<?php
/**
 * Role lifecycle rules (RO-30, RO-40, RO-41, SEC-3) with known vectors.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure parts of BLT_Toolkit_Role_Lifecycle.
 */
class Test_Role_Lifecycle extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * Roles of a small site: the built-in ones and two custom roles.
	 *
	 * @return array
	 */
	private function roles() {
		$templates = BLT_Toolkit_Role_Lifecycle::templates();

		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array_merge(
					$templates['editor'],
					array(
						'manage_options'   => true,
						'promote_users'    => true,
						'blt_create_roles' => true,
					)
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => $templates['editor'],
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => $templates['subscriber'],
			),
			'helper'        => array(
				'name'         => 'Helper',
				'capabilities' => array(
					'read'       => true,
					'edit_posts' => true,
				),
			),
			'muted'         => array(
				'name'         => 'Muted',
				'capabilities' => array(
					'read'       => true,
					'edit_posts' => false,
				),
			),
		);
	}

	// ---------- RO-30: role ID suggestion ----------

	/**
	 * RO-30: the ID comes from the display name through sanitize_key(), with
	 * spaces as underscores.
	 *
	 * @return void
	 */
	public function test_ro30_suggest_id_from_display_name() {
		$this->assertSame( 'shop_manager', BLT_Toolkit_Role_Lifecycle::suggest_id( 'Shop Manager' ) );
		$this->assertSame( 'shop_manager', BLT_Toolkit_Role_Lifecycle::suggest_id( '  Shop   Manager  ' ) );
		$this->assertSame( 'seo-editor', BLT_Toolkit_Role_Lifecycle::suggest_id( 'SEO-Editor' ) );
		$this->assertSame( 'events_team_2024', BLT_Toolkit_Role_Lifecycle::suggest_id( 'Events team (2024)!' ) );
	}

	/**
	 * RO-30: a name with no letter gives no suggestion (RO-23: an ID must
	 * contain a letter).
	 *
	 * @return void
	 */
	public function test_ro30_suggest_id_needs_a_letter() {
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::suggest_id( '' ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::suggest_id( '2024' ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::suggest_id( '!!! ???' ) );
	}

	/**
	 * RO-30: a taken or reserved suggestion gets a numbered suffix, compared
	 * without case.
	 *
	 * @return void
	 */
	public function test_ro30_suggest_id_avoids_taken_and_reserved_names() {
		$this->assertSame( 'helper_2', BLT_Toolkit_Role_Lifecycle::suggest_id( 'Helper', array( 'helper' ) ) );
		$this->assertSame( 'helper_3', BLT_Toolkit_Role_Lifecycle::suggest_id( 'Helper', array( 'Helper', 'helper_2' ) ) );
		$this->assertSame( 'read_2', BLT_Toolkit_Role_Lifecycle::suggest_id( 'Read', array(), array( 'read' ) ) );
	}

	/**
	 * RO-30: a suggestion is at most 64 characters, suffix included.
	 *
	 * @return void
	 */
	public function test_ro30_suggest_id_is_cut_to_64() {
		$long = str_repeat( 'a', 80 );
		$id   = BLT_Toolkit_Role_Lifecycle::suggest_id( $long );

		$this->assertSame( 64, strlen( $id ) );

		$suffixed = BLT_Toolkit_Role_Lifecycle::suggest_id( $long, array( $id ) );

		$this->assertSame( 64, strlen( $suffixed ) );
		$this->assertSame( '_2', substr( $suffixed, -2 ) );
	}

	// ---------- RO-30: role ID validation (RO-23 rules, max 64) ----------

	/**
	 * RO-30 / RO-23: lowercase letters, digits, underscores and hyphens only.
	 *
	 * @return void
	 */
	public function test_ro30_id_allows_only_lowercase_digits_underscore_hyphen() {
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::id_error( 'shop_manager-2', array(), array() ) );
		$this->assertSame( 'chars', BLT_Toolkit_Role_Lifecycle::id_error( 'Shop_Manager', array(), array() ) );
		$this->assertSame( 'chars', BLT_Toolkit_Role_Lifecycle::id_error( 'shop manager', array(), array() ) );
		$this->assertSame( 'chars', BLT_Toolkit_Role_Lifecycle::id_error( 'shop.manager', array(), array() ) );
		$this->assertSame( 'chars', BLT_Toolkit_Role_Lifecycle::id_error( "shop\nmanager", array(), array() ) );
		$this->assertSame( 'chars', BLT_Toolkit_Role_Lifecycle::id_error( 'café', array(), array() ) );
	}

	/**
	 * RO-30 / RO-23: empty, letterless and over-long IDs are refused.
	 *
	 * @return void
	 */
	public function test_ro30_id_needs_a_letter_and_at_most_64_characters() {
		$this->assertSame( 'empty', BLT_Toolkit_Role_Lifecycle::id_error( '', array(), array() ) );
		$this->assertSame( 'letter', BLT_Toolkit_Role_Lifecycle::id_error( '2024', array(), array() ) );
		$this->assertSame( 'letter', BLT_Toolkit_Role_Lifecycle::id_error( '_-_', array(), array() ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::id_error( str_repeat( 'a', 64 ), array(), array() ) );
		$this->assertSame( 'length', BLT_Toolkit_Role_Lifecycle::id_error( str_repeat( 'a', 65 ), array(), array() ) );
	}

	/**
	 * RO-30 / RO-23: an existing role ID is refused, compared without case.
	 *
	 * @return void
	 */
	public function test_ro30_id_must_not_exist() {
		$this->assertSame( 'exists', BLT_Toolkit_Role_Lifecycle::id_error( 'editor', array( 'editor' ), array() ) );
		$this->assertSame( 'exists', BLT_Toolkit_Role_Lifecycle::id_error( 'shop_manager', array( 'Shop_Manager' ), array() ) );
	}

	/**
	 * RO-30 / RO-23: an ID that names a capability or a meta capability is
	 * refused, because WordPress would read it as a role membership wherever
	 * that capability is given to a user.
	 *
	 * @return void
	 */
	public function test_ro30_id_must_not_name_a_capability() {
		$reserved = array_merge( BLT_Toolkit_Role_Lifecycle::META_CAPS, array( 'read', 'edit_posts' ) );

		$this->assertSame( 'reserved', BLT_Toolkit_Role_Lifecycle::id_error( 'read', array(), $reserved ) );
		$this->assertSame( 'reserved', BLT_Toolkit_Role_Lifecycle::id_error( 'edit_post', array(), $reserved ) );
		$this->assertSame( 'reserved', BLT_Toolkit_Role_Lifecycle::id_error( 'promote_user', array(), $reserved ) );
		$this->assertSame( 'reserved', BLT_Toolkit_Role_Lifecycle::id_error( 'blt_switch_to_user', array(), $reserved ) );
		$this->assertSame( 'reserved', BLT_Toolkit_Role_Lifecycle::id_error( 'do_not_allow', array(), $reserved ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::id_error( 'reader', array(), $reserved ) );
	}

	/**
	 * RO-30: every id_error() code has a message.
	 *
	 * @return void
	 */
	public function test_ro30_every_id_code_has_a_message() {
		foreach ( array( 'empty', 'chars', 'letter', 'length', 'exists', 'reserved' ) as $code ) {
			$this->assertNotSame( '', BLT_Toolkit_Role_Lifecycle::id_message( $code ), $code );
		}
	}

	// ---------- RO-30, RO-31: display names ----------

	/**
	 * RO-30 / RO-31: a display name is required, at most 100 characters, and
	 * not another role's name (compared without case); a role's own name
	 * does not count against a rename.
	 *
	 * @return void
	 */
	public function test_ro31_display_name_rules() {
		$names = array(
			'editor' => 'Editor',
			'helper' => 'Helper',
		);

		$this->assertSame( 'empty', BLT_Toolkit_Role_Lifecycle::name_error( '   ', $names ) );
		$this->assertSame( 'length', BLT_Toolkit_Role_Lifecycle::name_error( str_repeat( 'x', 101 ), $names ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::name_error( str_repeat( 'é', 100 ), $names ) );
		$this->assertSame( 'exists', BLT_Toolkit_Role_Lifecycle::name_error( 'editor', $names ) );
		$this->assertSame( 'exists', BLT_Toolkit_Role_Lifecycle::name_error( ' HELPER ', $names ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::name_error( 'helper', $names, 'helper' ) );
		$this->assertSame( 'exists', BLT_Toolkit_Role_Lifecycle::name_error( 'Editor', $names, 'helper' ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::name_error( 'Shop Manager', $names ) );
	}

	// ---------- RO-40: deletable roles ----------

	/**
	 * RO-40: built-in roles and administrator are never deletable.
	 *
	 * @return void
	 */
	public function test_ro40_built_in_and_administrator_never_deletable() {
		$facts = array(
			'exists'         => true,
			'default_role'   => 'helper',
			'extra_defaults' => array(),
		);

		$this->assertSame( 'administrator', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'administrator', $facts ) );

		foreach ( array( 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
			$this->assertSame( 'built_in', BLT_Toolkit_Role_Lifecycle::undeletable_reason( $role, $facts ), $role );
		}
	}

	/**
	 * RO-40: the default role and the additional default roles are never
	 * deletable; any other custom role is.
	 *
	 * @return void
	 */
	public function test_ro40_default_and_additional_default_roles_never_deletable() {
		$facts = array(
			'exists'         => true,
			'default_role'   => 'member',
			'extra_defaults' => array( 'newsletter', 'forum' ),
		);

		$this->assertSame( 'default', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'member', $facts ) );
		$this->assertSame( 'extra_default', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'newsletter', $facts ) );
		$this->assertSame( 'extra_default', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'forum', $facts ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'helper', $facts ) );
		$this->assertSame( '', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'Helper', $facts ) );
	}

	/**
	 * RO-40: a role that does not exist is reported as missing first.
	 *
	 * @return void
	 */
	public function test_ro40_missing_role() {
		$this->assertSame( 'missing', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'helper', array( 'exists' => false ) ) );
		$this->assertSame( 'missing', BLT_Toolkit_Role_Lifecycle::undeletable_reason( 'administrator', array() ) );
	}

	/**
	 * RO-40: the built-in default roles are still "built in" when they are
	 * also the default role, and damaged settings read as no extra roles.
	 *
	 * @return void
	 */
	public function test_ro40_reason_order_and_damaged_facts() {
		$this->assertSame(
			'built_in',
			BLT_Toolkit_Role_Lifecycle::undeletable_reason(
				'subscriber',
				array(
					'exists'         => true,
					'default_role'   => 'subscriber',
					'extra_defaults' => array( 'subscriber' ),
				)
			)
		);

		$this->assertSame(
			'',
			BLT_Toolkit_Role_Lifecycle::undeletable_reason(
				'helper',
				array(
					'exists'         => true,
					'default_role'   => null,
					'extra_defaults' => 'helper',
				)
			)
		);
	}

	// ---------- RO-30 / SEC-3: start options ----------

	/**
	 * RO-30: the templates are the WordPress defaults, Blank holds `read`
	 * only, and each builds on the one before.
	 *
	 * @return void
	 */
	public function test_ro30_templates_are_wordpress_defaults() {
		$templates = BLT_Toolkit_Role_Lifecycle::templates();

		$this->assertSame( array( 'blank', 'subscriber', 'contributor', 'author', 'editor' ), array_keys( $templates ) );
		$this->assertSame( array( 'read' => true ), $templates['blank'] );
		$this->assertSame(
			array(
				'level_0' => true,
				'read'    => true,
			),
			$templates['subscriber']
		);
		$this->assertSame( array( 'delete_posts', 'edit_posts', 'level_0', 'level_1', 'read' ), array_keys( $templates['contributor'] ) );
		$this->assertCount( 10, $templates['author'] );
		$this->assertCount( 34, $templates['editor'] );
		$this->assertArrayHasKey( 'unfiltered_html', $templates['editor'] );
		$this->assertArrayNotHasKey( 'manage_options', $templates['editor'] );

		foreach ( array( 'subscriber' => 'blank', 'contributor' => 'subscriber', 'author' => 'contributor', 'editor' => 'author' ) as $key => $smaller ) {
			$this->assertSame( array(), array_diff_key( $templates[ $smaller ], $templates[ $key ] ), $key );
		}
	}

	/**
	 * RO-30: a toolkit admin is offered every template and a copy of every
	 * role, administrator included.
	 *
	 * @return void
	 */
	public function test_ro30_admin_gets_every_start_option() {
		$options = BLT_Toolkit_Role_Lifecycle::start_options( $this->roles(), array(), true, array_keys( $this->roles() ) );

		$this->assertSame(
			array( 'blank', 'subscriber', 'contributor', 'author', 'editor', 'copy:administrator', 'copy:editor', 'copy:subscriber', 'copy:helper', 'copy:muted' ),
			array_keys( $options )
		);
		$this->assertSame( 'template', $options['editor']['kind'] );
		$this->assertSame( 'copy', $options['copy:helper']['kind'] );
		$this->assertSame( 'helper', $options['copy:helper']['role'] );
		$this->assertSame( 'Helper', $options['copy:helper']['name'] );
		$this->assertSame(
			array(
				'read'       => true,
				'edit_posts' => true,
			),
			$options['copy:helper']['caps']
		);
	}

	/**
	 * SEC-3 / URE-3: a non-admin is offered only the starting points whose
	 * capabilities they hold, never a copy of administrator.
	 *
	 * @return void
	 */
	public function test_sec3_start_options_filtered_by_subset() {
		$actor = array(
			'read'             => true,
			'edit_posts'       => true,
			'level_0'          => true,
			'blt_edit_roles'   => true,
			'blt_create_roles' => true,
		);

		$options = BLT_Toolkit_Role_Lifecycle::start_options( $this->roles(), $actor, false, array_keys( $this->roles() ) );

		$this->assertSame( array( 'blank', 'subscriber', 'copy:subscriber', 'copy:helper', 'copy:muted' ), array_keys( $options ) );
	}

	/**
	 * SEC-3: user levels count for a grant, so an actor without `level_0`
	 * cannot start from Subscriber (decided policy: levels still count for
	 * SEC-3 grants).
	 *
	 * @return void
	 */
	public function test_sec3_levels_count_when_copying() {
		$actor = array(
			'read'           => true,
			'blt_edit_roles' => true,
		);

		$options = BLT_Toolkit_Role_Lifecycle::start_options( $this->roles(), $actor, false, array_keys( $this->roles() ) );

		$this->assertSame( array( 'blank' ), array_keys( $options ) );
	}

	/**
	 * SEC-3: a denied capability in a copied role counts: a non-admin may
	 * deny only what they hold.
	 *
	 * @return void
	 */
	public function test_sec3_denies_in_a_copied_role_count() {
		$actor = array( 'read' => true );

		$this->assertNull( BLT_Toolkit_Role_Lifecycle::copy_violation( array( 'read' => true ), $actor, array() ) );

		$violation = BLT_Toolkit_Role_Lifecycle::copy_violation(
			array(
				'read'       => true,
				'edit_posts' => false,
			),
			$actor,
			array()
		);

		$this->assertSame( 'blt_toolkit_deny_exceeds_actor', $violation['code'] );
		$this->assertSame( array( 'edit_posts' ), $violation['caps'] );
	}

	/**
	 * SEC-3: a copied role carrying a key named like a role is refused for a
	 * non-admin, even when its value is false.
	 *
	 * @return void
	 */
	public function test_sec3_role_named_key_in_a_copy_is_refused() {
		$violation = BLT_Toolkit_Role_Lifecycle::copy_violation(
			array(
				'read'          => true,
				'administrator' => false,
			),
			array( 'read' => true ),
			array( 'administrator', 'editor' )
		);

		$this->assertSame( 'blt_toolkit_role_name_cap', $violation['code'] );
	}

	/**
	 * SEC-3 / URE-3: an actor holding every administrator capability is still
	 * not offered a copy of administrator (SEC-1, as editable_roles).
	 *
	 * @return void
	 */
	public function test_sec3_administrator_copy_never_offered_to_non_admin() {
		$roles = $this->roles();
		$actor = array_fill_keys( array_keys( $roles['administrator']['capabilities'] ), true );

		$options = BLT_Toolkit_Role_Lifecycle::start_options( $roles, $actor, false, array_keys( $roles ) );

		$this->assertArrayNotHasKey( 'copy:administrator', $options );
		$this->assertArrayHasKey( 'copy:editor', $options );
		$this->assertArrayHasKey( 'editor', $options );
	}

	// ---------- RO-41: reassigning one user ----------

	/**
	 * RO-41: a user whose only role is emptied gets the replacement.
	 *
	 * @return void
	 */
	public function test_ro41_only_role_is_replaced() {
		$this->assertSame(
			array(
				'op'    => 'set',
				'roles' => array( 'subscriber' ),
			),
			BLT_Toolkit_Role_Lifecycle::plan_user( array( 'helper' ), 'helper', 'subscriber', BLT_Toolkit_Role_Lifecycle::MODE_REPLACE )
		);
	}

	/**
	 * RO-41: a user with other roles simply loses the role.
	 *
	 * @return void
	 */
	public function test_ro41_user_with_other_roles_just_loses_it() {
		$this->assertSame(
			array(
				'op'    => 'remove',
				'roles' => array( 'author', 'shop' ),
			),
			BLT_Toolkit_Role_Lifecycle::plan_user( array( 'author', 'helper', 'shop' ), 'helper', 'subscriber', BLT_Toolkit_Role_Lifecycle::MODE_REPLACE )
		);
	}

	/**
	 * RO-41: a user who no longer holds the role is skipped, so a batch is
	 * safe to repeat.
	 *
	 * @return void
	 */
	public function test_ro41_repeat_is_a_no_op() {
		$this->assertSame(
			array(
				'op'    => 'skip',
				'roles' => array( 'subscriber' ),
			),
			BLT_Toolkit_Role_Lifecycle::plan_user( array( 'subscriber' ), 'helper', 'subscriber', BLT_Toolkit_Role_Lifecycle::MODE_REPLACE )
		);
	}

	/**
	 * RO-41: with no replacement, a user whose only role it is cannot be
	 * changed, and one with other roles still loses it.
	 *
	 * @return void
	 */
	public function test_ro41_no_replacement() {
		$this->assertSame( 'blocked', BLT_Toolkit_Role_Lifecycle::plan_user( array( 'helper' ), 'helper', '', BLT_Toolkit_Role_Lifecycle::MODE_REPLACE )['op'] );
		$this->assertSame( 'blocked', BLT_Toolkit_Role_Lifecycle::plan_user( array( 'helper' ), 'helper', 'helper', BLT_Toolkit_Role_Lifecycle::MODE_MOVE )['op'] );
		$this->assertSame( 'remove', BLT_Toolkit_Role_Lifecycle::plan_user( array( 'helper', 'author' ), 'helper', '', BLT_Toolkit_Role_Lifecycle::MODE_MOVE )['op'] );
	}

	/**
	 * RO-32 / RO-42 (move mode): the target takes the role's place, keeping
	 * its position, and a user already holding the target just loses the
	 * role.
	 *
	 * @return void
	 */
	public function test_ro42_move_mode_swaps_in_place() {
		$this->assertSame(
			array(
				'op'    => 'swap',
				'roles' => array( 'author', 'editor', 'shop' ),
			),
			BLT_Toolkit_Role_Lifecycle::plan_user( array( 'author', 'helper', 'shop' ), 'helper', 'editor', BLT_Toolkit_Role_Lifecycle::MODE_MOVE )
		);

		$this->assertSame(
			array(
				'op'    => 'remove',
				'roles' => array( 'editor' ),
			),
			BLT_Toolkit_Role_Lifecycle::plan_user( array( 'helper', 'editor' ), 'helper', 'editor', BLT_Toolkit_Role_Lifecycle::MODE_MOVE )
		);

		$this->assertSame( 'set', BLT_Toolkit_Role_Lifecycle::plan_user( array( 'helper' ), 'helper', 'editor', BLT_Toolkit_Role_Lifecycle::MODE_MOVE )['op'] );
	}

	/**
	 * RO-41: holders are split into batches of BATCH users, each numbered
	 * from 1, duplicates and zero IDs dropped.
	 *
	 * @return void
	 */
	public function test_ro41_batches() {
		$ids     = range( 1, 120 );
		$batches = BLT_Toolkit_Role_Lifecycle::batches( 'helper', $ids, 50 );

		$this->assertCount( 3, $batches );
		$this->assertSame( 1, $batches[0]['from'] );
		$this->assertSame( 51, $batches[1]['from'] );
		$this->assertSame( 101, $batches[2]['from'] );
		$this->assertCount( 20, $batches[2]['users'] );
		$this->assertSame( 120, $batches[2]['of'] );
		$this->assertSame( 'helper', $batches[1]['role'] );

		$this->assertSame(
			array(
				array(
					'role'  => 'helper',
					'users' => array( 3, 1 ),
					'from'  => 1,
					'of'    => 2,
				),
			),
			BLT_Toolkit_Role_Lifecycle::batches( 'helper', array( 3, 0, 1, 3, '1' ), 50 )
		);

		$this->assertSame( array(), BLT_Toolkit_Role_Lifecycle::batches( 'helper', array() ) );
	}

	/**
	 * SEC-12: nobody takes `administrator` away from themselves through a
	 * move, merge or reassign; other people, and roles other than
	 * administrator, are not affected by this rule.
	 *
	 * @return void
	 */
	public function test_sec12_drops_own_admin() {
		$this->assertTrue( BLT_Toolkit_Role_Lifecycle::drops_own_admin( true, array( 'administrator' ), array( 'admins_copy' ) ) );
		$this->assertTrue( BLT_Toolkit_Role_Lifecycle::drops_own_admin( true, array( 'editor', 'administrator' ), array( 'editor' ) ) );
		$this->assertFalse( BLT_Toolkit_Role_Lifecycle::drops_own_admin( false, array( 'administrator' ), array( 'admins_copy' ) ) );
		$this->assertFalse( BLT_Toolkit_Role_Lifecycle::drops_own_admin( true, array( 'administrator', 'helper' ), array( 'administrator', 'editor' ) ) );
		$this->assertFalse( BLT_Toolkit_Role_Lifecycle::drops_own_admin( true, array( 'helper' ), array( 'editor' ) ) );
	}
}
