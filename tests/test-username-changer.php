<?php
/**
 * The pure parts of the rename procedure, author redirects and self-service
 * settings (UN-12, UN-13a, UN-15, UN-20) with known vectors.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure helpers of BLT_Toolkit_Username_Changer,
 * BLT_Toolkit_Author_Redirects and BLT_Toolkit_Admin_Settings.
 */
class Test_Username_Changer extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * UN-12: no session is switched.
	 *
	 * @return void
	 */
	public function test_un12_plain_sessions_are_not_switched() {
		$state = BLT_Toolkit_Username_Changer::switched_flags(
			array(
				array(
					'expiration' => 2,
					'login'      => 1,
				),
				array(),
				'not a session',
			)
		);

		$this->assertFalse( $state['switched_into'] );
		$this->assertFalse( $state['switched_from'] );
		$this->assertSame( array(), $state['from_ids'] );
		$this->assertSame( array(), $state['into_ids'] );
	}

	/**
	 * UN-12: someone is switched into the user (`blt_switched_from_id` on one
	 * of their sessions), and the user is switched into someone
	 * (`blt_switched_into` on one of theirs). IDs are de-duplicated; empty,
	 * zero and non-numeric values do not count.
	 *
	 * @return void
	 */
	public function test_un12_switched_sessions_are_found() {
		$state = BLT_Toolkit_Username_Changer::switched_flags(
			array(
				array( 'blt_switched_from_id' => 7 ),
				array( 'blt_switched_from_id' => '7' ),
				array( 'blt_switched_from_id' => 0 ),
				array( 'blt_switched_into' => 'abc' ),
				array(
					'blt_switched_into' => 9,
					'blt_switched_at'   => 123,
				),
			)
		);

		$this->assertTrue( $state['switched_from'] );
		$this->assertTrue( $state['switched_into'] );
		$this->assertSame( array( 7 ), $state['from_ids'] );
		$this->assertSame( array( 9 ), $state['into_ids'] );
	}

	/**
	 * UN-12, review fix: with lookups, only live switches count, by the rule
	 * of the Switch module's duplicate-switch check (SW-8): a session
	 * switched into the user counts while its links lead back to a live
	 * session that was not switched into; a `blt_switched_into` marker counts
	 * while the account it names has a live session switched in from this
	 * user.
	 *
	 * @return void
	 */
	public function test_un12_only_live_switches_count() {
		// Live sessions by user and verifier.
		$live = array(
			'1|v-admin' => array( 'login' => 1 ),
			'5|v-five'  => array(
				'blt_switched_from_id'      => 1,
				'blt_switched_from_session' => 'v-admin',
			),
			'6|v-loop'  => array(
				'blt_switched_from_id'      => 6,
				'blt_switched_from_session' => 'v-loop',
			),
			'10|v-ten'  => array( 'blt_switched_into' => 20 ),
		);

		$lookup = function ( $user_id, $verifier ) use ( $live ) {
			$key = (int) $user_id . '|' . $verifier;

			return isset( $live[ $key ] ) ? $live[ $key ] : null;
		};

		$target = array(
			// Live: straight back to the admin's session.
			array(
				'blt_switched_from_id'      => 1,
				'blt_switched_from_session' => 'v-admin',
			),
			// Orphaned: the session switched from has ended.
			array(
				'blt_switched_from_id'      => 3,
				'blt_switched_from_session' => 'v-gone',
			),
			// No verifier: cannot be followed.
			array( 'blt_switched_from_id' => 4 ),
			// Live through a chain: 5 was switched into from the admin.
			array(
				'blt_switched_from_id'      => 5,
				'blt_switched_from_session' => 'v-five',
			),
			// A loop never reaches anyone who signed in.
			array(
				'blt_switched_from_id'      => 6,
				'blt_switched_from_session' => 'v-loop',
			),
		);

		$state = BLT_Toolkit_Username_Changer::switched_flags( $target, 2, $lookup );

		$this->assertTrue( $state['switched_from'] );
		$this->assertSame( array( 1, 5 ), $state['from_ids'] );

		$orphaned = BLT_Toolkit_Username_Changer::switched_flags( array_slice( $target, 1, 1 ), 2, $lookup );

		$this->assertFalse( $orphaned['switched_from'], 'An orphaned switched session raises no warning.' );

		// User 10 carries into-markers for 20 (live), 21 (no session from
		// 10) and 22 (switched from a session of 10 that has ended).
		$sessions_of = function ( $user_id ) {
			$by_user = array(
				20 => array(
					array(
						'blt_switched_from_id'      => 10,
						'blt_switched_from_session' => 'v-ten',
					),
				),
				21 => array( array( 'login' => 1 ) ),
				22 => array(
					array(
						'blt_switched_from_id'      => 10,
						'blt_switched_from_session' => 'v-dead',
					),
				),
			);

			return isset( $by_user[ $user_id ] ) ? $by_user[ $user_id ] : array();
		};

		$into = BLT_Toolkit_Username_Changer::switched_flags(
			array(
				array( 'blt_switched_into' => 20 ),
				array( 'blt_switched_into' => 21 ),
				array( 'blt_switched_into' => 22 ),
			),
			10,
			$lookup,
			$sessions_of
		);

		$this->assertTrue( $into['switched_into'] );
		$this->assertSame( array( 20 ), $into['into_ids'] );

		$stale = BLT_Toolkit_Username_Changer::switched_flags( array( array( 'blt_switched_into' => 21 ) ), 10, $lookup, $sessions_of );

		$this->assertFalse( $stale['switched_into'], 'A stale into-marker raises no warning.' );
	}

	/**
	 * UN-12: leads_to_origin() stops after MAX_LINKS links.
	 *
	 * @return void
	 */
	public function test_un12_link_walk_is_bounded() {
		$calls  = 0;
		$lookup = function ( $user_id ) use ( &$calls ) {
			++$calls;

			return array(
				'blt_switched_from_id'      => (int) $user_id + 1,
				'blt_switched_from_session' => 'v' . ( (int) $user_id + 1 ),
			);
		};

		$this->assertFalse(
			BLT_Toolkit_Username_Changer::leads_to_origin(
				array(
					'blt_switched_from_id'      => 1,
					'blt_switched_from_session' => 'v1',
				),
				$lookup
			)
		);
		$this->assertSame( BLT_Toolkit_Username_Changer::MAX_LINKS, $calls );
	}

	/**
	 * UN-15: every tag is replaced, in one pass, so a value that looks like a
	 * tag (a display name of "{sitename}") is not replaced again; unknown
	 * tags stay.
	 *
	 * @return void
	 */
	public function test_un15_tags_are_replaced_once() {
		$tags = BLT_Toolkit_Username_Changer::email_tags(
			array(
				'old_username' => 'jsmith',
				'new_username' => 'JSmith',
				'display_name' => '{sitename}',
				'email'        => 'j@example.com',
				'sitename'     => 'Site',
				'siteurl'      => 'https://example.com/',
				'loginurl'     => 'https://example.com/wp-login.php',
			)
		);

		$this->assertCount( 7, $tags );

		$this->assertSame(
			'Hi {sitename}: jsmith is now JSmith on Site (https://example.com/), j@example.com, https://example.com/wp-login.php {unknown}',
			BLT_Toolkit_Username_Changer::replace_tags( 'Hi {display_name}: {old_username} is now {new_username} on {sitename} ({siteurl}), {email}, {loginurl} {unknown}', $tags )
		);
	}

	/**
	 * UN-15: a missing or non-scalar value becomes an empty string.
	 *
	 * @return void
	 */
	public function test_un15_missing_values_are_empty() {
		$tags = BLT_Toolkit_Username_Changer::email_tags( array( 'old_username' => array( 'x' ) ) );

		$this->assertSame( '', $tags['{old_username}'] );
		$this->assertSame( '', $tags['{loginurl}'] );
	}

	/**
	 * UN-13a: the oldest previous slugs beyond the limit are the ones to drop.
	 *
	 * @return void
	 */
	public function test_un13a_overflow_drops_oldest() {
		$this->assertSame( array(), BLT_Toolkit_Author_Redirects::overflow( array( 'a', 'b' ), 3 ) );
		$this->assertSame( array(), BLT_Toolkit_Author_Redirects::overflow( array( 'a', 'b', 'c' ), 3 ) );
		$this->assertSame( array( 'a', 'b' ), BLT_Toolkit_Author_Redirects::overflow( array( 'a', 'b', 'c', 'd', 'e' ), 3 ) );
		$this->assertSame( array( 'b' ), BLT_Toolkit_Author_Redirects::overflow( array( '', 'b', 'c' ), 1 ) );
	}

	/**
	 * UN-20, RO-1: only toggles the person changed from the drawn state are
	 * written, and only when the role is not already in the wanted state.
	 *
	 * @return void
	 */
	public function test_un20_changes_are_diffs_within_the_page() {
		$shown = array( 'editor', 'author', 'subscriber', 'contributor' );

		// Author switched on, subscriber switched off, editor untouched (on),
		// contributor untouched (off).
		$this->assertSame(
			array(
				'add'    => array( 'author' ),
				'remove' => array( 'subscriber' ),
			),
			BLT_Toolkit_Admin_Settings::self_service_changes(
				array( 'editor', 'author' ),
				$shown,
				array( 'editor', 'subscriber' ),
				array( 'editor', 'subscriber' )
			)
		);

		// Someone else already switched author on and subscriber off since the
		// page was drawn: nothing left to write.
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			BLT_Toolkit_Admin_Settings::self_service_changes(
				array( 'editor', 'author' ),
				$shown,
				array( 'editor', 'subscriber' ),
				array( 'editor', 'author' )
			)
		);

		// Someone else switched contributor on since the page was drawn; this
		// form did not touch it, so it is not undone.
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			BLT_Toolkit_Admin_Settings::self_service_changes(
				array( 'editor' ),
				$shown,
				array( 'editor' ),
				array( 'editor', 'contributor' )
			)
		);
	}

	/**
	 * UN-20: a role that was not shown (added since, or one the person may not
	 * change) is never touched, whatever is posted for it.
	 *
	 * @return void
	 */
	public function test_un20_roles_not_shown_are_left_alone() {
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			BLT_Toolkit_Admin_Settings::self_service_changes(
				array( 'author', 'shop_manager' ),
				array( 'author' ),
				array( 'administrator', 'author' ),
				array( 'administrator', 'author' )
			)
		);
	}

	/**
	 * UN-20: posted role IDs keep their case, lose anything outside
	 * `[A-Za-z0-9_-]`, and must name an existing role.
	 *
	 * @return void
	 */
	public function test_un20_role_ids_are_cleaned() {
		$this->assertSame(
			array( 'author', 'Shop_Manager' ),
			BLT_Toolkit_Admin_Settings::clean_role_ids(
				array( 'author', 'Shop_Manager', 'author', 'ghost', '<b>author</b>x', array( 'editor' ), 5 ),
				array( 'author', 'Shop_Manager', 'editor' )
			)
		);
	}
	/**
	 * UN-22 throttle: wrong current passwords within the window are counted,
	 * oldest first, at most the limit's worth; reaching the limit pauses
	 * renames until the oldest of them is a window old (a sliding window).
	 *
	 * @return void
	 */
	public function test_un22_throttle_pauses_after_the_limit() {
		$now = 100000;

		$this->assertSame(
			array(
				'failures'     => array(),
				'locked_until' => 0,
			),
			BLT_Toolkit_Username_Changer::throttle_state( '', $now, 5, 900 ),
			'Nothing stored (get_user_meta() gives an empty string).'
		);

		$four = array( $now - 800, $now - 600, $now - 400, $now - 10 );

		$this->assertSame(
			array(
				'failures'     => $four,
				'locked_until' => 0,
			),
			BLT_Toolkit_Username_Changer::throttle_state( $four, $now, 5, 900 ),
			'Four wrong passwords: one more try.'
		);

		$five = array_merge( $four, array( $now ) );

		$this->assertSame(
			array(
				'failures'     => $five,
				'locked_until' => $now - 800 + 900,
			),
			BLT_Toolkit_Username_Changer::throttle_state( $five, $now, 5, 900 ),
			'The fifth within 15 minutes pauses until the first is 15 minutes old.'
		);

		// One second after the oldest leaves the window, one try is back.
		$later = BLT_Toolkit_Username_Changer::throttle_state( $five, $now + 100, 5, 900 );

		$this->assertSame( 0, $later['locked_until'] );
		$this->assertCount( 4, $later['failures'] );

		// Old, future, malformed and surplus entries are dropped; order fixed.
		$messy = array( 'x', $now + 60, $now - 5000, $now - 1, null, $now - 3, $now - 2, array( 1 ), $now - 50, $now - 40, $now - 30 );
		$state = BLT_Toolkit_Username_Changer::throttle_state( $messy, $now, 5, 900 );

		$this->assertSame( array( $now - 40, $now - 30, $now - 3, $now - 2, $now - 1 ), $state['failures'], 'The newest five, oldest first.' );
		$this->assertSame( $now - 40 + 900, $state['locked_until'] );

		// A limit of 0 turns the throttle off and keeps nothing.
		$this->assertSame(
			array(
				'failures'     => array(),
				'locked_until' => 0,
			),
			BLT_Toolkit_Username_Changer::throttle_state( $five, $now, 0, 900 )
		);
	}

	/**
	 * UN-22 throttle: the filtered value is two bounded integers, with the
	 * default for anything missing or not a number (5 within 15 minutes).
	 *
	 * @return void
	 */
	public function test_un22_throttle_filter_value_is_cleaned() {
		$default = array(
			'limit'  => 5,
			'window' => 900,
		);

		$this->assertSame( $default, BLT_Toolkit_Username_Changer::clean_throttle( null ) );
		$this->assertSame( $default, BLT_Toolkit_Username_Changer::clean_throttle( array( 'limit' => 'many' ) ) );
		$this->assertSame(
			array(
				'limit'  => 0,
				'window' => 900,
			),
			BLT_Toolkit_Username_Changer::clean_throttle( array( 'limit' => -3 ) ),
			'Off.'
		);
		$this->assertSame(
			array(
				'limit'  => 100,
				'window' => 86400,
			),
			BLT_Toolkit_Username_Changer::clean_throttle(
				array(
					'limit'  => '1000',
					'window' => 10 * 86400,
				)
			)
		);
		$this->assertSame( 1, BLT_Toolkit_Username_Changer::clean_throttle( array( 'window' => 0 ) )['window'] );
	}
}
