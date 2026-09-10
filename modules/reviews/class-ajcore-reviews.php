<?php
defined( 'ABSPATH' ) || exit;

final class AJCore_Reviews {
	const INTERVAL = 2419200; // 28 days.
	const TTL = 2462400; // 28.5 days; headroom for WordPress daily transient cleanup.
	const SYNC = 'ajcore_reviews_sync';
	const RETRY = 'ajcore_reviews_retry';
	const CLEANUP = 'ajcore_reviews_cleanup';
	private static $held = array();
	private static function lock_name() { global $wpdb; return 'ajcore_reviews_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 ); }
	public static function owns_lock() {
		global $wpdb;
		return ! empty( self::$held[self::lock_name()] ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_name() ) );
	}

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::SYNC, array( __CLASS__, 'scheduled_sync' ) );
		add_action( self::RETRY, array( __CLASS__, 'retry_sync' ) );
		add_action( self::CLEANUP, array( __CLASS__, 'cleanup' ) );
		add_action( 'rest_api_init', function() {
			register_rest_route( 'ajcore/v1', '/reviews/status', array( 'methods' => 'GET', 'permission_callback' => function() { return current_user_can( 'edit_posts' ); }, 'callback' => function() { $response = new WP_REST_Response( self::status() ); $response->header( 'Cache-Control', 'no-store, private' ); return $response; } ) );
		} );
	}

	public static function provider() {
		$provider = apply_filters( 'ajcore_reviews_provider', new AJCore_Google_Review_Provider() );
		return $provider instanceof AJCore_Review_Provider ? $provider : new AJCore_Google_Review_Provider();
	}

	/** Connection-scoped database lock, shared by sync, credentials, OAuth and moderation. */
	public static function locked( $callback ) {
		global $wpdb;
		$name = self::lock_name();
		if ( ! empty( self::$held[$name] ) ) { return new WP_Error( 'busy' ); }
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { return new WP_Error( 'busy' ); }
		self::$held[$name] = true;
		try { return call_user_func( $callback ); }
		catch ( Throwable $e ) { return new WP_Error( 'operation_failed' ); }
		finally { unset( self::$held[$name] ); $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	}

	public static function config() { return (array) get_option( 'ajcore_reviews_config', array() ); }
	public static function connected() { $c = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' ); return ! empty( $c['refresh_token'] ); }
	public static function schedules( $schedules ) { $schedules['ajcore_reviews_28_days'] = array( 'interval' => self::INTERVAL, 'display' => __( 'Every 28 days (AJ Core reviews)', 'ajcore' ) ); return $schedules; }

	public static function schedule() {
		$config = self::config();
		// No credential decryption and no Google request on ordinary page loads.
		if ( ! empty( $config['location'] ) ) {
			if ( ! wp_next_scheduled( self::SYNC ) ) { wp_schedule_event( time() + self::INTERVAL, 'ajcore_reviews_28_days', self::SYNC ); }
			if ( ! wp_next_scheduled( self::CLEANUP ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP ); }
		}
	}

	public static function unschedule() { foreach ( array( self::SYNC, self::RETRY, self::CLEANUP ) as $hook ) { wp_clear_scheduled_hook( $hook ); } }
	public static function scheduled_sync() { self::sync( 'scheduled' ); }
	public static function retry_sync() { self::sync( 'retry' ); }

	public static function snapshot() {
		$data = AJCore_Reviews_Vault::read( 'ajcore_reviews_snapshot' );
		if ( ! is_int( $data['retrieved_at'] ?? null ) || ! is_int( $data['expires_at'] ?? null ) || ! is_array( $data['reviews'] ?? null ) || ! is_array( $data['summary'] ?? null ) || $data['retrieved_at'] > time() || $data['expires_at'] > $data['retrieved_at'] + self::TTL || $data['expires_at'] <= time() ) { return array(); }
		$summary = $data['summary'];
		if ( ( $summary['location'] ?? '' ) !== ( self::config()['location'] ?? '' ) || ! is_int( $summary['total'] ?? null ) || $summary['total'] < 0 || ! is_string( $summary['title'] ?? null ) || ( isset( $summary['rating'] ) && ( ! is_numeric( $summary['rating'] ) || $summary['rating'] < 0 || $summary['rating'] > 5 ) ) ) { return array(); }
		foreach ( array( 'maps_url', 'write_url' ) as $field ) { if ( ! is_string( $summary[$field] ?? null ) ) { return array(); } }
		foreach ( $data['reviews'] as $key => $review ) { if ( ! self::valid_review( $review ) || $key !== $review['key'] ) { unset( $data['reviews'][$key] ); } }
		return $data;
	}

	public static function cleanup() {
		return self::locked( function() {
			AJCore_Reviews_Vault::cleanup_database_copies();
			if ( ! self::snapshot() ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_snapshot' ); AJCore_Reviews_Vault::delete( 'ajcore_reviews_selection' ); }
			foreach ( array( 'ajcore_reviews_choices', 'ajcore_reviews_oauth' ) as $key ) {
				$data = AJCore_Reviews_Vault::read( $key );
				if ( empty( $data['expires_at'] ) || $data['expires_at'] <= time() ) { AJCore_Reviews_Vault::delete( $key ); }
			}
			return true;
		} );
	}

	public static function sync( $trigger = 'manual' ) {
		return self::locked( function() use ( $trigger ) {
			$start = microtime( true ); $config = self::config();
			$trigger = in_array( $trigger, array( 'manual', 'scheduled', 'retry' ), true ) ? $trigger : 'manual';
			if ( empty( $config['account'] ) || empty( $config['location'] ) ) { return new WP_Error( 'invalid_location' ); }
			if ( ! self::snapshot() ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_snapshot' ); }
			try { $data = self::provider()->fetch( $config['account'], $config['location'] ); }
			catch ( Throwable $e ) { $data = new WP_Error( 'operation_failed' ); }
			if ( ! is_wp_error( $data ) ) {
				if ( ! is_array( $data ) || ! isset( $data['reviews'], $data['summary'], $data['retrieved_at'], $data['expires_at'] ) || ! is_array( $data['reviews'] ) || ! is_array( $data['summary'] ) || $data['retrieved_at'] < (int) $start || $data['retrieved_at'] > time() || $data['expires_at'] > $data['retrieved_at'] + self::TTL || $data['expires_at'] <= time() || strlen( (string) wp_json_encode( $data ) ) > 8 * MB_IN_BYTES ) { $data = new WP_Error( 'invalid_response' ); }
			}
			if ( ! is_wp_error( $data ) ) {
				foreach ( $data['reviews'] as $key => $review ) {
					if ( ! self::valid_review( $review ) || $key !== $review['key'] || $review['retrieved_at'] < $data['retrieved_at'] || $review['expires_at'] > $data['expires_at'] ) { $data = new WP_Error( 'invalid_response' ); break; }
				}
			}
			if ( ! self::owns_lock() ) { return new WP_Error( 'busy' ); }
			if ( ! is_wp_error( $data ) ) {
				$result = AJCore_Reviews_Vault::write( 'ajcore_reviews_snapshot', $data );
				if ( is_wp_error( $result ) ) { $data = $result; }
			}
			$meta = (array) get_option( 'ajcore_reviews_sync_meta', array() );
			if ( $trigger !== 'retry' ) { $meta['failures'] = 0; }
			if ( is_wp_error( $data ) ) {
				$code = self::safe_code( $data->get_error_code() );
				$meta['last_error'] = $code;
				$meta['failures'] = min( 10, (int) ( $meta['failures'] ?? 0 ) + 1 );
				if ( in_array( $code, array( 'temporary_error', 'transport_error', 'snapshot_changed' ), true ) && $meta['failures'] <= 5 && ! wp_next_scheduled( self::RETRY ) ) {
					wp_schedule_single_event( time() + min( DAY_IN_SECONDS, 900 * ( 2 ** ( $meta['failures'] - 1 ) ) ), self::RETRY );
				}
				$count = 0;
			} else {
				$code = ''; $count = count( $data['reviews'] );
				$meta = array( 'last_success' => time(), 'last_error' => '', 'failures' => 0 );
				$selection = array_intersect_key( (array) get_option( 'ajcore_reviews_selection', array() ), $data['reviews'] );
				update_option( 'ajcore_reviews_selection', $selection, false );
				wp_clear_scheduled_hook( self::RETRY );
				wp_clear_scheduled_hook( self::SYNC );
				wp_schedule_event( time() + self::INTERVAL, 'ajcore_reviews_28_days', self::SYNC );
				do_action( 'ajcore_reviews_content_changed' ); // Purge any externally configured page caches.
			}
			update_option( 'ajcore_reviews_sync_meta', $meta, false );
			self::history( $code ?: 'success', $count, microtime( true ) - $start, $trigger );
			self::schedule();
			return $code ? new WP_Error( $code ) : $count;
		} );
	}

	public static function safe_code( $code ) {
		$allowed = array( 'success', 'busy', 'credentials_required', 'not_connected', 'authorization_failed', 'refresh_token_required', 'access_denied', 'invalid_location', 'invalid_response', 'invalid_data', 'snapshot_changed', 'sync_limit', 'temporary_error', 'transport_error', 'encryption_unavailable', 'encryption_key_invalid', 'storage_failed', 'forms_unavailable', 'oauth_state_invalid', 'operation_failed', 'disconnected', 'revoke_failed' );
		return in_array( $code, $allowed, true ) ? $code : 'operation_failed';
	}

	public static function history( $code, $count = 0, $duration = 0, $trigger = 'manual' ) {
		$history = (array) get_option( 'ajcore_reviews_history', array() );
		$history[] = array( 'timestamp' => time(), 'status' => self::safe_code( $code ), 'count' => (int) $count, 'duration' => round( $duration, 2 ), 'trigger' => in_array( $trigger, array( 'manual', 'scheduled', 'retry' ), true ) ? $trigger : 'manual' );
		update_option( 'ajcore_reviews_history', array_slice( $history, -50 ), false );
	}

	/** Caller holds the shared lock. Local credentials are erased even if revocation fails. */
	public static function disconnect() {
		try { $result = self::provider()->revoke(); } catch ( Throwable $e ) { $result = new WP_Error( 'revoke_failed' ); }
		foreach ( array( 'credentials', 'snapshot', 'selection', 'choices', 'oauth', 'config', 'sync_meta' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
		self::unschedule();
		self::history( is_wp_error( $result ) ? 'revoke_failed' : 'disconnected' );
		do_action( 'ajcore_reviews_content_changed' );
		return is_wp_error( $result ) ? new WP_Error( 'revoke_failed' ) : true;
	}

	public static function featured( $limit = 6, $order = 'manual' ) {
		$data = self::snapshot(); $selection = (array) get_option( 'ajcore_reviews_selection', array() ); $items = array();
		foreach ( $data['reviews'] ?? array() as $key => $review ) {
			if ( ! isset( $selection[$key] ) || ! self::valid_review( $review ) ) { continue; }
			// Explicit DTO allowlist protects presentation layers from accidental provider additions.
			$review = array_intersect_key( $review, array_flip( array( 'id', 'key', 'kind', 'name', 'avatar', 'profile_url', 'rating', 'text', 'language', 'translated_text', 'translation_status', 'date', 'updated_at', 'relative_date', 'source_url', 'report_url', 'location', 'retrieved_at', 'expires_at' ) ) );
			$review['order'] = (int) $selection[$key]; $items[] = $review;
		}
		usort( $items, function( $a, $b ) use ( $order ) {
			$comparison = $order === 'date' ? strtotime( $b['date'] ) <=> strtotime( $a['date'] ) : $a['order'] <=> $b['order'];
			return $comparison ?: strcmp( $a['key'], $b['key'] );
		} );
		return array_slice( $items, 0, max( 1, min( 50, (int) $limit ) ) );
	}

	public static function valid_review( $r ) {
		if ( ! is_array( $r ) || ( $r['kind'] ?? '' ) !== 'google' || ! is_string( $r['id'] ?? null ) || ! is_string( $r['key'] ?? null ) || ! is_string( $r['location'] ?? null ) || ! is_int( $r['rating'] ?? null ) || $r['rating'] < 1 || $r['rating'] > 5 || ! is_int( $r['retrieved_at'] ?? null ) || ! is_int( $r['expires_at'] ?? null ) ) { return false; }
		foreach ( array( 'name', 'text', 'date', 'avatar', 'profile_url', 'source_url', 'report_url', 'language', 'translated_text', 'translation_status', 'relative_date', 'updated_at' ) as $field ) {
			if ( ! is_string( $r[$field] ?? null ) || strlen( $r[$field] ) > 100000 ) { return false; }
		}
		return $r['key'] === hash( 'sha256', $r['location'] . '/' . $r['id'] ) && strtotime( $r['date'] ) !== false && $r['retrieved_at'] <= time() && $r['expires_at'] > time() && $r['expires_at'] <= $r['retrieved_at'] + self::TTL && $r['location'] === ( self::config()['location'] ?? '' );
	}

	/** Administrative selection; HTTP callers must additionally validate their request nonce. Caller holds lock. */
	public static function set_featured( $key, $featured, $order = 0 ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'access_denied' ); }
		if ( ! self::owns_lock() ) { return new WP_Error( 'busy' ); }
		$data = self::snapshot();
		if ( ! isset( $data['reviews'][$key] ) || ! self::valid_review( $data['reviews'][$key] ) ) { return new WP_Error( 'invalid_data' ); }
		$selection = (array) get_option( 'ajcore_reviews_selection', array() );
		if ( $featured ) { $selection[$key] = max( -10000, min( 10000, (int) $order ) ); } else { unset( $selection[$key] ); }
		update_option( 'ajcore_reviews_selection', $selection, false );
		do_action( 'ajcore_reviews_content_changed' );
		return true;
	}

	public static function status() {
		$meta = (array) get_option( 'ajcore_reviews_sync_meta', array() ); $data = self::snapshot();
		$count = count( array_filter( $data['reviews'] ?? array(), array( __CLASS__, 'valid_review' ) ) );
		$state = ! self::connected() ? 'disconnected' : ( ! $data ? ( empty( $meta['last_success'] ) ? 'no_reviews' : 'expired' ) : ( $count === 0 ? 'no_reviews' : ( self::featured( 1 ) ? 'ready' : 'no_featured' ) ) );
		return array( 'state' => $state, 'valid_count' => $count, 'last_success' => (int) ( $meta['last_success'] ?? 0 ), 'expires_at' => (int) ( $data['expires_at'] ?? 0 ), 'stale' => ! empty( $meta['last_error'] ) || ( ! empty( $meta['last_success'] ) && time() - $meta['last_success'] >= self::INTERVAL ) );
	}
}
