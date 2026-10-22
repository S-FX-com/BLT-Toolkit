<?php
/**
 * Module contract.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A BLT Toolkit feature module (SPEC 4.1).
 *
 * The registry (BLT_Toolkit_Plugin) constructs a module only when it is
 * switched on, not disabled by `BLT_TOOLKIT_DISABLE_MODULES` and not in
 * conflict with its legacy plugin, then calls boot() once on `plugins_loaded`
 * priority 1. Construction must stay trivial: every hook belongs in boot().
 *
 * label() and description() return translated strings, so call them only
 * from `init` onward (admin screens, notices), never during boot.
 */
interface BLT_Toolkit_Module {

	/**
	 * Unique module key, as used in settings `modules` and the registry.
	 *
	 * @return string
	 */
	public function key();

	/**
	 * Human-readable module name.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * One-line description a site owner understands.
	 *
	 * @return string
	 */
	public function description();

	/**
	 * Register the module's hooks. Called once per request when enabled.
	 *
	 * @return void
	 */
	public function boot();
}
