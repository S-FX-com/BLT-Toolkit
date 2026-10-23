<?php
/**
 * Bulk rename from CSV: the WordPress side of UN-30 and the `username_bulk`
 * job type (JOB-5).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk rename (SPEC 8.6, UN-30), between the Usernames screen and the pure
 * classes:
 *
 * - The template (step 1): every user the person may see, as
 *   `user_id,username,new_username`, read 500 at a time (template_batch()).
 * - The plan (steps 2 to 4): BLT_Toolkit_Csv parses the upload, then
 *   BLT_Toolkit_Rename_Planner plans it with the lookups wired here: the
 *   users the rows name (minus administrators hidden from the person,
 *   SEC-6), the one validator run against the batch's projected state
 *   (UN-1 to UN-9), the SEC rules through `blt_change_username`, and the
 *   warnings (UN-12 switched sessions, UN-13 author URL, "this is you").
 *   The plan is kept for an hour in a transient of the person who made it,
 *   with a random token the confirm form must echo, so what runs is exactly
 *   what was shown.
 * - The backup acknowledgment (step 5, CONVENTIONS 11): asked once per
 *   sign-in session, remembered in that session's data.
 * - The job (step 6, JOB-1 to JOB-5): one item per ready row, in plan order.
 *   Each runs BLT_Toolkit_Username_Changer::change() (UN-10), which logs the
 *   rename; a row that fails is logged here. The settings that change what a
 *   rename does (`notify_user`, `sign_out_others`, the email's subject and
 *   body) are pinned at start (JOB-3), and so is the author URL policy
 *   (`nicename_policy`) the dry run's "author URL will change" warnings
 *   were worked out with, so the run does what the dry run showed.
 *
 * Loaded only while the Usernames module runs: the module registers the
 * job type, and the screen offers bulk renaming only then.
 */
class BLT_Toolkit_Username_Bulk {

	/**
	 * Job type (JOB-5).
	 */
	const JOB_TYPE = 'username_bulk';

	/**
	 * Capability that runs the job and opens the Usernames screen (SPEC
	 * 13.1). Each row is still authorized against its user with
	 * `blt_change_username` (SEC-1, SEC-2). Following, cancelling and
	 * retrying a job also needs can_use(): its owner or a toolkit
	 * administrator.
	 */
	const CAPABILITY = 'blt_change_usernames';

	/**
	 * Default row cap (`blt_toolkit_bulk_rename_max_rows`).
	 */
	const DEFAULT_MAX_ROWS = 5000;

	/**
	 * Users read per query for the template (UN-30 step 1).
	 */
	const TEMPLATE_BATCH = 500;

	/**
	 * Bytes allowed per data row of an upload; a file larger than the row
	 * cap times this is refused before it is read.
	 */
	const BYTES_PER_ROW = 1024;

	/**
	 * Transient holding a person's pending plan, followed by their user ID.
	 */
	const PLAN_TRANSIENT = 'blt_toolkit_bulk_plan_';

	/**
	 * How long a plan waits for confirmation.
	 */
	const PLAN_TTL = HOUR_IN_SECONDS;

	/**
	 * Key in the sign-in session's data recording the backup acknowledgment
	 * (CONVENTIONS 11: once per session).
	 */
	const ACK_SESSION_KEY = 'blt_toolkit_backup_ack';

	/**
	 * Register the `username_bulk` job type. Hooked on `init` priority 5 by
	 * BLT_Toolkit_Usernames::boot().
	 *
	 * @return void
	 */
	public static function register_job_type() {
		BLT_Toolkit_Jobs::register_type(
			self::JOB_TYPE,
			array(
				'label'      => __( 'Bulk rename usernames', 'blt-toolkit' ),
				'capability' => self::CAPABILITY,
				'mode'       => 'items',
				'process'    => array( __CLASS__, 'process' ),
				'describe'   => array( __CLASS__, 'describe' ),
				'can_use'    => array( __CLASS__, 'can_use' ),
				'audit'      => array( __CLASS__, 'audit' ),
			)
		);
	}

	/**
	 * Who may follow, cancel and retry a bulk rename (the job type's
	 * `can_use`): the person who started it, or a toolkit administrator.
	 *
	 * The job's items and failures name every login in the batch, and a
	 * retry renames as the job's owner (JOB-3). Any other holder of
	 * `blt_change_usernames` could otherwise read the logins of
	 * administrators SEC-6 hides from them, and have a rename the Guard
	 * refuses them run in the owner's name. Pure.
	 *
	 * @param array   $job  Stored state.
	 * @param WP_User $user Who asks.
	 * @return bool
	 */
	public static function can_use( array $job, WP_User $user ) {
		if ( ! $user->exists() ) {
			return false;
		}

		return ( isset( $job['owner'] ) && (int) $job['owner'] === (int) $user->ID ) || BLT_Toolkit_Guard::is_toolkit_admin( $user );
	}

	/**
	 * Log a cancel or a retry of a bulk rename with the person who asked
	 * (LOG-1, LOG-2): the renames themselves are logged as the job's owner,
	 * so this row is what says who stopped or re-ran them. The job type's
	 * `audit` callback.
	 *
	 * @param string $verb `cancel` or `retry`.
	 * @param array  $job  The job after the change.
	 * @return void
	 */
	public static function audit( $verb, array $job ) {
		$retry = 'retry' === $verb;
		$count = $retry ? (int) $job['pass_total'] : max( 0, (int) $job['total'] - (int) $job['done'] );

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => $retry ? 'bulk_rename_retried' : 'bulk_rename_cancelled',
				'object_type' => 'job',
				'object_id'   => (string) $job['id'],
				'summary'     => $retry
					? sprintf(
						/* translators: %s: number of usernames. */
						_n( 'Retried %s failed username of a bulk rename.', 'Retried %s failed usernames of a bulk rename.', $count, 'blt-toolkit' ),
						number_format_i18n( $count )
					)
					: __( 'Cancelled a bulk rename. Usernames already changed stay changed.', 'blt-toolkit' ),
				'details'     => array(
					'job'   => (string) $job['id'],
					'batch' => isset( $job['pinned']['batch'] ) ? (string) $job['pinned']['batch'] : '',
					'owner' => (int) $job['owner'],
					'pass'  => (int) $job['pass'],
					'done'  => (int) $job['done'],
					'rows'  => $count,
				),
			)
		);
	}

	/**
	 * The most data rows one CSV may hold (UN-30, at least 1).
	 *
	 * @return int
	 */
	public static function max_rows() {
		/**
		 * Filter the most data rows one bulk rename CSV may contain (UN-30). A
		 * larger file is refused, not truncated.
		 *
		 * @param int $rows Default 5000.
		 */
		return max( 1, (int) apply_filters( 'blt_toolkit_bulk_rename_max_rows', self::DEFAULT_MAX_ROWS ) );
	}

	/**
	 * The largest upload read, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return self::max_rows() * self::BYTES_PER_ROW + 65536;
	}

	// ---------- Step 1: template ----------

	/**
	 * One batch of template rows: users in ID order, `$per_page` per query.
	 *
	 * Every user the person may see: administrators are left out when they
	 * are hidden from this person (SEC-6), as in the Users list. Someone
	 * without `list_users` gets only the users they may rename, so the file
	 * never lists accounts they could not list anyway.
	 *
	 * @param WP_User $actor    Who downloads.
	 * @param int     $page     Page, from 1.
	 * @param int     $per_page Users per query.
	 * @return array{rows:array<int,array{0:int,1:string}>,fetched:int} `fetched`
	 *               counts the users read, so the caller knows whether another
	 *               page may follow.
	 */
	public static function template_batch( WP_User $actor, $page, $per_page = self::TEMPLATE_BATCH ) {
		$args = array(
			'fields'      => array( 'ID', 'user_login' ),
			'number'      => max( 1, (int) $per_page ),
			'paged'       => max( 1, (int) $page ),
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => false,
		);

		if ( BLT_Toolkit_Guard::hides_admins_from( $actor ) ) {
			$args['role__not_in'] = array( BLT_Toolkit_Guard::ADMIN_ROLE );
		}

		$query    = new WP_User_Query( $args );
		$found    = (array) $query->get_results();
		$may_list = user_can( $actor, 'list_users' );
		$rows     = array();
		$ids      = array_map( 'intval', wp_list_pluck( $found, 'ID' ) );

		// SEC-9: the per-user check below loads each user and their meta.
		// Read the batch's users in one query and their meta in another,
		// instead of two queries per user.
		if ( ! $may_list && array() !== $ids ) {
			cache_users( $ids );
		}

		foreach ( $found as $user ) {
			$id = (int) $user->ID;

			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			if ( ! $may_list && ! user_can( $actor, 'blt_change_username', $id ) ) {
				continue;
			}

			$rows[] = array( $id, (string) $user->user_login );
		}

		if ( ! $may_list ) {
			self::release_users( $ids );
		}

		return array(
			'rows'    => $rows,
			'fetched' => count( $found ),
		);
	}

	/**
	 * Let go of the users a template batch loaded, so a download that walks
	 * every user keeps the memory of one batch rather than of every user:
	 * a streamed file cannot report an error once its first line is sent.
	 *
	 * Without a persistent object cache the entries live only in this
	 * request's memory and are deleted. With one, deleting would empty the
	 * shared cache, so only this request's copy is dropped
	 * (wp_cache_flush_runtime()), when the cache supports that.
	 *
	 * @param int[] $ids User IDs.
	 * @return void
	 */
	private static function release_users( array $ids ) {
		if ( array() === $ids ) {
			return;
		}

		if ( ! wp_using_ext_object_cache() ) {
			foreach ( $ids as $id ) {
				$data = wp_cache_get( $id, 'users' );

				// The lookups update_user_caches() adds next to the user.
				if ( is_object( $data ) ) {
					wp_cache_delete( (string) $data->user_login, 'userlogins' );
					wp_cache_delete( (string) $data->user_nicename, 'userslugs' );
					wp_cache_delete( (string) $data->user_email, 'useremail' );
				}

				wp_cache_delete( $id, 'users' );
				wp_cache_delete( $id, 'user_meta' );
			}

			return;
		}

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	// ---------- Steps 2 to 4: plan ----------

	/**
	 * Plan a parsed CSV for the current user (UN-30 step 3).
	 *
	 * @param array   $parsed BLT_Toolkit_Csv::parse() result.
	 * @param WP_User $actor  Who plans: the current user.
	 * @return array BLT_Toolkit_Rename_Planner::plan() result, plus
	 *               `nicename_policy`: the author URL policy its warnings used,
	 *               which start() pins (JOB-3).
	 */
	public static function plan( array $parsed, WP_User $actor ) {
		$rows   = isset( $parsed['rows'] ) && is_array( $parsed['rows'] ) ? $parsed['rows'] : array();
		$users  = self::users_for( $rows, $actor );
		$cache  = array();
		$policy = (string) BLT_Toolkit_Settings::get( 'usernames', 'nicename_policy' );

		$load = static function ( $user_id ) use ( &$cache ) {
			$user_id = (int) $user_id;

			if ( ! array_key_exists( $user_id, $cache ) ) {
				$user              = get_userdata( $user_id );
				$cache[ $user_id ] = $user instanceof WP_User ? $user : null;
			}

			return $cache[ $user_id ];
		};

		$lookup   = null;
		$validate = static function ( $new_name, $user_id, array $projected ) use ( $load, &$lookup ) {
			$user = $load( $user_id );

			if ( null === $user ) {
				return __( 'That user does not exist.', 'blt-toolkit' );
			}

			// One lookup for the batch: names it frees or claims answer from the
			// projection, every other name from the database.
			if ( null === $lookup ) {
				$lookup = BLT_Toolkit_Rename_Planner::owner_lookup( $projected, array( 'BLT_Toolkit_Username_Validator', 'login_owner' ) );
			}

			$errors = BLT_Toolkit_Username_Validator::validate( $new_name, $user, array( 'login_owner' => $lookup ) );

			if ( BLT_Toolkit_Username_Validator::is_valid( $errors ) ) {
				return true;
			}

			return BLT_Toolkit_Username_Changer::public_error_message( (string) $errors->get_error_code(), $errors->get_error_message() );
		};

		$context = array(
			'actor_id'         => (int) $actor->ID,
			'may_rename'       => static function ( $user_id ) {
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
				if ( current_user_can( 'blt_change_username', (int) $user_id ) ) {
					return true;
				}

				return BLT_Toolkit_Username_Changer::refusal( (int) $user_id )->get_error_message();
			},
			'switched'         => static function ( $user_id ) {
				$state = BLT_Toolkit_Username_Changer::switched_state( (int) $user_id );

				return $state['switched_from'] || $state['switched_into'];
			},
			'nicename_changes' => static function ( $user_id, $new_name ) use ( $load, $policy ) {
				$user = $load( $user_id );

				return null !== $user && self::nicename_would_change( $user, $new_name, $policy );
			},
		);

		$plan = BLT_Toolkit_Rename_Planner::plan( $rows, $users, $validate, $context );

		$plan['nicename_policy'] = $policy;

		return $plan;
	}

	/**
	 * The users the rows name, by ID or by username, as the planner wants
	 * them: ID => login and email. Administrators hidden from the person
	 * (SEC-6), and for someone without `list_users` every user they may not
	 * rename, are left out, so their rows read as unknown users and the plan
	 * never shows their login. Their meta is cached in one query for the
	 * checks that follow.
	 *
	 * @param array   $rows  Parsed rows.
	 * @param WP_User $actor Who plans.
	 * @return array<int,array{login:string,email:string}>
	 */
	public static function users_for( array $rows, WP_User $actor ) {
		$ids    = array();
		$logins = array();

		foreach ( $rows as $row ) {
			$cells = isset( $row['cells'] ) && is_array( $row['cells'] ) ? $row['cells'] : array();
			$id    = isset( $cells['user_id'] ) && is_scalar( $cells['user_id'] ) ? trim( (string) $cells['user_id'] ) : '';
			$name  = isset( $cells['username'] ) && is_scalar( $cells['username'] ) ? trim( (string) $cells['username'] ) : '';

			if ( 1 === preg_match( '/^[0-9]{1,20}$/', $id ) && (int) $id > 0 ) {
				$ids[ (int) $id ] = true;
			} elseif ( '' !== $name ) {
				$logins[ $name ] = true;
			}
		}

		$found = array();

		foreach ( array_chunk( array_keys( $ids ), 500 ) as $chunk ) {
			foreach ( get_users(
				array(
					'include'     => $chunk,
					'count_total' => false,
				)
			) as $user ) {
				$found[ (int) $user->ID ] = $user;
			}
		}

		foreach ( array_chunk( array_keys( $logins ), 500 ) as $chunk ) {
			foreach ( get_users(
				array(
					'login__in'   => array_map( 'strval', $chunk ),
					'count_total' => false,
				)
			) as $user ) {
				$found[ (int) $user->ID ] = $user;
			}
		}

		if ( array() !== $found ) {
			cache_users( array_keys( $found ) );
		}

		$hide     = BLT_Toolkit_Guard::hides_admins_from( $actor );
		$may_list = user_can( $actor, 'list_users' );
		$users    = array();

		foreach ( $found as $id => $user ) {
			if ( $hide && BLT_Toolkit_Guard::is_toolkit_admin( $user ) ) {
				continue;
			}

			// As in the template: without `list_users`, only the users this
			// person may rename are known to them.
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's meta capability (SPEC 5.1).
			if ( ! $may_list && ! user_can( $actor, 'blt_change_username', (int) $id ) ) {
				continue;
			}

			$users[ $id ] = array(
				'login' => (string) $user->user_login,
				'email' => (string) $user->user_email,
			);
		}

		return $users;
	}

	/**
	 * Whether renaming would change the user's author slug, for the dry
	 * run's "author URL will change" warning (UN-13): exactly the slug the
	 * rename will write (BLT_Toolkit_Username_Changer::new_nicename(), policy,
	 * `pre_user_nicename` and the uniqueness suffix included), against the
	 * slugs stored now.
	 *
	 * @param WP_User     $user      The user as stored now.
	 * @param string      $new_login The new login.
	 * @param string|null $policy    Author URL policy; null reads the setting.
	 * @return bool
	 */
	public static function nicename_would_change( WP_User $user, $new_login, $policy = null ) {
		return BLT_Toolkit_Username_Changer::new_nicename( $user, (string) $new_login, $policy ) !== (string) $user->user_nicename;
	}

	// ---------- Plan storage ----------

	/**
	 * Keep a plan for its author until they confirm or discard it, replacing
	 * any earlier one. Holds either a plan or the reason the file could not
	 * be read (`error`), so the screen can show either after the redirect.
	 *
	 * @param int   $user_id Author.
	 * @param array $data    Plan fields (rows, order, counts, file, ...) or `error`.
	 * @return string|false The token the screen and the confirm form carry, or
	 *                      false when it could not be stored.
	 */
	public static function store_plan( $user_id, array $data ) {
		try {
			$token = bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			$token = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}

		$data['token']   = $token;
		$data['user_id'] = (int) $user_id;
		$data['created'] = time();

		delete_transient( self::PLAN_TRANSIENT . (int) $user_id );

		return set_transient( self::PLAN_TRANSIENT . (int) $user_id, self::pack( $data ), self::PLAN_TTL ) ? $token : false;
	}

	/**
	 * A plan as stored: compressed when zlib is available, because a
	 * 5,000-row plan is over 1 MB serialized, more than a Memcached object
	 * cache accepts by default, and repeats itself enough to shrink about
	 * tenfold. Base64, so the option row holds text only. Pure.
	 *
	 * @param array $data Plan.
	 * @return array Either the plan, or array( 'packed' => string ).
	 */
	public static function pack( array $data ) {
		if ( ! function_exists( 'gzcompress' ) ) {
			return $data;
		}

		$packed = gzcompress( serialize( $data ), 6 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Our own array, read back by unpack() only.

		return false === $packed ? $data : array( 'packed' => base64_encode( $packed ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for the options table, not obfuscation.
	}

	/**
	 * The plan from what pack() stored, or null when it is damaged. Pure.
	 *
	 * @param mixed $stored Stored value.
	 * @return array|null
	 */
	public static function unpack( $stored ) {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		if ( ! isset( $stored['packed'] ) ) {
			return $stored;
		}

		$bytes = is_string( $stored['packed'] ) && function_exists( 'gzuncompress' ) ? base64_decode( $stored['packed'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See pack().
		$plain = false === $bytes ? false : @gzuncompress( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Damaged storage is answered with null, not a PHP warning.
		$data  = false === $plain ? false : unserialize( $plain, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Written by pack(); no objects allowed.

		return is_array( $data ) ? $data : null;
	}

	/**
	 * A person's stored plan, when the token matches.
	 *
	 * @param int    $user_id Author.
	 * @param string $token   Token from the URL or the form.
	 * @return array|null
	 */
	public static function get_plan( $user_id, $token ) {
		$token = is_string( $token ) ? $token : '';
		$data  = '' === $token ? null : self::unpack( get_transient( self::PLAN_TRANSIENT . (int) $user_id ) );

		if ( ! is_array( $data ) || ! isset( $data['token'], $data['user_id'] ) || ! hash_equals( (string) $data['token'], $token ) || (int) $data['user_id'] !== (int) $user_id ) {
			return null;
		}

		return $data;
	}

	/**
	 * Forget a person's plan.
	 *
	 * @param int $user_id Author.
	 * @return void
	 */
	public static function discard_plan( $user_id ) {
		delete_transient( self::PLAN_TRANSIENT . (int) $user_id );
	}

	// ---------- Step 5: backup acknowledgment ----------

	/**
	 * Whether the current sign-in session already acknowledged a backup
	 * (CONVENTIONS 11).
	 *
	 * @return bool
	 */
	public static function has_backup_ack() {
		$session = self::current_session();

		return null !== $session && ! empty( $session['session'][ self::ACK_SESSION_KEY ] );
	}

	/**
	 * Record the acknowledgment in the current sign-in session's data, so it
	 * is not asked again until the person signs in again.
	 *
	 * @return bool Whether it was recorded.
	 */
	public static function record_backup_ack() {
		$session = self::current_session();

		if ( null === $session ) {
			return false;
		}

		$data                          = $session['session'];
		$data[ self::ACK_SESSION_KEY ] = time();

		$session['manager']->update( $session['token'], $data );

		return true;
	}

	/**
	 * The current request's session: its manager, token and data, or null.
	 *
	 * @return array{manager:WP_Session_Tokens,token:string,session:array}|null
	 */
	private static function current_session() {
		$user_id = get_current_user_id();
		$token   = wp_get_session_token();

		if ( $user_id <= 0 || '' === $token ) {
			return null;
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		$session = $manager->get( $token );

		if ( ! is_array( $session ) ) {
			return null;
		}

		return array(
			'manager' => $manager,
			'token'   => $token,
			'session' => $session,
		);
	}

	// ---------- Step 6: the job ----------

	/**
	 * The job's items: the ready rows in execution order (chains with
	 * dependencies first). Pure.
	 *
	 * A row the dry run flagged for a switched session (UN-12) carries the
	 * confirmation the person gave by starting the job; a row that became
	 * switched later is not confirmed, so it fails with that reason and can
	 * be retried once the session ends.
	 *
	 * @param array $plan Rename_Planner::plan() result (rows, order).
	 * @return array<int,array{user_id:int,old:string,new:string,line:int,confirmed_switched:bool}>
	 */
	public static function items_from_plan( array $plan ) {
		$rows  = isset( $plan['rows'] ) && is_array( $plan['rows'] ) ? $plan['rows'] : array();
		$order = isset( $plan['order'] ) && is_array( $plan['order'] ) ? $plan['order'] : array();
		$items = array();

		foreach ( $order as $index ) {
			if ( ! isset( $rows[ $index ] ) || BLT_Toolkit_Rename_Planner::READY !== $rows[ $index ]['status'] ) {
				continue;
			}

			$row = $rows[ $index ];

			$items[] = array(
				'user_id'            => (int) $row['user_id'],
				'old'                => (string) $row['old'],
				'new'                => (string) $row['new'],
				'line'               => (int) $row['line'],
				'confirmed_switched' => isset( $row['warnings']['switched'] ),
			);
		}

		return $items;
	}

	/**
	 * The live `username_bulk` job, if one is queued or running.
	 *
	 * @return array|null Stored state.
	 */
	public static function live_job() {
		foreach ( BLT_Toolkit_Jobs::jobs( self::JOB_TYPE ) as $job ) {
			if ( ! BLT_Toolkit_Jobs::is_terminal( $job['status'] ) ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * Start the job for a stored plan (UN-30 step 6), as the current user.
	 *
	 * The caller has checked the capability, the nonce, the plan's token and
	 * the backup acknowledgment. Settings that change what a rename does are
	 * pinned (JOB-3), and the start is logged (`bulk_rename`) with the
	 * numbers of the dry run.
	 *
	 * @param array $plan Stored plan.
	 * @return string|WP_Error Job ID, or `blt_toolkit_bulk_busy`,
	 *                         `blt_toolkit_bulk_nothing` or a
	 *                         BLT_Toolkit_Jobs::start() error.
	 */
	public static function start( array $plan ) {
		if ( null !== self::live_job() ) {
			return new WP_Error( 'blt_toolkit_bulk_busy', __( 'Another bulk rename is still running.', 'blt-toolkit' ) );
		}

		$items = self::items_from_plan( $plan );

		if ( array() === $items ) {
			return new WP_Error( 'blt_toolkit_bulk_nothing', __( 'No row in the dry run is ready to rename.', 'blt-toolkit' ) );
		}

		$pinned = self::pinned_settings( $plan );

		$counts = isset( $plan['counts'] ) && is_array( $plan['counts'] ) ? $plan['counts'] : array();
		$file   = isset( $plan['file'] ) ? (string) $plan['file'] : '';

		// Logged once the job is stored and before its first tick is queued
		// (`blt_toolkit_before_job`), so the start row comes before every row
		// a tick writes, even when a tick runs at once in another process.
		$log_start = static function ( $job ) use ( $pinned, $items, $counts, $file ) {
			if ( ! is_array( $job ) || self::JOB_TYPE !== ( isset( $job['type'] ) ? $job['type'] : '' ) || 1 !== (int) $job['pass'] || ! isset( $job['pinned']['batch'] ) || $pinned['batch'] !== $job['pinned']['batch'] ) {
				return;
			}

			self::log_start( (string) $job['id'], $pinned, count( $items ), $counts, $file );
		};

		add_action( 'blt_toolkit_before_job', $log_start );

		try {
			$id = BLT_Toolkit_Jobs::start( self::JOB_TYPE, $items, $pinned );
		} finally {
			remove_action( 'blt_toolkit_before_job', $log_start );
		}

		return $id;
	}

	/**
	 * The settings a bulk rename pins when it starts (JOB-3): whether to
	 * email and sign out each user, the email's subject and body, and the
	 * author URL policy. The policy is the one the dry run's "author URL will
	 * change" warnings used (stored with the plan), so the run changes
	 * exactly the author URLs the dry run said it would; the rest are read
	 * now.
	 *
	 * @param array $plan Stored plan.
	 * @return array{notify:bool,sign_out_others:bool,nicename_policy:string,email_subject:string,email_body:string,batch:string}
	 */
	public static function pinned_settings( array $plan ) {
		$policy = isset( $plan['nicename_policy'] ) && in_array( $plan['nicename_policy'], BLT_Toolkit_Settings::NICENAME_POLICIES, true )
			? (string) $plan['nicename_policy']
			: (string) BLT_Toolkit_Settings::get( 'usernames', 'nicename_policy' );

		return array(
			'notify'          => (bool) BLT_Toolkit_Settings::get( 'usernames', 'notify_user' ),
			'sign_out_others' => (bool) BLT_Toolkit_Settings::get( 'usernames', 'sign_out_others' ),
			'nicename_policy' => $policy,
			'email_subject'   => (string) BLT_Toolkit_Settings::get( 'usernames', 'email_subject' ),
			'email_body'      => (string) BLT_Toolkit_Settings::get( 'usernames', 'email_body' ),
			'batch'           => isset( $plan['token'] ) ? (string) $plan['token'] : '',
		);
	}

	/**
	 * Rename one row (the job's `process`): UN-10 through
	 * BLT_Toolkit_Username_Changer::change() with the pinned settings.
	 *
	 * Runs as the person who started the job (JOB-3), so the SEC rules are
	 * theirs. Safe to repeat (a tick can be interrupted between saves): a
	 * user who already has the new name is done. A user whose name is no
	 * longer the one in the dry run is left alone and the row fails, so a
	 * rename never applies to a state nobody reviewed.
	 *
	 * @param array $item   From items_from_plan().
	 * @param array $pinned Pinned settings (pinned_settings()).
	 * @return true|string|WP_Error True, a warning (done), or why it failed.
	 */
	public static function process( $item, array $pinned ) {
		$item    = is_array( $item ) ? $item : array();
		$user_id = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
		$old     = isset( $item['old'] ) ? (string) $item['old'] : '';
		$new     = isset( $item['new'] ) ? (string) $item['new'] : '';
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user instanceof WP_User ) {
			$error = new WP_Error( 'blt_toolkit_username_no_user', __( 'That user no longer exists.', 'blt-toolkit' ) );

			self::log_failure( $user_id, $old, $new, $error, $pinned );

			return $error;
		}

		$current = (string) $user->user_login;

		if ( $current === $new ) {
			return true;
		}

		if ( $current !== $old ) {
			$error = new WP_Error(
				'blt_toolkit_bulk_stale',
				sprintf(
					/* translators: 1: the username now, 2: the username in the dry run. */
					__( 'The username is now %1$s, not %2$s as in the dry run, so it was not changed.', 'blt-toolkit' ),
					$current,
					$old
				)
			);

			self::log_failure( $user_id, $old, $new, $error, $pinned );

			return $error;
		}

		$self    = get_current_user_id() === $user_id;
		$options = array(
			'notify'             => ! empty( $pinned['notify'] ),
			'sign_out_others'    => ! empty( $pinned['sign_out_others'] ),
			'source'             => 'bulk',
			'confirmed_switched' => ! empty( $item['confirmed_switched'] ),
		);

		// JOB-3: the author URL policy and the email as they were at the
		// start (a job stored before they were pinned reads the settings).
		foreach ( array( 'nicename_policy', 'email_subject', 'email_body' ) as $key ) {
			if ( isset( $pinned[ $key ] ) && is_string( $pinned[ $key ] ) ) {
				$options[ $key ] = $pinned[ $key ];
			}
		}

		$result = BLT_Toolkit_Username_Changer::change( $user_id, $new, $options );

		if ( is_wp_error( $result ) ) {
			// The changer logs a failed write itself; everything it refused
			// before writing is logged here, so every row has its own entry.
			if ( 'blt_toolkit_username_not_saved' !== $result->get_error_code() ) {
				self::log_failure( $user_id, $old, $new, $result, $pinned );
			}

			return new WP_Error(
				(string) $result->get_error_code(),
				BLT_Toolkit_Username_Changer::public_error_message( (string) $result->get_error_code(), $result->get_error_message() )
			);
		}

		if ( $self && empty( $result['session_kept'] ) ) {
			return __( 'This was your own username: sign in again with the new one.', 'blt-toolkit' );
		}

		return true;
	}

	/**
	 * An item as people read it: "bob to carol".
	 *
	 * @param mixed $item Item.
	 * @return string
	 */
	public static function describe( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['old'], $item['new'] ) ) {
			return '';
		}

		/* translators: 1: current username, 2: new username. */
		return sprintf( __( '%1$s to %2$s', 'blt-toolkit' ), (string) $item['old'], (string) $item['new'] );
	}

	/**
	 * Log the start of a bulk rename (`bulk_rename`) with the dry run's
	 * numbers and the pinned settings (JOB-3).
	 *
	 * @param string $job_id Job ID.
	 * @param array  $pinned Pinned settings.
	 * @param int    $ready  Rows to rename.
	 * @param array  $counts The dry run's counts.
	 * @param string $file   Uploaded file name.
	 * @return void
	 */
	private static function log_start( $job_id, array $pinned, $ready, array $counts, $file ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => 'bulk_rename',
				'object_type' => 'job',
				'object_id'   => $job_id,
				'summary'     => sprintf(
					/* translators: %s: number of usernames. */
					_n( 'Started a bulk rename of %s username from a CSV file.', 'Started a bulk rename of %s usernames from a CSV file.', $ready, 'blt-toolkit' ),
					number_format_i18n( $ready )
				),
				'details'     => array(
					'job'             => $job_id,
					'batch'           => $pinned['batch'],
					'file'            => $file,
					'rows'            => isset( $counts['total'] ) ? (int) $counts['total'] : 0,
					'ready'           => (int) $ready,
					'errors'          => isset( $counts[ BLT_Toolkit_Rename_Planner::ERROR ] ) ? (int) $counts[ BLT_Toolkit_Rename_Planner::ERROR ] : 0,
					'no_change'       => isset( $counts[ BLT_Toolkit_Rename_Planner::NO_CHANGE ] ) ? (int) $counts[ BLT_Toolkit_Rename_Planner::NO_CHANGE ] : 0,
					'notify'          => $pinned['notify'],
					'sign_out_others' => $pinned['sign_out_others'],
					'nicename_policy' => isset( $pinned['nicename_policy'] ) ? (string) $pinned['nicename_policy'] : '',
				),
			)
		);
	}

	/**
	 * Log a row the job could not rename (LOG-1), so every row of a bulk
	 * rename has its own entry, as UN-30 step 6 asks.
	 *
	 * @param int      $user_id  User.
	 * @param string   $old      Username in the dry run.
	 * @param string   $new_name New username.
	 * @param WP_Error $error    Why.
	 * @param array    $pinned   Pinned settings (for the batch).
	 * @return void
	 */
	private static function log_failure( $user_id, $old, $new_name, WP_Error $error, array $pinned ) {
		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => 'username_changed',
				'object_type' => 'user',
				'object_id'   => (string) (int) $user_id,
				'summary'     => sprintf(
					/* translators: 1: current username, 2: the username it could not be changed to, 3: why. */
					__( 'Could not change the username %1$s to %2$s: %3$s', 'blt-toolkit' ),
					$old,
					$new_name,
					$error->get_error_message()
				),
				'details'     => array(
					'old_login' => (string) $old,
					'new_login' => (string) $new_name,
					'source'    => 'bulk',
					'batch'     => isset( $pinned['batch'] ) ? (string) $pinned['batch'] : '',
					'error'     => (string) $error->get_error_code(),
				),
				'status'      => 'failure',
			)
		);
	}
}
