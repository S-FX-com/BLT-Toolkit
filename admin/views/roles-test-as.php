<?php
/**
 * Roles screen: "Test as…" in the role header (X-3). A select of up to 20
 * users holding the role whom the person may switch to; choosing one
 * switches with `redirect_to` set to the dashboard. With JavaScript and more
 * holders than the select shows, a search (SW-20's user search, narrowed to
 * this role on the server) finds the others; without it, a link to the
 * Users list filtered to the role, where each row offers Switch To.
 *
 * @package Blt_Toolkit
 *
 * @var array  $test_as available, users (id, label), holders, more, search, users_url.
 * @var string $role    Role ID.
 * @var string $name    Role display name.
 * @var string $back    The editor's query string, to come back to on refusal.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$blt_tk_select_id = 'blt-tk-test-as';
?>
<div class="blt-toolkit-test-as">
	<?php if ( 0 === (int) $test_as['holders'] ) : ?>
		<p class="blt-field-desc"><?php esc_html_e( 'Test as…: no user has this role.', 'blt-toolkit' ); ?></p>
	<?php elseif ( array() === $test_as['users'] ) : ?>
		<p class="blt-field-desc"><?php esc_html_e( 'Test as…: you cannot switch to anyone who has this role.', 'blt-toolkit' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>" class="blt-toolkit-test-as-form">
			<?php BLT_Toolkit_Admin::form_fields( 'blt_toolkit_roles_test_as' ); ?>
			<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>" />
			<label for="<?php echo esc_attr( $blt_tk_select_id ); ?>"><?php esc_html_e( 'Test as…', 'blt-toolkit' ); ?></label>
			<select id="<?php echo esc_attr( $blt_tk_select_id ); ?>" name="user_id" aria-describedby="<?php echo esc_attr( $blt_tk_select_id ); ?>-desc">
				<?php foreach ( $test_as['users'] as $blt_tk_user ) : ?>
					<option value="<?php echo esc_attr( (string) $blt_tk_user['id'] ); ?>"><?php echo esc_html( $blt_tk_user['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Switch', 'blt-toolkit' ); ?></button>
		</form>
		<p class="blt-field-desc" id="<?php echo esc_attr( $blt_tk_select_id ); ?>-desc">
			<?php esc_html_e( 'You become that user and land on their dashboard, to see the site as this role does. Use Switch back to return.', 'blt-toolkit' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( $test_as['search'] ) : ?>
		<div class="blt-toolkit-test-as-search" data-blt-test-as data-role="<?php echo esc_attr( $role ); ?>" hidden>
			<label for="<?php echo esc_attr( $blt_tk_select_id ); ?>-search"><?php esc_html_e( 'Find someone else with this role', 'blt-toolkit' ); ?></label>
			<input type="search" id="<?php echo esc_attr( $blt_tk_select_id ); ?>-search" class="regular-text" autocomplete="off" spellcheck="false" />
			<p class="blt-field-desc" data-blt-test-as-status aria-live="polite"></p>
			<ul class="blt-toolkit-test-as-results" data-blt-test-as-results></ul>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $test_as['users_url'] ) : ?>
		<p class="blt-field-desc" data-blt-test-as-fallback>
			<a href="<?php echo esc_url( $test_as['users_url'] ); ?>"><?php esc_html_e( 'More people have this role: find them on the Users screen, where each row offers Switch To.', 'blt-toolkit' ); ?></a>
		</p>
	<?php endif; ?>
</div>
