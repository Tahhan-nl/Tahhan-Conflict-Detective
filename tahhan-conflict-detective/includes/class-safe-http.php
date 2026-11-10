<?php
/**
 * SSRF-hardened outbound HTTP for admin-configured URLs.
 *
 * @package TahhanConflictDetective
 * @since   2.8.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Used for every request to a URL an administrator typed in (agency hub
 * polling, alert webhooks).
 *
 * - Core's wp_http_validate_url() misses link-local (cloud metadata), CGNAT and
 *   IPv6 internal ranges and resolves IPv4 only. We resolve A + AAAA ourselves
 *   and reject the host if ANY address is non-public.
 * - The connection is pinned to the vetted address (CURLOPT_RESOLVE), so DNS
 *   rebinding between check and connect is impossible. Requests that are not
 *   carried by cURL, or that would go through an HTTP proxy, fail closed.
 * - HTTPS on port 443 only (filters allow HTTP for test networks), no redirects.
 *
 * Moved out of Agency in 2.8.0 so webhooks share the exact same protections.
 *
 * @since 2.8.0
 */
final class Safe_Http {

	const TIMEOUT = 6;

	private const BLOCKED_V4 = array(
		'0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
		'192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
		'203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
	);
	private const BLOCKED_V6 = array(
		'::/96', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32',
		'2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
	);

	/** @var array{host: string, port: int, ip: string, applied: bool}|null Active DNS pin. */
	private static $pin = null;

	public static function insecure_allowed( string $url ): bool {
		return (bool) apply_filters( 'tahcd_allow_insecure_remote', false, $url );
	}

	/**
	 * Validates an admin-supplied URL: scheme, port and that every resolved
	 * address is public.
	 *
	 * @return array{url: string, scheme: string, host: string, port: int, path: string, ip: string}|\WP_Error
	 */
	public static function check_url( string $input ) {
		$url   = esc_url_raw( trim( $input ), array( 'https', 'http' ) );
		$parts = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'tahcd_bad_url', __( 'Enter a valid URL, e.g. https://example.com', 'tahhan-conflict-detective' ) );
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! self::insecure_allowed( $url ) ) {
			return new \WP_Error( 'tahcd_https_required', __( 'HTTPS is required — secrets would otherwise be sent in plain text.', 'tahhan-conflict-detective' ) );
		}

		$host = strtolower( rtrim( $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		$allowed_ports = array_map( 'intval', (array) apply_filters( 'tahcd_remote_allowed_ports', self::insecure_allowed( $url ) ? array( 443, 80 ) : array( 443 ) ) );
		if ( ! in_array( $port, $allowed_ports, true ) ) {
			return new \WP_Error( 'tahcd_port_blocked', __( 'Only the standard HTTPS port (443) is allowed.', 'tahhan-conflict-detective' ) );
		}

		$ip = self::resolve_public_ip( $host );
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		return array(
			'url'    => $url,
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'path'   => (string) ( $parts['path'] ?? '/' ),
			'ip'     => $ip,
		);
	}

	/**
	 * Resolves A and AAAA records and returns one address to pin, but only if
	 * EVERY address is public — a single internal record rejects the host.
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_public_ip( string $host ) {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips = array( $host );
		} else {
			$ips = gethostbynamel( $host ) ?: array();
			if ( function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- emits a warning on NXDOMAIN; failure is handled below.
				foreach ( is_array( $records ) ? $records : array() as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return new \WP_Error( 'tahcd_dns', __( 'The host name could not be resolved.', 'tahhan-conflict-detective' ) );
		}

		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new \WP_Error( 'tahcd_url_blocked', __( 'This address is not allowed: local, private-network, link-local and reserved addresses are blocked.', 'tahhan-conflict-detective' ) );
			}
		}

		// Prefer IPv4 for the pin; it is the most widely supported by cURL builds.
		foreach ( $ips as $ip ) {
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return $ip;
			}
		}
		return $ips[0];
	}

	public static function is_public_ip( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$list = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? self::BLOCKED_V4 : self::BLOCKED_V6;
		foreach ( $list as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return false;
			}
		}
		return true;
	}

	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		list( $subnet, $bits ) = explode( '/', $cidr );
		$ip_bin  = inet_pton( $ip );
		$sub_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $sub_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( ( 0xFF << ( 8 - $rem ) ) & 0xFF );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $sub_bin[ $bytes ] & $mask );
	}

	/**
	 * Performs one request to $url with all protections applied.
	 *
	 * @param  array<string, mixed> $args wp_safe_remote_request() arguments; redirection
	 *                                    and limit_response_size are enforced.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function request( string $url, array $args ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return new \WP_Error( 'tahcd_no_curl', __( 'The PHP cURL extension is required for outbound requests.', 'tahhan-conflict-detective' ) );
		}

		// Through an HTTP proxy the proxy resolves the host, so the IP pin would
		// not protect against DNS rebinding.
		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			return new \WP_Error( 'tahcd_proxy', __( 'Not available while an outbound HTTP proxy (WP_PROXY_HOST) is configured.', 'tahhan-conflict-detective' ) );
		}

		// Re-validated on every request: DNS may have changed since the URL was saved.
		$checked = self::check_url( $url );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		self::$pin = array(
			'host'    => $checked['host'],
			'port'    => $checked['port'],
			'ip'      => $checked['ip'],
			'applied' => false,
		);

		$args = array_merge(
			array( 'timeout' => self::TIMEOUT, 'limit_response_size' => 65536 ),
			$args,
			array( 'redirection' => 0 )
		);

		add_action( 'http_api_curl', array( __CLASS__, 'apply_pin' ), 10, 3 );
		try {
			$response = wp_safe_remote_request( $checked['url'], $args );
			$pinned   = self::$pin['applied'];
		} finally {
			remove_action( 'http_api_curl', array( __CLASS__, 'apply_pin' ), 10 );
			self::$pin = null;
		}

		// Fail closed: without the pin the request may have bypassed the IP check.
		if ( ! $pinned ) {
			return new \WP_Error( 'tahcd_not_pinned', __( 'Request was not sent through cURL, so its destination could not be verified.', 'tahhan-conflict-detective' ) );
		}

		return $response;
	}

	/** True for errors raised by our own guards (messages are safe to show as-is). */
	public static function is_guard_error( \WP_Error $error ): bool {
		return 0 === strpos( (string) $error->get_error_code(), 'tahcd_' );
	}

	/**
	 * http_api_curl callback: pins the connection to the vetted IP and restricts
	 * the protocol. Only active for the duration of one request().
	 *
	 * @param resource|\CurlHandle $handle cURL handle.
	 * @param array                $args   Request arguments (unused).
	 * @param string               $url    Request URL.
	 */
	public static function apply_pin( $handle, $args, $url ): void {
		if ( null === self::$pin || strtolower( rtrim( (string) wp_parse_url( (string) $url, PHP_URL_HOST ), '.' ) ) !== self::$pin['host'] ) {
			return;
		}
		$ip = self::$pin['ip'];
		$ip = false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $ip . ']' : $ip;

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WP exposes no API to pin DNS; this is the documented http_api_curl extension point.
		curl_setopt( $handle, CURLOPT_RESOLVE, array( self::$pin['host'] . ':' . self::$pin['port'] . ':' . $ip ) );
		curl_setopt( $handle, CURLOPT_PROTOCOLS, self::insecure_allowed( (string) $url ) ? ( CURLPROTO_HTTPS | CURLPROTO_HTTP ) : CURLPROTO_HTTPS );
		// phpcs:enable
		self::$pin['applied'] = true;
	}
}
