<?php
/**
 * Bulk rename from CSV, the dry run (UN-30 steps 4 and 5): the numbers, every
 * row with its status and warnings, and the confirm form with the backup
 * acknowledgment. The start and discard forms are siblings, never nested.
 *
 * Included by admin/views/usernames.php inside the card.
 *
 * @package Blt_Toolkit
 *
 * @var array      $plan      The stored plan (rows, order, counts, file, token, created).
 * @var array      $view      The page of rows (BLT_Toolkit_Admin_Usernames::plan_view()).
 * @var array|null $live      The running bulk rename, or null.
 * @var array      $settings  `notify` and `sign_out_others` as they would be pinned now.
 * @var bool       $needs_ack Whether starting needs the backup acknowledgment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_counts = $plan['counts'];
$blt_tk_ready  = (int) $blt_tk_counts[ BLT_Toolkit_Rename_Planner::READY ];
$blt_tk_token  = (string) $plan['token'];
$blt_tk_tabs   = array(
	'all'       => array( __( 'All', 'blt-toolkit' ), (int) $blt_tk_counts['total'] ),
	'ready'     => array( __( 'Ready', 'blt-toolkit' ), $blt_tk_ready ),
	'error'     => array( __( 'Errors', 'blt-toolkit' ), (int) $blt_tk_counts[ BLT_Toolkit_Rename_Planner::ERROR ] ),
	'no_change' => array( __( 'No change', 'blt-toolkit' ), (int) $blt_tk_counts[ BLT_Toolkit_Rename_Planner::NO_CHANGE ] ),
);
?>
<div class="blt-card-body">
	<div class="blt-stats">
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Rows', 'blt-toolkit' ); ?></span>
			<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( (int) $blt_tk_counts['total'] ) ); ?></span>
			<?php if ( ! empty( $plan['file'] ) ) : ?>
				<span class="blt-stat-sub"><?php echo esc_html( $plan['file'] ); ?></span>
			<?php endif; ?>
		</div>
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Ready', 'blt-toolkit' ); ?></span>
			<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( $blt_tk_ready ) ); ?></span>
		</div>
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Errors', 'blt-toolkit' ); ?></span>
			<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( (int) $blt_tk_counts[ BLT_Toolkit_Rename_Planner::ERROR ] ) ); ?></span>
			<span class="blt-stat-sub"><?php esc_html_e( 'Skipped', 'blt-toolkit' ); ?></span>
		</div>
		<div class="blt-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'No change', 'blt-toolkit' ); ?></span>
			<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( (int) $blt_tk_counts[ BLT_Toolkit_Rename_Planner::NO_CHANGE ] ) ); ?></span>
			<?php if ( ! empty( $plan['blank'] ) ) : ?>
				<span class="blt-stat-sub">
					<?php
					printf(
						/* translators: %s: number of rows. */
						esc_html( _n( 'Plus %s row with no new username, not listed', 'Plus %s rows with no new username, not listed', (int) $plan['blank'], 'blt-toolkit' ) ),
						esc_html( number_format_i18n( (int) $plan['blank'] ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	</div>
</div>

<nav class="blt-toolbar blt-toolkit-bulk-filter" aria-label="<?php esc_attr_e( 'Show rows', 'blt-toolkit' ); ?>">
	<?php foreach ( $blt_tk_tabs as $blt_tk_key => $blt_tk_tab ) : ?>
		<?php
		$blt_tk_label = sprintf(
			/* translators: 1: a row status such as Errors, 2: number of rows. */
			__( '%1$s (%2$s)', 'blt-toolkit' ),
			$blt_tk_tab[0],
			number_format_i18n( $blt_tk_tab[1] )
		);
		?>
		<?php if ( $blt_tk_key === $view['show'] ) : ?>
			<strong aria-current="page"><?php echo esc_html( $blt_tk_label ); ?></strong>
		<?php else : ?>
			<a href="<?php echo esc_url( BLT_Toolkit_Admin_Usernames::plan_url( $blt_tk_token, $blt_tk_key ) ); ?>"><?php echo esc_html( $blt_tk_label ); ?></a>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>

<div class="blt-card-body">
	<?php if ( array() === $view['rows'] ) : ?>
		<div class="blt-empty">
			<span class="blt-empty-title"><?php esc_html_e( 'No rows with this status', 'blt-toolkit' ); ?></span>
			<span><a href="<?php echo esc_url( BLT_Toolkit_Admin_Usernames::plan_url( $blt_tk_token ) ); ?>"><?php esc_html_e( 'Show every row', 'blt-toolkit' ); ?></a></span>
		</div>
	<?php else : ?>
		<div class="blt-toolkit-table-scroll">
			<table class="widefat striped blt-toolkit-bulk-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Dry run of the bulk rename', 'blt-toolkit' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Line', 'blt-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User ID', 'blt-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Change', 'blt-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'blt-toolkit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notes', 'blt-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $view['rows'] as $blt_tk_row ) : ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Line', 'blt-toolkit' ); ?>"><?php echo esc_html( number_format_i18n( (int) $blt_tk_row['line'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'User ID', 'blt-toolkit' ); ?>"><?php echo $blt_tk_row['user_id'] > 0 ? esc_html( (string) (int) $blt_tk_row['user_id'] ) : '&mdash;'; ?></td>
							<td class="blt-toolkit-bulk-change" data-label="<?php esc_attr_e( 'Change', 'blt-toolkit' ); ?>">
								<?php if ( '' !== $blt_tk_row['old'] ) : ?>
									<code><?php echo esc_html( $blt_tk_row['old'] ); ?></code>
								<?php endif; ?>
								<?php if ( '' !== $blt_tk_row['old'] && '' !== $blt_tk_row['new'] ) : ?>
									<span aria-hidden="true">&rarr;</span><span class="screen-reader-text"><?php esc_html_e( 'to', 'blt-toolkit' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $blt_tk_row['new'] ) : ?>
									<code><?php echo esc_html( $blt_tk_row['new'] ); ?></code>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Status', 'blt-toolkit' ); ?>"><?php echo BLT_Toolkit_Admin_Usernames::status_badge( $blt_tk_row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></td>
							<td data-label="<?php esc_attr_e( 'Notes', 'blt-toolkit' ); ?>">
								<?php if ( '' !== (string) $blt_tk_row['reason'] ) : ?>
									<?php echo esc_html( $blt_tk_row['reason'] ); ?>
								<?php endif; ?>
								<?php if ( ! empty( $blt_tk_row['warnings'] ) ) : ?>
									<ul class="blt-toolkit-bulk-warnings">
										<?php foreach ( (array) $blt_tk_row['warnings'] as $blt_tk_warning ) : ?>
											<li><?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Warning', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?> <?php echo esc_html( $blt_tk_warning ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $view['pages'] > 1 ) : ?>
			<nav class="blt-toolkit-bulk-pages blt-stack-top" aria-label="<?php esc_attr_e( 'Dry run pages', 'blt-toolkit' ); ?>">
				<?php if ( $view['paged'] > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin_Usernames::plan_url( $blt_tk_token, $view['show'], $view['paged'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'blt-toolkit' ); ?></a>
				<?php endif; ?>
				<span>
					<?php
					printf(
						/* translators: 1: current page, 2: number of pages. */
						esc_html__( 'Page %1$s of %2$s', 'blt-toolkit' ),
						esc_html( number_format_i18n( $view['paged'] ) ),
						esc_html( number_format_i18n( $view['pages'] ) )
					);
					?>
				</span>
				<?php if ( $view['paged'] < $view['pages'] ) : ?>
					<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin_Usernames::plan_url( $blt_tk_token, $view['show'], $view['paged'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'blt-toolkit' ); ?></a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>

<div class="blt-card-body">
	<?php if ( 0 === $blt_tk_ready ) : ?>
		<div class="blt-empty">
			<span class="blt-empty-title"><?php esc_html_e( 'Nothing to rename', 'blt-toolkit' ); ?></span>
			<span><?php esc_html_e( 'No row of this file is ready. Correct the rows with errors and upload the file again.', 'blt-toolkit' ); ?></span>
		</div>
	<?php else : ?>
		<?php if ( $view['self'] ) : ?>
			<div class="blt-callout">
				<strong><?php esc_html_e( 'Your own username is in this file', 'blt-toolkit' ); ?></strong>
				<span><?php esc_html_e( 'The renames run in the background, so when yours changes you are signed out. Sign in again with your new username.', 'blt-toolkit' ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( $view['switched'] > 0 ) : ?>
			<div class="blt-callout blt-stack-top">
				<strong><?php esc_html_e( 'Switched sessions will end', 'blt-toolkit' ); ?></strong>
				<span>
					<?php
					printf(
						/* translators: %s: number of users. */
						esc_html( _n( '%s user in this file is in a switched session right now. Renaming them ends that session; starting the bulk rename confirms it.', '%s users in this file are in a switched session right now. Renaming them ends those sessions; starting the bulk rename confirms it.', $view['switched'], 'blt-toolkit' ) ),
						esc_html( number_format_i18n( $view['switched'] ) )
					);
					?>
				</span>
			</div>
		<?php endif; ?>

		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'What each rename does', 'blt-toolkit' ); ?></div>
			<div>
				<ul class="blt-toolkit-bulk-settings">
					<li>
						<?php echo $settings['notify'] ? BLT_Toolkit_Admin::badge( 'on', __( 'On', 'blt-toolkit' ) ) : BLT_Toolkit_Admin::badge( 'off', __( 'Off', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
						<?php esc_html_e( 'Email each user their new username', 'blt-toolkit' ); ?>
					</li>
					<li>
						<?php echo $settings['sign_out_others'] ? BLT_Toolkit_Admin::badge( 'on', __( 'On', 'blt-toolkit' ) ) : BLT_Toolkit_Admin::badge( 'off', __( 'Off', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
						<?php esc_html_e( 'Sign each renamed user out everywhere', 'blt-toolkit' ); ?>
					</li>
					<li>
						<?php
						// JOB-3: the policy this dry run's "author URL will change" warnings used, which the run pins.
						$blt_tk_policy = isset( $plan['nicename_policy'] ) ? (string) $plan['nicename_policy'] : (string) BLT_Toolkit_Settings::get( 'usernames', 'nicename_policy' );

						if ( 'always' === $blt_tk_policy ) {
							esc_html_e( 'Author URLs change to match the new usernames.', 'blt-toolkit' );
						} elseif ( 'never' === $blt_tk_policy ) {
							esc_html_e( 'Author URLs stay as they are.', 'blt-toolkit' );
						} else {
							esc_html_e( 'Author URLs change only where they were made from the old username.', 'blt-toolkit' );
						}
						?>
					</li>
				</ul>
				<p class="blt-field-desc">
					<?php esc_html_e( 'These settings are fixed when the renames start, so changing them during the run does not split the batch. The author URL rule is the one this dry run used.', 'blt-toolkit' ); ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( BLT_Toolkit_Admin::page_url( 'settings', array( 'tab' => 'usernames' ) ) ); ?>"><?php esc_html_e( 'Change them in Settings', 'blt-toolkit' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-bulk-start">
			<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Usernames::START_ACTION ); ?>
			<input type="hidden" name="plan" value="<?php echo esc_attr( $blt_tk_token ); ?>" />

			<?php if ( $needs_ack ) : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Backup', 'blt-toolkit' ); ?></div>
					<div>
						<?php
						BLT_Toolkit_Admin::toggle(
							BLT_Toolkit_Admin_Usernames::ACK_FIELD,
							false,
							__( 'I have a recent backup of this site', 'blt-toolkit' ),
							__( 'A bulk rename changes many accounts at once. Each rename is logged, but there is no undo for the whole batch. You are asked once each time you sign in.', 'blt-toolkit' ),
							'blt-tk-backup-ack'
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( null !== $live ) : ?>
				<p class="blt-field-desc"><?php esc_html_e( 'Another bulk rename is still running. Start this one when it has finished.', 'blt-toolkit' ); ?></p>
			<?php endif; ?>

			<div class="blt-settings-footer">
				<button type="submit" class="button button-primary blt-save-button"<?php disabled( null !== $live ); ?>>
					<?php
					printf(
						/* translators: %s: number of users. */
						esc_html( _n( 'Rename %s user', 'Rename %s users', $blt_tk_ready, 'blt-toolkit' ) ),
						esc_html( number_format_i18n( $blt_tk_ready ) )
					);
					?>
				</button>
			</div>
		</form>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-bulk-discard blt-stack-top">
		<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Usernames::DISCARD_ACTION ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Discard and upload another file', 'blt-toolkit' ); ?></button>
	</form>
</div>
