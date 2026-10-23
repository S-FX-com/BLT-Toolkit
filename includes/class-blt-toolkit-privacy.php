<?php
/**
 * Privacy: the personal data exporter and eraser for the activity log, and
 * the suggested privacy policy text (SPEC 14.5, SW-24).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress's privacy tools for what BLT Toolkit stores about people
 * (SPEC 14.5).
 *
 * - Exporter (Tools > Export Personal Data): every activity log row the
 *   person is part of, as the actor, as the user acted as while someone had
 *   switched to their account, as the object (a row about their account),
 *   or named inside the details (a switch chain, the people of a bulk role
 *   change, the people moved out of a role, the users given separate
 *   permissions, a log export filtered to them).
 * - Eraser (Tools > Erase Personal Data): keeps those rows, which are a
 *   security record, and takes the person out of them. On rows they made,
 *   `actor_login` and `ip` are blanked; on every row, their logins are
 *   blanked wherever the details hold them (switch chains, `from`, `to`,
 *   old and new usernames and author slugs, bulk lists), and their logins
 *   and display name are replaced in the summary. The rows are reported as
 *   retained. Afterwards the Activity screen's cached filter menus are
 *   dropped (BLT_Toolkit_Log::forget_menus()), so its Who menu stops
 *   offering the blanked login.
 * - Policy text (Settings > Privacy > Policy Guide): one "BLT Toolkit"
 *   section with the activity log and, while the Switch module runs, the
 *   switch cookies (SW-24).
 *
 * The IP address and the login stored with a row belong to its actor. On a
 * row where the person is only the user acted as or the object, they are
 * the actor's (an administrator's), so the eraser keeps them: they are what
 * makes the row a security record, and they are not the requester's data.
 *
 * Registered only on wp-admin (the privacy tools run through
 * admin-ajax.php), WP-Cron and WP-CLI requests, and only as hooks: a
 * visitor's page view loads nothing of this (SEC-9).
 *
 * The rules that decide whether a row is about a person and what to blank
 * are pure (relations(), scrub_row(), scrub_details(), scrub_text(),
 * logins_in(), needles()) and unit-tested in tests/test-privacy.php.
 */
class BLT_Toolkit_Privacy {

	/**
	 * Key of the exporter and the eraser.
	 */
	const ID = 'blt-toolkit';

	/**
	 * Group of the exported items.
	 */
	const GROUP_ID = 'blt-toolkit-activity';

	/**
	 * Candidate rows read per exporter page.
	 */
	const EXPORT_BATCH = 100;

	/**
	 * Candidate rows read per eraser call.
	 */
	const ERASE_BATCH = 200;

	/**
	 * Transient carrying an erasure across the eraser's calls, suffixed with
	 * the user ID: where it got to, the logins it looks for and its counts.
	 */
	const STATE_TRANSIENT = 'blt_toolkit_privacy_erase_';

	/**
	 * Most logins searched for one person (current, recorded in rename rows,
	 * recorded as their actor login or in their switch chain entries).
	 */
	const MAX_LOGINS = 20;

	/**
	 * Rows read to collect a person's earlier logins, per source.
	 */
	const MAX_SCAN = 500;

	/**
	 * Deepest details nesting walked.
	 */
	const MAX_DEPTH = 64;

	/**
	 * Details keys holding a login, wherever they appear: a rename's old and
	 * new username (UN-10, the bulk rename, Username Changer's imported rows).
	 */
	const LOGIN_KEYS = array( 'old_login', 'new_login' );

	/**
	 * Details keys holding an author slug, blanked on rows about the person.
	 */
	const SLUG_KEYS = array( 'old_author_slug', 'new_author_slug' );

	/**
	 * Lists of logins in a bulk role change on the Users screen (US-3), whose
	 * row has the object type `users`.
	 */
	const BULK_LISTS = array( 'added', 'removed', 'unchanged' );

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private static $registered = false;

	// ---------- Boot ----------

	/**
	 * Add the hooks. Called from BLT_Toolkit_Plugin::boot() on wp-admin,
	 * WP-Cron and WP-CLI requests; only adds hooks.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * `wp_privacy_personal_data_exporters`: add ours.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'BLT Toolkit activity log', 'blt-toolkit' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * `wp_privacy_personal_data_erasers`: add ours.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'BLT Toolkit activity log', 'blt-toolkit' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	// ---------- Policy text (SPEC 14.5, SW-24) ----------

	/**
	 * `admin_init`: suggest the privacy policy text, as one section.
	 *
	 * @return void
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'BLT Toolkit', self::policy_text() );
	}

	/**
	 * The suggested text: the activity log as configured (IP recording,
	 * retention), what export and erase requests do with it, and the switch
	 * cookies while the Switch module runs (SW-24).
	 *
	 * @return string HTML.
	 */
	public static function policy_text() {
		$days = BLT_Toolkit_Log::retention_days();

		$text  = '<h2>' . esc_html__( 'Activity log', 'blt-toolkit' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'This text suggests what to tell people about the record BLT Toolkit keeps. Review it and edit it to match how you use the site.', 'blt-toolkit' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When someone switches to another user\'s account, changes a username, or changes roles and permissions, we record it: who did it, whose account they were using if they had switched to another user, when, and what changed, including the usernames involved.', 'blt-toolkit' ) . '</p>';
		$text .= '<p>' . esc_html( BLT_Toolkit_Settings::get( 'log', 'record_ip' ) ? __( 'The record includes the IP address each change came from.', 'blt-toolkit' ) : __( 'The record does not include IP addresses.', 'blt-toolkit' ) ) . '</p>';

		if ( $days > 0 ) {
			$text .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of days entries are kept. */
					_n( 'Entries are deleted automatically after %s day.', 'Entries are deleted automatically after %s days.', $days, 'blt-toolkit' ),
					number_format_i18n( $days )
				)
			) . '</p>';
		}

		$text .= '<p>' . esc_html__( 'If you ask for a copy of your personal data, it includes the entries about you. If you ask us to erase your personal data, your username and IP address are removed from those entries, and the entries themselves are kept as a security record.', 'blt-toolkit' ) . '</p>';

		if ( BLT_Toolkit_Plugin::is_active( 'switch' ) ) {
			$text .= '<h2>' . esc_html__( 'Cookies', 'blt-toolkit' ) . '</h2>';
			$text .= '<p>' . esc_html__( 'When a user with permission switches to another user\'s account, BLT Toolkit sets cookies that let them switch back. Like the cookies WordPress sets when you log in, they contain the username of the user who switched, an expiration time and a secure hash. They expire after two days at most, when the browser is closed unless that user chose "Remember Me" when logging in, and as soon as anyone logs in or out in that browser.', 'blt-toolkit' ) . '</p>';
		}

		return wp_kses_post( $text );
	}

	// ---------- Exporter ----------

	/**
	 * Export the activity log rows a person is part of (SPEC 14.5).
	 *
	 * Pages through the candidate rows (matched in SQL by ID or by one of
	 * the person's logins), keeping those relations() confirms.
	 *
	 * @param string $email_address The person's email address.
	 * @param int    $page          Page, from 1.
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email_address, $page = 1 ) {
		$empty = array(
			'data' => array(),
			'done' => true,
		);
		$user  = get_user_by( 'email', (string) $email_address );

		if ( ! $user instanceof WP_User ) {
			return $empty;
		}

		$user_id = (int) $user->ID;
		$logins  = self::user_logins( $user );
		$rows    = self::candidates( $user_id, $logins, 0, self::EXPORT_BATCH, ( max( 1, (int) $page ) - 1 ) * self::EXPORT_BATCH );

		if ( null === $rows ) {
			return $empty;
		}

		$labels = class_exists( 'BLT_Toolkit_Admin_Activity' ) ? new BLT_Toolkit_Admin_Activity() : null;
		$items  = array();

		foreach ( $rows as $row ) {
			$relations = self::relations( $row, $user_id, $logins );

			if ( array() !== $relations ) {
				$items[] = self::export_item( $row, $relations, $labels );
			}
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::EXPORT_BATCH,
		);
	}

	/**
	 * One exported row.
	 *
	 * The username and IP address stored with a row are exported only when
	 * the person made it: otherwise they are someone else's.
	 *
	 * @param array                          $row       Row (BLT_Toolkit_Log::shape_row()).
	 * @param string[]                       $relations relations().
	 * @param BLT_Toolkit_Admin_Activity|null $labels   The Activity screen, for module and action names.
	 * @return array
	 */
	private static function export_item( array $row, array $relations, $labels ) {
		$parts = array();

		foreach ( $relations as $relation ) {
			$parts[] = self::relation_label( $relation );
		}

		$activity = $row['module'] . ' / ' . $row['action'];

		if ( $labels instanceof BLT_Toolkit_Admin_Activity ) {
			/* translators: 1: module name, such as Roles. 2: action name, such as Role saved. */
			$activity = sprintf( __( '%1$s: %2$s', 'blt-toolkit' ), $labels->module_label( $row['module'] ), $labels->action_label( $row['action'] ) );
		}

		$data = array(
			array(
				'name'  => __( 'When', 'blt-toolkit' ),
				'value' => '' !== $row['created_at'] ? get_date_from_gmt( $row['created_at'], 'Y-m-d H:i:s' ) : '',
			),
			array(
				'name'  => __( 'Activity', 'blt-toolkit' ),
				'value' => $activity,
			),
			array(
				'name'  => __( 'What happened', 'blt-toolkit' ),
				'value' => $row['summary'],
			),
			array(
				'name'  => __( 'Your part', 'blt-toolkit' ),
				'value' => implode( wp_get_list_item_separator(), $parts ),
			),
		);

		if ( in_array( 'actor', $relations, true ) ) {
			if ( '' !== $row['actor_login'] ) {
				$data[] = array(
					'name'  => __( 'Username recorded', 'blt-toolkit' ),
					'value' => $row['actor_login'],
				);
			}

			if ( '' !== $row['ip'] ) {
				$data[] = array(
					'name'  => __( 'IP address', 'blt-toolkit' ),
					'value' => $row['ip'],
				);
			}
		}

		return array(
			'group_id'          => self::GROUP_ID,
			'group_label'       => __( 'BLT Toolkit activity log', 'blt-toolkit' ),
			'group_description' => __( 'Changes recorded by BLT Toolkit that you made, that were made while someone had switched to your account, or that are about you.', 'blt-toolkit' ),
			'item_id'           => 'blt-toolkit-log-' . (int) $row['id'],
			'data'              => $data,
		);
	}

	/**
	 * A relation as the export names it.
	 *
	 * @param string $relation `actor`, `acting_as`, `object` or `named`.
	 * @return string
	 */
	private static function relation_label( $relation ) {
		switch ( $relation ) {
			case 'actor':
				return __( 'Made by you', 'blt-toolkit' );
			case 'acting_as':
				return __( 'Made while someone had switched to your account', 'blt-toolkit' );
			case 'object':
				return __( 'About your account', 'blt-toolkit' );
		}

		return __( 'Names you', 'blt-toolkit' );
	}

	// ---------- Eraser ----------

	/**
	 * Erase a person from the activity log, keeping the rows (SPEC 14.5).
	 *
	 * Each call scrubs up to ERASE_BATCH candidate rows in ID order. What it
	 * got to, the logins it searches for and its counts travel to the next
	 * call in a transient: the person's earlier logins are read from rows
	 * (rename rows, their actor logins and switch chain entries) that this
	 * erasure blanks, so later calls could no longer find rows that name
	 * them only by an old login. Page 1 always starts afresh; a later page
	 * whose transient is gone starts again from the first row, which is
	 * harmless because scrubbing a clean row changes nothing.
	 *
	 * @param string $email_address The person's email address.
	 * @param int    $page          Page, from 1.
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( $email_address, $page = 1 ) {
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		$user   = get_user_by( 'email', (string) $email_address );

		if ( ! $user instanceof WP_User ) {
			return $result;
		}

		$user_id = (int) $user->ID;
		$key     = self::STATE_TRANSIENT . $user_id;
		$state   = (int) $page > 1 ? self::state( get_transient( $key ) ) : null;

		if ( null === $state ) {
			$state = array(
				'after'    => 0,
				'logins'   => self::user_logins( $user ),
				'retained' => 0,
				'changed'  => 0,
			);
		}

		$names       = '' !== (string) $user->display_name ? array( (string) $user->display_name ) : array();
		$placeholder = function_exists( 'wp_privacy_anonymize_data' ) ? (string) wp_privacy_anonymize_data( 'text' ) : '[deleted]';
		$rows        = self::candidates( $user_id, $state['logins'], $state['after'], self::ERASE_BATCH, 0 );

		if ( null === $rows ) {
			delete_transient( $key );

			return $result;
		}

		$changed  = 0;
		$retained = 0;

		foreach ( $rows as $row ) {
			$state['after'] = max( $state['after'], (int) $row['id'] );

			if ( array() === self::relations( $row, $user_id, $state['logins'] ) ) {
				continue;
			}

			++$retained;

			$changes = self::scrub_row( $row, $user_id, $state['logins'], $names, $placeholder );

			if ( array() !== $changes && self::update_row( (int) $row['id'], $changes ) ) {
				++$changed;
			}
		}

		$state['retained'] += $retained;
		$state['changed']  += $changed;

		$result['items_removed']  = $changed > 0;
		$result['items_retained'] = $retained > 0;

		if ( count( $rows ) >= self::ERASE_BATCH ) {
			set_transient( $key, $state, HOUR_IN_SECONDS );

			$result['done'] = false;

			return $result;
		}

		delete_transient( $key );

		if ( $state['changed'] > 0 ) {
			// The Who menu caches each actor's login (filter_menus()).
			BLT_Toolkit_Log::forget_menus();
			self::log_erasure( $user_id, $state['retained'], $state['changed'] );
		}

		if ( $state['retained'] > 0 ) {
			$result['messages'][] = sprintf(
				/* translators: %s: number of activity log entries. */
				_n(
					'BLT Toolkit kept %s activity log entry as a security record. This person\'s username and IP address were removed from it.',
					'BLT Toolkit kept %s activity log entries as a security record. This person\'s usernames and IP addresses were removed from them.',
					$state['retained'],
					'blt-toolkit'
				),
				number_format_i18n( $state['retained'] )
			);
		}

		return $result;
	}

	/**
	 * A stored erasure state, or null when it is missing or damaged.
	 *
	 * @param mixed $state Transient value.
	 * @return array{after:int,logins:string[],retained:int,changed:int}|null
	 */
	private static function state( $state ) {
		if ( ! is_array( $state ) || ! isset( $state['after'], $state['logins'], $state['retained'], $state['changed'] ) || ! is_array( $state['logins'] ) ) {
			return null;
		}

		return array(
			'after'    => max( 0, (int) $state['after'] ),
			'logins'   => self::clean_logins( $state['logins'] ),
			'retained' => max( 0, (int) $state['retained'] ),
			'changed'  => max( 0, (int) $state['changed'] ),
		);
	}

	/**
	 * Write a scrubbed row's changed columns.
	 *
	 * @param int   $id      Row ID.
	 * @param array $changes Column => value (scrub_row()).
	 * @return bool Whether the row was written.
	 */
	private static function update_row( $id, array $changes ) {
		global $wpdb;

		$formats    = array_fill( 0, count( $changes ), '%s' );
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table; a privacy erasure.
		$ok = $wpdb->update( BLT_Toolkit_Schema::table( 'log' ), $changes, array( 'id' => (int) $id ), $formats, array( '%d' ) );

		$wpdb->suppress_errors( $suppressed );

		return false !== $ok;
	}

	/**
	 * Record the erasure itself (the log records changes to its own entries,
	 * as it does exports and prunes). Names the person by ID only.
	 *
	 * @param int $user_id  The person.
	 * @param int $retained Rows they were part of.
	 * @param int $changed  Rows changed.
	 * @return void
	 */
	private static function log_erasure( $user_id, $retained, $changed ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'personal_data_erased',
				'object_type' => 'user',
				'object_id'   => (string) (int) $user_id,
				'summary'     => sprintf(
					/* translators: 1: number of entries, 2: user ID. */
					_n(
						'Removed the username and IP address of user ID %2$d from %1$s activity log entry for a personal data erasure request. The entry was kept.',
						'Removed the usernames and IP addresses of user ID %2$d from %1$s activity log entries for a personal data erasure request. The entries were kept.',
						$changed,
						'blt-toolkit'
					),
					number_format_i18n( $changed ),
					(int) $user_id
				),
				'details'     => array(
					'entries' => (int) $retained,
					'changed' => (int) $changed,
				),
			)
		);
	}

	// ---------- Reading ----------

	/**
	 * Candidate rows for a person, in ID order: rows they made or were acted
	 * as in, rows about their account, and rows whose details name them by
	 * ID or by one of their logins (needles()). A LIKE match is only a
	 * candidate; relations() decides.
	 *
	 * The details searches scan the table, as `acting_as_id` (no index in
	 * SPEC 14.3) does; the privacy tools run them a page at a time.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $logins  The person's logins.
	 * @param int      $after   Only rows with a higher ID.
	 * @param int      $limit   Rows to read.
	 * @param int      $offset  Rows to skip.
	 * @return array[]|null Rows (BLT_Toolkit_Log::shape_row()), or null when the table cannot be read.
	 */
	private static function candidates( $user_id, array $logins, $after, $limit, $offset ) {
		global $wpdb;

		$table  = BLT_Toolkit_Schema::table( 'log' );
		$where  = array( 'actor_id = %d', 'acting_as_id = %d', "( object_type = 'user' AND object_id = %s )" );
		$params = array( (int) $after, (int) $user_id, (int) $user_id, (string) (int) $user_id );

		foreach ( self::needles( $user_id, $logins ) as $needle ) {
			$where[]  = 'details LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		$params[] = (int) $limit;
		$params[] = (int) $offset;

		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Our own table; the clauses are fixed strings with one bound value per placeholder.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d AND ( " . implode( ' OR ', $where ) . ' ) ORDER BY id ASC LIMIT %d OFFSET %d', $params ), ARRAY_A );
		$error = (string) $wpdb->last_error;

		$wpdb->suppress_errors( $suppressed );

		if ( '' !== $error || ! is_array( $rows ) ) {
			return null;
		}

		return array_map( array( 'BLT_Toolkit_Log', 'shape_row' ), $rows );
	}

	/**
	 * Every login the log may hold for a person: the current one, the ones
	 * stored as their actor login, the old and new usernames of the renames
	 * of their account, and the logins recorded for them in switch chains
	 * and other person entries. Current first, at most MAX_LOGINS.
	 *
	 * @param WP_User $user The person.
	 * @return string[]
	 */
	private static function user_logins( WP_User $user ) {
		global $wpdb;

		$table   = BLT_Toolkit_Schema::table( 'log' );
		$user_id = (int) $user->ID;
		$current = (string) $user->user_login;
		$logins  = array( $current );

		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; values are bound.
		$actor = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT actor_login FROM {$table} WHERE actor_id = %d AND actor_login <> '' LIMIT %d", $user_id, self::MAX_LOGINS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; values are bound.
		$renames = $wpdb->get_col( $wpdb->prepare( "SELECT details FROM {$table} WHERE object_type = 'user' AND object_id = %s AND action = 'username_changed' AND details IS NOT NULL ORDER BY id ASC LIMIT %d", (string) $user_id, self::MAX_SCAN ) );

		// Person entries recorded with another login than today's.
		$people = array();

		foreach ( array( 'id', 'user_id' ) as $key ) {
			$prefix = '"' . $key . '":' . $user_id . ',"login":';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; values are bound.
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT details FROM {$table} WHERE details LIKE %s AND details NOT LIKE %s AND details NOT LIKE %s ORDER BY id ASC LIMIT %d", '%' . $wpdb->esc_like( $prefix ) . '%', '%' . $wpdb->esc_like( $prefix . self::json_string( $current ) ) . '%', '%' . $wpdb->esc_like( $prefix . '""' ) . '%', self::MAX_SCAN ) );

			$people = array_merge( $people, is_array( $found ) ? $found : array() );
		}

		$wpdb->suppress_errors( $suppressed );

		$logins = array_merge( $logins, is_array( $actor ) ? $actor : array() );

		foreach ( is_array( $renames ) ? $renames : array() as $json ) {
			$details = BLT_Toolkit_Log::decode_details( $json );

			if ( is_array( $details ) ) {
				$logins = array_merge( $logins, self::logins_in( $details, $user_id, true ) );
			}
		}

		foreach ( $people as $json ) {
			$details = BLT_Toolkit_Log::decode_details( $json );

			if ( is_array( $details ) ) {
				$logins = array_merge( $logins, self::logins_in( $details, $user_id, false ) );
			}
		}

		return self::clean_logins( $logins );
	}

	// ---------- Pure rules ----------

	/**
	 * How a row relates to a person. Pure.
	 *
	 * `actor` (they made it), `acting_as` (someone had switched to their
	 * account), `object` (the row is about their account: object type
	 * `user` with their ID) and `named` (none of those, but the details name
	 * them: a person entry with their ID or login, one of their logins in a
	 * login field, or a log export filtered to them).
	 *
	 * @param array    $row     Row (BLT_Toolkit_Log::shape_row()).
	 * @param int      $user_id User ID.
	 * @param string[] $logins  The person's logins.
	 * @return string[] Empty when the row is not about them.
	 */
	public static function relations( array $row, $user_id, array $logins ) {
		$user_id = (int) $user_id;
		$out     = array();

		if ( $user_id <= 0 ) {
			return $out;
		}

		if ( isset( $row['actor_id'] ) && (int) $row['actor_id'] === $user_id ) {
			$out[] = 'actor';
		}

		if ( isset( $row['acting_as_id'] ) && (int) $row['acting_as_id'] === $user_id ) {
			$out[] = 'acting_as';
		}

		if ( self::is_about( $row, $user_id ) ) {
			$out[] = 'object';
		}

		if ( array() === $out && isset( $row['details'] ) && is_array( $row['details'] ) ) {
			$scrub = self::scrub_details( $row['details'], $user_id, $logins, self::object_user( $row ), self::is_bulk( $row ) );

			if ( $scrub['found'] ) {
				$out[] = 'named';
			}
		}

		return $out;
	}

	/**
	 * The columns to change so a row no longer identifies a person. Pure.
	 *
	 * - `actor_login` and `ip`, blanked on a row they made (on any other row
	 *   both belong to its actor and stay).
	 * - `details`: their logins blanked (scrub_details()), re-encoded only
	 *   when something changed, so other values keep their stored form.
	 * - `summary`: their logins and names replaced (scrub_text()).
	 *
	 * @param array    $row         Row (BLT_Toolkit_Log::shape_row()).
	 * @param int      $user_id     User ID.
	 * @param string[] $logins      The person's logins.
	 * @param string[] $names       Other names to take out of the summary (the display name).
	 * @param string   $placeholder What replaces a name in the summary.
	 * @return array<string,string|null> Column => new value; empty when nothing changes.
	 */
	public static function scrub_row( array $row, $user_id, array $logins, array $names, $placeholder ) {
		$user_id = (int) $user_id;
		$changes = array();

		if ( $user_id <= 0 ) {
			return $changes;
		}

		if ( isset( $row['actor_id'] ) && (int) $row['actor_id'] === $user_id ) {
			foreach ( array( 'actor_login', 'ip' ) as $column ) {
				if ( isset( $row[ $column ] ) && '' !== (string) $row[ $column ] ) {
					$changes[ $column ] = '';
				}
			}
		}

		if ( isset( $row['details'] ) && is_array( $row['details'] ) ) {
			$scrub = self::scrub_details( $row['details'], $user_id, $logins, self::object_user( $row ), self::is_bulk( $row ) );

			if ( $scrub['details'] !== $row['details'] ) {
				$changes['details'] = BLT_Toolkit_Log::encode_details( $scrub['details'] );
			}
		}

		$summary = isset( $row['summary'] ) ? (string) $row['summary'] : '';
		$cleaned = self::scrub_text( $summary, array_merge( $logins, $names ), $placeholder );

		if ( $cleaned !== $summary ) {
			$changes['summary'] = $cleaned;
		}

		return $changes;
	}

	/**
	 * Details with a person's logins blanked, and whether they were named.
	 * Pure.
	 *
	 * - A person entry (an array with a string `login`, as in `switch_chain`,
	 *   `from`, `to`, `duplicate_of`, the people of a role move or of an
	 *   isolation): its login is blanked when its `id` or `user_id` is the
	 *   person's, or, without an ID, when the login is one of theirs.
	 * - `old_login` and `new_login`: blanked on a row about their account;
	 *   on a row about no user (Username Changer's imported rows whose user
	 *   was not found), when the value is one of their logins. A rename row
	 *   about someone else keeps them even when they match: logins are
	 *   reused, and those are that person's. `old_author_slug` and
	 *   `new_author_slug` are blanked on a row about their account.
	 * - `filters` (a log export): a typed username of theirs is blanked; their
	 *   ID counts as naming them.
	 * - On a bulk role change (`$bulk`): their logins in `added`, `removed`
	 *   and `unchanged`, and as a key of `skipped`, become ''.
	 *
	 * Logins compare without regard to case, as WordPress compares them.
	 *
	 * @param array    $details     Decoded details.
	 * @param int      $user_id     User ID.
	 * @param string[] $logins      The person's logins.
	 * @param int      $object_user The user the row is about (object type `user`), 0 for none.
	 * @param bool     $bulk        Whether the row is a bulk role change.
	 * @return array{details:array,found:bool}
	 */
	public static function scrub_details( array $details, $user_id, array $logins, $object_user = 0, $bulk = false ) {
		$found = false;
		$lower = self::lower_set( $logins );
		$about = (int) $user_id > 0 && (int) $object_user === (int) $user_id;
		$loose = (int) $object_user <= 0;
		$out   = self::walk( $details, (int) $user_id, $lower, $about, $loose, $found, 0 );

		if ( $bulk ) {
			foreach ( self::BULK_LISTS as $key ) {
				if ( ! isset( $out[ $key ] ) || ! is_array( $out[ $key ] ) ) {
					continue;
				}

				foreach ( $out[ $key ] as $index => $value ) {
					if ( is_scalar( $value ) && ! is_bool( $value ) && self::is_login( (string) $value, $lower ) ) {
						$out[ $key ][ $index ] = '';
						$found                 = true;
					}
				}
			}

			if ( isset( $out['skipped'] ) && is_array( $out['skipped'] ) ) {
				$skipped = array();

				foreach ( $out['skipped'] as $login => $rule ) {
					if ( self::is_login( (string) $login, $lower ) ) {
						$found = true;
						$login = '';

						if ( array_key_exists( '', $skipped ) ) {
							continue;
						}
					}

					$skipped[ $login ] = $rule;
				}

				$out['skipped'] = $skipped;
			}
		}

		return array(
			'details' => $out,
			'found'   => $found,
		);
	}

	/**
	 * Walk one level of details for scrub_details().
	 *
	 * @param array              $node    Array to walk.
	 * @param int                $user_id User ID.
	 * @param array<string,bool> $lower   lower_set() of the logins.
	 * @param bool               $about   Whether the row is about the person.
	 * @param bool               $loose   Whether the row is about no user, so a matching old or new login is theirs.
	 * @param bool               $found   Set to true when the person is named.
	 * @param int                $depth   Nesting depth.
	 * @return array
	 */
	private static function walk( array $node, $user_id, array $lower, $about, $loose, &$found, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return $node;
		}

		if ( isset( $node['login'] ) && is_string( $node['login'] ) ) {
			$owner = self::owner( $node );

			if ( null !== $owner ? $owner === $user_id : self::is_login( $node['login'], $lower ) ) {
				$found         = true;
				$node['login'] = '';
			}
		}

		foreach ( self::LOGIN_KEYS as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && '' !== $node[ $key ] && ( $about || ( $loose && self::is_login( $node[ $key ], $lower ) ) ) ) {
				$found        = true;
				$node[ $key ] = '';
			}
		}

		if ( $about ) {
			foreach ( self::SLUG_KEYS as $key ) {
				if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && '' !== $node[ $key ] ) {
					$node[ $key ] = '';
				}
			}
		}

		if ( isset( $node['filters'] ) && is_array( $node['filters'] ) ) {
			foreach ( array( 'actor', 'acting_as' ) as $key ) {
				if ( ! isset( $node['filters'][ $key ] ) || ! is_scalar( $node['filters'][ $key ] ) ) {
					continue;
				}

				$value = (string) $node['filters'][ $key ];

				if ( ctype_digit( $value ) ) {
					$found = $found || (int) $value === $user_id;
				} elseif ( 'actor' === $key && self::is_login( $value, $lower ) ) {
					$found                   = true;
					$node['filters'][ $key ] = '';
				}
			}
		}

		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = self::walk( $value, $user_id, $lower, $about, $loose, $found, $depth + 1 );
			}
		}

		return $node;
	}

	/**
	 * The user ID a person entry was recorded with, or null when it has none.
	 *
	 * @param array $node Person entry.
	 * @return int|null
	 */
	private static function owner( array $node ) {
		foreach ( array( 'id', 'user_id' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_numeric( $node[ $key ] ) ) {
				return (int) $node[ $key ];
			}
		}

		return null;
	}

	/**
	 * The logins a row's details record for a person: person entries with
	 * their ID and, on a row about their account, the old and new usernames.
	 * Pure.
	 *
	 * @param array $details Decoded details.
	 * @param int   $user_id User ID.
	 * @param bool  $about   Whether the row is about their account.
	 * @param int   $depth   Nesting depth (internal).
	 * @return string[]
	 */
	public static function logins_in( array $details, $user_id, $about, $depth = 0 ) {
		$out = array();

		if ( $depth > self::MAX_DEPTH ) {
			return $out;
		}

		if ( isset( $details['login'] ) && is_string( $details['login'] ) && '' !== $details['login'] && self::owner( $details ) === (int) $user_id ) {
			$out[] = $details['login'];
		}

		if ( $about ) {
			foreach ( self::LOGIN_KEYS as $key ) {
				if ( isset( $details[ $key ] ) && is_string( $details[ $key ] ) && '' !== $details[ $key ] ) {
					$out[] = $details[ $key ];
				}
			}
		}

		foreach ( $details as $value ) {
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::logins_in( $value, $user_id, $about, $depth + 1 ) );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Text with each of a person's logins and names replaced, where it
	 * stands as a whole name (not inside another username such as
	 * `bob.smith` for `bob`). Longest first, in one pass, so the placeholder
	 * is never matched again. Case-sensitive: summaries hold names exactly
	 * as they were. Pure.
	 *
	 * @param string   $text        Text.
	 * @param string[] $identifiers Logins and names.
	 * @param string   $placeholder Replacement.
	 * @return string
	 */
	public static function scrub_text( $text, array $identifiers, $placeholder ) {
		$text = (string) $text;
		$list = array();

		foreach ( $identifiers as $identifier ) {
			if ( is_scalar( $identifier ) && '' !== trim( (string) $identifier ) ) {
				$list[ (string) $identifier ] = strlen( (string) $identifier );
			}
		}

		if ( '' === $text || array() === $list ) {
			return $text;
		}

		arsort( $list, SORT_NUMERIC );

		$alternatives = array();

		foreach ( array_keys( $list ) as $identifier ) {
			$alternatives[] = preg_quote( (string) $identifier, '/' );
		}

		$callback = static function () use ( $placeholder ) {
			return (string) $placeholder;
		};
		$pattern  = '(?:' . implode( '|', $alternatives ) . ')';
		$out      = preg_replace_callback( '/(?<![\p{L}\p{N}_@.\-])' . $pattern . '(?![\p{L}\p{N}_@\-]|\.[\p{L}\p{N}_@\-])/u', $callback, $text );

		// Text that is not valid UTF-8: the same boundaries, byte by byte.
		if ( null === $out ) {
			$out = preg_replace_callback( '/(?<![A-Za-z0-9_@.\-])' . $pattern . '(?![A-Za-z0-9_@\-]|\.[A-Za-z0-9_@\-])/', $callback, $text );
		}

		return null === $out ? $text : $out;
	}

	/**
	 * What the SQL search looks for in the details JSON: person entries and
	 * export filters with the person's ID, and each login as a JSON string
	 * (at most MAX_LOGINS). Matches are candidates for relations(). Pure.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $logins  The person's logins.
	 * @return string[]
	 */
	public static function needles( $user_id, array $logins ) {
		$user_id = (int) $user_id;
		$out     = array(
			'"id":' . $user_id . ',',
			'"user_id":' . $user_id . ',',
			'"actor":"' . $user_id . '"',
			'"acting_as":"' . $user_id . '"',
		);
		$seen    = array();

		foreach ( self::clean_logins( $logins ) as $login ) {
			$key = self::lc( $login );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = self::json_string( $login );
		}

		return $out;
	}

	/**
	 * Logins as non-empty strings, each once, in order, at most MAX_LOGINS.
	 * Pure.
	 *
	 * @param array $logins Candidates.
	 * @return string[]
	 */
	public static function clean_logins( array $logins ) {
		$out = array();

		foreach ( $logins as $login ) {
			if ( ! is_scalar( $login ) || is_bool( $login ) ) {
				continue;
			}

			$login = (string) $login;

			if ( '' !== trim( $login ) && ! in_array( $login, $out, true ) ) {
				$out[] = $login;
			}

			if ( count( $out ) >= self::MAX_LOGINS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Whether a row is about a person's account.
	 *
	 * @param array $row     Row.
	 * @param int   $user_id User ID.
	 * @return bool
	 */
	private static function is_about( array $row, $user_id ) {
		return (int) $user_id > 0 && self::object_user( $row ) === (int) $user_id;
	}

	/**
	 * The user a row is about (object type `user`), or 0.
	 *
	 * @param array $row Row.
	 * @return int
	 */
	private static function object_user( array $row ) {
		if ( ! isset( $row['object_type'], $row['object_id'] ) || 'user' !== (string) $row['object_type'] ) {
			return 0;
		}

		$id = (string) $row['object_id'];

		return '' !== $id && ctype_digit( $id ) ? (int) $id : 0;
	}

	/**
	 * Whether a row is a bulk role change on the Users screen (US-3).
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function is_bulk( array $row ) {
		return isset( $row['object_type'] ) && 'users' === (string) $row['object_type'];
	}

	/**
	 * Logins lowercased, as a set.
	 *
	 * @param string[] $logins Logins.
	 * @return array<string,bool>
	 */
	private static function lower_set( array $logins ) {
		$set = array();

		foreach ( self::clean_logins( $logins ) as $login ) {
			$set[ self::lc( $login ) ] = true;
		}

		return $set;
	}

	/**
	 * Whether a value is one of the logins.
	 *
	 * @param string             $value Value.
	 * @param array<string,bool> $lower lower_set().
	 * @return bool
	 */
	private static function is_login( $value, array $lower ) {
		return '' !== (string) $value && isset( $lower[ self::lc( (string) $value ) ] );
	}

	/**
	 * Lowercase, multibyte-aware when possible.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function lc( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * A string as the log's JSON stores it (BLT_Toolkit_Log::encode_details()
	 * flags), quotes included.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function json_string( $text ) {
		$json = json_encode( (string) $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Must match the stored encoding exactly.

		return is_string( $json ) ? $json : '""';
	}
}
