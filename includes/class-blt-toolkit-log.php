<?php
/**
 * The activity log: writing rows, reading them for the Activity screen, and
 * pruning them on a schedule.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One log for every module (SPEC 6.1). It always loads, even with every
 * module off, so the Guard's decisions and recovery mode are recorded.
 *
 * - LOG-1: record() inserts one row into `{prefix}blt_toolkit_log`.
 * - LOG-2: while switched, `actor_id` is the human who signed in (the first
 *   user of the switch chain) and `acting_as_id` the current user; the chain
 *   goes into details. The Switch module supplies the chain through
 *   set_switch_chain_provider(); without a provider nobody counts as switched.
 *   A background job pins the chain it started under (with_switch_chain()),
 *   so its rows name that human whoever drives the tick.
 * - LOG-3: writing never blocks or interrupts the action being recorded. A
 *   failed insert goes to error_log() and sets the `blt_toolkit_log_failure`
 *   flag (autoload no) for the Site Health test; the person never sees it.
 *   The next successful write stamps the flag `recovered` instead of
 *   clearing it (last_failure()).
 * - LOG-4: rows older than the retention (setting `log.retention_days`, filter
 *   `blt_toolkit_log_retention_days`) are removed by a daily WP-Cron event that
 *   starts a cursor-mode `log_prune` job deleting 1,000 rows per step.
 * - LOG-5: the IP is stored only when `log.record_ip` is on: `REMOTE_ADDR`, or
 *   `CF-Connecting-IP` when the request comes from Cloudflare (BLT_Toolkit_Ip).
 * - LOG-6: `blt_toolkit_logged` fires after each successful insert.
 *
 * The methods marked "pure" take plain values and call no WordPress function,
 * so tests/test-log.php covers them with known vectors.
 */
class BLT_Toolkit_Log {

	/**
	 * Daily WP-Cron hook that starts the prune (LOG-4). Starts with
	 * `blt_toolkit_`, so deactivation and uninstall clear it.
	 */
	const CRON_HOOK = 'blt_toolkit_log_prune';

	/**
	 * Job type registered with BLT_Toolkit_Jobs (JOB-5).
	 */
	const JOB_TYPE = 'log_prune';

	/**
	 * Rows deleted per prune step (LOG-4).
	 */
	const BATCH = 1000;

	/**
	 * Option flagging the most recent failed insert (LOG-3), autoload no:
	 * array( 'time' => int, 'error' => string, 'count' => int ), plus
	 * 'recovered' => int once a later write succeeded (last_failure()).
	 */
	const FAILURE_OPTION = 'blt_toolkit_log_failure';

	/**
	 * Allowed `status` values; anything else is stored as `success`.
	 */
	const STATUSES = array( 'success', 'failure', 'warning' );

	/**
	 * Longest summary kept, in characters. The column is TEXT; a summary is
	 * one line a person reads, so anything longer is cut.
	 */
	const MAX_SUMMARY = 1000;

	/**
	 * Largest details JSON stored, in bytes. Larger details are replaced by a
	 * marker naming their top-level keys, so one oversized row cannot fail the
	 * insert (LOG-3) or bloat the table.
	 */
	const MAX_DETAILS_BYTES = 1048576;

	/**
	 * Deepest nesting json_encode() is asked to follow.
	 */
	const MAX_DETAILS_DEPTH = 64;

	/**
	 * Page sizes for the Activity screen.
	 */
	const DEFAULT_PER_PAGE = 50;
	const MAX_PER_PAGE     = 200;

	/**
	 * Highest page number honored, so `(paged - 1) * per_page` stays an int.
	 */
	const MAX_PAGED = 1000000;

	/**
	 * The arguments that filter the log, as opposed to sorting or paging it.
	 */
	const FILTER_KEYS = array( 'module', 'action', 'actor', 'acting_as', 'from', 'to' );

	/**
	 * Transient holding the Activity screen's filter menus (filter_menus()).
	 */
	const MENUS_TRANSIENT = 'blt_toolkit_log_menus';

	/**
	 * Most module and action pairs in the filter menu.
	 */
	const MENU_ACTIONS = 500;

	/**
	 * Most people read for the Who and Acting as menus: one more than the
	 * screen lists, so it can tell when there are too many for a menu.
	 */
	const MENU_USERS = 201;

	/**
	 * Rows below the cached highest ID read again when merging newer rows,
	 * in case an insert with a lower ID committed late.
	 */
	const MENUS_OVERLAP = 100;

	/**
	 * Sort keys the Activity screen may use, mapped to the column sorted on.
	 * The only way a column name reaches ORDER BY.
	 */
	const SORTABLE = array(
		'when'     => 'created_at',
		'actor'    => 'actor_login',
		'activity' => 'module',
	);

	/**
	 * Characters that make a spreadsheet read a cell as a formula.
	 */
	const FORMULA_LEAD = array( '=', '+', '-', '@', "\t", "\r", "\n" );

	/**
	 * Column formats, in insert order.
	 */
	const COLUMNS = array(
		'created_at'   => '%s',
		'module'       => '%s',
		'action'       => '%s',
		'actor_id'     => '%d',
		'actor_login'  => '%s',
		'acting_as_id' => '%d',
		'object_type'  => '%s',
		'object_id'    => '%s',
		'summary'      => '%s',
		'details'      => '%s',
		'snapshot_id'  => '%d',
		'ip'           => '%s',
		'status'       => '%s',
		'source'       => '%s',
	);

	/**
	 * Returns the switch chain (user IDs, oldest first), or null when the
	 * Switch module has not registered one (LOG-2).
	 *
	 * @var callable|null
	 */
	private static $chain_provider = null;

	/**
	 * Switch chains pinned by with_switch_chain(), innermost last. While one
	 * is set it replaces the provider's answer (LOG-2 for background jobs).
	 *
	 * @var array<int,int[]>
	 */
	private static $pinned_chains = array();

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Messages already written to the PHP error log this request, so a broken
	 * table produces one line per request, not one per row.
	 *
	 * @var array<string,bool>
	 */
	private static $reported = array();

	/**
	 * Whether this request already looked at the failure flag after a
	 * successful write (note_recovery()). A failure in between resets it.
	 *
	 * @var bool
	 */
	private static $recovery_checked = false;

	// ---------- Boot ----------

	/**
	 * Register the log's hooks. Called on `plugins_loaded` priority 1, on
	 * every request, so it only adds hooks.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_prune' ) );

		// On `init`: the job's label is translated, and translating earlier
		// would load the text domain too early. Every path that runs a job tick
		// (WP-Cron, a loopback, the REST tick route) comes after `init`.
		add_action( 'init', array( __CLASS__, 'register_job_type' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
	}

	/**
	 * Register the `log_prune` job type (JOB-5) with BLT_Toolkit_Jobs, when
	 * the job runner is available.
	 *
	 * @return void
	 */
	public static function register_job_type() {
		if ( ! class_exists( 'BLT_Toolkit_Jobs' ) || ! method_exists( 'BLT_Toolkit_Jobs', 'register_type' ) ) {
			return;
		}

		BLT_Toolkit_Jobs::register_type(
			self::JOB_TYPE,
			array(
				'label'      => __( 'Remove old activity log entries', 'blt-toolkit' ),
				// Retention is a setting, so only those who manage settings see,
				// drive or cancel the job.
				'capability' => 'manage_options',
				'mode'       => 'cursor',
				'process'    => array( __CLASS__, 'prune_step' ),
			)
		);
	}

	/**
	 * Schedule the daily prune event when it is missing (LOG-4).
	 *
	 * Runs only on wp-admin screens, WP-Cron and WP-CLI, as the schema check
	 * does: a visitor's page view never touches the schedule. The plugin's
	 * deactivation clears every `blt_toolkit_` event, this one included, and
	 * the next backend request after reactivating schedules it again.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$backend = ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

		if ( ! $backend || false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Register the callable that returns the switch chain (LOG-2).
	 *
	 * The Switch module calls this when it boots. The callable takes no
	 * arguments and returns user IDs, oldest first: the human who signed in,
	 * then each user switched into, the current user last. It returns an
	 * empty array when the current session is not switched.
	 *
	 * @param callable $provider Returns int[].
	 * @return void
	 */
	public static function set_switch_chain_provider( callable $provider ) {
		self::$chain_provider = $provider;
	}

	/**
	 * The switch chain rows written now are attributed to (LOG-2): the one
	 * pinned by with_switch_chain(), else the provider's. User IDs, oldest
	 * first; empty when not switched.
	 *
	 * BLT_Toolkit_Jobs::start() stores this with a job, so the rows its ticks
	 * write name the human who started it even when WP-Cron runs them.
	 *
	 * @return int[]
	 */
	public static function current_switch_chain() {
		return self::switch_chain();
	}

	/**
	 * Run a callable with a pinned switch chain (LOG-2), then restore what
	 * was there before, even when it throws.
	 *
	 * A job tick runs as the person who started the job (JOB-3), but in a
	 * request without their cookies (WP-Cron, the loopback), or driven by
	 * someone else's browser, so the provider cannot know the chain then.
	 * BLT_Toolkit_Jobs wraps each tick in this with the chain it pinned at
	 * start, and every row the tick writes keeps its actor: the human who
	 * signed in, acting as the job's owner. An empty chain pins "not
	 * switched". Calls nest.
	 *
	 * @param array    $chain    User IDs, oldest first (anything else is dropped).
	 * @param callable $callback What to run.
	 * @return mixed What `$callback` returns.
	 */
	public static function with_switch_chain( array $chain, callable $callback ) {
		self::$pinned_chains[] = self::clean_chain( $chain );

		try {
			return call_user_func( $callback );
		} finally {
			array_pop( self::$pinned_chains );
		}
	}

	/**
	 * A chain as positive user IDs, in order. Pure.
	 *
	 * @param array $chain Candidate chain.
	 * @return int[]
	 */
	public static function clean_chain( array $chain ) {
		$out = array();

		foreach ( $chain as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}

	// ---------- Writing ----------

	/**
	 * Write one row (LOG-1).
	 *
	 * Never throws and never stops the caller (LOG-3): a failure is written to
	 * the PHP error log, flagged for Site Health, and reported as `false`.
	 *
	 * @param array $row {
	 *     The row. Every key is optional.
	 *
	 *     @type string   $module      `switch`, `usernames`, `roles` or `toolkit` (default `toolkit`).
	 *     @type string   $action      What happened, a key such as `switched_to` or `role_saved`.
	 *     @type string   $object_type What was acted on: `user`, `role`, `post_type`, `log` ...
	 *     @type string   $object_id   Its ID or key.
	 *     @type string   $summary     One line a person can read, already translated.
	 *     @type array    $details     Anything else (before and after, a Role_Diff::diff() result); stored as JSON.
	 *     @type int|null $snapshot_id The snapshot taken for a role change.
	 *     @type string   $status      `success` (default), `failure` or `warning`.
	 *     @type string   $source      Empty for native rows; the plugin's slug for imported rows.
	 *     @type bool     $system      Write the row as the system's: `actor_id` 0, not acting as anyone and no
	 *                                 switch chain, whoever is signed in. For what BLT Toolkit does by itself,
	 *                                 such as ending a switched session whose origin ended. Default false.
	 * }
	 * @return int|false The new row's ID, or false when it could not be written.
	 */
	public static function record( array $row ) {
		try {
			$data    = self::normalize_row( $row );
			$details = $data['details'];
			$who     = empty( $row['system'] ) ? self::attribute( get_current_user_id(), self::switch_chain() ) : self::attribute( 0, array() );

			if ( ! empty( $who['chain'] ) ) {
				$details['switch_chain'] = self::chain_details( $who['chain'] );
			}

			$data['created_at']   = gmdate( 'Y-m-d H:i:s' );
			$data['actor_id']     = $who['actor_id'];
			$data['actor_login']  = self::login_of( $who['actor_id'] );
			$data['acting_as_id'] = $who['acting_as_id'];
			$data['ip']           = self::ip();
			$data['details']      = self::encode_details( $details );

			$id = self::insert( $data );

			if ( false === $id ) {
				return false;
			}

			self::note_recovery();

			// LOG-6: listeners get the row as written, so the details are the
			// stored JSON decoded (the truncation marker, substituted values
			// and the switch chain included), not the caller's array.
			$stored = self::decode_details( $data['details'] );

			$data['id']      = $id;
			$data['details'] = null === $stored ? array() : $stored;

			self::announce( $data );

			return $id;
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage() );

			return false;
		}
	}

	/**
	 * Clean the caller's fields into column values. Pure.
	 *
	 * `details` stays an array here; record() encodes it after adding the
	 * switch chain.
	 *
	 * @param array $row Caller's row (see record()).
	 * @return array{module:string,action:string,object_type:string,object_id:string,summary:string,details:array,snapshot_id:int|null,status:string,source:string}
	 */
	public static function normalize_row( array $row ) {
		$module = self::clean_key( isset( $row['module'] ) ? $row['module'] : '', 20 );
		$action = self::clean_key( isset( $row['action'] ) ? $row['action'] : '', 40 );
		$status = isset( $row['status'] ) && is_string( $row['status'] ) ? strtolower( trim( $row['status'] ) ) : '';

		$object_id = '';

		if ( isset( $row['object_id'] ) && is_scalar( $row['object_id'] ) && ! is_bool( $row['object_id'] ) ) {
			$object_id = self::cut( self::one_line( (string) $row['object_id'] ), 191 );
		}

		$snapshot_id = null;

		if ( isset( $row['snapshot_id'] ) && is_numeric( $row['snapshot_id'] ) && (int) $row['snapshot_id'] > 0 ) {
			$snapshot_id = (int) $row['snapshot_id'];
		}

		return array(
			'module'      => '' !== $module ? $module : 'toolkit',
			'action'      => '' !== $action ? $action : 'unknown',
			'object_type' => self::clean_key( isset( $row['object_type'] ) ? $row['object_type'] : '', 20 ),
			'object_id'   => $object_id,
			'summary'     => self::clean_summary( isset( $row['summary'] ) ? $row['summary'] : '' ),
			'details'     => isset( $row['details'] ) && is_array( $row['details'] ) ? $row['details'] : array(),
			'snapshot_id' => $snapshot_id,
			'status'      => in_array( $status, self::STATUSES, true ) ? $status : 'success',
			'source'      => self::clean_key( isset( $row['source'] ) ? $row['source'] : '', 40 ),
		);
	}

	/**
	 * A machine key: lowercase letters, digits, `_` and `-`, cut to a length.
	 * The same characters sanitize_key() keeps. Pure.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Longest result.
	 * @return string
	 */
	public static function clean_key( $value, $max ) {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}

		return substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ), 0, (int) $max );
	}

	/**
	 * The summary as one clean line: valid UTF-8, control characters and line
	 * breaks turned into spaces, runs of spaces collapsed, trimmed, and cut to
	 * MAX_SUMMARY characters. Markup is kept as text and escaped on output.
	 * Pure.
	 *
	 * @param mixed $summary Raw summary.
	 * @return string
	 */
	public static function clean_summary( $summary ) {
		if ( ! is_scalar( $summary ) || is_bool( $summary ) ) {
			return '';
		}

		return self::cut( self::one_line( (string) $summary ), self::MAX_SUMMARY );
	}

	/**
	 * Details as stored: JSON, or null when there are none. Pure.
	 *
	 * Invalid UTF-8 is replaced rather than failing the encode, a value JSON
	 * cannot hold (INF, a resource) becomes 0 or null, and details larger than
	 * MAX_DETAILS_BYTES, or nested deeper than JSON allows, are replaced by a
	 * marker that names their top-level keys, so the row is still written.
	 *
	 * @param mixed $details Details array.
	 * @return string|null
	 */
	public static function encode_details( $details ) {
		if ( ! is_array( $details ) || empty( $details ) ) {
			return null;
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
		$json  = json_encode( $details, $flags, self::MAX_DETAILS_DEPTH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure method; the flags matter here.

		// Partial output stands in 0 or null for a value JSON cannot hold, which
		// is fine; past the depth limit it would silently drop a whole branch.
		$too_deep = JSON_ERROR_DEPTH === json_last_error();

		if ( is_string( $json ) && ! $too_deep && strlen( $json ) <= self::MAX_DETAILS_BYTES ) {
			return $json;
		}

		$keys = array();

		foreach ( array_slice( array_keys( $details ), 0, 50 ) as $key ) {
			$keys[] = self::cut( self::one_line( (string) $key ), 100 );
		}

		$marker = array(
			'truncated' => true,
			'bytes'     => is_string( $json ) && ! $too_deep ? strlen( $json ) : 0,
			'keys'      => $keys,
		);

		return (string) json_encode( $marker, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure method.
	}

	/**
	 * Stored details back as an array, or null when there are none. Pure.
	 *
	 * A value that is not a JSON object or array (damaged by hand, say) comes
	 * back as array( 'value' => ... ) so it can still be shown.
	 *
	 * @param mixed $json Stored column value.
	 * @return array|null
	 */
	public static function decode_details( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$value = json_decode( $json, true );

		if ( is_array( $value ) ) {
			return $value;
		}

		return array( 'value' => null === $value ? $json : $value );
	}

	/**
	 * Who to record for a write (LOG-2). Pure.
	 *
	 * Not switched (an empty chain, or one that starts with the current user):
	 * the actor is the current user and `acting_as_id` is 0. Switched: the
	 * actor is the first user of the chain, the human who signed in, and
	 * `acting_as_id` is the current user, or 0 when nobody is signed in (a
	 * switched-off session).
	 *
	 * @param int   $current_id Current user ID (0 when nobody is signed in).
	 * @param array $chain      User IDs, oldest first.
	 * @return array{actor_id:int,acting_as_id:int,chain:int[]} `chain` is empty when not switched.
	 */
	public static function attribute( $current_id, array $chain ) {
		$current_id = max( 0, (int) $current_id );
		$ids        = array();

		foreach ( $chain as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}

		if ( empty( $ids ) || $ids[0] === $current_id ) {
			return array(
				'actor_id'     => $current_id,
				'acting_as_id' => 0,
				'chain'        => array(),
			);
		}

		return array(
			'actor_id'     => $ids[0],
			'acting_as_id' => $current_id,
			'chain'        => $ids,
		);
	}

	/**
	 * The switch chain from the provider, or an empty array.
	 *
	 * @return array
	 */
	private static function switch_chain() {
		if ( array() !== self::$pinned_chains ) {
			return self::$pinned_chains[ count( self::$pinned_chains ) - 1 ];
		}

		if ( null === self::$chain_provider ) {
			return array();
		}

		try {
			$chain = call_user_func( self::$chain_provider );
		} catch ( \Throwable $e ) {
			self::report( 'the switch chain provider failed: ' . $e->getMessage() );

			return array();
		}

		return is_array( $chain ) ? array_values( $chain ) : array();
	}

	/**
	 * The chain as stored in details: each user's ID and login, so the record
	 * still reads after a user is deleted or renamed.
	 *
	 * @param int[] $chain User IDs, oldest first.
	 * @return array<int,array{id:int,login:string}>
	 */
	private static function chain_details( array $chain ) {
		$out = array();

		foreach ( $chain as $id ) {
			$out[] = array(
				'id'    => (int) $id,
				'login' => self::login_of( (int) $id ),
			);
		}

		return $out;
	}

	/**
	 * A user's login, or '' for 0 or a user who does not exist.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function login_of( $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( (int) $user_id );

		return $user ? self::cut( (string) $user->user_login, 60 ) : '';
	}

	/**
	 * The client IP to store, or '' when IP recording is off (LOG-5).
	 *
	 * @return string
	 */
	private static function ip() {
		if ( ! BLT_Toolkit_Settings::get( 'log', 'record_ip' ) ) {
			return '';
		}

		// Each value is validated as an IP address in BLT_Toolkit_Ip::resolve().
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$cf_ip  = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';

		return substr( BLT_Toolkit_Ip::resolve( $remote, $cf_ip ), 0, 45 );
	}

	/**
	 * Insert a prepared row with database errors kept off the screen.
	 *
	 * @param array $data Column values (details already encoded).
	 * @return int|false Row ID, or false on failure.
	 */
	private static function insert( array $data ) {
		global $wpdb;

		$values  = array();
		$formats = array();

		foreach ( self::COLUMNS as $column => $format ) {
			$values[ $column ] = $data[ $column ];
			$formats[]         = $format;
		}

		// With WP_DEBUG_DISPLAY on, wpdb prints a failed query into the page,
		// which would surface mid-action and can break the redirect that
		// follows. The error is reported through fail() instead (LOG-3).
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.
		$ok    = $wpdb->insert( BLT_Toolkit_Schema::table( 'log' ), $values, $formats );
		$error = (string) $wpdb->last_error;
		$id    = (int) $wpdb->insert_id;

		$wpdb->suppress_errors( $suppressed );

		if ( false === $ok || $id <= 0 ) {
			self::fail( '' !== $error ? $error : 'the insert returned no row ID' );

			return false;
		}

		return $id;
	}

	/**
	 * Fire `blt_toolkit_logged` (LOG-6). A listener that throws is reported
	 * and otherwise ignored: the row is already written.
	 *
	 * @param array $row The row as written, with its `id` and `details` as an array.
	 * @return void
	 */
	private static function announce( array $row ) {
		$ordered = array( 'id' => (int) $row['id'] );

		foreach ( array_keys( self::COLUMNS ) as $column ) {
			$ordered[ $column ] = $row[ $column ];
		}

		try {
			/**
			 * Fires after a row is written to the activity log (LOG-6). A
			 * failed insert does not fire it.
			 *
			 * @param array $row The row as written: `id`, `created_at` (UTC), `module`, `action`,
			 *                   `actor_id`, `actor_login`, `acting_as_id`, `object_type`, `object_id`,
			 *                   `summary`, `details` (array, decoded), `snapshot_id` (int|null), `ip`,
			 *                   `status` and `source`.
			 */
			do_action( 'blt_toolkit_logged', $ordered );
		} catch ( \Throwable $e ) {
			self::report( 'a blt_toolkit_logged listener failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Handle a failed write (LOG-3): one line in the PHP error log per
	 * request, and the failure flag for the Site Health test. Never shown to
	 * the person and never thrown.
	 *
	 * @param string $error What went wrong.
	 * @return void
	 */
	private static function fail( $error ) {
		global $wpdb;

		// Some database drivers (SQLite Database Integration) report errors as
		// HTML; the flag and the PHP error log hold plain text.
		$error = html_entity_decode( wp_strip_all_tags( (string) $error ), ENT_QUOTES, 'UTF-8' );
		$error = self::cut( self::one_line( (string) preg_replace( '/^[\s\x{00A0}]+/u', '', $error ) ), 500 );

		self::report( 'could not write an activity log entry: ' . $error );

		// A later successful write in this request stamps the recovery again.
		self::$recovery_checked = false;

		try {
			$previous = get_option( self::FAILURE_OPTION );
			$count    = is_array( $previous ) && isset( $previous['count'] ) ? (int) $previous['count'] : 0;

			// When the database itself is failing, this write fails too; keep
			// that off the screen as well.
			$suppressed = $wpdb->suppress_errors( true );

			update_option(
				self::FAILURE_OPTION,
				array(
					'time'  => time(),
					'error' => $error,
					'count' => $count + 1,
				),
				false
			);

			$wpdb->suppress_errors( $suppressed );
		} catch ( \Throwable $e ) {
			self::report( 'could not store the failure flag: ' . $e->getMessage() );
		}
	}

	/**
	 * Write a line to the PHP error log, each distinct message once per
	 * request.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function report( $message ) {
		$message = (string) $message;

		if ( isset( self::$reported[ $message ] ) ) {
			return;
		}

		self::$reported[ $message ] = true;

		error_log( 'BLT Toolkit: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- LOG-3: failures go to the site owner's logs, never to the screen.
	}

	/**
	 * The most recent failed write (LOG-3), for the Site Health test, or null
	 * when none was recorded.
	 *
	 * Clearing semantics (decided in the Phase 7 backlog, tasks/lessons.md):
	 * a later successful write never deletes the flag, because entries were
	 * lost and the owner should hear about it, but it stamps `recovered`
	 * with the time of the first successful write after the latest failure.
	 * `recovered` is 0 while no write has succeeded since; a new failure
	 * resets it to 0 and keeps counting. So a Site Health test can tell
	 * "writes are failing now" (`recovered` 0) from "writes failed earlier
	 * and work again" (`recovered` after `time`). Only clear_failure()
	 * removes the flag (a Site Health action, or uninstall).
	 *
	 * @return array{time:int,error:string,count:int,recovered:int}|null
	 */
	public static function last_failure() {
		$flag = get_option( self::FAILURE_OPTION );

		return self::failure_from( $flag );
	}

	/**
	 * The failure flag as last_failure() returns it, from the stored value.
	 * Pure.
	 *
	 * @param mixed $flag The stored option value.
	 * @return array{time:int,error:string,count:int,recovered:int}|null
	 */
	public static function failure_from( $flag ) {
		if ( ! is_array( $flag ) || empty( $flag['time'] ) || ! is_numeric( $flag['time'] ) ) {
			return null;
		}

		$time      = (int) $flag['time'];
		$recovered = isset( $flag['recovered'] ) && is_numeric( $flag['recovered'] ) ? (int) $flag['recovered'] : 0;

		return array(
			'time'      => $time,
			'error'     => isset( $flag['error'] ) && is_scalar( $flag['error'] ) ? (string) $flag['error'] : '',
			'count'     => isset( $flag['count'] ) && is_numeric( $flag['count'] ) ? max( 1, (int) $flag['count'] ) : 1,
			'recovered' => $recovered >= $time ? $recovered : 0,
		);
	}

	/**
	 * The stored flag after a successful write at `$now`: stamped with
	 * `recovered` when it records a failure and no write succeeded since;
	 * null when nothing needs storing. Pure.
	 *
	 * @param mixed $flag The stored option value.
	 * @param int   $now  Time of the successful write.
	 * @return array|null The value to store, or null to leave the option alone.
	 */
	public static function recovered_flag( $flag, $now ) {
		$failure = self::failure_from( $flag );

		if ( null === $failure || $failure['recovered'] > 0 ) {
			return null;
		}

		$flag['recovered'] = max( (int) $now, $failure['time'] );

		return $flag;
	}

	/**
	 * After a successful write, stamp the failure flag as recovered
	 * (last_failure()). One option read per request at most, answered from
	 * the cache on admin screens (BLT_Toolkit_Settings::prime_admin_options()
	 * reads it with the other options that are not autoloaded) and from
	 * `notoptions` once read; a write only on the first success after a
	 * failure.
	 *
	 * @return void
	 */
	private static function note_recovery() {
		if ( self::$recovery_checked ) {
			return;
		}

		self::$recovery_checked = true;

		try {
			$stamped = self::recovered_flag( get_option( self::FAILURE_OPTION ), time() );

			if ( null !== $stamped ) {
				update_option( self::FAILURE_OPTION, $stamped, false );
			}
		} catch ( \Throwable $e ) {
			self::report( 'could not update the failure flag: ' . $e->getMessage() );
		}
	}

	/**
	 * Clear the failure flag (for a Site Health action once the cause is fixed).
	 *
	 * @return void
	 */
	public static function clear_failure() {
		delete_option( self::FAILURE_OPTION );
	}

	// ---------- Reading (Activity screen) ----------

	/**
	 * Clean raw request arguments into query arguments. Pure.
	 *
	 * Unknown or malformed values fall back to "no filter" rather than to
	 * something that would match nothing. Idempotent: normalizing normalized
	 * arguments changes nothing.
	 *
	 * @param array $raw Unslashed request arguments.
	 * @return array{module:string,action:string,actor:string,acting_as:string,from:string,to:string,orderby:string,order:string,paged:int,per_page:int}
	 */
	public static function normalize_args( array $raw ) {
		$args = array(
			'module'    => '',
			'action'    => '',
			'actor'     => '',
			'acting_as' => '',
			'from'      => '',
			'to'        => '',
			'orderby'   => 'when',
			'order'     => 'desc',
			'paged'     => 1,
			'per_page'  => self::DEFAULT_PER_PAGE,
		);

		foreach ( array(
			'module' => 20,
			'action' => 40,
		) as $key => $max ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
				$value = strtolower( trim( $raw[ $key ] ) );

				if ( 1 === preg_match( '/^[a-z0-9_\-]{1,' . $max . '}$/', $value ) ) {
					$args[ $key ] = $value;
				}
			}
		}

		if ( isset( $raw['actor'] ) && is_scalar( $raw['actor'] ) && ! is_bool( $raw['actor'] ) ) {
			$actor = trim( (string) $raw['actor'] );

			if ( 0 === strpos( $actor, 'login:' ) ) {
				$actor = trim( substr( $actor, 6 ) );
				$login = self::cut( self::one_line( $actor ), 60 );

				$args['actor'] = '' !== $login ? 'login:' . $login : '';
			} elseif ( 'none' === $actor || self::is_positive_int( $actor ) ) {
				$args['actor'] = 'none' === $actor ? 'none' : (string) (int) $actor;
			} elseif ( '' !== $actor && ! ctype_digit( $actor ) ) {
				// Typed into the text field the screen shows on a log with many people.
				$login = self::cut( self::one_line( $actor ), 60 );

				$args['actor'] = '' !== $login ? 'login:' . $login : '';
			}
		}

		if ( isset( $raw['acting_as'] ) && is_scalar( $raw['acting_as'] ) && ! is_bool( $raw['acting_as'] ) ) {
			$acting = trim( (string) $raw['acting_as'] );

			if ( 'none' === $acting || 'any' === $acting ) {
				$args['acting_as'] = $acting;
			} elseif ( self::is_positive_int( $acting ) ) {
				$args['acting_as'] = (string) (int) $acting;
			}
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && self::valid_date( $raw[ $key ] ) ) {
				$args[ $key ] = $raw[ $key ];
			}
		}

		if ( isset( $raw['orderby'] ) && is_string( $raw['orderby'] ) && isset( self::SORTABLE[ $raw['orderby'] ] ) ) {
			$args['orderby'] = $raw['orderby'];
		}

		if ( isset( $raw['order'] ) && is_string( $raw['order'] ) && 'asc' === strtolower( $raw['order'] ) ) {
			$args['order'] = 'asc';
		}

		if ( isset( $raw['paged'] ) && is_scalar( $raw['paged'] ) ) {
			$args['paged'] = max( 1, min( self::MAX_PAGED, (int) $raw['paged'] ) );
		}

		if ( isset( $raw['per_page'] ) && is_scalar( $raw['per_page'] ) ) {
			$args['per_page'] = max( 1, min( self::MAX_PER_PAGE, (int) $raw['per_page'] ) );
		}

		return $args;
	}

	/**
	 * Whether any filter (not sorting or paging) is set. Pure.
	 *
	 * @param array $args Normalized arguments.
	 * @return bool
	 */
	public static function is_filtered( array $args ) {
		foreach ( self::FILTER_KEYS as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a string is a real `Y-m-d` calendar date. Pure.
	 *
	 * @param string $date Candidate.
	 * @return bool
	 */
	public static function valid_date( $date ) {
		if ( ! is_string( $date ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );

		return $year >= 1970 && checkdate( $month, $day, $year );
	}

	/**
	 * A site-local date range as UTC bounds. Pure.
	 *
	 * `from` is the start of that local day; `to` includes its whole day, so
	 * the upper bound is the start of the next local day, exclusive. The next
	 * day is found on the calendar, not by adding 86,400 seconds, so a range
	 * across a daylight-saving change stays right.
	 *
	 * @param string       $from Local `Y-m-d`, or ''.
	 * @param string       $to   Local `Y-m-d`, or ''.
	 * @param DateTimeZone $tz   Site timezone.
	 * @return array{from:string|null,to_exclusive:string|null} UTC `Y-m-d H:i:s`.
	 */
	public static function utc_range( $from, $to, DateTimeZone $tz ) {
		$utc    = new DateTimeZone( 'UTC' );
		$result = array(
			'from'         => null,
			'to_exclusive' => null,
		);

		if ( self::valid_date( $from ) ) {
			$start          = new DateTimeImmutable( $from . ' 00:00:00', $tz );
			$result['from'] = $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		if ( self::valid_date( $to ) ) {
			$next                   = ( new DateTimeImmutable( $to . ' 00:00:00', $utc ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$end                    = new DateTimeImmutable( $next . ' 00:00:00', $tz );
			$result['to_exclusive'] = $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		}

		return $result;
	}

	/**
	 * The WHERE clause for normalized arguments. Pure.
	 *
	 * Only column names written here reach the SQL; every value is a
	 * placeholder bound later through `$wpdb->prepare()`.
	 *
	 * @param array        $args Normalized arguments.
	 * @param DateTimeZone $tz   Site timezone.
	 * @return array{sql:string,params:array} `sql` is '1=1' when nothing filters.
	 */
	public static function build_where( array $args, DateTimeZone $tz ) {
		$clauses = array();
		$params  = array();

		foreach ( array( 'module', 'action' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$clauses[] = $key . ' = %s';
				$params[]  = (string) $args[ $key ];
			}
		}

		$actor = isset( $args['actor'] ) ? (string) $args['actor'] : '';

		if ( 'none' === $actor ) {
			$clauses[] = 'actor_id = %d';
			$params[]  = 0;
		} elseif ( self::is_positive_int( $actor ) ) {
			$clauses[] = 'actor_id = %d';
			$params[]  = (int) $actor;
		} elseif ( 0 === strpos( $actor, 'login:' ) && strlen( $actor ) > 6 ) {
			$clauses[] = 'actor_login = %s';
			$params[]  = substr( $actor, 6 );
		}

		$acting = isset( $args['acting_as'] ) ? (string) $args['acting_as'] : '';

		if ( 'none' === $acting ) {
			$clauses[] = 'acting_as_id = %d';
			$params[]  = 0;
		} elseif ( 'any' === $acting ) {
			$clauses[] = 'acting_as_id > %d';
			$params[]  = 0;
		} elseif ( self::is_positive_int( $acting ) ) {
			$clauses[] = 'acting_as_id = %d';
			$params[]  = (int) $acting;
		}

		$range = self::utc_range(
			isset( $args['from'] ) ? (string) $args['from'] : '',
			isset( $args['to'] ) ? (string) $args['to'] : '',
			$tz
		);

		if ( null !== $range['from'] ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $range['from'];
		}

		if ( null !== $range['to_exclusive'] ) {
			$clauses[] = 'created_at < %s';
			$params[]  = $range['to_exclusive'];
		}

		return array(
			'sql'    => empty( $clauses ) ? '1=1' : implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * The ORDER BY clause for a sort key and direction. Pure.
	 *
	 * An unknown key falls back to newest first. `id` breaks ties, so paging
	 * through rows written in the same second is stable.
	 *
	 * @param string $orderby Sort key (a key of SORTABLE).
	 * @param string $order   `asc` or `desc`.
	 * @return string
	 */
	public static function order_by( $orderby, $order ) {
		$direction = is_string( $order ) && 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		if ( ! is_string( $orderby ) || ! isset( self::SORTABLE[ $orderby ] ) ) {
			return 'created_at DESC, id DESC';
		}

		if ( 'activity' === $orderby ) {
			return "module {$direction}, action {$direction}, id {$direction}";
		}

		return self::SORTABLE[ $orderby ] . " {$direction}, id {$direction}";
	}

	/**
	 * One page of rows for the arguments, newest first by default.
	 *
	 * @param array $args Raw or normalized arguments (normalized here).
	 * @return array<int,array> Rows as from shape_row().
	 */
	public static function query( array $args ) {
		global $wpdb;

		$args   = self::normalize_args( $args );
		$where  = self::build_where( $args, wp_timezone() );
		$table  = BLT_Toolkit_Schema::table( 'log' );
		$order  = self::order_by( $args['orderby'], $args['order'] );
		$params = $where['params'];

		$params[] = (int) $args['per_page'];
		$params[] = ( (int) $args['paged'] - 1 ) * (int) $args['per_page'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table and ORDER BY come from whitelists; the WHERE placeholders come from build_where(); every value is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where['sql']} ORDER BY {$order} LIMIT %d OFFSET %d", $params ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( __CLASS__, 'shape_row' ), $rows ) : array();
	}

	/**
	 * How many rows match the arguments.
	 *
	 * @param array $args Raw or normalized arguments (normalized here).
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$args  = self::normalize_args( $args );
		$where = self::build_where( $args, wp_timezone() );
		$table = BLT_Toolkit_Schema::table( 'log' );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}";

		if ( ! empty( $where['params'] ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table is ours; the placeholders come from build_where(), and every value is bound.
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above when it has values.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * One batch of matching rows for the CSV export, newest first, keyed on
	 * `id` so memory stays flat however large the log is.
	 *
	 * @param array $args      Normalized arguments.
	 * @param int   $before_id Only rows with a smaller ID (pass the previous batch's last ID).
	 * @param int   $limit     Batch size.
	 * @return array<int,array>|null Rows (empty when there are no more), or null when the query failed.
	 */
	public static function export_batch( array $args, $before_id, $limit ) {
		global $wpdb;

		$where  = self::build_where( self::normalize_args( $args ), wp_timezone() );
		$table  = BLT_Toolkit_Schema::table( 'log' );
		$params = $where['params'];

		$params[] = max( 0, (int) $before_id );
		$params[] = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table is ours; the WHERE placeholders come from build_where(); every value is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where['sql']} AND id < %d ORDER BY id DESC LIMIT %d", $params ), ARRAY_A );

		// get_results() returns an empty array, not null, when the query fails.
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return array_map( array( __CLASS__, 'shape_row' ), $rows );
	}

	/**
	 * The highest row ID, or 0 for an empty log.
	 *
	 * @return int
	 */
	public static function max_id() {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	/**
	 * The Activity screen's filter menus: module and action pairs, actors and
	 * users acted as, without reading the whole table on every view.
	 *
	 * The lists are kept in a transient with the highest row ID they cover.
	 * A view reads that ID (a primary-key lookup) and, when rows were added
	 * since, reads only those rows (plus a small overlap) and merges them in.
	 * The prune drops the transient whenever it deletes rows, so the lists do
	 * not keep naming people whose rows are gone; the whole table is read
	 * again only then, when the transient expires (a week), or when the table
	 * was emptied or replaced.
	 *
	 * @return array{actions:array<int,array{module:string,action:string}>,actors:array<int,array{actor_id:int,actor_login:string}>,acting_as:int[],max_id:int}
	 *         Each list keeps at most MENU_ACTIONS (actions) or
	 *         MENU_USERS (actors, acting as) entries, as the queries do.
	 */
	public static function filter_menus() {
		$max    = self::max_id();
		$cached = get_transient( self::MENUS_TRANSIENT );
		$valid  = is_array( $cached ) && isset( $cached['max_id'], $cached['actions'], $cached['actors'], $cached['acting_as'] ) && (int) $cached['max_id'] <= $max;

		if ( $valid && (int) $cached['max_id'] === $max ) {
			return $cached;
		}

		$after = $valid ? max( 0, (int) $cached['max_id'] - self::MENUS_OVERLAP ) : 0;
		$menus = self::merge_menus(
			$valid ? $cached : array(),
			array(
				'actions'   => self::menu_actions( $after ),
				'actors'    => self::menu_actors( $after ),
				'acting_as' => self::menu_acting_as( $after ),
			)
		);

		$menus['max_id'] = $max;

		set_transient( self::MENUS_TRANSIENT, $menus, WEEK_IN_SECONDS );

		return $menus;
	}

	/**
	 * Drop the cached filter menus (after rows were deleted).
	 *
	 * @return void
	 */
	public static function forget_menus() {
		delete_transient( self::MENUS_TRANSIENT );
	}

	/**
	 * Merge filter-menu lists read from newer rows into cached ones, as the
	 * queries over the whole table would give them: unique, sorted the same
	 * way and cut to the same limits. Pure.
	 *
	 * @param array $cached Lists from filter_menus(), or an empty array.
	 * @param array $fresh  `actions`, `actors` and `acting_as` from newer rows.
	 * @return array{actions:array,actors:array,acting_as:int[]}
	 */
	public static function merge_menus( array $cached, array $fresh ) {
		$actions = array();

		foreach ( array( $cached, $fresh ) as $source ) {
			foreach ( isset( $source['actions'] ) && is_array( $source['actions'] ) ? $source['actions'] : array() as $pair ) {
				if ( is_array( $pair ) && isset( $pair['module'], $pair['action'] ) ) {
					$actions[ $pair['module'] . "\0" . $pair['action'] ] = array(
						'module' => (string) $pair['module'],
						'action' => (string) $pair['action'],
					);
				}
			}
		}

		ksort( $actions, SORT_STRING );

		$actors = array();

		foreach ( array( $cached, $fresh ) as $source ) {
			foreach ( isset( $source['actors'] ) && is_array( $source['actors'] ) ? $source['actors'] : array() as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['actor_id'] ) || (int) $row['actor_id'] <= 0 ) {
					continue;
				}

				$id    = (int) $row['actor_id'];
				$login = isset( $row['actor_login'] ) ? (string) $row['actor_login'] : '';

				// MAX(actor_login), as the query takes it.
				if ( ! isset( $actors[ $id ] ) || strcmp( $login, $actors[ $id ]['actor_login'] ) > 0 ) {
					$actors[ $id ] = array(
						'actor_id'    => $id,
						'actor_login' => $login,
					);
				}
			}
		}

		uasort(
			$actors,
			static function ( $a, $b ) {
				$order = strcasecmp( $a['actor_login'], $b['actor_login'] );

				return 0 !== $order ? $order : $a['actor_id'] - $b['actor_id'];
			}
		);

		$acting = array();

		foreach ( array( $cached, $fresh ) as $source ) {
			foreach ( isset( $source['acting_as'] ) && is_array( $source['acting_as'] ) ? $source['acting_as'] : array() as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$acting[ (int) $id ] = (int) $id;
				}
			}
		}

		ksort( $acting, SORT_NUMERIC );

		return array(
			'actions'   => array_slice( array_values( $actions ), 0, self::MENU_ACTIONS ),
			'actors'    => array_slice( array_values( $actors ), 0, self::MENU_USERS ),
			'acting_as' => array_slice( array_values( $acting ), 0, self::MENU_USERS ),
		);
	}

	/**
	 * Module and action pairs of rows after an ID, for filter_menus().
	 *
	 * @param int $after Row ID; 0 for every row.
	 * @return array<int,array{module:string,action:string}>
	 */
	private static function menu_actions( $after ) {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound. Cached by filter_menus().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT module, action FROM {$table} WHERE id > %d ORDER BY module ASC, action ASC LIMIT %d", (int) $after, self::MENU_ACTIONS ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Actors of rows after an ID, for filter_menus().
	 *
	 * @param int $after Row ID; 0 for every row.
	 * @return array<int,array{actor_id:int,actor_login:string}>
	 */
	private static function menu_actors( $after ) {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound. Cached by filter_menus().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT actor_id, MAX(actor_login) AS actor_login FROM {$table} WHERE id > %d AND actor_id > 0 GROUP BY actor_id ORDER BY actor_login ASC LIMIT %d", (int) $after, self::MENU_USERS ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Users acted as in rows after an ID, for filter_menus().
	 *
	 * @param int $after Row ID; 0 for every row.
	 * @return int[]
	 */
	private static function menu_acting_as( $after ) {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'log' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound. Cached by filter_menus().
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT acting_as_id FROM {$table} WHERE id > %d AND acting_as_id > 0 ORDER BY acting_as_id ASC LIMIT %d", (int) $after, self::MENU_USERS ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * A stored row with typed values and decoded details. Pure.
	 *
	 * @param array $row Row from the database.
	 * @return array
	 */
	public static function shape_row( array $row ) {
		return array(
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'created_at'   => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'module'       => isset( $row['module'] ) ? (string) $row['module'] : '',
			'action'       => isset( $row['action'] ) ? (string) $row['action'] : '',
			'actor_id'     => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : 0,
			'actor_login'  => isset( $row['actor_login'] ) ? (string) $row['actor_login'] : '',
			'acting_as_id' => isset( $row['acting_as_id'] ) ? (int) $row['acting_as_id'] : 0,
			'object_type'  => isset( $row['object_type'] ) ? (string) $row['object_type'] : '',
			'object_id'    => isset( $row['object_id'] ) ? (string) $row['object_id'] : '',
			'summary'      => isset( $row['summary'] ) ? (string) $row['summary'] : '',
			'details'      => self::decode_details( isset( $row['details'] ) ? $row['details'] : null ),
			'snapshot_id'  => isset( $row['snapshot_id'] ) && (int) $row['snapshot_id'] > 0 ? (int) $row['snapshot_id'] : null,
			'ip'           => isset( $row['ip'] ) ? (string) $row['ip'] : '',
			'status'       => isset( $row['status'] ) ? (string) $row['status'] : '',
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : '',
		);
	}

	// ---------- CSV ----------

	/**
	 * Neutralize a cell a spreadsheet would read as a formula. Pure.
	 *
	 * Log text carries names people chose (usernames, role names), so a cell
	 * that starts with `=`, `+`, `-`, `@`, a tab or a line break, or that does
	 * so after leading spaces (some importers trim them), gets a leading
	 * apostrophe, which spreadsheets show as text.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		if ( null === $value || is_bool( $value ) || ! is_scalar( $value ) ) {
			$value = is_bool( $value ) ? ( $value ? '1' : '0' ) : '';
		}

		$value   = (string) $value;
		$trimmed = ltrim( $value, ' ' );

		if ( '' !== $trimmed && in_array( $trimmed[0], self::FORMULA_LEAD, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * One CSV line, RFC 4180 quoting, every cell neutralized. Pure.
	 *
	 * @param array $cells Cell values.
	 * @return string The line with its trailing newline.
	 */
	public static function csv_line( array $cells ) {
		$out = array();

		foreach ( $cells as $cell ) {
			$cell = self::csv_cell( $cell );

			if ( '' !== $cell && strcspn( $cell, ",\"\r\n" ) !== strlen( $cell ) ) {
				$cell = '"' . str_replace( '"', '""', $cell ) . '"';
			}

			$out[] = $cell;
		}

		return implode( ',', $out ) . "\n";
	}

	// ---------- Pruning (LOG-4) ----------

	/**
	 * Days to keep rows: the `log.retention_days` setting through
	 * `blt_toolkit_log_retention_days`. Below 1 means "keep everything":
	 * nothing is pruned.
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = (int) BLT_Toolkit_Settings::get( 'log', 'retention_days' );

		/**
		 * Filter how many days activity log rows are kept (LOG-4).
		 *
		 * @param int $days The Settings > Activity log value (default 365). Return 0 to keep every row.
		 */
		return (int) apply_filters( 'blt_toolkit_log_retention_days', $days );
	}

	/**
	 * The oldest `created_at` (UTC) a row may have and be kept. Pure.
	 *
	 * @param int      $days Retention in days.
	 * @param int|null $now  Unix time; now when omitted.
	 * @return string|null `Y-m-d H:i:s`, or null when retention is off (below 1 day).
	 */
	public static function cutoff( $days, $now = null ) {
		$days = (int) $days;

		if ( $days < 1 ) {
			return null;
		}

		$now = null === $now ? time() : (int) $now;

		return gmdate( 'Y-m-d H:i:s', $now - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Daily cron callback: start the `log_prune` job (LOG-4, JOB-5).
	 *
	 * The cutoff is worked out now and pinned (JOB-3), so changing the setting
	 * while the job runs cannot change what it deletes. Every live job that
	 * went quiet is queued again first (BLT_Toolkit_Jobs::resume_stalled()),
	 * and no new prune starts while one is still live. When the job runner
	 * is unavailable, the prune runs here instead, time-boxed, and schedules
	 * its own continuation.
	 *
	 * @return void
	 */
	public static function run_daily_prune() {
		try {
			$days   = self::retention_days();
			$cutoff = self::cutoff( $days );

			if ( null === $cutoff ) {
				return;
			}

			$pinned = array(
				'cutoff'         => $cutoff,
				'retention_days' => $days,
			);
			$state  = array(
				'deleted' => 0,
				'batches' => 0,
			);

			if ( class_exists( 'BLT_Toolkit_Jobs' ) && method_exists( 'BLT_Toolkit_Jobs', 'start' ) ) {
				// Requeue any job that went quiet (JOB-1), and let a prune that
				// is still live finish rather than start a second one.
				foreach ( BLT_Toolkit_Jobs::resume_stalled() as $live ) {
					if ( self::JOB_TYPE === $live['type'] ) {
						return;
					}
				}

				$job = BLT_Toolkit_Jobs::start( self::JOB_TYPE, $state, $pinned );

				if ( ! is_wp_error( $job ) ) {
					return;
				}

				self::report( 'could not start the log_prune job (' . $job->get_error_code() . '); pruning in this cron run instead.' );
			}

			self::prune_inline( $state, $pinned );
		} catch ( \Throwable $e ) {
			self::report( 'the activity log prune failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Prune without the job runner: steps until done or the tick budget is
	 * spent, then, if rows remain, a single event a minute later carries on.
	 *
	 * @param array $state  Cursor state.
	 * @param array $pinned Pinned values (`cutoff`, `retention_days`).
	 * @return array The final state.
	 */
	public static function prune_inline( array $state, array $pinned ) {
		/** This filter is documented in HOOKS.md (JOB-2). */
		$filtered = apply_filters( 'blt_toolkit_tick_budget', 15 );
		$limit    = (int) ini_get( 'max_execution_time' );

		// The job runner's reading of the filter (a nonsense value means the
		// default, and PHP's time limit caps it), so both paths agree.
		$budget = class_exists( 'BLT_Toolkit_Jobs' ) && method_exists( 'BLT_Toolkit_Jobs', 'budget' )
			? BLT_Toolkit_Jobs::budget( $filtered, $limit )
			: max( 1.0, min( 15.0, $limit > 0 ? 0.6 * $limit : 15.0 ) );
		$start  = microtime( true );

		do {
			$done = self::prune_step( $state, $pinned );
		} while ( ! $done && ( microtime( true ) - $start ) < $budget );

		if ( ! $done ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
		}

		return $state;
	}

	/**
	 * One step of the `log_prune` job (cursor mode): delete up to BATCH rows
	 * older than the pinned cutoff, oldest first.
	 *
	 * Each step is complete on its own, so a job stopped anywhere resumes
	 * cleanly. A database error ends the job with the error in its state,
	 * rather than retrying forever.
	 *
	 * @param array $state  Cursor state, updated in place: `deleted`, `batches`, and `error` on failure.
	 * @param array $pinned Pinned at start: `cutoff` (UTC `Y-m-d H:i:s`) and `retention_days`.
	 * @return bool True when finished.
	 */
	public static function prune_step( array &$state, array $pinned ) {
		global $wpdb;

		$state['deleted'] = isset( $state['deleted'] ) ? (int) $state['deleted'] : 0;
		$state['batches'] = isset( $state['batches'] ) ? (int) $state['batches'] : 0;

		$cutoff = isset( $pinned['cutoff'] ) ? (string) $pinned['cutoff'] : '';

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cutoff ) ) {
			$state['error'] = 'no valid cutoff was pinned';

			return true;
		}

		$table      = BLT_Toolkit_Schema::table( 'log' );
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is ours; values are bound.
		$rows  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, self::BATCH ) );
		$error = (string) $wpdb->last_error;

		$wpdb->suppress_errors( $suppressed );

		if ( false === $rows ) {
			$state['error'] = self::cut( '' !== $error ? $error : 'the delete failed', 500 );

			self::report( 'the activity log prune stopped after ' . $state['deleted'] . ' rows: ' . $state['error'] );

			return true;
		}

		$state['deleted'] += (int) $rows;
		++$state['batches'];

		// The cached filter menus may name people whose rows are now gone.
		if ( (int) $rows > 0 ) {
			self::forget_menus();
		}

		if ( (int) $rows >= self::BATCH ) {
			return false;
		}

		self::record_prune( $state, $pinned );

		return true;
	}

	/**
	 * Log a finished prune that removed rows, so deleting part of the audit
	 * trail is itself on the record.
	 *
	 * @param array $state  Final state.
	 * @param array $pinned Pinned values.
	 * @return void
	 */
	private static function record_prune( array $state, array $pinned ) {
		if ( $state['deleted'] <= 0 ) {
			return;
		}

		$days = isset( $pinned['retention_days'] ) ? (int) $pinned['retention_days'] : 0;

		$age = sprintf(
			/* translators: %s: number of days. */
			_n( '%s day', '%s days', $days, 'blt-toolkit' ),
			number_format_i18n( $days )
		);

		self::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'log_pruned',
				'object_type' => 'log',
				'summary'     => sprintf(
					/* translators: 1: number of entries, 2: an age such as "365 days". */
					_n( 'Removed %1$s activity log entry older than %2$s.', 'Removed %1$s activity log entries older than %2$s.', $state['deleted'], 'blt-toolkit' ),
					number_format_i18n( $state['deleted'] ),
					$age
				),
				'details'     => array(
					'deleted'        => (int) $state['deleted'],
					'cutoff'         => (string) $pinned['cutoff'],
					'retention_days' => $days,
				),
			)
		);
	}

	// ---------- Helpers ----------

	/**
	 * Whether a string is a whole number above zero. Pure.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_positive_int( $value ) {
		return is_string( $value ) && '' !== $value && ctype_digit( $value ) && strlen( $value ) <= 19 && (int) $value > 0;
	}

	/**
	 * Text as one line of valid UTF-8: invalid bytes replaced, control
	 * characters and line breaks turned into spaces, runs of white space
	 * collapsed, trimmed. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( $text ) {
		$text = (string) $text;

		if ( 1 !== preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_convert_encoding' )
				? (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' )
				: (string) preg_replace( '/[\x80-\xFF]/', '?', $text );
		}

		$text = (string) preg_replace( '/[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]+/u', ' ', $text );
		$text = (string) preg_replace( '/ {2,}/', ' ', $text );

		return trim( $text );
	}

	/**
	 * Cut text to a number of characters, never through a character. Pure.
	 *
	 * @param string $text Text.
	 * @param int    $max  Most characters.
	 * @return string
	 */
	private static function cut( $text, $max ) {
		$text = (string) $text;

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, (int) $max, 'UTF-8' );
		}

		return substr( $text, 0, (int) $max );
	}
}
