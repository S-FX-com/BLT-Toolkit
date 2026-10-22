<?php
/**
 * Tools > Sync administrator (RO-5): the registered capabilities the
 * Administrator role lacks, then one button that grants exactly those.
 *
 * Opening this tab changes nothing: BLT Toolkit never grants capabilities
 * while it builds a list (SPEC 19, URE-2). The form carries a hash of the
 * list shown, so a list that changed in between grants nothing.
 *
 * Included by BLT_Toolkit_Admin_Tools inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var bool       $available Whether the Roles module runs (the catalog is its code).
 * @var string     $error     Why there is no plan, or ''.
 * @var array|null $plan      BLT_Toolkit_Sync::plan().
 * @var array[]    $grant     Rows of capabilities to grant: cap, label, tier, source.
 * @var array[]    $denied    Rows of capabilities the role holds as denied.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $available ) {
	BLT_Toolkit_Admin::module_callout( 'roles' );
}

$blt_tk_guard   = is_array( $plan ) ? $plan['guard'] : true;
$blt_tk_can_run = is_array( $plan ) && ! $plan['in_code'] && array() !== $grant && ! is_wp_error( $blt_tk_guard );
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Sync administrator', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Give the Administrator role every registered capability it does not have: WordPress\'s own, those of active content types and taxonomies, BLT Toolkit\'s, and those plugins declare. Opening this tab changes nothing; only the button below does.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php if ( ! $available ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'Not available while the Roles module is off', 'blt-toolkit' ); ?></span>
				<span><?php esc_html_e( 'Sync uses the Roles module\'s list of registered capabilities. Turn the module on to use it.', 'blt-toolkit' ); ?></span>
			</div>
		<?php elseif ( null === $plan ) : ?>
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'Sync cannot be previewed', 'blt-toolkit' ); ?></span>
				<span><?php echo esc_html( $error ); ?></span>
			</div>
		<?php else : ?>
			<?php if ( is_wp_error( $blt_tk_guard ) ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p>
						<?php
						printf(
							/* translators: %s: the reason. */
							esc_html__( 'You cannot sync the Administrator role: %s', 'blt-toolkit' ),
							esc_html( $blt_tk_guard->get_error_message() )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $plan['in_code'] ) : ?>
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Roles defined in code', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( 'This site defines its roles in code ($wp_user_roles), so the Administrator role cannot be changed from here.', 'blt-toolkit' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( array() === $grant ) : ?>
				<div class="blt-empty">
					<span class="blt-empty-title"><?php esc_html_e( 'Nothing to sync', 'blt-toolkit' ); ?></span>
					<span><?php esc_html_e( 'The Administrator role already has every registered capability.', 'blt-toolkit' ); ?></span>
				</div>
			<?php else : ?>
				<div class="blt-field">
					<div class="blt-field-label">
						<?php
						printf(
							/* translators: %s: number of capabilities. */
							esc_html( _n( '%s capability the Administrator role lacks', '%s capabilities the Administrator role lacks', count( $grant ), 'blt-toolkit' ) ),
							esc_html( number_format_i18n( count( $grant ) ) )
						);
						?>
					</div>
					<ul class="blt-toolkit-review-list">
						<?php foreach ( $grant as $blt_tk_cap ) : ?>
							<li>
								<?php echo esc_html( $blt_tk_cap['label'] ); ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code>
								<?php echo BLT_Toolkit_Admin_Tools::tier_badge( $blt_tk_cap['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								<?php if ( '' !== $blt_tk_cap['source'] ) : ?>
									<span class="blt-toolkit-muted"><?php echo esc_html( $blt_tk_cap['source'] ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( array() !== $denied ) : ?>
				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Denied on purpose', 'blt-toolkit' ); ?></div>
					<div>
						<p><?php esc_html_e( 'The Administrator role holds these as denied, which someone chose, so sync leaves them as they are. Change them in the Roles screen\'s Advanced view.', 'blt-toolkit' ); ?></p>
						<p class="blt-chips">
							<?php foreach ( $denied as $blt_tk_cap ) : ?>
								<code class="blt-chip"><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code>
							<?php endforeach; ?>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<p class="blt-field-desc"><?php esc_html_e( 'Never added: orphaned capabilities (registered by nothing active), the deprecated user levels, capabilities used only on multisite networks, and read-only markers.', 'blt-toolkit' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php if ( $blt_tk_can_run ) : ?>
	<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
		<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Tools::SYNC_ACTION ); ?>
		<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::EXPECTED_FIELD ); ?>" value="<?php echo esc_attr( $plan['expected'] ); ?>" />
		<div class="blt-settings-footer">
			<button type="submit" class="button button-primary">
				<?php
				printf(
					/* translators: %s: number of capabilities. */
					esc_html( _n( 'Give the Administrator role %s capability', 'Give the Administrator role %s capabilities', count( $grant ), 'blt-toolkit' ) ),
					esc_html( number_format_i18n( count( $grant ) ) )
				);
				?>
			</button>
		</div>
	</form>
<?php endif; ?>
