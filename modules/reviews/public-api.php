<?php
/** Public v1 presentation API. Returned Google content must never be persistently cached. */
defined( 'ABSPATH' ) || exit;

function ajcore_get_featured_google_reviews( $limit = 6, $order = 'manual' ) { return AJCore_Reviews::featured( $limit, $order ); }
function ajcore_get_google_review_count() { return AJCore_Reviews::status()['valid_count']; }
function ajcore_get_google_location_summary() { $data = AJCore_Reviews::snapshot(); return array_intersect_key( $data['summary'] ?? array(), array_flip( array( 'total', 'rating', 'title', 'maps_url', 'write_url', 'location' ) ) ); }
function ajcore_get_reviews_status() { return AJCore_Reviews::status(); }
function ajcore_get_reviews_last_sync() { return AJCore_Reviews::status()['last_success']; }
function ajcore_get_featured_testimonials( $limit = 6, $order = 'manual' ) { return AJCore_Testimonials::collection( $limit, $order ); }
function ajcore_get_review_collections( $limit = 6, $order = 'manual' ) { return array( 'google' => ajcore_get_featured_google_reviews( $limit, $order ), 'manual' => ajcore_get_featured_testimonials( $limit, $order ) ); }
function ajcore_get_reviews_display_settings() {
	$data = (array) get_option( 'ajcore_reviews_display', array() );
	return array( 'fallback' => sanitize_text_field( $data['fallback'] ?? '' ), 'order' => ( $data['order'] ?? '' ) === 'date' ? 'date' : 'manual' );
}

/** Business-authored navigation settings, independent of review selection and rating. */
function ajcore_sanitize_review_prompt_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$data = array( 'prompt_enabled' => ! empty( $input['prompt_enabled'] ) );
	foreach ( array( 'prompt_label', 'feedback_url', 'google_review_url' ) as $key ) {
		$value = isset( $input[$key] ) && is_string( $input[$key] ) ? $input[$key] : '';
		$data[$key] = $key === 'prompt_label' ? sanitize_text_field( $value ) : esc_url_raw( $value, array( 'https' ) );
		if ( $key !== 'prompt_label' && ( wp_parse_url( $data[$key], PHP_URL_SCHEME ) !== 'https' || ! wp_parse_url( $data[$key], PHP_URL_HOST ) || wp_parse_url( $data[$key], PHP_URL_USER ) || wp_parse_url( $data[$key], PHP_URL_PASS ) ) ) { $data[$key] = ''; }
	}
	return $data;
}

/**
 * Where the two review destinations point, independent of whether the header
 * prompt bar is switched on — the Rate Us page uses the same links with the bar
 * hidden. Never fetch Google while rendering.
 *
 * @param bool $resolve_google Read the snapshot for a write-review URL when none is configured.
 */
function ajcore_get_review_destinations( $resolve_google = true ) {
	$data = ajcore_sanitize_review_prompt_settings( get_option( 'ajcore_reviews_display', array() ) );
	$summary = $data['google_review_url'] === '' && $resolve_google ? ajcore_get_google_location_summary() : array();
	$google_url = $data['google_review_url'] ?: ( $summary['write_url'] ?? '' );
	return array(
		'available' => $data['feedback_url'] !== '' && $google_url !== '',
		'label' => $data['prompt_label'] ?: __( 'Rate Us', 'ajcore' ),
		'feedback_url' => $data['feedback_url'],
		'google_review_url' => $google_url,
		// A provider-supplied URL inherits the snapshot's expiry; manually configured URLs do not.
		'expires_at' => $summary ? (int) ajcore_get_reviews_status()['expires_at'] : 0,
	);
}

/** Both destinations are identical for every rating. Never fetch Google while rendering. */
function ajcore_get_review_prompt_settings() {
	$enabled = ! empty( ajcore_sanitize_review_prompt_settings( get_option( 'ajcore_reviews_display', array() ) )['prompt_enabled'] );
	// With the bar switched off nothing is rendered, so the snapshot is not read.
	return array( 'enabled' => $enabled ) + ajcore_get_review_destinations( $enabled );
}
