<?php
/**
 * Template: the front-end username form, `[blt_toolkit_username_form]` (UN-23).
 *
 * Override it by copying this file to `your-theme/blt-toolkit/username-form.php`
 * (a child theme's copy wins over the parent theme's). Keep the hidden fields,
 * the field names and the form's method and action: the server checks the
 * nonce, the capability, the cooldown and every validation rule whatever the
 * markup says.
 *
 * Everything comes in `$args` (plain values; escape on output, as below):
 *
 * - `id`                (string) Unique id prefix for this form on the page.
 * - `classes`           (string) Wrapper classes: `blt-tk-username-form`, the
 *                       `blt-tk-username-form--styled` modifier unless
 *                       `style="none"`, and the shortcode's `class` attribute.
 * - `styled`            (bool)   Whether the stylesheet is loaded for this form.
 * - `show_form`         (bool)   The person may change their username now.
 * - `notice`            (array|null) The result after a submit: `type`
 *                       (`success` or `error`), `message` (plain text),
 *                       `html` (the same, escaped, with usernames and symbols
 *                       in `<code>`, which wptexturize() leaves alone) and
 *                       `field` (`new`, `password`, `confirm` or '') it is about.
 * - `cooldown`          (string) When set, the person is in their cooldown
 *                       (UN-21) and this says when they can change it again.
 * - `current_login`     (string) Their username now.
 * - `value`             (string) What they typed, shown again after an error.
 * - `min_length`        (int)    Shortest username allowed (UN-2).
 * - `max_length`        (int)    Longest username allowed (60).
 * - `allow_at`          (bool)   Whether `@` is allowed (UN-7).
 * - `password_required` (bool)   Ask for the current password (UN-22).
 * - `confirm_switched`  (bool)   Show the switched-session confirmation (UN-12).
 * - `switched_message`  (string) Its warning text.
 * - `action_url`        (string) Where the form posts (admin-post.php).
 * - `hidden`            (array)  Hidden fields, name => value.
 * - `fields`            (array)  Field names: `new`, `password`, `confirm`.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$form_id  = (string) $args['id'];
$notice   = is_array( $args['notice'] ) ? $args['notice'] : null;
$field    = null !== $notice ? (string) $notice['field'] : '';
$is_error = null !== $notice && 'error' === $notice['type'];

// Ids the fields point at with aria-describedby.
$notice_id   = $form_id . '-notice';
$new_id      = $form_id . '-new';
$help_id     = $form_id . '-help';
$password_id = $form_id . '-password';
$pw_help_id  = $form_id . '-password-help';
$confirm_id  = $form_id . '-confirm';
$warning_id  = $form_id . '-switched';

$new_describedby = $help_id . ( $is_error && 'new' === $field ? ' ' . $notice_id : '' );
$pw_describedby  = $pw_help_id . ( $is_error && 'password' === $field ? ' ' . $notice_id : '' );
$cf_describedby  = $warning_id . ( $is_error && 'confirm' === $field ? ' ' . $notice_id : '' );
?>
<div id="<?php echo esc_attr( $form_id ); ?>" class="<?php echo esc_attr( $args['classes'] ); ?>">
	<?php if ( null !== $notice ) : ?>
		<div id="<?php echo esc_attr( $notice_id ); ?>" class="blt-tk-username-form__notice blt-tk-username-form__notice--<?php echo esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ); ?>" role="<?php echo esc_attr( $is_error ? 'alert' : 'status' ); ?>" tabindex="-1">
			<p class="blt-tk-username-form__notice-text"><?php echo isset( $notice['html'] ) ? wp_kses( $notice['html'], array( 'code' => array( 'class' => true ) ) ) : esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $args['cooldown'] ) : ?>
		<p class="blt-tk-username-form__cooldown"><?php echo esc_html( $args['cooldown'] ); ?></p>
	<?php endif; ?>

	<?php if ( $args['show_form'] ) : ?>
		<form class="blt-tk-username-form__form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>">
			<?php foreach ( (array) $args['hidden'] as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>

			<p class="blt-tk-username-form__current">
				<?php
				printf(
					/* translators: %s: the person's current username. */
					esc_html__( 'Your username is %s.', 'blt-toolkit' ),
					'<code class="blt-tk-username-form__login">' . esc_html( $args['current_login'] ) . '</code>'
				);
				?>
			</p>

			<div class="blt-tk-username-form__field">
				<label class="blt-tk-username-form__label" for="<?php echo esc_attr( $new_id ); ?>"><?php esc_html_e( 'New username', 'blt-toolkit' ); ?></label>
				<input
					type="text"
					class="blt-tk-username-form__input"
					id="<?php echo esc_attr( $new_id ); ?>"
					name="<?php echo esc_attr( $args['fields']['new'] ); ?>"
					value="<?php echo esc_attr( $args['value'] ); ?>"
					minlength="<?php echo esc_attr( (string) $args['min_length'] ); ?>"
					maxlength="<?php echo esc_attr( (string) $args['max_length'] ); ?>"
					required
					autocomplete="off"
					autocapitalize="none"
					spellcheck="false"
					aria-describedby="<?php echo esc_attr( $new_describedby ); ?>"
					<?php echo $is_error && 'new' === $field ? 'aria-invalid="true"' : ''; ?>
				/>
				<p class="blt-tk-username-form__help" id="<?php echo esc_attr( $help_id ); ?>">
					<?php
					// The symbols sit in <code>, which wptexturize() leaves alone
					// (block themes run it over the whole page, and it would turn
					// " - " into a dash).
					printf(
						/* translators: 1: shortest length, 2: longest length, 3: the symbols allowed, such as ". - _ @". */
						esc_html__( 'From %1$d to %2$d characters: letters without accents, numbers, spaces and %3$s. You stay signed in after the change.', 'blt-toolkit' ),
						(int) $args['min_length'],
						(int) $args['max_length'],
						'<code class="blt-tk-username-form__symbols">' . esc_html( $args['allow_at'] ? '. - _ @' : '. - _' ) . '</code>'
					);
					?>
				</p>
			</div>

			<?php if ( $args['password_required'] ) : ?>
				<div class="blt-tk-username-form__field">
					<label class="blt-tk-username-form__label" for="<?php echo esc_attr( $password_id ); ?>"><?php esc_html_e( 'Current password', 'blt-toolkit' ); ?></label>
					<input
						type="password"
						class="blt-tk-username-form__input"
						id="<?php echo esc_attr( $password_id ); ?>"
						name="<?php echo esc_attr( $args['fields']['password'] ); ?>"
						value=""
						required
						autocomplete="current-password"
						spellcheck="false"
						aria-describedby="<?php echo esc_attr( $pw_describedby ); ?>"
						<?php echo $is_error && 'password' === $field ? 'aria-invalid="true"' : ''; ?>
					/>
					<p class="blt-tk-username-form__help" id="<?php echo esc_attr( $pw_help_id ); ?>"><?php esc_html_e( 'Needed to confirm that it is you.', 'blt-toolkit' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $args['confirm_switched'] ) : ?>
				<div class="blt-tk-username-form__field blt-tk-username-form__field--confirm">
					<p class="blt-tk-username-form__warning" id="<?php echo esc_attr( $warning_id ); ?>"><?php echo esc_html( $args['switched_message'] ); ?></p>
					<p class="blt-tk-username-form__confirm">
						<input
							type="checkbox"
							class="blt-tk-username-form__checkbox"
							id="<?php echo esc_attr( $confirm_id ); ?>"
							name="<?php echo esc_attr( $args['fields']['confirm'] ); ?>"
							value="1"
							aria-describedby="<?php echo esc_attr( $cf_describedby ); ?>"
							<?php echo $is_error && 'confirm' === $field ? 'aria-invalid="true"' : ''; ?>
						/>
						<label class="blt-tk-username-form__confirm-label" for="<?php echo esc_attr( $confirm_id ); ?>"><?php esc_html_e( 'Change my username and end the switched session', 'blt-toolkit' ); ?></label>
					</p>
				</div>
			<?php endif; ?>

			<p class="blt-tk-username-form__actions">
				<button type="submit" class="blt-tk-username-form__button"><?php esc_html_e( 'Change username', 'blt-toolkit' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
