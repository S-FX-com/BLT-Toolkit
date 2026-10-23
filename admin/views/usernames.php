<?php
/**
 * Usernames screen (SPEC 13.2): the bulk rename job's progress, the Bulk
 * rename from CSV card (upload or dry run) and Recent changes.
 *
 * Included by BLT_Toolkit_Admin_Usernames::render().
 *
 * @package Blt_Toolkit
 *
 * @var string                     $sub          Subtitle.
 * @var array                      $actions      Header buttons.
 * @var bool                       $module_on    Whether the Usernames module runs.
 * @var array|null                 $plan         The person's stored plan for the URL's token, or null.
 * @var bool                       $plan_missing The URL named a plan that is gone.
 * @var array|null                 $view         The dry run's page of rows (plan_view()), or null.
 * @var string                     $job_id       Job whose panel shows, or ''.
 * @var array|null                 $live         The running bulk rename, or null.
 * @var array                      $settings     `notify` and `sign_out_others` as they would be pinned now.
 * @var bool                       $needs_ack    Whether starting needs the backup acknowledgment.
 * @var int                        $max_rows     Row cap (rows with a new username).
 * @var int                        $max_upload   Largest CSV accepted, in bytes.
 * @var array[]                    $recent       Recent usernames log rows.
 * @var BLT_Toolkit_Admin_Activity $activity     Formatting helpers for log rows.
 * @var bool                       $can_view_log Whether the person may open the Activity screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap blt-ui blt-toolkit blt-toolkit-usernames">
	<?php BLT_Toolkit_Admin::header( $sub, $actions ); ?>

	<?php BLT_Toolkit_Admin::module_callout( 'usernames' ); ?>

	<?php if ( $plan_missing ) : ?>
		<div class="notice notice-warning inline blt-toolkit-notice" role="status">
			<p><?php esc_html_e( 'That dry run is no longer available: it expired after an hour, was started, or was replaced by a newer upload. Upload the file again.', 'blt-toolkit' ); ?></p>
		</div>
	<?php elseif ( null !== $plan && isset( $plan['error'] ) ) : ?>
		<div class="notice notice-error inline blt-toolkit-notice" role="alert">
			<p>
				<?php
				if ( ! empty( $plan['file'] ) ) {
					printf(
						/* translators: 1: file name, 2: what is wrong with it. */
						esc_html__( '%1$s could not be used: %2$s', 'blt-toolkit' ),
						esc_html( $plan['file'] ),
						esc_html( $plan['error'] )
					);
				} else {
					printf(
						/* translators: %s: what is wrong with the upload. */
						esc_html__( 'Nothing was uploaded: %s', 'blt-toolkit' ),
						esc_html( $plan['error'] )
					);
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	if ( $module_on && '' !== $job_id ) {
		BLT_Toolkit_Jobs::render_ui( $job_id );
	}
	?>

	<?php if ( $module_on ) : ?>
		<div class="blt-card blt-toolkit-bulk">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Bulk rename from CSV', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Change many usernames in one pass: check a dry run of the whole file first, then the renames run in the background.', 'blt-toolkit' ); ?></p>
				<?php if ( null !== $view ) : ?>
					<div class="blt-card-header-badges">
						<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Dry run', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
					</div>
				<?php endif; ?>
			</div>

			<?php
			if ( null !== $view ) {
				require BLT_TOOLKIT_DIR . 'admin/views/usernames-plan.php';
			} else {
				require BLT_TOOLKIT_DIR . 'admin/views/usernames-upload.php';
			}
			?>
		</div>
	<?php endif; ?>

	<?php require BLT_TOOLKIT_DIR . 'admin/views/usernames-recent.php'; ?>
</div>
