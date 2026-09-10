<?php
defined( 'ABSPATH' ) || exit;

final class AJCore_Reviews_Admin {
	public static function init() {
		add_action( 'admin_menu', function() { add_submenu_page( 'ajforms', __( 'Reviews & Testimonials', 'ajcore' ), __( 'Reviews & Testimonials', 'ajcore' ), 'manage_options', 'ajcore-reviews', array( __CLASS__, 'page' ) ); }, 30 );
		add_action( 'admin_post_ajcore_reviews_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_ajcore_reviews_oauth', array( __CLASS__, 'oauth' ) );
	}

	private static function value( $key, $source = null ) {
		$source = $source === null ? $_POST : $source;
		return isset( $source[$key] ) && is_string( $source[$key] ) ? wp_unslash( $source[$key] ) : '';
	}
	public static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to manage reviews.', 'ajcore' ), '', array( 'response' => 403 ) ); }
	}
	private static function url( $tab = 'settings' ) { return add_query_arg( array( 'page' => 'ajcore-reviews', 'tab' => $tab ), admin_url( 'admin.php' ) ); }
	private static function finish( $result, $tab = 'settings', $success = 'success' ) {
		$code = is_wp_error( $result ) ? AJCore_Reviews::safe_code( $result->get_error_code() ) : $success;
		wp_safe_redirect( add_query_arg( 'result', $code, self::url( $tab ) ) ); exit;
	}

	public static function action() {
		self::authorize();
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( esc_html__( 'A POST request is required.', 'ajcore' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'ajcore_reviews_action' );
		$operation = sanitize_key( self::value( 'operation' ) );
		// A refusal describes the attempt in progress, never the previous one.
		delete_option( 'ajcore_reviews_api_error' );
		if ( $operation === 'sync' ) { self::finish( AJCore_Reviews::sync(), 'reviews' ); }
		$result = AJCore_Reviews::locked( function() use ( $operation ) {
			switch ( $operation ) {
				case 'credentials':
					$id = trim( self::value( 'client_id' ) ); $secret = trim( self::value( 'client_secret' ) );
					if ( ! preg_match( '/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/D', $id ) || strlen( $secret ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $secret ) ) { return new WP_Error( 'credentials_required' ); }
					$old = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' );
					if ( $secret === '' ) { $secret = $old['client_secret'] ?? ''; }
					if ( $secret === '' ) { return new WP_Error( 'credentials_required' ); }
					$data = array( 'client_id' => $id, 'client_secret' => $secret );
					$sealed = AJCore_Reviews_Vault::seal( $data );
					if ( is_wp_error( $sealed ) ) { return $sealed; }
					if ( $id === ( $old['client_id'] ?? '' ) && $secret === ( $old['client_secret'] ?? '' ) ) { return true; }
					AJCore_Reviews::disconnect();
					return AJCore_Reviews_Vault::write( 'ajcore_reviews_credentials', $data );
				case 'connect':
					$state = bin2hex( random_bytes( 32 ) ); $verifier = bin2hex( random_bytes( 32 ) );
					$data = array( 'hash' => hash( 'sha256', $state ), 'verifier' => $verifier, 'user' => get_current_user_id(), 'session' => hash( 'sha256', wp_get_session_token() ), 'expires_at' => time() + 600 );
					$result = AJCore_Reviews_Vault::write( 'ajcore_reviews_oauth', $data );
					if ( is_wp_error( $result ) ) { return $result; }
					$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
					$url = AJCore_Reviews::provider()->authorization_url( $state, $challenge );
					return is_wp_error( $url ) ? $url : array( 'redirect' => $url );
				case 'disconnect': return AJCore_Reviews::disconnect();
				// One button, one list: every location under every authorized account.
				case 'load':
					$accounts = AJCore_Reviews::provider()->accounts();
					if ( is_wp_error( $accounts ) ) { return $accounts; }
					$options = array();
					foreach ( array_slice( $accounts, 0, 10 ) as $account ) {
						$locations = AJCore_Reviews::provider()->locations( $account['name'] );
						if ( is_wp_error( $locations ) ) { return $locations; }
						foreach ( $locations as $location ) {
							if ( ! preg_match( '#^locations/[0-9]+$#D', (string) $location['name'] ) ) { continue; }
							$options[] = array( 'account' => $account['name'], 'location' => $location['name'], 'label' => ( $location['title'] ?? $location['name'] ) . ' — ' . ( $account['accountName'] ?? $account['name'] ) );
						}
					}
					if ( ! $options ) { return new WP_Error( 'invalid_location' ); }
					return AJCore_Reviews_Vault::write( 'ajcore_reviews_choices', array( 'options' => $options, 'expires_at' => time() + 900 ) );
				case 'location':
					$options = self::choices()['options'] ?? array();
					$pick = $options[ (int) self::value( 'choice' ) ] ?? null;
					if ( ! $pick || ! preg_match( '#^accounts/[0-9]+$#D', (string) $pick['account'] ) || ! preg_match( '#^locations/[0-9]+$#D', (string) $pick['location'] ) ) { return new WP_Error( 'invalid_location' ); }
					if ( AJCore_Reviews::config() !== array( 'account' => $pick['account'], 'location' => $pick['location'] ) ) {
						foreach ( array( 'snapshot', 'selection', 'sync_meta' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
						AJCore_Reviews::unschedule();
						update_option( 'ajcore_reviews_config', array( 'account' => $pick['account'], 'location' => $pick['location'] ), false );
						do_action( 'ajcore_reviews_content_changed' );
					}
					AJCore_Reviews::schedule(); return true;
				case 'test':
					$config = AJCore_Reviews::config();
					if ( empty( $config['account'] ) || empty( $config['location'] ) ) { return new WP_Error( 'invalid_location' ); }
					$locations = AJCore_Reviews::provider()->locations( $config['account'] );
					if ( is_wp_error( $locations ) ) { return $locations; }
					return in_array( $config['location'], array_column( $locations, 'name' ), true ) ? true : new WP_Error( 'invalid_location' );
				case 'feature':
					return AJCore_Reviews::set_featured( self::value( 'key' ), self::value( 'featured' ) === '1', (int) self::value( 'order' ) );
				case 'provision':
					$result = AJCore_Reviews_Setup::provision();
					if ( is_wp_error( $result ) ) { return $result; }
					// Point the prompt at the page we just made, unless one is already set.
					$display = (array) get_option( 'ajcore_reviews_display', array() );
					if ( empty( $display['feedback_url'] ) && $result['url'] !== '' ) {
						$display['feedback_url'] = $result['url'];
						update_option( 'ajcore_reviews_display', array_merge( $display, ajcore_sanitize_review_prompt_settings( $display ) ), false );
						do_action( 'ajcore_reviews_content_changed' );
					}
					return true;
				case 'display':
					$display = array( 'fallback' => sanitize_text_field( self::value( 'fallback' ) ), 'order' => self::value( 'order' ) === 'date' ? 'date' : 'manual' );
					$prompt = ajcore_sanitize_review_prompt_settings( array( 'prompt_enabled' => self::value( 'prompt_enabled' ) === '1', 'prompt_label' => self::value( 'prompt_label' ), 'feedback_url' => self::value( 'feedback_url' ), 'google_review_url' => self::value( 'google_review_url' ) ) );
					update_option( 'ajcore_reviews_display', array_merge( $display, $prompt ), false );
					do_action( 'ajcore_reviews_content_changed' ); return true;
			}
			return new WP_Error( 'operation_failed' );
		} );
		if ( is_array( $result ) && isset( $result['redirect'] ) && wp_parse_url( $result['redirect'], PHP_URL_HOST ) === 'accounts.google.com' && wp_parse_url( $result['redirect'], PHP_URL_SCHEME ) === 'https' ) { wp_redirect( $result['redirect'] ); exit; }
		$success = array( 'test' => 'connection_ok', 'load' => 'locations_loaded', 'location' => 'location_selected' );
		self::finish( $result, $operation === 'feature' ? 'reviews' : 'settings', $success[ $operation ] ?? 'success' );
	}

	public static function valid_state( $pending, $state, $user, $session, $now ) {
		return is_array( $pending ) && is_string( $state ) && strlen( $state ) === 64 && ! empty( $pending['hash'] ) && ! empty( $pending['session'] ) && ( $pending['user'] ?? 0 ) === $user && ( $pending['expires_at'] ?? 0 ) > $now && hash_equals( $pending['hash'], hash( 'sha256', $state ) ) && hash_equals( $pending['session'], hash( 'sha256', $session ) );
	}

	public static function oauth() {
		self::authorize(); nocache_headers(); header( 'Referrer-Policy: no-referrer' );
		self::finish( self::complete_oauth( $_GET ) );
	}

	public static function complete_oauth( $query ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'access_denied' ); }
		return AJCore_Reviews::locked( function() use ( $query ) {
			$pending = AJCore_Reviews_Vault::read( 'ajcore_reviews_oauth' );
			if ( ! self::valid_state( $pending, self::value( 'state', $query ), get_current_user_id(), wp_get_session_token(), time() ) ) { return new WP_Error( 'oauth_state_invalid' ); }
			AJCore_Reviews_Vault::delete( 'ajcore_reviews_oauth' ); // Single use, including denial and failed exchange.
			$code = self::value( 'code', $query );
			if ( $code === '' || strlen( $code ) > 4096 || self::value( 'error', $query ) !== '' ) { return new WP_Error( 'authorization_failed' ); }
			$result = AJCore_Reviews::provider()->exchange( $code, $pending['verifier'] );
			if ( ! is_wp_error( $result ) ) {
				foreach ( array( 'snapshot', 'selection', 'choices', 'config', 'sync_meta' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
				AJCore_Reviews::unschedule(); do_action( 'ajcore_reviews_content_changed' );
			}
			return $result;
		} );
	}

	private static function choices() { $data = AJCore_Reviews_Vault::read( 'ajcore_reviews_choices' ); return ( $data['expires_at'] ?? 0 ) > time() ? $data : array(); }
	private static function form( $operation ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ajcore_reviews_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		wp_nonce_field( 'ajcore_reviews_action' );
	}
	private static function button( $operation, $label ) { self::form( $operation ); submit_button( $label, 'secondary', 'submit', false ); echo '</form>'; }
	/** One line of the connection checklist: done or not, and what to do about it. */
	private static function step( $done, $label, $detail ) {
		echo '<li style="margin:0 0 .5em;list-style:none"><span aria-hidden="true" style="font-weight:700;color:' . ( $done ? '#00700f' : '#996800' ) . '">' . ( $done ? '&#10003;' : '&#9675;' ) . '</span> <strong>' . esc_html( $label ) . '</strong><br><span style="color:#50575e;margin-left:1.4em">' . esc_html( $detail ) . '</span></li>';
	}
	private static function time( $timestamp ) { return $timestamp ? wp_date( 'Y-m-d H:i T', $timestamp ) : __( 'Not available', 'ajcore' ); }

	/** Two screens now: everything you configure, and everything you publish. */
	private static function tab() {
		$tab = sanitize_key( self::value( 'tab', $_GET ) );
		// Bookmarks and old links from the seven-tab layout.
		$legacy = array( 'overview' => 'settings', 'connection' => 'settings', 'display' => 'settings', 'history' => 'settings', 'inbox' => 'reviews', 'featured' => 'reviews', 'manual' => 'reviews' );
		$tab = $legacy[$tab] ?? $tab;
		return in_array( $tab, array( 'reviews', 'settings' ), true ) ? $tab : 'reviews';
	}

	public static function page() {
		self::authorize();
		$tabs = array( 'reviews' => __( 'Reviews & Testimonials', 'ajcore' ), 'settings' => __( 'Settings', 'ajcore' ) );
		$tab  = self::tab();
		echo '<div class="wrap"><h1>' . esc_html__( 'Reviews & Testimonials', 'ajcore' ) . '</h1><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Reviews sections', 'ajcore' ) . '">';
		foreach ( $tabs as $key => $label ) { echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		if ( self::value( 'result', $_GET ) ) { echo '<div class="notice notice-info"><p>' . esc_html( self::message( AJCore_Reviews::safe_code( self::value( 'result', $_GET ) ) ) ) . '</p></div>'; }
		if ( $tab === 'settings' ) { self::settings(); } else { self::collection(); }
		echo '</div>';
	}

	/* ---------------------------------------------------------------- Settings */

	/** Google on the left, what visitors see on the right. Stacks on narrow screens. */
	private static function settings() {
		echo '<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin-top:1em">';
		echo '<div style="flex:1 1 460px;min-width:300px;max-width:900px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Google connection', 'ajcore' ) . '</h2>';
		self::connection();
		self::status_panel();
		echo '</div>';
		echo '<div style="flex:1 1 460px;min-width:300px;max-width:900px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Rate Us prompt and display', 'ajcore' ) . '</h2>';
		self::display_settings();
		echo '</div></div>';
	}

	private static function status_panel() {
		$status = AJCore_Reviews::status(); $meta = (array) get_option( 'ajcore_reviews_sync_meta', array() );
		echo '<h3>' . esc_html__( 'Sync status', 'ajcore' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';
		$rows = array(
			__( 'Connection and content status', 'ajcore' ) => esc_html( $status['state'] ) . ( $status['stale'] ? ' — ' . esc_html__( 'Stale: refresh needed', 'ajcore' ) : '' ),
			__( 'Valid synchronized reviews', 'ajcore' )    => (int) $status['valid_count'],
			__( 'Last successful sync', 'ajcore' )          => esc_html( self::time( $status['last_success'] ) ),
			__( 'Next scheduled sync', 'ajcore' )           => esc_html( self::time( wp_next_scheduled( AJCore_Reviews::SYNC ) ) ),
			__( 'Content expires', 'ajcore' )               => esc_html( self::time( $status['expires_at'] ) ),
		);
		if ( ! empty( $meta['last_error'] ) ) { $rows[ __( 'Last error', 'ajcore' ) ] = esc_html( self::message( $meta['last_error'] ) ); }
		// What Google actually said, when the generic refusal is not enough to act on.
		$api = (array) get_option( 'ajcore_reviews_api_error', array() );
		if ( ! empty( $api['time'] ) ) {
			$facts = array_filter( array( 'HTTP ' . (int) $api['http'], $api['status'] ?? '', $api['reason'] ?? '', $api['service'] ?? '', $api['endpoint'] ?? '' ) );
			$rows[ __( 'Last Google refusal', 'ajcore' ) ] = esc_html( self::time( (int) $api['time'] ) ) . '<br><code>' . esc_html( implode( ' · ', $facts ) ) . '</code>';
		}
		foreach ( $rows as $label => $value ) { echo '<tr><th scope="row" style="width:16em">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; }
		echo '</tbody></table>';
		// Replaces the old Sync History tab: same rows, folded away until asked for.
		$history = array_slice( array_reverse( (array) get_option( 'ajcore_reviews_history', array() ) ), 0, 10 );
		if ( $history ) {
			echo '<details style="margin:1em 0"><summary>' . esc_html__( 'Recent sync activity', 'ajcore' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'ajcore' ) . '</th><th>' . esc_html__( 'Result', 'ajcore' ) . '</th><th>' . esc_html__( 'Reviews', 'ajcore' ) . '</th><th>' . esc_html__( 'Seconds', 'ajcore' ) . '</th><th>' . esc_html__( 'Trigger', 'ajcore' ) . '</th></tr></thead><tbody>';
			foreach ( $history as $row ) { echo '<tr><td>' . esc_html( self::time( $row['timestamp'] ) ) . '</td><td>' . esc_html( self::message( $row['status'] ) ) . ' <code>' . esc_html( $row['status'] ) . '</code></td><td>' . (int) $row['count'] . '</td><td>' . esc_html( $row['duration'] ) . '</td><td>' . esc_html( $row['trigger'] ) . '</td></tr>'; }
			echo '</tbody></table></details>';
		}
	}

	private static function connection() {
		$c = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' ); $config = AJCore_Reviews::config();
		$saved = ! empty( $c['client_id'] ); $connected = AJCore_Reviews::connected(); $located = ! empty( $config['location'] );

		// Three facts, no prose: what is done, and what each done step resolved to.
		echo '<div class="card" style="max-width:100%"><h3 style="margin-top:0">' . esc_html__( 'Connection status', 'ajcore' ) . '</h3><ul style="margin:0">';
		self::step( $saved, __( 'OAuth client saved', 'ajcore' ), $saved ? $c['client_id'] : __( 'Not saved', 'ajcore' ) );
		self::step( $connected, __( 'Google account connected', 'ajcore' ), $connected ? ( $c['email'] ?? __( 'Account not identified', 'ajcore' ) ) : __( 'Not connected', 'ajcore' ) );
		self::step( $located, __( 'Business location selected', 'ajcore' ), $located ? ( ( $config['account'] ?? '' ) . ' / ' . $config['location'] ) : __( 'Not selected', 'ajcore' ) );
		echo '</ul></div>';

		echo '<h3>' . esc_html__( 'Google credentials', 'ajcore' ) . '</h3>';
		echo '<p>' . esc_html__( 'Authorized redirect URI', 'ajcore' ) . '<br><code>' . esc_html( AJCore_Google_Review_Provider::redirect_uri() ) . '</code></p>';
		self::form( 'credentials' );
		echo '<p><label>' . esc_html__( 'OAuth client ID', 'ajcore' ) . '<br><input class="large-text" name="client_id" value="' . esc_attr( $c['client_id'] ?? '' ) . '" required autocomplete="off" placeholder="000000000000-abcdefghijklmnop.apps.googleusercontent.com"></label></p>';
		echo '<p><label>' . esc_html__( 'OAuth client secret (leave empty to keep the saved one)', 'ajcore' ) . '<br><input class="regular-text" type="password" name="client_secret" value="" autocomplete="new-password"></label></p>';
		submit_button( __( 'Save Credentials', 'ajcore' ) ); echo '</form>';

		echo '<h3>' . esc_html__( 'Business location', 'ajcore' ) . '</h3>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
		self::button( 'connect', $connected ? __( 'Reconnect Google Account', 'ajcore' ) : __( 'Connect Google Account', 'ajcore' ) );
		if ( $connected ) { self::button( 'load', __( 'Find Locations', 'ajcore' ) ); }
		if ( $located ) { self::button( 'test', __( 'Test Connection', 'ajcore' ) ); }
		if ( $saved || $connected ) { self::button( 'disconnect', __( 'Disconnect', 'ajcore' ) ); }
		echo '</div>';

		// One list of real locations, chosen and saved in a single step.
		$options = self::choices()['options'] ?? array();
		if ( $options ) {
			$current = ( $config['account'] ?? '' ) . '|' . ( $config['location'] ?? '' );
			self::form( 'location' ); echo '<p><label>' . esc_html__( 'Location', 'ajcore' ) . '<br><select name="choice">';
			foreach ( $options as $index => $option ) { echo '<option value="' . (int) $index . '" ' . selected( $current, $option['account'] . '|' . $option['location'], false ) . '>' . esc_html( $option['label'] ) . '</option>'; }
			echo '</select></label> '; submit_button( __( 'Use This Location', 'ajcore' ), 'primary', 'submit', false ); echo '</p></form>';
		}
	}

	private static function display_settings() {
		$data   = ajcore_get_reviews_display_settings();
		$prompt = ajcore_sanitize_review_prompt_settings( get_option( 'ajcore_reviews_display', array() ) );
		$suggested = AJCore_Reviews_Setup::suggested_url();
		$has_page  = AJCore_Reviews_Setup::page_exists();

		echo '<div class="card" style="max-width:100%"><h3 style="margin-top:0">' . esc_html__( 'Private feedback page', 'ajcore' ) . '</h3>';
		if ( $has_page ) {
			echo '<p><a href="' . esc_url( $suggested ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $suggested ) . '</a>';
			$form_id = AJCore_Reviews_Setup::form_id();
			if ( $form_id ) { echo ' — ' . esc_html( sprintf( __( 'AJ Forms form #%d', 'ajcore' ), $form_id ) ); }
			echo '</p>';
		} else {
			echo '<p><code>' . esc_html( $suggested ) . '</code></p>';
		}
		if ( AJCore_Reviews_Setup::forms_available() ) {
			self::button( 'provision', $has_page ? __( 'Recreate anything missing', 'ajcore' ) : __( 'Create the feedback page and form', 'ajcore' ) );
		} else {
			echo '<p>' . esc_html__( 'AJ Forms storage is unavailable. Build the page yourself and paste its URL below.', 'ajcore' ) . '</p>';
		}
		echo '</div>';

		self::form( 'display' );
		echo '<h3>' . esc_html__( 'Rate Us header prompt', 'ajcore' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="prompt_enabled" value="1" ' . checked( $prompt['prompt_enabled'], true, false ) . '> ' . esc_html__( 'Enable the Rate Us header prompt', 'ajcore' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Prompt label', 'ajcore' ) . '<br><input class="large-text" type="text" name="prompt_label" value="' . esc_attr( $prompt['prompt_label'] ) . '" placeholder="' . esc_attr__( 'Rate Us', 'ajcore' ) . '"></label></p>';

		// Auto-filled once the page exists; before that the suggestion is only a
		// placeholder, so nobody saves a URL that would 404.
		$feedback_value = $prompt['feedback_url'] !== '' ? $prompt['feedback_url'] : ( $has_page ? $suggested : '' );
		echo '<p><label>' . esc_html__( 'Private feedback page URL (HTTPS)', 'ajcore' ) . '<br><input class="large-text" type="url" name="feedback_url" value="' . esc_attr( $feedback_value ) . '" placeholder="' . esc_attr( $suggested ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Google Write a Review URL (HTTPS; optional override)', 'ajcore' ) . '<br><input class="large-text" type="url" name="google_review_url" value="' . esc_attr( $prompt['google_review_url'] ) . '" placeholder="https://search.google.com/local/writereview?placeid=ChIJ..."></label></p>';

		echo '<h3>' . esc_html__( 'Collection display', 'ajcore' ) . '</h3>';
		echo '<p><label>' . esc_html__( 'Frontend fallback', 'ajcore' ) . '<br><input class="large-text" name="fallback" value="' . esc_attr( $data['fallback'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Default featured ordering', 'ajcore' ) . ' <select name="order"><option value="manual" ' . selected( $data['order'], 'manual', false ) . '>' . esc_html__( 'Business display order', 'ajcore' ) . '</option><option value="date" ' . selected( $data['order'], 'date', false ) . '>' . esc_html__( 'Publication date, newest first', 'ajcore' ) . '</option></select></label></p>';
		submit_button(); echo '</form>';
	}

	/* ------------------------------------------------- Reviews & Testimonials */

	/** Which sources the list is showing. Nothing selected yet means show everything. */
	private static function filters() {
		$applied = self::value( 'filtered', $_GET ) === '1';
		return array(
			'google'   => ! $applied || self::value( 'src_google', $_GET ) === '1',
			'manual'   => ! $applied || self::value( 'src_manual', $_GET ) === '1',
			'featured' => $applied && self::value( 'featured_only', $_GET ) === '1',
		);
	}

	private static function collection() {
		$f = self::filters();
		echo '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;margin:1em 0">';
		self::button( 'sync', __( 'Sync Google Reviews Now', 'ajcore' ) );
		echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . AJCore_Testimonials::TYPE ) ) . '">' . esc_html__( 'Add Manual Testimonial', 'ajcore' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . AJCore_Testimonials::TYPE ) ) . '">' . esc_html__( 'All Manual Testimonials', 'ajcore' ) . '</a>';
		echo '</div>';

		echo '<form method="get" style="margin:1em 0;padding:.6em 1em;background:#fff;border:1px solid #c3c4c7"><input type="hidden" name="page" value="ajcore-reviews"><input type="hidden" name="tab" value="reviews"><input type="hidden" name="filtered" value="1">';
		echo '<label style="margin-right:1.5em"><input type="checkbox" name="src_google" value="1" ' . checked( $f['google'], true, false ) . '> ' . esc_html__( 'Google reviews', 'ajcore' ) . '</label>';
		echo '<label style="margin-right:1.5em"><input type="checkbox" name="src_manual" value="1" ' . checked( $f['manual'], true, false ) . '> ' . esc_html__( 'Manual testimonials', 'ajcore' ) . '</label>';
		echo '<label style="margin-right:1.5em"><input type="checkbox" name="featured_only" value="1" ' . checked( $f['featured'], true, false ) . '> ' . esc_html__( 'Only what is published on the site', 'ajcore' ) . '</label>';
		submit_button( __( 'Apply', 'ajcore' ), 'secondary', '', false );
		echo '</form>';

		if ( ! $f['google'] && ! $f['manual'] ) { echo '<p>' . esc_html__( 'Both sources are switched off, so there is nothing to show. Tick one above.', 'ajcore' ) . '</p>'; return; }
		if ( $f['google'] ) { self::google_list( $f['featured'] ); }
		if ( $f['manual'] ) { self::manual_list( $f['featured'] ); }
	}

	private static function google_list( $featured_only ) {
		$data = AJCore_Reviews::snapshot(); $selection = (array) get_option( 'ajcore_reviews_selection', array() );
		$reviews = array_filter( $data['reviews'] ?? array(), array( 'AJCore_Reviews', 'valid_review' ) );
		if ( $featured_only ) { $reviews = array_intersect_key( $reviews, $selection ); uasort( $reviews, function( $a, $b ) use ( $selection ) { return $selection[$a['key']] <=> $selection[$b['key']]; } ); }
		$total = count( $reviews ); $pages = max( 1, (int) ceil( $total / 20 ) );
		$page  = max( 1, min( $pages, absint( self::value( 'review_page', $_GET ) ) ) );
		echo '<h2>' . esc_html__( 'Google reviews', 'ajcore' ) . ' <span class="count">(' . (int) $total . ')</span></h2>';
		if ( ! $total ) { echo '<p>' . esc_html__( 'No Google reviews. Select a location on the Settings tab, then Sync Google Reviews Now.', 'ajcore' ) . '</p>'; return; }
		foreach ( array_slice( $reviews, ( $page - 1 ) * 20, 20, true ) as $key => $review ) {
			echo '<section class="card" style="max-width:900px"><h3>' . esc_html( $review['name'] ?: __( 'Anonymous reviewer', 'ajcore' ) ) . ' — ' . (int) $review['rating'] . '/5' . ( isset( $selection[$key] ) ? ' <span style="font-size:12px;color:#1d4ed8">' . esc_html__( '· on the site', 'ajcore' ) . '</span>' : '' ) . '</h3><p>' . nl2br( esc_html( $review['text'] ) ) . '</p><p>' . esc_html( $review['date'] ) . '</p><p>' . esc_html__( 'Retrieved:', 'ajcore' ) . ' ' . esc_html( self::time( $review['retrieved_at'] ) ) . ' · ' . esc_html__( 'Expires:', 'ajcore' ) . ' ' . esc_html( self::time( $review['expires_at'] ) ) . '</p>';
			if ( $review['avatar'] ) { echo '<p><img src="' . esc_url( $review['avatar'] ) . '" alt="" width="56" height="56" loading="lazy" referrerpolicy="no-referrer"></p>'; }
			echo '<details><summary>' . esc_html__( 'Source details', 'ajcore' ) . '</summary><p><code>' . esc_html( $review['id'] ) . '</code></p>';
			if ( $review['profile_url'] ) { echo '<p><a href="' . esc_url( $review['profile_url'] ) . '">' . esc_html__( 'Reviewer profile', 'ajcore' ) . '</a></p>'; }
			if ( $review['report_url'] ) { echo '<p><a href="' . esc_url( $review['report_url'] ) . '">' . esc_html__( 'Report review', 'ajcore' ) . '</a></p>'; }
			if ( $review['language'] ) { echo '<p>' . esc_html__( 'Original language:', 'ajcore' ) . ' ' . esc_html( $review['language'] ) . '</p>'; }
			if ( $review['translated_text'] ) { echo '<p>' . esc_html__( 'Translation supplied by Google:', 'ajcore' ) . '</p><p>' . nl2br( esc_html( $review['translated_text'] ) ) . '</p><p>' . esc_html( $review['translation_status'] ) . '</p>'; }
			echo '</details>';
			$source = $review['source_url'] ?: ( $data['summary']['maps_url'] ?? '' );
			if ( $source ) { echo '<p><a href="' . esc_url( $source ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $review['source_url'] ? __( 'Open original review on Google', 'ajcore' ) : __( 'View business on Google Maps (individual review link unavailable)', 'ajcore' ) ) . '</a></p>'; }
			self::form( 'feature' ); echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '"><label><input type="checkbox" name="featured" value="1" ' . checked( isset( $selection[$key] ), true, false ) . '> ' . esc_html__( 'Show on the site', 'ajcore' ) . '</label> <label>' . esc_html__( 'Display order', 'ajcore' ) . ' <input type="number" name="order" min="-10000" max="10000" value="' . (int) ( $selection[$key] ?? 0 ) . '"></label>'; submit_button( __( 'Save Selection', 'ajcore' ), 'secondary' ); echo '</form></section>';
		}
		if ( $pages > 1 ) { echo '<p>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'review_page', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ) . '</p>'; }
	}

	private static function manual_list( $featured_only ) {
		$posts = get_posts( array( 'post_type' => AJCore_Testimonials::TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private' ), 'numberposts' => 100, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true ) );
		$rows = array();
		foreach ( $posts as $post ) {
			$meta = AJCore_Testimonials::sanitize( get_post_meta( $post->ID, AJCore_Testimonials::META, true ) );
			$live = ! empty( $meta['featured'] ) && $post->post_status === 'publish';
			if ( $featured_only && ! $live ) { continue; }
			$rows[] = array( 'post' => $post, 'meta' => $meta, 'live' => $live );
		}
		echo '<h2>' . esc_html__( 'Manual testimonials', 'ajcore' ) . ' <span class="count">(' . count( $rows ) . ')</span></h2>';
		if ( ! $rows ) { echo '<p>' . esc_html__( 'No manual testimonials yet. Use Add Manual Testimonial above.', 'ajcore' ) . '</p>'; return; }
		foreach ( $rows as $row ) {
			$post = $row['post']; $meta = $row['meta'];
			$rating = $meta['rating'] ? ' — ' . (int) $meta['rating'] . '/5' : '';
			echo '<section class="card" style="max-width:900px"><h3>' . esc_html( wp_strip_all_tags( $post->post_title ) ?: __( '(untitled)', 'ajcore' ) ) . esc_html( $rating ) . ( $row['live'] ? ' <span style="font-size:12px;color:#1d4ed8">' . esc_html__( '· on the site', 'ajcore' ) . '</span>' : ' <span style="font-size:12px;color:#8c8f94">' . esc_html( $post->post_status === 'publish' ? __( '· not featured', 'ajcore' ) : $post->post_status ) . '</span>' ) . '</h3>';
			echo '<p>' . nl2br( esc_html( wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 60 ) ) ) . '</p>';
			$facts = array_filter( array( $meta['organization'], $meta['date'], $meta['source_label'] ) );
			if ( $facts ) { echo '<p>' . esc_html( implode( ' · ', $facts ) ) . '</p>'; }
			echo '<p><a class="button button-secondary" href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'Edit testimonial', 'ajcore' ) . '</a></p></section>';
		}
	}

	public static function message( $code ) {
		$messages = array(
			'success' => __( 'Operation completed.', 'ajcore' ),
			'connection_ok' => __( 'Connection test passed.', 'ajcore' ),
			'locations_loaded' => __( 'Locations loaded. Choose one and press Use This Location.', 'ajcore' ),
			'location_selected' => __( 'Location saved.', 'ajcore' ), 'busy' => __( 'Another reviews operation is running. Try again shortly.', 'ajcore' ),
			'credentials_required' => __( 'Save a valid Google OAuth client ID and secret first.', 'ajcore' ), 'not_connected' => __( 'Connect a Google account first.', 'ajcore' ),
			'authorization_failed' => __( 'Google authorization failed. Reconnect and grant Business Profile access.', 'ajcore' ), 'refresh_token_required' => __( 'Google did not provide offline access. Reconnect with consent.', 'ajcore' ),
			'access_denied' => __( 'Google denied API access. Check project approval, enabled APIs, and location permissions.', 'ajcore' ), 'invalid_location' => __( 'Press Find Locations, then choose a location.', 'ajcore' ),
			'temporary_error' => __( 'Google is temporarily unavailable or rate limited. A bounded retry will be scheduled for sync failures.', 'ajcore' ), 'transport_error' => __( 'Google could not be reached. Check outbound HTTPS access.', 'ajcore' ),
			'snapshot_changed' => __( 'The review collection changed while it was being retrieved. The previous snapshot remains within its original expiry.', 'ajcore' ),
			'invalid_response' => __( 'The provider returned incomplete or invalid data. No partial snapshot was published.', 'ajcore' ), 'sync_limit' => __( 'The request exceeded the supported size or time limit. No partial snapshot was published.', 'ajcore' ),
			'encryption_unavailable' => __( 'Secure storage is unavailable. Enable PHP sodium; plaintext storage is refused.', 'ajcore' ), 'encryption_key_invalid' => __( 'The AJ Core encryption key is invalid. Restore it securely; do not replace it casually.', 'ajcore' ),
			'storage_failed' => __( 'Secure data could not be saved. Check database availability.', 'ajcore' ), 'forms_unavailable' => __( 'AJ Forms storage is not available on this site, so the feedback form could not be created. Create the page manually and paste its URL.', 'ajcore' ), 'oauth_state_invalid' => __( 'The authorization session is expired or does not match. Start Connect again in this browser.', 'ajcore' ),
			'disconnected' => __( 'Google disconnected. Local credentials and synchronized content removed.', 'ajcore' ), 'revoke_failed' => __( 'Local credentials and content were removed, but Google revocation could not be confirmed. Remove this app in your Google account connections.', 'ajcore' ),
		);
		return $messages[$code] ?? __( 'The operation could not be completed. Review the setup documentation and try again.', 'ajcore' );
	}
}
