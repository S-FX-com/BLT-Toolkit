<?php
/**
 * The activity log.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One log for every module (SPEC 6.1). It always loads, even with every
 * module off, so the Guard's decisions and recovery mode are recorded.
 */
class BLT_Toolkit_Log {

	/**
	 * Register the log's hooks. Called on `plugins_loaded` priority 1.
	 *
	 * Phase 1 fills this in (LOG-1 to LOG-6: `record()`, the daily prune job
	 * and the `blt_toolkit_logged` action).
	 *
	 * @return void
	 */
	public static function register() {
	}
}
