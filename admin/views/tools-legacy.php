<?php
/**
 * Tools > Import from legacy plugins: one card per plugin BLT Toolkit
 * replaces, with its state, what was imported from it and a link to the
 * preview of its import (SPEC 15).
 *
 * Opening this tab changes nothing, here or in the other plugins' data.
 *
 * Included by BLT_Toolkit_Admin_Legacy::render() inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var array  $state   BLT_Toolkit_Legacy::setup_state().
 * @var array  $history Module => item => "Imported ... by ...".
 * @var string $job     ID of the live audit log job, or ''.
 * @var array  $urls    Module => preview URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_cards = array(
	'usernames' => array(
		'desc' => __( 'Its settings (minimum length, the notification email, who may change their own username) and its audit log of username changes.', 'blt-toolkit' ),
	),
	'roles'     => array(
		'desc' => __( 'Roles need no import: they live in WordPress itself. What is imported: the other default roles, the roles whose admin bar is hidden, and the backup of the roles it took when it was first activated. Its own ure_* capabilities can be removed afterwards.', 'blt-toolkit' ),
	),
);
?>
<div class="blt-callout">
	<strong><?php esc_html_e( 'Read-only imports', 'blt-toolkit' ); ?></strong>
	<span><?php esc_html_e( 'Each import shows what it would change first, and never changes or deletes the other plugin\'s data. Importing twice does not duplicate anything.', 'blt-toolkit' ); ?></span>
</div>

<?php foreach ( $blt_tk_cards as $blt_tk_key => $blt_tk_card ) : ?>
	<?php $blt_tk_source = $state[ $blt_tk_key ]; ?>
	<div class="blt-card blt-toolkit-legacy-card">
		<div class="blt-card-header">
			<h2><?php echo esc_html( $blt_tk_source['name'] ); ?></h2>
			<p><?php echo esc_html( $blt_tk_card['desc'] ); ?></p>
			<div class="blt-card-header-badges">
				<?php
				if ( $blt_tk_source['active'] ) {
					echo BLT_Toolkit_Admin::badge( 'pending', __( 'Active', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}

				if ( $blt_tk_source['partly'] ) {
					echo BLT_Toolkit_Admin::badge( 'pending', __( 'Partly imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				} elseif ( $blt_tk_source['imported'] ) {
					echo BLT_Toolkit_Admin::badge( 'confirmed', __( 'Imported', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				} elseif ( $blt_tk_source['data'] ) {
					echo BLT_Toolkit_Admin::badge( 'on', __( 'Data found', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				} else {
					echo BLT_Toolkit_Admin::badge( 'off', __( 'No data found', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}

				if ( 'usernames' === $blt_tk_key && '' !== $job ) {
					echo BLT_Toolkit_Admin::badge( 'pending', __( 'Importing now', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				}
				?>
			</div>
		</div>
		<div class="blt-card-body">
			<?php if ( $blt_tk_source['active'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: plugin name, such as Username Changer. */
						esc_html__( '%s is active, so the BLT Toolkit module that replaces it is not running. Import first, then deactivate it.', 'blt-toolkit' ),
						esc_html( $blt_tk_source['name'] )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( 'roles' === $blt_tk_key && $blt_tk_source['data'] ) : ?>
				<p><?php esc_html_e( 'Import before you delete User Role Editor: deleting it removes its settings and its roles backup. Deactivating it keeps them.', 'blt-toolkit' ); ?></p>
			<?php endif; ?>

			<?php if ( array() !== $history[ $blt_tk_key ] ) : ?>
				<ul class="blt-toolkit-review-list">
					<?php foreach ( $history[ $blt_tk_key ] as $blt_tk_item => $blt_tk_line ) : ?>
						<li><strong><?php echo esc_html( BLT_Toolkit_Admin_Legacy::item_label( $blt_tk_item ) ); ?>:</strong> <?php echo esc_html( $blt_tk_line ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $blt_tk_source['data'] || $blt_tk_source['active'] || $blt_tk_source['imported'] ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $urls[ $blt_tk_key ] ); ?>"><?php esc_html_e( 'Preview the import', 'blt-toolkit' ); ?></a></p>
			<?php else : ?>
				<div class="blt-empty">
					<span class="blt-empty-title">
						<?php
						printf(
							/* translators: %s: plugin name, such as Username Changer. */
							esc_html__( 'Nothing from %s on this site', 'blt-toolkit' ),
							esc_html( $blt_tk_source['name'] )
						);
						?>
					</span>
					<span><?php esc_html_e( 'Its settings are not in the database, so there is nothing to import.', 'blt-toolkit' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>

<div class="blt-card blt-toolkit-legacy-card">
	<div class="blt-card-header">
		<h2><?php echo esc_html( $state['switch']['name'] ); ?></h2>
		<p><?php esc_html_e( 'It keeps nothing on the server, so there is nothing to import.', 'blt-toolkit' ); ?></p>
		<div class="blt-card-header-badges">
			<?php
			if ( $state['switch']['active'] ) {
				echo BLT_Toolkit_Admin::badge( 'pending', __( 'Active', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			} else {
				echo BLT_Toolkit_Admin::badge( 'off', __( 'Not active', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			}
			?>
		</div>
	</div>
	<div class="blt-card-body">
		<p><?php esc_html_e( 'Before you deactivate User Switching, ask everyone who is switched into another account to switch back first. Its switch cookies mean nothing to BLT Toolkit, so after the change they would stay signed in as the other person with no way back except signing out.', 'blt-toolkit' ); ?></p>
	</div>
</div>
