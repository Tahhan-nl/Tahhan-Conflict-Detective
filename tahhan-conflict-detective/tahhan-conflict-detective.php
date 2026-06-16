<?php
/**
 * Plugin Name:       Tahhan Conflict Detective
 * Plugin URI:        https://github.com/Tahhan-nl/Tahhan-Conflict-Detective
 * Description:       Automatically detects which plugin, theme, or update broke your WordPress site — without manual trial and error.
 * Version:           2.6.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tahhan
 * Author URI:        https://github.com/Tahhan-nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tahhan-conflict-detective
 * Domain Path:       /languages
 *
 * @package TahhanConflictDetective
 */

declare( strict_types=1 );

namespace TahhanConflictDetective;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against double-loading (e.g. mu-plugins + plugins).
if ( defined( 'TAHCD_VERSION' ) ) {
	return;
}

define( 'TAHCD_VERSION',     '2.6.0' );
define( 'TAHCD_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TAHCD_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TAHCD_PLUGIN_FILE', __FILE__ );
define( 'TAHCD_MIN_PHP',     '7.4' );
define( 'TAHCD_MIN_WP',      '5.8' );

/**
 * Abort early if the environment does not meet our requirements.
 * Showing an admin notice is friendlier than a white screen.
 */
if ( version_compare( PHP_VERSION, TAHCD_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', static function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'Conflict Detective requires PHP %1$s or higher. Your server is running PHP %2$s.', 'tahhan-conflict-detective' ),
					TAHCD_MIN_PHP,
					PHP_VERSION
				)
			)
		);
	} );
	return;
}

require_once TAHCD_PLUGIN_DIR . 'includes/class-database.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-change-history.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-error-log.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-health-scan.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-conflict-scanner.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-safe-mode.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-wizard.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-performance.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-cron-monitor.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-ajax-monitor.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-interaction-map.php';
require_once TAHCD_PLUGIN_DIR . 'includes/class-dashboard.php';

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
		register_activation_hook( TAHCD_PLUGIN_FILE, array( 'TahhanConflictDetective\Database', 'install' ) );
		register_deactivation_hook( TAHCD_PLUGIN_FILE, array( 'TahhanConflictDetective\Database', 'on_deactivate' ) );

		add_action( 'plugins_loaded', array( 'TahhanConflictDetective\Change_History',  'init' ) );
		add_action( 'plugins_loaded', array( 'TahhanConflictDetective\Ajax_Monitor',    'init' ) );
		add_action( 'plugins_loaded', array( 'TahhanConflictDetective\Cron_Monitor',    'register_ajax' ) );
		add_action( 'plugins_loaded', array( 'TahhanConflictDetective\Dashboard',       'register_ajax' ) );
		add_action( 'admin_menu',     array( 'TahhanConflictDetective\Dashboard',       'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( 'TahhanConflictDetective\Dashboard', 'enqueue_assets' ) );
	}
}

// Safe Mode MUST init before plugins_loaded fires so its pre_option filter
// and AJAX handlers are registered immediately when the plugin file loads.
Safe_Mode::init();

// Performance Monitor MUST init before plugins_loaded so its `plugin_loaded`
// hook fires for the remaining plugins that load after us.
Performance::init();

// Schema migration: register at priority 0 from the top-level so it fires
// BEFORE Plugin::instance() at priority 5.
add_action( 'plugins_loaded', array( 'TahhanConflictDetective\Database', 'maybe_upgrade' ), 0 );

// Boot.
add_action( 'plugins_loaded', array( 'TahhanConflictDetective\Plugin', 'instance' ), 5 );
