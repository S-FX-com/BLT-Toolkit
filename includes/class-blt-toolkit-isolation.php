<?php
/**
 * Separate permissions for a custom post type ("isolation", RS-30 to RS-35).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives a custom post type that shares Posts' (or Pages') capabilities its
 * own, without changing anyone's access on day one (SPEC 10.5).
 *
 * - RS-30 candidates(), plan() and isolate(): a candidate is a post type
 *   whose `capability_type` is `post` or `page` and whose capability strings
 *   equal Posts' or Pages' exactly, that is not a core type and not in
 *   `blt_toolkit_isolation_excluded_types` (default `bricks_template`,
 *   RS-35). The admin confirms a singular and a plural (default: the type
 *   name and name + `s`), validated so no new string collides with a
 *   capability another type, a taxonomy, core or BLT Toolkit uses. The dry
 *   run shows, for every role and every user with Posts or Pages
 *   capabilities of their own, the capabilities they will receive: each
 *   `*_posts` capability they hold (granted or denied) maps to the matching
 *   `*_{plural}` one, and `edit_posts` also to `create_{plural}`, so
 *   effective access is unchanged. On confirm the roles are seeded in one
 *   write through BLT_Toolkit_Role_Store::transaction() (snapshot reason
 *   `before_isolation`, RO-3, SNAP-1), users through
 *   BLT_Toolkit_User_Caps::save(), the type is stored under
 *   `roles.isolated_post_types` with `since`, the change is logged and
 *   `blt_toolkit_post_type_isolated` fires.
 * - RS-31 and RS-33 (the filter, verification, the stored list and the
 *   state option) live in BLT_Toolkit_Isolation_Core, which every request
 *   loads; this class adds the flows and is loaded only where they run
 *   (SEC-9). Failures are kept in memory for the request (failed(), which
 *   Simple view uses to lock the level selector), shown in an admin notice
 *   (admin_notice()), and stored in the `blt_toolkit_isolation_state`
 *   option for the Site Health test (stored_failures()).
 * - RS-32 revert() and cleanup(): reverting takes the type off the list (it
 *   shares Posts again on the next request) and fires
 *   `blt_toolkit_post_type_shared`. It writes no role, but every role and
 *   user it gives Posts' (or Pages') access back to is checked with the
 *   Guard first for someone who is not a toolkit admin (SEC-2, SEC-3,
 *   SEC-5), as isolating is. A separate dry-run step removes the
 *   now-unused `*_{plural}` and marker capabilities from roles and users,
 *   and only while nothing registers them (RO-11).
 * - RS-34 plugin_row(): the warning under BLT Toolkit on the Plugins screen
 *   while any type is isolated (and while a role holds a read-only marker
 *   for a type whose `create_posts` is distinct on its own, since RS-14
 *   enforcement also stops on deactivation, SPEC 14.6).
 *
 * The rules that need no WordPress are pure and unit-tested in
 * tests/test-isolation.php: candidate_reason(), default_names(),
 * new_strings(), collisions(), seed_map(), seed_changes(), access_diff(),
 * cleanup_strings() and plan_hash() here, and those listed in
 * BLT_Toolkit_Isolation_Core.
 */
class BLT_Toolkit_Isolation extends BLT_Toolkit_Isolation_Core {

	/**
	 * Snapshot reason of every isolation write (SPEC 14.3).
	 */
	const REASON = 'before_isolation';

	/**
	 * Context passed to the role store's hooks and log.
	 */
	const CONTEXT = 'isolation';

	/**
	 * Types never offered by default (RS-35): Bricks templates. See
	 * tasks/lessons.md (Phase 5): Bricks gates templates through its own
	 * builder access and `edit_posts`, so separate capabilities would not
	 * control them and Simple view would claim a control it does not have.
	 */
	const DEFAULT_EXCLUDED = array( 'bricks_template' );

	// ---------- Reading ----------

	/**
	 * The type map of an isolated type for BLT_Toolkit_Simple_Mapper (its
	 * registered capability strings and its read-only marker), or an empty
	 * array when the type is not isolated or not registered. Taxonomies are
	 * left to the caller.
	 *
	 * @param string $type Post type.
	 * @return array
	 */
	public static function map_for( $type ) {
		$type = (string) $type;

		if ( ! self::is_isolated( $type ) || ! post_type_exists( $type ) ) {
			return array();
		}

		$isolated = self::isolated();
		$object   = get_post_type_object( $type );
		$caps     = array();

		foreach ( self::LEVEL_KEYS as $key ) {
			if ( isset( $object->cap->$key ) ) {
				$caps[ $key ] = (string) $object->cap->$key;
			}
		}

		$create = isset( $caps['create_posts'] ) ? $caps['create_posts'] : '';
		$edit   = isset( $caps['edit_posts'] ) ? $caps['edit_posts'] : '';

		return array(
			'caps'       => $caps,
			'marker'     => '' !== $create && $create !== $edit ? self::MARKER_PREFIX . $isolated[ $type ]['plural'] : '',
			'taxonomies' => array(),
		);
	}

	/**
	 * The capability type an isolated type shared before (`post` or
	 * `page`): seen by filter_args() this request, else recorded when it
	 * was isolated, else `post`.
	 *
	 * @param string $type Post type.
	 * @return string
	 */
	public static function shares( $type ) {
		$type = (string) $type;

		if ( isset( self::$shares[ $type ] ) ) {
			return self::$shares[ $type ];
		}

		$state = self::state();

		return isset( $state['shares'][ $type ] ) && in_array( $state['shares'][ $type ], self::SOURCES, true ) ? $state['shares'][ $type ] : 'post';
	}

	/**
	 * Post types never offered separate permissions (RS-30, RS-35).
	 *
	 * @return string[]
	 */
	public static function excluded_types() {
		/**
		 * Filter the post types that are never offered "Give {Type} separate
		 * permissions", for example a type whose plugin checks `edit_posts`
		 * directly instead of the type's own capability (RS-35).
		 *
		 * @param string[] $types Post type names. Default `array( 'bricks_template' )`.
		 */
		$types = apply_filters( 'blt_toolkit_isolation_excluded_types', self::DEFAULT_EXCLUDED );

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', array_filter( $types, 'is_scalar' ) ) ) ) : self::DEFAULT_EXCLUDED;
	}

	/**
	 * Post types that may be given separate permissions now (RS-30): type =>
	 * array( 'type', 'label', 'singular', 'plural', 'shares' ), where
	 * `singular` and `plural` are the defaults. Isolated types are never
	 * candidates, even when their separate permissions failed (RS-33).
	 *
	 * @return array<string,array{type:string,label:string,singular:string,plural:string,shares:string}>
	 */
	public static function candidates() {
		$posts = get_post_type_object( 'post' );
		$pages = get_post_type_object( 'page' );

		if ( ! $posts || ! $pages ) {
			return array();
		}

		$posts_cap = (array) $posts->cap;
		$pages_cap = (array) $pages->cap;
		$excluded  = self::excluded_types();
		$out       = array();

		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			$name = (string) $name;

			if ( ! $object instanceof WP_Post_Type || self::is_isolated( $name ) ) {
				continue;
			}

			$info = self::type_info( $object );

			if ( '' !== self::candidate_reason( $info, $posts_cap, $pages_cap, $excluded ) ) {
				continue;
			}

			$names = self::default_names( $name );

			$out[ $name ] = array(
				'type'     => $name,
				'label'    => isset( $object->labels->name ) ? (string) $object->labels->name : $name,
				'singular' => $names['singular'],
				'plural'   => $names['plural'],
				'shares'   => $info['capability_type'],
			);
		}

		return $out;
	}

	/**
	 * A post type object as the plain array candidate_reason() reads.
	 *
	 * @param WP_Post_Type $post_type Post type.
	 * @return array{name:string,builtin:bool,capability_type:string,cap:array}
	 */
	private static function type_info( WP_Post_Type $post_type ) {
		$type = $post_type->capability_type;

		if ( is_array( $type ) ) {
			$type = array_values( $type );
			$type = isset( $type[0] ) ? $type[0] : '';
		}

		return array(
			'name'            => (string) $post_type->name,
			'builtin'         => (bool) $post_type->_builtin,
			'capability_type' => is_scalar( $type ) ? (string) $type : '',
			'cap'             => (array) $post_type->cap,
		);
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * Why a post type cannot be given separate permissions (RS-30), or ''
	 * when it can. Pure.
	 *
	 * - `core`: a type WordPress registers (`_builtin`).
	 * - `excluded`: listed in `blt_toolkit_isolation_excluded_types`.
	 * - `own_type`: its capability type is not `post` or `page`.
	 * - `overrides`: its capability strings differ from Posts' or Pages'
	 *   (a `capabilities` override, or `map_meta_cap` off).
	 *
	 * @param array $type      array( 'name', 'builtin', 'capability_type', 'cap' ).
	 * @param array $posts_cap Posts' cap object as an array.
	 * @param array $pages_cap Pages' cap object as an array.
	 * @param array $excluded  Excluded type names.
	 * @return string
	 */
	public static function candidate_reason( array $type, array $posts_cap, array $pages_cap, array $excluded ) {
		$name = isset( $type['name'] ) ? (string) $type['name'] : '';

		if ( ! empty( $type['builtin'] ) ) {
			return 'core';
		}

		if ( in_array( $name, array_map( 'strval', $excluded ), true ) ) {
			return 'excluded';
		}

		$base = isset( $type['capability_type'] ) && is_scalar( $type['capability_type'] ) ? (string) $type['capability_type'] : '';

		if ( ! in_array( $base, self::SOURCES, true ) ) {
			return 'own_type';
		}

		$cap   = self::strings( isset( $type['cap'] ) ? (array) $type['cap'] : array() );
		$share = self::strings( 'page' === $base ? $pages_cap : $posts_cap );

		return array() !== $share && $cap === $share ? '' : 'overrides';
	}

	/**
	 * The default singular and plural for a type: its name, and its name
	 * plus `s` (RS-30). Pure.
	 *
	 * @param string $type Post type.
	 * @return array{singular:string,plural:string}
	 */
	public static function default_names( $type ) {
		$type = (string) $type;

		return array(
			'singular' => $type,
			'plural'   => $type . 's',
		);
	}

	/**
	 * Every capability string isolation brings in: the new cap object's
	 * values except `read`, plus the read-only marker, unique and sorted.
	 * Pure.
	 *
	 * @param array  $new_cap New cap object (caps_from_args()).
	 * @param string $plural  Plural, for the marker.
	 * @return string[]
	 */
	public static function new_strings( array $new_cap, $plural ) {
		$out = array();

		foreach ( $new_cap as $value ) {
			$value = (string) $value;

			if ( '' !== $value && 'read' !== $value ) {
				$out[ $value ] = true;
			}
		}

		$out[ self::MARKER_PREFIX . (string) $plural ] = true;

		$out = array_map( 'strval', array_keys( $out ) );

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * The strings that collide (RS-30 step 1): new strings already used by
	 * something else, plus any string the new cap object gives to two keys
	 * (a per-item name equal to a primitive). Pure.
	 *
	 * @param array $new_cap New cap object.
	 * @param array $taken   Strings in use elsewhere (name => anything, or a list).
	 * @param string $plural Plural, for the marker.
	 * @return string[]
	 */
	public static function collisions( array $new_cap, array $taken, $plural ) {
		$taken = self::as_set( $taken );
		$seen  = array();
		$out   = array();

		foreach ( $new_cap as $value ) {
			$value = (string) $value;

			if ( 'read' === $value || '' === $value ) {
				continue;
			}

			if ( isset( $seen[ $value ] ) ) {
				$out[ $value ] = true;
			}

			$seen[ $value ] = true;
		}

		foreach ( self::new_strings( $new_cap, $plural ) as $value ) {
			if ( isset( $taken[ $value ] ) ) {
				$out[ $value ] = true;
			}
		}

		$out = array_map( 'strval', array_keys( $out ) );

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * How capabilities map when a type is isolated (RS-30 step 2): for each
	 * SPEC 10.4 key whose string changes, the old string => the new strings
	 * it gives. With Posts' strings, `edit_posts` gives `edit_{plural}` and
	 * `create_{plural}`. Pure.
	 *
	 * @param array $old_cap The type's cap object now (Posts' or Pages').
	 * @param array $new_cap Its cap object once isolated.
	 * @return array<string,string[]>
	 */
	public static function seed_map( array $old_cap, array $new_cap ) {
		$out = array();

		foreach ( self::LEVEL_KEYS as $key ) {
			if ( ! isset( $old_cap[ $key ], $new_cap[ $key ] ) ) {
				continue;
			}

			$from = (string) $old_cap[ $key ];
			$to   = (string) $new_cap[ $key ];

			if ( '' === $from || '' === $to || $from === $to || 'read' === $to ) {
				continue;
			}

			if ( ! isset( $out[ $from ] ) ) {
				$out[ $from ] = array();
			}

			if ( ! in_array( $to, $out[ $from ], true ) ) {
				$out[ $from ][] = $to;
			}
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * The change that keeps one capability set's access unchanged once the
	 * type is isolated. Pure.
	 *
	 * Every new string takes the value of the old string it maps from
	 * (granted or denied) when the set mentions that; a new string that the
	 * set mentions while it mentions none of its old strings is removed,
	 * because it would start to grant (or deny) access it does not give
	 * today. Strings outside the map are never touched.
	 *
	 * @param array $caps Capability => value (a role's capabilities, or a user's own).
	 * @param array $map  seed_map().
	 * @return array{add:array<string,bool>,remove:string[],from:array<string,string>}
	 */
	public static function seed_changes( array $caps, array $map ) {
		$target = array();
		$from   = array();
		$all    = array();

		foreach ( $map as $old => $news ) {
			$old = (string) $old;

			foreach ( (array) $news as $new ) {
				$new = (string) $new;

				$all[ $new ] = true;

				if ( ! array_key_exists( $old, $caps ) ) {
					continue;
				}

				$value = (bool) $caps[ $old ];

				$target[ $new ] = isset( $target[ $new ] ) ? ( $target[ $new ] || $value ) : $value;

				if ( ! isset( $from[ $new ] ) ) {
					$from[ $new ] = $old;
				}
			}
		}

		$add    = array();
		$remove = array();

		foreach ( array_keys( $all ) as $new ) {
			$new = (string) $new;
			$has = array_key_exists( $new, $caps );

			if ( array_key_exists( $new, $target ) ) {
				if ( ! $has || (bool) $caps[ $new ] !== $target[ $new ] ) {
					$add[ $new ] = $target[ $new ];
				}
			} elseif ( $has ) {
				$remove[] = $new;
			}
		}

		ksort( $add, SORT_STRING );
		sort( $remove, SORT_STRING );
		ksort( $from, SORT_STRING );

		return array(
			'add'    => $add,
			'remove' => $remove,
			'from'   => array_intersect_key( $from, $add ),
		);
	}

	/**
	 * What reverting changes for one capability set (RS-32): the old
	 * (shared) strings it grants for keys where it does not grant the
	 * isolated string (`gains`), and the isolated strings it grants where it
	 * does not grant the shared one (`loses`). Pure.
	 *
	 * @param array $caps    Capability => value (effective capabilities, or a role's).
	 * @param array $old_cap The cap object the type returns to.
	 * @param array $new_cap The cap object it has while isolated.
	 * @return array{gains:string[],loses:string[]}
	 */
	public static function access_diff( array $caps, array $old_cap, array $new_cap ) {
		$gains = array();
		$loses = array();

		foreach ( self::LEVEL_KEYS as $key ) {
			if ( ! isset( $old_cap[ $key ], $new_cap[ $key ] ) ) {
				continue;
			}

			$old = (string) $old_cap[ $key ];
			$new = (string) $new_cap[ $key ];

			if ( $old === $new ) {
				continue;
			}

			$has_old = ! empty( $caps[ $old ] );
			$has_new = ! empty( $caps[ $new ] );

			if ( $has_old && ! $has_new ) {
				$gains[ $old ] = true;
			} elseif ( $has_new && ! $has_old ) {
				$loses[ $new ] = true;
			}
		}

		$gains = array_map( 'strval', array_keys( $gains ) );
		$loses = array_map( 'strval', array_keys( $loses ) );

		sort( $gains, SORT_STRING );
		sort( $loses, SORT_STRING );

		return array(
			'gains' => $gains,
			'loses' => $loses,
		);
	}

	/**
	 * The strings a reverted type left behind that RS-32's cleanup may
	 * remove: its isolated strings and marker, less any still registered by
	 * something (RO-11: only orphans). Pure.
	 *
	 * @param array $new_cap    The cap object it had while isolated.
	 * @param string $plural    Its plural.
	 * @param array $registered Strings registered now (name => anything, or a list).
	 * @return string[]
	 */
	public static function cleanup_strings( array $new_cap, $plural, array $registered ) {
		$registered = self::as_set( $registered );
		$out        = array();

		foreach ( self::new_strings( $new_cap, $plural ) as $value ) {
			if ( ! isset( $registered[ $value ] ) ) {
				$out[] = $value;
			}
		}

		return $out;
	}

	/**
	 * The lock token of a plan (the RO-2 rule for isolation): SHA-256 over
	 * the type, the names and every role's and user's change, so a confirm
	 * whose roles or users changed since the dry run is refused. Pure.
	 *
	 * @param array $plan array( 'type', 'singular', 'plural', 'roles', 'users' ).
	 * @return string
	 */
	public static function plan_hash( array $plan ) {
		$roles = array();
		$users = array();

		foreach ( isset( $plan['roles'] ) ? (array) $plan['roles'] : array() as $role => $change ) {
			$roles[ (string) $role ] = array(
				'add'    => isset( $change['add'] ) ? (array) $change['add'] : array(),
				'remove' => isset( $change['remove'] ) ? array_values( (array) $change['remove'] ) : array(),
			);
		}

		foreach ( isset( $plan['users'] ) ? (array) $plan['users'] : array() as $id => $change ) {
			$users[ (string) $id ] = array(
				'add'    => isset( $change['add'] ) ? (array) $change['add'] : array(),
				'remove' => isset( $change['remove'] ) ? array_values( (array) $change['remove'] ) : array(),
				'hash'   => isset( $change['hash'] ) ? (string) $change['hash'] : '',
			);
		}

		ksort( $roles, SORT_STRING );
		ksort( $users, SORT_STRING );

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'type'     => isset( $plan['type'] ) ? (string) $plan['type'] : '',
					'singular' => isset( $plan['singular'] ) ? (string) $plan['singular'] : '',
					'plural'   => isset( $plan['plural'] ) ? (string) $plan['plural'] : '',
					'strings'  => isset( $plan['strings'] ) ? array_values( (array) $plan['strings'] ) : array(),
					'roles'    => $roles,
					'users'    => $users,
				)
			)
		);
	}

	/**
	 * Role changes for every role in a roles array (seed_changes() per role),
	 * only the roles with something to change. Pure.
	 *
	 * @param array $roles Roles array (`$wp_roles->roles`).
	 * @param array $map   seed_map().
	 * @return array<string,array{add:array<string,bool>,remove:string[],from:array<string,string>}>
	 */
	public static function role_seed( array $roles, array $map ) {
		$out = array();

		foreach ( $roles as $id => $role ) {
			$caps   = is_array( $role ) && isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$change = self::seed_changes( $caps, $map );

			if ( array() !== $change['add'] || array() !== $change['remove'] ) {
				$out[ (string) $id ] = $change;
			}
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * A list or a set as a set (name => true). Pure.
	 *
	 * @param array $values List of names, or name => anything.
	 * @return array<string,bool>
	 */
	private static function as_set( array $values ) {
		$out = array();

		foreach ( $values as $key => $value ) {
			if ( is_int( $key ) && is_scalar( $value ) ) {
				$out[ (string) $value ] = true;
			} else {
				$out[ (string) $key ] = true;
			}
		}

		return $out;
	}

	/**
	 * A cap object as sorted key => string pairs, for comparing. Pure.
	 *
	 * @param array $cap Cap object as an array.
	 * @return array<string,string>
	 */
	private static function strings( array $cap ) {
		$out = array();

		foreach ( $cap as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $key ] = (string) $value;
			}
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	// ---------- RS-30: dry run and isolation ----------

	/**
	 * Capability strings in use by anything but this type: every other
	 * registered post type and taxonomy, core's capabilities and meta
	 * capability names, BLT Toolkit's own, and the strings of other types on
	 * the isolated list (registered or not).
	 *
	 * @param string $type Post type to leave out ('' for none).
	 * @return array<string,bool>
	 */
	public static function taken_strings( $type ) {
		$type = (string) $type;
		$out  = array();

		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			if ( (string) $name === $type || ! $object instanceof WP_Post_Type ) {
				continue;
			}

			foreach ( (array) $object->cap as $value ) {
				$out[ (string) $value ] = true;
			}
		}

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( $taxonomy instanceof WP_Taxonomy ) {
				foreach ( (array) $taxonomy->cap as $value ) {
					$out[ (string) $value ] = true;
				}
			}
		}

		foreach ( array_keys( BLT_Toolkit_Capability_Catalog::core_caps() ) as $cap ) {
			$out[ (string) $cap ] = true;
		}

		foreach ( BLT_Toolkit_Capability_Catalog::core_meta_names() as $cap ) {
			$out[ (string) $cap ] = true;
		}

		foreach ( array_merge( array_keys( BLT_Toolkit_Caps::own() ), array_keys( BLT_Toolkit_Caps::meta() ) ) as $cap ) {
			$out[ (string) $cap ] = true;
		}

		foreach ( self::isolated() as $other => $info ) {
			if ( (string) $other === $type ) {
				continue;
			}

			foreach ( self::new_strings( self::new_caps( (string) $other, $info['singular'], $info['plural'] ), $info['plural'] ) as $value ) {
				$out[ $value ] = true;
			}
		}

		unset( $out['read'], $out[''] );

		return $out;
	}

	/**
	 * Check a type and names for RS-30: the type exists, is not isolated
	 * already, is a candidate, and the names are valid and collide with
	 * nothing.
	 *
	 * @param string $type     Post type.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return true|WP_Error `blt_toolkit_type_not_found` (404), `blt_toolkit_already_isolated` (409),
	 *                       `blt_toolkit_not_candidate` (400, data `reason`), `blt_toolkit_isolation_names`
	 *                       (400, data `field`, `code`) or `blt_toolkit_isolation_collision` (409, data `caps`).
	 */
	public static function validate( $type, $singular, $plural ) {
		$type   = (string) $type;
		$object = '' !== $type ? get_post_type_object( $type ) : null;

		if ( ! $object instanceof WP_Post_Type ) {
			return new WP_Error( 'blt_toolkit_type_not_found', __( 'That content type is not registered on this site.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		if ( self::is_isolated( $type ) ) {
			return new WP_Error( 'blt_toolkit_already_isolated', __( 'This content type already has separate permissions.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		$posts  = get_post_type_object( 'post' );
		$pages  = get_post_type_object( 'page' );
		$reason = self::candidate_reason( self::type_info( $object ), $posts ? (array) $posts->cap : array(), $pages ? (array) $pages->cap : array(), self::excluded_types() );

		if ( '' !== $reason ) {
			return new WP_Error(
				'blt_toolkit_not_candidate',
				self::reason_message( $reason ),
				array(
					'status' => 400,
					'reason' => $reason,
				)
			);
		}

		$code = self::name_error( $singular, $plural );

		if ( '' !== $code ) {
			return new WP_Error(
				'blt_toolkit_isolation_names',
				self::name_message( $code ),
				array(
					'status' => 400,
					'field'  => 'plural_invalid' === $code ? 'plural' : 'singular',
					'code'   => $code,
				)
			);
		}

		$collisions = self::collisions( self::new_caps( $type, $singular, $plural ), self::taken_strings( $type ), $plural );

		if ( array() !== $collisions ) {
			return new WP_Error(
				'blt_toolkit_isolation_collision',
				sprintf(
					/* translators: %s: comma-separated capability names. */
					__( 'These names would reuse capabilities something else already uses: %s. Choose another singular or plural.', 'blt-toolkit' ),
					implode( ', ', $collisions )
				),
				array(
					'status' => 409,
					'caps'   => $collisions,
				)
			);
		}

		return true;
	}

	/**
	 * The plain-English reason for a candidate_reason() code.
	 *
	 * @param string $reason Code.
	 * @return string
	 */
	public static function reason_message( $reason ) {
		switch ( (string) $reason ) {
			case 'core':
				return __( 'WordPress\'s own content types cannot be given separate permissions.', 'blt-toolkit' );
			case 'excluded':
				return __( 'This content type is on the list of types that must keep sharing Posts\' permissions, because its plugin checks those directly.', 'blt-toolkit' );
			case 'own_type':
				return __( 'This content type already has permissions of its own.', 'blt-toolkit' );
		}

		return __( 'This content type does not use exactly the same permissions as Posts or Pages, so it cannot be given separate ones here. Manage it in Advanced view.', 'blt-toolkit' );
	}

	/**
	 * The message for a name_error() code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function name_message( $code ) {
		switch ( (string) $code ) {
			case 'singular_invalid':
				/* translators: %d: maximum length. */
				return sprintf( __( 'The singular must be 1 to %d characters: lowercase letters, digits, underscores and hyphens, with at least one letter.', 'blt-toolkit' ), self::NAME_MAX );
			case 'plural_invalid':
				/* translators: %d: maximum length. */
				return sprintf( __( 'The plural must be 1 to %d characters: lowercase letters, digits, underscores and hyphens, with at least one letter.', 'blt-toolkit' ), self::NAME_MAX );
			case 'same':
				return __( 'The singular and the plural must differ.', 'blt-toolkit' );
		}

		return __( 'Those names cannot be used.', 'blt-toolkit' );
	}

	/**
	 * The dry run of RS-30 step 2. Reads only (RO-5).
	 *
	 * @param string $type     Post type.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return array|WP_Error array(
	 *     'type', 'label', 'singular', 'plural', 'shares',
	 *     'old_caps', 'new_caps' (key => string), 'strings' (every new string),
	 *     'seed' (seed_map()),
	 *     'roles' => role => array( 'name', 'add', 'remove', 'from' ),
	 *     'kept' => role => name (roles that need nothing),
	 *     'users' => user ID => array( 'login', 'name', 'add', 'remove', 'from', 'hash' ),
	 *     'hash'
	 * ), or validate()'s error.
	 */
	public static function plan( $type, $singular, $plural ) {
		$type     = (string) $type;
		$singular = is_scalar( $singular ) ? (string) $singular : '';
		$plural   = is_scalar( $plural ) ? (string) $plural : '';
		$valid    = self::validate( $type, $singular, $plural );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$object   = get_post_type_object( $type );
		$old      = self::strings( (array) $object->cap );
		$new      = self::new_caps( $type, $singular, $plural );
		$map      = self::seed_map( $old, $new );
		$wp_roles = wp_roles();
		$roles    = self::role_seed( (array) $wp_roles->roles, $map );
		$kept     = array();

		foreach ( (array) $wp_roles->roles as $id => $role ) {
			$id = (string) $id;

			if ( isset( $roles[ $id ] ) ) {
				$roles[ $id ]['name'] = self::role_name( $id );
			} else {
				$kept[ $id ] = self::role_name( $id );
			}
		}

		$plan = array(
			'type'     => $type,
			'label'    => isset( $object->labels->name ) ? (string) $object->labels->name : $type,
			'singular' => $singular,
			'plural'   => $plural,
			'shares'   => self::type_info( $object )['capability_type'],
			'old_caps' => $old,
			'new_caps' => $new,
			'strings'  => self::new_strings( $new, $plural ),
			'seed'     => $map,
			'roles'    => $roles,
			'kept'     => $kept,
			'users'    => self::user_seed( $map ),
		);

		$plan['hash'] = self::plan_hash( $plan );

		return $plan;
	}

	/**
	 * Give a post type separate permissions (RS-30 step 3).
	 *
	 * Works the plan out again and refuses when it differs from the dry run
	 * the person confirmed (`expected`). Every user change is checked first
	 * (BLT_Toolkit_User_Caps::save() as a dry run), so nothing is written
	 * when one would be refused. Then: the roles in one write through the
	 * role store (Guard, snapshot `before_isolation` or no write, hooks,
	 * log `post_type_isolated`), each user's capabilities, the type under
	 * `roles.isolated_post_types` with `since`, and
	 * `blt_toolkit_post_type_isolated`. The new capabilities apply from the
	 * next request, when the type registers through filter_args().
	 *
	 * @param string $type     Post type.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string  $expected The dry run's `hash`; refused with `blt_toolkit_isolation_changed` when it differs.
	 *     @type WP_User $actor    Who is acting (default: the current user).
	 * }
	 * @return array|WP_Error array( 'snapshot_id', 'log_id', 'roles' (count), 'users' (count), 'user_errors' (login => message) ).
	 */
	public static function isolate( $type, $singular, $plural, array $args = array() ) {
		$actor = isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user();
		$allow = self::can_manage( $actor );

		if ( is_wp_error( $allow ) ) {
			return $allow;
		}

		$plan = self::plan( $type, $singular, $plural );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( isset( $args['expected'] ) && ! hash_equals( $plan['hash'], (string) $args['expected'] ) ) {
			return self::changed_error();
		}

		$users = self::check_users( $plan['users'], $actor );

		if ( is_wp_error( $users ) ) {
			return $users;
		}

		$map      = $plan['seed'];
		$expected = self::plan_hash( array( 'roles' => $plan['roles'] ) );
		$result   = BLT_Toolkit_Role_Store::transaction(
			static function ( array $roles ) use ( $map, $expected ) {
				$changes = BLT_Toolkit_Isolation::role_seed( $roles, $map );

				// The roles must be exactly as the dry run saw them.
				if ( BLT_Toolkit_Isolation::plan_hash( array( 'roles' => $changes ) ) !== $expected ) {
					return BLT_Toolkit_Isolation::changed_error();
				}

				foreach ( $changes as $role => $change ) {
					$roles = BLT_Toolkit_Role_Store::apply_changes( $roles, $role, $change );
				}

				return $roles;
			},
			array(
				'reason'      => self::REASON,
				'context'     => self::CONTEXT,
				'module'      => 'roles',
				'action'      => 'post_type_isolated',
				'object_type' => 'post_type',
				'object_id'   => $plan['type'],
				'summary'     => self::isolated_summary( $plan ),
				'details'     => self::log_details( $plan ),
				'actor'       => $actor,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user_errors = self::save_users( $plan['users'], $actor, 'seed' );

		$isolated                  = self::isolated();
		$isolated[ $plan['type'] ] = array(
			'singular' => $plan['singular'],
			'plural'   => $plan['plural'],
			'since'    => time(),
		);

		BLT_Toolkit_Settings::set( 'roles', 'isolated_post_types', $isolated );

		$state = self::state();

		$state['shares'][ $plan['type'] ] = $plan['shares'];

		unset( $state['reverted'][ $plan['type'] ], $state['failed'][ $plan['type'] ] );

		self::write_state( $state );

		$log_id = (int) $result['log_id'];

		// No role needed seeding (nothing written, nothing logged by the
		// store): the isolation itself is still on the record.
		if ( 0 === $log_id ) {
			$log_id = (int) BLT_Toolkit_Log::record(
				array(
					'module'      => 'roles',
					'action'      => 'post_type_isolated',
					'object_type' => 'post_type',
					'object_id'   => $plan['type'],
					'summary'     => self::isolated_summary( $plan ),
					'details'     => array_merge( self::log_details( $plan ), array( 'context' => self::CONTEXT ) ),
				)
			);
		}

		/**
		 * Fires after a post type was given separate permissions (RS-30): the
		 * seed capabilities are written and the type is stored under
		 * `roles.isolated_post_types`. They apply from the next request.
		 *
		 * @param string $post_type The post type.
		 */
		do_action( 'blt_toolkit_post_type_isolated', $plan['type'] );

		return array(
			'snapshot_id' => (int) $result['snapshot_id'],
			'log_id'      => $log_id,
			'roles'       => count( $plan['roles'] ),
			'users'       => count( $plan['users'] ),
			'user_errors' => $user_errors,
		);
	}

	/**
	 * The log summary of an isolation.
	 *
	 * @param array $plan plan().
	 * @return string
	 */
	private static function isolated_summary( array $plan ) {
		return sprintf(
			/* translators: 1: plural content type name, such as Events. 2: post type key. 3: capability base, such as events. */
			__( 'Gave %1$s (%2$s) separate permissions, %3$s, seeded so nobody\'s access changed.', 'blt-toolkit' ),
			$plan['label'],
			$plan['type'],
			'*_' . $plan['plural']
		);
	}

	/**
	 * The log details of an isolation: the names, the seed map and each
	 * user's change (each role's is in the store's diff).
	 *
	 * @param array $plan plan().
	 * @return array
	 */
	private static function log_details( array $plan ) {
		$users = array();

		foreach ( $plan['users'] as $id => $change ) {
			$users[] = array(
				'user_id' => (int) $id,
				'login'   => $change['login'],
				'add'     => $change['add'],
				'remove'  => $change['remove'],
			);
		}

		return array(
			'post_type' => $plan['type'],
			'singular'  => $plan['singular'],
			'plural'    => $plan['plural'],
			'shares'    => $plan['shares'],
			'seed'      => $plan['seed'],
			'users'     => $users,
		);
	}

	// ---------- RS-32: revert and cleanup ----------

	/**
	 * What reverting a type changes (RS-32), for the confirm page: per role
	 * and per user with capabilities of their own in either set, the access
	 * gained back through the shared strings and lost with the isolated
	 * ones. Reads only.
	 *
	 * @param string $type Post type.
	 * @return array|WP_Error array( 'type', 'label', 'singular', 'plural', 'shares', 'failed' (bool),
	 *                        'roles' => role => array( 'name', 'gains', 'loses' ), 'users' => ID => array( 'login', 'name', 'gains', 'loses' ) ).
	 */
	public static function revert_plan( $type ) {
		$type = (string) $type;

		if ( ! self::is_isolated( $type ) ) {
			return new WP_Error( 'blt_toolkit_not_isolated', __( 'This content type does not have separate permissions.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		$info   = self::isolated()[ $type ];
		$shares = self::shares( $type );
		$base   = get_post_type_object( $shares );
		$old    = $base ? self::strings( (array) $base->cap ) : self::caps_from_args(
			array(
				'capability_type' => $shares,
				'map_meta_cap'    => true,
			)
		);
		$new    = self::new_caps( $type, $info['singular'], $info['plural'] );
		$object = get_post_type_object( $type );
		$roles  = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$diff = self::access_diff( $caps, $old, $new );

			if ( array() !== $diff['gains'] || array() !== $diff['loses'] ) {
				$roles[ (string) $id ] = array_merge( array( 'name' => self::role_name( (string) $id ) ), $diff );
			}
		}

		$users = array();

		foreach ( self::users_with( array_merge( array_values( $old ), array_values( $new ) ) ) as $user ) {
			$diff = self::access_diff( (array) $user->allcaps, $old, $new );

			if ( array() !== $diff['gains'] || array() !== $diff['loses'] ) {
				$users[ (int) $user->ID ] = array_merge(
					array(
						'login' => (string) $user->user_login,
						'name'  => (string) $user->display_name,
					),
					$diff
				);
			}
		}

		$failed = self::failed();

		return array(
			'type'         => $type,
			'label'        => $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type,
			'singular'     => (string) $info['singular'],
			'plural'       => (string) $info['plural'],
			'shares'       => $shares,
			'shares_label' => $base && isset( $base->labels->name ) ? (string) $base->labels->name : $shares,
			'failed'       => isset( $failed[ $type ] ),
			'missing'      => ! $object,
			'roles'        => $roles,
			'users'        => $users,
		);
	}

	/**
	 * Take a type off the isolated list (RS-32). It shares Posts' (or
	 * Pages') permissions again from the next request. No role is written:
	 * the capabilities it used stay on roles, flagged as orphans (RO-11),
	 * until cleanup() removes them. Records the names for that cleanup,
	 * logs `post_type_shared` and fires `blt_toolkit_post_type_shared`.
	 *
	 * @param string $type Post type.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type WP_User $actor Who is acting (default: the current user).
	 * }
	 * @return array|WP_Error array( 'log_id' ).
	 */
	public static function revert( $type, array $args = array() ) {
		$actor = isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user();
		$allow = self::can_manage( $actor );

		if ( is_wp_error( $allow ) ) {
			return $allow;
		}

		$type = (string) $type;

		if ( ! self::is_isolated( $type ) ) {
			return new WP_Error( 'blt_toolkit_not_isolated', __( 'This content type does not have separate permissions.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		$guard = self::check_revert( $type, $actor );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$isolated = self::isolated();
		$info     = $isolated[ $type ];
		$shares   = self::shares( $type );
		$object   = get_post_type_object( $type );
		$label    = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;

		unset( $isolated[ $type ] );

		BLT_Toolkit_Settings::set( 'roles', 'isolated_post_types', $isolated );

		$state = self::state();

		$state['reverted'][ $type ] = array(
			'singular' => (string) $info['singular'],
			'plural'   => (string) $info['plural'],
			'shares'   => $shares,
			'label'    => $label,
			'reverted' => time(),
		);

		unset( $state['failed'][ $type ], $state['shares'][ $type ] );

		self::write_state( $state );

		self::$failed = is_array( self::$failed ) ? array_diff_key( self::$failed, array( $type => true ) ) : self::$failed;

		$log_id = BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'post_type_shared',
				'object_type' => 'post_type',
				'object_id'   => $type,
				'summary'     => sprintf(
					/* translators: 1: plural content type name, such as Events. 2: post type key. 3: Posts or Pages. */
					__( '%1$s (%2$s) share %3$s\' permissions again. Their own capabilities stay on roles until they are cleaned up.', 'blt-toolkit' ),
					$label,
					$type,
					'page' === $shares ? __( 'Pages', 'blt-toolkit' ) : __( 'Posts', 'blt-toolkit' )
				),
				'details'     => array(
					'post_type' => $type,
					'singular'  => (string) $info['singular'],
					'plural'    => (string) $info['plural'],
					'shares'    => $shares,
					'since'     => (int) $info['since'],
					'context'   => self::CONTEXT,
				),
			)
		);

		/**
		 * Fires after a post type was reverted to sharing Posts' (or Pages')
		 * permissions (RS-32). Its own capabilities stay on roles until the
		 * separate cleanup step removes them.
		 *
		 * @param string $post_type The post type.
		 */
		do_action( 'blt_toolkit_post_type_shared', $type );

		return array( 'log_id' => (int) $log_id );
	}

	/**
	 * The Guard on a revert (RS-32, SEC-2, SEC-3, SEC-5). Reverting writes
	 * no role, but every role and user that holds a Posts (or Pages)
	 * capability without its isolated counterpart gets that access to the
	 * type back: a grant, judged as if the actor gave them those strings.
	 * Someone who is not a toolkit admin may therefore revert only when the
	 * Guard would let them make each of those grants, so not when it gives
	 * access back to their own role, to a role or user more powerful than
	 * them, or to an administrator, just as they could not isolate the type.
	 * A toolkit admin may always revert.
	 *
	 * @param string  $type  Post type.
	 * @param WP_User $actor Who is acting.
	 * @return true|WP_Error The first refusal, its message naming the role or user.
	 */
	public static function check_revert( $type, WP_User $actor ) {
		if ( BLT_Toolkit_Guard::is_toolkit_admin( $actor ) ) {
			return true;
		}

		$plan = self::revert_plan( $type );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		foreach ( $plan['roles'] as $role => $change ) {
			if ( array() === $change['gains'] ) {
				continue;
			}

			$check = BLT_Toolkit_Guard::check_role_change( $actor, (string) $role, array_fill_keys( $change['gains'], true ), array() );

			if ( is_wp_error( $check ) ) {
				return new WP_Error(
					$check->get_error_code(),
					sprintf(
						/* translators: 1: role name. 2: the reason. */
						__( 'Sharing Posts\' permissions again would give the %1$s role access back, which you may not do: %2$s', 'blt-toolkit' ),
						$change['name'],
						$check->get_error_message()
					),
					$check->get_error_data()
				);
			}
		}

		foreach ( $plan['users'] as $id => $change ) {
			$user = get_userdata( (int) $id );

			if ( array() === $change['gains'] || ! $user instanceof WP_User ) {
				continue;
			}

			$check = BLT_Toolkit_Guard::can_change_user_caps( $actor, $user, array_fill_keys( $change['gains'], true ), array() );

			if ( is_wp_error( $check ) ) {
				return new WP_Error(
					$check->get_error_code(),
					sprintf(
						/* translators: 1: username. 2: the reason. */
						__( 'Sharing Posts\' permissions again would give %1$s access back, which you may not do: %2$s', 'blt-toolkit' ),
						$change['login'],
						$check->get_error_message()
					),
					$check->get_error_data()
				);
			}
		}

		return true;
	}

	/**
	 * Records of reverted types whose capabilities may still need cleaning
	 * up: type => array( 'singular', 'plural', 'shares', 'label', 'reverted' ).
	 *
	 * @return array<string,array>
	 */
	public static function reverted() {
		$state = self::state();

		return $state['reverted'];
	}

	/**
	 * The dry run of RS-32's cleanup: which of a reverted type's strings are
	 * orphans now (RO-11), and which roles and users hold them (with any
	 * value). Reads only.
	 *
	 * @param string $type Post type.
	 * @return array|WP_Error array( 'type', 'label', 'singular', 'plural', 'strings' (all), 'orphans' (removable),
	 *                        'kept' (still registered), 'roles' => role => array( 'name', 'remove' ),
	 *                        'users' => ID => array( 'login', 'name', 'remove', 'hash' ), 'hash' ).
	 */
	public static function cleanup_plan( $type ) {
		$type = (string) $type;

		if ( self::is_isolated( $type ) ) {
			return new WP_Error( 'blt_toolkit_still_isolated', __( 'This content type still has separate permissions. Make it share Posts\' permissions again first.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		$reverted = self::reverted();

		if ( ! isset( $reverted[ $type ] ) ) {
			return new WP_Error( 'blt_toolkit_nothing_to_clean', __( 'There is nothing left to clean up for this content type.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		$info    = $reverted[ $type ];
		$new     = self::new_caps( $type, $info['singular'], $info['plural'] );
		$all     = self::new_strings( $new, $info['plural'] );
		$orphans = self::cleanup_strings( $new, $info['plural'], self::registered_strings() );
		$set     = array_fill_keys( $orphans, true );
		$roles   = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$caps   = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$remove = array_values( array_map( 'strval', array_keys( array_intersect_key( $caps, $set ) ) ) );

			if ( array() !== $remove ) {
				sort( $remove, SORT_STRING );

				$roles[ (string) $id ] = array(
					'name'   => self::role_name( (string) $id ),
					'add'    => array(),
					'remove' => $remove,
				);
			}
		}

		ksort( $roles, SORT_STRING );

		$users    = array();
		$role_ids = array_keys( (array) wp_roles()->roles );

		foreach ( array() !== $orphans ? self::users_with( $orphans ) : array() as $user ) {
			$own    = BLT_Toolkit_User_Caps::own_caps( is_array( $user->caps ) ? $user->caps : array(), $role_ids );
			$remove = array_values( array_map( 'strval', array_keys( array_intersect_key( $own, $set ) ) ) );

			if ( array() !== $remove ) {
				sort( $remove, SORT_STRING );

				$users[ (int) $user->ID ] = array(
					'login'  => (string) $user->user_login,
					'name'   => (string) $user->display_name,
					'add'    => array(),
					'remove' => $remove,
					'hash'   => BLT_Toolkit_User_Caps::user_hash( $user ),
				);
			}
		}

		$plan = array(
			'type'     => $type,
			'label'    => isset( $info['label'] ) && '' !== (string) $info['label'] ? (string) $info['label'] : $type,
			'singular' => (string) $info['singular'],
			'plural'   => (string) $info['plural'],
			'strings'  => $all,
			'orphans'  => $orphans,
			'kept'     => array_values( array_diff( $all, $orphans ) ),
			'roles'    => $roles,
			'users'    => $users,
		);

		$plan['hash'] = self::plan_hash( $plan );

		return $plan;
	}

	/**
	 * Remove a reverted type's now-unused capabilities from roles and users
	 * (RS-32's separate step): the roles in one write through the role
	 * store (snapshot `before_isolation`, logged as `capability_deleted`),
	 * then each user. Only orphans are removed (RO-11). The record of the
	 * reverted type is dropped afterwards.
	 *
	 * @param string $type Post type.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string  $expected The dry run's `hash`.
	 *     @type WP_User $actor    Who is acting (default: the current user).
	 * }
	 * @return array|WP_Error array( 'snapshot_id', 'log_id', 'roles', 'users', 'user_errors' ).
	 */
	public static function cleanup( $type, array $args = array() ) {
		$actor = isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user();
		$allow = self::can_manage( $actor );

		if ( is_wp_error( $allow ) ) {
			return $allow;
		}

		$plan = self::cleanup_plan( $type );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( isset( $args['expected'] ) && ! hash_equals( $plan['hash'], (string) $args['expected'] ) ) {
			return self::changed_error();
		}

		$users = self::check_users( $plan['users'], $actor );

		if ( is_wp_error( $users ) ) {
			return $users;
		}

		$set     = array_fill_keys( $plan['orphans'], true );
		$summary = sprintf(
			/* translators: 1: plural content type name, such as Events. 2: post type key. */
			__( 'Removed the capabilities %1$s (%2$s) used while it had separate permissions.', 'blt-toolkit' ),
			$plan['label'],
			$plan['type']
		);
		$details = array(
			'post_type' => $plan['type'],
			'plural'    => $plan['plural'],
			'removed'   => $plan['orphans'],
			'users'     => array_map(
				static function ( $change ) {
					return array(
						'login'  => $change['login'],
						'remove' => $change['remove'],
					);
				},
				array_values( $plan['users'] )
			),
		);
		$result  = array(
			'snapshot_id' => 0,
			'log_id'      => 0,
		);

		if ( array() !== $plan['roles'] ) {
			$result = BLT_Toolkit_Role_Store::transaction(
				static function ( array $roles ) use ( $set ) {
					foreach ( $roles as $id => $role ) {
						if ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) {
							$roles[ $id ]['capabilities'] = array_diff_key( $role['capabilities'], $set );
						}
					}

					return $roles;
				},
				array(
					'reason'      => self::REASON,
					'context'     => self::CONTEXT,
					'module'      => 'roles',
					'action'      => 'capability_deleted',
					'object_type' => 'post_type',
					'object_id'   => $plan['type'],
					'summary'     => $summary,
					'details'     => $details,
					'actor'       => $actor,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$user_errors = self::save_users( $plan['users'], $actor, 'inherit' );
		$log_id      = (int) $result['log_id'];

		if ( 0 === $log_id && array() !== $plan['users'] ) {
			$log_id = (int) BLT_Toolkit_Log::record(
				array(
					'module'      => 'roles',
					'action'      => 'capability_deleted',
					'object_type' => 'post_type',
					'object_id'   => $plan['type'],
					'summary'     => $summary,
					'details'     => array_merge( $details, array( 'context' => self::CONTEXT ) ),
				)
			);
		}

		$state = self::state();

		unset( $state['reverted'][ $plan['type'] ] );

		self::write_state( $state );

		return array(
			'snapshot_id' => (int) $result['snapshot_id'],
			'log_id'      => $log_id,
			'roles'       => count( $plan['roles'] ),
			'users'       => count( $plan['users'] ),
			'user_errors' => $user_errors,
		);
	}

	/**
	 * Forget a reverted type's record without removing anything (the
	 * cleanup page's "Keep them").
	 *
	 * @param string $type Post type.
	 * @return void
	 */
	public static function forget_reverted( $type ) {
		$state = self::state();

		if ( isset( $state['reverted'][ (string) $type ] ) ) {
			unset( $state['reverted'][ (string) $type ] );
			self::write_state( $state );
		}
	}

	/**
	 * Capability strings registered now (RO-11's "registered"): every post
	 * type's and taxonomy's, core's, BLT Toolkit's own and the named groups
	 * of `blt_toolkit_capability_groups`, read from the capability catalog.
	 *
	 * @return array<string,bool>
	 */
	public static function registered_strings() {
		$out = self::taken_strings( '' );

		foreach ( BLT_Toolkit_Capability_Catalog::all() as $cap => $entry ) {
			if ( empty( $entry['orphan'] ) ) {
				$out[ (string) $cap ] = true;
			}
		}

		return $out;
	}

	// ---------- RS-33: the notice ----------

	/**
	 * RS-33's admin notice, for people who manage roles or the site, while
	 * any isolated type's separate permissions are not in effect.
	 *
	 * @return void
	 */
	public static function admin_notice() {
		$failed = self::failed();

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
		if ( array() === $failed || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'blt_edit_roles' ) ) ) {
			return;
		}

		$can_fix = ! is_wp_error( self::can_manage( wp_get_current_user() ) );
		?>
		<div class="notice notice-warning blt-toolkit-notice">
			<p><strong><?php esc_html_e( 'BLT Toolkit: separate permissions are not in effect', 'blt-toolkit' ); ?></strong></p>
			<p><?php esc_html_e( 'These content types were given separate permissions, but they registered before BLT Toolkit loaded or another plugin changed their permissions. Until that is fixed they may share Posts\' permissions again or lose read-only (View) enforcement, and their levels are switched off in Simple view.', 'blt-toolkit' ); ?></p>
			<ul class="ul-disc">
				<?php foreach ( $failed as $type => $failure ) : ?>
					<?php
					$object = get_post_type_object( (string) $type );
					$label  = $object && isset( $object->labels->name ) ? (string) $object->labels->name : (string) $type;
					?>
					<li>
						<?php
						printf(
							/* translators: 1: content type name, such as Events. 2: post type key. 3: expected capability. 4: capability found. */
							esc_html__( '%1$s (%2$s): expected %3$s, found %4$s.', 'blt-toolkit' ),
							esc_html( $label ),
							'<code>' . esc_html( (string) $type ) . '</code>',
							'<code>' . esc_html( $failure['expected'] ) . '</code>',
							'<code>' . esc_html( '' !== $failure['found'] ? $failure['found'] : '-' ) . '</code>'
						);
						?>
						<?php if ( $can_fix && class_exists( 'BLT_Toolkit_Admin_Isolation' ) ) : ?>
							<a href="<?php echo esc_url( BLT_Toolkit_Admin_Isolation::url( 'revert', (string) $type ) ); ?>"><?php esc_html_e( 'Share Posts\' permissions again', 'blt-toolkit' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	// ---------- RS-34: the Plugins row ----------

	/**
	 * `after_plugin_row_{basename}`: while any type is isolated, or a role
	 * holds a read-only marker that only BLT Toolkit enforces, warn under
	 * BLT Toolkit's row what deactivating it changes (RS-34, RS-14, SPEC
	 * 14.6). Reads the settings and the loaded roles only.
	 *
	 * @return void
	 */
	public static function plugin_row() {
		$isolated = self::isolated();
		$readonly = self::native_readonly_roles();

		if ( array() === $isolated && array() === $readonly ) {
			return;
		}

		$columns = 4;

		if ( isset( $GLOBALS['wp_list_table'] ) && $GLOBALS['wp_list_table'] instanceof WP_List_Table ) {
			$columns = (int) $GLOBALS['wp_list_table']->get_column_count();
		}

		$names = array();

		foreach ( array_keys( $isolated ) as $type ) {
			$object  = get_post_type_object( (string) $type );
			$names[] = $object && isset( $object->labels->name ) ? (string) $object->labels->name : (string) $type;
		}
		?>
		<tr class="plugin-update-tr active blt-toolkit-plugin-warning" id="blt-toolkit-deactivation-warning">
			<td colspan="<?php echo esc_attr( (string) max( 1, $columns ) ); ?>" class="plugin-update colspanchange">
				<div class="notice inline notice-warning notice-alt">
					<?php if ( array() !== $isolated ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: number of content types. */
								esc_html( _n( 'Deactivating BLT Toolkit returns %s content type to sharing Posts permissions. People with Posts access will regain access to it.', 'Deactivating BLT Toolkit returns %s content types to sharing Posts permissions. People with Posts access will regain access to them.', count( $isolated ), 'blt-toolkit' ) ),
								esc_html( number_format_i18n( count( $isolated ) ) )
							);
							?>
							<?php
							printf(
								/* translators: %s: comma-separated content type names. */
								esc_html__( 'Content types with separate permissions: %s.', 'blt-toolkit' ),
								esc_html( implode( wp_get_list_item_separator(), $names ) )
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( array() !== $readonly ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: comma-separated role names. */
								esc_html__( 'Read-only (View) access stops being enforced for these roles: %s. Their people still cannot add items or edit other people\'s, but could edit items an administrator assigned to them.', 'blt-toolkit' ),
								esc_html( implode( wp_get_list_item_separator(), $readonly ) )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Names of roles that hold a read-only marker for a type that is not on
	 * the isolated list (its `create_posts` is distinct natively), whose
	 * View level only BLT Toolkit enforces (RS-14).
	 *
	 * @return string[]
	 */
	private static function native_readonly_roles() {
		$isolated = array();

		foreach ( self::isolated() as $info ) {
			$isolated[ self::MARKER_PREFIX . $info['plural'] ] = true;
		}

		$out = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			foreach ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array() as $cap => $value ) {
				$cap = (string) $cap;

				if ( $value && 0 === strpos( $cap, self::MARKER_PREFIX ) && ! isset( $isolated[ $cap ] ) ) {
					$out[] = self::role_name( (string) $id );
					break;
				}
			}
		}

		return $out;
	}

	// ---------- Shared helpers ----------

	/**
	 * Whether someone may change separate permissions (RS-30):
	 * `blt_edit_capabilities` and `manage_options`.
	 *
	 * @param WP_User $actor Who is acting.
	 * @return true|WP_Error `blt_toolkit_forbidden` (403).
	 */
	public static function can_manage( WP_User $actor ) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
		if ( $actor->exists() && user_can( $actor, 'blt_edit_capabilities' ) && user_can( $actor, 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'blt_toolkit_forbidden',
			__( 'Giving a content type separate permissions needs the "Edit capabilities" permission and the right to change site settings.', 'blt-toolkit' ),
			array(
				'status' => 403,
				'caps'   => array( 'blt_edit_capabilities', 'manage_options' ),
			)
		);
	}

	/**
	 * The refusal when roles or users changed since the dry run.
	 *
	 * @return WP_Error
	 */
	public static function changed_error() {
		return new WP_Error( 'blt_toolkit_isolation_changed', __( 'Roles or users changed since this preview was made. Review the new preview and confirm again.', 'blt-toolkit' ), array( 'status' => 409 ) );
	}

	/**
	 * Users whose own capabilities (not their roles') mention any of these
	 * strings, with any value. One query on the capabilities meta key, on
	 * a dry run or a confirm only.
	 *
	 * @param string[] $strings Capability names.
	 * @return WP_User[]
	 */
	public static function users_with( array $strings ) {
		global $wpdb;

		$strings = array_values( array_unique( array_filter( array_map( 'strval', $strings ) ) ) );
		$strings = array_values( array_diff( $strings, array( 'read' ) ) );

		if ( array() === $strings ) {
			return array();
		}

		$clauses = array();
		$params  = array( $wpdb->get_blog_prefix() . 'capabilities' );

		foreach ( $strings as $cap ) {
			// The key as PHP serializes a string: `s:10:"edit_posts";`.
			$clauses[] = 'meta_value LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( 's:' . strlen( $cap ) . ':"' . $cap . '";' ) . '%';
		}

		$sql = "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND ( " . implode( ' OR ', $clauses ) . ' ) ORDER BY user_id ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- A dry run's one read of the capabilities meta (RS-30, RS-32); the clauses are placeholders only, every value is bound.
		$ids      = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		$role_ids = array_keys( (array) wp_roles()->roles );
		$set      = array_fill_keys( $strings, true );
		$out      = array();

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$user = get_userdata( (int) $id );

			if ( ! $user instanceof WP_User || ! $user->exists() ) {
				continue;
			}

			$own = BLT_Toolkit_User_Caps::own_caps( is_array( $user->caps ) ? $user->caps : array(), $role_ids );

			if ( array() !== array_intersect_key( $own, $set ) ) {
				$out[] = $user;
			}
		}

		return $out;
	}

	/**
	 * The seed for users with capabilities of their own (RS-30 step 2):
	 * user ID => array( 'login', 'name', 'add', 'remove', 'from', 'hash' ),
	 * only users with something to change.
	 *
	 * @param array $map seed_map().
	 * @return array<int,array>
	 */
	private static function user_seed( array $map ) {
		$strings = array();

		foreach ( $map as $old => $news ) {
			$strings[] = (string) $old;
			$strings   = array_merge( $strings, array_map( 'strval', (array) $news ) );
		}

		$role_ids = array_keys( (array) wp_roles()->roles );
		$out      = array();

		foreach ( self::users_with( $strings ) as $user ) {
			$own    = BLT_Toolkit_User_Caps::own_caps( is_array( $user->caps ) ? $user->caps : array(), $role_ids );
			$change = self::seed_changes( $own, $map );

			if ( array() === $change['add'] && array() === $change['remove'] ) {
				continue;
			}

			$out[ (int) $user->ID ] = array_merge(
				array(
					'login' => (string) $user->user_login,
					'name'  => (string) $user->display_name,
					'hash'  => BLT_Toolkit_User_Caps::user_hash( $user ),
				),
				$change
			);
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The states BLT_Toolkit_User_Caps::save() takes for one user's change:
	 * `grant` or `deny` for each add, `inherit` for each removal.
	 *
	 * @param array $change array( 'add' => cap => bool, 'remove' => string[] ).
	 * @return array{on_page:string[],states:array<string,string>}
	 */
	private static function user_states( array $change ) {
		$states = array();

		foreach ( $change['add'] as $cap => $value ) {
			$states[ (string) $cap ] = $value ? BLT_Toolkit_User_Caps::GRANT : BLT_Toolkit_User_Caps::DENY;
		}

		foreach ( $change['remove'] as $cap ) {
			$states[ (string) $cap ] = BLT_Toolkit_User_Caps::INHERIT;
		}

		return array(
			'on_page' => array_map( 'strval', array_keys( $states ) ),
			'states'  => $states,
		);
	}

	/**
	 * Check every user change as a dry run before anything is written.
	 *
	 * @param array   $users Plan users.
	 * @param WP_User $actor Who is acting.
	 * @return true|WP_Error The first refusal, its message naming the user.
	 */
	private static function check_users( array $users, WP_User $actor ) {
		foreach ( $users as $id => $change ) {
			$user = get_userdata( (int) $id );

			if ( ! $user instanceof WP_User ) {
				return self::changed_error();
			}

			$input  = self::user_states( $change );
			$result = BLT_Toolkit_User_Caps::save(
				$user,
				$input['on_page'],
				$input['states'],
				$change['hash'],
				array(
					'dry_run' => true,
					'context' => self::CONTEXT,
					'actor'   => $actor,
				)
			);

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					$result->get_error_code(),
					sprintf(
						/* translators: 1: username. 2: the reason. */
						__( 'The capabilities of %1$s cannot be changed: %2$s', 'blt-toolkit' ),
						$change['login'],
						$result->get_error_message()
					),
					$result->get_error_data()
				);
			}
		}

		return true;
	}

	/**
	 * Write every user change (after check_users() passed).
	 *
	 * @param array   $users Plan users.
	 * @param WP_User $actor Who is acting.
	 * @param string  $kind  `seed` or `inherit`, for the record only.
	 * @return array<string,string> Login => message, for any that failed.
	 */
	private static function save_users( array $users, WP_User $actor, $kind ) {
		$errors = array();

		foreach ( $users as $id => $change ) {
			$user = get_userdata( (int) $id );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$input  = self::user_states( $change );
			$result = BLT_Toolkit_User_Caps::save(
				$user,
				$input['on_page'],
				$input['states'],
				BLT_Toolkit_User_Caps::user_hash( $user ),
				array(
					'context' => self::CONTEXT,
					'actor'   => $actor,
				)
			);

			if ( is_wp_error( $result ) ) {
				$errors[ (string) $change['login'] ] = $result->get_error_message();

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A user write that failed after the roles were written; the log row and this line say which.
				error_log( sprintf( 'BLT Toolkit: separate permissions (%s) could not update user %d: %s', $kind, (int) $id, $result->get_error_message() ) );
			}
		}

		return $errors;
	}

	/**
	 * A role's display name, translated, or its ID.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	private static function role_name( $role ) {
		$names = (array) wp_roles()->role_names;

		return isset( $names[ $role ] ) ? translate_user_role( (string) $names[ $role ] ) : (string) $role;
	}
}
