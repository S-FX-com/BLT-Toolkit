<?php
/**
 * Settings > Switching (SPEC 7, settings group `switch`).
 *
 * Included inside the settings form by views/settings.php.
 *
 * @package Blt_Toolkit
 *
 * @var array  $settings      BLT_Toolkit_Settings::all().
 * @var string $field         Name of the settings field array.
 * @var array  $support_agent BLT_Toolkit_Admin_Settings::support_agent_state().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$switch = $settings['switch'];
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Switching', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'How switching to another user\'s account looks and behaves. Who may switch is decided by the "Switch to users" capability, and only administrators can ever switch into an administrator.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-toggle-stack">
			<?php
			BLT_Toolkit_Admin::toggle(
				$field . '[footer_link]',
				$switch['footer_link'],
				__( 'Show a "Switch back" link on the site', 'blt-toolkit' ),
				__( 'While you are switched and the toolbar is hidden, a small fixed link on the site takes you back to your own account.', 'blt-toolkit' )
			);
			BLT_Toolkit_Admin::toggle(
				$field . '[switch_off]',
				$switch['switch_off'],
				__( 'Offer "Switch Off" in the toolbar', 'blt-toolkit' ),
				__( 'Signs you out for a moment to see the site as a visitor. The login screen and the site then offer to switch back to you.', 'blt-toolkit' )
			);
			BLT_Toolkit_Admin::toggle(
				$field . '[search]',
				$switch['search'],
				__( 'Search for a user to switch to from the toolbar', 'blt-toolkit' ),
				__( 'A "Switch to…" item in the toolbar finds people by name. It lists only users you are allowed to switch to.', 'blt-toolkit' )
			);
			BLT_Toolkit_Admin::toggle(
				$field . '[compat_hooks]',
				$switch['compat_hooks'],
				__( 'Work with tools made for User Switching', 'blt-toolkit' ),
				__( 'Also announces each switch the way User Switching does, so activity logs and debugging tools that support User Switching keep working without changes.', 'blt-toolkit' )
			);
			BLT_Toolkit_Admin::toggle(
				$field . '[woocommerce]',
				$switch['woocommerce'],
				__( 'WooCommerce', 'blt-toolkit' ),
				__( 'Adds "Switch to customer" on orders and "Switch back" in My Account, and keeps each user's cart and WooCommerce session separate when you switch. Applies only while WooCommerce is active.', 'blt-toolkit' )
			);
			?>
		</div>
	</div>
</div>

<div class="blt-card blt-toolkit-support-agent">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Support Agent role', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'A ready-made role for people who help others with their accounts: it can sign in, see the list of users and switch to users who have no more access than itself. Administrators stay out of its reach.', 'blt-toolkit' ); ?></p>
		<?php if ( ! empty( $support_agent['exists'] ) ) : ?>
			<div class="blt-card-header-badges">
				<?php echo BLT_Toolkit_Admin::badge( 'on', __( 'Created', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			</div>
		<?php endif; ?>
	</div>
	<div class="blt-card-body">
		<?php if ( ! empty( $support_agent['exists'] ) ) : ?>
			<p class="blt-field-desc"><?php esc_html_e( 'The role exists. Give it to someone from their profile or from the Users screen.', 'blt-toolkit' ); ?></p>
		<?php elseif ( ! empty( $support_agent['can_create'] ) ) : ?>
			<p class="blt-field-desc"><?php esc_html_e( 'Nothing changes until you confirm: the next page shows exactly what the role can do and whom it can switch into.', 'blt-toolkit' ); ?></p>
			<p class="blt-stack-top">
				<a class="button" href="<?php echo esc_url( $support_agent['preview_url'] ); ?>"><?php esc_html_e( 'Create Support Agent role…', 'blt-toolkit' ); ?></a>
			</p>
		<?php else : ?>
			<p class="blt-field-desc"><?php esc_html_e( 'Creating roles needs the "Create roles" permission, which your account does not have.', 'blt-toolkit' ); ?></p>
		<?php endif; ?>
	</div>
</div>
