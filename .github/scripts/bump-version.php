<?php
/**
 * Version bump for CI.
 *
 * Usage: php .github/scripts/bump-version.php <patch|minor|major>
 *
 * Reads the current version from the plugin header (the file — not git
 * tags — is the source of truth, so the first run works with no tags),
 * computes the next semver, and rewrites all three version locations:
 * the Version: header and BLT_TOOLKIT_VERSION in blt-toolkit.php, and
 * Stable tag in readme.txt. Exits non-zero unless every replacement
 * matched exactly once. Prints the new version to stdout.
 *
 * The CLI block below is guarded so the unit-test suite can include this
 * file for blt_toolkit_next_version() without side effects. It has no
 * ABSPATH guard on purpose: it runs without WordPress and never ships
 * (.github is excluded from the release zip).
 *
 * @package Blt_Toolkit
 */

/**
 * Compute the next semantic version.
 *
 * @param string $current Current X.Y.Z version.
 * @param string $level One of patch|minor|major.
 * @return string Next version.
 * @throws InvalidArgumentException On malformed input.
 */
function blt_toolkit_next_version( $current, $level ) {
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', (string) $current, $m ) ) {
		throw new InvalidArgumentException( "Not a X.Y.Z version: {$current}" );
	}

	list( , $major, $minor, $patch ) = array_map( 'intval', $m );

	switch ( $level ) {
		case 'major':
			return ( $major + 1 ) . '.0.0';
		case 'minor':
			return $major . '.' . ( $minor + 1 ) . '.0';
		case 'patch':
			return $major . '.' . $minor . '.' . ( $patch + 1 );
	}

	throw new InvalidArgumentException( "Unknown bump level: {$level}" );
}

/**
 * Apply the bump to file contents.
 *
 * @param string $plugin_file Contents of blt-toolkit.php.
 * @param string $readme Contents of readme.txt.
 * @param string $level Bump level.
 * @return array{version: string, plugin_file: string, readme: string}
 * @throws RuntimeException When a version marker is missing or ambiguous.
 */
function blt_toolkit_apply_bump( $plugin_file, $readme, $level ) {
	if ( ! preg_match( '/^ \* Version:\s+(\d+\.\d+\.\d+)$/m', $plugin_file, $m ) ) {
		throw new RuntimeException( 'Could not find the Version: header in blt-toolkit.php' );
	}
	$next = blt_toolkit_next_version( $m[1], $level );

	$replacements = array(
		'plugin_file' => array(
			array( '/^( \* Version:\s+)\d+\.\d+\.\d+$/m', '${1}' . $next ),
			array( "/(define\( 'BLT_TOOLKIT_VERSION', ')\d+\.\d+\.\d+(' \);)/", '${1}' . $next . '${2}' ),
		),
		'readme'      => array(
			array( '/^(Stable tag: )\d+\.\d+\.\d+$/m', '${1}' . $next ),
		),
	);

	$contents = array(
		'plugin_file' => $plugin_file,
		'readme'      => $readme,
	);

	foreach ( $replacements as $target => $patterns ) {
		foreach ( $patterns as $pair ) {
			list( $pattern, $replacement ) = $pair;

			$count               = 0;
			$contents[ $target ] = preg_replace( $pattern, $replacement, $contents[ $target ], -1, $count );
			if ( 1 !== $count ) {
				throw new RuntimeException( "Pattern {$pattern} matched {$count} times in {$target} (expected exactly 1)" );
			}
		}
	}

	return array(
		'version'     => $next,
		'plugin_file' => $contents['plugin_file'],
		'readme'      => $contents['readme'],
	);
}

// CLI entry point — inert when this file is include()d by tests.
if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$level = isset( $argv[1] ) ? $argv[1] : 'patch';
	$root  = dirname( __DIR__, 2 );

	try {
		$result = blt_toolkit_apply_bump(
			(string) file_get_contents( $root . '/blt-toolkit.php' ),
			(string) file_get_contents( $root . '/readme.txt' ),
			$level
		);
	} catch ( Exception $e ) {
		fwrite( STDERR, $e->getMessage() . "\n" );
		exit( 1 );
	}

	file_put_contents( $root . '/blt-toolkit.php', $result['plugin_file'] );
	file_put_contents( $root . '/readme.txt', $result['readme'] );

	echo $result['version'] . "\n";
}
