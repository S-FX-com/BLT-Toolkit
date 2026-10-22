<?php
/**
 * Page header shared by every screen: the BLT mark, the title, a subtitle,
 * optional action buttons, and the notice from the last redirect.
 *
 * @package Blt_Toolkit
 *
 * @var string     $sub     Subtitle beside the title.
 * @var array      $actions Header buttons: each array( 'label' => '', 'url' => '', 'primary' => false ).
 * @var array|null $notice  Notice from BLT_Toolkit_Admin::notice().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-admin-page-header">
	<h1>
		<?php
		// Pre-built, KSES-filtered SVG markup from the shared brand class.
		echo BLT_Family_Brand::inline_mark( BLT_TOOLKIT_DIR ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitized in inline_mark().
		?>
		<?php esc_html_e( 'BLT Toolkit', 'blt-toolkit' ); ?>
		<?php if ( '' !== $sub ) : ?>
			<span class="blt-admin-page-header-sub"><?php echo esc_html( $sub ); ?></span>
		<?php endif; ?>
	</h1>
	<?php if ( ! empty( $actions ) ) : ?>
		<div class="blt-admin-page-actions">
			<?php foreach ( $actions as $button ) : ?>
				<a class="button<?php echo ! empty( $button['primary'] ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $button['url'] ); ?>"><?php echo esc_html( $button['label'] ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<hr class="wp-header-end" />

<?php if ( $notice ) : ?>
	<?php // `inline` keeps core's script from moving the notice above the page header. ?>
	<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> inline blt-toolkit-notice" role="status">
		<p><?php echo esc_html( $notice['message'] ); ?></p>
	</div>
<?php endif; ?>
