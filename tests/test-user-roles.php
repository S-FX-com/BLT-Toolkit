<?php
/**
 * Roles of users: the pure parts of BLT_Toolkit_User_Roles (US-1, US-2,
 * US-3, US-12).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for additional roles, additional default roles, the
 * bulk change and the cached no-role count.
 */
class Test_User_Roles extends TestCase {

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * US-1: core set the main role, which replaced the one its dropdown
	 * showed; a shown and ticked role stays, a shown and unticked one goes, a
	 * held role that was not shown (the actor may not give it) is kept.
	 *
	 * @return void
	 */
	public function test_us1_desired_roles_after_core_set_role() {
		$this->assertSame(
			array( 'author', 'shop_manager', 'translator' ),
			BLT_Toolkit_User_Roles::desired_roles(
				array( 'editor', 'shop_manager', 'contributor', 'subscriber' ),
				array( 'contributor', 'subscriber', 'translator' ),
				array( 'translator' ),
				'editor',
				'author'
			)
		);
	}

	/**
	 * US-1: without a Role field (core set no role), the main role is not on
	 * the list and is kept; only shown roles change.
	 *
	 * @return void
	 */
	public function test_us1_desired_roles_when_core_set_no_role() {
		$this->assertSame(
			array( 'editor', 'contributor' ),
			BLT_Toolkit_User_Roles::desired_roles(
				array( 'editor', 'subscriber' ),
				array( 'subscriber', 'contributor' ),
				array( 'contributor' ),
				'editor',
				null
			)
		);
	}

	/**
	 * US-1: "No role for this site" as main role, with ticked roles, and the
	 * main role also ticked is not repeated.
	 *
	 * @return void
	 */
	public function test_us1_desired_roles_no_main_role_and_duplicates() {
		$this->assertSame(
			array( 'subscriber' ),
			BLT_Toolkit_User_Roles::desired_roles( array( 'author' ), array( 'subscriber' ), array( 'subscriber' ), 'author', '' )
		);

		$this->assertSame(
			array( 'editor', 'author' ),
			BLT_Toolkit_User_Roles::desired_roles( array( 'subscriber' ), array( 'editor', 'author' ), array( 'editor', 'author' ), 'subscriber', 'editor' )
		);
	}

	/**
	 * US-1, SEC-3: core's Role field takes the place of the role its
	 * dropdown showed, so the roles keep their order and a role that denies
	 * a capability stays after the role that grants it. Putting the main
	 * role first would let the kept `boss` override the `limiter` deny.
	 *
	 * @return void
	 */
	public function test_us1_sec3_desired_roles_keep_order_so_a_deny_still_wins() {
		$this->assertSame(
			array( 'boss', 'limiter' ),
			BLT_Toolkit_User_Roles::desired_roles( array( 'boss', 'limiter' ), array(), array(), 'limiter', 'limiter' )
		);

		$this->assertSame(
			array( 'boss', 'subscriber', 'translator' ),
			BLT_Toolkit_User_Roles::desired_roles( array( 'boss', 'limiter' ), array( 'translator' ), array( 'translator' ), 'limiter', 'subscriber' )
		);

		$this->assertSame(
			array( 'author', 'boss' ),
			BLT_Toolkit_User_Roles::desired_roles( array( 'boss' ), array(), array(), '', 'author' ),
			'With no role shown in the dropdown, the main role comes first, as core sets it.'
		);
	}

	/**
	 * US-1, SEC-3: the capabilities meta is rewritten with its role keys in
	 * the wanted order and every other key kept.
	 *
	 * @return void
	 */
	public function test_us1_sec3_caps_in_role_order() {
		$this->assertSame(
			array(
				'boss'            => true,
				'limiter'         => true,
				'upload_files'    => false,
				'edit_dashboard'  => true,
			),
			BLT_Toolkit_User_Roles::caps_in_role_order(
				array(
					'limiter'        => true,
					'upload_files'   => false,
					'boss'           => true,
					'edit_dashboard' => true,
				),
				array( 'boss', 'limiter', 'gone' )
			)
		);
	}

	/**
	 * US-3, SEC-3: the roles a user holds after a bulk Apply, the added role
	 * last as WP_User::add_role() appends it.
	 *
	 * @return void
	 */
	public function test_us3_sec3_bulk_roles_after() {
		$this->assertSame( array( 'boss' ), BLT_Toolkit_User_Roles::bulk_roles_after( array( 'boss', 'limiter' ), '', 'limiter' ) );
		$this->assertSame( array( 'limiter', 'boss', 'author' ), BLT_Toolkit_User_Roles::bulk_roles_after( array( 'limiter', 'boss' ), 'author', '' ) );
		$this->assertSame( array( 'boss', 'author' ), BLT_Toolkit_User_Roles::bulk_roles_after( array( 'boss', 'author' ), 'author', 'gone' ) );
		$this->assertSame( array( 'boss', 'author' ), BLT_Toolkit_User_Roles::bulk_roles_after( array( 'limiter', 'boss' ), 'author', 'limiter' ) );
	}

	/**
	 * US-1: the main role is the first held role the actor may give, as
	 * wp-admin/user-edit.php picks it.
	 *
	 * @return void
	 */
	public function test_us1_displayed_primary() {
		$this->assertSame( 'subscriber', BLT_Toolkit_User_Roles::displayed_primary( array( 'shop_manager', 'subscriber', 'author' ), array( 'author', 'subscriber' ) ) );
		$this->assertSame( '', BLT_Toolkit_User_Roles::displayed_primary( array( 'shop_manager' ), array( 'author' ) ) );
	}

	/**
	 * US-2: never administrator, never a role that does not exist or is
	 * held, each once, in the setting's order; a creator's limit applies.
	 *
	 * @return void
	 */
	public function test_us2_extra_roles_to_add() {
		$known = array( 'administrator', 'editor', 'author', 'subscriber', 'translator' );
		$extra = array( 'translator', 'administrator', 'gone', 'subscriber', 'author', 'translator', 7 );

		$this->assertSame( array( 'translator', 'author' ), BLT_Toolkit_User_Roles::extra_roles_to_add( $extra, array( 'subscriber' ), $known ) );
		$this->assertSame( array( 'author' ), BLT_Toolkit_User_Roles::extra_roles_to_add( $extra, array( 'subscriber' ), $known, array( 'author', 'subscriber' ) ) );
		$this->assertSame( array(), BLT_Toolkit_User_Roles::extra_roles_to_add( $extra, array(), $known, array() ) );
	}

	/**
	 * US-2, Settings > Roles: only shown roles change; a stored role that was
	 * not shown keeps its setting; administrator and missing roles drop.
	 *
	 * @return void
	 */
	public function test_us2_merge_default_roles() {
		$known = array( 'administrator', 'editor', 'author', 'subscriber', 'translator' );

		$this->assertSame(
			array( 'editor', 'translator', 'subscriber' ),
			BLT_Toolkit_User_Roles::merge_default_roles(
				array( 'editor', 'author', 'gone', 'translator' ),
				array( 'author', 'subscriber', 'translator', 'administrator' ),
				array( 'subscriber', 'translator', 'administrator' ),
				$known
			)
		);
	}

	/**
	 * US-3: add only when not held, remove only when held.
	 *
	 * @return void
	 */
	public function test_us3_bulk_change() {
		$this->assertSame(
			array(
				'add'    => true,
				'remove' => true,
			),
			BLT_Toolkit_User_Roles::bulk_change( array( 'author' ), 'editor', 'author' )
		);
		$this->assertSame(
			array(
				'add'    => false,
				'remove' => false,
			),
			BLT_Toolkit_User_Roles::bulk_change( array( 'editor' ), 'editor', 'author' )
		);
		$this->assertSame(
			array(
				'add'    => false,
				'remove' => false,
			),
			BLT_Toolkit_User_Roles::bulk_change( array(), '', '' )
		);
	}

	/**
	 * US-12: the transient reads as unknown, counting (a job ID) or counted.
	 *
	 * @return void
	 */
	public function test_us12_read_state() {
		$this->assertSame( 'unknown', BLT_Toolkit_User_Roles::read_state( false )['status'] );
		$this->assertSame( 'unknown', BLT_Toolkit_User_Roles::read_state( array( 'job' => 'not-an-id' ) )['status'] );

		$counted = BLT_Toolkit_User_Roles::read_state(
			array(
				'count' => '12',
				'at'    => 1700000000,
			)
		);

		$this->assertSame( 'counted', $counted['status'] );
		$this->assertSame( 12, $counted['count'] );
		$this->assertSame( 1700000000, $counted['at'] );

		$this->assertSame(
			array(
				'status' => 'counted',
				'count'  => 0,
				'at'     => 0,
				'job'    => '',
				'stale'  => false,
			),
			BLT_Toolkit_User_Roles::read_state( array( 'count' => 0 ) )
		);

		$counting = BLT_Toolkit_User_Roles::read_state(
			array(
				'job' => '0123456789abcdef',
				'at'  => 5,
			)
		);

		$this->assertSame( 'counting', $counting['status'] );
		$this->assertSame( '0123456789abcdef', $counting['job'] );
		$this->assertFalse( $counting['stale'] );

		// A role changed while the job ran: the marker stays, flagged.
		$stale = BLT_Toolkit_User_Roles::read_state(
			array(
				'job'   => '0123456789abcdef',
				'at'    => 5,
				'stale' => true,
			)
		);

		$this->assertSame( 'counting', $stale['status'] );
		$this->assertTrue( $stale['stale'] );
		$this->assertTrue( BLT_Toolkit_User_Roles::read_state( array( 'count' => 3, 'stale' => 1 ) )['stale'] );
		$this->assertSame( 'unknown', BLT_Toolkit_User_Roles::read_state( array( 'count' => -1 ) )['status'] );
	}
}
