<?php
/**
 * Roles > Move users to a new role (RO-32): how a role's ID is changed.
 *
 * Creates the new role as a copy, moves every holder to it with the
 * `role_reassign` job (RO-41), then offers to delete the old role. Shows the
 * form with a dry run, the SEC-10 review when the copy has Critical
 * capabilities, or the job's progress once it started.
 *
 * Included by BLT_Toolkit_Admin_Role_Lifecycle::render() inside the
 * screen's `.wrap`. Writes nothing; the forms post to admin-post.php.
 *
 * @package Blt_Toolkit
 *
 * @var string               $role       Role whose users move.
 * @var bool                 $exists     Whether it exists.
 * @var string               $name       Its display name.
 * @var array<string,string> $values     `name` and `role_id` of the new role, to prefill.
 * @var string               $job_id     The reassign job to show, or ''.
 * @var string               $new        The new role, once created, or ''.
 * @var string               $new_name   Its display name.
 * @var array|null           $counts     Dry run (BLT_Toolkit_Role_Lifecycle::reassign_counts()), or null.
 * @var array|null           $review     The SEC-10 review, or null.
 * @var true|WP_Error        $refusal    Why the person may not copy this role (SEC-3), or true.
 * @var bool                 $can_delete Whether the person may delete the old role afterwards.
 * @var string               $back_url   The role list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_action     = 'blt_toolkit_role_move';
$blt_tk_badges     = BLT_Toolkit_Admin_Role_Lifecycle::tier_badges();
$blt_tk_delete_url = BLT_Toolkit_Admin_Role_Lifecycle::url(
	'delete',
	array(
		'step'  => 'confirm',
		'roles' => array( $role ),
	)
);
$blt_tk_edit_url   = null === $review ? '' : BLT_Toolkit_Admin_Role_Lifecycle::url(
	'move',
	array(
		BLT_Toolkit_Admin_Role_Lifecycle::ROLE_ARG => $role,
		'name'                                     => $review['name'],
		'role_id'                                  => $review['role_id'],
	)
);

if ( '' === $job_id ) {
	BLT_Toolkit_Admin_Role_Lifecycle::print_live_job();
}
?>
<?php if ( ! $exists ) : ?>
	<?php if ( '' !== $job_id ) : ?>
		<?php BLT_Toolkit_Jobs::render_ui( $job_id ); ?>
	<?php endif; ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'That role does not exist', 'blt-toolkit' ); ?></span>
				<span><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></span>
			</div>
		</div>
	</div>
<?php elseif ( '' !== $job_id || '' !== $new ) : ?>
	<?php if ( '' !== $job_id ) : ?>
		<?php BLT_Toolkit_Jobs::render_ui( $job_id ); ?>
	<?php endif; ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Next: delete the old role', 'blt-toolkit' ); ?></h2>
			<p>
				<?php
				if ( '' !== $new ) {
					printf(
						/* translators: 1: old role name, 2: old role ID, 3: new role name, 4: new role ID. */
						esc_html__( 'Once everyone has moved from %1$s (%2$s) to %3$s (%4$s), the old role can go.', 'blt-toolkit' ),
						'<strong>' . esc_html( $name ) . '</strong>',
						'<code>' . esc_html( $role ) . '</code>',
						'<strong>' . esc_html( $new_name ) . '</strong>',
						'<code>' . esc_html( $new ) . '</code>'
					);
				} else {
					printf(
						/* translators: %s: old role name. */
						esc_html__( 'Once everyone has moved out of %s, the old role can go.', 'blt-toolkit' ),
						'<strong>' . esc_html( $name ) . '</strong>'
					);
				}
				?>
			</p>
		</div>
		<div class="blt-card-body">
			<?php if ( $can_delete ) : ?>
				<p><a class="button" href="<?php echo esc_url( $blt_tk_delete_url ); ?>"><?php esc_html_e( 'Delete the old role', 'blt-toolkit' ); ?></a></p>
				<p class="blt-field-desc"><?php esc_html_e( 'The next page shows how many people still hold it. Wait for the move to finish first, or anyone still holding it is moved again.', 'blt-toolkit' ); ?></p>
			<?php else : ?>
				<p class="blt-field-desc"><?php esc_html_e( 'You cannot delete this role yourself. Ask an administrator, or keep it.', 'blt-toolkit' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $new ) : ?>
				<p><a href="<?php echo esc_url( BLT_Toolkit_Admin::page_url( 'roles', array( BLT_Toolkit_Admin_Role_Lifecycle::ROLE_ARG => $new ) ) ); ?>">
					<?php
					printf(
						/* translators: %s: role name. */
						esc_html__( 'Open %s in the role editor', 'blt-toolkit' ),
						esc_html( $new_name )
					);
					?>
				</a></p>
			<?php endif; ?>
		</div>
	</div>
<?php elseif ( is_wp_error( $refusal ) ) : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Move users to a new role', 'blt-toolkit' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-callout">
				<strong><?php esc_html_e( 'You cannot move this role\'s users', 'blt-toolkit' ); ?></strong>
				<span><?php echo esc_html( $refusal->get_error_message() ); ?></span>
			</div>
		</div>
	</div>
<?php elseif ( null !== $review ) : ?>
	<?php $blt_tk_confirm_id = 'blt-toolkit-role-confirm'; ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( $blt_tk_action ); ?>
		<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>" />
		<input type="hidden" name="name" value="<?php echo esc_attr( $review['name'] ); ?>" />
		<input type="hidden" name="role_id" value="<?php echo esc_attr( $review['role_id'] ); ?>" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Review the new role', 'blt-toolkit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: new role name, 2: new role ID, 3: old role name. */
						esc_html__( '%1$s (%2$s) will be a copy of %3$s, so it has the same Critical capabilities.', 'blt-toolkit' ),
						'<strong>' . esc_html( $review['name'] ) . '</strong>',
						'<code>' . esc_html( $review['role_id'] ) . '</code>',
						'<strong>' . esc_html( $name ) . '</strong>'
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
					<span><?php esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts: change settings, run code, or edit and sign in as other users. Everyone moved to this role keeps them.', 'blt-toolkit' ); ?></span>
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
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create role and move users', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $blt_tk_edit_url ); ?>"><?php esc_html_e( 'Change something', 'blt-toolkit' ); ?></a>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( $blt_tk_action ); ?>
		<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2>
					<?php
					printf(
						/* translators: %s: role name. */
						esc_html__( 'Move users of %s to a new role', 'blt-toolkit' ),
						esc_html( $name )
					);
					?>
				</h2>
				<p>
					<?php
					printf(
						/* translators: %s: role ID. */
						esc_html__( 'A role\'s ID (%s) cannot be changed in place, because each person\'s role assignment refers to it. This creates a copy under the new ID, moves everyone to it in the background, then offers to delete the old role.', 'blt-toolkit' ),
						'<code>' . esc_html( $role ) . '</code>'
					);
					?>
				</p>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Dry run', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Who moves', 'blt-toolkit' ); ?></div>
					<div>
						<ul class="ul-disc">
							<?php foreach ( BLT_Toolkit_Admin_Role_Lifecycle::count_lines( $counts, $name, '', BLT_Toolkit_Role_Lifecycle::MODE_MOVE ) as $blt_tk_line ) : ?>
								<li><?php echo esc_html( $blt_tk_line ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-toolkit-role-name"><?php esc_html_e( 'New display name', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text" id="blt-toolkit-role-name" name="name" value="<?php echo esc_attr( $values['name'] ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Role_Lifecycle::NAME_MAX ); ?>" required aria-describedby="blt-toolkit-role-name-desc" />
						<p class="blt-field-desc" id="blt-toolkit-role-name-desc"><?php esc_html_e( 'Two roles cannot share a name, so the new one needs its own while the old one exists. Rename it afterwards if you like.', 'blt-toolkit' ); ?></p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-toolkit-role-id"><?php esc_html_e( 'New role ID', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text code" id="blt-toolkit-role-id" name="role_id" value="<?php echo esc_attr( $values['role_id'] ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Role_Lifecycle::ID_MAX ); ?>" pattern="[a-z0-9_\-]*[a-z][a-z0-9_\-]*" autocomplete="off" spellcheck="false" required aria-describedby="blt-toolkit-role-id-desc" />
						<p class="blt-field-desc" id="blt-toolkit-role-id-desc">
							<?php
							printf(
								/* translators: %s: maximum length. */
								esc_html__( 'Lowercase letters, digits, underscores and hyphens, up to %s characters.', 'blt-toolkit' ),
								esc_html( number_format_i18n( BLT_Toolkit_Role_Lifecycle::ID_MAX ) )
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create role and move users', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin::page_url( 'roles', array( BLT_Toolkit_Admin_Role_Lifecycle::ROLE_ARG => $role ) ) ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php endif; ?>
