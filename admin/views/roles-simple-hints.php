<?php
/**
 * Roles screen, Simple view: a control's dependency hints (RS-7, RS-17).
 *
 * A hint shows while the control is on (a content row at or above the
 * hint's level) and the control it usually needs is off. It names that
 * control and links to it, so it works without JavaScript; the script keeps
 * each hint current as controls change and shows "Also allow", which turns
 * the needed control on in one click. Nothing is ever added silently.
 *
 * @package Blt_Toolkit
 *
 * @var array[] $hints Each: dom, text, needs, needs_dom, needs_label, when (control ID => lowest level), active, can_allow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( $hints as $blt_tk_hint ) :
	?>
	<p class="blt-toolkit-simple-hint" id="<?php echo esc_attr( $blt_tk_hint['dom'] ); ?>" data-blt-hint data-needs="<?php echo esc_attr( $blt_tk_hint['needs'] ); ?>" data-when="<?php echo esc_attr( (string) wp_json_encode( $blt_tk_hint['when'] ) ); ?>"<?php echo $blt_tk_hint['active'] ? '' : ' hidden'; ?>>
		<span class="blt-toolkit-simple-hint-text"><?php echo esc_html( $blt_tk_hint['text'] ); ?></span>
		<a href="<?php echo esc_attr( '#' . $blt_tk_hint['needs_dom'] ); ?>">
			<?php
			/* translators: %s: Simple view control, such as "See the list of users". */
			printf( esc_html__( 'Go to "%s"', 'blt-toolkit' ), esc_html( $blt_tk_hint['needs_label'] ) );
			?>
		</a>
		<?php if ( $blt_tk_hint['can_allow'] ) : ?>
			<button type="button" class="button button-small" data-blt-also-allow="<?php echo esc_attr( $blt_tk_hint['needs'] ); ?>" hidden>
				<?php
				/* translators: %s: Simple view control, such as "See the list of users". */
				echo wp_kses( sprintf( __( 'Also allow<span class="screen-reader-text">: %s</span>', 'blt-toolkit' ), esc_html( $blt_tk_hint['needs_label'] ) ), array( 'span' => array( 'class' => true ) ) );
				?>
			</button>
		<?php endif; ?>
	</p>
	<?php
endforeach;
