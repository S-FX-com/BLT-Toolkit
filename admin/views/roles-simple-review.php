<?php
/**
 * Roles screen, Simple view: the review before a save (RS-6), its own page
 * in the PRG flow so it works without JavaScript.
 *
 * Shown when the change touches a Critical capability (with SEC-10's word
 * "Critical", what each granted one lets people do, and the role's name
 * typed to confirm), changes more than ten capabilities, replaces a Custom
 * control by taking capabilities away (RS-4), or would take the person's
 * own access to roles away while another administrator could give it back
 * (SEC-13). Each changed control reads "Events: Author (own only) → Edit
 * all", with what it adds and removes; the raw names are in a disclosure.
 *
 * The change itself waits on the server for an hour under the token this
 * form posts back; nothing the browser sends here can alter it.
 *
 * @package Blt_Toolkit
 *
 * @var string  $token            Pending review token.
 * @var string  $name             The role's display name (typed to confirm).
 * @var array[] $controls         Each: label, from, to, adds, removes (cap, label, tier), custom.
 * @var array[] $grants           Every capability granted: cap, label, tier.
 * @var array[] $removes          Every capability removed.
 * @var array[] $critical         Critical grants: cap, label, tier, consequence.
 * @var array[] $removed_critical Critical capabilities removed.
 * @var bool    $lockout          Whether the SEC-13 confirmation is needed.
 * @var bool    $mismatch         Whether the last attempt typed the wrong name.
 * @var string  $back_url         The editor, where the change can be made again.
 * @var int     $count            How many capabilities change.
 * @var int     $threshold        Changes above which a review is always shown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_confirm_id = 'blt-tk-simple-confirm';
$blt_tk_custom     = false;

foreach ( $controls as $blt_tk_control ) {
	$blt_tk_custom = $blt_tk_custom || $blt_tk_control['custom'];
}
?>
<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-review blt-toolkit-simple-review">
	<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_simple_save' ); ?>
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::PENDING_ARG ); ?>" value="<?php echo esc_attr( $token ); ?>" />

	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				printf(
					/* translators: %s: role name. */
					esc_html__( 'Review the change to the %s role', 'blt-toolkit' ),
					esc_html( $name )
				);
				?>
			</h2>
			<?php if ( array() !== $critical || array() !== $removed_critical ) : ?>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin_Roles::tier_badge( BLT_Toolkit_Caps::CRITICAL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: %s: number of capabilities. */
					esc_html( _n( 'Nothing is saved yet. This change touches %s capability:', 'Nothing is saved yet. This change touches %s capabilities:', (int) $count, 'blt-toolkit' ) ),
					esc_html( number_format_i18n( (int) $count ) )
				);
				?>
			</p>
		</div>
		<div class="blt-card-body">
			<?php if ( $mismatch ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p><?php esc_html_e( 'The name you typed does not match. Nothing was saved; type it again exactly as shown.', 'blt-toolkit' ); ?></p>
				</div>
			<?php endif; ?>

			<ul class="blt-toolkit-review-list blt-toolkit-simple-review-lines">
				<?php foreach ( $controls as $blt_tk_control ) : ?>
					<li>
						<strong>
							<?php
							/* translators: 1: control, such as Events, 2: state before, 3: state after. */
							printf( esc_html__( '%1$s: %2$s → %3$s.', 'blt-toolkit' ), esc_html( $blt_tk_control['label'] ), esc_html( $blt_tk_control['from'] ), esc_html( $blt_tk_control['to'] ) );
							?>
						</strong>
						<?php if ( array() !== $blt_tk_control['adds'] ) : ?>
							<?php
							/* translators: %s: comma-separated capability labels. */
							printf( esc_html__( 'Adds: %s.', 'blt-toolkit' ), esc_html( BLT_Toolkit_Admin_Roles::cap_words( wp_list_pluck( $blt_tk_control['adds'], 'cap' ) ) ) );
							?>
						<?php endif; ?>
						<?php if ( array() !== $blt_tk_control['removes'] ) : ?>
							<?php
							/* translators: %s: comma-separated capability labels. */
							printf( esc_html__( 'Removes: %s.', 'blt-toolkit' ), esc_html( BLT_Toolkit_Admin_Roles::cap_words( wp_list_pluck( $blt_tk_control['removes'], 'cap' ) ) ) );
							?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<details class="blt-toolkit-simple-raw">
				<summary><?php esc_html_e( 'Capability names', 'blt-toolkit' ); ?></summary>
				<?php
				foreach (
					array(
						array( $grants, __( 'Granted', 'blt-toolkit' ) ),
						array( $removes, __( 'Removed', 'blt-toolkit' ) ),
					) as $blt_tk_group
				) :
					?>
					<?php if ( array() !== $blt_tk_group[0] ) : ?>
						<div class="blt-field">
							<div class="blt-field-label"><?php echo esc_html( $blt_tk_group[1] ); ?></div>
							<ul class="blt-toolkit-review-list">
								<?php foreach ( $blt_tk_group[0] as $blt_tk_cap ) : ?>
									<li>
										<code class="blt-chip"><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code>
										<?php echo esc_html( $blt_tk_cap['label'] ); ?>
										<?php echo BLT_Toolkit_Admin_Roles::tier_badge( $blt_tk_cap['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</details>
		</div>
	</div>

	<?php if ( $blt_tk_custom ) : ?>
		<div class="blt-callout">
			<strong><?php esc_html_e( 'Replacing a Custom setting', 'blt-toolkit' ); ?></strong>
			<span><?php esc_html_e( 'A control that showed Custom or Partly allowed is being set to a level or switched off. The capabilities listed as removed will be taken away from this role.', 'blt-toolkit' ); ?></span>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $removed_critical && array() === $critical ) : ?>
		<div class="blt-callout">
			<strong><?php esc_html_e( 'Critical', 'blt-toolkit' ); ?></strong>
			<span>
				<?php
				printf(
					/* translators: %s: comma-separated capability labels. */
					esc_html__( 'This takes away Critical capabilities: %s. People with this role may lose access they rely on.', 'blt-toolkit' ),
					esc_html( implode( ', ', wp_list_pluck( $removed_critical, 'label' ) ) )
				);
				?>
			</span>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $critical ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Critical capabilities', 'blt-toolkit' ); ?></h2>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin_Roles::tier_badge( BLT_Toolkit_Caps::CRITICAL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
				<p><?php esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts. Everyone who has this role will be able to:', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php foreach ( $critical as $blt_tk_cap ) : ?>
					<div class="blt-field">
						<div class="blt-field-label">
							<?php echo esc_html( $blt_tk_cap['label'] ); ?>
							<code class="blt-chip"><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code>
						</div>
						<div><?php echo esc_html( $blt_tk_cap['consequence'] ); ?></div>
					</div>
				<?php endforeach; ?>
				<div class="blt-field">
					<div class="blt-field-label"><label for="<?php echo esc_attr( $blt_tk_confirm_id ); ?>"><?php esc_html_e( 'Type the role name to confirm', 'blt-toolkit' ); ?></label></div>
					<div>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $blt_tk_confirm_id ); ?>" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::CONFIRM_FIELD ); ?>" value="" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $blt_tk_confirm_id ); ?>-desc" />
						<p class="blt-field-desc" id="<?php echo esc_attr( $blt_tk_confirm_id ); ?>-desc">
							<?php
							printf(
								/* translators: %s: role name. */
								esc_html__( 'Type %s exactly as shown.', 'blt-toolkit' ),
								'<strong>' . esc_html( $name ) . '</strong>'
							);
							?>
						</p>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $lockout ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'You will lose access to roles', 'blt-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'After this change you can no longer edit roles, including to undo it. Another administrator can give the access back.', 'blt-toolkit' ); ?></p>
			</div>
			<div class="blt-card-body">
				<?php BLT_Toolkit_Admin::toggle( BLT_Toolkit_Admin_Roles::LOCKOUT_FIELD, false, __( 'I understand that I will lose access to the Roles screen', 'blt-toolkit' ) ); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( (int) $count > (int) $threshold ) : ?>
		<div class="blt-callout">
			<strong><?php esc_html_e( 'A large change', 'blt-toolkit' ); ?></strong>
			<span>
				<?php
				printf(
					/* translators: %s: number of capabilities. */
					esc_html__( 'Changes to more than %s capabilities are shown for review before they are saved.', 'blt-toolkit' ),
					esc_html( number_format_i18n( (int) $threshold ) )
				);
				?>
			</span>
		</div>
	<?php endif; ?>

	<div class="blt-settings-footer">
		<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></a>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the change', 'blt-toolkit' ); ?></button>
	</div>
</form>
