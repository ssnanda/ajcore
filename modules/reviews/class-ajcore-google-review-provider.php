<?php
defined( 'ABSPATH' ) || exit;

final class AJCore_Google_Review_Provider implements AJCore_Review_Provider {
	const SCOPE = 'https://www.googleapis.com/auth/business.manage';
	private $credentials;
	private $deadline;

	public function __construct() {
		$this->credentials = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' );
		$this->deadline = microtime( true ) + 90;
	}

	public static function redirect_uri() { return admin_url( 'admin-post.php?action=ajcore_reviews_oauth' ); }
	private static function query_url( $url, $query ) {
		return add_query_arg( array_map( function( $value ) { return rawurlencode( (string) $value ); }, $query ), $url );
	}

	public function authorization_url( $state, $challenge ) {
		if ( empty( $this->credentials['client_id'] ) || empty( $this->credentials['client_secret'] ) ) { return new WP_Error( 'credentials_required' ); }
		return self::query_url( 'https://accounts.google.com/o/oauth2/v2/auth', array(
			'client_id' => $this->credentials['client_id'], 'redirect_uri' => self::redirect_uri(),
			'response_type' => 'code', 'scope' => self::SCOPE . ' openid email', 'access_type' => 'offline',
			'prompt' => 'consent', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
		) );
	}

	/** Fixed HTTPS endpoints; redirects disabled so bearer credentials cannot follow a redirect. */
	private function http( $url, $args = array(), $empty_response_allowed = false ) {
		if ( microtime( true ) >= $this->deadline ) { return new WP_Error( 'sync_limit' ); }
		$args = array_merge( array( 'timeout' => 12, 'redirection' => 0, 'limit_response_size' => 2 * MB_IN_BYTES ), $args );
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'transport_error' ); }
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$code = $status === 429 || $status >= 500 ? 'temporary_error' : ( in_array( $status, array( 400, 401 ), true ) ? 'authorization_failed' : 'access_denied' );
			self::record_failure( $url, $status, wp_remote_retrieve_body( $response ) );
			return new WP_Error( $code );
		}
		if ( $empty_response_allowed ) { return true; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'invalid_response' );
	}

	/**
	 * Google's 403s all read the same in the UI, so keep the machine-readable part of
	 * the last one: which endpoint, which status enum, which reason, which service.
	 * Enum-shaped fields only — no message text, no response body, no credentials.
	 */
	private static function record_failure( $url, $status, $body ) {
		$data = json_decode( (string) $body, true );
		$error = is_array( $data ) && is_array( $data['error'] ?? null ) ? $data['error'] : array();
		$enum = function( $value ) { return is_string( $value ) && preg_match( '/^[A-Za-z0-9_.\/-]{1,120}$/D', $value ) ? $value : ''; };
		$reason = ''; $service = '';
		foreach ( (array) ( $error['details'] ?? array() ) as $detail ) {
			if ( ! is_array( $detail ) ) { continue; }
			if ( $reason === '' ) { $reason = $enum( $detail['reason'] ?? '' ); }
			if ( $service === '' ) { $service = $enum( $detail['metadata']['service'] ?? '' ); }
		}
		$parts = wp_parse_url( $url );
		update_option( 'ajcore_reviews_api_error', array(
			'time' => time(), 'http' => (int) $status, 'status' => $enum( $error['status'] ?? '' ),
			'reason' => $reason, 'service' => $service,
			'endpoint' => ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' ),
		), false );
	}

	private function token_request( $fields ) {
		if ( empty( $this->credentials['client_id'] ) || empty( $this->credentials['client_secret'] ) ) { return new WP_Error( 'credentials_required' ); }
		$data = $this->http( 'https://oauth2.googleapis.com/token', array( 'method' => 'POST', 'body' => array_merge( $fields, array(
			'client_id' => $this->credentials['client_id'], 'client_secret' => $this->credentials['client_secret'],
		) ) ) );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! AJCore_Reviews::owns_lock() ) { return new WP_Error( 'busy' ); }
		if ( empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) || empty( $data['expires_in'] ) || ! is_numeric( $data['expires_in'] ) || ( isset( $data['scope'] ) && ! in_array( self::SCOPE, explode( ' ', $data['scope'] ), true ) ) ) { return new WP_Error( 'authorization_failed' ); }
		$this->credentials['access_token'] = $data['access_token'];
		$this->credentials['expires_at'] = time() + min( 3600, max( 60, (int) $data['expires_in'] ) );
		if ( ! empty( $data['refresh_token'] ) && is_string( $data['refresh_token'] ) ) { $this->credentials['refresh_token'] = $data['refresh_token']; }
		if ( empty( $this->credentials['refresh_token'] ) ) { return new WP_Error( 'refresh_token_required' ); }
		return AJCore_Reviews_Vault::write( 'ajcore_reviews_credentials', $this->credentials );
	}

	public function exchange( $code, $verifier ) {
		unset( $this->credentials['refresh_token'], $this->credentials['access_token'], $this->credentials['email'] );
		$result = $this->token_request( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirect_uri(), 'code_verifier' => $verifier ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$identity = $this->get( 'https://openidconnect.googleapis.com/v1/userinfo' );
		if ( ! is_wp_error( $identity ) && ! empty( $identity['email_verified'] ) && ! empty( $identity['email'] ) ) {
			$this->credentials['email'] = sanitize_email( $identity['email'] );
			if ( ! AJCore_Reviews::owns_lock() ) { return new WP_Error( 'busy' ); }
			return AJCore_Reviews_Vault::write( 'ajcore_reviews_credentials', $this->credentials );
		}
		return true;
	}

	private function get( $url ) {
		if ( empty( $this->credentials['refresh_token'] ) ) { return new WP_Error( 'not_connected' ); }
		if ( empty( $this->credentials['access_token'] ) || (int) ( $this->credentials['expires_at'] ?? 0 ) <= time() + 60 ) {
			$result = $this->token_request( array( 'grant_type' => 'refresh_token', 'refresh_token' => $this->credentials['refresh_token'] ) );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return $this->http( $url, array( 'headers' => array( 'Authorization' => 'Bearer ' . $this->credentials['access_token'] ) ) );
	}

	private function listing( $url, $key, $query = array() ) {
		$items = array(); $seen = array();
		do {
			$data = $this->get( self::query_url( $url, $query ) );
			if ( is_wp_error( $data ) ) { return $data; }
			if ( isset( $data[$key] ) && ! is_array( $data[$key] ) ) { return new WP_Error( 'invalid_response' ); }
			foreach ( $data[$key] ?? array() as $item ) {
				$pattern = $key === 'accounts' ? '#^accounts/[0-9]+$#D' : '#^locations/[0-9]+$#D';
				$label = $key === 'accounts' ? 'accountName' : 'title';
				if ( ! is_array( $item ) || ! is_string( $item['name'] ?? null ) || ! preg_match( $pattern, $item['name'] ) || ! is_string( $item[$label] ?? '' ) ) { return new WP_Error( 'invalid_response' ); }
				$items[] = array( 'name' => $item['name'], $label => $item[$label] ?? $item['name'] );
			}
			$token = $data['nextPageToken'] ?? '';
			if ( ! is_string( $token ) || isset( $seen[$token] ) || count( $items ) > 10000 ) { return new WP_Error( 'sync_limit' ); }
			$seen[$token] = true;
			$query['pageToken'] = $token;
		} while ( $token !== '' );
		return $items;
	}

	public function accounts() { return $this->listing( 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts', 'accounts', array( 'pageSize' => 20 ) ); }

	public function locations( $account ) {
		if ( ! preg_match( '#^accounts/[0-9]+$#D', $account ) ) { return new WP_Error( 'invalid_location' ); }
		return $this->listing( 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $account . '/locations', 'locations', array( 'readMask' => 'name,title,metadata', 'pageSize' => 100 ) );
	}

	public function fetch( $account, $location ) {
		if ( ! preg_match( '#^accounts/[0-9]+$#D', $account ) || ! preg_match( '#^locations/[0-9]+$#D', $location ) ) { return new WP_Error( 'invalid_location' ); }
		$retrieved = time();
		$details = $this->get( self::query_url( 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $location, array( 'readMask' => 'name,title,metadata' ) ) );
		if ( is_wp_error( $details ) ) { return $details; }
		if ( ( $details['name'] ?? '' ) !== $location || ! is_string( $details['title'] ?? null ) || ( isset( $details['metadata'] ) && ! is_array( $details['metadata'] ) ) ) { return new WP_Error( 'invalid_response' ); }
		$reviews = array(); $tokens = array(); $token = ''; $summary = null; $bytes = 0;
		do {
			$data = $this->get( self::query_url( 'https://mybusiness.googleapis.com/v4/' . $account . '/' . $location . '/reviews', array( 'pageSize' => 50, 'pageToken' => $token, 'orderBy' => 'updateTime desc' ) ) );
			if ( is_wp_error( $data ) ) { return $data; }
			// Protobuf JSON may omit a zero-valued total on an empty collection.
			if ( ! isset( $data['totalReviewCount'] ) && empty( $data['reviews'] ) && empty( $data['nextPageToken'] ) ) { $data['totalReviewCount'] = 0; }
			if ( ! isset( $data['totalReviewCount'] ) || ! is_int( $data['totalReviewCount'] ) || $data['totalReviewCount'] < 0 || ( isset( $data['reviews'] ) && ! is_array( $data['reviews'] ) ) ) { return new WP_Error( 'invalid_response' ); }
			if ( null === $summary ) { $summary = array( 'total' => $data['totalReviewCount'], 'rating' => $data['averageRating'] ?? null ); }
			if ( $summary['total'] !== $data['totalReviewCount'] ) { return new WP_Error( 'snapshot_changed' ); }
			foreach ( $data['reviews'] ?? array() as $raw ) {
				$review = self::normalize( $raw, $location, $retrieved );
				if ( is_wp_error( $review ) ) { return $review; }
				$bytes += strlen( (string) wp_json_encode( $review ) );
				if ( $bytes > 8 * MB_IN_BYTES ) { return new WP_Error( 'sync_limit' ); }
				$reviews[$review['key']] = $review;
			}
			$token = $data['nextPageToken'] ?? '';
			if ( ! is_string( $token ) || isset( $tokens[$token] ) || count( $tokens ) >= 200 ) { return new WP_Error( 'sync_limit' ); }
			$tokens[$token] = true;
		} while ( $token !== '' );
		if ( count( $reviews ) !== $summary['total'] ) { return new WP_Error( 'snapshot_changed' ); }
		if ( $summary['rating'] !== null && ( ! is_numeric( $summary['rating'] ) || $summary['rating'] < 0 || $summary['rating'] > 5 ) ) { return new WP_Error( 'invalid_response' ); }
		$summary['title'] = is_string( $details['title'] ?? null ) ? $details['title'] : '';
		$summary['maps_url'] = self::url( $details['metadata']['mapsUri'] ?? '' );
		$summary['write_url'] = self::url( $details['metadata']['newReviewUri'] ?? '' );
		$summary['location'] = $location;
		return array( 'reviews' => $reviews, 'summary' => $summary, 'retrieved_at' => $retrieved, 'expires_at' => $retrieved + AJCore_Reviews::TTL );
	}

	public static function url( $value ) { return is_string( $value ) && wp_parse_url( $value, PHP_URL_SCHEME ) === 'https' ? esc_url_raw( $value, array( 'https' ) ) : ''; }

	/** Preserve Google's strings exactly. Validate shape, then escape at the presentation boundary. */
	public static function normalize( $raw, $location, $retrieved ) {
		$ratings = array( 'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5 );
		if ( ! is_array( $raw ) || ! is_string( $raw['reviewId'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,512}$/D', $raw['reviewId'] ) || ! is_string( $raw['starRating'] ?? null ) || ! isset( $ratings[$raw['starRating']] ) || ! is_array( $raw['reviewer'] ?? null ) ) { return new WP_Error( 'invalid_response' ); }
		$review = array( 'id' => $raw['reviewId'], 'key' => hash( 'sha256', $location . '/' . $raw['reviewId'] ), 'kind' => 'google', 'rating' => $ratings[$raw['starRating']], 'location' => $location, 'retrieved_at' => $retrieved, 'expires_at' => $retrieved + AJCore_Reviews::TTL );
		$fields = array( 'name' => $raw['reviewer']['displayName'] ?? '', 'avatar' => $raw['reviewer']['profilePhotoUrl'] ?? '', 'profile_url' => $raw['reviewer']['profileUri'] ?? '', 'text' => $raw['comment'] ?? '', 'date' => $raw['createTime'] ?? '', 'updated_at' => $raw['updateTime'] ?? '', 'language' => $raw['originalLanguage'] ?? '', 'translated_text' => $raw['translatedText'] ?? '', 'translation_status' => $raw['translationStatus'] ?? '', 'relative_date' => $raw['relativePublishTimeDescription'] ?? '', 'source_url' => $raw['googleMapsUri'] ?? '', 'report_url' => $raw['reportingUri'] ?? '' );
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 100000 ) { return new WP_Error( 'invalid_response' ); }
			$review[$key] = $value;
		}
		if ( $review['date'] === '' || strtotime( $review['date'] ) === false ) { return new WP_Error( 'invalid_response' ); }
		return $review;
	}

	public function revoke() {
		$token = $this->credentials['refresh_token'] ?? ( $this->credentials['access_token'] ?? '' );
		return $token ? $this->http( 'https://oauth2.googleapis.com/revoke', array( 'method' => 'POST', 'body' => array( 'token' => $token ) ), true ) : true;
	}
}
