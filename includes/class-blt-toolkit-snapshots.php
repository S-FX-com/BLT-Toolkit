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
 * baseline taken on first activation (SNAP-2), keeps the newest of them
 * (SNAP-3), lists them with what differs from the roles today, and restores
 * one (SNAP-4).
 *
 * Each row holds `$wp_roles->roles` as canonical JSON with its SHA-256, so two
 * snapshots of the same roles are recognizably identical whatever order the
 * roles were built in, and a row whose JSON no longer matches its hash is
 * recognizably damaged. The Tools screen that shows all of this arrives in
 * Phase 6; nothing here renders anything.
 *
 * Decided policy for every BLT write to the roles option: when the snapshot
 * that must come first cannot be stored (take() returns 0), the write is
 * abandoned with a WP_Error. The one exception is SEC-14 recovery, which goes
 * ahead and records that the snapshot failed, because an administrator who is
 * locked out must get back in.
 */
class BLT_Toolkit_Snapshots {

	/**
	 * Snapshots kept besides the baseline when neither the setting nor the
	 * filter says otherwise (SNAP-3).
	 */
	const DEFAULT_RETENTION = 50;

	/**
	 * Rows per page for list() when the caller does not say.
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Most rows list() returns at once. Each row's roles are decoded and
	 * compared with the current roles, so a page stays small.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * The role that restore() never removes and always leaves holding the
	 * lockout-critical set (SEC-12).
	 */
	const ADMIN_ROLE = 'administrator';

	/**
	 * Store a snapshot of the current roles (SNAP-1), then prune (SNAP-3).
	 *
	 * When the roles hash to the same value as the newest snapshot, nothing is
	 * inserted and that snapshot's ID is returned, so repeated saves that
	 * change nothing do not fill the table.
	 *
	 * A caller about to write the roles option must stop when this returns 0
	 * (see the class description for the one exception, SEC-14 recovery).
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

		$id = self::insert( $reason, $roles, $hash, false );

		if ( $id > 0 ) {
			self::prune();
		}

		return $id;
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

		return self::shape_meta( $row );
	}

	// ---------- Retention (SNAP-3) ----------

	/**
	 * How many snapshots to keep besides the baseline: the Settings > Roles
	 * field (`roles.snapshot_retention`) through
	 * `blt_toolkit_snapshot_retention`, never below 1.
	 *
	 * At least one is always kept: take() compares with the newest snapshot,
	 * and a restore needs the copy it just took.
	 *
	 * @return int
	 */
	public static function retention() {
		$count = BLT_Toolkit_Settings::get( 'roles', 'snapshot_retention' );
		$count = is_numeric( $count ) ? (int) $count : self::DEFAULT_RETENTION;

		/**
		 * Filter how many role snapshots are kept, newest first, besides the
		 * baseline, which is never pruned (SNAP-2, SNAP-3).
		 *
		 * @param int $count The Settings > Roles field "Snapshots to keep" (default 50).
		 */
		$count = apply_filters( 'blt_toolkit_snapshot_retention', $count );

		return self::clamp_retention( $count );
	}

	/**
	 * A retention count as an integer of at least 1. Pure.
	 *
	 * @param mixed $count Filtered value.
	 * @return int
	 */
	public static function clamp_retention( $count ) {
		if ( ! is_numeric( $count ) ) {
			return self::DEFAULT_RETENTION;
		}

		return max( 1, (int) $count );
	}

	/**
	 * Delete every snapshot but the newest retention() ones and the baseline
	 * (SNAP-3). Runs after each insert by take().
	 *
	 * Two queries: find the oldest snapshot that is kept, then delete the
	 * non-baseline rows older than it.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'snapshots' );
		$keep  = self::retention();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; values are bound.
		$oldest_kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE is_baseline = 0 ORDER BY id DESC LIMIT 1 OFFSET %d", $keep - 1 ) );

		if ( $oldest_kept <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; values are bound.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE is_baseline = 0 AND id < %d", $oldest_kept ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	// ---------- Reading (SNAP-4) ----------

	/**
	 * One snapshot with its roles.
	 *
	 * `valid` is false when the stored JSON no longer hashes to the stored
	 * hash or does not decode to a roles array: such a snapshot can be looked
	 * at but never restored.
	 *
	 * @param int $id Snapshot ID.
	 * @return array{id:int,created_at:string,actor_id:int,reason:string,hash:string,is_baseline:bool,roles:array,valid:bool}|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id <= 0 ) {
			return null;
		}

		$table = BLT_Toolkit_Schema::table( 'snapshots' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; values are bound.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at, actor_id, reason, roles, hash, is_baseline FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$out   = self::shape_meta( $row );
		$roles = self::decode( (string) $row['roles'], $out['hash'] );

		$out['roles'] = null === $roles ? array() : $roles;
		$out['valid'] = null !== $roles;

		return $out;
	}

	/**
	 * How many snapshots are stored, the baseline included.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'snapshots' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * One page of snapshots, newest first, for the Tools screen (SNAP-4).
	 *
	 * Each row says when it was taken, by whom, why, and how many roles and
	 * capabilities differ from the roles today. Those counts are worked out
	 * only for the rows of the page asked for, one row at a time, and a row
	 * whose hash equals the current roles' hash is not decoded at all.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type int  $per_page Rows per page, 1 to 100 (default 20).
	 *     @type int  $paged    Page number, from 1 (default 1).
	 *     @type bool $counts   Whether to compute the differing counts (default true).
	 * }
	 * @return array[] Each: `id`, `created_at` (UTC `Y-m-d H:i:s`), `actor_id`,
	 *                 `actor` (display name, '' when no user or the user is
	 *                 gone), `reason`, `reason_label`, `is_baseline`, `hash`,
	 *                 `valid`, `is_current` (the roles today hash the same),
	 *                 and `counts` (array{roles:int,capabilities:int}, or null
	 *                 when not computed or the row is damaged).
	 */
	public static function list( array $args = array() ) {
		global $wpdb;

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
		$per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
		$paged    = isset( $args['paged'] ) ? max( 1, min( 1000000, (int) $args['paged'] ) ) : 1;
		$counts   = ! isset( $args['counts'] ) || (bool) $args['counts'];
		$table    = BLT_Toolkit_Schema::table( 'snapshots' );
		$columns  = $counts ? 'id, created_at, actor_id, reason, roles, hash, is_baseline' : 'id, created_at, actor_id, reason, hash, is_baseline';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the column list is one of two literals; values are bound.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, ( $paged - 1 ) * $per_page ), ARRAY_A );

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		$current      = self::current_roles();
		$current_hash = BLT_Toolkit_Role_Diff::hash( $current );
		$actor_ids    = array();

		foreach ( $rows as $row ) {
			if ( (int) $row['actor_id'] > 0 ) {
				$actor_ids[] = (int) $row['actor_id'];
			}
		}

		if ( array() !== $actor_ids && function_exists( 'cache_users' ) ) {
			cache_users( array_values( array_unique( $actor_ids ) ) );
		}

		$out = array();

		foreach ( $rows as $row ) {
			$item               = self::shape_meta( $row );
			$item['actor']      = self::actor_name( $item['actor_id'] );
			$item['is_current'] = hash_equals( $item['hash'], $current_hash );
			$item['valid']      = true;
			$item['counts']     = null;

			if ( $counts ) {
				if ( $item['is_current'] ) {
					$item['valid']  = hash_equals( $item['hash'], hash( 'sha256', (string) $row['roles'] ) );
					$item['counts'] = $item['valid'] ? array(
						'roles'        => 0,
						'capabilities' => 0,
					) : null;
				} else {
					$roles = self::decode( (string) $row['roles'], $item['hash'] );

					$item['valid']  = null !== $roles;
					$item['counts'] = null === $roles ? null : BLT_Toolkit_Role_Diff::counts( BLT_Toolkit_Role_Diff::diff( $current, $roles ) );
				}
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * A reason code as words, for the list.
	 *
	 * @param string $reason Reason code (SPEC 14.3).
	 * @return string
	 */
	public static function reason_label( $reason ) {
		switch ( (string) $reason ) {
			case 'baseline':
				return __( 'Baseline, before BLT Toolkit changed anything', 'blt-toolkit' );
			case 'before_save':
				return __( 'Before a roles change', 'blt-toolkit' );
			case 'before_reset':
				return __( 'Before a reset', 'blt-toolkit' );
			case 'before_restore':
				return __( 'Before a restore', 'blt-toolkit' );
			case 'before_import':
				return __( 'Before an import', 'blt-toolkit' );
			case 'before_isolation':
				return __( 'Before separate permissions were changed', 'blt-toolkit' );
			case 'recovery':
				return __( 'Before recovery mode', 'blt-toolkit' );
			case 'legacy_ure_backup':
				return __( 'User Role Editor backup', 'blt-toolkit' );
		}

		return (string) $reason;
	}

	// ---------- Restore (SNAP-4) ----------

	/**
	 * What restoring a snapshot would change: Role_Diff::diff() of the roles
	 * today against the roles restore() would write. Nothing is written.
	 *
	 * That is the snapshot's roles with the administrator safeguards of
	 * restorable_roles() applied, so the preview never shows a change that the
	 * restore would not make.
	 *
	 * @param int $id Snapshot ID.
	 * @return array|WP_Error The diff, or `blt_toolkit_snapshot_not_found` /
	 *                        `blt_toolkit_snapshot_damaged`.
	 */
	public static function restore_preview( $id ) {
		$snapshot = self::restorable( $id );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		return BLT_Toolkit_Role_Diff::diff( self::current_roles(), self::target_roles( $snapshot['roles'] ) );
	}

	/**
	 * Restore a snapshot (SNAP-4).
	 *
	 * 1. The snapshot must exist and be undamaged, the roles must live in the
	 *    database, and something must differ.
	 * 2. The Guard checks the change for every role it touches, as the
	 *    current user (SEC-2, SEC-3, SEC-5, SEC-12, SEC-13), so a restore can
	 *    never grant what its user could not grant by hand.
	 * 3. A `before_restore` snapshot of the roles today (SNAP-1). If it cannot
	 *    be stored, nothing is written.
	 * 4. One write of the roles option, in which `administrator` keeps the
	 *    lockout-critical set and, when the snapshot predates BLT Toolkit's
	 *    grants, gets BLT Toolkit's capabilities back (restorable_roles()).
	 * 5. A `roles` / `snapshot_restored` row in the activity log, with the
	 *    diff and the `before_restore` snapshot's ID.
	 *
	 * The caller checks that the user may restore at all (`blt_reset_roles`
	 * on the Tools screen) and shows restore_preview() first.
	 *
	 * @param int  $id        Snapshot ID.
	 * @param bool $confirmed Whether the user confirmed a SEC-13 warning (the
	 *                        restore takes away their own `blt_edit_roles`
	 *                        while another administrator could give it back).
	 * @return int|WP_Error The `before_restore` snapshot's ID, or a WP_Error:
	 *                      `blt_toolkit_snapshot_not_found`,
	 *                      `blt_toolkit_snapshot_damaged`,
	 *                      `blt_toolkit_roles_not_in_db`,
	 *                      `blt_toolkit_snapshot_unchanged`, one of the
	 *                      Guard's codes, `blt_toolkit_snapshot_failed` or
	 *                      `blt_toolkit_roles_write_failed`.
	 */
	public static function restore( $id, $confirmed = false ) {
		$snapshot = self::restorable( $id );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$wp_roles = wp_roles();

		if ( ! $wp_roles->use_db ) {
			return new WP_Error(
				'blt_toolkit_roles_not_in_db',
				__( 'This site defines its roles in code ($wp_user_roles), so they cannot be restored from here.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		$current = self::current_roles();
		$target  = self::target_roles( $snapshot['roles'] );
		$diff    = BLT_Toolkit_Role_Diff::diff( $current, $target );

		if ( BLT_Toolkit_Role_Diff::is_empty( $diff ) ) {
			return new WP_Error(
				'blt_toolkit_snapshot_unchanged',
				__( 'Your roles already match this snapshot, so nothing was changed.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		$allowed = self::guard_restore( wp_get_current_user(), $diff, $confirmed );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// SNAP-1, and the decided policy: no snapshot, no write.
		$before = self::take( 'before_restore' );

		if ( $before <= 0 ) {
			return new WP_Error(
				'blt_toolkit_snapshot_failed',
				__( 'A copy of your roles could not be saved first, so nothing was restored. Try again; if this keeps happening, the PHP error log says why.', 'blt-toolkit' ),
				array( 'status' => 500 )
			);
		}

		// One write for the whole restore (the RO-3 technique), then reload
		// the role objects from it. The diff is not empty, so update_option()
		// returning false means the database refused the write.
		if ( ! update_option( $wp_roles->role_key, $target ) ) {
			self::log_restore( $snapshot, $diff, $before, false );

			return new WP_Error(
				'blt_toolkit_roles_write_failed',
				__( 'The roles could not be saved, so nothing was restored. Your roles are as they were.', 'blt-toolkit' ),
				array( 'status' => 500 )
			);
		}

		$wp_roles->for_site();

		if ( is_callable( array( 'BLT_Toolkit_Guard', 'flush' ) ) ) {
			BLT_Toolkit_Guard::flush();
		}

		// Capabilities of the current user were worked out before the write.
		$user = wp_get_current_user();

		if ( $user->exists() ) {
			$user->get_role_caps();
		}

		self::log_restore( $snapshot, $diff, $before, true );

		return $before;
	}

	/**
	 * The roles a restore writes: the snapshot's, with `administrator` made
	 * safe. Pure.
	 *
	 * - `administrator` always exists: a snapshot without it keeps the role as
	 *   it is today (SEC-12: it can never be deleted).
	 * - It holds every lockout-critical capability, granted, whatever the
	 *   snapshot says (SEC-12).
	 * - When the snapshot's copy mentions none of BLT Toolkit's capabilities
	 *   (it was taken before BLT Toolkit first granted them: the baseline, or
	 *   any copy of that state), it gets them all, as activation gives them,
	 *   so restoring the baseline does not take BLT Toolkit away from the
	 *   people who manage it. A snapshot that mentions any of them was taken
	 *   while BLT Toolkit was in use, so it is restored as it is (SNAP-4): a
	 *   capability the owner had removed or denied stays that way, unless it
	 *   is lockout-critical.
	 *
	 * @param array    $snapshot Roles array from a snapshot.
	 * @param array    $current  Roles array today.
	 * @param string[] $lockout  The lockout-critical set (SEC-12).
	 * @param string[] $own      BLT Toolkit's capabilities (SPEC 5.1).
	 * @return array
	 */
	public static function restorable_roles( array $snapshot, array $current, array $lockout, array $own ) {
		$admin = self::ADMIN_ROLE;

		if ( ! isset( $snapshot[ $admin ] ) || ! is_array( $snapshot[ $admin ] ) ) {
			$snapshot[ $admin ] = isset( $current[ $admin ] ) && is_array( $current[ $admin ] )
				? $current[ $admin ]
				: array(
					'name'         => 'Administrator',
					'capabilities' => array(),
				);
		}

		$role = $snapshot[ $admin ];

		if ( ! isset( $role['name'] ) || ! is_scalar( $role['name'] ) || '' === (string) $role['name'] ) {
			$role['name'] = 'Administrator';
		}

		$caps    = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
		$own     = array_map( 'strval', $own );
		$granted = array_intersect( $own, array_map( 'strval', array_keys( $caps ) ) );

		// Only a copy from before BLT Toolkit's first grant gets them back.
		if ( array() === $granted ) {
			foreach ( $own as $cap ) {
				$caps[ $cap ] = true;
			}
		}

		foreach ( $lockout as $cap ) {
			$caps[ (string) $cap ] = true;
		}

		$role['capabilities'] = $caps;
		$snapshot[ $admin ]   = $role;

		return $snapshot;
	}

	/**
	 * Each role's part of a diff as the adds and removes the Guard checks.
	 * Pure.
	 *
	 * A role the restore creates grants its capabilities; a role it deletes
	 * removes all of them; a changed role adds, flips (an add with the new
	 * value) and removes.
	 *
	 * @param array $diff Result of BLT_Toolkit_Role_Diff::diff().
	 * @return array<string,array{adds:array<string,bool>,removes:string[]}>
	 */
	public static function guard_changes( array $diff ) {
		$out = array();

		foreach ( isset( $diff['added'] ) ? (array) $diff['added'] : array() as $role => $data ) {
			$out[ (string) $role ] = array(
				'adds'    => isset( $data['capabilities'] ) ? (array) $data['capabilities'] : array(),
				'removes' => array(),
			);
		}

		foreach ( isset( $diff['removed'] ) ? (array) $diff['removed'] : array() as $role => $data ) {
			$out[ (string) $role ] = array(
				'adds'    => array(),
				'removes' => array_map( 'strval', array_keys( isset( $data['capabilities'] ) ? (array) $data['capabilities'] : array() ) ),
			);
		}

		foreach ( isset( $diff['changed'] ) ? (array) $diff['changed'] : array() as $role => $change ) {
			$adds = isset( $change['added'] ) ? (array) $change['added'] : array();

			foreach ( isset( $change['flipped'] ) ? (array) $change['flipped'] : array() as $cap => $flip ) {
				$adds[ $cap ] = ! empty( $flip['to'] );
			}

			$out[ (string) $role ] = array(
				'adds'    => $adds,
				'removes' => array_map( 'strval', array_keys( isset( $change['removed'] ) ? (array) $change['removed'] : array() ) ),
			);
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Run the Guard on every role a restore touches.
	 *
	 * @param WP_User $actor     Current user.
	 * @param array   $diff      Diff of today against the roles to write.
	 * @param bool    $confirmed SEC-13 confirmation.
	 * @return true|WP_Error The first refusal.
	 */
	private static function guard_restore( WP_User $actor, array $diff, $confirmed ) {
		foreach ( self::guard_changes( $diff ) as $role => $change ) {
			$check = BLT_Toolkit_Guard::check_role_change( $actor, $role, $change['adds'], $change['removes'], (bool) $confirmed );

			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}

		return true;
	}

	/**
	 * A snapshot that exists and can be restored, or why not.
	 *
	 * @param int $id Snapshot ID.
	 * @return array|WP_Error
	 */
	private static function restorable( $id ) {
		$snapshot = self::get( $id );

		if ( null === $snapshot ) {
			return new WP_Error(
				'blt_toolkit_snapshot_not_found',
				__( 'That snapshot does not exist. It may have been removed to keep only the newest ones.', 'blt-toolkit' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $snapshot['valid'] ) {
			return new WP_Error(
				'blt_toolkit_snapshot_damaged',
				__( 'That snapshot is damaged: its contents no longer match the checksum stored with it, so it cannot be restored.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		return $snapshot;
	}

	/**
	 * The roles restore() writes for a snapshot's roles.
	 *
	 * @param array $roles Snapshot roles.
	 * @return array
	 */
	private static function target_roles( array $roles ) {
		return self::restorable_roles(
			$roles,
			self::current_roles(),
			BLT_Toolkit_Caps::lockout_critical(),
			array_keys( BLT_Toolkit_Caps::own() )
		);
	}

	/**
	 * Record a restore, or a failed one, in the activity log (LOG-1).
	 *
	 * @param array $snapshot The restored snapshot.
	 * @param array $diff     What changed (or would have).
	 * @param int   $before   The `before_restore` snapshot's ID.
	 * @param bool  $ok       Whether the write succeeded.
	 * @return void
	 */
	private static function log_restore( array $snapshot, array $diff, $before, $ok ) {
		if ( ! is_callable( array( 'BLT_Toolkit_Log', 'record' ) ) ) {
			return;
		}

		$when = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $snapshot['created_at'], 'Y-m-d H:i' ) : $snapshot['created_at'];

		$summary = $ok
			? sprintf(
				/* translators: 1: snapshot number, 2: date and time it was taken. */
				__( 'Restored the roles saved in snapshot %1$d (%2$s).', 'blt-toolkit' ),
				$snapshot['id'],
				$when
			)
			: sprintf(
				/* translators: 1: snapshot number, 2: date and time it was taken. */
				__( 'Could not restore the roles saved in snapshot %1$d (%2$s); the roles were not changed.', 'blt-toolkit' ),
				$snapshot['id'],
				$when
			);

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'snapshot_restored',
				'object_type' => 'snapshot',
				'object_id'   => (string) $snapshot['id'],
				'summary'     => $summary,
				'details'     => array(
					'restored_snapshot' => array(
						'id'         => $snapshot['id'],
						'created_at' => $snapshot['created_at'],
						'reason'     => $snapshot['reason'],
					),
					'counts'            => BLT_Toolkit_Role_Diff::counts( $diff ),
					'diff'              => $diff,
				),
				'snapshot_id' => (int) $before,
				'status'      => $ok ? 'success' : 'failure',
				'source'      => '',
			)
		);
	}

	// ---------- Helpers ----------

	/**
	 * Decode a row's roles JSON, checking it against its hash.
	 *
	 * The stored JSON is the canonical form, so its own SHA-256 is the hash
	 * take() stored; anything else means the row was changed afterwards.
	 *
	 * @param string $json Stored JSON.
	 * @param string $hash Stored hash.
	 * @return array|null Roles, or null when damaged.
	 */
	private static function decode( $json, $hash ) {
		if ( ! hash_equals( (string) $hash, hash( 'sha256', (string) $json ) ) ) {
			return null;
		}

		$roles = json_decode( (string) $json, true );

		return is_array( $roles ) ? $roles : null;
	}

	/**
	 * Typed metadata of a row.
	 *
	 * @param array $row Database row.
	 * @return array{id:int,created_at:string,actor_id:int,reason:string,reason_label:string,hash:string,is_baseline:bool}
	 */
	private static function shape_meta( array $row ) {
		$reason = isset( $row['reason'] ) ? (string) $row['reason'] : '';

		return array(
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'created_at'   => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'actor_id'     => isset( $row['actor_id'] ) ? (int) $row['actor_id'] : 0,
			'reason'       => $reason,
			'reason_label' => self::reason_label( $reason ),
			'hash'         => isset( $row['hash'] ) ? (string) $row['hash'] : '',
			'is_baseline'  => ! empty( $row['is_baseline'] ),
		);
	}

	/**
	 * A user's display name for the list, or '' when there is none.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function actor_name( $user_id ) {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return '';
		}

		return '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;
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
