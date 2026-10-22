<?php
/**
 * The Guard's pure rule logic (SEC-1, SEC-2, SEC-3, SEC-5, SEC-9, SEC-10,
 * SEC-12, SEC-13, SEC-14, UN-21).
 *
 * The WordPress-coupled parts (map_meta_cap with real users, editable_roles,
 * the Users list and REST hiding, recovery writes) are smoke-tested against a
 * real install and recorded in tasks/todo.md.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Guard's pure methods.
 */
class Test_Guard extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * Facts for a role change, with defaults that allow it.
	 *
	 * @param array $overrides Facts to change.
	 * @return array
	 */
	private function role_facts( array $overrides = array() ) {
		return array_merge(
			array(
				'actor_is_admin'   => false,
				'actor_roles'      => array( 'manager' ),
				'actor_caps'       => array_fill_keys( array( 'read', 'edit_posts', 'list_users', 'blt_edit_roles' ), true ),
				'role'             => 'helper',
				'role_caps'        => array( 'read' => true ),
				'grants'           => array(),
				'denies'           => array(),
				'removes'          => array(),
				'lockout'          => BLT_Toolkit_Caps::lockout_critical(),
				'role_names'       => array( 'administrator', 'editor', 'manager', 'helper' ),
				'loses_edit_roles' => false,
				'other_admin'      => false,
				'confirmed'        => false,
			),
			$overrides
		);
	}

	/**
	 * SEC-9: a capability outside the guarded set returns untouched, before
	 * any WordPress function runs (none is shimmed here, so any call would
	 * fail the test).
	 *
	 * @return void
	 */
	public function test_sec9_unguarded_caps_return_at_once() {
		foreach ( array( 'read', 'edit_posts', 'edit_users', 'promote_users', 'manage_options', 'blt_switch_users' ) as $cap ) {
			$this->assertSame( array( $cap ), BLT_Toolkit_Guard::map_meta_cap( array( $cap ), $cap, 7, array( 1 ) ), $cap );
		}

		$this->assertSame( array( 'x' ), BLT_Toolkit_Guard::map_meta_cap( array( 'x' ), null, 7, array() ), 'A non-string capability is not ours.' );
	}

	/**
	 * SEC-12: only `administrator` is protected.
	 *
	 * @return void
	 */
	public function test_sec12_protected_role() {
		$this->assertTrue( BLT_Toolkit_Guard::is_protected_role( 'administrator' ) );
		$this->assertFalse( BLT_Toolkit_Guard::is_protected_role( 'Administrator' ) );
		$this->assertFalse( BLT_Toolkit_Guard::is_protected_role( 'editor' ) );
		$this->assertFalse( BLT_Toolkit_Guard::is_protected_role( '' ) );
	}

	/**
	 * Requested changes split into grants, denies and removals, cleaned.
	 *
	 * @return void
	 */
	public function test_cap_changes_shapes() {
		$this->assertSame(
			array(
				'grants'  => array( 'edit_posts', 'read' ),
				'denies'  => array(),
				'removes' => array( 'upload_files' ),
			),
			BLT_Toolkit_Guard::cap_changes( array( 'read', 'edit_posts', 'read', '', 'do_not_allow' ), array( 'upload_files' ) ),
			'Lists: each name is a grant; duplicates, empty names and do_not_allow are dropped.'
		);

		$this->assertSame(
			array(
				'grants'  => array( 'publish_posts' ),
				'denies'  => array( 'delete_posts', 'edit_posts' ),
				'removes' => array( 'level_1', 'upload_files' ),
			),
			BLT_Toolkit_Guard::cap_changes(
				array(
					'publish_posts' => true,
					'edit_posts'    => false,
					'delete_posts'  => 0,
				),
				array(
					'upload_files' => true,
					'level_1'      => false,
				)
			),
			'Maps: false denies; removal keys are used whatever their value.'
		);
	}

	/**
	 * Applying a change to a role's capabilities.
	 *
	 * @return void
	 */
	public function test_apply_changes() {
		$caps = array(
			'read'         => true,
			'edit_posts'   => true,
			'upload_files' => true,
		);

		$this->assertSame(
			array(
				'read'          => true,
				'edit_posts'    => false,
				'publish_posts' => true,
			),
			BLT_Toolkit_Guard::apply_changes(
				$caps,
				BLT_Toolkit_Guard::cap_changes(
					array(
						'publish_posts' => true,
						'edit_posts'    => false,
					),
					array( 'upload_files' )
				)
			)
		);
	}

	/**
	 * SEC-13 simulation: allcaps is built like WP_User::get_role_caps(), a
	 * later role overriding an earlier one and the user's own capabilities
	 * overriding every role.
	 *
	 * @return void
	 */
	public function test_sec13_merge_caps_matches_wordpress_order() {
		$merged = BLT_Toolkit_Guard::merge_caps(
			array(
				'editor' => array(
					'blt_edit_roles' => true,
					'edit_posts'     => true,
				),
				'helper' => array(
					'blt_edit_roles' => false,
					'read'           => true,
				),
			),
			array(
				'editor'     => true,
				'helper'     => true,
				'edit_posts' => false,
			)
		);

		$this->assertFalse( $merged['blt_edit_roles'], 'The later role wins.' );
		$this->assertFalse( $merged['edit_posts'], 'The user-level deny wins.' );
		$this->assertTrue( $merged['read'] );
		$this->assertTrue( $merged['editor'], 'Role keys from the meta are in allcaps, as in WordPress.' );
	}

	/**
	 * UN-21: when a self-service cooldown ends.
	 *
	 * @return void
	 */
	public function test_un21_cooldown_until() {
		$at = 1700000000;

		$this->assertSame( 0, BLT_Toolkit_Guard::cooldown_until( $at, 0 ), 'No cooldown configured.' );
		$this->assertSame( 0, BLT_Toolkit_Guard::cooldown_until( '', 7 ), 'Never changed.' );
		$this->assertSame( 0, BLT_Toolkit_Guard::cooldown_until( 'yesterday', 7 ), 'Not a timestamp.' );
		$this->assertSame( 0, BLT_Toolkit_Guard::cooldown_until( false, 7 ) );
		$this->assertSame( $at + 7 * 86400, BLT_Toolkit_Guard::cooldown_until( $at, 7 ) );
		$this->assertSame( $at + 86400, BLT_Toolkit_Guard::cooldown_until( (string) $at, 1 ), 'Meta comes back as a string.' );
		$this->assertSame( $at + 2 * 86400, BLT_Toolkit_Guard::cooldown_until( gmdate( 'Y-m-d H:i:s', $at ), 2 ), 'A UTC date is read too.' );
	}

	/**
	 * SEC-1: a non-admin can never act on a toolkit admin; an admin can.
	 *
	 * @return void
	 */
	public function test_sec1_protects_administrators() {
		$facts = array(
			'actor_exists'    => true,
			'target_exists'   => true,
			'actor_is_admin'  => false,
			'target_is_admin' => true,
			'same_user'       => false,
			'missing'         => array(),
		);

		$violation = BLT_Toolkit_Guard::user_action_violation( $facts );

		$this->assertSame( 'blt_toolkit_protected_admin', $violation['code'] );
		$this->assertSame( 'SEC-1', $violation['rule'] );

		$facts['actor_is_admin'] = true;

		$this->assertNull( BLT_Toolkit_Guard::user_action_violation( $facts ), 'An administrator may act on another administrator.' );
	}

	/**
	 * SEC-2: a non-admin may act only on users within their own capabilities,
	 * and always on themselves.
	 *
	 * @return void
	 */
	public function test_sec2_target_must_be_a_subset() {
		$facts = array(
			'actor_exists'    => true,
			'target_exists'   => true,
			'actor_is_admin'  => false,
			'target_is_admin' => false,
			'same_user'       => false,
			'missing'         => array( 'publish_posts', 'edit_others_posts' ),
		);

		$this->assertSame(
			array(
				'code'        => 'blt_toolkit_target_exceeds_actor',
				'rule'        => 'SEC-2',
				'caps'        => array( 'edit_others_posts', 'publish_posts' ),
				'confirmable' => false,
			),
			BLT_Toolkit_Guard::user_action_violation( $facts )
		);

		$facts['missing'] = array();
		$this->assertNull( BLT_Toolkit_Guard::user_action_violation( $facts ), 'A subset target is allowed.' );

		$facts['missing']   = array( 'publish_posts' );
		$facts['same_user'] = true;
		$this->assertNull( BLT_Toolkit_Guard::user_action_violation( $facts ), 'Acting on oneself is not a SEC-2 matter.' );
	}

	/**
	 * Missing users are refused before any rule.
	 *
	 * @return void
	 */
	public function test_missing_actor_or_target() {
		$this->assertSame( 'blt_toolkit_no_user', BLT_Toolkit_Guard::user_action_violation( array( 'actor_exists' => true ) )['code'] );
		$this->assertSame(
			'blt_toolkit_no_actor',
			BLT_Toolkit_Guard::user_action_violation(
				array(
					'target_exists'  => true,
					'actor_is_admin' => true,
				)
			)['code']
		);
	}

	/**
	 * SEC-3: a non-admin grants only what they hold, and never a role name.
	 *
	 * @return void
	 */
	public function test_sec3_never_grant_what_you_do_not_hold() {
		$facts = array(
			'actor_is_admin' => false,
			'actor_caps'     => array_fill_keys( array( 'read', 'edit_posts', 'upload_files' ), true ),
			'grants'         => array( 'read', 'edit_posts' ),
			'role_names'     => array( 'administrator', 'editor' ),
		);

		$this->assertNull( BLT_Toolkit_Guard::grant_violation( $facts ) );

		$facts['grants'] = array( 'upload_files', 'manage_options', 'install_plugins' );
		$this->assertSame(
			array(
				'code'        => 'blt_toolkit_grant_exceeds_actor',
				'rule'        => 'SEC-3',
				'caps'        => array( 'install_plugins', 'manage_options' ),
				'confirmable' => false,
			),
			BLT_Toolkit_Guard::grant_violation( $facts )
		);

		$facts['grants'] = array( 'read', 'administrator' );
		$violation       = BLT_Toolkit_Guard::grant_violation( $facts );
		$this->assertSame( 'blt_toolkit_role_name_cap', $violation['code'] );
		$this->assertSame( array( 'administrator' ), $violation['caps'] );

		$facts['actor_is_admin'] = true;
		$facts['grants']         = array( 'manage_options', 'administrator' );
		$this->assertNull( BLT_Toolkit_Guard::grant_violation( $facts ), 'SEC-3 binds non-admins only.' );
	}

	/**
	 * SEC-12: the lockout-critical set can never be removed from, or denied
	 * to, administrator, by anyone. Other capabilities can be removed by an
	 * administrator.
	 *
	 * @return void
	 */
	public function test_sec12_lockout_critical_set_stays_on_administrator() {
		$base = array(
			'actor_is_admin' => true,
			'actor_roles'    => array( 'administrator' ),
			'role'           => 'administrator',
		);

		$violation = BLT_Toolkit_Guard::role_change_violation( $this->role_facts( $base + array( 'removes' => array( 'manage_options', 'install_plugins' ) ) ) );
		$this->assertSame( 'blt_toolkit_lockout_critical', $violation['code'] );
		$this->assertSame( 'SEC-12', $violation['rule'] );
		$this->assertSame( array( 'manage_options' ), $violation['caps'] );

		$violation = BLT_Toolkit_Guard::role_change_violation( $this->role_facts( $base + array( 'denies' => array( 'blt_edit_roles', 'read' ) ) ) );
		$this->assertSame( array( 'blt_edit_roles', 'read' ), $violation['caps'], 'Setting a lockout-critical capability to false is a removal.' );

		$this->assertNull( BLT_Toolkit_Guard::role_change_violation( $this->role_facts( $base + array( 'removes' => array( 'install_plugins' ) ) ) ) );

		$this->assertNull(
			BLT_Toolkit_Guard::role_change_violation(
				$this->role_facts(
					array(
						'actor_is_admin' => true,
						'role'           => 'editor',
						'removes'        => array( 'manage_options', 'read' ),
					)
				)
			),
			'The set only protects administrator.'
		);
	}

	/**
	 * SEC-1 for roles: a non-admin cannot edit administrator at all.
	 *
	 * @return void
	 */
	public function test_non_admin_cannot_edit_administrator_role() {
		$violation = BLT_Toolkit_Guard::role_change_violation(
			$this->role_facts(
				array(
					'role'   => 'administrator',
					'grants' => array( 'read' ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_protected_role', $violation['code'] );
	}

	/**
	 * SEC-5: a non-admin cannot change a role they hold.
	 *
	 * @return void
	 */
	public function test_sec5_no_editing_your_own_role() {
		$violation = BLT_Toolkit_Guard::role_change_violation(
			$this->role_facts(
				array(
					'role'    => 'manager',
					'removes' => array( 'read' ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_own_role', $violation['code'] );
		$this->assertSame( 'SEC-5', $violation['rule'] );

		$this->assertNull(
			BLT_Toolkit_Guard::role_change_violation(
				$this->role_facts(
					array(
						'actor_is_admin' => true,
						'actor_roles'    => array( 'administrator', 'manager' ),
						'role'           => 'manager',
						'removes'        => array( 'read' ),
					)
				)
			),
			'An administrator may edit a role they hold.'
		);
	}

	/**
	 * SEC-2 for roles (RO-4): a non-admin cannot edit a role whose
	 * capabilities exceed their own, even to remove something.
	 *
	 * @return void
	 */
	public function test_sec2_no_editing_a_role_more_powerful_than_you() {
		$violation = BLT_Toolkit_Guard::role_change_violation(
			$this->role_facts(
				array(
					'role'      => 'editor',
					'role_caps' => array_fill_keys( array( 'read', 'edit_posts', 'edit_others_posts', 'level_7' ), true ),
					'removes'   => array( 'edit_others_posts' ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_role_exceeds_actor', $violation['code'] );
		$this->assertSame( array( 'edit_others_posts', 'level_7' ), $violation['caps'] );
	}

	/**
	 * SEC-3 for roles: a non-admin cannot add a capability they lack.
	 *
	 * @return void
	 */
	public function test_sec3_role_grants() {
		$this->assertNull( BLT_Toolkit_Guard::role_change_violation( $this->role_facts( array( 'grants' => array( 'edit_posts' ) ) ) ) );

		$violation = BLT_Toolkit_Guard::role_change_violation( $this->role_facts( array( 'grants' => array( 'edit_posts', 'promote_users' ) ) ) );
		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', $violation['code'] );
		$this->assertSame( array( 'promote_users' ), $violation['caps'] );

		$this->assertNull(
			BLT_Toolkit_Guard::role_change_violation( $this->role_facts( array( 'denies' => array( 'edit_posts' ) ) ) ),
			'Denying a capability the actor holds, in a role no administrator holds, is allowed.'
		);

		$violation = BLT_Toolkit_Guard::role_change_violation( $this->role_facts( array( 'denies' => array( 'edit_posts', 'manage_options' ) ) ) );
		$this->assertSame( 'blt_toolkit_deny_exceeds_actor', $violation['code'], 'A deny of a capability the actor lacks takes it from people with more power.' );
		$this->assertSame( 'SEC-3', $violation['rule'] );
		$this->assertSame( array( 'manage_options' ), $violation['caps'] );
	}

	/**
	 * SEC-1 for role denies: a non-admin cannot deny anything in a role a
	 * toolkit admin also holds, because a later role's `false` overrides what
	 * `administrator` grants, where SEC-14 recovery cannot see it. Grants and
	 * removals there stay allowed; an administrator may deny.
	 *
	 * @return void
	 */
	public function test_sec1_no_denies_in_a_role_an_administrator_holds() {
		$violation = BLT_Toolkit_Guard::role_change_violation(
			$this->role_facts(
				array(
					'admin_holds_role' => true,
					'denies'           => array( 'read', 'list_users' ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_admin_holds_role', $violation['code'] );
		$this->assertSame( 'SEC-1', $violation['rule'] );
		$this->assertSame( array( 'list_users', 'read' ), $violation['caps'] );

		$this->assertNull(
			BLT_Toolkit_Guard::role_change_violation(
				$this->role_facts(
					array(
						'admin_holds_role' => true,
						'grants'           => array( 'edit_posts' ),
						'removes'          => array( 'read' ),
					)
				)
			),
			'A grant or a removal cannot take anything from the administrator.'
		);

		$this->assertNull(
			BLT_Toolkit_Guard::role_change_violation(
				$this->role_facts(
					array(
						'actor_is_admin'   => true,
						'actor_roles'      => array( 'administrator' ),
						'admin_holds_role' => true,
						'denies'           => array( 'manage_options' ),
					)
				)
			),
			'An administrator may deny in any role but their own lockout set.'
		);
	}

	/**
	 * SEC-3: removing a capability a role denies is a grant, because its
	 * holders get it back from their other roles.
	 *
	 * @return void
	 */
	public function test_sec3_removing_a_role_deny_is_a_grant() {
		$facts = $this->role_facts(
			array(
				'role_caps'   => array( 'read' => true ),
				'role_denied' => array( 'publish_posts' ),
				'removes'     => array( 'publish_posts' ),
			)
		);

		$violation = BLT_Toolkit_Guard::role_change_violation( $facts );
		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', $violation['code'] );
		$this->assertSame( array( 'publish_posts' ), $violation['caps'] );

		$facts['actor_caps']['publish_posts'] = true;
		$this->assertNull( BLT_Toolkit_Guard::role_change_violation( $facts ), 'An actor who holds it may lift the deny.' );

		$facts = $this->role_facts(
			array(
				'role_denied' => array( 'publish_posts' ),
				'removes'     => array( 'read' ),
			)
		);
		$this->assertNull( BLT_Toolkit_Guard::role_change_violation( $facts ), 'Removing a granted capability is not a grant.' );
	}

	/**
	 * Facts for a per-user capability change, with defaults that allow it.
	 *
	 * @param array $overrides Facts to change.
	 * @return array
	 */
	private function user_caps_facts( array $overrides = array() ) {
		return array_merge(
			array(
				'actor_exists'    => true,
				'target_exists'   => true,
				'actor_is_admin'  => false,
				'target_is_admin' => false,
				'same_user'       => false,
				'missing'         => array(),
				'actor_caps'      => array_fill_keys( array( 'read', 'level_0', 'edit_posts', 'delete_posts', 'blt_edit_capabilities' ), true ),
				'grants'          => array(),
				'denies'          => array(),
				'removes'         => array(),
				'target_denied'   => array(),
				'role_names'      => array( 'administrator', 'editor', 'subscriber' ),
			),
			$overrides
		);
	}

	/**
	 * SEC-3: a key named like a role is a role membership in a user's
	 * capabilities whatever its value (`administrator => false` still makes
	 * the user an administrator in WP_User::get_role_caps()), so a non-admin
	 * can never grant, deny or remove one there.
	 *
	 * @return void
	 */
	public function test_sec3_role_name_deny_is_refused() {
		foreach ( array(
			array( 'denies' => array( 'administrator' ) ),
			array( 'grants' => array( 'editor' ) ),
			array( 'removes' => array( 'subscriber' ) ),
		) as $change ) {
			$violation = BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( $change ) );

			$this->assertSame( 'blt_toolkit_role_name_cap', $violation['code'], wp_json_encode( $change ) );
			$this->assertSame( 'SEC-3', $violation['rule'] );
		}

		$violation = BLT_Toolkit_Guard::grant_violation(
			array(
				'actor_is_admin' => false,
				'actor_caps'     => array( 'read' => true ),
				'grants'         => array(),
				'denies'         => array( 'administrator' ),
				'role_names'     => array( 'administrator' ),
			)
		);
		$this->assertSame( 'blt_toolkit_role_name_cap', $violation['code'], 'can_grant() refuses a role-named deny too.' );

		$this->assertNull(
			BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'actor_is_admin' => true, 'denies' => array( 'administrator' ) ) ) ),
			'An administrator may change role keys (they may promote anyone anyway).'
		);
	}

	/**
	 * SEC-1, SEC-2 and SEC-5 for per-user capability edits: never on an
	 * administrator, a more powerful user, or oneself.
	 *
	 * @return void
	 */
	public function test_sec5_no_editing_your_own_capabilities() {
		$this->assertSame( 'blt_toolkit_own_caps', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'same_user' => true, 'grants' => array( 'read' ) ) ) )['code'] );
		$this->assertSame( 'SEC-5', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'same_user' => true ) ) )['rule'] );
		$this->assertSame( 'blt_toolkit_protected_admin', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'target_is_admin' => true ) ) )['code'] );
		$this->assertSame( 'blt_toolkit_target_exceeds_actor', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'missing' => array( 'publish_posts' ) ) ) )['code'] );
		$this->assertSame( 'blt_toolkit_no_user', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'target_exists' => false ) ) )['code'] );
		$this->assertNull( BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'grants' => array( 'edit_posts' ), 'denies' => array( 'delete_posts' ) ) ) ) );
		$this->assertNull( BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'actor_is_admin' => true, 'same_user' => true, 'grants' => array( 'manage_options' ) ) ) ), 'An administrator may edit anyone, themselves included.' );
	}

	/**
	 * SEC-3 for per-user edits: grant and deny only what you hold, and taking
	 * away a user's deny is a grant.
	 *
	 * @return void
	 */
	public function test_sec3_removing_a_user_deny_is_a_grant() {
		$violation = BLT_Toolkit_Guard::user_caps_violation(
			$this->user_caps_facts(
				array(
					'target_denied' => array( 'publish_posts', 'upload_files' ),
					'removes'       => array( 'publish_posts' ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', $violation['code'] );
		$this->assertSame( array( 'publish_posts' ), $violation['caps'] );

		$this->assertNull(
			BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'target_denied' => array( 'edit_posts' ), 'removes' => array( 'edit_posts' ) ) ) ),
			'Lifting a deny of a capability the actor holds is fine.'
		);

		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'grants' => array( 'publish_posts' ) ) ) )['code'] );
		$this->assertSame( 'blt_toolkit_deny_exceeds_actor', BLT_Toolkit_Guard::user_caps_violation( $this->user_caps_facts( array( 'denies' => array( 'manage_options' ) ) ) )['code'] );
	}

	/**
	 * SEC-1: a capability check's target argument that is not a user ID
	 * fails closed (-1), because (int) and absint() would still turn it into
	 * one; 0 and a missing argument mean "no target".
	 *
	 * @return void
	 */
	public function test_sec1_unparseable_target_fails_closed() {
		foreach ( array( '1abc', true, array( 1 ), -1, '-1', INF, NAN, new stdClass() ) as $arg ) {
			$this->assertSame( -1, BLT_Toolkit_Guard::target_id( array( $arg ) ), var_export( $arg, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test message.
		}

		foreach ( array(
			array( 1, 1 ),
			array( '1', 1 ),
			array( '+1', 1 ),
			array( '01', 1 ),
			array( '1e0', 1 ),
			array( 1.5, 1 ),
			array( 42, 42 ),
			array( 0, 0 ),
			array( '0', 0 ),
		) as $case ) {
			$this->assertSame( $case[1], BLT_Toolkit_Guard::target_id( array( $case[0] ) ), var_export( $case[0], true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test message.
		}

		$this->assertSame( 0, BLT_Toolkit_Guard::target_id( array() ) );
		$this->assertSame( 0, BLT_Toolkit_Guard::target_id( array( null ) ) );
	}

	/**
	 * SEC-13: losing your own blt_edit_roles needs another toolkit admin and
	 * a confirmation.
	 *
	 * @return void
	 */
	public function test_sec13_self_lockout() {
		$facts = $this->role_facts(
			array(
				'actor_is_admin'   => true,
				'actor_roles'      => array( 'administrator', 'helper' ),
				'denies'           => array( 'blt_edit_roles' ),
				'loses_edit_roles' => true,
			)
		);

		$violation = BLT_Toolkit_Guard::role_change_violation( $facts );
		$this->assertSame( 'blt_toolkit_self_lockout', $violation['code'] );
		$this->assertSame( 'SEC-13', $violation['rule'] );
		$this->assertFalse( $violation['confirmable'], 'No other administrator: confirming cannot help.' );

		$facts['other_admin'] = true;
		$violation            = BLT_Toolkit_Guard::role_change_violation( $facts );
		$this->assertTrue( $violation['confirmable'] );

		$facts['confirmed'] = true;
		$this->assertNull( BLT_Toolkit_Guard::role_change_violation( $facts ) );

		$facts['other_admin'] = false;
		$this->assertSame( 'blt_toolkit_self_lockout', BLT_Toolkit_Guard::role_change_violation( $facts )['code'], 'Confirming alone is not enough.' );
	}

	/**
	 * SEC-10: which grants need the Critical review step.
	 *
	 * @return void
	 */
	public function test_sec10_requires_critical_review() {
		$this->assertSame(
			array( 'blt_switch_users', 'edit_users', 'manage_options' ),
			BLT_Toolkit_Guard::requires_critical_review( array( 'read', 'manage_options', 'list_users', 'edit_users', 'blt_switch_users', 'blt_change_own_username' ) )
		);

		$this->assertSame(
			array( 'install_plugins' ),
			BLT_Toolkit_Guard::requires_critical_review(
				array(
					'install_plugins' => true,
					'manage_options'  => false,
					'edit_posts'      => true,
				)
			),
			'A deny is not a grant.'
		);

		$this->assertSame( array(), BLT_Toolkit_Guard::requires_critical_review( array( 'read', 'edit_others_posts' ) ) );
	}

	/**
	 * SEC-14: recovery restores the lockout-critical set and every BLT
	 * Toolkit capability, and nothing of other BLT plugins.
	 *
	 * @return void
	 */
	public function test_sec14_recovery_caps() {
		$caps = BLT_Toolkit_Guard::recovery_caps();

		foreach ( array( 'read', 'manage_options', 'list_users', 'edit_users', 'promote_users', 'activate_plugins' ) as $cap ) {
			$this->assertContains( $cap, $caps );
		}

		foreach ( array_keys( BLT_Toolkit_Caps::own() ) as $cap ) {
			$this->assertContains( $cap, $caps );
		}

		$this->assertNotContains( 'blt_view_activity_log', $caps );
		$this->assertSame( count( $caps ), count( array_unique( $caps ) ) );

		add_filter(
			'blt_toolkit_lockout_critical_caps',
			function ( $list ) {
				$list[] = 'manage_woocommerce';

				return $list;
			}
		);

		$this->assertContains( 'manage_woocommerce', BLT_Toolkit_Guard::recovery_caps() );
	}

	/**
	 * Violations become WP_Errors with the same code and a readable message.
	 *
	 * @return void
	 */
	public function test_error_messages() {
		$error = BLT_Toolkit_Guard::error( BLT_Toolkit_Guard::user_action_violation( array( 'actor_exists' => true ) ) );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 'blt_toolkit_no_user', $error->get_error_code() );
		$this->assertSame( 'That user does not exist.', $error->get_error_message() );

		$error = BLT_Toolkit_Guard::error(
			BLT_Toolkit_Guard::role_change_violation(
				$this->role_facts(
					array(
						'actor_is_admin' => true,
						'role'           => 'administrator',
						'removes'        => array( 'manage_options', 'read' ),
					)
				)
			)
		);
		$this->assertSame( 'blt_toolkit_lockout_critical', $error->get_error_code() );
		$this->assertStringContainsString( 'manage_options, read', $error->get_error_message() );

		$this->assertSame( 'You do not have permission to do that.', BLT_Toolkit_Guard::error( array( 'code' => 'anything_else' ) )->get_error_message() );
	}
}
