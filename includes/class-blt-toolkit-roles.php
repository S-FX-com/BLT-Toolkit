<?php
/**
 * Roles module.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create and edit roles and capabilities in a Simple or Advanced view
 * (SPEC 9 and 10).
 *
 * The registry boots this module only when it is on, not disabled by
 * `BLT_TOOLKIT_DISABLE_MODULES` and User Role Editor is not active (GEN-1).
 *
 * boot() only adds hooks (SEC-9); nothing here reads a role or builds the
 * capability catalog on page load:
 *
 * - Role metadata (SPEC 9.5): `show_admin_bar` hides the toolbar on the site
 *   for a user only when every one of their roles has "Hide the toolbar on
 *   the site". A visitor who is not signed in costs one boolean check.
 * - BLT_Toolkit_Role_Lifecycle: the `role_reassign` job type (RO-41,
 *   JOB-5), registered on `init` priority 5.
 * - BLT_Toolkit_User_Roles: additional roles, additional default roles, bulk
 *   add and remove, users without a role (US-1 to US-3, US-12).
 * - BLT_Toolkit_User_Caps: per-user capabilities (US-10, US-11).
 * - BLT_Toolkit_Admin_Roles: the Roles screen's own hooks, on admin
 *   requests only (the screen itself is routed by BLT_Toolkit_Admin).
 *
 * The role store (BLT_Toolkit_Role_Store) and the capability catalog
 * (BLT_Toolkit_Capability_Catalog) register no hooks: they are called by the
 * screens and write paths, and the store also works with this module off,
 * because activation grants and the Settings screens write roles through it.
 */
class BLT_Toolkit_Roles implements BLT_Toolkit_Module {

	/**
	 * Module key.
	 */
	const KEY = 'roles';

	/**
	 * Priority of the `show_admin_bar` filter: late, so a theme or plugin
	 * that turns the toolbar on at the usual priority does not undo a hide
	 * the owner set for every role a user holds.
	 */
	const TOOLBAR_PRIORITY = 1000;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Module key.
	 *
	 * @return string
	 */
	public function key() {
		return self::KEY;
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function label() {
		return BLT_Toolkit_Plugin::labels()[ self::KEY ]['label'];
	}

	/**
	 * One-line description.
	 *
	 * @return string
	 */
	public function description() {
		return BLT_Toolkit_Plugin::labels()[ self::KEY ]['description'];
	}

	/**
	 * Register the hooks. Called once on `plugins_loaded` priority 1.
	 *
	 * @return void
	 */
	public function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// SPEC 9.5, X-5. The Switch module's footer link (SW-17) is printed
		// whenever the toolbar is not showing, so a hidden toolbar never
		// removes the way back.
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ), self::TOOLBAR_PRIORITY );

		// RO-41: the `role_reassign` job type, before `init` priority 10 (its
		// label is translated; every request that runs a tick comes later).
		if ( class_exists( 'BLT_Toolkit_Role_Lifecycle' ) ) {
			if ( is_callable( array( 'BLT_Toolkit_Role_Lifecycle', 'boot' ) ) ) {
				BLT_Toolkit_Role_Lifecycle::boot();
			} elseif ( is_callable( array( 'BLT_Toolkit_Role_Lifecycle', 'register_job_type' ) ) ) {
				add_action( 'init', array( 'BLT_Toolkit_Role_Lifecycle', 'register_job_type' ), 5 );
			}
		}

		// US-1 to US-3, US-12.
		if ( class_exists( 'BLT_Toolkit_User_Roles' ) && is_callable( array( 'BLT_Toolkit_User_Roles', 'boot' ) ) ) {
			BLT_Toolkit_User_Roles::boot();
		}

		// US-10, US-11.
		if ( class_exists( 'BLT_Toolkit_User_Caps' ) && is_callable( array( 'BLT_Toolkit_User_Caps', 'boot' ) ) ) {
			BLT_Toolkit_User_Caps::boot();
		}

		// The Roles screen's own hooks (assets, notices), on admin requests.
		if ( is_admin() && class_exists( 'BLT_Toolkit_Admin_Roles' ) && is_callable( array( 'BLT_Toolkit_Admin_Roles', 'boot' ) ) ) {
			BLT_Toolkit_Admin_Roles::boot();
		}
	}

	// ---------- Role metadata (SPEC 9.5) ----------

	/**
	 * `show_admin_bar` callback: hide the toolbar on the site when every role
	 * the signed-in user holds has "Hide the toolbar on the site".
	 *
	 * Capabilities are additive and the most permissive role wins, so one
	 * role without the flag keeps the toolbar. It only ever hides; it never
	 * shows a toolbar that is off. In wp-admin core shows the toolbar without
	 * asking this filter. Returns at once, with no query, when the toolbar is
	 * already off (every visitor who is not signed in) or no role has the
	 * flag.
	 *
	 * @param bool $show Whether the toolbar shows.
	 * @return bool
	 */
	public static function filter_admin_bar( $show ) {
		if ( ! $show ) {
			return $show;
		}

		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );

		if ( ! is_array( $meta ) || array() === $meta || ! is_user_logged_in() ) {
			return $show;
		}

		return self::hides_toolbar( (array) wp_get_current_user()->roles, $meta ) ? false : $show;
	}

	/**
	 * Whether role metadata hides the toolbar for someone holding these
	 * roles: they hold at least one, and every one has `hide_toolbar`. Pure.
	 *
	 * @param string[] $roles     The user's role IDs.
	 * @param array    $role_meta `roles.role_meta`: role ID => array( 'description', 'hide_toolbar' ).
	 * @return bool
	 */
	public static function hides_toolbar( array $roles, array $role_meta ) {
		if ( array() === $roles ) {
			return false;
		}

		foreach ( $roles as $role ) {
			$role = (string) $role;

			if ( empty( $role_meta[ $role ] ) || ! is_array( $role_meta[ $role ] ) || empty( $role_meta[ $role ]['hide_toolbar'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A role's description from role metadata (SPEC 9.5), or ''.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	public static function description_of( $role ) {
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$role = (string) $role;

		return is_array( $meta ) && isset( $meta[ $role ]['description'] ) ? (string) $meta[ $role ]['description'] : '';
	}
}
