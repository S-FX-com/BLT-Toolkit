<?php
/**
 * Reset (RO-43) and Sync administrator (RO-5): the pure parts of
 * BLT_Toolkit_Reset and BLT_Toolkit_Sync.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Reset and BLT_Toolkit_Sync.
 */
class Test_Reset extends TestCase {

	/**
	 * BLT Toolkit's capabilities used by these tests.
	 *
	 * @var string[]
	 */
	private $own = array( 'blt_edit_roles', 'blt_reset_roles' );

	/**
	 * The lockout-critical set used by these tests.
	 *
	 * @var string[]
	 */
	private $lockout = array( 'read', 'manage_options', 'blt_edit_roles' );

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A small stand-in for what populate_roles() creates.
	 *
	 * @return array
	 */
	private function defaults() {
		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => true,
					'edit_posts'     => true,
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'read'            => true,
					'edit_posts'      => true,
					'unfiltered_html' => true,
				),
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => array( 'read' => true ),
			),
		);
	}

	/**
	 * A site's roles today: an edited Editor with a new name, a plugin's
	 * capability on Administrator, no Subscriber, and a custom role.
	 *
	 * @return array
	 */
	private function current() {
		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'               => true,
					'manage_options'     => true,
					'edit_posts'         => true,
					'manage_woocommerce' => true,
					'blt_reset_roles'    => false,
				),
			),
			'shop_helper'   => array(
				'name'         => 'Shop Helper',
				'capabilities' => array( 'read' => true ),
			),
			'editor'        => array(
				'name'         => 'Chief Editor',
				'capabilities' => array(
					'read'               => true,
					'edit_posts'         => true,
					'edit_theme_options' => true,
				),
			),
		);
	}

	/**
	 * RO-43 with RS-30: the roles a reset gives WordPress's defaults also
	 * get the strings of a type that has separate permissions, matching the
	 * Posts capabilities the defaults hold, so the type stays editable;
	 * custom roles kept by the built-in reset are not touched.
	 *
	 * @return void
	 */
	public function test_ro43_rs30_reset_seeds_isolated_types() {
		$maps = array(
			'event' => array(
				'label' => 'Events',
				'map'   => array(
					'edit_posts' => array( 'edit_events', 'create_events' ),
				),
				'edit'  => 'edit_events',
			),
		);

		$current                                = $this->current();
		$current['shop_helper']['capabilities'] = array(
			'read'       => true,
			'edit_posts' => true,
		);

		$builtin = BLT_Toolkit_Reset::target( 'builtin', $current, $this->defaults(), array(), array(), $maps );

		$this->assertTrue( $builtin['administrator']['capabilities']['edit_events'] );
		$this->assertTrue( $builtin['editor']['capabilities']['create_events'] );
		$this->assertArrayNotHasKey( 'edit_events', $builtin['subscriber']['capabilities'] );
		$this->assertArrayNotHasKey( 'edit_events', $builtin['shop_helper']['capabilities'], 'A custom role keeps exactly what it has.' );

		$full = BLT_Toolkit_Reset::target( 'full', $current, $this->defaults(), array(), array(), $maps );

		$this->assertTrue( $full['administrator']['capabilities']['edit_events'] );
		$this->assertSame( array(), BLT_Toolkit_Snapshots::orphaned_types( $full, $maps ) );

		$plain = BLT_Toolkit_Reset::target( 'full', $current, $this->defaults(), array(), array() );

		$this->assertSame( array( 'event' ), BLT_Toolkit_Snapshots::orphaned_types( $plain, $maps ), 'Without seeding nobody could edit Events.' );
	}

	/**
	 * RO-43: the typed confirmation is RESET in capitals; spaces around it
	 * are ignored, anything else refuses.
	 *
	 * @return void
	 */
	public function test_ro43_word_confirmed() {
		$this->assertTrue( BLT_Toolkit_Reset::word_confirmed( 'RESET' ) );
		$this->assertTrue( BLT_Toolkit_Reset::word_confirmed( '  RESET ' ) );
		$this->assertFalse( BLT_Toolkit_Reset::word_confirmed( 'reset' ) );
		$this->assertFalse( BLT_Toolkit_Reset::word_confirmed( 'Reset' ) );
		$this->assertFalse( BLT_Toolkit_Reset::word_confirmed( '' ) );
		$this->assertFalse( BLT_Toolkit_Reset::word_confirmed( 'RESET!' ) );
		$this->assertFalse( BLT_Toolkit_Reset::word_confirmed( array( 'RESET' ) ) );
		$this->assertFalse( BLT_Toolkit_Reset::word_confirmed( null ) );
	}

	/**
	 * RO-43: only `full` picks the full reset; anything else is the
	 * default, built-in roles only.
	 *
	 * @return void
	 */
	public function test_ro43_mode_defaults_to_builtin() {
		$this->assertSame( 'full', BLT_Toolkit_Reset::mode( 'full' ) );
		$this->assertSame( 'builtin', BLT_Toolkit_Reset::mode( 'builtin' ) );
		$this->assertSame( 'builtin', BLT_Toolkit_Reset::mode( 'FULL' ) );
		$this->assertSame( 'builtin', BLT_Toolkit_Reset::mode( '' ) );
		$this->assertSame( 'builtin', BLT_Toolkit_Reset::mode( null ) );
	}

	/**
	 * RO-43, built-in reset: built-in roles get the default capabilities and
	 * keep their names, a missing built-in role comes back, custom roles and
	 * the role order stay, and Administrator gets BLT Toolkit's capabilities
	 * and the lockout set back (a deny of one included).
	 *
	 * @return void
	 */
	public function test_ro43_builtin_reset_target() {
		$out = BLT_Toolkit_Reset::target( 'builtin', $this->current(), $this->defaults(), $this->own, $this->lockout );

		$this->assertSame( array( 'administrator', 'shop_helper', 'editor', 'subscriber' ), array_keys( $out ) );
		$this->assertSame(
			array(
				'name'         => 'Chief Editor',
				'capabilities' => array(
					'read'            => true,
					'edit_posts'      => true,
					'unfiltered_html' => true,
				),
			),
			$out['editor']
		);
		$this->assertSame( $this->current()['shop_helper'], $out['shop_helper'] );
		$this->assertSame( $this->defaults()['subscriber'], $out['subscriber'] );
		$this->assertSame(
			array(
				'read'            => true,
				'manage_options'  => true,
				'edit_posts'      => true,
				'blt_edit_roles'  => true,
				'blt_reset_roles' => true,
			),
			$out['administrator']['capabilities']
		);
	}

	/**
	 * RO-43, full reset: the roles become the defaults exactly, custom roles
	 * and names included, plus BLT Toolkit's capabilities on Administrator.
	 *
	 * @return void
	 */
	public function test_ro43_full_reset_target() {
		$out = BLT_Toolkit_Reset::target( 'full', $this->current(), $this->defaults(), $this->own, $this->lockout );

		$this->assertSame( array( 'administrator', 'editor', 'subscriber' ), array_keys( $out ) );
		$this->assertSame( 'Editor', $out['editor']['name'] );
		$this->assertSame( $this->defaults()['editor'], $out['editor'] );
		$this->assertTrue( $out['administrator']['capabilities']['blt_reset_roles'] );
		$this->assertArrayNotHasKey( 'manage_woocommerce', $out['administrator']['capabilities'] );
	}

	/**
	 * SEC-12: whatever the defaults say, the Administrator role written by a
	 * reset exists and holds the lockout-critical set.
	 *
	 * @return void
	 */
	public function test_ro43_reset_keeps_administrator_safe() {
		$out = BLT_Toolkit_Reset::target( 'full', array(), array( 'subscriber' => $this->defaults()['subscriber'] ), array(), $this->lockout );

		$this->assertSame( 'Administrator', $out['administrator']['name'] );

		foreach ( $this->lockout as $cap ) {
			$this->assertTrue( $out['administrator']['capabilities'][ $cap ] );
		}
	}

	/**
	 * RO-43: the custom roles a full reset removes are listed with their
	 * names; a built-in reset removes none.
	 *
	 * @return void
	 */
	public function test_ro43_removed_roles() {
		$current = $this->current();
		$full    = BLT_Toolkit_Reset::target( 'full', $current, $this->defaults(), $this->own, $this->lockout );
		$builtin = BLT_Toolkit_Reset::target( 'builtin', $current, $this->defaults(), $this->own, $this->lockout );

		$this->assertSame( array( 'shop_helper' => 'Shop Helper' ), BLT_Toolkit_Reset::removed_roles( $current, $full ) );
		$this->assertSame( array(), BLT_Toolkit_Reset::removed_roles( $current, $builtin ) );
		$this->assertSame( array( 'odd' => 'odd' ), BLT_Toolkit_Reset::removed_roles( array( 'odd' => 'not a role' ), array() ) );
	}

	/**
	 * RO-5: Sync grants only registered capabilities the role does not
	 * mention: never orphans, deprecated or multisite-only ones, read-only
	 * markers, meta capability names, role names or a deny someone chose.
	 *
	 * @return void
	 */
	public function test_ro5_sync_missing() {
		$entries = array(
			'edit_books'             => array(),
			'publish_books'          => array( 'orphan' => false ),
			'manage_options'         => array(),
			'old_plugin_cap'         => array( 'orphan' => true ),
			'level_3'                => array( 'deprecated' => true ),
			'manage_network'         => array( 'multisite' => true ),
			'blt_readonly_books'     => array(),
			'blt_switch_users'       => array(),
			'edit_post'              => array(),
			'shop_manager'           => array(),
			'view_site_health_check' => array(),
			'do_not_allow'           => array(),
		);
		$admin   = array(
			'manage_options'         => true,
			'view_site_health_check' => false,
		);

		$out = BLT_Toolkit_Sync::missing( $entries, $admin, array( 'edit_post', 'shop_manager' ) );

		$this->assertSame( array( 'blt_switch_users', 'edit_books', 'publish_books' ), $out['grant'] );
		$this->assertSame( array( 'view_site_health_check' ), $out['denied'] );
	}

	/**
	 * RO-5: the confirm token changes when the list or the role changes, and
	 * not with the list's order or `1` versus `true`.
	 *
	 * @return void
	 */
	public function test_ro5_sync_plan_hash() {
		$hash = BLT_Toolkit_Sync::plan_hash( array( 'b', 'a' ), array( 'read' => true ) );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertSame( $hash, BLT_Toolkit_Sync::plan_hash( array( 'a', 'b' ), array( 'read' => 1 ) ) );
		$this->assertNotSame( $hash, BLT_Toolkit_Sync::plan_hash( array( 'a' ), array( 'read' => true ) ) );
		$this->assertNotSame(
			$hash,
			BLT_Toolkit_Sync::plan_hash(
				array( 'a', 'b' ),
				array(
					'read' => true,
					'x'    => true,
				)
			)
		);
	}
}
