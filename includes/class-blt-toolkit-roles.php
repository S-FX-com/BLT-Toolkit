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
 */
class BLT_Toolkit_Roles implements BLT_Toolkit_Module {

	/**
	 * Module key.
	 */
	const KEY = 'roles';

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
	 * Register hooks. Phases 4 and 5 (RO, US and RS requirements) fill this in.
	 *
	 * @return void
	 */
	public function boot() {
	}
}
