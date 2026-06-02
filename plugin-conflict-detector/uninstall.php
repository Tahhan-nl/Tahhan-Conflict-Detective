<?php
/**
 * Runs when a site admin clicks "Delete" on the plugin.
 * Called by WordPress core — never directly.
 *
 * @package PluginConflictDetector
 */

// WordPress-supplied constant that guards against direct execution.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-database.php';

PluginConflictDetector\Database::drop_tables();
