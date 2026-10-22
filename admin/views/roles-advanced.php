<?php
/**
 * Roles screen, Advanced view for a role (SPEC 9.3): the toolbar, the
 * capability matrix and the sticky save bar (RO-20 to RO-22, RO-26), then
 * Add capability (RO-23).
 *
 * The matrix is a plain form. It carries the role's lock hash from render
 * time (RO-2) and the capabilities that were on the page as one JSON field
 * (RO-1): the server changes nothing outside that set. Rows the person may
 * not change (SEC-12 for the Administrator role, SEC-3 for capabilities they
 * lack, or a role they may not edit at all) are disabled and left out of the
 * set. The last field of the form tells the server it arrived whole.
 *
 * The save bar sits outside the matrix card, tied to the form with `form=`,
 * so it can stick to the bottom of the window.
 *
 * @package Blt_Toolkit
 *
 * @var array      $state   The request state.
 * @var array      $matrix  node, shared, sections (id, label, rows), leaf, rows, on_page, hash, read_only, reason, back, discard.
 * @var array|null $add_cap roles (ID => name), checked, back, max.
 * @var string     $name    Role display name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_form_id = 'blt-tk-matrix-form';
$blt_tk_cols    = max( 1, min( 3, (int) $state['cols'] ) );
?>
<div class="blt-toolkit-matrix-wrap" data-blt-matrix-wrap>
	<div class="blt-card blt-toolkit-matrix-card" id="blt-tk-matrix" tabindex="-1">
		<div class="blt-card-header">
			<h2><?php echo esc_html( 'all' === $matrix['node']['id'] ? __( 'All capabilities', 'blt-toolkit' ) : $matrix['node']['label'] ); ?></h2>
			<div class="blt-card-header-badges">
				<span class="blt-badge blt-badge-off" data-blt-shown>
					<?php
					printf(
						/* translators: %s: number of capabilities shown. */
						esc_html__( 'Shown: %s', 'blt-toolkit' ),
						esc_html( number_format_i18n( (int) $matrix['rows'] ) )
					);
					?>
				</span>
			</div>
			<p>
				<?php
				if ( $matrix['read_only'] ) {
					esc_html_e( 'You can see this role\'s capabilities but not change them.', 'blt-toolkit' );
				} else {
					printf(
						/* translators: %s: role name. */
						esc_html__( 'Switch a capability on to grant it to %s, or off to remove it. Only the capabilities listed here are saved; filtered-out ones stay as they are.', 'blt-toolkit' ),
						esc_html( $name )
					);
				}
				?>
			</p>
		</div>

		<?php BLT_Toolkit_Admin_Roles::partial( 'roles-toolbar', array( 'state' => $state ) ); ?>

		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( $blt_tk_form_id ); ?>" class="blt-toolkit-matrix-form" data-blt-matrix>
			<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_save' ); ?>
			<input type="hidden" name="role" value="<?php echo esc_attr( $state['role'] ); ?>" />
			<input type="hidden" name="hash" value="<?php echo esc_attr( $matrix['hash'] ); ?>" />
			<input type="hidden" name="on_page" value="<?php echo esc_attr( (string) wp_json_encode( array_values( $matrix['on_page'] ) ) ); ?>" />
			<input type="hidden" name="back" value="<?php echo esc_attr( $matrix['back'] ); ?>" />

			<div class="blt-card-body">
				<?php if ( '' !== $matrix['shared'] ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Shared capabilities', 'blt-toolkit' ); ?></strong>
						<span><?php echo esc_html( $matrix['shared'] ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( $matrix['read_only'] && '' !== $matrix['reason'] ) : ?>
					<div class="blt-callout">
						<strong><?php esc_html_e( 'Read only', 'blt-toolkit' ); ?></strong>
						<span><?php echo esc_html( $matrix['reason'] ); ?></span>
					</div>
				<?php endif; ?>

				<?php if ( 0 === (int) $matrix['rows'] ) : ?>
					<div class="blt-empty">
						<span class="blt-empty-title"><?php esc_html_e( 'No capability matches', 'blt-toolkit' ); ?></span>
						<span><?php esc_html_e( 'Change the quick filter, turn off Granted only, or choose another group.', 'blt-toolkit' ); ?></span>
					</div>
				<?php endif; ?>

				<p class="blt-toolkit-filter-status" data-blt-filter-status aria-live="polite"></p>

				<?php foreach ( $matrix['sections'] as $blt_tk_section ) : ?>
					<div class="blt-toolkit-matrix-section" data-blt-section>
						<?php if ( ! $matrix['leaf'] ) : ?>
							<h3 class="blt-toolkit-matrix-heading"><?php echo esc_html( $blt_tk_section['label'] ); ?></h3>
						<?php endif; ?>
						<ul class="blt-toolkit-matrix blt-toolkit-cols-<?php echo esc_attr( (string) $blt_tk_cols ); ?>">
							<?php foreach ( $blt_tk_section['rows'] as $blt_tk_row ) : ?>
								<li class="blt-toolkit-cap<?php echo $blt_tk_row['disabled'] ? ' is-locked' : ''; ?>" data-blt-cap data-search="<?php echo esc_attr( $blt_tk_row['search'] ); ?>">
									<label class="blt-toggle">
										<input type="checkbox" name="caps[]" value="<?php echo esc_attr( $blt_tk_row['cap'] ); ?>" id="<?php echo esc_attr( $blt_tk_row['id'] ); ?>" <?php checked( $blt_tk_row['checked'] ); ?> <?php disabled( $blt_tk_row['disabled'] ); ?> />
										<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
										<span class="blt-toggle-text">
											<span class="blt-toggle-label">
												<?php if ( $state['readable'] ) : ?>
													<?php echo esc_html( $blt_tk_row['label'] ); ?>
													<code class="blt-chip"><?php echo esc_html( $blt_tk_row['cap'] ); ?></code>
												<?php else : ?>
													<code class="blt-chip"><?php echo esc_html( $blt_tk_row['cap'] ); ?></code>
												<?php endif; ?>
											</span>
											<span class="blt-toggle-desc">
												<span class="blt-chip blt-toolkit-source"><?php echo esc_html( $blt_tk_row['source_label'] ); ?></span>
												<?php echo BLT_Toolkit_Admin_Roles::tier_badge( $blt_tk_row['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
												<?php if ( $blt_tk_row['denied'] ) : ?>
													<span class="blt-text-danger"><?php esc_html_e( 'Denied in this role, which overrides other roles', 'blt-toolkit' ); ?></span>
												<?php endif; ?>
												<?php if ( '' !== $blt_tk_row['also'] ) : ?>
													<span class="blt-toolkit-note">
														<?php
														/* translators: %s: comma-separated content type names, such as "Posts, Events". */
														printf( esc_html__( 'Also used by %s', 'blt-toolkit' ), esc_html( $blt_tk_row['also'] ) );
														?>
													</span>
												<?php endif; ?>
												<?php if ( '' !== $blt_tk_row['simple'] ) : ?>
													<span class="blt-toolkit-simple"><?php echo esc_html( $blt_tk_row['simple'] ); ?></span>
												<?php endif; ?>
												<?php if ( '' !== $blt_tk_row['note'] ) : ?>
													<span class="blt-toolkit-note"><?php echo esc_html( $blt_tk_row['note'] ); ?></span>
												<?php endif; ?>
												<?php if ( '' !== $blt_tk_row['locked'] ) : ?>
													<span class="blt-toolkit-note"><?php echo esc_html( $blt_tk_row['locked'] ); ?></span>
												<?php endif; ?>
											</span>
										</span>
									</label>
									<?php if ( '' !== $blt_tk_row['delete_url'] ) : ?>
										<a class="blt-toolkit-cap-action" href="<?php echo esc_url( $blt_tk_row['delete_url'] ); ?>">
											<?php
											printf(
												/* translators: %s: capability name. */
												esc_html__( 'Delete %s…', 'blt-toolkit' ),
												'<span class="screen-reader-text">' . esc_html( $blt_tk_row['cap'] ) . '</span>'
											);
											?>
										</a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</form>
	</div>

	<?php if ( ! $matrix['read_only'] && array() !== $matrix['on_page'] ) : ?>
		<div class="blt-toolkit-savebar" data-blt-savebar>
			<p class="blt-toolkit-change-count" data-blt-count aria-live="polite"><?php esc_html_e( 'Your changes are checked before they are saved.', 'blt-toolkit' ); ?></p>
			<span class="blt-toolkit-savebar-actions">
				<a class="button" href="<?php echo esc_url( $matrix['discard'] ); ?>" data-blt-discard><?php esc_html_e( 'Discard', 'blt-toolkit' ); ?></a>
				<button type="submit" class="button button-primary blt-save-button" form="<?php echo esc_attr( $blt_tk_form_id ); ?>"><?php esc_html_e( 'Review and save', 'blt-toolkit' ); ?></button>
			</span>
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::END_FIELD ); ?>" value="1" form="<?php echo esc_attr( $blt_tk_form_id ); ?>" />
		</div>
	<?php endif; ?>
</div>

<?php if ( null !== $add_cap && array() !== $add_cap['roles'] ) : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-add-cap">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_add_cap' ); ?>
		<input type="hidden" name="back" value="<?php echo esc_attr( $add_cap['back'] ); ?>" />
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Add a capability', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'For a plugin or custom code that checks a capability no role has yet. WordPress has no list of capabilities of its own: a capability exists once a role or a user holds it.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-tk-cap-name"><?php esc_html_e( 'Name', 'blt-toolkit' ); ?></label></div>
					<div>
						<input type="text" class="regular-text code" id="blt-tk-cap-name" name="cap_name" value="" maxlength="<?php echo esc_attr( (string) (int) $add_cap['max'] ); ?>" pattern="[a-z0-9_\-]*[a-z][a-z0-9_\-]*" autocomplete="off" spellcheck="false" required aria-describedby="blt-tk-cap-name-desc" />
						<p class="blt-field-desc" id="blt-tk-cap-name-desc">
							<?php
							printf(
								/* translators: %s: maximum number of characters. */
								esc_html__( 'Lowercase letters, digits, underscores and hyphens, with at least one letter; at most %s characters. It must not exist yet, and must not be a name WordPress checks per item (such as edit_post).', 'blt-toolkit' ),
								esc_html( number_format_i18n( (int) $add_cap['max'] ) )
							);
							?>
						</p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Roles that get it', 'blt-toolkit' ); ?></div>
					<div class="blt-toggle-stack">
						<?php foreach ( $add_cap['roles'] as $blt_tk_id => $blt_tk_role_name ) : ?>
							<label class="blt-toggle">
								<input type="checkbox" name="cap_roles[]" value="<?php echo esc_attr( (string) $blt_tk_id ); ?>" <?php checked( (string) $blt_tk_id === $add_cap['checked'] ); ?> />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text"><span class="blt-toggle-label"><?php echo esc_html( $blt_tk_role_name ); ?></span></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<div class="blt-settings-footer">
			<button type="submit" class="button"><?php esc_html_e( 'Add capability', 'blt-toolkit' ); ?></button>
		</div>
	</form>
<?php endif; ?>
