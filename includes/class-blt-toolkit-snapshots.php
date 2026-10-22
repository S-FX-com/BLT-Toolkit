<?php
/**
 * Role snapshots.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the complete roles array before it is written (SNAP-1), plus the
 * baseline taken on first activation (SNAP-2).
 *
 * Each row holds `$wp_roles->roles` as canonical JSON with its SHA-256, so two
 * snapshots of the same roles are recognizably identical whatever order the
 * roles were built in. Listing, restore and pruning arrive in Phases 1 and 6.
 */
class BLT_Toolkit_Snapshots {

	/**
	 * Store a snapshot of the current roles.
	 *
	 * When the roles hash to the same value as the newest snapshot, nothing is
	 * inserted and that snapshot's ID is returned, so repeated saves that
	 * change nothing do not fill the table.
	 *
	 * @param string $reason Why it was taken (SPEC 14.3 lists the values).
	 * @return int Snapshot ID, or 0 when it could not be stored.
	 */
	public static function take( $reason ) {
		$roles  = self::current_roles();
		$hash   = BLT_Toolkit_Role_Diff::hash( $roles );
		$newest = self::newest();

		if ( null !== $newest && hash_equals( $newest['hash'], $hash ) ) {
			return (int) $newest['id'];
		}

		return self::insert( $reason, $roles, $hash, false );
	}

	/**
	 * Store the baseline snapshot (SNAP-2), only if none exists yet.
	 *
	 * Taken on first activation, before the plugin grants its own
	 * capabilities, so it records the roles exactly as they were before BLT
	 * Toolkit touched anything. It is never pruned.
	 *
	 * Two activations at once (a double-clicked link, WP-CLI and the browser
	 * together) can both find no baseline and both insert one, and the table
	 * has no unique key to stop them. So after inserting, the oldest baseline
	 * is kept and any later one deleted. The oldest is always a true "before"
	 * copy: every activation stores its baseline before it grants anything,
	 * so a copy read after some grant was inserted after that activation's
	 * own, older, baseline.
	 *
	 * @return int The baseline's ID (existing or new), or 0 when it could not be stored.
	 */
	public static function baseline() {
		global $wpdb;

		$table    = BLT_Toolkit_Schema::table( 'snapshots' );
		$existing = self::baseline_id();

		if ( $existing > 0 ) {
			return $existing;
		}

		$roles = self::current_roles();
		$id    = self::insert( 'baseline', $roles, BLT_Toolkit_Role_Diff::hash( $roles ), true );

		if ( 0 === $id ) {
			return 0;
		}

		$first = self::baseline_id();

		if ( $first > 0 && $first < $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; runs on activation only.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE is_baseline = 1 AND id > %d", $first ) );

			return $first;
		}

		return $id;
	}

	/**
	 * The oldest baseline's ID, or 0 when there is none.
	 *
	 * @return int
	 */
	private static function baseline_id() {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'snapshots' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; runs on activation only.
		return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE is_baseline = 1 ORDER BY id ASC LIMIT 1" );
	}

	/**
	 * The newest snapshot's metadata, without the roles JSON.
	 *
	 * @return array{id:int,created_at:string,actor_id:int,reason:string,hash:string,is_baseline:bool}|null
	 */
	public static function newest() {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'snapshots' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; read just before a roles write.
		$row = $wpdb->get_row( "SELECT id, created_at, actor_id, reason, hash, is_baseline FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'          => (int) $row['id'],
			'created_at'  => (string) $row['created_at'],
			'actor_id'    => (int) $row['actor_id'],
			'reason'      => (string) $row['reason'],
			'hash'        => (string) $row['hash'],
			'is_baseline' => (bool) $row['is_baseline'],
		);
	}

	/**
	 * The roles array as WordPress holds it right now.
	 *
	 * @return array
	 */
	private static function current_roles() {
		$roles = wp_roles()->roles;

		return is_array( $roles ) ? $roles : array();
	}

	/**
	 * Insert one row.
	 *
	 * @param string $reason      Reason.
	 * @param array  $roles       Roles array.
	 * @param string $hash        SHA-256 of the canonical JSON.
	 * @param bool   $is_baseline Whether this is the baseline.
	 * @return int New ID, or 0 on failure.
	 */
	private static function insert( $reason, array $roles, $hash, $is_baseline ) {
		global $wpdb;

		$reason = substr( sanitize_key( (string) $reason ), 0, 40 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table.
		$ok = $wpdb->insert(
			BLT_Toolkit_Schema::table( 'snapshots' ),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'actor_id'    => get_current_user_id(),
				'reason'      => '' !== $reason ? $reason : 'before_save',
				'roles'       => BLT_Toolkit_Role_Diff::canonicalize( $roles ),
				'hash'        => $hash,
				'is_baseline' => $is_baseline ? 1 : 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		if ( false === $ok ) {
			error_log( 'BLT Toolkit: could not store a role snapshot: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed snapshot must be visible to the site owner's logs.
			return 0;
		}

		return (int) $wpdb->insert_id;
	}
}
