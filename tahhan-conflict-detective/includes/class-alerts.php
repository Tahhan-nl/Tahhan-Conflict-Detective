<?php
/**
 * Email alerts for new fatal errors, conflicts, and health issues.
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
 * An hourly cron job compares the current state against what was already
 * reported and sends one digest email when something new appears.
 *
 * Severity levels:
 *   critical — new fatal errors only
 *   high     — critical + conflicts >= 50 % confidence + critical health issues
 *   all      — high + new PHP warnings + every health issue
 *
 * Every reported conflict and health issue is remembered by signature, so the
 * same item is never emailed twice. Turning alerts on (or raising the severity)
 * records the current state as "already known" so the backlog is not mailed.
 *
 * @since 2.7.0
 */
final class Alerts {

	const CRON_HOOK      = 'tahcd_alerts_check';
	const OPTION         = 'tahcd_alert_settings';
	const STATE_OPTION   = 'tahcd_alert_state';
	const SEVERITIES     = array( 'critical', 'high', 'all' );
	const MAX_RECIPIENTS = 10;

	private const MAX_ITEMS_PER_SECTION = 10;
	private const MAX_REMEMBERED        = 300;
	private const TEST_THROTTLE_KEY     = 'tahcd_alert_test_sent';
	private const TEST_THROTTLE_SECONDS = 300;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );
		add_action( 'wp_ajax_tahcd_alerts_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_tahcd_alerts_test', array( __CLASS__, 'ajax_test' ) );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * @return array{enabled: bool, recipients: string[], severity: string}
	 */
	public static function get_settings(): array {
		$raw = get_option( self::OPTION );
		$raw = is_array( $raw ) ? $raw : array();

		$severity = isset( $raw['severity'] ) ? (string) $raw['severity'] : 'high';

		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'recipients' => isset( $raw['recipients'] ) && is_array( $raw['recipients'] )
				? array_values( array_filter( $raw['recipients'], 'is_email' ) )
				: array(),
			'severity'   => in_array( $severity, self::SEVERITIES, true ) ? $severity : 'high',
		);
	}

	/**
	 * Splits a comma/newline separated list into at most MAX_RECIPIENTS valid,
	 * unique addresses (first spelling wins).
	 *
	 * @return string[]
	 */
	public static function parse_recipients( string $input ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $input ) ?: array() as $candidate ) {
			$email = sanitize_email( $candidate );
			if ( '' !== $email && is_email( $email ) && ! isset( $out[ strtolower( $email ) ] ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		return array_slice( array_values( $out ), 0, self::MAX_RECIPIENTS );
	}

	/**
	 * Ensures the cron event matches the settings. Called on admin_init (which
	 * also restores the event after a deactivate/reactivate cycle) and on save.
	 */
	public static function sync_schedule(): void {
		$settings  = self::get_settings();
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		$wanted    = $settings['enabled'] && ! empty( $settings['recipients'] );

		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		} elseif ( ! $wanted && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	public static function run_check(): void {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] || empty( $settings['recipients'] ) ) {
			return;
		}

		$state = self::get_state();
		$now   = time();
		$since = $state['last_check'] > 0 ? $state['last_check'] : $now - HOUR_IN_SECONDS;

		$sections = self::collect( $settings['severity'], $since, $state, true );

		$state['last_check'] = $now;
		update_option( self::STATE_OPTION, $state, false );

		if ( array_sum( array_map( 'count', $sections ) ) > 0 ) {
			self::send( $settings['recipients'], $sections );
		}
	}

	/**
	 * Records everything currently visible as already reported, without
	 * sending anything. Used when alerts are switched on or made broader.
	 */
	private static function prime_state( string $severity ): void {
		$state = self::get_state();
		self::collect( $severity, PHP_INT_MAX, $state, false );
		$state['last_check'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * @param  string               $severity One of SEVERITIES.
	 * @param  int                  $since    Log entries strictly older than this are ignored.
	 * @param  array<string, mixed> $state    Updated in place with newly seen signatures.
	 * @param  bool                 $report   False = only record signatures (priming).
	 * @return array<string, string[]>        Section label => lines.
	 */
	private static function collect( string $severity, int $since, array &$state, bool $report ): array {
		$fatal     = array();
		$warnings  = array();
		$conflicts = array();
		$health    = array();

		if ( $report ) {
			foreach ( Error_Log::get_entries( 500 ) as $entry ) {
				if ( '' === $entry['time'] ) {
					continue;
				}
				$ts = strtotime( $entry['time'] );
				// Strict "<": an entry logged in the same second as the previous
				// check is reported (rare duplicate) rather than silently lost.
				if ( false === $ts || $ts < $since ) {
					continue;
				}
				if ( 'fatal' === $entry['type'] ) {
					$fatal[] = self::format_error( $entry );
				} elseif ( 'all' === $severity && 'warning' === $entry['type'] ) {
					$warnings[] = self::format_error( $entry );
				}
			}
		}

		if ( 'critical' !== $severity ) {
			foreach ( Conflict_Scanner::analyse( 10 ) as $suspect ) {
				if ( (int) $suspect['confidence'] < Conflict_Scanner::CONFLICT_THRESHOLD ) {
					continue;
				}
				$sig = md5( $suspect['plugin_slug'] . '|' . $suspect['changed_at'] );
				if ( in_array( $sig, $state['conflicts'], true ) ) {
					continue;
				}
				$state['conflicts'][] = $sig;
				$conflicts[]          = sprintf(
					/* translators: 1: plugin name, 2: confidence percentage */
					__( '%1$s — %2$d%% confidence', 'tahhan-conflict-detective' ),
					self::clean( (string) $suspect['plugin_name'] ),
					(int) $suspect['confidence']
				);
			}

			$scan = Health_Scan::get_last_scan();
			if ( $scan ) {
				foreach ( array( 'plugins', 'theme', 'server' ) as $section ) {
					foreach ( $scan[ $section ]['issues'] ?? array() as $issue ) {
						$type    = (string) ( $issue['type'] ?? '' );
						$message = (string) ( $issue['message'] ?? '' );
						if ( 'all' !== $severity && ! in_array( $type, Health_Scan::CRITICAL_TYPES, true ) ) {
							continue;
						}
						$sig = md5( $type . '|' . $message );
						if ( in_array( $sig, $state['health'], true ) ) {
							continue;
						}
						$state['health'][] = $sig;
						$health[]          = self::clean( $message );
					}
				}
			}
		}

		$state['conflicts'] = array_slice( $state['conflicts'], -self::MAX_REMEMBERED );
		$state['health']    = array_slice( $state['health'], -self::MAX_REMEMBERED );

		return array_filter( array(
			__( 'New fatal errors', 'tahhan-conflict-detective' )       => $fatal,
			__( 'New conflicts detected', 'tahhan-conflict-detective' ) => $conflicts,
			__( 'Health scan issues', 'tahhan-conflict-detective' )     => $health,
			__( 'New PHP warnings', 'tahhan-conflict-detective' )       => $warnings,
		) );
	}

	/**
	 * @return array{last_check: int, conflicts: string[], health: string[]}
	 */
	private static function get_state(): array {
		$raw  = get_option( self::STATE_OPTION );
		$raw  = is_array( $raw ) ? $raw : array();
		$list = static function ( string $key ) use ( $raw ): array {
			return isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? array_values( array_map( 'strval', $raw[ $key ] ) ) : array();
		};
		return array(
			'last_check' => isset( $raw['last_check'] ) ? (int) $raw['last_check'] : 0,
			'conflicts'  => $list( 'conflicts' ),
			'health'     => $list( 'health' ),
		);
	}

	// -------------------------------------------------------------------------
	// Email
	// -------------------------------------------------------------------------

	/**
	 * @param string[]                $recipients Validated addresses.
	 * @param array<string, string[]> $sections   Section label => lines.
	 */
	private static function send( array $recipients, array $sections ): bool {
		$site  = self::clean( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$total = array_sum( array_map( 'count', $sections ) );

		$subject = sprintf(
			/* translators: 1: site name, 2: number of new issues */
			_n( '[%1$s] Conflict Detective: %2$d new issue', '[%1$s] Conflict Detective: %2$d new issues', $total, 'tahhan-conflict-detective' ),
			$site,
			$total
		);

		$body = array(
			sprintf(
				/* translators: %s: site URL */
				__( 'Conflict Detective found new problems on %s.', 'tahhan-conflict-detective' ),
				home_url( '/' )
			),
			__( 'Log excerpts below are quoted as plain text; links in them are deliberately broken and should not be trusted.', 'tahhan-conflict-detective' ),
			'',
		);

		foreach ( $sections as $label => $lines ) {
			$body[] = $label . ' (' . count( $lines ) . ')';
			$body[] = str_repeat( '-', 40 );
			foreach ( array_slice( $lines, 0, self::MAX_ITEMS_PER_SECTION ) as $line ) {
				$body[] = '* ' . $line;
			}
			if ( count( $lines ) > self::MAX_ITEMS_PER_SECTION ) {
				$body[] = sprintf(
					/* translators: %d: number of additional items not shown */
					__( '… and %d more', 'tahhan-conflict-detective' ),
					count( $lines ) - self::MAX_ITEMS_PER_SECTION
				);
			}
			$body[] = '';
		}

		$body[] = __( 'Open the dashboard:', 'tahhan-conflict-detective' ) . ' ' . admin_url( 'admin.php?page=' . Dashboard::PAGE_SLUG );
		$body[] = '';
		$body[] = __( 'You receive this email because you are listed as an alert recipient in Conflict Detective → Agency. An administrator can remove you there.', 'tahhan-conflict-detective' );

		return wp_mail(
			$recipients,
			$subject,
			implode( "\n", $body ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	/**
	 * Log lines are attacker-influenced (any request can write to debug.log),
	 * so strip control characters, defang links and cap length before they
	 * reach an email sent from this site's domain.
	 */
	private static function clean( string $text ): string {
		$text = wp_check_invalid_utf8( wp_strip_all_tags( $text ), true );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = (string) preg_replace( '#\b(https?|ftp)://#i', '$1[:]//', $text );
		$text = (string) preg_replace( '/\bwww\./i', 'www[.]', $text );
		$text = trim( $text );
		return mb_strlen( $text ) > 200 ? mb_substr( $text, 0, 200 ) . '…' : $text;
	}

	/**
	 * @param array<string, string> $entry Parsed error log entry.
	 */
	private static function format_error( array $entry ): string {
		$prefix = '' !== $entry['plugin_name'] ? '[' . self::clean( $entry['plugin_name'] ) . '] ' : '';
		return $entry['time'] . ' ' . $prefix . self::clean( $entry['message'] );
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public static function ajax_save(): void {
		check_ajax_referer( 'tahcd_nonce', 'nonce' );

		if ( ! current_user_can( Dashboard::sensitive_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'tahhan-conflict-detective' ) ), 403 );
			return;
		}

		$enabled    = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		$recipients = self::parse_recipients( isset( $_POST['recipients'] ) ? sanitize_textarea_field( wp_unslash( $_POST['recipients'] ) ) : '' );
		$severity   = isset( $_POST['severity'] ) ? sanitize_key( wp_unslash( $_POST['severity'] ) ) : 'high';

		if ( ! in_array( $severity, self::SEVERITIES, true ) ) {
			$severity = 'high';
		}

		if ( $enabled && empty( $recipients ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one valid email address.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		$previous = self::get_settings();

		update_option(
			self::OPTION,
			array(
				'enabled'    => $enabled,
				'recipients' => $recipients,
				'severity'   => $severity,
			),
			true // Small and read on every admin request by sync_schedule().
		);

		// Switching on, or widening the severity, must not email the existing
		// backlog. A plain recipient edit keeps last_check so nothing is lost.
		$order = array_flip( self::SEVERITIES );
		if ( $enabled && ( ! $previous['enabled'] || $order[ $severity ] > $order[ $previous['severity'] ] ) ) {
			self::prime_state( $severity );
		}

		self::sync_schedule();

		wp_send_json_success( array( 'message' => __( 'Alert settings saved.', 'tahhan-conflict-detective' ) ) );
	}

	public static function ajax_test(): void {
		check_ajax_referer( 'tahcd_nonce', 'nonce' );

		if ( ! current_user_can( Dashboard::sensitive_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'tahhan-conflict-detective' ) ), 403 );
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['recipients'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Save at least one recipient first.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		// Prevents the button from being used to relay mail in bulk.
		if ( false !== get_transient( self::TEST_THROTTLE_KEY ) ) {
			wp_send_json_error( array( 'message' => __( 'A test email was sent recently. Please wait 5 minutes before sending another.', 'tahhan-conflict-detective' ) ) );
			return;
		}
		set_transient( self::TEST_THROTTLE_KEY, 1, self::TEST_THROTTLE_SECONDS );

		$sent = self::send(
			$settings['recipients'],
			array( __( 'Test', 'tahhan-conflict-detective' ) => array( __( 'This is a test alert. Email delivery works.', 'tahhan-conflict-detective' ) ) )
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'wp_mail() reported a failure. Check your site\'s mail configuration.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		wp_send_json_success( array( 'message' => __( 'Test email sent.', 'tahhan-conflict-detective' ) ) );
	}
}
