<?php
/**
 * Roles screen: the separate-permissions pages (isolate, revert, clean up)
 * and their admin-post.php handlers.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The flow pages of "Give {Type} separate permissions" (SPEC 10.5).
 *
 * The Roles screen (`admin.php?page=blt-toolkit-roles`) shows one of these
 * instead of the role editor when its URL carries `action=isolate`,
 * `revert` or `cleanup` with `type=X` (BLT_Toolkit_Admin_Roles calls
 * render()). Every page is a dry run that writes nothing (RO-5):
 *
 * - `isolate` (RS-30): confirm the singular and plural (a GET form), then
 *   the dry run per role and per user with Posts or Pages capabilities of
 *   their own, then a POST that isolates.
 * - `revert` (RS-32): what changes for whom, then a POST.
 * - `cleanup` (RS-32): the reverted type's leftover capabilities that are
 *   orphans now, who holds them, then a POST that removes them or keeps
 *   them.
 *
 * All of them need `blt_edit_capabilities` and `manage_options` (RS-30); a
 * page someone may not open answers 403 from the screen's `load-` hook,
 * before any output. The POSTs are routed here on admin-post.php only:
 * method (405), permission (403), nonce, then the handler, which calls
 * BLT_Toolkit_Isolation and redirects (SEC-7). Results come back as the
 * Roles screen's one-time notice (BLT_Toolkit_Admin_Role_Lifecycle's
 * transient, named by a token in the URL), so the role editor prints them
 * too and no message text travels in a URL.
 */
class BLT_Toolkit_Admin_Isolation {

	/**
	 * Query argument naming the page (shared with the lifecycle pages).
	 */
	const ARG = 'action';

	/**
	 * Query argument naming the post type.
	 */
	const TYPE_ARG = 'type';

	/**
	 * Query argument naming the role the person came from (to return to).
	 */
	const ROLE_ARG = 'role';

	/**
	 * Query argument naming the step of the isolate page (`preview`).
	 */
	const STEP_ARG = 'step';

	/**
	 * The pages.
	 */
	const VIEWS = array( 'isolate', 'revert', 'cleanup' );

	/**
	 * admin-post.php action => handler method.
	 */
	const ACTIONS = array(
		'blt_toolkit_isolation_isolate' => 'handle_isolate',
		'blt_toolkit_isolation_revert'  => 'handle_revert',
		'blt_toolkit_isolation_cleanup' => 'handle_cleanup',
	);

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	// ---------- Wiring ----------

	/**
	 * Called by BLT_Toolkit_Isolation::register() on admin requests. On
	 * admin-post.php it routes this flow's actions; elsewhere it hooks the
	 * pre-output permission check onto the Roles screen once the menu
	 * exists.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
			foreach ( array_keys( self::ACTIONS ) as $action ) {
				add_action( 'admin_post_' . $action, array( __CLASS__, 'route' ) );
			}

			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'hook_check' ), PHP_INT_MAX );
	}

	/**
	 * Hook check_access() on the Roles screen's `load-` hook. The hook name
	 * comes from WordPress (it depends on the translated menu title), so it
	 * is read once the menu is registered.
	 *
	 * @return void
	 */
	public static function hook_check() {
		$screens = BLT_Toolkit_Admin::screens();
		$hook    = get_plugin_page_hookname( $screens['roles']['slug'], BLT_Toolkit_Admin::MENU_SLUG );

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'check_access' ) );
		}
	}

	/**
	 * The page the current request asks for, or ''.
	 *
	 * @return string
	 */
	public static function requested_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses what to display; nothing is written.
		$view = isset( $_GET[ self::ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) : '';

		return in_array( $view, self::VIEWS, true ) ? $view : '';
	}

	/**
	 * Refuse a page the current user may not open with a real 403, before
	 * the admin header is sent.
	 *
	 * @return void
	 */
	public static function check_access() {
		if ( '' !== self::requested_view() && is_wp_error( BLT_Toolkit_Isolation::can_manage( wp_get_current_user() ) ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * A page's URL.
	 *
	 * @param string $view `isolate`, `revert` or `cleanup`.
	 * @param string $type Post type.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $view, $type, array $args = array() ) {
		return BLT_Toolkit_Admin::page_url(
			'roles',
			array_merge(
				array(
					self::ARG      => (string) $view,
					self::TYPE_ARG => (string) $type,
				),
				$args
			)
		);
	}

	// ---------- Rendering ----------

	/**
	 * Render a page as the whole Roles screen: `.wrap`, header, the one-time
	 * notice, then the page. Writes nothing (RO-5).
	 *
	 * @param string $view `isolate`, `revert` or `cleanup`.
	 * @return bool False when `$view` is not one of these (the caller draws
	 *              the role editor); true once drawn.
	 */
	public static function render( $view ) {
		if ( ! in_array( $view, self::VIEWS, true ) ) {
			return false;
		}

		if ( is_wp_error( BLT_Toolkit_Isolation::can_manage( wp_get_current_user() ) ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array( 'response' => 403 )
			);
		}

		$role      = self::url_role();
		$back_url  = self::back_url( $role );
		$module_on = BLT_Toolkit_Plugin::is_active( 'roles' );

		echo '<div class="wrap blt-ui blt-toolkit blt-toolkit-roles blt-toolkit-isolation">';

		BLT_Toolkit_Admin::header(
			BLT_Toolkit_Admin::screen_title( 'roles' ),
			array(
				array(
					'label' => __( 'Back to roles', 'blt-toolkit' ),
					'url'   => $back_url,
				),
			)
		);
		BLT_Toolkit_Admin::module_callout( 'roles' );

		if ( class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ) {
			BLT_Toolkit_Admin_Role_Lifecycle::print_notice();
		}

		if ( $module_on ) {
			$data = call_user_func( array( __CLASS__, 'data_' . $view ) );

			$data['back_url'] = $back_url;
			$data['role']     = $role;

			self::view( $view, $data );
		}

		echo '</div>';

		return true;
	}

	/**
	 * Include a page's template with its data as variables.
	 *
	 * @param string $view Page key.
	 * @param array  $data Variables for the template.
	 * @return void
	 */
	private static function view( $view, array $data ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, documented at the top of each view.
		extract( $data, EXTR_SKIP );

		require BLT_TOOLKIT_DIR . 'admin/views/isolation-' . $view . '.php';
	}

	/**
	 * Data for the isolate page (RS-30): the names form, or the dry run.
	 *
	 * @return array
	 */
	private static function data_isolate() {
		$type     = self::url_type();
		$object   = '' !== $type ? get_post_type_object( $type ) : null;
		$defaults = BLT_Toolkit_Isolation::default_names( $type );
		$singular = self::url_name( 'singular' );
		$plural   = self::url_name( 'plural' );
		$data     = array(
			'type'     => $type,
			'label'    => $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type,
			'state'    => 'form',
			'message'  => '',
			'field'    => '',
			'singular' => '' !== $singular ? $singular : $defaults['singular'],
			'plural'   => '' !== $plural ? $plural : $defaults['plural'],
			'plan'     => null,
			'rows'     => array(),
			'shares'   => 'post',
		);

		if ( ! $object instanceof WP_Post_Type ) {
			$data['state'] = 'missing';

			return $data;
		}

		if ( BLT_Toolkit_Isolation::is_isolated( $type ) ) {
			$data['state'] = 'isolated';

			return $data;
		}

		if ( 'page' === BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => $object->capability_type ) ) ) {
			$data['shares'] = 'page';
		}

		$check = BLT_Toolkit_Isolation::validate( $type, $defaults['singular'], $defaults['plural'] );

		if ( is_wp_error( $check ) && 'blt_toolkit_not_candidate' === $check->get_error_code() ) {
			$data['state']   = 'not_candidate';
			$data['message'] = $check->get_error_message();

			return $data;
		}

		if ( 'preview' !== self::url_value( self::STEP_ARG ) ) {
			return $data;
		}

		$plan = BLT_Toolkit_Isolation::plan( $type, $data['singular'], $data['plural'] );

		if ( is_wp_error( $plan ) ) {
			$error           = (array) $plan->get_error_data();
			$data['message'] = $plan->get_error_message();
			$data['field']   = isset( $error['field'] ) ? (string) $error['field'] : 'plural';

			return $data;
		}

		$data['state']  = 'preview';
		$data['plan']   = $plan;
		$data['shares'] = $plan['shares'];
		$data['rows']   = self::cap_rows( $plan );

		return $data;
	}

	/**
	 * The new capabilities of a plan for display: key => array( 'cap',
	 * 'label', 'from' ), in the order of the SPEC 10.4 table, then the
	 * per-item names.
	 *
	 * @param array $plan BLT_Toolkit_Isolation::plan().
	 * @return array[]
	 */
	private static function cap_rows( array $plan ) {
		$out = array();

		foreach ( array_merge( BLT_Toolkit_Isolation::LEVEL_KEYS, BLT_Toolkit_Isolation::META_KEYS ) as $key ) {
			if ( ! isset( $plan['new_caps'][ $key ] ) ) {
				continue;
			}

			$out[] = array(
				'cap'   => (string) $plan['new_caps'][ $key ],
				'label' => BLT_Toolkit_Capability_Catalog::content_label( $key, $plan['label'] ),
				'from'  => isset( $plan['old_caps'][ $key ] ) ? (string) $plan['old_caps'][ $key ] : '',
				'meta'  => in_array( $key, BLT_Toolkit_Isolation::META_KEYS, true ),
			);
		}

		return $out;
	}

	/**
	 * Data for the revert page (RS-32).
	 *
	 * @return array
	 */
	private static function data_revert() {
		$type = self::url_type();
		$plan = '' !== $type ? BLT_Toolkit_Isolation::revert_plan( $type ) : new WP_Error( 'blt_toolkit_not_isolated', __( 'This content type does not have separate permissions.', 'blt-toolkit' ) );

		return array(
			'type'    => $type,
			'plan'    => is_wp_error( $plan ) ? null : $plan,
			'message' => is_wp_error( $plan ) ? $plan->get_error_message() : '',
		);
	}

	/**
	 * Data for the cleanup page (RS-32's separate step).
	 *
	 * @return array
	 */
	private static function data_cleanup() {
		$type = self::url_type();
		$plan = '' !== $type ? BLT_Toolkit_Isolation::cleanup_plan( $type ) : new WP_Error( 'blt_toolkit_nothing_to_clean', __( 'There is nothing left to clean up for this content type.', 'blt-toolkit' ) );

		return array(
			'type'    => $type,
			'plan'    => is_wp_error( $plan ) ? null : $plan,
			'code'    => is_wp_error( $plan ) ? $plan->get_error_code() : '',
			'message' => is_wp_error( $plan ) ? $plan->get_error_message() : '',
		);
	}

	/**
	 * A capability's display label: the catalog's when it knows it,
	 * otherwise humanized.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function cap_label( $cap ) {
		return BLT_Toolkit_Capability_Catalog::label( (string) $cap );
	}

	// ---------- Handling ----------

	/**
	 * Route one of ACTIONS as BLT_Toolkit_Admin::route_post() does: a POST
	 * (405 otherwise), the permission (403), the nonce, then the handler,
	 * which redirects.
	 *
	 * @return void
	 */
	public static function route() {
		$action = substr( current_action(), strlen( 'admin_post_' ) );

		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			wp_die(
				esc_html__( 'This action needs a form submission.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 405,
					'back_link' => true,
				)
			);
		}

		if ( is_wp_error( BLT_Toolkit_Isolation::can_manage( wp_get_current_user() ) ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		check_admin_referer( $action, BLT_Toolkit_Admin::NONCE_FIELD );

		$role = self::posted_role();

		if ( ! BLT_Toolkit_Plugin::is_active( 'roles' ) ) {
			self::back( self::back_url( $role ), 'error', __( 'Nothing was changed: the Roles module is off.', 'blt-toolkit' ) );
		}

		call_user_func( array( __CLASS__, self::ACTIONS[ $action ] ), $role );

		self::back( self::back_url( $role ), 'error', __( 'Nothing was changed: the request could not be handled.', 'blt-toolkit' ) );
	}

	/**
	 * POST: give a type separate permissions (RS-30 step 3).
	 *
	 * @param string $role Role to return to.
	 * @return void
	 */
	public static function handle_isolate( $role ) {
		$type     = self::posted_type();
		$singular = self::posted_name( 'singular' );
		$plural   = self::posted_name( 'plural' );
		$preview  = self::url(
			'isolate',
			$type,
			array_filter(
				array(
					self::STEP_ARG => 'preview',
					'singular'     => $singular,
					'plural'       => $plural,
					self::ROLE_ARG => $role,
				)
			)
		);

		$result = BLT_Toolkit_Isolation::isolate( $type, $singular, $plural, array( 'expected' => self::posted( 'hash' ) ) );

		if ( is_wp_error( $result ) ) {
			self::back( $preview, 'blt_toolkit_isolation_changed' === $result->get_error_code() ? 'warning' : 'error', self::error_text( $result ) );
		}

		$object = get_post_type_object( $type );
		$label  = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;
		$items  = array();

		foreach ( $result['user_errors'] as $login => $message ) {
			/* translators: 1: username. 2: the reason. */
			$items[] = sprintf( __( '%1$s was not updated: %2$s', 'blt-toolkit' ), $login, $message );
		}

		self::back(
			self::back_url( $role, array( 'view' => 'simple' ) ),
			array() === $items ? 'success' : 'warning',
			sprintf(
				/* translators: 1: plural content type name, such as Events. 2: number of roles. 3: number of users. */
				__( '%1$s now have separate permissions. Nobody\'s access changed: %2$s roles and %3$s users with permissions of their own received the matching capabilities.', 'blt-toolkit' ),
				$label,
				number_format_i18n( (int) $result['roles'] ),
				number_format_i18n( (int) $result['users'] )
			),
			$items
		);
	}

	/**
	 * POST: revert a type to sharing Posts' permissions (RS-32), then show
	 * the separate cleanup step.
	 *
	 * @param string $role Role to return to.
	 * @return void
	 */
	public static function handle_revert( $role ) {
		$type   = self::posted_type();
		$object = get_post_type_object( $type );
		$label  = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;
		$result = BLT_Toolkit_Isolation::revert( $type );

		if ( is_wp_error( $result ) ) {
			self::back( self::url( 'revert', $type, array_filter( array( self::ROLE_ARG => $role ) ) ), 'error', self::error_text( $result ) );
		}

		self::back(
			self::url( 'cleanup', $type, array_filter( array( self::ROLE_ARG => $role ) ) ),
			'success',
			sprintf(
				/* translators: %s: plural content type name, such as Events. */
				__( '%s share Posts\' permissions again from the next page load. Their own capabilities are still on roles; review them below.', 'blt-toolkit' ),
				$label
			)
		);
	}

	/**
	 * POST: the cleanup step's decision (RS-32). `remove` takes a reverted
	 * type's leftover capabilities off roles and users; `keep` leaves them
	 * (they stay flagged as orphans in Advanced view, RO-11, where RO-24 can
	 * delete each one) and forgets the step.
	 *
	 * @param string $role Role to return to.
	 * @return void
	 */
	public static function handle_cleanup( $role ) {
		$type = self::posted_type();

		if ( 'remove' !== self::posted( 'decision' ) ) {
			BLT_Toolkit_Isolation::forget_reverted( $type );

			self::back( self::back_url( $role ), 'success', __( 'The capabilities were kept. They are listed under Orphaned in Advanced view, where each can be deleted later.', 'blt-toolkit' ) );
		}

		$result = BLT_Toolkit_Isolation::cleanup( $type, array( 'expected' => self::posted( 'hash' ) ) );

		if ( is_wp_error( $result ) ) {
			self::back( self::url( 'cleanup', $type, array_filter( array( self::ROLE_ARG => $role ) ) ), 'blt_toolkit_isolation_changed' === $result->get_error_code() ? 'warning' : 'error', self::error_text( $result ) );
		}

		$items = array();

		foreach ( $result['user_errors'] as $login => $message ) {
			/* translators: 1: username. 2: the reason. */
			$items[] = sprintf( __( '%1$s was not updated: %2$s', 'blt-toolkit' ), $login, $message );
		}

		self::back(
			self::back_url( $role ),
			array() === $items ? 'success' : 'warning',
			sprintf(
				/* translators: 1: number of roles. 2: number of users. */
				__( 'Removed the unused capabilities from %1$s roles and %2$s users.', 'blt-toolkit' ),
				number_format_i18n( (int) $result['roles'] ),
				number_format_i18n( (int) $result['users'] )
			),
			$items
		);
	}

	// ---------- Helpers ----------

	/**
	 * Where "Back to roles" goes: the role the person came from, or the
	 * Roles screen.
	 *
	 * @param string $role Role ID, or ''.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	private static function back_url( $role, array $args = array() ) {
		if ( '' !== $role && wp_roles()->is_role( $role ) ) {
			$args = array_merge( array( self::ROLE_ARG => $role ), $args );
		}

		return BLT_Toolkit_Admin::page_url( 'roles', $args );
	}

	/**
	 * Store the one-time notice the Roles screen prints, then redirect and
	 * stop. Uses BLT_Toolkit_Admin_Role_Lifecycle's notice, so the role
	 * editor shows it too.
	 *
	 * @param string $url     Where to.
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 * @param array  $items   Optional list under the message.
	 * @return void
	 */
	private static function back( $url, $type, $message, array $items = array() ) {
		$token = strtolower( wp_generate_password( 12, false ) );

		set_transient(
			BLT_Toolkit_Admin_Role_Lifecycle::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'token'   => $token,
				'type'    => $type,
				'message' => (string) $message,
				'items'   => array_values( array_map( 'strval', $items ) ),
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( BLT_Toolkit_Admin_Role_Lifecycle::NOTICE_ARG, $token, $url ) );
		exit;
	}

	/**
	 * An error's message for the notice, prefixed so it is clear nothing
	 * was changed.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private static function error_text( WP_Error $error ) {
		return sprintf(
			/* translators: %s: the reason. */
			__( 'Nothing was changed: %s', 'blt-toolkit' ),
			$error->get_error_message()
		);
	}

	/**
	 * A post type key: what sanitize_key() keeps, at most 20 characters
	 * (register_post_type()'s limit).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_type( $value ) {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return strlen( $value ) <= 20 ? $value : '';
	}

	/**
	 * A singular or plural as typed: lowercased and trimmed. Validated by
	 * BLT_Toolkit_Isolation::name_error() before use.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_name( $value ) {
		$value = is_scalar( $value ) ? strtolower( trim( sanitize_text_field( (string) $value ) ) ) : '';

		return substr( $value, 0, 100 );
	}

	/**
	 * A role ID from a request: the characters role IDs use.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function clean_role( $value ) {
		return is_scalar( $value ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ), 0, 64 ) : '';
	}

	/**
	 * A raw query argument, unslashed.
	 *
	 * @param string $key Argument.
	 * @return string
	 */
	private static function url_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Display only; each caller sanitizes.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : '';
	}

	/**
	 * The post type the URL names.
	 *
	 * @return string
	 */
	private static function url_type() {
		return self::clean_type( self::url_value( self::TYPE_ARG ) );
	}

	/**
	 * A name the URL carries (`singular` or `plural`).
	 *
	 * @param string $key Argument.
	 * @return string
	 */
	private static function url_name( $key ) {
		return self::clean_name( self::url_value( $key ) );
	}

	/**
	 * The role the URL says the person came from.
	 *
	 * @return string
	 */
	private static function url_role() {
		return self::clean_role( self::url_value( self::ROLE_ARG ) );
	}

	/**
	 * A posted field, unslashed. Only called after route() checked the
	 * nonce.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- route() checked the nonce; each caller sanitizes.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * The posted post type.
	 *
	 * @return string
	 */
	private static function posted_type() {
		return self::clean_type( self::posted( self::TYPE_ARG ) );
	}

	/**
	 * A posted name.
	 *
	 * @param string $key `singular` or `plural`.
	 * @return string
	 */
	private static function posted_name( $key ) {
		return self::clean_name( self::posted( $key ) );
	}

	/**
	 * The posted role to return to.
	 *
	 * @return string
	 */
	private static function posted_role() {
		return self::clean_role( self::posted( self::ROLE_ARG ) );
	}
}
