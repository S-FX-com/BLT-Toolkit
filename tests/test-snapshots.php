<?php
/**
 * Role snapshots: the pure parts of BLT_Toolkit_Snapshots (SNAP-3, SNAP-4,
 * SEC-12 on restore).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Snapshots.
 */
class Test_Snapshots extends TestCase {

	/**
	 * The lockout-critical set used by these tests.
	 *
	 * @var string[]
	 */
	private $lockout = array( 'read', 'manage_options', 'blt_edit_roles' );

	/**
	 * BLT Toolkit's capabilities used by these tests.
	 *
	 * @var string[]
	 */
	private $own = array( 'blt_edit_roles', 'blt_switch_users', 'blt_view_toolkit_log' );

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * SNAP-3: retention is at least 1; nonsense means the default.
	 *
	 * @return void
	 */
	public function test_snap3_clamp_retention() {
		$this->assertSame( 50, BLT_Toolkit_Snapshots::clamp_retention( 50 ) );
		$this->assertSame( 7, BLT_Toolkit_Snapshots::clamp_retention( '7' ) );
		$this->assertSame( 1, BLT_Toolkit_Snapshots::clamp_retention( 0 ) );
		$this->assertSame( 1, BLT_Toolkit_Snapshots::clamp_retention( -3 ) );
		$this->assertSame( 50, BLT_Toolkit_Snapshots::clamp_retention( 'lots' ) );
		$this->assertSame( 50, BLT_Toolkit_Snapshots::clamp_retention( null ) );
	}

	/**
	 * SNAP-4 with SEC-12: restoring a baseline taken before BLT Toolkit
	 * existed keeps the administrator's lockout-critical capabilities and
	 * gives back the BLT Toolkit ones; other roles come back as they were.
	 *
	 * @return void
	 */
	public function test_snap4_restore_keeps_administrator_safe() {
		$snapshot = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => false,
					'edit_posts'     => true,
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array( 'edit_posts' => true ),
			),
		);

		$out = BLT_Toolkit_Snapshots::restorable_roles( $snapshot, array(), $this->lockout, $this->own );

		$this->assertSame(
			array(
				'read'                 => true,
				'manage_options'       => true,
				'edit_posts'           => true,
				'blt_edit_roles'       => true,
				'blt_switch_users'     => true,
				'blt_view_toolkit_log' => true,
			),
			$out['administrator']['capabilities']
		);
		$this->assertSame( $snapshot['editor'], $out['editor'] );
	}

	/**
	 * A BLT Toolkit capability the snapshot explicitly denies stays denied,
	 * unless it is lockout-critical; and a snapshot taken while BLT Toolkit
	 * was in use is restored as it is (SNAP-4), so a BLT Toolkit capability
	 * it does not mention is not added.
	 *
	 * @return void
	 */
	public function test_snap4_restore_keeps_deliberate_denials() {
		$snapshot = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'blt_switch_users' => false,
					'blt_edit_roles'   => false,
				),
			),
		);

		$caps = BLT_Toolkit_Snapshots::restorable_roles( $snapshot, array(), $this->lockout, $this->own )['administrator']['capabilities'];

		$this->assertFalse( $caps['blt_switch_users'] );
		$this->assertTrue( $caps['blt_edit_roles'] );
		$this->assertArrayNotHasKey( 'blt_view_toolkit_log', $caps );
	}

	/**
	 * SNAP-4: restoring a snapshot taken after the owner removed a Critical
	 * BLT Toolkit capability from Administrator does not grant it again; only
	 * the SEC-12 lockout set is forced.
	 *
	 * @return void
	 */
	public function test_snap4_restore_does_not_regrant_removed_blt_caps() {
		$snapshot = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'                 => true,
					'manage_options'       => true,
					'blt_edit_roles'       => true,
					'blt_view_toolkit_log' => true,
				),
			),
		);

		$caps = BLT_Toolkit_Snapshots::restorable_roles( $snapshot, array(), $this->lockout, $this->own )['administrator']['capabilities'];

		$this->assertArrayNotHasKey( 'blt_switch_users', $caps, 'The owner removed it before the snapshot; restoring keeps it removed.' );
		$this->assertSame( $snapshot['administrator']['capabilities'], $caps );
	}

	/**
	 * SEC-12: a snapshot without the administrator role does not delete it;
	 * the role stays as it is today, made safe.
	 *
	 * @return void
	 */
	public function test_snap4_restore_never_removes_administrator() {
		$current = array(
			'administrator' => array(
				'name'         => 'Site boss',
				'capabilities' => array( 'edit_posts' => true ),
			),
		);

		$out = BLT_Toolkit_Snapshots::restorable_roles(
			array(
				'editor' => array(
					'name'         => 'Editor',
					'capabilities' => array(),
				),
			),
			$current,
			$this->lockout,
			$this->own
		);

		$this->assertSame( 'Site boss', $out['administrator']['name'] );
		$this->assertTrue( $out['administrator']['capabilities']['edit_posts'] );
		$this->assertTrue( $out['administrator']['capabilities']['manage_options'] );

		$none = BLT_Toolkit_Snapshots::restorable_roles( array(), array(), $this->lockout, $this->own );

		$this->assertSame( 'Administrator', $none['administrator']['name'] );
		$this->assertTrue( $none['administrator']['capabilities']['read'] );
	}

	/**
	 * The Guard sees each role a restore touches: a created role grants its
	 * capabilities, a deleted role removes all of them, and a changed role
	 * adds, flips and removes.
	 *
	 * @return void
	 */
	public function test_snap4_guard_changes_from_a_diff() {
		$diff = BLT_Toolkit_Role_Diff::diff(
			array(
				'author'  => array(
					'name'         => 'Author',
					'capabilities' => array(
						'read'          => true,
						'publish_posts' => true,
						'upload_files'  => true,
					),
				),
				'shopper' => array(
					'name'         => 'Shopper',
					'capabilities' => array( 'read' => true ),
				),
			),
			array(
				'author' => array(
					'name'         => 'Author',
					'capabilities' => array(
						'read'          => true,
						'publish_posts' => false,
						'edit_posts'    => true,
					),
				),
				'helper' => array(
					'name'         => 'Helper',
					'capabilities' => array( 'list_users' => true ),
				),
			)
		);

		$this->assertSame(
			array(
				'author'  => array(
					'adds'    => array(
						'edit_posts'    => true,
						'publish_posts' => false,
					),
					'removes' => array( 'upload_files' ),
				),
				'helper'  => array(
					'adds'    => array( 'list_users' => true ),
					'removes' => array(),
				),
				'shopper' => array(
					'adds'    => array(),
					'removes' => array( 'read' ),
				),
			),
			BLT_Toolkit_Snapshots::guard_changes( $diff )
		);
	}
	/**
	 * SNAP-4: a restore writes the roles in today's order, roles that are
	 * new coming last, so core's role menus keep their order.
	 *
	 * @return void
	 */
	public function test_snap4_restore_keeps_role_order() {
		$current = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'gone'          => array( 'name' => 'Gone' ),
		);
		$target  = array(
			'added'         => array( 'name' => 'Added' ),
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor 2' ),
		);

		$out = BLT_Toolkit_Snapshots::in_order( $target, $current );

		$this->assertSame( array( 'administrator', 'editor', 'added' ), array_keys( $out ) );
		$this->assertSame( 'Editor 2', $out['editor']['name'] );
	}

	/**
	 * SNAP-4 with SEC-12: the preview names what Administrator gets beyond
	 * the snapshot, split into the lockout set and BLT Toolkit's own
	 * capabilities (the snapshot predates them).
	 *
	 * @return void
	 */
	public function test_snap4_restore_additions() {
		$snapshot = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => false,
					'edit_posts'     => true,
				),
			),
		);
		$target   = BLT_Toolkit_Snapshots::restorable_roles( $snapshot, array(), $this->lockout, $this->own );
		$out      = BLT_Toolkit_Snapshots::restore_additions( $snapshot, array(), $target, $this->lockout, $this->own );

		$this->assertSame( array( 'blt_edit_roles', 'manage_options' ), $out['lockout'] );
		$this->assertSame( array( 'blt_switch_users', 'blt_view_toolkit_log' ), $out['blt'] );
		$this->assertSame( array(), $out['other'] );
		$this->assertFalse( $out['kept_role'] );

		$same = BLT_Toolkit_Snapshots::restore_additions( $target, array(), $target, $this->lockout, $this->own );

		$this->assertSame( array(), array_merge( $same['lockout'], $same['blt'], $same['other'] ), 'A snapshot that already holds everything gets nothing extra.' );
	}

	/**
	 * SNAP-4 with SEC-12: a snapshot without Administrator keeps today's role;
	 * additions are counted against today's copy.
	 *
	 * @return void
	 */
	public function test_snap4_restore_additions_when_snapshot_lacks_admin() {
		$current = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => true,
					'blt_edit_roles' => true,
				),
			),
		);
		$target  = BLT_Toolkit_Snapshots::restorable_roles( array(), $current, $this->lockout, $this->own );
		$out     = BLT_Toolkit_Snapshots::restore_additions( array(), $current, $target, $this->lockout, $this->own );

		$this->assertTrue( $out['kept_role'] );
		$this->assertSame( array(), $out['lockout'] );
		$this->assertSame( array(), $out['blt'], 'Today\'s copy mentions a BLT Toolkit capability, so none is added.' );
	}

	/**
	 * The seed map of an Events type that shared Posts' strings (RS-30).
	 *
	 * @return array isolation_maps() shape.
	 */
	private function event_maps() {
		$old = array(
			'edit_posts'             => 'edit_posts',
			'create_posts'           => 'edit_posts',
			'publish_posts'          => 'publish_posts',
			'edit_others_posts'      => 'edit_others_posts',
			'delete_posts'           => 'delete_posts',
			'read_private_posts'     => 'read_private_posts',
			'edit_published_posts'   => 'edit_published_posts',
			'delete_published_posts' => 'delete_published_posts',
		);
		$new = array(
			'edit_posts'             => 'edit_events',
			'create_posts'           => 'create_events',
			'publish_posts'          => 'publish_events',
			'edit_others_posts'      => 'edit_others_events',
			'delete_posts'           => 'delete_events',
			'read_private_posts'     => 'read_private_events',
			'edit_published_posts'   => 'edit_published_events',
			'delete_published_posts' => 'delete_published_events',
		);

		return array(
			'event' => array(
				'label' => 'Events',
				'map'   => BLT_Toolkit_Isolation::seed_map( $old, $new ),
				'edit'  => 'edit_events',
			),
		);
	}

	/**
	 * SNAP-4 with RS-30: restoring a snapshot taken before a type got
	 * separate permissions (no role mentions its strings) gives each role
	 * the type's strings matching its Posts capabilities, Administrator
	 * included, so the type stays editable by whoever could edit Posts; a
	 * snapshot taken while the type had them is restored as it is.
	 *
	 * @return void
	 */
	public function test_snap4_rs30_restore_seeds_types_isolated_since() {
		$baseline = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'          => true,
					'edit_posts'    => true,
					'publish_posts' => true,
				),
			),
			'author'        => array(
				'name'         => 'Author',
				'capabilities' => array(
					'read'         => true,
					'edit_posts'   => true,
					'delete_posts' => false,
				),
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => array( 'read' => true ),
			),
		);
		$maps     = $this->event_maps();
		$target   = BLT_Toolkit_Snapshots::restorable_roles( $baseline, array(), $this->lockout, $this->own, $maps );

		$this->assertTrue( $target['administrator']['capabilities']['edit_events'] );
		$this->assertTrue( $target['administrator']['capabilities']['create_events'] );
		$this->assertTrue( $target['administrator']['capabilities']['publish_events'] );
		$this->assertTrue( $target['author']['capabilities']['edit_events'] );
		$this->assertFalse( $target['author']['capabilities']['delete_events'], 'A deny maps to a deny.' );
		$this->assertArrayNotHasKey( 'edit_events', $target['subscriber']['capabilities'] );
		$this->assertSame( array(), BLT_Toolkit_Snapshots::orphaned_types( $target, $maps ) );

		$additions = BLT_Toolkit_Snapshots::restore_additions( $baseline, array(), $target, $this->lockout, $this->own, $maps );

		$this->assertSame( array( 'create_events', 'edit_events', 'publish_events' ), $additions['isolated'] );
		$this->assertSame( array(), $additions['other'] );

		// Taken while Events had separate permissions, with Author left out
		// on purpose: restored as it is.
		$later                                            = $baseline;
		$later['administrator']['capabilities']['edit_events'] = true;

		$kept = BLT_Toolkit_Snapshots::seed_isolated( $later, $maps );

		$this->assertSame( array(), $kept['seeded'] );
		$this->assertArrayNotHasKey( 'edit_events', $kept['roles']['author']['capabilities'] );

		$seeded = BLT_Toolkit_Snapshots::seed_isolated( $baseline, $maps );

		$this->assertSame( array( 'event' ), $seeded['seeded'] );
	}

	/**
	 * SNAP-4, RS-32: a type no role lets anyone edit is reported, so the
	 * preview can warn and point to the revert.
	 *
	 * @return void
	 */
	public function test_snap4_rs32_orphaned_types() {
		$maps  = $this->event_maps();
		$roles = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'        => true,
					'edit_events' => false,
				),
			),
		);

		$this->assertSame( array( 'event' ), BLT_Toolkit_Snapshots::orphaned_types( $roles, $maps ) );

		$roles['editor'] = array(
			'name'         => 'Editor',
			'capabilities' => array( 'edit_events' => true ),
		);

		$this->assertSame( array(), BLT_Toolkit_Snapshots::orphaned_types( $roles, $maps ) );
		$this->assertSame( array(), BLT_Toolkit_Snapshots::orphaned_types( $roles, array() ) );
	}

	/**
	 * US-12, RO-43: a role is found in a user's serialized capabilities by
	 * its key, with the length in bytes.
	 *
	 * @return void
	 */
	public function test_us12_role_key() {
		$this->assertSame( 's:13:"administrator";', BLT_Toolkit_Snapshots::role_key( 'administrator' ) );
		$this->assertSame( 's:13:"custom_role_2";', BLT_Toolkit_Snapshots::role_key( 'custom_role_2' ) );
		$this->assertStringContainsString( BLT_Toolkit_Snapshots::role_key( 'custom_role_2' ), serialize( array( 'custom_role_2' => true ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores the capabilities meta this way.
	}

	/**
	 * SEC-10 on restore and reset: every role granted a Critical capability
	 * needs its display name typed (either name accepted, case and spaces
	 * ignored); nothing to confirm passes.
	 *
	 * @return void
	 */
	public function test_sec10_names_confirmed() {
		$names = array(
			'editor' => array(
				'label'  => 'Rédacteur',
				'accept' => array( 'Rédacteur', 'Editor' ),
			),
			'shop'   => array(
				'label'  => 'Shop',
				'accept' => array( 'Shop' ),
			),
		);

		$this->assertTrue( BLT_Toolkit_Snapshots::names_confirmed( array(), array() ) );
		$this->assertTrue( BLT_Toolkit_Snapshots::names_confirmed( array(), 'junk' ) );
		$this->assertTrue(
			BLT_Toolkit_Snapshots::names_confirmed(
				$names,
				array(
					'editor' => ' RÉDACTEUR ',
					'shop'   => 'shop',
				)
			)
		);
		$this->assertTrue(
			BLT_Toolkit_Snapshots::names_confirmed(
				$names,
				array(
					'editor' => 'editor',
					'shop'   => 'Shop',
				)
			)
		);
		$this->assertFalse( BLT_Toolkit_Snapshots::names_confirmed( $names, array( 'editor' => 'Editor' ) ), 'Every role must be confirmed.' );
		$this->assertFalse(
			BLT_Toolkit_Snapshots::names_confirmed(
				$names,
				array(
					'editor' => 'Edtor',
					'shop'   => 'Shop',
				)
			)
		);
		$this->assertFalse(
			BLT_Toolkit_Snapshots::names_confirmed(
				$names,
				array(
					'editor' => array( 'Editor' ),
					'shop'   => 'Shop',
				)
			)
		);
		$this->assertFalse( BLT_Toolkit_Snapshots::names_confirmed( $names, 'Editor' ) );
	}
}
