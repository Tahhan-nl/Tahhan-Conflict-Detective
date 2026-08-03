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

if ( is_multisite() ) {
	$blog_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );
		TahhanConflictDetective\Database::drop_tables();
		restore_current_blog();
	}
} else {
	TahhanConflictDetective\Database::drop_tables();
}
