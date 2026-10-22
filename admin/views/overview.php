<?php
/**
 * The Overview screen (SPEC 13.2).
 *
 * @package Blt_Toolkit
 *
 * @var array<string,array>  $modules  BLT_Toolkit_Admin::modules().
 * @var int                  $running  Modules running on this request.
 * @var array<string,string> $roles    Role ID => display name.
 * @var bool                 $recovery Whether BLT_TOOLKIT_RECOVERY is set.
 * @var array                $activity BLT_Toolkit_Admin_Overview::activity_counts(): switches, renames (null when unknown).
 * @var array                $links    Tile => Activity screen URL, or ''.
 * @var array[]              $setup    BLT_Toolkit_Admin_Overview::setup_lines().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_tiles = array(
	'switches' => array(
		'label' => __( 'Switches', 'blt-toolkit' ),
		'sub'   => __( 'into another account, last 30 days', 'blt-toolkit' ),
	),
	'renames'  => array(
		'label' => __( 'Username changes', 'blt-toolkit' ),
		'sub'   => __( 'last 30 days', 'blt-toolkit' ),
	),
);

/**
 * A11Y-2: what a tile's link counts, read by screen readers after the
 * number, in the plural form that number needs.
 *
 * @param string $key   Tile key.
 * @param int    $count The number shown.
 * @return string
 */
$blt_tk_tile_sr = static function ( $key, $count ) {
	if ( 'switches' === $key ) {
		return _n( 'switch into another account in the last 30 days', 'switches into another account in the last 30 days', $count, 'blt-toolkit' );
	}

	return _n( 'username change in the last 30 days', 'username changes in the last 30 days', $count, 'blt-toolkit' );
};
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
					esc_html__( '%s is set in wp-config.php: whenever an administrator opens an admin page, the Administrator role gets back any capability it needs to manage the site, or any BLT Toolkit capability, that it lacks. Remove that line as soon as administrators can manage roles again; it is meant for emergencies only.', 'blt-toolkit' ),
					'<code>BLT_TOOLKIT_RECOVERY</code>'
				);
				?>
			</span>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $setup ) : ?>
		<div class="blt-card blt-toolkit-setup-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Moving from other plugins', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'BLT Toolkit replaces User Switching, Username Changer and User Role Editor. Roles need no import: they live in WordPress itself. Imports show what they would change first and never change the other plugin\'s data.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $setup as $blt_tk_line ) : ?>
					<div class="blt-toolkit-setup-line">
						<p><?php echo esc_html( $blt_tk_line['text'] ); ?></p>
						<?php if ( '' !== $blt_tk_line['url'] ) : ?>
							<p><a class="button" href="<?php echo esc_url( $blt_tk_line['url'] ); ?>"><?php echo esc_html( $blt_tk_line['label'] ); ?></a></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_save_modules' ); ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Modules', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Switch off what this site does not need. A module that is off loads no code. Protection for administrators and the activity log always run, whatever is switched off here.', 'blt-toolkit' ); ?></p>
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
				<?php
				// US-12: counted only when someone asks (the tile's Count button).
				if ( BLT_Toolkit_Plugin::is_active( 'roles' ) ) {
					BLT_Toolkit_User_Roles::render_overview_tile();
				}
				?>
				<?php foreach ( $blt_tk_tiles as $blt_tk_key => $blt_tk_tile ) : ?>
					<div class="blt-stat">
						<span class="blt-stat-label"><?php echo esc_html( $blt_tk_tile['label'] ); ?></span>
						<span class="blt-stat-value">
							<?php if ( null === $activity[ $blt_tk_key ] ) : ?>
								<span aria-hidden="true">&mdash;</span><span class="screen-reader-text"><?php esc_html_e( 'Not available', 'blt-toolkit' ); ?></span>
							<?php elseif ( '' !== $links[ $blt_tk_key ] && $activity[ $blt_tk_key ] > 0 ) : ?>
								<a href="<?php echo esc_url( $links[ $blt_tk_key ] ); ?>"><?php echo esc_html( number_format_i18n( $activity[ $blt_tk_key ] ) ); ?><span class="screen-reader-text"> <?php echo esc_html( $blt_tk_tile_sr( $blt_tk_key, (int) $activity[ $blt_tk_key ] ) ); ?></span></a>
							<?php else : ?>
								<?php echo esc_html( number_format_i18n( $activity[ $blt_tk_key ] ) ); ?>
							<?php endif; ?>
						</span>
						<span class="blt-stat-sub"><?php echo esc_html( $blt_tk_tile['sub'] ); ?></span>
					</div>
				<?php endforeach; ?>
				<div class="blt-stat">
					<span class="blt-stat-label"><?php esc_html_e( 'Version', 'blt-toolkit' ); ?></span>
					<span class="blt-stat-value"><?php echo esc_html( BLT_TOOLKIT_VERSION ); ?></span>
				</div>
			</div>
		</div>
	</div>

	<?php
	// US-12, JOB-4: the count job's progress while it runs on a large site.
	if ( BLT_Toolkit_Plugin::is_active( 'roles' ) ) {
		BLT_Toolkit_User_Roles::render_overview_job();
	}
	?>
</div>
