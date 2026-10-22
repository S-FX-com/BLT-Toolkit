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
	 * single write to the roles option (the RO-3 technique).
	 *
	 * Only capabilities the role does not mention at all are added. One the
	 * owner explicitly set to "deny" stays denied: reactivating the plugin must
	 * not quietly undo a deliberate edit. SEC-14 recovery is the way back from
	 * a damaged administrator role.
	 *
	 * When something is about to change, a snapshot of the current roles is
	 * taken first (SNAP-1). On first activation the baseline was just taken
	 * from the same state, so that snapshot is skipped as a duplicate.
	 *
	 * @return bool True when the role now carries every capability (or was
	 *              left with deliberate denials), false when there is no
	 *              administrator role.
	 */
	public static function grant_admin() {
		$wp_roles = wp_roles();
		$role     = $wp_roles->get_role( 'administrator' );

		if ( ! $role instanceof WP_Role ) {
			return false;
		}

		$missing = array_diff( array_keys( self::own() ), array_keys( (array) $role->capabilities ) );

		if ( empty( $missing ) ) {
			return true;
		}

		BLT_Toolkit_Snapshots::take( 'before_save' );

		// WP_Role::add_cap() writes the whole roles option once per call. With
		// use_db off it only updates memory (both the role object and
		// $wp_roles->roles), and the single update_option() below persists it.
		$use_db           = $wp_roles->use_db;
		$wp_roles->use_db = false;

		foreach ( $missing as $cap ) {
			$role->add_cap( $cap, true );
		}

		$wp_roles->use_db = $use_db;

		if ( $use_db ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}

		return true;
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
