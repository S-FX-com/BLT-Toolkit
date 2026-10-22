<?php
/**
 * CIDR matching and Cloudflare-aware client IP choice. WordPress-free.
 *
 * @package Blt_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which address to record for a request.
 *
 * `CF-Connecting-IP` is honored only when `REMOTE_ADDR` is inside Cloudflare's
 * published ranges. Anywhere else the header is attacker-supplied, and
 * trusting it would let a visitor write any address into the log.
 * `X-Forwarded-For` is never trusted (LOG-5: `REMOTE_ADDR`, or
 * `CF-Connecting-IP` from Cloudflare only).
 *
 * Copied unchanged, apart from the names, from BLT Activity Log's
 * `BLT_Activity_Log_Ip`, which carries the same range check as BLT Secure.
 * LOG-5 asks to reuse that check without forking the family layer, so it is
 * vendored here as a plain class rather than added to `includes/blt-family/`
 * (no shared-layer addition is made without asking, CONVENTIONS 16.3, rule
 * 4). Keep the copies in step.
 *
 * The range list is a bundled snapshot, refreshed with each release; this
 * plugin makes no network call to keep it current.
 */
class BLT_Toolkit_Ip {

	/**
	 * Cloudflare's published ranges (snapshot).
	 *
	 * @var string[]
	 */
	const CF_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The address to record.
	 *
	 * @param string        $remote REMOTE_ADDR.
	 * @param string        $cf_ip  CF-Connecting-IP header value ('' when absent).
	 * @param string[]|null $ranges CIDR ranges to trust; null for the bundled list.
	 * @return string The chosen address, or '' when none is a valid IP.
	 */
	public static function resolve( $remote, $cf_ip, $ranges = null ) {
		$remote = (string) $remote;
		$cf_ip  = (string) $cf_ip;
		$ranges = null === $ranges ? self::CF_RANGES : $ranges;

		if ( '' !== $cf_ip && false !== filter_var( $cf_ip, FILTER_VALIDATE_IP ) && self::in_ranges( $remote, $ranges ) ) {
			return $cf_ip;
		}

		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/**
	 * Whether an address falls inside any of the CIDR ranges.
	 *
	 * @param string   $ip     IPv4 or IPv6 address.
	 * @param string[] $ranges CIDR strings.
	 * @return bool
	 */
	public static function in_ranges( $ip, array $ranges ) {
		$packed = self::pack( $ip );

		if ( null === $packed ) {
			return false;
		}

		foreach ( $ranges as $cidr ) {
			if ( self::in_cidr( $packed, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a string is a well-formed CIDR.
	 *
	 * @param mixed $cidr Candidate.
	 * @return bool
	 */
	public static function is_valid_cidr( $cidr ) {
		if ( ! is_string( $cidr ) || false === strpos( $cidr, '/' ) ) {
			return false;
		}

		list( $net, $bits ) = explode( '/', $cidr, 2 );

		$packed = self::pack( $net );

		if ( null === $packed || '' === $bits || ! ctype_digit( $bits ) ) {
			return false;
		}

		return (int) $bits <= ( strlen( $packed ) * 8 );
	}

	/**
	 * Pack an address with inet_pton.
	 *
	 * @param string $ip Address.
	 * @return string|null Packed bytes, or null when malformed.
	 */
	private static function pack( $ip ) {
		if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = inet_pton( $ip );

		return false === $packed ? null : $packed;
	}

	/**
	 * Binary-mask CIDR match.
	 *
	 * @param string $packed Packed address.
	 * @param string $cidr   CIDR string.
	 * @return bool
	 */
	private static function in_cidr( $packed, $cidr ) {
		if ( ! self::is_valid_cidr( $cidr ) ) {
			return false;
		}

		list( $net, $bits ) = explode( '/', $cidr, 2 );

		$net_packed = self::pack( $net );
		$bits       = (int) $bits;

		// Different families (v4 vs v6) pack to different lengths.
		if ( null === $net_packed || strlen( $net_packed ) !== strlen( $packed ) ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		$rem_bits   = $bits % 8;

		if ( $full_bytes > 0 && 0 !== substr_compare( $packed, $net_packed, 0, $full_bytes ) ) {
			return false;
		}

		if ( $rem_bits > 0 ) {
			$mask = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;

			if ( ( ord( $packed[ $full_bytes ] ) & $mask ) !== ( ord( $net_packed[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}
}
