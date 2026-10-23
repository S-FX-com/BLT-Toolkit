<?php
/**
 * Roles screen in user mode (US-10): the Advanced matrix for one user, with
 * Inherit, Grant or Deny per capability and where each comes from. Saved
 * through BLT_Toolkit_User_Caps, which writes the user's capabilities meta
 * once and keeps their role keys.
 *
 * Like the role matrix, the form carries the lock hash from render time and
 * the capabilities that were on the page (RO-1, RO-2).
 *
 * @package Blt_Toolkit
 *
 * @var array    $state     The request state.
 * @var WP_User  $user      The user.
 * @var string   $user_ref  "Display Name (login)".
 * @var array[]  $roles     The user's roles: id, name, url.
 * @var array[]  $role_list The role list (left column).
 * @var string   $add_url   Add role link, or ''.
 * @var array[]  $tree      Group tree.
 * @var array    $node      Current group.
 * @var array[]  $sections  Sections with rows (state, from: where the state comes from, effective, disabled).
 * @var bool     $leaf      Whether the group is one section.
 * @var int      $rows      Rows shown.
 * @var string[] $on_page   Capabilities on the form.
 * @var string   $hash      Lock hash.
 * @var bool     $read_only Whether nothing can be changed here.
 * @var string   $reason    Why, or ''.
 * @var string   $back      The editor's query string.
 * @var string   $discard   This page's URL.
 * @var string   $profile   The user's profile URL, or ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_form_id = 'blt-tk-user-caps-form';
$blt_tk_cols    = max( 1, min( 3, (int) $state['cols'] ) );
$blt_tk_options = array(
	'inherit' => __( 'Inherit', 'blt-toolkit' ),
	'grant'   => __( 'Grant', 'blt-toolkit' ),
	'deny'    => __( 'Deny', 'blt-toolkit' ),
);
?>
<?php // WCAG 2.4.1: the role list and group tree come first; skip past them. ?>
<a class="blt-toolkit-skip" href="#blt-tk-matrix"><?php esc_html_e( 'Skip to the capabilities', 'blt-toolkit' ); ?></a>
<div class="blt-toolkit-roles-layout">
	<?php
	BLT_Toolkit_Admin_Roles::partial(
		'roles-sidebar',
		array(
			'roles'   => $role_list,
			'add_url' => $add_url,
			'tree'    => $tree,
		)
	);
	?>

	<div class="blt-toolkit-roles-main">
		<div class="blt-card blt-toolkit-role-header">
			<div class="blt-card-header">
				<h2>
					<?php
					printf(
						/* translators: %s: user's display name and username. */
						esc_html__( 'Capabilities of %s', 'blt-toolkit' ),
						esc_html( $user_ref )
					);
					?>
				</h2>
				<?php if ( '' !== $profile ) : ?>
					<div class="blt-card-header-badges">
						<a href="<?php echo esc_url( $profile ); ?>"><?php esc_html_e( 'Profile', 'blt-toolkit' ); ?></a>
					</div>
				<?php endif; ?>
				<p><?php esc_html_e( 'Inherit uses what the user\'s roles give. Grant or Deny sets a capability for this user alone; Deny wins over every role.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<p class="blt-toolkit-user-roles">
					<strong><?php esc_html_e( 'Roles:', 'blt-toolkit' ); ?></strong>
					<?php if ( array() === $roles ) : ?>
						<?php esc_html_e( 'None', 'blt-toolkit' ); ?>
					<?php endif; ?>
					<?php foreach ( $roles as $blt_tk_role ) : ?>
						<a class="blt-chip" href="<?php echo esc_url( $blt_tk_role['url'] ); ?>"><?php echo esc_html( $blt_tk_role['name'] ); ?></a>
					<?php endforeach; ?>
				</p>
			</div>
		</div>

		<div class="blt-toolkit-matrix-wrap" data-blt-matrix-wrap>
			<div class="blt-card blt-toolkit-matrix-card" id="blt-tk-matrix" tabindex="-1">
				<div class="blt-card-header">
					<h2><?php echo esc_html( 'all' === $node['id'] ? __( 'All capabilities', 'blt-toolkit' ) : $node['label'] ); ?></h2>
					<div class="blt-card-header-badges">
						<span class="blt-badge blt-badge-off" data-blt-shown>
							<?php
							printf(
								/* translators: %s: number of capabilities shown. */
								esc_html__( 'Shown: %s', 'blt-toolkit' ),
								esc_html( number_format_i18n( (int) $rows ) )
							);
							?>
						</span>
					</div>
				</div>

				<?php BLT_Toolkit_Admin_Roles::partial( 'roles-toolbar', array( 'state' => $state ) ); ?>

				<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( $blt_tk_form_id ); ?>" class="blt-toolkit-matrix-form" data-blt-matrix>
					<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_user_caps' ); ?>
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) (int) $user->ID ); ?>" />
					<input type="hidden" name="hash" value="<?php echo esc_attr( $hash ); ?>" />
					<input type="hidden" name="on_page" value="<?php echo esc_attr( (string) wp_json_encode( array_values( $on_page ) ) ); ?>" />
					<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />

					<div class="blt-card-body">
						<?php if ( $read_only && '' !== $reason ) : ?>
							<div class="blt-callout">
								<strong><?php esc_html_e( 'Read only', 'blt-toolkit' ); ?></strong>
								<span><?php echo esc_html( $reason ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( 0 === (int) $rows ) : ?>
							<div class="blt-empty">
								<span class="blt-empty-title"><?php esc_html_e( 'No capability matches', 'blt-toolkit' ); ?></span>
								<span><?php esc_html_e( 'Change the quick filter, turn off Granted only, or choose another group.', 'blt-toolkit' ); ?></span>
							</div>
						<?php endif; ?>

						<p class="blt-toolkit-filter-status" data-blt-filter-status aria-live="polite"></p>

						<?php foreach ( $sections as $blt_tk_section ) : ?>
							<div class="blt-toolkit-matrix-section" data-blt-section>
								<?php if ( ! $leaf ) : ?>
									<h3 class="blt-toolkit-matrix-heading"><?php echo esc_html( $blt_tk_section['label'] ); ?></h3>
								<?php endif; ?>
								<ul class="blt-toolkit-matrix blt-toolkit-cols-<?php echo esc_attr( (string) $blt_tk_cols ); ?>">
									<?php foreach ( $blt_tk_section['rows'] as $blt_tk_row ) : ?>
										<li class="blt-toolkit-cap blt-toolkit-cap-user<?php echo $blt_tk_row['disabled'] ? ' is-locked' : ''; ?>" data-blt-cap data-search="<?php echo esc_attr( $blt_tk_row['search'] ); ?>" data-granted="<?php echo $blt_tk_row['effective'] ? '1' : '0'; ?>">
											<span class="blt-toolkit-cap-text">
												<label class="blt-toolkit-cap-label" for="<?php echo esc_attr( $blt_tk_row['id'] ); ?>">
													<?php if ( $state['readable'] ) : ?>
														<?php echo esc_html( $blt_tk_row['label'] ); ?>
														<code class="blt-chip"><?php echo esc_html( $blt_tk_row['cap'] ); ?></code>
													<?php else : ?>
														<code class="blt-chip"><?php echo esc_html( $blt_tk_row['cap'] ); ?></code>
													<?php endif; ?>
												</label>
												<span class="blt-toolkit-cap-meta" id="<?php echo esc_attr( $blt_tk_row['id'] ); ?>-source">
													<span class="blt-chip blt-toolkit-source"><?php echo esc_html( $blt_tk_row['source_label'] ); ?></span>
													<?php echo BLT_Toolkit_Admin_Roles::tier_badge( $blt_tk_row['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
													<span class="<?php echo 'deny' === $blt_tk_row['state'] ? 'blt-text-danger' : 'blt-toolkit-note'; ?>"><?php echo esc_html( $blt_tk_row['from'] ); ?></span>
												</span>
											</span>
											<select id="<?php echo esc_attr( $blt_tk_row['id'] ); ?>" name="state[<?php echo esc_attr( $blt_tk_row['cap'] ); ?>]" aria-describedby="<?php echo esc_attr( $blt_tk_row['id'] ); ?>-source" <?php disabled( $blt_tk_row['disabled'] ); ?>>
												<?php foreach ( $blt_tk_options as $blt_tk_value => $blt_tk_label ) : ?>
													<option value="<?php echo esc_attr( $blt_tk_value ); ?>" <?php selected( $blt_tk_row['state'], $blt_tk_value ); ?>><?php echo esc_html( $blt_tk_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endforeach; ?>
					</div>
				</form>
			</div>

			<?php if ( ! $read_only && array() !== $on_page ) : ?>
				<div class="blt-toolkit-savebar" data-blt-savebar>
					<p class="blt-toolkit-change-count" data-blt-count aria-live="polite"><?php esc_html_e( 'Your changes are checked before they are saved.', 'blt-toolkit' ); ?></p>
					<span class="blt-toolkit-savebar-actions">
						<a class="button" href="<?php echo esc_url( $discard ); ?>" data-blt-discard><?php esc_html_e( 'Discard', 'blt-toolkit' ); ?></a>
						<button type="submit" class="button button-primary blt-save-button" form="<?php echo esc_attr( $blt_tk_form_id ); ?>"><?php esc_html_e( 'Review and save', 'blt-toolkit' ); ?></button>
					</span>
					<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::END_FIELD ); ?>" value="1" form="<?php echo esc_attr( $blt_tk_form_id ); ?>" />
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>
