<?php
/**
 * Author slug (user_nicename) derivation, as wp_insert_user() does it (UN-13).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and recognizes the author slug WordPress derives from a username
 * (SPEC 8.3, UN-13).
 *
 * Pure: the title sanitizer and the "is this slug taken" lookup are injected,
 * so the logic is unit-tested against outputs recorded from a real
 * wp_insert_user() (tests/fixtures/nicename-vectors.json, SPEC 18). This is
 * the fix for Username Changer's UC-2, which wrote the raw new username into
 * the slug: not sanitized, not truncated to 50 characters, never made unique.
 *
 * Core's algorithm (wp-includes/user.php, wp_insert_user()):
 *
 * 1. base: sanitize_title( mb_substr( $user_login, 0, 50 ) ), then the
 *    `pre_user_nicename` filter;
 * 2. refuse an empty base, or one longer than 50 characters (a percent-encoded
 *    non-Latin login easily is), see fits();
 * 3. while the slug is taken by another user, try
 *    mb_substr( $base, 0, 49 - mb_strlen( $suffix ) ) . "-$suffix" with the
 *    suffix counting up from 2, see unique().
 *
 * Callers that want `pre_user_nicename` honored pass a sanitizer that applies
 * it after sanitize_title(), as core does.
 */
class BLT_Toolkit_Nicename {

	/**
	 * Longest login prefix core feeds to the sanitizer, and the longest slug
	 * core stores (the `user_nicename` column is varchar(50)).
	 */
	const MAX_LENGTH = 50;

	/**
	 * Most suffixes unique() tries before giving up. Core has no limit; this
	 * guards against a lookup that never says "free" (the loop would never
	 * end). A real site never needs more than a handful.
	 */
	const MAX_SUFFIX = 10000;

	/**
	 * The slug core derives from a login before uniqueness: exactly
	 * wp_insert_user()'s sanitize_title( mb_substr( $login, 0, 50 ) ).
	 *
	 * The result can be empty or longer than 50 characters, both of which
	 * core refuses; check it with fits() before using it.
	 *
	 * @param string        $login          The login (as stored, or as it will be stored).
	 * @param callable|null $sanitize_title Title sanitizer; default sanitize_title().
	 * @return string
	 */
	public static function base( $login, $sanitize_title = null ) {
		$sanitize = null === $sanitize_title ? 'sanitize_title' : $sanitize_title;

		return (string) call_user_func( $sanitize, mb_substr( (string) $login, 0, self::MAX_LENGTH ) );
	}

	/**
	 * Whether core would store this slug: not empty and at most 50
	 * characters. "Empty" is PHP's empty(), as in core, so '0' does not fit.
	 *
	 * @param string $nicename Slug.
	 * @return bool
	 */
	public static function fits( $nicename ) {
		$nicename = (string) $nicename;

		return ! empty( $nicename ) && mb_strlen( $nicename ) <= self::MAX_LENGTH;
	}

	/**
	 * The first free slug for a base, with wp_insert_user()'s suffix loop.
	 *
	 * The lookup must answer for every user except the one being renamed
	 * (core excludes the user's own login from its check), so a user keeps
	 * their slug when nothing else holds it.
	 *
	 * @param string   $base  Base slug, from base().
	 * @param callable $taken Lookup: callable( string $slug ): bool, true when another user holds it.
	 * @return string The slug; '' when MAX_SUFFIX suffixes were all taken.
	 */
	public static function unique( $base, $taken ) {
		$base = (string) $base;

		if ( ! call_user_func( $taken, $base ) ) {
			return $base;
		}

		for ( $suffix = 2; $suffix <= self::MAX_SUFFIX; $suffix++ ) {
			$candidate = self::suffixed( $base, $suffix );

			if ( ! call_user_func( $taken, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Whether a slug is the one core would have derived from a login: the
	 * base itself, or the base truncated and suffixed `-N` (N of 2 or more,
	 * written without leading zeros) exactly as unique() builds it. This is
	 * the "Only when it was generated from the username" test of UN-13.
	 *
	 * @param string        $nicename       Current slug.
	 * @param string        $old_login      Login the slug would have come from.
	 * @param callable|null $sanitize_title Title sanitizer; default sanitize_title().
	 * @return bool
	 */
	public static function is_derived( $nicename, $old_login, $sanitize_title = null ) {
		$nicename = (string) $nicename;
		$base     = self::base( $old_login, $sanitize_title );

		if ( '' === $base || '' === $nicename ) {
			return false;
		}

		if ( $nicename === $base ) {
			return true;
		}

		if ( 1 !== preg_match( '/^(.*)-([1-9][0-9]*)$/s', $nicename, $match ) || '1' === $match[2] ) {
			return false;
		}

		return self::suffixed( $base, $match[2] ) === $nicename;
	}

	/**
	 * One candidate of core's suffix loop.
	 *
	 * @param string     $base   Base slug.
	 * @param int|string $suffix Suffix number.
	 * @return string
	 */
	private static function suffixed( $base, $suffix ) {
		$suffix = (string) $suffix;

		return mb_substr( $base, 0, self::MAX_LENGTH - 1 - mb_strlen( $suffix ) ) . '-' . $suffix;
	}
}
