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
 */
class BLT_Toolkit_Rest {

	/**
	 * Route pattern of a job ID (BLT_Toolkit_Jobs IDs are 16 hex characters).
	 */
	const JOB_ID = '(?P<id>[a-f0-9]{16})';

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
