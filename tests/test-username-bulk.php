<?php
/**
 * Bulk rename (UN-30): the pure parts of BLT_Toolkit_Username_Bulk that turn
 * a dry run into the `username_bulk` job's items.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Username_Bulk::items_from_plan(),
 * describe(), can_use() and pinned_settings().
 */
class Test_Username_Bulk extends TestCase {

	/**
	 * Fresh shimmed options and settings for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();
	}

	/**
	 * A plan row as the planner returns it.
	 *
	 * @param int    $line     CSV line.
	 * @param int    $user_id  User.
	 * @param string $old      Current login.
	 * @param string $new_name New login.
	 * @param string $status   Planner status.
	 * @param array  $warnings Warnings.
	 * @return array
	 */
	private function row( $line, $user_id, $old, $new_name, $status = 'ready', array $warnings = array() ) {
		return array(
			'line'     => $line,
			'user_id'  => $user_id,
			'old'      => $old,
			'new'      => $new_name,
			'status'   => $status,
			'code'     => '',
			'reason'   => '',
			'warnings' => $warnings,
		);
	}

	/**
	 * UN-30 step 6: the items follow the plan's execution order (bob to
	 * carol before alice to bob), only ready rows become items, and a row
	 * the dry run flagged for a switched session carries the confirmation
	 * (UN-12) while the others do not.
	 *
	 * @return void
	 */
	public function test_un30_items_follow_plan_order_and_carry_un12_confirmation() {
		$plan = array(
			'rows'  => array(
				$this->row( 2, 2, 'alice', 'bob' ),
				$this->row( 3, 3, 'bob', 'carol', 'ready', array( 'switched' => 'Someone is switched in.' ) ),
				$this->row( 4, 5, 'dave', 'dave', 'no_change' ),
				$this->row( 5, 6, 'erin', 'admin', 'error' ),
			),
			'order' => array( 1, 0 ),
		);

		$this->assertSame(
			array(
				array(
					'user_id'            => 3,
					'old'                => 'bob',
					'new'                => 'carol',
					'line'               => 3,
					'confirmed_switched' => true,
				),
				array(
					'user_id'            => 2,
					'old'                => 'alice',
					'new'                => 'bob',
					'line'               => 2,
					'confirmed_switched' => false,
				),
			),
			BLT_Toolkit_Username_Bulk::items_from_plan( $plan )
		);
	}

	/**
	 * An order that names a row that is not ready, or not there, adds
	 * nothing; an empty or damaged plan gives no items (the start refuses
	 * it).
	 *
	 * @return void
	 */
	public function test_un30_items_ignore_rows_that_cannot_run() {
		$plan = array(
			'rows'  => array(
				$this->row( 2, 2, 'alice', 'bob', 'error' ),
				$this->row( 3, 3, 'bob', 'carol' ),
			),
			'order' => array( 0, 7, 1 ),
		);

		$items = BLT_Toolkit_Username_Bulk::items_from_plan( $plan );

		$this->assertCount( 1, $items );
		$this->assertSame( 3, $items[0]['user_id'] );
		$this->assertSame( array(), BLT_Toolkit_Username_Bulk::items_from_plan( array() ) );
		$this->assertSame( array(), BLT_Toolkit_Username_Bulk::items_from_plan( array( 'rows' => 'x', 'order' => 'y' ) ) );
	}

	/**
	 * JOB-4: an item reads as "old to new" in the progress panel.
	 *
	 * @return void
	 */
	public function test_un30_item_label() {
		$this->assertSame(
			'bob to carol',
			BLT_Toolkit_Username_Bulk::describe(
				array(
					'old' => 'bob',
					'new' => 'carol',
				)
			)
		);
		$this->assertSame( '', BLT_Toolkit_Username_Bulk::describe( 'bob' ) );
	}

	/**
	 * UN-30: a stored plan round-trips through pack() and unpack(), shrinks,
	 * and damaged storage reads as no plan rather than a broken one.
	 *
	 * @return void
	 */
	public function test_un30_stored_plan_round_trips_compressed() {
		$rows = array();

		for ( $i = 1; $i <= 500; $i++ ) {
			$rows[] = $this->row( $i + 1, $i, 'user' . $i, 'user' . $i . '-new', 'ready', array( 'author_url' => 'The author URL will change.' ) );
		}

		$plan = array(
			'rows'    => $rows,
			'order'   => range( 0, 499 ),
			'token'   => 'abcdef0123456789',
			'user_id' => 1,
		);

		$packed = BLT_Toolkit_Username_Bulk::pack( $plan );

		$this->assertArrayHasKey( 'packed', $packed );
		$this->assertLessThan( strlen( serialize( $plan ) ) / 4, strlen( $packed['packed'] ), 'Compressed well below the serialized size.' );
		$this->assertSame( $plan, BLT_Toolkit_Username_Bulk::unpack( $packed ) );
		$this->assertSame( $plan, BLT_Toolkit_Username_Bulk::unpack( $plan ), 'A plan stored without zlib reads as is.' );
		$this->assertNull( BLT_Toolkit_Username_Bulk::unpack( array( 'packed' => 'not base64 !' ) ) );
		$this->assertNull( BLT_Toolkit_Username_Bulk::unpack( array( 'packed' => base64_encode( 'not zlib' ) ) ) );
		$this->assertNull( BLT_Toolkit_Username_Bulk::unpack( false ), 'An expired transient.' );
	}

	/**
	 * SEC-6, LOG-2, review fix: only the person who started a bulk rename, or
	 * a toolkit administrator, may follow, cancel or retry it; another holder
	 * of `blt_change_usernames` may not, so its logins stay hidden and its
	 * retries cannot run in the owner's name.
	 *
	 * @return void
	 */
	public function test_sec6_bulk_job_is_its_owners() {
		$job = array( 'owner' => 7 );

		$this->assertTrue( BLT_Toolkit_Username_Bulk::can_use( $job, new WP_User( array( 'ID' => 7, 'roles' => array( 'umanager' ) ) ) ) );
		$this->assertFalse( BLT_Toolkit_Username_Bulk::can_use( $job, new WP_User( array( 'ID' => 8, 'roles' => array( 'umanager' ) ) ) ) );
		$this->assertTrue( BLT_Toolkit_Username_Bulk::can_use( $job, new WP_User( array( 'ID' => 1, 'roles' => array( 'administrator' ) ) ) ) );
		$this->assertFalse( BLT_Toolkit_Username_Bulk::can_use( array( 'owner' => 0 ), new WP_User( array( 'ID' => 0 ) ) ), 'Nobody signed in is nobody\'s owner.' );
		$this->assertFalse( BLT_Toolkit_Username_Bulk::can_use( array(), new WP_User( array( 'ID' => 7 ) ) ) );
	}

	/**
	 * JOB-3, review fix: the author URL policy is pinned from the dry run
	 * (the policy its warnings used), the email's subject and body from the
	 * settings at start; a plan without a valid policy falls back to the
	 * setting.
	 *
	 * @return void
	 */
	public function test_job3_pins_author_url_policy_and_email() {
		$GLOBALS['blt_test_options']['blt_toolkit_settings'] = array(
			'usernames' => array(
				'nicename_policy' => 'always',
				'notify_user'     => true,
				'sign_out_others' => false,
				'email_subject'   => 'Subject {sitename}',
				'email_body'      => 'Body {new_username}',
			),
		);
		BLT_Toolkit_Settings::flush();

		$pinned = BLT_Toolkit_Username_Bulk::pinned_settings(
			array(
				'token'           => 'abc',
				'nicename_policy' => 'never',
			)
		);

		$this->assertSame( 'never', $pinned['nicename_policy'], 'The dry run\'s policy wins over a later change.' );
		$this->assertTrue( $pinned['notify'] );
		$this->assertFalse( $pinned['sign_out_others'] );
		$this->assertSame( 'Subject {sitename}', $pinned['email_subject'] );
		$this->assertSame( 'Body {new_username}', $pinned['email_body'] );
		$this->assertSame( 'abc', $pinned['batch'] );

		$this->assertSame( 'always', BLT_Toolkit_Username_Bulk::pinned_settings( array( 'nicename_policy' => 'sometimes' ) )['nicename_policy'] );
		$this->assertSame( 'always', BLT_Toolkit_Username_Bulk::pinned_settings( array() )['nicename_policy'] );
	}
}
