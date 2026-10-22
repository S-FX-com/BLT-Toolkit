<?php
/**
 * Roles screen, Simple view (SPEC 10, 13.2): cards in order (Basics,
 * Content, Media and comments, Appearance, Plugins, Site, People, BLT
 * Toolkit, then any section another plugin adds, then Other plugin
 * permissions), and the sticky save bar.
 *
 * Without JavaScript this is a plain form: every control posts its state,
 * and the server works out which controls changed (RS-5) from the role as it
 * is now, which the render-time hash pins (RO-2). A toggle posts `off` (or,
 * when it is partly allowed, `indeterminate`, meaning "leave it") from a
 * hidden field before its checkbox. Locked and read-only controls post
 * nothing, so they can never change. With JavaScript (assets/js/
 * blt-toolkit-roles.js): a change counter, level help and modifiers that
 * follow the selected level, RS-7 hints with "Also allow", only changed
 * controls posted, the RS-6 review in a dialog, the RS-2 save-or-discard
 * question when switching views, and a `beforeunload` guard.
 *
 * @package Blt_Toolkit
 *
 * @var array  $state  The request state.
 * @var string $name   Role display name.
 * @var array  $simple BLT_Toolkit_Admin_Roles::simple_data().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $simple['available'] ) ) {
	BLT_Toolkit_Admin_Roles::partial(
		'roles-message',
		array(
			'title'    => __( 'Simple view is not available', 'blt-toolkit' ),
			'text'     => __( 'Part of BLT Toolkit that Simple view needs is missing. Reinstall the plugin, or use Advanced view.', 'blt-toolkit' ),
			'back_url' => '',
		)
	);

	return;
}

$blt_tk_form_id  = 'blt-tk-simple-form';
$blt_tk_editable = ! $simple['read_only'];
?>
<div class="blt-toolkit-simple-wrap blt-toolkit-matrix-wrap" data-blt-simple-wrap>
	<?php if ( '' !== $simple['reason'] ) : ?>
		<div class="blt-callout blt-toolkit-simple-readonly">
			<strong><?php esc_html_e( 'Read only', 'blt-toolkit' ); ?></strong>
			<span><?php echo esc_html( $simple['reason'] ); ?></span>
			<?php if ( $simple['protected'] && '' !== $simple['advanced'] ) : ?>
				<span><a href="<?php echo esc_url( $simple['advanced'] ); ?>"><?php esc_html_e( 'Open the Administrator role in Advanced view', 'blt-toolkit' ); ?></a></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" id="<?php echo esc_attr( $blt_tk_form_id ); ?>" class="blt-toolkit-simple-form" data-blt-simple>
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_simple_save' ); ?>
		<input type="hidden" name="role" value="<?php echo esc_attr( $simple['role'] ); ?>" />
		<input type="hidden" name="hash" value="<?php echo esc_attr( $simple['hash'] ); ?>" />
		<input type="hidden" name="back" value="<?php echo esc_attr( $simple['back'] ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::REVIEWED_FIELD ); ?>" value="0" data-blt-reviewed />
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::CONFIRM_FIELD ); ?>" value="" data-blt-confirm />
		<input type="hidden" name="then_view" value="" data-blt-then-view />
		<input type="hidden" name="then_ctl" value="" data-blt-then-ctl />

		<?php foreach ( $simple['sections'] as $blt_tk_section ) : ?>
			<?php $blt_tk_heading = 'blt-tk-section-' . $blt_tk_section['id'] . '-title'; ?>
			<section class="blt-card blt-toolkit-simple-card" id="<?php echo esc_attr( 'blt-tk-section-' . $blt_tk_section['id'] ); ?>" aria-labelledby="<?php echo esc_attr( $blt_tk_heading ); ?>">
				<div class="blt-card-header">
					<h2 id="<?php echo esc_attr( $blt_tk_heading ); ?>"><?php echo esc_html( $blt_tk_section['label'] ); ?></h2>
					<?php if ( '' !== $blt_tk_section['desc'] ) : ?>
						<p><?php echo esc_html( $blt_tk_section['desc'] ); ?></p>
					<?php endif; ?>
				</div>
				<div class="blt-card-body">
					<?php
					if ( 'content' === $blt_tk_section['id'] ) {
						BLT_Toolkit_Admin_Roles::partial(
							'roles-simple-content',
							array(
								'rows'         => $blt_tk_section['controls'],
								'unregistered' => isset( $simple['unregistered'] ) ? $simple['unregistered'] : array(),
							)
						);
					} else {
						foreach ( $blt_tk_section['controls'] as $blt_tk_control ) {
							BLT_Toolkit_Admin_Roles::partial(
								'roles-simple-control',
								array(
									'control' => $blt_tk_control,
								)
							);
						}
					}
					?>
				</div>
			</section>
		<?php endforeach; ?>
	</form>

	<?php if ( $blt_tk_editable ) : ?>
		<div class="blt-toolkit-savebar" data-blt-savebar>
			<p class="blt-toolkit-change-count" data-blt-count aria-live="polite"><?php esc_html_e( 'Your changes are checked before they are saved.', 'blt-toolkit' ); ?></p>
			<span class="blt-toolkit-savebar-actions">
				<a class="button" href="<?php echo esc_url( $simple['discard'] ); ?>" data-blt-discard><?php esc_html_e( 'Discard', 'blt-toolkit' ); ?></a>
				<button type="submit" class="button button-primary blt-save-button" form="<?php echo esc_attr( $blt_tk_form_id ); ?>" data-blt-simple-submit><?php esc_html_e( 'Review and save', 'blt-toolkit' ); ?></button>
			</span>
			<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::END_FIELD ); ?>" value="1" form="<?php echo esc_attr( $blt_tk_form_id ); ?>" />
		</div>

		<script type="application/json" id="blt-tk-simple-data"><?php echo wp_json_encode( $simple['script'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON read by the script; JSON_HEX_TAG keeps it inside this element. ?></script>

		<?php
		BLT_Toolkit_Admin_Roles::partial(
			'roles-simple-dialogs',
			array(
				'name' => $name,
			)
		);
		?>
	<?php endif; ?>
</div>
