<?php
/**
 * Role diff: canonical JSON, hash and diff (SNAP-5).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Role_Diff.
 */
class Test_Role_Diff extends TestCase {

	/**
	 * A small roles array in the shape of $wp_roles->roles.
	 *
	 * @return array
	 */
	private function roles() {
		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => true,
					'edit_posts'     => true,
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'read'              => true,
					'edit_posts'        => true,
					'edit_others_posts' => true,
				),
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => array( 'read' => true ),
			),
		);
	}

	/**
	 * The same roles built in a different order give the same JSON and hash.
	 *
	 * @return void
	 */
	public function test_snap5_canonicalize_is_order_independent() {
		$roles = $this->roles();

		$shuffled = array(
			'subscriber'    => $roles['subscriber'],
			'editor'        => array(
				'capabilities' => array_reverse( $roles['editor']['capabilities'], true ),
				'name'         => 'Editor',
			),
			'administrator' => array(
				'capabilities' => array(
					'edit_posts'     => true,
					'read'           => true,
					'manage_options' => true,
				),
				'name'         => 'Administrator',
			),
		);

		$this->assertSame( BLT_Toolkit_Role_Diff::canonicalize( $roles ), BLT_Toolkit_Role_Diff::canonicalize( $shuffled ) );
		$this->assertSame( BLT_Toolkit_Role_Diff::hash( $roles ), BLT_Toolkit_Role_Diff::hash( $shuffled ) );
	}

	/**
	 * Keys are sorted at every depth, and the JSON is compact and readable.
	 *
	 * @return void
	 */
	public function test_snap5_canonicalize_sorts_keys_recursively() {
		$json = BLT_Toolkit_Role_Diff::canonicalize(
			array(
				'b' => array(
					'name'         => 'B/é',
					'capabilities' => array(
						'z' => true,
						'a' => false,
					),
				),
				'a' => array(
					'name'         => 'A',
					'capabilities' => array(),
				),
			)
		);

		$this->assertSame( '{"a":{"capabilities":[],"name":"A"},"b":{"capabilities":{"a":false,"z":true},"name":"B/é"}}', $json );
	}

	/**
	 * The hash is the SHA-256 of the canonical JSON: a fixed vector.
	 *
	 * @return void
	 */
	public function test_snap5_hash_is_stable_sha256_of_canonical_json() {
		$roles = array(
			'subscriber' => array(
				'name'         => 'Subscriber',
				'capabilities' => array(
					'read'    => true,
					'level_0' => true,
				),
			),
		);

		$json = '{"subscriber":{"capabilities":{"level_0":true,"read":true},"name":"Subscriber"}}';

		$this->assertSame( $json, BLT_Toolkit_Role_Diff::canonicalize( $roles ) );
		$this->assertSame( hash( 'sha256', $json ), BLT_Toolkit_Role_Diff::hash( $roles ) );
		$this->assertSame( 64, strlen( BLT_Toolkit_Role_Diff::hash( $roles ) ) );
		$this->assertSame( BLT_Toolkit_Role_Diff::hash( $roles ), BLT_Toolkit_Role_Diff::hash( $roles ), 'Same input, same hash.' );
	}

	/**
	 * Any real change changes the hash, including a flip and a display name.
	 *
	 * @return void
	 */
	public function test_snap5_hash_changes_with_content() {
		$roles = $this->roles();
		$hash  = BLT_Toolkit_Role_Diff::hash( $roles );

		$flipped                                   = $roles;
		$flipped['editor']['capabilities']['read'] = false;

		$renamed                   = $roles;
		$renamed['editor']['name'] = 'Content Editor';

		$this->assertNotSame( $hash, BLT_Toolkit_Role_Diff::hash( $flipped ) );
		$this->assertNotSame( $hash, BLT_Toolkit_Role_Diff::hash( $renamed ) );
	}

	/**
	 * Identical inputs, in any order, give an empty diff.
	 *
	 * @return void
	 */
	public function test_snap5_identical_roles_give_an_empty_diff() {
		$roles = $this->roles();
		$diff  = BLT_Toolkit_Role_Diff::diff( $roles, array_reverse( $roles, true ) );

		$this->assertTrue( BLT_Toolkit_Role_Diff::is_empty( $diff ) );
		$this->assertSame(
			array(
				'added'   => array(),
				'removed' => array(),
				'changed' => array(),
			),
			$diff
		);
	}

	/**
	 * Roles only on one side are reported as added or removed, with content.
	 *
	 * @return void
	 */
	public function test_snap5_added_and_removed_roles() {
		$before = $this->roles();
		$after  = $before;

		unset( $after['subscriber'] );

		$after['support_agent'] = array(
			'name'         => 'Support Agent',
			'capabilities' => array(
				'read'             => true,
				'blt_switch_users' => true,
			),
		);

		$diff = BLT_Toolkit_Role_Diff::diff( $before, $after );

		$this->assertSame( array( 'support_agent' ), array_keys( $diff['added'] ) );
		$this->assertSame( 'Support Agent', $diff['added']['support_agent']['name'] );
		$this->assertSame(
			array(
				'blt_switch_users' => true,
				'read'             => true,
			),
			$diff['added']['support_agent']['capabilities']
		);
		$this->assertSame( array( 'subscriber' ), array_keys( $diff['removed'] ) );
		$this->assertSame( array(), $diff['changed'] );
		$this->assertSame(
			array(
				'roles'        => 2,
				'capabilities' => 3,
			),
			BLT_Toolkit_Role_Diff::counts( $diff )
		);
	}

	/**
	 * Capabilities added, removed and flipped both ways, per role.
	 *
	 * @return void
	 */
	public function test_snap5_capabilities_added_removed_and_flipped() {
		$before = $this->roles();
		$after  = $before;

		$after['editor']['capabilities']['upload_files'] = true;  // Added.
		unset( $after['editor']['capabilities']['edit_others_posts'] ); // Removed.
		$after['editor']['capabilities']['edit_posts'] = false;   // Flipped true to false.

		$before['subscriber']['capabilities']['level_0'] = false; // Flipped false to true below.
		$after['subscriber']['capabilities']['level_0']  = true;

		$diff = BLT_Toolkit_Role_Diff::diff( $before, $after );

		$this->assertSame( array( 'editor', 'subscriber' ), array_keys( $diff['changed'] ) );

		$editor = $diff['changed']['editor'];

		$this->assertNull( $editor['name'] );
		$this->assertSame( array( 'upload_files' => true ), $editor['added'] );
		$this->assertSame( array( 'edit_others_posts' => true ), $editor['removed'] );
		$this->assertSame(
			array(
				'edit_posts' => array(
					'from' => true,
					'to'   => false,
				),
			),
			$editor['flipped']
		);

		$this->assertSame(
			array(
				'level_0' => array(
					'from' => false,
					'to'   => true,
				),
			),
			$diff['changed']['subscriber']['flipped']
		);

		$this->assertSame(
			array(
				'roles'        => 2,
				'capabilities' => 4,
			),
			BLT_Toolkit_Role_Diff::counts( $diff )
		);
	}

	/**
	 * A capability added as an explicit denial keeps its false value.
	 *
	 * @return void
	 */
	public function test_snap5_capability_added_as_denial() {
		$before = $this->roles();
		$after  = $before;

		$after['editor']['capabilities']['delete_users'] = false;

		$diff = BLT_Toolkit_Role_Diff::diff( $before, $after );

		$this->assertSame( array( 'delete_users' => false ), $diff['changed']['editor']['added'] );
		$this->assertSame( array(), $diff['changed']['editor']['flipped'] );
	}

	/**
	 * A display name change alone is a change.
	 *
	 * @return void
	 */
	public function test_snap5_display_name_change() {
		$before = $this->roles();
		$after  = $before;

		$after['editor']['name'] = 'Content Editor';

		$diff = BLT_Toolkit_Role_Diff::diff( $before, $after );

		$this->assertSame(
			array(
				'from' => 'Editor',
				'to'   => 'Content Editor',
			),
			$diff['changed']['editor']['name']
		);
		$this->assertSame( array(), $diff['changed']['editor']['added'] );
		$this->assertSame( array(), $diff['changed']['editor']['removed'] );
		$this->assertSame( array(), $diff['changed']['editor']['flipped'] );
		$this->assertSame( array( 'editor' ), array_keys( $diff['changed'] ) );
	}

	/**
	 * Truthy storage variants are the same grant, so they are not a flip.
	 *
	 * @return void
	 */
	public function test_snap5_truthy_variants_are_not_flips() {
		$before = $this->roles();
		$after  = $before;

		$after['editor']['capabilities']['read'] = 1;

		$this->assertTrue( BLT_Toolkit_Role_Diff::is_empty( BLT_Toolkit_Role_Diff::diff( $before, $after ) ) );
	}

	/**
	 * Malformed roles read as empty instead of breaking the diff.
	 *
	 * @return void
	 */
	public function test_snap5_malformed_roles_do_not_break_the_diff() {
		$before = array(
			'broken' => 'not an array',
			'nocaps' => array( 'name' => 'No caps' ),
		);
		$after  = array(
			'broken' => array(
				'name'         => '',
				'capabilities' => 'nope',
			),
			'nocaps' => array(
				'name'         => 'No caps',
				'capabilities' => array( 'read' => true ),
			),
		);

		$diff = BLT_Toolkit_Role_Diff::diff( $before, $after );

		$this->assertSame( array( 'nocaps' ), array_keys( $diff['changed'] ) );
		$this->assertSame( array( 'read' => true ), $diff['changed']['nocaps']['added'] );
	}

	/**
	 * Output is sorted, so the same change always reads the same way.
	 *
	 * @return void
	 */
	public function test_snap5_diff_output_is_sorted() {
		$after = $this->roles();

		$after['zeta']  = array(
			'name'         => 'Zeta',
			'capabilities' => array(),
		);
		$after['alpha'] = array(
			'name'         => 'Alpha',
			'capabilities' => array(),
		);

		$after['editor']['capabilities']['z_cap'] = true;
		$after['editor']['capabilities']['a_cap'] = true;

		$diff = BLT_Toolkit_Role_Diff::diff( $this->roles(), $after );

		$this->assertSame( array( 'alpha', 'zeta' ), array_keys( $diff['added'] ) );
		$this->assertSame( array( 'a_cap', 'z_cap' ), array_keys( $diff['changed']['editor']['added'] ) );
	}
}
