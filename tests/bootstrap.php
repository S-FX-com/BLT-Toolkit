<?php
/**
 * Minimal WordPress shims so the pure-logic classes load without a WP install.
 *
 * Only what the classes under test actually touch is shimmed. Anything that
 * needs real WordPress behavior belongs in the manual smoke checklist in
 * tasks/todo.md, not here.
 *
 * @package Blt_Toolkit
 */

define( 'ABSPATH', __DIR__ . '/fake-abspath/' );
define( 'BLT_TOOLKIT_DIR', dirname( __DIR__ ) . '/' );
define( 'BLT_TOOLKIT_VERSION', 'tests' );
define( 'BLT_TOOLKIT_BASENAME', 'blt-toolkit/blt-toolkit.php' );
define( 'BLT_TOOLKIT_REST_NS', 'blt-toolkit/v1' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

// The same computed autoloader the main plugin file registers.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'BLT_Toolkit';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$rest = substr( $class_name, strlen( $prefix ) );

		if ( '' !== $rest && '_' !== $rest[0] ) {
			return;
		}

		$file = 'class-blt-toolkit' . strtolower( str_replace( '_', '-', $rest ) ) . '.php';

		foreach ( array( BLT_TOOLKIT_DIR . 'includes/' . $file, BLT_TOOLKIT_DIR . 'admin/' . $file ) as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// In-memory options and hooks, reset per test through blt_test_reset().
$GLOBALS['blt_test_options']  = array();
$GLOBALS['blt_test_autoload'] = array();
$GLOBALS['blt_test_filters']  = array();
$GLOBALS['blt_test_actions']  = array();
$GLOBALS['blt_test_salt']     = 'test-salt';

/**
 * Reset the shimmed WordPress state, and the per-request caches of the
 * classes under test, between tests.
 *
 * @return void
 */
function blt_test_reset() {
	$GLOBALS['blt_test_options']  = array();
	$GLOBALS['blt_test_autoload'] = array();
	$GLOBALS['blt_test_filters']  = array();
	$GLOBALS['blt_test_actions']  = array();
	$GLOBALS['blt_test_salt']     = 'test-salt';

	BLT_Toolkit_Settings::flush();
	BLT_Toolkit_Caps::reset();
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * WP shim: just enough of WP_Error to read the code and message.
	 */
	class WP_Error { // phpcs:ignore

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * The error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * The error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * WP shim.
	 *
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * WP shim.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * WP shim: runs any callback registered with add_filter().
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) { // phpcs:ignore
		$args = func_get_args();

		array_shift( $args );

		if ( ! empty( $GLOBALS['blt_test_filters'][ $hook ] ) ) {
			foreach ( $GLOBALS['blt_test_filters'][ $hook ] as $callback ) {
				$args[0] = call_user_func_array( $callback, $args );
			}
		}

		return $args[0];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_filter( $hook, $callback ) { // phpcs:ignore
		$GLOBALS['blt_test_filters'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * WP shim: remembers the registration so a test can see it happened.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_action( $hook, $callback ) { // phpcs:ignore
		$GLOBALS['blt_test_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $text Value.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );

		return strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * WP shim: tags stripped, whitespace collapsed, trimmed.
	 *
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * WP shim: tags stripped, line breaks kept.
	 *
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_textarea_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_user' ) ) {
	/**
	 * WP shim of the non-strict path: tags, octets and entities removed,
	 * whitespace collapsed, trimmed.
	 *
	 * @param string $username Username.
	 * @param bool   $strict   Strict mode (ASCII only).
	 * @return string
	 */
	function sanitize_user( $username, $strict = false ) {
		$username = wp_strip_all_tags( (string) $username );
		$username = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '', $username );
		$username = preg_replace( '/&.+?;/', '', $username );

		if ( $strict ) {
			$username = preg_replace( '|[^a-z0-9 _.\-@]|i', '', $username );
		}

		$username = trim( $username );

		return preg_replace( '|\s+|', ' ', $username );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * WP shim.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $name          Option name.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['blt_test_options'] ) ? $GLOBALS['blt_test_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * WP shim: never overwrites, like core.
	 *
	 * @param string $name       Option name.
	 * @param mixed  $value      Value.
	 * @param string $deprecated Unused.
	 * @param mixed  $autoload   Autoload flag (recorded in blt_test_autoload).
	 * @return bool
	 */
	function add_option( $name, $value = '', $deprecated = '', $autoload = null ) { // phpcs:ignore
		if ( array_key_exists( $name, $GLOBALS['blt_test_options'] ) ) {
			return false;
		}

		$GLOBALS['blt_test_options'][ $name ]  = $value;
		$GLOBALS['blt_test_autoload'][ $name ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload flag (recorded in blt_test_autoload).
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['blt_test_options'][ $name ]  = $value;
		$GLOBALS['blt_test_autoload'][ $name ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( $name ) {
		$existed = array_key_exists( $name, $GLOBALS['blt_test_options'] );

		unset( $GLOBALS['blt_test_options'][ $name ], $GLOBALS['blt_test_autoload'][ $name ] );

		return $existed;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * WP shim: change $GLOBALS['blt_test_salt'] to simulate rotated salts.
	 *
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return $GLOBALS['blt_test_salt'] . '|' . $scheme;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * WP shim: core's wp_parse_args() (arrays, objects and query strings).
	 *
	 * @param array|object|string $args     Arguments.
	 * @param array               $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$parsed_args = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed_args =& $args;
		} else {
			parse_str( (string) $args, $parsed_args );
		}

		if ( is_array( $defaults ) && $defaults ) {
			return array_merge( $defaults, $parsed_args );
		}

		return $parsed_args;
	}
}
