<?php
/**
 * The username form shortcode's pure helpers (UN-23).
 *
 * Rendering, the POST handler and the redirect are WordPress-coupled and are
 * smoke-tested on a real install (tasks/todo.md, Phase 3b).
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

/**
 * Tests for BLT_Toolkit_Username_Shortcode.
 */
class Test_Username_Shortcode extends TestCase {

	/**
	 * UN-23: `style` is `styled` unless it asks for no CSS.
	 *
	 * @dataProvider style_vectors
	 *
	 * @param mixed  $value    Attribute value.
	 * @param string $expected Mode.
	 * @return void
	 */
	public function test_un23_style_attribute( $value, $expected ) {
		$this->assertSame( $expected, BLT_Toolkit_Username_Shortcode::style_mode( $value ) );
	}

	/**
	 * Known `style` values.
	 *
	 * @return array
	 */
	public function style_vectors() {
		return array(
			'default'          => array( 'styled', 'styled' ),
			'none'             => array( 'none', 'none' ),
			'none, any case'   => array( ' NONE ', 'none' ),
			'no-css alias'     => array( 'no-css', 'none' ),
			'empty'            => array( '', 'styled' ),
			'unknown'          => array( 'skeleton', 'styled' ),
			'not a string'     => array( array( 'none' ), 'styled' ),
			'number'           => array( 0, 'styled' ),
			'false is not off' => array( 'false', 'styled' ),
		);
	}

	/**
	 * UN-23: every error change() can return maps to a whitelisted result
	 * code, and an unknown code (a UN-9 filter's) reads as a validation error.
	 *
	 * @dataProvider error_vectors
	 *
	 * @param string $error_code WP_Error code.
	 * @param string $expected   Result code.
	 * @return void
	 */
	public function test_un23_result_for_error( $error_code, $expected ) {
		$result = BLT_Toolkit_Username_Shortcode::result_for_error( $error_code );

		$this->assertSame( $expected, $result );
		$this->assertContains( $result, BLT_Toolkit_Username_Shortcode::RESULTS );
	}

	/**
	 * Error codes from BLT_Toolkit_Username_Changer::change() and the
	 * validator.
	 *
	 * @return array
	 */
	public function error_vectors() {
		return array(
			'UN-21 cooldown'          => array( 'blt_toolkit_username_cooldown', 'cooldown' ),
			'not allowed'             => array( 'blt_toolkit_username_forbidden', 'forbidden' ),
			'UN-22 password'          => array( 'blt_toolkit_username_password', 'password' ),
			'UN-12 switched'          => array( 'blt_toolkit_username_switched', 'switched' ),
			'no such user'            => array( 'blt_toolkit_username_no_user', 'failed' ),
			'not saved'               => array( 'blt_toolkit_username_not_saved', 'failed' ),
			'no code'                 => array( '', 'failed' ),
			'UN-1 sanitize'           => array( 'blt_toolkit_username_sanitize', 'invalid' ),
			'UN-2 length'             => array( 'blt_toolkit_username_length', 'invalid' ),
			'UN-3 invalid'            => array( 'blt_toolkit_username_invalid', 'invalid' ),
			'UN-4 disallowed'         => array( 'blt_toolkit_username_disallowed', 'invalid' ),
			'UN-5 taken'              => array( 'blt_toolkit_username_taken', 'invalid' ),
			'UN-6 email'              => array( 'blt_toolkit_username_email', 'invalid' ),
			'UN-7 at sign'            => array( 'blt_toolkit_username_at_sign', 'invalid' ),
			'UN-8 unchanged'          => array( 'blt_toolkit_username_unchanged', 'invalid' ),
			'UN-9 filter-added error' => array( 'my_plugin_reserved', 'invalid' ),
		);
	}

	/**
	 * UN-23: usernames and the symbol list in a notice are printed as code,
	 * so a block theme's wptexturize() cannot turn `a--b` into a dash; the
	 * rest is escaped, and a literal that looks like the markup added for
	 * another is not touched twice.
	 *
	 * @dataProvider literal_vectors
	 *
	 * @param string   $message  Plain message.
	 * @param string[] $literals Literals.
	 * @param string   $expected HTML.
	 * @return void
	 */
	public function test_un23_mark_literals( $message, array $literals, $expected ) {
		$this->assertSame( $expected, BLT_Toolkit_Username_Shortcode::mark_literals( $message, $literals ) );
	}

	/**
	 * Known messages.
	 *
	 * @return array
	 */
	public function literal_vectors() {
		$code = '<code class="blt-tk-username-form__literal">';

		return array(
			'no literals'               => array( 'Plain <b>text</b> & more', array(), 'Plain &lt;b&gt;text&lt;/b&gt; &amp; more' ),
			'success'                   => array( 'Your username is now a--b. Done.', array( 'a--b' ), 'Your username is now ' . $code . 'a--b</code>. Done.' ),
			'UN-1 symbols + suggestion' => array(
				'Only these symbols: . - _ @. Try bad name instead.',
				array( '. - _ @', '. - _', 'bad name' ),
				'Only these symbols: ' . $code . '. - _ @</code>. Try ' . $code . 'bad name</code> instead.',
			),
			'shorter symbol list'       => array( 'Only these symbols: . - _. Try x instead.', array( '. - _ @', '. - _', 'x' ), 'Only these symbols: ' . $code . '. - _</code>. Try ' . $code . 'x</code> instead.' ),
			'literal named like markup' => array( 'Try code instead of class.', array( 'class', 'code' ), 'Try ' . $code . 'code</code> instead of ' . $code . 'class</code>.' ),
			'literal needing escape'    => array( 'Now a&b.', array( 'a&b' ), 'Now ' . $code . 'a&amp;b</code>.' ),
			'absent literal'            => array( 'Nothing to mark.', array( 'zzz', '' ), 'Nothing to mark.' ),
		);
	}

	/**
	 * The result codes are query-argument safe as they are (sanitize_key()
	 * leaves them unchanged), so the check on the way back cannot reject a
	 * code the handler sent.
	 *
	 * @return void
	 */
	public function test_un23_result_codes_survive_sanitize_key() {
		foreach ( BLT_Toolkit_Username_Shortcode::RESULTS as $code ) {
			$this->assertSame( $code, sanitize_key( $code ) );
		}
	}

	/**
	 * UN-23, review fix: what is kept for the result page is cut to a byte
	 * bound without splitting a UTF-8 character, so a multi-megabyte post
	 * cannot become a multi-megabyte transient.
	 *
	 * @return void
	 */
	public function test_un23_cut_bounds_stored_text() {
		$this->assertSame( 'abc', BLT_Toolkit_Username_Shortcode::cut( 'abc', 5 ) );
		$this->assertSame( 'abc', BLT_Toolkit_Username_Shortcode::cut( 'abc', 3 ) );
		$this->assertSame( 'a', BLT_Toolkit_Username_Shortcode::cut( "a\xC3\xA9b", 2 ), 'An é (two bytes) is not split.' );
		$this->assertSame( "a\xC3\xA9", BLT_Toolkit_Username_Shortcode::cut( "a\xC3\xA9b", 3 ) );
		$this->assertSame( '', BLT_Toolkit_Username_Shortcode::cut( 'abc', -1 ) );
		$this->assertSame( BLT_Toolkit_Rest::USERNAME_CHECK_MAX, strlen( BLT_Toolkit_Username_Shortcode::cut( str_repeat( 'x', 3000000 ), BLT_Toolkit_Rest::USERNAME_CHECK_MAX ) ) );
	}
}
