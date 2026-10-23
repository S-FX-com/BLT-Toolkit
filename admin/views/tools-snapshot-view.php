<?php
/**
 * Tools > Snapshots > one snapshot: what changed in the roles since it was
 * taken (SNAP-4, "viewed as a diff"). Reading this page changes nothing.
 *
 * Included by BLT_Toolkit_Admin_Tools inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var array|null $snapshot    The snapshot described (id, when, ago, who, reason, is_baseline, valid), or null when it does not exist.
 * @var string     $diff_html   Role_Diff from the snapshot to the roles today, as escaped HTML.
 * @var array|null $counts      Role_Diff::counts() of that diff, or null when the snapshot is damaged.
 * @var string     $list_url    The snapshot list.
 * @var string     $restore_url The restore preview of this snapshot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_changed = is_array( $counts ) && (int) $counts['roles'] + (int) $counts['capabilities'] > 0;
?>
<?php if ( null === $snapshot ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'That snapshot does not exist', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'It may have been removed to keep only the newest ones.', 'blt-toolkit' ); ?></span>
				<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to snapshots', 'blt-toolkit' ); ?></a>
			</div>
		</div>
	</div>
<?php else : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				/* translators: %d: snapshot number. */
				printf( esc_html__( 'Changes since snapshot %d', 'blt-toolkit' ), (int) $snapshot['id'] );
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
			</p>
		</div>
		<div class="blt-card-body">
			<?php if ( ! $snapshot['valid'] ) : ?>
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Damaged', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( 'This snapshot\'s contents no longer match the checksum stored with it, so it cannot be shown or restored.', 'blt-toolkit' ); ?></span>
				</div>
			<?php elseif ( ! $blt_tk_changed ) : ?>
				<p><?php esc_html_e( 'Nothing has changed since this snapshot: your roles are the same today.', 'blt-toolkit' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: 1: a number of roles, such as 2 roles, 2: a number of capabilities, such as 14 capabilities. */
						esc_html__( 'Since this snapshot, %1$s and %2$s changed. Below, "added" means added since the snapshot was taken.', 'blt-toolkit' ),
						/* translators: %s: number of roles. */
						esc_html( sprintf( _n( '%s role', '%s roles', (int) $counts['roles'], 'blt-toolkit' ), number_format_i18n( (int) $counts['roles'] ) ) ),
						/* translators: %s: number of capabilities. */
						esc_html( sprintf( _n( '%s capability', '%s capabilities', (int) $counts['capabilities'], 'blt-toolkit' ), number_format_i18n( (int) $counts['capabilities'] ) ) )
					);
					?>
				</p>
				<?php echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in BLT_Toolkit_Admin_Activity::diff_html(). ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="blt-settings-footer">
		<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to snapshots', 'blt-toolkit' ); ?></a>
		<?php if ( $snapshot['valid'] && $blt_tk_changed ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $restore_url ); ?>"><?php esc_html_e( 'Preview restoring this snapshot', 'blt-toolkit' ); ?></a>
		<?php endif; ?>
	</div>
<?php endif; ?>
