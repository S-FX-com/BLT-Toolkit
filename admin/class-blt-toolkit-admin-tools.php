<?php
/**
 * Tools screen.
 *
 * @package Blt_Toolkit
 *
 * @todo Phase 1 and Phase 6 (SNAP-4, RO-5, RO-43 to RO-45, SPEC 15) replace
 *       each tab's card with its tool.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tools screen (SPEC 13.2). Needs `blt_reset_roles`; the "Import from
 * legacy plugins" tab also needs `manage_options` (SPEC 13.1).
 *
 * Tabs are links, one URL each. In Phase 0 each tab says what it will hold.
 * It reads nothing and writes nothing.
 */
class BLT_Toolkit_Admin_Tools {

	/**
	 * Tabs the current user may open, key => label.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		$tabs = array(
			'snapshots' => __( 'Snapshots', 'blt-toolkit' ),
			'transfer'  => __( 'Export and import', 'blt-toolkit' ),
			'reset'     => __( 'Reset', 'blt-toolkit' ),
			'sync'      => __( 'Sync administrator', 'blt-toolkit' ),
		);

		if ( current_user_can( 'manage_options' ) ) {
			$tabs['legacy'] = __( 'Import from legacy plugins', 'blt-toolkit' );
		}

		return $tabs;
	}

	/**
	 * The open tab. An unknown tab, or one the user may not open, falls back
	 * to the first.
	 *
	 * @return string
	 */
	public function current_tab() {
		$tabs = $this->tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return isset( $tabs[ $tab ] ) ? $tab : (string) key( $tabs );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$page_class = 'blt-toolkit-tools';
		$sub        = BLT_Toolkit_Admin::screen_title( 'tools' );
		$actions    = array();
		$module_key = '';
		$screen_key = 'tools';
		$tabs       = $this->tabs();
		$tab        = $this->current_tab();
		$tabs_label = __( 'Tools sections', 'blt-toolkit' );
		$cards      = array( $this->card( $tab ) );

		require BLT_TOOLKIT_DIR . 'admin/views/placeholder.php';
	}

	/**
	 * What a tab will hold.
	 *
	 * @param string $tab Tab key.
	 * @return array{title:string,desc:string,empty_title:string,empty_text:string}
	 */
	private function card( $tab ) {
		switch ( $tab ) {
			case 'transfer':
				return array(
					'title'       => __( 'Export and import roles', 'blt-toolkit' ),
					'desc'        => __( 'Reuse a set of roles on another site.', 'blt-toolkit' ),
					'empty_title' => __( 'Export and import are not here yet', 'blt-toolkit' ),
					'empty_text'  => __( 'You will be able to export selected roles as a file and import them on another site, choosing for each role whether to create, merge, replace or skip it after a preview of what would change.', 'blt-toolkit' ),
				);

			case 'reset':
				return array(
					'title'       => __( 'Reset roles', 'blt-toolkit' ),
					'desc'        => __( 'Go back to WordPress\'s default roles.', 'blt-toolkit' ),
					'empty_title' => __( 'Reset is not here yet', 'blt-toolkit' ),
					'empty_text'  => __( 'You will be able to reset the built-in roles to their default capabilities and keep custom roles, or reset every role as on a fresh install. Both show a preview first, take a snapshot, and ask you to type RESET to confirm.', 'blt-toolkit' ),
				);

			case 'sync':
				return array(
					'title'       => __( 'Sync administrator', 'blt-toolkit' ),
					'desc'        => __( 'Give the Administrator role every capability registered on this site.', 'blt-toolkit' ),
					'empty_title' => __( 'Sync is not here yet', 'blt-toolkit' ),
					'empty_text'  => __( 'You will see which registered capabilities the Administrator role is missing and grant them in one step. BLT Toolkit never changes a role just because a screen was opened.', 'blt-toolkit' ),
				);

			case 'legacy':
				return array(
					'title'       => __( 'Import from legacy plugins', 'blt-toolkit' ),
					'desc'        => __( 'Bring settings over from Username Changer and User Role Editor.', 'blt-toolkit' ),
					'empty_title' => __( 'Imports are not here yet', 'blt-toolkit' ),
					'empty_text'  => __( 'You will be able to import Username Changer\'s settings and audit log, and User Role Editor\'s extra default roles, hidden toolbars and role backup, each after a preview. The other plugin\'s data is never changed or deleted. User Switching keeps nothing on the server, so there is nothing to import from it.', 'blt-toolkit' ),
				);
		}

		return array(
			'title'       => __( 'Role snapshots', 'blt-toolkit' ),
			'desc'        => __( 'A copy of every role is kept before each change.', 'blt-toolkit' ),
			'empty_title' => __( 'Snapshots are not listed here yet', 'blt-toolkit' ),
			'empty_text'  => __( 'BLT Toolkit keeps a baseline copy of your roles from when it was first activated, and a copy before every change it makes. This tab will list them, show what differs from your roles today, and restore any of them after a preview.', 'blt-toolkit' ),
		);
	}
}
