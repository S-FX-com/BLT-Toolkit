<?php
/**
 * Settings > Activity log (SPEC 6.1, settings group `log`).
 *
 * Included inside the settings form by views/settings.php.
 *
 * @package Blt_Toolkit
 *
 * @var array  $settings BLT_Toolkit_Settings::all().
 * @var string $field    Name of the settings field array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$log = $settings['log'];
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Activity log', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Every switch, username change and role change is recorded with who made it, including who they were acting as if they had switched to another user.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-retention-days"><?php esc_html_e( 'Keep entries for', 'blt-toolkit' ); ?></label></div>
			<div>
				<?php BLT_Toolkit_Admin_Settings::number( 'log', 'retention_days', $log['retention_days'], 'blt-toolkit-retention-days', 'blt-toolkit-retention-days-desc' ); ?>
				<?php esc_html_e( 'days', 'blt-toolkit' ); ?>
				<p class="blt-field-desc" id="blt-toolkit-retention-days-desc"><?php esc_html_e( 'From 1 to 3,650. Older entries are removed in the background once a day.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Privacy', 'blt-toolkit' ); ?></div>
			<div>
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[record_ip]',
					$log['record_ip'],
					__( 'Record IP addresses', 'blt-toolkit' ),
					__( 'Stores the address each change came from, for security reviews. Applies to new entries.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Personal data requests', 'blt-toolkit' ); ?></div>
			<div>
				<p class="blt-field-desc"><?php esc_html_e( 'WordPress\'s personal data tools cover these entries. An export includes every entry a person made, that was made while someone had switched to their account, or that is about them. An erasure keeps those entries as a security record and removes the person\'s usernames and name from them, and the IP address stored with the entries they made.', 'blt-toolkit' ); ?></p>
				<?php if ( current_user_can( 'export_others_personal_data' ) || current_user_can( 'erase_others_personal_data' ) ) : ?>
					<p class="blt-field-desc">
						<?php if ( current_user_can( 'export_others_personal_data' ) ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'export-personal-data.php' ) ); ?>"><?php esc_html_e( 'Export Personal Data', 'blt-toolkit' ); ?></a>
						<?php endif; ?>
						<?php if ( current_user_can( 'erase_others_personal_data' ) ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'erase-personal-data.php' ) ); ?>"><?php esc_html_e( 'Erase Personal Data', 'blt-toolkit' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
