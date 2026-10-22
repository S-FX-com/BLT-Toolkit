<?php
/**
 * Activity screen.
 *
 * @package Blt_Toolkit
 *
 * @todo Phase 1 (LOG-1 to LOG-6) replaces render() with the filter toolbar,
 *       the paginated log table and the CSV export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Activity screen (SPEC 13.2). Needs `blt_view_toolkit_log`.
 *
 * In Phase 0 it says what it will hold. It reads nothing and writes nothing.
 */
class BLT_Toolkit_Admin_Activity {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$page_class = 'blt-toolkit-activity';
		$sub        = BLT_Toolkit_Admin::screen_title( 'activity' );
		$actions    = array();
		$module_key = '';
		$screen_key = 'activity';
		$tabs       = array();
		$tab        = '';
		$tabs_label = '';

		if ( current_user_can( 'manage_options' ) ) {
			$actions[] = array(
				'label' => __( 'Log settings', 'blt-toolkit' ),
				'url'   => BLT_Toolkit_Admin::page_url( 'settings', array( 'tab' => 'log' ) ),
			);
		}

		$cards = array(
			array(
				'title'       => __( 'Activity log', 'blt-toolkit' ),
				'desc'        => __( 'Every switch, username change and role change, with who did it.', 'blt-toolkit' ),
				'empty_title' => __( 'No activity recorded yet', 'blt-toolkit' ),
				'empty_text'  => __( 'Entries will be listed here with who made each change and when, who they were acting as if they had switched to another user, and what changed. You will be able to filter by module, action, person and date, and export the list as a CSV file.', 'blt-toolkit' ),
			),
		);

		require BLT_TOOLKIT_DIR . 'admin/views/placeholder.php';
	}
}
