<?php
/**
 * The one username validator for every path (UN-1 to UN-9).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a proposed username (SPEC 8.1).
 *
 * One validator for the profile screen, the bulk rename, the REST check and
 * the shortcode, so no path can skip a rule (SPEC 19: UC-4 checked the
 * minimum length only in JavaScript, UC-5 compared the illegal list without
 * lowercasing the name on one path, UC-6 had no maximum at all).
 *
 * check() is pure: every lookup (who owns a login or an email, core's
 * sanitize_user() and validate_username()) comes in through the context, so
 * the rules are unit-tested with known vectors and the bulk rename can run
 * them against the projected state of a batch. context_for() builds the
 * WordPress-backed context, and validate() adds the UN-9 filter.
 *
 * The rules run in SPEC order and the first one that fails is the only
 * error returned, each with its own code and message:
 *
 * - UN-1 `blt_toolkit_username_sanitize`: sanitize_user( $new, true ) must
 *   leave the trimmed name unchanged; data `suggestion` holds the cleaned
 *   name (without `@` when UN-7 forbids it).
 * - UN-2 `blt_toolkit_username_length`: between the minimum and 60
 *   characters (the `user_login` column limit core enforces).
 * - UN-3 `blt_toolkit_username_invalid`: validate_username().
 * - UN-4 `blt_toolkit_username_disallowed`: not on the disallowed list,
 *   compared case-insensitively on both sides.
 * - UN-5 `blt_toolkit_username_taken`: no other user owns the login (the
 *   user's own ID is fine, so `jsmith` to `JSmith` passes).
 * - UN-6 `blt_toolkit_username_email`: not another user's email address.
 * - UN-7 `blt_toolkit_username_at_sign`: `@` only when allowed.
 * - UN-8 `blt_toolkit_username_unchanged`: not identical to the current
 *   username (a change of case is a change).
 *
 * Every error's data carries `rule` (the requirement ID). Messages may
 * contain the suggestion, which came from the person's input: escape them on
 * output.
 */
class BLT_Toolkit_Username_Validator {

	/**
	 * The `user_login` column limit; core refuses longer logins.
	 */
	const MAX_LENGTH = 60;

	/**
	 * The name every rule sees: the input as a string, trimmed (UN-1). A
	 * caller that writes the name stores this value, never the raw input.
	 *
	 * @param mixed $new Proposed username.
	 * @return string
	 */
	public static function normalize( $new ) {
		return is_scalar( $new ) ? trim( (string) $new ) : '';
	}

	/**
	 * Run UN-1 to UN-8 and return at the first rule that fails. Pure.
	 *
	 * Context keys (a missing lookup falls back to the WordPress function):
	 *
	 * - `user_id` (int) and `current_login` (string): the user being renamed.
	 * - `min_length` (int, at least 1) and `max_length` (int, at most 60).
	 * - `disallowed` (string[]): the merged list, any case.
	 * - `allow_at` (bool): whether `@` may appear (UN-7, D13).
	 * - `login_owner` callable( string $login ): int, the ID owning that login or 0.
	 * - `email_owner` callable( string $email ): int, the ID owning that email or 0.
	 * - `sanitize_user` callable( string $name, bool $strict ): string.
	 * - `validate_username` callable( string $name ): bool.
	 *
	 * @param string $new     Proposed username (trimmed here, see normalize()).
	 * @param array  $context Rule inputs and lookups.
	 * @return WP_Error Empty (no error code) when the name passes.
	 */
	public static function check( $new, array $context ) {
		$new     = self::normalize( $new );
		$context = self::complete( $context );

		// UN-1: the strict sanitizer must leave the name as typed.
		$sanitized = (string) call_user_func( $context['sanitize_user'], $new, true );

		if ( $sanitized !== $new ) {
			return self::sanitize_error( $sanitized, $context );
		}

		// UN-2: length, on the server (UC-4, UC-6).
		$length = mb_strlen( $new );

		if ( $length < $context['min_length'] ) {
			return new WP_Error(
				'blt_toolkit_username_length',
				sprintf(
					/* translators: %d: minimum number of characters. */
					_n( 'Usernames must be at least %d character long.', 'Usernames must be at least %d characters long.', $context['min_length'], 'blt-toolkit' ),
					$context['min_length']
				),
				self::data( 'UN-2', array( 'min' => $context['min_length'], 'max' => $context['max_length'] ) )
			);
		}

		if ( $length > $context['max_length'] ) {
			return new WP_Error(
				'blt_toolkit_username_length',
				sprintf(
					/* translators: %d: maximum number of characters. */
					_n( 'Usernames can be at most %d character long.', 'Usernames can be at most %d characters long.', $context['max_length'], 'blt-toolkit' ),
					$context['max_length']
				),
				self::data( 'UN-2', array( 'min' => $context['min_length'], 'max' => $context['max_length'] ) )
			);
		}

		// UN-3: core's own test, with its `validate_username` filter.
		if ( ! call_user_func( $context['validate_username'], $new ) ) {
			return new WP_Error( 'blt_toolkit_username_invalid', __( 'That username is not valid on this site.', 'blt-toolkit' ), self::data( 'UN-3' ) );
		}

		// UN-4: case-insensitive on both sides (UC-5).
		if ( self::is_disallowed( $new, $context['disallowed'] ) ) {
			return new WP_Error( 'blt_toolkit_username_disallowed', __( 'That username is reserved and cannot be used.', 'blt-toolkit' ), self::data( 'UN-4' ) );
		}

		// UN-5: free, or already this user's (a change of case).
		$owner = (int) call_user_func( $context['login_owner'], $new );

		if ( $owner > 0 && $owner !== $context['user_id'] ) {
			return new WP_Error( 'blt_toolkit_username_taken', __( 'That username is already taken.', 'blt-toolkit' ), self::data( 'UN-5' ) );
		}

		// UN-6: WordPress accepts an email address at sign-in, and only a name
		// containing `@` can be one, so other names need no lookup.
		if ( false !== strpos( $new, '@' ) ) {
			$owner = (int) call_user_func( $context['email_owner'], $new );

			if ( $owner > 0 && $owner !== $context['user_id'] ) {
				return new WP_Error(
					'blt_toolkit_username_email',
					__( 'That username is the email address of another account. Both can be used to sign in, so they must not match.', 'blt-toolkit' ),
					self::data( 'UN-6' )
				);
			}
		}

		// UN-7: `@` is allowed by default (D13).
		if ( ! $context['allow_at'] && false !== strpos( $new, '@' ) ) {
			return new WP_Error( 'blt_toolkit_username_at_sign', __( 'Usernames cannot contain the @ symbol on this site.', 'blt-toolkit' ), self::data( 'UN-7' ) );
		}

		// UN-8: identical only; `jsmith` to `JSmith` is a change.
		if ( $new === $context['current_login'] ) {
			return new WP_Error( 'blt_toolkit_username_unchanged', __( 'That is already the current username.', 'blt-toolkit' ), self::data( 'UN-8' ) );
		}

		return new WP_Error();
	}

	/**
	 * The WordPress-backed context for renaming a user: settings, filters and
	 * lookups (UN-2, UN-4, UN-5, UN-6, UN-7).
	 *
	 * @param WP_User $user The user being renamed.
	 * @return array Context for check().
	 */
	public static function context_for( WP_User $user ) {
		$settings = BLT_Toolkit_Settings::get( 'usernames' );
		$settings = is_array( $settings ) ? $settings : array();

		/** This filter is documented in HOOKS.md (UN-2). */
		$min = (int) apply_filters( 'blt_toolkit_username_min_length', isset( $settings['min_length'] ) ? (int) $settings['min_length'] : 3 );

		/** This filter is documented in HOOKS.md (UN-4). */
		$own = apply_filters( 'blt_toolkit_disallowed_usernames', isset( $settings['disallowed'] ) ? (array) $settings['disallowed'] : array() );

		// Core's list, as wp_insert_user() reads it.
		$core = apply_filters( 'illegal_user_logins', array() );

		return array(
			'user_id'           => (int) $user->ID,
			'current_login'     => (string) $user->user_login,
			'min_length'        => $min,
			'max_length'        => self::MAX_LENGTH,
			'disallowed'        => array_merge( (array) $own, (array) $core ),
			'allow_at'          => ! empty( $settings['allow_at_sign'] ),
			'login_owner'       => array( __CLASS__, 'login_owner' ),
			'email_owner'       => array( __CLASS__, 'email_owner' ),
			'sanitize_user'     => 'sanitize_user',
			'validate_username' => 'validate_username',
		);
	}

	/**
	 * Validate a proposed username for a user: check() with context_for(),
	 * then the UN-9 filter. Every path calls this.
	 *
	 * `$overrides` replaces context keys, so the bulk rename can answer
	 * `login_owner` from the batch's projected state
	 * (BLT_Toolkit_Rename_Planner::owner_lookup()) while every rule, and the
	 * filter, still run.
	 *
	 * @param string  $new       Proposed username.
	 * @param WP_User $user      The user being renamed.
	 * @param array   $overrides Context keys to replace.
	 * @return WP_Error Empty when the name passes.
	 */
	public static function validate( $new, WP_User $user, array $overrides = array() ) {
		$new    = self::normalize( $new );
		$errors = self::check( $new, array_merge( self::context_for( $user ), $overrides ) );

		/** This filter is documented in HOOKS.md (UN-9). */
		$filtered = apply_filters( 'blt_toolkit_validate_username', $errors, $new, $user );

		// A filter that breaks the contract refuses the name rather than
		// letting it through unchecked.
		if ( ! $filtered instanceof WP_Error ) {
			return new WP_Error( 'blt_toolkit_username_invalid', __( 'That username is not valid on this site.', 'blt-toolkit' ), self::data( 'UN-9' ) );
		}

		return $filtered;
	}

	/**
	 * Whether a WP_Error from check() or validate() holds no error.
	 *
	 * @param WP_Error $errors Result.
	 * @return bool
	 */
	public static function is_valid( WP_Error $errors ) {
		return '' === (string) $errors->get_error_code();
	}

	/**
	 * Default `login_owner` lookup: username_exists().
	 *
	 * @param string $login Login.
	 * @return int User ID, or 0.
	 */
	public static function login_owner( $login ) {
		return (int) username_exists( $login );
	}

	/**
	 * Default `email_owner` lookup: email_exists().
	 *
	 * @param string $email Email address.
	 * @return int User ID, or 0.
	 */
	public static function email_owner( $email ) {
		return (int) email_exists( $email );
	}

	/**
	 * Fill in and clamp the context.
	 *
	 * @param array $context Context as passed.
	 * @return array
	 */
	private static function complete( array $context ) {
		$context = array_merge(
			array(
				'user_id'           => 0,
				'current_login'     => '',
				'min_length'        => 3,
				'max_length'        => self::MAX_LENGTH,
				'disallowed'        => array(),
				'allow_at'          => true,
				'login_owner'       => array( __CLASS__, 'login_owner' ),
				'email_owner'       => array( __CLASS__, 'email_owner' ),
				'sanitize_user'     => 'sanitize_user',
				'validate_username' => 'validate_username',
			),
			$context
		);

		$context['user_id']       = (int) $context['user_id'];
		$context['current_login'] = (string) $context['current_login'];
		$context['max_length']    = max( 1, min( self::MAX_LENGTH, (int) $context['max_length'] ) );
		$context['min_length']    = max( 1, (int) $context['min_length'] );
		$context['disallowed']    = is_array( $context['disallowed'] ) ? $context['disallowed'] : array();
		$context['allow_at']      = (bool) $context['allow_at'];

		return $context;
	}

	/**
	 * UN-4 comparison: both sides trimmed and lowercased, as core lowercases
	 * `illegal_user_logins` (strtolower(), like wp_insert_user()).
	 *
	 * @param string $new        Proposed username.
	 * @param array  $disallowed Disallowed names, any case.
	 * @return bool
	 */
	private static function is_disallowed( $new, array $disallowed ) {
		$needle = strtolower( $new );

		foreach ( $disallowed as $name ) {
			if ( is_scalar( $name ) && strtolower( trim( (string) $name ) ) === $needle ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The UN-1 error, with the cleaned name as a suggestion when there is one.
	 *
	 * @param string $sanitized sanitize_user( $new, true ).
	 * @param array  $context   Completed context.
	 * @return WP_Error
	 */
	private static function sanitize_error( $sanitized, array $context ) {
		// Offer only what UN-7 would accept.
		if ( ! $context['allow_at'] && false !== strpos( $sanitized, '@' ) ) {
			$sanitized = (string) call_user_func( $context['sanitize_user'], str_replace( '@', '', $sanitized ), true );
		}

		$symbols = $context['allow_at'] ? '. - _ @' : '. - _';

		if ( '' === $sanitized ) {
			$message = sprintf(
				/* translators: %s: the symbols allowed in usernames, such as ". - _ @". */
				__( 'Usernames can contain only letters without accents, numbers, spaces and these symbols: %s', 'blt-toolkit' ),
				$symbols
			);
		} else {
			$message = sprintf(
				/* translators: 1: the symbols allowed in usernames, such as ". - _ @". 2: a suggested username. */
				__( 'Usernames can contain only letters without accents, numbers, spaces and these symbols: %1$s. Try %2$s instead.', 'blt-toolkit' ),
				$symbols,
				$sanitized
			);
		}

		return new WP_Error( 'blt_toolkit_username_sanitize', $message, self::data( 'UN-1', array( 'suggestion' => $sanitized ) ) );
	}

	/**
	 * Error data: the requirement ID plus extras.
	 *
	 * @param string $rule  Requirement ID.
	 * @param array  $extra Extra keys.
	 * @return array
	 */
	private static function data( $rule, array $extra = array() ) {
		return array_merge( array( 'rule' => $rule ), $extra );
	}
}
