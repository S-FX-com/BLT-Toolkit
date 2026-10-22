<?php
/**
 * Background jobs: the pure parts of BLT_Toolkit_Jobs (JOB-1 to JOB-4):
 * state transitions, the tick budget with a fake clock, saving, cancel,
 * retry, crash recovery and the progress numbers.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * A clock the test moves by hand.
 */
class Blt_Test_Job_Clock {

	/**
	 * Current time in seconds.
	 *
	 * @var float
	 */
	public $now;

	/**
	 * Constructor.
	 *
	 * @param float $now Start time.
	 */
	public function __construct( $now = 1000.0 ) {
		$this->now = (float) $now;
	}

	/**
	 * The time (used as the `$clock` callable).
	 *
	 * @return float
	 */
	public function __invoke() {
		return $this->now;
	}
}

/**
 * Known-vector tests for BLT_Toolkit_Jobs.
 */
class Test_Jobs extends TestCase {

	/**
	 * Fake clock.
	 *
	 * @var Blt_Test_Job_Clock
	 */
	private $clock;

	/**
	 * States passed to the checkpoint, in order.
	 *
	 * @var array[]
	 */
	private $saves = array();

	/**
	 * Fresh clock and save log per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();

		$this->clock = new Blt_Test_Job_Clock( 1000.0 );
		$this->saves = array();
	}

	/**
	 * A new items-mode job and its items.
	 *
	 * @param int   $count  Number of items (1 .. $count).
	 * @param array $pinned Pinned values.
	 * @return array{job:array,items:array}
	 */
	private function items_job( $count, array $pinned = array() ) {
		return BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'test_items', 'items', range( 1, $count ), $pinned, 7, 1000 );
	}

	/**
	 * A type whose process takes `$seconds` of fake time per item and
	 * returns what `$result` returns.
	 *
	 * @param float         $seconds Seconds per item.
	 * @param callable|null $result  function( $item, $pinned ), default true.
	 * @return array
	 */
	private function type( $seconds, $result = null ) {
		$clock = $this->clock;

		return array(
			'process'  => static function ( $item, array $pinned ) use ( $clock, $seconds, $result ) {
				$clock->now += $seconds;

				return null === $result ? true : call_user_func( $result, $item, $pinned );
			},
			'describe' => static function ( $item ) {
				return 'item ' . $item;
			},
		);
	}

	/**
	 * A checkpoint that records each save and answers `$answer` (or the
	 * answers in turn, when an array).
	 *
	 * @param string|string[] $answer Answer(s).
	 * @return callable
	 */
	private function checkpoint( $answer = 'continue' ) {
		$answers = (array) $answer;

		return function ( array $job ) use ( &$answers ) {
			$this->saves[] = $job;

			return count( $answers ) > 1 ? array_shift( $answers ) : $answers[0];
		};
	}

	/**
	 * Run a tick with the fake clock.
	 *
	 * @param array    $job        State.
	 * @param array    $type       Type.
	 * @param array    $items      Items.
	 * @param float    $budget     Budget.
	 * @param callable $checkpoint Checkpoint.
	 * @return array
	 */
	private function tick( array $job, array $type, array $items, $budget = 15.0, $checkpoint = null ) {
		return BLT_Toolkit_Jobs::run_tick( $job, $type, $items, $budget, $this->clock, null === $checkpoint ? $this->checkpoint() : $checkpoint );
	}

	// ---------- JOB-1 / JOB-3: a new job ----------

	/**
	 * A new items job is queued with its items re-indexed, its settings
	 * pinned and its owner recorded.
	 *
	 * @return void
	 */
	public function test_job3_new_job_pins_settings_and_owner() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'username_bulk', 'items', array( 'a' => 'x', 'b' => 'y' ), array( 'notify_user' => true ), 7, 1234 );

		$this->assertSame( array( 'x', 'y' ), $built['items'] );
		$this->assertSame( 'queued', $built['job']['status'] );
		$this->assertSame( 2, $built['job']['total'] );
		$this->assertSame( 2, $built['job']['pass_total'] );
		$this->assertSame( array( 'notify_user' => true ), $built['job']['pinned'] );
		$this->assertSame( 7, $built['job']['owner'] );
		$this->assertSame( 1234, $built['job']['created_at'] );
		$this->assertSame( 0, $built['job']['started_at'] );
		$this->assertNull( $built['job']['queue'] );
	}

	/**
	 * A cursor job keeps its initial state and has no items.
	 *
	 * @return void
	 */
	public function test_job1_new_cursor_job_keeps_initial_state() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'log_prune', 'cursor', array( 'deleted' => 0 ), array( 'cutoff' => '2026-01-01 00:00:00' ), 0, 1000 );

		$this->assertSame( array(), $built['items'] );
		$this->assertSame( 'cursor', $built['job']['mode'] );
		$this->assertSame( array( 'deleted' => 0 ), $built['job']['cursor'] );
		$this->assertSame( 0, $built['job']['owner'] );
	}

	/**
	 * Every process call sees the pinned settings, whatever happens later.
	 *
	 * @return void
	 */
	public function test_job3_process_receives_pinned_settings() {
		$built = $this->items_job( 3, array( 'sign_out' => false ) );
		$seen  = array();
		$type  = $this->type(
			0.1,
			static function ( $item, array $pinned ) use ( &$seen ) {
				$seen[] = $pinned;

				return true;
			}
		);

		$this->tick( $built['job'], $type, $built['items'] );

		$this->assertSame( array_fill( 0, 3, array( 'sign_out' => false ) ), $seen );
	}

	// ---------- JOB-2: the tick budget ----------

	/**
	 * With 2 s per item and a 5 s budget, a tick starts items at 0, 2 and
	 * 4 s, then stops: the budget is checked before each item.
	 *
	 * @return void
	 */
	public function test_job2_budget_stops_starting_new_items() {
		$built = $this->items_job( 10 );
		$job   = $this->tick( $built['job'], $this->type( 2.0 ), $built['items'], 5.0 );

		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 3, $job['position'] );
		$this->assertSame( 3, $job['done'] );
		$this->assertSame( 1, $job['ticks'] );
		$this->assertSame( 1006, $job['last_activity'] );
	}

	/**
	 * An item slower than the whole budget still runs, one per tick, so a
	 * slow item never stalls the job.
	 *
	 * @return void
	 */
	public function test_job2_at_least_one_item_per_tick() {
		$built = $this->items_job( 3 );
		$type  = $this->type( 100.0 );
		$job   = $this->tick( $built['job'], $type, $built['items'], 1.0 );

		$this->assertSame( 1, $job['position'] );

		$job = $this->tick( $job, $type, $built['items'], 1.0 );
		$job = $this->tick( $job, $type, $built['items'], 1.0 );

		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 3, $job['done'] );
		$this->assertSame( 3, $job['ticks'] );
	}

	/**
	 * The state is saved every SAVE_EVERY items when items are fast.
	 *
	 * @return void
	 */
	public function test_job2_saves_every_few_items() {
		$built = $this->items_job( 12 );
		$job   = $this->tick( $built['job'], $this->type( 0.1 ), $built['items'] );

		$this->assertSame( 'done', $job['status'] );
		$this->assertCount( 2, $this->saves );
		$this->assertSame( 5, $this->saves[0]['position'] );
		$this->assertSame( 10, $this->saves[1]['position'] );
	}

	/**
	 * The state is also saved every SAVE_SECONDS when items are slow.
	 *
	 * @return void
	 */
	public function test_job2_saves_every_few_seconds() {
		$built = $this->items_job( 4 );
		$this->tick( $built['job'], $this->type( 1.5 ), $built['items'] );

		// 1.5 s after the first item, 3 s after the second: save. Then again
		// after the fourth (3 s since the last save).
		$this->assertCount( 2, $this->saves );
		$this->assertSame( 2, $this->saves[0]['position'] );
		$this->assertSame( 4, $this->saves[1]['position'] );
	}

	/**
	 * The budget: the filtered value, at least 1 s, at most 60 percent of
	 * PHP's time limit; nonsense falls back to the default.
	 *
	 * @return void
	 */
	public function test_job2_budget_clamps() {
		$this->assertSame( 15.0, BLT_Toolkit_Jobs::budget( 15, 0 ) );
		$this->assertSame( 1.0, BLT_Toolkit_Jobs::budget( 0.2, 0 ) );
		$this->assertSame( 15.0, BLT_Toolkit_Jobs::budget( 'soon', 0 ) );
		$this->assertSame( 15.0, BLT_Toolkit_Jobs::budget( -5, 0 ) );
		$this->assertSame( 15.0, BLT_Toolkit_Jobs::budget( null, 0 ) );
		$this->assertSame( 12.0, BLT_Toolkit_Jobs::budget( 15, 20 ) );
		$this->assertSame( 18.0, BLT_Toolkit_Jobs::budget( 100, 30 ) );
		$this->assertSame( 1.0, BLT_Toolkit_Jobs::budget( 15, 1 ) );
	}

	/**
	 * A lock goes stale after four budgets, never under two minutes.
	 *
	 * @return void
	 */
	public function test_job2_lock_stale_after() {
		$this->assertSame( 120, BLT_Toolkit_Jobs::lock_stale_after( 15.0 ) );
		$this->assertSame( 240, BLT_Toolkit_Jobs::lock_stale_after( 60.0 ) );
		$this->assertSame( 120, BLT_Toolkit_Jobs::lock_stale_after( 1.0 ) );
	}

	/**
	 * When the checkpoint reports the lock lost, the tick stops at once and
	 * marks the state so the caller does not save it.
	 *
	 * @return void
	 */
	public function test_job2_lost_lock_stops_without_finishing() {
		$built = $this->items_job( 20 );
		$job   = $this->tick( $built['job'], $this->type( 0.1 ), $built['items'], 15.0, $this->checkpoint( 'stop' ) );

		$this->assertTrue( $job['lost_lock'] );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 5, $job['position'] );
	}

	// ---------- JOB-4: state transitions ----------

	/**
	 * Queued, then running with its start recorded, then done with its end
	 * recorded; a finished job is left alone by later ticks.
	 *
	 * @return void
	 */
	public function test_job4_queued_running_done() {
		$built = $this->items_job( 6 );
		$type  = $this->type( 2.0 );

		$job = $this->tick( $built['job'], $type, $built['items'], 5.0 );

		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 1000, $job['started_at'] );
		$this->assertSame( 1000, $job['pass_started_at'] );
		$this->assertSame( 'item 3', $job['current'] );
		$this->assertSame( 0, $job['finished_at'] );

		$job = $this->tick( $job, $type, $built['items'], 5.0 );

		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 1000, $job['started_at'] );
		$this->assertSame( 1012, $job['finished_at'] );
		$this->assertSame( '', $job['current'] );
		$this->assertSame( 6, $job['done'] );

		$again = $this->tick( $job, $type, $built['items'], 5.0 );

		$this->assertSame( $job, $again );
	}

	/**
	 * A WP_Error or an exception fails the item with its message, a string
	 * is done with a warning, and anything else fails with a generic reason.
	 * The job goes on to the end either way.
	 *
	 * @return void
	 */
	public function test_job4_failed_items_are_skipped_with_reasons() {
		$built = $this->items_job( 5 );
		$type  = $this->type(
			0.1,
			static function ( $item ) {
				switch ( $item ) {
					case 2:
						return new WP_Error( 'taken', 'That username is taken.' );
					case 3:
						throw new RuntimeException( 'Database went away.' );
					case 4:
						return 'Renamed, but the email could not be sent.';
					case 5:
						return false;
				}

				return true;
			}
		);

		$job = $this->tick( $built['job'], $type, $built['items'] );

		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 2, $job['done'] );
		$this->assertSame( array( 1, 2, 4 ), array_keys( $job['failed'] ) );
		$this->assertSame(
			array(
				'item'   => 'item 2',
				'reason' => 'That username is taken.',
			),
			$job['failed'][1]
		);
		$this->assertSame( 'Database went away.', $job['failed'][2]['reason'] );
		$this->assertSame( 'The item could not be processed.', $job['failed'][4]['reason'] );
		$this->assertSame( 1, $job['warning_count'] );
		$this->assertSame( 'Renamed, but the email could not be sent.', $job['warnings'][0]['message'] );
	}

	/**
	 * Retry failed: a new pass over just the failed items, in order, with
	 * their reasons cleared; the done count carries on.
	 *
	 * @return void
	 */
	public function test_job4_retry_failed_runs_only_failed_items() {
		$built   = $this->items_job( 6 );
		$fail    = array( 2 => true, 5 => true );
		$visited = array();
		$type    = $this->type(
			0.1,
			static function ( $item ) use ( &$fail, &$visited ) {
				$visited[] = $item;

				return isset( $fail[ $item ] ) ? new WP_Error( 'nope', 'Not yet.' ) : true;
			}
		);

		$job = $this->tick( $built['job'], $type, $built['items'] );

		$this->assertSame( 4, $job['done'] );
		$this->assertSame( array( 1, 4 ), array_keys( $job['failed'] ) );

		$progress = BLT_Toolkit_Jobs::progress( $job, 1010 );

		$this->assertTrue( $progress['can_retry'] );
		$this->assertSame( 2, $progress['failed_count'] );

		$job = BLT_Toolkit_Jobs::apply_retry( $job, 2000 );

		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( array( 1, 4 ), $job['queue'] );
		$this->assertSame( 2, $job['pass_total'] );
		$this->assertSame( 0, $job['position'] );
		$this->assertSame( 2, $job['pass'] );
		$this->assertSame( array(), $job['failed'] );
		$this->assertSame( 0, $job['finished_at'] );

		// Item 5 fails again; item 2 now works.
		$fail    = array( 5 => true );
		$visited = array();

		$this->clock->now = 2000.0;

		$job = $this->tick( $job, $type, $built['items'] );

		$this->assertSame( array( 2, 5 ), $visited );
		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 5, $job['done'] );
		$this->assertSame( array( 4 ), array_keys( $job['failed'] ) );
		$this->assertSame( 2000, $job['pass_started_at'] );
		$this->assertSame( 1000, $job['started_at'] );
	}

	/**
	 * Retry is refused while the job runs, in cursor mode, and when nothing
	 * failed.
	 *
	 * @return void
	 */
	public function test_job4_retry_refused_without_failed_items() {
		$built = $this->items_job( 2 );

		$this->assertInstanceOf( WP_Error::class, BLT_Toolkit_Jobs::apply_retry( $built['job'], 1000 ) );

		$job = $this->tick( $built['job'], $this->type( 0.1 ), $built['items'] );

		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 'blt_toolkit_job_not_retryable', BLT_Toolkit_Jobs::apply_retry( $job, 1000 )->get_error_code() );

		$cursor = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'log_prune', 'cursor', array(), array(), 0, 1000 );

		$cursor['job']['status'] = 'done';
		$cursor['job']['failed'] = array( 0 => array( 'item' => '', 'reason' => 'x' ) );

		$this->assertInstanceOf( WP_Error::class, BLT_Toolkit_Jobs::apply_retry( $cursor['job'], 1000 ) );
	}

	/**
	 * A cancel asked for mid-tick stops at the next save, keeping what
	 * finished; the rest is never started.
	 *
	 * @return void
	 */
	public function test_job4_cancel_keeps_finished_items() {
		$built   = $this->items_job( 20 );
		$visited = array();
		$type    = $this->type(
			0.1,
			static function ( $item ) use ( &$visited ) {
				$visited[] = $item;

				return true;
			}
		);

		$job = $this->tick( $built['job'], $type, $built['items'], 15.0, $this->checkpoint( 'cancel' ) );

		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 5, $job['done'] );
		$this->assertSame( range( 1, 5 ), $visited );
		$this->assertSame( 1000, $job['finished_at'] );

		$after = $this->tick( $job, $type, $built['items'] );

		$this->assertSame( $job, $after );
		$this->assertSame( range( 1, 5 ), $visited );

		$progress = BLT_Toolkit_Jobs::progress( $job, 1050 );

		$this->assertFalse( $progress['can_cancel'] );
		$this->assertFalse( $progress['can_retry'] );
		$this->assertSame( 25, $progress['percent'] );
	}

	/**
	 * Cancelling a job that never ran, and cancelling a finished one.
	 *
	 * @return void
	 */
	public function test_job4_apply_cancel() {
		$built = $this->items_job( 3 );
		$job   = BLT_Toolkit_Jobs::apply_cancel( $built['job'], 1500 );

		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( 1500, $job['finished_at'] );
		$this->assertSame( 0, $job['done'] );

		$done           = $built['job'];
		$done['status'] = 'done';

		$this->assertSame( $done, BLT_Toolkit_Jobs::apply_cancel( $done, 1500 ) );
	}

	/**
	 * A job-level failure (the owner lost the capability) is final, keeps
	 * the work done, and carries the reason.
	 *
	 * @return void
	 */
	public function test_job4_apply_failure() {
		$built = $this->items_job( 3 );
		$job   = BLT_Toolkit_Jobs::apply_failure( $built['job'], 'No longer allowed.', 1100 );

		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'No longer allowed.', $job['error'] );
		$this->assertSame( 1100, $job['finished_at'] );
		$this->assertTrue( BLT_Toolkit_Jobs::is_terminal( $job['status'] ) );
	}

	// ---------- Cursor mode (LOG-4, US-12) ----------

	/**
	 * A cursor job steps until process() says it is finished, with its
	 * state kept between ticks and its progress keys read.
	 *
	 * @return void
	 */
	public function test_job2_cursor_job_steps_until_finished() {
		$clock = $this->clock;
		$built = BLT_Toolkit_Jobs::new_job(
			'abcdef0123456789',
			'count_no_role',
			'cursor',
			array(
				'total' => 4000,
				'done'  => 0,
			),
			array( 'batch' => 1000 ),
			0,
			1000
		);
		$type  = array(
			'process' => static function ( array &$state, array $pinned ) use ( $clock ) {
				$clock->now      += 4.0;
				$state['done']   += $pinned['batch'];
				$state['current'] = 'rows up to ' . $state['done'];

				if ( 2000 === $state['done'] ) {
					$state['warning'] = 'Half way.';
				}

				return $state['done'] >= $state['total'];
			},
		);

		$job = $this->tick( $built['job'], $type, array(), 5.0 );

		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 2000, $job['cursor']['done'] );
		$this->assertSame( 2, $job['steps'] );
		$this->assertArrayNotHasKey( 'warning', $job['cursor'] );
		$this->assertSame( 'Half way.', $job['warnings'][0]['message'] );
		$this->assertSame( 'rows up to 2000', $job['warnings'][0]['item'] );

		$progress = BLT_Toolkit_Jobs::progress( $job, 1008 );

		$this->assertSame( 4000, $progress['total'] );
		$this->assertSame( 50, $progress['percent'] );
		$this->assertSame( 8, $progress['elapsed'] );
		$this->assertSame( 8, $progress['estimate'] );

		$job = $this->tick( $job, $type, array(), 5.0 );

		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 4000, $job['cursor']['done'] );
		$this->assertSame( 100, BLT_Toolkit_Jobs::progress( $job, 2000 )['percent'] );
	}

	/**
	 * A cursor job that reports an error, or throws, ends as failed with
	 * the message (LOG-4's prune stops on a database error).
	 *
	 * @return void
	 */
	public function test_job4_cursor_error_and_exception_fail_the_job() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'log_prune', 'cursor', array(), array(), 0, 1000 );

		$job = $this->tick(
			$built['job'],
			array(
				'process' => static function ( array &$state ) {
					$state['error'] = 'the delete failed';

					return true;
				},
			),
			array()
		);

		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'the delete failed', $job['error'] );

		$job = $this->tick(
			$built['job'],
			array(
				'process' => static function () {
					throw new RuntimeException( 'Boom.' );
				},
			),
			array()
		);

		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( 'Boom.', $job['error'] );
	}

	/**
	 * Without `total`, a cursor job's progress is indeterminate until done.
	 *
	 * @return void
	 */
	public function test_job4_cursor_progress_without_total() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'log_prune', 'cursor', array( 'deleted' => 0 ), array(), 0, 1000 );
		$job   = $built['job'];

		$job['status']          = 'running';
		$job['steps']           = 3;
		$job['pass_started_at'] = 1000;

		$progress = BLT_Toolkit_Jobs::progress( $job, 1030 );

		$this->assertNull( $progress['total'] );
		$this->assertNull( $progress['percent'] );
		$this->assertNull( $progress['estimate'] );
		$this->assertSame( 3, $progress['done'] );
		$this->assertSame( 30, $progress['elapsed'] );
	}

	// ---------- Crash recovery ----------

	/**
	 * A tick that dies leaves its lock; the next one resumes from the last
	 * save. The third death in a row at the same item fails that item and
	 * moves on.
	 *
	 * @return void
	 */
	public function test_job2_repeated_crash_skips_the_item() {
		$built = $this->items_job( 5 );
		$job   = $built['job'];

		$job['status']   = 'running';
		$job['position'] = 2;

		$job = BLT_Toolkit_Jobs::apply_crash( $job, $built['items'], 1100 );

		$this->assertSame( 1, $job['crash']['count'] );
		$this->assertSame( 2, $job['position'] );

		$job = BLT_Toolkit_Jobs::apply_crash( $job, $built['items'], 1200 );

		$this->assertSame( 2, $job['crash']['count'] );

		$job = BLT_Toolkit_Jobs::apply_crash( $job, $built['items'], 1300 );

		$this->assertSame( 3, $job['position'] );
		$this->assertSame( array( 2 ), array_keys( $job['failed'] ) );
		$this->assertSame( '3', $job['failed'][2]['item'] );
		$this->assertSame( 0, $job['crash']['count'] );
		$this->assertSame( 'running', $job['status'] );
	}

	/**
	 * Progress between crashes resets the count.
	 *
	 * @return void
	 */
	public function test_job2_crash_count_resets_when_work_moves_on() {
		$built = $this->items_job( 5 );
		$job   = $built['job'];

		$job['status'] = 'running';
		$job           = BLT_Toolkit_Jobs::apply_crash( $job, $built['items'], 1100 );
		$job           = BLT_Toolkit_Jobs::apply_crash( $job, $built['items'], 1100 );

		$job['position'] = 1;
		$job             = BLT_Toolkit_Jobs::apply_crash( $job, $built['items'], 1200 );

		$this->assertSame( 1, $job['crash']['count'] );
		$this->assertSame( 1, $job['crash']['position'] );
		$this->assertSame( array(), $job['failed'] );
	}

	/**
	 * A cursor job that keeps dying stops as failed.
	 *
	 * @return void
	 */
	public function test_job2_repeated_crash_fails_a_cursor_job() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'log_prune', 'cursor', array(), array(), 0, 1000 );
		$job   = $built['job'];

		$job['status'] = 'running';

		for ( $i = 0; $i < 3; $i++ ) {
			$job = BLT_Toolkit_Jobs::apply_crash( $job, array(), 1100 );
		}

		$this->assertSame( 'failed', $job['status'] );
		$this->assertNotSame( '', $job['error'] );
	}

	// ---------- JOB-4: progress numbers ----------

	/**
	 * Seconds left at the rate so far.
	 *
	 * @return void
	 */
	public function test_job4_estimate_vectors() {
		$this->assertSame( 180, BLT_Toolkit_Jobs::estimate( 10, 90, 20 ) );
		$this->assertSame( 1, BLT_Toolkit_Jobs::estimate( 199, 1, 100 ) );
		$this->assertNull( BLT_Toolkit_Jobs::estimate( 0, 10, 5 ) );
		$this->assertNull( BLT_Toolkit_Jobs::estimate( 3, 10, 0 ) );
		$this->assertSame( 0, BLT_Toolkit_Jobs::estimate( 5, 0, 10 ) );
	}

	/**
	 * A running job with no activity for more than STALL_AFTER seconds is
	 * stalled, as is a job that waited too long for its first tick; a final
	 * job never is.
	 *
	 * @return void
	 */
	public function test_job4_stall_detection() {
		$built = $this->items_job( 3 );
		$job   = $built['job'];

		$this->assertFalse( BLT_Toolkit_Jobs::is_stalled( $job, 1000 + BLT_Toolkit_Jobs::QUEUED_STALL_AFTER ) );
		$this->assertTrue( BLT_Toolkit_Jobs::is_stalled( $job, 1001 + BLT_Toolkit_Jobs::QUEUED_STALL_AFTER ) );

		$job['status']        = 'running';
		$job['last_activity'] = 5000;

		$this->assertFalse( BLT_Toolkit_Jobs::is_stalled( $job, 5000 + BLT_Toolkit_Jobs::STALL_AFTER ) );
		$this->assertTrue( BLT_Toolkit_Jobs::is_stalled( $job, 5001 + BLT_Toolkit_Jobs::STALL_AFTER ) );

		$job['status'] = 'done';

		$this->assertFalse( BLT_Toolkit_Jobs::is_stalled( $job, 99999 ) );
	}

	/**
	 * Progress of a running items job: done/total, percent of the pass,
	 * elapsed, estimate, and failures listed before warnings.
	 *
	 * @return void
	 */
	public function test_job4_progress_of_a_running_job() {
		$built = $this->items_job( 200 );
		$type  = $this->type(
			0.5,
			static function ( $item ) {
				if ( 3 === $item ) {
					return new WP_Error( 'x', 'Failed three.' );
				}

				return 7 === $item ? 'Warned seven.' : true;
			}
		);

		$job      = $this->tick( $built['job'], $type, $built['items'], 25.0 );
		$progress = BLT_Toolkit_Jobs::progress( $job, (int) $this->clock->now );

		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 200, $progress['total'] );
		$this->assertSame( 50, $progress['processed'] );
		$this->assertSame( 49, $progress['done'] );
		$this->assertSame( 1, $progress['failed_count'] );
		$this->assertSame( 25, $progress['percent'] );
		$this->assertSame( 25, $progress['elapsed'] );
		$this->assertSame( 75, $progress['estimate'] );
		$this->assertFalse( $progress['stalled'] );
		$this->assertTrue( $progress['can_cancel'] );
		$this->assertFalse( $progress['can_retry'] );
		$this->assertSame( 'item 50', $progress['current'] );
		$this->assertSame(
			array(
				array(
					'kind'    => 'failed',
					'item'    => 'item 3',
					'message' => 'Failed three.',
				),
				array(
					'kind'    => 'warning',
					'item'    => 'item 7',
					'message' => 'Warned seven.',
				),
			),
			$progress['issues']
		);
	}

	/**
	 * The warnings kept in the state are capped, newest kept; the count is
	 * not.
	 *
	 * @return void
	 */
	public function test_job4_warnings_are_capped() {
		$built = $this->items_job( BLT_Toolkit_Jobs::MAX_WARNINGS + 10 );
		$type  = $this->type(
			0.0,
			static function ( $item ) {
				return 'Warning ' . $item;
			}
		);

		$job = $this->tick( $built['job'], $type, $built['items'] );

		$this->assertSame( BLT_Toolkit_Jobs::MAX_WARNINGS + 10, $job['warning_count'] );
		$this->assertCount( BLT_Toolkit_Jobs::MAX_WARNINGS, $job['warnings'] );
		$this->assertSame( 'Warning ' . ( BLT_Toolkit_Jobs::MAX_WARNINGS + 10 ), end( $job['warnings'] )['message'] );
		$this->assertCount( BLT_Toolkit_Jobs::VIEW_ISSUES, BLT_Toolkit_Jobs::progress( $job, 2000 )['issues'] );
	}

	/**
	 * Without a describe callback, an item is labeled by itself, its
	 * `label`, or its position; long labels are cut.
	 *
	 * @return void
	 */
	public function test_job4_item_labels() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'test', 'items', array( 'bob', array( 'label' => 'alice to carol' ), array( 'x' => 1 ), str_repeat( 'é', 400 ) ), array(), 0, 1000 );
		$type  = array(
			'process' => static function () {
				return new WP_Error( 'x', 'No.' );
			},
		);

		$job = $this->tick( $built['job'], $type, $built['items'] );

		$this->assertSame( 'bob', $job['failed'][0]['item'] );
		$this->assertSame( 'alice to carol', $job['failed'][1]['item'] );
		$this->assertSame( 'Item 3', $job['failed'][2]['item'] );
		$this->assertSame( BLT_Toolkit_Jobs::MAX_TEXT, mb_strlen( $job['failed'][3]['item'], 'UTF-8' ) );
	}

	// ---------- Stored state ----------

	/**
	 * A stored state is normalized: missing keys come back with defaults,
	 * numbers are numbers, and something that is not a job is refused.
	 *
	 * @return void
	 */
	public function test_job2_normalize_stored_state() {
		$this->assertNull( BLT_Toolkit_Jobs::normalize( array() ) );
		$this->assertNull(
			BLT_Toolkit_Jobs::normalize(
				array(
					'id'     => 'abcdef0123456789',
					'type'   => 't',
					'status' => 'exploded',
					'mode'   => 'items',
				)
			)
		);

		$job = BLT_Toolkit_Jobs::normalize(
			array(
				'id'       => 'abcdef0123456789',
				'type'     => 't',
				'status'   => 'running',
				'mode'     => 'items',
				'position' => '4',
				'queue'    => array( '3', '9' ),
				'failed'   => 'oops',
			)
		);

		$this->assertSame( 4, $job['position'] );
		$this->assertSame( array( 3, 9 ), $job['queue'] );
		$this->assertSame( 2, $job['pass_total'] );
		$this->assertSame( array(), $job['failed'] );
		$this->assertSame( 1, $job['pass'] );
	}

	/**
	 * Only 16 lowercase hex characters make a job ID.
	 *
	 * @return void
	 */
	public function test_job1_clean_id() {
		$this->assertSame( 'abcdef0123456789', BLT_Toolkit_Jobs::clean_id( 'abcdef0123456789' ) );
		$this->assertSame( '', BLT_Toolkit_Jobs::clean_id( 'ABCDEF0123456789' ) );
		$this->assertSame( '', BLT_Toolkit_Jobs::clean_id( 'abcdef012345678' ) );
		$this->assertSame( '', BLT_Toolkit_Jobs::clean_id( '../../wp-config' ) );
		$this->assertSame( '', BLT_Toolkit_Jobs::clean_id( 1234567890123456 ) );
	}
}
