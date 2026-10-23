<?php
/**
 * Privacy: the pure rules of BLT_Toolkit_Privacy (SPEC 14.5): which log rows
 * are about a person, and what the eraser blanks in them.
 *
 * The exporter's and eraser's queries, the paging across calls and the
 * policy text are WordPress-coupled and verified on a real install
 * (tasks/todo.md, Phase 7).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Privacy.
 */
class Test_Privacy extends TestCase {

	/**
	 * The person the tests erase: user 5, renamed from `bob` to `robert`.
	 */
	const USER = 5;

	/**
	 * Their logins, current first.
	 *
	 * @var string[]
	 */
	private $logins = array( 'robert', 'bob' );

	/**
	 * A log row as BLT_Toolkit_Log::shape_row() gives it.
	 *
	 * @param array $fields Fields to set.
	 * @return array
	 */
	private function row( array $fields ) {
		return array_merge(
			array(
				'id'           => 1,
				'created_at'   => '2026-10-22 10:00:00',
				'module'       => 'toolkit',
				'action'       => 'unknown',
				'actor_id'     => 1,
				'actor_login'  => 'admin',
				'acting_as_id' => 0,
				'object_type'  => '',
				'object_id'    => '',
				'summary'      => '',
				'details'      => null,
				'snapshot_id'  => null,
				'ip'           => '203.0.113.9',
				'status'       => 'success',
				'source'       => '',
			),
			$fields
		);
	}

	/**
	 * Scrub a row as the eraser does.
	 *
	 * @param array $row Row.
	 * @return array Changed columns.
	 */
	private function scrub( array $row ) {
		return BLT_Toolkit_Privacy::scrub_row( $row, self::USER, $this->logins, array( 'Robert Smith' ), '[deleted]' );
	}

	// ---------- Relations ----------

	/**
	 * SPEC 14.5: actor, acting-as and object are each a relation, by ID.
	 *
	 * @return void
	 */
	public function test_spec14_5_relations_by_id() {
		$this->assertSame( array( 'actor' ), BLT_Toolkit_Privacy::relations( $this->row( array( 'actor_id' => 5 ) ), self::USER, $this->logins ) );
		$this->assertSame( array( 'acting_as' ), BLT_Toolkit_Privacy::relations( $this->row( array( 'acting_as_id' => 5 ) ), self::USER, $this->logins ) );
		$this->assertSame(
			array( 'object' ),
			BLT_Toolkit_Privacy::relations(
				$this->row(
					array(
						'object_type' => 'user',
						'object_id'   => '5',
					)
				),
				self::USER,
				$this->logins
			)
		);
		$this->assertSame(
			array(),
			BLT_Toolkit_Privacy::relations(
				$this->row(
					array(
						'object_type' => 'role',
						'object_id'   => '5',
					)
				),
				self::USER,
				$this->logins
			),
			'A role whose ID is 5 is not the user.'
		);
		$this->assertSame( array(), BLT_Toolkit_Privacy::relations( $this->row( array( 'actor_id' => 5 ) ), 0, $this->logins ) );
	}

	/**
	 * SPEC 14.5 (Phase 1 handoff): a person in the middle of a switch chain
	 * is named, by the ID recorded with their login.
	 *
	 * @return void
	 */
	public function test_spec14_5_switch_chain_member_is_named() {
		$row = $this->row(
			array(
				'actor_id'     => 1,
				'acting_as_id' => 7,
				'details'      => array(
					'switch_chain' => array(
						array(
							'id'    => 1,
							'login' => 'admin',
						),
						array(
							'id'    => 5,
							'login' => 'bob',
						),
						array(
							'id'    => 7,
							'login' => 'carol',
						),
					),
				),
			)
		);

		$this->assertSame( array( 'named' ), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );
		$this->assertSame( array(), BLT_Toolkit_Privacy::relations( $row, 9, array( 'dave' ) ) );
	}

	/**
	 * A person entry is decided by its ID when it has one: another user who
	 * holds one of the person's old logins today is not them.
	 *
	 * @return void
	 */
	public function test_spec14_5_person_entry_id_wins_over_login() {
		$row = $this->row(
			array(
				'details' => array(
					'to' => array(
						'id'    => 8,
						'login' => 'bob',
					),
				),
			)
		);

		$this->assertSame( array(), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );
		$this->assertSame( array(), $this->scrub( $row ) );
	}

	/**
	 * Bulk role changes (US-3) name people by login only.
	 *
	 * @return void
	 */
	public function test_us3_bulk_lists_name_by_login() {
		$row = $this->row(
			array(
				'module'      => 'roles',
				'action'      => 'user_roles_changed',
				'object_type' => 'users',
				'summary'     => 'Bulk role change on the Users screen: added Editor to 2 users.',
				'details'     => array(
					'add'       => 'editor',
					'remove'    => '',
					'added'     => array( 'alice', 'BOB' ),
					'removed'   => array(),
					'unchanged' => array(),
					'skipped'   => array(
						'robert' => 'SEC-2',
						'zed'    => 'SEC-3',
					),
				),
			)
		);

		$this->assertSame( array( 'named' ), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );

		$changes = $this->scrub( $row );
		$details = json_decode( $changes['details'], true );

		$this->assertSame( array( 'alice', '' ), $details['added'], 'Logins compare without regard to case.' );
		$this->assertSame(
			array(
				''    => 'SEC-2',
				'zed' => 'SEC-3',
			),
			$details['skipped']
		);
		$this->assertSame( 'editor', $details['add'] );
		$this->assertArrayNotHasKey( 'actor_login', $changes, 'The admin made it: their login stays.' );
		$this->assertArrayNotHasKey( 'ip', $changes );
	}

	/**
	 * Lists named `added` and `removed` hold role IDs or capabilities on any
	 * other row, so a login equal to one is not a mention there.
	 *
	 * @return void
	 */
	public function test_spec14_5_lists_on_other_rows_are_not_logins() {
		$row = $this->row(
			array(
				'object_type' => 'role',
				'object_id'   => 'editor',
				'details'     => array(
					'added'   => array( 'bob' ),
					'removed' => array( 'robert' ),
				),
			)
		);

		$this->assertSame( array(), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );
	}

	/**
	 * A rename row about someone else keeps its logins even when they equal
	 * one of the person's: logins are reused.
	 *
	 * @return void
	 */
	public function test_un10_rename_of_someone_else_is_not_theirs() {
		$row = $this->row(
			array(
				'module'      => 'usernames',
				'action'      => 'username_changed',
				'object_type' => 'user',
				'object_id'   => '8',
				'details'     => array(
					'old_login' => 'zed',
					'new_login' => 'bob',
				),
			)
		);

		$this->assertSame( array(), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );
	}

	/**
	 * A log export filtered to the person names them (by ID or typed login).
	 *
	 * @return void
	 */
	public function test_spec14_5_log_export_filters() {
		$by_id = $this->row(
			array(
				'action'  => 'log_exported',
				'details' => array(
					'entries' => 3,
					'filters' => array( 'actor' => '5' ),
				),
			)
		);

		$this->assertSame( array( 'named' ), BLT_Toolkit_Privacy::relations( $by_id, self::USER, $this->logins ) );
		$this->assertArrayNotHasKey( 'details', $this->scrub( $by_id ), 'An ID is kept.' );

		$by_login = $this->row(
			array(
				'action'  => 'log_exported',
				'details' => array(
					'entries' => 3,
					'filters' => array( 'actor' => 'Bob' ),
				),
			)
		);

		$changes = $this->scrub( $by_login );

		$this->assertSame( '', json_decode( $changes['details'], true )['filters']['actor'] );
	}

	// ---------- Scrubbing ----------

	/**
	 * SPEC 14.5: on a row they made, `actor_login` and `ip` are blanked.
	 *
	 * @return void
	 */
	public function test_spec14_5_actor_row_blanks_login_and_ip() {
		$changes = $this->scrub(
			$this->row(
				array(
					'actor_id'    => 5,
					'actor_login' => 'bob',
					'summary'     => 'Saved the Editor role: 1 capability added.',
				)
			)
		);

		$this->assertSame(
			array(
				'actor_login' => '',
				'ip'          => '',
			),
			$changes
		);
	}

	/**
	 * On a row where they were only acted as, the IP and login belong to the
	 * actor and stay; their own login in the chain and the summary goes.
	 *
	 * @return void
	 */
	public function test_sw_switch_row_where_they_were_switched_to() {
		$row = $this->row(
			array(
				'module'      => 'switch',
				'action'      => 'switched_to',
				'object_type' => 'user',
				'object_id'   => '5',
				'summary'     => 'Switched to Robert Smith.',
				'details'     => array(
					'from'     => array(
						'id'    => 1,
						'login' => 'admin',
					),
					'to'       => array(
						'id'    => 5,
						'login' => 'robert',
					),
					'remember' => false,
				),
			)
		);

		$changes = $this->scrub( $row );
		$details = json_decode( $changes['details'], true );

		$this->assertArrayNotHasKey( 'actor_login', $changes );
		$this->assertArrayNotHasKey( 'ip', $changes );
		$this->assertSame( 'Switched to [deleted].', $changes['summary'] );
		$this->assertSame( 'admin', $details['from']['login'] );
		$this->assertSame( '', $details['to']['login'] );
		$this->assertSame( 5, $details['to']['id'], 'IDs are kept.' );
		$this->assertFalse( $details['remember'], 'Other values keep their type.' );
	}

	/**
	 * UN-10: a rename of their account blanks the old and new usernames and
	 * author slugs, and both names in the summary.
	 *
	 * @return void
	 */
	public function test_un10_rename_row_about_them() {
		$row = $this->row(
			array(
				'module'      => 'usernames',
				'action'      => 'username_changed',
				'object_type' => 'user',
				'object_id'   => '5',
				'summary'     => 'Changed the username bob to robert.',
				'details'     => array(
					'old_login'           => 'bob',
					'new_login'           => 'robert',
					'author_slug_changed' => true,
					'old_author_slug'     => 'bob',
					'new_author_slug'     => 'robert',
					'source'              => 'profile',
				),
			)
		);

		$changes = $this->scrub( $row );
		$details = json_decode( $changes['details'], true );

		$this->assertSame( 'Changed the username [deleted] to [deleted].', $changes['summary'] );
		$this->assertSame( '', $details['old_login'] );
		$this->assertSame( '', $details['new_login'] );
		$this->assertSame( '', $details['old_author_slug'] );
		$this->assertSame( '', $details['new_author_slug'] );
		$this->assertSame( 'profile', $details['source'] );
		$this->assertTrue( $details['author_slug_changed'] );
	}

	/**
	 * Username Changer's imported rows whose user was not found have no
	 * object: a matching old or new login is the person's.
	 *
	 * @return void
	 */
	public function test_spec15_imported_row_without_object() {
		$row = $this->row(
			array(
				'module'  => 'usernames',
				'action'  => 'username_changed',
				'source'  => 'username-changer',
				'summary' => 'Changed the username bob to bobby. (Imported from Username Changer.)',
				'details' => array(
					'old_login' => 'bob',
					'new_login' => 'bobby',
					'source_id' => 12,
				),
			)
		);

		$this->assertSame( array( 'named' ), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );

		$changes = $this->scrub( $row );
		$details = json_decode( $changes['details'], true );

		$this->assertSame( '', $details['old_login'] );
		$this->assertSame( 'bobby', $details['new_login'], 'Not a login of theirs that the log knows.' );
		$this->assertSame( 12, $details['source_id'], 'The import ledger key stays.' );
	}

	/**
	 * Person entries without an ID (an isolation cleanup's users) match by
	 * login; entries with one, by ID (a role move's changed and failed).
	 *
	 * @return void
	 */
	public function test_ro41_rs32_person_lists() {
		$row = $this->row(
			array(
				'details' => array(
					'users'   => array(
						array(
							'login'  => 'ROBERT',
							'remove' => array( 'edit_events' ),
						),
					),
					'changed' => array(
						array(
							'id'    => 5,
							'login' => 'robert',
							'op'    => 'replace',
						),
						array(
							'id'    => 6,
							'login' => 'robert2',
							'op'    => 'replace',
						),
					),
				),
			)
		);

		$details = json_decode( $this->scrub( $row )['details'], true );

		$this->assertSame( '', $details['users'][0]['login'] );
		$this->assertSame( array( 'edit_events' ), $details['users'][0]['remove'] );
		$this->assertSame( '', $details['changed'][0]['login'] );
		$this->assertSame( 'robert2', $details['changed'][1]['login'] );
	}

	/**
	 * Nothing to change: no key at all, so the stored JSON is untouched.
	 *
	 * @return void
	 */
	public function test_spec14_5_clean_row_changes_nothing() {
		$row = $this->row(
			array(
				'acting_as_id' => 5,
				'summary'      => 'Saved the Editor role.',
				'details'      => array(
					'switch_chain' => array(
						array(
							'id'    => 1,
							'login' => 'admin',
						),
						array(
							'id'    => 5,
							'login' => '',
						),
					),
				),
			)
		);

		$this->assertSame( array( 'acting_as' ), BLT_Toolkit_Privacy::relations( $row, self::USER, $this->logins ) );
		$this->assertSame( array(), $this->scrub( $row ), 'Erasing twice changes nothing the second time.' );
	}

	// ---------- Text ----------

	/**
	 * Names are replaced only where they stand alone: not inside another
	 * username, not in another case, longest first, placeholder never
	 * matched again.
	 *
	 * @dataProvider text_vectors
	 *
	 * @param string   $text        Text.
	 * @param string[] $identifiers Names.
	 * @param string   $expected    Result.
	 * @return void
	 */
	public function test_spec14_5_scrub_text( $text, array $identifiers, $expected ) {
		$this->assertSame( $expected, BLT_Toolkit_Privacy::scrub_text( $text, $identifiers, '[deleted]' ) );
	}

	/**
	 * Vectors for test_spec14_5_scrub_text().
	 *
	 * @return array
	 */
	public function text_vectors() {
		return array(
			'end of sentence'       => array( 'Switched to bob.', array( 'bob' ), 'Switched to [deleted].' ),
			'inside a longer login' => array( 'Changed the roles of bob.smith and bobby.', array( 'bob' ), 'Changed the roles of bob.smith and bobby.' ),
			'login with a dot'      => array( 'Changed the username bob.smith to bob.', array( 'bob.smith', 'bob' ), 'Changed the username [deleted] to [deleted].' ),
			'longest first'         => array( 'Switched to Robert Smith.', array( 'Robert', 'Robert Smith' ), 'Switched to [deleted].' ),
			'case-sensitive'        => array( 'Saved the Editor role.', array( 'editor' ), 'Saved the Editor role.' ),
			'email-like login'      => array( 'Changed the username a@b.co to ab.', array( 'a@b.co' ), 'Changed the username [deleted] to ab.' ),
			'placeholder kept'      => array( 'Switched to deleted.', array( 'deleted' ), 'Switched to [deleted].' ),
			'regex characters'      => array( 'Changed the roles of a+b (x).', array( 'a+b' ), 'Changed the roles of [deleted] (x).' ),
			'nothing to replace'    => array( 'Switched off.', array(), 'Switched off.' ),
			'empty identifiers'     => array( 'Switched off.', array( '', '  ' ), 'Switched off.' ),
			'unicode boundary'      => array( 'Switched to José and joséphine.', array( 'José', 'jos' ), 'Switched to [deleted] and joséphine.' ),
		);
	}

	// ---------- Logins and needles ----------

	/**
	 * The logins a row records for the person: person entries with their
	 * ID, and old and new usernames on a row about them.
	 *
	 * @return void
	 */
	public function test_spec14_5_logins_in() {
		$details = array(
			'switch_chain' => array(
				array(
					'id'    => 5,
					'login' => 'bob',
				),
				array(
					'id'    => 6,
					'login' => 'carol',
				),
			),
			'old_login'    => 'bobby',
			'new_login'    => 'bob',
		);

		$this->assertSame( array( 'bob' ), BLT_Toolkit_Privacy::logins_in( $details, self::USER, false ) );
		$this->assertSame( array( 'bobby', 'bob' ), BLT_Toolkit_Privacy::logins_in( $details, self::USER, true ) );
	}

	/**
	 * The SQL candidates: ID patterns that cannot match a longer ID, and each
	 * login once as a JSON string.
	 *
	 * @return void
	 */
	public function test_spec14_5_needles() {
		$needles = BLT_Toolkit_Privacy::needles( self::USER, array( 'bob', 'BOB', 'b/o"b', 'josé' ) );

		$this->assertSame(
			array(
				'"id":5,',
				'"user_id":5,',
				'"actor":"5"',
				'"acting_as":"5"',
				'"bob"',
				'"b/o\"b"',
				'"josé"',
			),
			$needles
		);
		$this->assertFalse( strpos( '{"id":55,"login":"x"}', $needles[0] ), '5 does not match 55.' );
		$this->assertFalse( strpos( '{"source_id":5,"x":1}', $needles[0] ), 'source_id is not id.' );
	}

	/**
	 * Logins: strings only, each once, at most MAX_LOGINS.
	 *
	 * @return void
	 */
	public function test_spec14_5_clean_logins() {
		$this->assertSame( array( 'bob', 'Bob', '7' ), BLT_Toolkit_Privacy::clean_logins( array( 'bob', '', ' ', null, true, array( 'x' ), 'bob', 'Bob', 7 ) ) );

		$many = array();

		for ( $i = 0; $i < 30; $i++ ) {
			$many[] = 'user' . $i;
		}

		$this->assertCount( BLT_Toolkit_Privacy::MAX_LOGINS, BLT_Toolkit_Privacy::clean_logins( $many ) );
	}
}
