<?php
/**
 * The switch cookie stack's pure operations (SW-3, SW-7, SPEC 18).
 *
 * Reading and writing the cookies, validating entries with
 * wp_validate_auth_cookie() and the flows that use them are smoke-tested
 * against a real install and recorded in tasks/todo.md.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Switch_Cookies.
 */
class Test_Switch_Cookies extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * SW-3: push adds the newest entry last; peek reads it without changing
	 * the stack; pop removes it.
	 *
	 * @return void
	 */
	public function test_sw3_push_peek_pop() {
		$json = BLT_Toolkit_Switch_Cookies::push( '', 'admin|1|tok-a|hmac' );

		$this->assertSame( '["admin|1|tok-a|hmac"]', $json );
		$this->assertSame( 'admin|1|tok-a|hmac', BLT_Toolkit_Switch_Cookies::peek( $json ) );
		$this->assertSame( array( 'admin|1|tok-a|hmac' ), BLT_Toolkit_Switch_Cookies::parse( $json ) );

		$json = BLT_Toolkit_Switch_Cookies::pop( $json );

		$this->assertSame( '[]', $json );
		$this->assertSame( '', BLT_Toolkit_Switch_Cookies::peek( $json ) );
		$this->assertSame( array(), BLT_Toolkit_Switch_Cookies::parse( $json ) );
		$this->assertSame( '[]', BLT_Toolkit_Switch_Cookies::pop( $json ), 'Popping an empty stack leaves it empty.' );
	}

	/**
	 * SW-3, SW-5: three levels deep (admin, then editor, then author switched
	 * in), then back one level at a time, newest first.
	 *
	 * @return void
	 */
	public function test_sw3_nested_depth() {
		$json = '';

		foreach ( array( 'admin', 'editor', 'author' ) as $login ) {
			$json = BLT_Toolkit_Switch_Cookies::push( $json, $login . '|1700000000|token|hmac' );
		}

		$this->assertSame( array( 'admin|1700000000|token|hmac', 'editor|1700000000|token|hmac', 'author|1700000000|token|hmac' ), BLT_Toolkit_Switch_Cookies::parse( $json ), 'Oldest first.' );
		$this->assertSame( 'author|1700000000|token|hmac', BLT_Toolkit_Switch_Cookies::peek( $json ) );

		$json = BLT_Toolkit_Switch_Cookies::pop( $json );
		$this->assertSame( 'editor|1700000000|token|hmac', BLT_Toolkit_Switch_Cookies::peek( $json ) );

		$json = BLT_Toolkit_Switch_Cookies::pop( $json );
		$this->assertSame( 'admin|1700000000|token|hmac', BLT_Toolkit_Switch_Cookies::peek( $json ) );
		$this->assertCount( 1, BLT_Toolkit_Switch_Cookies::parse( $json ) );

		$json = BLT_Toolkit_Switch_Cookies::pop( $json );
		$this->assertSame( array(), BLT_Toolkit_Switch_Cookies::parse( $json ) );
	}

	/**
	 * SPEC 18: malformed JSON, and anything that is not a JSON list of
	 * strings, is an empty stack; pushing onto it starts a new stack.
	 *
	 * @return void
	 */
	public function test_sw3_malformed_json_is_an_empty_stack() {
		$bad = array(
			'not json',
			'["unterminated"',
			'{"a":"admin|1|t|h"}',
			'{"0":"admin|1|t|h"}',
			'"admin|1|t|h"',
			'42',
			'null',
			'true',
			'',
			'[[]]',
		);

		foreach ( $bad as $json ) {
			$this->assertSame( array(), BLT_Toolkit_Switch_Cookies::parse( $json ), $json );
			$this->assertSame( '', BLT_Toolkit_Switch_Cookies::peek( $json ), $json );
			$this->assertSame( '[]', BLT_Toolkit_Switch_Cookies::pop( $json ), $json );
			$this->assertSame( '["new"]', BLT_Toolkit_Switch_Cookies::push( $json, 'new' ), $json );
		}

		foreach ( array( null, 7, array( 'a' ), false ) as $value ) {
			$this->assertSame( array(), BLT_Toolkit_Switch_Cookies::parse( $value ) );
		}
	}

	/**
	 * Entries that are not non-empty strings are dropped, the rest kept in
	 * order; an empty or non-string entry is never pushed.
	 *
	 * @return void
	 */
	public function test_sw3_non_string_entries_are_dropped() {
		$this->assertSame( array( 'a', 'b' ), BLT_Toolkit_Switch_Cookies::parse( '["a",1,null,"",{"x":1},["y"],true,"b"]' ) );
		$this->assertSame( '["a"]', BLT_Toolkit_Switch_Cookies::push( '["a"]', '' ) );
		$this->assertSame( '[]', BLT_Toolkit_Switch_Cookies::encode( array( '', 3, null ) ) );
	}

	/**
	 * Cookie values hold characters core's auth cookies use (`|`, `%`, `/`,
	 * quotes in logins) and survive a round trip.
	 *
	 * @return void
	 */
	public function test_sw3_round_trip() {
		$entry = 'o\'brien%40example.com|1700000000|AbC/123+x|' . str_repeat( 'f', 64 );
		$json  = BLT_Toolkit_Switch_Cookies::push( BLT_Toolkit_Switch_Cookies::push( '', 'admin|1|t|h' ), $entry );

		$this->assertSame( array( 'admin|1|t|h', $entry ), BLT_Toolkit_Switch_Cookies::parse( $json ) );
		$this->assertSame( $json, BLT_Toolkit_Switch_Cookies::encode( BLT_Toolkit_Switch_Cookies::parse( $json ) ) );
	}

	/**
	 * The stack keeps at most MAX_DEPTH entries; the oldest go first.
	 *
	 * @return void
	 */
	public function test_sw3_depth_is_capped() {
		$json = '';
		$max  = BLT_Toolkit_Switch_Cookies::MAX_DEPTH;

		for ( $i = 1; $i <= $max + 2; $i++ ) {
			$json = BLT_Toolkit_Switch_Cookies::push( $json, 'user' . $i );
		}

		$stack = BLT_Toolkit_Switch_Cookies::parse( $json );

		$this->assertCount( $max, $stack );
		$this->assertSame( 'user3', $stack[0] );
		$this->assertSame( 'user' . ( $max + 2 ), BLT_Toolkit_Switch_Cookies::peek( $json ) );
	}

	/**
	 * SW-3: default names and the `wordpress_` prefix page caches bypass on.
	 *
	 * @return void
	 */
	public function test_sw3_default_names() {
		$this->assertSame( 'wordpress_blt_sw_abc123', BLT_Toolkit_Switch_Cookies::default_name( BLT_Toolkit_Switch_Cookies::AUTH, 'abc123' ) );
		$this->assertSame( 'wordpress_blt_sw_secure_abc123', BLT_Toolkit_Switch_Cookies::default_name( BLT_Toolkit_Switch_Cookies::SECURE, 'abc123' ) );
		$this->assertSame( 'wordpress_blt_sw_olduser_abc123', BLT_Toolkit_Switch_Cookies::default_name( BLT_Toolkit_Switch_Cookies::OLDUSER, 'abc123' ) );
		$this->assertSame( 'auth', BLT_Toolkit_Switch_Cookies::scheme( BLT_Toolkit_Switch_Cookies::AUTH ) );
		$this->assertSame( 'secure_auth', BLT_Toolkit_Switch_Cookies::scheme( BLT_Toolkit_Switch_Cookies::SECURE ) );
	}

	/**
	 * SW-7: a cookie is "remember me" when its remaining lifetime is longer
	 * than the default lifetime (two days), as User Switching's remember().
	 *
	 * @return void
	 */
	public function test_sw7_is_persistent() {
		$now  = 1700000000;
		$life = 2 * DAY_IN_SECONDS;

		$this->assertTrue( BLT_Toolkit_Switch_Cookies::is_persistent( $now + 14 * DAY_IN_SECONDS, $now, $life ), 'A fresh 14-day cookie.' );
		$this->assertTrue( BLT_Toolkit_Switch_Cookies::is_persistent( $now + $life + 1, $now, $life ) );
		$this->assertFalse( BLT_Toolkit_Switch_Cookies::is_persistent( $now + $life, $now, $life ), 'Exactly the default is not persistent.' );
		$this->assertFalse( BLT_Toolkit_Switch_Cookies::is_persistent( $now + HOUR_IN_SECONDS, $now, $life ) );
		$this->assertFalse( BLT_Toolkit_Switch_Cookies::is_persistent( $now - 1, $now, $life ), 'An expired cookie.' );
	}

	/**
	 * SW-6, SW-5: a full stack has no room, so switch off is refused like
	 * switch to instead of pushing and dropping the oldest entry, the person
	 * who signed in.
	 *
	 * @return void
	 */
	public function test_sw6_full_stack_is_refused_not_trimmed() {
		$max   = BLT_Toolkit_Switch_Cookies::MAX_DEPTH;
		$stack = array();

		for ( $i = 1; $i < $max; $i++ ) {
			$stack[] = 'user' . $i;
		}

		$this->assertFalse( BLT_Toolkit_Switch_Cookies::is_full( $stack ), 'One short of the limit still has room.' );

		$stack[] = 'user' . $max;

		$this->assertTrue( BLT_Toolkit_Switch_Cookies::is_full( $stack ) );
		$this->assertFalse( BLT_Toolkit_Switch_Cookies::is_full( array() ) );
	}

	/**
	 * SW-7, SW-3: the switch cookies persist only when the actor's sign-in
	 * does; otherwise they are browser-session cookies (expires 0).
	 *
	 * @return void
	 */
	public function test_sw7_cookies_follow_remember_me() {
		$this->assertSame( 1700172800, BLT_Toolkit_Switch_Cookies::expires( 1700172800, true ) );
		$this->assertSame( 0, BLT_Toolkit_Switch_Cookies::expires( 1700172800, false ) );
	}

	/**
	 * SW-3, SW-2: the stack lives on SITECOOKIEPATH, unless the login URL
	 * (BLT Secure's custom slug on a home URL) is outside it; then on the
	 * longest directory both share, so switching back there receives it.
	 *
	 * @return void
	 */
	public function test_sw3_stack_path_covers_the_login_url() {
		// WordPress at the root: unchanged.
		$this->assertSame( '/', BLT_Toolkit_Switch_Cookies::path_for( '/', '/wp-login.php' ) );
		$this->assertSame( '/', BLT_Toolkit_Switch_Cookies::path_for( '/', '/team-login' ) );

		// WordPress in its own directory, core login screen: SITECOOKIEPATH.
		$this->assertSame( '/wp/', BLT_Toolkit_Switch_Cookies::path_for( '/wp/', '/wp/wp-login.php' ) );
		$this->assertSame( '/wp/', BLT_Toolkit_Switch_Cookies::path_for( '/wp/', '' ), 'Unknown login path.' );

		// A home-path slug outside it.
		$this->assertSame( '/', BLT_Toolkit_Switch_Cookies::path_for( '/wp/', '/my-login/' ) );
		$this->assertSame( '/', BLT_Toolkit_Switch_Cookies::path_for( '/wp/', '/team-login' ) );
		$this->assertSame( '/blog/', BLT_Toolkit_Switch_Cookies::path_for( '/blog/wp/', '/blog/team-login' ) );
		$this->assertSame( '/blog/', BLT_Toolkit_Switch_Cookies::path_for( '/blog/wp', '/blog/team-login/' ), 'SITECOOKIEPATH without its trailing slash.' );

		// `/blog/` does not cover a login path of exactly `/blog`.
		$this->assertSame( '/', BLT_Toolkit_Switch_Cookies::path_for( '/blog/wp/', '/blog' ) );
		$this->assertSame( '/blog/', BLT_Toolkit_Switch_Cookies::path_for( '/blog/wp/', '/blog/' ) );

		// A shared prefix that is not a whole directory does not count.
		$this->assertSame( '/', BLT_Toolkit_Switch_Cookies::path_for( '/wp/', '/wp-login/' ) );
	}
}
