<?php
/**
 * The rename procedure (UN-10 to UN-15).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes one user's username: the only code in BLT Toolkit that writes
 * `user_login`. Every path (profile, bulk, self-service shortcode) calls
 * change(), so the rules hold the same everywhere (SPEC 8, SPEC 19 UC-1 to
 * UC-10).
 *
 * UN-10, in this order and no other:
 *
 * 1. Authorize with `blt_change_username` for the target (the Guard maps it:
 *    oneself to `blt_change_own_username` plus the cooldown, anyone else to
 *    `blt_change_usernames` plus SEC-1 and SEC-2).
 * 2. Load and clone the user before anything is written, then validate with
 *    the one validator (BLT_Toolkit_Username_Validator::validate()). The
 *    optional password check (UN-22) and the switched-session confirmation
 *    (UN-12) come here too, before any write.
 * 3. `blt_toolkit_before_username_change`.
 * 4. `UPDATE ... SET user_login WHERE ID`, requiring exactly one row
 *    (never found by `user_login`, UC-9). The old login is a second
 *    condition, so a concurrent rename of the same user makes this one
 *    change nothing (UN-21 cooldown, UN-10 log).
 * 5. The author slug per the `usernames.nicename_policy` setting (UN-13),
 *    with core's algorithm (BLT_Toolkit_Nicename), unique among the other
 *    users; the old slug is kept for the 301 (UN-13a). 5a (UN-11, multisite,
 *    written now and never reached on a single site): the old login is
 *    replaced in place in `site_admins` (UC-7).
 * 6. `display_name` and `nickname` follow only when each exactly equals the
 *    old login.
 * 7. clean_user_cache() with the old object, so the old login's
 *    `userlogins` key and the old slug's `userslugs` key go (UC-1), then
 *    clean_user_cache() with the ID.
 * 8. Sessions (UN-14): the current user renaming themselves keeps the same
 *    session token, with a cookie re-issued for the new login (UC-10); for
 *    anyone else every session is ended when `usernames.sign_out_others` is
 *    on (their cookies stop validating anyway).
 * 9. `blt_toolkit_after_username_change`, the log row (old and new), then
 *    the optional email (UN-15).
 * 10. The result.
 */
class BLT_Toolkit_Username_Changer {

	/**
	 * Where a change came from, as recorded in the log.
	 */
	const SOURCES = array( 'profile', 'bulk', 'self', 'shortcode', 'rest' );

	/**
	 * Sources that are self-service by definition: when the
	 * `usernames.require_password` setting is on, change() requires the
	 * `current_password` option for these (UN-22), whether or not the caller
	 * passed it.
	 */
	const SELF_SERVICE_SOURCES = array( 'self', 'shortcode' );

	/**
	 * Session keys the Switch module writes (SPEC 14.4). Named here rather
	 * than read from BLT_Toolkit_Switch, so a rename never loads the switch
	 * module's code while that module is off.
	 */
	const SESSION_FROM_ID      = 'blt_switched_from_id';
	const SESSION_FROM_SESSION = 'blt_switched_from_session';
	const SESSION_INTO         = 'blt_switched_into';
	const SESSION_REMEMBER     = 'blt_switched_remember';

	/**
	 * Most switch links followed back to the person who signed in: the
	 * switch stack's depth (BLT_Toolkit_Switch_Cookies::MAX_DEPTH, not read
	 * from there for the same reason as the keys above).
	 */
	const MAX_LINKS = 10;

	/**
	 * User meta holding the times (Unix) of the person's recent wrong
	 * current-password attempts (UN-22 throttle), newest last, at most the
	 * limit's worth. Deleted after a correct password and by uninstall.
	 */
	const PASSWORD_FAILURES_META = 'blt_toolkit_password_failures';

	/**
	 * Default UN-22 throttle: this many wrong current passwords within the
	 * window pause the person's self-service username changes until the
	 * oldest of them is a window old (filter
	 * `blt_toolkit_username_password_throttle`).
	 */
	const PASSWORD_LIMIT  = 5;
	const PASSWORD_WINDOW = 900;

	/**
	 * Change a user's username (UN-10).
	 *
	 * @param int    $user_id  Whose username changes.
	 * @param string $new_name The new username (trimmed here, then validated as is).
	 * @param array  $options  {
	 *     Optional.
	 *
	 *     @type bool   $notify             Email the user (UN-15). Default: setting `usernames.notify_user`.
	 *     @type bool   $sign_out_others    End every session of another user (UN-14). Default: setting `usernames.sign_out_others`.
	 *     @type string $source             `profile` (default), `bulk`, `self`, `shortcode` or `rest`.
	 *     @type bool   $confirmed_switched The UN-12 confirmation was given. Default false.
	 *     @type string $current_password   The user's current password (UN-22). When passed it is
	 *                                      checked for a self rename; it is required for the
	 *                                      self-service sources when the setting is on.
	 *     @type string $nicename_policy    `if_derived`, `always` or `never` (UN-13). Default: setting
	 *                                      `usernames.nicename_policy`. A bulk rename pins it (JOB-3).
	 *     @type string $email_subject      Email subject template (UN-15). Default: the setting.
	 *     @type string $email_body         Email body template (UN-15). Default: the setting.
	 * }
	 * @return array|WP_Error {
	 *     @type string $old_login        The previous login.
	 *     @type string $new_login        The new login.
	 *     @type bool   $nicename_changed Whether the author slug changed.
	 *     @type string $old_nicename     The previous author slug.
	 *     @type string $new_nicename     The author slug now (extra key).
	 *     @type int    $sessions_ended   Sessions destroyed (0 when none).
	 *     @type bool   $session_kept     A self rename kept the current session (extra key).
	 * }
	 */
	public static function change( $user_id, $new_name, array $options = array() ) {
		global $wpdb;

		$user_id  = (int) $user_id;
		$new_name = is_string( $new_name ) ? BLT_Toolkit_Username_Validator::normalize( $new_name ) : ''; // UN-1: the trimmed name is what is validated and stored.
		$options  = self::options( $options );
		$self     = $user_id > 0 && get_current_user_id() === $user_id;

		// 1. Authorize (SPEC 5.1; SEC-1, SEC-2 and the cooldown live in the Guard).
		if ( $user_id <= 0 ) {
			return new WP_Error( 'blt_toolkit_username_no_user', __( 'That user does not exist.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'blt_change_username', $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			return self::refusal( $user_id );
		}

		// 2. Load and clone before any write, then validate (UN-1 to UN-9).
		$fresh = get_userdata( $user_id );

		if ( ! $fresh instanceof WP_User || ! $fresh->exists() ) {
			return new WP_Error( 'blt_toolkit_username_no_user', __( 'That user does not exist.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		$old       = clone $fresh;
		$old->data = clone $fresh->data;
		$old_login = (string) $old->user_login;

		$errors = BLT_Toolkit_Username_Validator::validate( $new_name, $old );

		if ( ! BLT_Toolkit_Username_Validator::is_valid( $errors ) ) {
			return $errors;
		}

		// UN-22: the password, when asked for.
		if ( $self && self::password_required( $user_id ) && ( array_key_exists( 'current_password', $options ) || in_array( $options['source'], self::SELF_SERVICE_SOURCES, true ) ) ) {
			$password = isset( $options['current_password'] ) && is_string( $options['current_password'] ) ? $options['current_password'] : '';
			$checked  = self::check_password( $old, $password );

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
		}

		// UN-12: someone is switched into this user, or this user is switched
		// into someone. A self rename keeps the current session, so it does
		// not count.
		$state = self::switched_state( $user_id, $self ? self::session_token( $user_id, $old_login ) : '' );

		if ( ( $state['switched_from'] || $state['switched_into'] ) && ! $options['confirmed_switched'] ) {
			return new WP_Error(
				'blt_toolkit_username_switched',
				self::switched_message( $state ),
				array_merge( array( 'status' => 409 ), $state )
			);
		}

		// 3.
		/**
		 * Fires before a username is written (UN-10 step 3): the change is
		 * authorized and the new name validated.
		 *
		 * @param int    $user_id The user being renamed.
		 * @param string $old     The current login.
		 * @param string $new     The new login.
		 */
		do_action( 'blt_toolkit_before_username_change', $user_id, $old_login, $new_name );

		// 4. By ID, exactly one row (UC-9), and only while the login is still
		// the one authorized and validated in steps 1 and 2. Two requests
		// that passed those steps together (the UN-21 cooldown is read in
		// step 1 and written after the write) cannot both rename: the
		// second finds no row and changes nothing, so neither the cooldown
		// nor the log's "from" can be outrun. The row is still found by its
		// ID; the login is only the condition.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UN-10 step 4: core has no API that changes user_login; both user caches are cleared in step 7.
		$updated  = $wpdb->update(
			$wpdb->users,
			array( 'user_login' => $new_name ),
			array(
				'ID'         => $user_id,
				'user_login' => $old_login,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		$db_error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( 1 !== $updated ) {
			// Whatever was written, the caches must not keep a stale login.
			clean_user_cache( $old );
			clean_user_cache( $user_id );

			self::log_failure( $old, $new_name, $options['source'], false === $updated ? wp_strip_all_tags( $db_error ) : sprintf( 'rows affected: %d (the username changed in another request)', (int) $updated ) );

			if ( 0 === $updated ) {
				return new WP_Error( 'blt_toolkit_username_not_saved', __( 'The username was changed by another request at the same moment, so this change was not saved. Reload the page to see the username now.', 'blt-toolkit' ), array( 'status' => 409 ) );
			}

			return new WP_Error( 'blt_toolkit_username_not_saved', __( 'The username could not be saved. Nothing was changed; try again.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		// 5 and 6. Author slug, display name; one write for both.
		$old_nicename = (string) $old->user_nicename;
		$new_nicename = self::new_nicename( $old, $new_name, $options['nicename_policy'] );
		$fields       = array();

		if ( '' !== $new_nicename && $new_nicename !== $old_nicename ) {
			$fields['user_nicename'] = $new_nicename;
		} else {
			$new_nicename = $old_nicename;
		}

		$display_changed = (string) $old->display_name === $old_login;

		if ( $display_changed ) {
			$fields['display_name'] = $new_name;
		}

		$extra_ok = true;

		if ( array() !== $fields ) {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UN-10 steps 5 and 6, by ID; caches cleared in step 7.
			$extra_ok = false !== $wpdb->update( $wpdb->users, $fields, array( 'ID' => $user_id ), array_fill( 0, count( $fields ), '%s' ), array( '%d' ) );
			$wpdb->suppress_errors( $suppress );

			if ( ! $extra_ok ) {
				$new_nicename    = $old_nicename;
				$display_changed = false;
			}
		}

		// 5a. UN-11, for the multisite path (D1 defers multisite; never reached
		// on a single site, where the plugin runs).
		self::replace_site_admin( $old_login, $new_name );

		$nicename_changed = $new_nicename !== $old_nicename;
		$nickname_changed = false;

		if ( (string) get_user_meta( $user_id, 'nickname', true ) === $old_login ) {
			$nickname_changed = (bool) update_user_meta( $user_id, 'nickname', $new_name );
		}

		// 7. The old object first: its login, slug and email keys (UC-1).
		clean_user_cache( $old );
		clean_user_cache( $user_id );

		// UN-13a: the old slug redirects to the new one.
		if ( $nicename_changed ) {
			BLT_Toolkit_Author_Redirects::remember( $user_id, $old_nicename, $new_nicename );
		}

		// UN-21: the cooldown counts from a self-service change.
		if ( $self ) {
			update_user_meta( $user_id, BLT_Toolkit_Guard::CHANGED_AT_META, time() );
		}

		// 8. Sessions (UN-14).
		$sessions = self::handle_sessions( $user_id, $old_login, $self, $options['sign_out_others'] );

		$result = array(
			'old_login'        => $old_login,
			'new_login'        => $new_name,
			'nicename_changed' => $nicename_changed,
			'old_nicename'     => $old_nicename,
			'new_nicename'     => $new_nicename,
			'sessions_ended'   => $sessions['ended'],
			'session_kept'     => $sessions['kept'],
		);

		// 9.
		/**
		 * Fires after a username changed: the login, author slug, display
		 * name and nickname are written, both user caches are cleared and
		 * sessions are handled (UN-10 step 9).
		 *
		 * @param int    $user_id The renamed user.
		 * @param string $old     The previous login.
		 * @param string $new     The new login.
		 * @param array  $result  What change() returns.
		 */
		do_action( 'blt_toolkit_after_username_change', $user_id, $old_login, $new_name, $result );

		$user = get_userdata( $user_id );

		self::log_change(
			$old,
			$new_name,
			$result,
			array(
				'source'               => $options['source'],
				'self'                 => $self,
				'display_name_changed' => $display_changed,
				'nickname_changed'     => $nickname_changed,
				'switched_confirmed'   => $state['switched_from'] || $state['switched_into'],
				'partial'              => ! $extra_ok,
			)
		);

		if ( $options['notify'] && $user instanceof WP_User ) {
			self::send_email( $user, $old_login, $new_name, $options['email_subject'], $options['email_body'] );
		}

		// 10.
		return $result;
	}

	/**
	 * Fill in the options from the settings (UN-14, UN-15).
	 *
	 * @param array $options Caller's options.
	 * @return array
	 */
	private static function options( array $options ) {
		$out = array(
			'notify'             => array_key_exists( 'notify', $options ) ? (bool) $options['notify'] : (bool) BLT_Toolkit_Settings::get( 'usernames', 'notify_user' ),
			'sign_out_others'    => array_key_exists( 'sign_out_others', $options ) ? (bool) $options['sign_out_others'] : (bool) BLT_Toolkit_Settings::get( 'usernames', 'sign_out_others' ),
			'source'             => isset( $options['source'] ) && in_array( $options['source'], self::SOURCES, true ) ? $options['source'] : 'profile',
			'confirmed_switched' => ! empty( $options['confirmed_switched'] ),
			'nicename_policy'    => isset( $options['nicename_policy'] ) && in_array( $options['nicename_policy'], BLT_Toolkit_Settings::NICENAME_POLICIES, true ) ? $options['nicename_policy'] : (string) BLT_Toolkit_Settings::get( 'usernames', 'nicename_policy' ),
			'email_subject'      => isset( $options['email_subject'] ) && is_string( $options['email_subject'] ) ? $options['email_subject'] : null,
			'email_body'         => isset( $options['email_body'] ) && is_string( $options['email_body'] ) ? $options['email_body'] : null,
		);

		if ( array_key_exists( 'current_password', $options ) ) {
			$out['current_password'] = is_string( $options['current_password'] ) ? $options['current_password'] : '';
		}

		return $out;
	}

	/**
	 * Why `blt_change_username` refused, in words the person can act on.
	 *
	 * Someone else's account gets one message whatever the reason, so a
	 * refusal never tells a delegated user who is an administrator (SEC-1)
	 * or more powerful than them (SEC-2).
	 *
	 * @param int $user_id Target.
	 * @return WP_Error `blt_toolkit_username_forbidden` (403), or
	 *                  `blt_toolkit_username_cooldown` (403, data `until`).
	 */
	public static function refusal( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id > 0 && get_current_user_id() === $user_id ) {
			$until = BLT_Toolkit_Guard::username_cooldown_until( $user_id );

			if ( $until > time() && current_user_can( 'blt_change_own_username' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
				return new WP_Error(
					'blt_toolkit_username_cooldown',
					sprintf(
						/* translators: %s: date and time. */
						__( 'You changed your username recently. You can change it again after %s.', 'blt-toolkit' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $until )
					),
					array(
						'status' => 403,
						'until'  => $until,
					)
				);
			}

			return new WP_Error( 'blt_toolkit_username_forbidden', __( 'You are not allowed to change your own username.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		return new WP_Error( 'blt_toolkit_username_forbidden', __( 'You are not allowed to change this user\'s username.', 'blt-toolkit' ), array( 'status' => 403 ) );
	}

	/**
	 * A validation message as shown to the person who typed the name.
	 *
	 * UN-6 refuses a username equal to another user's email address. Saying
	 * so to someone who may not list users would tell them the address
	 * belongs to an account, so for them that refusal reads exactly as
	 * UN-5's "already taken", code included (public_error_code()). The
	 * refusal itself still says the string belongs to some account, as a
	 * taken name does, and as core's registration and lost-password screens
	 * do; it no longer says that it is an email address. The rule is
	 * unchanged.
	 *
	 * @param string $code    Validator error code.
	 * @param string $message Validator message.
	 * @return string
	 */
	public static function public_error_message( $code, $message ) {
		if ( self::masks_email_rule( $code ) ) {
			return BLT_Toolkit_Username_Validator::taken_message();
		}

		return (string) $message;
	}

	/**
	 * A validation error code as shown to the person who typed the name:
	 * UN-6's reads as UN-5's for someone who may not list users (see
	 * public_error_message()).
	 *
	 * @param string $code Validator error code.
	 * @return string
	 */
	public static function public_error_code( $code ) {
		return self::masks_email_rule( $code ) ? 'blt_toolkit_username_taken' : (string) $code;
	}

	/**
	 * Whether a UN-6 refusal is shown as UN-5's to the current user.
	 *
	 * @param string $code Validator error code.
	 * @return bool
	 */
	private static function masks_email_rule( $code ) {
		return 'blt_toolkit_username_email' === (string) $code && ! current_user_can( 'list_users' );
	}

	// ---------- UN-22 ----------

	/**
	 * Whether renaming this user needs the current password (UN-22): the
	 * setting is on and the user is renaming themselves.
	 *
	 * @param int $user_id Target.
	 * @return bool
	 */
	public static function password_required( $user_id ) {
		$user_id = (int) $user_id;

		return $user_id > 0 && get_current_user_id() === $user_id && (bool) BLT_Toolkit_Settings::get( 'usernames', 'require_password' );
	}

	/**
	 * Check a user's current password (UN-22), throttled.
	 *
	 * Every path that asks for the password calls this: the profile screen
	 * (BLT_Toolkit_Username_Profile), and change() for the shortcode and any
	 * other self-service caller. Without a throttle a hijacked session could
	 * use the rename form to guess the account's password, so wrong
	 * passwords are counted per user: after the limit within the window
	 * (default 5 within 15 minutes, filter
	 * `blt_toolkit_username_password_throttle`) the password is not checked
	 * at all, right or wrong, until the oldest of those attempts is a window
	 * old (`blt_toolkit_username_password_locked`, 429, with `until`). The
	 * attempt that reaches the limit is logged once (`usernames` /
	 * `password_locked`, status `warning`). A correct password clears the
	 * count. Signing in is not affected: this counts only the rename form's
	 * password field.
	 *
	 * @param WP_User $user     The user, as stored before any change.
	 * @param string  $password What was typed.
	 * @return true|WP_Error `blt_toolkit_username_password` (403) or
	 *                       `blt_toolkit_username_password_locked` (429).
	 */
	public static function check_password( WP_User $user, $password ) {
		$user_id  = (int) $user->ID;
		$throttle = self::password_throttle( $user );
		$now      = time();
		$stored   = $throttle['limit'] > 0 ? get_user_meta( $user_id, self::PASSWORD_FAILURES_META, true ) : array();
		$state    = self::throttle_state( $stored, $now, $throttle['limit'], $throttle['window'] );

		if ( $state['locked_until'] > 0 ) {
			return self::locked_error( $state['locked_until'] );
		}

		if ( ! is_string( $password ) || '' === $password || ! wp_check_password( $password, (string) $user->user_pass, $user_id ) ) {
			if ( $throttle['limit'] > 0 ) {
				$after = self::throttle_state( array_merge( $state['failures'], array( $now ) ), $now, $throttle['limit'], $throttle['window'] );

				update_user_meta( $user_id, self::PASSWORD_FAILURES_META, $after['failures'] );

				if ( $after['locked_until'] > 0 ) {
					self::log_lock( $user, count( $after['failures'] ), $after['locked_until'], $throttle['window'] );
				}
			}

			return new WP_Error( 'blt_toolkit_username_password', __( 'Your current password is not correct, so your username was not changed.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $stored ) ) {
			delete_user_meta( $user_id, self::PASSWORD_FAILURES_META );
		}

		return true;
	}

	/**
	 * The UN-22 throttle for a user: how many wrong passwords within how
	 * many seconds pause their self-service renames. Filter
	 * `blt_toolkit_username_password_throttle`; a limit of 0 turns the
	 * throttle off.
	 *
	 * @param WP_User $user The user renaming themselves.
	 * @return array{limit:int,window:int}
	 */
	public static function password_throttle( WP_User $user ) {
		/**
		 * Filter the throttle on wrong current passwords in the username
		 * forms (UN-22): after `limit` wrong passwords within `window`
		 * seconds, the person's self-service renames pause until the oldest
		 * of them is `window` seconds old. Return a `limit` of 0 to turn it
		 * off.
		 *
		 * @param array   $throttle `limit` (default 5) and `window` (seconds, default 900).
		 * @param WP_User $user     The user renaming themselves.
		 */
		$throttle = apply_filters(
			'blt_toolkit_username_password_throttle',
			array(
				'limit'  => self::PASSWORD_LIMIT,
				'window' => self::PASSWORD_WINDOW,
			),
			$user
		);

		return self::clean_throttle( $throttle );
	}

	/**
	 * A filtered throttle as two integers: `limit` 0 (off) to 100,
	 * `window` 1 second to 1 day; anything missing or not numeric takes the
	 * default. Pure.
	 *
	 * @param mixed $throttle Filtered value.
	 * @return array{limit:int,window:int}
	 */
	public static function clean_throttle( $throttle ) {
		$throttle = is_array( $throttle ) ? $throttle : array();
		$limit    = isset( $throttle['limit'] ) && is_numeric( $throttle['limit'] ) ? (int) $throttle['limit'] : self::PASSWORD_LIMIT;
		$window   = isset( $throttle['window'] ) && is_numeric( $throttle['window'] ) ? (int) $throttle['window'] : self::PASSWORD_WINDOW;

		return array(
			'limit'  => min( 100, max( 0, $limit ) ),
			'window' => min( DAY_IN_SECONDS, max( 1, $window ) ),
		);
	}

	/**
	 * Where a user stands with the UN-22 throttle at `$now`. Pure.
	 *
	 * `failures` keeps the stored attempts that are numbers, not in the
	 * future and less than a window old, oldest first, at most `$limit` of
	 * them (the newest). The person is paused while they number `$limit`:
	 * until the oldest of them is a window old (`locked_until`, else 0).
	 * A limit of 0 never pauses and keeps nothing.
	 *
	 * @param mixed $stored The stored meta value.
	 * @param int   $now    Current time.
	 * @param int   $limit  Wrong passwords allowed within the window.
	 * @param int   $window Seconds.
	 * @return array{failures:int[],locked_until:int}
	 */
	public static function throttle_state( $stored, $now, $limit, $window ) {
		$now      = (int) $now;
		$limit    = max( 0, (int) $limit );
		$window   = max( 1, (int) $window );
		$failures = array();

		if ( $limit > 0 && is_array( $stored ) ) {
			foreach ( $stored as $time ) {
				if ( is_numeric( $time ) && (int) $time <= $now && (int) $time > $now - $window ) {
					$failures[] = (int) $time;
				}
			}

			sort( $failures );
			$failures = array_slice( $failures, -$limit );
		}

		return array(
			'failures'     => $failures,
			'locked_until' => $limit > 0 && count( $failures ) >= $limit ? $failures[0] + $window : 0,
		);
	}

	/**
	 * The error for a paused rename (UN-22 throttle).
	 *
	 * @param int $until When renaming with the password works again.
	 * @return WP_Error `blt_toolkit_username_password_locked` (429).
	 */
	private static function locked_error( $until ) {
		return new WP_Error(
			'blt_toolkit_username_password_locked',
			sprintf(
				/* translators: %s: date and time. */
				__( 'Your current password was entered wrongly too many times, so your username was not changed. You can try again after %s.', 'blt-toolkit' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $until )
			),
			array(
				'status' => 429,
				'until'  => (int) $until,
			)
		);
	}

	/**
	 * Log the attempt that paused someone's renames (UN-22 throttle).
	 *
	 * @param WP_User $user   Whose renames are paused.
	 * @param int     $count  Wrong passwords within the window.
	 * @param int     $until  When renaming works again.
	 * @param int     $window The window, in seconds.
	 * @return void
	 */
	private static function log_lock( WP_User $user, $count, $until, $window ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => 'password_locked',
				'object_type' => 'user',
				'object_id'   => (string) (int) $user->ID,
				'summary'     => sprintf(
					/* translators: 1: number of wrong passwords, 2: number of minutes. */
					_n( 'Username changes paused after %1$s wrong current password within %2$s minutes.', 'Username changes paused after %1$s wrong current passwords within %2$s minutes.', (int) $count, 'blt-toolkit' ),
					number_format_i18n( (int) $count ),
					number_format_i18n( (int) ceil( (int) $window / MINUTE_IN_SECONDS ) )
				),
				'details'     => array(
					'attempts' => (int) $count,
					'until'    => gmdate( 'Y-m-d H:i:s', (int) $until ),
				),
				'status'      => 'warning',
			)
		);
	}

	// ---------- UN-12 ----------

	/**
	 * Whether the user is in a switched session (UN-12, X-4): someone is
	 * switched into them (`blt_switched_from_id` on one of their sessions),
	 * or they are switched into someone (`blt_switched_into` on one of their
	 * sessions). Renaming ends such a session: its cookies embed the old
	 * login.
	 *
	 * Only live switches count, by the rule the Switch module uses for its
	 * duplicate-switch warning (SW-8, BLT_Toolkit_Switch::live_switch_from()):
	 * a switched session counts while its links lead back to the live session
	 * of the person who signed in, and a `blt_switched_into` marker counts
	 * while the account it names has such a session switched in from this
	 * user. A switch whose origin session ended (sign-out, "Log out
	 * everywhere", expiry) is over, even before the Switch module cleans it
	 * up on its next request, so it raises no warning.
	 *
	 * Reads the sessions through WP_Session_Tokens, so a custom session
	 * manager is respected and expired sessions do not count.
	 *
	 * @param int    $user_id      User.
	 * @param string $except_token A session to leave out: the current one, for
	 *                             a self rename, which keeps it (UN-14).
	 * @return array{switched_into:bool,switched_from:bool,from_ids:int[],into_ids:int[]}
	 */
	public static function switched_state( $user_id, $except_token = '' ) {
		$user_id = (int) $user_id;
		$empty   = self::switched_flags( array() );

		if ( $user_id <= 0 || ! class_exists( 'WP_Session_Tokens' ) ) {
			return $empty;
		}

		$manager  = WP_Session_Tokens::get_instance( $user_id );
		$sessions = $manager->get_all();

		if ( is_string( $except_token ) && '' !== $except_token ) {
			$skip = $manager->get( $except_token );

			if ( is_array( $skip ) ) {
				$index = array_search( $skip, $sessions, true );

				if ( false !== $index ) {
					unset( $sessions[ $index ] );
				}
			}
		}

		return self::switched_flags(
			is_array( $sessions ) ? $sessions : array(),
			$user_id,
			array( __CLASS__, 'session_by_verifier' ),
			static function ( $id ) {
				$all = WP_Session_Tokens::get_instance( (int) $id )->get_all();

				return is_array( $all ) ? $all : array();
			}
		);
	}

	/**
	 * The switched flags of a list of sessions (UN-12). Pure: other sessions
	 * are found through the lookups.
	 *
	 * Without lookups every marker counts. With them only live switches do
	 * (see switched_state()): a session switched into the user counts when
	 * leads_to_origin(), and a `blt_switched_into` marker counts when the
	 * account it names has a session switched in from `$user_id` that leads
	 * to its origin.
	 *
	 * @param array         $sessions    Session arrays, as WP_Session_Tokens::get_all() returns them.
	 * @param int           $user_id     Whose sessions they are (for the `blt_switched_into` check).
	 * @param callable|null $lookup      function( int $user_id, string $verifier ): array|null, one live session.
	 * @param callable|null $sessions_of function( int $user_id ): array, a user's live sessions.
	 * @return array{switched_into:bool,switched_from:bool,from_ids:int[],into_ids:int[]}
	 */
	public static function switched_flags( array $sessions, $user_id = 0, $lookup = null, $sessions_of = null ) {
		$user_id     = (int) $user_id;
		$lookup      = is_callable( $lookup ) ? $lookup : null;
		$sessions_of = is_callable( $sessions_of ) && $user_id > 0 ? $sessions_of : null;
		$from        = array();
		$into        = array();

		foreach ( $sessions as $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}

			$from_id = self::session_int( $session, self::SESSION_FROM_ID );
			$into_id = self::session_int( $session, self::SESSION_INTO );

			if ( $from_id > 0 && ( null === $lookup || self::leads_to_origin( $session, $lookup ) ) ) {
				$from[] = $from_id;
			}

			if ( $into_id > 0 ) {
				$into[] = $into_id;
			}
		}

		$from = array_values( array_unique( $from ) );
		$into = array_values( array_unique( $into ) );

		if ( null !== $sessions_of ) {
			$into = array_values(
				array_filter(
					$into,
					static function ( $target ) use ( $user_id, $lookup, $sessions_of ) {
						foreach ( (array) call_user_func( $sessions_of, $target ) as $session ) {
							if ( is_array( $session ) && self::session_int( $session, self::SESSION_FROM_ID ) === $user_id && ( null === $lookup || self::leads_to_origin( $session, $lookup ) ) ) {
								return true;
							}
						}

						return false;
					}
				)
			);
		}

		return array(
			'switched_into' => array() !== $into,
			'switched_from' => array() !== $from,
			'from_ids'      => $from,
			'into_ids'      => $into,
		);
	}

	/**
	 * Whether a switched session still leads back to the session of the
	 * person who signed in (UN-12), by the rule of the Switch module's
	 * BLT_Toolkit_Switch::follow_links(): each link is
	 * `blt_switched_from_id` plus `blt_switched_from_session` (the verifier
	 * of the session switched from), and the walk must end at a live session
	 * that was not switched into. A link with no verifier, a session that is
	 * gone, a repeated link or a walk deeper than MAX_LINKS breaks it. Pure.
	 *
	 * @param mixed    $session A session.
	 * @param callable $lookup  function( int $user_id, string $verifier ): array|null, the live session or null.
	 * @return bool
	 */
	public static function leads_to_origin( $session, callable $lookup ) {
		$seen  = array();
		$links = 0;

		while ( is_array( $session ) ) {
			$from_id  = self::session_int( $session, self::SESSION_FROM_ID );
			$verifier = isset( $session[ self::SESSION_FROM_SESSION ] ) && is_string( $session[ self::SESSION_FROM_SESSION ] ) ? $session[ self::SESSION_FROM_SESSION ] : '';

			if ( $from_id <= 0 ) {
				return true;
			}

			$key = $from_id . '|' . $verifier;

			if ( '' === $verifier || $links >= self::MAX_LINKS || isset( $seen[ $key ] ) ) {
				return false;
			}

			$seen[ $key ] = true;
			++$links;
			$session = call_user_func( $lookup, $from_id, $verifier );
		}

		return false;
	}

	/**
	 * One of a user's live sessions by its verifier (the key core stores it
	 * under), or null, through the session manager's own get_session(), so
	 * a custom manager is respected and an expired session does not count.
	 * The same read as BLT_Toolkit_Switch::session_by_verifier(), kept here
	 * so a rename never loads the Switch module's code.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Session verifier.
	 * @return array|null
	 */
	public static function session_by_verifier( $user_id, $verifier ) {
		if ( (int) $user_id <= 0 || '' === (string) $verifier || ! class_exists( 'WP_Session_Tokens' ) ) {
			return null;
		}

		$manager = WP_Session_Tokens::get_instance( (int) $user_id );

		// get_session() is protected; read it in the manager's own scope.
		$read = Closure::bind(
			function ( $key ) {
				return $this->get_session( $key );
			},
			$manager,
			get_class( $manager )
		);

		$session = $read instanceof Closure ? $read( (string) $verifier ) : null;

		return is_array( $session ) ? $session : null;
	}

	/**
	 * A positive integer stored under a session key, or 0. Pure.
	 *
	 * @param array  $session Session.
	 * @param string $key     Key.
	 * @return int
	 */
	private static function session_int( array $session, $key ) {
		return ! empty( $session[ $key ] ) && is_numeric( $session[ $key ] ) && (int) $session[ $key ] > 0 ? (int) $session[ $key ] : 0;
	}

	/**
	 * The UN-12 warning, in words.
	 *
	 * @param array $state switched_state().
	 * @return string
	 */
	public static function switched_message( array $state ) {
		if ( ! empty( $state['switched_from'] ) && ! empty( $state['switched_into'] ) ) {
			return __( 'Someone is switched into this account, and this person is switched into another account, right now. Changing the username ends both switched sessions. Confirm to go ahead.', 'blt-toolkit' );
		}

		if ( ! empty( $state['switched_from'] ) ) {
			return __( 'Someone is switched into this account right now. Changing the username ends that switched session. Confirm to go ahead.', 'blt-toolkit' );
		}

		return __( 'This person is switched into another account right now. Changing the username ends that switched session, and they cannot switch back. Confirm to go ahead.', 'blt-toolkit' );
	}

	// ---------- UN-13 ----------

	/**
	 * The author slug the user should have after the rename (UN-13), or the
	 * current one when the policy leaves it alone.
	 *
	 * Same algorithm as wp_insert_user(): sanitize_title() of the first 50
	 * characters, core's `pre_user_nicename` filter, then a `-2`, `-3` ...
	 * suffix while another user has it. A slug longer than 50 characters
	 * after the filter is not used (core refuses it too), and neither is a
	 * base whose suffixes are all taken: the current slug stays.
	 *
	 * The "generated from the username" test (BLT_Toolkit_Nicename::is_derived())
	 * gets the same sanitizer, `pre_user_nicename` included, so it matches on
	 * a site that filters slugs.
	 *
	 * Public so the bulk dry run predicts the slug exactly as the rename
	 * will write it (UN-30 "author URL will change"). Reads the database for
	 * the uniqueness check; call it only when a rename is being planned.
	 *
	 * @param WP_User     $old       The user as stored before the change.
	 * @param string      $new_login The new login.
	 * @param string|null $policy    `if_derived`, `always` or `never`; null reads the
	 *                               `usernames.nicename_policy` setting. A bulk rename
	 *                               passes the policy pinned when it started (JOB-3).
	 * @return string
	 */
	public static function new_nicename( WP_User $old, $new_login, $policy = null ) {
		global $wpdb;

		$policy  = null === $policy ? (string) BLT_Toolkit_Settings::get( 'usernames', 'nicename_policy' ) : (string) $policy;
		$current = (string) $old->user_nicename;

		if ( 'never' === $policy ) {
			return $current;
		}

		$sanitize = array( __CLASS__, 'sanitize_nicename' );

		if ( 'always' !== $policy && ! BLT_Toolkit_Nicename::is_derived( $current, (string) $old->user_login, $sanitize ) ) {
			return $current;
		}

		$base = BLT_Toolkit_Nicename::base( (string) $new_login, $sanitize );

		if ( ! BLT_Toolkit_Nicename::fits( $base ) ) {
			return $current;
		}

		$user_id = (int) $old->ID;
		$slug    = BLT_Toolkit_Nicename::unique(
			$base,
			static function ( $slug ) use ( $wpdb, $user_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The same uniqueness query wp_insert_user() runs, keyed on ID instead of login.
				return null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s AND ID != %d LIMIT 1", $slug, $user_id ) );
			}
		);

		// '' means every suffix was taken (BLT_Toolkit_Nicename::MAX_SUFFIX).
		return '' === $slug ? $current : $slug;
	}

	/**
	 * Core's slug sanitizer for logins, as wp_insert_user() applies it:
	 * sanitize_title(), then the `pre_user_nicename` filter (UN-13).
	 *
	 * @param string $text Login, already cut to 50 characters.
	 * @return string
	 */
	public static function sanitize_nicename( $text ) {
		/** This filter is documented in wp-includes/user.php */
		$slug = apply_filters( 'pre_user_nicename', sanitize_title( (string) $text ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as wp_insert_user() does.

		return is_string( $slug ) ? $slug : '';
	}

	// ---------- UN-11 ----------

	/**
	 * Multisite only (UN-11): replace the old login in `site_admins` in place,
	 * never adding the new one and leaving the old (SPEC 19, UC-7). The
	 * plugin does not run on multisite in this version (D1), so this returns
	 * at once; it is written now so the network path cannot forget it.
	 *
	 * @param string $old_login Previous login.
	 * @param string $new_login New login.
	 * @return bool Whether `site_admins` was changed.
	 */
	private static function replace_site_admin( $old_login, $new_login ) {
		if ( ! is_multisite() ) {
			return false;
		}

		$admins = get_site_option( 'site_admins', array() );

		if ( ! is_array( $admins ) ) {
			return false;
		}

		$key = array_search( (string) $old_login, $admins, true );

		if ( false === $key ) {
			return false;
		}

		$admins[ $key ] = (string) $new_login;

		return (bool) update_site_option( 'site_admins', $admins );
	}

	// ---------- UN-14 ----------

	/**
	 * End or keep sessions after the rename (UN-14).
	 *
	 * - The current user renaming themselves: the auth cookies are issued
	 *   again for the new login with the same session token, so the session
	 *   goes on (UC-10). With `sign_out_others` on, their other sessions end
	 *   (their cookies carry the old login and no longer validate).
	 * - Anyone else: with `sign_out_others` on, every session ends.
	 *
	 * @param int    $user_id         User.
	 * @param string $old_login       Login before the change.
	 * @param bool   $is_self         Whether the current user renamed themselves.
	 * @param bool   $sign_out_others Setting `usernames.sign_out_others` (or the option).
	 * @return array{ended:int,kept:bool}
	 */
	private static function handle_sessions( $user_id, $old_login, $is_self, $sign_out_others ) {
		$manager = WP_Session_Tokens::get_instance( (int) $user_id );
		$out     = array(
			'ended' => 0,
			'kept'  => false,
		);

		if ( $is_self ) {
			$token = self::session_token( $user_id, $old_login );

			if ( '' !== $token ) {
				$out['kept'] = self::reissue_cookie( $user_id, $token );
			}

			if ( $sign_out_others ) {
				$before = count( $manager->get_all() );

				if ( $out['kept'] ) {
					$manager->destroy_others( $token );
				} else {
					$manager->destroy_all();
				}

				$out['ended'] = max( 0, $before - count( $manager->get_all() ) );
			}

			return $out;
		}

		if ( $sign_out_others ) {
			$out['ended'] = count( $manager->get_all() );
			$manager->destroy_all();
		}

		return $out;
	}

	/**
	 * The current request's session token, when it is a live session of this
	 * user signed in under that login; '' otherwise (a WP-Cron or job tick
	 * run as the user, or a cookie that belongs to someone else).
	 *
	 * @param int    $user_id   User.
	 * @param string $old_login Login the cookie must carry (the login before the change).
	 * @return string
	 */
	public static function session_token( $user_id, $old_login ) {
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );

		if ( ! is_array( $cookie ) || empty( $cookie['token'] ) || ! isset( $cookie['username'] ) || (string) $cookie['username'] !== (string) $old_login ) {
			return '';
		}

		$token = (string) $cookie['token'];

		return is_array( WP_Session_Tokens::get_instance( (int) $user_id )->get( $token ) ) ? $token : '';
	}

	/**
	 * Issue the auth cookies again for the new login with the same session
	 * token (UN-14): wp_set_auth_cookie( $user_id, $remember, '', $token ).
	 *
	 * The cookie keeps the expiration of the one it replaces, so the session
	 * neither grows nor shrinks, and "Remember Me" is kept: read from the
	 * switched session's own flag when there is one (the Switch module caps
	 * those sessions), otherwise from the cookie's remaining life, as core's
	 * wp_update_user() does.
	 *
	 * @param int    $user_id User.
	 * @param string $token   Session token to keep.
	 * @return bool False when headers were already sent or the cookie expired.
	 */
	private static function reissue_cookie( $user_id, $token ) {
		if ( headers_sent() ) {
			return false;
		}

		$cookie  = wp_parse_auth_cookie( '', 'logged_in' );
		$life    = is_array( $cookie ) && isset( $cookie['expiration'] ) ? (int) $cookie['expiration'] - time() : 0;
		$session = WP_Session_Tokens::get_instance( (int) $user_id )->get( $token );

		if ( $life <= 0 || ! is_array( $session ) ) {
			return false;
		}

		if ( isset( $session[ self::SESSION_REMEMBER ] ) ) {
			$remember = (bool) $session[ self::SESSION_REMEMBER ];
		} else {
			/** This filter is documented in wp-includes/pluggable.php */
			$default  = (int) apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, (int) $user_id, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, read as wp_update_user() does.
			$remember = $life > $default;
		}

		$keep = static function () use ( $life ) {
			return $life;
		};

		add_filter( 'auth_cookie_expiration', $keep, PHP_INT_MAX ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, scoped to this one call.
		wp_set_auth_cookie( (int) $user_id, $remember, '', $token );
		remove_filter( 'auth_cookie_expiration', $keep, PHP_INT_MAX );

		return true;
	}

	// ---------- UN-15 ----------

	/**
	 * Send the plain-text notification (UN-15) through wp_mail() with the
	 * site's defaults: no `From:` header is built, so an SMTP plugin decides.
	 * Written in the user's locale.
	 *
	 * @param WP_User     $user     The renamed user (as stored now).
	 * @param string      $old_name Previous login.
	 * @param string      $new_name New login.
	 * @param string|null $subject  Subject template; null reads the setting. A bulk
	 *                              rename passes the one pinned at its start (JOB-3).
	 * @param string|null $body     Body template; null reads the setting.
	 * @return bool Whether wp_mail() accepted it.
	 */
	public static function send_email( WP_User $user, $old_name, $new_name, $subject = null, $body = null ) {
		$switched = function_exists( 'switch_to_user_locale' ) ? switch_to_user_locale( (int) $user->ID ) : false;

		$subject = null === $subject ? (string) BLT_Toolkit_Settings::get( 'usernames', 'email_subject' ) : (string) $subject;
		$body    = null === $body ? (string) BLT_Toolkit_Settings::get( 'usernames', 'email_body' ) : (string) $body;

		if ( '' === trim( $subject ) ) {
			$subject = self::default_subject();
		}

		if ( '' === trim( $body ) ) {
			$body = self::default_body();
		}

		$tags = self::email_tags(
			array(
				'old_username' => (string) $old_name,
				'new_username' => (string) $new_name,
				'display_name' => (string) $user->display_name,
				'email'        => (string) $user->user_email,
				'sitename'     => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'siteurl'      => home_url( '/' ),
				'loginurl'     => wp_login_url(),
			)
		);

		$email = array(
			'to'      => (string) $user->user_email,
			'subject' => self::one_line( self::replace_tags( $subject, $tags ) ),
			'message' => self::replace_tags( $body, $tags ),
			'headers' => '',
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		/**
		 * Filter the rename notification email (UN-15).
		 *
		 * @param array   $email `to`, `subject`, `message`, `headers`, with the tags replaced.
		 * @param WP_User $user  The renamed user.
		 * @param string  $old   The previous login.
		 * @param string  $new   The new login.
		 */
		$email = apply_filters( 'blt_toolkit_username_email', $email, $user, (string) $old_name, (string) $new_name );

		if ( ! is_array( $email ) || empty( $email['to'] ) || ! is_string( $email['to'] ) || ! is_email( $email['to'] ) ) {
			return false;
		}

		return (bool) wp_mail(
			$email['to'],
			isset( $email['subject'] ) ? self::one_line( (string) $email['subject'] ) : '',
			isset( $email['message'] ) ? (string) $email['message'] : '',
			isset( $email['headers'] ) ? $email['headers'] : ''
		);
	}

	/**
	 * The default subject, in the current locale.
	 *
	 * @return string
	 */
	public static function default_subject() {
		/* translators: Email subject. Keep the tag in braces as it is. */
		return __( '[{sitename}] Your username has changed', 'blt-toolkit' );
	}

	/**
	 * The default message, in the current locale.
	 *
	 * @return string
	 */
	public static function default_body() {
		/* translators: Plain-text email. Keep the tags in braces as they are. */
		return __(
			'Hi {display_name},

Your username on {sitename} has changed from {old_username} to {new_username}.

Sign in with your new username, or with your email address, at:
{loginurl}

If you did not expect this change, contact the site\'s administrator.

{sitename}
{siteurl}',
			'blt-toolkit'
		);
	}

	/**
	 * Tag => value map with braces. Pure.
	 *
	 * @param array<string,string> $values Tag name (no braces) => value.
	 * @return array<string,string>
	 */
	public static function email_tags( array $values ) {
		$tags = array();

		foreach ( array( 'old_username', 'new_username', 'display_name', 'email', 'sitename', 'siteurl', 'loginurl' ) as $name ) {
			$tags[ '{' . $name . '}' ] = isset( $values[ $name ] ) && is_scalar( $values[ $name ] ) ? (string) $values[ $name ] : '';
		}

		return $tags;
	}

	/**
	 * Replace the tags in a template, in one pass, so a value that itself
	 * looks like a tag is never replaced again. Pure.
	 *
	 * @param string               $text Template.
	 * @param array<string,string> $tags email_tags().
	 * @return string
	 */
	public static function replace_tags( $text, array $tags ) {
		return strtr( (string) $text, $tags );
	}

	/**
	 * A subject line on one line. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( $text ) {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) $text ) );
	}

	// ---------- Log (LOG-1) ----------

	/**
	 * Log a rename (UN-10 step 9, action `username_changed`).
	 *
	 * @param WP_User $old      The user before the change.
	 * @param string  $new_name New login.
	 * @param array   $result   change()'s result.
	 * @param array   $facts    Source and what else changed.
	 * @return void
	 */
	private static function log_change( WP_User $old, $new_name, array $result, array $facts ) {
		$old_login = (string) $old->user_login;

		if ( ! empty( $facts['self'] ) ) {
			$summary = sprintf(
				/* translators: 1: previous username, 2: new username. */
				__( 'Changed their own username from %1$s to %2$s.', 'blt-toolkit' ),
				$old_login,
				$new_name
			);
		} else {
			$summary = sprintf(
				/* translators: 1: previous username, 2: new username. */
				__( 'Changed the username %1$s to %2$s.', 'blt-toolkit' ),
				$old_login,
				$new_name
			);
		}

		$details = array(
			'old_login'            => $old_login,
			'new_login'            => (string) $new_name,
			'author_slug_changed'  => (bool) $result['nicename_changed'],
			'display_name_changed' => ! empty( $facts['display_name_changed'] ),
			'nickname_changed'     => ! empty( $facts['nickname_changed'] ),
			'sessions_ended'       => (int) $result['sessions_ended'],
			'session_kept'         => (bool) $result['session_kept'],
			'source'               => (string) $facts['source'],
		);

		if ( $result['nicename_changed'] ) {
			$details['old_author_slug'] = (string) $result['old_nicename'];
			$details['new_author_slug'] = (string) $result['new_nicename'];
		}

		if ( ! empty( $facts['switched_confirmed'] ) ) {
			$details['switched_session_ended'] = true;
		}

		if ( ! empty( $facts['partial'] ) ) {
			$details['error'] = 'The username changed, but the author slug and display name could not be written.';
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => 'username_changed',
				'object_type' => 'user',
				'object_id'   => (string) (int) $old->ID,
				'summary'     => $summary,
				'details'     => $details,
				'status'      => empty( $facts['partial'] ) ? 'success' : 'warning',
			)
		);
	}

	/**
	 * Log a rename whose write failed (LOG-1).
	 *
	 * @param WP_User $old      The user.
	 * @param string  $new_name The new login that was not written.
	 * @param string  $source   Where the change came from.
	 * @param string  $error    What the database said.
	 * @return void
	 */
	private static function log_failure( WP_User $old, $new_name, $source, $error ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => 'username_changed',
				'object_type' => 'user',
				'object_id'   => (string) (int) $old->ID,
				'summary'     => sprintf(
					/* translators: 1: current username, 2: the username it could not be changed to. */
					__( 'Could not change the username %1$s to %2$s.', 'blt-toolkit' ),
					(string) $old->user_login,
					(string) $new_name
				),
				'details'     => array(
					'old_login' => (string) $old->user_login,
					'new_login' => (string) $new_name,
					'source'    => (string) $source,
					'error'     => (string) $error,
				),
				'status'      => 'failure',
			)
		);
	}
}
