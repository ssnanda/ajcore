<?php
/**
 * The public "Rate Us" page: the same five stars the header prompt shows, at
 * page scale, followed by a "Visit Us" block with the map, address and phone.
 *
 * Star behaviour is deliberately identical to the header prompt bar — one to
 * four stars go to the private feedback page, five goes to Google — so the
 * page cannot become a second, differently-behaving rating widget.
 *
 * Everything is self-contained (markup plus one inline stylesheet) so the page
 * renders on any theme; a theme that wants its own look can filter the parts.
 */
defined( 'ABSPATH' ) || exit;

final class AJCore_Rate_Us {
	const OPTION    = 'ajcore_reviews_rate_us';
	const PAGE_SLUG = 'rate-us';
	const SHORTCODE = 'ajcore_rate_us';

	/** Printed once per request, the first time the shortcode renders. */
	private static $styled = false;

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		// Headers must be settled before the theme starts printing, so the decision
		// is made here rather than while the shortcode renders.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_no_cache' ) );
	}

	/**
	 * A Google-supplied write-review URL expires with the snapshot it came from,
	 * so a page carrying one must not be cached past that point. A manually
	 * configured URL never expires and the page caches normally.
	 */
	public static function maybe_no_cache() {
		if ( ! is_singular() || ! function_exists( 'ajcore_get_review_destinations' ) ) { return; }
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) { return; }
		$settings = ajcore_get_review_destinations();
		if ( empty( $settings['available'] ) || empty( $settings['expires_at'] ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( ! headers_sent() ) { nocache_headers(); header( 'Cache-Control: no-store, private, max-age=0' ); }
	}

	private static function stored() { return (array) get_option( self::OPTION, array() ); }

	/** The page we created previously, or an existing page already on the slug. */
	public static function page_id() {
		$stored = self::stored();
		$id     = (int) ( $stored['page_id'] ?? 0 );
		if ( $id && get_post_type( $id ) === 'page' && ! in_array( get_post_status( $id ), array( false, 'trash' ), true ) ) { return $id; }
		$page = get_page_by_path( self::PAGE_SLUG );
		return $page ? (int) $page->ID : 0;
	}

	public static function page_exists() { return self::page_id() > 0; }

	/** The real permalink when the page exists, otherwise where it would be created. */
	public static function suggested_url() {
		$page = self::page_id();
		$url  = $page ? get_permalink( $page ) : home_url( '/' . self::PAGE_SLUG . '/' );
		return is_string( $url ) ? $url : '';
	}

	private static function page_content() {
		return "<!-- wp:shortcode -->\n[" . self::SHORTCODE . "]\n<!-- /wp:shortcode -->";
	}

	/**
	 * Create the page when it is missing, or add the shortcode to a page already
	 * sitting on the slug. Idempotent, like the feedback-page button next to it.
	 *
	 * @return array|WP_Error
	 */
	public static function provision() {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'access_denied' ); }

		$created = array( 'page' => false, 'shortcode' => false );
		$page_id = self::page_id();

		if ( ! $page_id ) {
			$page_id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Rate Us', 'ajcore' ),
				'post_name'    => self::PAGE_SLUG,
				'post_content' => self::page_content(),
			), true );
			if ( is_wp_error( $page_id ) ) { return $page_id; }
			$page_id         = (int) $page_id;
			$created['page'] = true;
		} elseif ( false === strpos( (string) get_post_field( 'post_content', $page_id ), '[' . self::SHORTCODE ) ) {
			// The page exists but does not show the widget — append rather than replace.
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => trim( (string) get_post_field( 'post_content', $page_id ) . "\n\n" . self::page_content() ),
			) );
			$created['shortcode'] = true;
		}

		update_option( self::OPTION, array( 'page_id' => $page_id ), false );

		$url = get_permalink( $page_id );
		return array( 'page_id' => $page_id, 'url' => is_string( $url ) ? $url : '', 'created' => $created );
	}

	/* --------------------------------------------------------------- Rendering */

	/**
	 * Business details for the "Visit Us" block. The defaults are the same
	 * theme settings the header prompt bar already reads (AJNanda's Search & AI
	 * profile writes both), so nothing new has to be filled in twice.
	 *
	 * @return array{name:string,address:string,phone:string}
	 */
	public static function business() {
		$business = array(
			'name'    => (string) get_bloginfo( 'name' ),
			'address' => trim( (string) get_theme_mod( 'seo_business_address', '' ) ),
			'phone'   => trim( (string) get_theme_mod( 'seo_business_phone', '' ) ),
		);
		$filtered = apply_filters( 'ajcore_rate_us_business', $business );
		if ( ! is_array( $filtered ) ) { return $business; }
		return array(
			'name'    => sanitize_text_field( (string) ( $filtered['name'] ?? '' ) ),
			'address' => sanitize_text_field( (string) ( $filtered['address'] ?? '' ) ),
			'phone'   => sanitize_text_field( (string) ( $filtered['phone'] ?? '' ) ),
		);
	}

	/**
	 * A keyless Google Maps embed for the address. Filterable for sites that
	 * would rather paste the place-specific embed URL from Google Maps.
	 */
	public static function map_url( $business ) {
		$query = trim( $business['address'] !== '' ? $business['address'] : $business['name'] );
		$url   = $query === '' ? '' : 'https://www.google.com/maps?q=' . rawurlencode( $query ) . '&output=embed';
		$url   = (string) apply_filters( 'ajcore_rate_us_map_url', $url, $business );
		return wp_parse_url( $url, PHP_URL_SCHEME ) === 'https' && wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/** Where the address text links to: Google Maps search for that address. */
	private static function directions_url( $business ) {
		$query = trim( $business['address'] !== '' ? $business['address'] : $business['name'] );
		return $query === '' ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
	}

	private static function icon( $name ) {
		$paths = array(
			'pin'   => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
			'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.9.35 1.77.66 2.61a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.47-1.47a2 2 0 0 1 2.11-.45c.84.31 1.71.53 2.61.66A2 2 0 0 1 22 16.92z"/>',
		);
		return '<svg class="ajcore-rate-us__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/** One inline stylesheet, printed with the first widget on the page. */
	private static function styles() {
		if ( self::$styled ) { return ''; }
		self::$styled = true;
		return '<style>'
			. '.ajcore-rate-us{margin:0 auto;max-width:960px;text-align:center}'
			. '.ajcore-rate-us__heading{margin:0 0 .35em}'
			. '.ajcore-rate-us__intro{margin:0 auto 1.2em;max-width:44em}'
			. '.ajcore-rate-us__stars{display:inline-flex;align-items:center;justify-content:center;gap:0;line-height:1}'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star{display:inline-flex;align-items:center;justify-content:center;min-width:64px;min-height:64px;border-radius:.25rem;color:#c9ccd1;text-decoration:none;font-size:2.75rem;transition:color .12s ease,transform .12s ease}'
			/* Same fill mechanic as the header prompt: hovering a star lights it
			   and every star before it, so the click target reads as a rating. */
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:hover,'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:focus-visible,'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:has(~ .ajcore-rate-us__star:hover),'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:has(~ .ajcore-rate-us__star:focus-visible){color:#ffb400}'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:hover{transform:scale(1.08)}'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:focus-visible{outline:3px solid currentColor;outline-offset:2px}'
			. '.ajcore-visit-us{margin:2.5rem auto 0;max-width:1200px}'
			. '.ajcore-visit-us__grid{display:flex;flex-wrap:wrap;gap:1.75rem;align-items:stretch}'
			. '.ajcore-visit-us__map,.ajcore-visit-us__body{flex:1 1 320px;min-width:0}'
			. '.ajcore-visit-us__map iframe{display:block;width:100%;height:100%;min-height:340px;border:0}'
			. '.ajcore-visit-us__body{display:flex;flex-direction:column;justify-content:center;text-align:left}'
			. '.ajcore-visit-us__list{list-style:none;margin:1.1rem 0 0;padding:0}'
			. '.ajcore-visit-us__list li{display:flex;align-items:flex-start;gap:.6rem;margin:0 0 .6rem}'
			. '.ajcore-rate-us__icon{flex:0 0 auto;margin-top:.2em}'
			. '@media (max-width:781px){.ajcore-rate-us__stars a.ajcore-rate-us__star{min-width:48px;min-height:48px;font-size:2rem}'
			. '.ajcore-visit-us__body{text-align:center}.ajcore-visit-us__list li{justify-content:center}}'
			. '@media (prefers-reduced-motion:reduce){.ajcore-rate-us__stars a.ajcore-rate-us__star{transition:none}'
			. '.ajcore-rate-us__stars a.ajcore-rate-us__star:hover{transform:none}}'
			. '</style>';
	}

	/** The five stars, wired exactly like the header prompt's. */
	private static function stars( $settings ) {
		$html = '<div class="ajcore-rate-us__stars" role="group" aria-label="' . esc_attr( $settings['label'] ) . '">';
		for ( $star = 1; $star <= 5; ++$star ) {
			$is_google = 5 === $star;
			$url       = $is_google ? $settings['google_review_url'] : $settings['feedback_url'];
			/* translators: %d: selected star rating. */
			$label = $is_google ? __( '5 stars: Leave a review on Google', 'ajcore' ) : sprintf( __( '%d stars: Send private feedback', 'ajcore' ), $star );
			$html .= '<a class="ajcore-rate-us__star" href="' . esc_url( $url ) . '"' . ( $is_google ? ' target="_blank" rel="noopener noreferrer"' : '' )
				. ' aria-label="' . esc_attr( $label ) . '"><span aria-hidden="true">&#9733;</span></a>';
		}
		return $html . '</div>';
	}

	private static function visit_us() {
		$business = self::business();
		$map      = self::map_url( $business );
		$intro    = (string) apply_filters( 'ajcore_rate_us_visit_intro', __( 'Our goal is for you to leave our office with a memorable and enjoyable experience, which is why our welcoming and compassionate staff will do everything they can to make you feel right at home.', 'ajcore' ) );

		// Nothing to show is better than an empty box with a heading over it.
		if ( '' === $map && '' === $business['address'] && '' === $business['phone'] ) { return ''; }

		$items = '';
		if ( '' !== $business['address'] ) {
			$directions = self::directions_url( $business );
			$address    = esc_html( $business['address'] );
			$items     .= '<li>' . self::icon( 'pin' ) . ( $directions !== ''
				? '<a href="' . esc_url( $directions ) . '" target="_blank" rel="noopener noreferrer">' . $address . '</a>'
				: '<span>' . $address . '</span>' ) . '</li>';
		}
		if ( '' !== $business['phone'] ) {
			$tel    = preg_replace( '/[^0-9+]/', '', $business['phone'] );
			$items .= '<li>' . self::icon( 'phone' ) . ( $tel !== ''
				? '<a href="' . esc_attr( 'tel:' . $tel ) . '">' . esc_html( $business['phone'] ) . '</a>'
				: '<span>' . esc_html( $business['phone'] ) . '</span>' ) . '</li>';
		}

		$html = '<section class="ajcore-visit-us"><div class="ajcore-visit-us__grid">';
		if ( '' !== $map ) {
			$html .= '<div class="ajcore-visit-us__map"><iframe src="' . esc_url( $map ) . '" width="100%" height="450" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen'
				. ' title="' . esc_attr( sprintf( /* translators: %s: business name. */ __( 'Map to %s', 'ajcore' ), $business['name'] ) ) . '"></iframe></div>';
		}
		$html .= '<div class="ajcore-visit-us__body"><h2>' . esc_html__( 'Visit Us', 'ajcore' ) . '</h2>'
			. '<p>' . esc_html( $intro ) . '</p>'
			. ( $items !== '' ? '<ul class="ajcore-visit-us__list">' . $items . '</ul>' : '' )
			. '</div></div></section>';
		return $html;
	}

	/**
	 * [ajcore_rate_us] — stars plus the Visit Us block. With the prompt's links
	 * unconfigured the stars would go nowhere, so they are dropped entirely and
	 * only administrators are told why.
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts( array( 'heading' => '', 'intro' => '', 'visit' => 'yes' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );

		// The page's stars work whether or not the header prompt bar is enabled.
		$settings = function_exists( 'ajcore_get_review_destinations' ) ? ajcore_get_review_destinations() : array();
		$ready    = ! empty( $settings['available'] );

		$heading = $atts['heading'] !== '' ? $atts['heading'] : __( 'Click the number of stars to let us know how we are doing!', 'ajcore' );
		$intro   = $atts['intro'];

		$html = self::styles() . '<div class="ajcore-rate-us">';
		$html .= '<h2 class="ajcore-rate-us__heading">' . esc_html( $heading ) . '</h2>';
		if ( '' !== $intro ) { $html .= '<p class="ajcore-rate-us__intro">' . esc_html( $intro ) . '</p>'; }

		if ( $ready ) {
			$html .= self::stars( $settings );
		} elseif ( current_user_can( 'manage_options' ) ) {
			$html .= '<p class="ajcore-rate-us__notice">' . esc_html__( 'Set the private feedback page URL and the Google Write a Review URL in AJ Core → Reviews & Testimonials to activate these stars. Only administrators see this message.', 'ajcore' ) . '</p>';
		}
		$html .= '</div>';

		if ( 'no' !== strtolower( (string) $atts['visit'] ) ) { $html .= self::visit_us(); }

		return $html;
	}
}
