<?php
/**
 * Settings storage, sanitizing and the encrypted GitHub token.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the single `blt_toolkit_settings` option (SPEC 14.1), merged over
 * defaults and hand-sanitized on save, never through `register_setting`
 * (CONVENTIONS 6.2), plus the encrypted `blt_toolkit_secrets` option.
 *
 * The settings option is autoloaded because the Guard and read-only
 * enforcement read it on most requests; the merged result is cached for the
 * request and dropped whenever the option is written.
 *
 * Two write paths, never mixed:
 *
 * - save_tab() takes one Settings tab's submitted fields and touches only
 *   those. A checkbox that was not submitted means "off".
 * - set() writes one key at a time from inside the plugin. It is the only
 *   way to change `roles.role_meta`, `roles.isolated_post_types` and
 *   `roles.extra_default_roles`, which no Settings tab owns.
 */
class BLT_Toolkit_Settings {

	/**
	 * Settings option (autoload yes).
	 */
	const OPTION = 'blt_toolkit_settings';

	/**
	 * Encrypted secrets option (autoload no).
	 */
	const SECRETS_OPTION = 'blt_toolkit_secrets';

	/**
	 * Key of the GitHub token inside the secrets option.
	 */
	const GITHUB_TOKEN_KEY = 'github_token';

	/**
	 * Option the bundled update checker keeps its state in (its StateStore
	 * names it `external_updates-{slug}`). Not autoloaded; read on every
	 * admin screen that counts updates. See prime_admin_options().
	 */
	const UPDATE_STATE_OPTION = 'external_updates-blt-toolkit';

	/**
	 * Settings tabs and the settings group each one edits.
	 */
	const TABS = array(
		'general'   => 'modules',
		'switching' => 'switch',
		'usernames' => 'usernames',
		'roles'     => 'roles',
		'log'       => 'log',
		'advanced'  => 'advanced',
	);

	/**
	 * Keys in the `roles` group that no tab owns: they are written through
	 * set() by the features that manage them.
	 */
	const INTERNAL_KEYS = array( 'role_meta', 'isolated_post_types', 'extra_default_roles' );

	/**
	 * Whitelisted values.
	 */
	const NICENAME_POLICIES = array( 'if_derived', 'always', 'never' );
	const VIEWS             = array( 'simple', 'advanced' );

	/**
	 * Integer bounds, as array( min, max ), keyed by "group.key".
	 */
	const INT_RANGES = array(
		'usernames.min_length'     => array( 1, 60 ),
		'usernames.cooldown_days'  => array( 0, 365 ),
		'roles.snapshot_retention' => array( 1, 500 ),
		'log.retention_days'       => array( 1, 3650 ),
	);

	/**
	 * Most entries kept in the disallowed-usernames list.
	 */
	const MAX_DISALLOWED = 500;

	/**
	 * Merged settings for this request, or null when not read yet.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Whether the option-change hooks that drop the cache are registered.
	 *
	 * @var bool
	 */
	private static $watching = false;

	/**
	 * The plugin's own stored token for this request: array( 'state', 'token' ).
	 *
	 * @var array|null
	 */
	private static $own_token = null;

	/**
	 * Further options prime_admin_options() reads (prime_also()).
	 *
	 * @var string[]
	 */
	private static $prime = array();

	/**
	 * Default settings, exactly SPEC 14.1.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'modules'   => array(
				'switch'    => true,
				'usernames' => true,
				'roles'     => true,
			),
			'switch'    => array(
				'footer_link'  => true,
				'switch_off'   => true,
				'search'       => true,
				'compat_hooks' => true,
				'woocommerce'  => true,
			),
			'usernames' => array(
				'min_length'         => 3,
				'disallowed'         => array( 'admin', 'administrator', 'root', 'sysadmin', 'webmaster' ),
				'allow_at_sign'      => true,
				'nicename_policy'    => 'if_derived', // if_derived | always | never.
				'redirect_old_slugs' => true,
				'sign_out_others'    => true,
				'notify_user'        => false,
				'email_subject'      => '', // '' = translated default.
				'email_body'         => '',
				'cooldown_days'      => 0,
				'require_password'   => false,
			),
			'roles'     => array(
				'default_view'        => 'simple',
				'show_deprecated'     => false,
				'readable_labels'     => true,
				'hide_admins'         => true,
				'extra_default_roles' => array(),
				'role_meta'           => array(), // Role ID to its description and hide_toolbar flag.
				'isolated_post_types' => array(), // Post type to its singular and plural labels and since time.
				'snapshot_retention'  => 50,
			),
			'log'       => array(
				'retention_days' => 365,
				'record_ip'      => true,
			),
			'advanced'  => array(
				'delete_data_on_uninstall' => false,
			),
		);
	}

	/**
	 * All settings, stored values merged over the defaults group by group.
	 *
	 * Within a group each stored key replaces its default wholesale, lists
	 * included: a disallowed-usernames list the owner shortened stays short.
	 * Every stored value goes through the same sanitizing as a save, so a
	 * value written around this class (`wp option patch`, a hand-edited
	 * database) reads the way the Settings screen would have saved it: the
	 * strings `false`, `off`, `no` and `0` are off, numbers are clamped into
	 * range, whitelisted values fall back to the default, and lists hold
	 * only clean entries. A stored value of the wrong type (a damaged option)
	 * reads as the default. Keys the defaults do not know are dropped, except
	 * module toggles, which a `blt_toolkit_modules` filter may add.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::watch();

		$stored      = get_option( self::OPTION, array() );
		self::$cache = self::merge( is_array( $stored ) ? $stored : array() );

		return self::$cache;
	}

	/**
	 * One group, or one key of a group.
	 *
	 * @param string      $group Group key (`modules`, `switch`, `usernames`, `roles`, `log`, `advanced`).
	 * @param string|null $key   Key within the group, or null for the whole group.
	 * @return mixed Null when the group or key does not exist.
	 */
	public static function get( $group, $key = null ) {
		$all = self::all();

		if ( ! isset( $all[ $group ] ) ) {
			return null;
		}

		if ( null === $key ) {
			return $all[ $group ];
		}

		return array_key_exists( $key, $all[ $group ] ) ? $all[ $group ][ $key ] : null;
	}

	/**
	 * Store the defaults on first activation. Never overwrites a stored value.
	 *
	 * @return void
	 */
	public static function seed() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
		}

		self::flush();
	}

	/**
	 * Save one Settings tab.
	 *
	 * Takes the tab's fields flat, keyed as in its settings group (for
	 * `general`, the module keys: `array( 'switch' => '1', 'roles' => '1' )`),
	 * already unslashed. Every field of that tab is sanitized by hand:
	 *
	 * - A toggle that is missing from $raw is off, because an unticked
	 *   checkbox is not submitted.
	 * - Any other field missing from $raw keeps its stored value; a value that
	 *   is not valid (text where a number belongs, a value outside a
	 *   whitelist) also keeps the stored value, and numbers are clamped into
	 *   range.
	 *
	 * Fields of other tabs, and the `roles` keys only set() writes, are never
	 * touched.
	 *
	 * @param string $tab `general`, `switching`, `usernames`, `roles`, `log` or `advanced`.
	 * @param array  $raw Submitted fields for that tab.
	 * @return array The sanitized group as stored, or an empty array for an unknown tab.
	 */
	public static function save_tab( $tab, array $raw ) {
		$tab = (string) $tab;

		if ( ! isset( self::TABS[ $tab ] ) ) {
			return array();
		}

		$group   = self::TABS[ $tab ];
		$all     = self::all();
		$current = $all[ $group ];

		foreach ( self::tab_fields( $tab ) as $key ) {
			$value = array_key_exists( $key, $raw ) ? $raw[ $key ] : null;

			$all[ $group ][ $key ] = self::sanitize_field( $group, $key, $value, self::current_value( $current, $group, $key ) );
		}

		self::write( $all );

		return $all[ $group ];
	}

	/**
	 * Write one key from inside the plugin, sanitized for that key.
	 *
	 * This is how `roles.role_meta`, `roles.isolated_post_types` and
	 * `roles.extra_default_roles` change; any other known key works too.
	 *
	 * @param string $group Group key.
	 * @param string $key   Key within the group.
	 * @param mixed  $value New value.
	 * @return bool False when the group or key is unknown.
	 */
	public static function set( $group, $key, $value ) {
		$group    = (string) $group;
		$key      = (string) $key;
		$defaults = self::defaults();

		if ( ! isset( $defaults[ $group ] ) ) {
			return false;
		}

		if ( 'modules' === $group ) {
			if ( ! in_array( $key, self::module_keys(), true ) ) {
				return false;
			}
		} elseif ( ! array_key_exists( $key, $defaults[ $group ] ) ) {
			return false;
		}

		$all = self::all();

		$all[ $group ][ $key ] = self::sanitize_field( $group, $key, $value, self::current_value( $all[ $group ], $group, $key ) );

		self::write( $all );

		return true;
	}

	/**
	 * Drop the cached settings so the next read comes from the option.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache     = null;
		self::$own_token = null;
	}

	/**
	 * The keys a tab's form owns.
	 *
	 * @param string $tab Tab key.
	 * @return string[]
	 */
	public static function tab_fields( $tab ) {
		$tab = (string) $tab;

		if ( ! isset( self::TABS[ $tab ] ) ) {
			return array();
		}

		if ( 'general' === $tab ) {
			return self::module_keys();
		}

		$group = self::TABS[ $tab ];
		$keys  = array_keys( self::defaults()[ $group ] );

		if ( 'roles' === $group ) {
			$keys = array_values( array_diff( $keys, self::INTERNAL_KEYS ) );
		}

		return $keys;
	}

	// ---------- GitHub token ----------

	/**
	 * `init` priority 0 on admin requests (BLT_Toolkit_Plugin::boot()): read
	 * in one query the options that are not autoloaded and that admin
	 * screens read one query each later on (SEC-9): the update token's
	 * secrets and the family opt-in (github_token(), `init` priority 1), the
	 * update checker's state (UPDATE_STATE_OPTION, read by `menu.php` on
	 * every admin screen that counts updates), and whatever prime_also()
	 * added for this request. An option that does not exist is remembered
	 * as missing, as get_option() would. Nothing is read twice: options
	 * already cached are skipped. Needs wp_prime_option_caches() (WordPress
	 * 6.4); does nothing before.
	 *
	 * @return void
	 */
	public static function prime_admin_options() {
		if ( ! function_exists( 'wp_prime_option_caches' ) ) {
			return;
		}

		$names = array( self::SECRETS_OPTION, self::UPDATE_STATE_OPTION );

		if ( class_exists( 'BLT_Family' ) && defined( 'BLT_Family::OPT_IN_OPTION' ) ) {
			$names[] = BLT_Family::OPT_IN_OPTION;
		}

		wp_prime_option_caches( array_values( array_unique( array_merge( $names, self::$prime ) ) ) );
	}

	/**
	 * Have prime_admin_options() read these options too. Call it before
	 * `init`; the names are option names as stored (a transient is
	 * `_transient_{name}` and `_transient_timeout_{name}`).
	 *
	 * @param string[] $names Option names.
	 * @return void
	 */
	public static function prime_also( array $names ) {
		foreach ( $names as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				self::$prime[] = $name;
			}
		}
	}

	/**
	 * The effective GitHub update token, or '' when there is none.
	 *
	 * Precedence (CONVENTIONS 9.1): the `BLT_TOOLKIT_GITHUB_TOKEN` constant,
	 * then the plugin's own encrypted option, then the shared `github` group,
	 * which BLT_Family::get() answers only when the owner opted this plugin
	 * in. The shared store is consulted only after the plugin's own value
	 * came up empty.
	 *
	 * Call it from `init` onward: the shared store exists only once the family
	 * copy is elected (`plugins_loaded` priority 0), and reading it translates
	 * strings, which earlier in the request would authenticate the current
	 * user before SSO plugins have hooked in.
	 *
	 * @return string
	 */
	public static function github_token() {
		$constant = self::github_token_constant();

		if ( '' !== $constant ) {
			return $constant;
		}

		// Both options read below are not autoloaded and this runs on every
		// admin request (`init`): read them in one query, not one each
		// (SEC-9). wp_prime_option_caches() arrived in WordPress 6.4.
		if ( null === self::$own_token && function_exists( 'wp_prime_option_caches' ) ) {
			$names = array( self::SECRETS_OPTION );

			if ( class_exists( 'BLT_Family' ) && defined( 'BLT_Family::OPT_IN_OPTION' ) ) {
				$names[] = BLT_Family::OPT_IN_OPTION;
			}

			wp_prime_option_caches( $names );
		}

		$own = self::own_github_token();

		if ( '' !== $own['token'] ) {
			return $own['token'];
		}

		return self::shared_github_token();
	}

	/**
	 * Where the token comes from, for the Advanced tab.
	 *
	 * `undecryptable` means a token is stored but the security keys in
	 * wp-config.php changed since it was saved, so it can no longer be read
	 * and the owner needs to enter it again. It outranks `shared`: the screen
	 * should ask for the plugin's own token even while a shared one is in use.
	 *
	 * @return string `constant`, `stored`, `undecryptable`, `shared` or `none`.
	 */
	public static function github_token_status() {
		if ( '' !== self::github_token_constant() ) {
			return 'constant';
		}

		$own = self::own_github_token();

		if ( 'ok' === $own['state'] ) {
			return 'stored';
		}

		if ( 'undecryptable' === $own['state'] ) {
			return 'undecryptable';
		}

		return '' !== self::shared_github_token() ? 'shared' : 'none';
	}

	/**
	 * Encrypt and store the plugin's own GitHub token.
	 *
	 * A blank submission keeps the stored token: clearing it is the explicit
	 * delete_github_token() (CONVENTIONS 6.2). If no authenticated cipher is
	 * available the token is refused, never stored in plaintext.
	 *
	 * @param string $token Token as pasted.
	 * @return true|WP_Error
	 */
	public static function set_github_token( $token ) {
		$token = is_scalar( $token ) ? preg_replace( '/\s+/', '', (string) $token ) : '';

		if ( '' === $token ) {
			return true;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_.\-]{1,255}$/', $token ) ) {
			return new WP_Error( 'blt_toolkit_bad_token', __( 'That does not look like a GitHub token. Paste it exactly as GitHub showed it.', 'blt-toolkit' ) );
		}

		$crypto = new BLT_Toolkit_Crypto();

		if ( ! $crypto->is_available() ) {
			return new WP_Error( 'blt_toolkit_no_aead', __( 'The token was not saved: this server has neither libsodium nor OpenSSL AES-GCM, so it cannot be stored encrypted. Define BLT_TOOLKIT_GITHUB_TOKEN in wp-config.php instead.', 'blt-toolkit' ) );
		}

		$envelope = $crypto->encrypt( $token );

		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}

		$secrets = self::secrets();

		$secrets[ self::GITHUB_TOKEN_KEY ] = $envelope;

		self::write_secrets( $secrets );

		return true;
	}

	/**
	 * Remove the plugin's own stored token. The constant and the shared store
	 * are not affected.
	 *
	 * @return void
	 */
	public static function delete_github_token() {
		$secrets = self::secrets();

		unset( $secrets[ self::GITHUB_TOKEN_KEY ] );

		self::write_secrets( $secrets );
	}

	// ---------- Internals ----------

	/**
	 * Merge stored values over the defaults (see all()).
	 *
	 * @param array $stored Stored option value.
	 * @return array
	 */
	public static function merge( array $stored ) {
		$out = self::defaults();

		foreach ( $out as $group => $fields ) {
			if ( ! isset( $stored[ $group ] ) || ! is_array( $stored[ $group ] ) ) {
				continue;
			}

			foreach ( $stored[ $group ] as $key => $value ) {
				if ( array_key_exists( $key, $fields ) ) {
					$out[ $group ][ $key ] = self::read_field( $group, $key, $value, $fields[ $key ] );
				} elseif ( 'modules' === $group && is_string( $key ) && '' !== $key ) {
					$out[ $group ][ $key ] = is_scalar( $value ) && self::to_bool( $value );
				}
			}
		}

		return $out;
	}

	/**
	 * A stored value as the write path would have saved it, or the default
	 * when it has the wrong type.
	 *
	 * The default stands in for the stored value wherever sanitize_field()
	 * would keep "the current value" (a number that is not one, a value
	 * outside a whitelist), so a damaged entry never survives a read.
	 *
	 * @param string $group         Group key.
	 * @param string $key           Field key.
	 * @param mixed  $value         Stored value.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	private static function read_field( $group, $key, $value, $default_value ) {
		$wrong_type = is_array( $default_value ) ? ! is_array( $value ) : ! is_scalar( $value );

		if ( $wrong_type ) {
			return $default_value;
		}

		return self::sanitize_field( $group, $key, $value, $default_value );
	}

	/**
	 * Sanitize one field.
	 *
	 * @param string $group   Group key.
	 * @param string $key     Field key.
	 * @param mixed  $value   Submitted value; null when it was not submitted.
	 * @param mixed  $current Stored value, returned when $value is not usable.
	 * @return mixed
	 */
	private static function sanitize_field( $group, $key, $value, $current ) {
		$id = $group . '.' . $key;

		if ( isset( self::INT_RANGES[ $id ] ) ) {
			return self::clamp_int( $value, self::INT_RANGES[ $id ][0], self::INT_RANGES[ $id ][1], $current );
		}

		switch ( $id ) {
			case 'usernames.disallowed':
				return null === $value ? $current : self::sanitize_username_list( $value );

			case 'usernames.nicename_policy':
				return is_string( $value ) && in_array( $value, self::NICENAME_POLICIES, true ) ? $value : $current;

			case 'usernames.email_subject':
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $current;

			case 'usernames.email_body':
				return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : $current;

			case 'roles.default_view':
				return is_string( $value ) && in_array( $value, self::VIEWS, true ) ? $value : $current;

			case 'roles.extra_default_roles':
				return self::sanitize_role_list( $value );

			case 'roles.role_meta':
				return self::sanitize_role_meta( $value );

			case 'roles.isolated_post_types':
				return self::sanitize_isolated_types( $value );
		}

		// Every remaining field is a toggle.
		return self::to_bool( $value );
	}

	/**
	 * The stored value of a key, or its default.
	 *
	 * @param array  $current Stored group.
	 * @param string $group   Group key.
	 * @param string $key     Field key.
	 * @return mixed
	 */
	private static function current_value( array $current, $group, $key ) {
		if ( array_key_exists( $key, $current ) ) {
			return $current[ $key ];
		}

		$defaults = self::defaults();

		return isset( $defaults[ $group ][ $key ] ) ? $defaults[ $group ][ $key ] : false;
	}

	/**
	 * A checkbox or programmatic boolean. Missing (null) is false.
	 *
	 * Only true, a non-zero number and the strings 1, true, on and yes are
	 * true. uninstall.php repeats this reading for `delete_data_on_uninstall`,
	 * which it must decide without loading the plugin; keep the two in step.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- 1 and 1.0 alike.
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'yes' ), true );
		}

		return false;
	}

	/**
	 * A whole number clamped into [min, max]; the current value when the
	 * input is missing or not a number.
	 *
	 * @param mixed $value   Submitted value.
	 * @param int   $min     Lowest allowed.
	 * @param int   $max     Highest allowed.
	 * @param mixed $current Stored value.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $current ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( ! is_numeric( $value ) ) {
			$value = is_numeric( $current ) ? $current : $min;
		}

		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Disallowed usernames from a textarea (one per line or comma separated)
	 * or an array: sanitized like a username, lowercased (UN-4 compares
	 * case-insensitively), deduplicated, at most 60 characters each.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function sanitize_username_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $name ) {
			if ( ! is_scalar( $name ) ) {
				continue;
			}

			$name = strtolower( sanitize_user( (string) $name ) );

			if ( '' === $name || strlen( $name ) > 60 || in_array( $name, $out, true ) ) {
				continue;
			}

			$out[] = $name;

			if ( count( $out ) >= self::MAX_DISALLOWED ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * A role ID as WordPress stores it: letters, digits, underscores and
	 * hyphens. Case is kept so it still matches an existing role.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_role_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ), 0, 191 );
	}

	/**
	 * A unique list of role IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function sanitize_role_list( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $role ) {
			$role = self::sanitize_role_id( $role );

			if ( '' !== $role && ! in_array( $role, $out, true ) ) {
				$out[] = $role;
			}
		}

		return $out;
	}

	/**
	 * Role metadata (SPEC 9.5): role ID => description and toolbar flag.
	 * Entries that say nothing (no description, toolbar shown) are dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,array{description:string,hide_toolbar:bool}>
	 */
	private static function sanitize_role_meta( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $role => $meta ) {
			$role = self::sanitize_role_id( $role );

			if ( '' === $role || ! is_array( $meta ) ) {
				continue;
			}

			$entry = array(
				'description'  => isset( $meta['description'] ) && is_scalar( $meta['description'] ) ? mb_substr( sanitize_text_field( (string) $meta['description'] ), 0, 500 ) : '',
				'hide_toolbar' => isset( $meta['hide_toolbar'] ) ? self::to_bool( $meta['hide_toolbar'] ) : false,
			);

			if ( '' !== $entry['description'] || $entry['hide_toolbar'] ) {
				$out[ $role ] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Isolated post types (SPEC 10.5): type => labels and the time isolation
	 * started.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string,array{singular:string,plural:string,since:int}>
	 */
	private static function sanitize_isolated_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $type => $info ) {
			$type = sanitize_key( (string) $type );

			// Post type keys are at most 20 characters (register_post_type()).
			if ( '' === $type || strlen( $type ) > 20 || ! is_array( $info ) ) {
				continue;
			}

			$entry = array(
				'singular' => isset( $info['singular'] ) && is_scalar( $info['singular'] ) ? sanitize_text_field( (string) $info['singular'] ) : '',
				'plural'   => isset( $info['plural'] ) && is_scalar( $info['plural'] ) ? sanitize_text_field( (string) $info['plural'] ) : '',
				'since'    => isset( $info['since'] ) && is_numeric( $info['since'] ) ? absint( $info['since'] ) : 0,
			);

			// RS-30, RS-31: never store an entry the filter would refuse (a
			// core type, invalid names, or strings that are core's own), so
			// an import cannot move Posts or a core capability.
			if ( class_exists( 'BLT_Toolkit_Isolation_Core' ) && '' !== BLT_Toolkit_Isolation_Core::entry_error( $type, $entry ) ) {
				continue;
			}

			$out[ $type ] = $entry;
		}

		return $out;
	}

	/**
	 * Module keys a settings toggle may exist for: the built-in three plus
	 * any added through `blt_toolkit_modules`.
	 *
	 * @return string[]
	 */
	private static function module_keys() {
		$keys = array_keys( self::defaults()['modules'] );

		if ( class_exists( 'BLT_Toolkit_Plugin' ) ) {
			$keys = array_merge( $keys, array_keys( BLT_Toolkit_Plugin::modules() ) );
		}

		return array_values( array_unique( array_map( 'strval', $keys ) ) );
	}

	/**
	 * Persist the whole settings array and drop the cache.
	 *
	 * @param array $all Settings.
	 * @return void
	 */
	private static function write( array $all ) {
		update_option( self::OPTION, $all, true );
		self::flush();
	}

	/**
	 * Drop the cache whenever anything writes the option, including code
	 * that bypasses this class.
	 *
	 * @return void
	 */
	private static function watch() {
		if ( self::$watching || ! function_exists( 'add_action' ) ) {
			return;
		}

		self::$watching = true;

		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . self::OPTION, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * The `BLT_TOOLKIT_GITHUB_TOKEN` constant, or '' when it is not set.
	 *
	 * @return string
	 */
	private static function github_token_constant() {
		if ( ! defined( 'BLT_TOOLKIT_GITHUB_TOKEN' ) ) {
			return '';
		}

		$value = constant( 'BLT_TOOLKIT_GITHUB_TOKEN' );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The plugin's own stored token, decrypted once per request.
	 *
	 * A decrypt failure is reported, never repaired here: reading must not
	 * write, and the envelope reads again if the old salts come back.
	 *
	 * @return array{state:string,token:string} State `none`, `ok` or `undecryptable`.
	 */
	private static function own_github_token() {
		if ( null !== self::$own_token ) {
			return self::$own_token;
		}

		$secrets  = self::secrets();
		$envelope = isset( $secrets[ self::GITHUB_TOKEN_KEY ] ) && is_string( $secrets[ self::GITHUB_TOKEN_KEY ] ) ? $secrets[ self::GITHUB_TOKEN_KEY ] : '';

		if ( '' === $envelope ) {
			self::$own_token = array(
				'state' => 'none',
				'token' => '',
			);

			return self::$own_token;
		}

		$plain = ( new BLT_Toolkit_Crypto() )->decrypt( $envelope );

		if ( is_wp_error( $plain ) || ! is_string( $plain ) || '' === $plain ) {
			self::$own_token = array(
				'state' => 'undecryptable',
				'token' => '',
			);

			return self::$own_token;
		}

		self::$own_token = array(
			'state' => 'ok',
			'token' => $plain,
		);

		return self::$own_token;
	}

	/**
	 * The shared `github` token, or '' when the owner has not opted this
	 * plugin in (or the family layer is absent).
	 *
	 * @return string
	 */
	private static function shared_github_token() {
		if ( ! class_exists( 'BLT_Family' ) ) {
			return '';
		}

		// BLT_Family::get() answers the default unless the owner opted this
		// plugin in to the group, but builds the family's group list (with
		// translated labels) before it asks. Asking first gives the same
		// answer without that work on every admin request of a site that
		// never opted in (SEC-9).
		// The elected family copy may come from another BLT plugin.
		if ( method_exists( 'BLT_Family', 'opted_in' ) && ! in_array( 'github', (array) BLT_Family::opted_in( 'blt-toolkit' ), true ) ) {
			return '';
		}

		$shared = BLT_Family::get( 'blt-toolkit', 'github', 'token' );

		return is_string( $shared ) ? trim( $shared ) : '';
	}

	/**
	 * The secrets option as an array.
	 *
	 * @return array
	 */
	private static function secrets() {
		$secrets = get_option( self::SECRETS_OPTION, array() );

		return is_array( $secrets ) ? $secrets : array();
	}

	/**
	 * Persist the secrets option (autoload no), or delete it when empty.
	 *
	 * @param array $secrets Envelopes keyed by secret name.
	 * @return void
	 */
	private static function write_secrets( array $secrets ) {
		self::$own_token = null;

		if ( empty( $secrets ) ) {
			delete_option( self::SECRETS_OPTION );
			return;
		}

		if ( false === get_option( self::SECRETS_OPTION, false ) ) {
			add_option( self::SECRETS_OPTION, $secrets, '', false );
			return;
		}

		update_option( self::SECRETS_OPTION, $secrets, false );
	}
}
