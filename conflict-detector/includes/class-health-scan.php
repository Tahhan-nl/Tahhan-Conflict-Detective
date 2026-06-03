<?php
/**
 * Site health scanner.
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
 * Runs a multi-section health scan and persists the result.
 *
 * Sections
 * --------
 *  plugins  — duplicate functionality, known incompatibilities, outdated plugins
 *  theme    — missing files, missing parent theme, pending updates
 *  server   — PHP version, memory limit, max_execution_time, WP version
 *
 * The scanner is intentionally read-only and non-destructive.
 *
 * @since 1.0.0
 */
final class Health_Scan {

	/**
	 * Plugins grouped by functional category used for "duplicate functionality"
	 * detection.  Two or more active plugins from the same group is a warning.
	 *
	 * @var array<string, array{label: string, slugs: string[]}>
	 */
	private const FUNCTIONAL_GROUPS = array(
		'seo'          => array( 'label' => 'SEO',           'slugs' => array( 'wordpress-seo', 'all-in-one-seo-pack', 'seo-by-rank-math', 'the-seo-framework', 'squirrly-seo' ) ),
		'caching'      => array( 'label' => 'Caching',       'slugs' => array( 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'litespeed-cache', 'wp-rocket', 'comet-cache' ) ),
		'security'     => array( 'label' => 'Security',      'slugs' => array( 'wordfence', 'sucuri-scanner', 'all-in-one-wp-security-and-firewall', 'ithemes-security', 'bulletproof-security' ) ),
		'backup'       => array( 'label' => 'Backup',        'slugs' => array( 'updraftplus', 'backwpup', 'duplicator', 'all-in-one-wp-migration', 'wp-db-backup' ) ),
		'contact-form' => array( 'label' => 'Contact Forms', 'slugs' => array( 'contact-form-7', 'wpforms-lite', 'ninja-forms', 'formidable' ) ),
		'page-builder' => array( 'label' => 'Page Builder',  'slugs' => array( 'elementor', 'beaver-builder-plugin', 'brizy', 'oxygen', 'divi-builder', 'visual-composer' ) ),
		'ecommerce'    => array( 'label' => 'E-commerce',    'slugs' => array( 'woocommerce', 'easy-digital-downloads', 'wp-ecommerce', 'jigoshop' ) ),
	);

	/**
	 * Pairs of plugins known to cause problems when active simultaneously.
	 *
	 * @var array<int, array{slugs: string[], note: string}>
	 */
	private const KNOWN_CONFLICTS = array(
		array(
			'slugs' => array( 'nextgen-gallery', 'jetpack' ),
			'note'  => 'NextGEN Gallery and Jetpack can conflict on gallery pages.',
		),
		array(
			'slugs' => array( 'wp-rocket', 'autoptimize' ),
			'note'  => 'WP Rocket and Autoptimize both handle JS/CSS optimisation — use one or the other.',
		),
		array(
			'slugs' => array( 'jetpack', 'wordfence' ),
			'note'  => 'Jetpack and Wordfence can conflict on login protection features.',
		),
	);

	/** Minimum recommended PHP version (non-EOL). */
	private const PHP_MINIMUM     = '7.4';
	private const PHP_RECOMMENDED = '8.2';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Executes a full health scan, persists the result, and returns the data.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		$results = array(
			'plugins' => self::scan_plugins(),
			'theme'   => self::scan_theme(),
			'server'  => self::scan_server(),
		);

		$issue_count = array_sum( array_map(
			static fn( $section ) => count( $section['issues'] ?? array() ),
			$results
		) );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cd_scans',
			array(
				'scan_type'    => 'health',
				'result'       => wp_json_encode( $results ),
				'issues_found' => $issue_count,
				'scanned_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);

		$results['scanned_at']   = current_time( 'mysql' );
		$results['issues_found'] = $issue_count;

		return $results;
	}

	/**
	 * Retrieves the most recent scan result from the database.
	 *
	 * @return array<string, mixed>|null  null when no scan has been run yet.
	 */
	public static function get_last_scan(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			"SELECT * FROM `{$wpdb->prefix}cd_scans` WHERE scan_type = 'health' ORDER BY scanned_at DESC LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! $row ) {
			return null;
		}

		$result = json_decode( $row->result, true );
		if ( ! is_array( $result ) ) {
			// Corrupted or empty JSON — treat as no scan.
			return null;
		}
		$result['scanned_at']   = $row->scanned_at;
		$result['issues_found'] = (int) $row->issues_found;

		return $result;
	}

	/**
	 * Simple timeline correlation: finds the most recently changed plugin
	 * whose slug appears in recent error log entries.
	 *
	 * Used by the dashboard "Likely Culprit" widget.
	 * Returns null when there is not enough data to make a suggestion.
	 *
	 * @return array{plugin_name: string, plugin_slug: string, action: string,
	 *               changed_at: string, error_count: int}|null
	 */
	public static function get_likely_culprit(): ?array {
		global $wpdb;

		// Last 10 plugin changes.
		$changes = $wpdb->get_results(
			"SELECT * FROM `{$wpdb->prefix}cd_plugin_changes` ORDER BY changed_at DESC LIMIT 10" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( empty( $changes ) ) {
			return null;
		}

		$recent_errors = Error_Log::get_entries( 200 );
		if ( empty( $recent_errors ) ) {
			return null;
		}

		// Count how many errors mention each changed plugin's slug.
		foreach ( $changes as $change ) {
			$slug  = explode( '/', $change->plugin_slug )[0];
			$count = 0;
			foreach ( $recent_errors as $error ) {
				if ( $error['plugin_slug'] === $slug ) {
					$count++;
				}
			}
			if ( $count > 0 ) {
				return array(
					'plugin_name' => $change->plugin_name,
					'plugin_slug' => $slug,
					'action'      => $change->action,
					'changed_at'  => $change->changed_at,
					'error_count' => $count,
				);
			}
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Scan sections
	// -------------------------------------------------------------------------

	/**
	 * @return array{issues: array<int, array<string,string>>, info: array<int, array<string,string>>}
	 */
	private static function scan_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$issues         = array();
		$info           = array();

		// Build a slug → plugin_file map for active plugins only.
		$active_by_slug = array();
		foreach ( $active_plugins as $plugin_file ) {
			$slug                    = explode( '/', $plugin_file )[0];
			$active_by_slug[ $slug ] = $plugin_file;
		}

		// ---- Duplicate functionality ----------------------------------------
		foreach ( self::FUNCTIONAL_GROUPS as $group ) {
			$active_names = array();
			foreach ( $group['slugs'] as $slug ) {
				if ( isset( $active_by_slug[ $slug ] ) ) {
					$active_names[] = $all_plugins[ $active_by_slug[ $slug ] ]['Name'] ?? $slug;
				}
			}
			if ( count( $active_names ) > 1 ) {
				$issues[] = array(
					'type'    => 'duplicate',
					'label'   => $group['label'],
					'message' => sprintf(
						/* translators: 1: category label, 2: comma-separated plugin names */
						__( 'Multiple %1$s plugins active: %2$s', 'conflict-detector' ),
						$group['label'],
						implode( ', ', $active_names )
					),
				);
			}
		}

		// ---- Known incompatibilities -----------------------------------------
		foreach ( self::KNOWN_CONFLICTS as $conflict ) {
			$found = array_filter(
				$conflict['slugs'],
				static function ( $slug ) use ( $active_by_slug ) {
					return isset( $active_by_slug[ $slug ] );
				}
			);
			if ( count( $found ) === count( $conflict['slugs'] ) ) {
				$issues[] = array(
					'type'    => 'incompatible',
					'message' => $conflict['note'],
				);
			}
		}

		// ---- Outdated plugins (no update in > 2 years) -----------------------
		$update_data = get_site_transient( 'update_plugins' );
		foreach ( $active_plugins as $plugin_file ) {
			$no_update = $update_data->no_update[ $plugin_file ] ?? null;
			if ( $no_update && ! empty( $no_update->last_updated ) ) {
				if ( strtotime( $no_update->last_updated ) < strtotime( '-2 years' ) ) {
					$name     = $all_plugins[ $plugin_file ]['Name'] ?? $plugin_file;
					$issues[] = array(
						'type'    => 'outdated',
						'message' => sprintf(
							/* translators: plugin name */
							__( '"%s" has not been updated in over 2 years.', 'conflict-detector' ),
							$name
						),
					);
				}
			}
		}

		// ---- Info list -------------------------------------------------------
		foreach ( $active_plugins as $plugin_file ) {
			$d      = $all_plugins[ $plugin_file ] ?? array();
			$info[] = array(
				'name'    => $d['Name']    ?? $plugin_file,
				'version' => $d['Version'] ?? '',
				'slug'    => explode( '/', $plugin_file )[0],
			);
		}

		return compact( 'issues', 'info' );
	}

	/**
	 * @return array{issues: array<int, array<string,string>>, info: array<string, string>}
	 */
	private static function scan_theme(): array {
		$theme  = wp_get_theme();
		$issues = array();
		$info   = array(
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
			'author'  => $theme->get( 'Author' ),
		);

		// Parent theme missing.
		if ( $theme->parent() && ! $theme->parent()->exists() ) {
			$issues[] = array(
				'type'    => 'missing-parent',
				'message' => sprintf(
					/* translators: parent theme name */
					__( 'Parent theme "%s" is missing.', 'conflict-detector' ),
					$theme->get( 'Template' )
				),
			);
		}

		if ( $theme->parent() ) {
			$info['parent'] = $theme->parent()->get( 'Name' );
		}

		// Essential theme files.
		foreach ( array( 'functions.php', 'style.css', 'index.php' ) as $file ) {
			if ( ! file_exists( get_template_directory() . '/' . $file ) ) {
				$issues[] = array(
					'type'    => 'missing-file',
					'message' => sprintf(
						/* translators: filename */
						__( '"%s" is missing from the active theme.', 'conflict-detector' ),
						$file
					),
				);
			}
		}

		// Pending theme update.
		$updates = get_site_transient( 'update_themes' );
		if ( $updates && isset( $updates->response[ get_template() ] ) ) {
			$issues[] = array(
				'type'    => 'update-available',
				'message' => sprintf(
					/* translators: theme name */
					__( 'An update is available for theme "%s".', 'conflict-detector' ),
					$theme->get( 'Name' )
				),
			);
		}

		return compact( 'issues', 'info' );
	}

	/**
	 * @return array{issues: array<int, array<string,mixed>>, info: array<string, mixed>}
	 */
	private static function scan_server(): array {
		global $wp_version;

		$issues = array();
		$info   = array(
			'php'        => PHP_VERSION,
			'wp_version' => $wp_version,
			'memory'     => WP_MEMORY_LIMIT,
			'max_exec'   => (int) ini_get( 'max_execution_time' ) . 's',
			'debug_mode' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		);

		// PHP version check.
		if ( version_compare( PHP_VERSION, self::PHP_MINIMUM, '<' ) ) {
			$issues[] = array(
				'type'    => 'php-version',
				'message' => sprintf(
					__( 'PHP %s is end-of-life. Minimum supported: PHP %s. Recommended: PHP %s.', 'conflict-detector' ),
					PHP_VERSION, self::PHP_MINIMUM, self::PHP_RECOMMENDED
				),
			);
		} elseif ( version_compare( PHP_VERSION, self::PHP_RECOMMENDED, '<' ) ) {
			$issues[] = array(
				'type'    => 'php-version-warning',
				'message' => sprintf(
					__( 'PHP %s is supported but PHP %s or higher is recommended.', 'conflict-detector' ),
					PHP_VERSION, self::PHP_RECOMMENDED
				),
			);
		}

		// Memory limit.
		$mem_bytes = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
		if ( $mem_bytes < wp_convert_hr_to_bytes( '64M' ) ) {
			$issues[] = array(
				'type'    => 'memory-low',
				'message' => sprintf(
					__( 'Memory limit is %s. At least 64 MB is required; 256 MB recommended.', 'conflict-detector' ),
					WP_MEMORY_LIMIT
				),
			);
		} elseif ( $mem_bytes < wp_convert_hr_to_bytes( '256M' ) ) {
			$issues[] = array(
				'type'    => 'memory-warning',
				'message' => sprintf(
					__( 'Memory limit is %s. 256 MB or more is recommended for heavy plugins.', 'conflict-detector' ),
					WP_MEMORY_LIMIT
				),
			);
		}

		// max_execution_time.
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && $max_exec < 30 ) {
			$issues[] = array(
				'type'    => 'max-exec-low',
				'message' => sprintf(
					__( 'max_execution_time is %d seconds. At least 30 seconds is recommended.', 'conflict-detector' ),
					$max_exec
				),
			);
		}

		// WordPress core update available.
		$core_updates = get_site_transient( 'update_core' );
		if ( $core_updates && ! empty( $core_updates->updates ) ) {
			$latest = $core_updates->updates[0];
			if ( isset( $latest->version ) && version_compare( $wp_version, $latest->version, '<' ) ) {
				$issues[] = array(
					'type'    => 'wp-update',
					'message' => sprintf(
						__( 'WordPress %s is available (current: %s).', 'conflict-detector' ),
						$latest->version, $wp_version
					),
				);
			}
		}

		return compact( 'issues', 'info' );
	}
}
