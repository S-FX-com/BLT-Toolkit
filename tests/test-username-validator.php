<?php
/**
 * The username validator (UN-1 to UN-9) with known vectors (SPEC 18).
 *
 * check() runs with every lookup injected. sanitize_user( $x, true ) and
 * validate_username() answer from outputs recorded on a real WordPress
 * (tests/fixtures/sanitize-user-vectors.json), so UN-1's suggestions are
 * exactly what core produces. validate() and context_for() run against the
 * bootstrap shims and the Settings class.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Username_Validator.
 */
class Test_Username_Validator extends TestCase {

	/**
	 * Recorded core outputs.
	 *
	 * @var array|null
	 */
	private static $core = null;

	/**
	 * Users on the pretend site: ID => login and email.
	 *
	 * @var array
	 */
	private $users = array(
		1 => array(
			'login' => 'admin',
			'email' => 'owner@example.com',
		),
		2 => array(
			'login' => 'jsmith',
			'email' => 'jsmith@example.com',
		),
		3 => array(
			'login' => 'carol',
			'email' => 'carol@example.com',
		),
		4 => array(
			'login' => 'carol@example.com',
			'email' => 'c2@example.com',
		),
	);

	/**
	 * Fresh state for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blt_test_reset();

		$GLOBALS['blt_test_users'] = $this->users;
	}

	/**
	 * Clean up the pretend users.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['blt_test_users'] );
	}

	/**
	 * The recorded core outputs, decoded once.
	 *
	 * @return array
	 */
	private static function core() {
		if ( null === self::$core ) {
			self::$core = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/sanitize-user-vectors.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return self::$core;
	}

	/**
	 * A context for renaming jsmith (ID 2), with core's recorded answers and
	 * case-insensitive lookups over $this->users (as MySQL compares).
	 *
	 * @param array $over Keys to replace.
	 * @return array
	 */
	private function context( array $over = array() ) {
		$core  = self::core();
		$users = $this->users;

		return array_merge(
			array(
				'user_id'           => 2,
				'current_login'     => 'jsmith',
				'min_length'        => 3,
				'max_length'        => 60,
				'disallowed'        => array( 'admin', 'administrator', 'root', 'sysadmin', 'webmaster' ),
				'allow_at'          => true,
				'login_owner'       => function ( $login ) use ( $users ) {
					foreach ( $users as $id => $user ) {
						if ( strtolower( $user['login'] ) === strtolower( $login ) ) {
							return $id;
						}
					}

					return 0;
				},
				'email_owner'       => function ( $email ) use ( $users ) {
					foreach ( $users as $id => $user ) {
						if ( strtolower( $user['email'] ) === strtolower( $email ) ) {
							return $id;
						}
					}

					return 0;
				},
				'sanitize_user'     => function ( $name, $strict ) use ( $core ) {
					if ( true !== $strict || ! array_key_exists( $name, $core['strict'] ) ) {
						throw new RuntimeException( 'No recorded sanitize_user() output for: ' . $name );
					}

					return $core['strict'][ $name ];
				},
				'validate_username' => function ( $name ) use ( $core ) {
					if ( ! array_key_exists( $name, $core['valid'] ) ) {
						throw new RuntimeException( 'No recorded validate_username() output for: ' . $name );
					}

					return $core['valid'][ $name ];
				},
			),
			$over
		);
	}

	/**
	 * Run check() for jsmith.
	 *
	 * @param string $new  Proposed name.
	 * @param array  $over Context keys to replace.
	 * @return WP_Error
	 */
	private function check( $new, array $over = array() ) {
		return BLT_Toolkit_Username_Validator::check( $new, $this->context( $over ) );
	}

	/**
	 * Assert the result failed with one code (and only one error).
	 *
	 * @param string   $code   Expected code.
	 * @param WP_Error $errors Result.
	 * @param string   $label  Message.
	 * @return void
	 */
	private function assertFails( $code, $errors, $label = '' ) {
		$this->assertInstanceOf( WP_Error::class, $errors, $label );
		$this->assertSame( array( $code ), $errors->get_error_codes(), $label );
		$this->assertNotSame( '', $errors->get_error_message(), $label );
		$this->assertFalse( BLT_Toolkit_Username_Validator::is_valid( $errors ), $label );
	}

	/**
	 * Assert the result passed.
	 *
	 * @param WP_Error $errors Result.
	 * @param string   $label  Message.
	 * @return void
	 */
	private function assertPasses( $errors, $label = '' ) {
		$this->assertInstanceOf( WP_Error::class, $errors, $label );
		$this->assertSame( array(), $errors->get_error_codes(), $label . ' ' . $errors->get_error_message() );
		$this->assertFalse( $errors->has_errors(), $label );
		$this->assertTrue( BLT_Toolkit_Username_Validator::is_valid( $errors ), $label );
	}

	/**
	 * UN-1: a name the strict sanitizer changes is refused, and the error
	 * carries core's cleaned name as the suggestion.
	 *
	 * @return void
	 */
	public function test_un1_sanitize_rejects_with_core_suggestion() {
		$cases = array(
			'José'          => 'Jose',
			'José García'   => 'Jose Garcia',
			'<b>bob</b>'    => 'bob',
			'tom&amp;jerry' => 'tomjerry',
			'a%20b'         => 'ab',
			'bob#1'         => 'bob1',
			'ünïcödé'       => 'unicode',
			'a  b'          => 'a b',
		);

		foreach ( $cases as $input => $suggestion ) {
			$errors = $this->check( $input );

			$this->assertFails( 'blt_toolkit_username_sanitize', $errors, $input );
			$this->assertSame(
				array(
					'rule'       => 'UN-1',
					'suggestion' => $suggestion,
				),
				$errors->get_error_data(),
				$input
			);
			$this->assertStringContainsString( 'Try ' . $suggestion . ' instead.', $errors->get_error_message(), $input );
			$this->assertStringContainsString( '. - _ @', $errors->get_error_message() );
		}
	}

	/**
	 * UN-1: when nothing survives sanitizing, there is no suggestion to offer.
	 *
	 * @return void
	 */
	public function test_un1_no_suggestion_when_nothing_survives() {
		foreach ( array( '!!!', '李小龍' ) as $input ) {
			$errors = $this->check( $input );

			$this->assertFails( 'blt_toolkit_username_sanitize', $errors, $input );
			$this->assertSame( '', $errors->get_error_data()['suggestion'] );
			$this->assertStringNotContainsString( 'Try', $errors->get_error_message() );
		}
	}

	/**
	 * UN-1: the name is trimmed before any rule, and normalize() gives
	 * callers the same trimmed value to store.
	 *
	 * @return void
	 */
	public function test_un1_trims_before_checking() {
		$this->assertPasses( $this->check( "  padded \t" ) );
		$this->assertSame( 'padded', BLT_Toolkit_Username_Validator::normalize( "  padded \t" ) );
		$this->assertSame( '', BLT_Toolkit_Username_Validator::normalize( array( 'x' ) ) );
		$this->assertSame( '12', BLT_Toolkit_Username_Validator::normalize( 12 ) );
	}

	/**
	 * UN-1 with UN-7: when `@` is not allowed, the suggestion leaves it out
	 * and the message does not list it.
	 *
	 * @return void
	 */
	public function test_un1_suggestion_respects_un7() {
		$errors = $this->check( 'bob@home!', array( 'allow_at' => false ) );

		$this->assertFails( 'blt_toolkit_username_sanitize', $errors );
		$this->assertSame( 'bobhome', $errors->get_error_data()['suggestion'] );
		$this->assertStringContainsString( 'symbols: . - _. Try bobhome instead.', $errors->get_error_message() );

		$this->assertSame( 'bob@home', $this->check( 'bob@home!' )->get_error_data()['suggestion'], 'Kept when allowed.' );
	}

	/**
	 * UN-2 (UC-4, UC-6): the minimum and the 60-character maximum hold on
	 * the server; 60 passes and 61 does not.
	 *
	 * @return void
	 */
	public function test_un2_length_bounds() {
		$this->assertFails( 'blt_toolkit_username_length', $this->check( 'ab' ) );
		$this->assertPasses( $this->check( 'abc' ) );
		$this->assertPasses( $this->check( str_repeat( 'a', 59 ) ) );
		$this->assertPasses( $this->check( str_repeat( 'a', 60 ) ) );

		$errors = $this->check( str_repeat( 'a', 61 ) );

		$this->assertFails( 'blt_toolkit_username_length', $errors );
		$this->assertSame( 'Usernames can be at most 60 characters long.', $errors->get_error_message() );
		$this->assertSame(
			array(
				'rule' => 'UN-2',
				'min'  => 3,
				'max'  => 60,
			),
			$errors->get_error_data()
		);

		$this->assertSame( 'Usernames must be at least 3 characters long.', $this->check( 'ab' )->get_error_message() );
		$this->assertFails( 'blt_toolkit_username_length', $this->check( '' ), 'An empty name is too short.' );
		$this->assertFails( 'blt_toolkit_username_length', $this->check( '   ' ), 'Spaces alone trim to empty.' );
	}

	/**
	 * UN-2: the minimum comes from the context (the setting and its filter),
	 * a minimum below 1 still refuses an empty name, and a maximum above 60
	 * is clamped to the column limit.
	 *
	 * @return void
	 */
	public function test_un2_min_from_context_and_max_clamped() {
		$this->assertFails( 'blt_toolkit_username_length', $this->check( 'carol2', array( 'min_length' => 7 ) ) );
		$this->assertSame( 'Usernames must be at least 1 character long.', $this->check( '', array( 'min_length' => 0 ) )->get_error_message() );
		$this->assertPasses( $this->check( 'a', array( 'min_length' => 1 ) ) );
		$this->assertFails( 'blt_toolkit_username_length', $this->check( str_repeat( 'a', 61 ), array( 'max_length' => 255 ) ) );
		$this->assertFails( 'blt_toolkit_username_length', $this->check( 'abc', array( 'max_length' => 2 ) ) );
	}

	/**
	 * UN-3: validate_username() has the last word on what core accepts (its
	 * `validate_username` filter can refuse a name the sanitizer keeps).
	 *
	 * @return void
	 */
	public function test_un3_validate_username() {
		$errors = $this->check(
			'reserved',
			array(
				'validate_username' => function () {
					return false;
				},
			)
		);

		$this->assertFails( 'blt_toolkit_username_invalid', $errors );
		$this->assertSame( 'UN-3', $errors->get_error_data()['rule'] );
	}

	/**
	 * UN-4 (UC-5): `Admin` is refused when `admin` is disallowed, and the
	 * list is lowercased too, so `ADMIN` in the list refuses `admin`;
	 * entries are trimmed and non-strings ignored.
	 *
	 * @return void
	 */
	public function test_un4_disallowed_case_insensitive_on_both_sides() {
		$this->assertFails( 'blt_toolkit_username_disallowed', $this->check( 'Admin' ) );
		$this->assertFails( 'blt_toolkit_username_disallowed', $this->check( 'ADMIN' ) );
		$this->assertFails( 'blt_toolkit_username_disallowed', $this->check( 'WebMaster' ) );
		$this->assertFails( 'blt_toolkit_username_disallowed', $this->check( 'admin', array( 'disallowed' => array( 'ADMIN' ) ) ) );
		$this->assertFails( 'blt_toolkit_username_disallowed', $this->check( 'blocked', array( 'disallowed' => array( '  Blocked ' ) ) ) );
		$this->assertPasses( $this->check( 'blocked', array( 'disallowed' => array( array( 'blocked' ), null, 'other' ) ) ) );
		$this->assertPasses( $this->check( 'Root', array( 'disallowed' => array() ) ) );
		$this->assertFails( 'blt_toolkit_username_taken', $this->check( 'Admin', array( 'disallowed' => array() ) ), 'Not disallowed, but user 1 owns it.' );
	}

	/**
	 * UN-5: a login another user owns is taken, in any case.
	 *
	 * @return void
	 */
	public function test_un5_taken_by_another_user() {
		$this->assertFails( 'blt_toolkit_username_taken', $this->check( 'carol' ) );
		$this->assertFails( 'blt_toolkit_username_taken', $this->check( 'Carol' ) );
		$this->assertPasses( $this->check( 'alice' ) );
	}

	/**
	 * UN-5 (SPEC 17 Phase 3, criterion d): `jsmith` to `JSmith` passes,
	 * because the lookup returns the user's own ID.
	 *
	 * @return void
	 */
	public function test_un5_case_only_change_is_allowed() {
		$this->assertPasses( $this->check( 'JSmith' ) );
	}

	/**
	 * UN-6: another user's email address is refused (any case); the user's
	 * own address and an unused one are fine.
	 *
	 * @return void
	 */
	public function test_un6_another_users_email() {
		$this->assertPasses( $this->check( 'bob@example.com' ), 'Nobody uses it.' );

		$this->assertFails(
			'blt_toolkit_username_email',
			$this->check(
				'CAROL@EXAMPLE.COM',
				array(
					'login_owner' => function () {
						return 0;
					},
				)
			)
		);
		$this->assertPasses( $this->check( 'jsmith@example.com' ), 'Their own address.' );
	}

	/**
	 * UN-6: only a name containing `@` can be an address, so no lookup runs
	 * for any other name.
	 *
	 * @return void
	 */
	public function test_un6_email_lookup_only_for_at_names() {
		$asked = array();
		$spy   = function ( $email ) use ( &$asked ) {
			$asked[] = $email;

			return 0;
		};

		$this->check( 'alice', array( 'email_owner' => $spy ) );
		$this->check( 'x@y', array( 'email_owner' => $spy ) );

		$this->assertSame( array( 'x@y' ), $asked );
	}

	/**
	 * UN-7 (D13): `@` is allowed by default and refused when the setting
	 * turns it off.
	 *
	 * @return void
	 */
	public function test_un7_at_sign_toggle() {
		$this->assertPasses( $this->check( 'bob@example.com' ) );
		$this->assertFails( 'blt_toolkit_username_at_sign', $this->check( 'bob@example.com', array( 'allow_at' => false ) ) );
		$this->assertFails( 'blt_toolkit_username_at_sign', $this->check( 'bob@', array( 'allow_at' => false ) ) );
		$this->assertPasses( $this->check( 'bob', array( 'allow_at' => false ) ) );
	}

	/**
	 * UN-8: the current username is refused, but only when identical.
	 *
	 * @return void
	 */
	public function test_un8_unchanged() {
		$this->assertFails( 'blt_toolkit_username_unchanged', $this->check( 'jsmith' ) );
		$this->assertFails( 'blt_toolkit_username_unchanged', $this->check( ' jsmith ' ), 'Trimmed first.' );
		$this->assertPasses( $this->check( 'JSmith' ) );
	}

	/**
	 * Rule order: a name that breaks several rules gets the first one's
	 * error, and later rules' lookups do not run.
	 *
	 * @return void
	 */
	public function test_rules_run_in_spec_order() {
		$lookups = 0;
		$count   = function () use ( &$lookups ) {
			++$lookups;

			return 3;
		};

		// UN-1 before UN-2: too short and unclean.
		$this->assertFails( 'blt_toolkit_username_sanitize', $this->check( 'a%20b', array( 'min_length' => 5 ) ) );

		// UN-2 before UN-4: too short and disallowed.
		$this->assertFails( 'blt_toolkit_username_length', $this->check( 'ab', array( 'disallowed' => array( 'ab' ) ) ) );

		// UN-3 before UN-4.
		$this->assertFails(
			'blt_toolkit_username_invalid',
			$this->check(
				'admin',
				array(
					'validate_username' => function () {
						return false;
					},
				)
			)
		);

		// UN-4 before UN-5: disallowed and taken; the owner lookup is skipped.
		$this->assertFails(
			'blt_toolkit_username_disallowed',
			$this->check(
				'carol',
				array(
					'disallowed'  => array( 'carol' ),
					'login_owner' => $count,
				)
			)
		);
		$this->assertSame( 0, $lookups );

		// UN-5 before UN-6: user 4's login is also user 3's email.
		$this->assertFails( 'blt_toolkit_username_taken', $this->check( 'carol@example.com' ) );

		// UN-6 before UN-7: another user's email, with `@` refused.
		$this->assertFails(
			'blt_toolkit_username_email',
			$this->check(
				'carol@example.com',
				array(
					'allow_at'    => false,
					'login_owner' => function () {
						return 0;
					},
				)
			)
		);

		// UN-7 before UN-8: the current login holds `@`, which is now refused.
		$this->assertFails(
			'blt_toolkit_username_at_sign',
			$this->check(
				'jsmith@example.com',
				array(
					'allow_at'      => false,
					'current_login' => 'jsmith@example.com',
				)
			)
		);

		// UN-4 before UN-8: retyping a disallowed current login.
		$this->assertFails(
			'blt_toolkit_username_disallowed',
			BLT_Toolkit_Username_Validator::check(
				'admin',
				$this->context(
					array(
						'user_id'       => 1,
						'current_login' => 'admin',
					)
				)
			)
		);
	}

	/**
	 * Every error's data names its rule.
	 *
	 * @return void
	 */
	public function test_error_data_names_the_rule() {
		$no_owner = function () {
			return 0;
		};
		$rules    = array(
			'UN-1' => $this->check( 'bob!' ),
			'UN-2' => $this->check( 'ab' ),
			'UN-4' => $this->check( 'Root' ),
			'UN-5' => $this->check( 'carol' ),
			'UN-6' => $this->check( 'carol@example.com', array( 'login_owner' => $no_owner ) ),
			'UN-7' => $this->check( 'x@y', array( 'allow_at' => false ) ),
			'UN-8' => $this->check( 'jsmith' ),
		);

		foreach ( $rules as $rule => $errors ) {
			$this->assertSame( $rule, $errors->get_error_data()['rule'], $rule );
		}
	}

	/**
	 * Settings stored for the Usernames group.
	 *
	 * @param array $usernames Stored `usernames` keys.
	 * @return void
	 */
	private function store_settings( array $usernames ) {
		update_option( BLT_Toolkit_Settings::OPTION, array( 'usernames' => $usernames ) );
		BLT_Toolkit_Settings::flush();
	}

	/**
	 * Context for a stored user.
	 *
	 * @param int $id User ID.
	 * @return WP_User
	 */
	private function user( $id ) {
		return new WP_User(
			array(
				'ID'         => $id,
				'user_login' => $this->users[ $id ]['login'],
				'user_email' => $this->users[ $id ]['email'],
			)
		);
	}

	/**
	 * UN-2, UN-4, UN-7: context_for() reads the settings and applies the
	 * filters: the minimum through `blt_toolkit_username_min_length`, the
	 * setting's list through `blt_toolkit_disallowed_usernames`, merged with
	 * core's `illegal_user_logins`.
	 *
	 * @return void
	 */
	public function test_context_for_reads_settings_and_filters() {
		$defaults = BLT_Toolkit_Username_Validator::context_for( $this->user( 2 ) );

		$this->assertSame( 2, $defaults['user_id'] );
		$this->assertSame( 'jsmith', $defaults['current_login'] );
		$this->assertSame( 3, $defaults['min_length'] );
		$this->assertSame( 60, $defaults['max_length'] );
		$this->assertSame( array( 'admin', 'administrator', 'root', 'sysadmin', 'webmaster' ), $defaults['disallowed'] );
		$this->assertTrue( $defaults['allow_at'] );

		$this->store_settings(
			array(
				'min_length'    => 5,
				'disallowed'    => array( 'Reserved' ),
				'allow_at_sign' => false,
			)
		);

		add_filter(
			'blt_toolkit_username_min_length',
			function ( $length ) {
				return $length + 1;
			}
		);
		add_filter(
			'blt_toolkit_disallowed_usernames',
			function ( $names ) {
				$names[] = 'fromfilter';

				return $names;
			}
		);
		add_filter(
			'illegal_user_logins',
			function ( $names ) {
				$names[] = 'FromCore';

				return $names;
			}
		);

		$context = BLT_Toolkit_Username_Validator::context_for( $this->user( 2 ) );

		$this->assertSame( 6, $context['min_length'] );
		$this->assertSame( array( 'reserved', 'fromfilter', 'FromCore' ), $context['disallowed'] );
		$this->assertFalse( $context['allow_at'] );
		$this->assertSame( 3, call_user_func( $context['login_owner'], 'Carol' ) );
		$this->assertSame( 0, call_user_func( $context['login_owner'], 'nobody' ) );
		$this->assertSame( 3, call_user_func( $context['email_owner'], 'carol@example.com' ) );
	}

	/**
	 * SPEC 17 Phase 3, criterion d, through validate(): `jsmith` to `JSmith`
	 * succeeds; `Admin` is refused while `admin` is disallowed; another
	 * user's email is refused; 61 characters are refused; the minimum holds.
	 *
	 * @return void
	 */
	public function test_phase3_criterion_d_through_validate() {
		$jsmith = $this->user( 2 );

		$this->assertPasses( BLT_Toolkit_Username_Validator::validate( 'JSmith', $jsmith ) );
		$this->assertFails( 'blt_toolkit_username_disallowed', BLT_Toolkit_Username_Validator::validate( 'Admin', $jsmith ) );
		$this->assertFails( 'blt_toolkit_username_email', BLT_Toolkit_Username_Validator::validate( 'owner@example.com', $jsmith ) );
		$this->assertFails( 'blt_toolkit_username_length', BLT_Toolkit_Username_Validator::validate( str_repeat( 'b', 61 ), $jsmith ) );
		$this->assertPasses( BLT_Toolkit_Username_Validator::validate( str_repeat( 'b', 60 ), $jsmith ) );
		$this->assertFails( 'blt_toolkit_username_length', BLT_Toolkit_Username_Validator::validate( 'jo', $jsmith ) );

		$this->store_settings( array( 'min_length' => 8 ) );

		$this->assertFails( 'blt_toolkit_username_length', BLT_Toolkit_Username_Validator::validate( 'johnny', $jsmith ) );
	}

	/**
	 * UN-9: validate() passes the errors, the trimmed name and the user to
	 * `blt_toolkit_validate_username`, which can add an error or clear one.
	 *
	 * @return void
	 */
	public function test_un9_filter_adds_and_removes_errors() {
		$seen = array();

		add_filter(
			'blt_toolkit_validate_username',
			function ( $errors, $new, $user ) use ( &$seen ) {
				$seen[] = array( $new, $user->ID );

				if ( 'blocked' === $new ) {
					$errors->add( 'my_plugin_blocked', 'Blocked by my plugin.' );
				}

				if ( 'Admin' === $new ) {
					return new WP_Error();
				}

				return $errors;
			}
		);

		$jsmith = $this->user( 2 );
		$errors = BLT_Toolkit_Username_Validator::validate( '  blocked ', $jsmith );

		$this->assertSame( array( 'my_plugin_blocked' ), $errors->get_error_codes() );
		$this->assertPasses( BLT_Toolkit_Username_Validator::validate( 'Admin', $jsmith ), 'The filter cleared UN-4.' );
		$this->assertSame(
			array(
				array( 'blocked', 2 ),
				array( 'Admin', 2 ),
			),
			$seen
		);
	}

	/**
	 * UN-9: a filter that returns something other than a WP_Error refuses
	 * the name instead of letting it through unchecked.
	 *
	 * @return void
	 */
	public function test_un9_filter_breaking_the_contract_fails_closed() {
		add_filter(
			'blt_toolkit_validate_username',
			function () {
				return true;
			}
		);

		$errors = BLT_Toolkit_Username_Validator::validate( 'alice', $this->user( 2 ) );

		$this->assertFails( 'blt_toolkit_username_invalid', $errors );
		$this->assertSame( 'UN-9', $errors->get_error_data()['rule'] );
	}

	/**
	 * UN-30: validate() takes context overrides, so the bulk rename can
	 * answer UN-5 from the batch's projected state while every other rule
	 * and the filter still run.
	 *
	 * @return void
	 */
	public function test_validate_with_projected_login_owner() {
		$jsmith = $this->user( 2 );
		$freed  = array(
			'login_owner' => function ( $login ) {
				return 'carol' === strtolower( $login ) ? 0 : (int) username_exists( $login );
			},
		);

		$this->assertFails( 'blt_toolkit_username_taken', BLT_Toolkit_Username_Validator::validate( 'carol', $jsmith ) );
		$this->assertPasses( BLT_Toolkit_Username_Validator::validate( 'carol', $jsmith, $freed ), 'Carol is renamed earlier in the batch.' );
		$this->assertFails( 'blt_toolkit_username_disallowed', BLT_Toolkit_Username_Validator::validate( 'root', $jsmith, $freed ) );
	}
}
