<?php
/**
 * Usernames module.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change a user's username (SPEC 8).
 *
 * The registry boots this module only when it is on, not disabled by
 * `BLT_TOOLKIT_DISABLE_MODULES` and Username Changer is not active (GEN-1).
 *
 * boot() only adds hooks (SEC-9):
 *
 * - BLT_Toolkit_Username_Profile: the profile section, its save and the
 *   Users list row action (UN-16 to UN-19).
 * - BLT_Toolkit_Author_Redirects: 301s from old author slugs (UN-13a),
 *   hooked only when `usernames.redirect_old_slugs` is on.
 * - BLT_Toolkit_Username_Shortcode: `[blt_toolkit_username_form]` and its
 *   admin-post.php handlers, the front-end self-service form (UN-23).
 * - The `username_bulk` job type (UN-30, JOB-5), registered on `init`
 *   priority 5 by BLT_Toolkit_Username_Bulk. The bulk screen's admin-post.php
 *   actions go through BLT_Toolkit_Admin's router.
 * - BLT_Toolkit_Rest registers `GET blt-toolkit/v1/usernames/check` while
 *   this module runs (UN-16, advisory).
 *
 * Every rename goes through BLT_Toolkit_Username_Changer::change() (UN-10);
 * self-service (UN-20 to UN-22) is the `blt_change_own_username` capability,
 * set per role on Settings > Usernames, plus the Guard's cooldown.
 */
class BLT_Toolkit_Usernames implements BLT_Toolkit_Module {

	/**
	 * Module key.
	 */
	const KEY = 'usernames';

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
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

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

		// UN-16 to UN-19.
		BLT_Toolkit_Username_Profile::boot();

		// UN-13a.
		BLT_Toolkit_Author_Redirects::boot();

		// UN-23: the shortcode and its admin-post.php handlers.
		if ( class_exists( 'BLT_Toolkit_Username_Shortcode' ) && is_callable( array( 'BLT_Toolkit_Username_Shortcode', 'boot' ) ) ) {
			BLT_Toolkit_Username_Shortcode::boot();
		}

		// UN-30: the `username_bulk` job type, on `init` before priority 10
		// (its label is translated; every request that runs a tick comes
		// after `init`).
		if ( class_exists( 'BLT_Toolkit_Username_Bulk' ) && is_callable( array( 'BLT_Toolkit_Username_Bulk', 'register_job_type' ) ) ) {
			add_action( 'init', array( 'BLT_Toolkit_Username_Bulk', 'register_job_type' ), 5 );
		}
	}
}
