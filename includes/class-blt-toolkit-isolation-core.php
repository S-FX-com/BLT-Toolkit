<?php
/**
 * Separate permissions for a custom post type: the part every request needs
 * (RS-31 filter, RS-33 verification).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What separate permissions need on every request, kept small so a visitor's
 * page view never compiles the flows (SEC-9): the `register_post_type_args`
 * filter (RS-31), the RS-33 verification at `init`, reading the stored list
 * and the state option, and the pure rules they rest on.
 *
 * BLT_Toolkit_Isolation extends this class with the dry runs, the isolate,
 * revert and cleanup flows, the RS-33 notice and the RS-34 Plugins row,
 * which only admin, cron and WP-CLI requests load. Every method here is
 * reachable as `BLT_Toolkit_Isolation::method()` too.
 *
 * - filter_args(): returns its input at once when nothing is isolated or
 *   this type is not. An isolated type gets `capability_type => array(
 *   singular, plural )`, `map_meta_cap => true` and `capabilities =>
 *   array( 'create_posts' => "create_{plural}" )` through
 *   `blt_toolkit_isolation_caps`. Its registration's `do_not_allow`
 *   overrides are kept (they only take access away); any other override of
 *   Posts' (or Pages') strings means the type is no longer what was
 *   isolated, so it is left alone and RS-33 reports it.
 * - isolated(): the stored list without entries that could never have been
 *   isolated (entry_error(): a core type, names that fail name_error(), or
 *   strings that would be core's or BLT Toolkit's own), so a damaged or
 *   imported entry never moves a core type or a core capability.
 * - verify() and check(): every isolated, registered type must have
 *   `edit_posts === "edit_{plural}"` and the other SPEC 10.4 strings
 *   isolation gave it (`create_posts` above all, which read-only
 *   enforcement depends on), `do_not_allow` kept from the registration
 *   excepted.
 *
 * Pure and unit-tested in tests/test-isolation.php: isolation_args(),
 * applies_to(), overrides_error(), entry_error(), name_error(),
 * caps_from_args() and check().
 */
class BLT_Toolkit_Isolation_Core {

	/**
	 * Option holding the RS-33 failure flag, the records of reverted types
	 * (for the RS-32 cleanup) and which type each isolated type shared.
	 * Autoloaded, so reading it on an admin request costs nothing; it is
	 * created on the first isolation and never read on the front end.
	 */
	const STATE_OPTION = 'blt_toolkit_isolation_state';

	/**
	 * Prefix of the read-only marker (RS-13).
	 */
	const MARKER_PREFIX = 'blt_readonly_';

	/**
	 * Longest singular or plural accepted.
	 */
	const NAME_MAX = 60;

	/**
	 * Capability types a candidate may have (RS-30).
	 */
	const SOURCES = array( 'post', 'page' );

	/**
	 * The value a registration uses to refuse a capability to everyone.
	 */
	const DO_NOT_ALLOW = 'do_not_allow';

	/**
	 * Keys of a cap object that are primitives seeded and compared (the
	 * SPEC 10.4 table). `read` and the per-item meta keys are left out.
	 */
	const LEVEL_KEYS = array(
		'edit_posts',
		'edit_others_posts',
		'edit_published_posts',
		'edit_private_posts',
		'publish_posts',
		'read_private_posts',
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_private_posts',
		'create_posts',
	);

	/**
	 * Per-item (meta) keys of a cap object.
	 */
	const META_KEYS = array( 'edit_post', 'read_post', 'delete_post' );

	/**
	 * Post types WordPress registers itself (`_builtin`, WordPress 7.1).
	 * Never isolated, whatever the stored list says (RS-30).
	 */
	const CORE_TYPES = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/**
	 * Capability names WordPress itself grants or checks (core's primitives
	 * and meta capabilities, WordPress 7.1), written out so the filter never
	 * loads the capability catalog (SEC-9). An isolated type whose strings
	 * would include one of them (a plural of `pages` or `users`) is never
	 * applied.
	 */
	const CORE_STRINGS = array( 'read', 'exist', 'do_not_allow', 'manage_options', 'moderate_comments', 'manage_links', 'import', 'export', 'unfiltered_html', 'edit_dashboard', 'update_core', 'view_site_health_checks', 'install_languages', 'switch_themes', 'edit_theme_options', 'install_themes', 'update_themes', 'delete_themes', 'edit_themes', 'resume_themes', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'create_posts', 'manage_categories', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'edit_private_pages', 'publish_pages', 'read_private_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'create_pages', 'upload_files', 'unfiltered_upload', 'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'resume_plugins', 'list_users', 'create_users', 'edit_users', 'delete_users', 'remove_users', 'promote_users', 'manage_network', 'manage_sites', 'create_sites', 'delete_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network', 'edit_files', 'remove_user', 'promote_user', 'add_users', 'edit_user', 'delete_user', 'edit_post', 'edit_page', 'read_post', 'read_page', 'delete_post', 'delete_page', 'publish_post', 'edit_post_meta', 'delete_post_meta', 'add_post_meta', 'edit_comment_meta', 'delete_comment_meta', 'add_comment_meta', 'edit_term_meta', 'delete_term_meta', 'add_term_meta', 'edit_user_meta', 'delete_user_meta', 'add_user_meta', 'edit_comment', 'edit_css', 'upload_plugins', 'upload_themes', 'update_languages', 'deactivate_plugins', 'activate_plugin', 'deactivate_plugin', 'resume_plugin', 'resume_theme', 'customize', 'delete_site', 'edit_term', 'delete_term', 'assign_term', 'manage_post_tags', 'edit_categories', 'edit_post_tags', 'delete_categories', 'delete_post_tags', 'assign_categories', 'assign_post_tags', 'setup_network', 'update_php', 'update_https', 'export_others_personal_data', 'erase_others_personal_data', 'manage_privacy_options', 'create_app_password', 'list_app_passwords', 'read_app_password', 'edit_app_password', 'delete_app_passwords', 'delete_app_password', 'edit_block_binding' );

	/**
	 * Whether register() ran.
	 *
	 * @var bool
	 */
	protected static $registered = false;

	/**
	 * RS-33 failures found this request by verify(), or null before it ran.
	 *
	 * @var array<string,array{expected:string,found:string}>|null
	 */
	protected static $failed = null;

	/**
	 * Post type => the capability type it was registered with before
	 * filter_args() replaced it (`post` or `page`), seen this request.
	 *
	 * @var array<string,string>
	 */
	protected static $shares = array();

	/**
	 * isolated()'s last result: `from` (the stored list it was built from)
	 * and `list`.
	 *
	 * @var array{from:mixed,list:array}|null
	 */
	protected static $isolated_cache = null;

	// ---------- Wiring ----------

	/**
	 * Add the hooks. Called once by BLT_Toolkit_Plugin::boot(), whatever
	 * the Roles module's state, because the filter that isolates types is
	 * added when the main file loads and must be verified and warned about
	 * whenever it applies.
	 *
	 * - `init` at `PHP_INT_MAX`: verify() (RS-33, SPEC 4.2 step 4).
	 * - Admin only: the RS-33 notice, the RS-34 Plugins row, and the flow
	 *   pages' admin-post.php routes (BLT_Toolkit_Admin_Isolation::boot()),
	 *   all in BLT_Toolkit_Isolation, which loads only when they run.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'init', array( __CLASS__, 'verify' ), PHP_INT_MAX );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_notices', array( 'BLT_Toolkit_Isolation', 'admin_notice' ) );
		add_action( 'after_plugin_row_' . BLT_TOOLKIT_BASENAME, array( 'BLT_Toolkit_Isolation', 'plugin_row' ), 10, 0 );

		if ( class_exists( 'BLT_Toolkit_Admin_Isolation' ) ) {
			BLT_Toolkit_Admin_Isolation::boot();
		}
	}

	// ---------- RS-31: the filter ----------

	/**
	 * `register_post_type_args` callback, added when the main file loads
	 * (SPEC 4.2): give each isolated type its own capabilities (RS-31).
	 *
	 * Returns `$args` untouched at once when no type is isolated or this
	 * type is not; when the entry could never have been isolated (a core
	 * type, invalid names, or strings that are core's or BLT Toolkit's,
	 * entry_error()); and when the registration is no longer that of a
	 * Posts or Pages type: a capability type of its own, or `capabilities`
	 * overrides other than Posts' (or Pages') own strings and `do_not_allow`
	 * (overrides_error()). RS-33 then reports the type. `do_not_allow`
	 * overrides are kept, since they only take access away. Reads only the
	 * autoloaded settings: no query.
	 *
	 * @param array  $args      Registration arguments.
	 * @param string $post_type Post type key.
	 * @return array
	 */
	public static function filter_args( $args, $post_type ) {
		if ( ! is_array( $args ) || ( function_exists( 'is_multisite' ) && is_multisite() ) ) {
			return $args;
		}

		$isolated = BLT_Toolkit_Settings::get( 'roles', 'isolated_post_types' );

		if ( ! is_array( $isolated ) || array() === $isolated || ! is_string( $post_type ) || ! isset( $isolated[ $post_type ] ) ) {
			return $args;
		}

		$info = $isolated[ $post_type ];

		if ( ! empty( $args['_builtin'] ) || '' !== self::entry_error( $post_type, $info ) ) {
			return $args;
		}

		$base = self::applies_to( $args );

		if ( '' === $base || '' !== self::overrides_error( $args, $base ) ) {
			return $args;
		}

		self::$shares[ $post_type ] = $base;

		$filtered = self::filtered_args( $post_type, $info );
		$kept     = self::restrictive_overrides( $args );

		if ( array() !== $kept ) {
			$filtered['capabilities'] = array_merge( isset( $filtered['capabilities'] ) && is_array( $filtered['capabilities'] ) ? $filtered['capabilities'] : array(), $kept );
		}

		return array_merge( $args, $filtered );
	}

	/**
	 * The arguments isolation applies to a type, through
	 * `blt_toolkit_isolation_caps`.
	 *
	 * @param string $post_type Post type.
	 * @param array  $info      Its stored entry: `singular`, `plural`, `since`.
	 * @return array
	 */
	public static function filtered_args( $post_type, array $info ) {
		$singular = isset( $info['singular'] ) ? (string) $info['singular'] : '';
		$plural   = isset( $info['plural'] ) ? (string) $info['plural'] : '';

		/**
		 * Filter the registration arguments applied to an isolated post type
		 * (RS-31). They are merged over the type's own arguments.
		 *
		 * @param array  $args      `capability_type => array( singular, plural )`, `map_meta_cap => true`,
		 *                          `capabilities => array( 'create_posts' => "create_{plural}" )`.
		 * @param string $post_type The post type.
		 * @param array  $info      Its stored entry: `singular`, `plural`, `since`.
		 */
		$filtered = apply_filters( 'blt_toolkit_isolation_caps', self::isolation_args( $singular, $plural ), (string) $post_type, $info );

		return is_array( $filtered ) ? $filtered : self::isolation_args( $singular, $plural );
	}

	/**
	 * The arguments RS-31 sets. Pure.
	 *
	 * @param string $singular Singular capability base.
	 * @param string $plural   Plural capability base.
	 * @return array
	 */
	public static function isolation_args( $singular, $plural ) {
		return array(
			'capability_type' => array( (string) $singular, (string) $plural ),
			'map_meta_cap'    => true,
			'capabilities'    => array( 'create_posts' => 'create_' . (string) $plural ),
		);
	}

	/**
	 * Whether registration arguments still describe a type that shares
	 * Posts' or Pages' capabilities: `capability_type` missing (core's
	 * default, `post`), `post` or `page`, or an array starting with one of
	 * them. Pure.
	 *
	 * @param array $args Registration arguments.
	 * @return string `post`, `page`, or '' when the type has a capability type of its own.
	 */
	public static function applies_to( array $args ) {
		$type = array_key_exists( 'capability_type', $args ) ? $args['capability_type'] : 'post';

		if ( is_array( $type ) ) {
			$type = array_values( $type );
			$type = isset( $type[0] ) ? $type[0] : '';
		}

		$type = is_scalar( $type ) ? (string) $type : '';

		return in_array( $type, self::SOURCES, true ) ? $type : '';
	}

	/**
	 * Why a registration's `capabilities` overrides stop a type from being
	 * isolated, or '' when they do not (RS-30, RS-31). Overrides that name
	 * Posts' (or Pages') own string for that key change nothing and are
	 * replaced; `do_not_allow` is kept (restrictive_overrides()); any other
	 * string means the registering plugin decided who may do that
	 * (`read_private_posts => manage_options`), which isolation would
	 * silently widen. Pure.
	 *
	 * @param array  $args Registration arguments.
	 * @param string $base `post` or `page` (applies_to()).
	 * @return string `overrides`, or ''.
	 */
	public static function overrides_error( array $args, $base ) {
		$overrides = isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) ? $args['capabilities'] : array();

		if ( array() === $overrides ) {
			return '';
		}

		$shared = self::caps_from_args(
			array(
				'capability_type' => (string) $base,
				'map_meta_cap'    => true,
			)
		);

		foreach ( $overrides as $key => $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';

			if ( self::DO_NOT_ALLOW === $value || ( isset( $shared[ (string) $key ] ) && $shared[ (string) $key ] === $value ) ) {
				continue;
			}

			return 'overrides';
		}

		return '';
	}

	/**
	 * The `capabilities` overrides of a registration that refuse something
	 * to everyone (`create_posts => do_not_allow`), kept when isolation
	 * applies so it never gives back what the registering plugin took
	 * away. Pure.
	 *
	 * @param array $args Registration arguments.
	 * @return array<string,string>
	 */
	public static function restrictive_overrides( array $args ) {
		$out = array();

		foreach ( isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) ? $args['capabilities'] : array() as $key => $value ) {
			if ( is_scalar( $value ) && self::DO_NOT_ALLOW === (string) $value ) {
				$out[ (string) $key ] = self::DO_NOT_ALLOW;
			}
		}

		return $out;
	}

	// ---------- Reading ----------

	/**
	 * Isolated types as stored: type => array( 'singular', 'plural', 'since' ),
	 * without entries entry_error() refuses (they are never applied).
	 *
	 * @return array<string,array{singular:string,plural:string,since:int}>
	 */
	public static function isolated() {
		$stored = BLT_Toolkit_Settings::get( 'roles', 'isolated_post_types' );

		if ( null !== self::$isolated_cache && self::$isolated_cache['from'] === $stored ) {
			return self::$isolated_cache['list'];
		}

		$list = array();

		foreach ( is_array( $stored ) ? $stored : array() as $type => $info ) {
			if ( '' === self::entry_error( $type, $info ) ) {
				$list[ (string) $type ] = $info;
			}
		}

		self::$isolated_cache = array(
			'from' => $stored,
			'list' => $list,
		);

		return $list;
	}

	/**
	 * Whether a post type has separate permissions (it is on the list,
	 * whether or not they are in effect; see failed()).
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_isolated( $type ) {
		$isolated = self::isolated();

		return is_string( $type ) && isset( $isolated[ $type ] );
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * Why a stored entry can never be isolated, or '' when it can. Pure
	 * (BLT_Toolkit_Caps::own() and meta() are constants).
	 *
	 * - `core_type`: a type WordPress registers (RS-30), which no flow
	 *   offers; isolating Posts would move every Posts permission.
	 * - `invalid`: not a type key and an array.
	 * - `singular_invalid`, `plural_invalid`, `same`: name_error().
	 * - `reserved`: a string isolation would use is a core capability
	 *   (CORE_STRINGS: a plural of `pages` gives `edit_pages`) or BLT
	 *   Toolkit's own, so the type would follow core's permission.
	 *
	 * @param mixed $type Post type key.
	 * @param mixed $info The stored entry.
	 * @return string
	 */
	public static function entry_error( $type, $info ) {
		if ( ! is_string( $type ) || '' === $type || ! is_array( $info ) ) {
			return 'invalid';
		}

		if ( in_array( $type, self::CORE_TYPES, true ) ) {
			return 'core_type';
		}

		$singular = isset( $info['singular'] ) && is_scalar( $info['singular'] ) ? (string) $info['singular'] : '';
		$plural   = isset( $info['plural'] ) && is_scalar( $info['plural'] ) ? (string) $info['plural'] : '';
		$error    = self::name_error( $singular, $plural );

		if ( '' !== $error ) {
			return $error;
		}

		$reserved = array_flip( self::CORE_STRINGS );

		if ( class_exists( 'BLT_Toolkit_Caps' ) ) {
			$reserved += BLT_Toolkit_Caps::own() + BLT_Toolkit_Caps::meta();
		}

		foreach ( self::caps_from_args( self::isolation_args( $singular, $plural ) ) as $key => $string ) {
			if ( 'read' !== $key && isset( $reserved[ $string ] ) ) {
				return 'reserved';
			}
		}

		return '';
	}

	/**
	 * Why a singular and plural cannot be used, or ''. Pure.
	 *
	 * Each must be 1 to 60 characters of lowercase letters, digits, `_` and
	 * `-` with at least one letter (the capability name rules of RO-23), and
	 * they must differ, or the per-item `edit_{singular}` would be the same
	 * string as `edit_{plural}`.
	 *
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return string `singular_invalid`, `plural_invalid`, `same` or ''.
	 */
	public static function name_error( $singular, $plural ) {
		$singular = is_scalar( $singular ) ? (string) $singular : '';
		$plural   = is_scalar( $plural ) ? (string) $plural : '';

		if ( ! self::valid_name( $singular ) ) {
			return 'singular_invalid';
		}

		if ( ! self::valid_name( $plural ) ) {
			return 'plural_invalid';
		}

		return $singular === $plural ? 'same' : '';
	}

	/**
	 * Whether one name is usable. Pure.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	protected static function valid_name( $name ) {
		return '' !== $name && strlen( $name ) <= self::NAME_MAX && 1 === preg_match( '/^[a-z0-9_-]+$/', $name ) && 1 === preg_match( '/[a-z]/', $name );
	}

	/**
	 * The cap object WordPress builds from registration arguments, as
	 * get_post_type_capabilities() does it (without recording meta
	 * capabilities, which would change a global). Pure.
	 *
	 * @param array $args `capability_type`, `map_meta_cap`, `capabilities`.
	 * @return array<string,string>
	 */
	public static function caps_from_args( array $args ) {
		$type = array_key_exists( 'capability_type', $args ) ? $args['capability_type'] : 'post';

		if ( ! is_array( $type ) ) {
			$type = array( (string) $type, (string) $type . 's' );
		}

		$type     = array_values( $type );
		$singular = isset( $type[0] ) && is_scalar( $type[0] ) ? (string) $type[0] : '';
		$plural   = isset( $type[1] ) && is_scalar( $type[1] ) ? (string) $type[1] : $singular . 's';

		$caps = array(
			'edit_post'          => 'edit_' . $singular,
			'read_post'          => 'read_' . $singular,
			'delete_post'        => 'delete_' . $singular,
			'edit_posts'         => 'edit_' . $plural,
			'edit_others_posts'  => 'edit_others_' . $plural,
			'delete_posts'       => 'delete_' . $plural,
			'publish_posts'      => 'publish_' . $plural,
			'read_private_posts' => 'read_private_' . $plural,
		);

		if ( ! empty( $args['map_meta_cap'] ) ) {
			$caps = array_merge(
				$caps,
				array(
					'read'                   => 'read',
					'delete_private_posts'   => 'delete_private_' . $plural,
					'delete_published_posts' => 'delete_published_' . $plural,
					'delete_others_posts'    => 'delete_others_' . $plural,
					'edit_private_posts'     => 'edit_private_' . $plural,
					'edit_published_posts'   => 'edit_published_' . $plural,
				)
			);
		}

		foreach ( isset( $args['capabilities'] ) && is_array( $args['capabilities'] ) ? $args['capabilities'] : array() as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$caps[ (string) $key ] = (string) $value;
			}
		}

		if ( ! isset( $caps['create_posts'] ) ) {
			$caps['create_posts'] = $caps['edit_posts'];
		}

		return $caps;
	}

	/**
	 * RS-33: the isolated types whose separate permissions are not in
	 * effect. A type that is not registered (its plugin is off) is not a
	 * failure. Pure.
	 *
	 * `edit_posts` must be `edit_{plural}`; then `create_posts` and every
	 * other SPEC 10.4 key must be what isolation applied (`$expected`, by
	 * default isolation_args()' cap object), except that a `do_not_allow`
	 * kept from the registration is accepted. A `create_posts` that is no
	 * longer distinct switches read-only enforcement off (RS-14), so it is
	 * a failure like the rest.
	 *
	 * @param array $isolated Type => array( 'singular', 'plural', ... ).
	 * @param array $caps     Type => its registered cap object as an array, or null when not registered.
	 * @param array $expected Optional. Type => the cap object isolation applies (new_caps()).
	 * @return array<string,array{expected:string,found:string}>
	 */
	public static function check( array $isolated, array $caps, array $expected = array() ) {
		$out = array();

		foreach ( $isolated as $type => $info ) {
			$type = (string) $type;

			if ( ! isset( $caps[ $type ] ) || ! is_array( $caps[ $type ] ) ) {
				continue;
			}

			$singular = is_array( $info ) && isset( $info['singular'] ) ? (string) $info['singular'] : '';
			$plural   = is_array( $info ) && isset( $info['plural'] ) ? (string) $info['plural'] : '';
			$want     = isset( $expected[ $type ] ) && is_array( $expected[ $type ] ) ? $expected[ $type ] : self::caps_from_args( self::isolation_args( $singular, $plural ) );
			$found    = $caps[ $type ];
			$keys     = array_merge( array( 'create_posts' ), array_diff( self::LEVEL_KEYS, array( 'edit_posts', 'create_posts' ) ) );
			$failure  = null;
			$edit     = isset( $found['edit_posts'] ) ? (string) $found['edit_posts'] : '';

			if ( 'edit_' . $plural !== $edit ) {
				$failure = array(
					'expected' => 'edit_' . $plural,
					'found'    => $edit,
				);
			} else {
				foreach ( $keys as $key ) {
					if ( ! isset( $want[ $key ] ) ) {
						continue;
					}

					$value = isset( $found[ $key ] ) ? (string) $found[ $key ] : '';

					if ( $value !== (string) $want[ $key ] && self::DO_NOT_ALLOW !== $value ) {
						$failure = array(
							'expected' => (string) $want[ $key ],
							'found'    => $value,
						);
						break;
					}
				}
			}

			if ( null !== $failure ) {
				$out[ $type ] = $failure;
			}
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	// ---------- RS-33: verification ----------

	/**
	 * The new cap object a type would get with these names, after
	 * `blt_toolkit_isolation_caps`.
	 *
	 * @param string $type     Post type.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return array<string,string>
	 */
	public static function new_caps( $type, $singular, $plural ) {
		return self::caps_from_args(
			self::filtered_args(
				(string) $type,
				array(
					'singular' => (string) $singular,
					'plural'   => (string) $plural,
					'since'    => 0,
				)
			)
		);
	}

	/**
	 * At `init` priority `PHP_INT_MAX`: check every isolated, registered
	 * type (RS-33, check()). Returns at once when nothing is isolated. No
	 * query: the result is kept for this request, and stored for Site
	 * Health only on admin, cron and WP-CLI requests, only when it changed.
	 *
	 * @return void
	 */
	public static function verify() {
		$isolated = self::isolated();

		if ( array() === $isolated ) {
			self::$failed = array();

			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				self::store_failures( array() );
			}

			return;
		}

		$caps     = array();
		$expected = array();

		foreach ( $isolated as $type => $info ) {
			$object = get_post_type_object( (string) $type );

			$caps[ (string) $type ]     = $object instanceof WP_Post_Type ? (array) $object->cap : null;
			$expected[ (string) $type ] = self::new_caps( (string) $type, $info['singular'], $info['plural'] );
		}

		self::$failed = self::check( $isolated, $caps, $expected );

		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			self::store_failures( self::$failed );
		}
	}

	/**
	 * RS-33 failures this request: type => array( 'expected', 'found' ).
	 * Runs verify() first when it has not run (before `init` finished, only
	 * types registered so far are checked).
	 *
	 * @return array<string,array{expected:string,found:string}>
	 */
	public static function failed() {
		if ( null === self::$failed ) {
			self::verify();
		}

		return is_array( self::$failed ) ? self::$failed : array();
	}

	/**
	 * The stored RS-33 flag the Site Health test reads (Phase 7): type =>
	 * array( 'expected', 'found', 'since' ), as last seen on an admin, cron
	 * or WP-CLI request.
	 *
	 * @return array<string,array{expected:string,found:string,since:int}>
	 */
	public static function stored_failures() {
		$state = self::state();

		return $state['failed'];
	}

	/**
	 * Store the failures when they differ from the stored flag (keeping each
	 * one's first `since`). Writes nothing while the option does not exist
	 * and there is nothing to store.
	 *
	 * @param array $failed check().
	 * @return void
	 */
	protected static function store_failures( array $failed ) {
		$state  = self::state();
		$stored = $state['failed'];
		$next   = array();

		foreach ( $failed as $type => $failure ) {
			$next[ $type ] = array(
				'expected' => $failure['expected'],
				'found'    => $failure['found'],
				'since'    => isset( $stored[ $type ]['since'] ) && isset( $stored[ $type ]['found'] ) && $stored[ $type ]['found'] === $failure['found'] ? (int) $stored[ $type ]['since'] : time(),
			);
		}

		if ( $next === $stored ) {
			return;
		}

		$state['failed'] = $next;

		self::write_state( $state );
	}

	// ---------- The state option ----------

	/**
	 * The state option, with its three keys always present and only
	 * well-formed entries in each (a damaged option must not break a
	 * screen): `failed` type => array( 'expected', 'found', 'since' ),
	 * `reverted` type => array( 'singular', 'plural', 'shares', 'label',
	 * 'reverted' ) with valid names, `shares` type => `post` or `page`.
	 *
	 * @return array{failed:array,reverted:array,shares:array}
	 */
	protected static function state() {
		$raw   = get_option( self::STATE_OPTION, array() );
		$raw   = is_array( $raw ) ? $raw : array();
		$state = array(
			'failed'   => array(),
			'reverted' => array(),
			'shares'   => array(),
		);

		foreach ( isset( $raw['failed'] ) && is_array( $raw['failed'] ) ? $raw['failed'] : array() as $type => $entry ) {
			if ( is_string( $type ) && is_array( $entry ) && isset( $entry['expected'], $entry['found'] ) && is_scalar( $entry['expected'] ) && is_scalar( $entry['found'] ) ) {
				$state['failed'][ $type ] = array(
					'expected' => (string) $entry['expected'],
					'found'    => (string) $entry['found'],
					'since'    => isset( $entry['since'] ) && is_numeric( $entry['since'] ) ? (int) $entry['since'] : 0,
				);
			}
		}

		foreach ( isset( $raw['reverted'] ) && is_array( $raw['reverted'] ) ? $raw['reverted'] : array() as $type => $entry ) {
			if ( ! is_string( $type ) || ! is_array( $entry ) || ! isset( $entry['singular'], $entry['plural'] ) || '' !== self::name_error( $entry['singular'], $entry['plural'] ) ) {
				continue;
			}

			$state['reverted'][ $type ] = array(
				'singular' => (string) $entry['singular'],
				'plural'   => (string) $entry['plural'],
				'shares'   => isset( $entry['shares'] ) && in_array( $entry['shares'], self::SOURCES, true ) ? (string) $entry['shares'] : 'post',
				'label'    => isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : $type,
				'reverted' => isset( $entry['reverted'] ) && is_numeric( $entry['reverted'] ) ? (int) $entry['reverted'] : 0,
			);
		}

		foreach ( isset( $raw['shares'] ) && is_array( $raw['shares'] ) ? $raw['shares'] : array() as $type => $base ) {
			if ( is_string( $type ) && in_array( $base, self::SOURCES, true ) ) {
				$state['shares'][ $type ] = (string) $base;
			}
		}

		return $state;
	}

	/**
	 * Write the state option (autoloaded). Nothing is written while the
	 * option does not exist and the state is empty.
	 *
	 * @param array $state State.
	 * @return void
	 */
	protected static function write_state( array $state ) {
		$value = array(
			'failed'   => isset( $state['failed'] ) ? (array) $state['failed'] : array(),
			'reverted' => isset( $state['reverted'] ) ? (array) $state['reverted'] : array(),
			'shares'   => isset( $state['shares'] ) ? (array) $state['shares'] : array(),
		);

		if ( array() === array_filter( $value ) && false === get_option( self::STATE_OPTION, false ) ) {
			return;
		}

		update_option( self::STATE_OPTION, $value, true );
	}
}
