<?php
/**
 * The pure part of the WooCommerce integration (SW-22): where "Switch back"
 * goes in the My Account navigation.
 *
 * The order screen link, the login form link and forgetting the WooCommerce
 * session need WordPress and WooCommerce and are smoke-tested against a real
 * install (tasks/todo.md).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Switch_Woocommerce::insert_item().
 */
class Test_Switch_Woocommerce extends TestCase {

	/**
	 * WooCommerce's default My Account items.
	 *
	 * @return array<string,string>
	 */
	private function menu() {
		return array(
			'dashboard'       => 'Dashboard',
			'orders'          => 'Orders',
			'downloads'       => 'Downloads',
			'edit-address'    => 'Addresses',
			'edit-account'    => 'Account details',
			'customer-logout' => 'Log out',
		);
	}

	/**
	 * SW-22: "Switch back" goes just before "Log out".
	 *
	 * @return void
	 */
	public function test_sw22_switch_back_goes_before_logout() {
		$items = BLT_Toolkit_Switch_Woocommerce::insert_item( $this->menu(), 'blt-toolkit-switch-back', 'Switch back to Shane', 'customer-logout' );

		$this->assertSame(
			array( 'dashboard', 'orders', 'downloads', 'edit-address', 'edit-account', 'blt-toolkit-switch-back', 'customer-logout' ),
			array_keys( $items )
		);
		$this->assertSame( 'Switch back to Shane', $items['blt-toolkit-switch-back'] );
		$this->assertSame( 'Log out', $items['customer-logout'] );
	}

	/**
	 * SW-22: with no "Log out" item (removed by the theme or another
	 * plugin), "Switch back" goes last.
	 *
	 * @return void
	 */
	public function test_sw22_switch_back_goes_last_without_logout() {
		$menu = $this->menu();
		unset( $menu['customer-logout'] );

		$items = BLT_Toolkit_Switch_Woocommerce::insert_item( $menu, 'blt-toolkit-switch-back', 'Switch back to Shane', 'customer-logout' );

		$this->assertSame( 'blt-toolkit-switch-back', array_key_last( $items ) );
		$this->assertCount( 6, $items );
	}

	/**
	 * SW-22: an item already under the key is moved, not duplicated, and
	 * takes the new label; the other items keep their order and labels.
	 *
	 * @return void
	 */
	public function test_sw22_existing_item_is_moved_not_duplicated() {
		$menu = array( 'blt-toolkit-switch-back' => 'Old label' ) + $this->menu();

		$items = BLT_Toolkit_Switch_Woocommerce::insert_item( $menu, 'blt-toolkit-switch-back', 'Switch back to Shane', 'customer-logout' );

		$this->assertSame(
			array( 'dashboard', 'orders', 'downloads', 'edit-address', 'edit-account', 'blt-toolkit-switch-back', 'customer-logout' ),
			array_keys( $items )
		);
		$this->assertSame( 'Switch back to Shane', $items['blt-toolkit-switch-back'] );
	}

	/**
	 * SW-22: numeric keys from other plugins survive, and an empty menu
	 * gets just the one item.
	 *
	 * @return void
	 */
	public function test_sw22_numeric_keys_and_empty_menu() {
		$items = BLT_Toolkit_Switch_Woocommerce::insert_item(
			array(
				7                 => 'Seven',
				'customer-logout' => 'Log out',
			),
			'blt-toolkit-switch-back',
			'Back',
			'customer-logout'
		);

		$this->assertSame( array( 7, 'blt-toolkit-switch-back', 'customer-logout' ), array_keys( $items ) );
		$this->assertSame( 'Seven', $items[7] );

		$this->assertSame(
			array( 'blt-toolkit-switch-back' => 'Back' ),
			BLT_Toolkit_Switch_Woocommerce::insert_item( array(), 'blt-toolkit-switch-back', 'Back', 'customer-logout' )
		);
	}
}
