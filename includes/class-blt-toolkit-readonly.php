<?php
/**
 * Read-only enforcement for the Simple view's View level (RS-13, RS-14).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the View level read-only in the dashboard (SPEC 10.4, RS-14).
 *
 * WordPress needs a type's `edit_posts` capability for its list screen and
 * menu, and has no native way to say "can open the list but cannot change
 * anything". When a type's `create_posts` is a string of its own (natively,
 * or because it has separate permissions, RS-30), Simple view's View level
 * grants `read_private_{plural}`, `edit_{plural}` and the marker
 * `blt_readonly_{plural}`, and this class adds `do_not_allow` to
 * `edit_post`, `delete_post` and `publish_post` on that type's items for a
 * user who holds the marker **and lacks** the type's `create_posts`.
 *
 * Because the test is "has the marker and lacks create", someone who also
 * holds a higher level through another role is not restricted:
 * capabilities stay additive. List-table titles become plain text and the
 * Edit, Quick Edit and Trash row actions disappear because `edit_post` and
 * `delete_post` fail; post.php, post-new.php (no `create_posts`) and the
 * REST create and update routes refuse.
 *
 * The marker name is `blt_readonly_` plus the part of the type's
 * `edit_posts` string after `edit_`, the same rule the capability catalog
 * and BLT_Toolkit_Simple_Controls::type_map() use.
 *
 * Cost (SEC-9): one closure on `map_meta_cap` that returns at once for any
 * capability but the three above. For those, it returns without loading a
 * post when no registered type has a distinct `create_posts`, and without
 * loading a post when the user holds no marker at all. The type => marker
 * map is built once per request from the registered post types (no query)
 * and rebuilt when a type registers later. It runs whether or not the Roles
 * module is on, like the Guard: a marker granted while the module ran must
 * keep its meaning (SPEC 4.1).
 *
 * decide(), marker_for() and restricts() are pure and unit-tested in
 * tests/test-readonly.php.
 */
class BLT_Toolkit_Readonly {

	/**
	 * Prefix of the read-only marker capabilities (RS-13), the same as
	 * BLT_Toolkit_Capability_Catalog::MARKER_PREFIX.
	 */
	const MARKER_PREFIX = 'blt_readonly_';

	/**
	 * The meta capabilities RS-14 restricts.
	 */
	const CAPS = array(
		'edit_post'    => true,
		'delete_post'  => true,
		'publish_post' => true,
	);

	/**
	 * Priority of the `map_meta_cap` filter: after core's mapping and the
	 * usual plugin filters, since it only ever adds `do_not_allow`.
	 */
	const PRIORITY = 20;

	/**
	 * Parent slug of the list screens registered by list_access(): not a
	 * menu, so the entries are never drawn.
	 */
	const HIDDEN_PARENT = 'blt-toolkit-readonly';

	/**
	 * Whether register() ran.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Post type => marker for every registered type with a distinct
	 * `create_posts`, or null when not built yet this request.
	 *
	 * @var array<string,array{marker:string,create:string}>|null
	 */
	private static $markers = null;

	// ---------- Wiring ----------

	/**
	 * Add the `map_meta_cap` filter and the hook that forgets the type map
	 * when a post type registers. Called once by BLT_Toolkit_Plugin::boot().
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		$guarded = self::CAPS;

		// A closure gate, as the Guard's: cheaper per call than a static
		// method callable, and every current_user_can() runs it (SEC-9).
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap, $user_id, $args ) use ( $guarded ) {
				if ( ! is_string( $cap ) || ! isset( $guarded[ $cap ] ) ) {
					return $caps;
				}

				return BLT_Toolkit_Readonly::map_meta_cap( $caps, $cap, $user_id, $args );
			},
			self::PRIORITY,
			4
		);

		add_action( 'registered_post_type', array( __CLASS__, 'flush' ), 10, 0 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'list_access' ), PHP_INT_MAX );
		}
	}

	/**
	 * Let a read-only user reach the type's list screen (RS-13, RS-14).
	 *
	 * WordPress removes a top-level content menu's "Add New" item for
	 * someone without `create_posts`, then drops the submenu that is left
	 * because its only item is the menu itself. With that gone,
	 * get_admin_page_parent() cannot place `edit.php?post_type={type}`, so
	 * user_can_access_admin_page() falls back to the Posts menu, and someone
	 * without `edit_posts` is refused the list ("Sorry, you are not allowed
	 * to access this page.", 403), although they hold the type's own
	 * `edit_posts`. For each type the current user is held read-only on,
	 * this registers the list once more under a parent that is never drawn,
	 * with the type's `edit_posts` as its capability, so the page has a
	 * parent again. Nothing shows in the menu, and nobody gains a
	 * capability. Runs on `admin_menu` (late), after core and plugins built
	 * the menu and before the access check.
	 *
	 * @return void
	 */
	public static function list_access() {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return;
		}

		foreach ( self::markers() as $type => $entry ) {
			if ( ! self::restricts( (array) $user->allcaps, $entry['marker'], $entry['create'] ) || $user->has_cap( $entry['create'] ) ) {
				continue;
			}

			$object = get_post_type_object( $type );

			if ( ! $object instanceof WP_Post_Type || ! $object->show_ui || true !== $object->show_in_menu || ! $user->has_cap( $object->cap->edit_posts ) ) {
				continue;
			}

			$label = isset( $object->labels->all_items ) ? (string) $object->labels->all_items : (string) $type;

			add_submenu_page( self::HIDDEN_PARENT, $label, $label, $object->cap->edit_posts, 'edit.php?post_type=' . $type );
		}
	}

	/**
	 * Forget the type => marker map (a post type registered or changed).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$markers = null;
	}

	// ---------- RS-14 ----------

	/**
	 * `map_meta_cap` for `edit_post`, `delete_post` and `publish_post`: add
	 * `do_not_allow` when the user holds the item's type's read-only marker
	 * and lacks its `create_posts` (RS-14).
	 *
	 * @param string[] $caps    Primitive capabilities core mapped to.
	 * @param string   $cap     Meta capability being checked.
	 * @param int      $user_id User being checked.
	 * @param array    $args    The post ID, first.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		$caps = (array) $caps;

		if ( in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}

		$markers = self::markers();

		if ( array() === $markers ) {
			return $caps;
		}

		$user = self::user( (int) $user_id );

		if ( null === $user || ! self::holds_any( (array) $user->allcaps, $markers ) ) {
			return $caps;
		}

		$post = isset( $args[0] ) ? get_post( $args[0] ) : null;

		// A revision is judged by its parent, as core maps it.
		if ( $post instanceof WP_Post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}

		if ( ! $post instanceof WP_Post || ! isset( $markers[ $post->post_type ] ) ) {
			return $caps;
		}

		$entry = $markers[ $post->post_type ];

		if ( ! self::restricts( (array) $user->allcaps, $entry['marker'], $entry['create'] ) ) {
			return $caps;
		}

		// "Lacks create" also asks the capability filters, so a grant made by
		// another plugin through `user_has_cap` lifts the restriction as a
		// role's grant would. has_cap() maps `create_{plural}` through
		// map_meta_cap(), which this class leaves alone.
		if ( $user->has_cap( $entry['create'] ) ) {
			return $caps;
		}

		$caps[] = 'do_not_allow';

		return $caps;
	}

	/**
	 * Post type => marker and create capability for every registered type
	 * whose `create_posts` differs from its `edit_posts` and whose
	 * `edit_posts` starts with `edit_`. Built once per request, no query.
	 *
	 * @return array<string,array{marker:string,create:string}>
	 */
	public static function markers() {
		if ( null !== self::$markers ) {
			return self::$markers;
		}

		$out = array();

		foreach ( get_post_types( array(), 'objects' ) as $name => $type ) {
			if ( ! $type instanceof WP_Post_Type || ! isset( $type->cap ) ) {
				continue;
			}

			$cap    = (array) $type->cap;
			$marker = self::marker_for( $cap );

			if ( '' !== $marker ) {
				$out[ (string) $name ] = array(
					'marker' => $marker,
					'create' => (string) $cap['create_posts'],
				);
			}
		}

		// Types still registering (before `init` finished) are picked up by
		// flush() on `registered_post_type`.
		self::$markers = $out;

		return self::$markers;
	}

	/**
	 * The user a check is about: the current user's object when it is them
	 * (no lookup), otherwise get_userdata().
	 *
	 * @param int $user_id User ID.
	 * @return WP_User|null
	 */
	private static function user( $user_id ) {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_current_user_id() === $user_id ? wp_get_current_user() : get_userdata( $user_id );

		return $user instanceof WP_User && $user->exists() ? $user : null;
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * The read-only marker of a type's cap object (RS-13), or '' when the
	 * type's `create_posts` is not distinct from its `edit_posts` (View is
	 * then site-only and nothing is enforced) or `edit_posts` does not start
	 * with `edit_`. Pure.
	 *
	 * @param array $cap A post type's cap object as an array.
	 * @return string
	 */
	public static function marker_for( array $cap ) {
		$edit   = isset( $cap['edit_posts'] ) && is_scalar( $cap['edit_posts'] ) ? (string) $cap['edit_posts'] : '';
		$create = isset( $cap['create_posts'] ) && is_scalar( $cap['create_posts'] ) ? (string) $cap['create_posts'] : '';

		if ( '' === $create || $create === $edit || 0 !== strpos( $edit, 'edit_' ) || strlen( $edit ) <= 5 ) {
			return '';
		}

		return self::MARKER_PREFIX . substr( $edit, 5 );
	}

	/**
	 * Whether someone with these effective capabilities is held read-only:
	 * they hold the marker and lack the create capability (RS-14). A
	 * capability stored `false` is not held (SEC-8). Pure.
	 *
	 * @param array  $allcaps Effective capabilities (`WP_User::$allcaps`).
	 * @param string $marker  `blt_readonly_{plural}`.
	 * @param string $create  The type's `create_posts`.
	 * @return bool
	 */
	public static function restricts( array $allcaps, $marker, $create ) {
		$marker = (string) $marker;
		$create = (string) $create;

		if ( '' === $marker || '' === $create ) {
			return false;
		}

		return ! empty( $allcaps[ $marker ] ) && empty( $allcaps[ $create ] );
	}

	/**
	 * The RS-14 decision: whether checking `$cap` on an item of a type with
	 * this cap object adds `do_not_allow` for someone with these effective
	 * capabilities. True only for `edit_post`, `delete_post` and
	 * `publish_post`, on a type with a distinct `create_posts`, for someone
	 * who holds the type's marker and lacks its `create_posts`. Pure.
	 *
	 * @param string $cap      Meta capability checked.
	 * @param array  $type_cap The item's post type's cap object as an array.
	 * @param array  $allcaps  Effective capabilities.
	 * @return bool
	 */
	public static function decide( $cap, array $type_cap, array $allcaps ) {
		if ( ! is_string( $cap ) || ! isset( self::CAPS[ $cap ] ) ) {
			return false;
		}

		$marker = self::marker_for( $type_cap );

		return '' !== $marker && self::restricts( $allcaps, $marker, (string) $type_cap['create_posts'] );
	}

	/**
	 * Whether the effective capabilities hold any of the markers. Pure.
	 *
	 * @param array $allcaps Effective capabilities.
	 * @param array $markers markers().
	 * @return bool
	 */
	public static function holds_any( array $allcaps, array $markers ) {
		foreach ( $markers as $entry ) {
			if ( isset( $entry['marker'] ) && ! empty( $allcaps[ (string) $entry['marker'] ] ) ) {
				return true;
			}
		}

		return false;
	}
}
