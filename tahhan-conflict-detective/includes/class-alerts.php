<?php
/**
 * Alerts for new fatal errors, conflicts, health issues and monitored sites.
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
 * reported and delivers new items by email and/or webhook — immediately, or
 * collected into a daily/weekly digest.
 *
 * Severity levels:
 *   critical — new fatal errors only
 *   high     — critical + conflicts >= 50 % confidence + critical health issues
 *   all      — high + new PHP warnings + every health issue
 *
 * Every reported conflict and health issue is remembered by signature, so the
 * same item is never sent twice. Turning alerts on (or raising the severity)
 * records the current state as "already known" so the backlog is not sent.
 *
 * Hub alerts (a monitored site turned critical/unreachable or recovered) come
 * from Agency and are always delivered immediately, bypassing the digest.
 *
 * Each recipient gets an individual email (addresses are never disclosed to
 * each other) with a personal unsubscribe link and RFC 8058 one-click headers.
 *
 * @since 2.7.0
 */
final class Alerts {

	const CRON_HOOK      = 'tahcd_alerts_check';
	const OPTION         = 'tahcd_alert_settings';
	const STATE_OPTION   = 'tahcd_alert_state';
	/** Separate from STATE_OPTION so a webhook sent during a poll can never overwrite the digest queue. */
	const WEBHOOK_STATUS_OPTION = 'tahcd_alert_webhook_status';
	const HUB_RATE_KEY   = 'tahcd_hub_alert_rate';
	/** Maximum hub-alert deliveries per hour; further events are summarised in the next one. */
	const HUB_MAX_PER_HOUR = 6;
	const SEVERITIES     = array( 'critical', 'high', 'all' );
	const FREQUENCIES    = array( 'immediate' => 0, 'daily' => DAY_IN_SECONDS, 'weekly' => WEEK_IN_SECONDS );
	const WEBHOOK_FORMATS = array( 'slack', 'discord', 'teams', 'json' );
	const MAX_RECIPIENTS = 10;
	const UNSUB_ACTION   = 'tahcd_unsubscribe';

	/** Section keys in display order. */
	private const SECTIONS = array( 'sites', 'fatal', 'conflicts', 'health', 'warnings' );

	private const MAX_ITEMS_PER_SECTION = 10;
	private const MAX_PENDING           = 50;
	private const MAX_REMEMBERED        = 300;
	private const TEST_THROTTLE_KEY     = 'tahcd_alert_test_sent';
	private const TEST_THROTTLE_SECONDS = 300;
	private const WEBHOOK_CONTEXT       = 'tahcd-webhook';

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );
		add_action( 'wp_ajax_tahcd_alerts_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_tahcd_alerts_test', array( __CLASS__, 'ajax_test' ) );
		// Unsubscribe links are opened by recipients who are not logged in.
		add_action( 'admin_post_nopriv_' . self::UNSUB_ACTION, array( __CLASS__, 'handle_unsubscribe' ) );
		add_action( 'admin_post_' . self::UNSUB_ACTION, array( __CLASS__, 'handle_unsubscribe' ) );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * @return array{enabled: bool, recipients: string[], severity: string, frequency: string, hub: bool, webhook: string, webhook_format: string, webhook_hint: string}
	 */
	public static function get_settings(): array {
		$raw = get_option( self::OPTION );
		$raw = is_array( $raw ) ? $raw : array();

		$severity  = isset( $raw['severity'] ) ? (string) $raw['severity'] : 'high';
		$frequency = isset( $raw['frequency'] ) ? (string) $raw['frequency'] : 'immediate';
		$format    = isset( $raw['webhook_format'] ) ? (string) $raw['webhook_format'] : 'slack';

		return array(
			'enabled'        => ! empty( $raw['enabled'] ),
			'recipients'     => isset( $raw['recipients'] ) && is_array( $raw['recipients'] )
				? array_values( array_filter( $raw['recipients'], 'is_email' ) )
				: array(),
			'severity'       => in_array( $severity, self::SEVERITIES, true ) ? $severity : 'high',
			'frequency'      => array_key_exists( $frequency, self::FREQUENCIES ) ? $frequency : 'immediate',
			'hub'            => ! isset( $raw['hub'] ) || ! empty( $raw['hub'] ),
			'webhook'        => isset( $raw['webhook'] ) ? (string) $raw['webhook'] : '',
			'webhook_format' => in_array( $format, self::WEBHOOK_FORMATS, true ) ? $format : 'slack',
			'webhook_hint'   => isset( $raw['webhook_hint'] ) ? (string) $raw['webhook_hint'] : '',
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function save_settings( array $settings ): void {
		// Autoloaded: small and read on every admin request by sync_schedule().
		update_option( self::OPTION, $settings, true );
	}

	/**
	 * @param array<string, mixed> $settings Output of get_settings().
	 */
	private static function has_channel( array $settings ): bool {
		return ! empty( $settings['recipients'] ) || '' !== $settings['webhook'];
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
		$wanted    = $settings['enabled'] && self::has_channel( $settings );

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
		if ( ! $settings['enabled'] || ! self::has_channel( $settings ) ) {
			return;
		}

		$state = self::get_state();
		$now   = time();
		$since = $state['last_check'] > 0 ? $state['last_check'] : $now - HOUR_IN_SECONDS;

		$sections            = self::collect( $settings['severity'], $since, $state, true );
		$state['last_check'] = $now;

		$period = self::FREQUENCIES[ $settings['frequency'] ];
		self::queue( $state, $sections );

		if ( 0 === $period || $now >= $state['next_digest'] ) {
			$pending = $state['pending'];
			$more    = $state['pending_more'];
			// Clear before sending so a crash mid-send cannot produce a duplicate digest.
			$state['pending']      = array();
			$state['pending_more'] = array();
			if ( $period > 0 ) {
				$state['next_digest'] = $now + $period;
			}
			update_option( self::STATE_OPTION, $state, false );

			if ( ! empty( $pending ) ) {
				self::deliver( $settings, $pending, $more, $period > 0 ? $settings['frequency'] : '' );
			}
			return;
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Adds newly found lines to the pending queue (capped per section).
	 *
	 * @param array<string, mixed>    $state
	 * @param array<string, string[]> $sections
	 */
	private static function queue( array &$state, array $sections ): void {
		foreach ( $sections as $key => $lines ) {
			$current = $state['pending'][ $key ] ?? array();
			$room    = max( 0, self::MAX_PENDING - count( $current ) );
			$state['pending'][ $key ]      = array_merge( $current, array_slice( $lines, 0, $room ) );
			$state['pending_more'][ $key ] = (int) ( $state['pending_more'][ $key ] ?? 0 ) + max( 0, count( $lines ) - $room );
		}
	}

	/**
	 * Records everything currently visible as already reported, without
	 * sending anything. Used when alerts are switched on or made broader.
	 */
	private static function prime_state( string $severity, string $frequency ): void {
		$state = self::get_state();
		self::collect( $severity, PHP_INT_MAX, $state, false );
		$state['last_check']   = time();
		// Items queued before alerts were switched off (or under a broader
		// severity) must not surface in the next digest.
		$state['pending']      = array();
		$state['pending_more'] = array();
		$state['next_digest']  = time() + self::FREQUENCIES[ $frequency ];
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function clear_queue(): void {
		$state                 = self::get_state();
		$state['pending']      = array();
		$state['pending_more'] = array();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * @param  string               $severity One of SEVERITIES.
	 * @param  int                  $since    Log entries strictly older than this are ignored.
	 * @param  array<string, mixed> $state    Updated in place with newly seen signatures.
	 * @param  bool                 $report   False = only record signatures (priming).
	 * @return array<string, string[]>        Section key => lines (empty sections omitted).
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
			'fatal'     => $fatal,
			'conflicts' => $conflicts,
			'health'    => $health,
			'warnings'  => $warnings,
		) );
	}

	/**
	 * @return array{last_check: int, conflicts: string[], health: string[], pending: array<string, string[]>, pending_more: array<string, int>, next_digest: int}
	 */
	private static function get_state(): array {
		$raw  = get_option( self::STATE_OPTION );
		$raw  = is_array( $raw ) ? $raw : array();
		$list = static function ( string $key ) use ( $raw ): array {
			return isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? array_values( array_map( 'strval', $raw[ $key ] ) ) : array();
		};

		$pending = array();
		$more    = array();
		foreach ( self::SECTIONS as $key ) {
			if ( ! empty( $raw['pending'][ $key ] ) && is_array( $raw['pending'][ $key ] ) ) {
				$pending[ $key ] = array_values( array_map( 'strval', $raw['pending'][ $key ] ) );
			}
			if ( ! empty( $raw['pending_more'][ $key ] ) ) {
				$more[ $key ] = (int) $raw['pending_more'][ $key ];
			}
		}

		return array(
			'last_check'   => isset( $raw['last_check'] ) ? (int) $raw['last_check'] : 0,
			'conflicts'    => $list( 'conflicts' ),
			'health'       => $list( 'health' ),
			'pending'      => $pending,
			'pending_more' => $more,
			'next_digest'  => isset( $raw['next_digest'] ) ? (int) $raw['next_digest'] : 0,
		);
	}

	// -------------------------------------------------------------------------
	// Hub alerts (called by Agency after polling)
	// -------------------------------------------------------------------------

	/**
	 * Delivers monitored-site events immediately (never batched in a digest:
	 * a site going down is urgent).
	 *
	 * @param array<int, array<string, mixed>> $events Events from Agency::record().
	 * @param int                              $polled Number of sites polled in this run.
	 */
	public static function notify_hub( array $events, int $polled ): void {
		if ( empty( $events ) ) {
			return;
		}
		$settings = self::get_settings();
		if ( ! $settings['enabled'] || ! $settings['hub'] || ! self::has_channel( $settings ) ) {
			return;
		}

		// Global cap: even many flapping or hostile sites cannot flood recipients.
		$now  = time();
		$stored  = get_transient( self::HUB_RATE_KEY );
		$stored  = is_array( $stored ) ? $stored : array();
		$carried = (int) ( $stored['dropped'] ?? 0 );
		// A new window starts with a fresh counter but keeps the suppressed count,
		// so the next message can still report what was skipped.
		$rate = (int) ( $stored['until'] ?? 0 ) > $now
			? array( 'n' => (int) ( $stored['n'] ?? 0 ), 'until' => (int) $stored['until'], 'dropped' => $carried )
			: array( 'n' => 0, 'until' => $now + HOUR_IN_SECONDS, 'dropped' => $carried );
		if ( $rate['n'] >= self::HUB_MAX_PER_HOUR ) {
			$rate['dropped'] += count( $events );
			set_transient( self::HUB_RATE_KEY, $rate, DAY_IN_SECONDS );
			return;
		}
		$dropped         = $rate['dropped'];
		$rate['n']++;
		$rate['dropped'] = 0;
		set_transient( self::HUB_RATE_KEY, $rate, DAY_IN_SECONDS );

		$lines       = array();
		$unreachable = 0;
		foreach ( $events as $event ) {
			// Label/URL are admin-entered; details may come from the remote site.
			$name           = self::clean( (string) $event['label'] ) . ' (' . self::clean( (string) $event['url'] ) . ')';
			$event['detail'] = self::clean( (string) $event['detail'], true );
			switch ( $event['type'] ) {
				case 'critical':
					/* translators: 1: site name and URL, 2: details */
					$lines[] = sprintf( __( 'CRITICAL — %1$s: %2$s', 'tahhan-conflict-detective' ), $name, self::clean( (string) $event['detail'] ) );
					break;
				case 'unreachable':
					$unreachable++;
					/* translators: 1: site name and URL, 2: error message */
					$lines[] = sprintf( __( 'UNREACHABLE — %1$s: %2$s', 'tahhan-conflict-detective' ), $name, self::clean( (string) $event['detail'] ) );
					break;
				case 'recovered':
					/* translators: 1: site name and URL, 2: new status (ok or warning) */
					$lines[] = sprintf( __( 'RECOVERED — %1$s is back to "%2$s".', 'tahhan-conflict-detective' ), $name, self::clean( (string) $event['detail'] ) );
					break;
				case 'key_expiring':
					/* translators: 1: site name and URL, 2: expiry date */
					$lines[] = sprintf( __( 'API KEY — the key for %1$s expires on %2$s. Generate a new one on that site and update it here.', 'tahhan-conflict-detective' ), $name, date_i18n( 'd-m-Y', (int) $event['detail'] ) );
					break;
			}
		}

		$notes = array();
		// Every site unreachable at once usually means the hub itself is offline.
		if ( $unreachable >= 3 && $unreachable === $polled ) {
			$notes[] = __( 'Note: every monitored site was unreachable in the same run. The problem may be the network connection of the monitoring site itself.', 'tahhan-conflict-detective' );
		}
		if ( $dropped > 0 ) {
			/* translators: %d: number of site alerts that were not sent */
			$notes[] = sprintf( _n( '%d earlier site alert was not sent because of the hourly limit — check the Agency tab.', '%d earlier site alerts were not sent because of the hourly limit — check the Agency tab.', $dropped, 'tahhan-conflict-detective' ), $dropped );
		}

		self::deliver( $settings, array( 'sites' => $lines ), array(), 'hub', implode( "\n", $notes ) );
	}

	// -------------------------------------------------------------------------
	// Delivery
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, string> Section key => translated label.
	 */
	private static function labels(): array {
		return array(
			'sites'     => __( 'Monitored sites', 'tahhan-conflict-detective' ),
			'fatal'     => __( 'New fatal errors', 'tahhan-conflict-detective' ),
			'conflicts' => __( 'New conflicts detected', 'tahhan-conflict-detective' ),
			'health'    => __( 'Health scan issues', 'tahhan-conflict-detective' ),
			'warnings'  => __( 'New PHP warnings', 'tahhan-conflict-detective' ),
			'test'      => __( 'Test', 'tahhan-conflict-detective' ),
		);
	}

	/**
	 * Sends to every configured channel.
	 *
	 * @param  array<string, mixed>    $settings
	 * @param  array<string, string[]> $sections
	 * @param  array<string, int>      $more     Items dropped per section (queue cap).
	 * @param  string                  $kind     '', 'daily', 'weekly' (digest) or 'hub'.
	 * @param  string                  $footer   Extra text after the sections (not counted as items).
	 * @return array{email: bool|null, webhook: bool|null} null = channel not configured.
	 */
	private static function deliver( array $settings, array $sections, array $more, string $kind, string $footer = '' ): array {
		$ordered = array();
		foreach ( array_merge( self::SECTIONS, array( 'test' ) ) as $key ) {
			if ( ! empty( $sections[ $key ] ) ) {
				$ordered[ $key ] = $sections[ $key ];
			}
		}
		if ( empty( $ordered ) ) {
			return array( 'email' => null, 'webhook' => null );
		}

		$site    = self::clean( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		$total   = 0;
		foreach ( $ordered as $key => $lines ) {
			$total += count( $lines ) + (int) ( $more[ $key ] ?? 0 );
		}

		if ( 'hub' === $kind ) {
			/* translators: 1: site name, 2: number of site alerts */
			$subject = sprintf( _n( '[%1$s] Conflict Detective: %2$d monitored-site alert', '[%1$s] Conflict Detective: %2$d monitored-site alerts', $total, 'tahhan-conflict-detective' ), $site, $total );
		} elseif ( 'daily' === $kind ) {
			/* translators: 1: site name, 2: number of new issues */
			$subject = sprintf( _n( '[%1$s] Conflict Detective daily digest: %2$d new item', '[%1$s] Conflict Detective daily digest: %2$d new items', $total, 'tahhan-conflict-detective' ), $site, $total );
		} elseif ( 'weekly' === $kind ) {
			/* translators: 1: site name, 2: number of new issues */
			$subject = sprintf( _n( '[%1$s] Conflict Detective weekly digest: %2$d new item', '[%1$s] Conflict Detective weekly digest: %2$d new items', $total, 'tahhan-conflict-detective' ), $site, $total );
		} else {
			/* translators: 1: site name, 2: number of new issues */
			$subject = sprintf( _n( '[%1$s] Conflict Detective: %2$d new issue', '[%1$s] Conflict Detective: %2$d new issues', $total, 'tahhan-conflict-detective' ), $site, $total );
		}

		$text = self::format_text( $ordered, $more );
		if ( '' !== $footer ) {
			$text .= "\n\n" . $footer;
		}

		return array(
			'email'   => empty( $settings['recipients'] ) ? null : self::send_emails( $settings['recipients'], $subject, $text ),
			'webhook' => '' === $settings['webhook'] ? null : self::send_webhook( $settings, $subject, $text, $ordered ),
		);
	}

	/**
	 * @param array<string, string[]> $sections
	 * @param array<string, int>      $more
	 */
	private static function format_text( array $sections, array $more ): string {
		$labels = self::labels();
		$out    = array();
		foreach ( $sections as $key => $lines ) {
			$hidden = count( $lines ) - self::MAX_ITEMS_PER_SECTION;
			$hidden = max( 0, $hidden ) + (int) ( $more[ $key ] ?? 0 );
			$out[]  = ( $labels[ $key ] ?? $key ) . ' (' . ( count( $lines ) + (int) ( $more[ $key ] ?? 0 ) ) . ')';
			$out[]  = str_repeat( '-', 40 );
			foreach ( array_slice( $lines, 0, self::MAX_ITEMS_PER_SECTION ) as $line ) {
				$out[] = '* ' . $line;
			}
			if ( $hidden > 0 ) {
				/* translators: %d: number of additional items not shown */
				$out[] = sprintf( __( '… and %d more', 'tahhan-conflict-detective' ), $hidden );
			}
			$out[] = '';
		}
		return rtrim( implode( "\n", $out ) );
	}

	/**
	 * One message per recipient: addresses are never disclosed to each other,
	 * and each gets a personal unsubscribe link.
	 *
	 * @param string[] $recipients
	 */
	private static function send_emails( array $recipients, string $subject, string $text ): bool {
		$all_sent = true;
		foreach ( $recipients as $recipient ) {
			$unsub = self::unsubscribe_url( $recipient );
			$body  = implode(
				"\n",
				array(
					sprintf(
						/* translators: %s: site URL */
						__( 'Conflict Detective found new problems on %s.', 'tahhan-conflict-detective' ),
						home_url( '/' )
					),
					__( 'Log excerpts below are quoted as plain text; links in them are deliberately broken and should not be trusted.', 'tahhan-conflict-detective' ),
					'',
					$text,
					'',
					__( 'Open the dashboard:', 'tahhan-conflict-detective' ) . ' ' . admin_url( 'admin.php?page=' . Dashboard::PAGE_SLUG ),
					'',
					__( 'You receive this email because you are listed as an alert recipient in Conflict Detective → Agency.', 'tahhan-conflict-detective' ),
					__( 'Unsubscribe:', 'tahhan-conflict-detective' ) . ' ' . $unsub,
				)
			);

			$sent = wp_mail(
				$recipient,
				$subject,
				$body,
				array(
					'Content-Type: text/plain; charset=UTF-8',
					'List-Unsubscribe: <' . $unsub . '>',
					'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
				)
			);
			$all_sent = $all_sent && $sent;
		}
		return $all_sent;
	}

	/**
	 * @param array<string, mixed>    $settings
	 * @param array<string, string[]> $sections
	 */
	private static function send_webhook( array $settings, string $subject, string $text, array $sections ): bool {
		$url = self::webhook_url( $settings );

		if ( null === $url ) {
			$ok    = false;
			$error = __( 'The stored webhook URL could not be decrypted (security salts changed?). Enter it again.', 'tahhan-conflict-detective' );
		} else {
			$response = Safe_Http::request(
				$url,
				array(
					'method'     => 'POST',
					'headers'    => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'       => (string) wp_json_encode( self::webhook_payload( $settings['webhook_format'], $subject, $text, $sections ) ),
					'user-agent' => 'TahhanConflictDetective/' . TAHCD_VERSION,
				)
			);
			$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$ok    = $code >= 200 && $code < 300;
			$error = '';
			if ( is_wp_error( $response ) ) {
				$error = mb_substr( sanitize_text_field( $response->get_error_message() ), 0, 200 );
			} elseif ( ! $ok ) {
				/* translators: %d: HTTP status code */
				$error = sprintf( __( 'The webhook answered with HTTP status %d.', 'tahhan-conflict-detective' ), $code );
			}
		}

		update_option( self::WEBHOOK_STATUS_OPTION, array( 'at' => time(), 'ok' => $ok, 'error' => $error ), false );
		return $ok;
	}

	/**
	 * @param  array<string, string[]> $sections
	 * @return array<string, mixed>
	 */
	public static function webhook_payload( string $format, string $subject, string $text, array $sections ): array {
		if ( in_array( $format, array( 'slack', 'teams' ), true ) ) {
			// Mattermost (and Slack legacy) turn plain "@channel"/"@all"/"@here"
			// into mentions; a zero-width space after "@" keeps them inert.
			$text = str_replace( '@', "@\u{200B}", $text );
		}
		$message = $subject . "\n\n" . $text;

		switch ( $format ) {
			case 'discord':
				// No mention parsing: log text must never ping @everyone.
				return array(
					'content'          => mb_substr( $message, 0, 1990 ),
					'allowed_mentions' => array( 'parse' => array() ),
				);
			case 'teams':
				return array(
					'type'        => 'message',
					'attachments' => array(
						array(
							'contentType' => 'application/vnd.microsoft.card.adaptive',
							'content'     => array(
								'type'    => 'AdaptiveCard',
								'version' => '1.4',
								'body'    => array(
									array( 'type' => 'TextBlock', 'text' => $subject, 'weight' => 'Bolder', 'wrap' => true ),
									array( 'type' => 'TextBlock', 'text' => mb_substr( $text, 0, 20000 ), 'wrap' => true ),
								),
							),
						),
					),
				);
			case 'json':
				return array(
					'event'    => 'tahcd_alert',
					'site'     => home_url( '/' ),
					'subject'  => $subject,
					'sections' => $sections,
					'text'     => $text,
				);
			default:
				// Slack/Mattermost: & < > must be escaped or "<!channel>" in a log line would ping everyone.
				return array( 'text' => str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), mb_substr( $message, 0, 35000 ) ) );
		}
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function webhook_url( array $settings ): ?string {
		return '' === $settings['webhook'] ? null : Secret_Box::decrypt( $settings['webhook'], self::WEBHOOK_CONTEXT );
	}

	/**
	 * Log lines are attacker-influenced (any request can write to debug.log),
	 * so strip control characters, defang links and cap length before they
	 * reach an email or chat message sent from this site.
	 */
	/**
	 * @param bool $strict Remote-site data: defang every "word.word" pattern, not only common TLDs.
	 */
	private static function clean( string $text, bool $strict = false ): string {
		$text = wp_check_invalid_utf8( wp_strip_all_tags( $text ), true );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = (string) preg_replace( '#\b(https?|ftp)://#i', '$1[:]//', $text );
		$text = (string) preg_replace( '/\bwww\./i', 'www[.]', $text );
		// Bare domains are auto-linked by mail clients too.
		$text = (string) preg_replace( '/\b([a-z0-9-]+)\.(com|net|org|info|biz|io|co|app|dev|xyz|top|site|online|shop|ru|cn|nl|de|uk|eu|me|ly|link|support|xyz|click)\b/i', '$1[.]$2', $text );
		if ( $strict ) {
			$text = (string) preg_replace( '/([\p{L}\d])\.(\p{L})/u', '$1[.]$2', $text );
		}
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
	// Unsubscribe
	// -------------------------------------------------------------------------

	/** 128-bit HMAC token per address; the URL never contains the address itself. */
	public static function unsubscribe_token( string $email ): string {
		return substr( hash_hmac( 'sha256', 'tahcd-unsub|' . strtolower( $email ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function unsubscribe_url( string $email ): string {
		return add_query_arg(
			array(
				'action' => self::UNSUB_ACTION,
				't'      => self::unsubscribe_token( $email ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * GET shows a confirmation page (link scanners in mail gateways follow GET
	 * links and must not unsubscribe anyone); POST — from that page or from a
	 * mail client's RFC 8058 one-click request — removes the address.
	 * The secret token is the authorisation; recipients are not logged in.
	 */
	public static function handle_unsubscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- authorised by the HMAC token; works for logged-out recipients and one-click mail clients.
		$token   = isset( $_REQUEST['t'] ) && is_string( $_REQUEST['t'] ) ? sanitize_key( wp_unslash( $_REQUEST['t'] ) ) : '';
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		// phpcs:enable

		$title    = __( 'Conflict Detective alerts', 'tahhan-conflict-detective' );
		$settings = self::get_settings();
		$match    = null;

		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			foreach ( $settings['recipients'] as $recipient ) {
				if ( hash_equals( self::unsubscribe_token( $recipient ), $token ) ) {
					$match = $recipient;
				}
			}
		}

		if ( null === $match ) {
			wp_die(
				esc_html__( 'This unsubscribe link is invalid, or the address has already been removed.', 'tahhan-conflict-detective' ),
				esc_html( $title ),
				array( 'response' => 404 )
			);
			return;
		}

		if ( ! $is_post ) {
			wp_die(
				'<p>' . esc_html( sprintf(
					/* translators: %s: partially hidden email address */
					__( 'Stop sending Conflict Detective alerts to %s?', 'tahhan-conflict-detective' ),
					self::mask_email( $match )
				) ) . '</p>'
				. '<form method="post" action="' . esc_url( add_query_arg( 'action', self::UNSUB_ACTION, admin_url( 'admin-post.php' ) ) ) . '">'
				. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '">'
				. '<button type="submit" class="button">' . esc_html__( 'Unsubscribe', 'tahhan-conflict-detective' ) . '</button>'
				. '</form>',
				esc_html( $title ),
				array( 'response' => 200 )
			);
			return;
		}

		$settings['recipients'] = array_values( array_filter(
			$settings['recipients'],
			static fn( $r ) => strtolower( $r ) !== strtolower( (string) $match )
		) );
		self::save_settings( $settings );
		self::sync_schedule();

		wp_die(
			esc_html__( 'You have been unsubscribed and will no longer receive these alerts.', 'tahhan-conflict-detective' ),
			esc_html( $title ),
			array( 'response' => 200 )
		);
	}

	private static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
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

	private static function post( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
		return isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	public static function ajax_save(): void {
		if ( ! self::guard() ) {
			return;
		}

		$previous = self::get_settings();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
		$raw_recipients = isset( $_POST['recipients'] ) && is_string( $_POST['recipients'] ) ? sanitize_textarea_field( wp_unslash( $_POST['recipients'] ) ) : '';

		$settings = array(
			'enabled'        => '1' === self::post( 'enabled' ),
			'recipients'     => self::parse_recipients( $raw_recipients ),
			'severity'       => in_array( self::post( 'severity' ), self::SEVERITIES, true ) ? self::post( 'severity' ) : 'high',
			'frequency'      => array_key_exists( self::post( 'frequency' ), self::FREQUENCIES ) ? self::post( 'frequency' ) : 'immediate',
			'hub'            => '1' === self::post( 'hub' ),
			'webhook'        => $previous['webhook'],
			'webhook_format' => in_array( self::post( 'webhook_format' ), self::WEBHOOK_FORMATS, true ) ? self::post( 'webhook_format' ) : 'slack',
			'webhook_hint'   => $previous['webhook_hint'],
		);

		// The webhook URL contains a secret token: it is never sent back to the
		// browser, so an empty field means "keep the current one".
		if ( '1' === self::post( 'webhook_remove' ) ) {
			$settings['webhook']      = '';
			$settings['webhook_hint'] = '';
			delete_option( self::WEBHOOK_STATUS_OPTION );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
			$new_url = isset( $_POST['webhook_url'] ) && is_string( $_POST['webhook_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['webhook_url'] ) ), array( 'https', 'http' ) ) : '';
			if ( '' !== $new_url ) {
				$checked = Safe_Http::check_url( $new_url );
				if ( is_wp_error( $checked ) ) {
					wp_send_json_error( array( 'message' => __( 'Webhook URL:', 'tahhan-conflict-detective' ) . ' ' . $checked->get_error_message() ) );
					return;
				}
				$encrypted = Secret_Box::encrypt( $checked['url'], self::WEBHOOK_CONTEXT );
				if ( null === $encrypted ) {
					wp_send_json_error( array( 'message' => __( 'Encryption (libsodium) is unavailable on this server, so the webhook URL cannot be stored safely.', 'tahhan-conflict-detective' ) ) );
					return;
				}
				$settings['webhook']      = $encrypted;
				// Well-known services: the host is public. Elsewhere the host itself may be the secret.
				$known = (bool) preg_match( '/(^|\.)(slack\.com|discord\.com|discordapp\.com|logic\.azure\.com|office\.com|powerautomate\.com)$/', $checked['host'] );
				$settings['webhook_hint'] = ( $known ? $checked['host'] : substr( $checked['host'], 0, 3 ) . '…' ) . '/…' . substr( $checked['url'], -4 );
				delete_option( self::WEBHOOK_STATUS_OPTION );
			}
		}

		if ( $settings['enabled'] && ! self::has_channel( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least one valid email address or a webhook URL.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		self::save_settings( $settings );

		// Switching on, or widening the severity, must not send the existing
		// backlog. A plain recipient edit keeps last_check so nothing is lost.
		$order = array_flip( self::SEVERITIES );
		if ( $settings['enabled'] && ( ! $previous['enabled'] || $order[ $settings['severity'] ] > $order[ $previous['severity'] ] ) ) {
			self::prime_state( $settings['severity'], $settings['frequency'] );
		} elseif ( ! $settings['enabled'] || $order[ $settings['severity'] ] < $order[ $previous['severity'] ] ) {
			// Disabled or narrowed: drop queued items that no longer qualify.
			self::clear_queue();
		}

		// A new digest period starts when the frequency changes.
		if ( $settings['frequency'] !== $previous['frequency'] ) {
			$state                = self::get_state();
			$state['next_digest'] = time() + self::FREQUENCIES[ $settings['frequency'] ];
			update_option( self::STATE_OPTION, $state, false );
		}

		self::sync_schedule();

		wp_send_json_success( array( 'message' => __( 'Alert settings saved.', 'tahhan-conflict-detective' ) ) );
	}

	public static function ajax_test(): void {
		if ( ! self::guard() ) {
			return;
		}

		$settings = self::get_settings();
		if ( ! self::has_channel( $settings ) ) {
			wp_send_json_error( array( 'message' => __( 'Save at least one recipient or a webhook URL first.', 'tahhan-conflict-detective' ) ) );
			return;
		}

		// Prevents the button from being used to relay messages in bulk.
		if ( false !== get_transient( self::TEST_THROTTLE_KEY ) ) {
			wp_send_json_error( array( 'message' => __( 'A test was sent recently. Please wait 5 minutes before sending another.', 'tahhan-conflict-detective' ) ) );
			return;
		}
		set_transient( self::TEST_THROTTLE_KEY, 1, self::TEST_THROTTLE_SECONDS );

		$result = self::deliver(
			$settings,
			array( 'test' => array( __( 'This is a test alert. Delivery works.', 'tahhan-conflict-detective' ) ) ),
			array(),
			''
		);

		$parts = array();
		if ( null !== $result['email'] ) {
			$parts[] = $result['email']
				? __( 'Test email sent.', 'tahhan-conflict-detective' )
				: __( 'wp_mail() reported a failure — check your site\'s mail configuration.', 'tahhan-conflict-detective' );
		}
		if ( null !== $result['webhook'] ) {
			$hook    = get_option( self::WEBHOOK_STATUS_OPTION );
			$parts[] = $result['webhook']
				? __( 'Webhook delivered.', 'tahhan-conflict-detective' )
				: __( 'Webhook failed:', 'tahhan-conflict-detective' ) . ' ' . (string) ( is_array( $hook ) ? ( $hook['error'] ?? '' ) : '' );
		}

		$message = implode( ' ', $parts );
		if ( false === $result['email'] || false === $result['webhook'] ) {
			wp_send_json_error( array( 'message' => $message ) );
			return;
		}
		wp_send_json_success( array( 'message' => $message ) );
	}

	// -------------------------------------------------------------------------
	// Render (Agency tab)
	// -------------------------------------------------------------------------

	public static function render_card(): void {
		$settings = self::get_settings();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Alerts', 'tahhan-conflict-detective' ) . '</h2>';
		echo '<p class="pcd-scanner-intro">' . esc_html__( 'Checked hourly via WP-Cron. Only new problems are sent — the same conflict or health issue is never sent twice. Alerts about monitored sites are always sent immediately. Log excerpts are included as plain text.', 'tahhan-conflict-detective' ) . '</p>';

		echo '<form id="tahcd-alerts-form" class="tahcd-form-stack" autocomplete="off">';
		printf(
			'<label class="tahcd-check"><input type="checkbox" name="enabled" value="1"%s> %s</label>',
			checked( $settings['enabled'], true, false ),
			esc_html__( 'Enable alerts', 'tahhan-conflict-detective' )
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

		echo '<label>' . esc_html__( 'Delivery', 'tahhan-conflict-detective' ) . '<select name="frequency">';
		$frequencies = array(
			'immediate' => __( 'Immediately (checked every hour)', 'tahhan-conflict-detective' ),
			'daily'     => __( 'Daily digest', 'tahhan-conflict-detective' ),
			'weekly'    => __( 'Weekly digest', 'tahhan-conflict-detective' ),
		);
		foreach ( $frequencies as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $settings['frequency'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label>';

		printf(
			'<label class="tahcd-check"><input type="checkbox" name="hub" value="1"%s> %s</label>',
			checked( $settings['hub'], true, false ),
			esc_html__( 'Alert when a monitored site becomes critical or unreachable (and when it recovers)', 'tahhan-conflict-detective' )
		);

		echo '<fieldset class="tahcd-fieldset"><legend>' . esc_html__( 'Email', 'tahhan-conflict-detective' ) . '</legend>';
		printf(
			'<label>%s<textarea name="recipients" rows="2" placeholder="%s">%s</textarea></label>',
			esc_html__( 'Recipients (comma separated, max 10) — each receives a separate email with an unsubscribe link', 'tahhan-conflict-detective' ),
			esc_attr__( 'agency@example.com, client@example.com', 'tahhan-conflict-detective' ),
			esc_textarea( implode( ', ', $settings['recipients'] ) )
		);
		echo '</fieldset>';

		echo '<fieldset class="tahcd-fieldset"><legend>' . esc_html__( 'Webhook (Slack, Discord, Teams)', 'tahhan-conflict-detective' ) . '</legend>';
		echo '<label>' . esc_html__( 'Format', 'tahhan-conflict-detective' ) . '<select name="webhook_format">';
		$formats = array(
			'slack'   => __( 'Slack / Mattermost', 'tahhan-conflict-detective' ),
			'discord' => __( 'Discord', 'tahhan-conflict-detective' ),
			'teams'   => __( 'Microsoft Teams (Workflows)', 'tahhan-conflict-detective' ),
			'json'    => __( 'Generic JSON', 'tahhan-conflict-detective' ),
		);
		foreach ( $formats as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $settings['webhook_format'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		printf(
			'<label>%s<input type="password" name="webhook_url" autocomplete="new-password" placeholder="%s"></label>',
			esc_html__( 'Webhook URL (HTTPS)', 'tahhan-conflict-detective' ),
			esc_attr( '' !== $settings['webhook_hint']
				/* translators: %s: masked webhook URL */
				? sprintf( __( 'Saved: %s — leave empty to keep', 'tahhan-conflict-detective' ), $settings['webhook_hint'] )
				: 'https://hooks.slack.com/services/…' )
		);
		if ( '' !== $settings['webhook'] ) {
			echo '<label class="tahcd-check"><input type="checkbox" name="webhook_remove" value="1"> ' . esc_html__( 'Remove the saved webhook', 'tahhan-conflict-detective' ) . '</label>';
			$hook_state = get_option( self::WEBHOOK_STATUS_OPTION );
			$hook_state = is_array( $hook_state ) ? $hook_state : array();
			if ( ! empty( $hook_state['at'] ) ) {
				echo '<p class="pcd-tip">' . esc_html__( 'Last delivery:', 'tahhan-conflict-detective' ) . ' ' . esc_html( date_i18n( 'd-m-Y H:i', (int) $hook_state['at'] ) ) . ' ';
				if ( ! empty( $hook_state['ok'] ) ) {
					echo '<span class="pcd-badge pcd-badge--ok">' . esc_html__( 'OK', 'tahhan-conflict-detective' ) . '</span>';
				} else {
					echo '<span class="pcd-badge pcd-badge--error">' . esc_html__( 'Failed', 'tahhan-conflict-detective' ) . '</span> ' . esc_html( (string) ( $hook_state['error'] ?? '' ) );
				}
				echo '</p>';
			}
		}
		echo '</fieldset>';

		echo '<div class="tahcd-form-row">';
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Save alert settings', 'tahhan-conflict-detective' ) );
		printf( '<button type="button" id="tahcd-alerts-test" class="button">%s</button>', esc_html__( 'Send test', 'tahhan-conflict-detective' ) );
		echo '</div>';
		echo '</form>';

		echo '</div>';
	}
}
