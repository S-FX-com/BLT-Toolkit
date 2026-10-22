<?php
/**
 * Capabilities: own caps, risk tiers, lockout-critical set (SPEC 5.1, 5.4, SEC-12).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Caps.
 */
class Test_Caps extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * The nine capabilities of SPEC 5.1 with their tiers.
	 *
	 * @return void
	 */
	public function test_own_caps_match_spec_5_1() {
		$this->assertSame(
			array(
				'blt_switch_users'        => 'critical',
				'blt_change_usernames'    => 'critical',
				'blt_change_own_username' => 'standard',
				'blt_edit_roles'          => 'critical',
				'blt_edit_capabilities'   => 'critical',
				'blt_create_roles'        => 'critical',
				'blt_delete_roles'        => 'critical',
				'blt_reset_roles'         => 'critical',
				'blt_view_toolkit_log'    => 'elevated',
			),
			BLT_Toolkit_Caps::own()
		);

		foreach ( BLT_Toolkit_Caps::own() as $cap => $tier ) {
			$this->assertSame( $tier, BLT_Toolkit_Caps::tier( $cap ), $cap );
		}
	}

	/**
	 * Meta capabilities are never among the primitives.
	 *
	 * @return void
	 */
	public function test_meta_caps_are_not_primitives() {
		$meta = BLT_Toolkit_Caps::meta();

		$this->assertSame( array( 'blt_switch_to_user', 'blt_change_username' ), array_keys( $meta ) );
		$this->assertSame( array(), array_intersect( array_keys( $meta ), array_keys( BLT_Toolkit_Caps::own() ) ) );
	}

	/**
	 * Every Critical capability listed in SPEC 5.4.
	 *
	 * @return void
	 */
	public function test_sec10_critical_tier() {
		$critical = array( 'manage_options', 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'install_themes', 'update_themes', 'delete_themes', 'edit_themes', 'update_core', 'unfiltered_html', 'unfiltered_upload', 'edit_users', 'create_users', 'delete_users', 'remove_users', 'promote_users' );

		foreach ( $critical as $cap ) {
			$this->assertSame( 'critical', BLT_Toolkit_Caps::tier( $cap ), $cap );
		}
	}

	/**
	 * Every Elevated capability listed in SPEC 5.4, including the
	 * `edit_others_*` and `delete_others_*` patterns for any content type.
	 *
	 * @return void
	 */
	public function test_sec10_elevated_tier_including_others_patterns() {
		$elevated = array( 'list_users', 'export', 'import', 'edit_theme_options', 'switch_themes', 'moderate_comments', 'manage_categories', 'edit_others_posts', 'edit_others_pages', 'delete_others_posts', 'delete_others_pages', 'edit_others_products', 'delete_others_tribe_events', 'blt_view_toolkit_log' );

		foreach ( $elevated as $cap ) {
			$this->assertSame( 'elevated', BLT_Toolkit_Caps::tier( $cap ), $cap );
		}
	}

	/**
	 * Everything else is Standard, and a pattern needs something after its
	 * prefix.
	 *
	 * @return void
	 */
	public function test_everything_else_is_standard() {
		$standard = array( 'read', 'edit_posts', 'publish_posts', 'upload_files', 'view_site_health_checks', 'edit_others_', 'my_edit_others_posts', 'blt_change_own_username', 'level_10', '' );

		foreach ( $standard as $cap ) {
			$this->assertSame( 'standard', BLT_Toolkit_Caps::tier( $cap ), $cap );
		}
	}

	/**
	 * The tier filter can add capabilities and patterns, and Critical wins
	 * when a capability lands in both lists.
	 *
	 * @return void
	 */
	public function test_risky_caps_filter() {
		add_filter(
			'blt_toolkit_risky_caps',
			function ( $tiers ) {
				$tiers['critical'][] = 'gravityforms_*';
				$tiers['critical'][] = 'list_users';
				$tiers['elevated'][] = 'manage_woocommerce';
				$tiers['elevated'][] = 42;
				$tiers['elevated'][] = '*';

				return $tiers;
			}
		);

		BLT_Toolkit_Caps::reset();

		$this->assertSame( 'critical', BLT_Toolkit_Caps::tier( 'gravityforms_edit_forms' ) );
		$this->assertSame( 'critical', BLT_Toolkit_Caps::tier( 'list_users' ) );
		$this->assertSame( 'elevated', BLT_Toolkit_Caps::tier( 'manage_woocommerce' ) );
		$this->assertSame( 'standard', BLT_Toolkit_Caps::tier( 'read' ), 'A bare * matches nothing.' );
		$this->assertNotContains( 42, BLT_Toolkit_Caps::tiers()['elevated'] );
	}

	/**
	 * A filter returning junk leaves no tiers rather than breaking.
	 *
	 * @return void
	 */
	public function test_risky_caps_filter_junk() {
		add_filter(
			'blt_toolkit_risky_caps',
			function () {
				return 'junk';
			}
		);

		BLT_Toolkit_Caps::reset();

		$this->assertSame(
			array(
				'critical' => array(),
				'elevated' => array(),
			),
			BLT_Toolkit_Caps::tiers()
		);
		$this->assertSame( 'standard', BLT_Toolkit_Caps::tier( 'manage_options' ) );
	}

	/**
	 * The SEC-12 lockout-critical set.
	 *
	 * @return void
	 */
	public function test_sec12_lockout_critical_set() {
		$this->assertSame(
			array( 'read', 'manage_options', 'list_users', 'edit_users', 'promote_users', 'activate_plugins', 'blt_edit_roles', 'blt_edit_capabilities' ),
			BLT_Toolkit_Caps::lockout_critical()
		);
	}

	/**
	 * The lockout filter can add to the set but never shrink it.
	 *
	 * @return void
	 */
	public function test_sec12_lockout_filter_can_add_but_not_remove() {
		add_filter(
			'blt_toolkit_lockout_critical_caps',
			function () {
				return array( 'manage_woocommerce', 'read', 'edit_*' );
			}
		);

		$caps = BLT_Toolkit_Caps::lockout_critical();

		$this->assertContains( 'manage_woocommerce', $caps );
		$this->assertNotContains( 'edit_*', $caps, 'Wildcards are not lockout entries.' );

		foreach ( array( 'read', 'manage_options', 'list_users', 'edit_users', 'promote_users', 'activate_plugins', 'blt_edit_roles', 'blt_edit_capabilities' ) as $cap ) {
			$this->assertContains( $cap, $caps, $cap );
		}

		$this->assertSame( count( $caps ), count( array_unique( $caps ) ) );
	}
}
