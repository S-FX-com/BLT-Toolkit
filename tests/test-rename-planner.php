<?php
/**
 * Bulk rename planning (UN-30 step 3, D8, SPEC 18) with known vectors.
 *
 * Rows come from BLT_Toolkit_Csv::parse() and are validated by the real
 * BLT_Toolkit_Username_Validator::check(), whose `login_owner` answers from
 * the planner's projection (owner_lookup()), so names freed or claimed by the
 * batch are tested end to end without WordPress.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Rename_Planner.
 */
class Test_Rename_Planner extends TestCase {

	/**
	 * Users on the pretend site. Nobody is called carol, zed or zoe.
	 *
	 * @var array
	 */
	private $users = array(
		1 => array(
			'login' => 'admin',
			'email' => 'owner@example.com',
		),
		2 => array(
			'login' => 'alice',
			'email' => 'alice@example.com',
		),
		3 => array(
			'login' => 'bob',
			'email' => 'bob@example.com',
		),
		4 => array(
			'login' => 'dave',
			'email' => 'dave@example.com',
		),
		5 => array(
			'login' => 'erin',
			'email' => 'erin@example.com',
		),
		6 => array(
			'login' => 'frank',
			'email' => 'frank@example.com',
		),
		7 => array(
			'login' => 'grace',
			'email' => 'grace@example.com',
		),
		8 => array(
			'login' => 'heidi',
			'email' => 'heidi@example.com',
		),
	);

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A database-style lookup over $this->users (case-insensitive).
	 *
	 * @param string $field login or email.
	 * @return callable
	 */
	private function lookup( $field ) {
		$users = $this->users;

		return function ( $value ) use ( $users, $field ) {
			foreach ( $users as $id => $user ) {
				if ( strtolower( $user[ $field ] ) === strtolower( (string) $value ) ) {
					return $id;
				}
			}

			return 0;
		};
	}

	/**
	 * The planner's validate callable: the real validator, with UN-5 answered
	 * from the projection, then the database.
	 *
	 * @param array $calls Receives array( new, user_id, projected ) per call.
	 * @return callable
	 */
	private function validator( &$calls = array() ) {
		$users  = $this->users;
		$logins = $this->lookup( 'login' );
		$emails = $this->lookup( 'email' );

		return function ( $new, $user_id, $projected ) use ( $users, $logins, $emails, &$calls ) {
			$calls[] = array( $new, $user_id, $projected );

			$errors = BLT_Toolkit_Username_Validator::check(
				$new,
				array(
					'user_id'           => $user_id,
					'current_login'     => $users[ $user_id ]['login'],
					'disallowed'        => array( 'admin', 'root' ),
					'login_owner'       => BLT_Toolkit_Rename_Planner::owner_lookup( $projected, $logins ),
					'email_owner'       => $emails,
					'sanitize_user'     => 'sanitize_user',
					'validate_username' => 'validate_username',
				)
			);

			return BLT_Toolkit_Username_Validator::is_valid( $errors ) ? true : $errors->get_error_message();
		};
	}

	/**
	 * Parse a CSV and plan it.
	 *
	 * @param string $csv     File contents.
	 * @param array  $context Planner context.
	 * @param array  $calls   Receives the validator calls.
	 * @return array
	 */
	private function plan( $csv, array $context = array(), &$calls = array() ) {
		$parsed = BLT_Toolkit_Csv::parse( $csv, 5000 );

		$this->assertIsArray( $parsed );

		return BLT_Toolkit_Rename_Planner::plan( $parsed['rows'], $this->users, $this->validator( $calls ), $context );
	}

	/**
	 * Statuses by line, for compact assertions.
	 *
	 * @param array $plan Result of plan().
	 * @return array line => status or "error:code".
	 */
	private function statuses( array $plan ) {
		$out = array();

		foreach ( $plan['rows'] as $row ) {
			$out[ $row['line'] ] = BLT_Toolkit_Rename_Planner::ERROR === $row['status'] ? 'error:' . $row['code'] : $row['status'];
		}

		return $out;
	}

	/**
	 * Execution order as line numbers.
	 *
	 * @param array $plan Result of plan().
	 * @return int[]
	 */
	private function order_lines( array $plan ) {
		$lines = array();

		foreach ( $plan['order'] as $index ) {
			$lines[] = $plan['rows'][ $index ]['line'];
		}

		return $lines;
	}

	/**
	 * SPEC 17 Phase 3, criterion f: `bob` to `carol` and `alice` to `bob`
	 * both succeed, `bob` first, whichever order the file lists them in.
	 *
	 * @return void
	 */
	public function test_un30_chain_runs_dependency_first() {
		$plan = $this->plan( "user_id,new_username\n3,carol\n2,bob\n" );

		$this->assertSame(
			array(
				2 => 'ready',
				3 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( array( 2, 3 ), $this->order_lines( $plan ) );

		$reversed = $this->plan( "user_id,new_username\n2,bob\n3,carol\n" );

		$this->assertSame( array( 3, 2 ), $this->order_lines( $reversed ) );
		$this->assertSame( array( 1, 0 ), $reversed['order'], 'Order holds indexes into rows, which keep file order.' );
		$this->assertSame( 'alice', $reversed['rows'][0]['old'] );
		$this->assertSame( 'bob', $reversed['rows'][0]['new'] );
		$this->assertSame( 2, $reversed['rows'][0]['user_id'] );
	}

	/**
	 * UN-30: a three-long chain runs from its free end, and independent rows
	 * keep file order around it.
	 *
	 * @return void
	 */
	public function test_un30_three_long_chain() {
		$plan = $this->plan( "username,new_username\ngrace,gina\ndave,erin\nerin,frank\nfrank,zoe\nheidi,hana\n" );

		$this->assertSame( array_fill_keys( array( 2, 3, 4, 5, 6 ), 'ready' ), $this->statuses( $plan ) );
		$this->assertSame( array( 2, 5, 4, 3, 6 ), $this->order_lines( $plan ) );
	}

	/**
	 * D8: `alice` and `bob` swapping names is refused, both rows, with the
	 * "Swap detected" reason naming the lines; so is a swap spelled in
	 * another case.
	 *
	 * @return void
	 */
	public function test_d8_two_row_cycle_rejected() {
		$plan = $this->plan( "user_id,new_username\n2,bob\n3,alice\n" );

		$this->assertSame(
			array(
				2 => 'error:cycle',
				3 => 'error:cycle',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'Swap detected: rename one of these to a temporary name first (lines 2, 3).', $plan['rows'][0]['reason'] );
		$this->assertSame( array(), $plan['order'] );

		$this->assertSame(
			array(
				2 => 'error:cycle',
				3 => 'error:cycle',
			),
			$this->statuses( $this->plan( "username,new_username\nalice,BOB\nbob,Alice\n" ) )
		);
	}

	/**
	 * D8: a three-row cycle is refused whole; a row that feeds into the
	 * cycle is blocked, since the name it wants is never freed; unrelated
	 * rows still run.
	 *
	 * @return void
	 */
	public function test_d8_three_row_cycle_and_feeder() {
		$plan = $this->plan( "user_id,new_username\n4,erin\n5,frank\n6,dave\n8,dave2\n7,frank\n" );

		$this->assertSame(
			array(
				2 => 'error:cycle',
				3 => 'error:cycle',
				4 => 'error:cycle',
				5 => 'ready',
				6 => 'error:blocked',
			),
			$this->statuses( $plan )
		);
		$this->assertStringContainsString( '(lines 2, 3, 4)', $plan['rows'][1]['reason'] );
		$this->assertSame( 'frank is still taken: the row on line 4 that would free it cannot run.', $plan['rows'][4]['reason'] );
		$this->assertSame( array( 5 ), $this->order_lines( $plan ) );
	}

	/**
	 * UN-30: two rows claiming one new name (in any case) are both refused.
	 *
	 * @return void
	 */
	public function test_un30_duplicate_targets() {
		$plan = $this->plan( "user_id,new_username\n2,zed\n3,Zed\n4,zoe\n" );

		$this->assertSame(
			array(
				2 => 'error:duplicate_target',
				3 => 'error:duplicate_target',
				4 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'More than one row renames a user to zed (lines 2, 3).', $plan['rows'][0]['reason'] );
	}

	/**
	 * UN-30: one user in two rows (by ID and by username here) is refused in
	 * both, even though each new name alone would be fine.
	 *
	 * @return void
	 */
	public function test_un30_duplicate_users() {
		$plan = $this->plan( "user_id,username,new_username\n2,,alpha\n,ALICE,beta\n" );

		$this->assertSame(
			array(
				2 => 'error:duplicate_user',
				3 => 'error:duplicate_user',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'This user appears in more than one row (lines 2, 3).', $plan['rows'][1]['reason'] );
	}

	/**
	 * UN-30: a name freed by another row is available, and the validator is
	 * asked with the projection that says so (the freed name now belongs to
	 * the row's own user); a freed name claimed twice still conflicts.
	 *
	 * @return void
	 */
	public function test_un30_names_freed_within_batch() {
		$calls = array();
		$plan  = $this->plan( "user_id,new_username\n3,zed\n2,bob\n", array(), $calls );

		$this->assertSame(
			array(
				2 => 'ready',
				3 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertCount( 2, $calls );
		$this->assertSame(
			array(
				'bob'   => 2,
				'alice' => 0,
			),
			$calls[0][2]
		);

		$this->assertSame(
			array(
				2 => 'ready',
				3 => 'error:duplicate_target',
				4 => 'error:duplicate_target',
			),
			$this->statuses( $this->plan( "user_id,new_username\n3,zed\n2,bob\n4,bob\n" ) )
		);
	}

	/**
	 * UN-30: without a row freeing it, an existing user's name is taken,
	 * including the name of a user whose row makes no change.
	 *
	 * @return void
	 */
	public function test_un30_name_not_freed_is_taken() {
		$plan = $this->plan( "user_id,new_username\n2,bob\n" );

		$this->assertSame( array( 2 => 'error:invalid' ), $this->statuses( $plan ) );
		$this->assertSame( 'That username is already taken.', $plan['rows'][0]['reason'] );

		$this->assertSame(
			array(
				2 => 'no_change',
				3 => 'error:invalid',
			),
			$this->statuses( $this->plan( "user_id,new_username\n3,bob\n2,bob\n" ) )
		);
	}

	/**
	 * UN-30: when the row that would free a name fails validation, the rows
	 * waiting for that name are blocked, down the whole chain.
	 *
	 * @return void
	 */
	public function test_un30_failed_row_blocks_its_chain() {
		$plan = $this->plan( "user_id,new_username\n3,Root\n2,bob\n4,alice\n7,newgrace\n" );

		$this->assertSame(
			array(
				2 => 'error:invalid',
				3 => 'error:blocked',
				4 => 'error:blocked',
				5 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'That username is reserved and cannot be used.', $plan['rows'][0]['reason'] );
		$this->assertSame( 'bob is still taken: the row on line 2 that would free it cannot run.', $plan['rows'][1]['reason'] );
		$this->assertSame( 'alice is still taken: the row on line 3 that would free it cannot run.', $plan['rows'][2]['reason'] );
		$this->assertSame( array( 5 ), $this->order_lines( $plan ) );
	}

	/**
	 * UN-30 (SEC rules per row): may_rename's reason is shown, a refused row
	 * blocks the row waiting for its name, and the callable is asked only
	 * about rows that could otherwise run.
	 *
	 * @return void
	 */
	public function test_un30_sec_refusals() {
		$asked = array();
		$plan  = $this->plan(
			"user_id,new_username\n1,boss\n3,zed\n2,bob\n4,dave\n99,x\n5,erin2\n6,frank2\n",
			array(
				'may_rename' => function ( $user_id ) use ( &$asked ) {
					$asked[] = $user_id;

					if ( 1 === $user_id ) {
						return 'You cannot rename an administrator.';
					}

					if ( 3 === $user_id ) {
						return false;
					}

					if ( 6 === $user_id ) {
						return new WP_Error( 'x', 'Refused by the Guard.' );
					}

					return true;
				},
			)
		);

		$this->assertSame(
			array(
				2 => 'error:not_allowed',
				3 => 'error:not_allowed',
				4 => 'error:blocked',
				5 => 'no_change',
				6 => 'error:unknown_user',
				7 => 'ready',
				8 => 'error:not_allowed',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'You cannot rename an administrator.', $plan['rows'][0]['reason'] );
		$this->assertSame( "You cannot change this user's username.", $plan['rows'][1]['reason'] );
		$this->assertSame( 'Refused by the Guard.', $plan['rows'][6]['reason'] );
		$this->assertSame( array( 1, 3, 2, 5, 6 ), $asked );
	}

	/**
	 * UN-30 step 4: "this is you" for the actor's own row, including a
	 * change of case only, which is a valid change (UN-5) and not a
	 * dependency on itself.
	 *
	 * @return void
	 */
	public function test_un30_self_rows() {
		$plan = $this->plan( "user_id,new_username\n2,Alice\n3,bobby\n", array( 'actor_id' => 2 ) );

		$this->assertSame(
			array(
				2 => 'ready',
				3 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( array( 'self' ), array_keys( $plan['rows'][0]['warnings'] ) );
		$this->assertSame( 'This is your own account.', $plan['rows'][0]['warnings']['self'] );
		$this->assertSame( array(), $plan['rows'][1]['warnings'] );
		$this->assertSame( array( 2, 3 ), $this->order_lines( $plan ) );
	}

	/**
	 * UN-30 step 4: the switched-session (UN-12) and author URL (UN-13)
	 * warnings come from the callables, which run only for ready rows.
	 *
	 * @return void
	 */
	public function test_un30_warnings_only_for_ready_rows() {
		$switched = array();
		$slugs    = array();
		$plan     = $this->plan(
			"user_id,new_username\n2,alpha\n3,Root\n4,delta\n5,\n",
			array(
				'switched'         => function ( $user_id ) use ( &$switched ) {
					$switched[] = $user_id;

					return 2 === $user_id;
				},
				'nicename_changes' => function ( $user_id, $new ) use ( &$slugs ) {
					$slugs[] = array( $user_id, $new );

					return 4 === $user_id;
				},
			)
		);

		$this->assertSame( array( 2, 4 ), $switched );
		$this->assertSame(
			array(
				array( 2, 'alpha' ),
				array( 4, 'delta' ),
			),
			$slugs
		);
		$this->assertSame( array( 'switched' ), array_keys( $plan['rows'][0]['warnings'] ) );
		$this->assertSame( array( 'author_url' ), array_keys( $plan['rows'][2]['warnings'] ) );
		$this->assertSame( 'The author URL will change.', $plan['rows'][2]['warnings']['author_url'] );
		$this->assertSame( array(), $plan['rows'][1]['warnings'] );
	}

	/**
	 * UN-30: the current username exactly is "no change", while a change of
	 * case is a change; no-change rows are never validated. Rows with a
	 * blank new username (the downloaded template is all blank) never reach
	 * the planner from a file: BLT_Toolkit_Csv::parse() counts them instead.
	 *
	 * @return void
	 */
	public function test_un30_no_change_rows() {
		$calls = array();
		$plan  = $this->plan( "user_id,username,new_username\n1,admin,\n2,alice,alice\n3,bob,  \n4,dave,Dave\n", array(), $calls );

		$this->assertSame(
			array(
				3 => 'no_change',
				5 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'unchanged', $plan['rows'][0]['code'] );
		$this->assertSame( 'The new username is the same as the current one.', $plan['rows'][0]['reason'] );
		$this->assertCount( 1, $calls );
		$this->assertSame(
			array(
				'ready'     => 1,
				'error'     => 0,
				'no_change' => 1,
				'total'     => 2,
			),
			$plan['counts']
		);
	}

	/**
	 * UN-30, review fix: a row given to the planner with a blank new username
	 * is "no change" before any other check, so cells a spreadsheet rewrote
	 * in rows nobody touched (leading zeros dropped, `12-3` read as a date)
	 * never become errors. A row that does ask for a rename still has to
	 * agree with its username column.
	 *
	 * @return void
	 */
	public function test_un30_blank_rows_are_no_change_before_mismatch() {
		$users = array(
			45 => array(
				'login' => '000123',
				'email' => 'a@example.com',
			),
			46 => array(
				'login' => '12-3',
				'email' => 'b@example.com',
			),
		);
		$rows  = array();

		foreach ( array(
			array( '45', '123', '' ),
			array( '46', '3-Dec', '' ),
			array( '99', 'ghost', '' ),
			array( '1.00E+02', '', '  ' ),
			array( '', 'nobody', '' ),
			array( '45', '123', 'renamed' ),
		) as $i => $cells ) {
			$rows[] = array(
				'line'  => $i + 2,
				'cells' => array(
					'user_id'      => $cells[0],
					'username'     => $cells[1],
					'new_username' => $cells[2],
				),
				'extra' => 0,
			);
		}

		$plan = BLT_Toolkit_Rename_Planner::plan(
			$rows,
			$users,
			function () {
				return true;
			}
		);

		$this->assertSame(
			array(
				2 => 'no_change',
				3 => 'no_change',
				4 => 'no_change',
				5 => 'no_change',
				6 => 'no_change',
				7 => 'error:username_mismatch',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'empty', $plan['rows'][0]['code'] );
		$this->assertSame( 45, $plan['rows'][0]['user_id'], 'A resolved user is still shown.' );
		$this->assertSame( '000123', $plan['rows'][0]['old'] );
		$this->assertSame( 0, $plan['rows'][2]['user_id'] );
		$this->assertSame( 'User ID 45 has the username 000123, not 123.', $plan['rows'][5]['reason'] );
	}

	/**
	 * UN-30: rows naming users that are not there, or not a valid ID, are
	 * errors; usernames resolve exactly, then case-insensitively.
	 *
	 * @return void
	 */
	public function test_un30_unknown_users_and_bad_ids() {
		$plan = $this->plan( "user_id,new_username\n99,x1\nabc,x2\n0,x3\n-2,x4\n2.5,x5\n007,gina\n" );

		$this->assertSame(
			array(
				2 => 'error:unknown_user',
				3 => 'error:bad_user_id',
				4 => 'error:bad_user_id',
				5 => 'error:bad_user_id',
				6 => 'error:bad_user_id',
				7 => 'ready',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'No user has the ID 99.', $plan['rows'][0]['reason'] );
		$this->assertSame( 'abc is not a user ID.', $plan['rows'][1]['reason'] );
		$this->assertSame( 7, $plan['rows'][5]['user_id'], 'Leading zeros still name the user.' );

		$by_name = $this->plan( "username,new_username\nnobody,x1\nHEIDI,hana\n" );

		$this->assertSame(
			array(
				2 => 'error:unknown_user',
				3 => 'ready',
			),
			$this->statuses( $by_name )
		);
		$this->assertSame( 'No user has the username nobody.', $by_name['rows'][0]['reason'] );
		$this->assertSame( 8, $by_name['rows'][1]['user_id'] );
		$this->assertSame( 'heidi', $by_name['rows'][1]['old'] );
	}

	/**
	 * UN-30: both column shapes work; with the template's three columns the
	 * ID wins, a username that disagrees with it is refused, and a row with
	 * only a username still resolves.
	 *
	 * @return void
	 */
	public function test_un30_both_column_shapes() {
		$this->assertSame( array( 2 => 'ready' ), $this->statuses( $this->plan( "user_id,new_username\n2,alpha\n" ) ) );
		$this->assertSame( array( 2 => 'ready' ), $this->statuses( $this->plan( "username,new_username\nalice,alpha\n" ) ) );

		$plan = $this->plan( "user_id,username,new_username\n2,alice,alpha\n3,alice,beta\n,dave,delta\n5,ERIN,echo\n,,orphan\n" );

		$this->assertSame(
			array(
				2 => 'ready',
				3 => 'error:username_mismatch',
				4 => 'ready',
				5 => 'ready',
				6 => 'error:no_user',
			),
			$this->statuses( $plan )
		);
		$this->assertSame( 'User ID 3 has the username bob, not alice.', $plan['rows'][1]['reason'] );
	}

	/**
	 * UN-30: a row with more cells than the header (an unquoted delimiter)
	 * is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function test_un30_extra_cells_refused() {
		$plan = $this->plan( "user_id,new_username\n2,john,smith\n3,bobby\n" );

		$this->assertSame(
			array(
				2 => 'error:extra_cells',
				3 => 'ready',
			),
			$this->statuses( $plan )
		);
	}

	/**
	 * UN-30: the planner tolerates odd input: non-ID keys in $users (a login
	 * index passed alongside) are ignored, and rows that are not arrays
	 * become unresolved rows.
	 *
	 * @return void
	 */
	public function test_un30_tolerates_odd_input() {
		$users              = $this->users;
		$users['by_login'] = array( 'alice' => 2 );
		$users[0]          = array( 'login' => 'ghost' );

		$plan = BLT_Toolkit_Rename_Planner::plan(
			array(
				array(
					'line'  => 2,
					'cells' => array(
						'username'     => 'ghost',
						'new_username' => 'x',
					),
				),
				'junk',
				array(
					'line'  => 4,
					'cells' => array(
						'user_id'      => '2',
						'new_username' => 'alpha',
					),
				),
			),
			$users,
			$this->validator()
		);

		$this->assertSame(
			array(
				2 => 'error:unknown_user',
				0 => 'no_change',
				4 => 'ready',
			),
			$this->statuses( $plan )
		);
	}

	/**
	 * owner_lookup(): names in the projection answer from it (0 when freed),
	 * case-insensitively; every other name asks the fallback.
	 *
	 * @return void
	 */
	public function test_owner_lookup() {
		$asked  = array();
		$lookup = BLT_Toolkit_Rename_Planner::owner_lookup(
			array(
				'bob'   => 2,
				'alice' => 0,
			),
			function ( $login ) use ( &$asked ) {
				$asked[] = $login;

				return 'dave' === $login ? 4 : false;
			}
		);

		$this->assertSame( 2, $lookup( 'BOB' ) );
		$this->assertSame( 0, $lookup( 'alice' ) );
		$this->assertSame( 4, $lookup( 'dave' ) );
		$this->assertSame( 0, $lookup( 'nobody' ) );
		$this->assertSame( array( 'dave', 'nobody' ), $asked );
	}

	/**
	 * UN-30 at the default cap: a 5,000-row chain is planned in order, and a
	 * 5,000-row cycle is refused whole, in well under a second each.
	 *
	 * @return void
	 */
	public function test_un30_five_thousand_rows() {
		$users = array();
		$rows  = array();
		$count = 5000;

		for ( $i = 1; $i <= $count; $i++ ) {
			$users[ $i ] = array(
				'login' => 'u' . $i,
				'email' => 'u' . $i . '@example.com',
			);
			$rows[]      = array(
				'line'  => $i + 1,
				'cells' => array(
					'user_id'      => (string) $i,
					'new_username' => $i < $count ? 'u' . ( $i + 1 ) : 'fresh',
				),
				'extra' => 0,
			);
		}

		$valid = function () {
			return true;
		};
		$start = microtime( true );
		$plan  = BLT_Toolkit_Rename_Planner::plan( $rows, $users, $valid );

		$this->assertSame( $count, $plan['counts']['ready'] );
		$this->assertSame( range( $count - 1, 0 ), $plan['order'] );

		$rows[ $count - 1 ]['cells']['new_username'] = 'u1';

		$cycle = BLT_Toolkit_Rename_Planner::plan( $rows, $users, $valid );

		$this->assertSame( $count, $cycle['counts']['error'] );
		$this->assertSame( 'cycle', $cycle['rows'][123]['code'] );
		$this->assertSame( 'Swap detected: rename one of these to a temporary name first (lines ' . implode( ', ', range( 2, 21 ) ) . ', …).', $cycle['rows'][123]['reason'], 'At most 20 lines are listed.' );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}
}
