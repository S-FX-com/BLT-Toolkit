<?php
/**
 * Plans a bulk rename: resolve, validate against the projected state, order
 * (UN-30 step 3).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns parsed CSV rows into a dry-run plan (SPEC 8.6, UN-30 steps 3 and 4).
 *
 * Pure: users, validation and the SEC rules come in as arrays and callables,
 * so chains, cycles and conflicts are unit-tested with known vectors
 * (SPEC 18). The plan is advisory; each row still runs the full UN-10
 * procedure (authorization and validation against the real database) when
 * the `username_bulk` job executes it, so a plan made stale by a change in
 * between can only fail a row, never rename unsafely.
 *
 * What it does, in order, keeping the first error each row earns:
 *
 * 1. Resolve each row to a user: by `user_id` (preferred) or `username`
 *    (exact, then case-insensitive, as MySQL compares). When both columns
 *    are filled and the row asks for a rename they must agree. A blank
 *    new username is "no change" whatever the other cells hold (a
 *    spreadsheet may have rewritten them), and so is the current one
 *    exactly.
 * 2. Refuse every row of a cycle (`alice` to `bob` and `bob` to `alice`,
 *    or longer) with "Swap detected" (D8). This comes first because a row
 *    feeding into a cycle always claims a name the cycle claims too, and
 *    calling that a duplicate would hide the swap.
 * 3. Refuse the same user in two rows, and two rows claiming one new name
 *    (case-insensitively): every row involved is an error.
 * 4. Apply the SEC rules per row (`may_rename`).
 * 5. A row whose new name is the old name of a row that cannot run is
 *    blocked: that name stays taken. This repeats down chains.
 * 6. Validate each remaining row against the projected state: the old names
 *    of renamed users are free, so a name freed by another row is available.
 * 7. Step 5 again for rows that failed validation.
 * 8. Warnings for ready rows; execution order with dependencies first
 *    (`bob` to `carol` runs before `alice` to `bob`).
 */
class BLT_Toolkit_Rename_Planner {

	/**
	 * Row statuses.
	 */
	const READY     = 'ready';
	const ERROR     = 'error';
	const NO_CHANGE = 'no_change';

	/**
	 * Most line numbers one reason lists.
	 */
	const MAX_LINES = 20;

	/**
	 * Plan a bulk rename.
	 *
	 * `$users` maps user ID => array( 'login' => string, 'email' => string )
	 * and must hold every user a row names, by ID or by username; any other
	 * ID or username reads as unknown. Leave out users the actor may not
	 * see (SEC-6), so the plan never shows their login. Keys that are not
	 * positive IDs are ignored.
	 *
	 * `$validate( string $new, int $user_id, array $projected )` returns
	 * true or the reason the name cannot be used. `$projected` maps
	 * lowercased logins whose owner the batch changes to their owner
	 * afterwards (0 when freed); turn it into the validator's `login_owner`
	 * with owner_lookup() so every other name falls back to the database.
	 *
	 * `$context` keys, all optional:
	 * - `actor_id` (int): the person planning, for the "this is you" warning.
	 * - `may_rename` callable( int $user_id ): true|string, the SEC rules.
	 * - `switched` callable( int $user_id ): bool, for the UN-12 warning.
	 * - `nicename_changes` callable( int $user_id, string $new ): bool, for the
	 *   "author URL will change" warning (UN-13).
	 * The last two run only for ready rows.
	 *
	 * @param array    $rows     Rows from BLT_Toolkit_Csv::parse(): line, cells (and extra).
	 * @param array    $users    User ID => login and email.
	 * @param callable $validate Validation against the projected state.
	 * @param array    $context  Actor and callables.
	 * @return array array( 'rows', 'order', 'counts' ). Each row: line, user_id
	 *               (0 when unresolved), old, new, status (ready, error,
	 *               no_change), code, reason, warnings (code => message).
	 *               `order` lists ready rows' indexes (positions in `rows`,
	 *               which keeps the input order) in execution order. `counts`
	 *               has ready, error, no_change and total.
	 */
	public static function plan( array $rows, array $users, $validate, array $context = array() ) {
		$context = array_merge(
			array(
				'actor_id'         => 0,
				'may_rename'       => null,
				'switched'         => null,
				'nicename_changes' => null,
			),
			$context
		);

		$index = self::index_users( $users );
		$plan  = array();

		foreach ( array_values( $rows ) as $row ) {
			$plan[] = self::resolve( is_array( $row ) ? $row : array(), $index );
		}

		self::refuse_cycles( $plan );
		self::refuse_duplicates( $plan );

		// Step 4: the SEC rules, per row.
		if ( is_callable( $context['may_rename'] ) ) {
			foreach ( $plan as $i => $row ) {
				if ( self::READY !== $row['status'] ) {
					continue;
				}

				$allowed = call_user_func( $context['may_rename'], $row['user_id'] );

				if ( true !== $allowed ) {
					self::fail( $plan[ $i ], 'not_allowed', self::reason( $allowed, __( "You cannot change this user's username.", 'blt-toolkit' ) ) );
				}
			}
		}

		self::block_dependents( $plan );

		// Step 6: validate against the projected state.
		$projected = self::projection( $plan );

		foreach ( $plan as $i => $row ) {
			if ( self::READY !== $row['status'] ) {
				continue;
			}

			$valid = call_user_func( $validate, $row['new'], $row['user_id'], $projected );

			if ( true !== $valid ) {
				self::fail( $plan[ $i ], 'invalid', self::reason( $valid, __( 'This username cannot be used.', 'blt-toolkit' ) ) );
			}
		}

		self::block_dependents( $plan );
		self::add_warnings( $plan, $context );

		return array(
			'rows'   => $plan,
			'order'  => self::order( $plan ),
			'counts' => self::counts( $plan ),
		);
	}

	/**
	 * A validator `login_owner` lookup over a projection from plan(): names
	 * the batch frees or claims answer from the projection, every other name
	 * from the fallback (username_exists() in WordPress).
	 *
	 * @param array    $projected Lowercased login => owner ID (0 when free).
	 * @param callable $fallback  callable( string $login ): int|false.
	 * @return callable callable( string $login ): int.
	 */
	public static function owner_lookup( array $projected, $fallback ) {
		return function ( $login ) use ( $projected, $fallback ) {
			$key = strtolower( (string) $login );

			if ( array_key_exists( $key, $projected ) ) {
				return (int) $projected[ $key ];
			}

			return (int) call_user_func( $fallback, $login );
		};
	}

	/**
	 * Logins by user, plus exact and case-folded indexes.
	 *
	 * @param array $users User ID => login and email.
	 * @return array array( 'logins' => id => login, 'exact' => login => id, 'folded' => lowercased login => id, or -1 when two users share it ).
	 */
	private static function index_users( array $users ) {
		$index = array(
			'logins' => array(),
			'exact'  => array(),
			'folded' => array(),
		);

		foreach ( $users as $id => $user ) {
			$id = (int) $id;

			if ( $id <= 0 || ! is_array( $user ) || ! isset( $user['login'] ) || ! is_scalar( $user['login'] ) ) {
				continue;
			}

			$login = (string) $user['login'];
			$key   = strtolower( $login );

			$index['logins'][ $id ]   = $login;
			$index['exact'][ $login ] = $id;
			$index['folded'][ $key ]  = ( isset( $index['folded'][ $key ] ) && $index['folded'][ $key ] !== $id ) ? -1 : $id;
		}

		return $index;
	}

	/**
	 * Step 1: a plan row from a CSV row.
	 *
	 * @param array $row   CSV row.
	 * @param array $index From index_users().
	 * @return array
	 */
	private static function resolve( array $row, array $index ) {
		$cells = isset( $row['cells'] ) && is_array( $row['cells'] ) ? $row['cells'] : array();
		$plan  = array(
			'line'     => isset( $row['line'] ) ? (int) $row['line'] : 0,
			'user_id'  => 0,
			'old'      => '',
			'new'      => self::cell( $cells, 'new_username' ),
			'status'   => self::READY,
			'code'     => '',
			'reason'   => '',
			'warnings' => array(),
		);

		if ( ! empty( $row['extra'] ) ) {
			return self::fail( $plan, 'extra_cells', __( 'This row has more cells than the first row has columns. Usernames cannot contain commas or semicolons, so check for a stray one.', 'blt-toolkit' ) );
		}

		$id_text = self::cell( $cells, 'user_id' );
		$name    = self::cell( $cells, 'username' );
		$blank   = '' === $plan['new'];

		if ( '' !== $id_text ) {
			// A row that asks for no rename is "no change" whatever else it
			// holds: a spreadsheet may have rewritten the cells the person
			// never touched (leading zeros dropped, `3-Dec` for `12-3`), and
			// those rows must not become errors.
			if ( 1 !== preg_match( '/^[0-9]+$/', $id_text ) || 0 === (int) $id_text ) {
				if ( $blank ) {
					return self::skip_blank( $plan );
				}

				/* translators: %s: the value found in the user_id column. */
				return self::fail( $plan, 'bad_user_id', sprintf( __( '%s is not a user ID.', 'blt-toolkit' ), $id_text ) );
			}

			$id = (int) $id_text;

			if ( ! isset( $index['logins'][ $id ] ) ) {
				if ( $blank ) {
					return self::skip_blank( $plan );
				}

				/* translators: %d: a user ID. */
				return self::fail( $plan, 'unknown_user', sprintf( __( 'No user has the ID %d.', 'blt-toolkit' ), $id ) );
			}

			$plan['user_id'] = $id;
			$plan['old']     = $index['logins'][ $id ];

			if ( $blank ) {
				return self::skip_blank( $plan );
			}

			// With a new username, the username column must agree: a wrong
			// user ID must not rename someone else.
			if ( '' !== $name && strtolower( $name ) !== strtolower( $plan['old'] ) ) {
				return self::fail(
					$plan,
					'username_mismatch',
					/* translators: 1: a user ID. 2: that user's username. 3: the username the row gives. */
					sprintf( __( 'User ID %1$d has the username %2$s, not %3$s.', 'blt-toolkit' ), $id, $plan['old'], $name )
				);
			}
		} elseif ( '' !== $name ) {
			$key = strtolower( $name );
			$id  = 0;

			if ( isset( $index['exact'][ $name ] ) ) {
				$id = $index['exact'][ $name ];
			} elseif ( isset( $index['folded'][ $key ] ) && $index['folded'][ $key ] > 0 ) {
				$id = $index['folded'][ $key ];
			}

			if ( 0 === $id ) {
				if ( $blank ) {
					return self::skip_blank( $plan );
				}

				/* translators: %s: a username. */
				return self::fail( $plan, 'unknown_user', sprintf( __( 'No user has the username %s.', 'blt-toolkit' ), $name ) );
			}

			$plan['user_id'] = $id;
			$plan['old']     = $index['logins'][ $id ];
		} elseif ( '' !== $plan['new'] ) {
			return self::fail( $plan, 'no_user', __( 'This row does not say which user to rename.', 'blt-toolkit' ) );
		}

		if ( $blank ) {
			return self::skip_blank( $plan );
		}

		if ( $plan['new'] === $plan['old'] ) {
			return self::skip( $plan, 'unchanged', __( 'The new username is the same as the current one.', 'blt-toolkit' ) );
		}

		return $plan;
	}

	/**
	 * Step 3: the same user twice, or one new name claimed twice; every row
	 * involved is an error.
	 *
	 * @param array $plan Plan rows; changed in place.
	 * @return void
	 */
	private static function refuse_duplicates( array &$plan ) {
		$by_user   = array();
		$by_target = array();

		foreach ( $plan as $i => $row ) {
			if ( self::READY === $row['status'] ) {
				$by_user[ $row['user_id'] ][]             = $i;
				$by_target[ strtolower( $row['new'] ) ][] = $i;
			}
		}

		foreach ( $by_user as $group ) {
			if ( count( $group ) > 1 ) {
				/* translators: %s: comma-separated line numbers. */
				$reason = sprintf( __( 'This user appears in more than one row (lines %s).', 'blt-toolkit' ), self::lines( $plan, $group ) );

				foreach ( $group as $i ) {
					self::fail( $plan[ $i ], 'duplicate_user', $reason );
				}
			}
		}

		foreach ( $by_target as $group ) {
			if ( count( $group ) > 1 ) {
				/* translators: 1: a username. 2: comma-separated line numbers. */
				$reason = sprintf( __( 'More than one row renames a user to %1$s (lines %2$s).', 'blt-toolkit' ), $plan[ $group[0] ]['new'], self::lines( $plan, $group ) );

				foreach ( $group as $i ) {
					self::fail( $plan[ $i ], 'duplicate_target', $reason );
				}
			}
		}
	}

	/**
	 * Step 2: every row of a cycle is refused (D8). Each row points to at
	 * most one other (the row renaming the user whose old name it takes),
	 * so following the pointers finds every cycle once.
	 *
	 * @param array $plan Plan rows; changed in place.
	 * @return void
	 */
	private static function refuse_cycles( array &$plan ) {
		$next  = self::edges( $plan );
		$state = array();

		foreach ( array_keys( $next ) as $start ) {
			$path = array();
			$at   = $start;

			while ( null !== $at && ! isset( $state[ $at ] ) ) {
				$state[ $at ] = 1;
				$path[]       = $at;
				$at           = isset( $next[ $at ] ) ? $next[ $at ] : null;
			}

			if ( null !== $at && 1 === $state[ $at ] ) {
				$cycle = array_slice( $path, (int) array_search( $at, $path, true ) );

				/* translators: %s: comma-separated line numbers. */
				$reason = sprintf( __( 'Swap detected: rename one of these to a temporary name first (lines %s).', 'blt-toolkit' ), self::lines( $plan, $cycle ) );

				foreach ( $cycle as $i ) {
					self::fail( $plan[ $i ], 'cycle', $reason );
				}
			}

			foreach ( $path as $i ) {
				$state[ $i ] = 2;
			}
		}
	}

	/**
	 * Steps 5 and 7: a ready row whose new name is the old name of a row
	 * that cannot run is blocked, since that name stays taken; repeated down
	 * chains.
	 *
	 * @param array $plan Plan rows; changed in place.
	 * @return void
	 */
	private static function block_dependents( array &$plan ) {
		$waiting = array();
		$queue   = array();

		foreach ( $plan as $i => $row ) {
			if ( self::READY === $row['status'] ) {
				$waiting[ strtolower( $row['new'] ) ][] = $i;
			} elseif ( self::ERROR === $row['status'] && $row['user_id'] > 0 ) {
				$queue[] = $i;
			}
		}

		// The queue grows while it is walked.
		for ( $head = 0; isset( $queue[ $head ] ); $head++ ) {
			$failed = $queue[ $head ];
			$key    = strtolower( $plan[ $failed ]['old'] );

			if ( empty( $waiting[ $key ] ) ) {
				continue;
			}

			foreach ( $waiting[ $key ] as $i ) {
				if ( self::READY !== $plan[ $i ]['status'] || $plan[ $i ]['user_id'] === $plan[ $failed ]['user_id'] ) {
					continue;
				}

				self::fail(
					$plan[ $i ],
					'blocked',
					/* translators: 1: a username. 2: a line number. */
					sprintf( __( '%1$s is still taken: the row on line %2$d that would free it cannot run.', 'blt-toolkit' ), $plan[ $i ]['new'], $plan[ $failed ]['line'] )
				);

				$queue[] = $i;
			}
		}
	}

	/**
	 * The projected owners of the names the batch frees: lowercased old
	 * login => 0, or the ID of the ready row that takes it.
	 *
	 * @param array $plan Plan rows.
	 * @return array
	 */
	private static function projection( array $plan ) {
		$projected = array();

		foreach ( $plan as $row ) {
			if ( self::READY === $row['status'] ) {
				$projected[ strtolower( $row['old'] ) ] = 0;
			}
		}

		foreach ( $plan as $row ) {
			$key = strtolower( $row['new'] );

			if ( self::READY === $row['status'] && array_key_exists( $key, $projected ) ) {
				$projected[ $key ] = $row['user_id'];
			}
		}

		return $projected;
	}

	/**
	 * Step 8: warnings for ready rows (UN-30 step 4).
	 *
	 * @param array $plan    Plan rows; changed in place.
	 * @param array $context Planner context.
	 * @return void
	 */
	private static function add_warnings( array &$plan, array $context ) {
		foreach ( $plan as $i => $row ) {
			if ( self::READY !== $row['status'] ) {
				continue;
			}

			if ( (int) $context['actor_id'] > 0 && (int) $context['actor_id'] === $row['user_id'] ) {
				$plan[ $i ]['warnings']['self'] = __( 'This is your own account.', 'blt-toolkit' );
			}

			if ( is_callable( $context['switched'] ) && call_user_func( $context['switched'], $row['user_id'] ) ) {
				$plan[ $i ]['warnings']['switched'] = __( 'Someone is switched into this account, or it is switched into another. Renaming ends that switched session.', 'blt-toolkit' );
			}

			if ( is_callable( $context['nicename_changes'] ) && call_user_func( $context['nicename_changes'], $row['user_id'], $row['new'] ) ) {
				$plan[ $i ]['warnings']['author_url'] = __( 'The author URL will change.', 'blt-toolkit' );
			}
		}
	}

	/**
	 * Execution order of the ready rows: each after the row that frees its
	 * new name, otherwise in file order.
	 *
	 * @param array $plan Plan rows.
	 * @return int[] Row indexes.
	 */
	private static function order( array $plan ) {
		$next    = self::edges( $plan );
		$order   = array();
		$emitted = array();

		foreach ( $plan as $i => $row ) {
			if ( self::READY !== $row['status'] || isset( $emitted[ $i ] ) ) {
				continue;
			}

			$chain = array();
			$seen  = array();
			$at    = $i;

			while ( null !== $at && ! isset( $emitted[ $at ] ) && ! isset( $seen[ $at ] ) ) {
				$seen[ $at ] = true;
				$chain[]     = $at;
				$at          = isset( $next[ $at ] ) ? $next[ $at ] : null;
			}

			foreach ( array_reverse( $chain ) as $at ) {
				$order[]        = $at;
				$emitted[ $at ] = true;
			}
		}

		return $order;
	}

	/**
	 * Dependencies among ready rows: row index => index of the other ready
	 * row whose old name (case-insensitively) is this row's new name.
	 *
	 * @param array $plan Plan rows.
	 * @return array<int,int>
	 */
	private static function edges( array $plan ) {
		$by_old = array();

		foreach ( $plan as $i => $row ) {
			if ( self::READY === $row['status'] ) {
				$by_old[ strtolower( $row['old'] ) ] = $i;
			}
		}

		$next = array();

		foreach ( $plan as $i => $row ) {
			$key = strtolower( $row['new'] );

			if ( self::READY === $row['status'] && isset( $by_old[ $key ] ) && $by_old[ $key ] !== $i ) {
				$next[ $i ] = $by_old[ $key ];
			}
		}

		return $next;
	}

	/**
	 * Row counts by status.
	 *
	 * @param array $plan Plan rows.
	 * @return array
	 */
	private static function counts( array $plan ) {
		$counts = array(
			self::READY     => 0,
			self::ERROR     => 0,
			self::NO_CHANGE => 0,
			'total'         => count( $plan ),
		);

		foreach ( $plan as $row ) {
			++$counts[ $row['status'] ];
		}

		return $counts;
	}

	/**
	 * A trimmed cell, '' when missing.
	 *
	 * @param array  $cells Cells by column.
	 * @param string $name  Column.
	 * @return string
	 */
	private static function cell( array $cells, $name ) {
		return isset( $cells[ $name ] ) && is_scalar( $cells[ $name ] ) ? trim( (string) $cells[ $name ] ) : '';
	}

	/**
	 * Mark a row as an error, unless it already is one (the first reason
	 * wins).
	 *
	 * @param array  $row    Plan row; changed in place.
	 * @param string $code   Machine code.
	 * @param string $reason Message.
	 * @return array The row.
	 */
	private static function fail( array &$row, $code, $reason ) {
		if ( self::ERROR !== $row['status'] ) {
			$row['status'] = self::ERROR;
			$row['code']   = $code;
			$row['reason'] = $reason;
		}

		return $row;
	}

	/**
	 * Mark a row as "no change".
	 *
	 * @param array  $row    Plan row.
	 * @param string $code   Machine code.
	 * @param string $reason Message.
	 * @return array
	 */
	private static function skip( array $row, $code, $reason ) {
		$row['status'] = self::NO_CHANGE;
		$row['code']   = $code;
		$row['reason'] = $reason;

		return $row;
	}

	/**
	 * Mark a row that gives no new username as "no change".
	 *
	 * @param array $row Plan row.
	 * @return array
	 */
	private static function skip_blank( array $row ) {
		return self::skip( $row, 'empty', __( 'No new username given.', 'blt-toolkit' ) );
	}

	/**
	 * A reason from a callable's answer: its text, a WP_Error's message, or
	 * the fallback.
	 *
	 * @param mixed  $answer   What the callable returned.
	 * @param string $fallback Default message.
	 * @return string
	 */
	private static function reason( $answer, $fallback ) {
		if ( $answer instanceof WP_Error ) {
			$answer = $answer->get_error_message();
		}

		return is_string( $answer ) && '' !== trim( $answer ) ? $answer : $fallback;
	}

	/**
	 * Comma-separated line numbers of some rows, sorted; past MAX_LINES the
	 * list ends in an ellipsis, so a 5,000-row cycle does not put 5,000
	 * numbers in every row's reason.
	 *
	 * @param array $plan    Plan rows.
	 * @param int[] $indexes Row indexes.
	 * @return string
	 */
	private static function lines( array $plan, array $indexes ) {
		$lines = array();

		foreach ( $indexes as $i ) {
			$lines[] = (int) $plan[ $i ]['line'];
		}

		sort( $lines );

		if ( count( $lines ) > self::MAX_LINES ) {
			return implode( ', ', array_slice( $lines, 0, self::MAX_LINES ) ) . ', …';
		}

		return implode( ', ', $lines );
	}
}
