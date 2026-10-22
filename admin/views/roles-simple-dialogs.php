<?php
/**
 * Roles screen, Simple view: the two dialogs the script opens.
 *
 * - The review (RS-6): what each changed control goes from and to, what it
 *   adds and removes in plain English, the raw names in a disclosure, and,
 *   when Critical capabilities are granted, what they let people do and
 *   the role's name typed to confirm (SEC-10). Shown when the change
 *   touches a Critical capability, changes more than ten capabilities, or
 *   replaces a Custom control by taking capabilities away (RS-4). The
 *   server checks all of it again, and shows its own review page when the
 *   dialog could not settle it (or without JavaScript).
 * - The view switch (RS-2): save or discard unsaved changes first.
 *
 * Both are empty shells until the script fills and opens them; without
 * JavaScript they never show.
 *
 * @package Blt_Toolkit
 *
 * @var string $name Role display name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dialog class="blt-toolkit-dialog" id="blt-tk-simple-review" aria-labelledby="blt-tk-simple-review-title" data-blt-review-dialog>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2 id="blt-tk-simple-review-title">
				<?php
				printf(
					/* translators: %s: role name. */
					esc_html__( 'Review the change to the %s role', 'blt-toolkit' ),
					esc_html( $name )
				);
				?>
			</h2>
			<p data-blt-review-intro></p>
		</div>
		<div class="blt-card-body">
			<ul class="blt-toolkit-review-list blt-toolkit-simple-review-lines" data-blt-review-lines></ul>

			<div class="blt-callout" data-blt-review-custom hidden>
				<strong><?php esc_html_e( 'Replacing a Custom setting', 'blt-toolkit' ); ?></strong>
				<span><?php esc_html_e( 'A control that showed Custom or Partly allowed is being set to a level or switched off. The capabilities listed as removed will be taken away from this role.', 'blt-toolkit' ); ?></span>
			</div>

			<div data-blt-review-critical hidden>
				<div class="blt-callout">
					<strong><?php esc_html_e( 'Critical', 'blt-toolkit' ); ?></strong>
					<span><?php esc_html_e( 'Critical capabilities can be used to take over the site or other people\'s accounts. Everyone who has this role will be able to:', 'blt-toolkit' ); ?></span>
				</div>
				<ul class="blt-toolkit-review-list" data-blt-review-consequences></ul>
				<div class="blt-field">
					<div class="blt-field-label"><label for="blt-tk-simple-review-confirm"><?php esc_html_e( 'Type the role name to confirm', 'blt-toolkit' ); ?></label></div>
					<div>
						<input type="text" class="regular-text" id="blt-tk-simple-review-confirm" value="" autocomplete="off" spellcheck="false" aria-describedby="blt-tk-simple-review-confirm-desc" data-blt-review-confirm />
						<p class="blt-field-desc" id="blt-tk-simple-review-confirm-desc">
							<?php
							printf(
								/* translators: %s: role name. */
								esc_html__( 'Type %s exactly as shown.', 'blt-toolkit' ),
								'<strong>' . esc_html( $name ) . '</strong>'
							);
							?>
						</p>
						<p class="blt-text-danger" role="alert" data-blt-review-mismatch hidden><?php esc_html_e( 'The name you typed does not match. Type it exactly as shown.', 'blt-toolkit' ); ?></p>
					</div>
				</div>
			</div>

			<div class="blt-callout" data-blt-review-large hidden>
				<strong><?php esc_html_e( 'A large change', 'blt-toolkit' ); ?></strong>
				<span data-blt-review-large-text></span>
			</div>

			<details class="blt-toolkit-simple-raw">
				<summary><?php esc_html_e( 'Capability names', 'blt-toolkit' ); ?></summary>
				<div data-blt-review-raw></div>
			</details>
		</div>
		<div class="blt-settings-footer">
			<button type="button" class="button" data-blt-review-cancel><?php esc_html_e( 'Cancel', 'blt-toolkit' ); ?></button>
			<button type="button" class="button button-primary" data-blt-review-save><?php esc_html_e( 'Save the change', 'blt-toolkit' ); ?></button>
		</div>
	</div>
</dialog>

<dialog class="blt-toolkit-dialog" id="blt-tk-simple-switch" aria-labelledby="blt-tk-simple-switch-title" aria-describedby="blt-tk-simple-switch-desc" data-blt-switch-dialog>
	<div class="blt-card">
		<div class="blt-card-header">
			<h2 id="blt-tk-simple-switch-title"><?php esc_html_e( 'You have unsaved changes', 'blt-toolkit' ); ?></h2>
			<p id="blt-tk-simple-switch-desc"><?php esc_html_e( 'Save them before switching views, or discard them.', 'blt-toolkit' ); ?></p>
		</div>
		<div class="blt-settings-footer">
			<button type="button" class="button" data-blt-switch-cancel><?php esc_html_e( 'Keep editing', 'blt-toolkit' ); ?></button>
			<button type="button" class="button" data-blt-switch-discard><?php esc_html_e( 'Discard and switch', 'blt-toolkit' ); ?></button>
			<button type="button" class="button button-primary" data-blt-switch-save><?php esc_html_e( 'Save and switch', 'blt-toolkit' ); ?></button>
		</div>
	</div>
</dialog>
