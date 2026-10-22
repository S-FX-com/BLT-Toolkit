<?php
/**
 * Role store: the one write path to the roles option.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every BLT Toolkit write to the roles option goes through here (SPEC 9.1).
 *
 * - RO-1: a save names the capabilities that were on the page and those
 *   checked; only differences within that set are written.
 * - RO-2: each form carries role_hash() from render time; a save whose role
 *   changed since is refused with the difference.
 * - RO-3: the roles are read again inside the save, a snapshot is taken
 *   (SNAP-1; no snapshot, no write), and the option is written once.
 * - RO-4: the Guard checks the computed change set first (SEC-2, SEC-3,
 *   SEC-5, SEC-12, SEC-13, plus SEC-10's review), the save fires
 *   `blt_toolkit_before_roles_save` and `blt_toolkit_after_roles_save`, and
 *   a log row records the diff and the snapshot's ID.
 * - RO-5: nothing here runs on a read path; rendering calls role_hash() only.
 *
 * save_caps() is the matrix and Simple view save for one role; transaction()
 * is the general write (role lifecycle, import, isolation, reset, the
 * self-service toggles, the Support Agent role, activation grants). Both
 * work without the Roles module booted, because activation grants run before
 * any module does.
 *
 * The rules that need no WordPress (change sets, the lock hash, applying a
 * change) are pure and unit-tested in tests/test-role-store.php.
 */
class BLT_Toolkit_Role_Store {

	/**
	 * The snapshot reason when the caller gives none (SPEC 14.3).
	 */
	const DEFAULT_REASON = 'before_save';

	/**
	 * Longest capability name a save accepts.
	 */
	const NAME_MAX = 191;

	/**
	 * How many recent snapshots a refused save searches for the role as it
	 * was when the form was drawn (RO-2's diff).
	 */
	const CONFLICT_SNAPSHOTS = 10;

	/**
	 * The role that SEC-12 protects.
	 */
	const ADMIN_ROLE = 'administrator';

	// ---------- RO-2: the lock token ----------

	/**
	 * The lock token for a role's form (RO-2): SHA-256 of its capabilities
	 * as stored now, canonicalized (BLT_Toolkit_Role_Diff), each value read
	 * as granted or denied. The display name is not part of it.
	 *
	 * @param string $role Role ID.
	 * @return string 64 hex characters, or '' when the role does not exist.
	 */
	public static function role_hash( $role ) {
		$roles = wp_roles()->roles;
		$role  = (string) $role;

		if ( ! isset( $roles[ $role ] ) || ! is_array( $roles[ $role ] ) ) {
			return '';
		}

		return self::caps_hash( isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ? $roles[ $role ]['capabilities'] : array() );
	}

	/**
	 * SHA-256 of a capability array's canonical form, values as booleans,
	 * so key order and `1` versus `true` do not matter. Pure.
	 *
	 * @param array $caps Capability => value.
	 * @return string
	 */
	public static function caps_hash( array $caps ) {
		$normalized = array();

		foreach ( $caps as $cap => $value ) {
			$normalized[ (string) $cap ] = (bool) $value;
		}

		return hash( 'sha256', BLT_Toolkit_Role_Diff::canonicalize( $normalized ) );
	}

	// ---------- RO-1 to RO-4: one role ----------

	/**
	 * Save one role's capabilities from a form (RO-1 to RO-4).
	 *
	 * Within `$on_page` only: a checked capability the role does not grant
	 * is added (one it holds as `false` flips to `true`); an unchecked one it
	 * grants is removed; an unchecked one it holds as `false` stays a deny.
	 * Anything outside `$on_page` is never touched, and checked names
	 * outside it are ignored.
	 *
	 * @param string $role          Role ID.
	 * @param array  $on_page       Capability names rendered on the form.
	 * @param array  $checked       Of those, the ones submitted as granted.
	 * @param string $expected_hash role_hash() from when the form was drawn.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string  $context            Where the save came from: `advanced` (default), `simple`, `self_service`, ...
	 *     @type bool    $confirmed_critical The person confirmed the SEC-10 review (typed the role's name).
	 *     @type bool    $confirmed_lockout  The person confirmed the SEC-13 warning.
	 *     @type bool    $dry_run            Return the change set and the Guard's verdict; write nothing.
	 *     @type string  $reason             Snapshot reason (default `before_save`).
	 *     @type WP_User $actor              Who is saving (default: the current user).
	 * }
	 * @return array|WP_Error array( 'changes' => array( role => array( 'add' => cap => bool, 'remove' => string[], ... ) ),
	 *                        'snapshot_id', 'log_id', 'diff', 'critical', 'dry_run' [, 'guard'] ), or a WP_Error:
	 *                        `blt_toolkit_bad_request` (no lock token), `blt_toolkit_roles_in_code`,
	 *                        `blt_toolkit_role_not_found`, `blt_toolkit_role_changed` (RO-2, data `diff`),
	 *                        a Guard refusal, `blt_toolkit_critical_review` (SEC-10),
	 *                        `blt_toolkit_snapshot_failed` or `blt_toolkit_roles_write_failed`.
	 */
	public static function save_caps( $role, array $on_page, array $checked, $expected_hash, array $args = array() ) {
		$role     = (string) $role;
		$on_page  = self::clean_names( $on_page );
		$checked  = array_values( array_intersect( self::clean_names( $checked ), $on_page ) );
		$expected = is_string( $expected_hash ) ? strtolower( trim( $expected_hash ) ) : '';

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $expected ) ) {
			return new WP_Error( 'blt_toolkit_bad_request', __( 'The form is missing its check for changes made by someone else. Reload the page and try again.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		$computed = array(
			'add'    => array(),
			'remove' => array(),
		);

		$mutate = static function ( array $roles ) use ( $role, $on_page, $checked, $expected, &$computed ) {
			if ( ! isset( $roles[ $role ] ) || ! is_array( $roles[ $role ] ) ) {
				return new WP_Error( 'blt_toolkit_role_not_found', __( 'That role does not exist. Someone may have deleted it.', 'blt-toolkit' ), array( 'status' => 404 ) );
			}

			$caps    = isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ? $roles[ $role ]['capabilities'] : array();
			$current = self::caps_hash( $caps );

			// RO-2: the role must be exactly as it was drawn.
			if ( ! hash_equals( $current, $expected ) ) {
				return BLT_Toolkit_Role_Store::conflict( $role, $roles[ $role ], $expected, $current );
			}

			$computed = self::compute_changes( $caps, $on_page, $checked );

			return self::apply_changes( $roles, $role, $computed );
		};

		$args = array_merge(
			$args,
			array(
				'context'     => isset( $args['context'] ) ? $args['context'] : 'advanced',
				'module'      => 'roles',
				'action'      => 'role_saved',
				'object_type' => 'role',
				'object_id'   => $role,
				'describe'    => static function () use ( $role, $on_page, &$computed ) {
					return BLT_Toolkit_Role_Store::describe_role_save( $role, $computed, count( $on_page ) );
				},
			)
		);

		return self::transaction( $mutate, $args );
	}

	// ---------- The general write ----------

	/**
	 * Write the roles option once, through every rule (RO-3, RO-4, SNAP-1).
	 *
	 * 1. Roles defined in code (`$wp_user_roles`) cannot be written:
	 *    `blt_toolkit_roles_in_code`.
	 * 2. The roles are read again from the database (RO-3).
	 * 3. `$mutate( array $roles )` returns the roles to write (or a WP_Error,
	 *    returned as is). Nothing changed: nothing is written, no snapshot
	 *    is taken, and the result's `changes` is empty.
	 * 4. The Guard, per role, for the person acting (`actor`, default the
	 *    current user): a changed role through check_role_change() (SEC-12,
	 *    SEC-1, SEC-5, SEC-2, SEC-3, SEC-13); a created role through
	 *    can_grant() (SEC-3; only an administrator may create
	 *    `administrator`); a deleted role through check_role_change() with
	 *    every capability removed, and `administrator` never (SEC-12). With
	 *    `system` (the plugin's own write with nobody acting, such as the
	 *    activation grant) only SEC-12 applies.
	 * 5. SEC-10: Critical capabilities granted to any role but
	 *    `administrator` need `confirmed_critical`, otherwise
	 *    `blt_toolkit_critical_review` (data `roles`: role => capabilities,
	 *    `names`: role => display name for the typed confirmation).
	 * 6. `dry_run`: return the plan with `guard` (true or the refusal).
	 * 7. A snapshot (`reason`, default `before_save`). None, no write:
	 *    `blt_toolkit_snapshot_failed`.
	 * 8. `blt_toolkit_before_roles_save`, one update_option(), the role
	 *    objects reloaded from it, the Guard's and the catalog's caches
	 *    dropped, the current user's capabilities refreshed.
	 * 9. One log row (`module`, `action`, `summary`, `object_type`,
	 *    `object_id`, `details` plus `context` and the Role_Diff), then
	 *    `blt_toolkit_after_roles_save`.
	 *
	 * @param callable $mutate Receives the current roles array, returns the new one or a WP_Error.
	 * @param array    $args {
	 *     Optional.
	 *
	 *     @type string   $reason             Snapshot reason (default `before_save`).
	 *     @type string   $context            Passed to the hooks and logged (default `roles`).
	 *     @type string   $module             Log module (default `roles`).
	 *     @type string   $action             Log action (default `roles_saved`).
	 *     @type string   $summary            Log summary (default: generated from the diff).
	 *     @type string   $object_type        Log object type (default `role`).
	 *     @type string   $object_id          Log object ID (default: the changed role IDs).
	 *     @type array    $details            Extra log details.
	 *     @type callable $describe           ( array $diff, array $changes ): array with any of `summary`, `object_id`, `details`, called after $mutate.
	 *     @type bool     $confirmed_critical SEC-10 confirmed.
	 *     @type bool     $confirmed_lockout  SEC-13 confirmed.
	 *     @type bool     $dry_run            Plan only.
	 *     @type WP_User  $actor              Who is acting (default: the current user).
	 *     @type bool     $system             The plugin's own write with nobody acting: SEC-12 only.
	 * }
	 * @return array|WP_Error array( 'changes', 'snapshot_id', 'log_id', 'diff', 'critical', 'dry_run' [, 'guard'] ), or a WP_Error.
	 */
	public static function transaction( callable $mutate, array $args = array() ) {
		$args     = self::defaults( $args );
		$wp_roles = wp_roles();

		if ( ! $wp_roles->use_db ) {
			return new WP_Error( 'blt_toolkit_roles_in_code', __( 'This site defines its roles in code ($wp_user_roles), so they cannot be changed here.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		// RO-3, SPEC 19 URE-5: never write back a copy read at the start of
		// the request.
		self::reload( $wp_roles );

		$before = is_array( $wp_roles->roles ) ? $wp_roles->roles : array();
		$after  = call_user_func( $mutate, $before );

		if ( is_wp_error( $after ) ) {
			return $after;
		}

		if ( ! is_array( $after ) ) {
			return new WP_Error( 'blt_toolkit_roles_invalid', __( 'The new roles could not be worked out, so nothing was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		$after   = self::normalize_touched( $before, $after );
		$diff    = BLT_Toolkit_Role_Diff::diff( $before, $after );
		$changes = self::changes_from_diff( $diff );
		$result  = array(
			'changes'     => $changes,
			'snapshot_id' => 0,
			'log_id'      => 0,
			'diff'        => $diff,
			'critical'    => array(),
			'dry_run'     => $args['dry_run'],
		);

		if ( array() === $changes ) {
			return $result;
		}

		$actor   = $args['actor'];
		$verdict = $args['system'] ? self::system_check( $changes ) : self::guard( $actor, $changes, $args['confirmed_lockout'] );

		$result['critical'] = $args['system'] ? array() : self::critical( $changes );

		if ( true === $verdict && array() !== $result['critical'] && ! $args['confirmed_critical'] ) {
			$verdict = self::critical_error( $result['critical'], $after );
		}

		if ( $args['dry_run'] ) {
			$result['guard'] = $verdict;

			return $result;
		}

		if ( true !== $verdict ) {
			return $verdict;
		}

		// SNAP-1, and the decided policy: no snapshot, no write.
		$snapshot_id = (int) BLT_Toolkit_Snapshots::take( $args['reason'] );

		if ( $snapshot_id <= 0 ) {
			return new WP_Error( 'blt_toolkit_snapshot_failed', __( 'A copy of the roles could not be saved first, so no role was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		$described = self::describe( $args, $diff, $changes );

		/**
		 * Fires after the Guard approved a change to the roles and before the
		 * roles option is written (RO-4).
		 *
		 * @param array  $changes Role ID => array( 'add' => cap => bool, 'remove' => string[], 'created' => bool, 'deleted' => bool, 'name' => null|array( 'from', 'to' ) ).
		 * @param string $context Where the save came from (`advanced`, `simple`, `self_service`, `support_agent`, `activation`, `sync`, ...).
		 */
		do_action( 'blt_toolkit_before_roles_save', $changes, $args['context'] );

		// RO-3: one write for the whole change. The diff is not empty, so
		// update_option() returning false means the database refused it.
		if ( ! update_option( $wp_roles->role_key, $after ) ) {
			self::reload( $wp_roles );
			self::log( $args, $described, $diff, $snapshot_id, 'failure' );

			return new WP_Error( 'blt_toolkit_roles_write_failed', __( 'The roles could not be saved. Nothing was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		$wp_roles->for_site();
		self::after_write();

		$result['snapshot_id'] = $snapshot_id;
		$result['log_id']      = self::log( $args, $described, $diff, $snapshot_id, 'success' );

		/**
		 * Fires after the roles option was written once (RO-3) and the change
		 * logged (RO-4).
		 *
		 * @param array  $changes     As for `blt_toolkit_before_roles_save`.
		 * @param string $context     Where the save came from.
		 * @param int    $snapshot_id The snapshot taken first (SNAP-1).
		 */
		do_action( 'blt_toolkit_after_roles_save', $changes, $args['context'], $snapshot_id );

		return $result;
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * Names from a form: strings, trimmed, unique, without empty names,
	 * control characters, names longer than 191 bytes or `do_not_allow`.
	 * Case is kept: capability names are case-sensitive. Pure.
	 *
	 * @param array $names Submitted values.
	 * @return string[]
	 */
	public static function clean_names( array $names ) {
		$out = array();

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$name = trim( $name );

			if ( '' === $name || strlen( $name ) > self::NAME_MAX || preg_match( '/[\x00-\x1F\x7F]/', $name ) || 'do_not_allow' === $name ) {
				continue;
			}

			$out[ $name ] = true;
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * The adds and removes for one role within the on-page set (RO-1). Pure.
	 *
	 * - Checked and not granted (absent, or stored `false`): add `true`.
	 * - Unchecked and granted: remove.
	 * - Unchecked and stored `false`: untouched (a deny stays a deny).
	 * - Not on the page: untouched.
	 *
	 * @param array    $current Role's capabilities (cap => value).
	 * @param string[] $on_page Capabilities on the form.
	 * @param string[] $checked Capabilities submitted as granted.
	 * @return array{add:array<string,bool>,remove:string[]}
	 */
	public static function compute_changes( array $current, array $on_page, array $checked ) {
		$checked = array_fill_keys( array_map( 'strval', $checked ), true );
		$add     = array();
		$remove  = array();

		foreach ( $on_page as $cap ) {
			$cap     = (string) $cap;
			$has_key = array_key_exists( $cap, $current );
			$granted = $has_key && (bool) $current[ $cap ];

			if ( isset( $checked[ $cap ] ) ) {
				if ( ! $granted ) {
					$add[ $cap ] = true;
				}
			} elseif ( $granted ) {
				$remove[] = $cap;
			}
		}

		ksort( $add, SORT_STRING );
		sort( $remove, SORT_STRING );

		return array(
			'add'    => $add,
			'remove' => $remove,
		);
	}

	/**
	 * A roles array with one role's change applied. Pure.
	 *
	 * @param array  $roles   Roles array.
	 * @param string $role    Role ID.
	 * @param array  $changes array( 'add' => cap => bool, 'remove' => string[] ).
	 * @return array
	 */
	public static function apply_changes( array $roles, $role, array $changes ) {
		$role = (string) $role;

		if ( ! isset( $roles[ $role ] ) || ! is_array( $roles[ $role ] ) ) {
			return $roles;
		}

		$caps = isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ? $roles[ $role ]['capabilities'] : array();

		foreach ( isset( $changes['remove'] ) ? (array) $changes['remove'] : array() as $cap ) {
			unset( $caps[ (string) $cap ] );
		}

		foreach ( isset( $changes['add'] ) ? (array) $changes['add'] : array() as $cap => $value ) {
			$caps[ (string) $cap ] = (bool) $value;
		}

		$roles[ $role ]['capabilities'] = $caps;

		return $roles;
	}

	/**
	 * The change set of a Role_Diff::diff(): per role, `add` (cap => the new
	 * value; a flip is an add with its new value), `remove` (names),
	 * `created`, `deleted` and `name` (null, or array( 'from', 'to' )). Pure.
	 *
	 * @param array $diff Result of BLT_Toolkit_Role_Diff::diff().
	 * @return array<string,array{add:array<string,bool>,remove:string[],created:bool,deleted:bool,name:array|null}>
	 */
	public static function changes_from_diff( array $diff ) {
		$out = array();

		foreach ( isset( $diff['added'] ) ? (array) $diff['added'] : array() as $role => $data ) {
			$out[ (string) $role ] = array(
				'add'     => self::bools( isset( $data['capabilities'] ) ? (array) $data['capabilities'] : array() ),
				'remove'  => array(),
				'created' => true,
				'deleted' => false,
				'name'    => null,
			);
		}

		foreach ( isset( $diff['removed'] ) ? (array) $diff['removed'] : array() as $role => $data ) {
			$remove = array_map( 'strval', array_keys( isset( $data['capabilities'] ) ? (array) $data['capabilities'] : array() ) );

			sort( $remove, SORT_STRING );

			$out[ (string) $role ] = array(
				'add'     => array(),
				'remove'  => $remove,
				'created' => false,
				'deleted' => true,
				'name'    => null,
			);
		}

		foreach ( isset( $diff['changed'] ) ? (array) $diff['changed'] : array() as $role => $change ) {
			$add = self::bools( isset( $change['added'] ) ? (array) $change['added'] : array() );

			foreach ( isset( $change['flipped'] ) ? (array) $change['flipped'] : array() as $cap => $flip ) {
				$add[ (string) $cap ] = ! empty( $flip['to'] );
			}

			ksort( $add, SORT_STRING );

			$remove = array_map( 'strval', array_keys( isset( $change['removed'] ) ? (array) $change['removed'] : array() ) );

			sort( $remove, SORT_STRING );

			$out[ (string) $role ] = array(
				'add'     => $add,
				'remove'  => $remove,
				'created' => false,
				'deleted' => false,
				'name'    => isset( $change['name'] ) && is_array( $change['name'] ) ? $change['name'] : null,
			);
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * A roles array as the store writes it: string IDs, each role an array
	 * with a string `name` and an array of `capabilities`. Anything else is
	 * dropped. Values are kept as given. Pure.
	 *
	 * @param array $roles Roles array.
	 * @return array
	 */
	public static function normalize_roles( array $roles ) {
		$out = array();

		foreach ( $roles as $id => $role ) {
			$id = (string) $id;

			if ( '' === $id || ! is_array( $role ) ) {
				continue;
			}

			$out[ $id ] = array(
				'name'         => isset( $role['name'] ) && is_scalar( $role['name'] ) ? (string) $role['name'] : $id,
				'capabilities' => isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array(),
			) + $role;
		}

		return $out;
	}

	/**
	 * The roles to write: every role the mutator left exactly as stored is
	 * written back as stored, however malformed (no `name`, capabilities
	 * that are not an array), and only the roles it added or changed are
	 * normalized (normalize_roles()). Normalizing every role would show an
	 * untouched malformed role as changed in each save's diff, run the Guard
	 * on it and write a repair nobody asked for. Pure.
	 *
	 * @param array $before The roles as stored.
	 * @param array $after  The mutator's result.
	 * @return array
	 */
	public static function normalize_touched( array $before, array $after ) {
		$out = array();

		foreach ( $after as $id => $role ) {
			if ( array_key_exists( $id, $before ) && $before[ $id ] === $role ) {
				$out[ $id ] = $role;
				continue;
			}

			$one = self::normalize_roles( array( $id => $role ) );

			foreach ( $one as $key => $normalized ) {
				$out[ $key ] = $normalized;
			}
		}

		return $out;
	}

	/**
	 * What a refused save should show (RO-2): the role as it was drawn,
	 * found among recent snapshots by its lock hash, against the role now,
	 * as a Role_Diff::diff() of that one role. Null when that copy is not
	 * in a recent snapshot (the role was changed outside BLT Toolkit). Pure.
	 *
	 * @param string $role       Role ID.
	 * @param array  $current    The role now (`name`, `capabilities`).
	 * @param string $expected   The form's lock hash.
	 * @param array  $candidates Roles arrays from recent snapshots, newest first.
	 * @return array|null
	 */
	public static function conflict_diff( $role, array $current, $expected, array $candidates ) {
		$role = (string) $role;

		foreach ( $candidates as $roles ) {
			if ( ! is_array( $roles ) || ! isset( $roles[ $role ] ) || ! is_array( $roles[ $role ] ) ) {
				continue;
			}

			$caps = isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ? $roles[ $role ]['capabilities'] : array();

			if ( hash_equals( self::caps_hash( $caps ), (string) $expected ) ) {
				return BLT_Toolkit_Role_Diff::diff( array( $role => $roles[ $role ] ), array( $role => $current ) );
			}
		}

		return null;
	}

	// ---------- Helpers ----------

	/**
	 * The RO-2 refusal, with the difference when it can be found.
	 *
	 * @param string $role     Role ID.
	 * @param array  $current  The role now.
	 * @param string $expected The form's lock hash.
	 * @param string $hash     The role's hash now.
	 * @return WP_Error `blt_toolkit_role_changed`, data `status` 409, `role`, `diff` (or null), `current_hash`.
	 */
	public static function conflict( $role, array $current, $expected, $hash ) {
		$candidates = array();

		foreach ( BLT_Toolkit_Snapshots::list(
			array(
				'per_page' => self::CONFLICT_SNAPSHOTS,
				'counts'   => false,
			)
		) as $row ) {
			$snapshot = BLT_Toolkit_Snapshots::get( $row['id'] );

			if ( is_array( $snapshot ) && ! empty( $snapshot['valid'] ) ) {
				$candidates[] = $snapshot['roles'];
			}
		}

		return new WP_Error(
			'blt_toolkit_role_changed',
			__( 'This role changed since you opened it, so nothing was saved. Review the changes below, then reload the role and make your change again.', 'blt-toolkit' ),
			array(
				'status'       => 409,
				'role'         => (string) $role,
				'diff'         => self::conflict_diff( $role, $current, $expected, $candidates ),
				'current_hash' => (string) $hash,
			)
		);
	}

	/**
	 * Log fields for a one-role save (save_caps()).
	 *
	 * @param string $role     Role ID.
	 * @param array  $computed compute_changes() result.
	 * @param int    $on_page  How many capabilities were on the form.
	 * @return array
	 */
	public static function describe_role_save( $role, array $computed, $on_page ) {
		$added   = array_keys( isset( $computed['add'] ) ? (array) $computed['add'] : array() );
		$removed = isset( $computed['remove'] ) ? array_values( (array) $computed['remove'] ) : array();

		return array(
			'summary' => sprintf(
				/* translators: 1: role name, 2: list of changes, such as "2 capabilities added, 1 removed". */
				__( 'Saved the %1$s role: %2$s.', 'blt-toolkit' ),
				self::role_name( $role ),
				self::counts_text( count( $added ), count( $removed ) )
			),
			'details' => array(
				'role'    => (string) $role,
				'added'   => $added,
				'removed' => $removed,
				'on_page' => (int) $on_page,
			),
		);
	}

	/**
	 * Arguments with their defaults.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private static function defaults( array $args ) {
		$actor = isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user();

		return array(
			'reason'             => isset( $args['reason'] ) && is_string( $args['reason'] ) && '' !== $args['reason'] ? $args['reason'] : self::DEFAULT_REASON,
			'context'            => isset( $args['context'] ) && is_string( $args['context'] ) && '' !== sanitize_key( $args['context'] ) ? sanitize_key( $args['context'] ) : 'roles',
			'module'             => isset( $args['module'] ) && is_string( $args['module'] ) && '' !== $args['module'] ? $args['module'] : 'roles',
			'action'             => isset( $args['action'] ) && is_string( $args['action'] ) && '' !== $args['action'] ? $args['action'] : 'roles_saved',
			'summary'            => isset( $args['summary'] ) && is_string( $args['summary'] ) ? $args['summary'] : '',
			'object_type'        => isset( $args['object_type'] ) && is_string( $args['object_type'] ) ? $args['object_type'] : 'role',
			'object_id'          => isset( $args['object_id'] ) && is_scalar( $args['object_id'] ) ? (string) $args['object_id'] : '',
			'details'            => isset( $args['details'] ) && is_array( $args['details'] ) ? $args['details'] : array(),
			'describe'           => isset( $args['describe'] ) && is_callable( $args['describe'] ) ? $args['describe'] : null,
			'confirmed_critical' => ! empty( $args['confirmed_critical'] ),
			'confirmed_lockout'  => ! empty( $args['confirmed_lockout'] ),
			'dry_run'            => ! empty( $args['dry_run'] ),
			'actor'              => $actor,
			'system'             => ! empty( $args['system'] ),
		);
	}

	/**
	 * Read the roles option from the database again (RO-3).
	 *
	 * The option is autoloaded, so get_option() answers from the copy of
	 * every autoloaded option read when the request started (or, with a
	 * persistent object cache, from that cache). Dropping those entries
	 * makes WP_Roles::for_site() read it again. One query, on a save only.
	 *
	 * @param WP_Roles $wp_roles Roles.
	 * @return void
	 */
	private static function reload( WP_Roles $wp_roles ) {
		if ( ! $wp_roles->use_db ) {
			return;
		}

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $wp_roles->role_key, 'options' );

		$wp_roles->for_site();
	}

	/**
	 * The Guard on every role a change touches (RO-4).
	 *
	 * @param WP_User $actor     Who is acting.
	 * @param array   $changes   changes_from_diff().
	 * @param bool    $confirmed SEC-13 confirmed.
	 * @return true|WP_Error The first refusal.
	 */
	private static function guard( WP_User $actor, array $changes, $confirmed ) {
		if ( ! $actor->exists() ) {
			return BLT_Toolkit_Guard::error(
				array(
					'code' => 'blt_toolkit_no_actor',
					'rule' => '',
				)
			);
		}

		$is_admin = BLT_Toolkit_Guard::is_toolkit_admin( $actor );

		foreach ( $changes as $role => $change ) {
			$role = (string) $role;

			if ( $change['deleted'] && BLT_Toolkit_Guard::is_protected_role( $role ) ) {
				return self::protected_error();
			}

			if ( $change['created'] ) {
				if ( BLT_Toolkit_Guard::is_protected_role( $role ) && ! $is_admin ) {
					return BLT_Toolkit_Guard::error(
						array(
							'code' => 'blt_toolkit_protected_role',
							'rule' => 'SEC-1',
						)
					);
				}

				$check = BLT_Toolkit_Guard::can_grant( $actor, $change['add'] );
			} else {
				$check = BLT_Toolkit_Guard::check_role_change( $actor, $role, $change['add'], $change['remove'], $confirmed );
			}

			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		return true;
	}

	/**
	 * The rules that hold for the plugin's own writes with nobody acting
	 * (activation): `administrator` is never deleted and never loses the
	 * lockout-critical set (SEC-12).
	 *
	 * @param array $changes changes_from_diff().
	 * @return true|WP_Error
	 */
	private static function system_check( array $changes ) {
		foreach ( $changes as $role => $change ) {
			$role = (string) $role;

			if ( $change['deleted'] && BLT_Toolkit_Guard::is_protected_role( $role ) ) {
				return self::protected_error();
			}

			$split     = BLT_Toolkit_Guard::cap_changes( $change['add'], $change['remove'] );
			$violation = BLT_Toolkit_Guard::role_change_violation(
				array(
					'actor_is_admin' => true,
					'role'           => $role,
					'grants'         => $split['grants'],
					'denies'         => $split['denies'],
					'removes'        => $split['removes'],
					'lockout'        => BLT_Toolkit_Caps::lockout_critical(),
				)
			);

			if ( null !== $violation ) {
				return BLT_Toolkit_Guard::error( $violation );
			}
		}

		return true;
	}

	/**
	 * The refusal to delete `administrator` (SEC-12).
	 *
	 * @return WP_Error
	 */
	private static function protected_error() {
		return new WP_Error(
			'blt_toolkit_protected_role',
			__( 'The Administrator role cannot be deleted.', 'blt-toolkit' ),
			array(
				'status'      => 403,
				'rule'        => 'SEC-12',
				'caps'        => array(),
				'confirmable' => false,
			)
		);
	}

	/**
	 * SEC-10: Critical capabilities granted to roles other than
	 * `administrator`, per role.
	 *
	 * @param array $changes changes_from_diff().
	 * @return array<string,string[]>
	 */
	private static function critical( array $changes ) {
		$out = array();

		foreach ( $changes as $role => $change ) {
			if ( self::ADMIN_ROLE === (string) $role || $change['deleted'] ) {
				continue;
			}

			$caps = BLT_Toolkit_Guard::requires_critical_review( $change['add'] );

			if ( array() !== $caps ) {
				$out[ (string) $role ] = $caps;
			}
		}

		return $out;
	}

	/**
	 * The SEC-10 refusal: the review step must be shown and confirmed.
	 *
	 * @param array $critical critical().
	 * @param array $after    Roles after the change (for display names).
	 * @return WP_Error `blt_toolkit_critical_review`, data `status` 409, `rule`, `caps`, `roles`, `names`, `confirmable`.
	 */
	private static function critical_error( array $critical, array $after ) {
		$all   = array();
		$names = array();

		foreach ( $critical as $role => $caps ) {
			$all            = array_merge( $all, $caps );
			$names[ $role ] = isset( $after[ $role ]['name'] ) ? (string) $after[ $role ]['name'] : (string) $role;
		}

		$all = array_values( array_unique( $all ) );

		sort( $all, SORT_STRING );

		return new WP_Error(
			'blt_toolkit_critical_review',
			__( 'This change grants Critical capabilities, which can be used to take over the site or its users. Review it and type the role\'s name to confirm.', 'blt-toolkit' ),
			array(
				'status'      => 409,
				'rule'        => 'SEC-10',
				'caps'        => $all,
				'roles'       => $critical,
				'names'       => $names,
				'confirmable' => true,
			)
		);
	}

	/**
	 * Values as booleans, keys as strings. Pure.
	 *
	 * @param array $caps Capability => value.
	 * @return array<string,bool>
	 */
	private static function bools( array $caps ) {
		$out = array();

		foreach ( $caps as $cap => $value ) {
			$out[ (string) $cap ] = (bool) $value;
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * The log fields after the caller's describe callback.
	 *
	 * @param array $args    defaults().
	 * @param array $diff    Diff.
	 * @param array $changes Change set.
	 * @return array{summary:string,object_id:string,details:array}
	 */
	private static function describe( array $args, array $diff, array $changes ) {
		$out = array(
			'summary'   => $args['summary'],
			'object_id' => $args['object_id'],
			'details'   => $args['details'],
		);

		if ( null !== $args['describe'] ) {
			$extra = call_user_func( $args['describe'], $diff, $changes );

			if ( is_array( $extra ) ) {
				if ( isset( $extra['summary'] ) && is_string( $extra['summary'] ) ) {
					$out['summary'] = $extra['summary'];
				}

				if ( isset( $extra['object_id'] ) && is_scalar( $extra['object_id'] ) ) {
					$out['object_id'] = (string) $extra['object_id'];
				}

				if ( isset( $extra['details'] ) && is_array( $extra['details'] ) ) {
					$out['details'] = array_merge( $out['details'], $extra['details'] );
				}
			}
		}

		if ( '' === $out['object_id'] ) {
			$out['object_id'] = implode( ',', array_keys( $changes ) );
		}

		if ( '' === $out['summary'] ) {
			$out['summary'] = self::default_summary( $changes );
		}

		return $out;
	}

	/**
	 * A summary for any change set.
	 *
	 * @param array $changes Change set.
	 * @return string
	 */
	private static function default_summary( array $changes ) {
		if ( 1 === count( $changes ) ) {
			$role   = (string) key( $changes );
			$change = reset( $changes );

			if ( $change['created'] ) {
				/* translators: %s: role name. */
				return sprintf( __( 'Created the %s role.', 'blt-toolkit' ), self::role_name( $role ) );
			}

			if ( $change['deleted'] ) {
				/* translators: %s: role ID. */
				return sprintf( __( 'Deleted the %s role.', 'blt-toolkit' ), $role );
			}

			return sprintf(
				/* translators: 1: role name, 2: list of changes, such as "2 capabilities added, 1 removed". */
				__( 'Changed the %1$s role: %2$s.', 'blt-toolkit' ),
				self::role_name( $role ),
				self::counts_text( count( $change['add'] ), count( $change['remove'] ) )
			);
		}

		return sprintf(
			/* translators: 1: number of roles, 2: comma-separated role IDs. */
			_n( 'Changed %1$s role: %2$s.', 'Changed %1$s roles: %2$s.', count( $changes ), 'blt-toolkit' ),
			number_format_i18n( count( $changes ) ),
			implode( ', ', array_keys( $changes ) )
		);
	}

	/**
	 * "2 capabilities added, 1 removed".
	 *
	 * @param int $added   Added or flipped.
	 * @param int $removed Removed.
	 * @return string
	 */
	private static function counts_text( $added, $removed ) {
		$parts = array();

		if ( $added > 0 ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s capability granted', '%s capabilities granted', $added, 'blt-toolkit' ), number_format_i18n( $added ) );
		}

		if ( $removed > 0 ) {
			/* translators: %s: number of capabilities. */
			$parts[] = sprintf( _n( '%s capability removed', '%s capabilities removed', $removed, 'blt-toolkit' ), number_format_i18n( $removed ) );
		}

		return array() === $parts ? __( 'nothing changed', 'blt-toolkit' ) : implode( ', ', $parts );
	}

	/**
	 * A role's display name, translated as WordPress shows it.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	private static function role_name( $role ) {
		$roles = wp_roles()->roles;

		if ( isset( $roles[ $role ]['name'] ) && function_exists( 'translate_user_role' ) ) {
			return translate_user_role( (string) $roles[ $role ]['name'] );
		}

		return (string) $role;
	}

	/**
	 * After a write: the role objects already match the stored option; drop
	 * every per-request cache that read the old roles and refresh the current
	 * user's capabilities, without making WordPress decide who is signed in
	 * when it has not yet.
	 *
	 * @return void
	 */
	private static function after_write() {
		BLT_Toolkit_Guard::flush();

		if ( class_exists( 'BLT_Toolkit_Capability_Catalog', false ) ) {
			BLT_Toolkit_Capability_Catalog::flush();
		}

		if ( isset( $GLOBALS['current_user'] ) && $GLOBALS['current_user'] instanceof WP_User && $GLOBALS['current_user']->exists() ) {
			$GLOBALS['current_user']->get_role_caps();
		}
	}

	/**
	 * The log row (RO-4, LOG-1).
	 *
	 * @param array  $args        defaults().
	 * @param array  $described   describe().
	 * @param array  $diff        Diff.
	 * @param int    $snapshot_id Snapshot taken first.
	 * @param string $status      `success` or `failure`.
	 * @return int Log row ID, or 0.
	 */
	private static function log( array $args, array $described, array $diff, $snapshot_id, $status ) {
		$details = array_merge(
			$described['details'],
			array(
				'context' => $args['context'],
				'diff'    => $diff,
			)
		);

		$summary = $described['summary'];

		if ( 'failure' === $status ) {
			/* translators: %s: what was being changed. */
			$summary = sprintf( __( 'Could not save the roles; nothing was changed. (%s)', 'blt-toolkit' ), $summary );
		}

		$id = BLT_Toolkit_Log::record(
			array(
				'module'      => $args['module'],
				'action'      => $args['action'],
				'object_type' => $args['object_type'],
				'object_id'   => $described['object_id'],
				'summary'     => $summary,
				'details'     => $details,
				'snapshot_id' => (int) $snapshot_id,
				'status'      => $status,
				'source'      => '',
			)
		);

		return false === $id ? 0 : (int) $id;
	}
}
