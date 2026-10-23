<?php
/**
 * Regenerates tests/fixtures/nicename-vectors.json from a real WordPress
 * install (UN-13, SPEC 18). Not loaded by PHPUnit and never shipped.
 *
 * Run it with WP-CLI against a disposable site (it deletes every user except
 * ID 1 between scenarios), then copy the output over the fixture:
 *
 *     wp eval-file tests/fixtures/generate-nicename-vectors.php /tmp/vectors.json
 *
 * Each target user is created with wp_insert_user(), so the recorded
 * nicename is the one core assigns. "Taken" nicenames are occupied by rows
 * inserted straight into the users table, since core's uniqueness check is a
 * plain SELECT there. Rows marked raw add a sanitize_user filter that keeps
 * the login as typed, as plugins that allow non-Latin usernames do, so
 * logins outside sanitize_user()'s strict set are covered too.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable -- A WP-CLI script for tests; it writes straight to the users table on purpose.

global $wpdb;

$out_file = isset( $args[0] ) ? $args[0] : 'php://stdout';

$p3_raw = false;
add_filter(
	'sanitize_user',
	function ( $username, $raw, $strict ) use ( &$p3_raw ) {
		if ( $p3_raw ) {
			return preg_replace( '|\s+|', ' ', trim( $raw ) );
		}
		return $username;
	},
	10,
	3
);

$titles = array();

$record_title = function ( $s ) use ( &$titles ) {
	$titles[ $s ] = sanitize_title( $s );
	return $titles[ $s ];
};

$reset = function () use ( $wpdb ) {
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE ID > 1" );
	foreach ( $ids as $id ) {
		$wpdb->delete( $wpdb->users, array( 'ID' => $id ) );
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $id ) );
		clean_user_cache( (int) $id );
	}
	wp_cache_flush();
};

$n = 0;

$occupy = function ( $nicename ) use ( $wpdb, &$n ) {
	++$n;
	$wpdb->insert(
		$wpdb->users,
		array(
			'user_login'      => 'occ' . $n,
			'user_pass'       => 'x',
			'user_nicename'   => $nicename,
			'user_email'      => 'occ' . $n . '@example.test',
			'user_registered' => '2026-01-01 00:00:00',
			'display_name'    => 'occ' . $n,
		)
	);
};

$create = function ( $login, $raw ) use ( &$p3_raw, &$n, $wpdb ) {
	++$n;
	$p3_raw = $raw;
	$id     = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => 'pw-' . $n,
			'user_email' => 'target' . $n . '@example.test',
		)
	);
	$p3_raw = false;
	if ( is_wp_error( $id ) ) {
		return array( 'error' => $id->get_error_code() );
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_login, user_nicename FROM {$wpdb->users} WHERE ID = %d", $id ) );
	return array(
		'stored_login' => $row->user_login,
		'nicename'     => $row->user_nicename,
	);
};

$taken_now = function ( $login ) use ( $wpdb ) {
	return $wpdb->get_col( $wpdb->prepare( "SELECT user_nicename FROM {$wpdb->users} WHERE user_login != %s ORDER BY ID", $login ) );
};

// Logins.
$ten  = 'abcdefghij';
$long = function ( $len, $tail = '' ) use ( $ten ) {
	$s = 'User.Name_' . str_repeat( $ten, 7 );
	return substr( $s, 0, $len - strlen( $tail ) ) . $tail;
};

$base_rows = array(
	array( 'plain lowercase', 'jsmith', false ),
	array( 'uppercase', 'JSmith', false ),
	array( 'space', 'John Smith', false ),
	array( 'collapsed whitespace', "john \t  smith", false ),
	array( 'email style', 'john.smith@example.com', false ),
	array( 'underscore', 'jane_doe', false ),
	array( 'repeated dashes', 'a-b--c', false ),
	array( 'leading and trailing dashes', '--bob--', false ),
	array( 'mixed case with dot', 'ADMIN.User', false ),
	array( 'digits', '12345', false ),
	array( 'looks suffixed', 'bob-2', false ),
	array( 'accents, core sanitized', 'José García', false ),
	array( 'accents, raw', 'José García', true ),
	array( 'more accents, raw', 'Zoë Ünal-Øster', true ),
	array( 'angstrom, raw', 'Ångström', true ),
	array( 'apostrophe, raw', "O'Brien", true ),
	array( 'cyrillic short, raw', 'Иван', true ),
	array( 'cyrillic long, raw', 'Иван Петров', true ),
	array( 'cjk, raw', '李小龍', true ),
	array( 'greek, raw', 'Σωκράτης', true ),
	array( 'emoji, raw', 'rock🎸star', true ),
	array( 'only at signs', '@@@', false ),
	array( 'only dots', '...', false ),
	array( '49 characters', $long( 49 ), false ),
	array( '50 characters', $long( 50 ), false ),
	array( '51 characters', $long( 51 ), false ),
	array( '60 characters', $long( 60 ), false ),
	array( '49 ASCII then accented, raw', $long( 49 ) . 'ézz', true ),
	array( '49 ASCII then cyrillic, raw', $long( 49 ) . 'Жzz', true ),
	array( '50 ASCII then cyrillic, raw', $long( 50 ) . 'Ж', true ),
	array( '2 ASCII then 8 cyrillic, raw', 'ab' . 'Александ', true ),
);

$vectors = array(
	'about'  => 'Generated from WordPress ' . get_bloginfo( 'version' ) . ' on PHP ' . PHP_VERSION . ' by wp_insert_user() (UN-13, SPEC 18). Do not edit by hand.',
	'base'   => array(),
	'unique' => array(),
);

foreach ( $base_rows as $row ) {
	list( $label, $login, $raw ) = $row;
	$reset();
	$res    = $create( $login, $raw );
	$stored = isset( $res['stored_login'] ) ? $res['stored_login'] : null;
	if ( null === $stored ) {
		// Core refused; record what it would have used as the login.
		$p3_raw = $raw;
		$stored = trim( sanitize_user( $login, true ) );
		$p3_raw = false;
	}
	$cut  = mb_substr( $stored, 0, 50 );
	$base = $record_title( $cut );
	$vectors['base'][] = array(
		'label'      => $label,
		'raw'        => $raw,
		'input'      => $login,
		'login'      => $stored,
		'base'       => $base,
		'nicename'   => isset( $res['nicename'] ) ? $res['nicename'] : null,
		'core_error' => isset( $res['error'] ) ? $res['error'] : null,
	);
}

// Collision scenarios: array( label, login, raw, taken nicenames as a closure of base ).
$range = function ( $base, $from, $to ) {
	$out = array();
	for ( $i = $from; $i <= $to; $i++ ) {
		$out[] = mb_substr( $base, 0, 49 - strlen( (string) $i ) ) . '-' . $i;
	}
	return $out;
};

$collisions = array(
	array( 'needs -2', 'carol', false, function ( $b ) { return array( $b ); } ),
	array( 'needs -3', 'dave', false, function ( $b ) { return array( $b, $b . '-2' ); } ),
	array( 'needs -10', 'erin', false, function ( $b ) use ( $range ) { return array_merge( array( $b ), $range( $b, 2, 9 ) ); } ),
	array( 'first free suffix wins', 'frank', false, function ( $b ) { return array( $b, $b . '-3' ); } ),
	array( 'uppercase login collides with lowercase slug', 'Grace.Hopper', false, function ( $b ) { return array( $b ); } ),
	array( '49-character base truncated for -2', $long( 49 ), false, function ( $b ) { return array( $b ); } ),
	array( '50-character base truncated for -2', $long( 50 ), false, function ( $b ) { return array( $b ); } ),
	array( '50-character base truncated for -10', $long( 50 ), false, function ( $b ) use ( $range ) { return array_merge( array( $b ), $range( $b, 2, 9 ) ); } ),
	array( '50-character base truncated for -100', $long( 50 ), false, function ( $b ) use ( $range ) { return array_merge( array( $b ), $range( $b, 2, 99 ) ); } ),
	array( '60-character login sharing its first 50 with another', $long( 60, 'ZZ' ), false, function ( $b ) { return array( $b ); } ),
	array( 'cyrillic needs -2, raw', 'Иван', true, function ( $b ) { return array( $b ); } ),
	array( 'encoded 50-character base cut inside an escape, raw', 'ab' . 'Александ', true, function ( $b ) { return array( $b ); } ),
	array( 'accented boundary needs -2, raw', $long( 49 ) . 'ézz', true, function ( $b ) { return array( $b ); } ),
	array( 'looks suffixed needs -2', 'bob-2', false, function ( $b ) { return array( $b ); } ),
);

foreach ( $collisions as $row ) {
	list( $label, $login, $raw, $taken_fn ) = $row;
	$reset();
	$p3_raw = $raw;
	$stored = trim( sanitize_user( $login, true ) );
	$p3_raw = false;
	$cut    = mb_substr( $stored, 0, 50 );
	$base   = $record_title( $cut );
	foreach ( $taken_fn( $base ) as $nn ) {
		$occupy( $nn );
	}
	$taken = $taken_now( $stored );
	$res   = $create( $login, $raw );
	$vectors['unique'][] = array(
		'label'      => $label,
		'raw'        => $raw,
		'login'      => isset( $res['stored_login'] ) ? $res['stored_login'] : $stored,
		'base'       => $base,
		'taken'      => $taken,
		'nicename'   => isset( $res['nicename'] ) ? $res['nicename'] : null,
		'core_error' => isset( $res['error'] ) ? $res['error'] : null,
	);
}

$reset();

ksort( $titles, SORT_STRING );
$vectors['sanitize_title'] = $titles;

file_put_contents( $out_file, wp_json_encode( $vectors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
echo 'base ' . count( $vectors['base'] ) . ', unique ' . count( $vectors['unique'] ) . ', titles ' . count( $titles ) . "\n";
