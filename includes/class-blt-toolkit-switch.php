<?php
/**
 * Switch module: switch to another user, switch back, switch off.
 *
 * The request flow, the session handling and the duplicate-switch check
 * follow User Switching 1.12.2 by John Blackbourn (action_init(),
 * switch_to_user(), switch_off_user(), authenticate_old_user(),
 * get_duplicated_switch(), remember()), reimplemented with BLT names and the
 * BLT permission model (SPEC 7).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switch to another user's account and back (SPEC 7).
 *
 * The registry boots this module only when it is on, not disabled by
 * `BLT_TOOLKIT_DISABLE_MODULES` and User Switching is not active (GEN-1).
 *
 * - SW-1: switching to a user needs `blt_switch_to_user` for that user (the
 *   Guard maps it to `blt_switch_users` plus SEC-1 to SEC-5); switching off
 *   needs `blt_switch_users`.
 * - SW-2: the three actions are GET links through wp_login_url(), handled on
 *   `init`, each with its own nonce: `blt_switch_to_user_{user_id}`,
 *   `blt_switch_back_{original_user_id}` and `blt_switch_off` (SEC-7).
 * - SW-3: cookies in BLT_Toolkit_Switch_Cookies.
 * - SW-4 to SW-8: the flows below. Session information is kept in core's
 *   `session_tokens` user meta: the target's new session carries
 *   `blt_switched_from_id` and `blt_switched_from_session`, the actor's
 *   own session `blt_switched_into` and `blt_switched_at` (SPEC 14.4).
 * - SW-9: redirects in BLT_Toolkit_Switch_Redirects.
 * - SW-10: on `wp_login` and `wp_logout` (and on BLT M365 WP SSO's sign-in,
 *   which fires no `wp_login`) every session the switch cookies could
 *   return to is destroyed and the cookies are cleared, and
 *   `$_COOKIE[ LOGGED_IN_COOKIE ]` is dropped on `clear_auth_cookie`. The
 *   shared layer does the same while this module is off
 *   (BLT_Toolkit_Plugin::end_switch()).
 * - A switched session lives only as long as the session it was switched
 *   from: on `determine_current_user`, a session whose
 *   `blt_switched_from_session` link (followed back to the person who
 *   signed in) is gone is destroyed and the request is signed out. It also
 *   expires with the switch cookies (48 hours), whatever "Remember Me"
 *   gave the actor.
 * - SW-11: messages addressed to the originating user use their locale.
 * - SW-19: User Switching's action names and current_user_switched(),
 *   behind the setting `switch.compat_hooks`.
 * - SW-24: privacy policy text about the cookies.
 * - LOG-2: chain() is the log's switch chain provider; every switch, switch
 *   back and switch off is logged under module `switch`.
 *
 * The places switching appears (SPEC 7.3) are BLT_Toolkit_Switch_Ui, and
 * WooCommerce (SW-22) is BLT_Toolkit_Switch_Woocommerce; boot() starts both.
 *
 * Who switched is answered in two strengths, as User Switching does:
 * old_user() (the old-user cookie, path `COOKIEPATH`, so it is sent on the
 * site's front end and REST requests as well as in wp-admin) for everything
 * shown and for the log, and original_user() (old_user() plus the newest
 * stack entry) for switching back. While someone is signed in, old_user()
 * also requires the current session to carry the switch link to that
 * user's session, so switch cookies left behind by someone else never
 * count.
 *
 * Cost per request (SEC-9): boot() only adds hooks. On `init`, a request
 * without one of the three `action` values returns after one array lookup.
 * old_user(), original_user() and chain() return at once, without a query,
 * when the browser sent no old-user cookie, so a visitor's page view runs
 * no query from this module; a switch cookie that no longer validates is
 * expired, so it costs at most one request. The `determine_current_user`
 * check reads only the session data core has already loaded, plus one
 * user's sessions per link while switched.
 */
class BLT_Toolkit_Switch implements BLT_Toolkit_Module {

	/**
	 * Module key.
	 */
	const KEY = 'switch';

	/**
	 * Action parameter for switching to a user (SW-2).
	 */
	const ACTION_TO = 'blt_switch_to_user';

	/**
	 * Action parameter for switching back (SW-2).
	 */
	const ACTION_BACK = 'blt_switch_back';

	/**
	 * Action parameter for switching off (SW-2).
	 */
	const ACTION_OFF = 'blt_switch_off';

	/**
	 * Session key on the target's session: who switched in (SPEC 14.4).
	 */
	const FROM_ID = 'blt_switched_from_id';

	/**
	 * Session key on the target's session: the verifier (the
	 * `session_tokens` key) of the session they switched in from.
	 */
	const FROM_SESSION = 'blt_switched_from_session';

	/**
	 * Session key on the actor's session: who they switched into.
	 */
	const INTO = 'blt_switched_into';

	/**
	 * Session key on the actor's session: when they switched.
	 */
	const AT = 'blt_switched_at';

	/**
	 * Session key on the target's session: whether the switch was made with
	 * "Remember Me" (SW-7). The session itself is capped at the switch
	 * cookies' lifetime, so its length no longer tells.
	 */
	const REMEMBER = 'blt_switched_remember';

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * old_user(): null until computed, then a WP_User or false. Reset
	 * whenever the switch cookies change.
	 *
	 * @var WP_User|false|null
	 */
	private static $old = null;

	/**
	 * The session token inside the validated old-user cookie.
	 *
	 * @var string
	 */
	private static $old_token = '';

	/**
	 * original_user(): null until computed, then a WP_User or false.
	 *
	 * @var WP_User|false|null
	 */
	private static $original = null;

	/**
	 * chain() results, keyed by current user ID.
	 *
	 * @var array<int,int[]>
	 */
	private static $chains = array();

	/**
	 * The session a switch flow just signed in with this request, as
	 * array( user ID, token ): core does not update `$_COOKIE` when it sends
	 * the new cookie. Null when no flow ran.
	 *
	 * @var array|null
	 */
	private static $token = null;

	/**
	 * maybe_switch_url() results: "current user ID|target ID" => URL or false.
	 *
	 * @var array<string,string|false>
	 */
	private static $switch_urls = array();

	// ---------- Module ----------

	/**
	 * Module key.
	 *
	 * @return string
	 */
	public function key() {
		return self::KEY;
	}

	/**
	 * Module name.
	 *
	 * @return string
	 */
	public function label() {
		return BLT_Toolkit_Plugin::labels()[ self::KEY ]['label'];
	}

	/**
	 * One-line description.
	 *
	 * @return string
	 */
	public function description() {
		return BLT_Toolkit_Plugin::labels()[ self::KEY ]['description'];
	}

	/**
	 * Register the hooks. Called once on `plugins_loaded` priority 1; it only
	 * adds hooks and reads the (autoloaded) settings.
	 *
	 * @return void
	 */
	public function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// SW-2, at the default priority on `init` (SPEC 4.2): after the schema
		// check, before the recovery check.
		add_action( 'init', array( __CLASS__, 'handle_request' ) );

		// SW-10.
		add_action( 'wp_login', array( __CLASS__, 'clear_cookies' ), 10, 0 );
		add_action( 'wp_logout', array( __CLASS__, 'clear_cookies' ), 10, 0 );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'forget_logged_in_cookie' ), 10, 0 );

		// SW-10 for BLT M365 WP SSO, whose sign-in sets the auth cookie
		// without firing `wp_login`; without this, whoever signs in through
		// Microsoft in a browser left switched would be offered the switch back.
		add_action( 'blt_m365_sso_user_authenticated', array( __CLASS__, 'clear_cookies' ), 10, 0 );

		// A switched session ends with the session it was switched from. After
		// core's cookie checks (priorities 10 and 20).
		add_filter( 'determine_current_user', array( __CLASS__, 'end_orphaned_session' ), 99 );

		// Who switched depends on who is signed in (old_user()).
		add_action( 'set_current_user', array( __CLASS__, 'forget_user_state' ), 10, 0 );

		// SW-24.
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );

		// LOG-2.
		BLT_Toolkit_Log::set_switch_chain_provider( array( __CLASS__, 'chain' ) );

		// SW-19: the shim is a functions-only file, loaded only with the setting.
		if ( self::compat_hooks() ) {
			require_once BLT_TOOLKIT_DIR . 'includes/blt-toolkit-compat.php';
		}

		// SPEC 7.3.
		if ( class_exists( 'BLT_Toolkit_Switch_Ui' ) ) {
			BLT_Toolkit_Switch_Ui::boot();
		}

		// SW-22: only with WooCommerce active and the setting on. Every plugin
		// file is loaded before `plugins_loaded`, so its class is known here.
		if ( class_exists( 'WooCommerce', false ) && BLT_Toolkit_Settings::get( 'switch', 'woocommerce' ) && class_exists( 'BLT_Toolkit_Switch_Woocommerce' ) ) {
			BLT_Toolkit_Switch_Woocommerce::boot();
		}
	}

	/**
	 * Whether User Switching's action names are fired too (SW-19).
	 *
	 * @return bool
	 */
	public static function compat_hooks() {
		return (bool) BLT_Toolkit_Settings::get( 'switch', 'compat_hooks' );
	}

	// ---------- URLs (SW-2) ----------

	/**
	 * The URL that switches the current user to a user (SW-2).
	 *
	 * It does not check permission; maybe_switch_url() does. The URL is raw
	 * (`&`, not `&amp;`): escape it with esc_url() when printing it.
	 *
	 * @param WP_User $target   The user to switch to.
	 * @param array   $redirect Optional redirect parameters (SW-9): `redirect_to`, `redirect_to_post`,
	 *                          `redirect_to_term`, `redirect_to_user` or `redirect_to_comment`.
	 * @return string
	 */
	public static function switch_to_url( WP_User $target, array $redirect = array() ) {
		return self::action_url(
			array(
				'action'  => self::ACTION_TO,
				'user_id' => (int) $target->ID,
			),
			$redirect,
			self::ACTION_TO . '_' . (int) $target->ID
		);
	}

	/**
	 * The URL that takes the current user to this user, else false (SW-1,
	 * SW-12, SW-13, SW-27): the switch-back URL when this is the user who
	 * switched in, as User Switching's maybe_switch_url() does, so nobody
	 * nests a switch into the account they came from; otherwise the
	 * switch-to URL when the current user may switch to this user. Cached
	 * per request by current user and target (SEC-9).
	 *
	 * @param WP_User $target The user to switch to.
	 * @return string|false
	 */
	public static function maybe_switch_url( WP_User $target ) {
		$key = get_current_user_id() . '|' . (int) $target->ID;

		if ( ! array_key_exists( $key, self::$switch_urls ) ) {
			$old = is_user_logged_in() ? self::old_user() : false;

			if ( $old instanceof WP_User && (int) $old->ID === (int) $target->ID ) {
				self::$switch_urls[ $key ] = self::switch_back_url( $old );
			} else {
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Meta capability the Guard maps (SPEC 5.1, SW-1).
				self::$switch_urls[ $key ] = current_user_can( 'blt_switch_to_user', (int) $target->ID ) ? self::switch_to_url( $target ) : false;
			}
		}

		return self::$switch_urls[ $key ];
	}

	/**
	 * The URL that switches back to the originating user (SW-2, SW-5).
	 *
	 * @param WP_User $original The user to switch back to (original_user()).
	 * @param array   $redirect Optional redirect parameters (SW-9).
	 * @return string Raw URL.
	 */
	public static function switch_back_url( WP_User $original, array $redirect = array() ) {
		return self::action_url(
			array( 'action' => self::ACTION_BACK ),
			$redirect,
			self::ACTION_BACK . '_' . (int) $original->ID
		);
	}

	/**
	 * The URL that switches the current user off (SW-2, SW-6).
	 *
	 * @param array $redirect Optional redirect parameters (SW-9); in wp-admin
	 *                        pass the item being edited
	 *                        (BLT_Toolkit_Switch_Redirects::admin_context()).
	 * @return string Raw URL.
	 */
	public static function switch_off_url( array $redirect = array() ) {
		return self::action_url( array( 'action' => self::ACTION_OFF ), $redirect, self::ACTION_OFF );
	}

	/**
	 * A nonced action URL on the login URL (SW-2).
	 *
	 * Built on wp_login_url(), so a login URL filter (a custom login slug)
	 * applies, the action works when nobody is signed in, and page caches
	 * leave it alone.
	 *
	 * @param array  $args     Action arguments.
	 * @param array  $redirect Redirect parameters.
	 * @param string $nonce    Nonce action.
	 * @return string
	 */
	private static function action_url( array $args, array $redirect, $nonce ) {
		foreach ( BLT_Toolkit_Switch_Redirects::args( $redirect ) as $key => $value ) {
			$args[ $key ] = 'redirect_to' === $key ? rawurlencode( (string) $value ) : (int) $value;
		}

		$args['_wpnonce'] = wp_create_nonce( $nonce );

		return add_query_arg( $args, wp_login_url() );
	}

	// ---------- State ----------

	/**
	 * The user who switched in, for everything shown and for the log (SW-14
	 * to SW-19, SW-22, LOG-2), as User Switching's get_old_user(): the
	 * old-user cookie (`logged_in` scheme, path `COOKIEPATH`), which is sent
	 * on the site's front end and REST requests as well as in wp-admin.
	 * Validating it also confirms that user's session is still live. Works
	 * while nobody is signed in, after a switch off.
	 *
	 * While someone is signed in, the cookie counts only for the session it
	 * was made for: the current session must carry `blt_switched_from_id`
	 * equal to that user and `blt_switched_from_session` equal to the
	 * verifier of the cookie's token (is_bound()). So switch cookies left in
	 * a browser by someone else (a sign-in that fired no `wp_login`, or one
	 * made while the module was off) never offer a way into another account.
	 *
	 * While nobody is signed in (after a switch off, or once a switched
	 * session ended), the session the cookie names must still lead back to
	 * the person who signed in (follow_links()), as a signed-in switched
	 * session must (end_orphaned_session()); when it does not, what is left
	 * of the switch is ended.
	 *
	 * A cookie the browser sent that fails either check is stale: it is
	 * expired (when headers can still be sent), so later requests stop at
	 * the cookie check instead of running queries (SEC-9).
	 *
	 * Switching back needs more: original_user().
	 *
	 * Cached per request; no query when the browser sent no old-user cookie.
	 *
	 * @return WP_User|false
	 */
	public static function old_user() {
		if ( null !== self::$old ) {
			return self::$old;
		}

		self::$old       = false;
		self::$old_token = '';
		$olduser         = BLT_Toolkit_Switch_Cookies::olduser();

		if ( '' === $olduser ) {
			return false;
		}

		$old_id = (int) wp_validate_auth_cookie( $olduser, 'logged_in' );
		$parts  = $old_id > 0 ? wp_parse_auth_cookie( $olduser, 'logged_in' ) : false;
		$token  = is_array( $parts ) && isset( $parts['token'] ) ? (string) $parts['token'] : '';

		if ( '' === $token ) {
			self::discard_stale();

			return false;
		}

		if ( is_user_logged_in() ) {
			$session = self::current_session();

			// Signed in without a cookie session of their own (an application
			// password, say): nothing to bind to, so nothing to show.
			if ( null === $session ) {
				return false;
			}

			if ( ! self::is_bound( $session, $old_id, self::verifier( $token ) ) ) {
				self::discard_stale();

				return false;
			}
		} else {
			$session = WP_Session_Tokens::get_instance( $old_id )->get( $token );

			if ( is_array( $session ) && ! empty( $session[ self::FROM_ID ] ) && ! self::follow_links( $session, array( __CLASS__, 'session_by_verifier' ) )['intact'] ) {
				if ( BLT_Toolkit_Switch_Cookies::from_browser( BLT_Toolkit_Switch_Cookies::OLDUSER ) ) {
					BLT_Toolkit_Switch_Cookies::end_sessions();
				}

				return false;
			}
		}

		$user = get_userdata( $old_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		self::$old       = $user;
		self::$old_token = $token;

		return $user;
	}

	/**
	 * The user the current browser can switch back to (SW-5): old_user(),
	 * and the newest entry of the stack (`auth` or `secure_auth` scheme)
	 * must validate for the same user, as in User Switching's
	 * authenticate_old_user(). Only the switch-back request needs this: the
	 * stack is scoped to the login URL's path
	 * (BLT_Toolkit_Switch_Cookies::stack_path()), so other requests may not
	 * carry it.
	 *
	 * @return WP_User|false
	 */
	public static function original_user() {
		if ( null !== self::$original ) {
			return self::$original;
		}

		self::$original = false;
		$old            = self::old_user();

		if ( ! $old instanceof WP_User ) {
			return false;
		}

		$read   = BLT_Toolkit_Switch_Cookies::read_stack();
		$newest = self::newest( $read['stack'] );

		if ( '' === $newest || (int) wp_validate_auth_cookie( $newest, $read['scheme'] ) !== (int) $old->ID ) {
			return false;
		}

		self::$original = $old;

		return $old;
	}

	/**
	 * Whether a session was switched into from the given user's session.
	 * Pure.
	 *
	 * @param mixed  $session  The current session's data.
	 * @param int    $old_id   The user named by the old-user cookie.
	 * @param string $verifier The verifier of that cookie's session token.
	 * @return bool
	 */
	public static function is_bound( $session, $old_id, $verifier ) {
		if ( ! is_array( $session ) || (int) $old_id <= 0 || '' === (string) $verifier ) {
			return false;
		}

		$from_id  = isset( $session[ self::FROM_ID ] ) && is_numeric( $session[ self::FROM_ID ] ) ? (int) $session[ self::FROM_ID ] : 0;
		$from_ses = isset( $session[ self::FROM_SESSION ] ) && is_string( $session[ self::FROM_SESSION ] ) ? $session[ self::FROM_SESSION ] : '';

		return (int) $old_id === $from_id && hash_equals( $from_ses, (string) $verifier );
	}

	/**
	 * Follow a switched session's links back to the session of the person
	 * who signed in. Pure: sessions are found through `$lookup`.
	 *
	 * Each link is `blt_switched_from_id` plus `blt_switched_from_session`
	 * (the verifier of the session switched from). The walk ends, intact,
	 * at a session that was not switched into (the person who signed in).
	 * It is broken when a link has no verifier, its session is gone, a link
	 * repeats, or it runs deeper than any stack can be.
	 *
	 * @param mixed    $session The session to start from.
	 * @param callable $lookup  function( int $user_id, string $verifier ): array|null, the live session or null.
	 * @param int      $max     Most links to follow.
	 * @return array{ids:int[],intact:bool} `ids`: the users switched from, oldest first, up to a broken link.
	 */
	public static function follow_links( $session, callable $lookup, $max = BLT_Toolkit_Switch_Cookies::MAX_DEPTH ) {
		$ids  = array();
		$seen = array();

		while ( is_array( $session ) ) {
			$from_id  = isset( $session[ self::FROM_ID ] ) && is_numeric( $session[ self::FROM_ID ] ) ? (int) $session[ self::FROM_ID ] : 0;
			$verifier = isset( $session[ self::FROM_SESSION ] ) && is_string( $session[ self::FROM_SESSION ] ) ? $session[ self::FROM_SESSION ] : '';

			if ( $from_id <= 0 ) {
				return array(
					'ids'    => $ids,
					'intact' => true,
				);
			}

			$key = $from_id . '|' . $verifier;

			if ( '' === $verifier || count( $ids ) >= (int) $max || isset( $seen[ $key ] ) ) {
				break;
			}

			$seen[ $key ] = true;
			$session      = call_user_func( $lookup, $from_id, $verifier );

			if ( ! is_array( $session ) ) {
				break;
			}

			array_unshift( $ids, $from_id );
		}

		return array(
			'ids'    => $ids,
			'intact' => false,
		);
	}

	/**
	 * One of a user's live sessions by its verifier (the key core stores it
	 * under), or null. Goes through the session manager's own
	 * get_session(), so a custom `session_token_manager` is respected and an
	 * expired session does not count.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $verifier Session verifier.
	 * @return array|null
	 */
	public static function session_by_verifier( $user_id, $verifier ) {
		if ( (int) $user_id <= 0 || '' === (string) $verifier ) {
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
	 * The current user's session: the one a switch flow just signed in with,
	 * else the one named by the logged-in cookie. Null when that cookie is
	 * not the current user's (another authentication method) or names no
	 * live session.
	 *
	 * @return array|null
	 */
	private static function current_session() {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return null;
		}

		if ( is_array( self::$token ) && (int) self::$token[0] === $user_id ) {
			$token = (string) self::$token[1];
		} else {
			$cookie = wp_parse_auth_cookie( '', 'logged_in' );
			$user   = wp_get_current_user();

			if ( ! is_array( $cookie ) || empty( $cookie['token'] ) || ! isset( $cookie['username'] ) || (string) $cookie['username'] !== (string) $user->user_login ) {
				return null;
			}

			$token = (string) $cookie['token'];
		}

		$session = '' !== $token ? WP_Session_Tokens::get_instance( $user_id )->get( $token ) : null;

		return is_array( $session ) ? $session : null;
	}

	/**
	 * Expire switch cookies the browser sent that no longer count
	 * (old_user()). Never touches cookies written during this request.
	 *
	 * @return void
	 */
	private static function discard_stale() {
		if ( ! BLT_Toolkit_Switch_Cookies::from_browser( BLT_Toolkit_Switch_Cookies::OLDUSER ) ) {
			return;
		}

		BLT_Toolkit_Switch_Cookies::clear();
		self::$original = false;
	}

	/**
	 * The newest entry of a stack, or '' when it is empty.
	 *
	 * Never pass '' to core's cookie functions: wp_parse_auth_cookie() and
	 * wp_validate_auth_cookie() read the request's own auth cookie when
	 * given an empty value.
	 *
	 * @param string[] $stack Stack entries, oldest first.
	 * @return string
	 */
	private static function newest( array $stack ) {
		return BLT_Toolkit_Switch_Cookies::peek( BLT_Toolkit_Switch_Cookies::encode( $stack ) );
	}

	/**
	 * Whether the current user switched into this session (signed in, with
	 * a valid old user bound to this session).
	 *
	 * @return bool
	 */
	public static function is_switched() {
		return is_user_logged_in() && false !== self::old_user();
	}

	/**
	 * The switch chain (LOG-2): the user IDs from the person who signed in,
	 * oldest first, to the current user. Empty when not switched. After a
	 * switch off nobody is signed in, so the chain ends with the user who
	 * switched off.
	 *
	 * Built on the server from the session links (follow_links()), starting
	 * at the current session, or at the old-user cookie's session after a
	 * switch off, so it does not depend on the stack cookie, which the
	 * site's front end and REST requests may not receive. A link whose
	 * session ended stops the chain there.
	 *
	 * @return int[]
	 */
	public static function chain() {
		$old = self::old_user();

		if ( ! $old instanceof WP_User ) {
			return array();
		}

		$current = get_current_user_id();

		if ( isset( self::$chains[ $current ] ) ) {
			return self::$chains[ $current ];
		}

		if ( $current > 0 ) {
			$start   = $current;
			$session = self::current_session();
		} else {
			$start   = (int) $old->ID;
			$session = self::session_by_verifier( $start, self::verifier( self::$old_token ) );
		}

		$links   = self::follow_links( $session, array( __CLASS__, 'session_by_verifier' ) );
		$chain   = $links['ids'];
		$chain[] = $start;

		self::$chains[ $current ] = $chain;

		return $chain;
	}

	/**
	 * Forget the cached state when the current user changes.
	 * `set_current_user` callback.
	 *
	 * @return void
	 */
	public static function forget_user_state() {
		self::forget_state();
	}

	/**
	 * Forget the cached state after the cookies or the current user changed.
	 *
	 * @return void
	 */
	private static function forget_state() {
		self::$old         = null;
		self::$old_token   = '';
		self::$original    = null;
		self::$chains      = array();
		self::$switch_urls = array();
	}

	/**
	 * Whether the current cookie is a "remember me" one (SW-7, as User
	 * Switching's remember()): its remaining lifetime is longer than the
	 * default, non-persistent, `auth_cookie_expiration`.
	 *
	 * @return bool
	 */
	public static function remember() {
		$current = wp_parse_auth_cookie( '', 'logged_in' );

		if ( ! is_array( $current ) || empty( $current['expiration'] ) ) {
			return false;
		}

		// A switched session is capped at the switch cookies' lifetime, so it
		// carries the choice it was made with instead.
		$session = self::current_session();

		if ( is_array( $session ) && isset( $session[ self::REMEMBER ] ) ) {
			return (bool) $session[ self::REMEMBER ];
		}

		return BLT_Toolkit_Switch_Cookies::is_persistent( (int) $current['expiration'], time(), self::default_life( get_current_user_id() ) );
	}

	/**
	 * The lifetime core gives a cookie without "remember me".
	 *
	 * @param int $user_id User ID.
	 * @return int Seconds.
	 */
	private static function default_life( $user_id ) {
		/** This filter is documented in wp-includes/pluggable.php */
		return (int) apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, (int) $user_id, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as wp_set_auth_cookie() does.
	}

	// ---------- Messages (SW-11) ----------

	/**
	 * "Switched to {Name}." Addressed to the person who switched, so it is in
	 * their locale when an original user is known.
	 *
	 * @param WP_User $user The user switched to.
	 * @return string Plain text.
	 */
	public static function switched_to_message( WP_User $user ) {
		$original = self::old_user();
		$switched = $original instanceof WP_User && switch_to_user_locale( $original->ID );

		/* translators: %s: display name of the user switched to. */
		$message = sprintf( __( 'Switched to %s.', 'blt-toolkit' ), $user->display_name );

		if ( $switched ) {
			restore_previous_locale();
		}

		return $message;
	}

	/**
	 * "Switch back to {Name}", in that user's locale (SW-11).
	 *
	 * @param WP_User $original The user to switch back to.
	 * @return string Plain text.
	 */
	public static function switch_back_message( WP_User $original ) {
		$switched = switch_to_user_locale( $original->ID );

		/* translators: %s: display name of the user to switch back to. */
		$message = sprintf( __( 'Switch back to %s', 'blt-toolkit' ), $original->display_name );

		if ( $switched ) {
			restore_previous_locale();
		}

		return $message;
	}

	/**
	 * "Switched back to {Name}.", in the reader's locale (SW-11): the user
	 * switched back to, unless they are still switched in from someone
	 * else, who is then the one reading it.
	 *
	 * @param WP_User      $user   The user switched back to.
	 * @param WP_User|null $reader Who reads the message, when not `$user` (a nested switch back).
	 * @return string Plain text.
	 */
	public static function switched_back_message( WP_User $user, $reader = null ) {
		$reader   = $reader instanceof WP_User ? $reader : $user;
		$switched = switch_to_user_locale( $reader->ID );

		/* translators: %s: display name of the user switched back to. */
		$message = sprintf( __( 'Switched back to %s.', 'blt-toolkit' ), $user->display_name );

		if ( $switched ) {
			restore_previous_locale();
		}

		return $message;
	}

	// ---------- Request handling (SW-2) ----------

	/**
	 * Route the three switch actions. Hooked on `init`.
	 *
	 * @return void
	 */
	public static function handle_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Each action checks its own nonce below, after the capability check.
		if ( ! isset( $_REQUEST['action'] ) || ! is_string( $_REQUEST['action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( self::ACTION_TO, self::ACTION_BACK, self::ACTION_OFF ), true ) ) {
			return;
		}

		// SW-2: never cached, whichever URL the login screen lives at.
		nocache_headers();

		switch ( $action ) {
			case self::ACTION_TO:
				self::request_switch_to();
				break;

			case self::ACTION_BACK:
				self::request_switch_back();
				break;

			case self::ACTION_OFF:
				self::request_switch_off();
				break;
		}
	}

	/**
	 * The request's redirect parameters, unslashed.
	 *
	 * @return array
	 */
	private static function redirect_request() {
		$out = array();

		foreach ( BLT_Toolkit_Switch_Redirects::PARAMS as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read after the action's nonce was checked; redirect_to is validated by wp_safe_redirect().
			if ( isset( $_REQUEST[ $param ] ) && is_scalar( $_REQUEST[ $param ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A URL is validated by wp_safe_redirect(); IDs are parsed as positive integers.
				$out[ $param ] = wp_unslash( (string) $_REQUEST[ $param ] );
			}
		}

		return $out;
	}

	/**
	 * Stop with an error page.
	 *
	 * @param string $message Translated message.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	private static function refuse( $message, $status ) {
		wp_die( esc_html( $message ), '', array( 'response' => (int) $status ) );
	}

	/**
	 * SW-4: switch to a user.
	 *
	 * @return void
	 */
	private static function request_switch_to() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce for this exact user ID is checked right after the capability.
		$user_id = isset( $_REQUEST['user_id'] ) && is_scalar( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0;

		// SW-1: the Guard refuses oneself, a missing target, SEC-1 and SEC-2.
		if ( $user_id <= 0 || ! current_user_can( 'blt_switch_to_user', $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Meta capability the Guard maps (SPEC 5.1).
			self::refuse( __( 'Could not switch users.', 'blt-toolkit' ), 403 );
		}

		check_admin_referer( self::ACTION_TO . '_' . $user_id );

		$target = get_userdata( $user_id );

		if ( ! $target instanceof WP_User ) {
			self::refuse( __( 'Could not switch users.', 'blt-toolkit' ), 404 );
		}

		$actor = wp_get_current_user();

		self::refuse_when_full();

		// SW-8, SW-23.
		$duplicate = self::duplicate_switch( $target, $actor );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce was checked above; `force` only confirms the warning.
		$forced = isset( $_REQUEST['force'] ) && '1' === $_REQUEST['force'];

		if ( null !== $duplicate && ! $forced ) {
			self::duplicate_page( $duplicate, $target );
		}

		self::switch_to( $target, self::remember(), null === $duplicate ? 0 : (int) $duplicate['user']->ID );

		BLT_Toolkit_Switch_Redirects::go(
			BLT_Toolkit_Switch_Redirects::destination( self::ACTION_TO, $target, $actor, self::redirect_request() ),
			BLT_Toolkit_Switch_Redirects::FLAG_SWITCHED
		);
	}

	/**
	 * SW-5: switch back to the originating user.
	 *
	 * @return void
	 */
	private static function request_switch_back() {
		$old = self::old_user();

		if ( ! $old instanceof WP_User ) {
			self::refuse( __( 'Could not switch users.', 'blt-toolkit' ), 400 );
		}

		// Both cookies must validate, for the same user (SW-5).
		$original = self::original_user();

		if ( ! $original instanceof WP_User || (int) $original->ID !== (int) $old->ID ) {
			self::refuse( __( 'Could not switch users.', 'blt-toolkit' ), 403 );
		}

		check_admin_referer( self::ACTION_BACK . '_' . $original->ID );

		$from = is_user_logged_in() ? wp_get_current_user() : null;

		self::switch_back( $original );

		// The auth-check modal's login screen asks for an interim result page,
		// which closes the modal (as User Switching does).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce was checked above.
		if ( ! empty( $_REQUEST['interim-login'] ) && function_exists( 'login_header' ) ) {
			$GLOBALS['interim_login'] = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's flag for the interim login result.
			login_header( '', '' );
			exit;
		}

		BLT_Toolkit_Switch_Redirects::go(
			BLT_Toolkit_Switch_Redirects::destination( self::ACTION_BACK, $original, $from, self::redirect_request() ),
			BLT_Toolkit_Switch_Redirects::FLAG_SWITCHED_BACK
		);
	}

	/**
	 * SW-6: switch off.
	 *
	 * @return void
	 */
	private static function request_switch_off() {
		// SW-1.
		if ( ! current_user_can( 'blt_switch_users' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1), granted on activation.
			/* translators: "switch off" means signing out for a moment, with a way back. */
			self::refuse( __( 'Could not switch off.', 'blt-toolkit' ), 403 );
		}

		check_admin_referer( self::ACTION_OFF );

		// SW-6 pushes onto the stack too, so a full stack is refused here as
		// it is for switch to, rather than dropping the person who signed in.
		self::refuse_when_full();

		$actor = wp_get_current_user();

		self::switch_off();

		BLT_Toolkit_Switch_Redirects::go(
			BLT_Toolkit_Switch_Redirects::destination( self::ACTION_OFF, null, $actor, self::redirect_request() ),
			BLT_Toolkit_Switch_Redirects::FLAG_SWITCHED_OFF
		);
	}

	/**
	 * Stop with a 403 when the stack has no room for another entry
	 * (BLT_Toolkit_Switch_Cookies::is_full()).
	 *
	 * @return void
	 */
	private static function refuse_when_full() {
		if ( BLT_Toolkit_Switch_Cookies::is_full( BLT_Toolkit_Switch_Cookies::read_stack()['stack'] ) ) {
			self::refuse( __( 'You have switched too many times in a row. Switch back first.', 'blt-toolkit' ), 403 );
		}
	}

	// ---------- Flows ----------

	/**
	 * Switch the current user to a user (SW-4 steps 3 to 6). The caller has
	 * checked permission, the nonce and the duplicate warning.
	 *
	 * The row is logged before the identity changes, so `acting_as_id` is
	 * the user switched from (0 when that is the person who signed in) and
	 * the chain ends with them (LOG-2).
	 *
	 * @param WP_User $target       The user to switch to.
	 * @param bool    $remember     Whether the new cookie persists (SW-7).
	 * @param int     $duplicate_of Who else was switched into the target when the warning was confirmed, or 0.
	 * @return array{new_token:string,old_token:string}
	 */
	private static function switch_to( WP_User $target, $remember, $duplicate_of = 0 ) {
		$actor_id  = get_current_user_id();
		$old_token = (string) wp_get_session_token();

		// A request authenticated without a cookie session has no token; give
		// the actor one session to come back to.
		if ( '' === $old_token ) {
			$old_token = WP_Session_Tokens::get_instance( $actor_id )->create( time() + BLT_Toolkit_Switch_Cookies::LIFETIME );
		}

		self::log(
			'switched_to',
			$target,
			/* translators: %s: display name of the user switched to. */
			sprintf( __( 'Switched to %s.', 'blt-toolkit' ), $target->display_name ),
			array(
				'from'         => self::person( $actor_id ),
				'to'           => self::person( (int) $target->ID ),
				'remember'     => (bool) $remember,
				'duplicate_of' => $duplicate_of > 0 ? self::person( $duplicate_of ) : null,
			)
		);

		// Step 3: the actor goes onto the stack and becomes the old user. The
		// cookies persist only when the actor's own sign-in does (SW-7).
		BLT_Toolkit_Switch_Cookies::push_user( $actor_id, $old_token, (bool) $remember );

		// Step 4: the actor's own session records whom they switched into.
		self::update_session(
			$actor_id,
			$old_token,
			array(
				self::INTO => (int) $target->ID,
				self::AT   => time(),
			),
			array()
		);

		// Steps 4 and 5: the target's new session records who switched in, and
		// its token is caught as core sets the cookie. The session ends with
		// the switch cookies (48 hours) even when "Remember Me" would keep it
		// for two weeks, so it never outlives the way back.
		$verifier  = self::verifier( $old_token );
		$new_token = '';

		$attach = static function ( $session ) use ( $actor_id, $verifier, $remember ) {
			$session                       = is_array( $session ) ? $session : array();
			$session[ self::FROM_ID ]      = (int) $actor_id;
			$session[ self::FROM_SESSION ] = $verifier;
			$session[ self::REMEMBER ]     = (bool) $remember;

			return $session;
		};

		$catch = static function ( $cookie, $expire, $expiration, $user_id, $scheme, $token ) use ( &$new_token ) {
			$new_token = (string) $token;
		};

		$cap = static function ( $length ) {
			return min( (int) $length, BLT_Toolkit_Switch_Cookies::LIFETIME );
		};

		add_filter( 'attach_session_information', $attach, 99 );
		add_action( 'set_logged_in_cookie', $catch, 10, 6 );
		add_filter( 'auth_cookie_expiration', $cap, PHP_INT_MAX );

		wp_clear_auth_cookie();
		wp_set_auth_cookie( (int) $target->ID, (bool) $remember, '', '' );
		wp_set_current_user( (int) $target->ID );

		remove_filter( 'attach_session_information', $attach, 99 );
		remove_action( 'set_logged_in_cookie', $catch, 10 );
		remove_filter( 'auth_cookie_expiration', $cap, PHP_INT_MAX );

		self::$token = array( (int) $target->ID, $new_token );
		self::forget_state();

		// Step 6.
		/**
		 * Fires after the current user switched to another user (SW-4): the
		 * cookies are set and the current user is the target.
		 *
		 * @param int    $target_id The user switched to.
		 * @param int    $actor_id  The user who switched.
		 * @param string $new_token Session token of the target's new session.
		 * @param string $old_token Session token of the actor's session, which switching back returns to.
		 */
		do_action( 'blt_toolkit_switched_to', (int) $target->ID, (int) $actor_id, $new_token, $old_token );

		if ( self::compat_hooks() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- SW-19 compat: User Switching's action name and signature.
			do_action( 'switch_to_user', (int) $target->ID, (int) $actor_id, $new_token, $old_token );
		}

		return array(
			'new_token' => $new_token,
			'old_token' => $old_token,
		);
	}

	/**
	 * Switch back to the originating user (SW-5). The caller has validated
	 * both cookies and the nonce.
	 *
	 * The original session token comes from the newest stack entry, so the
	 * person returns to the very session they switched from. The session made
	 * for the target is destroyed, and `blt_switched_into` is cleared from the
	 * restored one. Remember-me follows the current cookie (SW-7); after a
	 * switch off there is none, so it follows the restored session instead.
	 *
	 * @param WP_User $original The user to switch back to.
	 * @return array{new_token:string,old_token:string}
	 */
	private static function switch_back( WP_User $original ) {
		$from_id   = is_user_logged_in() ? get_current_user_id() : 0;
		$old_token = $from_id > 0 ? (string) wp_get_session_token() : '';
		$read      = BLT_Toolkit_Switch_Cookies::read_stack();
		$newest    = self::newest( $read['stack'] );
		$parts     = '' !== $newest ? wp_parse_auth_cookie( $newest, $read['scheme'] ) : false;
		$new_token = is_array( $parts ) && isset( $parts['token'] ) ? (string) $parts['token'] : '';
		$manager   = WP_Session_Tokens::get_instance( (int) $original->ID );
		$session   = '' !== $new_token ? $manager->get( $new_token ) : null;

		if ( $from_id > 0 ) {
			$remember = self::remember();
		} elseif ( is_array( $session ) && isset( $session[ self::REMEMBER ] ) ) {
			// A switched session switched off from carries its own choice.
			$remember = (bool) $session[ self::REMEMBER ];
		} else {
			$remember = is_array( $session ) && isset( $session['expiration'] ) && BLT_Toolkit_Switch_Cookies::is_persistent( (int) $session['expiration'], time(), self::default_life( (int) $original->ID ) );
		}

		self::log(
			'switched_back',
			$original,
			/* translators: %s: display name of the user switched back to. */
			sprintf( __( 'Switched back to %s.', 'blt-toolkit' ), $original->display_name ),
			array(
				'from'             => $from_id > 0 ? self::person( $from_id ) : null,
				'to'               => self::person( (int) $original->ID ),
				'session_restored' => is_array( $session ),
				'remember'         => (bool) $remember,
			)
		);

		BLT_Toolkit_Switch_Cookies::pop_user( (bool) $remember );

		wp_clear_auth_cookie();
		wp_set_auth_cookie( (int) $original->ID, (bool) $remember, '', $new_token );
		wp_set_current_user( (int) $original->ID );

		self::$token = array( (int) $original->ID, $new_token );
		self::forget_state();

		// The restored session is no longer switched into anyone.
		self::update_session( (int) $original->ID, $new_token, array(), array( self::INTO, self::AT ) );

		/**
		 * Fires after a user switched back to the account they came from
		 * (SW-5): the stack is popped and the original session restored.
		 *
		 * @param int       $user_id   The user switched back to.
		 * @param int|false $from_id   The user switched back from, or false after a switch off.
		 * @param string    $new_token Session token of the restored session.
		 * @param string    $old_token Session token of the session being left ('' after a switch off).
		 */
		do_action( 'blt_toolkit_switched_back', (int) $original->ID, $from_id > 0 ? $from_id : false, $new_token, $old_token );

		if ( self::compat_hooks() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- SW-19 compat: User Switching's action name and signature.
			do_action( 'switch_back_user', (int) $original->ID, $from_id > 0 ? $from_id : false, $new_token, $old_token );
		}

		// The session that was made for the target ends here.
		if ( $from_id > 0 && '' !== $old_token ) {
			WP_Session_Tokens::get_instance( $from_id )->destroy( $old_token );
		}

		return array(
			'new_token' => $new_token,
			'old_token' => $old_token,
		);
	}

	/**
	 * Switch off (SW-6): push the current user onto the stack, clear the
	 * auth cookies and sign out for this request. Their session stays, so
	 * switching back returns to it. The caller has checked permission and
	 * the nonce.
	 *
	 * @return string The token of the session switched off from.
	 */
	private static function switch_off() {
		$user_id = get_current_user_id();
		$token   = (string) wp_get_session_token();

		if ( '' === $token ) {
			$token = WP_Session_Tokens::get_instance( $user_id )->create( time() + BLT_Toolkit_Switch_Cookies::LIFETIME );
		}

		$remember = self::remember();

		self::log(
			'switched_off',
			wp_get_current_user(),
			__( 'Switched off.', 'blt-toolkit' ),
			array( 'from' => self::person( $user_id ) )
		);

		BLT_Toolkit_Switch_Cookies::push_user( $user_id, $token, $remember );

		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		self::$token = null;
		self::forget_state();

		/**
		 * Fires after a user switched off (SW-6): they are on the switch stack
		 * and signed out.
		 *
		 * @param int    $user_id The user who switched off.
		 * @param string $token   Session token of the session they switched off from.
		 */
		do_action( 'blt_toolkit_switched_off', (int) $user_id, $token );

		if ( self::compat_hooks() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- SW-19 compat: User Switching's action name and signature.
			do_action( 'switch_off_user', (int) $user_id, $token );
		}

		return $token;
	}

	// ---------- Duplicate switch (SW-8, SW-23) ----------

	/**
	 * Someone other than the actor who is switched into the target right
	 * now (SW-8): the first of the target's live sessions that carries
	 * `blt_switched_from_id` for another existing user and still leads back
	 * to the person who signed in (live_switch_from()).
	 *
	 * @param WP_User $target The user to switch to.
	 * @param WP_User $actor  The user switching.
	 * @return array{user:WP_User,login:int}|null
	 */
	public static function duplicate_switch( WP_User $target, WP_User $actor ) {
		$sessions = WP_Session_Tokens::get_instance( (int) $target->ID )->get_all();

		foreach ( $sessions as $session ) {
			$from_id = self::live_switch_from( $session, (int) $actor->ID, array( __CLASS__, 'session_by_verifier' ) );

			if ( $from_id <= 0 ) {
				continue;
			}

			$from = get_userdata( $from_id );

			if ( $from instanceof WP_User ) {
				return array(
					'user'  => $from,
					'login' => isset( $session['login'] ) ? (int) $session['login'] : time(),
				);
			}
		}

		return null;
	}

	/**
	 * Who switched into a session, when that is someone other than the
	 * actor and the switch is still on (SW-8). Pure: sessions are found
	 * through `$lookup`.
	 *
	 * A switch is on while its links lead back to the person who signed in
	 * (follow_links()). Once that session ends (a sign-in or sign-out over
	 * the switched browser, which ends the sessions the switch could return
	 * to, "Log out everywhere", expiry), the switched session is over too,
	 * even before end_orphaned_session() destroys it on its next request,
	 * so it never raises the duplicate-switch warning.
	 *
	 * @param mixed    $session  One of the target's sessions.
	 * @param int      $actor_id The user switching.
	 * @param callable $lookup   function( int $user_id, string $verifier ): array|null, as follow_links().
	 * @return int The user who switched in, or 0.
	 */
	public static function live_switch_from( $session, $actor_id, callable $lookup ) {
		$from_id = is_array( $session ) && isset( $session[ self::FROM_ID ] ) && is_numeric( $session[ self::FROM_ID ] ) ? (int) $session[ self::FROM_ID ] : 0;

		if ( $from_id <= 0 || (int) $actor_id === $from_id ) {
			return 0;
		}

		return self::follow_links( $session, $lookup )['intact'] ? $from_id : 0;
	}

	/**
	 * The 409 confirmation page for a duplicate switch (SW-8). Stops.
	 *
	 * @param array   $duplicate From duplicate_switch().
	 * @param WP_User $target    The user to switch to.
	 * @return void
	 */
	private static function duplicate_page( array $duplicate, WP_User $target ) {
		// SW-23: keep Query Monitor from printing a stack trace for wp_die().
		do_action( 'qm/cease' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Query Monitor's own action.

		$message = sprintf(
			/* translators: 1: name of the user who is switched in, 2: name of the user to switch to, 3: how long ago, such as "5 mins". */
			__( '%1$s is currently switched to %2$s. They switched %3$s ago. Continue?', 'blt-toolkit' ),
			$duplicate['user']->display_name,
			$target->display_name,
			human_time_diff( (int) $duplicate['login'] )
		);

		/* translators: %s: name of the user to switch to. */
		$yes  = sprintf( __( 'Yes, switch to %s', 'blt-toolkit' ), $target->display_name );
		$back = wp_get_referer();

		if ( ! $back ) {
			$back = current_user_can( 'list_users' ) ? admin_url( 'users.php' ) : admin_url();
		}

		wp_die(
			sprintf(
				'<p>%1$s</p><p><a class="button button-primary" href="%2$s">%3$s</a> <a class="button" href="%4$s">%5$s</a></p>',
				esc_html( $message ),
				esc_url( add_query_arg( 'force', '1' ) ),
				esc_html( $yes ),
				esc_url( $back ),
				esc_html__( 'No, go back', 'blt-toolkit' )
			),
			esc_html__( 'Someone is already switched in', 'blt-toolkit' ),
			array(
				'response'  => 409,
				'back_link' => false,
			)
		);
	}

	// ---------- Sessions ----------

	/**
	 * Add and remove keys on one of a user's sessions, through
	 * WP_Session_Tokens::update(). Does nothing when the session is gone.
	 *
	 * @param int      $user_id User ID.
	 * @param string   $token   Session token.
	 * @param array    $set     Keys to set.
	 * @param string[] $remove  Keys to remove.
	 * @return bool Whether the session was updated.
	 */
	private static function update_session( $user_id, $token, array $set, array $remove ) {
		if ( (int) $user_id <= 0 || '' === (string) $token ) {
			return false;
		}

		$manager = WP_Session_Tokens::get_instance( (int) $user_id );
		$session = $manager->get( (string) $token );

		if ( ! is_array( $session ) ) {
			return false;
		}

		foreach ( $remove as $key ) {
			unset( $session[ $key ] );
		}

		$manager->update( (string) $token, array_merge( $session, $set ) );

		return true;
	}

	/**
	 * A session token's verifier: the key core stores the session under in
	 * `session_tokens` (WP_User_Meta_Session_Tokens). The target's session
	 * keeps this, never the token itself, so the user meta holds nothing
	 * that could stand in for the actor's cookie.
	 *
	 * @param string $token Session token.
	 * @return string
	 */
	private static function verifier( $token ) {
		return '' === (string) $token ? '' : hash( 'sha256', (string) $token );
	}

	// ---------- SW-10 ----------

	/**
	 * End switching in this browser. Hooked on `wp_login` and `wp_logout`: a
	 * normal sign-in or sign-out ends any way back (SW-10), so every session
	 * the switch cookies could return to is destroyed, then the cookies are
	 * cleared (BLT_Toolkit_Switch_Cookies::end_sessions()). Also hooked on
	 * BLT M365 WP SSO's `blt_m365_sso_user_authenticated`, which is its
	 * sign-in and does not fire `wp_login`.
	 *
	 * @return void
	 */
	public static function clear_cookies() {
		BLT_Toolkit_Switch_Cookies::end_sessions();
		self::forget_state();
	}

	/**
	 * Sign out a switched session whose origin ended. `determine_current_user`
	 * callback, priority 99 (after core's cookie checks).
	 *
	 * A session made by a switch carries the verifier of the session it was
	 * switched from. When that session, or any session further up the
	 * chain, is gone (signed out, "Log out everywhere", a password reset,
	 * expired, the user deleted), the switched session is destroyed and the
	 * request is treated as signed out: impersonation never outlives the
	 * person who started it, and someone whose own sessions were ended
	 * cannot keep working as another user.
	 *
	 * Reads only the current user's sessions, which core has just loaded to
	 * validate the cookie, unless the session was switched into (SEC-9).
	 * Nothing here may ask for the current user: this runs while WordPress
	 * is still deciding who that is.
	 *
	 * @param int|false $user_id The user core authenticated, or false.
	 * @return int|false
	 */
	public static function end_orphaned_session( $user_id ) {
		$id = is_int( $user_id ) || ( is_string( $user_id ) && ctype_digit( $user_id ) ) ? (int) $user_id : 0;

		if ( $id <= 0 ) {
			return $user_id;
		}

		$user = get_userdata( $id );

		if ( ! $user instanceof WP_User ) {
			return $user_id;
		}

		// The logged-in cookie, else the auth cookie of the request's scheme:
		// whichever names this user carries the session token.
		$token = '';

		foreach ( array( 'logged_in', '' ) as $scheme ) {
			$cookie = wp_parse_auth_cookie( '', $scheme );

			if ( is_array( $cookie ) && ! empty( $cookie['token'] ) && isset( $cookie['username'] ) && (string) $cookie['username'] === (string) $user->user_login ) {
				$token = (string) $cookie['token'];
				break;
			}
		}

		if ( '' === $token ) {
			return $user_id;
		}

		$manager = WP_Session_Tokens::get_instance( $id );
		$session = $manager->get( $token );

		if ( ! is_array( $session ) || empty( $session[ self::FROM_ID ] ) ) {
			return $user_id;
		}

		$links = self::follow_links( $session, array( __CLASS__, 'session_by_verifier' ) );

		if ( $links['intact'] ) {
			return $user_id;
		}

		$manager->destroy( $token );

		return false;
	}

	/**
	 * Drop the logged-in cookie from `$_COOKIE` when core clears the auth
	 * cookies (SW-10). Core only sends the deleting headers, so later code in
	 * the same request would still read a dead session token from it, and a
	 * nonce made with that token would fail on the next request.
	 *
	 * @return void
	 */
	public static function forget_logged_in_cookie() {
		if ( defined( 'LOGGED_IN_COOKIE' ) ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		}
	}

	// ---------- SW-24 ----------

	/**
	 * Suggested privacy policy text about the switch cookies (SW-24).
	 * Hooked on `admin_init`, where core expects it.
	 *
	 * @return void
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = '<p>' . esc_html__( 'When a user with permission switches to another user\'s account, BLT Toolkit sets cookies that let them switch back. Like the cookies WordPress sets when you log in, they contain the username of the user who switched, an expiration time and a secure hash. They expire after two days at most, when the browser is closed unless that user chose "Remember Me" when logging in, and as soon as anyone logs in or out in that browser.', 'blt-toolkit' ) . '</p>';

		wp_add_privacy_policy_content( 'BLT Toolkit', wp_kses_post( $text ) );
	}

	// ---------- Logging (LOG-2) ----------

	/**
	 * Write one switch row.
	 *
	 * @param string  $action  `switched_to`, `switched_back` or `switched_off`.
	 * @param WP_User $subject The user the row is about.
	 * @param string  $summary One translated line.
	 * @param array   $details Details.
	 * @return void
	 */
	private static function log( $action, WP_User $subject, $summary, array $details ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => self::KEY,
				'action'      => $action,
				'object_type' => 'user',
				'object_id'   => (string) (int) $subject->ID,
				'summary'     => $summary,
				'details'     => array_filter(
					$details,
					static function ( $value ) {
						return null !== $value;
					}
				),
			)
		);
	}

	/**
	 * A user as the log stores them: ID and login, so the row still reads
	 * after the user is renamed or deleted.
	 *
	 * @param int $user_id User ID.
	 * @return array{id:int,login:string}
	 */
	private static function person( $user_id ) {
		$user = $user_id > 0 ? get_userdata( (int) $user_id ) : false;

		return array(
			'id'    => (int) $user_id,
			'login' => $user instanceof WP_User ? (string) $user->user_login : '',
		);
	}
}
