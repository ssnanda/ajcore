<?php
/**
 * Product catalog settings shared by the Products admin screen and the public
 * [ajcore_products] / [ajcore_product] shortcodes.
 *
 * Products themselves stay Stripe prices (the synced cache). This option adds the
 * site-side layer Stripe does not hold well:
 *   - categories      saved display names + order; names found in Stripe metadata
 *                     are added automatically (see ajcore_product_catalog_categories)
 *   - assign          price_id => [ category slugs ]; only used for products with no
 *                     Stripe metadata `category` (comma-separated names)
 *   - pages           price_id => WP page ID (the product's own page)
 *   - catalog_page    page that holds the listing; 0 = first published page that
 *                     contains [ajcore_products
 *   - ui              listing UI: pagination, search, category filter, card links,
 *                     tablet/phone columns
 *
 * @package AJCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AJCORE_PRODUCT_CATALOG_OPTION = 'ajcore_product_catalog';

function ajcore_product_catalog_ui_defaults() {
	// Defaults keep an existing listing looking exactly as before until UI is configured:
	// no paging, no search; the category filter only appears once categories are assigned.
	return array(
		'per_page'           => 0,
		'search'             => 'no',
		'search_placeholder' => __( 'Search services', 'ajforms' ),
		'filter'             => 'chips',
		'phone_filter'       => 'chips',
		'all_label'          => __( 'All', 'ajforms' ),
		'counts'             => 'yes',
		'link'               => 'yes',
		'link_label'         => __( 'Details', 'ajforms' ),
		'cols_tablet'        => 2,
		'cols_phone'         => 1,
	);
}

function ajcore_product_catalog_normalize_ui( $raw ) {
	$raw      = is_array( $raw ) ? $raw : array();
	$defaults = ajcore_product_catalog_ui_defaults();
	$yes_no   = static function ( $value, $fallback ) {
		if ( null === $value ) {
			return $fallback;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true ) ? 'yes' : 'no';
	};
	$text = static function ( $value, $fallback ) {
		$value = null === $value ? '' : trim( sanitize_text_field( (string) $value ) );
		return '' === $value ? $fallback : $value;
	};
	$pick = static function ( $value, $allowed, $fallback ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	};

	return array(
		'per_page'           => isset( $raw['per_page'] ) ? min( 60, absint( $raw['per_page'] ) ) : $defaults['per_page'],
		'search'             => $yes_no( isset( $raw['search'] ) ? $raw['search'] : null, $defaults['search'] ),
		'search_placeholder' => $text( isset( $raw['search_placeholder'] ) ? $raw['search_placeholder'] : null, $defaults['search_placeholder'] ),
		'filter'             => $pick( isset( $raw['filter'] ) ? $raw['filter'] : '', array( 'chips', 'dropdown', 'none' ), $defaults['filter'] ),
		'phone_filter'       => $pick( isset( $raw['phone_filter'] ) ? $raw['phone_filter'] : '', array( 'chips', 'dropdown' ), $defaults['phone_filter'] ),
		'all_label'          => $text( isset( $raw['all_label'] ) ? $raw['all_label'] : null, $defaults['all_label'] ),
		'counts'             => $yes_no( isset( $raw['counts'] ) ? $raw['counts'] : null, $defaults['counts'] ),
		'link'               => $yes_no( isset( $raw['link'] ) ? $raw['link'] : null, $defaults['link'] ),
		'link_label'         => $text( isset( $raw['link_label'] ) ? $raw['link_label'] : null, $defaults['link_label'] ),
		'cols_tablet'        => isset( $raw['cols_tablet'] ) ? min( 3, max( 1, absint( $raw['cols_tablet'] ) ) ) : $defaults['cols_tablet'],
		'cols_phone'         => isset( $raw['cols_phone'] ) ? min( 2, max( 1, absint( $raw['cols_phone'] ) ) ) : $defaults['cols_phone'],
	);
}

function ajcore_product_catalog_normalize( $raw ) {
	$raw = is_array( $raw ) ? $raw : array();

	$categories = array();
	$seen       = array();
	foreach ( isset( $raw['categories'] ) && is_array( $raw['categories'] ) ? $raw['categories'] : array() as $category ) {
		if ( ! is_array( $category ) ) {
			continue;
		}
		$name = isset( $category['name'] ) ? trim( sanitize_text_field( (string) $category['name'] ) ) : '';
		if ( '' === $name ) {
			continue;
		}
		$slug = isset( $category['slug'] ) ? sanitize_title( (string) $category['slug'] ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( '' === $slug || isset( $seen[ $slug ] ) ) {
			continue;
		}
		$seen[ $slug ] = true;
		$categories[]  = array(
			'slug' => $slug,
			'name' => $name,
		);
	}

	$assign = array();
	foreach ( isset( $raw['assign'] ) && is_array( $raw['assign'] ) ? $raw['assign'] : array() as $price_id => $slugs ) {
		$price_id = sanitize_text_field( (string) $price_id );
		$slugs    = array_values( array_intersect( array_keys( $seen ), array_map( 'sanitize_title', (array) $slugs ) ) );
		if ( '' !== $price_id && ! empty( $slugs ) ) {
			$assign[ $price_id ] = $slugs;
		}
	}

	$pages = array();
	foreach ( isset( $raw['pages'] ) && is_array( $raw['pages'] ) ? $raw['pages'] : array() as $price_id => $page_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		$page_id  = absint( $page_id );
		if ( '' !== $price_id && $page_id > 0 ) {
			$pages[ $price_id ] = $page_id;
		}
	}

	return array(
		'categories'   => $categories,
		'assign'       => $assign,
		'pages'        => $pages,
		'catalog_page' => isset( $raw['catalog_page'] ) ? absint( $raw['catalog_page'] ) : 0,
		'ui'           => ajcore_product_catalog_normalize_ui( isset( $raw['ui'] ) ? $raw['ui'] : array() ),
	);
}

function ajcore_product_catalog_get( $refresh = false ) {
	static $cache = null;
	if ( null === $cache || $refresh ) {
		$cache = ajcore_product_catalog_normalize( get_option( AJCORE_PRODUCT_CATALOG_OPTION, array() ) );
	}
	return $cache;
}

function ajcore_product_catalog_save( $catalog ) {
	$catalog = ajcore_product_catalog_normalize( $catalog );
	update_option( AJCORE_PRODUCT_CATALOG_OPTION, $catalog, false );
	return ajcore_product_catalog_get( true );
}

/** Raw category names from Stripe product metadata (`category`, `categories` or `ajcore_category`, comma-separated). */
function ajcore_product_catalog_metadata_names( $price ) {
	$meta = is_array( $price ) && ! empty( $price['product_metadata'] ) && is_array( $price['product_metadata'] ) ? $price['product_metadata'] : array();
	foreach ( array( 'category', 'categories', 'ajcore_category' ) as $key ) {
		if ( ! empty( $meta[ $key ] ) ) {
			$names = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $meta[ $key ] ) ) ), 'strlen' );
			return array_values( array_unique( $names ) );
		}
	}
	return array();
}

/**
 * Categories in use: the ones saved in Products > Catalog (display name + order), then any
 * new names found in Stripe metadata, in first-seen order. Stripe is the source of truth for
 * which product is in which category; nothing has to be created on the site first.
 */
function ajcore_product_catalog_categories( $prices, $catalog = null ) {
	$catalog    = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$categories = array();
	foreach ( $catalog['categories'] as $category ) {
		$categories[ $category['slug'] ] = $category;
	}
	foreach ( (array) $prices as $price ) {
		foreach ( ajcore_product_catalog_metadata_names( $price ) as $name ) {
			$slug = sanitize_title( $name );
			if ( '' !== $slug && ! isset( $categories[ $slug ] ) && ! ajcore_product_catalog_match_slug( $name, $categories ) ) {
				$categories[ $slug ] = array( 'slug' => $slug, 'name' => $name );
			}
		}
	}
	return array_values( $categories );
}

/** Slug of the category whose slug or name matches $name, or ''. */
function ajcore_product_catalog_match_slug( $name, $categories ) {
	$key = sanitize_title( $name );
	foreach ( $categories as $category ) {
		if ( $category['slug'] === $key || sanitize_title( $category['name'] ) === $key ) {
			return $category['slug'];
		}
	}
	return '';
}

/** Category slugs for one synced price: Stripe metadata first, else boxes ticked in Products > Catalog. */
function ajcore_product_catalog_price_categories( $price, $catalog = null ) {
	$catalog = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$names   = ajcore_product_catalog_metadata_names( $price );
	if ( ! empty( $names ) ) {
		$slugs = array();
		foreach ( $names as $name ) {
			$slug    = ajcore_product_catalog_match_slug( $name, $catalog['categories'] );
			$slugs[] = '' !== $slug ? $slug : sanitize_title( $name );
		}
		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	$price_id = is_array( $price ) && ! empty( $price['id'] ) ? (string) $price['id'] : '';
	return '' !== $price_id && ! empty( $catalog['assign'][ $price_id ] ) ? $catalog['assign'][ $price_id ] : array();
}

/**
 * Standard URL slug for a product's page (the last part of /service/<slug>/).
 *
 * 1. Stripe product metadata `page_slug` (or `slug`) when set: the deliberate override,
 *    kept in Stripe next to `category` so it survives re-syncs and applies on every site.
 * 2. Otherwise derived from the product name for search: lowercase words only, dotted
 *    abbreviations joined (U.S. -> us), "&" dropped, and filler / billing words removed
 *    (a, the, for, subscription, service…), capped at 6 words. Examples:
 *      Virtual Office Subscription               -> virtual-office
 *      LLC Setup – U.S.-Based Owners             -> llc-setup-us-based-owners
 *      NC Assumed Business Name (DBA) Registration -> nc-assumed-business-name-dba-registration
 *
 * Only used when a page is created; existing pages keep their address (changing a live
 * URL would break links and lose ranking).
 */
function ajcore_product_catalog_page_slug( $price ) {
	$meta = is_array( $price ) && ! empty( $price['product_metadata'] ) && is_array( $price['product_metadata'] ) ? $price['product_metadata'] : array();
	foreach ( array( 'page_slug', 'slug' ) as $key ) {
		if ( ! empty( $meta[ $key ] ) ) {
			$override = sanitize_title( (string) $meta[ $key ] );
			if ( '' !== $override ) {
				return $override;
			}
		}
	}

	$name = is_array( $price ) && isset( $price['product_name'] ) ? (string) $price['product_name'] : '';
	$name = remove_accents( html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$name = preg_replace_callback( '/\b(?:[A-Za-z]\.){2,}/', static function ( $m ) { return str_replace( '.', '', $m[0] ); }, $name ); // U.S. -> US
	$words = preg_split( '/[^a-z0-9]+/', strtolower( $name ), -1, PREG_SPLIT_NO_EMPTY );
	$drop  = array( 'a', 'an', 'the', 'and', 'or', 'of', 'for', 'to', 'with', 'in', 'on', 'subscription', 'subscriptions', 'service', 'services', 'plan', 'package' );
	$kept  = array_values( array_diff( $words, $drop ) );
	$words = empty( $kept ) ? $words : $kept;
	$slug  = sanitize_title( implode( '-', array_slice( $words, 0, 6 ) ) );
	return '' !== $slug ? $slug : sanitize_title( is_array( $price ) && isset( $price['id'] ) ? (string) $price['id'] : 'product' );
}

/**
 * The price that requires this one ("Requires" in Settings > Product Catalog), or ''. E.g. for
 * Virtual Office Setup this returns Virtual Office Subscription's price ID.
 */
function ajcore_product_catalog_pair_parent( $price_id ) {
	$price_id     = (string) $price_id;
	$dependencies = get_option( 'ajcore_public_product_dependencies', array() );
	foreach ( is_array( $dependencies ) ? $dependencies : array() as $parent_id => $dependency ) {
		if ( is_array( $dependency ) && isset( $dependency['requires_price_id'] ) && (string) $dependency['requires_price_id'] === $price_id && (string) $parent_id !== $price_id ) {
			return (string) $parent_id;
		}
	}
	return '';
}

/**
 * Published page ID linked to a price, or 0. A paired product without its own page uses its
 * parent's page: the pair is sold, and shown, on one page.
 */
function ajcore_product_catalog_page_for_price( $price_id, $catalog = null, $require_published = true ) {
	$catalog = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$page_id = isset( $catalog['pages'][ (string) $price_id ] ) ? absint( $catalog['pages'][ (string) $price_id ] ) : 0;
	if ( ! $page_id ) {
		$parent_id = ajcore_product_catalog_pair_parent( $price_id );
		$page_id   = '' !== $parent_id && isset( $catalog['pages'][ $parent_id ] ) ? absint( $catalog['pages'][ $parent_id ] ) : 0;
	}
	if ( ! $page_id ) {
		return 0;
	}
	$status = get_post_status( $page_id );
	if ( false === $status || 'trash' === $status ) {
		return 0;
	}
	if ( $require_published && 'publish' !== $status ) {
		return 0;
	}
	return $page_id;
}

/** Price linked to a page (reverse of pages), or ''. */
function ajcore_product_catalog_price_for_page( $page_id, $catalog = null ) {
	$catalog = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$found   = array_search( absint( $page_id ), array_map( 'absint', $catalog['pages'] ), true );
	return false === $found ? '' : (string) $found;
}

/** Page ID of the listing: the configured page, else the first published page using [ajcore_products. */
function ajcore_product_catalog_listing_page_id( $catalog = null ) {
	$catalog = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	if ( $catalog['catalog_page'] && 'publish' === get_post_status( $catalog['catalog_page'] ) ) {
		return (int) $catalog['catalog_page'];
	}

	static $detected = null;
	if ( null === $detected ) {
		global $wpdb;
		$detected = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[ajcore\\_products%' ORDER BY menu_order ASC, ID ASC LIMIT 1"
		);
	}
	return $detected;
}

function ajcore_product_catalog_listing_url( $catalog = null ) {
	$page_id = ajcore_product_catalog_listing_page_id( $catalog );
	return $page_id ? (string) get_permalink( $page_id ) : '';
}
