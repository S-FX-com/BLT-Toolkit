<?php
/**
 * Separate permissions for a post type: the pure rules (RS-30 to RS-33).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Isolation.
 */
class Test_Isolation extends TestCase {

	/**
	 * Fresh shimmed state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * Posts' cap object, as WordPress 7.1 builds it.
	 *
	 * @return array
	 */
	private function posts_cap() {
		return array(
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
		);
	}

	/**
	 * Pages' cap object.
	 *
	 * @return array
	 */
	private function pages_cap() {
		return array(
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
		);
	}

	/**
	 * The cap object WordPress 7.1 builds for an isolated `event` type
	 * (`get_post_type_capabilities()` with the RS-31 arguments).
	 *
	 * @return array
	 */
	private function events_cap() {
		return array(
			'edit_post'              => 'edit_event',
			'read_post'              => 'read_event',
			'delete_post'            => 'delete_event',
			'edit_posts'             => 'edit_events',
			'edit_others_posts'      => 'edit_others_events',
			'delete_posts'           => 'delete_events',
			'publish_posts'          => 'publish_events',
			'read_private_posts'     => 'read_private_events',
			'read'                   => 'read',
			'delete_private_posts'   => 'delete_private_events',
			'delete_published_posts' => 'delete_published_events',
			'delete_others_posts'    => 'delete_others_events',
			'edit_private_posts'     => 'edit_private_events',
			'edit_published_posts'   => 'edit_published_events',
			'create_posts'           => 'create_events',
		);
	}

	/**
	 * WordPress's default roles' content capabilities (7.1).
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function default_roles() {
		$grant = static function ( array $caps ) {
			return array_fill_keys( $caps, true );
		};

		return array(
			'administrator' => $grant( array( 'read', 'manage_options', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'edit_private_pages', 'read_private_pages' ) ),
			'editor'        => $grant( array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages', 'edit_private_pages', 'read_private_pages' ) ),
			'author'        => $grant( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts', 'upload_files' ) ),
			'contributor'   => $grant( array( 'read', 'edit_posts', 'delete_posts' ) ),
			'subscriber'    => $grant( array( 'read' ) ),
		);
	}

	/**
	 * Store isolated types in the shimmed settings option.
	 *
	 * @param array $isolated Type => entry.
	 * @return void
	 */
	private function isolate( array $isolated ) {
		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array( 'roles' => array( 'isolated_post_types' => $isolated ) );

		BLT_Toolkit_Settings::flush();
	}

	// ---------- RS-31 ----------

	/**
	 * RS-31: the arguments set for an isolated type.
	 *
	 * @return void
	 */
	public function test_rs31_isolation_args() {
		$this->assertSame(
			array(
				'capability_type' => array( 'event', 'events' ),
				'map_meta_cap'    => true,
				'capabilities'    => array( 'create_posts' => 'create_events' ),
			),
			BLT_Toolkit_Isolation::isolation_args( 'event', 'events' )
		);
	}

	/**
	 * RS-31: with nothing isolated the filter hands back exactly what it was
	 * given, and with another type isolated, this one is untouched too.
	 *
	 * @return void
	 */
	public function test_rs31_filter_returns_input_when_not_isolated() {
		$args = array(
			'public'          => true,
			'capability_type' => 'post',
		);

		$this->assertSame( $args, BLT_Toolkit_Isolation::filter_args( $args, 'event' ) );
		$this->assertSame( 'not an array', BLT_Toolkit_Isolation::filter_args( 'not an array', 'event' ) );

		$this->isolate(
			array(
				'book' => array(
					'singular' => 'book',
					'plural'   => 'books',
					'since'    => 1,
				),
			)
		);

		$this->assertSame( $args, BLT_Toolkit_Isolation::filter_args( $args, 'event' ) );
	}

	/**
	 * RS-31: an isolated type gets its own capability type, map_meta_cap
	 * and a distinct create capability; its other arguments are kept, and
	 * any `capabilities` override it had is replaced.
	 *
	 * @return void
	 */
	public function test_rs31_filter_applies_to_isolated_type() {
		$this->isolate(
			array(
				'event' => array(
					'singular' => 'event',
					'plural'   => 'events',
					'since'    => 1700000000,
				),
			)
		);

		$out = BLT_Toolkit_Isolation::filter_args(
			array(
				'public'       => true,
				'labels'       => array( 'name' => 'Events' ),
				'capabilities' => array( 'edit_posts' => 'edit_posts' ),
			),
			'event'
		);

		$this->assertSame( true, $out['public'] );
		$this->assertSame( array( 'name' => 'Events' ), $out['labels'] );
		$this->assertSame( array( 'event', 'events' ), $out['capability_type'] );
		$this->assertTrue( $out['map_meta_cap'] );
		$this->assertSame( array( 'create_posts' => 'create_events' ), $out['capabilities'] );
		$this->assertSame( 'post', BLT_Toolkit_Isolation::shares( 'event' ) );

		// The cap object WordPress builds from that is the isolated one.
		$this->assertEquals( $this->events_cap(), BLT_Toolkit_Isolation::caps_from_args( $out ) );
	}

	/**
	 * RS-31 and RS-33: a type whose plugin since gave it a capability type
	 * of its own is left alone (verification reports it), and a Pages type
	 * is recorded as sharing Pages.
	 *
	 * @return void
	 */
	public function test_rs31_filter_skips_type_with_own_capability_type() {
		$entry = array(
			'singular' => 'event',
			'plural'   => 'events',
			'since'    => 1,
		);

		$this->isolate(
			array(
				'event' => $entry,
				'doc'   => array(
					'singular' => 'doc',
					'plural'   => 'docs',
					'since'    => 1,
				),
			)
		);

		$own = array( 'capability_type' => 'tribe_event' );

		$this->assertSame( $own, BLT_Toolkit_Isolation::filter_args( $own, 'event' ) );

		$out = BLT_Toolkit_Isolation::filter_args( array( 'capability_type' => 'page' ), 'doc' );

		$this->assertSame( array( 'doc', 'docs' ), $out['capability_type'] );
		$this->assertSame( 'page', BLT_Toolkit_Isolation::shares( 'doc' ) );
	}

	/**
	 * RS-31: `blt_toolkit_isolation_caps` adjusts the arguments and receives
	 * the type and its stored entry.
	 *
	 * @return void
	 */
	public function test_rs31_isolation_caps_filter() {
		$entry = array(
			'singular' => 'event',
			'plural'   => 'events',
			'since'    => 5,
		);
		$seen  = array();

		$this->isolate( array( 'event' => $entry ) );

		add_filter(
			'blt_toolkit_isolation_caps',
			static function ( $args, $post_type, $info ) use ( &$seen ) {
				$seen                                       = array( $post_type, $info );
				$args['capabilities']['read_private_posts'] = 'read';

				return $args;
			}
		);

		$out = BLT_Toolkit_Isolation::filter_args( array(), 'event' );

		$this->assertSame( array( 'event', $entry ), $seen );
		$this->assertSame( 'read', $out['capabilities']['read_private_posts'] );
		$this->assertSame( 'create_events', $out['capabilities']['create_posts'] );
	}

	/**
	 * RS-31: which registration arguments still mean "shares Posts or
	 * Pages".
	 *
	 * @return void
	 */
	public function test_rs31_applies_to() {
		$this->assertSame( 'post', BLT_Toolkit_Isolation::applies_to( array() ) );
		$this->assertSame( 'post', BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => 'post' ) ) );
		$this->assertSame( 'page', BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => 'page' ) ) );
		$this->assertSame( 'post', BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => array( 'post', 'posts' ) ) ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => 'product' ) ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => array( 'event', 'events' ) ) ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::applies_to( array( 'capability_type' => null ) ) );
	}

	// ---------- RS-30: candidates and names ----------

	/**
	 * RS-30: the candidate rule.
	 *
	 * @return void
	 */
	public function test_rs30_candidate_rule() {
		$posts = $this->posts_cap();
		$pages = $this->pages_cap();
		$type  = static function ( $name, $base, array $cap, $builtin = false ) {
			return array(
				'name'            => $name,
				'builtin'         => $builtin,
				'capability_type' => $base,
				'cap'             => $cap,
			);
		};

		// A CPT with capability_type post and no overrides.
		$this->assertSame( '', BLT_Toolkit_Isolation::candidate_reason( $type( 'event', 'post', $posts ), $posts, $pages, array() ) );
		// A Pages-like CPT.
		$this->assertSame( '', BLT_Toolkit_Isolation::candidate_reason( $type( 'doc', 'page', $pages ), $posts, $pages, array() ) );
		// Key order does not matter.
		$this->assertSame( '', BLT_Toolkit_Isolation::candidate_reason( $type( 'event', 'post', array_reverse( $posts, true ) ), $posts, $pages, array() ) );
		// Core types never.
		$this->assertSame( 'core', BLT_Toolkit_Isolation::candidate_reason( $type( 'post', 'post', $posts, true ), $posts, $pages, array() ) );
		$this->assertSame( 'core', BLT_Toolkit_Isolation::candidate_reason( $type( 'wp_block', 'post', $posts, true ), $posts, $pages, array() ) );
		// RS-35: excluded by the filter's list.
		$this->assertSame( 'excluded', BLT_Toolkit_Isolation::candidate_reason( $type( 'bricks_template', 'post', $posts ), $posts, $pages, array( 'bricks_template' ) ) );
		// Its own capability type (WooCommerce products, an isolated type).
		$this->assertSame( 'own_type', BLT_Toolkit_Isolation::candidate_reason( $type( 'product', 'product', $posts ), $posts, $pages, array() ) );
		$this->assertSame( 'own_type', BLT_Toolkit_Isolation::candidate_reason( $type( 'event', 'event', $this->events_cap() ), $posts, $pages, array() ) );
		// Post type with a capabilities override (ACF style).
		$acf               = $posts;
		$acf['edit_posts'] = 'manage_options';
		$this->assertSame( 'overrides', BLT_Toolkit_Isolation::candidate_reason( $type( 'acf-field-group', 'post', $acf ), $posts, $pages, array() ) );
		// map_meta_cap false: the mapping keys are missing.
		$plain = array_diff_key( $posts, array_flip( array( 'read', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts' ) ) );
		$this->assertSame( 'overrides', BLT_Toolkit_Isolation::candidate_reason( $type( 'legacy', 'post', $plain ), $posts, $pages, array() ) );
		// A post-type CPT compared with Posts, not Pages.
		$this->assertSame( 'overrides', BLT_Toolkit_Isolation::candidate_reason( $type( 'odd', 'post', $pages ), $posts, $pages, array() ) );
	}

	/**
	 * RS-35: Bricks templates are excluded by default; the filter can change
	 * the list.
	 *
	 * @return void
	 */
	public function test_rs35_default_exclusions() {
		$this->assertSame( array( 'bricks_template' ), BLT_Toolkit_Isolation::excluded_types() );

		add_filter(
			'blt_toolkit_isolation_excluded_types',
			static function ( $types ) {
				$types[] = 'elementor_library';

				return $types;
			}
		);

		$this->assertSame( array( 'bricks_template', 'elementor_library' ), BLT_Toolkit_Isolation::excluded_types() );
	}

	/**
	 * RS-30 step 1: default names.
	 *
	 * @return void
	 */
	public function test_rs30_default_names() {
		$this->assertSame(
			array(
				'singular' => 'event',
				'plural'   => 'events',
			),
			BLT_Toolkit_Isolation::default_names( 'event' )
		);
		$this->assertSame( 'my-books', BLT_Toolkit_Isolation::default_names( 'my-book' )['plural'] );
	}

	/**
	 * RS-30 step 1: name validation.
	 *
	 * @return void
	 */
	public function test_rs30_name_rules() {
		$this->assertSame( '', BLT_Toolkit_Isolation::name_error( 'event', 'events' ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::name_error( 'my-event', 'my_events2' ) );
		$this->assertSame( 'singular_invalid', BLT_Toolkit_Isolation::name_error( '', 'events' ) );
		$this->assertSame( 'singular_invalid', BLT_Toolkit_Isolation::name_error( 'Event', 'events' ) );
		$this->assertSame( 'singular_invalid', BLT_Toolkit_Isolation::name_error( '123', 'events' ) );
		$this->assertSame( 'singular_invalid', BLT_Toolkit_Isolation::name_error( 'ev ent', 'events' ) );
		$this->assertSame( 'plural_invalid', BLT_Toolkit_Isolation::name_error( 'event', 'ev"ents' ) );
		$this->assertSame( 'plural_invalid', BLT_Toolkit_Isolation::name_error( 'event', str_repeat( 'e', 61 ) ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::name_error( 'event', str_repeat( 'e', 60 ) ) );
		$this->assertSame( 'same', BLT_Toolkit_Isolation::name_error( 'news', 'news' ) );
		$this->assertSame( 'singular_invalid', BLT_Toolkit_Isolation::name_error( array( 'event' ), 'events' ) );
	}

	/**
	 * RS-30 step 1: collisions with strings in use elsewhere, and inside the
	 * new set itself.
	 *
	 * @return void
	 */
	public function test_rs30_collision_validation() {
		$taken = array_fill_keys( array_values( $this->posts_cap() ), true ) + array_fill_keys( array_values( $this->pages_cap() ), true );

		unset( $taken['read'] );

		$events = BLT_Toolkit_Isolation::caps_from_args( BLT_Toolkit_Isolation::isolation_args( 'event', 'events' ) );

		$this->assertSame( array(), BLT_Toolkit_Isolation::collisions( $events, $taken, 'events' ) );

		// Plural `posts`: every primitive is Posts'.
		$posts_plural = BLT_Toolkit_Isolation::caps_from_args( BLT_Toolkit_Isolation::isolation_args( 'event', 'posts' ) );
		$this->assertContains( 'edit_posts', BLT_Toolkit_Isolation::collisions( $posts_plural, $taken, 'posts' ) );
		$this->assertContains( 'publish_posts', BLT_Toolkit_Isolation::collisions( $posts_plural, $taken, 'posts' ) );
		$this->assertNotContains( 'create_posts', BLT_Toolkit_Isolation::collisions( $posts_plural, $taken, 'posts' ) );

		// Singular `post`: the per-item names are Posts'.
		$post_singular = BLT_Toolkit_Isolation::caps_from_args( BLT_Toolkit_Isolation::isolation_args( 'post', 'events' ) );
		$this->assertSame( array( 'delete_post', 'edit_post', 'read_post' ), BLT_Toolkit_Isolation::collisions( $post_singular, $taken, 'events' ) );

		// Another type's strings, by its own plural.
		$taken['edit_events'] = true;
		$this->assertSame( array( 'edit_events' ), BLT_Toolkit_Isolation::collisions( $events, $taken, 'events' ) );

		// A per-item name equal to a primitive of the same set.
		$odd = BLT_Toolkit_Isolation::caps_from_args( BLT_Toolkit_Isolation::isolation_args( 'published_events', 'events' ) );
		$this->assertSame( array( 'delete_published_events', 'edit_published_events' ), BLT_Toolkit_Isolation::collisions( $odd, array(), 'events' ) );

		// The marker counts too.
		$this->assertSame( array( 'blt_readonly_events' ), BLT_Toolkit_Isolation::collisions( $events, array( 'blt_readonly_events' ), 'events' ) );
	}

	/**
	 * RS-30: caps_from_args() matches get_post_type_capabilities() for
	 * Posts, Pages and the isolated arguments (vectors captured from
	 * WordPress 7.1).
	 *
	 * @return void
	 */
	public function test_rs30_caps_from_args_matches_core() {
		$this->assertEquals( $this->posts_cap(), BLT_Toolkit_Isolation::caps_from_args( array( 'map_meta_cap' => true ) ) );
		$this->assertEquals(
			$this->pages_cap(),
			BLT_Toolkit_Isolation::caps_from_args(
				array(
					'capability_type' => 'page',
					'map_meta_cap'    => true,
				)
			)
		);
		$this->assertEquals( $this->events_cap(), BLT_Toolkit_Isolation::caps_from_args( BLT_Toolkit_Isolation::isolation_args( 'event', 'events' ) ) );

		$plain = BLT_Toolkit_Isolation::caps_from_args( array( 'capability_type' => 'book' ) );

		$this->assertSame( 'edit_books', $plain['create_posts'] );
		$this->assertArrayNotHasKey( 'read', $plain );
		$this->assertArrayNotHasKey( 'edit_published_posts', $plain );
	}

	/**
	 * RS-30: the full list of new strings, marker included, without `read`.
	 *
	 * @return void
	 */
	public function test_rs30_new_strings() {
		$this->assertSame(
			array(
				'blt_readonly_events',
				'create_events',
				'delete_event',
				'delete_events',
				'delete_others_events',
				'delete_private_events',
				'delete_published_events',
				'edit_event',
				'edit_events',
				'edit_others_events',
				'edit_private_events',
				'edit_published_events',
				'publish_events',
				'read_event',
				'read_private_events',
			),
			BLT_Toolkit_Isolation::new_strings( $this->events_cap(), 'events' )
		);
	}

	// ---------- RS-30: the seeding map ----------

	/**
	 * RS-30 step 2: every `*_posts` maps to `*_{plural}`, and `edit_posts`
	 * also to `create_{plural}`.
	 *
	 * @return void
	 */
	public function test_rs30_seeding_map_posts() {
		$this->assertSame(
			array(
				'delete_others_posts'    => array( 'delete_others_events' ),
				'delete_posts'           => array( 'delete_events' ),
				'delete_private_posts'   => array( 'delete_private_events' ),
				'delete_published_posts' => array( 'delete_published_events' ),
				'edit_others_posts'      => array( 'edit_others_events' ),
				'edit_posts'             => array( 'edit_events', 'create_events' ),
				'edit_private_posts'     => array( 'edit_private_events' ),
				'edit_published_posts'   => array( 'edit_published_events' ),
				'publish_posts'          => array( 'publish_events' ),
				'read_private_posts'     => array( 'read_private_events' ),
			),
			BLT_Toolkit_Isolation::seed_map( $this->posts_cap(), $this->events_cap() )
		);
	}

	/**
	 * RS-30 step 2 for a type sharing Pages: `*_pages` maps the same way.
	 *
	 * @return void
	 */
	public function test_rs30_seeding_map_pages() {
		$docs = BLT_Toolkit_Isolation::caps_from_args( BLT_Toolkit_Isolation::isolation_args( 'doc', 'docs' ) );
		$map  = BLT_Toolkit_Isolation::seed_map( $this->pages_cap(), $docs );

		$this->assertSame( array( 'edit_docs', 'create_docs' ), $map['edit_pages'] );
		$this->assertSame( array( 'publish_docs' ), $map['publish_pages'] );
		$this->assertCount( 10, $map );
		$this->assertArrayNotHasKey( 'read', $map );
	}

	/**
	 * RS-30 step 2: an Author receives exactly the matching capabilities.
	 *
	 * @return void
	 */
	public function test_rs30_seed_changes_author() {
		$roles  = $this->default_roles();
		$change = BLT_Toolkit_Isolation::seed_changes( $roles['author'], BLT_Toolkit_Isolation::seed_map( $this->posts_cap(), $this->events_cap() ) );

		$this->assertSame(
			array(
				'create_events'           => true,
				'delete_events'           => true,
				'delete_published_events' => true,
				'edit_events'             => true,
				'edit_published_events'   => true,
				'publish_events'          => true,
			),
			$change['add']
		);
		$this->assertSame( array(), $change['remove'] );
		$this->assertSame( 'edit_posts', $change['from']['create_events'] );
		$this->assertSame( 'publish_posts', $change['from']['publish_events'] );
	}

	/**
	 * RS-30 step 2: a deny maps to a deny, a stale new capability with no
	 * old one is removed, one already right is left alone, and nothing
	 * outside the map is touched.
	 *
	 * @return void
	 */
	public function test_rs30_seed_changes_denies_and_stale() {
		$map  = BLT_Toolkit_Isolation::seed_map( $this->posts_cap(), $this->events_cap() );
		$caps = array(
			'read'                => true,
			'edit_posts'          => false,
			'publish_posts'       => true,
			'publish_events'      => true,
			'edit_others_events'  => true,
			'read_private_events' => false,
			'some_plugin_cap'     => true,
		);

		$change = BLT_Toolkit_Isolation::seed_changes( $caps, $map );

		$this->assertSame(
			array(
				'create_events' => false,
				'edit_events'   => false,
			),
			$change['add']
		);
		$this->assertSame( array( 'edit_others_events', 'read_private_events' ), $change['remove'] );
	}

	/**
	 * RS-30, SPEC 17 Phase 5 criterion d: after seeding, every default role
	 * holds each isolated capability exactly when it held the Posts
	 * capability it replaces, so every check WordPress maps to them answers
	 * the same; Subscriber needs no change.
	 *
	 * @return void
	 */
	public function test_rs30_effective_access_unchanged_for_default_roles() {
		$old   = $this->posts_cap();
		$new   = $this->events_cap();
		$map   = BLT_Toolkit_Isolation::seed_map( $old, $new );
		$roles = array();

		foreach ( $this->default_roles() as $id => $caps ) {
			$roles[ $id ] = array(
				'name'         => ucfirst( $id ),
				'capabilities' => $caps,
			);
		}

		$seed = BLT_Toolkit_Isolation::role_seed( $roles, $map );

		$this->assertSame( array( 'administrator', 'author', 'contributor', 'editor' ), array_keys( $seed ) );

		foreach ( $roles as $id => $role ) {
			$after = $role['capabilities'];

			if ( isset( $seed[ $id ] ) ) {
				foreach ( $seed[ $id ]['add'] as $cap => $value ) {
					$after[ $cap ] = $value;
				}

				foreach ( $seed[ $id ]['remove'] as $cap ) {
					unset( $after[ $cap ] );
				}
			}

			foreach ( BLT_Toolkit_Isolation::LEVEL_KEYS as $key ) {
				$this->assertSame( ! empty( $role['capabilities'][ $old[ $key ] ] ), ! empty( $after[ $new[ $key ] ] ), "$id: $key" );
			}

			// Posts' own capabilities are never touched.
			foreach ( array_values( $old ) as $string ) {
				$this->assertSame( isset( $role['capabilities'][ $string ] ), isset( $after[ $string ] ), "$id keeps $string" );
			}

			$diff = BLT_Toolkit_Isolation::access_diff( $after, $old, $new );

			$this->assertSame(
				array(
					'gains' => array(),
					'loses' => array(),
				),
				$diff,
				"$id: reverting right after isolating changes nothing"
			);
		}
	}

	// ---------- RS-32 ----------

	/**
	 * RS-32: what reverting changes for a role whose Events level differs
	 * from its Posts level.
	 *
	 * @return void
	 */
	public function test_rs32_access_diff() {
		$caps = array(
			'read'                => true,
			'edit_posts'          => true,
			'publish_posts'       => true,
			'edit_events'         => true,
			'read_private_events' => true,
			'create_events'       => false,
		);

		// Gains: publish, and create (Posts' create is edit_posts, while
		// create_events is denied here). Loses: reading private Events.
		$this->assertSame(
			array(
				'gains' => array( 'edit_posts', 'publish_posts' ),
				'loses' => array( 'read_private_events' ),
			),
			BLT_Toolkit_Isolation::access_diff( $caps, $this->posts_cap(), $this->events_cap() )
		);
	}

	/**
	 * RS-32, RO-11: cleanup removes only strings nothing registers.
	 *
	 * @return void
	 */
	public function test_rs32_cleanup_strings_only_orphans() {
		$all = BLT_Toolkit_Isolation::new_strings( $this->events_cap(), 'events' );

		$this->assertSame( $all, BLT_Toolkit_Isolation::cleanup_strings( $this->events_cap(), 'events', array() ) );
		$this->assertSame(
			array_values( array_diff( $all, array( 'edit_events', 'publish_events' ) ) ),
			BLT_Toolkit_Isolation::cleanup_strings(
				$this->events_cap(),
				'events',
				array(
					'edit_events',
					'publish_events' => true,
				)
			)
		);
	}

	// ---------- RS-33 ----------

	/**
	 * RS-33: a registered isolated type must have `edit_{plural}`; a type
	 * that is not registered is not a failure.
	 *
	 * @return void
	 */
	public function test_rs33_verification_rule() {
		$isolated = array(
			'event' => array(
				'singular' => 'event',
				'plural'   => 'events',
				'since'    => 1,
			),
			'doc'   => array(
				'singular' => 'doc',
				'plural'   => 'docs',
				'since'    => 1,
			),
			'gone'  => array(
				'singular' => 'gone',
				'plural'   => 'gones',
				'since'    => 1,
			),
		);

		$this->assertSame(
			array(
				'doc' => array(
					'expected' => 'edit_docs',
					'found'    => 'edit_pages',
				),
			),
			BLT_Toolkit_Isolation::check(
				$isolated,
				array(
					'event' => $this->events_cap(),
					'doc'   => $this->pages_cap(),
					'gone'  => null,
				)
			)
		);
	}

	/**
	 * RS-33, RS-14: `create_posts` must stay what isolation gave the type.
	 * A plugin that re-registers it with `capability_type => event` keeps
	 * `edit_events` but makes `create_posts` the same string, which turns
	 * read-only enforcement off; that is a failure. A `do_not_allow` kept
	 * from the registration is not.
	 *
	 * @return void
	 */
	public function test_rs33_create_posts_is_verified() {
		$isolated = array(
			'event' => array(
				'singular' => 'event',
				'plural'   => 'events',
				'since'    => 1,
			),
		);
		$own_type = BLT_Toolkit_Isolation::caps_from_args(
			array(
				'capability_type' => 'event',
				'map_meta_cap'    => true,
			)
		);

		$this->assertSame( 'edit_events', $own_type['edit_posts'] );
		$this->assertSame(
			array(
				'event' => array(
					'expected' => 'create_events',
					'found'    => 'edit_events',
				),
			),
			BLT_Toolkit_Isolation::check( $isolated, array( 'event' => $own_type ) )
		);

		$refused                 = $this->events_cap();
		$refused['create_posts'] = 'do_not_allow';

		$this->assertSame( array(), BLT_Toolkit_Isolation::check( $isolated, array( 'event' => $refused ) ) );

		$other                       = $this->events_cap();
		$other['read_private_posts'] = 'manage_options';

		$this->assertSame(
			array(
				'event' => array(
					'expected' => 'read_private_events',
					'found'    => 'manage_options',
				),
			),
			BLT_Toolkit_Isolation::check( $isolated, array( 'event' => $other ) )
		);

		// What isolation applied (blt_toolkit_isolation_caps) is the reference.
		$filtered                       = $this->events_cap();
		$filtered['read_private_posts'] = 'manage_options';

		$this->assertSame( array(), BLT_Toolkit_Isolation::check( $isolated, array( 'event' => $other ), array( 'event' => $filtered ) ) );
	}

	/**
	 * RS-31: a registration's `do_not_allow` overrides are kept when
	 * isolation applies (it never gives back what the plugin took away);
	 * other overrides of Posts' strings stop isolation from applying, and
	 * Posts' own strings are replaced as before.
	 *
	 * @return void
	 */
	public function test_rs31_filter_keeps_restrictive_overrides() {
		$this->isolate(
			array(
				'event' => array(
					'singular' => 'event',
					'plural'   => 'events',
					'since'    => 1,
				),
			)
		);

		$out = BLT_Toolkit_Isolation::filter_args( array( 'capabilities' => array( 'create_posts' => 'do_not_allow' ) ), 'event' );

		$this->assertSame( array( 'event', 'events' ), $out['capability_type'] );
		$this->assertSame( array( 'create_posts' => 'do_not_allow' ), $out['capabilities'] );
		$this->assertSame( 'do_not_allow', BLT_Toolkit_Isolation::caps_from_args( $out )['create_posts'] );

		$args = array( 'capabilities' => array( 'read_private_posts' => 'manage_options' ) );

		$this->assertSame( $args, BLT_Toolkit_Isolation::filter_args( $args, 'event' ) );
		$this->assertSame( 'overrides', BLT_Toolkit_Isolation::overrides_error( $args, 'post' ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::overrides_error( array( 'capabilities' => array( 'edit_posts' => 'edit_posts' ) ), 'post' ) );
		$this->assertSame( 'overrides', BLT_Toolkit_Isolation::overrides_error( array( 'capabilities' => array( 'edit_posts' => 'edit_posts' ) ), 'page' ) );
		$this->assertSame(
			array( 'delete_posts' => 'do_not_allow' ),
			BLT_Toolkit_Isolation::restrictive_overrides(
				array(
					'capabilities' => array(
						'delete_posts' => 'do_not_allow',
						'edit_posts'   => 'edit_posts',
					),
				)
			)
		);
	}

	/**
	 * RS-30, RS-31: an entry that could never have been isolated is never
	 * applied and is left out of isolated(): a core type, invalid names, or
	 * strings that are core's own (a plural of `pages`). A `_builtin`
	 * registration is left alone too.
	 *
	 * @return void
	 */
	public function test_rs31_damaged_entries_are_never_applied() {
		$entries = array(
			'post'        => array(
				'singular' => 'article',
				'plural'   => 'articles',
				'since'    => 1,
			),
			'testimonial' => array(
				'singular' => 'testimonial',
				'plural'   => 'pages',
				'since'    => 1,
			),
			'person'      => array(
				'singular' => 'user',
				'plural'   => 'users',
				'since'    => 1,
			),
			'promo'       => array(
				'singular' => 'Promo',
				'plural'   => 'promos',
				'since'    => 1,
			),
			'event'       => array(
				'singular' => 'event',
				'plural'   => 'events',
				'since'    => 1,
			),
		);

		$this->assertSame( 'core_type', BLT_Toolkit_Isolation::entry_error( 'post', $entries['post'] ) );
		$this->assertSame( 'reserved', BLT_Toolkit_Isolation::entry_error( 'testimonial', $entries['testimonial'] ) );
		$this->assertSame( 'reserved', BLT_Toolkit_Isolation::entry_error( 'person', $entries['person'] ) );
		$this->assertSame( 'singular_invalid', BLT_Toolkit_Isolation::entry_error( 'promo', $entries['promo'] ) );
		$this->assertSame( 'invalid', BLT_Toolkit_Isolation::entry_error( 'event', 'garbage' ) );
		$this->assertSame( '', BLT_Toolkit_Isolation::entry_error( 'event', $entries['event'] ) );

		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array( 'roles' => array( 'isolated_post_types' => $entries ) );
		BLT_Toolkit_Settings::flush();

		$this->assertSame( array( 'event' ), array_keys( BLT_Toolkit_Isolation::isolated() ) );

		foreach ( array( 'post', 'testimonial', 'person', 'promo' ) as $type ) {
			$args = array( 'capability_type' => 'post' );

			$this->assertSame( $args, BLT_Toolkit_Isolation::filter_args( $args, $type ), $type );
		}

		$builtin = array(
			'capability_type' => 'post',
			'_builtin'        => true,
		);

		$this->assertSame( $builtin, BLT_Toolkit_Isolation::filter_args( $builtin, 'event' ) );
	}

	/**
	 * Robustness: a damaged state option never breaks a screen; only
	 * well-formed entries are read (RS-32's cleanup reads `reverted`).
	 *
	 * @return void
	 */
	public function test_rs32_damaged_state_is_ignored() {
		$GLOBALS['blt_test_options'][ BLT_Toolkit_Isolation::STATE_OPTION ] = array(
			'failed'   => array(
				'event' => 'garbage',
				'book'  => array(
					'expected' => 'edit_books',
					'found'    => 'edit_posts',
					'since'    => '5',
				),
			),
			'reverted' => array(
				'testimonial' => 'garbage',
				'promo'       => array(
					'singular' => 'promo',
					'plural'   => 'promo',
				),
				'gig'         => array(
					'singular' => 'gig',
					'plural'   => 'gigs',
					'shares'   => 'nonsense',
				),
			),
			'shares'   => 'garbage',
		);

		$this->assertSame(
			array(
				'gig' => array(
					'singular' => 'gig',
					'plural'   => 'gigs',
					'shares'   => 'post',
					'label'    => 'gig',
					'reverted' => 0,
				),
			),
			BLT_Toolkit_Isolation::reverted()
		);
		$this->assertSame(
			array(
				'book' => array(
					'expected' => 'edit_books',
					'found'    => 'edit_posts',
					'since'    => 5,
				),
			),
			BLT_Toolkit_Isolation::stored_failures()
		);
	}

	// ---------- The plan's lock token ----------

	/**
	 * The plan hash (RO-2 for isolation) is stable for the same plan in any
	 * key order and changes with any role or user change.
	 *
	 * @return void
	 */
	public function test_plan_hash() {
		$plan = array(
			'type'     => 'event',
			'singular' => 'event',
			'plural'   => 'events',
			'roles'    => array(
				'editor' => array(
					'name'   => 'Editor',
					'add'    => array( 'edit_events' => true ),
					'remove' => array(),
				),
				'author' => array(
					'add'    => array( 'edit_events' => true ),
					'remove' => array(),
				),
			),
			'users'    => array(
				7 => array(
					'add'    => array( 'edit_events' => false ),
					'remove' => array(),
					'hash'   => 'abc',
				),
			),
		);

		$hash = BLT_Toolkit_Isolation::plan_hash( $plan );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );

		$reordered          = $plan;
		$reordered['roles'] = array_reverse( $plan['roles'], true );

		unset( $reordered['roles']['editor']['name'] );

		$this->assertSame( $hash, BLT_Toolkit_Isolation::plan_hash( $reordered ) );

		$changed = $plan;
		$changed['roles']['author']['add']['create_events'] = true;
		$this->assertNotSame( $hash, BLT_Toolkit_Isolation::plan_hash( $changed ) );

		$user_changed                     = $plan;
		$user_changed['users'][7]['hash'] = 'abd';
		$this->assertNotSame( $hash, BLT_Toolkit_Isolation::plan_hash( $user_changed ) );

		$renamed           = $plan;
		$renamed['plural'] = 'evts';
		$this->assertNotSame( $hash, BLT_Toolkit_Isolation::plan_hash( $renamed ) );
	}
}
