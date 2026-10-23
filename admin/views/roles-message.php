<?php
/**
 * Roles screen: a message instead of a page (a user who does not exist, a
 * review that expired, a change the Guard refuses).
 *
 * @package Blt_Toolkit
 *
 * @var string $title    Heading.
 * @var string $text     Explanation.
 * @var string $back_url Where to go back to, or ''.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card">
	<div class="blt-card-body">
		<div class="blt-empty">
			<span class="blt-empty-title"><?php echo esc_html( $title ); ?></span>
			<span><?php echo esc_html( $text ); ?></span>
			<?php if ( '' !== $back_url ) : ?>
				<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to roles', 'blt-toolkit' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</div>
