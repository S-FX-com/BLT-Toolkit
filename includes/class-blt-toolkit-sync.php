<?php
/**
 * Sync administrator (RO-5): give the Administrator role the registered
 * capabilities it lacks, on request.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools > Sync administrator (RO-5; SPEC 19 URE-2).
 *
 * User Role Editor grants missing capabilities to `administrator` while it
 * builds its capability list, a read path that writes roles. BLT Toolkit
 * never does that; this is the explicit action instead: a preview of the
 * registered capabilities the role lacks, then one write on confirm.
 *
 * "Registered" means known to BLT_Toolkit_Capability_Catalog (core, active
 * post types and taxonomies, BLT Toolkit, groups declared through
 * `blt_toolkit_capability_groups`), never an orphan (RO-11). Left out as
 * well: deprecated capabilities (`level_*`, `edit_files`), multisite-only
 * ones, read-only markers (`blt_readonly_*`, which restrict whoever holds
 * them), meta capability names and role names. A capability the role holds
 * as denied (`false`) is a deliberate choice and stays denied; the preview
 * lists those separately.
 *
 * The catalog belongs to the Roles module, so sync is available only while
 * that module runs (SPEC 4.1: a module that is off loads no code).
 *
 * The write goes through BLT_Toolkit_Role_Store::transaction() (context
 * `sync`, snapshot reason `before_save`, log action `administrator_synced`)
 * and grants exactly the capabilities the preview listed: the confirm
 * carries a hash of that list and of the role as it was, and anything that
 * changed in between refuses the sync.
 */
class BLT_Toolkit_Sync {

	/**
	 * Context passed to the roles-save hooks and logged.
	 */
	const CONTEXT = 'sync';

	/**
	 * The role synced.
	 */
	const ADMIN_ROLE = 'administrator';

	/**
	 * Prefix of the read-only markers (RS-13), never granted by a sync.
	 */
	const MARKER_PREFIX = 'blt_readonly_';

	// ---------- Pure rules ----------

	/**
	 * The registered capabilities `administrator` lacks, and those it holds
	 * as denied. Pure.
	 *
	 * @param array    $entries    Catalog entries: cap => array( 'orphan', 'deprecated', 'multisite', ... ).
	 * @param array    $admin_caps The role's capabilities (cap => value).
	 * @param string[] $skip       Names never granted (meta capability names, role names).
	 * @return array{grant:string[],denied:string[]} Sorted names.
	 */
	public static function missing( array $entries, array $admin_caps, array $skip = array() ) {
		$skip = array_fill_keys( array_map( 'strval', $skip ), true );
		$out  = array(
			'grant'  => array(),
			'denied' => array(),
		);

		foreach ( $entries as $cap => $entry ) {
			$cap   = (string) $cap;
			$entry = is_array( $entry ) ? $entry : array();

			if ( '' === $cap || 'do_not_allow' === $cap || 'exist' === $cap || isset( $skip[ $cap ] ) || 0 === strpos( $cap, self::MARKER_PREFIX ) ) {
				continue;
			}

			if ( ! empty( $entry['orphan'] ) || ! empty( $entry['deprecated'] ) || ! empty( $entry['multisite'] ) ) {
				continue;
			}

			if ( ! array_key_exists( $cap, $admin_caps ) ) {
				$out['grant'][] = $cap;
			} elseif ( ! $admin_caps[ $cap ] ) {
				$out['denied'][] = $cap;
			}
		}

		sort( $out['grant'], SORT_STRING );
		sort( $out['denied'], SORT_STRING );

		return $out;
	}

	/**
	 * The token a confirm carries: SHA-256 of the capabilities to grant and
	 * of the role's capabilities as they were. Pure.
	 *
	 * @param string[] $grant      Capabilities to grant.
	 * @param array    $admin_caps The role's capabilities.
	 * @return string
	 */
	public static function plan_hash( array $grant, array $admin_caps ) {
		$grant = array_values( array_map( 'strval', $grant ) );

		sort( $grant, SORT_STRING );

		return hash( 'sha256', implode( ',', $grant ) . '|' . BLT_Toolkit_Role_Store::caps_hash( $admin_caps ) );
	}

	// ---------- Plan and run ----------

	/**
	 * Whether sync can be used now: the Roles module runs (the catalog is
	 * its code).
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'BLT_Toolkit_Plugin' ) && BLT_Toolkit_Plugin::is_active( 'roles' );
	}

	/**
	 * What a sync would grant, with the Guard's verdict from a dry run
	 * through the role store. Nothing is written (RO-5).
	 *
	 * @param array $args Optional: `actor` (WP_User; default the current user).
	 * @return array|WP_Error array( 'grant' => string[], 'denied' => string[],
	 *                        'expected' => plan_hash(), 'in_code' => bool,
	 *                        'guard' => true|WP_Error ), or
	 *                        `blt_toolkit_sync_unavailable` /
	 *                        `blt_toolkit_no_admin_role`.
	 */
	public static function plan( array $args = array() ) {
		if ( ! self::available() ) {
			return self::unavailable();
		}

		$admin_caps = self::admin_caps();

		if ( null === $admin_caps ) {
			return self::no_admin();
		}

		$missing = self::missing( BLT_Toolkit_Capability_Catalog::all(), $admin_caps, self::skip() );
		$plan    = array(
			'grant'    => $missing['grant'],
			'denied'   => $missing['denied'],
			'expected' => self::plan_hash( $missing['grant'], $admin_caps ),
			'in_code'  => ! wp_roles()->use_db,
			'guard'    => true,
		);

		if ( $plan['in_code'] || array() === $plan['grant'] ) {
			return $plan;
		}

		$review = BLT_Toolkit_Snapshots::review(
			self::mutator( $plan['grant'], '' ),
			array(
				'context' => self::CONTEXT,
				'actor'   => isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user(),
			)
		);

		if ( is_wp_error( $review ) ) {
			return $review;
		}

		$plan['guard'] = $review['guard'];

		return $plan;
	}

	/**
	 * Grant what the preview listed (RO-5).
	 *
	 * The plan is worked out again; when its hash is not `$expected` (a
	 * capability was registered or removed, or the role changed since the
	 * preview) nothing is written (`blt_toolkit_roles_changed`). Then one
	 * write through the role store, which re-reads the roles and refuses if
	 * the role no longer matches the plan, runs the Guard as the person
	 * acting (only an administrator may change the Administrator role,
	 * SEC-1), takes a snapshot first and logs `roles` /
	 * `administrator_synced`.
	 *
	 * @param string $expected plan_hash() from the preview.
	 * @param array  $args     Optional: `actor` (WP_User).
	 * @return array|WP_Error The role store's result plus `granted`
	 *                        (string[]), or a WP_Error:
	 *                        `blt_toolkit_sync_unavailable`,
	 *                        `blt_toolkit_no_admin_role`,
	 *                        `blt_toolkit_roles_changed`,
	 *                        `blt_toolkit_sync_nothing`, or any refusal of
	 *                        the role store.
	 */
	public static function run( $expected, array $args = array() ) {
		$plan = self::plan( $args );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$expected = is_string( $expected ) ? strtolower( trim( $expected ) ) : '';

		if ( '' === $expected || ! hash_equals( $plan['expected'], $expected ) ) {
			return BLT_Toolkit_Snapshots::changed_error();
		}

		if ( array() === $plan['grant'] ) {
			return new WP_Error(
				'blt_toolkit_sync_nothing',
				__( 'The Administrator role already has every registered capability, so nothing was changed.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		$grant  = $plan['grant'];
		$result = BLT_Toolkit_Role_Store::transaction(
			self::mutator( $grant, $expected ),
			array(
				'context'     => self::CONTEXT,
				'module'      => 'roles',
				'action'      => 'administrator_synced',
				'object_type' => 'role',
				'object_id'   => self::ADMIN_ROLE,
				'summary'     => sprintf(
					/* translators: %s: number of capabilities. */
					_n( 'Gave the Administrator role %s registered capability it did not have (Sync administrator).', 'Gave the Administrator role %s registered capabilities it did not have (Sync administrator).', count( $grant ), 'blt-toolkit' ),
					number_format_i18n( count( $grant ) )
				),
				'details'     => array( 'granted' => $grant ),
				'actor'       => isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['granted'] = $grant;

		return $result;
	}

	// ---------- Helpers ----------

	/**
	 * The role-store mutator of a sync: adds `$grant` to `administrator`.
	 * With `$expected`, the role as re-read by the store must still give the
	 * same plan hash, or the sync is refused.
	 *
	 * @param string[] $grant    Capabilities to grant.
	 * @param string   $expected plan_hash() to check, or ''.
	 * @return callable
	 */
	private static function mutator( array $grant, $expected ) {
		return static function ( array $roles ) use ( $grant, $expected ) {
			$admin = BLT_Toolkit_Sync::ADMIN_ROLE;

			if ( ! isset( $roles[ $admin ] ) || ! is_array( $roles[ $admin ] ) ) {
				return BLT_Toolkit_Sync::no_admin();
			}

			$caps = isset( $roles[ $admin ]['capabilities'] ) && is_array( $roles[ $admin ]['capabilities'] ) ? $roles[ $admin ]['capabilities'] : array();

			if ( '' !== $expected && ! hash_equals( BLT_Toolkit_Sync::plan_hash( $grant, $caps ), $expected ) ) {
				return BLT_Toolkit_Snapshots::changed_error();
			}

			foreach ( $grant as $cap ) {
				$caps[ (string) $cap ] = true;
			}

			$roles[ $admin ]['capabilities'] = $caps;

			return $roles;
		};
	}

	/**
	 * Names a sync never grants: core's meta capability names and every role
	 * ID (a role-named key is read as a role membership).
	 *
	 * @return string[]
	 */
	private static function skip() {
		return array_merge(
			BLT_Toolkit_Capability_Catalog::core_meta_names(),
			array_keys( BLT_Toolkit_Caps::meta() ),
			array_map( 'strval', array_keys( (array) wp_roles()->roles ) )
		);
	}

	/**
	 * The Administrator role's capabilities now, or null when there is no
	 * such role.
	 *
	 * @return array|null
	 */
	private static function admin_caps() {
		$roles = wp_roles()->roles;

		if ( ! is_array( $roles ) || ! isset( $roles[ self::ADMIN_ROLE ] ) || ! is_array( $roles[ self::ADMIN_ROLE ] ) ) {
			return null;
		}

		return isset( $roles[ self::ADMIN_ROLE ]['capabilities'] ) && is_array( $roles[ self::ADMIN_ROLE ]['capabilities'] ) ? $roles[ self::ADMIN_ROLE ]['capabilities'] : array();
	}

	/**
	 * The refusal while the Roles module is not running.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error(
			'blt_toolkit_sync_unavailable',
			__( 'Sync administrator needs the Roles module, which is not running.', 'blt-toolkit' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal when there is no Administrator role.
	 *
	 * @return WP_Error
	 */
	public static function no_admin() {
		return new WP_Error(
			'blt_toolkit_no_admin_role',
			__( 'There is no Administrator role.', 'blt-toolkit' ),
			array( 'status' => 404 )
		);
	}
}
