<?php
/** Remove integration secrets and temporary content; retain manual testimonials by default. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

$ajcore_reviews_remove_site = function() {
	foreach ( array( 'ajcore_reviews_sync', 'ajcore_reviews_retry', 'ajcore_reviews_cleanup' ) as $hook ) { wp_clear_scheduled_hook( $hook ); }
	foreach ( array( 'snapshot', 'choices', 'oauth' ) as $key ) {
		delete_transient( 'ajcore_reviews_' . $key );
		delete_option( '_transient_ajcore_reviews_' . $key ); delete_option( '_transient_timeout_ajcore_reviews_' . $key );
	}
	foreach ( array( 'credentials', 'config', 'selection', 'sync_meta', 'history', 'display', 'api_error' ) as $key ) { delete_option( 'ajcore_reviews_' . $key ); }
	// Explicit site-owner opt-in only. The shared encryption key and other AJ Core data are untouched.
	if ( defined( 'AJCORE_DELETE_MANUAL_TESTIMONIALS' ) && AJCORE_DELETE_MANUAL_TESTIMONIALS ) {
		global $wpdb;
		$after = 0;
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT 100", 'ajcore_testimonial', $after ) );
			foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); }
			if ( $ids ) { $after = (int) end( $ids ); }
		} while ( count( $ids ) === 100 );
	}
};

if ( is_multisite() ) {
	$ajcore_reviews_offset = 0;
	do {
		$ajcore_reviews_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $ajcore_reviews_offset ) );
		foreach ( $ajcore_reviews_sites as $ajcore_reviews_site ) {
			switch_to_blog( $ajcore_reviews_site );
			try { $ajcore_reviews_remove_site(); } finally { restore_current_blog(); }
		}
		$ajcore_reviews_offset += 100;
	} while ( count( $ajcore_reviews_sites ) === 100 );
} else { $ajcore_reviews_remove_site(); }
