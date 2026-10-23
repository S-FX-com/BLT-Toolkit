<?php
/**
 * User Switching compatibility shim (SW-19).
 *
 * Required by BLT_Toolkit_Switch::boot() only while the setting
 * `switch.compat_hooks` (Settings > Switching, "Work with tools made for
 * User Switching") is on. It holds functions only: WordPress-Extra
 * (`Universal.Files.SeparateFunctionsFromOO`) keeps functions out of class
 * files.
 *
 * `current_user_switched()` is the plugin's only global symbol without the
 * `blt_toolkit_` prefix, and it is declared only when no function of that
 * name exists. BLT Toolkit never declares `switch_to_user()`,
 * `switch_off_user()` or any `user_switching_*()` function, which belong to
 * User Switching (GEN-2, SPEC 19 US-B).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'current_user_switched' ) ) {
	/**
	 * The user who switched into the current session (SW-19), with User
	 * Switching's signature, so code written for User Switching works as is.
	 *
	 * @return WP_User|false The originating user (the most recent one when
	 *                       switched more than once), or false when nobody
	 *                       is signed in or the current user did not switch
	 *                       in.
	 */
	function current_user_switched() {
		if ( ! is_user_logged_in() || ! class_exists( 'BLT_Toolkit_Switch' ) ) {
			return false;
		}

		return BLT_Toolkit_Switch::old_user();
	}
}
