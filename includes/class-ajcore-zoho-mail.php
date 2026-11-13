<?php
/**
 * Zoho Mail shared mailbox (OAuth connection, live read-only view) in AJCore.
 *
 * Uses Zoho's Shared Mailbox API, /api/v2/sharedmailbox/{address}/..., which is addressed by the mailbox's email
 * address: no organization id or group id, and no Groups scope. Only the Shared Mailbox Address and a Zoho login that is a
 * member of that mailbox are needed. Message bodies come from the login's own account (the real folderId the shared API
 * reports). Same REST URLs as before (/zoho-mail/oauth/callback, /zoho-mail/test, /zoho-mail/diagnose, /ops/inbox);
 * /ops/inbox "folders" are now the mailbox views (all, unread, unassigned, assigned, archived).
 *
 * Settings: option `ajcore_ra_zoho_mail` (name kept; client secret and tokens encrypted). Scopes changed, so an existing
 * connection must be reconnected once.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Zoho_Mail {

	const OPTION = 'ajcore_ra_zoho_mail';
	const TAB    = 'pipeline'; // AJCore_Pipeline_Tab owns the tab; this class draws its panels

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init() {
		self::seed_settings();
		add_action( 'rest_api_init', array( __CLASS__, 'boot_routes' ) );
		add_filter( 'ajcore_endpoint_catalog', array( __CLASS__, 'catalog' ) );
		add_action( 'admin_post_ajcore_ra_save_zoho_mail', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ajcore_ra_zoho_mail_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	public static function boot_routes() {
		self::instance()->register_routes();
	}

	public static function handle_save() {
		self::instance()->save_from_post();
	}

	public static function handle_disconnect() {
		self::instance()->disconnect();
	}

	public static function tab_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB, 'src' => 'zoho' ), $args ), admin_url( 'admin.php' ) );
	}

	// ── settings ────────────────────────────────────────────────────────────────

	private static function defaults() {
		return array(
			'zoho_mail_client_id'        => '',
			'zoho_mail_client_secret'    => '',
			'zoho_mail_account_email'    => '',
			'zoho_mail_mailboxes'        => '',
			'zoho_mail_filter_text'      => (string) apply_filters( 'ajcore_zoho_mail_default_filter', '' ),
			'zoho_mail_filter_subject'   => '',
			'zoho_mail_filter_sender'    => '',
			'zoho_mail_filter_match'     => 'all',
			'zoho_mail_data_center'      => 'com',
			'zoho_mail_access_token'     => '',
			'zoho_mail_refresh_token'    => '',
			'zoho_mail_token_expires_at' => 0,
			'zoho_mail_account_id'       => '',
			'zoho_mail_connected_email'  => '',
			'zoho_mail_connected_at'     => '',
		);
	}

	private static function secret_keys() {
		return array( 'zoho_mail_client_secret', 'zoho_mail_access_token', 'zoho_mail_refresh_token' );
	}

	private static function encrypt( $value ) {
		return function_exists( 'ajcore_encrypt_setting_value' ) ? ajcore_encrypt_setting_value( (string) $value ) : (string) $value;
	}

	private static function decrypt( $value ) {
		return function_exists( 'ajcore_decrypt_setting_value' ) ? (string) ajcore_decrypt_setting_value( (string) $value ) : (string) $value;
	}

	public function get_settings() {
		$opt      = get_option( self::OPTION, array() );
		$opt      = is_array( $opt ) ? $opt : array();
		$settings = array();
		foreach ( self::defaults() as $key => $default ) {
			$value            = isset( $opt[ $key ] ) ? $opt[ $key ] : $default;
			$settings[ $key ] = in_array( $key, self::secret_keys(), true ) ? trim( self::decrypt( (string) $value ) ) : $value;
		}
		return $settings;
	}

	private function store( array $settings ) {
		$opt = array( 'seeded' => 1 );
		foreach ( self::defaults() as $key => $default ) {
			$value       = isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
			$opt[ $key ] = in_array( $key, self::secret_keys(), true ) ? self::encrypt( (string) $value ) : $value;
		}
		update_option( self::OPTION, $opt, false );
	}

	/** One-time copy of AJCore's old zoho_mail_* keys (client id/secret, tokens, mailbox). */
	private static function seed_settings() {
		$opt = get_option( self::OPTION, null );
		if ( is_array( $opt ) && ! empty( $opt['seeded'] ) ) {
			return;
		}
		$found = array();
		if ( function_exists( 'ajforms_get_settings' ) ) {
			$legacy = ajforms_get_settings();
			foreach ( self::defaults() as $key => $default ) {
				if ( isset( $legacy[ $key ] ) && '' !== (string) $legacy[ $key ] && (string) $legacy[ $key ] !== (string) $default ) {
					$found[ $key ] = $legacy[ $key ];
				}
			}
		}
		self::instance()->store( array_merge( self::defaults(), $found ) );
	}

	private function save_from_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore' ) );
		}
		check_admin_referer( 'ajcore_ra_save_zoho_mail' );
		$settings = $this->get_settings();
		foreach ( array( 'zoho_mail_client_id' ) as $field ) {
			$settings[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$settings['zoho_mail_mailboxes']     = isset( $_POST['zoho_mail_mailboxes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['zoho_mail_mailboxes'] ) ) : '';
		$settings['zoho_mail_account_email'] = isset( $_POST['zoho_mail_account_email'] ) ? sanitize_email( wp_unslash( $_POST['zoho_mail_account_email'] ) ) : '';
		$dc                                  = isset( $_POST['zoho_mail_data_center'] ) ? sanitize_key( wp_unslash( $_POST['zoho_mail_data_center'] ) ) : 'com';
		$settings['zoho_mail_data_center']   = in_array( $dc, array( 'com', 'eu', 'in', 'com.au', 'jp' ), true ) ? $dc : 'com';
		if ( ! AJCore_Pipeline::instance()->filter_managed( 'zoho' ) ) { // when AJCore-RA defines the filter, the saved fields are left untouched
			$settings['zoho_mail_filter_match'] = ( isset( $_POST['zoho_mail_filter_match'] ) && 'any' === $_POST['zoho_mail_filter_match'] ) ? 'any' : 'all';
			foreach ( array( 'zoho_mail_filter_text', 'zoho_mail_filter_subject', 'zoho_mail_filter_sender' ) as $filter_key ) {
				$settings[ $filter_key ] = isset( $_POST[ $filter_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $filter_key ] ) ) : '';
			}
		}
		$secret                              = isset( $_POST['zoho_mail_client_secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['zoho_mail_client_secret'] ) ) ) : '';
		if ( '' !== $secret ) { // blank keeps the saved secret
			$settings['zoho_mail_client_secret'] = $secret;
		}
		$this->store( $settings );
		AJCore_Pipeline::instance()->purge_pending_for_source( 'zoho' ); // re-read the pending queue under the saved filters on the next pass
		wp_safe_redirect( self::tab_url( array( 'saved' => 1 ) ) );
		exit;
	}

	private function disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajcore' ) );
		}
		check_admin_referer( 'ajcore_zoho_mail_disconnect' );
		$settings = $this->get_settings();
		foreach ( array( 'zoho_mail_access_token', 'zoho_mail_refresh_token', 'zoho_mail_account_id', 'zoho_mail_connected_email', 'zoho_mail_connected_at' ) as $field ) {
			$settings[ $field ] = '';
		}
		$settings['zoho_mail_token_expires_at'] = 0;
		$this->store( $settings );
		wp_safe_redirect( self::tab_url( array( 'zoho-mail-disconnected' => 'true' ) ) );
		exit;
	}

	// ── REST ────────────────────────────────────────────────────────────────────

	public function register_routes() {
		$ns = AJCore_Extensions::rest_namespace();
		// Zoho's redirect back carries none of WordPress's REST auth headers, so WP treats it as logged out;
		// security is the handler's own state-token check (a random value stashed in a transient on Connect).
		register_rest_route( $ns, '/zoho-mail/oauth/callback', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'oauth_callback' ), 'permission_callback' => '__return_true' ) );
		// Called with fetch() from the tab itself (real X-WP-Nonce), so cookie auth works.
		register_rest_route( $ns, '/zoho-mail/test', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'test_connection' ), 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
		register_rest_route( $ns, '/zoho-mail/diagnose', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'diagnose_route' ), 'permission_callback' => function () { return current_user_can( 'manage_options' ); } ) );
		register_rest_route( $ns, '/ops/inbox', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_inbox' ), 'permission_callback' => array( 'AJCore_Extensions', 'can_manage_ops' ) ) );
	}

	public static function catalog( $catalog ) {
		$catalog[] = array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/inbox', 'auth' => 'Admin', 'purpose' => 'Shared Zoho Mailbox, read live through the Shared Mailbox API (folder_id = view: all|unread|unassigned|assigned|archived; start, limit). Not connected returns empty lists.', 'app' => 'OPS inbox' );
		$catalog[] = array( 'surface' => 'System', 'method' => 'GET', 'path' => '/zoho-mail/oauth/callback', 'auth' => 'State token', 'purpose' => 'Browser redirect target after the Zoho Mail consent screen.', 'app' => 'WP admin' );
		$catalog[] = array( 'surface' => 'System', 'method' => 'GET', 'path' => '/zoho-mail/test', 'auth' => 'Admin', 'purpose' => 'Connection test for the Zoho Mail tab.', 'app' => 'WP admin' );
		$catalog[] = array( 'surface' => 'System', 'method' => 'GET', 'path' => '/zoho-mail/diagnose', 'auth' => 'Admin', 'purpose' => 'Lists the shared mailboxes the connected login can read, with counts.', 'app' => 'WP admin' );
		return $catalog;
	}

	public function get_ops_inbox( WP_REST_Request $request ) {
		$settings = $this->get_settings();
		if ( empty( $settings['zoho_mail_refresh_token'] ) ) {
			return rest_ensure_response( array( 'connected' => false, 'folders' => array(), 'messages' => array() ) );
		}
		// "folders" are the shared mailbox's views (all, unread, unassigned, ...): same shape AJOps already reads.
		$views = $this->get_views();
		if ( is_wp_error( $views ) ) {
			return new WP_Error( $views->get_error_code(), $views->get_error_message(), array( 'status' => 502 ) );
		}
		$view     = sanitize_key( (string) $request->get_param( 'folder_id' ) );
		$view     = in_array( $view, array_column( $views, 'folder_id' ), true ) ? $view : 'all';
		$limit    = min( 200, max( 1, absint( $request->get_param( 'limit' ) ?: 50 ) ) );
		$messages = $this->get_recent_messages( $limit, $view, absint( $request->get_param( 'start' ) ) );
		if ( is_wp_error( $messages ) ) {
			return new WP_Error( $messages->get_error_code(), $messages->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'connected' => true, 'connected_email' => (string) $settings['zoho_mail_connected_email'], 'folders' => $views, 'messages' => $messages ) );
	}

	/** Browser redirect from Zoho after the admin approves (or denies) the consent screen. */
	public function oauth_callback( WP_REST_Request $request ) {
		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );
		if ( '' !== $error ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => $error ) ) );
			exit;
		}
		$state          = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$expected_state = get_transient( 'ajcore_zoho_mail_oauth_state' );
		delete_transient( 'ajcore_zoho_mail_oauth_state' );
		if ( '' === $state || ! $expected_state || ! hash_equals( (string) $expected_state, $state ) ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => __( 'Could not verify this request (expired link) — click Connect Zoho Mail again.', 'ajcore' ) ) ) );
			exit;
		}
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( '' === $code ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => __( 'Zoho did not return an authorization code.', 'ajcore' ) ) ) );
			exit;
		}
		$result = $this->complete_oauth_connection( $code );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::tab_url( array( 'zoho-mail-error' => $result->get_error_message() ) ) );
			exit;
		}
		wp_safe_redirect( self::tab_url( array( 'zoho-mail-connected' => 'true' ) ) );
		exit;
	}

	public function test_connection( WP_REST_Request $request ) {
		$views = $this->get_views();
		if ( is_wp_error( $views ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $views->get_error_message() ) );
		}
		$messages = $this->get_recent_messages( 5 );
		if ( is_wp_error( $messages ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $messages->get_error_message() ) );
		}
		return rest_ensure_response( array(
			'success'         => true,
			'folders'         => wp_list_pluck( $views, 'name' ),
			'recent_messages' => array_map( function ( $m ) {
				$from = '' !== $m['from_name'] ? $m['from_name'] : $m['from_email'];
				return trim( $m['subject'] . ( '' !== $from ? ' — ' . $from : '' ) );
			}, $messages ),
		) );
	}

	/** Lists the shared mailboxes this Zoho login can read (with counts), so the right address can be copied into settings. */
	public function diagnose_route( WP_REST_Request $request ) {
		$boxes = $this->list_shared_mailboxes();
		if ( is_wp_error( $boxes ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => $boxes->get_error_message() ) );
		}
		return rest_ensure_response( array( 'success' => true, 'mailboxes' => $boxes ) );
	}

	// ── Zoho OAuth ──────────────────────────────────────────────────────────────

	/** The data-center-specific accounts host Zoho token/authorize endpoints live on. */
	private function accounts_host( $data_center ) {
		$dc = in_array( $data_center, array( 'com', 'eu', 'in', 'com.au', 'jp' ), true ) ? $data_center : 'com';
		return 'accounts.zoho.' . $dc;
	}

	/** The data-center-specific Mail API host (only com/eu/in currently have mail.zoho.{dc}). */
	private function api_host( $data_center ) {
		$dc = in_array( $data_center, array( 'com', 'eu', 'in' ), true ) ? $data_center : 'com';
		return 'mail.zoho.' . $dc;
	}

	/**
	 * Exchanges an authorization code for tokens, then proves the shared mailbox is readable: Zoho's Shared Mailbox API
	 * (/api/v2/sharedmailbox/{address}/...) is addressed by the mailbox's email address, so no organization or group id is
	 * needed. The login's own accountId is kept for fetching message bodies (they live behind the regular folder endpoints).
	 */
	private function complete_oauth_connection( $code ) {
		$settings      = $this->get_settings();
		$client_id     = trim( (string) $settings['zoho_mail_client_id'] );
		$client_secret = trim( (string) $settings['zoho_mail_client_secret'] );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'zoho_mail_not_configured', __( 'Zoho Mail Client ID/Secret are not saved.', 'ajcore' ) );
		}
		$wanted = $this->shared_address( $settings );
		if ( '' === $wanted ) {
			return new WP_Error( 'zoho_mail_no_address', __( 'Save the Shared Mailbox Address first, then connect.', 'ajcore' ) );
		}
		$token_response = wp_remote_post( 'https://' . $this->accounts_host( $settings['zoho_mail_data_center'] ) . '/oauth/v2/token', array(
			'timeout' => 20,
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => rest_url( 'ajcore/v1/zoho-mail/oauth/callback' ),
				'code'          => sanitize_text_field( (string) $code ),
			),
		) );
		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}
		$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( empty( $token_data['access_token'] ) ) {
			return new WP_Error( 'zoho_mail_token_exchange_failed', ! empty( $token_data['error'] ) ? (string) $token_data['error'] : __( 'Zoho did not return an access token.', 'ajcore' ) );
		}
		$access_token  = sanitize_text_field( (string) $token_data['access_token'] );
		$refresh_token = ! empty( $token_data['refresh_token'] ) ? sanitize_text_field( (string) $token_data['refresh_token'] ) : trim( (string) $settings['zoho_mail_refresh_token'] );
		$expires_in    = ! empty( $token_data['expires_in'] ) ? absint( $token_data['expires_in'] ) : 3600;

		$settings['zoho_mail_access_token']     = $access_token;
		$settings['zoho_mail_refresh_token']    = $refresh_token;
		$settings['zoho_mail_token_expires_at'] = time() + $expires_in;

		// The login's own account (id for message bodies) and the group send-as addresses it can see.
		$accounts = $this->api_request( '/accounts', array(), 'GET', $access_token, $settings );
		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}
		$first = isset( $accounts['data']['data'][0] ) && is_array( $accounts['data']['data'][0] ) ? $accounts['data']['data'][0] : array();
		if ( 200 !== $accounts['status'] || empty( $first['accountId'] ) ) {
			return new WP_Error( 'zoho_mail_accounts_failed', sprintf( __( 'Could not read this Zoho login\'s account — GET /api/accounts returned HTTP %1$d: %2$s', 'ajcore' ), $accounts['status'], substr( $accounts['raw'], 0, 600 ) ) );
		}

		$probe = $this->api_request( '/v2/sharedmailbox/' . $this->path_address( $wanted ) . '/counts', array(), 'GET', $access_token, $settings );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}
		if ( 200 !== $probe['status'] ) {
			$seen = array();
			foreach ( $this->group_addresses( $first ) as $address => $name ) {
				$seen[] = $address;
			}
			return new WP_Error( 'zoho_mail_shared_mailbox_unreadable', sprintf(
				__( 'This Zoho login cannot read the shared mailbox %1$s (Shared Mailbox API returned HTTP %2$d: %3$s). Shared-mailbox addresses this login has: %4$s. Sign in with a Zoho user that is a member of the shared mailbox, or fix the Shared Mailbox Address.', 'ajcore' ),
				$wanted,
				$probe['status'],
				substr( $probe['raw'], 0, 300 ),
				$seen ? implode( ', ', $seen ) : __( 'none', 'ajcore' )
			) );
		}

		$settings['zoho_mail_account_id']      = sanitize_text_field( (string) $first['accountId'] );
		$settings['zoho_mail_connected_email'] = $wanted;
		$settings['zoho_mail_connected_at']    = current_time( 'mysql' );
		$this->store( $settings );
		return true;
	}

	/** A usable access token, refreshed through the stored refresh token when expired (or about to be). */
	private function get_valid_access_token() {
		$settings      = $this->get_settings();
		$access_token  = trim( (string) $settings['zoho_mail_access_token'] );
		$refresh_token = trim( (string) $settings['zoho_mail_refresh_token'] );
		$expires_at    = absint( $settings['zoho_mail_token_expires_at'] );
		if ( '' === $refresh_token ) {
			return new WP_Error( 'zoho_mail_not_connected', __( 'Zoho Mail is not connected.', 'ajcore' ) );
		}
		if ( '' !== $access_token && $expires_at > time() + 60 ) {
			return $access_token;
		}
		$client_id     = trim( (string) $settings['zoho_mail_client_id'] );
		$client_secret = trim( (string) $settings['zoho_mail_client_secret'] );
		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'zoho_mail_not_configured', __( 'Zoho Mail Client ID/Secret are not saved.', 'ajcore' ) );
		}
		$response = wp_remote_post( 'https://' . $this->accounts_host( $settings['zoho_mail_data_center'] ) . '/oauth/v2/token', array(
			'timeout' => 20,
			'body'    => array( 'grant_type' => 'refresh_token', 'client_id' => $client_id, 'client_secret' => $client_secret, 'refresh_token' => $refresh_token ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'zoho_mail_refresh_failed', ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Zoho did not return a refreshed access token.', 'ajcore' ) );
		}
		$settings['zoho_mail_access_token']     = sanitize_text_field( (string) $data['access_token'] );
		$settings['zoho_mail_token_expires_at'] = time() + ( ! empty( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 3600 );
		$this->store( $settings );
		return $settings['zoho_mail_access_token'];
	}

	// ── Shared Mailbox API ──────────────────────────────────────────────────────

	private function shared_address( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->get_settings();
		return strtolower( trim( (string) $settings['zoho_mail_account_email'] ) );
	}

	/** An email address as a URL path segment (Zoho takes the literal "@"). */
	private function path_address( $address ) {
		return str_replace( '%40', '@', rawurlencode( (string) $address ) );
	}

	/**
	 * Authenticated request to Zoho Mail. $token / $settings are passed only while connecting (the token is not stored yet);
	 * otherwise the stored, auto-refreshed token is used. Returns array( status, data (decoded JSON), raw ), or WP_Error on
	 * transport failure. The last response is kept 15 minutes for the debug panel.
	 */
	private function api_request( $path, $query = array(), $method = 'GET', $token = null, $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->get_settings();
		if ( null === $token ) {
			$token = $this->get_valid_access_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
		}
		$url = 'https://' . $this->api_host( $settings['zoho_mail_data_center'] ) . '/api' . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}
		$response = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 20, 'headers' => array( 'Authorization' => 'Zoho-oauthtoken ' . $token ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		set_transient( 'ajcore_zoho_mail_api_debug', array( 'url' => $url, 'status' => $status, 'body' => $raw, 'at' => current_time( 'mysql' ) ), 15 * MINUTE_IN_SECONDS );
		return array( 'status' => $status, 'data' => is_array( $data ) ? $data : array(), 'raw' => $raw );
	}

	/** GET against the configured shared mailbox; WP_Error unless HTTP 200. */
	private function shared_get( $path, $query = array(), $address = '' ) {
		$address = '' !== (string) $address ? strtolower( trim( (string) $address ) ) : $this->shared_address();
		if ( '' === $address || empty( $this->get_settings()['zoho_mail_refresh_token'] ) ) {
			return new WP_Error( 'zoho_mail_not_connected', __( 'Zoho Mail is not connected.', 'ajcore' ) );
		}
		$res = $this->api_request( '/v2/sharedmailbox/' . $this->path_address( $address ) . $path, $query );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['status'] ) {
			return new WP_Error( 'zoho_mail_api_error', sprintf( __( 'Zoho Shared Mailbox API returned HTTP %1$d: %2$s', 'ajcore' ), $res['status'], substr( $res['raw'], 0, 300 ) ) );
		}
		return $res['data'];
	}

	/** The group ("mode":"group") send-as addresses in a /api/accounts entry, address => display name. */
	private function group_addresses( array $account ) {
		$out = array();
		foreach ( (array) ( isset( $account['sendMailDetails'] ) ? $account['sendMailDetails'] : array() ) as $entry ) {
			if ( ! is_array( $entry ) || 'group' !== ( isset( $entry['mode'] ) ? $entry['mode'] : '' ) || empty( $entry['fromAddress'] ) ) {
				continue;
			}
			$address = strtolower( (string) $entry['fromAddress'] );
			if ( ! isset( $out[ $address ] ) ) {
				$out[ $address ] = isset( $entry['displayName'] ) ? (string) $entry['displayName'] : $address;
			}
		}
		return $out;
	}

	/**
	 * Shared mailboxes this login can read. There is no list endpoint: the candidates are the login's group send-as
	 * addresses, each probed with /counts (only HTTP 200 ones are real, readable shared mailboxes). Aliases of one
	 * mailbox share a display name, so only the first address that answers is kept per name.
	 */
	public function list_shared_mailboxes() {
		$accounts = $this->api_request( '/accounts' );
		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}
		$first = isset( $accounts['data']['data'][0] ) && is_array( $accounts['data']['data'][0] ) ? $accounts['data']['data'][0] : array();
		if ( 200 !== $accounts['status'] || empty( $first ) ) {
			return new WP_Error( 'zoho_mail_accounts_failed', sprintf( __( 'GET /api/accounts returned HTTP %1$d: %2$s', 'ajcore' ), $accounts['status'], substr( $accounts['raw'], 0, 300 ) ) );
		}
		$by_name = array();
		foreach ( $this->group_addresses( $first ) as $address => $name ) {
			$by_name[ $name ][] = $address;
		}
		$boxes = array();
		foreach ( $by_name as $name => $addresses ) {
			foreach ( $addresses as $address ) {
				$res = $this->api_request( '/v2/sharedmailbox/' . $this->path_address( $address ) . '/counts' );
				if ( is_wp_error( $res ) || 200 !== $res['status'] ) {
					continue;
				}
				$c       = isset( $res['data']['data'] ) && is_array( $res['data']['data'] ) ? $res['data']['data'] : array();
				$boxes[] = array(
					'address'    => $address,
					'name'       => $name,
					'total'      => absint( isset( $c['total'] ) ? $c['total'] : 0 ),
					'unread'     => absint( isset( $c['unread'] ) ? $c['unread'] : 0 ),
					'assigned'   => absint( isset( $c['assigned'] ) ? $c['assigned'] : 0 ),
					'unassigned' => absint( isset( $c['unassigned'] ) ? $c['unassigned'] : 0 ),
				);
				break;
			}
		}
		return $boxes;
	}

	/** The mailbox's views as "folders" (all, unread, unassigned, assigned, archived); unread_count carries the useful number. */
	public function get_views() {
		$data = $this->shared_get( '/counts' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$c = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		$n = static function ( $key ) use ( $c ) {
			return absint( isset( $c[ $key ] ) ? $c[ $key ] : 0 );
		};
		return array(
			array( 'folder_id' => 'all', 'name' => __( 'All', 'ajcore' ), 'unread_count' => 0 ),
			array( 'folder_id' => 'unread', 'name' => __( 'Unread', 'ajcore' ), 'unread_count' => $n( 'unread' ) ),
			array( 'folder_id' => 'unassigned', 'name' => __( 'Unassigned', 'ajcore' ), 'unread_count' => $n( 'unassigned' ) ),
			array( 'folder_id' => 'assigned', 'name' => __( 'Assigned', 'ajcore' ), 'unread_count' => $n( 'assigned' ) ),
			array( 'folder_id' => 'archived', 'name' => __( 'Archived', 'ajcore' ), 'unread_count' => 0 ),
		);
	}

	/** Breadth-first lookup of a key in nested arrays: the shared API's responses nest fields in sub-objects. */
	private static function deep_find( $node, $key ) {
		$queue = array( $node );
		while ( $queue ) {
			$cur = array_shift( $queue );
			if ( ! is_array( $cur ) ) {
				continue;
			}
			if ( array_key_exists( $key, $cur ) && null !== $cur[ $key ] ) {
				return $cur[ $key ];
			}
			foreach ( $cur as $v ) {
				if ( is_array( $v ) ) {
					$queue[] = $v;
				}
			}
		}
		return null;
	}

	private static function deep_string( $node, array $keys ) {
		foreach ( $keys as $key ) {
			$v = self::deep_find( $node, $key );
			if ( is_string( $v ) && '' !== $v ) {
				return html_entity_decode( $v, ENT_QUOTES, 'UTF-8' );
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				return (string) $v;
			}
		}
		return '';
	}

	/** Messages in a view (all, unread, unassigned, assigned, archived, spam, trash), newest first, paged by $start. $address = another shared mailbox (default: the configured one). */
	public function get_recent_messages( $limit = 10, $view = 'all', $start = 0, $address = '' ) {
		$view = in_array( $view, array( 'all', 'unread', 'unassigned', 'assigned', 'archived', 'spam', 'trash' ), true ) ? $view : 'all';
		$data = $this->shared_get( '/messages/' . $view, array( 'start' => absint( $start ), 'limit' => max( 1, absint( $limit ) ) ), $address );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = isset( $data['data']['messages'] ) && is_array( $data['data']['messages'] ) ? $data['data']['messages'] : array();
		return $this->normalize_message_rows( $rows, $view );
	}

	/** Shared Mailbox list rows => the plain message shape used everywhere (message_id, subject, from_*, summary, received_at...). */
	private function normalize_message_rows( array $rows, $folder ) {
		$messages = array();
		foreach ( $rows as $row ) {
			$id = self::deep_string( $row, array( 'messageId' ) );
			if ( '' === $id ) {
				continue;
			}
			$unread     = self::deep_find( $row, 'unread' );
			$messages[] = array(
				'message_id'     => $id,
				'folder_id'      => $folder,
				'subject'        => self::deep_string( $row, array( 'title', 'subject' ) ) ?: __( '(no subject)', 'ajcore' ),
				'from_name'      => self::deep_string( $row, array( 'senderName' ) ),
				'from_email'     => self::deep_string( $row, array( 'fromAddress' ) ),
				'summary'        => self::deep_string( $row, array( 'summary' ) ),
				'received_at'    => $this->timestamp_to_mysql( self::deep_find( $row, 'createdAt' ) ),
				'is_unread'      => true === $unread || 'true' === $unread || 1 === $unread || '1' === $unread,
				'has_attachment' => true === self::deep_find( $row, 'hasAttachments' ) || '1' === (string) self::deep_find( $row, 'hasAttachments' ),
			);
		}
		return $messages;
	}

	/**
	 * Labels (Zoho calls them tags) of a shared mailbox: array of [ id, name ]. The response layout isn't pinned down by the docs, so
	 * both data[] and data.tags[] are accepted.
	 */
	public function list_labels( $address = '' ) {
		$data = $this->shared_get( '/tags', array(), $address );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = isset( $data['data']['tags'] ) && is_array( $data['data']['tags'] ) ? $data['data']['tags'] : ( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id   = self::deep_string( $row, array( 'tagId', 'id' ) );
			$name = self::deep_string( $row, array( 'tagName', 'name', 'displayName' ) );
			if ( '' !== $id ) {
				$out[] = array( 'id' => $id, 'name' => '' !== $name ? $name : $id );
			}
		}
		return $out;
	}

	/** Messages that carry a label, newest first as returned, paged by $start. Same row shape as get_recent_messages(). */
	public function get_messages_by_label( $tag_id, $address = '', $start = 0, $limit = 50 ) {
		$data = $this->shared_get( '/tags/' . rawurlencode( (string) $tag_id ) . '/messages', array( 'start' => absint( $start ), 'limit' => max( 1, absint( $limit ) ) ), $address );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = isset( $data['data']['messages'] ) && is_array( $data['data']['messages'] ) ? $data['data']['messages'] : ( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() );
		return $this->normalize_message_rows( array_values( array_filter( $rows, 'is_array' ) ), 'label:' . $tag_id );
	}

	/** The named shared mailboxes configured in settings (one per line: "address | purpose"), as [ address, purpose ] rows. */
	public function mailboxes() {
		$s   = $this->get_settings();
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['zoho_mail_mailboxes'] ) as $line ) {
			$parts   = array_map( 'trim', explode( '|', $line, 2 ) );
			$address = strtolower( sanitize_email( $parts[0] ) );
			if ( '' !== $address ) {
				$out[ $address ] = array( 'address' => $address, 'purpose' => isset( $parts[1] ) ? $parts[1] : '' );
			}
		}
		return array_values( $out );
	}

	/** One message's details from the Shared Mailbox API (a summary plus the real folderId; no body). */
	public function get_message( $message_id, $address = '' ) {
		$data = $this->shared_get( '/messages/' . rawurlencode( (string) $message_id ), array(), $address );
		return is_wp_error( $data ) ? $data : ( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() );
	}

	/**
	 * The message body (HTML). The Shared Mailbox API returns only a summary, so the body comes from the login's regular
	 * content endpoint using the real folderId that response reports; the summary is the fallback.
	 */
	public function get_message_body( $message_id, $address = '' ) {
		$details = $this->get_message( $message_id, $address );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$summary    = self::deep_string( $details, array( 'summary', 'title' ) );
		$folder_id  = self::deep_string( $details, array( 'folderId' ) );
		$account_id = trim( (string) $this->get_settings()['zoho_mail_account_id'] );
		if ( '' !== $folder_id && '' !== $account_id ) {
			$res = $this->api_request( '/accounts/' . rawurlencode( $account_id ) . '/folders/' . rawurlencode( $folder_id ) . '/messages/' . rawurlencode( (string) $message_id ) . '/content' );
			if ( ! is_wp_error( $res ) && 200 === $res['status'] ) {
				$content = self::deep_string( $res['data'], array( 'content' ) );
				if ( '' !== $content ) {
					return $content;
				}
			}
		}
		return '<pre style="white-space:pre-wrap;font-family:inherit">' . esc_html( $summary ) . '</pre>';
	}

	// ── Intake pipeline adapter (same contract as the Gmail source) ─────────────

	public function intake_filter_text() {
		return trim( (string) $this->get_settings()['zoho_mail_filter_text'] );
	}

	/** Lowercase comma-separated terms from a setting. */
	private function terms( $text ) {
		return array_map( 'strtolower', AJCore_Pipeline::split_terms( $text ) );
	}

	/** The filter in use: [ subject, sender, any, match ] (from AJCore-RA when it defines one, else the saved settings). */
	public function intake_filter_terms() {
		$f = AJCore_Pipeline::instance()->effective_filter( 'zoho' );
		return array( 'subject' => $f['subject'], 'sender' => $f['sender'], 'any' => $f['any'], 'match' => $f['match'] );
	}

	/** The filter in use, in words ('' = none, i.e. every message). */
	public function intake_filter_summary() {
		$f     = $this->intake_filter_terms();
		$parts = array();
		if ( $f['subject'] ) {
			$parts[] = sprintf( __( 'subject has any of: %s', 'ajcore' ), implode( ', ', $f['subject'] ) );
		}
		if ( $f['sender'] ) {
			$parts[] = sprintf( __( 'sender has any of: %s', 'ajcore' ), implode( ', ', $f['sender'] ) );
		}
		if ( $f['any'] ) {
			$parts[] = sprintf( __( 'subject, sender or preview has any of: %s', 'ajcore' ), implode( ', ', $f['any'] ) );
		}
		return implode( 'any' === $f['match'] ? ' OR ' : ' AND ', $parts );
	}

	/** Human description of the mailbox filters, for the "Filters & rules" panel. */
	public function intake_filter_description() {
		$summary = $this->intake_filter_summary();
		return '' !== $summary
			? sprintf( __( 'Shared mailbox %1$s: newest messages where %2$s', 'ajcore' ), $this->shared_address(), $summary )
			: sprintf( __( 'Shared mailbox %s: every message', 'ajcore' ), $this->shared_address() );
	}

	/** Whether a listed message passes the filters: each filled field is a hit if any of its terms match; "all" needs every filled field to hit, "any" just one. */
	private function passes_filters( array $row, array $subject_terms, array $sender_terms, array $any_terms, $match = 'all' ) {
		$subject = strtolower( $row['subject'] );
		$sender  = strtolower( $row['from_name'] . ' ' . $row['from_email'] );
		$all     = $subject . ' ' . $sender . ' ' . strtolower( $row['summary'] );
		$hits    = array();
		foreach ( array( array( $subject_terms, $subject ), array( $sender_terms, $sender ), array( $any_terms, $all ) ) as $field ) {
			if ( ! $field[0] ) {
				continue; // blank field: no condition
			}
			$hit = false;
			foreach ( $field[0] as $term ) {
				if ( false !== strpos( $field[1], $term ) ) {
					$hit = true;
					break;
				}
			}
			$hits[] = $hit;
		}
		if ( ! $hits ) {
			return true;
		}
		return 'any' === $match ? in_array( true, $hits, true ) : ! in_array( false, $hits, true );
	}

	/**
	 * Native ids of the newest messages that pass the filter (newest first, at most $max). Scans up to 200 recent
	 * messages in pages of 50 because the Shared Mailbox API has no search; $show_all skips the text filter.
	 * Returns array( 'ids' => string[], 'query' => string, 'estimate' => int ) or WP_Error.
	 */
	public function intake_ids( $max, $show_all = false ) {
		$ft            = $this->intake_filter_terms();
		$subject_terms = $show_all ? array() : array_map( 'strtolower', $ft['subject'] );
		$sender_terms  = $show_all ? array() : array_map( 'strtolower', $ft['sender'] );
		$any_terms     = $show_all ? array() : array_map( 'strtolower', $ft['any'] );
		$max    = max( 1, min( 50, (int) $max ) );
		// The Shared Mailbox API does not return messages in date order, so read up to 200, then sort newest first.
		$hits = array();
		for ( $page = 0; $page < 4; $page++ ) {
			$rows = $this->get_recent_messages( 50, 'all', $page * 50 );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $row ) {
				$hit = $this->passes_filters( $row, $subject_terms, $sender_terms, $any_terms, $ft['match'] );
				if ( $hit ) {
					$hits[ $row['message_id'] ] = $row['received_at'];
				}
			}
			if ( count( $rows ) < 50 ) {
				break;
			}
		}
		arsort( $hits ); // received_at is "Y-m-d H:i:s", so string order is date order
		$ids = array_slice( array_map( 'strval', array_keys( $hits ) ), 0, $max );
		return array( 'ids' => $ids, 'query' => $this->intake_filter_description(), 'estimate' => count( $ids ) );
	}

	/**
	 * One message in the pipeline's normalized shape: subject, sender, date, snippet, plain-text body and its PDF
	 * attachments (filename, attachment_id, size). Body and attachments live behind the login's own account endpoints,
	 * using the real folderId the Shared Mailbox API reports.
	 */
	public function intake_message( $message_id, $address = '' ) {
		$details = $this->get_message( $message_id, $address );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$folder_id  = self::deep_string( $details, array( 'folderId' ) );
		$account_id = trim( (string) $this->get_settings()['zoho_mail_account_id'] );
		$created    = self::deep_find( $details, 'createdAt' );
		$date_ts    = strtotime( $this->timestamp_to_mysql( $created ) . ' UTC' );
		$from_name  = self::deep_string( $details, array( 'senderName' ) );
		$from_email = self::deep_string( $details, array( 'fromAddress' ) );
		$body_text  = self::deep_string( $details, array( 'summary' ) );
		$pdfs       = array();

		if ( '' !== $folder_id && '' !== $account_id ) {
			$base = '/accounts/' . rawurlencode( $account_id ) . '/folders/' . rawurlencode( $folder_id ) . '/messages/' . rawurlencode( (string) $message_id );
			$body = $this->api_request( $base . '/content' );
			if ( ! is_wp_error( $body ) && 200 === $body['status'] ) {
				$html = self::deep_string( $body['data'], array( 'content' ) );
				if ( '' !== $html ) {
					$text = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html );
					$text = preg_replace( '/<br\s*\/?>|<\/p>|<\/div>|<\/tr>|<\/li>/i', "\n", (string) $text );
					$body_text = html_entity_decode( wp_strip_all_tags( (string) $text, false ), ENT_QUOTES, 'UTF-8' );
				}
			}
			$info = $this->api_request( $base . '/attachmentinfo' );
			if ( ! is_wp_error( $info ) && 200 === $info['status'] ) {
				foreach ( (array) ( isset( $info['data']['data']['attachments'] ) ? $info['data']['data']['attachments'] : array() ) as $a ) {
					$name = isset( $a['attachmentName'] ) ? (string) $a['attachmentName'] : '';
					if ( '' === $name || empty( $a['attachmentId'] ) || 0 !== strcasecmp( substr( $name, -4 ), '.pdf' ) ) {
						continue;
					}
					$pdfs[] = array( 'filename' => $name, 'attachment_id' => (string) $a['attachmentId'], 'size' => isset( $a['attachmentSize'] ) ? (int) $a['attachmentSize'] : 0 );
				}
			}
		}
		return array(
			'subject'   => self::deep_string( $details, array( 'title', 'subject' ) ),
			'sender'    => '' !== $from_name && '' !== $from_email ? $from_name . ' <' . $from_email . '>' : ( '' !== $from_email ? $from_email : $from_name ),
			'date_ts'   => $date_ts ? $date_ts : null,
			'date_str'  => $date_ts ? gmdate( 'D, d M Y H:i:s', $date_ts ) . ' +0000' : '',
			'snippet'   => $body_text !== '' ? mb_substr( preg_replace( '/\s+/', ' ', $body_text ), 0, 200 ) : '',
			'body_text' => $body_text,
			'pdfs'      => $pdfs,
		);
	}

	/** Raw bytes of one attachment (Zoho returns the file itself, not JSON), or WP_Error. */
	public function intake_attachment( $message_id, $attachment_id, $address = '' ) {
		$details    = $this->get_message( $message_id, $address );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$folder_id  = self::deep_string( $details, array( 'folderId' ) );
		$account_id = trim( (string) $this->get_settings()['zoho_mail_account_id'] );
		if ( '' === $folder_id || '' === $account_id ) {
			return new WP_Error( 'zoho_mail_no_folder', __( 'Zoho did not report this message\'s folder.', 'ajcore' ) );
		}
		$res = $this->api_request( '/accounts/' . rawurlencode( $account_id ) . '/folders/' . rawurlencode( $folder_id ) . '/messages/' . rawurlencode( (string) $message_id ) . '/attachments/' . rawurlencode( (string) $attachment_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== $res['status'] || '' === $res['raw'] ) {
			return new WP_Error( 'zoho_mail_attachment_failed', sprintf( __( 'Zoho attachment download returned HTTP %d.', 'ajcore' ), $res['status'] ) );
		}
		return $res['raw'];
	}

	/** Zoho Mail timestamps: milliseconds since epoch (number or numeric string) or an ISO 8601 string. */
	private function timestamp_to_mysql( $value ) {
		if ( is_string( $value ) && ! is_numeric( $value ) ) {
			$t = strtotime( $value );
			return $t ? gmdate( 'Y-m-d H:i:s', $t ) : '';
		}
		$n = (float) $value;
		if ( $n <= 0 ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', (int) ( $n > 1e11 ? round( $n / 1000 ) : $n ) );
	}

	// ── Admin tab ───────────────────────────────────────────────────────────────

	public function is_connected() {
		$settings = $this->get_settings();
		return ! empty( $settings['zoho_mail_refresh_token'] );
	}

	public function render_notices() {
		if ( isset( $_GET['zoho-mail-connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zoho Mail connected.', 'ajcore' ) . '</p></div>';
		} elseif ( isset( $_GET['zoho-mail-disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Zoho Mail disconnected.', 'ajcore' ) . '</p></div>';
		} elseif ( isset( $_GET['zoho-mail-error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['zoho-mail-error'] ) ) ) . '</p></div>';
		} elseif ( isset( $_GET['saved'] ) && 'zoho' === ( isset( $_GET['src'] ) ? sanitize_key( wp_unslash( $_GET['src'] ) ) : '' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'ajcore' ) . '</p></div>';
		}
	}

	/** The live shared inbox. */
	public function render_inbox_panel() {
		$settings = $this->get_settings();
		$this->render_inbox( $settings, $this->is_connected() );
	}

	/** Connection settings (the Pipeline tab keeps them collapsed behind the Zoho gear). */
	public function render_connection_panel() {
		$settings     = $this->get_settings();
		$is_connected = ! empty( $settings['zoho_mail_refresh_token'] );
		$can_connect  = ! empty( $settings['zoho_mail_client_id'] ) && ! empty( $settings['zoho_mail_client_secret'] );
		$redirect_uri = rest_url( 'ajcore/v1/zoho-mail/oauth/callback' );

		// A random token in a transient (not a user-tied nonce) survives Zoho's redirect back, which carries no WP auth.
		$state = wp_generate_password( 32, false );
		set_transient( 'ajcore_zoho_mail_oauth_state', $state, 10 * MINUTE_IN_SECONDS );
		$authorize_url = add_query_arg( array(
			// accounts: find the login's own account; messages.ALL: the Shared Mailbox API; folders: message bodies.
			'scope'         => 'ZohoMail.accounts.READ,ZohoMail.messages.ALL,ZohoMail.folders.READ',
			'client_id'     => (string) $settings['zoho_mail_client_id'],
			'response_type' => 'code',
			'access_type'   => 'offline',
			'redirect_uri'  => $redirect_uri,
			'prompt'        => 'consent',
			'state'         => $state,
		), 'https://' . $this->accounts_host( $settings['zoho_mail_data_center'] ) . '/oauth/v2/auth' );
		$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=ajcore_ra_zoho_mail_disconnect' ), 'ajcore_zoho_mail_disconnect' );
		?>
		<div class="ajmr-conn">
			<details style="margin:10px 0">
				<summary style="cursor:pointer"><?php esc_html_e( 'Setup steps', 'ajcore' ); ?></summary>
				<ol style="line-height:1.7">
					<li><?php esc_html_e( 'Open api-console.zoho.com (the Zoho API Console, not the Mail Admin Console) and sign in with a Zoho user that is a member of the shared mailbox.', 'ajcore' ); ?></li>
					<li><?php esc_html_e( 'Add Client, choose Server-based Applications.', 'ajcore' ); ?></li>
					<li><?php echo esc_html( sprintf( __( 'Client Name: AJCore Zoho Mail. Homepage URL: %s', 'ajcore' ), home_url( '/' ) ) ); ?></li>
					<li><?php esc_html_e( 'Authorized Redirect URI (must match exactly):', 'ajcore' ); ?> <code><?php echo esc_html( $redirect_uri ); ?></code></li>
					<li><?php esc_html_e( 'Copy the Client ID and Client Secret below, enter the shared mailbox address, Save, then Connect and sign in as that same user. No Organization ID is needed.', 'ajcore' ); ?></li>
				</ol>
			</details>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:720px">
				<input type="hidden" name="action" value="ajcore_ra_save_zoho_mail">
				<?php wp_nonce_field( 'ajcore_ra_save_zoho_mail' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="zoho_mail_client_id"><?php esc_html_e( 'Client ID', 'ajcore' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_client_id" id="zoho_mail_client_id" value="<?php echo esc_attr( $settings['zoho_mail_client_id'] ); ?>" autocomplete="off"></td></tr>
					<tr><th><label for="zoho_mail_client_secret"><?php esc_html_e( 'Client Secret', 'ajcore' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_client_secret" id="zoho_mail_client_secret" value="" placeholder="<?php echo ! empty( $settings['zoho_mail_client_secret'] ) ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'ajcore' ) : ''; ?>" autocomplete="off"></td></tr>
					<tr><th><label for="zoho_mail_account_email"><?php esc_html_e( 'Shared Mailbox Address', 'ajcore' ); ?></label></th><td><input type="email" class="regular-text" name="zoho_mail_account_email" id="zoho_mail_account_email" value="<?php echo esc_attr( $settings['zoho_mail_account_email'] ); ?>"></td></tr>
					<tr><th><label for="zoho_mail_mailboxes"><?php esc_html_e( 'Other shared mailboxes', 'ajcore' ); ?></label></th><td><textarea name="zoho_mail_mailboxes" id="zoho_mail_mailboxes" rows="3" class="large-text" placeholder="shop@example.com | purchases"><?php echo esc_textarea( $settings['zoho_mail_mailboxes'] ); ?></textarea><p class="description"><?php esc_html_e( 'One per line: address | what it is for. Sub-pipelines (e.g. Purchases) read these with the same Zoho login. "Find shared mailboxes" below lists what this login can read.', 'ajcore' ); ?></p></td></tr>
					<?php $filter_managed = AJCore_Pipeline::instance()->filter_managed( 'zoho' ); $filter_ro = $filter_managed ? ' readonly' : ''; ?>
					<?php if ( $filter_managed ) : ?>
						<tr><th><?php esc_html_e( 'Filter', 'ajcore' ); ?></th><td><strong><?php esc_html_e( 'Set by AJCore-RA', 'ajcore' ); ?></strong>: <code><?php echo esc_html( '' !== $this->intake_filter_summary() ? $this->intake_filter_summary() : __( 'every message', 'ajcore' ) ); ?></code><p class="description"><?php esc_html_e( 'The fields below are not in use while it is set.', 'ajcore' ); ?></p></td></tr>
					<?php endif; ?>
					<tr><th><label for="zoho_mail_filter_subject"><?php esc_html_e( 'Subject contains', 'ajcore' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_filter_subject" id="zoho_mail_filter_subject"<?php echo $filter_ro; ?> value="<?php echo esc_attr( $settings['zoho_mail_filter_subject'] ); ?>" placeholder="<?php echo esc_attr__( 'Secretary of State, Filing', 'ajcore' ); ?>"><p class="description"><?php esc_html_e( 'Comma-separated; any one matches.', 'ajcore' ); ?></p></td></tr>
					<tr><th><label for="zoho_mail_filter_sender"><?php esc_html_e( 'Sender contains', 'ajcore' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_filter_sender" id="zoho_mail_filter_sender"<?php echo $filter_ro; ?> value="<?php echo esc_attr( $settings['zoho_mail_filter_sender'] ); ?>" placeholder="<?php echo esc_attr__( 'sosnc.gov', 'ajcore' ); ?>"><p class="description"><?php esc_html_e( 'Name or address, comma-separated; any one matches.', 'ajcore' ); ?></p></td></tr>
					<tr><th><label for="zoho_mail_filter_text"><?php esc_html_e( 'Anywhere', 'ajcore' ); ?></label></th><td><input type="text" class="regular-text" name="zoho_mail_filter_text" id="zoho_mail_filter_text"<?php echo $filter_ro; ?> value="<?php echo esc_attr( $settings['zoho_mail_filter_text'] ); ?>"><p class="description"><?php esc_html_e( 'Optional: matches the subject, sender or preview. All filters blank = every message.', 'ajcore' ); ?></p></td></tr>
					<tr><th><label for="zoho_mail_filter_match"><?php esc_html_e( 'Match', 'ajcore' ); ?></label></th><td><select name="zoho_mail_filter_match" id="zoho_mail_filter_match"<?php echo $filter_managed ? ' disabled' : ''; ?>><option value="all" <?php selected( 'any' === $settings['zoho_mail_filter_match'] ? 'any' : 'all', 'all' ); ?>><?php esc_html_e( 'All filled fields (narrow)', 'ajcore' ); ?></option><option value="any" <?php selected( $settings['zoho_mail_filter_match'], 'any' ); ?>><?php esc_html_e( 'Any filled field (broad)', 'ajcore' ); ?></option></select></td></tr>
					<tr><th><label for="zoho_mail_data_center"><?php esc_html_e( 'Data center', 'ajcore' ); ?></label></th><td><select name="zoho_mail_data_center" id="zoho_mail_data_center"><?php foreach ( array( 'com' => 'zoho.com (US)', 'eu' => 'zoho.eu', 'in' => 'zoho.in', 'com.au' => 'zoho.com.au', 'jp' => 'zoho.jp' ) as $dc_key => $dc_label ) : ?><option value="<?php echo esc_attr( $dc_key ); ?>" <?php selected( $settings['zoho_mail_data_center'], $dc_key ); ?>><?php echo esc_html( $dc_label ); ?></option><?php endforeach; ?></select></td></tr>
				</table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ajcore' ); ?></button></p>
			</form>
			<p>
				<?php if ( $can_connect ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $authorize_url ); ?>"><?php echo esc_html( $is_connected ? __( 'Reconnect Zoho Mail', 'ajcore' ) : __( 'Connect Zoho Mail', 'ajcore' ) ); ?></a>
				<?php else : ?>
					<button type="button" class="button" disabled><?php esc_html_e( 'Connect Zoho Mail', 'ajcore' ); ?></button> <span class="description"><?php esc_html_e( 'Save a Client ID and Client Secret first.', 'ajcore' ); ?></span>
				<?php endif; ?>
				<?php if ( $is_connected ) : ?>
					<button type="button" class="button" id="ajra-zm-test"><?php esc_html_e( 'Test Connection', 'ajcore' ); ?></button>
					<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect Zoho Mail?', 'ajcore' ) ); ?>');"><?php esc_html_e( 'Disconnect', 'ajcore' ); ?></a>
				<?php endif; ?>
			</p>
			<div id="ajra-zm-result" style="display:none;margin:10px 0;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px"></div>
			<?php if ( $is_connected ) : ?>
				<h4 style="margin-bottom:4px"><?php esc_html_e( 'Shared mailboxes this login can read', 'ajcore' ); ?></h4>
				<p><button type="button" class="button" id="ajra-zm-diag"><?php esc_html_e( 'Find shared mailboxes', 'ajcore' ); ?></button></p>
				<div id="ajra-zm-diag-result" style="display:none;margin:10px 0;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px"></div>
			<?php endif; ?>
			<?php
			foreach ( array( 'ajcore_zoho_mail_api_debug' => __( 'Last raw Zoho Mail API response', 'ajcore' ) ) as $debug_key => $debug_label ) {
				$debug = get_transient( $debug_key );
				if ( empty( $debug ) || ! is_array( $debug ) ) {
					continue;
				}
				$decoded = json_decode( $debug['body'], true );
				$pretty  = null !== $decoded ? wp_json_encode( $decoded, JSON_PRETTY_PRINT ) : $debug['body'];
				echo '<details style="margin-top:10px"><summary>' . esc_html( $debug_label ) . ' — ' . esc_html( $debug['url'] ) . ' — HTTP ' . esc_html( $debug['status'] ) . ' — ' . esc_html( $debug['at'] ) . '</summary><pre style="max-height:320px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font-size:12px;white-space:pre-wrap">' . esc_html( $pretty ) . '</pre></details>';
			}
			?>
		</div>
		<script>
		(function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var testUrl = <?php echo wp_json_encode( rest_url( 'ajcore/v1/zoho-mail/test' ) ); ?>;
			var diagUrl = <?php echo wp_json_encode( rest_url( 'ajcore/v1/zoho-mail/diagnose' ) ); ?>;
			function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
			function call(url, btn, box, render) {
				btn.disabled = true; box.style.display = 'none';
				fetch(url, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (d) { box.innerHTML = render(d || {}); box.style.display = 'block'; })
					.catch(function () { box.innerHTML = 'Request to the site failed.'; box.style.display = 'block'; })
					.then(function () { btn.disabled = false; });
			}
			var t = document.getElementById('ajra-zm-test');
			if (t) { t.addEventListener('click', function () {
				call(testUrl, t, document.getElementById('ajra-zm-result'), function (d) {
					if (!d.success) { return '<strong style="color:#b32d2e">Test failed</strong><br>' + esc(d.message || 'Unknown error.'); }
					return '<strong style="color:#007017">Connection works</strong><br>Folders: ' + esc((d.folders || []).join(', ')) + '<br>Recent: ' + ((d.recent_messages || []).length ? '<ul>' + d.recent_messages.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>' : 'none');
				});
			}); }
			var g = document.getElementById('ajra-zm-diag');
			if (g) { g.addEventListener('click', function () {
				call(diagUrl, g, document.getElementById('ajra-zm-diag-result'), function (d) {
					if (!d.success) { return '<strong style="color:#b32d2e">Lookup failed</strong><br>' + esc(d.message || 'Unknown error.'); }
					if (!(d.mailboxes || []).length) { return '<strong style="color:#b32d2e">None found</strong> — this login is not a member of any shared mailbox it can read.'; }
					return '<ul>' + d.mailboxes.map(function (m) { return '<li><strong>' + esc(m.address) + '</strong> (' + esc(m.name) + ') — ' + esc(m.total) + ' messages, ' + esc(m.unread) + ' unread</li>'; }).join('') + '</ul>Copy the address you want into Shared Mailbox Address.';
				});
			}); }
		})();
		</script>
		<?php
	}

	private function render_inbox( array $settings, $is_connected ) {
		echo '<div class="ajmr-card">';
		if ( ! $is_connected ) {
			echo '<p>' . esc_html__( 'Zoho is not connected. Open its button above to connect.', 'ajcore' ) . '</p></div>';
			return;
		}
		$views = $this->get_views();
		if ( is_wp_error( $views ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $views->get_error_message() ) . '</p></div></div>';
			return;
		}
		$view  = isset( $_GET['zoho_view'] ) ? sanitize_key( wp_unslash( $_GET['zoho_view'] ) ) : 'all';
		$view  = in_array( $view, array_column( $views, 'folder_id' ), true ) ? $view : 'all';
		$limit = 50;
		$start = isset( $_GET['zoho_start'] ) ? absint( $_GET['zoho_start'] ) : 0;
		$messages = $this->get_recent_messages( $limit, $view, $start );
		if ( is_wp_error( $messages ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $messages->get_error_message() ) . '</p></div></div>';
			return;
		}
		$mailbox = $settings['zoho_mail_connected_email'] ? $settings['zoho_mail_connected_email'] : $settings['zoho_mail_account_email'];
		echo '<p class="ajmr-meta">' . esc_html( sprintf( __( '%s · read-only: reply, assign and archive in Zoho Mail', 'ajcore' ), $mailbox ) ) . '</p>';
		$page_url = static function ( $args ) {
			return add_query_arg( array_merge( array( 'page' => 'ajforms-client-portal', 'tab' => self::TAB, 'src' => 'zoho' ), $args ), admin_url( 'admin.php' ) );
		};
		?>
		<div class="ajmr-pills">
			<?php foreach ( $views as $v ) : ?>
				<a class="<?php echo $view === $v['folder_id'] ? 'is-on' : ''; ?>" href="<?php echo esc_url( $page_url( array( 'zoho_view' => $v['folder_id'] ) ) ); ?>"><?php echo esc_html( $v['name'] ); ?><?php echo $v['unread_count'] > 0 ? '<b>' . (int) $v['unread_count'] . '</b>' : ''; ?></a>
			<?php endforeach; ?>
		</div>
		<table class="widefat striped">
			<thead><tr><th style="width:170px"><?php esc_html_e( 'Date', 'ajcore' ); ?></th><th style="width:220px"><?php esc_html_e( 'From', 'ajcore' ); ?></th><th><?php esc_html_e( 'Subject', 'ajcore' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $messages ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No messages in this view.', 'ajcore' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $messages as $message ) : ?>
					<tr style="<?php echo $message['is_unread'] ? 'font-weight:700;' : ''; ?>">
						<td><?php echo esc_html( $message['received_at'] ? get_date_from_gmt( $message['received_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—' ); ?></td>
						<td><?php echo esc_html( $message['from_name'] ? $message['from_name'] : $message['from_email'] ); ?></td>
						<td><?php echo esc_html( $message['subject'] ); ?><?php echo $message['has_attachment'] ? ' <span title="' . esc_attr__( 'Has attachment', 'ajcore' ) . '">📎</span>' : ''; ?><?php if ( '' !== $message['summary'] ) : ?><div style="color:#646970;font-weight:400"><?php echo esc_html( wp_trim_words( $message['summary'], 24 ) ); ?></div><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<p>
			<?php if ( $start > 0 ) : ?><a class="button" href="<?php echo esc_url( $page_url( array( 'zoho_view' => $view, 'zoho_start' => max( 0, $start - $limit ) ) ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'ajcore' ); ?></a><?php endif; ?>
			<?php if ( count( $messages ) >= $limit ) : ?><a class="button" href="<?php echo esc_url( $page_url( array( 'zoho_view' => $view, 'zoho_start' => $start + $limit ) ) ); ?>"><?php esc_html_e( 'Older', 'ajcore' ); ?> &rarr;</a><?php endif; ?>
		</p>
		</div>
		<?php
	}
}
