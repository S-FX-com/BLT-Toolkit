<?php
/**
 * Roles screen: the role editor (role list, role header, Simple and Advanced
 * tabs, the capability matrix, per-user capabilities) and its admin-post.php
 * handlers.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Roles screen (SPEC 9.3, 13.2), reached from BLT Toolkit > Roles and
 * from Users > Roles (D4). The screen needs `blt_edit_roles`; Advanced view,
 * per-user capabilities and adding or deleting capabilities need
 * `blt_edit_capabilities` (SPEC 9.3, 5.1).
 *
 * What the URL asks for (every view is one URL, CONVENTIONS 6.4):
 *
 * - `action=add|rename|move|delete|merge`: a role lifecycle page, drawn by
 *   BLT_Toolkit_Admin_Role_Lifecycle::render().
 * - `role=ID&view=simple|advanced`: the role editor. The left column lists
 *   the roles and, in Advanced, the capability group tree (`group=`); the
 *   toolbar's quick filter, "Granted only", "Show deprecated", "Readable
 *   names" and columns are GET fields (RO-22, RO-26).
 * - `user_id=N&view=advanced`: the same matrix for one user, with Inherit,
 *   Grant or Deny per capability (US-10).
 * - `step=review|conflict|compare|delete_cap`: the SEC-10 / SEC-13 review
 *   before a save, the RO-2 conflict after a refused save, compare two roles
 *   (RO-25), delete an orphaned capability (RO-24).
 *
 * Rendering writes no role (RO-5). The only write on a page view is the
 * viewer's own display preference (`blt_toolkit_editor_mode`,
 * `blt_toolkit_columns`) when the URL changes it, as core remembers a list's
 * view mode (see tasks/lessons.md).
 *
 * Every form posts to admin-post.php: the method, the action's capability
 * and the nonce are checked first (route()), then a handler validates its
 * fields, writes through BLT_Toolkit_Role_Store (roles) or
 * BLT_Toolkit_User_Caps (one user), and redirects back (SEC-7). A capability
 * form names the capabilities that were on the page and carries the role's
 * lock hash from render time, so a save only changes what was shown and a
 * stale page is refused with the difference (RO-1, RO-2). Results come back
 * through the lifecycle screen's one-time notice, so the Guard's exact
 * reasons reach the person and the URL carries no message text.
 */
class BLT_Toolkit_Admin_Roles {

	/**
	 * Script handle of assets/js/blt-toolkit-roles.js.
	 */
	const SCRIPT_HANDLE = 'blt-toolkit-roles';

	/**
	 * User meta: the person's view, `simple` or `advanced` (RS-1, SPEC 14.4).
	 */
	const MODE_META = 'blt_toolkit_editor_mode';

	/**
	 * User meta: how many columns the Advanced matrix uses, 1 to 3 (RO-22).
	 */
	const COLUMNS_META = 'blt_toolkit_columns';

	/**
	 * Transient prefix (plus the user ID) of a pending review or a refused
	 * save, one per person, named by a random token in the URL.
	 */
	const PENDING_TRANSIENT = 'blt_toolkit_roles_pending_';

	/**
	 * Query and form field carrying a pending review's token.
	 */
	const PENDING_ARG = 'blt_rv';

	/**
	 * Field of the SEC-10 typed confirmation.
	 */
	const CONFIRM_FIELD = 'confirm_name';

	/**
	 * Field of the SEC-13 confirmation.
	 */
	const LOCKOUT_FIELD = 'confirm_lockout';

	/**
	 * Last field of every capability form. PHP drops whatever follows its
	 * `max_input_vars`th field, so a form that arrives without it was cut
	 * short and is refused rather than read as unchecked boxes.
	 */
	const END_FIELD = 'blt_end';

	/**
	 * A save changing more capabilities than this is reviewed first, as
	 * Simple view's RS-6 does.
	 */
	const REVIEW_THRESHOLD = 10;

	/**
	 * "Test as…" offers at most this many users (X-3).
	 */
	const TEST_AS_LIMIT = 20;

	/**
	 * "Test as…" reads at most this many holders of the role to find them.
	 */
	const TEST_AS_SCAN = 200;

	/**
	 * Longest quick filter, in characters.
	 */
	const SEARCH_MAX = 100;

	/**
	 * How long a pending review or refused save is kept.
	 */
	const PENDING_TTL = HOUR_IN_SECONDS;

	/**
	 * WordPress's own roles (the Built-in badge).
	 */
	const BUILTIN = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * Sub-pages of the editor (the `step` query argument).
	 */
	const STEPS = array( 'review', 'conflict', 'compare', 'delete_cap' );

	/**
	 * Query arguments the editor's own links carry, in URL order. `ctl`
	 * narrows Advanced view to one Simple control's capabilities (RS-4's
	 * "Edit in Advanced").
	 */
	const STATE_ARGS = array( 'role', 'user_id', 'view', 'group', 'ctl', 's', 'granted', 'deprecated', 'readable' );

	/**
	 * Role-editor pages of the separate-permissions flow (RS-30 to RS-32),
	 * drawn by BLT_Toolkit_Admin_Isolation (the `action` query argument).
	 */
	const ISOLATION_VIEWS = array( 'isolate', 'revert', 'cleanup' );

	/**
	 * Field of a Simple form that says the script showed the review (RS-6).
	 */
	const REVIEWED_FIELD = 'reviewed';

	/**
	 * admin-post.php action => array( handler method, capability ).
	 */
	const ACTIONS = array(
		'blt_toolkit_roles_simple_save' => array( 'handle_simple_save', 'blt_edit_roles' ),
		'blt_toolkit_roles_save'        => array( 'handle_save', 'blt_edit_roles' ),
		'blt_toolkit_roles_user_caps'   => array( 'handle_user_caps', 'blt_edit_capabilities' ),
		'blt_toolkit_roles_add_cap'     => array( 'handle_add_cap', 'blt_edit_capabilities' ),
		'blt_toolkit_roles_delete_cap'  => array( 'handle_delete_cap', 'blt_edit_capabilities' ),
		'blt_toolkit_roles_details'     => array( 'handle_details', 'blt_edit_roles' ),
		'blt_toolkit_roles_test_as'     => array( 'handle_test_as', 'blt_edit_roles' ),
	);

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	// ---------- Wiring ----------

	/**
	 * Called by the Roles module on admin requests. On admin-post.php it
	 * routes the screen's actions, and the lifecycle pages', to route(),
	 * unless BLT_Toolkit_Admin's own router already lists them. Any other
	 * request adds nothing, so the screen costs no hook on other pages.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( ! isset( $GLOBALS['pagenow'] ) || 'admin-post.php' !== $GLOBALS['pagenow'] ) {
			return;
		}

		$routed = class_exists( 'BLT_Toolkit_Admin' ) ? BLT_Toolkit_Admin::post_actions() : array();

		foreach ( array_keys( self::post_actions() ) as $action ) {
			if ( ! isset( $routed[ $action ] ) ) {
				add_action( 'admin_post_' . $action, array( __CLASS__, 'route' ) );
			}
		}
	}

	/**
	 * Every admin-post.php action of the Roles screen, the lifecycle pages'
	 * included, in BLT_Toolkit_Admin::post_actions()'s format.
	 *
	 * @return array<string,array{screen:string,method:string,cap:string}>
	 */
	public static function post_actions() {
		$out = array();

		foreach ( self::ACTIONS as $action => $route ) {
			$out[ $action ] = array(
				'screen' => 'roles',
				'method' => $route[0],
				'cap'    => $route[1],
			);
		}

		if ( class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ) {
			$out = array_merge( $out, BLT_Toolkit_Admin_Role_Lifecycle::post_actions() );
		}

		return $out;
	}

	/**
	 * Route one of post_actions() as BLT_Toolkit_Admin::route_post() does:
	 * a POST (405 otherwise), the action's capability (403), the nonce, then
	 * the handler, which ends with a redirect.
	 *
	 * @return void
	 */
	public static function route() {
		$action  = substr( current_action(), strlen( 'admin_post_' ) );
		$actions = self::post_actions();

		if ( ! isset( $actions[ $action ] ) ) {
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

		if ( ! current_user_can( $actions[ $action ]['cap'] ) ) {
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

		$screen = BLT_Toolkit_Admin::instance()->screen( 'roles' );

		call_user_func( array( $screen, $actions[ $action ]['method'] ) );

		BLT_Toolkit_Admin::redirect( 'roles', array(), 'failed' );
	}

	/**
	 * A role lifecycle POST (add, rename, move, delete, merge): handed to
	 * BLT_Toolkit_Admin_Role_Lifecycle::handle(), which redirects.
	 *
	 * @return void
	 */
	public function handle_lifecycle() {
		BLT_Toolkit_Admin_Role_Lifecycle::handle();
	}

	// ---------- URLs ----------

	/**
	 * The role editor's URL for a role (or the screen when '').
	 *
	 * @param string $role Role ID.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function role_url( $role = '', array $args = array() ) {
		if ( '' !== (string) $role ) {
			$args = array_merge( array( 'role' => (string) $role ), $args );
		}

		return BLT_Toolkit_Admin::page_url( 'roles', $args );
	}

	/**
	 * The URL of a user's capabilities (US-10): the Roles screen in user
	 * mode. The profile's "Capabilities" link points here.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function user_url( $user_id ) {
		return BLT_Toolkit_Admin::page_url(
			'roles',
			array(
				'user_id' => absint( $user_id ),
				'view'    => 'advanced',
			)
		);
	}

	/**
	 * The editor URL for a state, with overrides (null drops an argument).
	 *
	 * @param array $state     state().
	 * @param array $overrides Argument => value or null.
	 * @return string
	 */
	private static function state_url( array $state, array $overrides = array() ) {
		return BLT_Toolkit_Admin::page_url( 'roles', self::state_args( $state, $overrides ) );
	}

	/**
	 * The query arguments that reproduce a state, with overrides. Pure apart
	 * from nothing: the state is plain data.
	 *
	 * @param array $state     state().
	 * @param array $overrides Argument => value, or null to drop it.
	 * @return array<string,string>
	 */
	public static function state_args( array $state, array $overrides = array() ) {
		$args = array();

		if ( ! empty( $state['user_id'] ) ) {
			$args['user_id'] = (string) (int) $state['user_id'];
		} elseif ( isset( $state['role'] ) && '' !== $state['role'] ) {
			$args['role'] = (string) $state['role'];
		}

		if ( isset( $state['view'] ) && '' !== $state['view'] ) {
			$args['view'] = (string) $state['view'];
		}

		if ( isset( $state['group'] ) && '' !== $state['group'] && 'all' !== $state['group'] ) {
			$args['group'] = (string) $state['group'];
		}

		if ( isset( $state['ctl'] ) && '' !== $state['ctl'] ) {
			$args['ctl'] = (string) $state['ctl'];
		}

		if ( isset( $state['search'] ) && '' !== $state['search'] ) {
			$args['s'] = (string) $state['search'];
		}

		if ( ! empty( $state['granted'] ) ) {
			$args['granted'] = '1';
		}

		foreach ( array( 'deprecated', 'readable' ) as $key ) {
			if ( isset( $state[ $key . '_arg' ] ) && '' !== $state[ $key . '_arg' ] ) {
				$args[ $key ] = (string) $state[ $key . '_arg' ];
			}
		}

		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $args[ $key ] );
			} else {
				$args[ $key ] = (string) $value;
			}
		}

		return $args;
	}

	/**
	 * Return arguments posted with a form (the `back` field), reduced to the
	 * editor's own arguments with safe values. Pure.
	 *
	 * @param array $raw Parsed query arguments.
	 * @return array<string,string>
	 */
	public static function clean_back( array $raw ) {
		$out = array();

		foreach ( self::STATE_ARGS as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $raw[ $key ] );

			switch ( $key ) {
				case 'role':
					$value = self::clean_role( $value );
					break;
				case 'user_id':
					$value = (string) max( 0, (int) $value );
					$value = '0' === $value ? '' : $value;
					break;
				case 'view':
					$value = in_array( $value, array( 'simple', 'advanced' ), true ) ? $value : '';
					break;
				case 'group':
				case 'ctl':
					$value = self::clean_group( $value );
					break;
				case 's':
					$value = self::clean_search( $value );
					break;
				default:
					$value = in_array( $value, array( '0', '1' ), true ) ? $value : '';
			}

			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	// ---------- Request state ----------

	/**
	 * What the URL asks the editor to show, resolved against the person's
	 * preferences and the settings.
	 *
	 * @return array
	 */
	private function state() {
		$can_advanced = self::can_advanced();
		$view_arg     = self::arg( 'view' );
		$view_arg     = in_array( $view_arg, array( 'simple', 'advanced' ), true ) ? $view_arg : '';
		$user_id      = absint( self::arg( 'user_id' ) );
		$deprecated   = self::arg( 'deprecated' );
		$readable     = self::arg( 'readable' );
		$cols_arg     = self::arg( 'cols' );
		$step         = self::arg( 'step' );

		if ( '' !== $view_arg ) {
			$view = $view_arg;
		} else {
			$stored = (string) get_user_meta( get_current_user_id(), self::MODE_META, true );
			$view   = in_array( $stored, array( 'simple', 'advanced' ), true ) ? $stored : (string) BLT_Toolkit_Settings::get( 'roles', 'default_view' );
		}

		if ( ! $can_advanced ) {
			$view = 'simple';
		} elseif ( $user_id > 0 ) {
			// US-10: user mode is Advanced only.
			$view = 'advanced';
		}

		$view = 'advanced' === $view ? 'advanced' : 'simple';

		$deprecated = in_array( $deprecated, array( '0', '1' ), true ) ? $deprecated : '';
		$readable   = in_array( $readable, array( '0', '1' ), true ) ? $readable : '';
		$cols_arg   = in_array( $cols_arg, array( '1', '2', '3' ), true ) ? $cols_arg : '';
		$cols       = '' !== $cols_arg ? (int) $cols_arg : (int) get_user_meta( get_current_user_id(), self::COLUMNS_META, true );

		return array(
			'role'           => self::resolve_role( self::clean_role( self::arg( 'role' ) ) ),
			'user_id'        => $user_id,
			'view'           => $view,
			'view_arg'       => $view_arg,
			'can_advanced'   => $can_advanced,
			'group'          => '' !== self::clean_group( self::arg( 'group' ) ) ? self::clean_group( self::arg( 'group' ) ) : 'all',
			'ctl'            => self::clean_group( self::arg( 'ctl' ) ),
			'search'         => self::clean_search( self::arg( 's' ) ),
			'granted'        => '1' === self::arg( 'granted' ),
			'deprecated'     => '' !== $deprecated ? '1' === $deprecated : (bool) BLT_Toolkit_Settings::get( 'roles', 'show_deprecated' ),
			'deprecated_arg' => $deprecated,
			'readable'       => '' !== $readable ? '1' === $readable : (bool) BLT_Toolkit_Settings::get( 'roles', 'readable_labels' ),
			'readable_arg'   => $readable,
			'cols'           => max( 1, min( 3, $cols ) ),
			'cols_arg'       => $cols_arg,
			'step'           => in_array( $step, self::STEPS, true ) ? $step : '',
		);
	}

	/**
	 * Remember the view and the column count the URL chose (RS-1, RO-22):
	 * the person's own display preferences, written only when they change.
	 * Never a role (RO-5).
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function store_preferences( array $state ) {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		if ( '' !== $state['view_arg'] && $state['view_arg'] === $state['view'] && empty( $state['user_id'] ) && (string) get_user_meta( $user_id, self::MODE_META, true ) !== $state['view'] ) {
			update_user_meta( $user_id, self::MODE_META, $state['view'] );
		}

		if ( '' !== $state['cols_arg'] && (int) get_user_meta( $user_id, self::COLUMNS_META, true ) !== (int) $state['cols_arg'] ) {
			update_user_meta( $user_id, self::COLUMNS_META, (int) $state['cols_arg'] );
		}
	}

	/**
	 * The role to open: the one asked for when it exists, otherwise the first
	 * role the person may change, otherwise the first role.
	 *
	 * @param string $role Role asked for.
	 * @return string
	 */
	private static function resolve_role( $role ) {
		$roles = (array) wp_roles()->roles;

		if ( '' !== $role && isset( $roles[ $role ] ) ) {
			return $role;
		}

		$actor = wp_get_current_user();

		foreach ( array_keys( $roles ) as $id ) {
			if ( true === BLT_Toolkit_Guard::check_role_change( $actor, (string) $id, array(), array() ) ) {
				return (string) $id;
			}
		}

		$first = array_keys( $roles );

		return isset( $first[0] ) ? (string) $first[0] : '';
	}

	// ---------- Rendering ----------

	/**
	 * Render the screen: a lifecycle page when the URL names one, otherwise
	 * the role editor or one of its steps.
	 *
	 * @return void
	 */
	public function render() {
		if ( class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ) {
			$sub_view = BLT_Toolkit_Admin_Role_Lifecycle::requested_view();

			if ( '' !== $sub_view && BLT_Toolkit_Admin_Role_Lifecycle::render( $sub_view ) ) {
				return;
			}
		}

		// RS-30 to RS-32: "Give {Type} separate permissions", revert and
		// clean up, linked from Simple view's content rows.
		$isolation = self::isolation_view();

		if ( '' !== $isolation && false !== BLT_Toolkit_Admin_Isolation::render( $isolation ) ) {
			return;
		}

		$module_on = self::module_on();
		$state     = $module_on ? $this->state() : array();

		if ( $module_on ) {
			$this->store_preferences( $state );

			// RO-21: the matrix names each capability's Simple control.
			if ( class_exists( 'BLT_Toolkit_Simple_Controls' ) && class_exists( 'BLT_Toolkit_Simple_Mapper' ) ) {
				BLT_Toolkit_Simple_Controls::register_provider();
			}
		}

		echo '<div class="wrap blt-ui blt-ui-wide blt-toolkit blt-toolkit-roles">';

		BLT_Toolkit_Admin::header( BLT_Toolkit_Admin::screen_title( 'roles' ), $module_on ? $this->header_actions( $state ) : array() );
		BLT_Toolkit_Admin::module_callout( 'roles' );

		if ( class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ) {
			BLT_Toolkit_Admin_Role_Lifecycle::print_notice();
		}

		if ( $module_on ) {
			$this->render_body( $state );
		}

		echo '</div>';
	}

	/**
	 * Whether the Roles module runs, with the engine loaded.
	 *
	 * @return bool
	 */
	private static function module_on() {
		return BLT_Toolkit_Plugin::is_active( 'roles' ) && class_exists( 'BLT_Toolkit_Role_Store' ) && class_exists( 'BLT_Toolkit_Capability_Catalog' );
	}

	/**
	 * The page header's buttons: Delete roles and Compare roles.
	 *
	 * @param array $state state().
	 * @return array[]
	 */
	private function header_actions( array $state ) {
		$actions = array();

		if ( class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ) {
			foreach ( BLT_Toolkit_Admin_Role_Lifecycle::links( '' ) as $link ) {
				if ( 'delete' === $link['view'] ) {
					$actions[] = array(
						'label' => $link['label'],
						'url'   => $link['url'],
					);
				}
			}
		}

		if ( $state['can_advanced'] ) {
			$actions[] = array(
				'label' => __( 'Compare roles', 'blt-toolkit' ),
				'url'   => BLT_Toolkit_Admin::page_url(
					'roles',
					array(
						'step' => 'compare',
						'a'    => $state['role'],
					)
				),
			);
		}

		return $actions;
	}

	/**
	 * Everything below the page header.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_body( array $state ) {
		switch ( $state['step'] ) {
			case 'review':
				$this->render_review( $state );
				return;
			case 'conflict':
				$this->render_conflict( $state );
				return;
			case 'compare':
				$this->render_compare( $state );
				return;
			case 'delete_cap':
				$this->render_delete_cap( $state );
				return;
		}

		if ( $state['user_id'] > 0 ) {
			$this->render_user( $state );
			return;
		}

		$this->render_editor( $state );
	}

	/**
	 * The role editor: role list, role header, the view, role details.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_editor( array $state ) {
		$role = $state['role'];

		if ( '' === $role ) {
			self::view(
				'roles-message',
				array(
					'title'    => __( 'There are no roles', 'blt-toolkit' ),
					'text'     => __( 'This site has no roles. Add one to get started.', 'blt-toolkit' ),
					'back_url' => '',
				)
			);
			return;
		}

		$actor    = wp_get_current_user();
		$editable = BLT_Toolkit_Guard::check_role_change( $actor, $role, array(), array() );
		$advanced = 'advanced' === $state['view'];
		$matrix   = $advanced ? $this->role_matrix( $state, $editable ) : null;
		$simple   = $advanced ? null : $this->simple_data( $state, $editable );

		self::enqueue();

		self::view(
			'roles',
			array(
				'state'    => $state,
				'roles'    => $this->role_list( $state ),
				'add_url'  => $this->add_role_url( $state ),
				'tree'     => $advanced ? $this->tree_data( $state, null !== $matrix ? $matrix['granted'] : array() ) : array(),
				'header'   => $this->role_header( $state, $editable ),
				'tabs'     => $this->view_tabs( $state ),
				'matrix'   => $matrix,
				'simple'   => $simple,
				'add_cap'  => $advanced ? $this->add_cap_data( $state ) : null,
				'details'  => $this->details_data( $state, $editable ),
				'editable' => $editable,
			)
		);
	}

	/**
	 * The role list (SPEC 13.2): each role with its name, user count, badges
	 * (Built-in, Default, Has Critical capabilities) and description.
	 *
	 * @param array $state state().
	 * @return array[]
	 */
	private function role_list( array $state ) {
		$counts   = self::user_counts();
		$defaults = self::default_roles();
		$actor    = wp_get_current_user();
		$out      = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$id       = (string) $id;
			$caps     = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$critical = false;

			foreach ( $caps as $cap => $value ) {
				if ( $value && BLT_Toolkit_Caps::CRITICAL === BLT_Toolkit_Caps::tier( (string) $cap ) ) {
					$critical = true;
					break;
				}
			}

			$out[] = array(
				'id'          => $id,
				'name'        => self::role_name( $id ),
				'users'       => null === $counts ? null : ( isset( $counts[ $id ] ) ? $counts[ $id ] : 0 ),
				'builtin'     => in_array( $id, self::BUILTIN, true ),
				'default'     => in_array( $id, $defaults, true ),
				'critical'    => $critical,
				'description' => BLT_Toolkit_Roles::description_of( $id ),
				'read_only'   => true !== BLT_Toolkit_Guard::check_role_change( $actor, $id, array(), array() ),
				'current'     => empty( $state['user_id'] ) && $id === $state['role'],
				'url'         => self::state_url(
					$state,
					array(
						'role'    => $id,
						'user_id' => null,
					)
				),
			);
		}

		return $out;
	}

	/**
	 * The Add role link (RO-30), opening the new role in the current view,
	 * or '' when the person may not add roles.
	 *
	 * @param array $state state().
	 * @return string
	 */
	private function add_role_url( array $state ) {
		if ( ! class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ) {
			return '';
		}

		foreach ( BLT_Toolkit_Admin_Role_Lifecycle::links( '' ) as $link ) {
			if ( 'add' === $link['view'] ) {
				return add_query_arg( BLT_Toolkit_Admin_Role_Lifecycle::VIEW_ARG, $state['view'], $link['url'] );
			}
		}

		return '';
	}

	/**
	 * The role header (SPEC 13.2): name, ID, user count with a link to the
	 * filtered Users list, description, lifecycle links and Test as… (X-3).
	 *
	 * @param array         $state    state().
	 * @param true|WP_Error $editable Whether the person may change the role.
	 * @return array
	 */
	private function role_header( array $state, $editable ) {
		$role   = $state['role'];
		$counts = self::user_counts();
		$users  = null === $counts ? null : ( isset( $counts[ $role ] ) ? $counts[ $role ] : 0 );
		$links  = class_exists( 'BLT_Toolkit_Admin_Role_Lifecycle' ) ? BLT_Toolkit_Admin_Role_Lifecycle::links( $role ) : array();
		$actor  = wp_get_current_user();

		// SEC-6: the Users list shows such a person no administrator, so no
		// link leads there for the role.
		$users_url = current_user_can( 'list_users' ) && ! ( BLT_Toolkit_Guard::is_protected_role( $role ) && BLT_Toolkit_Guard::hides_admins_from( $actor ) )
			? add_query_arg( 'role', $role, admin_url( 'users.php' ) )
			: '';

		foreach ( $links as $index => $link ) {
			if ( 'rename' === $link['view'] && true !== $editable && ! BLT_Toolkit_Guard::is_toolkit_admin( wp_get_current_user() ) ) {
				unset( $links[ $index ] );
			}
		}

		return array(
			'id'          => $role,
			'name'        => self::role_name( $role ),
			'users'       => $users,
			'users_url'   => $users_url,
			'description' => BLT_Toolkit_Roles::description_of( $role ),
			'links'       => array_values( $links ),
			'test_as'     => $this->test_as_data( $role, $users ),
			'compare_url' => $state['can_advanced'] ? BLT_Toolkit_Admin::page_url(
				'roles',
				array(
					'step' => 'compare',
					'a'    => $role,
				)
			) : '',
		);
	}

	/**
	 * "Test as…" (X-3): up to 20 users holding the role whom the person may
	 * switch to, each checked with BLT_Toolkit_Switch::maybe_switch_url()
	 * (SW-1, SEC-1, SEC-2).
	 *
	 * @param string   $role  Role ID.
	 * @param int|null $users How many users hold it, or null when not counted (a large site).
	 * @return array{available:bool,users:array,holders:int,more:bool,search:bool,users_url:string}
	 */
	private function test_as_data( $role, $users ) {
		$out = array(
			'available' => false,
			'users'     => array(),
			'holders'   => null === $users ? -1 : (int) $users,
			'more'      => false,
			'search'    => false,
			'users_url' => '',
		);

		if ( ! BLT_Toolkit_Plugin::is_active( 'switch' ) || ! class_exists( 'BLT_Toolkit_Switch' ) || ! current_user_can( 'blt_switch_users' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return $out;
		}

		$out['available'] = true;

		if ( null !== $users && $users <= 0 ) {
			return $out;
		}

		$query = new WP_User_Query(
			array(
				'role'        => $role,
				'exclude'     => array( get_current_user_id() ),
				'orderby'     => 'display_name',
				'order'       => 'ASC',
				'number'      => self::TEST_AS_SCAN,
				'count_total' => false,
				'fields'      => 'all',
			)
		);

		$read = 0;

		foreach ( (array) $query->get_results() as $user ) {
			++$read;

			if ( ! $user instanceof WP_User || false === BLT_Toolkit_Switch::maybe_switch_url( $user ) ) {
				continue;
			}

			if ( count( $out['users'] ) >= self::TEST_AS_LIMIT ) {
				$out['more'] = true;
				break;
			}

			$out['users'][] = array(
				'id'    => (int) $user->ID,
				'label' => sprintf(
					/* translators: 1: display name, 2: username. */
					__( '%1$s (%2$s)', 'blt-toolkit' ),
					$user->display_name,
					$user->user_login
				),
			);
		}

		if ( $read >= self::TEST_AS_SCAN && ( null === $users || $users > $read ) ) {
			$out['more'] = true;
		}

		if ( null === $users && 0 === $read ) {
			$out['holders'] = 0;
		}

		$out['search']    = $out['more'] && (bool) BLT_Toolkit_Settings::get( 'switch', 'search' );
		$out['users_url'] = $out['more'] && current_user_can( 'list_users' ) ? add_query_arg( 'role', $role, admin_url( 'users.php' ) ) : '';

		return $out;
	}

	/**
	 * The Simple / Advanced tabs (RS-2), or nothing for someone who may only
	 * use Simple view (SPEC 9.3: the switcher is hidden for them).
	 *
	 * @param array $state state().
	 * @return array[]
	 */
	private function view_tabs( array $state ) {
		if ( ! $state['can_advanced'] ) {
			return array();
		}

		$out = array();

		foreach ( array(
			'simple'   => __( 'Simple', 'blt-toolkit' ),
			'advanced' => __( 'Advanced', 'blt-toolkit' ),
		) as $view => $label ) {
			$out[] = array(
				'view'    => $view,
				'label'   => $label,
				'url'     => self::state_url( $state, array( 'view' => $view ) ),
				'current' => $view === $state['view'],
			);
		}

		return $out;
	}

	/**
	 * The capability matrix for a role (RO-20, RO-21): one row per
	 * capability of the chosen group after the toolbar's filters, each
	 * locked with a reason when the person may not change it here.
	 *
	 * Locked rows are not part of the on-page set (RO-1), because a disabled
	 * box is never submitted and would otherwise read as unchecked.
	 *
	 * @param array         $state    state().
	 * @param true|WP_Error $editable Whether the person may change the role.
	 * @return array
	 */
	private function role_matrix( array $state, $editable ) {
		$role     = $state['role'];
		$role_obj = wp_roles()->get_role( $role );
		$caps     = $role_obj instanceof WP_Role ? (array) $role_obj->capabilities : array();
		$granted  = array();

		foreach ( $caps as $cap => $value ) {
			if ( $value ) {
				$granted[ (string) $cap ] = true;
			}
		}

		$entries   = BLT_Toolkit_Capability_Catalog::all();
		$control   = $this->simple_control_filter( $state );
		$node      = null !== $control ? $control['node'] : $this->current_node( $state );
		$sections  = self::visible_sections(
			self::sections_for( BLT_Toolkit_Capability_Catalog::tree(), null !== $control ? 'all' : $node['id'] ),
			$entries,
			array(
				'deprecated' => $state['deprecated'] || 'core-deprecated' === $node['id'] || null !== $control,
				'search'     => $state['search'],
				'granted'    => $state['granted'] ? $granted : null,
				'readable'   => $state['readable'],
				'only'       => null !== $control ? $control['caps'] : null,
			)
		);
		$actor     = wp_get_current_user();
		$is_admin  = BLT_Toolkit_Guard::is_toolkit_admin( $actor );
		$own       = BLT_Toolkit_Guard::effective_caps( $actor );
		$lockout   = array_fill_keys( BLT_Toolkit_Caps::lockout_critical(), true );
		$read_only = true !== $editable;
		$on_page   = array();
		$rows      = 0;
		$index     = 0;
		$deletable = current_user_can( 'blt_edit_capabilities' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
		$overlaps  = self::node_overlaps( $node['id'] );

		foreach ( $sections as $s => $section ) {
			$list = array();

			foreach ( $section['caps'] as $cap ) {
				$entry   = $entries[ $cap ];
				$checked = isset( $granted[ $cap ] );
				$locked  = '';

				if ( $read_only ) {
					$locked = ' ';
				} elseif ( BLT_Toolkit_Guard::is_protected_role( $role ) && $checked && isset( $lockout[ $cap ] ) ) {
					// SEC-12.
					$locked = __( 'The Administrator role always keeps this, so nobody can be locked out of the site.', 'blt-toolkit' );
				} elseif ( ! $is_admin && ! $checked && empty( $own[ $cap ] ) ) {
					// SEC-3.
					$locked = __( 'You do not have this capability yourself, so you cannot grant it.', 'blt-toolkit' );
				}

				if ( '' === $locked ) {
					$on_page[] = $cap;
				}

				$list[] = self::row( $entry, $cap, ++$index ) + array(
					'also'       => isset( $overlaps[ $cap ] ) ? implode( ', ', $overlaps[ $cap ] ) : '',
					'checked'    => $checked,
					'denied'     => array_key_exists( $cap, $caps ) && ! $caps[ $cap ],
					'locked'     => trim( $locked ),
					'disabled'   => '' !== $locked,
					'delete_url' => $deletable && ! empty( $entry['orphan'] ) ? self::state_url(
						$state,
						array(
							'step' => 'delete_cap',
							'cap'  => $cap,
						)
					) : '',
				);
			}

			$rows += count( $list );

			$sections[ $s ]['rows'] = $list;
		}

		return array(
			'node'      => $node,
			'shared'    => $this->shared_note( $node['id'] ),
			'sections'  => $sections,
			'leaf'      => 1 === count( $sections ) && $sections[0]['id'] === $node['id'],
			'rows'      => $rows,
			'on_page'   => $on_page,
			'hash'      => BLT_Toolkit_Role_Store::role_hash( $role ),
			'granted'   => $granted,
			'read_only' => $read_only,
			'reason'    => is_wp_error( $editable ) ? $editable->get_error_message() : '',
			'back'      => http_build_query( self::state_args( $state ) ),
			'discard'   => self::state_url( $state ),
		);
	}

	/**
	 * The parts of a matrix row every mode shares.
	 *
	 * @param array  $entry Catalog entry.
	 * @param string $cap   Capability.
	 * @param int    $index Row number on the page (for element IDs).
	 * @return array
	 */
	private static function row( array $entry, $cap, $index ) {
		return array(
			'id'           => 'blt-tk-cap-' . (int) $index,
			'cap'          => (string) $cap,
			'label'        => (string) $entry['label'],
			'source'       => (string) $entry['source'],
			'source_label' => (string) $entry['source_label'],
			'tier'         => (string) $entry['tier'],
			'simple'       => isset( $entry['simple'] ) && is_string( $entry['simple'] ) ? $entry['simple'] : '',
			'note'         => (string) $entry['note'],
			'orphan'       => ! empty( $entry['orphan'] ),
			'deprecated'   => ! empty( $entry['deprecated'] ),
			'search'       => self::lower( $entry['label'] . ' ' . $cap ),
		);
	}

	/**
	 * The group the URL asks for, or All.
	 *
	 * @param array $state state().
	 * @return array Tree node.
	 */
	private function current_node( array $state ) {
		$node = BLT_Toolkit_Capability_Catalog::node( $state['group'] );

		if ( null === $node ) {
			$node = BLT_Toolkit_Capability_Catalog::node( 'all' );
		}

		return is_array( $node ) ? $node : array(
			'id'       => 'all',
			'label'    => __( 'All', 'blt-toolkit' ),
			'caps'     => array(),
			'count'    => 0,
			'children' => array(),
		);
	}

	/**
	 * The content group a tree node shows: a Content types node's own group,
	 * or the Posts or Pages group for Core's Posts and Pages; null for any
	 * other node.
	 *
	 * @param string $node_id Tree node ID.
	 * @return array|null
	 */
	private static function node_group( $node_id ) {
		$primary = '';

		if ( 'core-posts' === $node_id ) {
			$primary = 'post';
		} elseif ( 'core-pages' === $node_id ) {
			$primary = 'page';
		}

		foreach ( BLT_Toolkit_Capability_Catalog::content_groups() as $group ) {
			if ( '' !== $primary ? $group['primary'] === $primary && empty( $group['internal'] ) : $group['id'] === $node_id ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * For a content group (or Core's Posts and Pages): which content types
	 * share these capabilities (RO-14, SPEC 19 URE-4). Every member type of
	 * the group, and every other type that uses some of the same strings
	 * (the rows say which). '' when nothing is shared.
	 *
	 * @param string $node_id Tree node ID.
	 * @return string
	 */
	private function shared_note( $node_id ) {
		$group = self::node_group( $node_id );

		if ( null === $group ) {
			return '';
		}

		$parts = array();

		if ( ! empty( $group['type_labels'] ) && count( $group['type_labels'] ) >= 2 ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated content type names, such as "Posts, Events, Testimonials". */
				__( 'These capabilities are shared by %s. A change here changes access to every one of them. To control one of them on its own, it needs permissions of its own.', 'blt-toolkit' ),
				implode( ', ', array_values( $group['type_labels'] ) )
			);
		}

		$others = array();

		foreach ( isset( $group['also_used_by'] ) ? (array) $group['also_used_by'] : array() as $names ) {
			$others = array_merge( $others, (array) $names );
		}

		$others = array_values( array_unique( $others ) );

		if ( array() !== $others ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated content type names, such as "Posts, Events". */
				__( 'Some of these capabilities are also used by %s, as each row says. A change to one of them changes access there too.', 'blt-toolkit' ),
				implode( ', ', $others )
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Which other content types use each capability of a node's group
	 * (RO-14): capability => their names, for the rows' "Also used by".
	 *
	 * @param string $node_id Tree node ID.
	 * @return array<string,string[]>
	 */
	private static function node_overlaps( $node_id ) {
		$group = self::node_group( $node_id );

		return null !== $group && isset( $group['also_used_by'] ) ? (array) $group['also_used_by'] : array();
	}

	/**
	 * The group tree (RO-13, RO-26): links, one URL per group, with how many
	 * of each group's capabilities are granted.
	 *
	 * @param array $state   state().
	 * @param array $granted Capability => true for those granted.
	 * @return array[]
	 */
	private function tree_data( array $state, array $granted ) {
		$entries = BLT_Toolkit_Capability_Catalog::all();
		$current = $this->current_node( $state );

		return $this->tree_nodes( BLT_Toolkit_Capability_Catalog::tree(), $entries, $granted, $state, $current['id'] );
	}

	/**
	 * The Simple control the URL narrows Advanced view to (`ctl`, RS-4's
	 * "Edit in Advanced"): its capabilities and a heading for the matrix, or
	 * null when there is none.
	 *
	 * @param array $state state().
	 * @return array{caps:string[],node:array}|null
	 */
	private function simple_control_filter( array $state ) {
		if ( '' === $state['ctl'] || ! class_exists( 'BLT_Toolkit_Simple_Controls' ) || ! class_exists( 'BLT_Toolkit_Simple_Mapper' ) ) {
			return null;
		}

		$controls = BLT_Toolkit_Simple_Controls::definitions();

		if ( ! isset( $controls[ $state['ctl'] ] ) || array() === $controls[ $state['ctl'] ]['universe'] ) {
			return null;
		}

		$control = $controls[ $state['ctl'] ];

		return array(
			'caps' => $control['universe'],
			'node' => array(
				'id'       => 'simple-' . $control['id'],
				/* translators: %s: Simple view control, such as "Install, update or delete themes". */
				'label'    => sprintf( __( 'Simple view: %s', 'blt-toolkit' ), $control['label'] ),
				'caps'     => $control['universe'],
				'count'    => count( $control['universe'] ),
				'children' => array(),
			),
		);
	}

	/**
	 * Tree nodes for display, recursively. Deprecated is hidden while
	 * deprecated capabilities are, and groups with nothing in them are left
	 * out.
	 *
	 * @param array[] $nodes   Catalog tree nodes.
	 * @param array   $entries Catalog entries.
	 * @param array   $granted Capability => true.
	 * @param array   $state   state().
	 * @param string  $current Current node ID.
	 * @return array[]
	 */
	private function tree_nodes( array $nodes, array $entries, array $granted, array $state, $current ) {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( 'core-deprecated' === $node['id'] && ! $state['deprecated'] && 'core-deprecated' !== $current ) {
				continue;
			}

			$total = 0;
			$have  = 0;

			foreach ( $node['caps'] as $cap ) {
				if ( ! isset( $entries[ $cap ] ) || ( ! $state['deprecated'] && ! empty( $entries[ $cap ]['deprecated'] ) && 'core-deprecated' !== $node['id'] ) ) {
					continue;
				}

				++$total;

				if ( isset( $granted[ $cap ] ) ) {
					++$have;
				}
			}

			if ( 0 === $total && 'all' !== $node['id'] ) {
				continue;
			}

			$out[] = array(
				'id'       => $node['id'],
				'label'    => $node['label'],
				'url'      => self::state_url(
					$state,
					array(
						'group' => 'all' === $node['id'] ? null : $node['id'],
						'ctl'   => null,
					)
				),
				'total'    => $total,
				'granted'  => $have,
				'current'  => $node['id'] === $current,
				'children' => $this->tree_nodes( $node['children'], $entries, $granted, $state, $current ),
			);
		}

		return $out;
	}

	/**
	 * The Add capability card (RO-23): the roles the person may change.
	 *
	 * Only for a toolkit admin. A new capability is by definition one nobody
	 * holds, so SEC-3 refuses it to anyone else, whatever they submit.
	 *
	 * @param array $state state().
	 * @return array|null Null when the card is not shown.
	 */
	private function add_cap_data( array $state ) {
		$actor = wp_get_current_user();
		$roles = array();

		if ( ! BLT_Toolkit_Guard::is_toolkit_admin( $actor ) ) {
			return null;
		}

		foreach ( array_keys( (array) wp_roles()->roles ) as $id ) {
			$id = (string) $id;

			if ( true === BLT_Toolkit_Guard::check_role_change( $actor, $id, array(), array() ) ) {
				$roles[ $id ] = self::role_name( $id );
			}
		}

		return array(
			'roles'   => $roles,
			'checked' => $state['role'],
			'back'    => http_build_query( self::state_args( $state ) ),
			'max'     => BLT_Toolkit_Capability_Catalog::NAME_MAX,
		);
	}

	/**
	 * The role details card (SPEC 9.5): description and "Hide the toolbar on
	 * the site".
	 *
	 * @param array         $state    state().
	 * @param true|WP_Error $editable Whether the person may change the role.
	 * @return array
	 */
	private function details_data( array $state, $editable ) {
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$role = $state['role'];
		$own  = is_array( $meta ) && isset( $meta[ $role ] ) && is_array( $meta[ $role ] ) ? $meta[ $role ] : array();

		return array(
			'role'         => $role,
			'description'  => isset( $own['description'] ) ? (string) $own['description'] : '',
			'hide_toolbar' => ! empty( $own['hide_toolbar'] ),
			// Simple view shows the toolbar setting in its Basics card
			// (Appendix A), so the details card leaves it out there.
			'toolbar'      => 'simple' !== $state['view'],
			'editable'     => true === $editable,
			'back'         => http_build_query( self::state_args( $state ) ),
		);
	}

	// ---------- Simple view (SPEC 10, Appendix A) ----------

	/**
	 * What Simple view draws for a role (SPEC 10, 13.2): the cards in order,
	 * each control with its state, tier badge (SEC-11), hints (RS-7) and
	 * whether the person may change it, plus the data the script uses for
	 * the change counter and the review (RS-5, RS-6).
	 *
	 * The administrator role is read only here (SEC-12); a role the person
	 * may not change (SEC-2, SEC-5) is read only with the Guard's reason; a
	 * control that would give the person's role editor something they do
	 * not hold is locked (SEC-3). Drawing changes nothing (RO-5).
	 *
	 * @param array         $state    state().
	 * @param true|WP_Error $editable Whether the person may change the role.
	 * @return array
	 */
	private function simple_data( array $state, $editable ) {
		$role = $state['role'];

		if ( ! class_exists( 'BLT_Toolkit_Simple_Controls' ) || ! class_exists( 'BLT_Toolkit_Simple_Mapper' ) ) {
			return array( 'available' => false );
		}

		$actor     = wp_get_current_user();
		$protected = BLT_Toolkit_Guard::is_protected_role( $role );
		$read_only = $protected || true !== $editable;
		$controls  = BLT_Toolkit_Simple_Controls::all( $role );
		$context   = array(
			'state'     => $state,
			'read_only' => $read_only,
			'is_admin'  => BLT_Toolkit_Guard::is_toolkit_admin( $actor ),
			'own'       => BLT_Toolkit_Guard::effective_caps( $actor ),
			'isolate'   => current_user_can( 'blt_edit_capabilities' ) && current_user_can( 'manage_options' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1); RS-30 needs both.
			'controls'  => $controls,
		);
		$sections  = array();
		$script    = array();
		$labels    = array();

		foreach ( BLT_Toolkit_Simple_Controls::sections() as $id => $section ) {
			$sections[ $id ] = $section + array(
				'id'       => $id,
				'controls' => array(),
			);
		}

		foreach ( $controls as $id => $control ) {
			$view = BLT_Toolkit_Simple_Controls::SUMMARY === $control['kind'] ? $this->simple_summary_view( $control, $state ) : $this->simple_control_view( $control, $context );

			if ( ! isset( $sections[ $control['section'] ] ) ) {
				continue;
			}

			$sections[ $control['section'] ]['controls'][] = $view;

			if ( ! $view['disabled'] && BLT_Toolkit_Simple_Controls::changeable( $control ) ) {
				$script[] = $this->simple_script_data( $control );

				foreach ( $control['universe'] as $cap ) {
					$labels[ $cap ] = BLT_Toolkit_Capability_Catalog::label( $cap );
				}
			}
		}

		$critical = array();

		foreach ( array_keys( $labels ) as $cap ) {
			if ( BLT_Toolkit_Caps::CRITICAL === BLT_Toolkit_Caps::tier( (string) $cap ) ) {
				$critical[ $cap ] = self::consequence( (string) $cap );
			}
		}

		$reason = '';

		if ( $protected ) {
			$reason = $state['can_advanced']
				? __( 'The Administrator role always has every permission, so nobody can be locked out of the site. Simple view shows it read only; single capabilities can be changed in Advanced view, where the ones that keep the site reachable stay locked.', 'blt-toolkit' )
				: __( 'The Administrator role always has every permission, so nobody can be locked out of the site. Simple view shows it read only.', 'blt-toolkit' );
		} elseif ( is_wp_error( $editable ) ) {
			$reason = $editable->get_error_message();
		}

		return array(
			'available' => true,
			'role'      => $role,
			'sections'  => array_filter(
				$sections,
				static function ( $section ) {
					return array() !== $section['controls'];
				}
			),
			'read_only' => $read_only,
			'protected' => $protected,
			'reason'    => $reason,
			'hash'      => BLT_Toolkit_Role_Store::role_hash( $role ),
			'back'      => http_build_query( self::state_args( $state ) ),
			'discard'   => self::state_url( $state ),
			'advanced'  => $state['can_advanced'] ? self::state_url( $state, array( 'view' => 'advanced' ) ) : '',
			'script'    => array(
				'role'      => self::role_name( $role ),
				'names'     => array_values( array_unique( array_filter( array( self::role_name( $role ), self::stored_role_name( $role ) ) ) ) ),
				'threshold' => self::REVIEW_THRESHOLD,
				'controls'  => $script,
				'labels'    => $labels,
				'critical'  => $critical,
				'levels'    => BLT_Toolkit_Simple_Controls::level_labels(),
			),
		);
	}

	/**
	 * One control as Simple view draws it.
	 *
	 * @param array $control Control with state (BLT_Toolkit_Simple_Controls::all()).
	 * @param array $context state, read_only, is_admin, own, isolate, controls.
	 * @return array
	 */
	private function simple_control_view( array $control, array $context ) {
		$state    = $context['state'];
		$id       = $control['id'];
		$disabled = $context['read_only'];
		$locked   = '';
		$missing  = $context['is_admin'] ? array() : array_values( array_diff( $control['universe'], array_keys( $context['own'] ) ) );

		$view = array(
			'id'           => $id,
			'dom'          => 'blt-tk-ctl-' . $id,
			'kind'         => $control['kind'],
			'label'        => $control['label'],
			'help'         => $control['help'],
			'note'         => $control['note'],
			'tier'         => $control['tier'],
			'caps'         => BLT_Toolkit_Simple_Controls::LEVEL === $control['kind'] ? array() : $control['universe'],
			'badges'       => array(),
			'hints'        => array(),
			'advanced_url' => $state['can_advanced'] && array() !== $control['universe'] ? self::state_url(
				$state,
				array(
					'view'  => 'advanced',
					'ctl'   => $id,
					'group' => null,
					's'     => null,
				)
			) : '',
		);

		// SEC-3: a non-admin cannot give what they do not hold, so a control
		// that is not fully on and needs more than they have is locked.
		if ( ! $disabled && array() !== $missing && BLT_Toolkit_Simple_Controls::TOGGLE === $control['kind'] && 'on' !== $control['state'] ) {
			$disabled = true;
			$locked   = __( 'You do not have this permission yourself, so you cannot give it.', 'blt-toolkit' );
		}

		$view['disabled'] = $disabled;
		$view['locked']   = $locked;

		if ( BLT_Toolkit_Simple_Controls::LEVEL === $control['kind'] ) {
			$view = $this->simple_level_view( $control, $context, $view );
		} else {
			$view['checked']       = 'on' === $control['state'];
			$view['indeterminate'] = 'indeterminate' === $control['state'];
			$view['drawn']         = $view['indeterminate'] ? 'indeterminate' : 'off';

			if ( $view['indeterminate'] ) {
				$view['badges'][] = array( 'pending', __( 'Partly allowed', 'blt-toolkit' ) );
			}
		}

		foreach ( $control['hints'] as $index => $hint ) {
			$needs = isset( $context['controls'][ $hint['control'] ] ) ? $context['controls'][ $hint['control'] ] : null;

			if ( null === $needs ) {
				continue;
			}

			$needs_missing = $context['is_admin'] ? array() : array_diff( $needs['universe'], array_keys( $context['own'] ) );

			$view['hints'][] = array(
				'dom'         => $view['dom'] . '-hint-' . $index,
				'text'        => $hint['text'],
				'needs'       => $hint['control'],
				'needs_dom'   => 'blt-tk-ctl-' . $hint['control'],
				'needs_label' => $needs['label'],
				'when'        => $hint['when'],
				'active'      => $hint['active'],
				'can_allow'   => ! $context['read_only'] && array() === $needs_missing,
			);
		}

		return $view;
	}

	/**
	 * The parts of a content row's view (SPEC 10.2 to 10.4): the level
	 * selector with each level's help text, Can delete and Manage
	 * {taxonomy}, badges (RS-11 to RS-13), and separate permissions (RS-30,
	 * RS-32).
	 *
	 * @param array $control Level row with state.
	 * @param array $context See simple_control_view().
	 * @param array $view    The view so far.
	 * @return array
	 */
	private function simple_level_view( array $control, array $context, array $view ) {
		$plural  = $control['plural'];
		$labels  = BLT_Toolkit_Simple_Controls::level_labels();
		$current = $control['state']['level'];
		$mods    = $control['state']['mods'];
		$own     = $context['own'];
		$role    = $context['state']['role'];

		$view['plural']   = $plural;
		$view['readonly'] = $control['readonly'];
		$view['level']    = $current;
		$view['granted']  = count( $control['granted'] );
		$view['total']    = count( $control['universe'] );
		$view['also']     = $control['also'];
		$view['isolate']  = array();
		$view['revert']   = '';
		$view['options']  = array();
		$view['mods']     = array();

		if ( '' !== $control['readonly'] ) {
			$view['disabled'] = true;

			switch ( $control['readonly'] ) {
				case BLT_Toolkit_Simple_Controls::READONLY_EXCLUDED:
					$owners = $this->simple_owners( $control['foreign'], $context['controls'] );

					$view['controlled_by'] = $owners;
					$view['badges'][]      = array(
						'pending',
						/* translators: %s: Simple view control names, such as "Change settings". */
						sprintf( __( 'Controlled by: %s', 'blt-toolkit' ), array() !== $owners ? implode( ', ', wp_list_pluck( $owners, 'label' ) ) : __( 'capabilities managed in Advanced', 'blt-toolkit' ) ),
					);
					$view['status']        = __( 'This content type uses site-wide permissions instead of its own, so it has no levels. Whoever has those permissions can work with it.', 'blt-toolkit' );
					break;
				case BLT_Toolkit_Simple_Controls::READONLY_OWNERSHIP:
					$view['badges'][] = array( 'pending', __( 'Custom ownership rules', 'blt-toolkit' ) );
					$view['status']   = __( 'This content type does not let WordPress tell people\'s own items from everyone else\'s, so levels such as Author (own only) cannot be enforced. Manage it in Advanced.', 'blt-toolkit' );
					break;
				default:
					$view['badges'][] = array( 'cancelled', __( 'Separate permissions not applied', 'blt-toolkit' ) );
					$view['status']   = __( 'This content type was given separate permissions, but they are not in effect: another plugin changed them, or it was registered before BLT Toolkit loaded. Its levels are switched off until that is fixed.', 'blt-toolkit' );
			}

			return $view;
		}

		$view['create_distinct'] = $control['create_distinct'];

		// RS-13: "Enforced by BLT Toolkit" while the row is at View and the
		// type has a create capability of its own.
		if ( $control['create_distinct'] ) {
			$view['badges'][] = array( 'on', __( 'Enforced by BLT Toolkit', 'blt-toolkit' ), 'view' );
		}

		if ( 1 === count( $control['types'] ) && isset( $control['isolated'][ $control['types'][0] ] ) ) {
			$view['badges'][] = array( 'confirmed', __( 'Separate permissions', 'blt-toolkit' ) );

			if ( $context['isolate'] ) {
				$view['revert'] = self::isolation_url( 'revert', $control['types'][0], $role );
			}
		}

		// Isolated members of a shared group: their separate permissions did
		// not apply (RS-33), so they still follow this row.
		$failed = $control['isolated'];

		if ( 1 === count( $control['types'] ) ) {
			unset( $failed[ (string) $control['types'][0] ] );
		}

		if ( array() !== $failed ) {
			$view['badges'][] = array(
				'cancelled',
				/* translators: %s: comma-separated content type names. */
				sprintf( __( 'Separate permissions not applied: %s', 'blt-toolkit' ), implode( ', ', $failed ) ),
			);
		}

		$separate = array();

		if ( $context['isolate'] ) {
			foreach ( $control['candidates'] as $type => $type_label ) {
				$view['isolate'][] = array(
					/* translators: %s: plural content type name, such as Events. */
					'label' => sprintf( __( 'Give %s separate permissions', 'blt-toolkit' ), $type_label ),
					'url'   => self::isolation_url( 'isolate', (string) $type, $role ),
				);
				$separate[]        = $type_label;
			}
		}

		$view_help = BLT_Toolkit_Simple_Controls::level_help( 'view', $plural );

		if ( ! $control['create_distinct'] ) {
			$view_help .= ' ' . BLT_Toolkit_Simple_Controls::site_only_help( $plural, false );

			foreach ( $separate as $type_label ) {
				/* translators: %1$s: plural content type name, such as Events. */
				$view_help .= ' ' . sprintf( __( 'To browse %1$s read-only in the dashboard, give %1$s separate permissions.', 'blt-toolkit' ), $type_label );
			}
		}

		foreach ( BLT_Toolkit_Simple_Mapper::LEVELS as $level ) {
			$target = BLT_Toolkit_Simple_Mapper::caps_for( $control['map'], $level, BLT_Toolkit_Simple_Mapper::default_mods( $control['map'], $level ) );
			$lacks  = $context['is_admin'] ? array() : array_diff( $target, array_keys( $own ) );

			$view['options'][ $level ] = array(
				'label'    => $labels[ $level ],
				'help'     => 'view' === $level ? $view_help : BLT_Toolkit_Simple_Controls::level_help( $level, $plural ),
				// SEC-3: a level that would give what the person lacks.
				'disabled' => $level !== $current && array() !== $lacks,
			);
		}

		if ( BLT_Toolkit_Simple_Mapper::CUSTOM === $current ) {
			$view['options']  = array(
				'custom' => array(
					'label'    => $labels['custom'],
					'help'     => BLT_Toolkit_Simple_Controls::level_help( 'custom', $plural ),
					'disabled' => false,
				),
			) + $view['options'];
			$view['badges'][] = array( 'pending', $labels['custom'] );
		}

		$view['help_text'] = $view['options'][ $current ]['help'];

		// Can delete (SPEC 10.3), drawn for every level; the script hides it
		// where it does not apply. Locked (SEC-3) only when the person could
		// not give even Contribute's deletion; at higher levels the levels
		// themselves are locked, and the Guard checks every save.
		$delete_caps = array_diff(
			BLT_Toolkit_Simple_Mapper::caps_for( $control['map'], 'contribute', array( 'can_delete' => true ) ),
			BLT_Toolkit_Simple_Mapper::caps_for( $control['map'], 'contribute', array( 'can_delete' => false ) )
		);

		$view['mods'][] = array(
			'key'      => 'can_delete',
			'name'     => 'simple[' . $view['id'] . '][can_delete]',
			'dom'      => $view['dom'] . '-delete',
			'label'    => __( 'Can delete', 'blt-toolkit' ),
			'desc'     => __( 'For Contribute, Author (own only) and Edit all. Full control always includes deleting.', 'blt-toolkit' ),
			'checked'  => ! empty( $mods['can_delete'] ),
			'levels'   => array_keys( BLT_Toolkit_Simple_Mapper::DELETE_DEFAULTS ),
			'shown'    => BLT_Toolkit_Simple_Mapper::shows_can_delete( $current ),
			'disabled' => $view['disabled'] || ( empty( $mods['can_delete'] ) && ! $context['is_admin'] && array() !== array_diff( $delete_caps, array_keys( $own ) ) ),
		);

		foreach ( BLT_Toolkit_Simple_Mapper::toggles( $control['map'] ) as $tax => $strings ) {
			$on             = ! empty( $mods['manage'][ $tax ] );
			$view['mods'][] = array(
				'key'      => 'manage',
				'tax'      => $tax,
				'name'     => 'simple[' . $view['id'] . '][manage][' . $tax . ']',
				'dom'      => $view['dom'] . '-manage-' . sanitize_html_class( $tax ),
				/* translators: %s: plural taxonomy name, such as Event Categories. */
				'label'    => sprintf( __( 'Manage %s', 'blt-toolkit' ), isset( $control['taxonomies'][ $tax ] ) ? $control['taxonomies'][ $tax ] : $tax ),
				'desc'     => __( 'For Contribute and above. Add, rename and delete them.', 'blt-toolkit' ),
				'checked'  => $on,
				'levels'   => BLT_Toolkit_Simple_Mapper::MANAGE_LEVELS,
				'shown'    => BLT_Toolkit_Simple_Mapper::shows_manage( $current ),
				'disabled' => $view['disabled'] || ( ! $on && ! $context['is_admin'] && array() !== array_diff( $strings, array_keys( $own ) ) ),
			);
		}

		return $view;
	}

	/**
	 * "Other plugin permissions" (SPEC 13.2).
	 *
	 * @param array $control The summary.
	 * @param array $state   state().
	 * @return array
	 */
	private function simple_summary_view( array $control, array $state ) {
		$url = '';

		if ( $state['can_advanced'] ) {
			$plugins = BLT_Toolkit_Capability_Catalog::node( 'plugins' );
			$node    = is_array( $plugins ) && array() !== $plugins['caps'] ? 'plugins' : 'orphaned';
			$url     = self::state_url(
				$state,
				array(
					'view'  => 'advanced',
					'group' => $node,
					'ctl'   => null,
					's'     => null,
				)
			);
		}

		return array(
			'id'           => $control['id'],
			'dom'          => 'blt-tk-ctl-' . $control['id'],
			'kind'         => $control['kind'],
			'label'        => $control['label'],
			'count'        => count( $control['universe'] ),
			'held'         => count( $control['granted'] ),
			'advanced_url' => $url,
			'disabled'     => true,
		);
	}

	/**
	 * The controls owning some capabilities (RS-11's "Controlled by").
	 *
	 * @param string[] $caps     Capabilities.
	 * @param array    $controls Controls.
	 * @return array[] Each: id, dom, label.
	 */
	private function simple_owners( array $caps, array $controls ) {
		$out = array();

		foreach ( $controls as $id => $control ) {
			if ( BLT_Toolkit_Simple_Controls::changeable( $control ) && BLT_Toolkit_Simple_Controls::TOGGLE === $control['kind'] && array() !== array_intersect( $caps, $control['universe'] ) ) {
				$out[] = array(
					'id'    => (string) $id,
					'dom'   => 'blt-tk-ctl-' . $id,
					'label' => $control['label'],
				);
			}
		}

		return $out;
	}

	/**
	 * What the script needs to count changes and preview them (RS-5,
	 * RS-6): a toggle's capabilities and state; a level row's capabilities
	 * per level, split into the base set, what Can delete adds and what
	 * each Manage toggle adds (BLT_Toolkit_Simple_Mapper::caps_for()), and
	 * the defaults each level starts with.
	 *
	 * @param array $control Control with state.
	 * @return array
	 */
	private function simple_script_data( array $control ) {
		$out = array(
			'id'       => $control['id'],
			'kind'     => $control['kind'],
			'label'    => $control['label'],
			'universe' => $control['universe'],
			'granted'  => $control['granted'],
			'state'    => $control['state'],
		);

		if ( BLT_Toolkit_Simple_Controls::LEVEL !== $control['kind'] ) {
			return $out;
		}

		$map    = $control['map'];
		$taxes  = array_keys( BLT_Toolkit_Simple_Mapper::toggles( $map ) );
		$none   = array_fill_keys( $taxes, false );
		$levels = array();

		foreach ( BLT_Toolkit_Simple_Mapper::LEVELS as $level ) {
			$base = BLT_Toolkit_Simple_Mapper::caps_for(
				$map,
				$level,
				array(
					'can_delete' => false,
					'manage'     => $none,
				)
			);
			$del  = BLT_Toolkit_Simple_Mapper::caps_for(
				$map,
				$level,
				array(
					'can_delete' => true,
					'manage'     => $none,
				)
			);

			$manage = array();

			foreach ( $taxes as $tax ) {
				$manage[ $tax ] = array_values(
					array_diff(
						BLT_Toolkit_Simple_Mapper::caps_for(
							$map,
							$level,
							array(
								'can_delete' => false,
								'manage'     => array( $tax => true ) + $none,
							)
						),
						$base
					)
				);
			}

			$levels[ $level ] = array(
				'base'     => $base,
				'del'      => array_values( array_diff( $del, $base ) ),
				'manage'   => (object) $manage,
				'defaults' => BLT_Toolkit_Simple_Mapper::default_mods( $map, $level ),
			);
		}

		$out['levels']     = $levels;
		$out['taxonomies'] = (object) $control['taxonomies'];

		return $out;
	}

	/**
	 * A separate-permissions page's URL (RS-30, RS-32), drawn by
	 * BLT_Toolkit_Admin_Isolation on the Roles screen.
	 *
	 * @param string $action `isolate`, `revert` or `cleanup`.
	 * @param string $type   Post type.
	 * @param string $role   Role to come back to.
	 * @return string
	 */
	public static function isolation_url( $action, $type, $role = '' ) {
		$extra = '' !== (string) $role ? array( 'role' => (string) $role ) : array();

		if ( class_exists( 'BLT_Toolkit_Admin_Isolation' ) && is_callable( array( 'BLT_Toolkit_Admin_Isolation', 'url' ) ) ) {
			return BLT_Toolkit_Admin_Isolation::url( (string) $action, (string) $type, $extra );
		}

		return BLT_Toolkit_Admin::page_url(
			'roles',
			array_merge(
				array(
					'action' => (string) $action,
					'type'   => (string) $type,
				),
				$extra
			)
		);
	}

	/**
	 * The separate-permissions page the URL asks for, when its screen class
	 * is loaded; '' otherwise.
	 *
	 * @return string
	 */
	private static function isolation_view() {
		$action = sanitize_key( self::arg( 'action' ) );

		if ( ! in_array( $action, self::ISOLATION_VIEWS, true ) || ! class_exists( 'BLT_Toolkit_Admin_Isolation' ) || ! is_callable( array( 'BLT_Toolkit_Admin_Isolation', 'render' ) ) ) {
			return '';
		}

		return $action;
	}

	/**
	 * Save Simple view (RS-5, RS-6, SPEC 10.7): the controls that changed,
	 * worked out again on the server (BLT_Toolkit_Simple_Controls::
	 * changes_for()), written through the role store with context `simple`
	 * and the render-time hash (RO-1, RO-2), after the review when it is
	 * needed, plus the toolbar setting (SPEC 9.5).
	 *
	 * The review (RS-6) is shown before writing when the change touches a
	 * Critical capability, changes more than ten capabilities, or replaces a
	 * Custom mix by taking capabilities away (RS-4). With JavaScript the
	 * page's own dialog shows it (`reviewed`, with the typed role name for
	 * SEC-10); without, or when the dialog could not settle it, it is its
	 * own page (`step=review`), as is the SEC-13 confirmation.
	 *
	 * @return void
	 */
	public function handle_simple_save() {
		$token = self::posted( self::PENDING_ARG );

		if ( '' !== $token ) {
			$pending = self::pending( $token, array( 'simple_review' ) );

			if ( null === $pending ) {
				self::back( array(), 'error', __( 'Nothing was saved: that review is no longer available. Make the change again.', 'blt-toolkit' ) );
			}

			$data     = $pending['data'];
			$reviewed = true;
		} else {
			if ( ! self::form_complete() ) {
				self::back( self::posted_back(), 'error', self::truncated_message() );
			}

			$role = self::clean_role( self::posted( 'role' ) );
			$back = self::posted_back();

			if ( '' === $role || ! wp_roles()->is_role( $role ) ) {
				self::back( $back, 'error', __( 'Nothing was saved: that role does not exist. Someone may have deleted it.', 'blt-toolkit' ) );
			}

			if ( ! class_exists( 'BLT_Toolkit_Simple_Controls' ) || ! class_exists( 'BLT_Toolkit_Simple_Mapper' ) ) {
				self::back( $back, 'error', __( 'Nothing was saved: Simple view is not available on this site.', 'blt-toolkit' ) );
			}

			// SEC-12, before anything is worked out.
			if ( BLT_Toolkit_Guard::is_protected_role( $role ) ) {
				self::back( $back, 'error', __( 'Nothing was saved: the Administrator role cannot be changed in Simple view.', 'blt-toolkit' ) );
			}

			$plan = BLT_Toolkit_Simple_Controls::changes_for( $role, self::posted_simple() );

			if ( is_wp_error( $plan ) ) {
				self::back( $back, 'error', self::error_text( $plan ) );
			}

			$data     = array(
				'role'     => $role,
				'on_page'  => $plan['on_page'],
				'checked'  => $plan['checked'],
				'hash'     => self::posted( 'hash' ),
				'back'     => $back,
				'controls' => $plan['controls'],
				'meta'     => $plan['meta'],
				// RS-2: "Save and switch" lands in the other view.
				'then'     => in_array( self::posted( 'then_view' ), array( 'simple', 'advanced' ), true ) ? self::posted( 'then_view' ) : '',
			);
			$reviewed = false;
		}

		$back = $data['back'];
		$role = $data['role'];

		if ( BLT_Toolkit_Guard::is_protected_role( $role ) ) {
			self::back( $back, 'error', __( 'Nothing was saved: the Administrator role cannot be changed in Simple view.', 'blt-toolkit' ) );
		}

		if ( array() === $data['on_page'] && array() === $data['meta'] ) {
			self::back( $back, 'warning', __( 'Nothing was saved: you did not change anything.', 'blt-toolkit' ) );
		}

		$client             = ! $reviewed && '1' === self::posted( self::REVIEWED_FIELD );
		$typed              = self::posted( self::CONFIRM_FIELD );
		$confirmed_critical = ( $reviewed || $client ) && self::confirms( $typed, array( self::role_name( $role ), self::stored_role_name( $role ) ) );
		$args               = array(
			'context'            => 'simple',
			'confirmed_critical' => $confirmed_critical,
			'confirmed_lockout'  => $reviewed && '1' === self::posted( self::LOCKOUT_FIELD ),
		);
		$change             = array(
			'add'    => array(),
			'remove' => array(),
		);

		if ( array() !== $data['on_page'] ) {
			$dry = BLT_Toolkit_Role_Store::save_caps( $role, $data['on_page'], $data['checked'], $data['hash'], $args + array( 'dry_run' => true ) );

			if ( is_wp_error( $dry ) ) {
				$this->refused( $dry, $data );
			}

			if ( isset( $dry['changes'][ $role ] ) ) {
				$change = $dry['changes'][ $role ];
			}

			$guard = isset( $dry['guard'] ) ? $dry['guard'] : true;

			if ( is_wp_error( $guard ) ) {
				$code = $guard->get_error_code();

				if ( 'blt_toolkit_critical_review' === $code || ( 'blt_toolkit_self_lockout' === $code && self::confirmable( $guard ) ) ) {
					$data['mismatch'] = ( $reviewed || $client ) && '' !== $typed && ! $confirmed_critical;

					self::to_review( 'simple_review', $data );
				}

				$this->refused( $guard, $data );
			}

			if ( ! $reviewed && ! $client && self::simple_needs_review( $change, $data['controls'] ) ) {
				self::to_review( 'simple_review', $data );
			}
		}

		if ( array() === $change['add'] && array() === $change['remove'] && array() === $data['meta'] ) {
			self::back( $back, 'warning', __( 'Nothing was saved: you did not change anything.', 'blt-toolkit' ) );
		}

		if ( array() !== $change['add'] || array() !== $change['remove'] ) {
			$result = BLT_Toolkit_Role_Store::save_caps( $role, $data['on_page'], $data['checked'], $data['hash'], $args );

			if ( is_wp_error( $result ) ) {
				$this->refused( $result, $data );
			}
		}

		if ( array() !== $data['meta'] && ! self::save_toolbar_flag( $role, ! empty( $data['meta'][ BLT_Toolkit_Simple_Controls::TOOLBAR_ID ] ) ) ) {
			self::back( $back, 'error', __( 'The capabilities were saved, but the toolbar setting could not be. Try again.', 'blt-toolkit' ) );
		}

		if ( $reviewed ) {
			self::forget_pending();
		}

		// SEC-13, confirmed: the person can no longer open this screen.
		if ( ! current_user_can( 'blt_edit_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			wp_safe_redirect( admin_url() );
			exit;
		}

		if ( ! empty( $data['then'] ) && ( 'simple' === $data['then'] || self::can_advanced() ) ) {
			$back['view'] = $data['then'];
		}

		self::back(
			$back,
			'success',
			sprintf(
				/* translators: %s: role name. */
				__( 'Saved the %s role.', 'blt-toolkit' ),
				self::role_name( $role )
			),
			self::simple_summary( $data['controls'] )
		);
	}

	/**
	 * The review page of a Simple save (RS-6): its own page, so it works
	 * without JavaScript.
	 *
	 * @param array $pending Pending entry.
	 * @return void
	 */
	private function render_simple_review( array $pending ) {
		$data = $pending['data'];
		$role = (string) $data['role'];
		$back = BLT_Toolkit_Admin::page_url( 'roles', $data['back'] );
		$add  = array();
		$rem  = array();

		$guard    = true;
		$critical = array();

		if ( array() !== $data['on_page'] ) {
			$dry = BLT_Toolkit_Role_Store::save_caps(
				$role,
				$data['on_page'],
				$data['checked'],
				$data['hash'],
				array(
					'context'            => 'simple',
					'dry_run'            => true,
					'confirmed_critical' => true,
					'confirmed_lockout'  => false,
				)
			);

			if ( is_wp_error( $dry ) ) {
				if ( 'blt_toolkit_role_changed' === $dry->get_error_code() ) {
					self::view( 'roles-conflict', self::conflict_data( $role, $dry, $data ) + array( 'back_url' => $back ) );
					return;
				}

				self::view( 'roles-message', self::refusal( $dry, $back ) );
				return;
			}

			$change = isset( $dry['changes'][ $role ] ) ? $dry['changes'][ $role ] : array(
				'add'    => array(),
				'remove' => array(),
			);
			$guard  = isset( $dry['guard'] ) ? $dry['guard'] : true;

			if ( is_wp_error( $guard ) && ! ( 'blt_toolkit_self_lockout' === $guard->get_error_code() && self::confirmable( $guard ) ) ) {
				self::view( 'roles-message', self::refusal( $guard, $back ) );
				return;
			}

			$add      = array_keys( array_filter( $change['add'] ) );
			$rem      = array_values( $change['remove'] );
			$critical = isset( $dry['critical'][ $role ] ) ? (array) $dry['critical'][ $role ] : array();
		}

		$controls = array();

		foreach ( $data['controls'] as $control ) {
			$controls[] = array(
				'label'   => (string) $control['label'],
				'from'    => (string) $control['from'],
				'to'      => (string) $control['to'],
				'adds'    => self::cap_rows( (array) $control['add'] ),
				'removes' => self::cap_rows( (array) $control['remove'] ),
				'custom'  => ! empty( $control['custom'] ) && array() !== (array) $control['remove'],
			);
		}

		$removed_critical = array();

		foreach ( $rem as $cap ) {
			if ( BLT_Toolkit_Caps::CRITICAL === BLT_Toolkit_Caps::tier( (string) $cap ) ) {
				$removed_critical[] = (string) $cap;
			}
		}

		self::view(
			'roles-simple-review',
			array(
				'token'            => $pending['token'],
				'name'             => self::role_name( $role ),
				'controls'         => $controls,
				'grants'           => self::cap_rows( $add ),
				'removes'          => self::cap_rows( $rem ),
				'critical'         => self::critical_rows( $critical ),
				'removed_critical' => self::cap_rows( $removed_critical ),
				'lockout'          => is_wp_error( $guard ),
				'mismatch'         => ! empty( $data['mismatch'] ),
				'back_url'         => $back,
				'count'            => count( $add ) + count( $rem ),
				'threshold'        => self::REVIEW_THRESHOLD,
			)
		);
	}

	/**
	 * Whether a Simple change is reviewed before it is saved (RS-6, RS-4):
	 * it touches a Critical capability, changes more than ten, or replaces
	 * a Custom (or partly allowed) control by taking capabilities away.
	 * Pure apart from the tier lookup.
	 *
	 * @param array $change   One role's change set from the store.
	 * @param array $controls The changed controls (changes_for()'s `controls`).
	 * @return bool
	 */
	public static function simple_needs_review( array $change, array $controls ) {
		$add    = isset( $change['add'] ) ? array_keys( (array) $change['add'] ) : array();
		$remove = isset( $change['remove'] ) ? array_values( (array) $change['remove'] ) : array();

		if ( count( $add ) + count( $remove ) > self::REVIEW_THRESHOLD ) {
			return true;
		}

		foreach ( array_merge( $add, $remove ) as $cap ) {
			if ( BLT_Toolkit_Caps::CRITICAL === BLT_Toolkit_Caps::tier( (string) $cap ) ) {
				return true;
			}
		}

		foreach ( $controls as $control ) {
			if ( ! empty( $control['custom'] ) && ! empty( $control['remove'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The plain-English lines of a Simple change (RS-6): "Events: Author
	 * (own only) → Edit all. Adds: edit others' Events. Removes: publish
	 * Events."
	 *
	 * @param array $controls The changed controls (changes_for()'s `controls`).
	 * @return string[]
	 */
	private static function simple_summary( array $controls ) {
		$out = array();

		foreach ( $controls as $control ) {
			/* translators: 1: control, such as Events, 2: state before, 3: state after. */
			$line = sprintf( __( '%1$s: %2$s → %3$s.', 'blt-toolkit' ), $control['label'], $control['from'], $control['to'] );

			if ( ! empty( $control['add'] ) ) {
				/* translators: %s: comma-separated capability labels. */
				$line .= ' ' . sprintf( __( 'Adds: %s.', 'blt-toolkit' ), self::cap_words( (array) $control['add'] ) );
			}

			if ( ! empty( $control['remove'] ) ) {
				/* translators: %s: comma-separated capability labels. */
				$line .= ' ' . sprintf( __( 'Removes: %s.', 'blt-toolkit' ), self::cap_words( (array) $control['remove'] ) );
			}

			$out[] = $line;
		}

		return $out;
	}

	/**
	 * Capability labels as words in a sentence: "edit others' Events,
	 * publish Events".
	 *
	 * @param string[] $caps Capabilities.
	 * @return string
	 */
	public static function cap_words( array $caps ) {
		$words = array();

		foreach ( $caps as $cap ) {
			$words[] = self::sentence_case( BLT_Toolkit_Capability_Catalog::label( (string) $cap ) );
		}

		return implode( ', ', $words );
	}

	/**
	 * A label lowercased at its start for use inside a sentence ("Edit
	 * others' Events" becomes "edit others' Events"), unless its first word
	 * is a name or an acronym (two capitals in a row, "BLT"). Pure.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	public static function sentence_case( $label ) {
		$label = (string) $label;

		if ( preg_match( '/^[A-Z][a-z\']/', $label ) && ! preg_match( '/^(WordPress|Site Health|Customizer)\b/', $label ) ) {
			return strtolower( $label[0] ) . substr( $label, 1 );
		}

		return $label;
	}

	/**
	 * Write "Hide the toolbar on the site" for a role (SPEC 9.5), as the
	 * role details card does, with its log row.
	 *
	 * @param string $role Role ID.
	 * @param bool   $hide Whether the toolbar is hidden.
	 * @return bool Whether it is stored.
	 */
	private static function save_toolbar_flag( $role, $hide ) {
		$meta   = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$meta   = is_array( $meta ) ? $meta : array();
		$before = array(
			'description'  => isset( $meta[ $role ]['description'] ) ? (string) $meta[ $role ]['description'] : '',
			'hide_toolbar' => ! empty( $meta[ $role ]['hide_toolbar'] ),
		);
		$after  = array(
			'description'  => $before['description'],
			'hide_toolbar' => (bool) $hide,
		);

		if ( $before === $after ) {
			return true;
		}

		$meta[ $role ] = $after;

		if ( ! BLT_Toolkit_Settings::set( 'roles', 'role_meta', $meta ) ) {
			return false;
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'role_details_saved',
				'object_type' => 'role',
				'object_id'   => $role,
				'summary'     => sprintf(
					/* translators: %s: role name. */
					__( 'Saved the details of the %s role.', 'blt-toolkit' ),
					self::role_name( $role )
				),
				'details'     => array(
					'before'  => $before,
					'after'   => $after,
					'context' => 'simple',
				),
			)
		);

		return true;
	}

	/**
	 * The Simple form's controls as posted (`simple[ID]`): a toggle's `on`,
	 * `off` or `indeterminate`; a content row's level, Can delete, Manage
	 * {taxonomy} and whether the script set them (`mods_set`). Keys and
	 * values are cleaned; BLT_Toolkit_Simple_Controls checks them against
	 * the controls that exist.
	 *
	 * @return array
	 */
	private static function posted_simple() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- route() checked the nonce; every key and value is cleaned below.
		if ( ! isset( $_POST['simple'] ) || ! is_array( $_POST['simple'] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST['simple'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return self::clean_simple( (array) $raw );
	}

	/**
	 * Clean a posted `simple` array. Pure.
	 *
	 * @param array $raw Unslashed input.
	 * @return array
	 */
	public static function clean_simple( array $raw ) {
		$key = static function ( $value ) {
			return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ), 0, 191 );
		};
		$out = array();

		foreach ( $raw as $id => $value ) {
			$id = strtolower( $key( $id ) );

			if ( '' === $id ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				$value = strtolower( trim( (string) $value ) );

				if ( in_array( $value, array( 'on', 'off', 'indeterminate' ), true ) ) {
					$out[ $id ] = $value;
				}

				continue;
			}

			if ( ! is_array( $value ) || ! isset( $value['level'] ) || ! is_scalar( $value['level'] ) ) {
				continue;
			}

			$entry = array( 'level' => strtolower( $key( $value['level'] ) ) );

			if ( isset( $value['can_delete'] ) && is_scalar( $value['can_delete'] ) ) {
				$entry['can_delete'] = '1' === (string) $value['can_delete'];
			}

			if ( isset( $value['manage'] ) && is_array( $value['manage'] ) ) {
				$entry['manage'] = array();

				foreach ( $value['manage'] as $tax => $on ) {
					if ( '' !== $key( $tax ) && is_scalar( $on ) ) {
						$entry['manage'][ $key( $tax ) ] = '1' === (string) $on;
					}
				}
			}

			if ( ! empty( $value['mods_set'] ) ) {
				$entry['mods_set'] = true;
			}

			$out[ $id ] = $entry;
		}

		return $out;
	}

	// ---------- User mode (US-10) ----------

	/**
	 * The matrix for one user: Inherit, Grant or Deny per capability, with
	 * where each comes from.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_user( array $state ) {
		if ( ! $state['can_advanced'] ) {
			self::view( 'roles-message', self::advanced_only() );
			return;
		}

		$user = get_userdata( $state['user_id'] );

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			self::view( 'roles-message', self::missing_user( BLT_Toolkit_Admin::page_url( 'roles' ) ) );
			return;
		}

		$actor     = wp_get_current_user();
		$editable  = BLT_Toolkit_Guard::can_change_user_caps( $actor, $user, array(), array() );
		$hidden    = BLT_Toolkit_Guard::hides_admins_from( $actor ) && BLT_Toolkit_Guard::is_toolkit_admin( $user );
		$available = class_exists( 'BLT_Toolkit_User_Caps' ) && is_callable( array( 'BLT_Toolkit_User_Caps', 'save' ) );

		// US-10: the engine's own gate (blt_edit_capabilities plus
		// promote_user on the target, which the Guard extends).
		if ( true === $editable && $available && is_callable( array( 'BLT_Toolkit_User_Caps', 'can_edit' ) ) && ! BLT_Toolkit_User_Caps::can_edit( $actor, $user ) ) {
			$editable = new WP_Error( 'blt_toolkit_forbidden', __( 'You are not allowed to change this user\'s capabilities.', 'blt-toolkit' ) );
		}

		if ( $hidden ) {
			// SEC-6: an administrator is not shown to someone who is not one.
			self::view( 'roles-message', self::missing_user( BLT_Toolkit_Admin::page_url( 'roles' ) ) );
			return;
		}

		$states  = self::user_states( $user );
		$granted = array();

		foreach ( (array) $user->allcaps as $cap => $value ) {
			if ( $value && ! wp_roles()->is_role( (string) $cap ) ) {
				$granted[ (string) $cap ] = true;
			}
		}

		$entries  = BLT_Toolkit_Capability_Catalog::all();
		$node     = $this->current_node( $state );
		$sections = self::visible_sections(
			self::sections_for( BLT_Toolkit_Capability_Catalog::tree(), $node['id'] ),
			$entries,
			array(
				'deprecated' => $state['deprecated'] || 'core-deprecated' === $node['id'],
				'search'     => $state['search'],
				'granted'    => $state['granted'] ? $granted : null,
				'readable'   => $state['readable'],
			)
		);

		$read_only = true !== $editable || ! $available;
		$on_page   = array();
		$index     = 0;
		$rows      = 0;

		foreach ( $sections as $s => $section ) {
			$list = array();

			foreach ( $section['caps'] as $cap ) {
				$info = isset( $states[ $cap ] ) ? $states[ $cap ] : array(
					'state'  => 'inherit',
					'source' => '',
				);

				if ( ! $read_only ) {
					$on_page[] = $cap;
				}

				$list[] = self::row( $entries[ $cap ], $cap, ++$index ) + array(
					'state'     => $info['state'],
					'from'      => '' !== (string) $info['source'] ? (string) $info['source'] : ( isset( $granted[ $cap ] ) ? __( 'Granted', 'blt-toolkit' ) : __( 'Not granted', 'blt-toolkit' ) ),
					'effective' => isset( $granted[ $cap ] ),
					'disabled'  => $read_only,
				);
			}

			$rows += count( $list );

			$sections[ $s ]['rows'] = $list;
		}

		$roles = array();

		foreach ( (array) $user->roles as $role ) {
			$roles[] = array(
				'id'   => (string) $role,
				'name' => self::role_name( (string) $role ),
				'url'  => self::role_url( (string) $role, array( 'view' => 'advanced' ) ),
			);
		}

		self::enqueue();

		self::view(
			'roles-user',
			array(
				'state'     => $state,
				'user'      => $user,
				'user_ref'  => self::user_ref( $user ),
				'roles'     => $roles,
				'role_list' => $this->role_list( $state ),
				'add_url'   => $this->add_role_url( $state ),
				'tree'      => $this->tree_data( $state, $granted ),
				'node'      => $node,
				'sections'  => $sections,
				'leaf'      => 1 === count( $sections ) && $sections[0]['id'] === $node['id'],
				'rows'      => $rows,
				'on_page'   => $on_page,
				'hash'      => self::user_hash( $user ),
				'read_only' => $read_only,
				'reason'    => is_wp_error( $editable ) ? $editable->get_error_message() : ( $available ? '' : __( 'Per-user capabilities cannot be saved on this site yet.', 'blt-toolkit' ) ),
				'back'      => http_build_query( self::state_args( $state ) ),
				'discard'   => self::state_url( $state ),
				'profile'   => current_user_can( 'edit_user', $user->ID ) ? get_edit_user_link( $user->ID ) : '',
			)
		);
	}

	/**
	 * A user's state per capability: Inherit, Grant or Deny, with where an
	 * inherited one comes from. BLT_Toolkit_User_Caps::state() when it is
	 * there, else read here from the capabilities meta and the roles.
	 *
	 * @param WP_User $user User.
	 * @return array<string,array{state:string,source:string}>
	 */
	private static function user_states( WP_User $user ) {
		if ( class_exists( 'BLT_Toolkit_User_Caps' ) && is_callable( array( 'BLT_Toolkit_User_Caps', 'state' ) ) ) {
			$states = BLT_Toolkit_User_Caps::state( $user );

			if ( is_array( $states ) ) {
				$out = array();

				foreach ( $states as $cap => $info ) {
					$out[ (string) $cap ] = array(
						'state'  => isset( $info['state'] ) && in_array( $info['state'], array( 'inherit', 'grant', 'deny' ), true ) ? $info['state'] : 'inherit',
						'source' => isset( $info['source'] ) ? (string) $info['source'] : '',
					);
				}

				return $out;
			}
		}

		$role_caps = array();

		foreach ( (array) $user->roles as $role ) {
			$obj                         = wp_roles()->get_role( (string) $role );
			$role_caps[ (string) $role ] = $obj instanceof WP_Role ? (array) $obj->capabilities : array();
		}

		return self::local_user_states( is_array( $user->caps ) ? $user->caps : array(), $role_caps, self::role_names() );
	}

	/**
	 * A user's state per capability from their capabilities meta and their
	 * roles' capabilities: a key of their own is Grant or Deny ("Granted to
	 * this user", "Denied for this user"); anything else inherits from the
	 * roles that grant or deny it. Role-name keys are memberships, not
	 * capabilities, and are left out. Pure.
	 *
	 * @param array $own       The user's capabilities meta (role keys included).
	 * @param array $role_caps Role ID => capabilities, for the roles they hold, in order.
	 * @param array $names     Role ID => display name, for every role.
	 * @return array<string,array{state:string,source:string}>
	 */
	public static function local_user_states( array $own, array $role_caps, array $names ) {
		$out  = array();
		$from = array();
		$deny = array();

		foreach ( $role_caps as $role => $caps ) {
			$name = isset( $names[ $role ] ) ? (string) $names[ $role ] : (string) $role;

			foreach ( (array) $caps as $cap => $value ) {
				if ( $value ) {
					$from[ (string) $cap ][] = $name;
				} else {
					$deny[ (string) $cap ][] = $name;
				}
			}
		}

		foreach ( array_keys( $from + $deny ) as $cap ) {
			$cap = (string) $cap;

			if ( isset( $deny[ $cap ] ) ) {
				/* translators: %s: comma-separated role names. */
				$source = sprintf( __( 'Denied by %s', 'blt-toolkit' ), implode( ', ', $deny[ $cap ] ) );
			} else {
				/* translators: %s: comma-separated role names. */
				$source = sprintf( __( 'From %s', 'blt-toolkit' ), implode( ', ', $from[ $cap ] ) );
			}

			$out[ $cap ] = array(
				'state'  => 'inherit',
				'source' => $source,
			);
		}

		foreach ( $own as $cap => $value ) {
			$cap = (string) $cap;

			if ( isset( $names[ $cap ] ) ) {
				continue;
			}

			$out[ $cap ] = array(
				'state'  => $value ? 'grant' : 'deny',
				'source' => $value ? __( 'Granted to this user', 'blt-toolkit' ) : __( 'Denied for this user', 'blt-toolkit' ),
			);
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * The lock hash of a user's capabilities form (RO-2 for US-10):
	 * BLT_Toolkit_User_Caps::user_hash() when it is there, else the same
	 * canonical hash of the capabilities meta as stored.
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function user_hash( WP_User $user ) {
		if ( class_exists( 'BLT_Toolkit_User_Caps' ) && is_callable( array( 'BLT_Toolkit_User_Caps', 'user_hash' ) ) ) {
			return (string) BLT_Toolkit_User_Caps::user_hash( $user );
		}

		return BLT_Toolkit_Role_Store::caps_hash( is_array( $user->caps ) ? $user->caps : array() );
	}

	/**
	 * "Display Name (login)".
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private static function user_ref( WP_User $user ) {
		return sprintf(
			/* translators: 1: display name, 2: username. */
			__( '%1$s (%2$s)', 'blt-toolkit' ),
			$user->display_name,
			$user->user_login
		);
	}

	/**
	 * The message page for an Advanced-only page (user mode, compare, delete
	 * capability) opened by someone without `blt_edit_capabilities` (SPEC
	 * 9.3). The screen itself already needs `blt_edit_roles`.
	 *
	 * @return array
	 */
	private static function advanced_only() {
		return array(
			'title'    => __( 'This needs the "Edit capabilities" permission', 'blt-toolkit' ),
			'text'     => __( 'Single capabilities, per-user capabilities and comparing roles are part of Advanced view. You can change roles in Simple view.', 'blt-toolkit' ),
			'back_url' => BLT_Toolkit_Admin::page_url( 'roles' ),
		);
	}

	/**
	 * The message page for a user who does not exist (or is hidden, SEC-6).
	 *
	 * @param string $back_url Where to go back to.
	 * @return array
	 */
	private static function missing_user( $back_url ) {
		return array(
			'title'    => __( 'User not found', 'blt-toolkit' ),
			'text'     => __( 'That user does not exist, or you cannot see them.', 'blt-toolkit' ),
			'back_url' => $back_url,
		);
	}

	/**
	 * The message page for a review or conflict that is no longer stored.
	 *
	 * @param array $state state().
	 * @return array
	 */
	private static function expired( array $state ) {
		return array(
			'title'    => __( 'This page has expired', 'blt-toolkit' ),
			'text'     => __( 'Nothing was saved. A review is kept for an hour, and only for the last change you made. Make the change again.', 'blt-toolkit' ),
			'back_url' => self::state_url(
				$state,
				array(
					'step'            => null,
					self::PENDING_ARG => null,
				)
			),
		);
	}

	/**
	 * The message page for a change the Guard refuses.
	 *
	 * @param WP_Error $error    Refusal.
	 * @param string   $back_url Where to go back to.
	 * @return array
	 */
	private static function refusal( WP_Error $error, $back_url ) {
		return array(
			'title'    => __( 'This change cannot be saved', 'blt-toolkit' ),
			'text'     => self::error_text( $error ),
			'back_url' => $back_url,
		);
	}

	// ---------- Review, conflict, compare, delete ----------

	/**
	 * The review before a save (SEC-10, SEC-13, or more than ten changes):
	 * its own page, so it works without JavaScript.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_review( array $state ) {
		$pending = self::pending( self::arg( self::PENDING_ARG ), array( 'review', 'user_review', 'simple_review' ) );

		if ( null === $pending ) {
			self::view( 'roles-message', self::expired( $state ) );
			return;
		}

		$data = $pending['data'];

		if ( 'user_review' === $pending['kind'] ) {
			$this->render_user_review( $pending );
			return;
		}

		if ( 'simple_review' === $pending['kind'] ) {
			$this->render_simple_review( $pending );
			return;
		}

		$back = BLT_Toolkit_Admin::page_url( 'roles', $data['back'] );
		$dry  = BLT_Toolkit_Role_Store::save_caps(
			$data['role'],
			$data['on_page'],
			$data['checked'],
			$data['hash'],
			array(
				'context'            => 'advanced',
				'dry_run'            => true,
				'confirmed_critical' => true,
				'confirmed_lockout'  => false,
			)
		);

		if ( is_wp_error( $dry ) ) {
			if ( 'blt_toolkit_role_changed' === $dry->get_error_code() ) {
				$conflict = self::conflict_data( $data['role'], $dry, $data );

				self::view( 'roles-conflict', $conflict + array( 'back_url' => $back ) );
				return;
			}

			self::view( 'roles-message', self::refusal( $dry, $back ) );
			return;
		}

		$change = isset( $dry['changes'][ $data['role'] ] ) ? $dry['changes'][ $data['role'] ] : array(
			'add'    => array(),
			'remove' => array(),
		);
		$guard  = isset( $dry['guard'] ) ? $dry['guard'] : true;

		if ( is_wp_error( $guard ) && ! ( 'blt_toolkit_self_lockout' === $guard->get_error_code() && self::confirmable( $guard ) ) ) {
			self::view( 'roles-message', self::refusal( $guard, $back ) );
			return;
		}

		$critical = isset( $dry['critical'][ $data['role'] ] ) ? (array) $dry['critical'][ $data['role'] ] : array();

		self::view(
			'roles-review',
			array(
				'kind'      => 'role',
				'token'     => $pending['token'],
				'name'      => self::role_name( $data['role'] ),
				'subject'   => $data['role'],
				'grants'    => self::cap_rows( array_keys( array_filter( $change['add'] ) ) ),
				'denies'    => self::cap_rows( array_keys( array_diff_key( $change['add'], array_filter( $change['add'] ) ) ) ),
				'removes'   => self::cap_rows( $change['remove'] ),
				'critical'  => self::critical_rows( $critical ),
				'lockout'   => is_wp_error( $guard ),
				'mismatch'  => ! empty( $data['mismatch'] ),
				'action'    => 'blt_toolkit_roles_save',
				'back_url'  => $back,
				'count'     => count( $change['add'] ) + count( $change['remove'] ),
				'threshold' => self::REVIEW_THRESHOLD,
			)
		);
	}

	/**
	 * The review of a per-user change that grants Critical capabilities
	 * (SEC-10, "or to any user").
	 *
	 * @param array $pending Pending entry.
	 * @return void
	 */
	private function render_user_review( array $pending ) {
		$data = $pending['data'];
		$user = get_userdata( (int) $data['user_id'] );
		$back = BLT_Toolkit_Admin::page_url( 'roles', $data['back'] );

		if ( ! $user instanceof WP_User ) {
			self::view( 'roles-message', self::missing_user( $back ) );
			return;
		}

		if ( ! class_exists( 'BLT_Toolkit_User_Caps' ) || ! is_callable( array( 'BLT_Toolkit_User_Caps', 'save' ) ) ) {
			self::view( 'roles-message', self::missing_user( $back ) );
			return;
		}

		$plan = self::user_plan( $user, $data['on_page'], $data['states'], $data['hash'] );

		if ( is_wp_error( $plan ) ) {
			self::view( 'roles-message', self::refusal( $plan, $back ) );
			return;
		}

		self::view(
			'roles-review',
			array(
				'kind'      => 'user',
				'token'     => $pending['token'],
				'name'      => $user->user_login,
				'subject'   => self::user_ref( $user ),
				'grants'    => self::cap_rows( $plan['grants'] ),
				'denies'    => self::cap_rows( $plan['denies'] ),
				'removes'   => self::cap_rows( $plan['inherit'] ),
				'critical'  => self::critical_rows( $plan['critical'] ),
				'lockout'   => false,
				'mismatch'  => ! empty( $data['mismatch'] ),
				'action'    => 'blt_toolkit_roles_user_caps',
				'back_url'  => $back,
				'count'     => count( $plan['grants'] ) + count( $plan['denies'] ) + count( $plan['inherit'] ),
				'threshold' => self::REVIEW_THRESHOLD,
			)
		);
	}

	/**
	 * The page after a save refused because the role changed since the form
	 * was drawn (RO-2): what changed, and what the person's change was.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_conflict( array $state ) {
		$pending = self::pending( self::arg( self::PENDING_ARG ), array( 'conflict' ) );

		if ( null === $pending ) {
			self::view( 'roles-message', self::expired( $state ) );
			return;
		}

		$data = $pending['data'];

		self::view(
			'roles-conflict',
			array(
				'role'      => $data['role'],
				'name'      => self::role_name( $data['role'] ),
				'message'   => $data['message'],
				'diff'      => $data['diff'],
				'diff_html' => is_array( $data['diff'] ) ? ( new BLT_Toolkit_Admin_Activity() )->diff_html( $data['diff'] ) : '',
				'attempted' => $data['attempted'],
				'back_url'  => BLT_Toolkit_Admin::page_url( 'roles', $data['back'] ),
			)
		);
	}

	/**
	 * Compare two roles (RO-25): a read-only, side-by-side difference by
	 * group, worked out with BLT_Toolkit_Role_Diff.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_compare( array $state ) {
		if ( ! $state['can_advanced'] ) {
			self::view( 'roles-message', self::advanced_only() );
			return;
		}

		$roles = (array) wp_roles()->roles;
		$a     = self::clean_role( self::arg( 'a' ) );
		$b     = self::clean_role( self::arg( 'b' ) );
		$a     = isset( $roles[ $a ] ) ? $a : '';
		$b     = isset( $roles[ $b ] ) ? $b : '';
		$names = self::role_names();

		if ( '' === $b ) {
			// Offer a different role than the first one to compare with.
			foreach ( array_keys( $names ) as $id ) {
				if ( (string) $id !== $a ) {
					$pick = (string) $id;
					break;
				}
			}
		}

		$data = array(
			'names'    => $names,
			'a'        => $a,
			'b'        => $b,
			'b_pick'   => '' !== $b ? $b : ( isset( $pick ) ? $pick : '' ),
			'sections' => array(),
			'counts'   => null,
			'back_url' => self::role_url( '' !== $a ? $a : $state['role'] ),
		);

		if ( '' !== $a && '' !== $b && $a !== $b ) {
			$data = array_merge( $data, $this->compare_data( $a, $b ) );
		}

		self::view( 'roles-compare', $data );
	}

	/**
	 * The difference between two roles, by group.
	 *
	 * @param string $a Role ID.
	 * @param string $b Role ID.
	 * @return array{sections:array,counts:array}
	 */
	private function compare_data( $a, $b ) {
		$roles  = (array) wp_roles()->roles;
		$caps_a = isset( $roles[ $a ]['capabilities'] ) && is_array( $roles[ $a ]['capabilities'] ) ? $roles[ $a ]['capabilities'] : array();
		$caps_b = isset( $roles[ $b ]['capabilities'] ) && is_array( $roles[ $b ]['capabilities'] ) ? $roles[ $b ]['capabilities'] : array();

		// The same key on both sides, so the diff compares capabilities only.
		$diff   = BLT_Toolkit_Role_Diff::diff(
			array(
				'compare' => array(
					'name'         => '',
					'capabilities' => $caps_a,
				),
			),
			array(
				'compare' => array(
					'name'         => '',
					'capabilities' => $caps_b,
				),
			)
		);
		$change = isset( $diff['changed']['compare'] ) ? $diff['changed']['compare'] : array();
		$differ = array();

		foreach ( array( 'added', 'removed', 'flipped' ) as $side ) {
			foreach ( isset( $change[ $side ] ) ? (array) $change[ $side ] : array() as $cap => $unused ) {
				$differ[ (string) $cap ] = true;
			}
		}

		$entries  = BLT_Toolkit_Capability_Catalog::all();
		$sections = self::visible_sections(
			self::sections_for( BLT_Toolkit_Capability_Catalog::tree(), 'all' ),
			$entries,
			array(
				'deprecated' => true,
				'search'     => '',
				'granted'    => $differ,
				'readable'   => true,
			)
		);

		foreach ( $sections as $s => $section ) {
			$rows = array();

			foreach ( $section['caps'] as $cap ) {
				$rows[] = array(
					'cap'   => $cap,
					'label' => $entries[ $cap ]['label'],
					'tier'  => $entries[ $cap ]['tier'],
					'a'     => self::cap_state( $caps_a, $cap ),
					'b'     => self::cap_state( $caps_b, $cap ),
				);
			}

			$sections[ $s ]['rows'] = $rows;
		}

		$same = 0;

		foreach ( $caps_a as $cap => $value ) {
			if ( ! isset( $differ[ (string) $cap ] ) && $value ) {
				++$same;
			}
		}

		return array(
			'sections' => $sections,
			'counts'   => array(
				'only_a' => isset( $change['removed'] ) ? count( array_filter( (array) $change['removed'] ) ) : 0,
				'only_b' => isset( $change['added'] ) ? count( array_filter( (array) $change['added'] ) ) : 0,
				'differ' => count( $differ ),
				'same'   => $same,
			),
		);
	}

	/**
	 * `granted`, `denied` or `absent`.
	 *
	 * @param array  $caps Role capabilities.
	 * @param string $cap  Capability.
	 * @return string
	 */
	private static function cap_state( array $caps, $cap ) {
		if ( ! array_key_exists( $cap, $caps ) ) {
			return 'absent';
		}

		return $caps[ $cap ] ? 'granted' : 'denied';
	}

	/**
	 * Delete an orphaned capability (RO-24): who holds it, the dry run, then
	 * the confirmation. Offered only for orphans; registered and core
	 * capabilities are refused even when the URL asks.
	 *
	 * @param array $state state().
	 * @return void
	 */
	private function render_delete_cap( array $state ) {
		if ( ! $state['can_advanced'] ) {
			self::view( 'roles-message', self::advanced_only() );
			return;
		}

		$cap     = self::clean_cap( self::arg( 'cap' ) );
		$entries = BLT_Toolkit_Capability_Catalog::all();
		$back    = self::state_url(
			$state,
			array(
				'step' => null,
				'cap'  => null,
			)
		);
		$data    = array(
			'cap'      => $cap,
			'known'    => '' !== $cap && isset( $entries[ $cap ] ),
			'orphan'   => '' !== $cap && BLT_Toolkit_Capability_Catalog::is_orphan( $cap ),
			'entry'    => '' !== $cap && isset( $entries[ $cap ] ) ? $entries[ $cap ] : null,
			'back_url' => $back,
			'back'     => http_build_query( self::state_args( $state ) ),
			'plan'     => null,
		);

		if ( $data['orphan'] ) {
			$data['plan'] = self::delete_plan( $cap );
		}

		self::view( 'roles-delete-cap', $data );
	}

	/**
	 * Who would lose an orphaned capability, and whether the person may take
	 * it from every one of them: a dry run through the role store for the
	 * roles (the Guard), and Guard::can_change_user_caps() per user.
	 *
	 * @param string $cap Capability.
	 * @return array{roles:array,users:array,guard:true|WP_Error}
	 */
	private static function delete_plan( $cap ) {
		$holders = BLT_Toolkit_Capability_Catalog::holders( $cap );
		$actor   = wp_get_current_user();
		$guard   = true;
		$roles   = array();
		$users   = array();

		foreach ( $holders['roles'] as $role ) {
			$roles[ (string) $role ] = self::role_name( (string) $role );
		}

		if ( array() !== $roles ) {
			$dry = BLT_Toolkit_Role_Store::transaction(
				self::remove_cap_mutator( $cap ),
				array(
					'dry_run' => true,
					'context' => 'advanced',
				)
			);

			if ( is_wp_error( $dry ) ) {
				$guard = $dry;
			} elseif ( isset( $dry['guard'] ) && true !== $dry['guard'] ) {
				$guard = $dry['guard'];
			}
		}

		foreach ( $holders['users'] as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$users[ (int) $user->ID ] = self::user_ref( $user );

			if ( true === $guard ) {
				$check = BLT_Toolkit_Guard::can_change_user_caps( $actor, $user, array(), array( $cap ) );

				if ( is_wp_error( $check ) ) {
					$guard = $check;
				}
			}
		}

		return array(
			'roles' => $roles,
			'users' => $users,
			'guard' => $guard,
		);
	}

	/**
	 * A role-store mutator that takes a capability out of every role.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	private static function remove_cap_mutator( $cap ) {
		return static function ( array $roles ) use ( $cap ) {
			foreach ( $roles as $id => $role ) {
				if ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) && array_key_exists( $cap, $role['capabilities'] ) ) {
					unset( $roles[ $id ]['capabilities'][ $cap ] );
				}
			}

			return $roles;
		};
	}

	// ---------- POST handlers ----------

	/**
	 * Save a role's capabilities from the Advanced matrix (RO-1 to RO-4).
	 *
	 * A dry run through the role store comes first. A refusal that asks for
	 * a review (SEC-10 Critical grants, the SEC-13 self-lockout another
	 * administrator could undo) or a change of more than ten capabilities
	 * goes to the review page; any other refusal comes back as a notice; a
	 * role that changed since the form was drawn goes to the conflict page
	 * (RO-2). Then the real save, with the confirmations given.
	 *
	 * @return void
	 */
	public function handle_save() {
		$token = self::posted( self::PENDING_ARG );

		if ( '' !== $token ) {
			$pending = self::pending( $token, array( 'review' ) );

			if ( null === $pending ) {
				self::back( array(), 'error', __( 'Nothing was saved: that review is no longer available. Make the change again.', 'blt-toolkit' ) );
			}

			$data     = $pending['data'];
			$reviewed = true;
		} else {
			if ( ! self::form_complete() ) {
				self::back( self::posted_back(), 'error', self::truncated_message() );
			}

			$data     = array(
				'role'    => self::clean_role( self::posted( 'role' ) ),
				'on_page' => self::parse_on_page( self::posted_raw( 'on_page' ) ),
				'checked' => self::posted_list( 'caps' ),
				'hash'    => self::posted( 'hash' ),
				'back'    => self::posted_back(),
			);
			$reviewed = false;
		}

		$back = $data['back'];

		// SPEC 9.3: Advanced view needs blt_edit_capabilities. Simple view
		// saves through handle_simple_save() (context `simple`).
		if ( ! self::can_advanced() ) {
			self::back( $back, 'error', __( 'Nothing was saved: changing capabilities one by one needs the "Edit capabilities" permission.', 'blt-toolkit' ) );
		}

		$name               = self::role_name( $data['role'] );
		$confirmed_critical = $reviewed && self::confirms( self::posted( self::CONFIRM_FIELD ), array( $name, self::stored_role_name( $data['role'] ) ) );
		$confirmed_lockout  = $reviewed && '1' === self::posted( self::LOCKOUT_FIELD );
		$args               = array(
			'context'            => 'advanced',
			'confirmed_critical' => $confirmed_critical,
			'confirmed_lockout'  => $confirmed_lockout,
		);

		$dry = BLT_Toolkit_Role_Store::save_caps( $data['role'], $data['on_page'], $data['checked'], $data['hash'], $args + array( 'dry_run' => true ) );

		if ( is_wp_error( $dry ) ) {
			$this->refused( $dry, $data );
		}

		$change = isset( $dry['changes'][ $data['role'] ] ) ? $dry['changes'][ $data['role'] ] : null;

		if ( null === $change ) {
			self::back( $back, 'warning', __( 'Nothing was saved: you did not change anything.', 'blt-toolkit' ) );
		}

		$guard = isset( $dry['guard'] ) ? $dry['guard'] : true;

		if ( is_wp_error( $guard ) ) {
			$code = $guard->get_error_code();

			if ( 'blt_toolkit_critical_review' === $code || ( 'blt_toolkit_self_lockout' === $code && self::confirmable( $guard ) ) ) {
				$data['mismatch'] = $reviewed && '' !== self::posted( self::CONFIRM_FIELD ) && ! $confirmed_critical;

				self::to_review( 'review', $data );
			}

			$this->refused( $guard, $data );
		}

		if ( ! $reviewed && count( $change['add'] ) + count( $change['remove'] ) > self::REVIEW_THRESHOLD ) {
			self::to_review( 'review', $data );
		}

		$result = BLT_Toolkit_Role_Store::save_caps( $data['role'], $data['on_page'], $data['checked'], $data['hash'], $args );

		if ( is_wp_error( $result ) ) {
			$this->refused( $result, $data );
		}

		if ( $reviewed ) {
			self::forget_pending();
		}

		$change = isset( $result['changes'][ $data['role'] ] ) ? $result['changes'][ $data['role'] ] : $change;

		// SEC-13, confirmed: the person can no longer open this screen.
		if ( ! current_user_can( 'blt_edit_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			wp_safe_redirect( admin_url() );
			exit;
		}

		self::back(
			$back,
			'success',
			sprintf(
				/* translators: 1: role name, 2: what changed, such as "2 capabilities granted, 1 removed". */
				__( 'Saved the %1$s role: %2$s.', 'blt-toolkit' ),
				$name,
				self::counts_text( count( array_filter( $change['add'] ) ), count( $change['add'] ) - count( array_filter( $change['add'] ) ), count( $change['remove'] ) )
			),
			self::change_items( $change )
		);
	}

	/**
	 * Save one user's capabilities (US-10) through BLT_Toolkit_User_Caps,
	 * after the SEC-10 review when Critical capabilities are granted.
	 *
	 * @return void
	 */
	public function handle_user_caps() {
		$token = self::posted( self::PENDING_ARG );

		if ( '' !== $token ) {
			$pending = self::pending( $token, array( 'user_review' ) );

			if ( null === $pending ) {
				self::back( array(), 'error', __( 'Nothing was saved: that review is no longer available. Make the change again.', 'blt-toolkit' ) );
			}

			$data     = $pending['data'];
			$reviewed = true;
		} else {
			if ( ! self::form_complete() ) {
				self::back( self::posted_back(), 'error', self::truncated_message() );
			}

			$data     = array(
				'user_id' => absint( self::posted( 'user_id' ) ),
				'on_page' => self::parse_on_page( self::posted_raw( 'on_page' ) ),
				'states'  => self::posted_states(),
				'hash'    => self::posted( 'hash' ),
				'back'    => self::posted_back(),
			);
			$reviewed = false;
		}

		$back = $data['back'];
		$user = get_userdata( (int) $data['user_id'] );

		if ( ! $user instanceof WP_User ) {
			self::back( $back, 'error', __( 'Nothing was saved: that user does not exist.', 'blt-toolkit' ) );
		}

		if ( ! class_exists( 'BLT_Toolkit_User_Caps' ) || ! is_callable( array( 'BLT_Toolkit_User_Caps', 'save' ) ) ) {
			self::back( $back, 'error', __( 'Nothing was saved: per-user capabilities cannot be saved on this site yet.', 'blt-toolkit' ) );
		}

		$plan = self::user_plan( $user, $data['on_page'], $data['states'], $data['hash'] );

		if ( is_wp_error( $plan ) ) {
			self::user_refused( $plan, $back );
		}

		if ( array() === $plan['grants'] && array() === $plan['denies'] && array() === $plan['inherit'] ) {
			self::back( $back, 'warning', __( 'Nothing was saved: you did not change anything.', 'blt-toolkit' ) );
		}

		// SEC-10: a Critical grant to any user is reviewed and confirmed by
		// typing the username.
		$confirmed = $reviewed && self::confirms( self::posted( self::CONFIRM_FIELD ), array( $user->user_login ) );

		if ( array() !== $plan['critical'] && ! $confirmed ) {
			$data['mismatch'] = $reviewed && '' !== self::posted( self::CONFIRM_FIELD );

			self::to_review( 'user_review', $data );
		}

		$result = BLT_Toolkit_User_Caps::save( $user, $data['on_page'], $data['states'], $data['hash'], array( 'confirmed_critical' => $confirmed ) );

		if ( is_wp_error( $result ) ) {
			self::user_refused( $result, $back );
		}

		if ( $reviewed ) {
			self::forget_pending();
		}

		$items = array();

		if ( array() !== $plan['grants'] ) {
			/* translators: %s: comma-separated capability names. */
			$items[] = sprintf( __( 'Granted: %s', 'blt-toolkit' ), implode( ', ', $plan['grants'] ) );
		}

		if ( array() !== $plan['denies'] ) {
			/* translators: %s: comma-separated capability names. */
			$items[] = sprintf( __( 'Denied: %s', 'blt-toolkit' ), implode( ', ', $plan['denies'] ) );
		}

		if ( array() !== $plan['inherit'] ) {
			/* translators: %s: comma-separated capability names. */
			$items[] = sprintf( __( 'Back to what their roles give: %s', 'blt-toolkit' ), implode( ', ', $plan['inherit'] ) );
		}

		self::back(
			$back,
			'success',
			sprintf(
				/* translators: %s: user's display name and username. */
				__( 'Saved the capabilities of %s.', 'blt-toolkit' ),
				self::user_ref( $user )
			),
			$items
		);
	}

	/**
	 * Add a capability to the chosen roles (RO-23), through the role store.
	 *
	 * @return void
	 */
	public function handle_add_cap() {
		$back  = self::posted_back();
		$cap   = trim( self::posted_raw( 'cap_name' ) );
		$roles = array_values( array_intersect( array_map( array( __CLASS__, 'clean_role' ), self::posted_list( 'cap_roles' ) ), array_keys( (array) wp_roles()->roles ) ) );
		$check = BLT_Toolkit_Capability_Catalog::check_new_name( $cap );

		if ( is_wp_error( $check ) ) {
			self::back( $back, 'error', self::error_text( $check ) );
		}

		if ( array() === $roles ) {
			self::back( $back, 'error', __( 'Nothing was added: choose at least one role to receive the capability. A capability no role or user holds is not stored anywhere.', 'blt-toolkit' ) );
		}

		$names = array();

		foreach ( $roles as $role ) {
			$names[] = self::role_name( $role );
		}

		$result = BLT_Toolkit_Role_Store::transaction(
			static function ( array $all ) use ( $cap, $roles ) {
				foreach ( $roles as $role ) {
					if ( ! isset( $all[ $role ] ) || ! is_array( $all[ $role ] ) ) {
						continue;
					}

					// A role stored with capabilities that are not an array
					// grants nothing; start it from none.
					if ( ! isset( $all[ $role ]['capabilities'] ) || ! is_array( $all[ $role ]['capabilities'] ) ) {
						$all[ $role ]['capabilities'] = array();
					}

					$all[ $role ]['capabilities'][ $cap ] = true;
				}

				return $all;
			},
			array(
				'context'     => 'advanced',
				'action'      => 'capability_added',
				'object_type' => 'capability',
				'object_id'   => $cap,
				'summary'     => sprintf(
					/* translators: 1: capability name, 2: comma-separated role names. */
					__( 'Added the capability %1$s to: %2$s.', 'blt-toolkit' ),
					$cap,
					implode( ', ', $names )
				),
				'details'     => array(
					'capability' => $cap,
					'roles'      => $roles,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::back( $back, 'error', self::error_text( $result ), self::error_items( $result ) );
		}

		self::back(
			$back,
			'success',
			sprintf(
				/* translators: 1: capability name, 2: comma-separated role names. */
				__( 'Added the capability %1$s to: %2$s.', 'blt-toolkit' ),
				$cap,
				implode( ', ', $names )
			)
		);
	}

	/**
	 * Delete an orphaned capability from every role and user holding it
	 * (RO-24): orphans only, after the Guard's dry run, with a snapshot of
	 * the roles first (the role store takes it).
	 *
	 * @return void
	 */
	public function handle_delete_cap() {
		$back = self::posted_back();
		$cap  = self::clean_cap( self::posted_raw( 'cap' ) );

		if ( '' === $cap || ! BLT_Toolkit_Capability_Catalog::is_orphan( $cap ) ) {
			self::back( $back, 'error', __( 'Nothing was deleted: only capabilities that nothing on this site registers can be deleted. WordPress, an active content type or taxonomy, or BLT Toolkit uses this one.', 'blt-toolkit' ) );
		}

		$plan = self::delete_plan( $cap );

		if ( true !== $plan['guard'] ) {
			self::back( $back, 'error', self::error_text( $plan['guard'] ), self::error_items( $plan['guard'] ) );
		}

		$details = array(
			'capability' => $cap,
			'roles'      => array_keys( $plan['roles'] ),
			'users'      => array_keys( $plan['users'] ),
		);
		$summary = sprintf(
			/* translators: 1: capability name, 2: "2 roles", 3: "1 user". */
			__( 'Deleted the capability %1$s from %2$s and %3$s.', 'blt-toolkit' ),
			$cap,
			self::count_roles( count( $plan['roles'] ) ),
			self::count_users( count( $plan['users'] ) )
		);

		if ( array() !== $plan['roles'] ) {
			$result = BLT_Toolkit_Role_Store::transaction(
				self::remove_cap_mutator( $cap ),
				array(
					'context'     => 'advanced',
					'action'      => 'capability_deleted',
					'object_type' => 'capability',
					'object_id'   => $cap,
					'summary'     => $summary,
					'details'     => $details,
				)
			);

			if ( is_wp_error( $result ) ) {
				self::back( $back, 'error', self::error_text( $result ), self::error_items( $result ) );
			}
		}

		foreach ( array_keys( $plan['users'] ) as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( $user instanceof WP_User && is_array( $user->caps ) && array_key_exists( $cap, $user->caps ) ) {
				$user->remove_cap( $cap );
			}
		}

		if ( array() === $plan['roles'] && array() !== $plan['users'] ) {
			BLT_Toolkit_Log::record(
				array(
					'module'      => 'roles',
					'action'      => 'capability_deleted',
					'object_type' => 'capability',
					'object_id'   => $cap,
					'summary'     => $summary,
					'details'     => $details,
				)
			);
		}

		$back = self::clean_back( $back );

		unset( $back['s'] );

		self::back( $back, 'success', $summary );
	}

	/**
	 * Save a role's description and "Hide the toolbar on the site" (SPEC
	 * 9.5) through BLT_Toolkit_Settings::set(), with a log row.
	 *
	 * @return void
	 */
	public function handle_details() {
		$back = self::posted_back();
		$role = self::clean_role( self::posted( 'role' ) );

		if ( '' === $role || ! wp_roles()->is_role( $role ) ) {
			self::back( $back, 'error', __( 'Nothing was saved: that role does not exist.', 'blt-toolkit' ) );
		}

		$editable = BLT_Toolkit_Guard::check_role_change( wp_get_current_user(), $role, array(), array() );

		if ( true !== $editable ) {
			self::back( $back, 'error', self::error_text( $editable ) );
		}

		$meta        = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$meta        = is_array( $meta ) ? $meta : array();
		$description = self::posted( 'description' );
		$before      = array(
			'description'  => isset( $meta[ $role ]['description'] ) ? (string) $meta[ $role ]['description'] : '',
			'hide_toolbar' => ! empty( $meta[ $role ]['hide_toolbar'] ),
		);
		$after       = array(
			// The settings layer keeps at most 500 characters (SPEC 9.5).
			'description'  => function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 500 ) : substr( $description, 0, 500 ),
			// In Simple view the toolbar setting is on the Simple form, not
			// this one (`keep_toolbar`), so it stays as it is.
			'hide_toolbar' => '1' === self::posted( 'keep_toolbar' ) ? $before['hide_toolbar'] : '1' === self::posted( 'hide_toolbar' ),
		);

		if ( $before === $after ) {
			self::back( $back, 'warning', __( 'Nothing was saved: you did not change anything.', 'blt-toolkit' ) );
		}

		$meta[ $role ] = $after;

		if ( ! BLT_Toolkit_Settings::set( 'roles', 'role_meta', $meta ) ) {
			self::back( $back, 'error', __( 'The role details could not be saved. Try again.', 'blt-toolkit' ) );
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'role_details_saved',
				'object_type' => 'role',
				'object_id'   => $role,
				'summary'     => sprintf(
					/* translators: %s: role name. */
					__( 'Saved the details of the %s role.', 'blt-toolkit' ),
					self::role_name( $role )
				),
				'details'     => array(
					'before' => $before,
					'after'  => $after,
				),
			)
		);

		self::back(
			$back,
			'success',
			sprintf(
				/* translators: %s: role name. */
				__( 'Saved the details of the %s role.', 'blt-toolkit' ),
				self::role_name( $role )
			)
		);
	}

	/**
	 * "Test as…" (X-3): switch to the chosen user with `redirect_to` set to
	 * the dashboard. The Guard decides through maybe_switch_url(); the switch
	 * link it gives carries its own per-user nonce (SEC-7).
	 *
	 * @return void
	 */
	public function handle_test_as() {
		$back = self::posted_back();
		$user = get_userdata( absint( self::posted( 'user_id' ) ) );

		if ( ! BLT_Toolkit_Plugin::is_active( 'switch' ) || ! class_exists( 'BLT_Toolkit_Switch' ) ) {
			self::back( $back, 'error', __( 'Switching users is turned off, so you cannot test a role as one of its users.', 'blt-toolkit' ) );
		}

		if ( ! $user instanceof WP_User ) {
			self::back( $back, 'error', __( 'Choose someone to test the role as.', 'blt-toolkit' ) );
		}

		if ( false === BLT_Toolkit_Switch::maybe_switch_url( $user ) ) {
			self::back( $back, 'error', __( 'You cannot switch to that user.', 'blt-toolkit' ) );
		}

		$redirect = array( 'redirect_to' => admin_url() );
		$old      = BLT_Toolkit_Switch::old_user();
		$url      = $old instanceof WP_User && (int) $old->ID === (int) $user->ID
			? BLT_Toolkit_Switch::switch_back_url( $old, $redirect )
			: BLT_Toolkit_Switch::switch_to_url( $user, $redirect );

		wp_safe_redirect( $url );
		exit;
	}

	// ---------- Handler helpers ----------

	/**
	 * After a refused save: the conflict page for RO-2, otherwise a notice
	 * with the reason and the capabilities it names. Stops.
	 *
	 * @param WP_Error $error Refusal.
	 * @param array    $data  The submission.
	 * @return void
	 */
	private function refused( WP_Error $error, array $data ) {
		if ( 'blt_toolkit_role_changed' === $error->get_error_code() ) {
			$conflict = self::conflict_data( $data['role'], $error, $data );

			self::to_pending(
				'conflict',
				array(
					'role'      => $data['role'],
					'message'   => $conflict['message'],
					'diff'      => $conflict['diff'],
					'attempted' => $conflict['attempted'],
					'back'      => $data['back'],
				),
				array_merge(
					self::clean_back( $data['back'] ),
					array( 'step' => 'conflict' )
				)
			);
		}

		self::back( $data['back'], 'error', self::error_text( $error ), self::error_items( $error ) );
	}

	/**
	 * After a refused per-user save: a notice saying why, with the
	 * capabilities named. Stops.
	 *
	 * @param WP_Error $error Refusal.
	 * @param array    $back  Editor arguments.
	 * @return void
	 */
	private static function user_refused( WP_Error $error, array $back ) {
		$data = (array) $error->get_error_data();

		if ( isset( $data['status'] ) && 409 === (int) $data['status'] && 'blt_toolkit_user_changed' === $error->get_error_code() ) {
			self::back( $back, 'error', __( 'Nothing was saved: this user\'s roles or capabilities changed since you opened the page. They are shown below as they are now; make your change again.', 'blt-toolkit' ) );
		}

		self::back( $back, 'error', self::error_text( $error ), self::error_items( $error ) );
	}

	/**
	 * What the conflict page shows: the store's message, the difference
	 * between the role as drawn and as stored (when a recent snapshot holds
	 * the drawn copy), and the person's own change, worked out against the
	 * role as it was drawn.
	 *
	 * @param string   $role  Role ID.
	 * @param WP_Error $error `blt_toolkit_role_changed`.
	 * @param array    $data  The submission.
	 * @return array{role:string,name:string,message:string,diff:array|null,diff_html:string,attempted:array}
	 */
	private static function conflict_data( $role, WP_Error $error, array $data ) {
		$info      = (array) $error->get_error_data();
		$diff      = isset( $info['diff'] ) && is_array( $info['diff'] ) ? $info['diff'] : null;
		$obj       = wp_roles()->get_role( $role );
		$current   = $obj instanceof WP_Role ? (array) $obj->capabilities : array();
		$attempted = array(
			'add'    => array(),
			'remove' => array(),
		);

		if ( null !== $diff && isset( $diff['changed'][ $role ] ) && is_array( $diff['changed'][ $role ] ) ) {
			$drawn     = self::revert_change( $current, $diff['changed'][ $role ] );
			$attempted = BLT_Toolkit_Role_Store::compute_changes( $drawn, $data['on_page'], $data['checked'] );
		}

		return array(
			'role'      => $role,
			'name'      => self::role_name( $role ),
			'message'   => $error->get_error_message(),
			'diff'      => $diff,
			'diff_html' => null !== $diff ? ( new BLT_Toolkit_Admin_Activity() )->diff_html( $diff ) : '',
			'attempted' => array(
				'add'    => array_keys( array_filter( $attempted['add'] ) ),
				'remove' => array_values( $attempted['remove'] ),
			),
		);
	}

	/**
	 * What a per-user submission changes, from BLT_Toolkit_User_Caps' own
	 * dry run (confirmed, so it reports the Critical grants instead of
	 * refusing them): the capabilities newly granted, denied, or taken back
	 * to Inherit, and which grants are Critical (SEC-10, which also counts
	 * lifting a deny on a capability a role grants). A WP_Error when the
	 * engine refuses (the user changed since the form was drawn, the Guard).
	 *
	 * @param WP_User $user    User.
	 * @param array   $on_page Capabilities on the form.
	 * @param array   $states  Capability => inherit|grant|deny.
	 * @param string  $hash    Lock hash from render time.
	 * @return array{grants:string[],denies:string[],inherit:string[],critical:string[]}|WP_Error
	 */
	private static function user_plan( WP_User $user, array $on_page, array $states, $hash ) {
		$dry = BLT_Toolkit_User_Caps::save(
			$user,
			$on_page,
			$states,
			$hash,
			array(
				'dry_run'            => true,
				'confirmed_critical' => true,
			)
		);

		if ( is_wp_error( $dry ) ) {
			return $dry;
		}

		$add    = isset( $dry['changes']['add'] ) ? (array) $dry['changes']['add'] : array();
		$grants = array_keys( array_filter( $add ) );
		$denies = array_keys( array_diff_key( $add, array_filter( $add ) ) );
		$back   = isset( $dry['changes']['remove'] ) ? array_values( (array) $dry['changes']['remove'] ) : array();

		sort( $grants, SORT_STRING );
		sort( $denies, SORT_STRING );
		sort( $back, SORT_STRING );

		return array(
			'grants'   => array_map( 'strval', $grants ),
			'denies'   => array_map( 'strval', $denies ),
			'inherit'  => array_map( 'strval', $back ),
			'critical' => isset( $dry['critical'] ) ? array_values( (array) $dry['critical'] ) : array(),
		);
	}

	/**
	 * Keep a submission for the review (or conflict) page and redirect there.
	 * Stops.
	 *
	 * @param string $kind `review`, `user_review` or `simple_review`.
	 * @param array  $data The submission.
	 * @return void
	 */
	private static function to_review( $kind, array $data ) {
		self::to_pending( $kind, $data, array_merge( self::clean_back( $data['back'] ), array( 'step' => 'review' ) ) );
	}

	/**
	 * Store a pending entry for the person and redirect to the page that
	 * shows it. Stops.
	 *
	 * @param string $kind `review`, `user_review`, `simple_review` or `conflict`.
	 * @param array  $data What the page needs.
	 * @param array  $args Query arguments of that page.
	 * @return void
	 */
	private static function to_pending( $kind, array $data, array $args ) {
		$token = strtolower( wp_generate_password( 16, false ) );

		set_transient(
			self::PENDING_TRANSIENT . get_current_user_id(),
			array(
				'token' => $token,
				'kind'  => $kind,
				'data'  => $data,
			),
			self::PENDING_TTL
		);

		$args[ self::PENDING_ARG ] = $token;

		wp_safe_redirect( BLT_Toolkit_Admin::page_url( 'roles', $args ) );
		exit;
	}

	/**
	 * The person's pending entry when the token matches and it is of one of
	 * the kinds asked for, else null.
	 *
	 * @param string   $token Token from the URL or form.
	 * @param string[] $kinds Accepted kinds.
	 * @return array|null
	 */
	private static function pending( $token, array $kinds ) {
		$token = sanitize_key( (string) $token );

		if ( '' === $token ) {
			return null;
		}

		$pending = get_transient( self::PENDING_TRANSIENT . get_current_user_id() );

		if ( ! is_array( $pending ) || ! isset( $pending['token'], $pending['kind'], $pending['data'] ) || ! is_array( $pending['data'] ) ) {
			return null;
		}

		if ( ! hash_equals( (string) $pending['token'], $token ) || ! in_array( $pending['kind'], $kinds, true ) ) {
			return null;
		}

		$pending['data']['back'] = isset( $pending['data']['back'] ) && is_array( $pending['data']['back'] ) ? self::clean_back( $pending['data']['back'] ) : array();

		return $pending;
	}

	/**
	 * Drop the person's pending entry once it was used.
	 *
	 * @return void
	 */
	private static function forget_pending() {
		delete_transient( self::PENDING_TRANSIENT . get_current_user_id() );
	}

	/**
	 * Store the one-time notice (the lifecycle pages' channel, which this
	 * screen prints too) and redirect to the editor. Stops.
	 *
	 * @param array  $args    Editor query arguments (cleaned here).
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 * @param array  $items   Optional list under the message.
	 * @return void
	 */
	private static function back( array $args, $type, $message, array $items = array() ) {
		$args  = self::clean_back( $args );
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

		$args[ BLT_Toolkit_Admin_Role_Lifecycle::NOTICE_ARG ] = $token;

		wp_safe_redirect( BLT_Toolkit_Admin::page_url( 'roles', $args ) );
		exit;
	}

	/**
	 * An error's message, prefixed so it is clear nothing was changed.
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
	 * The capabilities a Guard refusal names (its data's full `caps` list),
	 * as one line for the notice.
	 *
	 * @param WP_Error $error Error.
	 * @return string[]
	 */
	private static function error_items( WP_Error $error ) {
		$data = (array) $error->get_error_data();

		if ( empty( $data['caps'] ) || ! is_array( $data['caps'] ) || count( $data['caps'] ) <= 5 ) {
			return array();
		}

		return array(
			sprintf(
				/* translators: %s: comma-separated capability names. */
				__( 'All of them: %s', 'blt-toolkit' ),
				implode( ', ', array_map( 'strval', $data['caps'] ) )
			),
		);
	}

	/**
	 * Whether a Guard refusal can be confirmed through (SEC-13).
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function confirmable( WP_Error $error ) {
		$data = (array) $error->get_error_data();

		return ! empty( $data['confirmable'] );
	}

	/**
	 * The notice's lines for a role save: what was granted, denied and
	 * removed.
	 *
	 * @param array $change One role's change set.
	 * @return string[]
	 */
	private static function change_items( array $change ) {
		$items  = array();
		$grants = array_keys( array_filter( $change['add'] ) );
		$denies = array_keys( array_diff_key( $change['add'], array_filter( $change['add'] ) ) );

		if ( array() !== $grants ) {
			/* translators: %s: comma-separated capability names. */
			$items[] = sprintf( __( 'Granted: %s', 'blt-toolkit' ), implode( ', ', $grants ) );
		}

		if ( array() !== $denies ) {
			/* translators: %s: comma-separated capability names. */
			$items[] = sprintf( __( 'Denied: %s', 'blt-toolkit' ), implode( ', ', $denies ) );
		}

		if ( array() !== $change['remove'] ) {
			/* translators: %s: comma-separated capability names. */
			$items[] = sprintf( __( 'Removed: %s', 'blt-toolkit' ), implode( ', ', $change['remove'] ) );
		}

		return $items;
	}

	/**
	 * "2 capabilities granted, 1 removed".
	 *
	 * @param int $granted Granted.
	 * @param int $denied  Denied.
	 * @param int $removed Removed.
	 * @return string
	 */
	private static function counts_text( $granted, $denied, $removed ) {
		$parts = array();

		if ( $granted > 0 ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s capability granted', '%s capabilities granted', $granted, 'blt-toolkit' ), number_format_i18n( $granted ) );
		}

		if ( $denied > 0 ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s capability denied', '%s capabilities denied', $denied, 'blt-toolkit' ), number_format_i18n( $denied ) );
		}

		if ( $removed > 0 ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s capability removed', '%s capabilities removed', $removed, 'blt-toolkit' ), number_format_i18n( $removed ) );
		}

		return array() === $parts ? __( 'nothing changed', 'blt-toolkit' ) : implode( ', ', $parts );
	}

	/**
	 * "1 role", "2 roles".
	 *
	 * @param int $count Number of roles.
	 * @return string
	 */
	public static function count_roles( $count ) {
		/* translators: %s: number of roles. */
		return sprintf( _n( '%s role', '%s roles', (int) $count, 'blt-toolkit' ), number_format_i18n( (int) $count ) );
	}

	/**
	 * "1 user", "2 users".
	 *
	 * @param int $count Number of users.
	 * @return string
	 */
	public static function count_users( $count ) {
		/* translators: %s: number of users. */
		return sprintf( _n( '%s user', '%s users', (int) $count, 'blt-toolkit' ), number_format_i18n( (int) $count ) );
	}

	/**
	 * Review rows: label, raw name and tier for each capability.
	 *
	 * @param string[] $caps Capabilities.
	 * @return array[]
	 */
	private static function cap_rows( array $caps ) {
		$out = array();

		foreach ( $caps as $cap ) {
			$cap   = (string) $cap;
			$out[] = array(
				'cap'   => $cap,
				'label' => BLT_Toolkit_Capability_Catalog::label( $cap ),
				'tier'  => BLT_Toolkit_Caps::tier( $cap ),
			);
		}

		return $out;
	}

	/**
	 * SEC-10 rows: each Critical capability with what it lets people do.
	 *
	 * @param string[] $caps Critical capabilities.
	 * @return array[]
	 */
	private static function critical_rows( array $caps ) {
		$out = array();

		foreach ( self::cap_rows( $caps ) as $row ) {
			$out[] = $row + array( 'consequence' => self::consequence( $row['cap'] ) );
		}

		return $out;
	}

	/**
	 * What a Critical capability lets its holders do, in plain English
	 * (SEC-10).
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function consequence( $cap ) {
		$plugins = __( 'Plugins run code on the server, so this can be used to take over the whole site.', 'blt-toolkit' );
		$themes  = __( 'Themes run code on the server, so this can be used to take over the whole site.', 'blt-toolkit' );

		$list = array(
			'manage_options'        => __( 'Can change every site setting, including the site address, who may register and the role new users get.', 'blt-toolkit' ),
			'activate_plugins'      => __( 'Can turn plugins on and off, including security plugins and BLT Toolkit itself.', 'blt-toolkit' ),
			'install_plugins'       => __( 'Can install new plugins.', 'blt-toolkit' ) . ' ' . $plugins,
			'update_plugins'        => __( 'Can replace plugins with other versions.', 'blt-toolkit' ) . ' ' . $plugins,
			'delete_plugins'        => __( 'Can delete plugins and the features they provide.', 'blt-toolkit' ),
			'edit_plugins'          => __( 'Can edit plugin files.', 'blt-toolkit' ) . ' ' . $plugins,
			'install_themes'        => __( 'Can install new themes.', 'blt-toolkit' ) . ' ' . $themes,
			'update_themes'         => __( 'Can replace themes with other versions.', 'blt-toolkit' ) . ' ' . $themes,
			'delete_themes'         => __( 'Can delete themes.', 'blt-toolkit' ),
			'edit_themes'           => __( 'Can edit theme files.', 'blt-toolkit' ) . ' ' . $themes,
			'update_core'           => __( 'Can update WordPress itself.', 'blt-toolkit' ),
			'unfiltered_html'       => __( 'Can publish scripts that run in the browser of everyone who views the content, administrators included.', 'blt-toolkit' ),
			'unfiltered_upload'     => __( 'Can upload any type of file, including files the server may run.', 'blt-toolkit' ),
			'edit_users'            => __( 'Can change other people\'s email addresses and passwords, and so sign in as them.', 'blt-toolkit' ),
			'create_users'          => __( 'Can add new user accounts.', 'blt-toolkit' ),
			'delete_users'          => __( 'Can delete user accounts and everything they wrote.', 'blt-toolkit' ),
			'remove_users'          => __( 'Can remove people from the site.', 'blt-toolkit' ),
			'promote_users'         => __( 'Can change people\'s roles, up to the most powerful role they may give.', 'blt-toolkit' ),
			'blt_switch_users'      => __( 'Can sign in as other users without their password.', 'blt-toolkit' ),
			'blt_change_usernames'  => __( 'Can change other people\'s usernames, which signs them out.', 'blt-toolkit' ),
			'blt_edit_roles'        => __( 'Can change what roles may do.', 'blt-toolkit' ),
			'blt_edit_capabilities' => __( 'Can grant and take away single capabilities on roles and users.', 'blt-toolkit' ),
			'blt_create_roles'      => __( 'Can create roles.', 'blt-toolkit' ),
			'blt_delete_roles'      => __( 'Can delete and merge roles, moving their users to other roles.', 'blt-toolkit' ),
			'blt_reset_roles'       => __( 'Can reset roles, restore snapshots and import roles.', 'blt-toolkit' ),
		);

		return isset( $list[ $cap ] ) ? $list[ $cap ] : __( 'Can be used to take over the site or other people\'s accounts.', 'blt-toolkit' );
	}

	// ---------- Request reading ----------

	/**
	 * Whether the current user may use Advanced view (SPEC 9.3).
	 *
	 * @return bool
	 */
	private static function can_advanced() {
		return current_user_can( 'blt_edit_capabilities' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
	}

	/**
	 * A scalar GET value as plain text, or ''.
	 *
	 * @param string $key Query argument.
	 * @return string
	 */
	private static function arg( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Chooses what to display; nothing is written.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * A scalar POST value as plain text, or ''. Only called after route()
	 * checked the nonce.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- route() checked the nonce.
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A scalar POST value unslashed but otherwise as sent, for values that
	 * are validated by their own rules (capability names, JSON).
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted_raw( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- route() checked the nonce; callers validate the value.
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * A POST list of strings (`name[]`), unslashed; values are capability or
	 * role names, cleaned by the caller.
	 *
	 * @param string $key Field.
	 * @return string[]
	 */
	private static function posted_list( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- route() checked the nonce; the store cleans capability names (clean_names()).
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$out = array();

		foreach ( wp_unslash( $_POST[ $key ] ) as $value ) {
			if ( is_string( $value ) ) {
				$out[] = $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return BLT_Toolkit_Role_Store::clean_names( $out );
	}

	/**
	 * The per-user states posted (`state[cap] = inherit|grant|deny`).
	 *
	 * @return array<string,string>
	 */
	private static function posted_states() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- route() checked the nonce; keys are cleaned with clean_names() and values whitelisted.
		if ( ! isset( $_POST['state'] ) || ! is_array( $_POST['state'] ) ) {
			return array();
		}

		$out = array();

		foreach ( wp_unslash( $_POST['state'] ) as $cap => $value ) {
			$names = BLT_Toolkit_Role_Store::clean_names( array( (string) $cap ) );

			if ( array() !== $names && is_string( $value ) && in_array( $value, array( 'inherit', 'grant', 'deny' ), true ) ) {
				$out[ $names[0] ] = $value;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return $out;
	}

	/**
	 * The editor arguments posted in the `back` field.
	 *
	 * @return array<string,string>
	 */
	private static function posted_back() {
		$raw = array();

		wp_parse_str( self::posted_raw( 'back' ), $raw );

		return self::clean_back( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Whether the form arrived whole: its last field is there (see
	 * END_FIELD).
	 *
	 * @return bool
	 */
	private static function form_complete() {
		return '1' === self::posted( self::END_FIELD );
	}

	/**
	 * The message for a form PHP cut short.
	 *
	 * @return string
	 */
	private static function truncated_message() {
		return sprintf(
			/* translators: %s: the PHP setting's name. */
			__( 'Nothing was saved: this page has more fields than the server accepts in one form (%s), so part of it was lost. Choose a group or filter the list, then save.', 'blt-toolkit' ),
			'max_input_vars'
		);
	}

	// ---------- Pure helpers (unit-tested) ----------

	/**
	 * The capabilities on a form, from its `on_page` field (a JSON list),
	 * cleaned as the store cleans names (RO-1). Anything that is not a JSON
	 * list of strings gives an empty set, so nothing changes. Pure.
	 *
	 * @param string $json Field value.
	 * @return string[]
	 */
	public static function parse_on_page( $json ) {
		$list = json_decode( (string) $json );

		if ( ! is_array( $list ) ) {
			return array();
		}

		return BLT_Toolkit_Role_Store::clean_names( $list );
	}

	/**
	 * The sections a group shows (RO-13): a leaf group is one section; a
	 * group with children, and All, show one section per leaf below them in
	 * tree order, then whatever the group holds that no leaf does. Each
	 * capability appears once, in the first section holding it, so a form
	 * never has two boxes for one capability. Empty sections are dropped.
	 * Pure.
	 *
	 * @param array[] $tree    The catalog tree.
	 * @param string  $node_id Group asked for.
	 * @return array[] Each array( 'id', 'label', 'caps' ).
	 */
	public static function sections_for( array $tree, $node_id ) {
		$node = self::find( $tree, (string) $node_id );

		if ( null === $node ) {
			$node = self::find( $tree, 'all' );
		}

		if ( null === $node ) {
			return array();
		}

		if ( ! empty( $node['children'] ) ) {
			$leaves = self::leaves( $node['children'] );
		} elseif ( 'all' === $node['id'] ) {
			$others = array();

			foreach ( $tree as $top ) {
				if ( 'all' !== $top['id'] ) {
					$others[] = $top;
				}
			}

			$leaves = self::leaves( $others );
		} else {
			$leaves = array( $node );
		}

		$seen = array();
		$out  = array();

		foreach ( $leaves as $leaf ) {
			$caps = array();

			foreach ( isset( $leaf['caps'] ) ? (array) $leaf['caps'] : array() as $cap ) {
				$cap = (string) $cap;

				if ( ! isset( $seen[ $cap ] ) ) {
					$seen[ $cap ] = true;
					$caps[]       = $cap;
				}
			}

			if ( array() !== $caps ) {
				$out[] = array(
					'id'    => (string) $leaf['id'],
					'label' => (string) $leaf['label'],
					'caps'  => $caps,
				);
			}
		}

		$rest = array();

		foreach ( isset( $node['caps'] ) ? (array) $node['caps'] : array() as $cap ) {
			if ( ! isset( $seen[ (string) $cap ] ) ) {
				$seen[ (string) $cap ] = true;
				$rest[]                = (string) $cap;
			}
		}

		if ( array() !== $rest ) {
			$out[] = array(
				'id'    => (string) $node['id'],
				'label' => (string) $node['label'],
				'caps'  => $rest,
			);
		}

		return $out;
	}

	/**
	 * Sections after the toolbar (RO-22): only capabilities the catalog
	 * knows; deprecated ones only when shown; the quick filter matched
	 * against label and raw name, ignoring case; "Granted only"; sorted by
	 * label with readable names, by raw name without. Each capability once.
	 * Empty sections are dropped. Pure.
	 *
	 * @param array[] $sections sections_for().
	 * @param array   $entries  Catalog entries.
	 * @param array   $opts {
	 *     @type bool       $deprecated Show deprecated capabilities.
	 *     @type string     $search     Quick filter.
	 *     @type array|null $granted    Capability => true to keep only those, or null for all.
	 *     @type bool       $readable   Sort by label (true) or raw name.
	 *     @type array|null $only       Capability names to keep (one Simple control's, RS-4), or null for all.
	 * }
	 * @return array[] Each array( 'id', 'label', 'caps' ).
	 */
	public static function visible_sections( array $sections, array $entries, array $opts ) {
		$deprecated = ! empty( $opts['deprecated'] );
		$search     = isset( $opts['search'] ) ? self::lower( trim( (string) $opts['search'] ) ) : '';
		$granted    = isset( $opts['granted'] ) && is_array( $opts['granted'] ) ? $opts['granted'] : null;
		$readable   = ! isset( $opts['readable'] ) || ! empty( $opts['readable'] );
		$only       = isset( $opts['only'] ) && is_array( $opts['only'] ) ? array_fill_keys( array_map( 'strval', $opts['only'] ), true ) : null;
		$seen       = array();
		$out        = array();

		foreach ( $sections as $section ) {
			$caps = array();

			foreach ( (array) $section['caps'] as $cap ) {
				$cap = (string) $cap;

				if ( isset( $seen[ $cap ] ) || ! isset( $entries[ $cap ] ) || ( null !== $only && ! isset( $only[ $cap ] ) ) ) {
					continue;
				}

				$entry = $entries[ $cap ];

				if ( ! $deprecated && ! empty( $entry['deprecated'] ) ) {
					continue;
				}

				if ( null !== $granted && empty( $granted[ $cap ] ) ) {
					continue;
				}

				if ( '' !== $search && false === strpos( self::lower( ( isset( $entry['label'] ) ? (string) $entry['label'] : '' ) . ' ' . $cap ), $search ) ) {
					continue;
				}

				$seen[ $cap ] = true;
				$caps[ $cap ] = $readable && isset( $entry['label'] ) ? self::lower( (string) $entry['label'] ) . "\0" . $cap : $cap;
			}

			if ( array() === $caps ) {
				continue;
			}

			asort( $caps, SORT_STRING );

			$out[] = array(
				'id'    => (string) $section['id'],
				'label' => (string) $section['label'],
				'caps'  => array_map( 'strval', array_keys( $caps ) ),
			);
		}

		return $out;
	}

	/**
	 * A role's capabilities as they were before a change, from the
	 * capabilities now and that role's entry in a Role_Diff::diff() from
	 * then to now: added ones go, removed ones come back with their value,
	 * flipped ones take their old value. Pure.
	 *
	 * @param array $caps   Capabilities now.
	 * @param array $change `added`, `removed`, `flipped` (Role_Diff::diff()'s changed entry).
	 * @return array
	 */
	public static function revert_change( array $caps, array $change ) {
		foreach ( isset( $change['added'] ) ? (array) $change['added'] : array() as $cap => $unused ) {
			unset( $caps[ (string) $cap ] );
		}

		foreach ( isset( $change['removed'] ) ? (array) $change['removed'] : array() as $cap => $value ) {
			$caps[ (string) $cap ] = (bool) $value;
		}

		foreach ( isset( $change['flipped'] ) ? (array) $change['flipped'] : array() as $cap => $flip ) {
			$caps[ (string) $cap ] = is_array( $flip ) && ! empty( $flip['from'] );
		}

		return $caps;
	}

	/**
	 * Whether a typed confirmation matches one of the names (SEC-10):
	 * surrounding spaces and case are ignored, as for the Support Agent role.
	 * An empty entry never matches. Pure.
	 *
	 * @param string   $typed What was typed.
	 * @param string[] $names Accepted names.
	 * @return bool
	 */
	public static function confirms( $typed, array $names ) {
		$typed = self::lower( trim( (string) $typed ) );

		if ( '' === $typed ) {
			return false;
		}

		foreach ( $names as $name ) {
			$name = self::lower( trim( (string) $name ) );

			if ( '' !== $name && $name === $typed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A role ID from a URL or a form. WordPress accepts any string as a role
	 * ID (`add_role( 'Event Manager', ... )`), so one that exists is used
	 * exactly as stored; anything else keeps only `[A-Za-z0-9_-]`, at most
	 * 191 characters. Pure when `$known` is given; otherwise the site's role
	 * IDs are read from wp_roles().
	 *
	 * @param string        $value Raw value.
	 * @param string[]|null $known The site's role IDs, or null to read them.
	 * @return string
	 */
	public static function clean_role( $value, $known = null ) {
		$value = (string) $value;

		if ( null === $known ) {
			$known = function_exists( 'wp_roles' ) ? array_keys( (array) wp_roles()->roles ) : array();
		}

		if ( '' !== $value && in_array( $value, array_map( 'strval', (array) $known ), true ) ) {
			return $value;
		}

		return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value ), 0, 191 );
	}

	/**
	 * A group ID's characters only (`[a-z0-9_-]`), at most 191. Pure.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_group( $value ) {
		return substr( (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ), 0, 191 );
	}

	/**
	 * A quick filter: one line, at most SEARCH_MAX characters. Pure.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_search( $value ) {
		$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::SEARCH_MAX ) : substr( $value, 0, self::SEARCH_MAX );
	}

	/**
	 * A capability name as the store accepts it, or ''. Pure.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_cap( $value ) {
		$names = BLT_Toolkit_Role_Store::clean_names( array( (string) $value ) );

		return array() !== $names ? $names[0] : '';
	}

	/**
	 * Lowercase, multibyte-aware when possible. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * A node by ID, depth first. Pure.
	 *
	 * @param array[] $nodes Nodes.
	 * @param string  $id    ID.
	 * @return array|null
	 */
	private static function find( array $nodes, $id ) {
		foreach ( $nodes as $node ) {
			if ( isset( $node['id'] ) && (string) $node['id'] === $id ) {
				return $node;
			}

			$found = self::find( isset( $node['children'] ) ? (array) $node['children'] : array(), $id );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * The leaves under some nodes, in order (a node without children is its
	 * own leaf). Pure.
	 *
	 * @param array[] $nodes Nodes.
	 * @return array[]
	 */
	private static function leaves( array $nodes ) {
		$out = array();

		foreach ( $nodes as $node ) {
			if ( empty( $node['children'] ) ) {
				$out[] = $node;
			} else {
				$out = array_merge( $out, self::leaves( (array) $node['children'] ) );
			}
		}

		return $out;
	}

	// ---------- WordPress reads ----------

	/**
	 * Users per role, one counting query, without administrators for someone
	 * they are hidden from (SEC-6); null on a large site (SEC-9).
	 *
	 * @return array<string,int>|null
	 */
	private static function user_counts() {
		static $counts = false;

		if ( false === $counts ) {
			// SEC-9: on a site WordPress calls large, core's Users screen
			// skips its per-role counts, and so does this one.
			if ( ! class_exists( 'BLT_Toolkit_Role_Lifecycle' ) || ( function_exists( 'wp_is_large_user_count' ) && wp_is_large_user_count() ) ) {
				$counts = null;
			} else {
				$counts = BLT_Toolkit_Role_Lifecycle::user_counts();
			}
		}

		return $counts;
	}

	/**
	 * The default role and the additional default roles (US-2).
	 *
	 * @return string[]
	 */
	private static function default_roles() {
		$extra = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );

		return array_values( array_unique( array_merge( array( (string) get_option( 'default_role' ) ), is_array( $extra ) ? array_map( 'strval', $extra ) : array() ) ) );
	}

	/**
	 * Role ID => display name (translated).
	 *
	 * @return array<string,string>
	 */
	private static function role_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ] = translate_user_role( (string) $name );
		}

		return $out;
	}

	/**
	 * A role's display name, translated, or its ID.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	private static function role_name( $role ) {
		$names = self::role_names();

		return isset( $names[ $role ] ) && '' !== $names[ $role ] ? $names[ $role ] : (string) $role;
	}

	/**
	 * A role's display name as stored (untranslated).
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	private static function stored_role_name( $role ) {
		$names = (array) wp_roles()->role_names;

		return isset( $names[ $role ] ) ? (string) $names[ $role ] : '';
	}

	// ---------- Output ----------

	/**
	 * Load the screen's script with its settings in `bltToolkit.roles`
	 * (merged into `window.bltToolkit`). Footer, deferred.
	 *
	 * @return void
	 */
	private static function enqueue() {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			BLT_TOOLKIT_URL . 'assets/js/blt-toolkit-roles.js',
			array(),
			BLT_TOOLKIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'switchSearch' => esc_url_raw( rest_url( BLT_TOOLKIT_REST_NS . '/switch/users' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'dashboard'    => esc_url_raw( admin_url() ),
			'strings'      => array(
				// Count-neutral wording: the browser cannot pick a plural form.
				'noChanges'  => __( 'No unsaved changes.', 'blt-toolkit' ),
				/* translators: %s: number of changes. */
				'changes'    => __( 'Unsaved changes: %s.', 'blt-toolkit' ),
				/* translators: 1: number of capabilities shown, 2: number on the page. */
				'shown'      => __( 'Capabilities shown: %1$s of %2$s.', 'blt-toolkit' ),
				/* translators: %s: number of capabilities shown. */
				'shownBadge' => __( 'Shown: %s', 'blt-toolkit' ),
				'discarded'  => __( 'Your changes were discarded.', 'blt-toolkit' ),
				'pressEnter' => __( 'Press Enter to search every capability, not only those listed.', 'blt-toolkit' ),
				'pressApply' => __( 'Press Apply to list every capability again, not only the granted ones.', 'blt-toolkit' ),
				'searching'  => __( 'Searching…', 'blt-toolkit' ),
				'noUsers'    => __( 'Nobody with this role you can switch to matches.', 'blt-toolkit' ),
				/* translators: %s: number of users found. */
				'foundUsers' => __( 'Users found: %s.', 'blt-toolkit' ),
				'failed'     => __( 'The search did not work. Try again, or reload the page.', 'blt-toolkit' ),
				// Simple view (RS-5, RS-6).
				'allowed'    => __( 'Allowed', 'blt-toolkit' ),
				'notAllowed' => __( 'Not allowed', 'blt-toolkit' ),
				'partly'     => __( 'Partly allowed', 'blt-toolkit' ),
				'hidden'     => __( 'Hidden', 'blt-toolkit' ),
				'shownState' => __( 'Shown', 'blt-toolkit' ),
				'canDelete'  => __( 'can delete', 'blt-toolkit' ),
				/* translators: %s: plural taxonomy name, such as Event Categories. */
				'manages'    => __( 'manages %s', 'blt-toolkit' ),
				/* translators: 1: control, such as Events, 2: state before, 3: state after. */
				'line'       => __( '%1$s: %2$s → %3$s.', 'blt-toolkit' ),
				/* translators: %s: comma-separated capability labels. */
				'adds'       => __( 'Adds: %s.', 'blt-toolkit' ),
				/* translators: %s: comma-separated capability labels. */
				'removes'    => __( 'Removes: %s.', 'blt-toolkit' ),
				'granted'    => __( 'Granted', 'blt-toolkit' ),
				'removed'    => __( 'Removed', 'blt-toolkit' ),
				/* translators: %s: number of capabilities. */
				'touches'    => __( 'Nothing is saved yet. Capabilities this change touches: %s.', 'blt-toolkit' ),
				/* translators: %s: number of capabilities. */
				'large'      => __( 'Changes to more than %s capabilities are shown for review before they are saved.', 'blt-toolkit' ),
				'noChange'   => __( 'Nothing to save: no control was changed.', 'blt-toolkit' ),
			),
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.bltToolkit = window.bltToolkit || {}; window.bltToolkit.roles = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Tier => badge type and label (SEC-11), as on the lifecycle pages.
	 *
	 * @param string $tier Tier.
	 * @return string Escaped badge markup.
	 */
	public static function tier_badge( $tier ) {
		switch ( $tier ) {
			case BLT_Toolkit_Caps::CRITICAL:
				return BLT_Toolkit_Admin::badge( 'cancelled', __( 'Critical', 'blt-toolkit' ) );
			case BLT_Toolkit_Caps::ELEVATED:
				return BLT_Toolkit_Admin::badge( 'pending', __( 'Elevated', 'blt-toolkit' ) );
		}

		return BLT_Toolkit_Admin::badge( 'off', __( 'Standard', 'blt-toolkit' ) );
	}

	/**
	 * Include a view with its data as variables.
	 *
	 * The parameters have names no view uses, because extract() skips any
	 * key that would overwrite them (a view's `$name` once printed the view's
	 * file name).
	 *
	 * @param string $blt_tk_view_file View name (admin/views/{name}.php).
	 * @param array  $blt_tk_view_data Variables for the view.
	 * @return void
	 */
	private static function view( $blt_tk_view_file, array $blt_tk_view_data ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, documented at the top of each view.
		extract( $blt_tk_view_data, EXTR_SKIP );

		require BLT_TOOLKIT_DIR . 'admin/views/' . $blt_tk_view_file . '.php';
	}

	/**
	 * Include a partial view from another view, with its data.
	 *
	 * @param string $name View name.
	 * @param array  $data Variables.
	 * @return void
	 */
	public static function partial( $name, array $data ) {
		self::view( $name, $data );
	}
}
