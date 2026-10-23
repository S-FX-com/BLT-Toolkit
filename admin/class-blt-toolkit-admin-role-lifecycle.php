<?php
/**
 * Roles screen: the role lifecycle sub-views (add, rename, move users,
 * delete, merge) and their admin-post.php handlers.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lifecycle pages of the Roles screen (SPEC 9.4, 13.2).
 *
 * The Roles screen (`admin.php?page=blt-toolkit-roles`) shows one of these
 * instead of the role editor when its URL carries `action=add`, `rename`,
 * `move`, `delete` or `merge`: it calls render(). Each form posts to
 * admin-post.php; BLT_Toolkit_Admin's router checks the method, the
 * capability (post_actions()) and the nonce, then hands the request to
 * handle(), which calls BLT_Toolkit_Role_Lifecycle and redirects back
 * (post / redirect / get, SEC-7). Rendering writes nothing (RO-5): every
 * count shown is a dry run.
 *
 * The result of a POST comes back as a one-time notice kept for the person
 * in a short-lived transient and named by a random token in the URL
 * (`blt_lc`), so the URL never carries message text (a crafted link shows
 * nothing) and the Guard's exact reasons (which capabilities, which rule)
 * reach the screen. print_notice() prints it; the role editor calls it
 * too, because a successful add or rename lands there.
 */
class BLT_Toolkit_Admin_Role_Lifecycle {

	/**
	 * Query argument naming the sub-view on the Roles screen.
	 */
	const ARG = 'action';

	/**
	 * Query argument naming the role a sub-view is about (and the role the
	 * role editor opens).
	 */
	const ROLE_ARG = 'role';

	/**
	 * Query argument naming the role editor's view (Simple or Advanced),
	 * passed through so a new role opens in the view the person was using
	 * (RO-30).
	 */
	const VIEW_ARG = 'view';

	/**
	 * Query argument carrying the one-time notice's token.
	 */
	const NOTICE_ARG = 'blt_lc';

	/**
	 * Transient prefix of the one-time notice (plus the user ID).
	 */
	const NOTICE_TRANSIENT = 'blt_toolkit_lifecycle_notice_';

	/**
	 * Field of the SEC-10 typed confirmation.
	 */
	const CONFIRM_FIELD = 'confirm_name';

	/**
	 * Sub-view => capability (SPEC 5.1). The Roles screen itself needs
	 * `blt_edit_roles`.
	 */
	const VIEWS = array(
		'add'    => 'blt_create_roles',
		'rename' => 'blt_edit_roles',
		'move'   => 'blt_create_roles',
		'delete' => 'blt_delete_roles',
		'merge'  => 'blt_delete_roles',
	);

	/**
	 * admin-post.php action => array( handler method, capability ).
	 */
	const ACTIONS = array(
		'blt_toolkit_role_add'    => array( 'handle_add', 'blt_create_roles' ),
		'blt_toolkit_role_rename' => array( 'handle_rename', 'blt_edit_roles' ),
		'blt_toolkit_role_move'   => array( 'handle_move', 'blt_create_roles' ),
		'blt_toolkit_role_delete' => array( 'handle_delete', 'blt_delete_roles' ),
		'blt_toolkit_role_merge'  => array( 'handle_merge', 'blt_delete_roles' ),
	);

	/**
	 * Tier => badge type and label, as on the Support Agent preview.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function tier_badges() {
		return array(
			BLT_Toolkit_Caps::CRITICAL => array( 'cancelled', __( 'Critical', 'blt-toolkit' ) ),
			BLT_Toolkit_Caps::ELEVATED => array( 'pending', __( 'Elevated', 'blt-toolkit' ) ),
			BLT_Toolkit_Caps::STANDARD => array( 'off', __( 'Standard', 'blt-toolkit' ) ),
		);
	}

	// ---------- Wiring ----------

	/**
	 * The admin-post.php actions in BLT_Toolkit_Admin::post_actions()'s
	 * format, for the router to merge: each goes to the Roles screen's
	 * `handle_lifecycle` method, which calls handle().
	 *
	 * @return array<string,array{screen:string,method:string,cap:string}>
	 */
	public static function post_actions() {
		$out = array();

		foreach ( self::ACTIONS as $action => $route ) {
			$out[ $action ] = array(
				'screen' => 'roles',
				'method' => 'handle_lifecycle',
				'cap'    => $route[1],
			);
		}

		return $out;
	}

	/**
	 * Whether a sub-view exists.
	 *
	 * @param string $view Sub-view key.
	 * @return bool
	 */
	public static function is_view( $view ) {
		return is_string( $view ) && isset( self::VIEWS[ $view ] );
	}

	/**
	 * The sub-view the current request asks for, or ''.
	 *
	 * @return string
	 */
	public static function requested_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses what to display; nothing is written.
		$view = isset( $_GET[ self::ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) : '';

		return self::is_view( $view ) ? $view : '';
	}

	/**
	 * Refuse a sub-view the current user may not open with a real 403,
	 * before the admin header is sent. Runs on the Roles screen's `load-`
	 * hook; render() checks again, but by then the page is answering 200.
	 *
	 * @return void
	 */
	public static function check_access() {
		$view = self::requested_view();

		if ( '' !== $view && ! current_user_can( self::VIEWS[ $view ] ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * The URL of a sub-view.
	 *
	 * @param string $view Sub-view key.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $view, array $args = array() ) {
		return BLT_Toolkit_Admin::page_url( 'roles', array_merge( array( self::ARG => $view ), $args ) );
	}

	/**
	 * Header buttons the role editor may show: the sub-views the current
	 * user may open, for a role when one is given. Each has `label`, `url`
	 * and `view`.
	 *
	 * @param string $role Role open in the editor, or '' for the screen's own buttons (Add role, Delete roles).
	 * @return array[]
	 */
	public static function links( $role = '' ) {
		$role = (string) $role;
		$out  = array();

		if ( '' === $role ) {
			if ( current_user_can( self::VIEWS['add'] ) ) {
				$out[] = array(
					'view'  => 'add',
					'label' => __( 'Add role', 'blt-toolkit' ),
					'url'   => self::url( 'add' ),
				);
			}

			if ( current_user_can( self::VIEWS['delete'] ) ) {
				$out[] = array(
					'view'  => 'delete',
					'label' => __( 'Delete roles', 'blt-toolkit' ),
					'url'   => self::url( 'delete' ),
				);
			}

			return $out;
		}

		$labels = array(
			'rename' => __( 'Rename', 'blt-toolkit' ),
			'move'   => __( 'Move users to a new role', 'blt-toolkit' ),
			'merge'  => __( 'Merge into another role', 'blt-toolkit' ),
			'delete' => __( 'Delete', 'blt-toolkit' ),
		);

		foreach ( $labels as $view => $label ) {
			if ( ! current_user_can( self::VIEWS[ $view ] ) ) {
				continue;
			}

			if ( in_array( $view, array( 'merge', 'delete' ), true ) && '' !== BLT_Toolkit_Role_Lifecycle::deletable_code( $role ) ) {
				continue;
			}

			// SEC-12: nobody is moved out of `administrator`.
			if ( 'move' === $view && true !== BLT_Toolkit_Role_Lifecycle::move_refusal( $role ) ) {
				continue;
			}

			$args = 'delete' === $view
				? array(
					'step'  => 'confirm',
					'roles' => array( $role ),
				)
				: array( self::ROLE_ARG => $role );

			$out[] = array(
				'view'  => $view,
				'label' => $label,
				'url'   => self::url( $view, $args ),
			);
		}

		return $out;
	}

	// ---------- Rendering ----------

	/**
	 * Render a sub-view as the whole Roles screen: `.wrap`, header, the
	 * one-time notice, then the sub-view. Writes nothing (RO-5).
	 *
	 * @param string $view `add`, `rename`, `move`, `delete` or `merge`.
	 * @return bool False when `$view` is not a sub-view (the caller renders
	 *              the role editor instead); true once it rendered, or
	 *              refused with wp_die() when the user lacks its capability.
	 */
	public static function render( $view ) {
		if ( ! self::is_view( $view ) ) {
			return false;
		}

		if ( ! current_user_can( self::VIEWS[ $view ] ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to access this page.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array( 'response' => 403 )
			);
		}

		$module_on = BLT_Toolkit_Plugin::is_active( 'roles' ) && class_exists( 'BLT_Toolkit_Role_Lifecycle' );
		$back_url  = BLT_Toolkit_Admin::page_url( 'roles' );
		$sub       = BLT_Toolkit_Admin::screen_title( 'roles' );
		$actions   = array(
			array(
				'label' => __( 'Back to roles', 'blt-toolkit' ),
				'url'   => $back_url,
			),
		);

		echo '<div class="wrap blt-ui blt-toolkit blt-toolkit-roles blt-toolkit-role-lifecycle">';

		BLT_Toolkit_Admin::header( $sub, $actions );
		BLT_Toolkit_Admin::module_callout( 'roles' );
		self::print_notice();

		if ( $module_on ) {
			$data = call_user_func( array( __CLASS__, 'data_' . $view ) );

			$data['back_url'] = $back_url;

			self::view( $view, $data );
		}

		echo '</div>';

		return true;
	}

	/**
	 * Include a sub-view's template with its data as variables.
	 *
	 * @param string $view Sub-view key.
	 * @param array  $data Variables for the template.
	 * @return void
	 */
	private static function view( $view, array $data ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, documented at the top of each view.
		extract( $data, EXTR_SKIP );

		require BLT_TOOLKIT_DIR . 'admin/views/roles-lifecycle-' . $view . '.php';
	}

	/**
	 * Print the one-time notice of the last lifecycle POST, if the URL names
	 * it. The role editor calls this too. Reading it deletes it.
	 *
	 * @return void
	 */
	public static function print_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only; the token is compared with the person's own stored notice.
		$token = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['token'], $notice['type'], $notice['message'] ) || ! hash_equals( (string) $notice['token'], $token ) ) {
			return;
		}

		delete_transient( $key );

		$type  = in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'error';
		$items = isset( $notice['items'] ) && is_array( $notice['items'] ) ? $notice['items'] : array();
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> inline blt-toolkit-notice" role="<?php echo 'error' === $type ? 'alert' : 'status'; ?>">
			<p><?php echo esc_html( (string) $notice['message'] ); ?></p>
			<?php if ( array() !== $items ) : ?>
				<ul class="ul-disc">
					<?php foreach ( $items as $item ) : ?>
						<li><?php echo esc_html( (string) $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Data for the Add role view (RO-30), with the SEC-10 review step when
	 * the URL asks for it.
	 *
	 * @return array
	 */
	private static function data_add() {
		$values  = self::url_values( array( 'name', 'role_id', 'from' ) );
		$options = BLT_Toolkit_Role_Lifecycle::start_options_for_current_user();
		$all     = BLT_Toolkit_Role_Lifecycle::start_options( (array) wp_roles()->roles, array(), true, array() );
		$review  = null;

		if ( '' === $values['from'] ) {
			$values['from'] = BLT_Toolkit_Role_Lifecycle::BLANK;
		}

		if ( 'review' === self::url_value( 'step' ) ) {
			$review = self::review( $values['name'], $values['role_id'], $values['from'] );
		}

		return array(
			'values'   => $values,
			'options'  => self::option_groups( $options ),
			'filtered' => count( $all ) > count( $options ),
			'review'   => $review,
			'view_arg' => self::url_view(),
		);
	}

	/**
	 * Data for the Rename view (RO-31).
	 *
	 * @return array
	 */
	private static function data_rename() {
		$role = self::url_role();

		return array(
			'role'      => $role,
			'exists'    => '' !== $role && wp_roles()->is_role( $role ),
			'name'      => '' !== $role ? BLT_Toolkit_Role_Lifecycle::role_name( $role ) : '',
			'value'     => self::url_value( 'name' ),
			'refusal'   => '' !== $role && wp_roles()->is_role( $role ) ? BLT_Toolkit_Guard::check_role_change( wp_get_current_user(), $role, array(), array() ) : true,
			'protected' => BLT_Toolkit_Guard::is_protected_role( $role ),
		);
	}

	/**
	 * Data for the Move users to a new role view (RO-32): the form with a dry
	 * run, the SEC-10 review step, or the job's progress after it started.
	 *
	 * @return array
	 */
	private static function data_move() {
		$role   = self::url_role();
		$exists = '' !== $role && wp_roles()->is_role( $role );
		$values = self::url_values( array( 'name', 'role_id' ) );
		$job_id = self::url_job();
		$new    = sanitize_key( self::url_value( 'new' ) );
		$data   = array(
			'role'       => $role,
			'exists'     => $exists,
			'name'       => $exists ? BLT_Toolkit_Role_Lifecycle::role_name( $role ) : '',
			'values'     => $values,
			'job_id'     => $job_id,
			'new'        => '' !== $new && wp_roles()->is_role( $new ) ? $new : '',
			'new_name'   => '' !== $new ? BLT_Toolkit_Role_Lifecycle::role_name( $new ) : '',
			'counts'     => null,
			'review'     => null,
			'refusal'    => true,
			'can_delete' => false,
		);

		if ( ! $exists ) {
			return $data;
		}

		$options = BLT_Toolkit_Role_Lifecycle::start_options_for_current_user();
		$moving  = BLT_Toolkit_Role_Lifecycle::move_refusal( $role );

		if ( is_wp_error( $moving ) ) {
			$data['refusal'] = $moving;

			return $data;
		}

		if ( ! isset( $options[ BLT_Toolkit_Role_Lifecycle::COPY_PREFIX . $role ] ) ) {
			$data['refusal'] = new WP_Error( 'blt_toolkit_start_not_allowed', __( 'You cannot copy this role, because it has capabilities you do not have, so you cannot move its users to a new role.', 'blt-toolkit' ) );
		}

		$data['can_delete'] = current_user_can( self::VIEWS['delete'] ) && '' === BLT_Toolkit_Role_Lifecycle::deletable_code( $role );

		if ( '' !== $job_id || '' !== $data['new'] ) {
			return $data;
		}

		if ( '' === $values['name'] ) {
			$data['values']['name'] = sprintf(
				/* translators: %s: role name. */
				__( '%s (new)', 'blt-toolkit' ),
				$data['name']
			);
		}

		if ( 'review' === self::url_value( 'step' ) ) {
			$data['review'] = self::review( $values['name'], $values['role_id'], BLT_Toolkit_Role_Lifecycle::COPY_PREFIX . $role );
		}

		$data['counts'] = BLT_Toolkit_Role_Lifecycle::reassign_counts( $role, BLT_Toolkit_Role_Lifecycle::NEW_ROLE, BLT_Toolkit_Role_Lifecycle::MODE_MOVE );

		return $data;
	}

	/**
	 * Data for the Delete roles view (RO-40): the multi-select table, the
	 * confirmation step with its dry run and replacement, or the job's
	 * progress.
	 *
	 * @return array
	 */
	private static function data_delete() {
		$job_id   = self::url_job();
		$step     = 'confirm' === self::url_value( 'step' ) ? 'confirm' : 'select';
		$counts   = BLT_Toolkit_Role_Lifecycle::user_counts();
		$names    = BLT_Toolkit_Role_Lifecycle::role_names();
		$rows     = array();
		$blocked  = array();
		$chosen   = self::url_roles();
		$selected = array();

		foreach ( $names as $id => $name ) {
			$code = BLT_Toolkit_Role_Lifecycle::deletable_code( $id );
			$row  = array(
				'id'    => $id,
				'name'  => $name,
				'users' => isset( $counts[ $id ] ) ? $counts[ $id ] : 0,
			);

			if ( '' !== $code ) {
				$row['reason'] = BLT_Toolkit_Role_Lifecycle::undeletable_message( $code );
				$blocked[]     = $row;
				continue;
			}

			$refusal = BLT_Toolkit_Role_Lifecycle::delete_refusal( $id );

			if ( is_wp_error( $refusal ) ) {
				$row['reason'] = $refusal->get_error_message();
				$blocked[]     = $row;
				continue;
			}

			$rows[ $id ] = $row;
		}

		foreach ( $chosen as $id ) {
			if ( isset( $rows[ $id ] ) ) {
				$selected[ $id ] = $rows[ $id ];
			}
		}

		$data = array(
			'job_id'       => $job_id,
			'step'         => '' !== $job_id ? 'job' : $step,
			'rows'         => $rows,
			'blocked'      => $blocked,
			'selected'     => $selected,
			'unknown'      => array_values( array_diff( $chosen, array_keys( $selected ) ) ),
			'replacements' => array(),
			'replacement'  => '',
			'needs'        => false,
			'counts'       => array(),
		);

		if ( 'confirm' !== $data['step'] || array() === $selected ) {
			return $data;
		}

		foreach ( $selected as $row ) {
			if ( $row['users'] > 0 ) {
				$data['needs'] = true;
			}
		}

		$data['replacements'] = self::replacement_choices( array_keys( $selected ) );
		$data['replacement']  = self::default_replacement( $data['replacements'] );

		foreach ( $selected as $id => $row ) {
			$data['counts'][ $id ] = $row['users'] > 0 && '' !== $data['replacement']
				? BLT_Toolkit_Role_Lifecycle::reassign_counts( $id, $data['replacement'], BLT_Toolkit_Role_Lifecycle::MODE_REPLACE )
				: null;
		}

		return $data;
	}

	/**
	 * Data for the Merge view (RO-42).
	 *
	 * @return array
	 */
	private static function data_merge() {
		$role   = self::url_role();
		$exists = '' !== $role && wp_roles()->is_role( $role );
		$data   = array(
			'role'         => $role,
			'exists'       => $exists,
			'name'         => $exists ? BLT_Toolkit_Role_Lifecycle::role_name( $role ) : '',
			'job_id'       => self::url_job(),
			'refusal'      => true,
			'replacements' => array(),
			'replacement'  => '',
			'counts'       => null,
		);

		if ( ! $exists || '' !== $data['job_id'] ) {
			return $data;
		}

		$data['refusal'] = BLT_Toolkit_Role_Lifecycle::delete_refusal( $role );

		if ( is_wp_error( $data['refusal'] ) ) {
			return $data;
		}

		$data['replacements'] = self::replacement_choices( array( $role ) );
		$data['replacement']  = self::default_replacement( $data['replacements'] );

		if ( '' !== $data['replacement'] ) {
			$data['counts'] = BLT_Toolkit_Role_Lifecycle::reassign_counts( $role, $data['replacement'], BLT_Toolkit_Role_Lifecycle::MODE_MOVE );
		}

		return $data;
	}

	/**
	 * The SEC-10 review of a new role's Critical capabilities: what the
	 * actor must confirm by typing the display name. Null when the values
	 * do not check out or nothing Critical is involved (the form then
	 * shows the first step again).
	 *
	 * @param string $name    Display name.
	 * @param string $role_id Role ID.
	 * @param string $from    Start option key.
	 * @return array|null array( 'name', 'role_id', 'from', 'from_label', 'critical' => array of cap rows, 'total' ).
	 */
	private static function review( $name, $role_id, $from ) {
		$checked = BLT_Toolkit_Role_Lifecycle::check_new_role( $name, $role_id, $from );

		if ( is_wp_error( $checked ) ) {
			return null;
		}

		$critical = BLT_Toolkit_Guard::requires_critical_review( $checked['caps'] );

		if ( array() === $critical ) {
			return null;
		}

		$rows = array();

		foreach ( $critical as $cap ) {
			$rows[] = array(
				'cap'   => $cap,
				'label' => self::cap_label( $cap ),
			);
		}

		return array(
			'name'       => $checked['name'],
			'role_id'    => $checked['role'],
			'from'       => $checked['from'],
			'from_label' => BLT_Toolkit_Role_Lifecycle::start_label( $checked['from'] ),
			'critical'   => $rows,
			'total'      => count( array_filter( $checked['caps'] ) ),
		);
	}

	/**
	 * Start options grouped for the select: templates, then copies, each
	 * with its label, capability count and whether it holds Critical
	 * capabilities.
	 *
	 * @param array $options start_options() result.
	 * @return array{templates:array,copies:array}
	 */
	private static function option_groups( array $options ) {
		$out = array(
			'templates' => array(),
			'copies'    => array(),
		);

		foreach ( $options as $key => $option ) {
			$row = array(
				'key'      => (string) $key,
				'label'    => 'template' === $option['kind'] ? BLT_Toolkit_Role_Lifecycle::template_label( $key ) : BLT_Toolkit_Role_Lifecycle::role_name( $option['role'] ),
				'count'    => count( array_filter( $option['caps'] ) ),
				'critical' => array() !== BLT_Toolkit_Guard::requires_critical_review( $option['caps'] ),
			);

			$out[ 'template' === $option['kind'] ? 'templates' : 'copies' ][] = $row;
		}

		return $out;
	}

	/**
	 * Roles the people of the given roles may be moved to: the current
	 * user's assignable roles (`get_editable_roles()`, filtered by SEC-4),
	 * without the given ones.
	 *
	 * @param string[] $exclude Roles going away.
	 * @return array<string,string> Role ID => name.
	 */
	private static function replacement_choices( array $exclude ) {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$out = array();

		foreach ( (array) get_editable_roles() as $id => $role ) {
			$id = (string) $id;

			if ( ! in_array( $id, $exclude, true ) ) {
				$out[ $id ] = translate_user_role( isset( $role['name'] ) ? (string) $role['name'] : $id );
			}
		}

		return $out;
	}

	/**
	 * The replacement preselected: the one in the URL, else the site's
	 * default role, else the first choice.
	 *
	 * @param array<string,string> $choices replacement_choices().
	 * @return string
	 */
	private static function default_replacement( array $choices ) {
		$asked = self::url_value( 'replacement' );

		if ( '' !== $asked && isset( $choices[ $asked ] ) ) {
			return $asked;
		}

		$default = (string) get_option( 'default_role' );

		if ( isset( $choices[ $default ] ) ) {
			return $default;
		}

		$keys = array_keys( $choices );

		return array() === $keys ? '' : (string) $keys[0];
	}

	/**
	 * A dry run's counts as sentences (RO-41: "Dry run shows counts").
	 *
	 * @param array|null $counts      BLT_Toolkit_Role_Lifecycle::reassign_counts(), or null when not counted.
	 * @param string     $role_name   Name of the role being emptied.
	 * @param string     $target_name Name of the role people get ('' for a role not created yet).
	 * @param string     $mode        MODE_REPLACE or MODE_MOVE.
	 * @return string[]
	 */
	public static function count_lines( $counts, $role_name, $target_name, $mode ) {
		if ( ! is_array( $counts ) ) {
			return array();
		}

		$move   = BLT_Toolkit_Role_Lifecycle::MODE_MOVE === $mode;
		$target = '' !== $target_name ? $target_name : __( 'the new role', 'blt-toolkit' );
		$out    = array();

		if ( 0 === (int) $counts['users'] ) {
			return array( __( 'Nobody holds this role.', 'blt-toolkit' ) );
		}

		$out[] = sprintf(
			/* translators: 1: number of people, 2: role name. */
			_n( '%1$s person holds %2$s.', '%1$s people hold %2$s.', (int) $counts['users'], 'blt-toolkit' ),
			number_format_i18n( (int) $counts['users'] ),
			$role_name
		);

		if ( $counts['get'] > 0 ) {
			$out[] = $move
				/* translators: 1: number of people, 2: role name they get, 3: role name they lose. */
				? sprintf( _n( '%1$s will get %2$s in place of %3$s, keeping any other roles.', '%1$s will get %2$s in place of %3$s, keeping any other roles.', (int) $counts['get'], 'blt-toolkit' ), number_format_i18n( (int) $counts['get'] ), $target, $role_name )
				/* translators: 1: number of people, 2: role name they get. */
				: sprintf( _n( '%1$s has no other role and will get %2$s.', '%1$s have no other role and will get %2$s.', (int) $counts['get'], 'blt-toolkit' ), number_format_i18n( (int) $counts['get'] ), $target );
		}

		if ( $counts['remove'] > 0 ) {
			$out[] = $move
				/* translators: 1: number of people, 2: role name they hold already, 3: role name they lose. */
				? sprintf( _n( '%1$s already holds %2$s and will just lose %3$s.', '%1$s already hold %2$s and will just lose %3$s.', (int) $counts['remove'], 'blt-toolkit' ), number_format_i18n( (int) $counts['remove'] ), $target, $role_name )
				/* translators: 1: number of people, 2: role name they lose. */
				: sprintf( _n( '%1$s has other roles and will just lose %2$s.', '%1$s have other roles and will just lose %2$s.', (int) $counts['remove'], 'blt-toolkit' ), number_format_i18n( (int) $counts['remove'] ), $role_name );
		}

		if ( $counts['blocked'] > 0 ) {
			$out[] = sprintf(
				/* translators: 1: number of people, 2: role name. */
				_n( '%1$s cannot be changed by you (an administrator, someone with more access than you, or you yourself), so they keep %2$s and the role stays.', '%1$s cannot be changed by you (administrators, people with more access than you, or you yourself), so they keep %2$s and the role stays.', (int) $counts['blocked'], 'blt-toolkit' ),
				number_format_i18n( (int) $counts['blocked'] ),
				$role_name
			);
		}

		return $out;
	}

	/**
	 * A capability's label from the catalog (RO-12) when it is loaded, else
	 * its name in words.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function cap_label( $cap ) {
		if ( class_exists( 'BLT_Toolkit_Capability_Catalog' ) && is_callable( array( 'BLT_Toolkit_Capability_Catalog', 'label' ) ) ) {
			$label = BLT_Toolkit_Capability_Catalog::label( $cap );

			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		return ucfirst( str_replace( array( '_', '-' ), ' ', (string) $cap ) );
	}

	// ---------- POST handlers ----------

	/**
	 * Handle a lifecycle POST after BLT_Toolkit_Admin::route_post() checked
	 * the method, the capability and the nonce. Ends with a redirect.
	 *
	 * @param string $action admin-post.php action; '' reads it from the current hook.
	 * @return void
	 */
	public static function handle( $action = '' ) {
		$action = '' !== (string) $action ? (string) $action : substr( current_action(), strlen( 'admin_post_' ) );

		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			BLT_Toolkit_Admin::redirect( 'roles', array(), 'failed' );
		}

		// The router checked this already; a handler never depends on it.
		if ( ! current_user_can( self::ACTIONS[ $action ][1] ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		if ( ! BLT_Toolkit_Plugin::is_active( 'roles' ) || ! class_exists( 'BLT_Toolkit_Role_Lifecycle' ) ) {
			self::back( '', array(), 'error', __( 'Nothing was changed: the Roles module is not running.', 'blt-toolkit' ) );
		}

		call_user_func( array( __CLASS__, self::ACTIONS[ $action ][0] ) );

		BLT_Toolkit_Admin::redirect( 'roles', array(), 'failed' );
	}

	/**
	 * RO-30: add a role, through the SEC-10 review when it has Critical
	 * capabilities.
	 *
	 * @return void
	 */
	public static function handle_add() {
		$name    = self::posted( 'name' );
		$role_id = self::posted( 'role_id' );
		$from    = self::posted( 'from' );
		$view    = self::posted_view();
		$values  = array(
			'name'    => $name,
			'role_id' => $role_id,
			'from'    => $from,
		);
		$typed   = self::posted_confirmation();

		if ( null !== $typed && ! BLT_Toolkit_Admin_Settings::confirmation_matches( $typed, $name ) ) {
			self::back( 'add', array_merge( $values, array( 'step' => 'review' ), self::view_args( $view ) ), 'error', __( 'Nothing was changed: type the role name exactly as shown to confirm.', 'blt-toolkit' ) );
		}

		$result = BLT_Toolkit_Role_Lifecycle::add( $name, $role_id, $from, array( 'confirmed_critical' => null !== $typed ) );

		if ( is_wp_error( $result ) ) {
			if ( 'blt_toolkit_critical_review' === $result->get_error_code() ) {
				$data = (array) $result->get_error_data();

				$values['role_id'] = isset( $data['role'] ) ? (string) $data['role'] : $role_id;

				self::redirect_to( self::url( 'add', array_merge( $values, array( 'step' => 'review' ), self::view_args( $view ) ) ) );
			}

			self::back( 'add', array_merge( $values, self::view_args( $view ), self::suggestion( $result ) ), 'error', self::error_text( $result ) );
		}

		self::back(
			'',
			array_merge( array( self::ROLE_ARG => $result['role'] ), self::view_args( $view ) ),
			'success',
			sprintf(
				/* translators: 1: role name, 2: role ID, 3: what it started from. */
				__( 'Added the %1$s role (ID %2$s), starting from: %3$s.', 'blt-toolkit' ),
				$result['name'],
				$result['role'],
				BLT_Toolkit_Role_Lifecycle::start_label( $result['from'] )
			)
		);
	}

	/**
	 * RO-31: rename a role.
	 *
	 * @return void
	 */
	public static function handle_rename() {
		$role   = self::posted_role( 'role' );
		$name   = self::posted( 'name' );
		$result = BLT_Toolkit_Role_Lifecycle::rename( $role, $name );

		if ( is_wp_error( $result ) ) {
			self::back(
				'rename',
				array(
					self::ROLE_ARG => $role,
					'name'         => $name,
				),
				'error',
				self::error_text( $result )
			);
		}

		self::back(
			'',
			array( self::ROLE_ARG => $role ),
			'success',
			sprintf(
				/* translators: 1: old name, 2: new name. */
				__( 'Renamed %1$s to %2$s. Its ID and its users are unchanged.', 'blt-toolkit' ),
				$result['old'],
				$result['name']
			)
		);
	}

	/**
	 * RO-32: create the new role as a copy and start moving the users.
	 *
	 * @return void
	 */
	public static function handle_move() {
		$role    = self::posted_role( 'role' );
		$name    = self::posted( 'name' );
		$role_id = self::posted( 'role_id' );
		$typed   = self::posted_confirmation();
		$values  = array(
			self::ROLE_ARG => $role,
			'name'         => $name,
			'role_id'      => $role_id,
		);

		if ( null !== $typed && ! BLT_Toolkit_Admin_Settings::confirmation_matches( $typed, $name ) ) {
			self::back( 'move', array_merge( $values, array( 'step' => 'review' ) ), 'error', __( 'Nothing was changed: type the role name exactly as shown to confirm.', 'blt-toolkit' ) );
		}

		$result = BLT_Toolkit_Role_Lifecycle::move_users( $role, $name, $role_id, array( 'confirmed_critical' => null !== $typed ) );

		if ( is_wp_error( $result ) ) {
			if ( 'blt_toolkit_critical_review' === $result->get_error_code() ) {
				$data = (array) $result->get_error_data();

				$values['role_id'] = isset( $data['role'] ) ? (string) $data['role'] : $role_id;

				self::redirect_to( self::url( 'move', array_merge( $values, array( 'step' => 'review' ) ) ) );
			}

			self::back( 'move', array_merge( $values, self::suggestion( $result ) ), 'error', self::error_text( $result ) );
		}

		$args = array(
			self::ROLE_ARG => $role,
			'new'          => $result['role'],
		);

		if ( isset( $result['job_error'] ) && is_wp_error( $result['job_error'] ) ) {
			self::back(
				'move',
				$args,
				'warning',
				sprintf(
					/* translators: 1: new role name, 2: why the users could not be moved. */
					__( 'The %1$s role was created, but its users could not be moved yet: %2$s', 'blt-toolkit' ),
					$result['name'],
					$result['job_error']->get_error_message()
				)
			);
		}

		if ( '' !== $result['job_id'] ) {
			$args['job'] = $result['job_id'];

			self::back(
				'move',
				$args,
				'success',
				sprintf(
					/* translators: 1: new role name, 2: new role ID, 3: number of people, 4: old role name. */
					_n( 'The %1$s role (ID %2$s) was created. %3$s person is being moved to it from %4$s.', 'The %1$s role (ID %2$s) was created. %3$s people are being moved to it from %4$s.', (int) $result['users'], 'blt-toolkit' ),
					$result['name'],
					$result['role'],
					number_format_i18n( (int) $result['users'] ),
					BLT_Toolkit_Role_Lifecycle::role_name( $role )
				)
			);
		}

		self::back(
			'move',
			$args,
			'success',
			sprintf(
				/* translators: 1: new role name, 2: new role ID, 3: old role name. */
				__( 'The %1$s role (ID %2$s) was created. Nobody held %3$s, so nobody needed to move.', 'blt-toolkit' ),
				$result['name'],
				$result['role'],
				BLT_Toolkit_Role_Lifecycle::role_name( $role )
			)
		);
	}

	/**
	 * RO-40: delete the chosen roles, moving their users first.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		$roles       = self::posted_roles();
		$replacement = self::posted_role( 'replacement' );
		$names       = array();

		foreach ( $roles as $role ) {
			$names[ $role ] = BLT_Toolkit_Role_Lifecycle::role_name( $role );
		}

		$result = BLT_Toolkit_Role_Lifecycle::delete( $roles, $replacement );

		if ( is_wp_error( $result ) ) {
			self::back(
				'delete',
				array(
					'step'        => 'confirm',
					'roles'       => $roles,
					'replacement' => $replacement,
				),
				'error',
				self::error_text( $result )
			);
		}

		self::after_delete( $result, $names, $replacement, 'delete', array() );
	}

	/**
	 * RO-42: merge a role into another.
	 *
	 * @return void
	 */
	public static function handle_merge() {
		$role   = self::posted_role( 'role' );
		$into   = self::posted_role( 'replacement' );
		$names  = array( $role => BLT_Toolkit_Role_Lifecycle::role_name( $role ) );
		$result = BLT_Toolkit_Role_Lifecycle::merge( $role, $into );

		if ( is_wp_error( $result ) ) {
			self::back(
				'merge',
				array(
					self::ROLE_ARG => $role,
					'replacement'  => $into,
				),
				'error',
				self::error_text( $result )
			);
		}

		self::after_delete( $result, $names, $into, 'merge', array( self::ROLE_ARG => $role ) );
	}

	/**
	 * Redirect after a delete or a merge: to the job's progress when people
	 * are being moved, else to the role list.
	 *
	 * @param array                $result      BLT_Toolkit_Role_Lifecycle::delete() result.
	 * @param array<string,string> $names       Role ID => name, read before the delete.
	 * @param string               $replacement Replacement role.
	 * @param string               $view        `delete` or `merge`.
	 * @param array                $args        Extra query arguments for the job view.
	 * @return void
	 */
	private static function after_delete( array $result, array $names, $replacement, $view, array $args ) {
		$deleted = array();
		$pending = array();

		foreach ( $result['deleted'] as $role ) {
			$deleted[] = isset( $names[ $role ] ) ? $names[ $role ] : $role;
		}

		foreach ( $result['pending'] as $role ) {
			$pending[] = isset( $names[ $role ] ) ? $names[ $role ] : $role;
		}

		$separator = wp_get_list_item_separator();
		$target    = BLT_Toolkit_Role_Lifecycle::role_name( $replacement );
		$parts     = array();

		if ( array() !== $deleted ) {
			$parts[] = 'merge' === $view
				/* translators: 1: role name, 2: role it was merged into. */
				? sprintf( __( 'Merged %1$s into %2$s and deleted it: nobody held it.', 'blt-toolkit' ), implode( $separator, $deleted ), $target )
				/* translators: %s: comma-separated role names. */
				: sprintf( _n( 'Deleted the role %s.', 'Deleted the roles %s.', count( $deleted ), 'blt-toolkit' ), implode( $separator, $deleted ) );
		}

		if ( isset( $result['job_error'] ) && is_wp_error( $result['job_error'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: why. */
				__( 'The roles that people hold were kept, because their people could not be moved: %s', 'blt-toolkit' ),
				$result['job_error']->get_error_message()
			);

			self::back( '', array(), 'warning', implode( ' ', $parts ) );
		}

		if ( '' !== $result['job_id'] ) {
			$parts[] = 'merge' === $view
				/* translators: 1: role name, 2: role its people get. */
				? sprintf( __( 'Everyone who holds %1$s is being given %2$s instead; %1$s is deleted as soon as nobody holds it.', 'blt-toolkit' ), implode( $separator, $pending ), $target )
				/* translators: 1: comma-separated role names, 2: replacement role name. */
				: sprintf( __( 'People are being moved out of %1$s (those with no other role get %2$s); each role is deleted as soon as nobody holds it.', 'blt-toolkit' ), implode( $separator, $pending ), $target );

			self::back( $view, array_merge( $args, array( 'job' => $result['job_id'] ) ), 'success', implode( ' ', $parts ) );
		}

		if ( array() === $parts ) {
			$parts[] = __( 'Nothing needed to change.', 'blt-toolkit' );
		}

		self::back( '', array(), 'success', implode( ' ', $parts ) );
	}

	// ---------- Request helpers ----------

	/**
	 * A posted text field, unslashed and sanitized ('' when missing).
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		$value = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

		return trim( $value );
	}

	/**
	 * A posted role ID: kept only when it is a role ID's characters
	 * (`[A-Za-z0-9_-]`, case kept, as Settings stores them).
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted_role( $key ) {
		return self::clean_role( self::posted( $key ) );
	}

	/**
	 * The posted list of roles (`roles[]`).
	 *
	 * @return string[]
	 */
	private static function posted_roles() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified in BLT_Toolkit_Admin::route_post(); each entry is cleaned by clean_roles().
		$raw = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : array();

		return self::clean_roles( $raw );
	}

	/**
	 * The typed SEC-10 confirmation, or null when the form did not include
	 * the review step.
	 *
	 * @return string|null
	 */
	private static function posted_confirmation() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		if ( ! isset( $_POST[ self::CONFIRM_FIELD ] ) || ! is_string( $_POST[ self::CONFIRM_FIELD ] ) ) {
			return null;
		}

		return self::posted( self::CONFIRM_FIELD );
	}

	/**
	 * The posted role editor view, `simple`, `advanced` or ''.
	 *
	 * @return string
	 */
	private static function posted_view() {
		$view = sanitize_key( self::posted( self::VIEW_ARG ) );

		return in_array( $view, array( 'simple', 'advanced' ), true ) ? $view : '';
	}

	/**
	 * A URL query value, unslashed and sanitized ('' when missing).
	 *
	 * @param string $key Query argument.
	 * @return string
	 */
	private static function url_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: prefills a form or picks a step; nothing is written.
		$value = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

		return trim( $value );
	}

	/**
	 * Several URL values.
	 *
	 * @param string[] $keys Query arguments.
	 * @return array<string,string>
	 */
	private static function url_values( array $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			$out[ $key ] = self::url_value( $key );
		}

		return $out;
	}

	/**
	 * The role the URL names.
	 *
	 * @return string
	 */
	private static function url_role() {
		return self::clean_role( self::url_value( self::ROLE_ARG ) );
	}

	/**
	 * The roles the URL names (`roles[]`).
	 *
	 * @return string[]
	 */
	private static function url_roles() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Display only; each entry is cleaned by clean_roles().
		$raw = isset( $_GET['roles'] ) && is_array( $_GET['roles'] ) ? wp_unslash( $_GET['roles'] ) : array();

		return self::clean_roles( $raw );
	}

	/**
	 * The role editor view the URL names, for passing through.
	 *
	 * @return string
	 */
	private static function url_view() {
		$view = sanitize_key( self::url_value( self::VIEW_ARG ) );

		return in_array( $view, array( 'simple', 'advanced' ), true ) ? $view : '';
	}

	/**
	 * The job the URL names, when it is a reassign job the current user may
	 * follow, else ''.
	 *
	 * @return string
	 */
	private static function url_job() {
		$asked = BLT_Toolkit_Jobs::clean_id( self::url_value( 'job' ) );

		if ( '' === $asked ) {
			return '';
		}

		$job = BLT_Toolkit_Jobs::get( $asked );

		return null !== $job && BLT_Toolkit_Role_Lifecycle::JOB_TYPE === $job['type'] && BLT_Toolkit_Jobs::current_user_can_use( $job ) ? $job['id'] : '';
	}

	/**
	 * The reassign job still running, when the current user may follow it,
	 * else '' (JOB-4: coming back to the screen finds it). Another lifecycle
	 * change that moves people cannot start until it ends.
	 *
	 * @return string
	 */
	public static function live_job_id() {
		$live = BLT_Toolkit_Role_Lifecycle::live_job();

		return null !== $live && BLT_Toolkit_Jobs::current_user_can_use( $live ) ? $live['id'] : '';
	}

	/**
	 * Print the running reassign job's panel above a form, with a note that
	 * a new move waits for it. Prints nothing when none runs, or when it is
	 * the job the page already shows.
	 *
	 * @param string $shown Job the page shows anyway, or ''.
	 * @return void
	 */
	public static function print_live_job( $shown = '' ) {
		$live = self::live_job_id();

		if ( '' === $live || $live === $shown ) {
			return;
		}

		BLT_Toolkit_Jobs::render_ui( $live );
		?>
		<div class="blt-callout">
			<strong><?php esc_html_e( 'People are still being moved between roles', 'blt-toolkit' ); ?></strong>
			<span><?php esc_html_e( 'A change that moves people can start once this one has finished.', 'blt-toolkit' ); ?></span>
		</div>
		<?php
	}

	/**
	 * A role ID from a URL or a form: one that exists exactly as stored
	 * (WordPress accepts any string as a role ID), otherwise only
	 * `[A-Za-z0-9_-]`, at most 191 (BLT_Toolkit_Admin_Roles::clean_role()).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean_role( $value ) {
		return BLT_Toolkit_Admin_Roles::clean_role( $value );
	}

	/**
	 * Role IDs from a form or URL list, cleaned and unique.
	 *
	 * @param array $raw Values.
	 * @return string[]
	 */
	private static function clean_roles( array $raw ) {
		$out = array();

		foreach ( $raw as $value ) {
			if ( is_string( $value ) ) {
				$role = self::clean_role( $value );

				if ( '' !== $role ) {
					$out[ $role ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * The `view` argument to pass on, if any.
	 *
	 * @param string $view `simple`, `advanced` or ''.
	 * @return array
	 */
	private static function view_args( $view ) {
		return '' !== $view ? array( self::VIEW_ARG => $view ) : array();
	}

	/**
	 * The free role ID an ID error suggests, to prefill the form.
	 *
	 * @param WP_Error $error Error.
	 * @return array
	 */
	private static function suggestion( WP_Error $error ) {
		$data = (array) $error->get_error_data();

		return isset( $data['field'], $data['suggest'] ) && 'role_id' === $data['field'] && '' !== (string) $data['suggest']
			? array( 'role_id' => (string) $data['suggest'] )
			: array();
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
	 * Store the one-time notice and redirect to a sub-view (or, with '', to
	 * the role editor). Stops.
	 *
	 * @param string $view    Sub-view key, or '' for the role editor.
	 * @param array  $args    Query arguments.
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 * @param array  $items   Optional list under the message.
	 * @return void
	 */
	private static function back( $view, array $args, $type, $message, array $items = array() ) {
		$token = strtolower( wp_generate_password( 12, false ) );

		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'token'   => $token,
				'type'    => $type,
				'message' => (string) $message,
				'items'   => array_values( array_map( 'strval', $items ) ),
			),
			10 * MINUTE_IN_SECONDS
		);

		$args[ self::NOTICE_ARG ] = $token;

		self::redirect_to( '' === $view ? BLT_Toolkit_Admin::page_url( 'roles', $args ) : self::url( $view, $args ) );
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Where to.
	 * @return void
	 */
	private static function redirect_to( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
