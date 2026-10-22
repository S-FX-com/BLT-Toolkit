<?php
/**
 * Module registry, boot and the activation lifecycle.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin on `plugins_loaded` priority 1 (SPEC 4.2): the shared
 * services that always load (the Guard and the Log), the schema check on
 * `init`, then each module that is switched on, not disabled by
 * `BLT_TOOLKIT_DISABLE_MODULES` and not in conflict with its legacy plugin.
 *
 * Also owns activation (schema, default settings, baseline snapshot, then
 * the administrator's capabilities) and a deactivation that changes no data.
 */
class BLT_Toolkit_Plugin {

	/**
	 * Prefix every cron hook of this plugin starts with.
	 */
	const CRON_PREFIX = 'blt_toolkit_';

	/**
	 * Whether boot() already ran this request.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Modules booted this request, keyed by module key.
	 *
	 * @var array<string,BLT_Toolkit_Module>
	 */
	private static $modules = array();

	/**
	 * Display names read through display_name(), keyed by "viewer ID|user
	 * ID|context": `raw` (the stored value) and `name`.
	 *
	 * @var array<string,array{raw:string,name:string}>
	 */
	private static $names = array();

	/**
	 * The display name being read while the `user_display_name` filters run
	 * on users.php: user ID, context and stored value (note_display_name_read()).
	 *
	 * @var array{0:int,1:string,2:string}|null
	 */
	private static $reading = null;

	/**
	 * Boot. Called on `plugins_loaded` priority 1.
	 *
	 * On multisite nothing boots (SPEC D1): activation is refused there, so
	 * this only happens when the plugin was activated by other means, and an
	 * admin notice explains why it is idle.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'multisite_notice' ) );

			return;
		}

		// Shared services that hold with every module off (SPEC 4.1). Each only
		// adds hooks here, so a front-end page view pays nothing for them.
		//
		// - The Guard: map_meta_cap, editable_roles and the user queries
		//   (SEC-1 to SEC-6), plus the recovery check (SEC-14), hooked on
		//   `init` priority 20 only while BLT_TOOLKIT_RECOVERY is set.
		// - The log: its daily prune event (LOG-4), scheduled on `init` 20
		//   on backend requests, and the `log_prune` job type, registered on
		//   `init` 5 (JOB-5).
		// - The job runner (SPEC 6.3) and the REST routes under
		//   BLT_TOOLKIT_REST_NS, registered on `rest_api_init`.
		BLT_Toolkit_Guard::register();
		BLT_Toolkit_Log::register();
		BLT_Toolkit_Jobs::register();
		BLT_Toolkit_Rest::register();

		// - Separate permissions (RS-30 to RS-34): isolated types are
		//   re-registered by the filter the main file adds, whatever the
		//   Roles module's state, so their verification at `init`
		//   PHP_INT_MAX (RS-33), the notice and the Plugins-row warning
		//   (RS-34) load with it.
		// - Read-only enforcement (RS-14): one map_meta_cap closure that
		//   returns at once outside edit_post, delete_post and publish_post.
		//   A View-level marker granted while the module ran keeps its
		//   meaning with the module off.
		BLT_Toolkit_Isolation::register();
		BLT_Toolkit_Readonly::register();

		// SPEC 4.2, step 3, on `init` in this order: the idempotent schema
		// upgrade (priority 0; also covers a plugin update by file copy,
		// which never fires the activation hook), the switch request handling
		// a module adds at the default priority (SW-2), then the recovery
		// check the Guard added above (priority 20), which needs the tables.
		add_action( 'init', array( 'BLT_Toolkit_Schema', 'maybe_upgrade' ), 0 );

		foreach ( self::modules() as $key => $class_name ) {
			if ( 'on' !== self::module_status( $key ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( ! $module instanceof BLT_Toolkit_Module ) {
				continue;
			}

			$module->boot();

			self::$modules[ $key ] = $module;
		}

		// SW-10 holds with the Switch module off too (setting, constant or
		// GEN-1 conflict): protection never depends on a module (SPEC 4.1).
		// Switch cookies left in a browser from before the module went off are
		// ended by the next sign-in or sign-out, so nobody who later signs in
		// on that browser is offered the original user's account once the
		// module is back. Only these hooks are added; the cookie class loads
		// only when such a cookie is present.
		if ( ! isset( self::$modules['switch'] ) ) {
			add_action( 'wp_login', array( __CLASS__, 'end_switch' ), 10, 0 );
			add_action( 'wp_logout', array( __CLASS__, 'end_switch' ), 10, 0 );
			add_action( 'blt_m365_sso_user_authenticated', array( __CLASS__, 'end_switch' ), 10, 0 );
		}

		// Menu, screens and the PRG router (admin/class-blt-toolkit-admin.php).
		if ( is_admin() && class_exists( 'BLT_Toolkit_Admin' ) ) {
			BLT_Toolkit_Admin::instance()->init();
		}

		// The Users list row actions name each user (display_name()), and
		// admin screens read a few options that are not autoloaded: one
		// query for all of them, ahead of the update token on `init` 1.
		if ( is_admin() ) {
			add_action( 'load-users.php', array( __CLASS__, 'load_users_screen' ) );
			add_action( 'init', array( 'BLT_Toolkit_Settings', 'prime_admin_options' ), 0 );
		}
	}

	/**
	 * `load-users.php` callback: note each display name core reads for the
	 * list's rows (core names the user in its own "View posts by" link before
	 * the row actions run), so display_name() reuses it instead of running
	 * the display filters again for the same answer.
	 *
	 * @return void
	 */
	public static function load_users_screen() {
		add_filter( 'user_display_name', array( __CLASS__, 'note_display_name_read' ), PHP_INT_MIN, 3 );
		add_filter( 'user_display_name', array( __CLASS__, 'note_display_name' ), PHP_INT_MAX, 3 );
	}

	/**
	 * `user_display_name`, first in line on users.php: the stored value a
	 * read starts from. Returns it unchanged.
	 *
	 * @param mixed  $value   The stored display name.
	 * @param int    $user_id User ID.
	 * @param string $context Context (`display` on the list).
	 * @return mixed
	 */
	public static function note_display_name_read( $value, $user_id = 0, $context = '' ) {
		self::$reading = is_scalar( $value ) ? array( (int) $user_id, (string) $context, (string) $value ) : null;

		return $value;
	}

	/**
	 * `user_display_name`, last in line on users.php: remember the result of
	 * the read note_display_name_read() saw start, for display_name(). Any
	 * other filter that read a display name in between leaves nothing
	 * remembered. Returns the value unchanged.
	 *
	 * @param mixed  $value   The filtered display name.
	 * @param int    $user_id User ID.
	 * @param string $context Context.
	 * @return mixed
	 */
	public static function note_display_name( $value, $user_id = 0, $context = '' ) {
		$reading       = self::$reading;
		self::$reading = null;

		if ( null !== $reading && is_scalar( $value ) && (int) $user_id === $reading[0] && (string) $context === $reading[1] ) {
			self::remember_name( get_current_user_id() . '|' . (int) $user_id . '|' . (string) $context, $reading[2], (string) $value );
		}

		return $value;
	}

	/**
	 * End switching in this browser while the Switch module is off (SW-10):
	 * destroy the sessions the switch cookies could return to and clear
	 * them, as the module does when it runs. Hooked on `wp_login`,
	 * `wp_logout` and BLT M365 WP SSO's sign-in only when the module did not
	 * boot. Costs one scan of the cookie names when no switch cookie is
	 * present.
	 *
	 * @return void
	 */
	public static function end_switch() {
		$names = array();

		foreach ( array( 'BLT_TOOLKIT_SWITCH_COOKIE', 'BLT_TOOLKIT_SWITCH_SECURE_COOKIE', 'BLT_TOOLKIT_SWITCH_OLDUSER_COOKIE' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
				$names[] = constant( $constant );
			}
		}

		$found = false;

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, 'wordpress_blt_sw_' ) || in_array( (string) $name, $names, true ) ) {
				$found = true;
				break;
			}
		}

		if ( $found && class_exists( 'BLT_Toolkit_Switch_Cookies' ) ) {
			BLT_Toolkit_Switch_Cookies::end_sessions();
		}
	}

	/**
	 * Registered modules, key => class name, filterable through
	 * `blt_toolkit_modules`.
	 *
	 * @return array<string,string>
	 */
	public static function modules() {
		/**
		 * Filter the module registry.
		 *
		 * @param array<string,string> $modules Module key => class name. Each class must implement BLT_Toolkit_Module.
		 */
		$modules = apply_filters(
			'blt_toolkit_modules',
			array(
				'switch'    => 'BLT_Toolkit_Switch',
				'usernames' => 'BLT_Toolkit_Usernames',
				'roles'     => 'BLT_Toolkit_Roles',
			)
		);

		$out = array();

		if ( ! is_array( $modules ) ) {
			return $out;
		}

		foreach ( $modules as $key => $class_name ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key && is_string( $class_name ) && '' !== $class_name ) {
				$out[ $key ] = $class_name;
			}
		}

		return $out;
	}

	/**
	 * Name and one-line description of each built-in module, translated.
	 *
	 * They live with the registry so a screen can name a module that did not
	 * boot without loading its class: a module that is off, disabled by
	 * `BLT_TOOLKIT_DISABLE_MODULES` or in conflict with its legacy plugin
	 * loads no code beyond its registry entry (SPEC 4.1, SEC-15, GEN-1). The
	 * module classes return these same strings. Call it from `init` onward.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function labels() {
		return array(
			'switch'    => array(
				'label'       => __( 'Switch', 'blt-toolkit' ),
				'description' => __( 'Switch to another user\'s account and back.', 'blt-toolkit' ),
			),
			'usernames' => array(
				'label'       => __( 'Usernames', 'blt-toolkit' ),
				'description' => __( 'Change a user\'s username, with old author links redirected.', 'blt-toolkit' ),
			),
			'roles'     => array(
				'label'       => __( 'Roles', 'blt-toolkit' ),
				'description' => __( 'Create and edit roles and capabilities in a Simple or Advanced view.', 'blt-toolkit' ),
			),
		);
	}

	/**
	 * A module's state.
	 *
	 * - `conflict`: the legacy plugin that does the same job is active
	 *   (GEN-1), so this module must not boot. It is reported ahead of the
	 *   constant, because GEN-1 requires the screen to name the plugin to
	 *   deactivate; the conflict note also mentions the constant when both
	 *   apply.
	 * - `disabled`: named in `BLT_TOOLKIT_DISABLE_MODULES` (SEC-15), which
	 *   wins over settings.
	 * - `on` / `off`: the owner's choice in Settings > General.
	 *
	 * Both `conflict` and `disabled` stop the module from booting, whatever
	 * its setting says.
	 *
	 * @param string $key Module key.
	 * @return string `on`, `off`, `conflict` or `disabled`.
	 */
	public static function module_status( $key ) {
		$key = (string) $key;

		if ( ! array_key_exists( $key, self::modules() ) ) {
			return 'off';
		}

		if ( '' !== BLT_Toolkit_Legacy::conflict( $key ) ) {
			return 'conflict';
		}

		if ( in_array( $key, self::disabled_by_constant(), true ) ) {
			return 'disabled';
		}

		$modules = BLT_Toolkit_Settings::get( 'modules' );

		return is_array( $modules ) && ! empty( $modules[ $key ] ) ? 'on' : 'off';
	}

	/**
	 * Whether a module booted on this request.
	 *
	 * @param string $key Module key.
	 * @return bool
	 */
	public static function is_active( $key ) {
		return isset( self::$modules[ (string) $key ] );
	}

	/**
	 * The booted instance of a module, or null when it did not boot.
	 *
	 * @param string $key Module key.
	 * @return BLT_Toolkit_Module|null
	 */
	public static function module( $key ) {
		$key = (string) $key;

		return isset( self::$modules[ $key ] ) ? self::$modules[ $key ] : null;
	}

	/**
	 * A user's display name exactly as `$user->display_name` gives it, worked
	 * out once per request for each user (SEC-9).
	 *
	 * In wp-admin, core runs `sanitize_text_field()`, `wp_kses_data()` and
	 * `_wp_specialchars()` over the name on every read from a user object in
	 * the `display` context, which is the context of the Users list rows
	 * (8 to 12 µs a read). The Switch To and Change username row actions both
	 * name the user in their accessible labels, so each row paid for it
	 * twice, and core reads it once more for its own "View posts by" link
	 * (load_users_screen()). The answer is reused only for the same viewer,
	 * user, context and stored name, which is everything those filters are
	 * given.
	 *
	 * @param WP_User $user The user.
	 * @return string
	 */
	public static function display_name( WP_User $user ) {
		$raw = isset( $user->data->display_name ) && is_scalar( $user->data->display_name ) ? (string) $user->data->display_name : '';
		$key = get_current_user_id() . '|' . (int) $user->ID . '|' . (string) $user->filter;

		if ( isset( self::$names[ $key ] ) && self::$names[ $key ]['raw'] === $raw ) {
			return self::$names[ $key ]['name'];
		}

		$name = (string) $user->display_name;

		self::remember_name( $key, $raw, $name );

		return $name;
	}

	/**
	 * Store a display name for display_name().
	 *
	 * @param string $key  "viewer ID|user ID|context".
	 * @param string $raw  The stored value it was worked out from.
	 * @param string $name The display name.
	 * @return void
	 */
	private static function remember_name( $key, $raw, $name ) {
		// A list page has at most 999 rows; keep the memory bounded anyway.
		if ( count( self::$names ) >= 1000 ) {
			self::$names = array();
		}

		self::$names[ $key ] = array(
			'raw'  => $raw,
			'name' => $name,
		);
	}

	/**
	 * Module keys named in `BLT_TOOLKIT_DISABLE_MODULES` (SEC-15), e.g.
	 * `define( 'BLT_TOOLKIT_DISABLE_MODULES', 'switch,usernames' );`.
	 *
	 * @return string[]
	 */
	public static function disabled_by_constant() {
		if ( ! defined( 'BLT_TOOLKIT_DISABLE_MODULES' ) ) {
			return array();
		}

		$value = constant( 'BLT_TOOLKIT_DISABLE_MODULES' );

		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$keys = array();

		foreach ( $value as $key ) {
			$key = is_scalar( $key ) ? sanitize_key( trim( (string) $key ) ) : '';

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Activation hook.
	 *
	 * Refused on multisite (SPEC D1). Otherwise: tables, default settings,
	 * the baseline snapshot (SNAP-2), and only then the administrator's
	 * capabilities, so the baseline records the roles exactly as they were
	 * before BLT Toolkit changed anything. Every step is idempotent, so
	 * reactivating repairs anything missing without creating a second
	 * baseline.
	 *
	 * If the tables cannot be created or the baseline cannot be stored, the
	 * activation is refused before any role is touched: granting first would
	 * let a later baseline record BLT Toolkit's own grants as the state
	 * before it, and a restore to that baseline would keep them.
	 *
	 * @param bool $network_wide Whether the activation is network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		// Refused on any multisite install, network-wide or for one site.
		if ( is_multisite() || $network_wide ) {
			self::refuse_activation( __( 'BLT Toolkit supports single-site installs only in this version, so it cannot be activated on a multisite network, either for the whole network or for one site.', 'blt-toolkit' ) );
		}

		if ( ! BLT_Toolkit_Schema::install() ) {
			self::refuse_activation( __( 'BLT Toolkit could not create its database tables, so it was not activated and your roles were not changed. Check that the database user may create tables, then activate it again.', 'blt-toolkit' ) );
		}

		BLT_Toolkit_Settings::seed();

		if ( 0 === BLT_Toolkit_Snapshots::baseline() || is_wp_error( BLT_Toolkit_Caps::grant_admin() ) ) {
			self::refuse_activation( __( 'BLT Toolkit could not save a copy of your roles before changing them, so it was not activated and your roles were not changed. Activate it again; if this keeps happening, the PHP error log says why.', 'blt-toolkit' ) );
		}
	}

	/**
	 * Stop an activation with a readable message.
	 *
	 * activate_plugin() runs the activation hook before it records the plugin
	 * as active, so stopping here leaves it inactive. Loading the main file
	 * for the activation already scheduled the update checker's daily event,
	 * and no deactivation hook will ever clear it, so it is cleared here.
	 *
	 * @param string $message Translated, unescaped message.
	 * @return void
	 */
	private static function refuse_activation( $message ) {
		wp_clear_scheduled_hook( 'puc_cron_check_updates-blt-toolkit' );

		wp_die(
			esc_html( $message ),
			esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Deactivation hook.
	 *
	 * Changes no data (SPEC 14.6): settings, tables, snapshots, role edits and
	 * capabilities all stay. It only clears this plugin's scheduled events,
	 * WP-Cron and Action Scheduler alike (JOB-1), so nothing runs while the
	 * code is inactive. A job that was still live is queued again by the
	 * daily prune after reactivation (BLT_Toolkit_Jobs::resume_stalled()).
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_cron();
		BLT_Toolkit_Jobs::unschedule_all();
	}

	/**
	 * Unschedule every cron event whose hook starts with `blt_toolkit_`.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) ) {
			return;
		}

		$hooks = array();

		foreach ( $crons as $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}

			foreach ( array_keys( $events ) as $hook ) {
				if ( 0 === strpos( (string) $hook, self::CRON_PREFIX ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		foreach ( array_keys( $hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Explain why the plugin is idle on a multisite network.
	 *
	 * @return void
	 */
	public static function multisite_notice() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'BLT Toolkit supports single-site installs only in this version, so it is not running on this network. Deactivate it from the Plugins screen.', 'blt-toolkit' ) . '</p></div>';
	}
}
