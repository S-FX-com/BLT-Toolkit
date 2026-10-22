<?php
/**
 * The front-end username form, `[blt_toolkit_username_form]` (UN-23).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-service username changes on the front end (SPEC 8.5, UN-23).
 *
 * - A real form that POSTs to admin-post.php (`blt_toolkit_username_form`,
 *   signed-in action) with a per-user nonce (SEC-7). The rename itself is
 *   BLT_Toolkit_Username_Changer::change() for the current user with the
 *   source `shortcode`, so the one validator (UN-1 to UN-9), the cooldown
 *   (UN-21), the current password (UN-22), the switched-session
 *   confirmation (UN-12) and the session rule (UN-14: the cookie is issued
 *   again for the new login with the same session, so the person stays
 *   signed in) all hold exactly as on the profile screen.
 * - Post / redirect / get: the handler redirects back to the page the form
 *   was on with a result code from a fixed list, plus a nonce whose action
 *   contains that code (like BLT_Toolkit_Admin::result_args()). The nonce is
 *   bound to the person and their session, so a crafted link cannot show a
 *   success that never happened. Details that cannot be rebuilt from the
 *   code (the validator's message, the name that was typed) wait in a
 *   five-minute transient of that person and are read only behind a valid
 *   result code.
 * - Renders nothing for visitors and for anyone `blt_change_username` refuses
 *   for themselves, except that someone holding `blt_change_own_username`
 *   during their cooldown is told when they can change it again (as the
 *   profile screen does).
 * - The stylesheet (assets/css/blt-toolkit-front.css, the "Username form
 *   (UN-23)" section) loads only on a page where the form renders in
 *   `style="styled"`; `style="none"` loads nothing and prints BEM markup only
 *   (CONVENTIONS 6.4). It is enqueued in the head when the queried post's own
 *   content holds the shortcode, otherwise when the shortcode renders (in the
 *   footer when the head has already been printed).
 * - The markup comes from templates/username-form.php, which a theme
 *   replaces with `your-theme/blt-toolkit/username-form.php` (child theme
 *   first). No JavaScript.
 *
 * Attributes:
 *
 * | Attribute | Default or Values      | Description                                   |
 * | --------- | ---------------------- | --------------------------------------------- |
 * | `style`   | `styled` / `none`      | `none` loads no CSS and prints BEM markup only. |
 * | `class`   | (empty)                | Extra classes on the wrapper.                 |
 *
 * Cost per request (SEC-9): boot() only adds hooks. On a front-end page view
 * by a visitor who is not signed in, the `wp_enqueue_scripts` callback
 * returns at is_user_logged_in() and nothing else runs. Everything else runs
 * only where the shortcode renders or the form is posted.
 */
class BLT_Toolkit_Username_Shortcode {

	/**
	 * Shortcode tag.
	 */
	const TAG = 'blt_toolkit_username_form';

	/**
	 * admin-post.php action (signed in; the signed-out one only redirects to
	 * the login screen).
	 */
	const ACTION = 'blt_toolkit_username_form';

	/**
	 * Form fields.
	 */
	const FIELD          = 'blt_tk_uf_new';
	const PASSWORD_FIELD = 'blt_tk_uf_password';
	const CONFIRM_FIELD  = 'blt_tk_uf_confirm';
	const RETURN_FIELD   = 'blt_tk_uf_return';
	const INSTANCE_FIELD = 'blt_tk_uf_form';
	const NONCE_FIELD    = 'blt_tk_uf_nonce';

	/**
	 * Prefix of the form's nonce action; the user ID follows.
	 */
	const NONCE_ACTION = 'blt_toolkit_username_form_';

	/**
	 * Query arguments of the redirect back: the result code and the nonce
	 * vouching for it.
	 */
	const RESULT_ARG = 'blt_tk_uf_result';
	const SIG_ARG    = 'blt_tk_uf_sig';

	/**
	 * Every result code the redirect may carry. Anything else is ignored.
	 */
	const RESULTS = array( 'changed', 'expired', 'empty', 'invalid', 'password', 'locked', 'cooldown', 'forbidden', 'switched', 'failed' );

	/**
	 * Transient prefix for a result's details; the user ID follows.
	 */
	const DETAIL_TRANSIENT = 'blt_toolkit_username_form_';

	/**
	 * Stylesheet handle, shared with the "Switch back" link (SW-17): one file,
	 * printed once whichever piece asks for it first.
	 */
	const STYLE = 'blt-toolkit-front';

	/**
	 * Template file, and the folder a theme puts its copy in.
	 */
	const TEMPLATE  = 'username-form.php';
	const THEME_DIR = 'blt-toolkit';

	/**
	 * Id prefix of each rendered form; the instance number follows.
	 */
	const ID_PREFIX = 'blt-tk-username-form-';

	/**
	 * Forms rendered so far on this request, for unique ids.
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * The result details read for this request: false until read.
	 *
	 * @var array|false|null
	 */
	private static $detail = false;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the shortcode and the handlers. Called by the Usernames
	 * module's boot(); adds hooks only (SEC-9).
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_signed_out' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );
	}

	// ---------- Rendering ----------

	/**
	 * The shortcode. Returns the form as a string (CONVENTIONS 6.4); the
	 * template escapes everything it prints.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = self::atts( $atts );

		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return '';
		}

		$user_id  = (int) $user->ID;
		$can      = current_user_can( 'blt_change_username', $user_id ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1): self-service plus the cooldown.
		$cooldown = '';

		if ( ! $can ) {
			// UN-21: someone allowed to change their own username, in their
			// cooldown, is told when; anyone else sees nothing (UN-23).
			$refusal = BLT_Toolkit_Username_Changer::refusal( $user_id );

			if ( 'blt_toolkit_username_cooldown' !== $refusal->get_error_code() ) {
				return '';
			}

			$cooldown = $refusal->get_error_message();
		}

		$result = self::result();
		$detail = '' !== $result ? self::detail( $user_id, $result ) : array();
		$notice = '' !== $result ? self::notice( $result, $detail, $user ) : null;

		// The cooldown is the notice itself after a refused attempt.
		if ( null !== $notice && 'cooldown' === $result ) {
			$cooldown = '';
		}

		++self::$instances;

		$id     = self::ID_PREFIX . self::$instances;
		$styled = 'styled' === self::style_mode( $atts['style'] );
		$args   = array(
			'id'                => $id,
			'styled'            => $styled,
			'classes'           => self::wrapper_classes( $styled, $atts['class'] ),
			'show_form'         => $can,
			'notice'            => $notice,
			'cooldown'          => $cooldown,
			'current_login'     => (string) $user->user_login,
			'value'             => '',
			'min_length'        => 1,
			'max_length'        => BLT_Toolkit_Username_Validator::MAX_LENGTH,
			'allow_at'          => true,
			'password_required' => false,
			'confirm_switched'  => false,
			'switched_message'  => '',
			'action_url'        => admin_url( 'admin-post.php' ),
			'hidden'            => array(),
			'fields'            => array(
				'new'      => self::FIELD,
				'password' => self::PASSWORD_FIELD,
				'confirm'  => self::CONFIRM_FIELD,
			),
		);

		if ( $can ) {
			$args = array_merge( $args, self::form_args( $user, $result, $detail ) );

			// UN-12: the warning is printed above the checkbox; the notice
			// points there instead of repeating it.
			if ( 'switched' === $result && $args['confirm_switched'] ) {
				$args['notice'] = self::make_notice( 'error', __( 'Your username was not changed. Tick the box below to confirm that the switched session may end, then try again.', 'blt-toolkit' ), 'confirm' );
			}

			// The form carries a nonce: keep it out of page caches.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page caches' own constant.
			}
		}

		if ( $styled ) {
			self::enqueue_style();
		}

		return self::render_template( $args );
	}

	/**
	 * The shortcode's attributes over their defaults, through core's
	 * shortcode_atts() and so its `shortcode_atts_blt_toolkit_username_form`
	 * filter. render() and enqueue_early() both read them here, so a filter
	 * that sets `style` to `none` site-wide also keeps the stylesheet out of
	 * the head (UN-23, CONVENTIONS 6.4).
	 *
	 * @param mixed $atts Attributes as given.
	 * @return array{style:string,class:string}
	 */
	private static function atts( $atts ) {
		return shortcode_atts(
			array(
				'style' => 'styled',
				'class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
	}

	/**
	 * The form's own values: limits, what was typed, the password and
	 * switched-session fields, and the hidden fields.
	 *
	 * @param WP_User $user   The current user.
	 * @param string  $result The result code being shown, or ''.
	 * @param array   $detail Its details.
	 * @return array
	 */
	private static function form_args( WP_User $user, $result, array $detail ) {
		$user_id = (int) $user->ID;
		$login   = (string) $user->user_login;
		$context = BLT_Toolkit_Username_Validator::context_for( $user );

		// UN-12. A self rename keeps the current session, so it does not count.
		$state    = BLT_Toolkit_Username_Changer::switched_state( $user_id, BLT_Toolkit_Username_Changer::session_token( $user_id, $login ) );
		$switched = $state['switched_from'] || $state['switched_into'];

		// A name that failed is offered again to correct; after a success the
		// field starts empty.
		$value = 'changed' !== $result && isset( $detail['value'] ) && is_string( $detail['value'] ) ? $detail['value'] : '';

		return array(
			'value'             => $value,
			'min_length'        => isset( $context['min_length'] ) ? max( 1, (int) $context['min_length'] ) : 1,
			'max_length'        => isset( $context['max_length'] ) ? (int) $context['max_length'] : BLT_Toolkit_Username_Validator::MAX_LENGTH,
			'allow_at'          => ! empty( $context['allow_at'] ),
			'password_required' => BLT_Toolkit_Username_Changer::password_required( $user_id ),
			'confirm_switched'  => $switched,
			'switched_message'  => $switched ? BLT_Toolkit_Username_Changer::switched_message( $state ) : '',
			'hidden'            => array(
				'action'             => self::ACTION,
				self::NONCE_FIELD    => wp_create_nonce( self::NONCE_ACTION . $user_id ),
				self::RETURN_FIELD   => self::current_uri(),
				self::INSTANCE_FIELD => (string) ( self::$instances ),
			),
		);
	}

	/**
	 * The notice for a result code: type, message and the field it is about
	 * (so the template can point the field at it with aria-describedby).
	 *
	 * @param string  $result Result code (one of RESULTS).
	 * @param array   $detail Its details, possibly empty.
	 * @param WP_User $user   The current user.
	 * @return array{type:string,message:string,field:string}
	 */
	private static function notice( $result, array $detail, WP_User $user ) {
		$message  = isset( $detail['message'] ) && is_string( $detail['message'] ) ? $detail['message'] : '';
		$literals = isset( $detail['literals'] ) && is_array( $detail['literals'] ) ? $detail['literals'] : array();

		switch ( $result ) {
			case 'changed':
				$text = sprintf(
					/* translators: %s: the new username. */
					__( 'Your username is now %s. You are still signed in; use it the next time you sign in.', 'blt-toolkit' ),
					(string) $user->user_login
				);

				if ( ! empty( $detail['nicename_changed'] ) ) {
					$text .= ' ' . ( BLT_Toolkit_Settings::get( 'usernames', 'redirect_old_slugs' )
						? __( 'Your author URL changed too; links to the old one lead to the new one.', 'blt-toolkit' )
						: __( 'Your author URL changed too.', 'blt-toolkit' ) );
				}

				return self::make_notice( 'success', $text, '', array( (string) $user->user_login ) );

			case 'expired':
				return self::make_notice( 'error', __( 'Your username was not changed because the form had expired. Try again.', 'blt-toolkit' ), 'new' );

			case 'empty':
				return self::make_notice( 'error', __( 'Enter the new username you want.', 'blt-toolkit' ), 'new' );

			case 'invalid':
				return self::make_notice( 'error', '' !== $message ? $message : __( 'That username cannot be used. Choose another.', 'blt-toolkit' ), 'new', $literals );

			case 'password':
				return self::make_notice( 'error', '' !== $message ? $message : __( 'Your current password is not correct, so your username was not changed.', 'blt-toolkit' ), 'password' );

			case 'locked':
				return self::make_notice( 'error', '' !== $message ? $message : __( 'Your current password was entered wrongly too many times, so your username was not changed. Try again later.', 'blt-toolkit' ), 'password' );

			case 'cooldown':
				$refusal = BLT_Toolkit_Username_Changer::refusal( (int) $user->ID );

				return self::make_notice( 'error', 'blt_toolkit_username_cooldown' === $refusal->get_error_code() ? $refusal->get_error_message() : ( '' !== $message ? $message : __( 'You changed your username recently, so it was not changed again.', 'blt-toolkit' ) ), '' );

			case 'forbidden':
				return self::make_notice( 'error', __( 'You are not allowed to change your own username.', 'blt-toolkit' ), '' );

			case 'switched':
				return self::make_notice( 'error', '' !== $message ? $message : __( 'Your account is in a switched session right now. Confirm below to change your username and end it.', 'blt-toolkit' ), 'confirm' );
		}

		return self::make_notice( 'error', __( 'Your username could not be changed. Nothing was changed; try again.', 'blt-toolkit' ), '' );
	}

	/**
	 * A notice array: the message as plain text, and as HTML with its
	 * literals (usernames, the allowed symbols) in `<code>`.
	 *
	 * @param string   $type     `success` or `error`.
	 * @param string   $message  Text (plain).
	 * @param string   $field    `new`, `password`, `confirm` or ''.
	 * @param string[] $literals Parts of the message to print as code.
	 * @return array{type:string,message:string,html:string,field:string}
	 */
	private static function make_notice( $type, $message, $field, array $literals = array() ) {
		return array(
			'type'    => $type,
			'message' => (string) $message,
			'html'    => self::mark_literals( (string) $message, $literals ),
			'field'   => $field,
		);
	}

	/**
	 * Escape a message and put each literal it contains (a username, the
	 * list of allowed symbols) in `<code class="blt-tk-username-form__literal">`.
	 *
	 * Block themes run wptexturize() over the whole rendered page, which
	 * would print `a--b` as `a—b`, `1x2` as `1×2` and ` - ` as a dash: a
	 * username or a suggestion shown that way is wrong. wptexturize() leaves
	 * `<code>` alone. The swap goes through placeholders in one pass, so a
	 * literal is never searched for inside markup added for another. Pure.
	 *
	 * @param string   $message  Plain text.
	 * @param string[] $literals Substrings to mark.
	 * @return string Safe HTML: escaped text plus `<code>` elements.
	 */
	public static function mark_literals( $message, array $literals ) {
		$html  = esc_html( (string) $message );
		$swaps = array();
		$back  = array();

		foreach ( $literals as $literal ) {
			$escaped = is_scalar( $literal ) ? esc_html( trim( (string) $literal ) ) : '';

			if ( '' === $escaped || isset( $swaps[ $escaped ] ) || false === strpos( $html, $escaped ) ) {
				continue;
			}

			$token             = "\x00" . count( $swaps ) . "\x00";
			$swaps[ $escaped ] = $token;
			$back[ $token ]    = '<code class="blt-tk-username-form__literal">' . $escaped . '</code>';
		}

		return array() === $swaps ? $html : strtr( strtr( $html, $swaps ), $back );
	}

	/**
	 * Include the template (the theme's copy when there is one) and return
	 * what it printed.
	 *
	 * @param array $args Template data, also available as `$args` there.
	 * @return string
	 */
	private static function render_template( array $args ) {
		$file = self::locate_template();

		if ( '' === $file ) {
			return '';
		}

		ob_start();
		self::load_template( $file, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Include a template file with `$args` in scope and nothing else.
	 *
	 * @param string $file Absolute path.
	 * @param array  $args Template data.
	 * @return void
	 */
	private static function load_template( $file, array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is read by the included template.
		include $file;
	}

	/**
	 * The template to use: `blt-toolkit/username-form.php` in the child
	 * theme, then the parent theme, then the plugin's own (CONVENTIONS 6.4).
	 *
	 * @return string Absolute path, or '' when even the plugin's copy is missing.
	 */
	public static function locate_template() {
		$theme = locate_template( array( self::THEME_DIR . '/' . self::TEMPLATE ) );

		if ( is_string( $theme ) && '' !== $theme ) {
			return $theme;
		}

		$own = BLT_TOOLKIT_DIR . 'templates/' . self::TEMPLATE;

		return is_readable( $own ) ? $own : '';
	}

	/**
	 * The wrapper's classes: the block, the styled modifier, and the
	 * shortcode's `class` attribute, each cleaned with sanitize_html_class().
	 *
	 * @param bool   $styled Whether the styled mode is on.
	 * @param string $extra  The `class` attribute.
	 * @return string
	 */
	public static function wrapper_classes( $styled, $extra ) {
		$classes = array( 'blt-tk-username-form' );

		if ( $styled ) {
			$classes[] = 'blt-tk-username-form--styled';
		}

		foreach ( preg_split( '/\s+/', is_scalar( $extra ) ? (string) $extra : '' ) as $class ) {
			$class = sanitize_html_class( $class );

			if ( '' !== $class ) {
				$classes[] = $class;
			}
		}

		return implode( ' ', array_unique( $classes ) );
	}

	/**
	 * The `style` attribute as a mode: `none` (also `no-css`) or `styled`,
	 * the default for anything else. Pure.
	 *
	 * @param mixed $value Attribute value.
	 * @return string `styled` or `none`.
	 */
	public static function style_mode( $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		return in_array( $value, array( 'none', 'no-css' ), true ) ? 'none' : 'styled';
	}

	/**
	 * The page the form is on, as a root-relative path with its query, for
	 * the redirect back. The result arguments of an earlier redirect are
	 * dropped.
	 *
	 * @return string
	 */
	private static function current_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $uri || '/' !== $uri[0] || 0 === strpos( $uri, '//' ) ) {
			$uri = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		}

		return remove_query_arg( array( self::RESULT_ARG, self::SIG_ARG ), $uri );
	}

	// ---------- Stylesheet ----------

	/**
	 * Enqueue the front-end stylesheet (its UN-23 section styles the form).
	 * The same handle as the "Switch back" link, so the file prints once.
	 *
	 * @return void
	 */
	private static function enqueue_style() {
		wp_enqueue_style( self::STYLE, BLT_TOOLKIT_URL . 'assets/css/blt-toolkit-front.css', array(), BLT_TOOLKIT_VERSION );
	}

	/**
	 * Put the stylesheet in the head when the queried post's own content
	 * holds a styled form, so the form is styled from the first paint.
	 * `wp_enqueue_scripts` callback. Elsewhere (a widget, a page builder's
	 * template) render() enqueues it when the form renders.
	 *
	 * Costs nothing for visitors who are not signed in (SEC-9): it returns at
	 * is_user_logged_in(), before anything is read.
	 *
	 * @return void
	 */
	public static function enqueue_early() {
		if ( ! is_user_logged_in() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || false === strpos( (string) $post->post_content, '[' . self::TAG ) || post_password_required( $post ) ) {
			return;
		}

		if ( ! self::content_wants_style( (string) $post->post_content ) ) {
			return;
		}

		// The form renders (or says when the cooldown ends) only for this capability.
		if ( ! current_user_can( 'blt_change_own_username' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return;
		}

		self::enqueue_style();
	}

	/**
	 * Whether content holds at least one `[blt_toolkit_username_form]` in the
	 * styled mode (an escaped `[[...]]` does not count), with each one's
	 * attributes read as render() reads them (atts(), filter included).
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function content_wants_style( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '[' . self::TAG ) ) {
			return false;
		}

		if ( ! preg_match_all( '/' . get_shortcode_regex( array( self::TAG ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $match ) {
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}

			$atts = self::atts( shortcode_parse_atts( $match[3] ) );

			if ( 'styled' === self::style_mode( $atts['style'] ) ) {
				return true;
			}
		}

		return false;
	}

	// ---------- The POST (admin-post.php) ----------

	/**
	 * Handle the form (UN-23): method, nonce, then the rename procedure,
	 * which authorizes (`blt_change_username` for oneself: the capability and
	 * the cooldown), validates and checks the password itself (UN-10). Ends
	 * with a redirect back to the form's page.
	 * `admin_post_blt_toolkit_username_form` callback.
	 *
	 * @return void
	 */
	public static function handle() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			wp_die(
				esc_html__( 'This action needs a form submission.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 405,
					'back_link' => true,
				)
			);
		}

		$user_id  = get_current_user_id();
		$return   = self::posted_return();
		$instance = self::posted_instance();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is read here and verified on the next line, before anything else is used.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $user_id <= 0 || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $user_id ) ) {
			self::finish( $return, 'expired', $instance );
		}

		// Unsanitized on purpose: UN-1 compares the name with sanitize_user() of
		// itself and offers the result, so it must see what was typed.
		$new = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? trim( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by BLT_Toolkit_Username_Validator (UN-1 to UN-9) inside change() before any use.

		if ( '' === $new ) {
			self::finish( $return, 'empty', $instance );
		}

		// Far longer than any username (UN-2 allows 60 characters): refused
		// before it is validated, stored for the result page or shown again,
		// with the bound the REST check uses.
		if ( strlen( $new ) > BLT_Toolkit_Rest::USERNAME_CHECK_MAX ) {
			self::store_detail(
				$user_id,
				array(
					'code'    => 'invalid',
					'message' => BLT_Toolkit_Username_Validator::too_long_message(),
					'value'   => '',
				)
			);

			self::finish( $return, 'invalid', $instance );
		}

		$options = array(
			'source'             => 'shortcode',
			'confirmed_switched' => ! empty( $_POST[ self::CONFIRM_FIELD ] ),
		);

		if ( isset( $_POST[ self::PASSWORD_FIELD ] ) && is_string( $_POST[ self::PASSWORD_FIELD ] ) ) {
			$options['current_password'] = wp_unslash( $_POST[ self::PASSWORD_FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is compared, never stored or shown; sanitizing would change it.
		}

		$result = BLT_Toolkit_Username_Changer::change( $user_id, $new, $options );

		if ( is_wp_error( $result ) ) {
			$code = self::result_for_error( (string) $result->get_error_code() );

			self::store_detail(
				$user_id,
				array(
					'code'     => $code,
					'message'  => BLT_Toolkit_Username_Changer::public_error_message( (string) $result->get_error_code(), $result->get_error_message() ),
					'value'    => BLT_Toolkit_Username_Validator::normalize( $new ),
					'literals' => self::error_literals( $result ),
				)
			);

			self::finish( $return, $code, $instance );
		}

		// UN-14 keeps the session for a self rename. Should it not have been
		// kept (the cookie names the old login, so it no longer signs anyone
		// in), the page would show nothing to a signed-out visitor: send them
		// to sign in with the new username, then back to the page.
		if ( empty( $result['session_kept'] ) ) {
			wp_safe_redirect( wp_login_url( $return ) );
			exit;
		}

		self::store_detail(
			$user_id,
			array(
				'code'             => 'changed',
				'nicename_changed' => ! empty( $result['nicename_changed'] ),
			)
		);

		self::finish( $return, 'changed', $instance );
	}

	/**
	 * A signed-out POST (the session ended while the form was open): send the
	 * person to sign in, then back to the form's page. Nothing is changed.
	 * `admin_post_nopriv_blt_toolkit_username_form` callback.
	 *
	 * @return void
	 */
	public static function handle_signed_out() {
		wp_safe_redirect( wp_login_url( self::posted_return() ) );
		exit;
	}

	/**
	 * Redirect back to the form's page with a signed result code, and stop.
	 *
	 * @param string $return_url Root-relative URL of the page (posted_return()).
	 * @param string $code       Result code (one of RESULTS).
	 * @param int    $instance   Which form on the page posted, for the fragment.
	 * @return void
	 */
	private static function finish( $return_url, $code, $instance ) {
		$url = add_query_arg( self::result_args( $code ), $return_url );

		if ( $instance > 0 ) {
			$url .= '#' . self::ID_PREFIX . $instance;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The redirect arguments for a result: the code and a nonce over it. The
	 * nonce is bound to the person and their session (wp_create_nonce()), so
	 * nobody else's link, and no edited link, shows a result.
	 *
	 * @param string $code Result code.
	 * @return array<string,string>
	 */
	public static function result_args( $code ) {
		$code = in_array( $code, self::RESULTS, true ) ? $code : 'failed';

		return array(
			self::RESULT_ARG => $code,
			self::SIG_ARG    => wp_create_nonce( self::result_action( $code ) ),
		);
	}

	/**
	 * The result code of this request, when it is a known code with a valid
	 * nonce for the current user; '' otherwise.
	 *
	 * @return string
	 */
	public static function result() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; the code is checked against its own nonce below.
		if ( empty( $_GET[ self::RESULT_ARG ] ) || empty( $_GET[ self::SIG_ARG ] ) || ! is_string( $_GET[ self::RESULT_ARG ] ) || ! is_string( $_GET[ self::SIG_ARG ] ) ) {
			return '';
		}

		$code = sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) );
		$sig  = sanitize_text_field( wp_unslash( $_GET[ self::SIG_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $code, self::RESULTS, true ) || ! wp_verify_nonce( $sig, self::result_action( $code ) ) ) {
			return '';
		}

		return $code;
	}

	/**
	 * The nonce action for a result code.
	 *
	 * @param string $code Result code.
	 * @return string
	 */
	private static function result_action( $code ) {
		return 'blt_toolkit_username_form_result|' . $code;
	}

	/**
	 * The result code for an error from change(). Pure.
	 *
	 * @param string $error_code WP_Error code.
	 * @return string One of RESULTS.
	 */
	public static function result_for_error( $error_code ) {
		switch ( (string) $error_code ) {
			case 'blt_toolkit_username_cooldown':
				return 'cooldown';
			case 'blt_toolkit_username_forbidden':
				return 'forbidden';
			case 'blt_toolkit_username_password':
				return 'password';
			case 'blt_toolkit_username_password_locked':
				return 'locked';
			case 'blt_toolkit_username_switched':
				return 'switched';
			case 'blt_toolkit_username_no_user':
			case 'blt_toolkit_username_not_saved':
			case '':
				return 'failed';
		}

		// The validator's codes (UN-1 to UN-8) and any code a
		// `blt_toolkit_validate_username` filter adds (UN-9).
		return 'invalid';
	}

	/**
	 * The literals of a validation message to print as code (mark_literals()):
	 * UN-1's suggestion and the list of allowed symbols it names.
	 *
	 * @param WP_Error $error change()'s error.
	 * @return string[]
	 */
	private static function error_literals( WP_Error $error ) {
		if ( 'blt_toolkit_username_sanitize' !== $error->get_error_code() ) {
			return array();
		}

		$data = $error->get_error_data();

		return array_values(
			array_filter(
				array(
					'. - _ @',
					'. - _',
					is_array( $data ) && isset( $data['suggestion'] ) && is_string( $data['suggestion'] ) ? $data['suggestion'] : '',
				),
				'strlen'
			)
		);
	}

	/**
	 * Keep a result's details for the page after the redirect: five minutes,
	 * for this person only. Text that came from the input (the typed value,
	 * a message or literal carrying UN-1's suggestion) is cut to
	 * BLT_Toolkit_Rest::USERNAME_CHECK_MAX bytes, twice that for a message,
	 * so the stored row stays small whatever was posted.
	 *
	 * @param int   $user_id User.
	 * @param array $detail  `code`, plus `message`, `value`, `literals` or `nicename_changed`.
	 * @return void
	 */
	private static function store_detail( $user_id, array $detail ) {
		if ( $user_id <= 0 ) {
			return;
		}

		$max    = BLT_Toolkit_Rest::USERNAME_CHECK_MAX;
		$limits = array(
			'value'   => $max,
			'message' => 2 * $max,
		);

		foreach ( $limits as $key => $bytes ) {
			if ( isset( $detail[ $key ] ) && is_string( $detail[ $key ] ) ) {
				$detail[ $key ] = self::cut( $detail[ $key ], $bytes );
			}
		}

		if ( isset( $detail['literals'] ) && is_array( $detail['literals'] ) ) {
			foreach ( $detail['literals'] as $index => $literal ) {
				$detail['literals'][ $index ] = is_string( $literal ) ? self::cut( $literal, $max ) : '';
			}
		}

		set_transient( self::DETAIL_TRANSIENT . (int) $user_id, $detail, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Text cut to at most `$bytes` bytes without splitting a UTF-8 character.
	 * Pure.
	 *
	 * @param string $text  Text.
	 * @param int    $bytes Most bytes.
	 * @return string
	 */
	public static function cut( $text, $bytes ) {
		$text  = (string) $text;
		$bytes = max( 0, (int) $bytes );

		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}

		return function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $bytes, 'UTF-8' ) : substr( $text, 0, $bytes );
	}

	/**
	 * A result's details, when they belong to the result being shown. Read
	 * once per request (a page may render its content more than once) and
	 * then deleted.
	 *
	 * @param int    $user_id User.
	 * @param string $code    The result code being shown.
	 * @return array
	 */
	private static function detail( $user_id, $code ) {
		if ( false === self::$detail ) {
			$key          = self::DETAIL_TRANSIENT . (int) $user_id;
			$stored       = get_transient( $key );
			self::$detail = is_array( $stored ) ? $stored : null;

			if ( null !== self::$detail ) {
				delete_transient( $key );
			}
		}

		if ( ! is_array( self::$detail ) || ! isset( self::$detail['code'] ) || self::$detail['code'] !== $code ) {
			return array();
		}

		return self::$detail;
	}

	/**
	 * The posted return URL, when it is a root-relative URL on this site;
	 * the home page otherwise.
	 *
	 * @return string
	 */
	private static function posted_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only used as a redirect target, validated here; the nonce is verified by the caller where it matters.
		$raw = isset( $_POST[ self::RETURN_FIELD ] ) && is_string( $_POST[ self::RETURN_FIELD ] ) ? esc_url_raw( wp_unslash( $_POST[ self::RETURN_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return self::safe_return( $raw );
	}

	/**
	 * A return URL as the redirect uses it: a root-relative path that
	 * wp_validate_redirect() accepts, without earlier result arguments; the
	 * home page otherwise.
	 *
	 * @param string $raw Candidate.
	 * @return string
	 */
	public static function safe_return( $raw ) {
		$raw  = is_string( $raw ) ? trim( $raw ) : '';
		$home = home_url( '/' );

		if ( '' === $raw || '/' !== $raw[0] || 0 === strpos( $raw, '//' ) || false !== strpos( $raw, '\\' ) ) {
			return $home;
		}

		$valid = wp_validate_redirect( $raw, '' );

		if ( ! is_string( $valid ) || '' === $valid ) {
			return $home;
		}

		return remove_query_arg( array( self::RESULT_ARG, self::SIG_ARG ), $valid );
	}

	/**
	 * Which form on the page posted (1 and up), or 0.
	 *
	 * @return int
	 */
	private static function posted_instance() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only picks the fragment of the redirect.
		$instance = isset( $_POST[ self::INSTANCE_FIELD ] ) && is_string( $_POST[ self::INSTANCE_FIELD ] ) ? absint( wp_unslash( $_POST[ self::INSTANCE_FIELD ] ) ) : 0;

		return $instance <= 100 ? $instance : 0;
	}
}
