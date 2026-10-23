<?php
/**
 * Tools screen: the one-time notice of the last POST (a restore, a reset, a
 * sync, or a refusal with the Guard's reason).
 *
 * Included by BLT_Toolkit_Admin_Tools::print_notice().
 *
 * @package Blt_Toolkit
 *
 * @var string   $type    `success`, `warning` or `error`.
 * @var string   $message Message.
 * @var string[] $items   List under the message.
 * @var array    $link    Optional array( 'url', 'label' ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice notice-<?php echo esc_attr( $type ); ?> inline blt-toolkit-notice" role="<?php echo 'error' === $type ? 'alert' : 'status'; ?>">
	<p><?php echo esc_html( $message ); ?></p>
	<?php if ( array() !== $items ) : ?>
		<p class="blt-chips">
			<?php foreach ( $items as $blt_tk_item ) : ?>
				<code class="blt-chip"><?php echo esc_html( (string) $blt_tk_item ); ?></code>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $link['url'] ) ) : ?>
		<p><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></p>
	<?php endif; ?>
</div>
