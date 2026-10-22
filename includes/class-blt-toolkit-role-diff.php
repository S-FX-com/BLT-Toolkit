<?php
/**
 * Canonical form, hash and diff of a roles array.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure logic behind role snapshots (SNAP-5).
 *
 * Works on the shape of `$wp_roles->roles`:
 *
 *     array( 'editor' => array( 'name' => 'Editor', 'capabilities' => array( 'edit_posts' => true, ... ) ), ... )
 *
 * Takes plain arrays, returns plain arrays and strings, and calls no
 * WordPress function, so it is unit-tested with known vectors.
 */
class BLT_Toolkit_Role_Diff {

	/**
	 * Canonical JSON of a roles array: keys sorted recursively, so the same
	 * roles always produce the same string whatever order they were built in.
	 *
	 * Values are kept exactly as stored (a capability stored as `1` stays `1`),
	 * because a snapshot must restore what was there. Keys are compared as
	 * strings, so PHP versions that order numeric-looking keys differently
	 * still agree.
	 *
	 * @param array $roles Roles array.
	 * @return string
	 */
	public static function canonicalize( array $roles ) {
		$json = json_encode( self::sort_keys( $roles ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class; wp_json_encode() would add nothing here.

		return false === $json ? '' : $json;
	}

	/**
	 * SHA-256 of the canonical JSON.
	 *
	 * @param array $roles Roles array.
	 * @return string 64 lowercase hex characters.
	 */
	public static function hash( array $roles ) {
		return hash( 'sha256', self::canonicalize( $roles ) );
	}

	/**
	 * What changed between two roles arrays.
	 *
	 * Capabilities compare by whether they grant: a value stored as `1` and one
	 * stored as `true` are the same permission, so only a change from granting
	 * to denying (or back) counts as a flip.
	 *
	 * @param array $a Before.
	 * @param array $b After.
	 * @return array {
	 *     @type array $added   Role ID => array( 'name' => string, 'capabilities' => array<string,bool> ), roles only in $b.
	 *     @type array $removed Role ID => the same shape, roles only in $a.
	 *     @type array $changed Role ID => array(
	 *         'name'    => null or array( 'from' => string, 'to' => string ),
	 *         'added'   => array<string,bool>  capabilities only in $b, with their value,
	 *         'removed' => array<string,bool>  capabilities only in $a, with their value,
	 *         'flipped' => array<string,array{from:bool,to:bool}> capabilities whose grant changed,
	 *     ). Only roles with at least one difference appear.
	 * }
	 */
	public static function diff( array $a, array $b ) {
		$a = self::normalize( $a );
		$b = self::normalize( $b );

		$out = array(
			'added'   => array(),
			'removed' => array(),
			'changed' => array(),
		);

		foreach ( $b as $id => $role ) {
			if ( ! isset( $a[ $id ] ) ) {
				$out['added'][ $id ] = $role;
			}
		}

		foreach ( $a as $id => $role ) {
			if ( ! isset( $b[ $id ] ) ) {
				$out['removed'][ $id ] = $role;
				continue;
			}

			$change = self::diff_role( $role, $b[ $id ] );

			if ( null !== $change ) {
				$out['changed'][ $id ] = $change;
			}
		}

		ksort( $out['added'], SORT_STRING );
		ksort( $out['removed'], SORT_STRING );
		ksort( $out['changed'], SORT_STRING );

		return $out;
	}

	/**
	 * Whether a diff holds no change at all.
	 *
	 * @param array $diff Result of diff().
	 * @return bool
	 */
	public static function is_empty( array $diff ) {
		return empty( $diff['added'] ) && empty( $diff['removed'] ) && empty( $diff['changed'] );
	}

	/**
	 * How many roles and capabilities differ (SNAP-4's list column).
	 *
	 * A role added or removed counts once, and each of its capabilities counts
	 * as a differing capability. A changed role counts once, plus each
	 * capability added, removed or flipped.
	 *
	 * @param array $diff Result of diff().
	 * @return array{roles:int,capabilities:int}
	 */
	public static function counts( array $diff ) {
		$roles = 0;
		$caps  = 0;

		foreach ( array( 'added', 'removed' ) as $side ) {
			if ( empty( $diff[ $side ] ) || ! is_array( $diff[ $side ] ) ) {
				continue;
			}

			foreach ( $diff[ $side ] as $role ) {
				++$roles;
				$caps += isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? count( $role['capabilities'] ) : 0;
			}
		}

		if ( ! empty( $diff['changed'] ) && is_array( $diff['changed'] ) ) {
			foreach ( $diff['changed'] as $change ) {
				++$roles;

				foreach ( array( 'added', 'removed', 'flipped' ) as $kind ) {
					$caps += isset( $change[ $kind ] ) && is_array( $change[ $kind ] ) ? count( $change[ $kind ] ) : 0;
				}
			}
		}

		return array(
			'roles'        => $roles,
			'capabilities' => $caps,
		);
	}

	/**
	 * Differences within one role, or null when there are none.
	 *
	 * @param array $before Normalized role.
	 * @param array $after  Normalized role.
	 * @return array|null
	 */
	private static function diff_role( array $before, array $after ) {
		$change = array(
			'name'    => null,
			'added'   => array(),
			'removed' => array(),
			'flipped' => array(),
		);

		if ( $before['name'] !== $after['name'] ) {
			$change['name'] = array(
				'from' => $before['name'],
				'to'   => $after['name'],
			);
		}

		foreach ( $after['capabilities'] as $cap => $grant ) {
			if ( ! array_key_exists( $cap, $before['capabilities'] ) ) {
				$change['added'][ $cap ] = $grant;
			} elseif ( $before['capabilities'][ $cap ] !== $grant ) {
				$change['flipped'][ $cap ] = array(
					'from' => $before['capabilities'][ $cap ],
					'to'   => $grant,
				);
			}
		}

		foreach ( $before['capabilities'] as $cap => $grant ) {
			if ( ! array_key_exists( $cap, $after['capabilities'] ) ) {
				$change['removed'][ $cap ] = $grant;
			}
		}

		if ( null === $change['name'] && empty( $change['added'] ) && empty( $change['removed'] ) && empty( $change['flipped'] ) ) {
			return null;
		}

		ksort( $change['added'], SORT_STRING );
		ksort( $change['removed'], SORT_STRING );
		ksort( $change['flipped'], SORT_STRING );

		return $change;
	}

	/**
	 * Roles keyed by string ID, each with a string name and boolean grants.
	 *
	 * Anything malformed (a role that is not an array, capabilities that are
	 * not an array) reads as empty instead of breaking the comparison.
	 *
	 * @param array $roles Roles array.
	 * @return array<string,array{name:string,capabilities:array<string,bool>}>
	 */
	private static function normalize( array $roles ) {
		$out = array();

		foreach ( $roles as $id => $role ) {
			$role = is_array( $role ) ? $role : array();
			$caps = array();

			if ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) {
				foreach ( $role['capabilities'] as $cap => $grant ) {
					$caps[ (string) $cap ] = (bool) $grant;
				}
			}

			ksort( $caps, SORT_STRING );

			$out[ (string) $id ] = array(
				'name'         => isset( $role['name'] ) && is_scalar( $role['name'] ) ? (string) $role['name'] : '',
				'capabilities' => $caps,
			);
		}

		return $out;
	}

	/**
	 * Sort array keys recursively, comparing keys as strings.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_keys( $item );
		}

		ksort( $value, SORT_STRING );

		return $value;
	}
}
