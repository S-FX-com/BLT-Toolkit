<?php
/**
 * Settings: defaults, merging, hand-sanitized saving and the GitHub token.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Settings.
 */
class Test_Settings extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * SPEC 14.1, copied verbatim.
	 *
	 * @return array
	 */
	private function spec_defaults() {
		return array(
			'modules'   => array(
				'switch'    => true,
				'usernames' => true,
				'roles'     => true,
			),
			'switch'    => array(
				'footer_link'  => true,
				'switch_off'   => true,
				'search'       => true,
				'compat_hooks' => true,
				'woocommerce'  => true,
			),
			'usernames' => array(
				'min_length'         => 3,
				'disallowed'         => array( 'admin', 'administrator', 'root', 'sysadmin', 'webmaster' ),
				'allow_at_sign'      => true,
				'nicename_policy'    => 'if_derived',
				'redirect_old_slugs' => true,
				'sign_out_others'    => true,
				'notify_user'        => false,
				'email_subject'      => '',
				'email_body'         => '',
				'cooldown_days'      => 0,
				'require_password'   => false,
			),
			'roles'     => array(
				'default_view'        => 'simple',
				'show_deprecated'     => false,
				'readable_labels'     => true,
				'hide_admins'         => true,
				'extra_default_roles' => array(),
				'role_meta'           => array(),
				'isolated_post_types' => array(),
				'snapshot_retention'  => 50,
			),
			'log'       => array(
				'retention_days' => 365,
				'record_ip'      => true,
			),
			'advanced'  => array(
				'delete_data_on_uninstall' => false,
			),
		);
	}

	/**
	 * Defaults are SPEC 14.1 exactly: same keys, order, types and values.
	 *
	 * @return void
	 */
	public function test_defaults_equal_spec_14_1() {
		$this->assertSame( $this->spec_defaults(), BLT_Toolkit_Settings::defaults() );
	}

	/**
	 * With nothing stored, all() is the defaults.
	 *
	 * @return void
	 */
	public function test_all_without_a_stored_option_is_the_defaults() {
		$this->assertSame( $this->spec_defaults(), BLT_Toolkit_Settings::all() );
	}

	/**
	 * seed() stores the defaults autoloaded, and never overwrites.
	 *
	 * @return void
	 */
	public function test_seed_stores_defaults_once() {
		BLT_Toolkit_Settings::seed();

		$this->assertSame( $this->spec_defaults(), $GLOBALS['blt_test_options']['blt_toolkit_settings'] );
		$this->assertTrue( $GLOBALS['blt_test_autoload']['blt_toolkit_settings'] );

		$GLOBALS['blt_test_options']['blt_toolkit_settings']['log']['retention_days'] = 30;

		BLT_Toolkit_Settings::seed();

		$this->assertSame( 30, $GLOBALS['blt_test_options']['blt_toolkit_settings']['log']['retention_days'] );
	}

	/**
	 * Stored values merge over defaults per group; lists are replaced whole.
	 *
	 * @return void
	 */
	public function test_stored_values_merge_per_group_and_lists_replace_wholesale() {
		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array(
			'modules'   => array( 'switch' => false ),
			'usernames' => array( 'disallowed' => array( 'root' ) ),
			'log'       => array( 'retention_days' => 30 ),
		);

		$all = BLT_Toolkit_Settings::all();

		$this->assertFalse( $all['modules']['switch'] );
		$this->assertTrue( $all['modules']['roles'], 'A module missing from the stored map keeps its default.' );
		$this->assertSame( array( 'root' ), $all['usernames']['disallowed'], 'Lists are not merged with the default list.' );
		$this->assertSame( 3, $all['usernames']['min_length'] );
		$this->assertSame( 30, $all['log']['retention_days'] );
		$this->assertTrue( $all['log']['record_ip'] );
		$this->assertSame( 30, BLT_Toolkit_Settings::get( 'log', 'retention_days' ) );
		$this->assertSame( $all['switch'], BLT_Toolkit_Settings::get( 'switch' ) );
		$this->assertNull( BLT_Toolkit_Settings::get( 'nope' ) );
		$this->assertNull( BLT_Toolkit_Settings::get( 'log', 'nope' ) );
	}

	/**
	 * A damaged option cannot break reading it.
	 *
	 * @return void
	 */
	public function test_damaged_option_reads_as_defaults() {
		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = 'garbage';

		$this->assertSame( $this->spec_defaults(), BLT_Toolkit_Settings::all() );

		BLT_Toolkit_Settings::flush();

		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array(
			'usernames' => array(
				'disallowed' => 'not-a-list',
				'min_length' => array(),
			),
			'roles'     => 'nope',
			'unknown'   => array( 'x' => 1 ),
		);

		$all = BLT_Toolkit_Settings::all();

		$this->assertSame( $this->spec_defaults()['usernames']['disallowed'], $all['usernames']['disallowed'] );
		$this->assertSame( 3, $all['usernames']['min_length'] );
		$this->assertSame( $this->spec_defaults()['roles'], $all['roles'] );
		$this->assertArrayNotHasKey( 'unknown', $all );
	}

	/**
	 * Values stored around this class (`wp option patch` stores strings, or a
	 * hand-edited database) read the way a save would have stored them, so
	 * the strings "false" and "off" are off, never on.
	 *
	 * @return void
	 */
	public function test_stored_values_read_through_the_save_sanitizers() {
		$falsy  = array( 'false', 'off', 'no', '0', '', ' FALSE ', 'garbage', 0, 0.0, false );
		$truthy = array( 'true', 'on', 'yes', '1', ' On ', 1, 2, 1.0, true );

		foreach ( array( $falsy, $truthy ) as $expected => $values ) {
			foreach ( $values as $value ) {
				BLT_Toolkit_Settings::flush();

				$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array(
					'modules'  => array(
						'switch' => $value,
						'extra'  => $value,
					),
					'advanced' => array( 'delete_data_on_uninstall' => $value ),
				);

				$label = var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Assertion message.

				$this->assertSame( (bool) $expected, BLT_Toolkit_Settings::get( 'advanced', 'delete_data_on_uninstall' ), 'delete_data_on_uninstall stored as ' . $label );
				$this->assertSame( (bool) $expected, BLT_Toolkit_Settings::get( 'modules', 'switch' ), 'modules.switch stored as ' . $label );
				$this->assertSame( (bool) $expected, BLT_Toolkit_Settings::get( 'modules', 'extra' ), 'A filter-added module stored as ' . $label );
			}
		}

		BLT_Toolkit_Settings::flush();

		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array(
			'usernames' => array(
				'min_length'      => 900,
				'cooldown_days'   => '-5',
				'nicename_policy' => 'bogus',
				'email_subject'   => ' <b>Hi</b> ',
			),
			'roles'     => array(
				'default_view'       => 'bogus',
				'snapshot_retention' => '0',
			),
			'log'       => array( 'retention_days' => 0 ),
		);

		$all = BLT_Toolkit_Settings::all();

		$this->assertSame( 60, $all['usernames']['min_length'], 'Clamped to the INT_RANGES maximum.' );
		$this->assertSame( 0, $all['usernames']['cooldown_days'], 'Clamped to the INT_RANGES minimum.' );
		$this->assertSame( 'if_derived', $all['usernames']['nicename_policy'], 'Outside the whitelist reads as the default.' );
		$this->assertSame( 'Hi', $all['usernames']['email_subject'] );
		$this->assertSame( 'simple', $all['roles']['default_view'] );
		$this->assertSame( 1, $all['roles']['snapshot_retention'] );
		$this->assertSame( 1, $all['log']['retention_days'], 'Log rows can never be kept for zero days.' );
	}

	/**
	 * A damaged list keeps only clean entries, so a screen that prints it
	 * never meets an array or an object (Settings > Usernames).
	 *
	 * @return void
	 */
	public function test_damaged_lists_read_clean() {
		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array(
			'usernames' => array(
				'disallowed' => array( array( 'a' ), new stdClass(), 'OK', 'ok', 'Root' ),
			),
			'roles'     => array(
				'extra_default_roles' => array( 'editor', array( 'x' ), 'bad role!', 'editor' ),
				'role_meta'           => array(
					'editor' => array(
						'description'  => '<i>Edits</i>',
						'hide_toolbar' => 'off',
					),
					'author' => 'not-an-array',
					'empty'  => array( 'description' => '' ),
				),
				'isolated_post_types' => array(
					'book'  => array(
						'singular' => 'book',
						'plural'   => 'books',
						'since'    => '12',
					),
					'thing' => new stdClass(),
				),
			),
		);

		$all = BLT_Toolkit_Settings::all();

		$this->assertSame( array( 'ok', 'root' ), $all['usernames']['disallowed'] );
		$this->assertSame( array( 'editor', 'badrole' ), $all['roles']['extra_default_roles'] );
		$this->assertSame(
			array(
				'editor' => array(
					'description'  => 'Edits',
					'hide_toolbar' => false,
				),
			),
			$all['roles']['role_meta']
		);
		$this->assertSame(
			array(
				'book' => array(
					'singular' => 'book',
					'plural'   => 'books',
					'since'    => 12,
				),
			),
			$all['roles']['isolated_post_types']
		);
	}

	/**
	 * all() is cached for the request and dropped on every write.
	 *
	 * @return void
	 */
	public function test_cache_is_invalidated_on_write() {
		$this->assertSame( 365, BLT_Toolkit_Settings::get( 'log', 'retention_days' ) );

		// A write behind the class's back is not seen until the cache drops...
		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array( 'log' => array( 'retention_days' => 10 ) );
		$this->assertSame( 365, BLT_Toolkit_Settings::get( 'log', 'retention_days' ) );

		// ...which the option hooks registered on first read take care of.
		BLT_Toolkit_Settings::flush();
		$this->assertSame( 10, BLT_Toolkit_Settings::get( 'log', 'retention_days' ) );

		BLT_Toolkit_Settings::save_tab( 'log', array( 'retention_days' => '20' ) );
		$this->assertSame( 20, BLT_Toolkit_Settings::get( 'log', 'retention_days' ) );
	}

	/**
	 * General: module toggles, missing means off.
	 *
	 * @return void
	 */
	public function test_save_general_module_toggles() {
		$saved = BLT_Toolkit_Settings::save_tab(
			'general',
			array(
				'switch'  => '1',
				'roles'   => 'on',
				'bogus'   => '1',
			)
		);

		$this->assertSame(
			array(
				'switch'    => true,
				'usernames' => false,
				'roles'     => true,
			),
			$saved
		);
		$this->assertSame( $saved, BLT_Toolkit_Settings::get( 'modules' ) );
	}

	/**
	 * Switching: unticked checkboxes are absent and read as off.
	 *
	 * @return void
	 */
	public function test_save_switching_absent_checkboxes_are_false() {
		$saved = BLT_Toolkit_Settings::save_tab( 'switching', array( 'search' => '1' ) );

		$this->assertSame(
			array(
				'footer_link'  => false,
				'switch_off'   => false,
				'search'       => true,
				'compat_hooks' => false,
				'woocommerce'  => false,
			),
			$saved
		);
	}

	/**
	 * Usernames: clamped numbers, whitelists, the disallowed list, email text.
	 *
	 * @return void
	 */
	public function test_save_usernames_sanitizes_every_field() {
		$saved = BLT_Toolkit_Settings::save_tab(
			'usernames',
			array(
				'min_length'      => '99',
				'disallowed'      => "Admin\r\n  root  , Webmaster\n\n<b>bad</b>\nadmin\n" . str_repeat( 'x', 61 ),
				'allow_at_sign'   => '1',
				'nicename_policy' => 'always',
				'notify_user'     => '1',
				'email_subject'   => "  Your <em>new</em>\nusername ",
				'email_body'      => "Hello {display_name},\n\nYour username is now {new_username}.<script>alert(1)</script>",
				'cooldown_days'   => '-5',
			)
		);

		$this->assertSame( 60, $saved['min_length'], 'Clamped to the user_login limit.' );
		$this->assertSame( array( 'admin', 'root', 'webmaster', 'bad' ), $saved['disallowed'] );
		$this->assertTrue( $saved['allow_at_sign'] );
		$this->assertSame( 'always', $saved['nicename_policy'] );
		$this->assertFalse( $saved['redirect_old_slugs'], 'Absent checkbox.' );
		$this->assertFalse( $saved['sign_out_others'], 'Absent checkbox.' );
		$this->assertTrue( $saved['notify_user'] );
		$this->assertSame( 'Your new username', $saved['email_subject'] );
		$this->assertSame( "Hello {display_name},\n\nYour username is now {new_username}.", $saved['email_body'] );
		$this->assertSame( 0, $saved['cooldown_days'] );
		$this->assertFalse( $saved['require_password'] );
	}

	/**
	 * Invalid or missing non-toggle fields keep the stored value.
	 *
	 * @return void
	 */
	public function test_invalid_values_keep_the_stored_value() {
		BLT_Toolkit_Settings::save_tab(
			'usernames',
			array(
				'min_length'      => '5',
				'nicename_policy' => 'never',
				'email_subject'   => 'Kept',
			)
		);

		$saved = BLT_Toolkit_Settings::save_tab(
			'usernames',
			array(
				'min_length'      => 'ten',
				'nicename_policy' => 'sometimes',
			)
		);

		$this->assertSame( 5, $saved['min_length'] );
		$this->assertSame( 'never', $saved['nicename_policy'] );
		$this->assertSame( 'Kept', $saved['email_subject'], 'Not submitted, so not cleared.' );
		$this->assertSame( array( 'admin', 'administrator', 'root', 'sysadmin', 'webmaster' ), $saved['disallowed'], 'Not submitted, so unchanged.' );

		$saved = BLT_Toolkit_Settings::save_tab( 'usernames', array( 'min_length' => '0' ) );

		$this->assertSame( 1, $saved['min_length'], 'Clamped up to 1.' );
	}

	/**
	 * An empty textarea empties the disallowed list (empty is not unset).
	 *
	 * @return void
	 */
	public function test_empty_disallowed_textarea_empties_the_list() {
		$saved = BLT_Toolkit_Settings::save_tab( 'usernames', array( 'disallowed' => '' ) );

		$this->assertSame( array(), $saved['disallowed'] );
	}

	/**
	 * Roles: whitelisted view, clamped retention, and the keys only set()
	 * writes are never touched by the tab.
	 *
	 * @return void
	 */
	public function test_save_roles_never_touches_internal_keys() {
		BLT_Toolkit_Settings::set( 'roles', 'role_meta', array( 'editor' => array( 'hide_toolbar' => true ) ) );
		BLT_Toolkit_Settings::set( 'roles', 'extra_default_roles', array( 'customer' ) );
		BLT_Toolkit_Settings::set(
			'roles',
			'isolated_post_types',
			array(
				'event' => array(
					'singular' => 'Event',
					'plural'   => 'Events',
					'since'    => 1700000000,
				),
			)
		);

		$saved = BLT_Toolkit_Settings::save_tab(
			'roles',
			array(
				'default_view'        => 'advanced',
				'readable_labels'     => '1',
				'snapshot_retention'  => '100000',
				'role_meta'           => array(),
				'extra_default_roles' => array( 'administrator' ),
				'isolated_post_types' => array(),
			)
		);

		$this->assertSame( 'advanced', $saved['default_view'] );
		$this->assertFalse( $saved['show_deprecated'] );
		$this->assertTrue( $saved['readable_labels'] );
		$this->assertFalse( $saved['hide_admins'] );
		$this->assertSame( 500, $saved['snapshot_retention'] );
		$this->assertSame(
			array(
				'editor' => array(
					'description'  => '',
					'hide_toolbar' => true,
				),
			),
			$saved['role_meta']
		);
		$this->assertSame( array( 'customer' ), $saved['extra_default_roles'] );
		$this->assertSame( array( 'event' ), array_keys( $saved['isolated_post_types'] ) );

		$saved = BLT_Toolkit_Settings::save_tab( 'roles', array( 'default_view' => 'matrix' ) );

		$this->assertSame( 'advanced', $saved['default_view'], 'Not in the whitelist, so unchanged.' );
	}

	/**
	 * Saving one tab leaves every other group exactly as it was.
	 *
	 * @return void
	 */
	public function test_save_tab_leaves_other_tabs_untouched() {
		BLT_Toolkit_Settings::save_tab( 'usernames', array( 'min_length' => '8' ) );

		$before = BLT_Toolkit_Settings::all();

		foreach ( array( 'general', 'switching', 'roles', 'log', 'advanced' ) as $tab ) {
			$group = BLT_Toolkit_Settings::TABS[ $tab ];

			BLT_Toolkit_Settings::save_tab( $tab, array() );

			$after = BLT_Toolkit_Settings::all();

			foreach ( array_keys( $before ) as $other ) {
				if ( $other !== $group ) {
					$this->assertSame( $before[ $other ], $after[ $other ], "Saving {$tab} changed {$other}." );
				}
			}

			$before = $after;
		}

		$this->assertSame( 8, BLT_Toolkit_Settings::get( 'usernames', 'min_length' ) );
	}

	/**
	 * Log and Advanced tabs.
	 *
	 * @return void
	 */
	public function test_save_log_and_advanced() {
		$log = BLT_Toolkit_Settings::save_tab( 'log', array( 'retention_days' => '0' ) );

		$this->assertSame(
			array(
				'retention_days' => 1,
				'record_ip'      => false,
			),
			$log
		);

		$advanced = BLT_Toolkit_Settings::save_tab( 'advanced', array( 'delete_data_on_uninstall' => '1' ) );

		$this->assertSame( array( 'delete_data_on_uninstall' => true ), $advanced );
	}

	/**
	 * An unknown tab changes nothing.
	 *
	 * @return void
	 */
	public function test_unknown_tab_changes_nothing() {
		$this->assertSame( array(), BLT_Toolkit_Settings::save_tab( 'secrets', array( 'x' => 1 ) ) );
		$this->assertArrayNotHasKey( 'blt_toolkit_settings', $GLOBALS['blt_test_options'] );
	}

	/**
	 * set() validates per key and refuses unknown keys.
	 *
	 * @return void
	 */
	public function test_set_validates_per_key() {
		$this->assertFalse( BLT_Toolkit_Settings::set( 'roles', 'nope', 1 ) );
		$this->assertFalse( BLT_Toolkit_Settings::set( 'nope', 'x', 1 ) );
		$this->assertFalse( BLT_Toolkit_Settings::set( 'modules', 'bogus', true ) );

		$this->assertTrue(
			BLT_Toolkit_Settings::set(
				'roles',
				'role_meta',
				array(
					'editor'        => array(
						'description'  => ' Edits <b>everything</b> ',
						'hide_toolbar' => '0',
						'extra'        => 'dropped',
					),
					'shop_manager'  => array( 'hide_toolbar' => '1' ),
					'empty'         => array(
						'description'  => '',
						'hide_toolbar' => false,
					),
					'bad role!'     => array( 'description' => 'kept under a cleaned ID' ),
					'not-an-array'  => 'x',
				)
			)
		);

		$this->assertSame(
			array(
				'editor'       => array(
					'description'  => 'Edits everything',
					'hide_toolbar' => false,
				),
				'shop_manager' => array(
					'description'  => '',
					'hide_toolbar' => true,
				),
				'badrole'      => array(
					'description'  => 'kept under a cleaned ID',
					'hide_toolbar' => false,
				),
			),
			BLT_Toolkit_Settings::get( 'roles', 'role_meta' )
		);

		BLT_Toolkit_Settings::set(
			'roles',
			'isolated_post_types',
			array(
				'Event'                           => array(
					'singular' => '<i>Event</i>',
					'plural'   => 'Events',
					'since'    => '1700000000',
				),
				'a_post_type_key_over_twenty_chars' => array( 'singular' => 'Too long' ),
			)
		);

		$this->assertSame(
			array(
				'event' => array(
					'singular' => 'Event',
					'plural'   => 'Events',
					'since'    => 1700000000,
				),
			),
			BLT_Toolkit_Settings::get( 'roles', 'isolated_post_types' )
		);

		BLT_Toolkit_Settings::set( 'roles', 'extra_default_roles', array( 'customer', 'customer', '', 'Shop_Manager', array() ) );

		$this->assertSame( array( 'customer', 'Shop_Manager' ), BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' ) );

		BLT_Toolkit_Settings::set( 'usernames', 'min_length', 0 );

		$this->assertSame( 1, BLT_Toolkit_Settings::get( 'usernames', 'min_length' ) );
	}

	/**
	 * The settings option is written autoloaded.
	 *
	 * @return void
	 */
	public function test_writes_are_autoloaded() {
		BLT_Toolkit_Settings::save_tab( 'advanced', array() );

		$this->assertTrue( $GLOBALS['blt_test_autoload']['blt_toolkit_settings'] );
	}

	/**
	 * The token is stored encrypted, autoload off, and read back.
	 *
	 * @return void
	 */
	public function test_github_token_is_stored_encrypted() {
		$this->assertSame( 'none', BLT_Toolkit_Settings::github_token_status() );
		$this->assertSame( '', BLT_Toolkit_Settings::github_token() );

		$this->assertTrue( BLT_Toolkit_Settings::set_github_token( "  ghp_abc123DEF456\n" ) );

		$stored = $GLOBALS['blt_test_options']['blt_toolkit_secrets'];

		$this->assertFalse( $GLOBALS['blt_test_autoload']['blt_toolkit_secrets'] );
		$this->assertStringStartsWith( '$blt', $stored['github_token'] );
		$this->assertStringNotContainsString( 'ghp_abc123DEF456', serialize( $stored ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertSame( 'ghp_abc123DEF456', BLT_Toolkit_Settings::github_token() );
		$this->assertSame( 'stored', BLT_Toolkit_Settings::github_token_status() );
	}

	/**
	 * A blank submission keeps the stored token; delete removes it.
	 *
	 * @return void
	 */
	public function test_blank_token_preserves_and_delete_clears() {
		BLT_Toolkit_Settings::set_github_token( 'ghp_first' );

		$this->assertTrue( BLT_Toolkit_Settings::set_github_token( '' ) );
		$this->assertTrue( BLT_Toolkit_Settings::set_github_token( '   ' ) );
		$this->assertSame( 'ghp_first', BLT_Toolkit_Settings::github_token() );

		BLT_Toolkit_Settings::set_github_token( 'ghp_second' );
		$this->assertSame( 'ghp_second', BLT_Toolkit_Settings::github_token() );

		BLT_Toolkit_Settings::delete_github_token();

		$this->assertSame( '', BLT_Toolkit_Settings::github_token() );
		$this->assertSame( 'none', BLT_Toolkit_Settings::github_token_status() );
		$this->assertArrayNotHasKey( 'blt_toolkit_secrets', $GLOBALS['blt_test_options'] );
	}

	/**
	 * Something that is not a token is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function test_malformed_token_is_refused() {
		$result = BLT_Toolkit_Settings::set_github_token( 'ghp_abc<script>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blt_toolkit_bad_token', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'blt_toolkit_secrets', $GLOBALS['blt_test_options'] );
	}

	/**
	 * Rotated salts: the token reads as '' and the status says so, without
	 * deleting the stored envelope (a read never writes).
	 *
	 * @return void
	 */
	public function test_rotated_salts_are_detectable() {
		BLT_Toolkit_Settings::set_github_token( 'ghp_secret' );

		$GLOBALS['blt_test_salt'] = 'rotated';
		BLT_Toolkit_Settings::flush();

		$this->assertSame( '', BLT_Toolkit_Settings::github_token() );
		$this->assertSame( 'undecryptable', BLT_Toolkit_Settings::github_token_status() );
		$this->assertArrayHasKey( 'blt_toolkit_secrets', $GLOBALS['blt_test_options'] );

		// Entering it again fixes it.
		BLT_Toolkit_Settings::set_github_token( 'ghp_secret' );

		$this->assertSame( 'ghp_secret', BLT_Toolkit_Settings::github_token() );
		$this->assertSame( 'stored', BLT_Toolkit_Settings::github_token_status() );
	}
}
