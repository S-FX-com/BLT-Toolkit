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
 * (SNAP-3; the baseline and User Role Editor's imported backup are never
 * pruned), lists them with what differs from the roles today, and restores
 * one (SNAP-4).
 *
 * Each row holds `$wp_roles->roles` as canonical JSON with its SHA-256, so two
 * snapshots of the same roles are recognizably identical whatever order the
 * roles were built in, and a row whose JSON no longer matches its hash is
 * recognizably damaged. Tools > Snapshots (BLT_Toolkit_Admin_Tools) shows
 * all of this; nothing here renders anything. A restore writes through
 * BLT_Toolkit_Role_Store::transaction(), like every BLT roles write, and
 * review() is the dry run the Tools previews share (restore, reset, sync).
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
	 * Reasons whose snapshots SNAP-3 never prunes, like the baseline: User
	 * Role Editor's first-activation backup (SPEC 15) cannot be made again
	 * once User Role Editor is deleted. Decided in the Phase 7 backlog
	 * (tasks/lessons.md). They do not count toward the newest ones kept.
	 */
	const KEPT_REASONS = array( 'legacy_ure_backup' );

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
	 * Store a snapshot of roles other than the current ones, such as User
	 * Role Editor's first-activation backup (reason `legacy_ure_backup`,
	 * SPEC 15), then prune (SNAP-3). Writes no role.
	 *
	 * Unlike take(), it does not compare with the newest snapshot: the
	 * caller decides whether this content is stored already.
	 *
	 * @param string $reason Why it was stored (SPEC 14.3 lists the values).
	 * @param array  $roles  A roles array in the shape of `$wp_roles->roles`.
	 * @return int Snapshot ID, or 0 when it could not be stored.
	 */
	public static function store( $reason, array $roles ) {
		$id = self::insert( $reason, $roles, BLT_Toolkit_Role_Diff::hash( $roles ), false );

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
	 * How many snapshots to keep besides the baseline and User Role Editor's
	 * backup (KEPT_REASONS): the Settings > Roles field
	 * (`roles.snapshot_retention`) through `blt_toolkit_snapshot_retention`,
	 * never below 1.
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
		 * baseline and User Role Editor's backup, which are never pruned
		 * (SNAP-2, SNAP-3).
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
	 * Delete every snapshot but the newest retention() ones, the baseline and
	 * those kept for their reason (KEPT_REASONS) (SNAP-3). Runs after each
	 * insert by take() and store().
	 *
	 * Two queries: the IDs, reasons and baseline flags of every row (the
	 * table holds about retention() rows, since every insert prunes), then
	 * one delete of the rows prunable() picks. The delete repeats the
	 * baseline and reason conditions, so a kept row is never removed.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'snapshots' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; no values.
		$rows = $wpdb->get_results( "SELECT id, reason, is_baseline FROM {$table}", ARRAY_A );
		$ids  = self::prunable( is_array( $rows ) ? $rows : array(), self::retention() );

		if ( array() === $ids ) {
			return 0;
		}

		$id_list     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$reason_list = implode( ', ', array_fill( 0, count( self::KEPT_REASONS ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Our own table; the placeholder lists are built from the counts of the values bound.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE is_baseline = 0 AND reason NOT IN ({$reason_list}) AND id IN ({$id_list})", array_merge( self::KEPT_REASONS, $ids ) ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * The snapshot IDs SNAP-3 prunes: every row but the baseline, the rows
	 * kept for their reason (KEPT_REASONS) and the newest `$keep` of the
	 * rest. Pure.
	 *
	 * @param array[] $rows Each with `id`, `reason` and `is_baseline`, in any order.
	 * @param int     $keep How many of the other rows to keep (at least 1).
	 * @return int[] IDs to delete, ascending.
	 */
	public static function prunable( array $rows, $keep ) {
		$keep  = max( 1, (int) $keep );
		$other = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || (int) $row['id'] <= 0 ) {
				continue;
			}

			if ( ! self::is_kept( $row ) ) {
				$other[] = (int) $row['id'];
			}
		}

		rsort( $other );

		$ids = array_slice( $other, $keep );

		sort( $ids );

		return $ids;
	}

	/**
	 * Whether SNAP-3 never prunes a snapshot: the baseline, and the reasons
	 * in KEPT_REASONS. Pure.
	 *
	 * @param array $snapshot With `is_baseline` and `reason`.
	 * @return bool
	 */
	public static function is_kept( array $snapshot ) {
		return ! empty( $snapshot['is_baseline'] ) || ( isset( $snapshot['reason'] ) && in_array( (string) $snapshot['reason'], self::KEPT_REASONS, true ) );
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
	 * Everything the Tools screen shows before a restore (SNAP-4), worked out
	 * by a dry run through the role store, so the diff, the Guard's verdict
	 * and the SEC-10 list are exactly those of the write. Nothing is written
	 * (RO-5).
	 *
	 * @param int   $id   Snapshot ID.
	 * @param array $args Optional: `actor` (WP_User; default the current user),
	 *                    `count` (false skips counting the people who hold
	 *                    the roles the restore deletes, as the confirm does).
	 * @return array|WP_Error array(
	 *     'snapshot'  => the snapshot's metadata (no roles),
	 *     'diff'      => what the restore changes (today against the roles written),
	 *     'counts'    => Role_Diff::counts() of that diff,
	 *     'additions' => restore_additions(): what Administrator gets beyond the snapshot,
	 *     'expected'  => hash of the roles the plan was made from (the confirm form carries it),
	 *     'unchanged' => nothing would change,
	 *     'in_code'   => the roles live in code and cannot be restored,
	 *     'critical'  => role => Critical capabilities granted (SEC-10),
	 *     'names'     => role => array( 'label', 'accept' ) for the typed confirmation,
	 *     'lockout'   => the SEC-13 confirmation is needed,
	 *     'guard'     => true or the Guard's refusal,
	 *     'isolated'  => isolation_notes(): content types seeded, and those nobody could edit after,
	 *     'removed', 'no_role', 'default_role', 'extra_default' => removal_impact() (RO-43, SNAP-4),
	 * ), or `blt_toolkit_snapshot_not_found` / `blt_toolkit_snapshot_damaged`.
	 */
	public static function restore_plan( $id, array $args = array() ) {
		$snapshot = self::restorable( $id );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		$current = self::current_roles();
		$maps    = self::isolation_maps();
		$target  = self::target_roles( $snapshot['roles'], $maps );
		$plan    = array(
			'snapshot'      => self::meta_only( $snapshot ),
			'diff'          => BLT_Toolkit_Role_Diff::diff( $current, $target ),
			'counts'        => array(
				'roles'        => 0,
				'capabilities' => 0,
			),
			'additions'     => self::restore_additions( $snapshot['roles'], $current, $target, BLT_Toolkit_Caps::lockout_critical(), array_keys( BLT_Toolkit_Caps::own() ), $maps ),
			'isolated'      => self::isolation_notes( self::seed_isolated( $snapshot['roles'], $maps )['seeded'], $target, $maps ),
			'removed'       => array(),
			'no_role'       => null,
			'default_role'  => '',
			'extra_default' => array(),
			'expected'      => BLT_Toolkit_Role_Diff::hash( $current ),
			'unchanged'     => false,
			'in_code'       => ! wp_roles()->use_db,
			'critical'      => array(),
			'names'         => array(),
			'lockout'       => false,
			'guard'         => true,
		);

		if ( $plan['in_code'] ) {
			$plan['counts']    = BLT_Toolkit_Role_Diff::counts( $plan['diff'] );
			$plan['unchanged'] = BLT_Toolkit_Role_Diff::is_empty( $plan['diff'] );

			return $plan;
		}

		$seen      = '';
		$additions = $plan['additions'];
		$review    = self::review(
			self::restore_mutator( $snapshot, '', $seen, $additions, $maps ),
			array(
				'reason'  => 'before_restore',
				'context' => 'restore',
				'actor'   => isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user(),
			)
		);

		if ( is_wp_error( $review ) ) {
			return $review;
		}

		$plan['diff']      = $review['diff'];
		$plan['counts']    = BLT_Toolkit_Role_Diff::counts( $review['diff'] );
		$plan['unchanged'] = array() === $review['changes'];
		$plan['additions'] = $additions;
		$plan['expected']  = '' !== $seen ? $seen : $plan['expected'];
		$plan['critical']  = $review['critical'];
		$plan['names']     = $review['names'];
		$plan['lockout']   = $review['lockout'];
		$plan['guard']     = $review['guard'];

		// RO-43's user impact, shared with the reset (SNAP-4): who holds the
		// roles the restore deletes, and who is left with none. Counted for
		// the preview only (`count` false on the confirm).
		$impact = self::removal_impact( $plan['diff'], array_keys( $target ), ! isset( $args['count'] ) || ! empty( $args['count'] ) );

		$plan['removed']       = $impact['removed'];
		$plan['no_role']       = $impact['no_role'];
		$plan['default_role']  = $impact['default_role'];
		$plan['extra_default'] = $impact['extra_default'];

		return $plan;
	}

	/**
	 * Restore a snapshot (SNAP-4), through the role store
	 * (BLT_Toolkit_Role_Store::transaction(), context `restore`).
	 *
	 * 1. The snapshot must exist and be undamaged, and the roles must live in
	 *    the database.
	 * 2. The store reads the roles again; when the caller passes `expected`
	 *    (the hash restore_plan() showed), roles that changed since the
	 *    preview refuse the restore (`blt_toolkit_roles_changed`), so what
	 *    is written is what was previewed.
	 * 3. The roles to write are the snapshot's, in today's role order, with
	 *    `administrator` made safe (restorable_roles(): the SEC-12 lockout
	 *    set, and BLT Toolkit's capabilities when the snapshot predates
	 *    them).
	 * 4. The Guard checks every role the restore touches as the person
	 *    acting (SEC-1, SEC-2, SEC-3, SEC-5, SEC-12, SEC-13), and SEC-10's
	 *    Critical grants need `confirmed_critical`, so a restore never grants
	 *    what its user could not grant by hand.
	 * 5. A `before_restore` snapshot of the roles today (SNAP-1; none, no
	 *    write), `blt_toolkit_before_roles_save`, one write of the roles
	 *    option, a `roles` / `snapshot_restored` log row with the diff and the
	 *    `before_restore` snapshot's ID, `blt_toolkit_after_roles_save`.
	 *
	 * The caller checks that the user may restore at all (`blt_reset_roles`
	 * on the Tools screen) and shows restore_plan() first.
	 *
	 * @param int   $id        Snapshot ID.
	 * @param bool  $confirmed Whether the user confirmed a SEC-13 warning (the
	 *                         restore takes away their own `blt_edit_roles`
	 *                         while another administrator could give it back).
	 * @param array $args      Optional: `confirmed_critical` (SEC-10 typed
	 *                         confirmation given), `expected` (roles hash from
	 *                         the preview), `actor` (WP_User).
	 * @return int|WP_Error The `before_restore` snapshot's ID, or a WP_Error:
	 *                      `blt_toolkit_snapshot_not_found`,
	 *                      `blt_toolkit_snapshot_damaged`,
	 *                      `blt_toolkit_roles_not_in_db`,
	 *                      `blt_toolkit_roles_changed`,
	 *                      `blt_toolkit_snapshot_unchanged`, one of the
	 *                      Guard's codes, `blt_toolkit_critical_review`,
	 *                      `blt_toolkit_snapshot_failed` or
	 *                      `blt_toolkit_roles_write_failed`.
	 */
	public static function restore( $id, $confirmed = false, array $args = array() ) {
		$snapshot = self::restorable( $id );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		if ( ! wp_roles()->use_db ) {
			return new WP_Error(
				'blt_toolkit_roles_not_in_db',
				__( 'This site defines its roles in code ($wp_user_roles), so they cannot be restored from here.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		$expected  = isset( $args['expected'] ) && is_string( $args['expected'] ) ? strtolower( trim( $args['expected'] ) ) : '';
		$seen      = '';
		$additions = array();
		$meta      = self::meta_only( $snapshot );
		$result    = BLT_Toolkit_Role_Store::transaction(
			self::restore_mutator( $snapshot, $expected, $seen, $additions, self::isolation_maps() ),
			array(
				'reason'             => 'before_restore',
				'context'            => 'restore',
				'module'             => 'roles',
				'action'             => 'snapshot_restored',
				'object_type'        => 'snapshot',
				'object_id'          => (string) $snapshot['id'],
				'summary'            => self::restored_summary( $meta ),
				'describe'           => static function ( array $diff ) use ( $meta, &$additions ) {
					$details = array(
						'restored_snapshot' => array(
							'id'         => $meta['id'],
							'created_at' => $meta['created_at'],
							'reason'     => $meta['reason'],
						),
						'counts'            => BLT_Toolkit_Role_Diff::counts( $diff ),
					);

					// What Administrator got beyond the snapshot (SEC-12), when anything.
					$extra = array_filter(
						array(
							'lockout_set'          => isset( $additions['lockout'] ) ? $additions['lockout'] : array(),
							'toolkit_capabilities' => isset( $additions['blt'] ) ? $additions['blt'] : array(),
							'separate_permissions' => isset( $additions['isolated'] ) ? $additions['isolated'] : array(),
							'other'                => isset( $additions['other'] ) ? $additions['other'] : array(),
						)
					);

					if ( array() !== $extra ) {
						$details['administrator_additions'] = $extra;
					}

					return array( 'details' => $details );
				},
				'confirmed_lockout'  => (bool) $confirmed,
				'confirmed_critical' => ! empty( $args['confirmed_critical'] ),
				'actor'              => isset( $args['actor'] ) && $args['actor'] instanceof WP_User ? $args['actor'] : wp_get_current_user(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( array() === $result['changes'] ) {
			return new WP_Error(
				'blt_toolkit_snapshot_unchanged',
				__( 'Your roles already match this snapshot, so nothing was changed.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		return (int) $result['snapshot_id'];
	}

	/**
	 * A dry run of a roles write through the role store, split the way a
	 * review page needs it. Shared by restore, reset (BLT_Toolkit_Reset) and
	 * Sync administrator (BLT_Toolkit_Sync). Nothing is written.
	 *
	 * The store's dry run returns one verdict, and a SEC-13 refusal hides
	 * whatever the Guard would say after it. So the first pass takes SEC-10
	 * as confirmed and SEC-13 as not; when SEC-13 asks for a confirmation,
	 * a second pass takes it as given and reports what remains. SEC-10's
	 * list comes from the store either way.
	 *
	 * @param callable $mutate Role-store mutator.
	 * @param array    $args   BLT_Toolkit_Role_Store::transaction() arguments.
	 * @return array|WP_Error array( 'changes', 'diff', 'critical' (role => caps),
	 *                        'names' (role => array( 'label', 'accept' )),
	 *                        'lockout' (bool), 'guard' (true or WP_Error) ),
	 *                        or the mutator's or the store's WP_Error.
	 */
	public static function review( callable $mutate, array $args = array() ) {
		$args = array_merge(
			$args,
			array(
				'dry_run'            => true,
				'confirmed_critical' => true,
				'confirmed_lockout'  => false,
			)
		);
		$dry  = BLT_Toolkit_Role_Store::transaction( $mutate, $args );

		if ( is_wp_error( $dry ) ) {
			return $dry;
		}

		$out = array(
			'changes'  => $dry['changes'],
			'diff'     => $dry['diff'],
			'critical' => isset( $dry['critical'] ) ? (array) $dry['critical'] : array(),
			'names'    => array(),
			'lockout'  => false,
			'guard'    => isset( $dry['guard'] ) ? $dry['guard'] : true,
		);

		if ( is_wp_error( $out['guard'] ) && 'blt_toolkit_self_lockout' === $out['guard']->get_error_code() ) {
			$data = $out['guard']->get_error_data();

			if ( is_array( $data ) && ! empty( $data['confirmable'] ) ) {
				$again = BLT_Toolkit_Role_Store::transaction( $mutate, array_merge( $args, array( 'confirmed_lockout' => true ) ) );

				if ( is_wp_error( $again ) ) {
					return $again;
				}

				$out['lockout']  = true;
				$out['guard']    = isset( $again['guard'] ) ? $again['guard'] : true;
				$out['critical'] = isset( $again['critical'] ) ? (array) $again['critical'] : $out['critical'];
			}
		}

		foreach ( array_keys( $out['critical'] ) as $role ) {
			$stored = self::after_name( (string) $role, $out['diff'], self::current_roles() );
			$label  = function_exists( 'translate_user_role' ) ? translate_user_role( $stored ) : $stored;

			$out['names'][ (string) $role ] = array(
				'label'  => $label,
				'accept' => array_values( array_unique( array( $label, $stored ) ) ),
			);
		}

		return $out;
	}

	/**
	 * Whether every role in a SEC-10 review got its display name typed. Pure.
	 *
	 * The comparison ignores case and surrounding spaces, as the Roles
	 * screen's does; either the translated or the stored name is accepted.
	 *
	 * @param array $names review()'s `names`: role => array( 'label', 'accept' ).
	 * @param mixed $typed Posted values: role => typed text.
	 * @return bool True when there is nothing to confirm, or all of it matches.
	 */
	public static function names_confirmed( array $names, $typed ) {
		$typed = is_array( $typed ) ? $typed : array();

		foreach ( $names as $role => $name ) {
			$value = isset( $typed[ $role ] ) && is_scalar( $typed[ $role ] ) ? self::fold( (string) $typed[ $role ] ) : '';
			$match = false;

			if ( '' !== $value ) {
				foreach ( isset( $name['accept'] ) ? (array) $name['accept'] : array() as $accept ) {
					if ( self::fold( (string) $accept ) === $value ) {
						$match = true;
						break;
					}
				}
			}

			if ( ! $match ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The roles restore() writes for a snapshot's roles: restorable_roles(),
	 * in the order of today's roles (roles that are new come last). Pure.
	 *
	 * Role order is what core's role menus follow; the snapshot stores roles
	 * sorted by ID, so writing them as stored would reorder every menu.
	 *
	 * @param array $target  restorable_roles() result.
	 * @param array $current Roles array today.
	 * @return array
	 */
	public static function in_order( array $target, array $current ) {
		$out = array();

		foreach ( array_keys( $current ) as $id ) {
			if ( array_key_exists( $id, $target ) ) {
				$out[ $id ] = $target[ $id ];
			}
		}

		foreach ( $target as $id => $role ) {
			if ( ! array_key_exists( $id, $out ) ) {
				$out[ $id ] = $role;
			}
		}

		return $out;
	}

	/**
	 * The new capability strings of a seed map, as a set. Pure.
	 *
	 * @param array $map BLT_Toolkit_Isolation::seed_map().
	 * @return array<string,true>
	 */
	public static function map_strings( array $map ) {
		$out = array();

		foreach ( $map as $news ) {
			foreach ( (array) $news as $new ) {
				$out[ (string) $new ] = true;
			}
		}

		return $out;
	}

	/**
	 * Give roles the strings of content types with separate permissions as
	 * RS-30 does: every Posts (or Pages) capability a role holds gives the
	 * type's matching string with the same value, and `edit_posts` gives
	 * `create_{plural}` too (BLT_Toolkit_Isolation::seed_changes(), adds
	 * only; a string the role mentions already is left as it is). Pure.
	 *
	 * - `$only` null (a restore): a type is seeded only when no role
	 *   mentions any of its strings, which means the roles predate its
	 *   separate permissions; every role then gets them.
	 * - `$only` a list of role IDs (a reset): those roles are seeded,
	 *   whatever the others hold, because they are WordPress's defaults,
	 *   which know nothing of the type.
	 *
	 * @param array      $roles Roles array.
	 * @param array      $maps  isolation_maps(): type => array( 'label', 'map', 'edit' ).
	 * @param array|null $only  Role IDs to seed, or null.
	 * @return array{roles:array,seeded:string[]} `seeded`: the types seeded, sorted.
	 */
	public static function seed_isolated( array $roles, array $maps, $only = null ) {
		$seeded = array();

		foreach ( $maps as $type => $info ) {
			$map     = isset( $info['map'] ) && is_array( $info['map'] ) ? $info['map'] : array();
			$strings = self::map_strings( $map );

			if ( array() === $strings ) {
				continue;
			}

			if ( null === $only ) {
				foreach ( $roles as $role ) {
					$caps = is_array( $role ) && isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();

					if ( array() !== array_intersect_key( $caps, $strings ) ) {
						continue 2;
					}
				}
			}

			$changed = false;

			foreach ( $roles as $id => $role ) {
				if ( ! is_array( $role ) || ( null !== $only && ! in_array( (string) $id, array_map( 'strval', (array) $only ), true ) ) ) {
					continue;
				}

				$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();

				foreach ( BLT_Toolkit_Isolation::seed_changes( $caps, $map )['add'] as $cap => $value ) {
					if ( ! array_key_exists( $cap, $caps ) ) {
						$caps[ $cap ] = (bool) $value;
						$changed      = true;
					}
				}

				$roles[ $id ]['capabilities'] = $caps;
			}

			if ( $changed ) {
				$seeded[] = (string) $type;
			}
		}

		sort( $seeded, SORT_STRING );

		return array(
			'roles'  => $roles,
			'seeded' => $seeded,
		);
	}

	/**
	 * Content types with separate permissions that no role would let anyone
	 * edit: no role in `$roles` grants the type's `edit_{plural}`. Pure.
	 *
	 * @param array $roles Roles array.
	 * @param array $maps  isolation_maps().
	 * @return string[] Types, sorted.
	 */
	public static function orphaned_types( array $roles, array $maps ) {
		$out = array();

		foreach ( $maps as $type => $info ) {
			$edit = isset( $info['edit'] ) ? (string) $info['edit'] : '';

			if ( '' === $edit ) {
				continue;
			}

			foreach ( $roles as $role ) {
				if ( is_array( $role ) && ! empty( $role['capabilities'][ $edit ] ) ) {
					continue 2;
				}
			}

			$out[] = (string) $type;
		}

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Each content type that has separate permissions (RS-30) with what
	 * maps to its strings: the strings of Posts (or Pages, the type it
	 * shared before) => the type's own (BLT_Toolkit_Isolation::seed_map()),
	 * its label, and its `edit_posts` string. Reads only; empty when no
	 * type is isolated, without loading the separate-permissions flows.
	 *
	 * @return array<string,array{label:string,map:array,edit:string}>
	 */
	public static function isolation_maps() {
		$isolated = BLT_Toolkit_Isolation_Core::isolated();
		$out      = array();

		if ( array() === $isolated ) {
			return $out;
		}

		foreach ( $isolated as $type => $info ) {
			$type = (string) $type;
			$base = get_post_type_object( BLT_Toolkit_Isolation::shares( $type ) );

			if ( ! $base instanceof WP_Post_Type ) {
				continue;
			}

			$old = array();

			foreach ( (array) $base->cap as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$old[ (string) $key ] = (string) $value;
				}
			}

			$new = BLT_Toolkit_Isolation_Core::new_caps( $type, $info['singular'], $info['plural'] );
			$map = BLT_Toolkit_Isolation::seed_map( $old, $new );

			if ( array() === $map ) {
				continue;
			}

			$object = get_post_type_object( $type );

			$out[ $type ] = array(
				'label' => $object instanceof WP_Post_Type && isset( $object->labels->name ) ? (string) $object->labels->name : $type,
				'map'   => $map,
				'edit'  => isset( $new['edit_posts'] ) ? (string) $new['edit_posts'] : '',
			);
		}

		return $out;
	}

	/**
	 * What a restore or reset preview says about content types with
	 * separate permissions: the types whose strings the roles get because
	 * the written roles predate them (`seeded`), and the types no role
	 * would let anyone edit afterwards (`orphaned`, with RS-32's revert as
	 * the way out). Type => label.
	 *
	 * @param string[] $seeded Types seed_isolated() seeded.
	 * @param array    $target The roles to be written.
	 * @param array    $maps   isolation_maps().
	 * @return array{seeded:array<string,string>,orphaned:array<string,string>}
	 */
	public static function isolation_notes( array $seeded, array $target, array $maps ) {
		$out = array(
			'seeded'   => array(),
			'orphaned' => array(),
		);

		foreach ( $seeded as $type ) {
			if ( isset( $maps[ $type ] ) ) {
				$out['seeded'][ $type ] = $maps[ $type ]['label'];
			}
		}

		foreach ( self::orphaned_types( $target, $maps ) as $type ) {
			$out['orphaned'][ $type ] = $maps[ $type ]['label'];
		}

		return $out;
	}

	/**
	 * How a role is written in a user's serialized capabilities meta: the
	 * key `s:LENGTH:"id";`. A key of that array is a role membership
	 * whatever its value (WP_User::get_role_caps()), and its values are
	 * booleans, so this text marks the role. Pure.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	public static function role_key( $role ) {
		$role = (string) $role;

		return 's:' . strlen( $role ) . ':"' . $role . '";';
	}

	/**
	 * Who a write that deletes roles leaves behind (RO-43, SNAP-4): for each
	 * role the diff removes, its name and how many people hold it, and how
	 * many of those hold none of the roles that remain (they are left with
	 * no role). One grouped query on the capabilities meta, only when
	 * `$count` is true (a preview); the confirm skips it (SEC-9). Also the
	 * default role for new users and the additional default roles (US-2)
	 * that disappear.
	 *
	 * @param array    $diff      Role_Diff::diff() of the write.
	 * @param string[] $remaining Role IDs after the write.
	 * @param bool     $count     Whether to count people.
	 * @return array{removed:array<string,array{name:string,users:int|null}>,no_role:int|null,default_role:string,extra_default:string[]}
	 */
	public static function removal_impact( array $diff, array $remaining, $count = true ) {
		global $wpdb;

		$out = array(
			'removed'       => array(),
			'no_role'       => null,
			'default_role'  => '',
			'extra_default' => array(),
		);

		foreach ( isset( $diff['removed'] ) ? (array) $diff['removed'] : array() as $role => $data ) {
			$name = is_array( $data ) && isset( $data['name'] ) && is_scalar( $data['name'] ) && '' !== (string) $data['name'] ? (string) $data['name'] : (string) $role;

			$out['removed'][ (string) $role ] = array(
				'name'  => function_exists( 'translate_user_role' ) ? translate_user_role( $name ) : $name,
				'users' => null,
			);
		}

		if ( array() === $out['removed'] ) {
			return $out;
		}

		$default = (string) get_option( 'default_role' );

		if ( '' !== $default && isset( $out['removed'][ $default ] ) ) {
			$out['default_role'] = $default;
		}

		$extra = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );

		foreach ( is_array( $extra ) ? $extra : array() as $role ) {
			if ( is_string( $role ) && isset( $out['removed'][ $role ] ) ) {
				$out['extra_default'][] = $role;
			}
		}

		if ( ! $count ) {
			return $out;
		}

		$like    = static function ( $role ) use ( $wpdb ) {
			return $wpdb->prepare( 'meta_value LIKE %s', '%' . $wpdb->esc_like( self::role_key( $role ) ) . '%' );
		};
		$columns = array();
		$any     = array();
		$keep    = array();
		$index   = 0;

		foreach ( array_keys( $out['removed'] ) as $role ) {
			$match     = $like( $role );
			$any[]     = $match;
			$columns[] = 'SUM( CASE WHEN ' . $match . ' THEN 1 ELSE 0 END ) AS r' . $index;
			++$index;
		}

		foreach ( $remaining as $role ) {
			$keep[] = $like( (string) $role );
		}

		$columns[] = 'SUM( CASE WHEN ' . ( array() === $keep ? '1 = 1' : 'NOT ( ' . implode( ' OR ', $keep ) . ' )' ) . ' THEN 1 ELSE 0 END ) AS left_with_none';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- One count for a preview that deletes roles; every LIKE is prepared above.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . implode( ', ', $columns ) . " FROM {$wpdb->usermeta} WHERE meta_key = %s AND ( " . implode( ' OR ', $any ) . ' )', $wpdb->get_blog_prefix() . 'capabilities' ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return $out;
		}

		$index = 0;

		foreach ( array_keys( $out['removed'] ) as $role ) {
			$out['removed'][ $role ]['users'] = isset( $row[ 'r' . $index ] ) ? (int) $row[ 'r' . $index ] : 0;
			++$index;
		}

		$out['no_role'] = isset( $row['left_with_none'] ) ? (int) $row['left_with_none'] : 0;

		return $out;
	}

	/**
	 * What a restore gives `administrator` beyond the snapshot (SNAP-4,
	 * SEC-12), so the preview can say so in words and not only in the diff.
	 * Pure.
	 *
	 * Compared with the snapshot's own copy of the role, or with the role
	 * today when the snapshot has none (restorable_roles() then keeps
	 * today's). A capability counts when the role written grants it and that
	 * copy does not (absent, or denied).
	 *
	 * @param array    $snapshot Roles array from the snapshot.
	 * @param array    $current  Roles array today.
	 * @param array    $target   The roles the restore writes.
	 * @param string[] $lockout  The lockout-critical set (SEC-12).
	 * @param string[] $own      BLT Toolkit's capabilities (SPEC 5.1).
	 * @param array    $maps     Optional: isolation_maps().
	 * @return array{lockout:string[],blt:string[],isolated:string[],other:string[],kept_role:bool} Sorted names: the
	 *         lockout-critical ones, BLT Toolkit's own (because the snapshot
	 *         predates them), the strings of content types with separate
	 *         permissions (because the snapshot predates those, RS-30),
	 *         anything else; `kept_role` when the snapshot had no
	 *         Administrator role and today's is kept.
	 */
	public static function restore_additions( array $snapshot, array $current, array $target, array $lockout, array $own, array $maps = array() ) {
		$admin     = self::ADMIN_ROLE;
		$kept_role = ! isset( $snapshot[ $admin ] ) || ! is_array( $snapshot[ $admin ] );
		$base_role = $kept_role ? ( isset( $current[ $admin ] ) && is_array( $current[ $admin ] ) ? $current[ $admin ] : array() ) : $snapshot[ $admin ];
		$base      = isset( $base_role['capabilities'] ) && is_array( $base_role['capabilities'] ) ? $base_role['capabilities'] : array();
		$after     = isset( $target[ $admin ]['capabilities'] ) && is_array( $target[ $admin ]['capabilities'] ) ? $target[ $admin ]['capabilities'] : array();
		$lockout   = array_map( 'strval', $lockout );
		$own       = array_map( 'strval', $own );
		$typed     = array();
		$out       = array(
			'lockout'   => array(),
			'blt'       => array(),
			'isolated'  => array(),
			'other'     => array(),
			'kept_role' => $kept_role,
		);

		foreach ( $maps as $info ) {
			$typed += self::map_strings( isset( $info['map'] ) ? (array) $info['map'] : array() );
		}

		foreach ( $after as $cap => $grant ) {
			$cap = (string) $cap;

			if ( ! $grant || ( array_key_exists( $cap, $base ) && $base[ $cap ] ) ) {
				continue;
			}

			if ( in_array( $cap, $lockout, true ) ) {
				$out['lockout'][] = $cap;
			} elseif ( in_array( $cap, $own, true ) ) {
				$out['blt'][] = $cap;
			} elseif ( isset( $typed[ $cap ] ) ) {
				$out['isolated'][] = $cap;
			} else {
				$out['other'][] = $cap;
			}
		}

		sort( $out['lockout'], SORT_STRING );
		sort( $out['blt'], SORT_STRING );
		sort( $out['isolated'], SORT_STRING );
		sort( $out['other'], SORT_STRING );

		return $out;
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
	 * - RS-30: for each content type that has separate permissions now but
	 *   whose strings no role in the snapshot mentions (the snapshot was
	 *   taken before the type got them: the baseline, a `before_isolation`
	 *   snapshot), every role gets the type's strings that match the Posts
	 *   (or Pages) capabilities it holds, as giving the type separate
	 *   permissions did (seed_isolated()). Without that, restoring such a
	 *   snapshot would take the type's strings from every role, Administrator
	 *   included, while the type keeps its separate permissions, and nobody
	 *   could edit it.
	 *
	 * @param array    $snapshot Roles array from a snapshot.
	 * @param array    $current  Roles array today.
	 * @param string[] $lockout  The lockout-critical set (SEC-12).
	 * @param string[] $own      BLT Toolkit's capabilities (SPEC 5.1).
	 * @param array    $maps     Optional: isolation_maps().
	 * @return array
	 */
	public static function restorable_roles( array $snapshot, array $current, array $lockout, array $own, array $maps = array() ) {
		$admin    = self::ADMIN_ROLE;
		$snapshot = self::seed_isolated( $snapshot, $maps )['roles'];

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
	 * value) and removes. The role store builds the same change set
	 * (BLT_Toolkit_Role_Store::changes_from_diff()) for its own Guard pass;
	 * this stays for callers that want the plain adds and removes.
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
	 * The refusal when the roles changed between the preview and the
	 * confirm (the RO-2 rule for whole-site writes: what is written is what
	 * was shown).
	 *
	 * @return WP_Error `blt_toolkit_roles_changed`, status 409.
	 */
	public static function changed_error() {
		return new WP_Error(
			'blt_toolkit_roles_changed',
			__( 'The roles changed since you opened the preview. Look at the new preview and confirm again.', 'blt-toolkit' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The role-store mutator of a restore.
	 *
	 * It receives the roles as re-read by the store, refuses when they no
	 * longer hash to `$expected` (when one is given), and returns the
	 * snapshot's roles made safe, in today's order. `$seen` receives the hash
	 * of the roles it was given and `$additions` restore_additions(), for
	 * the preview and the log.
	 *
	 * @param array  $snapshot  The snapshot, with its roles.
	 * @param string $expected  Roles hash from the preview, or '' to skip the check.
	 * @param string $seen      Receives the hash of the roles the store read.
	 * @param array  $additions Receives restore_additions().
	 * @param array  $maps      isolation_maps().
	 * @return callable
	 */
	private static function restore_mutator( array $snapshot, $expected, &$seen, &$additions, array $maps ) {
		$lockout = BLT_Toolkit_Caps::lockout_critical();
		$own     = array_keys( BLT_Toolkit_Caps::own() );
		$roles   = $snapshot['roles'];

		return static function ( array $current ) use ( $roles, $expected, $lockout, $own, $maps, &$seen, &$additions ) {
			$seen = BLT_Toolkit_Role_Diff::hash( $current );

			if ( '' !== $expected && ! hash_equals( $seen, $expected ) ) {
				return BLT_Toolkit_Snapshots::changed_error();
			}

			$target    = BLT_Toolkit_Snapshots::restorable_roles( $roles, $current, $lockout, $own, $maps );
			$additions = BLT_Toolkit_Snapshots::restore_additions( $roles, $current, $target, $lockout, $own, $maps );

			return BLT_Toolkit_Snapshots::in_order( $target, $current );
		};
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
	 * @param array      $roles Snapshot roles.
	 * @param array|null $maps  isolation_maps(), or null to work them out.
	 * @return array
	 */
	private static function target_roles( array $roles, $maps = null ) {
		$current = self::current_roles();

		return self::in_order(
			self::restorable_roles(
				$roles,
				$current,
				BLT_Toolkit_Caps::lockout_critical(),
				array_keys( BLT_Toolkit_Caps::own() ),
				is_array( $maps ) ? $maps : self::isolation_maps()
			),
			$current
		);
	}

	/**
	 * The log summary of a restore.
	 *
	 * @param array $meta The restored snapshot's metadata.
	 * @return string
	 */
	private static function restored_summary( array $meta ) {
		$when = function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( $meta['created_at'], 'Y-m-d H:i' ) : $meta['created_at'];

		return sprintf(
			/* translators: 1: snapshot number, 2: date and time it was taken. */
			__( 'Restored the roles saved in snapshot %1$d (%2$s).', 'blt-toolkit' ),
			$meta['id'],
			$when
		);
	}

	/**
	 * A role's display name as it will be after a change: the name a created
	 * or renamed role gets, otherwise its name today, otherwise its ID.
	 *
	 * @param string $role    Role ID.
	 * @param array  $diff    Role_Diff::diff() of the change.
	 * @param array  $current Roles array today.
	 * @return string The stored (untranslated) name.
	 */
	private static function after_name( $role, array $diff, array $current ) {
		if ( isset( $diff['added'][ $role ]['name'] ) && is_scalar( $diff['added'][ $role ]['name'] ) && '' !== (string) $diff['added'][ $role ]['name'] ) {
			return (string) $diff['added'][ $role ]['name'];
		}

		if ( isset( $diff['changed'][ $role ]['name']['to'] ) && is_scalar( $diff['changed'][ $role ]['name']['to'] ) && '' !== (string) $diff['changed'][ $role ]['name']['to'] ) {
			return (string) $diff['changed'][ $role ]['name']['to'];
		}

		if ( isset( $current[ $role ]['name'] ) && is_scalar( $current[ $role ]['name'] ) && '' !== (string) $current[ $role ]['name'] ) {
			return (string) $current[ $role ]['name'];
		}

		return $role;
	}

	/**
	 * Text folded for a typed confirmation: trimmed, lowercase. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fold( $text ) {
		$text = trim( $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * A snapshot's metadata without its roles.
	 *
	 * @param array $snapshot get() result.
	 * @return array
	 */
	private static function meta_only( array $snapshot ) {
		unset( $snapshot['roles'] );

		return $snapshot;
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
