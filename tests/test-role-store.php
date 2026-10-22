<?php
/**
 * Role store: the pure parts of BLT_Toolkit_Role_Store (RO-1 to RO-4) and
 * role metadata enforcement (SPEC 9.5).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Role_Store and
 * BLT_Toolkit_Roles::hides_toolbar().
 */
class Test_Role_Store extends TestCase {

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * RO-1: only capabilities on the page change; a capability outside the
	 * on-page set (filtered out, another group) is never touched, granted or
	 * not, and a checked name outside it is ignored.
	 *
	 * @return void
	 */
	public function test_ro1_changes_stay_within_on_page_set() {
		$current = array(
			'read'         => true,
			'edit_posts'   => true,
			'upload_files' => true,
			'hidden_cap'   => true,
		);

		$changes = BLT_Toolkit_Role_Store::compute_changes(
			$current,
			array( 'edit_posts', 'publish_posts', 'upload_files' ),
			array( 'publish_posts', 'upload_files', 'other_group_cap' )
		);

		$this->assertSame(
			array(
				'add'    => array( 'publish_posts' => true ),
				'remove' => array( 'edit_posts' ),
			),
			$changes
		);

		$after = BLT_Toolkit_Role_Store::apply_changes(
			array(
				'editor' => array(
					'name'         => 'Editor',
					'capabilities' => $current,
				),
			),
			'editor',
			$changes
		);

		$this->assertSame(
			array(
				'read'          => true,
				'upload_files'  => true,
				'hidden_cap'    => true,
				'publish_posts' => true,
			),
			$after['editor']['capabilities']
		);

		$diff = BLT_Toolkit_Role_Diff::diff(
			array( 'editor' => array( 'capabilities' => array_intersect_key( $current, array_flip( array( 'read', 'hidden_cap' ) ) ) ) ),
			array( 'editor' => array( 'capabilities' => array_intersect_key( $after['editor']['capabilities'], array_flip( array( 'read', 'hidden_cap' ) ) ) ) )
		);

		$this->assertTrue( BLT_Toolkit_Role_Diff::is_empty( $diff ), 'Capabilities outside the on-page set are identical before and after.' );
	}

	/**
	 * RO-1: a stored `false` (deny) that stays unchecked is untouched;
	 * checking it flips it to `true`.
	 *
	 * @return void
	 */
	public function test_ro1_stored_false_stays_unless_checked() {
		$current = array(
			'edit_posts'    => false,
			'publish_posts' => false,
			'delete_posts'  => 1,
		);

		$this->assertSame(
			array(
				'add'    => array( 'publish_posts' => true ),
				'remove' => array(),
			),
			BLT_Toolkit_Role_Store::compute_changes( $current, array( 'edit_posts', 'publish_posts', 'delete_posts' ), array( 'publish_posts', 'delete_posts' ) )
		);

		$this->assertSame(
			array(
				'add'    => array(),
				'remove' => array(),
			),
			BLT_Toolkit_Role_Store::compute_changes( $current, array( 'edit_posts', 'delete_posts' ), array( 'delete_posts' ) ),
			'Nothing to do: the deny stays, the grant stays.'
		);
	}

	/**
	 * RO-2: the lock hash ignores key order and `1` versus `true`, ignores
	 * the display name, and changes when a capability changes.
	 *
	 * @return void
	 */
	public function test_ro2_hash_is_canonical() {
		$a = BLT_Toolkit_Role_Store::caps_hash(
			array(
				'read'       => true,
				'edit_posts' => true,
				'level_0'    => false,
			)
		);
		$b = BLT_Toolkit_Role_Store::caps_hash(
			array(
				'level_0'    => 0,
				'edit_posts' => 1,
				'read'       => '1',
			)
		);

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
		$this->assertSame( $a, $b );
		$this->assertNotSame(
			$a,
			BLT_Toolkit_Role_Store::caps_hash(
				array(
					'read'       => true,
					'edit_posts' => true,
					'level_0'    => true,
				)
			),
			'A flip changes the hash.'
		);
		$this->assertNotSame(
			$a,
			BLT_Toolkit_Role_Store::caps_hash(
				array(
					'read'       => true,
					'edit_posts' => true,
				)
			),
			'A removal changes the hash.'
		);
		$this->assertSame( hash( 'sha256', '[]' ), BLT_Toolkit_Role_Store::caps_hash( array() ) );
	}

	/**
	 * RO-2 (URE-5): a refused save shows what changed since the form was
	 * drawn, from the newest snapshot holding the role as it was then; none
	 * when no snapshot has it.
	 *
	 * @return void
	 */
	public function test_ro2_conflict_diff_from_render_time_copy() {
		$drawn   = array(
			'name'         => 'Editor',
			'capabilities' => array(
				'read'       => true,
				'edit_posts' => true,
			),
		);
		$current = array(
			'name'         => 'Editor',
			'capabilities' => array(
				'read'          => true,
				'edit_posts'    => true,
				'publish_posts' => true,
			),
		);
		$hash    = BLT_Toolkit_Role_Store::caps_hash( $drawn['capabilities'] );
		$older   = array( 'editor' => $current );
		$then    = array(
			'editor' => $drawn,
			'author' => array(
				'name'         => 'Author',
				'capabilities' => array(),
			),
		);

		$diff = BLT_Toolkit_Role_Store::conflict_diff( 'editor', $current, $hash, array( array( 'other' => array() ), $then, $older ) );

		$this->assertSame( array( 'publish_posts' => true ), $diff['changed']['editor']['added'] );
		$this->assertSame( array(), $diff['added'] );
		$this->assertNull( BLT_Toolkit_Role_Store::conflict_diff( 'editor', $current, $hash, array( $older ) ) );
	}

	/**
	 * RO-4: the change set of a diff, as the Guard and the hooks receive it:
	 * adds with their value (a flip is an add), removes, created and
	 * deleted roles, and a display name change.
	 *
	 * @return void
	 */
	public function test_ro4_changes_from_diff() {
		$before = array(
			'editor' => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'read'         => true,
					'edit_posts'   => true,
					'upload_files' => false,
				),
			),
			'old'    => array(
				'name'         => 'Old',
				'capabilities' => array(
					'read'    => true,
					'level_0' => true,
				),
			),
		);
		$after  = array(
			'editor' => array(
				'name'         => 'Editors',
				'capabilities' => array(
					'read'          => true,
					'upload_files'  => true,
					'publish_posts' => true,
					'export'        => false,
				),
			),
			'new'    => array(
				'name'         => 'New',
				'capabilities' => array(
					'read'      => 1,
					'moderated' => false,
				),
			),
		);

		$this->assertSame(
			array(
				'editor' => array(
					'add'     => array(
						'export'        => false,
						'publish_posts' => true,
						'upload_files'  => true,
					),
					'remove'  => array( 'edit_posts' ),
					'created' => false,
					'deleted' => false,
					'name'    => array(
						'from' => 'Editor',
						'to'   => 'Editors',
					),
				),
				'new'    => array(
					'add'     => array(
						'moderated' => false,
						'read'      => true,
					),
					'remove'  => array(),
					'created' => true,
					'deleted' => false,
					'name'    => null,
				),
				'old'    => array(
					'add'     => array(),
					'remove'  => array( 'level_0', 'read' ),
					'created' => false,
					'deleted' => true,
					'name'    => null,
				),
			),
			BLT_Toolkit_Role_Store::changes_from_diff( BLT_Toolkit_Role_Diff::diff( $before, $after ) )
		);

		$this->assertSame( array(), BLT_Toolkit_Role_Store::changes_from_diff( BLT_Toolkit_Role_Diff::diff( $before, $before ) ) );
	}

	/**
	 * RO-1: names from the form are trimmed, unique, case kept, and junk,
	 * control characters, overlong names and `do_not_allow` are dropped.
	 *
	 * @return void
	 */
	public function test_ro1_clean_names() {
		$this->assertSame(
			array( 'edit_posts', 'NextGEN Manage gallery', 'Edit_Posts' ),
			BLT_Toolkit_Role_Store::clean_names(
				array( ' edit_posts ', 'edit_posts', 'NextGEN Manage gallery', 'Edit_Posts', '', 7, array( 'x' ), "bad\nname", str_repeat( 'a', 192 ), 'do_not_allow' )
			)
		);
	}

	/**
	 * RO-3: a roles array is written in the shape WordPress reads.
	 *
	 * @return void
	 */
	public function test_ro3_normalize_roles() {
		$this->assertSame(
			array(
				'editor' => array(
					'name'         => 'Editor',
					'capabilities' => array( 'read' => 1 ),
				),
				'bare'   => array(
					'name'         => 'bare',
					'capabilities' => array(),
				),
			),
			BLT_Toolkit_Role_Store::normalize_roles(
				array(
					'editor' => array(
						'name'         => 'Editor',
						'capabilities' => array( 'read' => 1 ),
					),
					'bare'   => array( 'capabilities' => 'junk' ),
					'broken' => 'junk',
					''       => array( 'name' => 'Empty' ),
				)
			)
		);
	}

	/**
	 * RO-1, RO-4: a malformed role the mutator did not touch is written back
	 * as stored and never shows in the diff, so a save of another role does
	 * not rename it or run the Guard on it; a touched one is normalized.
	 *
	 * @return void
	 */
	public function test_ro1_ro4_normalize_touched_leaves_untouched_roles_alone() {
		$before = array(
			'noname'     => array( 'capabilities' => array( 'manage_options' => true ) ),
			'strcaps'    => array(
				'name'         => 'Strcaps',
				'capabilities' => 'read',
			),
			'broken'     => 'junk',
			'subscriber' => array(
				'name'         => 'Subscriber',
				'capabilities' => array( 'read' => true ),
			),
		);
		$after  = $before;

		$after['subscriber']['capabilities']['upload_files'] = true;

		$written = BLT_Toolkit_Role_Store::normalize_touched( $before, $after );
		$diff    = BLT_Toolkit_Role_Diff::diff( $before, $written );

		$this->assertSame( $before['noname'], $written['noname'] );
		$this->assertSame( $before['strcaps'], $written['strcaps'] );
		$this->assertSame( 'junk', $written['broken'] );
		$this->assertSame( array( 'subscriber' ), array_keys( $diff['changed'] ) );
		$this->assertSame( array(), $diff['added'] );
		$this->assertSame( array(), $diff['removed'] );
		$this->assertSame( array( 'upload_files' ), array_keys( BLT_Toolkit_Role_Store::changes_from_diff( $diff )['subscriber']['add'] ) );

		// A touched malformed role is normalized; a new role too.
		$after = $before;

		$after['noname']['capabilities']['read'] = true;
		$after['fresh']                          = array( 'capabilities' => array( 'read' => true ) );

		$written = BLT_Toolkit_Role_Store::normalize_touched( $before, $after );

		$this->assertSame(
			array(
				'name'         => 'noname',
				'capabilities' => array(
					'manage_options' => true,
					'read'           => true,
				),
			),
			$written['noname']
		);
		$this->assertSame( 'fresh', $written['fresh']['name'] );
		$this->assertSame( $before['strcaps'], $written['strcaps'] );
		$this->assertArrayNotHasKey( 'gone', BLT_Toolkit_Role_Store::normalize_touched( $before, array( 'gone' => 'junk' ) ) );
	}

	/**
	 * RO-3: applying a change to a role that does not exist changes nothing.
	 *
	 * @return void
	 */
	public function test_ro3_apply_changes_to_missing_role() {
		$roles = array(
			'editor' => array(
				'name'         => 'Editor',
				'capabilities' => array( 'read' => true ),
			),
		);

		$this->assertSame(
			$roles,
			BLT_Toolkit_Role_Store::apply_changes(
				$roles,
				'ghost',
				array(
					'add'    => array( 'x' => true ),
					'remove' => array(),
				)
			)
		);
	}

	/**
	 * SPEC 9.5, X-5: the toolbar is hidden only when every role the user
	 * holds has the flag; no roles, or one role without it, keeps it.
	 *
	 * @return void
	 */
	public function test_spec_9_5_toolbar_hidden_only_when_every_role_says_so() {
		$meta = array(
			'subscriber' => array(
				'description'  => '',
				'hide_toolbar' => true,
			),
			'customer'   => array(
				'description'  => '',
				'hide_toolbar' => true,
			),
			'author'     => array(
				'description'  => 'Writes.',
				'hide_toolbar' => false,
			),
		);

		$this->assertTrue( BLT_Toolkit_Roles::hides_toolbar( array( 'subscriber' ), $meta ) );
		$this->assertTrue( BLT_Toolkit_Roles::hides_toolbar( array( 'subscriber', 'customer' ), $meta ) );
		$this->assertFalse( BLT_Toolkit_Roles::hides_toolbar( array( 'subscriber', 'author' ), $meta ) );
		$this->assertFalse( BLT_Toolkit_Roles::hides_toolbar( array( 'subscriber', 'editor' ), $meta ) );
		$this->assertFalse( BLT_Toolkit_Roles::hides_toolbar( array(), $meta ) );
		$this->assertFalse( BLT_Toolkit_Roles::hides_toolbar( array( 'subscriber' ), array() ) );
	}
}
