<?php
/**
 * Tools: the confirmations a restore or a reset may need, inside its form.
 *
 * - SEC-10: each role that would be granted Critical capabilities, with the
 *   word "Critical", what each capability lets people do, and the role's
 *   display name typed to confirm.
 * - SEC-13: the change takes away the person's own ability to edit roles,
 *   and another administrator could give it back.
 *
 * Required by tools-snapshot-restore.php and tools-reset.php, which set the
 * variables below.
 *
 * @package Blt_Toolkit
 *
 * @var array[] $critical       Each array( 'role', 'label', 'caps' => array of array( 'cap', 'label', 'tier', 'consequence' ) ).
 * @var bool    $blt_tk_lockout Whether the SEC-13 confirmation is needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( array() !== $critical ) : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'Critical capabilities', 'blt-toolkit' ); ?></h2>
			<div class="blt-card-header-badges">
				<?php echo BLT_Toolkit_Admin_Tools::tier_badge( BLT_Toolkit_Caps::CRITICAL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			</div>
			<p><?php esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts. Afterwards, everyone who has these roles will be able to do what is listed. Type each role\'s name to confirm.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<?php foreach ( $critical as $blt_tk_i => $blt_tk_role ) : ?>
				<?php $blt_tk_input = 'blt-tk-tools-confirm-' . (int) $blt_tk_i; ?>
				<div class="blt-field">
					<div class="blt-field-label">
						<?php echo esc_html( $blt_tk_role['label'] ); ?>
						<code class="blt-chip"><?php echo esc_html( $blt_tk_role['role'] ); ?></code>
					</div>
					<div>
						<ul class="blt-toolkit-review-list">
							<?php foreach ( $blt_tk_role['caps'] as $blt_tk_cap ) : ?>
								<li>
									<?php echo esc_html( $blt_tk_cap['label'] ); ?>
									<code class="blt-chip"><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code>
									<br /><span class="blt-field-desc"><?php echo esc_html( $blt_tk_cap['consequence'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
						<label for="<?php echo esc_attr( $blt_tk_input ); ?>">
							<?php
							printf(
								/* translators: %s: role name. */
								esc_html__( 'Type %s to confirm', 'blt-toolkit' ),
								'<strong>' . esc_html( $blt_tk_role['label'] ) . '</strong>'
							);
							?>
						</label>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $blt_tk_input ); ?>" name="<?php echo esc_attr( BLT_Toolkit_Admin_Tools::CONFIRM_FIELD . '[' . $blt_tk_role['role'] . ']' ); ?>" value="" autocomplete="off" spellcheck="false" required />
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( $blt_tk_lockout ) : ?>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2><?php esc_html_e( 'You will lose access to roles', 'blt-toolkit' ); ?></h2>
			<p><?php esc_html_e( 'After this change you can no longer edit roles, including to undo it. Another administrator can give the access back.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-card-body">
			<?php BLT_Toolkit_Admin::toggle( BLT_Toolkit_Admin_Tools::LOCKOUT_FIELD, false, __( 'I understand that I will lose access to the Roles screen', 'blt-toolkit' ) ); ?>
		</div>
	</div>
<?php endif; ?>
