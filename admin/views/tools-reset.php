<?php
/**
 * Tools > Reset (RO-43): the two resets, the chosen one's dry run, then the
 * form that needs the typed word RESET.
 *
 * The preview is the role store's own dry run (BLT_Toolkit_Reset::plan()):
 * every role and capability that changes, for a full reset every custom role
 * that disappears with how many people have it, the Guard's verdict and the
 * SEC-10 / SEC-13 confirmations. The form carries the hash of the roles the
 * preview was made from. Reading this page changes nothing (RO-5).
 *
 * Included by BLT_Toolkit_Admin_Tools inside the screen's `.wrap`.
 *
 * @package Blt_Toolkit
 *
 * @var string     $mode      `builtin` or `full`.
 * @var array      $modes     Mode => array( 'title', 'desc', 'url' ).
 * @var string     $error     Why there is no plan, or ''.
 * @var array|null $plan      BLT_Toolkit_Reset::plan().
 * @var string     $diff_html What the reset changes, as escaped HTML.
 * @var array[]    $critical  SEC-10 rows (see tools-review.php).
 * @var string     $sync_url  The Sync administrator tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_word_id = 'blt-tk-reset-word';
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Reset roles', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Go back to WordPress\'s default roles. Choose a reset to see exactly what it changes; nothing is changed until you confirm. Your roles are saved as a snapshot first, so a reset can be undone from Snapshots.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php foreach ( $modes as $blt_tk_key => $blt_tk_mode ) : ?>
			<div class="blt-field">
				<div class="blt-field-label">
					<?php echo esc_html( $blt_tk_mode['title'] ); ?>
					<?php if ( BLT_Toolkit_Reset::MODE_BUILTIN === $blt_tk_key ) : ?>
						<?php echo BLT_Toolkit_Admin::badge( 'off', __( 'Default', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
					<?php endif; ?>
				</div>
				<div>
					<p><?php echo esc_html( $blt_tk_mode['desc'] ); ?></p>
					<?php if ( $blt_tk_key === $mode ) : ?>
						<?php echo BLT_Toolkit_Admin::badge( 'on', __( 'Previewed below', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( $blt_tk_mode['url'] ); ?>">
							<?php
							printf(
								/* translators: %s: name of the reset, such as Full reset. */
								esc_html__( 'Preview: %s', 'blt-toolkit' ),
								esc_html( $blt_tk_mode['title'] )
							);
							?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<?php if ( null === $plan ) : ?>
	<div class="blt-card">
		<div class="blt-card-body">
			<div class="blt-empty">
				<span class="blt-empty-title"><?php esc_html_e( 'The reset cannot be previewed', 'blt-toolkit' ); ?></span>
				<span><?php echo esc_html( $error ); ?></span>
			</div>
		</div>
	</div>
	<?php
	return;
endif;

$blt_tk_guard   = $plan['guard'];
$blt_tk_blocked = is_wp_error( $blt_tk_guard ) || $plan['in_code'] || $plan['unchanged'];
$blt_tk_lockout = ! $blt_tk_blocked && $plan['lockout'];
$blt_tk_counts  = $plan['counts'];
$blt_tk_full    = BLT_Toolkit_Reset::MODE_FULL === $plan['mode'];
$critical       = $blt_tk_blocked ? array() : $critical;
?>
<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
	<?php BLT_Toolkit_Admin::form_fields( BLT_Toolkit_Admin_Tools::RESET_ACTION ); ?>
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::MODE_ARG ); ?>" value="<?php echo esc_attr( $plan['mode'] ); ?>" />
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::EXPECTED_FIELD ); ?>" value="<?php echo esc_attr( $plan['expected'] ); ?>" />

	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				if ( $blt_tk_full ) {
					esc_html_e( 'What the full reset changes', 'blt-toolkit' );
				} else {
					esc_html_e( 'What resetting the built-in roles changes', 'blt-toolkit' );
				}
				?>
			</h2>
			<p><?php esc_html_e( 'Nothing is changed yet.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<?php if ( is_wp_error( $blt_tk_guard ) ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p>
						<?php
						printf(
							/* translators: %s: the reason. */
							esc_html__( 'You cannot make this reset: %s', 'blt-toolkit' ),
							esc_html( $blt_tk_guard->get_error_message() )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $plan['in_code'] ) : ?>
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Roles defined in code', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( 'This site defines its roles in code ($wp_user_roles), so they cannot be reset from here.', 'blt-toolkit' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $plan['unchanged'] ) : ?>
				<p><?php esc_html_e( 'Your roles already match WordPress\'s defaults, so this reset would change nothing.', 'blt-toolkit' ); ?></p>
			<?php else : ?>
				<?php
				$blt_tk_is_reset = true;
				require BLT_TOOLKIT_DIR . 'admin/views/tools-removed-roles.php';
				?>

				<div class="blt-field">
					<div class="blt-field-label"><?php esc_html_e( 'Every change', 'blt-toolkit' ); ?></div>
					<div>
						<p>
							<?php
							printf(
								/* translators: 1: a number of roles, such as 2 roles, 2: a number of capabilities, such as 14 capabilities. */
								esc_html__( 'This reset changes %1$s and %2$s. Below, "added" means the reset adds it, "removed" that the reset takes it away.', 'blt-toolkit' ),
								/* translators: %s: number of roles. */
								esc_html( sprintf( _n( '%s role', '%s roles', (int) $blt_tk_counts['roles'], 'blt-toolkit' ), number_format_i18n( (int) $blt_tk_counts['roles'] ) ) ),
								/* translators: %s: number of capabilities. */
								esc_html( sprintf( _n( '%s capability', '%s capabilities', (int) $blt_tk_counts['capabilities'], 'blt-toolkit' ), number_format_i18n( (int) $blt_tk_counts['capabilities'] ) ) )
							);
							?>
						</p>
						<?php echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in BLT_Toolkit_Admin_Activity::diff_html(). ?>
					</div>
				</div>

				<div class="blt-callout">
					<strong><?php esc_html_e( 'BLT Toolkit stays usable', 'blt-toolkit' ); ?></strong>
					<span>
						<?php esc_html_e( 'The Administrator role gets BLT Toolkit\'s capabilities back in the same step. Capabilities that other plugins gave to the roles reset are removed, as the list above shows; afterwards, Sync administrator can give the Administrator role the ones still registered.', 'blt-toolkit' ); ?>
						<a href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Sync administrator', 'blt-toolkit' ); ?></a>
					</span>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! $blt_tk_blocked ) : ?>
		<?php require BLT_TOOLKIT_DIR . 'admin/views/tools-review.php'; ?>

		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Confirm the reset', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Your roles as they are now are saved as a snapshot first, so this reset can be undone by restoring it from Snapshots.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<div class="blt-field">
					<div class="blt-field-label">
						<label for="<?php echo esc_attr( $blt_tk_word_id ); ?>">
							<?php
							printf(
								/* translators: %s: the word to type, RESET. */
								esc_html__( 'Type %s to confirm', 'blt-toolkit' ),
								'<strong>' . esc_html( BLT_Toolkit_Reset::WORD ) . '</strong>'
							);
							?>
						</label>
					</div>
					<div>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $blt_tk_word_id ); ?>" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::WORD_FIELD ); ?>" value="" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $blt_tk_word_id ); ?>-desc" />
						<p class="blt-field-desc" id="<?php echo esc_attr( $blt_tk_word_id ); ?>-desc">
							<?php esc_html_e( 'In capitals, exactly as shown.', 'blt-toolkit' ); ?>
							<?php if ( null !== $plan['no_role'] && (int) $plan['no_role'] > 0 ) : ?>
								<strong>
									<?php
									printf(
										/* translators: %s: number of people. */
										esc_html( _n( 'After this reset, %s person has no role.', 'After this reset, %s people have no role.', (int) $plan['no_role'], 'blt-toolkit' ) ),
										esc_html( number_format_i18n( (int) $plan['no_role'] ) )
									);
									?>
								</strong>
							<?php endif; ?>
						</p>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="blt-settings-footer">
		<a class="button" href="<?php echo esc_url( BLT_Toolkit_Admin_Tools::url( 'snapshots' ) ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		<?php if ( ! $blt_tk_blocked ) : ?>
			<button type="submit" class="button button-primary">
				<?php
				if ( $blt_tk_full ) {
					esc_html_e( 'Reset every role', 'blt-toolkit' );
				} else {
					esc_html_e( 'Reset the built-in roles', 'blt-toolkit' );
				}
				?>
			</button>
		<?php endif; ?>
	</div>
</form>
