<?php
/**
 * Legacy importers (SPEC 15): the pure parts of BLT_Toolkit_Legacy, the
 * capability catalog's "From User Role Editor" family and the Overview's
 * activity tiles.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Legacy.
 */
class Test_Legacy extends TestCase {

	/**
	 * Reset shims.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A Username Changer 3.3.1 audit row, as its table stores it.
	 *
	 * @param array $over Fields to replace.
	 * @return array
	 */
	private function audit_row( array $over = array() ) {
		return array_merge(
			array(
				'id'               => '17',
				'changed_at'       => '2026-01-15 09:30:00',
				'changed_by_id'    => '1',
				'changed_by_login' => 'admin',
				'old_username'     => 'ann',
				'new_username'     => 'anna',
				'ip_address'       => '203.0.113.9',
				'status'           => 'success',
			),
			$over
		);
	}

	/**
	 * The context map_audit_row() gets during an import.
	 *
	 * @param array $over Fields to replace.
	 * @return array
	 */
	private function context( array $over = array() ) {
		return array_merge(
			array(
				'timezone'  => new DateTimeZone( 'America/New_York' ),
				'record_ip' => true,
				'user_id'   => 42,
				'now'       => '2026-10-22 12:00:00',
			),
			$over
		);
	}

	// ---------- Username Changer settings ----------

	/**
	 * SPEC 15: minimum length, the notification toggle, subject and body map
	 * directly; the editor HTML body becomes plain text; custom labels are
	 * dropped; tags BLT Toolkit does not replace are reported.
	 *
	 * @return void
	 */
	public function test_spec15_uc_settings_map_directly() {
		$mapped = BLT_Toolkit_Legacy::map_uc_settings(
			array(
				'allowed_roles'        => array( 'author' => 'Author' ),
				'minimum_length'       => '5',
				'enable_notifications' => '1',
				'email_subject'        => 'Your username on {sitename} changed',
				'email_message'        => "<p>Hi {name},</p>\n<p>You are now {new_username}.<br />Log in at {loginurl}</p>",
				'change_button_label'  => 'Rename me',
				'success_message'      => 'Done!',
				'save_button_label'    => '',
			)
		);

		$this->assertSame(
			array(
				'min_length'    => 5,
				'notify_user'   => true,
				'email_subject' => 'Your username on {sitename} changed',
				'email_body'    => "Hi {name},\n\nYou are now {new_username}.\nLog in at {loginurl}",
			),
			$mapped['values']
		);
		$this->assertSame( array(), $mapped['kept'] );
		$this->assertSame( array( 'change_button_label', 'success_message' ), $mapped['dropped'] );
		$this->assertSame( array( '{name}' ), $mapped['tags'] );
	}

	/**
	 * SPEC 15: Username Changer uses its own default when the subject or
	 * message is empty, so BLT Toolkit's are kept; an unticked checkbox is not
	 * stored, which means off; a missing minimum length changes nothing.
	 *
	 * @return void
	 */
	public function test_spec15_uc_defaults_keep_blt_values() {
		$mapped = BLT_Toolkit_Legacy::map_uc_settings( array( 'email_subject' => '' ) );

		$this->assertSame( array( 'notify_user' => false ), $mapped['values'] );
		$this->assertSame( array( 'email_subject', 'email_body' ), $mapped['kept'] );
		$this->assertSame( array(), $mapped['dropped'] );
	}

	/**
	 * SPEC 15, SPEC 14.1: Username Changer's settings screen shows its default
	 * subject and message in empty fields and stores them on any save (for
	 * example to set Allowed Roles), so its exact default text counts as not
	 * customized: BLT Toolkit's empty, translated default is kept. Slashes,
	 * Windows line breaks and the editor's paragraphs do not matter; any
	 * other text is imported.
	 *
	 * @return void
	 */
	public function test_spec15_uc_default_text_is_not_customized() {
		$saved = BLT_Toolkit_Legacy::map_uc_settings(
			array(
				'allowed_roles' => array( 'author' => 'Author' ),
				'email_subject' => 'Username change notification - {sitename}',
				'email_message' => "Howdy! We\\'re just writing to let you know that your username for {siteurl} has been changed to {new_username}.\r\n\r\nLogin now at {loginurl}",
			)
		);

		$this->assertSame( array( 'notify_user' => false ), $saved['values'] );
		$this->assertSame( array( 'email_subject', 'email_body' ), $saved['kept'] );

		$html = BLT_Toolkit_Legacy::map_uc_settings(
			array(
				'email_message' => "<p>Howdy! We're just writing to let you know that your username for {siteurl} has been changed to {new_username}.</p>\n<p>Login now at {loginurl}</p>",
			)
		);

		$this->assertSame( array( 'email_subject', 'email_body' ), $html['kept'] );

		$custom = BLT_Toolkit_Legacy::map_uc_settings(
			array(
				'email_subject' => 'Username change notification - {sitename}!',
				'email_message' => 'Login now at {loginurl}',
			)
		);

		$this->assertSame( 'Username change notification - {sitename}!', $custom['values']['email_subject'] );
		$this->assertSame( 'Login now at {loginurl}', $custom['values']['email_body'] );
		$this->assertSame( array(), $custom['kept'] );
	}

	/**
	 * CONVENTIONS 11, SPEC 13.2: a plugin counts as imported only when every
	 * item with something to import is in the ledger; the audit log counts
	 * as missing while rows wait, whatever the ledger says.
	 *
	 * @return void
	 */
	public function test_spec15_missing_items_mean_partly_imported() {
		$ledger = array(
			'uc_audit'    => array( 'last_id' => 9 ),
			'uc_settings' => array( 'values' => array() ),
		);

		$this->assertSame( array( 'uc_roles' ), BLT_Toolkit_Legacy::missing_items( array( 'uc_settings', 'uc_roles' ), $ledger ) );
		$this->assertSame( array( 'uc_audit' ), BLT_Toolkit_Legacy::missing_items( array( 'uc_settings', 'uc_audit' ), $ledger ) );
		$this->assertSame( array(), BLT_Toolkit_Legacy::missing_items( array( 'uc_settings' ), $ledger ) );
		$this->assertSame( array( 'ure_backup' ), BLT_Toolkit_Legacy::missing_items( array( 'ure_backup' ), array() ) );
	}

	/**
	 * SPEC 15, UN-2: the minimum length is clamped to 1 to 60; text that is not
	 * a number is ignored; Username Changer's slashes are removed.
	 *
	 * @return void
	 */
	public function test_spec15_uc_min_length_clamped_and_text_unslashed() {
		$low  = BLT_Toolkit_Legacy::map_uc_settings( array( 'minimum_length' => '0' ) );
		$high = BLT_Toolkit_Legacy::map_uc_settings( array( 'minimum_length' => 99 ) );
		$text = BLT_Toolkit_Legacy::map_uc_settings(
			array(
				'minimum_length' => 'three',
				'email_subject'  => "Don\\'t worry",
			)
		);

		$this->assertSame( 1, $low['values']['min_length'] );
		$this->assertSame( 60, $high['values']['min_length'] );
		$this->assertArrayNotHasKey( 'min_length', $text['values'] );
		$this->assertSame( "Don't worry", $text['values']['email_subject'] );
	}

	/**
	 * SPEC 15: the editor HTML becomes plain text with its breaks kept.
	 *
	 * @return void
	 */
	public function test_spec15_uc_body_text() {
		$this->assertSame( "One\n\nTwo\nThree & four", BLT_Toolkit_Legacy::uc_body_text( "<p>One</p><p>Two<br>Three &amp; four</p>\n\n\n" ) );
		$this->assertSame( 'Plain', BLT_Toolkit_Legacy::uc_body_text( "  Plain \r\n" ) );
		$this->assertSame( 'Kept', BLT_Toolkit_Legacy::uc_body_text( '<script>alert(1)</script>Kept' ) );
	}

	/**
	 * UN-15: the tags BLT Toolkit replaces are exactly those of the rename
	 * email, so "tag names shared" (SPEC 15) is checked against the code.
	 *
	 * @return void
	 */
	public function test_un15_blt_tags_match_the_rename_email() {
		$tags = array();

		foreach ( array_keys( BLT_Toolkit_Username_Changer::email_tags( array() ) ) as $tag ) {
			$tags[] = trim( $tag, '{}' );
		}

		$this->assertSame( $tags, BLT_Toolkit_Legacy::BLT_TAGS );
		$this->assertSame( array( '{date}', '{fullname}', '{minlength}' ), BLT_Toolkit_Legacy::unsupported_tags( '{date} {old_username} {fullname} {date} {email} {minlength}' ) );
	}

	/**
	 * SPEC 15, UN-20: Username Changer's multicheck (`sanitize_key( role )`
	 * => display name) names roles by key, then by ID or display name in the
	 * value; administrators are left out; unknown names are reported.
	 *
	 * @return void
	 */
	public function test_spec15_uc_allowed_roles() {
		$known = array(
			'administrator' => 'Administrator',
			'editor'        => 'Editor',
			'author'        => 'Author',
			'Shop_Manager'  => 'Shop manager',
			'subscriber'    => 'Subscriber',
		);

		$this->assertSame(
			array(
				'roles'   => array( 'editor', 'author', 'Shop_Manager' ),
				'missing' => array( 'gone' ),
			),
			BLT_Toolkit_Legacy::uc_allowed_roles(
				array(
					'editor'        => 'Editor',
					'author'        => 'Author',
					'administrator' => 'Administrator',
					'shop_manager'  => 'Shop manager',
					'gone'          => 'Gone',
				),
				$known
			)
		);

		$this->assertSame(
			array(
				'roles'   => array( 'subscriber', 'editor' ),
				'missing' => array(),
			),
			BLT_Toolkit_Legacy::uc_allowed_roles( array( 'Subscriber', 'editor', '-1' ), $known )
		);

		$this->assertSame(
			array(
				'roles'   => array(),
				'missing' => array(),
			),
			BLT_Toolkit_Legacy::uc_allowed_roles( '-1', $known )
		);
	}

	// ---------- Username Changer audit log ----------

	/**
	 * LOG-1, SPEC 14.3: an audit row becomes a native-looking rename row with
	 * source `username-changer`, Username Changer's actor, the time in UTC,
	 * the IP, and the source row's ID in the details.
	 *
	 * @return void
	 */
	public function test_log1_audit_row_maps_to_log_row() {
		$row     = BLT_Toolkit_Legacy::map_audit_row( $this->audit_row(), $this->context() );
		$details = BLT_Toolkit_Log::decode_details( $row['details'] );

		$this->assertSame( array_keys( BLT_Toolkit_Log::COLUMNS ), array_keys( $row ), 'Columns in the table\'s insert order.' );
		$this->assertSame( '2026-01-15 14:30:00', $row['created_at'] );
		$this->assertSame( 'usernames', $row['module'] );
		$this->assertSame( 'username_changed', $row['action'] );
		$this->assertSame( 1, $row['actor_id'] );
		$this->assertSame( 'admin', $row['actor_login'] );
		$this->assertSame( 0, $row['acting_as_id'] );
		$this->assertSame( 'user', $row['object_type'] );
		$this->assertSame( '42', $row['object_id'] );
		$this->assertSame( 'Changed the username ann to anna. (Imported from Username Changer.)', $row['summary'] );
		$this->assertNull( $row['snapshot_id'] );
		$this->assertSame( '203.0.113.9', $row['ip'] );
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 'username-changer', $row['source'] );
		$this->assertSame(
			array(
				'old_login'  => 'ann',
				'new_login'  => 'anna',
				'source'     => 'username-changer',
				'source_id'  => 17,
				'changed_at' => '2026-01-15 09:30:00',
			),
			$details
		);
	}

	/**
	 * LOG-5, LOG-1: with IP recording off, or an address that is not one, no
	 * IP is copied; a self-rename reads as one; an unknown subject has no
	 * object; a time that is not a real time is flagged and stamped with the
	 * import's time; a status other than success is kept in the details.
	 *
	 * @return void
	 */
	public function test_log5_audit_row_edges() {
		$no_ip   = BLT_Toolkit_Legacy::map_audit_row( $this->audit_row(), $this->context( array( 'record_ip' => false ) ) );
		$bad     = BLT_Toolkit_Legacy::map_audit_row(
			$this->audit_row(
				array(
					'ip_address'       => 'not-an-ip',
					'changed_by_login' => 'ann',
					'changed_at'       => '0000-00-00 00:00:00',
					'status'           => 'failed',
				)
			),
			$this->context( array( 'user_id' => 0 ) )
		);
		$details = BLT_Toolkit_Log::decode_details( $bad['details'] );

		$this->assertSame( '', $no_ip['ip'] );
		$this->assertSame( '', $bad['ip'] );
		$this->assertSame( 'Changed their own username from ann to anna. (Imported from Username Changer.)', $bad['summary'] );
		$this->assertSame( '', $bad['object_type'] );
		$this->assertSame( '', $bad['object_id'] );
		$this->assertSame( '2026-10-22 12:00:00', $bad['created_at'] );
		$this->assertTrue( $details['time_unknown'] );
		$this->assertSame( 'failed', $details['source_status'] );
		$this->assertSame( 'failure', $bad['status'] );
	}

	/**
	 * SPEC 15: Username Changer stores the site's local time; it becomes UTC,
	 * and impossible times become ''.
	 *
	 * @return void
	 */
	public function test_spec15_local_time_to_utc() {
		$ny = new DateTimeZone( 'America/New_York' );

		$this->assertSame( '2026-07-01 04:00:00', BLT_Toolkit_Legacy::local_to_utc( '2026-07-01 00:00:00', $ny ), 'Daylight saving time.' );
		$this->assertSame( '2026-01-01 05:00:00', BLT_Toolkit_Legacy::local_to_utc( '2026-01-01 00:00:00', $ny ) );
		$this->assertSame( '2026-01-01 00:00:00', BLT_Toolkit_Legacy::local_to_utc( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( '', BLT_Toolkit_Legacy::local_to_utc( '0000-00-00 00:00:00', $ny ) );
		$this->assertSame( '', BLT_Toolkit_Legacy::local_to_utc( '2026-02-31 10:00:00', $ny ) );
		$this->assertSame( '', BLT_Toolkit_Legacy::local_to_utc( '2026-01-01 24:00:00', $ny ) );
		$this->assertSame( '', BLT_Toolkit_Legacy::local_to_utc( null, $ny ) );
	}

	/**
	 * SPEC 15: Username Changer's statuses as log statuses.
	 *
	 * @return void
	 */
	public function test_spec15_uc_status() {
		$this->assertSame( 'success', BLT_Toolkit_Legacy::uc_status( 'success' ) );
		$this->assertSame( 'success', BLT_Toolkit_Legacy::uc_status( '' ) );
		$this->assertSame( 'failure', BLT_Toolkit_Legacy::uc_status( 'FAILED' ) );
		$this->assertSame( 'failure', BLT_Toolkit_Legacy::uc_status( 'error' ) );
		$this->assertSame( 'warning', BLT_Toolkit_Legacy::uc_status( 'pending' ) );
		$this->assertSame( 'success', BLT_Toolkit_Legacy::uc_status( array() ) );
	}

	/**
	 * SPEC 17 Phase 6, CONVENTIONS 11: the import resumes after the highest
	 * imported ID, taken from the ledger or from the newest imported row,
	 * whichever is higher, so a second run never copies a row twice, even
	 * when a batch's ledger update was lost.
	 *
	 * @return void
	 */
	public function test_spec15_resumable_cursor_by_highest_id() {
		$newest = BLT_Toolkit_Legacy::map_audit_row( $this->audit_row( array( 'id' => '25' ) ), $this->context() );

		$this->assertSame( 25, BLT_Toolkit_Legacy::source_id_of( $newest['details'] ) );
		$this->assertSame( 0, BLT_Toolkit_Legacy::cursor( 0, null ) );
		$this->assertSame( 25, BLT_Toolkit_Legacy::cursor( 10, $newest['details'] ), 'The ledger lags behind the rows written.' );
		$this->assertSame( 40, BLT_Toolkit_Legacy::cursor( 40, $newest['details'] ), 'Imported rows were pruned since.' );
		$this->assertSame( 7, BLT_Toolkit_Legacy::cursor( 7, 'not json' ) );
		$this->assertSame( 0, BLT_Toolkit_Legacy::source_id_of( '{"source_id":"-3"}' ) );
	}

	// ---------- User Role Editor ----------

	/**
	 * SPEC 15, US-2: `other_default_roles` add to `extra_default_roles`;
	 * administrator never; roles already there and roles that do not exist
	 * are reported, each once.
	 *
	 * @return void
	 */
	public function test_spec15_ure_default_roles() {
		$known = array( 'administrator', 'editor', 'author', 'subscriber' );

		$this->assertSame(
			array(
				'add'     => array( 'author' ),
				'already' => array( 'editor' ),
				'missing' => array( 'gone' ),
			),
			BLT_Toolkit_Legacy::ure_default_roles(
				array(
					'ure_caps_readable'   => 1,
					'other_default_roles' => array( 'author', 'administrator', 'gone', 'editor', 'author', 7 ),
				),
				$known,
				array( 'editor' )
			)
		);

		$this->assertSame(
			array(
				'add'     => array(),
				'already' => array(),
				'missing' => array(),
			),
			BLT_Toolkit_Legacy::ure_default_roles( false, $known, array() )
		);
	}

	/**
	 * SPEC 15, SPEC 9.5: the hide-admin-bar flags
	 * (`ure_role_additional_options_values`) name the roles whose toolbar is
	 * hidden.
	 *
	 * @return void
	 */
	public function test_spec15_ure_flags_map_to_hide_toolbar() {
		$this->assertSame(
			array(
				'roles'   => array( 'subscriber', 'customer' ),
				'missing' => array( 'gone' ),
			),
			BLT_Toolkit_Legacy::ure_toolbar_roles(
				array(
					'subscriber' => array( 'hide_admin_bar' => 1 ),
					'editor'     => array(),
					'gone'       => array( 'hide_admin_bar' => 1 ),
					'customer'   => array(
						'hide_admin_bar' => 1,
						'other_option'   => 1,
					),
					'author'     => 'hide_admin_bar',
				),
				array( 'subscriber', 'editor', 'author', 'customer' )
			)
		);

		$this->assertSame(
			array(
				'roles'   => array(),
				'missing' => array(),
			),
			BLT_Toolkit_Legacy::ure_toolbar_roles( null, array( 'subscriber' ) )
		);
	}

	/**
	 * SPEC 14.3: the backup is a snapshot only when it is a roles array; it is
	 * kept exactly as stored.
	 *
	 * @return void
	 */
	public function test_snap_ure_backup_shape() {
		$roles = array(
			'editor' => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'read'       => true,
					'edit_posts' => 1,
				),
			),
		);

		$this->assertSame( $roles, BLT_Toolkit_Legacy::ure_backup_roles( $roles ) );
		$this->assertNull( BLT_Toolkit_Legacy::ure_backup_roles( array() ) );
		$this->assertNull( BLT_Toolkit_Legacy::ure_backup_roles( 'a:0:{}' ) );
		$this->assertNull( BLT_Toolkit_Legacy::ure_backup_roles( array( 'editor' => array( 'name' => 'Editor' ) ) ) );
		$this->assertNull( BLT_Toolkit_Legacy::ure_backup_roles( array( 'editor' => array( 'capabilities' => array() ) ) ) );
	}

	/**
	 * SPEC 15: only `ure_*` names are User Role Editor's, sorted.
	 *
	 * @return void
	 */
	public function test_spec15_ure_caps_in() {
		$this->assertSame(
			array( 'ure_edit_roles', 'ure_manage_options' ),
			BLT_Toolkit_Legacy::ure_caps_in(
				array(
					'ure_manage_options' => true,
					'read'               => true,
					'pure_cap'           => true,
					'ure_edit_roles'     => false,
					'URE_upper'          => true,
				)
			)
		);
	}

	/**
	 * RO-11, RO-13, SPEC 15: `ure_*` capabilities stay orphans and are grouped
	 * under the label "From User Role Editor", even when there are fewer than
	 * three; without the label they keep the detected-prefix rule.
	 *
	 * @return void
	 */
	public function test_ro13_ure_caps_labeled_from_user_role_editor() {
		$input = array(
			'core'            => BLT_Toolkit_Capability_Catalog::core_caps(),
			'core_meta'       => BLT_Toolkit_Capability_Catalog::core_meta_names(),
			'post_types'      => array(),
			'taxonomies'      => array(),
			'declared'        => array(),
			'blt'             => array(),
			'blt_meta'        => array(),
			'roles'           => array(
				'administrator' => array(
					'manage_options'     => true,
					'ure_edit_roles'     => true,
					'ure_manage_options' => true,
				),
			),
			'users'           => array(),
			'orphan_families' => BLT_Toolkit_Legacy::orphan_families(),
		);

		$built = BLT_Toolkit_Capability_Catalog::build( $input );

		foreach ( array( 'ure_edit_roles', 'ure_manage_options' ) as $cap ) {
			$this->assertTrue( $built['entries'][ $cap ]['orphan'], $cap );
			$this->assertSame( 'From User Role Editor', $built['entries'][ $cap ]['source_label'], $cap );
			$this->assertSame( 'plugin-ure', $built['entries'][ $cap ]['group'], $cap );
		}

		unset( $input['orphan_families'] );

		$plain = BLT_Toolkit_Capability_Catalog::build( $input );

		$this->assertSame( 'orphaned', $plain['entries']['ure_edit_roles']['group'], 'Two members make no detected group.' );
	}

	// ---------- Ledger and plan hash ----------

	/**
	 * SPEC 14.2: the ledger keeps known entries only, with typed fields.
	 *
	 * @return void
	 */
	public function test_spec14_ledger_cleaned() {
		$ledger = BLT_Toolkit_Legacy::clean_ledger(
			array(
				'uc_audit'    => array(
					'at'      => '1760000000',
					'by'      => 3,
					'last_id' => '250',
					'rows'    => 250,
					'job'     => array( 'x' ),
				),
				'ure_backup'  => array(
					'snapshot_id' => 'nine',
					'hash'        => 'abc',
				),
				'uc_roles'    => array( 'roles' => array( 'editor', array( 'x' ), 5 ) ),
				'unknown'     => array( 'at' => 1 ),
				'uc_settings' => 'not an array',
			)
		);

		$this->assertSame( array( 'uc_roles', 'uc_audit', 'ure_backup' ), array_keys( $ledger ) );
		$this->assertSame(
			array(
				'at'      => 1760000000,
				'by'      => 3,
				'last_id' => 250,
				'rows'    => 250,
				'job'     => '',
			),
			$ledger['uc_audit']
		);
		$this->assertSame( 0, $ledger['ure_backup']['snapshot_id'] );
		$this->assertSame( array( 'editor', '5' ), $ledger['uc_roles']['roles'] );
		$this->assertSame( array(), BLT_Toolkit_Legacy::clean_ledger( 'junk' ) );
	}

	/**
	 * CONVENTIONS 11: a run that changes nothing leaves the ledger entry, and
	 * its "imported on" time, as they were.
	 *
	 * @return void
	 */
	public function test_spec15_ledger_entry_unchanged_by_noop_run() {
		$stored = array(
			'at'    => 100,
			'by'    => 1,
			'roles' => array( 'contributor', 'shop_helper' ),
		);

		$this->assertTrue( BLT_Toolkit_Legacy::same_entry( $stored, array( 'roles' => array( 'contributor', 'shop_helper' ) ) ) );
		$this->assertTrue(
			BLT_Toolkit_Legacy::same_entry(
				$stored,
				array(
					'at'    => 200,
					'roles' => array( 'contributor', 'shop_helper' ),
				)
			)
		);
		$this->assertFalse( BLT_Toolkit_Legacy::same_entry( $stored, array( 'roles' => array( 'contributor' ) ) ) );
		$this->assertFalse( BLT_Toolkit_Legacy::same_entry( $stored, array( 'snapshot_id' => 3 ) ) );
		$this->assertFalse(
			BLT_Toolkit_Legacy::same_entry(
				array(
					'last_id' => 6,
					'rows'    => 6,
					'job'     => '',
				),
				array(
					'last_id' => 7,
					'rows'    => 7,
					'job'     => '',
				)
			)
		);
	}

	/**
	 * CONVENTIONS 11: the confirm's hash does not depend on key order, and
	 * changes with any value.
	 *
	 * @return void
	 */
	public function test_spec15_plan_hash() {
		$a = BLT_Toolkit_Legacy::plan_hash(
			array(
				'roles'    => array( 'editor' ),
				'settings' => array(
					'min_length'  => 5,
					'notify_user' => true,
				),
			)
		);
		$b = BLT_Toolkit_Legacy::plan_hash(
			array(
				'settings' => array(
					'notify_user' => true,
					'min_length'  => 5,
				),
				'roles'    => array( 'editor' ),
			)
		);
		$c = BLT_Toolkit_Legacy::plan_hash(
			array(
				'settings' => array(
					'notify_user' => true,
					'min_length'  => 6,
				),
				'roles'    => array( 'editor' ),
			)
		);

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertSame( 64, strlen( $a ) );
	}

	// ---------- Overview (SPEC 13.2) ----------

	/**
	 * SPEC 13.2: the activity tiles read switches and username changes from
	 * the grouped counts; a failed read is "unknown", not zero.
	 *
	 * @return void
	 */
	public function test_spec13_overview_activity_counts() {
		$this->assertSame(
			array(
				'switches' => 4,
				'renames'  => 9,
			),
			BLT_Toolkit_Admin_Overview::count_rows(
				array(
					array(
						'module' => 'switch',
						'action' => 'switched_to',
						'total'  => '4',
					),
					array(
						'module' => 'usernames',
						'action' => 'username_changed',
						'total'  => '9',
					),
					array(
						'module' => 'switch',
						'action' => 'switched_back',
						'total'  => '3',
					),
					'junk',
				)
			)
		);

		$this->assertSame(
			array(
				'switches' => 0,
				'renames'  => 0,
			),
			BLT_Toolkit_Admin_Overview::count_rows( array() )
		);

		$this->assertSame(
			array(
				'switches' => null,
				'renames'  => null,
			),
			BLT_Toolkit_Admin_Overview::count_rows( null )
		);
	}
}
