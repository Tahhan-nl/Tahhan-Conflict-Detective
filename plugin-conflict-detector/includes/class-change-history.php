<?php
/**
 * Plugin change-history logger.
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
 * Listens to WordPress plugin lifecycle hooks and persists every activation,
 * deactivation, update, and deletion to {prefix}cd_plugin_changes.
 *
 * This is the core "timeline" that lets us later answer:
 * "WooCommerce was updated at 12:10 — errors started at 12:11."
 *
 * @since 1.0.0
 */
final class Change_History {

	/** Option-name prefix for cached "previous version" values. */
	private const PREV_VERSION_PREFIX = 'pcd_prev_version_';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Registers all hooks. Called by the main plugin bootstrap.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'activated_plugin',          array( __CLASS__, 'on_activate' ),   10, 2 );
		add_action( 'deactivated_plugin',        array( __CLASS__, 'on_deactivate' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_update' ),      10, 2 );
		add_action( 'delete_plugin',             array( __CLASS__, 'on_delete' ) );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * @param string $plugin       Relative path to plugin file (slug/file.php).
	 * @param bool   $network_wide Whether the activation was network-wide.
	 * @return void
	 */
	public static function on_activate( string $plugin, bool $network_wide ): void {
		$data = self::read_plugin_data( $plugin );
		self::write( $plugin, $data['Name'], 'activated', $data['Version'] );
	}

	/**
	 * @param string $plugin               Relative path to plugin file.
	 * @param bool   $network_deactivating Whether network-wide.
	 * @return void
	 */
	public static function on_deactivate( string $plugin, bool $network_deactivating ): void {
		$data = self::read_plugin_data( $plugin );
		self::write( $plugin, $data['Name'], 'deactivated', $data['Version'] );
	}

	/**
	 * Fired by WP_Upgrader after one or more plugins have been updated.
	 *
	 * @param \WP_Upgrader $upgrader  Upgrader instance (unused).
	 * @param array        $options   Hook extra data; contains 'type' and 'plugins'.
	 * @return void
	 */
	public static function on_update( $upgrader, array $options ): void {
		if ( ( $options['type'] ?? '' ) !== 'plugin' || empty( $options['plugins'] ) ) {
			return;
		}

		foreach ( (array) $options['plugins'] as $plugin ) {
			$data     = self::read_plugin_data( $plugin );
			$prev_key = self::PREV_VERSION_PREFIX . sanitize_key( $plugin );
			$previous = (string) get_option( $prev_key, '' );

			self::write( $plugin, $data['Name'], 'updated', $data['Version'], $previous );

			// Store the *new* version so the next update has an accurate baseline.
			update_option( $prev_key, $data['Version'], false );
		}
	}

	/**
	 * @param string $plugin Relative path to plugin file.
	 * @return void
	 */
	public static function on_delete( string $plugin ): void {
		// Plugin files are already gone by the time this hook fires; fall back to slug.
		$name = basename( dirname( $plugin ) );
		self::write( $plugin, $name, 'deleted' );
	}

	// -------------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------------

	/**
	 * Returns the most recent change records, newest first.
	 *
	 * @param  int $limit Maximum rows to return.
	 * @return array<int, object>
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}cd_plugin_changes` ORDER BY changed_at DESC LIMIT %d",
				$limit
			)
		);
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Reads the plugin header data for a given relative plugin path.
	 *
	 * @param  string $plugin_relative_path e.g. "woocommerce/woocommerce.php"
	 * @return array{Name: string, Version: string}
	 */
	private static function read_plugin_data( string $plugin_relative_path ): array {
		$full_path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $plugin_relative_path;

		if ( file_exists( $full_path ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data = get_plugin_data( $full_path, false, false );
			return array(
				'Name'    => $data['Name']    ?? $plugin_relative_path,
				'Version' => $data['Version'] ?? '',
			);
		}

		return array( 'Name' => $plugin_relative_path, 'Version' => '' );
	}

	/**
	 * Inserts a single change record into the database.
	 *
	 * @param string $slug             Plugin slug / relative path.
	 * @param string $name             Human-readable plugin name.
	 * @param string $action           'activated' | 'deactivated' | 'updated' | 'deleted'.
	 * @param string $version          New (current) version string.
	 * @param string $previous_version Previous version string (updates only).
	 * @return void
	 */
	private static function write(
		string $slug,
		string $name,
		string $action,
		string $version = '',
		string $previous_version = ''
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'cd_plugin_changes',
			array(
				'plugin_slug'      => $slug,
				'plugin_name'      => $name,
				'action'           => $action,
				'plugin_version'   => $version,
				'previous_version' => $previous_version,
				'changed_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
