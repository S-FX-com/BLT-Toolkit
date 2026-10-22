<?php
/**
 * Settings > Advanced: uninstall behavior, the GitHub update token, updates,
 * and the wp-config.php recovery switches (SEC-14, SEC-15).
 *
 * Included inside the settings form by views/settings.php. The Test
 * connection and Remove token buttons belong to two small forms printed after
 * the main form, through their `form` attribute, so no form is nested.
 *
 * @package Blt_Toolkit
 *
 * @var array    $settings     BLT_Toolkit_Settings::all().
 * @var string   $field        Name of the settings field array.
 * @var string   $token_status `constant`, `stored`, `undecryptable`, `shared` or `none`.
 * @var string   $update_url   Nonced "Check for Updates" URL, or ''.
 * @var int      $last_check   Time of the last update check, or 0.
 * @var string[] $disabled     Modules named in BLT_TOOLKIT_DISABLE_MODULES.
 * @var bool     $recovery     Whether BLT_TOOLKIT_RECOVERY is set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$advanced = $settings['advanced'];

switch ( $token_status ) {
	case 'constant':
		$token_badge = BLT_Toolkit_Admin::badge( 'on', __( 'Set in wp-config.php', 'blt-toolkit' ) );
		break;
	case 'stored':
		$token_badge = BLT_Toolkit_Admin::badge( 'on', __( 'Token stored', 'blt-toolkit' ) );
		break;
	case 'shared':
		$token_badge = BLT_Toolkit_Admin::badge( 'on', __( 'Shared BLT token', 'blt-toolkit' ) );
		break;
	case 'undecryptable':
		$token_badge = BLT_Toolkit_Admin::badge( 'pending', __( 'Needs re-entry', 'blt-toolkit' ) );
		break;
	default:
		$token_badge = BLT_Toolkit_Admin::badge( 'off', __( 'No token', 'blt-toolkit' ) );
}

$can_remove   = in_array( $token_status, array( 'stored', 'undecryptable' ), true );
$date_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$recovery_php = "define( 'BLT_TOOLKIT_RECOVERY', true );";
$disable_php  = "define( 'BLT_TOOLKIT_DISABLE_MODULES', 'switch,usernames' );";
?>
<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Uninstall', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'What happens when BLT Toolkit is deleted from the Plugins screen. Deactivating it never deletes anything.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<?php
		BLT_Toolkit_Admin::toggle(
			$field . '[delete_data_on_uninstall]',
			$advanced['delete_data_on_uninstall'],
			__( 'Delete all plugin data on uninstall', 'blt-toolkit' ),
			__( 'Off by default, so deleting and reinstalling the plugin loses nothing.', 'blt-toolkit' )
		);
		?>
		<p class="blt-field-desc">
			<span class="blt-text-danger"><?php esc_html_e( 'Warning:', 'blt-toolkit' ); ?></span>
			<?php esc_html_e( 'with this on, deleting the plugin permanently removes the activity log, every role snapshot including the baseline, the settings and the stored token, and takes every BLT Toolkit capability off every role and user. It cannot be undone. Your roles, and the changes you made to them, stay as they are.', 'blt-toolkit' ); ?>
		</p>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Updates', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'BLT Toolkit checks GitHub for a new version once a day, at midnight site time. A token is needed only while the repository is private.', 'blt-toolkit' ); ?></p>
		<div class="blt-card-header-badges">
			<?php echo $token_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
		</div>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label">
				<?php if ( 'constant' === $token_status ) : ?>
					<?php esc_html_e( 'GitHub token', 'blt-toolkit' ); ?>
				<?php else : ?>
					<label for="blt-toolkit-github-token"><?php esc_html_e( 'GitHub token', 'blt-toolkit' ); ?></label>
				<?php endif; ?>
			</div>
			<div>
				<?php if ( 'constant' === $token_status ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: constant name. */
							esc_html__( 'Set by %s in wp-config.php, which takes precedence over a token saved here. Change it there.', 'blt-toolkit' ),
							'<code>BLT_TOOLKIT_GITHUB_TOKEN</code>'
						);
						?>
					</p>
				<?php else : ?>
					<input type="password" id="blt-toolkit-github-token" class="regular-text" name="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::TOKEN_FIELD ); ?>" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( $can_remove ? __( 'Saved. Leave blank to keep it', 'blt-toolkit' ) : __( 'Not set', 'blt-toolkit' ) ); ?>" />
					<?php if ( 'undecryptable' === $token_status ) : ?>
						<p class="blt-field-desc blt-text-danger"><?php esc_html_e( 'The stored token can no longer be read, because the security keys in wp-config.php changed since it was saved. Enter it again.', 'blt-toolkit' ); ?></p>
					<?php elseif ( 'shared' === $token_status ) : ?>
						<p class="blt-field-desc"><?php esc_html_e( 'Using the token from the shared BLT settings. A token saved here takes precedence over it.', 'blt-toolkit' ); ?></p>
					<?php endif; ?>
					<p class="blt-field-desc"><?php esc_html_e( 'A fine-grained personal access token with read-only Contents access to the repository is enough. It is stored encrypted with your site\'s security keys and never shown again. A blank field keeps the stored token.', 'blt-toolkit' ); ?></p>
				<?php endif; ?>
				<p class="blt-stack-top">
					<button type="submit" class="button" form="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::FORM_TEST ); ?>"><?php esc_html_e( 'Test connection', 'blt-toolkit' ); ?></button>
					<?php if ( $can_remove ) : ?>
						<button type="submit" class="button" form="<?php echo esc_attr( BLT_Toolkit_Admin_Settings::FORM_REMOVE ); ?>"><?php esc_html_e( 'Remove token', 'blt-toolkit' ); ?></button>
					<?php endif; ?>
				</p>
				<p class="blt-field-desc">
					<?php
					printf(
						/* translators: %s: GitHub repository, such as S-FX-com/BLT-Toolkit. */
						esc_html__( 'Test connection asks GitHub for %s with the token update checks would use, and reports the answer. Save a new token first to test it.', 'blt-toolkit' ),
						'<code>' . esc_html( BLT_Toolkit_Admin_Settings::repository() ) . '</code>'
					);
					?>
				</p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label"><?php esc_html_e( 'Version', 'blt-toolkit' ); ?></div>
			<div>
				<p>
					<?php
					printf(
						/* translators: %s: plugin version. */
						esc_html__( 'Installed version: %s', 'blt-toolkit' ),
						esc_html( BLT_TOOLKIT_VERSION )
					);
					?>
				</p>
				<?php if ( '' !== $update_url ) : ?>
					<p class="blt-stack-top"><a class="button" href="<?php echo esc_url( $update_url ); ?>"><?php esc_html_e( 'Check for Updates', 'blt-toolkit' ); ?></a></p>
				<?php endif; ?>
				<p class="blt-field-desc">
					<?php
					if ( $last_check > 0 ) {
						printf(
							/* translators: %s: date and time of the last update check. */
							esc_html__( 'Last checked %s. Checking now runs at once and ends on the Plugins screen with the result.', 'blt-toolkit' ),
							esc_html( wp_date( $date_format, $last_check ) )
						);
					} else {
						esc_html_e( 'Not checked yet. Checking now runs at once and ends on the Plugins screen with the result.', 'blt-toolkit' );
					}
					?>
				</p>
			</div>
		</div>
	</div>
</div>

<div class="blt-card">
	<div class="blt-card-header">
		<h2><?php esc_html_e( 'Recovery', 'blt-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Lines in wp-config.php that work even when you cannot open this screen: one gets administrators back in after a role change locks them out, the other switches off a module that misbehaves.', 'blt-toolkit' ); ?></p>
	</div>
	<div class="blt-card-body">
		<div class="blt-field">
			<div class="blt-field-label">
				<?php esc_html_e( 'Restore administrator access', 'blt-toolkit' ); ?>
				<?php if ( $recovery ) : ?>
					<br /><?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Set now', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				<?php endif; ?>
			</div>
			<div>
				<code class="blt-code"><?php echo esc_html( $recovery_php ); ?></code>
				<p class="blt-field-desc"><?php esc_html_e( 'Add it, then open any admin page as a user with the Administrator role. BLT Toolkit takes a snapshot, gives the Administrator role back the capabilities it needs to manage the site and every BLT Toolkit capability, and records it in the activity log. Nothing else changes. Remove the line afterwards; a notice reminds you while it is there.', 'blt-toolkit' ); ?></p>
			</div>
		</div>
		<div class="blt-field">
			<div class="blt-field-label">
				<?php esc_html_e( 'Switch modules off', 'blt-toolkit' ); ?>
				<?php if ( ! empty( $disabled ) ) : ?>
					<br /><?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Set now', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
				<?php endif; ?>
			</div>
			<div>
				<code class="blt-code"><?php echo esc_html( $disable_php ); ?></code>
				<p class="blt-field-desc">
					<?php
					printf(
						/* translators: 1: module key, 2: module key, 3: module key. */
						esc_html__( 'Turns off the named modules whatever the settings say, for example during an incident. Use any of %1$s, %2$s and %3$s, separated by commas. Protection for administrators and the activity log keep running.', 'blt-toolkit' ),
						'<code>switch</code>',
						'<code>usernames</code>',
						'<code>roles</code>'
					);
					?>
				</p>
				<?php if ( ! empty( $disabled ) ) : ?>
					<p class="blt-field-desc">
						<?php
						printf(
							/* translators: %s: comma-separated module keys. */
							esc_html__( 'Switched off this way right now: %s', 'blt-toolkit' ),
							'<code>' . esc_html( implode( ', ', $disabled ) ) . '</code>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
