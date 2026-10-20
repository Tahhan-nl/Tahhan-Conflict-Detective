<?php
/**
 * Read-only REST endpoint used by an agency hub to monitor this site.
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
 * GET /wp-json/tahhan-cd/v1/status
 *
 * Authentication: the key is accepted ONLY in the X-Tahcd-Key header, never
 * as a query parameter (query strings end up in access logs and referrers).
 * The response contains counts and plugin names only — no file paths, log
 * messages, or other data that could help an attacker.
 *
 * @since 2.7.0
 */
final class Rest_Api {

	const NAMESPACE_V1 = 'tahhan-cd/v1';
	const ROUTE        = '/status';
	const HEADER       = 'X-Tahcd-Key';

	/** Failed attempts allowed per IP within FAIL_WINDOW before returning 429. */
	private const MAX_FAILS   = 10;
	private const FAIL_WINDOW = 900;

	private const CACHE_KEY = 'tahcd_status_cache';
	private const CACHE_TTL = 60;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_status' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error
	 */
	public static function check_permission( \WP_REST_Request $request ) {
		// A valid key is always accepted, even from a throttled IP: behind a CDN
		// or reverse proxy many clients share REMOTE_ADDR, and anyone could
		// otherwise lock the legitimate hub out with a few bad requests.
		$key = (string) $request->get_header( self::HEADER );
		if ( '' !== $key && Api_Keys::verify( $key ) ) {
			return true;
		}

		$rl_key = 'tahcd_rl_' . md5( self::client_bucket() );
		$state  = get_transient( $rl_key );
		$now    = time();
		$fails  = is_array( $state ) ? (int) ( $state['n'] ?? 0 ) : 0;
		$until  = is_array( $state ) ? (int) ( $state['until'] ?? 0 ) : 0;

		if ( $fails >= self::MAX_FAILS && $until > $now ) {
			return new \WP_Error(
				'tahcd_rate_limited',
				__( 'Too many failed attempts. Try again later.', 'tahhan-conflict-detective' ),
				array( 'status' => 429 )
			);
		}

		// Fixed window: the expiry is set by the first failure and not extended
		// by later ones, so a misconfigured hub recovers once its key is fixed.
		if ( $until <= $now ) {
			$fails = 0;
			$until = $now + self::FAIL_WINDOW;
		}
		set_transient( $rl_key, array( 'n' => $fails + 1, 'until' => $until ), max( 1, $until - $now ) );

		// Same response whether no key is configured or the key is wrong, so an
		// anonymous caller cannot tell whether remote monitoring is enabled.
		return new \WP_Error(
			'tahcd_invalid_key',
			__( 'Invalid or missing API key.', 'tahhan-conflict-detective' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * @param  \WP_REST_Request $request Incoming request (unused).
	 * @return \WP_REST_Response
	 */
	public static function get_status( \WP_REST_Request $request ): \WP_REST_Response {
		$response = new \WP_REST_Response( self::build_status(), 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Builds the status payload. Cached briefly so a burst of hub polls does
	 * not re-parse the error log every time.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_status(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wp_version;

		$suspects  = Conflict_Scanner::analyse( 10 );
		$conflicts = array_values( array_filter(
			$suspects,
			static fn( $s ) => (int) $s['confidence'] >= Conflict_Scanner::CONFLICT_THRESHOLD
		) );

		$errors    = Error_Log::get_entries( 500 );
		$cutoff    = time() - DAY_IN_SECONDS;
		$fatal_24h = 0;
		foreach ( $errors as $entry ) {
			if ( 'fatal' !== $entry['type'] || '' === $entry['time'] ) {
				continue;
			}
			$ts = strtotime( $entry['time'] );
			if ( false !== $ts && $ts >= $cutoff ) {
				$fatal_24h++;
			}
		}

		$last_scan   = Health_Scan::get_last_scan();
		$last_change = Change_History::get_recent( 1 );
		$last_change = $last_change[0] ?? null;

		$top_confidence  = $conflicts ? (int) $conflicts[0]['confidence'] : 0;
		$health_issues   = $last_scan ? (int) $last_scan['issues_found'] : 0;
		// Advisory items (pending updates etc.) must not flag every site as "warning".
		$health_critical = $last_scan ? Health_Scan::count_critical( $last_scan ) : 0;

		if ( $fatal_24h > 0 || $top_confidence >= Conflict_Scanner::CRITICAL_THRESHOLD ) {
			$status = 'critical';
		} elseif ( $conflicts || $health_critical > 0 ) {
			$status = 'warning';
		} else {
			$status = 'ok';
		}

		$data = array(
			'schema'         => 1,
			'status'         => $status,
			'plugin_version' => TAHCD_VERSION,
			'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
			'site'           => array(
				'name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			'environment'    => array(
				'wp'  => (string) $wp_version,
				'php' => PHP_VERSION,
			),
			'conflicts'      => array(
				'count' => count( $conflicts ),
				'top'   => $conflicts
					? array(
						'plugin'     => (string) $conflicts[0]['plugin_name'],
						'confidence' => $top_confidence,
					)
					: null,
			),
			'errors'         => array(
				'total'     => count( $errors ),
				'fatal_24h' => $fatal_24h,
			),
			'health'         => array(
				'issues'     => $health_issues,
				'critical'   => $health_critical,
				'scanned_at' => $last_scan ? (string) $last_scan['scanned_at'] : '',
			),
			'last_change'    => $last_change
				? array(
					'plugin' => (string) $last_change->plugin_name,
					'action' => (string) $last_change->action,
					'at'     => (string) $last_change->changed_at,
				)
				: null,
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	public static function endpoint_url(): string {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * REMOTE_ADDR only: X-Forwarded-For is client-controlled and would let an
	 * attacker rotate identities to bypass the rate limit. Sites behind a
	 * trusted reverse proxy can override via the filter.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'tahcd_rest_client_ip', $ip );
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Rate-limit bucket: the full IPv4 address, or the /64 prefix for IPv6
	 * (a single host typically controls a whole /64 and could rotate within it).
	 */
	private static function client_bucket(): string {
		$ip = self::client_ip();
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		// IPv4 clients reported as ::ffff:a.b.c.d keep their own bucket.
		if ( 0 === stripos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return substr( $ip, 7 );
		}
		$packed = inet_pton( $ip );
		return false === $packed ? $ip : bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
	}
}
