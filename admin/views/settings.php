<?php
/**
 * The Settings screen: header, tabs, and the open tab's form.
 *
 * @package Blt_Toolkit
 *
 * @var string               $tab          Open tab.
 * @var array<string,string> $tabs         Tab key => label.
 * @var array                $settings     BLT_Toolkit_Settings::all().
 * @var array                $ranges       BLT_Toolkit_Settings::INT_RANGES.
 * @var string               $field        Name of the settings field array.
 * @var array                $actions      Header buttons.
 * @var string               $update_url   Nonced "Check for Updates" URL, or ''.
 * @var array                $modules      General tab: BLT_Toolkit_Admin::modules().
 * @var string               $token_status Advanced tab: BLT_Toolkit_Settings::github_token_status().
 * @var int                  $last_check   Advanced tab: time of the last update check, or 0.
 * @var string[]             $disabled     Advanced tab: modules named in BLT_TOOLKIT_DISABLE_MODULES.
 * @var bool                 $recovery     Whether BLT_TOOLKIT_RECOVERY is set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap blt-ui blt-toolkit blt-toolkit-settings">
	<?php BLT_Toolkit_Admin::header( __( 'Settings', 'blt-toolkit' ), $actions ); ?>

	<?php BLT_Toolkit_Admin::tabs( 'settings', $tabs, $tab, __( 'Settings sections', 'blt-toolkit' ) ); ?>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="blt-toolkit-settings-form">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_save_settings' ); ?>
		<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />

		<?php require BLT_TOOLKIT_DIR . 'admin/views/settings-' . $tab . '.php'; ?>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary blt-save-button"><?php esc_html_e( 'Save changes', 'blt-toolkit' ); ?></button>
		</div>
	</form>

	<?php if ( 'advanced' === $tab ) : ?>
		<?php // Targets of the Test connection and Remove token buttons in the token card (their `form` attribute). ?>
		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::FORM_TEST ); ?>">
			<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_test_connection' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::FORM_REMOVE ); ?>">
			<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_remove_token' ); ?>
		</form>
	<?php endif; ?>
</div>
