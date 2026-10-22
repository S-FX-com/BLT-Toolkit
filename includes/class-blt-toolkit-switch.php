<?php
/**
 * Switch module.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switch to another user's account and back (SPEC 7).
 *
 * The registry boots this module only when it is on, not disabled by
 * `BLT_TOOLKIT_DISABLE_MODULES` and User Switching is not active (GEN-1).
 */
class BLT_Toolkit_Switch implements BLT_Toolkit_Module {

	/**
	 * Module key.
	 */
	const KEY = 'switch';

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
	 * Register hooks. Phase 2 (SW-1 to SW-28) fills this in.
	 *
	 * @return void
	 */
	public function boot() {
	}
}
