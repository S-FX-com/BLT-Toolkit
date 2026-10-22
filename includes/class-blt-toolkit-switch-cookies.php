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
 *   originating user, path `SITECOOKIEPATH`, or a shorter path when the
 *   login URL lies outside it (stack_path(), SW-2).
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
 * wp_set_auth_cookie() decides it for the core cookies. The cookies persist
 * (48 hours) only when the person's own sign-in did ("Remember Me", SW-7);
 * otherwise they are browser-session cookies, and the 48-hour limit stays
 * inside each value. The filter `blt_toolkit_switch_send_cookies` (default
 * true) stops the `setcookie()` calls for tests and headless setups.
 *
 * The stack operations (parse(), encode(), push(), pop(), peek(),
 * is_full()), is_persistent(), expires() and path_for() are pure: they take
 * and return plain strings and numbers and call no WordPress function, so
 * tests/test-switch-cookies.php covers them (SPEC 18). Reading and writing
 * the cookies is kept apart from them.
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
	 * Most entries the stack keeps, which keeps the cookie well under the
	 * 4 KB browsers accept. Switching to someone and switching off are both
	 * refused on a full stack (is_full()), so the oldest entry, the person
	 * who signed in, is never dropped; push() still caps a stack it is
	 * handed.
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
	 * Whether a stack has no room for another entry (MAX_DEPTH). Pure.
	 *
	 * Switch to and switch off check it before pushing (SW-4, SW-6), so a
	 * full stack never loses its oldest entry, the person who signed in.
	 *
	 * @param string[] $stack Entries, oldest first.
	 * @return bool
	 */
	public static function is_full( array $stack ) {
		return count( $stack ) >= self::MAX_DEPTH;
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
	 * The `expires` attribute of a switch cookie (SW-3, SW-7). Pure.
	 *
	 * A persistent cookie expires with its value; otherwise it is a
	 * browser-session cookie (0), so closing the browser ends the way back
	 * just as it ends a sign-in made without "Remember Me". The value keeps
	 * its own 48-hour expiration either way.
	 *
	 * @param int  $expiration The value's expiration (Unix time).
	 * @param bool $persistent Whether the person's sign-in persists.
	 * @return int
	 */
	public static function expires( $expiration, $persistent ) {
		return $persistent ? (int) $expiration : 0;
	}

	/**
	 * The path for the stack cookie (SW-3, SW-2). Pure.
	 *
	 * SW-3 scopes the stack to `SITECOOKIEPATH`. Switching back reads it on
	 * the login URL (SW-2), and a login URL filter can move that outside
	 * the WordPress directory: BLT Secure's custom slug is a home URL, so
	 * with WordPress in its own directory (site `/wp/`, slug `/team-login`)
	 * the browser would never send the stack there. When the login path is
	 * not under `$site_path`, the path is the longest directory both share.
	 *
	 * @param string $site_path  `SITECOOKIEPATH`.
	 * @param string $login_path The path of wp_login_url(), '' when unknown.
	 * @return string A path that starts and ends with `/`.
	 */
	public static function path_for( $site_path, $login_path ) {
		$site_path  = '/' . trim( (string) $site_path, '/' ) . '/';
		$site_path  = '//' === $site_path ? '/' : $site_path;
		$login_path = (string) $login_path;

		if ( '' === $login_path || 0 === strpos( $login_path, $site_path ) ) {
			return $site_path;
		}

		$site  = array_values( array_filter( explode( '/', $site_path ), 'strlen' ) );
		$login = array_values( array_filter( explode( '/', $login_path ), 'strlen' ) );
		$same  = array();

		foreach ( $site as $i => $part ) {
			if ( ! isset( $login[ $i ] ) || $login[ $i ] !== $part ) {
				break;
			}

			$same[] = $part;
		}

		// A cookie path covers only what starts with it, so `/blog/` does not
		// cover a login path of exactly `/blog`: step up until it does.
		while ( array() !== $same && 0 !== strpos( $login_path, '/' . implode( '/', $same ) . '/' ) ) {
			array_pop( $same );
		}

		return array() === $same ? '/' : '/' . implode( '/', $same ) . '/';
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
	 * The path the stack cookies are set on: `SITECOOKIEPATH`, or a shorter
	 * one that also covers the login URL (path_for()).
	 *
	 * @return string
	 */
	public static function stack_path() {
		$login = wp_parse_url( wp_login_url(), PHP_URL_PATH );

		return self::path_for( SITECOOKIEPATH, is_string( $login ) ? $login : '' );
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
	 * Whether a cookie's current value is the one the browser sent (nothing
	 * was written to it this request).
	 *
	 * @param string $kind AUTH, SECURE or OLDUSER.
	 * @return bool
	 */
	public static function from_browser( $kind ) {
		return ! array_key_exists( $kind, self::$written );
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
	 * @param int    $user_id    The originating user.
	 * @param string $token      Their session token, which switching back returns to.
	 * @param bool   $persistent Whether the cookies outlive the browser session (SW-7).
	 * @return void
	 */
	public static function push_user( $user_id, $token, $persistent = true ) {
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

		self::write( $kind, self::push( self::encode( $read['stack'] ), $entry ), $expiration, $user_id, $secure, $token, $persistent );
	}

	/**
	 * Take the newest user off the stack (SW-5).
	 *
	 * When users remain, the old-user cookie is made again for the newest of
	 * them, with the session token from their stack entry. When none remain,
	 * or the newest no longer validates (its session ended), every switch
	 * cookie is cleared: there is nothing left to switch back to.
	 *
	 * @param bool $persistent Whether the cookies outlive the browser session (SW-7).
	 * @return void
	 */
	public static function pop_user( $persistent = true ) {
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

		self::write( $read['kind'], self::encode( $stack ), time() + self::LIFETIME, $user_id, self::SECURE === $read['kind'], (string) $parts['token'], $persistent );
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

		foreach ( self::stack_paths() as $path ) {
			self::send( self::name( self::AUTH ), ' ', $expire, $path, false );
			self::send( self::name( self::SECURE ), ' ', $expire, $path, false );
		}

		self::send( self::name( self::OLDUSER ), ' ', $expire, COOKIEPATH, false );
	}

	/**
	 * End switching in this browser for good (SW-10): destroy every session
	 * the switch cookies could return to, then clear the cookies.
	 *
	 * Run on a sign-in and a sign-out. Clearing the cookies alone would
	 * leave those sessions live, often an administrator's, until they
	 * expire, so any copy of a stack entry, of the old-user cookie or of
	 * the original sign-in cookie would still sign in. Each entry is
	 * validated with its own scheme before its token is used, so only a
	 * cookie this site made can end a session. Returns at once, without a
	 * query, when the browser sent no switch cookie.
	 *
	 * @return void
	 */
	public static function end_sessions() {
		if ( ! self::present() ) {
			return;
		}

		$cookies = array();

		foreach ( array( self::AUTH, self::SECURE ) as $kind ) {
			foreach ( self::parse( self::value( $kind ) ) as $entry ) {
				$cookies[] = array( $entry, self::scheme( $kind ) );
			}
		}

		if ( '' !== self::olduser() ) {
			$cookies[] = array( self::olduser(), 'logged_in' );
		}

		foreach ( $cookies as $cookie ) {
			$user_id = (int) wp_validate_auth_cookie( $cookie[0], $cookie[1] );
			$parts   = $user_id > 0 ? wp_parse_auth_cookie( $cookie[0], $cookie[1] ) : false;

			if ( is_array( $parts ) && ! empty( $parts['token'] ) ) {
				WP_Session_Tokens::get_instance( $user_id )->destroy( (string) $parts['token'] );
			}
		}

		self::clear();
	}

	/**
	 * The paths a stack cookie may have been set on: `SITECOOKIEPATH` and,
	 * when it differs, stack_path(). A cleared cookie is cleared on both.
	 *
	 * @return string[]
	 */
	private static function stack_paths() {
		return array_values( array_unique( array( (string) SITECOOKIEPATH, self::stack_path() ) ) );
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
	 * @param bool   $persistent Whether the cookies outlive the browser session (SW-7).
	 * @return void
	 */
	private static function write( $kind, $stack_json, $expiration, $user_id, $secure, $token, $persistent ) {
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

		$expires = self::expires( (int) $expiration, (bool) $persistent );
		$path    = self::stack_path();

		self::send( self::name( $kind ), $stack_json, $expires, $path, (bool) $secure );
		self::send( self::name( self::OLDUSER ), $olduser, $expires, COOKIEPATH, $secure_olduser );

		if ( $stale ) {
			self::send( self::name( $other ), ' ', time() - YEAR_IN_SECONDS, $path, false );
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
