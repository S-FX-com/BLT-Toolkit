<?php
/**
 * Overview screen: module cards and the plugin's state at a glance.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Overview screen (SPEC 13.2):
 *
 * - The recovery-mode callout while BLT_TOOLKIT_RECOVERY is set (SEC-14).
 * - A setup card while one of the plugins BLT Toolkit replaces is active or
 *   left its settings behind and nothing was imported from it yet (SPEC 15),
 *   linking to Tools > Import from legacy plugins.
 * - One card per module with its state (On, Off, Conflict per GEN-1, or
 *   disabled by BLT_TOOLKIT_DISABLE_MODULES per SEC-15) and its toggle.
 * - Stat tiles: roles, users without a role (US-12: the cached count and a
 *   Count button, never counted on load), and switches and username changes
 *   in the last 30 days (one indexed range query on the log).
 *
 * Rendering writes nothing. Its queries: the legacy plugins' options and the
 * import ledger in one (BLT_Toolkit_Legacy::setup_state()), the US-12
 * transient, and the log's 30-day counts. The module toggles post to
 * admin-post.php and save through BLT_Toolkit_Settings::save_tab(), the same
 * path as Settings > General.
 */
class BLT_Toolkit_Admin_Overview {

	/**
	 * Days the activity tiles cover (SPEC 13.2).
	 */
	const ACTIVITY_DAYS = 30;

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		// One query for the options this screen reads that are not
		// autoloaded: the import ledger, the legacy plugins' settings (when
		// they are gone) and the US-12 count's transient.
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches(
				array(
					BLT_Toolkit_Legacy::LEDGER_OPTION,
					BLT_Toolkit_Legacy::UC_OPTION,
					BLT_Toolkit_Legacy::URE_OPTION,
					BLT_Toolkit_Legacy::URE_EXTRA_OPTION,
					'_transient_blt_toolkit_no_role_count',
					'_transient_timeout_blt_toolkit_no_role_count',
				)
			);
		}

		$modules  = BLT_Toolkit_Admin::modules();
		$running  = count(
			array_filter(
				$modules,
				function ( $module ) {
					return 'on' === $module['status'];
				}
			)
		);
		$roles    = wp_roles()->get_names();
		$recovery = defined( 'BLT_TOOLKIT_RECOVERY' ) && constant( 'BLT_TOOLKIT_RECOVERY' );
		$activity = self::activity_counts( self::ACTIVITY_DAYS );
		$links    = self::activity_links( self::ACTIVITY_DAYS );
		$setup    = self::setup_lines( BLT_Toolkit_Legacy::setup_state() );

		require BLT_TOOLKIT_DIR . 'admin/views/overview.php';
	}

	/**
	 * Switches and username changes in the last `$days` days (SPEC 13.2):
	 * one query on the log's `created_at` index, counting rows that did not
	 * fail (an imported Username Changer change counts: it happened).
	 *
	 * @param int $days Days back from now.
	 * @return array{switches:int|null,renames:int|null} Null when the log cannot be read.
	 */
	public static function activity_counts( $days ) {
		global $wpdb;

		$table = BLT_Toolkit_Schema::table( 'log' );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		// With WP_DEBUG_DISPLAY on, a missing table would print into the page
		// (LOG-3 covers writes; a read here just shows no number).
		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; a range on its created_at index, on the Overview only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT module, action, COUNT(*) AS total FROM {$table} WHERE created_at >= %s AND status <> %s AND ( ( module = %s AND action = %s ) OR ( module = %s AND action = %s ) ) GROUP BY module, action", $since, 'failure', 'switch', 'switched_to', 'usernames', 'username_changed' ), ARRAY_A );

		$wpdb->suppress_errors( $suppressed );

		return self::count_rows( $rows );
	}

	/**
	 * The tiles' numbers from the grouped rows. Pure.
	 *
	 * @param mixed $rows Rows of module, action, total; anything else is a failed read.
	 * @return array{switches:int|null,renames:int|null}
	 */
	public static function count_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return array(
				'switches' => null,
				'renames'  => null,
			);
		}

		$out = array(
			'switches' => 0,
			'renames'  => 0,
		);

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['module'], $row['action'], $row['total'] ) ) {
				continue;
			}

			if ( 'switch' === $row['module'] && 'switched_to' === $row['action'] ) {
				$out['switches'] += (int) $row['total'];
			} elseif ( 'usernames' === $row['module'] && 'username_changed' === $row['action'] ) {
				$out['renames'] += (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * Links from the activity tiles to the Activity screen, filtered the
	 * same way, for those who may open it; '' otherwise.
	 *
	 * @param int $days Days back from today.
	 * @return array{switches:string,renames:string}
	 */
	private static function activity_links( $days ) {
		if ( ! current_user_can( 'blt_view_toolkit_log' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return array(
				'switches' => '',
				'renames'  => '',
			);
		}

		$from = wp_date( 'Y-m-d', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );

		return array(
			'switches' => BLT_Toolkit_Admin::page_url(
				'activity',
				array(
					'module'     => 'switch',
					'log_action' => 'switched_to',
					'from'       => $from,
				)
			),
			'renames'  => BLT_Toolkit_Admin::page_url(
				'activity',
				array(
					'module'     => 'usernames',
					'log_action' => 'username_changed',
					'from'       => $from,
				)
			),
		);
	}

	/**
	 * The setup card's lines (SPEC 13.2, 15): per legacy plugin that is
	 * active or left settings behind, what to do next. Empty when there is
	 * nothing to say.
	 *
	 * @param array $state BLT_Toolkit_Legacy::setup_state().
	 * @return array[] Each array( 'text', 'url', 'label' ); `url` is '' when there is no button.
	 */
	public static function setup_lines( array $state ) {
		$can_import = current_user_can( 'manage_options' ) && current_user_can( 'blt_reset_roles' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
		$plugins    = admin_url( 'plugins.php' );
		$out        = array();

		$sources = array(
			'usernames' => array(
				'source' => 'uc',
				/* translators: %s: plugin name, Username Changer. */
				'import' => __( 'Import settings from %s', 'blt-toolkit' ),
				/* translators: %s: plugin name, Username Changer. */
				'todo'   => __( '%s left its settings and audit log on this site. Bring them into BLT Toolkit before you remove it.', 'blt-toolkit' ),
			),
			'roles'     => array(
				'source' => 'ure',
				/* translators: %s: plugin name, User Role Editor. */
				'import' => __( 'Import settings from %s', 'blt-toolkit' ),
				/* translators: %s: plugin name, User Role Editor. */
				'todo'   => __( '%s left its settings on this site: other default roles, hidden admin bars and a backup of the roles. Import them before you delete it; deleting it removes them.', 'blt-toolkit' ),
			),
		);

		/* translators: 1: plugin name, such as Username Changer, 2: a list of the parts not imported yet. */
		$partly = __( 'Part of what %1$s left on this site is imported. Still to import: %2$s.', 'blt-toolkit' );

		foreach ( $sources as $module => $source ) {
			if ( ! isset( $state[ $module ] ) ) {
				continue;
			}

			$plugin = $state[ $module ];

			if ( ! empty( $plugin['partly'] ) ) {
				$out[] = array(
					'text'  => sprintf( $partly, $plugin['name'], self::item_list( (array) $plugin['missing'] ) ),
					'url'   => $can_import ? BLT_Toolkit_Admin_Legacy::url( array( BLT_Toolkit_Admin_Legacy::SOURCE_ARG => $source['source'] ) ) : '',
					'label' => sprintf( $source['import'], $plugin['name'] ),
				);
			} elseif ( ! $plugin['imported'] && ( $plugin['data'] || $plugin['active'] ) ) {
				$out[] = array(
					'text'  => sprintf( $source['todo'], $plugin['name'] ),
					'url'   => $can_import ? BLT_Toolkit_Admin_Legacy::url( array( BLT_Toolkit_Admin_Legacy::SOURCE_ARG => $source['source'] ) ) : '',
					'label' => sprintf( $source['import'], $plugin['name'] ),
				);
			} elseif ( $plugin['imported'] && $plugin['active'] ) {
				$out[] = array(
					/* translators: %s: plugin name, such as Username Changer. */
					'text'  => sprintf( __( 'Settings from %s are imported. Deactivate it so BLT Toolkit\'s module can run.', 'blt-toolkit' ), $plugin['name'] ),
					'url'   => current_user_can( 'activate_plugins' ) ? $plugins : '',
					'label' => __( 'Go to Plugins', 'blt-toolkit' ),
				);
			}
		}

		if ( isset( $state['switch'] ) && $state['switch']['active'] ) {
			$out[] = array(
				/* translators: %s: plugin name, User Switching. */
				'text'  => sprintf( __( '%s is active. It keeps nothing to import: ask everyone who is switched into another account to switch back, then deactivate it.', 'blt-toolkit' ), $state['switch']['name'] ),
				'url'   => current_user_can( 'activate_plugins' ) ? $plugins : '',
				'label' => __( 'Go to Plugins', 'blt-toolkit' ),
			);
		}

		return $out;
	}

	/**
	 * Legacy import items named for a sentence, as a localized list.
	 *
	 * @param string[] $items Ledger items (BLT_Toolkit_Legacy::UC_ITEMS, URE_ITEMS).
	 * @return string
	 */
	private static function item_list( array $items ) {
		$names = array();

		foreach ( $items as $item ) {
			$names[] = BLT_Toolkit_Admin_Legacy::item_label( (string) $item );
		}

		return implode( wp_get_list_item_separator(), $names );
	}

	/**
	 * Save the module toggles from the Overview cards.
	 *
	 * Called by BLT_Toolkit_Admin::route_post() after the method, capability
	 * (`manage_options`) and nonce checks. A module whose toggle was not
	 * submitted is switched off, as an unticked checkbox sends nothing.
	 *
	 * @return void
	 */
	public function handle_save_modules() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BLT_Toolkit_Admin::route_post().
		$raw = isset( $_POST['blt_toolkit'] ) && is_array( $_POST['blt_toolkit'] ) ? wp_unslash( $_POST['blt_toolkit'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in BLT_Toolkit_Settings::save_tab().
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		BLT_Toolkit_Settings::save_tab( 'general', $raw );

		BLT_Toolkit_Admin::redirect( 'overview', array(), 'modules_saved' );
	}
}
