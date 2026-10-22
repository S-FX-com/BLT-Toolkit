<?php
/**
 * Crypto envelope: round trips, tamper detection, no unauthenticated tier.
 *
 * @package Blt_Toolkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Toolkit_Crypto.
 */
class Test_Crypto extends TestCase {

	/**
	 * A fixed 32-byte key.
	 *
	 * @return string
	 */
	private function key() {
		return str_repeat( "\x42", 32 );
	}

	/**
	 * Backends this PHP build supports, for the data providers.
	 *
	 * @return array<string,array{string}>
	 */
	public function backends() {
		return array(
			'sodium'  => array( 'sodium' ),
			'openssl' => array( 'openssl' ),
		);
	}

	/**
	 * Skip a backend this PHP build lacks.
	 *
	 * @param string $backend 'sodium' or 'openssl'.
	 * @return void
	 */
	private function require_backend( $backend ) {
		if ( 'sodium' === $backend && ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium is not available.' );
		}

		if ( 'openssl' === $backend && ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true ) ) ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM is not available.' );
		}
	}

	/**
	 * Encrypt then decrypt returns the plaintext, with the backend's prefix.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend.
	 * @return void
	 */
	public function test_round_trip( $backend ) {
		$this->require_backend( $backend );

		$crypto = new BLT_Toolkit_Crypto( $this->key(), $backend );

		foreach ( array( 'ghp_abcdefghijklmnopqrstuvwxyz0123456789', '', "multi\nline \x00 binary é" ) as $plain ) {
			$envelope = $crypto->encrypt( $plain );

			$this->assertIsString( $envelope );
			$this->assertStringStartsWith( 'sodium' === $backend ? BLT_Toolkit_Crypto::ENVELOPE_SODIUM : BLT_Toolkit_Crypto::ENVELOPE_OPENSSL, $envelope );
			$this->assertSame( $plain, $crypto->decrypt( $envelope ) );
		}
	}

	/**
	 * The plaintext never appears in the envelope, and each encryption is
	 * randomized (fresh nonce or IV).
	 *
	 * @dataProvider backends
	 * @param string $backend Backend.
	 * @return void
	 */
	public function test_envelope_hides_plaintext_and_is_randomized( $backend ) {
		$this->require_backend( $backend );

		$crypto = new BLT_Toolkit_Crypto( $this->key(), $backend );
		$plain  = 'ghp_secrettoken1234567890';
		$one    = $crypto->encrypt( $plain );
		$two    = $crypto->encrypt( $plain );

		$this->assertNotSame( $one, $two );
		$this->assertStringNotContainsString( $plain, $one );
		$this->assertStringNotContainsString( base64_encode( $plain ), $one ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Flipping any byte of the payload is detected, never decrypted to junk.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend.
	 * @return void
	 */
	public function test_tamper_is_detected( $backend ) {
		$this->require_backend( $backend );

		$crypto   = new BLT_Toolkit_Crypto( $this->key(), $backend );
		$envelope = $crypto->encrypt( 'ghp_secret' );
		$prefix   = substr( $envelope, 0, 6 );
		$raw      = base64_decode( substr( $envelope, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		for ( $i = 0; $i < strlen( $raw ); $i++ ) {
			$tampered      = $raw;
			$tampered[ $i ] = chr( ord( $tampered[ $i ] ) ^ 0x01 );

			$result = $crypto->decrypt( $prefix . base64_encode( $tampered ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

			$this->assertInstanceOf( WP_Error::class, $result, "Byte {$i} flipped." );
			$this->assertSame( 'blt_toolkit_decrypt_failed', $result->get_error_code() );
		}
	}

	/**
	 * A different key (rotated salts) fails authentication.
	 *
	 * @dataProvider backends
	 * @param string $backend Backend.
	 * @return void
	 */
	public function test_wrong_key_fails( $backend ) {
		$this->require_backend( $backend );

		$envelope = ( new BLT_Toolkit_Crypto( $this->key(), $backend ) )->encrypt( 'ghp_secret' );
		$result   = ( new BLT_Toolkit_Crypto( str_repeat( "\x43", 32 ), $backend ) )->decrypt( $envelope );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'blt_toolkit_decrypt_failed', $result->get_error_code() );
	}

	/**
	 * Truncated payloads are tamper errors, not warnings.
	 *
	 * @return void
	 */
	public function test_truncated_envelopes_fail() {
		$crypto = new BLT_Toolkit_Crypto( $this->key() );

		foreach ( array( '$blt1$', '$blt1$AAAA', '$blt2$', '$blt2$AAAA', '$blt1$!!not base64!!' ) as $envelope ) {
			$result = $crypto->decrypt( $envelope );

			$this->assertInstanceOf( WP_Error::class, $result, $envelope );
		}
	}

	/**
	 * Nothing but the two authenticated formats is accepted: no base64 or
	 * plaintext tier (CONVENTIONS 17, item 2).
	 *
	 * @return void
	 */
	public function test_no_unauthenticated_tier() {
		$crypto = new BLT_Toolkit_Crypto( $this->key() );

		foreach ( array( 'ghp_plaintext', base64_encode( 'ghp_plaintext' ), '$blt0$' . base64_encode( 'ghp_plaintext' ), '' ) as $envelope ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$result = $crypto->decrypt( $envelope );

			$this->assertInstanceOf( WP_Error::class, $result, $envelope );
			$this->assertSame( 'blt_toolkit_bad_envelope', $result->get_error_code() );
		}
	}

	/**
	 * An envelope from either backend decrypts with the default instance, so
	 * a host that changes backend keeps reading what it stored.
	 *
	 * @return void
	 */
	public function test_default_instance_reads_both_formats() {
		$this->require_backend( 'sodium' );
		$this->require_backend( 'openssl' );

		$default = new BLT_Toolkit_Crypto( $this->key() );

		$this->assertSame( 'one', $default->decrypt( ( new BLT_Toolkit_Crypto( $this->key(), 'sodium' ) )->encrypt( 'one' ) ) );
		$this->assertSame( 'two', $default->decrypt( ( new BLT_Toolkit_Crypto( $this->key(), 'openssl' ) )->encrypt( 'two' ) ) );
		$this->assertTrue( $default->is_available() );
	}

	/**
	 * Without an injected key, the key is derived from the salts: the same
	 * salts read back, rotated salts do not.
	 *
	 * @return void
	 */
	public function test_key_is_derived_from_salts() {
		blt_test_reset();

		$envelope = ( new BLT_Toolkit_Crypto() )->encrypt( 'ghp_secret' );

		$this->assertSame( 'ghp_secret', ( new BLT_Toolkit_Crypto() )->decrypt( $envelope ) );

		$GLOBALS['blt_test_salt'] = 'rotated';

		$this->assertInstanceOf( WP_Error::class, ( new BLT_Toolkit_Crypto() )->decrypt( $envelope ) );

		blt_test_reset();
	}
}
