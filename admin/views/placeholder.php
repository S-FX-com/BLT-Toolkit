<?php
/**
 * A screen whose features arrive in a later phase: page header, the state of
 * the module it belongs to, optional tabs, and one empty-state card per
 * feature saying what the screen will hold (SPEC 13.2). It shows no sample
 * data.
 *
 * @package Blt_Toolkit
 *
 * @var string                $page_class CSS class for the `.wrap`, such as `blt-toolkit-roles`.
 * @var string                $sub        Subtitle beside the title.
 * @var array                 $actions    Header buttons (see BLT_Toolkit_Admin::header()).
 * @var string                $module_key Module this screen belongs to, or ''.
 * @var string                $screen_key Screen key, for tab links.
 * @var array<string,string>  $tabs       Tab key => label, or empty for no tabs.
 * @var string                $tab        Open tab.
 * @var string                $tabs_label Accessible name for the tab bar.
 * @var array<int,array>      $cards      Each array( 'title', 'desc', 'empty_title', 'empty_text' ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap blt-ui blt-toolkit <?php echo esc_attr( $page_class ); ?>">
	<?php BLT_Toolkit_Admin::header( $sub, $actions ); ?>

	<?php if ( ! empty( $tabs ) ) : ?>
		<?php BLT_Toolkit_Admin::tabs( $screen_key, $tabs, $tab, $tabs_label ); ?>
	<?php endif; ?>

	<?php
	if ( '' !== $module_key ) {
		BLT_Toolkit_Admin::module_callout( $module_key );
	}
	?>

	<?php foreach ( $cards as $card ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php echo esc_html( $card['title'] ); ?></h2>
				<?php if ( '' !== $card['desc'] ) : ?>
					<p><?php echo esc_html( $card['desc'] ); ?></p>
				<?php endif; ?>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Coming soon', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-empty">
					<span class="blt-empty-title"><?php echo esc_html( $card['empty_title'] ); ?></span>
					<span><?php echo esc_html( $card['empty_text'] ); ?></span>
				</div>
			</div>
		</div>
	<?php endforeach; ?>
</div>
