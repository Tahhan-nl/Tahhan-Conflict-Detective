<?php
/**
 * Performance Monitor — tracks per-plugin load-time, memory and DB query impact.
 *
 * Hooks into `plugin_loaded` (fires after every individual plugin is included)
 * to record snapshots and compute the delta cost of each plugin.
 *
 * Data is stored in a 5-minute transient (`tahcd_perf_snapshot`) so that normal
 * page views never query the DB; the AJAX handler refreshes it on demand.
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
 * Tracks per-plugin performance impact.
 *
 * Usage:
 *   Performance::init();   // hooked at file-load time (before plugins_loaded)
 *
 * @since 2.6.0
 */
final class Performance {

	/** Transient key for cached snapshot results. */
	const TRANSIENT_KEY = 'tahcd_perf_snapshot';

	/** Transient TTL in seconds (5 minutes). */
	const TRANSIENT_TTL = 300;

	/** Load-time threshold (ms) above which a plugin is marked "slow". */
	const THRESHOLD_SLOW_MS = 100;

	/** Memory delta threshold (bytes) above which a plugin is marked "heavy". */
	const THRESHOLD_HEAVY_MEMORY = 2097152; // 2 MB

	/** DB query threshold above which a plugin is marked "heavy". */
	const THRESHOLD_HEAVY_QUERIES = 10;

	/**
	 * Internal list of raw snapshots keyed by plugin basename.
	 * Each entry: [ 'time' => float, 'memory' => int, 'queries' => int ]
	 *
	 * @var array<string, array<string, int|float>>
	 */
	private static $snapshots = array();

	/**
	 * Snapshot taken just before the current plugin loads
	 * (i.e. the "previous" reference point).
	 *
	 * @var array<string, int|float>
	 */
	private static $prev = array();

	/**
	 * Whether we have captured the very first baseline snapshot.
	 *
	 * @var bool
	 */
	private static $baseline_taken = false;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Registers the `plugin_loaded` hook at the earliest possible moment.
	 * Must be called from the main plugin file before `plugins_loaded` fires.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Capture a baseline before any subsequent plugin fires.
		self::take_baseline();

		// plugin_loaded fires once per plugin, passing the plugin file path.
		add_action( 'plugin_loaded', array( __CLASS__, 'on_plugin_loaded' ), PHP_INT_MAX, 1 );

		// Register the AJAX handler on plugins_loaded so it is available for
		// admin-ajax.php requests.
		add_action( 'plugins_loaded', array( __CLASS__, 'register_ajax' ) );
	}

	// -------------------------------------------------------------------------
	// Snapshot logic
	// -------------------------------------------------------------------------

	/**
	 * Records the baseline snapshot (time/memory/queries before plugins load).
	 *
	 * @return void
	 */
	private static function take_baseline(): void {
		if ( self::$baseline_taken ) {
			return;
		}
		self::$prev           = self::current_snapshot();
		self::$baseline_taken = true;
	}

	/**
	 * Returns the current runtime state.
	 *
	 * @return array<string, int|float>
	 */
	private static function current_snapshot(): array {
		global $wpdb;
		return array(
			'time'    => microtime( true ),
			'memory'  => memory_get_usage(),
			'queries' => isset( $wpdb ) ? (int) $wpdb->num_queries : 0,
		);
	}

	/**
	 * Called after every individual plugin has been loaded.
	 * Computes the delta vs the previous snapshot and stores the result.
	 *
	 * @param string $plugin Full path to the plugin file that just loaded.
	 * @return void
	 */
	public static function on_plugin_loaded( string $plugin ): void {
		$now  = self::current_snapshot();
		$prev = self::$prev;

		$basename = plugin_basename( $plugin );

		// Delta calculation.
		$delta_time    = ( $now['time']    - $prev['time'] )    * 1000; // ms
		$delta_memory  = $now['memory']  - $prev['memory'];             // bytes
		$delta_queries = $now['queries'] - $prev['queries'];

		self::$snapshots[ $basename ] = array(
			'time_ms'  => round( $delta_time, 2 ),
			'memory'   => $delta_memory,
			'queries'  => $delta_queries,
		);

		// Move forward the "previous" pointer.
		self::$prev = $now;
	}

	// -------------------------------------------------------------------------
	// Data access
	// -------------------------------------------------------------------------

	/**
	 * Returns the latest performance data.
	 * Results are cached in a transient for TRANSIENT_TTL seconds.
	 *
	 * @return array<string, array<string, mixed>>  Keyed by plugin basename.
	 */
	public static function get_data(): array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$data = self::build_data();
		set_transient( self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL );
		return $data;
	}

	/**
	 * Forces a refresh of the transient and returns fresh data.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function refresh(): array {
		delete_transient( self::TRANSIENT_KEY );
		return self::get_data();
	}

	/**
	 * Builds the enriched performance data from raw snapshots.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_data(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$data        = array();

		foreach ( self::$snapshots as $basename => $snap ) {
			$name   = isset( $all_plugins[ $basename ] ) ? $all_plugins[ $basename ]['Name'] : $basename;
			$status = self::compute_status( (float) $snap['time_ms'], (int) $snap['memory'], (int) $snap['queries'] );

			$data[ $basename ] = array(
				'name'      => $name,
				'time_ms'   => $snap['time_ms'],
				'memory_kb' => round( $snap['memory'] / 1024, 1 ),
				'queries'   => $snap['queries'],
				'status'    => $status,
			);
		}

		// Sort by load time descending (slowest first).
		uasort( $data, static function ( $a, $b ) {
			return $b['time_ms'] <=> $a['time_ms'];
		} );

		return $data;
	}

	/**
	 * Computes a status badge string for the given metrics.
	 *
	 * @param float $time_ms  Load time in milliseconds.
	 * @param int   $memory   Memory delta in bytes.
	 * @param int   $queries  DB queries fired.
	 * @return string 'fast' | 'slow' | 'heavy'
	 */
	private static function compute_status( float $time_ms, int $memory, int $queries ): string {
		if ( $memory > self::THRESHOLD_HEAVY_MEMORY || $queries > self::THRESHOLD_HEAVY_QUERIES ) {
			return 'heavy';
		}
		if ( $time_ms > self::THRESHOLD_SLOW_MS ) {
			return 'slow';
		}
		return 'fast';
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Registers the AJAX handler. Called on plugins_loaded.
	 *
	 * @return void
	 */
	public static function register_ajax(): void {
		add_action( 'wp_ajax_tahcd_get_performance', array( __CLASS__, 'ajax_get_performance' ) );
	}

	/**
	 * AJAX handler: returns refreshed performance data as JSON.
	 *
	 * @return void
	 */
	public static function ajax_get_performance(): void {
		check_ajax_referer( 'tahcd_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$data = self::refresh();
		wp_send_json_success( array(
			'data' => $data,
			'html' => self::build_table_html( $data ),
		) );
	}

	// -------------------------------------------------------------------------
	// Render helpers
	// -------------------------------------------------------------------------

	/**
	 * Renders the Performance tab content.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tahhan-conflict-detective' ) );
		}

		$data = self::get_data();

		echo '<div class="pcd-card pcd-card--full">';
		echo '<div class="pcd-card__header">';
		echo '<h2 class="pcd-card__title">' . esc_html__( 'Performance Monitor', 'tahhan-conflict-detective' ) . '</h2>';
		printf(
			'<button id="tahcd-refresh-perf" class="button" type="button">%s</button>',
			esc_html__( 'Refresh Data', 'tahhan-conflict-detective' )
		);
		echo '</div>';

		echo '<p class="pcd-scanner-intro">'
			. esc_html__( 'Shows the estimated load-time, memory and database query cost of each active plugin during this page load. Data is refreshed every 5 minutes or on demand.', 'tahhan-conflict-detective' )
			. '</p>';

		echo '<div id="tahcd-perf-table-wrap">';
		if ( empty( $data ) ) {
			echo '<p class="pcd-empty">' . esc_html__( 'No performance data available yet. Reload the page or click "Refresh Data".', 'tahhan-conflict-detective' ) . '</p>';
		} else {
			echo self::build_table_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- build_table_html() escapes all output internally
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Builds the HTML table for performance data.
	 * All output is escaped internally.
	 *
	 * @param  array<string, array<string, mixed>> $data
	 * @return string
	 */
	public static function build_table_html( array $data ): string {
		ob_start();

		$legend = array(
			'fast'  => __( 'Fast',  'tahhan-conflict-detective' ),
			'slow'  => __( 'Slow',  'tahhan-conflict-detective' ),
			'heavy' => __( 'Heavy', 'tahhan-conflict-detective' ),
		);

		echo '<table class="tahcd-perf-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Plugin',         'tahhan-conflict-detective' ),
			__( 'Load Time (ms)', 'tahhan-conflict-detective' ),
			__( 'Memory (KB)',    'tahhan-conflict-detective' ),
			__( 'DB Queries',     'tahhan-conflict-detective' ),
			__( 'Status',         'tahhan-conflict-detective' ),
		) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $data as $row ) {
			$status       = isset( $row['status'] ) ? (string) $row['status'] : 'fast';
			$status_label = $legend[ $status ] ?? $status;

			printf(
				'<tr class="tahcd-perf-row tahcd-perf-row--%s">
					<td class="tahcd-perf-plugin">%s</td>
					<td class="tahcd-perf-time">%s</td>
					<td class="tahcd-perf-mem">%s</td>
					<td class="tahcd-perf-queries">%d</td>
					<td><span class="tahcd-perf-badge tahcd-perf-badge--%s">%s</span></td>
				</tr>',
				esc_attr( $status ),
				esc_html( (string) ( $row['name'] ?? '' ) ),
				esc_html( number_format( (float) ( $row['time_ms'] ?? 0 ), 2 ) ),
				esc_html( number_format( (float) ( $row['memory_kb'] ?? 0 ), 1 ) ),
				absint( $row['queries'] ?? 0 ),
				esc_attr( $status ),
				esc_html( $status_label )
			);
		}

		echo '</tbody></table>';

		return (string) ob_get_clean();
	}
}
