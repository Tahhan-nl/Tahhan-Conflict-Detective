<?php
/**
 * Error log reader and parser.
 *
 * @package PluginConflictDetector
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace PluginConflictDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads WordPress debug.log and the server PHP error log, parses each line
 * into a structured entry, and attempts to attribute the error to a plugin.
 *
 * Why we tail instead of reading the whole file: production debug logs can
 * easily exceed hundreds of MB.  We only need the recent end.
 *
 * @since 1.0.0
 */
final class Error_Log {

	/** Maximum lines read from the end of each log file. */
	private const MAX_TAIL_LINES = 500;

	/** Maximum entries returned to the caller after merging all sources. */
	private const DEFAULT_LIMIT = 100;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Returns merged, de-duplicated, reverse-chronological error entries from
	 * all available log sources.
	 *
	 * Each entry is an associative array with the keys:
	 *   time, type, message, file, line, plugin_slug, plugin_name, source
	 *
	 * @param  int $limit Maximum entries to return.
	 * @return array<int, array<string, string>>
	 */
	public static function get_entries( int $limit = self::DEFAULT_LIMIT ): array {
		$entries = array_merge(
			self::parse_file( WP_CONTENT_DIR . '/debug.log', 'debug.log' ),
			self::parse_php_error_log()
		);

		// Sort newest-first, then truncate.
		usort( $entries, static function ( $a, $b ) {
			$ta = ( $a['time'] !== '' ) ? strtotime( $a['time'] ) : 0;
			$tb = ( $b['time'] !== '' ) ? strtotime( $b['time'] ) : 0;
			$ta = $ta ?: 0;
			$tb = $tb ?: 0;
			return $tb <=> $ta;
		} );

		return array_slice( $entries, 0, $limit );
	}

	/**
	 * Returns metadata about the WordPress debug log file.
	 *
	 * @return array{exists: bool, size: int, modified: string, writable: bool, debug_enabled: bool}
	 */
	public static function get_log_file_info(): array {
		$path = WP_CONTENT_DIR . '/debug.log';

		return array(
			'exists'        => file_exists( $path ),
			'size'          => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'modified'      => file_exists( $path ) ? date_i18n( 'd-m-Y H:i', (int) filemtime( $path ) ) : '',
			'writable'      => file_exists( $path ) && is_writable( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- is_readable check only, no WP_Filesystem equivalent for this guard
			'debug_enabled' => defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
		);
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Parses the server PHP error_log if it differs from the WP debug log.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function parse_php_error_log(): array {
		$path = (string) ini_get( 'error_log' );

		if ( empty( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		// Avoid double-counting lines that appear in both files.
		if ( file_exists( WP_CONTENT_DIR . '/debug.log' ) &&
			realpath( $path ) === realpath( WP_CONTENT_DIR . '/debug.log' )
		) {
			return array();
		}

		return self::parse_file( $path, 'error_log' );
	}

	/**
	 * Tails a log file and returns parsed entries.
	 *
	 * @param  string $path   Absolute path to the log file.
	 * @param  string $source Label for the 'source' field (e.g. 'debug.log').
	 * @return array<int, array<string, string>>
	 */
	private static function parse_file( string $path, string $source ): array {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		$entries = array();

		foreach ( self::tail( $path, self::MAX_TAIL_LINES ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$entry = self::parse_line( $line );
			if ( $entry !== null ) {
				$entry['source'] = $source;
				$entries[]       = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Parses a single log line into a structured entry.
	 * Returns null when the line carries no actionable content.
	 *
	 * Supports:
	 *  - WordPress debug.log format: [DD-Mon-YYYY HH:MM:SS UTC] PHP <type>: <msg> in <file> on line <n>
	 *  - Apache/Nginx error_log format: [<weekday> <Mon> <DD> <HH>:<MM>:<SS> <YYYY>] [<level>] <msg>
	 *  - Plain lines with no timestamp
	 *
	 * @param  string $line Raw log line.
	 * @return array<string, string>|null
	 */
	private static function parse_line( string $line ): ?array {
		$entry = array(
			'time'        => '',
			'type'        => 'notice',
			'message'     => $line,
			'file'        => '',
			'line'        => '',
			'plugin_slug' => '',
			'plugin_name' => '',
			'source'      => '',
		);

		// WordPress debug.log: [31-May-2026 20:05:12 UTC] PHP Fatal error: ...
		if ( preg_match(
			'/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+\w+)\]\s+PHP\s+(Fatal error|Warning|Notice|Parse error|Deprecated|Strict Standards):\s*(.+?)(?:\s+in\s+(.+?)\s+on line\s+(\d+))?$/i',
			$line,
			$m
		) ) {
			$entry['time']    = $m[1];
			$entry['type']    = self::normalise_type( $m[2] );
			$entry['message'] = trim( $m[3] );
			$entry['file']    = $m[4] ?? '';
			$entry['line']    = $m[5] ?? '';

		// Generic WP log line with timestamp but no PHP type prefix.
		} elseif ( preg_match( '/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}\s+\w+)\]\s*(.+)$/i', $line, $m ) ) {
			$entry['time']    = $m[1];
			$entry['message'] = trim( $m[2] );

		// Apache/Nginx: [Mon Jun 02 20:05:12 2026] [error] [client ...] msg
		} elseif ( preg_match( '/^\[(\w+\s+\w+\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4})\]\s+\[(\w+)\]\s*(.+)$/i', $line, $m ) ) {
			$entry['time']    = $m[1];
			$entry['type']    = self::normalise_type( $m[2] );
			$entry['message'] = trim( $m[3] );
		}

		// Re-classify from message text when the type header is ambiguous.
		if ( $entry['type'] === 'notice' ) {
			$lc = strtolower( $entry['message'] );
			if ( strpos( $lc, 'fatal' ) !== false || strpos( $lc, 'parse error' ) !== false ) {
				$entry['type'] = 'fatal';
			} elseif ( strpos( $lc, 'warning' ) !== false ) {
				$entry['type'] = 'warning';
			}
		}

		// Attribute to a plugin when the file path lives inside wp-content/plugins.
		if ( $entry['file'] !== '' ) {
			$attribution           = self::attribute_to_plugin( $entry['file'] );
			$entry['plugin_slug']  = $attribution['slug'];
			$entry['plugin_name']  = $attribution['name'];
		}

		return $entry;
	}

	/**
	 * Converts a raw PHP error type string to a consistent lowercase key.
	 *
	 * @param  string $raw E.g. "Fatal error", "Warning", "NOTICE".
	 * @return string      One of: fatal, warning, notice, deprecated, parse_error.
	 */
	private static function normalise_type( string $raw ): string {
		$map = array(
			'fatal error'     => 'fatal',
			'fatal'           => 'fatal',
			'warning'         => 'warning',
			'notice'          => 'notice',
			'deprecated'      => 'deprecated',
			'strict standards' => 'notice',
			'parse error'     => 'fatal',
			'error'           => 'fatal',
		);
		return $map[ strtolower( trim( $raw ) ) ] ?? 'notice';
	}

	/**
	 * Attempts to map an absolute file path back to the owning plugin.
	 *
	 * @param  string $file Absolute file path from the error log.
	 * @return array{slug: string, name: string}
	 */
	private static function attribute_to_plugin( string $file ): array {
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$file        = wp_normalize_path( $file );

		if ( strpos( $file, $plugins_dir . '/' ) !== 0 ) {
			return array( 'slug' => '', 'name' => '' );
		}

		$relative = substr( $file, strlen( $plugins_dir ) + 1 );
		$slug     = strtok( $relative, '/' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, $slug . '/' ) === 0 ) {
				return array( 'slug' => $slug, 'name' => $plugin_data['Name'] );
			}
		}

		return array( 'slug' => $slug, 'name' => $slug );
	}

	/**
	 * Memory-efficient file tail — reads from the end without loading the
	 * whole file into memory.
	 *
	 * @param  string $path  Absolute file path.
	 * @param  int    $lines Number of lines to return.
	 * @return string[]      Lines in file order (not reversed).
	 */
	private static function tail( string $path, int $lines ): array {
		// WP_Filesystem is not used here intentionally: the API does not expose
		// fseek/ftell which are required for the memory-efficient reverse-scan
		// algorithm below. The path is validated by the caller (file_exists +
		// is_readable checks) and this method is never called with user input.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem does not expose fseek/ftell needed for memory-efficient reverse scan.
		$fp = fopen( $path, 'rb' );
		if ( ! $fp ) {
			return array();
		}

		$buffer     = '';
		$chunk_size = 8192;

		fseek( $fp, 0, SEEK_END );
		$pos = ftell( $fp );

		while ( $pos > 0 && substr_count( $buffer, "\n" ) < $lines ) {
			$read    = min( $chunk_size, $pos );
			$pos    -= $read;
			fseek( $fp, $pos );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem does not expose fseek/ftell needed for memory-efficient reverse scan.
			$buffer = fread( $fp, $read ) . $buffer;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem does not expose fseek/ftell needed for memory-efficient reverse scan.
		fclose( $fp );

		$all = explode( "\n", $buffer );
		return array_slice( $all, -$lines );
	}
}
