<?php
/**
 * Usernames screen: bulk rename from CSV and the recent changes.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Usernames screen (SPEC 13.2). Needs `blt_change_usernames`.
 *
 * Single usernames are changed from the user's profile (UN-16); this screen
 * holds the two cards SPEC 13.2 lists:
 *
 * - **Bulk rename from CSV** (UN-30), a dry-run-first flow (CONVENTIONS 11)
 *   in plain forms that works without JavaScript, each a POST through
 *   BLT_Toolkit_Admin's router (nonce, capability, PRG):
 *   1. Download template: streams `user_id,username,new_username` for every
 *      user the person may see, 500 users per query.
 *   2. Upload: the CSV is parsed and planned at once (nothing is written)
 *      and the person lands on the dry run.
 *   3. Dry run: every row with its user, old and new username, status and
 *      warnings, pageable and filterable by status.
 *   4. Start: with a backup acknowledgment the first time in a sign-in
 *      session, the ready rows become a `username_bulk` job, whose progress
 *      panel (BLT_Toolkit_Jobs::render_ui()) shows on this screen until a
 *      day after it ends, so closing the tab loses nothing.
 * - **Recent changes**: the usernames module's log rows. Someone who cannot
 *   view the activity log sees only the changes they made.
 *
 * The bulk tools need the Usernames module; while it is off the screen says
 * why and shows the recent changes only. Rendering reads and never writes
 * (RO-5): the plan is made by the upload's POST and only read here.
 */
class BLT_Toolkit_Admin_Usernames {

	/**
	 * admin-post.php actions (see BLT_Toolkit_Admin::post_actions()).
	 */
	const TEMPLATE_ACTION = 'blt_toolkit_bulk_template';
	const PLAN_ACTION     = 'blt_toolkit_bulk_plan';
	const START_ACTION    = 'blt_toolkit_bulk_start';
	const DISCARD_ACTION  = 'blt_toolkit_bulk_discard';

	/**
	 * Name of the file field of the upload form.
	 */
	const FILE_FIELD = 'blt_tk_bulk_csv';

	/**
	 * Name of the backup acknowledgment field.
	 */
	const ACK_FIELD = 'blt_tk_backup_ack';

	/**
	 * Dry-run rows per page.
	 */
	const PER_PAGE = 100;

	/**
	 * Log rows in the Recent changes card.
	 */
	const RECENT = 10;

	/**
	 * A finished job's panel stays on the screen this long.
	 */
	const SHOW_FINISHED = DAY_IN_SECONDS;

	/**
	 * Status filters of the dry-run table: key => planner status ('' for all).
	 */
	const SHOW = array(
		'all'       => '',
		'ready'     => 'ready',
		'error'     => 'error',
		'no_change' => 'no_change',
	);

	// ---------- Screen ----------

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$sub       = BLT_Toolkit_Admin::screen_title( 'usernames' );
		$actions   = array();
		$module_on = BLT_Toolkit_Plugin::is_active( 'usernames' );
		$user_id   = get_current_user_id();

		if ( current_user_can( 'manage_options' ) ) {
			$actions[] = array(
				'label' => __( 'Username settings', 'blt-toolkit' ),
				'url'   => BLT_Toolkit_Admin::page_url( 'settings', array( 'tab' => 'usernames' ) ),
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display state after our own redirect; the plan token is checked against the person's stored plan.
		$token = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : '';
		$show  = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'all';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$asked = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$show = isset( self::SHOW[ $show ] ) ? $show : 'all';

		$plan         = null;
		$plan_missing = false;
		$job_id       = '';
		$live         = null;
		$view         = null;

		if ( $module_on ) {
			$plan         = '' !== $token ? BLT_Toolkit_Username_Bulk::get_plan( $user_id, $token ) : null;
			$plan_missing = '' !== $token && null === $plan;
			$live         = BLT_Toolkit_Username_Bulk::live_job();
			$job_id       = $this->job_to_show( $asked );

			if ( null !== $plan && ! isset( $plan['error'] ) ) {
				$view = $this->plan_view( $plan, $show, $paged );
			}
		}

		$settings = array(
			'notify'          => (bool) BLT_Toolkit_Settings::get( 'usernames', 'notify_user' ),
			'sign_out_others' => (bool) BLT_Toolkit_Settings::get( 'usernames', 'sign_out_others' ),
		);

		$needs_ack    = $module_on && ! BLT_Toolkit_Username_Bulk::has_backup_ack();
		$max_rows     = $module_on ? BLT_Toolkit_Username_Bulk::max_rows() : 0;
		$max_upload   = $module_on ? self::max_upload() : 0;
		$recent       = $this->recent_changes();
		$activity     = new BLT_Toolkit_Admin_Activity();
		$can_view_log = current_user_can( 'blt_view_toolkit_log' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).

		$activity->prime_users( array_merge( wp_list_pluck( $recent, 'actor_id' ), wp_list_pluck( $recent, 'acting_as_id' ) ) );

		require BLT_TOOLKIT_DIR . 'admin/views/usernames.php';
	}

	/**
	 * Which job's panel to show: the one named in the URL (after a start or
	 * a panel action), else the newest bulk rename while it runs and for a
	 * day after it ends, so reopening the screen finds it (JOB-4).
	 *
	 * @param string $asked Job ID from the URL, or ''.
	 * @return string Job ID, or ''.
	 */
	private function job_to_show( $asked ) {
		$asked = BLT_Toolkit_Jobs::clean_id( $asked );

		if ( '' !== $asked ) {
			$job = BLT_Toolkit_Jobs::get( $asked );

			if ( null !== $job && BLT_Toolkit_Username_Bulk::JOB_TYPE === $job['type'] && BLT_Toolkit_Jobs::current_user_can_use( $job ) ) {
				return $job['id'];
			}
		}

		$job = BLT_Toolkit_Jobs::latest( BLT_Toolkit_Username_Bulk::JOB_TYPE );

		if ( null === $job || ! BLT_Toolkit_Jobs::current_user_can_use( $job ) ) {
			return '';
		}

		if ( BLT_Toolkit_Jobs::is_terminal( $job['status'] ) && time() - (int) $job['finished_at'] > self::SHOW_FINISHED ) {
			return '';
		}

		return $job['id'];
	}

	/**
	 * The dry run's page of rows for the status filter, with its numbers.
	 *
	 * @param array  $plan  Stored plan.
	 * @param string $show  Status filter key.
	 * @param int    $paged Page.
	 * @return array{rows:array,show:string,paged:int,pages:int,total:int,self:bool,switched:int}
	 */
	private function plan_view( array $plan, $show, $paged ) {
		$status   = self::SHOW[ $show ];
		$rows     = array();
		$self     = false;
		$switched = 0;

		foreach ( (array) $plan['rows'] as $row ) {
			if ( BLT_Toolkit_Rename_Planner::READY === $row['status'] ) {
				$self      = $self || isset( $row['warnings']['self'] );
				$switched += isset( $row['warnings']['switched'] ) ? 1 : 0;
			}

			if ( '' === $status || $status === $row['status'] ) {
				$rows[] = $row;
			}
		}

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( max( 1, (int) $paged ), $pages );

		return array(
			'rows'     => array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE ),
			'show'     => $show,
			'paged'    => $paged,
			'pages'    => $pages,
			'total'    => $total,
			'self'     => $self,
			'switched' => $switched,
		);
	}

	/**
	 * The newest usernames log rows: all of them for someone who may view the
	 * activity log, otherwise only the changes this person made.
	 *
	 * @return array[] Rows from BLT_Toolkit_Log::query().
	 */
	private function recent_changes() {
		$args = array(
			'module'   => 'usernames',
			'per_page' => self::RECENT,
		);

		if ( ! current_user_can( 'blt_view_toolkit_log' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			$args['actor'] = (string) get_current_user_id();
		}

		return BLT_Toolkit_Log::query( $args );
	}

	/**
	 * The URL of the dry run with a status filter and page.
	 *
	 * @param string $token Plan token.
	 * @param string $show  Status filter key.
	 * @param int    $paged Page.
	 * @return string
	 */
	public static function plan_url( $token, $show = 'all', $paged = 1 ) {
		$args = array( 'plan' => $token );

		if ( 'all' !== $show ) {
			$args['show'] = $show;
		}

		if ( $paged > 1 ) {
			$args['paged'] = (int) $paged;
		}

		return BLT_Toolkit_Admin::page_url( 'usernames', $args );
	}

	/**
	 * A plan row's status as a badge, escaped.
	 *
	 * @param string $status Planner status.
	 * @return string
	 */
	public static function status_badge( $status ) {
		switch ( $status ) {
			case BLT_Toolkit_Rename_Planner::READY:
				return BLT_Toolkit_Admin::badge( 'confirmed', __( 'Ready', 'blt-toolkit' ) );
			case BLT_Toolkit_Rename_Planner::ERROR:
				return BLT_Toolkit_Admin::badge( 'cancelled', __( 'Error', 'blt-toolkit' ) );
		}

		return BLT_Toolkit_Admin::badge( 'off', __( 'No change', 'blt-toolkit' ) );
	}

	// ---------- Handlers (BLT_Toolkit_Admin::route_post() checked method, capability and nonce) ----------

	/**
	 * Stream the template (UN-30 step 1): `user_id,username,new_username`
	 * with the new username empty, UTF-8 with a byte order mark (so Excel
	 * reads it as UTF-8), 500 users per query. Usernames are written exactly,
	 * without formula escaping, so the file uploads back unchanged
	 * (BLT_Toolkit_Csv::line()). The download is logged first.
	 *
	 * @return void
	 */
	public function handle_template() {
		$this->require_module();

		$actor = wp_get_current_user();

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'usernames',
				'action'      => 'usernames_exported',
				'object_type' => 'user',
				'summary'     => __( 'Downloaded the bulk rename template (every username they can see).', 'blt-toolkit' ),
			)
		);

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A long download on a site with many users.
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="blt-toolkit-usernames-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV built by BLT_Toolkit_Csv::line(); usernames must round-trip exactly.
		echo BLT_Toolkit_Csv::BOM;
		echo BLT_Toolkit_Csv::line( array( 'user_id', 'username', BLT_Toolkit_Csv::NEW_COLUMN ) );

		$page = 1;

		do {
			$batch = BLT_Toolkit_Username_Bulk::template_batch( $actor, $page, BLT_Toolkit_Username_Bulk::TEMPLATE_BATCH );

			foreach ( $batch['rows'] as $row ) {
				echo BLT_Toolkit_Csv::line( array( $row[0], $row[1], '' ) );
			}

			flush();

			++$page;
		} while ( $batch['fetched'] >= BLT_Toolkit_Username_Bulk::TEMPLATE_BATCH );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Read the uploaded CSV and plan it (UN-30 steps 2 and 3), then show the
	 * dry run. Writes nothing but the person's plan; a file that cannot be
	 * used is reported on the screen with the reason.
	 *
	 * @return void
	 */
	public function handle_plan() {
		$this->require_module();

		$user_id = get_current_user_id();
		$file    = $this->read_upload();

		if ( is_wp_error( $file ) ) {
			$token = BLT_Toolkit_Username_Bulk::store_plan( $user_id, array( 'error' => $file->get_error_message() ) );

			BLT_Toolkit_Admin::redirect( 'usernames', false === $token ? array() : array( 'plan' => $token ), false === $token ? 'failed' : '' );
		}

		$parsed = BLT_Toolkit_Csv::parse( $file['bytes'], BLT_Toolkit_Username_Bulk::max_rows() );

		if ( is_wp_error( $parsed ) ) {
			$token = BLT_Toolkit_Username_Bulk::store_plan(
				$user_id,
				array(
					'error' => $parsed->get_error_message(),
					'file'  => $file['name'],
				)
			);

			BLT_Toolkit_Admin::redirect( 'usernames', false === $token ? array() : array( 'plan' => $token ), false === $token ? 'failed' : '' );
		}

		$plan = BLT_Toolkit_Username_Bulk::plan( $parsed, wp_get_current_user() );

		$plan['file']      = $file['name'];
		$plan['delimiter'] = $parsed['delimiter'];
		$plan['id_column'] = isset( $parsed['id_column'] ) ? $parsed['id_column'] : '';
		$plan['blank']     = isset( $parsed['blank'] ) ? (int) $parsed['blank'] : 0;

		$token = BLT_Toolkit_Username_Bulk::store_plan( $user_id, $plan );

		if ( false === $token ) {
			BLT_Toolkit_Admin::redirect( 'usernames', array(), 'bulk_not_stored' );
		}

		BLT_Toolkit_Admin::redirect( 'usernames', array( 'plan' => $token ) );
	}

	/**
	 * Start the bulk rename of a dry run (UN-30 steps 5 and 6).
	 *
	 * The form names the plan by its token, so what starts is the dry run
	 * the person saw. The backup acknowledgment is required unless this
	 * sign-in session gave it already (CONVENTIONS 11).
	 *
	 * @return void
	 */
	public function handle_start() {
		$this->require_module();

		$user_id = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce checked in BLT_Toolkit_Admin::route_post().
		$token = isset( $_POST['plan'] ) ? sanitize_key( wp_unslash( $_POST['plan'] ) ) : '';
		$ack   = ! empty( $_POST[ self::ACK_FIELD ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$plan = BLT_Toolkit_Username_Bulk::get_plan( $user_id, $token );

		if ( null === $plan || isset( $plan['error'] ) ) {
			BLT_Toolkit_Admin::redirect( 'usernames', array(), 'bulk_expired' );
		}

		if ( ! BLT_Toolkit_Username_Bulk::has_backup_ack() ) {
			if ( ! $ack ) {
				BLT_Toolkit_Admin::redirect( 'usernames', array( 'plan' => $token ), 'bulk_ack' );
			}

			BLT_Toolkit_Username_Bulk::record_backup_ack();
		}

		$job_id = BLT_Toolkit_Username_Bulk::start( $plan );

		if ( is_wp_error( $job_id ) ) {
			switch ( $job_id->get_error_code() ) {
				case 'blt_toolkit_bulk_busy':
					$notice = 'bulk_busy';
					break;
				case 'blt_toolkit_bulk_nothing':
					$notice = 'bulk_nothing';
					break;
				default:
					$notice = 'bulk_not_started';
			}

			BLT_Toolkit_Admin::redirect( 'usernames', array( 'plan' => $token ), $notice );
		}

		BLT_Toolkit_Username_Bulk::discard_plan( $user_id );

		BLT_Toolkit_Admin::redirect( 'usernames', array( 'job' => $job_id ), 'bulk_started' );
	}

	/**
	 * Throw away the person's dry run.
	 *
	 * @return void
	 */
	public function handle_discard() {
		$this->require_module();

		BLT_Toolkit_Username_Bulk::discard_plan( get_current_user_id() );

		BLT_Toolkit_Admin::redirect( 'usernames', array(), 'bulk_discarded' );
	}

	/**
	 * Stop a handler when the Usernames module is not running: its classes
	 * must not load then (SPEC 4.1), and the job type is not registered.
	 *
	 * @return void
	 */
	private function require_module() {
		if ( ! BLT_Toolkit_Plugin::is_active( 'usernames' ) ) {
			BLT_Toolkit_Admin::redirect( 'usernames', array(), 'failed' );
		}
	}

	/**
	 * The uploaded CSV: its bytes and file name, or why it cannot be used.
	 * The file is read from PHP's temporary upload and never stored.
	 *
	 * @return array{bytes:string,name:string}|WP_Error
	 */
	private function read_upload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in BLT_Toolkit_Admin::route_post(); the upload's fields are checked one by one below.
		$upload = isset( $_FILES[ self::FILE_FIELD ] ) && is_array( $_FILES[ self::FILE_FIELD ] ) ? $_FILES[ self::FILE_FIELD ] : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$error = is_array( $upload ) && isset( $upload['error'] ) && is_numeric( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new WP_Error( 'blt_toolkit_bulk_no_file', __( 'Choose a CSV file to upload.', 'blt-toolkit' ) );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return $this->too_large();
		}

		$tmp  = isset( $upload['tmp_name'] ) && is_string( $upload['tmp_name'] ) ? $upload['tmp_name'] : '';
		$size = isset( $upload['size'] ) && is_numeric( $upload['size'] ) ? (int) $upload['size'] : 0;
		$name = isset( $upload['name'] ) && is_string( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';

		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'blt_toolkit_bulk_upload', __( 'The file could not be uploaded. Try again.', 'blt-toolkit' ) );
		}

		if ( $size > BLT_Toolkit_Username_Bulk::max_bytes() ) {
			return $this->too_large();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's temporary copy of the upload, checked with is_uploaded_file(); read once and never stored.
		$bytes = file_get_contents( $tmp );

		if ( ! is_string( $bytes ) ) {
			return new WP_Error( 'blt_toolkit_bulk_upload', __( 'The file could not be uploaded. Try again.', 'blt-toolkit' ) );
		}

		if ( strlen( $bytes ) > BLT_Toolkit_Username_Bulk::max_bytes() ) {
			return $this->too_large();
		}

		return array(
			'bytes' => $bytes,
			'name'  => $name,
		);
	}

	/**
	 * The error for a file larger than this screen or PHP accepts, in bytes
	 * (UN-30 step 2): PHP's upload limit and the bulk rename's own byte
	 * limit both stop a file by its size, whatever its number of rows.
	 *
	 * @return WP_Error
	 */
	private function too_large() {
		return new WP_Error(
			'blt_toolkit_bulk_too_large',
			sprintf(
				/* translators: %s: the largest file accepted, such as 2 MB. */
				__( 'The file is too large: it can be at most %s. Split it into smaller files.', 'blt-toolkit' ),
				size_format( self::max_upload() )
			)
		);
	}

	/**
	 * The largest CSV accepted, in bytes: the smaller of what PHP and
	 * WordPress let this server receive (wp_max_upload_size()) and the bulk
	 * rename's own limit.
	 *
	 * @return int
	 */
	public static function max_upload() {
		$php = (int) wp_max_upload_size();

		return $php > 0 ? min( $php, BLT_Toolkit_Username_Bulk::max_bytes() ) : BLT_Toolkit_Username_Bulk::max_bytes();
	}

	/**
	 * Whether this request is a POST whose body PHP threw away because it
	 * was larger than `post_max_size`: no fields, no files, and a
	 * Content-Length over the limit. The upload form names its action in
	 * the URL, so it still reaches the router (UN-30 step 2).
	 *
	 * @return bool
	 */
	public static function body_dropped() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only checks that the body is empty; nothing in it is used.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
		$limit  = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

		return $limit > 0 && $length > $limit;
	}
}
