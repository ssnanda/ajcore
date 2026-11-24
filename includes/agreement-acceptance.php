<?php
/**
 * Product-specific agreement acceptance at checkout (generic; no business rules here).
 *
 * A product requires acceptance when its synced Stripe metadata has `agreement_title` and
 * `agreement_version` (optional `agreement_url`). The cart shows one checkbox per required
 * agreement; checkout is refused unless every required agreement was accepted at the current
 * version. Each acceptance is written to {prefix}aj_agreement_acceptances with the UTC
 * timestamp, agreement title/URL/version, product and price IDs, Stripe Checkout Session ID,
 * user ID, a salted IP hash, user agent and page URL. Local to each site (created on first use,
 * not part of the shared portal schema).
 */

defined( 'ABSPATH' ) || exit;

const AJCORE_AGREEMENT_SCHEMA = '1';

/** Agreement required by one synced price row: ['key','title','url','version','product_id'] or null. */
function ajcore_agreement_for_price( $price ) {
	$meta    = is_array( $price ) && ! empty( $price['product_metadata'] ) && is_array( $price['product_metadata'] ) ? $price['product_metadata'] : array();
	$title   = isset( $meta['agreement_title'] ) ? trim( sanitize_text_field( (string) $meta['agreement_title'] ) ) : '';
	$version = isset( $meta['agreement_version'] ) ? trim( sanitize_text_field( (string) $meta['agreement_version'] ) ) : '';
	if ( '' === $title || '' === $version ) {
		return null;
	}
	$url        = isset( $meta['agreement_url'] ) ? esc_url_raw( trim( (string) $meta['agreement_url'] ) ) : '';
	$product_id = isset( $price['product_id'] ) ? sanitize_text_field( (string) $price['product_id'] ) : '';
	return array(
		'key'        => md5( strtolower( $title ) . '|' . $version . '|' . $url ),
		'title'      => $title,
		'url'        => $url,
		'version'    => $version,
		'product_id' => $product_id,
	);
}

/**
 * Agreements required by checkout items, keyed by agreement key, each with the price IDs that
 * need it. $items: [['price_id'=>…], …]; $price_map: price_id => synced price row.
 */
function ajcore_agreements_required_for_items( $items, $price_map ) {
	$required = array();
	foreach ( (array) $items as $item ) {
		$price_id = is_array( $item ) && ! empty( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '';
		if ( '' === $price_id || empty( $price_map[ $price_id ] ) ) {
			continue;
		}
		$agreement = ajcore_agreement_for_price( $price_map[ $price_id ] );
		if ( ! $agreement ) {
			continue;
		}
		if ( ! isset( $required[ $agreement['key'] ] ) ) {
			$required[ $agreement['key'] ]              = $agreement;
			$required[ $agreement['key'] ]['price_ids'] = array();
		}
		$required[ $agreement['key'] ]['price_ids'][] = $price_id;
	}
	return $required;
}

/** Accepted agreement keys from the request (JSON array of keys in $_POST['agreements']). */
function ajcore_agreements_accepted_from_request() {
	$raw  = isset( $_POST['agreements'] ) ? wp_unslash( $_POST['agreements'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the checkout handler.
	$list = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return array_values( array_filter( array_map( 'sanitize_key', (array) $list ), 'strlen' ) );
}

/** Required agreements that were not accepted (empty array = all accepted). */
function ajcore_agreements_missing( $required, $accepted ) {
	return array_values( array_diff_key( (array) $required, array_flip( (array) $accepted ) ) );
}

function ajcore_agreement_table() {
	global $wpdb;
	return $wpdb->prefix . 'aj_agreement_acceptances';
}

function ajcore_agreement_ensure_table() {
	if ( AJCORE_AGREEMENT_SCHEMA === get_option( 'ajcore_agreement_acceptance_schema', '' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = ajcore_agreement_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			accepted_at datetime NOT NULL,
			agreement_title varchar(255) NOT NULL DEFAULT '',
			agreement_url varchar(500) NOT NULL DEFAULT '',
			agreement_version varchar(64) NOT NULL DEFAULT '',
			product_id varchar(64) NOT NULL DEFAULT '',
			price_ids varchar(500) NOT NULL DEFAULT '',
			checkout_session_id varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			page_url varchar(500) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY checkout_session_id (checkout_session_id(64)),
			KEY accepted_at (accepted_at)
		) $charset;"
	);
	update_option( 'ajcore_agreement_acceptance_schema', AJCORE_AGREEMENT_SCHEMA, false );
}

/** Writes one audit row per accepted agreement. Returns the number of rows written. */
function ajcore_agreements_record( $required, $checkout_session_id, $page_url = '' ) {
	if ( empty( $required ) ) {
		return 0;
	}
	ajcore_agreement_ensure_table();
	global $wpdb;
	$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
	$written = 0;
	foreach ( $required as $agreement ) {
		$ok = $wpdb->insert(
			ajcore_agreement_table(),
			array(
				'accepted_at'         => current_time( 'mysql', true ),
				'agreement_title'     => substr( $agreement['title'], 0, 255 ),
				'agreement_url'       => substr( $agreement['url'], 0, 500 ),
				'agreement_version'   => substr( $agreement['version'], 0, 64 ),
				'product_id'          => substr( $agreement['product_id'], 0, 64 ),
				'price_ids'           => substr( implode( ',', array_unique( $agreement['price_ids'] ) ), 0, 500 ),
				'checkout_session_id' => substr( sanitize_text_field( (string) $checkout_session_id ), 0, 255 ),
				'user_id'             => get_current_user_id(),
				'ip_hash'             => '' !== $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '',
				'user_agent'          => $agent,
				'page_url'            => substr( esc_url_raw( (string) $page_url ), 0, 500 ),
			)
		);
		$written += $ok ? 1 : 0;
	}
	return $written;
}

/** Agreement data attributes for a shop card / buy box (empty string when none). */
function ajcore_agreement_data_attributes( $price ) {
	$agreement = ajcore_agreement_for_price( $price );
	if ( ! $agreement ) {
		return '';
	}
	return sprintf(
		' data-agreement-key="%s" data-agreement-title="%s" data-agreement-url="%s" data-agreement-version="%s"',
		esc_attr( $agreement['key'] ),
		esc_attr( $agreement['title'] ),
		esc_attr( $agreement['url'] ),
		esc_attr( $agreement['version'] )
	);
}
