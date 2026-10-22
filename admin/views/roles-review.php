<?php
/**
 * Roles screen: the review before a save, its own page so it works without
 * JavaScript. Shown when the change grants Critical capabilities to a role
 * other than Administrator or to a user (SEC-10: the word "Critical", what
 * each lets people do, and the role's display name, or the username, typed
 * to confirm), when it would take away the person's own access to roles and
 * another administrator could give it back (SEC-13), or when it changes more
 * than ten capabilities.
 *
 * The change itself waits on the server for an hour under the token this
 * form posts back; nothing the browser sends here can alter it.
 *
 * @package Blt_Toolkit
 *
 * @var string  $kind      `role` or `user`.
 * @var string  $token     Pending review token.
 * @var string  $name      What must be typed (the role's display name, or the username).
 * @var string  $subject   Role ID, or "Display Name (login)".
 * @var array[] $grants    Capabilities granted: cap, label, tier.
 * @var array[] $denies    Capabilities denied.
 * @var array[] $removes   Capabilities removed (role), or set back to Inherit (user).
 * @var array[] $critical  Critical grants: cap, label, tier, consequence.
 * @var bool    $lockout   Whether the SEC-13 confirmation is needed.
 * @var bool    $mismatch  Whether the last attempt typed the wrong name.
 * @var string  $action    admin-post.php action to post to.
 * @var string  $back_url  The editor, where the change can be made again.
 * @var int     $count     How many capabilities change.
 * @var int     $threshold Changes above which a review is always shown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_confirm_id = 'blt-tk-review-confirm';
$blt_tk_groups     = array(
	array( $grants, __( 'Granted', 'blt-toolkit' ) ),
	array( $denies, __( 'Denied', 'blt-toolkit' ) ),
	array( $removes, 'user' === $kind ? __( 'Back to what their roles give', 'blt-toolkit' ) : __( 'Removed', 'blt-toolkit' ) ),
);
?>
<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-review">
	<?php BLT_Toolkit_Admin::form_fields( $action ); ?>
	<input type="hidden" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::PENDING_ARG ); ?>" value="<?php echo esc_attr( $token ); ?>" />

	<div class="blt-card">
		<div class="blt-card-header">
			<h2>
				<?php
				if ( 'user' === $kind ) {
					printf(
						/* translators: %s: user's display name and username. */
						esc_html__( 'Review the change to %s', 'blt-toolkit' ),
						esc_html( $subject )
					);
				} else {
					printf(
						/* translators: %s: role name. */
						esc_html__( 'Review the change to the %s role', 'blt-toolkit' ),
						esc_html( $name )
					);
				}
				?>
			</h2>
			<?php if ( array() !== $critical ) : ?>
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

			<?php foreach ( $blt_tk_groups as $blt_tk_group ) : ?>
				<?php if ( array() !== $blt_tk_group[0] ) : ?>
					<div class="blt-field">
						<div class="blt-field-label"><?php echo esc_html( $blt_tk_group[1] ); ?></div>
						<ul class="blt-toolkit-review-list">
							<?php foreach ( $blt_tk_group[0] as $blt_tk_cap ) : ?>
								<li>
									<?php echo esc_html( $blt_tk_cap['label'] ); ?>
									<code class="blt-chip"><?php echo esc_html( $blt_tk_cap['cap'] ); ?></code>
									<?php echo BLT_Toolkit_Admin_Roles::tier_badge( $blt_tk_cap['tier'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ( array() !== $critical ) : ?>
		<div class="blt-card">
			<div class="blt-card-header">
				<h2><?php esc_html_e( 'Critical capabilities', 'blt-toolkit' ); ?></h2>
				<div class="blt-card-header-badges">
					<?php echo BLT_Toolkit_Admin_Roles::tier_badge( BLT_Toolkit_Caps::CRITICAL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				</div>
				<p>
					<?php
					if ( 'user' === $kind ) {
						esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts. This user will be able to:', 'blt-toolkit' );
					} else {
						esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts. Everyone who has this role will be able to:', 'blt-toolkit' );
					}
					?>
				</p>
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
					<div class="blt-field-label">
						<label for="<?php echo esc_attr( $blt_tk_confirm_id ); ?>">
							<?php
							if ( 'user' === $kind ) {
								esc_html_e( 'Type the username to confirm', 'blt-toolkit' );
							} else {
								esc_html_e( 'Type the role name to confirm', 'blt-toolkit' );
							}
							?>
						</label>
					</div>
					<div>
						<input type="text" class="regular-text" id="<?php echo esc_attr( $blt_tk_confirm_id ); ?>" name="<?php echo esc_attr( BLT_Toolkit_Admin_Roles::CONFIRM_FIELD ); ?>" value="" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $blt_tk_confirm_id ); ?>-desc" />
						<p class="blt-field-desc" id="<?php echo esc_attr( $blt_tk_confirm_id ); ?>-desc">
							<?php
							printf(
								/* translators: %s: role name or username. */
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

	<?php if ( array() === $critical && ! $lockout ) : ?>
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
