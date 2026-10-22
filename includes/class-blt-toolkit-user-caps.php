<?php
/**
 * Per-user capabilities (US-10) and the profile's Capabilities row (US-11).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The engine behind the Roles screen's user mode (US-10) and the profile
 * link that replaces core's "Additional Capabilities" row (US-11).
 *
 * A user's own capabilities live in WordPress's `{prefix}capabilities` user
 * meta, in the same array as the user's role memberships (SPEC 14.4): every
 * key that names a role is a role, whatever its value; every other key is a
 * capability given (`true`) or denied (`false`) to this user alone. Each
 * capability is in one of three states:
 *
 * - `inherit`: no key of its own; the user has what their roles give, merged
 *   in the order of the meta as `WP_User::get_role_caps()` does (a later
 *   role's value wins);
 * - `grant`: a `true` key of its own;
 * - `deny`: a `false` key of its own, which beats every role.
 *
 * save() is the one write path. It re-reads the meta inside the save,
 * refuses a save whose user changed since the form was drawn (the RO-2 lock,
 * by user_hash()), changes only capabilities that were on the page (the RO-1
 * rule), never touches a role key, asks the Guard
 * (BLT_Toolkit_Guard::can_change_user_caps(): SEC-1, SEC-2, SEC-3, SEC-5),
 * requires the SEC-10 confirmation for a Critical grant, never lets a
 * lockout-critical capability (SEC-12) be denied to a toolkit admin, writes
 * the meta once with the role keys in place, then runs
 * `update_user_level_from_caps()`, and logs the change (LOG-1). The roles
 * option is not written, so no snapshot is taken (SNAP-1 covers roles only).
 *
 * compute_state(), compute_changes(), apply_to_meta(), own_caps() and
 * caps_hash() are pure and unit-tested in tests/test-user-caps.php.
 *
 * Cost (SEC-9): boot() adds two profile hooks and one filter on admin
 * requests; nothing runs on the Users list or on a page view.
 */
class BLT_Toolkit_User_Caps {

	/**
	 * The three states of a capability for one user (US-10).
	 */
	const INHERIT = 'inherit';
	const GRANT   = 'grant';
	const DENY    = 'deny';

	/**
	 * Longest capability name a save accepts.
	 */
	const NAME_MAX = 191;

	/**
	 * Slug of the Roles screen, whose user mode edits these (US-10).
	 */
	const ROLES_PAGE = 'blt-toolkit-roles';

	/**
	 * Log action of a save (BLT_Toolkit_Admin_Activity lists it).
	 */
	const LOG_ACTION = 'user_caps_changed';

	/**
	 * Id of the profile section (US-11).
	 */
	const ANCHOR = 'blt-tk-user-caps';

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

		// Core's row and the profile screen exist in wp-admin only.
		if ( ! is_admin() ) {
			return;
		}

		// US-11: core's read-only "Additional Capabilities" row gives way to
		// the section below, which names denies the same way and links to the
		// editor (US-10).
		add_filter( 'additional_capabilities_display', array( __CLASS__, 'hide_core_row' ), 10, 2 );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ), 30 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ), 30 );
	}

	// ---------- US-11: the profile ----------

	/**
	 * `additional_capabilities_display` callback: hide core's row (US-11).
	 *
	 * @param bool         $display Whether core shows the row.
	 * @param WP_User|null $user    The user being edited.
	 * @return bool
	 */
	public static function hide_core_row( $display, $user = null ) {
		unset( $display, $user );

		return false;
	}

	/**
	 * The "Capabilities" section on the profile screen (US-10, US-11).
	 *
	 * Lists the user's own grants and denies, as core's row did, and links to
	 * the Roles screen in user mode for someone who may edit them
	 * (can_edit()). Prints nothing when the user has none of their own and
	 * the viewer may not edit them.
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public static function render_profile_section( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return;
		}

		$own  = self::own_caps( is_array( $user->caps ) ? $user->caps : array(), self::role_ids() );
		$link = self::can_edit( wp_get_current_user(), $user ) ? self::edit_url( (int) $user->ID ) : '';

		if ( array() === $own && '' === $link ) {
			return;
		}

		ksort( $own, SORT_STRING );
		?>
		<div id="<?php echo esc_attr( self::ANCHOR ); ?>" class="blt-tk-user-caps">
			<h2><?php esc_html_e( 'Capabilities', 'blt-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr class="user-capabilities-wrap blt-tk-user-caps-row">
						<th scope="row"><?php esc_html_e( 'Own capabilities', 'blt-toolkit' ); ?></th>
						<td>
							<?php if ( array() === $own ) : ?>
								<p><?php esc_html_e( 'None. This user has exactly what their roles give.', 'blt-toolkit' ); ?></p>
							<?php else : ?>
								<ul class="blt-tk-user-caps-list">
									<?php foreach ( $own as $cap => $granted ) : ?>
										<li>
											<?php
											if ( $granted ) {
												printf(
													/* translators: %s: capability name, such as edit_posts. */
													esc_html__( 'Granted: %s', 'blt-toolkit' ),
													'<code>' . esc_html( (string) $cap ) . '</code>'
												);
											} else {
												printf(
													/* translators: %s: capability name, such as edit_posts. */
													esc_html__( 'Denied: %s', 'blt-toolkit' ),
													'<code>' . esc_html( (string) $cap ) . '</code>'
												);
											}
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( '' !== $link ) : ?>
								<p><a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Edit capabilities', 'blt-toolkit' ); ?></a></p>
								<p class="description"><?php esc_html_e( 'Opens the Roles screen for this user: grant or deny a capability for them alone, or let their roles decide.', 'blt-toolkit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * The Roles screen in user mode for a user (US-10).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function edit_url( $user_id ) {
		return add_query_arg(
			array(
				'page'    => self::ROLES_PAGE,
				'user_id' => (int) $user_id,
				'view'    => 'advanced',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Whether the actor may open the per-user capability editor for a user:
	 * `blt_edit_capabilities` (US-10, SPEC 5.1) and `blt_edit_roles`, which
	 * the Roles screen that holds the editor needs (SPEC 13.1), plus
	 * `promote_user` on the target, the check core makes before changing a
	 * user's role, which the Guard extends with SEC-1, SEC-2 and SEC-5.
	 * save() checks these again, with the Guard's rules on the actual
	 * change.
	 *
	 * @param WP_User $actor  Who is looking.
	 * @param WP_User $target Whose capabilities.
	 * @return bool
	 */
	public static function can_edit( WP_User $actor, WP_User $target ) {
		if ( ! $actor->exists() || ! $target->exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capabilities (SPEC 5.1).
		if ( ! user_can( $actor, 'blt_edit_capabilities' ) || ! user_can( $actor, 'blt_edit_roles' ) ) {
			return false;
		}

		return user_can( $actor, 'promote_user', (int) $target->ID );
	}

	// ---------- US-10: reading ----------

	/**
	 * Each capability's state for a user (US-10): `state` (`inherit`,
	 * `grant` or `deny`) and `source` (from role X, granted to this user,
	 * denied for this user), plus `effective` (whether the user has it),
	 * `inherited` (what the roles give: true, false or null) and `roles` (the
	 * role IDs that grant it).
	 *
	 * Covers every capability the user's roles or own meta mention, plus
	 * `$caps` (rows the caller will draw), so a capability nobody mentions
	 * reads as `inherit`, not held. Reads only; writes nothing (RO-5).
	 *
	 * @param WP_User $user User.
	 * @param array   $caps Optional extra capability names to include.
	 * @return array<string,array{state:string,source:string,effective:bool,inherited:bool|null,roles:string[]}>
	 */
	public static function state( WP_User $user, array $caps = array() ) {
		$wp_roles   = wp_roles();
		$roles_caps = array();

		foreach ( (array) $user->roles as $role ) {
			$object = $wp_roles->get_role( (string) $role );

			$roles_caps[ (string) $role ] = $object instanceof WP_Role ? (array) $object->capabilities : array();
		}

		$names = array();

		foreach ( (array) $wp_roles->role_names as $id => $name ) {
			$names[ (string) $id ] = translate_user_role( (string) $name );
		}

		$own = self::own_caps( is_array( $user->caps ) ? $user->caps : array(), array_keys( $names ) );

		return self::compute_state( $roles_caps, $own, $names, $caps );
	}

	/**
	 * The lock token for a user's form (the RO-2 rule for US-10): SHA-256 of
	 * the user's whole capabilities meta, role keys included, canonicalized,
	 * each value read as a boolean.
	 *
	 * @param WP_User $user User.
	 * @return string 64 hex characters.
	 */
	public static function user_hash( WP_User $user ) {
		return self::caps_hash( is_array( $user->caps ) ? $user->caps : array() );
	}

	// ---------- US-10: the write path ----------

	/**
	 * Save a user's own capabilities from a form (US-10).
	 *
	 * `$states` maps capability => `inherit`, `grant` or `deny`. Only names
	 * in `$on_page` are considered (a name missing from `$states` is left as
	 * it is); a capability outside `$on_page` and every role key are never
	 * touched. The meta is written once, then the user's level is updated.
	 *
	 * @param WP_User $user          The user whose capabilities change.
	 * @param array   $on_page       Capability names drawn on the form.
	 * @param array   $states        Capability => state, as submitted.
	 * @param string  $expected_hash user_hash() from when the form was drawn.
	 * @param array   $args {
	 *     Optional.
	 *
	 *     @type bool    $confirmed_critical The person confirmed the SEC-10 review.
	 *     @type bool    $dry_run            Return the change set and the verdict; write nothing.
	 *     @type string  $context            Where the save came from (default `advanced`), for the log.
	 *     @type WP_User $actor              Who is saving (default: the current user).
	 * }
	 * @return array|WP_Error On success: `changes` (`add`: capability => bool,
	 *                        `remove`: names back to inherit), `critical`
	 *                        (Critical capabilities granted), `before` and
	 *                        `after` (the user's own capabilities),
	 *                        `hash` (the new lock token), `log_id`, and
	 *                        `dry_run` when nothing was written. Errors:
	 *                        `blt_toolkit_no_user`, `blt_toolkit_forbidden`,
	 *                        `blt_toolkit_user_changed` (409, data `current`),
	 *                        `blt_toolkit_role_name_cap`, a Guard refusal,
	 *                        `blt_toolkit_lockout_critical`,
	 *                        `blt_toolkit_critical_review` (data `caps`,
	 *                        `confirmable`), `blt_toolkit_user_caps_not_saved`.
	 */
	public static function save( WP_User $user, array $on_page, array $states, $expected_hash, array $args = array() ) {
		$args  = array_merge(
			array(
				'confirmed_critical' => false,
				'dry_run'            => false,
				'context'            => 'advanced',
				'actor'              => null,
			),
			$args
		);
		$actor = $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user();

		if ( ! $user->exists() ) {
			return self::error( 'blt_toolkit_no_user', __( 'That user does not exist.', 'blt-toolkit' ), 404 );
		}

		if ( ! self::can_edit( $actor, $user ) ) {
			return self::error( 'blt_toolkit_forbidden', __( 'You are not allowed to change this user\'s capabilities.', 'blt-toolkit' ), 403, array( 'rule' => 'US-10' ) );
		}

		// RO-3's rule for users: read the meta again inside the save, never
		// from an object loaded earlier in the request.
		$fresh    = self::fresh_user( (int) $user->ID );
		$meta     = is_array( $fresh->caps ) ? $fresh->caps : array();
		$role_ids = self::role_ids();
		$before   = self::own_caps( $meta, $role_ids );

		if ( ! hash_equals( self::caps_hash( $meta ), (string) $expected_hash ) ) {
			return self::error(
				'blt_toolkit_user_changed',
				__( 'This user\'s roles or capabilities changed since you opened them. Nothing was saved. Look at them again, then make your change.', 'blt-toolkit' ),
				409,
				array(
					'current' => array(
						'roles'        => array_values( array_map( 'strval', (array) $fresh->roles ) ),
						'capabilities' => $before,
					),
				)
			);
		}

		$computed = self::compute_changes( $before, $on_page, $states, $role_ids );

		if ( array() !== $computed['invalid'] ) {
			return self::error(
				'blt_toolkit_role_name_cap',
				__( 'A role cannot be granted or denied as a capability. Change the user\'s roles on their profile instead.', 'blt-toolkit' ),
				400,
				array(
					'rule'        => 'SEC-3',
					'caps'        => $computed['invalid'],
					'confirmable' => false,
				)
			);
		}

		$changes = array(
			'add'    => $computed['add'],
			'remove' => $computed['remove'],
		);
		$after   = self::own_caps( self::apply_to_meta( $meta, $changes, $role_ids ), $role_ids );

		if ( array() === $changes['add'] && array() === $changes['remove'] ) {
			return array(
				'changes'  => $changes,
				'critical' => array(),
				'before'   => $before,
				'after'    => $before,
				'hash'     => self::caps_hash( $meta ),
				'log_id'   => 0,
				'dry_run'  => (bool) $args['dry_run'],
			);
		}

		// SEC-1, SEC-2, SEC-3 (lifting a deny counts as a grant) and SEC-5.
		$allowed = BLT_Toolkit_Guard::can_change_user_caps( $actor, $fresh, $changes['add'], $changes['remove'] );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// SEC-12 for people: a per-user deny beats the administrator role, and
		// SEC-14 recovery repairs only that role, so a toolkit admin is never
		// denied a lockout-critical capability of their own.
		$lockout = self::lockout_denies( $changes, BLT_Toolkit_Guard::is_toolkit_admin( $fresh ), BLT_Toolkit_Caps::lockout_critical() );

		if ( array() !== $lockout ) {
			return self::error(
				'blt_toolkit_lockout_critical',
				__( 'An administrator cannot be denied a capability they need to manage the site and its roles. Nothing was saved.', 'blt-toolkit' ),
				409,
				array(
					'rule'        => 'SEC-12',
					'caps'        => $lockout,
					'confirmable' => false,
				)
			);
		}

		// SEC-10: a Critical capability granted to a user, including one a
		// role grants whose deny is lifted, needs the review step.
		$state    = self::state( $fresh, array_keys( $changes['add'] ) );
		$critical = BLT_Toolkit_Guard::requires_critical_review( self::granting( $changes, $before, $state ) );

		if ( array() !== $critical && ! $args['confirmed_critical'] ) {
			return self::error(
				'blt_toolkit_critical_review',
				__( 'This gives the user a Critical capability: one that can take over the site or its users. Review it and confirm to save.', 'blt-toolkit' ),
				409,
				array(
					'rule'        => 'SEC-10',
					'caps'        => $critical,
					'confirmable' => true,
				)
			);
		}

		if ( $args['dry_run'] ) {
			return array(
				'changes'  => $changes,
				'critical' => $critical,
				'before'   => $before,
				'after'    => $after,
				'hash'     => self::caps_hash( $meta ),
				'log_id'   => 0,
				'dry_run'  => true,
			);
		}

		$new_meta = self::apply_to_meta( $meta, $changes, $role_ids );

		// One write of the meta, role keys in place (US-10).
		if ( false === update_user_meta( (int) $fresh->ID, $fresh->cap_key, $new_meta ) ) {
			$check = self::fresh_user( (int) $fresh->ID );

			if ( self::caps_hash( is_array( $check->caps ) ? $check->caps : array() ) !== self::caps_hash( $new_meta ) ) {
				return self::error( 'blt_toolkit_user_caps_not_saved', __( 'The capabilities could not be saved. Try again.', 'blt-toolkit' ), 500 );
			}
		}

		// Rebuild the user's capabilities from the new meta, then their level,
		// as WP_User::add_cap() and remove_cap() do.
		$fresh->for_site();
		$fresh->update_user_level_from_caps();

		if ( $user !== $fresh ) {
			$user->for_site();
		}

		$current = wp_get_current_user();

		if ( (int) $current->ID === (int) $fresh->ID && $current !== $user ) {
			$current->for_site();
		}

		$log_id = self::log( $fresh, $changes, $before, $after, (string) $args['context'] );

		return array(
			'changes'  => $changes,
			'critical' => $critical,
			'before'   => $before,
			'after'    => $after,
			'hash'     => self::caps_hash( $new_meta ),
			'log_id'   => (int) $log_id,
		);
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * The keys of a capabilities meta that are capabilities, not roles, as
	 * capability => bool, in their stored order. Pure.
	 *
	 * @param array $meta     The `{prefix}capabilities` meta.
	 * @param array $role_ids The site's role IDs.
	 * @return array<string,bool>
	 */
	public static function own_caps( array $meta, array $role_ids ) {
		$roles = array_flip( array_map( 'strval', $role_ids ) );
		$out   = array();

		foreach ( $meta as $cap => $value ) {
			$cap = (string) $cap;

			if ( '' === $cap || isset( $roles[ $cap ] ) ) {
				continue;
			}

			$out[ $cap ] = (bool) $value;
		}

		return $out;
	}

	/**
	 * Each capability's state from plain facts (see state()). Pure.
	 *
	 * Roles merge in the order given, a later role's value winning, as
	 * `WP_User::get_role_caps()` merges them; the user's own value beats them
	 * all. `do_not_allow` and keys naming a role are left out.
	 *
	 * @param array $roles_caps Role ID => capabilities, in the user's role order.
	 * @param array $own        The user's own capabilities (capability => bool).
	 * @param array $role_names Every role on the site: ID => display name.
	 * @param array $extra      More capability names to include.
	 * @return array<string,array{state:string,source:string,effective:bool,inherited:bool|null,roles:string[]}> Sorted by name.
	 */
	public static function compute_state( array $roles_caps, array $own, array $role_names, array $extra = array() ) {
		$universe = array();

		foreach ( $roles_caps as $caps ) {
			foreach ( (array) $caps as $cap => $value ) {
				$universe[ (string) $cap ] = true;
			}
		}

		foreach ( $own as $cap => $value ) {
			$universe[ (string) $cap ] = true;
		}

		foreach ( $extra as $cap ) {
			if ( is_string( $cap ) || is_int( $cap ) ) {
				$universe[ (string) $cap ] = true;
			}
		}

		$out = array();

		foreach ( array_keys( $universe ) as $cap ) {
			$cap = (string) $cap;

			if ( '' === $cap || 'do_not_allow' === $cap || array_key_exists( $cap, $role_names ) ) {
				continue;
			}

			$inherited = null;
			$granting  = array();
			$denying   = array();

			foreach ( $roles_caps as $role => $caps ) {
				if ( ! is_array( $caps ) || ! array_key_exists( $cap, $caps ) ) {
					continue;
				}

				$inherited = (bool) $caps[ $cap ];

				if ( $inherited ) {
					$granting[] = (string) $role;
				} else {
					$denying[] = (string) $role;
				}
			}

			if ( array_key_exists( $cap, $own ) ) {
				$state     = $own[ $cap ] ? self::GRANT : self::DENY;
				$effective = (bool) $own[ $cap ];
				$source    = $own[ $cap ] ? __( 'Granted to this user', 'blt-toolkit' ) : __( 'Denied for this user', 'blt-toolkit' );
			} else {
				$state     = self::INHERIT;
				$effective = true === $inherited;

				if ( true === $inherited ) {
					$source = sprintf(
						/* translators: %s: role names, comma separated, such as Editor, Author. */
						_n( 'From role %s', 'From roles %s', count( $granting ), 'blt-toolkit' ),
						self::names( $granting, $role_names )
					);
				} elseif ( false === $inherited ) {
					$source = sprintf(
						/* translators: %s: role names, comma separated, such as Editor, Author. */
						_n( 'Denied by role %s', 'Denied by roles %s', count( $denying ), 'blt-toolkit' ),
						self::names( $denying, $role_names )
					);
				} else {
					$source = __( 'Not granted', 'blt-toolkit' );
				}
			}

			$out[ $cap ] = array(
				'state'     => $state,
				'source'    => $source,
				'effective' => $effective,
				'inherited' => $inherited,
				'roles'     => $granting,
			);
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * The change a submitted form asks for, within the on-page set. Pure.
	 *
	 * - `grant` sets the user's own key to `true` unless it already is;
	 *   `deny` sets it to `false` unless it already is; `inherit` removes the
	 *   key when there is one.
	 * - A capability outside `$on_page`, and an on-page capability without a
	 *   valid submitted state, is left exactly as it is.
	 * - A name that is a role is never a capability: it is reported in
	 *   `invalid` and the caller refuses the save.
	 *
	 * @param array $own      The user's own capabilities (capability => bool), from own_caps().
	 * @param array $on_page  Capability names drawn on the form.
	 * @param array $states   Capability => `inherit`, `grant` or `deny`.
	 * @param array $role_ids The site's role IDs.
	 * @return array{add:array<string,bool>,remove:string[],invalid:string[]}
	 */
	public static function compute_changes( array $own, array $on_page, array $states, array $role_ids ) {
		$roles   = array_flip( array_map( 'strval', $role_ids ) );
		$add     = array();
		$remove  = array();
		$invalid = array();

		foreach ( self::clean_names( $on_page ) as $cap ) {
			if ( ! isset( $states[ $cap ] ) || ! is_string( $states[ $cap ] ) ) {
				continue;
			}

			$want = $states[ $cap ];

			if ( ! in_array( $want, array( self::INHERIT, self::GRANT, self::DENY ), true ) ) {
				continue;
			}

			$current = array_key_exists( $cap, $own ) ? (bool) $own[ $cap ] : null;

			if ( isset( $roles[ $cap ] ) ) {
				// Only a real change counts: drawing a role-named row and
				// leaving it alone is not an attempt.
				if ( ( self::GRANT === $want && true !== $current ) || ( self::DENY === $want && false !== $current ) || ( self::INHERIT === $want && null !== $current ) ) {
					$invalid[] = $cap;
				}

				continue;
			}

			if ( self::GRANT === $want && true !== $current ) {
				$add[ $cap ] = true;
			} elseif ( self::DENY === $want && false !== $current ) {
				$add[ $cap ] = false;
			} elseif ( self::INHERIT === $want && null !== $current ) {
				$remove[] = $cap;
			}
		}

		ksort( $add, SORT_STRING );
		sort( $remove, SORT_STRING );
		sort( $invalid, SORT_STRING );

		return array(
			'add'     => $add,
			'remove'  => $remove,
			'invalid' => $invalid,
		);
	}

	/**
	 * A capabilities meta after a change, with every role key kept where it
	 * was. Pure.
	 *
	 * A capability that is set keeps its position; a new one goes at the end;
	 * a removed one goes. A key naming a role is never set or removed here,
	 * whatever the change says.
	 *
	 * @param array $meta     The `{prefix}capabilities` meta.
	 * @param array $changes  `add` (capability => bool) and `remove` (names).
	 * @param array $role_ids The site's role IDs.
	 * @return array
	 */
	public static function apply_to_meta( array $meta, array $changes, array $role_ids ) {
		$roles = array_flip( array_map( 'strval', $role_ids ) );

		foreach ( isset( $changes['remove'] ) ? (array) $changes['remove'] : array() as $cap ) {
			$cap = (string) $cap;

			if ( ! isset( $roles[ $cap ] ) ) {
				unset( $meta[ $cap ] );
			}
		}

		foreach ( isset( $changes['add'] ) ? (array) $changes['add'] : array() as $cap => $value ) {
			$cap = (string) $cap;

			if ( '' !== $cap && ! isset( $roles[ $cap ] ) ) {
				$meta[ $cap ] = (bool) $value;
			}
		}

		return $meta;
	}

	/**
	 * SHA-256 of a capabilities meta's canonical form, values as booleans,
	 * so key order and `1` versus `true` do not matter. Pure.
	 *
	 * @param array $meta Capability or role => value.
	 * @return string
	 */
	public static function caps_hash( array $meta ) {
		$normalized = array();

		foreach ( $meta as $key => $value ) {
			$normalized[ (string) $key ] = (bool) $value;
		}

		return hash( 'sha256', BLT_Toolkit_Role_Diff::canonicalize( $normalized ) );
	}

	/**
	 * Lockout-critical capabilities a change would deny to a toolkit admin
	 * (SEC-12 applied to a person). Pure.
	 *
	 * @param array $changes  `add` (capability => bool) and `remove`.
	 * @param bool  $is_admin Whether the user is a toolkit admin.
	 * @param array $lockout  The lockout-critical set.
	 * @return string[] Sorted.
	 */
	public static function lockout_denies( array $changes, $is_admin, array $lockout ) {
		if ( ! $is_admin ) {
			return array();
		}

		$out = array();

		foreach ( isset( $changes['add'] ) ? (array) $changes['add'] : array() as $cap => $value ) {
			if ( ! $value && in_array( (string) $cap, $lockout, true ) ) {
				$out[] = (string) $cap;
			}
		}

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * What a change gives the user, for SEC-10: each capability set to
	 * `true`, and each deny lifted where a role grants the capability. Pure.
	 *
	 * @param array $changes `add` and `remove`.
	 * @param array $before  The user's own capabilities before the change.
	 * @param array $state   compute_state() for the user before the change.
	 * @return string[]
	 */
	public static function granting( array $changes, array $before, array $state ) {
		$out = array();

		foreach ( isset( $changes['add'] ) ? (array) $changes['add'] : array() as $cap => $value ) {
			if ( $value ) {
				$out[] = (string) $cap;
			}
		}

		foreach ( isset( $changes['remove'] ) ? (array) $changes['remove'] : array() as $cap ) {
			$cap = (string) $cap;

			if ( array_key_exists( $cap, $before ) && false === $before[ $cap ] && isset( $state[ $cap ] ) && true === $state[ $cap ]['inherited'] ) {
				$out[] = $cap;
			}
		}

		sort( $out, SORT_STRING );

		return array_values( array_unique( $out ) );
	}

	/**
	 * Capability names as submitted, cleaned: strings, trimmed, not empty,
	 * at most NAME_MAX characters, never `do_not_allow`, each once. Pure.
	 *
	 * @param array $names Raw names.
	 * @return string[]
	 */
	public static function clean_names( array $names ) {
		$out = array();

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) && ! is_int( $name ) ) {
				continue;
			}

			$name = trim( (string) $name );

			if ( '' === $name || 'do_not_allow' === $name || strlen( $name ) > self::NAME_MAX ) {
				continue;
			}

			$out[ $name ] = true;
		}

		// array_keys() turns a numeric name back into an integer.
		return array_map( 'strval', array_keys( $out ) );
	}

	// ---------- Internals ----------

	/**
	 * The site's role IDs.
	 *
	 * @return string[]
	 */
	private static function role_ids() {
		return array_map( 'strval', array_keys( (array) wp_roles()->role_names ) );
	}

	/**
	 * Role names, comma separated, for a source line.
	 *
	 * @param string[] $roles      Role IDs.
	 * @param array    $role_names ID => display name.
	 * @return string
	 */
	private static function names( array $roles, array $role_names ) {
		$out = array();

		foreach ( $roles as $role ) {
			$out[] = isset( $role_names[ $role ] ) && '' !== (string) $role_names[ $role ] ? (string) $role_names[ $role ] : (string) $role;
		}

		return implode( ', ', $out );
	}

	/**
	 * A user object built from the database now, not from an object loaded
	 * earlier in the request.
	 *
	 * @param int $user_id User ID.
	 * @return WP_User
	 */
	private static function fresh_user( $user_id ) {
		wp_cache_delete( (int) $user_id, 'user_meta' );

		return new WP_User( (int) $user_id );
	}

	/**
	 * A WP_Error in the Guard's shape: data with `status`, `rule`, `caps`
	 * and `confirmable` where they apply.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message for a person.
	 * @param int    $status  HTTP status.
	 * @param array  $data    More data.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status, array $data = array() ) {
		return new WP_Error( $code, $message, array_merge( array( 'status' => (int) $status ), $data ) );
	}

	/**
	 * Log a save (LOG-1): the user's own capabilities before and after, and
	 * what was granted, denied and given back to the roles.
	 *
	 * @param WP_User $user    The user.
	 * @param array   $changes `add` and `remove`.
	 * @param array   $before  Own capabilities before.
	 * @param array   $after   Own capabilities after.
	 * @param string  $context Where the save came from.
	 * @return int|false Log row ID.
	 */
	private static function log( WP_User $user, array $changes, array $before, array $after, $context ) {
		$granted = array();
		$denied  = array();

		foreach ( $changes['add'] as $cap => $value ) {
			if ( $value ) {
				$granted[] = (string) $cap;
			} else {
				$denied[] = (string) $cap;
			}
		}

		$parts = array();

		if ( array() !== $granted ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s granted', '%s granted', count( $granted ), 'blt-toolkit' ), number_format_i18n( count( $granted ) ) );
		}

		if ( array() !== $denied ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s denied', '%s denied', count( $denied ), 'blt-toolkit' ), number_format_i18n( count( $denied ) ) );
		}

		if ( array() !== $changes['remove'] ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s back to what the roles give', '%s back to what the roles give', count( $changes['remove'] ), 'blt-toolkit' ), number_format_i18n( count( $changes['remove'] ) ) );
		}

		return BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => self::LOG_ACTION,
				'object_type' => 'user',
				'object_id'   => (string) (int) $user->ID,
				'summary'     => sprintf(
					/* translators: 1: username, 2: what changed, such as "2 granted, 1 denied". */
					__( 'Changed the capabilities of %1$s: %2$s.', 'blt-toolkit' ),
					(string) $user->user_login,
					implode( ', ', $parts )
				),
				'details'     => array(
					'changes' => array(
						'granted'   => $granted,
						'denied'    => $denied,
						'inherited' => array_values( $changes['remove'] ),
					),
					'before'  => $before,
					'after'   => $after,
					'context' => (string) $context,
				),
			)
		);
	}
}
