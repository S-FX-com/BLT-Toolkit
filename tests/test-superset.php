<?php
/**
 * Superset semantics (SEC-8) with known vectors.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Superset.
 */
class Test_Superset extends TestCase {

	/**
	 * Role names of a default WordPress site.
	 *
	 * @var string[]
	 */
	private $roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * SEC-8: only values that grant count, exactly as WP_User::has_cap()
	 * reads them (not empty()).
	 *
	 * @return void
	 */
	public function test_sec8_only_granting_values_count() {
		$caps = array(
			'bool_true'    => true,
			'int_one'      => 1,
			'string_one'   => '1',
			'bool_false'   => false,
			'int_zero'     => 0,
			'string_zero'  => '0',
			'empty_string' => '',
			'null_value'   => null,
		);

		$this->assertSame(
			array(
				'bool_true'  => true,
				'int_one'    => true,
				'string_one' => true,
			),
			BLT_Toolkit_Superset::normalize( $caps )
		);
	}

	/**
	 * SEC-8: `do_not_allow` is never held, whatever its value.
	 *
	 * @return void
	 */
	public function test_sec8_do_not_allow_is_ignored() {
		$this->assertSame(
			array( 'read' => true ),
			BLT_Toolkit_Superset::normalize(
				array(
					'do_not_allow' => true,
					'read'         => true,
				)
			)
		);
	}

	/**
	 * SEC-8: keys that are role names are role memberships, not capabilities.
	 * A key is only treated as a role when it is in the list passed in.
	 *
	 * @return void
	 */
	public function test_sec8_role_name_keys_are_ignored() {
		$allcaps = array(
			'edit_posts'    => true,
			'editor'        => true,
			'administrator' => true,
			'shop_manager'  => true,
		);

		$this->assertSame(
			array(
				'edit_posts'   => true,
				'shop_manager' => true,
			),
			BLT_Toolkit_Superset::normalize( $allcaps, $this->roles ),
			'shop_manager is not a registered role here, so it is a capability.'
		);

		$this->assertSame(
			array(
				'administrator' => true,
				'edit_posts'    => true,
				'editor'        => true,
				'shop_manager'  => true,
			),
			BLT_Toolkit_Superset::normalize( $allcaps ),
			'Without role names nothing is dropped.'
		);
	}

	/**
	 * SEC-8: a capability set `false` on the target does not count as held,
	 * so an actor who lacks it still covers the target.
	 *
	 * @return void
	 */
	public function test_sec8_false_on_target_is_not_held() {
		// An editor whose own capabilities deny publish_posts: WP_User merges
		// user caps over role caps, so allcaps carries the false.
		$target = BLT_Toolkit_Superset::normalize(
			array(
				'read'          => true,
				'edit_posts'    => true,
				'publish_posts' => false,
				'editor'        => true,
			),
			$this->roles
		);
		$actor  = BLT_Toolkit_Superset::normalize(
			array(
				'read'       => true,
				'edit_posts' => true,
				'manager'    => true,
			),
			array_merge( $this->roles, array( 'manager' ) )
		);

		$this->assertTrue( BLT_Toolkit_Superset::is_subset( $actor, $target ) );
		$this->assertSame( array(), BLT_Toolkit_Superset::missing( $actor, $target ) );
	}

	/**
	 * SEC-8: a capability set `false` on the actor is not held by them.
	 *
	 * @return void
	 */
	public function test_sec8_false_on_actor_is_not_held() {
		$actor  = BLT_Toolkit_Superset::normalize(
			array(
				'read'         => true,
				'list_users'   => true,
				'delete_users' => false,
			)
		);
		$target = BLT_Toolkit_Superset::normalize(
			array(
				'read'         => true,
				'delete_users' => true,
			)
		);

		$this->assertFalse( BLT_Toolkit_Superset::is_subset( $actor, $target ) );
		$this->assertSame( array( 'delete_users' ), BLT_Toolkit_Superset::missing( $actor, $target ) );
	}

	/**
	 * SEC-2: the subset relation itself.
	 *
	 * @return void
	 */
	public function test_sec2_subset_relation() {
		$manager    = array_fill_keys( array( 'read', 'list_users', 'edit_users', 'create_users', 'delete_users', 'promote_users' ), true );
		$subscriber = array_fill_keys( array( 'read' ), true );
		$equal      = $manager;
		$more       = $manager + array( 'manage_options' => true );

		$this->assertTrue( BLT_Toolkit_Superset::is_subset( $manager, array() ), 'A user with no capabilities is within anyone.' );
		$this->assertTrue( BLT_Toolkit_Superset::is_subset( $manager, $subscriber ) );
		$this->assertTrue( BLT_Toolkit_Superset::is_subset( $manager, $equal ), 'Equal sets are subsets.' );
		$this->assertFalse( BLT_Toolkit_Superset::is_subset( $manager, $more ) );
		$this->assertFalse( BLT_Toolkit_Superset::is_subset( array(), $subscriber ) );
		$this->assertSame( array( 'manage_options' ), BLT_Toolkit_Superset::missing( $manager, $more ) );
	}

	/**
	 * SEC-8: roles compare the same way as users. The legacy `level_*`
	 * capabilities count like any other: a custom role without `level_0`
	 * does not cover Subscriber.
	 *
	 * @return void
	 */
	public function test_sec8_roles_compare_the_same_way() {
		$editor = array(
			'moderate_comments'      => true,
			'manage_categories'      => true,
			'edit_others_posts'      => true,
			'edit_posts'             => true,
			'read'                   => true,
			'level_7'                => true,
			'level_1'                => true,
			'level_0'                => true,
			'edit_private_dashboard' => false,
		);
		$author = array(
			'edit_posts' => true,
			'read'       => true,
			'level_1'    => true,
			'level_0'    => true,
		);
		$custom = array(
			'read'          => true,
			'list_users'    => true,
			'promote_users' => true,
		);

		$editor_n = BLT_Toolkit_Superset::normalize( $editor, $this->roles );
		$author_n = BLT_Toolkit_Superset::normalize( $author, $this->roles );
		$custom_n = BLT_Toolkit_Superset::normalize( $custom, $this->roles );

		$this->assertTrue( BLT_Toolkit_Superset::is_subset( $editor_n, $author_n ) );
		$this->assertFalse( BLT_Toolkit_Superset::is_subset( $author_n, $editor_n ) );
		$this->assertSame( array( 'edit_others_posts', 'level_7', 'manage_categories', 'moderate_comments' ), BLT_Toolkit_Superset::missing( $author_n, $editor_n ) );
		$this->assertSame(
			array( 'level_0' ),
			BLT_Toolkit_Superset::missing(
				$custom_n,
				BLT_Toolkit_Superset::normalize(
					array(
						'read'    => true,
						'level_0' => true,
					)
				)
			)
		);
	}

	/**
	 * SEC-8 on a realistic `WP_User::$allcaps`: two roles (the later one's
	 * `false` already merged in), a user-level grant, a user-level deny and
	 * the role keys from the capabilities meta.
	 *
	 * @return void
	 */
	public function test_sec8_realistic_allcaps() {
		$allcaps = array(
			'read'              => true,
			'edit_posts'        => true,
			'upload_files'      => false,
			'level_1'           => true,
			'level_0'           => true,
			'author'            => true,
			'contributor'       => true,
			'list_users'        => true,
			'moderate_comments' => '1',
			'delete_posts'      => 0,
		);

		$this->assertSame(
			array(
				'edit_posts'        => true,
				'level_0'           => true,
				'level_1'           => true,
				'list_users'        => true,
				'moderate_comments' => true,
				'read'              => true,
			),
			BLT_Toolkit_Superset::normalize( $allcaps, $this->roles )
		);
	}

	/**
	 * The comparison reads values by whether they grant even on raw input,
	 * so a raw target with denied capabilities or `do_not_allow` is not made
	 * to look stronger, and an actor's `false` is never "held".
	 *
	 * @return void
	 */
	public function test_comparison_tolerates_raw_values() {
		$actor  = array(
			'read'         => true,
			'edit_posts'   => false,
			'do_not_allow' => true,
		);
		$target = array(
			'read'         => '1',
			'edit_users'   => false,
			'do_not_allow' => true,
		);

		$this->assertTrue( BLT_Toolkit_Superset::is_subset( $actor, $target ) );
		$this->assertFalse( BLT_Toolkit_Superset::is_subset( $actor, array( 'edit_posts' => true ) ) );
		$this->assertSame( array( 'edit_posts' ), BLT_Toolkit_Superset::missing( $actor, array( 'edit_posts' => 1 ) ) );
	}

	/**
	 * Normalizing sorts by name and is idempotent; missing() is sorted.
	 *
	 * @return void
	 */
	public function test_normalize_is_sorted_and_idempotent() {
		$once = BLT_Toolkit_Superset::normalize(
			array(
				'zeta'  => true,
				'alpha' => 1,
				'mid'   => '1',
			)
		);

		$this->assertSame( array( 'alpha', 'mid', 'zeta' ), array_keys( $once ) );
		$this->assertSame( $once, BLT_Toolkit_Superset::normalize( $once ) );
		$this->assertSame( array( 'b', 'c' ), BLT_Toolkit_Superset::missing( array( 'a' => true ), array_fill_keys( array( 'c', 'a', 'b' ), true ) ) );
	}
}
