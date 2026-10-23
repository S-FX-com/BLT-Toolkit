<?php
/**
 * The pure parts of the switching UI: the toolbar search text (SW-20), the
 * Support Agent role's definition and typed confirmation (SW-21, SEC-10),
 * and the query arguments core drops after a switch (SW-16).
 *
 * Every placement itself (row action, profile button, toolbar, notices,
 * wp_die links, footer link, login message, Meta widget, command palette,
 * REST field) needs WordPress and is smoke-tested against a real install
 * (tasks/todo.md).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Rest's search text rules, BLT_Toolkit_Admin_Settings'
 * Support Agent rules and BLT_Toolkit_Switch_Ui's removable query arguments.
 */
class Test_Switch_Ui extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * SW-20: the search text is trimmed and loses `*`, which WP_User_Query
	 * would read as a wildcard.
	 *
	 * @return void
	 */
	public function test_sw20_clean_search_text() {
		$this->assertSame( 'jane', BLT_Toolkit_Rest::clean_switch_search( '  jane ' ) );
		$this->assertSame( 'jane', BLT_Toolkit_Rest::clean_switch_search( '*ja*ne*' ) );
		$this->assertSame( '', BLT_Toolkit_Rest::clean_switch_search( '**' ) );
		$this->assertSame( '', BLT_Toolkit_Rest::clean_switch_search( array( 'jane' ) ) );
		$this->assertSame( '', BLT_Toolkit_Rest::clean_switch_search( 42 ) );
	}

	/**
	 * SW-20: at least 2 characters after cleaning, at most 100; multibyte
	 * characters count once.
	 *
	 * @return void
	 */
	public function test_sw20_search_length() {
		$this->assertTrue( BLT_Toolkit_Rest::validate_switch_search( 'ja' ) );
		$this->assertTrue( BLT_Toolkit_Rest::validate_switch_search( 'Zoë' ) );
		$this->assertTrue( BLT_Toolkit_Rest::validate_switch_search( str_repeat( 'a', 100 ) ) );
		$this->assertTrue( is_wp_error( BLT_Toolkit_Rest::validate_switch_search( 'j' ) ) );
		$this->assertTrue( is_wp_error( BLT_Toolkit_Rest::validate_switch_search( '  j  ' ) ) );
		$this->assertTrue( is_wp_error( BLT_Toolkit_Rest::validate_switch_search( '**' ) ) );
		$this->assertTrue( is_wp_error( BLT_Toolkit_Rest::validate_switch_search( str_repeat( 'a', 101 ) ) ) );
		$this->assertTrue( is_wp_error( BLT_Toolkit_Rest::validate_switch_search( null ) ) );
	}

	/**
	 * SW-21: the Support Agent role holds exactly read, list_users and
	 * blt_switch_users.
	 *
	 * @return void
	 */
	public function test_sw21_support_agent_caps() {
		$this->assertSame(
			array(
				'read'             => true,
				'list_users'       => true,
				'blt_switch_users' => true,
			),
			BLT_Toolkit_Admin_Settings::support_agent_caps()
		);
		$this->assertSame( 'blt_support_agent', BLT_Toolkit_Admin_Settings::SUPPORT_AGENT_ROLE );
	}

	/**
	 * SEC-10: the typed confirmation must be the role's display name,
	 * ignoring case and surrounding spaces, and never empty.
	 *
	 * @return void
	 */
	public function test_sec10_confirmation_matches() {
		$this->assertTrue( BLT_Toolkit_Admin_Settings::confirmation_matches( 'Support Agent', 'Support Agent' ) );
		$this->assertTrue( BLT_Toolkit_Admin_Settings::confirmation_matches( '  support AGENT ', 'Support Agent' ) );
		$this->assertTrue( BLT_Toolkit_Admin_Settings::confirmation_matches( 'ÉQUIPE', 'équipe' ) );
		$this->assertFalse( BLT_Toolkit_Admin_Settings::confirmation_matches( 'Support Agnet', 'Support Agent' ) );
		$this->assertFalse( BLT_Toolkit_Admin_Settings::confirmation_matches( 'SupportAgent', 'Support Agent' ) );
		$this->assertFalse( BLT_Toolkit_Admin_Settings::confirmation_matches( '', 'Support Agent' ) );
		$this->assertFalse( BLT_Toolkit_Admin_Settings::confirmation_matches( '   ', '' ) );
	}

	/**
	 * SW-16: the three flags the switch flow appends are added to core's
	 * removable query arguments, and nothing already there is lost.
	 *
	 * @return void
	 */
	public function test_sw16_removable_query_args() {
		$this->assertSame(
			array( 'updated', 'blt_switched', 'blt_switched_back', 'blt_switched_off' ),
			BLT_Toolkit_Switch_Ui::removable_query_args( array( 'updated' ) )
		);
	}

	/**
	 * SW-16: the wp_die() arguments are read as core reads them before the
	 * switch-back link is added, so a query string keeps its response code
	 * and an integer title or argument is the response code.
	 *
	 * @return void
	 */
	public function test_sw16_die_args_keep_core_shapes() {
		$this->assertSame( array( 'Title', array( 'response' => '403' ) ), BLT_Toolkit_Switch_Ui::die_args( 'Title', 'response=403' ) );
		$this->assertSame( array( 'Title', array( 'response' => 410 ) ), BLT_Toolkit_Switch_Ui::die_args( 'Title', 410 ) );
		$this->assertSame( array( '', array( 'response' => 404 ) ), BLT_Toolkit_Switch_Ui::die_args( 404, array( 'back_link' => true ) ) );
		$this->assertSame(
			array(
				'Title',
				array(
					'response'  => 500,
					'back_link' => true,
				),
			),
			BLT_Toolkit_Switch_Ui::die_args(
				'Title',
				array(
					'response'  => 500,
					'back_link' => true,
				)
			)
		);
		$this->assertSame( array( 'Title', array() ), BLT_Toolkit_Switch_Ui::die_args( 'Title', '' ) );
	}
}
