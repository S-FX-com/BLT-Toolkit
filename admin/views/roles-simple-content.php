<?php
/**
 * Roles screen, Simple view: the Content card's table (SPEC 10.2 to 10.4),
 * one row per capability group, labeled with every member type (RS-10).
 *
 * Each row is a level selector with the exact SPEC 10.3 help text of the
 * selected level, and the Can delete and Manage {taxonomy} modifiers. Those
 * are drawn for every level so the form works without JavaScript; the
 * script hides the ones that do not apply to the selected level and sets
 * each level's defaults when it is picked (and says so with `mods_set`).
 * Rows that cannot have levels say why instead (RS-11, RS-12, RS-33). A
 * shared group offers "Give {Type} separate permissions" for each member
 * that can have them (RS-30).
 *
 * @package Blt_Toolkit
 *
 * @var array[] $rows Content rows from BLT_Toolkit_Admin_Roles::simple_level_view().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( array() === $rows ) : ?>
	<div class="blt-empty">
		<span class="blt-empty-title"><?php esc_html_e( 'No content types', 'blt-toolkit' ); ?></span>
		<span><?php esc_html_e( 'This site shows no content types in the dashboard.', 'blt-toolkit' ); ?></span>
	</div>
<?php else : ?>
	<table class="widefat blt-toolkit-simple-content">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Content type', 'blt-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Access', 'blt-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $blt_tk_row ) : ?>
				<?php
				$blt_tk_select = $blt_tk_row['dom'] . '-level';
				$blt_tk_help   = $blt_tk_row['dom'] . '-help';
				$blt_tk_field  = 'simple[' . $blt_tk_row['id'] . ']';
				?>
				<tr class="blt-toolkit-simple-row" id="<?php echo esc_attr( $blt_tk_row['dom'] ); ?>" tabindex="-1" data-blt-control="<?php echo esc_attr( $blt_tk_row['id'] ); ?>" data-kind="level">
					<th scope="row">
						<span class="blt-toolkit-simple-label"><?php echo esc_html( $blt_tk_row['label'] ); ?></span>
						<span class="blt-toolkit-simple-badges">
							<?php echo BLT_Toolkit_Admin_Roles::tier_badge( $blt_tk_row['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
							<?php foreach ( $blt_tk_row['badges'] as $blt_tk_badge ) : ?>
								<?php if ( isset( $blt_tk_badge[2] ) ) : ?>
									<?php // Shown only at one level (RS-13's "Enforced by BLT Toolkit" at View). ?>
									<span class="blt-toolkit-simple-level-badge<?php echo $blt_tk_badge[2] === $blt_tk_row['level'] ? '' : ' is-off'; ?>" data-blt-at-level="<?php echo esc_attr( $blt_tk_badge[2] ); ?>"><?php echo BLT_Toolkit_Admin::badge( $blt_tk_badge[0], $blt_tk_badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></span>
								<?php else : ?>
									<?php echo BLT_Toolkit_Admin::badge( $blt_tk_badge[0], $blt_tk_badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</span>
						<?php if ( array() !== $blt_tk_row['also'] ) : ?>
							<span class="blt-toolkit-note">
								<?php
								/* translators: %s: comma-separated content type names, such as "Posts, Events". */
								printf( esc_html__( 'Some of these permissions are also used by %s, so a change here changes them too.', 'blt-toolkit' ), esc_html( implode( ', ', $blt_tk_row['also'] ) ) );
								?>
							</span>
						<?php endif; ?>
						<?php if ( array() !== $blt_tk_row['isolate'] || '' !== $blt_tk_row['revert'] ) : ?>
							<span class="blt-toolkit-simple-actions">
								<?php foreach ( $blt_tk_row['isolate'] as $blt_tk_link ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $blt_tk_link['url'] ); ?>"><?php echo esc_html( $blt_tk_link['label'] ); ?></a>
								<?php endforeach; ?>
								<?php if ( '' !== $blt_tk_row['revert'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $blt_tk_row['revert'] ); ?>"><?php esc_html_e( 'Share permissions again…', 'blt-toolkit' ); ?></a>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</th>
					<td>
						<?php if ( '' !== $blt_tk_row['readonly'] ) : ?>
							<p class="blt-toolkit-simple-status"><?php echo esc_html( $blt_tk_row['status'] ); ?></p>
							<?php if ( ! empty( $blt_tk_row['controlled_by'] ) ) : ?>
								<p class="blt-toolkit-simple-status">
									<?php esc_html_e( 'Controlled by:', 'blt-toolkit' ); ?>
									<?php foreach ( $blt_tk_row['controlled_by'] as $blt_tk_index => $blt_tk_owner ) : ?>
										<?php echo $blt_tk_index > 0 ? ', ' : ''; ?><a href="<?php echo esc_attr( '#' . $blt_tk_owner['dom'] ); ?>"><?php echo esc_html( $blt_tk_owner['label'] ); ?></a>
									<?php endforeach; ?>
								</p>
							<?php endif; ?>
							<p class="blt-toolkit-note">
								<?php
								printf(
									/* translators: 1: number of capabilities granted, 2: number of capabilities in the row. */
									esc_html__( 'This role has %1$s of its %2$s capabilities.', 'blt-toolkit' ),
									esc_html( number_format_i18n( (int) $blt_tk_row['granted'] ) ),
									esc_html( number_format_i18n( (int) $blt_tk_row['total'] ) )
								);
								?>
							</p>
						<?php else : ?>
							<div class="blt-toolkit-simple-level">
								<label for="<?php echo esc_attr( $blt_tk_select ); ?>">
									<?php
									/* translators: %s: content type names, such as "Posts (also used by: Events)". */
									echo wp_kses( sprintf( __( 'Access level<span class="screen-reader-text"> for %s</span>', 'blt-toolkit' ), esc_html( $blt_tk_row['label'] ) ), array( 'span' => array( 'class' => true ) ) );
									?>
								</label>
								<select id="<?php echo esc_attr( $blt_tk_select ); ?>" name="<?php echo esc_attr( $blt_tk_field . '[level]' ); ?>" aria-describedby="<?php echo esc_attr( $blt_tk_help ); ?>" data-blt-level <?php disabled( $blt_tk_row['disabled'] ); ?>>
									<?php foreach ( $blt_tk_row['options'] as $blt_tk_level => $blt_tk_option ) : ?>
										<option value="<?php echo esc_attr( $blt_tk_level ); ?>" data-help="<?php echo esc_attr( $blt_tk_option['help'] ); ?>" <?php selected( $blt_tk_level, $blt_tk_row['level'] ); ?> <?php disabled( $blt_tk_option['disabled'] ); ?>>
											<?php
											echo esc_html( $blt_tk_option['label'] );

											if ( $blt_tk_option['disabled'] ) {
												echo ' ' . esc_html__( '(needs permissions you do not have)', 'blt-toolkit' );
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<p class="blt-field-desc blt-toolkit-simple-help" id="<?php echo esc_attr( $blt_tk_help ); ?>" data-blt-level-help><?php echo esc_html( $blt_tk_row['help_text'] ); ?></p>

							<?php if ( ! $blt_tk_row['disabled'] ) : ?>
								<?php // Sent only by the script, which sets each level's default modifiers itself. ?>
								<input type="hidden" name="<?php echo esc_attr( $blt_tk_field . '[mods_set]' ); ?>" value="1" disabled data-blt-mods-set />
							<?php endif; ?>

							<div class="blt-toolkit-simple-mods">
								<?php foreach ( $blt_tk_row['mods'] as $blt_tk_mod ) : ?>
									<div class="blt-toolkit-simple-mod<?php echo $blt_tk_mod['shown'] ? '' : ' is-off'; ?>" data-blt-mod="<?php echo esc_attr( $blt_tk_mod['key'] ); ?>" data-tax="<?php echo esc_attr( isset( $blt_tk_mod['tax'] ) ? $blt_tk_mod['tax'] : '' ); ?>" data-levels="<?php echo esc_attr( implode( ' ', $blt_tk_mod['levels'] ) ); ?>">
										<?php if ( ! $blt_tk_mod['disabled'] ) : ?>
											<input type="hidden" name="<?php echo esc_attr( $blt_tk_mod['name'] ); ?>" value="0" />
										<?php endif; ?>
										<label class="blt-toggle">
											<input type="checkbox" name="<?php echo esc_attr( $blt_tk_mod['name'] ); ?>" value="1" id="<?php echo esc_attr( $blt_tk_mod['dom'] ); ?>" <?php checked( $blt_tk_mod['checked'] ); ?> <?php disabled( $blt_tk_mod['disabled'] ); ?> />
											<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
											<span class="blt-toggle-text">
												<span class="blt-toggle-label"><?php echo esc_html( $blt_tk_mod['label'] ); ?></span>
												<span class="blt-toggle-desc"><?php echo esc_html( $blt_tk_mod['desc'] ); ?></span>
											</span>
										</label>
									</div>
								<?php endforeach; ?>
							</div>

							<?php
							BLT_Toolkit_Admin_Roles::partial(
								'roles-simple-hints',
								array(
									'hints' => $blt_tk_row['hints'],
								)
							);
							?>

							<details class="blt-toolkit-simple-levels">
								<summary><?php esc_html_e( 'What each level means', 'blt-toolkit' ); ?></summary>
								<dl>
									<?php foreach ( $blt_tk_row['options'] as $blt_tk_level => $blt_tk_option ) : ?>
										<dt><?php echo esc_html( $blt_tk_option['label'] ); ?></dt>
										<dd><?php echo esc_html( $blt_tk_option['help'] ); ?></dd>
									<?php endforeach; ?>
								</dl>
							</details>
						<?php endif; ?>

						<?php if ( '' !== $blt_tk_row['advanced_url'] ) : ?>
							<a class="blt-toolkit-simple-advanced" href="<?php echo esc_url( $blt_tk_row['advanced_url'] ); ?>">
								<?php
								/* translators: %s: content type names. */
								echo wp_kses( sprintf( __( 'Edit in Advanced<span class="screen-reader-text">: %s</span>', 'blt-toolkit' ), esc_html( $blt_tk_row['label'] ) ), array( 'span' => array( 'class' => true ) ) );
								?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
