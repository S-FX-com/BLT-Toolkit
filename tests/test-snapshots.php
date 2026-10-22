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

		$out = BLT_Toolkit_Snapshots::restorable_roles( array( 'editor' => array( 'name' => 'Editor', 'capabilities' => array() ) ), $current, $this->lockout, $this->own );

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
}
