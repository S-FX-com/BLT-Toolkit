<?php
/**
 * Superset semantics for capability sets (SEC-8).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether one set of capabilities covers another (SPEC 5.3, SEC-8).
 *
 * Pure: it takes plain arrays and calls no WordPress function, so the Guard's
 * "never act with more power than you have" rules (SEC-2, SEC-3, SEC-4) rest
 * on logic that is unit-tested with known vectors.
 *
 * The rules, each enforced in normalize():
 *
 * - Only capabilities whose value grants count. WordPress grants a
 *   capability when its value is not empty (`WP_User::has_cap()`), so `true`,
 *   `1` and `'1'` grant, while `false`, `0`, `''` and `null` do not. A
 *   capability explicitly set `false` on a user is therefore not held, even
 *   when one of their roles grants it: `WP_User::$allcaps` already carries
 *   that override, because user capabilities are merged over role ones.
 * - `do_not_allow` is never a capability anyone holds.
 * - Keys that are role names are role memberships stored in the same array
 *   (`WP_User::$allcaps` merges the `{prefix}capabilities` meta, which holds
 *   both), so they are dropped. Membership is compared through the role's
 *   capabilities, which are already in the set.
 *
 * Roles compare the same way: normalize a role's `capabilities` array, then
 * compare it with an actor's normalized `allcaps`.
 */
class BLT_Toolkit_Superset {

	/**
	 * The capability WordPress uses to refuse outright; never "held".
	 */
	const DO_NOT_ALLOW = 'do_not_allow';

	/**
	 * Effective capabilities from an `allcaps`-style array.
	 *
	 * @param array    $caps       Capability => value, as in `WP_User::$allcaps` or a role's `capabilities`.
	 * @param string[] $role_names Names (IDs) of the site's roles; keys matching one are dropped.
	 * @return array<string,true> The capabilities held, as a set, sorted by name.
	 */
	public static function normalize( array $caps, array $role_names = array() ) {
		$roles = array();

		foreach ( $role_names as $name ) {
			if ( is_scalar( $name ) ) {
				$roles[ (string) $name ] = true;
			}
		}

		$out = array();

		foreach ( $caps as $cap => $value ) {
			$cap = (string) $cap;

			if ( '' === $cap || empty( $value ) || self::DO_NOT_ALLOW === $cap || isset( $roles[ $cap ] ) ) {
				continue;
			}

			$out[ $cap ] = true;
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Whether the actor holds every capability the target holds.
	 *
	 * Both arguments should come from normalize(). Values are still read by
	 * whether they grant, and `do_not_allow` is still ignored, so a raw array
	 * cannot make a target look weaker than it is; role-name keys, however,
	 * are only dropped by normalize().
	 *
	 * @param array $actor_caps  The actor's capabilities (cap => true).
	 * @param array $target_caps The target's capabilities (cap => true).
	 * @return bool True when the target's capabilities are a subset of the actor's.
	 */
	public static function is_subset( array $actor_caps, array $target_caps ) {
		foreach ( $target_caps as $cap => $value ) {
			if ( empty( $value ) || self::DO_NOT_ALLOW === (string) $cap ) {
				continue;
			}

			if ( empty( $actor_caps[ $cap ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The target's capabilities that the actor does not hold.
	 *
	 * @param array $actor_caps  The actor's capabilities (cap => true), from normalize().
	 * @param array $target_caps The target's capabilities (cap => true), from normalize().
	 * @return string[] Capability names, sorted; empty when is_subset() is true.
	 */
	public static function missing( array $actor_caps, array $target_caps ) {
		$out = array();

		foreach ( $target_caps as $cap => $value ) {
			$cap = (string) $cap;

			if ( empty( $value ) || self::DO_NOT_ALLOW === $cap ) {
				continue;
			}

			if ( empty( $actor_caps[ $cap ] ) ) {
				$out[] = $cap;
			}
		}

		sort( $out, SORT_STRING );

		return $out;
	}
}
