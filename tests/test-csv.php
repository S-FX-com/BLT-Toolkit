<?php
/**
 * Bulk rename CSV reading and writing (UN-30, SPEC 18).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Csv.
 */
class Test_Csv extends TestCase {

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * Parse and assert success.
	 *
	 * @param string $bytes    File contents.
	 * @param int    $max_rows Row cap.
	 * @return array
	 */
	private function parse( $bytes, $max_rows = 5000 ) {
		$parsed = BLT_Toolkit_Csv::parse( $bytes, $max_rows );

		$this->assertIsArray( $parsed, is_wp_error( $parsed ) ? $parsed->get_error_message() : '' );

		return $parsed;
	}

	/**
	 * The rows as line => cells, for compact assertions.
	 *
	 * @param array $parsed Result of parse().
	 * @return array
	 */
	private function cells( array $parsed ) {
		$out = array();

		foreach ( $parsed['rows'] as $row ) {
			$out[ $row['line'] ] = $row['cells'];
		}

		return $out;
	}

	/**
	 * Assert parse() refused the file with a code.
	 *
	 * @param string $code  Expected code.
	 * @param string $bytes File contents.
	 * @param int    $max   Row cap.
	 * @return WP_Error
	 */
	private function assertRefused( $code, $bytes, $max = 5000 ) {
		$result = BLT_Toolkit_Csv::parse( $bytes, $max );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertNotSame( '', $result->get_error_message() );

		return $result;
	}

	/**
	 * UN-30: the preferred shape, comma-separated with LF line ends.
	 *
	 * @return void
	 */
	public function test_un30_basic_user_id_shape() {
		$parsed = $this->parse( "user_id,new_username\n12,carol\n7,bob\n" );

		$this->assertSame( ',', $parsed['delimiter'] );
		$this->assertSame( array( 'user_id', 'new_username' ), $parsed['header'] );
		$this->assertSame( 'user_id', $parsed['id_column'] );
		$this->assertSame(
			array(
				array(
					'line'  => 2,
					'cells' => array(
						'user_id'      => '12',
						'new_username' => 'carol',
					),
					'extra' => 0,
				),
				array(
					'line'  => 3,
					'cells' => array(
						'user_id'      => '7',
						'new_username' => 'bob',
					),
					'extra' => 0,
				),
			),
			$parsed['rows']
		);
	}

	/**
	 * UN-30: a UTF-8 byte order mark (Excel's "CSV UTF-8") and CRLF line
	 * ends, with UTF-8 text intact.
	 *
	 * @return void
	 */
	public function test_un30_bom_and_crlf() {
		$parsed = $this->parse( "\xEF\xBB\xBFusername,new_username\r\nJosé,jose\r\nzoë,zoe" );

		$this->assertSame( array( 'username', 'new_username' ), $parsed['header'], 'The BOM is not part of the first column name.' );
		$this->assertSame( 'username', $parsed['id_column'] );
		$this->assertSame(
			array(
				2 => array(
					'username'     => 'José',
					'new_username' => 'jose',
				),
				3 => array(
					'username'     => 'zoë',
					'new_username' => 'zoe',
				),
			),
			$this->cells( $parsed ),
			'No trailing line end is needed.'
		);
	}

	/**
	 * UN-30: semicolons (Excel in locales with a decimal comma) are detected
	 * from the header, and a comma inside a cell is then just text.
	 *
	 * @return void
	 */
	public function test_un30_semicolon_detected() {
		$parsed = $this->parse( "user_id;new_username\r\n12;carol\r\n13;\"x;y\"\r\n" );

		$this->assertSame( ';', $parsed['delimiter'] );
		$this->assertSame(
			array(
				2 => array(
					'user_id'      => '12',
					'new_username' => 'carol',
				),
				3 => array(
					'user_id'      => '13',
					'new_username' => 'x;y',
				),
			),
			$this->cells( $parsed )
		);
	}

	/**
	 * UN-30: Excel's `sep=` first line names the delimiter and is not the
	 * header; line numbers still count it.
	 *
	 * @return void
	 */
	public function test_un30_excel_sep_line() {
		$parsed = $this->parse( "sep=;\r\nuser_id;new_username\r\n4;dave\r\n" );

		$this->assertSame( ';', $parsed['delimiter'] );
		$this->assertSame(
			array(
				3 => array(
					'user_id'      => '4',
					'new_username' => 'dave',
				),
			),
			$this->cells( $parsed )
		);

		$hinted = $this->parse( "SEP=,\nuser_id,new_username,a;b;c;d\n1,bob,x\n" );

		$this->assertSame( ',', $hinted['delimiter'], 'The hint wins over counting (3 semicolons, 2 commas).' );
		$this->assertSame( array( 'user_id', 'new_username', 'a;b;c;d' ), $hinted['header'] );
		$this->assertSame( ',', $this->parse( "user_id,new_username,a;b;c;d\n1,bob,x\n" )['delimiter'], 'Without the hint, the delimiter that makes a valid header wins.' );
	}

	/**
	 * UN-30: quoted cells hold delimiters, doubled quotes and line breaks;
	 * the next row's line number accounts for the breaks.
	 *
	 * @return void
	 */
	public function test_un30_quoted_fields() {
		$csv    = "user_id,new_username,note\n"
			. "1,\"a,b\",\"say \"\"hi\"\"\"\n"
			. "2,bob,\"two\r\nlines\nand three\"\n"
			. "3,carol,\"\"\n";
		$parsed = $this->parse( $csv );

		$this->assertSame(
			array(
				2 => array(
					'user_id'      => '1',
					'new_username' => 'a,b',
					'note'         => 'say "hi"',
				),
				3 => array(
					'user_id'      => '2',
					'new_username' => 'bob',
					'note'         => "two\r\nlines\nand three",
				),
				6 => array(
					'user_id'      => '3',
					'new_username' => 'carol',
					'note'         => '',
				),
			),
			$this->cells( $parsed )
		);
	}

	/**
	 * UN-30: text after a closing quote is kept, as fgetcsv() does, and a
	 * quote inside an unquoted cell is literal.
	 *
	 * @return void
	 */
	public function test_un30_stray_quotes_are_lenient() {
		$cells = $this->cells( $this->parse( "user_id,new_username\n1,\"ab\"cd\n2,o\"neil\n" ) );

		$this->assertSame( 'abcd', $cells[2]['new_username'] );
		$this->assertSame( 'o"neil', $cells[3]['new_username'] );
	}

	/**
	 * UN-30: blank lines, lines of empty cells (`,,` from a spreadsheet's
	 * trailing rows) and whitespace-only lines are skipped, before and
	 * after the header, and still counted in line numbers.
	 *
	 * @return void
	 */
	public function test_un30_blank_lines_skipped() {
		$parsed = $this->parse( "\n\r\n  \nuser_id,new_username\n\n5,eve\n,\n , \t\n6,frank\n\n\n" );

		$this->assertSame( array( 'user_id', 'new_username' ), $parsed['header'] );
		$this->assertSame( array( 6, 9 ), array_keys( $this->cells( $parsed ) ) );
	}

	/**
	 * UN-30: CR-only line ends (classic Mac) work too.
	 *
	 * @return void
	 */
	public function test_un30_lone_cr_line_ends() {
		$this->assertSame(
			array(
				2 => array(
					'user_id'      => '1',
					'new_username' => 'a',
				),
				3 => array(
					'user_id'      => '2',
					'new_username' => 'b',
				),
			),
			$this->cells( $this->parse( "user_id,new_username\r1,a\r2,b\r" ) )
		);
	}

	/**
	 * UN-30: header names ignore case and surrounding spaces, and read
	 * spaces or dashes as underscores; quoted names work; the template's
	 * three columns prefer user_id.
	 *
	 * @return void
	 */
	public function test_un30_header_variants() {
		$this->assertSame( array( 'user_id', 'new_username' ), $this->parse( "User ID,New Username\n1,a\n" )['header'] );
		$this->assertSame( array( 'username', 'new_username' ), $this->parse( " USERNAME ; new-username \nbob;robert\n" )['header'] );
		$this->assertSame( array( 'user_id', 'new_username' ), $this->parse( "\"user_id\",\"new_username\"\n1,a\n" )['header'] );

		$template = $this->parse( "user_id,username,new_username\n1,admin,\n2,bob,robert\n" );

		$this->assertSame( 'user_id', $template['id_column'] );
		$this->assertSame( 1, $template['blank'], 'The untouched template row is counted, not listed.' );
		$this->assertSame(
			array(
				'user_id'      => '2',
				'username'     => 'bob',
				'new_username' => 'robert',
			),
			$template['rows'][0]['cells']
		);

		$reordered = $this->parse( "new_username,email,username\nrobert,b@example.com,bob\n" );

		$this->assertSame( 'username', $reordered['id_column'] );
		$this->assertSame( 'robert', $reordered['rows'][0]['cells']['new_username'] );
		$this->assertSame( 'b@example.com', $reordered['rows'][0]['cells']['email'], 'Other columns are kept.' );
	}

	/**
	 * UN-30: a header without new_username, without a column naming the
	 * user, or with a column twice is refused.
	 *
	 * @return void
	 */
	public function test_un30_bad_headers_refused() {
		$this->assertRefused( 'blt_toolkit_csv_header', "user_id,username\n1,bob\n" );
		$this->assertRefused( 'blt_toolkit_csv_header', "id,new_username\n1,bob\n" );
		$this->assertRefused( 'blt_toolkit_csv_header', "12,carol\n7,bob\n" );
		$this->assertRefused( 'blt_toolkit_csv_header', "user_id,new_username,New Username\n1,a,b\n" );
	}

	/**
	 * UN-30: an empty file, one of only blank lines or only a BOM is refused;
	 * a header alone parses to no rows.
	 *
	 * @return void
	 */
	public function test_un30_empty_files() {
		$this->assertRefused( 'blt_toolkit_csv_empty', '' );
		$this->assertRefused( 'blt_toolkit_csv_empty', "\r\n\n  \n" );
		$this->assertRefused( 'blt_toolkit_csv_empty', "\xEF\xBB\xBF" );
		$this->assertRefused( 'blt_toolkit_csv_empty', "sep=;\r\n" );
		$this->assertSame( array(), $this->parse( "user_id,new_username\r\n" )['rows'] );
	}

	/**
	 * UN-30 (`blt_toolkit_bulk_rename_max_rows`): exactly the cap parses; one
	 * more row refuses the whole file rather than truncating it; blank lines
	 * do not count.
	 *
	 * @return void
	 */
	public function test_un30_max_rows() {
		$csv = "user_id,new_username\n1,a\n\n2,b\n,\n3,c\n";

		$this->assertCount( 3, $this->parse( $csv, 3 )['rows'] );

		$error = $this->assertRefused( 'blt_toolkit_csv_too_many_rows', $csv, 2 );

		$this->assertSame( array( 'max' => 2 ), $error->get_error_data() );
		$this->assertSame( 'The file has more than 2 rows with a new username. Split it into smaller files.', $error->get_error_message() );
		$this->assertCount( 1, $this->parse( "user_id,new_username\n1,a\n", 0 )['rows'], 'A cap below 1 still allows one row.' );
		$big = "user_id,new_username\n" . str_repeat( "1,a\n", 5001 );

		$this->assertSame( 'The file has more than 5,000 rows with a new username. Split it into smaller files.', $this->assertRefused( 'blt_toolkit_csv_too_many_rows', $big, 5000 )->get_error_message(), 'The cap is printed with thousands separators.' );
	}

	/**
	 * UN-30 steps 1 and 2, review fix: rows whose new_username is empty ask
	 * for nothing, so they do not count toward the cap and are not listed,
	 * only counted. The template of a site with more users than the cap
	 * uploads back with a few names filled in. A blank row whose cells
	 * overflow the header is kept for the planner to refuse.
	 *
	 * @return void
	 */
	public function test_un30_blank_rows_do_not_count_toward_the_cap() {
		$csv = "user_id,username,new_username\n";

		for ( $i = 1; $i <= 50; $i++ ) {
			$csv .= BLT_Toolkit_Csv::line( array( $i, 'user' . $i, 7 === $i ? 'seven' : '' ) );
		}

		$parsed = $this->parse( $csv, 3 );

		$this->assertSame( 49, $parsed['blank'] );
		$this->assertCount( 1, $parsed['rows'] );
		$this->assertSame( 'seven', $parsed['rows'][0]['cells']['new_username'] );
		$this->assertSame( 8, $parsed['rows'][0]['line'] );

		$shifted = $this->parse( "user_id,new_username\n1,,stray\n2, \n" );

		$this->assertSame( 1, $shifted['blank'], 'A cell of spaces is blank.' );
		$this->assertCount( 1, $shifted['rows'] );
		$this->assertSame( 1, $shifted['rows'][0]['extra'] );
	}

	/**
	 * UN-30, review fix: a record may have at most MAX_COLUMNS cells, so one
	 * line of delimiters is refused with its line instead of exhausting
	 * memory or time; duplicate header names are found in linear time.
	 *
	 * @return void
	 */
	public function test_un30_columns_capped() {
		$max = BLT_Toolkit_Csv::MAX_COLUMNS;

		$header = 'user_id,new_username';

		for ( $i = 3; $i <= $max; $i++ ) {
			$header .= ',c' . $i;
		}

		$this->assertCount( $max, $this->parse( $header . "\n1,a\n" )['header'], 'Exactly the cap is fine.' );

		$error = $this->assertRefused( 'blt_toolkit_csv_columns', $header . ",y\n1,a\n" );

		$this->assertSame( array( 'line' => 1 ), $error->get_error_data() );

		$error = $this->assertRefused( 'blt_toolkit_csv_columns', "user_id,new_username\n1,a\n2,b" . str_repeat( ',', $max ) . "\n" );

		$this->assertSame( array( 'line' => 3 ), $error->get_error_data() );
		$this->assertSame( 'The row on line 3 has more than 100 columns. Keep only the user_id or username column and new_username, then upload the file again.', $error->get_error_message() );

		$start = microtime( true );

		$this->assertRefused( 'blt_toolkit_csv_columns', 'user_id,new_username' . str_repeat( ',', 1000000 ) . "\n1,a\n" );
		$this->assertLessThan( 2.0, microtime( true ) - $start, 'A million commas are refused quickly.' );
	}

	/**
	 * UN-30: anything that is not UTF-8 is refused: Windows-1252 bytes,
	 * UTF-16 with its byte order mark (Excel's "Unicode Text"), and a binary
	 * file such as an .xlsx uploaded by mistake.
	 *
	 * @return void
	 */
	public function test_un30_bad_encoding_refused() {
		$this->assertRefused( 'blt_toolkit_csv_encoding', "username,new_username\nJos\xE9,jose\n" );
		$this->assertRefused( 'blt_toolkit_csv_encoding', "\xFF\xFEu\x00s\x00e\x00r\x00" );
		$this->assertRefused( 'blt_toolkit_csv_encoding', "\xFE\xFF\x00u\x00s" );
		$this->assertRefused( 'blt_toolkit_csv_encoding', "PK\x03\x04\x14\x00\x06\x00\x08\x00\x00\x00!\x00\xA4\xC3" );
		$this->assertRefused( 'blt_toolkit_csv_encoding', "\xEF\xBB\xBFuser_id,new_username\n1,\xC0\xAF\n" );
	}

	/**
	 * UN-30: a quote that is never closed is refused with the line it
	 * starts on.
	 *
	 * @return void
	 */
	public function test_un30_unterminated_quote() {
		$error = $this->assertRefused( 'blt_toolkit_csv_quote', "user_id,new_username\n1,a\n2,\"bob\n3,carol\n" );

		$this->assertSame( array( 'line' => 3 ), $error->get_error_data() );
	}

	/**
	 * UN-30: unquoted cells are trimmed, quoted cells kept exactly; missing
	 * cells read as empty; non-empty cells past the header are counted as
	 * extra (an unquoted delimiter), while an empty trailing one is not.
	 *
	 * @return void
	 */
	public function test_un30_cells_trim_missing_and_extra() {
		$parsed = $this->parse( "user_id,new_username\n 1 , bob \n2,\" kept \"\n3\n4,dave,smith\n5,eve,\n" );
		$rows   = $parsed['rows'];

		$this->assertSame( '1', $rows[0]['cells']['user_id'] );
		$this->assertSame( 'bob', $rows[0]['cells']['new_username'] );
		$this->assertSame( ' kept ', $rows[1]['cells']['new_username'] );
		$this->assertSame( 1, $parsed['blank'], 'The row with a missing new_username cell is blank.' );
		$this->assertSame( 1, $rows[2]['extra'] );
		$this->assertSame( 'dave', $rows[2]['cells']['new_username'] );
		$this->assertSame( 0, $rows[3]['extra'] );

		$unnamed = $this->parse( "user_id,,new_username\n1,x,a\n2,,b\n" )['rows'];

		$this->assertSame( 1, $unnamed[0]['extra'], 'A value under an unnamed column is extra.' );
		$this->assertSame( 0, $unnamed[1]['extra'] );
		$this->assertSame(
			array(
				'user_id'      => '1',
				'new_username' => 'a',
			),
			$unnamed[0]['cells']
		);
	}

	/**
	 * UN-30: line() quotes only what needs it and ends in CRLF.
	 *
	 * @return void
	 */
	public function test_un30_line_quoting() {
		$this->assertSame( "1,admin,\r\n", BLT_Toolkit_Csv::line( array( 1, 'admin', '' ) ) );
		$this->assertSame( "\"a,b\",\"say \"\"hi\"\"\",\"x\ny\",\" pad\"\r\n", BLT_Toolkit_Csv::line( array( 'a,b', 'say "hi"', "x\ny", ' pad' ) ) );
		$this->assertSame( "a,b;\"c;d\"\r\n", BLT_Toolkit_Csv::line( array( 'a,b', 'c;d' ), ';' ), 'With semicolons, a comma needs no quotes.' );
		$this->assertSame( ",x\r\n", BLT_Toolkit_Csv::line( array( array( 'nested' ), 'x' ) ), 'Non-scalars are written empty.' );
		$this->assertSame( "a,b\r\n", BLT_Toolkit_Csv::line( array( 'a', 'b' ), "\t" ), 'Only , and ; are delimiters.' );
	}

	/**
	 * UN-30 step 1: the template round-trips usernames exactly, including
	 * ones a formula-injection guard would have prefixed.
	 *
	 * @return void
	 */
	public function test_un30_template_round_trip() {
		$logins = array( 'admin', '-dash-', '@team', '=sum', '+plus', 'a"b', ' spaced ', 'José', 'x,y', 'two words', 'a;b' );

		foreach ( array( ',', ';' ) as $delimiter ) {
			$csv    = BLT_Toolkit_Csv::line( array( 'user_id', 'username', 'new_username' ), $delimiter );
			$filled = BLT_Toolkit_Csv::line( array( 'user_id', 'username', 'new_username' ), $delimiter );

			foreach ( $logins as $index => $login ) {
				$csv    .= BLT_Toolkit_Csv::line( array( $index + 1, $login, '' ), $delimiter );
				$filled .= BLT_Toolkit_Csv::line( array( $index + 1, $login, 'new' . $index ), $delimiter );
			}

			$parsed = $this->parse( $csv );

			$this->assertSame( $delimiter, $parsed['delimiter'] );
			$this->assertSame( array(), $parsed['rows'], 'The untouched template asks for nothing.' );
			$this->assertSame( count( $logins ), $parsed['blank'] );

			$parsed = $this->parse( $filled );

			$this->assertSame( $logins, array_map( 'strval', array_column( array_column( $parsed['rows'], 'cells' ), 'username' ) ), $delimiter );
		}
	}

	/**
	 * Formula injection: escape_cell() prefixes cells a spreadsheet would run
	 * and leaves everything else alone.
	 *
	 * @return void
	 */
	public function test_escape_cell_formula_injection() {
		foreach ( array( '=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx", "\nx" ) as $cell ) {
			$this->assertSame( "'" . $cell, BLT_Toolkit_Csv::escape_cell( $cell ), $cell );
		}

		foreach ( array( '', 'bob', '1=1', ' =1', "'quoted" ) as $cell ) {
			$this->assertSame( $cell, BLT_Toolkit_Csv::escape_cell( $cell ), $cell );
		}

		$this->assertSame( '', BLT_Toolkit_Csv::escape_cell( null ) );
		$this->assertSame( '12', BLT_Toolkit_Csv::escape_cell( 12 ) );
		$this->assertSame( "'-5", BLT_Toolkit_Csv::escape_cell( -5 ) );
	}
}
