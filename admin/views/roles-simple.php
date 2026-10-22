<?php
/**
 * Roles screen, Simple view (SPEC 10). Simple view arrives with Phase 5;
 * until then this card says so, and points to Advanced view for those who
 * may use it.
 *
 * @package Blt_Toolkit
 *
 * @var array  $state The request state.
 * @var string $name  Role display name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Simple view', 'blt-toolkit' ); ?></h2>
		<div class="blt-card-header-badges">
			<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Coming soon', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
		</div>
	</div>
	<div class="blt-card-body">
		<div class="blt-empty">
			<span class="blt-empty-title"><?php esc_html_e( 'Simple view arrives with Phase 5', 'blt-toolkit' ); ?></span>
			<span>
				<?php
				printf(
					/* translators: %s: role name. */
					esc_html__( 'Simple view will show what %s can do as one plain-English choice per content type and area of the site, such as "Events: Edit all".', 'blt-toolkit' ),
					esc_html( $name )
				);
				?>
			</span>
			<?php if ( $state['can_advanced'] ) : ?>
				<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin_Roles::role_url( $state['role'], array( 'view' => 'advanced' ) ) ); ?>"><?php esc_html_e( 'Use Advanced view', 'blt-toolkit' ); ?></a>
			<?php else : ?>
				<span><?php esc_html_e( 'Until then, the role\'s description and toolbar setting can be changed below.', 'blt-toolkit' ); ?></span>
			<?php endif; ?>
		</div>
	</div>
</div>
