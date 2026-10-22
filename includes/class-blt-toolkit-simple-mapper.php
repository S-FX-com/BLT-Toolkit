<?php
/**
 * Simple view level mapping (SPEC 10.3, 10.4).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a Simple view content row's level and modifiers into capability sets
 * and back (RS-3, RS-4, RS-5, RS-13, RS-15).
 *
 * Pure: it takes plain arrays and strings and calls no WordPress function,
 * so the rules are unit-tested with known vectors (tests/test-simple-mapper.php,
 * SPEC 18). `BLT_Toolkit_Simple_Controls` gathers the arrays and draws the
 * screen; the role store writes.
 *
 * A **type map** describes one capability group (RO-14):
 *
 *     array(
 *         'caps'       => array( 'edit_posts' => 'edit_events', ... ), // P->…, a post type's cap object
 *         'marker'     => 'blt_readonly_events',  // or '' (RS-13)
 *         'taxonomies' => array(
 *             'event_cat' => array(
 *                 'label'        => 'Event Categories',
 *                 'manage_terms' => 'manage_event_terms',
 *                 'edit_terms'   => 'edit_event_terms',
 *                 'delete_terms' => 'delete_event_terms',
 *                 'assign_terms' => 'assign_event_terms',
 *                 'dedicated'    => true,
 *                 // Optional: false when assign_terms is not the
 *                 // taxonomy's own (for example `edit_posts` on a type
 *                 // that is not Posts).
 *                 'assign_dedicated' => true,
 *             ),
 *         ),
 *     )
 *
 * Only the eleven keys of the SPEC 10.4 table are read from `caps` (LEVEL_KEYS);
 * `read`, the per-item keys of a `map_meta_cap` false type and anything else
 * are ignored, so a whole cap object can be passed. A missing key adds
 * nothing (a `map_meta_cap` false type has no `edit_published_posts`).
 *
 * The rules, each enforced below:
 *
 * - Levels and their sets follow the SPEC 10.4 table exactly (TABLE).
 *   `create_posts` adds a string only when it differs from `edit_posts`;
 *   when it is the same string it collapses, because every set is a set of
 *   strings (RS-15). The same goes for any two keys that share a string.
 * - View grants `edit_posts` and the read-only marker only when the type's
 *   `create_posts` is distinct and the map names a marker (RS-13, marker());
 *   otherwise View is site-only (`read_private_posts`).
 * - Can delete is shown at Contribute, Author and Edit all (defaults on, on,
 *   off); Full control always includes deletion. Manage {taxonomy} toggles
 *   exist for dedicated taxonomies only, apply at Contribute and above, and
 *   are on by default only at Full control. A dedicated `assign_terms` is
 *   granted at Contribute and above whatever the toggles say (SPEC 10.3).
 *   Taxonomies on `manage_categories` are site-wide (Appendix A) and never
 *   part of a row.
 * - `read` (the Basics control), `exist` and `do_not_allow` are never part
 *   of a row's universe, so a level change can never take dashboard access
 *   away or grant something meaningless.
 * - detect() compares the granted strings within the universe with every
 *   level, Can delete and Manage combination (RS-15). No match is Custom.
 *   When several combinations give the identical set (a type whose
 *   `delete_posts` is the same string as its `edit_posts`, as ACF's field
 *   groups do), the one that claims the most is returned: the highest
 *   level, then Can delete on, then the most Manage toggles on. Every
 *   ability it describes is then actually granted, and saving it changes
 *   nothing. SPEC 10.4 says only what exactly one match and no match mean.
 * - apply() adds and removes only within the universe (RS-5) and changes
 *   nothing for Custom or an unknown level (RS-4: Simple never changes a
 *   Custom control unless the admin picks a state).
 */
class BLT_Toolkit_Simple_Mapper {

	/**
	 * Level identifiers (SPEC 10.3), lowest first.
	 */
	const NONE       = 'none';
	const VIEW       = 'view';
	const CONTRIBUTE = 'contribute';
	const AUTHOR     = 'author';
	const EDIT_ALL   = 'edit_all';
	const FULL       = 'full';

	/**
	 * The detected state of a row that matches no level (RS-4, RS-15). Never
	 * a level that can be applied.
	 */
	const CUSTOM = 'custom';

	/**
	 * The levels a row can be set to, lowest first.
	 */
	const LEVELS = array( 'none', 'view', 'contribute', 'author', 'edit_all', 'full' );

	/**
	 * Prefix of the read-only marker capabilities (RS-13, RS-14), the same
	 * as `BLT_Toolkit_Capability_Catalog::MARKER_PREFIX`. Written out so the
	 * read-only enforcement can use this class without loading the catalog
	 * on every request (SEC-9).
	 */
	const MARKER_PREFIX = 'blt_readonly_';

	/**
	 * The SPEC 10.4 table: `P->…` key => level => how it is granted.
	 * `y` always; `d` only when Can delete is on; `v` only when the type has
	 * a distinct `create_posts` and a marker (the † rows, RS-13).
	 */
	const TABLE = array(
		'read_private_posts'     => array(
			'view'     => 'y',
			'edit_all' => 'y',
			'full'     => 'y',
		),
		'edit_posts'             => array(
			'view'       => 'v',
			'contribute' => 'y',
			'author'     => 'y',
			'edit_all'   => 'y',
			'full'       => 'y',
		),
		'create_posts'           => array(
			'contribute' => 'y',
			'author'     => 'y',
			'edit_all'   => 'y',
			'full'       => 'y',
		),
		'edit_published_posts'   => array(
			'author'   => 'y',
			'edit_all' => 'y',
			'full'     => 'y',
		),
		'publish_posts'          => array(
			'author' => 'y',
			'full'   => 'y',
		),
		'edit_others_posts'      => array(
			'edit_all' => 'y',
			'full'     => 'y',
		),
		'edit_private_posts'     => array(
			'edit_all' => 'y',
			'full'     => 'y',
		),
		'delete_posts'           => array(
			'contribute' => 'd',
			'author'     => 'd',
			'edit_all'   => 'd',
			'full'       => 'y',
		),
		'delete_published_posts' => array(
			'author'   => 'd',
			'edit_all' => 'd',
			'full'     => 'y',
		),
		'delete_others_posts'    => array(
			'edit_all' => 'd',
			'full'     => 'y',
		),
		'delete_private_posts'   => array(
			'edit_all' => 'd',
			'full'     => 'y',
		),
	);

	/**
	 * The `P->…` keys a row reads, in table order.
	 */
	const LEVEL_KEYS = array(
		'read_private_posts',
		'edit_posts',
		'create_posts',
		'edit_published_posts',
		'publish_posts',
		'edit_others_posts',
		'edit_private_posts',
		'delete_posts',
		'delete_published_posts',
		'delete_others_posts',
		'delete_private_posts',
	);

	/**
	 * Levels that show the Can delete toggle (SPEC 10.3), with its default.
	 */
	const DELETE_DEFAULTS = array(
		'contribute' => true,
		'author'     => true,
		'edit_all'   => false,
	);

	/**
	 * Levels at which Manage {taxonomy} toggles and a dedicated
	 * `assign_terms` apply (SPEC 10.3).
	 */
	const MANAGE_LEVELS = array( 'contribute', 'author', 'edit_all', 'full' );

	/**
	 * Strings never part of a content row's universe: `read` belongs to the
	 * Basics control (Appendix A), `exist` is held by everyone and
	 * `do_not_allow` by no one.
	 */
	const NEVER = array( 'read', 'exist', 'do_not_allow' );

	/**
	 * Site-wide capabilities with a control of their own (Appendix A), plus
	 * core's administrative ones that have none. A content type that maps
	 * one of its capabilities to one of these is "Controlled by" that
	 * control (RS-11, excluded_reason() `admin_caps`).
	 */
	const SITE_CAPS = array(
		'read',
		'upload_files',
		'moderate_comments',
		'manage_categories',
		'manage_links',
		'edit_theme_options',
		'switch_themes',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'edit_files',
		'edit_dashboard',
		'manage_options',
		'update_core',
		'import',
		'export',
		'view_site_health_checks',
		'unfiltered_html',
		'unfiltered_upload',
		'list_users',
		'create_users',
		'edit_users',
		'delete_users',
		'remove_users',
		'promote_users',
		'blt_switch_users',
		'blt_change_usernames',
		'blt_change_own_username',
		'blt_edit_roles',
		'blt_edit_capabilities',
		'blt_create_roles',
		'blt_delete_roles',
		'blt_reset_roles',
		'blt_view_toolkit_log',
	);

	/**
	 * Whether a value is one of the levels a row can be set to (not Custom).
	 *
	 * @param mixed $level Level.
	 * @return bool
	 */
	public static function is_level( $level ) {
		return is_string( $level ) && in_array( $level, self::LEVELS, true );
	}

	/**
	 * Whether the Can delete toggle is shown at a level (SPEC 10.3).
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	public static function shows_can_delete( $level ) {
		return is_string( $level ) && isset( self::DELETE_DEFAULTS[ $level ] );
	}

	/**
	 * Whether Manage {taxonomy} toggles apply at a level (SPEC 10.3).
	 *
	 * @param string $level Level.
	 * @return bool
	 */
	public static function shows_manage( $level ) {
		return is_string( $level ) && in_array( $level, self::MANAGE_LEVELS, true );
	}

	/**
	 * The read-only marker capability for a capability type's plural
	 * (RS-13): `blt_readonly_{plural}`.
	 *
	 * @param string $plural Plural capability type, such as `events`.
	 * @return string
	 */
	public static function marker_name( $plural ) {
		return self::MARKER_PREFIX . (string) $plural;
	}

	/**
	 * The capability string a map gives one `P->…` key, or '' when it gives
	 * none or one that is never part of a row (NEVER).
	 *
	 * @param array  $map Type map.
	 * @param string $key Key of the cap object.
	 * @return string
	 */
	public static function cap( array $map, $key ) {
		$caps = isset( $map['caps'] ) && is_array( $map['caps'] ) ? $map['caps'] : array();

		if ( ! isset( $caps[ $key ] ) || ! is_scalar( $caps[ $key ] ) ) {
			return '';
		}

		$string = (string) $caps[ $key ];

		return self::usable( $string ) ? $string : '';
	}

	/**
	 * Whether the type's `create_posts` is a string of its own, different
	 * from `edit_posts` (RS-13; SPEC 10.4 counts it only then).
	 *
	 * @param array $map Type map.
	 * @return bool
	 */
	public static function create_distinct( array $map ) {
		$create = self::cap( $map, 'create_posts' );

		return '' !== $create && self::cap( $map, 'edit_posts' ) !== $create;
	}

	/**
	 * The marker View grants, or '' when View is site-only: the map must
	 * name a marker and the type must have a distinct `create_posts`, since
	 * BLT's read-only enforcement tests "holds the marker and lacks create"
	 * (RS-13, RS-14).
	 *
	 * @param array $map Type map.
	 * @return string
	 */
	public static function marker( array $map ) {
		$marker = isset( $map['marker'] ) && is_scalar( $map['marker'] ) ? (string) $map['marker'] : '';

		if ( '' === $marker || ! self::usable( $marker ) || ! self::create_distinct( $map ) ) {
			return '';
		}

		return $marker;
	}

	/**
	 * The Manage {taxonomy} toggles of a row: taxonomy => the distinct
	 * strings the toggle grants (its `manage_terms`, `edit_terms` and
	 * `delete_terms`), for dedicated taxonomies only, in map order. Toggles
	 * whose strings coincide move together.
	 *
	 * @param array $map Type map.
	 * @return array<string,string[]>
	 */
	public static function toggles( array $map ) {
		$out = array();

		foreach ( self::taxonomies( $map ) as $tax => $entry ) {
			if ( empty( $entry['dedicated'] ) ) {
				continue;
			}

			$strings = array();

			foreach ( array( 'manage_terms', 'edit_terms', 'delete_terms' ) as $key ) {
				$string = isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? (string) $entry[ $key ] : '';

				if ( self::usable( $string ) ) {
					$strings[ $string ] = true;
				}
			}

			if ( array() !== $strings ) {
				$out[ $tax ] = self::sorted( array_keys( $strings ) );
			}
		}

		return $out;
	}

	/**
	 * The dedicated `assign_terms` strings a row grants at Contribute and
	 * above, whatever the Manage toggles say (SPEC 10.3). A taxonomy's
	 * `assign_terms` counts when the taxonomy is dedicated, the entry does
	 * not say `assign_dedicated => false`, and the string is not one of the
	 * taxonomy's own manage, edit or delete strings (those belong to the
	 * toggle).
	 *
	 * @param array $map Type map.
	 * @return string[]
	 */
	public static function assign_caps( array $map ) {
		$toggles = self::toggles( $map );
		$out     = array();

		foreach ( self::taxonomies( $map ) as $tax => $entry ) {
			if ( empty( $entry['dedicated'] ) || ( array_key_exists( 'assign_dedicated', $entry ) && ! $entry['assign_dedicated'] ) ) {
				continue;
			}

			$string = isset( $entry['assign_terms'] ) && is_scalar( $entry['assign_terms'] ) ? (string) $entry['assign_terms'] : '';

			if ( ! self::usable( $string ) ) {
				continue;
			}

			if ( isset( $toggles[ $tax ] ) && in_array( $string, $toggles[ $tax ], true ) ) {
				continue;
			}

			$out[ $string ] = true;
		}

		return self::sorted( array_keys( $out ) );
	}

	/**
	 * The modifiers a level starts with when it is picked (SPEC 10.3): Can
	 * delete on, on and off at Contribute, Author and Edit all; on at Full
	 * control, which always includes deletion; off at No access and View.
	 * Manage toggles are on only at Full control.
	 *
	 * @param array  $map   Type map.
	 * @param string $level Level.
	 * @return array{can_delete:bool,manage:array<string,bool>}
	 */
	public static function default_mods( array $map, $level ) {
		$can_delete = self::FULL === $level || ( self::shows_can_delete( $level ) && self::DELETE_DEFAULTS[ $level ] );
		$manage     = array();

		foreach ( array_keys( self::toggles( $map ) ) as $tax ) {
			$manage[ $tax ] = self::FULL === $level;
		}

		return array(
			'can_delete' => $can_delete,
			'manage'     => $manage,
		);
	}

	/**
	 * Submitted modifiers made complete and consistent with a level: the
	 * level's defaults for anything missing; Can delete only where it is
	 * shown (Full control forces it on); Manage only at Contribute and above
	 * and only for the row's toggles. Values may be booleans or form strings
	 * (`1`, `on`, `true`, `yes`).
	 *
	 * @param array  $map   Type map.
	 * @param string $level Level.
	 * @param array  $mods  array( 'can_delete' => bool, 'manage' => array( tax => bool ) ), any part optional.
	 * @return array{can_delete:bool,manage:array<string,bool>}
	 */
	public static function normalize_mods( array $map, $level, array $mods = array() ) {
		$out = self::default_mods( $map, $level );

		if ( self::shows_can_delete( $level ) && array_key_exists( 'can_delete', $mods ) ) {
			$out['can_delete'] = self::to_bool( $mods['can_delete'] );
		}

		if ( self::shows_manage( $level ) && isset( $mods['manage'] ) && is_array( $mods['manage'] ) ) {
			foreach ( array_keys( $out['manage'] ) as $tax ) {
				if ( array_key_exists( $tax, $mods['manage'] ) ) {
					$out['manage'][ $tax ] = self::to_bool( $mods['manage'][ $tax ] );
				}
			}
		}

		return $out;
	}

	/**
	 * The capabilities a level and its modifiers grant (SPEC 10.4), as a
	 * sorted list of distinct strings. Strings shared between keys collapse.
	 * An unknown level (including Custom) grants nothing here; apply()
	 * refuses to act on one.
	 *
	 * @param array  $map   Type map.
	 * @param string $level Level.
	 * @param array  $mods  Modifiers (normalize_mods() completes them).
	 * @return string[]
	 */
	public static function caps_for( array $map, $level, array $mods = array() ) {
		if ( ! self::is_level( $level ) ) {
			return array();
		}

		$mods   = self::normalize_mods( $map, $level, $mods );
		$marker = self::marker( $map );
		$out    = array();

		foreach ( self::TABLE as $key => $levels ) {
			if ( ! isset( $levels[ $level ] ) ) {
				continue;
			}

			$how = $levels[ $level ];

			if ( 'd' === $how && ! $mods['can_delete'] ) {
				continue;
			}

			if ( 'v' === $how && '' === $marker ) {
				continue;
			}

			$string = self::cap( $map, $key );

			if ( '' !== $string ) {
				$out[ $string ] = true;
			}
		}

		if ( self::VIEW === $level && '' !== $marker ) {
			$out[ $marker ] = true;
		}

		if ( self::shows_manage( $level ) ) {
			foreach ( self::assign_caps( $map ) as $string ) {
				$out[ $string ] = true;
			}

			foreach ( self::toggles( $map ) as $tax => $strings ) {
				if ( ! empty( $mods['manage'][ $tax ] ) ) {
					foreach ( $strings as $string ) {
						$out[ $string ] = true;
					}
				}
			}
		}

		return self::sorted( array_keys( $out ) );
	}

	/**
	 * Every capability string the row controls (RS-5): the table's keys,
	 * the marker View would grant, the dedicated `assign_terms` and every
	 * toggle's strings. Sorted and distinct.
	 *
	 * @param array $map Type map.
	 * @return string[]
	 */
	public static function universe( array $map ) {
		$out = array();

		foreach ( self::LEVEL_KEYS as $key ) {
			$string = self::cap( $map, $key );

			if ( '' !== $string ) {
				$out[ $string ] = true;
			}
		}

		$marker = self::marker( $map );

		if ( '' !== $marker ) {
			$out[ $marker ] = true;
		}

		foreach ( self::assign_caps( $map ) as $string ) {
			$out[ $string ] = true;
		}

		foreach ( self::toggles( $map ) as $strings ) {
			foreach ( $strings as $string ) {
				$out[ $string ] = true;
			}
		}

		return self::sorted( array_keys( $out ) );
	}

	/**
	 * A row's state from the capabilities granted (RS-15).
	 *
	 * Within the universe, the granted strings are compared with the set of
	 * every level, both values of Can delete where it is shown, and the
	 * Manage toggles. For one level and Can delete value, the toggles that
	 * can be on are exactly those whose strings are all granted: if any
	 * choice of toggles matches, that largest choice matches too, so one
	 * comparison per level and Can delete value decides. No match is
	 * Custom. Ties are explained in the class docblock.
	 *
	 * For Custom, `mods` holds Can delete off and, per toggle, whether all
	 * of its strings are granted (for display only; Custom applies nothing).
	 *
	 * @param array $map     Type map.
	 * @param array $granted The role's capabilities: name => value (granted when not empty, as WP_User::has_cap() reads it) or a list of names.
	 * @return array{level:string,mods:array{can_delete:bool,manage:array<string,bool>}}
	 */
	public static function detect( array $map, array $granted ) {
		$held    = self::within( $map, $granted );
		$toggles = self::toggles( $map );
		$covered = array();

		foreach ( $toggles as $tax => $strings ) {
			$covered[ $tax ] = array() === array_diff( $strings, $held );
		}

		if ( array() === $held ) {
			return array(
				'level' => self::NONE,
				'mods'  => self::default_mods( $map, self::NONE ),
			);
		}

		foreach ( array_reverse( self::LEVELS ) as $level ) {
			$deletes = self::shows_can_delete( $level ) ? array( true, false ) : array( null );

			foreach ( $deletes as $can_delete ) {
				$mods = array();

				if ( null !== $can_delete ) {
					$mods['can_delete'] = $can_delete;
				}

				if ( self::shows_manage( $level ) ) {
					$mods['manage'] = $covered;
				}

				$mods = self::normalize_mods( $map, $level, $mods );

				if ( self::caps_for( $map, $level, $mods ) === $held ) {
					return array(
						'level' => $level,
						'mods'  => $mods,
					);
				}
			}
		}

		return array(
			'level' => self::CUSTOM,
			'mods'  => array(
				'can_delete' => false,
				'manage'     => $covered,
			),
		);
	}

	/**
	 * The changes that set a row to a level (RS-5): the level's set within
	 * the universe, compared with what the role grants within it. Adds are
	 * strings of the target set the role does not grant (one it holds as
	 * `false` is added, which flips the deny); removes are strings the role
	 * grants that the target set leaves out. Nothing outside the universe
	 * is ever added or removed. Custom or an unknown level changes nothing.
	 *
	 * @param array  $map     Type map.
	 * @param array  $granted The role's capabilities, as for detect().
	 * @param string $level   Level picked.
	 * @param array  $mods    Modifiers picked.
	 * @return array{add:string[],remove:string[]}
	 */
	public static function apply( array $map, array $granted, $level, array $mods = array() ) {
		if ( ! self::is_level( $level ) ) {
			return array(
				'add'    => array(),
				'remove' => array(),
			);
		}

		$target = self::caps_for( $map, $level, $mods );
		$held   = self::within( $map, $granted );

		return array(
			'add'    => self::sorted( array_diff( $target, $held ) ),
			'remove' => self::sorted( array_diff( $held, $target ) ),
		);
	}

	/**
	 * The state of a toggle that maps to several capabilities (RS-4,
	 * Appendix A, SPEC 10.6): `on` when every one is granted, `off` when
	 * none is (or the list is empty), otherwise `indeterminate`.
	 *
	 * @param string[] $caps    The toggle's capabilities.
	 * @param array    $granted The role's capabilities, as for detect().
	 * @return string `on`, `off` or `indeterminate`.
	 */
	public static function toggle_state( array $caps, array $granted ) {
		$held  = self::granted_set( $granted );
		$total = 0;
		$count = 0;

		foreach ( array_unique( array_map( 'strval', array_filter( $caps, 'is_scalar' ) ) ) as $cap ) {
			if ( '' === $cap ) {
				continue;
			}

			++$total;

			if ( isset( $held[ $cap ] ) ) {
				++$count;
			}
		}

		if ( 0 === $count ) {
			return 'off';
		}

		return $count === $total ? 'on' : 'indeterminate';
	}

	/**
	 * Why a content type cannot have levels (RS-11), or '' when it can.
	 *
	 * Every string in the type's cap object (the `read` key aside) must be
	 * dedicated to content (it contains the type's own capability_type,
	 * singular or plural) or exactly one of Posts' or Pages' strings; the
	 * same rule as the catalog's `excluded_from_levels`. Otherwise:
	 * `admin_caps` when an offending string is a site-wide capability with a
	 * control of its own (SITE_CAPS, such as `manage_options` in an ACF-style
	 * type: "Controlled by: Change settings"), `foreign_caps` for anything
	 * else (another type's or a plugin's capability). foreign_caps() lists
	 * the offending strings.
	 *
	 * @param array        $map             Type map.
	 * @param array        $posts_map       Posts' type map.
	 * @param array        $pages_map       Pages' type map.
	 * @param string|array $capability_type The type's capability_type: a string, or array( singular, plural ).
	 * @return string '', `admin_caps` or `foreign_caps`.
	 */
	public static function excluded_reason( array $map, array $posts_map, array $pages_map, $capability_type ) {
		$foreign = self::foreign_caps( $map, $posts_map, $pages_map, $capability_type );

		if ( array() === $foreign ) {
			return '';
		}

		foreach ( $foreign as $string ) {
			if ( in_array( $string, self::SITE_CAPS, true ) ) {
				return 'admin_caps';
			}
		}

		return 'foreign_caps';
	}

	/**
	 * The strings that exclude a type from levels (RS-11): key => string for
	 * each one neither dedicated to the type nor one of Posts' or Pages'
	 * strings. See excluded_reason().
	 *
	 * @param array        $map             Type map.
	 * @param array        $posts_map       Posts' type map.
	 * @param array        $pages_map       Pages' type map.
	 * @param string|array $capability_type The type's capability_type.
	 * @return array<string,string>
	 */
	public static function foreign_caps( array $map, array $posts_map, array $pages_map, $capability_type ) {
		$shared = array();

		foreach ( array( $posts_map, $pages_map ) as $other ) {
			foreach ( self::cap_strings( $other ) as $string ) {
				$shared[ $string ] = true;
			}
		}

		$words = array();

		foreach ( is_array( $capability_type ) ? array_slice( array_values( $capability_type ), 0, 2 ) : array( $capability_type ) as $word ) {
			$word = is_scalar( $word ) ? strtolower( trim( (string) $word ) ) : '';

			if ( '' !== $word ) {
				$words[] = $word;
			}
		}

		if ( is_scalar( $capability_type ) && 1 === count( $words ) ) {
			$words[] = $words[0] . 's';
		}

		$out = array();

		foreach ( self::cap_strings( $map ) as $key => $string ) {
			if ( isset( $shared[ $string ] ) ) {
				continue;
			}

			$dedicated = false;

			foreach ( $words as $word ) {
				if ( false !== strpos( strtolower( $string ), $word ) ) {
					$dedicated = true;
					break;
				}
			}

			if ( ! $dedicated ) {
				$out[ $key ] = $string;
			}
		}

		return $out;
	}

	/**
	 * The capabilities granted, as a set: from name => value (granted when
	 * the value is not empty, as `WP_User::has_cap()` reads it) or from a
	 * list of names (integer keys).
	 *
	 * @param array $granted Capabilities.
	 * @return array<string,true>
	 */
	public static function granted_set( array $granted ) {
		$out = array();

		foreach ( $granted as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$out[ (string) $value ] = true;
				}
				continue;
			}

			if ( ! empty( $value ) ) {
				$out[ (string) $key ] = true;
			}
		}

		return $out;
	}

	// ---------- Internals ----------

	/**
	 * The granted strings within a row's universe, sorted.
	 *
	 * @param array $map     Type map.
	 * @param array $granted Capabilities, as for detect().
	 * @return string[]
	 */
	private static function within( array $map, array $granted ) {
		$held = self::granted_set( $granted );
		$out  = array();

		foreach ( self::universe( $map ) as $string ) {
			if ( isset( $held[ $string ] ) ) {
				$out[] = $string;
			}
		}

		return self::sorted( $out );
	}

	/**
	 * A map's taxonomy entries, as arrays keyed by taxonomy name.
	 *
	 * @param array $map Type map.
	 * @return array<string,array>
	 */
	private static function taxonomies( array $map ) {
		$out = array();

		if ( ! isset( $map['taxonomies'] ) || ! is_array( $map['taxonomies'] ) ) {
			return $out;
		}

		foreach ( $map['taxonomies'] as $tax => $entry ) {
			if ( is_array( $entry ) ) {
				$out[ (string) $tax ] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Every capability string of a map's cap object, key => string, for
	 * RS-11: the `read` key is left out when it is plain `read` (as in the
	 * catalog), and so are empty values, `exist` and `do_not_allow`.
	 *
	 * @param array $map Type map.
	 * @return array<string,string>
	 */
	private static function cap_strings( array $map ) {
		$caps = isset( $map['caps'] ) && is_array( $map['caps'] ) ? $map['caps'] : array();
		$out  = array();

		foreach ( $caps as $key => $string ) {
			if ( ! is_scalar( $string ) ) {
				continue;
			}

			$key    = (string) $key;
			$string = (string) $string;

			if ( '' === $string || 'exist' === $string || 'do_not_allow' === $string ) {
				continue;
			}

			if ( 'read' === $key && 'read' === $string ) {
				continue;
			}

			$out[ $key ] = $string;
		}

		return $out;
	}

	/**
	 * Whether a string can be part of a row's universe (not empty, not in
	 * NEVER).
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	private static function usable( $cap ) {
		return '' !== $cap && ! in_array( $cap, self::NEVER, true );
	}

	/**
	 * Distinct strings, sorted, reindexed.
	 *
	 * @param array $strings Strings.
	 * @return string[]
	 */
	private static function sorted( array $strings ) {
		$strings = array_values( array_unique( array_map( 'strval', $strings ) ) );
		sort( $strings, SORT_STRING );

		return $strings;
	}

	/**
	 * A modifier value from a boolean or a form string.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 0 !== $value;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'on', 'true', 'yes' ), true );
		}

		return false;
	}
}
