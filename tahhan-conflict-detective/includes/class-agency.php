<?php
/**
 * Agency hub — monitor many sites from one dashboard.
 *
 * @package TahhanConflictDetective
 * @since   2.7.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores remote sites (URL + their API key) and polls each site's
 * /tahhan-cd/v1/status endpoint on a cron schedule.
 *
 * Security notes
 * --------------
 * - SSRF: core's wp_http_validate_url() misses link-local (cloud metadata),
 *   CGNAT and IPv6 internal ranges and resolves IPv4 only. We therefore resolve
 *   A + AAAA ourselves, reject the host if ANY address is non-public, and pin
 *   cURL to the vetted address (CURLOPT_RESOLVE) so DNS rebinding between check
 *   and connect is impossible. Requests that do not go through cURL fail closed.
 * - No redirects are followed, so the key header never reaches another host.
 * - HTTPS only (protocol and port 443) unless explicitly allowed via filter.
 * - Remote keys are encrypted at rest with libsodium secretbox, key derived from
 *   the AUTH salt. There is no plaintext fallback.
 * - Remote responses are untrusted: every field is type-cast, whitelisted and
 *   length-limited before storage, and escaped again on output.
 *
 * @since 2.7.0
 */
final class Agency {

	const SITES_OPTION    = 'tahcd_remote_sites';
	const STATUS_OPTION   = 'tahcd_remote_status';
	const SETTINGS_OPTION = 'tahcd_agency_settings';
	const CRON_HOOK       = 'tahcd_agency_poll';
	const MAX_SITES       = 50;
	const INTERVALS       = array( 5 => 'tahcd_5min', 15 => 'tahcd_15min', 60 => 'hourly' );

	private const REQUEST_TIMEOUT    = 6;
	private const MAX_RESPONSE_BYTES = 65536;
	/** Upper bound for one cron run; lowered further to fit max_execution_time. */
	private const POLL_TIME_BUDGET   = 25;
	/** Minimum seconds between manual "Check now" requests for one site. */
	private const MANUAL_POLL_GAP    = 15;

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

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll_all' ) );

		foreach ( array( 'generate_key', 'revoke_key', 'add_site', 'remove_site', 'poll_site', 'save_settings' ) as $action ) {
			add_action( 'wp_ajax_tahcd_agency_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Registered at file-load time (not in init()) so the schedules exist in
	 * every request that may call wp_schedule_event or run cron.
	 *
	 * @param  mixed $schedules Existing schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules['tahcd_5min']  = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every 5 minutes', 'tahhan-conflict-detective' ) );
		$schedules['tahcd_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes', 'tahhan-conflict-detective' ) );
		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Storage
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{label: string, url: string, key: string, added: int}>
	 */
	public static function get_sites(): array {
		$raw = get_option( self::SITES_OPTION );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$sites = array();
		foreach ( $raw as $id => $site ) {
			if ( ! is_array( $site ) || empty( $site['url'] ) || empty( $site['key'] ) ) {
				continue;
			}
			$sites[ (string) $id ] = array(
				'label' => (string) ( $site['label'] ?? '' ),
				'url'   => (string) $site['url'],
				'key'   => (string) $site['key'],
				'added' => (int) ( $site['added'] ?? 0 ),
			);
		}
		return $sites;
	}

	/**
	 * @return array<string, array{checked_at: int, ok: bool, error: string, data: array<string, mixed>}>
	 */
	public static function get_statuses(): array {
		$raw = get_option( self::STATUS_OPTION );
		return is_array( $raw ) ? $raw : array();
	}

	public static function get_interval(): int {
		$raw      = get_option( self::SETTINGS_OPTION );
		$interval = is_array( $raw ) && isset( $raw['interval'] ) ? (int) $raw['interval'] : 15;
		return array_key_exists( $interval, self::INTERVALS ) ? $interval : 15;
	}

	private static function save_sites( array $sites ): void {
		update_option( self::SITES_OPTION, $sites, false );
		self::sync_schedule();
	}

	/**
	 * Writes one site's status, merging with the latest stored statuses so a
	 * concurrent cron run and a manual check never overwrite each other.
	 */
	private static function save_status( string $id, array $status ): void {
		$statuses        = self::get_statuses();
		$statuses[ $id ] = $status;
		update_option( self::STATUS_OPTION, array_intersect_key( $statuses, self::get_sites() ), false );
	}

	/**
	 * Ensures the cron event matches the stored sites and interval. Called on
	 * admin_init (restores the event after deactivate/reactivate) and on change.
	 */
	public static function sync_schedule(): void {
		$recurrence = self::INTERVALS[ self::get_interval() ];
		$event      = wp_get_scheduled_event( self::CRON_HOOK );

		if ( empty( self::get_sites() ) ) {
			if ( $event ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		if ( $event && $event->schedule === $recurrence ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, self::CRON_HOOK );
	}

	// -------------------------------------------------------------------------
	// Validation (SSRF guard)
	// -------------------------------------------------------------------------

	private static function insecure_allowed( string $url ): bool {
		return (bool) apply_filters( 'tahcd_allow_insecure_remote', false, $url );
	}

	/**
	 * Normalises and validates a remote site URL.
	 *
	 * @return string|\WP_Error Normalised base URL with trailing slash.
	 */
	public static function validate_url( string $input ) {
		$url   = esc_url_raw( trim( $input ), array( 'https', 'http' ) );
		$parts = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'tahcd_bad_url', __( 'Enter a valid site URL, e.g. https://example.com', 'tahhan-conflict-detective' ) );
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! self::insecure_allowed( $url ) ) {
			return new \WP_Error( 'tahcd_https_required', __( 'HTTPS is required — the API key would otherwise be sent in plain text.', 'tahhan-conflict-detective' ) );
		}

		$host = strtolower( rtrim( $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		$allowed_ports = array_map( 'intval', (array) apply_filters( 'tahcd_remote_allowed_ports', self::insecure_allowed( $url ) ? array( 443, 80 ) : array( 443 ) ) );
		if ( ! in_array( $port, $allowed_ports, true ) ) {
			return new \WP_Error( 'tahcd_port_blocked', __( 'Only the standard HTTPS port (443) is allowed.', 'tahhan-conflict-detective' ) );
		}

		$normalised = $scheme . '://' . $host
			. ( isset( $parts['port'] ) ? ':' . $port : '' )
			. trailingslashit( $parts['path'] ?? '/' );

		$ip = self::resolve_public_ip( $host );
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		return $normalised;
	}

	/**
	 * Resolves A and AAAA records and returns one address to pin, but only if
	 * EVERY address is public — a single internal record rejects the host.
	 *
	 * @return string|\WP_Error
	 */
	private static function resolve_public_ip( string $host ) {
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
	 * http_api_curl callback: pins the connection to the vetted IP and restricts
	 * the protocol. Only active for the duration of one fetch().
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

	// -------------------------------------------------------------------------
	// Polling
	// -------------------------------------------------------------------------

	public static function poll_all(): void {
		$sites    = self::get_sites();
		$statuses = self::get_statuses();
		$start    = microtime( true );

		$max_exec = (int) ini_get( 'max_execution_time' );
		$budget   = $max_exec > 0 ? min( self::POLL_TIME_BUDGET, $max_exec - 5 ) : self::POLL_TIME_BUDGET;
		// A single site can take two requests (404 → ?rest_route= fallback).
		$per_site = 2 * self::REQUEST_TIMEOUT;

		// Oldest-checked first so a budget cut-off never starves the same sites.
		uksort( $sites, static function ( $a, $b ) use ( $statuses ) {
			return ( $statuses[ $a ]['checked_at'] ?? 0 ) <=> ( $statuses[ $b ]['checked_at'] ?? 0 );
		} );

		$polled = 0;
		foreach ( $sites as $id => $site ) {
			// Always poll at least one site, even on hosts with a tiny time limit.
			if ( $polled > 0 && microtime( true ) - $start + $per_site > $budget ) {
				break;
			}
			$polled++;
			// Saved per site: if PHP is killed mid-run, completed results survive.
			self::save_status( $id, self::fetch( $site ) );
		}
	}

	public static function poll_one( string $id ): ?array {
		$sites = self::get_sites();
		if ( ! isset( $sites[ $id ] ) ) {
			return null;
		}
		$status = self::fetch( $sites[ $id ] );
		self::save_status( $id, $status );
		return $status;
	}

	/**
	 * @param  array{label: string, url: string, key: string, added: int} $site
	 * @return array{checked_at: int, ok: bool, error: string, data: array<string, mixed>}
	 */
	private static function fetch( array $site ): array {
		$result = array( 'checked_at' => time(), 'ok' => false, 'error' => '', 'data' => array() );

		if ( ! function_exists( 'curl_init' ) ) {
			$result['error'] = __( 'The PHP cURL extension is required for remote monitoring.', 'tahhan-conflict-detective' );
			return $result;
		}

		// Through an HTTP proxy the proxy resolves the host, so the IP pin would
		// not protect against DNS rebinding. Fail closed.
		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $site['url'] ) ) {
			$result['error'] = __( 'Remote monitoring is not available while an outbound HTTP proxy (WP_PROXY_HOST) is configured.', 'tahhan-conflict-detective' );
			return $result;
		}

		$key = self::decrypt( $site['key'] );
		if ( null === $key ) {
			$result['error'] = __( 'Stored API key could not be decrypted (security salts changed?). Remove and re-add this site.', 'tahhan-conflict-detective' );
			return $result;
		}

		// Re-validate at request time: DNS may have changed since the site was added.
		$parts = wp_parse_url( $site['url'] );
		$host  = is_array( $parts ) ? strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) ) : '';
		$ip    = '' !== $host ? self::resolve_public_ip( $host ) : new \WP_Error( 'tahcd_bad_url', '' );
		if ( is_wp_error( $ip ) ) {
			$result['error'] = '' !== $ip->get_error_message() ? $ip->get_error_message() : __( 'Invalid stored URL.', 'tahhan-conflict-detective' );
			return $result;
		}

		$scheme    = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		self::$pin = array(
			'host'    => $host,
			'port'    => isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 ),
			'ip'      => $ip,
			'applied' => false,
		);

		$args = array(
			'timeout'             => self::REQUEST_TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'user-agent'          => 'TahhanConflictDetective/' . TAHCD_VERSION,
			'headers'             => array(
				Rest_Api::HEADER => $key,
				'Accept'         => 'application/json',
			),
		);

		add_action( 'http_api_curl', array( __CLASS__, 'apply_pin' ), 10, 3 );
		try {
			$base     = trailingslashit( $site['url'] );
			$response = wp_safe_remote_get( $base . 'wp-json/' . Rest_Api::NAMESPACE_V1 . Rest_Api::ROUTE, $args );

			// Sites without pretty permalinks only answer on ?rest_route=.
			if ( ! is_wp_error( $response ) && 404 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$response = wp_safe_remote_get(
					add_query_arg( 'rest_route', '/' . Rest_Api::NAMESPACE_V1 . Rest_Api::ROUTE, $base ),
					$args
				);
			}
			$pinned = self::$pin['applied'];
		} finally {
			remove_action( 'http_api_curl', array( __CLASS__, 'apply_pin' ), 10 );
			self::$pin = null;
		}

		// Fail closed: without the pin the request may have bypassed the IP check.
		if ( ! $pinned ) {
			$result['error'] = __( 'Request was not sent through cURL, so its destination could not be verified.', 'tahhan-conflict-detective' );
			return $result;
		}

		if ( is_wp_error( $response ) ) {
			$result['error'] = sprintf(
				/* translators: %s: transport error message */
				__( 'Unreachable: %s', 'tahhan-conflict-detective' ),
				self::str( $response->get_error_message(), 150 )
			);
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$result['error'] = self::http_error_message( $code );
			return $result;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$result['error'] = __( 'Invalid response (not JSON).', 'tahhan-conflict-detective' );
			return $result;
		}

		$result['ok']   = true;
		$result['data'] = self::sanitize_remote( $body );
		return $result;
	}

	private static function http_error_message( int $code ): string {
		switch ( $code ) {
			case 301:
			case 302:
			case 307:
			case 308:
				return __( 'Redirected — enter the final URL of the site (check https:// and www).', 'tahhan-conflict-detective' );
			case 401:
			case 403:
				return __( 'API key rejected, expired, or revoked. Generate a new key on the remote site and re-add it here.', 'tahhan-conflict-detective' );
			case 404:
				return __( 'Status endpoint not found. Is Conflict Detective 2.7 or newer active on that site?', 'tahhan-conflict-detective' );
			case 429:
				return __( 'Rate limited by the remote site after failed attempts. Wait 15 minutes.', 'tahhan-conflict-detective' );
			default:
				/* translators: %d: HTTP status code */
				return sprintf( __( 'Unexpected HTTP status %d.', 'tahhan-conflict-detective' ), $code );
		}
	}

	/**
	 * Reduces an untrusted remote payload to a fixed, typed structure.
	 *
	 * @param  array<mixed> $body Decoded JSON.
	 * @return array<string, mixed>
	 */
	private static function sanitize_remote( array $body ): array {
		$sub = static function ( string $key ) use ( $body ): array {
			return isset( $body[ $key ] ) && is_array( $body[ $key ] ) ? $body[ $key ] : array();
		};

		$conflicts = $sub( 'conflicts' );
		$top       = isset( $conflicts['top'] ) && is_array( $conflicts['top'] ) ? $conflicts['top'] : array();
		$errors    = $sub( 'errors' );
		$health    = $sub( 'health' );
		$env       = $sub( 'environment' );
		$site      = $sub( 'site' );
		$change    = $sub( 'last_change' );
		$status    = isset( $body['status'] ) && is_string( $body['status'] ) ? $body['status'] : '';

		$change_text = '';
		if ( $change ) {
			$change_text = trim( self::str( $change['plugin'] ?? '', 100 ) . ' — ' . self::str( $change['action'] ?? '', 20 ), ' —' );
		}

		return array(
			'status'         => in_array( $status, array( 'ok', 'warning', 'critical' ), true ) ? $status : 'unknown',
			'site_name'      => self::str( $site['name'] ?? '', 100 ),
			'plugin_version' => self::str( $body['plugin_version'] ?? '', 20 ),
			'wp'             => self::str( $env['wp'] ?? '', 20 ),
			'php'            => self::str( $env['php'] ?? '', 20 ),
			'conflicts'      => self::count_val( $conflicts['count'] ?? 0 ),
			'top_plugin'     => self::str( $top['plugin'] ?? '', 100 ),
			'top_confidence' => min( 100, self::count_val( $top['confidence'] ?? 0 ) ),
			'errors_total'   => self::count_val( $errors['total'] ?? 0 ),
			'fatal_24h'      => self::count_val( $errors['fatal_24h'] ?? 0 ),
			'health_issues'  => self::count_val( $health['issues'] ?? 0 ),
			'last_change'    => $change_text,
		);
	}

	/**
	 * @param mixed $value Untrusted scalar.
	 */
	private static function str( $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	}

	/**
	 * @param mixed $value Untrusted scalar.
	 */
	private static function count_val( $value ): int {
		// Clamp as float first: casting e.g. 1e30 to int is undefined/warns on PHP 8.
		return is_numeric( $value ) ? (int) max( 0.0, min( 1000000.0, (float) $value ) ) : 0;
	}

	// -------------------------------------------------------------------------
	// Encryption at rest for remote keys
	// -------------------------------------------------------------------------

	private static function sodium_ready(): bool {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) && file_exists( ABSPATH . WPINC . '/sodium_compat/autoload.php' ) ) {
			require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
		}
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' );
	}

	private static function crypto_key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ) . '|tahcd-remote-keys', '', 32 );
	}

	/**
	 * @return string|null Null when encryption is unavailable — the caller must refuse to store the key.
	 */
	private static function encrypt( string $plain ): ?string {
		if ( ! self::sodium_ready() ) {
			return null;
		}
		$nonce = random_bytes( 24 );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::crypto_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext encoding for option storage.
	}

	private static function decrypt( string $stored ): ?string {
		if ( strlen( $stored ) < 4 || 0 !== strpos( $stored, 'v1:' ) || ! self::sodium_ready() ) {
			return null;
		}
		$payload = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext decoding.
		if ( false === $payload || strlen( $payload ) <= 24 ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $payload, 24 ), substr( $payload, 0, 24 ), self::crypto_key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $plain ) && 1 === preg_match( Api_Keys::PATTERN, $plain ) ? $plain : null;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	private static function guard(): bool {
		check_ajax_referer( 'tahcd_nonce', 'nonce' );
		if ( ! current_user_can( Dashboard::sensitive_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'tahhan-conflict-detective' ) ), 403 );
			return false;
		}
		return true;
	}

	private static function post_string( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
		return isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	public static function ajax_generate_key(): void {
		if ( ! self::guard() ) {
			return;
		}
		$expiry = self::post_string( 'expiry' );
		$key    = Api_Keys::generate( '' === $expiry ? 90 : (int) $expiry );
		wp_send_json_success( array(
			'key'     => $key,
			'message' => __( 'New API key generated. Copy it now — it will not be shown again.', 'tahhan-conflict-detective' ),
		) );
	}

	public static function ajax_revoke_key(): void {
		if ( ! self::guard() ) {
			return;
		}
		Api_Keys::revoke();
		wp_send_json_success( array( 'message' => __( 'API key revoked. Remote monitoring of this site is now disabled.', 'tahhan-conflict-detective' ) ) );
	}

	public static function ajax_add_site(): void {
		if ( ! self::guard() ) {
			return;
		}

		$sites = self::get_sites();
		if ( count( $sites ) >= self::MAX_SITES ) {
			/* translators: %d: maximum number of sites */
			wp_send_json_error( array( 'message' => sprintf( __( 'You can monitor at most %d sites.', 'tahhan-conflict-detective' ), self::MAX_SITES ) ) );
			return;
		}

		$url = self::validate_url( self::post_string( 'url' ) );
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array( 'message' => $url->get_error_message() ) );
			return;
		}

		foreach ( $sites as $site ) {
			if ( $site['url'] === $url ) {
				wp_send_json_error( array( 'message' => __( 'This site is already in the list.', 'tahhan-conflict-detective' ) ) );
				return;
			}
		}

		$key = self::post_string( 'key' );
		if ( 1 !== preg_match( Api_Keys::PATTERN, $key ) ) {
			wp_send_json_error( array( 'message' => __( 'The API key format is invalid. It starts with "tahcd_" followed by 64 hex characters.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		$encrypted = self::encrypt( $key );
		if ( null === $encrypted ) {
			wp_send_json_error( array( 'message' => __( 'Encryption (libsodium) is unavailable on this server, so the API key cannot be stored safely.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		$label = mb_substr( self::post_string( 'label' ), 0, 80 );
		if ( '' === $label ) {
			$label = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		$id           = bin2hex( random_bytes( 6 ) );
		$sites[ $id ] = array(
			'label' => $label,
			'url'   => $url,
			'key'   => $encrypted,
			'added' => time(),
		);
		self::save_sites( $sites );

		$status = self::poll_one( $id );

		wp_send_json_success( array(
			'message' => ( $status && $status['ok'] )
				? __( 'Site added and reachable.', 'tahhan-conflict-detective' )
				: __( 'Site added, but the first check failed — see the status column.', 'tahhan-conflict-detective' ),
		) );
	}

	public static function ajax_remove_site(): void {
		if ( ! self::guard() ) {
			return;
		}
		$id    = self::post_string( 'id' );
		$sites = self::get_sites();
		if ( ! isset( $sites[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown site.', 'tahhan-conflict-detective' ) ) );
			return;
		}
		unset( $sites[ $id ] );
		self::save_sites( $sites );
		update_option( self::STATUS_OPTION, array_intersect_key( self::get_statuses(), $sites ), false );

		wp_send_json_success( array(
			'message' => __( 'Site removed.', 'tahhan-conflict-detective' ),
			'empty'   => empty( $sites ),
		) );
	}

	public static function ajax_poll_site(): void {
		if ( ! self::guard() ) {
			return;
		}
		$id       = self::post_string( 'id' );
		$previous = self::get_statuses()[ $id ]['checked_at'] ?? 0;
		if ( time() - (int) $previous < self::MANUAL_POLL_GAP ) {
			wp_send_json_error( array( 'message' => __( 'This site was checked a moment ago. Please wait a few seconds.', 'tahhan-conflict-detective' ) ) );
			return;
		}
		$status = self::poll_one( $id );
		if ( null === $status ) {
			wp_send_json_error( array( 'message' => __( 'Unknown site.', 'tahhan-conflict-detective' ) ) );
			return;
		}
		wp_send_json_success( array(
			'message' => $status['ok'] ? __( 'Status updated.', 'tahhan-conflict-detective' ) : $status['error'],
		) );
	}

	public static function ajax_save_settings(): void {
		if ( ! self::guard() ) {
			return;
		}
		$interval = (int) self::post_string( 'interval' );
		if ( ! array_key_exists( $interval, self::INTERVALS ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid interval.', 'tahhan-conflict-detective' ) ) );
			return;
		}
		update_option( self::SETTINGS_OPTION, array( 'interval' => $interval ), false );
		self::sync_schedule();
		wp_send_json_success( array( 'message' => __( 'Polling interval saved.', 'tahhan-conflict-detective' ) ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( Dashboard::sensitive_cap() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tahhan-conflict-detective' ) );
		}

		echo '<div class="tahcd-agency">';
		echo '<div id="tahcd-agency-notice" aria-live="polite"></div>';
		self::render_sites_card();
		self::render_key_card();
		self::render_alerts_card();
		echo '</div>';
	}

	private static function render_sites_card(): void {
		$sites    = self::get_sites();
		$statuses = self::get_statuses();
		$interval = self::get_interval();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Monitored Sites', 'tahhan-conflict-detective' ) . '</h2>';
		echo '<label class="tahcd-inline-field" for="tahcd-agency-interval">' . esc_html__( 'Check every', 'tahhan-conflict-detective' ) . '</label>';
		echo '<select id="tahcd-agency-interval">';
		foreach ( array_keys( self::INTERVALS ) as $minutes ) {
			printf(
				'<option value="%d"%s>%s</option>',
				absint( $minutes ),
				selected( $interval, $minutes, false ),
				/* translators: %d: number of minutes */
				esc_html( sprintf( _n( '%d minute', '%d minutes', $minutes, 'tahhan-conflict-detective' ), $minutes ) )
			);
		}
		echo '</select>';
		echo '</div>';

		echo '<p class="pcd-scanner-intro">' . esc_html__( 'Monitor other WordPress sites that run Conflict Detective 2.7+. On each remote site, open Agency → Remote Access, generate an API key, and add it here together with the site URL.', 'tahhan-conflict-detective' ) . '</p>';

		if ( empty( $sites ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No sites added yet.', 'tahhan-conflict-detective' ) . '</p>';
		} else {
			echo '<div class="tahcd-table-scroll"><table class="pcd-table tahcd-agency-table"><thead><tr>';
			foreach ( array(
				__( 'Site', 'tahhan-conflict-detective' ),
				__( 'Status', 'tahhan-conflict-detective' ),
				__( 'Conflicts', 'tahhan-conflict-detective' ),
				__( 'Fatal (24h)', 'tahhan-conflict-detective' ),
				__( 'Health issues', 'tahhan-conflict-detective' ),
				__( 'Last check', 'tahhan-conflict-detective' ),
				__( 'Actions', 'tahhan-conflict-detective' ),
			) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $sites as $id => $site ) {
				self::render_site_row( $id, $site, $statuses[ $id ] ?? null );
			}
			echo '</tbody></table></div>';
		}

		echo '<h3>' . esc_html__( 'Add a site', 'tahhan-conflict-detective' ) . '</h3>';
		echo '<form id="tahcd-agency-add" class="tahcd-form-row" autocomplete="off">';
		printf(
			'<label class="screen-reader-text" for="tahcd-add-label">%1$s</label><input type="text" id="tahcd-add-label" name="label" maxlength="80" placeholder="%1$s">',
			esc_attr__( 'Label (optional)', 'tahhan-conflict-detective' )
		);
		printf(
			'<label class="screen-reader-text" for="tahcd-add-url">%1$s</label><input type="url" id="tahcd-add-url" name="url" required placeholder="%2$s">',
			esc_html__( 'Site URL', 'tahhan-conflict-detective' ),
			esc_attr__( 'https://client-site.com', 'tahhan-conflict-detective' )
		);
		printf(
			'<label class="screen-reader-text" for="tahcd-add-key">%1$s</label><input type="password" id="tahcd-add-key" name="key" required autocomplete="new-password" placeholder="%2$s">',
			esc_html__( 'API key of the remote site', 'tahhan-conflict-detective' ),
			esc_attr__( 'API key (tahcd_…)', 'tahhan-conflict-detective' )
		);
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Add site', 'tahhan-conflict-detective' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * @return array{0: string, 1: string} CSS modifier and label.
	 */
	private static function status_badge( ?array $status ): array {
		if ( null === $status ) {
			return array( 'pcd-badge--info', __( 'Pending', 'tahhan-conflict-detective' ) );
		}
		if ( empty( $status['ok'] ) ) {
			return array( 'pcd-badge--error', __( 'Unreachable', 'tahhan-conflict-detective' ) );
		}
		$map = array(
			'ok'       => array( 'pcd-badge--ok', __( 'All clear', 'tahhan-conflict-detective' ) ),
			'warning'  => array( 'pcd-badge--warning', __( 'Warning', 'tahhan-conflict-detective' ) ),
			'critical' => array( 'pcd-badge--error', __( 'Critical', 'tahhan-conflict-detective' ) ),
		);
		return $map[ $status['data']['status'] ?? '' ] ?? array( 'pcd-badge--info', __( 'Unknown', 'tahhan-conflict-detective' ) );
	}

	/**
	 * @param array{label: string, url: string, key: string, added: int}                        $site
	 * @param array{checked_at: int, ok: bool, error: string, data: array<string, mixed>}|null $status
	 */
	private static function render_site_row( string $id, array $site, ?array $status ): void {
		$data  = ( $status && ! empty( $status['ok'] ) && is_array( $status['data'] ?? null ) ) ? $status['data'] : array();
		$badge = self::status_badge( $status );
		$get   = static fn( string $k ): string => isset( $data[ $k ] ) ? (string) $data[ $k ] : '';

		$conflicts = '—';
		if ( $data ) {
			$conflicts = $get( 'conflicts' );
			if ( '' !== $get( 'top_plugin' ) ) {
				$conflicts .= ' (' . $get( 'top_plugin' ) . ' ' . $get( 'top_confidence' ) . '%)';
			}
		}

		$checked = ( $status && ! empty( $status['checked_at'] ) )
			/* translators: %s: human-readable time difference */
			? sprintf( __( '%s ago', 'tahhan-conflict-detective' ), human_time_diff( (int) $status['checked_at'] ) )
			: '—';

		echo '<tr><td>';
		echo '<strong>' . esc_html( $site['label'] ) . '</strong><br>';
		echo '<a href="' . esc_url( $site['url'] ) . '" target="_blank" rel="noopener noreferrer" class="tahcd-site-url">' . esc_html( $site['url'] ) . '</a>';
		if ( '' !== $get( 'plugin_version' ) ) {
			echo '<br><span class="pcd-version">' . esc_html( 'CD v' . $get( 'plugin_version' ) . ' · WP ' . $get( 'wp' ) . ' · PHP ' . $get( 'php' ) ) . '</span>';
		}
		echo '</td><td>';
		echo '<span class="pcd-badge ' . esc_attr( $badge[0] ) . '">' . esc_html( $badge[1] ) . '</span>';
		if ( $status && empty( $status['ok'] ) && '' !== (string) ( $status['error'] ?? '' ) ) {
			echo '<br><span class="tahcd-site-error">' . esc_html( (string) $status['error'] ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . esc_html( $conflicts ) . '</td>';
		echo '<td>' . esc_html( $data ? $get( 'fatal_24h' ) : '—' ) . '</td>';
		echo '<td>' . esc_html( $data ? $get( 'health_issues' ) : '—' ) . '</td>';
		echo '<td class="pcd-time">' . esc_html( $checked ) . '</td>';
		echo '<td class="tahcd-actions">';
		echo '<button type="button" class="button button-small tahcd-agency-poll" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Check now', 'tahhan-conflict-detective' ) . '</button> ';
		echo '<button type="button" class="button button-small pcd-btn-danger tahcd-agency-remove" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Remove', 'tahhan-conflict-detective' ) . '</button>';
		echo '</td></tr>';
	}

	private static function render_key_card(): void {
		$info = Api_Keys::get_info();
		$fmt  = static fn( int $ts ): string => $ts > 0 ? date_i18n( 'd-m-Y H:i', $ts ) : '—';

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Remote Access (this site)', 'tahhan-conflict-detective' ) . '</h2>';
		echo '<p class="pcd-scanner-intro">' . esc_html__( 'Generate an API key to let an agency dashboard read this site\'s status. The key is read-only: it can view conflict, error and health counts, but cannot change anything.', 'tahhan-conflict-detective' ) . '</p>';

		echo '<table class="pcd-table">';
		echo '<tr><th>' . esc_html__( 'Endpoint', 'tahhan-conflict-detective' ) . '</th><td><code>' . esc_html( Rest_Api::endpoint_url() ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'tahhan-conflict-detective' ) . '</th><td>';
		if ( null === $info ) {
			echo '<span class="pcd-badge pcd-badge--info">' . esc_html__( 'Disabled — no key', 'tahhan-conflict-detective' ) . '</span>';
		} elseif ( $info['expired'] ) {
			echo '<span class="pcd-badge pcd-badge--error">' . esc_html__( 'Expired', 'tahhan-conflict-detective' ) . '</span>';
		} else {
			echo '<span class="pcd-badge pcd-badge--ok">' . esc_html__( 'Active', 'tahhan-conflict-detective' ) . '</span>';
		}
		echo '</td></tr>';
		if ( null !== $info ) {
			echo '<tr><th>' . esc_html__( 'Key', 'tahhan-conflict-detective' ) . '</th><td><code>' . esc_html( 'tahcd_…' . $info['hint'] ) . '</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Created', 'tahhan-conflict-detective' ) . '</th><td>' . esc_html( $fmt( $info['created'] ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Expires', 'tahhan-conflict-detective' ) . '</th><td>' . esc_html( $info['expires'] > 0 ? $fmt( $info['expires'] ) : __( 'Never', 'tahhan-conflict-detective' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last used', 'tahhan-conflict-detective' ) . '</th><td>' . esc_html( $fmt( $info['last_used'] ) ) . '</td></tr>';
		}
		echo '</table>';

		echo '<div class="tahcd-form-row">';
		echo '<label class="screen-reader-text" for="tahcd-key-expiry">' . esc_html__( 'Key lifetime', 'tahhan-conflict-detective' ) . '</label>';
		echo '<select id="tahcd-key-expiry">';
		$labels = array(
			30  => __( 'Expires in 30 days', 'tahhan-conflict-detective' ),
			90  => __( 'Expires in 90 days', 'tahhan-conflict-detective' ),
			365 => __( 'Expires in 1 year', 'tahhan-conflict-detective' ),
			0   => __( 'Never expires', 'tahhan-conflict-detective' ),
		);
		foreach ( $labels as $days => $label ) {
			printf( '<option value="%d"%s>%s</option>', absint( $days ), selected( 90, $days, false ), esc_html( $label ) );
		}
		echo '</select>';
		printf(
			'<button type="button" id="tahcd-key-generate" class="button button-primary" data-confirm="%s">%s</button>',
			esc_attr( null !== $info ? __( 'Generating a new key immediately invalidates the current one. Continue?', 'tahhan-conflict-detective' ) : '' ),
			esc_html( null !== $info ? __( 'Rotate key', 'tahhan-conflict-detective' ) : __( 'Generate key', 'tahhan-conflict-detective' ) )
		);
		if ( null !== $info ) {
			printf(
				'<button type="button" id="tahcd-key-revoke" class="button pcd-btn-danger" data-confirm="%s">%s</button>',
				esc_attr__( 'Revoke the key? The agency dashboard will lose access immediately.', 'tahhan-conflict-detective' ),
				esc_html__( 'Revoke', 'tahhan-conflict-detective' )
			);
		}
		echo '</div>';

		echo '<div id="tahcd-key-output" hidden>';
		echo '<p><strong>' . esc_html__( 'Copy this key now — it will not be shown again:', 'tahhan-conflict-detective' ) . '</strong></p>';
		echo '<div class="tahcd-form-row">';
		echo '<label class="screen-reader-text" for="tahcd-key-value">' . esc_html__( 'New API key', 'tahhan-conflict-detective' ) . '</label>';
		echo '<input type="text" id="tahcd-key-value" readonly class="tahcd-key-value">';
		printf( '<button type="button" id="tahcd-key-copy" class="button">%s</button>', esc_html__( 'Copy', 'tahhan-conflict-detective' ) );
		printf( '<button type="button" id="tahcd-key-done" class="button">%s</button>', esc_html__( 'I have copied it', 'tahhan-conflict-detective' ) );
		echo '</div></div>';

		echo '</div>';
	}

	private static function render_alerts_card(): void {
		$settings = Alerts::get_settings();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Email Alerts', 'tahhan-conflict-detective' ) . '</h2>';
		echo '<p class="pcd-scanner-intro">' . esc_html__( 'Checked hourly via WP-Cron. One digest email is sent only when something new appears — the same conflict or health issue is never emailed twice. Log excerpts are included as plain text.', 'tahhan-conflict-detective' ) . '</p>';

		echo '<form id="tahcd-alerts-form" class="tahcd-form-stack">';
		printf(
			'<label class="tahcd-check"><input type="checkbox" name="enabled" value="1"%s> %s</label>',
			checked( $settings['enabled'], true, false ),
			esc_html__( 'Enable email alerts', 'tahhan-conflict-detective' )
		);
		printf(
			'<label>%s<textarea name="recipients" rows="2" placeholder="%s">%s</textarea></label>',
			esc_html__( 'Recipients (comma separated, max 10)', 'tahhan-conflict-detective' ),
			esc_attr__( 'agency@example.com, client@example.com', 'tahhan-conflict-detective' ),
			esc_textarea( implode( ', ', $settings['recipients'] ) )
		);
		echo '<label>' . esc_html__( 'Send alerts for', 'tahhan-conflict-detective' ) . '<select name="severity">';
		$levels = array(
			'critical' => __( 'Critical only — new fatal errors', 'tahhan-conflict-detective' ),
			'high'     => __( 'High — fatal errors, conflicts, critical health issues', 'tahhan-conflict-detective' ),
			'all'      => __( 'Everything — including PHP warnings and all health issues', 'tahhan-conflict-detective' ),
		);
		foreach ( $levels as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $settings['severity'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		echo '<div class="tahcd-form-row">';
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Save alert settings', 'tahhan-conflict-detective' ) );
		printf( '<button type="button" id="tahcd-alerts-test" class="button">%s</button>', esc_html__( 'Send test email', 'tahhan-conflict-detective' ) );
		echo '</div>';
		echo '</form>';

		echo '</div>';
	}
}
