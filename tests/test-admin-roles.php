<?php
/**
 * Roles screen: the pure helpers of BLT_Toolkit_Admin_Roles (RO-1, RO-2,
 * RO-13, RO-22, RO-25, SEC-10, US-10).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for the Roles screen's form and matrix rules.
 */
class Test_Admin_Roles extends TestCase {

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A small catalog tree shaped like BLT_Toolkit_Capability_Catalog::tree().
	 *
	 * @return array[]
	 */
	private function tree() {
		$node = static function ( $id, $label, array $caps, array $children = array() ) {
			foreach ( $children as $child ) {
				$caps = array_merge( $caps, $child['caps'] );
			}

			$caps = array_values( array_unique( $caps ) );

			sort( $caps );

			return array(
				'id'       => $id,
				'label'    => $label,
				'caps'     => $caps,
				'count'    => count( $caps ),
				'children' => $children,
			);
		};

		$general    = $node( 'core-general', 'General', array( 'read', 'manage_options' ) );
		$posts      = $node( 'core-posts', 'Posts', array( 'edit_posts', 'publish_posts' ) );
		$deprecated = $node( 'core-deprecated', 'Deprecated', array( 'level_0', 'edit_files' ) );
		$content    = $node( 'content-post', 'Posts (also used by: Events)', array( 'edit_posts', 'publish_posts', 'read' ) );
		$orphaned   = $node( 'orphaned', 'Orphaned', array( 'old_cap' ) );

		return array(
			$node( 'all', 'All', array( 'read', 'manage_options', 'edit_posts', 'publish_posts', 'level_0', 'edit_files', 'old_cap', 'blt_edit_roles' ) ),
			$node( 'core', 'Core', array(), array( $general, $posts, $deprecated ) ),
			$node( 'content', 'Content types', array(), array( $content ) ),
			$node( 'taxonomies', 'Taxonomies', array() ),
			$node( 'blt', 'BLT Toolkit', array( 'blt_edit_roles' ) ),
			$orphaned,
		);
	}

	/**
	 * Catalog entries for the tree above.
	 *
	 * @return array
	 */
	private function entries() {
		$entry = static function ( $label, $deprecated = false ) {
			return array(
				'label'      => $label,
				'deprecated' => $deprecated,
			);
		};

		return array(
			'read'           => $entry( 'Sign in to the dashboard' ),
			'manage_options' => $entry( 'Change site settings' ),
			'edit_posts'     => $entry( 'Create and edit their own posts' ),
			'publish_posts'  => $entry( 'Publish posts' ),
			'level_0'        => $entry( 'User level 0 (deprecated)', true ),
			'edit_files'     => $entry( 'Edit files (unused)', true ),
			'old_cap'        => $entry( 'Old cap' ),
			'blt_edit_roles' => $entry( 'Edit roles (Simple view)' ),
		);
	}

	/**
	 * RO-1: the on-page set travels as one JSON list; anything else is an
	 * empty set (so nothing changes), and names are cleaned as the store
	 * cleans them.
	 *
	 * @return void
	 */
	public function test_ro1_on_page_set_is_a_json_list_or_nothing() {
		$this->assertSame( array( 'edit_posts', 'read' ), BLT_Toolkit_Admin_Roles::parse_on_page( '["edit_posts"," read ","edit_posts","",7,"do_not_allow"]' ) );
		$this->assertSame( array(), BLT_Toolkit_Admin_Roles::parse_on_page( '' ) );
		$this->assertSame( array(), BLT_Toolkit_Admin_Roles::parse_on_page( '{"a":"edit_posts"}' ) );
		$this->assertSame( array(), BLT_Toolkit_Admin_Roles::parse_on_page( 'edit_posts' ) );
		$this->assertSame( array(), BLT_Toolkit_Admin_Roles::parse_on_page( 'null' ) );
	}

	/**
	 * RO-13: a leaf group is one section; a parent group, and All, are one
	 * section per leaf in tree order, each capability once (so a form never
	 * has two boxes for one capability), empty leaves dropped.
	 *
	 * @return void
	 */
	public function test_ro13_sections_list_each_capability_once() {
		$tree = $this->tree();

		$this->assertSame(
			array(
				array(
					'id'    => 'core-posts',
					'label' => 'Posts',
					'caps'  => array( 'edit_posts', 'publish_posts' ),
				),
			),
			BLT_Toolkit_Admin_Roles::sections_for( $tree, 'core-posts' )
		);

		$core = BLT_Toolkit_Admin_Roles::sections_for( $tree, 'core' );

		$this->assertSame( array( 'core-general', 'core-posts', 'core-deprecated' ), array_column( $core, 'id' ) );

		$all  = BLT_Toolkit_Admin_Roles::sections_for( $tree, 'all' );
		$caps = array();

		foreach ( $all as $section ) {
			$caps = array_merge( $caps, $section['caps'] );
		}

		// Content's Posts group repeats core strings: it adds nothing to All.
		$this->assertSame( array( 'core-general', 'core-posts', 'core-deprecated', 'blt', 'orphaned' ), array_column( $all, 'id' ) );
		$this->assertSame( count( $caps ), count( array_unique( $caps ) ) );
		$this->assertCount( 8, $caps );

		// An unknown group falls back to All; an empty group is no section.
		$this->assertSame( $all, BLT_Toolkit_Admin_Roles::sections_for( $tree, 'nope' ) );
		$this->assertSame( array(), BLT_Toolkit_Admin_Roles::sections_for( $tree, 'taxonomies' ) );
	}

	/**
	 * RO-22: deprecated capabilities only when shown, the quick filter on
	 * label and raw name ignoring case, Granted only, and sorting by label
	 * or raw name.
	 *
	 * @return void
	 */
	public function test_ro22_toolbar_filters() {
		$sections = BLT_Toolkit_Admin_Roles::sections_for( $this->tree(), 'all' );
		$entries  = $this->entries();
		$ids      = static function ( array $out ) {
			$caps = array();

			foreach ( $out as $section ) {
				$caps = array_merge( $caps, $section['caps'] );
			}

			return $caps;
		};

		$plain = BLT_Toolkit_Admin_Roles::visible_sections( $sections, $entries, array( 'deprecated' => false ) );

		$this->assertNotContains( 'level_0', $ids( $plain ) );
		$this->assertNotContains( 'core-deprecated', array_column( $plain, 'id' ) );

		$with = BLT_Toolkit_Admin_Roles::visible_sections( $sections, $entries, array( 'deprecated' => true ) );

		$this->assertContains( 'level_0', $ids( $with ) );

		// Label match ("Publish") and raw-name match ("EDIT_POSTS"), any case.
		$this->assertSame( array( 'publish_posts' ), $ids( BLT_Toolkit_Admin_Roles::visible_sections( $sections, $entries, array( 'search' => 'publish' ) ) ) );
		$this->assertSame( array( 'edit_posts' ), $ids( BLT_Toolkit_Admin_Roles::visible_sections( $sections, $entries, array( 'search' => 'EDIT_POSTS' ) ) ) );

		$granted = BLT_Toolkit_Admin_Roles::visible_sections(
			$sections,
			$entries,
			array(
				'granted' => array(
					'read'       => true,
					'edit_posts' => true,
				),
			)
		);

		$this->assertSame( array( 'read', 'edit_posts' ), $ids( $granted ) );

		// Readable names sort by label; raw names by name.
		$general = BLT_Toolkit_Admin_Roles::sections_for( $this->tree(), 'core-general' );

		$this->assertSame( array( 'manage_options', 'read' ), $ids( BLT_Toolkit_Admin_Roles::visible_sections( $general, $entries, array( 'readable' => true ) ) ) );
		$this->assertSame( array( 'manage_options', 'read' ), $ids( BLT_Toolkit_Admin_Roles::visible_sections( $general, $entries, array( 'readable' => false ) ) ) );

		$posts = BLT_Toolkit_Admin_Roles::sections_for( $this->tree(), 'core-posts' );

		// "Create and edit…" before "Publish…" by label; same by raw name.
		$this->assertSame( array( 'edit_posts', 'publish_posts' ), $ids( BLT_Toolkit_Admin_Roles::visible_sections( $posts, $entries, array( 'readable' => true ) ) ) );

		// A capability the catalog does not know is never drawn.
		$this->assertSame( array(), BLT_Toolkit_Admin_Roles::visible_sections( array( array( 'id' => 'x', 'label' => 'X', 'caps' => array( 'ghost' ) ) ), $entries, array() ) );
	}

	/**
	 * RO-2: the role as the form was drawn, rebuilt from the role now and
	 * the diff from then to now, so the conflict page can show the person's
	 * own change.
	 *
	 * @return void
	 */
	public function test_ro2_revert_change_rebuilds_the_drawn_role() {
		$drawn = array(
			'read'          => true,
			'edit_posts'    => true,
			'publish_posts' => true,
			'upload_files'  => false,
		);
		$now   = array(
			'read'          => true,
			'publish_posts' => true,
			'upload_files'  => true,
			'export'        => true,
		);
		$diff  = BLT_Toolkit_Role_Diff::diff(
			array(
				'r' => array(
					'name'         => 'R',
					'capabilities' => $drawn,
				),
			),
			array(
				'r' => array(
					'name'         => 'R',
					'capabilities' => $now,
				),
			)
		);

		$rebuilt = BLT_Toolkit_Admin_Roles::revert_change( $now, $diff['changed']['r'] );

		ksort( $rebuilt );
		ksort( $drawn );

		$this->assertSame( $drawn, $rebuilt );

		// The person's change against the drawn copy: they unchecked
		// publish_posts and checked export (which someone else also granted).
		$attempt = BLT_Toolkit_Role_Store::compute_changes( $rebuilt, array( 'publish_posts', 'export' ), array( 'export' ) );

		$this->assertSame(
			array(
				'add'    => array( 'export' => true ),
				'remove' => array( 'publish_posts' ),
			),
			$attempt
		);
	}

	/**
	 * SEC-10: the typed confirmation ignores surrounding spaces and case,
	 * matches any accepted name, and never matches when empty.
	 *
	 * @return void
	 */
	public function test_sec10_typed_confirmation() {
		$this->assertTrue( BLT_Toolkit_Admin_Roles::confirms( '  author ', array( 'Author' ) ) );
		$this->assertTrue( BLT_Toolkit_Admin_Roles::confirms( 'Shop Manager', array( 'Gestionnaire', 'Shop Manager' ) ) );
		$this->assertFalse( BLT_Toolkit_Admin_Roles::confirms( 'Editor', array( 'Author' ) ) );
		$this->assertFalse( BLT_Toolkit_Admin_Roles::confirms( '', array( '' ) ) );
		$this->assertFalse( BLT_Toolkit_Admin_Roles::confirms( '   ', array( 'Author' ) ) );
		$this->assertFalse( BLT_Toolkit_Admin_Roles::confirms( 'Auth', array( 'Author' ) ) );
	}

	/**
	 * A form's `back` field keeps only the editor's own arguments, with safe
	 * values, and round-trips through state_args() (PRG back to the same
	 * view).
	 *
	 * @return void
	 */
	public function test_back_arguments_are_whitelisted() {
		$this->assertSame(
			array(
				'role'       => 'shop_managerscript',
				'view'       => 'advanced',
				'group'      => 'content-wp_block',
				's'          => 'edit  posts',
				'granted'    => '1',
				'deprecated' => '0',
			),
			BLT_Toolkit_Admin_Roles::clean_back(
				array(
					'role'       => 'shop_manager"><script>',
					'view'       => 'advanced',
					'group'      => 'Content-WP_Block',
					's'          => "edit\x00 posts",
					'granted'    => '1',
					'deprecated' => '0',
					'readable'   => 'yes',
					'step'       => 'review',
					'page'       => 'other',
					'user_id'    => '-4',
					'blt_lc'     => 'x',
				)
			)
		);

		$state = array(
			'role'           => 'editor',
			'user_id'        => 0,
			'view'           => 'advanced',
			'group'          => 'core-posts',
			'search'         => 'pub',
			'granted'        => true,
			'deprecated_arg' => '1',
			'readable_arg'   => '',
		);

		$args = BLT_Toolkit_Admin_Roles::state_args( $state );

		$this->assertSame(
			array(
				'role'       => 'editor',
				'view'       => 'advanced',
				'group'      => 'core-posts',
				's'          => 'pub',
				'granted'    => '1',
				'deprecated' => '1',
			),
			$args
		);
		$this->assertSame( $args, BLT_Toolkit_Admin_Roles::clean_back( $args ) );

		// User mode carries the user, not the role; an override drops a key.
		$state['user_id'] = 4;

		$this->assertSame( array( 'user_id', 'view', 'group', 'granted', 'deprecated' ), array_keys( BLT_Toolkit_Admin_Roles::state_args( $state, array( 's' => null ) ) ) );
	}

	/**
	 * US-10: a user's own keys are Grant or Deny, everything else inherits
	 * from their roles (a role's deny named as such), and role-name keys are
	 * memberships, never capabilities.
	 *
	 * @return void
	 */
	public function test_us10_local_user_states() {
		$states = BLT_Toolkit_Admin_Roles::local_user_states(
			array(
				'author'       => true,
				'export'       => true,
				'upload_files' => false,
				'editor'       => false,
			),
			array(
				'author'  => array(
					'read'         => true,
					'upload_files' => true,
				),
				'limited' => array( 'read' => false ),
			),
			array(
				'author'  => 'Author',
				'editor'  => 'Editor',
				'limited' => 'Limited',
			)
		);

		$this->assertSame( array( 'export', 'read', 'upload_files' ), array_keys( $states ) );
		$this->assertSame( 'grant', $states['export']['state'] );
		$this->assertSame( 'deny', $states['upload_files']['state'] );
		$this->assertSame( 'inherit', $states['read']['state'] );
		$this->assertSame( 'Denied by Limited', $states['read']['source'] );
	}

	/**
	 * Names from the URL or a form are reduced to what each may hold.
	 *
	 * @return void
	 */
	public function test_cleaners() {
		$this->assertSame( 'shop_manager-2', BLT_Toolkit_Admin_Roles::clean_role( 'shop_manager-2!' ) );
		$this->assertSame( 'core-posts', BLT_Toolkit_Admin_Roles::clean_group( 'Core-Posts/..' ) );
		$this->assertSame( 191, strlen( BLT_Toolkit_Admin_Roles::clean_role( str_repeat( 'a', 300 ) ) ) );

		// A role ID WordPress accepted with other characters is kept when it
		// exists, so its link opens that role and not another.
		$this->assertSame( 'Event Manager', BLT_Toolkit_Admin_Roles::clean_role( 'Event Manager', array( 'editor', 'Event Manager' ) ) );
		$this->assertSame( 'EventManager', BLT_Toolkit_Admin_Roles::clean_role( 'Event Manager', array( 'editor' ) ) );
		$this->assertSame( '', BLT_Toolkit_Admin_Roles::clean_role( '', array( '' ) ) );
		$this->assertSame( BLT_Toolkit_Admin_Roles::SEARCH_MAX, strlen( BLT_Toolkit_Admin_Roles::clean_search( str_repeat( 'x', 500 ) ) ) );
		$this->assertSame( 'edit_posts', BLT_Toolkit_Admin_Roles::clean_cap( ' edit_posts ' ) );
		$this->assertSame( '', BLT_Toolkit_Admin_Roles::clean_cap( 'do_not_allow' ) );
		$this->assertSame( '', BLT_Toolkit_Admin_Roles::clean_cap( "bad\ncap" ) );
	}
}
