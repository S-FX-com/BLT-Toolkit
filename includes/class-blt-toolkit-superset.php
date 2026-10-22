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
 *
 * The deprecated user levels (`level_0` to `level_10`) are left out when one
 * set is compared with another (is_subset() and missing()). Core gives every
 * default role its levels (Subscriber holds `read` and `level_0`), so a role
 * built from named capabilities alone, such as the SW-21 Support Agent
 * (`read`, `list_users`, `blt_switch_users`), would otherwise never cover a
 * Subscriber, and SPEC 17 (Phase 2) requires it to. normalize() keeps the
 * levels, because they are still held: granting or denying one is a grant
 * like any other (SEC-3), checked with missing_grants(), which counts them.
 *
 * The read-only markers `blt_readonly_{plural}` (RS-13, RS-14) take access
 * away instead of giving it: someone holding one and lacking the type's
 * `create_posts` cannot edit, delete or publish that type's items. So a
 * target's marker is never a power the actor needs (is_subset() and
 * missing() leave markers out), and granting one is a narrowing
 * (missing_grants() leaves them out too). The other direction is in
 * missing() and lift_needs(), with the type's strings injected as
 * "restrictions" (marker => array( 'edit' => the type's `edit_posts`,
 * 'create' => its `create_posts` strings )):
 *
 * - An actor held read-only on a type (marker held, no create string) does
 *   not, in effect, hold the type's `edit_posts`: a target who holds that
 *   string without the marker can edit their own items and the actor
 *   cannot, so missing() reports the string.
 * - Taking a marker away (removing it, or denying it to a user) lets its
 *   holders edit again, so it is a grant of editing (SEC-3): the actor must
 *   hold the type's `edit_posts` and not be held read-only on it
 *   (lift_needs()).
 */
class BLT_Toolkit_Superset {

	/**
	 * The capability WordPress uses to refuse outright; never "held".
	 */
	const DO_NOT_ALLOW = 'do_not_allow';

	/**
	 * Prefix of the read-only markers (RS-13), the same as
	 * BLT_Toolkit_Capability_Catalog::MARKER_PREFIX; written out so the
	 * Guard never loads the catalog (SEC-9).
	 */
	const MARKER_PREFIX = 'blt_readonly_';

	/**
	 * Whether a capability is one of the deprecated user levels, `level_0`
	 * to `level_10` (SEC-8 comparisons leave them out). Pure.
	 *
	 * @param mixed $cap Capability name.
	 * @return bool
	 */
	public static function is_level( $cap ) {
		return is_scalar( $cap ) && 1 === preg_match( '/^level_(?:[0-9]|10)$/', (string) $cap );
	}

	/**
	 * Whether a capability is a read-only marker, `blt_readonly_{plural}`
	 * (RS-13). Pure.
	 *
	 * @param mixed $cap Capability name.
	 * @return bool
	 */
	public static function is_marker( $cap ) {
		return is_scalar( $cap ) && 0 === strpos( (string) $cap, self::MARKER_PREFIX ) && strlen( (string) $cap ) > strlen( self::MARKER_PREFIX );
	}

	/**
	 * The strings a marker stands for: the type's `edit_posts` and its
	 * `create_posts` strings, from the injected restrictions, or derived
	 * from the marker's name (`edit_{plural}`, `create_{plural}`, the
	 * separate-permissions defaults) when no registered type uses it. Pure.
	 *
	 * @param string $marker       `blt_readonly_{plural}`.
	 * @param array  $restrictions Marker => array( 'edit' => string, 'create' => string[] ).
	 * @return array{edit:string,create:string[]}
	 */
	public static function marker_strings( $marker, array $restrictions ) {
		$marker = (string) $marker;
		$suffix = (string) substr( $marker, strlen( self::MARKER_PREFIX ) );
		$entry  = isset( $restrictions[ $marker ] ) && is_array( $restrictions[ $marker ] ) ? $restrictions[ $marker ] : array();
		$edit   = isset( $entry['edit'] ) && is_scalar( $entry['edit'] ) && '' !== (string) $entry['edit'] ? (string) $entry['edit'] : 'edit_' . $suffix;
		$create = array();

		foreach ( isset( $entry['create'] ) ? (array) $entry['create'] : array() as $string ) {
			if ( is_scalar( $string ) && '' !== (string) $string ) {
				$create[] = (string) $string;
			}
		}

		return array(
			'edit'   => $edit,
			'create' => array() !== $create ? array_values( array_unique( $create ) ) : array( 'create_' . $suffix ),
		);
	}

	/**
	 * Whether someone with these capabilities is held read-only by a marker
	 * (RS-14): they hold it and none of its type's create strings. Pure.
	 *
	 * @param array  $caps         Capabilities (cap => true), from normalize().
	 * @param string $marker       Marker.
	 * @param array  $restrictions See marker_strings().
	 * @return bool
	 */
	public static function held_read_only( array $caps, $marker, array $restrictions ) {
		if ( empty( $caps[ (string) $marker ] ) ) {
			return false;
		}

		$strings = self::marker_strings( $marker, $restrictions );

		foreach ( $strings['create'] as $create ) {
			if ( ! empty( $caps[ $create ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * What an actor lacks to take a marker away from someone (SEC-3): the
	 * type's `edit_posts` when they do not hold it, and its create string
	 * when they are held read-only on the type themselves. Empty when they
	 * may. Pure.
	 *
	 * @param array  $actor_caps   The actor's capabilities (cap => true), from normalize().
	 * @param string $marker       Marker being lifted.
	 * @param array  $restrictions See marker_strings().
	 * @return string[]
	 */
	public static function lift_needs( array $actor_caps, $marker, array $restrictions ) {
		$strings = self::marker_strings( $marker, $restrictions );
		$out     = array();

		if ( empty( $actor_caps[ $strings['edit'] ] ) ) {
			$out[] = $strings['edit'];
		}

		if ( self::held_read_only( $actor_caps, $marker, $restrictions ) ) {
			$out[] = $strings['create'][0];
		}

		sort( $out, SORT_STRING );

		return array_values( array_unique( $out ) );
	}

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
	 * Whether the actor holds every capability the target holds (SEC-2,
	 * SEC-4), leaving out the target's user levels and read-only markers
	 * (see the class notes and missing()).
	 *
	 * Both arguments should come from normalize(). Values are still read by
	 * whether they grant, and `do_not_allow` is still ignored, so a raw array
	 * cannot make a target look weaker than it is; role-name keys, however,
	 * are only dropped by normalize().
	 *
	 * @param array $actor_caps   The actor's capabilities (cap => true).
	 * @param array $target_caps  The target's capabilities (cap => true).
	 * @param array $restrictions Optional. See marker_strings().
	 * @return bool True when the target's capabilities are a subset of the actor's.
	 */
	public static function is_subset( array $actor_caps, array $target_caps, array $restrictions = array() ) {
		return array() === self::missing( $actor_caps, $target_caps, $restrictions );
	}

	/**
	 * The target's capabilities that the actor does not hold, leaving out the
	 * target's user levels and read-only markers (SEC-2, SEC-8).
	 *
	 * With restrictions, a type's `edit_posts` the actor holds while held
	 * read-only on the type counts as not held when the target holds it
	 * without that marker (see the class notes).
	 *
	 * @param array $actor_caps   The actor's capabilities (cap => true), from normalize().
	 * @param array $target_caps  The target's capabilities (cap => true), from normalize().
	 * @param array $restrictions Optional. See marker_strings().
	 * @return string[] Capability names, sorted; empty when is_subset() is true.
	 */
	public static function missing( array $actor_caps, array $target_caps, array $restrictions = array() ) {
		$out = self::not_held( $actor_caps, $target_caps, false );

		foreach ( $actor_caps as $cap => $value ) {
			if ( empty( $value ) || ! self::is_marker( $cap ) || ! empty( $target_caps[ $cap ] ) || ! self::held_read_only( $actor_caps, (string) $cap, $restrictions ) ) {
				continue;
			}

			$strings = self::marker_strings( (string) $cap, $restrictions );

			if ( ! empty( $target_caps[ $strings['edit'] ] ) ) {
				$out[] = $strings['edit'];
			}
		}

		$out = array_values( array_unique( array_map( 'strval', $out ) ) );

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Capabilities being granted (or denied) that the actor does not hold
	 * (SEC-3). Unlike missing(), user levels count: a non-admin may grant or
	 * deny `level_0` only while holding it, and never `level_10` without it.
	 * Read-only markers never count: granting one narrows access (RS-14).
	 * Denying or removing one widens it; callers check that with
	 * lift_needs().
	 *
	 * @param array $actor_caps The actor's capabilities (cap => true), from normalize().
	 * @param array $caps       Capabilities being granted or denied (cap => true).
	 * @return string[] Capability names, sorted; empty when every one is held.
	 */
	public static function missing_grants( array $actor_caps, array $caps ) {
		return self::not_held( $actor_caps, $caps, true );
	}

	/**
	 * The capabilities of `$caps` that grant and are missing from the actor.
	 *
	 * @param array $actor_caps   The actor's capabilities (cap => true).
	 * @param array $caps         Capabilities to look for (cap => value).
	 * @param bool  $count_levels Whether `level_0` to `level_10` count.
	 * @return string[] Capability names, sorted; markers never included.
	 */
	private static function not_held( array $actor_caps, array $caps, $count_levels ) {
		$out = array();

		foreach ( $caps as $cap => $value ) {
			$cap = (string) $cap;

			if ( empty( $value ) || self::DO_NOT_ALLOW === $cap || self::is_marker( $cap ) || ( ! $count_levels && self::is_level( $cap ) ) ) {
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
