<?php
/**
 * Roles > Delete roles (RO-40): pick roles, then confirm with a dry run and
 * a replacement for their users (RO-41), then follow the job.
 *
 * Only deletable roles can be picked: never a built-in role, Administrator,
 * the default role or an additional default role, and for someone who is
 * not an administrator never a role they hold or one with more access than
 * theirs. The pick step is a GET form (it changes nothing); the confirm step
 * posts to admin-post.php.
 *
 * Included by BLT_Toolkit_Admin_Role_Lifecycle::render() inside the
 * screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string               $job_id       The reassign job to show, or ''.
 * @var string               $step         `select`, `confirm` or `job`.
 * @var array<string,array>  $rows         Deletable roles: ID => array( 'id', 'name', 'users' ).
 * @var array[]              $blocked      Roles that cannot be deleted: each array( 'id', 'name', 'users', 'reason' ).
 * @var array<string,array>  $selected     Chosen deletable roles (confirm step).
 * @var string[]             $unknown      Chosen roles that are not deletable or do not exist.
 * @var array<string,string> $replacements Roles the person may move people to: ID => name.
 * @var string               $replacement  Preselected replacement.
 * @var bool                 $needs        Whether any chosen role has users.
 * @var array                $counts       Role ID => dry run (reassign_counts()) or null.
 * @var string               $back_url     The role list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( 'job' !== $step ) {
	BLT_Toolkit_Admin_Role_Lifecycle::print_live_job();
}
?>
<?php if ( 'job' === $step ) : ?>
	<?php BLT_Toolkit_Jobs::render_ui( $job_id ); ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'What happens next', 'blt-toolkit' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<p><?php esc_html_e( 'Each role is deleted as soon as nobody holds it. Anyone who could not be moved is listed above with the reason; their role is kept until they are moved, so you can retry them or leave the role in place.', 'blt-toolkit' ); ?></p>
			<p><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></p>
		</div>
	</div>
<?php elseif ( 'confirm' === $step && array() !== $selected ) : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_role_delete' ); ?>
		<?php foreach ( array_keys( $selected ) as $blt_tk_id ) : ?>
			<input type="hidden" name="roles[]" value="<?php echo esc_attr( $blt_tk_id ); ?>" />
		<?php endforeach; ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php echo esc_html( _n( 'Delete this role?', 'Delete these roles?', count( $selected ), 'blt-toolkit' ) ); ?></h2>
				<p><?php esc_html_e( 'A copy of every role is saved first, so a deleted role can be brought back from the snapshots on the Tools screen.', 'blt-toolkit' ); ?></p>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Dry run', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $selected as $blt_tk_id => $blt_tk_row ) : ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<?php echo esc_html( $blt_tk_row['name'] ); ?>
							<code><?php echo esc_html( $blt_tk_id ); ?></code>
						</div>
						<div>
							<?php if ( 0 === (int) $blt_tk_row['users'] ) : ?>
								<p><?php esc_html_e( 'Nobody holds this role. It is deleted at once.', 'blt-toolkit' ); ?></p>
							<?php else : ?>
								<ul class="ul-disc">
									<?php foreach ( BLT_Toolkit_Admin_Role_Lifecycle::count_lines( isset( $counts[ $blt_tk_id ] ) ? $counts[ $blt_tk_id ] : null, $blt_tk_row['name'], __( 'the role you choose below', 'blt-toolkit' ), BLT_Toolkit_Role_Lifecycle::MODE_REPLACE ) as $blt_tk_line ) : ?>
										<li><?php echo esc_html( $blt_tk_line ); ?></li>
									<?php endforeach; ?>
								</ul>
								<p class="blt-field-desc"><?php esc_html_e( 'People are moved in the background; the role is deleted as soon as nobody holds it.', 'blt-toolkit' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<?php if ( $needs ) : ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<label for="blt-toolkit-role-replacement"><?php esc_html_e( 'Give them instead', 'blt-toolkit' ); ?></label>
						</div>
						<div>
							<?php if ( array() === $replacements ) : ?>
								<p><?php esc_html_e( 'There is no role you may give people instead, so roles with users cannot be deleted by you.', 'blt-toolkit' ); ?></p>
							<?php else : ?>
								<select id="blt-toolkit-role-replacement" name="replacement" required aria-describedby="blt-toolkit-role-replacement-desc">
									<?php foreach ( $replacements as $blt_tk_id => $blt_tk_name ) : ?>
										<option value="<?php echo esc_attr( $blt_tk_id ); ?>"<?php selected( $replacement, $blt_tk_id ); ?>><?php echo esc_html( $blt_tk_name ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="blt-field-desc" id="blt-toolkit-role-replacement-desc"><?php esc_html_e( 'Only people left with no role get it. Anyone holding another role keeps that role and simply loses the deleted one.', 'blt-toolkit' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( array() !== $unknown ) : ?>
			<div class="blt-callout">
				<strong><?php esc_html_e( 'Left out', 'blt-toolkit' ); ?></strong>
				<span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated role IDs. */
							__( 'These roles do not exist or cannot be deleted, so they are not part of this: %s', 'blt-toolkit' ),
							implode( wp_get_list_item_separator(), $unknown )
						)
					);
					?>
				</span>
			</div>
		<?php endif; ?>

		<div class="blt-settings-footer">
			<?php if ( ! $needs || array() !== $replacements ) : ?>
				<button type="submit" class="button button-primary"><?php echo esc_html( _n( 'Delete role', 'Delete roles', count( $selected ), 'blt-toolkit' ) ); ?></button>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin_Role_Lifecycle::url( 'delete', array( 'roles' => array_keys( $selected ) ) ) ); ?>"><?php esc_html_e( 'Choose other roles', 'blt-toolkit' ); ?></a>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php else : ?>
	<?php if ( 'confirm' === $step ) : ?>
		<div class="notice notice-warning inline blt-toolkit-notice" role="status">
			<p><?php esc_html_e( 'Choose at least one role that can be deleted.', 'blt-toolkit' ); ?></p>
		</div>
	<?php endif; ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( BLT_Toolkit_Admin::screens()['roles']['slug'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Role_Lifecycle::ARG ); ?>" value="delete" />
		<input type="hidden" name="step" value="confirm" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Delete roles', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Choose the roles to delete. The next step shows who holds them and asks which role those people get instead. Nothing changes until you confirm.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php if ( array() === $rows ) : ?>
					<div class="blt-empty">
						<span class="blt-empty-title"><?php esc_html_e( 'No role can be deleted', 'blt-toolkit' ); ?></span>
						<span><?php esc_html_e( 'WordPress\'s own roles, the Administrator role and the roles new users get are never deleted. The reasons for each role are listed below.', 'blt-toolkit' ); ?></span>
					</div>
				<?php else : ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Roles to delete', 'blt-toolkit' ); ?></legend>
						<table class="widefat striped blt-toolkit-role-delete-table">
							<thead>
								<tr>
									<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Delete', 'blt-toolkit' ); ?></span></td>
									<th scope="col"><?php esc_html_e( 'Role', 'blt-toolkit' ); ?></th>
									<th scope="col"><?php esc_html_e( 'People', 'blt-toolkit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $blt_tk_id => $blt_tk_row ) : ?>
									<?php $blt_tk_field = 'blt-toolkit-delete-' . sanitize_html_class( $blt_tk_id ); ?>
									<tr>
										<th scope="row" class="check-column">
											<input type="checkbox" id="<?php echo esc_attr( $blt_tk_field ); ?>" name="roles[]" value="<?php echo esc_attr( $blt_tk_id ); ?>"<?php checked( isset( $selected[ $blt_tk_id ] ) ); ?> />
										</th>
										<td>
											<label for="<?php echo esc_attr( $blt_tk_field ); ?>"><strong><?php echo esc_html( $blt_tk_row['name'] ); ?></strong></label>
											<code><?php echo esc_html( $blt_tk_id ); ?></code>
										</td>
										<td><?php echo esc_html( number_format_i18n( (int) $blt_tk_row['users'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</fieldset>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( array() !== $blocked ) : ?>
			<div class="blt-card">
				<div class="blt-card-header">
					<h2><?php esc_html_e( 'Roles that cannot be deleted', 'blt-toolkit' ); ?></h2>
				</div>
				<div class="blt-card-body">
					<?php foreach ( $blocked as $blt_tk_row ) : ?>
						<div class="blt-field">
							<div class="blt-field-label">
								<?php echo esc_html( $blt_tk_row['name'] ); ?>
								<code><?php echo esc_html( $blt_tk_row['id'] ); ?></code>
							</div>
							<div><p class="blt-field-desc"><?php echo esc_html( $blt_tk_row['reason'] ); ?></p></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="blt-settings-footer">
			<?php if ( array() !== $rows ) : ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Continue', 'blt-toolkit' ); ?></button>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php endif; ?>
