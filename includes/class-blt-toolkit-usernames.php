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
	 * Register hooks. Phase 3 (UN-1 to UN-30) fills this in.
	 *
	 * @return void
	 */
	public function boot() {
	}
}
