<?php
/**
 * Ip: CIDR matching and Cloudflare header trust (LOG-5).
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Toolkit_Ip.
 */
class Test_Ip extends TestCase {

	/**
	 * IPv4 in and out of range, on and off byte boundaries.
	 *
	 * @return void
	 */
	public function test_log5_ipv4_ranges() {
		$ranges = array( '173.245.48.0/20', '103.21.244.0/22', '10.0.0.0/8', '192.168.1.5/32' );

		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '173.245.48.1', $ranges ) );
		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '173.245.63.255', $ranges ), 'Last address of the /20.' );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '173.245.64.0', $ranges ), 'First address past the /20.' );
		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '103.21.247.255', $ranges ), 'Last address of the /22.' );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '103.21.248.0', $ranges ) );
		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '10.200.1.1', $ranges ), 'Whole-byte prefix.' );
		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '192.168.1.5', $ranges ), 'A single host.' );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '192.168.1.6', $ranges ) );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '8.8.8.8', $ranges ) );
	}

	/**
	 * IPv6 in and out of range, and no cross-family matches.
	 *
	 * @return void
	 */
	public function test_log5_ipv6_ranges() {
		$ranges = array( '2606:4700::/32', '2a06:98c0::/29', '2400:cb00::/32' );

		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '2606:4700:10::1', $ranges ) );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '2606:4701::1', $ranges ) );
		$this->assertTrue( BLT_Toolkit_Ip::in_ranges( '2a06:98c7:ffff::1', $ranges ), 'Inside the /29 (not on a byte boundary).' );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '2a06:98c8::1', $ranges ), 'Just past the /29.' );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '1.2.3.4', $ranges ), 'An IPv4 address never matches an IPv6 range.' );
		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '2606:4700::1', array( '173.245.48.0/20' ) ), 'And the reverse.' );
	}

	/**
	 * Malformed input never matches and never raises.
	 *
	 * @return void
	 */
	public function test_log5_malformed_input() {
		$ranges = array( '173.245.48.0/20' );

		foreach ( array( '', 'not-an-ip', '999.1.1.1', '1.2.3', '1.2.3.4.5', '::gggg' ) as $ip ) {
			$this->assertFalse( BLT_Toolkit_Ip::in_ranges( $ip, $ranges ), $ip );
		}

		$this->assertFalse( BLT_Toolkit_Ip::in_ranges( '173.245.48.1', array( 'garbage', '173.245.48.0/99', '/20', '173.245.48.0/', '' ) ), 'Bad ranges are skipped.' );
	}

	/**
	 * CIDR validity.
	 *
	 * @return void
	 */
	public function test_log5_is_valid_cidr() {
		$this->assertTrue( BLT_Toolkit_Ip::is_valid_cidr( '10.0.0.0/8' ) );
		$this->assertTrue( BLT_Toolkit_Ip::is_valid_cidr( '2400:cb00::/32' ) );
		$this->assertTrue( BLT_Toolkit_Ip::is_valid_cidr( '1.2.3.4/32' ) );
		$this->assertFalse( BLT_Toolkit_Ip::is_valid_cidr( '1.2.3.4/33' ) );
		$this->assertFalse( BLT_Toolkit_Ip::is_valid_cidr( '1.2.3.4' ) );
		$this->assertFalse( BLT_Toolkit_Ip::is_valid_cidr( '1.2.3.4/' ) );
		$this->assertFalse( BLT_Toolkit_Ip::is_valid_cidr( '1.2.3.4/-1' ) );
		$this->assertFalse( BLT_Toolkit_Ip::is_valid_cidr( 'x/8' ) );
		$this->assertFalse( BLT_Toolkit_Ip::is_valid_cidr( null ) );
	}

	/**
	 * Every bundled Cloudflare range is well formed.
	 *
	 * @return void
	 */
	public function test_log5_bundled_ranges_are_valid() {
		foreach ( BLT_Toolkit_Ip::CF_RANGES as $cidr ) {
			$this->assertTrue( BLT_Toolkit_Ip::is_valid_cidr( $cidr ), $cidr );
		}
	}

	/**
	 * The Cloudflare header is trusted only from a Cloudflare address.
	 *
	 * @return void
	 */
	public function test_log5_cf_header_is_honored_only_from_cloudflare() {
		$this->assertSame( '203.0.113.9', BLT_Toolkit_Ip::resolve( '173.245.48.10', '203.0.113.9' ), 'From a Cloudflare edge: trust it.' );
		$this->assertSame( '203.0.113.9', BLT_Toolkit_Ip::resolve( '2606:4700::1', '203.0.113.9' ), 'IPv6 edge address.' );
		$this->assertSame( '198.51.100.7', BLT_Toolkit_Ip::resolve( '198.51.100.7', '203.0.113.9' ), 'From anywhere else the header is attacker-supplied.' );
	}

	/**
	 * A bad header value is ignored even from Cloudflare.
	 *
	 * @return void
	 */
	public function test_log5_bad_cf_header_is_ignored() {
		$this->assertSame( '173.245.48.10', BLT_Toolkit_Ip::resolve( '173.245.48.10', 'not-an-ip' ) );
		$this->assertSame( '173.245.48.10', BLT_Toolkit_Ip::resolve( '173.245.48.10', '' ) );
	}

	/**
	 * No usable address gives an empty string, never garbage.
	 *
	 * @return void
	 */
	public function test_log5_unusable_remote_gives_empty_string() {
		$this->assertSame( '', BLT_Toolkit_Ip::resolve( '', '' ) );
		$this->assertSame( '', BLT_Toolkit_Ip::resolve( 'garbage', '203.0.113.9' ) );
	}

	/**
	 * A caller can supply its own ranges.
	 *
	 * @return void
	 */
	public function test_log5_custom_ranges() {
		$this->assertSame( '1.1.1.1', BLT_Toolkit_Ip::resolve( '10.0.0.5', '1.1.1.1', array( '10.0.0.0/8' ) ) );
		$this->assertSame( '173.245.48.10', BLT_Toolkit_Ip::resolve( '173.245.48.10', '1.1.1.1', array( '10.0.0.0/8' ) ), 'The bundled list is not consulted.' );
	}
}
