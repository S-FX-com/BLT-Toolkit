<?php
/**
 * Role export and import (RO-44, RO-45).
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves a set of roles from one site to another as a JSON file, so S-FX can
 * reuse a role blueprint across client sites (SPEC 9.4).
 *
 * - RO-44 export: the selected roles as
 *   `{ "format": "blt-toolkit-roles", "version": 1, "exported_at", "site",
 *   "roles": { id: { name, capabilities } }, "role_meta": {...},
 *   "isolated_post_types": {...} }`. `role_meta` holds the selected roles'
 *   descriptions and toolbar flags (SPEC 9.5); `isolated_post_types` the
 *   types with separate permissions (RS-30) whose capabilities a selected
 *   role mentions. Exporting writes nothing but a log row.
 * - RO-45 import: the file is checked (size, JSON, format, version, every
 *   role's shape), then previewed per role as New, Changed or Unchanged
 *   (Changed lists the capabilities added, removed and flipped), with
 *   capabilities that nothing on this site registers flagged "not registered
 *   here" (a content type or plugin missing here). Each role gets an action:
 *   Create (New), Merge capabilities or Replace capabilities (Changed), or
 *   Skip. The SEC rules apply as for any role write: a non-admin can import
 *   no role with capabilities they lack (SEC-3) and change no role they hold
 *   (SEC-5) or that exceeds them (SEC-2); Critical grants need the typed
 *   role name (SEC-10). Role details and separate permissions are imported
 *   only when chosen; a type that is not registered here is skipped with a
 *   note, and each one imported goes through the separate-permissions flow
 *   (BLT_Toolkit_Isolation::isolate(), its validation and seeding), so
 *   nobody's access changes. The roles are written once through
 *   BLT_Toolkit_Role_Store::transaction() with a `before_import` snapshot
 *   first (SNAP-1) and logged (`roles_imported`).
 *
 * The rules that need no WordPress (the document, its parsing, the
 * classification, the actions and the SEC prechecks, which call the
 * Guard's own pure rules) are unit-tested in tests/test-role-transfer.php.
 */
class BLT_Toolkit_Role_Transfer {

	/**
	 * The `format` of an export file (RO-44).
	 */
	const FORMAT = 'blt-toolkit-roles';

	/**
	 * The newest `version` this code reads and the one it writes.
	 */
	const VERSION = 1;

	/**
	 * Largest file accepted, in bytes. A role with every core capability is
	 * about 4 KB of JSON, so this holds far more roles than MAX_ROLES.
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Most roles one file may hold.
	 */
	const MAX_ROLES = 200;

	/**
	 * Most capabilities one role in a file may hold.
	 */
	const MAX_CAPS = 2000;

	/**
	 * Deepest nesting json_decode() accepts (a file needs five levels).
	 */
	const DEPTH = 16;

	/**
	 * Longest role ID a file may carry. Create also applies RO-30's rules
	 * (BLT_Toolkit_Role_Lifecycle::id_error(), 64 characters).
	 */
	const ID_MAX = 191;

	/**
	 * Longest description kept (the settings layer keeps 500, SPEC 9.5).
	 */
	const DESCRIPTION_MAX = 500;

	/**
	 * Role classifications (RO-45).
	 */
	const STATUS_NEW       = 'new';
	const STATUS_CHANGED   = 'changed';
	const STATUS_UNCHANGED = 'unchanged';
	const STATUS_INVALID   = 'invalid';

	/**
	 * Per-role actions (RO-45).
	 */
	const ACTION_CREATE  = 'create';
	const ACTION_MERGE   = 'merge';
	const ACTION_REPLACE = 'replace';
	const ACTION_SKIP    = 'skip';

	/**
	 * Snapshot reason of the import's roles write (SPEC 14.3).
	 */
	const REASON = 'before_import';

	/**
	 * Context passed to the role store's hooks and log.
	 */
	const CONTEXT = 'import';

	/**
	 * Transient prefix (plus the user ID) of an uploaded file waiting for
	 * its import, one per person, named by a random token.
	 */
	const PLAN_TRANSIENT = 'blt_toolkit_import_';

	/**
	 * How long an uploaded file is kept, in seconds (an hour).
	 */
	const PLAN_TTL = 3600;

	// ---------- Pure: the document (RO-44) ----------

	/**
	 * The export document for the selected roles. Pure.
	 *
	 * Capabilities are written as booleans, sorted by name. `role_meta`
	 * keeps the selected roles' entries; `isolated_post_types` keeps each
	 * type whose capabilities (or read-only marker) a selected role
	 * mentions, with its singular and plural.
	 *
	 * @param array  $roles       Roles array (ID => array( 'name', 'capabilities' )), in site order.
	 * @param array  $selected    Role IDs to export.
	 * @param array  $role_meta   `roles.role_meta`.
	 * @param array  $isolated    `roles.isolated_post_types`.
	 * @param string $site        The site's address.
	 * @param string $exported_at When, as an ISO 8601 UTC date.
	 * @return array
	 */
	public static function document( array $roles, array $selected, array $role_meta, array $isolated, $site, $exported_at ) {
		$want     = array_fill_keys( array_map( 'strval', array_filter( $selected, 'is_scalar' ) ), true );
		$out      = array();
		$mentions = array();

		foreach ( $roles as $id => $role ) {
			$id = (string) $id;

			if ( ! isset( $want[ $id ] ) || ! is_array( $role ) ) {
				continue;
			}

			$caps = self::bools( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array() );

			unset( $caps[''] );

			$out[ $id ] = array(
				'name'         => isset( $role['name'] ) && is_scalar( $role['name'] ) ? (string) $role['name'] : $id,
				'capabilities' => $caps,
			);

			$mentions += array_fill_keys( array_keys( $caps ), true );
		}

		$meta = array();

		foreach ( array_keys( $out ) as $id ) {
			if ( isset( $role_meta[ $id ] ) && is_array( $role_meta[ $id ] ) ) {
				$meta[ $id ] = array(
					'description'  => isset( $role_meta[ $id ]['description'] ) && is_scalar( $role_meta[ $id ]['description'] ) ? (string) $role_meta[ $id ]['description'] : '',
					'hide_toolbar' => ! empty( $role_meta[ $id ]['hide_toolbar'] ),
				);
			}
		}

		$types = array();

		foreach ( $isolated as $type => $info ) {
			if ( ! is_array( $info ) || ! isset( $info['singular'], $info['plural'] ) || ! is_scalar( $info['singular'] ) || ! is_scalar( $info['plural'] ) ) {
				continue;
			}

			$strings = self::isolation_strings( (string) $info['singular'], (string) $info['plural'] );

			if ( array() === array_intersect_key( $mentions, array_fill_keys( $strings, true ) ) ) {
				continue;
			}

			$types[ (string) $type ] = array(
				'singular' => (string) $info['singular'],
				'plural'   => (string) $info['plural'],
			);
		}

		ksort( $types, SORT_STRING );

		return array(
			'format'              => self::FORMAT,
			'version'             => self::VERSION,
			'exported_at'         => (string) $exported_at,
			'site'                => (string) $site,
			'roles'               => $out,
			'role_meta'           => $meta,
			'isolated_post_types' => $types,
		);
	}

	/**
	 * The document as the file's text: pretty-printed JSON whose every
	 * container is an object (the format has no lists), so an empty
	 * `capabilities` reads `{}`, not `[]`. Pure.
	 *
	 * @param array $doc document().
	 * @return string '' when it cannot be encoded.
	 */
	public static function encode( array $doc ) {
		$json = json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_FORCE_OBJECT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class; JSON_FORCE_OBJECT keeps the format's objects.

		return false === $json ? '' : $json . "\n";
	}

	/**
	 * The download's file name: `blt-toolkit-roles-{site}-{date}.json`,
	 * the site's host (and path) as lowercase letters, digits and hyphens.
	 * Pure.
	 *
	 * @param string $site Host and path, such as `example.com/blog`.
	 * @param string $date `Y-m-d`.
	 * @return string
	 */
	public static function filename( $site, $date ) {
		$slug = strtolower( (string) $site );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( substr( trim( $slug, '-' ), 0, 60 ), '-' );
		$date = (string) $date;

		if ( '' === $slug ) {
			$slug = 'site';
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = 'undated';
		}

		return 'blt-toolkit-roles-' . $slug . '-' . $date . '.json';
	}

	/**
	 * Every capability string separate permissions with this singular and
	 * plural use: the cap object WordPress builds (RS-31) without `read`,
	 * plus the read-only marker. Pure.
	 *
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @return string[]
	 */
	public static function isolation_strings( $singular, $plural ) {
		$out = array();

		foreach ( BLT_Toolkit_Isolation_Core::caps_from_args( BLT_Toolkit_Isolation_Core::isolation_args( $singular, $plural ) ) as $key => $string ) {
			if ( 'read' !== $key && '' !== (string) $string ) {
				$out[ (string) $string ] = true;
			}
		}

		$out[ BLT_Toolkit_Isolation_Core::MARKER_PREFIX . $plural ] = true;

		$out = array_keys( $out );

		sort( $out, SORT_STRING );

		return array_map( 'strval', $out );
	}

	// ---------- Pure: reading a file (RO-45) ----------

	/**
	 * Check and normalize an uploaded file. Pure.
	 *
	 * The whole file is refused when it is empty, too large, not JSON, not
	 * an object, not this format, of a version this code does not read, or
	 * lists no roles (or too many). A role whose own entry is malformed is
	 * kept with an `error` code (`id`, `name`, `caps`, `too_many_caps`) and
	 * can only be skipped. Role details for roles not in the file, and
	 * malformed details, are left out. Each separate-permissions entry
	 * carries an `error` code from BLT_Toolkit_Isolation_Core::entry_error()
	 * (or `type` for a key no post type can have), '' when it is usable.
	 *
	 * @param string $bytes The file's contents.
	 * @return array|WP_Error array( 'format', 'version', 'exported_at', 'site',
	 *                        'roles' => ID => array( 'name', 'capabilities' => cap => bool, 'error' ),
	 *                        'role_meta' => ID => array( 'description', 'hide_toolbar' ),
	 *                        'isolated_post_types' => type => array( 'singular', 'plural', 'error' ) ),
	 *                        or a WP_Error `blt_toolkit_import_empty`, `_too_large`, `_json`, `_shape`,
	 *                        `_format`, `_version`, `_roles` or `_too_many`.
	 */
	public static function parse( $bytes ) {
		$bytes = is_string( $bytes ) ? $bytes : '';

		if ( "\xEF\xBB\xBF" === substr( $bytes, 0, 3 ) ) {
			$bytes = substr( $bytes, 3 );
		}

		if ( '' === trim( $bytes ) ) {
			return new WP_Error( 'blt_toolkit_import_empty', __( 'The file is empty.', 'blt-toolkit' ) );
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error( 'blt_toolkit_import_too_large', __( 'The file is too large to be a BLT Toolkit roles export.', 'blt-toolkit' ) );
		}

		$data = json_decode( $bytes, false, self::DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'blt_toolkit_import_json', self::json_error_message( json_last_error() ) );
		}

		if ( ! is_object( $data ) ) {
			return new WP_Error( 'blt_toolkit_import_shape', __( 'This file is JSON, but not a BLT Toolkit roles export: it should hold one object with a format, a version and the roles.', 'blt-toolkit' ) );
		}

		$vars   = get_object_vars( $data );
		$format = isset( $vars['format'] ) ? $vars['format'] : null;

		if ( self::FORMAT !== $format ) {
			return new WP_Error(
				'blt_toolkit_import_format',
				is_string( $format )
					/* translators: 1: the format the file names, 2: the expected format, blt-toolkit-roles. */
					? sprintf( __( 'This is not a BLT Toolkit roles export: its format is "%1$s", not "%2$s".', 'blt-toolkit' ), self::clip( $format, 60 ), self::FORMAT )
					/* translators: %s: the expected format, blt-toolkit-roles. */
					: sprintf( __( 'This is not a BLT Toolkit roles export: it has no "format" of "%s".', 'blt-toolkit' ), self::FORMAT )
			);
		}

		$version = isset( $vars['version'] ) ? $vars['version'] : null;

		if ( ! is_int( $version ) || $version < 1 ) {
			return new WP_Error( 'blt_toolkit_import_version', __( 'This file has no valid format version, so it cannot be read safely.', 'blt-toolkit' ) );
		}

		if ( $version > self::VERSION ) {
			return new WP_Error(
				'blt_toolkit_import_version',
				sprintf(
					/* translators: 1: the file's format version, 2: the version this site reads. */
					__( 'This file uses format version %1$d, made by a newer BLT Toolkit; this site reads version %2$d. Update BLT Toolkit here, then import it again.', 'blt-toolkit' ),
					$version,
					self::VERSION
				)
			);
		}

		$roles = isset( $vars['roles'] ) ? $vars['roles'] : null;

		if ( ! is_object( $roles ) ) {
			return new WP_Error( 'blt_toolkit_import_roles', __( 'The file has no list of roles.', 'blt-toolkit' ) );
		}

		$roles = get_object_vars( $roles );

		if ( array() === $roles ) {
			return new WP_Error( 'blt_toolkit_import_roles', __( 'The file lists no roles.', 'blt-toolkit' ) );
		}

		if ( count( $roles ) > self::MAX_ROLES ) {
			return new WP_Error(
				'blt_toolkit_import_too_many',
				sprintf(
					/* translators: 1: number of roles in the file, 2: the most one file may hold. */
					__( 'The file lists %1$d roles; one file may hold at most %2$d.', 'blt-toolkit' ),
					count( $roles ),
					self::MAX_ROLES
				)
			);
		}

		$out = array(
			'format'              => self::FORMAT,
			'version'             => $version,
			'exported_at'         => isset( $vars['exported_at'] ) && is_string( $vars['exported_at'] ) ? self::clip( $vars['exported_at'], 64 ) : '',
			'site'                => isset( $vars['site'] ) && is_string( $vars['site'] ) ? self::clip( $vars['site'], 200 ) : '',
			'roles'               => array(),
			'role_meta'           => array(),
			'isolated_post_types' => array(),
		);

		foreach ( $roles as $id => $role ) {
			$out['roles'][ (string) $id ] = self::parse_role( (string) $id, $role );
		}

		$meta = isset( $vars['role_meta'] ) && is_object( $vars['role_meta'] ) ? get_object_vars( $vars['role_meta'] ) : array();

		foreach ( $meta as $id => $entry ) {
			$id = (string) $id;

			if ( ! isset( $out['roles'][ $id ] ) || '' !== $out['roles'][ $id ]['error'] || ! is_object( $entry ) ) {
				continue;
			}

			$entry       = get_object_vars( $entry );
			$description = isset( $entry['description'] ) && is_string( $entry['description'] ) ? self::clip( sanitize_text_field( $entry['description'] ), self::DESCRIPTION_MAX ) : '';
			$toolbar     = isset( $entry['hide_toolbar'] ) && ( true === $entry['hide_toolbar'] || 1 === $entry['hide_toolbar'] );

			$out['role_meta'][ $id ] = array(
				'description'  => $description,
				'hide_toolbar' => $toolbar,
			);
		}

		$types = isset( $vars['isolated_post_types'] ) && is_object( $vars['isolated_post_types'] ) ? get_object_vars( $vars['isolated_post_types'] ) : array();

		foreach ( $types as $type => $entry ) {
			$type  = (string) $type;
			$entry = is_object( $entry ) ? get_object_vars( $entry ) : array();
			$info  = array(
				'singular' => isset( $entry['singular'] ) && is_string( $entry['singular'] ) ? self::clip( $entry['singular'], 100 ) : '',
				'plural'   => isset( $entry['plural'] ) && is_string( $entry['plural'] ) ? self::clip( $entry['plural'], 100 ) : '',
			);

			// Post type keys: at most 20 lowercase characters (register_post_type()).
			$key = 1 === preg_match( '/^[a-z0-9_-]{1,20}$/', $type ) ? $type : '';

			if ( '' === $key ) {
				$info['error'] = 'type';
				$key           = self::clip( $type, 40 );
			} else {
				$info['error'] = BLT_Toolkit_Isolation_Core::entry_error( $key, $info );
			}

			if ( '' !== $key && ! isset( $out['isolated_post_types'][ $key ] ) ) {
				$out['isolated_post_types'][ $key ] = $info;
			}
		}

		ksort( $out['isolated_post_types'], SORT_STRING );

		return $out;
	}

	/**
	 * One role of a file, checked. Pure.
	 *
	 * @param string $id   Role ID as written in the file.
	 * @param mixed  $role Its decoded entry.
	 * @return array{name:string,capabilities:array,error:string}
	 */
	private static function parse_role( $id, $role ) {
		$out = array(
			'name'         => '',
			'capabilities' => array(),
			'error'        => '',
		);

		if ( '' === $id || strlen( $id ) > self::ID_MAX || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $id ) || 1 !== preg_match( '/[A-Za-z]/', $id ) ) {
			$out['error'] = 'id';

			return $out;
		}

		$vars = is_object( $role ) ? get_object_vars( $role ) : null;
		$name = is_array( $vars ) && isset( $vars['name'] ) && is_string( $vars['name'] ) ? trim( sanitize_text_field( $vars['name'] ) ) : '';

		if ( '' === $name ) {
			$out['error'] = 'name';

			return $out;
		}

		$out['name'] = self::clip( $name, 200 );

		$caps = isset( $vars['capabilities'] ) ? $vars['capabilities'] : null;

		// An empty object can arrive as `[]` from encoders without objects.
		if ( array() === $caps ) {
			return $out;
		}

		if ( ! is_object( $caps ) ) {
			$out['error'] = 'caps';

			return $out;
		}

		$caps = get_object_vars( $caps );

		if ( count( $caps ) > self::MAX_CAPS ) {
			$out['error'] = 'too_many_caps';

			return $out;
		}

		$clean = array();

		foreach ( $caps as $cap => $value ) {
			$cap = (string) $cap;

			if ( true === $value || 1 === $value ) {
				$value = true;
			} elseif ( false === $value || 0 === $value ) {
				$value = false;
			} else {
				$out['error'] = 'caps';

				return $out;
			}

			// `do_not_allow` and `exist` are never capabilities a role holds:
			// core drops the first and forces the second in
			// WP_User::has_cap(), and RO-23 refuses both as names.
			if ( 'do_not_allow' === $cap || 'exist' === $cap ) {
				continue;
			}

			if ( '' === $cap || strlen( $cap ) > BLT_Toolkit_Role_Store::NAME_MAX || 1 === preg_match( '/[\x00-\x1F\x7F]/', $cap ) ) {
				$out['error'] = 'caps';

				return $out;
			}

			$clean[ $cap ] = $value;
		}

		ksort( $clean, SORT_STRING );

		$out['capabilities'] = $clean;

		return $out;
	}

	/**
	 * A readable message for a json_last_error() code.
	 *
	 * @param int $code JSON error code.
	 * @return string
	 */
	public static function json_error_message( $code ) {
		switch ( (int) $code ) {
			case JSON_ERROR_SYNTAX:
			case JSON_ERROR_STATE_MISMATCH:
				return __( 'The file is not valid JSON. It may have been cut short or edited by hand: look for a missing comma, quote or bracket, or export it again.', 'blt-toolkit' );
			case JSON_ERROR_UTF8:
			case JSON_ERROR_UTF16:
				return __( 'The file is not valid JSON: it contains characters that are not UTF-8. Save it as UTF-8, or export it again.', 'blt-toolkit' );
			case JSON_ERROR_CTRL_CHAR:
				return __( 'The file is not valid JSON: it contains a control character where text was expected. Export it again.', 'blt-toolkit' );
			case JSON_ERROR_DEPTH:
				return __( 'The file is not a BLT Toolkit roles export: it is nested far more deeply than one.', 'blt-toolkit' );
		}

		return __( 'The file is not valid JSON. Export it again.', 'blt-toolkit' );
	}

	/**
	 * The message for a role's parse error code.
	 *
	 * @param string $code `id`, `name`, `caps`, `too_many_caps` or `cap_names`.
	 * @return string
	 */
	public static function role_error_message( $code ) {
		switch ( (string) $code ) {
			case 'id':
				return __( 'Its role ID is not valid: letters, digits, underscores and hyphens only, with at least one letter.', 'blt-toolkit' );
			case 'cap_names':
				return __( 'Some of its capability names cannot be given to a role. Edit the file to remove them, or export the role again from a site where it has none of them.', 'blt-toolkit' );
			case 'name':
				return __( 'It has no display name.', 'blt-toolkit' );
			case 'too_many_caps':
				/* translators: %d: the most capabilities one role may have in a file. */
				return sprintf( __( 'It lists more than %d capabilities.', 'blt-toolkit' ), self::MAX_CAPS );
		}

		return __( 'Its capabilities are not a list of names set to true or false.', 'blt-toolkit' );
	}

	/**
	 * Capability names an import may never write to a role, for every
	 * actor, toolkit admins included (RO-23, SEC-3, SEC-8). Pure.
	 *
	 * - `role`: the ID of a role here or in the file. A role's ID among its
	 *   capabilities makes every holder pass `current_user_can( 'that_role' )`,
	 *   which plugins use as a membership test, and SEC-8 ignores such keys,
	 *   so the Guard would never see the power it gives.
	 * - `meta`: a meta capability name (`edit_post`, `edit_user`, BLT
	 *   Toolkit's own) that no role here holds: WordPress maps it for one
	 *   item at a time, so granting it as a primitive does nothing useful
	 *   (RO-23). One a role here already holds (WooCommerce gives
	 *   `edit_product` to its roles) is accepted.
	 * - `name`: a name no role or user here holds that breaks RO-23's rule
	 *   (lowercase letters, digits, underscores and hyphens, a letter, at
	 *   most 191 characters), so a file cannot add a capability the Add
	 *   capability form would refuse.
	 *
	 * @param array $caps       Capability names, or cap => value.
	 * @param array $role_ids   Role IDs here and in the file (ID => true).
	 * @param array $present    Capabilities on any role or user here, or
	 *                          registered (cap => true).
	 * @param array $meta_names Meta capability names (cap => true).
	 * @return array<string,string> Capability => `role`, `meta` or `name`, sorted.
	 */
	public static function cap_name_problems( array $caps, array $role_ids, array $present, array $meta_names ) {
		$out = array();

		foreach ( $caps as $key => $value ) {
			$cap = is_int( $key ) ? ( is_scalar( $value ) ? (string) $value : '' ) : (string) $key;

			if ( '' === $cap ) {
				continue;
			}

			if ( isset( $role_ids[ $cap ] ) ) {
				$out[ $cap ] = 'role';
			} elseif ( isset( $present[ $cap ] ) ) {
				continue;
			} elseif ( isset( $meta_names[ $cap ] ) ) {
				$out[ $cap ] = 'meta';
			} elseif ( '' !== BLT_Toolkit_Capability_Catalog::name_error( $cap, BLT_Toolkit_Role_Store::NAME_MAX ) ) {
				$out[ $cap ] = 'name';
			}
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Why a capability name cannot be imported, for cap_name_problems()'s
	 * codes.
	 *
	 * @param string $code `role`, `meta` or `name`.
	 * @return string
	 */
	public static function cap_name_message( $code ) {
		switch ( (string) $code ) {
			case 'role':
				return __( 'a role\'s ID: as a capability it would make everyone with this role count as having that role wherever a plugin checks it', 'blt-toolkit' );
			case 'meta':
				return __( 'a meta capability, which WordPress checks for one item at a time, so granting it to a role does nothing useful', 'blt-toolkit' );
		}

		return __( 'not a valid name for a new capability: lowercase letters, digits, underscores and hyphens only, with at least one letter', 'blt-toolkit' );
	}

	// ---------- Pure: the preview (RO-45) ----------

	/**
	 * Classify each role of a file against this site's roles. Pure.
	 *
	 * - `invalid`: the role's entry is malformed (parse()).
	 * - `new`: no role here has this exact ID.
	 * - `unchanged`: a role here has this ID and the same capabilities,
	 *   each read as granted or denied (the display name does not count;
	 *   no action changes it).
	 * - `changed`: otherwise, with the capabilities the file adds, removes
	 *   and flips compared with the role here (BLT_Toolkit_Role_Diff).
	 *
	 * @param array $file_roles parse()'s `roles`.
	 * @param array $site_roles This site's roles array.
	 * @return array<string,array{status:string,name:string,site_name:string,caps:array,site_caps:array|null,added:array,removed:array,flipped:array,error:string}>
	 */
	public static function classify( array $file_roles, array $site_roles ) {
		$out = array();

		foreach ( $file_roles as $id => $role ) {
			$id   = (string) $id;
			$role = is_array( $role ) ? $role : array();
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? self::bools( $role['capabilities'] ) : array();
			$row  = array(
				'status'    => self::STATUS_NEW,
				'name'      => isset( $role['name'] ) ? (string) $role['name'] : '',
				'site_name' => '',
				'caps'      => $caps,
				'site_caps' => null,
				'added'     => array(),
				'removed'   => array(),
				'flipped'   => array(),
				'error'     => isset( $role['error'] ) ? (string) $role['error'] : '',
			);

			if ( '' !== $row['error'] ) {
				$row['status'] = self::STATUS_INVALID;
				$row['caps']   = array();
				$out[ $id ]    = $row;
				continue;
			}

			if ( ! isset( $site_roles[ $id ] ) || ! is_array( $site_roles[ $id ] ) ) {
				$row['added'] = $caps;
				$out[ $id ]   = $row;
				continue;
			}

			$site = self::bools( isset( $site_roles[ $id ]['capabilities'] ) && is_array( $site_roles[ $id ]['capabilities'] ) ? $site_roles[ $id ]['capabilities'] : array() );
			$diff = self::cap_diff( $site, $caps );

			$row['site_name'] = isset( $site_roles[ $id ]['name'] ) && is_scalar( $site_roles[ $id ]['name'] ) ? (string) $site_roles[ $id ]['name'] : $id;
			$row['site_caps'] = $site;
			$row['added']     = $diff['added'];
			$row['removed']   = $diff['removed'];
			$row['flipped']   = $diff['flipped'];
			$row['status']    = array() === $diff['added'] && array() === $diff['removed'] && array() === $diff['flipped'] ? self::STATUS_UNCHANGED : self::STATUS_CHANGED;

			$out[ $id ] = $row;
		}

		return $out;
	}

	/**
	 * The actions offered for a classification, in display order. Pure.
	 *
	 * @param string $status Classification.
	 * @return string[]
	 */
	public static function actions_for( $status ) {
		switch ( (string) $status ) {
			case self::STATUS_NEW:
				return array( self::ACTION_CREATE, self::ACTION_SKIP );
			case self::STATUS_CHANGED:
				return array( self::ACTION_MERGE, self::ACTION_REPLACE, self::ACTION_SKIP );
		}

		return array( self::ACTION_SKIP );
	}

	/**
	 * The action preselected for a classification: Create for a new role,
	 * Skip otherwise, so a role that exists here changes only when someone
	 * picks Merge or Replace. Pure.
	 *
	 * @param string $status Classification.
	 * @return string
	 */
	public static function default_action( $status ) {
		return self::STATUS_NEW === (string) $status ? self::ACTION_CREATE : self::ACTION_SKIP;
	}

	/**
	 * The capabilities a role ends up with. Pure.
	 *
	 * - Create and Replace: exactly the file's.
	 * - Merge: the role's own, plus every capability the file grants
	 *   (a deny here becomes a grant), plus the file's denies of
	 *   capabilities the role does not mention. Nothing the role grants is
	 *   taken away.
	 * - Skip: null.
	 *
	 * @param array|null $site_caps The role here (cap => value), or null.
	 * @param array      $file_caps The file's (cap => bool).
	 * @param string     $action    Action.
	 * @return array<string,bool>|null
	 */
	public static function target_caps( $site_caps, array $file_caps, $action ) {
		$file = self::bools( $file_caps );

		switch ( (string) $action ) {
			case self::ACTION_CREATE:
			case self::ACTION_REPLACE:
				return $file;

			case self::ACTION_MERGE:
				$caps = self::bools( is_array( $site_caps ) ? $site_caps : array() );

				foreach ( $file as $cap => $grant ) {
					if ( $grant ) {
						$caps[ $cap ] = true;
					} elseif ( ! array_key_exists( $cap, $caps ) ) {
						$caps[ $cap ] = false;
					}
				}

				ksort( $caps, SORT_STRING );

				return $caps;
		}

		return null;
	}

	/**
	 * The change an action makes to a role, in the role store's terms:
	 * `add` (cap => new value, flips included) and `remove` (names). Pure.
	 *
	 * @param array|null $site_caps The role here, or null for a new one.
	 * @param array      $file_caps The file's capabilities.
	 * @param string     $action    Action.
	 * @return array{add:array<string,bool>,remove:string[]}
	 */
	public static function change_for( $site_caps, array $file_caps, $action ) {
		$after = self::target_caps( $site_caps, $file_caps, $action );
		$none  = array(
			'add'    => array(),
			'remove' => array(),
		);

		if ( null === $after ) {
			return $none;
		}

		$diff = BLT_Toolkit_Role_Diff::diff(
			is_array( $site_caps ) ? array(
				'r' => array(
					'name'         => '',
					'capabilities' => $site_caps,
				),
			) : array(),
			array(
				'r' => array(
					'name'         => '',
					'capabilities' => $after,
				),
			)
		);

		$changes = BLT_Toolkit_Role_Store::changes_from_diff( $diff );

		return isset( $changes['r'] ) ? array(
			'add'    => $changes['r']['add'],
			'remove' => $changes['r']['remove'],
		) : $none;
	}

	/**
	 * Apply the chosen actions to a roles array (the role store's mutator).
	 * Pure.
	 *
	 * Create adds the role with the file's display name and capabilities,
	 * refused when a role with this ID exists (compared without case, as
	 * RO-30 does). Merge and Replace change only the capabilities of the
	 * role here, refused when it no longer exists. Skip changes nothing.
	 *
	 * @param array $roles Roles array, as stored now.
	 * @param array $plan  Role ID => array( 'action', 'name', 'caps' ).
	 * @return array|WP_Error `blt_toolkit_role_exists` or `blt_toolkit_role_not_found` (data `role`).
	 */
	public static function apply( array $roles, array $plan ) {
		foreach ( $plan as $id => $item ) {
			$id     = (string) $id;
			$action = isset( $item['action'] ) ? (string) $item['action'] : self::ACTION_SKIP;
			$caps   = isset( $item['caps'] ) && is_array( $item['caps'] ) ? $item['caps'] : array();

			if ( self::ACTION_CREATE === $action ) {
				foreach ( array_keys( $roles ) as $existing ) {
					if ( strtolower( (string) $existing ) === strtolower( $id ) ) {
						return new WP_Error(
							'blt_toolkit_role_exists',
							/* translators: %s: role ID. */
							sprintf( __( 'A role with the ID %s exists on this site now, so it was not created.', 'blt-toolkit' ), $id ),
							array(
								'status' => 409,
								'role'   => $id,
							)
						);
					}
				}

				$roles[ $id ] = array(
					'name'         => isset( $item['name'] ) ? (string) $item['name'] : $id,
					'capabilities' => self::target_caps( null, $caps, $action ),
				);

				continue;
			}

			if ( self::ACTION_MERGE !== $action && self::ACTION_REPLACE !== $action ) {
				continue;
			}

			if ( ! isset( $roles[ $id ] ) || ! is_array( $roles[ $id ] ) ) {
				return new WP_Error(
					'blt_toolkit_role_not_found',
					/* translators: %s: role ID. */
					sprintf( __( 'The %s role no longer exists on this site.', 'blt-toolkit' ), $id ),
					array(
						'status' => 404,
						'role'   => $id,
					)
				);
			}

			$current = isset( $roles[ $id ]['capabilities'] ) && is_array( $roles[ $id ]['capabilities'] ) ? $roles[ $id ]['capabilities'] : array();

			$roles[ $id ]['capabilities'] = self::target_caps( $current, $caps, $action );
		}

		return $roles;
	}

	/**
	 * Capabilities nothing on this site registers ("not registered here"):
	 * those of the list missing from the registered set. Pure.
	 *
	 * @param array $caps       Capability names, or cap => value.
	 * @param array $registered Registered capability => true.
	 * @return string[] Sorted.
	 */
	public static function unregistered( array $caps, array $registered ) {
		$out = array();

		foreach ( $caps as $key => $value ) {
			$cap = is_int( $key ) ? ( is_scalar( $value ) ? (string) $value : '' ) : (string) $key;

			if ( '' !== $cap && ! isset( $registered[ $cap ] ) ) {
				$out[ $cap ] = true;
			}
		}

		$out = array_map( 'strval', array_keys( $out ) );

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * Why the actor may not take an action on a role (SEC-1, SEC-2, SEC-3,
	 * SEC-5, SEC-12), or null when the Guard's rules allow it. Pure: the
	 * same rules the role store applies at write time
	 * (BLT_Toolkit_Guard::grant_violation() for a role created,
	 * role_change_violation() for one changed), from plain facts.
	 *
	 * A non-admin cannot create `administrator` (SEC-1), cannot create a
	 * role with a capability they lack, nor deny one (SEC-3), and cannot
	 * merge into or replace `administrator` (SEC-1), a role they hold
	 * (SEC-5) or a role with capabilities they lack (SEC-2), or grant,
	 * deny or lift a deny of a capability they lack (SEC-3). Nobody can
	 * take the lockout-critical set from `administrator` (SEC-12). SEC-13
	 * (self-lockout) and SEC-1's "denies in a role an administrator holds"
	 * need queries; the role store checks them when it writes.
	 *
	 * @param array $facts {
	 *     @type string     $action           Action.
	 *     @type string     $role             Role ID.
	 *     @type array|null $site_caps        The role here (cap => value), null when new.
	 *     @type array      $file_caps        The file's capabilities.
	 *     @type bool       $actor_is_admin   Whether the actor is a toolkit admin.
	 *     @type string[]   $actor_roles      The actor's roles.
	 *     @type array      $actor_caps       The actor's effective capabilities (cap => true).
	 *     @type string[]   $role_names       The site's role IDs.
	 *     @type array      $restrictions     BLT_Toolkit_Guard::restrictions().
	 *     @type string[]   $lockout          The lockout-critical set.
	 *     @type bool       $admin_holds_role Optional: whether a toolkit admin holds the role.
	 * }
	 * @return array|null array( 'code', 'rule', 'caps' ) or null.
	 */
	public static function refusal( array $facts ) {
		$action   = isset( $facts['action'] ) ? (string) $facts['action'] : self::ACTION_SKIP;
		$role     = isset( $facts['role'] ) ? (string) $facts['role'] : '';
		$site     = isset( $facts['site_caps'] ) && is_array( $facts['site_caps'] ) ? $facts['site_caps'] : null;
		$file     = isset( $facts['file_caps'] ) && is_array( $facts['file_caps'] ) ? $facts['file_caps'] : array();
		$is_admin = ! empty( $facts['actor_is_admin'] );
		$names    = isset( $facts['role_names'] ) ? (array) $facts['role_names'] : array();
		$restrict = isset( $facts['restrictions'] ) ? (array) $facts['restrictions'] : array();

		if ( self::ACTION_SKIP === $action || ! in_array( $action, array( self::ACTION_CREATE, self::ACTION_MERGE, self::ACTION_REPLACE ), true ) ) {
			return null;
		}

		if ( self::ACTION_CREATE === $action ) {
			if ( ! $is_admin && BLT_Toolkit_Guard::is_protected_role( $role ) ) {
				return array(
					'code'        => 'blt_toolkit_protected_role',
					'rule'        => 'SEC-1',
					'caps'        => array(),
					'confirmable' => false,
				);
			}

			$split = BLT_Toolkit_Guard::cap_changes( (array) self::target_caps( null, $file, $action ), array() );

			return BLT_Toolkit_Guard::grant_violation(
				array(
					'actor_is_admin' => $is_admin,
					'actor_caps'     => isset( $facts['actor_caps'] ) ? (array) $facts['actor_caps'] : array(),
					'grants'         => $split['grants'],
					'denies'         => $split['denies'],
					'role_names'     => $names,
					'restrictions'   => $restrict,
				)
			);
		}

		if ( null === $site ) {
			return null;
		}

		// Checked even when the action would change nothing, as Roles > Role
		// details checks a role: a non-admin never imports into
		// Administrator, a role they hold or one that exceeds them.
		$change = self::change_for( $site, $file, $action );
		$split  = BLT_Toolkit_Guard::cap_changes( $change['add'], $change['remove'] );
		$denied = array();

		foreach ( $site as $cap => $value ) {
			if ( empty( $value ) ) {
				$denied[] = (string) $cap;
			}
		}

		return BLT_Toolkit_Guard::role_change_violation(
			array(
				'actor_is_admin'   => $is_admin,
				'actor_roles'      => isset( $facts['actor_roles'] ) ? (array) $facts['actor_roles'] : array(),
				'actor_caps'       => isset( $facts['actor_caps'] ) ? (array) $facts['actor_caps'] : array(),
				'role'             => $role,
				'role_caps'        => BLT_Toolkit_Superset::normalize( $site, $names ),
				'role_denied'      => $denied,
				'admin_holds_role' => ! empty( $facts['admin_holds_role'] ),
				'grants'           => $split['grants'],
				'denies'           => $split['denies'],
				'removes'          => $split['removes'],
				'lockout'          => isset( $facts['lockout'] ) ? (array) $facts['lockout'] : array(),
				'role_names'       => $names,
				'loses_edit_roles' => false,
				'other_admin'      => false,
				'confirmed'        => false,
				'restrictions'     => $is_admin ? array() : $restrict,
			)
		);
	}

	/**
	 * The Critical capabilities an action grants a role (SEC-10): none for
	 * `administrator` (as the role store), else the Critical ones among the
	 * change's grants, flips to granted included. Pure apart from the
	 * `blt_toolkit_risky_caps` filter BLT_Toolkit_Caps::tier() applies.
	 *
	 * @param string     $role      Role ID.
	 * @param array|null $site_caps The role here, or null.
	 * @param array      $file_caps The file's capabilities.
	 * @param string     $action    Action.
	 * @return string[] Sorted.
	 */
	public static function critical_for( $role, $site_caps, array $file_caps, $action ) {
		if ( BLT_Toolkit_Role_Store::ADMIN_ROLE === (string) $role ) {
			return array();
		}

		$change = self::change_for( $site_caps, $file_caps, $action );

		return BLT_Toolkit_Guard::requires_critical_review( $change['add'] );
	}

	/**
	 * Whether a typed confirmation matches a role's display name: trimmed,
	 * ignoring case (SEC-10, as the rest of the plugin compares it). Pure.
	 *
	 * @param string $typed What was typed.
	 * @param string $name  The display name.
	 * @return bool
	 */
	public static function names_match( $typed, $name ) {
		$typed = trim( (string) $typed );
		$name  = trim( (string) $name );

		if ( '' === $typed || '' === $name ) {
			return false;
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $typed, 'UTF-8' ) === mb_strtolower( $name, 'UTF-8' );
		}

		return strtolower( $typed ) === strtolower( $name );
	}

	/**
	 * The lock token of a preview (RO-2 for imports): SHA-256 of what the
	 * import would act on, canonicalized, so a confirm is refused when the
	 * roles, the role details or the separate-permissions plans changed
	 * since the preview was drawn. Pure.
	 *
	 * @param array $parts Anything the preview depended on.
	 * @return string
	 */
	public static function plan_hash( array $parts ) {
		return hash( 'sha256', BLT_Toolkit_Role_Diff::canonicalize( $parts ) );
	}

	/**
	 * A role's capabilities compared as BLT_Toolkit_Role_Diff does: added,
	 * removed (with their values) and flipped. Pure.
	 *
	 * @param array $from Capabilities now.
	 * @param array $to   Capabilities after.
	 * @return array{added:array,removed:array,flipped:array}
	 */
	public static function cap_diff( array $from, array $to ) {
		$diff = BLT_Toolkit_Role_Diff::diff(
			array(
				'r' => array(
					'name'         => '',
					'capabilities' => $from,
				),
			),
			array(
				'r' => array(
					'name'         => '',
					'capabilities' => $to,
				),
			)
		);

		$change = isset( $diff['changed']['r'] ) ? $diff['changed']['r'] : array();

		return array(
			'added'   => isset( $change['added'] ) ? (array) $change['added'] : array(),
			'removed' => isset( $change['removed'] ) ? (array) $change['removed'] : array(),
			'flipped' => isset( $change['flipped'] ) ? (array) $change['flipped'] : array(),
		);
	}

	// ---------- WordPress: export ----------

	/**
	 * The site's address as the file names it: host, port and path of the
	 * home URL, without the scheme.
	 *
	 * @return string
	 */
	public static function site_label() {
		$parts = wp_parse_url( home_url( '/' ) );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$port  = is_array( $parts ) && isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';

		return $host . $port . $path;
	}

	/**
	 * The export of the given roles from this site (RO-44). Reads only.
	 *
	 * @param string[] $role_ids Role IDs; unknown ones are left out.
	 * @return array document().
	 */
	public static function export_document( array $role_ids ) {
		$meta = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );

		return self::document(
			(array) wp_roles()->roles,
			$role_ids,
			is_array( $meta ) ? $meta : array(),
			BLT_Toolkit_Isolation_Core::isolated(),
			home_url( '/' ),
			gmdate( 'Y-m-d\TH:i:s\Z' )
		);
	}

	/**
	 * Log an export (the roles left the site as a file).
	 *
	 * @param array  $doc      document().
	 * @param string $filename The download's name.
	 * @return void
	 */
	public static function log_export( array $doc, $filename ) {
		$ids = array_keys( $doc['roles'] );

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'roles_exported',
				'object_type' => 'role',
				'object_id'   => implode( ',', $ids ),
				'summary'     => sprintf(
					/* translators: 1: number of roles, 2: a list of role IDs. */
					_n( 'Exported %1$s role: %2$s.', 'Exported %1$s roles: %2$s.', count( $ids ), 'blt-toolkit' ),
					number_format_i18n( count( $ids ) ),
					implode( wp_get_list_item_separator(), $ids )
				),
				'details'     => array(
					'file'                => (string) $filename,
					'roles'               => $ids,
					'role_meta'           => array_keys( $doc['role_meta'] ),
					'isolated_post_types' => array_keys( $doc['isolated_post_types'] ),
				),
			)
		);
	}

	// ---------- WordPress: the uploaded file ----------

	/**
	 * Keep an uploaded, parsed file for its preview and import: one per
	 * person, named by a random token, for an hour.
	 *
	 * @param int    $user_id  Who uploaded it.
	 * @param array  $doc      parse().
	 * @param string $filename The upload's name, for display.
	 * @return string|false The token, or false when it could not be stored.
	 */
	public static function store( $user_id, array $doc, $filename ) {
		try {
			$token = bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			$token = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 16 );
		}

		$data = array(
			'token'   => $token,
			'user_id' => (int) $user_id,
			'created' => time(),
			'file'    => sanitize_file_name( (string) $filename ),
			'doc'     => $doc,
			'choices' => array(),
		);

		delete_transient( self::PLAN_TRANSIENT . (int) $user_id );

		return set_transient( self::PLAN_TRANSIENT . (int) $user_id, self::pack( $data ), self::PLAN_TTL ) ? $token : false;
	}

	/**
	 * Stored data, compressed when zlib is available: a file near the size
	 * limit is over 1 MB serialized, more than a Memcached object cache
	 * takes by default. Base64, so the option row holds text. Pure.
	 *
	 * @param array $data Data.
	 * @return array Either the data, or array( 'packed' => string ).
	 */
	public static function pack( array $data ) {
		if ( ! function_exists( 'gzcompress' ) ) {
			return $data;
		}

		$packed = gzcompress( serialize( $data ), 6 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Our own array, read back by unpack() only.

		return false === $packed ? $data : array( 'packed' => base64_encode( $packed ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary to text for the options table, not obfuscation.
	}

	/**
	 * The data pack() stored, or null when it is damaged. Pure.
	 *
	 * @param mixed $stored Stored value.
	 * @return array|null
	 */
	public static function unpack( $stored ) {
		if ( ! is_array( $stored ) ) {
			return null;
		}

		if ( ! isset( $stored['packed'] ) ) {
			return $stored;
		}

		$bytes = is_string( $stored['packed'] ) && function_exists( 'gzuncompress' ) ? base64_decode( $stored['packed'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See pack().
		$bytes = is_string( $bytes ) ? gzuncompress( $bytes ) : false;
		$data  = is_string( $bytes ) ? unserialize( $bytes, array( 'allowed_classes' => false ) ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Our own array from pack(); no objects allowed.

		return is_array( $data ) ? $data : null;
	}

	/**
	 * A person's stored file, when the token matches.
	 *
	 * @param int    $user_id Who uploaded it.
	 * @param string $token   Its token.
	 * @return array|null
	 */
	public static function stored( $user_id, $token ) {
		$token = is_string( $token ) ? $token : '';
		$data  = '' === $token ? null : self::unpack( get_transient( self::PLAN_TRANSIENT . (int) $user_id ) );

		if ( ! is_array( $data ) || ! isset( $data['token'], $data['user_id'], $data['doc'] ) || ! is_array( $data['doc'] ) || ! hash_equals( (string) $data['token'], $token ) || (int) $data['user_id'] !== (int) $user_id ) {
			return null;
		}

		return $data;
	}

	/**
	 * Remember the choices last posted for a stored file, so the preview
	 * shows them again after a refused import.
	 *
	 * @param int   $user_id Who uploaded it.
	 * @param array $data    stored().
	 * @param array $choices The choices.
	 * @return void
	 */
	public static function remember_choices( $user_id, array $data, array $choices ) {
		$data['choices'] = $choices;

		set_transient( self::PLAN_TRANSIENT . (int) $user_id, self::pack( $data ), self::PLAN_TTL );
	}

	/**
	 * Forget a person's stored file.
	 *
	 * @param int $user_id Who uploaded it.
	 * @return void
	 */
	public static function discard( $user_id ) {
		delete_transient( self::PLAN_TRANSIENT . (int) $user_id );
	}

	// ---------- WordPress: the preview ----------

	/**
	 * Every capability something on this site registers (core, a content
	 * type or taxonomy, BLT Toolkit, a declared group): the catalog's entries
	 * that are not orphans (RO-11). Reads only.
	 *
	 * @return array<string,true>
	 */
	public static function registered_caps() {
		$out = array();

		foreach ( BLT_Toolkit_Capability_Catalog::all() as $cap => $entry ) {
			if ( empty( $entry['orphan'] ) ) {
				$out[ (string) $cap ] = true;
			}
		}

		return $out;
	}

	/**
	 * The preview of an import for a person (RO-45). Reads only (RO-5).
	 *
	 * @param array   $doc     parse().
	 * @param WP_User $actor   Who is importing.
	 * @param array   $choices Optional: the choices to preselect (`actions`, `meta`, `types`).
	 * @return array array(
	 *     'roles' => ID => row (classify() plus `unregistered`, `pending`, `actions` => action =>
	 *                array( 'allowed', 'reason', 'critical' ), `selected`, `confirm_name`, `create_problem`),
	 *     'counts' => status => int, 'unregistered' => int,
	 *     'meta' => ID => array( 'from', 'to', 'reason', 'name' ), 'meta_chosen' => bool,
	 *     'types' => type => array( 'state', 'label', 'singular', 'plural', 'message', 'roles', 'users', 'hash', 'chosen' ),
	 *     'hash'
	 * )
	 */
	public static function preview( array $doc, WP_User $actor, array $choices = array() ) {
		$wp_roles   = wp_roles();
		$site_roles = (array) $wp_roles->roles;
		$rows       = self::refuse_cap_names( self::classify( isset( $doc['roles'] ) ? (array) $doc['roles'] : array(), $site_roles ), $site_roles );
		$registered = self::registered_caps();
		$types      = self::preview_types( isset( $doc['isolated_post_types'] ) ? (array) $doc['isolated_post_types'] : array(), $actor, $choices );
		$pending    = array();

		foreach ( $types as $type => $info ) {
			if ( 'ready' === $info['state'] ) {
				foreach ( self::isolation_strings( $info['singular'], $info['plural'] ) as $string ) {
					$pending[ $string ] = $info['label'];
				}
			}
		}

		$is_admin   = BLT_Toolkit_Guard::is_toolkit_admin( $actor );
		$facts      = array(
			'actor_is_admin' => $is_admin,
			'actor_roles'    => array_values( array_map( 'strval', (array) $actor->roles ) ),
			'actor_caps'     => BLT_Toolkit_Guard::effective_caps( $actor ),
			'role_names'     => array_map( 'strval', array_keys( $site_roles ) ),
			'restrictions'   => BLT_Toolkit_Guard::restrictions(),
			'lockout'        => BLT_Toolkit_Caps::lockout_critical(),
		);
		$asked      = isset( $choices['actions'] ) && is_array( $choices['actions'] ) ? $choices['actions'] : array();
		$counts     = array_fill_keys( array( self::STATUS_NEW, self::STATUS_CHANGED, self::STATUS_UNCHANGED, self::STATUS_INVALID ), 0 );
		$unreg      = array();
		$new_names  = array();
		$site_names = self::site_names();
		$reserved   = null;

		foreach ( $rows as $id => $row ) {
			++$counts[ $row['status'] ];

			$row['unregistered'] = array();
			$row['pending']      = array();

			foreach ( self::unregistered( $row['caps'], $registered ) as $cap ) {
				if ( isset( $pending[ $cap ] ) ) {
					$row['pending'][ $cap ] = $pending[ $cap ];
				} else {
					$row['unregistered'][] = $cap;
					$unreg[ $cap ]         = true;
				}
			}

			$row['create_problem'] = '';

			if ( self::STATUS_NEW === $row['status'] ) {
				if ( null === $reserved ) {
					$reserved = BLT_Toolkit_Role_Lifecycle::reserved_ids();
				}

				$row['create_problem'] = self::create_problem( (string) $id, $row['name'], array_keys( $site_roles ), $reserved, $site_names, $new_names );

				if ( '' === $row['create_problem'] ) {
					$new_names[] = BLT_Toolkit_Role_Lifecycle::clean_name( $row['name'] );
				}
			}

			$row['actions'] = array();

			foreach ( self::actions_for( $row['status'] ) as $action ) {
				$entry = array(
					'allowed'  => true,
					'reason'   => '',
					'critical' => array(),
				);

				if ( self::ACTION_CREATE === $action && '' !== $row['create_problem'] ) {
					$entry['allowed'] = false;
					$entry['reason']  = $row['create_problem'];
				} elseif ( self::ACTION_SKIP !== $action ) {
					$refusal = self::refusal(
						array_merge(
							$facts,
							array(
								'action'    => $action,
								'role'      => (string) $id,
								'site_caps' => $row['site_caps'],
								'file_caps' => $row['caps'],
							)
						)
					);

					if ( null !== $refusal ) {
						$entry['allowed'] = false;
						$entry['reason']  = BLT_Toolkit_Guard::error( $refusal )->get_error_message();
					} else {
						$entry['critical'] = self::critical_for( (string) $id, $row['site_caps'], $row['caps'], $action );
					}
				}

				$row['actions'][ $action ] = $entry;
			}

			$want            = isset( $asked[ $id ] ) && is_string( $asked[ $id ] ) ? $asked[ $id ] : self::default_action( $row['status'] );
			$row['selected'] = isset( $row['actions'][ $want ] ) && $row['actions'][ $want ]['allowed'] ? $want : self::ACTION_SKIP;

			$row['confirm_name'] = self::STATUS_NEW === $row['status']
				? BLT_Toolkit_Role_Lifecycle::clean_name( $row['name'] )
				: ( '' !== $row['site_name'] ? $row['site_name'] : (string) $id );

			$rows[ $id ] = $row;
		}

		$meta = self::preview_meta( $doc, $rows, $actor );

		return array(
			'roles'        => $rows,
			'counts'       => $counts,
			'unregistered' => count( $unreg ),
			'meta'         => $meta,
			'meta_chosen'  => ! empty( $choices['meta'] ),
			'types'        => $types,
			'hash'         => self::plan_hash(
				array(
					'actor'   => (int) $actor->ID,
					'file'    => self::plan_hash( $doc ),
					'roles'   => BLT_Toolkit_Role_Diff::hash( $site_roles ),
					'meta'    => $meta,
					'types'   => array_map(
						static function ( $info ) {
							return array( $info['state'], $info['hash'] );
						},
						$types
					),
					'allowed' => array_map(
						static function ( $row ) {
							return array_map(
								static function ( $entry ) {
									return array( $entry['allowed'], $entry['critical'] );
								},
								$row['actions']
							);
						},
						$rows
					),
				)
			),
		);
	}

	/**
	 * Mark as "cannot be imported" each role whose import would write a
	 * capability name cap_name_problems() refuses (error `cap_names`, the
	 * names in `bad_caps`). Only what the file adds or sets differently is
	 * checked, so a name the role here already holds the same way never
	 * blocks it. Reads only.
	 *
	 * @param array $rows       classify().
	 * @param array $site_roles This site's roles array.
	 * @return array The rows, each with `bad_caps` (cap => code).
	 */
	private static function refuse_cap_names( array $rows, array $site_roles ) {
		$role_ids = array_fill_keys( array_map( 'strval', array_merge( array_keys( $site_roles ), array_keys( $rows ) ) ), true );
		$present  = array();
		$meta     = array_fill_keys( BLT_Toolkit_Role_Lifecycle::META_CAPS, true );

		foreach ( array_keys( BLT_Toolkit_Capability_Catalog::all() ) as $cap ) {
			$present[ (string) $cap ] = true;
		}

		foreach ( $rows as $id => $row ) {
			$rows[ $id ]['bad_caps'] = array();

			if ( self::STATUS_NEW !== $row['status'] && self::STATUS_CHANGED !== $row['status'] ) {
				continue;
			}

			$written = array_merge( $row['added'], $row['flipped'] );

			foreach ( array_keys( $written ) as $cap ) {
				if ( ! isset( $present[ (string) $cap ] ) && BLT_Toolkit_Capability_Catalog::is_meta_cap_name( (string) $cap ) ) {
					$meta[ (string) $cap ] = true;
				}
			}

			$bad = self::cap_name_problems( $written, $role_ids, $present, $meta );

			if ( array() === $bad ) {
				continue;
			}

			$rows[ $id ] = array_merge(
				$row,
				array(
					'status'   => self::STATUS_INVALID,
					'error'    => 'cap_names',
					'caps'     => array(),
					'added'    => array(),
					'removed'  => array(),
					'flipped'  => array(),
					'bad_caps' => $bad,
				)
			);
		}

		return $rows;
	}

	/**
	 * Why a new role cannot be created here, or '': its ID breaks RO-30's
	 * rules or exists (without case) or names a capability, or its display
	 * name is empty, too long or another role's (here, or earlier in the
	 * file).
	 *
	 * @param string   $id         Role ID.
	 * @param string   $name       Display name from the file.
	 * @param string[] $taken      Role IDs here.
	 * @param string[] $reserved   BLT_Toolkit_Role_Lifecycle::reserved_ids().
	 * @param array    $site_names Role ID => names here (stored and translated).
	 * @param string[] $new_names  Names of roles created earlier in the file.
	 * @return string
	 */
	private static function create_problem( $id, $name, array $taken, array $reserved, array $site_names, array $new_names ) {
		$code = BLT_Toolkit_Role_Lifecycle::id_error( $id, $taken, $reserved );

		if ( '' === $code && BLT_Toolkit_Capability_Catalog::is_meta_cap_name( $id ) ) {
			$code = 'reserved';
		}

		if ( '' !== $code ) {
			return BLT_Toolkit_Role_Lifecycle::id_message( $code );
		}

		$clean = BLT_Toolkit_Role_Lifecycle::clean_name( $name );
		$names = $site_names;

		foreach ( $new_names as $index => $other ) {
			$names[ '#file' . $index ] = $other;
		}

		$code = BLT_Toolkit_Role_Lifecycle::name_error( $clean, $names );

		return '' === $code ? '' : BLT_Toolkit_Role_Lifecycle::name_message( $code );
	}

	/**
	 * The display names here, as stored and as shown, keyed so both count
	 * (RO-30: no two roles with one name).
	 *
	 * @return array<string,string>
	 */
	private static function site_names() {
		$out = array();

		foreach ( (array) wp_roles()->role_names as $id => $name ) {
			$out[ (string) $id ]            = (string) $name;
			$out[ (string) $id . '#shown' ] = translate_user_role( (string) $name );
		}

		return $out;
	}

	/**
	 * The role details part of the preview: for each role of the file whose
	 * description or toolbar flag differs from this site's (a role missing
	 * from the file's `role_meta` has none), the values before and after,
	 * and why the person may not change them ('' when they may: the Guard's
	 * role check, as Roles > Role details uses).
	 *
	 * @param array   $doc   parse().
	 * @param array   $rows  The preview's roles.
	 * @param WP_User $actor Who is importing.
	 * @return array<string,array{name:string,from:array,to:array,reason:string,exists:bool}>
	 */
	private static function preview_meta( array $doc, array $rows, WP_User $actor ) {
		$stored = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$stored = is_array( $stored ) ? $stored : array();
		$file   = isset( $doc['role_meta'] ) && is_array( $doc['role_meta'] ) ? $doc['role_meta'] : array();
		$out    = array();

		foreach ( $rows as $id => $row ) {
			if ( self::STATUS_INVALID === $row['status'] ) {
				continue;
			}

			$from = self::meta_entry( isset( $stored[ $id ] ) ? $stored[ $id ] : array() );
			$to   = self::meta_entry( isset( $file[ $id ] ) ? $file[ $id ] : array() );

			if ( $from === $to ) {
				continue;
			}

			$exists = null !== $row['site_caps'];
			$reason = '';

			if ( $exists ) {
				$check = BLT_Toolkit_Guard::check_role_change( $actor, (string) $id, array(), array() );

				if ( is_wp_error( $check ) ) {
					$reason = $check->get_error_message();
				}
			}

			$out[ (string) $id ] = array(
				'name'   => $exists ? translate_user_role( $row['site_name'] ) : $row['name'],
				'from'   => $from,
				'to'     => $to,
				'reason' => $reason,
				'exists' => $exists,
			);
		}

		return $out;
	}

	/**
	 * A role details entry with both keys. Pure.
	 *
	 * @param mixed $entry Stored or parsed entry.
	 * @return array{description:string,hide_toolbar:bool}
	 */
	public static function meta_entry( $entry ) {
		$entry = is_array( $entry ) ? $entry : array();

		return array(
			'description'  => isset( $entry['description'] ) && is_scalar( $entry['description'] ) ? (string) $entry['description'] : '',
			'hide_toolbar' => ! empty( $entry['hide_toolbar'] ),
		);
	}

	/**
	 * The separate-permissions part of the preview: per type of the file,
	 * its state here and, when it can be imported, the separate-permissions
	 * dry run (BLT_Toolkit_Isolation::plan()).
	 *
	 * States: `invalid` (the entry could never be isolated), `missing` (no
	 * such content type here: skipped), `same` (already separate here with
	 * these names), `other` (separate here with other names: skipped),
	 * `forbidden` (the person lacks RS-30's permissions), `not_candidate`
	 * (the type or names cannot be used here; `message` says why) and
	 * `ready`.
	 *
	 * @param array   $entries parse()'s `isolated_post_types`.
	 * @param WP_User $actor   Who is importing.
	 * @param array   $choices Optional: `types`, the chosen types.
	 * @return array<string,array{state:string,label:string,singular:string,plural:string,message:string,roles:int,users:int,hash:string,chosen:bool}>
	 */
	private static function preview_types( array $entries, WP_User $actor, array $choices ) {
		$chosen   = isset( $choices['types'] ) && is_array( $choices['types'] ) ? array_map( 'strval', $choices['types'] ) : array();
		$allowed  = BLT_Toolkit_Isolation::can_manage( $actor );
		$isolated = BLT_Toolkit_Isolation_Core::isolated();
		$out      = array();

		foreach ( $entries as $type => $info ) {
			$type   = (string) $type;
			$object = post_type_exists( $type ) ? get_post_type_object( $type ) : null;
			$row    = array(
				'state'    => 'ready',
				'label'    => $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type,
				'singular' => isset( $info['singular'] ) ? (string) $info['singular'] : '',
				'plural'   => isset( $info['plural'] ) ? (string) $info['plural'] : '',
				'message'  => '',
				'roles'    => 0,
				'users'    => 0,
				'hash'     => '',
				'chosen'   => false,
			);

			if ( isset( $info['error'] ) && '' !== (string) $info['error'] ) {
				$row['state']   = 'invalid';
				$row['message'] = self::entry_message( (string) $info['error'] );
			} elseif ( ! $object ) {
				$row['state'] = 'missing';
			} elseif ( isset( $isolated[ $type ] ) ) {
				$same         = $isolated[ $type ]['singular'] === $row['singular'] && $isolated[ $type ]['plural'] === $row['plural'];
				$row['state'] = $same ? 'same' : 'other';

				if ( ! $same ) {
					$row['message'] = sprintf(
						/* translators: 1: capability base on this site, such as edit_events. 2: the one in the file. */
						__( 'Here they use %1$s; the file\'s %2$s is skipped. Revert them on the Roles screen first to use the file\'s names.', 'blt-toolkit' ),
						'*_' . $isolated[ $type ]['plural'],
						'*_' . $row['plural']
					);
				}
			} elseif ( is_wp_error( $allowed ) ) {
				$row['state']   = 'forbidden';
				$row['message'] = $allowed->get_error_message();
			} else {
				$plan = BLT_Toolkit_Isolation::plan( $type, $row['singular'], $row['plural'] );

				if ( is_wp_error( $plan ) ) {
					$row['state']   = 'not_candidate';
					$row['message'] = $plan->get_error_message();
				} else {
					$row['roles']  = count( $plan['roles'] );
					$row['users']  = count( $plan['users'] );
					$row['hash']   = (string) $plan['hash'];
					$row['label']  = (string) $plan['label'];
					$row['chosen'] = in_array( $type, $chosen, true );
				}
			}

			$out[ $type ] = $row;
		}

		return $out;
	}

	/**
	 * The message for a separate-permissions entry that can never be used.
	 *
	 * @param string $code parse()'s entry error code.
	 * @return string
	 */
	private static function entry_message( $code ) {
		switch ( $code ) {
			case 'type':
				return __( 'Its name is not a valid content type key.', 'blt-toolkit' );
			case 'core_type':
				return __( 'WordPress\'s own content types cannot be given separate permissions.', 'blt-toolkit' );
			case 'singular_invalid':
			case 'plural_invalid':
			case 'same':
				return BLT_Toolkit_Isolation::name_message( $code );
			case 'reserved':
				return __( 'Its names would reuse capabilities WordPress or BLT Toolkit already has.', 'blt-toolkit' );
		}

		return __( 'It is not a valid entry.', 'blt-toolkit' );
	}

	// ---------- WordPress: the import ----------

	/**
	 * Import a stored file as previewed (RO-45).
	 *
	 * 1. The preview is worked out again for the person and must match the
	 *    one confirmed (`hash`): otherwise nothing is written.
	 * 2. Every chosen action must be allowed in it, every Critical grant
	 *    confirmed with the role's typed name (SEC-10), and something chosen.
	 * 3. The roles write is dry-run through the role store, so a refusal by
	 *    the Guard stops the import before anything is written.
	 * 4. Each chosen content type gets separate permissions through
	 *    BLT_Toolkit_Isolation::isolate() (its own `before_isolation`
	 *    snapshot, seeding so nobody's access changes, log row), first,
	 *    so the roles below are not undone by its seeding.
	 * 5. The roles: one write through BLT_Toolkit_Role_Store::transaction()
	 *    (Guard, `before_import` snapshot or no write, hooks, log
	 *    `roles_imported`); `blt_toolkit_role_created` for each role created.
	 * 6. Role details, when chosen, for the file's roles that exist now
	 *    (logged `role_details_saved`).
	 *
	 * @param array   $doc     parse().
	 * @param array   $choices array( 'hash', 'actions' => ID => action, 'meta' => bool, 'types' => string[], 'confirm' => ID => typed name, 'file' => name ).
	 * @param WP_User $actor   Who is importing.
	 * @return array|WP_Error array( 'created', 'merged', 'replaced', 'skipped' (role IDs), 'types' (isolated),
	 *                        'user_errors' (login => why a user's separate-permissions seed failed), 'meta' (role IDs),
	 *                        'snapshot_id', 'log_id', 'unregistered' ).
	 *                        WP_Error codes: `blt_toolkit_import_changed`, `blt_toolkit_import_action`,
	 *                        `blt_toolkit_import_nothing`, `blt_toolkit_critical_review` (data `roles` => ID =>
	 *                        array( 'name', 'caps' )), a Guard or role store refusal, an isolation refusal
	 *                        (data `isolated` lists types already done).
	 */
	public static function import( array $doc, array $choices, WP_User $actor ) {
		$preview = self::preview( $doc, $actor, $choices );
		$hash    = isset( $choices['hash'] ) && is_string( $choices['hash'] ) ? $choices['hash'] : '';

		if ( '' === $hash || ! hash_equals( $preview['hash'], $hash ) ) {
			return new WP_Error( 'blt_toolkit_import_changed', __( 'Roles, role details or content types changed since this preview was made, so nothing was imported. Review the new preview and import again.', 'blt-toolkit' ), array( 'status' => 409 ) );
		}

		$asked    = isset( $choices['actions'] ) && is_array( $choices['actions'] ) ? $choices['actions'] : array();
		$plan     = array();
		$critical = array();
		$confirm  = isset( $choices['confirm'] ) && is_array( $choices['confirm'] ) ? $choices['confirm'] : array();
		$done     = array(
			self::ACTION_CREATE  => array(),
			self::ACTION_MERGE   => array(),
			self::ACTION_REPLACE => array(),
			self::ACTION_SKIP    => array(),
		);

		foreach ( $preview['roles'] as $id => $row ) {
			$id     = (string) $id;
			$action = isset( $asked[ $id ] ) && is_string( $asked[ $id ] ) ? $asked[ $id ] : self::ACTION_SKIP;

			if ( ! isset( $row['actions'][ $action ] ) || ! $row['actions'][ $action ]['allowed'] ) {
				$reason = isset( $row['actions'][ $action ]['reason'] ) ? (string) $row['actions'][ $action ]['reason'] : '';

				return new WP_Error(
					'blt_toolkit_import_action',
					sprintf(
						/* translators: 1: role ID, 2: the reason. */
						__( 'The %1$s role cannot be imported that way: %2$s', 'blt-toolkit' ),
						$id,
						'' !== $reason ? $reason : __( 'that action is not offered for it.', 'blt-toolkit' )
					),
					array(
						'status' => 400,
						'role'   => $id,
					)
				);
			}

			$done[ $action ][] = $id;

			if ( self::ACTION_SKIP === $action ) {
				continue;
			}

			$plan[ $id ] = array(
				'action' => $action,
				'name'   => BLT_Toolkit_Role_Lifecycle::clean_name( $row['name'] ),
				'caps'   => $row['caps'],
			);

			if ( array() !== $row['actions'][ $action ]['critical'] && ! self::names_match( isset( $confirm[ $id ] ) && is_string( $confirm[ $id ] ) ? $confirm[ $id ] : '', $row['confirm_name'] ) ) {
				$critical[ $id ] = array(
					'name' => $row['confirm_name'],
					'caps' => $row['actions'][ $action ]['critical'],
				);
			}
		}

		$types = array();

		foreach ( isset( $choices['types'] ) && is_array( $choices['types'] ) ? $choices['types'] : array() as $type ) {
			$type = is_string( $type ) ? $type : '';

			if ( ! isset( $preview['types'][ $type ] ) || 'ready' !== $preview['types'][ $type ]['state'] ) {
				return new WP_Error(
					'blt_toolkit_import_action',
					/* translators: %s: post type key. */
					sprintf( __( 'Separate permissions for %s cannot be imported here.', 'blt-toolkit' ), $type ),
					array( 'status' => 400 )
				);
			}

			$types[ $type ] = $preview['types'][ $type ];
		}

		$meta = array();

		if ( ! empty( $choices['meta'] ) ) {
			foreach ( $preview['meta'] as $id => $entry ) {
				if ( '' !== $entry['reason'] ) {
					continue;
				}

				// A new role's details come with it, or not at all.
				if ( ! $entry['exists'] && ! isset( $plan[ $id ] ) ) {
					continue;
				}

				$meta[ $id ] = $entry;
			}
		}

		if ( array() === $plan && array() === $types && array() === $meta ) {
			return new WP_Error( 'blt_toolkit_import_nothing', __( 'Nothing was imported: every role is set to Skip, and no role details or separate permissions were chosen.', 'blt-toolkit' ), array( 'status' => 400 ) );
		}

		if ( array() !== $critical ) {
			return new WP_Error(
				'blt_toolkit_critical_review',
				__( 'Nothing was imported: some roles would get Critical capabilities, which can be used to take over the site or its users. Type each role\'s name exactly as shown to confirm.', 'blt-toolkit' ),
				array(
					'status' => 409,
					'rule'   => 'SEC-10',
					'roles'  => $critical,
				)
			);
		}

		$details = array(
			'file'         => isset( $choices['file'] ) ? (string) $choices['file'] : '',
			'from_site'    => (string) $doc['site'],
			'exported_at'  => (string) $doc['exported_at'],
			'version'      => (int) $doc['version'],
			'actions'      => array_map(
				static function ( $item ) {
					return $item['action'];
				},
				$plan
			),
			'skipped'      => $done[ self::ACTION_SKIP ],
			'unregistered' => array_filter(
				array_map(
					static function ( $row ) {
						return $row['unregistered'];
					},
					array_intersect_key( $preview['roles'], $plan )
				)
			),
			'types'        => array_keys( $types ),
			'role_meta'    => array_keys( $meta ),
		);

		$args = array(
			'reason'             => self::REASON,
			'context'            => self::CONTEXT,
			'module'             => 'roles',
			'action'             => 'roles_imported',
			'object_type'        => 'role',
			'summary'            => self::summary( $done, $doc ),
			'details'            => $details,
			'confirmed_critical' => true,
			'actor'              => $actor,
		);

		$mutate = static function ( array $roles ) use ( $plan ) {
			return BLT_Toolkit_Role_Transfer::apply( $roles, $plan );
		};

		// 3. The Guard's verdict before anything is written.
		if ( array() !== $plan ) {
			$dry = BLT_Toolkit_Role_Store::transaction( $mutate, array_merge( $args, array( 'dry_run' => true ) ) );

			if ( is_wp_error( $dry ) ) {
				return $dry;
			}

			if ( isset( $dry['guard'] ) && true !== $dry['guard'] ) {
				return $dry['guard'];
			}
		}

		// 4. Separate permissions first: their seeding maps Posts' strings
		// to the type's, and would take an imported type string from a role
		// that holds none of Posts' (RS-30). Imported after, the roles keep
		// exactly what the file gives them.
		$isolated    = array();
		$user_errors = array();

		foreach ( $types as $type => $info ) {
			$result = BLT_Toolkit_Isolation::isolate(
				$type,
				$info['singular'],
				$info['plural'],
				array(
					'expected' => $info['hash'],
					'actor'    => $actor,
				)
			);

			if ( is_wp_error( $result ) ) {
				$result->add_data(
					array_merge(
						(array) $result->get_error_data(),
						array(
							'isolated' => $isolated,
							'type'     => $type,
						)
					)
				);

				return $result;
			}

			$isolated[] = $type;

			if ( ! empty( $result['user_errors'] ) && is_array( $result['user_errors'] ) ) {
				$user_errors += $result['user_errors'];
			}
		}

		// 5. The roles, in one write.
		$store = array(
			'snapshot_id' => 0,
			'log_id'      => 0,
		);

		if ( array() !== $plan ) {
			$store = BLT_Toolkit_Role_Store::transaction( $mutate, $args );

			if ( is_wp_error( $store ) ) {
				if ( array() !== $isolated ) {
					$store->add_data( array_merge( (array) $store->get_error_data(), array( 'isolated' => $isolated ) ) );
				}

				return $store;
			}

			foreach ( $done[ self::ACTION_CREATE ] as $id ) {
				/** This action is documented in includes/class-blt-toolkit-role-lifecycle.php */
				do_action( 'blt_toolkit_role_created', $id, $plan[ $id ]['name'], 'import' );
			}
		}

		// The import is on the record even when no role changed.
		if ( 0 === (int) $store['log_id'] ) {
			$store['log_id'] = (int) BLT_Toolkit_Log::record(
				array(
					'module'      => 'roles',
					'action'      => 'roles_imported',
					'object_type' => 'role',
					'object_id'   => implode( ',', array_keys( $plan ) ),
					'summary'     => $args['summary'],
					'details'     => array_merge( $details, array( 'context' => self::CONTEXT ) ),
				)
			);
		}

		// 6. Role details, for roles that exist now.
		$written = self::import_meta( $meta );

		return array(
			'created'      => $done[ self::ACTION_CREATE ],
			'merged'       => $done[ self::ACTION_MERGE ],
			'replaced'     => $done[ self::ACTION_REPLACE ],
			'skipped'      => $done[ self::ACTION_SKIP ],
			'types'        => $isolated,
			'user_errors'  => $user_errors,
			'meta'         => $written,
			'snapshot_id'  => (int) $store['snapshot_id'],
			'log_id'       => (int) $store['log_id'],
			'unregistered' => $preview['unregistered'],
		);
	}

	/**
	 * Write the chosen role details (SPEC 9.5), for roles that exist now,
	 * and log them in one `role_details_saved` row.
	 *
	 * @param array $meta preview_meta() entries chosen.
	 * @return string[] Role IDs whose details were written.
	 */
	private static function import_meta( array $meta ) {
		if ( array() === $meta ) {
			return array();
		}

		$stored = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$stored = is_array( $stored ) ? $stored : array();
		$roles  = wp_roles();
		$done   = array();
		$log    = array();

		foreach ( $meta as $id => $entry ) {
			if ( ! $roles->is_role( (string) $id ) ) {
				continue;
			}

			$stored[ (string) $id ] = $entry['to'];
			$done[]                 = (string) $id;
			$log[ (string) $id ]    = array(
				'before' => $entry['from'],
				'after'  => $entry['to'],
			);
		}

		if ( array() === $done || ! BLT_Toolkit_Settings::set( 'roles', 'role_meta', $stored ) ) {
			return array();
		}

		BLT_Toolkit_Log::record(
			array(
				'module'      => 'roles',
				'action'      => 'role_details_saved',
				'object_type' => 'role',
				'object_id'   => implode( ',', $done ),
				'summary'     => sprintf(
					/* translators: 1: number of roles, 2: a list of role IDs. */
					_n( 'Imported the details of %1$s role: %2$s.', 'Imported the details of %1$s roles: %2$s.', count( $done ), 'blt-toolkit' ),
					number_format_i18n( count( $done ) ),
					implode( wp_get_list_item_separator(), $done )
				),
				'details'     => array(
					'context' => self::CONTEXT,
					'roles'   => $log,
				),
			)
		);

		return $done;
	}

	/**
	 * The log summary of an import.
	 *
	 * @param array $done Action => role IDs.
	 * @param array $doc  parse().
	 * @return string
	 */
	private static function summary( array $done, array $doc ) {
		$sep       = wp_get_list_item_separator();
		$sentences = array(
			'' !== (string) $doc['site']
				/* translators: %s: the site the file came from. */
				? sprintf( __( 'Imported roles from %s.', 'blt-toolkit' ), (string) $doc['site'] )
				: __( 'Imported roles from a file.', 'blt-toolkit' ),
		);

		if ( array() !== $done[ self::ACTION_CREATE ] ) {
			/* translators: %s: list of role IDs. */
			$sentences[] = sprintf( __( 'Roles created: %s.', 'blt-toolkit' ), implode( $sep, $done[ self::ACTION_CREATE ] ) );
		}

		if ( array() !== $done[ self::ACTION_MERGE ] ) {
			/* translators: %s: list of role IDs. */
			$sentences[] = sprintf( __( 'Capabilities merged into: %s.', 'blt-toolkit' ), implode( $sep, $done[ self::ACTION_MERGE ] ) );
		}

		if ( array() !== $done[ self::ACTION_REPLACE ] ) {
			/* translators: %s: list of role IDs. */
			$sentences[] = sprintf( __( 'Capabilities replaced: %s.', 'blt-toolkit' ), implode( $sep, $done[ self::ACTION_REPLACE ] ) );
		}

		if ( 1 === count( $sentences ) ) {
			$sentences[] = __( 'No role changed.', 'blt-toolkit' );
		}

		return implode( ' ', $sentences );
	}

	// ---------- Helpers ----------

	/**
	 * Values as booleans, keys as strings, sorted. Pure.
	 *
	 * @param array $caps Capability => value.
	 * @return array<string,bool>
	 */
	private static function bools( array $caps ) {
		$out = array();

		foreach ( $caps as $cap => $value ) {
			$out[ (string) $cap ] = (bool) $value;
		}

		ksort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * A string cut to a length in characters, control characters removed.
	 * Pure.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest length.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $text );

		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $text, 0, (int) $max, 'UTF-8' );
		}

		return substr( $text, 0, (int) $max );
	}
}
