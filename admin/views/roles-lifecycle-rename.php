<?php
/**
 * Roles > Rename (RO-31): the display name only; the ID never changes.
 *
 * Included by BLT_Toolkit_Admin_Role_Lifecycle::render() inside the
 * screen's `.wrap`. Writes nothing; the form posts to admin-post.php.
 *
 * @package Blt_Toolkit
 *
 * @var string        $role      Role ID from the URL.
 * @var bool          $exists    Whether it exists.
 * @var string        $name      Its display name now.
 * @var string        $value     The name to prefill after a refused save, or ''.
 * @var true|WP_Error $refusal   Why the person may not change this role (SEC-1, SEC-2, SEC-5), or true.
 * @var bool          $protected Whether it is the Administrator role (its ID never changes, SEC-12).
 * @var string        $back_url  The role list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( ! $exists ) : ?>
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
					esc_html__( 'Rename %s', 'blt-toolkit' ),
					esc_html( $name )
				);
				?>
			</h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-callout">
				<strong><?php esc_html_e( 'You cannot rename this role', 'blt-toolkit' ); ?></strong>
				<span><?php echo esc_html( $refusal->get_error_message() ); ?></span>
			</div>
		</div>
	</div>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_role_rename' ); ?>
		<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>" />

		<div class="blt-card">
			<div class="blt-card-header">
				<h2>
					<?php
					printf(
						/* translators: %s: role name. */
						esc_html__( 'Rename %s', 'blt-toolkit' ),
						esc_html( $name )
					);
					?>
				</h2>
				<p><?php esc_html_e( 'Only the name people see changes. Everyone who holds the role keeps it, and what it allows stays the same.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Role ID', 'blt-toolkit' ); ?></div>
					<div>
						<code><?php echo esc_html( $role ); ?></code>
						<p class="blt-field-desc">
							<?php if ( $protected ) : ?>
								<?php esc_html_e( 'The Administrator role\'s ID never changes.', 'blt-toolkit' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'The ID stays the same, because each person\'s role assignment refers to it. To change it, use "Move users to a new role".', 'blt-toolkit' ); ?>
							<?php endif; ?>
						</p>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="blt-toolkit-role-name"><?php esc_html_e( 'Display name', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text" id="blt-toolkit-role-name" name="name" value="<?php echo esc_attr( '' !== $value ? $value : $name ); ?>" maxlength="<?php echo esc_attr( (string) BLT_Toolkit_Role_Lifecycle::NAME_MAX ); ?>" required />
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Rename role', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin::page_url( 'roles', array( BLT_Toolkit_Admin_Role_Lifecycle::ROLE_ARG => $role ) ) ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
<?php endif; ?>
