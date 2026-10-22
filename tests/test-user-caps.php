<?php
/**
 * Per-user capabilities: the pure parts of BLT_Toolkit_User_Caps (US-10).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for the three-state model, the change set within the
 * on-page set, and the meta write that keeps role keys.
 */
class Test_User_Caps extends TestCase {

	/**
	 * The site's roles for these tests.
	 *
	 * @var array<string,string>
	 */
	private $role_names = array(
		'administrator' => 'Administrator',
		'editor'        => 'Editor',
		'author'        => 'Author',
		'shop_manager'  => 'Shop manager',
	);

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * US-10: own_caps() keeps only non-role keys, as booleans, in order;
	 * a role key counts as a role whatever its value.
	 *
	 * @return void
	 */
	public function test_us10_own_caps_skips_role_keys() {
		$meta = array(
			'editor'       => true,
			'upload_files' => 0,
			'shop_manager' => false,
			'edit_posts'   => '1',
		);

		$this->assertSame(
			array(
				'upload_files' => false,
				'edit_posts'   => true,
			),
			BLT_Toolkit_User_Caps::own_caps( $meta, array_keys( $this->role_names ) )
		);
	}

	/**
	 * US-10: inherit, grant and deny, with the sources "from role X",
	 * "granted to this user" and "denied for this user"; a later role's value
	 * wins, as WP_User::get_role_caps() merges.
	 *
	 * @return void
	 */
	public function test_us10_state_three_states_and_sources() {
		$roles_caps = array(
			'author' => array(
				'read'          => true,
				'edit_posts'    => true,
				'publish_posts' => true,
				'level_2'       => true,
				'delete_posts'  => false,
			),
			'editor' => array(
				'read'         => true,
				'edit_posts'   => true,
				'delete_posts' => true,
				'edit_pages'   => false,
			),
		);
		$own        = array(
			'publish_posts' => false,
			'upload_files'  => true,
		);

		$state = BLT_Toolkit_User_Caps::compute_state( $roles_caps, $own, $this->role_names, array( 'manage_options', 'editor', 'do_not_allow' ) );

		$this->assertSame( array( 'delete_posts', 'edit_pages', 'edit_posts', 'level_2', 'manage_options', 'publish_posts', 'read', 'upload_files' ), array_keys( $state ) );

		$this->assertSame( 'inherit', $state['edit_posts']['state'] );
		$this->assertTrue( $state['edit_posts']['effective'] );
		$this->assertSame( 'From roles Author, Editor', $state['edit_posts']['source'] );
		$this->assertSame( array( 'author', 'editor' ), $state['edit_posts']['roles'] );

		// Editor comes later and grants it, so the Author deny is overridden.
		$this->assertSame( 'inherit', $state['delete_posts']['state'] );
		$this->assertTrue( $state['delete_posts']['inherited'] );
		$this->assertSame( 'From role Editor', $state['delete_posts']['source'] );

		$this->assertSame( 'inherit', $state['edit_pages']['state'] );
		$this->assertFalse( $state['edit_pages']['effective'] );
		$this->assertFalse( $state['edit_pages']['inherited'] );
		$this->assertSame( 'Denied by role Editor', $state['edit_pages']['source'] );

		$this->assertSame( 'deny', $state['publish_posts']['state'] );
		$this->assertFalse( $state['publish_posts']['effective'] );
		$this->assertTrue( $state['publish_posts']['inherited'] );
		$this->assertSame( 'Denied for this user', $state['publish_posts']['source'] );

		$this->assertSame( 'grant', $state['upload_files']['state'] );
		$this->assertTrue( $state['upload_files']['effective'] );
		$this->assertNull( $state['upload_files']['inherited'] );
		$this->assertSame( 'Granted to this user', $state['upload_files']['source'] );

		$this->assertSame( 'inherit', $state['manage_options']['state'] );
		$this->assertFalse( $state['manage_options']['effective'] );
		$this->assertSame( 'Not granted', $state['manage_options']['source'] );
	}

	/**
	 * US-10, RO-1 rule: only on-page capabilities with a valid state change;
	 * a no-op state is no change; a role name is reported, never set.
	 *
	 * @return void
	 */
	public function test_us10_changes_stay_within_on_page_set() {
		$own = array(
			'upload_files'  => true,
			'publish_posts' => false,
			'hidden_cap'    => true,
		);

		$changes = BLT_Toolkit_User_Caps::compute_changes(
			$own,
			array( 'upload_files', 'publish_posts', 'edit_posts', 'delete_posts', 'moderate_comments', 'do_not_allow', '' ),
			array(
				'upload_files'      => 'inherit',
				'publish_posts'     => 'grant',
				'edit_posts'        => 'deny',
				'delete_posts'      => 'inherit',
				'moderate_comments' => 'bogus',
				'hidden_cap'        => 'inherit',
				'do_not_allow'      => 'grant',
			),
			array_keys( $this->role_names )
		);

		$this->assertSame(
			array(
				'add'     => array(
					'edit_posts'    => false,
					'publish_posts' => true,
				),
				'remove'  => array( 'upload_files' ),
				'invalid' => array(),
			),
			$changes
		);
	}

	/**
	 * US-10: a role-named row that asks for a change is invalid (in the
	 * capabilities meta it would be a role membership); left alone it is not.
	 *
	 * @return void
	 */
	public function test_us10_role_name_is_never_a_capability() {
		$changes = BLT_Toolkit_User_Caps::compute_changes(
			array(),
			array( 'editor', 'author' ),
			array(
				'editor' => 'grant',
				'author' => 'inherit',
			),
			array_keys( $this->role_names )
		);

		$this->assertSame( array(), $changes['add'] );
		$this->assertSame( array( 'editor' ), $changes['invalid'] );
	}

	/**
	 * US-10: the meta is written with every role key kept in place and in
	 * order; a changed capability keeps its position; role keys are never
	 * removed or set, whatever the change says.
	 *
	 * @return void
	 */
	public function test_us10_apply_to_meta_preserves_role_keys() {
		$meta = array(
			'editor'       => true,
			'edit_posts'   => false,
			'shop_manager' => true,
			'upload_files' => true,
		);

		$after = BLT_Toolkit_User_Caps::apply_to_meta(
			$meta,
			array(
				'add'    => array(
					'upload_files'   => false,
					'manage_options' => true,
					'author'         => true,
				),
				'remove' => array( 'edit_posts', 'shop_manager' ),
			),
			array_keys( $this->role_names )
		);

		$this->assertSame(
			array(
				'editor'         => true,
				'shop_manager'   => true,
				'upload_files'   => false,
				'manage_options' => true,
			),
			$after
		);
	}

	/**
	 * US-10, RO-2 rule: the lock hash ignores key order and 1 versus true,
	 * and changes when a role key or a capability changes.
	 *
	 * @return void
	 */
	public function test_us10_hash_is_canonical() {
		$a = array(
			'editor'     => true,
			'edit_posts' => false,
		);
		$b = array(
			'edit_posts' => 0,
			'editor'     => '1',
		);

		$this->assertSame( BLT_Toolkit_User_Caps::caps_hash( $a ), BLT_Toolkit_User_Caps::caps_hash( $b ) );
		$this->assertSame( 64, strlen( BLT_Toolkit_User_Caps::caps_hash( $a ) ) );
		$this->assertNotSame( BLT_Toolkit_User_Caps::caps_hash( $a ), BLT_Toolkit_User_Caps::caps_hash( array( 'editor' => true ) ) );
		$this->assertNotSame( BLT_Toolkit_User_Caps::caps_hash( $a ), BLT_Toolkit_User_Caps::caps_hash( array_merge( $a, array( 'author' => true ) ) ) );
	}

	/**
	 * SEC-12 for a person: a lockout-critical capability is never denied to
	 * a toolkit admin; anyone else, or a grant, passes.
	 *
	 * @return void
	 */
	public function test_sec12_lockout_denies_for_admin_only() {
		$changes = array(
			'add'    => array(
				'manage_options' => false,
				'edit_posts'     => false,
				'blt_edit_roles' => false,
				'list_users'     => true,
			),
			'remove' => array( 'read' ),
		);
		$lockout = array( 'read', 'manage_options', 'list_users', 'blt_edit_roles' );

		$this->assertSame( array( 'blt_edit_roles', 'manage_options' ), BLT_Toolkit_User_Caps::lockout_denies( $changes, true, $lockout ) );
		$this->assertSame( array(), BLT_Toolkit_User_Caps::lockout_denies( $changes, false, $lockout ) );
	}

	/**
	 * SEC-10 for a person: what a change gives is each `true` set and each
	 * deny lifted where a role grants the capability.
	 *
	 * @return void
	 */
	public function test_sec10_granting_counts_lifted_denies() {
		$before = array(
			'edit_users'   => false,
			'install_cats' => false,
		);
		$state  = array(
			'edit_users'   => array( 'inherited' => true ),
			'install_cats' => array( 'inherited' => null ),
		);
		$out    = BLT_Toolkit_User_Caps::granting(
			array(
				'add'    => array(
					'promote_users' => true,
					'upload_files'  => false,
				),
				'remove' => array( 'edit_users', 'install_cats' ),
			),
			$before,
			$state
		);

		$this->assertSame( array( 'edit_users', 'promote_users' ), $out );
	}

	/**
	 * US-10: names are cleaned (trimmed, deduplicated, bounded, never
	 * do_not_allow).
	 *
	 * @return void
	 */
	public function test_us10_clean_names() {
		$this->assertSame(
			array( 'edit_posts', 'read', '42' ),
			BLT_Toolkit_User_Caps::clean_names( array( ' edit_posts ', 'read', 'edit_posts', 'do_not_allow', '', array( 'x' ), str_repeat( 'a', 192 ), 42 ) )
		);
	}
}
