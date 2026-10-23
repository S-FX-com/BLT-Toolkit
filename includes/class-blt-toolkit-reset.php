<?php
/**
 * Reset roles to WordPress's defaults (RO-43).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two resets of Tools > Reset (RO-43, SPEC 19 URE-6):
 *
 * - **Built-in roles** (`builtin`, the default): Administrator, Editor,
 *   Author, Contributor and Subscriber get WordPress's default capabilities
 *   back; their display names stay; a built-in role that was deleted comes
 *   back; custom roles are not touched.
 * - **Full reset** (`full`): the roles become exactly what a fresh install
 *   creates, so every custom role disappears (its users keep the role ID in
 *   their profile, which then gives nothing).
 *
 * Both are planned first (plan(): the diff, the custom roles that disappear
 * with their user counts, the Guard's verdict, the SEC-10 list), need the
 * typed word RESET, and write once through BLT_Toolkit_Role_Store::transaction()
 * (snapshot reason `before_reset`, context `reset`, log action
 * `roles_reset`), so the `before_reset` snapshot restores the previous state.
 * In the same write `administrator` gets BLT Toolkit's capabilities and the
 * SEC-12 lockout set back, as RO-43 asks.
 *
 * WordPress's defaults come from core's own populate_roles(), run against a
 * scratch WP_Roles that never touches the database (default_roles()). RO-43
 * says the full reset "deletes the roles option and runs populate_roles()";
 * writing populate_roles()'s result in one update_option() gives the same
 * roles in a single write, which RO-3 and SNAP-1 need (see tasks/lessons.md).
 *
 * The rules that need no WordPress (target(), removed_roles(),
 * word_confirmed()) are pure and unit-tested in tests/test-reset.php.
 */
class BLT_Toolkit_Reset {

	/**
	 * Reset the built-in roles' capabilities, keep custom roles.
	 */
	const MODE_BUILTIN = 'builtin';

	/**
	 * Roles exactly as on a fresh install.
	 */
	const MODE_FULL = 'full';

	/**
	 * What must be typed to confirm either reset (RO-43).
	 */
	const WORD = 'RESET';

	/**
	 * Snapshot reason (SPEC 14.3).
	 */
	const REASON = 'before_reset';

	/**
	 * Context passed to the roles-save hooks and logged.
	 */
	const CONTEXT = 'reset';

	/**
	 * WordPress's own roles.
	 */
	const BUILTIN = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * The role SEC-12 protects.
	 */
	const ADMIN_ROLE = 'administrator';

	/**
	 * WordPress's default roles, worked out once per request.
	 *
	 * @var array|null
	 */
	private static $defaults = null;

	// ---------- Pure rules ----------

	/**
	 * A mode from a request: `full`, or the default `builtin`. Pure.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function mode( $value ) {
		return self::MODE_FULL === $value ? self::MODE_FULL : self::MODE_BUILTIN;
	}

	/**
	 * Whether the typed confirmation is RESET: exactly that word, in
	 * capitals, surrounding spaces ignored (RO-43). Pure.
	 *
	 * @param mixed $typed Posted value.
	 * @return bool
	 */
	public static function word_confirmed( $typed ) {
		return is_string( $typed ) && self::WORD === trim( $typed );
	}

	/**
	 * The roles a reset writes. Pure.
	 *
	 * - `builtin`: today's roles, in today's order, where each built-in role
	 *   present in `$defaults` gets the default capabilities and keeps its
	 *   display name; a built-in role that is missing is added as WordPress
	 *   creates it. Custom roles are left exactly as stored.
	 * - `full`: `$defaults` as they are.
	 *
	 * The roles that take WordPress's defaults (every role for `full`, the
	 * built-in ones for `builtin`) then get the strings of each content type
	 * that has separate permissions, as RS-30 seeds them from the Posts (or
	 * Pages) capabilities they now hold (BLT_Toolkit_Snapshots::
	 * seed_isolated()): WordPress's defaults know nothing of those types, so
	 * without this a reset would take their strings from every role,
	 * Administrator included, while the types keep separate permissions.
	 *
	 * Either way `administrator` then also gets BLT Toolkit's capabilities
	 * and the lockout-critical set (RO-43, SEC-12), granted.
	 *
	 * @param string   $mode     `builtin` or `full`.
	 * @param array    $current  Roles array today.
	 * @param array    $defaults Roles array WordPress creates (default_roles()).
	 * @param string[] $own      BLT Toolkit's capabilities (SPEC 5.1).
	 * @param string[] $lockout  The lockout-critical set (SEC-12).
	 * @param array    $maps     Optional: BLT_Toolkit_Snapshots::isolation_maps().
	 * @return array
	 */
	public static function target( $mode, array $current, array $defaults, array $own, array $lockout, array $maps = array() ) {
		if ( self::MODE_FULL === self::mode( $mode ) ) {
			$out   = $defaults;
			$reset = array_map( 'strval', array_keys( $defaults ) );
		} else {
			$reset = array();

			$out = $current;

			foreach ( self::BUILTIN as $role ) {
				if ( ! isset( $defaults[ $role ] ) || ! is_array( $defaults[ $role ] ) ) {
					continue;
				}

				$caps = isset( $defaults[ $role ]['capabilities'] ) && is_array( $defaults[ $role ]['capabilities'] ) ? $defaults[ $role ]['capabilities'] : array();

				$reset[] = $role;

				if ( isset( $out[ $role ] ) && is_array( $out[ $role ] ) ) {
					$name = isset( $out[ $role ]['name'] ) && is_scalar( $out[ $role ]['name'] ) && '' !== (string) $out[ $role ]['name'] ? (string) $out[ $role ]['name'] : ( isset( $defaults[ $role ]['name'] ) ? (string) $defaults[ $role ]['name'] : $role );

					$out[ $role ] = array(
						'name'         => $name,
						'capabilities' => $caps,
					);
				} else {
					$out[ $role ] = $defaults[ $role ];
				}
			}
		}

		if ( array() !== $maps ) {
			$out = BLT_Toolkit_Snapshots::seed_isolated( $out, $maps, $reset )['roles'];
		}

		$admin = self::ADMIN_ROLE;

		if ( ! isset( $out[ $admin ] ) || ! is_array( $out[ $admin ] ) ) {
			$out[ $admin ] = array(
				'name'         => 'Administrator',
				'capabilities' => array(),
			);
		}

		$caps = isset( $out[ $admin ]['capabilities'] ) && is_array( $out[ $admin ]['capabilities'] ) ? $out[ $admin ]['capabilities'] : array();

		foreach ( array_merge( $own, $lockout ) as $cap ) {
			$caps[ (string) $cap ] = true;
		}

		$out[ $admin ]['capabilities'] = $caps;

		return $out;
	}

	/**
	 * The roles of `$current` that `$target` no longer has, ID => stored
	 * display name. Pure.
	 *
	 * @param array $current Roles array today.
	 * @param array $target  Roles array to write.
	 * @return array<string,string>
	 */
	public static function removed_roles( array $current, array $target ) {
		$out = array();

		foreach ( $current as $id => $role ) {
			if ( array_key_exists( $id, $target ) ) {
				continue;
			}

			$out[ (string) $id ] = is_array( $role ) && isset( $role['name'] ) && is_scalar( $role['name'] ) && '' !== (string) $role['name'] ? (string) $role['name'] : (string) $id;
		}

		return $out;
	}

	// ---------- WordPress's defaults ----------

	/**
	 * The roles WordPress creates on a fresh install: core's populate_roles(),
	 * run against a scratch WP_Roles with `use_db` off, so nothing is read
	 * from or written to the database and the site's own roles object is not
	 * touched (it is put back even if populate_roles() fails).
	 *
	 * @return array|WP_Error Roles array, or `blt_toolkit_reset_defaults`.
	 */
	public static function default_roles() {
		if ( null !== self::$defaults ) {
			return self::$defaults;
		}

		if ( ! function_exists( 'populate_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/schema.php';
		}

		$live    = wp_roles();
		$scratch = clone $live;

		$scratch->use_db       = false;
		$scratch->roles        = array();
		$scratch->role_objects = array();
		$scratch->role_names   = array();

		$GLOBALS['wp_roles'] = $scratch; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Swapped for the scratch object and put back below.

		try {
			populate_roles();
			$roles = $scratch->roles;
		} catch ( Throwable $e ) {
			$roles = array();
		} finally {
			$GLOBALS['wp_roles'] = $live; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Putting the site's own object back.
		}

		if ( ! is_array( $roles ) || ! isset( $roles[ self::ADMIN_ROLE ] ) ) {
			return new WP_Error( 'blt_toolkit_reset_defaults', __( 'WordPress\'s default roles could not be worked out, so nothing was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		self::$defaults = $roles;

		return $roles;
	}

	// ---------- Plan and run ----------

	/**
	 * What a reset would do, worked out by a dry run through the role store
	 * (BLT_Toolkit_Snapshots::review()). Nothing is written (RO-5).
	 *
	 * @param string $mode `builtin` or `full`.
	 * @param array  $args Optional: `actor` (WP_User; default the current user),
	 *                     `count` (false skips counting the people who hold
	 *                     the roles that disappear, as the confirm does).
	 * @return array|WP_Error array(
	 *     'mode', 'diff', 'counts', 'expected' (hash of the roles planned from),
	 *     'unchanged', 'in_code',
	 *     'removed'       => role => array( 'name', 'users' (int, or null when not counted) ) for the roles that disappear,
	 *     'no_role'       => people left with no role (null when not counted),
	 *     'default_role'  => the default role for new users when it disappears, else '',
	 *     'extra_default' => additional default roles (US-2) that disappear,
	 *     'isolated'      => BLT_Toolkit_Snapshots::isolation_notes(),
	 *     'critical', 'names', 'lockout', 'guard' (as BLT_Toolkit_Snapshots::review()),
	 * ), or a WP_Error when WordPress's defaults cannot be worked out.
	 */
	public static function plan( $mode, array $args = array() ) {
		$mode     = self::mode( $mode );
		$defaults = self::default_roles();

		if ( is_wp_error( $defaults ) ) {
			return $defaults;
		}

		$current = self::current_roles();
		$maps    = BLT_Toolkit_Snapshots::isolation_maps();
		$target  = self::target( $mode, $current, $defaults, array_keys( BLT_Toolkit_Caps::own() ), BLT_Toolkit_Caps::lockout_critical(), $maps );
		$plain   = self::target( $mode, $current, $defaults, array(), array() );
		$plan    = array(
			'mode'          => $mode,
			'diff'          => BLT_Toolkit_Role_Diff::diff( $current, $target ),
			'counts'        => array(
				'roles'        => 0,
				'capabilities' => 0,
			),
			'expected'      => BLT_Toolkit_Role_Diff::hash( $current ),
			'unchanged'     => false,
			'in_code'       => ! wp_roles()->use_db,
			'removed'       => array(),
			'no_role'       => null,
			'default_role'  => '',
			'extra_default' => array(),
			'isolated'      => BLT_Toolkit_Snapshots::isolation_notes(
				BLT_Toolkit_Snapshots::seed_isolated( $plain, $maps, self::MODE_FULL === $mode ? array_keys( $defaults ) : self::BUILTIN )['seeded'],
				$target,
				$maps
			),
			'critical'      => array(),
			'names'         => array(),
			'lockout'       => false,
			'guard'         => true,
		);

		if ( ! $plan['in_code'] ) {
			$seen   = '';
			$review = BLT_Toolkit_Snapshots::review(
				self::mutator( $mode, '', $seen, $maps ),
				array(
					'reason'  => self::REASON,
					'context' => self::CONTEXT,
					'actor'   => isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user(),
				)
			);

			if ( is_wp_error( $review ) ) {
				return $review;
			}

			$plan['diff']     = $review['diff'];
			$plan['expected'] = '' !== $seen ? $seen : $plan['expected'];
			$plan['critical'] = $review['critical'];
			$plan['names']    = $review['names'];
			$plan['lockout']  = $review['lockout'];
			$plan['guard']    = $review['guard'];
		}

		$plan['counts']    = BLT_Toolkit_Role_Diff::counts( $plan['diff'] );
		$plan['unchanged'] = BLT_Toolkit_Role_Diff::is_empty( $plan['diff'] );

		// One grouped count for the preview; none on the confirm (SEC-9).
		$impact = BLT_Toolkit_Snapshots::removal_impact( $plan['diff'], array_keys( $target ), ! isset( $args['count'] ) || ! empty( $args['count'] ) );

		$plan['removed']       = $impact['removed'];
		$plan['no_role']       = $impact['no_role'];
		$plan['default_role']  = $impact['default_role'];
		$plan['extra_default'] = $impact['extra_default'];

		return $plan;
	}

	/**
	 * Reset the roles (RO-43).
	 *
	 * 1. The typed confirmation must be RESET (`blt_toolkit_reset_unconfirmed`).
	 * 2. Through the role store: the roles read again; when `expected` is
	 *    given, roles that changed since the preview refuse the reset
	 *    (`blt_toolkit_roles_changed`); the Guard as the person acting (an
	 *    administrator is needed in practice, since both resets change the
	 *    Administrator role, SEC-1); SEC-10's typed role names
	 *    (`confirmed_critical`); a `before_reset` snapshot (none, no write);
	 *    one write; a `roles` / `roles_reset` log row with the diff.
	 *
	 * @param string $mode  `builtin` or `full`.
	 * @param mixed  $typed What the person typed to confirm.
	 * @param array  $args  Optional: `expected` (roles hash from the preview),
	 *                      `confirmed_critical`, `confirmed_lockout`, `actor`.
	 * @return array|WP_Error The role store's result (`snapshot_id` is the
	 *                        `before_reset` snapshot), or a WP_Error:
	 *                        `blt_toolkit_reset_unconfirmed`,
	 *                        `blt_toolkit_reset_unchanged`,
	 *                        `blt_toolkit_roles_changed`, or any refusal of
	 *                        the role store.
	 */
	public static function run( $mode, $typed, array $args = array() ) {
		if ( ! self::word_confirmed( $typed ) ) {
			return new WP_Error(
				'blt_toolkit_reset_unconfirmed',
				/* translators: %s: the word to type, RESET. */
				sprintf( __( 'Type %s in capitals to confirm the reset.', 'blt-toolkit' ), self::WORD ),
				array( 'status' => 400 )
			);
		}

		$mode     = self::mode( $mode );
		$defaults = self::default_roles();

		if ( is_wp_error( $defaults ) ) {
			return $defaults;
		}

		$expected = isset( $args['expected'] ) && is_string( $args['expected'] ) ? strtolower( trim( $args['expected'] ) ) : '';
		$seen     = '';
		$result   = BLT_Toolkit_Role_Store::transaction(
			self::mutator( $mode, $expected, $seen, BLT_Toolkit_Snapshots::isolation_maps() ),
			array(
				'reason'             => self::REASON,
				'context'            => self::CONTEXT,
				'module'             => 'roles',
				'action'             => 'roles_reset',
				'object_type'        => 'roles',
				'object_id'          => $mode,
				'summary'            => self::MODE_FULL === $mode
					? __( 'Reset every role to WordPress\'s defaults (full reset).', 'blt-toolkit' )
					: __( 'Reset the built-in roles\' capabilities to WordPress\'s defaults; custom roles kept.', 'blt-toolkit' ),
				'describe'           => static function ( array $diff ) use ( $mode ) {
					return array(
						'details' => array(
							'mode'          => $mode,
							'counts'        => BLT_Toolkit_Role_Diff::counts( $diff ),
							'roles_removed' => array_keys( $diff['removed'] ),
						),
					);
				},
				'confirmed_critical' => ! empty( $args['confirmed_critical'] ),
				'confirmed_lockout'  => ! empty( $args['confirmed_lockout'] ),
				'actor'              => isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( array() === $result['changes'] ) {
			return new WP_Error(
				'blt_toolkit_reset_unchanged',
				__( 'The roles already match WordPress\'s defaults, so nothing was changed.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		return $result;
	}

	// ---------- Helpers ----------

	/**
	 * The role-store mutator of a reset: refuses roles that no longer hash
	 * to `$expected` (when given) and returns target() for the roles the
	 * store read. `$seen` receives their hash.
	 *
	 * @param string $mode     `builtin` or `full`.
	 * @param string $expected Roles hash from the preview, or ''.
	 * @param string $seen     Receives the hash of the roles the store read.
	 * @param array  $maps     BLT_Toolkit_Snapshots::isolation_maps().
	 * @return callable
	 */
	private static function mutator( $mode, $expected, &$seen, array $maps ) {
		$defaults = self::default_roles();
		$own      = array_keys( BLT_Toolkit_Caps::own() );
		$lockout  = BLT_Toolkit_Caps::lockout_critical();

		return static function ( array $current ) use ( $mode, $expected, $defaults, $own, $lockout, $maps, &$seen ) {
			if ( is_wp_error( $defaults ) ) {
				return $defaults;
			}

			$seen = BLT_Toolkit_Role_Diff::hash( $current );

			if ( '' !== $expected && ! hash_equals( $seen, $expected ) ) {
				return BLT_Toolkit_Snapshots::changed_error();
			}

			return BLT_Toolkit_Reset::target( $mode, $current, $defaults, $own, $lockout, $maps );
		};
	}

	/**
	 * The roles array as WordPress holds it right now.
	 *
	 * @return array
	 */
	private static function current_roles() {
		$roles = wp_roles()->roles;

		return is_array( $roles ) ? $roles : array();
	}
}
