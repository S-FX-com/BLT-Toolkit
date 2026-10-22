<?php
/**
 * Settings > General: the module toggles (SPEC 4.1, settings group `modules`).
 *
 * Included inside the settings form by views/settings.php.
 *
 * @package Blt_Toolkit
 *
 * @var array<string,array> $modules BLT_Toolkit_Admin::modules().
 * @var string              $field   Name of the settings field array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Modules', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Switch off what this site does not need. A module that is off loads no code. Protection for administrators and the activity log always run, whatever is switched off here.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php foreach ( $modules as $key => $module ) : ?>
			<div class="blt-field">
				<div class="blt-field-label"><?php echo esc_html( $module['label'] ); ?></div>
				<div>
					<?php
					BLT_Toolkit_Admin::toggle(
						$field . '[' . $key . ']',
						$module['enabled'],
						/* translators: %s: module name, such as Switch. */
						sprintf( __( '%s module', 'blt-toolkit' ), $module['label'] ),
						$module['description']
					);
					?>
					<p class="blt-field-desc">
						<?php esc_html_e( 'Right now:', 'blt-toolkit' ); ?>
						<?php echo BLT_Toolkit_Admin::status_badge( $module['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
					</p>
					<?php BLT_Toolkit_Admin::module_note( $module ); ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
