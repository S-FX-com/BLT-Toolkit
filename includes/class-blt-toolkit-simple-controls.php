<?php
/**
 * Simple view controls: every control Simple view shows, which capabilities
 * each one covers, and its state for a role.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Simple view's control registry (SPEC 10, Appendix A, RS-3).
 *
 * Every control is declared once, with:
 *
 * - `id` (`[a-z0-9_-]`), `section`, `label`, `help`;
 * - `kind`: `level` (a content row: a level selector with Can delete and
 *   Manage {taxonomy} modifiers, SPEC 10.3), `toggle` (one or more
 *   capabilities, Appendix A), `meta` (role metadata, not a capability:
 *   "Hide the toolbar on the site", SPEC 9.5) or `summary` ("Other plugin
 *   permissions", read only);
 * - `universe`: the capabilities it covers, resolved at runtime (a level
 *   row's from BLT_Toolkit_Simple_Mapper::universe(), a toggle's its
 *   `caps`);
 * - `tier`: the highest tier in the universe (SEC-11);
 * - `depends`: the RS-7 hints (another control it usually needs);
 * - `available`: a boolean or callable; a control that is not available is
 *   left out.
 *
 * State for a role (all()) comes from BLT_Toolkit_Simple_Mapper: detect() for
 * a level row (RS-15: one exact match, otherwise Custom), toggle_state() for a
 * toggle (RS-4: on, off or indeterminate). Target capabilities for a chosen
 * state come from apply() (RS-5: adds and removes within the row's universe
 * only). Rendering and computing state change nothing (RO-5).
 *
 * Content rows (RS-10 to RS-13) come from the capability catalog: one row per
 * capability group of post types shown in the dashboard (RO-14), labeled with
 * every member type. A group whose capabilities include something neither
 * its own nor shared with Posts or Pages is read only, "Controlled by" the
 * control that owns that capability (RS-11); a group with `map_meta_cap`
 * false is read only with "Custom ownership rules" (RS-12).
 *
 * `blt_toolkit_simple_controls` lets other BLT plugins add controls (or
 * sections). The registry also tells the capability catalog which Simple
 * control each capability belongs to, for the Advanced matrix's "Simple:
 * Events → level" (RO-21), through register_provider().
 *
 * Nothing here runs on page load: the Roles screen and its save handler are
 * the only callers, and the definitions are built once per request.
 */
class BLT_Toolkit_Simple_Controls {

	/**
	 * Built-in sections, in SPEC 13.2 order. Sections added through the
	 * filter go before `other`.
	 */
	const SECTIONS = array( 'basics', 'content', 'media', 'appearance', 'plugins', 'site', 'people', 'blt', 'other' );

	/**
	 * Control kinds.
	 */
	const LEVEL   = 'level';
	const TOGGLE  = 'toggle';
	const META    = 'meta';
	const SUMMARY = 'summary';

	/**
	 * The "Other plugin permissions" summary (SPEC 13.2).
	 */
	const OTHER_ID = 'other-plugins';

	/**
	 * The one role metadata control (SPEC 9.5).
	 */
	const TOOLBAR_ID = 'hide_toolbar';

	/**
	 * Why a content row has no level selector.
	 */
	const READONLY_EXCLUDED  = 'excluded';
	const READONLY_OWNERSHIP = 'custom_ownership';
	const READONLY_ISOLATION = 'isolation_failed';

	/**
	 * Definitions built this request.
	 *
	 * @var array|null
	 */
	private static $definitions = null;

	/**
	 * Whether definitions() is running, so a catalog build it causes (the
	 * RO-21 provider) cannot start it again.
	 *
	 * @var bool
	 */
	private static $building = false;

	/**
	 * Section labels this request (built-in plus filtered).
	 *
	 * @var array<string,string>
	 */
	private static $section_labels = array();

	// ---------- Public API ----------

	/**
	 * Section ID => array( label, desc ), in display order (SPEC 13.2),
	 * including sections added through `blt_toolkit_simple_controls`.
	 *
	 * @return array<string,array{label:string,desc:string}>
	 */
	public static function sections() {
		$builtin = array(
			'basics'     => array(
				'label' => __( 'Basics', 'blt-toolkit' ),
				'desc'  => __( 'Signing in, and the toolbar on the site.', 'blt-toolkit' ),
			),
			'content'    => array(
				'label' => __( 'Content', 'blt-toolkit' ),
				'desc'  => __( 'One row per set of content types that share permissions. Pick how much the role may do with each.', 'blt-toolkit' ),
			),
			'media'      => array(
				'label' => __( 'Media and comments', 'blt-toolkit' ),
				'desc'  => __( 'Uploads, comments, categories and tags.', 'blt-toolkit' ),
			),
			'appearance' => array(
				'label' => __( 'Appearance', 'blt-toolkit' ),
				'desc'  => __( 'Menus, widgets, the site editor and themes.', 'blt-toolkit' ),
			),
			'plugins'    => array(
				'label' => __( 'Plugins', 'blt-toolkit' ),
				'desc'  => __( 'Plugins run code on the server, so every control here is Critical.', 'blt-toolkit' ),
			),
			'site'       => array(
				'label' => __( 'Site', 'blt-toolkit' ),
				'desc'  => __( 'Settings, updates, import and export, and what content may contain.', 'blt-toolkit' ),
			),
			'people'     => array(
				'label' => __( 'People', 'blt-toolkit' ),
				'desc'  => __( 'Other users\' accounts, switching and usernames.', 'blt-toolkit' ),
			),
			'blt'        => array(
				'label' => __( 'BLT Toolkit', 'blt-toolkit' ),
				'desc'  => __( 'Who may change roles, and see BLT Toolkit\'s activity.', 'blt-toolkit' ),
			),
			'other'      => array(
				'label' => __( 'Other plugin permissions', 'blt-toolkit' ),
				'desc'  => __( 'Capabilities other plugins register that Simple view has no control for.', 'blt-toolkit' ),
			),
		);

		self::definitions();

		$out = array();

		foreach ( $builtin as $id => $section ) {
			if ( 'other' === $id ) {
				foreach ( self::$section_labels as $extra => $label ) {
					if ( ! isset( $builtin[ $extra ] ) ) {
						$out[ $extra ] = array(
							'label' => $label,
							'desc'  => '',
						);
					}
				}
			}

			$out[ $id ] = $section;
		}

		return $out;
	}

	/**
	 * Every control, without state, keyed by ID, in section order: the
	 * Basics controls, one content row per capability group, the Appendix A
	 * controls, then whatever `blt_toolkit_simple_controls` adds. Built once
	 * per request.
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}

		if ( self::$building ) {
			return array();
		}

		self::$building = true;

		$list = array_merge( self::basics_controls(), self::content_controls(), self::site_controls() );

		/**
		 * Filter the Simple view's controls (RS-3). Add a control to give
		 * another plugin's capabilities a place in Simple view.
		 *
		 * Each control is an array: `id` (`[a-z0-9_-]`, unique), `section` (a
		 * built-in section ID, or a new one with `section_label`), `label`,
		 * `help`, `kind` (`toggle`, the default, with `caps`: the capability
		 * names it turns on and off together; or `level`, with `map`: a
		 * BLT_Toolkit_Simple_Mapper type map), and optionally `tier` (default:
		 * the highest tier of its capabilities), `depends` (RS-7 hints: each
		 * array( 'control' => ID, 'text' => string, 'min' => level )), `note`
		 * and `available` (a boolean or a callable returning one; false
		 * leaves the control out). Simple view saves only controls that
		 * changed, and only within each control's capabilities (RS-5).
		 *
		 * @param array[] $controls The built-in controls.
		 */
		$filtered = apply_filters( 'blt_toolkit_simple_controls', $list );

		if ( ! is_array( $filtered ) ) {
			$filtered = $list;
		}

		$out = array();

		foreach ( $filtered as $control ) {
			$control = is_array( $control ) ? self::normalize( $control ) : null;

			if ( null === $control || isset( $out[ $control['id'] ] ) ) {
				continue;
			}

			$out[ $control['id'] ] = $control;
		}

		self::$definitions = self::in_section_order( $out );
		self::$building    = false;

		return self::$definitions;
	}

	/**
	 * Every control with its state for a role (RS-3, RS-4, RS-15), plus the
	 * "Other plugin permissions" summary. Each control adds:
	 *
	 * - `state`: a level row's array( 'level' => level or `custom`, 'mods'
	 *   => array( 'can_delete', 'manage' ) ); a toggle's `on`, `off` or
	 *   `indeterminate`; the toolbar control's `on` or `off`.
	 * - `granted`: the capabilities of its universe the role grants;
	 *   `denied`: those it holds as `false`.
	 * - `hints`: the RS-7 hints, each with `active` for this role.
	 *
	 * @param WP_Role|string $role Role or role ID.
	 * @return array<string,array> Control ID => control, in section order.
	 */
	public static function all( $role ) {
		$caps    = self::role_caps( $role );
		$role_id = $role instanceof WP_Role ? (string) $role->name : (string) $role;
		$meta    = BLT_Toolkit_Settings::get( 'roles', 'role_meta' );
		$out     = array();

		foreach ( self::definitions() as $id => $control ) {
			$control['granted'] = array();
			$control['denied']  = array();

			foreach ( $control['universe'] as $cap ) {
				if ( ! array_key_exists( $cap, $caps ) ) {
					continue;
				}

				if ( $caps[ $cap ] ) {
					$control['granted'][] = $cap;
				} else {
					$control['denied'][] = $cap;
				}
			}

			$control['state'] = self::state_of( $control, $caps, is_array( $meta ) && isset( $meta[ $role_id ] ) && is_array( $meta[ $role_id ] ) ? $meta[ $role_id ] : array() );

			$out[ $id ] = $control;
		}

		foreach ( $out as $id => $control ) {
			$out[ $id ]['hints'] = self::hints_for( $control, $out );
		}

		$out[ self::OTHER_ID ] = self::other_summary( $out, $caps );

		return $out;
	}

	/**
	 * What a Simple save changes (RS-5): only the controls whose submitted
	 * state differs from the role's state now. For each, the target
	 * capabilities are worked out within that control's universe; the
	 * result is in BLT_Toolkit_Role_Store::save_caps()' terms, so the store
	 * touches nothing outside the changed controls (RO-1).
	 *
	 * `$submitted`: control ID => `on`, `off` or `indeterminate` for a toggle
	 * or the toolbar; for a level row array( 'level', 'can_delete', 'manage'
	 * => array( taxonomy => bool ), 'mods_set' ). A control that is missing,
	 * read only, unknown or unchanged produces nothing; `indeterminate` and
	 * `custom` mean "leave it as it is" (RS-4).
	 *
	 * Modifiers: without `mods_set` (a form sent without JavaScript), a level
	 * that changed while its modifiers were left as drawn takes the new
	 * level's defaults (SPEC 10.3: Can delete on, on, off; Manage on only at
	 * Full control); the script sends `mods_set` with the values it set.
	 *
	 * @param WP_Role|string $role      Role or role ID.
	 * @param array          $submitted Control ID => submitted state.
	 * @return array{on_page:string[],checked:string[],controls:array,meta:array}|WP_Error
	 *         `controls`: ID => array( id, label, kind, from, to, add, remove, custom );
	 *         `meta`: array( 'hide_toolbar' => bool ) when it changed.
	 *         WP_Error `blt_toolkit_role_not_found`, or `blt_toolkit_simple_overlap`
	 *         when two changed controls want one capability both ways.
	 */
	public static function changes_for( $role, array $submitted ) {
		$role_obj = $role instanceof WP_Role ? $role : wp_roles()->get_role( (string) $role );

		if ( ! $role_obj instanceof WP_Role ) {
			return new WP_Error( 'blt_toolkit_role_not_found', __( 'That role does not exist. Someone may have deleted it.', 'blt-toolkit' ), array( 'status' => 404 ) );
		}

		return self::plan( self::all( $role_obj ), self::role_caps( $role_obj ), $submitted );
	}

	/**
	 * The plan for a submission, from controls with their state (all()) and
	 * the role's capabilities. See changes_for(). Pure apart from the mapper
	 * and the translated texts.
	 *
	 * @param array $controls  all() for the role.
	 * @param array $caps      The role's capabilities (cap => value).
	 * @param array $submitted Control ID => submitted state.
	 * @return array{on_page:string[],checked:string[],controls:array,meta:array}|WP_Error
	 */
	public static function plan( array $controls, array $caps, array $submitted ) {
		$on_page = array();
		$adds    = array();
		$removes = array();
		$changed = array();
		$meta    = array();
		$owner   = array();
		$wanted  = array();

		foreach ( $controls as $id => $control ) {
			$id = (string) $id;

			if ( ! array_key_exists( $id, $submitted ) || ! self::changeable( $control ) ) {
				continue;
			}

			$value = $submitted[ $id ];

			if ( self::META === $control['kind'] ) {
				$want = self::toggle_value( $value );

				if ( null !== $want && $want !== $control['state'] ) {
					$meta[ self::TOOLBAR_ID ] = 'on' === $want;
					$changed[ $id ]           = array(
						'id'     => $id,
						'label'  => $control['label'],
						'kind'   => $control['kind'],
						'from'   => self::state_text( $control, $control['state'] ),
						'to'     => self::state_text( $control, $want ),
						'add'    => array(),
						'remove' => array(),
						'custom' => false,
					);
				}

				continue;
			}

			if ( self::TOGGLE === $control['kind'] ) {
				$want = self::toggle_value( $value );

				if ( null === $want || 'indeterminate' === $want || $want === $control['state'] ) {
					continue;
				}

				$add    = 'on' === $want ? array_values( array_diff( $control['universe'], $control['granted'] ) ) : array();
				$remove = 'off' === $want ? $control['granted'] : array();
				$on     = 'on' === $want ? $control['universe'] : array();
				$to     = $want;
				$custom = 'indeterminate' === $control['state'];
			} else {
				$target = self::level_target( $control, $value );

				if ( null === $target ) {
					continue;
				}

				$change = BLT_Toolkit_Simple_Mapper::apply( $control['map'], $caps, $target['level'], $target['mods'] );
				$add    = $change['add'];
				$remove = $change['remove'];
				$on     = BLT_Toolkit_Simple_Mapper::caps_for( $control['map'], $target['level'], $target['mods'] );
				$to     = $target;
				$custom = BLT_Toolkit_Simple_Mapper::CUSTOM === $control['state']['level'];
			}

			// Two changed controls sharing a capability must agree on it: one
			// wanting it on while the other wants it off is refused, not
			// guessed (the later one would silently undo the earlier).
			$on = array_fill_keys( $on, true );

			foreach ( $control['universe'] as $cap ) {
				$is_on = isset( $on[ $cap ] );

				if ( isset( $wanted[ $cap ] ) && $wanted[ $cap ] !== $is_on ) {
					return self::overlap_error( $control, $controls[ $owner[ $cap ] ], $cap );
				}

				$wanted[ $cap ] = $is_on;
				$owner[ $cap ]  = $id;
			}

			foreach ( $add as $cap ) {
				$adds[ $cap ] = true;
			}

			foreach ( $remove as $cap ) {
				$removes[ $cap ] = true;
			}

			foreach ( $control['universe'] as $cap ) {
				$on_page[ $cap ] = true;
			}

			$changed[ $id ] = array(
				'id'     => $id,
				'label'  => $control['label'],
				'kind'   => $control['kind'],
				'from'   => self::state_text( $control, $control['state'] ),
				'to'     => self::state_text( $control, $to ),
				'add'    => array_values( $add ),
				'remove' => array_values( $remove ),
				'custom' => $custom,
			);
		}

		// The boxes "checked" after the change: within the changed controls'
		// capabilities, what the role grants now, less the removes, plus the
		// adds. The store then adds and removes exactly those (RO-1); a deny
		// (`false`) nobody asked to change stays a deny.
		$checked = array();

		foreach ( array_keys( $on_page ) as $cap ) {
			$cap     = (string) $cap;
			$granted = array_key_exists( $cap, $caps ) && $caps[ $cap ];

			if ( isset( $adds[ $cap ] ) || ( $granted && ! isset( $removes[ $cap ] ) ) ) {
				$checked[] = $cap;
			}
		}

		$on_page = array_map( 'strval', array_keys( $on_page ) );

		sort( $on_page, SORT_STRING );
		sort( $checked, SORT_STRING );

		return array(
			'on_page'  => $on_page,
			'checked'  => $checked,
			'controls' => $changed,
			'meta'     => $meta,
		);
	}

	/**
	 * Tell the capability catalog which Simple control each capability
	 * belongs to, for the Advanced matrix (RO-21: "Simple: Events → level").
	 * The Roles screen calls it before the catalog is built.
	 *
	 * @return void
	 */
	public static function register_provider() {
		BLT_Toolkit_Capability_Catalog::set_simple_provider(
			static function ( array $caps ) {
				return BLT_Toolkit_Simple_Controls::catalog_labels( $caps );
			}
		);
	}

	/**
	 * Capability => the Simple control it belongs to, for RO-21: "Simple:
	 * Events → level" for a content row, "Simple: Events → Manage Event
	 * Categories" for a Manage toggle, "Simple: Site → Change settings" for
	 * a toggle. Only controls that can change the capability count; several
	 * are joined with "; ".
	 *
	 * Never builds the full catalog (it is called while the catalog builds).
	 *
	 * @param string[] $caps Capability names.
	 * @return array<string,string>
	 */
	public static function catalog_labels( array $caps ) {
		$want     = array_fill_keys( array_map( 'strval', $caps ), true );
		$sections = self::sections();
		$found    = array();

		foreach ( self::definitions() as $control ) {
			if ( ! self::changeable( $control ) || self::META === $control['kind'] ) {
				continue;
			}

			if ( self::LEVEL === $control['kind'] ) {
				$manage = array();

				foreach ( BLT_Toolkit_Simple_Mapper::toggles( $control['map'] ) as $tax => $strings ) {
					foreach ( $strings as $string ) {
						$manage[ $string ] = isset( $control['taxonomies'][ $tax ] ) ? $control['taxonomies'][ $tax ] : $tax;
					}
				}

				foreach ( $control['universe'] as $cap ) {
					if ( ! isset( $want[ $cap ] ) ) {
						continue;
					}

					$found[ $cap ][] = isset( $manage[ $cap ] )
						/* translators: 1: plural content type name, such as Events, 2: plural taxonomy name, such as Event Categories. */
						? sprintf( __( 'Simple: %1$s → Manage %2$s', 'blt-toolkit' ), $control['plural'], $manage[ $cap ] )
						/* translators: %s: plural content type name, such as Events. */
						: sprintf( __( 'Simple: %s → level', 'blt-toolkit' ), $control['plural'] );
				}

				continue;
			}

			$section = isset( $sections[ $control['section'] ] ) ? $sections[ $control['section'] ]['label'] : $control['section'];

			foreach ( $control['universe'] as $cap ) {
				if ( isset( $want[ $cap ] ) ) {
					/* translators: 1: Simple view section, such as Site, 2: control label, such as Change settings. */
					$found[ $cap ][] = sprintf( __( 'Simple: %1$s → %2$s', 'blt-toolkit' ), $section, $control['label'] );
				}
			}
		}

		$out = array();

		foreach ( $found as $cap => $texts ) {
			$out[ (string) $cap ] = implode( '; ', array_values( array_unique( $texts ) ) );
		}

		return $out;
	}

	/**
	 * The label of each level (SPEC 10.3), Custom included.
	 *
	 * @return array<string,string>
	 */
	public static function level_labels() {
		return array(
			'none'       => __( 'No access', 'blt-toolkit' ),
			'view'       => __( 'View', 'blt-toolkit' ),
			'contribute' => __( 'Contribute', 'blt-toolkit' ),
			'author'     => __( 'Author (own only)', 'blt-toolkit' ),
			'edit_all'   => __( 'Edit all', 'blt-toolkit' ),
			'full'       => __( 'Full control', 'blt-toolkit' ),
			'custom'     => __( 'Custom', 'blt-toolkit' ),
		);
	}

	/**
	 * A level's help text exactly as SPEC 10.3 words it, for a content
	 * type's plural name.
	 *
	 * @param string $level  Level, or `custom`.
	 * @param string $plural Plural name, such as Events.
	 * @return string
	 */
	public static function level_help( $level, $plural ) {
		switch ( (string) $level ) {
			case 'none':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Can\'t see or change %s in the dashboard.', 'blt-toolkit' ), $plural );
			case 'view':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Can see all %s, including private ones, but can\'t change anything.', 'blt-toolkit' ), $plural );
			case 'contribute':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Can write %s and submit them for review. Can\'t publish.', 'blt-toolkit' ), $plural );
			case 'author':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Can create, publish and manage their own %s. Can\'t change anyone else\'s.', 'blt-toolkit' ), $plural );
			case 'edit_all':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Can edit everyone\'s %s, including published and private ones. Can\'t publish new ones. Edits to published items go live immediately.', 'blt-toolkit' ), $plural );
			case 'full':
				/* translators: %s: plural content type name, such as Events. */
				return sprintf( __( 'Can create, publish, edit and delete any %s, and manage their categories and tags.', 'blt-toolkit' ), $plural );
		}

		return __( 'This role has a mix of permissions Simple view can\'t show. Pick a level to replace it, or edit in Advanced.', 'blt-toolkit' );
	}

	/**
	 * The addition to View's help text when the type has no distinct create
	 * capability (RS-13): View is site-only.
	 *
	 * @param string $plural Plural name.
	 * @param bool   $can_separate Whether the row offers separate permissions.
	 * @return string
	 */
	public static function site_only_help( $plural, $can_separate ) {
		if ( $can_separate ) {
			/* translators: %1$s: plural content type name, such as Events. */
			return sprintf( __( 'On the site only. To browse %1$s read-only in the dashboard, give %1$s separate permissions.', 'blt-toolkit' ), $plural );
		}

		return __( 'On the site only.', 'blt-toolkit' );
	}

	/**
	 * A plain-English description of a control's state, for the review and
	 * the notice (RS-6): "Author (own only), can delete", "Allowed".
	 *
	 * @param array        $control Control.
	 * @param string|array $state   State (as in all()).
	 * @return string
	 */
	public static function state_text( array $control, $state ) {
		if ( self::LEVEL === $control['kind'] ) {
			$labels = self::level_labels();
			$level  = is_array( $state ) && isset( $state['level'] ) ? (string) $state['level'] : 'custom';
			$text   = isset( $labels[ $level ] ) ? $labels[ $level ] : $labels['custom'];
			$extra  = array();

			if ( BLT_Toolkit_Simple_Mapper::shows_can_delete( $level ) && ! empty( $state['mods']['can_delete'] ) ) {
				$extra[] = __( 'can delete', 'blt-toolkit' );
			}

			if ( BLT_Toolkit_Simple_Mapper::shows_manage( $level ) && isset( $state['mods']['manage'] ) && is_array( $state['mods']['manage'] ) ) {
				foreach ( $state['mods']['manage'] as $tax => $on ) {
					if ( $on ) {
						/* translators: %s: plural taxonomy name, such as Event Categories. */
						$extra[] = sprintf( __( 'manages %s', 'blt-toolkit' ), isset( $control['taxonomies'][ $tax ] ) ? $control['taxonomies'][ $tax ] : $tax );
					}
				}
			}

			return array() === $extra ? $text : $text . ', ' . implode( ', ', $extra );
		}

		switch ( $state ) {
			case 'on':
				return self::META === $control['kind'] ? __( 'Hidden', 'blt-toolkit' ) : __( 'Allowed', 'blt-toolkit' );
			case 'indeterminate':
				return __( 'Partly allowed', 'blt-toolkit' );
		}

		return self::META === $control['kind'] ? __( 'Shown', 'blt-toolkit' ) : __( 'Not allowed', 'blt-toolkit' );
	}

	/**
	 * Whether a control can be changed in Simple view at all (not the
	 * summary, not a read-only content row).
	 *
	 * @param array $control Control.
	 * @return bool
	 */
	public static function changeable( array $control ) {
		if ( self::SUMMARY === $control['kind'] ) {
			return false;
		}

		return ! ( self::LEVEL === $control['kind'] && '' !== $control['readonly'] );
	}

	/**
	 * Forget the definitions built this request (after a role or post type
	 * change, and between tests).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$definitions    = null;
		self::$section_labels = array();
	}

	/**
	 * The type map of a content group for BLT_Toolkit_Simple_Mapper: its
	 * capability strings, the read-only marker when `create_posts` is
	 * distinct (RS-13, `blt_readonly_{plural}` after the `edit_{plural}`
	 * string, as the catalog names it), and each attached taxonomy group
	 * with its own capabilities (SPEC 10.3). Pure.
	 *
	 * @param array $group      A content group (BLT_Toolkit_Capability_Catalog::content_groups()).
	 * @param array $taxonomies Taxonomy groups (taxonomy_groups()).
	 * @param array $core       Capability names (name => anything) a taxonomy's
	 *                          `assign_terms` is never dedicated to: core's, and
	 *                          every content type's (content_controls()), so a
	 *                          level change on one row never grants another
	 *                          type's capability.
	 * @return array
	 */
	public static function type_map( array $group, array $taxonomies, array $core = array() ) {
		$caps = isset( $group['caps'] ) && is_array( $group['caps'] ) ? $group['caps'] : array();
		$out  = array(
			'caps'       => array(),
			'marker'     => '',
			'taxonomies' => array(),
		);

		foreach ( BLT_Toolkit_Simple_Mapper::LEVEL_KEYS as $key ) {
			if ( isset( $caps[ $key ] ) && is_scalar( $caps[ $key ] ) && '' !== (string) $caps[ $key ] ) {
				$out['caps'][ $key ] = (string) $caps[ $key ];
			}
		}

		$edit = isset( $out['caps']['edit_posts'] ) ? $out['caps']['edit_posts'] : '';

		if ( 0 === strpos( $edit, 'edit_' ) && strlen( $edit ) > 5 && BLT_Toolkit_Simple_Mapper::create_distinct( $out ) ) {
			$out['marker'] = BLT_Toolkit_Simple_Mapper::marker_name( substr( $edit, 5 ) );
		}

		$types = isset( $group['types'] ) ? array_map( 'strval', (array) $group['types'] ) : array();
		$own   = array_flip( array_values( $out['caps'] ) );

		foreach ( $taxonomies as $tax_group ) {
			$objects = isset( $tax_group['object_types'] ) ? array_map( 'strval', (array) $tax_group['object_types'] ) : array();

			if ( array() === array_intersect( $types, $objects ) || empty( $tax_group['caps'] ) ) {
				continue;
			}

			$tax_caps = (array) $tax_group['caps'];
			$assign   = isset( $tax_caps['assign_terms'] ) ? (string) $tax_caps['assign_terms'] : '';
			$key      = isset( $tax_group['primary'] ) ? (string) $tax_group['primary'] : (string) $tax_group['id'];

			$out['taxonomies'][ $key ] = array(
				'label'            => isset( $tax_group['label'] ) ? (string) $tax_group['label'] : $key,
				'manage_terms'     => isset( $tax_caps['manage_terms'] ) ? (string) $tax_caps['manage_terms'] : '',
				'edit_terms'       => isset( $tax_caps['edit_terms'] ) ? (string) $tax_caps['edit_terms'] : '',
				'delete_terms'     => isset( $tax_caps['delete_terms'] ) ? (string) $tax_caps['delete_terms'] : '',
				'assign_terms'     => $assign,
				'dedicated'        => ! empty( $tax_group['dedicated'] ),
				// A core capability (edit_posts) or one of the row's own
				// strings is not the taxonomy's to grant separately.
				'assign_dedicated' => '' !== $assign && ! isset( $core[ $assign ] ) && ! isset( $own[ $assign ] ),
			);
		}

		return $out;
	}

	// ---------- Built-in controls ----------

	/**
	 * Basics (Appendix A).
	 *
	 * @return array[]
	 */
	private static function basics_controls() {
		return array(
			array(
				'id'      => 'read',
				'section' => 'basics',
				'label'   => __( 'Can sign in to the dashboard', 'blt-toolkit' ),
				'help'    => __( 'Reach the dashboard and edit their own profile. Every other dashboard permission needs this.', 'blt-toolkit' ),
				'caps'    => array( 'read' ),
			),
			array(
				'id'      => self::TOOLBAR_ID,
				'section' => 'basics',
				'kind'    => self::META,
				'label'   => __( 'Hide the toolbar on the site', 'blt-toolkit' ),
				'help'    => __( 'Role setting, not a capability. Applies only to people whose every role hides it, since the most permissive role wins. Someone switched into such a user still gets a Switch back link at the foot of the page.', 'blt-toolkit' ),
				'caps'    => array(),
			),
		);
	}

	/**
	 * One row per visible content capability group (RS-10 to RS-13).
	 *
	 * @return array[]
	 */
	private static function content_controls() {
		$groups     = BLT_Toolkit_Capability_Catalog::content_groups();
		$taxonomies = BLT_Toolkit_Capability_Catalog::taxonomy_groups();
		$core       = BLT_Toolkit_Capability_Catalog::core_caps();
		$candidates = self::isolation_candidates();
		$out        = array();

		// A taxonomy whose assign_terms is another content type's capability
		// (edit_events on a taxonomy shared by Events and Books) is not
		// dedicated: Books' level must not grant it (SPEC 10.3).
		foreach ( $groups as $group ) {
			foreach ( isset( $group['caps'] ) ? (array) $group['caps'] : array() as $string ) {
				if ( is_scalar( $string ) && '' !== (string) $string ) {
					$core[ (string) $string ] = true;
				}
			}
		}

		foreach ( $groups as $group ) {
			if ( ! empty( $group['internal'] ) ) {
				continue;
			}

			$map      = self::type_map( $group, $taxonomies, $core );
			$types    = array_map( 'strval', (array) $group['types'] );
			$readonly = '';
			$labels   = array();

			if ( ! empty( $group['excluded_from_levels'] ) ) {
				$readonly = self::READONLY_EXCLUDED;
			} elseif ( empty( $group['map_meta_cap'] ) ) {
				$readonly = self::READONLY_OWNERSHIP;
			} elseif ( self::isolation_failed( $group, $map ) ) {
				$readonly = self::READONLY_ISOLATION;
			}

			foreach ( $map['taxonomies'] as $tax => $entry ) {
				$labels[ $tax ] = $entry['label'];
			}

			$also = array();

			foreach ( isset( $group['also_used_by'] ) ? (array) $group['also_used_by'] : array() as $names ) {
				$also = array_merge( $also, (array) $names );
			}

			$isolated = array();

			foreach ( $types as $type ) {
				if ( self::is_isolated( $type ) ) {
					$isolated[ $type ] = isset( $group['type_labels'][ $type ] ) ? (string) $group['type_labels'][ $type ] : $type;
				}
			}

			$out[] = array(
				'id'              => (string) $group['id'],
				'section'         => 'content',
				'kind'            => self::LEVEL,
				'label'           => (string) $group['label'],
				'plural'          => (string) $group['primary_label'],
				'primary'         => (string) $group['primary'],
				'types'           => $types,
				'type_labels'     => isset( $group['type_labels'] ) ? (array) $group['type_labels'] : array(),
				'map'             => $map,
				'readonly'        => $readonly,
				'strings'         => array_values( array_unique( array_diff( array_map( 'strval', array_values( (array) $group['caps'] ) ), array( 'read' ) ) ) ),
				'foreign'         => array_values( array_unique( array_map( 'strval', isset( $group['foreign'] ) ? (array) $group['foreign'] : array() ) ) ),
				'taxonomies'      => $labels,
				'create_distinct' => BLT_Toolkit_Simple_Mapper::create_distinct( $map ),
				'candidates'      => array_intersect_key( $candidates, array_flip( $types ) ),
				'isolated'        => $isolated,
				'also'            => array_values( array_unique( $also ) ),
				'depends'         => array(
					array(
						'control' => 'upload_files',
						'min'     => 'contribute',
						// RS-17's wording.
						'text'    => __( 'People with this role usually also need to upload images.', 'blt-toolkit' ),
					),
				),
			);
		}

		return $out;
	}

	/**
	 * The site and people controls of Appendix A, in its order, after Basics.
	 *
	 * @return array[]
	 */
	private static function site_controls() {
		$list_users = __( 'The Users screen, where this starts, needs "See the list of users".', 'blt-toolkit' );
		$edit_roles = __( 'This is used from the Roles screen, which needs "Edit roles (Simple)".', 'blt-toolkit' );
		$file_edit  = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? __( 'File editing is turned off on this site (DISALLOW_FILE_EDIT), so this has no effect here.', 'blt-toolkit' ) : '';

		// Section, ID, label, help, capabilities, extra keys.
		$rows = array(
			array( 'media', 'upload_files', __( 'Upload files', 'blt-toolkit' ), __( 'Add images and other files to the Media Library.', 'blt-toolkit' ), array( 'upload_files' ) ),
			array( 'media', 'moderate_comments', __( 'Moderate comments', 'blt-toolkit' ), __( 'Approve, edit, mark as spam and delete everyone\'s comments.', 'blt-toolkit' ), array( 'moderate_comments' ) ),
			array( 'media', 'manage_categories', __( 'Manage categories and tags', 'blt-toolkit' ), __( 'Add, rename and delete categories and tags, for Posts and every content type that uses the same ones.', 'blt-toolkit' ), array( 'manage_categories' ) ),
			array( 'appearance', 'edit_theme_options', __( 'Customize menus, widgets and the site editor', 'blt-toolkit' ), __( 'Change menus, widgets, the Customizer and the site editor\'s templates and styles.', 'blt-toolkit' ), array( 'edit_theme_options' ) ),
			array( 'appearance', 'switch_themes', __( 'Change the active theme', 'blt-toolkit' ), __( 'Switch to another installed theme.', 'blt-toolkit' ), array( 'switch_themes' ) ),
			array( 'appearance', 'manage_themes', __( 'Install, update or delete themes', 'blt-toolkit' ), __( 'Themes run code on the server, so this can be used to take over the whole site.', 'blt-toolkit' ), array( 'install_themes', 'update_themes', 'delete_themes' ) ),
			array( 'appearance', 'edit_themes', __( 'Edit theme files', 'blt-toolkit' ), __( 'Change a theme\'s code in the dashboard\'s file editor.', 'blt-toolkit' ), array( 'edit_themes' ), array( 'note' => $file_edit ) ),
			array( 'plugins', 'activate_plugins', __( 'Activate and deactivate plugins', 'blt-toolkit' ), __( 'Turn plugins on and off, including security plugins and BLT Toolkit itself.', 'blt-toolkit' ), array( 'activate_plugins' ) ),
			array( 'plugins', 'manage_plugins', __( 'Install, update or delete plugins', 'blt-toolkit' ), __( 'Plugins run code on the server, so this can be used to take over the whole site.', 'blt-toolkit' ), array( 'install_plugins', 'update_plugins', 'delete_plugins' ) ),
			array( 'plugins', 'edit_plugins', __( 'Edit plugin files', 'blt-toolkit' ), __( 'Change a plugin\'s code in the dashboard\'s file editor.', 'blt-toolkit' ), array( 'edit_plugins' ), array( 'note' => $file_edit ) ),
			array( 'site', 'manage_options', __( 'Change settings', 'blt-toolkit' ), __( 'Change every site setting, including the site address, who may register and the role new users get.', 'blt-toolkit' ), array( 'manage_options' ) ),
			array( 'site', 'update_core', __( 'Update WordPress', 'blt-toolkit' ), __( 'Install WordPress updates.', 'blt-toolkit' ), array( 'update_core' ) ),
			array( 'site', 'import', __( 'Import content', 'blt-toolkit' ), __( 'Use the importers on Tools > Import.', 'blt-toolkit' ), array( 'import' ) ),
			array( 'site', 'export', __( 'Export content', 'blt-toolkit' ), __( 'Download the site\'s content from Tools > Export.', 'blt-toolkit' ), array( 'export' ) ),
			array( 'site', 'view_site_health_checks', __( 'View Site Health', 'blt-toolkit' ), __( 'See the Site Health screen and its report.', 'blt-toolkit' ), array( 'view_site_health_checks' ) ),
			array( 'site', 'unfiltered_html', __( 'Publish unfiltered HTML (scripts)', 'blt-toolkit' ), __( 'Publish scripts that run in the browser of everyone who views the content, administrators included.', 'blt-toolkit' ), array( 'unfiltered_html' ) ),
			array( 'site', 'unfiltered_upload', __( 'Upload any file type', 'blt-toolkit' ), __( 'Upload any type of file, including files the server may run. Works only when wp-config.php also defines ALLOW_UNFILTERED_UPLOADS.', 'blt-toolkit' ), array( 'unfiltered_upload' ) ),
			array( 'people', 'list_users', __( 'See the list of users', 'blt-toolkit' ), __( 'Open the Users screen and see who has an account.', 'blt-toolkit' ), array( 'list_users' ) ),
			array( 'people', 'create_users', __( 'Add users', 'blt-toolkit' ), __( 'Create new user accounts.', 'blt-toolkit' ), array( 'create_users' ) ),
			array(
				'people',
				'edit_users',
				__( 'Edit users', 'blt-toolkit' ),
				__( 'Change other people\'s profiles, email addresses and passwords. BLT Toolkit still stops anyone acting on someone more powerful than themselves.', 'blt-toolkit' ),
				array( 'edit_users' ),
				array(
					'depends' => array(
						array(
							'control' => 'list_users',
							'text'    => $list_users,
						),
					),
				),
			),
			array(
				'people',
				'delete_users',
				__( 'Delete users', 'blt-toolkit' ),
				__( 'Delete user accounts, and remove people from the site.', 'blt-toolkit' ),
				array( 'delete_users', 'remove_users' ),
				array(
					'depends' => array(
						array(
							'control' => 'list_users',
							'text'    => $list_users,
						),
					),
				),
			),
			array(
				'people',
				'promote_users',
				__( 'Change users\' roles', 'blt-toolkit' ),
				__( 'Give people roles, up to the most powerful role this role may give.', 'blt-toolkit' ),
				array( 'promote_users' ),
				array(
					'depends' => array(
						array(
							'control' => 'list_users',
							'text'    => $list_users,
						),
					),
				),
			),
			array(
				'people',
				'blt_switch_users',
				__( 'Switch to other users', 'blt-toolkit' ),
				__( 'Sign in as other users without their password, and switch back. Never into someone more powerful.', 'blt-toolkit' ),
				array( 'blt_switch_users' ),
				array(
					'depends' => array(
						array(
							'control' => 'list_users',
							// Appendix A: the hint "needs See the list of users".
							'text'    => __( 'Switching starts from the Users screen, which needs "See the list of users".', 'blt-toolkit' ),
						),
					),
					'note'    => self::module_note( 'switch' ),
				),
			),
			array( 'people', 'blt_change_usernames', __( 'Change other users\' usernames', 'blt-toolkit' ), __( 'Rename other people\'s accounts, which signs them out.', 'blt-toolkit' ), array( 'blt_change_usernames' ), array( 'note' => self::module_note( 'usernames' ) ) ),
			// X-6: self-service is a capability, so it is listed here.
			array( 'people', 'blt_change_own_username', __( 'Change their own username', 'blt-toolkit' ), __( 'Change their own username on their profile or with the username form, within the rules in Settings > Usernames.', 'blt-toolkit' ), array( 'blt_change_own_username' ), array( 'note' => self::module_note( 'usernames' ) ) ),
			array( 'blt', 'blt_edit_roles', __( 'Edit roles (Simple)', 'blt-toolkit' ), __( 'Open the Roles screen and change roles in Simple view, never beyond their own permissions.', 'blt-toolkit' ), array( 'blt_edit_roles' ) ),
			array(
				'blt',
				'blt_edit_capabilities',
				__( 'Edit capabilities (Advanced, per-user)', 'blt-toolkit' ),
				__( 'Use Advanced view, add and delete capabilities, and change single users\' capabilities.', 'blt-toolkit' ),
				array( 'blt_edit_capabilities' ),
				array(
					'depends' => array(
						array(
							'control' => 'blt_edit_roles',
							'text'    => $edit_roles,
						),
					),
				),
			),
			array(
				'blt',
				'blt_create_roles',
				__( 'Create roles', 'blt-toolkit' ),
				__( 'Add roles, from scratch or as a copy of a role no more powerful than their own.', 'blt-toolkit' ),
				array( 'blt_create_roles' ),
				array(
					'depends' => array(
						array(
							'control' => 'blt_edit_roles',
							'text'    => $edit_roles,
						),
					),
				),
			),
			array(
				'blt',
				'blt_delete_roles',
				__( 'Delete roles', 'blt-toolkit' ),
				__( 'Delete and merge roles, moving their users to other roles.', 'blt-toolkit' ),
				array( 'blt_delete_roles' ),
				array(
					'depends' => array(
						array(
							'control' => 'blt_edit_roles',
							'text'    => $edit_roles,
						),
					),
				),
			),
			array( 'blt', 'blt_reset_roles', __( 'Reset, restore and import roles', 'blt-toolkit' ), __( 'Use Tools to reset roles, restore snapshots and import roles.', 'blt-toolkit' ), array( 'blt_reset_roles' ) ),
			array( 'blt', 'blt_view_toolkit_log', __( 'View Toolkit activity', 'blt-toolkit' ), __( 'See and export BLT Toolkit\'s activity log.', 'blt-toolkit' ), array( 'blt_view_toolkit_log' ) ),
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[] = array_merge(
				array(
					'id'      => $row[1],
					'section' => $row[0],
					'kind'    => self::TOGGLE,
					'label'   => $row[2],
					'help'    => $row[3],
					'caps'    => $row[4],
				),
				isset( $row[5] ) ? $row[5] : array()
			);
		}

		return $out;
	}

	/**
	 * A note for a control whose module is not running.
	 *
	 * @param string $module Module key.
	 * @return string
	 */
	private static function module_note( $module ) {
		if ( class_exists( 'BLT_Toolkit_Plugin', false ) && ! BLT_Toolkit_Plugin::is_active( $module ) ) {
			return __( 'That part of BLT Toolkit is turned off on this site, so this has no effect until it is turned on.', 'blt-toolkit' );
		}

		return '';
	}

	// ---------- Normalizing ----------

	/**
	 * A control from the list or the filter, made complete, or null when it
	 * cannot be used.
	 *
	 * @param array $control Raw control.
	 * @return array|null
	 */
	private static function normalize( array $control ) {
		$id = isset( $control['id'] ) && is_scalar( $control['id'] ) ? strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $control['id'] ) ) : '';

		if ( '' === $id || self::OTHER_ID === $id || ! isset( $control['label'] ) || ! is_scalar( $control['label'] ) || '' === trim( (string) $control['label'] ) ) {
			return null;
		}

		if ( isset( $control['available'] ) ) {
			$available = is_callable( $control['available'] ) ? (bool) call_user_func( $control['available'] ) : (bool) $control['available'];

			if ( ! $available ) {
				return null;
			}
		}

		$kind    = isset( $control['kind'] ) && in_array( $control['kind'], array( self::LEVEL, self::TOGGLE, self::META ), true ) ? $control['kind'] : self::TOGGLE;
		$section = isset( $control['section'] ) && is_scalar( $control['section'] ) ? strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $control['section'] ) ) : '';

		if ( '' === $section || 'other' === $section ) {
			$section = 'site';
		}

		if ( ! in_array( $section, self::SECTIONS, true ) && ! isset( self::$section_labels[ $section ] ) ) {
			self::$section_labels[ $section ] = isset( $control['section_label'] ) && is_scalar( $control['section_label'] ) && '' !== trim( (string) $control['section_label'] )
				? trim( (string) $control['section_label'] )
				: BLT_Toolkit_Capability_Catalog::humanize( $section );
		}

		$out = array(
			'id'       => $id,
			'section'  => $section,
			'kind'     => $kind,
			'label'    => trim( (string) $control['label'] ),
			'help'     => isset( $control['help'] ) && is_scalar( $control['help'] ) ? (string) $control['help'] : '',
			'note'     => isset( $control['note'] ) && is_scalar( $control['note'] ) ? (string) $control['note'] : '',
			'depends'  => self::clean_depends( isset( $control['depends'] ) ? $control['depends'] : array() ),
			'readonly' => '',
		);

		if ( self::LEVEL === $kind ) {
			if ( ! isset( $control['map'] ) || ! is_array( $control['map'] ) ) {
				return null;
			}

			$out['map']      = $control['map'];
			$out['universe'] = BLT_Toolkit_Simple_Mapper::universe( $control['map'] );
			$out['readonly'] = isset( $control['readonly'] ) && is_string( $control['readonly'] ) ? $control['readonly'] : '';

			// A read-only row lists the strings it would cover; a changeable
			// one exactly the mapper's universe.
			if ( '' !== $out['readonly'] && isset( $control['strings'] ) ) {
				$out['universe'] = BLT_Toolkit_Role_Store::clean_names( (array) $control['strings'] );
			}

			foreach ( array( 'plural', 'primary' ) as $key ) {
				$out[ $key ] = isset( $control[ $key ] ) && is_scalar( $control[ $key ] ) ? (string) $control[ $key ] : $out['label'];
			}

			foreach ( array( 'types', 'type_labels', 'foreign', 'taxonomies', 'candidates', 'isolated', 'also' ) as $key ) {
				$out[ $key ] = isset( $control[ $key ] ) && is_array( $control[ $key ] ) ? $control[ $key ] : array();
			}

			$out['create_distinct'] = BLT_Toolkit_Simple_Mapper::create_distinct( $control['map'] );

			if ( array() === $out['taxonomies'] ) {
				foreach ( BLT_Toolkit_Simple_Mapper::toggles( $control['map'] ) as $tax => $unused ) {
					$out['taxonomies'][ $tax ] = isset( $control['map']['taxonomies'][ $tax ]['label'] ) ? (string) $control['map']['taxonomies'][ $tax ]['label'] : $tax;
				}
			}
		} elseif ( self::META === $kind ) {
			// The one role setting Simple view shows (SPEC 9.5).
			if ( self::TOOLBAR_ID !== $id ) {
				return null;
			}

			$out['universe'] = array();
		} else {
			$out['universe'] = BLT_Toolkit_Role_Store::clean_names( isset( $control['caps'] ) ? (array) $control['caps'] : array() );

			if ( array() === $out['universe'] ) {
				return null;
			}
		}

		if ( self::LEVEL === $kind && array() === $out['universe'] ) {
			return null;
		}

		$tiers       = isset( $control['tier'] ) && in_array( $control['tier'], array( BLT_Toolkit_Caps::CRITICAL, BLT_Toolkit_Caps::ELEVATED, BLT_Toolkit_Caps::STANDARD ), true ) ? array( $control['tier'] ) : array();
		$out['tier'] = self::META === $kind ? '' : self::highest_tier( $out['universe'], $tiers );

		return $out;
	}

	/**
	 * RS-7 hints, cleaned.
	 *
	 * @param mixed $depends Raw list.
	 * @return array[]
	 */
	private static function clean_depends( $depends ) {
		$out = array();

		foreach ( is_array( $depends ) ? $depends : array() as $depend ) {
			if ( ! is_array( $depend ) || empty( $depend['control'] ) || ! is_scalar( $depend['control'] ) || empty( $depend['text'] ) || ! is_scalar( $depend['text'] ) ) {
				continue;
			}

			$out[] = array(
				'control' => strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $depend['control'] ) ),
				'min'     => isset( $depend['min'] ) && BLT_Toolkit_Simple_Mapper::is_level( $depend['min'] ) ? $depend['min'] : 'view',
				'text'    => (string) $depend['text'],
			);
		}

		return $out;
	}

	/**
	 * The highest tier among capabilities (SEC-11), or the given ones.
	 *
	 * @param string[] $caps  Capabilities.
	 * @param string[] $given Tiers already known.
	 * @return string
	 */
	private static function highest_tier( array $caps, array $given ) {
		$rank = array(
			BLT_Toolkit_Caps::STANDARD => 0,
			BLT_Toolkit_Caps::ELEVATED => 1,
			BLT_Toolkit_Caps::CRITICAL => 2,
		);
		$best = BLT_Toolkit_Caps::STANDARD;

		foreach ( array_merge( $given, array_map( array( 'BLT_Toolkit_Caps', 'tier' ), $caps ) ) as $tier ) {
			if ( isset( $rank[ $tier ] ) && $rank[ $tier ] > $rank[ $best ] ) {
				$best = $tier;
			}
		}

		return $best;
	}

	/**
	 * Controls sorted by section (SPEC 13.2), keeping their order within one.
	 *
	 * @param array $controls ID => control.
	 * @return array
	 */
	private static function in_section_order( array $controls ) {
		$order = array_keys( self::sections_order() );
		$out   = array();

		foreach ( $order as $section ) {
			foreach ( $controls as $id => $control ) {
				if ( $control['section'] === $section ) {
					$out[ $id ] = $control;
				}
			}
		}

		return $out;
	}

	/**
	 * Section IDs in order, without labels (no translation needed).
	 *
	 * @return array<string,true>
	 */
	private static function sections_order() {
		$out = array();

		foreach ( self::SECTIONS as $id ) {
			if ( 'other' === $id ) {
				foreach ( array_keys( self::$section_labels ) as $extra ) {
					$out[ $extra ] = true;
				}
			}

			$out[ $id ] = true;
		}

		return $out;
	}

	// ---------- State ----------

	/**
	 * A control's state for a role.
	 *
	 * @param array $control Control.
	 * @param array $caps    The role's capabilities.
	 * @param array $meta    The role's metadata.
	 * @return string|array
	 */
	private static function state_of( array $control, array $caps, array $meta ) {
		if ( self::META === $control['kind'] ) {
			return ! empty( $meta['hide_toolbar'] ) ? 'on' : 'off';
		}

		if ( self::LEVEL === $control['kind'] ) {
			if ( '' !== $control['readonly'] ) {
				return array(
					'level' => BLT_Toolkit_Simple_Mapper::CUSTOM,
					'mods'  => array(
						'can_delete' => false,
						'manage'     => array(),
					),
				);
			}

			return BLT_Toolkit_Simple_Mapper::detect( $control['map'], $caps );
		}

		return BLT_Toolkit_Simple_Mapper::toggle_state( $control['universe'], $caps );
	}

	/**
	 * Whether a control counts as "on" for the RS-7 hints: a toggle on or
	 * partly on, the toolbar never, a level row at or above a level.
	 *
	 * @param array  $control Control with state.
	 * @param string $min     Lowest level that counts.
	 * @return bool
	 */
	public static function is_on( array $control, $min = 'view' ) {
		if ( self::LEVEL === $control['kind'] ) {
			$level = is_array( $control['state'] ) ? $control['state']['level'] : '';
			$rank  = array_flip( BLT_Toolkit_Simple_Mapper::LEVELS );

			return isset( $rank[ $level ], $rank[ $min ] ) && $rank[ $level ] >= $rank[ $min ];
		}

		if ( self::TOGGLE === $control['kind'] ) {
			return 'off' !== $control['state'];
		}

		return false;
	}

	/**
	 * The RS-7 hints of a control, each with `active`: the control is on and
	 * the one it needs is off. "Can sign in to the dashboard" carries one more:
	 * active while it is off and any dashboard permission is on.
	 *
	 * @param array $control  Control with state.
	 * @param array $controls Every control with state.
	 * @return array[] Each: control (needed), min, text, active, when (control ID => min level, for the script).
	 */
	private static function hints_for( array $control, array $controls ) {
		$out = array();

		foreach ( $control['depends'] as $depend ) {
			if ( ! isset( $controls[ $depend['control'] ] ) || ! self::changeable( $control ) ) {
				continue;
			}

			$out[] = array(
				'control' => $depend['control'],
				'text'    => $depend['text'],
				'when'    => array( $control['id'] => $depend['min'] ),
				'active'  => self::is_on( $control, $depend['min'] ) && ! self::is_on( $controls[ $depend['control'] ] ),
			);
		}

		if ( 'read' === $control['id'] ) {
			$when   = array();
			$active = false;

			foreach ( $controls as $id => $other ) {
				if ( 'read' === $id || ! self::changeable( $other ) || self::META === $other['kind'] || in_array( $id, array( 'blt_change_own_username', 'unfiltered_html', 'unfiltered_upload' ), true ) ) {
					continue;
				}

				$min         = self::LEVEL === $other['kind'] ? ( $other['create_distinct'] ? 'view' : 'contribute' ) : 'view';
				$when[ $id ] = $min;
				$active      = $active || self::is_on( $other, $min );
			}

			if ( array() !== $when ) {
				$out[] = array(
					'control' => 'read',
					'text'    => __( 'Without this, people with this role cannot reach the dashboard to use the permissions below.', 'blt-toolkit' ),
					'when'    => $when,
					'active'  => $active && ! self::is_on( $control ),
				);
			}
		}

		return $out;
	}

	/**
	 * "Other plugin permissions" (SPEC 13.2): capabilities from plugins (and
	 * orphans) that no Simple control covers, and how many of them the role
	 * has.
	 *
	 * @param array $controls Controls with state.
	 * @param array $caps     The role's capabilities.
	 * @return array
	 */
	private static function other_summary( array $controls, array $caps ) {
		$covered = array();

		foreach ( $controls as $control ) {
			foreach ( $control['universe'] as $cap ) {
				$covered[ $cap ] = true;
			}
		}

		$other = array();

		foreach ( BLT_Toolkit_Capability_Catalog::all() as $cap => $entry ) {
			$cap = (string) $cap;

			if ( isset( $covered[ $cap ] ) || ! empty( $entry['deprecated'] ) || ! empty( $entry['multisite'] ) ) {
				continue;
			}

			if ( in_array( $entry['source'], array( BLT_Toolkit_Capability_Catalog::SOURCE_PLUGIN, BLT_Toolkit_Capability_Catalog::SOURCE_ORPHAN, BLT_Toolkit_Capability_Catalog::SOURCE_TAXONOMY ), true ) ) {
				$other[] = $cap;
			}
		}

		sort( $other, SORT_STRING );

		$held = array();

		foreach ( $other as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				$held[] = $cap;
			}
		}

		return array(
			'id'       => self::OTHER_ID,
			'section'  => 'other',
			'kind'     => self::SUMMARY,
			'label'    => __( 'Other plugin permissions', 'blt-toolkit' ),
			'help'     => '',
			'note'     => '',
			'depends'  => array(),
			'readonly' => '',
			'tier'     => '',
			'universe' => $other,
			'granted'  => $held,
			'denied'   => array(),
			'state'    => '',
			'hints'    => array(),
		);
	}

	// ---------- Submissions ----------

	/**
	 * A submitted toggle value: `on`, `off`, `indeterminate`, or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function toggle_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'on' : 'off';
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( 'on', '1', 'true', 'yes' ), true ) ) {
			return 'on';
		}

		if ( in_array( $value, array( 'off', '0', 'false', 'no', '' ), true ) ) {
			return 'off';
		}

		return 'indeterminate' === $value ? 'indeterminate' : null;
	}

	/**
	 * The level and modifiers a level row was set to, or null when it is
	 * unchanged, Custom or not a level.
	 *
	 * @param array $control Level row with state.
	 * @param mixed $value   Submitted array( 'level', 'can_delete', 'manage', 'mods_set' ), or a level string.
	 * @return array{level:string,mods:array}|null
	 */
	private static function level_target( array $control, $value ) {
		if ( is_scalar( $value ) ) {
			$value = array( 'level' => (string) $value );
		}

		if ( ! is_array( $value ) || ! isset( $value['level'] ) || ! BLT_Toolkit_Simple_Mapper::is_level( $value['level'] ) ) {
			return null;
		}

		$map   = $control['map'];
		$level = (string) $value['level'];
		$drawn = $control['state'];
		$mods  = array();

		if ( array_key_exists( 'can_delete', $value ) ) {
			$mods['can_delete'] = $value['can_delete'];
		}

		if ( isset( $value['manage'] ) && is_array( $value['manage'] ) ) {
			$mods['manage'] = $value['manage'];
		}

		// Without the script, a new level keeps the modifiers as drawn
		// unless the person changed them: then the level's defaults apply.
		if ( empty( $value['mods_set'] ) && $level !== $drawn['level'] && self::same_mods( $map, $drawn['mods'], $mods ) ) {
			$mods = array();
		}

		$mods = BLT_Toolkit_Simple_Mapper::normalize_mods( $map, $level, $mods );

		if ( $level === $drawn['level'] && BLT_Toolkit_Simple_Mapper::normalize_mods( $map, $level, $drawn['mods'] ) === $mods ) {
			return null;
		}

		return array(
			'level' => $level,
			'mods'  => $mods,
		);
	}

	/**
	 * Whether submitted modifiers are the ones drawn (as form values).
	 *
	 * @param array $map   Type map.
	 * @param array $drawn Drawn modifiers.
	 * @param array $mods  Submitted modifiers.
	 * @return bool
	 */
	private static function same_mods( array $map, array $drawn, array $mods ) {
		$on = static function ( $value ) {
			return true === $value || 1 === $value || ( is_string( $value ) && in_array( strtolower( $value ), array( '1', 'on', 'true', 'yes' ), true ) );
		};

		if ( array_key_exists( 'can_delete', $mods ) && $on( $mods['can_delete'] ) !== ! empty( $drawn['can_delete'] ) ) {
			return false;
		}

		foreach ( array_keys( BLT_Toolkit_Simple_Mapper::toggles( $map ) ) as $tax ) {
			if ( isset( $mods['manage'] ) && array_key_exists( $tax, $mods['manage'] ) && $on( $mods['manage'][ $tax ] ) !== ! empty( $drawn['manage'][ $tax ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The refusal when two changed controls want one capability both ways.
	 *
	 * @param array  $one   One control.
	 * @param array  $other The other.
	 * @param string $cap   Capability.
	 * @return WP_Error
	 */
	private static function overlap_error( array $one, array $other, $cap ) {
		return new WP_Error(
			'blt_toolkit_simple_overlap',
			sprintf(
				/* translators: 1: control name, 2: other control name, 3: capability name. */
				__( '"%1$s" and "%2$s" share a capability (%3$s), and your changes set it both ways. Change one of them, save, then the other.', 'blt-toolkit' ),
				$one['label'],
				$other['label'],
				$cap
			),
			array(
				'status' => 409,
				'caps'   => array( (string) $cap ),
			)
		);
	}

	// ---------- WordPress reads ----------

	/**
	 * A role's capabilities (cap => value), or none.
	 *
	 * @param WP_Role|string $role Role or role ID.
	 * @return array
	 */
	private static function role_caps( $role ) {
		$obj = $role instanceof WP_Role ? $role : wp_roles()->get_role( (string) $role );

		return $obj instanceof WP_Role && is_array( $obj->capabilities ) ? $obj->capabilities : array();
	}

	/**
	 * Post types that may be given separate permissions (RS-30): type =>
	 * plural name, from BLT_Toolkit_Isolation when it is loaded.
	 *
	 * @return array<string,string>
	 */
	private static function isolation_candidates() {
		if ( ! class_exists( 'BLT_Toolkit_Isolation' ) || ! is_callable( array( 'BLT_Toolkit_Isolation', 'candidates' ) ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) BLT_Toolkit_Isolation::candidates() as $key => $value ) {
			$type = is_string( $key ) ? $key : ( is_string( $value ) ? $value : ( is_array( $value ) && isset( $value['type'] ) ? (string) $value['type'] : '' ) );

			if ( '' === $type || ! post_type_exists( $type ) ) {
				continue;
			}

			$object       = get_post_type_object( $type );
			$out[ $type ] = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;
		}

		return $out;
	}

	/**
	 * Whether a post type has separate permissions (RS-30).
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	private static function is_isolated( $type ) {
		return class_exists( 'BLT_Toolkit_Isolation' ) && is_callable( array( 'BLT_Toolkit_Isolation', 'is_isolated' ) ) && BLT_Toolkit_Isolation::is_isolated( (string) $type );
	}

	/**
	 * RS-33: a group of its own for an isolated type whose separate
	 * permissions did not apply (another plugin overrode them, or the type
	 * registered before BLT Toolkit loaded): its create capability is not
	 * distinct, so levels cannot be enforced. An isolated type still inside
	 * a shared group does not lock that group's row; the row says so.
	 *
	 * @param array $group Content group.
	 * @param array $map   Its type map.
	 * @return bool
	 */
	private static function isolation_failed( array $group, array $map ) {
		$types = array_map( 'strval', (array) $group['types'] );

		if ( 1 !== count( $types ) || ! self::is_isolated( $types[0] ) ) {
			return false;
		}

		if ( class_exists( 'BLT_Toolkit_Isolation' ) && is_callable( array( 'BLT_Toolkit_Isolation', 'failed' ) ) ) {
			$failed = (array) BLT_Toolkit_Isolation::failed();

			if ( in_array( $types[0], $failed, true ) || isset( $failed[ $types[0] ] ) ) {
				return true;
			}
		}

		return ! BLT_Toolkit_Simple_Mapper::create_distinct( $map );
	}
}
