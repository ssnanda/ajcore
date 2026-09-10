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
	private static function finish( $result, $tab = 'settings' ) {
		$code = is_wp_error( $result ) ? AJCore_Reviews::safe_code( $result->get_error_code() ) : 'success';
		wp_safe_redirect( add_query_arg( 'result', $code, self::url( $tab ) ) ); exit;
	}

	public static function action() {
		self::authorize();
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { wp_die( esc_html__( 'A POST request is required.', 'ajcore' ), '', array( 'response' => 405 ) ); }
		check_admin_referer( 'ajcore_reviews_action' );
		$operation = sanitize_key( self::value( 'operation' ) );
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
				case 'accounts':
					$accounts = AJCore_Reviews::provider()->accounts();
					return is_wp_error( $accounts ) ? $accounts : AJCore_Reviews_Vault::write( 'ajcore_reviews_choices', array( 'accounts' => $accounts, 'expires_at' => time() + 900 ) );
				case 'locations':
					$choices = self::choices(); $account = self::value( 'account' );
					if ( ! in_array( $account, array_column( $choices['accounts'] ?? array(), 'name' ), true ) ) { return new WP_Error( 'invalid_location' ); }
					$locations = AJCore_Reviews::provider()->locations( $account );
					if ( is_wp_error( $locations ) ) { return $locations; }
					return AJCore_Reviews_Vault::write( 'ajcore_reviews_choices', array( 'accounts' => $choices['accounts'], 'account' => $account, 'locations' => $locations, 'expires_at' => time() + 900 ) );
				case 'location':
					$choices = self::choices(); $location = self::value( 'location' );
					if ( empty( $choices['account'] ) || ! in_array( $location, array_column( $choices['locations'] ?? array(), 'name' ), true ) || ! preg_match( '#^locations/[0-9]+$#D', $location ) ) { return new WP_Error( 'invalid_location' ); }
					if ( AJCore_Reviews::config() !== array( 'account' => $choices['account'], 'location' => $location ) ) {
						foreach ( array( 'snapshot', 'selection', 'sync_meta' ) as $key ) { AJCore_Reviews_Vault::delete( 'ajcore_reviews_' . $key ); }
						AJCore_Reviews::unschedule();
						update_option( 'ajcore_reviews_config', array( 'account' => $choices['account'], 'location' => $location ), false );
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
		self::finish( $result, $operation === 'feature' ? 'reviews' : 'settings' );
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
	private static function button( $operation, $label ) { self::form( $operation ); submit_button( $label, 'secondary' ); echo '</form>'; }
	private static function time( $timestamp ) { return $timestamp ? wp_date( 'Y-m-d H:i T', $timestamp ) : __( 'Not available', 'ajcore' ); }

	/** Two screens now: everything you configure, and everything you publish. */
	private static function tab() {
		$tab = sanitize_key( self::value( 'tab', $_GET ) );
		// Bookmarks and old links from the seven-tab layout.
		$legacy = array( 'overview' => 'settings', 'connection' => 'settings', 'display' => 'settings', 'history' => 'settings', 'inbox' => 'reviews', 'featured' => 'reviews', 'manual' => 'reviews' );
		$tab = $legacy[$tab] ?? $tab;
		return in_array( $tab, array( 'settings', 'reviews' ), true ) ? $tab : 'settings';
	}

	public static function page() {
		self::authorize();
		$tabs = array( 'settings' => __( 'Settings', 'ajcore' ), 'reviews' => __( 'Reviews & Testimonials', 'ajcore' ) );
		$tab  = self::tab();
		echo '<div class="wrap"><h1>' . esc_html__( 'Reviews & Testimonials', 'ajcore' ) . '</h1><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Reviews sections', 'ajcore' ) . '">';
		foreach ( $tabs as $key => $label ) { echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		if ( self::value( 'result', $_GET ) ) { echo '<div class="notice notice-info"><p>' . esc_html( self::message( AJCore_Reviews::safe_code( self::value( 'result', $_GET ) ) ) ) . '</p></div>'; }
		if ( $tab === 'settings' ) { self::settings(); } else { self::collection(); }
		echo '</div>';
	}

	/* ---------------------------------------------------------------- Settings */

	private static function settings() {
		self::status_panel();
		echo '<h2>' . esc_html__( 'Google connection', 'ajcore' ) . '</h2>';
		self::connection();
		echo '<hr><h2>' . esc_html__( 'Rate Us prompt and display', 'ajcore' ) . '</h2>';
		self::display_settings();
	}

	private static function status_panel() {
		$status = AJCore_Reviews::status(); $meta = (array) get_option( 'ajcore_reviews_sync_meta', array() );
		echo '<table class="widefat striped" style="max-width:900px;margin-top:1em"><tbody>';
		$rows = array(
			__( 'Connection and content status', 'ajcore' ) => esc_html( $status['state'] ) . ( $status['stale'] ? ' — ' . esc_html__( 'Stale: refresh needed', 'ajcore' ) : '' ),
			__( 'Valid synchronized reviews', 'ajcore' )    => (int) $status['valid_count'],
			__( 'Last successful sync', 'ajcore' )          => esc_html( self::time( $status['last_success'] ) ),
			__( 'Next scheduled sync', 'ajcore' )           => esc_html( self::time( wp_next_scheduled( AJCore_Reviews::SYNC ) ) ),
			__( 'Content expires', 'ajcore' )               => esc_html( self::time( $status['expires_at'] ) ),
		);
		if ( ! empty( $meta['last_error'] ) ) { $rows[ __( 'Last error', 'ajcore' ) ] = esc_html( self::message( $meta['last_error'] ) ); }
		foreach ( $rows as $label => $value ) { echo '<tr><th scope="row" style="width:16em">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; }
		echo '</tbody></table>';
		// Replaces the old Sync History tab: same rows, folded away until asked for.
		$history = array_slice( array_reverse( (array) get_option( 'ajcore_reviews_history', array() ) ), 0, 10 );
		if ( $history ) {
			echo '<details style="margin:1em 0;max-width:900px"><summary>' . esc_html__( 'Recent sync activity', 'ajcore' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'ajcore' ) . '</th><th>' . esc_html__( 'Result', 'ajcore' ) . '</th><th>' . esc_html__( 'Reviews', 'ajcore' ) . '</th><th>' . esc_html__( 'Seconds', 'ajcore' ) . '</th><th>' . esc_html__( 'Trigger', 'ajcore' ) . '</th></tr></thead><tbody>';
			foreach ( $history as $row ) { echo '<tr><td>' . esc_html( self::time( $row['timestamp'] ) ) . '</td><td>' . esc_html( self::message( $row['status'] ) ) . ' <code>' . esc_html( $row['status'] ) . '</code></td><td>' . (int) $row['count'] . '</td><td>' . esc_html( $row['duration'] ) . '</td><td>' . esc_html( $row['trigger'] ) . '</td></tr>'; }
			echo '</tbody></table></details>';
		}
		echo '<p class="description">' . esc_html__( 'Refresh runs every 28 days. Selected reviews are disclosed as business-selected. Read docs/reviews-testimonials.md for Google access requirements, retention, cache exclusions, and policy limitations.', 'ajcore' ) . '</p>';
	}

	private static function connection() {
		$c = AJCore_Reviews_Vault::read( 'ajcore_reviews_credentials' ); $config = AJCore_Reviews::config();
		echo '<p>' . esc_html__( 'Authorized redirect URI:', 'ajcore' ) . ' <code>' . esc_html( AJCore_Google_Review_Provider::redirect_uri() ) . '</code></p><p>' . esc_html__( 'Authorized Google account:', 'ajcore' ) . ' ' . esc_html( $c['email'] ?? __( 'Not identified', 'ajcore' ) ) . '</p><p>' . esc_html__( 'Selected business / location:', 'ajcore' ) . ' <code>' . esc_html( ( $config['account'] ?? '' ) . ' / ' . ( $config['location'] ?? '' ) ) . '</code></p>';

		// Where the two credentials come from. Only stable entry points are linked;
		// the in-console path is spelled out because Google moves its deep links.
		echo '<details style="max-width:900px;margin:1em 0;padding:.6em 1em;background:#fff;border:1px solid #c3c4c7"><summary><strong>' . esc_html__( 'Where do I get the client ID and secret?', 'ajcore' ) . '</strong></summary><ol>';
		echo '<li>' . esc_html__( 'Open the Google Cloud console and pick (or create) the project that owns this integration.', 'ajcore' ) . ' <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">console.cloud.google.com</a></li>';
		echo '<li>' . esc_html__( 'Enable the Business Profile APIs for that project, and request Business Profile API access from Google if you have not already — approval is required before any review can be read.', 'ajcore' ) . '</li>';
		echo '<li>' . esc_html__( 'Go to APIs & Services → OAuth consent screen and complete it, including the privacy policy, terms, authorized domain, and test users while in testing.', 'ajcore' ) . '</li>';
		echo '<li>' . esc_html__( 'Go to APIs & Services → Credentials → Create credentials → OAuth client ID, and choose the Web application type.', 'ajcore' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the authorized redirect URI shown above into that client\'s Authorized redirect URIs list, exactly as displayed.', 'ajcore' ) . '</li>';
		echo '<li>' . esc_html__( 'Google then shows the client ID (ending in .apps.googleusercontent.com) and the client secret. Copy both into the fields below.', 'ajcore' ) . '</li>';
		echo '</ol><p>' . esc_html__( 'Google reference:', 'ajcore' ) . ' <a href="https://developers.google.com/my-business/content/implement-oauth" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Business Profile OAuth', 'ajcore' ) . '</a> · <a href="https://developers.google.com/identity/protocols/oauth2/web-server" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Web server OAuth 2.0', 'ajcore' ) . '</a></p></details>';

		self::form( 'credentials' );
		echo '<p><label>' . esc_html__( 'OAuth client ID', 'ajcore' ) . '<br><input class="large-text" name="client_id" value="' . esc_attr( $c['client_id'] ?? '' ) . '" required autocomplete="off" placeholder="000000000000-abcdefghijklmnop.apps.googleusercontent.com"></label><br><span class="description">' . esc_html__( 'Ends in .apps.googleusercontent.com. From APIs & Services → Credentials → your Web application client.', 'ajcore' ) . '</span></p>';
		echo '<p><label>' . esc_html__( 'OAuth client secret (leave empty to retain the saved secret)', 'ajcore' ) . '<br><input class="regular-text" type="password" name="client_secret" value="" autocomplete="new-password"></label><br><span class="description">' . esc_html__( 'Shown by Google once, beside the client ID. If you have lost it, use that client\'s Reset secret and paste the new one here.', 'ajcore' ) . '</span></p>';
		echo '<p>' . esc_html__( 'Changing credentials disconnects the previous account. Secret and tokens are never displayed.', 'ajcore' ) . '</p>';
		submit_button( __( 'Save Credentials', 'ajcore' ) ); echo '</form>';
		foreach ( array( 'connect' => __( 'Connect Google Account', 'ajcore' ), 'disconnect' => __( 'Disconnect Google Account and remove local credentials', 'ajcore' ), 'accounts' => __( 'Load Business Accounts', 'ajcore' ), 'test' => __( 'Test Connection', 'ajcore' ) ) as $key => $label ) { self::button( $key, $label ); }
		$choices = self::choices();
		if ( ! empty( $choices['accounts'] ) ) {
			self::form( 'locations' ); echo '<label>' . esc_html__( 'Business account', 'ajcore' ) . ' <select name="account">';
			foreach ( $choices['accounts'] as $account ) { echo '<option value="' . esc_attr( $account['name'] ) . '" ' . selected( $choices['account'] ?? '', $account['name'], false ) . '>' . esc_html( ( $account['accountName'] ?? $account['name'] ) . ' (' . $account['name'] . ')' ) . '</option>'; }
			echo '</select></label>'; submit_button( __( 'Load Locations', 'ajcore' ), 'secondary' ); echo '</form>';
		}
		if ( ! empty( $choices['locations'] ) ) {
			self::form( 'location' ); echo '<label>' . esc_html__( 'Location', 'ajcore' ) . ' <select name="location">';
			foreach ( $choices['locations'] as $location ) { echo '<option value="' . esc_attr( $location['name'] ) . '">' . esc_html( ( $location['title'] ?? $location['name'] ) . ' (' . $location['name'] . ')' ) . '</option>'; }
			echo '</select></label>'; submit_button( __( 'Use This Location', 'ajcore' ), 'secondary' ); echo '</form>';
		}
	}

	private static function display_settings() {
		$data   = ajcore_get_reviews_display_settings();
		$prompt = ajcore_sanitize_review_prompt_settings( get_option( 'ajcore_reviews_display', array() ) );
		$suggested = AJCore_Reviews_Setup::suggested_url();
		$has_page  = AJCore_Reviews_Setup::page_exists();

		echo '<p>' . esc_html__( 'In AJNanda, stars 1–4 open the private feedback page and star 5 opens the Google review link. The rating is not submitted to either destination. Feedback is not automatically published as a testimonial. Where the stars sit on the page is a theme setting, in Appearance → Customize → Reviews & Testimonials.', 'ajcore' ) . '</p>';

		// The feedback page is content, so it gets its own form and its own button.
		echo '<div class="card" style="max-width:900px"><h3 style="margin-top:0">' . esc_html__( 'Private feedback page', 'ajcore' ) . '</h3>';
		if ( $has_page ) {
			echo '<p>' . esc_html__( 'This page already exists:', 'ajcore' ) . ' <a href="' . esc_url( $suggested ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $suggested ) . '</a>';
			$form_id = AJCore_Reviews_Setup::form_id();
			if ( $form_id ) { echo ' — ' . esc_html( sprintf( __( 'AJ Forms form #%d', 'ajcore' ), $form_id ) ); }
			echo '</p>';
		} else {
			echo '<p>' . esc_html__( 'Stars 1–4 need somewhere private to land. AJ Core can build both halves for you: an AJ Forms feedback form, and a page that renders it.', 'ajcore' ) . '</p>';
			echo '<p>' . esc_html__( 'It will be created at:', 'ajcore' ) . ' <code>' . esc_html( $suggested ) . '</code></p>';
		}
		if ( AJCore_Reviews_Setup::forms_available() ) {
			self::button( 'provision', $has_page ? __( 'Recreate anything missing', 'ajcore' ) : __( 'Create the feedback page and form', 'ajcore' ) );
			echo '<p class="description">' . esc_html__( 'Nothing is overwritten: an existing form or page is reused, and a page that already renders a form is left alone. Submissions arrive under AJ Core → Leads.', 'ajcore' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'AJ Forms storage is not available on this site, so the form cannot be created automatically. Build the page yourself and paste its URL below.', 'ajcore' ) . '</p>';
		}
		echo '</div>';

		self::form( 'display' );
		echo '<h3>' . esc_html__( 'Rate Us header prompt', 'ajcore' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="prompt_enabled" value="1" ' . checked( $prompt['prompt_enabled'], true, false ) . '> ' . esc_html__( 'Enable the Rate Us header prompt', 'ajcore' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'Prompt label (defaults to Rate Us)', 'ajcore' ) . '<br><input class="large-text" type="text" name="prompt_label" value="' . esc_attr( $prompt['prompt_label'] ) . '" placeholder="' . esc_attr__( 'Rate Us', 'ajcore' ) . '"></label></p>';

		// Auto-filled once the page exists; before that the suggestion is only a
		// placeholder, so nobody saves a URL that would 404.
		$feedback_value = $prompt['feedback_url'] !== '' ? $prompt['feedback_url'] : ( $has_page ? $suggested : '' );
		echo '<p><label>' . esc_html__( 'Private feedback page URL (HTTPS)', 'ajcore' ) . '<br><input class="large-text" type="url" name="feedback_url" value="' . esc_attr( $feedback_value ) . '" placeholder="' . esc_attr( $suggested ) . '"></label><br><span class="description">' . esc_html__( 'Filled in for you when you create the page above. Must be HTTPS — an http:// URL is discarded on save.', 'ajcore' ) . '</span></p>';

		echo '<p><label>' . esc_html__( 'Google Write a Review URL (HTTPS; optional override)', 'ajcore' ) . '<br><input class="large-text" type="url" name="google_review_url" value="' . esc_attr( $prompt['google_review_url'] ) . '" placeholder="https://search.google.com/local/writereview?placeid=ChIJ..."></label><br><span class="description">' . esc_html__( 'Leave empty and the connected location supplies its own link. To override, use the Write a Review link for your Place ID. Example:', 'ajcore' ) . ' <code>https://search.google.com/local/writereview?placeid=ChIJN1t_tDeuEmsRUsoyG83frY4</code><br>' . esc_html__( 'Find your Place ID in the Google Business Profile manager for the location, or from the share link Google Maps gives that listing.', 'ajcore' ) . '</span></p>';

		echo '<h3>' . esc_html__( 'Collection display', 'ajcore' ) . '</h3>';
		echo '<p><label>' . esc_html__( 'Frontend fallback (empty by default)', 'ajcore' ) . '<br><input class="large-text" name="fallback" value="' . esc_attr( $data['fallback'] ) . '"></label><br><span class="description">' . esc_html__( 'Shown when a review block has nothing to display.', 'ajcore' ) . '</span></p>';
		echo '<p><label>' . esc_html__( 'Default featured ordering', 'ajcore' ) . ' <select name="order"><option value="manual" ' . selected( $data['order'], 'manual', false ) . '>' . esc_html__( 'Business display order', 'ajcore' ) . '</option><option value="date" ' . selected( $data['order'], 'date', false ) . '>' . esc_html__( 'Publication date, newest first', 'ajcore' ) . '</option></select></label></p>';
		echo '<p class="description">' . esc_html__( 'The prompt stays hidden unless both destinations are available. AJNanda displays it automatically; other themes must use the documented PHP integration. These links do not create or connect a submission form.', 'ajcore' ) . '</p>';
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

		echo '<p>' . esc_html__( 'Select reviews neutrally. Nothing is featured automatically. Google review text and attribution cannot be edited. Selection is disclosed publicly.', 'ajcore' ) . '</p>';
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
		if ( ! $total ) { echo '<p>' . esc_html__( 'No valid Google reviews. Connect a location on the Settings tab, then Sync Google Reviews Now.', 'ajcore' ) . '</p>'; return; }
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
		echo '<p class="description">' . esc_html__( 'Administrator-written content, independent of Google. A testimonial appears on the site only when it is published and marked Featured. Google reviews are never converted into testimonials.', 'ajcore' ) . '</p>';
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
			'success' => __( 'Operation completed.', 'ajcore' ), 'busy' => __( 'Another reviews operation is running. Try again shortly.', 'ajcore' ),
			'credentials_required' => __( 'Save a valid Google OAuth client ID and secret first.', 'ajcore' ), 'not_connected' => __( 'Connect a Google account first.', 'ajcore' ),
			'authorization_failed' => __( 'Google authorization failed. Reconnect and grant Business Profile access.', 'ajcore' ), 'refresh_token_required' => __( 'Google did not provide offline access. Reconnect with consent.', 'ajcore' ),
			'access_denied' => __( 'Google denied API access. Check project approval, enabled APIs, and location permissions.', 'ajcore' ), 'invalid_location' => __( 'Load business accounts and select an authorized location.', 'ajcore' ),
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
