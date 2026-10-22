<?php
/**
 * Roles screen.
 *
 * @package Blt_Toolkit
 *
 * @todo Phase 4 (RO-*) and Phase 5 (RS-*) replace render() with the role
 *       editor: role list, role header, Simple and Advanced views, save bar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Roles screen (SPEC 13.2), reached from BLT Toolkit > Roles and from
 * Users > Roles (D4). Needs `blt_edit_roles`.
 *
 * In Phase 0 it says what it will hold. It reads nothing and writes nothing.
 */
class BLT_Toolkit_Admin_Roles {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$page_class = 'blt-toolkit-roles';
		$sub        = BLT_Toolkit_Admin::screen_title( 'roles' );
		$actions    = array();
		$module_key = 'roles';
		$screen_key = 'roles';
		$tabs       = array();
		$tab        = '';
		$tabs_label = '';
		$cards      = array(
			array(
				'title'       => __( 'Role editor', 'blt-toolkit' ),
				'desc'        => __( 'Create roles and decide what each one can do.', 'blt-toolkit' ),
				'empty_title' => __( 'The role editor is not here yet', 'blt-toolkit' ),
				'empty_text'  => __( 'This screen will list every role with how many people hold it, and edit what each role can do in two views of the same data: Simple, with one plain-English access level per content type, and Advanced, with every capability. Each save will be reviewed first and snapshotted so it can be restored. Until then, BLT Toolkit changes no roles, apart from giving the Administrator role BLT Toolkit\'s own capabilities when it was activated.', 'blt-toolkit' ),
			),
		);

		require BLT_TOOLKIT_DIR . 'admin/views/placeholder.php';
	}
}
