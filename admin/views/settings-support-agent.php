<?php
/**
 * Settings > Switching > Create Support Agent role: the preview step (SW-21).
 *
 * Shows the role's capabilities with their risk tiers, the Critical review
 * and typed confirmation (SEC-10), and which of the site's roles a Support
 * Agent can and cannot switch into (SEC-1, SEC-2). Rendering it changes
 * nothing (RO-5); the form posts to admin-post.php.
 *
 * @package Blt_Toolkit
 *
 * @var string               $tab     Open tab (`switching`).
 * @var array<string,string> $tabs    Tab key => label.
 * @var array                $actions Header buttons.
 * @var array                $preview BLT_Toolkit_Admin_Settings::support_agent_preview().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tier_badges = array(
	'critical' => array( 'cancelled', __( 'Critical', 'blt-toolkit' ) ),
	'elevated' => array( 'pending', __( 'Elevated', 'blt-toolkit' ) ),
	'standard' => array( 'off', __( 'Standard', 'blt-toolkit' ) ),
);
$confirm_id  = 'blt-toolkit-confirm-role';
$cancel_url  = BLT_Toolkit_Admin::page_url( 'settings', array( 'tab' => 'switching' ) );
?>
<div class="wrap blt-ui blt-toolkit blt-toolkit-settings">
	<?php BLT_Toolkit_Admin::header( __( 'Settings', 'blt-toolkit' ), $actions ); ?>

	<?php BLT_Toolkit_Admin::tabs( 'settings', $tabs, $tab, __( 'Settings sections', 'blt-toolkit' ) ); ?>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="blt-toolkit-support-agent-form">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_create_support_agent' ); ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Create the Support Agent role', 'blt-toolkit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: role name, 2: role ID. */
						esc_html__( 'Adds a role named %1$s (%2$s) with the three capabilities below. Nobody has the role until you give it to them.', 'blt-toolkit' ),
						'<strong>' . esc_html( $preview['name'] ) . '</strong>',
						'<code>' . esc_html( BLT_Toolkit_Admin_Settings::SUPPORT_AGENT_ROLE ) . '</code>'
					);
					?>
				</p>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $preview['caps'] as $row ) : ?>
					<?php $badge = isset( $tier_badges[ $row['tier'] ] ) ? $tier_badges[ $row['tier'] ] : $tier_badges['standard']; ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<?php echo esc_html( $row['label'] ); ?>
							<?php echo BLT_Toolkit_Admin::badge( $badge[0], $badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
						</div>
						<div>
							<code><?php echo esc_html( $row['cap'] ); ?></code>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Whom a Support Agent can switch into', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Switching is allowed only into people who have no capability the Support Agent lacks, counting every role they hold and any capability given to them alone. Administrators are always out of reach.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Can switch into', 'blt-toolkit' ); ?></div>
					<div>
						<?php if ( empty( $preview['reachable'] ) ) : ?>
							<p class="blt-field-desc"><?php esc_html_e( 'No role on this site is limited enough today, so a Support Agent could switch only into other Support Agents.', 'blt-toolkit' ); ?></p>
						<?php else : ?>
							<p>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: comma-separated role names, such as "Subscriber, Customer". */
										__( 'People whose roles are only: %s, and other Support Agents.', 'blt-toolkit' ),
										implode( wp_get_list_item_separator(), $preview['reachable'] )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Still prevented', 'blt-toolkit' ); ?></div>
					<div>
						<ul class="ul-disc blt-toolkit-support-agent-blocked">
							<?php foreach ( $preview['blocked'] as $blocked ) : ?>
								<li>
									<?php
									$role_name = '<strong>' . esc_html( $blocked['name'] ) . '</strong>';

									if ( 'SEC-1' === $blocked['rule'] ) {
										printf(
											/* translators: %s: role name, such as Administrator. */
											esc_html__( '%s: administrators can never be switched into by anyone who is not an administrator.', 'blt-toolkit' ),
											$role_name // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
										);
									} else {
										printf(
											/* translators: 1: role name, such as Editor; 2: number of capabilities. */
											esc_html( _n( '%1$s: holds %2$s capability a Support Agent does not, so it is out of reach.', '%1$s: holds %2$s capabilities a Support Agent does not, so it is out of reach.', (int) $blocked['missing'], 'blt-toolkit' ) ),
											$role_name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
											esc_html( number_format_i18n( (int) $blocked['missing'] ) )
										);
									}
									?>
								</li>
							<?php endforeach; ?>
							<li><?php esc_html_e( 'Anyone given a capability of their own that a Support Agent lacks, whatever their role.', 'blt-toolkit' ); ?></li>
							<li><?php esc_html_e( 'Editing users, changing roles or settings: the role has only the three capabilities above.', 'blt-toolkit' ); ?></li>
						</ul>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Confirm', 'blt-toolkit' ); ?></h2>
			</div>
			<div class="blt-card-body">
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Critical capability', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( '"Switch to users" lets a Support Agent sign in as another person and act with that person\'s access until they switch back. Each switch, and any change made with BLT Toolkit while switched, is logged under the Support Agent\'s own name.', 'blt-toolkit' ); ?></span>
				</div>
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="<?php echo esc_attr( $confirm_id ); ?>"><?php esc_html_e( 'Type the role name to confirm', 'blt-toolkit' ); ?></label>
					</div>
					<div>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $confirm_id ); ?>" name="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::SUPPORT_AGENT_CONFIRM ); ?>" value="" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $confirm_id ); ?>-desc" />
						<p class="blt-field-desc" id="<?php echo esc_attr( $confirm_id ); ?>-desc">
							<?php
							printf(
								/* translators: %s: role name, such as Support Agent. */
								esc_html__( 'Type %s exactly as shown.', 'blt-toolkit' ),
								'<strong>' . esc_html( $preview['name'] ) . '</strong>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Support Agent role', 'blt-toolkit' ); ?></button>
			<a class="button" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		</div>
	</form>
</div>
