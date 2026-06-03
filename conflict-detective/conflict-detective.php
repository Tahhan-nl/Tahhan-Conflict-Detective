<?php
/**
 * Plugin Name:       Conflict Detective
 * Plugin URI:        https://github.com/Tahhan-nl/WordPress-Conflict-Detective
 * Description:       Automatically detects which plugin, theme, or update broke your WordPress site — without manual trial and error.
 * Version:           2.1.3
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tahhan
 * Author URI:        https://github.com/Tahhan-nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       conflict-detective
 * Domain Path:       /languages
 *
 * @package PluginConflictDetector
 */

declare( strict_types=1 );

namespace PluginConflictDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double-loading (e.g. mu-plugins + plugins).
if ( defined( 'CD_VERSION' ) ) {
	return;
}

define( 'CD_VERSION',     '2.1.3' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'CD_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'CD_PLUGIN_URL',  plugin_dir_url( __FILE__ ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'CD_PLUGIN_FILE', __FILE__ ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'CD_MIN_PHP',     '7.4' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'CD_MIN_WP',      '5.8' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

/**
 * Abort early if the environment does not meet our requirements.
 * Showing an admin notice is friendlier than a white screen.
 */
if ( version_compare( PHP_VERSION, CD_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', static function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'Conflict Detective requires PHP %1$s or higher. Your server is running PHP %2$s.', 'conflict-detective' ),
					CD_MIN_PHP,
					PHP_VERSION
				)
			)
		);
	} );
	return;
}

require_once CD_PLUGIN_DIR . 'includes/class-database.php';
require_once CD_PLUGIN_DIR . 'includes/class-change-history.php';
require_once CD_PLUGIN_DIR . 'includes/class-error-log.php';
require_once CD_PLUGIN_DIR . 'includes/class-health-scan.php';
require_once CD_PLUGIN_DIR . 'includes/class-conflict-scanner.php';
require_once CD_PLUGIN_DIR . 'includes/class-safe-mode.php';
require_once CD_PLUGIN_DIR . 'includes/class-wizard.php';
require_once CD_PLUGIN_DIR . 'includes/class-dashboard.php';

/**
 * Main plugin bootstrap — singleton, never instantiate directly.
 *
 * @since 1.0.0
 */
final class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	private function __construct() {}

	/**
	 * Returns (and, on first call, creates) the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/** Prevent cloning of the singleton. */
	public function __clone() {}

	/** Prevent unserialization of the singleton. */
	public function __wakeup() {}

	/**
	 * Wire up WordPress hooks.
	 *
	 * @return void
	 */
	private function init(): void {
		register_activation_hook( CD_PLUGIN_FILE, array( 'PluginConflictDetector\Database', 'install' ) );
		register_deactivation_hook( CD_PLUGIN_FILE, array( 'PluginConflictDetector\Database', 'on_deactivate' ) );

		// Run schema migration as early as possible (priority 0) so tables always
		// exist before any dashboard query or AJAX handler runs.
		add_action( 'plugins_loaded', array( 'PluginConflictDetector\Database', 'maybe_upgrade' ), 0 );

		// Safe Mode filter must be registered as early as possible.
		add_action( 'plugins_loaded', array( 'PluginConflictDetector\Safe_Mode',     'init' ), 1 );

		add_action( 'plugins_loaded', array( 'PluginConflictDetector\Change_History', 'init' ) );
		add_action( 'plugins_loaded', array( 'PluginConflictDetector\Dashboard',      'register_ajax' ) );
		add_action( 'admin_menu',     array( 'PluginConflictDetector\Dashboard',      'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'PluginConflictDetector\Dashboard', 'enqueue_assets' ) );
	}
}

// Boot.
add_action( 'plugins_loaded', array( 'PluginConflictDetector\Plugin', 'instance' ), 5 );
