<?php
/**
 * Conflict Scanner — correlates plugin changes with error log entries
 * and produces a ranked list of suspects with a confidence percentage.
 *
 * @package PluginConflictDetector
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace PluginConflictDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How the confidence score is calculated
 * ----------------------------------------
 * For each plugin changed in the last 30 days we look at:
 *
 *  1. Error count   — how many errors in the log are attributed to that plugin
 *  2. Timeline fit  — did errors start within 60 minutes of the change? (+bonus)
 *  3. Recency       — more recent changes score higher
 *  4. Action weight — updates score higher than activations; deletions lower
 *
 * The raw score is normalised to 0–100 %.
 *
 * @since 2.0.0
 */
final class Conflict_Scanner {

	/** Look-back window for plugin changes. */
	private const CHANGE_WINDOW_DAYS = 30;

	/** If the first error fires within this many minutes of a change, bonus applies. */
	private const TIMELINE_WINDOW_MINUTES = 60;

	/** Action weights used in scoring. */
	private const ACTION_WEIGHTS = array(
		'updated'     => 1.0,
		'activated'   => 0.8,
		'deactivated' => 0.4,
		'deleted'     => 0.2,
	);

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Runs the full conflict analysis and returns a ranked list of suspects.
	 *
	 * @param  int $limit Maximum number of suspects to return.
	 * @return array<int, array{
	 *   plugin_name: string,
	 *   plugin_slug: string,
	 *   action: string,
	 *   changed_at: string,
	 *   error_count: int,
	 *   first_error_at: string,
	 *   confidence: int,
	 *   reason: string
	 * }>
	 */
	public static function analyse( int $limit = 5 ): array {
		$changes = self::get_recent_changes();
		$errors  = Error_Log::get_entries( 500 );

		if ( empty( $changes ) || empty( $errors ) ) {
			return array();
		}

		$suspects = array();

		foreach ( $changes as $change ) {
			$slug   = explode( '/', $change->plugin_slug )[0];
			$result = self::score_plugin( $slug, $change, $errors );

			if ( $result['confidence'] > 0 ) {
				$suspects[] = $result;
			}
		}

		// Sort by confidence descending.
		usort( $suspects, static function ( $a, $b ) {
			return $b['confidence'] - $a['confidence'];
		} );

		// De-duplicate by slug (keep highest score).
		$seen = array();
		$deduped = array();
		foreach ( $suspects as $s ) {
			if ( ! isset( $seen[ $s['plugin_slug'] ] ) ) {
				$seen[ $s['plugin_slug'] ] = true;
				$deduped[] = $s;
			}
		}

		return array_slice( $deduped, 0, $limit );
	}

	/**
	 * Returns the single most likely suspect, or null if none found.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function top_suspect(): ?array {
		$suspects = self::analyse( 1 );
		return $suspects[0] ?? null;
	}

	// -------------------------------------------------------------------------
	// Scoring
	// -------------------------------------------------------------------------

	/**
	 * Scores a single plugin change against the error log.
	 *
	 * @param  string   $slug   Plugin directory slug.
	 * @param  object   $change Row from cd_plugin_changes.
	 * @param  array    $errors All parsed error log entries.
	 * @return array<string, mixed>
	 */
	private static function score_plugin( string $slug, object $change, array $errors ): array {
		$change_ts     = strtotime( $change->changed_at );
		$action_weight = self::ACTION_WEIGHTS[ $change->action ] ?? 0.5;

		// Errors attributed to this plugin that occurred AFTER the change.
		$attributed    = array();
		$first_error_ts = PHP_INT_MAX;

		foreach ( $errors as $error ) {
			if ( $error['plugin_slug'] !== $slug ) {
				continue;
			}
			$error_ts = $error['time'] ? strtotime( $error['time'] ) : 0;
			if ( $error_ts > 0 && $error_ts >= $change_ts ) {
				$attributed[] = $error;
				if ( $error_ts < $first_error_ts ) {
					$first_error_ts = $error_ts;
				}
			}
		}

		$error_count = count( $attributed );

		if ( $error_count === 0 ) {
			return array(
				'plugin_name'    => $change->plugin_name,
				'plugin_slug'    => $slug,
				'action'         => $change->action,
				'changed_at'     => $change->changed_at,
				'error_count'    => 0,
				'first_error_at' => '',
				'confidence'     => 0,
				'reason'         => '',
			);
		}

		// Base score: number of errors (capped at 10 for scoring purposes).
		$base = min( $error_count, 10 ) / 10;

		// Timeline bonus: first error within TIMELINE_WINDOW_MINUTES → +40 % weight.
		$timeline_bonus = 0.0;
		if ( $first_error_ts !== PHP_INT_MAX ) {
			$minutes_diff = ( $first_error_ts - $change_ts ) / 60;
			if ( $minutes_diff >= 0 && $minutes_diff <= self::TIMELINE_WINDOW_MINUTES ) {
				// Closer in time → higher bonus (up to 0.4).
				$timeline_bonus = 0.4 * ( 1 - ( $minutes_diff / self::TIMELINE_WINDOW_MINUTES ) );
			}
		}

		// Recency bonus: change within 24 h → +0.2, fades to 0 at 30 days.
		$age_days     = ( time() - $change_ts ) / DAY_IN_SECONDS;
		$recency_bonus = max( 0.0, 0.2 * ( 1 - ( $age_days / self::CHANGE_WINDOW_DAYS ) ) );

		// Final score.
		$raw        = ( $base + $timeline_bonus + $recency_bonus ) * $action_weight;
		$confidence = (int) min( 99, round( $raw * 100 ) );

		// Build human-readable reason.
		$reason = self::build_reason( $change, $error_count, $first_error_ts, $timeline_bonus > 0 );

		return array(
			'plugin_name'    => $change->plugin_name,
			'plugin_slug'    => $slug,
			'action'         => $change->action,
			'changed_at'     => $change->changed_at,
			'error_count'    => $error_count,
			'first_error_at' => $first_error_ts !== PHP_INT_MAX ? gmdate( 'Y-m-d H:i:s', $first_error_ts ) : '',
			'confidence'     => $confidence,
			'reason'         => $reason,
		);
	}

	/**
	 * Builds a plain-language explanation of the confidence score.
	 */
	private static function build_reason(
		object $change,
		int $error_count,
		int $first_error_ts,
		bool $within_window
	): string {
		$parts = array();

		$parts[] = sprintf(
			/* translators: 1: action, 2: plugin name, 3: formatted date */
			__( '%1$s "%2$s" on %3$s.', 'conflict-detector' ),
			ucfirst( $change->action ),
			$change->plugin_name,
			date_i18n( 'd-m-Y H:i', strtotime( $change->changed_at ) )
		);

		$parts[] = sprintf(
			_n(
				'%d error attributed to this plugin after the change.',
				'%d errors attributed to this plugin after the change.',
				$error_count,
				'conflict-detector'
			),
			$error_count
		);

		if ( $within_window && $first_error_ts !== PHP_INT_MAX ) {
			$parts[] = sprintf(
				/* translators: formatted time */
				__( 'First error appeared at %s — within the timeline window.', 'conflict-detector' ),
				date_i18n( 'H:i', $first_error_ts )
			);
		}

		return implode( ' ', $parts );
	}

	// -------------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns plugin changes from the last CHANGE_WINDOW_DAYS days.
	 *
	 * @return array<int, object>
	 */
	private static function get_recent_changes(): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}cd_plugin_changes`
				 WHERE changed_at >= %s
				 ORDER BY changed_at DESC
				 LIMIT 100",
				gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::CHANGE_WINDOW_DAYS . ' days' ) )
			)
		);
	}
}
