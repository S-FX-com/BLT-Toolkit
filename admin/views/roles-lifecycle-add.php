<?php
/**
 * Roles > Add role (RO-30), and its Critical review step (SEC-10).
 *
 * Included by BLT_Toolkit_Admin_Role_Lifecycle::render() inside the
 * screen's `.wrap`. Writes nothing; the forms post to admin-post.php.
 *
 * @package Blt_Toolkit
 *
 * @var array<string,string> $values   `name`, `role_id`, `from` to prefill.
 * @var array                $options  `templates` and `copies`, each a list of array( 'key', 'label', 'count', 'critical' ).
 * @var bool                 $filtered Whether some starting points were left out because the person lacks their capabilities (SEC-3).
 * @var array|null           $review   The SEC-10 review (name, role_id, from, from_label, critical, total), or null for the first step.
 * @var string               $view_arg Role editor view to open the new role in (`simple`, `advanced` or '').
 * @var string               $back_url The role list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_action = 'blt_toolkit_role_add';
$blt_tk_badges = BLT_Toolkit_Admin_Role_Lifecycle::tier_badges();
?>
<?php if ( null !== $review ) : ?>
	<?php
	$blt_tk_confirm_id = 'blt-toolkit-role-confirm';
	$blt_tk_edit_url   = BLT_Toolkit_Admin_Role_Lifecycle::url(
		'add',
		array_filter(
			array(
				'name'                                     => $review['name'],
				'role_id'                                  => $review['role_id'],
				'from'                                     => $review['from'],
				BLT_Toolkit_Admin_Role_Lifecycle::VIEW_ARG => $view_arg,
			)
		)
	);
	?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( $blt_tk_action ); ?>
		<input type="hidden" name="name" value="<?php echo esc_attr( $review['name'] ); ?>" />
		<input type="hidden" name="role_id" value="<?php echo esc_attr( $review['role_id'] ); ?>" />
		<input type="hidden" name="from" value="<?php echo esc_attr( $review['from'] ); ?>" />
		<?php if ( '' !== $view_arg ) : ?>
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Role_Lifecycle::VIEW_ARG ); ?>" value="<?php echo esc_attr( $view_arg ); ?>" />
		<?php endif; ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Review the new role', 'blt-toolkit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: role name, 2: role ID, 3: what it starts from, 4: number of capabilities. */
						esc_html( _n( 'The %1$s role (%2$s) will start from: %3$s, with %4$s capability.', 'The %1$s role (%2$s) will start from: %3$s, with %4$s capabilities.', (int) $review['total'], 'blt-toolkit' ) ),
						'<strong>' . esc_html( $review['name'] ) . '</strong>',
						'<code>' . esc_html( $review['role_id'] ) . '</code>',
						esc_html( $review['from_label'] ),
						esc_html( number_format_i18n( (int) $review['total'] ) )
					);
					?>
				</p>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin::badge( $blt_tk_badges['critical'][0], $blt_tk_badges['critical'][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Critical capabilities', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts: change settings, run code, or edit and sign in as other users. Everyone you give this role to gets them.', 'blt-toolkit' ); ?></span>
				</div>
				<?php foreach ( $review['critical'] as $blt_tk_cap ) : ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<?php echo esc_html( $blt_tk_cap['label'] ); ?>
							<?php echo BLT_Toolkit_Admin::badge( $blt_tk_badges['critical'][0], $blt_tk_badges['critical'][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
						</div>
						<div><code><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code></div>
					</div>
				<?php endforeach; ?>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="<?php echo esc_attr( $blt_tk_confirm_id ); ?>"><?php esc_html_e( 'Type the role name to confirm', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $blt_tk_confirm_id ); ?>" name="<?php echo esc_attr( BLT_Toolkit_Admin_Role_Lifecycle::CONFIRM_FIELD ); ?>" value="" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $blt_tk_confirm_id ); ?>-desc" />
						<p class="blt-field-desc" id="<?php echo esc_attr( $blt_tk_confirm_id ); ?>-desc">
							<?php
							printf(
								/* translators: %s: role name. */
								esc_html__( 'Type %s exactly as shown.', 'blt-toolkit' ),
								'<strong>' . esc_html( $review['name'] ) . '</strong>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add role', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $blt_tk_edit_url ); ?>"><?php esc_html_e( 'Change something', 'blt-toolkit' ); ?></a>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( $blt_tk_action ); ?>
		<?php if ( '' !== $view_arg ) : ?>
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Role_Lifecycle::VIEW_ARG ); ?>" value="<?php echo esc_attr( $view_arg ); ?>" />
		<?php endif; ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Add a role', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Nobody has the new role until you give it to them. You can change what it allows right after adding it.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-toolkit-role-name"><?php esc_html_e( 'Display name', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text" id="blt-toolkit-role-name" name="name" value="<?php echo esc_attr( $values['name'] ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Role_Lifecycle::NAME_MAX ); ?>" required aria-describedby="blt-toolkit-role-name-desc" />
						<p class="blt-field-desc" id="blt-toolkit-role-name-desc"><?php esc_html_e( 'What people see in role menus, such as Shop Manager. It can be changed later.', 'blt-toolkit' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-toolkit-role-id"><?php esc_html_e( 'Role ID', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text code" id="blt-toolkit-role-id" name="role_id" value="<?php echo esc_attr( $values['role_id'] ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Role_Lifecycle::ID_MAX ); ?>" pattern="[a-z0-9_\-]*[a-z][a-z0-9_\-]*" autocomplete="off" spellcheck="false" aria-describedby="blt-toolkit-role-id-desc" />
						<p class="blt-field-desc" id="blt-toolkit-role-id-desc">
							<?php
							printf(
								/* translators: 1: maximum length, 2: example display name, 3: example role ID. */
								esc_html__( 'Leave empty to make it from the display name (%2$s becomes %3$s). Lowercase letters, digits, underscores and hyphens, up to %1$s characters. It cannot be changed later; "Move users to a new role" is the way to change it.', 'blt-toolkit' ),
								esc_html( number_format_i18n( BLT_Toolkit_Role_Lifecycle::ID_MAX ) ),
								esc_html__( 'Shop Manager', 'blt-toolkit' ),
								'<code>shop_manager</code>'
							);
							?>
						</p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-toolkit-role-from"><?php esc_html_e( 'Start from', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<select id="blt-toolkit-role-from" name="from" aria-describedby="blt-toolkit-role-from-desc">
							<optgroup label="<?php esc_attr_e( 'A starting point', 'blt-toolkit' ); ?>">
								<?php foreach ( $options['templates'] as $blt_tk_option ) : ?>
									<option value="<?php echo esc_attr( $blt_tk_option['key'] ); ?>"<?php selected( $values['from'], $blt_tk_option['key'] ); ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: starting point, 2: number of capabilities. */
												_n( '%1$s, %2$s capability', '%1$s, %2$s capabilities', $blt_tk_option['count'], 'blt-toolkit' ),
												$blt_tk_option['label'],
												number_format_i18n( $blt_tk_option['count'] )
											) . ( $blt_tk_option['critical'] ? ' ' . __( '(includes Critical)', 'blt-toolkit' ) : '' )
										);
										?>
									</option>
								<?php endforeach; ?>
							</optgroup>
							<?php if ( array() !== $options['copies'] ) : ?>
								<optgroup label="<?php esc_attr_e( 'A copy of a role on this site', 'blt-toolkit' ); ?>">
									<?php foreach ( $options['copies'] as $blt_tk_option ) : ?>
										<option value="<?php echo esc_attr( $blt_tk_option['key'] ); ?>"<?php selected( $values['from'], $blt_tk_option['key'] ); ?>>
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: role name, 2: number of capabilities. */
													_n( 'Copy of %1$s, %2$s capability', 'Copy of %1$s, %2$s capabilities', $blt_tk_option['count'], 'blt-toolkit' ),
													$blt_tk_option['label'],
													number_format_i18n( $blt_tk_option['count'] )
												) . ( $blt_tk_option['critical'] ? ' ' . __( '(includes Critical)', 'blt-toolkit' ) : '' )
											);
											?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
						</select>
						<p class="blt-field-desc" id="blt-toolkit-role-from-desc">
							<?php esc_html_e( 'The starting points give WordPress\'s default capabilities for that role, whatever this site\'s own role has now; a copy takes the role exactly as it is here. A role with Critical capabilities asks you to confirm on the next step.', 'blt-toolkit' ); ?>
							<?php if ( $filtered ) : ?>
								<?php esc_html_e( 'Only roles whose capabilities you have yourself are listed.', 'blt-toolkit' ); ?>
							<?php endif; ?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Add role', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php endif; ?>
