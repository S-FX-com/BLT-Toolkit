<?php
/**
 * Settings > Roles (SPEC 9 and 10, settings group `roles`).
 *
 * `role_meta` and `isolated_post_types` are not on this form: the features
 * that own them write them through BLT_Toolkit_Settings::set().
 * `extra_default_roles` (US-2) is, with its own fields outside the
 * `blt_toolkit[...]` array, saved by
 * BLT_Toolkit_User_Roles::save_default_roles_from_request() (only roles that
 * were drawn as changeable change).
 *
 * Included inside the settings form by views/settings.php.
 *
 * @package Blt_Toolkit
 *
 * @var array  $settings BLT_Toolkit_Settings::all().
 * @var string $field    Name of the settings field array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$roles_settings = $settings['roles'];
$views          = array(
	'simple'   => __( 'Simple', 'blt-toolkit' ),
	'advanced' => __( 'Advanced', 'blt-toolkit' ),
);
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Role editor', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Simple and Advanced are two views of the same roles. Simple shows one access level per content type; Advanced shows every capability.', 'blt-toolkit' ); ?></p>
		<?php if ( BLT_Toolkit_Plugin::is_active( 'roles' ) ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Roles screen. */
					esc_html__( 'Each role\'s description and "Hide the toolbar on the site" are set under Role details on the %s screen.', 'blt-toolkit' ),
					'<a href="' . esc_url( BLT_Toolkit_Admin::page_url( 'roles' ) ) . '">' . esc_html__( 'Roles', 'blt-toolkit' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-default-view"><?php esc_html_e( 'Opens in', 'blt-toolkit' ); ?></label></div>
			<div>
				<select id="blt-toolkit-default-view" name="<?php echo esc_attr( $field ); ?>[default_view]">
					<?php foreach ( $views as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $roles_settings['default_view'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="blt-field-desc"><?php esc_html_e( 'The view the Roles screen opens in for someone who has not picked one yet. Each person\'s choice is remembered. People who cannot use Advanced view always see Simple.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Advanced view', 'blt-toolkit' ); ?></div>
			<div class="blt-toggle-stack">
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[readable_labels]',
					$roles_settings['readable_labels'],
					__( 'Readable capability names', 'blt-toolkit' ),
					__( 'Shows "Edit others\' posts" rather than edit_others_posts. The raw name is always shown beside it.', 'blt-toolkit' )
				);
				BLT_Toolkit_Admin::toggle(
					$field . '[show_deprecated]',
					$roles_settings['show_deprecated'],
					__( 'Show deprecated capabilities', 'blt-toolkit' ),
					__( 'Also lists capabilities WordPress no longer uses, such as level_0 to level_10.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Protection', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'People who are not administrators can never edit, delete, rename or switch into an administrator, or change the roles of someone with more access than they have, whatever is set here.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php
		BLT_Toolkit_Admin::toggle(
			$field . '[hide_admins]',
			$roles_settings['hide_admins'],
			__( 'Hide administrators from everyone else', 'blt-toolkit' ),
			__( 'People who are not administrators do not see administrator accounts in the Users list, its counts, or the users REST API. It keeps admin accounts out of view of delegated user managers.', 'blt-toolkit' )
		);
		?>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Snapshots', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'A copy of every role is saved before each change, so any change can be undone from Tools.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-snapshot-retention"><?php esc_html_e( 'Snapshots to keep', 'blt-toolkit' ); ?></label></div>
			<div>
				<?php BLT_Toolkit_Admin_Settings::number( 'roles', 'snapshot_retention', $roles_settings['snapshot_retention'], 'blt-toolkit-snapshot-retention' ); ?>
				<p class="blt-field-desc"><?php esc_html_e( 'From 1 to 500. The newest ones are kept, plus the baseline taken when BLT Toolkit was first activated, which is never removed.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'New users', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Roles every new user receives on top of the default role, however the account is made: the registration form, Add New User, the REST API, WP-CLI or a single sign-on plugin.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php if ( ! BLT_Toolkit_Plugin::is_active( 'roles' ) ) : ?>
			<p class="blt-text-muted"><?php esc_html_e( 'Turn on the Roles module on the General tab to give new users additional roles.', 'blt-toolkit' ); ?></p>
		<?php else : ?>
			<?php $default_roles = BLT_Toolkit_User_Roles::default_roles_state( wp_get_current_user() ); ?>
			<div class="blt-field">
				<div class="blt-field-label" id="blt-toolkit-extra-default-roles-label"><?php esc_html_e( 'Additional default roles', 'blt-toolkit' ); ?></div>
				<div>
					<?php if ( empty( $default_roles ) ) : ?>
						<p class="blt-text-muted"><?php esc_html_e( 'This site has no roles that can be given this way.', 'blt-toolkit' ); ?></p>
					<?php else : ?>
						<div class="blt-toggle-stack" role="group" aria-labelledby="blt-toolkit-extra-default-roles-label">
							<?php foreach ( $default_roles as $role_id => $role_state ) : ?>
								<label class="blt-toggle">
									<input type="checkbox" name="<?php echo esc_attr( BLT_Toolkit_User_Roles::DEFAULTS_FIELD ); ?>[]" value="<?php echo esc_attr( $role_id ); ?>" <?php checked( $role_state['on'] ); ?> <?php disabled( ! $role_state['editable'] ); ?> />
									<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
									<span class="blt-toggle-text">
										<span class="blt-toggle-label"><?php echo esc_html( $role_state['name'] ); ?></span>
										<?php if ( ! $role_state['editable'] ) : ?>
											<span class="blt-toggle-desc"><?php esc_html_e( 'This role has capabilities you do not have, so you cannot change this.', 'blt-toolkit' ); ?></span>
										<?php elseif ( $role_state['is_default'] ) : ?>
											<span class="blt-toggle-desc"><?php esc_html_e( 'Already the site\'s New User Default Role (WordPress\'s Settings > General), so new users get it anyway.', 'blt-toolkit' ); ?></span>
										<?php endif; ?>
									</span>
								</label>
								<?php if ( $role_state['editable'] ) : ?>
									<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_User_Roles::DEFAULTS_SHOWN ); ?>[]" value="<?php echo esc_attr( $role_id ); ?>" />
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<p class="blt-field-desc"><?php esc_html_e( 'The default role stays the main role. Add New User shows these ticked, so whoever creates an account can leave one out. Someone who is not an administrator and creates an account passes on only roles they could give themselves. Administrator is never offered: every person who signs up would get it.', 'blt-toolkit' ); ?></p>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
