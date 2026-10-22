<?php
/**
 * Roles of users: additional roles, additional default roles, bulk add and
 * remove, users without a role (US-1, US-2, US-3, US-12).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multiple roles per user, through WordPress's own `{prefix}capabilities`
 * user meta (SPEC 14.4; no parallel storage).
 *
 * - US-1, additional roles: a checkbox list on another user's profile
 *   (`edit_user_profile`) and on Add New User (`user_new_form`), below core's
 *   Role dropdown, which stays the main role. Without JavaScript it is its
 *   own section at the end of the form; with JavaScript the row moves under
 *   the Role row. It lists the actor's filtered `editable_roles` (SEC-4);
 *   roles the user holds that the actor may not give are shown disabled and
 *   kept. Checked on `user_profile_update_errors` (BLT nonce, `promote_user`
 *   or `promote_users`, BLT_Toolkit_Guard::can_act_on_user() for SEC-1 and
 *   SEC-2, never one's own roles for SEC-5, every checked role editable for
 *   SEC-4, and BLT_Toolkit_Guard::can_change_user_roles() on the roles the
 *   user will hold for SEC-3); a refusal is added to core's errors, so core
 *   saves nothing.
 *   Applied on `profile_update` and `user_register`, after core has set the
 *   main role, and logged.
 * - US-2, additional default roles: Settings > Roles chooses roles every new
 *   user receives on top of core's default role (`roles.extra_default_roles`,
 *   never `administrator`). Applied on `user_register` for every
 *   registration path (wp-login.php, user-new.php, REST, WP-CLI, SSO
 *   plugins, shop checkouts), and again if a plugin replaces the new user's
 *   role with set_role() later in the same request. A creator who is signed
 *   in and not a toolkit admin passes on only roles they may give
 *   (BLT_Toolkit_Guard::may_assign_role()); anonymous self-registration is
 *   not limited. Add New User shows them ticked in the US-1 list, so the
 *   person creating the user can untick one.
 * - US-3, bulk add or remove a role: "Add role…" and "Remove role…" with an
 *   Apply button next to core's "Change role to…" (`restrict_manage_users`).
 *   The button posts the Users form to admin-post.php (`formmethod`,
 *   `formaction`, so no form is nested and nothing needs JavaScript), with a
 *   nonce, `promote_users` and both roles in `editable_roles` (SEC-4); each
 *   user is checked on its own (`promote_user`, can_act_on_user(), not
 *   oneself). One aggregate log row; the result is shown on users.php.
 * - US-12, users without a role (capabilities meta missing, empty or naming
 *   only roles that no longer exist): never
 *   counted on a page load. The count runs when someone asks for it (the
 *   "Count" button in the Users screen's views and on the Overview), as one
 *   query on ordinary sites and as the `count_no_role` cursor job on large
 *   ones (`wp_is_large_user_count()`), and is kept for an hour in the
 *   transient `blt_toolkit_no_role_count` (dropped as soon as anyone's roles
 *   change). The view "No role (N)" filters the Users list to them, so core's
 *   "Change role to…" can then assign them.
 *
 * Cost per request (SEC-9): boot() only adds hooks. On a page view nothing
 * runs; a registration or role change pays the US-2 roles and, once per
 * request, dropping the US-12 transient. On users.php the transient is read
 * once (for the view), and the list query gains a clause only in the No role
 * view.
 *
 * desired_roles(), extra_roles_to_add(), merge_default_roles(),
 * bulk_change() and no_role_state() are pure and unit-tested in
 * tests/test-user-roles.php.
 */
class BLT_Toolkit_User_Roles {

	// ---------- US-1 ----------

	/**
	 * The checked additional roles.
	 */
	const FIELD = 'blt_toolkit_additional_roles';

	/**
	 * The roles that were drawn as changeable checkboxes; only these can be
	 * removed (the RO-1 rule for role lists).
	 */
	const SHOWN_FIELD = 'blt_toolkit_additional_roles_shown';

	/**
	 * Nonce field, and the prefix of its action (the user ID, or `new`).
	 */
	const NONCE_FIELD  = 'blt_toolkit_additional_roles_nonce';
	const NONCE_ACTION = 'blt_toolkit_additional_roles_';

	/**
	 * Id of the section and of its row.
	 */
	const ANCHOR = 'blt-tk-additional-roles';

	/**
	 * Script handle (moves the row under core's Role row).
	 */
	const SCRIPT = 'blt-toolkit-user-roles';

	/**
	 * Log action for every change of a user's roles (BLT_Toolkit_Admin_Activity
	 * lists it).
	 */
	const LOG_ACTION = 'user_roles_changed';

	// ---------- US-2 ----------

	/**
	 * Settings > Roles fields, outside the `blt_toolkit[...]` array so they
	 * never reach save_tab(): the roles switched on, and the roles drawn as
	 * changeable (a role that was not drawn is never touched).
	 */
	const DEFAULTS_FIELD = 'blt_toolkit_extra_default_roles';
	const DEFAULTS_SHOWN = 'blt_toolkit_extra_default_roles_shown';

	/**
	 * The role that is never an additional default role: giving it to every
	 * person who signs up would hand the site to strangers.
	 */
	const ADMIN_ROLE = 'administrator';

	// ---------- US-3 ----------

	/**
	 * admin-post.php action, its nonce argument (in the button's
	 * `formaction`), and the two selects.
	 */
	const BULK_ACTION = 'blt_toolkit_bulk_roles';
	const BULK_NONCE  = 'blt_toolkit_bulk_roles_nonce';
	const BULK_ADD    = 'blt_toolkit_add_role';
	const BULK_REMOVE = 'blt_toolkit_remove_role';

	/**
	 * Most users one Apply changes (core's Users list shows at most 999 a
	 * page).
	 */
	const BULK_MAX = 1000;

	/**
	 * Query argument added to the redirect, and the prefix of the transient
	 * that carries the result to the next screen (the actor's ID follows).
	 */
	const BULK_RESULT_ARG       = 'blt_tk_bulk_roles';
	const BULK_NOTICE_TRANSIENT = 'blt_toolkit_bulk_roles_notice_';

	// ---------- US-12 ----------

	/**
	 * The one-hour cache (SPEC 14.2).
	 */
	const COUNT_TRANSIENT = 'blt_toolkit_no_role_count';

	/**
	 * admin-post.php action of the Count button, and the field naming the
	 * screen to return to.
	 */
	const COUNT_ACTION = 'blt_toolkit_count_no_role';
	const BACK_FIELD   = 'blt_tk_back';

	/**
	 * Query argument the Count redirect adds (a whitelisted code).
	 */
	const COUNT_RESULT_ARG = 'blt_tk_no_role_count';

	/**
	 * Query argument of the "No role" view on users.php, and the
	 * WP_User_Query variable it sets.
	 */
	const VIEW_ARG  = 'blt_tk_no_role';
	const QUERY_VAR = 'blt_toolkit_no_role';

	/**
	 * Job type (JOB-5) and the user IDs one step covers.
	 */
	const JOB_TYPE  = 'count_no_role';
	const JOB_BATCH = 10000;

	// ---------- State ----------

	/**
	 * Additional roles approved on `user_profile_update_errors`, waiting for
	 * `profile_update` or `user_register`: user ID (or `new`) => plan.
	 *
	 * @var array<string,array>
	 */
	private static $plans = array();

	/**
	 * Users registered in this request => the additional roles they were
	 * given (US-2), for set_user_role() calls that follow.
	 *
	 * @var array<int,string[]>
	 */
	private static $registered = array();

	/**
	 * Re-entrancy guard for re-applying additional roles.
	 *
	 * @var bool
	 */
	private static $applying = false;

	/**
	 * Whether the US-12 transient was dropped in this request.
	 *
	 * @var bool
	 */
	private static $forgot = false;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the hooks. Called by the Roles module's boot().
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// US-12: the No role view reads its cached count on every Users
		// screen; read it with BLT Toolkit's other admin options, in one query
		// (BLT_Toolkit_Settings::prime_admin_options(), SEC-9).
		if ( is_admin() && isset( $GLOBALS['pagenow'] ) && 'users.php' === $GLOBALS['pagenow'] ) {
			BLT_Toolkit_Settings::prime_also( array( '_transient_' . self::COUNT_TRANSIENT, '_transient_timeout_' . self::COUNT_TRANSIENT ) );
		}

		// US-1 (Add New User) and US-2 on every request: users are created by
		// wp-login.php, REST, WP-CLI, SSO plugins and front-end forms.
		add_action( 'user_register', array( __CLASS__, 'on_register' ), 20, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'after_set_role' ), 20, 3 );

		// US-12: a cached count is wrong once anyone's roles change.
		add_action( 'add_user_role', array( __CLASS__, 'forget_count' ), 10, 0 );
		add_action( 'remove_user_role', array( __CLASS__, 'forget_count' ), 10, 0 );
		add_action( 'deleted_user', array( __CLASS__, 'forget_count' ), 10, 0 );
		add_action( 'blt_toolkit_after_roles_save', array( __CLASS__, 'after_roles_save' ), 10, 1 );

		// US-12, JOB-5: the label is translated, so on `init`; every request
		// that runs a tick comes later.
		add_action( 'init', array( __CLASS__, 'register_job_type' ), 5 );

		if ( ! is_admin() ) {
			return;
		}

		// US-1.
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile' ), 5 );
		add_action( 'user_new_form', array( __CLASS__, 'render_new_user' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate' ), 20, 3 );
		add_action( 'profile_update', array( __CLASS__, 'apply_profile' ), 20, 2 );

		// US-3.
		add_action( 'restrict_manage_users', array( __CLASS__, 'render_bulk_controls' ) );
		add_action( 'admin_post_' . self::BULK_ACTION, array( __CLASS__, 'handle_bulk' ) );

		// US-12.
		add_action( 'admin_post_' . self::COUNT_ACTION, array( __CLASS__, 'handle_count' ) );
		add_action( 'load-users.php', array( __CLASS__, 'load_users_screen' ) );

		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	// ---------- US-1: the profile and Add New User ----------

	/**
	 * The "Additional roles" section on another user's profile (US-1).
	 *
	 * Shown when the actor may change this user's role (`promote_user`, which
	 * the Guard extends with SEC-1 and SEC-2), never on one's own profile
	 * (SEC-5; core shows no Role field there either). The main role is core's
	 * dropdown and is not repeated here.
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public static function render_profile( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() || get_current_user_id() === (int) $user->ID ) {
			return;
		}

		if ( ! current_user_can( 'promote_user', (int) $user->ID ) ) {
			return;
		}

		$editable = self::editable_ids();
		$held     = array_values( array_map( 'strval', (array) $user->roles ) );
		$primary  = self::displayed_primary( $held, $editable );
		$rows     = array();

		foreach ( self::role_names() as $role => $name ) {
			$is_held = in_array( $role, $held, true );

			if ( $role === $primary ) {
				continue;
			}

			if ( in_array( $role, $editable, true ) ) {
				$rows[] = array(
					'id'      => $role,
					'name'    => $name,
					'checked' => $is_held,
					'enabled' => true,
				);
			} elseif ( $is_held ) {
				$rows[] = array(
					'id'      => $role,
					'name'    => $name,
					'checked' => true,
					'enabled' => false,
				);
			}
		}

		self::render_section( $rows, self::NONCE_ACTION . (int) $user->ID );
	}

	/**
	 * The "Additional roles" list on Add New User (US-1), with the additional
	 * default roles (US-2) ticked.
	 *
	 * Shown to people who see core's Role field there (`promote_users`). Only
	 * single-site `add-new-user` (D1).
	 *
	 * @param string $context `add-new-user` or `add-existing-user`.
	 * @return void
	 */
	public static function render_new_user( $context ) {
		if ( 'add-new-user' !== $context || ! current_user_can( 'promote_users' ) ) {
			return;
		}

		$editable = self::editable_ids();
		$extra    = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$checked  = self::extra_roles_to_add( is_array( $extra ) ? $extra : array(), array(), $editable, $editable );

		// Shown again after core refused the form (a username taken, say):
		// keep what was ticked. Display only; the save checks everything.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::SHOWN_FIELD ] ) ) {
			$checked = array_values( array_intersect( self::posted_roles( self::FIELD ), $editable ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$rows = array();

		foreach ( self::role_names() as $role => $name ) {
			if ( in_array( $role, $editable, true ) ) {
				$rows[] = array(
					'id'      => $role,
					'name'    => $name,
					'checked' => in_array( $role, $checked, true ),
					'enabled' => true,
				);
			}
		}

		self::render_section( $rows, self::NONCE_ACTION . 'new' );
	}

	/**
	 * Print the section: a fieldset of checkboxes inside a form-table row
	 * (core's own markup, since it sits inside core's form), the shown list
	 * and the nonce. No form of its own.
	 *
	 * @param array  $rows         Each: id, name, checked, enabled.
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private static function render_section( array $rows, $nonce_action ) {
		if ( array() === $rows ) {
			return;
		}

		wp_enqueue_script( self::SCRIPT, BLT_TOOLKIT_URL . 'assets/js/blt-toolkit-user-roles.js', array(), BLT_TOOLKIT_VERSION, true );
		?>
		<div id="<?php echo esc_attr( self::ANCHOR ); ?>" class="blt-tk-additional-roles">
			<h2><?php esc_html_e( 'Additional roles', 'blt-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr id="<?php echo esc_attr( self::ANCHOR . '-row' ); ?>" class="blt-tk-additional-roles-row">
						<th scope="row"><?php esc_html_e( 'Additional roles', 'blt-toolkit' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Additional roles', 'blt-toolkit' ); ?></legend>
								<?php foreach ( $rows as $row ) : ?>
									<label>
										<input type="checkbox"<?php echo $row['enabled'] ? ' name="' . esc_attr( self::FIELD ) . '[]"' : ''; ?> value="<?php echo esc_attr( $row['id'] ); ?>" <?php checked( $row['checked'] ); ?> <?php disabled( ! $row['enabled'] ); ?> />
										<?php echo esc_html( $row['name'] ); ?>
										<?php if ( ! $row['enabled'] ) : ?>
											<span class="description"><?php esc_html_e( '(you cannot change this role; it is kept)', 'blt-toolkit' ); ?></span>
										<?php endif; ?>
									</label><br />
									<?php if ( $row['enabled'] ) : ?>
										<input type="hidden" name="<?php echo esc_attr( self::SHOWN_FIELD ); ?>[]" value="<?php echo esc_attr( $row['id'] ); ?>" />
									<?php endif; ?>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'The Role above stays the main role. Each additional role adds its capabilities on top. Only roles you are allowed to give are listed.', 'blt-toolkit' ); ?></p>
							<input type="hidden" name="<?php echo esc_attr( self::NONCE_FIELD ); ?>" value="<?php echo esc_attr( wp_create_nonce( $nonce_action ) ); ?>" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * `user_profile_update_errors` callback: check the posted additional
	 * roles before core saves anything (US-1).
	 *
	 * Runs only when the section was on the form (its nonce is posted). A
	 * refusal is added to `$errors`, so core saves nothing and shows it. On
	 * success the plan waits for `profile_update` or `user_register`.
	 *
	 * @param WP_Error $errors Errors so far.
	 * @param bool     $update Whether an existing user is being updated.
	 * @param object   $user   The user data edit_user() is about to save.
	 * @return void
	 */
	public static function validate( $errors, $update, $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Our own nonce is verified right here.
		if ( ! $errors instanceof WP_Error || ! is_object( $user ) || ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$target = $update && isset( $user->ID ) ? (int) $user->ID : 0;
		$key    = $update ? (string) $target : 'new';
		$nonce  = is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $key ) ) {
			$errors->add( 'blt_toolkit_additional_roles_expired', __( 'The additional roles could not be checked because the form expired. Reload the page and try again.', 'blt-toolkit' ) );

			return;
		}

		$actor    = wp_get_current_user();
		$editable = self::editable_ids();
		$known    = array_keys( self::role_names() );
		$shown    = array_values( array_intersect( self::posted_roles( self::SHOWN_FIELD ), $editable ) );
		$posted   = array_values( array_intersect( self::posted_roles( self::FIELD ), $known ) );
		$replaced = '';

		if ( $update ) {
			$target_user = get_userdata( $target );

			if ( ! $target_user instanceof WP_User ) {
				return;
			}

			// SEC-5: never one's own roles (core shows no Role field there).
			if ( (int) $actor->ID === $target ) {
				$errors->add( 'blt_toolkit_own_roles', __( 'You cannot change your own roles.', 'blt-toolkit' ) );

				return;
			}

			$allowed = current_user_can( 'promote_user', $target ) ? BLT_Toolkit_Guard::can_act_on_user( $actor, $target_user ) : false;

			if ( true !== $allowed ) {
				$errors->add( 'blt_toolkit_additional_roles_denied', is_wp_error( $allowed ) ? esc_html( $allowed->get_error_message() ) : __( 'You are not allowed to change this user\'s roles.', 'blt-toolkit' ) );

				return;
			}

			$replaced = self::displayed_primary( array_values( array_map( 'strval', (array) $target_user->roles ) ), $editable );
		} elseif ( ! current_user_can( 'promote_users' ) ) {
			$errors->add( 'blt_toolkit_additional_roles_denied', __( 'You are not allowed to give roles to users.', 'blt-toolkit' ) );

			return;
		}

		// SEC-4: a ticked role must be one the actor may give. A role that
		// was not drawn but is still editable is simply ignored.
		$refused = array_values( array_diff( $posted, $editable ) );

		if ( array() !== $refused ) {
			$errors->add(
				'blt_toolkit_role_not_assignable',
				esc_html(
					sprintf(
						/* translators: %s: role names, comma separated. */
						__( 'You are not allowed to give these roles: %s.', 'blt-toolkit' ),
						self::names_text( $refused )
					)
				)
			);

			return;
		}

		// Another check refused the form: core saves nothing, so nothing
		// waits for a save.
		if ( $errors->has_errors() ) {
			return;
		}

		$plan = array(
			'shown'    => $shown,
			'checked'  => array_values( array_intersect( $posted, $shown ) ),
			'set_role' => isset( $user->role ) && is_scalar( $user->role ) ? (string) $user->role : null,
			'replaced' => $replaced,
		);

		// SEC-2, SEC-3: judged on the roles the user will hold, because an
		// unticked or replaced role may deny what a kept role grants.
		if ( $update && isset( $target_user ) ) {
			$desired = self::desired_roles( array_values( array_map( 'strval', (array) $target_user->roles ) ), $plan['shown'], $plan['checked'], $plan['replaced'], $plan['set_role'] );
			$allowed = BLT_Toolkit_Guard::can_change_user_roles( $actor, $target_user, $desired );

			if ( is_wp_error( $allowed ) ) {
				$errors->add( 'blt_toolkit_additional_roles_denied', esc_html( $allowed->get_error_message() ) );

				return;
			}
		}

		self::$plans[ $key ] = $plan;
	}

	/**
	 * `profile_update` callback: apply the additional roles approved by
	 * validate() (US-1), after core has saved the main role.
	 *
	 * Core's set_role() removes every other role when the Role field is
	 * posted; desired_roles() puts back the ones that were not on the page
	 * and adds or drops the ones that were, as ticked.
	 *
	 * @param int          $user_id       User ID.
	 * @param WP_User|null $old_user_data The user before core's save.
	 * @return void
	 */
	public static function apply_profile( $user_id, $old_user_data = null ) {
		$key = (string) (int) $user_id;

		if ( ! isset( self::$plans[ $key ] ) ) {
			return;
		}

		$plan = self::$plans[ $key ];

		unset( self::$plans[ $key ] );

		$user = get_userdata( (int) $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$before  = $old_user_data instanceof WP_User ? array_values( array_map( 'strval', (array) $old_user_data->roles ) ) : array_values( array_map( 'strval', (array) $user->roles ) );
		$desired = self::desired_roles( $before, $plan['shown'], $plan['checked'], $plan['replaced'], $plan['set_role'] );

		self::set_roles( $user, $desired );

		$after = array_values( array_map( 'strval', (array) get_userdata( (int) $user_id )->roles ) );

		self::log_user_change( $user, $before, $after, 'profile' );
	}

	// ---------- US-2 (and US-1 on Add New User) ----------

	/**
	 * `user_register` callback: give a new user their additional roles.
	 *
	 * From Add New User with the US-1 list on the form, exactly what was
	 * ticked there (the defaults were shown ticked). On every other path, the
	 * additional default roles (US-2), limited for a signed-in creator who is
	 * not a toolkit admin to roles they may give.
	 *
	 * @param int $user_id New user's ID.
	 * @return void
	 */
	public static function on_register( $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( isset( self::$plans['new'] ) ) {
			$roles  = self::$plans['new']['checked'];
			$source = 'new_user_form';

			unset( self::$plans['new'] );
		} else {
			$roles  = self::default_roles_for_new_user( $user );
			$source = 'extra_default_roles';
		}

		self::$registered[ (int) $user_id ] = $roles;

		if ( array() === $roles ) {
			return;
		}

		$before = array_values( array_map( 'strval', (array) $user->roles ) );

		self::add_roles( $user, $roles );

		$after = array_values( array_map( 'strval', (array) get_userdata( (int) $user_id )->roles ) );

		self::log_user_change( $user, $before, $after, $source );
	}

	/**
	 * `set_user_role` callback: drop the US-12 count, and when a plugin
	 * replaces the role of a user registered in this request (an SSO plugin
	 * mapping a group to a role after wp_create_user(), say), give back the
	 * additional roles set_role() just removed (US-2).
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      The new role ('' for none).
	 * @param string[] $old_roles The roles before.
	 * @return void
	 */
	public static function after_set_role( $user_id, $role = '', $old_roles = array() ) {
		unset( $old_roles );

		self::forget_count();

		$user_id = (int) $user_id;

		if ( self::$applying || '' === (string) $role || empty( self::$registered[ $user_id ] ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( $user instanceof WP_User ) {
			self::add_roles( $user, self::$registered[ $user_id ] );
		}
	}

	/**
	 * The additional default roles a new user gets now (US-2): the setting,
	 * without roles that no longer exist, roles already held and
	 * `administrator`, and, when a signed-in creator who is not a toolkit
	 * admin is making the account, without roles that creator may not give
	 * (SEC-4).
	 *
	 * @param WP_User $user The new user.
	 * @return string[]
	 */
	public static function default_roles_for_new_user( WP_User $user ) {
		$extra = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$extra = is_array( $extra ) ? $extra : array();

		if ( array() === $extra ) {
			return array();
		}

		$creator    = wp_get_current_user();
		$assignable = null;

		if ( $creator->exists() && ! BLT_Toolkit_Guard::is_toolkit_admin( $creator ) ) {
			$assignable = array_values( array_filter( array_map( 'strval', $extra ), array( 'BLT_Toolkit_Guard', 'may_assign_role' ) ) );
		}

		return self::extra_roles_to_add( $extra, array_map( 'strval', (array) $user->roles ), array_keys( self::role_names() ), $assignable );
	}

	/**
	 * The Settings > Roles control's rows (US-2): every role but
	 * `administrator`, in the site's order, with whether it is on and
	 * whether this person may change it (only roles they may give, SEC-4).
	 *
	 * @param WP_User $actor Who is looking.
	 * @return array<string,array{name:string,on:bool,editable:bool,is_default:bool}>
	 */
	public static function default_roles_state( WP_User $actor ) {
		unset( $actor );

		$stored   = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$stored   = is_array( $stored ) ? array_map( 'strval', $stored ) : array();
		$editable = self::editable_ids();
		$default  = (string) get_option( 'default_role' );
		$out      = array();

		foreach ( self::role_names() as $role => $name ) {
			if ( self::ADMIN_ROLE === $role ) {
				continue;
			}

			$out[ $role ] = array(
				'name'       => $name,
				'on'         => in_array( $role, $stored, true ),
				'editable'   => in_array( $role, $editable, true ),
				'is_default' => $role === $default,
			);
		}

		return $out;
	}

	/**
	 * Save the Settings > Roles control (US-2). Called by
	 * BLT_Toolkit_Admin_Settings::handle_save() after the router checked the
	 * method, `manage_options` and the nonce.
	 *
	 * Only roles that were drawn as changeable change; a role the person may
	 * not give keeps its setting; `administrator` is never stored.
	 *
	 * @return string[] The stored list.
	 */
	public static function save_default_roles_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		if ( ! isset( $_POST[ self::DEFAULTS_SHOWN ] ) ) {
			$stored = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );

			return is_array( $stored ) ? $stored : array();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$stored = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$known  = array_keys( self::role_names() );
		$shown  = array_values( array_intersect( self::posted_roles( self::DEFAULTS_SHOWN ), self::editable_ids() ) );
		$value  = self::merge_default_roles( is_array( $stored ) ? $stored : array(), $shown, self::posted_roles( self::DEFAULTS_FIELD ), $known );

		BLT_Toolkit_Settings::set( 'roles', 'extra_default_roles', $value );

		return $value;
	}

	// ---------- US-3: bulk add and remove ----------

	/**
	 * `restrict_manage_users` callback: "Add role…", "Remove role…" and Apply
	 * next to core's "Change role to…" (US-3), above the list only.
	 *
	 * The Apply button posts the Users form itself to admin-post.php with
	 * `formmethod` and `formaction` (whose query carries the action and the
	 * nonce), so there is no nested form, no extra field in core's GET
	 * requests and no JavaScript. Its own `name="action"` comes after core's
	 * bulk-action select, so it is the `action` PHP keeps.
	 *
	 * In the No role view it also keeps the view when the Users form is
	 * searched or submitted.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	public static function render_bulk_controls( $which = 'top' ) {
		if ( 'bottom' === $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A view filter, read only.
		if ( isset( $_GET[ self::VIEW_ARG ] ) ) {
			echo '<input type="hidden" name="' . esc_attr( self::VIEW_ARG ) . '" value="1" />';
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			return;
		}

		$names    = self::role_names();
		$editable = array_values( array_intersect( array_keys( $names ), self::editable_ids() ) );

		if ( array() === $editable ) {
			return;
		}

		$url = add_query_arg(
			array(
				'action'         => self::BULK_ACTION,
				self::BULK_NONCE => wp_create_nonce( self::BULK_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
		// Core prints this hook inside the `div` of its "Change role to…"
		// controls, whose selects float left of its Change button; closing
		// that `div` and opening our own keeps each Apply next to its selects.
		// Core's closing tag then closes ours.
		?>
		</div>
		<div class="alignleft actions blt-tk-bulk-roles">
			<label class="screen-reader-text" for="blt-tk-add-role"><?php esc_html_e( 'Add role&hellip;', 'blt-toolkit' ); ?></label>
			<select name="<?php echo esc_attr( self::BULK_ADD ); ?>" id="blt-tk-add-role">
				<option value=""><?php esc_html_e( 'Add role&hellip;', 'blt-toolkit' ); ?></option>
				<?php foreach ( $editable as $role ) : ?>
					<option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $names[ $role ] ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="blt-tk-remove-role"><?php esc_html_e( 'Remove role&hellip;', 'blt-toolkit' ); ?></label>
			<select name="<?php echo esc_attr( self::BULK_REMOVE ); ?>" id="blt-tk-remove-role">
				<option value=""><?php esc_html_e( 'Remove role&hellip;', 'blt-toolkit' ); ?></option>
				<?php foreach ( $editable as $role ) : ?>
					<option value="<?php echo esc_attr( $role ); ?>"><?php echo esc_html( $names[ $role ] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button" id="blt-tk-bulk-roles-apply" name="action" value="<?php echo esc_attr( self::BULK_ACTION ); ?>" formmethod="post" formaction="<?php echo esc_url( $url ); ?>">
				<?php
				echo wp_kses(
					__( 'Apply<span class="screen-reader-text"> the role changes to the selected users</span>', 'blt-toolkit' ),
					array( 'span' => array( 'class' => true ) )
				);
				?>
			</button>
		<?php
	}

	/**
	 * admin-post.php handler of the bulk Apply (US-3).
	 *
	 * Checks the method (405), `promote_users` (403) and the nonce; then that
	 * each chosen role is in the actor's `editable_roles` (SEC-4); then each
	 * user on their own: not oneself (SEC-5), `promote_user`,
	 * can_act_on_user() (SEC-1, SEC-2) and can_change_user_roles() on the
	 * roles they would hold (SEC-3: removing a role that denies a capability
	 * gives it back). Writes one aggregate log row and redirects back to the
	 * Users list with the result.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		self::require_post( 'promote_users' );

		check_admin_referer( self::BULK_ACTION, self::BULK_NONCE );

		$actor  = wp_get_current_user();
		$names  = self::role_names();
		$add    = self::posted_role( self::BULK_ADD, $names );
		$remove = self::posted_role( self::BULK_REMOVE, $names );
		$ids    = self::posted_user_ids();
		$back   = self::users_back_url();

		if ( '' === $add && '' === $remove ) {
			self::bulk_redirect( $back, array( 'code' => 'no_role' ) );
		}

		if ( $add === $remove ) {
			self::bulk_redirect( $back, array( 'code' => 'same_role' ) );
		}

		if ( array() === $ids ) {
			self::bulk_redirect( $back, array( 'code' => 'no_users' ) );
		}

		$editable = self::editable_ids();

		foreach ( array( $add, $remove ) as $role ) {
			if ( '' !== $role && ! in_array( $role, $editable, true ) ) {
				self::bulk_redirect(
					$back,
					array(
						'code' => 'not_assignable',
						'role' => $role,
					)
				);
			}
		}

		$result = array(
			'added'     => array(),
			'removed'   => array(),
			'unchanged' => array(),
			'skipped'   => array(),
		);

		foreach ( $ids as $id ) {
			$target = get_userdata( $id );

			if ( ! $target instanceof WP_User ) {
				continue;
			}

			$login = (string) $target->user_login;

			if ( (int) $actor->ID === $id ) {
				$result['skipped'][ $login ] = 'SEC-5';
				continue;
			}

			$allowed = current_user_can( 'promote_user', $id ) ? BLT_Toolkit_Guard::can_act_on_user( $actor, $target ) : false;

			if ( true !== $allowed ) {
				$rule = is_wp_error( $allowed ) ? (array) $allowed->get_error_data() : array();

				$result['skipped'][ $login ] = isset( $rule['rule'] ) && '' !== (string) $rule['rule'] ? (string) $rule['rule'] : 'promote_user';
				continue;
			}

			$held   = array_values( array_map( 'strval', (array) $target->roles ) );
			$change = self::bulk_change( $held, $add, $remove );

			// SEC-2, SEC-3: judged on the roles the user would hold, since
			// removing a role that denies a capability gives it back.
			if ( $change['add'] || $change['remove'] ) {
				$allowed = BLT_Toolkit_Guard::can_change_user_roles( $actor, $target, self::bulk_roles_after( $held, $add, $remove ) );

				if ( true !== $allowed ) {
					$rule = is_wp_error( $allowed ) ? (array) $allowed->get_error_data() : array();

					$result['skipped'][ $login ] = isset( $rule['rule'] ) && '' !== (string) $rule['rule'] ? (string) $rule['rule'] : 'SEC-3';
					continue;
				}
			}

			if ( $change['add'] ) {
				$target->add_role( $add );
				$result['added'][] = $login;
			}

			if ( $change['remove'] ) {
				$target->remove_role( $remove );
				$result['removed'][] = $login;
			}

			if ( ! $change['add'] && ! $change['remove'] ) {
				$result['unchanged'][] = $login;
			}
		}

		self::log_bulk( $add, $remove, $result );

		self::bulk_redirect(
			$back,
			array(
				'code'      => 'done',
				'add'       => $add,
				'remove'    => $remove,
				'added'     => count( $result['added'] ),
				'removed'   => count( $result['removed'] ),
				'unchanged' => count( $result['unchanged'] ),
				'skipped'   => count( $result['skipped'] ),
			)
		);
	}

	// ---------- US-12: users without a role ----------

	/**
	 * Register the `count_no_role` job type (JOB-5).
	 *
	 * @return void
	 */
	public static function register_job_type() {
		if ( ! class_exists( 'BLT_Toolkit_Jobs' ) ) {
			return;
		}

		BLT_Toolkit_Jobs::register_type(
			self::JOB_TYPE,
			array(
				'label'      => __( 'Count users without a role', 'blt-toolkit' ),
				// Whoever may see the Users list may see this number.
				'capability' => 'list_users',
				'mode'       => 'cursor',
				'process'    => array( __CLASS__, 'count_step' ),
			)
		);
	}

	/**
	 * The cached count as the screens show it, with no counting (US-12).
	 *
	 * One transient read. While a job runs, the transient names it; a job
	 * that ended without a count (cancelled, failed) reads as unknown.
	 *
	 * @return array{status:string,count:int,at:int,job:string,stale:bool}
	 *         `status` is `unknown`, `counting` or `counted`; `stale` says
	 *         someone's roles changed while the count job ran.
	 */
	public static function no_role_state() {
		$state = self::read_state( get_transient( self::COUNT_TRANSIENT ) );

		if ( 'counting' === $state['status'] ) {
			$job = BLT_Toolkit_Jobs::get( $state['job'] );

			if ( null === $job || BLT_Toolkit_Jobs::is_terminal( (string) $job['status'] ) ) {
				// A finished job writes its count over this marker, so a
				// marker left behind means it ended without one.
				$again = self::read_state( get_transient( self::COUNT_TRANSIENT ) );

				return 'counting' === $again['status'] ? self::read_state( false ) : $again;
			}
		}

		return $state;
	}

	/**
	 * The cached count from a transient value. Pure.
	 *
	 * @param mixed $value What get_transient() returned.
	 * @return array{status:string,count:int,at:int,job:string,stale:bool}
	 */
	public static function read_state( $value ) {
		$out = array(
			'status' => 'unknown',
			'count'  => 0,
			'at'     => 0,
			'job'    => '',
			'stale'  => false,
		);

		if ( ! is_array( $value ) ) {
			return $out;
		}

		$out['at']    = isset( $value['at'] ) && is_numeric( $value['at'] ) ? (int) $value['at'] : 0;
		$out['stale'] = ! empty( $value['stale'] );

		if ( isset( $value['count'] ) && is_numeric( $value['count'] ) && (int) $value['count'] >= 0 ) {
			$out['status'] = 'counted';
			$out['count']  = (int) $value['count'];
		} elseif ( isset( $value['job'] ) && is_string( $value['job'] ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $value['job'] ) ) {
			$out['status'] = 'counting';
			$out['job']    = $value['job'];
		}

		return $out;
	}

	/**
	 * Count users without a role now (US-12): one query on an ordinary site,
	 * the `count_no_role` job on a large one. Called only when someone asks.
	 *
	 * @return string `counted`, `started`, `busy` (a job is already running)
	 *                or `failed`.
	 */
	public static function count_now() {
		if ( function_exists( 'wp_is_large_user_count' ) && wp_is_large_user_count() ) {
			return self::start_count_job();
		}

		$count = self::count_range( 0, 0 );

		if ( null === $count ) {
			return 'failed';
		}

		self::store_count( $count );

		return 'counted';
	}

	/**
	 * Start the `count_no_role` job, or report the one already running.
	 *
	 * @return string `started`, `busy` or `failed`.
	 */
	private static function start_count_job() {
		global $wpdb;

		$state = self::no_role_state();

		if ( 'counting' === $state['status'] ) {
			return 'busy';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read when a count is asked for.
		$max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );
		$id  = BLT_Toolkit_Jobs::start(
			self::JOB_TYPE,
			array(
				'cursor' => 0,
				'count'  => 0,
				'total'  => $max,
				'done'   => 0,
			),
			array(
				'max_id' => $max,
				'batch'  => self::JOB_BATCH,
			)
		);

		if ( is_wp_error( $id ) ) {
			return 'failed';
		}

		set_transient(
			self::COUNT_TRANSIENT,
			array(
				'job' => (string) $id,
				'at'  => time(),
			),
			HOUR_IN_SECONDS
		);

		return 'started';
	}

	/**
	 * One step of the `count_no_role` job (cursor mode): count the users
	 * without a role among the next `batch` user IDs; at the end, store the
	 * total in the transient (US-12).
	 *
	 * @param array $state  Cursor state, updated in place: `cursor`, `count`, `total`, `done`, `current`, `error`.
	 * @param array $pinned `max_id` and `batch`, pinned at start (JOB-3).
	 * @return bool True when finished.
	 */
	public static function count_step( array &$state, array $pinned ) {
		$max    = isset( $pinned['max_id'] ) ? max( 0, (int) $pinned['max_id'] ) : 0;
		$batch  = isset( $pinned['batch'] ) ? max( 1, (int) $pinned['batch'] ) : self::JOB_BATCH;
		$cursor = isset( $state['cursor'] ) ? max( 0, (int) $state['cursor'] ) : 0;

		$state['count'] = isset( $state['count'] ) ? (int) $state['count'] : 0;
		$state['total'] = $max;

		if ( $cursor < $max ) {
			$to    = min( $max, $cursor + $batch );
			$count = self::count_range( $cursor, $to );

			if ( null === $count ) {
				$state['error'] = 'the count query failed';

				delete_transient( self::COUNT_TRANSIENT );

				return true;
			}

			$state['count']  += $count;
			$state['cursor']  = $to;
			$state['done']    = $to;
			$state['current'] = sprintf(
				/* translators: 1: first user ID, 2: last user ID. */
				__( 'Users %1$s to %2$s', 'blt-toolkit' ),
				number_format_i18n( $cursor + 1 ),
				number_format_i18n( $to )
			);

			if ( $to < $max ) {
				return false;
			}
		}

		$state['done'] = $max;

		self::store_count( $state['count'] );

		return true;
	}

	/**
	 * Store a count for an hour (US-12). A count job's result is marked
	 * stale when someone's roles changed while it ran (forget_count()).
	 *
	 * @param int $count Users without a role.
	 * @return void
	 */
	private static function store_count( $count ) {
		$now = self::read_state( get_transient( self::COUNT_TRANSIENT ) );

		set_transient(
			self::COUNT_TRANSIENT,
			array(
				'count' => max( 0, (int) $count ),
				'at'    => time(),
				'stale' => 'counting' === $now['status'] && $now['stale'],
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * `blt_toolkit_after_roles_save` callback (US-12): a write that deletes
	 * or creates a role (a restore, a full reset, a role deleted) changes who
	 * has no role, so the cached count goes.
	 *
	 * @param mixed $changes Role ID => change (BLT_Toolkit_Role_Store).
	 * @return void
	 */
	public static function after_roles_save( $changes ) {
		foreach ( is_array( $changes ) ? $changes : array() as $change ) {
			if ( is_array( $change ) && ( ! empty( $change['deleted'] ) || ! empty( $change['created'] ) ) ) {
				self::forget_count();

				return;
			}
		}
	}

	/**
	 * Drop the cached count (US-12), once per request: someone's roles
	 * changed, so the number may be wrong.
	 *
	 * While a count job runs, its marker is kept, flagged stale, so the
	 * screens still show it counting and nobody starts a second job; the
	 * job's result is then stored as stale (store_count()).
	 *
	 * @return void
	 */
	public static function forget_count() {
		if ( self::$forgot ) {
			return;
		}

		self::$forgot = true;

		$state = self::read_state( get_transient( self::COUNT_TRANSIENT ) );

		if ( 'counting' === $state['status'] && class_exists( 'BLT_Toolkit_Jobs' ) ) {
			$job = BLT_Toolkit_Jobs::get( $state['job'] );

			if ( null !== $job && ! BLT_Toolkit_Jobs::is_terminal( (string) $job['status'] ) ) {
				if ( ! $state['stale'] ) {
					set_transient(
						self::COUNT_TRANSIENT,
						array(
							'job'   => $state['job'],
							'at'    => $state['at'],
							'stale' => true,
						),
						HOUR_IN_SECONDS
					);
				}

				return;
			}
		}

		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Users without a role among IDs in (`$after`, `$to`], or among all users
	 * when `$to` is 0.
	 *
	 * @param int $after Exclusive lower bound.
	 * @param int $to    Inclusive upper bound; 0 for no range.
	 * @return int|null Null when the query failed.
	 */
	private static function count_range( $after, $to ) {
		global $wpdb;

		$where = self::no_role_sql( "{$wpdb->users}.ID" );

		if ( (int) $to > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->users}.ID > %d AND {$wpdb->users}.ID <= %d", (int) $after, (int) $to ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name.
		}

		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Run only when asked for (US-12); the clause is prepared in no_role_sql().
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE {$where}" );

		$wpdb->suppress_errors( $suppressed );

		return null === $count ? null : (int) $count;
	}

	/**
	 * The SQL condition "this user has no role on this site" (US-12): no
	 * capabilities meta row names a role that exists here. That covers a
	 * missing or empty meta (`''`, `a:0:{}`, NULL) and a meta that names
	 * only roles since deleted (a full reset, a restore), which give nothing
	 * (WP_User::$roles is empty for them, and core's own "No role" view
	 * counts them too). A role is matched by its key in the serialized
	 * array (BLT_Toolkit_Snapshots::role_key()). A user with two rows, one
	 * of them naming a role, has a role.
	 *
	 * @param string $id_column The users table's ID column as the query names it.
	 * @return string Prepared SQL.
	 */
	public static function no_role_sql( $id_column ) {
		global $wpdb;

		$match = array();

		foreach ( array_keys( (array) wp_roles()->roles ) as $role ) {
			$match[] = $wpdb->prepare( 'blt_caps.meta_value LIKE %s', '%' . $wpdb->esc_like( BLT_Toolkit_Snapshots::role_key( (string) $role ) ) . '%' );
		}

		if ( array() === $match ) {
			$match[] = '1 = 0';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Core table and column names; each LIKE is prepared above; the key is bound.
		return $wpdb->prepare( "NOT EXISTS ( SELECT 1 FROM {$wpdb->usermeta} blt_caps WHERE blt_caps.user_id = {$id_column} AND blt_caps.meta_key = %s AND ( " . implode( ' OR ', $match ) . ' ) )', $wpdb->get_blog_prefix() . 'capabilities' );
	}

	/**
	 * admin-post.php handler of the Count button (US-12), from users.php or
	 * the Overview.
	 *
	 * @return void
	 */
	public static function handle_count() {
		self::require_post( 'list_users' );

		check_admin_referer( self::COUNT_ACTION, BLT_Toolkit_Admin::NONCE_FIELD );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$back   = isset( $_POST[ self::BACK_FIELD ] ) && 'overview' === $_POST[ self::BACK_FIELD ] ? 'overview' : 'users';
		$result = self::count_now();
		$url    = 'overview' === $back && current_user_can( 'manage_options' )
			? add_query_arg( 'page', BLT_Toolkit_Admin::MENU_SLUG, admin_url( 'admin.php' ) )
			: self::users_back_url();

		wp_safe_redirect( add_query_arg( self::COUNT_RESULT_ARG, $result, $url ) );
		exit;
	}

	/**
	 * `load-users.php` callback: the US-12 view, its list filter and the
	 * notices, on the Users screen only.
	 *
	 * @return void
	 */
	public static function load_users_screen() {
		add_filter( 'views_users', array( __CLASS__, 'filter_views' ), 20 );
		add_filter( 'users_list_table_query_args', array( __CLASS__, 'filter_list_query' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'users_notices' ) );
	}

	/**
	 * `views_users` callback: replace core's "No role" view, which counts only
	 * an empty meta on every load, with BLT Toolkit's: "No role (N)" when a
	 * count is cached, otherwise a Count button (US-12). Never counts.
	 *
	 * @param array $views View key => link HTML.
	 * @return array
	 */
	public static function filter_views( $views ) {
		if ( ! is_array( $views ) || ! current_user_can( 'list_users' ) ) {
			return $views;
		}

		unset( $views['none'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A view filter, read only.
		$active = isset( $_GET[ self::VIEW_ARG ] );
		$state  = self::no_role_state();
		$url    = add_query_arg( self::VIEW_ARG, '1', admin_url( 'users.php' ) );

		if ( $active && isset( $views['all'] ) ) {
			$views['all'] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', (string) $views['all'] );
		}

		if ( 'counted' === $state['status'] ) {
			$count = number_format_i18n( $state['count'] );

			if ( $state['stale'] ) {
				/* translators: %s: number of users. */
				$count = sprintf( __( '%s, roles changed while counting', 'blt-toolkit' ), $count );
			}

			$views['blt_no_role'] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>%5$s',
				esc_url( $url ),
				$active ? ' class="current" aria-current="page"' : '',
				esc_html__( 'No role', 'blt-toolkit' ),
				esc_html( $count ),
				$state['stale'] ? ' ' . self::count_form( 'users' ) : ''
			);

			return $views;
		}

		$label = 'counting' === $state['status'] ? __( 'No role (counting…)', 'blt-toolkit' ) : __( 'No role', 'blt-toolkit' );

		$views['blt_no_role'] = sprintf(
			'<a href="%1$s"%2$s>%3$s</a> %4$s',
			esc_url( $url ),
			$active ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			'counting' === $state['status'] ? '' : self::count_form( 'users' )
		);

		return $views;
	}

	/**
	 * The Count button: its own small form (outside core's Users form),
	 * posting to admin-post.php.
	 *
	 * @param string $back `users` or `overview`.
	 * @return string HTML.
	 */
	public static function count_form( $back ) {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="blt-tk-count-no-role" style="display:inline">
			<?php BLT_Toolkit_Admin::form_fields( self::COUNT_ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::BACK_FIELD ); ?>" value="<?php echo esc_attr( 'overview' === $back ? 'overview' : 'users' ); ?>" />
			<button type="submit" class="<?php echo 'overview' === $back ? 'button button-secondary' : 'button-link'; ?>">
				<?php
				echo wp_kses(
					__( 'Count<span class="screen-reader-text"> users without a role</span>', 'blt-toolkit' ),
					array( 'span' => array( 'class' => true ) )
				);
				?>
			</button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * `users_list_table_query_args` callback: in the No role view, list only
	 * users without a role (US-12).
	 *
	 * SEC-6 leaves administrators out by ID, which this view does not
	 * affect. When it falls back to core's `role__not_in` clause (the IDs
	 * could not be read), that clause is taken out here, because core builds
	 * it as a meta clause that only users with a capabilities row pass, which
	 * would hide users who have none; nobody without a role is an
	 * administrator, so nobody hidden appears.
	 *
	 * @param array $args WP_User_Query arguments.
	 * @return array
	 */
	public static function filter_list_query( $args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A view filter, read only.
		if ( ! is_array( $args ) || ! isset( $_GET[ self::VIEW_ARG ] ) ) {
			return $args;
		}

		$args[ self::QUERY_VAR ] = true;

		unset( $args['role'] );

		if ( isset( $args['role__not_in'] ) ) {
			$args['role__not_in'] = array_values( array_diff( (array) $args['role__not_in'], array( self::ADMIN_ROLE ) ) );
		}

		add_action( 'pre_user_query', array( __CLASS__, 'restrict_query' ) );

		return $args;
	}

	/**
	 * `pre_user_query` callback, added only in the No role view: add the
	 * no-role condition to the query that carries the flag.
	 *
	 * @param WP_User_Query $query The query.
	 * @return void
	 */
	public static function restrict_query( $query ) {
		global $wpdb;

		if ( ! $query instanceof WP_User_Query || ! $query->get( self::QUERY_VAR ) ) {
			return;
		}

		$query->query_where .= ' AND ' . self::no_role_sql( "{$wpdb->users}.ID" );
	}

	/**
	 * `admin_notices` on users.php: the result of a bulk Apply (US-3) or a
	 * Count (US-12). Reads nothing unless the redirect's flag is present.
	 *
	 * @return void
	 */
	public static function users_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only, after our own redirect.
		$bulk  = isset( $_GET[ self::BULK_RESULT_ARG ] );
		$count = isset( $_GET[ self::COUNT_RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::COUNT_RESULT_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $count ) {
			$message = self::count_message( $count );

			if ( null !== $message ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $message['type'] ), esc_html( $message['text'] ) );
			}
		}

		if ( ! $bulk ) {
			return;
		}

		$key    = self::BULK_NOTICE_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( $key );

		$message = self::bulk_message( $result );

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $message['type'] ), esc_html( $message['text'] ) );
	}

	/**
	 * The notice for a Count result code, or null for an unknown code.
	 *
	 * @param string $code `counted`, `started`, `busy` or `failed`.
	 * @return array{type:string,text:string}|null
	 */
	public static function count_message( $code ) {
		switch ( $code ) {
			case 'counted':
				$state = self::no_role_state();

				if ( 'counted' !== $state['status'] ) {
					return null;
				}

				return array(
					'type' => 'success',
					'text' => sprintf(
						/* translators: %s: number of users. */
						_n( '%s user has no role on this site. Open the No role view to give them one.', '%s users have no role on this site. Open the No role view to give them one.', $state['count'], 'blt-toolkit' ),
						number_format_i18n( $state['count'] )
					),
				);

			case 'started':
				return array(
					'type' => 'info',
					'text' => __( 'This site has many users, so they are being counted in the background. Reload this page in a minute to see the number; the Overview shows the progress.', 'blt-toolkit' ),
				);

			case 'busy':
				return array(
					'type' => 'info',
					'text' => __( 'Users without a role are already being counted. Reload this page in a minute to see the number.', 'blt-toolkit' ),
				);

			case 'failed':
				return array(
					'type' => 'error',
					'text' => __( 'Users without a role could not be counted. Try again; if this keeps happening, the PHP error log may say why.', 'blt-toolkit' ),
				);
		}

		return null;
	}

	// ---------- The Overview (US-12, SPEC 13.2) ----------

	/**
	 * The Overview's "Users without a role" stat tile (US-12): the cached
	 * count, when it was counted and a Count button. Never counts.
	 *
	 * @return void
	 */
	public static function render_overview_tile() {
		$state = self::no_role_state();
		?>
		<div class="blt-stat blt-toolkit-no-role-stat">
			<span class="blt-stat-label"><?php esc_html_e( 'Users without a role', 'blt-toolkit' ); ?></span>
			<span class="blt-stat-value">
				<?php if ( 'counted' === $state['status'] && $state['count'] > 0 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( self::VIEW_ARG, '1', admin_url( 'users.php' ) ) ); ?>"><?php echo esc_html( number_format_i18n( $state['count'] ) ); ?></a>
				<?php elseif ( 'counted' === $state['status'] ) : ?>
					<?php echo esc_html( number_format_i18n( 0 ) ); ?>
				<?php else : ?>
					<span aria-hidden="true">&mdash;</span><span class="screen-reader-text"><?php esc_html_e( 'Not counted', 'blt-toolkit' ); ?></span>
				<?php endif; ?>
			</span>
			<span class="blt-stat-sub">
				<?php
				if ( 'counted' === $state['status'] && $state['stale'] ) {
					printf(
						/* translators: %s: how long ago, such as "5 mins". */
						esc_html__( 'counted %s ago; roles changed while counting, so count again for an exact number', 'blt-toolkit' ),
						esc_html( human_time_diff( $state['at'], time() ) )
					);
				} elseif ( 'counted' === $state['status'] ) {
					printf(
						/* translators: %s: how long ago, such as "5 mins". */
						esc_html__( 'counted %s ago', 'blt-toolkit' ),
						esc_html( human_time_diff( $state['at'], time() ) )
					);
				} elseif ( 'counting' === $state['status'] ) {
					esc_html_e( 'counting now', 'blt-toolkit' );
				} else {
					esc_html_e( 'counted when you ask, not on every page', 'blt-toolkit' );
				}
				?>
			</span>
			<?php if ( 'counting' !== $state['status'] && current_user_can( 'list_users' ) ) : ?>
				<div class="blt-stat-sub"><?php echo self::count_form( 'overview' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in count_form(). ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Under the Overview's stat tiles: the count job's progress panel
	 * (JOB-4) while one runs or just ended, and a failed Count's message.
	 *
	 * @return void
	 */
	public static function render_overview_job() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, after our own redirect.
		$code = isset( $_GET[ self::COUNT_RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::COUNT_RESULT_ARG ] ) ) : '';

		if ( 'failed' === $code ) {
			$message = self::count_message( 'failed' );
			?>
			<div class="blt-callout" role="alert">
				<strong><?php esc_html_e( 'Not counted', 'blt-toolkit' ); ?></strong>
				<span><?php echo esc_html( $message['text'] ); ?></span>
			</div>
			<?php
		}

		$state = self::no_role_state();

		if ( 'counting' === $state['status'] ) {
			BLT_Toolkit_Jobs::render_ui( $state['job'] );
		}
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * The roles a user should hold after a profile save with the US-1 list.
	 * Pure.
	 *
	 * - When core set the main role (`$set_role` not null; '' is "No role"),
	 *   it takes the place of the role its dropdown showed (`$replaced`), or
	 *   comes first when the dropdown showed none.
	 * - A role the user held that was drawn as a checkbox stays only if
	 *   ticked; one that was not drawn (a role the actor may not give, or the
	 *   main role when core did not set one) is kept, because core's
	 *   set_role() removed it without anyone asking.
	 * - Roles keep their order: a later role's `false` overrides an earlier
	 *   role's `true`, so moving a role that denies a capability ahead of one
	 *   that grants it would give the capability back (SEC-3).
	 * - A ticked role the user did not hold is added at the end.
	 *
	 * @param string[]    $before   The user's roles before the save, in order.
	 * @param string[]    $shown    Roles drawn as checkboxes.
	 * @param string[]    $checked  Of those, the ticked ones.
	 * @param string      $replaced The role core's dropdown showed ('' for none).
	 * @param string|null $set_role The role core set, '' for none, null when core did not set one.
	 * @return string[]
	 */
	public static function desired_roles( array $before, array $shown, array $checked, $replaced, $set_role ) {
		$out    = array();
		$placed = false;

		foreach ( $before as $role ) {
			$role = (string) $role;

			if ( null !== $set_role && $role === (string) $replaced ) {
				if ( '' !== (string) $set_role ) {
					$out[] = (string) $set_role;
				}

				$placed = true;
				continue;
			}

			if ( in_array( $role, $shown, true ) && ! in_array( $role, $checked, true ) ) {
				continue;
			}

			$out[] = $role;
		}

		if ( ! $placed && null !== $set_role && '' !== (string) $set_role ) {
			array_unshift( $out, (string) $set_role );
		}

		foreach ( $checked as $role ) {
			$out[] = (string) $role;
		}

		return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
	}

	/**
	 * The additional default roles to give a new user (US-2). Pure.
	 *
	 * Keeps the setting's order; leaves out `administrator`, roles that do not
	 * exist, roles the user already holds and, when `$assignable` is a list,
	 * roles not in it.
	 *
	 * @param array      $extra      `roles.extra_default_roles`.
	 * @param array      $held       The user's roles now.
	 * @param array      $known      The site's role IDs.
	 * @param array|null $assignable Roles the creator may give, or null for no limit.
	 * @return string[]
	 */
	public static function extra_roles_to_add( array $extra, array $held, array $known, $assignable = null ) {
		$out = array();

		foreach ( $extra as $role ) {
			if ( ! is_string( $role ) ) {
				continue;
			}

			if ( self::ADMIN_ROLE === $role || ! in_array( $role, $known, true ) || in_array( $role, $held, true ) || in_array( $role, $out, true ) ) {
				continue;
			}

			if ( is_array( $assignable ) && ! in_array( $role, $assignable, true ) ) {
				continue;
			}

			$out[] = $role;
		}

		return $out;
	}

	/**
	 * The new `roles.extra_default_roles` from the Settings > Roles control
	 * (US-2). Pure.
	 *
	 * A role that was drawn as changeable is on exactly when ticked; any other
	 * stored role keeps its setting while it exists; `administrator` and
	 * roles that do not exist are dropped. Stored order first, then newly
	 * ticked roles in the order of `$known`.
	 *
	 * @param array $stored  The stored list.
	 * @param array $shown   Roles drawn as changeable.
	 * @param array $checked Roles ticked.
	 * @param array $known   The site's role IDs, in order.
	 * @return string[]
	 */
	public static function merge_default_roles( array $stored, array $shown, array $checked, array $known ) {
		$out = array();

		foreach ( $stored as $role ) {
			$role = (string) $role;

			if ( in_array( $role, $shown, true ) && ! in_array( $role, $checked, true ) ) {
				continue;
			}

			$out[] = $role;
		}

		foreach ( $known as $role ) {
			$role = (string) $role;

			if ( in_array( $role, $shown, true ) && in_array( $role, $checked, true ) ) {
				$out[] = $role;
			}
		}

		$out = array_values( array_unique( $out ) );

		return array_values(
			array_filter(
				$out,
				static function ( $role ) use ( $known ) {
					return BLT_Toolkit_User_Roles::ADMIN_ROLE !== $role && in_array( $role, $known, true );
				}
			)
		);
	}

	/**
	 * What a bulk Apply changes for one user (US-3). Pure.
	 *
	 * @param string[] $held   The user's roles.
	 * @param string   $add    Role to add, or ''.
	 * @param string   $remove Role to remove, or ''.
	 * @return array{add:bool,remove:bool}
	 */
	public static function bulk_change( array $held, $add, $remove ) {
		return array(
			'add'    => '' !== (string) $add && ! in_array( (string) $add, $held, true ),
			'remove' => '' !== (string) $remove && in_array( (string) $remove, $held, true ),
		);
	}

	/**
	 * A capabilities meta array with its role keys in the given order, first,
	 * and every other key after them as it was. Values are kept. Pure.
	 *
	 * @param array    $caps  The user's `{prefix}capabilities` meta.
	 * @param string[] $roles The role keys, in the order wanted.
	 * @return array
	 */
	public static function caps_in_role_order( array $caps, array $roles ) {
		$out = array();

		foreach ( $roles as $role ) {
			$role = (string) $role;

			if ( array_key_exists( $role, $caps ) ) {
				$out[ $role ] = $caps[ $role ];
			}
		}

		foreach ( $caps as $key => $value ) {
			if ( ! array_key_exists( (string) $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * The roles a user holds after a bulk Apply (US-3), in the order
	 * WP_User::add_role() and remove_role() leave them: the added role last.
	 * Pure.
	 *
	 * @param string[] $held   The user's roles, in order.
	 * @param string   $add    Role to add, or ''.
	 * @param string   $remove Role to remove, or ''.
	 * @return string[]
	 */
	public static function bulk_roles_after( array $held, $add, $remove ) {
		$out = array_values( array_map( 'strval', $held ) );

		if ( '' !== (string) $add && ! in_array( (string) $add, $out, true ) ) {
			$out[] = (string) $add;
		}

		if ( '' !== (string) $remove ) {
			$out = array_values( array_diff( $out, array( (string) $remove ) ) );
		}

		return $out;
	}

	/**
	 * The main role core's profile dropdown shows: the first role the user
	 * holds that the actor may give, as wp-admin/user-edit.php picks it.
	 * Pure.
	 *
	 * @param string[] $held     The user's roles, in order.
	 * @param string[] $editable Roles the actor may give.
	 * @return string '' when none.
	 */
	public static function displayed_primary( array $held, array $editable ) {
		foreach ( $held as $role ) {
			if ( in_array( (string) $role, $editable, true ) ) {
				return (string) $role;
			}
		}

		return '';
	}

	// ---------- Internals ----------

	/**
	 * Every role, ID => translated display name, in the site's order.
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
	 * The current user's filtered `editable_roles` (SEC-4), as IDs.
	 *
	 * @return string[]
	 */
	private static function editable_ids() {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$editable = get_editable_roles();

		return is_array( $editable ) ? array_map( 'strval', array_keys( $editable ) ) : array();
	}

	/**
	 * A posted list of role IDs, matched exactly against the site's roles
	 * (role IDs keep their case, so no sanitize_key()).
	 *
	 * @param string $field POST field.
	 * @return string[]
	 */
	private static function posted_roles( $field ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		if ( ! isset( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is matched exactly against the site's role IDs below.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$known = self::role_names();
		$out   = array();

		foreach ( $raw as $value ) {
			if ( is_string( $value ) && isset( $known[ $value ] ) && ! in_array( $value, $out, true ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/**
	 * One posted role ID, matched exactly against the site's roles.
	 *
	 * @param string $field POST field.
	 * @param array  $names Role ID => name.
	 * @return string '' when missing or unknown.
	 */
	private static function posted_role( $field, array $names ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller.
		if ( ! isset( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) {
			return '';
		}

		$value = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Matched exactly against the site's role IDs.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return isset( $names[ $value ] ) ? $value : '';
	}

	/**
	 * The posted `users[]` of the Users form, as unique positive IDs.
	 *
	 * @return int[]
	 */
	private static function posted_user_ids() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the caller; absint() below.
		$raw = isset( $_POST['users'] ) && is_array( $_POST['users'] ) ? wp_unslash( $_POST['users'] ) : array();
		$ids = array();

		foreach ( $raw as $value ) {
			$id = is_scalar( $value ) ? absint( $value ) : 0;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}

			if ( count( $ids ) >= self::BULK_MAX ) {
				break;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Where to return on the Users screen: the referring Users list (search,
	 * view and page kept) when it is one, else users.php.
	 *
	 * @return string
	 */
	private static function users_back_url() {
		$users   = admin_url( 'users.php' );
		$referer = wp_get_referer();

		if ( is_string( $referer ) && 0 === strpos( $referer, $users ) ) {
			return remove_query_arg( array( self::BULK_RESULT_ARG, self::COUNT_RESULT_ARG, 'update', '_wpnonce', '_wp_http_referer' ), $referer );
		}

		return $users;
	}

	/**
	 * Refuse anything but a POST (405) by someone with the capability (403).
	 *
	 * @param string $cap Capability.
	 * @return void
	 */
	private static function require_post( $cap ) {
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

		if ( ! current_user_can( $cap ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Keep a bulk result for the next screen and go there (PRG).
	 *
	 * @param string $url    Users list URL.
	 * @param array  $result The result.
	 * @return void
	 */
	private static function bulk_redirect( $url, array $result ) {
		set_transient( self::BULK_NOTICE_TRANSIENT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( self::BULK_RESULT_ARG, '1', $url ) );
		exit;
	}

	/**
	 * The notice for a bulk result.
	 *
	 * @param array $result What handle_bulk() stored.
	 * @return array{type:string,text:string}
	 */
	private static function bulk_message( array $result ) {
		$code  = isset( $result['code'] ) ? (string) $result['code'] : '';
		$names = self::role_names();
		$name  = static function ( $key ) use ( $result, $names ) {
			$role = isset( $result[ $key ] ) ? (string) $result[ $key ] : '';

			return isset( $names[ $role ] ) ? $names[ $role ] : $role;
		};

		switch ( $code ) {
			case 'no_role':
				return array(
					'type' => 'error',
					'text' => __( 'Nothing was changed: choose a role to add or a role to remove.', 'blt-toolkit' ),
				);

			case 'same_role':
				return array(
					'type' => 'error',
					'text' => __( 'Nothing was changed: a role cannot be added and removed at the same time.', 'blt-toolkit' ),
				);

			case 'no_users':
				return array(
					'type' => 'error',
					'text' => __( 'Nothing was changed: select at least one user.', 'blt-toolkit' ),
				);

			case 'not_assignable':
				return array(
					'type' => 'error',
					/* translators: %s: role name. */
					'text' => sprintf( __( 'Nothing was changed: you are not allowed to give or take away the role %s.', 'blt-toolkit' ), $name( 'role' ) ),
				);
		}

		$parts = array();

		if ( ! empty( $result['add'] ) ) {
			/* translators: 1: role name, 2: number of users. */
			$parts[] = sprintf( _n( 'Added %1$s to %2$s user.', 'Added %1$s to %2$s users.', (int) $result['added'], 'blt-toolkit' ), $name( 'add' ), number_format_i18n( (int) $result['added'] ) );
		}

		if ( ! empty( $result['remove'] ) ) {
			/* translators: 1: role name, 2: number of users. */
			$parts[] = sprintf( _n( 'Removed %1$s from %2$s user.', 'Removed %1$s from %2$s users.', (int) $result['removed'], 'blt-toolkit' ), $name( 'remove' ), number_format_i18n( (int) $result['removed'] ) );
		}

		if ( ! empty( $result['unchanged'] ) ) {
			/* translators: %s: number of users. */
			$parts[] = sprintf( _n( '%s user already had what you asked for.', '%s users already had what you asked for.', (int) $result['unchanged'], 'blt-toolkit' ), number_format_i18n( (int) $result['unchanged'] ) );
		}

		if ( ! empty( $result['skipped'] ) ) {
			/* translators: %s: number of users. */
			$parts[] = sprintf( _n( '%s user was skipped: you cannot change your own roles or the roles of someone with more access than you, and a change cannot leave anyone with more access than you.', '%s users were skipped: you cannot change your own roles or the roles of someone with more access than you, and a change cannot leave anyone with more access than you.', (int) $result['skipped'], 'blt-toolkit' ), number_format_i18n( (int) $result['skipped'] ) );
		}

		return array(
			'type' => empty( $result['skipped'] ) ? 'success' : 'warning',
			'text' => implode( ' ', $parts ),
		);
	}

	/**
	 * Give a user roles they do not hold, through WP_User::add_role(), so the
	 * `add_user_role` action fires for each.
	 *
	 * @param WP_User  $user  User.
	 * @param string[] $roles Role IDs.
	 * @return string[] The roles added.
	 */
	private static function add_roles( WP_User $user, array $roles ) {
		$known = self::role_names();
		$added = array();

		self::$applying = true;

		foreach ( $roles as $role ) {
			$role = (string) $role;

			if ( isset( $known[ $role ] ) && ! in_array( $role, (array) $user->roles, true ) ) {
				$user->add_role( $role );
				$added[] = $role;
			}
		}

		self::$applying = false;

		return $added;
	}

	/**
	 * Make a user hold exactly these roles, in this order: adds first, so
	 * the user never passes through having none, then removals, then, when
	 * core's set_role() and add_role() left them in another order, one write
	 * of the capabilities meta that puts them in order (a later role's
	 * `false` overrides an earlier `true`, SEC-3).
	 *
	 * @param WP_User  $user    User.
	 * @param string[] $desired Role IDs.
	 * @return void
	 */
	private static function set_roles( WP_User $user, array $desired ) {
		$now = array_values( array_map( 'strval', (array) $user->roles ) );

		self::add_roles( $user, array_values( array_diff( $desired, $now ) ) );

		self::$applying = true;

		foreach ( array_diff( $now, $desired ) as $role ) {
			$user->remove_role( (string) $role );
		}

		self::$applying = false;

		$held  = array_values( array_map( 'strval', (array) $user->roles ) );
		$order = array_values( array_intersect( array_map( 'strval', $desired ), $held ) );

		if ( $order === $held || ! is_array( $user->caps ) ) {
			return;
		}

		$caps = self::caps_in_role_order( $user->caps, $order );

		update_user_meta( $user->ID, $user->cap_key, $caps );

		$user->caps = $caps;
		$user->get_role_caps();
		$user->update_user_level_from_caps();
	}

	/**
	 * Role names, comma separated.
	 *
	 * @param string[] $roles Role IDs.
	 * @return string
	 */
	private static function names_text( array $roles ) {
		$names = self::role_names();
		$out   = array();

		foreach ( $roles as $role ) {
			$out[] = isset( $names[ $role ] ) ? $names[ $role ] : (string) $role;
		}

		return implode( ', ', $out );
	}

	/**
	 * Log a change of one user's roles (LOG-1), when anything changed.
	 *
	 * @param WP_User  $user   The user.
	 * @param string[] $before Roles before.
	 * @param string[] $after  Roles after.
	 * @param string   $source `profile`, `new_user_form` or `extra_default_roles`.
	 * @return void
	 */
	private static function log_user_change( WP_User $user, array $before, array $after, $source ) {
		$added   = array_values( array_diff( $after, $before ) );
		$removed = array_values( array_diff( $before, $after ) );

		if ( array() === $added && array() === $removed ) {
			return;
		}

		if ( 'extra_default_roles' === $source ) {
			$summary = sprintf(
				/* translators: 1: username, 2: role names. */
				__( 'Gave the new user %1$s the additional default roles %2$s.', 'blt-toolkit' ),
				(string) $user->user_login,
				self::names_text( $added )
			);
		} elseif ( 'new_user_form' === $source ) {
			$summary = sprintf(
				/* translators: 1: username, 2: role names. */
				__( 'Gave the new user %1$s the additional roles %2$s.', 'blt-toolkit' ),
				(string) $user->user_login,
				self::names_text( $added )
			);
		} else {
			$parts = array();

			if ( array() !== $added ) {
				/* translators: %s: role names. */
				$parts[] = sprintf( __( 'added %s', 'blt-toolkit' ), self::names_text( $added ) );
			}

			if ( array() !== $removed ) {
				/* translators: %s: role names. */
				$parts[] = sprintf( __( 'removed %s', 'blt-toolkit' ), self::names_text( $removed ) );
			}

			$summary = sprintf(
				/* translators: 1: username, 2: what changed, such as "added Editor; removed Author". */
				__( 'Changed the roles of %1$s: %2$s.', 'blt-toolkit' ),
				(string) $user->user_login,
				implode( '; ', $parts )
			);
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => self::LOG_ACTION,
				'object_type' => 'user',
				'object_id'   => (string) (int) $user->ID,
				'summary'     => $summary,
				'details'     => array(
					'before'  => $before,
					'after'   => $after,
					'added'   => $added,
					'removed' => $removed,
					'source'  => (string) $source,
				),
			)
		);
	}

	/**
	 * Log a bulk Apply (US-3): one row for the whole batch.
	 *
	 * @param string $add    Role added, or ''.
	 * @param string $remove Role removed, or ''.
	 * @param array  $result `added`, `removed`, `unchanged` (logins) and `skipped` (login => rule).
	 * @return void
	 */
	private static function log_bulk( $add, $remove, array $result ) {
		if ( array() === $result['added'] && array() === $result['removed'] && array() === $result['skipped'] ) {
			return;
		}

		$parts = array();

		if ( '' !== $add ) {
			/* translators: 1: role name, 2: number of users. */
			$parts[] = sprintf( _n( 'added %1$s to %2$s user', 'added %1$s to %2$s users', count( $result['added'] ), 'blt-toolkit' ), self::names_text( array( $add ) ), number_format_i18n( count( $result['added'] ) ) );
		}

		if ( '' !== $remove ) {
			/* translators: 1: role name, 2: number of users. */
			$parts[] = sprintf( _n( 'removed %1$s from %2$s user', 'removed %1$s from %2$s users', count( $result['removed'] ), 'blt-toolkit' ), self::names_text( array( $remove ) ), number_format_i18n( count( $result['removed'] ) ) );
		}

		if ( array() !== $result['skipped'] ) {
			/* translators: %s: number of users. */
			$parts[] = sprintf( _n( 'skipped %s user', 'skipped %s users', count( $result['skipped'] ), 'blt-toolkit' ), number_format_i18n( count( $result['skipped'] ) ) );
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => self::LOG_ACTION,
				'object_type' => 'users',
				'object_id'   => '',
				'summary'     => sprintf(
					/* translators: %s: what changed, such as "added Editor to 3 users". */
					__( 'Bulk role change on the Users screen: %s.', 'blt-toolkit' ),
					implode( '; ', $parts )
				),
				'details'     => array(
					'add'       => $add,
					'remove'    => $remove,
					'added'     => $result['added'],
					'removed'   => $result['removed'],
					'unchanged' => $result['unchanged'],
					'skipped'   => $result['skipped'],
				),
				'status'      => array() === $result['skipped'] ? 'success' : 'warning',
			)
		);
	}

	/**
	 * `removable_query_args` callback: the result flags leave the address bar.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function removable_query_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$args[] = self::BULK_RESULT_ARG;
		$args[] = self::COUNT_RESULT_ARG;

		return $args;
	}
}
