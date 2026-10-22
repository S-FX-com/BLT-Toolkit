<?php
/**
 * Role lifecycle: add, rename, move users to a new role, delete, reassign
 * and merge (SPEC 9.4).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, renames and removes roles, and moves people between them
 * (RO-30 to RO-42).
 *
 * Every write to the roles option goes through
 * BLT_Toolkit_Role_Store::transaction(), which re-reads the roles, runs the
 * Guard on the change, takes the SNAP-1 snapshot (and stops when it cannot
 * be stored), writes the option once, fires the save hooks and logs one row
 * with the diff. This class adds the lifecycle's own rules in front of it:
 *
 * - RO-30 Add: a display name, a role ID (suggested from the name, editable,
 *   the RO-23 rules with a 64-character limit) and a starting point: Blank
 *   (`read` only), the WordPress default Subscriber, Contributor, Author or
 *   Editor, or a copy of any role. A non-administrator is offered only the
 *   starting points whose capabilities they hold themselves (SEC-3, URE-3),
 *   and a forged choice is refused the same way. Critical capabilities need
 *   the typed confirmation of SEC-10.
 * - RO-31 Rename: the display name only; `administrator`'s name may change,
 *   never its ID.
 * - RO-32 Move users to a new role: a copy under the new ID, then the
 *   `role_reassign` job moves every holder; deleting the old role is
 *   offered afterwards.
 * - RO-40 Delete: never a built-in role, `administrator`, the default role
 *   or an additional default role (Settings > Roles). A role with users
 *   needs a replacement, and is deleted once the job has emptied it.
 * - RO-41 Reassign (job `role_reassign`): each holder gets the replacement
 *   in place of the role, or simply loses the role when they hold another
 *   one. One aggregate log row per batch, with the per-user details.
 * - RO-42 Merge: every holder gets the target role in place of this one,
 *   then the role is deleted, as one flow.
 *
 * Moving people between roles is changing their roles, so for anyone who
 * is not a toolkit admin each user is checked as `promote_user` is (SEC-1,
 * SEC-2), never their own roles (SEC-5), and the role they are given must be
 * one the actor may assign (SEC-4).
 *
 * The methods marked "pure" take plain values and call no WordPress
 * function that touches the database, cookies or globals;
 * tests/test-role-lifecycle.php covers them with known vectors.
 */
class BLT_Toolkit_Role_Lifecycle {

	/**
	 * Job type of RO-41 (JOB-5).
	 */
	const JOB_TYPE = 'role_reassign';

	/**
	 * Capability needed to follow, cancel and retry a reassign job: the
	 * Roles screen's own. The job's owner (or a toolkit admin) is checked on
	 * top of it (can_use()).
	 */
	const JOB_CAPABILITY = 'blt_edit_roles';

	/**
	 * Users per job item. Each item is one batch and writes one aggregate
	 * log row (RO-41).
	 */
	const BATCH = 50;

	/**
	 * Longest role ID (RO-30: the RO-23 rules with 64 instead of 191).
	 */
	const ID_MAX = 64;

	/**
	 * Longest display name, in characters.
	 */
	const NAME_MAX = 100;

	/**
	 * The role SEC-12 protects.
	 */
	const ADMIN_ROLE = 'administrator';

	/**
	 * WordPress's own roles, which are never deletable (RO-40).
	 */
	const BUILT_IN = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	/**
	 * Start option key of a blank role.
	 */
	const BLANK = 'blank';

	/**
	 * Prefix of a start option that copies an existing role.
	 */
	const COPY_PREFIX = 'copy:';

	/**
	 * RO-41: the replacement goes only to holders left with no role; anyone
	 * holding another role simply loses this one (delete, plain reassign).
	 */
	const MODE_REPLACE = 'replace';

	/**
	 * RO-32 and RO-42: every holder gets the target role in this one's place,
	 * keeping their other roles (move users, merge).
	 */
	const MODE_MOVE = 'move';

	/**
	 * Users loaded at once while counting a dry run.
	 */
	const COUNT_PAGE = 500;

	/**
	 * Stand-in target for the dry run of a move whose new role does not
	 * exist yet (RO-32). `#` never appears in a role ID, so no real role
	 * matches it, and a role that does not exist is assignable (SEC-4 has
	 * nothing to compare).
	 */
	const NEW_ROLE = '#new';

	/**
	 * Meta capability names a role ID may not take (RO-23), used when the
	 * capability catalog is not loaded. A role ID equal to a capability
	 * name would be read as a role membership wherever that capability is
	 * stored on a user (`WP_User::get_role_caps()`), and dropped from every
	 * SEC-8 comparison as a role name.
	 */
	const META_CAPS = array(
		'edit_post',
		'read_post',
		'delete_post',
		'publish_post',
		'edit_page',
		'read_page',
		'delete_page',
		'edit_user',
		'delete_user',
		'promote_user',
		'remove_user',
		'edit_comment',
		'edit_term',
		'delete_term',
		'assign_term',
		'add_post_meta',
		'edit_post_meta',
		'delete_post_meta',
		'add_comment_meta',
		'edit_comment_meta',
		'delete_comment_meta',
		'add_term_meta',
		'edit_term_meta',
		'delete_term_meta',
		'add_user_meta',
		'edit_user_meta',
		'delete_user_meta',
		'edit_css',
		'customize',
		'delete_site',
		'create_app_password',
		'list_app_passwords',
		'read_app_password',
		'edit_app_password',
		'delete_app_passwords',
		'delete_app_password',
		'blt_switch_to_user',
		'blt_change_username',
		'do_not_allow',
		'exist',
	);

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	// ---------- Boot ----------

	/**
	 * Register the `role_reassign` job type on `init` before priority 10 (its
	 * label is translated; every request that runs a tick comes after
	 * `init`). Only adds a hook, so it costs nothing on a page view.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'init', array( __CLASS__, 'register_job_type' ), 5 );
	}

	/**
	 * Register the `role_reassign` job type (RO-41, JOB-5).
	 *
	 * @return void
	 */
	public static function register_job_type() {
		BLT_Toolkit_Jobs::register_type(
			self::JOB_TYPE,
			array(
				'label'      => __( 'Move users between roles', 'blt-toolkit' ),
				'capability' => self::JOB_CAPABILITY,
				'mode'       => 'items',
				'process'    => array( __CLASS__, 'process' ),
				'describe'   => array( __CLASS__, 'describe' ),
				'can_use'    => array( __CLASS__, 'can_use' ),
				'audit'      => array( __CLASS__, 'audit' ),
			)
		);
	}

	// ---------- Pure rules (unit-tested) ----------

	/**
	 * The WordPress default capabilities of the templates a new role can
	 * start from (RO-30), as `populate_roles()` gives them. Pure.
	 *
	 * These are the defaults, not the site's current built-in roles, which
	 * may have been edited; copying the site's own Editor is the
	 * "copy of a role" option.
	 *
	 * @return array<string,array<string,bool>> Template key => capabilities.
	 */
	public static function templates() {
		$read        = array( 'read' );
		$subscriber  = array_merge( $read, array( 'level_0' ) );
		$contributor = array_merge( $subscriber, array( 'delete_posts', 'edit_posts', 'level_1' ) );
		$author      = array_merge( $contributor, array( 'delete_published_posts', 'edit_published_posts', 'level_2', 'publish_posts', 'upload_files' ) );
		$editor      = array_merge(
			$author,
			array(
				'delete_others_pages',
				'delete_others_posts',
				'delete_pages',
				'delete_private_pages',
				'delete_private_posts',
				'delete_published_pages',
				'edit_others_pages',
				'edit_others_posts',
				'edit_pages',
				'edit_private_pages',
				'edit_private_posts',
				'edit_published_pages',
				'level_3',
				'level_4',
				'level_5',
				'level_6',
				'level_7',
				'manage_categories',
				'manage_links',
				'moderate_comments',
				'publish_pages',
				'read_private_pages',
				'read_private_posts',
				'unfiltered_html',
			)
		);

		$out = array();

		foreach ( array(
			self::BLANK   => $read,
			'subscriber'  => $subscriber,
			'contributor' => $contributor,
			'author'      => $author,
			'editor'      => $editor,
		) as $key => $caps ) {
			$caps = array_fill_keys( $caps, true );

			ksort( $caps, SORT_STRING );

			$out[ $key ] = $caps;
		}

		return $out;
	}

	/**
	 * The starting points a new role may be built from (RO-30), filtered by
	 * SEC-3 for a non-administrator. Pure.
	 *
	 * Templates come first, then a copy of every role. For anyone who is not
	 * a toolkit admin an option is left out when it holds a capability the
	 * actor lacks (granted or denied, levels included, as the Guard's
	 * `can_grant()` counts them) or a key named like a role, and copying
	 * `administrator` is never offered (SEC-1, as `editable_roles` never
	 * offers it either).
	 *
	 * @param array    $roles          The site's roles, as `$wp_roles->roles`.
	 * @param array    $actor_caps     The actor's effective capabilities (cap => true).
	 * @param bool     $actor_is_admin Whether the actor is a toolkit admin.
	 * @param string[] $role_names     The site's role IDs.
	 * @return array<string,array{kind:string,role:string,name:string,caps:array}> Option key => option.
	 */
	public static function start_options( array $roles, array $actor_caps, $actor_is_admin, array $role_names ) {
		$out = array();

		foreach ( self::templates() as $key => $caps ) {
			$out[ $key ] = array(
				'kind' => 'template',
				'role' => $key,
				'name' => $key,
				'caps' => $caps,
			);
		}

		foreach ( $roles as $id => $role ) {
			$id   = (string) $id;
			$role = is_array( $role ) ? $role : array();

			$out[ self::COPY_PREFIX . $id ] = array(
				'kind' => 'copy',
				'role' => $id,
				'name' => isset( $role['name'] ) && is_scalar( $role['name'] ) ? (string) $role['name'] : $id,
				'caps' => isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array(),
			);
		}

		if ( $actor_is_admin ) {
			return $out;
		}

		foreach ( $out as $key => $option ) {
			if ( ( 'copy' === $option['kind'] && self::ADMIN_ROLE === $option['role'] ) || null !== self::copy_violation( $option['caps'], $actor_caps, $role_names ) ) {
				unset( $out[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * Why a non-administrator may not create a role with these capabilities
	 * (SEC-3), or null when they may. Pure.
	 *
	 * @param array    $caps       Capability => value of the new role.
	 * @param array    $actor_caps The actor's effective capabilities (cap => true).
	 * @param string[] $role_names The site's role IDs.
	 * @return array|null The Guard's violation record, or null.
	 */
	public static function copy_violation( array $caps, array $actor_caps, array $role_names ) {
		$grants = array();
		$denies = array();

		foreach ( $caps as $cap => $value ) {
			$cap = (string) $cap;

			if ( '' === $cap || BLT_Toolkit_Superset::DO_NOT_ALLOW === $cap ) {
				continue;
			}

			if ( empty( $value ) ) {
				$denies[] = $cap;
			} else {
				$grants[] = $cap;
			}
		}

		return BLT_Toolkit_Guard::grant_violation(
			array(
				'actor_is_admin' => false,
				'actor_caps'     => $actor_caps,
				'grants'         => $grants,
				'denies'         => $denies,
				'role_names'     => $role_names,
			)
		);
	}

	/**
	 * A role ID suggested from a display name (RO-30): accents dropped,
	 * spaces turned into underscores, then `sanitize_key()`, cut to 64
	 * characters, and given a `_2`, `_3` ... suffix when the result is taken.
	 * '' when the name gives nothing usable (no letter). Pure.
	 *
	 * @param string   $name     Display name.
	 * @param string[] $taken    Role IDs that exist.
	 * @param string[] $reserved Names a role ID may not take (capabilities).
	 * @return string
	 */
	public static function suggest_id( $name, array $taken = array(), array $reserved = array() ) {
		$base = trim( (string) $name );

		if ( function_exists( 'remove_accents' ) ) {
			$base = remove_accents( $base );
		}

		$base = (string) preg_replace( '/\s+/u', '_', strtolower( $base ) );
		$base = sanitize_key( $base );
		$base = trim( (string) preg_replace( '/_{2,}/', '_', $base ), '_-' );
		$base = trim( substr( $base, 0, self::ID_MAX ), '_-' );

		if ( '' === $base || 1 !== preg_match( '/[a-z]/', $base ) ) {
			return '';
		}

		$used = array();

		foreach ( array_merge( $taken, $reserved ) as $id ) {
			if ( is_scalar( $id ) ) {
				$used[ strtolower( (string) $id ) ] = true;
			}
		}

		$candidate = $base;

		for ( $n = 2; isset( $used[ $candidate ] ) && $n < 1000; $n++ ) {
			$suffix    = '_' . $n;
			$candidate = rtrim( substr( $base, 0, self::ID_MAX - strlen( $suffix ) ), '_-' ) . $suffix;
		}

		return isset( $used[ $candidate ] ) ? '' : $candidate;
	}

	/**
	 * What is wrong with a role ID (RO-30: the RO-23 rules with a limit of 64
	 * characters), as a code, or '' when it may be used. Pure.
	 *
	 * Codes: `empty`; `chars` (only lowercase letters, digits, `_` and `-`);
	 * `letter` (no letter); `length` (over 64); `exists` (a role has it,
	 * compared without case); `reserved` (it names a capability or a meta
	 * capability).
	 *
	 * @param string   $id       Proposed role ID, as typed.
	 * @param string[] $taken    Role IDs that exist.
	 * @param string[] $reserved Capability and meta capability names.
	 * @return string
	 */
	public static function id_error( $id, array $taken, array $reserved ) {
		$id = (string) $id;

		if ( '' === $id ) {
			return 'empty';
		}

		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $id ) ) {
			return 'chars';
		}

		if ( 1 !== preg_match( '/[a-z]/', $id ) ) {
			return 'letter';
		}

		if ( strlen( $id ) > self::ID_MAX ) {
			return 'length';
		}

		foreach ( $taken as $role ) {
			if ( is_scalar( $role ) && strtolower( (string) $role ) === $id ) {
				return 'exists';
			}
		}

		foreach ( $reserved as $name ) {
			if ( is_scalar( $name ) && strtolower( (string) $name ) === $id ) {
				return 'reserved';
			}
		}

		return '';
	}

	/**
	 * What is wrong with a display name, as a code, or '' when it may be
	 * used. Pure.
	 *
	 * Codes: `empty`; `length` (over 100 characters); `exists` (another role
	 * has the same name, compared without case, so two "Editor" entries never
	 * sit in one role menu).
	 *
	 * @param string               $name   Display name, already sanitized.
	 * @param array<string,string> $names  Role ID => display name of the site's roles.
	 * @param string               $except Role being renamed, whose own name does not count, or ''.
	 * @return string
	 */
	public static function name_error( $name, array $names, $except = '' ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return 'empty';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );

		if ( $length > self::NAME_MAX ) {
			return 'length';
		}

		$folded = self::fold( $name );

		foreach ( $names as $id => $other ) {
			if ( (string) $id !== (string) $except && is_scalar( $other ) && self::fold( (string) $other ) === $folded ) {
				return 'exists';
			}
		}

		return '';
	}

	/**
	 * Why a role can never be deleted (RO-40), as a code, or '' when the
	 * rules of RO-40 allow it. Who is asking is checked separately
	 * (delete_refusal()). Pure.
	 *
	 * Codes, first match wins: `missing`, `administrator`, `built_in`,
	 * `default` (the site's default role for new users), `extra_default`
	 * (one of the additional default roles of Settings > Roles).
	 *
	 * @param string $role  Role ID.
	 * @param array  $facts {
	 *     @type bool     $exists         Whether the role exists.
	 *     @type string   $default_role   The `default_role` option.
	 *     @type string[] $extra_defaults Settings > Roles additional default roles.
	 * }
	 * @return string
	 */
	public static function undeletable_reason( $role, array $facts ) {
		$role = (string) $role;

		if ( empty( $facts['exists'] ) ) {
			return 'missing';
		}

		if ( self::ADMIN_ROLE === $role ) {
			return 'administrator';
		}

		if ( in_array( $role, self::BUILT_IN, true ) ) {
			return 'built_in';
		}

		if ( isset( $facts['default_role'] ) && is_scalar( $facts['default_role'] ) && (string) $facts['default_role'] === $role ) {
			return 'default';
		}

		$extra = isset( $facts['extra_defaults'] ) && is_array( $facts['extra_defaults'] ) ? array_map( 'strval', $facts['extra_defaults'] ) : array();

		if ( in_array( $role, $extra, true ) ) {
			return 'extra_default';
		}

		return '';
	}

	/**
	 * What happens to one user when a role is emptied (RO-41). Pure.
	 *
	 * Ops: `skip` (the user does not hold the role any more: safe to repeat),
	 * `remove` (the role is dropped and the user keeps their other roles),
	 * `set` (it was their only role; they get the target instead), `swap`
	 * (move mode: the target takes the role's place, keeping its position,
	 * because a later role's `false` overrides an earlier `true`), `blocked`
	 * (it was their only role and there is no target).
	 *
	 * @param string[] $roles  The user's roles, in stored order.
	 * @param string   $role   The role being emptied.
	 * @param string   $target The replacement or destination role, or ''.
	 * @param string   $mode   MODE_REPLACE or MODE_MOVE.
	 * @return array{op:string,roles:string[]} The op and the user's roles afterwards.
	 */
	public static function plan_user( array $roles, $role, $target, $mode ) {
		$role   = (string) $role;
		$target = (string) $target === $role ? '' : (string) $target;
		$roles  = array_values( array_unique( array_map( 'strval', $roles ) ) );
		$pos    = array_search( $role, $roles, true );

		if ( false === $pos ) {
			return array(
				'op'    => 'skip',
				'roles' => $roles,
			);
		}

		$others = array_values( array_diff( $roles, array( $role ) ) );

		if ( array() === $others ) {
			return '' === $target
				? array(
					'op'    => 'blocked',
					'roles' => $roles,
				)
				: array(
					'op'    => 'set',
					'roles' => array( $target ),
				);
		}

		if ( '' === $target || in_array( $target, $others, true ) || self::MODE_MOVE !== $mode ) {
			return array(
				'op'    => 'remove',
				'roles' => $others,
			);
		}

		$swapped         = $roles;
		$swapped[ $pos ] = $target;

		return array(
			'op'    => 'swap',
			'roles' => $swapped,
		);
	}

	/**
	 * Split the holders of a role into job items of BATCH users. Pure.
	 *
	 * @param string $role Role ID.
	 * @param int[]  $ids  User IDs holding it.
	 * @param int    $size Users per item.
	 * @return array[] Each array( 'role' => string, 'users' => int[], 'from' => int (1-based), 'of' => int ).
	 */
	public static function batches( $role, array $ids, $size = self::BATCH ) {
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$size = max( 1, (int) $size );
		$of   = count( $ids );
		$out  = array();

		foreach ( array_chunk( $ids, $size ) as $index => $chunk ) {
			$out[] = array(
				'role'  => (string) $role,
				'users' => $chunk,
				'from'  => $index * $size + 1,
				'of'    => $of,
			);
		}

		return $out;
	}

	/**
	 * Case-insensitive form of a name, for duplicate checks. Pure.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fold( $text ) {
		$text = trim( (string) $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	// ---------- Messages ----------

	/**
	 * The message for an id_error() code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function id_message( $code ) {
		switch ( $code ) {
			case 'empty':
				return __( 'Enter a role ID, or a display name it can be made from.', 'blt-toolkit' );
			case 'chars':
				return __( 'A role ID may use only lowercase letters, digits, underscores and hyphens.', 'blt-toolkit' );
			case 'letter':
				return __( 'A role ID must contain at least one letter.', 'blt-toolkit' );
			case 'length':
				/* translators: %d: maximum number of characters. */
				return sprintf( __( 'A role ID can be at most %d characters long.', 'blt-toolkit' ), self::ID_MAX );
			case 'exists':
				return __( 'A role with this ID already exists. Choose another ID.', 'blt-toolkit' );
			case 'reserved':
				return __( 'This ID is the name of a capability, so it cannot be used for a role: WordPress would read it as one wherever that capability is given to a person.', 'blt-toolkit' );
		}

		return '';
	}

	/**
	 * The message for a name_error() code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function name_message( $code ) {
		switch ( $code ) {
			case 'empty':
				return __( 'Enter a display name for the role.', 'blt-toolkit' );
			case 'length':
				/* translators: %d: maximum number of characters. */
				return sprintf( __( 'A display name can be at most %d characters long.', 'blt-toolkit' ), self::NAME_MAX );
			case 'exists':
				return __( 'Another role already has this display name. Choose a name people can tell apart.', 'blt-toolkit' );
		}

		return '';
	}

	/**
	 * The message for an undeletable_reason() code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function undeletable_message( $code ) {
		switch ( $code ) {
			case 'missing':
				return __( 'This role does not exist.', 'blt-toolkit' );
			case 'administrator':
				return __( 'The Administrator role can never be deleted.', 'blt-toolkit' );
			case 'built_in':
				return __( 'WordPress\'s own roles cannot be deleted.', 'blt-toolkit' );
			case 'default':
				return __( 'This is the role new users get. Choose another default role in Settings > General first.', 'blt-toolkit' );
			case 'extra_default':
				return __( 'New users get this role as an additional default role. Take it out of BLT Toolkit Settings > Roles first.', 'blt-toolkit' );
		}

		return '';
	}

	/**
	 * A template's name (RO-30).
	 *
	 * @param string $key Template key.
	 * @return string
	 */
	public static function template_label( $key ) {
		switch ( $key ) {
			case self::BLANK:
				return __( 'Blank (can only sign in and read)', 'blt-toolkit' );
			case 'subscriber':
				return __( 'Subscriber (WordPress default)', 'blt-toolkit' );
			case 'contributor':
				return __( 'Contributor (WordPress default)', 'blt-toolkit' );
			case 'author':
				return __( 'Author (WordPress default)', 'blt-toolkit' );
			case 'editor':
				return __( 'Editor (WordPress default)', 'blt-toolkit' );
		}

		return (string) $key;
	}

	/**
	 * A start option's name for a screen or a log line.
	 *
	 * @param string $key Option key (a template key, or `copy:` and a role ID).
	 * @return string
	 */
	public static function start_label( $key ) {
		$key = (string) $key;

		if ( 0 === strpos( $key, self::COPY_PREFIX ) ) {
			$role = substr( $key, strlen( self::COPY_PREFIX ) );

			/* translators: %s: role name, such as Editor. */
			return sprintf( __( 'A copy of %s', 'blt-toolkit' ), self::role_name( $role ) );
		}

		return self::template_label( $key );
	}

	// ---------- Site facts ----------

	/**
	 * Role ID => display name (translated) of every role.
	 *
	 * @return array<string,string>
	 */
	public static function role_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ] = translate_user_role( (string) $name );
		}

		return $out;
	}

	/**
	 * What is wrong with a display name on this site: name_error() against
	 * the names as stored and as shown (translated), so a name that reads
	 * like another role's in the role menus is refused either way.
	 *
	 * @param string $name   Display name, sanitized.
	 * @param string $except Role being renamed, or ''.
	 * @return string Code, or ''.
	 */
	private static function name_problem( $name, $except = '' ) {
		$error = self::name_error( $name, self::stored_names(), $except );

		return '' !== $error ? $error : self::name_error( $name, self::role_names(), $except );
	}

	/**
	 * Role ID => display name as stored (untranslated), for duplicate checks.
	 *
	 * @return array<string,string>
	 */
	private static function stored_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ] = (string) $name;
		}

		return $out;
	}

	/**
	 * A role's display name, translated, or its ID when it has none.
	 *
	 * @param string $role Role ID.
	 * @return string
	 */
	public static function role_name( $role ) {
		$names = wp_roles()->role_names;
		$role  = (string) $role;

		return isset( $names[ $role ] ) && '' !== (string) $names[ $role ] ? translate_user_role( (string) $names[ $role ] ) : $role;
	}

	/**
	 * Names a role ID may not take: every capability the catalog knows (or,
	 * without the catalog, every capability on a role), BLT Toolkit's own,
	 * and the meta capabilities of RO-23.
	 *
	 * @return string[]
	 */
	public static function reserved_ids() {
		$names = self::META_CAPS;

		foreach ( array_keys( BLT_Toolkit_Caps::own() ) as $cap ) {
			$names[] = $cap;
		}

		foreach ( (array) wp_roles()->roles as $role ) {
			if ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) {
				foreach ( array_keys( $role['capabilities'] ) as $cap ) {
					$names[] = (string) $cap;
				}
			}
		}

		if ( class_exists( 'BLT_Toolkit_Capability_Catalog' ) && is_callable( array( 'BLT_Toolkit_Capability_Catalog', 'all' ) ) ) {
			$catalog = BLT_Toolkit_Capability_Catalog::all();

			if ( is_array( $catalog ) ) {
				foreach ( array_keys( $catalog ) as $cap ) {
					$names[] = (string) $cap;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Whether a name is reserved: in reserved_ids(), or a meta capability
	 * by the catalog's rule (RO-23).
	 *
	 * @param string   $id       Proposed role ID.
	 * @param string[] $reserved reserved_ids().
	 * @return bool
	 */
	private static function is_reserved( $id, array $reserved ) {
		if ( in_array( (string) $id, $reserved, true ) ) {
			return true;
		}

		return class_exists( 'BLT_Toolkit_Capability_Catalog' )
			&& is_callable( array( 'BLT_Toolkit_Capability_Catalog', 'is_meta_cap_name' ) )
			&& (bool) BLT_Toolkit_Capability_Catalog::is_meta_cap_name( (string) $id );
	}

	/**
	 * The facts undeletable_reason() reads, for a role on this site.
	 *
	 * @param string $role Role ID.
	 * @return array
	 */
	private static function deletion_facts( $role ) {
		$extra = BLT_Toolkit_Settings::get( 'roles', 'extra_default_roles' );

		return array(
			'exists'         => wp_roles()->is_role( (string) $role ),
			'default_role'   => (string) get_option( 'default_role' ),
			'extra_defaults' => is_array( $extra ) ? $extra : array(),
		);
	}

	/**
	 * Why a role cannot be deleted (RO-40), '' when it can: undeletable_reason()
	 * on this site's facts.
	 *
	 * @param string $role Role ID.
	 * @return string Code.
	 */
	public static function deletable_code( $role ) {
		return self::undeletable_reason( (string) $role, self::deletion_facts( $role ) );
	}

	/**
	 * Whether the current user may delete (or merge away) this role: it
	 * passes RO-40, and for a non-administrator the Guard's role rules hold
	 * for taking every capability out of it (SEC-1, SEC-2, SEC-3, SEC-5;
	 * SEC-13 for everyone).
	 *
	 * @param string $role Role ID.
	 * @return true|WP_Error
	 */
	public static function delete_refusal( $role ) {
		$role = (string) $role;
		$code = self::deletable_code( $role );

		if ( '' !== $code ) {
			return new WP_Error(
				'blt_toolkit_role_not_deletable',
				self::undeletable_message( $code ),
				array(
					'status' => 'missing' === $code ? 404 : 403,
					'reason' => $code,
					'role'   => $role,
				)
			);
		}

		$obj  = wp_roles()->get_role( $role );
		$caps = $obj instanceof WP_Role ? array_keys( (array) $obj->capabilities ) : array();

		return BLT_Toolkit_Guard::check_role_change( wp_get_current_user(), $role, array(), $caps );
	}

	/**
	 * The start options offered to the current user (RO-30, SEC-3).
	 *
	 * @return array<string,array> See start_options().
	 */
	public static function start_options_for_current_user() {
		$actor = wp_get_current_user();

		return self::start_options(
			(array) wp_roles()->roles,
			BLT_Toolkit_Guard::effective_caps( $actor ),
			BLT_Toolkit_Guard::is_toolkit_admin( $actor ),
			array_keys( (array) wp_roles()->role_names )
		);
	}

	/**
	 * Number of users holding each role, from one counting query
	 * (`count_users()`). For someone from whom SEC-6 hides administrators,
	 * the users list's own count without them
	 * (BLT_Toolkit_Guard::count_users_without_admins()): no administrator is
	 * counted, in the Administrator role or any other.
	 *
	 * @return array<string,int>
	 */
	public static function user_counts() {
		$counts = null;
		$hidden = BLT_Toolkit_Guard::hides_admins_from( wp_get_current_user() );

		if ( $hidden ) {
			$counts = BLT_Toolkit_Guard::count_users_without_admins( null, 'time', get_current_blog_id() );
		}

		if ( ! is_array( $counts ) ) {
			$counts = count_users();
		}

		if ( $hidden && isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ) {
			unset( $counts['avail_roles'][ self::ADMIN_ROLE ] );
		}

		$out = array();

		if ( isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ) {
			foreach ( $counts['avail_roles'] as $role => $count ) {
				$out[ (string) $role ] = (int) $count;
			}
		}

		return $out;
	}

	/**
	 * The IDs of every user holding a role, lowest first. One query.
	 *
	 * @param string $role Role ID.
	 * @return int[]
	 */
	public static function holder_ids( $role ) {
		$query = new WP_User_Query(
			array(
				'role'        => (string) $role,
				'fields'      => 'ID',
				'number'      => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);

		return array_map( 'intval', (array) $query->get_results() );
	}

	/**
	 * How many users hold a role. One query.
	 *
	 * @param string $role Role ID.
	 * @return int
	 */
	public static function holder_count( $role ) {
		$query = new WP_User_Query(
			array(
				'role'        => (string) $role,
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			)
		);

		return (int) $query->get_total();
	}

	// ---------- RO-30 Add ----------

	/**
	 * Add a role (RO-30).
	 *
	 * @param string $display_name Display name.
	 * @param string $role_id      Role ID; '' to use the one suggested from the name.
	 * @param string $from         Start option: `blank`, `subscriber`, `contributor`, `author`, `editor`, or `copy:` and a role ID.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type bool   $confirmed_critical Whether the actor typed the display name to confirm Critical capabilities (SEC-10).
	 *     @type bool   $dry_run            Check everything and return the result without writing.
	 *     @type string $context            Context for the save hooks (default `lifecycle`).
	 * }
	 * @return array|WP_Error array( 'role', 'name', 'from', 'caps', 'critical', 'snapshot_id', 'log_id', 'diff', 'dry_run' ),
	 *                        or a WP_Error whose data holds `status` and `field` (`name`, `role_id`, `from`) where one is at fault.
	 *                        `blt_toolkit_critical_review` (data `caps`, `name`, `role`) asks for the SEC-10 confirmation.
	 */
	public static function add( $display_name, $role_id, $from, array $args = array() ) {
		if ( ! current_user_can( 'blt_create_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return new WP_Error( 'blt_toolkit_cannot_create_roles', __( 'You do not have permission to add roles.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		$checked = self::check_new_role( $display_name, $role_id, $from );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$id       = $checked['role'];
		$name     = $checked['name'];
		$caps     = $checked['caps'];
		$from     = $checked['from'];
		$critical = BLT_Toolkit_Guard::requires_critical_review( $caps );
		$dry_run  = ! empty( $args['dry_run'] );

		// SEC-10. The role store refuses it too; asking here lets the screen
		// show the review step before anything is attempted.
		if ( array() !== $critical && empty( $args['confirmed_critical'] ) && ! $dry_run ) {
			return new WP_Error(
				'blt_toolkit_critical_review',
				__( 'This role would have Critical capabilities. Review them and type the role name to confirm.', 'blt-toolkit' ),
				array(
					'status'      => 409,
					'rule'        => 'SEC-10',
					'caps'        => $critical,
					'confirmable' => true,
					'name'        => $name,
					'role'        => $id,
					'from'        => $from,
				)
			);
		}

		$result = self::transaction(
			static function ( array $roles ) use ( $id, $name, $caps ) {
				foreach ( array_keys( $roles ) as $existing ) {
					if ( strtolower( (string) $existing ) === $id ) {
						return new WP_Error( 'blt_toolkit_role_exists', self::id_message( 'exists' ), array( 'status' => 409 ) );
					}
				}

				$roles[ $id ] = array(
					'name'         => $name,
					'capabilities' => $caps,
				);

				return $roles;
			},
			array(
				'reason'             => 'before_save',
				'action'             => 'role_created',
				'summary'            => sprintf(
					/* translators: 1: role name, 2: role ID, 3: what it started from, such as "A copy of Editor". */
					__( 'Created the %1$s role (%2$s), starting from: %3$s.', 'blt-toolkit' ),
					$name,
					$id,
					self::start_label( $from )
				),
				'context'            => isset( $args['context'] ) && is_string( $args['context'] ) ? $args['context'] : 'lifecycle',
				'confirmed_critical' => ! empty( $args['confirmed_critical'] ),
				'dry_run'            => $dry_run,
				'object_id'          => $id,
				'details'            => array(
					'role' => $id,
					'name' => $name,
					'from' => $from,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $dry_run ) {
			/**
			 * Fires after BLT Toolkit created a role (RO-30, RO-32).
			 *
			 * @param string $role_id      The new role's ID.
			 * @param string $display_name Its display name.
			 * @param string $from         What it started from: `blank`, `subscriber`, `contributor`, `author`, `editor`, or `copy:` and the copied role's ID.
			 */
			do_action( 'blt_toolkit_role_created', $id, $name, $from );
		}

		return self::with_result(
			$result,
			array(
				'role'     => $id,
				'name'     => $name,
				'from'     => $from,
				'caps'     => $caps,
				'critical' => $critical,
				'dry_run'  => $dry_run,
			)
		);
	}

	/**
	 * Check a new role's name, ID and starting point (RO-30, SEC-3). Writes
	 * nothing.
	 *
	 * @param string $display_name Display name.
	 * @param string $role_id      Role ID, or '' to suggest one.
	 * @param string $from         Start option key.
	 * @return array|WP_Error array( 'role', 'name', 'from', 'caps' ).
	 */
	public static function check_new_role( $display_name, $role_id, $from ) {
		$name       = self::clean_name( $display_name );
		$name_error = self::name_problem( $name );

		if ( '' !== $name_error ) {
			return new WP_Error(
				'blt_toolkit_role_name_' . $name_error,
				self::name_message( $name_error ),
				array(
					'status' => 400,
					'field'  => 'name',
				)
			);
		}

		$taken    = array_keys( (array) wp_roles()->roles );
		$reserved = self::reserved_ids();
		$id       = trim( (string) $role_id );

		if ( '' === $id ) {
			$id = self::suggest_id( $name, $taken, $reserved );
		}

		$id_error = self::id_error( $id, $taken, $reserved );

		if ( '' === $id_error && self::is_reserved( $id, $reserved ) ) {
			$id_error = 'reserved';
		}

		if ( '' !== $id_error ) {
			return new WP_Error(
				'blt_toolkit_role_id_' . $id_error,
				self::id_message( $id_error ),
				array(
					'status'  => 'exists' === $id_error ? 409 : 400,
					'field'   => 'role_id',
					'suggest' => self::suggest_id( $name, $taken, $reserved ),
				)
			);
		}

		$from    = trim( (string) $from );
		$from    = '' === $from ? self::BLANK : $from;
		$options = self::start_options_for_current_user();

		if ( ! isset( $options[ $from ] ) ) {
			return self::start_refusal( $from );
		}

		return array(
			'role' => $id,
			'name' => $name,
			'from' => $from,
			'caps' => $options[ $from ]['caps'],
		);
	}

	/**
	 * The error for a start option that was not offered: unknown, or one
	 * the actor may not copy (SEC-3), with the Guard's own message.
	 *
	 * @param string $from Start option key.
	 * @return WP_Error
	 */
	private static function start_refusal( $from ) {
		$all = self::start_options( (array) wp_roles()->roles, array(), true, array() );

		if ( ! isset( $all[ $from ] ) ) {
			return new WP_Error(
				'blt_toolkit_start_unknown',
				__( 'Choose what the new role starts from.', 'blt-toolkit' ),
				array(
					'status' => 400,
					'field'  => 'from',
				)
			);
		}

		$option = $all[ $from ];

		if ( 'copy' === $option['kind'] && self::ADMIN_ROLE === $option['role'] ) {
			return new WP_Error(
				'blt_toolkit_protected_role',
				__( 'Only an administrator can copy the Administrator role.', 'blt-toolkit' ),
				array(
					'status' => 403,
					'rule'   => 'SEC-1',
					'field'  => 'from',
				)
			);
		}

		$allowed = BLT_Toolkit_Guard::can_grant( wp_get_current_user(), $option['caps'] );
		$data    = array(
			'status' => 403,
			'rule'   => 'SEC-3',
			'field'  => 'from',
			'caps'   => array(),
		);

		if ( is_wp_error( $allowed ) ) {
			$data = array_merge( $data, (array) $allowed->get_error_data() );

			$data['field'] = 'from';

			return new WP_Error( 'blt_toolkit_start_not_allowed', $allowed->get_error_message(), $data );
		}

		return new WP_Error( 'blt_toolkit_start_not_allowed', __( 'You cannot start a role from this one, because it has capabilities you do not have.', 'blt-toolkit' ), $data );
	}

	// ---------- RO-31 Rename ----------

	/**
	 * Change a role's display name (RO-31). The ID never changes; the
	 * administrator role may be renamed by an administrator.
	 *
	 * @param string $role         Role ID.
	 * @param string $display_name New display name.
	 * @param array  $args         Optional: `dry_run` (bool).
	 * @return array|WP_Error array( 'role', 'old', 'name', 'snapshot_id', 'log_id', 'diff' ).
	 */
	public static function rename( $role, $display_name, array $args = array() ) {
		$role = (string) $role;

		if ( ! current_user_can( 'blt_edit_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return new WP_Error( 'blt_toolkit_cannot_edit_roles', __( 'You do not have permission to edit roles.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'blt_toolkit_role_missing', self::undeletable_message( 'missing' ), array( 'status' => 404 ) );
		}

		$stored = self::stored_names();
		$old    = isset( $stored[ $role ] ) ? $stored[ $role ] : $role;
		$name   = self::clean_name( $display_name );
		$error  = self::name_problem( $name, $role );

		if ( '' !== $error ) {
			return new WP_Error(
				'blt_toolkit_role_name_' . $error,
				self::name_message( $error ),
				array(
					'status' => 400,
					'field'  => 'name',
				)
			);
		}

		if ( $name === $old ) {
			return new WP_Error( 'blt_toolkit_nothing_to_change', __( 'That is already the role\'s name, so nothing was changed.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		// SEC-1, SEC-2, SEC-5 for a non-administrator: renaming is editing.
		$allowed = BLT_Toolkit_Guard::check_role_change( wp_get_current_user(), $role, array(), array() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$dry_run = ! empty( $args['dry_run'] );
		$result  = self::transaction(
			static function ( array $roles ) use ( $role, $name ) {
				if ( ! isset( $roles[ $role ] ) || ! is_array( $roles[ $role ] ) ) {
					return new WP_Error( 'blt_toolkit_role_missing', self::undeletable_message( 'missing' ), array( 'status' => 404 ) );
				}

				$roles[ $role ]['name'] = $name;

				return $roles;
			},
			array(
				'reason'    => 'before_save',
				'action'    => 'role_renamed',
				'summary'   => sprintf(
					/* translators: 1: old role name, 2: new role name, 3: role ID. */
					__( 'Renamed the role %1$s to %2$s (%3$s).', 'blt-toolkit' ),
					$old,
					$name,
					$role
				),
				'context'   => 'lifecycle',
				'dry_run'   => $dry_run,
				'object_id' => $role,
				'details'   => array(
					'role' => $role,
					'from' => $old,
					'to'   => $name,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $dry_run ) {
			/**
			 * Fires after BLT Toolkit changed a role's display name (RO-31).
			 *
			 * @param string $role_id  Role ID (unchanged).
			 * @param string $old_name Display name before.
			 * @param string $new_name Display name after.
			 */
			do_action( 'blt_toolkit_role_renamed', $role, $old, $name );
		}

		return self::with_result(
			$result,
			array(
				'role'    => $role,
				'old'     => $old,
				'name'    => $name,
				'dry_run' => $dry_run,
			)
		);
	}

	// ---------- RO-32 Move users to a new role ----------

	/**
	 * "Change role ID" as RO-32 offers it: create the new role as a copy of
	 * the old one, then move every holder to it with the `role_reassign`
	 * job. The old role stays; deleting it is offered once it is empty.
	 *
	 * @param string $role             Role whose users move.
	 * @param string $new_display_name Display name of the new role.
	 * @param string $new_id           ID of the new role ('' to suggest one).
	 * @param array  $args             Optional: `confirmed_critical` (SEC-10), `dry_run`.
	 * @return array|WP_Error add()'s result plus `old` (the old role), `users` (how many hold it) and `job_id` ('' when nobody did).
	 */
	public static function move_users( $role, $new_display_name, $new_id, array $args = array() ) {
		$role = (string) $role;

		if ( ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'blt_toolkit_role_missing', self::undeletable_message( 'missing' ), array( 'status' => 404 ) );
		}

		$refusal = self::move_refusal( $role );

		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}

		$users = self::holder_count( $role );

		if ( $users > 0 ) {
			$ready = self::can_move_people();

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		$created = self::add(
			$new_display_name,
			$new_id,
			self::COPY_PREFIX . $role,
			array_merge( $args, array( 'context' => 'lifecycle' ) )
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$created['old']    = $role;
		$created['users']  = $users;
		$created['job_id'] = '';

		if ( ! empty( $created['dry_run'] ) ) {
			$created['counts'] = self::reassign_counts( $role, $created['role'], self::MODE_MOVE );

			return $created;
		}

		self::copy_role_meta( $role, $created['role'] );

		if ( $users > 0 ) {
			$job = self::start_reassign_many(
				array( $role ),
				$created['role'],
				array(
					'mode'        => self::MODE_MOVE,
					'then_delete' => false,
					'context'     => 'move',
				)
			);

			if ( is_wp_error( $job ) ) {
				$created['job_error'] = $job;
			} else {
				$created['job_id'] = $job['job_id'];
			}
		}

		return $created;
	}

	// ---------- RO-40 Delete, RO-42 Merge ----------

	/**
	 * Delete roles (RO-40). Roles nobody holds are deleted at once, in one
	 * write; roles with users need a replacement, and are deleted by the
	 * `role_reassign` job once it has emptied them (RO-41).
	 *
	 * @param string[] $roles       Role IDs.
	 * @param string   $replacement Role given to holders left with no role (required when any role has users).
	 * @param array    $args {
	 *     Optional.
	 *
	 *     @type bool $merge   RO-42: every holder gets the replacement in the role's place (default false: RO-41).
	 *     @type bool $dry_run Return the counts without writing.
	 * }
	 * @return array|WP_Error array( 'deleted' => string[], 'pending' => string[] (deleted when the job ends), 'job_id' => string,
	 *                        'counts' => role => counts (reassign_counts()), 'snapshot_id', 'log_id', 'job_error' => WP_Error (only when
	 *                        roles were deleted but the job could not start) ).
	 */
	public static function delete( array $roles, $replacement = '', array $args = array() ) {
		$merge   = ! empty( $args['merge'] );
		$dry_run = ! empty( $args['dry_run'] );
		$roles   = array_values( array_unique( array_filter( array_map( 'strval', $roles ), 'strlen' ) ) );

		if ( ! current_user_can( 'blt_delete_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return new WP_Error( 'blt_toolkit_cannot_delete_roles', __( 'You do not have permission to delete roles.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( array() === $roles ) {
			return new WP_Error( 'blt_toolkit_no_roles', __( 'Choose at least one role.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		foreach ( $roles as $role ) {
			$refusal = self::delete_refusal( $role );

			if ( is_wp_error( $refusal ) ) {
				return self::name_role_in_error( $refusal, $role );
			}
		}

		$users      = array();
		$with_users = array();

		foreach ( $roles as $role ) {
			$users[ $role ] = self::holder_count( $role );

			if ( $users[ $role ] > 0 ) {
				$with_users[] = $role;
			}
		}

		$replacement = (string) $replacement;
		$mode        = $merge ? self::MODE_MOVE : self::MODE_REPLACE;

		if ( array() !== $with_users || $merge ) {
			$ready = self::check_replacement( $replacement, $roles, $merge );

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		if ( array() !== $with_users ) {
			$ready = self::can_move_people();

			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		$out = array(
			'deleted'     => array(),
			'pending'     => $with_users,
			'job_id'      => '',
			'counts'      => array(),
			'snapshot_id' => 0,
			'log_id'      => 0,
			'dry_run'     => $dry_run,
		);

		if ( $dry_run ) {
			foreach ( $roles as $role ) {
				$out['counts'][ $role ] = $users[ $role ] > 0
					? self::reassign_counts( $role, $replacement, $mode )
					: self::empty_counts();
			}

			return $out;
		}

		if ( array() !== $with_users && null !== self::live_job() ) {
			return self::busy_error();
		}

		$empty = array_values( array_diff( $roles, $with_users ) );

		if ( array() !== $empty ) {
			$removed = self::remove_roles( $empty, $replacement, $merge ? 'merge' : 'delete', false );

			if ( is_wp_error( $removed ) ) {
				return $removed;
			}

			$out['deleted']     = $empty;
			$out['snapshot_id'] = $removed['snapshot_id'];
			$out['log_id']      = $removed['log_id'];
		}

		if ( array() !== $with_users ) {
			$job = self::start_reassign_many(
				$with_users,
				$replacement,
				array(
					'mode'        => $mode,
					'then_delete' => true,
					'context'     => $merge ? 'merge' : 'delete',
				)
			);

			if ( is_wp_error( $job ) ) {
				if ( array() === $out['deleted'] ) {
					return $job;
				}

				$out['job_error'] = $job;
				$out['pending']   = array();
			} else {
				$out['job_id']  = $job['job_id'];
				$out['deleted'] = array_merge( $out['deleted'], $job['deleted'] );
				$out['pending'] = array_values( array_diff( $out['pending'], $job['deleted'] ) );
			}
		}

		return $out;
	}

	/**
	 * Merge a role into another (RO-42): every holder gets `$into` in its
	 * place, keeping their other roles, then the role is deleted, as one
	 * flow (delete() with `merge`).
	 *
	 * @param string $role Role to merge away.
	 * @param string $into Role its users get.
	 * @param array  $args Optional: `dry_run`.
	 * @return array|WP_Error See delete().
	 */
	public static function merge( $role, $into, array $args = array() ) {
		$role = (string) $role;
		$into = (string) $into;

		if ( '' === $into ) {
			return new WP_Error(
				'blt_toolkit_replacement_missing',
				__( 'Choose the role to merge into.', 'blt-toolkit' ),
				array(
					'status' => 400,
					'field'  => 'replacement',
				)
			);
		}

		return self::delete( array( $role ), $into, array_merge( $args, array( 'merge' => true ) ) );
	}

	/**
	 * Whether a replacement role may be used (RO-40 to RO-42): it exists, is
	 * not one of the roles going away, and the actor may give it to people
	 * (SEC-4).
	 *
	 * @param string   $replacement Role ID.
	 * @param string[] $roles       Roles being deleted.
	 * @param bool     $merge       Whether this is a merge (the wording differs).
	 * @return true|WP_Error
	 */
	private static function check_replacement( $replacement, array $roles, $merge ) {
		$data = array(
			'status' => 400,
			'field'  => 'replacement',
		);

		if ( '' === $replacement ) {
			return new WP_Error(
				'blt_toolkit_replacement_missing',
				$merge ? __( 'Choose the role to merge into.', 'blt-toolkit' ) : __( 'Some of these roles have users. Choose the role they get instead.', 'blt-toolkit' ),
				$data
			);
		}

		if ( ! wp_roles()->is_role( $replacement ) ) {
			return new WP_Error( 'blt_toolkit_replacement_unknown', __( 'The replacement role does not exist.', 'blt-toolkit' ), $data );
		}

		if ( in_array( $replacement, $roles, true ) ) {
			return new WP_Error( 'blt_toolkit_replacement_deleted', __( 'The replacement role cannot be one of the roles being deleted.', 'blt-toolkit' ), $data );
		}

		if ( ! BLT_Toolkit_Guard::may_assign_role( $replacement ) ) {
			$data['status'] = 403;
			$data['rule']   = 'SEC-4';

			return new WP_Error( 'blt_toolkit_replacement_not_allowed', __( 'You cannot give people this role, because it has capabilities you do not have.', 'blt-toolkit' ), $data );
		}

		return true;
	}

	/**
	 * Whether every holder of this role may be moved out of it (RO-32,
	 * RO-41): never for `administrator`. Its ID cannot change (SEC-12), and
	 * emptying it would leave nobody a toolkit admin (SPEC 5.2), with no way
	 * back through SEC-14 recovery, which needs someone holding the role.
	 *
	 * @param string $role Role ID.
	 * @return true|WP_Error `blt_toolkit_protected_role_move` (403, rule SEC-12).
	 */
	public static function move_refusal( $role ) {
		if ( ! BLT_Toolkit_Guard::is_protected_role( (string) $role ) ) {
			return true;
		}

		return new WP_Error(
			'blt_toolkit_protected_role_move',
			__( 'Users cannot be moved out of the Administrator role: its ID never changes, and the site needs people who hold it. Rename it instead, or change each person\'s role on the Users screen.', 'blt-toolkit' ),
			array(
				'status' => 403,
				'rule'   => 'SEC-12',
			)
		);
	}

	/**
	 * Whether the current user may change other people's roles at all:
	 * `promote_users`, as core asks on the Users screen. Each person is
	 * checked again when the job reaches them (may_change_user()).
	 *
	 * @return true|WP_Error
	 */
	private static function can_move_people() {
		if ( current_user_can( 'promote_users' ) ) {
			return true;
		}

		return new WP_Error( 'blt_toolkit_cannot_promote', __( 'People hold this role, and you do not have permission to change people\'s roles, so nothing was changed.', 'blt-toolkit' ), array( 'status' => 403 ) );
	}

	/**
	 * Delete roles that nobody holds, in one transaction, then fire
	 * `blt_toolkit_role_deleted` for each and drop their role metadata.
	 *
	 * The rules are checked again here, because a job deletes its roles
	 * long after the request that asked (the default role may have changed
	 * since).
	 *
	 * @param string[] $roles       Role IDs.
	 * @param string   $replacement The replacement ('' when none was needed), for the log and, when people held the roles, the hook.
	 * @param string   $context     `delete` or `merge`.
	 * @param bool     $were_held   Whether people held the roles and were given the replacement; false sends '' to the hook, as HOOKS.md says for a role nobody held.
	 * @return array|WP_Error The store's result.
	 */
	private static function remove_roles( array $roles, $replacement, $context, $were_held = true ) {
		foreach ( $roles as $role ) {
			$refusal = self::delete_refusal( $role );

			if ( is_wp_error( $refusal ) ) {
				return self::name_role_in_error( $refusal, $role );
			}
		}

		$names = array();

		foreach ( $roles as $role ) {
			$names[] = self::role_name( $role );
		}

		$list   = implode( wp_get_list_item_separator(), $names );
		$merge  = 'merge' === $context;
		$target = '' !== (string) $replacement ? self::role_name( $replacement ) : '';

		if ( $merge ) {
			/* translators: 1: role name being merged away, 2: role name it was merged into. */
			$summary = sprintf( __( 'Merged the %1$s role into %2$s and deleted it.', 'blt-toolkit' ), $list, $target );
		} else {
			/* translators: %s: comma-separated role names. */
			$summary = sprintf( _n( 'Deleted the role %s.', 'Deleted the roles %s.', count( $roles ), 'blt-toolkit' ), $list );
		}

		$result = self::transaction(
			static function ( array $all ) use ( $roles ) {
				foreach ( $roles as $role ) {
					if ( ! isset( $all[ $role ] ) ) {
						return new WP_Error( 'blt_toolkit_role_missing', self::undeletable_message( 'missing' ), array( 'status' => 404 ) );
					}

					unset( $all[ $role ] );
				}

				return $all;
			},
			array(
				'reason'    => 'before_save',
				'action'    => $merge ? 'role_merged' : 'role_deleted',
				'summary'   => $summary,
				'context'   => 'lifecycle',
				'object_id' => implode( ',', $roles ),
				'details'   => array(
					'roles'       => $roles,
					'replacement' => (string) $replacement,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::drop_role_meta( $roles );

		foreach ( $roles as $role ) {
			/**
			 * Fires after BLT Toolkit deleted a role (RO-40, RO-42).
			 *
			 * @param string $role_id     The deleted role's ID.
			 * @param string $replacement The role its users were given instead, or '' when nobody held it.
			 */
			do_action( 'blt_toolkit_role_deleted', $role, $were_held ? (string) $replacement : '' );
		}

		return self::with_result( $result, array( 'deleted' => $roles ) );
	}

	/**
	 * Add the role to a refusal's message so a multi-role delete says which
	 * role was refused.
	 *
	 * @param WP_Error $error Refusal.
	 * @param string   $role  Role ID.
	 * @return WP_Error
	 */
	private static function name_role_in_error( WP_Error $error, $role ) {
		$data         = (array) $error->get_error_data();
		$data['role'] = (string) $role;

		return new WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: role name, 2: why it cannot be deleted. */
				__( '%1$s: %2$s', 'blt-toolkit' ),
				self::role_name( $role ),
				$error->get_error_message()
			),
			$data
		);
	}

	// ---------- RO-41 Reassign ----------

	/**
	 * Start moving every holder of a role to another (RO-41) without
	 * deleting it.
	 *
	 * @param string $role        Role being emptied.
	 * @param string $replacement Role its holders get.
	 * @param array  $args {
	 *     Optional.
	 *
	 *     @type string $mode        MODE_REPLACE (default; holders with another role just lose it) or MODE_MOVE.
	 *     @type bool   $then_delete Delete the role once it is empty (default false).
	 *     @type string $context     `reassign` (default), `move`, `delete` or `merge`.
	 * }
	 * @return array|WP_Error array( 'job_id', 'users', 'batches', 'deleted' ).
	 */
	public static function start_reassign( $role, $replacement, array $args = array() ) {
		$role        = (string) $role;
		$replacement = (string) $replacement;

		if ( ! current_user_can( 'blt_edit_roles' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- BLT Toolkit's own capability (SPEC 5.1).
			return new WP_Error( 'blt_toolkit_cannot_edit_roles', __( 'You do not have permission to edit roles.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( ! wp_roles()->is_role( $role ) ) {
			return new WP_Error( 'blt_toolkit_role_missing', self::undeletable_message( 'missing' ), array( 'status' => 404 ) );
		}

		$refusal = self::move_refusal( $role );

		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}

		$ready = self::check_replacement( $replacement, array( $role ), false );

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$ready = self::can_move_people();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! empty( $args['then_delete'] ) ) {
			$refusal = self::delete_refusal( $role );

			if ( is_wp_error( $refusal ) ) {
				return $refusal;
			}
		}

		return self::start_reassign_many( array( $role ), $replacement, $args );
	}

	/**
	 * Start one `role_reassign` job over the holders of several roles.
	 *
	 * Refused while another one runs, because two jobs moving people between
	 * the same roles would each work from a list the other is changing.
	 * Roles that turn out to have no holder any more are deleted at once
	 * when `then_delete` is set.
	 *
	 * @param string[] $roles       Role IDs.
	 * @param string   $replacement Target role.
	 * @param array    $args        See start_reassign().
	 * @return array|WP_Error array( 'job_id', 'users', 'batches', 'deleted' ).
	 */
	private static function start_reassign_many( array $roles, $replacement, array $args ) {
		if ( null !== self::live_job() ) {
			return self::busy_error();
		}

		$mode        = isset( $args['mode'] ) && self::MODE_MOVE === $args['mode'] ? self::MODE_MOVE : self::MODE_REPLACE;
		$then_delete = ! empty( $args['then_delete'] );
		$context     = isset( $args['context'] ) && is_string( $args['context'] ) ? sanitize_key( $args['context'] ) : 'reassign';
		$items       = array();
		$users       = 0;
		$names       = array();
		$nobody      = array();

		foreach ( $roles as $role ) {
			$ids            = self::holder_ids( $role );
			$names[ $role ] = self::role_name( $role );

			if ( array() === $ids ) {
				$nobody[] = $role;
				continue;
			}

			$users += count( $ids );
			$items  = array_merge( $items, self::batches( $role, $ids, self::BATCH ) );
		}

		$out = array(
			'job_id'  => '',
			'users'   => $users,
			'batches' => count( $items ),
			'deleted' => array(),
		);

		if ( $then_delete && array() !== $nobody ) {
			$removed = self::remove_roles( $nobody, $replacement, $context, false );

			if ( is_wp_error( $removed ) ) {
				return $removed;
			}

			$out['deleted'] = $nobody;
		}

		if ( array() === $items ) {
			return $out;
		}

		$job = BLT_Toolkit_Jobs::start(
			self::JOB_TYPE,
			$items,
			array(
				'roles'       => array_values( $roles ),
				'target'      => (string) $replacement,
				'mode'        => $mode,
				'then_delete' => $then_delete,
				'context'     => $context,
				'names'       => $names,
				'target_name' => self::role_name( $replacement ),
			)
		);

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$out['job_id'] = $job;

		return $out;
	}

	/**
	 * The live `role_reassign` job, or null.
	 *
	 * @return array|null
	 */
	public static function live_job() {
		foreach ( BLT_Toolkit_Jobs::jobs( self::JOB_TYPE ) as $job ) {
			if ( in_array( $job['status'], BLT_Toolkit_Jobs::LIVE, true ) ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * The error for a second job while one runs.
	 *
	 * @return WP_Error
	 */
	private static function busy_error() {
		return new WP_Error( 'blt_toolkit_reassign_busy', __( 'People are still being moved between roles by an earlier change. Start this one when it has finished, or cancel the other.', 'blt-toolkit' ), array( 'status' => 409 ) );
	}

	/**
	 * Counts for a role with no holders.
	 *
	 * @return array See reassign_counts().
	 */
	private static function empty_counts() {
		return array(
			'users'   => 0,
			'get'     => 0,
			'remove'  => 0,
			'blocked' => 0,
			'self'    => false,
		);
	}

	/**
	 * What emptying a role would do (the RO-41 dry run): how many hold it,
	 * how many would get the target, how many would simply lose the role,
	 * and how many the current user may not change (SEC-1 to SEC-5, SEC-12).
	 * Writes nothing; names nobody (SEC-6).
	 *
	 * Cost (SEC-9): for a toolkit admin, whom only the plan itself can stop,
	 * it reads each holder's capabilities meta in pages of COUNT_PAGE rows and
	 * builds no user object. For anyone else every holder is checked by the
	 * Guard, a page of COUNT_PAGE users at a time, and each page's users are
	 * let go before the next, so memory stays at one page however many hold
	 * the role.
	 *
	 * @param string $role   Role being emptied.
	 * @param string $target Replacement, or NEW_ROLE for the copy a move creates.
	 * @param string $mode   MODE_REPLACE or MODE_MOVE.
	 * @return array{users:int,get:int,remove:int,blocked:int,self:bool}
	 */
	public static function reassign_counts( $role, $target, $mode ) {
		$role       = (string) $role;
		$target     = (string) $target;
		$out        = self::empty_counts();
		$assignable = '' !== $target && BLT_Toolkit_Guard::may_assign_role( $target );
		$me         = get_current_user_id();
		$role_caps  = array();

		// The copy a move creates does not exist yet; it will hold exactly
		// the role's capabilities.
		if ( self::NEW_ROLE === $target ) {
			$obj = wp_roles()->get_role( $role );

			$role_caps[ self::NEW_ROLE ] = $obj instanceof WP_Role && is_array( $obj->capabilities ) ? $obj->capabilities : array();
		}

		if ( BLT_Toolkit_Guard::is_toolkit_admin( wp_get_current_user() ) ) {
			self::each_holder(
				$role,
				static function ( $id, array $roles ) use ( &$out, $role, $target, $mode, $me ) {
					$plan = BLT_Toolkit_Role_Lifecycle::plan_user( $roles, $role, $target, $mode );

					++$out['users'];

					if ( 'skip' === $plan['op'] ) {
						return;
					}

					if ( $id === $me ) {
						$out['self'] = true;
					}

					if ( 'blocked' === $plan['op'] || BLT_Toolkit_Role_Lifecycle::drops_own_admin( $id === $me, $roles, $plan['roles'] ) ) {
						++$out['blocked'];
					} elseif ( 'remove' === $plan['op'] ) {
						++$out['remove'];
					} else {
						++$out['get'];
					}
				}
			);

			return $out;
		}

		$ids = self::holder_ids( $role );

		$out['users'] = count( $ids );

		foreach ( array_chunk( $ids, self::COUNT_PAGE ) as $chunk ) {
			cache_users( $chunk );

			foreach ( $chunk as $id ) {
				$user = get_userdata( $id );

				if ( ! $user instanceof WP_User ) {
					continue;
				}

				$plan = self::plan_user( (array) $user->roles, $role, $target, $mode );

				if ( 'skip' === $plan['op'] ) {
					continue;
				}

				if ( (int) $user->ID === $me ) {
					$out['self'] = true;
				}

				if ( true !== self::may_change_user( $user, $plan, $assignable, $role_caps ) ) {
					++$out['blocked'];
				} elseif ( 'remove' === $plan['op'] ) {
					++$out['remove'];
				} else {
					++$out['get'];
				}
			}

			self::release_users( array_diff( $chunk, array( $me ) ) );
		}

		return $out;
	}

	/**
	 * Call `$visit( int $user_id, string[] $roles )` for every user holding a
	 * role, lowest ID first, from their capabilities meta alone: one query
	 * per COUNT_PAGE users and no user object (SEC-9). `$roles` are the keys
	 * that name a role, in stored order, as WP_User::$roles lists them.
	 *
	 * @param string   $role  Role ID.
	 * @param callable $visit Callback.
	 * @return void
	 */
	private static function each_holder( $role, callable $visit ) {
		global $wpdb;

		$wp_roles = wp_roles();
		$key      = $wpdb->get_blog_prefix() . 'capabilities';
		$like     = '%' . $wpdb->esc_like( '"' . (string) $role . '"' ) . '%';
		$after    = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A dry run's paged read of one meta key (SEC-9); nothing to cache across requests.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.user_id, m.meta_value FROM {$wpdb->usermeta} m INNER JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE m.meta_key = %s AND m.meta_value LIKE %s AND m.user_id > %d ORDER BY m.user_id ASC LIMIT %d", $key, $like, $after, self::COUNT_PAGE ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			$full = count( $rows ) === self::COUNT_PAGE;

			foreach ( $rows as $row ) {
				$after = (int) $row['user_id'];
				$caps  = maybe_unserialize( $row['meta_value'] );

				if ( ! is_array( $caps ) ) {
					continue;
				}

				$roles = array_values( array_filter( array_map( 'strval', array_keys( $caps ) ), array( $wp_roles, 'is_role' ) ) );

				if ( in_array( (string) $role, $roles, true ) ) {
					call_user_func( $visit, $after, $roles );
				}
			}
		} while ( $full );
	}

	/**
	 * Whether a change would take `administrator` away from the person
	 * making it (SEC-12; see may_change_user()). Pure.
	 *
	 * @param bool     $is_self Whether the person changed is the actor.
	 * @param string[] $before  Their roles now.
	 * @param string[] $after   Their roles afterwards.
	 * @return bool
	 */
	public static function drops_own_admin( $is_self, array $before, array $after ) {
		return $is_self
			&& in_array( self::ADMIN_ROLE, array_map( 'strval', $before ), true )
			&& ! in_array( self::ADMIN_ROLE, array_map( 'strval', $after ), true );
	}

	/**
	 * Let go of users a dry-run page loaded (see reassign_counts()). Without
	 * a persistent object cache the entries live only in this request and
	 * are deleted; with one, deleting would empty the shared cache, so only
	 * this request's copy is dropped (wp_cache_flush_runtime()), when the
	 * cache supports that. The same technique as the bulk-rename template
	 * (tasks/lessons.md, Phase 3 review fixes).
	 *
	 * @param int[] $ids User IDs.
	 * @return void
	 */
	private static function release_users( array $ids ) {
		if ( array() === $ids ) {
			return;
		}

		if ( ! wp_using_ext_object_cache() ) {
			foreach ( $ids as $id ) {
				$data = wp_cache_get( $id, 'users' );

				// The lookups update_user_caches() adds next to the user.
				if ( is_object( $data ) ) {
					wp_cache_delete( (string) $data->user_login, 'userlogins' );
					wp_cache_delete( (string) $data->user_nicename, 'userslugs' );
					wp_cache_delete( (string) $data->user_email, 'useremail' );
				}

				wp_cache_delete( $id, 'users' );
				wp_cache_delete( $id, 'user_meta' );
			}

			return;
		}

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Whether the current user may make this change to a person's roles.
	 *
	 * Nobody, a toolkit admin included, takes `administrator` away from
	 * themselves here (SEC-12, SEC-13: the last administrator could
	 * otherwise move every administrator, themselves too, out of the role).
	 * Otherwise a toolkit admin may. Anyone else: never their own roles
	 * (SEC-5), not an administrator or someone with more capabilities
	 * (SEC-1, SEC-2, via `can_act_on_user()`), only with `promote_user` on
	 * them (core's rule for changing roles, which the Guard also maps), only
	 * giving a role they may assign (SEC-4), and never leaving the person
	 * with a capability the actor lacks (SEC-3, `can_change_user_roles()`:
	 * dropping a role that denies a capability gives it back).
	 *
	 * @param WP_User $user       Whose roles change.
	 * @param array   $plan       plan_user() result.
	 * @param bool    $assignable Whether the actor may assign the target (Guard::may_assign_role()).
	 * @param array   $role_caps  Role ID => capabilities for a role not stored yet (the dry run's NEW_ROLE).
	 * @return true|WP_Error
	 */
	private static function may_change_user( WP_User $user, array $plan, $assignable, array $role_caps = array() ) {
		$actor = wp_get_current_user();

		if ( ! $actor->exists() ) {
			return new WP_Error( 'blt_toolkit_no_actor', __( 'You need to be signed in to do that.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( 'blocked' === $plan['op'] ) {
			return new WP_Error( 'blt_toolkit_replacement_missing', __( 'This person has no other role, and no replacement was chosen.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		$roles_after = isset( $plan['roles'] ) ? array_map( 'strval', (array) $plan['roles'] ) : array();

		if ( self::drops_own_admin( (int) $actor->ID === (int) $user->ID, (array) $user->roles, $roles_after ) ) {
			return new WP_Error(
				'blt_toolkit_own_admin_role',
				__( 'You cannot take the Administrator role away from yourself here.', 'blt-toolkit' ),
				array(
					'status' => 403,
					'rule'   => 'SEC-12',
				)
			);
		}

		if ( BLT_Toolkit_Guard::is_toolkit_admin( $actor ) ) {
			return true;
		}

		if ( (int) $actor->ID === (int) $user->ID ) {
			return new WP_Error(
				'blt_toolkit_own_roles',
				__( 'You cannot change your own roles. Ask an administrator.', 'blt-toolkit' ),
				array(
					'status' => 403,
					'rule'   => 'SEC-5',
				)
			);
		}

		$allowed = BLT_Toolkit_Guard::can_act_on_user( $actor, $user );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! current_user_can( 'promote_user', $user->ID ) ) {
			return new WP_Error( 'blt_toolkit_cannot_promote', __( 'You do not have permission to change this person\'s roles.', 'blt-toolkit' ), array( 'status' => 403 ) );
		}

		if ( in_array( $plan['op'], array( 'set', 'swap' ), true ) && ! $assignable ) {
			return new WP_Error(
				'blt_toolkit_replacement_not_allowed',
				__( 'You cannot give people this role, because it has capabilities you do not have.', 'blt-toolkit' ),
				array(
					'status' => 403,
					'rule'   => 'SEC-4',
				)
			);
		}

		return BLT_Toolkit_Guard::can_change_user_roles( $actor, $user, $roles_after, $role_caps );
	}

	/**
	 * Apply a plan to a user with one write of their capabilities meta.
	 *
	 * `remove` and `set` use core's own methods, so their hooks fire as
	 * usual. `swap` writes the meta with the target in the role's place
	 * (core's add_role() would append it, changing which role's `false`
	 * wins), then fires core's `remove_user_role` and `add_user_role`.
	 *
	 * @param WP_User $user   User.
	 * @param string  $role   Role being emptied.
	 * @param string  $target Target role.
	 * @param array   $plan   plan_user() result.
	 * @return void
	 */
	private static function apply_plan( WP_User $user, $role, $target, array $plan ) {
		if ( 'remove' === $plan['op'] ) {
			$user->remove_role( $role );

			return;
		}

		if ( 'set' === $plan['op'] ) {
			$user->set_role( $target );

			return;
		}

		if ( 'swap' !== $plan['op'] ) {
			return;
		}

		$caps = array();

		foreach ( (array) $user->caps as $key => $value ) {
			if ( (string) $key === $role ) {
				$caps[ $target ] = true;
				continue;
			}

			$caps[ $key ] = $value;
		}

		$user->caps = $caps;

		update_user_meta( $user->ID, $user->cap_key, $caps );

		$user->get_role_caps();
		$user->update_user_level_from_caps();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hooks, fired as WP_User::remove_role() and add_role() fire them.
		/** This action is documented in wp-includes/class-wp-user.php */
		do_action( 'remove_user_role', $user->ID, $role );
		/** This action is documented in wp-includes/class-wp-user.php */
		do_action( 'add_user_role', $user->ID, $target );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Process one batch of a `role_reassign` job (RO-41). Safe to repeat:
	 * a user who no longer holds the role is skipped.
	 *
	 * Runs as the job's owner (JOB-3), so every person is checked against
	 * them (may_change_user()). Writes one aggregate log row for the batch
	 * with each person's roles before and after. With `then_delete`, the
	 * role is deleted as soon as nobody holds it, whichever batch empties it
	 * (a retried batch included).
	 *
	 * @param mixed $item   array( 'role', 'users', 'from', 'of' ).
	 * @param array $pinned `target`, `mode`, `then_delete`, `context`, `names`, `target_name`.
	 * @return true|string|WP_Error True, a warning, or the reason the batch failed (Retry failed runs it again).
	 */
	public static function process( $item, array $pinned ) {
		$item   = is_array( $item ) ? $item : array();
		$role   = isset( $item['role'] ) ? (string) $item['role'] : '';
		$ids    = isset( $item['users'] ) && is_array( $item['users'] ) ? array_map( 'intval', $item['users'] ) : array();
		$target = isset( $pinned['target'] ) ? (string) $pinned['target'] : '';
		$mode   = isset( $pinned['mode'] ) && self::MODE_MOVE === $pinned['mode'] ? self::MODE_MOVE : self::MODE_REPLACE;

		if ( '' === $role ) {
			return new WP_Error( 'blt_toolkit_job_item', __( 'The batch names no role.', 'blt-toolkit' ) );
		}

		// SEC-12, for a job stored before move_refusal() existed.
		$refusal = self::move_refusal( $role );

		if ( is_wp_error( $refusal ) ) {
			return $refusal;
		}

		if ( '' !== $target && ! wp_roles()->is_role( $target ) ) {
			return new WP_Error(
				'blt_toolkit_replacement_unknown',
				sprintf(
					/* translators: %s: role name or ID. */
					__( 'The role %s no longer exists, so nobody was moved to it.', 'blt-toolkit' ),
					isset( $pinned['target_name'] ) && '' !== (string) $pinned['target_name'] ? (string) $pinned['target_name'] : $target
				)
			);
		}

		$assignable = '' !== $target && BLT_Toolkit_Guard::may_assign_role( $target );
		$changed    = array();
		$skipped    = 0;
		$failed     = array();

		cache_users( $ids );

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );

			if ( ! $user instanceof WP_User ) {
				++$skipped;
				continue;
			}

			$plan = self::plan_user( (array) $user->roles, $role, $target, $mode );

			if ( 'skip' === $plan['op'] ) {
				++$skipped;
				continue;
			}

			$allowed = self::may_change_user( $user, $plan, $assignable );

			if ( is_wp_error( $allowed ) ) {
				$failed[] = array(
					'id'     => (int) $user->ID,
					'login'  => (string) $user->user_login,
					'code'   => $allowed->get_error_code(),
					'reason' => $allowed->get_error_message(),
				);
				continue;
			}

			$before = array_values( array_map( 'strval', (array) $user->roles ) );

			self::apply_plan( $user, $role, $target, $plan );

			$changed[] = array(
				'id'    => (int) $user->ID,
				'login' => (string) $user->user_login,
				'op'    => $plan['op'],
				'from'  => $before,
				'to'    => array_values( array_map( 'strval', (array) $user->roles ) ),
			);
		}

		if ( array() !== $changed || array() !== $failed ) {
			self::log_batch( $item, $pinned, $changed, $failed, $skipped );
		}

		$result = true;

		if ( ! empty( $pinned['then_delete'] ) && wp_roles()->is_role( $role ) ) {
			$left = self::holder_count( $role );

			if ( 0 === $left ) {
				$removed = self::remove_roles( array( $role ), $target, isset( $pinned['context'] ) ? (string) $pinned['context'] : 'delete' );

				if ( is_wp_error( $removed ) ) {
					$result = $removed;
				}
			} elseif ( array() === $failed && self::is_last_batch( $item ) && $left > 0 ) {
				$result = sprintf(
					/* translators: 1: number of people, 2: role name. */
					_n( '%1$s person was given the %2$s role while it was being emptied, so the role was kept. Delete it again to move them too.', '%1$s people were given the %2$s role while it was being emptied, so the role was kept. Delete it again to move them too.', $left, 'blt-toolkit' ),
					number_format_i18n( $left ),
					self::pinned_name( $pinned, $role )
				);
			}
		}

		if ( array() !== $failed ) {
			// No login here: the job panel is shown to people from whom SEC-6
			// hides administrators. The log row names them.
			return new WP_Error(
				'blt_toolkit_reassign_failed',
				sprintf(
					/* translators: 1: number of people, 2: why the first of them could not be changed. */
					_n( '%1$s person could not be changed and keeps the role. %2$s', '%1$s people could not be changed and keep the role. The first reason: %2$s', count( $failed ), 'blt-toolkit' ),
					number_format_i18n( count( $failed ) ),
					$failed[0]['reason']
				)
			);
		}

		return $result;
	}

	/**
	 * Whether an item is the last batch of its role.
	 *
	 * @param array $item Job item.
	 * @return bool
	 */
	private static function is_last_batch( array $item ) {
		$from  = isset( $item['from'] ) ? (int) $item['from'] : 1;
		$of    = isset( $item['of'] ) ? (int) $item['of'] : 0;
		$count = isset( $item['users'] ) && is_array( $item['users'] ) ? count( $item['users'] ) : 0;

		return $from + $count - 1 >= $of;
	}

	/**
	 * A role's name as pinned at start (it may be gone by now).
	 *
	 * @param array  $pinned Pinned values.
	 * @param string $role   Role ID.
	 * @return string
	 */
	private static function pinned_name( array $pinned, $role ) {
		if ( isset( $pinned['names'][ $role ] ) && is_scalar( $pinned['names'][ $role ] ) && '' !== (string) $pinned['names'][ $role ] ) {
			return (string) $pinned['names'][ $role ];
		}

		return self::role_name( $role );
	}

	/**
	 * Write the batch's one aggregate log row (RO-41).
	 *
	 * @param array $item    Job item.
	 * @param array $pinned  Pinned values.
	 * @param array $changed Each array( 'id', 'login', 'op', 'from', 'to' ).
	 * @param array $failed  Each array( 'id', 'login', 'code', 'reason' ).
	 * @param int   $skipped Users no longer holding the role, or gone.
	 * @return void
	 */
	private static function log_batch( array $item, array $pinned, array $changed, array $failed, $skipped ) {
		$role   = (string) $item['role'];
		$target = isset( $pinned['target'] ) ? (string) $pinned['target'] : '';
		$from   = isset( $item['from'] ) ? (int) $item['from'] : 1;
		$of     = isset( $item['of'] ) ? (int) $item['of'] : count( $item['users'] );
		$to     = $from + count( (array) $item['users'] ) - 1;
		$gained = 0;
		$lost   = 0;

		foreach ( $changed as $change ) {
			if ( 'remove' === $change['op'] ) {
				++$lost;
			} else {
				++$gained;
			}
		}

		$name        = self::pinned_name( $pinned, $role );
		$target_name = isset( $pinned['target_name'] ) && '' !== (string) $pinned['target_name'] ? (string) $pinned['target_name'] : self::role_name( $target );

		$summary = sprintf(
			/* translators: 1: role name, 2: first person's number in the batch, 3: last person's number, 4: how many hold the role in all. */
			__( 'Moved people out of the %1$s role (people %2$s to %3$s of %4$s):', 'blt-toolkit' ),
			$name,
			number_format_i18n( $from ),
			number_format_i18n( $to ),
			number_format_i18n( $of )
		);

		$parts = array();

		if ( $gained > 0 ) {
			/* translators: 1: number of people, 2: role name. */
			$parts[] = sprintf( _n( '%1$s now has %2$s instead', '%1$s now have %2$s instead', $gained, 'blt-toolkit' ), number_format_i18n( $gained ), $target_name );
		}

		if ( $lost > 0 ) {
			/* translators: %s: number of people. */
			$parts[] = sprintf( _n( '%s kept their other roles', '%s kept their other roles', $lost, 'blt-toolkit' ), number_format_i18n( $lost ) );
		}

		if ( array() !== $failed ) {
			/* translators: %s: number of people. */
			$parts[] = sprintf( _n( '%s could not be changed', '%s could not be changed', count( $failed ), 'blt-toolkit' ), number_format_i18n( count( $failed ) ) );
		}

		$status = 'success';

		if ( array() !== $failed ) {
			$status = array() === $changed ? 'failure' : 'warning';
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'user_roles_changed',
				'object_type' => 'role',
				'object_id'   => $role,
				'summary'     => $summary . ' ' . implode( wp_get_list_item_separator(), $parts ) . '.',
				'details'     => array(
					'role'    => $role,
					'target'  => $target,
					'mode'    => isset( $pinned['mode'] ) ? (string) $pinned['mode'] : self::MODE_REPLACE,
					'context' => isset( $pinned['context'] ) ? (string) $pinned['context'] : '',
					'batch'   => array(
						'from' => $from,
						'to'   => $to,
						'of'   => $of,
					),
					'changed' => $changed,
					'failed'  => $failed,
					'skipped' => (int) $skipped,
				),
				'status'      => $status,
			)
		);
	}

	/**
	 * The label of a job item: which role and which people (JOB-4).
	 *
	 * @param mixed $item Job item.
	 * @return string
	 */
	public static function describe( $item ) {
		if ( ! is_array( $item ) || ! isset( $item['role'] ) ) {
			return '';
		}

		$from = isset( $item['from'] ) ? (int) $item['from'] : 1;
		$of   = isset( $item['of'] ) ? (int) $item['of'] : 0;
		$to   = $from + ( isset( $item['users'] ) && is_array( $item['users'] ) ? count( $item['users'] ) : 0 ) - 1;

		return sprintf(
			/* translators: 1: role name, 2: first person's number, 3: last person's number, 4: total. */
			__( '%1$s, people %2$s to %3$s of %4$s', 'blt-toolkit' ),
			self::role_name( (string) $item['role'] ),
			number_format_i18n( $from ),
			number_format_i18n( $to ),
			number_format_i18n( $of )
		);
	}

	/**
	 * Who may follow, cancel and retry a reassign job (the type's
	 * `can_use`): the person who started it, or a toolkit admin. Its items
	 * and failures name the people being moved, and a retry runs as the
	 * owner (JOB-3).
	 *
	 * @param array   $job  Stored state.
	 * @param WP_User $user Who asks.
	 * @return bool
	 */
	public static function can_use( array $job, WP_User $user ) {
		if ( ! $user->exists() ) {
			return false;
		}

		$owner = isset( $job['owner'] ) ? (int) $job['owner'] : 0;

		return $owner === (int) $user->ID || BLT_Toolkit_Guard::is_toolkit_admin( $user );
	}

	/**
	 * Log who cancelled or retried a reassign job (the type's `audit`).
	 *
	 * @param string $verb `cancel` or `retry`.
	 * @param array  $job  The job after the change.
	 * @return void
	 */
	public static function audit( $verb, array $job ) {
		$pinned = isset( $job['pinned'] ) && is_array( $job['pinned'] ) ? $job['pinned'] : array();
		$roles  = isset( $pinned['roles'] ) && is_array( $pinned['roles'] ) ? array_map( 'strval', $pinned['roles'] ) : array();
		$names  = array();

		foreach ( $roles as $role ) {
			$names[] = self::pinned_name( $pinned, $role );
		}

		$list = implode( wp_get_list_item_separator(), $names );

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'cancel' === $verb ? 'reassign_cancelled' : 'reassign_retried',
				'object_type' => 'role',
				'object_id'   => implode( ',', $roles ),
				'summary'     => 'cancel' === $verb
					/* translators: %s: role names. */
					? sprintf( __( 'Cancelled moving people out of: %s. People already moved stay moved.', 'blt-toolkit' ), $list )
					/* translators: %s: role names. */
					: sprintf( __( 'Retried moving the people who could not be moved out of: %s.', 'blt-toolkit' ), $list ),
				'details'     => array(
					'job'   => isset( $job['id'] ) ? (string) $job['id'] : '',
					'roles' => $roles,
				),
			)
		);
	}

	// ---------- Helpers ----------

	/**
	 * A display name as stored: plain text, single line, trimmed.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		return trim( sanitize_text_field( (string) $name ) );
	}

	/**
	 * Copy a role's metadata (description, hidden toolbar; SPEC 9.5) to a
	 * new role, for RO-32: the people moved keep the same toolbar.
	 *
	 * @param string $from Old role ID.
	 * @param string $to   New role ID.
	 * @return void
	 */
	private static function copy_role_meta( $from, $to ) {
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );

		if ( ! is_array( $meta ) || ! isset( $meta[ $from ] ) || isset( $meta[ $to ] ) ) {
			return;
		}

		$meta[ $to ] = $meta[ $from ];

		BLT_Toolkit_Settings::set( 'roles', 'role_meta', $meta );
	}

	/**
	 * Drop the metadata of deleted roles.
	 *
	 * @param string[] $roles Role IDs.
	 * @return void
	 */
	private static function drop_role_meta( array $roles ) {
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );

		if ( ! is_array( $meta ) ) {
			return;
		}

		$left = array_diff_key( $meta, array_flip( $roles ) );

		if ( count( $left ) !== count( $meta ) ) {
			BLT_Toolkit_Settings::set( 'roles', 'role_meta', $left );
		}
	}

	/**
	 * Run a roles write through the role store (snapshot, Guard, one write,
	 * hooks, log).
	 *
	 * @param callable $mutate function( array $roles ): array|WP_Error.
	 * @param array    $args   BLT_Toolkit_Role_Store::transaction() arguments.
	 * @return array|WP_Error
	 */
	private static function transaction( callable $mutate, array $args ) {
		if ( ! class_exists( 'BLT_Toolkit_Role_Store' ) || ! is_callable( array( 'BLT_Toolkit_Role_Store', 'transaction' ) ) ) {
			return new WP_Error( 'blt_toolkit_store_missing', __( 'The role editor is not available, so no role was changed.', 'blt-toolkit' ), array( 'status' => 500 ) );
		}

		return BLT_Toolkit_Role_Store::transaction( $mutate, $args );
	}

	/**
	 * Merge the store's result keys (`snapshot_id`, `log_id`, `diff`) into a
	 * lifecycle result.
	 *
	 * @param array $result Store result.
	 * @param array $out    Lifecycle result.
	 * @return array
	 */
	private static function with_result( array $result, array $out ) {
		$out['snapshot_id'] = isset( $result['snapshot_id'] ) ? (int) $result['snapshot_id'] : 0;
		$out['log_id']      = isset( $result['log_id'] ) ? (int) $result['log_id'] : 0;
		$out['diff']        = isset( $result['diff'] ) && is_array( $result['diff'] ) ? $result['diff'] : array();

		if ( isset( $result['guard'] ) ) {
			$out['guard'] = $result['guard'];
		}

		return $out;
	}
}
