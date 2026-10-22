<?php
/**
 * The CI version bump script: it is what keeps the header and the constant in step.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/.github/scripts/bump-version.php';

/**
 * Tests for .github/scripts/bump-version.php.
 */
class Test_Bump_Version extends TestCase {

	/**
	 * A plugin file and readme in the shapes CI edits.
	 *
	 * @param string $version Version.
	 * @return array{0:string,1:string}
	 */
	private function files( $version ) {
		$plugin = "<?php\n/**\n * Plugin Name:       BLT Toolkit\n * Version:           {$version}\n * Author:            S-FX.com\n */\n\ndefine( 'BLT_TOOLKIT_VERSION', '{$version}' ); // Must equal the header.\n";
		$readme = "=== BLT Toolkit ===\nStable tag: {$version}\nLicense: GPLv2 or later\n";

		return array( $plugin, $readme );
	}

	/**
	 * Semantic version arithmetic.
	 *
	 * @return void
	 */
	public function test_next_version() {
		$this->assertSame( '0.1.1', blt_toolkit_next_version( '0.1.0', 'patch' ) );
		$this->assertSame( '0.2.0', blt_toolkit_next_version( '0.1.9', 'minor' ) );
		$this->assertSame( '2.0.0', blt_toolkit_next_version( '1.9.9', 'major' ) );
		$this->assertSame( '1.0.10', blt_toolkit_next_version( '1.0.9', 'patch' ), 'Numeric, not string, comparison.' );
	}

	/**
	 * Bad input is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function test_bad_version_or_level_throws() {
		foreach ( array( array( '1.0', 'patch' ), array( 'v1.0.0', 'patch' ), array( '1.0.0', 'huge' ), array( '', 'patch' ) ) as $case ) {
			try {
				blt_toolkit_next_version( $case[0], $case[1] );
				$this->fail( 'Expected an exception for ' . wp_json_encode( $case ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * The header, the constant and the stable tag all move together.
	 *
	 * @return void
	 */
	public function test_apply_bump_updates_all_three_locations() {
		list( $plugin, $readme ) = $this->files( '0.1.0' );

		$result = blt_toolkit_apply_bump( $plugin, $readme, 'minor' );

		$this->assertSame( '0.2.0', $result['version'] );
		$this->assertStringContainsString( ' * Version:           0.2.0', $result['plugin_file'] );
		$this->assertStringContainsString( "define( 'BLT_TOOLKIT_VERSION', '0.2.0' );", $result['plugin_file'] );
		$this->assertStringContainsString( 'Stable tag: 0.2.0', $result['readme'] );
		$this->assertStringNotContainsString( '0.1.0', $result['plugin_file'] . $result['readme'] );
	}

	/**
	 * A missing or duplicated marker fails the release instead of half-bumping it.
	 *
	 * @return void
	 */
	public function test_missing_marker_fails() {
		list( $plugin, $readme ) = $this->files( '0.1.0' );

		$this->expectException( RuntimeException::class );

		blt_toolkit_apply_bump( str_replace( "define( 'BLT_TOOLKIT_VERSION'", "define( 'SOMETHING_ELSE'", $plugin ), $readme, 'patch' );
	}

	/**
	 * Two version constants would be ambiguous, so that fails too.
	 *
	 * @return void
	 */
	public function test_duplicate_marker_fails() {
		list( $plugin, $readme ) = $this->files( '0.1.0' );

		$this->expectException( RuntimeException::class );

		blt_toolkit_apply_bump( $plugin . "define( 'BLT_TOOLKIT_VERSION', '0.1.0' );\n", $readme, 'patch' );
	}

	/**
	 * The real plugin file and readme carry versions the script can read and agree.
	 *
	 * @return void
	 */
	public function test_real_files_are_bumpable_and_in_step() {
		$root   = dirname( __DIR__ );
		$plugin = (string) file_get_contents( $root . '/blt-toolkit.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( $root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match( '/^ \* Version:\s+(\S+)$/m', $plugin, $header );
		preg_match( "/define\( 'BLT_TOOLKIT_VERSION', '([^']+)' \);/", $plugin, $constant );
		preg_match( '/^Stable tag: (\S+)$/m', $readme, $stable );

		$this->assertNotEmpty( $header );
		$this->assertSame( $header[1], $constant[1], 'Header and constant agree.' );
		$this->assertSame( $header[1], $stable[1], 'And the readme.' );

		$result = blt_toolkit_apply_bump( $plugin, $readme, 'patch' );

		$this->assertNotSame( $header[1], $result['version'] );
	}
}
