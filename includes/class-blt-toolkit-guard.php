<?php
/**
 * The Guard: every authorization rule in one place.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces SPEC 5.3 through `map_meta_cap`, `editable_roles` and checks
 * inside BLT write paths. It always loads, even with every module off,
 * because protecting administrators must not depend on which tools are on.
 *
 * Every decision is made where WordPress itself asks the question
 * (`map_meta_cap`, `editable_roles`, the user queries), never by looking at
 * `$_GET` on particular screens, so the rules hold the same way for
 * users.php, user-edit.php, admin-ajax.php, admin-post.php, REST and WP-CLI
 * (SPEC 19, URE-1).
 *
 * - SEC-1 and SEC-2 for users, SEC-5 for one's own roles and the two meta
 *   capabilities of SPEC 5.1: map_meta_cap().
 * - SEC-4 (assignable roles are subsets): editable_roles(), plus the two
 *   places core gives a role without asking that filter: a new user's
 *   default role (check_new_user_role() and rest_users_guard()), and REST
 *   updates that send an empty `roles` list (rest_users_guard()).
 * - SEC-6 (hide administrators): the Users list query, its role counts and
 *   views, and the REST users collection.
 * - SEC-2, SEC-3, SEC-5, SEC-10, SEC-12 and SEC-13 for BLT write paths:
 *   can_act_on_user(), can_change_user_caps(), can_grant(),
 *   check_role_change(), requires_critical_review(), is_protected_role().
 * - SEC-14 recovery mode: maybe_recover() and recovery_notice(), hooked
 *   only while `BLT_TOOLKIT_RECOVERY` is true.
 *
 * Cost (SEC-9): the `map_meta_cap` callback is a closure that returns at
 * once for every capability outside a six-entry hash set, so
 * `current_user_can( 'read' )` and friends pay one isset() on top of
 * WordPress dispatching the filter. Inside the set, each user's effective
 * capabilities are normalized once per request per (role set + per-user
 * capabilities) fingerprint, and each actor/target comparison is cached by
 * the pair of fingerprints, so a Users list of editors compares once, not
 * once per row.
 *
 * The rule checks for write paths are split into a pure part that returns a
 * "violation" array (unit-tested in tests/test-guard.php) and a thin wrapper
 * that reads WordPress and turns the violation into a WP_Error.
 */
class BLT_Toolkit_Guard {

	/**
	 * Priority of the `map_meta_cap` and `editable_roles` filters: late, so
	 * the Guard's refusals are added after other plugins have mapped or
	 * filtered, and nothing that runs at the usual priorities removes them.
	 */
	const PRIORITY = 9999;

	/**
	 * The role whose holders are toolkit admins (SPEC 5.2) and that can never
	 * be deleted, renamed or stripped of the lockout-critical set (SEC-12).
	 */
	const ADMIN_ROLE = 'administrator';

	/**
	 * User meta holding the time of a user's last username change (UN-21).
	 */
	const CHANGED_AT_META = 'blt_toolkit_username_changed_at';

	/**
	 * Transient that remembers a recovery of roles defined in code was
	 * logged (SEC-14), so it is logged once a day, not on every request.
	 */
	const CODE_RECOVERY_TRANSIENT = 'blt_toolkit_recovery_in_code';

	/**
	 * Capabilities the `map_meta_cap` callback looks at (SEC-9). Everything
	 * else returns before any work.
	 *
	 * @var array<string,true>
	 */
	private static $guarded = array(
		'edit_user'           => true,
		'delete_user'         => true,
		'remove_user'         => true,
		'promote_user'        => true,
		'blt_switch_to_user'  => true,
		'blt_change_username' => true,
	);

	/**
	 * Of the guarded capabilities, those SEC-2 also applies to: a non-admin
	 * may not change the roles of, switch into, rename, edit, delete or
	 * remove a user whose effective capabilities are not a subset of their
	 * own.
	 *
	 * `edit_user` and `delete_user` are here as well as the three SPEC 5.3
	 * names, because core routes more than the profile form through
	 * `edit_user`: the application-password capabilities
	 * (`create_app_password` and the rest), session sign-out and password
	 * reset links all map to it. Without them a delegated user manager could
	 * mint an application password for a more powerful non-admin and sign in
	 * as them, the impersonation SPEC 5.1 and SPEC 19 (US-A) rule out.
	 *
	 * @var array<string,true>
	 */
	private static $subset_checked = array(
		'edit_user'           => true,
		'delete_user'         => true,
		'remove_user'         => true,
		'promote_user'        => true,
		'blt_switch_to_user'  => true,
		'blt_change_username' => true,
	);

	/**
	 * Whether the hooks are registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * The closure registered on `map_meta_cap` (see map_meta_cap_callback()).
	 *
	 * @var Closure|null
	 */
	private static $gate = null;

	/**
	 * Nesting depth of the `map_meta_cap` callback (re-entrancy guard).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Normalized effective capabilities, keyed by user fingerprint (SEC-9).
	 *
	 * @var array<string,array<string,true>>
	 */
	private static $caps_cache = array();

	/**
	 * Capabilities a target holds that an actor lacks, keyed by
	 * "actor fingerprint|target fingerprint" (SEC-9).
	 *
	 * @var array<string,string[]>
	 */
	private static $missing_cache = array();

	/**
	 * Register the Guard's hooks. Called on `plugins_loaded` priority 1.
	 *
	 * @return void
	 */
	public static function register() {
		global $wpdb;

		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		// SEC-1, SEC-2, SEC-5 and the meta capabilities of SPEC 5.1.
		add_filter( 'map_meta_cap', self::map_meta_cap_callback(), self::PRIORITY, 4 );

		// SEC-4.
		add_filter( 'editable_roles', array( __CLASS__, 'editable_roles' ), self::PRIORITY );

		// SEC-4 for a new user's default role (user-new.php and admin-ajax.php
		// add-user go through edit_user(), which fires this), and SEC-2,
		// SEC-4 and SEC-5 on the REST users routes where core asks less than
		// it does for a non-empty `roles` list. Neither runs on a page view.
		add_action( 'user_profile_update_errors', array( __CLASS__, 'check_new_user_role' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_users_guard' ), 10, 3 );

		// SEC-6. The list table query filter fires only inside
		// WP_Users_List_Table; counts and views are adjusted on users.php.
		add_filter( 'users_list_table_query_args', array( __CLASS__, 'exclude_admins_from_query' ) );
		add_filter( 'rest_user_query', array( __CLASS__, 'exclude_admins_from_query' ) );
		add_action( 'load-users.php', array( __CLASS__, 'load_users_screen' ) );

		// Cached comparisons depend on role definitions; drop them whenever
		// the roles option is written.
		$role_key = $wpdb->get_blog_prefix() . 'user_roles';
		add_action( 'add_option_' . $role_key, array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'update_option_' . $role_key, array( __CLASS__, 'flush' ), 10, 0 );

		// SEC-14. Nothing is hooked unless the constant is set. The work runs
		// on `init`, not `admin_init`: wp-admin refuses a user who cannot
		// reach the requested screen (wp-admin/includes/menu.php) before
		// `admin_init` fires, so an administrator role that lost `read` would
		// never get there.
		if ( self::recovery_requested() ) {
			add_action( 'init', array( __CLASS__, 'maybe_recover' ), 20 );
			add_action( 'admin_notices', array( __CLASS__, 'recovery_notice' ) );
		}
	}

	/**
	 * Whether a user counts as an administrator for BLT Toolkit (SPEC 5.2).
	 *
	 * True when the user has the `administrator` role on this site. It checks
	 * role membership, not capabilities, so it still answers correctly after
	 * the administrator role's capabilities were damaged (SEC-14 relies on
	 * this). Filterable through `blt_toolkit_is_toolkit_admin`.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_toolkit_admin( WP_User $user ) {
		$is_admin = $user->exists() && in_array( self::ADMIN_ROLE, (array) $user->roles, true );

		/**
		 * Filter whether a user counts as a toolkit administrator.
		 *
		 * @param bool    $is_admin Whether the user has the administrator role on this site.
		 * @param WP_User $user     The user.
		 */
		return (bool) apply_filters( 'blt_toolkit_is_toolkit_admin', $is_admin, $user );
	}

	// ---------- map_meta_cap (SEC-1, SEC-2, SEC-5, SPEC 5.1) ----------

	/**
	 * The callback registered on `map_meta_cap`: a closure that checks the
	 * guarded set itself and calls map_meta_cap() only for those six
	 * capabilities (SEC-9).
	 *
	 * Every current_user_can() call runs the `map_meta_cap` filter, so the
	 * callback's own cost is paid on every capability check on the site.
	 * Measured on PHP 8.3 (10,000 `current_user_can( 'read' )` calls), a
	 * closure costs what an empty closure at the same priority costs, while
	 * a static-method callable costs about 0.2 microseconds more per call,
	 * because WordPress dispatches it through call_user_func_array() with a
	 * class lookup. Pass this to remove_filter() to take the Guard off.
	 *
	 * @return Closure
	 */
	public static function map_meta_cap_callback() {
		if ( null === self::$gate ) {
			$guarded = self::$guarded;

			self::$gate = static function ( $caps, $cap, $user_id, $args ) use ( $guarded ) {
				if ( ! is_string( $cap ) || ! isset( $guarded[ $cap ] ) ) {
					return $caps;
				}

				return BLT_Toolkit_Guard::map_meta_cap( $caps, $cap, $user_id, $args );
			};
		}

		return self::$gate;
	}

	/**
	 * `map_meta_cap` callback.
	 *
	 * Returns at once for any capability outside the guarded set (SEC-9).
	 * Inside it:
	 *
	 * - `blt_switch_to_user` maps to `blt_switch_users`; `do_not_allow` when
	 *   the target is the user being checked or does not exist (SPEC 5.1,
	 *   SW-1).
	 * - `blt_change_username` maps, for oneself, to `blt_change_own_username`
	 *   plus the cooldown (UN-21), and for anyone else to
	 *   `blt_change_usernames` (UN-10 step 1). A target that does not exist
	 *   is `do_not_allow`.
	 * - SEC-1: a non-admin gets `do_not_allow` for every guarded capability
	 *   on a toolkit admin, and for any guarded check whose target argument
	 *   is not a user ID at all (`'1abc'`, `true`, an array, a negative
	 *   number): PHP's casts turn such values into a real ID, so a caller
	 *   that checks the raw value and then acts on `absint()` of it would
	 *   otherwise reach an administrator.
	 * - SEC-2: a non-admin gets `do_not_allow` for `edit_user`,
	 *   `delete_user`, `remove_user`, `promote_user`, `blt_switch_to_user`
	 *   and `blt_change_username` on a user whose effective capabilities are
	 *   not a subset of theirs. Editing a user's own capabilities is checked
	 *   by can_change_user_caps() in that write path.
	 * - SEC-5: a non-admin gets `do_not_allow` for `promote_user` on
	 *   themselves, so they cannot add or remove their own roles anywhere
	 *   core asks (profile form, Users bulk action, REST).
	 *
	 * @param string[] $caps    Primitive capabilities core mapped to.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User being checked (the actor).
	 * @param array    $args    Extra arguments; the target user ID first.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! is_string( $cap ) || ! isset( self::$guarded[ $cap ] ) ) {
			return $caps;
		}

		$caps = (array) $caps;

		// A capability check made while this callback is already working
		// (only possible through a third-party filter it runs) is refused
		// rather than recursing.
		if ( self::$depth > 0 ) {
			$caps[] = 'do_not_allow';

			return $caps;
		}

		++self::$depth;

		try {
			$caps = self::map_guarded( $caps, $cap, (int) $user_id, (array) $args );
		} finally {
			--self::$depth;
		}

		return $caps;
	}

	/**
	 * The guarded part of map_meta_cap().
	 *
	 * @param string[] $caps    Primitive capabilities core mapped to.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id Actor ID.
	 * @param array    $args    Extra arguments.
	 * @return string[]
	 */
	private static function map_guarded( array $caps, $cap, $user_id, array $args ) {
		$target_id = self::target_id( $args );
		$is_meta   = 'blt_switch_to_user' === $cap || 'blt_change_username' === $cap;

		if ( $is_meta ) {
			if ( $user_id <= 0 || $target_id <= 0 ) {
				return array( 'do_not_allow' );
			}

			$target = get_userdata( $target_id );

			if ( ! $target instanceof WP_User || ! $target->exists() ) {
				return array( 'do_not_allow' );
			}

			if ( 'blt_switch_to_user' === $cap ) {
				if ( $target_id === $user_id ) {
					return array( 'do_not_allow' );
				}

				$caps = array( 'blt_switch_users' );
			} elseif ( $target_id === $user_id ) {
				// Self-service: no SEC rule applies to oneself here.
				return self::username_cooldown_until( $user_id ) > time() ? array( 'do_not_allow' ) : array( 'blt_change_own_username' );
			} else {
				$caps = array( 'blt_change_usernames' );
			}
		} elseif ( $user_id <= 0 || 0 === $target_id ) {
			// Nobody to protect, or nobody acting: core's mapping stands.
			return $caps;
		}

		$actor = self::user( $user_id );

		if ( ! $actor instanceof WP_User || ! $actor->exists() ) {
			if ( $is_meta ) {
				return array( 'do_not_allow' );
			}

			return $caps;
		}

		if ( self::is_toolkit_admin( $actor ) ) {
			return $caps;
		}

		// SEC-1, fail closed: a target argument that is not a user ID could
		// still become an administrator's ID through (int) or absint().
		if ( $target_id < 0 ) {
			$caps[] = 'do_not_allow';

			return $caps;
		}

		if ( $target_id === $user_id ) {
			// SEC-5: no adding or removing one's own roles. SEC-1 and SEC-2
			// cannot apply: the actor is the target.
			if ( 'promote_user' === $cap ) {
				$caps[] = 'do_not_allow';
			}

			return $caps;
		}

		if ( ! isset( $target ) ) {
			$target = get_userdata( $target_id );

			if ( ! $target instanceof WP_User || ! $target->exists() ) {
				return $caps;
			}
		}

		// SEC-1.
		if ( self::is_toolkit_admin( $target ) ) {
			$caps[] = 'do_not_allow';

			return $caps;
		}

		// SEC-2.
		if ( isset( self::$subset_checked[ $cap ] ) && array() !== self::missing_caps( $actor, $target ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * The target user ID from a capability check's extra arguments. Pure.
	 *
	 * A WP_User, or a number (an integer, a float or a numeric string), gives
	 * its integer value. Anything else that was passed (`'1abc'`, `true`, an
	 * array, an object) is not a user ID, and PHP's casts would still turn
	 * it into one, so it gives -1, as does a negative number (absint( -1 ) is
	 * 1).
	 *
	 * @param array $args Extra arguments.
	 * @return int The ID; 0 when no target was passed (or it is 0); -1 when
	 *             the argument is not a usable user ID.
	 */
	public static function target_id( array $args ) {
		if ( ! isset( $args[0] ) ) {
			return 0;
		}

		if ( $args[0] instanceof WP_User ) {
			return (int) $args[0]->ID;
		}

		if ( is_float( $args[0] ) && ! is_finite( $args[0] ) ) {
			return -1;
		}

		if ( ( is_int( $args[0] ) || is_float( $args[0] ) || is_string( $args[0] ) ) && is_numeric( $args[0] ) ) {
			$id = (int) $args[0];

			return $id < 0 ? -1 : $id;
		}

		return -1;
	}

	/**
	 * A user by ID, reusing the current user's object when it is them.
	 *
	 * Reads the `current_user` global directly instead of calling
	 * wp_get_current_user(), so a capability check made before `init` never
	 * makes WordPress authenticate the request early.
	 *
	 * @param int $user_id User ID.
	 * @return WP_User|false
	 */
	private static function user( $user_id ) {
		if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof WP_User && (int) $GLOBALS['current_user']->ID === $user_id ) {
			return $GLOBALS['current_user'];
		}

		return get_userdata( $user_id );
	}

	// ---------- Effective capabilities and caching (SEC-8, SEC-9) ----------

	/**
	 * A user's effective capabilities (SEC-8): `WP_User::$allcaps` where the
	 * value grants, without role names or `do_not_allow`.
	 *
	 * Cached per request by the user's role set and per-user capabilities,
	 * so every user with the same roles and no capabilities of their own
	 * shares one entry.
	 *
	 * @param WP_User $user User.
	 * @return array<string,true>
	 */
	public static function effective_caps( WP_User $user ) {
		$key = self::fingerprint( $user );

		if ( ! isset( self::$caps_cache[ $key ] ) ) {
			self::$caps_cache[ $key ] = BLT_Toolkit_Superset::normalize( (array) $user->allcaps, self::role_names() );
		}

		return self::$caps_cache[ $key ];
	}

	/**
	 * Capabilities the target holds that the actor does not (SEC-2, SEC-8),
	 * cached per request by the pair of fingerprints (SEC-9).
	 *
	 * @param WP_User $actor  Actor.
	 * @param WP_User $target Target.
	 * @return string[] Sorted capability names; empty when the target is a subset.
	 */
	public static function missing_caps( WP_User $actor, WP_User $target ) {
		$key = self::fingerprint( $actor ) . '|' . self::fingerprint( $target );

		if ( ! isset( self::$missing_cache[ $key ] ) ) {
			self::$missing_cache[ $key ] = BLT_Toolkit_Superset::missing( self::effective_caps( $actor ), self::effective_caps( $target ) );
		}

		return self::$missing_cache[ $key ];
	}

	/**
	 * Drop every cached comparison. Hooked to writes of the roles option;
	 * call it after changing roles in memory without writing them.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$caps_cache    = array();
		self::$missing_cache = array();
	}

	/**
	 * A user's role set and per-user capabilities, as a short key.
	 *
	 * `WP_User::$caps` is the user's `{prefix}capabilities` meta, which holds
	 * both. Order is kept, because WordPress merges role capabilities in that
	 * order and a later role's `false` overrides an earlier `true`.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function fingerprint( WP_User $user ) {
		$caps = is_array( $user->caps ) ? $user->caps : array();

		return md5( (string) wp_json_encode( $caps ) );
	}

	/**
	 * Names (IDs) of the site's roles.
	 *
	 * @return string[]
	 */
	private static function role_names() {
		return array_map( 'strval', array_keys( (array) wp_roles()->role_names ) );
	}

	// ---------- editable_roles (SEC-4) ----------

	/**
	 * `editable_roles` callback (SEC-4).
	 *
	 * For a non-admin, removes every role whose capabilities are not a subset
	 * of the actor's effective capabilities, and always removes
	 * `administrator`: handing out the role that SEC-1 protects would let a
	 * non-admin create an account that is out of reach of the rules that
	 * apply to them. It only ever removes. Core uses this list for the role
	 * dropdowns, the Users bulk "Change role to", user creation and the REST
	 * users endpoint, so one filter covers them all (URE-3).
	 *
	 * @param array $roles Role ID => array( 'name' => ..., 'capabilities' => ... ).
	 * @return array
	 */
	public static function editable_roles( $roles ) {
		if ( ! is_array( $roles ) || array() === $roles ) {
			return $roles;
		}

		$actor = wp_get_current_user();

		if ( self::is_toolkit_admin( $actor ) ) {
			return $roles;
		}

		if ( ! $actor->exists() ) {
			return array();
		}

		$actor_caps = self::effective_caps( $actor );
		$names      = self::role_names();

		foreach ( $roles as $role => $data ) {
			$role_caps = is_array( $data ) && isset( $data['capabilities'] ) && is_array( $data['capabilities'] ) ? $data['capabilities'] : array();

			if ( self::ADMIN_ROLE === (string) $role || ! BLT_Toolkit_Superset::is_subset( $actor_caps, BLT_Toolkit_Superset::normalize( $role_caps, $names ) ) ) {
				unset( $roles[ $role ] );
			}
		}

		return $roles;
	}

	/**
	 * Whether the current user may give a role to a user (SEC-4): it is in
	 * their filtered `editable_roles`. No role at all (`''`) and a role that
	 * does not exist grant nothing, so they are allowed; a toolkit admin may
	 * give any role.
	 *
	 * @param string $role Role ID.
	 * @return bool
	 */
	public static function may_assign_role( $role ) {
		$role = (string) $role;

		if ( '' === $role || ! wp_roles()->is_role( $role ) ) {
			return true;
		}

		$actor = wp_get_current_user();

		if ( self::is_toolkit_admin( $actor ) ) {
			return true;
		}

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$editable = get_editable_roles();

		return is_array( $editable ) && isset( $editable[ $role ] );
	}

	/**
	 * `user_profile_update_errors` callback: SEC-4 for the role a new user is
	 * created with on user-new.php and through admin-ajax.php `add-user`.
	 *
	 * Core checks `editable_roles` only for a role that was chosen. When the
	 * form sends no role (the creator lacks `promote_users`, so the Role
	 * field is not shown, or the field was left out of the request), core
	 * gives the site's default role without asking, so a non-admin could
	 * create, and sign in as, an account more powerful than their own. A
	 * profile update (`$update` true) is left to core and map_meta_cap().
	 *
	 * @param WP_Error $errors Errors so far; refusing adds one.
	 * @param bool     $update Whether an existing user is being updated.
	 * @param object   $user   The user data edit_user() is about to save.
	 * @return void
	 */
	public static function check_new_user_role( $errors, $update, $user ) {
		if ( $update || ! $errors instanceof WP_Error || ! is_object( $user ) ) {
			return;
		}

		$role = isset( $user->role ) && is_scalar( $user->role ) ? (string) $user->role : (string) get_option( 'default_role' );

		if ( ! self::may_assign_role( $role ) ) {
			$errors->add( 'blt_toolkit_role_not_assignable', self::role_refusal( $role ) );
		}
	}

	/**
	 * `rest_request_before_callbacks` callback: the Guard's rules on the two
	 * REST users requests where core asks less than it does elsewhere.
	 *
	 * - Update (`POST /wp/v2/users/{id}` and `/users/me`) with `roles` sent
	 *   as an empty list: core removes every role, but asks for
	 *   `promote_user` only when the list is not empty, so `edit_user` would
	 *   be enough. Here it needs `promote_user` too, so SEC-2 and SEC-5
	 *   (no removing your own roles) hold.
	 * - Create (`POST /wp/v2/users`) without `roles`: core gives the default
	 *   role without asking `editable_roles`. Here a non-admin may do that
	 *   only when they could give that role (SEC-4).
	 *
	 * The controller method the route resolved to is checked, not the URL,
	 * so every spelling of the route is covered.
	 *
	 * @param mixed           $response Null, or an earlier result (a WP_Error stops the request).
	 * @param array           $handler  The matched route handler.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed The response unchanged, or a WP_Error.
	 */
	public static function rest_users_guard( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request || ! is_array( $handler ) || ! isset( $handler['callback'] ) || ! is_array( $handler['callback'] ) ) {
			return $response;
		}

		$callback = $handler['callback'];

		if ( ! isset( $callback[0], $callback[1] ) || ! $callback[0] instanceof WP_REST_Users_Controller || ! is_string( $callback[1] ) ) {
			return $response;
		}

		$method = $callback[1];

		if ( 'update_item' === $method || 'update_current_item' === $method ) {
			if ( ! isset( $request['roles'] ) || ! empty( $request['roles'] ) ) {
				return $response;
			}

			$target_id = 'update_current_item' === $method ? get_current_user_id() : (int) $request['id'];

			if ( $target_id <= 0 || ! get_userdata( $target_id ) instanceof WP_User || current_user_can( 'promote_user', $target_id ) ) {
				return $response;
			}

			return new WP_Error(
				'rest_cannot_edit_roles',
				__( 'Sorry, you are not allowed to edit roles of this user.', 'blt-toolkit' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( 'create_item' === $method && ! isset( $request['roles'] ) && current_user_can( 'create_users' ) ) {
			$role = (string) get_option( 'default_role' );

			if ( ! self::may_assign_role( $role ) ) {
				return new WP_Error(
					'rest_user_invalid_role',
					self::role_refusal( $role ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return $response;
	}

	/**
	 * Why a new user cannot get a role, for a person.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	private static function role_refusal( $role ) {
		$names = wp_roles()->role_names;
		$name  = isset( $names[ $role ] ) ? translate_user_role( (string) $names[ $role ] ) : (string) $role;

		return sprintf(
			/* translators: %s: role name, such as Author. */
			__( 'New users would get the role %s, which has capabilities you do not have, so you cannot create this user. Ask an administrator.', 'blt-toolkit' ),
			$name
		);
	}

	// ---------- Hiding administrators (SEC-6) ----------

	/**
	 * Whether administrators are hidden from this user (SEC-6).
	 *
	 * Never for a toolkit admin. For anyone else, the setting
	 * `roles.hide_admins`, filtered by
	 * `blt_toolkit_hide_admins_from_non_admins`.
	 *
	 * @param WP_User $user The user who is looking.
	 * @return bool
	 */
	public static function hides_admins_from( WP_User $user ) {
		if ( self::is_toolkit_admin( $user ) ) {
			return false;
		}

		$hide = (bool) BLT_Toolkit_Settings::get( 'roles', 'hide_admins' );

		/**
		 * Filter whether administrators are hidden from a non-administrator.
		 *
		 * @param bool    $hide Whether to hide them (Settings > Roles, default on).
		 * @param WP_User $user The user who is looking (never a toolkit admin).
		 */
		return (bool) apply_filters( 'blt_toolkit_hide_admins_from_non_admins', $hide, $user );
	}

	/**
	 * Leave holders of the administrator role out of a user query: the Users
	 * list table (`users_list_table_query_args`) and the REST users
	 * collection (`rest_user_query`). It adds a `role__not_in` clause to the
	 * query that runs anyway, so there is no extra query (SEC-6, SEC-9).
	 *
	 * @param array $args WP_User_Query arguments.
	 * @return array
	 */
	public static function exclude_admins_from_query( $args ) {
		if ( ! is_array( $args ) || ! self::hides_admins_from( wp_get_current_user() ) ) {
			return $args;
		}

		$not_in   = isset( $args['role__not_in'] ) ? (array) $args['role__not_in'] : array();
		$not_in[] = self::ADMIN_ROLE;

		$args['role__not_in'] = array_values( array_unique( $not_in ) );

		return $args;
	}

	/**
	 * On users.php, when administrators are hidden from the current user,
	 * correct the role counts and drop the Administrator view (SEC-6).
	 *
	 * @return void
	 */
	public static function load_users_screen() {
		if ( ! self::hides_admins_from( wp_get_current_user() ) ) {
			return;
		}

		add_filter( 'pre_count_users', array( __CLASS__, 'count_users_without_admins' ), 10, 3 );
		add_filter( 'views_users', array( __CLASS__, 'drop_admin_view' ) );
	}

	/**
	 * `pre_count_users` callback on users.php for a user who does not see
	 * administrators: core's own counting query with administrators left
	 * out, run in its place, so the views show the counts of the users this
	 * person can see, at no extra query.
	 *
	 * The administrator count comes back as zero, so core leaves the
	 * Administrator view out on its own; drop_admin_view() covers sites
	 * large enough that core skips counting. A user holding the
	 * administrator role and another one is left out of the other role's
	 * count too, exactly as the list leaves them out.
	 *
	 * @param array|null $result   A result another filter already supplied, or null.
	 * @param string     $strategy `time` or `memory`.
	 * @param int        $site_id  Site ID.
	 * @return array|null Null lets core count.
	 */
	public static function count_users_without_admins( $result, $strategy, $site_id ) {
		global $wpdb;

		if ( null !== $result || 'time' !== $strategy ) {
			return $result;
		}

		// Administrators are excluded by the WHERE clause, so their column
		// would always be zero.
		$names = array_values( array_diff( self::role_names(), array( self::ADMIN_ROLE ) ) );

		if ( array() === $names ) {
			return $result;
		}

		$select = array();

		foreach ( $names as $name ) {
			$select[] = $wpdb->prepare( 'COUNT(NULLIF(`meta_value` LIKE %s, false))', '%' . $wpdb->esc_like( '"' . $name . '"' ) . '%' );
		}

		$select[] = "COUNT(NULLIF(`meta_value` = 'a:0:{}', false))";

		$where = $wpdb->prepare(
			'meta_key = %s AND meta_value NOT LIKE %s',
			$wpdb->get_blog_prefix( (int) $site_id ) . 'capabilities',
			'%' . $wpdb->esc_like( '"' . self::ADMIN_ROLE . '"' ) . '%'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Replaces core's count_users() query on users.php; every value was prepared above, as core does.
		$row = $wpdb->get_row( 'SELECT ' . implode( ', ', $select ) . ", COUNT(*) FROM {$wpdb->usermeta} INNER JOIN {$wpdb->users} ON user_id = ID WHERE {$where}", ARRAY_N );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$col    = 0;
		$counts = array();

		foreach ( $names as $name ) {
			$count = (int) $row[ $col++ ];

			if ( $count > 0 ) {
				$counts[ $name ] = $count;
			}
		}

		$counts['none'] = (int) $row[ $col++ ];

		return array(
			'total_users' => (int) $row[ $col ],
			'avail_roles' => $counts,
		);
	}

	/**
	 * `views_users` callback: no Administrator view for a user who does not
	 * see administrators (SEC-6).
	 *
	 * @param array $views View key => link HTML.
	 * @return array
	 */
	public static function drop_admin_view( $views ) {
		if ( is_array( $views ) ) {
			unset( $views[ self::ADMIN_ROLE ] );
		}

		return $views;
	}

	// ---------- Checks for BLT write paths ----------

	/**
	 * Whether the actor may act on the target user: switch into them or
	 * rename them (SEC-1, SEC-2).
	 *
	 * Acting on oneself passes here; those paths have their own self rules
	 * (no switching to yourself, the username cooldown). A per-user
	 * capability edit must call can_change_user_caps() instead, which also
	 * refuses a non-admin's own capabilities (SEC-5) and sees removals, and a
	 * role change goes through `promote_user` and `editable_roles`.
	 *
	 * @param WP_User $actor  Who is acting.
	 * @param WP_User $target Who is acted on.
	 * @return true|WP_Error `blt_toolkit_no_user`, `blt_toolkit_no_actor`,
	 *                       `blt_toolkit_protected_admin` (SEC-1) or
	 *                       `blt_toolkit_target_exceeds_actor` (SEC-2, data
	 *                       `caps` lists what the target holds and the actor
	 *                       lacks).
	 */
	public static function can_act_on_user( WP_User $actor, WP_User $target ) {
		$actor_is_admin  = $actor->exists() && self::is_toolkit_admin( $actor );
		$target_is_admin = $target->exists() && self::is_toolkit_admin( $target );
		$compare         = $actor->exists() && $target->exists() && ! $actor_is_admin && ! $target_is_admin && (int) $actor->ID !== (int) $target->ID;

		$violation = self::user_action_violation(
			array(
				'actor_exists'    => $actor->exists(),
				'target_exists'   => $target->exists(),
				'actor_is_admin'  => $actor_is_admin,
				'target_is_admin' => $target_is_admin,
				'same_user'       => (int) $actor->ID === (int) $target->ID,
				'missing'         => $compare ? self::missing_caps( $actor, $target ) : array(),
			)
		);

		return null === $violation ? true : self::error( $violation );
	}

	/**
	 * Whether the actor may set these capabilities on a role or a user
	 * (SEC-3).
	 *
	 * A toolkit admin may set anything. Anyone else may grant or deny only
	 * capabilities they hold, and never a key named like a role, whatever
	 * its value: stored among a user's capabilities, a role name is a role
	 * membership even when its value is `false` (`WP_User::get_role_caps()`
	 * reads every key that names a role).
	 *
	 * For a per-user capability edit use can_change_user_caps(), which also
	 * applies SEC-1, SEC-2 and SEC-5 and sees removals.
	 *
	 * @param WP_User $actor Who is granting.
	 * @param array   $caps  Capability names (granted), or capability => bool
	 *                       (`true` grants, `false` denies).
	 * @return true|WP_Error `blt_toolkit_no_actor`,
	 *                       `blt_toolkit_role_name_cap`,
	 *                       `blt_toolkit_grant_exceeds_actor` or
	 *                       `blt_toolkit_deny_exceeds_actor` (data `caps`).
	 */
	public static function can_grant( WP_User $actor, array $caps ) {
		if ( ! $actor->exists() ) {
			return self::error( self::violation( 'blt_toolkit_no_actor', '' ) );
		}

		$changes = self::cap_changes( $caps, array() );

		$violation = self::grant_violation(
			array(
				'actor_is_admin' => self::is_toolkit_admin( $actor ),
				'actor_caps'     => self::effective_caps( $actor ),
				'grants'         => $changes['grants'],
				'denies'         => $changes['denies'],
				'role_names'     => self::role_names(),
			)
		);

		return null === $violation ? true : self::error( $violation );
	}

	/**
	 * Whether the actor may make this change to a user's own capabilities
	 * (US-10): the check every per-user capability write path calls.
	 *
	 * A toolkit admin may make any change. For anyone else:
	 *
	 * - SEC-1: not on a toolkit admin.
	 * - SEC-5: not on themselves.
	 * - SEC-2: not on a user whose effective capabilities exceed theirs.
	 * - No key named like a role, as a grant, a deny or a removal: in the
	 *   `{prefix}capabilities` meta that key is a role membership, whatever
	 *   its value, and role changes go through `promote_user` and
	 *   `editable_roles` (SEC-4) instead.
	 * - SEC-3: only capabilities they hold may be granted or denied, and
	 *   removing a capability the user is denied counts as granting it,
	 *   because the user may hold it again through a role.
	 *
	 * @param WP_User $actor   Who is saving.
	 * @param WP_User $target  Whose capabilities change.
	 * @param array   $adds    Capability => bool (`true` grants, `false`
	 *                         denies), or names (granted).
	 * @param array   $removes Capability names taken out of the user's
	 *                         capabilities (back to "inherit").
	 * @return true|WP_Error `blt_toolkit_no_user`, `blt_toolkit_no_actor`,
	 *                       `blt_toolkit_protected_admin`,
	 *                       `blt_toolkit_own_caps`,
	 *                       `blt_toolkit_target_exceeds_actor`,
	 *                       `blt_toolkit_role_name_cap`,
	 *                       `blt_toolkit_grant_exceeds_actor` or
	 *                       `blt_toolkit_deny_exceeds_actor` (data `caps`).
	 */
	public static function can_change_user_caps( WP_User $actor, WP_User $target, array $adds, array $removes ) {
		$actor_is_admin  = $actor->exists() && self::is_toolkit_admin( $actor );
		$target_is_admin = $target->exists() && self::is_toolkit_admin( $target );
		$same            = (int) $actor->ID === (int) $target->ID;
		$compare         = $actor->exists() && $target->exists() && ! $actor_is_admin && ! $target_is_admin && ! $same;
		$changes         = self::cap_changes( $adds, $removes );
		$denied          = array();

		foreach ( is_array( $target->caps ) ? $target->caps : array() as $cap => $value ) {
			if ( empty( $value ) ) {
				$denied[] = (string) $cap;
			}
		}

		$violation = self::user_caps_violation(
			array(
				'actor_exists'    => $actor->exists(),
				'target_exists'   => $target->exists(),
				'actor_is_admin'  => $actor_is_admin,
				'target_is_admin' => $target_is_admin,
				'same_user'       => $same,
				'missing'         => $compare ? self::missing_caps( $actor, $target ) : array(),
				'actor_caps'      => $compare ? self::effective_caps( $actor ) : array(),
				'grants'          => $changes['grants'],
				'denies'          => $changes['denies'],
				'removes'         => $changes['removes'],
				'target_denied'   => $denied,
				'role_names'      => self::role_names(),
			)
		);

		return null === $violation ? true : self::error( $violation );
	}

	/**
	 * Whether the actor may make this change to a role (RO-4).
	 *
	 * - SEC-12, for everyone: the lockout-critical set cannot be removed from
	 *   (or denied to) `administrator`.
	 * - For a non-admin: `administrator` cannot be edited at all (SEC-1); a
	 *   role the actor holds cannot be edited (SEC-5); a role whose
	 *   capabilities exceed the actor's cannot be edited (SEC-2); nothing the
	 *   actor lacks, and no role name, can be granted or denied (SEC-3), and
	 *   removing a capability the role denies counts as granting it, since
	 *   its holders may get it from another role again; no capability can be
	 *   denied in a role a toolkit admin holds (SEC-1), because a later
	 *   role's `false` overrides the `true` of `administrator`, which would
	 *   lock the administrator out where SEC-14 recovery cannot see it.
	 * - SEC-13, for everyone: a change that would leave the actor without
	 *   `blt_edit_roles` is refused, unless another toolkit admin exists and
	 *   the actor confirmed it.
	 *
	 * SEC-10 (the review step for a Critical grant) is the caller's to show;
	 * requires_critical_review() says which capabilities need it.
	 *
	 * @param WP_User $actor     Who is saving.
	 * @param string  $role      Role ID.
	 * @param array   $adds      Capabilities being set: names (granted), or
	 *                           capability => bool (`true` grants, `false`
	 *                           denies). A flip is an add with its new value.
	 * @param array   $removes   Capability names being removed from the role
	 *                           (or capability => anything; keys are used).
	 * @param bool    $confirmed Whether the actor confirmed a SEC-13 warning.
	 * @return true|WP_Error `blt_toolkit_no_actor`,
	 *                       `blt_toolkit_lockout_critical`,
	 *                       `blt_toolkit_protected_role`,
	 *                       `blt_toolkit_own_role`,
	 *                       `blt_toolkit_role_exceeds_actor`,
	 *                       `blt_toolkit_role_name_cap`,
	 *                       `blt_toolkit_grant_exceeds_actor`,
	 *                       `blt_toolkit_deny_exceeds_actor`,
	 *                       `blt_toolkit_admin_holds_role` or
	 *                       `blt_toolkit_self_lockout` (data `confirmable`
	 *                       true when confirming would let it through). The
	 *                       data's `caps` lists the offending capabilities
	 *                       and `rule` the SEC rule.
	 */
	public static function check_role_change( WP_User $actor, $role, array $adds, array $removes, $confirmed = false ) {
		if ( ! $actor->exists() ) {
			return self::error( self::violation( 'blt_toolkit_no_actor', '' ) );
		}

		$role     = (string) $role;
		$changes  = self::cap_changes( $adds, $removes );
		$is_admin = self::is_toolkit_admin( $actor );
		$names    = self::role_names();
		$role_obj = wp_roles()->get_role( $role );
		$current  = $role_obj instanceof WP_Role ? (array) $role_obj->capabilities : array();
		$denied   = array();

		foreach ( $current as $cap => $value ) {
			if ( empty( $value ) ) {
				$denied[] = (string) $cap;
			}
		}

		$facts = array(
			'actor_is_admin'   => $is_admin,
			'actor_roles'      => array_values( array_map( 'strval', (array) $actor->roles ) ),
			'actor_caps'       => self::effective_caps( $actor ),
			'role'             => $role,
			'role_caps'        => BLT_Toolkit_Superset::normalize( $current, $names ),
			'role_denied'      => $denied,
			'admin_holds_role' => false,
			'grants'           => $changes['grants'],
			'denies'           => $changes['denies'],
			'removes'          => $changes['removes'],
			'lockout'          => BLT_Toolkit_Caps::lockout_critical(),
			'role_names'       => $names,
			'loses_edit_roles' => false,
			'other_admin'      => false,
			'confirmed'        => (bool) $confirmed,
		);

		// SEC-1 for denies: only asked when a non-admin denies something, so
		// most saves run no user query.
		if ( ! $is_admin && array() !== $changes['denies'] && ! self::is_protected_role( $role ) ) {
			$facts['admin_holds_role'] = self::toolkit_admin_holds_role( $role );
		}

		// SEC-13 needs the actor's capabilities after the change, which only
		// a role they hold can alter.
		if ( ! empty( $actor->allcaps['blt_edit_roles'] ) && in_array( $role, $facts['actor_roles'], true ) ) {
			$roles_caps = array();

			foreach ( $facts['actor_roles'] as $held ) {
				$held_obj            = wp_roles()->get_role( $held );
				$held_caps           = $held_obj instanceof WP_Role ? (array) $held_obj->capabilities : array();
				$roles_caps[ $held ] = $held === $role ? self::apply_changes( $held_caps, $changes ) : $held_caps;
			}

			$after = self::merge_caps( $roles_caps, is_array( $actor->caps ) ? $actor->caps : array() );

			$facts['loses_edit_roles'] = empty( $after['blt_edit_roles'] );

			if ( $facts['loses_edit_roles'] ) {
				$facts['other_admin'] = self::other_toolkit_admin_exists( $actor );
			}
		}

		$violation = self::role_change_violation( $facts );

		return null === $violation ? true : self::error( $violation );
	}

	/**
	 * The Critical capabilities among those being granted (SEC-10).
	 *
	 * Granting any of them to a role other than `administrator`, or to any
	 * user, needs the review step with the word "Critical", the plain-English
	 * consequence and a typed confirmation of the role's display name.
	 *
	 * @param array $caps Capability names, or capability => value where only
	 *                    granting values count.
	 * @return string[] Sorted capability names; empty when no review is needed.
	 */
	public static function requires_critical_review( array $caps ) {
		$changes = self::cap_changes( $caps, array() );
		$out     = array();

		foreach ( $changes['grants'] as $cap ) {
			if ( BLT_Toolkit_Caps::CRITICAL === BLT_Toolkit_Caps::tier( $cap ) ) {
				$out[] = $cap;
			}
		}

		return $out;
	}

	/**
	 * Whether a role is protected (SEC-12): it cannot be deleted, its ID
	 * cannot change, and the lockout-critical set cannot be removed from it.
	 *
	 * @param string $role Role ID.
	 * @return bool
	 */
	public static function is_protected_role( $role ) {
		return self::ADMIN_ROLE === (string) $role;
	}

	/**
	 * When a user may next change their own username (UN-21), as a Unix
	 * timestamp; 0 when no cooldown applies.
	 *
	 * Reads `usernames.cooldown_days` and the user meta
	 * `blt_toolkit_username_changed_at`, which holds a Unix timestamp (a
	 * `Y-m-d H:i:s` UTC date is read too).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function username_cooldown_until( $user_id ) {
		$days = (int) BLT_Toolkit_Settings::get( 'usernames', 'cooldown_days' );

		if ( $days <= 0 ) {
			return 0;
		}

		return self::cooldown_until( get_user_meta( (int) $user_id, self::CHANGED_AT_META, true ), $days );
	}

	/**
	 * Whether another user than the actor is a toolkit admin (SEC-13).
	 *
	 * @param WP_User $actor Actor.
	 * @return bool
	 */
	private static function other_toolkit_admin_exists( WP_User $actor ) {
		$users = get_users(
			array(
				'role'        => self::ADMIN_ROLE,
				'exclude'     => array( (int) $actor->ID ),
				'number'      => 20,
				'count_total' => false,
			)
		);

		foreach ( (array) $users as $user ) {
			if ( $user instanceof WP_User && self::is_toolkit_admin( $user ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a user with the administrator role also holds this role (SEC-1
	 * for role denies). One user query with a limit of 1, run only on a
	 * non-admin's save that denies something.
	 *
	 * @param string $role Role ID.
	 * @return bool
	 */
	private static function toolkit_admin_holds_role( $role ) {
		$ids = get_users(
			array(
				'role'        => array( self::ADMIN_ROLE, (string) $role ),
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		return ! empty( $ids );
	}

	// ---------- Pure rule logic (unit-tested) ----------

	/**
	 * Split a requested change into grants, denies and removals.
	 *
	 * @param array $adds    Names (granted) or capability => bool.
	 * @param array $removes Names, or capability => anything (keys used).
	 * @return array{grants:string[],denies:string[],removes:string[]} Sorted, unique, without `do_not_allow` or empty names.
	 */
	public static function cap_changes( array $adds, array $removes ) {
		$grants = array();
		$denies = array();
		$gone   = array();

		foreach ( $adds as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( is_scalar( $value ) ) {
					$grants[ (string) $value ] = true;
				}
				continue;
			}

			if ( empty( $value ) ) {
				$denies[ $key ] = true;
			} else {
				$grants[ $key ] = true;
			}
		}

		foreach ( $removes as $key => $value ) {
			$name = is_int( $key ) ? ( is_scalar( $value ) ? (string) $value : '' ) : $key;

			$gone[ $name ] = true;
		}

		return array(
			'grants'  => self::clean_names( $grants ),
			'denies'  => self::clean_names( $denies ),
			'removes' => self::clean_names( $gone ),
		);
	}

	/**
	 * A role's capability array after a change from cap_changes().
	 *
	 * @param array $caps    Capability => value.
	 * @param array $changes Result of cap_changes().
	 * @return array
	 */
	public static function apply_changes( array $caps, array $changes ) {
		foreach ( isset( $changes['removes'] ) ? (array) $changes['removes'] : array() as $cap ) {
			unset( $caps[ $cap ] );
		}

		foreach ( isset( $changes['grants'] ) ? (array) $changes['grants'] : array() as $cap ) {
			$caps[ $cap ] = true;
		}

		foreach ( isset( $changes['denies'] ) ? (array) $changes['denies'] : array() as $cap ) {
			$caps[ $cap ] = false;
		}

		return $caps;
	}

	/**
	 * A user's `allcaps` as WordPress builds it (`WP_User::get_role_caps()`):
	 * each role's capabilities merged in order, a later role overriding an
	 * earlier one, then the user's own capabilities over all of them.
	 *
	 * @param array $roles_caps Role ID => capabilities, in the user's role order.
	 * @param array $user_caps  The user's `{prefix}capabilities` meta.
	 * @return array
	 */
	public static function merge_caps( array $roles_caps, array $user_caps ) {
		$all = array();

		foreach ( $roles_caps as $caps ) {
			$all = array_merge( $all, (array) $caps );
		}

		return array_merge( $all, $user_caps );
	}

	/**
	 * When a cooldown that started at `$changed_at` ends (UN-21).
	 *
	 * @param mixed $changed_at Unix timestamp, or a `Y-m-d H:i:s` UTC date; anything else means never changed.
	 * @param int   $days       Cooldown in days.
	 * @return int Unix timestamp, or 0 when no cooldown applies.
	 */
	public static function cooldown_until( $changed_at, $days ) {
		$days = (int) $days;

		if ( $days <= 0 ) {
			return 0;
		}

		if ( is_int( $changed_at ) || ( is_string( $changed_at ) && ctype_digit( $changed_at ) ) ) {
			$at = (int) $changed_at;
		} elseif ( is_string( $changed_at ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $changed_at ) ) {
			$at = (int) strtotime( $changed_at . ' UTC' );
		} else {
			$at = 0;
		}

		return $at > 0 ? $at + $days * 86400 : 0;
	}

	/**
	 * SEC-1 and SEC-2 for one actor and target, from plain facts.
	 *
	 * @param array $facts {
	 *     @type bool     $actor_exists    Whether the actor is a real user.
	 *     @type bool     $target_exists   Whether the target is a real user.
	 *     @type bool     $actor_is_admin  Whether the actor is a toolkit admin.
	 *     @type bool     $target_is_admin Whether the target is a toolkit admin.
	 *     @type bool     $same_user       Whether actor and target are one user.
	 *     @type string[] $missing         The target's capabilities the actor lacks.
	 * }
	 * @return array|null Null when allowed; otherwise array( 'code', 'rule', 'caps' ).
	 */
	public static function user_action_violation( array $facts ) {
		if ( empty( $facts['target_exists'] ) ) {
			return self::violation( 'blt_toolkit_no_user', '' );
		}

		if ( empty( $facts['actor_exists'] ) ) {
			return self::violation( 'blt_toolkit_no_actor', '' );
		}

		if ( ! empty( $facts['actor_is_admin'] ) ) {
			return null;
		}

		if ( ! empty( $facts['target_is_admin'] ) ) {
			return self::violation( 'blt_toolkit_protected_admin', 'SEC-1' );
		}

		if ( ! empty( $facts['same_user'] ) ) {
			return null;
		}

		$missing = isset( $facts['missing'] ) ? self::clean_list( $facts['missing'] ) : array();

		if ( array() !== $missing ) {
			return self::violation( 'blt_toolkit_target_exceeds_actor', 'SEC-2', $missing );
		}

		return null;
	}

	/**
	 * SEC-1, SEC-2, SEC-3 and SEC-5 for a change to a user's own
	 * capabilities, from plain facts (see can_change_user_caps()).
	 *
	 * @param array $facts {
	 *     @type bool     $actor_exists    Whether the actor is a real user.
	 *     @type bool     $target_exists   Whether the target is a real user.
	 *     @type bool     $actor_is_admin  Whether the actor is a toolkit admin.
	 *     @type bool     $target_is_admin Whether the target is a toolkit admin.
	 *     @type bool     $same_user       Whether actor and target are one user.
	 *     @type string[] $missing         The target's capabilities the actor lacks.
	 *     @type array    $actor_caps      The actor's effective capabilities (cap => true).
	 *     @type string[] $grants          Capabilities being granted.
	 *     @type string[] $denies          Capabilities being set to false.
	 *     @type string[] $removes         Keys being removed from the user's capabilities.
	 *     @type string[] $target_denied   Keys the user's capabilities hold as false now.
	 *     @type string[] $role_names      The site's role IDs.
	 * }
	 * @return array|null Null when allowed; otherwise array( 'code', 'rule', 'caps', 'confirmable' ).
	 */
	public static function user_caps_violation( array $facts ) {
		if ( empty( $facts['target_exists'] ) ) {
			return self::violation( 'blt_toolkit_no_user', '' );
		}

		if ( empty( $facts['actor_exists'] ) ) {
			return self::violation( 'blt_toolkit_no_actor', '' );
		}

		if ( ! empty( $facts['actor_is_admin'] ) ) {
			return null;
		}

		if ( ! empty( $facts['target_is_admin'] ) ) {
			return self::violation( 'blt_toolkit_protected_admin', 'SEC-1' );
		}

		if ( ! empty( $facts['same_user'] ) ) {
			return self::violation( 'blt_toolkit_own_caps', 'SEC-5' );
		}

		$missing = isset( $facts['missing'] ) ? self::clean_list( $facts['missing'] ) : array();

		if ( array() !== $missing ) {
			return self::violation( 'blt_toolkit_target_exceeds_actor', 'SEC-2', $missing );
		}

		$grants  = isset( $facts['grants'] ) ? self::clean_list( $facts['grants'] ) : array();
		$denies  = isset( $facts['denies'] ) ? self::clean_list( $facts['denies'] ) : array();
		$removes = isset( $facts['removes'] ) ? self::clean_list( $facts['removes'] ) : array();
		$roles   = isset( $facts['role_names'] ) ? array_flip( self::clean_list( $facts['role_names'] ) ) : array();
		$denied  = isset( $facts['target_denied'] ) ? array_flip( self::clean_list( $facts['target_denied'] ) ) : array();
		$named   = array();

		// A role ID among a user's capabilities is a role membership, whatever
		// its value (WP_User::get_role_caps()); taking one out is too.
		foreach ( self::clean_list( array_merge( $grants, $denies, $removes ) ) as $cap ) {
			if ( isset( $roles[ $cap ] ) ) {
				$named[] = $cap;
			}
		}

		if ( array() !== $named ) {
			return self::violation( 'blt_toolkit_role_name_cap', 'SEC-3', $named );
		}

		// Taking away a deny gives the capability back when a role grants it.
		foreach ( $removes as $cap ) {
			if ( isset( $denied[ $cap ] ) ) {
				$grants[] = $cap;
			}
		}

		return self::grant_violation(
			array(
				'actor_is_admin' => false,
				'actor_caps'     => isset( $facts['actor_caps'] ) ? (array) $facts['actor_caps'] : array(),
				'grants'         => $grants,
				'denies'         => $denies,
				'role_names'     => isset( $facts['role_names'] ) ? (array) $facts['role_names'] : array(),
			)
		);
	}

	/**
	 * SEC-3 for a set of grants and denies, from plain facts.
	 *
	 * A non-admin may set only capabilities they hold, to either value:
	 * granting one they lack gives power they do not have, and denying one
	 * they lack takes it from people who have more than they do. A key named
	 * like a role is refused whatever its value.
	 *
	 * @param array $facts {
	 *     @type bool     $actor_is_admin Whether the actor is a toolkit admin.
	 *     @type array    $actor_caps     The actor's effective capabilities (cap => true).
	 *     @type string[] $grants         Capabilities being granted.
	 *     @type string[] $denies         Capabilities being set to false (optional).
	 *     @type string[] $role_names     The site's role IDs.
	 * }
	 * @return array|null Null when allowed; otherwise array( 'code', 'rule', 'caps' ).
	 */
	public static function grant_violation( array $facts ) {
		if ( ! empty( $facts['actor_is_admin'] ) ) {
			return null;
		}

		$grants     = isset( $facts['grants'] ) ? self::clean_list( $facts['grants'] ) : array();
		$denies     = isset( $facts['denies'] ) ? self::clean_list( $facts['denies'] ) : array();
		$roles      = isset( $facts['role_names'] ) ? array_flip( self::clean_list( $facts['role_names'] ) ) : array();
		$actor_caps = isset( $facts['actor_caps'] ) ? (array) $facts['actor_caps'] : array();
		$named      = array();

		foreach ( self::clean_list( array_merge( $grants, $denies ) ) as $cap ) {
			if ( isset( $roles[ $cap ] ) ) {
				$named[] = $cap;
			}
		}

		if ( array() !== $named ) {
			return self::violation( 'blt_toolkit_role_name_cap', 'SEC-3', $named );
		}

		$missing = BLT_Toolkit_Superset::missing( $actor_caps, array_fill_keys( $grants, true ) );

		if ( array() !== $missing ) {
			return self::violation( 'blt_toolkit_grant_exceeds_actor', 'SEC-3', $missing );
		}

		$missing = BLT_Toolkit_Superset::missing( $actor_caps, array_fill_keys( $denies, true ) );

		if ( array() !== $missing ) {
			return self::violation( 'blt_toolkit_deny_exceeds_actor', 'SEC-3', $missing );
		}

		return null;
	}

	/**
	 * The role-change rules of check_role_change(), from plain facts.
	 *
	 * Checked in this order, first failure wins: SEC-12, then for a non-admin
	 * the protected role (SEC-1), SEC-5, SEC-2, denies in a role a toolkit
	 * admin holds (SEC-1) and SEC-3, then SEC-13.
	 *
	 * @param array $facts {
	 *     @type bool     $actor_is_admin   Whether the actor is a toolkit admin.
	 *     @type string[] $actor_roles      Roles the actor holds.
	 *     @type array    $actor_caps       The actor's effective capabilities (cap => true).
	 *     @type string   $role             Role being changed.
	 *     @type array    $role_caps        That role's capabilities now (cap => true).
	 *     @type string[] $role_denied      Capabilities the role holds as false now.
	 *     @type bool     $admin_holds_role Whether a toolkit admin holds the role.
	 *     @type string[] $grants           Capabilities being granted.
	 *     @type string[] $denies           Capabilities being set to false.
	 *     @type string[] $removes          Capabilities being removed.
	 *     @type string[] $lockout          The lockout-critical set (SEC-12).
	 *     @type string[] $role_names       The site's role IDs.
	 *     @type bool     $loses_edit_roles Whether the actor would no longer hold `blt_edit_roles`.
	 *     @type bool     $other_admin      Whether another toolkit admin exists.
	 *     @type bool     $confirmed        Whether the actor confirmed the SEC-13 warning.
	 * }
	 * @return array|null Null when allowed; otherwise array( 'code', 'rule', 'caps', 'confirmable' ).
	 */
	public static function role_change_violation( array $facts ) {
		$role    = isset( $facts['role'] ) ? (string) $facts['role'] : '';
		$grants  = isset( $facts['grants'] ) ? self::clean_list( $facts['grants'] ) : array();
		$taken   = array_merge(
			isset( $facts['removes'] ) ? self::clean_list( $facts['removes'] ) : array(),
			isset( $facts['denies'] ) ? self::clean_list( $facts['denies'] ) : array()
		);
		$lockout = isset( $facts['lockout'] ) ? self::clean_list( $facts['lockout'] ) : array();

		// SEC-12.
		if ( self::is_protected_role( $role ) ) {
			$hit = array_values( array_intersect( self::clean_list( $taken ), $lockout ) );

			if ( array() !== $hit ) {
				return self::violation( 'blt_toolkit_lockout_critical', 'SEC-12', $hit );
			}
		}

		if ( empty( $facts['actor_is_admin'] ) ) {
			if ( self::is_protected_role( $role ) ) {
				return self::violation( 'blt_toolkit_protected_role', 'SEC-1' );
			}

			// SEC-5.
			if ( in_array( $role, isset( $facts['actor_roles'] ) ? self::clean_list( $facts['actor_roles'] ) : array(), true ) ) {
				return self::violation( 'blt_toolkit_own_role', 'SEC-5' );
			}

			$actor_caps = isset( $facts['actor_caps'] ) ? (array) $facts['actor_caps'] : array();

			// SEC-2: never edit a role more powerful than yourself.
			$missing = BLT_Toolkit_Superset::missing( $actor_caps, isset( $facts['role_caps'] ) ? (array) $facts['role_caps'] : array() );

			if ( array() !== $missing ) {
				return self::violation( 'blt_toolkit_role_exceeds_actor', 'SEC-2', $missing );
			}

			$denies = isset( $facts['denies'] ) ? self::clean_list( $facts['denies'] ) : array();

			// SEC-1: a deny in a role an administrator also holds overrides
			// what `administrator` grants them.
			if ( array() !== $denies && ! empty( $facts['admin_holds_role'] ) ) {
				return self::violation( 'blt_toolkit_admin_holds_role', 'SEC-1', $denies );
			}

			// SEC-3. Removing a capability the role denies gives it back to
			// every holder who has it through another role: a grant.
			$denied = isset( $facts['role_denied'] ) ? array_flip( self::clean_list( $facts['role_denied'] ) ) : array();

			foreach ( isset( $facts['removes'] ) ? self::clean_list( $facts['removes'] ) : array() as $cap ) {
				if ( isset( $denied[ $cap ] ) ) {
					$grants[] = $cap;
				}
			}

			$grant = self::grant_violation(
				array(
					'actor_is_admin' => false,
					'actor_caps'     => $actor_caps,
					'grants'         => $grants,
					'denies'         => $denies,
					'role_names'     => isset( $facts['role_names'] ) ? (array) $facts['role_names'] : array(),
				)
			);

			if ( null !== $grant ) {
				return $grant;
			}
		}

		// SEC-13.
		if ( ! empty( $facts['loses_edit_roles'] ) ) {
			$other = ! empty( $facts['other_admin'] );

			if ( ! $other || empty( $facts['confirmed'] ) ) {
				return self::violation( 'blt_toolkit_self_lockout', 'SEC-13', array( 'blt_edit_roles' ), $other );
			}
		}

		return null;
	}

	/**
	 * A violation record.
	 *
	 * @param string   $code        Error code.
	 * @param string   $rule        SEC rule ID, or ''.
	 * @param string[] $caps        Offending capabilities.
	 * @param bool     $confirmable Whether confirming would let it through.
	 * @return array{code:string,rule:string,caps:string[],confirmable:bool}
	 */
	private static function violation( $code, $rule, array $caps = array(), $confirmable = false ) {
		return array(
			'code'        => $code,
			'rule'        => $rule,
			'caps'        => array_values( $caps ),
			'confirmable' => (bool) $confirmable,
		);
	}

	/**
	 * The WP_Error for a violation, with a message a person can act on.
	 *
	 * @param array $violation Result of one of the *_violation() methods.
	 * @return WP_Error Data: `status` (403, or 404 for a missing user), `rule`, `caps`, `confirmable`.
	 */
	public static function error( array $violation ) {
		$code = isset( $violation['code'] ) ? (string) $violation['code'] : 'blt_toolkit_forbidden';
		$caps = isset( $violation['caps'] ) ? (array) $violation['caps'] : array();
		$list = self::caps_text( $caps );

		switch ( $code ) {
			case 'blt_toolkit_no_user':
				$message = __( 'That user does not exist.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_no_actor':
				$message = __( 'You need to be signed in to do that.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_protected_admin':
				$message = __( 'Only an administrator can do that to an administrator.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_target_exceeds_actor':
				/* translators: %s: comma-separated capability names. */
				$message = sprintf( __( 'This user has capabilities you do not have (%s), so you cannot do that to them.', 'blt-toolkit' ), $list );
				break;
			case 'blt_toolkit_role_name_cap':
				/* translators: %s: comma-separated role IDs. */
				$message = sprintf( __( 'These are roles, not capabilities, so they cannot be granted, denied or removed here (%s). Change a user\'s roles with the role controls instead.', 'blt-toolkit' ), $list );
				break;
			case 'blt_toolkit_grant_exceeds_actor':
				/* translators: %s: comma-separated capability names. */
				$message = sprintf( __( 'You cannot grant capabilities you do not have yourself (%s).', 'blt-toolkit' ), $list );
				break;
			case 'blt_toolkit_deny_exceeds_actor':
				/* translators: %s: comma-separated capability names. */
				$message = sprintf( __( 'You cannot deny capabilities you do not have yourself (%s).', 'blt-toolkit' ), $list );
				break;
			case 'blt_toolkit_own_caps':
				$message = __( 'You cannot change your own capabilities. Ask an administrator.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_admin_holds_role':
				$message = __( 'An administrator also holds this role, so only an administrator can deny capabilities in it.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_lockout_critical':
				/* translators: %s: comma-separated capability names. */
				$message = sprintf( __( 'The Administrator role must keep these capabilities, or nobody could manage the site: %s.', 'blt-toolkit' ), $list );
				break;
			case 'blt_toolkit_protected_role':
				$message = __( 'Only an administrator can change the Administrator role.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_own_role':
				$message = __( 'You cannot change a role you hold yourself. Ask an administrator.', 'blt-toolkit' );
				break;
			case 'blt_toolkit_role_exceeds_actor':
				/* translators: %s: comma-separated capability names. */
				$message = sprintf( __( 'This role has capabilities you do not have (%s), so you cannot change it.', 'blt-toolkit' ), $list );
				break;
			case 'blt_toolkit_self_lockout':
				$message = empty( $violation['confirmable'] )
					? __( 'This change would take away your own ability to edit roles, and there is no other administrator to give it back.', 'blt-toolkit' )
					: __( 'This change would take away your own ability to edit roles. Another administrator could give it back. Confirm to continue.', 'blt-toolkit' );
				break;
			default:
				$message = __( 'You do not have permission to do that.', 'blt-toolkit' );
		}

		return new WP_Error(
			$code,
			$message,
			array(
				'status'      => in_array( $code, array( 'blt_toolkit_no_user' ), true ) ? 404 : 403,
				'rule'        => isset( $violation['rule'] ) ? (string) $violation['rule'] : '',
				'caps'        => array_values( $caps ),
				'confirmable' => ! empty( $violation['confirmable'] ),
			)
		);
	}

	/**
	 * Capability names for a message: the first five, then how many more.
	 * The error data always carries the full list.
	 *
	 * @param string[] $caps Capability names.
	 * @return string
	 */
	private static function caps_text( array $caps ) {
		$caps  = array_values( array_map( 'strval', $caps ) );
		$shown = implode( ', ', array_slice( $caps, 0, 5 ) );
		$more  = count( $caps ) - 5;

		if ( $more <= 0 ) {
			return $shown;
		}

		return sprintf(
			/* translators: 1: comma-separated capability names, 2: how many more there are. */
			_n( '%1$s and %2$s more', '%1$s and %2$s more', $more, 'blt-toolkit' ),
			$shown,
			number_format_i18n( $more )
		);
	}

	/**
	 * Unique, non-empty names from a set (name => true), sorted, without
	 * `do_not_allow`.
	 *
	 * @param array $set Name => true.
	 * @return string[]
	 */
	private static function clean_names( array $set ) {
		return self::clean_list( array_keys( $set ) );
	}

	/**
	 * Unique, non-empty string names from a list, sorted, without
	 * `do_not_allow`.
	 *
	 * @param mixed $values Values.
	 * @return string[]
	 */
	private static function clean_list( $values ) {
		$out = array();

		foreach ( (array) $values as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}

			$name = (string) $name;

			if ( '' !== $name && BLT_Toolkit_Superset::DO_NOT_ALLOW !== $name ) {
				$out[ $name ] = true;
			}
		}

		$out = array_keys( $out );

		sort( $out, SORT_STRING );

		return array_map( 'strval', $out );
	}

	// ---------- Recovery (SEC-14) ----------

	/**
	 * Whether `BLT_TOOLKIT_RECOVERY` is set to a true value.
	 *
	 * @return bool
	 */
	public static function recovery_requested() {
		return defined( 'BLT_TOOLKIT_RECOVERY' ) && (bool) constant( 'BLT_TOOLKIT_RECOVERY' );
	}

	/**
	 * The capabilities recovery makes sure `administrator` holds: the
	 * lockout-critical set (SEC-12) and every BLT Toolkit capability
	 * (SPEC 5.1). Other BLT plugins' `blt_*` capabilities are not ours to
	 * grant.
	 *
	 * @return string[]
	 */
	public static function recovery_caps() {
		return self::clean_list( array_merge( BLT_Toolkit_Caps::lockout_critical(), array_keys( BLT_Toolkit_Caps::own() ) ) );
	}

	/**
	 * `init` callback while recovery mode is on: on an admin request by a
	 * user with the administrator role, restore what `administrator` lacks.
	 *
	 * @return void
	 */
	public static function maybe_recover() {
		if ( ! is_admin() || ! self::recovery_requested() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! $user->exists() || ! self::holds_admin_role( $user ) ) {
			return;
		}

		self::recover();
	}

	/**
	 * Give `administrator` back every recovery capability it lacks (SEC-14).
	 *
	 * A capability counts as lacking when the role does not grant it (absent,
	 * or set to deny). When nothing lacks, nothing happens, so running on
	 * every admin request is harmless. Otherwise: a `recovery` snapshot
	 * (SNAP-1), one write to the roles option, and a log row. If the
	 * snapshot cannot be stored, recovery still goes ahead, because an
	 * administrator who is locked out must get back in, and the log row says
	 * the snapshot failed. If the role itself was deleted outside BLT
	 * Toolkit, it is created again with just these capabilities. Nothing
	 * else changes.
	 *
	 * When the site defines its roles in code (`$wp_user_roles`, so
	 * `WP_Roles::$use_db` is false), nothing can be written: the role is
	 * repaired in memory for this request, no snapshot is taken, and one
	 * `warning` row a day says so (instead of a "success" row on every admin
	 * request).
	 *
	 * @return string[] The capabilities granted; empty when nothing was needed.
	 */
	public static function recover() {
		$wp_roles = wp_roles();
		$role     = $wp_roles->get_role( self::ADMIN_ROLE );
		$recreate = ! $role instanceof WP_Role;
		$missing  = array();

		foreach ( self::recovery_caps() as $cap ) {
			if ( $recreate || empty( $role->capabilities[ $cap ] ) ) {
				$missing[] = $cap;
			}
		}

		if ( array() === $missing ) {
			return array();
		}

		// Roles defined in code ($wp_user_roles) are read from that code on
		// every request, so nothing can be saved: grant in memory, so the
		// administrator gets in for as long as recovery mode is on, and say
		// so once rather than on every admin request.
		if ( ! $wp_roles->use_db ) {
			self::grant_in_memory( $wp_roles, $missing, $recreate );
			self::note_code_defined_recovery( $missing, $recreate );

			return $missing;
		}

		$snapshot_id = (int) BLT_Toolkit_Snapshots::take( 'recovery' );

		if ( $snapshot_id <= 0 ) {
			error_log( 'BLT Toolkit: recovery mode could not store a role snapshot first; it restored the administrator role anyway.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed snapshot must be visible to the site owner's logs.
		}

		// One write for the whole batch (the RO-3 technique).
		self::grant_in_memory( $wp_roles, $missing, $recreate );

		update_option( $wp_roles->role_key, $wp_roles->roles );

		self::log_recovery( $missing, $recreate, $snapshot_id );

		return $missing;
	}

	/**
	 * Give `administrator` the capabilities in memory (use_db off, so no
	 * write per capability), drop the Guard's cached comparisons, and refresh
	 * the current user so the rest of the request sees the result.
	 *
	 * @param WP_Roles $wp_roles Roles.
	 * @param string[] $caps     Capabilities to grant.
	 * @param bool     $recreate Whether the role must be created first.
	 * @return void
	 */
	private static function grant_in_memory( WP_Roles $wp_roles, array $caps, $recreate ) {
		$use_db           = $wp_roles->use_db;
		$wp_roles->use_db = false;
		$role             = $recreate ? $wp_roles->add_role( self::ADMIN_ROLE, 'Administrator' ) : $wp_roles->get_role( self::ADMIN_ROLE );

		if ( $role instanceof WP_Role ) {
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap, true );
			}
		}

		$wp_roles->use_db = $use_db;

		self::flush();

		// The current user's capabilities were worked out before this;
		// refresh them so the rest of this request already sees the result.
		$current = wp_get_current_user();

		if ( $current->exists() ) {
			$current->get_role_caps();
		}
	}

	/**
	 * Record, once per day and per set of capabilities, that recovery could
	 * only repair roles defined in code for the current request (SEC-14,
	 * LOG-1). No snapshot is taken, because the roles option is not written.
	 *
	 * @param string[] $granted   Capabilities granted in memory.
	 * @param bool     $recreated Whether the role was created in memory.
	 * @return void
	 */
	private static function note_code_defined_recovery( array $granted, $recreated ) {
		$key = md5( implode( ',', $granted ) . '|' . (int) $recreated );

		if ( get_transient( self::CODE_RECOVERY_TRANSIENT ) === $key ) {
			return;
		}

		set_transient( self::CODE_RECOVERY_TRANSIENT, $key, DAY_IN_SECONDS );

		if ( ! is_callable( array( 'BLT_Toolkit_Log', 'record' ) ) ) {
			return;
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'recovery',
				'object_type' => 'role',
				'object_id'   => self::ADMIN_ROLE,
				'summary'     => sprintf(
					/* translators: %s: number of capabilities. */
					_n(
						'Recovery mode gave the Administrator role back %s capability for each request only: this site defines its roles in code, so nothing could be saved.',
						'Recovery mode gave the Administrator role back %s capabilities for each request only: this site defines its roles in code, so nothing could be saved.',
						count( $granted ),
						'blt-toolkit'
					),
					number_format_i18n( count( $granted ) )
				),
				'details'     => array(
					'granted'        => array_values( $granted ),
					'role_recreated' => (bool) $recreated,
					'roles_in_code'  => true,
				),
				'snapshot_id' => null,
				'status'      => 'warning',
				'source'      => '',
			)
		);
	}

	/**
	 * Record a recovery in the activity log (SEC-14, LOG-1).
	 *
	 * @param string[] $granted     Capabilities granted.
	 * @param bool     $recreated   Whether the role had to be created again.
	 * @param int      $snapshot_id Snapshot taken first, or 0 when it failed.
	 * @return void
	 */
	private static function log_recovery( array $granted, $recreated, $snapshot_id ) {
		if ( ! is_callable( array( 'BLT_Toolkit_Log', 'record' ) ) ) {
			return;
		}

		$count   = count( $granted );
		$summary = $recreated
			? __( 'Recovery mode created the Administrator role again with the capabilities it needs to manage the site.', 'blt-toolkit' )
			: sprintf(
				/* translators: %s: number of capabilities. */
				_n( 'Recovery mode gave the Administrator role back %s capability.', 'Recovery mode gave the Administrator role back %s capabilities.', $count, 'blt-toolkit' ),
				number_format_i18n( $count )
			);

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'recovery',
				'object_type' => 'role',
				'object_id'   => self::ADMIN_ROLE,
				'summary'     => $summary,
				'details'     => array(
					'granted'         => array_values( $granted ),
					'role_recreated'  => (bool) $recreated,
					'snapshot_failed' => $snapshot_id <= 0,
				),
				'snapshot_id' => $snapshot_id > 0 ? $snapshot_id : null,
				'status'      => $snapshot_id > 0 ? 'success' : 'warning',
				'source'      => '',
			)
		);
	}

	/**
	 * Whether a user holds the administrator role, for recovery.
	 *
	 * Normally is_toolkit_admin(). When the role itself no longer exists,
	 * WordPress leaves it out of `WP_User::$roles`, so the user's own
	 * capabilities meta, which still names it, is read instead.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	private static function holds_admin_role( WP_User $user ) {
		if ( self::is_toolkit_admin( $user ) ) {
			return true;
		}

		return ! wp_roles()->is_role( self::ADMIN_ROLE ) && is_array( $user->caps ) && ! empty( $user->caps[ self::ADMIN_ROLE ] );
	}

	/**
	 * Persistent notice to administrators while recovery mode is on (SEC-14).
	 *
	 * Shown on every admin screen but the BLT Toolkit Overview, whose own
	 * recovery-mode callout (SPEC 13.2) says the same thing.
	 *
	 * @return void
	 */
	public static function recovery_notice() {
		$user = wp_get_current_user();

		if ( ! $user->exists() || ! self::holds_admin_role( $user ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof WP_Screen && class_exists( 'BLT_Toolkit_Admin' ) && 'toplevel_page_' . BLT_Toolkit_Admin::MENU_SLUG === $screen->id ) {
			return;
		}

		$line = '<code>' . esc_html( "define( 'BLT_TOOLKIT_RECOVERY', true );" ) . '</code>';

		if ( ! wp_roles()->use_db ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'BLT Toolkit recovery mode is on.', 'blt-toolkit' ),
				sprintf(
					/* translators: 1: the PHP global that holds the roles, in code formatting, 2: the wp-config.php line, in code formatting. */
					esc_html__( 'This site defines its roles in code (%1$s), so recovery cannot save anything: the Administrator role has what it needs only while recovery mode is on. Fix the Administrator role in that code, then remove %2$s from wp-config.php.', 'blt-toolkit' ),
					'<code>$wp_user_roles</code>',
					$line // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'BLT Toolkit recovery mode is on.', 'blt-toolkit' ),
			sprintf(
				/* translators: %s: the wp-config.php line, in code formatting. */
				esc_html__( 'The Administrator role has the capabilities it needs to manage the site and every BLT Toolkit capability. Remove %s from wp-config.php now; it is meant for emergencies only.', 'blt-toolkit' ),
				$line // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			)
		);
	}
}
