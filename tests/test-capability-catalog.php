<?php
/**
 * Capability catalog: the pure parts of BLT_Toolkit_Capability_Catalog
 * (RO-10 to RO-14, RO-23).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Capability_Catalog.
 */
class Test_Capability_Catalog extends TestCase {

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A post type's `cap` object as get_post_type_capabilities() builds it.
	 *
	 * @param string $singular     Singular capability type.
	 * @param string $plural       Plural capability type.
	 * @param bool   $map_meta_cap Whether the type maps meta capabilities.
	 * @param array  $overrides    `capabilities` overrides.
	 * @return array
	 */
	private function caps( $singular, $plural, $map_meta_cap = true, array $overrides = array() ) {
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

		if ( $map_meta_cap ) {
			$caps += array(
				'read'                   => 'read',
				'delete_private_posts'   => 'delete_private_' . $plural,
				'delete_published_posts' => 'delete_published_' . $plural,
				'delete_others_posts'    => 'delete_others_' . $plural,
				'edit_private_posts'     => 'edit_private_' . $plural,
				'edit_published_posts'   => 'edit_published_' . $plural,
			);
		}

		$caps = array_merge( $caps, $overrides );

		if ( ! isset( $caps['create_posts'] ) ) {
			$caps['create_posts'] = $caps['edit_posts'];
		}

		return $caps;
	}

	/**
	 * One post type fixture.
	 *
	 * @param string       $label        Plural label.
	 * @param array        $cap          Cap object.
	 * @param string|array $type         capability_type.
	 * @param bool         $builtin      Built in.
	 * @param bool         $show_ui      Shown in the dashboard.
	 * @param bool         $map_meta_cap Maps meta capabilities.
	 * @return array
	 */
	private function type( $label, array $cap, $type, $builtin = false, $show_ui = true, $map_meta_cap = true ) {
		return array(
			'label'           => $label,
			'singular'        => $label,
			'cap'             => $cap,
			'map_meta_cap'    => $map_meta_cap,
			'capability_type' => $type,
			'builtin'         => $builtin,
			'show_ui'         => $show_ui,
		);
	}

	/**
	 * Post types like WordPress 7.1's, plus the SPEC 17 Phase 4 fixtures: a
	 * CPT with `capability_type => 'post'`, one with its own type, one with
	 * `map_meta_cap` false, an ACF-style one mapping `edit_posts` to
	 * `manage_options`, a hidden one with its own strings and an isolated
	 * one with a distinct `create_posts`.
	 *
	 * @return array
	 */
	private function post_types() {
		$posts = $this->caps( 'post', 'posts' );

		return array(
			'post'            => $this->type( 'Posts', $posts, 'post', true ),
			'page'            => $this->type( 'Pages', $this->caps( 'page', 'pages' ), 'page', true ),
			'attachment'      => $this->type( 'Media', $this->caps( 'post', 'posts', true, array( 'create_posts' => 'upload_files' ) ), 'post', true ),
			'revision'        => $this->type( 'Revisions', $posts, 'post', true, false ),
			'wp_block'        => $this->type(
				'Patterns',
				$this->caps(
					'block',
					'blocks',
					true,
					array(
						'read'                   => 'edit_posts',
						'create_posts'           => 'publish_posts',
						'edit_posts'             => 'edit_posts',
						'edit_published_posts'   => 'edit_published_posts',
						'delete_published_posts' => 'delete_published_posts',
						'edit_others_posts'      => 'edit_others_posts',
						'delete_others_posts'    => 'delete_others_posts',
						'delete_posts'           => 'delete_posts',
					)
				),
				'block',
				true
			),
			'event'           => $this->type( 'Events', $posts, 'post' ),
			'book'            => $this->type( 'Books', $this->caps( 'book', 'books' ), 'book' ),
			'ledger'          => $this->type( 'Ledgers', $this->caps( 'ledger', 'ledgers', false ), 'ledger', false, true, false ),
			// As WordPress 7.1 registers it: `capabilities` given, so
			// map_meta_cap stays false and core checks `read_post` itself.
			'acf-field-group' => $this->type(
				'Field Groups',
				$this->caps(
					'post',
					'posts',
					false,
					array(
						'edit_post'    => 'manage_options',
						'delete_post'  => 'manage_options',
						'edit_posts'   => 'manage_options',
						'delete_posts' => 'manage_options',
						'create_posts' => 'manage_options',
					)
				),
				'post',
				false,
				true,
				false
			),
			'secret'          => $this->type( 'Secrets', $this->caps( 'secret', 'secrets' ), 'secret', false, false ),
			'iso'             => $this->type( 'Isos', $this->caps( 'iso', 'isos', true, array( 'create_posts' => 'create_isos' ) ), array( 'iso', 'isos' ) ),
		);
	}

	/**
	 * Taxonomies: core's category (meta names) and two sharing their own
	 * strings.
	 *
	 * @return array
	 */
	private function taxonomies() {
		$event_terms = array(
			'manage_terms' => 'manage_event_terms',
			'edit_terms'   => 'edit_event_terms',
			'delete_terms' => 'delete_event_terms',
			'assign_terms' => 'assign_event_terms',
		);

		return array(
			'category'  => array(
				'label'        => 'Categories',
				'cap'          => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'edit_categories',
					'delete_terms' => 'delete_categories',
					'assign_terms' => 'assign_categories',
				),
				'builtin'      => true,
				'show_ui'      => true,
				'object_types' => array( 'post' ),
			),
			'event_cat' => array(
				'label'        => 'Event Categories',
				'cap'          => $event_terms,
				'builtin'      => false,
				'show_ui'      => true,
				'object_types' => array( 'event' ),
			),
			'event_tag' => array(
				'label'        => 'Event Tags',
				'cap'          => $event_terms,
				'builtin'      => false,
				'show_ui'      => true,
				'object_types' => array( 'event' ),
			),
		);
	}

	/**
	 * The whole build input.
	 *
	 * @param array $roles    Role ID => capabilities.
	 * @param array $users    User ID => capabilities meta.
	 * @param array $declared Declared groups.
	 * @return array
	 */
	private function input( array $roles = array(), array $users = array(), array $declared = array() ) {
		$blt = array();

		foreach ( array_keys( BLT_Toolkit_Caps::own() ) as $cap ) {
			$blt[ $cap ] = $cap;
		}

		return array(
			'core'       => BLT_Toolkit_Capability_Catalog::core_caps(),
			'core_meta'  => BLT_Toolkit_Capability_Catalog::core_meta_names(),
			'post_types' => $this->post_types(),
			'taxonomies' => $this->taxonomies(),
			'declared'   => BLT_Toolkit_Capability_Catalog::clean_declared( $declared ),
			'blt'        => $blt,
			'blt_meta'   => array_keys( BLT_Toolkit_Caps::meta() ),
			'roles'      => $roles,
			'users'      => $users,
		);
	}

	/**
	 * The content group a type belongs to.
	 *
	 * @param array  $groups Groups.
	 * @param string $type   Type name.
	 * @return array|null
	 */
	private function group_of( array $groups, $type ) {
		foreach ( $groups as $group ) {
			if ( in_array( $type, $group['types'], true ) ) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * RO-14 (URE-4): a CPT registered with `capability_type => 'post'` is a
	 * member of the Posts group, labeled with every member, never a group of
	 * its own.
	 *
	 * @return void
	 */
	public function test_ro14_capability_type_post_joins_posts_group() {
		$groups = BLT_Toolkit_Capability_Catalog::group_content_types( $this->post_types(), array(), BLT_Toolkit_Capability_Catalog::core_caps() );
		$posts  = $this->group_of( $groups, 'post' );

		$this->assertNotNull( $posts );
		$this->assertSame( 'content-post', $posts['id'] );
		$this->assertSame( array( 'post', 'event' ), $posts['types'] );
		$this->assertSame( 'Posts (also used by: Events)', $posts['label'] );
		$this->assertSame( $posts, $this->group_of( $groups, 'event' ) );
		$this->assertFalse( $posts['shares_posts'] );
		$this->assertFalse( $posts['excluded_from_levels'] );

		foreach ( $groups as $group ) {
			$this->assertNotSame( 'event', $group['primary'], 'Events must not be a node of its own.' );
		}
	}

	/**
	 * RO-14, RS-10: attachments are left to Media, and internal or hidden
	 * types never join a visible group; one with strings of its own forms an
	 * internal group.
	 *
	 * @return void
	 */
	public function test_ro14_internal_and_hidden_types_never_join_visible_groups() {
		$groups = BLT_Toolkit_Capability_Catalog::group_content_types( $this->post_types(), array(), BLT_Toolkit_Capability_Catalog::core_caps() );

		$this->assertNull( $this->group_of( $groups, 'attachment' ) );
		$this->assertNull( $this->group_of( $groups, 'revision' ), 'A hidden type with Posts\' strings adds nothing.' );

		$block = $this->group_of( $groups, 'wp_block' );

		$this->assertTrue( $block['internal'] );
		$this->assertSame( array( 'wp_block' ), $block['types'] );
		$this->assertSame( 'publish_blocks', $block['caps']['publish_posts'] );

		$secret = $this->group_of( $groups, 'secret' );

		$this->assertTrue( $secret['internal'] );
		$this->assertSame( 'content-secret', $secret['id'] );

		$visible = array();

		foreach ( $groups as $group ) {
			if ( empty( $group['internal'] ) ) {
				$visible[] = $group['primary'];
			}
		}

		$this->assertSame( array( 'post', 'page', 'book', 'ledger', 'acf-field-group', 'iso' ), $visible, 'Posts and Pages first, then registration order.' );
	}

	/**
	 * RO-14: a type with its own capability_type is its own group, shares
	 * nothing with Posts and is not excluded from levels; `create_posts`
	 * equal to `edit_posts` is not distinct.
	 *
	 * @return void
	 */
	public function test_ro14_own_capability_type_is_its_own_group() {
		$groups = BLT_Toolkit_Capability_Catalog::group_content_types( $this->post_types(), array(), BLT_Toolkit_Capability_Catalog::core_caps() );
		$books  = $this->group_of( $groups, 'book' );

		$this->assertSame( array( 'book' ), $books['types'] );
		$this->assertSame( 'Books', $books['label'] );
		$this->assertSame( array( 'book', 'books' ), $books['capability_type'] );
		$this->assertFalse( $books['shares_posts'] );
		$this->assertFalse( $books['shares_pages'] );
		$this->assertSame( array(), $books['foreign'] );
		$this->assertFalse( $books['excluded_from_levels'] );
		$this->assertFalse( $books['create_distinct'] );
		$this->assertTrue( $books['map_meta_cap'] );
		$this->assertArrayNotHasKey( 'edit_post', $books['caps'], 'Meta capabilities are not primitives here.' );
	}

	/**
	 * RS-11 with RO-14: an ACF-style type mapping `edit_posts` to
	 * `manage_options` shares Posts' other strings and is excluded from
	 * levels because of the administrative one.
	 *
	 * @return void
	 */
	public function test_rs11_acf_style_type_shares_posts_and_is_excluded() {
		$groups = BLT_Toolkit_Capability_Catalog::group_content_types( $this->post_types(), array(), BLT_Toolkit_Capability_Catalog::core_caps() );
		$acf    = $this->group_of( $groups, 'acf-field-group' );

		$this->assertSame( array( 'acf-field-group' ), $acf['types'] );
		$this->assertTrue( $acf['shares_posts'] );
		$this->assertSame( 'post', $acf['shared_with']['edit_others_posts'] );
		$this->assertSame(
			array(
				'edit_posts'   => 'manage_options',
				'delete_posts' => 'manage_options',
				'create_posts' => 'manage_options',
				'edit_post'    => 'manage_options',
				'delete_post'  => 'manage_options',
			),
			$acf['foreign']
		);
		$this->assertTrue( $acf['excluded_from_levels'] );
		$this->assertFalse( $acf['map_meta_cap'] );
		$this->assertSame( 'read_post', $acf['caps']['read_post'], 'Core checks this name itself for a type with map_meta_cap off.' );

		$built = BLT_Toolkit_Capability_Catalog::build( $this->input( array( 'designer' => array( 'read_post' => true ) ) ) );

		$this->assertFalse( $built['entries']['read_post']['orphan'] );
		$this->assertSame( 'content-acf-field-group', $built['entries']['read_post']['group'] );
	}

	/**
	 * RO-10, RS-12: with `map_meta_cap` false the per-item names are
	 * primitives core checks, so they are in the group and in the catalog.
	 *
	 * @return void
	 */
	public function test_rs12_map_meta_cap_false_lists_per_item_capabilities() {
		$built  = BLT_Toolkit_Capability_Catalog::build( $this->input() );
		$ledger = $this->group_of( $built['content_groups'], 'ledger' );

		$this->assertFalse( $ledger['map_meta_cap'] );
		$this->assertSame( 'edit_ledger', $ledger['caps']['edit_post'] );
		$this->assertSame( 'content', $built['entries']['edit_ledger']['source'] );
		$this->assertSame( 'Edit any one of the Ledgers', $built['entries']['edit_ledger']['label'] );
		$this->assertSame( 'content-ledger', $built['entries']['edit_ledger']['group'] );
		$this->assertArrayNotHasKey( 'edit_book', $built['entries'], 'A mapped type\'s per-item name is a meta capability.' );
	}

	/**
	 * RS-13: a type with a distinct `create_posts` (as isolation gives) is
	 * marked, and its read-only marker is a registered BLT capability; a
	 * marker for Posts, whose create is not distinct, is not.
	 *
	 * @return void
	 */
	public function test_rs13_marker_registered_only_for_distinct_create() {
		$built = BLT_Toolkit_Capability_Catalog::build( $this->input( array( 'viewer' => array( 'blt_readonly_posts' => true ) ) ) );
		$iso   = $this->group_of( $built['content_groups'], 'iso' );

		$this->assertTrue( $iso['create_distinct'] );
		$this->assertSame( 'blt', $built['entries']['blt_readonly_isos']['source'] );
		$this->assertFalse( $built['entries']['blt_readonly_isos']['orphan'] );
		$this->assertTrue( $built['entries']['blt_readonly_posts']['orphan'] );
		$this->assertSame( 'Create Isos', $built['entries']['create_isos']['label'] );
	}

	/**
	 * RO-11: present on a role or user but registered by nothing is an
	 * orphan; core, content, taxonomy, BLT and declared capabilities never
	 * are; a role name in a user's meta is a membership, not a capability.
	 *
	 * @return void
	 */
	public function test_ro11_orphan_rules() {
		$roles = array(
			'administrator' => array(
				'manage_options'   => true,
				'blt_switch_users' => true,
				'edit_books'       => true,
			),
			'shop'          => array(
				'old_plugin_cap'     => true,
				'manage_event_terms' => true,
				'acme_manage'        => true,
				'edit_post'          => true,
			),
		);
		$users = array(
			5 => array(
				'shop'            => true,
				'custom_user_cap' => true,
			),
			6 => array( 'edit_ledgers' => false ),
		);
		$built = BLT_Toolkit_Capability_Catalog::build(
			$this->input(
				$roles,
				$users,
				array(
					'acme' => array(
						'label' => 'Acme',
						'caps'  => array( 'acme_manage' ),
					),
				)
			)
		);
		$e     = $built['entries'];

		$this->assertTrue( $e['old_plugin_cap']['orphan'] );
		$this->assertSame( 'orphan', $e['old_plugin_cap']['source'] );
		$this->assertSame( 'orphaned', $e['old_plugin_cap']['group'] );
		$this->assertTrue( $e['custom_user_cap']['orphan'], 'A capability only a user holds counts.' );
		$this->assertTrue( $e['edit_post']['orphan'], 'A stored meta capability does nothing.' );
		$this->assertNotSame( '', $e['edit_post']['note'] );
		$this->assertArrayNotHasKey( 'shop', $e, 'A role key in user meta is not a capability.' );

		foreach ( array( 'manage_options', 'blt_switch_users', 'edit_books', 'manage_event_terms', 'acme_manage', 'edit_ledgers' ) as $cap ) {
			$this->assertFalse( $e[ $cap ]['orphan'], $cap );
		}

		$this->assertSame( 'core', $e['manage_options']['source'] );
		$this->assertSame( 'blt', $e['blt_switch_users']['source'] );
		$this->assertSame( 'content', $e['edit_books']['source'] );
		$this->assertSame( 'taxonomy', $e['manage_event_terms']['source'] );
		$this->assertSame( 'plugin', $e['acme_manage']['source'] );
		$this->assertSame( 'plugin-acme', $e['acme_manage']['group'] );
	}

	/**
	 * RO-13: three or more capabilities sharing a prefix make a detected
	 * plugin group (orphans still, since nothing declares them); two do not.
	 *
	 * @return void
	 */
	public function test_ro13_prefix_grouping_needs_three() {
		$this->assertSame( 'gravityforms', BLT_Toolkit_Capability_Catalog::prefix_family( 'gravityforms_edit_forms' ) );
		$this->assertSame( 'woocommerce', BLT_Toolkit_Capability_Catalog::prefix_family( 'manage_woocommerce' ) );
		$this->assertSame( 'woocommerce', BLT_Toolkit_Capability_Catalog::prefix_family( 'view_woocommerce_reports' ) );
		$this->assertSame( 'nextgen', BLT_Toolkit_Capability_Catalog::prefix_family( 'NextGEN Manage gallery' ) );
		$this->assertSame( '', BLT_Toolkit_Capability_Catalog::prefix_family( 'manage_edit' ) );

		$this->assertSame(
			array( 'gravityforms' => array( 'gravityforms_delete_forms', 'gravityforms_edit_forms', 'gravityforms_view_entries' ) ),
			BLT_Toolkit_Capability_Catalog::prefix_groups( array( 'gravityforms_edit_forms', 'gravityforms_delete_forms', 'gravityforms_view_entries', 'acme_one', 'acme_two' ), 3 )
		);

		$built = BLT_Toolkit_Capability_Catalog::build(
			$this->input(
				array(
					'shop' => array(
						'gravityforms_edit_forms'   => true,
						'gravityforms_delete_forms' => true,
						'gravityforms_view_entries' => true,
						'acme_one'                  => true,
						'acme_two'                  => true,
					),
				)
			)
		);

		$this->assertSame( 'plugin', $built['entries']['gravityforms_edit_forms']['source'] );
		$this->assertSame( 'plugin-gravityforms', $built['entries']['gravityforms_edit_forms']['group'] );
		$this->assertSame( 'Gravityforms', $built['entries']['gravityforms_edit_forms']['source_label'] );
		$this->assertTrue( $built['entries']['gravityforms_edit_forms']['orphan'] );
		$this->assertSame( 'orphan', $built['entries']['acme_one']['source'] );

		$plugins = $this->node( $built['tree'], 'plugins' );

		$this->assertSame( array( 'plugin-gravityforms' ), array_column( $plugins['children'], 'id' ) );
	}

	/**
	 * RO-12: core dictionary, labels from the objects ("Edit others'
	 * Events"), the first key naming a shared string, humanized fallback,
	 * and the filter.
	 *
	 * @return void
	 */
	public function test_ro12_labels() {
		$this->assertSame( 'Edit others\' Events', BLT_Toolkit_Capability_Catalog::content_label( 'edit_others_posts', 'Events' ) );
		$this->assertSame( 'Manage Event Categories', BLT_Toolkit_Capability_Catalog::taxonomy_label( 'manage_terms', 'Event Categories' ) );
		$this->assertSame( 'Gravityforms edit forms', BLT_Toolkit_Capability_Catalog::humanize( 'gravityforms_edit_forms' ) );
		$this->assertSame( 'Acme thing', BLT_Toolkit_Capability_Catalog::humanize( 'acme-thing' ) );

		$built = BLT_Toolkit_Capability_Catalog::build( $this->input() );

		$this->assertSame( 'Change site settings', $built['entries']['manage_options']['label'] );
		$this->assertSame( 'Edit others\' Books', $built['entries']['edit_others_books']['label'] );
		$this->assertSame( 'Create and edit their own Books', $built['entries']['edit_books']['label'], '`create_posts` shares the string; `edit_posts` names it.' );
		$this->assertSame( 'Books', $built['entries']['edit_books']['source_label'] );
		$this->assertSame( 'Event Categories, Event Tags', $built['entries']['manage_event_terms']['source_label'] );
		$this->assertSame( 'Manage Event Categories', $built['entries']['manage_event_terms']['label'] );
	}

	/**
	 * RO-23: meta capability names are recognized (core's, BLT Toolkit's and
	 * mapped post types' per-item names), while primitives, including those
	 * a type with `map_meta_cap` false checks and one ACF maps `edit_post`
	 * to, are not.
	 *
	 * @return void
	 */
	public function test_ro23_meta_cap_names() {
		$built = BLT_Toolkit_Capability_Catalog::build( $this->input() );
		$meta  = $built['meta_names'];

		foreach ( array( 'edit_post', 'read_post', 'delete_post', 'edit_user', 'promote_user', 'edit_post_meta', 'edit_term', 'assign_term', 'edit_comment', 'blt_switch_to_user', 'blt_change_username', 'edit_book', 'read_secret', 'edit_categories' ) as $cap ) {
			$this->assertArrayHasKey( $cap, $meta, $cap );
		}

		foreach ( array( 'edit_posts', 'edit_users', 'manage_options', 'edit_ledger', 'install_languages', 'unfiltered_html' ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $meta, $cap );
		}

		$this->assertSame( array(), array_values( array_intersect( array_keys( BLT_Toolkit_Capability_Catalog::core_caps() ), BLT_Toolkit_Capability_Catalog::core_meta_names() ) ), 'The core list holds primitives only.' );
	}

	/**
	 * RO-23 and RO-30: name rules (lowercase letters, digits, underscores
	 * and hyphens; a letter; a length limit).
	 *
	 * @return void
	 */
	public function test_ro23_name_rules() {
		$this->assertSame( '', BLT_Toolkit_Capability_Catalog::name_error( 'manage_widgets-2' ) );
		$this->assertSame( 'empty', BLT_Toolkit_Capability_Catalog::name_error( '' ) );
		$this->assertSame( 'chars', BLT_Toolkit_Capability_Catalog::name_error( 'Manage' ) );
		$this->assertSame( 'chars', BLT_Toolkit_Capability_Catalog::name_error( 'manage widgets' ) );
		$this->assertSame( 'no_letter', BLT_Toolkit_Capability_Catalog::name_error( '123_4' ) );
		$this->assertSame( '', BLT_Toolkit_Capability_Catalog::name_error( str_repeat( 'a', 191 ) ) );
		$this->assertSame( 'too_long', BLT_Toolkit_Capability_Catalog::name_error( str_repeat( 'a', 192 ) ) );
		$this->assertSame( 'too_long', BLT_Toolkit_Capability_Catalog::name_error( str_repeat( 'a', 65 ), 64 ) );
	}

	/**
	 * RO-13: the tree's top level, Core's sections, the Posts group node
	 * naming its members, and parents holding their children's capabilities.
	 *
	 * @return void
	 */
	public function test_ro13_tree() {
		$built = BLT_Toolkit_Capability_Catalog::build( $this->input( array( 'shop' => array( 'old_plugin_cap' => true ) ) ) );
		$tree  = $built['tree'];

		$this->assertSame( array( 'all', 'core', 'content', 'taxonomies', 'plugins', 'blt', 'orphaned' ), array_column( $tree, 'id' ) );

		// RO-13 names the top-level node "Plugins (detected)", so it never
		// reads the same as Core > Plugins.
		$this->assertSame( 'Plugins (detected)', $this->node( $tree, 'plugins' )['label'] );
		$this->assertSame( 'Plugins', $this->node( $tree, 'core-plugins' )['label'] );

		$core = $this->node( $tree, 'core' );

		// Single site, no role holds a network capability: no Network node.
		$this->assertSame( array( 'core-general', 'core-appearance', 'core-posts', 'core-pages', 'core-media', 'core-plugins', 'core-users', 'core-deprecated' ), array_column( $core['children'], 'id' ) );
		$this->assertContains( 'level_0', $this->node( $tree, 'core-deprecated' )['caps'] );
		$this->assertContains( 'edit_files', $this->node( $tree, 'core-deprecated' )['caps'] );
		$this->assertContains( 'manage_options', $core['caps'] );

		$posts = $this->node( $tree, 'content-post' );

		$this->assertSame( 'Posts (also used by: Events)', $posts['label'] );
		$this->assertSame( array( 'post', 'event' ), $posts['types'] );
		$this->assertContains( 'edit_posts', $posts['caps'] );
		$this->assertNotContains( 'read', $posts['caps'] );
		$this->assertNull( $this->node( $tree, 'content-event' ) );

		$this->assertSame( array( 'taxonomy-event_cat' ), array_column( $this->node( $tree, 'taxonomies' )['children'], 'id' ) );
		$this->assertSame( array( 'old_plugin_cap' ), $this->node( $tree, 'orphaned' )['caps'] );
		$this->assertContains( 'blt_edit_roles', $this->node( $tree, 'blt' )['caps'] );
		$this->assertSame( count( $built['entries'] ), $this->node( $tree, 'all' )['count'] );
	}

	/**
	 * RO-24: holders are every role and user with the key, any value.
	 *
	 * @return void
	 */
	public function test_ro24_holders() {
		$built = BLT_Toolkit_Capability_Catalog::build(
			$this->input(
				array(
					'shop'   => array( 'old_plugin_cap' => true ),
					'banned' => array( 'old_plugin_cap' => false ),
				),
				array(
					9 => array( 'old_plugin_cap' => true ),
					3 => array(
						'old_plugin_cap' => false,
						'shop'           => true,
					),
				)
			)
		);

		$this->assertSame( array( 'banned', 'shop' ), $built['holders']['roles']['old_plugin_cap'] );
		$this->assertSame( array( 3, 9 ), $built['holders']['users']['old_plugin_cap'] );
		$this->assertArrayNotHasKey( 'shop', $built['holders']['users'] );
	}

	/**
	 * RO-10: every core capability has its version and section; levels and
	 * `edit_files` are deprecated; the network capabilities are flagged.
	 *
	 * @return void
	 */
	public function test_ro10_core_list() {
		$core = BLT_Toolkit_Capability_Catalog::core_caps();

		foreach ( $core as $cap => $meta ) {
			$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $meta['since'], $cap );
			$this->assertContains( $meta['group'], BLT_Toolkit_Capability_Catalog::CORE_NODES, $cap );
		}

		for ( $level = 0; $level <= 10; $level++ ) {
			$this->assertTrue( $core[ 'level_' . $level ]['deprecated'] );
		}

		$this->assertTrue( $core['edit_files']['deprecated'] );
		$this->assertTrue( $core['manage_network']['multisite'] );
		$this->assertFalse( $core['manage_options']['multisite'] );
		$this->assertArrayHasKey( 'view_site_health_checks', $core );
		$this->assertArrayNotHasKey( 'customize', $core );
	}

	/**
	 * RO-10, RO-13: on a single site the multisite capabilities are listed
	 * only when a role or user holds one (they do nothing there); on a
	 * network, all of them.
	 *
	 * @return void
	 */
	public function test_ro13_network_capabilities_only_when_held_on_a_single_site() {
		$built = BLT_Toolkit_Capability_Catalog::build( $this->input() );

		$this->assertArrayNotHasKey( 'manage_network', $built['entries'] );
		$this->assertNull( $this->node( $built['tree'], 'core-network' ) );

		$built = BLT_Toolkit_Capability_Catalog::build( $this->input( array( 'netop' => array( 'manage_sites' => true ) ), array( 4 => array( 'upgrade_network' => false ) ) ) );

		$this->assertSame( array( 'manage_sites', 'upgrade_network' ), $this->node( $built['tree'], 'core-network' )['caps'] );
		$this->assertFalse( $built['entries']['manage_sites']['orphan'] );
		$this->assertSame( BLT_Toolkit_Capability_Catalog::SOURCE_CORE, $built['entries']['upgrade_network']['source'] );

		$input              = $this->input();
		$input['multisite'] = true;

		$this->assertSame( 9, $this->node( BLT_Toolkit_Capability_Catalog::build( $input )['tree'], 'core-network' )['count'] );
	}

	/**
	 * RO-14, SPEC 19 URE-4, SPEC 17 Phase 4 criterion e: a type that uses
	 * only some of another visible type's strings is its own group, and each
	 * shared string names the types it also belongs to. Hidden and internal
	 * types never count as users, so Posts is not "shared with" Patterns.
	 *
	 * @return void
	 */
	public function test_ro14_partial_overlap_names_the_other_types() {
		$groups   = BLT_Toolkit_Capability_Catalog::build( $this->input() )['content_groups'];
		$patterns = $this->group_of( $groups, 'wp_block' );
		$acf      = $this->group_of( $groups, 'acf-field-group' );
		$posts    = $this->group_of( $groups, 'post' );
		$books    = $this->group_of( $groups, 'book' );

		$this->assertTrue( $patterns['internal'] );
		$this->assertSame( array( 'Posts', 'Events' ), $patterns['also_used_by']['edit_posts'] );
		$this->assertSame( array( 'Posts', 'Events', 'Field Groups' ), $patterns['also_used_by']['publish_posts'] );
		$this->assertArrayNotHasKey( 'edit_private_blocks', $patterns['also_used_by'] );

		$this->assertSame( array( 'Posts', 'Events' ), $acf['also_used_by']['edit_others_posts'] );
		$this->assertArrayNotHasKey( 'manage_options', $acf['also_used_by'] );

		$this->assertSame( array( 'Field Groups' ), $posts['also_used_by']['edit_others_posts'] );
		$this->assertArrayNotHasKey( 'edit_posts', $posts['also_used_by'], 'Patterns are internal; Field Groups map edit_posts to manage_options.' );
		$this->assertArrayNotHasKey( 'read', $posts['also_used_by'] );

		$this->assertSame( array(), $books['also_used_by'] );
	}

	/**
	 * RO-11, RO-24, SPEC 19 URE-7, Appendix B: WooCommerce's whole core set
	 * is declared while it runs, so none of its live capabilities is an
	 * orphan or offered for deletion; a nested list is flattened.
	 *
	 * @return void
	 */
	public function test_ro11_woocommerce_core_capabilities_are_declared() {
		$caps = BLT_Toolkit_Capability_Catalog::woocommerce_caps();

		$this->assertCount( 54, array_unique( $caps ) );

		foreach ( array( 'manage_woocommerce', 'create_customers', 'view_woocommerce_reports', 'edit_shop_coupon', 'read_shop_coupon', 'delete_shop_coupon', 'manage_shop_order_terms', 'assign_shop_coupon_terms', 'edit_published_products' ) as $cap ) {
			$this->assertContains( $cap, $caps );
		}

		$built = BLT_Toolkit_Capability_Catalog::build(
			$this->input(
				array( 'shop_manager' => array_fill_keys( $caps, true ) ),
				array(),
				array(
					'woocommerce' => array(
						'label' => 'WooCommerce',
						'caps'  => $caps,
					),
				)
			)
		);

		foreach ( $caps as $cap ) {
			$this->assertFalse( $built['entries'][ $cap ]['orphan'], $cap );
		}

		$this->assertSame(
			array( 'a', 'b', 'c' ),
			BLT_Toolkit_Capability_Catalog::flatten_caps(
				array(
					'core'    => array( 'a' ),
					'product' => array( 'b', array( 'c', 7, '' ) ),
				)
			)
		);
		$this->assertSame( array(), BLT_Toolkit_Capability_Catalog::flatten_caps( 'junk' ) );
	}

	/**
	 * RO-13: `blt_toolkit_capability_groups` values are cleaned.
	 *
	 * @return void
	 */
	public function test_ro13_clean_declared() {
		$this->assertSame( array(), BLT_Toolkit_Capability_Catalog::clean_declared( 'junk' ) );
		$this->assertSame(
			array(
				'acme' => array(
					'label' => 'Acme',
					'caps'  => array( 'acme_a', 'acme_b' ),
				),
				'beta' => array(
					'label' => 'Beta',
					'caps'  => array( 'beta_x' ),
				),
			),
			BLT_Toolkit_Capability_Catalog::clean_declared(
				array(
					'Acme'  => array(
						'label' => ' Acme ',
						'caps'  => array( 'acme_a', 'acme_b', 'acme_a', 5, '' ),
					),
					'beta'  => array( 'caps' => array( 'beta_x' ) ),
					'empty' => array(
						'label' => 'Empty',
						'caps'  => array(),
					),
					''      => array( 'caps' => array( 'x' ) ),
				)
			)
		);
	}

	/**
	 * A node by ID, depth first.
	 *
	 * @param array  $nodes Nodes.
	 * @param string $id    ID.
	 * @return array|null
	 */
	private function node( array $nodes, $id ) {
		foreach ( $nodes as $node ) {
			if ( $node['id'] === $id ) {
				return $node;
			}

			$found = $this->node( $node['children'], $id );

			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}
}
