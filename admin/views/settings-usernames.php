<?php
/**
 * Settings > Usernames (SPEC 8, settings group `usernames`).
 *
 * Included inside the settings form by views/settings.php.
 *
 * @package Blt_Toolkit
 *
 * @var array  $settings     BLT_Toolkit_Settings::all().
 * @var string $field        Name of the settings field array.
 * @var array  $self_service BLT_Toolkit_Admin_Settings::self_service_state(): role ID => name, on, editable, reason (UN-20).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$usernames = $settings['usernames'];
$policies  = array(
	'if_derived' => __( 'Change it only when it was made from the old username (recommended)', 'blt-toolkit' ),
	'always'     => __( 'Always change it to match the new username', 'blt-toolkit' ),
	'never'      => __( 'Never change it', 'blt-toolkit' ),
);
$tags      = array( '{old_username}', '{new_username}', '{display_name}', '{email}', '{sitename}', '{siteurl}', '{loginurl}' );
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Rules for new usernames', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Checked every time a username is changed, by an administrator, in bulk or by the person themselves. Existing usernames are never affected.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-min-length"><?php esc_html_e( 'Minimum length', 'blt-toolkit' ); ?></label></div>
			<div>
				<?php BLT_Toolkit_Admin_Settings::number( 'usernames', 'min_length', $usernames['min_length'], 'blt-toolkit-min-length' ); ?>
				<?php esc_html_e( 'characters', 'blt-toolkit' ); ?>
				<p class="blt-field-desc"><?php esc_html_e( 'From 1 to 60. WordPress cannot store a username longer than 60 characters.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-disallowed"><?php esc_html_e( 'Names that cannot be used', 'blt-toolkit' ); ?></label></div>
			<div>
				<textarea id="blt-toolkit-disallowed" class="regular-text" rows="5" name="<?php echo esc_attr( $field ); ?>[disallowed]"><?php echo esc_textarea( implode( "\n", (array) $usernames['disallowed'] ) ); ?></textarea>
				<p class="blt-field-desc"><?php esc_html_e( 'One per line. Capitals are ignored, so "Admin" is refused when "admin" is listed. Names that other plugins block are refused too.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'The @ sign', 'blt-toolkit' ); ?></div>
			<div>
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[allow_at_sign]',
					$usernames['allow_at_sign'],
					__( 'Allow @ in usernames', 'blt-toolkit' ),
					__( 'Email-style usernames are common with single sign-on and membership sites. Either way, a username can never be another person\'s email address, because WordPress also accepts email addresses at login.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'When a username changes', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'What else changes with it.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-nicename-policy"><?php esc_html_e( 'Author URL', 'blt-toolkit' ); ?></label></div>
			<div>
				<select id="blt-toolkit-nicename-policy" name="<?php echo esc_attr( $field ); ?>[nicename_policy]">
					<?php foreach ( $policies as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $usernames['nicename_policy'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="blt-field-desc"><?php esc_html_e( 'The author URL is the last part of the link to someone\'s posts, such as /author/jsmith/. WordPress makes it from the username, unless someone changed it since.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Old links', 'blt-toolkit' ); ?></div>
			<div>
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[redirect_old_slugs]',
					$usernames['redirect_old_slugs'],
					__( 'Redirect old author URLs', 'blt-toolkit' ),
					__( 'A link to an old author URL goes to the new one with a permanent (301) redirect, which protects search rankings and inbound links. A current user\'s URL always wins over an old one.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Sessions', 'blt-toolkit' ); ?></div>
			<div>
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[sign_out_others]',
					$usernames['sign_out_others'],
					__( 'Sign the person out everywhere', 'blt-toolkit' ),
					__( 'Their sign-in stops working once their username changes anyway; this also clears the dead sessions. Someone who changes their own username stays signed in.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Email', 'blt-toolkit' ); ?></div>
			<div>
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[notify_user]',
					$usernames['notify_user'],
					__( 'Email the person about the change', 'blt-toolkit' ),
					__( 'A plain-text email, sent through the site\'s normal mail setup, so an SMTP plugin delivers it as usual.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-email-subject"><?php esc_html_e( 'Email subject', 'blt-toolkit' ); ?></label></div>
			<div>
				<input type="text" id="blt-toolkit-email-subject" class="regular-text" name="<?php echo esc_attr( $field ); ?>[email_subject]" value="<?php echo esc_attr( $usernames['email_subject'] ); ?>" />
				<p class="blt-field-desc"><?php esc_html_e( 'Leave blank for the default subject, in the site\'s language.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-email-body"><?php esc_html_e( 'Email message', 'blt-toolkit' ); ?></label></div>
			<div>
				<textarea id="blt-toolkit-email-body" class="large-text" rows="6" name="<?php echo esc_attr( $field ); ?>[email_body]"><?php echo esc_textarea( $usernames['email_body'] ); ?></textarea>
				<p class="blt-field-desc"><?php esc_html_e( 'Leave blank for the default message, in the site\'s language. These tags are replaced when the email is sent:', 'blt-toolkit' ); ?></p>
				<span class="blt-chips">
					<?php foreach ( $tags as $tag_name ) : ?>
						<code class="blt-chip"><?php echo esc_html( $tag_name ); ?></code>
					<?php endforeach; ?>
				</span>
			</div>
		</div>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Self-service', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'For people allowed to change their own username.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label" id="blt-toolkit-self-service-label"><?php esc_html_e( 'Who can change their own username', 'blt-toolkit' ); ?></div>
			<div>
				<?php if ( empty( $self_service ) ) : ?>
					<p class="blt-text-muted"><?php esc_html_e( 'This site has no roles.', 'blt-toolkit' ); ?></p>
				<?php else : ?>
					<div class="blt-toggle-stack" role="group" aria-labelledby="blt-toolkit-self-service-label">
						<?php foreach ( $self_service as $role_id => $role_state ) : ?>
							<label class="blt-toggle">
								<input type="checkbox" name="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::SELF_SERVICE_FIELD ); ?>[]" value="<?php echo esc_attr( $role_id ); ?>" <?php checked( $role_state['on'] ); ?> <?php disabled( ! $role_state['editable'] ); ?> />
								<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
								<span class="blt-toggle-text">
									<span class="blt-toggle-label"><?php echo esc_html( $role_state['name'] ); ?></span>
									<?php if ( ! $role_state['editable'] && '' !== $role_state['reason'] ) : ?>
										<span class="blt-toggle-desc"><?php echo esc_html( $role_state['reason'] ); ?></span>
									<?php endif; ?>
								</span>
							</label>
							<?php if ( $role_state['editable'] ) : ?>
								<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::SELF_SERVICE_SHOWN ); ?>[]" value="<?php echo esc_attr( $role_id ); ?>" />
								<?php if ( $role_state['on'] ) : ?>
									<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::SELF_SERVICE_WAS ); ?>[]" value="<?php echo esc_attr( $role_id ); ?>" />
								<?php endif; ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<p class="blt-field-desc"><?php esc_html_e( 'People with a role switched on here can change their own username on their profile. This is the "Change their own username" permission on each role, so the Roles screen shows it too. Saving takes a copy of the roles first and is recorded in the activity log.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><label for="blt-toolkit-cooldown"><?php esc_html_e( 'Wait between changes', 'blt-toolkit' ); ?></label></div>
			<div>
				<?php BLT_Toolkit_Admin_Settings::number( 'usernames', 'cooldown_days', $usernames['cooldown_days'], 'blt-toolkit-cooldown' ); ?>
				<?php esc_html_e( 'days', 'blt-toolkit' ); ?>
				<p class="blt-field-desc"><?php esc_html_e( 'How long someone must wait before changing their own username again. 0 means no wait. A change made for someone else is never held back.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Password', 'blt-toolkit' ); ?></div>
			<div>
				<?php
				BLT_Toolkit_Admin::toggle(
					$field . '[require_password]',
					$usernames['require_password'],
					__( 'Ask for their current password', 'blt-toolkit' ),
					__( 'Asked when people change their own username. People who sign in through single sign-on may not know a WordPress password, so leave this off on such sites.', 'blt-toolkit' )
				);
				?>
			</div>
		</div>
	</div>
</div>
