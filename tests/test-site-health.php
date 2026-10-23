<?php
/**
 * Site Health: the pure result builders of BLT_Toolkit_Site_Health (GEN-1,
 * RS-33, SEC-14, LOG-3 and the tables).
 *
 * Registering the tests, reading the flags, the buttons and the notice are
 * WordPress-coupled and verified on a real install (tasks/todo.md, Phase 7).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Shim of core's esc_html(): HTML special characters, entities not
	 * encoded twice.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Shim of core's esc_html__().
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Shim.
		return esc_html( __( $text, $domain ) ); // phpcs:ignore WordPress.WP.I18n -- Shim.
	}
}

/**
 * Known-vector tests for BLT_Toolkit_Site_Health.
 */
class Test_Site_Health extends TestCase {

	/**
	 * Format a time for the tests: the ISO date in UTC.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function format_time( $time ) {
		return gmdate( 'Y-m-d H:i', (int) $time );
	}

	/**
	 * Every result has the shape Site Health expects.
	 *
	 * @param array  $result Result.
	 * @param string $test   Test key.
	 * @param string $status Expected status.
	 * @return void
	 */
	private function assert_result( array $result, $test, $status ) {
		$this->assertSame( array( 'label', 'status', 'badge', 'description', 'actions', 'test' ), array_keys( $result ) );
		$this->assertSame( 'blt_toolkit_' . $test, $result['test'] );
		$this->assertSame( $status, $result['status'] );
		$this->assertSame( 'BLT Toolkit', $result['badge']['label'] );
		$this->assertNotSame( '', $result['label'] );
		$this->assertNotSame( '', $result['description'] );
		$this->assertSame( '', $result['actions'], 'Actions are added by the WordPress side.' );
	}

	/**
	 * Tables: good when both exist, critical with each missing name escaped.
	 *
	 * @return void
	 */
	public function test_spec14_3_tables() {
		$this->assert_result( BLT_Toolkit_Site_Health::tables_result( array() ), 'tables', 'good' );

		$log = BLT_Toolkit_Site_Health::tables_result( array( 'log' => 'wp_blt_toolkit_log' ) );

		$this->assert_result( $log, 'tables', 'critical' );
		$this->assertStringContainsString( '<code>wp_blt_toolkit_log</code>', $log['description'] );
		$this->assertStringContainsString( 'nothing BLT Toolkit does is recorded', $log['description'] );
		$this->assertStringNotContainsString( 'every change to roles is refused', $log['description'], 'Roles still save without the log (LOG-3).' );

		$both = BLT_Toolkit_Site_Health::tables_result(
			array(
				'log'       => 'wp_blt_toolkit_log',
				'snapshots' => 'wp_<b>',
			)
		);

		$this->assertStringContainsString( '<code>wp_&lt;b&gt;</code>', $both['description'] );
		$this->assertStringContainsString( 'every change to roles is refused', $both['description'], 'No snapshot, no roles write.' );
	}

	/**
	 * GEN-1: each legacy plugin is named with the module it keeps off.
	 *
	 * @return void
	 */
	public function test_gen1_legacy_conflicts() {
		$labels = array(
			'switch'    => 'Switch',
			'usernames' => 'Usernames',
			'roles'     => 'Roles',
		);

		$this->assert_result( BLT_Toolkit_Site_Health::legacy_result( array(), $labels ), 'legacy_conflicts', 'good' );

		$one = BLT_Toolkit_Site_Health::legacy_result( array( 'roles' => 'User Role Editor' ), $labels );

		$this->assert_result( $one, 'legacy_conflicts', 'recommended' );
		$this->assertSame( 'A plugin that BLT Toolkit replaces is still active', $one['label'] );
		$this->assertStringContainsString( 'User Role Editor is active, so BLT Toolkit&#039;s Roles module is not running.', $one['description'] );

		$two = BLT_Toolkit_Site_Health::legacy_result(
			array(
				'switch'    => 'User Switching',
				'usernames' => 'Username Changer',
			),
			$labels
		);

		$this->assertSame( 'Plugins that BLT Toolkit replaces are still active', $two['label'] );
		$this->assertSame( 2, substr_count( $two['description'], '<li>' ) );
	}

	/**
	 * RS-33: good with or without isolated types; a failure names the type,
	 * the expected and found capability, and since when.
	 *
	 * @return void
	 */
	public function test_rs33_isolation() {
		$format = array( __CLASS__, 'format_time' );

		$none = BLT_Toolkit_Site_Health::isolation_result( array(), array(), 0, $format );

		$this->assert_result( $none, 'isolation', 'good' );
		$this->assertStringContainsString( 'No content type has separate permissions.', $none['description'] );

		$two = BLT_Toolkit_Site_Health::isolation_result( array(), array(), 2, $format );

		$this->assertStringContainsString( '2 content types have separate permissions, and all are registered with them.', $two['description'] );

		$failed = BLT_Toolkit_Site_Health::isolation_result(
			array(
				'event' => array(
					'expected' => 'edit_events',
					'found'    => 'edit_posts',
					'since'    => 1761127200,
				),
				'promo' => array(
					'expected' => 'create_promos',
					'found'    => '',
				),
			),
			array( 'event' => 'Events & Talks' ),
			2,
			$format
		);

		$this->assert_result( $failed, 'isolation', 'recommended' );
		$this->assertStringContainsString( 'Events &amp; Talks (<code>event</code>): expected <code>edit_events</code>, found <code>edit_posts</code>. Since 2025-10-22 10:00.', $failed['description'] );
		$this->assertStringContainsString( 'promo (<code>promo</code>): expected <code>create_promos</code>, found <code>-</code>.', $failed['description'] );
		$this->assertSame( 'Some content types are not using their separate permissions', $failed['label'] );
	}

	/**
	 * SEC-14: off is good; on is a warning that says to remove the line, and
	 * says recovery cannot save when roles are defined in code.
	 *
	 * @return void
	 */
	public function test_sec14_recovery() {
		$this->assert_result( BLT_Toolkit_Site_Health::recovery_result( false, false ), 'recovery', 'good' );

		$on = BLT_Toolkit_Site_Health::recovery_result( true, false );

		$this->assert_result( $on, 'recovery', 'recommended' );
		$this->assertStringContainsString( '<code>define( &#039;BLT_TOOLKIT_RECOVERY&#039;, true );</code>', $on['description'] );
		$this->assertStringContainsString( 'Remove the line', $on['description'] );

		$code = BLT_Toolkit_Site_Health::recovery_result( true, true );

		$this->assertStringContainsString( 'defines its roles in code', $code['description'] );
	}

	/**
	 * LOG-3: no flag is good; a flag is a warning with the count, the time,
	 * the escaped error and whether writes work again since.
	 *
	 * @return void
	 */
	public function test_log3_log_writes() {
		$format = array( __CLASS__, 'format_time' );

		$this->assert_result( BLT_Toolkit_Site_Health::log_result( null, $format ), 'log_writes', 'good' );

		$failing = BLT_Toolkit_Site_Health::log_result(
			array(
				'time'      => 1761127200,
				'error'     => 'no such table: wp_blt_toolkit_log <x>',
				'count'     => 3,
				'recovered' => 0,
			),
			$format
		);

		$this->assert_result( $failing, 'log_writes', 'recommended' );
		$this->assertSame( 'BLT Toolkit cannot save activity log entries', $failing['label'] );
		$this->assertStringContainsString( '3 activity log entries could not be saved, most recently on 2025-10-22 10:00.', $failing['description'] );
		$this->assertStringContainsString( '<code>no such table: wp_blt_toolkit_log &lt;x&gt;</code>', $failing['description'] );
		$this->assertStringContainsString( 'No entry has been saved since.', $failing['description'] );

		$recovered = BLT_Toolkit_Site_Health::log_result(
			array(
				'time'      => 1761127200,
				'error'     => '',
				'count'     => 1,
				'recovered' => 1761130800,
			),
			$format
		);

		$this->assertSame( 'BLT Toolkit\'s activity log missed some entries', $recovered['label'] );
		$this->assertStringContainsString( '1 activity log entry could not be saved', $recovered['description'] );
		$this->assertStringContainsString( 'saved again since 2025-10-22 11:00', $recovered['description'] );
		$this->assertStringNotContainsString( 'The database said', $recovered['description'] );

		// A flag stored before `recovered` existed reads as still failing.
		$old = BLT_Toolkit_Site_Health::log_result(
			array(
				'time'  => 1761127200,
				'error' => 'x',
				'count' => 2,
			),
			$format
		);

		$this->assertSame( 'BLT Toolkit cannot save activity log entries', $old['label'] );
	}
}
