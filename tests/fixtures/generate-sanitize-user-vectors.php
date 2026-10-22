<?php
/**
 * Regenerates tests/fixtures/sanitize-user-vectors.json from a real
 * WordPress install (UN-1, UN-3, SPEC 18). Not loaded by PHPUnit and never
 * shipped. Read-only: it changes nothing on the site.
 *
 *     wp eval-file tests/fixtures/generate-sanitize-user-vectors.php /tmp/out.json
 *
 * For each input it records sanitize_user( $input, true ) and
 * validate_username( $input ), so the validator tests inject core's real
 * answers. Add an input here when a test needs a new one.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable -- A WP-CLI script for tests.

$out_file = isset( $args[0] ) ? $args[0] : 'php://stdout';

$inputs = array(
	'', 'a', 'ab', 'abc', 'jsmith', 'JSmith', 'jsmith2', 'Admin', 'ADMIN', 'admin', 'Root', 'carol', 'Carol', 'new.name', 'new name',
	'new_name', 'new-name', 'Jane Doe', 'padded', str_repeat( 'a', 59 ), str_repeat( 'a', 60 ), str_repeat( 'a', 61 ),
	'José', 'José García', 'Jose', 'Jose Garcia', '<b>bob</b>', 'bob', 'tom&amp;jerry', 'tomjerry', 'a%20b', 'ab',
	'bob!', 'bob#1', 'bob1', 'bob smith!', 'bob smith', 'bob@example.com', 'carol@example.com', 'CAROL@EXAMPLE.COM',
	'jsmith@example.com', 'name@', 'bob@home', 'bob@home!', 'bobhome', '@@@', '!!!', 'ünïcödé', 'unicode', '李小龍',
	'a  b', 'a b', "tab\there", 'tab here', 'webmaster', 'WebMaster', 'sysadmin', 'reserved', 'blocked', 'Blocked',
	'x@y', 'xy', 'bob@', 'carol2', 'alice', 'dave', 'erin', 'temp1', 'Bob', 'ALICE',
);

$vectors = array(
	'about' => 'Generated from WordPress ' . get_bloginfo( 'version' ) . ' on PHP ' . PHP_VERSION . ': sanitize_user( $input, true ) and validate_username( $input ) (UN-1, UN-3). Do not edit by hand.',
	'strict' => array(),
	'valid'  => array(),
);

foreach ( array_unique( $inputs ) as $input ) {
	$vectors['strict'][ $input ] = sanitize_user( $input, true );
	$vectors['valid'][ $input ]  = validate_username( $input );
}

file_put_contents( $out_file, wp_json_encode( $vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
echo count( $vectors['strict'] ) . " inputs\n";
