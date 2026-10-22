<?php
/**
 * Capability catalog: every capability the site knows, where it comes from,
 * what to call it and where it sits in the Advanced view's group tree.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers every capability and records where it comes from (SPEC 9.2).
 *
 * - RO-10 sources: a maintained core list (with WordPress-version notes,
 *   multisite capabilities for completeness, listed on this single site
 *   only when a role or user holds one, and the deprecated user levels),
 *   each post type's `cap` object (the meta capabilities too for a type with
 *   `map_meta_cap` false, because core then checks them as primitives), each
 *   taxonomy's `manage_terms`, `edit_terms`, `delete_terms` and
 *   `assign_terms`, BLT Toolkit's own capabilities and read-only markers, and
 *   any capability present on any role or on any user (one indexed query on
 *   the capabilities meta key, cached for the request).
 * - RO-11 orphans: present on a role or user but registered by nothing
 *   (core, an active post type or taxonomy, BLT Toolkit, or a group declared
 *   through `blt_toolkit_capability_groups`). Flagged, never removed here.
 * - RO-12 labels: a plain-English dictionary for core capabilities, labels
 *   built from each post type's and taxonomy's own labels ("Edit others'
 *   Events"), a humanized fallback, then `blt_toolkit_capability_labels`.
 * - RO-13 tree: All; Core and its sections; Content types (one node per
 *   capability group); Taxonomies; Plugins (named groups plus best-effort
 *   prefix groups of three or more); BLT Toolkit; Orphaned. Node IDs are
 *   URL-safe (`core-posts`, `content-post`, `plugin-woocommerce`).
 * - RO-14 shared detection: content types are grouped by identical
 *   capability strings, so a custom post type registered with
 *   `capability_type => 'post'` is a member of the Posts group, never a node
 *   of its own (SPEC 19, URE-4).
 *
 * Nothing here runs on page load: the catalog is built lazily, the first time
 * a BLT roles screen (or a write path that needs it) asks, and kept for the
 * rest of that request. Building it writes nothing (RO-5; SPEC 19, URE-2).
 *
 * The rules are pure (build() and the helpers it calls take plain arrays and
 * are unit-tested in tests/test-capability-catalog.php); the methods that
 * read WordPress only gather those arrays.
 */
class BLT_Toolkit_Capability_Catalog {

	/**
	 * Where a capability comes from.
	 */
	const SOURCE_CORE     = 'core';
	const SOURCE_CONTENT  = 'content';
	const SOURCE_TAXONOMY = 'taxonomy';
	const SOURCE_PLUGIN   = 'plugin';
	const SOURCE_BLT      = 'blt';
	const SOURCE_ORPHAN   = 'orphan';

	/**
	 * Prefix of the read-only marker capabilities (RS-13).
	 */
	const MARKER_PREFIX = 'blt_readonly_';

	/**
	 * How many capabilities must share a prefix to make a detected plugin
	 * group (RO-13).
	 */
	const PREFIX_MIN = 3;

	/**
	 * Longest capability name accepted by "Add capability" (RO-23): the
	 * indexed part of the log's `object_id` and of most meta keys.
	 */
	const NAME_MAX = 191;

	/**
	 * The keys of a post type's `cap` object that are primitive capabilities,
	 * in the order their strings are labeled: when one string serves several
	 * keys (`create_posts` usually equals `edit_posts`), the first key wins.
	 */
	const PRIMITIVE_KEYS = array(
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
		'read',
	);

	/**
	 * The keys of a post type's `cap` object that are meta capabilities:
	 * checked per item and mapped by core, unless the type has
	 * `map_meta_cap` false, when core checks them as primitives.
	 */
	const META_KEYS = array( 'edit_post', 'read_post', 'delete_post' );

	/**
	 * The keys of a taxonomy's `cap` object, in label order.
	 */
	const TERM_KEYS = array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' );

	/**
	 * The core sections of the tree, in order (RO-13). `core-network` holds
	 * the multisite capabilities listed for completeness.
	 */
	const CORE_NODES = array( 'core-general', 'core-appearance', 'core-posts', 'core-pages', 'core-media', 'core-plugins', 'core-users', 'core-network', 'core-deprecated' );

	/**
	 * Words that start many capability names and say nothing about which
	 * plugin they belong to, skipped when looking for a shared prefix.
	 */
	const VERBS = array( 'access', 'activate', 'add', 'assign', 'create', 'deactivate', 'delete', 'edit', 'export', 'import', 'install', 'list', 'manage', 'moderate', 'publish', 'read', 'remove', 'see', 'update', 'upload', 'use', 'view' );

	/**
	 * The full build for this request (roles and users included), or null.
	 *
	 * @var array|null
	 */
	private static $full = null;

	/**
	 * The build from registered sources and roles only (no user query).
	 *
	 * @var array|null
	 */
	private static $base = null;

	/**
	 * Filtered labels of the full build.
	 *
	 * @var array<string,array>|null
	 */
	private static $entries = null;

	/**
	 * Filtered labels of the build without users, for label().
	 *
	 * @var array<string,string>|null
	 */
	private static $base_labels = null;

	/**
	 * What fills each entry's `simple` field (Phase 5), or null.
	 *
	 * @var callable|null
	 */
	private static $simple_provider = null;

	// ---------- Public API ----------

	/**
	 * Every capability the site knows (RO-10), keyed by name.
	 *
	 * @return array<string,array{label:string,raw:string,source:string,source_label:string,group:string,groups:string[],tier:string,orphan:bool,deprecated:bool,multisite:bool,since:string,note:string,simple:string|null}>
	 */
	public static function all() {
		if ( null !== self::$entries ) {
			return self::$entries;
		}

		$built   = self::full();
		$entries = $built['entries'];
		$labels  = self::filtered_labels( $entries );
		$simple  = self::simple_labels( array_keys( $entries ) );

		foreach ( $entries as $cap => $entry ) {
			$cap = (string) $cap;

			$entries[ $cap ]['label']  = isset( $labels[ $cap ] ) ? $labels[ $cap ] : $entry['label'];
			$entries[ $cap ]['tier']   = BLT_Toolkit_Caps::tier( $cap );
			$entries[ $cap ]['simple'] = isset( $simple[ $cap ] ) ? $simple[ $cap ] : null;
		}

		self::$entries = $entries;

		return self::$entries;
	}

	/**
	 * The group tree (RO-13): nested nodes, each `id` (URL-safe), `label`,
	 * `caps` (names, sorted), `count` and `children`; content nodes also
	 * carry `types` and `internal`.
	 *
	 * @return array[]
	 */
	public static function tree() {
		$built = self::full();

		return $built['tree'];
	}

	/**
	 * One node of the tree by ID, or null.
	 *
	 * @param string $id Node ID.
	 * @return array|null
	 */
	public static function node( $id ) {
		return self::find_node( self::tree(), (string) $id );
	}

	/**
	 * Content capability groups (RO-14): post types grouped by identical
	 * capability strings. See group_content_types() for each group's keys.
	 *
	 * @return array[]
	 */
	public static function content_groups() {
		$built = self::base();

		return $built['content_groups'];
	}

	/**
	 * Taxonomy capability groups: taxonomies with capability strings of their
	 * own, grouped by identical strings. See group_taxonomies().
	 *
	 * @return array[]
	 */
	public static function taxonomy_groups() {
		$built = self::base();

		return $built['taxonomy_groups'];
	}

	/**
	 * Whether a capability is orphaned (RO-11): present on a role or a user,
	 * yet registered by nothing.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function is_orphan( $cap ) {
		$all = self::all();
		$cap = (string) $cap;

		return isset( $all[ $cap ] ) && ! empty( $all[ $cap ]['orphan'] );
	}

	/**
	 * A capability's label (RO-12), without running the user query: the
	 * registered sources' labels through `blt_toolkit_capability_labels`,
	 * otherwise the name humanized.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function label( $cap ) {
		$cap = (string) $cap;

		if ( null !== self::$entries && isset( self::$entries[ $cap ] ) ) {
			return self::$entries[ $cap ]['label'];
		}

		if ( null === self::$base_labels ) {
			$built             = self::base();
			self::$base_labels = self::filtered_labels( $built['entries'] );
		}

		$labels = self::$base_labels;

		if ( isset( $labels[ $cap ] ) ) {
			return $labels[ $cap ];
		}

		$extra = self::filtered_labels(
			array(
				$cap => array( 'label' => self::humanize( $cap ) ),
			)
		);

		return isset( $extra[ $cap ] ) ? $extra[ $cap ] : self::humanize( $cap );
	}

	/**
	 * Who holds a capability, with any value (granted or denied): roles from
	 * the roles option, users from a query for that one capability (the
	 * catalog itself reads only the distinct capability sets, SEC-9). Used
	 * by the delete dialog (RO-24).
	 *
	 * @param string $cap Capability.
	 * @return array{roles:string[],users:int[]}
	 */
	public static function holders( $cap ) {
		$built = self::full();
		$cap   = (string) $cap;

		return array(
			'roles' => isset( $built['holders']['roles'][ $cap ] ) ? $built['holders']['roles'][ $cap ] : array(),
			'users' => self::user_holders( $cap ),
		);
	}

	/**
	 * Whether a name is a meta capability (RO-23): core's (`edit_post`,
	 * `edit_user`, `edit_post_meta` and the rest), BLT Toolkit's own
	 * (`blt_switch_to_user`, `blt_change_username`), and each post type's
	 * per-item names when it has `map_meta_cap` on. Granting one as a
	 * primitive does nothing useful.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function is_meta_cap_name( $cap ) {
		$built = self::base();

		return isset( $built['meta_names'][ (string) $cap ] );
	}

	/**
	 * Whether a new capability name may be added (RO-23): lowercase letters,
	 * digits, underscores and hyphens, at least one letter, at most 191
	 * characters, not a capability that exists, not a meta capability, not a
	 * role ID and not `do_not_allow` or `exist`.
	 *
	 * @param string $cap Proposed name.
	 * @return true|WP_Error `blt_toolkit_cap_name_invalid`,
	 *                       `blt_toolkit_cap_exists` or
	 *                       `blt_toolkit_cap_meta` (status 400 or 409).
	 */
	public static function check_new_name( $cap ) {
		$cap   = (string) $cap;
		$error = self::name_error( $cap, self::NAME_MAX );

		if ( '' !== $error ) {
			return new WP_Error( 'blt_toolkit_cap_name_invalid', self::name_error_message( $error, self::NAME_MAX ), array( 'status' => 400 ) );
		}

		$all  = self::all();
		$core = self::core_caps();

		// A core capability is never new, even one this site does not list
		// (the multisite capabilities on a single site, RO-13).
		if ( isset( $all[ $cap ] ) || isset( $core[ $cap ] ) || isset( wp_roles()->roles[ $cap ] ) || in_array( $cap, array( 'do_not_allow', 'exist' ), true ) ) {
			return new WP_Error( 'blt_toolkit_cap_exists', __( 'That name is already in use. Choose another.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		if ( self::is_meta_cap_name( $cap ) ) {
			return new WP_Error( 'blt_toolkit_cap_meta', __( 'WordPress checks that name for one item at a time, so granting it to a role would do nothing. Choose another name.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * The maintained core list (RO-10): capability => its section, label,
	 * the WordPress version that introduced it, a note, and whether it is
	 * deprecated or for multisite networks only.
	 *
	 * Capabilities core maps from others (`customize`, `edit_css`,
	 * `add_users` and the like) are meta capabilities and are not listed.
	 * A few are given to people automatically by WordPress from another
	 * capability; their note says so.
	 *
	 * @return array<string,array{group:string,label:string,since:string,note:string,deprecated:bool,multisite:bool}>
	 */
	public static function core_caps() {
		$auto_plugins = __( 'WordPress also gives this to anyone who can install plugins.', 'blt-toolkit' );
		$deprecated   = __( 'Deprecated since WordPress 3.0, when roles replaced user levels. Kept for old plugins that still check it.', 'blt-toolkit' );
		$network      = __( 'Used only on a multisite network.', 'blt-toolkit' );

		$list = array(
			// General.
			'read'                    => array( 'core-general', __( 'Sign in to the dashboard and edit their own profile', 'blt-toolkit' ), '2.0', '' ),
			'manage_options'          => array( 'core-general', __( 'Change site settings', 'blt-toolkit' ), '2.0', '' ),
			'moderate_comments'       => array( 'core-general', __( 'Moderate comments', 'blt-toolkit' ), '2.0', '' ),
			'manage_links'            => array( 'core-general', __( 'Manage links (the old Links screen)', 'blt-toolkit' ), '2.0', __( 'The Links screen is hidden unless a plugin turns it back on.', 'blt-toolkit' ) ),
			'import'                  => array( 'core-general', __( 'Import content', 'blt-toolkit' ), '2.0', '' ),
			'export'                  => array( 'core-general', __( 'Export content', 'blt-toolkit' ), '3.0', '' ),
			'unfiltered_html'         => array( 'core-general', __( 'Publish unfiltered HTML (scripts)', 'blt-toolkit' ), '2.0', '' ),
			'edit_dashboard'          => array( 'core-general', __( 'Customize the dashboard', 'blt-toolkit' ), '2.5', '' ),
			'update_core'             => array( 'core-general', __( 'Update WordPress', 'blt-toolkit' ), '3.0', '' ),
			'view_site_health_checks' => array( 'core-general', __( 'View Site Health', 'blt-toolkit' ), '5.2', $auto_plugins ),
			'install_languages'       => array( 'core-general', __( 'Install and update languages', 'blt-toolkit' ), '4.9', __( 'WordPress also gives this to anyone who can install or update plugins or themes.', 'blt-toolkit' ) ),
			// Appearance.
			'switch_themes'           => array( 'core-appearance', __( 'Change the active theme', 'blt-toolkit' ), '2.0', '' ),
			'edit_theme_options'      => array( 'core-appearance', __( 'Customize menus, widgets and the site editor', 'blt-toolkit' ), '3.0', '' ),
			'install_themes'          => array( 'core-appearance', __( 'Install themes', 'blt-toolkit' ), '2.8', '' ),
			'update_themes'           => array( 'core-appearance', __( 'Update themes', 'blt-toolkit' ), '2.7', '' ),
			'delete_themes'           => array( 'core-appearance', __( 'Delete themes', 'blt-toolkit' ), '3.0', '' ),
			'edit_themes'             => array( 'core-appearance', __( 'Edit theme files', 'blt-toolkit' ), '2.0', '' ),
			'resume_themes'           => array( 'core-appearance', __( 'Resume themes paused by recovery mode', 'blt-toolkit' ), '5.2', __( 'WordPress also gives this to anyone who can change the active theme.', 'blt-toolkit' ) ),
			// Posts.
			'edit_posts'              => array( 'core-posts', __( 'Create and edit their own posts', 'blt-toolkit' ), '2.0', '' ),
			'edit_others_posts'       => array( 'core-posts', __( 'Edit others\' posts', 'blt-toolkit' ), '2.0', '' ),
			'edit_published_posts'    => array( 'core-posts', __( 'Edit published posts', 'blt-toolkit' ), '2.0', '' ),
			'edit_private_posts'      => array( 'core-posts', __( 'Edit private posts', 'blt-toolkit' ), '2.1', '' ),
			'publish_posts'           => array( 'core-posts', __( 'Publish posts', 'blt-toolkit' ), '2.0', '' ),
			'read_private_posts'      => array( 'core-posts', __( 'Read private posts', 'blt-toolkit' ), '2.1', '' ),
			'delete_posts'            => array( 'core-posts', __( 'Delete their own posts', 'blt-toolkit' ), '2.1', '' ),
			'delete_others_posts'     => array( 'core-posts', __( 'Delete others\' posts', 'blt-toolkit' ), '2.1', '' ),
			'delete_published_posts'  => array( 'core-posts', __( 'Delete published posts', 'blt-toolkit' ), '2.1', '' ),
			'delete_private_posts'    => array( 'core-posts', __( 'Delete private posts', 'blt-toolkit' ), '2.1', '' ),
			'manage_categories'       => array( 'core-posts', __( 'Manage categories and tags', 'blt-toolkit' ), '2.0', '' ),
			// Pages.
			'edit_pages'              => array( 'core-pages', __( 'Create and edit their own pages', 'blt-toolkit' ), '2.0', '' ),
			'edit_others_pages'       => array( 'core-pages', __( 'Edit others\' pages', 'blt-toolkit' ), '2.1', '' ),
			'edit_published_pages'    => array( 'core-pages', __( 'Edit published pages', 'blt-toolkit' ), '2.1', '' ),
			'edit_private_pages'      => array( 'core-pages', __( 'Edit private pages', 'blt-toolkit' ), '2.1', '' ),
			'publish_pages'           => array( 'core-pages', __( 'Publish pages', 'blt-toolkit' ), '2.1', '' ),
			'read_private_pages'      => array( 'core-pages', __( 'Read private pages', 'blt-toolkit' ), '2.1', '' ),
			'delete_pages'            => array( 'core-pages', __( 'Delete their own pages', 'blt-toolkit' ), '2.1', '' ),
			'delete_others_pages'     => array( 'core-pages', __( 'Delete others\' pages', 'blt-toolkit' ), '2.1', '' ),
			'delete_published_pages'  => array( 'core-pages', __( 'Delete published pages', 'blt-toolkit' ), '2.1', '' ),
			'delete_private_pages'    => array( 'core-pages', __( 'Delete private pages', 'blt-toolkit' ), '2.1', '' ),
			// Media.
			'upload_files'            => array( 'core-media', __( 'Upload files', 'blt-toolkit' ), '2.0', '' ),
			'unfiltered_upload'       => array( 'core-media', __( 'Upload any file type', 'blt-toolkit' ), '2.3', __( 'Works only when wp-config.php also defines ALLOW_UNFILTERED_UPLOADS.', 'blt-toolkit' ) ),
			// Plugins.
			'activate_plugins'        => array( 'core-plugins', __( 'Activate and deactivate plugins', 'blt-toolkit' ), '2.0', '' ),
			'install_plugins'         => array( 'core-plugins', __( 'Install plugins', 'blt-toolkit' ), '2.7', '' ),
			'update_plugins'          => array( 'core-plugins', __( 'Update plugins', 'blt-toolkit' ), '2.6', '' ),
			'delete_plugins'          => array( 'core-plugins', __( 'Delete plugins', 'blt-toolkit' ), '2.6', '' ),
			'edit_plugins'            => array( 'core-plugins', __( 'Edit plugin files', 'blt-toolkit' ), '2.0', '' ),
			'resume_plugins'          => array( 'core-plugins', __( 'Resume plugins paused by recovery mode', 'blt-toolkit' ), '5.2', __( 'WordPress also gives this to anyone who can activate plugins.', 'blt-toolkit' ) ),
			// Users.
			'list_users'              => array( 'core-users', __( 'See the list of users', 'blt-toolkit' ), '3.0', '' ),
			'create_users'            => array( 'core-users', __( 'Add users', 'blt-toolkit' ), '2.1', '' ),
			'edit_users'              => array( 'core-users', __( 'Edit users', 'blt-toolkit' ), '2.0', '' ),
			'delete_users'            => array( 'core-users', __( 'Delete users', 'blt-toolkit' ), '2.1', '' ),
			'remove_users'            => array( 'core-users', __( 'Remove users from the site', 'blt-toolkit' ), '3.0', '' ),
			'promote_users'           => array( 'core-users', __( 'Change users\' roles', 'blt-toolkit' ), '3.0', '' ),
			// Network (multisite), for completeness.
			'manage_network'          => array( 'core-network', __( 'Manage the network', 'blt-toolkit' ), '3.0', $network ),
			'manage_sites'            => array( 'core-network', __( 'Manage the network\'s sites', 'blt-toolkit' ), '3.0', $network ),
			'create_sites'            => array( 'core-network', __( 'Create sites', 'blt-toolkit' ), '4.4', $network ),
			'delete_sites'            => array( 'core-network', __( 'Delete sites', 'blt-toolkit' ), '4.4', $network ),
			'manage_network_users'    => array( 'core-network', __( 'Manage network users', 'blt-toolkit' ), '3.0', $network ),
			'manage_network_plugins'  => array( 'core-network', __( 'Manage network plugins', 'blt-toolkit' ), '3.0', $network ),
			'manage_network_themes'   => array( 'core-network', __( 'Manage network themes', 'blt-toolkit' ), '3.0', $network ),
			'manage_network_options'  => array( 'core-network', __( 'Change network settings', 'blt-toolkit' ), '3.0', $network ),
			'upgrade_network'         => array( 'core-network', __( 'Upgrade the network', 'blt-toolkit' ), '3.0', $network ),
			// Deprecated.
			'edit_files'              => array( 'core-deprecated', __( 'Edit files (unused)', 'blt-toolkit' ), '2.0', __( 'WordPress no longer checks this anywhere; the file editors use Edit theme files and Edit plugin files.', 'blt-toolkit' ) ),
		);

		for ( $level = 0; $level <= 10; $level++ ) {
			$list[ 'level_' . $level ] = array(
				'core-deprecated',
				/* translators: %d: user level, 0 to 10. */
				sprintf( __( 'User level %d (deprecated)', 'blt-toolkit' ), $level ),
				'2.0',
				$deprecated,
			);
		}

		$out = array();

		foreach ( $list as $cap => $row ) {
			$out[ $cap ] = array(
				'group'      => $row[0],
				'label'      => $row[1],
				'since'      => $row[2],
				'note'       => $row[3],
				'deprecated' => 'core-deprecated' === $row[0],
				'multisite'  => 'core-network' === $row[0],
			);
		}

		return $out;
	}

	/**
	 * Core's meta capabilities: names `map_meta_cap()` maps to primitives
	 * (WordPress 7.1 checked). Never stored on a role or user.
	 *
	 * Names core both maps and checks as primitives (`edit_users`,
	 * `unfiltered_html`, `install_languages`, `manage_links` and so on) are
	 * not here: they are real capabilities.
	 *
	 * @return string[]
	 */
	public static function core_meta_names() {
		return array(
			'remove_user',
			'promote_user',
			'add_users',
			'edit_user',
			'delete_user',
			'edit_post',
			'edit_page',
			'read_post',
			'read_page',
			'delete_post',
			'delete_page',
			'publish_post',
			'edit_post_meta',
			'delete_post_meta',
			'add_post_meta',
			'edit_comment_meta',
			'delete_comment_meta',
			'add_comment_meta',
			'edit_term_meta',
			'delete_term_meta',
			'add_term_meta',
			'edit_user_meta',
			'delete_user_meta',
			'add_user_meta',
			'edit_comment',
			'edit_css',
			'upload_plugins',
			'upload_themes',
			'update_languages',
			'deactivate_plugins',
			'activate_plugin',
			'deactivate_plugin',
			'resume_plugin',
			'resume_theme',
			'customize',
			'delete_site',
			'edit_term',
			'delete_term',
			'assign_term',
			'manage_post_tags',
			'edit_categories',
			'edit_post_tags',
			'delete_categories',
			'delete_post_tags',
			'assign_categories',
			'assign_post_tags',
			'setup_network',
			'update_php',
			'update_https',
			'export_others_personal_data',
			'erase_others_personal_data',
			'manage_privacy_options',
			'create_app_password',
			'list_app_passwords',
			'read_app_password',
			'edit_app_password',
			'delete_app_passwords',
			'delete_app_password',
			'edit_block_binding',
		);
	}

	/**
	 * Labels for BLT Toolkit's own capabilities (SPEC 5.1, Appendix A).
	 *
	 * @return array<string,string>
	 */
	public static function blt_labels() {
		return array(
			'blt_switch_users'        => __( 'Switch to other users', 'blt-toolkit' ),
			'blt_change_usernames'    => __( 'Change other users\' usernames', 'blt-toolkit' ),
			'blt_change_own_username' => __( 'Change their own username', 'blt-toolkit' ),
			'blt_edit_roles'          => __( 'Edit roles (Simple view)', 'blt-toolkit' ),
			'blt_edit_capabilities'   => __( 'Edit capabilities (Advanced view and per-user)', 'blt-toolkit' ),
			'blt_create_roles'        => __( 'Create roles', 'blt-toolkit' ),
			'blt_delete_roles'        => __( 'Delete and merge roles', 'blt-toolkit' ),
			'blt_reset_roles'         => __( 'Reset, restore and import roles', 'blt-toolkit' ),
			'blt_view_toolkit_log'    => __( 'View and export BLT Toolkit activity', 'blt-toolkit' ),
		);
	}

	/**
	 * Register what fills each entry's `simple` field: the Simple control a
	 * capability belongs to, such as "Simple: Events → level" (RO-21). The
	 * provider receives capability names and returns name => text; it is
	 * called once, when all() is built. Simple view (Phase 5) registers it.
	 *
	 * @param callable|null $provider Callback, or null to remove it.
	 * @return void
	 */
	public static function set_simple_provider( $provider ) {
		self::$simple_provider = is_callable( $provider ) ? $provider : null;
		self::$entries         = null;
	}

	/**
	 * Forget everything built this request. The role store calls it after a
	 * write; tests call it between cases.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$full        = null;
		self::$base        = null;
		self::$entries     = null;
		self::$base_labels = null;
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * Build the catalog from plain arrays. Pure.
	 *
	 * @param array $input {
	 *     @type array    $core       core_caps().
	 *     @type string[] $core_meta  core_meta_names().
	 *     @type array    $post_types Name => array( label, singular, cap (key => string), map_meta_cap, capability_type, builtin, show_ui ), in registration order.
	 *     @type array    $taxonomies Name => array( label, cap (key => string), builtin, show_ui, object_types ).
	 *     @type array    $declared   Group key => array( label, caps ), already cleaned (clean_declared()).
	 *     @type array    $blt        BLT Toolkit's capabilities, name => label.
	 *     @type string[] $blt_meta   BLT Toolkit's meta capabilities.
	 *     @type array    $roles      Role ID => capabilities (cap => value).
	 *     @type array    $users      User ID => capabilities meta (role keys included). `holders['users']` reports
	 *                                these keys; the site's build passes distinct sets by position (user_caps()), and
	 *                                holders() looks users up itself.
	 *     @type bool     $multisite  Whether the site is part of a network (default false). On a single site the
	 *                                multisite capabilities do nothing, so only those a role or user holds are listed.
	 *     @type array    $orphan_families Family (prefix_family()) => label for orphaned capabilities whose plugin is
	 *                                known (SPEC 15: `ure` => "From User Role Editor"). They stay orphans; their group
	 *                                gets this label and forms whatever its size.
	 * }
	 * @return array{entries:array,tree:array,content_groups:array,taxonomy_groups:array,meta_names:array,holders:array}
	 */
	public static function build( array $input ) {
		$core       = isset( $input['core'] ) ? (array) $input['core'] : array();
		$core_meta  = array_fill_keys( array_map( 'strval', isset( $input['core_meta'] ) ? (array) $input['core_meta'] : array() ), true );
		$blt_meta   = array_fill_keys( array_map( 'strval', isset( $input['blt_meta'] ) ? (array) $input['blt_meta'] : array() ), true );
		$post_types = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$taxonomies = isset( $input['taxonomies'] ) ? (array) $input['taxonomies'] : array();
		$declared   = isset( $input['declared'] ) ? (array) $input['declared'] : array();
		$blt        = isset( $input['blt'] ) ? (array) $input['blt'] : array();
		$roles      = isset( $input['roles'] ) ? (array) $input['roles'] : array();
		$users      = isset( $input['users'] ) ? (array) $input['users'] : array();
		$multisite  = ! empty( $input['multisite'] );
		$known      = isset( $input['orphan_families'] ) ? (array) $input['orphan_families'] : array();
		$held       = array();

		// What any role or user holds, with any value (RO-13: the multisite
		// capabilities are listed on a single site only when held).
		foreach ( array_merge( array_values( $roles ), array_values( $users ) ) as $caps ) {
			foreach ( is_array( $caps ) ? $caps : array() as $cap => $value ) {
				$held[ (string) $cap ] = true;
			}
		}

		// Names that are never registered as primitives: meta capabilities,
		// and the two names core treats specially.
		$exclude = $core_meta + $blt_meta + array(
			'do_not_allow' => true,
			'exist'        => true,
		);

		$content  = self::group_content_types( $post_types, $exclude, $core );
		$taxonomy = self::group_taxonomies( $taxonomies, $exclude, $core );

		// Every primitive a post type or taxonomy registers, internal types
		// included, so nothing registered can read as orphaned.
		$registered_strings = array();

		foreach ( $post_types as $type ) {
			foreach ( self::type_caps( (array) $type, $exclude ) as $string ) {
				$registered_strings[ $string ] = true;
			}
		}

		// Per-item names of types with map_meta_cap on are meta capabilities,
		// unless the same string is a primitive somewhere (ACF maps
		// `edit_post` to `manage_options`).
		$meta_names = $core_meta + $blt_meta;

		foreach ( $post_types as $type ) {
			$type = (array) $type;

			if ( empty( $type['map_meta_cap'] ) ) {
				continue;
			}

			$cap = isset( $type['cap'] ) ? (array) $type['cap'] : array();

			foreach ( self::META_KEYS as $key ) {
				$string = isset( $cap[ $key ] ) && is_scalar( $cap[ $key ] ) ? (string) $cap[ $key ] : '';

				if ( '' !== $string && ! isset( $registered_strings[ $string ] ) && ! isset( $core[ $string ] ) && ! isset( $exclude[ $string ] ) ) {
					$meta_names[ $string ] = true;
				}
			}
		}

		$entries = array();

		// 1. BLT Toolkit's own.
		foreach ( $blt as $cap => $label ) {
			$entries[ (string) $cap ] = self::entry( (string) $cap, (string) $label, self::SOURCE_BLT, __( 'BLT Toolkit', 'blt-toolkit' ), 'blt' );
		}

		// Read-only markers belong to content groups whose create capability
		// is distinct (RS-13), by their `edit_{plural}` string.
		foreach ( $content as $group ) {
			if ( ! empty( $group['internal'] ) || empty( $group['create_distinct'] ) ) {
				continue;
			}

			$plural = self::plural_of( isset( $group['caps']['edit_posts'] ) ? $group['caps']['edit_posts'] : '' );

			if ( '' === $plural ) {
				continue;
			}

			$marker = self::MARKER_PREFIX . $plural;

			if ( ! isset( $entries[ $marker ] ) ) {
				$entries[ $marker ] = self::entry(
					$marker,
					/* translators: %s: plural content type name, such as Events. */
					sprintf( __( 'View %s in the dashboard without changing them (enforced by BLT Toolkit)', 'blt-toolkit' ), $group['primary_label'] ),
					self::SOURCE_BLT,
					__( 'BLT Toolkit', 'blt-toolkit' ),
					'blt'
				);
			}
		}

		// 2. Core.
		foreach ( $core as $cap => $meta ) {
			$cap = (string) $cap;

			if ( isset( $entries[ $cap ] ) ) {
				continue;
			}

			$meta = (array) $meta;

			if ( ! $multisite && ! empty( $meta['multisite'] ) && ! isset( $held[ $cap ] ) ) {
				continue;
			}

			$row = self::entry( $cap, isset( $meta['label'] ) ? (string) $meta['label'] : self::humanize( $cap ), self::SOURCE_CORE, __( 'Core', 'blt-toolkit' ), isset( $meta['group'] ) ? (string) $meta['group'] : 'core-general' );

			$row['deprecated'] = ! empty( $meta['deprecated'] );
			$row['multisite']  = ! empty( $meta['multisite'] );
			$row['since']      = isset( $meta['since'] ) ? (string) $meta['since'] : '';
			$row['note']       = isset( $meta['note'] ) ? (string) $meta['note'] : '';

			$entries[ $cap ] = $row;
		}

		// 3. Content types, labeled from the group's first type.
		foreach ( $content as $group ) {
			foreach ( self::label_keys( $group['caps'], array_merge( self::PRIMITIVE_KEYS, self::META_KEYS ) ) as $string => $key ) {
				if ( isset( $entries[ $string ] ) ) {
					$entries[ $string ]['groups'][] = $group['id'];
					continue;
				}

				$entries[ $string ] = self::entry( $string, self::content_label( $key, $group['primary_label'] ), self::SOURCE_CONTENT, $group['primary_label'], $group['id'] );
			}
		}

		// Strings of internal types that no group shows (none in core 7.1).
		foreach ( array_keys( $registered_strings ) as $string ) {
			if ( ! isset( $entries[ $string ] ) ) {
				$entries[ $string ] = self::entry( $string, self::humanize( $string ), self::SOURCE_CORE, __( 'Core', 'blt-toolkit' ), 'core-general' );
			}
		}

		// 4. Taxonomies.
		foreach ( $taxonomy as $group ) {
			foreach ( self::label_keys( $group['caps'], self::TERM_KEYS ) as $string => $key ) {
				if ( isset( $entries[ $string ] ) ) {
					$entries[ $string ]['groups'][] = $group['id'];
					continue;
				}

				$entries[ $string ] = self::entry( $string, self::taxonomy_label( $key, $group['primary_label'] ), self::SOURCE_TAXONOMY, $group['label'], $group['id'] );
			}
		}

		// 5. Named plugin groups (`blt_toolkit_capability_groups`).
		$plugin_nodes = array();

		foreach ( $declared as $key => $group ) {
			$id = 'plugin-' . self::id_part( (string) $key );

			if ( 'plugin-' === $id ) {
				continue;
			}

			$plugin_nodes[ $id ] = array(
				'label' => (string) $group['label'],
				'caps'  => array(),
			);

			foreach ( (array) $group['caps'] as $cap ) {
				$cap = (string) $cap;

				$plugin_nodes[ $id ]['caps'][ $cap ] = true;

				if ( isset( $entries[ $cap ] ) ) {
					$entries[ $cap ]['groups'][] = $id;
					continue;
				}

				$entries[ $cap ] = self::entry( $cap, self::humanize( $cap ), self::SOURCE_PLUGIN, (string) $group['label'], $id );
			}
		}

		// 6. Present on roles and users; who holds what.
		$role_names = array_fill_keys( array_map( 'strval', array_keys( $roles ) ), true );
		$holders    = array(
			'roles' => array(),
			'users' => array(),
		);
		$present    = array();

		foreach ( $roles as $role => $caps ) {
			foreach ( is_array( $caps ) ? $caps : array() as $cap => $value ) {
				$cap = (string) $cap;

				$present[ $cap ]                   = true;
				$holders['roles'][ $cap ][ $role ] = (string) $role;
			}
		}

		foreach ( $users as $user_id => $caps ) {
			foreach ( is_array( $caps ) ? $caps : array() as $cap => $value ) {
				$cap = (string) $cap;

				// A key named like a role is a role membership, not a capability.
				if ( isset( $role_names[ $cap ] ) ) {
					continue;
				}

				$present[ $cap ]                      = true;
				$holders['users'][ $cap ][ $user_id ] = (int) $user_id;
			}
		}

		foreach ( array( 'roles', 'users' ) as $side ) {
			foreach ( $holders[ $side ] as $cap => $list ) {
				$list = array_values( $list );

				if ( 'users' === $side ) {
					sort( $list, SORT_NUMERIC );
				} else {
					sort( $list, SORT_STRING );
				}

				$holders[ $side ][ $cap ] = $list;
			}
		}

		// 7. Orphans (RO-11): present, registered by nothing. Those sharing a
		// prefix with two or more others make a detected plugin group; the
		// orphan flag stays, since nothing declares them.
		$orphans = array();

		foreach ( array_keys( $present ) as $cap ) {
			$cap = (string) $cap;

			if ( ! isset( $entries[ $cap ] ) ) {
				$orphans[] = $cap;
			}
		}

		sort( $orphans, SORT_STRING );

		// Meta capability names stored by mistake (`edit_post`) are junk, not
		// a plugin's family.
		$candidates = array_values( array_diff_key( array_combine( $orphans, $orphans ), $meta_names ) );
		$families   = self::prefix_groups( $candidates, self::PREFIX_MIN );
		$family     = array();

		// A family whose plugin is known (SPEC 15: User Role Editor's `ure_*`)
		// is grouped and labeled whatever its size, and stays orphaned.
		foreach ( $candidates as $cap ) {
			$prefix = self::prefix_family( $cap );

			if ( '' !== $prefix && isset( $known[ $prefix ] ) && ( ! isset( $families[ $prefix ] ) || ! in_array( $cap, $families[ $prefix ], true ) ) ) {
				$families[ $prefix ][] = $cap;
			}
		}

		foreach ( $families as $prefix => $caps ) {
			$id = 'plugin-' . self::id_part( $prefix );

			if ( ! isset( $plugin_nodes[ $id ] ) ) {
				$plugin_nodes[ $id ] = array(
					'label' => isset( $known[ $prefix ] ) && is_string( $known[ $prefix ] ) && '' !== $known[ $prefix ] ? $known[ $prefix ] : self::humanize( $prefix ),
					'caps'  => array(),
				);
			}

			foreach ( $caps as $cap ) {
				$plugin_nodes[ $id ]['caps'][ $cap ] = true;
				$family[ $cap ]                      = $id;
			}
		}

		foreach ( $orphans as $cap ) {
			if ( isset( $family[ $cap ] ) ) {
				$row = self::entry( $cap, self::humanize( $cap ), self::SOURCE_PLUGIN, $plugin_nodes[ $family[ $cap ] ]['label'], $family[ $cap ] );
			} else {
				$row = self::entry( $cap, self::humanize( $cap ), self::SOURCE_ORPHAN, __( 'Orphaned', 'blt-toolkit' ), 'orphaned' );
			}

			$row['orphan']   = true;
			$row['groups'][] = 'orphaned';

			if ( isset( $meta_names[ $cap ] ) ) {
				$row['note'] = __( 'WordPress checks this name for one item at a time, so stored on a role or user it does nothing.', 'blt-toolkit' );
			}

			$entries[ $cap ] = $row;
		}

		foreach ( $entries as $cap => $row ) {
			$entries[ $cap ]['groups'] = array_values( array_unique( $row['groups'] ) );
		}

		return array(
			'entries'         => $entries,
			'tree'            => self::build_tree( $entries, $content, $taxonomy, $plugin_nodes ),
			'content_groups'  => $content,
			'taxonomy_groups' => $taxonomy,
			'meta_names'      => $meta_names,
			'holders'         => $holders,
		);
	}

	/**
	 * Group content types by identical capability strings (RO-14). Pure.
	 *
	 * Types shown in the dashboard (`show_ui`) that are not internal (core's
	 * own types other than Posts and Pages) form the visible groups: a type
	 * registered with `capability_type => 'post'` has exactly Posts' strings,
	 * so it joins the Posts group. Attachments are left to Media. Any other
	 * type (hidden, or internal) forms a group, marked `internal`, only when
	 * it registers a string no visible group or core capability has, and
	 * never joins a visible group: a hidden type with Posts' strings must not
	 * read as "also used by" Posts.
	 *
	 * Each group:
	 *
	 * - `id`: `content-{first type}`, URL-safe.
	 * - `label`: "Posts (also used by: Events, Testimonials)", or the one
	 *   type's plural name; `primary`, `primary_label`, `types` and
	 *   `type_labels` (type => plural name), Posts and Pages first.
	 * - `caps`: key => capability string (the primitive keys, plus the
	 *   per-item keys for a type with `map_meta_cap` false), without meta
	 *   capabilities or `do_not_allow`.
	 * - `map_meta_cap`: true only when every member maps meta capabilities
	 *   (RS-12).
	 * - `capability_type`: the first type's, as array( singular, plural ).
	 * - `create_distinct`: `create_posts` differs from `edit_posts` (RS-13).
	 * - `shares_posts`, `shares_pages`: the group is not Posts' (or Pages')
	 *   yet uses some of its strings (`read` aside).
	 * - `shared_with`: key => 'post' or 'page' for each string Posts or Pages
	 *   also use.
	 * - `foreign`: key => string for each string that is neither the group's
	 *   own (it contains the type's capability_type) nor shared with Posts or
	 *   Pages, such as `manage_options` in an ACF-style type;
	 *   `excluded_from_levels` is true when there is one (RS-11).
	 * - `internal`: see above.
	 * - `also_used_by`: string => the plural names of the types in other
	 *   visible groups that use it too (add_overlaps()).
	 *
	 * @param array $types   Name => array( label, singular, cap, map_meta_cap, capability_type, builtin, show_ui ), in registration order.
	 * @param array $exclude Names never registered as primitives (name => true).
	 * @param array $core    core_caps() (only the keys are read).
	 * @return array[] Groups, visible ones first, Posts and Pages first among them.
	 */
	public static function group_content_types( array $types, array $exclude = array(), array $core = array() ) {
		$visible = array();
		$hidden  = array();

		foreach ( $types as $name => $type ) {
			$name = (string) $name;
			$type = (array) $type;

			if ( 'attachment' === $name ) {
				continue;
			}

			$builtin    = ! empty( $type['builtin'] );
			$is_visible = ! empty( $type['show_ui'] ) && ( ! $builtin || in_array( $name, array( 'post', 'page' ), true ) );

			if ( $is_visible ) {
				$visible[ $name ] = $type;
			} else {
				$hidden[ $name ] = $type;
			}
		}

		// Posts and Pages lead their groups and the list.
		$visible = self::posts_and_pages_first( $visible );
		$groups  = self::group_types( $visible, $exclude, false );

		$covered = array();

		foreach ( $groups as $group ) {
			foreach ( $group['caps'] as $string ) {
				$covered[ $string ] = true;
			}
		}

		$extra = array();

		foreach ( $hidden as $name => $type ) {
			foreach ( self::type_caps( $type, $exclude ) as $string ) {
				if ( ! isset( $covered[ $string ] ) && ! isset( $core[ $string ] ) ) {
					$extra[ $name ] = $type;
					break;
				}
			}
		}

		$groups = array_merge( $groups, self::group_types( $extra, $exclude, true ) );

		$posts = isset( $types['post'] ) ? self::type_caps( (array) $types['post'], $exclude ) : array();
		$pages = isset( $types['page'] ) ? self::type_caps( (array) $types['page'], $exclude ) : array();

		foreach ( $groups as $index => $group ) {
			$groups[ $index ] = self::describe_group( $group, $posts, $pages );
		}

		return self::add_overlaps( array_values( $groups ) );
	}

	/**
	 * Add `also_used_by` to each content group (RO-14, SPEC 19 URE-4):
	 * capability string => the plural names of the types in other visible
	 * groups that use the same string. A type that uses only some of another
	 * type's strings (Patterns uses Posts' edit_posts; a type registered
	 * with `capability_type => 'post'` and `map_meta_cap` false) is a group
	 * of its own, yet a change to one of those strings in it changes the
	 * other type too. `read` is left out, as everywhere in content groups.
	 * Hidden and internal types never count as users of a string, so Posts
	 * does not read as shared with Patterns. Pure.
	 *
	 * @param array[] $groups Groups from group_content_types().
	 * @return array[]
	 */
	private static function add_overlaps( array $groups ) {
		$users = array();

		foreach ( $groups as $index => $group ) {
			if ( ! empty( $group['internal'] ) ) {
				continue;
			}

			foreach ( array_unique( array_values( $group['caps'] ) ) as $string ) {
				if ( 'read' !== $string ) {
					$users[ (string) $string ][ $index ] = true;
				}
			}
		}

		foreach ( $groups as $index => $group ) {
			$also = array();

			foreach ( array_unique( array_values( $group['caps'] ) ) as $string ) {
				$string = (string) $string;

				if ( ! isset( $users[ $string ] ) ) {
					continue;
				}

				$names = array();

				foreach ( array_keys( $users[ $string ] ) as $other ) {
					if ( $other !== $index ) {
						$names = array_merge( $names, array_values( $groups[ $other ]['type_labels'] ) );
					}
				}

				if ( array() !== $names ) {
					$also[ $string ] = array_values( array_unique( $names ) );
				}
			}

			ksort( $also, SORT_STRING );

			$groups[ $index ]['also_used_by'] = $also;
		}

		return $groups;
	}

	/**
	 * Group taxonomies by identical capability strings. Pure.
	 *
	 * Only taxonomies with a string of their own (not a core capability and
	 * not a meta capability) make a group; those using core's defaults
	 * (`manage_categories`, `edit_posts`) are covered by Core.
	 *
	 * Each group: `id` (`taxonomy-{first}`), `label` (every member's plural
	 * name), `primary`, `primary_label`, `taxonomies`, `object_types` (post
	 * types they attach to), `caps` (key => string) and `dedicated` (true
	 * when manage, edit and delete are all the group's own strings, RS 10.3).
	 *
	 * @param array $taxonomies Name => array( label, cap, builtin, show_ui, object_types ).
	 * @param array $exclude    Names never registered as primitives (name => true).
	 * @param array $core       core_caps() (only the keys are read).
	 * @return array[]
	 */
	public static function group_taxonomies( array $taxonomies, array $exclude = array(), array $core = array() ) {
		$groups = array();

		foreach ( $taxonomies as $name => $taxonomy ) {
			$name     = (string) $name;
			$taxonomy = (array) $taxonomy;
			$cap      = isset( $taxonomy['cap'] ) ? (array) $taxonomy['cap'] : array();
			$caps     = array();
			$own      = false;

			foreach ( self::TERM_KEYS as $key ) {
				$string = isset( $cap[ $key ] ) && is_scalar( $cap[ $key ] ) ? (string) $cap[ $key ] : '';

				if ( '' === $string || isset( $exclude[ $string ] ) ) {
					continue;
				}

				$caps[ $key ] = $string;

				if ( ! isset( $core[ $string ] ) ) {
					$own = true;
				}
			}

			if ( ! $own ) {
				continue;
			}

			$key = self::signature( $caps );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'id'            => 'taxonomy-' . self::id_part( $name ),
					'label'         => '',
					'primary'       => $name,
					'primary_label' => self::type_label( $taxonomy, $name ),
					'taxonomies'    => array(),
					'labels'        => array(),
					'object_types'  => array(),
					'caps'          => $caps,
					'dedicated'     => true,
				);

				foreach ( array( 'manage_terms', 'edit_terms', 'delete_terms' ) as $own_key ) {
					if ( ! isset( $caps[ $own_key ] ) || isset( $core[ $caps[ $own_key ] ] ) ) {
						$groups[ $key ]['dedicated'] = false;
					}
				}
			}

			$groups[ $key ]['taxonomies'][]    = $name;
			$groups[ $key ]['labels'][ $name ] = self::type_label( $taxonomy, $name );
			$groups[ $key ]['object_types']    = array_values( array_unique( array_merge( $groups[ $key ]['object_types'], array_map( 'strval', isset( $taxonomy['object_types'] ) ? (array) $taxonomy['object_types'] : array() ) ) ) );
			$groups[ $key ]['label']           = implode( ', ', array_values( $groups[ $key ]['labels'] ) );
		}

		return array_values( $groups );
	}

	/**
	 * The family a capability's name points to, for prefix grouping: its
	 * first word that is not a common verb (`gravityforms_edit_forms` gives
	 * `gravityforms`, `manage_woocommerce` and `view_woocommerce_reports`
	 * give `woocommerce`). Pure.
	 *
	 * @param string $cap Capability.
	 * @return string Lowercase family, or '' when there is none.
	 */
	public static function prefix_family( $cap ) {
		$words = preg_split( '/[\s_\-]+/', strtolower( (string) $cap ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( (array) $words as $word ) {
			if ( in_array( $word, self::VERBS, true ) ) {
				continue;
			}

			return strlen( $word ) >= 2 ? $word : '';
		}

		return '';
	}

	/**
	 * Best-effort groups by shared prefix (RO-13): families with at least
	 * `$min` members. Pure.
	 *
	 * @param string[] $caps Capability names.
	 * @param int      $min  Smallest group.
	 * @return array<string,string[]> Family => names, both sorted.
	 */
	public static function prefix_groups( array $caps, $min = self::PREFIX_MIN ) {
		$families = array();

		foreach ( $caps as $cap ) {
			$cap    = (string) $cap;
			$family = self::prefix_family( $cap );

			if ( '' !== $family ) {
				$families[ $family ][ $cap ] = true;
			}
		}

		$out = array();

		foreach ( $families as $family => $members ) {
			if ( count( $members ) >= max( 2, (int) $min ) ) {
				$names = array_map( 'strval', array_keys( $members ) );

				sort( $names, SORT_STRING );

				$out[ (string) $family ] = $names;
			}
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * A capability name as words: underscores and hyphens become spaces, the
	 * first letter is capitalized (RO-12's fallback). Pure.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	public static function humanize( $cap ) {
		$text = trim( (string) preg_replace( '/[\s_\-]+/', ' ', (string) $cap ) );

		return '' === $text ? (string) $cap : ucfirst( $text );
	}

	/**
	 * A content type capability's label from the type's plural name (RO-12),
	 * such as "Edit others' Events". Pure.
	 *
	 * @param string $key    Key in the post type's `cap` object.
	 * @param string $plural Plural name, such as Events.
	 * @return string
	 */
	public static function content_label( $key, $plural ) {
		$plural = (string) $plural;

		switch ( (string) $key ) {
			case 'edit_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Create and edit their own %s', 'blt-toolkit' ), $plural );
			case 'edit_others_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Edit others\' %s', 'blt-toolkit' ), $plural );
			case 'edit_published_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Edit published %s', 'blt-toolkit' ), $plural );
			case 'edit_private_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Edit private %s', 'blt-toolkit' ), $plural );
			case 'publish_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Publish %s', 'blt-toolkit' ), $plural );
			case 'read_private_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Read private %s', 'blt-toolkit' ), $plural );
			case 'delete_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Delete their own %s', 'blt-toolkit' ), $plural );
			case 'delete_others_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Delete others\' %s', 'blt-toolkit' ), $plural );
			case 'delete_published_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Delete published %s', 'blt-toolkit' ), $plural );
			case 'delete_private_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Delete private %s', 'blt-toolkit' ), $plural );
			case 'create_posts':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Create %s', 'blt-toolkit' ), $plural );
			case 'read':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Read %s', 'blt-toolkit' ), $plural );
			case 'edit_post':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Edit any one of the %s', 'blt-toolkit' ), $plural );
			case 'read_post':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Read any one of the %s', 'blt-toolkit' ), $plural );
			case 'delete_post':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Delete any one of the %s', 'blt-toolkit' ), $plural );
		}

		return self::humanize( $key );
	}

	/**
	 * A taxonomy capability's label from the taxonomy's plural name (RO-12),
	 * such as "Manage Event Categories". Pure.
	 *
	 * @param string $key    Key in the taxonomy's `cap` object.
	 * @param string $plural Plural name.
	 * @return string
	 */
	public static function taxonomy_label( $key, $plural ) {
		$plural = (string) $plural;

		switch ( (string) $key ) {
			case 'manage_terms':
				/* translators: %s: plural taxonomy name, such as Event Categories. */
				return sprintf( __( 'Manage %s', 'blt-toolkit' ), $plural );
			case 'edit_terms':
				/* translators: %s: plural taxonomy name, such as Event Categories. */
				return sprintf( __( 'Edit %s', 'blt-toolkit' ), $plural );
			case 'delete_terms':
				/* translators: %s: plural taxonomy name, such as Event Categories. */
				return sprintf( __( 'Delete %s', 'blt-toolkit' ), $plural );
			case 'assign_terms':
				/* translators: %s: plural taxonomy name, such as Event Categories. */
				return sprintf( __( 'Assign %s', 'blt-toolkit' ), $plural );
		}

		return self::humanize( $key );
	}

	/**
	 * Why a name cannot be a capability (RO-23) or a role ID (RO-30, with a
	 * maximum of 64), as a code; '' when it can. Pure.
	 *
	 * @param string $name Proposed name.
	 * @param int    $max  Longest allowed, in characters.
	 * @return string '', `empty`, `chars`, `no_letter` or `too_long`.
	 */
	public static function name_error( $name, $max = self::NAME_MAX ) {
		$name = (string) $name;

		if ( '' === $name ) {
			return 'empty';
		}

		if ( ! preg_match( '/^[a-z0-9_\-]+$/', $name ) ) {
			return 'chars';
		}

		if ( ! preg_match( '/[a-z]/', $name ) ) {
			return 'no_letter';
		}

		if ( strlen( $name ) > (int) $max ) {
			return 'too_long';
		}

		return '';
	}

	/**
	 * The message for a name_error() code.
	 *
	 * @param string $code Code.
	 * @param int    $max  Longest allowed.
	 * @return string
	 */
	public static function name_error_message( $code, $max = self::NAME_MAX ) {
		switch ( (string) $code ) {
			case 'empty':
				return __( 'Enter a name.', 'blt-toolkit' );
			case 'chars':
				return __( 'Use only lowercase letters, digits, underscores and hyphens.', 'blt-toolkit' );
			case 'no_letter':
				return __( 'Include at least one letter.', 'blt-toolkit' );
			case 'too_long':
				/* translators: %d: maximum number of characters. */
				return sprintf( __( 'Use at most %d characters.', 'blt-toolkit' ), (int) $max );
		}

		return '';
	}

	/**
	 * Clean the value of `blt_toolkit_capability_groups`. Pure.
	 *
	 * @param mixed $groups Group key => array( 'label' => string, 'caps' => string[] ).
	 * @return array<string,array{label:string,caps:string[]}> Groups with at least one capability.
	 */
	public static function clean_declared( $groups ) {
		$out = array();

		if ( ! is_array( $groups ) ) {
			return $out;
		}

		foreach ( $groups as $key => $group ) {
			$key = self::id_part( (string) $key );

			if ( '' === $key || ! is_array( $group ) ) {
				continue;
			}

			$caps = array();

			foreach ( isset( $group['caps'] ) && is_array( $group['caps'] ) ? $group['caps'] : array() as $cap ) {
				if ( is_string( $cap ) && '' !== trim( $cap ) && strlen( $cap ) <= self::NAME_MAX ) {
					$caps[ trim( $cap ) ] = true;
				}
			}

			if ( array() === $caps ) {
				continue;
			}

			$label = isset( $group['label'] ) && is_string( $group['label'] ) && '' !== trim( $group['label'] ) ? trim( $group['label'] ) : self::humanize( $key );

			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]['caps'] = array_values( array_unique( array_merge( $out[ $key ]['caps'], array_keys( $caps ) ) ) );
				continue;
			}

			$out[ $key ] = array(
				'label' => $label,
				'caps'  => array_map( 'strval', array_keys( $caps ) ),
			);
		}

		return $out;
	}

	// ---------- Pure helpers ----------

	/**
	 * A post type's primitive capability strings, key => string. Pure.
	 *
	 * @param array $type    Type as in group_content_types().
	 * @param array $exclude Names never registered as primitives.
	 * @return array<string,string>
	 */
	private static function type_caps( array $type, array $exclude ) {
		$cap = isset( $type['cap'] ) ? (array) $type['cap'] : array();
		$out = array();

		foreach ( self::PRIMITIVE_KEYS as $key ) {
			$string = isset( $cap[ $key ] ) && is_scalar( $cap[ $key ] ) ? (string) $cap[ $key ] : '';

			if ( '' !== $string && ! isset( $exclude[ $string ] ) ) {
				$out[ $key ] = $string;
			}
		}

		// With map_meta_cap off, core checks the per-item names themselves
		// (map_meta_cap() returns `$post_type->cap->edit_post`), even when
		// the name is core's own `read_post`, as in a type registered with
		// `capability_type => 'post'` and `capabilities` overrides. Core's
		// own such types (`custom_css`) only point at meta capabilities.
		if ( empty( $type['map_meta_cap'] ) ) {
			$never = empty( $type['builtin'] )
				? array(
					'do_not_allow' => true,
					'exist'        => true,
				)
				: $exclude;

			foreach ( self::META_KEYS as $key ) {
				$string = isset( $cap[ $key ] ) && is_scalar( $cap[ $key ] ) ? (string) $cap[ $key ] : '';

				if ( '' !== $string && ! isset( $never[ $string ] ) ) {
					$out[ $key ] = $string;
				}
			}
		}

		return $out;
	}

	/**
	 * Group types with identical strings (and the same `map_meta_cap`). Pure.
	 *
	 * @param array $types    Name => type.
	 * @param array $exclude  Names never registered as primitives.
	 * @param bool  $internal Whether these are hidden or internal types.
	 * @return array[] Raw groups.
	 */
	private static function group_types( array $types, array $exclude, $internal ) {
		$groups = array();

		foreach ( $types as $name => $type ) {
			$name = (string) $name;
			$type = (array) $type;
			$caps = self::type_caps( $type, $exclude );
			$mmc  = ! empty( $type['map_meta_cap'] );
			$key  = self::signature( $caps ) . '|' . ( $mmc ? '1' : '0' );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'id'              => 'content-' . self::id_part( $name ),
					'primary'         => $name,
					'primary_label'   => self::type_label( $type, $name ),
					'types'           => array(),
					'type_labels'     => array(),
					'caps'            => $caps,
					'map_meta_cap'    => $mmc,
					'capability_type' => self::capability_type( $type, $name ),
					'internal'        => (bool) $internal,
				);
			}

			$groups[ $key ]['types'][]              = $name;
			$groups[ $key ]['type_labels'][ $name ] = self::type_label( $type, $name );
		}

		return array_values( $groups );
	}

	/**
	 * Add the derived keys of a content group (see group_content_types()).
	 * Pure.
	 *
	 * @param array $group Raw group.
	 * @param array $posts Posts' strings (key => string).
	 * @param array $pages Pages' strings (key => string).
	 * @return array
	 */
	private static function describe_group( array $group, array $posts, array $pages ) {
		$others = array();

		foreach ( $group['type_labels'] as $name => $label ) {
			if ( $name !== $group['primary'] ) {
				$others[] = $label;
			}
		}

		$group['label'] = array() === $others
			? $group['primary_label']
			/* translators: 1: plural content type name, such as Posts, 2: comma-separated names of the other types that share its permissions. */
			: sprintf( __( '%1$s (also used by: %2$s)', 'blt-toolkit' ), $group['primary_label'], implode( ', ', $others ) );

		$post_strings = array_flip( array_values( array_diff_key( $posts, array( 'read' => true ) ) ) );
		$page_strings = array_flip( array_values( array_diff_key( $pages, array( 'read' => true ) ) ) );
		$own          = array_filter( array_map( 'strtolower', $group['capability_type'] ) );
		$is_posts     = in_array( 'post', $group['types'], true );
		$is_pages     = in_array( 'page', $group['types'], true );

		$group['create_distinct']      = isset( $group['caps']['create_posts'], $group['caps']['edit_posts'] ) && $group['caps']['create_posts'] !== $group['caps']['edit_posts'];
		$group['shares_posts']         = false;
		$group['shares_pages']         = false;
		$group['shared_with']          = array();
		$group['foreign']              = array();
		$group['excluded_from_levels'] = false;

		foreach ( $group['caps'] as $key => $string ) {
			if ( 'read' === $key && 'read' === $string ) {
				continue;
			}

			if ( isset( $post_strings[ $string ] ) ) {
				$group['shared_with'][ $key ] = 'post';
				$group['shares_posts']        = $group['shares_posts'] || ! $is_posts;
				continue;
			}

			if ( isset( $page_strings[ $string ] ) ) {
				$group['shared_with'][ $key ] = 'page';
				$group['shares_pages']        = $group['shares_pages'] || ! $is_pages;
				continue;
			}

			$dedicated = false;

			foreach ( $own as $word ) {
				if ( false !== strpos( strtolower( $string ), $word ) ) {
					$dedicated = true;
					break;
				}
			}

			if ( ! $dedicated ) {
				$group['foreign'][ $key ]      = $string;
				$group['excluded_from_levels'] = true;
			}
		}

		return $group;
	}

	/**
	 * Post and page first, then the rest in their order. Pure.
	 *
	 * @param array $types Name => type.
	 * @return array
	 */
	private static function posts_and_pages_first( array $types ) {
		$first = array();

		foreach ( array( 'post', 'page' ) as $name ) {
			if ( isset( $types[ $name ] ) ) {
				$first[ $name ] = $types[ $name ];
				unset( $types[ $name ] );
			}
		}

		return $first + $types;
	}

	/**
	 * A type's capability_type as array( singular, plural ). Pure.
	 *
	 * @param array  $type Type.
	 * @param string $name Type name (fallback).
	 * @return string[]
	 */
	private static function capability_type( array $type, $name ) {
		$value = isset( $type['capability_type'] ) ? $type['capability_type'] : $name;

		if ( is_array( $value ) ) {
			$singular = isset( $value[0] ) && is_scalar( $value[0] ) ? (string) $value[0] : (string) $name;
			$plural   = isset( $value[1] ) && is_scalar( $value[1] ) ? (string) $value[1] : $singular . 's';

			return array( $singular, $plural );
		}

		$singular = is_scalar( $value ) && '' !== (string) $value ? (string) $value : (string) $name;

		return array( $singular, $singular . 's' );
	}

	/**
	 * A type's or taxonomy's plural name, or its name. Pure.
	 *
	 * @param array  $type Type or taxonomy.
	 * @param string $name Name.
	 * @return string
	 */
	private static function type_label( array $type, $name ) {
		return isset( $type['label'] ) && is_scalar( $type['label'] ) && '' !== (string) $type['label'] ? (string) $type['label'] : (string) $name;
	}

	/**
	 * A stable key for a set of key => string pairs. Pure.
	 *
	 * @param array $caps Key => string.
	 * @return string
	 */
	private static function signature( array $caps ) {
		ksort( $caps, SORT_STRING );

		return (string) wp_json_encode( $caps );
	}

	/**
	 * Each distinct string of a group with the key that names it, by the
	 * label order of `$order`. Pure.
	 *
	 * @param array    $caps  Key => string.
	 * @param string[] $order Keys in label order.
	 * @return array<string,string> String => key.
	 */
	private static function label_keys( array $caps, array $order ) {
		$out = array();

		foreach ( $order as $key ) {
			if ( isset( $caps[ $key ] ) && ! isset( $out[ $caps[ $key ] ] ) ) {
				$out[ (string) $caps[ $key ] ] = $key;
			}
		}

		// Plain `read` belongs to Core, not to each content group.
		if ( isset( $out['read'] ) && 'read' === $out['read'] ) {
			unset( $out['read'] );
		}

		return $out;
	}

	/**
	 * The plural word of an `edit_{plural}` string, or ''. Pure.
	 *
	 * @param string $edit_posts A group's `edit_posts` string.
	 * @return string
	 */
	private static function plural_of( $edit_posts ) {
		$edit_posts = (string) $edit_posts;

		return 0 === strpos( $edit_posts, 'edit_' ) && strlen( $edit_posts ) > 5 ? substr( $edit_posts, 5 ) : '';
	}

	/**
	 * A URL-safe node ID part. Pure.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function id_part( $value ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}

	/**
	 * A catalog entry with its defaults. Pure.
	 *
	 * @param string $cap          Capability.
	 * @param string $label        Label.
	 * @param string $source       Source.
	 * @param string $source_label Source as words.
	 * @param string $group        Primary node ID.
	 * @return array
	 */
	private static function entry( $cap, $label, $source, $source_label, $group ) {
		return array(
			'label'        => '' !== (string) $label ? (string) $label : self::humanize( $cap ),
			'raw'          => (string) $cap,
			'source'       => (string) $source,
			'source_label' => (string) $source_label,
			'group'        => (string) $group,
			'groups'       => array( (string) $group ),
			'tier'         => BLT_Toolkit_Caps::STANDARD,
			'orphan'       => false,
			'deprecated'   => false,
			'multisite'    => false,
			'since'        => '',
			'note'         => '',
			'simple'       => null,
		);
	}

	/**
	 * The group tree from the entries and groups. Pure.
	 *
	 * @param array $entries      Entries.
	 * @param array $content      Content groups.
	 * @param array $taxonomy     Taxonomy groups.
	 * @param array $plugin_nodes Node ID => array( label, caps (name => true) ).
	 * @return array[]
	 */
	private static function build_tree( array $entries, array $content, array $taxonomy, array $plugin_nodes ) {
		$labels = array(
			'core-general'    => __( 'General', 'blt-toolkit' ),
			'core-appearance' => __( 'Appearance', 'blt-toolkit' ),
			'core-posts'      => __( 'Posts', 'blt-toolkit' ),
			'core-pages'      => __( 'Pages', 'blt-toolkit' ),
			'core-media'      => __( 'Media', 'blt-toolkit' ),
			'core-plugins'    => __( 'Plugins', 'blt-toolkit' ),
			'core-users'      => __( 'Users', 'blt-toolkit' ),
			'core-network'    => __( 'Network (multisite only)', 'blt-toolkit' ),
			'core-deprecated' => __( 'Deprecated', 'blt-toolkit' ),
		);

		$by_group = array();
		$blt      = array();
		$orphaned = array();

		foreach ( $entries as $cap => $entry ) {
			$cap = (string) $cap;

			if ( self::SOURCE_CORE === $entry['source'] ) {
				$by_group[ $entry['group'] ][] = $cap;
			}

			if ( self::SOURCE_BLT === $entry['source'] ) {
				$blt[] = $cap;
			}

			if ( ! empty( $entry['orphan'] ) ) {
				$orphaned[] = $cap;
			}
		}

		$core_children = array();

		foreach ( self::CORE_NODES as $id ) {
			if ( ! empty( $by_group[ $id ] ) ) {
				$core_children[] = self::node_of( $id, $labels[ $id ], $by_group[ $id ] );
			}
		}

		$content_children = array();

		foreach ( $content as $group ) {
			$caps = array_keys( self::label_keys( $group['caps'], array_merge( self::PRIMITIVE_KEYS, self::META_KEYS ) ) );
			$node = self::node_of( $group['id'], $group['label'], $caps );

			$node['types']    = $group['types'];
			$node['internal'] = ! empty( $group['internal'] );

			$content_children[] = $node;
		}

		$taxonomy_children = array();

		foreach ( $taxonomy as $group ) {
			$node = self::node_of( $group['id'], $group['label'], array_values( array_unique( array_values( $group['caps'] ) ) ) );

			$node['taxonomies'] = $group['taxonomies'];

			$taxonomy_children[] = $node;
		}

		$plugin_children = array();

		foreach ( $plugin_nodes as $id => $node ) {
			$plugin_children[] = self::node_of( (string) $id, (string) $node['label'], array_keys( $node['caps'] ) );
		}

		usort(
			$plugin_children,
			static function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return array(
			self::node_of( 'all', __( 'All', 'blt-toolkit' ), array_keys( $entries ) ),
			self::node_of( 'core', __( 'Core', 'blt-toolkit' ), array(), $core_children ),
			self::node_of( 'content', __( 'Content types', 'blt-toolkit' ), array(), $content_children ),
			self::node_of( 'taxonomies', __( 'Taxonomies', 'blt-toolkit' ), array(), $taxonomy_children ),
			self::node_of( 'plugins', __( 'Plugins', 'blt-toolkit' ), array(), $plugin_children ),
			self::node_of( 'blt', __( 'BLT Toolkit', 'blt-toolkit' ), $blt ),
			self::node_of( 'orphaned', __( 'Orphaned', 'blt-toolkit' ), $orphaned ),
		);
	}

	/**
	 * One tree node; a parent's capabilities are its own plus its children's.
	 * Pure.
	 *
	 * @param string   $id       ID.
	 * @param string   $label    Label.
	 * @param string[] $caps     Capabilities.
	 * @param array[]  $children Child nodes.
	 * @return array
	 */
	private static function node_of( $id, $label, array $caps, array $children = array() ) {
		$all = array();

		foreach ( $caps as $cap ) {
			$all[ (string) $cap ] = true;
		}

		foreach ( $children as $child ) {
			foreach ( $child['caps'] as $cap ) {
				$all[ (string) $cap ] = true;
			}
		}

		$all = array_map( 'strval', array_keys( $all ) );

		sort( $all, SORT_STRING );

		return array(
			'id'       => (string) $id,
			'label'    => (string) $label,
			'caps'     => $all,
			'count'    => count( $all ),
			'children' => $children,
		);
	}

	/**
	 * Find a node by ID, depth first. Pure.
	 *
	 * @param array[] $nodes Nodes.
	 * @param string  $id    ID.
	 * @return array|null
	 */
	private static function find_node( array $nodes, $id ) {
		foreach ( $nodes as $node ) {
			if ( $node['id'] === $id ) {
				return $node;
			}

			$found = self::find_node( $node['children'], $id );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	// ---------- Reading WordPress ----------

	/**
	 * The full build: registered sources, roles and users. Built once per
	 * request (and again only after flush()).
	 *
	 * @return array
	 */
	private static function full() {
		if ( null === self::$full ) {
			$input          = self::input();
			$input['users'] = self::user_caps();
			self::$full     = self::build( $input );
		}

		return self::$full;
	}

	/**
	 * The build without the user query, for labels, groups and meta names.
	 *
	 * @return array
	 */
	private static function base() {
		if ( null !== self::$full ) {
			return self::$full;
		}

		if ( null === self::$base ) {
			self::$base = self::build( self::input() );
		}

		return self::$base;
	}

	/**
	 * Everything build() needs except the users, read from memory: core
	 * lists, post types, taxonomies, declared groups, BLT Toolkit's
	 * capabilities and the roles. No query beyond what WordPress loaded.
	 *
	 * @return array
	 */
	private static function input() {
		$post_types = array();

		foreach ( get_post_types( array(), 'objects' ) as $name => $type ) {
			if ( ! $type instanceof WP_Post_Type ) {
				continue;
			}

			$post_types[ (string) $name ] = array(
				'label'           => isset( $type->labels->name ) ? (string) $type->labels->name : (string) $name,
				'singular'        => isset( $type->labels->singular_name ) ? (string) $type->labels->singular_name : (string) $name,
				'cap'             => (array) $type->cap,
				'map_meta_cap'    => (bool) $type->map_meta_cap,
				'capability_type' => $type->capability_type,
				'builtin'         => (bool) $type->_builtin,
				'show_ui'         => (bool) $type->show_ui,
			);
		}

		$taxonomies = array();

		foreach ( get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy ) {
				continue;
			}

			$taxonomies[ (string) $name ] = array(
				'label'        => isset( $taxonomy->labels->name ) ? (string) $taxonomy->labels->name : (string) $name,
				'cap'          => (array) $taxonomy->cap,
				'builtin'      => (bool) $taxonomy->_builtin,
				'show_ui'      => (bool) $taxonomy->show_ui,
				'object_types' => (array) $taxonomy->object_type,
			);
		}

		$roles = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$roles[ (string) $id ] = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
		}

		$blt    = array();
		$labels = self::blt_labels();

		foreach ( array_keys( BLT_Toolkit_Caps::own() ) as $cap ) {
			$blt[ $cap ] = isset( $labels[ $cap ] ) ? $labels[ $cap ] : self::humanize( $cap );
		}

		return array(
			'core'            => self::core_caps(),
			'core_meta'       => self::core_meta_names(),
			'post_types'      => $post_types,
			'taxonomies'      => $taxonomies,
			'declared'        => self::declared(),
			'blt'             => $blt,
			'blt_meta'        => array_keys( BLT_Toolkit_Caps::meta() ),
			'roles'           => $roles,
			'users'           => array(),
			'multisite'       => is_multisite(),
			'orphan_families' => self::orphan_families(),
		);
	}

	/**
	 * Labels of orphaned capability families whose plugin is known: User Role
	 * Editor's `ure_*` (SPEC 15, BLT_Toolkit_Legacy::orphan_families()), then
	 * `blt_toolkit_orphan_family_labels`. The capabilities stay orphans (RO-11):
	 * nothing registers them, so RO-24 may still delete them.
	 *
	 * @return array<string,string> Family => label.
	 */
	private static function orphan_families() {
		$defaults = class_exists( 'BLT_Toolkit_Legacy' ) ? BLT_Toolkit_Legacy::orphan_families() : array();

		/**
		 * Filter the labels of orphaned capability families (RO-11, RO-13).
		 * A family is the first word of a capability's name that is not a
		 * common verb (`ure` for `ure_edit_roles`); its orphans are grouped
		 * under Plugins with this label, however few they are, and stay
		 * orphaned.
		 *
		 * @param array<string,string> $labels Family => label. Default: `ure` => "From User Role Editor".
		 */
		$filtered = apply_filters( 'blt_toolkit_orphan_family_labels', $defaults );
		$out      = array();

		foreach ( is_array( $filtered ) ? $filtered : array() as $family => $label ) {
			$family = strtolower( (string) $family );

			if ( 1 === preg_match( '/^[a-z0-9]{2,}$/', $family ) && is_scalar( $label ) && '' !== trim( (string) $label ) ) {
				$out[ $family ] = trim( (string) $label );
			}
		}

		return $out;
	}

	/**
	 * Named groups: the defaults for plugins whose capabilities can be read
	 * from their own code while they are active (WooCommerce's core set,
	 * `WC_Install::get_core_capabilities()`, the list it grants to
	 * administrators and shop managers; Gravity Forms'
	 * `GFCommon::all_caps()`), then `blt_toolkit_capability_groups`.
	 *
	 * @return array
	 */
	private static function declared() {
		$defaults = array();

		if ( class_exists( 'WooCommerce', false ) ) {
			$caps = self::woocommerce_caps();

			if ( class_exists( 'WC_Install', false ) && is_callable( array( 'WC_Install', 'get_core_capabilities' ) ) ) {
				try {
					$caps = array_merge( $caps, self::flatten_caps( call_user_func( array( 'WC_Install', 'get_core_capabilities' ) ) ) );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$defaults['woocommerce'] = array(
				'label' => 'WooCommerce',
				'caps'  => array_values( array_unique( $caps ) ),
			);
		}

		if ( class_exists( 'GFCommon', false ) && is_callable( array( 'GFCommon', 'all_caps' ) ) ) {
			try {
				$caps = call_user_func( array( 'GFCommon', 'all_caps' ) );

				if ( is_array( $caps ) ) {
					$defaults['gravityforms'] = array(
						'label' => 'Gravity Forms',
						'caps'  => array_values( array_filter( $caps, 'is_string' ) ),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Filter the named capability groups of the Advanced view's tree
		 * (RO-13). A capability declared here counts as registered, so it is
		 * never flagged as orphaned (RO-11) or offered for deletion (RO-24).
		 *
		 * @param array $groups Group key => array( 'label' => string, 'caps' => string[] ).
		 */
		$declared = self::clean_declared( apply_filters( 'blt_toolkit_capability_groups', $defaults ) );

		return $declared + self::clean_declared( self::isolated_groups() );
	}

	/**
	 * A named group for each type with separate permissions that are not in
	 * effect now (its plugin is inactive, or RS-33 found its capabilities
	 * changed): its isolated strings and marker (RO-11, RS-32). The type is
	 * still on the isolated list, so they are BLT Toolkit's and come back
	 * into use with the type; they are never orphans, and RO-24 never
	 * offers to delete them. Reverting (RS-32) and then the cleanup step is
	 * the way to remove them. Not filterable.
	 *
	 * @return array Group key => array( 'label', 'caps' ).
	 */
	private static function isolated_groups() {
		if ( ! class_exists( 'BLT_Toolkit_Isolation' ) ) {
			return array();
		}

		$failed = BLT_Toolkit_Isolation::failed();
		$out    = array();

		foreach ( BLT_Toolkit_Isolation::isolated() as $type => $info ) {
			$type = (string) $type;

			if ( post_type_exists( $type ) && ! isset( $failed[ $type ] ) ) {
				continue;
			}

			$object = get_post_type_object( $type );
			$label  = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;

			$out[ 'blt-isolated-' . $type ] = array(
				/* translators: %s: content type name or key, such as Events. */
				'label' => sprintf( __( '%s: separate permissions not in effect', 'blt-toolkit' ), $label ),
				'caps'  => BLT_Toolkit_Isolation::new_strings( BLT_Toolkit_Isolation::new_caps( $type, $info['singular'], $info['plural'] ), $info['plural'] ),
			);
		}

		return $out;
	}

	/**
	 * WooCommerce's core capabilities, as WC_Install::get_core_capabilities()
	 * lists them (WooCommerce 3 to 11): the site-wide ones, then for
	 * products, orders and coupons the post type's capabilities (the
	 * per-item names too, which WooCommerce grants as primitives) and the
	 * term capabilities. The fallback when that method cannot be called, and
	 * the floor when it can (RO-11, URE-7). Pure.
	 *
	 * @return string[]
	 */
	public static function woocommerce_caps() {
		$out = array( 'manage_woocommerce', 'create_customers', 'view_woocommerce_reports' );

		foreach ( array( 'product', 'shop_order', 'shop_coupon' ) as $type ) {
			foreach ( array( 'edit_%s', 'read_%s', 'delete_%s', 'edit_%ss', 'edit_others_%ss', 'publish_%ss', 'read_private_%ss', 'delete_%ss', 'delete_private_%ss', 'delete_published_%ss', 'delete_others_%ss', 'edit_private_%ss', 'edit_published_%ss', 'manage_%s_terms', 'edit_%s_terms', 'delete_%s_terms', 'assign_%s_terms' ) as $pattern ) {
				$out[] = sprintf( $pattern, $type );
			}
		}

		return $out;
	}

	/**
	 * Capability names from a nested list (WooCommerce's group => names).
	 * Pure.
	 *
	 * @param mixed $value List, or lists of lists.
	 * @return string[]
	 */
	public static function flatten_caps( $value ) {
		$out = array();

		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_array( $item ) ) {
				$out = array_merge( $out, self::flatten_caps( $item ) );
			} elseif ( is_string( $item ) && '' !== $item ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * The distinct per-user capability sets, from one indexed query on this
	 * site's capabilities meta key (RO-10), cached by the full build for the
	 * request.
	 *
	 * Rows holding nothing but one role (`a:1:{s:6:"editor";b:1;}`, the shape
	 * WordPress stores for most users) or nothing at all are left out in
	 * SQL, and the rest are grouped by value, so a site where every user has
	 * the same two roles (US-2) returns one row, not one per user (SEC-9).
	 * Keys are positions, not user IDs: holders() asks for a capability's
	 * users itself.
	 *
	 * @return array<int,array> Position => capabilities meta.
	 */
	private static function user_caps() {
		global $wpdb;

		$skip = array( 'a:0:{}', '' );

		foreach ( array_keys( (array) wp_roles()->roles ) as $role ) {
			$skip[] = maybe_serialize( array( (string) $role => true ) );
		}

		$in  = implode( ', ', array_fill( 0, count( $skip ), '%s' ) );
		$key = $wpdb->get_blog_prefix() . 'capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One indexed query on meta_key, only while a roles screen is built (RO-10); the IN list is placeholders only, every value is bound.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value NOT IN ( {$in} ) GROUP BY meta_value", array_merge( array( $key ), $skip ) ) );

		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $value ) {
			$caps = maybe_unserialize( $value );

			if ( is_array( $caps ) && array() !== $caps ) {
				$out[] = $caps;
			}
		}

		return $out;
	}

	/**
	 * The users whose capabilities meta holds a capability, with any value
	 * (RO-24): one query for that key, lowest ID first. A role name is a
	 * role membership there, never a capability, so it has no holders here.
	 *
	 * @param string $cap Capability.
	 * @return int[]
	 */
	private static function user_holders( $cap ) {
		global $wpdb;

		$cap = (string) $cap;

		if ( '' === $cap || wp_roles()->is_role( $cap ) ) {
			return array();
		}

		// The key as PHP serializes a string: `s:10:"edit_posts";`.
		$like = '%' . $wpdb->esc_like( 's:' . strlen( $cap ) . ':"' . $cap . '";' ) . '%';
		$key  = $wpdb->get_blog_prefix() . 'capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The delete dialog's one read of a capability's holders (RO-24).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY user_id ASC", $key, $like ), ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$caps = maybe_unserialize( $row['meta_value'] );

			if ( is_array( $caps ) && array_key_exists( $cap, $caps ) ) {
				$out[] = (int) $row['user_id'];
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Labels after `blt_toolkit_capability_labels` (RO-12).
	 *
	 * @param array $entries Entries (name => array with `label`).
	 * @return array<string,string>
	 */
	private static function filtered_labels( array $entries ) {
		$labels = array();

		foreach ( $entries as $cap => $entry ) {
			$labels[ (string) $cap ] = (string) $entry['label'];
		}

		/**
		 * Filter the plain-English capability labels (RO-12). The raw name is
		 * always shown next to the label.
		 *
		 * @param array<string,string> $labels Capability => label.
		 */
		$filtered = apply_filters( 'blt_toolkit_capability_labels', $labels );

		if ( ! is_array( $filtered ) ) {
			return $labels;
		}

		foreach ( $labels as $cap => $label ) {
			if ( isset( $filtered[ $cap ] ) && is_scalar( $filtered[ $cap ] ) && '' !== trim( (string) $filtered[ $cap ] ) ) {
				$labels[ $cap ] = trim( (string) $filtered[ $cap ] );
			}
		}

		return $labels;
	}

	/**
	 * The `simple` texts from the registered provider.
	 *
	 * @param string[] $caps Capability names.
	 * @return array<string,string>
	 */
	private static function simple_labels( array $caps ) {
		if ( null === self::$simple_provider ) {
			return array();
		}

		$out    = array();
		$result = call_user_func( self::$simple_provider, $caps );

		foreach ( is_array( $result ) ? $result : array() as $cap => $text ) {
			if ( is_scalar( $text ) && '' !== (string) $text ) {
				$out[ (string) $cap ] = (string) $text;
			}
		}

		return $out;
	}
}
