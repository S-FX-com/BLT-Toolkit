<?php
/**
 * Recent changes card (SPEC 13.2): the newest log rows of the usernames
 * module, with who made each change and when.
 *
 * Included by admin/views/usernames.php.
 *
 * @package Blt_Toolkit
 *
 * @var array[]                    $recent       Rows from BLT_Toolkit_Log::query().
 * @var BLT_Toolkit_Admin_Activity $activity     Formatting helpers.
 * @var bool                       $can_view_log Whether the person may open the Activity screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card blt-toolkit-recent">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Recent changes', 'blt-toolkit' ); ?></h2>
		<p>
			<?php
			if ( $can_view_log ) {
				esc_html_e( 'Username changes, bulk renames and template downloads from the activity log, newest first.', 'blt-toolkit' );
			} else {
				esc_html_e( 'The username changes, bulk renames and template downloads you made, newest first.', 'blt-toolkit' );
			}
			?>
		</p>
		<?php if ( $can_view_log && array() !== $recent ) : ?>
			<div class="blt-card-header-badges">
				<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin::page_url( 'activity', array( 'module' => 'usernames' ) ) ); ?>"><?php esc_html_e( 'View all in Activity', 'blt-toolkit' ); ?></a>
			</div>
		<?php endif; ?>
	</div>
	<div class="blt-card-body">
		<?php if ( array() === $recent ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'No username changes recorded yet', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'Each change is listed here with the old and new username, who made it and when.', 'blt-toolkit' ); ?></span>
			</div>
		<?php else : ?>
			<div class="blt-toolkit-table-scroll">
				<table class="widefat striped blt-toolkit-recent-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Recent username changes', 'blt-toolkit' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'When', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Who', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What happened', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'blt-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $blt_tk_row ) : ?>
							<?php $blt_tk_who = $activity->who( $blt_tk_row ); ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'When', 'blt-toolkit' ); ?>">
									<time datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $blt_tk_row['created_at'] . ' UTC' ) ) ); ?>" title="<?php echo esc_attr( $activity->site_time( $blt_tk_row['created_at'] ) ); ?>"><?php echo esc_html( $activity->relative_time( $blt_tk_row['created_at'] ) ); ?></time>
								</td>
								<td data-label="<?php esc_attr_e( 'Who', 'blt-toolkit' ); ?>">
									<?php echo esc_html( $blt_tk_who['actor'] ); ?>
									<?php if ( '' !== $blt_tk_who['acting_as'] ) : ?>
										<br /><span class="blt-field-desc">
											<?php
											/* translators: %s: the user someone was switched into. */
											echo esc_html( sprintf( __( 'acting as %s', 'blt-toolkit' ), $blt_tk_who['acting_as'] ) );
											?>
										</span>
									<?php endif; ?>
								</td>
								<td data-label="<?php esc_attr_e( 'What happened', 'blt-toolkit' ); ?>">
									<?php echo esc_html( $blt_tk_row['summary'] ); ?>
									<br /><span class="blt-field-desc"><?php echo esc_html( $activity->action_label( $blt_tk_row['action'] ) ); ?></span>
								</td>
								<td data-label="<?php esc_attr_e( 'Status', 'blt-toolkit' ); ?>"><?php echo $activity->status_badge( $blt_tk_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge(). ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>
