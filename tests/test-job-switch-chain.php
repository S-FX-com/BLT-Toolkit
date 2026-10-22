<?php
/**
 * LOG-2 for background jobs: the switch chain a job pins at start, and the
 * scoped override BLT_Toolkit_Log applies while a tick runs.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Log::with_switch_chain() and the
 * `chain` key of a job's state.
 */
class Test_Job_Switch_Chain extends TestCase {

	/**
	 * Remove any provider a test registered, so other tests see none.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$provider = new ReflectionProperty( 'BLT_Toolkit_Log', 'chain_provider' );
		$provider->setAccessible( true );
		$provider->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Without a pinned chain the provider answers; without a provider the
	 * chain is empty.
	 *
	 * @return void
	 */
	public function test_log2_current_chain_comes_from_the_provider() {
		$this->assertSame( array(), BLT_Toolkit_Log::current_switch_chain(), 'No provider: nobody is switched.' );

		BLT_Toolkit_Log::set_switch_chain_provider(
			static function () {
				return array( 1, 5 );
			}
		);

		$this->assertSame( array( 1, 5 ), BLT_Toolkit_Log::current_switch_chain() );
	}

	/**
	 * A pinned chain replaces the provider's answer inside the callable only,
	 * and the callable's return value comes back.
	 *
	 * @return void
	 */
	public function test_log2_pinned_chain_overrides_provider_in_scope() {
		BLT_Toolkit_Log::set_switch_chain_provider(
			static function () {
				return array( 1, 5 );
			}
		);

		$seen = BLT_Toolkit_Log::with_switch_chain(
			array( 2, 9 ),
			static function () {
				return BLT_Toolkit_Log::current_switch_chain();
			}
		);

		$this->assertSame( array( 2, 9 ), $seen, 'The job started by user 2 switched into 9.' );
		$this->assertSame( array( 1, 5 ), BLT_Toolkit_Log::current_switch_chain(), 'Restored after the tick.' );

		$who = BLT_Toolkit_Log::with_switch_chain(
			array( 2, 9 ),
			static function () {
				// The tick runs as the job's owner (JOB-3), the last user of the chain.
				return BLT_Toolkit_Log::attribute( 9, BLT_Toolkit_Log::current_switch_chain() );
			}
		);

		$this->assertSame( 2, $who['actor_id'], 'The human who signed in is the actor.' );
		$this->assertSame( 9, $who['acting_as_id'] );
	}

	/**
	 * An empty pinned chain means "not switched", whatever the request's own
	 * cookies say: a job started without switching, ticked from a browser
	 * that is switched now.
	 *
	 * @return void
	 */
	public function test_log2_empty_pinned_chain_means_not_switched() {
		BLT_Toolkit_Log::set_switch_chain_provider(
			static function () {
				return array( 1, 5 );
			}
		);

		$seen = BLT_Toolkit_Log::with_switch_chain(
			array(),
			static function () {
				return BLT_Toolkit_Log::current_switch_chain();
			}
		);

		$this->assertSame( array(), $seen );
	}

	/**
	 * Pins nest, are cleaned to positive IDs, and are restored when the
	 * callable throws.
	 *
	 * @return void
	 */
	public function test_log2_pinned_chains_nest_clean_and_restore_on_exception() {
		$inner = null;
		$outer = BLT_Toolkit_Log::with_switch_chain(
			array( '3', 'x', -1, 0, 8 ),
			static function () use ( &$inner ) {
				$inner = BLT_Toolkit_Log::with_switch_chain(
					array( 4, 6 ),
					static function () {
						return BLT_Toolkit_Log::current_switch_chain();
					}
				);

				return BLT_Toolkit_Log::current_switch_chain();
			}
		);

		$this->assertSame( array( 4, 6 ), $inner );
		$this->assertSame( array( 3, 8 ), $outer, 'Junk entries are dropped; the outer pin is back after the inner one.' );

		try {
			BLT_Toolkit_Log::with_switch_chain(
				array( 7, 2 ),
				static function () {
					throw new RuntimeException( 'tick died' );
				}
			);

			$this->fail( 'The exception must reach the caller.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'tick died', $e->getMessage() );
		}

		$this->assertSame( array(), BLT_Toolkit_Log::current_switch_chain(), 'Nothing stays pinned after a throw.' );
	}

	/**
	 * A new job carries an empty chain; a stored state's chain is cleaned on
	 * the way out, and a missing or damaged one reads as empty.
	 *
	 * @return void
	 */
	public function test_log2_job_state_keeps_a_clean_chain() {
		$built = BLT_Toolkit_Jobs::new_job( 'abcdef0123456789', 'username_bulk', 'items', array( 'x' ), array(), 7, 1000 );

		$this->assertSame( array(), $built['job']['chain'] );

		$job          = $built['job'];
		$job['chain'] = array( '1', 'junk', 7 );

		$this->assertSame( array( 1, 7 ), BLT_Toolkit_Jobs::normalize( $job )['chain'] );

		$job['chain'] = 'not a chain';

		$this->assertSame( array(), BLT_Toolkit_Jobs::normalize( $job )['chain'] );

		unset( $job['chain'] );

		$this->assertSame( array(), BLT_Toolkit_Jobs::normalize( $job )['chain'], 'A job stored before LOG-2 pinning.' );
	}
}
