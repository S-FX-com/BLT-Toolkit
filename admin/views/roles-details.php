<?php
/**
 * Roles screen: the role's details (SPEC 9.5): a description shown in the
 * role list, and "Hide the toolbar on the site". Saved through
 * BLT_Toolkit_Settings (`roles.role_meta`) with a log row.
 *
 * @package Blt_Toolkit
 *
 * @var array  $details role, description, hide_toolbar, toolbar (whether the toolbar setting is on this form; Simple view has it in its Basics card), editable, back.
 * @var string $name    Role display name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-role-details">
	<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_details' ); ?>
	<input type="hidden" name="role" value="<?php echo esc_attr( $details['role'] ); ?>" />
	<input type="hidden" name="back" value="<?php echo esc_attr( $details['back'] ); ?>" />
	<?php if ( isset( $details['toolbar'] ) && ! $details['toolbar'] ) : ?>
		<input type="hidden" name="keep_toolbar" value="1" />
	<?php endif; ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Role details', 'blt-toolkit' ); ?></h2>
			<p>
				<?php
				if ( isset( $details['toolbar'] ) && ! $details['toolbar'] ) {
					printf(
						/* translators: %s: role name. */
						esc_html__( 'How %s is described in the role list.', 'blt-toolkit' ),
						esc_html( $name )
					);
				} else {
					printf(
						/* translators: %s: role name. */
						esc_html__( 'How %s is described in the role list, and whether its users see the toolbar on the site.', 'blt-toolkit' ),
						esc_html( $name )
					);
				}
				?>
			</p>
		</div>
		<div class="blt-card-body">
			<div class="blt-field">
				<div class="blt-field-label"><label for="blt-tk-role-description"><?php esc_html_e( 'Description', 'blt-toolkit' ); ?></label></div>
				<div>
					<input type="text" class="large-text" id="blt-tk-role-description" aria-describedby="blt-tk-role-description-desc" name="description" value="<?php echo esc_attr( $details['description'] ); ?>" maxlength="500" <?php disabled( ! $details['editable'] ); ?> />
					<p class="blt-field-desc" id="blt-tk-role-description-desc"><?php esc_html_e( 'Optional. Shown under the role\'s name in the role list.', 'blt-toolkit' ); ?></p>
				</div>
			</div>
			<?php if ( ! isset( $details['toolbar'] ) || $details['toolbar'] ) : ?>
			<div class="blt-field">
				<div class="blt-field-label"><?php esc_html_e( 'Toolbar', 'blt-toolkit' ); ?></div>
				<div>
					<label class="blt-toggle">
						<input type="checkbox" name="hide_toolbar" value="1" <?php checked( $details['hide_toolbar'] ); ?> <?php disabled( ! $details['editable'] ); ?> />
						<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
						<span class="blt-toggle-text">
							<span class="blt-toggle-label"><?php esc_html_e( 'Hide the toolbar on the site', 'blt-toolkit' ); ?></span>
							<span class="blt-toggle-desc"><?php esc_html_e( 'Applies only to people whose every role hides it, since the most permissive role wins. Someone switched into such a user still gets a Switch back link at the foot of the page.', 'blt-toolkit' ); ?></span>
						</span>
					</label>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php if ( $details['editable'] ) : ?>
		<div class="blt-settings-footer">
			<button type="submit" class="button"><?php esc_html_e( 'Save details', 'blt-toolkit' ); ?></button>
		</div>
	<?php endif; ?>
</form>
