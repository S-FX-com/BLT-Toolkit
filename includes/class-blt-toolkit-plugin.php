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

		// Protection and the record of it hold with every module off.
		BLT_Toolkit_Guard::register();
		BLT_Toolkit_Log::register();

		// Idempotent schema upgrade for a plugin update by file copy, which
		// never fires the activation hook. The version option is autoloaded.
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

		// Menu, screens and the PRG router (admin/class-blt-toolkit-admin.php).
		if ( is_admin() && class_exists( 'BLT_Toolkit_Admin' ) ) {
			BLT_Toolkit_Admin::instance()->init();
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

		if ( 0 === BLT_Toolkit_Snapshots::baseline() ) {
			self::refuse_activation( __( 'BLT Toolkit could not save a copy of your roles before changing them, so it was not activated and your roles were not changed. Activate it again; if this keeps happening, the PHP error log says why.', 'blt-toolkit' ) );
		}

		BLT_Toolkit_Caps::grant_admin();
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
	 * capabilities all stay. It only clears this plugin's scheduled events, so
	 * nothing runs while the code is inactive.
	 *
	 * @return void
	 */
	public static function deactivate() {
		self::clear_cron();
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
