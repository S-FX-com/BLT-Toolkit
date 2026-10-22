<?php
/**
 * Tools > Snapshots > restore one: the dry run, then confirm (SNAP-4).
 *
 * Shows what restoring changes (the role store's own dry run, so the diff,
 * the Guard's verdict and the Critical list are those of the write), says in
 * words what Administrator gets beyond the snapshot (the SEC-12 lockout set,
 * and BLT Toolkit's capabilities when the snapshot predates them), and asks
 * for the SEC-10 and SEC-13 confirmations when they apply. The form carries
 * the hash of the roles the preview was made from, so roles that change in
 * between refuse the restore. Reading this page changes nothing (RO-5).
 *
 * Included by BLT_Toolkit_Admin_Tools inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string     $error     Why there is no plan (not found, damaged), or ''.
 * @var array|null $plan      BLT_Toolkit_Snapshots::restore_plan().
 * @var array|null $snapshot  The snapshot described (id, when, ago, who, reason, is_baseline).
 * @var string     $diff_html What the restore changes, as escaped HTML.
 * @var array[]    $critical  SEC-10 rows (see tools-review.php).
 * @var string     $list_url  The snapshot list.
 * @var string     $view_url  Changes since this snapshot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( null === $plan ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'This snapshot cannot be restored', 'blt-toolkit' ); ?></span>
				<span><?php echo esc_html( $error ); ?></span>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to snapshots', 'blt-toolkit' ); ?></a>
			</div>
		</div>
	</div>
	<?php
	return;
endif;

$blt_tk_guard     = $plan['guard'];
$blt_tk_blocked   = is_wp_error( $blt_tk_guard ) || $plan['in_code'] || $plan['unchanged'];
$blt_tk_lockout   = ! $blt_tk_blocked && $plan['lockout'];
$blt_tk_additions = $plan['additions'];
$blt_tk_counts    = $plan['counts'];
$critical         = $blt_tk_blocked ? array() : $critical;
?>
<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Tools::RESTORE_ACTION ); ?>
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::SNAPSHOT_ARG ); ?>" value="<?php echo esc_attr( (string) $snapshot['id'] ); ?>" />
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::EXPECTED_FIELD ); ?>" value="<?php echo esc_attr( $plan['expected'] ); ?>" />

	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				/* translators: %d: snapshot number. */
				printf( esc_html__( 'Restore snapshot %d', 'blt-toolkit' ), (int) $snapshot['id'] );
				?>
			</h2>
			<?php if ( $snapshot['is_baseline'] ) : ?>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Baseline', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: 1: date and time, 2: how long ago, 3: who, 4: why it was taken. */
					esc_html__( 'Taken %1$s (%2$s) by %3$s. %4$s.', 'blt-toolkit' ),
					esc_html( $snapshot['when'] ),
					esc_html( $snapshot['ago'] ),
					esc_html( $snapshot['who'] ),
					esc_html( $snapshot['reason'] )
				);
				?>
				<?php esc_html_e( 'Nothing is changed yet: this is what restoring it would do.', 'blt-toolkit' ); ?>
			</p>
		</div>
		<div class="blt-card-body">
			<?php if ( is_wp_error( $blt_tk_guard ) ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p>
						<?php
						printf(
							/* translators: %s: the reason. */
							esc_html__( 'You cannot restore this snapshot: %s', 'blt-toolkit' ),
							esc_html( $blt_tk_guard->get_error_message() )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $plan['in_code'] ) : ?>
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Roles defined in code', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( 'This site defines its roles in code ($wp_user_roles), so they cannot be restored from here. The changes below are what the snapshot holds.', 'blt-toolkit' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $plan['unchanged'] ) : ?>
				<p><?php esc_html_e( 'Your roles already match this snapshot, so restoring it would change nothing.', 'blt-toolkit' ); ?></p>
			<?php else : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'What restoring changes', 'blt-toolkit' ); ?></div>
					<div>
						<p>
							<?php
							printf(
								/* translators: 1: a number of roles, such as 2 roles, 2: a number of capabilities, such as 14 capabilities. */
								esc_html__( 'Restoring changes %1$s and %2$s. Below, "added" means the restore adds it, "removed" that the restore takes it away.', 'blt-toolkit' ),
								/* translators: %s: number of roles. */
								esc_html( sprintf( _n( '%s role', '%s roles', (int) $blt_tk_counts['roles'], 'blt-toolkit' ), number_format_i18n( (int) $blt_tk_counts['roles'] ) ) ),
								/* translators: %s: number of capabilities. */
								esc_html( sprintf( _n( '%s capability', '%s capabilities', (int) $blt_tk_counts['capabilities'], 'blt-toolkit' ), number_format_i18n( (int) $blt_tk_counts['capabilities'] ) ) )
							);
							?>
						</p>
						<?php echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in BLT_Toolkit_Admin_Activity::diff_html(). ?>
					</div>
				</div>
			<?php endif; ?>

			<?php
			// RO-43 for a restore: who holds the roles it deletes, who is left
			// with none, and content types with separate permissions.
			$blt_tk_is_reset = false;
			require BLT_TOOLKIT_DIR . 'admin/views/tools-removed-roles.php';
			?>

			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Administrator, beyond the snapshot', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( $blt_tk_additions['kept_role'] ) : ?>
						<p><?php esc_html_e( 'This snapshot has no Administrator role. The Administrator role is never deleted, so it stays as it is today.', 'blt-toolkit' ); ?></p>
					<?php endif; ?>

					<?php if ( array() === $blt_tk_additions['lockout'] && array() === $blt_tk_additions['blt'] && array() === $blt_tk_additions['isolated'] && array() === $blt_tk_additions['other'] ) : ?>
						<p><?php esc_html_e( 'Nothing: the Administrator role is restored exactly as the snapshot has it.', 'blt-toolkit' ); ?></p>
					<?php endif; ?>

					<?php if ( array() !== $blt_tk_additions['lockout'] ) : ?>
						<p><?php esc_html_e( 'The Administrator role also keeps these, which the snapshot does not grant, because without them nobody could manage the site or its roles:', 'blt-toolkit' ); ?></p>
						<p class="blt-chips">
							<?php foreach ( $blt_tk_additions['lockout'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>

					<?php if ( array() !== $blt_tk_additions['blt'] ) : ?>
						<p><?php esc_html_e( 'The snapshot was taken before BLT Toolkit gave out its capabilities, so the Administrator role also gets them, as when BLT Toolkit was activated:', 'blt-toolkit' ); ?></p>
						<p class="blt-chips">
							<?php foreach ( $blt_tk_additions['blt'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>

					<?php if ( array() !== $blt_tk_additions['isolated'] ) : ?>
						<p><?php esc_html_e( 'The snapshot was taken before some content types got separate permissions, so the Administrator role also gets their capabilities, matching the Posts or Pages access it has:', 'blt-toolkit' ); ?></p>
						<p class="blt-chips">
							<?php foreach ( $blt_tk_additions['isolated'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>

					<?php if ( array() !== $blt_tk_additions['other'] ) : ?>
						<p class="blt-chips">
							<?php foreach ( $blt_tk_additions['other'] as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! $blt_tk_blocked ) : ?>
				<p class="blt-field-desc"><?php esc_html_e( 'Before anything is written, your roles as they are now are saved as a new snapshot, so this restore can be undone by restoring that one.', 'blt-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<?php require BLT_TOOLKIT_DIR . 'admin/views/tools-review.php'; ?>

	<div class="blt-settings-footer">
		<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		<a class="button" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'Changes since this snapshot', 'blt-toolkit' ); ?></a>
		<?php if ( ! $blt_tk_blocked ) : ?>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Restore this snapshot', 'blt-toolkit' ); ?></button>
		<?php endif; ?>
	</div>
</form>
