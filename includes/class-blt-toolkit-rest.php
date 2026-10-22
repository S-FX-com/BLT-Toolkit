<?php
/**
 * REST routes under the `blt-toolkit/v1` namespace.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers BLT Toolkit's REST routes (SPEC 3: namespace `blt-toolkit/v1`,
 * BLT_TOOLKIT_REST_NS).
 *
 * Every route has a `permission_callback` (CONVENTIONS 6.3). Cookie
 * authentication needs the `X-WP-Nonce` header (`wp_rest`); without it
 * WordPress treats the request as signed out and every route here refuses
 * it, which is what protects the POST routes from cross-site requests.
 *
 * Phase 1 registers the job routes (JOB-1, JOB-4). Later phases add their
 * routes as `register_<area>_routes()` methods called from register_routes().
 *
 * - `GET  /jobs/{id}`        the job's progress (BLT_Toolkit_Jobs::view()).
 * - `POST /jobs/{id}/tick`   run one tick and return the progress.
 * - `POST /jobs/{id}/cancel` cancel; finished items are kept.
 * - `POST /jobs/{id}/retry`  start a pass over the failed items.
 *
 * All four need the capability the job's type was registered with.
 *
 * Phase 2 adds, while the Switch module runs (register_switch_routes()):
 *
 * - `GET /switch/users?search=` the toolbar "Switch to…" typeahead (SW-20):
 *   needs `blt_switch_users`, at least 2 characters, at most
 *   `blt_toolkit_switch_search_limit` (20) users, only users the current
 *   user may switch to, each with a nonced switch URL made here.
 * - The `blt_toolkit_switch_url` field on core's `user` object (SW-27):
 *   that URL, or null unless the current user may switch to that user.
 *
 * Phase 3 adds, while the Usernames module runs (register_username_routes()):
 *
 * - `GET /usernames/check?user_id=&username=` the profile screen's live
 *   check (UN-16): needs `blt_change_username` for that user, runs the one
 *   validator (UN-1 to UN-9) and says whether the name would pass. Advisory
 *   only: the rename itself validates again and decides.
 */
class BLT_Toolkit_Rest {

	/**
	 * Route pattern of a job ID (BLT_Toolkit_Jobs IDs are 16 hex characters).
	 */
	const JOB_ID = '(?P<id>[a-f0-9]{16})';

	/**
	 * Shortest and longest search text for `/switch/users` (SW-20).
	 */
	const SWITCH_SEARCH_MIN = 2;
	const SWITCH_SEARCH_MAX = 100;

	/**
	 * Most results `/switch/users` returns (SW-20), before the
	 * `blt_toolkit_switch_search_limit` filter, and the most that filter may
	 * ask for.
	 */
	const SWITCH_SEARCH_LIMIT     = 20;
	const SWITCH_SEARCH_LIMIT_MAX = 100;

	/**
	 * Most candidate users `/switch/users` reads to fill one answer. Users
	 * the current user may not switch to are dropped after the query, so a
	 * search that matches many such users stops here instead of reading the
	 * whole table.
	 */
	const SWITCH_SEARCH_SCAN = 500;

	/**
	 * Longest `username` the check route accepts. Far above the 60-character
	 * limit, so the validator still answers "too long" (UN-2); it only stops
	 * a huge string from being processed at all.
	 */
	const USERNAME_CHECK_MAX = 1000;

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook the routes onto `rest_api_init`. Only adds a hook, so it costs
	 * nothing on a request that is not a REST request.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register every route. `rest_api_init` callback.
	 *
	 * @return void
	 */
	public static function register_routes() {
		self::register_job_routes();

		// A module that did not boot registers nothing (SPEC 4.1).
		if ( BLT_Toolkit_Plugin::is_active( 'switch' ) ) {
			self::register_switch_routes();
		}

		if ( BLT_Toolkit_Plugin::is_active( 'usernames' ) ) {
			self::register_username_routes();
		}
	}

	/**
	 * The job routes (JOB-1, JOB-4).
	 *
	 * @return void
	 */
	public static function register_job_routes() {
		$id_arg = array(
			'id' => array(
				'description'       => __( 'Job ID.', 'blt-toolkit' ),
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_job_id' ),
			),
		);

		register_rest_route(
			BLT_TOOLKIT_REST_NS,
			'/jobs/' . self::JOB_ID,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_job' ),
				'permission_callback' => array( __CLASS__, 'job_permission' ),
				'args'                => $id_arg,
			)
		);

		$actions = array(
			'tick'   => 'tick_job',
			'cancel' => 'cancel_job',
			'retry'  => 'retry_job',
		);

		foreach ( $actions as $path => $callback ) {
			register_rest_route(
				BLT_TOOLKIT_REST_NS,
				'/jobs/' . self::JOB_ID . '/' . $path,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( __CLASS__, 'job_permission' ),
					'args'                => $id_arg,
				)
			);
		}
	}

	/**
	 * Whether a value is a well-formed job ID.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function validate_job_id( $value ) {
		return '' !== BLT_Toolkit_Jobs::clean_id( $value );
	}

	/**
	 * Permission for every job route: signed in, the job exists, and the
	 * current user holds its type's capability.
	 *
	 * A job whose type is not registered (its module is switched off) cannot
	 * be checked, so nobody may use it until the module is back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function job_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You need to be signed in to do that.', 'blt-toolkit' ), array( 'status' => 401 ) );
		}

		$job = BLT_Toolkit_Jobs::get( (string) $request->get_param( 'id' ) );

		if ( null === $job ) {
			return new WP_Error( 'blt_toolkit_job_not_found', __( 'That job no longer exists.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		if ( null === BLT_Toolkit_Jobs::type( $job['type'] ) ) {
			return new WP_Error( 'blt_toolkit_job_unavailable', __( 'This job belongs to a module that is not running, so it cannot be shown or continued.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( ! BLT_Toolkit_Jobs::current_user_can_use( $job ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to do that.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * `GET /jobs/{id}`: the job's progress.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_job( WP_REST_Request $request ) {
		$job = BLT_Toolkit_Jobs::get( (string) $request->get_param( 'id' ) );

		if ( null === $job ) {
			return self::job_gone();
		}

		return self::respond( BLT_Toolkit_Jobs::view( $job ) );
	}

	/**
	 * `POST /jobs/{id}/tick`: run one tick (up to the tick budget) and return
	 * the progress, with `ran` and `note` (`busy` when another request is
	 * running the job).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function tick_job( WP_REST_Request $request ) {
		$view = BLT_Toolkit_Jobs::tick( (string) $request->get_param( 'id' ) );

		return array() === $view ? self::job_gone() : self::respond( $view );
	}

	/**
	 * `POST /jobs/{id}/cancel`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_job( WP_REST_Request $request ) {
		$view = BLT_Toolkit_Jobs::cancel( (string) $request->get_param( 'id' ) );

		return is_wp_error( $view ) ? $view : self::respond( $view );
	}

	/**
	 * `POST /jobs/{id}/retry`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function retry_job( WP_REST_Request $request ) {
		$view = BLT_Toolkit_Jobs::retry_failed( (string) $request->get_param( 'id' ) );

		return is_wp_error( $view ) ? $view : self::respond( $view );
	}

	// ---------- Usernames (UN-16) ----------

	/**
	 * The live username check (UN-16).
	 *
	 * @return void
	 */
	public static function register_username_routes() {
		register_rest_route(
			BLT_TOOLKIT_REST_NS,
			'/usernames/check',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'check_username' ),
				'permission_callback' => array( __CLASS__, 'username_check_permission' ),
				'args'                => array(
					'user_id'  => array(
						'description' => __( 'The user whose username would change.', 'blt-toolkit' ),
						'type'        => 'integer',
						'minimum'     => 1,
						'required'    => true,
					),
					'username' => array(
						'description' => __( 'The proposed username, as typed.', 'blt-toolkit' ),
						'type'        => 'string',
						'maxLength'   => self::USERNAME_CHECK_MAX,
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Permission for `/usernames/check`: signed in, and allowed to change
	 * that user's username (`blt_change_username`, so SEC-1, SEC-2 and the
	 * cooldown apply exactly as they do to the rename).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function username_check_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You need to be signed in to do that.', 'blt-toolkit' ), array( 'status' => 401 ) );
		}

		$user_id = (int) $request->get_param( 'user_id' );

		if ( $user_id <= 0 || ! current_user_can( 'blt_change_username', $user_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to do that.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * `GET /usernames/check`: would this name pass for this user?
	 *
	 * Answers `valid`, the trimmed `username`, and for a name that fails the
	 * first failing rule's `code` and `message` (and `suggestion` for UN-1).
	 * The name is not reserved; the rename validates again.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function check_username( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request->get_param( 'user_id' ) );

		if ( ! $user instanceof WP_User ) {
			return new WP_Error( 'blt_toolkit_username_no_user', __( 'That user does not exist.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		$raw    = $request->get_param( 'username' );
		$name   = BLT_Toolkit_Username_Validator::normalize( $raw );
		$errors = BLT_Toolkit_Username_Validator::validate( is_string( $raw ) ? $raw : '', $user );

		if ( BLT_Toolkit_Username_Validator::is_valid( $errors ) ) {
			return self::respond(
				array(
					'valid'    => true,
					'username' => $name,
					'code'     => '',
					'message'  => sprintf(
						/* translators: %s: a username. */
						__( '"%s" is available.', 'blt-toolkit' ),
						$name
					),
				)
			);
		}

		$code = (string) $errors->get_error_code();
		$data = $errors->get_error_data( $code );
		$out  = array(
			'valid'    => false,
			'username' => $name,
			'code'     => $code,
			'message'  => BLT_Toolkit_Username_Changer::public_error_message( $code, $errors->get_error_message( $code ) ),
		);

		if ( is_array( $data ) && isset( $data['suggestion'] ) && is_string( $data['suggestion'] ) && '' !== $data['suggestion'] ) {
			$out['suggestion'] = $data['suggestion'];
		}

		return self::respond( $out );
	}

	// ---------- Switch (SW-20, SW-27) ----------

	/**
	 * The toolbar search route (SW-20) and the user field (SW-27).
	 *
	 * @return void
	 */
	public static function register_switch_routes() {
		register_rest_route(
			BLT_TOOLKIT_REST_NS,
			'/switch/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search_switch_users' ),
				'permission_callback' => array( __CLASS__, 'switch_search_permission' ),
				'args'                => array(
					'search' => array(
						'description'       => __( 'Part of a name, username or email address: at least 2 characters.', 'blt-toolkit' ),
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_switch_search' ),
						'sanitize_callback' => array( __CLASS__, 'clean_switch_search' ),
					),
				),
			)
		);

		register_rest_field(
			'user',
			'blt_toolkit_switch_url',
			array(
				'get_callback' => array( __CLASS__, 'switch_url_field' ),
				'schema'       => array(
					'description' => __( 'The URL that switches to this user, or null when the current user may not switch to them.', 'blt-toolkit' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Permission for `/switch/users`: signed in with `blt_switch_users`
	 * (SW-1). Who may be switched to is decided per user in the callback.
	 *
	 * @return true|WP_Error
	 */
	public static function switch_search_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You need to be signed in to do that.', 'blt-toolkit' ), array( 'status' => 401 ) );
		}

		if ( ! current_user_can( 'blt_switch_users' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to do that.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * The search text as the query will use it: one line, no wildcards
	 * (WP_User_Query treats a leading or trailing `*` as one), trimmed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_switch_search( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( str_replace( '*', '', sanitize_text_field( $value ) ) );
	}

	/**
	 * Whether the search text is long enough to search with, and not too
	 * long (SW-20).
	 *
	 * @param mixed $value Raw value.
	 * @return true|WP_Error
	 */
	public static function validate_switch_search( $value ) {
		$clean  = self::clean_switch_search( $value );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean );

		if ( $length < self::SWITCH_SEARCH_MIN || $length > self::SWITCH_SEARCH_MAX ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: 1: shortest length, 2: longest length. */
					__( 'Search for between %1$d and %2$d characters.', 'blt-toolkit' ),
					self::SWITCH_SEARCH_MIN,
					self::SWITCH_SEARCH_MAX
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * `GET /switch/users`: users matching the search whom the current user
	 * may switch to (SW-20).
	 *
	 * Matches the username, author slug, email address and display name,
	 * ordered by display name, never the current user. Each candidate is
	 * checked with BLT_Toolkit_Switch::maybe_switch_url(), which is
	 * `current_user_can( 'blt_switch_to_user', $id )` (SW-1, SEC-1, SEC-2),
	 * and only users who pass are returned, with that URL. For someone who
	 * is not a toolkit admin, administrators are left out of the query
	 * itself, since SEC-1 would refuse every one of them; the capability
	 * check still decides. Reads at most SWITCH_SEARCH_SCAN candidates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function search_switch_users( WP_REST_Request $request ) {
		$search = self::clean_switch_search( $request->get_param( 'search' ) );
		$actor  = wp_get_current_user();

		/**
		 * Filter the most users the toolbar "Switch to…" search returns (SW-20).
		 *
		 * @param int $limit Results. Default 20; at most 100 are returned.
		 */
		$limit = (int) apply_filters( 'blt_toolkit_switch_search_limit', self::SWITCH_SEARCH_LIMIT );
		$limit = max( 1, min( self::SWITCH_SEARCH_LIMIT_MAX, $limit ) );
		$batch = max( 25, $limit * 2 );

		$args = array(
			'search'         => '*' . $search . '*',
			'search_columns' => array( 'user_login', 'user_nicename', 'user_email', 'display_name' ),
			'orderby'        => 'display_name',
			'order'          => 'ASC',
			'exclude'        => array( (int) $actor->ID ),
			'number'         => $batch,
			'count_total'    => false,
			'fields'         => 'all_with_meta',
		);

		if ( ! BLT_Toolkit_Guard::is_toolkit_admin( $actor ) ) {
			$args['role__not_in'] = array( BLT_Toolkit_Guard::ADMIN_ROLE );
		}

		$found   = array();
		$total   = 0;
		$scanned = 0;

		while ( $total < $limit && $scanned < self::SWITCH_SEARCH_SCAN ) {
			$args['offset'] = $scanned;

			$users = ( new WP_User_Query( $args ) )->get_results();
			$read  = is_array( $users ) ? count( $users ) : 0;

			foreach ( (array) $users as $user ) {
				if ( $total >= $limit ) {
					break;
				}

				if ( ! $user instanceof WP_User ) {
					continue;
				}

				$url = BLT_Toolkit_Switch::maybe_switch_url( $user );

				if ( is_string( $url ) && '' !== $url ) {
					$found[] = self::switch_candidate( $user, $url );
					++$total;
				}
			}

			$scanned += $batch;

			if ( $read < $batch ) {
				break;
			}
		}

		return self::respond( $found );
	}

	/**
	 * One `/switch/users` result. Only what the menu shows: the display
	 * name, the username, the role names, the avatar (when the site shows
	 * avatars) and the switch URL, with `&` rather than `&amp;`.
	 *
	 * @param WP_User $user User.
	 * @param string  $url  Switch URL.
	 * @return array{id:int,name:string,login:string,roles:string[],avatar:string,url:string}
	 */
	private static function switch_candidate( WP_User $user, $url ) {
		$names = wp_roles()->role_names;
		$roles = array();

		foreach ( (array) $user->roles as $role ) {
			if ( isset( $names[ $role ] ) ) {
				$roles[] = translate_user_role( $names[ $role ] );
			}
		}

		return array(
			'id'     => (int) $user->ID,
			'name'   => (string) $user->display_name,
			'login'  => (string) $user->user_login,
			'roles'  => $roles,
			'avatar' => BLT_Toolkit_Switch_Ui::avatar( $user ),
			'url'    => BLT_Toolkit_Switch_Ui::raw_url( $url ),
		);
	}

	/**
	 * The `blt_toolkit_switch_url` field of a `user` response (SW-27): the
	 * switch URL, or null unless the current user may switch to that user.
	 *
	 * @param array $data The user as prepared for the response.
	 * @return string|null
	 */
	public static function switch_url_field( $data ) {
		if ( ! is_user_logged_in() || ! is_array( $data ) || empty( $data['id'] ) ) {
			return null;
		}

		$user = get_userdata( (int) $data['id'] );

		if ( ! $user instanceof WP_User ) {
			return null;
		}

		$url = BLT_Toolkit_Switch::maybe_switch_url( $user );

		return is_string( $url ) && '' !== $url ? BLT_Toolkit_Switch_Ui::raw_url( $url ) : null;
	}

	/**
	 * A response that no cache keeps.
	 *
	 * @param array $data Body.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data ) {
		$response = new WP_REST_Response( $data, 200 );

		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/**
	 * The error for a job that disappeared mid-request.
	 *
	 * @return WP_Error
	 */
	private static function job_gone() {
		return new WP_Error( 'blt_toolkit_job_not_found', __( 'That job no longer exists.', 'blt-toolkit' ), array( 'status' => 404 ) );
	}
}
