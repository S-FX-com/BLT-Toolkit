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
	 * WP shim: the part of core's WP_Error the classes under test use. An
	 * empty WP_Error (no code) has no errors, as in core, so the Username
	 * Validator's "empty means valid" reads the same here (Phase 3).
	 */
	class WP_Error { // phpcs:ignore

		/**
		 * Messages by code, in the order they were added.
		 *
		 * @var array<string,string[]>
		 */
		private $errors = array();

		/**
		 * Data by code (the last data added for each code).
		 *
		 * @var array<string,mixed>
		 */
		private $error_data = array();

		/**
		 * Constructor. With no code the error is empty, as in core.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code || null === $code ) {
				return;
			}

			$this->add( $code, $message, $data );
		}

		/**
		 * Add an error, as core does.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 * @return void
		 */
		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * Whether any error was added.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return ! empty( $this->errors );
		}

		/**
		 * Every error code, in order.
		 *
		 * @return string[]
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		/**
		 * The first error code, or '' when empty.
		 *
		 * @return string
		 */
		public function get_error_code() {
			$codes = $this->get_error_codes();

			return empty( $codes ) ? '' : $codes[0];
		}

		/**
		 * Every message for a code, or every message when no code is given.
		 *
		 * @param string $code Code.
		 * @return string[]
		 */
		public function get_error_messages( $code = '' ) {
			if ( '' === $code ) {
				$all = array();

				foreach ( $this->errors as $messages ) {
					$all = array_merge( $all, $messages );
				}

				return $all;
			}

			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
		}

		/**
		 * The first message for a code (the first code by default).
		 *
		 * @param string $code Code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		/**
		 * The data for a code (the first code by default).
		 *
		 * @param string $code Code.
		 * @return mixed Null when there is none.
		 */
		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
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

/*
 * Phase 3 (Usernames) shims. Users for username_exists() and email_exists()
 * come from $GLOBALS['blt_test_users'] (ID => array( 'login', 'email' )),
 * which a test sets itself.
 */

if ( ! function_exists( '_n' ) ) {
	/**
	 * WP shim: English plural rule.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Count.
	 * @param string $domain Domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * WP shim: a user built from an array of fields.
	 */
	class WP_User { // phpcs:ignore

		/**
		 * User ID.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * Login.
		 *
		 * @var string
		 */
		public $user_login = '';

		/**
		 * Email.
		 *
		 * @var string
		 */
		public $user_email = '';

		/**
		 * Author slug.
		 *
		 * @var string
		 */
		public $user_nicename = '';

		/**
		 * Display name.
		 *
		 * @var string
		 */
		public $display_name = '';

		/**
		 * Role IDs.
		 *
		 * @var string[]
		 */
		public $roles = array();

		/**
		 * Constructor.
		 *
		 * @param array $fields Field => value.
		 */
		public function __construct( $fields = array() ) {
			foreach ( (array) $fields as $key => $value ) {
				$this->$key = $value;
			}

			$this->ID = (int) $this->ID;
		}

		/**
		 * Whether the user exists.
		 *
		 * @return bool
		 */
		public function exists() {
			return $this->ID > 0;
		}
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * WP shim: English number formatting.
	 *
	 * @param float $number   Number.
	 * @param int   $decimals Decimals.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'username_exists' ) ) {
	/**
	 * WP shim: case-insensitive, as MySQL's default collation compares.
	 *
	 * @param string $username Login.
	 * @return int|false
	 */
	function username_exists( $username ) {
		$users = isset( $GLOBALS['blt_test_users'] ) ? (array) $GLOBALS['blt_test_users'] : array();

		foreach ( $users as $id => $user ) {
			if ( isset( $user['login'] ) && strtolower( $user['login'] ) === strtolower( trim( (string) $username ) ) ) {
				return (int) $id;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'email_exists' ) ) {
	/**
	 * WP shim: case-insensitive, as MySQL's default collation compares.
	 *
	 * @param string $email Email.
	 * @return int|false
	 */
	function email_exists( $email ) {
		$users = isset( $GLOBALS['blt_test_users'] ) ? (array) $GLOBALS['blt_test_users'] : array();

		foreach ( $users as $id => $user ) {
			if ( isset( $user['email'] ) && strtolower( $user['email'] ) === strtolower( (string) $email ) ) {
				return (int) $id;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'validate_username' ) ) {
	/**
	 * WP shim: core's validate_username(), with its filter.
	 *
	 * @param string $username Username.
	 * @return bool
	 */
	function validate_username( $username ) {
		$sanitized = sanitize_user( $username, true );

		return apply_filters( 'validate_username', $sanitized === $username && ! empty( $sanitized ), $username );
	}
}
