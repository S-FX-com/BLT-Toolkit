<?php
/**
 * Settings > Switching (SPEC 7, settings group `switch`).
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
				__( 'Adds "Switch to customer" on orders and "Switch back" in My Account, and starts a fresh cart session on each switch. Applies only while WooCommerce is active.', 'blt-toolkit' )
			);
			?>
		</div>
	</div>
	<?php BLT_Toolkit_Admin_Settings::later( __( 'A one-click Support Agent role that can switch only into people with less access than itself, created after a preview.', 'blt-toolkit' ) ); ?>
</div>
