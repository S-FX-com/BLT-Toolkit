<?php
/**
 * Site Health tests (SPEC 17, Phase 7).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BLT Toolkit's tests on Tools > Site Health > Status.
 *
 * - `blt_toolkit_tables`: both tables (SPEC 14.3) exist. Without them nothing
 *   is logged and every roles write is refused, because its snapshot cannot
 *   be stored (SNAP-1). Critical, with a button that creates them again.
 * - `blt_toolkit_legacy_conflicts` (GEN-1): User Switching, Username
 *   Changer or User Role Editor is active, so the matching module is not
 *   running.
 * - `blt_toolkit_isolation` (RS-33): a content type given separate
 *   permissions is not registered with them (this request's check, with
 *   since when from the flag Phase 5 stores,
 *   BLT_Toolkit_Isolation_Core::stored_failures()).
 * - `blt_toolkit_recovery` (SEC-14): `BLT_TOOLKIT_RECOVERY` is set.
 * - `blt_toolkit_log_writes` (LOG-3): an activity log write failed
 *   (BLT_Toolkit_Log::last_failure()), with whether writes work again since
 *   and a button that clears the warning (clear_failure()).
 *
 * Problems are `recommended` ("warning", as SPEC words LOG-3 and RS-33),
 * except missing tables, which stop BLT Toolkit from saving roles at all.
 *
 * Registered only on wp-admin, WP-Cron (Site Health's weekly check) and
 * WP-CLI requests, and only as hooks: the tests run when Site Health asks
 * for them, never on a page load. The buttons post to admin-post.php
 * through BLT_Toolkit_Admin's router (nonce, `manage_options`, PRG) and
 * come back to Site Health with a notice.
 *
 * The results are built from plain facts by the pure *_result() methods,
 * unit-tested in tests/test-site-health.php.
 */
class BLT_Toolkit_Site_Health {

	/**
	 * admin-post.php action that clears the LOG-3 flag.
	 */
	const CLEAR_ACTION = 'blt_toolkit_clear_log_failure';

	/**
	 * admin-post.php action that creates the tables again.
	 */
	const REPAIR_ACTION = 'blt_toolkit_repair_tables';

	/**
	 * Query argument carrying a button's result back to Site Health.
	 */
	const NOTICE_ARG = 'blt_toolkit_health';

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private static $registered = false;

	// ---------- Boot ----------

	/**
	 * Add the hooks. Called from BLT_Toolkit_Plugin::boot() on wp-admin,
	 * WP-Cron and WP-CLI requests; only adds hooks.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	/**
	 * `site_status_tests`: add the direct tests.
	 *
	 * @param mixed $tests Registered tests.
	 * @return array
	 */
	public static function tests( $tests ) {
		$tests = is_array( $tests ) ? $tests : array();

		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['blt_toolkit_tables'] = array(
			'label' => __( 'BLT Toolkit database tables', 'blt-toolkit' ),
			'test'  => array( __CLASS__, 'test_tables' ),
		);

		$tests['direct']['blt_toolkit_legacy_conflicts'] = array(
			'label' => __( 'Plugins BLT Toolkit replaces', 'blt-toolkit' ),
			'test'  => array( __CLASS__, 'test_legacy_conflicts' ),
		);

		$tests['direct']['blt_toolkit_isolation'] = array(
			'label' => __( 'BLT Toolkit separate permissions', 'blt-toolkit' ),
			'test'  => array( __CLASS__, 'test_isolation' ),
		);

		$tests['direct']['blt_toolkit_recovery'] = array(
			'label' => __( 'BLT Toolkit recovery mode', 'blt-toolkit' ),
			'test'  => array( __CLASS__, 'test_recovery' ),
		);

		$tests['direct']['blt_toolkit_log_writes'] = array(
			'label' => __( 'BLT Toolkit activity log', 'blt-toolkit' ),
			'test'  => array( __CLASS__, 'test_log_writes' ),
		);

		return $tests;
	}

	// ---------- Tests (WordPress side) ----------

	/**
	 * Both tables exist (SPEC 14.3).
	 *
	 * @return array Site Health result.
	 */
	public static function test_tables() {
		$result = self::tables_result( self::missing_tables() );

		if ( 'good' !== $result['status'] && current_user_can( 'manage_options' ) ) {
			$result['actions'] = self::button( self::REPAIR_ACTION, __( 'Create the tables again', 'blt-toolkit' ) );
		}

		return $result;
	}

	/**
	 * No legacy plugin blocks a module (GEN-1).
	 *
	 * @return array Site Health result.
	 */
	public static function test_legacy_conflicts() {
		$labels    = array();
		$conflicts = BLT_Toolkit_Legacy::conflicts();

		foreach ( BLT_Toolkit_Plugin::labels() as $key => $module ) {
			$labels[ $key ] = $module['label'];
		}

		$result = self::legacy_result( $conflicts, $labels );

		if ( 'good' !== $result['status'] ) {
			$links = array();

			if ( current_user_can( 'manage_options' ) && current_user_can( 'blt_reset_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
				$links[] = self::link( BLT_Toolkit_Admin::page_url( 'tools', array( 'tab' => 'legacy' ) ), __( 'Import from legacy plugins', 'blt-toolkit' ) );
			}

			if ( current_user_can( 'activate_plugins' ) ) {
				$links[] = self::link( admin_url( 'plugins.php' ), __( 'Go to Plugins', 'blt-toolkit' ) );
			}

			$result['actions'] = implode( '', $links );
		}

		return $result;
	}

	/**
	 * Every isolated content type has its own capabilities (RS-33).
	 *
	 * This request's own check decides (BLT_Toolkit_Isolation_Core::failed(),
	 * run at `init` PHP_INT_MAX), so a failure fixed since the flag was
	 * stored is not reported; the flag stored by admin, cron and WP-CLI
	 * requests (stored_failures()) says since when each one has failed.
	 *
	 * @return array Site Health result.
	 */
	public static function test_isolation() {
		$stored = BLT_Toolkit_Isolation_Core::stored_failures();
		$failed = did_action( 'init' ) ? BLT_Toolkit_Isolation_Core::failed() : $stored;
		$labels = array();

		foreach ( $failed as $type => $failure ) {
			$object          = get_post_type_object( (string) $type );
			$labels[ $type ] = $object instanceof WP_Post_Type && isset( $object->labels->name ) ? (string) $object->labels->name : (string) $type;

			if ( isset( $stored[ $type ]['since'] ) && $stored[ $type ]['found'] === $failure['found'] ) {
				$failed[ $type ]['since'] = (int) $stored[ $type ]['since'];
			}
		}

		$result = self::isolation_result( $failed, $labels, count( BLT_Toolkit_Isolation_Core::isolated() ), array( __CLASS__, 'format_time' ) );

		if ( 'good' !== $result['status'] ) {
			$links = array();

			if ( class_exists( 'BLT_Toolkit_Admin_Isolation' ) && ! is_wp_error( BLT_Toolkit_Isolation::can_manage( wp_get_current_user() ) ) ) {
				foreach ( array_keys( $failed ) as $type ) {
					$links[] = self::link(
						BLT_Toolkit_Admin_Isolation::url( 'revert', (string) $type ),
						/* translators: %s: content type name, such as Events. */
						sprintf( __( 'Make %s share Posts\' permissions again', 'blt-toolkit' ), $labels[ $type ] )
					);
				}
			}

			$result['actions'] = implode( '', $links );
		}

		return $result;
	}

	/**
	 * Recovery mode is off (SEC-14).
	 *
	 * @return array Site Health result.
	 */
	public static function test_recovery() {
		return self::recovery_result( BLT_Toolkit_Guard::recovery_requested(), ! wp_roles()->use_db );
	}

	/**
	 * No activity log write failed (LOG-3).
	 *
	 * @return array Site Health result.
	 */
	public static function test_log_writes() {
		$result = self::log_result( BLT_Toolkit_Log::last_failure(), array( __CLASS__, 'format_time' ) );

		if ( 'good' !== $result['status'] ) {
			$links = array();

			if ( current_user_can( 'manage_options' ) ) {
				$links[] = self::button( self::CLEAR_ACTION, __( 'Clear this warning', 'blt-toolkit' ) );
			}

			if ( current_user_can( 'blt_view_toolkit_log' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
				$links[] = self::link( BLT_Toolkit_Admin::page_url( 'activity' ), __( 'Open the activity log', 'blt-toolkit' ) );
			}

			$result['actions'] = implode( '', $links );
		}

		return $result;
	}

	/**
	 * The BLT Toolkit tables that do not exist. Two SHOW TABLES queries, run
	 * only when Site Health asks.
	 *
	 * @return array<string,string> `log` or `snapshots` => full table name.
	 */
	public static function missing_tables() {
		global $wpdb;

		$missing    = array();
		$suppressed = $wpdb->suppress_errors( true );

		foreach ( array_keys( BLT_Toolkit_Schema::TABLES ) as $name ) {
			$table = BLT_Toolkit_Schema::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A Site Health check; nothing to cache.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

			if ( 0 !== strcasecmp( (string) $found, $table ) ) {
				$missing[ $name ] = $table;
			}
		}

		$wpdb->suppress_errors( $suppressed );

		return $missing;
	}

	/**
	 * A UTC timestamp in the site's date and time format.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function format_time( $time ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $time );
	}

	// ---------- Results (pure) ----------

	/**
	 * The tables test. Pure.
	 *
	 * @param array<string,string> $missing `log` or `snapshots` => name of a table that does not exist.
	 * @return array Site Health result.
	 */
	public static function tables_result( array $missing ) {
		if ( array() === $missing ) {
			return self::result(
				'tables',
				'good',
				__( 'BLT Toolkit\'s database tables are in place', 'blt-toolkit' ),
				'<p>' . esc_html__( 'The activity log and the role snapshots have their tables.', 'blt-toolkit' ) . '</p>'
			);
		}

		$names = array();

		foreach ( $missing as $table ) {
			$names[] = '<code>' . esc_html( (string) $table ) . '</code>';
		}

		return self::result(
			'tables',
			'critical',
			__( 'BLT Toolkit\'s database tables are missing', 'blt-toolkit' ),
			'<p>' . sprintf(
				/* translators: %s: table names, in code formatting. */
				esc_html__( 'These tables do not exist: %s.', 'blt-toolkit' ),
				implode( ', ', $names ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			) . '</p>' . ( isset( $missing['log'] ) ? '<p>' . esc_html__( 'Without the activity log table, nothing BLT Toolkit does is recorded.', 'blt-toolkit' ) . '</p>' : '' )
			. ( isset( $missing['snapshots'] ) ? '<p>' . esc_html__( 'Without the snapshots table, every change to roles is refused, because BLT Toolkit saves a copy of the roles before it changes them.', 'blt-toolkit' ) . '</p>' : '' )
			. '<p>' . esc_html__( 'Create the tables again; if that fails, check that the database user may create tables.', 'blt-toolkit' ) . '</p>'
		);
	}

	/**
	 * The legacy plugins test (GEN-1). Pure.
	 *
	 * @param array<string,string> $conflicts Module key => legacy plugin name (BLT_Toolkit_Legacy::conflicts()).
	 * @param array<string,string> $labels    Module key => module name.
	 * @return array Site Health result.
	 */
	public static function legacy_result( array $conflicts, array $labels ) {
		if ( array() === $conflicts ) {
			return self::result(
				'legacy_conflicts',
				'good',
				__( 'No plugin that BLT Toolkit replaces is active', 'blt-toolkit' ),
				'<p>' . esc_html__( 'User Switching, Username Changer and User Role Editor are not active, so nothing keeps a BLT Toolkit module from running.', 'blt-toolkit' ) . '</p>'
			);
		}

		$items = array();

		foreach ( $conflicts as $module => $plugin ) {
			$items[] = '<li>' . esc_html(
				sprintf(
					/* translators: 1: plugin name, such as User Switching. 2: BLT Toolkit module name, such as Switch. */
					__( '%1$s is active, so BLT Toolkit\'s %2$s module is not running.', 'blt-toolkit' ),
					(string) $plugin,
					isset( $labels[ $module ] ) ? (string) $labels[ $module ] : (string) $module
				)
			) . '</li>';
		}

		return self::result(
			'legacy_conflicts',
			'recommended',
			_n( 'A plugin that BLT Toolkit replaces is still active', 'Plugins that BLT Toolkit replaces are still active', count( $conflicts ), 'blt-toolkit' ),
			'<ul class="ul-disc">' . implode( '', $items ) . '</ul><p>' . esc_html__( 'BLT Toolkit keeps a module off while the plugin it replaces is active, so the two never run side by side. Import what you need from the old plugin first, then deactivate it; the module starts on the next page load.', 'blt-toolkit' ) . '</p>'
		);
	}

	/**
	 * The separate permissions test (RS-33). Pure.
	 *
	 * @param array<string,array> $failed      Type => array( 'expected', 'found', optional 'since' ).
	 * @param array<string,string> $labels     Type => name.
	 * @param int                  $isolated   How many types have separate permissions.
	 * @param callable             $format_time Formats a Unix time.
	 * @return array Site Health result.
	 */
	public static function isolation_result( array $failed, array $labels, $isolated, $format_time ) {
		if ( array() === $failed ) {
			$text = (int) $isolated > 0
				? sprintf(
					/* translators: %s: number of content types. */
					_n( '%s content type has separate permissions and is registered with them.', '%s content types have separate permissions, and all are registered with them.', (int) $isolated, 'blt-toolkit' ),
					number_format_i18n( (int) $isolated )
				)
				: __( 'No content type has separate permissions.', 'blt-toolkit' );

			return self::result(
				'isolation',
				'good',
				__( 'Separate permissions for content types are in effect', 'blt-toolkit' ),
				'<p>' . esc_html( $text ) . '</p>'
			);
		}

		$items = array();

		foreach ( $failed as $type => $failure ) {
			$found = isset( $failure['found'] ) && '' !== (string) $failure['found'] ? (string) $failure['found'] : '-';
			$item  = sprintf(
				/* translators: 1: content type name, such as Events. 2: post type key. 3: expected capability. 4: capability found. */
				esc_html__( '%1$s (%2$s): expected %3$s, found %4$s.', 'blt-toolkit' ),
				esc_html( isset( $labels[ $type ] ) ? (string) $labels[ $type ] : (string) $type ),
				'<code>' . esc_html( (string) $type ) . '</code>',
				'<code>' . esc_html( isset( $failure['expected'] ) ? (string) $failure['expected'] : '' ) . '</code>',
				'<code>' . esc_html( $found ) . '</code>'
			);

			if ( ! empty( $failure['since'] ) ) {
				$item .= ' ' . esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Since %s.', 'blt-toolkit' ),
						(string) call_user_func( $format_time, (int) $failure['since'] )
					)
				);
			}

			$items[] = '<li>' . $item . '</li>';
		}

		return self::result(
			'isolation',
			'recommended',
			_n( 'A content type is not using its separate permissions', 'Some content types are not using their separate permissions', count( $failed ), 'blt-toolkit' ),
			'<ul class="ul-disc">' . implode( '', $items ) . '</ul><p>' . esc_html__( 'These content types were given separate permissions, but they registered before BLT Toolkit loaded or another plugin changed their permissions. Until that is fixed, people may have the access Posts gives them instead of what the Roles screen shows, and their levels are switched off in Simple view. Fix the registration, or make the type share Posts\' permissions again.', 'blt-toolkit' ) . '</p>'
		);
	}

	/**
	 * The recovery mode test (SEC-14). Pure.
	 *
	 * @param bool $on      Whether `BLT_TOOLKIT_RECOVERY` is set.
	 * @param bool $in_code Whether the site defines its roles in code (`$wp_user_roles`).
	 * @return array Site Health result.
	 */
	public static function recovery_result( $on, $in_code ) {
		$line = '<code>' . esc_html( "define( 'BLT_TOOLKIT_RECOVERY', true );" ) . '</code>';

		if ( ! $on ) {
			return self::result(
				'recovery',
				'good',
				__( 'BLT Toolkit recovery mode is off', 'blt-toolkit' ),
				'<p>' . sprintf(
					/* translators: %s: the wp-config.php line, in code formatting. */
					esc_html__( 'Recovery mode is for an administrator who has been locked out of the site. If that happens, add %s to wp-config.php and open any admin page.', 'blt-toolkit' ),
					$line // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				) . '</p>'
			);
		}

		$text = $in_code
			/* translators: %s: the wp-config.php line, in code formatting. */
			? esc_html__( 'This site defines its roles in code, so recovery cannot save anything: the Administrator role has what it needs only while %s is in wp-config.php. Fix the Administrator role in that code, then remove the line.', 'blt-toolkit' )
			/* translators: %s: the wp-config.php line, in code formatting. */
			: esc_html__( 'While %s is in wp-config.php, every admin page an administrator opens gives the Administrator role back the capabilities it needs to manage the site and every BLT Toolkit capability. Remove the line once you can manage the site again; it is meant for emergencies only.', 'blt-toolkit' );

		return self::result(
			'recovery',
			'recommended',
			__( 'BLT Toolkit recovery mode is on', 'blt-toolkit' ),
			'<p>' . sprintf( $text, $line ) . '</p>'
		);
	}

	/**
	 * The activity log test (LOG-3). Pure.
	 *
	 * @param array|null $failure     BLT_Toolkit_Log::last_failure(): `time`, `error`, `count` and, when a later write succeeded, `recovered`.
	 * @param callable   $format_time Formats a Unix time.
	 * @return array Site Health result.
	 */
	public static function log_result( $failure, $format_time ) {
		if ( ! is_array( $failure ) || empty( $failure['time'] ) ) {
			return self::result(
				'log_writes',
				'good',
				__( 'BLT Toolkit\'s activity log is being written', 'blt-toolkit' ),
				'<p>' . esc_html__( 'No entry has failed to save.', 'blt-toolkit' ) . '</p>'
			);
		}

		$count     = isset( $failure['count'] ) ? max( 1, (int) $failure['count'] ) : 1;
		$recovered = isset( $failure['recovered'] ) ? (int) $failure['recovered'] : 0;
		$when      = (string) call_user_func( $format_time, (int) $failure['time'] );
		$html      = '<p>' . esc_html(
			sprintf(
				/* translators: 1: number of entries, 2: date and time of the last failure. */
				_n( '%1$s activity log entry could not be saved, most recently on %2$s. The changes themselves were made; only their record is missing.', '%1$s activity log entries could not be saved, most recently on %2$s. The changes themselves were made; only their record is missing.', $count, 'blt-toolkit' ),
				number_format_i18n( $count ),
				$when
			)
		) . '</p>';

		if ( isset( $failure['error'] ) && '' !== (string) $failure['error'] ) {
			$html .= '<p>' . sprintf(
				/* translators: %s: the database error, in code formatting. */
				esc_html__( 'The database said: %s', 'blt-toolkit' ),
				'<code>' . esc_html( (string) $failure['error'] ) . '</code>'
			) . '</p>';
		}

		if ( $recovered > 0 ) {
			$html .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Entries have been saved again since %s, so the cause may be fixed. Clear this warning once you have checked.', 'blt-toolkit' ),
					(string) call_user_func( $format_time, $recovered )
				)
			) . '</p>';
		} else {
			$html .= '<p>' . esc_html__( 'No entry has been saved since. Check the database table and the PHP error log, which has a line for each failure; then clear this warning.', 'blt-toolkit' ) . '</p>';
		}

		return self::result(
			'log_writes',
			'recommended',
			$recovered > 0 ? __( 'BLT Toolkit\'s activity log missed some entries', 'blt-toolkit' ) : __( 'BLT Toolkit cannot save activity log entries', 'blt-toolkit' ),
			$html
		);
	}

	/**
	 * A Site Health result. Pure.
	 *
	 * @param string $test        Test key without the prefix.
	 * @param string $status      `good`, `recommended` or `critical`.
	 * @param string $label       Heading (plain text).
	 * @param string $description HTML, already escaped.
	 * @return array{label:string,status:string,badge:array,description:string,actions:string,test:string}
	 */
	public static function result( $test, $status, $label, $description ) {
		return array(
			'label'       => (string) $label,
			'status'      => (string) $status,
			'badge'       => array(
				'label' => __( 'BLT Toolkit', 'blt-toolkit' ),
				'color' => 'blue',
			),
			'description' => (string) $description,
			'actions'     => '',
			'test'        => 'blt_toolkit_' . $test,
		);
	}

	// ---------- Buttons (admin-post.php, through BLT_Toolkit_Admin) ----------

	/**
	 * A small POST form for a test's action: the router checks the method,
	 * `manage_options` and the nonce (BLT_Toolkit_Admin::route_post()).
	 *
	 * @param string $action admin-post.php action.
	 * @param string $label  Button text.
	 * @return string HTML.
	 */
	private static function button( $action, $label ) {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( BLT_Toolkit_Admin::post_url() ); ?>">
			<?php BLT_Toolkit_Admin::form_fields( $action ); ?>
			<p><button type="submit" class="button"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A link for a test's actions.
	 *
	 * @param string $url   URL.
	 * @param string $label Text.
	 * @return string HTML.
	 */
	private static function link( $url, $label ) {
		return '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Clear the LOG-3 flag, then prove the log works by recording that it
	 * was cleared: if that write fails, the flag is set again at once and
	 * the notice says so. Routed by BLT_Toolkit_Admin (`manage_options`).
	 *
	 * @return void
	 */
	public static function handle_clear_log_failure() {
		BLT_Toolkit_Log::clear_failure();

		$id = BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'log_failure_cleared',
				'object_type' => 'log',
				'summary'     => __( 'Cleared the Site Health warning about activity log entries that could not be saved.', 'blt-toolkit' ),
			)
		);

		self::back( false === $id ? 'still_failing' : 'cleared' );
	}

	/**
	 * Create the tables again (BLT_Toolkit_Schema::install(), idempotent).
	 * Routed by BLT_Toolkit_Admin (`manage_options`).
	 *
	 * @return void
	 */
	public static function handle_repair_tables() {
		if ( ! BLT_Toolkit_Schema::install() ) {
			self::back( 'repair_failed' );
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'toolkit',
				'action'      => 'tables_repaired',
				'object_type' => 'log',
				'summary'     => __( 'Created BLT Toolkit\'s database tables again from Site Health.', 'blt-toolkit' ),
			)
		);

		self::back( 'repaired' );
	}

	/**
	 * Redirect to Site Health with a result code, and stop (PRG).
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private static function back( $code ) {
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $code, admin_url( 'site-health.php' ) ) );
		exit;
	}

	/**
	 * `admin_notices` on Site Health: a button's result. Known codes only;
	 * the URL never carries text.
	 *
	 * @return void
	 */
	public static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, after our own redirect.
		if ( empty( $_GET[ self::NOTICE_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof WP_Screen || 'site-health' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, after our own redirect.
		$code = sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) );

		switch ( $code ) {
			case 'cleared':
				$type    = 'success';
				$message = __( 'BLT Toolkit: the activity log warning was cleared, and a test entry was saved.', 'blt-toolkit' );
				break;
			case 'still_failing':
				$type    = 'error';
				$message = __( 'BLT Toolkit: the warning was cleared, but saving a test entry failed again, so it is back. Check the database table and the PHP error log.', 'blt-toolkit' );
				break;
			case 'repaired':
				$type    = 'success';
				$message = __( 'BLT Toolkit: the database tables are in place.', 'blt-toolkit' );
				break;
			case 'repair_failed':
				$type    = 'error';
				$message = __( 'BLT Toolkit: the database tables could not be created. Check that the database user may create tables; the PHP error log may say why.', 'blt-toolkit' );
				break;
			default:
				return;
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}
}
