<?php
/**
 * Simple view level mapping (SPEC 10.3, 10.4; RS-4, RS-5, RS-11 to RS-16)
 * with known vectors.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Simple_Mapper (SPEC 18, SPEC 17 Phase 5).
 */
class Test_Simple_Mapper extends TestCase {

	/**
	 * Capabilities outside every fixture's universe; a level change must
	 * never touch them (RS-5). `read` belongs to the Basics control.
	 *
	 * @var string[]
	 */
	private $extras = array( 'read', 'upload_files', 'level_1', 'some_plugin_cap', 'blt_readonly_books' );

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
	 * WordPress's default roles exactly as populate_roles() (populate_roles_160
	 * to populate_roles_300 in wp-admin/includes/schema.php) leaves them,
	 * confirmed against a fresh WordPress 7.1.3 install.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function default_roles() {
		$subscriber  = array(
			'read'    => true,
			'level_0' => true,
		);
		$contributor = array(
			'edit_posts'   => true,
			'read'         => true,
			'level_1'      => true,
			'level_0'      => true,
			'delete_posts' => true,
		);
		$author      = array(
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
		$editor      = array(
			'moderate_comments'      => true,
			'manage_categories'      => true,
			'manage_links'           => true,
			'upload_files'           => true,
			'unfiltered_html'        => true,
			'edit_posts'             => true,
			'edit_others_posts'      => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'edit_pages'             => true,
			'read'                   => true,
			'level_7'                => true,
			'level_6'                => true,
			'level_5'                => true,
			'level_4'                => true,
			'level_3'                => true,
			'level_2'                => true,
			'level_1'                => true,
			'level_0'                => true,
			'edit_others_pages'      => true,
			'edit_published_pages'   => true,
			'publish_pages'          => true,
			'delete_pages'           => true,
			'delete_others_pages'    => true,
			'delete_published_pages' => true,
			'delete_posts'           => true,
			'delete_others_posts'    => true,
			'delete_published_posts' => true,
			'delete_private_posts'   => true,
			'edit_private_posts'     => true,
			'read_private_posts'     => true,
			'delete_private_pages'   => true,
			'edit_private_pages'     => true,
			'read_private_pages'     => true,
		);
		$admin       = array(
			'switch_themes'          => true,
			'edit_themes'            => true,
			'activate_plugins'       => true,
			'edit_plugins'           => true,
			'edit_users'             => true,
			'edit_files'             => true,
			'manage_options'         => true,
			'moderate_comments'      => true,
			'manage_categories'      => true,
			'manage_links'           => true,
			'upload_files'           => true,
			'import'                 => true,
			'unfiltered_html'        => true,
			'edit_posts'             => true,
			'edit_others_posts'      => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'edit_pages'             => true,
			'read'                   => true,
			'level_10'               => true,
			'level_9'                => true,
			'level_8'                => true,
			'level_7'                => true,
			'level_6'                => true,
			'level_5'                => true,
			'level_4'                => true,
			'level_3'                => true,
			'level_2'                => true,
			'level_1'                => true,
			'level_0'                => true,
			'edit_others_pages'      => true,
			'edit_published_pages'   => true,
			'publish_pages'          => true,
			'delete_pages'           => true,
			'delete_others_pages'    => true,
			'delete_published_pages' => true,
			'delete_posts'           => true,
			'delete_others_posts'    => true,
			'delete_published_posts' => true,
			'delete_private_posts'   => true,
			'edit_private_posts'     => true,
			'read_private_posts'     => true,
			'delete_private_pages'   => true,
			'edit_private_pages'     => true,
			'read_private_pages'     => true,
			'delete_users'           => true,
			'create_users'           => true,
			'unfiltered_upload'      => true,
			'edit_dashboard'         => true,
			'update_plugins'         => true,
			'delete_plugins'         => true,
			'install_plugins'        => true,
			'update_themes'          => true,
			'install_themes'         => true,
			'update_core'            => true,
			'list_users'             => true,
			'remove_users'           => true,
			'promote_users'          => true,
			'edit_theme_options'     => true,
			'delete_themes'          => true,
			'export'                 => true,
		);

		return array(
			'subscriber'    => $subscriber,
			'contributor'   => $contributor,
			'author'        => $author,
			'editor'        => $editor,
			'administrator' => $admin,
		);
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
	private function cap_object( $singular, $plural, $map_meta_cap = true, array $overrides = array() ) {
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

		$caps = array_merge( $caps, $overrides );

		if ( ! isset( $caps['create_posts'] ) ) {
			$caps['create_posts'] = $caps['edit_posts'];
		}

		return $caps;
	}

	/**
	 * A taxonomy entry of a type map.
	 *
	 * @param string $label     Label.
	 * @param string $manage    manage_terms.
	 * @param string $edit      edit_terms.
	 * @param string $delete    delete_terms.
	 * @param string $assign    assign_terms.
	 * @param bool   $dedicated Whether manage, edit and delete are the taxonomy's own.
	 * @return array
	 */
	private function tax( $label, $manage, $edit, $delete, $assign, $dedicated ) {
		return array(
			'label'        => $label,
			'manage_terms' => $manage,
			'edit_terms'   => $edit,
			'delete_terms' => $delete,
			'assign_terms' => $assign,
			'dedicated'    => $dedicated,
		);
	}

	/**
	 * Core's category taxonomy: the default `manage_categories`, so it is
	 * site-wide (Appendix A) and never part of a row.
	 *
	 * @return array
	 */
	private function category() {
		return $this->tax( 'Categories', 'manage_categories', 'edit_categories', 'delete_categories', 'assign_categories', false );
	}

	/**
	 * Posts, with the cap object WordPress 7.1 registers (create_posts is
	 * edit_posts) and its default taxonomies.
	 *
	 * @return array
	 */
	private function posts_map() {
		return array(
			'caps'       => array(
				'edit_post'              => 'edit_post',
				'read_post'              => 'read_post',
				'delete_post'            => 'delete_post',
				'edit_posts'             => 'edit_posts',
				'edit_others_posts'      => 'edit_others_posts',
				'delete_posts'           => 'delete_posts',
				'publish_posts'          => 'publish_posts',
				'read_private_posts'     => 'read_private_posts',
				'read'                   => 'read',
				'delete_private_posts'   => 'delete_private_posts',
				'delete_published_posts' => 'delete_published_posts',
				'delete_others_posts'    => 'delete_others_posts',
				'edit_private_posts'     => 'edit_private_posts',
				'edit_published_posts'   => 'edit_published_posts',
				'create_posts'           => 'edit_posts',
			),
			'marker'     => '',
			'taxonomies' => array(
				'category' => $this->category(),
				'post_tag' => $this->tax( 'Tags', 'manage_post_tags', 'edit_post_tags', 'delete_post_tags', 'assign_post_tags', false ),
			),
		);
	}

	/**
	 * Pages, with the cap object WordPress 7.1 registers.
	 *
	 * @return array
	 */
	private function pages_map() {
		return array(
			'caps'       => array(
				'edit_post'              => 'edit_page',
				'read_post'              => 'read_page',
				'delete_post'            => 'delete_page',
				'edit_posts'             => 'edit_pages',
				'edit_others_posts'      => 'edit_others_pages',
				'delete_posts'           => 'delete_pages',
				'publish_posts'          => 'publish_pages',
				'read_private_posts'     => 'read_private_pages',
				'read'                   => 'read',
				'delete_private_posts'   => 'delete_private_pages',
				'delete_published_posts' => 'delete_published_pages',
				'delete_others_posts'    => 'delete_others_pages',
				'edit_private_posts'     => 'edit_private_pages',
				'edit_published_posts'   => 'edit_published_pages',
				'create_posts'           => 'edit_pages',
			),
			'marker'     => '',
			'taxonomies' => array(),
		);
	}

	/**
	 * An isolated CPT as RS-31 registers it: capability_type
	 * array( 'event', 'events' ), map_meta_cap true, create_posts
	 * `create_events`; marker `blt_readonly_events`; two dedicated
	 * taxonomies and core's category.
	 *
	 * @return array
	 */
	private function isolated_map() {
		return array(
			'caps'       => $this->cap_object( 'event', 'events', true, array( 'create_posts' => 'create_events' ) ),
			'marker'     => BLT_Toolkit_Simple_Mapper::marker_name( 'events' ),
			'taxonomies' => array(
				'event_cat' => $this->tax( 'Event Categories', 'manage_event_cats', 'edit_event_cats', 'delete_event_cats', 'assign_event_cats', true ),
				'event_tag' => $this->tax( 'Event Tags', 'manage_event_tags', 'edit_event_tags', 'delete_event_tags', 'assign_event_tags', true ),
				'category'  => $this->category(),
			),
		);
	}

	/**
	 * A CPT with its own capability_type (`book`): create_posts is
	 * edit_books, so no marker; a dedicated Genres taxonomy whose manage,
	 * edit and delete are all `manage_genres` and whose assign_terms is the
	 * type's own edit_books (a common registration).
	 *
	 * @return array
	 */
	private function own_type_map() {
		return array(
			'caps'       => $this->cap_object( 'book', 'books' ),
			'marker'     => '',
			'taxonomies' => array(
				'genre' => $this->tax( 'Genres', 'manage_genres', 'manage_genres', 'manage_genres', 'edit_books', true ),
			),
		);
	}

	/**
	 * A CPT with map_meta_cap false (`ledger`): no edit_published_posts and
	 * the other mapping-only keys, and per-item names (edit_ledger,
	 * read_ledger, delete_ledger) in its cap object. RS-12 shows no level
	 * selector for it; the mapper must still behave.
	 *
	 * @return array
	 */
	private function meta_false_map() {
		return array(
			'caps'       => $this->cap_object( 'ledger', 'ledgers', false ),
			'marker'     => '',
			'taxonomies' => array(
				'ledger_type' => $this->tax( 'Ledger Types', 'manage_ledger_types', 'edit_ledger_types', 'delete_ledger_types', 'assign_ledger_types', true ),
			),
		);
	}

	/**
	 * An ACF-style CPT, as WordPress 7.1 registers ACF's field groups:
	 * capability_type `post`, map_meta_cap false, and edit_post,
	 * delete_post, edit_posts, delete_posts and create_posts all
	 * `manage_options`.
	 *
	 * @return array
	 */
	private function acf_map() {
		return array(
			'caps'       => $this->cap_object(
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
			'marker'     => '',
			'taxonomies' => array(),
		);
	}

	// ---------- Helpers ----------

	/**
	 * Every level and modifier combination a row can be set to: Can delete
	 * both ways where it is shown (on at Full control, off below
	 * Contribute), every subset of Manage toggles at Contribute and above.
	 *
	 * @param array $map Type map.
	 * @return array[] States, array( 'level' => ..., 'mods' => ... ).
	 */
	private function states( array $map ) {
		$taxes  = array_keys( BLT_Toolkit_Simple_Mapper::toggles( $map ) );
		$combos = array( array() );

		foreach ( $taxes as $tax ) {
			$next = array();

			foreach ( $combos as $combo ) {
				$next[] = $combo + array( $tax => true );
				$next[] = $combo + array( $tax => false );
			}

			$combos = $next;
		}

		$off = array_fill_keys( $taxes, false );
		$out = array();

		foreach ( BLT_Toolkit_Simple_Mapper::LEVELS as $level ) {
			if ( BLT_Toolkit_Simple_Mapper::shows_can_delete( $level ) ) {
				$deletes = array( true, false );
			} else {
				$deletes = array( BLT_Toolkit_Simple_Mapper::FULL === $level );
			}

			$manages = BLT_Toolkit_Simple_Mapper::shows_manage( $level ) ? $combos : array( $off );

			foreach ( $deletes as $can_delete ) {
				foreach ( $manages as $manage ) {
					$out[] = array(
						'level' => $level,
						'mods'  => array(
							'can_delete' => $can_delete,
							'manage'     => $manage,
						),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * A role's capabilities after the mapper's changes, as name => true.
	 *
	 * @param array $before  Capabilities before (name => value, or a list).
	 * @param array $changes apply() result.
	 * @return array<string,bool>
	 */
	private function after( array $before, array $changes ) {
		$caps = BLT_Toolkit_Simple_Mapper::granted_set( $before );

		foreach ( $changes['add'] as $cap ) {
			$caps[ $cap ] = true;
		}

		foreach ( $changes['remove'] as $cap ) {
			unset( $caps[ $cap ] );
		}

		ksort( $caps );

		return $caps;
	}

	/**
	 * Starting points for a round trip: nothing, everything in the universe
	 * plus unrelated capabilities, and a mixture Simple cannot show.
	 *
	 * @param array $map Type map.
	 * @return array[] Name => capabilities.
	 */
	private function starts( array $map ) {
		$universe = BLT_Toolkit_Simple_Mapper::universe( $map );
		$half     = array();

		foreach ( $universe as $index => $cap ) {
			if ( 0 === $index % 2 ) {
				$half[ $cap ] = true;
			}
		}

		$extras = array_fill_keys( $this->extras, true );

		return array(
			'empty'         => array(),
			'unrelated'     => $extras,
			'everything'    => array_fill_keys( $universe, true ) + $extras,
			'mixture'       => $half + $extras,
			'denied (list)' => array_keys( $half ),
		);
	}

	/**
	 * Assert detect( apply( state ) ) gives the expected state from every
	 * starting point, that nothing outside the universe moved (RS-5), and
	 * that saving the detected state again changes nothing.
	 *
	 * @param string   $name     Fixture name for messages.
	 * @param array    $map      Type map.
	 * @param callable $expected Maps a state to the state detection must give (identity for most fixtures).
	 * @return int Number of states checked.
	 */
	private function assert_round_trip( $name, array $map, $expected = null ) {
		$universe = BLT_Toolkit_Simple_Mapper::universe( $map );
		$states   = $this->states( $map );

		foreach ( $states as $state ) {
			$want  = null === $expected ? $state : call_user_func( $expected, $state );
			$label = $name . ' ' . wp_json_encode( $state );

			foreach ( $this->starts( $map ) as $start_name => $before ) {
				$changes = BLT_Toolkit_Simple_Mapper::apply( $map, $before, $state['level'], $state['mods'] );

				$this->assertSame( array(), array_diff( $changes['add'], $universe ), $label . ': adds stay in the universe (RS-5).' );
				$this->assertSame( array(), array_diff( $changes['remove'], $universe ), $label . ': removes stay in the universe (RS-5).' );

				$after = $this->after( $before, $changes );

				$this->assertSame( $want, BLT_Toolkit_Simple_Mapper::detect( $map, $after ), $label . ' from ' . $start_name );
				$this->assertSame(
					BLT_Toolkit_Simple_Mapper::caps_for( $map, $state['level'], $state['mods'] ),
					$this->set( array_values( array_intersect( array_keys( $after ), $universe ) ) ),
					$label . ' from ' . $start_name . ': the row holds exactly the level\'s set.'
				);

				$before_set = BLT_Toolkit_Simple_Mapper::granted_set( $before );

				foreach ( $this->extras as $extra ) {
					if ( isset( $before_set[ $extra ] ) && ! in_array( $extra, $universe, true ) ) {
						$this->assertArrayHasKey( $extra, $after, $label . ': ' . $extra . ' is outside the row and untouched (RS-5).' );
					}
				}

				$again = BLT_Toolkit_Simple_Mapper::apply( $map, $after, $want['level'], $want['mods'] );

				$this->assertSame(
					array(
						'add'    => array(),
						'remove' => array(),
					),
					$again,
					$label . ': saving the detected state changes nothing (RS-5).'
				);
			}
		}

		return count( $states );
	}

	/**
	 * A detected state.
	 *
	 * @param string $level      Level.
	 * @param bool   $can_delete Can delete.
	 * @param array  $manage     Manage toggles.
	 * @return array
	 */
	private function state( $level, $can_delete, array $manage = array() ) {
		return array(
			'level' => $level,
			'mods'  => array(
				'can_delete' => $can_delete,
				'manage'     => $manage,
			),
		);
	}

	/**
	 * Sorted list of strings.
	 *
	 * @param string[] $caps Capabilities.
	 * @return string[]
	 */
	private function set( array $caps ) {
		sort( $caps, SORT_STRING );

		return $caps;
	}

	// ---------- RS-16: WordPress's default roles ----------

	/**
	 * RS-16: for Posts, Subscriber reads No access, Contributor Contribute
	 * with Can delete, Author Author with Can delete, Editor and
	 * Administrator Full control.
	 *
	 * @return void
	 */
	public function test_rs16_default_roles_read_as_specified_for_posts() {
		$roles = $this->default_roles();
		$map   = $this->posts_map();

		$this->assertSame( $this->state( 'none', false ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['subscriber'] ) );
		$this->assertSame( $this->state( 'contribute', true ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['contributor'] ) );
		$this->assertSame( $this->state( 'author', true ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['author'] ) );
		$this->assertSame( $this->state( 'full', true ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['editor'] ) );
		$this->assertSame( $this->state( 'full', true ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['administrator'] ) );
	}

	/**
	 * RS-16: for Pages, Subscriber, Contributor and Author read No access,
	 * Editor and Administrator Full control.
	 *
	 * @return void
	 */
	public function test_rs16_default_roles_read_as_specified_for_pages() {
		$roles = $this->default_roles();
		$map   = $this->pages_map();

		$this->assertSame( $this->state( 'none', false ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['subscriber'] ) );
		$this->assertSame( $this->state( 'none', false ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['contributor'] ) );
		$this->assertSame( $this->state( 'none', false ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['author'] ) );
		$this->assertSame( $this->state( 'full', true ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['editor'] ) );
		$this->assertSame( $this->state( 'full', true ), BLT_Toolkit_Simple_Mapper::detect( $map, $roles['administrator'] ) );
	}

	/**
	 * RS-16 with RS-5: saving a default role's detected state changes
	 * nothing, for Posts and for Pages.
	 *
	 * @return void
	 */
	public function test_rs16_default_roles_are_unchanged_by_saving_their_state() {
		foreach ( array( $this->posts_map(), $this->pages_map() ) as $map ) {
			foreach ( $this->default_roles() as $role => $caps ) {
				$state = BLT_Toolkit_Simple_Mapper::detect( $map, $caps );

				$this->assertSame(
					array(
						'add'    => array(),
						'remove' => array(),
					),
					BLT_Toolkit_Simple_Mapper::apply( $map, $caps, $state['level'], $state['mods'] ),
					$role
				);
			}
		}
	}

	// ---------- SPEC 10.4 table: known vectors ----------

	/**
	 * SPEC 10.4 for Posts, where create_posts is edit_posts: every level and
	 * Can delete value gives the table's set; View is site-only (RS-13).
	 *
	 * @return void
	 */
	public function test_spec_10_4_posts_level_sets() {
		$map = $this->posts_map();

		$expected = array(
			'none'          => array(),
			'view'          => array( 'read_private_posts' ),
			'contribute/0'  => array( 'edit_posts' ),
			'contribute/1'  => array( 'delete_posts', 'edit_posts' ),
			'author/0'      => array( 'edit_posts', 'edit_published_posts', 'publish_posts' ),
			'author/1'      => array( 'delete_posts', 'delete_published_posts', 'edit_posts', 'edit_published_posts', 'publish_posts' ),
			'edit_all/0'    => array( 'edit_others_posts', 'edit_posts', 'edit_private_posts', 'edit_published_posts', 'read_private_posts' ),
			'edit_all/1'    => array( 'delete_others_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'edit_others_posts', 'edit_posts', 'edit_private_posts', 'edit_published_posts', 'read_private_posts' ),
			'full'          => array( 'delete_others_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'edit_others_posts', 'edit_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts' ),
			'full/no-del'   => array( 'delete_others_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'edit_others_posts', 'edit_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts' ),
			'none/with-del' => array(),
		);

		$this->assertSame( $expected['none'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'none' ) );
		$this->assertSame( $expected['view'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'view' ) );
		$this->assertSame( $expected['contribute/0'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute', array( 'can_delete' => false ) ) );
		$this->assertSame( $expected['contribute/1'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute', array( 'can_delete' => true ) ) );
		$this->assertSame( $expected['author/0'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'author', array( 'can_delete' => false ) ) );
		$this->assertSame( $expected['author/1'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'author', array( 'can_delete' => true ) ) );
		$this->assertSame( $expected['edit_all/0'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'edit_all', array( 'can_delete' => false ) ) );
		$this->assertSame( $expected['edit_all/1'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'edit_all', array( 'can_delete' => true ) ) );
		$this->assertSame( $expected['full'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'full' ) );
		$this->assertSame( $expected['full/no-del'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'full', array( 'can_delete' => false ) ), 'Full control always includes deletion.' );
		$this->assertSame( $expected['none/with-del'], BLT_Toolkit_Simple_Mapper::caps_for( $map, 'none', array( 'can_delete' => true ) ), 'Can delete is not shown at No access.' );
	}

	/**
	 * SPEC 10.4 and RS-13 for an isolated CPT: create_events is counted at
	 * Contribute and above, View grants read_private_events, edit_events and
	 * the marker (and never create_events), dedicated assign_terms come at
	 * Contribute and above.
	 *
	 * @return void
	 */
	public function test_rs13_isolated_type_level_sets() {
		$map    = $this->isolated_map();
		$assign = array( 'assign_event_cats', 'assign_event_tags' );

		$this->assertTrue( BLT_Toolkit_Simple_Mapper::create_distinct( $map ) );
		$this->assertSame( 'blt_readonly_events', BLT_Toolkit_Simple_Mapper::marker( $map ) );
		$this->assertSame( array( 'blt_readonly_events', 'edit_events', 'read_private_events' ), BLT_Toolkit_Simple_Mapper::caps_for( $map, 'view' ) );
		$this->assertSame(
			$this->set( array_merge( $assign, array( 'create_events', 'edit_events' ) ) ),
			BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute', array( 'can_delete' => false ) )
		);
		$this->assertSame(
			$this->set( array_merge( $assign, array( 'create_events', 'delete_events', 'delete_published_events', 'edit_events', 'edit_published_events', 'publish_events' ) ) ),
			BLT_Toolkit_Simple_Mapper::caps_for( $map, 'author', array( 'can_delete' => true ) )
		);
		$this->assertSame(
			$this->set( array_merge( $assign, array( 'create_events', 'edit_events', 'edit_others_events', 'edit_private_events', 'edit_published_events', 'read_private_events' ) ) ),
			BLT_Toolkit_Simple_Mapper::caps_for( $map, 'edit_all' )
		);

		$full = BLT_Toolkit_Simple_Mapper::caps_for( $map, 'full' );

		$this->assertContains( 'create_events', $full );
		$this->assertContains( 'manage_event_cats', $full, 'Manage toggles are on by default at Full control.' );
		$this->assertNotContains( 'blt_readonly_events', $full );
		$this->assertNotContains( 'manage_categories', $full, 'Default taxonomies are site-wide (Appendix A).' );

		$this->assertSame(
			$this->set(
				array(
					'assign_event_cats',
					'assign_event_tags',
					'blt_readonly_events',
					'create_events',
					'delete_event_cats',
					'delete_event_tags',
					'delete_events',
					'delete_others_events',
					'delete_private_events',
					'delete_published_events',
					'edit_event_cats',
					'edit_event_tags',
					'edit_events',
					'edit_others_events',
					'edit_private_events',
					'edit_published_events',
					'manage_event_cats',
					'manage_event_tags',
					'publish_events',
					'read_private_events',
				)
			),
			BLT_Toolkit_Simple_Mapper::universe( $map )
		);
	}

	/**
	 * RS-13: when create_posts is not distinct, View is site-only
	 * (read_private_posts alone), even if a marker is passed, and the marker
	 * is not part of the row.
	 *
	 * @return void
	 */
	public function test_rs13_view_is_site_only_without_distinct_create() {
		$books           = $this->own_type_map();
		$books['marker'] = 'blt_readonly_books';

		$this->assertFalse( BLT_Toolkit_Simple_Mapper::create_distinct( $books ) );
		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::marker( $books ) );
		$this->assertSame( array( 'read_private_books' ), BLT_Toolkit_Simple_Mapper::caps_for( $books, 'view' ) );
		$this->assertNotContains( 'blt_readonly_books', BLT_Toolkit_Simple_Mapper::universe( $books ) );
		$this->assertSame( array( 'read_private_posts' ), BLT_Toolkit_Simple_Mapper::caps_for( $this->posts_map(), 'view' ) );

		$isolated           = $this->isolated_map();
		$isolated['marker'] = '';

		$this->assertSame( array( 'read_private_events' ), BLT_Toolkit_Simple_Mapper::caps_for( $isolated, 'view' ), 'Without a marker, View cannot be enforced in the dashboard.' );
	}

	// ---------- RS-15 round trips (SPEC 17 Phase 5) ----------

	/**
	 * RS-15 round trip on Posts: detect( apply( state ) ) === state for every
	 * level and modifier combination, from every starting point.
	 *
	 * @return void
	 */
	public function test_rs15_round_trip_posts() {
		$this->assertSame( 9, $this->assert_round_trip( 'Posts', $this->posts_map() ) );
	}

	/**
	 * RS-15 round trip on Pages.
	 *
	 * @return void
	 */
	public function test_rs15_round_trip_pages() {
		$this->assertSame( 9, $this->assert_round_trip( 'Pages', $this->pages_map() ) );
	}

	/**
	 * RS-15 round trip on an isolated CPT (distinct create_events, marker,
	 * two Manage toggles: 4 combinations each at Contribute and above).
	 *
	 * @return void
	 */
	public function test_rs15_round_trip_isolated_cpt() {
		$this->assertSame( 2 + 3 * 2 * 4 + 4, $this->assert_round_trip( 'Isolated', $this->isolated_map() ) );
	}

	/**
	 * RS-15 round trip on a CPT with its own capability_type (no marker, a
	 * toggle whose assign_terms collapses into edit_books).
	 *
	 * @return void
	 */
	public function test_rs15_round_trip_own_capability_type_cpt() {
		$this->assertSame( 2 + 3 * 2 * 2 + 2, $this->assert_round_trip( 'Books', $this->own_type_map() ) );
	}

	/**
	 * RS-15 round trip on a map_meta_cap false CPT: the missing keys add
	 * nothing, the per-item names are not part of the row (RS-12 shows no
	 * selector, but the mapper still behaves).
	 *
	 * @return void
	 */
	public function test_rs15_round_trip_map_meta_cap_false_cpt() {
		$map = $this->meta_false_map();

		$this->assertSame( 2 + 3 * 2 * 2 + 2, $this->assert_round_trip( 'Ledgers', $map ) );

		$universe = BLT_Toolkit_Simple_Mapper::universe( $map );

		foreach ( array( 'edit_ledger', 'read_ledger', 'delete_ledger', 'edit_published_ledgers', 'delete_others_ledgers' ) as $cap ) {
			$this->assertNotContains( $cap, $universe );
		}

		$this->assertSame(
			array( 'assign_ledger_types', 'delete_ledgers', 'edit_ledgers', 'publish_ledgers' ),
			BLT_Toolkit_Simple_Mapper::caps_for( $map, 'author', array( 'can_delete' => true ) ),
			'Author on a map_meta_cap false type: no edit_published or delete_published keys exist.'
		);
	}

	/**
	 * RS-15 round trip on an ACF-style CPT. Its delete_posts is the same
	 * string as edit_posts (manage_options), so Can delete on and off give
	 * the identical set at Contribute, Author and Edit all: no detection can
	 * tell them apart. The round trip then gives the combination that claims
	 * the most (Can delete on), which is what the role can really do, and
	 * saving it changes nothing. Every other state round-trips exactly.
	 *
	 * @return void
	 */
	public function test_rs15_round_trip_acf_style_cpt() {
		$map = $this->acf_map();

		$this->assertSame( array( 'manage_options' ), BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute', array( 'can_delete' => false ) ) );
		$this->assertSame( array( 'manage_options' ), BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute', array( 'can_delete' => true ) ) );

		$checked = $this->assert_round_trip(
			'ACF',
			$map,
			function ( $state ) {
				if ( BLT_Toolkit_Simple_Mapper::shows_can_delete( $state['level'] ) ) {
					$state['mods']['can_delete'] = true;
				}

				return $state;
			}
		);

		$this->assertSame( 9, $checked );
		$this->assertSame( $this->state( 'edit_all', true ), BLT_Toolkit_Simple_Mapper::detect( $map, array( 'manage_options', 'edit_others_posts', 'read_private_posts' ) ) );
	}

	/**
	 * RS-15 on the fixtures other than ACF-style: no two combinations give
	 * the same set, so detection is exact.
	 *
	 * @return void
	 */
	public function test_rs15_every_combination_has_a_distinct_set() {
		foreach ( array( $this->posts_map(), $this->pages_map(), $this->isolated_map(), $this->own_type_map(), $this->meta_false_map() ) as $index => $map ) {
			$seen = array();

			foreach ( $this->states( $map ) as $state ) {
				$key = implode( ',', BLT_Toolkit_Simple_Mapper::caps_for( $map, $state['level'], $state['mods'] ) );

				$this->assertArrayNotHasKey( $key, $seen, 'Fixture ' . $index . ' ' . wp_json_encode( $state ) );
				$seen[ $key ] = true;
			}
		}
	}

	// ---------- RS-4: Custom ----------

	/**
	 * RS-4, RS-15: an extra capability within the row, a missing one, a
	 * stray marker or View plus create_events reads as Custom.
	 *
	 * @return void
	 */
	public function test_rs4_custom_when_no_level_matches() {
		$roles = $this->default_roles();
		$posts = $this->posts_map();

		$extra                      = $roles['author'];
		$extra['edit_others_posts'] = true;

		$missing = $roles['editor'];
		unset( $missing['delete_others_posts'] );

		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $posts, $extra )['level'], 'Author plus edit_others_posts.' );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $posts, $missing )['level'], 'Editor without delete_others_posts.' );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $posts, array( 'publish_posts' ) )['level'], 'Publish without edit.' );

		$events = $this->isolated_map();
		$view   = BLT_Toolkit_Simple_Mapper::caps_for( $events, 'view' );
		$full   = BLT_Toolkit_Simple_Mapper::caps_for( $events, 'full' );

		$this->assertSame( 'view', BLT_Toolkit_Simple_Mapper::detect( $events, $view )['level'] );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $events, array_merge( $view, array( 'create_events' ) ) )['level'], 'View plus create is not read-only.' );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $events, array_merge( $full, array( 'blt_readonly_events' ) ) )['level'], 'A stray marker.' );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $events, array( 'edit_events', 'create_events', 'manage_event_cats' ) )['level'], 'Contribute without the dedicated assign_terms.' );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $events, array( 'blt_readonly_events' ) )['level'], 'The marker alone.' );
	}

	/**
	 * RS-4: Custom reports which Manage toggles are fully granted and Can
	 * delete off, for display; it is never a level.
	 *
	 * @return void
	 */
	public function test_rs4_custom_state_shape() {
		$events = $this->isolated_map();
		$caps   = array( 'publish_events', 'manage_event_cats', 'edit_event_cats', 'delete_event_cats', 'manage_event_tags' );

		$this->assertSame(
			$this->state(
				'custom',
				false,
				array(
					'event_cat' => true,
					'event_tag' => false,
				)
			),
			BLT_Toolkit_Simple_Mapper::detect( $events, $caps )
		);
		$this->assertFalse( BLT_Toolkit_Simple_Mapper::is_level( 'custom' ) );
	}

	/**
	 * RS-4: Custom (or anything that is not a level) is never applied, so a
	 * Custom control the admin did not change is never touched.
	 *
	 * @return void
	 */
	public function test_rs4_custom_is_never_applied() {
		$posts = $this->posts_map();
		$caps  = array(
			'edit_posts'        => true,
			'edit_others_posts' => true,
		);
		$none  = array(
			'add'    => array(),
			'remove' => array(),
		);

		$this->assertSame( $none, BLT_Toolkit_Simple_Mapper::apply( $posts, $caps, 'custom' ) );
		$this->assertSame( $none, BLT_Toolkit_Simple_Mapper::apply( $posts, $caps, 'everything' ) );
		$this->assertSame( $none, BLT_Toolkit_Simple_Mapper::apply( $posts, $caps, null ) );
		$this->assertSame( array(), BLT_Toolkit_Simple_Mapper::caps_for( $posts, 'custom' ) );
	}

	/**
	 * RS-4, RS-6: picking a level for a Custom row removes exactly the row's
	 * capabilities that level leaves out, and nothing else.
	 *
	 * @return void
	 */
	public function test_rs4_picking_a_level_from_custom_lists_exact_removals() {
		$roles = $this->default_roles();
		$caps  = $roles['author'];
		$posts = $this->posts_map();

		$caps['edit_others_posts'] = true;

		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array( 'edit_others_posts' ),
			),
			BLT_Toolkit_Simple_Mapper::apply( $posts, $caps, 'author', array( 'can_delete' => true ) )
		);
		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array( 'delete_posts', 'delete_published_posts', 'edit_others_posts', 'edit_posts', 'edit_published_posts', 'publish_posts' ),
			),
			BLT_Toolkit_Simple_Mapper::apply( $posts, $caps, 'none' )
		);
	}

	/**
	 * RS-5 and SPEC 17 Phase 5 (c): a role edited into an unrepresentable
	 * combination for Posts stays Custom, with the same capabilities, when
	 * an unrelated row (Pages) is saved.
	 *
	 * @return void
	 */
	public function test_rs5_saving_an_unrelated_row_leaves_custom_untouched() {
		$roles = $this->default_roles();
		$posts = $this->posts_map();
		$pages = $this->pages_map();
		$caps  = $roles['author'];

		$caps['edit_private_posts'] = true;

		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $posts, $caps )['level'] );

		$changes = BLT_Toolkit_Simple_Mapper::apply( $pages, $caps, 'full' );
		$after   = $this->after( $caps, $changes );

		$this->assertSame( array(), array_intersect( array_merge( $changes['add'], $changes['remove'] ), BLT_Toolkit_Simple_Mapper::universe( $posts ) ) );
		$this->assertSame( 'custom', BLT_Toolkit_Simple_Mapper::detect( $posts, $after )['level'] );
		$this->assertSame( 'full', BLT_Toolkit_Simple_Mapper::detect( $pages, $after )['level'] );

		foreach ( BLT_Toolkit_Simple_Mapper::universe( $posts ) as $cap ) {
			$this->assertSame( ! empty( $caps[ $cap ] ), isset( $after[ $cap ] ), $cap );
		}
	}

	/**
	 * RS-5: nothing outside the row is added or removed, including `read`
	 * and capabilities shared with other controls.
	 *
	 * @return void
	 */
	public function test_rs5_apply_stays_within_the_universe() {
		$roles   = $this->default_roles();
		$posts   = $this->posts_map();
		$changes = BLT_Toolkit_Simple_Mapper::apply( $posts, $roles['administrator'], 'none' );

		$this->assertSame(
			array( 'delete_others_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'edit_others_posts', 'edit_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts' ),
			$changes['remove']
		);
		$this->assertSame( array(), $changes['add'] );
		$this->assertNotContains( 'read', $changes['remove'] );
		$this->assertNotContains( 'manage_categories', $changes['remove'] );
		$this->assertNotContains( 'upload_files', $changes['remove'] );
	}

	// ---------- RS-15: duplicate strings ----------

	/**
	 * RS-15: create_posts equal to edit_posts collapses (Posts' universe has
	 * ten strings, not eleven); two keys sharing one string count once.
	 *
	 * @return void
	 */
	public function test_rs15_duplicate_strings_collapse() {
		$posts = $this->posts_map();

		$this->assertFalse( BLT_Toolkit_Simple_Mapper::create_distinct( $posts ) );
		$this->assertCount( 10, BLT_Toolkit_Simple_Mapper::universe( $posts ) );
		$this->assertSame( array( 'edit_posts' ), BLT_Toolkit_Simple_Mapper::caps_for( $posts, 'contribute', array( 'can_delete' => false ) ) );

		// A type whose delete keys all reuse one string.
		$map = array(
			'caps' => $this->cap_object(
				'note',
				'notes',
				true,
				array(
					'delete_posts'           => 'delete_notes',
					'delete_published_posts' => 'delete_notes',
					'delete_others_posts'    => 'delete_notes',
					'delete_private_posts'   => 'delete_notes',
				)
			),
		);

		$this->assertCount( 7, BLT_Toolkit_Simple_Mapper::universe( $map ) );
		$this->assertSame( array( 'delete_notes', 'edit_notes' ), BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute', array( 'can_delete' => true ) ) );
		$this->assertSame( $this->state( 'author', true ), BLT_Toolkit_Simple_Mapper::detect( $map, array( 'edit_notes', 'edit_published_notes', 'publish_notes', 'delete_notes' ) ) );
	}

	/**
	 * RS-15: two taxonomies with identical strings are one toggle in effect;
	 * detection reports both on (the claim that matches what is granted),
	 * and turning one on grants what both grant.
	 *
	 * @return void
	 */
	public function test_rs15_identical_taxonomy_toggles_move_together() {
		$map                                 = $this->own_type_map();
		$map['taxonomies']['shelf']          = $map['taxonomies']['genre'];
		$map['taxonomies']['shelf']['label'] = 'Shelves';

		$caps = BLT_Toolkit_Simple_Mapper::caps_for(
			$map,
			'author',
			array(
				'manage' => array(
					'genre' => true,
					'shelf' => false,
				),
			)
		);

		$this->assertContains( 'manage_genres', $caps );
		$this->assertSame(
			$this->state(
				'author',
				true,
				array(
					'genre' => true,
					'shelf' => true,
				)
			),
			BLT_Toolkit_Simple_Mapper::detect( $map, $caps )
		);
	}

	// ---------- SPEC 10.3: modifiers ----------

	/**
	 * SPEC 10.3: Can delete defaults on, on, off at Contribute, Author and
	 * Edit all; Full control always includes deletion; Manage toggles are on
	 * by default only at Full control.
	 *
	 * @return void
	 */
	public function test_spec_10_3_default_modifiers() {
		$map = $this->isolated_map();
		$off = array(
			'event_cat' => false,
			'event_tag' => false,
		);
		$on  = array(
			'event_cat' => true,
			'event_tag' => true,
		);

		$this->assertSame( $this->state( 'none', false, $off )['mods'], BLT_Toolkit_Simple_Mapper::default_mods( $map, 'none' ) );
		$this->assertSame( $this->state( 'view', false, $off )['mods'], BLT_Toolkit_Simple_Mapper::default_mods( $map, 'view' ) );
		$this->assertSame( $this->state( 'contribute', true, $off )['mods'], BLT_Toolkit_Simple_Mapper::default_mods( $map, 'contribute' ) );
		$this->assertSame( $this->state( 'author', true, $off )['mods'], BLT_Toolkit_Simple_Mapper::default_mods( $map, 'author' ) );
		$this->assertSame( $this->state( 'edit_all', false, $off )['mods'], BLT_Toolkit_Simple_Mapper::default_mods( $map, 'edit_all' ) );
		$this->assertSame( $this->state( 'full', true, $on )['mods'], BLT_Toolkit_Simple_Mapper::default_mods( $map, 'full' ) );

		$this->assertTrue( BLT_Toolkit_Simple_Mapper::shows_can_delete( 'contribute' ) );
		$this->assertTrue( BLT_Toolkit_Simple_Mapper::shows_can_delete( 'edit_all' ) );
		$this->assertFalse( BLT_Toolkit_Simple_Mapper::shows_can_delete( 'full' ) );
		$this->assertFalse( BLT_Toolkit_Simple_Mapper::shows_can_delete( 'view' ) );
		$this->assertTrue( BLT_Toolkit_Simple_Mapper::shows_manage( 'contribute' ) );
		$this->assertTrue( BLT_Toolkit_Simple_Mapper::shows_manage( 'full' ) );
		$this->assertFalse( BLT_Toolkit_Simple_Mapper::shows_manage( 'view' ) );
	}

	/**
	 * SPEC 10.3: submitted modifiers are completed from the level's defaults
	 * and made consistent with it; form strings are read as booleans.
	 *
	 * @return void
	 */
	public function test_spec_10_3_normalize_mods() {
		$map = $this->isolated_map();

		$this->assertSame(
			array(
				'can_delete' => false,
				'manage'     => array(
					'event_cat' => true,
					'event_tag' => false,
				),
			),
			BLT_Toolkit_Simple_Mapper::normalize_mods(
				$map,
				'author',
				array(
					'can_delete' => '0',
					'manage'     => array(
						'event_cat' => 'on',
						'unknown'   => true,
					),
				)
			)
		);
		$this->assertTrue( BLT_Toolkit_Simple_Mapper::normalize_mods( $map, 'full', array( 'can_delete' => false ) )['can_delete'], 'Full control always includes deletion.' );
		$this->assertSame(
			array(
				'can_delete' => false,
				'manage'     => array(
					'event_cat' => false,
					'event_tag' => false,
				),
			),
			BLT_Toolkit_Simple_Mapper::normalize_mods(
				$map,
				'view',
				array(
					'can_delete' => true,
					'manage'     => array( 'event_cat' => true ),
				)
			),
			'View has no modifiers.'
		);
	}

	/**
	 * SPEC 10.3: a dedicated taxonomy's Manage toggle grants its manage, edit
	 * and delete strings at Contribute and above when on; its assign_terms
	 * comes at Contribute and above whatever the toggle says; neither at
	 * View. A taxonomy on manage_categories is never part of a row.
	 *
	 * @return void
	 */
	public function test_spec_10_3_manage_toggles_and_dedicated_assign() {
		$map = $this->isolated_map();

		$this->assertSame(
			array(
				'event_cat' => array( 'delete_event_cats', 'edit_event_cats', 'manage_event_cats' ),
				'event_tag' => array( 'delete_event_tags', 'edit_event_tags', 'manage_event_tags' ),
			),
			BLT_Toolkit_Simple_Mapper::toggles( $map )
		);
		$this->assertSame( array( 'assign_event_cats', 'assign_event_tags' ), BLT_Toolkit_Simple_Mapper::assign_caps( $map ) );

		$contribute = BLT_Toolkit_Simple_Mapper::caps_for(
			$map,
			'contribute',
			array(
				'can_delete' => false,
				'manage'     => array( 'event_tag' => true ),
			)
		);

		$this->assertSame(
			array( 'assign_event_cats', 'assign_event_tags', 'create_events', 'delete_event_tags', 'edit_event_tags', 'edit_events', 'manage_event_tags' ),
			$contribute
		);
		$this->assertNotContains( 'assign_event_cats', BLT_Toolkit_Simple_Mapper::caps_for( $map, 'view' ) );

		$universe = BLT_Toolkit_Simple_Mapper::universe( $map );

		foreach ( array( 'manage_categories', 'edit_categories', 'delete_categories', 'assign_categories' ) as $cap ) {
			$this->assertNotContains( $cap, $universe, 'Default taxonomies are controlled site-wide (Appendix A).' );
		}
	}

	/**
	 * SPEC 10.3: assign_terms is granted automatically only when it is the
	 * taxonomy's own: an entry may say `assign_dedicated => false` (for
	 * example `edit_posts` on a type that is not Posts), and an assign_terms
	 * equal to the taxonomy's manage string belongs to the toggle.
	 *
	 * @return void
	 */
	public function test_spec_10_3_assign_terms_must_be_dedicated() {
		$map = $this->isolated_map();

		$map['taxonomies']['event_cat']['assign_terms']     = 'edit_posts';
		$map['taxonomies']['event_cat']['assign_dedicated'] = false;
		$map['taxonomies']['event_tag']['assign_terms']     = 'manage_event_tags';

		$this->assertSame( array(), BLT_Toolkit_Simple_Mapper::assign_caps( $map ) );
		$this->assertNotContains( 'edit_posts', BLT_Toolkit_Simple_Mapper::universe( $map ) );
		$this->assertNotContains( 'manage_event_tags', BLT_Toolkit_Simple_Mapper::caps_for( $map, 'contribute' ) );

		// The book fixture's assign_terms is the type's own edit_books: it
		// collapses into the level's set.
		$books = $this->own_type_map();

		$this->assertSame( array( 'edit_books' ), BLT_Toolkit_Simple_Mapper::assign_caps( $books ) );
		$this->assertSame( array( 'edit_books' ), BLT_Toolkit_Simple_Mapper::caps_for( $books, 'contribute', array( 'can_delete' => false ) ) );
	}

	// ---------- RS-4, Appendix A: multi-capability toggles ----------

	/**
	 * RS-4, SPEC 10.6: a toggle over several capabilities is on with all of
	 * them, off with none, indeterminate with some. Explicit denies
	 * (`false`) are not granted; duplicates count once.
	 *
	 * @return void
	 */
	public function test_rs4_toggle_state_for_multi_capability_toggles() {
		$plugins = array( 'install_plugins', 'update_plugins', 'delete_plugins' );
		$roles   = $this->default_roles();

		$this->assertSame( 'on', BLT_Toolkit_Simple_Mapper::toggle_state( $plugins, $roles['administrator'] ) );
		$this->assertSame( 'off', BLT_Toolkit_Simple_Mapper::toggle_state( $plugins, $roles['editor'] ) );
		$this->assertSame( 'indeterminate', BLT_Toolkit_Simple_Mapper::toggle_state( $plugins, array( 'update_plugins' => true ) ) );
		$this->assertSame(
			'indeterminate',
			BLT_Toolkit_Simple_Mapper::toggle_state(
				$plugins,
				array(
					'install_plugins' => true,
					'update_plugins'  => true,
					'delete_plugins'  => false,
				)
			)
		);
		$this->assertSame( 'on', BLT_Toolkit_Simple_Mapper::toggle_state( array( 'delete_users', 'remove_users', 'delete_users' ), array( 'delete_users', 'remove_users' ) ) );
		$this->assertSame( 'indeterminate', BLT_Toolkit_Simple_Mapper::toggle_state( array( 'delete_users', 'remove_users' ), array( 'remove_users' ) ) );
		$this->assertSame( 'on', BLT_Toolkit_Simple_Mapper::toggle_state( array( 'read' ), $roles['subscriber'] ) );
		$this->assertSame( 'off', BLT_Toolkit_Simple_Mapper::toggle_state( array( 'read' ), array( 'read' => false ) ) );
		$this->assertSame( 'off', BLT_Toolkit_Simple_Mapper::toggle_state( array(), $roles['administrator'] ) );
	}

	// ---------- RS-11: types excluded from levels ----------

	/**
	 * RS-11: Posts, Pages, a CPT sharing Posts' strings, an isolated CPT, a
	 * CPT with its own type and a map_meta_cap false CPT can have levels;
	 * an ACF-style CPT (edit_posts is manage_options) is excluded as
	 * administrative; a CPT using another type's strings is excluded as
	 * foreign.
	 *
	 * @return void
	 */
	public function test_rs11_excluded_reason() {
		$posts = $this->posts_map();
		$pages = $this->pages_map();

		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( $posts, $posts, $pages, 'post' ) );
		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( $pages, $posts, $pages, 'page' ) );
		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( array( 'caps' => $this->cap_object( 'post', 'posts' ) ), $posts, $pages, 'post' ), 'A CPT with capability_type post shares Posts exactly.' );
		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( $this->isolated_map(), $posts, $pages, array( 'event', 'events' ) ) );
		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( $this->own_type_map(), $posts, $pages, 'book' ) );
		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( $this->meta_false_map(), $posts, $pages, 'ledger' ) );

		$this->assertSame( 'admin_caps', BLT_Toolkit_Simple_Mapper::excluded_reason( $this->acf_map(), $posts, $pages, 'post' ) );
		$this->assertSame(
			array(
				'edit_post'    => 'manage_options',
				'delete_post'  => 'manage_options',
				'edit_posts'   => 'manage_options',
				'delete_posts' => 'manage_options',
				'create_posts' => 'manage_options',
			),
			BLT_Toolkit_Simple_Mapper::foreign_caps( $this->acf_map(), $posts, $pages, 'post' )
		);

		$foreign = array( 'caps' => $this->cap_object( 'event', 'events', true, array( 'edit_others_posts' => 'edit_others_products' ) ) );

		$this->assertSame( 'foreign_caps', BLT_Toolkit_Simple_Mapper::excluded_reason( $foreign, $posts, $pages, 'event' ) );
		$this->assertSame( array( 'edit_others_posts' => 'edit_others_products' ), BLT_Toolkit_Simple_Mapper::foreign_caps( $foreign, $posts, $pages, 'event' ) );

		$mixed = array( 'caps' => $this->cap_object( 'event', 'events', true, array( 'read_private_posts' => 'read_private_pages' ) ) );

		$this->assertSame( '', BLT_Toolkit_Simple_Mapper::excluded_reason( $mixed, $posts, $pages, 'event' ), 'A string shared with Pages is allowed.' );
	}

	// ---------- Inputs ----------

	/**
	 * Granted capabilities may be name => value (granted when not empty, as
	 * WP_User::has_cap() reads them) or a list of names; an explicit deny
	 * inside the target set is added, which flips it.
	 *
	 * @return void
	 */
	public function test_granted_formats_and_denies() {
		$posts = $this->posts_map();

		$this->assertSame(
			array(
				'edit_posts'   => true,
				'delete_posts' => true,
			),
			BLT_Toolkit_Simple_Mapper::granted_set(
				array(
					'edit_posts'    => true,
					'publish_posts' => false,
					'delete_posts'  => 1,
					'edit_pages'    => 0,
					'read'          => '',
				)
			)
		);
		$this->assertSame(
			BLT_Toolkit_Simple_Mapper::detect( $posts, array( 'edit_posts', 'delete_posts' ) ),
			BLT_Toolkit_Simple_Mapper::detect(
				$posts,
				array(
					'edit_posts'   => true,
					'delete_posts' => true,
				)
			)
		);
		$this->assertSame(
			$this->state( 'contribute', true ),
			BLT_Toolkit_Simple_Mapper::detect(
				$posts,
				array(
					'edit_posts'    => true,
					'delete_posts'  => true,
					'publish_posts' => false,
				)
			),
			'A deny is not a grant.'
		);
		$this->assertSame(
			array(
				'add'    => array( 'edit_published_posts', 'publish_posts' ),
				'remove' => array( 'delete_posts' ),
			),
			BLT_Toolkit_Simple_Mapper::apply(
				$posts,
				array(
					'edit_posts'    => true,
					'delete_posts'  => true,
					'publish_posts' => false,
				),
				'author',
				array( 'can_delete' => false )
			),
			'The deny on publish_posts is lifted by adding it; Can delete off removes delete_posts.'
		);
	}

	/**
	 * The universe never holds `read`, `exist` or `do_not_allow`, so a level
	 * change can never take dashboard access away.
	 *
	 * @return void
	 */
	public function test_never_strings_are_outside_every_row() {
		$map = array(
			'caps' => $this->cap_object(
				'odd',
				'odds',
				true,
				array(
					'read_private_posts'  => 'read',
					'edit_others_posts'   => 'do_not_allow',
					'delete_others_posts' => 'exist',
				)
			),
		);

		$universe = BLT_Toolkit_Simple_Mapper::universe( $map );

		$this->assertNotContains( 'read', $universe );
		$this->assertNotContains( 'do_not_allow', $universe );
		$this->assertNotContains( 'exist', $universe );
		$this->assertNotContains( 'read', BLT_Toolkit_Simple_Mapper::apply( $map, array( 'read' => true ), 'none' )['remove'] );
		$this->assertSame( 'none', BLT_Toolkit_Simple_Mapper::detect( $map, array( 'read' => true ) )['level'] );
	}

	/**
	 * RS-13: the marker name is `blt_readonly_{plural}`.
	 *
	 * @return void
	 */
	public function test_rs13_marker_name() {
		$this->assertSame( 'blt_readonly_events', BLT_Toolkit_Simple_Mapper::marker_name( 'events' ) );
		$this->assertSame( BLT_Toolkit_Capability_Catalog::MARKER_PREFIX, BLT_Toolkit_Simple_Mapper::MARKER_PREFIX );
		// Read-only enforcement, isolation and the Guard's comparisons
		// (Superset) spell it out too, so none of them loads the catalog on
		// a request (SEC-9); all five must agree.
		$this->assertSame( BLT_Toolkit_Simple_Mapper::MARKER_PREFIX, BLT_Toolkit_Readonly::MARKER_PREFIX );
		$this->assertSame( BLT_Toolkit_Simple_Mapper::MARKER_PREFIX, BLT_Toolkit_Isolation::MARKER_PREFIX );
		$this->assertSame( BLT_Toolkit_Simple_Mapper::MARKER_PREFIX, BLT_Toolkit_Superset::MARKER_PREFIX );
	}
}
