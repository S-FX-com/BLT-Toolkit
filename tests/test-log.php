<?php
/**
 * Activity log: the pure parts of BLT_Toolkit_Log (LOG-1 to LOG-5, the
 * Activity screen's query arguments and the CSV export).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Log.
 */
class Test_Log extends TestCase {

	// ---------- LOG-1: row fields ----------

	/**
	 * An empty row still makes a valid row with the defaults.
	 *
	 * @return void
	 */
	public function test_log1_normalize_row_defaults() {
		$row = BLT_Toolkit_Log::normalize_row( array() );

		$this->assertSame( 'toolkit', $row['module'] );
		$this->assertSame( 'unknown', $row['action'] );
		$this->assertSame( '', $row['object_type'] );
		$this->assertSame( '', $row['object_id'] );
		$this->assertSame( '', $row['summary'] );
		$this->assertSame( array(), $row['details'] );
		$this->assertNull( $row['snapshot_id'] );
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( '', $row['source'] );
	}

	/**
	 * Keys are cleaned and cut to their column widths.
	 *
	 * @return void
	 */
	public function test_log1_normalize_row_cleans_keys_to_column_widths() {
		$row = BLT_Toolkit_Log::normalize_row(
			array(
				'module'      => 'Roles',
				'action'      => 'Role Saved!<script>',
				'object_type' => str_repeat( 'post_type', 5 ),
				'object_id'   => "editor\nrole",
				'source'      => 'Username-Changer',
				'status'      => 'WARNING',
				'snapshot_id' => '42',
			)
		);

		$this->assertSame( 'roles', $row['module'] );
		$this->assertSame( 'rolesavedscript', $row['action'] );
		$this->assertSame( 20, strlen( $row['object_type'] ) );
		$this->assertSame( 'editor role', $row['object_id'] );
		$this->assertSame( 'username-changer', $row['source'] );
		$this->assertSame( 'warning', $row['status'] );
		$this->assertSame( 42, $row['snapshot_id'] );

		$long = BLT_Toolkit_Log::normalize_row(
			array(
				'module' => str_repeat( 'm', 30 ),
				'action' => str_repeat( 'a', 60 ),
			)
		);

		$this->assertSame( 20, strlen( $long['module'] ), 'module is varchar(20).' );
		$this->assertSame( 40, strlen( $long['action'] ), 'action is varchar(40).' );
	}

	/**
	 * Unknown statuses, bad snapshot IDs and non-array details are dropped.
	 *
	 * @return void
	 */
	public function test_log1_normalize_row_rejects_bad_values() {
		$row = BLT_Toolkit_Log::normalize_row(
			array(
				'status'      => 'deleted',
				'snapshot_id' => 0,
				'details'     => 'not an array',
				'object_id'   => array( 'x' ),
				'summary'     => array( 'x' ),
			)
		);

		$this->assertSame( 'success', $row['status'] );
		$this->assertNull( $row['snapshot_id'] );
		$this->assertSame( array(), $row['details'] );
		$this->assertSame( '', $row['object_id'] );
		$this->assertSame( '', $row['summary'] );

		$this->assertNull( BLT_Toolkit_Log::normalize_row( array( 'snapshot_id' => -3 ) )['snapshot_id'] );
		$this->assertNull( BLT_Toolkit_Log::normalize_row( array( 'snapshot_id' => 'abc' ) )['snapshot_id'] );
		$this->assertSame( '7', BLT_Toolkit_Log::normalize_row( array( 'object_id' => 7 ) )['object_id'] );
		$this->assertSame( 191, mb_strlen( BLT_Toolkit_Log::normalize_row( array( 'object_id' => str_repeat( 'é', 300 ) ) )['object_id'] ), 'object_id is varchar(191), cut by character.' );
	}

	/**
	 * The summary is one clean line.
	 *
	 * @return void
	 */
	public function test_log1_summary_is_one_line() {
		$this->assertSame( 'Changed bob to carol.', BLT_Toolkit_Log::clean_summary( "  Changed bob\r\n to\tcarol.  " ) );
		$this->assertSame( 'a b', BLT_Toolkit_Log::clean_summary( "a\x00\x1F\x7Fb" ) );
		$this->assertSame( 'a b', BLT_Toolkit_Log::clean_summary( "a\u{2028}b" ), 'Unicode line separators count as line breaks.' );
		$this->assertSame( '<b>Editor</b> saved', BLT_Toolkit_Log::clean_summary( '<b>Editor</b> saved' ), 'Markup is kept as text and escaped on output.' );
		$this->assertSame( '12', BLT_Toolkit_Log::clean_summary( 12 ) );
		$this->assertSame( '', BLT_Toolkit_Log::clean_summary( null ) );
		$this->assertSame( '', BLT_Toolkit_Log::clean_summary( true ) );
	}

	/**
	 * Invalid UTF-8 in a summary is replaced, so the insert cannot fail on it.
	 *
	 * @return void
	 */
	public function test_log1_summary_invalid_utf8_is_replaced() {
		$clean = BLT_Toolkit_Log::clean_summary( "caf\xC3 \xFF ok" );

		$this->assertSame( 1, preg_match( '//u', $clean ), 'Valid UTF-8.' );
		$this->assertStringEndsWith( 'ok', $clean );
	}

	/**
	 * Long summaries are cut by character, never through a character.
	 *
	 * @return void
	 */
	public function test_log1_summary_is_cut_by_character() {
		$clean = BLT_Toolkit_Log::clean_summary( str_repeat( 'ü', BLT_Toolkit_Log::MAX_SUMMARY + 50 ) );

		$this->assertSame( BLT_Toolkit_Log::MAX_SUMMARY, mb_strlen( $clean ) );
		$this->assertSame( 1, preg_match( '//u', $clean ) );
	}

	// ---------- LOG-1: details encoding ----------

	/**
	 * Details round-trip through JSON; empty details are stored as NULL.
	 *
	 * @return void
	 */
	public function test_log1_details_round_trip() {
		$details = array(
			'before' => 'bob',
			'after'  => 'carol',
			'diff'   => array(
				'added'   => array(),
				'removed' => array(),
				'changed' => array(
					'editor' => array(
						'name'    => null,
						'added'   => array( 'edit_pages' => true ),
						'removed' => array(),
						'flipped' => array(
							'upload_files' => array(
								'from' => true,
								'to'   => false,
							),
						),
					),
				),
			),
			'path'   => 'a/b',
			'name'   => 'Ünïcödé',
		);

		$json = BLT_Toolkit_Log::encode_details( $details );

		$this->assertIsString( $json );
		$this->assertStringContainsString( '"path":"a/b"', $json, 'Slashes are not escaped.' );
		$this->assertStringContainsString( 'Ünïcödé', $json, 'Unicode is stored as is.' );
		$this->assertSame( $details, BLT_Toolkit_Log::decode_details( $json ) );

		$this->assertNull( BLT_Toolkit_Log::encode_details( array() ) );
		$this->assertNull( BLT_Toolkit_Log::encode_details( 'text' ) );
		$this->assertNull( BLT_Toolkit_Log::decode_details( null ) );
		$this->assertNull( BLT_Toolkit_Log::decode_details( '' ) );
	}

	/**
	 * Values JSON cannot hold never fail the encode (LOG-3: the row is still written).
	 *
	 * @return void
	 */
	public function test_log1_details_unencodable_values_still_encode() {
		$json = BLT_Toolkit_Log::encode_details(
			array(
				'bad_utf8' => "caf\xC3",
				'infinite' => INF,
				'ok'       => 1,
			)
		);

		$this->assertIsString( $json );

		$decoded = json_decode( $json, true );

		$this->assertSame( 1, $decoded['ok'] );
		$this->assertSame( 1, preg_match( '//u', $decoded['bad_utf8'] ) );
		$this->assertArrayHasKey( 'infinite', $decoded );
	}

	/**
	 * Oversized or over-deep details become a marker naming their keys.
	 *
	 * @return void
	 */
	public function test_log1_details_too_large_becomes_marker() {
		$json = BLT_Toolkit_Log::encode_details(
			array(
				'big'   => str_repeat( 'x', BLT_Toolkit_Log::MAX_DETAILS_BYTES + 10 ),
				'other' => 1,
			)
		);

		$this->assertLessThan( 1000, strlen( $json ) );

		$marker = json_decode( $json, true );

		$this->assertTrue( $marker['truncated'] );
		$this->assertGreaterThan( BLT_Toolkit_Log::MAX_DETAILS_BYTES, $marker['bytes'] );
		$this->assertSame( array( 'big', 'other' ), $marker['keys'] );

		$deep = array( 'v' => 1 );

		for ( $i = 0; $i < BLT_Toolkit_Log::MAX_DETAILS_DEPTH + 5; $i++ ) {
			$deep = array( 'n' => $deep );
		}

		$marker = json_decode( BLT_Toolkit_Log::encode_details( array( 'deep' => $deep ) ), true );

		$this->assertTrue( $marker['truncated'] );
		$this->assertSame( array( 'deep' ), $marker['keys'] );
	}

	/**
	 * A damaged details column still decodes to something showable.
	 *
	 * @return void
	 */
	public function test_log1_decode_damaged_details() {
		$this->assertSame( array( 'value' => '{not json' ), BLT_Toolkit_Log::decode_details( '{not json' ) );
		$this->assertSame( array( 'value' => 5 ), BLT_Toolkit_Log::decode_details( '5' ) );
	}

	// ---------- LOG-2: attribution ----------

	/**
	 * Not switched: the current user is the actor, acting as nobody.
	 *
	 * @return void
	 */
	public function test_log2_not_switched() {
		$this->assertSame(
			array(
				'actor_id'     => 5,
				'acting_as_id' => 0,
				'chain'        => array(),
			),
			BLT_Toolkit_Log::attribute( 5, array() )
		);

		$this->assertSame( 0, BLT_Toolkit_Log::attribute( 5, array( 5 ) )['acting_as_id'], 'A chain that starts with the current user is not a switch.' );
		$this->assertSame( 0, BLT_Toolkit_Log::attribute( 0, array() )['actor_id'], 'Cron and WP-CLI without a user.' );
	}

	/**
	 * Switched: the first user of the chain is the actor; the current user is acted as.
	 *
	 * @return void
	 */
	public function test_log2_switched_actor_is_first_in_chain() {
		$this->assertSame(
			array(
				'actor_id'     => 1,
				'acting_as_id' => 9,
				'chain'        => array( 1, 4, 9 ),
			),
			BLT_Toolkit_Log::attribute( 9, array( 1, 4, 9 ) ),
			'Three levels deep: the human who signed in is the actor.'
		);

		$this->assertSame( 1, BLT_Toolkit_Log::attribute( 4, array( 1 ) )['actor_id'], 'A provider that leaves out the current user.' );
		$this->assertSame( 4, BLT_Toolkit_Log::attribute( 4, array( 1 ) )['acting_as_id'] );
	}

	/**
	 * Switched off (nobody signed in) still names the human; junk entries are ignored.
	 *
	 * @return void
	 */
	public function test_log2_switched_off_and_junk_chain() {
		$who = BLT_Toolkit_Log::attribute( 0, array( 1, 4 ) );

		$this->assertSame( 1, $who['actor_id'] );
		$this->assertSame( 0, $who['acting_as_id'] );

		$who = BLT_Toolkit_Log::attribute( 7, array( 'x', -2, null, '3', 7 ) );

		$this->assertSame( 3, $who['actor_id'] );
		$this->assertSame( 7, $who['acting_as_id'] );
		$this->assertSame( array( 3, 7 ), $who['chain'] );
	}

	// ---------- CSV export ----------

	/**
	 * Cells a spreadsheet would read as a formula get a leading apostrophe.
	 *
	 * @return void
	 */
	public function test_csv_formula_cells_are_neutralized() {
		$cases = array(
			'=1+1'                 => "'=1+1",
			'+SUM(A1)'             => "'+SUM(A1)",
			'-2+3'                 => "'-2+3",
			'@cmd'                 => "'@cmd",
			"\t=1"                 => "'\t=1",
			"\r=1"                 => "'\r=1",
			"\n=1"                 => "'\n=1",
			'  =HYPERLINK("x")'    => '\'  =HYPERLINK("x")',
			'=cmd|\' /C calc\'!A0' => '\'=cmd|\' /C calc\'!A0',
		);

		foreach ( $cases as $cell => $expected ) {
			$this->assertSame( $expected, BLT_Toolkit_Log::csv_cell( $cell ), (string) $cell );
		}
	}

	/**
	 * Ordinary cells pass through unchanged.
	 *
	 * @return void
	 */
	public function test_csv_safe_cells_are_unchanged() {
		foreach ( array( 'alice', 'a=b', 'Role saved.', '2026-10-22 10:00:00', '', ' ', 'x-1', 'a@b.c' ) as $cell ) {
			$this->assertSame( $cell, BLT_Toolkit_Log::csv_cell( $cell ), $cell );
		}

		$this->assertSame( '12', BLT_Toolkit_Log::csv_cell( 12 ) );
		$this->assertSame( '', BLT_Toolkit_Log::csv_cell( null ) );
		$this->assertSame( '1', BLT_Toolkit_Log::csv_cell( true ) );
		$this->assertSame( '', BLT_Toolkit_Log::csv_cell( array( 'x' ) ) );
		$this->assertSame( "'-5", BLT_Toolkit_Log::csv_cell( -5 ), 'A negative number is indistinguishable from a formula lead.' );
	}

	/**
	 * Lines use RFC 4180 quoting and round-trip through str_getcsv().
	 *
	 * @return void
	 */
	public function test_csv_line_quotes_and_round_trips() {
		$this->assertSame( "1,alice,plain\n", BLT_Toolkit_Log::csv_line( array( 1, 'alice', 'plain' ) ) );
		$this->assertSame( "\"a,b\",\"say \"\"hi\"\"\",\"two\nlines\"\n", BLT_Toolkit_Log::csv_line( array( 'a,b', 'say "hi"', "two\nlines" ) ) );

		$cells = array( '=HYPERLINK("http://x","y")', 'x,y', "line\r\nbreak", '', 'back\\slash' );
		$line  = BLT_Toolkit_Log::csv_line( $cells );
		$back  = str_getcsv( rtrim( $line, "\n" ), ',', '"', '' );

		$this->assertSame( '\'=HYPERLINK("http://x","y")', $back[0], 'The formula stays neutralized inside quotes.' );
		$this->assertSame( array_slice( $cells, 1 ), array_slice( $back, 1 ) );
	}

	// ---------- Activity screen: query arguments ----------

	/**
	 * Defaults when nothing is asked for.
	 *
	 * @return void
	 */
	public function test_query_args_defaults() {
		$this->assertSame(
			array(
				'module'    => '',
				'action'    => '',
				'actor'     => '',
				'acting_as' => '',
				'from'      => '',
				'to'        => '',
				'orderby'   => 'when',
				'order'     => 'desc',
				'paged'     => 1,
				'per_page'  => BLT_Toolkit_Log::DEFAULT_PER_PAGE,
			),
			BLT_Toolkit_Log::normalize_args( array() )
		);
	}

	/**
	 * Every filter takes only well-formed values.
	 *
	 * @return void
	 */
	public function test_query_args_are_sanitized() {
		$args = BLT_Toolkit_Log::normalize_args(
			array(
				'module'    => 'Roles',
				'action'    => "role_saved' OR 1=1 --",
				'actor'     => '12',
				'acting_as' => 'any',
				'from'      => '2026-02-30',
				'to'        => '2026-10-22',
				'orderby'   => 'summary; DROP TABLE',
				'order'     => 'ASC',
				'paged'     => '-4',
				'per_page'  => '100000',
			)
		);

		$this->assertSame( 'roles', $args['module'] );
		$this->assertSame( '', $args['action'], 'Characters outside a key mean no filter, not a mangled one.' );
		$this->assertSame( '12', $args['actor'] );
		$this->assertSame( 'any', $args['acting_as'] );
		$this->assertSame( '', $args['from'], 'Not a real date.' );
		$this->assertSame( '2026-10-22', $args['to'] );
		$this->assertSame( 'when', $args['orderby'], 'Only whitelisted sort keys.' );
		$this->assertSame( 'asc', $args['order'] );
		$this->assertSame( 1, $args['paged'] );
		$this->assertSame( BLT_Toolkit_Log::MAX_PER_PAGE, $args['per_page'] );
	}

	/**
	 * The actor filter takes an ID, `none`, or a username.
	 *
	 * @return void
	 */
	public function test_query_args_actor_variants() {
		$this->assertSame( 'none', BLT_Toolkit_Log::normalize_args( array( 'actor' => 'none' ) )['actor'] );
		$this->assertSame( '7', BLT_Toolkit_Log::normalize_args( array( 'actor' => ' 007 ' ) )['actor'] );
		$this->assertSame( 'login:jane doe', BLT_Toolkit_Log::normalize_args( array( 'actor' => " jane\ndoe " ) )['actor'] );
		$this->assertSame( 'login:bob', BLT_Toolkit_Log::normalize_args( array( 'actor' => 'login:bob' ) )['actor'] );
		$this->assertSame( '', BLT_Toolkit_Log::normalize_args( array( 'actor' => 'login:' ) )['actor'] );
		$this->assertSame( '', BLT_Toolkit_Log::normalize_args( array( 'actor' => '0' ) )['actor'], 'User 0 is `none`, not an ID.' );
		$this->assertSame( '', BLT_Toolkit_Log::normalize_args( array( 'actor' => array( '1' ) ) )['actor'] );
		$this->assertSame( 60, mb_strlen( substr( BLT_Toolkit_Log::normalize_args( array( 'actor' => str_repeat( 'é', 90 ) ) )['actor'], 6 ) ) );
	}

	/**
	 * The acting-as filter takes `none`, `any` or an ID.
	 *
	 * @return void
	 */
	public function test_query_args_acting_as_variants() {
		$this->assertSame( 'none', BLT_Toolkit_Log::normalize_args( array( 'acting_as' => 'none' ) )['acting_as'] );
		$this->assertSame( '3', BLT_Toolkit_Log::normalize_args( array( 'acting_as' => '3' ) )['acting_as'] );
		$this->assertSame( '', BLT_Toolkit_Log::normalize_args( array( 'acting_as' => 'bob' ) )['acting_as'] );
		$this->assertSame( '', BLT_Toolkit_Log::normalize_args( array( 'acting_as' => '-3' ) )['acting_as'] );
	}

	/**
	 * Normalizing twice changes nothing.
	 *
	 * @return void
	 */
	public function test_query_args_are_idempotent() {
		$raw = array(
			'module'    => 'usernames',
			'action'    => 'username_changed',
			'actor'     => 'jane',
			'acting_as' => '4',
			'from'      => '2026-01-01',
			'to'        => '2026-01-31',
			'orderby'   => 'actor',
			'order'     => 'asc',
			'paged'     => '3',
			'per_page'  => '20',
		);

		$once = BLT_Toolkit_Log::normalize_args( $raw );

		$this->assertSame( $once, BLT_Toolkit_Log::normalize_args( $once ) );
		$this->assertTrue( BLT_Toolkit_Log::is_filtered( $once ) );
		$this->assertFalse(
			BLT_Toolkit_Log::is_filtered(
				BLT_Toolkit_Log::normalize_args(
					array(
						'orderby' => 'actor',
						'paged'   => 2,
					)
				)
			),
			'Sorting and paging are not filters.'
		);
	}

	/**
	 * Filters become placeholders with bound values, never interpolated text.
	 *
	 * @return void
	 */
	public function test_query_build_where() {
		$utc = new DateTimeZone( 'UTC' );

		$this->assertSame(
			array(
				'sql'    => '1=1',
				'params' => array(),
			),
			BLT_Toolkit_Log::build_where( BLT_Toolkit_Log::normalize_args( array() ), $utc )
		);

		$where = BLT_Toolkit_Log::build_where(
			BLT_Toolkit_Log::normalize_args(
				array(
					'module'    => 'roles',
					'action'    => 'role_saved',
					'actor'     => 'login:o\'brien',
					'acting_as' => 'any',
					'from'      => '2026-10-01',
					'to'        => '2026-10-02',
				)
			),
			$utc
		);

		$this->assertSame( 'module = %s AND action = %s AND actor_login = %s AND acting_as_id > %d AND created_at >= %s AND created_at < %s', $where['sql'] );
		$this->assertSame( array( 'roles', 'role_saved', 'o\'brien', 0, '2026-10-01 00:00:00', '2026-10-03 00:00:00' ), $where['params'] );

		$where = BLT_Toolkit_Log::build_where(
			array(
				'actor'     => 'none',
				'acting_as' => 'none',
			),
			$utc
		);

		$this->assertSame( 'actor_id = %d AND acting_as_id = %d', $where['sql'] );
		$this->assertSame( array( 0, 0 ), $where['params'] );

		$where = BLT_Toolkit_Log::build_where(
			array(
				'actor'     => '12',
				'acting_as' => '4',
			),
			$utc
		);

		$this->assertSame( 'actor_id = %d AND acting_as_id = %d', $where['sql'] );
		$this->assertSame( array( 12, 4 ), $where['params'] );
	}

	/**
	 * Dates are the site's local days, converted to UTC across a DST change.
	 *
	 * @return void
	 */
	public function test_query_date_range_is_site_local_and_dst_safe() {
		$tz = new DateTimeZone( 'America/New_York' );

		// 2026-11-01 is 25 hours long in New York (clocks go back).
		$range = BLT_Toolkit_Log::utc_range( '2026-11-01', '2026-11-01', $tz );

		$this->assertSame( '2026-11-01 04:00:00', $range['from'] );
		$this->assertSame( '2026-11-02 05:00:00', $range['to_exclusive'] );

		$range = BLT_Toolkit_Log::utc_range( '', 'garbage', $tz );

		$this->assertNull( $range['from'] );
		$this->assertNull( $range['to_exclusive'] );
	}

	/**
	 * ORDER BY comes only from the whitelist; ID breaks ties.
	 *
	 * @return void
	 */
	public function test_query_order_by_whitelist() {
		$this->assertSame( 'created_at DESC, id DESC', BLT_Toolkit_Log::order_by( 'when', 'desc' ) );
		$this->assertSame( 'created_at ASC, id ASC', BLT_Toolkit_Log::order_by( 'when', 'ASC' ) );
		$this->assertSame( 'actor_login DESC, id DESC', BLT_Toolkit_Log::order_by( 'actor', 'nonsense' ) );
		$this->assertSame( 'module ASC, action ASC, id ASC', BLT_Toolkit_Log::order_by( 'activity', 'asc' ) );
		$this->assertSame( 'created_at DESC, id DESC', BLT_Toolkit_Log::order_by( 'id; DROP TABLE x', 'asc' ) );
		$this->assertSame( 'created_at DESC, id DESC', BLT_Toolkit_Log::order_by( null, null ) );
	}

	/**
	 * Real calendar dates only.
	 *
	 * @return void
	 */
	public function test_query_valid_date() {
		$this->assertTrue( BLT_Toolkit_Log::valid_date( '2024-02-29' ) );
		$this->assertFalse( BLT_Toolkit_Log::valid_date( '2025-02-29' ) );
		$this->assertFalse( BLT_Toolkit_Log::valid_date( '2025-1-1' ) );
		$this->assertFalse( BLT_Toolkit_Log::valid_date( '1969-12-31' ) );
		$this->assertFalse( BLT_Toolkit_Log::valid_date( array() ) );
	}

	// ---------- LOG-4: retention ----------

	/**
	 * The cutoff is `days` before now; below one day there is none (keep everything).
	 *
	 * @return void
	 */
	public function test_log4_cutoff() {
		$now = gmmktime( 12, 0, 0, 10, 22, 2026 );

		$this->assertSame( '2026-10-21 12:00:00', BLT_Toolkit_Log::cutoff( 1, $now ) );
		$this->assertSame( '2025-10-22 12:00:00', BLT_Toolkit_Log::cutoff( 365, $now ) );
		$this->assertNull( BLT_Toolkit_Log::cutoff( 0, $now ), 'A filter returning 0 keeps every row rather than wiping the log.' );
		$this->assertNull( BLT_Toolkit_Log::cutoff( -5, $now ) );
	}

	// ---------- Activity screen filter menus ----------

	/**
	 * The cached filter menus merge newer rows as the whole-table queries
	 * would list them: unique, sorted, MAX(actor_login) per actor, and cut to
	 * the same limits (the screen then falls back to a text field).
	 *
	 * @return void
	 */
	public function test_activity_filter_menus_merge_like_the_full_queries() {
		$cached = array(
			'actions'   => array(
				array(
					'module' => 'toolkit',
					'action' => 'recovery',
				),
				array(
					'module' => 'roles',
					'action' => 'role_saved',
				),
			),
			'actors'    => array(
				array(
					'actor_id'    => 2,
					'actor_login' => 'bob',
				),
				array(
					'actor_id'    => 1,
					'actor_login' => 'admin',
				),
			),
			'acting_as' => array( 9, 4 ),
			'max_id'    => 10,
		);
		$fresh  = array(
			'actions'   => array(
				array(
					'module' => 'roles',
					'action' => 'role_saved',
				),
				array(
					'module' => 'roles',
					'action' => 'role_created',
				),
			),
			'actors'    => array(
				array(
					'actor_id'    => 2,
					'actor_login' => 'bobby',
				),
				array(
					'actor_id'    => 3,
					'actor_login' => 'Carol',
				),
			),
			'acting_as' => array( 4, 7, 0 ),
		);

		$merged = BLT_Toolkit_Log::merge_menus( $cached, $fresh );

		$this->assertSame(
			array(
				array(
					'module' => 'roles',
					'action' => 'role_created',
				),
				array(
					'module' => 'roles',
					'action' => 'role_saved',
				),
				array(
					'module' => 'toolkit',
					'action' => 'recovery',
				),
			),
			$merged['actions']
		);
		$this->assertSame(
			array(
				array(
					'actor_id'    => 1,
					'actor_login' => 'admin',
				),
				array(
					'actor_id'    => 2,
					'actor_login' => 'bobby',
				),
				array(
					'actor_id'    => 3,
					'actor_login' => 'Carol',
				),
			),
			$merged['actors'],
			'One entry per actor with the greatest login, ordered by login without regard to case.'
		);
		$this->assertSame( array( 4, 7, 9 ), $merged['acting_as'] );
		$this->assertSame( $merged, BLT_Toolkit_Log::merge_menus( array(), $merged ), 'Merging into nothing is the list itself.' );

		$many = array( 'acting_as' => range( 1, 300 ) );
		$this->assertCount( BLT_Toolkit_Log::MENU_USERS, BLT_Toolkit_Log::merge_menus( array(), $many )['acting_as'] );
		$this->assertSame( 1, BLT_Toolkit_Log::merge_menus( array( 'acting_as' => range( 50, 300 ) ), array( 'acting_as' => array( 1 ) ) )['acting_as'][0], 'A lower ID from newer rows enters a full list.' );
	}

	/**
	 * LOG-3: the failure flag as the Site Health test reads it. A later
	 * successful write stamps `recovered` once and never clears the flag; a
	 * new failure (stored without `recovered`) reads as failing again.
	 *
	 * @return void
	 */
	public function test_log3_failure_flag_is_stamped_recovered_not_cleared() {
		$flag = array(
			'time'  => 1000,
			'error' => 'no such table',
			'count' => 3,
		);

		$this->assertSame(
			array(
				'time'      => 1000,
				'error'     => 'no such table',
				'count'     => 3,
				'recovered' => 0,
			),
			BLT_Toolkit_Log::failure_from( $flag ),
			'Still failing: no write succeeded since.'
		);

		$stamped = BLT_Toolkit_Log::recovered_flag( $flag, 1500 );

		$this->assertSame( 1500, $stamped['recovered'] );
		$this->assertSame( 3, $stamped['count'], 'The count of lost entries stays.' );
		$this->assertSame( 1500, BLT_Toolkit_Log::failure_from( $stamped )['recovered'] );
		$this->assertNull( BLT_Toolkit_Log::recovered_flag( $stamped, 2000 ), 'Stamped once: the first success after the failure.' );

		$this->assertNull( BLT_Toolkit_Log::recovered_flag( false, 2000 ), 'No flag: nothing to store.' );
		$this->assertNull( BLT_Toolkit_Log::failure_from( false ) );
		$this->assertNull( BLT_Toolkit_Log::failure_from( array( 'time' => 0 ) ) );
		$this->assertNull( BLT_Toolkit_Log::failure_from( array( 'time' => 'soon' ) ) );

		// A clock that went back never stamps a recovery before the failure.
		$this->assertSame( 1000, BLT_Toolkit_Log::recovered_flag( $flag, 900 )['recovered'] );

		// A damaged stamp older than the failure reads as still failing.
		$this->assertSame( 0, BLT_Toolkit_Log::failure_from( array_merge( $flag, array( 'recovered' => 999 ) ) )['recovered'] );
		$this->assertSame( 1, BLT_Toolkit_Log::failure_from( array( 'time' => 5 ) )['count'] );
	}
}
