<?php
/**
 * Database installation and schema management.
 *
 * @package TahhanConflictDetective
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles creation, migration, and removal of custom database tables.
 *
 * Table prefix:  {$wpdb->prefix}cd_
 *
 *   cd_plugin_changes  — audit log of every plugin lifecycle event
 *   cd_errors          — parsed PHP / WordPress error log entries
 *   cd_scans           — serialised health-scan results
 *
 * @since 1.0.0
 */
final class Database {

	/**
	 * The database schema version stored in wp_options.
	 * Bump this when a schema migration is needed.
	 */
	const SCHEMA_VERSION = 3;

	/** @var string Option key that tracks the installed schema version. */
	const OPTION_KEY = 'tahcd_db_version';

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Called on plugin activation.
	 * Creates tables and stores the schema version.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$tables = array(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cd_plugin_changes (
				id               BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
				plugin_slug      VARCHAR(255) NOT NULL,
				plugin_name      VARCHAR(255) NOT NULL,
				action           VARCHAR(50)  NOT NULL,
				plugin_version   VARCHAR(50)           DEFAULT '',
				previous_version VARCHAR(50)           DEFAULT '',
				changed_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY idx_changed_at  (changed_at),
				KEY idx_plugin_slug (plugin_slug(100))
			) $collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cd_errors (
				id           BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
				error_type   VARCHAR(50)  NOT NULL,
				message      TEXT         NOT NULL,
				file         VARCHAR(500)          DEFAULT '',
				line         INT(11)      UNSIGNED DEFAULT 0,
				plugin_slug  VARCHAR(255)          DEFAULT '',
				plugin_name  VARCHAR(255)          DEFAULT '',
				occurred_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_occurred_at (occurred_at),
				KEY idx_plugin_slug (plugin_slug(100))
			) $collate;",

			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cd_scans (
				id           BIGINT(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
				scan_type    VARCHAR(50) NOT NULL,
				result       LONGTEXT    NOT NULL,
				issues_found INT(11)     UNSIGNED DEFAULT 0,
				scanned_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_scanned_at (scanned_at)
			) $collate;",

			// Phase 2: stored conflict detections.
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cd_conflicts (
				id            BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
				plugin_slug   VARCHAR(255) NOT NULL,
				plugin_name   VARCHAR(255) NOT NULL,
				action        VARCHAR(50)  NOT NULL,
				changed_at    DATETIME     NOT NULL,
				error_count   INT(11)      UNSIGNED DEFAULT 0,
				confidence    TINYINT(3)   UNSIGNED DEFAULT 0,
				reason        TEXT                  DEFAULT '',
				resolved      TINYINT(1)   NOT NULL DEFAULT 0,
				detected_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_detected_at (detected_at),
				KEY idx_plugin_slug (plugin_slug(100))
			) $collate;",

			// Phase 3: AJAX / REST slow-call log.
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cd_ajax_log (
				id          BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
				type        VARCHAR(10)  NOT NULL DEFAULT 'ajax',
				action      VARCHAR(200) NOT NULL DEFAULT '',
				duration_ms INT(11)      NOT NULL DEFAULT 0,
				status_code SMALLINT(6)  NOT NULL DEFAULT 200,
				user_id     BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
				created_at  DATETIME     NOT NULL,
				PRIMARY KEY (id),
				KEY type_created (type, created_at)
			) $collate;",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
	}

	/**
	 * Called on plugin deactivation.
	 * Intentionally a no-op — we keep data so the admin can reactivate without
	 * losing history.  Actual cleanup happens in uninstall.php.
	 *
	 * @return void
	 */
	public static function on_deactivate(): void {}

	/**
	 * Drops all plugin tables and removes associated options.
	 * Called from uninstall.php.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'cd_plugin_changes',
			$wpdb->prefix . 'cd_errors',
			$wpdb->prefix . 'cd_scans',
			$wpdb->prefix . 'cd_conflicts',
			$wpdb->prefix . 'cd_ajax_log',
		);

		foreach ( $tables as $table ) {
			// Table name is built from a controlled prefix, not user input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall schema removal, table names are controlled constants
		}

		delete_option( self::OPTION_KEY );

		// Remove Phase 3 transient (Performance Monitor snapshot).
		delete_transient( 'tahcd_perf_snapshot' );

		// Remove Safe Mode user meta from all users.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk user meta cleanup on uninstall; delete_metadata() requires a user ID.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->usermeta}` WHERE `meta_key` IN (%s, %s)",
				'_tahcd_safe_token',
				'_tahcd_disabled_plugins'
			)
		);

		// Remove transient version keys written by Change_History.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk option cleanup on uninstall; no transient API equivalent.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s",
				$wpdb->esc_like( 'tahcd_prev_version_' ) . '%'
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the database schema is up to date.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		return (int) get_option( self::OPTION_KEY, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Runs the installer when the stored schema version is behind SCHEMA_VERSION.
	 * Called on every request via plugins_loaded priority 1 — dbDelta is idempotent
	 * so existing tables are never dropped or truncated.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		global $wpdb;

		// Run install when the stored schema version is behind.
		if ( (int) get_option( self::OPTION_KEY, 0 ) < self::SCHEMA_VERSION ) {
			self::install();
			return;
		}

		// Also install when the main table is physically missing.
		// This handles FTP deployments where the activation hook never fires,
		// or database restores that wiped the tables but kept wp_options intact.
		// Uses esc_like() + prepare() to safely escape the table name in the LIKE clause.
		$table  = $wpdb->prefix . 'cd_plugin_changes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence check; no transient API equivalent.
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		if ( null === $exists ) {
			self::install();
		}
	}

	/**
	 * Returns true when all required tables exist in the database.
	 * Use this as a lightweight guard before running queries.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'cd_plugin_changes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema existence check; no transient API equivalent.
		return null !== $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}
}
