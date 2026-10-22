<?php
/**
 * Authenticated encryption for stored secrets.
 *
 * Copied from BLT Secure's envelope (includes/crypto/class-crypto.php), the
 * family's reference implementation (CONVENTIONS 6.3, 17 item 2). The
 * envelope formats and ciphers are unchanged. What differs: the class name,
 * error codes, text domain and key-derivation label; a test-only backend
 * selector; a readable error instead of a fatal when an AES-GCM envelope
 * meets a server that has lost OpenSSL; and an AES-GCM envelope of an empty
 * string (exactly 28 bytes) now decrypts instead of reading as tampered.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AEAD envelope encryption.
 *
 * Primary: libsodium secretbox (XSalsa20-Poly1305), bundled with PHP since
 * 7.2, so it is the dependable path on restricted shared hosting. Fallback:
 * OpenSSL AES-256-GCM. If neither is available we refuse to encrypt at all;
 * there is no downgrade to unauthenticated ciphers, base64 or plaintext.
 *
 * Envelope formats (versioned so the KDF/cipher can migrate later):
 *   $blt1$ base64( nonce . ciphertext )            sodium secretbox
 *   $blt2$ base64( iv . tag . ciphertext )         AES-256-GCM
 *
 * The key is derived on demand from the WP auth salts and never stored.
 */
class BLT_Toolkit_Crypto {

	const ENVELOPE_SODIUM  = '$blt1$';
	const ENVELOPE_OPENSSL = '$blt2$';

	/**
	 * Injected key for tests; null = derive from WP salts.
	 *
	 * @var string|null
	 */
	private $key_override;

	/**
	 * Backend to encrypt with: 'sodium', 'openssl', or null for the best one
	 * available. Tests pin it to exercise both paths; production never does.
	 *
	 * @var string|null
	 */
	private $backend;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_override Optional 32-byte key (tests only).
	 * @param string|null $backend      Optional 'sodium' or 'openssl' (tests only).
	 */
	public function __construct( $key_override = null, $backend = null ) {
		$this->key_override = $key_override;
		$this->backend      = in_array( $backend, array( 'sodium', 'openssl' ), true ) ? $backend : null;
	}

	/**
	 * Whether an AEAD backend exists on this host.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->sodium_available() || $this->openssl_available();
	}

	/**
	 * Encrypt a string into a versioned envelope.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string|WP_Error Envelope string.
	 */
	public function encrypt( $plaintext ) {
		$key = $this->key();

		if ( 'openssl' !== $this->backend && $this->sodium_available() ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( (string) $plaintext, $nonce, $key );
			return self::ENVELOPE_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		if ( 'sodium' !== $this->backend && $this->openssl_available() ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $cipher ) {
				return self::ENVELOPE_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		return new WP_Error( 'blt_toolkit_no_aead', __( 'No authenticated encryption backend (libsodium or OpenSSL AES-GCM) is available on this server.', 'blt-toolkit' ) );
	}

	/**
	 * Decrypt an envelope produced by encrypt().
	 *
	 * @param string $envelope Stored envelope string.
	 * @return string|WP_Error Plaintext.
	 */
	public function decrypt( $envelope ) {
		$envelope = (string) $envelope;
		$key      = $this->key();

		if ( 0 === strpos( $envelope, self::ENVELOPE_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return new WP_Error( 'blt_toolkit_no_sodium', __( 'This value was encrypted with libsodium, which is no longer available.', 'blt-toolkit' ) );
			}
			$raw = base64_decode( substr( $envelope, strlen( self::ENVELOPE_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return $this->tamper_error();
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return ( false === $plain ) ? $this->tamper_error() : $plain;
		}

		if ( 0 === strpos( $envelope, self::ENVELOPE_OPENSSL ) ) {
			if ( ! $this->openssl_available() ) {
				return new WP_Error( 'blt_toolkit_no_openssl', __( 'This value was encrypted with OpenSSL AES-GCM, which is no longer available.', 'blt-toolkit' ) );
			}
			$raw = base64_decode( substr( $envelope, strlen( self::ENVELOPE_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			// 12 iv + 16 tag; an empty plaintext is exactly 28 bytes, so only
			// shorter is malformed (BLT Secure's copy rejects 28, see lessons).
			if ( false === $raw || strlen( $raw ) < 28 ) {
				return $this->tamper_error();
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return ( false === $plain ) ? $this->tamper_error() : $plain;
		}

		return new WP_Error( 'blt_toolkit_bad_envelope', __( 'Unrecognized encrypted value format.', 'blt-toolkit' ) );
	}

	/**
	 * Whether libsodium secretbox is usable.
	 *
	 * @return bool
	 */
	private function sodium_available() {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Whether OpenSSL AES-256-GCM is usable.
	 *
	 * @return bool
	 */
	private function openssl_available() {
		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	/**
	 * Uniform error for failed authentication (wrong key, tampering, or
	 * rotated salts; callers treat these identically).
	 *
	 * @return WP_Error
	 */
	private function tamper_error() {
		return new WP_Error( 'blt_toolkit_decrypt_failed', __( 'Stored value could not be decrypted. The security keys in wp-config.php may have changed.', 'blt-toolkit' ) );
	}

	/**
	 * Derive the 32-byte key from WP salts.
	 *
	 * @return string
	 */
	private function key() {
		if ( null !== $this->key_override ) {
			return $this->key_override;
		}
		return hash( 'sha256', 'blt-toolkit-v1|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}
}
