<?php
/**
 * Role export and import: the pure parts of BLT_Toolkit_Role_Transfer
 * (RO-44, RO-45).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Role_Transfer.
 */
class Test_Role_Transfer extends TestCase {

	/**
	 * Fresh shimmed state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A site's roles: an event manager using an isolated type's strings, an
	 * editor and a role nobody exports.
	 *
	 * @return array
	 */
	private function site_roles() {
		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'           => true,
					'manage_options' => true,
				),
			),
			'event_manager' => array(
				'name'         => 'Event Manager',
				'capabilities' => array(
					'read'           => 1,
					'publish_events' => true,
					'edit_events'    => true,
					'upload_files'   => false,
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'read'       => true,
					'edit_posts' => true,
				),
			),
			'empty_role'    => array(
				'name'         => 'Empty',
				'capabilities' => array(),
			),
		);
	}

	/**
	 * A document as parse() returns it for the given roles.
	 *
	 * @param array $roles ID => array( name, capabilities ).
	 * @return string JSON.
	 */
	private function file_with( array $roles ) {
		return BLT_Toolkit_Role_Transfer::encode(
			array(
				'format'              => 'blt-toolkit-roles',
				'version'             => 1,
				'exported_at'         => '2026-10-22T10:00:00Z',
				'site'                => 'https://a.example/',
				'roles'               => $roles,
				'role_meta'           => array(),
				'isolated_post_types' => array(),
			)
		);
	}

	/**
	 * RO-44: the export document has exactly the SPEC's keys in order, only
	 * the selected roles (in site order), capabilities as booleans sorted by
	 * name, the selected roles' details, and only the isolated types a
	 * selected role mentions.
	 *
	 * @return void
	 */
	public function test_ro44_document_shape() {
		$doc = BLT_Toolkit_Role_Transfer::document(
			$this->site_roles(),
			array( 'event_manager', 'empty_role', 'no_such_role' ),
			array(
				'event_manager' => array(
					'description'  => 'Runs the calendar',
					'hide_toolbar' => true,
				),
				'editor'        => array(
					'description'  => 'Not exported',
					'hide_toolbar' => false,
				),
			),
			array(
				'event'   => array(
					'singular' => 'event',
					'plural'   => 'events',
					'since'    => 1700000000,
				),
				'product' => array(
					'singular' => 'product',
					'plural'   => 'products',
					'since'    => 1700000000,
				),
			),
			'https://a.example/',
			'2026-10-22T10:00:00Z'
		);

		$this->assertSame( array( 'format', 'version', 'exported_at', 'site', 'roles', 'role_meta', 'isolated_post_types' ), array_keys( $doc ) );
		$this->assertSame( 'blt-toolkit-roles', $doc['format'] );
		$this->assertSame( 1, $doc['version'] );
		$this->assertSame( array( 'event_manager', 'empty_role' ), array_keys( $doc['roles'] ) );
		$this->assertSame(
			array(
				'name'         => 'Event Manager',
				'capabilities' => array(
					'edit_events'    => true,
					'publish_events' => true,
					'read'           => true,
					'upload_files'   => false,
				),
			),
			$doc['roles']['event_manager']
		);
		$this->assertSame(
			array(
				'event_manager' => array(
					'description'  => 'Runs the calendar',
					'hide_toolbar' => true,
				),
			),
			$doc['role_meta']
		);
		$this->assertSame(
			array(
				'event' => array(
					'singular' => 'event',
					'plural'   => 'events',
				),
			),
			$doc['isolated_post_types']
		);
	}

	/**
	 * RO-44: the file is JSON whose containers are all objects (an empty
	 * capability list is `{}`), and reading it back gives the same roles.
	 *
	 * @return void
	 */
	public function test_ro44_encode_round_trip() {
		$doc  = BLT_Toolkit_Role_Transfer::document( $this->site_roles(), array( 'event_manager', 'empty_role' ), array(), array(), 'https://a.example/', '2026-10-22T10:00:00Z' );
		$json = BLT_Toolkit_Role_Transfer::encode( $doc );

		$this->assertStringContainsString( '"format": "blt-toolkit-roles"', $json );
		$this->assertStringContainsString( '"capabilities": {}', $json );
		$this->assertStringContainsString( '"role_meta": {}', $json );

		$parsed = BLT_Toolkit_Role_Transfer::parse( $json );

		$this->assertIsArray( $parsed );
		$this->assertSame( 1, $parsed['version'] );
		$this->assertSame( 'https://a.example/', $parsed['site'] );
		$this->assertSame( $doc['roles']['event_manager']['capabilities'], $parsed['roles']['event_manager']['capabilities'] );
		$this->assertSame( array(), $parsed['roles']['empty_role']['capabilities'] );
		$this->assertSame( '', $parsed['roles']['empty_role']['error'] );
	}

	/**
	 * RO-44: the download is named blt-toolkit-roles-{site}-{date}.json.
	 *
	 * @return void
	 */
	public function test_ro44_filename() {
		$this->assertSame( 'blt-toolkit-roles-example-com-2026-10-22.json', BLT_Toolkit_Role_Transfer::filename( 'example.com', '2026-10-22' ) );
		$this->assertSame( 'blt-toolkit-roles-localhost-9002-blog-2026-10-22.json', BLT_Toolkit_Role_Transfer::filename( 'localhost:9002/blog', '2026-10-22' ) );
		$this->assertSame( 'blt-toolkit-roles-site-undated.json', BLT_Toolkit_Role_Transfer::filename( '...', 'yesterday' ) );
	}

	/**
	 * RO-45: a file that is not this format, or of a version this code does
	 * not read, is refused as a whole with a readable reason.
	 *
	 * @return void
	 */
	public function test_ro45_format_and_version_checks() {
		$cases = array(
			''                                      => 'blt_toolkit_import_empty',
			"  \n"                                  => 'blt_toolkit_import_empty',
			'{"format": "blt-toolkit-roles", "version": 1,' => 'blt_toolkit_import_json',
			'["blt-toolkit-roles"]'                 => 'blt_toolkit_import_shape',
			'"blt-toolkit-roles"'                   => 'blt_toolkit_import_shape',
			'{"version": 1, "roles": {}}'           => 'blt_toolkit_import_format',
			'{"format": "ure-roles", "version": 1}' => 'blt_toolkit_import_format',
			'{"format": "blt-toolkit-roles"}'       => 'blt_toolkit_import_version',
			'{"format": "blt-toolkit-roles", "version": "1"}' => 'blt_toolkit_import_version',
			'{"format": "blt-toolkit-roles", "version": 0}' => 'blt_toolkit_import_version',
			'{"format": "blt-toolkit-roles", "version": 2}' => 'blt_toolkit_import_version',
			'{"format": "blt-toolkit-roles", "version": 1}' => 'blt_toolkit_import_roles',
			'{"format": "blt-toolkit-roles", "version": 1, "roles": []}' => 'blt_toolkit_import_roles',
			'{"format": "blt-toolkit-roles", "version": 1, "roles": {}}' => 'blt_toolkit_import_roles',
		);

		foreach ( $cases as $json => $code ) {
			$result = BLT_Toolkit_Role_Transfer::parse( $json );

			$this->assertInstanceOf( 'WP_Error', $result, 'Input: ' . $json );
			$this->assertSame( $code, $result->get_error_code(), 'Input: ' . $json );
			$this->assertNotSame( '', $result->get_error_message() );
		}

		$newer = BLT_Toolkit_Role_Transfer::parse( '{"format": "blt-toolkit-roles", "version": 2}' );

		$this->assertStringContainsString( 'version 2', $newer->get_error_message() );

		$format = BLT_Toolkit_Role_Transfer::parse( '{"format": "ure-roles", "version": 1}' );

		$this->assertStringContainsString( '"ure-roles"', $format->get_error_message() );
	}

	/**
	 * RO-45: a UTF-8 byte order mark is ignored, and a file over the size or
	 * role limit is refused.
	 *
	 * @return void
	 */
	public function test_ro45_bom_and_limits() {
		$json   = $this->file_with(
			array(
				'helper' => array(
					'name'         => 'Helper',
					'capabilities' => array( 'read' => true ),
				),
			)
		);
		$parsed = BLT_Toolkit_Role_Transfer::parse( "\xEF\xBB\xBF" . $json );

		$this->assertIsArray( $parsed );
		$this->assertSame( array( 'read' => true ), $parsed['roles']['helper']['capabilities'] );

		$too_large = BLT_Toolkit_Role_Transfer::parse( '{"pad": "' . str_repeat( 'x', BLT_Toolkit_Role_Transfer::MAX_BYTES ) . '"}' );

		$this->assertSame( 'blt_toolkit_import_too_large', $too_large->get_error_code() );

		$roles = array();

		for ( $i = 0; $i <= BLT_Toolkit_Role_Transfer::MAX_ROLES; $i++ ) {
			$roles[ 'role_' . $i ] = array(
				'name'         => 'Role ' . $i,
				'capabilities' => array(),
			);
		}

		$too_many = BLT_Toolkit_Role_Transfer::parse( $this->file_with( $roles ) );

		$this->assertSame( 'blt_toolkit_import_too_many', $too_many->get_error_code() );
	}

	/**
	 * RO-45: a malformed role is kept with an error and can only be
	 * skipped; `1` and `0` read as true and false; `do_not_allow` and
	 * `exist` are dropped; the other roles of the file are unaffected.
	 *
	 * @return void
	 */
	public function test_ro45_role_entries_are_checked() {
		$json = '{"format":"blt-toolkit-roles","version":1,"roles":{'
			. '"good":{"name":"Good","capabilities":{"read":1,"edit_posts":0,"do_not_allow":true,"exist":false}},'
			. '"bad id!":{"name":"Bad","capabilities":{}},'
			. '"9":{"name":"Digits","capabilities":{}},'
			. '"no_name":{"capabilities":{}},'
			. '"list_caps":{"name":"List","capabilities":["read"]},'
			. '"string_value":{"name":"String","capabilities":{"read":"yes"}},'
			. '"empty_list":{"name":"Empty","capabilities":[]}'
			. '}}';

		$parsed = BLT_Toolkit_Role_Transfer::parse( $json );

		$this->assertIsArray( $parsed );
		$this->assertSame(
			array(
				'name'         => 'Good',
				'capabilities' => array(
					'edit_posts' => false,
					'read'       => true,
				),
				'error'        => '',
			),
			$parsed['roles']['good']
		);
		$this->assertSame( 'id', $parsed['roles']['bad id!']['error'] );
		$this->assertSame( 'id', $parsed['roles']['9']['error'] );
		$this->assertSame( 'name', $parsed['roles']['no_name']['error'] );
		$this->assertSame( 'caps', $parsed['roles']['list_caps']['error'] );
		$this->assertSame( 'caps', $parsed['roles']['string_value']['error'] );
		$this->assertSame( '', $parsed['roles']['empty_list']['error'] );

		$classified = BLT_Toolkit_Role_Transfer::classify( $parsed['roles'], array() );

		$this->assertSame( 'invalid', $classified['list_caps']['status'] );
		$this->assertSame( array( 'skip' ), BLT_Toolkit_Role_Transfer::actions_for( $classified['list_caps']['status'] ) );
	}

	/**
	 * RO-45, RS-30: role details are read only for roles in the file, and
	 * every separate-permissions entry carries Phase 5's validation result
	 * (core types, invalid or equal names, strings core already uses).
	 *
	 * @return void
	 */
	public function test_ro45_role_meta_and_isolated_entries() {
		$json = '{"format":"blt-toolkit-roles","version":1,'
			. '"roles":{"helper":{"name":"Helper","capabilities":{}}},'
			. '"role_meta":{"helper":{"description":"Helps","hide_toolbar":true},"stranger":{"description":"x","hide_toolbar":true}},'
			. '"isolated_post_types":{'
			. '"event":{"singular":"event","plural":"events"},'
			. '"post":{"singular":"article","plural":"articles"},'
			. '"book":{"singular":"book","plural":"book"},'
			. '"guide":{"singular":"Guide!","plural":"guides"},'
			. '"leaf":{"singular":"leaf","plural":"pages"},'
			. '"Not A Type":{"singular":"x","plural":"xs"}'
			. '}}';

		$parsed = BLT_Toolkit_Role_Transfer::parse( $json );

		$this->assertSame(
			array(
				'helper' => array(
					'description'  => 'Helps',
					'hide_toolbar' => true,
				),
			),
			$parsed['role_meta']
		);

		$types = $parsed['isolated_post_types'];

		$this->assertSame( '', $types['event']['error'] );
		$this->assertSame( 'core_type', $types['post']['error'] );
		$this->assertSame( 'same', $types['book']['error'] );
		$this->assertSame( 'singular_invalid', $types['guide']['error'] );
		$this->assertSame( 'reserved', $types['leaf']['error'] );
		$this->assertSame( 'type', $types['Not A Type']['error'] );
	}

	/**
	 * RO-45: each role is New (no role with its ID here), Unchanged (same
	 * capabilities, values read as granted or not), or Changed with the
	 * capabilities added, removed and flipped.
	 *
	 * @return void
	 */
	public function test_ro45_classify_new_changed_unchanged() {
		$file = array(
			'event_manager' => array(
				'name'         => 'Event Manager',
				'capabilities' => array(
					'read'           => true,
					'publish_events' => true,
					'edit_events'    => true,
					'upload_files'   => false,
				),
				'error'        => '',
			),
			'editor'        => array(
				'name'         => 'Editor (A)',
				'capabilities' => array(
					'read'          => true,
					'edit_posts'    => false,
					'publish_posts' => true,
				),
				'error'        => '',
			),
			'helper'        => array(
				'name'         => 'Helper',
				'capabilities' => array( 'read' => true ),
				'error'        => '',
			),
			'empty_role'    => array(
				'name'         => 'Empty',
				'capabilities' => array(),
				'error'        => '',
			),
		);

		$rows = BLT_Toolkit_Role_Transfer::classify( $file, $this->site_roles() );

		// `read => 1` here and `read => true` in the file are the same grant.
		$this->assertSame( 'unchanged', $rows['event_manager']['status'] );
		$this->assertSame( 'unchanged', $rows['empty_role']['status'] );

		$this->assertSame( 'new', $rows['helper']['status'] );
		$this->assertSame( array( 'read' => true ), $rows['helper']['added'] );
		$this->assertNull( $rows['helper']['site_caps'] );

		$this->assertSame( 'changed', $rows['editor']['status'] );
		$this->assertSame( 'Editor', $rows['editor']['site_name'] );
		$this->assertSame( array( 'publish_posts' => true ), $rows['editor']['added'] );
		$this->assertSame( array(), $rows['editor']['removed'] );
		$this->assertSame(
			array(
				'edit_posts' => array(
					'from' => true,
					'to'   => false,
				),
			),
			$rows['editor']['flipped']
		);

		$less = BLT_Toolkit_Role_Transfer::classify(
			array(
				'editor' => array(
					'name'         => 'Editor',
					'capabilities' => array( 'read' => true ),
					'error'        => '',
				),
			),
			$this->site_roles()
		);

		$this->assertSame( 'changed', $less['editor']['status'] );
		$this->assertSame( array( 'edit_posts' => true ), $less['editor']['removed'] );
	}

	/**
	 * RO-45: the actions offered per classification, and the preselected
	 * one (a role that exists here changes only when someone asks).
	 *
	 * @return void
	 */
	public function test_ro45_actions_per_status() {
		$this->assertSame( array( 'create', 'skip' ), BLT_Toolkit_Role_Transfer::actions_for( 'new' ) );
		$this->assertSame( array( 'merge', 'replace', 'skip' ), BLT_Toolkit_Role_Transfer::actions_for( 'changed' ) );
		$this->assertSame( array( 'skip' ), BLT_Toolkit_Role_Transfer::actions_for( 'unchanged' ) );
		$this->assertSame( array( 'skip' ), BLT_Toolkit_Role_Transfer::actions_for( 'invalid' ) );

		$this->assertSame( 'create', BLT_Toolkit_Role_Transfer::default_action( 'new' ) );
		$this->assertSame( 'skip', BLT_Toolkit_Role_Transfer::default_action( 'changed' ) );
		$this->assertSame( 'skip', BLT_Toolkit_Role_Transfer::default_action( 'unchanged' ) );
	}

	/**
	 * RO-45: Create and Replace give exactly the file's capabilities; Merge
	 * keeps every capability the role has, adds the file's grants (a deny
	 * here becomes a grant) and the file's denies of capabilities the role
	 * does not mention, and never takes a grant away; Skip changes nothing.
	 *
	 * @return void
	 */
	public function test_ro45_each_action_does_what_it_says() {
		$site = array(
			'read'         => true,
			'edit_posts'   => true,
			'upload_files' => false,
			'local_only'   => true,
		);
		$file = array(
			'read'          => true,
			'edit_posts'    => false,
			'upload_files'  => true,
			'publish_posts' => true,
			'export'        => false,
		);

		$this->assertSame(
			array(
				'edit_posts'    => false,
				'export'        => false,
				'publish_posts' => true,
				'read'          => true,
				'upload_files'  => true,
			),
			BLT_Toolkit_Role_Transfer::target_caps( $site, $file, 'replace' )
		);
		$this->assertSame( BLT_Toolkit_Role_Transfer::target_caps( null, $file, 'create' ), BLT_Toolkit_Role_Transfer::target_caps( $site, $file, 'replace' ) );
		$this->assertSame(
			array(
				'edit_posts'    => true,
				'export'        => false,
				'local_only'    => true,
				'publish_posts' => true,
				'read'          => true,
				'upload_files'  => true,
			),
			BLT_Toolkit_Role_Transfer::target_caps( $site, $file, 'merge' )
		);
		$this->assertNull( BLT_Toolkit_Role_Transfer::target_caps( $site, $file, 'skip' ) );

		$merge = BLT_Toolkit_Role_Transfer::change_for( $site, $file, 'merge' );

		$this->assertSame(
			array(
				'add'    => array(
					'export'        => false,
					'publish_posts' => true,
					'upload_files'  => true,
				),
				'remove' => array(),
			),
			$merge
		);

		$replace = BLT_Toolkit_Role_Transfer::change_for( $site, $file, 'replace' );

		$this->assertSame( array( 'local_only' ), $replace['remove'] );
		$this->assertFalse( $replace['add']['edit_posts'] );
	}

	/**
	 * RO-45: apply() creates a new role with the file's name and
	 * capabilities, changes only the capabilities of a merged or replaced
	 * role, leaves skipped and unlisted roles exactly as stored, and refuses
	 * a create whose ID now exists (without case) or a change to a role that
	 * no longer exists.
	 *
	 * @return void
	 */
	public function test_ro45_apply() {
		$roles = $this->site_roles();
		$after = BLT_Toolkit_Role_Transfer::apply(
			$roles,
			array(
				'helper'        => array(
					'action' => 'create',
					'name'   => 'Helper',
					'caps'   => array( 'read' => true ),
				),
				'editor'        => array(
					'action' => 'merge',
					'name'   => 'Editor (A)',
					'caps'   => array( 'publish_posts' => true ),
				),
				'event_manager' => array(
					'action' => 'skip',
					'name'   => 'Event Manager',
					'caps'   => array(),
				),
			)
		);

		$this->assertSame(
			array(
				'name'         => 'Helper',
				'capabilities' => array( 'read' => true ),
			),
			$after['helper']
		);
		$this->assertSame( 'Editor', $after['editor']['name'] );
		$this->assertSame(
			array(
				'edit_posts'    => true,
				'publish_posts' => true,
				'read'          => true,
			),
			$after['editor']['capabilities']
		);
		$this->assertSame( $roles['event_manager'], $after['event_manager'] );
		$this->assertSame( $roles['administrator'], $after['administrator'] );

		$exists = BLT_Toolkit_Role_Transfer::apply(
			$roles,
			array(
				'Editor' => array(
					'action' => 'create',
					'name'   => 'Another',
					'caps'   => array(),
				),
			)
		);

		$this->assertSame( 'blt_toolkit_role_exists', $exists->get_error_code() );

		$gone = BLT_Toolkit_Role_Transfer::apply(
			$roles,
			array(
				'helper' => array(
					'action' => 'replace',
					'name'   => 'Helper',
					'caps'   => array(),
				),
			)
		);

		$this->assertSame( 'blt_toolkit_role_not_found', $gone->get_error_code() );
	}

	/**
	 * RO-45: capabilities nothing on the target site registers are flagged
	 * "not registered here".
	 *
	 * @return void
	 */
	public function test_ro45_not_registered_here() {
		$registered = array_fill_keys( array( 'read', 'edit_posts', 'upload_files' ), true );

		$this->assertSame(
			array( 'edit_events', 'publish_events' ),
			BLT_Toolkit_Role_Transfer::unregistered(
				array(
					'read'           => true,
					'publish_events' => true,
					'edit_events'    => false,
					'upload_files'   => true,
				),
				$registered
			)
		);
		$this->assertSame( array(), BLT_Toolkit_Role_Transfer::unregistered( array( 'read', 'edit_posts' ), $registered ) );
	}

	/**
	 * RO-23, SEC-3, SEC-8: an import never writes a role ID (here or in the
	 * file) as a capability, a meta capability name no role here holds, or
	 * a new name that breaks RO-23's character rule, whoever imports.
	 * Names already present here pass the name and meta rules.
	 *
	 * @return void
	 */
	public function test_ro23_sec3_import_refuses_capability_names_for_everyone() {
		$role_ids = array_fill_keys( array( 'administrator', 'subscriber', 'event_manager' ), true );
		$present  = array_fill_keys( array( 'read', 'level_0', 'edit_product', 'NextGEN Manage gallery' ), true );
		$meta     = array_fill_keys( array( 'edit_post', 'edit_user', 'edit_product', 'blt_switch_to_user' ), true );

		$this->assertSame(
			array(
				'<img src=x onerror=alert(1)>' => 'name',
				'Edit_Things'                  => 'name',
				'administrator'                => 'role',
				'blt_switch_to_user'           => 'meta',
				'edit_post'                    => 'meta',
				'event_manager'                => 'role',
			),
			BLT_Toolkit_Role_Transfer::cap_name_problems(
				array(
					'read'                         => true,
					'level_0'                      => true,
					'administrator'                => true,
					'event_manager'                => false,
					'edit_post'                    => true,
					'blt_switch_to_user'           => true,
					'edit_product'                 => true,
					'NextGEN Manage gallery'       => true,
					'<img src=x onerror=alert(1)>' => true,
					'Edit_Things'                  => true,
					'edit_things'                  => true,
					'manage_bookings'              => true,
				),
				$role_ids,
				$present,
				$meta
			)
		);

		$this->assertSame( array(), BLT_Toolkit_Role_Transfer::cap_name_problems( array( 'read', 'edit_events' ), $role_ids, $present, $meta ) );
		$this->assertSame( array( 'subscriber' => 'role' ), BLT_Toolkit_Role_Transfer::cap_name_problems( array( 'subscriber' ), $role_ids, array( 'subscriber' => true ), array() ) );
		$this->assertSame( array( str_repeat( 'a', 192 ) => 'name' ), BLT_Toolkit_Role_Transfer::cap_name_problems( array( str_repeat( 'a', 192 ) ), array(), array(), array() ) );
	}

	/**
	 * The facts of a delegated role manager (not a toolkit admin) holding
	 * `manager`.
	 *
	 * @param array $extra Overrides.
	 * @return array
	 */
	private function manager_facts( array $extra ) {
		return array_merge(
			array(
				'actor_is_admin' => false,
				'actor_roles'    => array( 'manager' ),
				'actor_caps'     => array_fill_keys( array( 'read', 'edit_posts', 'upload_files', 'blt_reset_roles' ), true ),
				'role_names'     => array( 'administrator', 'editor', 'manager', 'author' ),
				'restrictions'   => array(),
				'lockout'        => array( 'read', 'manage_options', 'list_users', 'edit_users', 'promote_users', 'activate_plugins', 'blt_edit_roles', 'blt_edit_capabilities' ),
			),
			$extra
		);
	}

	/**
	 * SEC-3: a non-admin cannot import a role with a capability they lack,
	 * nor deny one; with only capabilities they hold, Create is allowed. A
	 * toolkit admin may import anything.
	 *
	 * @return void
	 */
	public function test_sec3_import_never_grants_what_the_actor_lacks() {
		$this->assertNull(
			BLT_Toolkit_Role_Transfer::refusal(
				$this->manager_facts(
					array(
						'action'    => 'create',
						'role'      => 'helper',
						'site_caps' => null,
						'file_caps' => array(
							'read'       => true,
							'edit_posts' => true,
						),
					)
				)
			)
		);

		$grant = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'create',
					'role'      => 'helper',
					'site_caps' => null,
					'file_caps' => array(
						'read'           => true,
						'manage_options' => true,
					),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', $grant['code'] );
		$this->assertSame( 'SEC-3', $grant['rule'] );
		$this->assertSame( array( 'manage_options' ), $grant['caps'] );

		$deny = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'create',
					'role'      => 'helper',
					'site_caps' => null,
					'file_caps' => array(
						'read'       => true,
						'edit_users' => false,
					),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_deny_exceeds_actor', $deny['code'] );

		$named = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'create',
					'role'      => 'helper',
					'site_caps' => null,
					'file_caps' => array( 'administrator' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_role_name_cap', $named['code'] );

		$merge = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'merge',
					'role'      => 'author',
					'site_caps' => array( 'read' => true ),
					'file_caps' => array( 'publish_posts' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', $merge['code'] );

		$this->assertNull(
			BLT_Toolkit_Role_Transfer::refusal(
				array(
					'actor_is_admin' => true,
					'actor_roles'    => array( 'administrator' ),
					'actor_caps'     => array( 'read' => true ),
					'role_names'     => array( 'administrator' ),
					'restrictions'   => array(),
					'lockout'        => array(),
					'action'         => 'create',
					'role'           => 'helper',
					'site_caps'      => null,
					'file_caps'      => array( 'manage_options' => true ),
				)
			)
		);
	}

	/**
	 * SEC-3: replacing a role that denies a capability with one that does
	 * not mention it lifts the deny, which is a grant.
	 *
	 * @return void
	 */
	public function test_sec3_replacing_a_deny_is_a_grant() {
		$lift = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'replace',
					'role'      => 'author',
					'site_caps' => array(
						'read'          => true,
						'publish_posts' => false,
					),
					'file_caps' => array( 'read' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_grant_exceeds_actor', $lift['code'] );
		$this->assertSame( array( 'publish_posts' ), $lift['caps'] );

		// Taking away what the actor holds is fine.
		$this->assertNull(
			BLT_Toolkit_Role_Transfer::refusal(
				$this->manager_facts(
					array(
						'action'    => 'replace',
						'role'      => 'author',
						'site_caps' => array(
							'read'       => true,
							'edit_posts' => true,
						),
						'file_caps' => array( 'read' => true ),
					)
				)
			)
		);
	}

	/**
	 * SEC-1, SEC-2, SEC-5: a non-admin cannot import into Administrator
	 * (nor create it), into a role they hold, or into a role with more
	 * access than theirs.
	 *
	 * @return void
	 */
	public function test_sec1_sec2_sec5_roles_a_non_admin_cannot_change() {
		$admin = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'merge',
					'role'      => 'administrator',
					'site_caps' => array( 'read' => true ),
					'file_caps' => array( 'edit_posts' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_protected_role', $admin['code'] );

		$create_admin = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'create',
					'role'      => 'administrator',
					'site_caps' => null,
					'file_caps' => array( 'read' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_protected_role', $create_admin['code'] );

		$own = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'replace',
					'role'      => 'manager',
					'site_caps' => array( 'read' => true ),
					'file_caps' => array(
						'read'       => true,
						'edit_posts' => true,
					),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_own_role', $own['code'] );
		$this->assertSame( 'SEC-5', $own['rule'] );

		$stronger = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'replace',
					'role'      => 'editor',
					'site_caps' => array(
						'read'              => true,
						'edit_others_posts' => true,
					),
					'file_caps' => array( 'read' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_role_exceeds_actor', $stronger['code'] );
		$this->assertSame( 'SEC-2', $stronger['rule'] );

		// Skip is always allowed. An action that would change nothing is
		// still refused on a role the actor may not change, and allowed on
		// one they may.
		$this->assertNull(
			BLT_Toolkit_Role_Transfer::refusal(
				$this->manager_facts(
					array(
						'action' => 'skip',
						'role'   => 'administrator',
					)
				)
			)
		);

		$noop_own = BLT_Toolkit_Role_Transfer::refusal(
			$this->manager_facts(
				array(
					'action'    => 'merge',
					'role'      => 'manager',
					'site_caps' => array( 'read' => true ),
					'file_caps' => array( 'read' => true ),
				)
			)
		);

		$this->assertSame( 'blt_toolkit_own_role', $noop_own['code'] );
		$this->assertNull(
			BLT_Toolkit_Role_Transfer::refusal(
				$this->manager_facts(
					array(
						'action'    => 'merge',
						'role'      => 'author',
						'site_caps' => array( 'read' => true ),
						'file_caps' => array( 'read' => true ),
					)
				)
			)
		);
	}

	/**
	 * SEC-12: not even a toolkit admin can import an Administrator role that
	 * lacks the lockout-critical set.
	 *
	 * @return void
	 */
	public function test_sec12_replace_keeps_administrator_lockout_set() {
		$facts = array(
			'actor_is_admin' => true,
			'actor_roles'    => array( 'administrator' ),
			'actor_caps'     => array( 'read' => true ),
			'role_names'     => array( 'administrator' ),
			'restrictions'   => array(),
			'lockout'        => array( 'read', 'manage_options' ),
			'action'         => 'replace',
			'role'           => 'administrator',
			'site_caps'      => array(
				'read'           => true,
				'manage_options' => true,
			),
			'file_caps'      => array( 'read' => true ),
		);

		$refusal = BLT_Toolkit_Role_Transfer::refusal( $facts );

		$this->assertSame( 'blt_toolkit_lockout_critical', $refusal['code'] );
		$this->assertSame( array( 'manage_options' ), $refusal['caps'] );

		$facts['action'] = 'merge';

		$this->assertNull( BLT_Toolkit_Role_Transfer::refusal( $facts ) );
	}

	/**
	 * SEC-10: an import that grants Critical capabilities to a role other
	 * than Administrator names them, so the typed role name can be asked.
	 *
	 * @return void
	 */
	public function test_sec10_critical_grants_are_named() {
		$this->assertSame(
			array( 'edit_users', 'manage_options' ),
			BLT_Toolkit_Role_Transfer::critical_for(
				'helper',
				null,
				array(
					'read'           => true,
					'manage_options' => true,
					'edit_users'     => true,
					'list_users'     => true,
				),
				'create'
			)
		);
		$this->assertSame(
			array(),
			BLT_Toolkit_Role_Transfer::critical_for( 'editor', array( 'manage_options' => true ), array( 'manage_options' => true ), 'merge' )
		);
		$this->assertSame(
			array(),
			BLT_Toolkit_Role_Transfer::critical_for( 'administrator', array(), array( 'manage_options' => true ), 'replace' )
		);

		$this->assertTrue( BLT_Toolkit_Role_Transfer::names_match( '  event manager ', 'Event Manager' ) );
		$this->assertFalse( BLT_Toolkit_Role_Transfer::names_match( 'Event', 'Event Manager' ) );
		$this->assertFalse( BLT_Toolkit_Role_Transfer::names_match( '', '' ) );
	}

	/**
	 * RO-2 for imports: the preview's lock token does not depend on key
	 * order, and changes with the state it covers.
	 *
	 * @return void
	 */
	public function test_ro45_plan_hash() {
		$a = BLT_Toolkit_Role_Transfer::plan_hash(
			array(
				'roles' => 'x',
				'meta'  => array(
					'b' => 1,
					'a' => 2,
				),
			)
		);
		$b = BLT_Toolkit_Role_Transfer::plan_hash(
			array(
				'meta'  => array(
					'a' => 2,
					'b' => 1,
				),
				'roles' => 'x',
			)
		);

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, BLT_Toolkit_Role_Transfer::plan_hash( array( 'roles' => 'y' ) ) );
		$this->assertSame( 64, strlen( $a ) );
	}

	/**
	 * RS-30, RO-44: the strings a type with separate permissions uses, which
	 * decide whether a role "mentions" it.
	 *
	 * @return void
	 */
	public function test_ro44_isolation_strings() {
		$strings = BLT_Toolkit_Role_Transfer::isolation_strings( 'event', 'events' );

		$this->assertContains( 'edit_events', $strings );
		$this->assertContains( 'create_events', $strings );
		$this->assertContains( 'blt_readonly_events', $strings );
		$this->assertNotContains( 'read', $strings );
	}

	/**
	 * The stored upload survives pack() and unpack(), and damaged data reads
	 * as nothing.
	 *
	 * @return void
	 */
	public function test_pack_round_trip() {
		$data = array(
			'token' => 'abc',
			'doc'   => array( 'roles' => array( 'helper' => array( 'name' => 'Helper' ) ) ),
		);

		$this->assertSame( $data, BLT_Toolkit_Role_Transfer::unpack( BLT_Toolkit_Role_Transfer::pack( $data ) ) );
		$this->assertNull( BLT_Toolkit_Role_Transfer::unpack( array( 'packed' => 'not base64 !' ) ) );
		$this->assertNull( BLT_Toolkit_Role_Transfer::unpack( 'string' ) );
	}
}
