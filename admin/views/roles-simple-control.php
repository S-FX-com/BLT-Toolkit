<?php
/**
 * Roles screen, Simple view: one control of a card other than Content
 * (Appendix A): a `.blt-toggle` with its tier badge (SEC-11), the raw
 * capability names, notes and RS-7 hints; or the "Other plugin permissions"
 * summary.
 *
 * A toggle posts `simple[ID]`: `on` from the checkbox, otherwise the hidden
 * field before it, which holds `off`, or `indeterminate` ("leave it as it
 * is") for a toggle whose capabilities are only partly granted (RS-4). The
 * script draws that state as an indeterminate checkbox; a click then turns
 * the whole control on, and a second click off. A locked or read-only
 * control has no hidden field, so it posts nothing.
 *
 * @package Blt_Toolkit
 *
 * @var array $control The control's view (BLT_Toolkit_Admin_Roles::simple_control_view()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_input = $control['dom'] . '-input';
$blt_tk_field = 'simple[' . $control['id'] . ']';
?>
<?php if ( BLT_Toolkit_Simple_Controls::SUMMARY === $control['kind'] ) : ?>
	<div class="blt-toolkit-simple-control blt-toolkit-simple-summary" id="<?php echo esc_attr( $control['dom'] ); ?>" tabindex="-1">
		<p>
			<?php
			if ( 0 === (int) $control['count'] ) {
				esc_html_e( 'No other plugin on this site registers capabilities Simple view has no control for.', 'blt-toolkit' );
			} else {
				printf(
					/* translators: %s: number of capabilities. */
					esc_html( _n( '%s capability from other plugins.', '%s capabilities from other plugins.', (int) $control['count'], 'blt-toolkit' ) ),
					esc_html( number_format_i18n( (int) $control['count'] ) )
				);
				echo ' ';

				if ( '' !== $control['advanced_url'] ) {
					echo '<a href="' . esc_url( $control['advanced_url'] ) . '">' . esc_html__( 'Manage in Advanced.', 'blt-toolkit' ) . '</a>';
				} else {
					esc_html_e( 'Someone with the "Edit capabilities" permission can manage them in Advanced view.', 'blt-toolkit' );
				}
			}
			?>
		</p>
		<?php if ( (int) $control['count'] > 0 ) : ?>
			<p class="blt-toolkit-note">
				<?php
				printf(
					/* translators: %s: number of capabilities. */
					esc_html__( 'This role has %s of them.', 'blt-toolkit' ),
					esc_html( number_format_i18n( (int) $control['held'] ) )
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;
?>
<div class="blt-toolkit-simple-control<?php echo $control['disabled'] ? ' is-locked' : ''; ?>" id="<?php echo esc_attr( $control['dom'] ); ?>" tabindex="-1" data-blt-control="<?php echo esc_attr( $control['id'] ); ?>" data-kind="<?php echo esc_attr( $control['kind'] ); ?>">
	<?php if ( ! $control['disabled'] ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $blt_tk_field ); ?>" value="<?php echo esc_attr( $control['drawn'] ); ?>" data-blt-drawn />
	<?php endif; ?>
	<label class="blt-toggle">
		<input type="checkbox" name="<?php echo esc_attr( $blt_tk_field ); ?>" value="on" id="<?php echo esc_attr( $blt_tk_input ); ?>" <?php checked( $control['checked'] ); ?> <?php disabled( $control['disabled'] ); ?><?php echo $control['indeterminate'] ? ' data-indeterminate="1"' : ''; ?> />
		<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
		<span class="blt-toggle-text">
			<span class="blt-toggle-label"><?php echo esc_html( $control['label'] ); ?></span>
			<?php if ( '' !== $control['help'] ) : ?>
				<span class="blt-toggle-desc"><?php echo esc_html( $control['help'] ); ?></span>
			<?php endif; ?>
		</span>
	</label>
	<div class="blt-toolkit-simple-meta">
		<?php
		if ( '' !== $control['tier'] ) {
			echo BLT_Toolkit_Admin_Roles::tier_badge( $control['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
		}

		foreach ( $control['badges'] as $blt_tk_badge ) {
			echo BLT_Toolkit_Admin::badge( $blt_tk_badge[0], $blt_tk_badge[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
		}
		?>
		<?php foreach ( $control['caps'] as $blt_tk_cap ) : ?>
			<code class="blt-chip"><?php echo esc_html( $blt_tk_cap ); ?></code>
		<?php endforeach; ?>
		<?php if ( '' !== $control['advanced_url'] && ( $control['indeterminate'] || count( $control['caps'] ) > 1 ) ) : ?>
			<a class="blt-toolkit-simple-advanced" href="<?php echo esc_url( $control['advanced_url'] ); ?>">
				<?php
				/* translators: %s: Simple view control. */
				echo wp_kses( sprintf( __( 'Edit in Advanced<span class="screen-reader-text">: %s</span>', 'blt-toolkit' ), esc_html( $control['label'] ) ), array( 'span' => array( 'class' => true ) ) );
				?>
			</a>
		<?php endif; ?>
	</div>
	<?php if ( $control['indeterminate'] && ! $control['disabled'] ) : ?>
		<p class="blt-toolkit-note blt-toolkit-simple-partly"><?php esc_html_e( 'Only some of these are allowed now. Leave it as it is to keep them, switch it on to allow all of them, or use Edit in Advanced to choose one by one.', 'blt-toolkit' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $control['note'] ) : ?>
		<p class="blt-toolkit-note"><?php echo esc_html( $control['note'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $control['locked'] ) : ?>
		<p class="blt-toolkit-note"><?php echo esc_html( $control['locked'] ); ?></p>
	<?php endif; ?>
	<?php
	BLT_Toolkit_Admin_Roles::partial(
		'roles-simple-hints',
		array(
			'hints' => $control['hints'],
		)
	);
	?>
</div>
