<?php
/**
 * Usernames screen.
 *
 * @package Blt_Toolkit
 *
 * @todo Phase 3 (UN-30) replaces render() with the bulk rename card and the
 *       recent changes from the activity log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Usernames screen (SPEC 13.2). Needs `blt_change_usernames`.
 *
 * Single usernames are changed from the user's profile (UN-16); this screen
 * is for bulk renames and the record of past changes. In Phase 0 it says what
 * it will hold. It reads nothing and writes nothing.
 */
class BLT_Toolkit_Admin_Usernames {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$page_class = 'blt-toolkit-usernames';
		$sub        = BLT_Toolkit_Admin::screen_title( 'usernames' );
		$actions    = array();
		$module_key = 'usernames';
		$screen_key = 'usernames';
		$tabs       = array();
		$tab        = '';
		$tabs_label = '';

		if ( current_user_can( 'manage_options' ) ) {
			$actions[] = array(
				'label' => __( 'Username settings', 'blt-toolkit' ),
				'url'   => BLT_Toolkit_Admin::page_url( 'settings', array( 'tab' => 'usernames' ) ),
			);
		}

		$cards = array(
			array(
				'title'       => __( 'Bulk rename from CSV', 'blt-toolkit' ),
				'desc'        => __( 'Change many usernames in one pass.', 'blt-toolkit' ),
				'empty_title' => __( 'Bulk renaming is not here yet', 'blt-toolkit' ),
				'empty_text'  => __( 'You will be able to download a template of every user, upload a CSV of new usernames, check a dry run that flags every problem row, and then run the renames in the background with a progress bar. Single usernames will be changed from each user\'s profile.', 'blt-toolkit' ),
			),
			array(
				'title'       => __( 'Recent changes', 'blt-toolkit' ),
				'desc'        => __( 'Username changes from the activity log.', 'blt-toolkit' ),
				'empty_title' => __( 'No username changes recorded yet', 'blt-toolkit' ),
				'empty_text'  => __( 'Each change will be listed here with the old and new username, who made it and when.', 'blt-toolkit' ),
			),
		);

		require BLT_TOOLKIT_DIR . 'admin/views/placeholder.php';
	}
}
