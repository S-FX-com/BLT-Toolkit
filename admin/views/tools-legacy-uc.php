<?php
/**
 * Tools > Import from legacy plugins > Username Changer: the dry run of the
 * import (SPEC 15), a toggle per item and the Import button; the audit log
 * job's progress while it runs.
 *
 * Nothing here writes; Username Changer's option and table are only read.
 *
 * Included by BLT_Toolkit_Admin_Legacy::render() inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var array  $plan     BLT_Toolkit_Legacy::uc_plan().
 * @var array  $history  Item => "Imported ... by ...".
 * @var bool   $partly   The audit log was partly imported and no job runs.
 * @var array  $choices  Item => array( 'available', 'checked' ).
 * @var string $list_url The tab's first page.
 * @var string $log_url  The Activity screen filtered to usernames, or ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_settings = $plan['settings'];
$blt_tk_roles    = $plan['roles'];
$blt_tk_audit    = $plan['audit'];
$blt_tk_any      = in_array( true, array_column( $choices, 'available' ), true );
?>
<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'All legacy plugins', 'blt-toolkit' ); ?></a></p>

<?php if ( $plan['active'] ) : ?>
	<div class="blt-callout">
		<strong><?php esc_html_e( 'Username Changer is active', 'blt-toolkit' ); ?></strong>
		<span><?php esc_html_e( 'BLT Toolkit\'s Usernames module is not running while it is. Import, then deactivate Username Changer. Its data stays where it is either way.', 'blt-toolkit' ); ?></span>
	</div>
<?php endif; ?>

<?php if ( ! $plan['found'] ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'Nothing from Username Changer on this site', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'Neither its settings nor its audit log are in the database, so there is nothing to import.', 'blt-toolkit' ); ?></span>
			</div>
		</div>
	</div>
	<?php
	return;
endif;
?>

<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Legacy::UC_ACTION ); ?>
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Legacy::EXPECTED_FIELD ); ?>" value="<?php echo esc_attr( $plan['expected'] ); ?>" />

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Settings', 'blt-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'The minimum length and the notification email map to Settings > Usernames; the template tags in the email have the same names.', 'blt-toolkit' ); ?></p>
			<?php if ( isset( $history['uc_settings'] ) ) : ?>
				<div class="blt-card-header-badges"><?php echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></div>
			<?php endif; ?>
		</div>
		<div class="blt-card-body">
			<?php if ( null === $blt_tk_settings ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'No settings found', 'blt-toolkit' ); ?></span>
					<span><?php esc_html_e( 'Username Changer\'s settings are not in the database.', 'blt-toolkit' ); ?></span>
				</div>
			<?php else : ?>
				<?php foreach ( $blt_tk_settings['rows'] as $blt_tk_row ) : ?>
					<div class="blt-field">
						<div class="blt-field-label"><?php echo esc_html( $blt_tk_row['label'] ); ?></div>
						<div class="blt-toolkit-legacy-value">
							<?php if ( in_array( $blt_tk_row['key'], $blt_tk_settings['kept'], true ) ) : ?>
								<p><?php esc_html_e( 'Username Changer uses its default here, so BLT Toolkit\'s stays as it is:', 'blt-toolkit' ); ?></p>
								<p class="blt-toolkit-legacy-text"><?php echo esc_html( $blt_tk_row['now'] ); ?></p>
							<?php elseif ( ! $blt_tk_row['changes'] ) : ?>
								<p><?php esc_html_e( 'Already the same:', 'blt-toolkit' ); ?></p>
								<p class="blt-toolkit-legacy-text"><?php echo esc_html( $blt_tk_row['to'] ); ?></p>
							<?php else : ?>
								<p><?php esc_html_e( 'Now:', 'blt-toolkit' ); ?></p>
								<p class="blt-toolkit-legacy-text"><?php echo esc_html( $blt_tk_row['now'] ); ?></p>
								<p><?php esc_html_e( 'After the import:', 'blt-toolkit' ); ?></p>
								<p class="blt-toolkit-legacy-text"><?php echo esc_html( $blt_tk_row['to'] ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<?php if ( array() !== $blt_tk_settings['tags'] ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Tags BLT Toolkit does not replace', 'blt-toolkit' ); ?></strong>
						<span><?php esc_html_e( 'The email uses these Username Changer tags, which BLT Toolkit leaves as typed. Edit the email in Settings > Usernames after the import; {display_name} names the person.', 'blt-toolkit' ); ?></span>
						<span class="blt-chips">
							<?php foreach ( $blt_tk_settings['tags'] as $blt_tk_tag ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_tag ); ?></code>
							<?php endforeach; ?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( array() !== $blt_tk_settings['dropped'] ) : ?>
					<p class="blt-field-desc">
						<?php
						printf(
							/* translators: %s: number of custom texts. */
							esc_html( _n( 'Not imported: %s custom button label or message. BLT Toolkit\'s texts are translated instead.', 'Not imported: %s custom button labels and messages. BLT Toolkit\'s texts are translated instead.', count( $blt_tk_settings['dropped'] ), 'blt-toolkit' ) ),
							esc_html( number_format_i18n( count( $blt_tk_settings['dropped'] ) ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( isset( $history['uc_settings'] ) ) : ?>
					<p class="blt-field-desc"><?php echo esc_html( $history['uc_settings'] ); ?></p>
				<?php endif; ?>

				<div class="blt-toggle-stack">
					<?php
					BLT_Toolkit_Admin_Legacy::item_toggle(
						'uc_settings',
						$choices['uc_settings'],
						__( 'Import these settings', 'blt-toolkit' ),
						isset( $history['uc_settings'] ) ? __( 'Importing again overwrites any change made in Settings > Usernames since.', 'blt-toolkit' ) : ''
					);
					?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( null !== $blt_tk_settings ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Who may change their own username', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Username Changer\'s Allowed Roles become BLT Toolkit\'s "Change their own username" permission on those roles. A copy of the roles is saved first.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php if ( is_wp_error( $blt_tk_roles['guard'] ) ) : ?>
					<div class="notice notice-error inline" role="alert">
						<p>
							<?php
							printf(
								/* translators: %s: the reason. */
								esc_html__( 'You cannot give these roles the permission: %s', 'blt-toolkit' ),
								esc_html( $blt_tk_roles['guard']->get_error_message() )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php
				$blt_tk_groups = array(
					'add'     => __( 'Get the permission', 'blt-toolkit' ),
					'already' => __( 'Already have it', 'blt-toolkit' ),
					'denied'  => __( 'Denied on purpose in BLT Toolkit, left as they are', 'blt-toolkit' ),
					'missing' => __( 'Not roles on this site, skipped', 'blt-toolkit' ),
				);
				$blt_tk_shown  = false;
				?>
				<?php foreach ( $blt_tk_groups as $blt_tk_group => $blt_tk_label ) : ?>
					<?php
					if ( array() === $blt_tk_roles[ $blt_tk_group ] ) {
						continue;
					}

					$blt_tk_shown = true;
					?>
					<div class="blt-field">
						<div class="blt-field-label"><?php echo esc_html( $blt_tk_label ); ?></div>
						<div>
							<span class="blt-chips">
								<?php foreach ( $blt_tk_roles[ $blt_tk_group ] as $blt_tk_role => $blt_tk_name ) : ?>
									<code class="blt-chip"><?php echo esc_html( $blt_tk_name ); ?></code>
								<?php endforeach; ?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>

				<?php if ( array() !== $blt_tk_roles['add'] ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Username Changer never applied this setting', 'blt-toolkit' ); ?></strong>
						<span><?php esc_html_e( 'Username Changer 3.3.1 did not enforce Allowed Roles: a defect in its permission check let only administrators change their own username, whatever the setting said. Importing it lets the roles under "Get the permission" change their own username for the first time. This step is off until you turn it on; you can also give the permission later in Settings > Usernames.', 'blt-toolkit' ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( ! $blt_tk_shown ) : ?>
					<div class="blt-empty">
						<span class="blt-empty-title"><?php esc_html_e( 'No allowed roles', 'blt-toolkit' ); ?></span>
						<span><?php esc_html_e( 'Username Changer lets only administrators change usernames here, so no role changes.', 'blt-toolkit' ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( isset( $history['uc_roles'] ) ) : ?>
					<p class="blt-field-desc"><?php echo esc_html( $history['uc_roles'] ); ?></p>
				<?php endif; ?>

				<div class="blt-toggle-stack">
					<?php BLT_Toolkit_Admin_Legacy::item_toggle( 'uc_roles', $choices['uc_roles'], __( 'Give these roles the permission', 'blt-toolkit' ) ); ?>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Audit log', 'blt-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'Each username change Username Changer recorded is copied into BLT Toolkit\'s activity log, marked as imported from Username Changer, with its original time and who made it, and with its IP address while Settings > Activity log records IP addresses.', 'blt-toolkit' ); ?></p>
			<div class="blt-card-header-badges">
				<?php
				if ( '' !== $blt_tk_audit['job'] ) {
					echo BLT_Toolkit_Admin::badge( 'pending', __( 'Importing now', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				} elseif ( $partly ) {
					echo BLT_Toolkit_Admin::badge( 'pending', __( 'Partly imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				} elseif ( $blt_tk_audit['exists'] && $blt_tk_audit['total'] > 0 && 0 === $blt_tk_audit['remaining'] ) {
					echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}
				?>
			</div>
		</div>
		<div class="blt-card-body">
			<?php if ( ! $blt_tk_audit['exists'] ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'No audit log found', 'blt-toolkit' ); ?></span>
					<span><?php esc_html_e( 'Username Changer keeps one only with its Pro add-on, and its table is not in the database.', 'blt-toolkit' ); ?></span>
				</div>
			<?php else : ?>
				<div class="blt-stats">
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Entries', 'blt-toolkit' ); ?></span>
						<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( $blt_tk_audit['total'] ) ); ?></span>
						<span class="blt-stat-sub"><?php esc_html_e( 'in Username Changer\'s log', 'blt-toolkit' ); ?></span>
					</div>
					<div class="blt-stat">
						<span class="blt-stat-label"><?php esc_html_e( 'Still to import', 'blt-toolkit' ); ?></span>
						<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( $blt_tk_audit['remaining'] ) ); ?></span>
						<span class="blt-stat-sub">
							<?php
							if ( $blt_tk_audit['last_id'] > 0 ) {
								printf(
									/* translators: %s: entry number. */
									esc_html__( 'imported up to entry %s', 'blt-toolkit' ),
									esc_html( number_format_i18n( $blt_tk_audit['last_id'] ) )
								);
							} else {
								esc_html_e( 'nothing imported yet', 'blt-toolkit' );
							}
							?>
						</span>
					</div>
				</div>

				<?php if ( $blt_tk_audit['old'] > 0 ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Older than the activity log keeps', 'blt-toolkit' ); ?></strong>
						<span>
							<?php
							printf(
								/* translators: 1: number of entries, 2: number of days. */
								esc_html( _n( '%1$s entry is older than %2$s days, so the daily cleanup will remove it from the activity log after the import. To keep it, raise "Keep entries for" in Settings > Activity log first.', '%1$s entries are older than %2$s days, so the daily cleanup will remove them from the activity log after the import. To keep them, raise "Keep entries for" in Settings > Activity log first.', $blt_tk_audit['old'], 'blt-toolkit' ) ),
								esc_html( number_format_i18n( $blt_tk_audit['old'] ) ),
								esc_html( number_format_i18n( $blt_tk_audit['retention'] ) )
							);
							?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( $blt_tk_audit['remaining'] > BLT_Toolkit_Legacy::INLINE_MAX && '' === $blt_tk_audit['job'] ) : ?>
					<p class="blt-field-desc"><?php esc_html_e( 'This many entries are copied in the background, in batches; you can leave the page while it runs.', 'blt-toolkit' ); ?></p>
				<?php endif; ?>

				<?php if ( isset( $history['uc_audit'] ) ) : ?>
					<p class="blt-field-desc"><?php echo esc_html( $history['uc_audit'] ); ?></p>
				<?php endif; ?>

				<?php if ( '' !== $log_url && $blt_tk_audit['last_id'] > 0 ) : ?>
					<p><a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'See username changes in the activity log', 'blt-toolkit' ); ?></a></p>
				<?php endif; ?>

				<?php if ( '' === $blt_tk_audit['job'] ) : ?>
					<div class="blt-toggle-stack">
						<?php
						BLT_Toolkit_Admin_Legacy::item_toggle(
							'uc_audit',
							$choices['uc_audit'],
							__( 'Copy the entries not imported yet', 'blt-toolkit' ),
							0 === $blt_tk_audit['remaining'] ? __( 'Every entry is already in the activity log.', 'blt-toolkit' ) : ''
						);
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $blt_tk_any ) : ?>
		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Import the selected items', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	<?php else : ?>
		<p class="blt-field-desc"><?php esc_html_e( 'There is nothing left to import from Username Changer.', 'blt-toolkit' ); ?></p>
	<?php endif; ?>
</form>

<?php
// JOB-4: the audit log job's progress, outside the import form (its buttons
// are forms of their own).
if ( '' !== $blt_tk_audit['job'] ) {
	BLT_Toolkit_Jobs::render_ui( $blt_tk_audit['job'] );
}
