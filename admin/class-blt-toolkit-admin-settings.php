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
 * Controls that belong to later phases (self-service roles UN-20, the Support
 * Agent role SW-21, extra default roles US-2, role metadata 9.5) are not
 * rendered; each tab names what is still to come in one line.
 */
class BLT_Toolkit_Admin_Settings {

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
