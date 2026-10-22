<?php
/**
 * Read-only enforcement: the RS-14 decision (RS-13, RS-14).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Readonly.
 */
class Test_Readonly extends TestCase {

	/**
	 * Posts' cap object (create_posts is edit_posts).
	 *
	 * @return array
	 */
	private function posts_cap() {
		return array(
			'edit_posts'   => 'edit_posts',
			'create_posts' => 'edit_posts',
		);
	}

	/**
	 * An isolated `event` type's cap object (RS-31).
	 *
	 * @return array
	 */
	private function events_cap() {
		return array(
			'edit_post'    => 'edit_event',
			'edit_posts'   => 'edit_events',
			'create_posts' => 'create_events',
		);
	}

	/**
	 * A View-level role's capabilities on Events (RS-13).
	 *
	 * @return array
	 */
	private function view_level() {
		return array(
			'read'                => true,
			'read_private_events' => true,
			'edit_events'         => true,
			'blt_readonly_events' => true,
		);
	}

	/**
	 * RS-13: the marker exists only for a type whose create_posts is
	 * distinct, named after its edit_posts string.
	 *
	 * @return void
	 */
	public function test_rs13_marker_for() {
		$this->assertSame( '', BLT_Toolkit_Readonly::marker_for( $this->posts_cap() ) );
		$this->assertSame( 'blt_readonly_events', BLT_Toolkit_Readonly::marker_for( $this->events_cap() ) );
		$this->assertSame(
			'blt_readonly_books',
			BLT_Toolkit_Readonly::marker_for(
				array(
					'edit_posts'   => 'edit_books',
					'create_posts' => 'create_books',
				)
			)
		);
		// ACF style: edit_posts is not an edit_ string.
		$this->assertSame(
			'',
			BLT_Toolkit_Readonly::marker_for(
				array(
					'edit_posts'   => 'manage_options',
					'create_posts' => 'do_not_allow',
				)
			)
		);
		$this->assertSame( '', BLT_Toolkit_Readonly::marker_for( array( 'edit_posts' => 'edit_events' ) ) );
		$this->assertSame( '', BLT_Toolkit_Readonly::marker_for( array() ) );
	}

	/**
	 * RS-14: "holds the marker and lacks create". A role with a higher level
	 * elsewhere (create granted) is not restricted; a marker stored `false`
	 * is not held; a create stored `false` is lacked.
	 *
	 * @return void
	 */
	public function test_rs14_restricts() {
		$view = $this->view_level();

		$this->assertTrue( BLT_Toolkit_Readonly::restricts( $view, 'blt_readonly_events', 'create_events' ) );
		$this->assertFalse( BLT_Toolkit_Readonly::restricts( $view + array( 'create_events' => true ), 'blt_readonly_events', 'create_events' ), 'Additive: Author through another role.' );
		$this->assertTrue( BLT_Toolkit_Readonly::restricts( $view + array( 'create_events' => false ), 'blt_readonly_events', 'create_events' ) );

		$no_marker = $view;
		unset( $no_marker['blt_readonly_events'] );
		$this->assertFalse( BLT_Toolkit_Readonly::restricts( $no_marker, 'blt_readonly_events', 'create_events' ) );

		$false_marker                        = $view;
		$false_marker['blt_readonly_events'] = false;
		$this->assertFalse( BLT_Toolkit_Readonly::restricts( $false_marker, 'blt_readonly_events', 'create_events' ) );

		$this->assertFalse( BLT_Toolkit_Readonly::restricts( $view, '', 'create_events' ) );
		$this->assertFalse( BLT_Toolkit_Readonly::restricts( $view, 'blt_readonly_events', '' ) );
	}

	/**
	 * RS-14: the decision for each capability WordPress checks on an item.
	 *
	 * @return void
	 */
	public function test_rs14_decision() {
		$view   = $this->view_level();
		$events = $this->events_cap();

		foreach ( array( 'edit_post', 'delete_post', 'publish_post' ) as $cap ) {
			$this->assertTrue( BLT_Toolkit_Readonly::decide( $cap, $events, $view ), $cap );
			$this->assertFalse( BLT_Toolkit_Readonly::decide( $cap, $this->posts_cap(), $view + array( 'blt_readonly_posts' => true ) ), "$cap on Posts: View is site-only there" );
			$this->assertFalse( BLT_Toolkit_Readonly::decide( $cap, $events, $view + array( 'create_events' => true ) ), "$cap with create" );
		}

		// Reading and the list screen stay open; primitives are not touched.
		foreach ( array( 'read_post', 'edit_posts', 'edit_events', 'create_events', 'edit_others_posts', 'edit_post_meta', '' ) as $cap ) {
			$this->assertFalse( BLT_Toolkit_Readonly::decide( $cap, $events, $view ), $cap );
		}

		$this->assertFalse( BLT_Toolkit_Readonly::decide( null, $events, $view ) );
	}

	/**
	 * RS-14's fast path: whether a user holds any marker at all.
	 *
	 * @return void
	 */
	public function test_rs14_holds_any() {
		$markers = array(
			'event' => array(
				'marker' => 'blt_readonly_events',
				'create' => 'create_events',
			),
			'book'  => array(
				'marker' => 'blt_readonly_books',
				'create' => 'create_books',
			),
		);

		$this->assertTrue( BLT_Toolkit_Readonly::holds_any( array( 'blt_readonly_books' => true ), $markers ) );
		$this->assertFalse( BLT_Toolkit_Readonly::holds_any( array( 'blt_readonly_books' => false ), $markers ) );
		$this->assertFalse( BLT_Toolkit_Readonly::holds_any( array( 'edit_events' => true ), $markers ) );
		$this->assertFalse( BLT_Toolkit_Readonly::holds_any( array( 'blt_readonly_events' => true ), array() ) );
	}
}
