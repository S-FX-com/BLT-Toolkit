<?php
/**
 * Roles > Merge into another role (RO-42): everyone who holds the role gets
 * the chosen one in its place (RO-41), then the role is deleted, as one
 * flow.
 *
 * The target is picked with a GET form, so the dry run is counted for it;
 * the confirmation posts to admin-post.php.
 *
 * Included by BLT_Toolkit_Admin_Role_Lifecycle::render() inside the
 * screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string               $role         Role being merged away.
 * @var bool                 $exists       Whether it exists.
 * @var string               $name         Its display name.
 * @var string               $job_id       The reassign job to show, or ''.
 * @var true|WP_Error        $refusal      Why it cannot be merged away (RO-40, SEC rules), or true.
 * @var array<string,string> $replacements Roles its people may get: ID => name.
 * @var string               $replacement  The chosen target.
 * @var array|null           $counts       Dry run for that target, or null.
 * @var string               $back_url     The role list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( '' === $job_id ) {
	BLT_Toolkit_Admin_Role_Lifecycle::print_live_job();
}

$blt_tk_target = isset( $replacements[ $replacement ] ) ? $replacements[ $replacement ] : '';
?>
<?php if ( '' !== $job_id ) : ?>
	<?php BLT_Toolkit_Jobs::render_ui( $job_id ); ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'What happens next', 'blt-toolkit' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<p><?php esc_html_e( 'The role is deleted as soon as nobody holds it. Anyone who could not be moved is listed above with the reason; the role is kept until they are moved.', 'blt-toolkit' ); ?></p>
			<p><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></p>
		</div>
	</div>
<?php elseif ( ! $exists ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'That role does not exist', 'blt-toolkit' ); ?></span>
				<span><a href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a></span>
			</div>
		</div>
	</div>
<?php elseif ( is_wp_error( $refusal ) ) : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				printf(
					/* translators: %s: role name. */
					esc_html__( 'Merge %s into another role', 'blt-toolkit' ),
					esc_html( $name )
				);
				?>
			</h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-callout">
				<strong><?php esc_html_e( 'This role cannot be merged away', 'blt-toolkit' ); ?></strong>
				<span><?php echo esc_html( $refusal->get_error_message() ); ?></span>
			</div>
		</div>
	</div>
<?php else : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				printf(
					/* translators: %s: role name. */
					esc_html__( 'Merge %s into another role', 'blt-toolkit' ),
					esc_html( $name )
				);
				?>
			</h2>
			<p><?php esc_html_e( 'Everyone who holds this role gets the role you choose in its place, keeping any other roles, and then this role is deleted. A copy of every role is saved first.', 'blt-toolkit' ); ?></p>
			<div class="blt-card-header-badges">
				<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Dry run', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			</div>
		</div>
		<div class="blt-card-body">
			<?php if ( array() === $replacements ) : ?>
				<p><?php esc_html_e( 'There is no role you may give people instead, so you cannot merge this role.', 'blt-toolkit' ); ?></p>
			<?php else : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="blt-field">
					<input type="hidden" name="page" value="<?php echo esc_attr( BLT_Toolkit_Admin::screens()['roles']['slug'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Role_Lifecycle::ARG ); ?>" value="merge" />
					<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Role_Lifecycle::ROLE_ARG ); ?>" value="<?php echo esc_attr( $role ); ?>" />
					<div class="blt-field-label">
						<label for="blt-toolkit-role-into"><?php esc_html_e( 'Merge into', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<select id="blt-toolkit-role-into" name="replacement">
							<?php foreach ( $replacements as $blt_tk_id => $blt_tk_name ) : ?>
								<option value="<?php echo esc_attr( $blt_tk_id ); ?>"<?php selected( $replacement, $blt_tk_id ); ?>><?php echo esc_html( $blt_tk_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Show who moves', 'blt-toolkit' ); ?></button>
					</div>
				</form>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Who moves', 'blt-toolkit' ); ?></div>
					<div>
						<ul class="ul-disc">
							<?php foreach ( BLT_Toolkit_Admin_Role_Lifecycle::count_lines( $counts, $name, $blt_tk_target, BLT_Toolkit_Role_Lifecycle::MODE_MOVE ) as $blt_tk_line ) : ?>
								<li><?php echo esc_html( $blt_tk_line ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( '' !== $blt_tk_target ) : ?>
		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
			<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_role_merge' ); ?>
			<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>" />
			<input type="hidden" name="replacement" value="<?php echo esc_attr( $replacement ); ?>" />
			<div class="blt-settings-footer">
				<button type="submit" class="button button-primary">
					<?php
					printf(
						/* translators: 1: role being merged away, 2: role it is merged into. */
						esc_html__( 'Merge %1$s into %2$s', 'blt-toolkit' ),
						esc_html( $name ),
						esc_html( $blt_tk_target )
					);
					?>
				</button>
				<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin::page_url( 'roles', array( BLT_Toolkit_Admin_Role_Lifecycle::ROLE_ARG => $role ) ) ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
			</div>
		</form>
	<?php endif; ?>
<?php endif; ?>
