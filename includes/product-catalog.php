<?php
/**
 * Product catalog settings shared by the Products admin screen and the public
 * [ajcore_products] / [ajcore_product] shortcodes.
 *
 * Products themselves stay Stripe prices (the synced cache). This option adds the
 * site-side layer Stripe does not hold well:
 *   - categories      saved display names + order; names found in Stripe metadata
 *                     are added automatically (see ajcore_product_catalog_categories)
 *   - assign          price_id => [ category slugs ] chosen in Shop > Product pages. When a
 *                     price has an entry it wins over Stripe metadata `category`; an empty
 *                     array means "no category" on purpose. No entry = Stripe metadata.
 *   - pages           price_id => WP page ID (the product's own page)
 *   - listing         price_id => listing page ID (parent of its product page, where its
 *                     page's Back / Add to Cart return); none = catalog_page
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
		$slugs    = array_values( array_intersect( array_map( 'sanitize_title', (array) $slugs ), array_keys( $seen ) ) );
		if ( '' !== $price_id ) {
			$assign[ $price_id ] = $slugs; // empty = "no category" chosen on purpose
		}
	}

	$listing = array();
	foreach ( isset( $raw['listing'] ) && is_array( $raw['listing'] ) ? $raw['listing'] : array() as $price_id => $page_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		$page_id  = absint( $page_id );
		if ( '' !== $price_id && $page_id > 0 ) {
			$listing[ $price_id ] = $page_id;
		}
	}

	// Recommended services per product, in display order (site choice; wins over Stripe metadata
	// `related`). Empty list = no recommendations on purpose.
	$related = array();
	foreach ( isset( $raw['related'] ) && is_array( $raw['related'] ) ? $raw['related'] : array() as $price_id => $ids ) {
		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			continue;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $ids ) ), 'strlen' ) ) );
		$related[ $price_id ] = array_slice( array_values( array_diff( $ids, array( $price_id ) ) ), 0, 6 );
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
		'related'      => $related,
		'pages'        => $pages,
		'listing'      => $listing,
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
 * Categories in use: the ones saved in Shop > Product pages (display name + order), then any
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

/** Category slugs for one synced price: the choice made in Shop > Product pages, else Stripe metadata. */
function ajcore_product_catalog_price_categories( $price, $catalog = null ) {
	$catalog  = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$price_id = is_array( $price ) && ! empty( $price['id'] ) ? (string) $price['id'] : '';
	if ( '' !== $price_id && isset( $catalog['assign'] ) && array_key_exists( $price_id, $catalog['assign'] ) ) {
		return (array) $catalog['assign'][ $price_id ];
	}
	$names = ajcore_product_catalog_metadata_names( $price );
	if ( ! empty( $names ) ) {
		$slugs = array();
		foreach ( $names as $name ) {
			$slug    = ajcore_product_catalog_match_slug( $name, $catalog['categories'] );
			$slugs[] = '' !== $slug ? $slug : sanitize_title( $name );
		}
		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	return array();
}

/**
 * Standard URL slug for a product's page (the last part of /service/<slug>/).
 *
 * 1. Stripe product metadata `page_slug` (or `service_slug`, `slug`) when set: the deliberate override,
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
	foreach ( array( 'page_slug', 'service_slug', 'slug' ) as $key ) {
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

/**
 * Listing page for one price: its own choice in Shop > Product pages (published pages only),
 * else the pair parent's (a required product shares its parent's page), else the default.
 */
function ajcore_product_catalog_listing_for_price( $price_id, $catalog = null ) {
	$catalog  = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$price_id = (string) $price_id;
	foreach ( array( $price_id, ajcore_product_catalog_pair_parent( $price_id ) ) as $candidate ) {
		$page_id = '' !== $candidate && isset( $catalog['listing'][ $candidate ] ) ? absint( $catalog['listing'][ $candidate ] ) : 0;
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return $page_id;
		}
	}
	return ajcore_product_catalog_listing_page_id( $catalog );
}

function ajcore_product_catalog_listing_url( $catalog = null ) {
	$page_id = ajcore_product_catalog_listing_page_id( $catalog );
	return $page_id ? (string) get_permalink( $page_id ) : '';
}

/* ---- Product page content (Stripe is the source of truth) ---------------------------------
 *
 * A product page is rendered live from the synced Stripe product, so editing the product in
 * Stripe (or in Settings > Product Catalog, which writes to Stripe) and syncing updates every
 * page. Stripe fields used:
 *   name, description                 heading text and the "About" section
 *   marketing_features (max 15)       "What's included" checklist
 *   images (max 8)                    hero image (first)
 *   metadata (each value max 500 chars; lists are one item per line, or "a | b | c"):
 *     display_title    page heading (the product name stays the Stripe/checkout name)
 *     summary          one-line pitch under the title
 *     billing_note, state_fees_note, important_note   "Good to know" notes beside the buy box
 *     service_slug     page address used when the page is created (alias of page_slug)
 *     audience         who it is for
 *     turnaround       e.g. "Same business day"
 *     requirements     what the customer needs to provide (list)
 *     steps            how it works (list)
 *     faq_1_q / faq_1_a … faq_10_q / faq_10_a
 *     cta              final call-to-action heading
 *     agreement_title, agreement_url, agreement_version   agreement accepted in the cart before
 *                      checkout (includes/agreement-acceptance.php)
 *     related          "Other services" shown first, in this order: product or price IDs (list).
 *                      Remaining slots fall back to same-category, then other in-shop products.
 *     seo_title, seo_description   used when the page has no SEO title/description of its own
 */

/** Product content metadata keys, in editor order: key => [label, type (text|list|faq)]. */
function ajcore_product_page_meta_fields() {
	return array(
		'display_title'   => array( __( 'Page heading (instead of the product name)', 'ajforms' ), 'text' ),
		'summary'         => array( __( 'Short pitch', 'ajforms' ), 'text' ),
		'audience'        => array( __( 'Who it is for', 'ajforms' ), 'text' ),
		'turnaround'      => array( __( 'Turnaround', 'ajforms' ), 'text' ),
		'requirements'    => array( __( 'What you need (one per line)', 'ajforms' ), 'list' ),
		'steps'           => array( __( 'How it works (one step per line)', 'ajforms' ), 'list' ),
		'billing_note'    => array( __( 'Billing note', 'ajforms' ), 'note' ),
		'state_fees_note' => array( __( 'Government / state fees note', 'ajforms' ), 'note' ),
		'important_note'  => array( __( 'Important note', 'ajforms' ), 'note' ),
		'service_slug'    => array( __( 'Page address (slug) for a new page', 'ajforms' ), 'text' ),
		'cta'             => array( __( 'Call-to-action heading', 'ajforms' ), 'text' ),
		'related'         => array( __( 'Other services shown first (product or price IDs, one per line)', 'ajforms' ), 'list' ),
		'agreement_title'   => array( __( 'Agreement customers must accept at checkout (title)', 'ajforms' ), 'text' ),
		'agreement_url'     => array( __( 'Agreement link', 'ajforms' ), 'text' ),
		'agreement_version' => array( __( 'Agreement version (acceptance is required again when this changes)', 'ajforms' ), 'text' ),
		'seo_title'       => array( __( 'SEO title', 'ajforms' ), 'text' ),
		'seo_description' => array( __( 'SEO description', 'ajforms' ), 'text' ),
	);
}

/** Number of FAQ pairs (faq_N_q / faq_N_a) supported in Stripe metadata. */
function ajcore_product_page_faq_slots() {
	return 10;
}

/** Splits a metadata list value: one item per line, or items separated by " | ". */
function ajcore_product_page_list( $value ) {
	$items = preg_split( '/\r\n|\r|\n|\s\|\s/', (string) $value );
	return array_values( array_filter( array_map( 'trim', (array) $items ), 'strlen' ) );
}

/** Structured page content for one synced price row. */
function ajcore_product_page_content( $price ) {
	$price = is_array( $price ) ? $price : array();
	$meta  = ! empty( $price['product_metadata'] ) && is_array( $price['product_metadata'] ) ? $price['product_metadata'] : array();
	$text  = static function ( $key ) use ( $meta ) {
		return isset( $meta[ $key ] ) ? trim( sanitize_textarea_field( (string) $meta[ $key ] ) ) : '';
	};
	$faqs = array();
	for ( $i = 1; $i <= ajcore_product_page_faq_slots(); $i++ ) {
		$q = $text( 'faq_' . $i . '_q' );
		$a = $text( 'faq_' . $i . '_a' );
		if ( '' !== $q && '' !== $a ) {
			$faqs[] = array( 'q' => $q, 'a' => $a );
		}
	}
	return array(
		'name'            => isset( $price['product_name'] ) ? (string) $price['product_name'] : '',
		'title'           => '' !== $text( 'display_title' ) ? $text( 'display_title' ) : ( isset( $price['product_name'] ) ? (string) $price['product_name'] : '' ),
		'notes'           => array_filter(
			array(
				__( 'Billing', 'ajforms' )        => $text( 'billing_note' ),
				__( 'Government fees', 'ajforms' ) => $text( 'state_fees_note' ),
				__( 'Important', 'ajforms' )      => $text( 'important_note' ),
			),
			'strlen'
		),
		'summary'         => '' !== $text( 'summary' ) ? $text( 'summary' ) : ( isset( $price['product_summary'] ) ? (string) $price['product_summary'] : '' ),
		'description'     => isset( $price['product_description'] ) ? trim( (string) $price['product_description'] ) : '',
		'rich'            => isset( $price['product_rich_description'] ) ? (string) $price['product_rich_description'] : '',
		'features'        => isset( $price['product_features'] ) ? array_values( (array) $price['product_features'] ) : array(),
		'images'          => isset( $price['product_images'] ) ? array_values( (array) $price['product_images'] ) : array(),
		'audience'        => $text( 'audience' ),
		'turnaround'      => $text( 'turnaround' ),
		'requirements'    => ajcore_product_page_list( $text( 'requirements' ) ),
		'steps'           => ajcore_product_page_list( $text( 'steps' ) ),
		'faqs'            => $faqs,
		'cta'             => $text( 'cta' ),
		'related'         => ajcore_product_page_list( str_replace( ',', "\n", $text( 'related' ) ) ),
		'seo_title'       => $text( 'seo_title' ),
		'seo_description' => $text( 'seo_description' ),
	);
}

/** Synced price row by ID (any status), or null. */
function ajcore_product_catalog_price_row( $price_id ) {
	$cache = get_option( 'ajforms_stripe_products_cache', array() );
	foreach ( is_array( $cache ) && isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array() as $row ) {
		if ( is_array( $row ) && isset( $row['id'] ) && (string) $row['id'] === (string) $price_id ) {
			return $row;
		}
	}
	return null;
}

/**
 * SEO title/description for product pages from Stripe (seo_title / seo_description, else the
 * pitch or description), used only when the page has none of its own. Supplies the values the
 * theme reads (AJNanda's _ajnanda_seo_title / _ajnanda_seo_description) on the front end; never
 * written to the page, so the editor's own fields still win and stay empty until filled.
 */
function ajcore_product_page_seo_meta( $value, $object_id, $meta_key, $single ) {
	static $busy = false;
	if ( $busy || is_admin() || ! in_array( $meta_key, array( '_ajnanda_seo_title', '_ajnanda_seo_description' ), true ) || 'page' !== get_post_type( $object_id ) ) {
		return $value;
	}
	$busy   = true;
	$stored = get_post_meta( $object_id, $meta_key, true );
	$busy   = false;
	if ( '' !== trim( (string) $stored ) ) {
		return $value;
	}
	$price_id = ajcore_product_catalog_price_for_page( $object_id );
	$row      = '' !== $price_id ? ajcore_product_catalog_price_row( $price_id ) : null;
	if ( ! $row ) {
		return $value;
	}
	$content = ajcore_product_page_content( $row );
	if ( '_ajnanda_seo_title' === $meta_key ) {
		$out = '' !== $content['seo_title'] ? $content['seo_title'] : ( $content['title'] !== $content['name'] ? $content['title'] : '' );
	} else {
		$out = '' !== $content['seo_description'] ? $content['seo_description'] : ( '' !== $content['summary'] ? $content['summary'] : $content['description'] );
		$out = wp_html_excerpt( $out, 160, '…' );
	}
	if ( '' === $out ) {
		return $value;
	}
	return array( $out ); // get_metadata() returns [0] of this for $single, the list otherwise
}
add_filter( 'get_post_metadata', 'ajcore_product_page_seo_meta', 10, 4 );

/* ---- Product images: local copies of Stripe images -----------------------------------------
 *
 * Stripe stores product images as public URLs (an image uploaded in the Stripe dashboard is a
 * files.stripe.com link, without a file extension). After each product sync the images of
 * in-shop products are copied once into the Media Library, and the page template, social image
 * and the page's featured image use the local copy. The map is keyed by the Stripe URL, so a
 * changed image in Stripe (new URL) is fetched again; an unchanged one never is.
 */

const AJCORE_PRODUCT_IMAGE_MAP_OPTION = 'ajcore_product_image_map'; // Stripe image URL => attachment ID

/** Local Media Library URL for a Stripe product image, or the Stripe URL until it is copied. */
function ajcore_product_image_url( $url, $size = 'large' ) {
	$map = get_option( AJCORE_PRODUCT_IMAGE_MAP_OPTION, array() );
	$id  = is_array( $map ) && isset( $map[ (string) $url ] ) ? (int) $map[ (string) $url ] : 0;
	$src = $id ? wp_get_attachment_image_url( $id, $size ) : '';
	return $src ? $src : (string) $url;
}

/** Copies one Stripe image into the Media Library. @return int attachment ID, 0 on failure. */
function ajcore_product_image_import( $url, $title, $parent_id = 0 ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = download_url( $url, 20 );
	if ( is_wp_error( $tmp ) ) {
		return 0;
	}
	$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : '';
	$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
	if ( ! isset( $exts[ $mime ] ) ) {
		wp_delete_file( $tmp ); // a temp download that is not an image; not user content
		return 0;
	}
	$file = array( 'name' => sanitize_title( $title ) . '.' . $exts[ $mime ], 'tmp_name' => $tmp );
	$id   = media_handle_sideload( $file, (int) $parent_id, $title );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return 0;
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $title );
	update_post_meta( $id, '_ajcore_stripe_image_url', esc_url_raw( $url ) );
	return (int) $id;
}

/**
 * After a product sync: copy new images of in-shop products (first 3 per product) and give
 * each product page without a featured image its product's first image.
 */
function ajcore_product_images_sync() {
	$cache    = get_option( 'ajforms_stripe_products_cache', array() );
	$display  = get_option( 'ajcore_public_product_display_settings', array() );
	$selected = is_array( $display ) && ! empty( $display['selected_prices'] ) ? array_flip( array_map( 'strval', (array) $display['selected_prices'] ) ) : array();
	$map      = get_option( AJCORE_PRODUCT_IMAGE_MAP_OPTION, array() );
	$map      = is_array( $map ) ? $map : array();
	$catalog  = ajcore_product_catalog_get( true );
	$changed  = false;
	$budget   = 12; // keep one request short; the rest follow on the next sync
	foreach ( is_array( $cache ) && isset( $cache['prices'] ) ? (array) $cache['prices'] : array() as $row ) {
		if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['product_active'] ) || ( $selected && ! isset( $selected[ (string) $row['id'] ] ) ) ) {
			continue;
		}
		$page_id = ajcore_product_catalog_page_for_price( (string) $row['id'], $catalog, false );
		$first   = 0;
		foreach ( array_slice( (array) ( $row['product_images'] ?? array() ), 0, 3 ) as $index => $url ) {
			$url = (string) $url;
			if ( '' === $url ) {
				continue;
			}
			// Never try to download from hosts the public cannot reach (e.g. a *.ddev.site URL that
			// reached the shared Stripe account): each attempt would wait for a timeout on every sync.
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host || 'localhost' === $host || preg_match( '/\.(ddev\.site|local|test|localhost|lan|internal)$/', $host ) ) {
				if ( empty( $map[ $url ] ) && ! attachment_url_to_postid( $url ) ) {
					continue;
				}
			}
			if ( empty( $map[ $url ] ) && ( $own = attachment_url_to_postid( $url ) ) ) {
				$map[ $url ] = (int) $own; // picked from this site's Media Library: nothing to copy
				$changed     = true;
			}
			if ( empty( $map[ $url ] ) || ! wp_attachment_is_image( (int) $map[ $url ] ) ) {
				if ( $budget-- <= 0 ) {
					break 2;
				}
				$id = ajcore_product_image_import( $url, (string) ( $row['product_name'] ?? 'Product' ), $page_id );
				if ( ! $id ) {
					continue;
				}
				$map[ $url ] = $id;
				$changed     = true;
			}
			if ( 0 === $index ) {
				$first = (int) $map[ $url ];
			}
		}
		if ( $page_id && $first && ! has_post_thumbnail( $page_id ) ) {
			set_post_thumbnail( $page_id, $first );
		}
	}
	if ( $changed ) {
		update_option( AJCORE_PRODUCT_IMAGE_MAP_OPTION, $map, false );
	}
}

/** Runs ajcore_product_images_sync() once at the end of a request that changed the product list. */
function ajcore_product_images_queue() {
	static $queued = false;
	if ( ! $queued ) {
		$queued = true;
		add_action( 'shutdown', 'ajcore_product_images_sync', 20 );
	}
}
add_action( 'update_option_ajforms_stripe_products_cache', 'ajcore_product_images_queue' );
add_action( 'add_option_ajforms_stripe_products_cache', 'ajcore_product_images_queue' );

/**
 * Ordered recommended price IDs for one price among $prices (synced rows):
 *  1. its own list: Shop > Product pages "Recommends" (site), else Stripe metadata `related`
 *     (product or price IDs, mapped to prices in $prices);
 *  2. then reciprocal ones: products whose own list names this product.
 * Optional purchases only (never dependencies). The price itself and required add-ons
 * (the second half of a pair) are never returned.
 */
function ajcore_product_catalog_related_price_ids( $price, $prices, $catalog = null ) {
	$catalog  = null === $catalog ? ajcore_product_catalog_get() : $catalog;
	$price_id = is_array( $price ) && ! empty( $price['id'] ) ? (string) $price['id'] : '';
	$by_id    = array();
	$by_prod  = array();
	foreach ( (array) $prices as $row ) {
		if ( is_array( $row ) && ! empty( $row['id'] ) ) {
			$by_id[ (string) $row['id'] ] = $row;
			if ( ! empty( $row['product_id'] ) && ! isset( $by_prod[ (string) $row['product_id'] ] ) ) {
				$by_prod[ (string) $row['product_id'] ] = (string) $row['id'];
			}
		}
	}
	$own_list = static function ( $row ) use ( $catalog, $by_id, $by_prod ) {
		$id = (string) $row['id'];
		if ( isset( $catalog['related'] ) && array_key_exists( $id, (array) $catalog['related'] ) ) {
			$refs = (array) $catalog['related'][ $id ];
		} else {
			$content = ajcore_product_page_content( $row );
			$refs    = (array) $content['related'];
		}
		$out = array();
		foreach ( $refs as $ref ) {
			$ref = (string) $ref;
			if ( isset( $by_id[ $ref ] ) ) {
				$out[] = $ref;
			} elseif ( isset( $by_prod[ $ref ] ) ) {
				$out[] = $by_prod[ $ref ];
			}
		}
		return $out;
	};
	if ( '' === $price_id || ! isset( $by_id[ $price_id ] ) ) {
		return array();
	}
	$ordered = $own_list( $by_id[ $price_id ] );
	foreach ( $by_id as $other_id => $other ) {
		if ( (string) $other_id !== $price_id && in_array( $price_id, $own_list( $other ), true ) ) {
			$ordered[] = (string) $other_id;
		}
	}
	$ordered = array_values( array_unique( $ordered ) );
	return array_values(
		array_filter(
			$ordered,
			static function ( $id ) use ( $price_id ) {
				return $id !== $price_id && '' === ajcore_product_catalog_pair_parent( $id );
			}
		)
	);
}

/**
 * Customer-facing name of a synced price's product: metadata display_title when set, else the
 * Stripe product name. Used for visible shop-card titles; Stripe, checkout, invoices and cart
 * data attributes keep the product name.
 */
function ajcore_product_display_name( $price ) {
	$meta  = is_array( $price ) && ! empty( $price['product_metadata'] ) && is_array( $price['product_metadata'] ) ? $price['product_metadata'] : array();
	$title = isset( $meta['display_title'] ) ? trim( sanitize_text_field( (string) $meta['display_title'] ) ) : '';
	if ( '' !== $title ) {
		return $title;
	}
	return is_array( $price ) && isset( $price['product_name'] ) ? (string) $price['product_name'] : '';
}

/**
 * The page heading of a product page shows the product's display_title (Stripe metadata) when
 * set, e.g. "North Carolina Virtual Office Address", while Stripe and checkout keep the product
 * name. Main page heading only: menus, admin lists and other titles are untouched.
 */
function ajcore_product_page_display_title( $title, $post_id = 0 ) {
	if ( is_admin() || ! $post_id || ! in_the_loop() || ! is_main_query() || ! is_page( $post_id ) || (int) get_queried_object_id() !== (int) $post_id ) {
		return $title;
	}
	$price_id = ajcore_product_catalog_price_for_page( $post_id );
	$row      = '' !== $price_id ? ajcore_product_catalog_price_row( $price_id ) : null;
	if ( ! $row ) {
		return $title;
	}
	$content = ajcore_product_page_content( $row );
	return $content['title'] !== $content['name'] ? esc_html( $content['title'] ) : $title;
}
add_filter( 'the_title', 'ajcore_product_page_display_title', 10, 2 );

/* ---- Test products (Stripe metadata ajcore_test = 1) --------------------------------------
 *
 * DDEV and live share one Stripe account, so a test product (e.g. "AJCore Test Product – Do
 * Not Purchase", used to try product packages on its own page) exists on both. On a live site
 * it is never listed, never sold (public or portal checkout) and never gets a product page,
 * whatever its "In shop" / "In portal" settings say. It works normally on DDEV / local.
 */

/** True on DDEV (*.ddev.site) or a local/development environment; live sites fail closed. */
function ajcore_test_products_allowed() {
	static $allowed = null;
	if ( null === $allowed ) {
		$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allowed = '.ddev.site' === substr( $host, -10 ) || in_array( function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production', array( 'local', 'development' ), true );
		$allowed = (bool) apply_filters( 'ajcore_allow_test_products', $allowed );
	}
	return $allowed;
}

/** Whether a synced price row belongs to a test product (metadata ajcore_test = 1 / true / yes). */
function ajcore_price_is_test( $price ) {
	$meta = is_array( $price ) && ! empty( $price['product_metadata'] ) && is_array( $price['product_metadata'] ) ? $price['product_metadata'] : array();
	return isset( $meta['ajcore_test'] ) && in_array( strtolower( trim( (string) $meta['ajcore_test'] ) ), array( '1', 'true', 'yes' ), true );
}

/** True when this price (row or price ID) is a test product on a site that must not use it. */
function ajcore_test_price_blocked( $price ) {
	if ( ajcore_test_products_allowed() ) {
		return false;
	}
	$row = is_array( $price ) ? $price : ajcore_product_catalog_price_row( (string) $price );
	return is_array( $row ) && ajcore_price_is_test( $row );
}

/**
 * Pages of test products, on a site that must not show them (live). Found by the Catalog link
 * and by the price in an [ajcore_product price_id="…"] shortcode, so a page synced from DDEV
 * (e.g. by Synchy) is caught even when the Catalog link did not come with it. Empty on DDEV.
 *
 * @return int[]
 */
function ajcore_test_product_page_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}
	$ids = array();
	if ( ajcore_test_products_allowed() ) {
		return $ids;
	}
	$cache     = get_option( 'ajforms_stripe_products_cache', array() );
	$price_ids = array();
	foreach ( is_array( $cache ) && isset( $cache['prices'] ) ? (array) $cache['prices'] : array() as $row ) {
		if ( is_array( $row ) && ! empty( $row['id'] ) && ajcore_price_is_test( $row ) ) {
			$price_ids[] = (string) $row['id'];
		}
	}
	if ( ! $price_ids ) {
		return $ids;
	}
	$catalog = ajcore_product_catalog_get();
	foreach ( $price_ids as $price_id ) {
		if ( ! empty( $catalog['pages'][ $price_id ] ) ) {
			$ids[] = (int) $catalog['pages'][ $price_id ];
		}
	}
	global $wpdb;
	foreach ( $price_ids as $price_id ) {
		$like = '%' . $wpdb->esc_like( 'price_id="' . $price_id . '"' ) . '%';
		$ids  = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status <> 'trash' AND post_content LIKE %s", $like ) ) ) );
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	return $ids;
}

/** Live site: a test product's page is a 404 for everyone (also logged-in admins). */
function ajcore_test_product_page_404() {
	if ( is_admin() || ! is_singular( 'page' ) ) {
		return;
	}
	if ( in_array( (int) get_queried_object_id(), ajcore_test_product_page_ids(), true ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}
}
add_action( 'template_redirect', 'ajcore_test_product_page_404', 0 );

/** Live site: keep test product pages out of front-end searches and listings. */
function ajcore_test_product_pages_exclude( $query ) {
	if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
		return;
	}
	$ids = ajcore_test_product_page_ids();
	if ( $ids ) {
		$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $ids ) );
	}
}
add_action( 'pre_get_posts', 'ajcore_test_product_pages_exclude' );

/** Live site: and out of the WordPress sitemap. */
add_filter(
	'wp_sitemaps_posts_query_args',
	static function ( $args, $post_type ) {
		if ( 'page' === $post_type ) {
			$ids = ajcore_test_product_page_ids();
			if ( $ids ) {
				$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $ids );
			}
		}
		return $args;
	},
	10,
	2
);

/* ---- Site-wide floating cart ----------------------------------------------------------------
 *
 * The shop cart lives in sessionStorage per listing page ("ajcoreProductsCart:<path>:<archived>").
 * On every other front-end page, when that cart holds items, a small floating button shows the
 * item count and the amount due today and returns the shopper to the listing with the cart open
 * (?ajp_cart=open). Right side on desktop, bottom-right on phones. Nothing is printed for empty
 * carts or on pages that already render a cart ([ajcore_products] in cart mode).
 */
function ajcore_print_site_cart_launcher() {
	if ( is_admin() || wp_doing_ajax() || is_feed() ) {
		return;
	}
	?>
	<div class="ajcore-site-cart" hidden>
		<a class="ajcore-site-cart-link" href="#">
			<span class="ajcore-site-cart-icon" aria-hidden="true">&#128722;</span>
			<span class="ajcore-site-cart-text"><?php esc_html_e( 'Cart', 'ajforms' ); ?> <span class="ajcore-site-cart-count">0</span></span>
			<span class="ajcore-site-cart-total"></span>
		</a>
	</div>
	<style>
	.ajcore-site-cart{position:fixed;right:20px;top:50%;transform:translateY(-50%);z-index:99990}
	.ajcore-site-cart[hidden]{display:none}
	.ajcore-site-cart-link{display:flex;flex-direction:column;align-items:center;gap:4px;max-width:120px;padding:14px 12px;border-radius:16px;background:#0f7ac6;color:#fff;text-decoration:none;font-weight:800;font-size:14px;line-height:1.25;text-align:center;box-shadow:0 14px 30px rgba(15,23,42,.25)}
	.ajcore-site-cart-link:hover,.ajcore-site-cart-link:focus{background:#0b5f9c;color:#fff}
	.ajcore-site-cart-link:focus-visible{outline:3px solid #fde68a;outline-offset:2px}
	.ajcore-site-cart-icon{font-size:22px}
	.ajcore-site-cart-count{display:inline-flex;min-width:22px;height:22px;align-items:center;justify-content:center;border-radius:999px;background:#fff;color:#0f7ac6;font-size:12px;margin-left:2px}
	.ajcore-site-cart-total{font-size:12px;font-weight:700;opacity:.95}
	@media (max-width:800px){.ajcore-site-cart{top:auto;bottom:16px;right:16px;transform:none}.ajcore-site-cart-link{flex-direction:row;max-width:none;padding:12px 16px;border-radius:999px}}
	@media print{.ajcore-site-cart{display:none!important}}
	</style>
	<script>
	(function () {
		if (document.querySelector('.ajcore-products-wrap-cart')) { return; } // this page has its own cart
		var box = document.querySelector('.ajcore-site-cart');
		if (!box) { return; }
		var best = null;
		try {
			for (var i = 0; i < window.sessionStorage.length; i++) {
				var key = window.sessionStorage.key(i);
				if (!key || key.indexOf('ajcoreProductsCart:') !== 0) { continue; }
				var cart = JSON.parse(window.sessionStorage.getItem(key) || '{}') || {};
				var items = Object.keys(cart).map(function (id) { return cart[id]; }).filter(Boolean);
				if (!items.length) { continue; }
				var path = key.slice('ajcoreProductsCart:'.length).replace(/:(yes|no)$/, '');
				if (!best || items.length > best.items.length) { best = { path: path, items: items }; }
			}
		} catch (e) { return; }
		if (!best) { return; }
		var total = 0, currency = 'usd';
		best.items.forEach(function (item) { total += (parseFloat(item.amount) || 0) * (parseInt(item.quantity, 10) || 1); currency = item.currency || currency; });
		var money;
		try { money = new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.toUpperCase() }).format(total); } catch (e) { money = '$' + total.toFixed(2); }
		var link = box.querySelector('.ajcore-site-cart-link');
		link.href = best.path + (best.path.indexOf('?') === -1 ? '?' : '&') + 'ajp_cart=open';
		box.querySelector('.ajcore-site-cart-count').textContent = String(best.items.length);
		box.querySelector('.ajcore-site-cart-total').textContent = 'Due today ' + money;
		link.setAttribute('aria-label', 'View cart: ' + best.items.length + ' item' + (best.items.length === 1 ? '' : 's') + ', ' + money + ' due today. ' + best.items.map(function (item) { return item.name; }).join(', '));
		link.title = best.items.map(function (item) { return item.name; }).join(', ');
		box.hidden = false;
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'ajcore_print_site_cart_launcher', 20 );
