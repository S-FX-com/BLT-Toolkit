<?php
/**
 * The session rules of the switch module: binding the old-user cookie to
 * the current session (SW-5, SW-10, SW-17) and following switched sessions
 * back to the person who signed in (SW-5, LOG-2).
 *
 * Reading sessions from WordPress, destroying an orphaned session on
 * `determine_current_user` and expiring stale cookies need a real install
 * and are smoke-tested there (tasks/todo.md).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Switch::is_bound() and follow_links().
 */
class Test_Switch_Session extends TestCase {

	/**
	 * Sessions by "user ID|verifier", for the injected lookup.
	 *
	 * @var array<string,array>
	 */
	private $sessions = array();

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();

		// admin (1, signed in, session v-a) switched to usera (2, session
		// v-ua), who switched to userb (3, session v-ub).
		$this->sessions = array(
			'1|v-a'  => array( 'expiration' => 2000000000 ),
			'2|v-ua' => array(
				'blt_switched_from_id'      => 1,
				'blt_switched_from_session' => 'v-a',
			),
			'3|v-ub' => array(
				'blt_switched_from_id'      => 2,
				'blt_switched_from_session' => 'v-ua',
			),
		);
	}

	/**
	 * The injected session lookup.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Verifier.
	 * @return array|null
	 */
	public function lookup( $user_id, $verifier ) {
		$key = $user_id . '|' . $verifier;

		return isset( $this->sessions[ $key ] ) ? $this->sessions[ $key ] : null;
	}

	/**
	 * SW-10, SW-17: the old-user cookie counts for a signed-in session only
	 * when that session was switched into from that user's very session.
	 * Cookies left by someone else (a sign-in that fired no `wp_login`, or
	 * one made while the module was off) do not.
	 *
	 * @return void
	 */
	public function test_sw10_old_user_cookie_is_bound_to_the_session() {
		$switched = $this->sessions['2|v-ua'];

		$this->assertTrue( BLT_Toolkit_Switch::is_bound( $switched, 1, 'v-a' ) );
		$this->assertTrue( BLT_Toolkit_Switch::is_bound( array( 'blt_switched_from_id' => '1', 'blt_switched_from_session' => 'v-a' ), '1', 'v-a' ), 'Numeric strings from storage.' );

		$this->assertFalse( BLT_Toolkit_Switch::is_bound( $switched, 5, 'v-a' ), 'Another old user.' );
		$this->assertFalse( BLT_Toolkit_Switch::is_bound( $switched, 1, 'v-other' ), 'Another session of the same user.' );
		$this->assertFalse( BLT_Toolkit_Switch::is_bound( array( 'expiration' => 1 ), 1, 'v-a' ), 'A session nobody switched into (a fresh sign-in).' );
		$this->assertFalse( BLT_Toolkit_Switch::is_bound( null, 1, 'v-a' ), 'No session.' );
		$this->assertFalse( BLT_Toolkit_Switch::is_bound( $switched, 0, 'v-a' ) );
		$this->assertFalse( BLT_Toolkit_Switch::is_bound( $switched, 1, '' ) );
		$this->assertFalse( BLT_Toolkit_Switch::is_bound( array( 'blt_switched_from_id' => 1, 'blt_switched_from_session' => array( 'v-a' ) ), 1, 'v-a' ), 'A verifier that is not a string.' );
	}

	/**
	 * LOG-2, SW-5: the chain is rebuilt from the session links, oldest
	 * first, and is intact when it reaches a session nobody switched into.
	 *
	 * @return void
	 */
	public function test_log2_links_lead_back_to_the_person_who_signed_in() {
		$this->assertSame(
			array(
				'ids'    => array( 1, 2 ),
				'intact' => true,
			),
			BLT_Toolkit_Switch::follow_links( $this->sessions['3|v-ub'], array( $this, 'lookup' ) )
		);

		$this->assertSame(
			array(
				'ids'    => array(),
				'intact' => true,
			),
			BLT_Toolkit_Switch::follow_links( $this->sessions['1|v-a'], array( $this, 'lookup' ) ),
			'Not switched.'
		);
	}

	/**
	 * SW-5: when the originating session ends (sign-out, "Log out
	 * everywhere", a password reset, expiry), every session switched from
	 * it, directly or through others, is broken; the chain keeps only the
	 * live part.
	 *
	 * @return void
	 */
	public function test_sw5_a_switched_session_ends_with_its_origin() {
		unset( $this->sessions['1|v-a'] );

		$this->assertSame(
			array(
				'ids'    => array( 2 ),
				'intact' => false,
			),
			BLT_Toolkit_Switch::follow_links( $this->sessions['3|v-ub'], array( $this, 'lookup' ) )
		);

		$this->assertFalse( BLT_Toolkit_Switch::follow_links( $this->sessions['2|v-ua'], array( $this, 'lookup' ) )['intact'] );

		// A middle link gone.
		$this->setUp();
		unset( $this->sessions['2|v-ua'] );

		$this->assertSame(
			array(
				'ids'    => array(),
				'intact' => false,
			),
			BLT_Toolkit_Switch::follow_links( $this->sessions['3|v-ub'], array( $this, 'lookup' ) )
		);
	}

	/**
	 * SW-5: a link without a verifier, a loop and a chain deeper than any
	 * stack are broken, never followed forever.
	 *
	 * @return void
	 */
	public function test_sw5_malformed_links_are_broken() {
		$this->assertFalse( BLT_Toolkit_Switch::follow_links( array( 'blt_switched_from_id' => 1 ), array( $this, 'lookup' ) )['intact'], 'No verifier.' );

		$this->sessions['4|v-x'] = array(
			'blt_switched_from_id'      => 5,
			'blt_switched_from_session' => 'v-y',
		);
		$this->sessions['5|v-y'] = array(
			'blt_switched_from_id'      => 4,
			'blt_switched_from_session' => 'v-x',
		);

		$this->assertFalse( BLT_Toolkit_Switch::follow_links( $this->sessions['4|v-x'], array( $this, 'lookup' ) )['intact'], 'A loop.' );

		// Twelve nested switches, deeper than MAX_DEPTH.
		$this->sessions = array( '100|v100' => array() );

		for ( $i = 101; $i <= 112; $i++ ) {
			$this->sessions[ $i . '|v' . $i ] = array(
				'blt_switched_from_id'      => $i - 1,
				'blt_switched_from_session' => 'v' . ( $i - 1 ),
			);
		}

		$deep = BLT_Toolkit_Switch::follow_links( $this->sessions['112|v112'], array( $this, 'lookup' ) );

		$this->assertFalse( $deep['intact'] );
		$this->assertCount( BLT_Toolkit_Switch_Cookies::MAX_DEPTH, $deep['ids'] );
		$this->assertTrue( BLT_Toolkit_Switch::follow_links( $this->sessions['112|v112'], array( $this, 'lookup' ), 12 )['intact'] );
	}
}
