<?php
/**
 * The plugin's capabilities, risk tiers and lockout-critical set.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities (SPEC 5.1), risk tiers (5.4) and the lockout-critical set
 * (SEC-12).
 *
 * Tier lists accept a trailing `*` as a prefix wildcard (`edit_others_*`), so
 * every content type's "others" capabilities are Elevated without being
 * listed one by one.
 */
class BLT_Toolkit_Caps {

	/**
	 * Tier names, highest risk first.
	 */
	const CRITICAL = 'critical';
	const ELEVATED = 'elevated';
	const STANDARD = 'standard';

	/**
	 * Tiers resolved for this request (filtered once, then reused).
	 *
	 * @var array|null
	 */
	private static $tiers = null;

	/**
	 * The plugin's own primitive capabilities and their tiers (SPEC 5.1).
	 *
	 * All of them go to the `administrator` role on activation and to nobody
	 * else unless the owner grants them.
	 *
	 * @return array<string,string> Capability => tier.
	 */
	public static function own() {
		return array(
			'blt_switch_users'        => self::CRITICAL,
			'blt_change_usernames'    => self::CRITICAL,
			'blt_change_own_username' => self::STANDARD,
			'blt_edit_roles'          => self::CRITICAL,
			'blt_edit_capabilities'   => self::CRITICAL,
			'blt_create_roles'        => self::CRITICAL,
			'blt_delete_roles'        => self::CRITICAL,
			'blt_reset_roles'         => self::CRITICAL,
			'blt_view_toolkit_log'    => self::ELEVATED,
		);
	}

	/**
	 * Meta capabilities, mapped in `map_meta_cap` and never granted directly
	 * (SPEC 5.1), with the primitives each one can map to. The Guard uses the
	 * keys as its short-circuit set (SEC-9).
	 *
	 * @return array<string,string[]>
	 */
	public static function meta() {
		return array(
			'blt_switch_to_user'  => array( 'blt_switch_users' ),
			'blt_change_username' => array( 'blt_change_usernames', 'blt_change_own_username' ),
		);
	}

	/**
	 * Grant every one of the plugin's capabilities to `administrator` in a
	 * single write to the roles option, through the role store
	 * (BLT_Toolkit_Role_Store::transaction(): the roles read again, a
	 * snapshot first, one write, the before and after hooks, a log row).
	 *
	 * Only capabilities the role does not mention at all are added. One the
	 * owner explicitly set to "deny" stays denied: reactivating the plugin must
	 * not quietly undo a deliberate edit. SEC-14 recovery is the way back from
	 * a damaged administrator role.
	 *
	 * When something is about to change, a snapshot of the current roles is
	 * taken first (SNAP-1). On first activation the baseline was just taken
	 * from the same state, so that snapshot is skipped as a duplicate. When
	 * the snapshot cannot be stored, nothing is written (the decided policy
	 * for every BLT write to the roles option; see BLT_Toolkit_Snapshots).
	 * The write is logged as `toolkit` / `administrator_synced` with the
	 * capabilities granted, the diff and the snapshot's ID (LOG-1), so a
	 * reactivation that gives back a capability the owner had removed shows
	 * on the Activity screen.
	 *
	 * Activation runs before any module boots and, from WP-CLI, with nobody
	 * signed in, so `activation` is a system write: only SEC-12 applies. Any
	 * other context (`sync`, the Tools action) is checked by the Guard as the
	 * current user.
	 *
	 * @param string $context `activation` (default) or `sync`; passed to the
	 *                        `blt_toolkit_*_roles_save` hooks and logged.
	 * @return bool|WP_Error True when the role now carries every capability
	 *                       (or was left with deliberate denials), false when
	 *                       there is no administrator role,
	 *                       `blt_toolkit_snapshot_failed` when the snapshot
	 *                       could not be stored and nothing was changed, or
	 *                       another refusal from the role store.
	 */
	public static function grant_admin( $context = 'activation' ) {
		$wp_roles = wp_roles();
		$role     = $wp_roles->get_role( 'administrator' );

		if ( ! $role instanceof WP_Role ) {
			return false;
		}

		$missing = array_diff( array_keys( self::own() ), array_keys( (array) $role->capabilities ) );

		if ( empty( $missing ) ) {
			return true;
		}

		// Roles defined in code ($wp_user_roles) cannot be written: grant in
		// memory for this request, as before the role store existed.
		if ( ! $wp_roles->use_db ) {
			return self::grant_in_memory( $wp_roles, $role, $missing );
		}

		$granted = array();
		$context = 'sync' === $context ? 'sync' : 'activation';
		$result  = BLT_Toolkit_Role_Store::transaction(
			static function ( array $roles ) use ( &$granted ) {
				if ( ! isset( $roles['administrator'] ) || ! is_array( $roles['administrator'] ) ) {
					return new WP_Error( 'blt_toolkit_no_admin_role', __( 'There is no Administrator role.', 'blt-toolkit' ), array( 'status' => 404 ) );
				}

				$caps    = isset( $roles['administrator']['capabilities'] ) && is_array( $roles['administrator']['capabilities'] ) ? $roles['administrator']['capabilities'] : array();
				$granted = array_values( array_diff( array_keys( BLT_Toolkit_Caps::own() ), array_map( 'strval', array_keys( $caps ) ) ) );

				foreach ( $granted as $cap ) {
					$caps[ $cap ] = true;
				}

				$roles['administrator']['capabilities'] = $caps;

				return $roles;
			},
			array(
				'context'   => $context,
				'system'    => 'activation' === $context,
				'reason'    => 'before_save',
				'module'    => 'toolkit',
				'action'    => 'administrator_synced',
				'object_id' => 'administrator',
				'describe'  => static function () use ( &$granted ) {
					return array(
						'summary' => BLT_Toolkit_Caps::grant_summary( $granted ),
						'details' => array( 'granted' => $granted ),
					);
				},
			)
		);

		if ( is_wp_error( $result ) ) {
			return 'blt_toolkit_no_admin_role' === $result->get_error_code() ? false : $result;
		}

		return true;
	}

	/**
	 * The log summary of a grant.
	 *
	 * @param string[] $granted Capabilities given to `administrator`.
	 * @return string
	 */
	public static function grant_summary( array $granted ) {
		return sprintf(
			/* translators: %s: number of capabilities. */
			_n( 'Gave the Administrator role %s BLT Toolkit capability it did not have.', 'Gave the Administrator role %s BLT Toolkit capabilities it did not have.', count( $granted ), 'blt-toolkit' ),
			number_format_i18n( count( $granted ) )
		);
	}

	/**
	 * grant_admin() for roles defined in code: nothing can be written, so the
	 * capabilities are granted in memory for this request (after a snapshot,
	 * as every grant takes one) and logged.
	 *
	 * @param WP_Roles $wp_roles Roles.
	 * @param WP_Role  $role     The administrator role.
	 * @param string[] $missing  Capabilities to grant.
	 * @return true|WP_Error
	 */
	private static function grant_in_memory( WP_Roles $wp_roles, WP_Role $role, array $missing ) {
		$snapshot_id = (int) BLT_Toolkit_Snapshots::take( 'before_save' );

		if ( 0 === $snapshot_id ) {
			return new WP_Error( 'blt_toolkit_snapshot_failed', __( 'A copy of the roles could not be saved first, so no role was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		$before = $wp_roles->roles;

		// use_db is already off for roles defined in code, so add_cap() only
		// changes memory (both the role object and $wp_roles->roles).
		foreach ( $missing as $cap ) {
			$role->add_cap( $cap, true );
		}

		self::log_grant( array_values( $missing ), $before, $wp_roles->roles, $snapshot_id );

		return true;
	}

	/**
	 * Record grant_admin()'s write in the activity log (LOG-1), with the diff
	 * and the snapshot taken first, as every roles write is.
	 *
	 * @param string[] $granted     Capabilities given to `administrator`.
	 * @param array    $before      Roles before the write.
	 * @param array    $after       Roles after it.
	 * @param int      $snapshot_id The `before_save` snapshot (the baseline on a first activation).
	 * @return void
	 */
	private static function log_grant( array $granted, array $before, array $after, $snapshot_id ) {
		if ( ! is_callable( array( 'BLT_Toolkit_Log', 'record' ) ) ) {
			return;
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'administrator_synced',
				'object_type' => 'role',
				'object_id'   => 'administrator',
				'summary'     => self::grant_summary( $granted ),
				'details'     => array(
					'granted'       => $granted,
					'context'       => 'activation',
					'roles_in_code' => true,
					'diff'          => BLT_Toolkit_Role_Diff::diff( $before, $after ),
				),
				'snapshot_id' => (int) $snapshot_id,
				'source'      => '',
			)
		);
	}

	/**
	 * Risk tiers (SPEC 5.4), filterable through `blt_toolkit_risky_caps`.
	 *
	 * Standard is "everything else", so it has no list. A capability matching
	 * both lists is Critical.
	 *
	 * @return array{critical:string[],elevated:string[]} Capability names and
	 *                                                    `prefix_*` patterns.
	 */
	public static function tiers() {
		if ( null !== self::$tiers ) {
			return self::$tiers;
		}

		$critical = array(
			'manage_options',
			'activate_plugins',
			'install_plugins',
			'update_plugins',
			'delete_plugins',
			'edit_plugins',
			'install_themes',
			'update_themes',
			'delete_themes',
			'edit_themes',
			'update_core',
			'unfiltered_html',
			'unfiltered_upload',
			'edit_users',
			'create_users',
			'delete_users',
			'remove_users',
			'promote_users',
		);

		$elevated = array(
			'list_users',
			'export',
			'import',
			'edit_theme_options',
			'switch_themes',
			'moderate_comments',
			'manage_categories',
			'edit_others_*',
			'delete_others_*',
		);

		foreach ( self::own() as $cap => $tier ) {
			if ( self::CRITICAL === $tier ) {
				$critical[] = $cap;
			} elseif ( self::ELEVATED === $tier ) {
				$elevated[] = $cap;
			}
		}

		/**
		 * Filter the risk tiers.
		 *
		 * @param array $tiers {
		 *     @type string[] $critical Capabilities (or `prefix_*` patterns) that can take over the site or its users.
		 *     @type string[] $elevated Capabilities (or patterns) that reach other people's content or site structure.
		 * }
		 */
		$filtered = apply_filters(
			'blt_toolkit_risky_caps',
			array(
				self::CRITICAL => $critical,
				self::ELEVATED => $elevated,
			)
		);

		$filtered = is_array( $filtered ) ? $filtered : array();

		self::$tiers = array(
			self::CRITICAL => self::clean_list( isset( $filtered[ self::CRITICAL ] ) ? $filtered[ self::CRITICAL ] : array() ),
			self::ELEVATED => self::clean_list( isset( $filtered[ self::ELEVATED ] ) ? $filtered[ self::ELEVATED ] : array() ),
		);

		return self::$tiers;
	}

	/**
	 * The tier of one capability.
	 *
	 * @param string $cap Capability name.
	 * @return string 'critical', 'elevated' or 'standard'.
	 */
	public static function tier( $cap ) {
		$cap   = (string) $cap;
		$tiers = self::tiers();

		foreach ( array( self::CRITICAL, self::ELEVATED ) as $tier ) {
			foreach ( $tiers[ $tier ] as $entry ) {
				if ( self::matches( $cap, $entry ) ) {
					return $tier;
				}
			}
		}

		return self::STANDARD;
	}

	/**
	 * Capabilities that can never be removed from `administrator` (SEC-12),
	 * filterable through `blt_toolkit_lockout_critical_caps`.
	 *
	 * The filter can add to the set but not shrink it: this list is what
	 * keeps a site from locking out its own administrators, and SEC-14
	 * recovery re-grants exactly these.
	 *
	 * @return string[]
	 */
	public static function lockout_critical() {
		$base = array(
			'read',
			'manage_options',
			'list_users',
			'edit_users',
			'promote_users',
			'activate_plugins',
			'blt_edit_roles',
			'blt_edit_capabilities',
		);

		/**
		 * Filter the capabilities that can never be removed from administrator.
		 *
		 * @param string[] $caps Lockout-critical capabilities. Entries can be added; the built-in ones always stay.
		 */
		$filtered = apply_filters( 'blt_toolkit_lockout_critical_caps', $base );

		return array_values( array_unique( array_merge( $base, self::clean_list( $filtered, false ) ) ) );
	}

	/**
	 * Forget the filtered tiers (tests, or after adding a filter late).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$tiers = null;
	}

	/**
	 * Whether a capability matches a tier entry (exact, or `prefix_*`).
	 *
	 * @param string $cap   Capability name.
	 * @param string $entry Capability name or pattern.
	 * @return bool
	 */
	private static function matches( $cap, $entry ) {
		if ( '*' === substr( $entry, -1 ) ) {
			$prefix = substr( $entry, 0, -1 );

			return '' !== $prefix && 0 === strpos( $cap, $prefix ) && strlen( $cap ) > strlen( $prefix );
		}

		return $cap === $entry;
	}

	/**
	 * Unique, non-empty capability names from a filtered value.
	 *
	 * @param mixed $caps          Filtered value.
	 * @param bool  $allow_pattern Whether a trailing `*` wildcard is allowed.
	 * @return string[]
	 */
	private static function clean_list( $caps, $allow_pattern = true ) {
		if ( ! is_array( $caps ) ) {
			return array();
		}

		$out = array();

		foreach ( $caps as $cap ) {
			if ( ! is_string( $cap ) ) {
				continue;
			}

			$cap = trim( $cap );

			if ( '' === $cap || '*' === $cap || ( ! $allow_pattern && false !== strpos( $cap, '*' ) ) ) {
				continue;
			}

			$out[ $cap ] = true;
		}

		return array_keys( $out );
	}
}
