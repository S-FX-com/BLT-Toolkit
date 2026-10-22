<?php
/**
 * Changing a username from the profile screen and the Users list (UN-16 to
 * UN-19).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The profile integration. The change rides on the profile form's own
 * submit: no form of its own, so never a form inside core's (SPEC 19, UC-8).
 *
 * - UN-16: on `show_user_profile` and `edit_user_profile`, when
 *   `blt_change_username` passes, a "Change username" section with the field
 *   `blt_tk_new_user_login` (empty keeps the username) and a BLT nonce. With
 *   JavaScript the field moves into core's Username row behind a "Change"
 *   button, with advisory live checks through
 *   `GET blt-toolkit/v1/usernames/check`. Without JavaScript the section
 *   works as it is.
 * - UN-17: on `user_profile_update_errors`, a non-empty field is checked
 *   (nonce, `blt_change_username`, the validator, the password when UN-22
 *   asks for it, the UN-12 confirmation); a failure is added to core's
 *   errors, so core saves nothing and shows the message. On success the
 *   name is kept in a static stash.
 * - UN-18: on `profile_update` (after core's save), a stashed name goes
 *   through the rename procedure (BLT_Toolkit_Username_Changer::change()),
 *   and the result is shown on the next screen.
 * - UN-19: a "Change username" row action on the Users list.
 *
 * Cost per request (SEC-9): boot() only adds hooks. On the Users list the
 * row action costs two capability checks per row, which the Guard caches per
 * role set; nothing else runs unless a profile screen renders or is saved.
 */
class BLT_Toolkit_Username_Profile {

	/**
	 * The new-username field (UN-16).
	 */
	const FIELD = 'blt_tk_new_user_login';

	/**
	 * The nonce field and the prefix of its action (the user ID follows).
	 */
	const NONCE_FIELD  = 'blt_tk_username_nonce';
	const NONCE_ACTION = 'blt_toolkit_change_username_';

	/**
	 * The UN-12 confirmation checkbox.
	 */
	const CONFIRM_FIELD = 'blt_tk_username_confirm_switched';

	/**
	 * The current password field (UN-22).
	 */
	const PASSWORD_FIELD = 'blt_tk_username_password';

	/**
	 * Id of the section, and the fragment of the row action's link (UN-19).
	 */
	const ANCHOR = 'blt-tk-username';

	/**
	 * Script handle.
	 */
	const SCRIPT = 'blt-toolkit-usernames';

	/**
	 * Query argument added to core's redirect after a save that ran a rename,
	 * so the result notice is looked up only then.
	 */
	const RESULT_ARG = 'blt_tk_username';

	/**
	 * Transient prefix for the result notice (the actor's ID follows).
	 */
	const NOTICE_TRANSIENT = 'blt_toolkit_username_notice_';

	/**
	 * Names approved on `user_profile_update_errors`, waiting for
	 * `profile_update`: user ID => array( 'new' => string, 'confirmed' => bool ).
	 *
	 * @var array<int,array>
	 */
	private static $stash = array();

	/**
	 * What was typed this request, so a form shown again after an error keeps
	 * it: user ID => string.
	 *
	 * @var array<int,string>
	 */
	private static $attempt = array();

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the hooks. Called by the Usernames module's boot().
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// UN-16.
		add_action( 'show_user_profile', array( __CLASS__, 'render_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_section' ) );

		// UN-17, UN-18.
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'apply' ), 20, 1 );
		add_action( 'admin_notices', array( __CLASS__, 'result_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );

		// UN-19.
		add_filter( 'user_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	// ---------- UN-16 ----------

	/**
	 * The "Change username" section on the profile screen.
	 *
	 * For someone renaming themselves during the cooldown (UN-21) it says
	 * when they can next change it. Prints nothing for anyone else who may
	 * not change this username.
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public static function render_section( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return;
		}

		$user_id = (int) $user->ID;
		$self    = get_current_user_id() === $user_id;

		if ( ! current_user_can( 'blt_change_username', $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			if ( $self ) {
				$refusal = BLT_Toolkit_Username_Changer::refusal( $user_id );

				if ( 'blt_toolkit_username_cooldown' === $refusal->get_error_code() ) {
					self::render_cooldown( $refusal->get_error_message() );
				}
			}

			return;
		}

		$login    = (string) $user->user_login;
		$context  = BLT_Toolkit_Username_Validator::context_for( $user );
		$min      = isset( $context['min_length'] ) ? (int) $context['min_length'] : 1;
		$max      = isset( $context['max_length'] ) ? (int) $context['max_length'] : 60;
		$state    = BLT_Toolkit_Username_Changer::switched_state( $user_id, $self ? BLT_Toolkit_Username_Changer::session_token( $user_id, $login ) : '' );
		$switched = $state['switched_from'] || $state['switched_into'];
		$password = BLT_Toolkit_Username_Changer::password_required( $user_id );
		$typed    = isset( self::$attempt[ $user_id ] ) ? self::$attempt[ $user_id ] : '';
		$slug     = self::slug_note( $user );

		if ( $self ) {
			$after = __( 'You stay signed in here. Use the new username the next time you sign in.', 'blt-toolkit' );
		} elseif ( BLT_Toolkit_Settings::get( 'usernames', 'sign_out_others' ) ) {
			$after = __( 'They are signed out everywhere and sign in again with the new username.', 'blt-toolkit' );
		} else {
			$after = __( 'They need to sign in again with the new username.', 'blt-toolkit' );
		}

		self::enqueue( $user, $min, $max );
		?>
		<div id="<?php echo esc_attr( self::ANCHOR ); ?>" class="blt-tk-username-section">
			<h2><?php esc_html_e( 'Change username', 'blt-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr class="blt-tk-username-row">
						<th scope="row"><label for="blt-tk-new-user-login" id="blt-tk-new-user-login-label"><?php esc_html_e( 'New username', 'blt-toolkit' ); ?></label></th>
						<td>
							<div class="blt-tk-username-field" id="blt-tk-username-field">
								<input type="text" name="<?php echo esc_attr( self::FIELD ); ?>" id="blt-tk-new-user-login" class="regular-text" value="<?php echo esc_attr( $typed ); ?>" maxlength="<?php echo esc_attr( (string) $max ); ?>" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="blt-tk-new-user-login-desc blt-tk-new-user-login-status" />
								<p class="description" id="blt-tk-new-user-login-desc">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: current username, 2: shortest length, 3: longest length. */
											__( 'Leave blank to keep "%1$s". From %2$d to %3$d characters.', 'blt-toolkit' ),
											$login,
											$min,
											$max
										)
									);
									echo ' ' . esc_html( $after );

									if ( '' !== $slug ) {
										echo ' ' . esc_html( $slug );
									}
									?>
								</p>
								<p class="blt-tk-username-status" id="blt-tk-new-user-login-status" role="status"></p>
								<?php if ( $switched ) : ?>
									<div class="notice notice-warning inline blt-tk-username-switched">
										<p><?php echo esc_html( BLT_Toolkit_Username_Changer::switched_message( $state ) ); ?></p>
										<p>
											<label>
												<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_FIELD ); ?>" value="1" />
												<?php esc_html_e( 'Change the username and end the switched session', 'blt-toolkit' ); ?>
											</label>
										</p>
									</div>
								<?php endif; ?>
								<?php if ( $password ) : ?>
									<p class="blt-tk-username-password">
										<label for="blt-tk-username-password"><?php esc_html_e( 'Your current password', 'blt-toolkit' ); ?></label><br />
										<input type="password" name="<?php echo esc_attr( self::PASSWORD_FIELD ); ?>" id="blt-tk-username-password" class="regular-text" value="" autocomplete="current-password" spellcheck="false" />
										<span class="description"><?php esc_html_e( 'Needed to change your username. Leave blank if you are not changing it.', 'blt-toolkit' ); ?></span>
									</p>
								<?php endif; ?>
								<input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION . $user_id ) ); ?>" />
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The section for someone in their cooldown (UN-21): when they can change
	 * their username again, and no field.
	 *
	 * @param string $message The cooldown message.
	 * @return void
	 */
	private static function render_cooldown( $message ) {
		?>
		<div id="<?php echo esc_attr( self::ANCHOR ); ?>" class="blt-tk-username-section">
			<h2><?php esc_html_e( 'Change username', 'blt-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr class="blt-tk-username-row">
						<th scope="row"><?php esc_html_e( 'New username', 'blt-toolkit' ); ?></th>
						<td><p class="description"><?php echo esc_html( $message ); ?></p></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * A sentence about the author URL, when the rename would change it
	 * (UN-13): its current slug is used and the policy changes it.
	 *
	 * @param WP_User $user The user being edited.
	 * @return string '' when the author URL stays.
	 */
	private static function slug_note( WP_User $user ) {
		$policy = (string) BLT_Toolkit_Settings::get( 'usernames', 'nicename_policy' );

		if ( 'never' === $policy ) {
			return '';
		}

		if ( 'always' !== $policy && ! BLT_Toolkit_Nicename::is_derived( (string) $user->user_nicename, (string) $user->user_login ) ) {
			return '';
		}

		if ( BLT_Toolkit_Settings::get( 'usernames', 'redirect_old_slugs' ) ) {
			return __( 'The author URL changes to match, and links to the old one redirect to it.', 'blt-toolkit' );
		}

		return __( 'The author URL changes to match.', 'blt-toolkit' );
	}

	/**
	 * Load the profile script (UN-16): move the field into core's Username
	 * row behind a "Change" button and check names as they are typed. The
	 * server decides; the check is advisory.
	 *
	 * @param WP_User $user The user being edited.
	 * @param int     $min  Shortest length.
	 * @param int     $max  Longest length.
	 * @return void
	 */
	private static function enqueue( WP_User $user, $min, $max ) {
		wp_enqueue_script(
			self::SCRIPT,
			BLT_TOOLKIT_URL . 'assets/js/blt-toolkit-usernames.js',
			array(),
			BLT_TOOLKIT_VERSION,
			array( 'in_footer' => true )
		);

		$config = array(
			'root'      => esc_url_raw( rest_url( BLT_TOOLKIT_REST_NS . '/usernames/check' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'userId'    => (int) $user->ID,
			'current'   => (string) $user->user_login,
			'minLength' => (int) $min,
			'maxLength' => (int) $max,
			'anchor'    => self::ANCHOR,
			'strings'   => array(
				'change'    => __( 'Change', 'blt-toolkit' ),
				'cancel'    => __( 'Cancel', 'blt-toolkit' ),
				/* translators: %s: the user's display name. */
				'changeFor' => sprintf( __( 'Change the username of %s', 'blt-toolkit' ), $user->display_name ),
				'checking'  => __( 'Checking…', 'blt-toolkit' ),
				/* translators: %s: a username. */
				'available' => __( '"%s" is available.', 'blt-toolkit' ),
				/* translators: %s: a username. */
				'tryThis'   => __( 'Try "%s".', 'blt-toolkit' ),
				'failed'    => __( 'The name could not be checked just now. It is checked again when you save.', 'blt-toolkit' ),
			),
		);

		wp_add_inline_script(
			self::SCRIPT,
			'window.bltToolkit = window.bltToolkit || {}; window.bltToolkit.usernames = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	// ---------- UN-17 ----------

	/**
	 * Check a requested username before core saves the profile (UN-17).
	 * `user_profile_update_errors` callback.
	 *
	 * Any failure is added to core's errors, so core saves nothing and shows
	 * the form again with the message (and the typed name).
	 *
	 * @param WP_Error $errors Core's errors, by reference in core.
	 * @param bool     $update Whether an existing user is being updated.
	 * @param object   $user   The user data core is about to save.
	 * @return void
	 */
	public static function validate( $errors, $update, $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The BLT nonce is verified below, before anything else is read; core verified its own.
		if ( ! $errors instanceof WP_Error || ! $update || ! is_object( $user ) || empty( $user->ID ) || ! isset( $_POST[ self::FIELD ] ) || ! is_string( $_POST[ self::FIELD ] ) ) {
			return;
		}

		// Unsanitized on purpose: UN-1 compares the name with sanitize_user() of
		// itself and offers the result, so it must see what was typed.
		$new = trim( wp_unslash( $_POST[ self::FIELD ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by BLT_Toolkit_Username_Validator (UN-1 to UN-9) before any use.

		if ( '' === $new ) {
			return;
		}

		$user_id = (int) $user->ID;

		self::$attempt[ $user_id ] = $new;

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $user_id ) ) {
			$errors->add( 'blt_toolkit_username_nonce', __( 'The username was not changed because the form had expired. Try again.', 'blt-toolkit' ) );

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified just above.
		$confirmed = ! empty( $_POST[ self::CONFIRM_FIELD ] );
		$password  = isset( $_POST[ self::PASSWORD_FIELD ] ) && is_string( $_POST[ self::PASSWORD_FIELD ] ) ? wp_unslash( $_POST[ self::PASSWORD_FIELD ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is compared, never stored or shown; sanitizing would change it.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! current_user_can( 'blt_change_username', $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			$refusal = BLT_Toolkit_Username_Changer::refusal( $user_id );
			$errors->add( $refusal->get_error_code(), $refusal->get_error_message() );

			return;
		}

		$target = get_userdata( $user_id );

		if ( ! $target instanceof WP_User ) {
			return;
		}

		$result = BLT_Toolkit_Username_Validator::validate( $new, $target );

		if ( is_wp_error( $result ) && $result->has_errors() ) {
			foreach ( $result->get_error_codes() as $code ) {
				foreach ( $result->get_error_messages( $code ) as $message ) {
					$errors->add( $code, BLT_Toolkit_Username_Changer::public_error_message( $code, $message ) );
				}
			}

			return;
		}

		// UN-22. Checked here, against the password as stored before core saves
		// a new one from the same form.
		if ( BLT_Toolkit_Username_Changer::password_required( $user_id ) ) {
			$checked = BLT_Toolkit_Username_Changer::check_password( $target, $password );

			if ( is_wp_error( $checked ) ) {
				$errors->add( $checked->get_error_code(), $checked->get_error_message() );

				return;
			}
		}

		// UN-12.
		$self  = get_current_user_id() === $user_id;
		$state = BLT_Toolkit_Username_Changer::switched_state( $user_id, $self ? BLT_Toolkit_Username_Changer::session_token( $user_id, (string) $target->user_login ) : '' );

		if ( ( $state['switched_from'] || $state['switched_into'] ) && ! $confirmed ) {
			$errors->add( 'blt_toolkit_username_switched', BLT_Toolkit_Username_Changer::switched_message( $state ) );

			return;
		}

		self::$stash[ $user_id ] = array(
			'new'       => $new,
			'confirmed' => $confirmed,
		);
	}

	// ---------- UN-18 ----------

	/**
	 * Run the rename after core saved the profile (UN-18). `profile_update`
	 * callback; does nothing unless validate() approved a name for this user
	 * in this request.
	 *
	 * @param int $user_id The user core just saved.
	 * @return void
	 */
	public static function apply( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! isset( self::$stash[ $user_id ] ) ) {
			return;
		}

		$entry = self::$stash[ $user_id ];

		unset( self::$stash[ $user_id ], self::$attempt[ $user_id ] );

		// The password (UN-22) was checked in validate(), against the hash as
		// it was before this save.
		$result = BLT_Toolkit_Username_Changer::change(
			$user_id,
			$entry['new'],
			array(
				'source'             => 'profile',
				'confirmed_switched' => $entry['confirmed'],
			)
		);

		self::queue_notice( $user_id, $result );

		add_filter( 'wp_redirect', array( __CLASS__, 'flag_redirect' ) );
	}

	/**
	 * Keep the result for the next screen (UN-18).
	 *
	 * @param int            $user_id Target.
	 * @param array|WP_Error $result  change()'s result.
	 * @return void
	 */
	private static function queue_notice( $user_id, $result ) {
		$actor = get_current_user_id();

		if ( $actor <= 0 ) {
			return;
		}

		if ( is_wp_error( $result ) ) {
			$data = array(
				'ok'      => false,
				'message' => BLT_Toolkit_Username_Changer::public_error_message( (string) $result->get_error_code(), $result->get_error_message() ),
			);
		} else {
			$data = array(
				'ok'               => true,
				'self'             => $actor === (int) $user_id,
				'old'              => (string) $result['old_login'],
				'new'              => (string) $result['new_login'],
				'nicename_changed' => ! empty( $result['nicename_changed'] ),
				'sessions_ended'   => (int) $result['sessions_ended'],
				'session_kept'     => ! empty( $result['session_kept'] ),
			);
		}

		set_transient( self::NOTICE_TRANSIENT . $actor, $data, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Add the result flag to core's redirect after the save. `wp_redirect`
	 * filter, added only by apply() and removed after one use.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public static function flag_redirect( $location ) {
		remove_filter( 'wp_redirect', array( __CLASS__, 'flag_redirect' ) );

		return is_string( $location ) && '' !== $location ? add_query_arg( self::RESULT_ARG, '1', $location ) : $location;
	}

	/**
	 * Core's script removes the result flag from the address bar.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args   = (array) $args;
		$args[] = self::RESULT_ARG;

		return $args;
	}

	/**
	 * The rename's result after the redirect. `admin_notices` callback; reads
	 * nothing unless the redirect carried the flag.
	 *
	 * @return void
	 */
	public static function result_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the message comes from the actor's own transient, never from the URL.
		if ( empty( $_GET[ self::RESULT_ARG ] ) ) {
			return;
		}

		$actor = get_current_user_id();
		$key   = self::NOTICE_TRANSIENT . $actor;
		$data  = $actor > 0 ? get_transient( $key ) : false;

		if ( ! is_array( $data ) ) {
			return;
		}

		delete_transient( $key );

		if ( empty( $data['ok'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: why the username was not changed. */
						__( 'The username was not changed: %s', 'blt-toolkit' ),
						isset( $data['message'] ) ? (string) $data['message'] : ''
					)
				)
			);

			return;
		}

		$parts = array(
			sprintf(
				/* translators: 1: previous username, 2: new username. */
				__( 'Username changed from "%1$s" to "%2$s".', 'blt-toolkit' ),
				(string) $data['old'],
				(string) $data['new']
			),
		);

		if ( ! empty( $data['nicename_changed'] ) ) {
			$parts[] = BLT_Toolkit_Settings::get( 'usernames', 'redirect_old_slugs' )
				? __( 'The author URL changed too; links to the old one redirect to it.', 'blt-toolkit' )
				: __( 'The author URL changed too.', 'blt-toolkit' );
		}

		if ( ! empty( $data['self'] ) ) {
			$parts[] = ! empty( $data['session_kept'] )
				? __( 'You are still signed in. Use the new username the next time you sign in.', 'blt-toolkit' )
				: __( 'Sign in again with the new username.', 'blt-toolkit' );
		} elseif ( (int) $data['sessions_ended'] > 0 ) {
			$parts[] = __( 'They were signed out everywhere and sign in again with the new username.', 'blt-toolkit' );
		} else {
			$parts[] = __( 'They sign in with the new username from now on.', 'blt-toolkit' );
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( implode( ' ', $parts ) ) );
	}

	// ---------- UN-19 ----------

	/**
	 * "Change username" on the Users list, linking to the profile section
	 * (UN-19). Shown only when the profile screen opens for this user
	 * (`edit_user`) and the rename is allowed (`blt_change_username`).
	 *
	 * @param array   $actions Row actions.
	 * @param WP_User $user    The row's user.
	 * @return array
	 */
	public static function row_action( $actions, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $actions;
		}

		$user_id = (int) $user->ID;

		if ( ! current_user_can( 'blt_change_username', $user_id ) || ! current_user_can( 'edit_user', $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			return $actions;
		}

		$url = get_edit_user_link( $user_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return $actions;
		}

		// Translated once per request, not once per row (SEC-9).
		static $labels = null;

		if ( null === $labels ) {
			$labels = array(
				/* translators: %s: the user's display name. */
				'aria' => __( 'Change the username of %s', 'blt-toolkit' ),
				'text' => esc_html__( 'Change username', 'blt-toolkit' ),
			);
		}

		$actions = (array) $actions;

		$actions['blt_change_username'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( $url . '#' . self::ANCHOR ),
			esc_attr( sprintf( $labels['aria'], $user->display_name ) ),
			$labels['text']
		);

		return $actions;
	}
}
