<?php
/**
 * Background jobs: the runner, its stored state and the progress panel.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs long work in time-boxed ticks (SPEC 6.3, CONVENTIONS 10).
 *
 * - JOB-1, runner: Action Scheduler when `as_enqueue_async_action()` exists;
 *   otherwise a chain of single WP-Cron events plus an immediate,
 *   non-blocking loopback request to admin-ajax.php (as spawn_cron() does for
 *   wp-cron.php), so a job does not wait for the next visitor to trigger
 *   WP-Cron. While the job's screen is open, `POST blt-toolkit/v1/jobs/{id}/tick`
 *   (BLT_Toolkit_Rest) drives ticks too. A running tick keeps one rescue
 *   tick queued for the moment its lock would go stale, so a tick that dies
 *   is taken over without anyone watching; the log prune's daily event also
 *   requeues any live job that went quiet (resume_stalled()).
 * - JOB-2: each tick stops starting new work once `blt_toolkit_tick_budget`
 *   seconds (default 15) have passed, and the state is saved in
 *   `blt_toolkit_job_{id}` (autoload no) every few items and at the end.
 * - JOB-3: settings are pinned when the job starts and handed to every
 *   step, so changing a toggle mid-run cannot split behavior. The person who
 *   started the job is pinned too: every tick runs as that user, whoever or
 *   whatever drives it, and the job stops if they lose the type's capability.
 *   The switch chain of the starting request is pinned with them, so log
 *   rows written by a tick still name the human who signed in (LOG-2,
 *   BLT_Toolkit_Log::with_switch_chain()).
 * - JOB-4: progress (bar, current item, done/total, elapsed, estimate,
 *   per-item warnings) through view(), the REST routes and render_ui(); it
 *   survives closing the tab because the job lives on the server. Cancel
 *   keeps what finished. A failed item is skipped with its reason and
 *   offered to retry_failed(). A stalled job is driven from the open screen
 *   with a "keep this tab open" notice.
 *
 * Two modes:
 *
 * - `items`: a list fixed at start. `process( $item, array $pinned )`
 *   returns true, a non-empty string (done, with that warning), or a
 *   WP_Error (failed, with its message as the reason). Each item is
 *   processed once per pass; a later pass from retry_failed() takes only the
 *   failed ones. A PHP fatal error can interrupt a tick between two saves, so
 *   the few items processed since the last save may be processed again by
 *   the next tick: process() must be safe to repeat. An item that stops the
 *   job three times in a row is marked failed and skipped.
 * - `cursor`: open-ended work (LOG-4 pruning, US-12 counting).
 *   `process( array &$state, array $pinned )` does one step, updates the
 *   state in place and returns true when finished. Optional state keys the
 *   runner reads: `total` and `done` (numbers, for the bar and estimate),
 *   `current` (a label), `warning` (a message moved into the job's warnings),
 *   and `error` (a non-empty message on a finished job marks it failed).
 *
 * Storage: `blt_toolkit_job_{id}` holds the state, `blt_toolkit_job_{id}_items`
 * the item list (written once, so saving progress stays small), and two
 * short-lived rows, `_lock` and `_cancel`, coordinate concurrent requests.
 * Every name starts with `blt_toolkit_job_`, which uninstall removes.
 *
 * Nothing here runs on a front-end page view: register() only adds hooks,
 * and ticks run in WP-Cron, Action Scheduler, the loopback (admin-ajax.php),
 * REST and admin-post.php requests.
 *
 * The methods marked "pure" take plain values and call no WordPress function
 * that touches the database, so tests/test-jobs.php drives them with a fake
 * clock.
 */
class BLT_Toolkit_Jobs {

	/**
	 * Prefix of every option a job stores (SPEC 14.2).
	 */
	const OPTION_PREFIX = 'blt_toolkit_job_';

	/**
	 * WP-Cron hook of a chained tick; the job ID is its one argument. Starts
	 * with `blt_toolkit_`, so deactivation and uninstall clear it.
	 */
	const CRON_HOOK = 'blt_toolkit_job_tick';

	/**
	 * Action Scheduler hook of a tick (kept apart from the WP-Cron hook so
	 * the callback knows which runner it is in).
	 */
	const AS_HOOK = 'blt_toolkit_job_as_tick';

	/**
	 * Action Scheduler group.
	 */
	const AS_GROUP = 'blt-toolkit';

	/**
	 * admin-ajax.php action of the loopback request.
	 */
	const AJAX_ACTION = 'blt_toolkit_job_run';

	/**
	 * admin-post.php actions of the panel's forms, which work without
	 * JavaScript: verb => action.
	 */
	const POST_ACTIONS = array(
		'cancel' => 'blt_toolkit_job_cancel',
		'retry'  => 'blt_toolkit_job_retry',
		'tick'   => 'blt_toolkit_job_continue',
	);

	/**
	 * Script handle of the progress panel.
	 */
	const SCRIPT_HANDLE = 'blt-toolkit-jobs';

	/**
	 * Default tick budget in seconds (JOB-2, `blt_toolkit_tick_budget`).
	 */
	const DEFAULT_BUDGET = 15;

	/**
	 * Save the state after this many items or steps ...
	 */
	const SAVE_EVERY = 5;

	/**
	 * ... or after this many seconds, whichever comes first (JOB-2).
	 */
	const SAVE_SECONDS = 2;

	/**
	 * A running job with no activity for this many seconds is stalled
	 * (JOB-4): no tick is running and none is about to.
	 */
	const STALL_AFTER = 20;

	/**
	 * A job that has waited this many seconds for its first tick is stalled.
	 */
	const QUEUED_STALL_AFTER = 10;

	/**
	 * How many interrupted ticks at the same item before that item is marked
	 * failed and skipped.
	 */
	const MAX_CRASHES = 3;

	/**
	 * Seconds after a lock goes stale that the rescue tick runs (see
	 * rescue_at()).
	 */
	const RESCUE_GRACE = 5;

	/**
	 * Warnings kept in the state (the newest).
	 */
	const MAX_WARNINGS = 100;

	/**
	 * Problems listed in a view: failed items and warnings.
	 */
	const VIEW_ISSUES = 50;

	/**
	 * Longest item label or reason kept, in characters.
	 */
	const MAX_TEXT = 300;

	/**
	 * Finished jobs are removed this long after they end ...
	 */
	const KEEP_FINISHED = 604800;

	/**
	 * ... and unfinished ones after this long without activity.
	 */
	const KEEP_ABANDONED = 2592000;

	/**
	 * Job statuses. `queued` and `running` are live; the rest are final
	 * until retry_failed() starts another pass.
	 */
	const LIVE     = array( 'queued', 'running' );
	const TERMINAL = array( 'done', 'cancelled', 'failed' );

	/**
	 * Registered job types, keyed by type.
	 *
	 * @var array<string,array{label:string,capability:string,mode:string,process:callable,describe:callable|null,can_use:callable|null,audit:callable|null}>
	 */
	private static $types = array();

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private static $registered = false;

	// ---------- Boot ----------

	/**
	 * Add the runner's hooks, and BLT_Toolkit_Rest's. Only adds hooks, so it
	 * costs nothing on a front-end page view. Meant to be called from
	 * BLT_Toolkit_Plugin::boot(); register_type() calls it as well, so the
	 * runner is wired whenever a job type exists. Idempotent.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
		add_action( self::AS_HOOK, array( __CLASS__, 'run_action_scheduler' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'run_loopback' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'run_loopback' ) );

		foreach ( self::POST_ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle_post' ) );
		}

		if ( class_exists( 'BLT_Toolkit_Rest' ) ) {
			BLT_Toolkit_Rest::register();
		}
	}

	/**
	 * Register a job type.
	 *
	 * Call it on `init` before priority 10 (the label is translated, and every
	 * request that runs a tick does so after `init`).
	 *
	 * @param string $type Type key, such as `username_bulk` (JOB-5).
	 * @param array  $args {
	 *     @type string   $label      What the job does, translated.
	 *     @type string   $capability Needed to view, drive, cancel and retry the job (default `manage_options`).
	 *     @type string   $mode       `items` (default) or `cursor`.
	 *     @type callable $process    items: function( $item, array $pinned ): true|string|WP_Error.
	 *                                cursor: function( array &$state, array $pinned ): bool, true when finished.
	 *     @type callable $describe   Optional, items mode: function( $item ): string, the label shown as the current item.
	 *     @type callable $can_use    Optional: function( array $job, WP_User $user ): bool, a further check after the
	 *                                capability, for a type whose jobs show or act on what only some holders of the
	 *                                capability may see (a bulk rename lists logins SEC-6 hides from a delegate).
	 *     @type callable $audit      Optional: function( string $verb, array $job ): void, called in the request of the
	 *                                person who cancelled (`cancel`) or retried (`retry`) a job, after it took effect, so
	 *                                the type can log who did it (a retry runs as the job's owner, JOB-3).
	 * }
	 * @return bool Whether it was registered.
	 */
	public static function register_type( $type, array $args ) {
		$type = sanitize_key( (string) $type );
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'items';

		if ( '' === $type || ! isset( $args['process'] ) || ! is_callable( $args['process'] ) || ! in_array( $mode, array( 'items', 'cursor' ), true ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'A job type needs a key, a mode of items or cursor, and a callable process.' ), '0.1.0' );

			return false;
		}

		self::$types[ $type ] = array(
			'label'      => isset( $args['label'] ) && is_string( $args['label'] ) && '' !== $args['label'] ? $args['label'] : $type,
			'capability' => isset( $args['capability'] ) && is_string( $args['capability'] ) && '' !== $args['capability'] ? $args['capability'] : 'manage_options',
			'mode'       => $mode,
			'process'    => $args['process'],
			'describe'   => isset( $args['describe'] ) && is_callable( $args['describe'] ) ? $args['describe'] : null,
			'can_use'    => isset( $args['can_use'] ) && is_callable( $args['can_use'] ) ? $args['can_use'] : null,
			'audit'      => isset( $args['audit'] ) && is_callable( $args['audit'] ) ? $args['audit'] : null,
		);

		self::register();

		return true;
	}

	/**
	 * A registered type, or null.
	 *
	 * @param string $type Type key.
	 * @return array|null
	 */
	public static function type( $type ) {
		$type = (string) $type;

		return isset( self::$types[ $type ] ) ? self::$types[ $type ] : null;
	}

	/**
	 * Every registered type, keyed by type.
	 *
	 * @return array<string,array>
	 */
	public static function types() {
		return self::$types;
	}

	// ---------- Lifecycle ----------

	/**
	 * Start a job (JOB-1, JOB-3).
	 *
	 * Stores the state with the pinned settings, fires
	 * `blt_toolkit_before_job`, and asks the runner for the first tick. It
	 * does not check capabilities: the caller authorizes the action that
	 * starts the job. The job then runs as the current user (0 from WP-Cron).
	 *
	 * @param string $type                 Registered type.
	 * @param array  $items_or_initial_state items mode: the items (a list; keys are dropped).
	 *                                       cursor mode: the initial state.
	 * @param array  $pinned               Settings and anything else every step must see unchanged.
	 * @return string|WP_Error Job ID, or `blt_toolkit_job_unknown_type`,
	 *                         `blt_toolkit_job_empty` or `blt_toolkit_job_not_saved`.
	 */
	public static function start( $type, array $items_or_initial_state, array $pinned = array() ) {
		$type = (string) $type;
		$def  = self::type( $type );

		if ( null === $def ) {
			return new WP_Error( 'blt_toolkit_job_unknown_type', __( 'That kind of job is not available. Its module may be switched off.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		if ( 'items' === $def['mode'] && array() === $items_or_initial_state ) {
			return new WP_Error( 'blt_toolkit_job_empty', __( 'There is nothing to do: the job has no items.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		self::collect_garbage();

		$id    = self::new_id();
		$built = self::new_job( $id, $type, $def['mode'], $items_or_initial_state, $pinned, get_current_user_id(), time() );
		$job   = $built['job'];

		$job['runner'] = self::runner();

		// LOG-2: the switch chain of the request that starts the job, so the
		// rows its ticks write name the human who signed in (tick_locked()).
		$job['chain'] = BLT_Toolkit_Log::current_switch_chain();

		if ( 'items' === $def['mode'] && ! add_option( self::items_option( $id ), $built['items'], '', false ) ) {
			return new WP_Error( 'blt_toolkit_job_not_saved', __( 'The job could not be saved, so it was not started. Try again.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		if ( ! add_option( self::state_option( $id ), $job, '', false ) ) {
			delete_option( self::items_option( $id ) );

			return new WP_Error( 'blt_toolkit_job_not_saved', __( 'The job could not be saved, so it was not started. Try again.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires once when a background job starts, after its settings are
		 * pinned (JOB-3) and before its first item. Fires again when
		 * retry_failed() starts another pass.
		 *
		 * @param array $job The job state as stored in `blt_toolkit_job_{id}`.
		 */
		do_action( 'blt_toolkit_before_job', $job );

		self::dispatch( $id );

		return $id;
	}

	/**
	 * A job's stored state, or null.
	 *
	 * Read straight from the database, never from a cache another request
	 * may have outdated.
	 *
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = self::clean_id( $id );

		if ( '' === $id ) {
			return null;
		}

		$job = self::read_option( self::state_option( $id ) );

		return is_array( $job ) ? self::normalize( $job ) : null;
	}

	/**
	 * A stored state with every key present and typed, or null when it is
	 * not a job at all. Pure.
	 *
	 * @param array $job Stored state.
	 * @return array|null
	 */
	public static function normalize( array $job ) {
		if ( ! isset( $job['id'], $job['type'], $job['status'], $job['mode'] ) || ! in_array( $job['status'], array_merge( self::LIVE, self::TERMINAL ), true ) ) {
			return null;
		}

		$mode     = 'cursor' === $job['mode'] ? 'cursor' : 'items';
		$defaults = self::new_job( (string) $job['id'], (string) $job['type'], $mode, array(), array(), 0, 0 );
		$job      = array_merge( $defaults['job'], $job );

		$job['mode'] = $mode;

		foreach ( array( 'pinned', 'failed', 'warnings', 'cursor', 'crash', 'chain' ) as $key ) {
			if ( ! is_array( $job[ $key ] ) ) {
				$job[ $key ] = $defaults['job'][ $key ];
			}
		}

		$job['chain'] = BLT_Toolkit_Log::clean_chain( $job['chain'] );

		if ( null !== $job['queue'] && ! is_array( $job['queue'] ) ) {
			$job['queue'] = null;
		}

		if ( is_array( $job['queue'] ) ) {
			$job['queue']      = array_values( array_map( 'intval', $job['queue'] ) );
			$job['pass_total'] = count( $job['queue'] );
		}

		foreach ( array( 'owner', 'total', 'done', 'warning_count', 'position', 'pass', 'pass_total', 'steps', 'created_at', 'queued_at', 'started_at', 'pass_started_at', 'last_activity', 'finished_at', 'ticks' ) as $key ) {
			$job[ $key ] = (int) $job[ $key ];
		}

		foreach ( array( 'id', 'type', 'status', 'current', 'error', 'runner' ) as $key ) {
			$job[ $key ] = is_scalar( $job[ $key ] ) ? (string) $job[ $key ] : '';
		}

		return $job;
	}

	/**
	 * Run one tick of a job (JOB-2) and return its progress.
	 *
	 * The tick takes the job's lock, so two requests never process the same
	 * job at once; a request that finds the lock taken returns at once with
	 * `ran` false and `note` `busy`, after making sure a tick is queued for
	 * when that lock goes stale. When work remains, the next tick is queued
	 * with the runner. While the tick runs, a rescue tick waits with the
	 * runner for the moment its lock would go stale, so a tick that dies (a
	 * PHP fatal error, a time limit, a killed worker) never leaves the job
	 * orphaned.
	 *
	 * @param string $id Job ID.
	 * @return array view() of the job plus `ran` (bool) and `note`: '' when
	 *               it ran, or `not_found`, `finished`, `type_missing` or
	 *               `busy`. An empty array when the job does not exist.
	 */
	public static function tick( $id ) {
		$id  = self::clean_id( $id );
		$job = '' === $id ? null : self::get( $id );

		if ( null === $job ) {
			return array();
		}

		if ( self::is_terminal( $job['status'] ) ) {
			return self::with_note( self::view( $job ), false, 'finished' );
		}

		$type = self::type( $job['type'] );

		if ( null === $type ) {
			return self::with_note( self::view( $job ), false, 'type_missing' );
		}

		$budget = self::budget( self::filtered_budget(), (int) ini_get( 'max_execution_time' ) );
		$lock   = self::lock( $id, self::lock_stale_after( $budget ) );

		if ( null === $lock ) {
			// The holder may have died with the lock (a fatal error, a time
			// limit): make sure something comes back once it goes stale.
			self::ensure_continuation( $id, $budget );

			return self::with_note( self::view( $job ), false, 'busy' );
		}

		// A tick that dies from here on releases nothing and dispatches
		// nothing, so leave a continuation for when its lock goes stale; it
		// takes the job over and counts the crash (apply_crash()). The normal
		// end of the tick replaces it (dispatch()) or clears it (finish()).
		self::arm_rescue( $id, $budget );

		try {
			$outcome = self::tick_locked( $id, $lock, $type, $budget );
		} finally {
			self::unlock( $id, $lock['token'] );
		}

		// Only now, with the lock released: the loopback request for the next
		// tick can arrive within milliseconds and must find the job free.
		if ( 'finish' === $outcome['next'] ) {
			self::finish( $id, $outcome['job'], $outcome['was'] );
		} elseif ( 'dispatch' === $outcome['next'] ) {
			self::dispatch( $id );
		}

		return $outcome['view'];
	}

	/**
	 * The part of tick() that runs while holding the job's lock.
	 *
	 * @param string $id     Job ID.
	 * @param array  $lock   Result of lock().
	 * @param array  $type   Registered type.
	 * @param float  $budget Tick budget.
	 * @return array{view:array,next:string,job:array|null,was:string} `next`
	 *               is `finish` (the job just ended), `dispatch` (queue the
	 *               next tick) or ''.
	 */
	private static function tick_locked( $id, array $lock, array $type, $budget ) {
		// Another request may have moved the job on since it was first read.
		$job = self::get( $id );

		if ( null === $job ) {
			return array(
				'view' => array(),
				'next' => '',
				'job'  => null,
				'was'  => '',
			);
		}

		$was = $job['status'];

		if ( self::is_terminal( $was ) ) {
			return array(
				'view' => self::with_note( self::view( $job ), false, 'finished' ),
				'next' => '',
				'job'  => $job,
				'was'  => $was,
			);
		}

		$items = 'items' === $job['mode'] ? self::items( $id ) : array();
		$now   = time();

		if ( $lock['takeover'] ) {
			$job = self::apply_crash( $job, $items, $now );
		}

		if ( self::cancel_requested( $id ) ) {
			$job = self::apply_cancel( $job, $now );
		} elseif ( ! self::is_terminal( $job['status'] ) ) {
			$owner = self::owner_problem( $job, $type );

			if ( '' !== $owner ) {
				$job = self::apply_failure( $job, $owner, $now );
			}
		}

		if ( ! self::is_terminal( $job['status'] ) ) {
			$previous_user = get_current_user_id();
			$switch        = (int) $job['owner'] !== $previous_user;

			// JOB-3: the job runs as the person who started it.
			if ( $switch ) {
				wp_set_current_user( (int) $job['owner'] );
			}

			try {
				// LOG-2: rows written by the work name the chain pinned at
				// start, whoever drives this tick.
				$job = BLT_Toolkit_Log::with_switch_chain(
					$job['chain'],
					static function () use ( $job, $type, $items, $budget, $id, $lock ) {
						return self::run_tick(
							$job,
							$type,
							$items,
							$budget,
							'microtime',
							static function ( array $state ) use ( $id, $lock ) {
								return self::checkpoint( $id, $lock['token'], $state );
							}
						);
					}
				);
			} finally {
				if ( $switch ) {
					wp_set_current_user( $previous_user );
				}
			}
		}

		if ( ! empty( $job['lost_lock'] ) ) {
			// Another request took the job over; it owns the state now.
			unset( $job['lost_lock'] );

			return array(
				'view' => self::with_note( self::view( $job ), true, 'busy' ),
				'next' => '',
				'job'  => $job,
				'was'  => $was,
			);
		}

		// A cancel asked for after the tick's last save.
		if ( ! self::is_terminal( $job['status'] ) && self::cancel_requested( $id ) ) {
			$job = self::apply_cancel( $job, time() );
		}

		self::save( $id, $job );

		return array(
			'view' => self::with_note( self::view( $job ), true, '' ),
			'next' => self::is_terminal( $job['status'] ) ? 'finish' : 'dispatch',
			'job'  => $job,
			'was'  => $was,
		);
	}

	/**
	 * Cancel a job (JOB-4). Items that finished stay finished; the rest are
	 * not started.
	 *
	 * When no tick is running the job is cancelled at once. Otherwise the
	 * running tick is asked to stop and does so at its next save, within a
	 * few items.
	 *
	 * @param string $id Job ID.
	 * @return array|WP_Error view() of the job (its `cancel_requested` is true
	 *                        while a running tick finishes its item), or
	 *                        `blt_toolkit_job_not_found`.
	 */
	public static function cancel( $id ) {
		$id  = self::clean_id( $id );
		$job = '' === $id ? null : self::get( $id );

		if ( null === $job ) {
			return self::not_found();
		}

		if ( self::is_terminal( $job['status'] ) ) {
			return self::view( $job );
		}

		$budget = self::budget( self::filtered_budget(), 0 );
		$lock   = self::lock( $id, self::lock_stale_after( $budget ) );

		if ( null === $lock ) {
			self::request_cancel( $id );

			// If the holder died, the tick that takes over applies the cancel.
			self::ensure_continuation( $id, $budget );

			self::audit( 'cancel', $job );

			return self::view( $job );
		}

		$was = '';

		try {
			$job = self::get( $id );

			if ( null !== $job && ! self::is_terminal( $job['status'] ) ) {
				$was = $job['status'];
				$job = self::apply_cancel( $job, time() );

				self::save( $id, $job );
			}
		} finally {
			self::unlock( $id, $lock['token'] );
		}

		if ( null === $job ) {
			return self::not_found();
		}

		if ( '' !== $was ) {
			self::finish( $id, $job, $was );
			self::audit( 'cancel', $job );
		}

		return self::view( $job );
	}

	/**
	 * Start another pass over the items that failed (JOB-4).
	 *
	 * @param string $id Job ID.
	 * @return array|WP_Error view() of the job, or `blt_toolkit_job_not_found`,
	 *                        `blt_toolkit_job_busy` or one of apply_retry()'s
	 *                        errors.
	 */
	public static function retry_failed( $id ) {
		$id  = self::clean_id( $id );
		$job = '' === $id ? null : self::get( $id );

		if ( null === $job ) {
			return self::not_found();
		}

		$lock = self::lock( $id, self::lock_stale_after( self::budget( self::filtered_budget(), 0 ) ) );

		if ( null === $lock ) {
			return new WP_Error( 'blt_toolkit_job_busy', __( 'The job is busy. Try again in a moment.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		try {
			$job = self::get( $id );
			$job = null === $job ? self::not_found() : self::apply_retry( $job, time() );

			if ( ! is_wp_error( $job ) ) {
				$job['runner'] = self::runner();

				self::save( $id, $job );
				self::clear_cancel( $id );
			}
		} finally {
			self::unlock( $id, $lock['token'] );
		}

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		/** This action is documented in includes/class-blt-toolkit-jobs.php */
		do_action( 'blt_toolkit_before_job', $job );

		self::audit( 'retry', $job );

		self::dispatch( $id );

		return self::view( $job );
	}

	/**
	 * Hand a cancel or retry to the job type's `audit` callback, in the
	 * request of the person who asked for it.
	 *
	 * @param string $verb `cancel` or `retry`.
	 * @param array  $job  The job's state after the change.
	 * @return void
	 */
	private static function audit( $verb, array $job ) {
		$type = self::type( $job['type'] );

		if ( null !== $type && null !== $type['audit'] ) {
			call_user_func( $type['audit'], (string) $verb, $job );
		}
	}

	/**
	 * Jobs, newest first, optionally of one type, so a screen can find the
	 * job it started after the tab was closed.
	 *
	 * @param string $type Type key, or '' for every type.
	 * @return array[] Stored states.
	 */
	public static function jobs( $type = '' ) {
		$out = array();

		foreach ( self::job_ids() as $id ) {
			$job = self::get( $id );

			if ( null !== $job && ( '' === (string) $type || $job['type'] === (string) $type ) ) {
				$out[] = $job;
			}
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['created_at'] - (int) $a['created_at'];
			}
		);

		return $out;
	}

	/**
	 * The newest job of a type, or null.
	 *
	 * @param string $type Type key.
	 * @return array|null Stored state.
	 */
	public static function latest( $type ) {
		$jobs = self::jobs( $type );

		return array() === $jobs ? null : $jobs[0];
	}

	/**
	 * Remove every Action Scheduler action of this plugin. WP-Cron events are
	 * cleared by BLT_Toolkit_Plugin::clear_cron() with the other
	 * `blt_toolkit_` hooks; BLT_Toolkit_Plugin::deactivate() calls this too,
	 * so nothing runs while the code is inactive, and uninstall.php repeats
	 * it. A live job is picked up again by resume_stalled() after
	 * reactivation.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		// By hook alone: given a hook and a group with empty arguments,
		// Action Scheduler cancels only actions whose arguments are empty,
		// and every tick carries its job ID. The hook is this plugin's own.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::AS_HOOK );
		}
	}

	/**
	 * Queue a tick for every live job that has gone quiet for longer than a
	 * lock may and has nothing queued: a job whose tick died before the
	 * rescue existed, or whose queued tick was cleared when the plugin was
	 * deactivated. Called once a day from the log prune's cron event
	 * (BLT_Toolkit_Log::run_daily_prune()), which costs one options query.
	 *
	 * @return array[] Every live job (stored state), after queueing.
	 */
	public static function resume_stalled() {
		$live   = array();
		$now    = time();
		$budget = self::budget( self::filtered_budget(), 0 );

		foreach ( self::job_ids() as $id ) {
			$job = self::get( $id );

			if ( null === $job || self::is_terminal( $job['status'] ) ) {
				continue;
			}

			$live[] = $job;

			if ( self::needs_resume( $job, $now, $budget ) && ! self::continuation_pending( $id ) ) {
				self::dispatch( $id );
			}
		}

		return $live;
	}

	// ---------- Runner entry points ----------

	/**
	 * WP-Cron callback: one tick of a chained job.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	public static function run_cron( $id ) {
		self::tick( $id );
	}

	/**
	 * Action Scheduler callback: one tick.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	public static function run_action_scheduler( $id ) {
		self::tick( $id );
	}

	/**
	 * admin-ajax.php callback of the loopback request (JOB-1).
	 *
	 * The request carries no cookies, so it is checked with a key derived
	 * from the job ID and the site's salts instead of a nonce. All it can do
	 * is run a tick of a job that exists, which the runner would run anyway.
	 *
	 * @return void
	 */
	public static function run_loopback() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authenticated by the per-job key below; the request has no session.
		$id  = isset( $_POST['job'] ) ? self::clean_id( sanitize_text_field( wp_unslash( $_POST['job'] ) ) ) : '';
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $id || '' === $key || ! hash_equals( self::loopback_key( $id ), $key ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}

		// The caller hung up on purpose; finish the tick anyway.
		ignore_user_abort( true );

		$view = self::tick( $id );

		wp_send_json( array( 'ok' => ! empty( $view['ran'] ) ), 200 );
	}

	/**
	 * admin-post.php handler of the panel's Cancel, Retry failed and Continue
	 * forms, which work without JavaScript.
	 *
	 * Checks the method, that the job exists, the job type's capability and
	 * a nonce bound to the action and the job, then redirects back to the
	 * screen the form was on (post / redirect / get). The job type decides the
	 * capability, which is why these forms are handled here rather than by
	 * BLT_Toolkit_Admin's router, whose actions each carry one fixed
	 * capability.
	 *
	 * @return void
	 */
	public static function handle_post() {
		$action = substr( current_action(), strlen( 'admin_post_' ) );
		$verb   = array_search( $action, self::POST_ACTIONS, true );

		if ( false === $verb ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'This action needs a form submission.', 'blt-toolkit' ), esc_html__( 'BLT Toolkit', 'blt-toolkit' ), array( 'response' => 405 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below, once the job is known (the nonce is bound to it).
		$id  = isset( $_POST['job'] ) ? self::clean_id( sanitize_text_field( wp_unslash( $_POST['job'] ) ) ) : '';
		$job = '' === $id ? null : self::get( $id );

		if ( null === $job ) {
			wp_die( esc_html__( 'That job no longer exists.', 'blt-toolkit' ), esc_html__( 'BLT Toolkit', 'blt-toolkit' ), array( 'response' => 404 ) );
		}

		if ( ! self::current_user_can_use( $job ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'blt-toolkit' ), esc_html__( 'BLT Toolkit', 'blt-toolkit' ), array( 'response' => 403 ) );
		}

		check_admin_referer( self::nonce_action( $verb, $id ), BLT_Toolkit_Admin::NONCE_FIELD );

		if ( 'cancel' === $verb ) {
			self::cancel( $id );
		} elseif ( 'retry' === $verb ) {
			self::retry_failed( $id );
		} else {
			self::tick( $id );
		}

		$back = wp_get_referer();

		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Whether the current user may view, drive, cancel and retry a job: they
	 * hold its type's capability, and the type's `can_use` check, when it
	 * has one, agrees (a bulk rename: only the person who started it or a
	 * toolkit administrator, SEC-6). A job whose type is not registered (its
	 * module is off) cannot be checked, so nobody may.
	 *
	 * @param array $job Stored state.
	 * @return bool
	 */
	public static function current_user_can_use( array $job ) {
		$type = self::type( isset( $job['type'] ) ? $job['type'] : '' );

		if ( null === $type || ! current_user_can( $type['capability'] ) ) {
			return false;
		}

		return null === $type['can_use'] || (bool) call_user_func( $type['can_use'], $job, wp_get_current_user() );
	}

	// ---------- Progress for people ----------

	/**
	 * A job's progress for the screen and the REST routes (JOB-4).
	 *
	 * @param array $job Stored state.
	 * @return array progress() plus `id`, `type`, `label`, `status_label`,
	 *               `badge`, `summary`, `count_text`, `failed_text`,
	 *               `current_text`, `cancel_requested`, `available` (whether
	 *               the type is registered), `runner`, `now`, `stall_after`
	 *               and `error`.
	 */
	public static function view( array $job ) {
		$now  = time();
		$view = self::progress( $job, $now );
		$type = self::type( $job['type'] );

		$view['id']               = (string) $job['id'];
		$view['type']             = (string) $job['type'];
		$view['label']            = null !== $type ? $type['label'] : (string) $job['type'];
		$view['available']        = null !== $type;
		$view['cancel_requested'] = ! self::is_terminal( $job['status'] ) && self::cancel_requested( $job['id'] );
		$view['status_label']     = self::status_label( $view['status'], $view['cancel_requested'] );
		$view['badge']            = self::status_badge( $view['status'] );
		$view['count_text']       = self::count_text( $view );
		$view['failed_text']      = self::failed_text( $view );
		$view['current_text']     = '' !== $view['current'] && 'running' === $view['status']
			/* translators: %s: the item being worked on, such as "bob to carol". */
			? sprintf( __( 'Working on: %s', 'blt-toolkit' ), $view['current'] )
			: '';
		$view['error']       = isset( $job['error'] ) ? (string) $job['error'] : '';
		$view['summary']     = self::summary( $view );
		$view['runner']      = isset( $job['runner'] ) ? (string) $job['runner'] : '';
		$view['now']         = $now;
		$view['stall_after'] = self::STALL_AFTER;

		return $view;
	}

	/**
	 * Load the progress panel's script once, with its settings in the
	 * `bltToolkit.jobs` global (merged into `window.bltToolkit`, so other
	 * BLT Toolkit scripts on the page keep theirs).
	 *
	 * render_ui() calls it; a screen that prints its own markup may call it
	 * directly, from `admin_enqueue_scripts` or while rendering (the script
	 * goes in the footer).
	 *
	 * @return void
	 */
	public static function enqueue_ui() {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			BLT_TOOLKIT_URL . 'assets/js/blt-toolkit-jobs.js',
			array(),
			BLT_TOOLKIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'root'     => esc_url_raw( rest_url( BLT_TOOLKIT_REST_NS . '/jobs/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'interval' => 2000,
			'strings'  => array(
				/* translators: %s: number of seconds. */
				'seconds'       => __( '%s s', 'blt-toolkit' ),
				/* translators: 1: minutes, 2: seconds. */
				'minutes'       => __( '%1$s min %2$s s', 'blt-toolkit' ),
				/* translators: 1: hours, 2: minutes. */
				'hours'         => __( '%1$s h %2$s min', 'blt-toolkit' ),
				/* translators: %s: a duration, such as "2 min 10 s". */
				'about'         => __( 'About %s', 'blt-toolkit' ),
				'calculating'   => __( 'Estimating', 'blt-toolkit' ),
				'none'          => __( 'None', 'blt-toolkit' ),
				'failedIssue'   => self::issue_template( 'failed' ),
				'warningIssue'  => self::issue_template( 'warning' ),
				'issueItem'     => self::issue_template( 'item' ),
				'foreground'    => self::foreground_text(),
				'lost'          => __( 'Lost contact with the server. This page keeps trying; check your connection.', 'blt-toolkit' ),
				'expired'       => __( 'Your session has expired. Reload the page to keep following this job.', 'blt-toolkit' ),
				'gone'          => __( 'This job no longer exists.', 'blt-toolkit' ),
				'forbidden'     => __( 'You can no longer follow this job.', 'blt-toolkit' ),
				'confirmCancel' => __( 'Cancel this job? Items that already finished stay finished.', 'blt-toolkit' ),
			),
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.bltToolkit = window.bltToolkit || {}; window.bltToolkit.jobs = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Print the progress panel for a job (JOB-4) and load its script.
	 *
	 * The panel is complete without JavaScript: the progress as it stands,
	 * and Cancel, Retry failed and Continue as forms posting to
	 * admin-post.php. The script then keeps it current, drives the job from
	 * the page when background processing has stalled, and sends the buttons
	 * through REST. The job ID is in the markup, so a reload, or coming back
	 * after closing the tab, picks the job up again.
	 *
	 * Call it inside a screen's `.wrap.blt-ui`. Prints nothing when the job
	 * does not exist or the current user may not see it.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	public static function render_ui( $id ) {
		$job = self::get( $id );

		if ( null === $job || ! self::current_user_can_use( $job ) ) {
			return;
		}

		self::enqueue_ui();

		$view    = self::view( $job );
		$percent = null === $view['percent'] ? 0 : (int) $view['percent'];
		$issues  = $view['issues'];
		$buttons = array(
			'cancel' => $view['can_cancel'] && ! $view['cancel_requested'],
			'retry'  => $view['can_retry'],
			'tick'   => $view['stalled'],
		);

		// Catalog components that set `display` (callout, toolbar) sit inside
		// a plain wrapper that carries `hidden`, which they would override.
		?>
		<div class="blt-card blt-toolkit-job" data-blt-toolkit-job="<?php echo esc_attr( $view['id'] ); ?>" data-status="<?php echo esc_attr( $view['status'] ); ?>">
			<div class="blt-card-header">
				<h2><?php echo esc_html( $view['label'] ); ?></h2>
				<p data-field="summary" aria-live="polite"><?php echo esc_html( $view['summary'] ); ?></p>
				<div class="blt-card-header-badges">
					<span data-field="badge" class="blt-badge blt-badge-<?php echo esc_attr( $view['badge'] ); ?>"><?php echo esc_html( $view['status_label'] ); ?></span>
				</div>
			</div>
			<div data-field="actions"<?php echo in_array( true, $buttons, true ) ? '' : ' hidden'; ?>>
				<div class="blt-toolbar">
					<?php
					self::action_form( 'cancel', $view['id'], __( 'Cancel', 'blt-toolkit' ), $buttons['cancel'] );
					self::action_form( 'retry', $view['id'], __( 'Retry failed', 'blt-toolkit' ), $buttons['retry'] );
					self::action_form( 'tick', $view['id'], __( 'Continue now', 'blt-toolkit' ), $buttons['tick'] );
					?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-progress" role="progressbar" aria-label="<?php esc_attr_e( 'Progress', 'blt-toolkit' ); ?>" aria-valuemin="0" aria-valuemax="100"<?php echo null === $view['percent'] ? '' : ' aria-valuenow="' . esc_attr( (string) $percent ) . '"'; ?>>
					<div class="blt-progress-fill" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></div>
				</div>

				<div class="blt-stats blt-stack-top">
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Done', 'blt-toolkit' ); ?></span>
						<span class="blt-stat-value" data-field="count"><?php echo esc_html( $view['count_text'] ); ?></span>
						<span class="blt-stat-sub" data-field="failed"><?php echo esc_html( $view['failed_text'] ); ?></span>
					</div>
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Elapsed', 'blt-toolkit' ); ?></span>
						<span class="blt-stat-value" data-field="elapsed"><?php echo esc_html( self::format_duration( $view['elapsed'] ) ); ?></span>
					</div>
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Time left', 'blt-toolkit' ); ?></span>
						<span class="blt-stat-value" data-field="estimate"><?php echo esc_html( self::estimate_text( $view ) ); ?></span>
					</div>
				</div>

				<p class="blt-field-desc" data-field="current"<?php echo '' === $view['current_text'] ? ' hidden' : ''; ?>><?php echo esc_html( $view['current_text'] ); ?></p>

				<div class="blt-stack-top" data-field="stalled"<?php echo $view['stalled'] ? '' : ' hidden'; ?>>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Keep this tab open', 'blt-toolkit' ); ?></strong>
						<span data-field="stalled-text"><?php echo esc_html( self::stalled_text() ); ?></span>
					</div>
				</div>

				<p class="blt-field-desc" data-field="message" role="status" hidden></p>

				<ul class="blt-stack-top" data-field="issues"<?php echo array() === $issues ? ' hidden' : ''; ?>>
					<?php foreach ( $issues as $issue ) : ?>
						<li><?php echo esc_html( self::issue_text( $issue ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * One of the panel's translated patterns for a failed item or a warning,
	 * shared with the script so both build the same line (CONVENTIONS 6.7:
	 * whole patterns, so translators control word order and punctuation).
	 *
	 * @param string $which `failed`, `warning` or `item`.
	 * @return string
	 */
	private static function issue_template( $which ) {
		if ( 'failed' === $which ) {
			/* translators: %s: the item and what went wrong, such as "bob to carol: That username is taken." */
			return __( 'Failed: %s', 'blt-toolkit' );
		}

		if ( 'warning' === $which ) {
			/* translators: %s: the item and the warning, such as "bob to carol: The email could not be sent." */
			return __( 'Warning: %s', 'blt-toolkit' );
		}

		/* translators: 1: a job item, such as "bob to carol", 2: what happened to it. */
		return _x( '%1$s: %2$s', 'job item and its problem', 'blt-toolkit' );
	}

	/**
	 * One failed item or warning as a line of text.
	 *
	 * @param array $issue `kind`, `item` and `message` (see progress()).
	 * @return string
	 */
	private static function issue_text( array $issue ) {
		$text = '' !== $issue['item'] ? sprintf( self::issue_template( 'item' ), $issue['item'], $issue['message'] ) : $issue['message'];

		return sprintf( self::issue_template( 'failed' === $issue['kind'] ? 'failed' : 'warning' ), $text );
	}

	/**
	 * Print one of the panel's forms.
	 *
	 * @param string $verb    `cancel`, `retry` or `tick`.
	 * @param string $id      Job ID.
	 * @param string $label   Button text.
	 * @param bool   $visible Whether it shows now (the script shows and hides it later).
	 * @return void
	 */
	private static function action_form( $verb, $id, $label, $visible ) {
		$referer = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-job-action="<?php echo esc_attr( $verb ); ?>"<?php echo $visible ? '' : ' hidden'; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTIONS[ $verb ] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::nonce_action( $verb, $id ) ) ); ?>" />
			<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $referer ); ?>" />
			<input type="hidden" name="job" value="<?php echo esc_attr( $id ); ?>" />
			<button type="submit" class="button<?php echo 'retry' === $verb ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	// ---------- Pure state logic (unit-tested) ----------

	/**
	 * A new job's state, and its items for items mode. Pure.
	 *
	 * @param string $id      Job ID.
	 * @param string $type    Type key.
	 * @param string $mode    `items` or `cursor`.
	 * @param array  $payload Items, or the initial cursor state.
	 * @param array  $pinned  Pinned values (JOB-3).
	 * @param int    $owner   User the job runs as.
	 * @param int    $now     Unix time.
	 * @return array{job:array,items:array}
	 */
	public static function new_job( $id, $type, $mode, array $payload, array $pinned, $owner, $now ) {
		$items = 'items' === $mode ? array_values( $payload ) : array();
		$total = count( $items );

		$job = array(
			'id'              => (string) $id,
			'type'            => (string) $type,
			'mode'            => 'cursor' === $mode ? 'cursor' : 'items',
			'status'          => 'queued',
			'owner'           => (int) $owner,
			'pinned'          => $pinned,
			'total'           => $total,
			'done'            => 0,
			'failed'          => array(),
			'warnings'        => array(),
			'warning_count'   => 0,
			'queue'           => null,
			'position'        => 0,
			'pass'            => 1,
			'pass_total'      => $total,
			'cursor'          => 'cursor' === $mode ? $payload : array(),
			'steps'           => 0,
			'current'         => '',
			'created_at'      => (int) $now,
			'queued_at'       => (int) $now,
			'started_at'      => 0,
			'pass_started_at' => 0,
			'last_activity'   => (int) $now,
			'finished_at'     => 0,
			'ticks'           => 0,
			'crash'           => array(
				'position' => -1,
				'count'    => 0,
			),
			'error'           => '',
			'runner'          => '',
			// LOG-2: the switch chain the job started under (start()).
			'chain'           => array(),
		);

		return array(
			'job'   => $job,
			'items' => $items,
		);
	}

	/**
	 * Run one tick on a job's state (JOB-2). Pure: the clock and the save
	 * are passed in.
	 *
	 * Starts no new item once `$budget` seconds have passed since the tick
	 * began, but always processes at least one, so a slow item cannot stall a
	 * job forever. Calls `$checkpoint` after every SAVE_EVERY items or
	 * SAVE_SECONDS seconds; it saves the state and answers `continue`,
	 * `cancel` (a cancel was requested: stop, keeping what finished) or
	 * `stop` (another request took the job over: stop without saving).
	 *
	 * @param array    $job        Stored state.
	 * @param array    $type       The registered type (`process`, `describe`).
	 * @param array    $items      The job's items (items mode).
	 * @param float    $budget     Seconds.
	 * @param callable $clock      Returns the time in seconds (float).
	 * @param callable $checkpoint function( array $job ): string.
	 * @return array The new state. `lost_lock` is set when `$checkpoint` said `stop`.
	 */
	public static function run_tick( array $job, array $type, array $items, $budget, callable $clock, callable $checkpoint ) {
		if ( self::is_terminal( $job['status'] ) ) {
			return $job;
		}

		$start = (float) call_user_func( $clock, true );

		if ( 'queued' === $job['status'] ) {
			$job['status']          = 'running';
			$job['pass_started_at'] = (int) $start;

			if ( empty( $job['started_at'] ) ) {
				$job['started_at'] = (int) $start;
			}
		}

		++$job['ticks'];
		$job['last_activity'] = (int) $start;

		$ran        = 0;
		$since_save = 0;
		$saved_at   = $start;

		while ( true ) {
			if ( 'items' === $job['mode'] && (int) $job['position'] >= (int) $job['pass_total'] ) {
				$job = self::complete( $job, (float) call_user_func( $clock, true ) );
				break;
			}

			$now = (float) call_user_func( $clock, true );

			if ( $ran > 0 && ( $now - $start ) >= (float) $budget ) {
				break;
			}

			$finished = 'items' === $job['mode']
				? self::process_item( $job, $type, $items )
				: self::process_step( $job, $type );

			++$ran;
			++$since_save;

			$now                  = (float) call_user_func( $clock, true );
			$job['last_activity'] = (int) $now;

			if ( $finished ) {
				$job = self::complete( $job, $now );
				break;
			}

			if ( $since_save >= self::SAVE_EVERY || ( $now - $saved_at ) >= self::SAVE_SECONDS ) {
				$answer = (string) call_user_func( $checkpoint, $job );

				if ( 'stop' === $answer ) {
					$job['lost_lock'] = true;
					break;
				}

				if ( 'cancel' === $answer ) {
					$job = self::apply_cancel( $job, (int) $now );
					break;
				}

				$since_save = 0;
				$saved_at   = $now;
			}
		}

		return $job;
	}

	/**
	 * Process the next item of the pass. Pure apart from the callable.
	 *
	 * @param array $job   State, updated in place.
	 * @param array $type  Registered type.
	 * @param array $items The job's items.
	 * @return bool Always false; run_tick() sees the end of the pass itself.
	 */
	private static function process_item( array &$job, array $type, array $items ) {
		$position = (int) $job['position'];
		$index    = is_array( $job['queue'] ) ? (int) $job['queue'][ $position ] : $position;

		++$job['position'];
		++$job['steps'];

		if ( ! array_key_exists( $index, $items ) ) {
			self::fail_item( $job, $index, '', __( 'The item was missing from the job.', 'blt-toolkit' ) );

			return false;
		}

		$item  = $items[ $index ];
		$label = self::describe( $type, $item, $index );

		$job['current'] = $label;

		try {
			$result = call_user_func( $type['process'], $item, isset( $job['pinned'] ) ? (array) $job['pinned'] : array() );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'blt_toolkit_job_exception', $e->getMessage() );
		}

		if ( true === $result ) {
			++$job['done'];
		} elseif ( is_string( $result ) && '' !== $result ) {
			++$job['done'];
			self::add_warning( $job, $label, $result );
		} elseif ( is_wp_error( $result ) ) {
			$message = $result->get_error_message();

			self::fail_item( $job, $index, $label, '' !== $message ? $message : (string) $result->get_error_code() );
		} else {
			self::fail_item( $job, $index, $label, __( 'The item could not be processed.', 'blt-toolkit' ) );
		}

		return false;
	}

	/**
	 * Run one step of a cursor job. Pure apart from the callable.
	 *
	 * @param array $job  State, updated in place.
	 * @param array $type Registered type.
	 * @return bool Whether the work is finished.
	 */
	private static function process_step( array &$job, array $type ) {
		$state   = isset( $job['cursor'] ) && is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$pinned  = isset( $job['pinned'] ) ? (array) $job['pinned'] : array();
		$process = $type['process'];

		++$job['steps'];

		try {
			$finished = (bool) $process( $state, $pinned );
		} catch ( \Throwable $e ) {
			$job['cursor'] = $state;
			$job['error']  = self::cut( $e->getMessage() );

			return true;
		}

		if ( isset( $state['current'] ) && is_scalar( $state['current'] ) ) {
			$job['current'] = self::cut( (string) $state['current'] );
		}

		if ( isset( $state['warning'] ) ) {
			if ( is_scalar( $state['warning'] ) && '' !== (string) $state['warning'] ) {
				self::add_warning( $job, $job['current'], (string) $state['warning'] );
			}

			unset( $state['warning'] );
		}

		$job['cursor'] = $state;

		if ( $finished && isset( $state['error'] ) && is_scalar( $state['error'] ) && '' !== (string) $state['error'] ) {
			$job['error'] = self::cut( (string) $state['error'] );
		}

		return $finished;
	}

	/**
	 * End a pass: `done`, or `failed` when the work reported an error. Pure.
	 *
	 * @param array $job State.
	 * @param float $now Time.
	 * @return array
	 */
	private static function complete( array $job, $now ) {
		$job['status']        = '' !== (string) $job['error'] ? 'failed' : 'done';
		$job['finished_at']   = (int) $now;
		$job['last_activity'] = (int) $now;
		$job['current']       = '';

		return $job;
	}

	/**
	 * Cancel a state (JOB-4): finished items stay, the rest are not started.
	 * A final state is returned unchanged. Pure.
	 *
	 * @param array $job State.
	 * @param int   $now Unix time.
	 * @return array
	 */
	public static function apply_cancel( array $job, $now ) {
		if ( self::is_terminal( $job['status'] ) ) {
			return $job;
		}

		$job['status']        = 'cancelled';
		$job['finished_at']   = (int) $now;
		$job['last_activity'] = (int) $now;
		$job['current']       = '';

		if ( empty( $job['pass_started_at'] ) ) {
			$job['pass_started_at'] = (int) $now;
		}

		return $job;
	}

	/**
	 * Stop a state with an error (status `failed`). Pure.
	 *
	 * @param array  $job     State.
	 * @param string $message Why, for people.
	 * @param int    $now     Unix time.
	 * @return array
	 */
	public static function apply_failure( array $job, $message, $now ) {
		if ( self::is_terminal( $job['status'] ) ) {
			return $job;
		}

		$job['error'] = self::cut( (string) $message );

		if ( empty( $job['pass_started_at'] ) ) {
			$job['pass_started_at'] = (int) $now;
		}

		return self::complete( $job, $now );
	}

	/**
	 * Start another pass over the failed items (JOB-4). Pure.
	 *
	 * Only a final items-mode job with failed items can be retried. The
	 * failed items become the new pass, in their original order, and their
	 * reasons are cleared; an item that fails again is recorded again. Items
	 * a cancel left unstarted are not part of the pass.
	 *
	 * @param array $job State.
	 * @param int   $now Unix time.
	 * @return array|WP_Error The new state, or `blt_toolkit_job_not_retryable`.
	 */
	public static function apply_retry( array $job, $now ) {
		if ( 'items' !== $job['mode'] || ! self::is_terminal( $job['status'] ) || empty( $job['failed'] ) ) {
			return new WP_Error( 'blt_toolkit_job_not_retryable', __( 'There are no failed items to retry, or the job is still running.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		$queue = array_map( 'intval', array_keys( (array) $job['failed'] ) );

		sort( $queue, SORT_NUMERIC );

		$job['queue']           = $queue;
		$job['position']        = 0;
		$job['pass_total']      = count( $queue );
		$job['failed']          = array();
		$job['status']          = 'queued';
		$job['error']           = '';
		$job['current']         = '';
		$job['finished_at']     = 0;
		$job['pass_started_at'] = 0;
		$job['queued_at']       = (int) $now;
		$job['last_activity']   = (int) $now;
		$job['crash']           = array(
			'position' => -1,
			'count'    => 0,
		);
		++$job['pass'];

		return $job;
	}

	/**
	 * Account for a tick that died without releasing its lock (a PHP fatal
	 * error or a timeout). Pure.
	 *
	 * Work resumes from the last save. When ticks keep dying at the same
	 * place, MAX_CRASHES times in a row, the item there is marked failed and
	 * skipped (items mode), or the job stops (cursor mode), so one bad item
	 * cannot keep a job from ever finishing.
	 *
	 * @param array $job   State.
	 * @param array $items The job's items.
	 * @param int   $now   Unix time.
	 * @return array
	 */
	public static function apply_crash( array $job, array $items, $now ) {
		if ( self::is_terminal( $job['status'] ) ) {
			return $job;
		}

		$here  = 'items' === $job['mode'] ? (int) $job['position'] : (int) $job['steps'];
		$crash = isset( $job['crash'] ) && is_array( $job['crash'] ) ? $job['crash'] : array(
			'position' => -1,
			'count'    => 0,
		);

		$crash['count']    = (int) $crash['position'] === $here ? (int) $crash['count'] + 1 : 1;
		$crash['position'] = $here;
		$job['crash']      = $crash;

		if ( $crash['count'] < self::MAX_CRASHES ) {
			return $job;
		}

		$reason = __( 'Processing stopped unexpectedly here several times in a row (a PHP error or a time limit), so this item was skipped.', 'blt-toolkit' );

		if ( 'cursor' === $job['mode'] ) {
			return self::apply_failure( $job, $reason, $now );
		}

		if ( $here < (int) $job['pass_total'] ) {
			$index = is_array( $job['queue'] ) ? (int) $job['queue'][ $here ] : $here;
			$label = array_key_exists( $index, $items ) ? self::fallback_label( $items[ $index ], $index ) : '';

			self::fail_item( $job, $index, $label, $reason );

			++$job['position'];
		}

		$job['crash'] = array(
			'position' => -1,
			'count'    => 0,
		);

		return $job;
	}

	/**
	 * Numbers for the progress display (JOB-4). Pure.
	 *
	 * @param array $job State.
	 * @param int   $now Unix time.
	 * @return array {
	 *     @type string   $status       `queued`, `running`, `done`, `cancelled` or `failed`.
	 *     @type string   $mode         `items` or `cursor`.
	 *     @type int|null $total        Items in the job, or the cursor's `total` (null when unknown).
	 *     @type int      $done         Items done (cursor: the cursor's `done`, else steps).
	 *     @type int      $failed_count Items whose last attempt failed.
	 *     @type int      $pass         Pass number, 1 plus each retry.
	 *     @type int      $pass_total   Items in this pass (cursor: as total).
	 *     @type int      $processed    Items of this pass processed so far.
	 *     @type int|null $percent      0 to 100 for this pass, or null when unknown.
	 *     @type int      $elapsed      Seconds since this pass started (to its end once final).
	 *     @type int|null $estimate     Seconds left, or null when it cannot be told yet.
	 *     @type bool     $stalled      Live, and nothing has happened for too long.
	 *     @type bool     $terminal     Final.
	 *     @type bool     $can_cancel   Live.
	 *     @type bool     $can_retry    Final, items mode, with failed items.
	 *     @type string   $current      The item being worked on.
	 *     @type int      $warning_count Warnings recorded.
	 *     @type array[]  $issues       Up to VIEW_ISSUES failed items (`kind` failed) then the newest warnings (`kind` warning), each with `item` and `message`.
	 * }
	 */
	public static function progress( array $job, $now ) {
		$status   = (string) $job['status'];
		$terminal = self::is_terminal( $status );
		$now      = (int) $now;

		if ( 'items' === $job['mode'] ) {
			$total      = (int) $job['total'];
			$done       = (int) $job['done'];
			$pass_total = (int) $job['pass_total'];
			$processed  = min( (int) $job['position'], $pass_total );
			$remaining  = max( 0, $pass_total - $processed );
			$percent    = $pass_total > 0 ? (int) floor( 100 * $processed / $pass_total ) : 100;
		} else {
			$cursor     = isset( $job['cursor'] ) && is_array( $job['cursor'] ) ? $job['cursor'] : array();
			$total      = isset( $cursor['total'] ) && is_numeric( $cursor['total'] ) && (int) $cursor['total'] > 0 ? (int) $cursor['total'] : null;
			$done       = isset( $cursor['done'] ) && is_numeric( $cursor['done'] ) ? max( 0, (int) $cursor['done'] ) : (int) $job['steps'];
			$pass_total = null === $total ? 0 : $total;
			$processed  = null === $total ? $done : min( $done, $total );
			$remaining  = null === $total ? null : max( 0, $total - $processed );
			$percent    = null === $total ? null : (int) floor( 100 * $processed / $total );
		}

		if ( 'done' === $status ) {
			$percent = 100;
		}

		$started  = (int) $job['pass_started_at'];
		$end      = $terminal && ! empty( $job['finished_at'] ) ? (int) $job['finished_at'] : $now;
		$elapsed  = $started > 0 ? max( 0, $end - $started ) : 0;
		$estimate = 'running' === $status && null !== $remaining ? self::estimate( $processed, $remaining, $elapsed ) : null;
		$failed   = isset( $job['failed'] ) && is_array( $job['failed'] ) ? $job['failed'] : array();
		$issues   = array();

		foreach ( $failed as $entry ) {
			if ( count( $issues ) >= self::VIEW_ISSUES ) {
				break;
			}

			$issues[] = array(
				'kind'    => 'failed',
				'item'    => isset( $entry['item'] ) ? (string) $entry['item'] : '',
				'message' => isset( $entry['reason'] ) ? (string) $entry['reason'] : '',
			);
		}

		$warnings = isset( $job['warnings'] ) && is_array( $job['warnings'] ) ? array_reverse( $job['warnings'] ) : array();

		foreach ( $warnings as $entry ) {
			if ( count( $issues ) >= self::VIEW_ISSUES ) {
				break;
			}

			$issues[] = array(
				'kind'    => 'warning',
				'item'    => isset( $entry['item'] ) ? (string) $entry['item'] : '',
				'message' => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			);
		}

		return array(
			'status'        => $status,
			'mode'          => (string) $job['mode'],
			'total'         => $total,
			'done'          => $done,
			'failed_count'  => count( $failed ),
			'pass'          => (int) $job['pass'],
			'pass_total'    => $pass_total,
			'processed'     => $processed,
			'percent'       => $percent,
			'elapsed'       => $elapsed,
			'estimate'      => $estimate,
			'stalled'       => self::is_stalled( $job, $now ),
			'terminal'      => $terminal,
			'can_cancel'    => ! $terminal,
			'can_retry'     => $terminal && 'items' === $job['mode'] && array() !== $failed,
			'current'       => (string) $job['current'],
			'warning_count' => (int) $job['warning_count'],
			'issues'        => $issues,
		);
	}

	/**
	 * Seconds left at the rate so far, or null when there is no rate yet.
	 * Pure.
	 *
	 * @param int $processed Items processed in the elapsed time.
	 * @param int $remaining Items left.
	 * @param int $elapsed   Seconds elapsed.
	 * @return int|null
	 */
	public static function estimate( $processed, $remaining, $elapsed ) {
		$processed = (int) $processed;
		$remaining = (int) $remaining;
		$elapsed   = (int) $elapsed;

		if ( $remaining <= 0 ) {
			return 0;
		}

		if ( $processed < 1 || $elapsed < 1 ) {
			return null;
		}

		return (int) ceil( $remaining * $elapsed / $processed );
	}

	/**
	 * Whether a live job has gone quiet for too long (JOB-4). Pure.
	 *
	 * Running ticks save every few items or seconds, and the next tick starts
	 * right after the last, so silence means no tick is running.
	 *
	 * @param array $job State.
	 * @param int   $now Unix time.
	 * @return bool
	 */
	public static function is_stalled( array $job, $now ) {
		if ( 'queued' === $job['status'] ) {
			return ( (int) $now - (int) $job['queued_at'] ) > self::QUEUED_STALL_AFTER;
		}

		if ( 'running' === $job['status'] ) {
			return ( (int) $now - (int) $job['last_activity'] ) > self::STALL_AFTER;
		}

		return false;
	}

	/**
	 * The tick budget in seconds (JOB-2). Pure.
	 *
	 * The filtered value, at least 1 second, and no more than 60 percent of
	 * PHP's `max_execution_time` when one is set, so a tick ends and saves
	 * before PHP stops it.
	 *
	 * @param mixed $filtered           Value of `blt_toolkit_tick_budget`.
	 * @param int   $max_execution_time PHP's limit in seconds; 0 for none.
	 * @return float
	 */
	public static function budget( $filtered, $max_execution_time ) {
		$budget = is_numeric( $filtered ) ? (float) $filtered : (float) self::DEFAULT_BUDGET;

		if ( ! is_finite( $budget ) || $budget <= 0 ) {
			$budget = (float) self::DEFAULT_BUDGET;
		}

		$budget = max( 1.0, $budget );

		if ( (int) $max_execution_time > 0 ) {
			$budget = min( $budget, max( 1.0, 0.6 * (int) $max_execution_time ) );
		}

		return $budget;
	}

	/**
	 * How long a lock may go without a heartbeat before another request may
	 * take the job over, in seconds. Pure.
	 *
	 * @param float $budget Tick budget.
	 * @return int
	 */
	public static function lock_stale_after( $budget ) {
		return (int) max( 120, ceil( 4 * (float) $budget ) );
	}

	/**
	 * When a rescue tick should run for a lock taken at `$locked_at`: a few
	 * seconds after the lock goes stale, so it can take the job over. Pure.
	 *
	 * @param int   $locked_at Unix time the lock was taken or last refreshed.
	 * @param float $budget    Tick budget.
	 * @return int Unix time.
	 */
	public static function rescue_at( $locked_at, $budget ) {
		return (int) $locked_at + self::lock_stale_after( $budget ) + self::RESCUE_GRACE;
	}

	/**
	 * Whether a job has gone quiet for longer than any live tick could
	 * (resume_stalled()). Pure.
	 *
	 * @param array $job    State.
	 * @param int   $now    Unix time.
	 * @param float $budget Tick budget.
	 * @return bool
	 */
	public static function needs_resume( array $job, $now, $budget ) {
		if ( self::is_terminal( isset( $job['status'] ) ? $job['status'] : 'done' ) ) {
			return false;
		}

		return ( (int) $now - (int) $job['last_activity'] ) > self::lock_stale_after( $budget ) + self::RESCUE_GRACE;
	}

	/**
	 * Whether a status is final. Pure.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_terminal( $status ) {
		return in_array( (string) $status, self::TERMINAL, true );
	}

	/**
	 * Record a failed item. Pure.
	 *
	 * @param array  $job    State, updated in place.
	 * @param int    $index  Item index.
	 * @param string $label  Item label.
	 * @param string $reason Why.
	 * @return void
	 */
	private static function fail_item( array &$job, $index, $label, $reason ) {
		$job['failed'][ (int) $index ] = array(
			'item'   => self::cut( (string) $label ),
			'reason' => self::cut( (string) $reason ),
		);
	}

	/**
	 * Record a warning, keeping the newest MAX_WARNINGS. Pure.
	 *
	 * @param array  $job     State, updated in place.
	 * @param string $label   Item label.
	 * @param string $message Warning.
	 * @return void
	 */
	private static function add_warning( array &$job, $label, $message ) {
		$job['warnings'][] = array(
			'item'    => self::cut( (string) $label ),
			'message' => self::cut( (string) $message ),
		);

		++$job['warning_count'];

		if ( count( $job['warnings'] ) > self::MAX_WARNINGS ) {
			$job['warnings'] = array_slice( $job['warnings'], -self::MAX_WARNINGS );
		}
	}

	/**
	 * The label of an item: the type's `describe` callback, or a fallback.
	 *
	 * @param array $type  Registered type.
	 * @param mixed $item  Item.
	 * @param int   $index Index.
	 * @return string
	 */
	private static function describe( array $type, $item, $index ) {
		if ( ! empty( $type['describe'] ) && is_callable( $type['describe'] ) ) {
			try {
				$label = call_user_func( $type['describe'], $item );

				if ( is_scalar( $label ) && '' !== (string) $label ) {
					return self::cut( (string) $label );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return self::fallback_label( $item, $index );
	}

	/**
	 * A label for an item without a `describe` callback: a scalar as itself,
	 * an array's `label`, otherwise its position. Pure.
	 *
	 * @param mixed $item  Item.
	 * @param int   $index Index.
	 * @return string
	 */
	private static function fallback_label( $item, $index ) {
		if ( is_scalar( $item ) && '' !== (string) $item ) {
			return self::cut( (string) $item );
		}

		if ( is_array( $item ) && isset( $item['label'] ) && is_scalar( $item['label'] ) ) {
			return self::cut( (string) $item['label'] );
		}

		/* translators: %d: the item's position in the job, starting at 1. */
		return sprintf( __( 'Item %d', 'blt-toolkit' ), (int) $index + 1 );
	}

	/**
	 * Cut text to MAX_TEXT characters, never through a character. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function cut( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_substr' ) ? (string) mb_substr( $text, 0, self::MAX_TEXT, 'UTF-8' ) : substr( $text, 0, self::MAX_TEXT );
	}

	// ---------- Storage, locking and the runner ----------

	/**
	 * Save the state from inside a tick: heartbeat the lock, save, and see
	 * whether a cancel was requested.
	 *
	 * @param string $id    Job ID.
	 * @param string $token Lock token.
	 * @param array  $job   State.
	 * @return string `continue`, `cancel` or `stop`.
	 */
	private static function checkpoint( $id, $token, array $job ) {
		if ( ! self::heartbeat( $id, $token ) ) {
			return 'stop';
		}

		self::save( $id, $job );

		return self::cancel_requested( $id ) ? 'cancel' : 'continue';
	}

	/**
	 * Store the state (autoload no).
	 *
	 * The options cache entry is dropped first, so update_option() compares
	 * with what the database holds and never skips the write because this
	 * request remembers an older copy.
	 *
	 * @param string $id  Job ID.
	 * @param array  $job State.
	 * @return void
	 */
	private static function save( $id, array $job ) {
		unset( $job['lost_lock'] );

		$name = self::state_option( $id );

		wp_cache_delete( $name, 'options' );
		update_option( $name, $job, false );
	}

	/**
	 * After a job reaches a final status: drop the cancel request, unschedule
	 * what is pending, and fire `blt_toolkit_after_job`.
	 *
	 * @param string $id  Job ID.
	 * @param array  $job Final state.
	 * @param string $was Status before this request changed it.
	 * @return void
	 */
	private static function finish( $id, array $job, $was ) {
		self::clear_cancel( $id );

		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::AS_HOOK, array( $id ), self::AS_GROUP );
		}

		if ( ! self::is_terminal( $was ) ) {
			/**
			 * Fires once when a background job ends: finished, cancelled or
			 * stopped by an error. Items that finished before a cancel are
			 * kept (JOB-4). Fires again at the end of each retry pass.
			 *
			 * @param array $job The final job state, including done and failed counts.
			 */
			do_action( 'blt_toolkit_after_job', $job );
		}
	}

	/**
	 * Queue the next tick (JOB-1): an Action Scheduler async action, or a
	 * single WP-Cron event plus a loopback request.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	private static function dispatch( $id ) {
		self::schedule_tick( $id, 0 );

		if ( 'action_scheduler' !== self::runner() ) {
			self::loopback( $id );
		}
	}

	/**
	 * Make the job's one queued tick run at `$at` (0: now), replacing any
	 * tick already queued, such as the rescue of the tick that is ending.
	 *
	 * The job's own action or event is the one running, if any: Action
	 * Scheduler counts it as in progress, not pending, and WP-Cron takes an
	 * event off the schedule before running it, so it is never cancelled
	 * here.
	 *
	 * @param string $id Job ID.
	 * @param int    $at Unix time, or 0 for as soon as possible.
	 * @return void
	 */
	private static function schedule_tick( $id, $at ) {
		$args = array( $id );

		if ( 'action_scheduler' === self::runner() ) {
			as_unschedule_all_actions( self::AS_HOOK, $args, self::AS_GROUP );

			if ( $at > 0 ) {
				as_schedule_single_action( $at, self::AS_HOOK, $args, self::AS_GROUP );
			} else {
				as_enqueue_async_action( self::AS_HOOK, $args, self::AS_GROUP );
			}

			return;
		}

		// WP-Cron refuses a second event with the same hook and arguments
		// within ten minutes, so the old one goes first.
		wp_clear_scheduled_hook( self::CRON_HOOK, $args );
		wp_schedule_single_event( $at > 0 ? $at : time(), self::CRON_HOOK, $args );
	}

	/**
	 * Queue the rescue tick of a tick that just took the lock (JOB-1): it
	 * runs once the lock would be stale, and replaces whatever was queued.
	 *
	 * @param string $id     Job ID.
	 * @param float  $budget Tick budget.
	 * @return void
	 */
	private static function arm_rescue( $id, $budget ) {
		self::schedule_tick( $id, self::rescue_at( time(), $budget ) );
	}

	/**
	 * Make sure a tick is queued for a job whose lock another request holds:
	 * when nothing is, queue one for when that lock goes stale.
	 *
	 * @param string $id     Job ID.
	 * @param float  $budget Tick budget.
	 * @return void
	 */
	private static function ensure_continuation( $id, $budget ) {
		if ( self::continuation_pending( $id ) ) {
			return;
		}

		$locked_at = self::lock_time( $id );

		self::schedule_tick( $id, self::rescue_at( null === $locked_at ? time() : $locked_at, $budget ) );
	}

	/**
	 * Whether a tick of the job is queued with the runner.
	 *
	 * @param string $id Job ID.
	 * @return bool
	 */
	private static function continuation_pending( $id ) {
		if ( 'action_scheduler' === self::runner() ) {
			return self::action_pending( $id );
		}

		return false !== wp_next_scheduled( self::CRON_HOOK, array( $id ) );
	}

	/**
	 * Which runner this site uses (JOB-1).
	 *
	 * @return string `action_scheduler` or `wp_cron`.
	 */
	private static function runner() {
		return function_exists( 'as_enqueue_async_action' ) ? 'action_scheduler' : 'wp_cron';
	}

	/**
	 * Whether an Action Scheduler action for this job is waiting.
	 *
	 * @param string $id Job ID.
	 * @return bool
	 */
	private static function action_pending( $id ) {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( self::AS_HOOK, array( $id ), self::AS_GROUP );
		}

		return function_exists( 'as_next_scheduled_action' ) && false !== as_next_scheduled_action( self::AS_HOOK, array( $id ), self::AS_GROUP );
	}

	/**
	 * Fire the loopback request and return at once (as spawn_cron() does).
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	private static function loopback( $id ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as spawn_cron() does.
				'body'      => array(
					'action' => self::AJAX_ACTION,
					'job'    => $id,
					'key'    => self::loopback_key( $id ),
				),
			)
		);
	}

	/**
	 * The key a loopback request for a job carries: an HMAC of the job ID
	 * with the site's salts, so nothing secret is stored.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	private static function loopback_key( $id ) {
		return hash_hmac( 'sha256', 'blt_toolkit_job_loopback|' . $id, wp_salt( 'auth' ) );
	}

	/**
	 * Why a job may not run as its owner any more, or ''.
	 *
	 * A job started by a person runs as that person (JOB-3); if they were
	 * deleted or lost the type's capability, it stops. A job started by
	 * WP-Cron (owner 0) runs as nobody.
	 *
	 * @param array $job  State.
	 * @param array $type Registered type.
	 * @return string
	 */
	private static function owner_problem( array $job, array $type ) {
		$owner = (int) $job['owner'];

		if ( $owner <= 0 ) {
			return '';
		}

		$user = get_userdata( $owner );

		if ( ! $user instanceof WP_User ) {
			return __( 'The person who started this job no longer has an account, so it was stopped.', 'blt-toolkit' );
		}

		if ( ! user_can( $user, $type['capability'] ) ) {
			return __( 'The person who started this job is no longer allowed to run it, so it was stopped.', 'blt-toolkit' );
		}

		return '';
	}

	/**
	 * Take a job's lock.
	 *
	 * The lock is a row in the options table, inserted with INSERT IGNORE,
	 * which only one request can win (the technique of
	 * WP_Upgrader::create_lock()). Its value is "token|time"; a holder
	 * refreshes the time at every save. A lock not refreshed for
	 * `$stale_after` seconds belonged to a request that died, and is taken
	 * over with a compare-and-swap UPDATE. It never goes through the options
	 * API, so no cache can hide another request's lock.
	 *
	 * @param string $id          Job ID.
	 * @param int    $stale_after Seconds.
	 * @return array{token:string,takeover:bool}|null Null when another request holds it.
	 */
	private static function lock( $id, $stale_after ) {
		global $wpdb;

		$name  = self::lock_option( $id );
		$token = self::new_id();
		$value = $token . '|' . time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock; the options API cannot insert-if-absent.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );

		if ( 1 === (int) $inserted ) {
			return array(
				'token'    => $token,
				'takeover' => false,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row as it is now.
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( null === $old ) {
			return null;
		}

		$parts = explode( '|', (string) $old );
		$at    = isset( $parts[1] ) ? (int) $parts[1] : 0;

		if ( time() - $at < (int) $stale_after ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the stale value.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, (string) $old ) );

		if ( 1 !== (int) $updated ) {
			return null;
		}

		return array(
			'token'    => $token,
			'takeover' => true,
		);
	}

	/**
	 * When the job's lock was taken or last refreshed, or null when there is
	 * no lock.
	 *
	 * @param string $id Job ID.
	 * @return int|null Unix time.
	 */
	private static function lock_time( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::lock_option( $id ) ) );

		if ( null === $value ) {
			return null;
		}

		$parts = explode( '|', (string) $value );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
	}

	/**
	 * Refresh the lock's time, if this request still holds it.
	 *
	 * @param string $id    Job ID.
	 * @param string $token Lock token.
	 * @return bool False when another request took the job over.
	 */
	private static function heartbeat( $id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $token . '|' . time(), self::lock_option( $id ), $wpdb->esc_like( $token . '|' ) . '%' ) );

		// An UPDATE that writes the same value back counts 0 rows on MySQL,
		// so a second heartbeat within the same second reads the row instead.
		if ( 1 === (int) $updated ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::lock_option( $id ) ) );

		return 0 === strpos( $value, $token . '|' );
	}

	/**
	 * Release the lock, if this request still holds it.
	 *
	 * @param string $id    Job ID.
	 * @param string $token Lock token.
	 * @return void
	 */
	private static function unlock( $id, $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::lock_option( $id ), $wpdb->esc_like( $token . '|' ) . '%' ) );
	}

	/**
	 * Ask a running tick to cancel the job at its next save.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	private static function request_cancel( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A flag another request reads past every cache.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::cancel_option( $id ), (string) time() ) );
	}

	/**
	 * Whether a cancel was requested.
	 *
	 * @param string $id Job ID.
	 * @return bool
	 */
	private static function cancel_requested( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See request_cancel().
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::cancel_option( $id ) ) );
	}

	/**
	 * Drop a cancel request.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	private static function clear_cancel( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See request_cancel().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::cancel_option( $id ) ) );
	}

	/**
	 * Read an option straight from the database, unserialized.
	 *
	 * @param string $name Option name.
	 * @return mixed Null when absent.
	 */
	private static function read_option( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Job state is shared between requests; a cached copy may be outdated.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}

	/**
	 * A job's items.
	 *
	 * @param string $id Job ID.
	 * @return array
	 */
	private static function items( $id ) {
		$items = self::read_option( self::items_option( $id ) );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * IDs of every stored job.
	 *
	 * @return string[]
	 */
	private static function job_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Listing our own options by prefix; there is no API for it.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPTION_PREFIX ) . '%' ) );
		$ids   = array();

		foreach ( (array) $names as $name ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::OPTION_PREFIX, '/' ) . '([a-f0-9]{16})$/', (string) $name, $match ) ) {
				$ids[] = $match[1];
			}
		}

		return $ids;
	}

	/**
	 * Remove jobs that ended over a week ago, or were abandoned unfinished
	 * for a month (their type's module switched off, say). Runs when a job
	 * starts.
	 *
	 * @return void
	 */
	private static function collect_garbage() {
		$now = time();

		foreach ( self::job_ids() as $id ) {
			$job = self::get( $id );

			if ( null === $job ) {
				$old = true;
			} elseif ( self::is_terminal( $job['status'] ) ) {
				$old = ( $now - (int) $job['finished_at'] ) > self::KEEP_FINISHED;
			} else {
				$old = ( $now - (int) $job['last_activity'] ) > self::KEEP_ABANDONED;
			}

			if ( $old ) {
				self::delete( $id );
			}
		}
	}

	/**
	 * Delete everything stored for a job.
	 *
	 * @param string $id Job ID.
	 * @return void
	 */
	private static function delete( $id ) {
		delete_option( self::state_option( $id ) );
		delete_option( self::items_option( $id ) );
		self::clear_cancel( $id );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::lock_option( $id ) ) );

		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );
	}

	/**
	 * A new random ID: 16 lowercase hex characters.
	 *
	 * @return string
	 */
	private static function new_id() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}
	}

	/**
	 * A job ID if well formed, else ''.
	 *
	 * @param mixed $id Candidate.
	 * @return string
	 */
	public static function clean_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $id ) ? $id : '';
	}

	/**
	 * Option holding a job's state.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	private static function state_option( $id ) {
		return self::OPTION_PREFIX . $id;
	}

	/**
	 * Option holding a job's items.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	private static function items_option( $id ) {
		return self::OPTION_PREFIX . $id . '_items';
	}

	/**
	 * Row holding a job's lock.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	private static function lock_option( $id ) {
		return self::OPTION_PREFIX . $id . '_lock';
	}

	/**
	 * Row holding a job's cancel request.
	 *
	 * @param string $id Job ID.
	 * @return string
	 */
	private static function cancel_option( $id ) {
		return self::OPTION_PREFIX . $id . '_cancel';
	}

	/**
	 * The nonce action of a panel form, bound to the job.
	 *
	 * @param string $verb `cancel`, `retry` or `tick`.
	 * @param string $id   Job ID.
	 * @return string
	 */
	private static function nonce_action( $verb, $id ) {
		return 'blt_toolkit_job_' . $verb . '|' . $id;
	}

	/**
	 * `blt_toolkit_tick_budget` (JOB-2).
	 *
	 * @return mixed
	 */
	private static function filtered_budget() {
		/**
		 * Filter the seconds one background job tick may run before it saves
		 * its state and yields (JOB-2).
		 *
		 * @param int $seconds Default 15.
		 */
		return apply_filters( 'blt_toolkit_tick_budget', self::DEFAULT_BUDGET );
	}

	/**
	 * A view with the result of a tick added.
	 *
	 * @param array  $view View.
	 * @param bool   $ran  Whether work ran.
	 * @param string $note Why not, or ''.
	 * @return array
	 */
	private static function with_note( array $view, $ran, $note ) {
		$view['ran']  = (bool) $ran;
		$view['note'] = (string) $note;

		return $view;
	}

	/**
	 * The not-found error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'blt_toolkit_job_not_found', __( 'That job no longer exists.', 'blt-toolkit' ), array( 'status' => 404 ) );
	}

	// ---------- Words ----------

	/**
	 * A status in words.
	 *
	 * @param string $status           Status.
	 * @param bool   $cancel_requested Whether a cancel is on its way.
	 * @return string
	 */
	private static function status_label( $status, $cancel_requested ) {
		if ( $cancel_requested ) {
			return __( 'Cancelling', 'blt-toolkit' );
		}

		switch ( $status ) {
			case 'queued':
				return __( 'Waiting', 'blt-toolkit' );
			case 'running':
				return __( 'Running', 'blt-toolkit' );
			case 'done':
				return __( 'Finished', 'blt-toolkit' );
			case 'cancelled':
				return __( 'Cancelled', 'blt-toolkit' );
		}

		return __( 'Stopped', 'blt-toolkit' );
	}

	/**
	 * The badge style for a status (DESIGN 2, Badge).
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_badge( $status ) {
		switch ( $status ) {
			case 'running':
				return 'on';
			case 'done':
				return 'confirmed';
			case 'cancelled':
				return 'off';
			case 'failed':
				return 'cancelled';
		}

		return 'pending';
	}

	/**
	 * "37 of 200", or a count when the total is unknown.
	 *
	 * @param array $view progress().
	 * @return string
	 */
	private static function count_text( array $view ) {
		if ( 'items' === $view['mode'] ) {
			return sprintf(
				/* translators: 1: items done, 2: items in the job. */
				__( '%1$s of %2$s', 'blt-toolkit' ),
				number_format_i18n( $view['done'] ),
				number_format_i18n( (int) $view['total'] )
			);
		}

		if ( null !== $view['total'] ) {
			return sprintf(
				/* translators: 1: units done, 2: units in all. */
				__( '%1$s of %2$s', 'blt-toolkit' ),
				number_format_i18n( $view['processed'] ),
				number_format_i18n( (int) $view['total'] )
			);
		}

		return number_format_i18n( $view['done'] );
	}

	/**
	 * "2 failed", or ''.
	 *
	 * @param array $view View.
	 * @return string
	 */
	private static function failed_text( array $view ) {
		if ( $view['failed_count'] <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: number of items. */
			_n( '%s failed', '%s failed', $view['failed_count'], 'blt-toolkit' ),
			number_format_i18n( $view['failed_count'] )
		);
	}

	/**
	 * The estimate in words for the server-rendered panel.
	 *
	 * @param array $view View.
	 * @return string
	 */
	private static function estimate_text( array $view ) {
		if ( 'running' !== $view['status'] && 'queued' !== $view['status'] ) {
			return __( 'None', 'blt-toolkit' );
		}

		if ( null === $view['estimate'] ) {
			return __( 'Estimating', 'blt-toolkit' );
		}

		/* translators: %s: a duration, such as "2 min 10 s". */
		return sprintf( __( 'About %s', 'blt-toolkit' ), self::format_duration( $view['estimate'] ) );
	}

	/**
	 * One sentence saying where the job stands.
	 *
	 * @param array $view View (without `summary`).
	 * @return string
	 */
	private static function summary( array $view ) {
		$done   = number_format_i18n( $view['done'] );
		$failed = (int) $view['failed_count'];

		if ( ! empty( $view['cancel_requested'] ) ) {
			return __( 'Cancelling: the item in progress finishes first, then the job stops.', 'blt-toolkit' );
		}

		switch ( $view['status'] ) {
			case 'queued':
				return __( 'Waiting to start.', 'blt-toolkit' );

			case 'running':
				if ( 'items' !== $view['mode'] && null === $view['total'] ) {
					/* translators: %s: number of steps. */
					return sprintf( _n( 'Working: %s step so far.', 'Working: %s steps so far.', $view['done'], 'blt-toolkit' ), $done );
				}

				/* translators: 1: number processed, 2: number in all. */
				return sprintf( __( 'Working: %1$s of %2$s processed.', 'blt-toolkit' ), number_format_i18n( $view['processed'] ), number_format_i18n( (int) $view['pass_total'] ) );

			case 'done':
				if ( 'items' !== $view['mode'] ) {
					return __( 'Finished.', 'blt-toolkit' );
				}

				if ( $failed > 0 ) {
					/* translators: 1: number of items done, 2: number of items that failed. */
					return sprintf( _n( 'Finished: %1$s done, %2$s failed. You can retry the failed item.', 'Finished: %1$s done, %2$s failed. You can retry the failed items.', $failed, 'blt-toolkit' ), $done, number_format_i18n( $failed ) );
				}

				/* translators: %s: number of items. */
				return sprintf( _n( 'Finished: %s item done.', 'Finished: all %s items done.', $view['done'], 'blt-toolkit' ), $done );

			case 'cancelled':
				if ( 'items' !== $view['mode'] ) {
					return __( 'Cancelled. The work done before the cancel is kept.', 'blt-toolkit' );
				}

				/* translators: 1: number of items done, 2: number of items in the job. */
				return sprintf( __( 'Cancelled: %1$s of %2$s done. The rest were not started.', 'blt-toolkit' ), $done, number_format_i18n( (int) $view['total'] ) );
		}

		return __( 'Stopped before the end.', 'blt-toolkit' ) . ( '' !== (string) ( isset( $view['error'] ) ? $view['error'] : '' ) ? ' ' . $view['error'] : '' );
	}

	/**
	 * Seconds as "12 s", "4 min 10 s" or "1 h 5 min".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			/* translators: %s: number of seconds. */
			return sprintf( __( '%s s', 'blt-toolkit' ), number_format_i18n( $seconds ) );
		}

		if ( $seconds < 3600 ) {
			/* translators: 1: minutes, 2: seconds. */
			return sprintf( __( '%1$s min %2$s s', 'blt-toolkit' ), number_format_i18n( (int) floor( $seconds / 60 ) ), number_format_i18n( $seconds % 60 ) );
		}

		/* translators: 1: hours, 2: minutes. */
		return sprintf( __( '%1$s h %2$s min', 'blt-toolkit' ), number_format_i18n( (int) floor( $seconds / 3600 ) ), number_format_i18n( (int) floor( ( $seconds % 3600 ) / 60 ) ) );
	}

	/**
	 * The stalled notice without JavaScript.
	 *
	 * @return string
	 */
	private static function stalled_text() {
		return __( 'Background processing does not seem to be running on this server. With JavaScript on, this page does the work while it stays open; without it, press Continue now to run the next part.', 'blt-toolkit' );
	}

	/**
	 * The stalled notice while the script drives the job.
	 *
	 * @return string
	 */
	private static function foreground_text() {
		return __( 'Background processing is not running on this server, so this page is doing the work. Keep this tab open until the job finishes.', 'blt-toolkit' );
	}
}
