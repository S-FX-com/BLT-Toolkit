<?php
/**
 * The Overview screen.
 *
 * @package Blt_Toolkit
 *
 * @var array<string,array> $modules  BLT_Toolkit_Admin::modules().
 * @var int                 $running  Modules running on this request.
 * @var array<string,string> $roles   Role ID => display name.
 * @var bool                $recovery Whether BLT_TOOLKIT_RECOVERY is set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap blt-ui blt-toolkit blt-toolkit-overview">
	<?php
	BLT_Toolkit_Admin::header(
		__( 'Overview', 'blt-toolkit' ),
		array(
			array(
				'label' => __( 'Settings', 'blt-toolkit' ),
				'url'   => BLT_Toolkit_Admin::page_url( 'settings' ),
			),
		)
	);
	?>

	<?php if ( $recovery ) : ?>
		<div class="blt-callout">
			<strong><?php esc_html_e( 'Recovery mode is on', 'blt-toolkit' ); ?></strong>
			<span>
				<?php
				printf(
					/* translators: %s: constant name. */
					esc_html__( '%s is set in wp-config.php. Remove that line as soon as administrators can manage roles again; it is meant for emergencies only.', 'blt-toolkit' ),
					'<code>BLT_TOOLKIT_RECOVERY</code>'
				);
				?>
			</span>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_save_modules' ); ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Modules', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Switch off what this site does not need. A module that is off loads no code. Protection for administrators and the activity log are still to come; once they arrive, they run whatever is switched off here.', 'blt-toolkit' ); ?></p>
				<div class="blt-card-header-badges">
					<?php
					$running_badge = BLT_Toolkit_Admin::badge(
						$running > 0 ? 'on' : 'off',
						sprintf(
							/* translators: 1: modules running, 2: modules in total. */
							__( '%1$d of %2$d running', 'blt-toolkit' ),
							$running,
							count( $modules )
						)
					);

					echo $running_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
					?>
				</div>
			</div>
			<div class="blt-card-body">
				<div class="blt-select-cards blt-toolkit-module-cards">
					<?php foreach ( $modules as $key => $module ) : ?>
						<div class="blt-select-card blt-toolkit-module-card<?php echo 'on' === $module['status'] ? ' is-selected' : ''; ?>">
							<span class="blt-toolkit-module-head">
								<span class="blt-select-card-name"><?php echo esc_html( $module['label'] ); ?></span>
								<?php echo BLT_Toolkit_Admin::status_badge( $module['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
							</span>
							<?php if ( '' !== $module['description'] ) : ?>
								<span class="blt-select-card-desc"><?php echo esc_html( $module['description'] ); ?></span>
							<?php endif; ?>
							<?php BLT_Toolkit_Admin::module_note( $module ); ?>
							<span class="blt-toolkit-module-toggle">
								<?php
								BLT_Toolkit_Admin::toggle(
									'blt_toolkit[' . $key . ']',
									$module['enabled'],
									/* translators: %s: module name, such as Switch. */
									sprintf( __( '%s module', 'blt-toolkit' ), $module['label'] )
								);
								?>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary blt-save-button"><?php esc_html_e( 'Save modules', 'blt-toolkit' ); ?></button>
		</div>
	</form>

	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'At a glance', 'blt-toolkit' ); ?></h2>
		</div>
		<div class="blt-card-body">
			<div class="blt-stats">
				<div class="blt-stat">
					<span class="blt-stat-label"><?php esc_html_e( 'Roles', 'blt-toolkit' ); ?></span>
					<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( count( $roles ) ) ); ?></span>
					<span class="blt-stat-sub"><?php esc_html_e( 'defined on this site', 'blt-toolkit' ); ?></span>
				</div>
				<div class="blt-stat">
					<span class="blt-stat-label"><?php esc_html_e( 'Modules running', 'blt-toolkit' ); ?></span>
					<span class="blt-stat-value"><?php echo esc_html( number_format_i18n( $running ) ); ?></span>
					<span class="blt-stat-sub">
						<?php
						printf(
							/* translators: %s: number of modules. */
							esc_html__( 'of %s', 'blt-toolkit' ),
							esc_html( number_format_i18n( count( $modules ) ) )
						);
						?>
					</span>
				</div>
				<div class="blt-stat">
					<span class="blt-stat-label"><?php esc_html_e( 'Version', 'blt-toolkit' ); ?></span>
					<span class="blt-stat-value"><?php echo esc_html( BLT_TOOLKIT_VERSION ); ?></span>
				</div>
			</div>
		</div>
	</div>
</div>
