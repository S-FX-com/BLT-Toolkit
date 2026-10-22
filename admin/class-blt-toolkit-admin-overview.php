<?php
/**
 * Overview screen: module cards and the plugin's state at a glance.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Overview screen (SPEC 13.2): one card per module with its state
 * (On, Off, Conflict per GEN-1, or disabled by BLT_TOOLKIT_DISABLE_MODULES per
 * SEC-15) and its toggle, plus a recovery-mode callout while
 * BLT_TOOLKIT_RECOVERY is set (SEC-14).
 *
 * Rendering reads settings and the role list only. The module toggles post
 * to admin-post.php and save through BLT_Toolkit_Settings::save_tab(), the
 * same path as Settings > General.
 */
class BLT_Toolkit_Admin_Overview {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$modules  = BLT_Toolkit_Admin::modules();
		$running  = count(
			array_filter(
				$modules,
				function ( $module ) {
					return 'on' === $module['status'];
				}
			)
		);
		$roles    = wp_roles()->get_names();
		$recovery = defined( 'BLT_TOOLKIT_RECOVERY' ) && constant( 'BLT_TOOLKIT_RECOVERY' );

		require BLT_TOOLKIT_DIR . 'admin/views/overview.php';
	}

	/**
	 * Save the module toggles from the Overview cards.
	 *
	 * Called by BLT_Toolkit_Admin::route_post() after the method, capability
	 * (`manage_options`) and nonce checks. A module whose toggle was not
	 * submitted is switched off, as an unticked checkbox sends nothing.
	 *
	 * @return void
	 */
	public function handle_save_modules() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		$raw = isset( $_POST['blt_toolkit'] ) && is_array( $_POST['blt_toolkit'] ) ? wp_unslash( $_POST['blt_toolkit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in BLT_Toolkit_Settings::save_tab().
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		BLT_Toolkit_Settings::save_tab( 'general', $raw );

		BLT_Toolkit_Admin::redirect( 'overview', array(), 'modules_saved' );
	}
}
