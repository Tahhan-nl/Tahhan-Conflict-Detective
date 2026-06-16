<?php
/**
 * AJAX / REST Error Monitor — logs slow AJAX and REST API calls.
 *
 * - Hooks `all` (generic WordPress action) to intercept `wp_ajax_*` calls
 *   and measures elapsed time; logs any call exceeding 500 ms.
 * - Hooks `rest_post_dispatch` to log slow REST responses (> 500 ms).
 * - Persists entries to `{prefix}cd_ajax_log` (created in Phase 3 schema).
 * - Trims the table to the last 500 rows on every insert.
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
 * Monitors and logs slow AJAX and REST API requests.
 *
 * @since 2.6.0
 */
final class Ajax_Monitor {

	/** DB table (without prefix). */
	const TABLE_SUFFIX = 'cd_ajax_log';

	/** Slow-call threshold in milliseconds. */
	const SLOW_THRESHOLD_MS = 500;

	/** Maximum number of rows to retain in the log table. */
	const MAX_ROWS = 500;

	/**
	 * Microtime stamp captured when the AJAX action hook fires.
	 *
	 * @var float|null
	 */
	private static $ajax_start = null;

	/**
	 * The action name being timed for AJAX calls.
	 *
	 * @var string
	 */
	private static $ajax_action = '';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Registers all monitoring hooks.
	 * Must be called early (e.g. from plugins_loaded).
	 *
	 * @return void
	 */
	public static function init(): void {
		// Use the `all` action to intercept every hook — we look for wp_ajax_*.
		add_action( 'all', array( __CLASS__, 'on_all' ) );

		// REST API: measure after dispatch.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_rest_post_dispatch' ), PHP_INT_MAX, 3 );
	}

	// -------------------------------------------------------------------------
	// AJAX monitoring
	// -------------------------------------------------------------------------

	/**
	 * Generic hook listener. Records the start time when a `wp_ajax_` action fires.
	 *
	 * WordPress calls AJAX handlers via `do_action( "wp_ajax_{$action}" )`.
	 * We capture the hook name and start time here; a `shutdown` callback
	 * (registered on the same tick) measures the elapsed time.
	 *
	 * @return void
	 */
	public static function on_all(): void {
		$current = current_action();

		if ( ! is_string( $current ) ) {
			return;
		}

		// Match both authenticated (wp_ajax_) and non-authenticated (wp_ajax_nopriv_) calls.
		if ( 0 !== strpos( $current, 'wp_ajax_' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only monitoring
			? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) )
			: substr( $current, strlen( 'wp_ajax_' ) );

		self::$ajax_start  = microtime( true );
		self::$ajax_action = $action;

		// Register a shutdown function to measure elapsed time AFTER the handler runs.
		register_shutdown_function( array( __CLASS__, 'on_ajax_shutdown' ) );
	}

	/**
	 * Shutdown callback — measures elapsed time for the active AJAX call and
	 * logs it if it exceeds the threshold.
	 *
	 * @return void
	 */
	public static function on_ajax_shutdown(): void {
		if ( null === self::$ajax_start ) {
			return;
		}

		$elapsed_ms = (int) round( ( microtime( true ) - self::$ajax_start ) * 1000 );

		if ( $elapsed_ms < self::SLOW_THRESHOLD_MS ) {
			return;
		}

		self::insert_log( array(
			'type'        => 'ajax',
			'action'      => self::$ajax_action,
			'duration_ms' => $elapsed_ms,
			'status_code' => 200,
		) );
	}

	// -------------------------------------------------------------------------
	// REST monitoring
	// -------------------------------------------------------------------------

	/**
	 * Fires after a REST API request is dispatched.
	 * Logs the response if it took longer than SLOW_THRESHOLD_MS.
	 *
	 * @param  \WP_HTTP_Response         $response The response object.
	 * @param  \WP_REST_Server           $server   The REST server instance.
	 * @param  \WP_REST_Request          $request  The REST request.
	 * @return \WP_HTTP_Response                    Unmodified response.
	 */
	public static function on_rest_post_dispatch( $response, $server, $request ) {
		// WP_REST_Server::$start is available from WP 4.4+.
		// Fall back to REQUEST_TIME_FLOAT if the server property is unavailable.
		$start = null;

		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$start = (float) $_SERVER['REQUEST_TIME_FLOAT']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- float cast is safe
		} elseif ( defined( 'WP_START_TIMESTAMP' ) ) {
			$start = (float) WP_START_TIMESTAMP;
		}

		if ( null === $start ) {
			return $response;
		}

		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( $elapsed_ms < self::SLOW_THRESHOLD_MS ) {
			return $response;
		}

		$route       = is_a( $request, 'WP_REST_Request' ) ? $request->get_route() : '';
		$status_code = is_a( $response, 'WP_HTTP_Response' ) ? $response->get_status() : 200;

		self::insert_log( array(
			'type'        => 'rest',
			'action'      => $route,
			'duration_ms' => $elapsed_ms,
			'status_code' => $status_code,
		) );

		return $response;
	}

	// -------------------------------------------------------------------------
	// DB helpers
	// -------------------------------------------------------------------------

	/**
	 * Inserts a log entry and trims the table to MAX_ROWS.
	 *
	 * @param  array<string, mixed> $data  Keys: type, action, duration_ms, status_code.
	 * @return void
	 */
	private static function insert_log( array $data ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional log write; no caching layer needed
		$wpdb->insert(
			$table,
			array(
				'type'        => substr( (string) ( $data['type'] ?? 'ajax' ), 0, 10 ),
				'action'      => substr( (string) ( $data['action'] ?? '' ), 0, 200 ),
				'duration_ms' => (int) ( $data['duration_ms'] ?? 0 ),
				'status_code' => (int) ( $data['status_code'] ?? 200 ),
				'user_id'     => (int) get_current_user_id(),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		self::trim_table();
	}

	/**
	 * Keeps only the most recent MAX_ROWS rows in the log table.
	 *
	 * @return void
	 */
	private static function trim_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance trim, no cache needed
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE id NOT IN (
					SELECT id FROM (
						SELECT id FROM `{$table}` ORDER BY id DESC LIMIT %d
					) AS t
				)",
				self::MAX_ROWS
			)
		);
	}

	/**
	 * Returns the last N log entries with an optional type filter.
	 *
	 * @param  int    $limit  Number of rows to return (default 50).
	 * @param  string $filter 'all' | 'ajax' | 'rest' | 'slow'
	 * @return array<int, object>  Array of stdClass rows.
	 */
	public static function get_entries( int $limit = 50, string $filter = 'all' ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// Make sure the table exists before querying.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		if ( null === $table_exists ) {
			return array();
		}

		$where = '';
		if ( 'ajax' === $filter ) {
			$where = "WHERE type = 'ajax'";
		} elseif ( 'rest' === $filter ) {
			$where = "WHERE type = 'rest'";
		} elseif ( 'slow' === $filter ) {
			$where = $wpdb->prepare( 'WHERE duration_ms >= %d', self::SLOW_THRESHOLD_MS );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin view, short-lived data
		$results = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a controlled constant; $where is prepared above
				"SELECT id, type, action, duration_ms, status_code, user_id, created_at FROM `{$table}` {$where} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);

		return is_array( $results ) ? $results : array();
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Renders the AJAX / REST Monitor tab.
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['tahcd_ajax_filter'] ) ? sanitize_key( $_GET['tahcd_ajax_filter'] ) : 'all';
		$valid  = array( 'all', 'ajax', 'rest', 'slow' );
		if ( ! in_array( $filter, $valid, true ) ) {
			$filter = 'all';
		}

		$entries = self::get_entries( 50, $filter );

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'AJAX / REST Monitor', 'tahhan-conflict-detective' ) . '</h2>';
		echo '</div>';

		echo '<p class="pcd-scanner-intro">'
			. esc_html__( 'Logs slow AJAX and REST API calls (those taking more than 500 ms). Helps identify bottleneck endpoints affecting frontend and admin performance.', 'tahhan-conflict-detective' )
			. '</p>';

		// Filter bar.
		$base_url = add_query_arg( array( 'page' => Dashboard::PAGE_SLUG, 'tab' => 'ajax-monitor' ), admin_url( 'admin.php' ) );

		$filters = array(
			'all'  => __( 'All',        'tahhan-conflict-detective' ),
			'ajax' => __( 'AJAX',       'tahhan-conflict-detective' ),
			'rest' => __( 'REST',       'tahhan-conflict-detective' ),
			'slow' => __( 'Slow (>500ms)', 'tahhan-conflict-detective' ),
		);

		echo '<div class="pcd-filter-bar" role="toolbar">';
		foreach ( $filters as $key => $label ) {
			$url    = add_query_arg( 'tahcd_ajax_filter', $key, $base_url );
			$active = $key === $filter ? ' pcd-filter-btn--active' : '';
			printf(
				'<a href="%s" class="pcd-filter-btn%s">%s</a>',
				esc_url( $url ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
		echo '</div>';

		if ( empty( $entries ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No slow requests logged yet. Entries appear here when an AJAX or REST call exceeds 500 ms.', 'tahhan-conflict-detective' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="tahcd-ajax-log-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Time',          'tahhan-conflict-detective' ),
			__( 'Type',          'tahhan-conflict-detective' ),
			__( 'Action / Route', 'tahhan-conflict-detective' ),
			__( 'Duration (ms)', 'tahhan-conflict-detective' ),
			__( 'Status Code',   'tahhan-conflict-detective' ),
			__( 'User',          'tahhan-conflict-detective' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$type        = esc_html( strtoupper( (string) $entry->type ) );
			$badge_class = ( 'REST' === $type ) ? 'pcd-badge--info' : 'pcd-badge--warning';
			$user_label  = $entry->user_id ? get_userdata( (int) $entry->user_id ) : false;
			$user_name   = $user_label ? $user_label->user_login : __( 'Guest', 'tahhan-conflict-detective' );

			printf(
				'<tr>
					<td class="pcd-time">%s</td>
					<td><span class="pcd-badge %s">%s</span></td>
					<td class="tahcd-ajax-action">%s</td>
					<td class="tahcd-ajax-duration">%s</td>
					<td>%s</td>
					<td>%s</td>
				</tr>',
				esc_html( date_i18n( 'd-m-Y H:i:s', strtotime( (string) $entry->created_at ) ) ),
				esc_attr( $badge_class ),
				$type,
				esc_html( (string) $entry->action ),
				esc_html( number_format( (float) $entry->duration_ms ) ),
				esc_html( (string) $entry->status_code ),
				esc_html( $user_name )
			);
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
