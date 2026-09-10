<?php
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-ajcore-reviews-vault.php';
require_once __DIR__ . '/interface-ajcore-review-provider.php';
require_once __DIR__ . '/class-ajcore-google-review-provider.php';
require_once __DIR__ . '/class-ajcore-reviews.php';
require_once __DIR__ . '/class-ajcore-testimonials.php';
require_once __DIR__ . '/class-ajcore-reviews-setup.php';
require_once __DIR__ . '/class-ajcore-rate-us.php';
require_once __DIR__ . '/class-ajcore-reviews-admin.php';
require_once __DIR__ . '/public-api.php';
AJCore_Reviews::init();
AJCore_Testimonials::init();
AJCore_Rate_Us::init();
AJCore_Reviews_Admin::init();

// Keep data on deactivation. WordPress core expires the encrypted transients independently.
register_deactivation_hook( AJCORE_PLUGIN_DIR . 'ajcore.php', function( $network_wide ) {
	$cleanup = function() {
		AJCore_Reviews::unschedule();
		AJCore_Reviews_Vault::delete( 'ajcore_reviews_oauth' );
	};
	if ( is_multisite() && $network_wide ) {
		$offset = 0;
		do { $ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) ); foreach ( $ids as $id ) { switch_to_blog( $id ); $cleanup(); restore_current_blog(); } $offset += 100; } while ( count( $ids ) === 100 );
	} else { $cleanup(); }
} );
