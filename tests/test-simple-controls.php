<?php
/**
 * Simple view controls and their save (RS-4, RS-5, RS-6, RS-13, RS-16,
 * SPEC 10.7) with known vectors: BLT_Toolkit_Simple_Controls' pure rules
 * (type_map(), plan()), the Simple helpers of BLT_Toolkit_Admin_Roles and
 * the role store's per-context permission.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Simple view registry and save planning (SPEC 17 Phase 5).
 */
class Test_Simple_Controls extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	// ---------- Fixtures ----------

	/**
	 * Posts' content group as BLT_Toolkit_Capability_Catalog::content_groups()
	 * gives it, with Events sharing it (RO-14).
	 *
	 * @return array
	 */
	private function posts_group() {
		return array(
			'id'            => 'content-post',
			'primary'       => 'post',
			'primary_label' => 'Posts',
			'label'         => 'Posts (also used by: Events)',
			'types'         => array( 'post', 'event' ),
			'type_labels'   => array(
				'post'  => 'Posts',
				'event' => 'Events',
			),
			'caps'          => array(
				'edit_posts'             => 'edit_posts',
				'edit_others_posts'      => 'edit_others_posts',
				'edit_published_posts'   => 'edit_published_posts',
				'edit_private_posts'     => 'edit_private_posts',
				'publish_posts'          => 'publish_posts',
				'read_private_posts'     => 'read_private_posts',
				'delete_posts'           => 'delete_posts',
				'delete_others_posts'    => 'delete_others_posts',
				'delete_published_posts' => 'delete_published_posts',
				'delete_private_posts'   => 'delete_private_posts',
				'create_posts'           => 'edit_posts',
				'read'                   => 'read',
			),
			'map_meta_cap'  => true,
		);
	}

	/**
	 * A type with capabilities of its own and a distinct create capability
	 * (RS-13), and a taxonomy group attached to it with its own strings.
	 *
	 * @return array{0:array,1:array[]}
	 */
	private function books_group() {
		$caps = array();

		foreach ( array( 'edit', 'edit_others', 'edit_published', 'edit_private', 'publish', 'read_private', 'delete', 'delete_others', 'delete_published', 'delete_private' ) as $verb ) {
			$caps[ $verb . '_posts' ] = $verb . '_books';
		}

		$caps['create_posts'] = 'create_books';
		$caps['read']         = 'read';

		$group = array(
			'id'            => 'content-book',
			'primary'       => 'book',
			'primary_label' => 'Books',
			'label'         => 'Books',
			'types'         => array( 'book' ),
			'type_labels'   => array( 'book' => 'Books' ),
			'caps'          => $caps,
			'map_meta_cap'  => true,
		);

		$taxonomies = array(
			array(
				'id'           => 'taxonomy-genre',
				'primary'      => 'genre',
				'label'        => 'Genres',
				'object_types' => array( 'book' ),
				'caps'         => array(
					'manage_terms' => 'manage_genres',
					'edit_terms'   => 'edit_genres',
					'delete_terms' => 'delete_genres',
					'assign_terms' => 'assign_genres',
				),
				'dedicated'    => true,
			),
			array(
				'id'           => 'taxonomy-shelf',
				'primary'      => 'shelf',
				'label'        => 'Shelves',
				'object_types' => array( 'movie' ),
				'caps'         => array(
					'manage_terms' => 'manage_shelves',
					'edit_terms'   => 'manage_shelves',
					'delete_terms' => 'manage_shelves',
					'assign_terms' => 'assign_shelves',
				),
				'dedicated'    => true,
			),
		);

		return array( $group, $taxonomies );
	}

	/**
	 * Controls with state for a role's capabilities, shaped as
	 * BLT_Toolkit_Simple_Controls::all() gives them: one content row per
	 * map, then toggles.
	 *
	 * @param array $caps    The role's capabilities.
	 * @param array $rows    Row ID => type map.
	 * @param array $toggles Toggle ID => capabilities.
	 * @return array
	 */
	private function controls( array $caps, array $rows, array $toggles ) {
		$out = array();

		foreach ( $rows as $id => $map ) {
			$universe   = BLT_Toolkit_Simple_Mapper::universe( $map );
			$out[ $id ] = array(
				'id'         => $id,
				'kind'       => BLT_Toolkit_Simple_Controls::LEVEL,
				'label'      => ucfirst( $id ),
				'map'        => $map,
				'readonly'   => '',
				'taxonomies' => array(),
				'universe'   => $universe,
				'granted'    => $this->granted( $universe, $caps ),
				'state'      => BLT_Toolkit_Simple_Mapper::detect( $map, $caps ),
			);
		}

		foreach ( $toggles as $id => $list ) {
			$out[ $id ] = array(
				'id'       => $id,
				'kind'     => BLT_Toolkit_Simple_Controls::TOGGLE,
				'label'    => ucfirst( $id ),
				'readonly' => '',
				'universe' => $list,
				'granted'  => $this->granted( $list, $caps ),
				'state'    => BLT_Toolkit_Simple_Mapper::toggle_state( $list, $caps ),
			);
		}

		return $out;
	}

	/**
	 * The capabilities of a list the role grants.
	 *
	 * @param string[] $list Capabilities.
	 * @param array    $caps The role's capabilities.
	 * @return string[]
	 */
	private function granted( array $list, array $caps ) {
		return array_values(
			array_filter(
				$list,
				static function ( $cap ) use ( $caps ) {
					return ! empty( $caps[ $cap ] );
				}
			)
		);
	}

	/**
	 * A role's capabilities after a plan, as the store would leave them
	 * (BLT_Toolkit_Role_Store::compute_changes() and apply_changes()).
	 *
	 * @param array $caps The role's capabilities.
	 * @param array $plan BLT_Toolkit_Simple_Controls::plan().
	 * @return array
	 */
	private function saved( array $caps, array $plan ) {
		$changes = BLT_Toolkit_Role_Store::compute_changes( $caps, $plan['on_page'], $plan['checked'] );
		$roles   = BLT_Toolkit_Role_Store::apply_changes( array( 'r' => array( 'capabilities' => $caps ) ), 'r', $changes );

		return $roles['r']['capabilities'];
	}

	// ---------- type_map() ----------

	/**
	 * RS-13: a type whose create capability is shared with edit has no
	 * marker, so View is site-only; the map keeps the eleven SPEC 10.4 keys.
	 *
	 * @return void
	 */
	public function test_rs13_type_map_posts_has_no_marker() {
		$map = BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() );

		$this->assertSame( '', $map['marker'] );
		$this->assertArrayNotHasKey( 'read', $map['caps'] );
		$this->assertSame( 'edit_posts', $map['caps']['create_posts'] );
		$this->assertSame( array( 'read_private_posts' ), BLT_Toolkit_Simple_Mapper::caps_for( $map, 'view' ) );
	}

	/**
	 * RS-13, SPEC 10.3: a distinct create capability gives the
	 * `blt_readonly_{plural}` marker; an attached dedicated taxonomy becomes
	 * a Manage toggle with its assign capability granted at Contribute; a
	 * taxonomy of another type is not attached.
	 *
	 * @return void
	 */
	public function test_rs13_type_map_books_has_marker_and_taxonomy() {
		list( $group, $taxonomies ) = $this->books_group();

		$map = BLT_Toolkit_Simple_Controls::type_map( $group, $taxonomies, array( 'edit_posts' => true ) );

		$this->assertSame( 'blt_readonly_books', $map['marker'] );
		$this->assertSame( array( 'genre' ), array_keys( $map['taxonomies'] ) );
		$this->assertTrue( $map['taxonomies']['genre']['assign_dedicated'] );
		$this->assertSame( array( 'blt_readonly_books', 'edit_books', 'read_private_books' ), BLT_Toolkit_Simple_Mapper::caps_for( $map, 'view' ) );
		$this->assertContains( 'assign_genres', BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute' ) );
		$this->assertNotContains( 'manage_genres', BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute' ) );
		$this->assertContains( 'manage_genres', BLT_Toolkit_Simple_Mapper::caps_for( $map, 'full' ) );
	}

	/**
	 * SPEC 10.3: an assign capability that is a core capability (or one of
	 * the row's own strings) is not the taxonomy's own to grant.
	 *
	 * @return void
	 */
	public function test_spec_10_3_core_assign_is_not_dedicated() {
		list( $group, $taxonomies ) = $this->books_group();

		$taxonomies[0]['caps']['assign_terms'] = 'edit_posts';

		$map = BLT_Toolkit_Simple_Controls::type_map( $group, $taxonomies, array( 'edit_posts' => true ) );

		$this->assertFalse( $map['taxonomies']['genre']['assign_dedicated'] );
		$this->assertNotContains( 'edit_posts', BLT_Toolkit_Simple_Mapper::universe( $map ) );
	}

	/**
	 * SPEC 10.3: an assign_terms that is another content type's capability
	 * (content_controls() passes every type's strings with core's) is not
	 * dedicated, so the Books row never grants Events' edit_events.
	 *
	 * @return void
	 */
	public function test_spec_10_3_other_types_assign_is_not_dedicated() {
		list( $group, $taxonomies ) = $this->books_group();

		$taxonomies[0]['caps']['assign_terms'] = 'edit_events';

		$map = BLT_Toolkit_Simple_Controls::type_map( $group, $taxonomies, array( 'edit_posts' => true, 'edit_events' => true ) );

		$this->assertFalse( $map['taxonomies']['genre']['assign_dedicated'] );
		$this->assertNotContains( 'edit_events', BLT_Toolkit_Simple_Mapper::caps_for( $map, 'full' ) );
		$this->assertNotContains( 'edit_events', BLT_Toolkit_Simple_Mapper::universe( $map ) );
	}

	// ---------- plan() ----------

	/**
	 * RS-16 through the registry: WordPress's Author reads Author with Can
	 * delete for Posts and No access for Pages, and submitting those states
	 * unchanged plans nothing.
	 *
	 * @return void
	 */
	public function test_rs16_author_reads_and_resubmits_unchanged() {
		$author   = array(
			'upload_files'           => true,
			'edit_posts'             => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'read'                   => true,
			'level_2'                => true,
			'level_1'                => true,
			'level_0'                => true,
			'delete_posts'           => true,
			'delete_published_posts' => true,
		);
		$controls = $this->controls(
			$author,
			array(
				'posts' => BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() ),
				'pages' => BLT_Toolkit_Simple_Controls::type_map( $this->pages_group(), array() ),
			),
			array( 'upload_files' => array( 'upload_files' ) )
		);

		$this->assertSame( 'author', $controls['posts']['state']['level'] );
		$this->assertTrue( $controls['posts']['state']['mods']['can_delete'] );
		$this->assertSame( 'none', $controls['pages']['state']['level'] );

		$plan = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$author,
			array(
				'posts'        => array(
					'level'      => 'author',
					'can_delete' => true,
				),
				'pages'        => array( 'level' => 'none' ),
				'upload_files' => 'on',
			)
		);

		$this->assertSame( array(), $plan['on_page'] );
		$this->assertSame( array(), $plan['controls'] );
	}

	/**
	 * RS-5, RO-1: one changed row plans only that row's capabilities; every
	 * other capability of the role is left alone, unrelated toggles too.
	 *
	 * @return void
	 */
	public function test_rs5_plan_touches_only_the_changed_row() {
		$caps     = array(
			'read'         => true,
			'edit_posts'   => true,
			'delete_posts' => true,
			'upload_files' => true,
			'acme_cap'     => true,
		);
		$controls = $this->controls(
			$caps,
			array(
				'posts' => BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() ),
				'pages' => BLT_Toolkit_Simple_Controls::type_map( $this->pages_group(), array() ),
			),
			array( 'upload_files' => array( 'upload_files' ) )
		);

		$plan = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts'        => array( 'level' => 'contribute' ),
				'pages'        => array( 'level' => 'contribute' ),
				'upload_files' => 'on',
			)
		);

		$this->assertSame( array( 'pages' ), array_keys( $plan['controls'] ) );
		$this->assertSame( BLT_Toolkit_Simple_Mapper::universe( $controls['pages']['map'] ), $plan['on_page'] );
		$this->assertSame( array( 'delete_pages', 'edit_pages' ), $plan['checked'] );

		$after = $this->saved( $caps, $plan );

		$this->assertSame( array_merge( $caps, array( 'delete_pages' => true, 'edit_pages' => true ) ), $after );
	}

	/**
	 * SPEC 10.3 without JavaScript: a new level whose modifiers were left as
	 * drawn takes its own defaults (Contribute: Can delete on); modifiers
	 * the person changed are kept; `mods_set` (the script) is taken as sent.
	 *
	 * @return void
	 */
	public function test_spec_10_3_level_defaults_without_script() {
		$caps     = array( 'read' => true );
		$map      = BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() );
		$controls = $this->controls( $caps, array( 'posts' => $map ), array() );

		$drawn = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts' => array(
					'level'      => 'contribute',
					'can_delete' => false,
				),
			)
		);

		$this->assertSame( array( 'delete_posts', 'edit_posts' ), $drawn['checked'] );

		$script = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts' => array(
					'level'      => 'contribute',
					'can_delete' => false,
					'mods_set'   => true,
				),
			)
		);

		$this->assertSame( array( 'edit_posts' ), $script['checked'] );
	}

	/**
	 * RS-4: Custom and `indeterminate` mean "leave it"; picking a level from
	 * Custom is flagged so the review lists what is removed.
	 *
	 * @return void
	 */
	public function test_rs4_custom_and_indeterminate_are_left_alone() {
		$caps     = array(
			'read'              => true,
			'edit_posts'        => true,
			'edit_others_posts' => true,
			'install_plugins'   => true,
		);
		$controls = $this->controls(
			$caps,
			array( 'posts' => BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() ) ),
			array( 'plugins' => array( 'install_plugins', 'update_plugins', 'delete_plugins' ) )
		);

		$this->assertSame( 'custom', $controls['posts']['state']['level'] );
		$this->assertSame( 'indeterminate', $controls['plugins']['state'] );

		$none = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts'   => array( 'level' => 'custom' ),
				'plugins' => 'indeterminate',
			)
		);

		$this->assertSame( array(), $none['on_page'] );

		$plan = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts'   => array(
					'level'      => 'contribute',
					'can_delete' => false,
					'mods_set'   => true,
				),
				'plugins' => 'off',
			)
		);

		$this->assertTrue( $plan['controls']['posts']['custom'] );
		$this->assertSame( array( 'edit_others_posts' ), $plan['controls']['posts']['remove'] );
		$this->assertTrue( $plan['controls']['plugins']['custom'] );
		$this->assertSame( array( 'install_plugins' ), $plan['controls']['plugins']['remove'] );
		$this->assertTrue( BLT_Toolkit_Admin_Roles::simple_needs_review( array( 'add' => array(), 'remove' => array( 'edit_others_posts' ) ), $plan['controls'] ) );
	}

	/**
	 * RO-1: a deny (`false`) inside a changed control that the change does
	 * not grant stays a deny; one the change grants flips.
	 *
	 * @return void
	 */
	public function test_ro1_denies_stay_unless_granted() {
		$caps     = array(
			'read'          => true,
			'edit_posts'    => true,
			'publish_posts' => false,
		);
		$controls = $this->controls( $caps, array( 'posts' => BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() ) ), array() );

		$plan  = BLT_Toolkit_Simple_Controls::plan( $controls, $caps, array( 'posts' => array( 'level' => 'none' ) ) );
		$after = $this->saved( $caps, $plan );

		$this->assertSame(
			array(
				'read'          => true,
				'publish_posts' => false,
			),
			$after
		);

		$plan  = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts' => array(
					'level'      => 'author',
					'can_delete' => false,
					'mods_set'   => true,
				),
			)
		);
		$after = $this->saved( $caps, $plan );

		$this->assertTrue( $after['publish_posts'] );
	}

	/**
	 * RS-5: two changed controls that want one capability both ways are
	 * refused rather than guessed.
	 *
	 * @return void
	 */
	public function test_rs5_overlapping_controls_are_refused() {
		$map      = BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() );
		$caps2    = array(
			'read'       => true,
			'edit_posts' => true,
		);
		$controls = $this->controls( $caps2, array( 'posts' => $map ), array( 'writer' => array( 'edit_posts', 'acme_write' ) ) );

		$plan = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps2,
			array(
				'posts'  => array( 'level' => 'none' ),
				'writer' => 'on',
			)
		);

		$this->assertInstanceOf( 'WP_Error', $plan );
		$this->assertSame( 'blt_toolkit_simple_overlap', $plan->get_error_code() );
	}

	/**
	 * RS-5: read-only rows (RS-11, RS-12) and unknown controls produce
	 * nothing, whatever is posted.
	 *
	 * @return void
	 */
	public function test_rs5_read_only_rows_never_change() {
		$caps     = array( 'read' => true );
		$controls = $this->controls( $caps, array( 'posts' => BLT_Toolkit_Simple_Controls::type_map( $this->posts_group(), array() ) ), array() );

		$controls['posts']['readonly'] = BLT_Toolkit_Simple_Controls::READONLY_OWNERSHIP;

		$plan = BLT_Toolkit_Simple_Controls::plan(
			$controls,
			$caps,
			array(
				'posts' => array( 'level' => 'full' ),
				'nope'  => 'on',
			)
		);

		$this->assertSame( array(), $plan['on_page'] );
	}

	/**
	 * SPEC 9.5: the toolbar setting is role metadata, planned separately
	 * from capabilities.
	 *
	 * @return void
	 */
	public function test_spec_9_5_toolbar_is_metadata() {
		$controls = array(
			'hide_toolbar' => array(
				'id'       => 'hide_toolbar',
				'kind'     => BLT_Toolkit_Simple_Controls::META,
				'label'    => 'Hide the toolbar on the site',
				'readonly' => '',
				'universe' => array(),
				'granted'  => array(),
				'state'    => 'off',
			),
		);

		$plan = BLT_Toolkit_Simple_Controls::plan( $controls, array(), array( 'hide_toolbar' => 'on' ) );

		$this->assertSame( array(), $plan['on_page'] );
		$this->assertSame( array( 'hide_toolbar' => true ), $plan['meta'] );
	}

	// ---------- BLT_Toolkit_Admin_Roles helpers ----------

	/**
	 * RS-6: the review is needed for a Critical capability (granted or
	 * removed) and for more than ten changes, not for a small change.
	 *
	 * @return void
	 */
	public function test_rs6_review_rules() {
		$this->assertFalse( BLT_Toolkit_Admin_Roles::simple_needs_review( array( 'add' => array( 'edit_pages' => true ), 'remove' => array() ), array() ) );
		$this->assertTrue( BLT_Toolkit_Admin_Roles::simple_needs_review( array( 'add' => array( 'manage_options' => true ), 'remove' => array() ), array() ) );
		$this->assertTrue( BLT_Toolkit_Admin_Roles::simple_needs_review( array( 'add' => array(), 'remove' => array( 'promote_users' ) ), array() ) );

		$eleven = array();

		foreach ( range( 1, 11 ) as $n ) {
			$eleven[ 'acme_' . $n ] = true;
		}

		$this->assertTrue( BLT_Toolkit_Admin_Roles::simple_needs_review( array( 'add' => $eleven, 'remove' => array() ), array() ) );
		$this->assertFalse( BLT_Toolkit_Admin_Roles::simple_needs_review( array( 'add' => array_slice( $eleven, 0, 10, true ), 'remove' => array() ), array() ) );
	}

	/**
	 * RS-5: the posted `simple` array is cleaned: unknown values dropped,
	 * levels and modifiers kept, keys reduced to safe characters.
	 *
	 * @return void
	 */
	public function test_rs5_clean_simple() {
		$clean = BLT_Toolkit_Admin_Roles::clean_simple(
			array(
				'read'             => 'on',
				'Upload_Files'     => 'OFF',
				'x"><script>'      => 'on',
				'weird'            => 'maybe',
				'content-post'     => array(
					'level'      => 'Edit_All',
					'can_delete' => '1',
					'manage'     => array(
						'genre' => '0',
						'bad"'  => '1',
					),
					'mods_set'   => '1',
				),
				'content-page'     => array( 'can_delete' => '1' ),
				'content-nolevel'  => array( 'level' => array( 'x' ) ),
			)
		);

		$this->assertSame(
			array(
				'read'         => 'on',
				'upload_files' => 'off',
				'xscript'      => 'on',
				'content-post' => array(
					'level'      => 'edit_all',
					'can_delete' => true,
					'manage'     => array(
						'genre' => false,
						'bad'   => true,
					),
					'mods_set'   => true,
				),
			),
			$clean
		);
	}

	/**
	 * RS-6: labels read as words inside a sentence.
	 *
	 * @return void
	 */
	public function test_rs6_sentence_case() {
		$this->assertSame( 'edit others\' Events', BLT_Toolkit_Admin_Roles::sentence_case( 'Edit others\' Events' ) );
		$this->assertSame( 'WordPress updates', BLT_Toolkit_Admin_Roles::sentence_case( 'WordPress updates' ) );
		$this->assertSame( 'BLT Toolkit activity', BLT_Toolkit_Admin_Roles::sentence_case( 'BLT Toolkit activity' ) );
	}

	/**
	 * RS-4's "Edit in Advanced": the matrix can be narrowed to one control's
	 * capabilities.
	 *
	 * @return void
	 */
	public function test_rs4_visible_sections_only() {
		$entries  = array(
			'edit_posts'   => array( 'label' => 'Create and edit their own posts' ),
			'upload_files' => array( 'label' => 'Upload files' ),
			'install_themes' => array( 'label' => 'Install themes' ),
		);
		$sections = BLT_Toolkit_Admin_Roles::visible_sections(
			array(
				array(
					'id'    => 'a',
					'label' => 'A',
					'caps'  => array( 'edit_posts', 'upload_files' ),
				),
				array(
					'id'    => 'b',
					'label' => 'B',
					'caps'  => array( 'install_themes' ),
				),
			),
			$entries,
			array( 'only' => array( 'install_themes', 'edit_posts' ) )
		);

		$this->assertSame( array( 'a', 'b' ), array_column( $sections, 'id' ) );
		$this->assertSame( array( 'edit_posts' ), $sections[0]['caps'] );
		$this->assertSame( array( 'install_themes' ), $sections[1]['caps'] );
	}

	/**
	 * SPEC 9.3, 10.7: which permission a one-role save needs by context.
	 *
	 * @return void
	 */
	public function test_spec_10_7_context_permission() {
		$this->assertSame( 'blt_edit_roles', BLT_Toolkit_Role_Store::context_cap( 'simple' ) );
		$this->assertSame( 'blt_edit_capabilities', BLT_Toolkit_Role_Store::context_cap( 'advanced' ) );
		$this->assertSame( '', BLT_Toolkit_Role_Store::context_cap( 'lifecycle' ) );
	}

	/**
	 * Pages' content group.
	 *
	 * @return array
	 */
	private function pages_group() {
		$group = $this->posts_group();
		$caps  = array();

		foreach ( $group['caps'] as $key => $string ) {
			$caps[ $key ] = 'read' === $string ? 'read' : str_replace( 'posts', 'pages', $string );
		}

		return array_merge(
			$group,
			array(
				'id'            => 'content-page',
				'primary'       => 'page',
				'primary_label' => 'Pages',
				'label'         => 'Pages',
				'types'         => array( 'page' ),
				'type_labels'   => array( 'page' => 'Pages' ),
				'caps'          => $caps,
			)
		);
	}
}

