<?php
/**
 * Activity screen: the activity log, filtered, paged and exportable.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Activity screen (SPEC 13.2). Needs `blt_view_toolkit_log`, as does the
 * CSV export.
 *
 * - A filter toolbar (module, action, actor, acting as, date range) that is a
 *   plain GET form, so every view of the log is a URL and works without
 *   JavaScript.
 * - The rows in a `WP_List_Table` inside a `.blt-card`, each with a
 *   `<details>` disclosure showing everything recorded, details and role
 *   diffs rendered as lists rather than raw JSON.
 * - Export CSV: a POST with a nonce through admin-post.php, streamed in
 *   batches so memory stays flat, every cell neutralized against formula
 *   injection (BLT_Toolkit_Log::csv_line()).
 *
 * Rendering reads the log and changes nothing (RO-5).
 */
class BLT_Toolkit_Admin_Activity {

	/**
	 * admin-post.php action of the CSV export (see BLT_Toolkit_Admin::post_actions()).
	 */
	const EXPORT_ACTION = 'blt_toolkit_export_log';

	/**
	 * Rows read per query while streaming the CSV.
	 */
	const EXPORT_BATCH = 500;

	/**
	 * Distinct people above which the Who filter becomes a text field.
	 */
	const USER_SELECT_LIMIT = 200;

	/**
	 * Users looked up so far, keyed by ID (false when the user is gone).
	 *
	 * @var array<int,WP_User|false>
	 */
	private $users = array();

	/**
	 * Role display names, keyed by role ID, read once.
	 *
	 * @var array<string,string>|null
	 */
	private $role_names = null;

	/**
	 * The filter menus' lists (BLT_Toolkit_Log::filter_menus()), read once
	 * per request.
	 *
	 * @var array|null
	 */
	private $menus = null;

	// ---------- Screen ----------

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only filters, normalized by BLT_Toolkit_Log::normalize_args().
		$args  = self::request_args( wp_unslash( $_GET ) );
		$table = new BLT_Toolkit_Admin_Activity_Table( $this, $args );

		$table->prepare_items();

		$total     = (int) $table->get_pagination_arg( 'total_items' );
		$filtered  = BLT_Toolkit_Log::is_filtered( $args );
		$log_total = $filtered ? BLT_Toolkit_Log::count() : $total;
		$filters   = self::filter_state( $args );
		$sort      = self::sort_state( $args );
		$modules   = $this->module_options();
		$actions   = $this->action_options();
		$actors    = $this->actor_options();
		$acting_as = $this->acting_as_options();
		$reset_url = BLT_Toolkit_Admin::page_url( 'activity' );
		$sub       = BLT_Toolkit_Admin::screen_title( 'activity' );
		$buttons   = array();

		if ( current_user_can( 'manage_options' ) ) {
			$buttons[] = array(
				'label' => __( 'Log settings', 'blt-toolkit' ),
				'url'   => BLT_Toolkit_Admin::page_url( 'settings', array( 'tab' => 'log' ) ),
			);
		}

		require BLT_TOOLKIT_DIR . 'admin/views/activity.php';
	}

	/**
	 * Query arguments from a request.
	 *
	 * The action filter travels as `log_action`: `action` would make
	 * wp-admin/admin.php fire `admin_action_{value}`, and on the export form
	 * it would replace admin-post.php's own `action` field.
	 *
	 * @param array $raw Unslashed request arguments.
	 * @return array Normalized arguments.
	 */
	public static function request_args( array $raw ) {
		$raw['action'] = isset( $raw['log_action'] ) ? $raw['log_action'] : '';

		return BLT_Toolkit_Log::normalize_args( $raw );
	}

	/**
	 * The filters as URL arguments: only those that are set, named as the
	 * screen's forms name them (see request_args()).
	 *
	 * @param array $args Normalized arguments.
	 * @return array<string,string>
	 */
	public static function filter_state( array $args ) {
		$state = array();

		foreach ( BLT_Toolkit_Log::FILTER_KEYS as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$state[ 'action' === $key ? 'log_action' : $key ] = (string) $args[ $key ];
			}
		}

		// A typed username travels as typed; normalize_args() adds the prefix back.
		if ( isset( $state['actor'] ) && 0 === strpos( $state['actor'], 'login:' ) ) {
			$state['actor'] = substr( $state['actor'], 6 );
		}

		return $state;
	}

	/**
	 * A non-default sort as URL arguments.
	 *
	 * @param array $args Normalized arguments.
	 * @return array<string,string>
	 */
	public static function sort_state( array $args ) {
		if ( 'when' === $args['orderby'] && 'desc' === $args['order'] ) {
			return array();
		}

		return array(
			'orderby' => (string) $args['orderby'],
			'order'   => (string) $args['order'],
		);
	}

	/**
	 * The lists behind the filter menus, cached across requests by the log
	 * (SEC-9 spirit: a page view does not read the whole table) and read once
	 * here.
	 *
	 * @return array{actions:array,actors:array,acting_as:int[]}
	 */
	private function menus() {
		if ( null === $this->menus ) {
			$this->menus = BLT_Toolkit_Log::filter_menus();
		}

		return $this->menus;
	}

	/**
	 * Module filter choices: the built-in modules, then any other module the
	 * log holds rows for.
	 *
	 * @return array<string,string> Key => label.
	 */
	private function module_options() {
		$options = array();

		foreach ( array( 'switch', 'usernames', 'roles', 'toolkit' ) as $key ) {
			$options[ $key ] = $this->module_label( $key );
		}

		foreach ( $this->menus()['actions'] as $pair ) {
			$key = (string) $pair['module'];

			if ( '' !== $key && ! isset( $options[ $key ] ) ) {
				$options[ $key ] = $this->module_label( $key );
			}
		}

		return $options;
	}

	/**
	 * Action filter choices: every action the log holds, by label.
	 *
	 * @return array<string,string> Key => label.
	 */
	private function action_options() {
		$options = array();

		foreach ( $this->menus()['actions'] as $pair ) {
			$key = (string) $pair['action'];

			if ( '' !== $key ) {
				$options[ $key ] = $this->action_label( $key );
			}
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	/**
	 * Who filter choices, or null when there are too many people for a menu
	 * (the screen then shows a text field for a username).
	 *
	 * @return array<string,string>|null User ID => label.
	 */
	private function actor_options() {
		$rows = array_slice( $this->menus()['actors'], 0, self::USER_SELECT_LIMIT + 1 );

		if ( count( $rows ) > self::USER_SELECT_LIMIT ) {
			return null;
		}

		$this->prime_users( wp_list_pluck( $rows, 'actor_id' ) );

		$options = array();

		foreach ( $rows as $row ) {
			$options[ (string) $row['actor_id'] ] = $this->user_label( $row['actor_id'], $row['actor_login'] );
		}

		return $options;
	}

	/**
	 * Acting-as filter choices: everyone acted as while switched.
	 *
	 * @return array<string,string> User ID => label.
	 */
	private function acting_as_options() {
		$ids = array_slice( $this->menus()['acting_as'], 0, self::USER_SELECT_LIMIT );

		$this->prime_users( $ids );

		$options = array();

		foreach ( $ids as $id ) {
			$options[ (string) $id ] = $this->user_label( $id );
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	// ---------- CSV export ----------

	/**
	 * Stream the filtered log as CSV, newest first. Reached only through
	 * BLT_Toolkit_Admin::route_post(), which has checked the POST method,
	 * `blt_view_toolkit_log` and the nonce.
	 *
	 * The file holds the rows that existed when the export started, read in
	 * keyset batches. The export itself is logged first, so a download that
	 * is cancelled halfway is still on the record.
	 *
	 * @return void
	 */
	public function handle_export() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in BLT_Toolkit_Admin::route_post(); normalized by BLT_Toolkit_Log::normalize_args().
		$args  = self::request_args( wp_unslash( $_POST ) );
		$upto  = BLT_Toolkit_Log::max_id();
		$total = BLT_Toolkit_Log::count( $args );
		$state = array_filter(
			array_intersect_key( $args, array_flip( BLT_Toolkit_Log::FILTER_KEYS ) ),
			function ( $value ) {
				return '' !== (string) $value;
			}
		);

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'log_exported',
				'object_type' => 'log',
				'summary'     => sprintf(
					/* translators: %s: number of entries. */
					_n( 'Exported %s activity log entry as CSV.', 'Exported %s activity log entries as CSV.', $total, 'blt-toolkit' ),
					number_format_i18n( $total )
				),
				'details'     => array(
					'entries' => $total,
					'filters' => $state,
				),
			)
		);

		// A long log can outlast the default time limit; memory stays flat.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A long download of the site's own log.
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blt-toolkit-activity-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		echo BLT_Toolkit_Log::csv_line( $this->csv_header() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, neutralized cell by cell in BLT_Toolkit_Log::csv_line().

		$this->stream_rows( $args, $upto );

		exit;
	}

	/**
	 * The CSV header row.
	 *
	 * @return string[]
	 */
	private function csv_header() {
		return array(
			__( 'ID', 'blt-toolkit' ),
			__( 'Time (site)', 'blt-toolkit' ),
			__( 'Time (UTC)', 'blt-toolkit' ),
			__( 'Module', 'blt-toolkit' ),
			__( 'Action', 'blt-toolkit' ),
			__( 'Actor ID', 'blt-toolkit' ),
			__( 'Actor', 'blt-toolkit' ),
			__( 'Acting as ID', 'blt-toolkit' ),
			__( 'Acting as', 'blt-toolkit' ),
			__( 'Object type', 'blt-toolkit' ),
			__( 'Object ID', 'blt-toolkit' ),
			__( 'Summary', 'blt-toolkit' ),
			__( 'Status', 'blt-toolkit' ),
			__( 'Snapshot ID', 'blt-toolkit' ),
			__( 'IP', 'blt-toolkit' ),
			__( 'Source', 'blt-toolkit' ),
			__( 'Details (JSON)', 'blt-toolkit' ),
		);
	}

	/**
	 * Write the matching rows up to `$upto` as CSV lines, in keyset batches.
	 *
	 * If a query fails, a last line says the file is incomplete instead of
	 * the file ending as if it were whole.
	 *
	 * @param array $args Normalized arguments.
	 * @param int   $upto Highest row ID to include.
	 * @return int Rows written.
	 */
	private function stream_rows( array $args, $upto ) {
		$written   = 0;
		$before_id = (int) $upto + 1;

		do {
			$rows = BLT_Toolkit_Log::export_batch( $args, $before_id, self::EXPORT_BATCH );

			if ( null === $rows ) {
				$line = BLT_Toolkit_Log::csv_line(
					array(
						__( 'ERROR', 'blt-toolkit' ),
						sprintf(
							/* translators: %s: number of entries written before the error. */
							__( 'This file is incomplete: a database error stopped the export after %s entries. Export again.', 'blt-toolkit' ),
							number_format_i18n( $written )
						),
					)
				);

				echo $line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, neutralized cell by cell.

				return $written;
			}

			$this->prime_users( array_merge( wp_list_pluck( $rows, 'actor_id' ), wp_list_pluck( $rows, 'acting_as_id' ) ) );

			foreach ( $rows as $row ) {
				echo BLT_Toolkit_Log::csv_line( $this->csv_cells( $row ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, neutralized cell by cell.

				++$written;
				$before_id = (int) $row['id'];
			}

			flush();

			// The user objects of this batch are not needed again.
			$this->users = array();
			$fetched     = count( $rows );
		} while ( $fetched >= self::EXPORT_BATCH );

		return $written;
	}

	/**
	 * The CSV cells of one row.
	 *
	 * @param array $row Row from BLT_Toolkit_Log::shape_row().
	 * @return array
	 */
	private function csv_cells( array $row ) {
		$details = is_array( $row['details'] ) ? $row['details'] : array();

		return array(
			$row['id'],
			$this->site_time( $row['created_at'], 'Y-m-d H:i:s' ),
			$row['created_at'],
			$row['module'],
			$row['action'],
			$row['actor_id'],
			$row['actor_login'],
			$row['acting_as_id'] > 0 ? $row['acting_as_id'] : '',
			$row['acting_as_id'] > 0 ? $this->login_for( $row['acting_as_id'], $details ) : '',
			$row['object_type'],
			$row['object_id'],
			$row['summary'],
			$row['status'],
			null !== $row['snapshot_id'] ? $row['snapshot_id'] : '',
			$row['ip'],
			$row['source'],
			empty( $details ) ? '' : (string) wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
	}

	// ---------- Labels ----------

	/**
	 * Look up several users in one query and keep them for this request.
	 *
	 * @param array $ids User IDs.
	 * @return void
	 */
	public function prime_users( array $ids ) {
		$ids = array_values( array_diff( array_unique( array_filter( array_map( 'intval', $ids ) ) ), array_keys( $this->users ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		cache_users( $ids );

		foreach ( $ids as $id ) {
			$this->users[ $id ] = get_userdata( $id );
		}
	}

	/**
	 * A user, or false when the user no longer exists.
	 *
	 * @param int $id User ID.
	 * @return WP_User|false
	 */
	private function user( $id ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return false;
		}

		if ( ! array_key_exists( $id, $this->users ) ) {
			$this->users[ $id ] = get_userdata( $id );
		}

		return $this->users[ $id ];
	}

	/**
	 * How a person is named on screen: "Display Name (login)", the login
	 * alone when they match, or the recorded login marked deleted.
	 *
	 * @param int    $id    User ID.
	 * @param string $login Login recorded in the row, used when the user is gone.
	 * @return string Plain text.
	 */
	public function user_label( $id, $login = '' ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return __( 'System (no signed-in user)', 'blt-toolkit' );
		}

		$user = $this->user( $id );

		if ( $user ) {
			if ( '' === (string) $user->display_name || $user->display_name === $user->user_login ) {
				return (string) $user->user_login;
			}

			/* translators: 1: a user's display name, 2: their username. */
			return sprintf( __( '%1$s (%2$s)', 'blt-toolkit' ), $user->display_name, $user->user_login );
		}

		if ( '' !== (string) $login ) {
			/* translators: %s: the username of a user who has since been deleted. */
			return sprintf( __( '%s (deleted)', 'blt-toolkit' ), $login );
		}

		/* translators: %d: ID of a user who has since been deleted. */
		return sprintf( __( 'User %d (deleted)', 'blt-toolkit' ), $id );
	}

	/**
	 * A user's login now, or the one recorded in the switch chain.
	 *
	 * @param int   $id      User ID.
	 * @param array $details The row's details.
	 * @return string
	 */
	private function login_for( $id, array $details ) {
		$user = $this->user( $id );

		if ( $user ) {
			return (string) $user->user_login;
		}

		return $this->chain_login( $id, $details );
	}

	/**
	 * The login recorded for a user in a row's switch chain, or ''.
	 *
	 * @param int   $id      User ID.
	 * @param array $details The row's details.
	 * @return string
	 */
	private function chain_login( $id, array $details ) {
		if ( empty( $details['switch_chain'] ) || ! is_array( $details['switch_chain'] ) ) {
			return '';
		}

		foreach ( $details['switch_chain'] as $link ) {
			if ( is_array( $link ) && isset( $link['id'], $link['login'] ) && (int) $link['id'] === (int) $id ) {
				return (string) $link['login'];
			}
		}

		return '';
	}

	/**
	 * Who did it, for the table: the actor, and who they were acting as.
	 *
	 * @param array $row Row.
	 * @return array{actor:string,acting_as:string} Plain text; `acting_as` is '' when not switched.
	 */
	public function who( array $row ) {
		$details = is_array( $row['details'] ) ? $row['details'] : array();

		return array(
			'actor'     => $this->user_label( $row['actor_id'], $row['actor_login'] ),
			'acting_as' => $row['acting_as_id'] > 0 ? $this->user_label( $row['acting_as_id'], $this->chain_login( $row['acting_as_id'], $details ) ) : '',
		);
	}

	/**
	 * A module's name.
	 *
	 * @param string $key Module key.
	 * @return string
	 */
	public function module_label( $key ) {
		if ( 'toolkit' === $key ) {
			return __( 'BLT Toolkit', 'blt-toolkit' );
		}

		$labels = BLT_Toolkit_Plugin::labels();

		return isset( $labels[ $key ] ) ? $labels[ $key ]['label'] : self::humanize( $key );
	}

	/**
	 * An action's name. Known actions have a written label; any other reads
	 * as its key with spaces.
	 *
	 * @param string $key Action key.
	 * @return string
	 */
	public function action_label( $key ) {
		$labels = array(
			'switched_to'           => __( 'Switched to a user', 'blt-toolkit' ),
			'switched_back'         => __( 'Switched back', 'blt-toolkit' ),
			'switched_off'          => __( 'Switched off', 'blt-toolkit' ),
			'username_changed'      => __( 'Username changed', 'blt-toolkit' ),
			'bulk_rename'           => __( 'Bulk rename', 'blt-toolkit' ),
			'bulk_rename_cancelled' => __( 'Bulk rename cancelled', 'blt-toolkit' ),
			'bulk_rename_retried'   => __( 'Bulk rename retried', 'blt-toolkit' ),
			'role_saved'            => __( 'Role saved', 'blt-toolkit' ),
			'roles_saved'           => __( 'Roles saved', 'blt-toolkit' ),
			'role_created'          => __( 'Role created', 'blt-toolkit' ),
			'role_deleted'          => __( 'Role deleted', 'blt-toolkit' ),
			'role_renamed'          => __( 'Role renamed', 'blt-toolkit' ),
			'role_copied'           => __( 'Role copied', 'blt-toolkit' ),
			'role_merged'           => __( 'Roles merged', 'blt-toolkit' ),
			'roles_reset'           => __( 'Roles reset', 'blt-toolkit' ),
			'roles_imported'        => __( 'Roles imported', 'blt-toolkit' ),
			'roles_exported'        => __( 'Roles exported', 'blt-toolkit' ),
			'snapshot_restored'     => __( 'Snapshot restored', 'blt-toolkit' ),
			'capability_added'      => __( 'Capability added', 'blt-toolkit' ),
			'capability_deleted'    => __( 'Capability deleted', 'blt-toolkit' ),
			'user_roles_changed'    => __( 'User roles changed', 'blt-toolkit' ),
			'user_caps_changed'     => __( 'User capabilities changed', 'blt-toolkit' ),
			'role_details_saved'    => __( 'Role details saved', 'blt-toolkit' ),
			'reassign_cancelled'    => __( 'Role reassignment cancelled', 'blt-toolkit' ),
			'reassign_retried'      => __( 'Role reassignment retried', 'blt-toolkit' ),
			'post_type_isolated'    => __( 'Separate permissions on', 'blt-toolkit' ),
			'post_type_shared'      => __( 'Separate permissions off', 'blt-toolkit' ),
			'administrator_synced'  => __( 'Administrator synced', 'blt-toolkit' ),
			'recovery'              => __( 'Recovery mode', 'blt-toolkit' ),
			'log_exported'          => __( 'Activity exported', 'blt-toolkit' ),
			'log_pruned'            => __( 'Old entries removed', 'blt-toolkit' ),
			'legacy_imported'       => __( 'Imported from another plugin', 'blt-toolkit' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : self::humanize( $key );
	}

	/**
	 * A status badge (DESIGN 2, Badge), escaped.
	 *
	 * @param string $status `success`, `warning` or `failure`.
	 * @return string
	 */
	public function status_badge( $status ) {
		switch ( $status ) {
			case 'failure':
				return BLT_Toolkit_Admin::badge( 'cancelled', __( 'Failed', 'blt-toolkit' ) );
			case 'warning':
				return BLT_Toolkit_Admin::badge( 'pending', __( 'Warning', 'blt-toolkit' ) );
		}

		return BLT_Toolkit_Admin::badge( 'confirmed', __( 'Success', 'blt-toolkit' ) );
	}

	/**
	 * What the row acted on, in words, or ''.
	 *
	 * @param array $row Row.
	 * @return string Plain text.
	 */
	public function object_label( array $row ) {
		$type = (string) $row['object_type'];
		$id   = (string) $row['object_id'];

		if ( '' === $type && '' === $id ) {
			return '';
		}

		switch ( $type ) {
			case 'user':
				$details = is_array( $row['details'] ) ? $row['details'] : array();

				/* translators: %s: a user's name. */
				return sprintf( __( 'User: %s', 'blt-toolkit' ), $this->user_label( (int) $id, $this->chain_login( (int) $id, $details ) ) );

			case 'role':
				$name = $this->role_name( $id );

				if ( '' === $name || $name === $id ) {
					/* translators: %s: role ID, such as editor. */
					return sprintf( __( 'Role: %s', 'blt-toolkit' ), $id );
				}

				/* translators: 1: role name, 2: role ID. */
				return sprintf( __( 'Role: %1$s (%2$s)', 'blt-toolkit' ), $name, $id );

			case 'log':
				return __( 'Activity log', 'blt-toolkit' );

			case 'snapshot':
				/* translators: %d: snapshot number. */
				return sprintf( __( 'Snapshot %d', 'blt-toolkit' ), (int) $id );
		}

		/* translators: 1: kind of thing, such as Post type, 2: its ID. */
		return sprintf( __( '%1$s: %2$s', 'blt-toolkit' ), self::humanize( $type ), $id );
	}

	/**
	 * A role's display name now, or '' when the role no longer exists.
	 *
	 * Reads the roles WordPress already loaded; never writes (RO-5).
	 *
	 * @param string $role_id Role ID.
	 * @return string
	 */
	public function role_name( $role_id ) {
		if ( null === $this->role_names ) {
			$this->role_names = array();

			foreach ( wp_roles()->role_names as $id => $name ) {
				$this->role_names[ (string) $id ] = translate_user_role( (string) $name );
			}
		}

		return isset( $this->role_names[ (string) $role_id ] ) ? $this->role_names[ (string) $role_id ] : '';
	}

	/**
	 * A UTC `Y-m-d H:i:s` time in the site's timezone.
	 *
	 * @param string $utc    Stored time.
	 * @param string $format Date format; the site's date and time formats when ''.
	 * @return string
	 */
	public function site_time( $utc, $format = '' ) {
		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return (string) $utc;
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		return (string) wp_date( $format, $timestamp );
	}

	/**
	 * How long ago a UTC time was, such as "3 hours ago".
	 *
	 * @param string $utc Stored time.
	 * @return string
	 */
	public function relative_time( $utc ) {
		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		/* translators: %s: a span of time, such as "3 hours". */
		return sprintf( __( '%s ago', 'blt-toolkit' ), human_time_diff( $timestamp, time() ) );
	}

	/**
	 * A machine key as words: `role_saved` reads "Role saved".
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function humanize( $key ) {
		$text = trim( str_replace( array( '_', '-' ), ' ', (string) $key ) );

		return '' === $text ? '' : ucfirst( $text );
	}

	// ---------- Row disclosure ----------

	/**
	 * Everything recorded for one row, as escaped HTML for its disclosure.
	 *
	 * @param array $row Row from BLT_Toolkit_Log::shape_row().
	 * @return string
	 */
	public function details_html( array $row ) {
		$details = is_array( $row['details'] ) ? $row['details'] : array();
		$who     = $this->who( $row );
		$chain   = $this->chain_labels( $details );
		$object  = $this->object_label( $row );

		unset( $details['switch_chain'] );

		ob_start();
		require BLT_TOOLKIT_DIR . 'admin/views/activity-details.php';

		return (string) ob_get_clean();
	}

	/**
	 * The switch chain as names, oldest first, or an empty array.
	 *
	 * @param array $details The row's details.
	 * @return string[]
	 */
	private function chain_labels( array $details ) {
		if ( empty( $details['switch_chain'] ) || ! is_array( $details['switch_chain'] ) ) {
			return array();
		}

		$labels = array();

		foreach ( $details['switch_chain'] as $link ) {
			if ( is_array( $link ) && isset( $link['id'] ) ) {
				$labels[] = $this->user_label( (int) $link['id'], isset( $link['login'] ) ? (string) $link['login'] : '' );
			}
		}

		return $labels;
	}

	/**
	 * The details a module recorded, as escaped HTML: role diffs as lists of
	 * capabilities added, removed and flipped; other values as labeled
	 * fields; nesting as nested lists.
	 *
	 * @param array $details Details, without the switch chain.
	 * @param int   $depth   Nesting depth.
	 * @return string
	 */
	public function fields_html( array $details, $depth = 0 ) {
		if ( self::is_roles_diff( $details ) ) {
			return $this->diff_html( $details );
		}

		$blocks = '';
		$items  = '';

		foreach ( $details as $key => $value ) {
			$label = $this->field_label( $key );

			if ( is_array( $value ) && self::is_roles_diff( $value ) ) {
				$blocks .= '<div class="blt-toolkit-activity-block"><p class="blt-toolkit-activity-block-title">' . esc_html( $label ) . '</p>' . $this->diff_html( $value ) . '</div>';
				continue;
			}

			if ( is_array( $value ) && self::is_role_change( $value ) ) {
				$blocks .= '<div class="blt-toolkit-activity-block"><p class="blt-toolkit-activity-block-title">' . esc_html( $label ) . '</p>' . $this->role_change_html( '', $value ) . '</div>';
				continue;
			}

			// A snapshot's reason reads as its label (`baseline` is "Baseline,
			// before BLT Toolkit changed anything"); other text stays as it is.
			if ( 'reason' === $key && is_string( $value ) ) {
				$value = BLT_Toolkit_Snapshots::reason_label( $value );
			}

			$items .= '<dt>' . esc_html( $label ) . '</dt><dd>' . $this->value_html( $value, $depth + 1 ) . '</dd>';
		}

		$list = '' !== $items ? '<dl class="blt-toolkit-activity-fields">' . $items . '</dl>' : '';

		return $list . $blocks;
	}

	/**
	 * One value as escaped HTML.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Nesting depth.
	 * @return string
	 */
	private function value_html( $value, $depth ) {
		if ( null === $value || ( is_array( $value ) && empty( $value ) ) || '' === $value ) {
			return '<span class="blt-toolkit-muted">' . esc_html__( 'None', 'blt-toolkit' ) . '</span>';
		}

		if ( is_bool( $value ) ) {
			return esc_html( $value ? __( 'Yes', 'blt-toolkit' ) : __( 'No', 'blt-toolkit' ) );
		}

		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		// A change of one value: from / to, before / after, old / new.
		foreach ( array( array( 'from', 'to' ), array( 'before', 'after' ), array( 'old', 'new' ) ) as $pair ) {
			if ( 2 === count( $value ) && array_key_exists( $pair[0], $value ) && array_key_exists( $pair[1], $value ) ) {
				return $this->value_html( $value[ $pair[0] ], $depth + 1 )
					. ' <span aria-hidden="true">&rarr;</span><span class="screen-reader-text">' . esc_html__( 'changed to', 'blt-toolkit' ) . '</span> '
					. $this->value_html( $value[ $pair[1] ], $depth + 1 );
			}
		}

		if ( $depth > 4 ) {
			return '<code class="blt-code">' . esc_html( (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</code>';
		}

		// A plain list of words: one line, comma separated.
		if ( array_values( $value ) === $value && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
			return esc_html( implode( ', ', array_map( array( __CLASS__, 'scalar_text' ), $value ) ) );
		}

		return $this->fields_html( $value, $depth );
	}

	/**
	 * A scalar as text (booleans as Yes and No).
	 *
	 * @param mixed $value Scalar.
	 * @return string
	 */
	private static function scalar_text( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'blt-toolkit' ) : __( 'No', 'blt-toolkit' );
		}

		return (string) $value;
	}

	/**
	 * A details key as a label.
	 *
	 * @param int|string $key Key.
	 * @return string
	 */
	private function field_label( $key ) {
		$labels = array(
			'before'            => __( 'Before', 'blt-toolkit' ),
			'after'             => __( 'After', 'blt-toolkit' ),
			'old_login'         => __( 'Old username', 'blt-toolkit' ),
			'new_login'         => __( 'New username', 'blt-toolkit' ),
			'diff'              => __( 'Changes', 'blt-toolkit' ),
			'changes'           => __( 'Changes', 'blt-toolkit' ),
			'reason'            => __( 'Reason', 'blt-toolkit' ),
			'entries'           => __( 'Entries', 'blt-toolkit' ),
			'filters'           => __( 'Filters', 'blt-toolkit' ),
			'deleted'           => __( 'Entries removed', 'blt-toolkit' ),
			'cutoff'            => __( 'Older than (UTC)', 'blt-toolkit' ),
			'retention_days'    => __( 'Keep entries for (days)', 'blt-toolkit' ),
			'error'             => __( 'Error', 'blt-toolkit' ),
			'truncated'         => __( 'Too large to keep in full', 'blt-toolkit' ),
			'bytes'             => __( 'Size in bytes', 'blt-toolkit' ),
			'keys'              => __( 'Recorded fields', 'blt-toolkit' ),
			'granted'           => __( 'Capabilities granted', 'blt-toolkit' ),
			'denied'            => __( 'Capabilities denied', 'blt-toolkit' ),
			'inherited'         => __( 'Back to what the roles give', 'blt-toolkit' ),
			'added'             => __( 'Added', 'blt-toolkit' ),
			'removed'           => __( 'Removed', 'blt-toolkit' ),
			'unchanged'         => __( 'Unchanged', 'blt-toolkit' ),
			'skipped'           => __( 'Skipped', 'blt-toolkit' ),
			'source'            => __( 'Source', 'blt-toolkit' ),
			'context'           => __( 'Where', 'blt-toolkit' ),
			'role_recreated'    => __( 'Role created again', 'blt-toolkit' ),
			'snapshot_failed'   => __( 'Snapshot could not be saved', 'blt-toolkit' ),
			'roles_in_code'     => __( 'Roles defined in code', 'blt-toolkit' ),
			'restored_snapshot' => __( 'Restored snapshot', 'blt-toolkit' ),
			'id'                => __( 'ID', 'blt-toolkit' ),
			'created_at'        => __( 'Taken (UTC)', 'blt-toolkit' ),
			'counts'            => __( 'How much changed', 'blt-toolkit' ),
			'roles'             => __( 'Roles', 'blt-toolkit' ),
			'capabilities'      => __( 'Capabilities', 'blt-toolkit' ),
			'module'            => __( 'Module', 'blt-toolkit' ),
			'action'            => __( 'Action', 'blt-toolkit' ),
			'actor'             => __( 'Who', 'blt-toolkit' ),
			'acting_as'         => __( 'Acting as', 'blt-toolkit' ),
			'from'              => __( 'From', 'blt-toolkit' ),
			'to'                => __( 'To', 'blt-toolkit' ),
		);

		if ( is_int( $key ) ) {
			/* translators: %d: position in a list, starting at 1. */
			return sprintf( __( 'Item %d', 'blt-toolkit' ), $key + 1 );
		}

		return isset( $labels[ $key ] ) ? $labels[ $key ] : self::humanize( $key );
	}

	/**
	 * Whether a value has the shape of BLT_Toolkit_Role_Diff::diff(): roles
	 * added, removed and changed.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_roles_diff( $value ) {
		if ( ! is_array( $value ) || 3 !== count( $value ) ) {
			return false;
		}

		foreach ( array( 'added', 'removed', 'changed' ) as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_array( $value[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value has the shape of one role's change inside a diff:
	 * `added`, `removed` and `flipped` capabilities, and optionally `name`.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_role_change( $value ) {
		if ( ! is_array( $value ) || ! array_key_exists( 'flipped', $value ) || ! is_array( $value['flipped'] ) ) {
			return false;
		}

		$extra = array_diff( array_keys( $value ), array( 'name', 'added', 'removed', 'flipped' ) );

		return empty( $extra );
	}

	/**
	 * A roles diff as escaped HTML: each role added or removed with its
	 * capabilities, and each role changed with what changed.
	 *
	 * @param array $diff Result of BLT_Toolkit_Role_Diff::diff().
	 * @return string
	 */
	public function diff_html( array $diff ) {
		if ( empty( $diff['added'] ) && empty( $diff['removed'] ) && empty( $diff['changed'] ) ) {
			return '<p class="blt-toolkit-muted">' . esc_html__( 'No role or capability changed.', 'blt-toolkit' ) . '</p>';
		}

		$html = '';

		foreach ( array(
			/* translators: %s: role name, in bold. */
			'added'   => __( 'Role added: %s', 'blt-toolkit' ),
			/* translators: %s: role name, in bold. */
			'removed' => __( 'Role removed: %s', 'blt-toolkit' ),
		) as $side => $heading ) {
			foreach ( $diff[ $side ] as $role_id => $role ) {
				$role  = is_array( $role ) ? $role : array();
				$name  = isset( $role['name'] ) && is_scalar( $role['name'] ) ? (string) $role['name'] : '';
				$caps  = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
				$html .= '<div class="blt-toolkit-diff-role">'
					. '<p class="blt-toolkit-diff-role-name">' . sprintf( esc_html( $heading ), '<strong>' . esc_html( '' !== $name ? $name : (string) $role_id ) . '</strong>' ) . ' <code>' . esc_html( (string) $role_id ) . '</code></p>'
					. $this->caps_html(
						'added' === $side
							/* translators: %s: number of capabilities. */
							? _n( '%s capability', '%s capabilities', count( $caps ), 'blt-toolkit' )
							/* translators: %s: number of capabilities. */
							: _n( '%s capability it had', '%s capabilities it had', count( $caps ), 'blt-toolkit' ),
						$caps
					)
					. '</div>';
			}
		}

		foreach ( $diff['changed'] as $role_id => $change ) {
			if ( is_array( $change ) ) {
				$html .= $this->role_change_html( (string) $role_id, $change );
			}
		}

		return '<div class="blt-toolkit-diff">' . $html . '</div>';
	}

	/**
	 * One role's change as escaped HTML.
	 *
	 * @param string $role_id Role ID, or '' when the change stands alone.
	 * @param array  $change  `name`, `added`, `removed`, `flipped`.
	 * @return string
	 */
	private function role_change_html( $role_id, array $change ) {
		$html = '<div class="blt-toolkit-diff-role">';

		if ( '' !== $role_id ) {
			$name  = $this->role_name( $role_id );
			$html .= '<p class="blt-toolkit-diff-role-name"><strong>' . esc_html( '' !== $name ? $name : $role_id ) . '</strong> <code>' . esc_html( $role_id ) . '</code></p>';
		}

		if ( isset( $change['name'] ) && is_array( $change['name'] ) && isset( $change['name']['from'], $change['name']['to'] ) ) {
			$html .= '<p>' . sprintf(
				/* translators: 1: old role name, 2: new role name. */
				esc_html__( 'Renamed from “%1$s” to “%2$s”.', 'blt-toolkit' ),
				esc_html( (string) $change['name']['from'] ),
				esc_html( (string) $change['name']['to'] )
			) . '</p>';
		}

		if ( ! empty( $change['added'] ) && is_array( $change['added'] ) ) {
			/* translators: %s: number of capabilities. */
			$html .= $this->caps_html( _n( '%s capability added', '%s capabilities added', count( $change['added'] ), 'blt-toolkit' ), $change['added'] );
		}

		if ( ! empty( $change['removed'] ) && is_array( $change['removed'] ) ) {
			/* translators: %s: number of capabilities. */
			$html .= $this->caps_html( _n( '%s capability removed', '%s capabilities removed', count( $change['removed'] ), 'blt-toolkit' ), $change['removed'] );
		}

		if ( ! empty( $change['flipped'] ) && is_array( $change['flipped'] ) ) {
			$rows = '';

			foreach ( $change['flipped'] as $cap => $flip ) {
				$to    = is_array( $flip ) && ! empty( $flip['to'] );
				$rows .= '<li><code>' . esc_html( (string) $cap ) . '</code> '
					. esc_html( $to ? __( 'now granted (was denied)', 'blt-toolkit' ) : __( 'now denied (was granted)', 'blt-toolkit' ) )
					. '</li>';
			}

			$html .= '<p class="blt-toolkit-diff-label">' . esc_html(
				sprintf(
					/* translators: %s: number of capabilities. */
					_n( '%s capability switched between granted and denied', '%s capabilities switched between granted and denied', count( $change['flipped'] ), 'blt-toolkit' ),
					number_format_i18n( count( $change['flipped'] ) )
				)
			) . '</p><ul class="blt-toolkit-diff-flips">' . $rows . '</ul>';
		}

		return $html . '</div>';
	}

	/**
	 * A labeled set of capability chips. A capability stored as denied
	 * (`false`) says so.
	 *
	 * @param string $label Translated label with a %s for the count.
	 * @param array  $caps  Capability => grant.
	 * @return string
	 */
	private function caps_html( $label, array $caps ) {
		$label = sprintf( $label, number_format_i18n( count( $caps ) ) );

		if ( empty( $caps ) ) {
			return '<p class="blt-toolkit-diff-label">' . esc_html( $label ) . '</p>';
		}

		$chips = '';

		foreach ( $caps as $cap => $grant ) {
			$chips .= '<code class="blt-chip">' . esc_html( (string) $cap ) . ( $grant ? '' : ' ' . esc_html__( '(denied)', 'blt-toolkit' ) ) . '</code>';
		}

		return '<p class="blt-toolkit-diff-label">' . esc_html( $label ) . '</p><span class="blt-chips">' . $chips . '</span>';
	}
}
