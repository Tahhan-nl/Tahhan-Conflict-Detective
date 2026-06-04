<?php
/**
 * Runs when a site admin clicks "Delete" on the plugin.
 * Called by WordPress core — never directly.
 *
 * @package TahhanConflictDetective
 */

// WordPress-supplied constant that guards against direct execution.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-database.php';

TahhanConflictDetective\Database::drop_tables();
