<?php
/**
 * WooCommerce integration for switching (SW-22).
 *
 * The placements (order screen, My Account navigation, WooCommerce login
 * form) and forgetting the WooCommerce session on a switch follow User
 * Switching 1.12.2 by John Blackbourn (action_woocommerce_order_details(),
 * filter_woocommerce_account_menu_items(),
 * filter_woocommerce_get_endpoint_url(),
 * action_woocommerce_login_form_start(), forget_woocommerce_session()),
 * reimplemented with BLT names and the BLT permission model.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switching on a WooCommerce shop (SW-22).
 *
 * BLT_Toolkit_Switch::boot() boots this class only when WooCommerce is
 * active and the setting `switch.woocommerce` is on, so on any other site
 * this file is never loaded.
 *
 * - "Switch to customer" on the order edit screen, in the order data box
 *   under the Customer field. Both order storages render that box through
 *   the same action, `woocommerce_admin_order_data_after_order_details`:
 *   the post-based screen (post.php) and the HPOS screen
 *   (admin.php?page=wc-orders). The link appears only when
 *   `current_user_can( 'blt_switch_to_user', $customer_id )` passes (SW-1,
 *   the Guard's SEC-1 and SEC-2), and lands on the customer's view of the
 *   order in My Account.
 * - "Switch back to {Name}" in the My Account navigation while switched,
 *   before "Log out". On an order's page it returns to that order's edit
 *   screen when the original user may edit orders.
 * - "Switch back to {Name}" at the top of WooCommerce's login form (My
 *   Account, checkout) when an old-user cookie validates, which is the case
 *   after a switch off.
 * - The WooCommerce session is forgotten on every switch to, switch back
 *   and switch off, so the cart, the customer details and the session
 *   cookie of one user never carry over to the next.
 *
 * Every message addressed to the person who switched is in their locale
 * (SW-11, through BLT_Toolkit_Switch::switch_back_message()).
 *
 * Cost per request (SEC-9): boot() only adds hooks. The endpoint URL filter
 * returns after one string comparison for every endpoint but its own; the
 * menu and login form callbacks read the switch cookies through
 * BLT_Toolkit_Switch, which runs no query when the browser sent none; the
 * order screen link costs one capability check per order screen.
 */
class BLT_Toolkit_Switch_Woocommerce {

	/**
	 * My Account menu item key, which WooCommerce also passes to
	 * `woocommerce_get_endpoint_url` as the endpoint. It prints as the
	 * class `woocommerce-MyAccount-navigation-link--blt-toolkit-switch-back`.
	 */
	const MENU_ITEM = 'blt-toolkit-switch-back';

	/**
	 * The My Account menu item WooCommerce ends with, which the switch-back
	 * item is placed before.
	 */
	const LOGOUT_ITEM = 'customer-logout';

	/**
	 * WooCommerce's cart cookies: they describe the cart of whoever was
	 * signed in, and page caches and the cart fragments script read them.
	 */
	const CART_COOKIES = array( 'woocommerce_items_in_cart', 'woocommerce_cart_hash' );

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register the hooks. Called by BLT_Toolkit_Switch::boot(); safe to call
	 * twice. Only adds hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		// Order screen, both storages. Priority 1 puts the link right under
		// the Customer field.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'order_switch_link' ), 1 );

		// My Account navigation. Late, so items other plugins add are kept.
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu_items' ), 999 );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'endpoint_url' ), 10, 2 );

		// WooCommerce's login form.
		add_action( 'woocommerce_login_form_start', array( __CLASS__, 'login_form_link' ), 10, 0 );

		// The session (the BLT actions only, so the compat actions of SW-19
		// do not run it a second time).
		add_action( 'blt_toolkit_switched_to', array( __CLASS__, 'forget_session' ), 10, 0 );
		add_action( 'blt_toolkit_switched_back', array( __CLASS__, 'forget_session' ), 10, 0 );
		add_action( 'blt_toolkit_switched_off', array( __CLASS__, 'forget_session' ), 10, 0 );
	}

	// ---------- Order screen ----------

	/**
	 * "Switch to customer" in the order data box, when the order belongs to
	 * a registered user the current user may switch to (SW-1). After the
	 * switch the browser lands on the order in the customer's My Account.
	 *
	 * @param WC_Order|mixed $order The order being edited.
	 * @return void
	 */
	public static function order_switch_link( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$user = $order->get_user();

		// A guest order has no account; the Guard refuses oneself, a missing
		// user, SEC-1 and SEC-2.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Meta capability the Guard maps (SPEC 5.1, SW-1).
		if ( ! $user instanceof WP_User || ! current_user_can( 'blt_switch_to_user', (int) $user->ID ) ) {
			return;
		}

		$url = BLT_Toolkit_Switch::switch_to_url( $user, array( 'redirect_to' => $order->get_view_order_url() ) );

		// The accessible name starts with the visible text, then names the
		// customer (WCAG 2.5.3, label in name).
		$label = sprintf(
			/* translators: %s: the customer's display name, read out by screen readers only. Keep the span. */
			__( 'Switch to customer<span class="screen-reader-text">: %s</span>', 'blt-toolkit' ),
			esc_html( $user->display_name )
		);

		printf(
			'<p class="form-field form-field-wide blt-tk-wc-switch-to"><a href="%1$s">%2$s</a></p>',
			esc_url( $url ),
			wp_kses( $label, array( 'span' => array( 'class' => true ) ) )
		);
	}

	// ---------- My Account ----------

	/**
	 * Add "Switch back to {Name}" to the My Account navigation while
	 * switched. `woocommerce_account_menu_items` callback, priority 999.
	 *
	 * @param array<string,string>|mixed $items Menu items, endpoint => label.
	 * @return array<string,string>|mixed
	 */
	public static function account_menu_items( $items ) {
		if ( ! is_array( $items ) || ! BLT_Toolkit_Switch::is_switched() ) {
			return $items;
		}

		$original = BLT_Toolkit_Switch::old_user();

		if ( ! $original instanceof WP_User || get_current_user_id() === (int) $original->ID ) {
			return $items;
		}

		return self::insert_item( $items, self::MENU_ITEM, BLT_Toolkit_Switch::switch_back_message( $original ), self::LOGOUT_ITEM );
	}

	/**
	 * Add one item to a menu, just before another one, or at the end when
	 * that one is missing. An item already under the key moves to the new
	 * position with the new label. Pure.
	 *
	 * @param array<string,string> $items  Menu items, key => label.
	 * @param string               $key    The new item's key.
	 * @param string               $label  The new item's label.
	 * @param string               $before The key to place it before.
	 * @return array<string,string>
	 */
	public static function insert_item( array $items, $key, $label, $before ) {
		unset( $items[ $key ] );

		$out    = array();
		$placed = false;

		foreach ( $items as $item_key => $item_label ) {
			$item_key = (string) $item_key;

			if ( ! $placed && (string) $before === $item_key ) {
				$out[ $key ] = $label;
				$placed      = true;
			}

			$out[ $item_key ] = $item_label;
		}

		if ( ! $placed ) {
			$out[ $key ] = $label;
		}

		return $out;
	}

	/**
	 * The switch-back item's URL. `woocommerce_get_endpoint_url` callback;
	 * returns at once for every other endpoint.
	 *
	 * @param string|mixed $url      The URL WooCommerce built.
	 * @param string|mixed $endpoint The endpoint (the menu item key).
	 * @return string|mixed
	 */
	public static function endpoint_url( $url, $endpoint ) {
		if ( self::MENU_ITEM !== $endpoint || ! BLT_Toolkit_Switch::is_switched() ) {
			return $url;
		}

		$original = BLT_Toolkit_Switch::old_user();

		if ( ! $original instanceof WP_User ) {
			return $url;
		}

		return BLT_Toolkit_Switch::switch_back_url( $original, self::switch_back_redirect( $original ) );
	}

	/**
	 * Where switching back from My Account lands (SW-9): on an order's page,
	 * that order's edit screen when the original user may edit orders;
	 * otherwise the switch-back default (the Users screen).
	 *
	 * @param WP_User $original The user switching back.
	 * @return array<string,string> Redirect parameters.
	 */
	private static function switch_back_redirect( WP_User $original ) {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
			return array();
		}

		$order_id = absint( get_query_var( 'view-order' ) );
		$order    = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's capability for the order screens.
		if ( ! $order instanceof WC_Order || ! user_can( $original, 'edit_shop_orders' ) ) {
			return array();
		}

		return array( 'redirect_to' => $order->get_edit_order_url() );
	}

	// ---------- Login form ----------

	/**
	 * "Switch back to {Name}" at the top of WooCommerce's login form (My
	 * Account, checkout) when an old-user cookie validates, as the login
	 * screen does (SW-18). Switching back returns to this page.
	 * `woocommerce_login_form_start` callback.
	 *
	 * @return void
	 */
	public static function login_form_link() {
		$original = BLT_Toolkit_Switch::old_user();

		if ( ! $original instanceof WP_User ) {
			return;
		}

		$url = BLT_Toolkit_Switch::switch_back_url( $original, array( 'redirect_to' => self::current_url() ) );

		printf(
			'<p class="woocommerce-info blt-tk-wc-switch-back" lang="%1$s"><a href="%2$s">%3$s</a></p>',
			esc_attr( str_replace( '_', '-', (string) get_user_locale( $original ) ) ),
			esc_url( $url ),
			esc_html( BLT_Toolkit_Switch::switch_back_message( $original ) )
		);
	}

	/**
	 * The current front-end URL. Only used as a redirect, which the switch
	 * flow passes through wp_safe_redirect().
	 *
	 * @return string
	 */
	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' === $host ) {
			return home_url( '/' );
		}

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	// ---------- Session ----------

	/**
	 * Forget the WooCommerce session of the user switched from, without
	 * deleting it. Hooked to `blt_toolkit_switched_to`,
	 * `blt_toolkit_switched_back` and `blt_toolkit_switched_off`, which fire
	 * after the current user changed.
	 *
	 * The stored sessions of both users stay as they were, so each finds
	 * their own cart again; what is dropped is the browser's and this
	 * request's hold on the old one:
	 *
	 * 1. WC_Session_Handler::forget_session() expires the session cookie,
	 *    empties the cart in memory and drops the session data in memory.
	 *    Without it, the next request would carry the old user's session
	 *    cookie, which WooCommerce treats as invalid for the new user and
	 *    answers by deleting the old user's stored session.
	 * 2. The session cookie is removed from `$_COOKIE` and the session is
	 *    read again for the user now signed in, so a write later in this
	 *    request lands on that user's stored session instead of replacing it
	 *    with an empty one. After a switch off nobody is signed in and
	 *    nothing is read.
	 * 3. The cart cookies are expired; WooCommerce sets them again from the
	 *    new user's cart on their next page.
	 * 4. WC()->customer was loaded for the user switched from, and
	 *    WooCommerce saves it into the session on `shutdown`, which would
	 *    write that user's name and addresses into the new user's session.
	 *    It is replaced by the customer now signed in (or a guest).
	 *
	 * @return void
	 */
	public static function forget_session() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$wc = WC();

		if ( ! is_object( $wc ) ) {
			return;
		}

		$session = ( isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;
		$cookie  = self::session_cookie_name();

		// Step 1.
		if ( null !== $session && method_exists( $session, 'forget_session' ) ) {
			$session->forget_session();
		} elseif ( isset( $_COOKIE[ $cookie ] ) && function_exists( 'wc_setcookie' ) ) {
			// No session handler on this request: expire the cookie by name.
			wc_setcookie( $cookie, '', time() - YEAR_IN_SECONDS, false, true );
		}

		// Step 2.
		unset( $_COOKIE[ $cookie ] );

		if ( null !== $session && method_exists( $session, 'init_session_cookie' ) ) {
			if ( method_exists( $session, 'set_session_expiration' ) ) {
				$session->set_session_expiration();
			}

			$session->init_session_cookie();
		}

		// Step 3.
		foreach ( self::CART_COOKIES as $name ) {
			if ( isset( $_COOKIE[ $name ] ) ) {
				if ( function_exists( 'wc_setcookie' ) ) {
					wc_setcookie( $name, 0, time() - HOUR_IN_SECONDS );
				}

				unset( $_COOKIE[ $name ] );
			}
		}

		// Step 4.
		if ( isset( $wc->customer ) && $wc->customer instanceof WC_Customer && (int) $wc->customer->get_id() !== get_current_user_id() ) {
			remove_action( 'shutdown', array( $wc->customer, 'save' ), 10 );

			$wc->customer = null;

			if ( method_exists( $wc, 'initialize_cart' ) ) {
				$wc->initialize_cart();
			}
		}
	}

	/**
	 * The name of WooCommerce's session cookie, as WC_Session_Handler builds
	 * it.
	 *
	 * @return string
	 */
	private static function session_cookie_name() {
		/** This filter is documented in woocommerce/includes/class-wc-session-handler.php */
		return (string) apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter, applied as its session handler does.
	}
}
