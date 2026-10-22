<?php
/**
 * Tools screen: the tab shell, Snapshots (SNAP-4), Reset (RO-43) and Sync
 * administrator (RO-5), and their admin-post.php handlers.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Tools screen (SPEC 13.2). Needs `blt_reset_roles`; the "Import from
 * legacy plugins" tab also needs `manage_options` (SPEC 13.1).
 *
 * Tabs are links, one URL each (`tab=`). This class draws the page (header,
 * tab bar, the one-time notice of the last POST) and each tab:
 *
 * - `snapshots`: the list of role snapshots, newest first, paged, with time
 *   (site timezone), who, why, and how many roles and capabilities differ
 *   from the roles today (worked out for the page shown only). Each can be
 *   viewed as a diff (`step=view`: what changed since it was taken) and
 *   restored (`step=restore`: a dry run of the restore, which also says what
 *   Administrator gets beyond the snapshot, then confirm).
 * - `reset`: the two resets of RO-43 (`mode=builtin|full`), each previewed
 *   in full before the form that needs the typed word RESET.
 * - `sync`: the registered capabilities Administrator lacks, granted on
 *   confirm (RO-5).
 * - `transfer` and `legacy`: drawn by BLT_Toolkit_Admin_Transfer::render()
 *   (RO-44, RO-45) and BLT_Toolkit_Admin_Legacy::render() (SPEC 15), static,
 *   printing the tab's body inside this page's `.wrap`, after the tab bar.
 *   Their POSTs are routed to those classes' static handlers (the `class`
 *   key of BLT_Toolkit_Admin::post_actions()).
 *
 * Every write is a POST routed by BLT_Toolkit_Admin::route_post() (method,
 * capability, nonce) to handle_restore(), handle_reset() or handle_sync(),
 * which check again that the roles are what the preview showed, run the
 * Guard through the role store, and redirect back with a one-time notice
 * (post / redirect / get, SEC-7). Rendering any tab writes no role (RO-5):
 * previews are dry runs of the role store.
 */
class BLT_Toolkit_Admin_Tools {

	/**
	 * Query argument naming a snapshot.
	 */
	const SNAPSHOT_ARG = 'snapshot';

	/**
	 * Query argument of a snapshot's sub-page (`view` or `restore`). Not
	 * `action`, which wp-admin/admin.php turns into an `admin_action_` hook.
	 */
	const STEP_ARG = 'step';

	/**
	 * Query argument and field of the reset mode.
	 */
	const MODE_ARG = 'mode';

	/**
	 * Query argument of the snapshot list's page.
	 */
	const PAGE_ARG = 'paged';

	/**
	 * Snapshots per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Query argument carrying the one-time notice's token.
	 */
	const NOTICE_ARG = 'blt_tn';

	/**
	 * Transient prefix (plus the user ID) of the one-time notice.
	 */
	const NOTICE_TRANSIENT = 'blt_toolkit_tools_notice_';

	/**
	 * Field of the SEC-10 typed role names: role ID => typed name.
	 */
	const CONFIRM_FIELD = 'confirm_name';

	/**
	 * Field of the SEC-13 confirmation.
	 */
	const LOCKOUT_FIELD = 'confirm_lockout';

	/**
	 * Field of the typed RESET (RO-43).
	 */
	const WORD_FIELD = 'confirm_reset';

	/**
	 * Field carrying the hash of what the preview showed.
	 */
	const EXPECTED_FIELD = 'expected';

	/**
	 * admin-post.php actions of this class (routed by BLT_Toolkit_Admin).
	 */
	const RESTORE_ACTION = 'blt_toolkit_snapshot_restore';
	const RESET_ACTION   = 'blt_toolkit_roles_reset';
	const SYNC_ACTION    = 'blt_toolkit_sync_admin';

	/**
	 * Tabs drawn by a class of their own: tab => class with a static render().
	 */
	const DELEGATES = array(
		'transfer' => 'BLT_Toolkit_Admin_Transfer',
		'legacy'   => 'BLT_Toolkit_Admin_Legacy',
	);

	// ---------- Tabs ----------

	/**
	 * Tabs the current user may open, key => label.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		$tabs = array(
			'snapshots' => __( 'Snapshots', 'blt-toolkit' ),
			'transfer'  => __( 'Export and import', 'blt-toolkit' ),
			'reset'     => __( 'Reset', 'blt-toolkit' ),
			'sync'      => __( 'Sync administrator', 'blt-toolkit' ),
		);

		if ( current_user_can( 'manage_options' ) ) {
			$tabs['legacy'] = __( 'Import from legacy plugins', 'blt-toolkit' );
		}

		return $tabs;
	}

	/**
	 * The open tab. An unknown tab, or one the user may not open, falls back
	 * to the first.
	 *
	 * @return string
	 */
	public function current_tab() {
		$tabs = $this->tabs();
		$tab  = sanitize_key( self::arg( 'tab' ) );

		return isset( $tabs[ $tab ] ) ? $tab : (string) key( $tabs );
	}

	/**
	 * A Tools URL for a tab, with optional query arguments.
	 *
	 * @param string $tab  Tab key.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab, array $args = array() ) {
		return BLT_Toolkit_Admin::page_url( 'tools', array_merge( array( 'tab' => (string) $tab ), $args ) );
	}

	// ---------- Rendering ----------

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$tabs = $this->tabs();
		$tab  = $this->current_tab();

		?>
		<div class="wrap blt-ui blt-toolkit blt-toolkit-tools">
			<?php BLT_Toolkit_Admin::header( BLT_Toolkit_Admin::screen_title( 'tools' ) ); ?>
			<?php BLT_Toolkit_Admin::tabs( 'tools', $tabs, $tab, __( 'Tools sections', 'blt-toolkit' ) ); ?>
			<?php self::print_notice(); ?>
			<?php $this->render_tab( $tab ); ?>
		</div>
		<?php
	}

	/**
	 * Draw one tab's body.
	 *
	 * @param string $tab Tab key.
	 * @return void
	 */
	private function render_tab( $tab ) {
		if ( isset( self::DELEGATES[ $tab ] ) ) {
			call_user_func( array( self::DELEGATES[ $tab ], 'render' ) );

			return;
		}

		switch ( $tab ) {
			case 'reset':
				self::view( 'tools-reset', $this->reset_data() );
				return;

			case 'sync':
				self::view( 'tools-sync', $this->sync_data() );
				return;
		}

		$step = sanitize_key( self::arg( self::STEP_ARG ) );
		$id   = absint( self::arg( self::SNAPSHOT_ARG ) );

		if ( $id > 0 && 'view' === $step ) {
			self::view( 'tools-snapshot-view', $this->snapshot_view_data( $id ) );
			return;
		}

		if ( $id > 0 && 'restore' === $step ) {
			self::view( 'tools-snapshot-restore', $this->restore_data( $id ) );
			return;
		}

		self::view( 'tools-snapshots', $this->snapshots_data() );
	}

	/**
	 * Data for the snapshot list (SNAP-4). Reads only; the counts are worked
	 * out for the rows of this page.
	 *
	 * @return array
	 */
	private function snapshots_data() {
		$total = BLT_Toolkit_Snapshots::count();
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $pages, max( 1, absint( self::arg( self::PAGE_ARG ) ) ) );
		$rows  = BLT_Toolkit_Snapshots::list(
			array(
				'per_page' => self::PER_PAGE,
				'paged'    => $paged,
			)
		);

		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['when']        = self::site_time( $row['created_at'] );
			$rows[ $i ]['ago']         = self::ago( $row['created_at'] );
			$rows[ $i ]['who']         = self::actor_label( $row['actor_id'], $row['actor'] );
			$rows[ $i ]['view_url']    = self::url(
				'snapshots',
				array(
					self::SNAPSHOT_ARG => $row['id'],
					self::STEP_ARG     => 'view',
				)
			);
			$rows[ $i ]['restore_url'] = self::url(
				'snapshots',
				array(
					self::SNAPSHOT_ARG => $row['id'],
					self::STEP_ARG     => 'restore',
				)
			);
		}

		return array(
			'rows'      => $rows,
			'total'     => $total,
			'paged'     => $paged,
			'pages'     => $pages,
			'newer_url' => $paged > 1 ? self::url( 'snapshots', $paged > 2 ? array( self::PAGE_ARG => $paged - 1 ) : array() ) : '',
			'older_url' => $paged < $pages ? self::url( 'snapshots', array( self::PAGE_ARG => $paged + 1 ) ) : '',
			'retention' => BLT_Toolkit_Snapshots::retention(),
		);
	}

	/**
	 * Data for "what changed since this snapshot": Role_Diff from the
	 * snapshot's roles to the roles today. Reads only.
	 *
	 * @param int $id Snapshot ID.
	 * @return array
	 */
	private function snapshot_view_data( $id ) {
		$snapshot = BLT_Toolkit_Snapshots::get( $id );
		$data     = array(
			'snapshot'    => null,
			'diff_html'   => '',
			'counts'      => null,
			'list_url'    => self::url( 'snapshots' ),
			'restore_url' => self::url(
				'snapshots',
				array(
					self::SNAPSHOT_ARG => $id,
					self::STEP_ARG     => 'restore',
				)
			),
		);

		if ( null === $snapshot ) {
			return $data;
		}

		$data['snapshot'] = self::describe_snapshot( $snapshot );

		if ( $snapshot['valid'] ) {
			$diff = BLT_Toolkit_Role_Diff::diff( $snapshot['roles'], (array) wp_roles()->roles );

			$data['counts']    = BLT_Toolkit_Role_Diff::counts( $diff );
			$data['diff_html'] = self::diff_html( $diff );
		}

		return $data;
	}

	/**
	 * Data for the restore page: the dry run (BLT_Toolkit_Snapshots::
	 * restore_plan()), rendered. Writes nothing.
	 *
	 * @param int $id Snapshot ID.
	 * @return array
	 */
	private function restore_data( $id ) {
		$plan = BLT_Toolkit_Snapshots::restore_plan( $id );
		$data = array(
			'error'     => '',
			'plan'      => null,
			'snapshot'  => null,
			'diff_html' => '',
			'critical'  => array(),
			'list_url'  => self::url( 'snapshots' ),
			'view_url'  => self::url(
				'snapshots',
				array(
					self::SNAPSHOT_ARG => $id,
					self::STEP_ARG     => 'view',
				)
			),
		);

		if ( is_wp_error( $plan ) ) {
			$data['error'] = $plan->get_error_message();

			return $data;
		}

		$data['plan']      = $plan;
		$data['snapshot']  = self::describe_snapshot( $plan['snapshot'] );
		$data['diff_html'] = $plan['unchanged'] ? '' : self::diff_html( $plan['diff'] );
		$data['critical']  = self::critical_rows( $plan['critical'], $plan['names'] );

		return $data;
	}

	/**
	 * Data for the Reset tab: both modes described, the chosen one planned
	 * by a dry run (BLT_Toolkit_Reset::plan()). Writes nothing.
	 *
	 * @return array
	 */
	private function reset_data() {
		$mode = BLT_Toolkit_Reset::mode( self::arg( self::MODE_ARG ) );
		$plan = BLT_Toolkit_Reset::plan( $mode );
		$data = array(
			'mode'      => $mode,
			'modes'     => array(
				BLT_Toolkit_Reset::MODE_BUILTIN => array(
					'title' => __( 'Reset built-in roles\' capabilities', 'blt-toolkit' ),
					'desc'  => __( 'Administrator, Editor, Author, Contributor and Subscriber get WordPress\'s default capabilities back, keeping their names. Custom roles are left as they are.', 'blt-toolkit' ),
					'url'   => self::url( 'reset' ),
				),
				BLT_Toolkit_Reset::MODE_FULL    => array(
					'title' => __( 'Full reset', 'blt-toolkit' ),
					'desc'  => __( 'Every role becomes exactly what a fresh WordPress install creates. Custom roles are deleted; people who had them keep only their other roles.', 'blt-toolkit' ),
					'url'   => self::url( 'reset', array( self::MODE_ARG => BLT_Toolkit_Reset::MODE_FULL ) ),
				),
			),
			'error'     => '',
			'plan'      => null,
			'diff_html' => '',
			'critical'  => array(),
			'sync_url'  => self::url( 'sync' ),
		);

		if ( is_wp_error( $plan ) ) {
			$data['error'] = $plan->get_error_message();

			return $data;
		}

		$data['plan']      = $plan;
		$data['diff_html'] = $plan['unchanged'] ? '' : self::diff_html( $plan['diff'] );
		$data['critical']  = self::critical_rows( $plan['critical'], $plan['names'] );

		return $data;
	}

	/**
	 * Data for the Sync administrator tab (BLT_Toolkit_Sync::plan()). Writes
	 * nothing.
	 *
	 * @return array
	 */
	private function sync_data() {
		$data = array(
			'available' => BLT_Toolkit_Sync::available(),
			'error'     => '',
			'plan'      => null,
			'grant'     => array(),
			'denied'    => array(),
		);

		if ( ! $data['available'] ) {
			return $data;
		}

		$plan = BLT_Toolkit_Sync::plan();

		if ( is_wp_error( $plan ) ) {
			$data['error'] = $plan->get_error_message();

			return $data;
		}

		$data['plan']   = $plan;
		$data['grant']  = self::cap_rows( $plan['grant'] );
		$data['denied'] = self::cap_rows( $plan['denied'] );

		return $data;
	}

	// ---------- POST handlers ----------

	/**
	 * Restore a snapshot (SNAP-4), after BLT_Toolkit_Admin::route_post()
	 * checked the method, `blt_reset_roles` and the nonce.
	 *
	 * The plan is worked out again and must hash to what the preview showed;
	 * the Guard's refusal, a SEC-10 name not typed or a SEC-13 confirmation
	 * not given send the person back to the preview with the reason. Then
	 * BLT_Toolkit_Snapshots::restore() writes through the role store (a
	 * `before_restore` snapshot first) and the notice names that snapshot,
	 * which undoes the restore.
	 *
	 * @return void
	 */
	public function handle_restore() {
		$id   = absint( self::posted( self::SNAPSHOT_ARG ) );
		$page = array(
			self::SNAPSHOT_ARG => $id,
			self::STEP_ARG     => 'restore',
		);
		$plan = BLT_Toolkit_Snapshots::restore_plan( $id, array( 'count' => false ) );

		if ( is_wp_error( $plan ) ) {
			self::back( 'snapshots', array(), 'error', self::nothing( $plan ) );
		}

		if ( $plan['in_code'] ) {
			self::back( 'snapshots', $page, 'error', __( 'Nothing was restored: this site defines its roles in code ($wp_user_roles), so they cannot be restored from here.', 'blt-toolkit' ) );
		}

		if ( $plan['unchanged'] ) {
			self::back( 'snapshots', array(), 'warning', __( 'Nothing was restored: your roles already match this snapshot.', 'blt-toolkit' ) );
		}

		$checked = self::confirmations( $plan );

		if ( is_wp_error( $checked ) ) {
			self::back( 'snapshots', $page, 'error', self::nothing( $checked ) );
		}

		$result = BLT_Toolkit_Snapshots::restore(
			$id,
			$checked['lockout'],
			array(
				'confirmed_critical' => true,
				'expected'           => $plan['expected'],
			)
		);

		if ( is_wp_error( $result ) ) {
			self::back( 'snapshots', $page, 'error', self::nothing( $result ) );
		}

		self::leave_if_locked_out();

		self::back(
			'snapshots',
			array(),
			'success',
			sprintf(
				/* translators: 1: number of the snapshot restored, 2: number of the snapshot taken just before. */
				__( 'Snapshot %1$d was restored. Your roles as they were just before are saved as snapshot %2$d: restore that one to undo this.', 'blt-toolkit' ),
				$id,
				(int) $result
			),
			array(),
			self::undo_link( (int) $result )
		);
	}

	/**
	 * Reset roles (RO-43), after BLT_Toolkit_Admin::route_post() checked the
	 * method, `blt_reset_roles` and the nonce.
	 *
	 * Without the typed word RESET nothing else is looked at. Then the same
	 * checks as a restore: the plan must hash to what the preview showed, the
	 * Guard must agree, SEC-10's names must be typed and SEC-13 confirmed.
	 * BLT_Toolkit_Reset::run() writes through the role store (a
	 * `before_reset` snapshot first).
	 *
	 * @return void
	 */
	public function handle_reset() {
		$mode  = BLT_Toolkit_Reset::mode( self::posted( self::MODE_ARG ) );
		$page  = BLT_Toolkit_Reset::MODE_FULL === $mode ? array( self::MODE_ARG => $mode ) : array();
		$typed = self::posted( self::WORD_FIELD );

		if ( ! BLT_Toolkit_Reset::word_confirmed( $typed ) ) {
			self::back(
				'reset',
				$page,
				'error',
				/* translators: %s: the word to type, RESET. */
				sprintf( __( 'Nothing was reset: type %s in capitals to confirm.', 'blt-toolkit' ), BLT_Toolkit_Reset::WORD )
			);
		}

		// The people counts are for the preview; the confirm needs none (SEC-9).
		$plan = BLT_Toolkit_Reset::plan( $mode, array( 'count' => false ) );

		if ( is_wp_error( $plan ) ) {
			self::back( 'reset', $page, 'error', self::nothing( $plan ) );
		}

		if ( $plan['in_code'] ) {
			self::back( 'reset', $page, 'error', __( 'Nothing was reset: this site defines its roles in code ($wp_user_roles), so they cannot be reset from here.', 'blt-toolkit' ) );
		}

		if ( $plan['unchanged'] ) {
			self::back( 'reset', $page, 'warning', __( 'Nothing was reset: the roles already match WordPress\'s defaults.', 'blt-toolkit' ) );
		}

		$checked = self::confirmations( $plan );

		if ( is_wp_error( $checked ) ) {
			self::back( 'reset', $page, 'error', self::nothing( $checked ) );
		}

		$result = BLT_Toolkit_Reset::run(
			$mode,
			$typed,
			array(
				'expected'           => $plan['expected'],
				'confirmed_critical' => true,
				'confirmed_lockout'  => $checked['lockout'],
			)
		);

		if ( is_wp_error( $result ) ) {
			self::back( 'reset', $page, 'error', self::nothing( $result ) );
		}

		self::leave_if_locked_out();

		$before = (int) $result['snapshot_id'];

		self::back(
			'snapshots',
			array(),
			'success',
			sprintf(
				BLT_Toolkit_Reset::MODE_FULL === $mode
					/* translators: %d: number of the snapshot taken just before the reset. */
					? __( 'Every role was reset to WordPress\'s defaults. Your roles as they were just before are saved as snapshot %d: restore that one to undo the reset.', 'blt-toolkit' )
					/* translators: %d: number of the snapshot taken just before the reset. */
					: __( 'The built-in roles\' capabilities were reset to WordPress\'s defaults. Your roles as they were just before are saved as snapshot %d: restore that one to undo the reset.', 'blt-toolkit' ),
				$before
			),
			array(),
			self::undo_link( $before )
		);
	}

	/**
	 * Sync administrator (RO-5), after BLT_Toolkit_Admin::route_post()
	 * checked the method, `blt_reset_roles` and the nonce.
	 * BLT_Toolkit_Sync::run() grants exactly what the preview listed, or
	 * nothing.
	 *
	 * @return void
	 */
	public function handle_sync() {
		$result = BLT_Toolkit_Sync::run( self::posted( self::EXPECTED_FIELD ) );

		if ( is_wp_error( $result ) ) {
			self::back( 'sync', array(), 'error', self::nothing( $result ) );
		}

		$granted = isset( $result['granted'] ) ? (array) $result['granted'] : array();

		self::back(
			'sync',
			array(),
			'success',
			sprintf(
				/* translators: %s: number of capabilities. */
				_n( 'The Administrator role was given %s capability:', 'The Administrator role was given %s capabilities:', count( $granted ), 'blt-toolkit' ),
				number_format_i18n( count( $granted ) )
			),
			$granted,
			self::undo_link( (int) $result['snapshot_id'] )
		);
	}

	/**
	 * The confirmations a reviewed write needs, checked against the plan:
	 * the roles still hash to what the preview showed, the Guard agreed,
	 * every SEC-10 role name was typed, and SEC-13 was confirmed when asked.
	 *
	 * @param array $plan restore_plan() or BLT_Toolkit_Reset::plan().
	 * @return array{lockout:bool}|WP_Error
	 */
	private static function confirmations( array $plan ) {
		$expected = strtolower( self::posted( self::EXPECTED_FIELD ) );

		if ( '' === $expected || ! hash_equals( (string) $plan['expected'], $expected ) ) {
			return BLT_Toolkit_Snapshots::changed_error();
		}

		if ( is_wp_error( $plan['guard'] ) ) {
			return $plan['guard'];
		}

		if ( ! BLT_Toolkit_Snapshots::names_confirmed( $plan['names'], self::posted_map( self::CONFIRM_FIELD ) ) ) {
			return new WP_Error(
				'blt_toolkit_critical_review',
				__( 'This grants Critical capabilities: type each role\'s name exactly as shown to confirm.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		$lockout = '1' === self::posted( self::LOCKOUT_FIELD );

		if ( $plan['lockout'] && ! $lockout ) {
			return new WP_Error(
				'blt_toolkit_self_lockout',
				__( 'This takes away your own ability to edit roles: confirm that you understand to continue.', 'blt-toolkit' ),
				array( 'status' => 409 )
			);
		}

		return array( 'lockout' => $lockout );
	}

	/**
	 * After a write that took the person's own access to this screen away
	 * (a restore of a state in which they lacked it, or a confirmed SEC-13),
	 * go to the dashboard instead of a page that would refuse them.
	 *
	 * @return void
	 */
	private static function leave_if_locked_out() {
		if ( ! current_user_can( 'blt_reset_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * The "undo" link of a notice: the restore page of the snapshot taken
	 * before a write.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return array{url:string,label:string}|array
	 */
	private static function undo_link( $snapshot_id ) {
		if ( $snapshot_id <= 0 ) {
			return array();
		}

		return array(
			'url'   => self::url(
				'snapshots',
				array(
					self::SNAPSHOT_ARG => $snapshot_id,
					self::STEP_ARG     => 'restore',
				)
			),
			/* translators: %d: snapshot number. */
			'label' => sprintf( __( 'Preview restoring snapshot %d', 'blt-toolkit' ), $snapshot_id ),
		);
	}

	/**
	 * "Nothing was changed: {reason}".
	 *
	 * @param WP_Error $error The refusal.
	 * @return string
	 */
	private static function nothing( WP_Error $error ) {
		return sprintf(
			/* translators: %s: the reason. */
			__( 'Nothing was changed: %s', 'blt-toolkit' ),
			$error->get_error_message()
		);
	}

	// ---------- One-time notice ----------

	/**
	 * Store a one-time notice for the person and redirect to a tab. Stops.
	 *
	 * The URL carries only a random token; the message (which can be the
	 * Guard's exact reason) stays on the server for ten minutes and is read
	 * once. Other Tools tabs may use this too.
	 *
	 * @param string $tab     Tab key.
	 * @param array  $args    Query arguments.
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Message.
	 * @param array  $items   Optional list under the message.
	 * @param array  $link    Optional array( 'url', 'label' ) shown after it.
	 * @return void
	 */
	public static function back( $tab, array $args, $type, $message, array $items = array(), array $link = array() ) {
		$token = strtolower( wp_generate_password( 12, false ) );

		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'token'   => $token,
				'type'    => $type,
				'message' => (string) $message,
				'items'   => array_values( array_map( 'strval', $items ) ),
				'link'    => isset( $link['url'], $link['label'] ) ? array(
					'url'   => (string) $link['url'],
					'label' => (string) $link['label'],
				) : array(),
			),
			10 * MINUTE_IN_SECONDS
		);

		$args[ self::NOTICE_ARG ] = $token;

		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	/**
	 * Print the one-time notice the URL names, if it is the person's own.
	 * Reading it deletes it.
	 *
	 * @return void
	 */
	public static function print_notice() {
		$token = sanitize_key( self::arg( self::NOTICE_ARG ) );

		if ( '' === $token ) {
			return;
		}

		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || ! isset( $notice['token'], $notice['type'], $notice['message'] ) || ! hash_equals( (string) $notice['token'], $token ) ) {
			return;
		}

		delete_transient( $key );

		self::view(
			'tools-notice',
			array(
				'type'    => in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'error',
				'message' => (string) $notice['message'],
				'items'   => isset( $notice['items'] ) && is_array( $notice['items'] ) ? $notice['items'] : array(),
				'link'    => isset( $notice['link']['url'], $notice['link']['label'] ) ? $notice['link'] : array(),
			)
		);
	}

	// ---------- Presentation helpers ----------

	/**
	 * A roles diff as escaped HTML, drawn as on the Activity screen.
	 *
	 * @param array $diff BLT_Toolkit_Role_Diff::diff() result.
	 * @return string
	 */
	public static function diff_html( array $diff ) {
		$activity = new BLT_Toolkit_Admin_Activity();

		return $activity->diff_html( $diff );
	}

	/**
	 * A snapshot's metadata for display: number, time in the site's
	 * timezone, who, why.
	 *
	 * @param array $snapshot BLT_Toolkit_Snapshots::get() or a plan's `snapshot`.
	 * @return array{id:int,when:string,ago:string,who:string,reason:string,is_baseline:bool,valid:bool}
	 */
	private static function describe_snapshot( array $snapshot ) {
		$actor = isset( $snapshot['actor_id'] ) ? (int) $snapshot['actor_id'] : 0;
		$user  = $actor > 0 ? get_userdata( $actor ) : false;
		$name  = $user instanceof WP_User ? ( '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login ) : '';

		return array(
			'id'          => (int) $snapshot['id'],
			'when'        => self::site_time( (string) $snapshot['created_at'] ),
			'ago'         => self::ago( (string) $snapshot['created_at'] ),
			'who'         => self::actor_label( $actor, $name ),
			'reason'      => BLT_Toolkit_Snapshots::reason_label( isset( $snapshot['reason'] ) ? (string) $snapshot['reason'] : '' ),
			'is_baseline' => ! empty( $snapshot['is_baseline'] ),
			'valid'       => ! isset( $snapshot['valid'] ) || ! empty( $snapshot['valid'] ),
		);
	}

	/**
	 * Who took a snapshot, in words.
	 *
	 * @param int    $actor_id User ID, 0 for none.
	 * @param string $name     Display name, '' when the user is gone.
	 * @return string
	 */
	private static function actor_label( $actor_id, $name ) {
		if ( (int) $actor_id <= 0 ) {
			return __( 'No one signed in (activation, WP-CLI or a scheduled task)', 'blt-toolkit' );
		}

		if ( '' === (string) $name ) {
			/* translators: %d: user ID. */
			return sprintf( __( 'Deleted user #%d', 'blt-toolkit' ), (int) $actor_id );
		}

		return (string) $name;
	}

	/**
	 * A stored UTC time in the site's timezone and formats.
	 *
	 * @param string $utc `Y-m-d H:i:s` in UTC.
	 * @return string
	 */
	private static function site_time( $utc ) {
		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return (string) $utc;
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * How long ago a stored UTC time was.
	 *
	 * @param string $utc `Y-m-d H:i:s` in UTC.
	 * @return string
	 */
	private static function ago( $utc ) {
		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		/* translators: %s: a span of time, such as "3 hours". */
		return sprintf( __( '%s ago', 'blt-toolkit' ), human_time_diff( $timestamp, time() ) );
	}

	/**
	 * Rows for a capability list: name, label and tier. Labels come from the
	 * capability catalog while the Roles module runs (it is that module's
	 * code); otherwise the raw name stands in.
	 *
	 * @param string[] $caps Capability names.
	 * @return array[] Each array( 'cap', 'label', 'tier', 'source' ).
	 */
	private static function cap_rows( array $caps ) {
		$catalog = BLT_Toolkit_Plugin::is_active( 'roles' );
		$entries = $catalog ? BLT_Toolkit_Capability_Catalog::all() : array();
		$out     = array();

		foreach ( $caps as $cap ) {
			$cap   = (string) $cap;
			$out[] = array(
				'cap'    => $cap,
				'label'  => $catalog ? BLT_Toolkit_Capability_Catalog::label( $cap ) : $cap,
				'tier'   => BLT_Toolkit_Caps::tier( $cap ),
				'source' => isset( $entries[ $cap ]['source_label'] ) ? (string) $entries[ $cap ]['source_label'] : '',
			);
		}

		return $out;
	}

	/**
	 * SEC-10 rows per role: the role's name to type and each Critical
	 * capability with what it lets people do.
	 *
	 * @param array $critical role => Critical capabilities granted.
	 * @param array $names    role => array( 'label', 'accept' ).
	 * @return array[] Each array( 'role', 'label', 'caps' => array of array( 'cap', 'label', 'consequence' ) ).
	 */
	private static function critical_rows( array $critical, array $names ) {
		$out = array();

		foreach ( $critical as $role => $caps ) {
			$rows = array();

			foreach ( self::cap_rows( (array) $caps ) as $row ) {
				$rows[] = $row + array( 'consequence' => self::consequence( $row['cap'] ) );
			}

			$out[] = array(
				'role'  => (string) $role,
				'label' => isset( $names[ $role ]['label'] ) ? (string) $names[ $role ]['label'] : (string) $role,
				'caps'  => $rows,
			);
		}

		return $out;
	}

	/**
	 * What a Critical capability lets its holders do (SEC-10): the Roles
	 * screen's wording while that screen's class is loaded (the Roles module
	 * runs), otherwise the general sentence.
	 *
	 * @param string $cap Capability.
	 * @return string
	 */
	private static function consequence( $cap ) {
		if ( class_exists( 'BLT_Toolkit_Admin_Roles', false ) && is_callable( array( 'BLT_Toolkit_Admin_Roles', 'consequence' ) ) ) {
			return BLT_Toolkit_Admin_Roles::consequence( $cap );
		}

		return __( 'Can be used to take over the site or other people\'s accounts.', 'blt-toolkit' );
	}

	/**
	 * A tier badge, escaped.
	 *
	 * @param string $tier `critical`, `elevated` or `standard`.
	 * @return string
	 */
	public static function tier_badge( $tier ) {
		switch ( $tier ) {
			case BLT_Toolkit_Caps::CRITICAL:
				return BLT_Toolkit_Admin::badge( 'cancelled', __( 'Critical', 'blt-toolkit' ) );
			case BLT_Toolkit_Caps::ELEVATED:
				return BLT_Toolkit_Admin::badge( 'pending', __( 'Elevated', 'blt-toolkit' ) );
		}

		return BLT_Toolkit_Admin::badge( 'off', __( 'Standard', 'blt-toolkit' ) );
	}

	// ---------- Request reading ----------

	/**
	 * A scalar GET value as plain text, or ''.
	 *
	 * @param string $key Query argument.
	 * @return string
	 */
	private static function arg( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Chooses what to display; nothing is written.
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * A scalar POST value as plain text, or ''. The router checked the nonce.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function posted( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- BLT_Toolkit_Admin::route_post() checked the nonce.
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * A POST field that maps keys to text (role ID => typed name), each
	 * value plain text; anything else is dropped.
	 *
	 * @param string $key Field.
	 * @return array<string,string>
	 */
	private static function posted_map( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by the router; each value is sanitized below.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$out = array();

		foreach ( $raw as $role => $value ) {
			if ( is_scalar( $value ) ) {
				$out[ (string) $role ] = sanitize_text_field( (string) $value );
			}
		}

		return $out;
	}

	/**
	 * Include a Tools view with its data as variables.
	 *
	 * @param string $blt_tk_view View file name, without `.php`.
	 * @param array  $blt_tk_data Variables for the template.
	 * @return void
	 */
	private static function view( $blt_tk_view, array $blt_tk_data ) {
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables, documented at the top of each view.
		extract( $blt_tk_data, EXTR_SKIP );

		require BLT_TOOLKIT_DIR . 'admin/views/' . $blt_tk_view . '.php';
	}
}
