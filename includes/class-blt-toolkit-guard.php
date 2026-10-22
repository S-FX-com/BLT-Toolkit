<?php
/**
 * The Guard: every authorization rule in one place.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces SPEC 5.3 through `map_meta_cap`, `editable_roles` and checks
 * inside BLT write paths. It always loads, even with every module off,
 * because protecting administrators must not depend on which tools are on.
 */
class BLT_Toolkit_Guard {

	/**
	 * Register the Guard's hooks. Called on `plugins_loaded` priority 1.
	 *
	 * Phase 1 fills this in (SEC-1 to SEC-15). Until then the plugin grants
	 * capabilities but exposes no action that uses them.
	 *
	 * @return void
	 */
	public static function register() {
	}

	/**
	 * Whether a user counts as an administrator for BLT Toolkit (SPEC 5.2).
	 *
	 * True when the user has the `administrator` role on this site. It checks
	 * role membership, not capabilities, so it still answers correctly after
	 * the administrator role's capabilities were damaged (SEC-14 relies on
	 * this). Filterable through `blt_toolkit_is_toolkit_admin`.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_toolkit_admin( WP_User $user ) {
		$is_admin = $user->exists() && in_array( 'administrator', (array) $user->roles, true );

		/**
		 * Filter whether a user counts as a toolkit administrator.
		 *
		 * @param bool    $is_admin Whether the user has the administrator role on this site.
		 * @param WP_User $user     The user.
		 */
		return (bool) apply_filters( 'blt_toolkit_is_toolkit_admin', $is_admin, $user );
	}
}
