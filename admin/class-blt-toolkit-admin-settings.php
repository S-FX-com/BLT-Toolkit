<?php
/**
 * Settings screen: one tab per settings group, the GitHub update token,
 * Test connection and Check for Updates.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Settings screen (SPEC 13.2, 14.1). Needs `manage_options`
 * (CONVENTIONS 6.3), for viewing and for every form it posts.
 *
 * Each tab is its own URL and its own form, saved through
 * BLT_Toolkit_Settings::save_tab(), which sanitizes field by field and never
 * touches another tab's values. The token is saved through
 * BLT_Toolkit_Settings::set_github_token(): a blank field keeps the stored
 * token, and removing it is a separate POST (CONVENTIONS 6.2).
 *
 * Controls that belong to later phases (self-service roles UN-20, extra
 * default roles US-2, role metadata 9.5) are not rendered; each tab names
 * what is still to come in one line.
 *
 * Settings > Switching also offers the Support Agent role (SW-21): a button
 * that opens a preview of the role's capabilities and of who it can and
 * cannot switch into (SEC-1, SEC-2), with a typed confirmation because the
 * role holds a Critical capability (SEC-10). Creating it takes a snapshot
 * first and stops when that fails (SNAP-1), then writes the role once and
 * logs it. The button is hidden once the role exists.
 */
class BLT_Toolkit_Admin_Settings {

	/**
	 * The Support Agent role's ID (SW-21).
	 */
	const SUPPORT_AGENT_ROLE = 'blt_support_agent';

	/**
	 * Query argument and value that open the Support Agent preview on the
	 * Switching tab. A GET that only renders (RO-5).
	 */
	const SUPPORT_AGENT_ARG     = 'support_agent';
	const SUPPORT_AGENT_PREVIEW = 'preview';

	/**
	 * Name of the typed-confirmation field on the preview (SEC-10).
	 */
	const SUPPORT_AGENT_CONFIRM = 'blt_toolkit_confirm_role';

	/**
	 * GitHub repository the update checker reads, used when the checker is not
	 * loaded. Keep in step with the main file.
	 */
	const REPOSITORY = 'S-FX-com/BLT-Toolkit';

	/**
	 * Name of the token field. Kept outside the `blt_toolkit[...]` array so it
	 * never reaches save_tab().
	 */
	const TOKEN_FIELD = 'blt_toolkit_github_token';

	/**
	 * Form ids of the two token actions that sit outside the main form. Their
	 * buttons sit in the token card and point at them with the `form`
	 * attribute, so no form is ever nested in another.
	 */
	const FORM_TEST   = 'blt-toolkit-test-connection';
	const FORM_REMOVE = 'blt-toolkit-remove-token';

	/**
	 * The tabs, key => label. Keys match BLT_Toolkit_Settings::TABS.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return array(
			'general'   => __( 'General', 'blt-toolkit' ),
			'switching' => __( 'Switching', 'blt-toolkit' ),
			'usernames' => __( 'Usernames', 'blt-toolkit' ),
			'roles'     => __( 'Roles', 'blt-toolkit' ),
			'log'       => __( 'Activity log', 'blt-toolkit' ),
			'advanced'  => __( 'Advanced', 'blt-toolkit' ),
		);
	}

	/**
	 * The open tab; an unknown one falls back to General.
	 *
	 * @return string
	 */
	public function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'general';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		$tab      = $this->current_tab();
		$tabs     = $this->tabs();
		$settings = BLT_Toolkit_Settings::all();
		$ranges   = BLT_Toolkit_Settings::INT_RANGES;
		$field    = 'blt_toolkit';
		$actions  = array();

		$update_url = ( class_exists( 'BLT_Family_Updates' ) && current_user_can( 'update_plugins' ) )
			? BLT_Family_Updates::check_now_url( 'blt-toolkit' )
			: '';

		if ( '' !== $update_url ) {
			// The automatic check runs once a day at midnight site time; this is
			// an explicit request and runs at once (DESIGN 4).
			$actions[] = array(
				'label' => __( 'Check for Updates', 'blt-toolkit' ),
				'url'   => $update_url,
			);
		}

		// Only what the open tab needs: the token status decrypts the stored
		// token, so it is read on the Advanced tab alone.
		$modules      = 'general' === $tab ? BLT_Toolkit_Admin::modules() : array();
		$token_status = 'advanced' === $tab ? BLT_Toolkit_Settings::github_token_status() : '';
		$last_check   = 'advanced' === $tab ? $this->last_check() : 0;
		$disabled     = 'advanced' === $tab ? BLT_Toolkit_Plugin::disabled_by_constant() : array();
		$recovery     = defined( 'BLT_TOOLKIT_RECOVERY' ) && constant( 'BLT_TOOLKIT_RECOVERY' );

		$support_agent = 'switching' === $tab ? self::support_agent_state() : array();

		// SW-21: the preview is its own page, so its form is never nested in
		// the settings form.
		if ( ! empty( $support_agent['can_create'] ) && $this->support_agent_preview_requested() ) {
			$preview = self::support_agent_preview();

			require BLT_TOOLKIT_DIR . 'admin/views/settings-support-agent.php';

			return;
		}

		require BLT_TOOLKIT_DIR . 'admin/views/settings.php';
	}

	// ---------- POST handlers (called by BLT_Toolkit_Admin::route_post()) ----------

	/**
	 * Save one tab, and on Advanced a newly entered token.
	 *
	 * The method, `manage_options` and the nonce were checked by the router.
	 *
	 * @return void
	 */
	public function handle_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$raw   = isset( $_POST['blt_toolkit'] ) && is_array( $_POST['blt_toolkit'] ) ? wp_unslash( $_POST['blt_toolkit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in BLT_Toolkit_Settings::save_tab().
		$token = isset( $_POST[ self::TOKEN_FIELD ] ) && is_string( $_POST[ self::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! array_key_exists( $tab, $this->tabs() ) ) {
			BLT_Toolkit_Admin::redirect( 'settings', array(), 'failed' );
		}

		$saved  = BLT_Toolkit_Settings::save_tab( $tab, $raw );
		$notice = $this->was_adjusted( $tab, $raw, $saved ) ? 'saved_adjusted' : 'saved';

		// A blank field keeps the stored token. With the constant set the
		// field is not shown, and a stored token would be ignored anyway.
		if ( 'advanced' === $tab && '' !== $token && 'constant' !== BLT_Toolkit_Settings::github_token_status() ) {
			$result = BLT_Toolkit_Settings::set_github_token( $token );

			if ( is_wp_error( $result ) ) {
				switch ( $result->get_error_code() ) {
					case 'blt_toolkit_bad_token':
						$notice = 'token_bad';
						break;
					case 'blt_toolkit_no_aead':
						$notice = 'token_no_aead';
						break;
					default:
						$notice = 'token_failed';
				}
			} else {
				$notice = 'token_saved';
			}
		}

		BLT_Toolkit_Admin::redirect( 'settings', array( 'tab' => $tab ), $notice );
	}

	/**
	 * Remove the plugin's own stored token. The constant and the shared store
	 * are not affected.
	 *
	 * @return void
	 */
	public function handle_remove_token() {
		BLT_Toolkit_Settings::delete_github_token();

		BLT_Toolkit_Admin::redirect( 'settings', array( 'tab' => 'advanced' ), 'token_removed' );
	}

	/**
	 * Ask GitHub for the repository with the token update checks would use,
	 * and report the HTTP status (CONVENTIONS 7, Test Connection).
	 *
	 * The token goes only into the request's Authorization header; the
	 * redirect carries the status code and where the token came from, never
	 * the token, with a nonce over those values so the notice cannot be faked
	 * by a crafted link (BLT_Toolkit_Admin::result_args()).
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		$token  = BLT_Toolkit_Settings::github_token();
		$status = BLT_Toolkit_Settings::github_token_status();

		if ( '' === $token ) {
			$via = 'none';
		} elseif ( in_array( $status, array( 'constant', 'stored' ), true ) ) {
			$via = $status;
		} else {
			$via = 'shared';
		}

		$headers = array( 'Accept' => 'application/vnd.github+json' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repository(),
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = sanitize_key( (string) $response->get_error_code() );

			BLT_Toolkit_Admin::redirect(
				'settings',
				array_merge( array( 'tab' => 'advanced' ), BLT_Toolkit_Admin::result_args( 0, '', '' !== $err ? $err : 'unknown' ) ),
				'test_unreachable'
			);
		}

		BLT_Toolkit_Admin::redirect(
			'settings',
			array_merge( array( 'tab' => 'advanced' ), BLT_Toolkit_Admin::result_args( (int) wp_remote_retrieve_response_code( $response ), $via, '' ) ),
			'test_result'
		);
	}

	/**
	 * Create the Support Agent role (SW-21) after the preview.
	 *
	 * The method, `manage_options` and the nonce were checked by the router.
	 * Adding a role also needs `blt_create_roles` (SPEC 5.1), and the typed
	 * role name must match (SEC-10). Nothing is written when the role
	 * already exists.
	 *
	 * @return void
	 */
	public function handle_create_support_agent() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		$typed = isset( $_POST[ self::SUPPORT_AGENT_CONFIRM ] ) && is_string( $_POST[ self::SUPPORT_AGENT_CONFIRM ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::SUPPORT_AGENT_CONFIRM ] ) ) : '';
		$tab   = array( 'tab' => 'switching' );

		if ( ! current_user_can( 'blt_create_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			BLT_Toolkit_Admin::redirect( 'settings', $tab, 'support_agent_denied' );
		}

		if ( null !== get_role( self::SUPPORT_AGENT_ROLE ) ) {
			BLT_Toolkit_Admin::redirect( 'settings', $tab, 'support_agent_exists' );
		}

		if ( ! self::confirmation_matches( $typed, self::support_agent_name() ) ) {
			BLT_Toolkit_Admin::redirect( 'settings', array_merge( $tab, array( self::SUPPORT_AGENT_ARG => self::SUPPORT_AGENT_PREVIEW ) ), 'support_agent_confirm' );
		}

		$result = self::create_support_agent( wp_get_current_user() );

		if ( is_wp_error( $result ) ) {
			switch ( $result->get_error_code() ) {
				case 'blt_toolkit_role_exists':
					$notice = 'support_agent_exists';
					break;
				case 'blt_toolkit_snapshot_failed':
					$notice = 'support_agent_snapshot';
					break;
				case 'blt_toolkit_grant_exceeds_actor':
				case 'blt_toolkit_deny_exceeds_actor':
				case 'blt_toolkit_role_name_cap':
				case 'blt_toolkit_no_actor':
					$notice = 'support_agent_denied';
					break;
				default:
					$notice = 'support_agent_failed';
			}

			BLT_Toolkit_Admin::redirect( 'settings', $tab, $notice );
		}

		BLT_Toolkit_Admin::redirect( 'settings', $tab, 'support_agent_created' );
	}

	// ---------- Support Agent role (SW-21) ----------

	/**
	 * The Support Agent role's capabilities (SW-21).
	 *
	 * @return array<string,bool>
	 */
	public static function support_agent_caps() {
		return array(
			'read'             => true,
			'list_users'       => true,
			'blt_switch_users' => true,
		);
	}

	/**
	 * The role's display name, in the site's language, because that is the
	 * language it is stored in and shown to everyone.
	 *
	 * @return string
	 */
	public static function support_agent_name() {
		$switched = switch_to_locale( get_locale() );
		$name     = __( 'Support Agent', 'blt-toolkit' );

		if ( $switched ) {
			restore_previous_locale();
		}

		return $name;
	}

	/**
	 * What the Switching tab shows about the role: whether it exists,
	 * whether the current user may create it, and the preview URL.
	 *
	 * @return array{exists:bool,can_create:bool,preview_url:string}
	 */
	public static function support_agent_state() {
		$exists = null !== get_role( self::SUPPORT_AGENT_ROLE );

		return array(
			'exists'      => $exists,
			'can_create'  => ! $exists && current_user_can( 'blt_create_roles' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			'preview_url' => BLT_Toolkit_Admin::page_url(
				'settings',
				array(
					'tab'                   => 'switching',
					self::SUPPORT_AGENT_ARG => self::SUPPORT_AGENT_PREVIEW,
				)
			),
		);
	}

	/**
	 * Whether this request asks for the Support Agent preview.
	 *
	 * @return bool
	 */
	private function support_agent_preview_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$value = isset( $_GET[ self::SUPPORT_AGENT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::SUPPORT_AGENT_ARG ] ) ) : '';

		return self::SUPPORT_AGENT_PREVIEW === $value;
	}

	/**
	 * Everything the preview shows (SW-21): the role's name, each capability
	 * with its risk tier (SPEC 5.4), and which of the site's roles a Support
	 * Agent could and could not switch into.
	 *
	 * A role is out of reach when it is `administrator` (SEC-1) or holds a
	 * capability the Support Agent lacks (SEC-2), compared with
	 * BLT_Toolkit_Superset exactly as the Guard compares users. Reads only.
	 *
	 * @return array{name:string,caps:array,reachable:string[],blocked:array}
	 */
	public static function support_agent_preview() {
		$caps   = self::support_agent_caps();
		$names  = array_map( 'strval', array_keys( (array) wp_roles()->role_names ) );
		$agent  = BLT_Toolkit_Superset::normalize( $caps, $names );
		$rows   = array();
		$labels = array(
			'read'             => __( 'Sign in to the dashboard and edit their own profile', 'blt-toolkit' ),
			'list_users'       => __( 'See the list of users', 'blt-toolkit' ),
			'blt_switch_users' => __( 'Switch to other users', 'blt-toolkit' ),
		);

		foreach ( array_keys( $caps ) as $cap ) {
			$rows[] = array(
				'cap'   => $cap,
				'label' => isset( $labels[ $cap ] ) ? $labels[ $cap ] : $cap,
				'tier'  => BLT_Toolkit_Caps::tier( $cap ),
			);
		}

		$reachable = array();
		$blocked   = array();

		foreach ( (array) wp_roles()->roles as $id => $role ) {
			$id   = (string) $id;
			$name = isset( $role['name'] ) ? translate_user_role( (string) $role['name'] ) : $id;

			if ( BLT_Toolkit_Guard::ADMIN_ROLE === $id ) {
				$blocked[] = array(
					'name'    => $name,
					'rule'    => 'SEC-1',
					'missing' => 0,
				);
				continue;
			}

			$role_caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$missing   = BLT_Toolkit_Superset::missing( $agent, BLT_Toolkit_Superset::normalize( $role_caps, $names ) );

			if ( array() === $missing ) {
				$reachable[] = $name;
			} else {
				$blocked[] = array(
					'name'    => $name,
					'rule'    => 'SEC-2',
					'missing' => count( $missing ),
				);
			}
		}

		return array(
			'name'      => self::support_agent_name(),
			'caps'      => $rows,
			'reachable' => $reachable,
			'blocked'   => $blocked,
		);
	}

	/**
	 * Create the Support Agent role (SW-21): SEC-3 for the actor, a snapshot
	 * first (SNAP-1; nothing is written when it cannot be stored), one write
	 * of the roles option through add_role(), then a log row with the diff
	 * and the snapshot's ID (LOG-1).
	 *
	 * @param WP_User $actor Who is creating it.
	 * @return int|WP_Error The snapshot ID, or `blt_toolkit_role_exists`,
	 *                      `blt_toolkit_roles_in_code`, a Guard refusal
	 *                      (SEC-3), `blt_toolkit_snapshot_failed` or
	 *                      `blt_toolkit_role_not_created`.
	 */
	public static function create_support_agent( WP_User $actor ) {
		$wp_roles = wp_roles();

		if ( null !== $wp_roles->get_role( self::SUPPORT_AGENT_ROLE ) ) {
			return new WP_Error( 'blt_toolkit_role_exists', __( 'The Support Agent role already exists.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		// Roles defined in code ($wp_user_roles) cannot be saved.
		if ( ! $wp_roles->use_db ) {
			return new WP_Error( 'blt_toolkit_roles_in_code', __( 'This site\'s roles are defined in code, so a role cannot be added here.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		$caps    = self::support_agent_caps();
		$allowed = BLT_Toolkit_Guard::can_grant( $actor, $caps );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$snapshot_id = (int) BLT_Toolkit_Snapshots::take( 'before_save' );

		if ( 0 === $snapshot_id ) {
			return new WP_Error( 'blt_toolkit_snapshot_failed', __( 'A copy of the roles could not be saved first, so no role was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		$name   = self::support_agent_name();
		$before = $wp_roles->roles;
		$role   = add_role( self::SUPPORT_AGENT_ROLE, $name, $caps );

		if ( ! $role instanceof WP_Role ) {
			return new WP_Error( 'blt_toolkit_role_not_created', __( 'The Support Agent role could not be created.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'switch',
				'action'      => 'role_created',
				'object_type' => 'role',
				'object_id'   => self::SUPPORT_AGENT_ROLE,
				'summary'     => sprintf(
					/* translators: %s: role name, such as Support Agent. */
					__( 'Created the %s role: it can sign in, see the list of users and switch to users who have no more access than itself.', 'blt-toolkit' ),
					$name
				),
				'details'     => array(
					'role'         => self::SUPPORT_AGENT_ROLE,
					'name'         => $name,
					'capabilities' => array_keys( $caps ),
					'diff'         => BLT_Toolkit_Role_Diff::diff( $before, $wp_roles->roles ),
				),
				'snapshot_id' => $snapshot_id,
			)
		);

		return $snapshot_id;
	}

	/**
	 * Whether a typed confirmation matches a role's display name (SEC-10):
	 * the same text, ignoring case and surrounding spaces. Pure.
	 *
	 * @param string $typed What was typed.
	 * @param string $name  The display name.
	 * @return bool
	 */
	public static function confirmation_matches( $typed, $name ) {
		$typed = trim( (string) $typed );
		$name  = trim( (string) $name );

		if ( '' === $typed || '' === $name ) {
			return false;
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $typed, 'UTF-8' ) === mb_strtolower( $name, 'UTF-8' );
		}

		return strtolower( $typed ) === strtolower( $name );
	}

	// ---------- Helpers ----------

	/**
	 * The `owner/name` of the GitHub repository updates come from: read from
	 * the update checker when it is loaded, so the two cannot drift.
	 *
	 * @return string
	 */
	public static function repository() {
		$checker = isset( $GLOBALS['blt_toolkit_update_checker'] ) ? $GLOBALS['blt_toolkit_update_checker'] : null;

		if ( is_object( $checker ) && method_exists( $checker, 'getVcsApi' ) ) {
			$api = $checker->getVcsApi();

			if ( is_object( $api ) && method_exists( $api, 'getRepositoryUrl' ) ) {
				$path = trim( (string) wp_parse_url( (string) $api->getRepositoryUrl(), PHP_URL_PATH ), '/' );

				if ( preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $path ) ) {
					return $path;
				}
			}
		}

		return self::REPOSITORY;
	}

	/**
	 * When the update checker last ran, or 0.
	 *
	 * @return int
	 */
	private function last_check() {
		if ( ! class_exists( 'BLT_Family_Updates' ) || ! isset( $GLOBALS['blt_toolkit_update_checker'] ) ) {
			return 0;
		}

		return BLT_Family_Updates::last_check_time( $GLOBALS['blt_toolkit_update_checker'] );
	}

	/**
	 * Whether a submitted number was not saved as typed: outside its range
	 * (clamped) or not a whole number (the previous value kept).
	 *
	 * @param string $tab   Tab key.
	 * @param array  $raw   Submitted fields.
	 * @param array  $saved The group as stored.
	 * @return bool
	 */
	private function was_adjusted( $tab, array $raw, array $saved ) {
		if ( ! isset( BLT_Toolkit_Settings::TABS[ $tab ] ) ) {
			return false;
		}

		$group = BLT_Toolkit_Settings::TABS[ $tab ];

		foreach ( array_keys( BLT_Toolkit_Settings::INT_RANGES ) as $id ) {
			list( $range_group, $key ) = explode( '.', $id, 2 );

			if ( $range_group !== $group || ! array_key_exists( $key, $raw ) || ! array_key_exists( $key, $saved ) ) {
				continue;
			}

			$posted = is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';

			if ( ! preg_match( '/^-?\d+$/', $posted ) || (int) $posted !== (int) $saved[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A "coming in a later release" line for a control that is not built yet.
	 *
	 * @param string $text What is coming.
	 * @return void
	 */
	public static function later( $text ) {
		?>
		<div class="blt-card-body blt-toolkit-later">
			<?php echo BLT_Toolkit_Admin::badge( 'pending', __( 'Coming soon', 'blt-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?>
			<span class="blt-text-muted"><?php echo esc_html( $text ); ?></span>
		</div>
		<?php
	}

	/**
	 * A whole-number input bounded by the settings class's range for that
	 * key, so the browser and the server agree.
	 *
	 * @param string $group Settings group.
	 * @param string $key   Key within the group.
	 * @param int    $value Current value.
	 * @param string $id    Input id.
	 * @return void
	 */
	public static function number( $group, $key, $value, $id ) {
		$range = isset( BLT_Toolkit_Settings::INT_RANGES[ $group . '.' . $key ] ) ? BLT_Toolkit_Settings::INT_RANGES[ $group . '.' . $key ] : array( 0, PHP_INT_MAX );

		printf(
			'<input type="number" class="small-text" id="%1$s" name="blt_toolkit[%2$s]" value="%3$s" min="%4$s" max="%5$s" step="1" />',
			esc_attr( $id ),
			esc_attr( $key ),
			esc_attr( (string) (int) $value ),
			esc_attr( (string) $range[0] ),
			esc_attr( (string) $range[1] )
		);
	}
}
