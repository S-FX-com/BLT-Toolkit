<?php
/**
 * Coexistence with the three plugins BLT Toolkit replaces, and the read-only
 * importers of their settings and data.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects User Switching, Username Changer and User Role Editor (GEN-1), and
 * imports what they leave behind (SPEC 15).
 *
 * Detection: when one of them is active, the BLT module that does the same job
 * must not boot: two plugins answering the same switch link, or rewriting the
 * same username, would fight each other. Detection uses the symbols each
 * plugin declares at load, so it is reliable from `plugins_loaded` onward and
 * costs no query.
 *
 * Importers (SPEC 15, CONVENTIONS 11), each previewed first (a plan that
 * writes nothing), confirmed with the hash of what the preview showed,
 * idempotent, recorded in the ledger `blt_toolkit_legacy_imports` (what, when,
 * by whom, highest source row ID), and never changing or deleting the source
 * plugin's data:
 *
 * - Username Changer 3.3.1 (`username_changer_settings`):
 *   - `allowed_roles` become `blt_change_own_username` on those roles, through
 *     BLT_Toolkit_Role_Store::transaction() (Guard, `before_import` snapshot,
 *     one write, log). A role that denies the capability on purpose keeps the
 *     deny.
 *   - `minimum_length`, `enable_notifications`, `email_subject` and
 *     `email_message` map to the Usernames settings; the template tags share
 *     names. A subject or message Username Changer never customized leaves
 *     BLT Toolkit's alone. Custom button labels and messages are dropped (use
 *     translations).
 *   - `{prefix}uc_audit_log` rows are copied into the activity log with
 *     `source = 'username-changer'`, in batches of BATCH rows in ID order,
 *     resumable by the highest imported ID, as the `legacy_uc_log` job when
 *     more than INLINE_MAX rows remain. Never duplicated: the cursor is the
 *     larger of the ledger's and the newest imported row's own source ID, and
 *     a lock row lets one batch run at a time.
 * - User Role Editor 4.66.2:
 *   - `user_role_editor['other_default_roles']` is added to
 *     `roles.extra_default_roles` (US-2), roles the person may give only
 *     (SEC-4).
 *   - `ure_role_additional_options_values[ role ]['hide_admin_bar']` becomes
 *     `roles.role_meta[ role ]['hide_toolbar']`, roles the person may edit
 *     only.
 *   - `{prefix}backup_user_roles` is stored as a role snapshot with reason
 *     `legacy_ure_backup`, once per distinct content.
 *   - `ure_*` capabilities are labeled "From User Role Editor" in the
 *     capability catalog (orphan_families()) and can be removed from every
 *     role and user in one step after a dry run (remove_ure_caps()), never
 *     while User Role Editor is active.
 * - User Switching keeps nothing on the server; there is nothing to import.
 *
 * The methods marked "pure" take plain values and touch no database, so
 * tests/test-legacy.php covers them with known vectors.
 */
class BLT_Toolkit_Legacy {

	/**
	 * The import ledger (SPEC 14.2, autoload no).
	 */
	const LEDGER_OPTION = 'blt_toolkit_legacy_imports';

	/**
	 * The lock row that lets one import step run at a time: an audit-log
	 * batch, a run of the importers, the `ure_*` removal and every write
	 * to the ledger.
	 */
	const LOCK_OPTION = 'blt_toolkit_legacy_lock';

	/**
	 * Seconds an import run waits for another run to release the lock, so
	 * a second click finds the first run's work done ("already imported")
	 * rather than "busy".
	 */
	const LOCK_WAIT = 10;

	/**
	 * Seconds after which a lock that was never released (a request that
	 * died mid-batch) may be taken over.
	 */
	const LOCK_STALE = 120;

	/**
	 * Username Changer's settings option (Simple_Settings, slug
	 * `username_changer`, confirmed in 3.3.1).
	 */
	const UC_OPTION = 'username_changer_settings';

	/**
	 * Username Changer's audit table, without the prefix
	 * (`Username_Changer_Audit_Log::get_table_name()`).
	 */
	const UC_TABLE = 'uc_audit_log';

	/**
	 * User Role Editor's settings option (`URE_Lib::get_instance(
	 * 'user_role_editor' )`).
	 */
	const URE_OPTION = 'user_role_editor';

	/**
	 * User Role Editor's per-role additional options
	 * (`URE_Role_Additional_Options::STORAGE_ID`).
	 */
	const URE_EXTRA_OPTION = 'ure_role_additional_options_values';

	/**
	 * Suffix of User Role Editor's one-time roles backup, after the blog
	 * prefix (`backup_wp_roles()`).
	 */
	const URE_BACKUP_SUFFIX = 'backup_user_roles';

	/**
	 * `source` of the log rows copied from Username Changer (SPEC 14.3).
	 */
	const SOURCE_UC = 'username-changer';

	/**
	 * Object ID of User Role Editor's imports in the log.
	 */
	const SOURCE_URE = 'user-role-editor';

	/**
	 * The job that copies a large audit log (JOB-5 extended).
	 */
	const JOB_TYPE = 'legacy_uc_log';

	/**
	 * Audit rows read and written per batch (one multi-row INSERT).
	 */
	const BATCH = 250;

	/**
	 * At most this many remaining rows are copied in the confirming request;
	 * more start the job.
	 */
	const INLINE_MAX = 1000;

	/**
	 * Seconds the confirming request spends copying before it hands the rest
	 * to the job.
	 */
	const INLINE_SECONDS = 20;

	/**
	 * The capability Username Changer's "Allowed Roles" become (UN-20).
	 */
	const SELF_SERVICE_CAP = 'blt_change_own_username';

	/**
	 * The capability family of User Role Editor's own capabilities.
	 */
	const URE_FAMILY = 'ure';

	/**
	 * The role nothing here ever changes for Username Changer or US-2.
	 */
	const ADMIN_ROLE = 'administrator';

	/**
	 * Snapshot reason of User Role Editor's backup (SPEC 14.3).
	 */
	const BACKUP_REASON = 'legacy_ure_backup';

	/**
	 * The tags BLT Toolkit replaces in the rename email
	 * (BLT_Toolkit_Username_Changer::email_tags(); a test keeps the two
	 * equal, so this class never loads the Usernames module's code).
	 */
	const BLT_TAGS = array( 'old_username', 'new_username', 'display_name', 'email', 'sitename', 'siteurl', 'loginurl' );

	/**
	 * Username Changer's custom labels and messages, which are dropped (SPEC
	 * 15: use translations).
	 */
	const UC_DROPPED = array( 'change_button_label', 'save_button_label', 'cancel_button_label', 'please_wait_message', 'success_message', 'relogin_message', 'error_short_username', 'error_wrong_permissions', 'error_duplicate_username', 'error_invalid_chars' );

	/**
	 * What a Username Changer import can bring over.
	 */
	const UC_ITEMS = array( 'uc_settings', 'uc_roles', 'uc_audit' );

	/**
	 * What a User Role Editor import can bring over (the `ure_*` removal is
	 * its own step).
	 */
	const URE_ITEMS = array( 'ure_default_roles', 'ure_toolbar', 'ure_backup' );

	/**
	 * Username Changer 3.3.1's default email subject (its `std`), which its
	 * settings screen shows in an empty field and stores on any save.
	 */
	const UC_STD_SUBJECT = 'Username change notification - {sitename}';

	/**
	 * Username Changer 3.3.1's default email message (its `std`), likewise.
	 */
	const UC_STD_MESSAGE = "Howdy! We're just writing to let you know that your username for {siteurl} has been changed to {new_username}.\n\nLogin now at {loginurl}";

	/**
	 * Every ledger entry and the fields it keeps, with their types.
	 */
	const LEDGER_FIELDS = array(
		'uc_settings'       => array( 'values' => 'array' ),
		'uc_roles'          => array(
			'roles'       => 'list',
			'snapshot_id' => 'int',
		),
		'uc_audit'          => array(
			'last_id' => 'int',
			'rows'    => 'int',
			'job'     => 'string',
		),
		'ure_default_roles' => array( 'roles' => 'list' ),
		'ure_toolbar'       => array( 'roles' => 'list' ),
		'ure_backup'        => array(
			'snapshot_id' => 'int',
			'hash'        => 'string',
		),
		'ure_caps'          => array(
			'removed'     => 'list',
			'snapshot_id' => 'int',
		),
	);

	/**
	 * Detection results for this request, keyed by module.
	 *
	 * @var array<string,bool>
	 */
	private static $detected = array();

	/**
	 * The lock token this request holds, or ''.
	 *
	 * @var string
	 */
	private static $held = '';

	/**
	 * How many nested acquire() calls share the held lock.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * The legacy plugin behind each module.
	 *
	 * `name` is the plugin's own product name, shown untranslated. `basename`
	 * is where the plugin normally lives, used only to point the owner at the
	 * right row on the Plugins screen; detection never relies on it, because
	 * a plugin can be installed under any folder name.
	 *
	 * @return array<string,array{name:string,basename:string}>
	 */
	public static function plugins() {
		return array(
			'switch'    => array(
				'name'     => 'User Switching',
				'basename' => 'user-switching/user-switching.php',
			),
			'usernames' => array(
				'name'     => 'Username Changer',
				'basename' => 'username-changer/class-username-changer.php',
			),
			'roles'     => array(
				'name'     => 'User Role Editor',
				'basename' => 'user-role-editor/user-role-editor.php',
			),
		);
	}

	/**
	 * Whether the legacy plugin matching a module is active (GEN-1).
	 *
	 * @param string $module Module key.
	 * @return bool
	 */
	public static function is_active( $module ) {
		$module = (string) $module;

		if ( ! array_key_exists( $module, self::$detected ) ) {
			self::$detected[ $module ] = self::detect( $module );
		}

		return self::$detected[ $module ];
	}

	/**
	 * The display name of the legacy plugin that blocks a module, or '' when
	 * nothing does.
	 *
	 * @param string $module Module key.
	 * @return string
	 */
	public static function conflict( $module ) {
		$plugins = self::plugins();
		$module  = (string) $module;

		if ( ! isset( $plugins[ $module ] ) || ! self::is_active( $module ) ) {
			return '';
		}

		return $plugins[ $module ]['name'];
	}

	/**
	 * Every module currently blocked, mapped to the legacy plugin's name.
	 *
	 * @return array<string,string>
	 */
	public static function conflicts() {
		$out = array();

		foreach ( array_keys( self::plugins() ) as $module ) {
			$name = self::conflict( $module );

			if ( '' !== $name ) {
				$out[ $module ] = $name;
			}
		}

		return $out;
	}

	/**
	 * Forget the cached detection (tests, or after a plugin was loaded late).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$detected = array();
	}

	/**
	 * Run the GEN-1 check for one module.
	 *
	 * @param string $module Module key.
	 * @return bool
	 */
	private static function detect( $module ) {
		switch ( $module ) {
			case 'switch':
				return class_exists( 'user_switching' );

			case 'usernames':
				return function_exists( 'username_changer' );

			case 'roles':
				return class_exists( 'URE_Lib' );
		}

		return false;
	}

	// ---------- Registration ----------

	/**
	 * Register the audit-log job type on `init` 5. Only adds a hook, so a
	 * front-end page view pays nothing. Called from BLT_Toolkit_Plugin::boot()
	 * whatever the modules' state: the import is a Tools feature.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_job_type' ), 5 );
	}

	/**
	 * Register the `legacy_uc_log` job type (JOB-5 extended): copies a large
	 * Username Changer audit log in batches, resumable.
	 *
	 * @return void
	 */
	public static function register_job_type() {
		if ( ! class_exists( 'BLT_Toolkit_Jobs' ) ) {
			return;
		}

		BLT_Toolkit_Jobs::register_type(
			self::JOB_TYPE,
			array(
				'label'      => __( 'Import Username Changer\'s audit log', 'blt-toolkit' ),
				// The import tab needs manage_options (SPEC 13.1).
				'capability' => 'manage_options',
				'mode'       => 'cursor',
				'process'    => array( __CLASS__, 'audit_step' ),
			)
		);
	}

	/**
	 * Labels of orphaned capability families whose plugin is known, read by
	 * the capability catalog (SPEC 15: `ure_*` is "From User Role Editor").
	 *
	 * @return array<string,string> Family => label.
	 */
	public static function orphan_families() {
		return array( self::URE_FAMILY => __( 'From User Role Editor', 'blt-toolkit' ) );
	}

	// ---------- Ledger (SPEC 14.2) ----------

	/**
	 * The import ledger, cleaned.
	 *
	 * @return array<string,array>
	 */
	public static function ledger() {
		return self::clean_ledger( get_option( self::LEDGER_OPTION, array() ) );
	}

	/**
	 * A stored ledger with only known entries and typed fields. Each entry
	 * keeps `at` (Unix time) and `by` (user ID) plus its own fields
	 * (LEDGER_FIELDS). Pure.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<string,array>
	 */
	public static function clean_ledger( $raw ) {
		$out = array();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( self::LEDGER_FIELDS as $key => $fields ) {
			if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
				continue;
			}

			$entry = array(
				'at' => isset( $raw[ $key ]['at'] ) && is_numeric( $raw[ $key ]['at'] ) ? max( 0, (int) $raw[ $key ]['at'] ) : 0,
				'by' => isset( $raw[ $key ]['by'] ) && is_numeric( $raw[ $key ]['by'] ) ? max( 0, (int) $raw[ $key ]['by'] ) : 0,
			);

			foreach ( $fields as $field => $type ) {
				$value = isset( $raw[ $key ][ $field ] ) ? $raw[ $key ][ $field ] : null;

				switch ( $type ) {
					case 'int':
						$entry[ $field ] = is_numeric( $value ) ? max( 0, (int) $value ) : 0;
						break;
					case 'string':
						$entry[ $field ] = is_string( $value ) ? $value : '';
						break;
					case 'list':
						$entry[ $field ] = is_array( $value ) ? array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : array();
						break;
					default:
						$entry[ $field ] = is_array( $value ) ? $value : array();
				}
			}

			$out[ $key ] = $entry;
		}

		return $out;
	}

	/**
	 * Record one import in the ledger, keeping the other entries.
	 *
	 * @param string $key   Ledger entry (LEDGER_FIELDS).
	 * @param array  $entry Its fields.
	 * @return void
	 */
	private static function record_import( $key, array $entry ) {
		// Read, change and write under the lock, from the database rather
		// than a copy cached earlier in this request, so a batch running in
		// another request never loses its update (SPEC 15, ledger).
		$held = self::acquire( self::LOCK_WAIT );

		try {
			self::write_ledger_entry( $key, $entry );
		} finally {
			if ( $held ) {
				self::release();
			}
		}
	}

	/**
	 * record_import()'s write, with the lock held (or not obtainable: the
	 * work it records is done, so it is recorded anyway).
	 *
	 * @param string $key   Ledger entry (LEDGER_FIELDS).
	 * @param array  $entry Its fields.
	 * @return void
	 */
	private static function write_ledger_entry( $key, array $entry ) {
		$ledger = self::fresh_ledger();

		// The audit cursor and count only ever grow.
		if ( 'uc_audit' === $key && isset( $ledger[ $key ] ) ) {
			$entry['last_id'] = max( isset( $entry['last_id'] ) ? (int) $entry['last_id'] : 0, (int) $ledger[ $key ]['last_id'] );
			$entry['rows']    = max( isset( $entry['rows'] ) ? (int) $entry['rows'] : 0, (int) $ledger[ $key ]['rows'] );
		}

		// A run that changed nothing keeps the entry as it was, so "Imported
		// on" still names the run that imported.
		if ( isset( $ledger[ $key ] ) && self::same_entry( $ledger[ $key ], $entry ) ) {
			return;
		}

		$ledger[ $key ] = array_merge(
			array(
				'at' => time(),
				'by' => get_current_user_id(),
			),
			$entry
		);

		if ( false === get_option( self::LEDGER_OPTION, false ) ) {
			add_option( self::LEDGER_OPTION, $ledger, '', false );
		} else {
			update_option( self::LEDGER_OPTION, $ledger, false );
		}
	}

	/**
	 * The ledger as stored now: drops this request's cached copy (and a
	 * cached "no such option") first, so a write made by another request
	 * since is seen.
	 *
	 * @return array<string,array>
	 */
	private static function fresh_ledger() {
		wp_cache_delete( self::LEDGER_OPTION, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $missing ) && isset( $missing[ self::LEDGER_OPTION ] ) ) {
			unset( $missing[ self::LEDGER_OPTION ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}

		return self::ledger();
	}

	// ---------- What the Overview shows (SPEC 13.2) ----------

	/**
	 * For the Overview's setup card and the Tools tab's cards: each legacy
	 * plugin, whether it is active, whether its data is still on the site,
	 * and how much of it was imported. A plugin counts as imported only when
	 * every item that has something to import is in the ledger
	 * (CONVENTIONS 11, SPEC 13.2); when some are and others are not, it is
	 * `partly` imported and the setup line stays. Reads the plugins' options
	 * (one query primes them) and, when Username Changer's audit table
	 * exists, whether rows wait beyond the cursor; never counts rows.
	 *
	 * @return array<string,array{name:string,active:bool,data:bool,imported:bool,partly:bool,missing:string[],at:int}>
	 */
	public static function setup_state() {
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches( array( self::LEDGER_OPTION, self::UC_OPTION, self::URE_OPTION, self::URE_EXTRA_OPTION, self::ure_backup_option() ) );
		}

		$ledger  = self::ledger();
		$plugins = self::plugins();
		$out     = array();
		$uc      = self::uc_settings_raw();
		$table   = self::uc_table_exists();
		$backup  = get_option( self::ure_backup_option(), null );
		$sources = array(
			'usernames' => array(
				'data'      => null !== $uc || $table,
				'items'     => self::UC_ITEMS,
				'available' => self::uc_available( $uc, $table, $ledger ),
			),
			'roles'     => array(
				'data'      => is_array( get_option( self::URE_OPTION, null ) ) || is_array( get_option( self::URE_EXTRA_OPTION, null ) ) || ( null !== $backup && false !== $backup ),
				'items'     => self::URE_ITEMS,
				'available' => self::ure_available( $backup, $ledger ),
			),
			'switch'    => array(
				'data'      => false,
				'items'     => array(),
				'available' => array(),
			),
		);

		foreach ( $sources as $module => $source ) {
			$at = 0;

			foreach ( $source['items'] as $item ) {
				if ( isset( $ledger[ $item ] ) ) {
					$at = max( $at, (int) $ledger[ $item ]['at'], 1 );
				}
			}

			$missing = self::missing_items( $source['available'], $ledger );

			$out[ $module ] = array(
				'name'     => $plugins[ $module ]['name'],
				'active'   => self::is_active( $module ),
				'data'     => $source['data'],
				'imported' => $at > 0 && array() === $missing,
				'partly'   => $at > 0 && array() !== $missing,
				'missing'  => $missing,
				'at'       => $at,
			);
		}

		return $out;
	}

	/**
	 * The items with something left to import that the ledger does not
	 * record as done. An item listed as available but recorded in the
	 * ledger counts as done, except `uc_audit`, whose availability already
	 * means rows wait beyond the cursor. Pure.
	 *
	 * @param string[] $available Items with something to import.
	 * @param array    $ledger    clean_ledger().
	 * @return string[]
	 */
	public static function missing_items( array $available, array $ledger ) {
		$out = array();

		foreach ( $available as $item ) {
			$item = (string) $item;

			if ( 'uc_audit' === $item || ! isset( $ledger[ $item ] ) ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Username Changer's items that have something to import: its settings
	 * while they exist, Allowed Roles while one of them lacks
	 * `blt_change_own_username` (neither granted nor denied), and the audit
	 * log while a row waits beyond the cursor and no job copies it.
	 *
	 * @param array|null $raw    uc_settings_raw().
	 * @param bool       $table  Whether its audit table exists.
	 * @param array      $ledger The ledger.
	 * @return string[]
	 */
	private static function uc_available( $raw, $table, array $ledger ) {
		global $wpdb;

		$out = array();

		if ( is_array( $raw ) ) {
			$out[]  = 'uc_settings';
			$wanted = self::uc_allowed_roles( isset( $raw['allowed_roles'] ) ? $raw['allowed_roles'] : array(), self::raw_role_names() );
			$roles  = (array) wp_roles()->roles;

			foreach ( $wanted['roles'] as $role ) {
				$caps = isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ? $roles[ $role ]['capabilities'] : array();

				if ( ! array_key_exists( self::SELF_SERVICE_CAP, $caps ) ) {
					$out[] = 'uc_roles';
					break;
				}
			}
		}

		if ( $table ) {
			if ( ! isset( $ledger['uc_audit'] ) ) {
				$out[] = 'uc_audit';
			} elseif ( '' === self::live_job() ) {
				$source = self::uc_table();

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only; one primary-key probe.
				if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$source} WHERE id > %d LIMIT 1", self::audit_cursor() ) ) ) {
					$out[] = 'uc_audit';
				}
			}
		}

		return $out;
	}

	/**
	 * User Role Editor's items that have something to import: other default
	 * roles BLT Toolkit does not give yet, roles whose admin bar it hid and
	 * BLT Toolkit does not hide yet, and its backup until it is stored.
	 *
	 * @param mixed $backup The backup option.
	 * @param array $ledger The ledger.
	 * @return string[]
	 */
	private static function ure_available( $backup, array $ledger ) {
		$known   = array_keys( (array) wp_roles()->roles );
		$current = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$meta    = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$meta    = is_array( $meta ) ? $meta : array();
		$out     = array();

		if ( array() !== self::ure_default_roles( get_option( self::URE_OPTION, null ), $known, is_array( $current ) ? $current : array() )['add'] ) {
			$out[] = 'ure_default_roles';
		}

		foreach ( self::ure_toolbar_roles( get_option( self::URE_EXTRA_OPTION, null ), $known )['roles'] as $role ) {
			if ( empty( $meta[ $role ]['hide_toolbar'] ) ) {
				$out[] = 'ure_toolbar';
				break;
			}
		}

		if ( null !== self::ure_backup_roles( $backup ) && ! isset( $ledger['ure_backup'] ) ) {
			$out[] = 'ure_backup';
		}

		return $out;
	}

	// ---------- Username Changer: reading ----------

	/**
	 * Username Changer's settings, or null when the option is not there.
	 *
	 * @return array|null
	 */
	public static function uc_settings_raw() {
		$raw = get_option( self::UC_OPTION, null );

		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * The audit table's full name.
	 *
	 * @return string
	 */
	public static function uc_table() {
		global $wpdb;

		return $wpdb->prefix . self::UC_TABLE;
	}

	/**
	 * Whether the audit table exists. One query; only the import tab asks.
	 *
	 * @return bool
	 */
	public static function uc_table_exists() {
		global $wpdb;

		$table = self::uc_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Another plugin's table; read on the import tab only.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * What a Username Changer import would do, read from the source and from
	 * BLT Toolkit's settings and roles now. Writes nothing (the role check is
	 * the role store's dry run).
	 *
	 * @param WP_User $actor Who would import.
	 * @return array {
	 *     @type bool       $active   Username Changer is active (GEN-1).
	 *     @type bool       $found    Its settings or its audit table exist.
	 *     @type array|null $settings null without settings; else `rows` (key, label, from, now, to, changes),
	 *                                `values` (key => value to write), `dropped` (labels dropped), `tags`
	 *                                (tags BLT Toolkit does not replace).
	 *     @type array      $roles    `add`, `already`, `denied`, `missing` (role ID => name), `guard` (true or WP_Error).
	 *     @type array      $audit    `exists`, `total`, `last_id`, `max_id`, `remaining`, `old`, `job` (live job ID or '').
	 *     @type array      $ledger   Ledger entries of this source.
	 *     @type string     $expected Hash of what an import would write (settings and roles).
	 * }
	 */
	public static function uc_plan( WP_User $actor ) {
		$raw    = self::uc_settings_raw();
		$ledger = self::ledger();
		$names  = self::role_names();
		$plan   = array(
			'active'   => self::is_active( 'usernames' ),
			'found'    => null !== $raw,
			'settings' => null,
			'roles'    => array(
				'add'     => array(),
				'already' => array(),
				'denied'  => array(),
				'missing' => array(),
				'guard'   => true,
			),
			'audit'    => self::audit_state(),
			'ledger'   => array_intersect_key( $ledger, array_flip( self::UC_ITEMS ) ),
			'expected' => '',
		);

		$plan['found'] = $plan['found'] || $plan['audit']['exists'];

		if ( null !== $raw ) {
			$mapped  = self::map_uc_settings( $raw );
			$current = BLT_Toolkit_Settings::get( 'usernames' );
			$rows    = array();

			foreach ( $mapped['values'] as $key => $value ) {
				$now    = is_array( $current ) && array_key_exists( $key, $current ) ? $current[ $key ] : null;
				$rows[] = array(
					'key'     => $key,
					'label'   => self::setting_label( $key ),
					'now'     => self::setting_text( $key, $now ),
					'to'      => self::setting_text( $key, $value ),
					'changes' => $now !== $value,
				);
			}

			foreach ( $mapped['kept'] as $key ) {
				$rows[] = array(
					'key'     => $key,
					'label'   => self::setting_label( $key ),
					'now'     => self::setting_text( $key, is_array( $current ) && isset( $current[ $key ] ) ? $current[ $key ] : null ),
					'to'      => '',
					'changes' => false,
				);
			}

			$plan['settings'] = array(
				'rows'    => $rows,
				'values'  => $mapped['values'],
				'kept'    => $mapped['kept'],
				'dropped' => $mapped['dropped'],
				'tags'    => $mapped['tags'],
			);

			$wanted = self::uc_allowed_roles( isset( $raw['allowed_roles'] ) ? $raw['allowed_roles'] : array(), self::raw_role_names() );
			$roles  = (array) wp_roles()->roles;

			foreach ( $wanted['roles'] as $role ) {
				$caps = isset( $roles[ $role ]['capabilities'] ) && is_array( $roles[ $role ]['capabilities'] ) ? $roles[ $role ]['capabilities'] : array();
				$name = isset( $names[ $role ] ) ? $names[ $role ] : $role;

				if ( ! array_key_exists( self::SELF_SERVICE_CAP, $caps ) ) {
					$plan['roles']['add'][ $role ] = $name;
				} elseif ( $caps[ self::SELF_SERVICE_CAP ] ) {
					$plan['roles']['already'][ $role ] = $name;
				} else {
					$plan['roles']['denied'][ $role ] = $name;
				}
			}

			foreach ( $wanted['missing'] as $role ) {
				$plan['roles']['missing'][ $role ] = $role;
			}

			if ( array() !== $plan['roles']['add'] ) {
				$dry = BLT_Toolkit_Role_Store::transaction(
					self::grant_mutator( array_keys( $plan['roles']['add'] ), self::SELF_SERVICE_CAP ),
					array(
						'dry_run' => true,
						'actor'   => $actor,
						'context' => 'legacy_import',
					)
				);

				if ( is_wp_error( $dry ) ) {
					$plan['roles']['guard'] = $dry;
				} elseif ( isset( $dry['guard'] ) ) {
					$plan['roles']['guard'] = $dry['guard'];
				}
			}
		}

		$plan['expected'] = self::uc_expected( $plan );

		return $plan;
	}

	/**
	 * The hash an import confirm must carry: what would be written and what
	 * it would replace.
	 *
	 * @param array $plan uc_plan().
	 * @return string
	 */
	private static function uc_expected( array $plan ) {
		return self::plan_hash(
			array(
				'settings' => null === $plan['settings'] ? null : $plan['settings']['values'],
				'current'  => BLT_Toolkit_Settings::get( 'usernames' ),
				'roles'    => array_keys( $plan['roles']['add'] ),
			)
		);
	}

	/**
	 * The audit table's state for the plan: whether it exists, its rows, the
	 * import cursor, how many rows remain and how many of those are older than
	 * the activity log keeps (LOG-4), and the live job, if any.
	 *
	 * @return array{exists:bool,total:int,last_id:int,max_id:int,remaining:int,old:int,job:string,retention:int}
	 */
	public static function audit_state() {
		global $wpdb;

		$out = array(
			'exists'    => self::uc_table_exists(),
			'total'     => 0,
			'last_id'   => 0,
			'max_id'    => 0,
			'remaining' => 0,
			'old'       => 0,
			'job'       => self::live_job(),
			'retention' => BLT_Toolkit_Log::retention_days(),
		);

		if ( ! $out['exists'] ) {
			return $out;
		}

		$table  = self::uc_table();
		$cursor = self::audit_cursor();
		$cutoff = BLT_Toolkit_Log::cutoff( $out['retention'] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only, on the import tab.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, MAX(id) AS max_id, SUM(CASE WHEN id > %d THEN 1 ELSE 0 END) AS remaining FROM {$table}", $cursor ), ARRAY_A );

		if ( null !== $cutoff ) {
			$out['old'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id > %d AND changed_at < %s", $cursor, get_date_from_gmt( $cutoff ) ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out['total']     = is_array( $row ) ? (int) $row['total'] : 0;
		$out['max_id']    = is_array( $row ) ? (int) $row['max_id'] : 0;
		$out['remaining'] = is_array( $row ) ? (int) $row['remaining'] : 0;
		$out['last_id']   = $cursor;

		return $out;
	}

	/**
	 * The ID of the live audit-log job, or ''.
	 *
	 * @return string
	 */
	public static function live_job() {
		$ledger = self::ledger();
		$id     = isset( $ledger['uc_audit']['job'] ) ? (string) $ledger['uc_audit']['job'] : '';

		if ( '' === $id || ! class_exists( 'BLT_Toolkit_Jobs' ) ) {
			return '';
		}

		$job = BLT_Toolkit_Jobs::get( $id );

		return null !== $job && in_array( (string) $job['status'], BLT_Toolkit_Jobs::LIVE, true ) ? $id : '';
	}

	/**
	 * The highest Username Changer row ID already in the activity log: the
	 * larger of the ledger's and the newest imported row's own `source_id`, so
	 * a batch whose ledger update was lost is never copied twice.
	 *
	 * @return int
	 */
	public static function audit_cursor() {
		global $wpdb;

		$ledger = self::ledger();
		$table  = BLT_Toolkit_Schema::table( 'log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; imported rows are the newest with this source.
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT details FROM {$table} WHERE source = %s ORDER BY id DESC LIMIT 1", self::SOURCE_UC ) );

		return self::cursor( isset( $ledger['uc_audit']['last_id'] ) ? (int) $ledger['uc_audit']['last_id'] : 0, $json );
	}

	// ---------- Username Changer: importing ----------

	/**
	 * Import from Username Changer: the chosen items, after the plan was
	 * worked out again and still hashes to what the preview showed.
	 *
	 * @param WP_User $actor    Who imports.
	 * @param array   $items    Of UC_ITEMS.
	 * @param string  $expected The preview's hash.
	 * @return array|WP_Error `done` (item => message), `job` (job ID or ''), `snapshot_id`.
	 */
	public static function import_uc( WP_User $actor, array $items, $expected ) {
		$items = array_values( array_intersect( self::UC_ITEMS, $items ) );

		if ( array() === $items ) {
			return new WP_Error( 'blt_toolkit_legacy_nothing', __( 'Choose at least one thing to import.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		// One run at a time (SPEC 15: imports are idempotent): a second
		// run waits, then works out its plan from what the first one wrote,
		// so it finds the work done instead of doing it again.
		if ( ! self::acquire( self::LOCK_WAIT ) ) {
			return self::busy_error();
		}

		try {
			return self::import_uc_locked( $actor, $items, $expected );
		} finally {
			self::release();
		}
	}

	/**
	 * import_uc() with the lock held.
	 *
	 * @param WP_User  $actor    Who imports.
	 * @param string[] $items    Of UC_ITEMS, at least one.
	 * @param string   $expected The preview's hash.
	 * @return array|WP_Error As import_uc().
	 */
	private static function import_uc_locked( WP_User $actor, array $items, $expected ) {
		$plan = self::uc_plan( $actor );

		if ( ! hash_equals( $plan['expected'], (string) $expected ) ) {
			return self::changed_error();
		}

		$out = array(
			'done'        => array(),
			'job'         => '',
			'snapshot_id' => 0,
		);

		if ( in_array( 'uc_roles', $items, true ) && null !== $plan['settings'] ) {
			$roles = self::import_uc_roles( $actor, $plan );

			if ( is_wp_error( $roles ) ) {
				return $roles;
			}

			$out['done']['uc_roles'] = $roles['message'];
			$out['snapshot_id']      = $roles['snapshot_id'];
		}

		if ( in_array( 'uc_settings', $items, true ) && null !== $plan['settings'] ) {
			$out['done']['uc_settings'] = self::import_uc_settings( $plan );
		}

		if ( in_array( 'uc_audit', $items, true ) && $plan['audit']['exists'] ) {
			$audit = self::import_uc_audit();

			if ( is_wp_error( $audit ) ) {
				$out['done']['uc_audit'] = $audit;
			} else {
				$out['done']['uc_audit'] = $audit['message'];
				$out['job']              = $audit['job'];
			}
		}

		return $out;
	}

	/**
	 * Write the mapped settings (SPEC 15) through BLT_Toolkit_Settings::set(),
	 * log them and record them.
	 *
	 * @param array $plan uc_plan().
	 * @return string What was done, in words.
	 */
	private static function import_uc_settings( array $plan ) {
		$before  = BLT_Toolkit_Settings::get( 'usernames' );
		$changed = array();

		foreach ( $plan['settings']['values'] as $key => $value ) {
			if ( ! is_array( $before ) || ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
				BLT_Toolkit_Settings::set( 'usernames', $key, $value );
				$changed[ $key ] = self::setting_label( $key );
			}
		}

		$after = BLT_Toolkit_Settings::get( 'usernames' );

		self::record_import( 'uc_settings', array( 'values' => $plan['settings']['values'] ) );

		if ( array() === $changed ) {
			$message = __( 'Username Changer\'s settings: BLT Toolkit already had the same values, so nothing changed.', 'blt-toolkit' );
		} else {
			$message = sprintf(
				/* translators: %s: a list of setting names. */
				__( 'Imported Username Changer\'s settings: %s.', 'blt-toolkit' ),
				implode( wp_get_list_item_separator(), $changed )
			);
		}

		$diff = array();

		foreach ( array_keys( $changed ) as $key ) {
			$diff[ $key ] = array(
				'from' => is_array( $before ) && isset( $before[ $key ] ) ? $before[ $key ] : null,
				'to'   => is_array( $after ) && isset( $after[ $key ] ) ? $after[ $key ] : null,
			);
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'legacy_imported',
				'object_type' => 'plugin',
				'object_id'   => self::SOURCE_UC,
				'summary'     => $message,
				'details'     => array(
					'source'  => self::SOURCE_UC,
					'item'    => 'uc_settings',
					'changes' => $diff,
					'kept'    => $plan['settings']['kept'],
					'dropped' => $plan['settings']['dropped'],
				),
			)
		);

		return $message;
	}

	/**
	 * Give `blt_change_own_username` to Username Changer's allowed roles
	 * (UN-20) through the role store: Guard, `before_import` snapshot, one
	 * write, log row `toolkit` / `legacy_imported`. Worked out again against
	 * the roles as stored now, so a role that gained the capability or a
	 * deny since the preview is left alone.
	 *
	 * @param WP_User $actor Who imports.
	 * @param array   $plan  uc_plan().
	 * @return array{message:string,snapshot_id:int}|WP_Error
	 */
	private static function import_uc_roles( WP_User $actor, array $plan ) {
		$targets = array_keys( $plan['roles']['add'] );

		if ( array() === $targets ) {
			$wanted = array_keys( $plan['roles']['already'] + $plan['roles']['denied'] );
			$ledger = self::ledger();

			// Nothing changes, so the entry keeps the snapshot of the run
			// that did change the roles; with 0 here, same_entry() would see
			// a difference and this no-op would replace that run's entry.
			self::record_import(
				'uc_roles',
				array(
					'roles'       => $wanted,
					'snapshot_id' => isset( $ledger['uc_roles']['snapshot_id'] ) ? (int) $ledger['uc_roles']['snapshot_id'] : 0,
				)
			);

			return array(
				'message'     => __( 'Who may change their own username: every allowed role was already set, so no role changed.', 'blt-toolkit' ),
				'snapshot_id' => 0,
			);
		}

		$names  = $plan['roles']['add'];
		$result = BLT_Toolkit_Role_Store::transaction(
			self::grant_mutator( $targets, self::SELF_SERVICE_CAP ),
			array(
				'actor'       => $actor,
				'reason'      => 'before_import',
				'context'     => 'legacy_import',
				'module'      => 'toolkit',
				'action'      => 'legacy_imported',
				'object_type' => 'plugin',
				'object_id'   => self::SOURCE_UC,
				'details'     => array(
					'source'     => self::SOURCE_UC,
					'item'       => 'uc_roles',
					'capability' => self::SELF_SERVICE_CAP,
				),
				'describe'    => static function ( array $diff, array $changes ) use ( $names ) {
					$labels = array();

					foreach ( array_keys( $changes ) as $role ) {
						$labels[] = isset( $names[ $role ] ) ? $names[ $role ] : (string) $role;
					}

					return array(
						'summary' => sprintf(
							/* translators: %s: a list of role names. */
							__( 'Imported from Username Changer: these roles may now change their own username: %s.', 'blt-toolkit' ),
							implode( wp_get_list_item_separator(), $labels )
						),
					);
				},
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$added = array_keys( $result['changes'] );

		self::record_import(
			'uc_roles',
			array(
				'roles'       => array_values( array_unique( array_merge( $added, array_keys( $plan['roles']['already'] ) ) ) ),
				'snapshot_id' => (int) $result['snapshot_id'],
			)
		);

		$labels = array();

		foreach ( $added as $role ) {
			$labels[] = isset( $names[ $role ] ) ? $names[ $role ] : (string) $role;
		}

		return array(
			'message'     => array() === $labels ? __( 'Who may change their own username: no role needed changing.', 'blt-toolkit' ) : sprintf(
				/* translators: %s: a list of role names. */
				__( 'These roles may now change their own username: %s.', 'blt-toolkit' ),
				implode( wp_get_list_item_separator(), $labels )
			),
			'snapshot_id' => (int) $result['snapshot_id'],
		);
	}

	/**
	 * Copy the audit log: in this request when at most INLINE_MAX rows
	 * remain (time-boxed; whatever is left goes to the job), as the
	 * `legacy_uc_log` job otherwise. A live job is never started twice.
	 *
	 * @return array{message:string,job:string}|WP_Error
	 */
	private static function import_uc_audit() {
		$live = self::live_job();

		if ( '' !== $live ) {
			return array(
				'message' => __( 'The audit log is already being imported; its progress is shown below.', 'blt-toolkit' ),
				'job'     => $live,
			);
		}

		$state  = self::audit_state();
		$pinned = self::audit_pinned();

		if ( 0 === $state['remaining'] ) {
			self::touch_audit_ledger( '' );

			return array(
				'message' => __( 'Username Changer\'s audit log: every entry was already imported, so nothing was copied.', 'blt-toolkit' ),
				'job'     => '',
			);
		}

		if ( $state['remaining'] > self::INLINE_MAX ) {
			return self::start_audit_job( $state, $pinned );
		}

		$start  = microtime( true );
		$copied = 0;

		do {
			$batch = self::import_audit_batch( self::BATCH, $pinned );

			if ( is_wp_error( $batch ) ) {
				if ( $copied > 0 ) {
					self::log_audit_import( $copied, self::audit_cursor(), 'warning' );
				}

				return $batch;
			}

			$copied += $batch['imported'];
		} while ( ! $batch['done'] && microtime( true ) - $start < self::INLINE_SECONDS );

		if ( ! $batch['done'] ) {
			$job = self::start_audit_job( self::audit_state(), $pinned );

			if ( $copied > 0 ) {
				self::log_audit_import( $copied, $batch['last_id'], 'success' );
			}

			return $job;
		}

		self::log_audit_import( $copied, $batch['last_id'], 'success' );

		return array(
			'message' => sprintf(
				/* translators: %s: number of entries. */
				_n( 'Copied %s entry of Username Changer\'s audit log into the activity log.', 'Copied %s entries of Username Changer\'s audit log into the activity log.', $copied, 'blt-toolkit' ),
				number_format_i18n( $copied )
			),
			'job'     => '',
		);
	}

	/**
	 * Start the `legacy_uc_log` job for the rest of the audit log.
	 *
	 * @param array $state  audit_state().
	 * @param array $pinned audit_pinned().
	 * @return array{message:string,job:string}|WP_Error
	 */
	private static function start_audit_job( array $state, array $pinned ) {
		if ( ! class_exists( 'BLT_Toolkit_Jobs' ) || null === BLT_Toolkit_Jobs::type( self::JOB_TYPE ) ) {
			return new WP_Error( 'blt_toolkit_legacy_no_job', __( 'The audit log is too large to copy in one request, and the background job is not available. Try again.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		$id = BLT_Toolkit_Jobs::start(
			self::JOB_TYPE,
			array(
				'total'   => (int) $state['remaining'],
				'done'    => 0,
				'copied'  => 0,
				'last_id' => (int) $state['last_id'],
			),
			$pinned
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		self::touch_audit_ledger( (string) $id );

		return array(
			'message' => sprintf(
				/* translators: %s: number of entries. */
				_n( 'Copying %s entry of Username Changer\'s audit log in the background. It keeps running if you leave this page.', 'Copying %s entries of Username Changer\'s audit log in the background. It keeps running if you leave this page.', (int) $state['remaining'], 'blt-toolkit' ),
				number_format_i18n( (int) $state['remaining'] )
			),
			'job'     => (string) $id,
		);
	}

	/**
	 * One step of the `legacy_uc_log` job (cursor mode): copy one batch.
	 *
	 * @param array $state  Job state, updated in place.
	 * @param array $pinned audit_pinned(), fixed when the job started (JOB-3).
	 * @return bool True when finished.
	 */
	public static function audit_step( array &$state, array $pinned ) {
		$batch = self::import_audit_batch( self::BATCH, $pinned );

		if ( is_wp_error( $batch ) ) {
			if ( 'blt_toolkit_legacy_busy' === $batch->get_error_code() ) {
				// Another request holds the lock for a moment; try again.
				usleep( 250000 );

				return false;
			}

			$state['error'] = $batch->get_error_message();

			if ( ! empty( $state['copied'] ) ) {
				self::log_audit_import( (int) $state['copied'], self::audit_cursor(), 'warning' );
			}

			return true;
		}

		$state['copied']  = ( isset( $state['copied'] ) ? (int) $state['copied'] : 0 ) + $batch['imported'];
		$state['done']    = min( isset( $state['total'] ) ? (int) $state['total'] : $state['copied'], $state['copied'] );
		$state['last_id'] = $batch['last_id'];
		/* translators: %d: row number in Username Changer's audit log. */
		$state['current'] = sprintf( __( 'Up to entry %d', 'blt-toolkit' ), $batch['last_id'] );

		if ( $batch['done'] ) {
			$state['done'] = isset( $state['total'] ) ? (int) $state['total'] : $state['copied'];

			self::log_audit_import( (int) $state['copied'], $batch['last_id'], 'success' );
		}

		return $batch['done'];
	}

	/**
	 * Settings every batch must see unchanged (JOB-3): whether IPs are
	 * recorded (LOG-5) and the site's timezone, which Username Changer's local
	 * times are read in.
	 *
	 * @return array{record_ip:bool,timezone:string}
	 */
	private static function audit_pinned() {
		return array(
			'record_ip' => (bool) BLT_Toolkit_Settings::get( 'log', 'record_ip' ),
			'timezone'  => wp_timezone()->getName(),
		);
	}

	/**
	 * Copy the next batch of audit rows after the cursor, in ID order, with
	 * one INSERT, then move the ledger's cursor. Holds the lock throughout,
	 * so two requests never copy the same rows.
	 *
	 * @param int   $limit  Rows.
	 * @param array $pinned audit_pinned().
	 * @return array{imported:int,last_id:int,done:bool}|WP_Error
	 */
	public static function import_audit_batch( $limit, array $pinned ) {
		global $wpdb;

		if ( ! self::acquire( 0 ) ) {
			return self::busy_error();
		}

		try {
			$cursor = self::audit_cursor();
			$table  = self::uc_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Another plugin's table, read only, by primary key.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, changed_at, changed_by_id, changed_by_login, old_username, new_username, ip_address, status FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $cursor, max( 1, (int) $limit ) ), ARRAY_A );

			if ( ! is_array( $rows ) ) {
				return new WP_Error( 'blt_toolkit_legacy_read_failed', __( 'Username Changer\'s audit log could not be read.', 'blt-toolkit' ), array( 'status' => 500 ) );
			}

			if ( array() === $rows ) {
				return array(
					'imported' => 0,
					'last_id'  => $cursor,
					'done'     => true,
				);
			}

			$timezone = self::timezone( isset( $pinned['timezone'] ) ? (string) $pinned['timezone'] : '' );
			$subjects = self::audit_subjects( $rows );
			$now      = gmdate( 'Y-m-d H:i:s' );
			$mapped   = array();
			$last     = $cursor;

			foreach ( $rows as $row ) {
				$new = isset( $row['new_username'] ) ? (string) $row['new_username'] : '';

				$mapped[] = self::map_audit_row(
					$row,
					array(
						'timezone'  => $timezone,
						'record_ip' => ! empty( $pinned['record_ip'] ),
						'user_id'   => isset( $subjects[ $new ] ) ? (int) $subjects[ $new ] : 0,
						'now'       => $now,
					)
				);

				$last = max( $last, (int) $row['id'] );
			}

			if ( ! self::insert_log_rows( $mapped ) ) {
				return new WP_Error( 'blt_toolkit_legacy_write_failed', __( 'The entries could not be written to the activity log. Nothing more was copied; try again.', 'blt-toolkit' ), array( 'status' => 500 ) );
			}

			$ledger = self::fresh_ledger();
			$entry  = isset( $ledger['uc_audit'] ) ? $ledger['uc_audit'] : array(
				'rows' => 0,
				'job'  => '',
			);

			self::record_import(
				'uc_audit',
				array(
					'last_id' => $last,
					'rows'    => (int) $entry['rows'] + count( $mapped ),
					'job'     => (string) $entry['job'],
				)
			);

			return array(
				'imported' => count( $mapped ),
				'last_id'  => $last,
				'done'     => count( $rows ) < max( 1, (int) $limit ),
			);
		} finally {
			self::release();
		}
	}

	/**
	 * Note the audit import in the ledger without copying (nothing left, or a
	 * job started), keeping its cursor and count.
	 *
	 * @param string $job Job ID, or ''.
	 * @return void
	 */
	private static function touch_audit_ledger( $job ) {
		$ledger = self::fresh_ledger();
		$entry  = isset( $ledger['uc_audit'] ) ? $ledger['uc_audit'] : array(
			'last_id' => 0,
			'rows'    => 0,
		);

		self::record_import(
			'uc_audit',
			array(
				'last_id' => max( (int) $entry['last_id'], self::audit_cursor() ),
				'rows'    => (int) $entry['rows'],
				'job'     => (string) $job,
			)
		);
	}

	/**
	 * One log row saying how many audit entries a run copied.
	 *
	 * @param int    $copied  Entries copied.
	 * @param int    $last_id Highest Username Changer ID copied.
	 * @param string $status  `success` or `warning` (the run stopped early).
	 * @return void
	 */
	private static function log_audit_import( $copied, $last_id, $status ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'legacy_imported',
				'object_type' => 'plugin',
				'object_id'   => self::SOURCE_UC,
				'summary'     => sprintf(
					/* translators: 1: number of entries, 2: highest entry number. */
					_n( 'Imported %1$s entry of Username Changer\'s audit log (up to entry %2$d).', 'Imported %1$s entries of Username Changer\'s audit log (up to entry %2$d).', (int) $copied, 'blt-toolkit' ),
					number_format_i18n( (int) $copied ),
					(int) $last_id
				),
				'details'     => array(
					'source'  => self::SOURCE_UC,
					'item'    => 'uc_audit',
					'copied'  => (int) $copied,
					'last_id' => (int) $last_id,
				),
				'status'      => $status,
			)
		);
	}

	/**
	 * Whom each row's new username names now: one query for the batch's new
	 * usernames, leaving out any that a later Username Changer row renamed
	 * away (the name may since belong to someone else).
	 *
	 * @param array[] $rows Audit rows of one batch.
	 * @return array<string,int> New username => user ID.
	 */
	private static function audit_subjects( array $rows ) {
		global $wpdb;

		$names = array();
		$last  = 0;

		foreach ( $rows as $row ) {
			$name = isset( $row['new_username'] ) ? (string) $row['new_username'] : '';

			if ( '' !== $name ) {
				$names[ $name ] = true;
			}

			$last = max( $last, (int) $row['id'] );
		}

		if ( array() === $names ) {
			return array();
		}

		$list = array_keys( $names );
		$in   = implode( ', ', array_fill( 0, count( $list ), '%s' ) );
		$uc   = self::uc_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One lookup per batch; the IN lists are placeholders only.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login FROM {$wpdb->users} WHERE user_login IN ( {$in} )", $list ), ARRAY_A );
		$moved = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT old_username FROM {$uc} WHERE id > %d AND old_username IN ( {$in} )", array_merge( array( $last ), $list ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$moved = array_flip( array_map( 'strval', is_array( $moved ) ? $moved : array() ) );
		$out   = array();

		foreach ( is_array( $found ) ? $found : array() as $user ) {
			$login = (string) $user['user_login'];

			if ( ! isset( $moved[ $login ] ) ) {
				$out[ $login ] = (int) $user['ID'];
			}
		}

		return $out;
	}

	/**
	 * Insert mapped rows into the activity log with one statement, so a batch
	 * is all written or not at all. `blt_toolkit_logged` (LOG-6) is not fired
	 * for these: they are history, not something that just happened.
	 *
	 * @param array[] $rows map_audit_row() results.
	 * @return bool
	 */
	private static function insert_log_rows( array $rows ) {
		global $wpdb;

		if ( array() === $rows ) {
			return true;
		}

		$columns = BLT_Toolkit_Log::COLUMNS;
		$values  = array();

		foreach ( $rows as $row ) {
			$placeholders = array();
			$args         = array();

			foreach ( $columns as $column => $format ) {
				if ( ! isset( $row[ $column ] ) ) {
					$placeholders[] = 'NULL';
					continue;
				}

				$placeholders[] = $format;
				$args[]         = $row[ $column ];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholders are the column formats; every value is bound.
			$values[] = $wpdb->prepare( '( ' . implode( ', ', $placeholders ) . ' )', $args );
		}

		$table      = BLT_Toolkit_Schema::table( 'log' );
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Our own table; each row was prepared above.
		$ok    = $wpdb->query( "INSERT INTO {$table} (" . implode( ', ', array_keys( $columns ) ) . ') VALUES ' . implode( ', ', $values ) );
		$error = wp_strip_all_tags( (string) $wpdb->last_error );

		$wpdb->suppress_errors( $suppressed );

		if ( false === $ok ) {
			error_log( 'BLT Toolkit: could not copy Username Changer\'s audit rows: ' . $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed import must be visible to the site owner's logs.

			return false;
		}

		return true;
	}

	// ---------- User Role Editor: reading ----------

	/**
	 * The name of User Role Editor's roles backup option on this site.
	 *
	 * @return string
	 */
	public static function ure_backup_option() {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::URE_BACKUP_SUFFIX;
	}

	/**
	 * What a User Role Editor import would do. Writes nothing.
	 *
	 * @param WP_User $actor Who would import.
	 * @return array {
	 *     @type bool   $active   User Role Editor is active (GEN-1).
	 *     @type bool   $found    Any of its options exist.
	 *     @type array  $defaults `add`, `already`, `missing`, `refused` (role ID => name).
	 *     @type array  $toolbar  `add`, `already`, `missing`, `refused` (role ID => name or reason).
	 *     @type array  $backup   `exists`, `valid`, `roles` (count), `hash`, `imported` (snapshot ID or 0), `counts` (vs. today).
	 *     @type array  $caps     ure_caps_plan().
	 *     @type array  $ledger   Ledger entries of this source.
	 *     @type string $expected Hash of what an import would write.
	 * }
	 */
	public static function ure_plan( WP_User $actor ) {
		$settings = get_option( self::URE_OPTION, null );
		$extra    = get_option( self::URE_EXTRA_OPTION, null );
		$backup   = get_option( self::ure_backup_option(), null );
		$names    = self::role_names();
		$known    = array_keys( (array) wp_roles()->roles );
		$ledger   = self::ledger();
		$is_admin = BLT_Toolkit_Guard::is_toolkit_admin( $actor );

		$plan = array(
			'active'   => self::is_active( 'roles' ),
			'found'    => is_array( $settings ) || is_array( $extra ) || null !== $backup,
			'defaults' => array(
				'add'     => array(),
				'already' => array(),
				'missing' => array(),
				'refused' => array(),
			),
			'toolbar'  => array(
				'add'     => array(),
				'already' => array(),
				'missing' => array(),
				'refused' => array(),
			),
			'backup'   => array(
				'exists'   => null !== $backup && false !== $backup,
				'valid'    => false,
				'roles'    => 0,
				'hash'     => '',
				'imported' => 0,
				'counts'   => null,
			),
			'caps'     => self::ure_caps_plan( $actor ),
			'ledger'   => array_intersect_key( $ledger, array_flip( array_merge( self::URE_ITEMS, array( 'ure_caps' ) ) ) ),
			'expected' => '',
		);

		// US-2: User Role Editor's other default roles.
		$current  = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$current  = is_array( $current ) ? $current : array();
		$defaults = self::ure_default_roles( $settings, $known, $current );

		foreach ( $defaults['add'] as $role ) {
			if ( $is_admin || BLT_Toolkit_Guard::may_assign_role( $role ) ) {
				$plan['defaults']['add'][ $role ] = isset( $names[ $role ] ) ? $names[ $role ] : $role;
			} else {
				$plan['defaults']['refused'][ $role ] = isset( $names[ $role ] ) ? $names[ $role ] : $role;
			}
		}

		foreach ( $defaults['already'] as $role ) {
			$plan['defaults']['already'][ $role ] = isset( $names[ $role ] ) ? $names[ $role ] : $role;
		}

		foreach ( $defaults['missing'] as $role ) {
			$plan['defaults']['missing'][ $role ] = $role;
		}

		// SPEC 9.5: hide-admin-bar flags.
		$meta    = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$meta    = is_array( $meta ) ? $meta : array();
		$toolbar = self::ure_toolbar_roles( $extra, $known );

		foreach ( $toolbar['roles'] as $role ) {
			$name = isset( $names[ $role ] ) ? $names[ $role ] : $role;

			if ( ! empty( $meta[ $role ]['hide_toolbar'] ) ) {
				$plan['toolbar']['already'][ $role ] = $name;
				continue;
			}

			$check = BLT_Toolkit_Guard::check_role_change( $actor, $role, array(), array() );

			if ( true === $check ) {
				$plan['toolbar']['add'][ $role ] = $name;
			} else {
				$plan['toolbar']['refused'][ $role ] = is_wp_error( $check ) ? $name . ': ' . $check->get_error_message() : $name;
			}
		}

		foreach ( $toolbar['missing'] as $role ) {
			$plan['toolbar']['missing'][ $role ] = $role;
		}

		// SNAP-*: the one-time backup.
		$roles = self::ure_backup_roles( $backup );

		if ( null !== $roles ) {
			$plan['backup']['valid']    = true;
			$plan['backup']['roles']    = count( $roles );
			$plan['backup']['hash']     = BLT_Toolkit_Role_Diff::hash( $roles );
			$plan['backup']['imported'] = self::backup_snapshot_id( $plan['backup']['hash'] );
			$plan['backup']['counts']   = BLT_Toolkit_Role_Diff::counts( BLT_Toolkit_Role_Diff::diff( $roles, (array) wp_roles()->roles ) );
		}

		$plan['expected'] = self::plan_hash(
			array(
				'defaults' => array_keys( $plan['defaults']['add'] ),
				'current'  => $current,
				'toolbar'  => array_keys( $plan['toolbar']['add'] ),
				'meta'     => $meta,
				'backup'   => $plan['backup']['hash'],
			)
		);

		return $plan;
	}

	/**
	 * The stored snapshot of User Role Editor's backup with this content, or
	 * 0: the ledger's, while it still exists with that hash, or any snapshot
	 * stored for the same reason with the same hash.
	 *
	 * @param string $hash Role_Diff::hash() of the backup.
	 * @return int
	 */
	private static function backup_snapshot_id( $hash ) {
		global $wpdb;

		if ( '' === (string) $hash ) {
			return 0;
		}

		$table = BLT_Toolkit_Schema::table( 'snapshots' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table, small (SNAP-3).
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE reason = %s AND hash = %s ORDER BY id DESC LIMIT 1", self::BACKUP_REASON, (string) $hash ) );
	}

	/**
	 * Where User Role Editor's own capabilities (`ure_*`) are held, and
	 * whether this person may remove them all: a dry run through the role
	 * store for the roles and SEC checks per user. Never offered while User
	 * Role Editor is active (it needs them), nor for a name the capability
	 * catalog says something registers (RO-24).
	 *
	 * @param WP_User $actor Who would remove them.
	 * @return array{roles:array,users:array,caps:string[],guard:true|WP_Error,blocked:string,expected:string}
	 *         `roles`: role ID => capabilities; `users`: user ID => array( 'label', 'caps' ).
	 */
	public static function ure_caps_plan( WP_User $actor ) {
		global $wpdb;

		$out = array(
			'roles'    => array(),
			'users'    => array(),
			'caps'     => array(),
			'guard'    => true,
			'blocked'  => '',
			'expected' => '',
		);

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$caps = self::ure_caps_in( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array() );

			if ( array() !== $caps ) {
				$out['roles'][ (string) $id ] = $caps;
			}
		}

		$key = $wpdb->get_blog_prefix() . 'capabilities';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of who holds User Role Editor's capabilities, on the import tab only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY user_id ASC", $key, '%' . $wpdb->esc_like( '"' . self::URE_FAMILY . '_' ) . '%' ), ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta = maybe_unserialize( $row['meta_value'] );
			$caps = is_array( $meta ) ? self::ure_caps_in( $meta ) : array();

			// A key named like a role is a membership, never a capability.
			$caps = array_values(
				array_filter(
					$caps,
					static function ( $cap ) {
						return ! wp_roles()->is_role( $cap );
					}
				)
			);

			if ( array() === $caps ) {
				continue;
			}

			$user = get_userdata( (int) $row['user_id'] );

			$out['users'][ (int) $row['user_id'] ] = array(
				'label' => $user instanceof WP_User ? (string) $user->user_login : '#' . (int) $row['user_id'],
				'caps'  => $caps,
			);
		}

		$all = array();

		foreach ( $out['roles'] as $caps ) {
			$all = array_merge( $all, $caps );
		}

		foreach ( $out['users'] as $user ) {
			$all = array_merge( $all, $user['caps'] );
		}

		$all = array_values( array_unique( $all ) );
		sort( $all, SORT_STRING );

		$out['caps']     = $all;
		$out['expected'] = self::plan_hash(
			array(
				'roles' => $out['roles'],
				'users' => array_map(
					static function ( $user ) {
						return $user['caps'];
					},
					$out['users']
				),
			)
		);

		if ( array() === $all ) {
			return $out;
		}

		if ( self::is_active( 'roles' ) ) {
			$out['blocked'] = __( 'User Role Editor is active and uses these capabilities. Deactivate it first.', 'blt-toolkit' );

			return $out;
		}

		// RO-24: never offer to delete a name something registers. The
		// catalog is the Roles module's code; with the module off, the names
		// are checked against the module-free rule only (nothing active
		// registers `ure_*` once User Role Editor is inactive).
		if ( BLT_Toolkit_Plugin::is_active( 'roles' ) && class_exists( 'BLT_Toolkit_Capability_Catalog' ) ) {
			$registered = array_values(
				array_filter(
					$all,
					static function ( $cap ) {
						$entries = BLT_Toolkit_Capability_Catalog::all();

						return isset( $entries[ $cap ] ) && empty( $entries[ $cap ]['orphan'] );
					}
				)
			);

			if ( array() !== $registered ) {
				/* translators: %s: a list of capability names. */
				$out['blocked'] = sprintf( __( 'Something on this site registers %s, so they are not removed.', 'blt-toolkit' ), implode( wp_get_list_item_separator(), $registered ) );

				return $out;
			}
		}

		if ( array() !== $out['roles'] ) {
			$dry = BLT_Toolkit_Role_Store::transaction(
				self::remove_mutator( $all ),
				array(
					'dry_run' => true,
					'actor'   => $actor,
					'context' => 'legacy_cleanup',
				)
			);

			if ( is_wp_error( $dry ) ) {
				$out['guard'] = $dry;
			} elseif ( isset( $dry['guard'] ) && true !== $dry['guard'] ) {
				$out['guard'] = $dry['guard'];
			}
		}

		if ( true === $out['guard'] ) {
			foreach ( $out['users'] as $user_id => $user ) {
				$target = get_userdata( (int) $user_id );

				if ( ! $target instanceof WP_User ) {
					continue;
				}

				$check = BLT_Toolkit_Guard::can_change_user_caps( $actor, $target, array(), $user['caps'] );

				if ( is_wp_error( $check ) ) {
					$out['guard'] = $check;
					break;
				}
			}
		}

		return $out;
	}

	// ---------- User Role Editor: importing ----------

	/**
	 * Import from User Role Editor: the chosen items, after the plan was
	 * worked out again and still hashes to what the preview showed.
	 *
	 * @param WP_User $actor    Who imports.
	 * @param array   $items    Of URE_ITEMS.
	 * @param string  $expected The preview's hash.
	 * @return array|WP_Error `done` (item => message), `snapshot_id` (the backup's).
	 */
	public static function import_ure( WP_User $actor, array $items, $expected ) {
		$items = array_values( array_intersect( self::URE_ITEMS, $items ) );

		if ( array() === $items ) {
			return new WP_Error( 'blt_toolkit_legacy_nothing', __( 'Choose at least one thing to import.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		// As import_uc(): a concurrent run waits and then finds the backup
		// stored and the settings written (SPEC 15).
		if ( ! self::acquire( self::LOCK_WAIT ) ) {
			return self::busy_error();
		}

		try {
			return self::import_ure_locked( $actor, $items, $expected );
		} finally {
			self::release();
		}
	}

	/**
	 * import_ure() with the lock held.
	 *
	 * @param WP_User  $actor    Who imports.
	 * @param string[] $items    Of URE_ITEMS, at least one.
	 * @param string   $expected The preview's hash.
	 * @return array|WP_Error As import_ure().
	 */
	private static function import_ure_locked( WP_User $actor, array $items, $expected ) {
		$plan = self::ure_plan( $actor );

		if ( ! hash_equals( $plan['expected'], (string) $expected ) ) {
			return self::changed_error();
		}

		$out = array(
			'done'        => array(),
			'snapshot_id' => 0,
		);

		if ( in_array( 'ure_default_roles', $items, true ) ) {
			$out['done']['ure_default_roles'] = self::import_ure_defaults( $plan );
		}

		if ( in_array( 'ure_toolbar', $items, true ) ) {
			$out['done']['ure_toolbar'] = self::import_ure_toolbar( $plan );
		}

		if ( in_array( 'ure_backup', $items, true ) && $plan['backup']['valid'] ) {
			$backup = self::import_ure_backup( $plan );

			if ( is_wp_error( $backup ) ) {
				$out['done']['ure_backup'] = $backup;
			} else {
				$out['done']['ure_backup'] = $backup['message'];
				$out['snapshot_id']        = $backup['snapshot_id'];
			}
		}

		return $out;
	}

	/**
	 * Add User Role Editor's other default roles to `roles.extra_default_roles`
	 * (US-2), keeping the ones already there.
	 *
	 * @param array $plan ure_plan().
	 * @return string What was done, in words.
	 */
	private static function import_ure_defaults( array $plan ) {
		$add     = array_keys( $plan['defaults']['add'] );
		$current = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );
		$current = is_array( $current ) ? $current : array();

		if ( array() !== $add ) {
			BLT_Toolkit_Settings::set( 'roles', 'extra_default_roles', array_values( array_unique( array_merge( $current, $add ) ) ) );
		}

		self::record_import( 'ure_default_roles', array( 'roles' => array_values( array_unique( array_merge( $add, array_keys( $plan['defaults']['already'] ) ) ) ) ) );

		if ( array() === $add ) {
			return __( 'Other default roles: nothing to add.', 'blt-toolkit' );
		}

		$message = sprintf(
			/* translators: %s: a list of role names. */
			__( 'New users now also get these roles: %s.', 'blt-toolkit' ),
			implode( wp_get_list_item_separator(), $plan['defaults']['add'] )
		);

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'legacy_imported',
				'object_type' => 'plugin',
				'object_id'   => self::SOURCE_URE,
				'summary'     => sprintf(
					/* translators: %s: a list of role names. */
					__( 'Imported User Role Editor\'s other default roles: %s.', 'blt-toolkit' ),
					implode( wp_get_list_item_separator(), $plan['defaults']['add'] )
				),
				'details'     => array(
					'source'              => self::SOURCE_URE,
					'item'                => 'ure_default_roles',
					'extra_default_roles' => array(
						'from' => $current,
						'to'   => BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' ),
					),
				),
			)
		);

		return $message;
	}

	/**
	 * Set `role_meta[ role ]['hide_toolbar']` for the roles User Role Editor
	 * hid the admin bar for (SPEC 9.5), keeping each role's description.
	 *
	 * @param array $plan ure_plan().
	 * @return string What was done, in words.
	 */
	private static function import_ure_toolbar( array $plan ) {
		$add  = $plan['toolbar']['add'];
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$meta = is_array( $meta ) ? $meta : array();

		foreach ( array_keys( $add ) as $role ) {
			$meta[ $role ] = array(
				'description'  => isset( $meta[ $role ]['description'] ) ? (string) $meta[ $role ]['description'] : '',
				'hide_toolbar' => true,
			);
		}

		if ( array() !== $add ) {
			BLT_Toolkit_Settings::set( 'roles', 'role_meta', $meta );
		}

		self::record_import( 'ure_toolbar', array( 'roles' => array_values( array_unique( array_merge( array_keys( $add ), array_keys( $plan['toolbar']['already'] ) ) ) ) ) );

		if ( array() === $add ) {
			return __( 'Hidden toolbars: nothing to change.', 'blt-toolkit' );
		}

		$message = sprintf(
			/* translators: %s: a list of role names. */
			__( 'The toolbar is now hidden on the site for: %s.', 'blt-toolkit' ),
			implode( wp_get_list_item_separator(), $add )
		);

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'legacy_imported',
				'object_type' => 'plugin',
				'object_id'   => self::SOURCE_URE,
				'summary'     => sprintf(
					/* translators: %s: a list of role names. */
					__( 'Imported User Role Editor\'s hidden admin bar for: %s.', 'blt-toolkit' ),
					implode( wp_get_list_item_separator(), $add )
				),
				'details'     => array(
					'source' => self::SOURCE_URE,
					'item'   => 'ure_toolbar',
					'roles'  => array_keys( $add ),
				),
			)
		);

		return $message;
	}

	/**
	 * Store User Role Editor's roles backup as a snapshot with reason
	 * `legacy_ure_backup` (SPEC 14.3), unless one with the same content is
	 * stored already. Writes no role; Tools > Snapshots can view and restore
	 * it like any other.
	 *
	 * @param array $plan ure_plan().
	 * @return array{message:string,snapshot_id:int}|WP_Error
	 */
	private static function import_ure_backup( array $plan ) {
		// Asked again here, with the lock held, so a backup another run
		// stored a moment ago is found and never stored twice.
		$existing = max( (int) $plan['backup']['imported'], self::backup_snapshot_id( $plan['backup']['hash'] ) );

		if ( $existing > 0 ) {
			self::record_import(
				'ure_backup',
				array(
					'snapshot_id' => $existing,
					'hash'        => $plan['backup']['hash'],
				)
			);

			return array(
				/* translators: %d: snapshot number. */
				'message'     => sprintf( __( 'User Role Editor\'s backup is already stored as snapshot %d.', 'blt-toolkit' ), $existing ),
				'snapshot_id' => $existing,
			);
		}

		$roles = self::ure_backup_roles( get_option( self::ure_backup_option(), null ) );

		if ( null === $roles || ! hash_equals( $plan['backup']['hash'], BLT_Toolkit_Role_Diff::hash( $roles ) ) ) {
			return self::changed_error();
		}

		// Not the current roles, so not take(): store() keeps them as they
		// are, with the same canonical JSON and hash, and prunes (SNAP-3),
		// which never removes this reason (Snapshots::KEPT_REASONS).
		$id = BLT_Toolkit_Snapshots::store( self::BACKUP_REASON, $roles );

		if ( $id <= 0 ) {
			return new WP_Error( 'blt_toolkit_snapshot_failed', __( 'User Role Editor\'s backup could not be stored as a snapshot. Try again.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		self::record_import(
			'ure_backup',
			array(
				'snapshot_id' => $id,
				'hash'        => $plan['backup']['hash'],
			)
		);

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'legacy_imported',
				'object_type' => 'snapshot',
				'object_id'   => (string) $id,
				/* translators: %d: snapshot number. */
				'summary'     => sprintf( __( 'Stored User Role Editor\'s roles backup as snapshot %d.', 'blt-toolkit' ), $id ),
				'details'     => array(
					'source' => self::SOURCE_URE,
					'item'   => 'ure_backup',
					'roles'  => (int) $plan['backup']['roles'],
					'hash'   => $plan['backup']['hash'],
				),
				'snapshot_id' => $id,
			)
		);

		return array(
			/* translators: %d: snapshot number. */
			'message'     => sprintf( __( 'User Role Editor\'s backup is stored as snapshot %d. Find it on the Snapshots tab.', 'blt-toolkit' ), $id ),
			'snapshot_id' => $id,
		);
	}

	/**
	 * Remove every `ure_*` capability from every role (through the role
	 * store: Guard, snapshot, one write, log) and user, after the plan was
	 * worked out again and still hashes to what the dry run showed.
	 *
	 * @param WP_User $actor    Who removes them.
	 * @param string  $expected The dry run's hash.
	 * @return array{message:string,snapshot_id:int,caps:string[]}|WP_Error
	 */
	public static function remove_ure_caps( WP_User $actor, $expected ) {
		if ( ! self::acquire( self::LOCK_WAIT ) ) {
			return self::busy_error();
		}

		try {
			return self::remove_ure_caps_locked( $actor, $expected );
		} finally {
			self::release();
		}
	}

	/**
	 * remove_ure_caps() with the lock held.
	 *
	 * @param WP_User $actor    Who removes them.
	 * @param string  $expected The dry run's hash.
	 * @return array{message:string,snapshot_id:int,caps:string[]}|WP_Error As remove_ure_caps().
	 */
	private static function remove_ure_caps_locked( WP_User $actor, $expected ) {
		$plan = self::ure_caps_plan( $actor );

		if ( ! hash_equals( $plan['expected'], (string) $expected ) ) {
			return self::changed_error();
		}

		if ( array() === $plan['caps'] ) {
			return array(
				'message'     => __( 'No role or user holds a User Role Editor capability, so nothing was removed.', 'blt-toolkit' ),
				'snapshot_id' => 0,
				'caps'        => array(),
			);
		}

		if ( '' !== $plan['blocked'] ) {
			return new WP_Error( 'blt_toolkit_legacy_blocked', $plan['blocked'], array( 'status' => 409 ) );
		}

		if ( true !== $plan['guard'] ) {
			return $plan['guard'];
		}

		$caps        = $plan['caps'];
		$snapshot_id = 0;
		$summary     = sprintf(
			/* translators: 1: number of capabilities, 2: a list of their names. */
			_n( 'Removed %1$s User Role Editor capability: %2$s.', 'Removed %1$s User Role Editor capabilities: %2$s.', count( $caps ), 'blt-toolkit' ),
			number_format_i18n( count( $caps ) ),
			implode( wp_get_list_item_separator(), $caps )
		);
		// Per user, what is removed: no snapshot covers users' own
		// capabilities, so this row is the record (CONVENTIONS 11).
		$details = array(
			'source' => self::SOURCE_URE,
			'item'   => 'ure_caps',
			'caps'   => $caps,
			'roles'  => array_keys( $plan['roles'] ),
			'users'  => array_map(
				static function ( $user ) {
					return isset( $user['caps'] ) ? array_values( (array) $user['caps'] ) : array();
				},
				$plan['users']
			),
		);

		if ( array() !== $plan['roles'] ) {
			$result = BLT_Toolkit_Role_Store::transaction(
				self::remove_mutator( $caps ),
				array(
					'actor'       => $actor,
					'reason'      => 'before_save',
					'context'     => 'legacy_cleanup',
					'module'      => 'roles',
					'action'      => 'capability_deleted',
					'object_type' => 'capability',
					'object_id'   => implode( ', ', $caps ),
					'summary'     => $summary,
					'details'     => $details,
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$snapshot_id = (int) $result['snapshot_id'];
		}

		foreach ( $plan['users'] as $user_id => $user ) {
			$target = get_userdata( (int) $user_id );

			if ( ! $target instanceof WP_User ) {
				continue;
			}

			foreach ( $user['caps'] as $cap ) {
				if ( is_array( $target->caps ) && array_key_exists( $cap, $target->caps ) ) {
					$target->remove_cap( $cap );
				}
			}
		}

		if ( array() === $plan['roles'] ) {
			BLT_Toolkit_Log::record(
				array(
					'module'      => 'roles',
					'action'      => 'capability_deleted',
					'object_type' => 'capability',
					'object_id'   => implode( ', ', $caps ),
					'summary'     => $summary,
					'details'     => $details,
				)
			);
		}

		self::record_import(
			'ure_caps',
			array(
				'removed'     => $caps,
				'snapshot_id' => $snapshot_id,
			)
		);

		return array(
			'message'     => $summary,
			'snapshot_id' => $snapshot_id,
			'caps'        => $caps,
		);
	}

	// ---------- Shared helpers ----------

	/**
	 * "Nothing was imported: this changed since the preview".
	 *
	 * @return WP_Error
	 */
	private static function changed_error() {
		return new WP_Error( 'blt_toolkit_legacy_changed', __( 'Something changed since the preview was shown, so nothing was imported. Check the new preview and try again.', 'blt-toolkit' ), array( 'status' => 409 ) );
	}

	/**
	 * A role-store mutator that grants a capability to roles that do not
	 * mention it; a role that grants or denies it already is left alone.
	 *
	 * @param string[] $roles Role IDs.
	 * @param string   $cap   Capability.
	 * @return callable
	 */
	private static function grant_mutator( array $roles, $cap ) {
		return static function ( array $all ) use ( $roles, $cap ) {
			foreach ( $roles as $role ) {
				if ( ! isset( $all[ $role ] ) || ! is_array( $all[ $role ] ) ) {
					continue;
				}

				// A role stored with capabilities that are not an array grants
				// nothing; start it from none.
				if ( ! isset( $all[ $role ]['capabilities'] ) || ! is_array( $all[ $role ]['capabilities'] ) ) {
					$all[ $role ]['capabilities'] = array();
				}

				if ( ! array_key_exists( $cap, $all[ $role ]['capabilities'] ) ) {
					$all[ $role ]['capabilities'][ $cap ] = true;
				}
			}

			return $all;
		};
	}

	/**
	 * A role-store mutator that takes capabilities out of every role.
	 *
	 * @param string[] $caps Capabilities.
	 * @return callable
	 */
	private static function remove_mutator( array $caps ) {
		return static function ( array $all ) use ( $caps ) {
			foreach ( $all as $id => $role ) {
				if ( ! isset( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
					continue;
				}

				foreach ( $caps as $cap ) {
					unset( $all[ $id ]['capabilities'][ $cap ] );
				}
			}

			return $all;
		};
	}

	/**
	 * Role ID => translated display name.
	 *
	 * @return array<string,string>
	 */
	private static function role_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ] = translate_user_role( (string) $name );
		}

		return $out;
	}

	/**
	 * Role ID => display name as stored (what Username Changer saved).
	 *
	 * @return array<string,string>
	 */
	private static function raw_role_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ] = (string) $name;
		}

		return $out;
	}

	/**
	 * A Usernames setting's name as Settings > Usernames shows it.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function setting_label( $key ) {
		switch ( (string) $key ) {
			case 'min_length':
				return __( 'Minimum length', 'blt-toolkit' );
			case 'notify_user':
				return __( 'Email the person about the change', 'blt-toolkit' );
			case 'email_subject':
				return __( 'Email subject', 'blt-toolkit' );
			case 'email_body':
				return __( 'Email message', 'blt-toolkit' );
		}

		return (string) $key;
	}

	/**
	 * A Usernames setting's value in words.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public static function setting_text( $key, $value ) {
		if ( 'notify_user' === $key ) {
			return $value ? __( 'On', 'blt-toolkit' ) : __( 'Off', 'blt-toolkit' );
		}

		if ( 'min_length' === $key ) {
			return is_numeric( $value ) ? number_format_i18n( (int) $value ) : '';
		}

		if ( null === $value || '' === $value ) {
			return __( '(the default)', 'blt-toolkit' );
		}

		return (string) $value;
	}

	/**
	 * A timezone from its name, or the site's.
	 *
	 * @param string $name Timezone name.
	 * @return DateTimeZone
	 */
	private static function timezone( $name ) {
		try {
			return '' !== $name ? new DateTimeZone( $name ) : wp_timezone();
		} catch ( \Exception $e ) {
			unset( $e );

			return wp_timezone();
		}
	}

	/**
	 * Hold the lock for this request, waiting up to `$wait` seconds for
	 * another request to release it. Nested calls share the lock; each
	 * successful call needs one release(). When the lock is newly taken,
	 * this request's cached copies of the ledger and of the autoloaded
	 * options (BLT Toolkit's settings among them) are dropped, so a plan
	 * worked out under the lock sees what the run that held it wrote.
	 *
	 * @param int $wait Seconds to wait; 0 tries once.
	 * @return bool Whether the lock is held.
	 */
	private static function acquire( $wait = 0 ) {
		if ( '' !== self::$held ) {
			++self::$depth;

			return true;
		}

		$until = microtime( true ) + max( 0, (int) $wait );

		while ( true ) {
			$token = self::lock();

			if ( null !== $token ) {
				self::$held  = $token;
				self::$depth = 1;

				wp_cache_delete( 'alloptions', 'options' );
				BLT_Toolkit_Settings::flush();
				self::fresh_ledger();

				return true;
			}

			if ( microtime( true ) >= $until ) {
				return false;
			}

			usleep( 200000 );
		}
	}

	/**
	 * Undo one acquire(); the last one releases the lock.
	 *
	 * @return void
	 */
	private static function release() {
		if ( '' === self::$held ) {
			return;
		}

		--self::$depth;

		if ( self::$depth > 0 ) {
			return;
		}

		self::unlock( self::$held );

		self::$held  = '';
		self::$depth = 0;
	}

	/**
	 * The refusal when another import holds the lock.
	 *
	 * @return WP_Error
	 */
	private static function busy_error() {
		return new WP_Error( 'blt_toolkit_legacy_busy', __( 'Another import is running. Try again in a minute.', 'blt-toolkit' ), array( 'status' => 409 ) );
	}

	/**
	 * Take the lock row (INSERT IGNORE, which one request wins; a lock older
	 * than LOCK_STALE is taken over with a compare-and-swap UPDATE). It never
	 * goes through the options API, so no cache hides another request's lock.
	 *
	 * @return string|null The token, or null when another request holds it.
	 */
	private static function lock() {
		global $wpdb;

		$token = wp_generate_password( 16, false );
		$value = $token . '|' . time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock; the options API cannot insert-if-absent.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $value ) );

		if ( 1 === (int) $inserted ) {
			return $token;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row as it is now.
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

		if ( null === $old ) {
			return null;
		}

		$parts = explode( '|', (string) $old );

		if ( time() - ( isset( $parts[1] ) ? (int) $parts[1] : 0 ) < self::LOCK_STALE ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the stale value.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK_OPTION, (string) $old ) );

		return 1 === (int) $updated ? $token : null;
	}

	/**
	 * Release the lock, if this request still holds it.
	 *
	 * @param string $token lock()'s token.
	 * @return void
	 */
	private static function unlock( $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK_OPTION, $wpdb->esc_like( $token . '|' ) . '%' ) );
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * Username Changer's settings mapped to BLT Toolkit's Usernames settings
	 * (SPEC 15). Pure.
	 *
	 * - `minimum_length` becomes `min_length`, clamped to 1 to 60, when set.
	 * - `enable_notifications` becomes `notify_user`: Username Changer saves
	 *   the checkbox only when ticked, so a missing key is off.
	 * - `email_subject` and `email_message` become `email_subject` and
	 *   `email_body` when Username Changer customized them. Empty means it
	 *   used its own default, and so does its English default text itself
	 *   (UC_STD_SUBJECT, UC_STD_MESSAGE), which its settings screen fills
	 *   into an empty field and stores on any save: in both cases BLT
	 *   Toolkit's translated default stands in and BLT Toolkit's own value
	 *   is left alone (`kept`). Username Changer
	 *   stores them slashed and the message as editor HTML, so they are
	 *   unslashed and the message turned into plain text. The template tags
	 *   share names; `tags` lists those BLT Toolkit does not replace.
	 * - Custom button labels and messages are dropped (`dropped`).
	 *
	 * @param array $raw `username_changer_settings`.
	 * @return array{values:array,kept:string[],dropped:string[],tags:string[]}
	 */
	public static function map_uc_settings( array $raw ) {
		$out = array(
			'values'  => array(),
			'kept'    => array(),
			'dropped' => array(),
			'tags'    => array(),
		);

		if ( isset( $raw['minimum_length'] ) && is_numeric( $raw['minimum_length'] ) ) {
			$out['values']['min_length'] = max( 1, min( 60, (int) $raw['minimum_length'] ) );
		}

		$out['values']['notify_user'] = ! empty( $raw['enable_notifications'] ) && '-1' !== $raw['enable_notifications'];

		$subject = isset( $raw['email_subject'] ) && is_scalar( $raw['email_subject'] ) ? trim( stripslashes( (string) $raw['email_subject'] ) ) : '';
		$body    = isset( $raw['email_message'] ) && is_scalar( $raw['email_message'] ) ? self::uc_body_text( stripslashes( (string) $raw['email_message'] ) ) : '';

		// SPEC 14.1: an empty subject or body means "the translated default".
		if ( self::UC_STD_SUBJECT === $subject ) {
			$subject = '';
		}

		if ( self::uc_body_text( self::UC_STD_MESSAGE ) === $body ) {
			$body = '';
		}

		if ( '' !== $subject ) {
			$out['values']['email_subject'] = sanitize_text_field( $subject );
		} else {
			$out['kept'][] = 'email_subject';
		}

		if ( '' !== $body ) {
			$out['values']['email_body'] = sanitize_textarea_field( $body );
		} else {
			$out['kept'][] = 'email_body';
		}

		$out['tags'] = self::unsupported_tags( $subject . "\n" . $body );

		foreach ( self::UC_DROPPED as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				$out['dropped'][] = $key;
			}
		}

		return $out;
	}

	/**
	 * Username Changer's editor HTML as a plain-text email body: line and
	 * paragraph breaks kept, tags removed, entities decoded, at most one empty
	 * line in a row. Pure.
	 *
	 * @param string $html Message.
	 * @return string
	 */
	public static function uc_body_text( $html ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $html );
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#</p>\s*#i', "\n\n", $text );
		$text = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $text );
		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure method; script and style bodies are removed above.
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Template tags in a text that BLT Toolkit's rename email does not
	 * replace (Username Changer's `{date}`, `{name}`, `{fullname}`,
	 * `{minlength}`), each once, in order. Pure.
	 *
	 * @param string $text Text.
	 * @return string[] Tags with braces.
	 */
	public static function unsupported_tags( $text ) {
		$out = array();

		if ( preg_match_all( '/\{([A-Za-z0-9_\-]+)\}/', (string) $text, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( ! in_array( $tag, self::BLT_TAGS, true ) && ! in_array( '{' . $tag . '}', $out, true ) ) {
					$out[] = '{' . $tag . '}';
				}
			}
		}

		return $out;
	}

	/**
	 * The role IDs Username Changer's "Allowed Roles" names. Pure.
	 *
	 * Its multicheck stores `sanitize_key( role ID ) => display name`, so a
	 * key is matched against the role IDs without case, and a value against
	 * the IDs and then the display names. `administrator` is left out
	 * (Username Changer always lets administrators rename). Anything that
	 * names no role here is reported in `missing`.
	 *
	 * @param mixed                $value Stored `allowed_roles`.
	 * @param array<string,string> $known Role ID => display name as stored.
	 * @return array{roles:string[],missing:string[]}
	 */
	public static function uc_allowed_roles( $value, array $known ) {
		$out = array(
			'roles'   => array(),
			'missing' => array(),
		);

		if ( ! is_array( $value ) ) {
			return $out;
		}

		$ids   = array();
		$names = array();

		foreach ( $known as $id => $name ) {
			$ids[ strtolower( (string) $id ) ]     = (string) $id;
			$names[ strtolower( (string) $name ) ] = (string) $id;
		}

		foreach ( $value as $key => $item ) {
			$role = '';
			$seen = '';

			if ( is_string( $key ) && '' !== $key ) {
				$seen = $key;

				if ( isset( $known[ $key ] ) ) {
					$role = $key;
				} elseif ( isset( $ids[ strtolower( $key ) ] ) ) {
					$role = $ids[ strtolower( $key ) ];
				}
			}

			if ( '' === $role && is_string( $item ) && '' !== $item && '-1' !== $item ) {
				$seen = '' !== $seen ? $seen : $item;

				if ( isset( $known[ $item ] ) ) {
					$role = $item;
				} elseif ( isset( $ids[ strtolower( $item ) ] ) ) {
					$role = $ids[ strtolower( $item ) ];
				} elseif ( isset( $names[ strtolower( $item ) ] ) ) {
					$role = $names[ strtolower( $item ) ];
				}
			}

			if ( '' === $role ) {
				if ( '' !== $seen && ! in_array( $seen, $out['missing'], true ) ) {
					$out['missing'][] = $seen;
				}

				continue;
			}

			if ( self::ADMIN_ROLE !== $role && ! in_array( $role, $out['roles'], true ) ) {
				$out['roles'][] = $role;
			}
		}

		return $out;
	}

	/**
	 * A Username Changer status as a log status. Pure.
	 *
	 * @param mixed $status Stored status (`success` in 3.3.1).
	 * @return string `success`, `failure` or `warning`.
	 */
	public static function uc_status( $status ) {
		$status = is_scalar( $status ) ? strtolower( trim( (string) $status ) ) : '';

		if ( '' === $status || 'success' === $status ) {
			return 'success';
		}

		if ( in_array( $status, array( 'failed', 'failure', 'fail', 'error' ), true ) ) {
			return 'failure';
		}

		return 'warning';
	}

	/**
	 * A local `Y-m-d H:i:s` (Username Changer stores `current_time( 'mysql' )`,
	 * the site's time) as UTC, or '' when it is not a real time. Pure.
	 *
	 * @param mixed        $local    Stored time.
	 * @param DateTimeZone $timezone The site's timezone.
	 * @return string
	 */
	public static function local_to_utc( $local, DateTimeZone $timezone ) {
		if ( ! is_string( $local ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $local, $m ) ) {
			return '';
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[1] < 1970 || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			return '';
		}

		$date = DateTime::createFromFormat( '!Y-m-d H:i:s', $local, $timezone );

		if ( false === $date ) {
			return '';
		}

		$date->setTimezone( new DateTimeZone( 'UTC' ) );

		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * One `{prefix}uc_audit_log` row as an activity log row (LOG-1), in the
	 * table's column order (BLT_Toolkit_Log::COLUMNS). Pure.
	 *
	 * Module `usernames`, action `username_changed`, as a native rename; the
	 * actor is Username Changer's `changed_by_id` and login, never the person
	 * importing; the time is converted from the site's time to UTC; the
	 * object is the user whose login is the new username now, when known
	 * (`user_id`); `source` is `username-changer` and the details keep the
	 * source row's ID, which makes the import resumable and never duplicated.
	 *
	 * @param array $row     Source row: id, changed_at, changed_by_id, changed_by_login, old_username, new_username, ip_address, status.
	 * @param array $context `timezone` (DateTimeZone), `record_ip` (bool, LOG-5), `user_id` (int), `now` (UTC fallback time).
	 * @return array<string,mixed>
	 */
	public static function map_audit_row( array $row, array $context ) {
		$timezone = isset( $context['timezone'] ) && $context['timezone'] instanceof DateTimeZone ? $context['timezone'] : new DateTimeZone( 'UTC' );
		$old      = self::text( isset( $row['old_username'] ) ? $row['old_username'] : '', 60 );
		$new      = self::text( isset( $row['new_username'] ) ? $row['new_username'] : '', 60 );
		$by_login = self::text( isset( $row['changed_by_login'] ) ? $row['changed_by_login'] : '', 60 );
		$by_id    = isset( $row['changed_by_id'] ) && is_numeric( $row['changed_by_id'] ) ? max( 0, (int) $row['changed_by_id'] ) : 0;
		$user_id  = isset( $context['user_id'] ) ? max( 0, (int) $context['user_id'] ) : 0;
		$local    = isset( $row['changed_at'] ) ? $row['changed_at'] : '';
		$utc      = self::local_to_utc( $local, $timezone );
		$ip       = isset( $row['ip_address'] ) && is_string( $row['ip_address'] ) ? trim( $row['ip_address'] ) : '';
		$ip       = ! empty( $context['record_ip'] ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		$self     = '' !== $by_login && $by_login === $old;

		$details = array(
			'old_login'  => $old,
			'new_login'  => $new,
			'source'     => self::SOURCE_UC,
			'source_id'  => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'changed_at' => is_scalar( $local ) ? (string) $local : '',
		);

		if ( isset( $row['status'] ) && is_scalar( $row['status'] ) && 'success' !== (string) $row['status'] ) {
			$details['source_status'] = self::text( $row['status'], 20 );
		}

		if ( '' === $utc ) {
			$details['time_unknown'] = true;
		}

		if ( $self ) {
			/* translators: 1: previous username, 2: new username. */
			$summary = sprintf( __( 'Changed their own username from %1$s to %2$s.', 'blt-toolkit' ), $old, $new );
		} else {
			/* translators: 1: previous username, 2: new username. */
			$summary = sprintf( __( 'Changed the username %1$s to %2$s.', 'blt-toolkit' ), $old, $new );
		}

		return array(
			'created_at'   => '' !== $utc ? $utc : ( isset( $context['now'] ) ? (string) $context['now'] : '1970-01-01 00:00:00' ),
			'module'       => 'usernames',
			'action'       => 'username_changed',
			'actor_id'     => $by_id,
			'actor_login'  => $by_login,
			'acting_as_id' => 0,
			'object_type'  => $user_id > 0 ? 'user' : '',
			'object_id'    => $user_id > 0 ? (string) $user_id : '',
			/* translators: %s: what happened, such as "Changed the username ann to anna." */
			'summary'      => BLT_Toolkit_Log::clean_summary( sprintf( __( '%s (Imported from Username Changer.)', 'blt-toolkit' ), $summary ) ),
			'details'      => BLT_Toolkit_Log::encode_details( $details ),
			'snapshot_id'  => null,
			'ip'           => $ip,
			'status'       => self::uc_status( isset( $row['status'] ) ? $row['status'] : '' ),
			'source'       => self::SOURCE_UC,
		);
	}

	/**
	 * The source row ID stored in an imported row's details JSON, or 0.
	 * Pure.
	 *
	 * @param mixed $json Stored details.
	 * @return int
	 */
	public static function source_id_of( $json ) {
		$details = BLT_Toolkit_Log::decode_details( $json );

		return is_array( $details ) && isset( $details['source_id'] ) && is_numeric( $details['source_id'] ) ? max( 0, (int) $details['source_id'] ) : 0;
	}

	/**
	 * Where the audit import resumes: the larger of the ledger's highest
	 * imported ID and the source ID stored in the newest imported row, so a
	 * batch written just before its ledger update was lost is never copied
	 * again. Pure.
	 *
	 * @param int   $ledger_last The ledger's `last_id`.
	 * @param mixed $newest      Details JSON of the newest row with source `username-changer`, or null.
	 * @return int
	 */
	public static function cursor( $ledger_last, $newest ) {
		return max( 0, (int) $ledger_last, self::source_id_of( $newest ) );
	}

	/**
	 * User Role Editor's other default roles for US-2: those to add, those
	 * already there and those that are not roles here. `administrator` is
	 * never one (US-2). Pure.
	 *
	 * @param mixed    $settings The `user_role_editor` option.
	 * @param string[] $known    Role IDs on this site.
	 * @param string[] $current  `roles.extra_default_roles`.
	 * @return array{add:string[],already:string[],missing:string[]}
	 */
	public static function ure_default_roles( $settings, array $known, array $current ) {
		$out  = array(
			'add'     => array(),
			'already' => array(),
			'missing' => array(),
		);
		$list = is_array( $settings ) && isset( $settings['other_default_roles'] ) && is_array( $settings['other_default_roles'] ) ? $settings['other_default_roles'] : array();

		foreach ( $list as $role ) {
			if ( ! is_string( $role ) || '' === $role || self::ADMIN_ROLE === $role ) {
				continue;
			}

			if ( ! in_array( $role, $known, true ) ) {
				$bucket = 'missing';
			} elseif ( in_array( $role, $current, true ) ) {
				$bucket = 'already';
			} else {
				$bucket = 'add';
			}

			if ( ! in_array( $role, $out[ $bucket ], true ) ) {
				$out[ $bucket ][] = $role;
			}
		}

		return $out;
	}

	/**
	 * The roles User Role Editor hides the admin bar for
	 * (`ure_role_additional_options_values[ role ]['hide_admin_bar']`), and
	 * those that are not roles here. Pure.
	 *
	 * @param mixed    $value The option.
	 * @param string[] $known Role IDs on this site.
	 * @return array{roles:string[],missing:string[]}
	 */
	public static function ure_toolbar_roles( $value, array $known ) {
		$out = array(
			'roles'   => array(),
			'missing' => array(),
		);

		if ( ! is_array( $value ) ) {
			return $out;
		}

		foreach ( $value as $role => $items ) {
			$role = (string) $role;

			if ( '' === $role || ! is_array( $items ) || empty( $items['hide_admin_bar'] ) ) {
				continue;
			}

			$out[ in_array( $role, $known, true ) ? 'roles' : 'missing' ][] = $role;
		}

		return $out;
	}

	/**
	 * User Role Editor's roles backup, if it has the shape of a roles array
	 * (role ID => name and capabilities), exactly as stored; otherwise null.
	 * Pure.
	 *
	 * @param mixed $value The `{prefix}backup_user_roles` option.
	 * @return array|null
	 */
	public static function ure_backup_roles( $value ) {
		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}

		foreach ( $value as $id => $role ) {
			if ( '' === (string) $id || ! is_array( $role ) || ! isset( $role['name'] ) || ! is_scalar( $role['name'] ) || ! isset( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
				return null;
			}
		}

		return $value;
	}

	/**
	 * The `ure_*` capability names among a capabilities array's keys, sorted.
	 * Pure.
	 *
	 * @param array $caps Capability => value.
	 * @return string[]
	 */
	public static function ure_caps_in( array $caps ) {
		$out = array();

		foreach ( array_keys( $caps ) as $cap ) {
			$cap = (string) $cap;

			if ( 0 === strpos( $cap, self::URE_FAMILY . '_' ) ) {
				$out[] = $cap;
			}
		}

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Whether a new ledger entry says what the stored one says, ignoring when
	 * and by whom (`at`, `by`). Values compare as the ledger stores them, so
	 * a role list in another order is a change. Pure.
	 *
	 * @param array $stored Stored entry (clean_ledger()).
	 * @param array $entry  New fields.
	 * @return bool
	 */
	public static function same_entry( array $stored, array $entry ) {
		foreach ( $entry as $field => $value ) {
			if ( in_array( $field, array( 'at', 'by' ), true ) ) {
				continue;
			}

			if ( ! array_key_exists( $field, $stored ) || self::plan_hash( array( $stored[ $field ] ) ) !== self::plan_hash( array( $value ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * SHA-256 of a plan's canonical JSON (keys sorted), for the confirm's
	 * "nothing changed since the preview" check. Pure.
	 *
	 * @param array $data What the preview showed.
	 * @return string
	 */
	public static function plan_hash( array $data ) {
		return hash( 'sha256', BLT_Toolkit_Role_Diff::canonicalize( $data ) );
	}

	/**
	 * Text on one line, valid UTF-8, cut to a length. Pure.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Most characters.
	 * @return string
	 */
	private static function text( $value, $max ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}

		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) );

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, (int) $max, 'UTF-8' );
		}

		return substr( $text, 0, (int) $max );
	}
}
