<?php
/**
 * Switch cookies: the stack of originating users and the old-user cookie.
 *
 * The mechanism (a JSON stack of `auth`-scheme cookies plus a `logged_in`
 * scheme "old user" cookie, all made and checked with core's
 * wp_generate_auth_cookie() and wp_validate_auth_cookie()) follows User
 * Switching 1.12.2 by John Blackbourn, reimplemented with BLT names (SPEC 7).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three switch cookies of SW-3.
 *
 * - `wordpress_blt_sw_{COOKIEHASH}` (constant `BLT_TOOLKIT_SWITCH_COOKIE`):
 *   a JSON array, oldest first, of `auth`-scheme cookies, one for each
 *   originating user, path `SITECOOKIEPATH`.
 * - `wordpress_blt_sw_secure_{COOKIEHASH}` (`BLT_TOOLKIT_SWITCH_SECURE_COOKIE`):
 *   the same stack in the `secure_auth` scheme, used when the auth cookie is
 *   secure (core's `secure_auth_cookie`).
 * - `wordpress_blt_sw_olduser_{COOKIEHASH}` (`BLT_TOOLKIT_SWITCH_OLDUSER_COOKIE`):
 *   a `logged_in`-scheme cookie for the most recent originating user, path
 *   `COOKIEPATH`, so the site's front end and login screen can offer
 *   "Switch back" even after a switch off.
 *
 * Every cookie is HttpOnly and SameSite=Lax; Secure follows core's
 * `secure_auth_cookie` and `secure_logged_in_cookie` filters, exactly as
 * wp_set_auth_cookie() decides it for the core cookies. The filter
 * `blt_toolkit_switch_send_cookies` (default true) stops the `setcookie()`
 * calls for tests and headless setups.
 *
 * The stack operations (parse(), encode(), push(), pop(), peek()) and
 * is_persistent() are pure: they take and return plain strings and numbers
 * and call no WordPress function, so tests/test-switch-cookies.php covers
 * them (SPEC 18). Reading and writing the cookies is kept apart from them.
 *
 * A value written during a request is also what later reads in the same
 * request see (core does not update `$_COOKIE` when it sends a cookie), so
 * the log's switch chain and original_user() reflect the switch at once.
 */
class BLT_Toolkit_Switch_Cookies {

	/**
	 * Cookie kind: the `auth`-scheme stack.
	 */
	const AUTH = 'auth';

	/**
	 * Cookie kind: the `secure_auth`-scheme stack.
	 */
	const SECURE = 'secure';

	/**
	 * Cookie kind: the `logged_in`-scheme old-user cookie.
	 */
	const OLDUSER = 'olduser';

	/**
	 * How long the switch cookies live: 48 hours, as in User Switching. The
	 * cookies in the stack carry the same expiration.
	 */
	const LIFETIME = 172800;

	/**
	 * Most entries the stack keeps. Deeper nesting drops the oldest, which
	 * only means that switching back stops at the oldest one kept. It keeps
	 * the cookie well under the 4 KB browsers accept.
	 */
	const MAX_DEPTH = 10;

	/**
	 * Values written this request: kind => value ('' after a clear). Reads
	 * prefer these over `$_COOKIE`.
	 *
	 * @var array<string,string>
	 */
	private static $written = array();

	// ---------- Pure stack operations (SPEC 18) ----------

	/**
	 * The stack held in a cookie value. Pure.
	 *
	 * A JSON array of strings, oldest first. Anything else, including
	 * malformed JSON, a JSON object, or a value that is not a string, is an
	 * empty stack; entries that are not non-empty strings are dropped.
	 *
	 * @param mixed $json Cookie value.
	 * @return string[]
	 */
	public static function parse( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		// Objects decode to stdClass, so only a JSON array is a list here.
		$value = json_decode( $json );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * A stack as a cookie value. Pure.
	 *
	 * @param string[] $stack Entries, oldest first.
	 * @return string JSON array; `[]` for an empty stack.
	 */
	public static function encode( array $stack ) {
		$clean = array();

		foreach ( $stack as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$clean[] = $entry;
			}
		}

		$json = json_encode( $clean, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure method; no WordPress function may be called here.

		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * Push an entry onto the stack. Pure.
	 *
	 * Beyond MAX_DEPTH entries the oldest are dropped.
	 *
	 * @param mixed  $json  Cookie value (anything parse() accepts).
	 * @param string $entry The new newest entry.
	 * @return string The new cookie value.
	 */
	public static function push( $json, $entry ) {
		$stack = self::parse( $json );

		if ( is_string( $entry ) && '' !== $entry ) {
			$stack[] = $entry;
		}

		if ( count( $stack ) > self::MAX_DEPTH ) {
			$stack = array_slice( $stack, -self::MAX_DEPTH );
		}

		return self::encode( $stack );
	}

	/**
	 * Take the newest entry off the stack. Pure.
	 *
	 * @param mixed $json Cookie value.
	 * @return string The new cookie value (`[]` when it is now empty).
	 */
	public static function pop( $json ) {
		$stack = self::parse( $json );

		array_pop( $stack );

		return self::encode( $stack );
	}

	/**
	 * The newest entry, without changing the stack. Pure.
	 *
	 * @param mixed $json Cookie value.
	 * @return string The entry, or '' when the stack is empty.
	 */
	public static function peek( $json ) {
		$stack = self::parse( $json );

		return array() === $stack ? '' : (string) end( $stack );
	}

	/**
	 * Whether a cookie expiring at `$expiration` is a "remember me" cookie
	 * (SW-7): it outlives the default, non-persistent, lifetime. Pure.
	 *
	 * @param int $expiration   The cookie's expiration (Unix time).
	 * @param int $now          The current time.
	 * @param int $default_life The default `auth_cookie_expiration` (seconds).
	 * @return bool
	 */
	public static function is_persistent( $expiration, $now, $default_life ) {
		return ( (int) $expiration - (int) $now ) > (int) $default_life;
	}

	/**
	 * A cookie's default name. Pure.
	 *
	 * @param string $kind       AUTH, SECURE or OLDUSER.
	 * @param string $cookiehash Core's COOKIEHASH.
	 * @return string
	 */
	public static function default_name( $kind, $cookiehash ) {
		$infix = array(
			self::AUTH    => '',
			self::SECURE  => 'secure_',
			self::OLDUSER => 'olduser_',
		);

		return 'wordpress_blt_sw_' . ( isset( $infix[ $kind ] ) ? $infix[ $kind ] : '' ) . (string) $cookiehash;
	}

	// ---------- Names and schemes ----------

	/**
	 * A cookie's name: the constant when it is defined (SW-3), otherwise the
	 * default built from COOKIEHASH.
	 *
	 * @param string $kind AUTH, SECURE or OLDUSER.
	 * @return string
	 */
	public static function name( $kind ) {
		$constants = array(
			self::AUTH    => 'BLT_TOOLKIT_SWITCH_COOKIE',
			self::SECURE  => 'BLT_TOOLKIT_SWITCH_SECURE_COOKIE',
			self::OLDUSER => 'BLT_TOOLKIT_SWITCH_OLDUSER_COOKIE',
		);

		if ( isset( $constants[ $kind ] ) && defined( $constants[ $kind ] ) ) {
			$name = constant( $constants[ $kind ] );

			if ( is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}

		return self::default_name( $kind, defined( 'COOKIEHASH' ) ? COOKIEHASH : '' );
	}

	/**
	 * Whether this request reads the secure stack: the request is HTTPS and
	 * so is the login URL every switch goes through (as User Switching's
	 * secure_auth_cookie() decides).
	 *
	 * @return bool
	 */
	public static function secure_request() {
		return is_ssl() && 'https' === wp_parse_url( wp_login_url(), PHP_URL_SCHEME );
	}

	/**
	 * Whether the stack written for a user is secure: secure_request(),
	 * then core's `secure_auth_cookie` filter, as wp_set_auth_cookie()
	 * applies it to the core auth cookie.
	 *
	 * @param int $user_id The user the newest entry is for.
	 * @return bool
	 */
	public static function secure_for( $user_id ) {
		/** This filter is documented in wp-includes/pluggable.php */
		return (bool) apply_filters( 'secure_auth_cookie', self::secure_request(), (int) $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as wp_set_auth_cookie() does.
	}

	/**
	 * The cookie scheme of a stack kind.
	 *
	 * @param string $kind AUTH or SECURE.
	 * @return string `auth` or `secure_auth`.
	 */
	public static function scheme( $kind ) {
		return self::SECURE === $kind ? 'secure_auth' : 'auth';
	}

	// ---------- Reading ----------

	/**
	 * A cookie's current value: written this request, else from the browser.
	 *
	 * @param string $kind AUTH, SECURE or OLDUSER.
	 * @return string '' when there is none.
	 */
	public static function value( $kind ) {
		if ( array_key_exists( $kind, self::$written ) ) {
			return self::$written[ $kind ];
		}

		$name = self::name( $kind );

		if ( isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ) {
			// An opaque cookie value checked by wp_validate_auth_cookie(); it is
			// never printed or stored.
			return wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		return '';
	}

	/**
	 * The old-user cookie's value.
	 *
	 * @return string '' when there is none.
	 */
	public static function olduser() {
		return self::value( self::OLDUSER );
	}

	/**
	 * The stack this request reads, and its scheme.
	 *
	 * The cookie that matches the request (secure_request()) is read first.
	 * When it holds nothing, the other one is read, with its own scheme, so a
	 * `secure_auth_cookie` filter that disagrees with the request cannot
	 * strand a switched user: each entry is still checked with the key of
	 * the scheme it was made with.
	 *
	 * @return array{stack:string[],kind:string,scheme:string}
	 */
	public static function read_stack() {
		$first  = self::secure_request() ? self::SECURE : self::AUTH;
		$second = self::SECURE === $first ? self::AUTH : self::SECURE;

		foreach ( array( $first, $second ) as $kind ) {
			$stack = self::parse( self::value( $kind ) );

			if ( array() !== $stack ) {
				return array(
					'stack'  => $stack,
					'kind'   => $kind,
					'scheme' => self::scheme( $kind ),
				);
			}
		}

		return array(
			'stack'  => array(),
			'kind'   => $first,
			'scheme' => self::scheme( $first ),
		);
	}

	/**
	 * Whether the browser sent any switch cookie, without validating it. A
	 * cheap test that runs no query.
	 *
	 * @return bool
	 */
	public static function present() {
		foreach ( array( self::OLDUSER, self::AUTH, self::SECURE ) as $kind ) {
			if ( '' !== self::value( $kind ) ) {
				return true;
			}
		}

		return false;
	}

	// ---------- Writing ----------

	/**
	 * Push a user onto the stack and make them the old user (SW-4 step 3,
	 * SW-6).
	 *
	 * @param int    $user_id The originating user.
	 * @param string $token   Their session token, which switching back returns to.
	 * @return void
	 */
	public static function push_user( $user_id, $token ) {
		$user_id    = (int) $user_id;
		$expiration = time() + self::LIFETIME;
		$read       = self::read_stack();

		// Every entry of one stack shares its scheme, or the older entries
		// would fail validation. A new stack follows core's filter; a nested
		// switch keeps the scheme the stack started with.
		if ( array() !== $read['stack'] ) {
			$kind   = $read['kind'];
			$secure = self::SECURE === $kind;
		} else {
			$secure = self::secure_for( $user_id );
			$kind   = $secure ? self::SECURE : self::AUTH;
		}

		$entry = wp_generate_auth_cookie( $user_id, $expiration, self::scheme( $kind ), (string) $token );

		self::write( $kind, self::push( self::encode( $read['stack'] ), $entry ), $expiration, $user_id, $secure, $token );
	}

	/**
	 * Take the newest user off the stack (SW-5).
	 *
	 * When users remain, the old-user cookie is made again for the newest of
	 * them, with the session token from their stack entry. When none remain,
	 * or the newest no longer validates (its session ended), every switch
	 * cookie is cleared: there is nothing left to switch back to.
	 *
	 * @return void
	 */
	public static function pop_user() {
		$read  = self::read_stack();
		$stack = $read['stack'];

		array_pop( $stack );

		if ( array() === $stack ) {
			self::clear();

			return;
		}

		$newest  = (string) end( $stack );
		$user_id = (int) wp_validate_auth_cookie( $newest, $read['scheme'] );
		$parts   = $user_id > 0 ? wp_parse_auth_cookie( $newest, $read['scheme'] ) : false;

		if ( ! is_array( $parts ) || ! isset( $parts['token'] ) ) {
			self::clear();

			return;
		}

		self::write( $read['kind'], self::encode( $stack ), time() + self::LIFETIME, $user_id, self::SECURE === $read['kind'], (string) $parts['token'] );
	}

	/**
	 * Clear every switch cookie (SW-10, and when the stack empties).
	 *
	 * @return void
	 */
	public static function clear() {
		self::$written[ self::AUTH ]    = '';
		self::$written[ self::SECURE ]  = '';
		self::$written[ self::OLDUSER ] = '';

		if ( ! self::sending() ) {
			return;
		}

		$expire = time() - YEAR_IN_SECONDS;

		self::send( self::name( self::AUTH ), ' ', $expire, SITECOOKIEPATH, false );
		self::send( self::name( self::SECURE ), ' ', $expire, SITECOOKIEPATH, false );
		self::send( self::name( self::OLDUSER ), ' ', $expire, COOKIEPATH, false );
	}

	/**
	 * Forget what was written this request, so reads come from `$_COOKIE`
	 * again. For tests and long-running processes.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$written = array();
	}

	/**
	 * Write the stack and the old-user cookie.
	 *
	 * @param string $kind       AUTH or SECURE: which stack cookie holds it.
	 * @param string $stack_json The stack.
	 * @param int    $expiration When both cookies expire.
	 * @param int    $user_id    The newest originating user (the old user).
	 * @param bool   $secure     Whether the stack cookie is secure.
	 * @param string $token      The old user's session token.
	 * @return void
	 */
	private static function write( $kind, $stack_json, $expiration, $user_id, $secure, $token ) {
		$other   = self::SECURE === $kind ? self::AUTH : self::SECURE;
		$olduser = wp_generate_auth_cookie( (int) $user_id, (int) $expiration, 'logged_in', (string) $token );
		$stale   = '' !== self::value( $other );

		/*
		 * The front-end cookie is secure when the stack is and the home URL is
		 * HTTPS, as core decides for its logged_in cookie, then the same
		 * filter core applies.
		 */
		$secure_olduser = $secure && 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );

		/** This filter is documented in wp-includes/pluggable.php */
		$secure_olduser = (bool) apply_filters( 'secure_logged_in_cookie', $secure_olduser, (int) $user_id, (bool) $secure ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as wp_set_auth_cookie() does.

		self::$written[ $kind ]         = $stack_json;
		self::$written[ $other ]        = '';
		self::$written[ self::OLDUSER ] = $olduser;

		if ( ! self::sending() ) {
			return;
		}

		self::send( self::name( $kind ), $stack_json, (int) $expiration, SITECOOKIEPATH, (bool) $secure );
		self::send( self::name( self::OLDUSER ), $olduser, (int) $expiration, COOKIEPATH, $secure_olduser );

		if ( $stale ) {
			self::send( self::name( $other ), ' ', time() - YEAR_IN_SECONDS, SITECOOKIEPATH, false );
		}
	}

	/**
	 * Whether to send cookies (filter `blt_toolkit_switch_send_cookies`).
	 *
	 * @return bool
	 */
	private static function sending() {
		/**
		 * Filter whether the switch cookies are sent with setcookie() (SW-3).
		 *
		 * The switch itself still happens; return false for tests and for
		 * headless setups that carry the cookies themselves.
		 *
		 * @param bool $send Whether to send the switch cookies. Default true.
		 */
		return (bool) apply_filters( 'blt_toolkit_switch_send_cookies', true );
	}

	/**
	 * Send one cookie: HttpOnly and SameSite=Lax always (SW-3).
	 *
	 * @param string $name    Cookie name.
	 * @param string $value   Value.
	 * @param int    $expires Expiration (Unix time); in the past to delete.
	 * @param string $path    Path.
	 * @param bool   $secure  Secure flag.
	 * @return void
	 */
	private static function send( $name, $value, $expires, $path, $secure ) {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			$name,
			$value,
			array(
				'expires'  => (int) $expires,
				'path'     => (string) $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '',
				'secure'   => (bool) $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
