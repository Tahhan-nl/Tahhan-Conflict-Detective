<?php
/**
 * Cron Monitor — inspect and manually trigger WordPress Cron events.
 *
 * Reads the native WP-Cron array to list every scheduled event, its schedule,
 * next run time, and whether it is overdue. Provides an AJAX handler to
 * manually fire any event from the admin UI.
 *
 * No custom database tables are used — this class works entirely through
 * the built-in WP-Cron API.
 *
 * @package TahhanConflictDetective
 * @since   2.6.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monitors and manually triggers WordPress Cron events.
 *
 * @since 2.6.0
 */
final class Cron_Monitor {

	/**
	 * Number of seconds past the scheduled time before an event is considered
	 * overdue (5 minutes).
	 */
	const OVERDUE_GRACE = 300;

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/**
	 * Returns a flat list of all scheduled cron events.
	 *
	 * Each element is an associative array:
	 *   - hook        string  The action hook name.
	 *   - schedule    string  Human-readable schedule name (or 'once').
	 *   - interval    int     Schedule interval in seconds (0 for one-off).
	 *   - next_run    int     Unix timestamp of next scheduled run.
	 *   - overdue     bool    True when next_run < now - OVERDUE_GRACE.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_events(): array {
		$cron_array = _get_cron_array();

		if ( ! is_array( $cron_array ) || empty( $cron_array ) ) {
			return array();
		}

		$schedules = wp_get_schedules();
		$now       = time();
		$events    = array();

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $hook_events ) {
				if ( ! is_array( $hook_events ) ) {
					continue;
				}
				foreach ( $hook_events as $event ) {
					$schedule_key = isset( $event['schedule'] ) ? (string) $event['schedule'] : '';
					$interval     = isset( $event['interval'] ) ? (int) $event['interval'] : 0;

					// Resolve a readable label.
					if ( $schedule_key && isset( $schedules[ $schedule_key ] ) ) {
						$schedule_label = $schedules[ $schedule_key ]['display'];
					} elseif ( $schedule_key ) {
						$schedule_label = $schedule_key;
					} else {
						$schedule_label = __( 'Once', 'tahhan-conflict-detective' );
					}

					$ts      = (int) $timestamp;
					$overdue = $ts < ( $now - self::OVERDUE_GRACE );

					$events[] = array(
						'hook'           => $hook,
						'schedule'       => $schedule_label,
						'interval'       => $interval,
						'next_run'       => $ts,
						'overdue'        => $overdue,
					);
				}
			}
		}

		// Sort ascending by next_run (soonest first, overdue on top).
		usort( $events, static function ( $a, $b ) {
			return $a['next_run'] <=> $b['next_run'];
		} );

		return $events;
	}

	/**
	 * Manually fires a cron hook and returns the elapsed time in milliseconds.
	 *
	 * @param string $hook The action hook to fire.
	 * @return float Elapsed time in milliseconds.
	 */
	public static function run_event( string $hook ): float {
		$start = microtime( true );
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- dynamic hook name from cron array
		return round( ( microtime( true ) - $start ) * 1000, 2 );
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Registers the AJAX handler for manually triggering a cron event.
	 *
	 * @return void
	 */
	public static function register_ajax(): void {
		add_action( 'wp_ajax_tahcd_run_cron_event', array( __CLASS__, 'ajax_run_cron_event' ) );
	}

	/**
	 * AJAX handler: fires the requested cron hook and returns elapsed time.
	 *
	 * @return void
	 */
	public static function ajax_run_cron_event(): void {
		check_ajax_referer( 'tahcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';

		if ( '' === $hook ) {
			wp_send_json_error( array( 'message' => __( 'No hook specified.', 'tahhan-conflict-detective' ) ) );
		}

		$elapsed = self::run_event( $hook );

		wp_send_json_success( array(
			'hook'    => $hook,
			'elapsed' => $elapsed,
			/* translators: 1: hook name, 2: elapsed time in ms */
			'message' => sprintf(
				__( 'Hook "%1$s" fired in %2$s ms.', 'tahhan-conflict-detective' ),
				$hook,
				number_format( $elapsed, 2 )
			),
		) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the Cron Monitor tab.
	 *
	 * @return void
	 */
	public static function render(): void {
		$events = self::get_events();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Cron Monitor', 'tahhan-conflict-detective' ) . '</h2>';
		echo '</div>';

		echo '<p class="pcd-scanner-intro">'
			. esc_html__( 'Lists all scheduled WordPress cron events. Overdue events (next run more than 5 minutes in the past) are highlighted in red. You can manually trigger any event using the "Run Now" button.', 'tahhan-conflict-detective' )
			. '</p>';

		if ( empty( $events ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No scheduled cron events found.', 'tahhan-conflict-detective' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div id="tahcd-cron-run-result"></div>';

		echo '<table class="tahcd-cron-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Hook',      'tahhan-conflict-detective' ),
			__( 'Schedule',  'tahhan-conflict-detective' ),
			__( 'Next Run',  'tahhan-conflict-detective' ),
			__( 'Status',    'tahhan-conflict-detective' ),
			__( 'Actions',   'tahhan-conflict-detective' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			$hook     = (string) $event['hook'];
			$overdue  = (bool)   $event['overdue'];
			$next_run = date_i18n( 'd-m-Y H:i:s', (int) $event['next_run'] );

			if ( $overdue ) {
				$status_html = '<span class="tahcd-cron-overdue">'
					. esc_html__( 'OVERDUE', 'tahhan-conflict-detective' )
					. '</span>';
			} else {
				$status_html = '<span class="pcd-badge pcd-badge--ok">'
					. esc_html__( 'On Schedule', 'tahhan-conflict-detective' )
					. '</span>';
			}

			printf(
				'<tr class="%s">
					<td class="tahcd-cron-hook">%s</td>
					<td>%s</td>
					<td class="pcd-time">%s</td>
					<td>%s</td>
					<td>
						<button class="button button-small tahcd-run-cron-btn" type="button" data-hook="%s">%s</button>
					</td>
				</tr>',
				$overdue ? 'tahcd-cron-row--overdue' : '',
				esc_html( $hook ),
				esc_html( (string) $event['schedule'] ),
				esc_html( $next_run ),
				$status_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
				esc_attr( $hook ),
				esc_html__( 'Run Now', 'tahhan-conflict-detective' )
			);
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
