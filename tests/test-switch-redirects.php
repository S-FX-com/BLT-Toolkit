<?php
/**
 * The pure parts of switch redirects (SW-9).
 *
 * Resolving IDs to public URLs, core's redirect filters and
 * wp_safe_redirect() need WordPress and are smoke-tested against a real
 * install (tasks/todo.md).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Switch_Redirects.
 */
class Test_Switch_Redirects extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * SW-9: the first non-empty parameter wins, in User Switching's order.
	 *
	 * @return void
	 */
	public function test_sw9_pick_order() {
		$this->assertSame(
			array(
				'type'  => 'url',
				'value' => 'https://example.com/wp-admin/edit.php',
			),
			BLT_Toolkit_Switch_Redirects::pick(
				array(
					'redirect_to'      => ' https://example.com/wp-admin/edit.php ',
					'redirect_to_post' => '12',
				)
			)
		);

		$this->assertSame(
			array(
				'type'  => 'post',
				'value' => 12,
			),
			BLT_Toolkit_Switch_Redirects::pick(
				array(
					'redirect_to'      => '',
					'redirect_to_post' => '12',
					'redirect_to_term' => '5',
				)
			)
		);

		$this->assertSame( 'term', BLT_Toolkit_Switch_Redirects::pick( array( 'redirect_to_term' => '5' ) )['type'] );
		$this->assertSame( 'user', BLT_Toolkit_Switch_Redirects::pick( array( 'redirect_to_user' => '3' ) )['type'] );
		$this->assertSame( 'comment', BLT_Toolkit_Switch_Redirects::pick( array( 'redirect_to_comment' => '9' ) )['type'] );
		$this->assertSame(
			array(
				'type'  => '',
				'value' => '',
			),
			BLT_Toolkit_Switch_Redirects::pick( array( 'other' => 'x' ) )
		);
	}

	/**
	 * SW-9: an ID must be a positive whole number. Anything else asks for
	 * nothing, so the default destination applies (never an ID cast from
	 * junk such as `1abc`).
	 *
	 * @return void
	 */
	public function test_sw9_ids_must_be_positive_integers() {
		foreach ( array( '0', '-3', '1abc', '1.5', ' ', 'x', '1e3', '99999999999999999999' ) as $raw ) {
			$this->assertSame( 0, BLT_Toolkit_Switch_Redirects::pick( array( 'redirect_to_post' => $raw ) )['value'] ?: 0, $raw );
		}

		$this->assertSame( 7, BLT_Toolkit_Switch_Redirects::pick( array( 'redirect_to_post' => ' 7 ' ) )['value'] );
		$this->assertSame( '', BLT_Toolkit_Switch_Redirects::pick( array( 'redirect_to_post' => array( 7 ) ) )['type'], 'Arrays are ignored.' );
	}

	/**
	 * Switch URLs carry only the five known keys, with a URL string or a
	 * positive ID.
	 *
	 * @return void
	 */
	public function test_sw9_args_keep_known_keys() {
		$this->assertSame(
			array(
				'redirect_to'         => 'https://example.com/?p=1',
				'redirect_to_post'    => 4,
				'redirect_to_comment' => 9,
			),
			BLT_Toolkit_Switch_Redirects::args(
				array(
					'redirect_to'         => 'https://example.com/?p=1',
					'redirect_to_post'    => 4,
					'redirect_to_term'    => 'abc',
					'redirect_to_user'    => 0,
					'redirect_to_comment' => '9',
					'action'              => 'blt_switch_off',
					'_wpnonce'            => 'forged',
				)
			)
		);

		$this->assertSame( array(), BLT_Toolkit_Switch_Redirects::args( array( 'redirect_to' => '  ' ) ) );
		$this->assertSame( array(), BLT_Toolkit_Switch_Redirects::args( array( 'redirect_to' => true ) ) );
	}

	/**
	 * SW-9: switching off from an edit screen carries the item being edited,
	 * as User Switching's get_admin_redirect_to().
	 *
	 * @return void
	 */
	public function test_sw9_admin_context() {
		$this->assertSame( array( 'redirect_to_post' => 42 ), BLT_Toolkit_Switch_Redirects::admin_context( array( 'post' => '42', 'action' => 'edit' ) ) );
		$this->assertSame( array( 'redirect_to_term' => 5 ), BLT_Toolkit_Switch_Redirects::admin_context( array( 'taxonomy' => 'category', 'tag_ID' => '5' ) ) );
		$this->assertSame( array( 'redirect_to_user' => 3 ), BLT_Toolkit_Switch_Redirects::admin_context( array( 'user_id' => '3' ) ) );
		$this->assertSame( array( 'redirect_to_comment' => 8 ), BLT_Toolkit_Switch_Redirects::admin_context( array( 'c' => '8', 'action' => 'editcomment' ) ) );
		$this->assertSame( array( 'redirect_to_post' => 42 ), BLT_Toolkit_Switch_Redirects::admin_context( array( 'post' => '42', 'user_id' => '3' ) ), 'First match wins.' );
		$this->assertSame( array( 'redirect_to_user' => 3 ), BLT_Toolkit_Switch_Redirects::admin_context( array( 'post' => 'abc', 'user_id' => '3' ) ), 'A key that is not an ID is skipped.' );
		$this->assertSame( array(), BLT_Toolkit_Switch_Redirects::admin_context( array( 'page' => 'blt-toolkit' ) ) );
	}
}
