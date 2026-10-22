<?php
/**
 * Database schema: the activity log and role snapshot tables.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades both tables with dbDelta (SPEC 14.3), gated on the
 * stored schema version so a plugin update by file copy, which never fires
 * the activation hook, still migrates on the next `init`.
 */
class BLT_Toolkit_Schema {

	/**
	 * Schema version. Bump when either CREATE TABLE definition changes.
	 */
	const VERSION = '1.0.0';

	/**
	 * Option storing the installed schema version (autoload yes, so a request
	 * with nothing to upgrade runs no query).
	 */
	const OPTION = 'blt_toolkit_db_version';

	/**
	 * Table names without the site prefix, keyed by the name callers use.
	 */
	const TABLES = array(
		'log'       => 'blt_toolkit_log',
		'snapshots' => 'blt_toolkit_snapshots',
	);

	/**
	 * Full table name for 'log' or 'snapshots'.
	 *
	 * @param string $name 'log' or 'snapshots'.
	 * @return string Prefixed table name, or '' for an unknown name.
	 */
	public static function table( $name ) {
		global $wpdb;

		$name = (string) $name;

		if ( ! isset( self::TABLES[ $name ] ) ) {
			return '';
		}

		return $wpdb->prefix . self::TABLES[ $name ];
	}

	/**
	 * Re-run the install when the stored schema version is stale.
	 *
	 * Hooked on `init`. It looks only on wp-admin screens, WP-Cron and WP-CLI,
	 * never on a visitor's page view, admin-ajax.php or a REST request, where
	 * it returns before reading anything. There the version option is
	 * autoloaded, so when nothing has changed this costs one array lookup
	 * and no query.
	 *
	 * Front-end page views run no queries from this plugin (SEC-9). On a host
	 * that keeps refusing the CREATE, the version is never stored, so the
	 * option is missing from the autoloaded set and reading it would itself
	 * be a query; retrying the install would add dbDelta, two SHOW TABLES
	 * queries and a database error in the log, on every request. The next
	 * admin screen or cron run picks it up instead, and writes already
	 * tolerate a missing table (LOG-3).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$backend = ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

		if ( ! $backend || get_option( self::OPTION ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or upgrade both tables. Idempotent: dbDelta diffs the schema.
	 *
	 * The statements follow dbDelta's formatting rules to the letter: one
	 * column per line, KEY (not INDEX), two spaces after PRIMARY KEY, no
	 * backticks. The version is recorded only when both tables exist, so a
	 * host that refused the CREATE (no privilege, say) is retried on the next
	 * admin screen, cron run or WP-CLI command (see maybe_upgrade()) instead
	 * of every write failing for good.
	 *
	 * @return bool Whether both tables exist afterwards.
	 */
	public static function install() {
		global $wpdb;

		$log             = self::table( 'log' );
		$snapshots       = self::table( 'snapshots' );
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			array(
				"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  module varchar(20) NOT NULL,
  action varchar(40) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_login varchar(60) NOT NULL DEFAULT '',
  acting_as_id bigint(20) unsigned NOT NULL DEFAULT 0,
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id varchar(191) NOT NULL DEFAULT '',
  summary text NOT NULL,
  details longtext NULL,
  snapshot_id bigint(20) unsigned NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'success',
  source varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY module_action (module,action),
  KEY actor_id (actor_id),
  KEY object_lookup (object_type,object_id(40))
) {$charset_collate};",
				"CREATE TABLE {$snapshots} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason varchar(40) NOT NULL,
  roles longtext NOT NULL,
  hash char(64) NOT NULL,
  is_baseline tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$charset_collate};",
			)
		);

		$ok = self::table_exists( $log ) && self::table_exists( $snapshots );

		if ( $ok ) {
			update_option( self::OPTION, self::VERSION, true );
		}

		return $ok;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check during install; nothing to cache.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return 0 === strcasecmp( (string) $found, $table );
	}
}
