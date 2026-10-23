<?php
/**
 * Tools > Import from legacy plugins: the tab's body and its admin-post.php
 * handlers.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Import from legacy plugins" tab of the Tools screen (SPEC 13.2, 15).
 * Needs `manage_options` as well as the screen's `blt_reset_roles` (SPEC
 * 13.1); BLT_Toolkit_Admin_Tools::tabs() hides it from everyone else and
 * every handler checks both.
 *
 * BLT_Toolkit_Admin_Tools draws the page (header, tab bar, the one-time
 * notice) and calls render(), which prints the tab's body inside the page's
 * `.wrap`:
 *
 * - No `source`: a card per plugin (User Switching, Username Changer, User
 *   Role Editor) saying whether it is active, whether its data is here, and
 *   what was imported and when, with a link to its preview.
 * - `source=uc` / `source=ure`: the dry run of that plugin's import
 *   (BLT_Toolkit_Legacy::uc_plan() / ure_plan()), a toggle per item, and the
 *   Import button; for User Role Editor also the dry run of removing its
 *   `ure_*` capabilities, with its own button. The audit log job's progress
 *   panel shows on the Username Changer page while it runs.
 *
 * Every write is a POST routed by BLT_Toolkit_Admin::route_post() (method,
 * `manage_options`, nonce) to a static handler here, which checks
 * `blt_reset_roles`, has the importer work the plan out again and compare it
 * with the preview's hash, and redirects back with a one-time notice (post /
 * redirect / get, SEC-7). Rendering writes nothing (RO-5): every preview is
 * a plan and the role changes in it are the role store's dry runs. The
 * source plugins' options and tables are only ever read.
 */
class BLT_Toolkit_Admin_Legacy {

	/**
	 * The tab's key on the Tools screen.
	 */
	const TAB = 'legacy';

	/**
	 * Query argument naming the plugin whose import is previewed.
	 */
	const SOURCE_ARG = 'source';

	/**
	 * admin-post.php actions (BLT_Toolkit_Admin::post_actions()).
	 */
	const UC_ACTION     = 'blt_toolkit_legacy_import_uc';
	const URE_ACTION    = 'blt_toolkit_legacy_import_ure';
	const REMOVE_ACTION = 'blt_toolkit_legacy_remove_ure_caps';

	/**
	 * Field of the chosen items: item => 1.
	 */
	const ITEMS_FIELD = 'items';

	/**
	 * Field carrying the hash of what the preview showed.
	 */
	const EXPECTED_FIELD = 'expected';

	// ---------- URLs ----------

	/**
	 * The tab's URL, with optional query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return BLT_Toolkit_Admin::page_url( 'tools', array_merge( array( 'tab' => self::TAB ), $args ) );
	}

	// ---------- Rendering ----------

	/**
	 * Print the tab's body. Called by BLT_Toolkit_Admin_Tools inside the
	 * page's `.wrap`, after the tab bar and the notice. Writes nothing.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$actor = wp_get_current_user();

		switch ( sanitize_key( self::arg( self::SOURCE_ARG ) ) ) {
			case 'uc':
				self::view( 'tools-legacy-uc', self::uc_data( $actor ) );
				return;

			case 'ure':
				self::view( 'tools-legacy-ure', self::ure_data( $actor ) );
				return;
		}

		self::view( 'tools-legacy', self::summary_data() );
	}

	/**
	 * Data for the tab's first page: each plugin's state and what was
	 * imported from it. Reads only.
	 *
	 * @return array
	 */
	private static function summary_data() {
		// CONVENTIONS 11: a plugin with some items imported and others not
		// (an audit log import that stopped part way, settings imported but
		// not Allowed Roles) is "partly imported", not imported.
		$state  = BLT_Toolkit_Legacy::setup_state();
		$ledger = BLT_Toolkit_Legacy::ledger();
		$job    = BLT_Toolkit_Legacy::live_job();

		return array(
			'state'   => $state,
			'history' => array(
				'usernames' => self::history( $ledger, BLT_Toolkit_Legacy::UC_ITEMS ),
				'roles'     => self::history( $ledger, array_merge( BLT_Toolkit_Legacy::URE_ITEMS, array( 'ure_caps' ) ) ),
			),
			'job'     => $job,
			'urls'    => array(
				'usernames' => self::url( array( self::SOURCE_ARG => 'uc' ) ),
				'roles'     => self::url( array( self::SOURCE_ARG => 'ure' ) ),
			),
		);
	}

	/**
	 * Data for the Username Changer preview. Reads only.
	 *
	 * @param WP_User $actor Who is looking.
	 * @return array
	 */
	private static function uc_data( WP_User $actor ) {
		$plan   = BLT_Toolkit_Legacy::uc_plan( $actor );
		$ledger = $plan['ledger'];
		$audit  = $plan['audit'];

		$settings_changes = null !== $plan['settings'] && in_array( true, array_column( $plan['settings']['rows'], 'changes' ), true );
		$roles_ready      = array() !== $plan['roles']['add'] && true === $plan['roles']['guard'];

		return array(
			'plan'     => $plan,
			'history'  => self::history( $ledger, BLT_Toolkit_Legacy::UC_ITEMS ),
			'partly'   => isset( $ledger['uc_audit'] ) && $ledger['uc_audit']['rows'] > 0 && $audit['remaining'] > 0 && '' === $audit['job'],
			'choices'  => array(
				'uc_settings' => array(
					'available' => null !== $plan['settings'],
					'checked'   => null !== $plan['settings'] && $settings_changes && ! isset( $ledger['uc_settings'] ),
				),
				// Opt-in (Phase 7 backlog): Username Changer 3.3.1 never
				// enforced Allowed Roles, so importing them turns self-service
				// on for those roles for the first time; the preview says so.
				'uc_roles'    => array(
					'available' => $roles_ready,
					'checked'   => false,
				),
				'uc_audit'    => array(
					'available' => $audit['exists'] && $audit['remaining'] > 0 && '' === $audit['job'],
					'checked'   => $audit['exists'] && $audit['remaining'] > 0 && '' === $audit['job'],
				),
			),
			'list_url' => self::url(),
			'log_url'  => current_user_can( 'blt_view_toolkit_log' ) ? BLT_Toolkit_Admin::page_url( 'activity', array( 'module' => 'usernames' ) ) : '', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
		);
	}

	/**
	 * Data for the User Role Editor preview. Reads only.
	 *
	 * @param WP_User $actor Who is looking.
	 * @return array
	 */
	private static function ure_data( WP_User $actor ) {
		$plan   = BLT_Toolkit_Legacy::ure_plan( $actor );
		$backup = $plan['backup'];

		return array(
			'plan'         => $plan,
			'history'      => self::history( $plan['ledger'], array_merge( BLT_Toolkit_Legacy::URE_ITEMS, array( 'ure_caps' ) ) ),
			'choices'      => array(
				'ure_default_roles' => array(
					'available' => array() !== $plan['defaults']['add'],
					'checked'   => array() !== $plan['defaults']['add'],
				),
				'ure_toolbar'       => array(
					'available' => array() !== $plan['toolbar']['add'],
					'checked'   => array() !== $plan['toolbar']['add'],
				),
				'ure_backup'        => array(
					'available' => $backup['valid'] && 0 === (int) $backup['imported'],
					'checked'   => $backup['valid'] && 0 === (int) $backup['imported'],
				),
			),
			'snapshot_url' => (int) $backup['imported'] > 0 ? BLT_Toolkit_Admin_Tools::url(
				'snapshots',
				array(
					BLT_Toolkit_Admin_Tools::SNAPSHOT_ARG => (int) $backup['imported'],
					BLT_Toolkit_Admin_Tools::STEP_ARG     => 'view',
				)
			) : '',
			'role_names'   => self::role_names(),
			'list_url'     => self::url(),
		);
	}

	/**
	 * What was imported, for display: item => "when, by whom".
	 *
	 * @param array    $ledger BLT_Toolkit_Legacy::ledger() (or part of it).
	 * @param string[] $items  Items to report.
	 * @return array<string,string>
	 */
	private static function history( array $ledger, array $items ) {
		$out = array();

		foreach ( $items as $item ) {
			if ( ! isset( $ledger[ $item ] ) ) {
				continue;
			}

			$at   = (int) $ledger[ $item ]['at'];
			$user = (int) $ledger[ $item ]['by'] > 0 ? get_userdata( (int) $ledger[ $item ]['by'] ) : false;
			$who  = $user instanceof WP_User ? (string) $user->display_name : __( 'no one signed in', 'blt-toolkit' );
			$when = $at > 0 ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) : '';

			$out[ $item ] = sprintf(
				/* translators: 1: date and time, 2: a person's name. */
				__( 'Imported %1$s by %2$s', 'blt-toolkit' ),
				$when,
				$who
			);
		}

		return $out;
	}

	/**
	 * An item's name.
	 *
	 * @param string $item Ledger item.
	 * @return string
	 */
	public static function item_label( $item ) {
		switch ( (string) $item ) {
			case 'uc_settings':
				return __( 'Settings', 'blt-toolkit' );
			case 'uc_roles':
				return __( 'Who may change their own username', 'blt-toolkit' );
			case 'uc_audit':
				return __( 'Audit log', 'blt-toolkit' );
			case 'ure_default_roles':
				return __( 'Other default roles', 'blt-toolkit' );
			case 'ure_toolbar':
				return __( 'Hidden admin bar', 'blt-toolkit' );
			case 'ure_backup':
				return __( 'Roles backup', 'blt-toolkit' );
			case 'ure_caps':
				return __( 'User Role Editor\'s capabilities', 'blt-toolkit' );
		}

		return (string) $item;
	}

	/**
	 * Print the toggle that chooses an item (DESIGN 2, Toggle switch),
	 * disabled when there is nothing to import for it.
	 *
	 * @param string $item   Item key.
	 * @param array  $choice array( 'available' => bool, 'checked' => bool ).
	 * @param string $label  Label.
	 * @param string $desc   Description under the label.
	 * @return void
	 */
	public static function item_toggle( $item, array $choice, $label, $desc = '' ) {
		$available = ! empty( $choice['available'] );
		?>
		<label class="blt-toggle<?php echo $available ? '' : ' is-disabled'; ?>">
			<input type="checkbox" name="<?php echo esc_attr( self::ITEMS_FIELD . '[' . $item . ']' ); ?>" value="1" <?php checked( $available && ! empty( $choice['checked'] ) ); ?> <?php disabled( ! $available ); ?> />
			<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span>
			<span class="blt-toggle-text">
				<span class="blt-toggle-label"><?php echo esc_html( $label ); ?></span>
				<?php if ( '' !== $desc ) : ?>
					<span class="blt-toggle-desc"><?php echo esc_html( $desc ); ?></span>
				<?php endif; ?>
			</span>
		</label>
		<?php
	}

	/**
	 * Role ID => translated display name.
	 *
	 * @return array<string,string>
	 */
	private static function role_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ] = translate_user_role( (string) $name );
		}

		return $out;
	}

	/**
	 * Include a view with its data as variables.
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

	// ---------- POST handlers ----------

	/**
	 * Import the chosen items from Username Changer, after
	 * BLT_Toolkit_Admin::route_post() checked the method, `manage_options`
	 * and the nonce.
	 *
	 * @return void
	 */
	public static function handle_import_uc() {
		self::require_tools();

		$result = BLT_Toolkit_Legacy::import_uc( wp_get_current_user(), self::posted_items(), self::posted( self::EXPECTED_FIELD ) );

		self::finish( $result, 'uc' );
	}

	/**
	 * Import the chosen items from User Role Editor.
	 *
	 * @return void
	 */
	public static function handle_import_ure() {
		self::require_tools();

		$result = BLT_Toolkit_Legacy::import_ure( wp_get_current_user(), self::posted_items(), self::posted( self::EXPECTED_FIELD ) );

		self::finish( $result, 'ure' );
	}

	/**
	 * Remove User Role Editor's `ure_*` capabilities from every role and
	 * user, after the dry run the preview showed.
	 *
	 * @return void
	 */
	public static function handle_remove_ure_caps() {
		self::require_tools();

		$result = BLT_Toolkit_Legacy::remove_ure_caps( wp_get_current_user(), self::posted( self::EXPECTED_FIELD ) );
		$args   = array( self::SOURCE_ARG => 'ure' );

		if ( is_wp_error( $result ) ) {
			BLT_Toolkit_Admin_Tools::back( self::TAB, $args, 'error', self::nothing( $result ) );
		}

		$link = array();

		if ( $result['snapshot_id'] > 0 ) {
			$link = array(
				'url'   => BLT_Toolkit_Admin_Tools::url(
					'snapshots',
					array(
						BLT_Toolkit_Admin_Tools::SNAPSHOT_ARG => (int) $result['snapshot_id'],
						BLT_Toolkit_Admin_Tools::STEP_ARG => 'restore',
					)
				),
				/* translators: %d: snapshot number. */
				'label' => sprintf( __( 'Preview restoring snapshot %d', 'blt-toolkit' ), (int) $result['snapshot_id'] ),
			);
		}

		BLT_Toolkit_Admin_Tools::back( self::TAB, $args, 'success', $result['message'], array(), $link );
	}

	/**
	 * Redirect back with what an import did: success when every item went
	 * through, a warning when one failed after others were imported, an
	 * error when nothing was imported.
	 *
	 * @param array|WP_Error $result BLT_Toolkit_Legacy::import_uc() or import_ure().
	 * @param string         $source `uc` or `ure`.
	 * @return void
	 */
	private static function finish( $result, $source ) {
		$args = array( self::SOURCE_ARG => $source );

		if ( is_wp_error( $result ) ) {
			BLT_Toolkit_Admin_Tools::back( self::TAB, $args, 'error', self::nothing( $result ) );
		}

		$lines  = array();
		$failed = false;

		foreach ( $result['done'] as $item => $message ) {
			if ( is_wp_error( $message ) ) {
				$failed  = true;
				$lines[] = sprintf(
					/* translators: 1: what was being imported, 2: the reason. */
					__( '%1$s: not imported. %2$s', 'blt-toolkit' ),
					self::item_label( $item ),
					$message->get_error_message()
				);
				continue;
			}

			$lines[] = (string) $message;
		}

		$link = array();

		if ( ! empty( $result['snapshot_id'] ) ) {
			$link = array(
				'url'   => BLT_Toolkit_Admin_Tools::url(
					'snapshots',
					array(
						BLT_Toolkit_Admin_Tools::SNAPSHOT_ARG => (int) $result['snapshot_id'],
						BLT_Toolkit_Admin_Tools::STEP_ARG => 'view',
					)
				),
				/* translators: %d: snapshot number. */
				'label' => sprintf( __( 'View snapshot %d', 'blt-toolkit' ), (int) $result['snapshot_id'] ),
			);
		}

		BLT_Toolkit_Admin_Tools::back( self::TAB, $args, $failed ? 'warning' : 'success', implode( ' ', $lines ), array(), $link );
	}

	/**
	 * Refuse anyone who may not open the Tools screen: the tab needs
	 * `blt_reset_roles` as well as the route's `manage_options` (SPEC 13.1).
	 *
	 * @return void
	 */
	private static function require_tools() {
		if ( ! current_user_can( 'blt_reset_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			wp_die(
				esc_html__( 'You do not have permission to do that.', 'blt-toolkit' ),
				esc_html__( 'BLT Toolkit', 'blt-toolkit' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * "Nothing was imported: {reason}".
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

	// ---------- Request reading ----------

	/**
	 * The chosen items: keys of the items field whose value is 1.
	 *
	 * @return string[]
	 */
	private static function posted_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by the router; keys are matched against a whitelist by the importer.
		if ( ! isset( $_POST[ self::ITEMS_FIELD ] ) || ! is_array( $_POST[ self::ITEMS_FIELD ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ self::ITEMS_FIELD ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$out = array();

		foreach ( $raw as $item => $value ) {
			if ( is_scalar( $value ) && '1' === (string) $value ) {
				$out[] = sanitize_key( (string) $item );
			}
		}

		return $out;
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
}
