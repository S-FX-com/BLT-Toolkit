<?php
/**
 * CSV reading and writing for the bulk rename (UN-30).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses an uploaded bulk rename CSV and writes CSV lines (SPEC 8.6, UN-30).
 *
 * Pure: strings in, arrays out, no file or WordPress access, so every shape a
 * spreadsheet produces is unit-tested (SPEC 18): UTF-8 with or without a
 * byte order mark, comma or semicolon delimiters (detected from the header,
 * or from Excel's `sep=` first line), CRLF, LF or CR line ends, quoted
 * fields holding delimiters, quotes and line breaks, and blank lines.
 *
 * The header must name `new_username` and one of `user_id` (preferred) or
 * `username`, in any case, with surrounding spaces ignored and spaces or
 * dashes read as underscores (`New Username` is `new_username`). Other
 * columns are kept in the row's cells and ignored by the planner.
 *
 * Unquoted cells are trimmed; quoted cells are kept exactly, so a value
 * written by line() reads back unchanged.
 */
class BLT_Toolkit_Csv {

	/**
	 * The column that holds the new username.
	 */
	const NEW_COLUMN = 'new_username';

	/**
	 * Columns that identify the user, in order of preference.
	 */
	const ID_COLUMNS = array( 'user_id', 'username' );

	/**
	 * UTF-8 byte order mark.
	 */
	const BOM = "\xEF\xBB\xBF";

	/**
	 * Parse a bulk rename CSV.
	 *
	 * Rows come back in file order, blank lines (and lines whose cells are
	 * all empty) skipped. Each row is `line` (the physical line it starts on,
	 * counting from 1 including the header and blank lines), `cells` (header
	 * name => value, '' for a missing cell) and `extra` (how many non-empty
	 * cells sit beyond the header's columns: a username holding the
	 * delimiter without quotes, which the planner refuses).
	 *
	 * A file with more than `$max_rows` data rows is refused, not truncated.
	 *
	 * @param string $bytes    The file's contents.
	 * @param int    $max_rows Most data rows accepted (at least 1).
	 * @return array|WP_Error array( 'delimiter', 'header', 'id_column', 'rows' ), or the reason the file cannot be read.
	 */
	public static function parse( $bytes, $max_rows ) {
		$bytes    = (string) $bytes;
		$max_rows = max( 1, (int) $max_rows );

		if ( 0 === strncmp( $bytes, "\xFF\xFE", 2 ) || 0 === strncmp( $bytes, "\xFE\xFF", 2 ) ) {
			return self::encoding_error();
		}

		if ( 0 === strncmp( $bytes, self::BOM, 3 ) ) {
			$bytes = (string) substr( $bytes, 3 );
		}

		if ( 1 !== preg_match( '//u', $bytes ) ) {
			return self::encoding_error();
		}

		if ( '' === trim( $bytes ) ) {
			return new WP_Error( 'blt_toolkit_csv_empty', __( 'The file is empty.', 'blt-toolkit' ) );
		}

		$offset    = 0;
		$line      = 1;
		$delimiter = '';

		// Excel's "sep=;" first line names the delimiter.
		if ( 1 === preg_match( '/^sep=([,;])[ \t]*(\r\n|\n|\r|$)/i', $bytes, $match ) ) {
			$delimiter = $match[1];
			$offset    = strlen( $match[0] );
			$line      = 2;
		}

		if ( '' === $delimiter ) {
			$delimiter = self::detect_delimiter( $bytes, $offset );
		}

		$header = null;
		$rows   = array();
		$length = strlen( $bytes );

		while ( $offset < $length ) {
			$record = self::read_record( $bytes, $offset, $line, $delimiter );

			if ( is_wp_error( $record ) ) {
				return $record;
			}

			list( $cells, $quoted, $start ) = $record;

			if ( self::is_blank( $cells ) ) {
				continue;
			}

			if ( null === $header ) {
				$header = self::header( $cells );

				if ( is_wp_error( $header ) ) {
					return $header;
				}

				continue;
			}

			if ( count( $rows ) >= $max_rows ) {
				return new WP_Error(
					'blt_toolkit_csv_too_many_rows',
					sprintf(
						/* translators: %d: the most rows one file may have. */
						_n( 'The file has more than %d row. Split it into smaller files.', 'The file has more than %d rows. Split it into smaller files.', $max_rows, 'blt-toolkit' ),
						$max_rows
					),
					array( 'max' => $max_rows )
				);
			}

			$rows[] = self::row( $header, $cells, $quoted, $start );
		}

		if ( null === $header ) {
			return new WP_Error( 'blt_toolkit_csv_empty', __( 'The file is empty.', 'blt-toolkit' ) );
		}

		return array(
			'delimiter' => $delimiter,
			'header'    => $header,
			'id_column' => in_array( 'user_id', $header, true ) ? 'user_id' : 'username',
			'rows'      => $rows,
		);
	}

	/**
	 * One CSV line, ending in CRLF (RFC 4180), for output.
	 *
	 * A cell is quoted when it holds the delimiter, a quote or a line break,
	 * or starts or ends with whitespace, so parse() reads it back exactly.
	 * Nothing is prefixed: apply escape_cell() to free text that a
	 * spreadsheet might run as a formula, but not to usernames in the
	 * template, which must round-trip exactly (`-dash-` and `@team` are
	 * valid usernames).
	 *
	 * @param array  $cells     Cell values (scalars; anything else is written empty).
	 * @param string $delimiter `,` or `;`.
	 * @return string The line, with its CRLF.
	 */
	public static function line( array $cells, $delimiter = ',' ) {
		$delimiter = ';' === $delimiter ? ';' : ',';
		$out       = array();

		foreach ( $cells as $cell ) {
			$cell = is_scalar( $cell ) ? (string) $cell : '';

			if ( '' !== $cell && ( false !== strpbrk( $cell, $delimiter . "\"\r\n" ) || trim( $cell ) !== $cell ) ) {
				$cell = '"' . str_replace( '"', '""', $cell ) . '"';
			}

			$out[] = $cell;
		}

		return implode( $delimiter, $out ) . "\r\n";
	}

	/**
	 * Formula-injection protection for one cell: a value starting with `=`,
	 * `+`, `-`, `@`, a tab or a line break gets a leading apostrophe, so a
	 * spreadsheet shows it as text instead of running it (OWASP "CSV
	 * Injection"). Only for free text the caller chooses; see line().
	 *
	 * @param mixed $cell Value.
	 * @return string
	 */
	public static function escape_cell( $cell ) {
		$cell = is_scalar( $cell ) ? (string) $cell : '';

		if ( '' !== $cell && false !== strpos( "=+-@\t\r\n", $cell[0] ) ) {
			return "'" . $cell;
		}

		return $cell;
	}

	/**
	 * The delimiter of the first non-blank line: a semicolon when it has
	 * more unquoted semicolons than commas, otherwise a comma.
	 *
	 * @param string $bytes  File contents.
	 * @param int    $offset Where the header search starts.
	 * @return string
	 */
	private static function detect_delimiter( $bytes, $offset ) {
		$length = strlen( $bytes );
		$counts = array(
			','  => 0,
			';'  => 0,
		);
		$quoted = false;
		$seen   = false;

		for ( $i = $offset; $i < $length; $i++ ) {
			$char = $bytes[ $i ];

			if ( '"' === $char ) {
				$quoted = ! $quoted;
			} elseif ( ! $quoted && ( "\n" === $char || "\r" === $char ) ) {
				if ( $seen ) {
					break;
				}
			} elseif ( ! $quoted && isset( $counts[ $char ] ) ) {
				++$counts[ $char ];
				$seen = true;
			} elseif ( ' ' !== $char && "\t" !== $char ) {
				$seen = true;
			}
		}

		return $counts[';'] > $counts[','] ? ';' : ',';
	}

	/**
	 * Read one record (which quoted line breaks may spread over several
	 * lines) and advance the offset and line counter past it.
	 *
	 * @param string $bytes     File contents.
	 * @param int    $offset    Read position; advanced.
	 * @param int    $line      Current physical line; advanced.
	 * @param string $delimiter Delimiter.
	 * @return array|WP_Error array( cells, quoted flags, start line ).
	 */
	private static function read_record( $bytes, &$offset, &$line, $delimiter ) {
		$length = strlen( $bytes );
		$start  = $line;
		$stops  = $delimiter . "\r\n";
		$cells  = array();
		$quoted = array();

		while ( true ) {
			$cell      = '';
			$is_quoted = $offset < $length && '"' === $bytes[ $offset ];

			if ( $is_quoted ) {
				++$offset;

				while ( true ) {
					$close = strpos( $bytes, '"', $offset );

					if ( false === $close ) {
						return new WP_Error(
							'blt_toolkit_csv_quote',
							sprintf(
								/* translators: %d: line number in the file. */
								__( 'A quoted cell that starts on line %d is never closed.', 'blt-toolkit' ),
								$start
							),
							array( 'line' => $start )
						);
					}

					$chunk = (string) substr( $bytes, $offset, $close - $offset );
					$line += substr_count( $chunk, "\n" ) + substr_count( $chunk, "\r" ) - substr_count( $chunk, "\r\n" );
					$cell .= $chunk;

					$offset = $close + 1;

					// A doubled quote is a literal quote; anything else closes the cell.
					if ( $offset < $length && '"' === $bytes[ $offset ] ) {
						$cell .= '"';
						++$offset;
						continue;
					}

					break;
				}
			}

			// Unquoted text, or stray text after a closing quote (kept, as fgetcsv() does).
			$run     = strcspn( $bytes, $stops, $offset );
			$cell   .= (string) substr( $bytes, $offset, $run );
			$offset += $run;

			$cells[]  = $cell;
			$quoted[] = $is_quoted;

			if ( $offset >= $length ) {
				break;
			}

			if ( $delimiter === $bytes[ $offset ] ) {
				++$offset;
				continue;
			}

			// A line end: CRLF, LF or a lone CR.
			$offset += ( "\r" === $bytes[ $offset ] && $offset + 1 < $length && "\n" === $bytes[ $offset + 1 ] ) ? 2 : 1;
			++$line;
			break;
		}

		return array( $cells, $quoted, $start );
	}

	/**
	 * Whether every cell of a record is empty or whitespace.
	 *
	 * @param string[] $cells Cells.
	 * @return bool
	 */
	private static function is_blank( array $cells ) {
		foreach ( $cells as $cell ) {
			if ( '' !== trim( $cell ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize and check the header row.
	 *
	 * @param string[] $cells Header cells.
	 * @return string[]|WP_Error Column names ('' for an unnamed column).
	 */
	private static function header( array $cells ) {
		$names = array();

		foreach ( $cells as $cell ) {
			$name = strtolower( trim( $cell ) );
			$name = (string) preg_replace( '/[\s\-]+/', '_', $name );

			if ( '' !== $name && in_array( $name, $names, true ) ) {
				return new WP_Error(
					'blt_toolkit_csv_header',
					sprintf(
						/* translators: %s: a column name, such as new_username. */
						__( 'The column %s appears more than once in the first row.', 'blt-toolkit' ),
						$name
					)
				);
			}

			$names[] = $name;
		}

		if ( ! in_array( self::NEW_COLUMN, $names, true ) || ! array_intersect( self::ID_COLUMNS, $names ) ) {
			return new WP_Error(
				'blt_toolkit_csv_header',
				__( 'The first row must name the columns: user_id and new_username, or username and new_username.', 'blt-toolkit' )
			);
		}

		return $names;
	}

	/**
	 * One data row, keyed by the header.
	 *
	 * @param string[] $header Column names.
	 * @param string[] $cells  Cells.
	 * @param bool[]   $quoted Whether each cell was quoted.
	 * @param int      $start  Physical line.
	 * @return array
	 */
	private static function row( array $header, array $cells, array $quoted, $start ) {
		$assoc = array();
		$extra = 0;

		foreach ( $header as $name ) {
			if ( '' !== $name ) {
				$assoc[ $name ] = '';
			}
		}

		foreach ( $cells as $index => $cell ) {
			$value = $quoted[ $index ] ? $cell : trim( $cell );
			$name  = isset( $header[ $index ] ) ? $header[ $index ] : '';

			if ( '' === $name ) {
				if ( '' !== trim( $value ) ) {
					++$extra;
				}

				continue;
			}

			$assoc[ $name ] = $value;
		}

		return array(
			'line'  => $start,
			'cells' => $assoc,
			'extra' => $extra,
		);
	}

	/**
	 * The error for a file that is not UTF-8 text.
	 *
	 * @return WP_Error
	 */
	private static function encoding_error() {
		return new WP_Error(
			'blt_toolkit_csv_encoding',
			__( 'The file is not UTF-8 text. In your spreadsheet, save it as "CSV UTF-8" and upload it again.', 'blt-toolkit' )
		);
	}
}
