<?php
/**
 * Tools > Snapshots: every stored copy of the roles, newest first (SNAP-4).
 *
 * Each row says when it was taken (site timezone), by whom, why, and how
 * many roles and capabilities differ from the roles today, with links to
 * view what changed since and to preview a restore. Reading this page
 * changes nothing (RO-5).
 *
 * Included by BLT_Toolkit_Admin_Tools inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var array[] $rows      BLT_Toolkit_Snapshots::list() rows plus `when`, `ago`, `who`, `view_url`, `restore_url`.
 * @var int     $total     Snapshots stored.
 * @var int     $paged     Page shown.
 * @var int     $pages     Pages.
 * @var string  $newer_url Link to the newer page, or ''.
 * @var string  $older_url Link to the older page, or ''.
 * @var int     $retention Snapshots kept besides the baseline.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Role snapshots', 'blt-toolkit' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: number of snapshots kept. */
				esc_html__( 'BLT Toolkit saves a copy of every role before each change it makes, and kept the roles as they were when it was first activated (the baseline). The newest %s copies are kept, plus the baseline. Any of them can be restored after a preview.', 'blt-toolkit' ),
				esc_html( number_format_i18n( (int) $retention ) )
			);
			?>
		</p>
	</div>
	<div class="blt-card-body">
		<?php if ( array() === $rows ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'No snapshots yet', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'A snapshot is saved before BLT Toolkit changes any role.', 'blt-toolkit' ); ?></span>
			</div>
		<?php else : ?>
			<div class="blt-toolkit-table-scroll">
				<table class="widefat striped blt-toolkit-snapshots">
					<caption class="screen-reader-text">
						<?php
						printf(
							/* translators: 1: page number, 2: number of pages. */
							esc_html__( 'Role snapshots, page %1$s of %2$s', 'blt-toolkit' ),
							esc_html( number_format_i18n( (int) $paged ) ),
							esc_html( number_format_i18n( (int) $pages ) )
						);
						?>
					</caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Snapshot', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Taken', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'By', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Why', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Differs from now', 'blt-toolkit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'blt-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $blt_tk_row ) : ?>
							<tr>
								<th scope="row">
									<?php
									/* translators: %d: snapshot number. */
									printf( esc_html__( 'Snapshot %d', 'blt-toolkit' ), (int) $blt_tk_row['id'] );
									?>
									<?php if ( $blt_tk_row['is_baseline'] ) : ?>
										<?php echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Baseline', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
									<?php endif; ?>
								</th>
								<td data-label="<?php esc_attr_e( 'Taken', 'blt-toolkit' ); ?>">
									<?php echo esc_html( $blt_tk_row['when'] ); ?>
									<?php if ( '' !== $blt_tk_row['ago'] ) : ?>
										<br /><span class="blt-toolkit-muted"><?php echo esc_html( $blt_tk_row['ago'] ); ?></span>
									<?php endif; ?>
								</td>
								<td data-label="<?php esc_attr_e( 'By', 'blt-toolkit' ); ?>"><?php echo esc_html( $blt_tk_row['who'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Why', 'blt-toolkit' ); ?>"><?php echo esc_html( $blt_tk_row['reason_label'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Differs from now', 'blt-toolkit' ); ?>">
									<?php if ( ! $blt_tk_row['valid'] || null === $blt_tk_row['counts'] ) : ?>
										<?php echo BLT_Toolkit_Admin::badge( 'cancelled', __( 'Damaged', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
									<?php elseif ( $blt_tk_row['is_current'] || ( 0 === (int) $blt_tk_row['counts']['roles'] && 0 === (int) $blt_tk_row['counts']['capabilities'] ) ) : ?>
										<?php echo BLT_Toolkit_Admin::badge( 'on', __( 'Same as now', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
									<?php else : ?>
										<?php
										printf(
											/* translators: 1: a number of roles, such as 2 roles, 2: a number of capabilities, such as 14 capabilities. */
											esc_html__( '%1$s, %2$s', 'blt-toolkit' ),
											/* translators: %s: number of roles. */
											esc_html( sprintf( _n( '%s role', '%s roles', (int) $blt_tk_row['counts']['roles'], 'blt-toolkit' ), number_format_i18n( (int) $blt_tk_row['counts']['roles'] ) ) ),
											/* translators: %s: number of capabilities. */
											esc_html( sprintf( _n( '%s capability', '%s capabilities', (int) $blt_tk_row['counts']['capabilities'], 'blt-toolkit' ), number_format_i18n( (int) $blt_tk_row['counts']['capabilities'] ) ) )
										);
										?>
									<?php endif; ?>
								</td>
								<td class="blt-toolkit-snapshot-actions">
									<a href="<?php echo esc_url( $blt_tk_row['view_url'] ); ?>">
										<?php
										echo wp_kses(
											sprintf(
												/* translators: %d: snapshot number. */
												__( 'View changes<span class="screen-reader-text"> since snapshot %d</span>', 'blt-toolkit' ),
												(int) $blt_tk_row['id']
											),
											array( 'span' => array( 'class' => array() ) )
										);
										?>
									</a>
									<?php if ( $blt_tk_row['valid'] && null !== $blt_tk_row['counts'] && ! $blt_tk_row['is_current'] && (int) $blt_tk_row['counts']['roles'] + (int) $blt_tk_row['counts']['capabilities'] > 0 ) : ?>
										<a href="<?php echo esc_url( $blt_tk_row['restore_url'] ); ?>">
											<?php
											echo wp_kses(
												sprintf(
													/* translators: %d: snapshot number. */
													__( 'Restore<span class="screen-reader-text"> snapshot %d</span>…', 'blt-toolkit' ),
													(int) $blt_tk_row['id']
												),
												array( 'span' => array( 'class' => array() ) )
											);
											?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<nav class="blt-toolkit-pager" aria-label="<?php esc_attr_e( 'Snapshot pages', 'blt-toolkit' ); ?>">
					<?php if ( '' !== $newer_url ) : ?>
						<a class="button" href="<?php echo esc_url( $newer_url ); ?>"><?php esc_html_e( 'Newer snapshots', 'blt-toolkit' ); ?></a>
					<?php endif; ?>
					<span class="blt-toolkit-muted">
						<?php
						printf(
							/* translators: 1: page number, 2: number of pages, 3: number of snapshots. */
							esc_html__( 'Page %1$s of %2$s (%3$s snapshots)', 'blt-toolkit' ),
							esc_html( number_format_i18n( (int) $paged ) ),
							esc_html( number_format_i18n( (int) $pages ) ),
							esc_html( number_format_i18n( (int) $total ) )
						);
						?>
					</span>
					<?php if ( '' !== $older_url ) : ?>
						<a class="button" href="<?php echo esc_url( $older_url ); ?>"><?php esc_html_e( 'Older snapshots', 'blt-toolkit' ); ?></a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
