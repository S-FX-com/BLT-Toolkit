<?php
/**
 * Author slug derivation (UN-13) against outputs recorded from a real
 * wp_insert_user() (tests/fixtures/nicename-vectors.json, SPEC 18).
 *
 * The fixture holds, for every login, what core's sanitize_title() returned
 * for the first 50 characters and the nicename wp_insert_user() then stored,
 * with the colliding slugs that existed at the time. The tests inject a
 * sanitizer that answers from that recording and fails on any other input,
 * so they check BLT's truncation and suffix logic against core's real
 * results without WordPress. Regenerate the fixture with
 * tests/fixtures/generate-nicename-vectors.php.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Nicename.
 */
class Test_Nicename extends TestCase {

	/**
	 * The decoded fixture.
	 *
	 * @var array|null
	 */
	private static $vectors = null;

	/**
	 * The fixture, decoded once.
	 *
	 * @return array
	 */
	private static function vectors() {
		if ( null === self::$vectors ) {
			self::$vectors = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/nicename-vectors.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$vectors;
	}

	/**
	 * A sanitizer that answers with core's recorded sanitize_title() output
	 * and fails the test for any input core was not asked about.
	 *
	 * @return callable
	 */
	private function core_title() {
		$titles = self::vectors()['sanitize_title'];

		return function ( $title ) use ( $titles ) {
			if ( ! array_key_exists( $title, $titles ) ) {
				throw new RuntimeException( 'sanitize_title() was called with an input core never saw: ' . $title );
			}

			return $titles[ $title ];
		};
	}

	/**
	 * A "taken" lookup over a fixed list of slugs, recording every probe.
	 *
	 * @param string[] $slugs  Slugs other users hold.
	 * @param string[] $probes Receives each slug asked about.
	 * @return callable
	 */
	private function taken( array $slugs, &$probes = array() ) {
		$set = array_fill_keys( $slugs, true );

		return function ( $slug ) use ( $set, &$probes ) {
			$probes[] = $slug;

			return isset( $set[ $slug ] );
		};
	}

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * The fixture covers what SPEC 18 lists: accents, spaces, `@`, 60-character
	 * logins, collisions needing -2 and -3, plus the task's extra shapes.
	 *
	 * @return void
	 */
	public function test_un13_fixture_covers_spec18_table() {
		$labels = array_merge(
			array_column( self::vectors()['base'], 'label' ),
			array_column( self::vectors()['unique'], 'label' )
		);

		foreach ( array( 'space', 'email style', 'accents, raw', 'cyrillic short, raw', 'cjk, raw', '49 characters', '50 characters', '51 characters', '60 characters', '49 ASCII then cyrillic, raw', 'needs -2', 'needs -3', 'needs -10', '60-character login sharing its first 50 with another' ) as $label ) {
			$this->assertContains( $label, $labels );
		}

		$this->assertStringContainsString( 'wp_insert_user()', self::vectors()['about'] );
	}

	/**
	 * UN-13: base() is sanitize_title( mb_substr( $login, 0, 50 ) ) for every
	 * recorded login, including multibyte characters on the 50-character
	 * boundary, and core stored exactly that base when nothing collided.
	 *
	 * @return void
	 */
	public function test_un13_base_matches_wp_insert_user() {
		foreach ( self::vectors()['base'] as $vector ) {
			$base = BLT_Toolkit_Nicename::base( $vector['login'], $this->core_title() );

			$this->assertSame( $vector['base'], $base, $vector['label'] );

			if ( null === $vector['core_error'] ) {
				$this->assertSame( $vector['nicename'], $base, $vector['label'] . ': core stored the base itself.' );
				$this->assertTrue( BLT_Toolkit_Nicename::fits( $base ), $vector['label'] );
			} else {
				$this->assertFalse( BLT_Toolkit_Nicename::fits( $base ), $vector['label'] . ': core refused it (' . $vector['core_error'] . ').' );
			}
		}
	}

	/**
	 * UN-13: the 50-character cut happens before sanitizing, on characters
	 * rather than bytes, as core's mb_substr() does.
	 *
	 * @return void
	 */
	public function test_un13_base_truncates_login_before_sanitizing() {
		$seen = array();
		$spy  = function ( $title ) use ( &$seen ) {
			$seen[] = $title;

			return strtolower( $title );
		};

		BLT_Toolkit_Nicename::base( str_repeat( 'a', 49 ) . 'ЖЖ', $spy );
		BLT_Toolkit_Nicename::base( 'Short', $spy );

		$this->assertSame( array( str_repeat( 'a', 49 ) . 'Ж', 'Short' ), $seen );
	}

	/**
	 * UN-13: with the colliding slugs that existed, unique() returns the
	 * nicename wp_insert_user() stored: -2, -3, -10 and -100, truncation of 49
	 * and 50-character bases (including one cut inside a %xx escape, as core
	 * does), and the first free suffix when a later one is taken.
	 *
	 * @return void
	 */
	public function test_un13_unique_matches_wp_insert_user() {
		foreach ( self::vectors()['unique'] as $vector ) {
			$this->assertNull( $vector['core_error'], $vector['label'] );
			$this->assertSame( $vector['base'], BLT_Toolkit_Nicename::base( $vector['login'], $this->core_title() ), $vector['label'] );

			$nicename = BLT_Toolkit_Nicename::unique( $vector['base'], $this->taken( $vector['taken'] ) );

			$this->assertSame( $vector['nicename'], $nicename, $vector['label'] );
			$this->assertLessThanOrEqual( 50, mb_strlen( $nicename ), $vector['label'] );
		}
	}

	/**
	 * UN-13: the suffix loop probes the base, then -2, -3 ... in order, and
	 * stops at the first free one.
	 *
	 * @return void
	 */
	public function test_un13_unique_probe_order() {
		$probes = array();

		$this->assertSame( 'dave-3', BLT_Toolkit_Nicename::unique( 'dave', $this->taken( array( 'dave', 'dave-2' ), $probes ) ) );
		$this->assertSame( array( 'dave', 'dave-2', 'dave-3' ), $probes );

		$probes = array();

		$this->assertSame( 'erin', BLT_Toolkit_Nicename::unique( 'erin', $this->taken( array( 'erin-2' ), $probes ) ) );
		$this->assertSame( array( 'erin' ), $probes, 'A free base needs one probe.' );
	}

	/**
	 * UN-13: a lookup that never reports a free slug cannot hang a request;
	 * unique() gives up with '' after MAX_SUFFIX tries, which fits() refuses.
	 *
	 * @return void
	 */
	public function test_un13_unique_gives_up_on_a_lookup_that_is_always_taken() {
		$calls  = 0;
		$always = function () use ( &$calls ) {
			++$calls;

			return true;
		};

		$this->assertSame( '', BLT_Toolkit_Nicename::unique( 'zed', $always ) );
		$this->assertSame( BLT_Toolkit_Nicename::MAX_SUFFIX, $calls );
		$this->assertFalse( BLT_Toolkit_Nicename::fits( '' ) );
	}

	/**
	 * UN-13: every nicename core stored in the fixture counts as derived from
	 * its own login, with and without a suffix.
	 *
	 * @return void
	 */
	public function test_un13_is_derived_for_every_core_nicename() {
		$pairs = 0;

		foreach ( array_merge( self::vectors()['base'], self::vectors()['unique'] ) as $vector ) {
			if ( null === $vector['nicename'] ) {
				continue;
			}

			++$pairs;
			$this->assertTrue( BLT_Toolkit_Nicename::is_derived( $vector['nicename'], $vector['login'], $this->core_title() ), $vector['label'] );
		}

		$this->assertGreaterThan( 30, $pairs );
	}

	/**
	 * UN-13: slugs core would not have produced from the login are not
	 * derived: a custom slug, -1, a leading zero, a suffix core would have cut
	 * the base for, a cut of the wrong length, another login's base, and
	 * empty values.
	 *
	 * @return void
	 */
	public function test_un13_is_derived_rejects_other_slugs() {
		$title = $this->core_title();
		$long  = 'User.Name_abcdefghijabcdefghijabcdefghijabcdefghij';
		$base  = 'user-name_abcdefghijabcdefghijabcdefghijabcdefghij';

		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'john', 'jsmith', $title ), 'A slug the owner chose.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'jsmith-1', 'jsmith', $title ), 'Core never uses -1.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'jsmith-02', 'jsmith', $title ), 'Core never writes a leading zero.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'jsmith-2x', 'jsmith', $title ) );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'jsmith-', 'jsmith', $title ) );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'jsmit-2', 'jsmith', $title ), 'Short bases are never cut.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( $base . '-2', $long, $title ), 'Core cuts a 50-character base to 48 before -2.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( mb_substr( $base, 0, 47 ) . '-2', $long, $title ), 'Cut one character too many.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( mb_substr( $base, 0, 48 ) . '-10', $long, $title ), 'A two-digit suffix needs a 47-character cut.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'carol', 'jsmith', $title ), "Another login's base." );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( '', 'jsmith', $title ) );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( '', '@@@', $title ), 'An empty base derives nothing.' );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( '-2', '@@@', $title ) );
	}

	/**
	 * UN-13: a login that itself ends in -N derives both its base and the
	 * suffixed form, and only through the last dash.
	 *
	 * @return void
	 */
	public function test_un13_is_derived_with_a_login_that_looks_suffixed() {
		$title = $this->core_title();

		$this->assertTrue( BLT_Toolkit_Nicename::is_derived( 'bob-2', 'bob-2', $title ) );
		$this->assertTrue( BLT_Toolkit_Nicename::is_derived( 'bob-2-2', 'bob-2', $title ) );
		$this->assertTrue( BLT_Toolkit_Nicename::is_derived( 'bob-2-15', 'bob-2', $title ) );
		$this->assertFalse( BLT_Toolkit_Nicename::is_derived( 'bob-3', 'bob-2', $title ) );
	}

	/**
	 * UN-13 (UC-2): the new slug for a rename goes through the same pipeline
	 * as core, never the raw username: a 60-character email-style login is
	 * sanitized, cut to 50 and suffixed when taken.
	 *
	 * @return void
	 */
	public function test_uc2_new_slug_is_sanitized_truncated_and_unique() {
		$sanitize = function ( $t ) {
			return trim( preg_replace( '/[^a-z0-9_]+/', '-', strtolower( $t ) ), '-' );
		};
		$login    = str_repeat( 'abcdefghij', 4 ) . '.smith@example.com';
		$base     = BLT_Toolkit_Nicename::base( $login, $sanitize );

		$this->assertSame( str_repeat( 'abcdefghij', 4 ) . '-smith-exa', $base );
		$this->assertSame( 50, mb_strlen( $base ) );
		$this->assertSame( mb_substr( $base, 0, 48 ) . '-2', BLT_Toolkit_Nicename::unique( $base, $this->taken( array( $base ) ) ) );
	}

	/**
	 * UN-13: fits() mirrors core's refusals: empty (PHP's empty(), so '0'
	 * too) or longer than 50 characters, counted in characters.
	 *
	 * @return void
	 */
	public function test_un13_fits() {
		$this->assertTrue( BLT_Toolkit_Nicename::fits( 'jsmith' ) );
		$this->assertTrue( BLT_Toolkit_Nicename::fits( str_repeat( 'a', 50 ) ) );
		$this->assertFalse( BLT_Toolkit_Nicename::fits( str_repeat( 'a', 51 ) ) );
		$this->assertFalse( BLT_Toolkit_Nicename::fits( '' ) );
		$this->assertFalse( BLT_Toolkit_Nicename::fits( '0' ) );
		$this->assertTrue( BLT_Toolkit_Nicename::fits( str_repeat( 'é', 50 ) ), 'Characters, not bytes.' );
	}
}
