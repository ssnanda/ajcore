<?php
/**
 * AJ Core REST API.
 *
 * Provides API endpoints for OPS and customer-facing apps while respecting
 * AJ Core shared DB / multi-site master rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJCore_REST_API {

	const NAMESPACE = 'ajcore/v1';

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'can_view_status' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/docs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_docs' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_create_customer' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'name'            => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'email'           => array( 'required' => true,  'sanitize_callback' => 'sanitize_email' ),
					'phone'           => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'description'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'business_name'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'individual_name' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_line1'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_line2'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_city'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_state'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_postal'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_country'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers/(?P<stripe_customer_id>cus_[A-Za-z0-9_\-]+)',
			array(
				'methods'             => 'POST, PUT, PATCH',
				'callback'            => array( $this, 'ops_update_customer' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'name'            => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'email'           => array( 'required' => true,  'sanitize_callback' => 'sanitize_email' ),
					'phone'           => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'description'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'business_name'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'individual_name' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_line1'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_line2'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_city'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_state'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_postal'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'addr_country'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers/(?P<stripe_customer_id>cus_[A-Za-z0-9_\-]+)/action',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_customer_action' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'action' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_trigger_sync' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		foreach ( $this->get_route_map() as $route => $args ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $args['methods'],
					'callback'            => array( $this, $args['callback'] ),
					'permission_callback' => array( $this, $args['permission'] ),
					'args'                => isset( $args['args'] ) ? $args['args'] : array(),
				)
			);
		}
	}

	private function get_route_map() {
		$read_args = array(
			'per_page' => array(
				'default'           => 25,
				'sanitize_callback' => 'absint',
			),
			'search' => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		return array(
			'/ops/summary' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_summary', 'permission' => 'can_manage_ops_api' ),
			'/ops/customers' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_customers', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/customers/(?P<stripe_customer_id>cus_[A-Za-z0-9_\-]+)' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_customer', 'permission' => 'can_manage_ops_api' ),
			'/ops/products' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_products', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/subscriptions' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_subscriptions', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/ledger' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_ledger', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/tasks' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_tasks', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/service-requests' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_service_requests', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/sync-logs' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_sync_logs', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/event-log' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_event_log', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			// OPS staff auth (login validates ajcore_ops_access before issuing JWT)
			'/ops/auth/login'  => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'ops_auth_login',  'permission' => 'public_permission' ),
			'/ops/auth/logout' => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'ops_auth_logout', 'permission' => 'can_manage_ops_api' ),
			'/ops/auth/me'     => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_ops_me',      'permission' => 'can_manage_ops_api' ),
			// Mobile customer auth (login is public so anyone can obtain a JWT)
			'/portal/auth/login'  => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_auth_login',  'permission' => 'public_permission' ),
			'/portal/auth/logout' => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_auth_logout', 'permission' => 'can_use_portal_api' ),
			'/portal/auth/me'     => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_me',      'permission' => 'can_use_portal_api' ),
			// Portal data routes used by the mobile app
			'/portal/me'              => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_me',       'permission' => 'can_use_portal_api' ),
			'/portal/profile'         => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_me',       'permission' => 'can_use_portal_api' ),
			'/portal/overview'        => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_overview', 'permission' => 'can_use_portal_api' ),
			'/portal/dashboard'       => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_dashboard','permission' => 'can_use_portal_api' ),
			'/portal/services'        => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_services', 'permission' => 'can_use_portal_api' ),
			'/portal/billing/invoices'     => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_billing_invoices',     'permission' => 'can_use_portal_api' ),
			'/portal/billing/transactions' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_billing_transactions',  'permission' => 'can_use_portal_api' ),
			'/portal/billing'         => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_billing', 'permission' => 'can_use_portal_api' ),
			'/portal/tasks'           => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_tasks',              'permission' => 'can_use_portal_api' ),
			'/portal/service-requests/create' => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'create_portal_service_request', 'permission' => 'can_use_portal_api' ),
			'/portal/files'           => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_files',         'permission' => 'can_use_portal_api' ),
			'/portal/service-requests'=> array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_service_requests', 'permission' => 'can_use_portal_api' ),
			'/portal/store'           => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_store',       'permission' => 'can_use_portal_api' ),
			'/portal/store/checkout'  => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_store_checkout', 'permission' => 'can_use_portal_api' ),
			'/portal/cart'            => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_cart',      'permission' => 'can_use_portal_api' ),
			'/portal/cart/add'        => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_cart_add',      'permission' => 'can_use_portal_api' ),
			'/portal/cart/remove'     => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_cart_remove',   'permission' => 'can_use_portal_api' ),
			'/portal/cart/clear'      => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_cart_clear',    'permission' => 'can_use_portal_api' ),
			'/portal/cart/checkout'   => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_cart_checkout', 'permission' => 'can_use_portal_api' ),
			// Public (no auth) — product catalog for the non-logged-in home screen
			'/public/services'        => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_public_services',      'permission' => 'public_permission' ),
			// Portal reservation endpoints (sub-routes before bare /portal/reservations)
			'/portal/reservations/config'           => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'portal_reservations_config',       'permission' => 'can_use_portal_api' ),
			'/portal/reservations/availability'     => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'portal_reservations_availability',  'permission' => 'can_use_portal_api' ),
			'/portal/reservations/quote'            => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_reservations_quote',         'permission' => 'can_use_portal_api' ),
			'/portal/reservations/cart/add'         => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_reservations_cart_add',      'permission' => 'can_use_portal_api' ),
			'/portal/reservations/cart/remove'      => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_reservations_cart_remove',   'permission' => 'can_use_portal_api' ),
			'/portal/reservations/cart/checkout'    => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'portal_reservations_cart_checkout', 'permission' => 'can_use_portal_api' ),
			'/portal/reservations/cart'             => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'portal_reservations_get_cart',      'permission' => 'can_use_portal_api' ),
			'/portal/reservations/(?P<id>[0-9]+)'   => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'portal_get_reservation',            'permission' => 'can_use_portal_api' ),
			'/portal/reservations'                  => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'portal_get_reservations',            'permission' => 'can_use_portal_api' ),
			// OPS read-only reservation endpoints
			'/ops/reservations/(?P<id>[0-9]+)'      => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'ops_get_reservation',               'permission' => 'can_manage_ops_api' ),
			'/ops/reservations'                     => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'ops_get_reservations',               'permission' => 'can_manage_ops_api' ),
		);
	}

	public static function get_default_api_settings() {
		return array(
			'enabled'             => '1',
			'ops_enabled'         => '1',
			'portal_enabled'      => '1',
			'public_status'       => '1',
			'master_only'         => '1',
			'portal_master_only'  => '0',
			'require_https_notes' => '1',
		);
	}

	public static function get_api_settings() {
		$settings = get_option( 'ajcore_api_settings', array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::get_default_api_settings() );
	}

	public static function get_endpoint_catalog() {
		return array(
			array( 'surface' => 'System', 'method' => 'GET', 'path' => '/status', 'auth' => 'Public or Admin, based on API settings', 'purpose' => 'Health check, version, site UUID, shared DB and master-site status.', 'app' => 'OPS / diagnostics' ),
			array( 'surface' => 'System', 'method' => 'GET', 'path' => '/docs', 'auth' => 'Admin', 'purpose' => 'Machine-readable API catalog.', 'app' => 'OPS / developer tools' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/summary', 'auth' => 'Admin', 'purpose' => 'Counts for customers, products, subscriptions, ledger, tasks, service requests and sync logs.', 'app' => 'OPS dashboard' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/customers', 'auth' => 'Admin', 'purpose' => 'Customer list with portal status and Stripe customer references.', 'app' => 'OPS customers' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/customers/{stripe_customer_id}', 'auth' => 'Admin', 'purpose' => 'Single customer profile with subscriptions, ledger, service requests and tasks.', 'app' => 'OPS customer 360' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/products', 'auth' => 'Admin', 'purpose' => 'Synced Stripe/product catalog rows for product management.', 'app' => 'OPS catalog' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/subscriptions', 'auth' => 'Admin', 'purpose' => 'Subscription list for services and renewals.', 'app' => 'OPS services' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/ledger', 'auth' => 'Admin', 'purpose' => 'Billing ledger entries.', 'app' => 'OPS billing' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/tasks', 'auth' => 'Admin', 'purpose' => 'Task definitions and customer task statuses.', 'app' => 'OPS tasks' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/service-requests', 'auth' => 'Admin', 'purpose' => 'Service request queue.', 'app' => 'OPS service desk' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/sync-logs', 'auth' => 'Admin', 'purpose' => 'Stripe/sync job history.', 'app' => 'OPS sync center' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/event-log', 'auth' => 'Admin', 'purpose' => 'Portal event/audit log.', 'app' => 'OPS audit' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/me', 'auth' => 'Portal user or Admin', 'purpose' => 'Current WordPress user and linked customer identity.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/dashboard', 'auth' => 'Portal user or Admin', 'purpose' => 'Mobile dashboard summary for services, billing, tasks, files and requests.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/services', 'auth' => 'Portal user or Admin', 'purpose' => 'Current user service/subscription list.', 'app' => 'iOS services' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/billing', 'auth' => 'Portal user or Admin', 'purpose' => 'Current user ledger/billing history.', 'app' => 'iOS billing' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/tasks', 'auth' => 'Portal user or Admin', 'purpose' => 'Current user tasks and statuses.', 'app' => 'iOS tasks' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/files', 'auth' => 'Portal user or Admin', 'purpose' => 'Current user assigned/uploaded files metadata.', 'app' => 'iOS files' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/service-requests', 'auth' => 'Portal user or Admin', 'purpose' => 'Current user service requests.', 'app' => 'iOS service requests' ),
			// Reservation portal endpoints
			array( 'surface' => 'Portal', 'method' => 'GET',  'path' => '/portal/reservations/config',        'auth' => 'Portal user', 'purpose' => 'Reservation config: enabled status, resource name, timezone, rates, booking window, hold minutes, policy text, Zoho configured flag.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'GET',  'path' => '/portal/reservations',               'auth' => 'Portal user', 'purpose' => 'Current user reservations (excludes in_cart and admin_archived rows).', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'GET',  'path' => '/portal/reservations/availability',  'auth' => 'Portal user', 'purpose' => 'Slot availability check (booking window + local conflict + Zoho). Query params: resource_key, start_at, end_at.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'POST', 'path' => '/portal/reservations/quote',         'auth' => 'Portal user', 'purpose' => 'Pricing quote for a slot: pricing_type, pricing_label, duration_minutes, amount, currency, available (bool), message. Zoho calendar check uses lenient mode — failures are logged, not surfaced to the user.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'POST', 'path' => '/portal/reservations/cart/add',      'auth' => 'Portal user', 'purpose' => 'Add slot to reservation cart. Body: resource_key, start_at, end_at, customer_phone, customer_notes.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'GET',  'path' => '/portal/reservations/cart',          'auth' => 'Portal user', 'purpose' => 'Cart items with per-item and grand total pricing.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'POST', 'path' => '/portal/reservations/cart/remove',   'auth' => 'Portal user', 'purpose' => 'Remove slot from cart. Body: reservation_uuid.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'POST', 'path' => '/portal/reservations/cart/checkout', 'auth' => 'Portal user', 'purpose' => 'Stripe checkout for all cart items. Returns checkout_url, session_id, publishable_key.', 'app' => 'iOS app' ),
			array( 'surface' => 'Portal', 'method' => 'GET',  'path' => '/portal/reservations/{id}',          'auth' => 'Portal user', 'purpose' => 'Single reservation by numeric ID — only returned if it belongs to the current portal user.', 'app' => 'iOS app' ),
			// Reservation OPS endpoints
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/reservations',       'auth' => 'Admin', 'purpose' => 'All reservations with optional filters: status, resource_key, date_from, date_to, per_page.', 'app' => 'OPS reservations' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/reservations/{id}',  'auth' => 'Admin', 'purpose' => 'Single reservation detail (admin view — includes Stripe/Zoho IDs, customer notes, admin notes).', 'app' => 'OPS reservations' ),
		);
	}

	public function public_permission() {
		return true;
	}

	public function can_view_status() {
		$settings = self::get_api_settings();
		if ( '1' === (string) $settings['public_status'] ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	private function protected_api_enabled( $surface ) {
		$settings = self::get_api_settings();
		if ( '1' !== (string) $settings['enabled'] ) {
			return new WP_Error( 'ajcore_api_disabled', __( 'AJ Core API is disabled in API settings.', 'ajforms' ), array( 'status' => 403 ) );
		}
		if ( 'ops' === $surface && '1' !== (string) $settings['ops_enabled'] ) {
			return new WP_Error( 'ajcore_ops_api_disabled', __( 'AJ Core OPS API is disabled in API settings.', 'ajforms' ), array( 'status' => 403 ) );
		}
		if ( 'portal' === $surface && '1' !== (string) $settings['portal_enabled'] ) {
			return new WP_Error( 'ajcore_portal_api_disabled', __( 'AJ Core portal API is disabled in API settings.', 'ajforms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private function is_master_api_site( $surface = 'ops' ) {
		$settings    = self::get_api_settings();
		$setting_key = ( 'portal' === $surface ) ? 'portal_master_only' : 'master_only';
		if ( '1' !== (string) $settings[ $setting_key ] ) {
			return true;
		}
		if ( ! function_exists( 'ajcore_is_multisite_portal_enabled' ) || ! ajcore_is_multisite_portal_enabled() ) {
			return true;
		}
		return function_exists( 'ajcore_is_stripe_sync_owner' ) && ajcore_is_stripe_sync_owner();
	}

	private function master_only_error() {
		return new WP_Error(
			'ajcore_api_not_master',
			__( 'AJ Core API is only available on the master site in multi-site portal mode.', 'ajforms' ),
			array( 'status' => 403 )
		);
	}

	public function can_manage_ops_api() {
		$enabled = $this->protected_api_enabled( 'ops' );
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		if ( ! $this->is_master_api_site( 'ops' ) ) {
			return $this->master_only_error();
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ajcore_api_auth_required', __( 'Authentication required.', 'ajforms' ), array( 'status' => 401 ) );
		}
		$user = wp_get_current_user();
		if ( current_user_can( 'manage_options' )
			|| user_can( $user, 'ajcore_ops_access' )
			|| in_array( 'aj_ops_user', (array) $user->roles, true )
		) {
			return true;
		}
		return new WP_Error( 'ajcore_api_forbidden', __( 'This account does not have OPS API access.', 'ajforms' ), array( 'status' => 403 ) );
	}

	public function can_use_portal_api() {
		$enabled = $this->protected_api_enabled( 'portal' );
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		if ( ! $this->is_master_api_site( 'portal' ) ) {
			return $this->master_only_error();
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ajcore_api_auth_required', __( 'Authentication required.', 'ajforms' ), array( 'status' => 401 ) );
		}
		$user = wp_get_current_user();
		if ( current_user_can( 'manage_options' ) || user_can( $user, 'ajcore_customer_portal_access' ) || in_array( 'aj_portal_user', (array) $user->roles, true ) ) {
			return true;
		}
		return new WP_Error( 'ajcore_api_forbidden', __( 'This account does not have portal API access.', 'ajforms' ), array( 'status' => 403 ) );
	}

	public function get_status() {
		$shared_enabled    = function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled();
		$ms_enabled        = function_exists( 'ajcore_is_multisite_portal_enabled' ) && ajcore_is_multisite_portal_enabled();
		$ops_available     = $this->is_master_api_site( 'ops' );
		$portal_available  = $this->is_master_api_site( 'portal' );
		$settings          = self::get_api_settings();

		return rest_ensure_response(
			array(
				'plugin'                          => 'ajcore',
				'version'                         => defined( 'AJCORE_VERSION' ) ? AJCORE_VERSION : '',
				'site_url'                        => home_url( '/' ),
				'site_uuid'                       => get_option( 'ajcore_site_uuid', '' ),
				'shared_db_enabled'               => (bool) $shared_enabled,
				'multisite_portal_enabled'        => (bool) $ms_enabled,
				'is_master_api_site'              => (bool) $ops_available,
				'api_available_on_this_site'      => (bool) $ops_available,
				'ops_available_on_this_site'      => (bool) $ops_available,
				'portal_available_on_this_site'   => (bool) $portal_available,
				'api_enabled'                     => '1' === (string) $settings['enabled'],
				'ops_api_enabled'                 => '1' === (string) $settings['ops_enabled'],
				'portal_api_enabled'              => '1' === (string) $settings['portal_enabled'],
			)
		);
	}

	public function get_docs() {
		return rest_ensure_response(
			array(
				'namespace' => self::NAMESPACE,
				'base_url'  => rest_url( self::NAMESPACE ),
				'endpoints' => self::get_endpoint_catalog(),
			)
		);
	}

	public function get_ops_summary() {
		$pdb = $this->get_portal_db();
		return rest_ensure_response(
			array(
				'customers'        => $this->count_table( $pdb, $this->portal_table( 'aj_portal_stripe_customers' ) ),
				'customer_states'  => $this->count_table( $pdb, $this->portal_table( 'aj_portal_customer_states' ) ),
				'products'         => $this->count_table( $pdb, $this->portal_table( 'aj_portal_stripe_products' ) ),
				'subscriptions'    => $this->count_table( $pdb, $this->portal_table( 'aj_portal_stripe_subscriptions' ) ),
				'ledger'           => $this->count_table( $pdb, $this->portal_table( 'aj_portal_ledger' ) ),
				'tasks'            => $this->count_table( $pdb, $this->portal_table( 'aj_portal_tasks' ) ),
				'service_requests' => $this->count_table( $pdb, $this->portal_table( 'aj_portal_service_requests' ) ),
				'sync_logs'        => $this->count_table( $pdb, $this->portal_table( 'aj_portal_sync_logs' ) ),
			)
		);
	}

	public function get_ops_customers( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'customers' => $this->select_rows( $this->portal_table( 'aj_portal_stripe_customers' ), array( 'stripe_customer_id', 'email', 'name', 'phone', 'description', 'metadata', 'portal_status', 'enabled_portal', 'livemode', 'synced_at' ), $request, array( 'name', 'email', 'stripe_customer_id' ), 'synced_at DESC, id DESC' ) ) );
	}

	public function get_ops_customer( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$desired_cols   = array( 'stripe_customer_id', 'email', 'name', 'phone', 'description', 'address', 'metadata', 'raw_data', 'portal_status', 'enabled_portal', 'livemode', 'synced_at' );
		$select_cols    = $this->table_exists( $pdb, $customer_table ) ? $this->existing_columns( $pdb, $customer_table, $desired_cols ) : array();
		$customer       = ! empty( $select_cols ) ? $pdb->get_row( $pdb->prepare( 'SELECT `' . implode( '`,`', $select_cols ) . "` FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ), ARRAY_A ) : null;
		if ( ! $customer ) {
			return new WP_Error( 'ajcore_customer_not_found', __( 'Customer not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$decoded = $this->decode_json_fields( $customer, array( 'address', 'metadata' ) );
		// Extract business_name / individual_name from metadata; fall back to raw_data if metadata column is empty.
		$meta = is_array( $decoded['metadata'] ?? null ) ? $decoded['metadata'] : array();
		if ( empty( $meta ) && ! empty( $customer['raw_data'] ) ) {
			$raw_parsed = json_decode( (string) $customer['raw_data'], true );
			if ( is_array( $raw_parsed ) && is_array( $raw_parsed['metadata'] ?? null ) ) {
				$meta = $raw_parsed['metadata'];
				$decoded['metadata'] = $meta;
			}
		}
		$decoded['business_name']   = $meta['business_name'] ?? '';
		$decoded['individual_name'] = $meta['individual_name'] ?? '';
		unset( $decoded['raw_data'] );
		return rest_ensure_response(
			array(
				'customer'         => $decoded,
				'subscriptions'    => $this->select_by_customer( 'aj_portal_stripe_subscriptions', array( 'stripe_subscription_id', 'stripe_customer_id', 'status', 'current_period_end', 'cancel_at_period_end', 'items', 'synced_at' ), $stripe_customer_id, 'synced_at DESC, id DESC' ),
				'ledger'           => $this->select_by_customer( 'aj_portal_ledger', array( 'id', 'stripe_customer_id', 'source_type', 'source_id', 'description', 'amount', 'currency', 'status', 'transaction_date', 'due_date', 'created_at' ), $stripe_customer_id, 'created_at DESC, id DESC' ),
				'service_requests' => $this->select_by_customer( 'aj_portal_service_requests', array( 'id', 'stripe_customer_id', 'title', 'status', 'service_status', 'amount', 'currency', 'created_at', 'updated_at' ), $stripe_customer_id, 'updated_at DESC, id DESC' ),
			)
		);
	}

	public function get_ops_products( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'products' => $this->select_rows( $this->portal_table( 'aj_portal_stripe_products' ), array( 'stripe_product_id', 'stripe_price_id', 'name', 'description', 'price_amount', 'currency', 'recurring_interval', 'active', 'visibility', 'custom_label', 'sort_order', 'livemode', 'synced_at' ), $request, array( 'name', 'stripe_product_id', 'stripe_price_id' ), 'sort_order ASC, name ASC, id DESC' ) ) );
	}

	public function get_ops_subscriptions( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'subscriptions' => $this->select_rows( $this->portal_table( 'aj_portal_stripe_subscriptions' ), array( 'stripe_subscription_id', 'stripe_customer_id', 'status', 'current_period_end', 'cancel_at_period_end', 'livemode', 'synced_at' ), $request, array( 'stripe_subscription_id', 'stripe_customer_id', 'status' ), 'synced_at DESC, id DESC' ) ) );
	}

	public function get_ops_ledger( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'ledger' => $this->select_rows( $this->portal_table( 'aj_portal_ledger' ), array( 'id', 'stripe_customer_id', 'source_type', 'source_id', 'description', 'amount', 'currency', 'status', 'transaction_date', 'due_date', 'created_at' ), $request, array( 'stripe_customer_id', 'description', 'status' ), 'created_at DESC, id DESC' ) ) );
	}

	public function get_ops_tasks( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'tasks' => $this->select_rows( $this->portal_table( 'aj_portal_tasks' ), array( 'id', 'title', 'description', 'task_type', 'status', 'due_month', 'due_day', 'created_at', 'updated_at' ), $request, array( 'title', 'description', 'task_type', 'status' ), 'updated_at DESC, id DESC' ) ) );
	}

	public function get_ops_service_requests( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'service_requests' => $this->select_rows( $this->portal_table( 'aj_portal_service_requests' ), array( 'id', 'stripe_customer_id', 'title', 'status', 'service_status', 'amount', 'currency', 'source', 'created_by', 'created_at', 'updated_at' ), $request, array( 'stripe_customer_id', 'title', 'status', 'service_status' ), 'updated_at DESC, id DESC' ) ) );
	}

	public function get_ops_sync_logs( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'sync_logs' => $this->select_rows( $this->portal_table( 'aj_portal_sync_logs' ), array( 'id', 'run_key', 'job_name', 'status', 'message', 'started_at', 'finished_at', 'created_at' ), $request, array( 'run_key', 'job_name', 'status', 'message' ), 'created_at DESC, id DESC' ) ) );
	}

	public function get_ops_event_log( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'event_log' => $this->select_rows( $this->portal_table( 'aj_portal_event_log' ), array( 'id', 'event_type', 'severity', 'source', 'correlation_id', 'site_uuid', 'stripe_customer_id', 'actor_user_id', 'actor_email', 'created_at' ), $request, array( 'event_type', 'severity', 'source', 'stripe_customer_id', 'actor_email' ), 'created_at DESC, id DESC' ) ) );
	}

	public function get_portal_me() {
		$user = wp_get_current_user();
		return rest_ensure_response( array(
			'user'               => $this->format_user( $user ),
			'site_uuid'          => get_option( 'ajcore_site_uuid', '' ),
			'stripe_customer_id' => $this->get_current_user_stripe_customer_id(),
		) );
	}

	public function get_portal_dashboard() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		return rest_ensure_response(
			array(
				'stripe_customer_id' => $stripe_customer_id,
				'counts' => array(
					'services'         => count( $this->get_customer_rows( 'aj_portal_service_snapshots', array( 'id' ), $stripe_customer_id ) ),
					'billing'          => count( $this->get_customer_rows( 'aj_portal_ledger', array( 'id' ), $stripe_customer_id ) ),
					'tasks'            => count( $this->get_customer_rows( 'aj_portal_task_statuses', array( 'id' ), $stripe_customer_id ) ),
					'service_requests' => count( $this->get_customer_rows( 'aj_portal_service_requests', array( 'id' ), $stripe_customer_id ) ),
					'files'            => count( $this->get_current_user_file_rows() ),
				),
			)
		);
	}

	public function get_portal_services() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_get_portal_services() );
		}
		return rest_ensure_response( array( 'services' => array() ) );
	}

	public function get_portal_billing() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		return rest_ensure_response( array( 'ledger' => $this->get_customer_rows( 'aj_portal_ledger', array( 'id', 'stripe_customer_id', 'source_type', 'source_id', 'description', 'amount', 'currency', 'status', 'transaction_date', 'due_date', 'created_at' ), $stripe_customer_id, 'created_at DESC, id DESC' ) ) );
	}

	public function get_portal_tasks() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_get_portal_tasks() );
		}
		return rest_ensure_response( array( 'tasks' => array() ) );
	}

	public function get_portal_files() {
		return rest_ensure_response( array( 'files' => $this->get_current_user_file_rows() ) );
	}

	public function get_portal_service_requests() {
		if ( ! AJForms::$instance ) {
			return rest_ensure_response( array( 'service_requests' => array() ) );
		}
		$data = AJForms::$instance->api_get_client_service_requests();
		$rows = isset( $data['service_requests'] ) ? (array) $data['service_requests'] : array();
		$mapped = array_map( function ( $row ) {
			$row = (array) $row;
			$row['title']       = isset( $row['service_name'] ) ? $row['service_name'] : '';
			$row['description'] = isset( $row['client_notes'] ) ? $row['client_notes'] : '';
			return $row;
		}, $rows );
		return rest_ensure_response( array( 'service_requests' => $mapped ) );
	}

	public function create_portal_service_request( WP_REST_Request $request ) {
		$title       = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		$priority    = sanitize_key( (string) ( $request->get_param( 'priority' ) ?: 'normal' ) );

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'Title is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( ! AJForms::$instance ) {
			return new WP_Error( 'ajcore_unavailable', __( 'Portal service unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$result = AJForms::$instance->api_create_portal_service_request( $title, $description, $priority );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function get_portal_store() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_get_portal_store() );
		}
		return rest_ensure_response( array( 'products' => array() ) );
	}

	public function get_portal_cart() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_get_portal_cart() );
		}
		return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'currency' => 'usd', 'count' => 0 ) );
	}

	public function portal_cart_add( WP_REST_Request $request ) {
		$price_id = sanitize_text_field( (string) $request->get_param( 'price_id' ) );
		if ( '' === $price_id ) {
			return new WP_Error( 'invalid_price', __( 'A price_id is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( ! AJForms::$instance ) {
			return new WP_Error( 'ajcore_unavailable', __( 'Portal service unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$result = AJForms::$instance->api_portal_cart_add( $price_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function portal_cart_remove( WP_REST_Request $request ) {
		$price_id = sanitize_text_field( (string) $request->get_param( 'price_id' ) );
		if ( '' === $price_id ) {
			return new WP_Error( 'invalid_price', __( 'A price_id is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( ! AJForms::$instance ) {
			return new WP_Error( 'ajcore_unavailable', __( 'Portal service unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		return rest_ensure_response( AJForms::$instance->api_portal_cart_remove( $price_id ) );
	}

	public function portal_cart_clear() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_portal_cart_clear() );
		}
		return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'currency' => 'usd', 'count' => 0 ) );
	}

	public function portal_cart_checkout() {
		if ( ! AJForms::$instance ) {
			return new WP_Error( 'ajcore_unavailable', __( 'Portal service unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$result = AJForms::$instance->api_portal_cart_checkout();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function get_public_services() {
		// Returns the product catalog without requiring authentication.
		// Products are shown to non-logged-in users on the home screen.
		if ( AJForms::$instance ) {
			$data = AJForms::$instance->api_get_portal_store();
			// Strip any user-personalised flags before returning publicly.
			if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
				$data['products'] = array_map( function( $p ) {
					unset( $p['isOwned'], $p['hasOpenRequest'] );
					$p['canAdd'] = true;
					return $p;
				}, $data['products'] );
			}
			return rest_ensure_response( $data );
		}
		return rest_ensure_response( array( 'products' => array() ) );
	}

	public function portal_store_checkout( WP_REST_Request $request ) {
		$price_id = sanitize_text_field( (string) $request->get_param( 'price_id' ) );
		if ( '' === $price_id ) {
			return new WP_Error( 'invalid_price', __( 'A price_id is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( ! AJForms::$instance ) {
			return new WP_Error( 'ajcore_unavailable', __( 'Portal service unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$result = AJForms::$instance->api_portal_add_service( $price_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	// ── Portal Reservation Endpoints ─────────────────────────────────────────────

	public function portal_reservations_config() {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$settings  = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$enabled   = ! empty( $settings['zoho_reservations_enabled'] );
		$has_token = ! empty( $settings['zoho_access_token'] ) || ! empty( $settings['zoho_api_token'] );

		$biz_label  = ! empty( $settings['reservation_business_hours_label'] ) ? sanitize_text_field( $settings['reservation_business_hours_label'] ) : 'Business Hours (Mon–Fri 9am–5pm)';
		$aft_label  = ! empty( $settings['reservation_after_hours_label'] )    ? sanitize_text_field( $settings['reservation_after_hours_label'] )    : 'After-Hours / Weekend';

		return rest_ensure_response( array(
			'enabled'              => (bool) $enabled,
			'resource_name'        => ! empty( $settings['reservation_resource_name'] )     ? sanitize_text_field( $settings['reservation_resource_name'] ) : 'Conference Room',
			'resource_key'         => ! empty( $settings['reservation_resource_key'] )      ? sanitize_key( $settings['reservation_resource_key'] )         : 'conference_room',
			'timezone'             => ! empty( $settings['zoho_default_timezone'] )         ? sanitize_text_field( $settings['zoho_default_timezone'] )      : 'America/New_York',
			'business_hours_label' => $biz_label,
			'after_hours_label'    => $aft_label,
			'biz_rate_label'       => $biz_label,
			'after_rate_label'     => $aft_label,
			'business_hours_rate'  => isset( $settings['reservation_business_hours_rate'] ) ? (float) $settings['reservation_business_hours_rate']           : 40.0,
			'after_hours_rate'     => isset( $settings['reservation_after_hours_rate'] )    ? (float) $settings['reservation_after_hours_rate']              : 80.0,
			'currency'             => 'usd',
			'booking_window_start' => AJCore_Reservations::BOOKING_WINDOW_START_HOUR,
			'booking_window_end'   => AJCore_Reservations::BOOKING_WINDOW_END_HOUR,
			'min_duration_minutes' => 60,
			'max_duration_minutes' => 840,
			'pending_hold_minutes' => AJCore_Reservations::PENDING_HOLD_MINUTES,
			'hold_minutes'         => AJCore_Reservations::PENDING_HOLD_MINUTES,
			'policy_text'          => __( 'No cancellations, no rescheduling — reservations are final.', 'ajforms' ),
			'zoho_configured'      => (bool) ( $has_token && ! empty( $settings['zoho_calendar_uid'] ) ),
		) );
	}

	public function portal_get_reservations() {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return rest_ensure_response( array( 'reservations' => array() ) );
		}
		$settings           = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone           = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$wp_user_id         = get_current_user_id();
		$rows               = AJCore_Reservations::get_customer_reservations( $stripe_customer_id, $wp_user_id );

		$formatted = array_map( function( $row ) use ( $timezone ) {
			return $this->format_reservation_row( (array) $row, $timezone, false );
		}, is_array( $rows ) ? $rows : array() );

		return rest_ensure_response( array( 'reservations' => array_values( $formatted ) ) );
	}

	public function portal_reservations_availability( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$resource_key = sanitize_key( (string) ( $request->get_param( 'resource_key' ) ?: '' ) );
		$start_at_raw = sanitize_text_field( (string) ( $request->get_param( 'start_at' ) ?: '' ) );
		$end_at_raw   = sanitize_text_field( (string) ( $request->get_param( 'end_at' ) ?: '' ) );

		if ( '' === $resource_key || '' === $start_at_raw || '' === $end_at_raw ) {
			return new WP_Error( 'missing_params', __( 'resource_key, start_at, and end_at are required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		try {
			$start_dt = $this->res_parse_dt( $start_at_raw, $timezone );
			$end_dt   = $this->res_parse_dt( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_datetime', __( 'Invalid date/time format.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$slot_check = $this->res_validate_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $slot_check ) ) {
			return rest_ensure_response( array( 'available' => false, 'message' => $slot_check->get_error_message() ) );
		}

		$resource = AJCore_Reservations::get_resource_by_key( $resource_key );
		if ( ! $resource ) {
			return new WP_Error( 'resource_not_found', __( 'Resource not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$avail = $this->res_check_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings );

		return rest_ensure_response( array(
			'available'    => (bool) $avail['available'],
			'message'      => isset( $avail['message'] )      ? $avail['message']      : '',
			'pricing_type' => isset( $avail['pricing_type'] ) ? $avail['pricing_type'] : '',
		) );
	}

	public function portal_reservations_quote( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$resource_key = sanitize_key( (string) ( $request->get_param( 'resource_key' ) ?: '' ) );
		$start_at_raw = sanitize_text_field( (string) ( $request->get_param( 'start_at' ) ?: '' ) );
		$end_at_raw   = sanitize_text_field( (string) ( $request->get_param( 'end_at' ) ?: '' ) );

		if ( '' === $resource_key || '' === $start_at_raw || '' === $end_at_raw ) {
			return new WP_Error( 'missing_params', __( 'resource_key, start_at, and end_at are required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		try {
			$start_dt = $this->res_parse_dt( $start_at_raw, $timezone );
			$end_dt   = $this->res_parse_dt( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_datetime', __( 'Invalid date/time format.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$slot_check = $this->res_validate_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $slot_check ) ) {
			return new WP_Error( $slot_check->get_error_code(), $slot_check->get_error_message(), array( 'status' => 400 ) );
		}

		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		$resource = AJCore_Reservations::get_resource_by_key( $resource_key );
		if ( ! $resource ) {
			return new WP_Error( 'resource_not_found', __( 'Resource not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$breakdown = AJCore_Reservations::calculate_pricing_breakdown( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $breakdown ) ) {
			return new WP_Error( $breakdown->get_error_code(), $breakdown->get_error_message(), array( 'status' => 400 ) );
		}

		$avail      = $this->res_check_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings );
		$biz_rate   = max( 1.0, (float) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate = max( 1.0, (float) ( $settings['reservation_after_hours_rate'] ?? 80 ) );
		$biz_label  = ! empty( $settings['reservation_business_hours_label'] ) ? $settings['reservation_business_hours_label'] : 'Business Hours (Mon–Fri 9am–5pm)';
		$aft_label  = ! empty( $settings['reservation_after_hours_label'] )    ? $settings['reservation_after_hours_label']    : 'After-Hours / Weekend';

		$biz_amount   = round( ( $breakdown['business_minutes'] / 60 ) * $biz_rate, 2 );
		$after_amount = round( ( $breakdown['after_hours_minutes'] / 60 ) * $after_rate, 2 );
		$total_amount = $biz_amount + $after_amount;

		if ( 'business_hours' === $breakdown['pricing_type'] ) {
			$pricing_label = $biz_label;
		} elseif ( 'after_hours_weekend' === $breakdown['pricing_type'] ) {
			$pricing_label = $aft_label;
		} else {
			$pricing_label = __( 'Mixed (Business + After-Hours)', 'ajforms' );
		}

		return rest_ensure_response( array(
			'pricing_type'        => $breakdown['pricing_type'],
			'pricing_label'       => $pricing_label,
			'duration_minutes'    => (int) $breakdown['total_minutes'],
			'business_minutes'    => (int) $breakdown['business_minutes'],
			'after_hours_minutes' => (int) $breakdown['after_hours_minutes'],
			'business_amount'     => $biz_amount,
			'after_hours_amount'  => $after_amount,
			'amount'              => $total_amount,
			'currency'            => 'usd',
			'available'           => (bool) $avail['available'],
			'message'             => isset( $avail['message'] ) ? $avail['message'] : '',
		) );
	}

	public function portal_reservations_cart_add( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$resource_key   = sanitize_key( (string) ( $request->get_param( 'resource_key' ) ?: '' ) );
		$start_at_raw   = sanitize_text_field( (string) ( $request->get_param( 'start_at' ) ?: '' ) );
		$end_at_raw     = sanitize_text_field( (string) ( $request->get_param( 'end_at' ) ?: '' ) );
		$customer_phone = sanitize_text_field( (string) ( $request->get_param( 'customer_phone' ) ?: '' ) );
		$customer_notes = sanitize_textarea_field( (string) ( $request->get_param( 'customer_notes' ) ?: '' ) );

		if ( '' === $resource_key || '' === $start_at_raw || '' === $end_at_raw ) {
			return new WP_Error( 'missing_params', __( 'resource_key, start_at, and end_at are required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone   = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$wp_user    = wp_get_current_user();
		$wp_user_id = (int) $wp_user->ID;

		try {
			$start_dt = $this->res_parse_dt( $start_at_raw, $timezone );
			$end_dt   = $this->res_parse_dt( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_datetime', __( 'Invalid date/time format.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$slot_check = $this->res_validate_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $slot_check ) ) {
			return new WP_Error( $slot_check->get_error_code(), $slot_check->get_error_message(), array( 'status' => 400 ) );
		}

		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		$window_check = AJCore_Reservations::validate_booking_window( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $window_check ) ) {
			return new WP_Error( $window_check->get_error_code(), $window_check->get_error_message(), array( 'status' => 400 ) );
		}

		$resource = AJCore_Reservations::get_resource_by_key( $resource_key );
		if ( ! $resource ) {
			return new WP_Error( 'resource_not_found', __( 'Resource not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$avail = $this->res_check_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings );
		if ( empty( $avail['available'] ) ) {
			return new WP_Error( 'not_available', isset( $avail['message'] ) ? $avail['message'] : __( 'This slot is not available.', 'ajforms' ), array( 'status' => 409 ) );
		}

		$pricing_type = AJCore_Reservations::determine_pricing_type( $start_at_utc, $timezone );
		$biz_rate     = max( 1.0, (float) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate   = max( 1.0, (float) ( $settings['reservation_after_hours_rate'] ?? 80 ) );
		$unit_rate    = 'business_hours' === $pricing_type ? $biz_rate : $after_rate;
		$duration_hrs = max( 1, (int) round( ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 3600 ) );
		$total_amount = $unit_rate * $duration_hrs;

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$customer_name      = sanitize_text_field( $wp_user->display_name ? $wp_user->display_name : $wp_user->user_login );
		$customer_email     = sanitize_email( $wp_user->user_email );
		$stored_notes       = trim( sprintf( "Phone: %s\n%s", $customer_phone, $customer_notes ) );

		$pending = AJCore_Reservations::create_pending_reservation( array(
			'resource_id'        => (int) $resource->id,
			'resource_key'       => sanitize_key( (string) $resource->resource_key ),
			'resource_name'      => sanitize_text_field( (string) $resource->resource_name ),
			'stripe_customer_id' => $stripe_customer_id,
			'wp_user_id'         => $wp_user_id,
			'start_at'           => $start_at_utc,
			'end_at'             => $end_at_utc,
			'timezone'           => $timezone,
			'pricing_type'       => $pricing_type,
			'amount'             => $total_amount,
			'currency'           => 'usd',
			'customer_name'      => $customer_name,
			'customer_email'     => $customer_email,
			'customer_notes'     => $stored_notes,
			'zoho_calendar_id'   => ! empty( $settings['zoho_calendar_id'] )   ? $settings['zoho_calendar_id']   : '',
			'zoho_resource_uid'  => ! empty( $settings['zoho_resource_uid'] )   ? $settings['zoho_resource_uid']   : '',
		) );

		if ( is_wp_error( $pending ) ) {
			return new WP_Error( $pending->get_error_code(), $pending->get_error_message(), array( 'status' => 500 ) );
		}

		$pdb_res   = AJCore_Reservations::get_pdb();
		$res_table = AJCore_Reservations::get_reservations_table();
		$pdb_res->update(
			$res_table,
			array( 'status' => 'in_cart', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $pending['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		AJCore_Reservations::log_reservation_event( 'reservation_added_to_cart', array(
			'reservation_uuid'   => $pending['reservation_uuid'],
			'reservation_id'     => $pending['id'],
			'stripe_customer_id' => $stripe_customer_id,
			'wp_user_id'         => $wp_user_id,
			'resource_key'       => $resource_key,
			'pricing_type'       => $pricing_type,
			'start_at'           => $start_at_utc,
			'end_at'             => $end_at_utc,
			'source'             => 'portal_api',
		) );

		$cart_items = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );

		return rest_ensure_response( array(
			'reservation_uuid' => $pending['reservation_uuid'],
			'reservation_id'   => (int) $pending['id'],
			'cart_count'       => count( $cart_items ),
			'message'          => __( 'Added to cart.', 'ajforms' ),
		) );
	}

	public function portal_reservations_get_cart() {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0.0, 'currency' => 'usd', 'count' => 0 ) );
		}
		$settings           = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone           = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$wp_user_id         = get_current_user_id();
		$items              = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );

		$formatted   = array();
		$grand_total = 0.0;
		$biz_rate    = max( 1.0, (float) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate  = max( 1.0, (float) ( $settings['reservation_after_hours_rate'] ?? 80 ) );

		foreach ( $items as $item ) {
			$row = $this->res_format_cart_item( (array) $item, $settings, $timezone, $biz_rate, $after_rate );
			if ( $row ) {
				$formatted[]  = $row;
				$grand_total += (float) $row['total'];
			}
		}

		return rest_ensure_response( array(
			'items'       => $formatted,
			'grand_total' => round( $grand_total, 2 ),
			'total'       => round( $grand_total, 2 ),
			'currency'    => 'usd',
			'count'       => count( $formatted ),
		) );
	}

	public function portal_reservations_cart_remove( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$uuid = sanitize_text_field( (string) ( $request->get_param( 'reservation_uuid' ) ?: '' ) );
		if ( '' === $uuid ) {
			return new WP_Error( 'missing_param', __( 'reservation_uuid is required.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$wp_user_id         = get_current_user_id();
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$pdb_res            = AJCore_Reservations::get_pdb();
		$res_table          = AJCore_Reservations::get_reservations_table();

		$reservation = $pdb_res->get_row( $pdb_res->prepare(
			"SELECT * FROM `{$res_table}` WHERE reservation_uuid = %s AND status = 'in_cart' AND (wp_user_id = %d OR stripe_customer_id = %s) LIMIT 1",
			$uuid, $wp_user_id, $stripe_customer_id
		) );

		if ( ! $reservation ) {
			return new WP_Error( 'not_found', __( 'Reservation not found or already removed.', 'ajforms' ), array( 'status' => 404 ) );
		}

		if ( ! AJCore_Reservations::is_hard_delete_allowed( $reservation ) ) {
			return new WP_Error( 'cannot_remove', __( 'This reservation cannot be removed from cart.', 'ajforms' ), array( 'status' => 409 ) );
		}

		$pdb_res->delete( $res_table, array( 'id' => (int) $reservation->id ), array( '%d' ) );

		AJCore_Reservations::log_reservation_event( 'reservation_removed_from_cart', array(
			'reservation_uuid'   => $uuid,
			'reservation_id'     => $reservation->id,
			'stripe_customer_id' => $stripe_customer_id,
			'wp_user_id'         => $wp_user_id,
			'source'             => 'portal_api',
		) );

		$settings    = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone    = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$biz_rate    = max( 1.0, (float) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate  = max( 1.0, (float) ( $settings['reservation_after_hours_rate'] ?? 80 ) );
		$items       = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );
		$formatted   = array();
		$grand_total = 0.0;

		foreach ( $items as $item ) {
			$row = $this->res_format_cart_item( (array) $item, $settings, $timezone, $biz_rate, $after_rate );
			if ( $row ) {
				$formatted[]  = $row;
				$grand_total += (float) $row['total'];
			}
		}

		return rest_ensure_response( array(
			'success'     => true,
			'items'       => $formatted,
			'grand_total' => round( $grand_total, 2 ),
			'total'       => round( $grand_total, 2 ),
			'currency'    => 'usd',
			'count'       => count( $formatted ),
		) );
	}

	public function portal_reservations_cart_checkout() {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$settings           = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone           = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$wp_user_id         = get_current_user_id();
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$cart_items         = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );

		if ( empty( $cart_items ) ) {
			return new WP_Error( 'empty_cart', __( 'Your cart is empty.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$line_items = array();
		$uuids      = array();

		foreach ( $cart_items as $item ) {
			$item_uuid    = sanitize_text_field( (string) $item->reservation_uuid );
			$start_at_utc = sanitize_text_field( (string) $item->start_at );
			$end_at_utc   = sanitize_text_field( (string) $item->end_at );
			$resource_id  = (int) $item->resource_id;

			$conflict = AJCore_Reservations::check_local_conflict( $resource_id, $start_at_utc, $end_at_utc, $item_uuid );
			if ( is_wp_error( $conflict ) ) {
				return new WP_Error( 'cart_conflict', sprintf(
					/* translators: %s: reservation start time */
					__( 'A slot in your cart is no longer available: %s. Please remove it and try again.', 'ajforms' ),
					$start_at_utc
				), array( 'status' => 409 ) );
			}

			$breakdown = AJCore_Reservations::calculate_pricing_breakdown( $start_at_utc, $end_at_utc, $timezone );
			if ( is_wp_error( $breakdown ) ) {
				continue;
			}

			$item_prices         = $this->res_get_price_ids( sanitize_key( (string) $item->resource_key ), $settings );
			$item_biz_price_id   = $item_prices['business_hours_price_id'];
			$item_after_price_id = $item_prices['after_hours_price_id'];

			if ( '' === $item_biz_price_id || '' === $item_after_price_id ) {
				return new WP_Error( 'pricing_not_configured', __( 'Conference room pricing is not fully configured. Please contact support.', 'ajforms' ), array( 'status' => 503 ) );
			}

			$biz_qty   = (int) round( $breakdown['business_minutes'] / 60 );
			$after_qty = (int) round( $breakdown['after_hours_minutes'] / 60 );

			if ( $biz_qty > 0 ) {
				$line_items[] = array( 'price' => $item_biz_price_id, 'quantity' => $biz_qty );
			}
			if ( $after_qty > 0 ) {
				$line_items[] = array( 'price' => $item_after_price_id, 'quantity' => $after_qty );
			}
			if ( 0 === $biz_qty && 0 === $after_qty ) {
				$total_qty    = max( 1, (int) round( $breakdown['total_minutes'] / 60 ) );
				$line_items[] = array(
					'price'    => 'business_hours' === $breakdown['pricing_type'] ? $item_biz_price_id : $item_after_price_id,
					'quantity' => $total_qty,
				);
			}
			$uuids[] = $item_uuid;
		}

		if ( empty( $line_items ) ) {
			return new WP_Error( 'empty_cart', __( 'No valid items in cart.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$stripe_settings = $this->res_get_stripe_settings( $settings );
		$secret_key      = $stripe_settings['secret_key'];
		$pub_key         = $stripe_settings['publishable_key'];

		if ( '' === $secret_key ) {
			return new WP_Error( 'payment_not_configured', __( 'Payment gateway is not configured.', 'ajforms' ), array( 'status' => 503 ) );
		}

		$portal_url  = home_url( '/' );
		$success_url = add_query_arg( array( 'ajcore_checkout' => 'success',    'portal_tab' => 'reservations' ), $portal_url );
		$cancel_url  = add_query_arg( array( 'ajcore_checkout' => 'cancelled',  'portal_tab' => 'reservations' ), $portal_url );
		$uuids_str   = implode( ',', $uuids );

		$checkout_payload = array(
			'payment_method_types' => array( 'card' ),
			'mode'                 => 'payment',
			'line_items'           => $line_items,
			'success_url'          => $success_url,
			'cancel_url'           => $cancel_url,
			'metadata'             => array(
				'reservation_uuids' => $uuids_str,
				'ajcore_source'     => 'reservation_cart',
			),
		);

		if ( $stripe_customer_id && 0 === strpos( $stripe_customer_id, 'cus_' ) ) {
			$checkout_payload['customer'] = $stripe_customer_id;
		} else {
			$first_item                         = (array) $cart_items[0];
			$checkout_payload['customer_email'] = sanitize_email( (string) ( $first_item['customer_email'] ?? '' ) );
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body' => $this->res_flatten_for_stripe( $checkout_payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_error', __( 'Payment gateway error. Please try again.', 'ajforms' ), array( 'status' => 502 ) );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		$session_id   = ! empty( $body['id'] )  ? sanitize_text_field( (string) $body['id'] )  : '';
		$checkout_url = ! empty( $body['url'] ) ? esc_url_raw( (string) $body['url'] )          : '';

		if ( 200 !== (int) $code || ! $session_id || ! $checkout_url ) {
			$err_msg = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not create checkout session.', 'ajforms' );
			return new WP_Error( 'stripe_session_error', $err_msg, array( 'status' => 502 ) );
		}

		$pdb       = AJCore_Reservations::get_pdb();
		$res_table = AJCore_Reservations::get_reservations_table();
		foreach ( $uuids as $uuid ) {
			$pdb->update(
				$res_table,
				array( 'stripe_checkout_session_id' => $session_id, 'updated_at' => current_time( 'mysql' ) ),
				array( 'reservation_uuid' => $uuid ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}

		AJCore_Reservations::log_reservation_event( 'reservation_cart_checkout_started', array(
			'reservation_uuids'          => $uuids_str,
			'stripe_customer_id'         => $stripe_customer_id,
			'wp_user_id'                 => $wp_user_id,
			'stripe_checkout_session_id' => $session_id,
			'item_count'                 => count( $uuids ),
			'source'                     => 'portal_api',
		) );

		return rest_ensure_response( array(
			'checkout_url'    => $checkout_url,
			'session_id'      => $session_id,
			'publishable_key' => $pub_key,
		) );
	}

	public function portal_get_reservation( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$id                 = absint( $request->get_param( 'id' ) );
		$wp_user_id         = get_current_user_id();
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$pdb                = AJCore_Reservations::get_pdb();
		$res_table          = AJCore_Reservations::get_reservations_table();

		$reservation = $pdb->get_row( $pdb->prepare(
			"SELECT * FROM `{$res_table}` WHERE id = %d AND status <> 'admin_archived' AND (wp_user_id = %d OR stripe_customer_id = %s) LIMIT 1",
			$id, $wp_user_id, $stripe_customer_id
		) );

		if ( ! $reservation ) {
			return new WP_Error( 'not_found', __( 'Reservation not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		return rest_ensure_response( array(
			'reservation' => $this->format_reservation_row( (array) $reservation, $timezone, false ),
		) );
	}

	// ── OPS Reservation Endpoints ─────────────────────────────────────────────

	public function ops_get_reservations( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return rest_ensure_response( array( 'reservations' => array() ) );
		}
		$status       = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );
		$resource_key = sanitize_key( (string) ( $request->get_param( 'resource_key' ) ?: '' ) );
		$date_from    = sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?: '' ) );
		$date_to      = sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?: '' ) );
		$limit        = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );

		$filters = array( 'limit' => $limit );
		if ( '' !== $status )       { $filters['status']       = $status; }
		if ( '' !== $resource_key ) { $filters['resource_key'] = $resource_key; }
		if ( '' !== $date_from )    { $filters['date_from']    = $date_from; }
		if ( '' !== $date_to )      { $filters['date_to']      = $date_to; }

		$settings  = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone  = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$rows      = AJCore_Reservations::get_all_reservations( $filters );

		$formatted = array_map( function( $row ) use ( $timezone ) {
			return $this->format_reservation_row( (array) $row, $timezone, true );
		}, is_array( $rows ) ? $rows : array() );

		return rest_ensure_response( array( 'reservations' => array_values( $formatted ) ) );
	}

	public function ops_get_reservation( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$id        = absint( $request->get_param( 'id' ) );
		$pdb       = AJCore_Reservations::get_pdb();
		$res_table = AJCore_Reservations::get_reservations_table();

		$reservation = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$res_table}` WHERE id = %d LIMIT 1", $id ) );
		if ( ! $reservation ) {
			return new WP_Error( 'not_found', __( 'Reservation not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		return rest_ensure_response( array(
			'reservation' => $this->format_reservation_row( (array) $reservation, $timezone, true ),
		) );
	}

	private function get_portal_db() {
		if ( function_exists( 'ajcore_get_portal_db' ) ) {
			return ajcore_get_portal_db();
		}
		global $wpdb;
		return $wpdb;
	}

	private function portal_table( $suffix ) {
		$pdb = $this->get_portal_db();
		return $pdb->prefix . $suffix;
	}

	private function table_exists( $db, $table ) {
		return $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function count_table( $db, $table ) {
		if ( ! $this->table_exists( $db, $table ) ) {
			return 0;
		}
		return (int) $db->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	private function table_columns( $db, $table ) {
		if ( ! $this->table_exists( $db, $table ) ) {
			return array();
		}
		$columns = $db->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		return is_array( $columns ) ? $columns : array();
	}

	private function existing_columns( $db, $table, $columns ) {
		$available = $this->table_columns( $db, $table );
		return array_values( array_intersect( $columns, $available ) );
	}

	private function select_rows( $table, $columns, WP_REST_Request $request, $search_columns = array(), $order_by = 'id DESC' ) {
		$pdb = $this->get_portal_db();
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return array();
		}
		$columns = $this->existing_columns( $pdb, $table, $columns );
		if ( empty( $columns ) ) {
			return array();
		}
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$where    = '1=1';
		$params   = array();
		$search_columns = $this->existing_columns( $pdb, $table, $search_columns );
		if ( '' !== $search && ! empty( $search_columns ) ) {
			$like = '%' . $pdb->esc_like( $search ) . '%';
			$parts = array();
			foreach ( $search_columns as $column ) {
				$parts[] = "`{$column}` LIKE %s";
				$params[] = $like;
			}
			$where = '(' . implode( ' OR ', $parts ) . ')';
		}
		$params[] = $per_page;
		$sql = 'SELECT `' . implode( '`,`', $columns ) . "` FROM `{$table}` WHERE {$where} ORDER BY {$order_by} LIMIT %d";
		$rows = $pdb->get_results( $pdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( $this, 'decode_known_json_fields' ), $rows ) : array();
	}

	private function select_by_customer( $suffix, $columns, $stripe_customer_id, $order_by = 'id DESC' ) {
		return $this->get_customer_rows( $suffix, $columns, $stripe_customer_id, $order_by );
	}

	private function get_customer_rows( $suffix, $columns, $stripe_customer_id, $order_by = 'id DESC' ) {
		$pdb = $this->get_portal_db();
		$table = $this->portal_table( $suffix );
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		if ( '' === $stripe_customer_id || ! $this->table_exists( $pdb, $table ) ) {
			return array();
		}
		$columns = $this->existing_columns( $pdb, $table, $columns );
		$available = $this->table_columns( $pdb, $table );
		if ( empty( $columns ) || ! in_array( 'stripe_customer_id', $available, true ) ) {
			return array();
		}
		$rows = $pdb->get_results( $pdb->prepare( 'SELECT `' . implode( '`,`', $columns ) . "` FROM `{$table}` WHERE stripe_customer_id = %s ORDER BY {$order_by} LIMIT 100", $stripe_customer_id ), ARRAY_A );
		return is_array( $rows ) ? array_map( array( $this, 'decode_known_json_fields' ), $rows ) : array();
	}

	private function decode_known_json_fields( $row ) {
		return $this->decode_json_fields( $row, array( 'address', 'metadata', 'items', 'raw_data' ) );
	}

	private function decode_json_fields( $row, $fields ) {
		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$decoded = json_decode( $row[ $field ], true );
				if ( is_array( $decoded ) ) {
					$row[ $field ] = $decoded;
				}
			}
		}
		return $row;
	}

	// ── Reservation private helpers ───────────────────────────────────────────────

	private function res_parse_dt( $raw, $timezone ) {
		$timezone = ! empty( $timezone ) ? $timezone : 'America/New_York';
		$tz       = new DateTimeZone( $timezone );
		if ( preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', (string) $raw ) ) {
			$dt = new DateTime( (string) $raw );
		} else {
			$dt = new DateTime( (string) $raw, $tz );
		}
		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt;
	}

	private function res_validate_slot( $start_dt, $end_dt, $timezone ) {
		$tz    = new DateTimeZone( ! empty( $timezone ) ? $timezone : 'America/New_York' );
		$start = clone $start_dt;
		$end   = clone $end_dt;
		$start->setTimezone( $tz );
		$end->setTimezone( $tz );

		if ( '00' !== $start->format( 'i' ) || '00' !== $end->format( 'i' ) ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations must start and end on the hour.', 'ajforms' ) );
		}
		$secs = $end_dt->getTimestamp() - $start_dt->getTimestamp();
		if ( $secs < 3600 ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations must be at least one hour.', 'ajforms' ) );
		}
		if ( $secs > 50400 ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations cannot exceed 14 hours.', 'ajforms' ) );
		}
		if ( 0 !== ( $secs % 3600 ) ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations must be in whole-hour increments.', 'ajforms' ) );
		}
		return true;
	}

	private function res_check_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings ) {
		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		$window = AJCore_Reservations::validate_booking_window( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $window ) ) {
			return array( 'available' => false, 'message' => $window->get_error_message() );
		}

		$conflict = AJCore_Reservations::check_local_conflict( (int) $resource->id, $start_at_utc, $end_at_utc, '' );
		if ( is_wp_error( $conflict ) ) {
			return array( 'available' => false, 'message' => $conflict->get_error_message() );
		}

		$api_token    = ! empty( $settings['zoho_access_token'] ) ? $settings['zoho_access_token'] : ( ! empty( $settings['zoho_api_token'] ) ? $settings['zoho_api_token'] : '' );
		$calendar_uid = ! empty( $settings['zoho_calendar_uid'] )         ? $settings['zoho_calendar_uid']         : '';
		$resource_uid = ! empty( $settings['zoho_resource_uid'] )         ? $settings['zoho_resource_uid']         : '';
		$freebusy_url = ! empty( $settings['zoho_resource_freebusy_url'] ) ? $settings['zoho_resource_freebusy_url'] : '';
		// Portal REST API always uses lenient mode: Zoho outages must not block customers.
		// Local DB conflict check (above) is the hard gate against double-bookings.
		// The zoho_availability_failure_mode setting applies to the admin UI only.
		$failure_mode = 'lenient';

		if ( $api_token && $calendar_uid && class_exists( 'AJCore_Zoho_Calendar' ) ) {
			$check = AJCore_Zoho_Calendar::check_zoho_calendar_events_availability( $calendar_uid, $start_dt->format( 'c' ), $end_dt->format( 'c' ), $timezone, $api_token );
			if ( is_wp_error( $check ) ) {
				if ( 'lenient' !== $failure_mode ) {
					return array( 'available' => false, 'message' => __( 'Could not verify calendar availability. Please try again or contact support.', 'ajforms' ) );
				}
				AJCore_Reservations::log_reservation_event( 'reservation_zoho_check_failed', array( 'severity' => 'warning', 'error' => $check->get_error_message(), 'mode' => 'lenient', 'source' => 'portal_api' ) );
			} elseif ( isset( $check['is_free'] ) && ! (bool) $check['is_free'] ) {
				return array( 'available' => false, 'message' => __( 'This time slot is already booked on the calendar. Please choose another time.', 'ajforms' ) );
			}
		} elseif ( $api_token && $resource_uid && $freebusy_url && class_exists( 'AJCore_Zoho_Calendar' ) ) {
			$freebusy = AJCore_Zoho_Calendar::check_zoho_resource_freebusy( $resource_uid, $freebusy_url, $start_dt->format( 'c' ), $end_dt->format( 'c' ), $api_token );
			if ( is_wp_error( $freebusy ) ) {
				if ( 'lenient' !== $failure_mode ) {
					return array( 'available' => false, 'message' => __( 'Could not verify calendar availability. Please try again or contact support.', 'ajforms' ) );
				}
				AJCore_Reservations::log_reservation_event( 'reservation_zoho_check_failed', array( 'severity' => 'warning', 'error' => $freebusy->get_error_message(), 'mode' => 'lenient', 'source' => 'portal_api' ) );
			} elseif ( isset( $freebusy['is_free'] ) && ! (bool) $freebusy['is_free'] ) {
				return array( 'available' => false, 'message' => __( 'This time slot is already booked on the calendar. Please choose another time.', 'ajforms' ) );
			}
		}

		return array(
			'available'    => true,
			'pricing_type' => AJCore_Reservations::determine_pricing_type( $start_at_utc, $timezone ),
		);
	}

	private function res_get_price_ids( $resource_key, $settings = array() ) {
		$pdb   = AJCore_Reservations::get_pdb();
		$table = $pdb->prefix . 'aj_portal_product_catalog';

		$biz_id   = '';
		$after_id = '';

		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$row = $pdb->get_row( $pdb->prepare(
				"SELECT reservation_business_hours_price_id, reservation_after_hours_price_id FROM `{$table}` WHERE product_type = 'reservation' AND reservation_resource_key = %s ORDER BY id DESC LIMIT 1",
				sanitize_key( (string) $resource_key )
			) );
			if ( ! $row ) {
				$row = $pdb->get_row(
					"SELECT reservation_business_hours_price_id, reservation_after_hours_price_id FROM `{$table}` WHERE product_type = 'reservation' AND reservation_business_hours_price_id <> '' AND reservation_after_hours_price_id <> '' ORDER BY id DESC LIMIT 1"
				);
			}
			if ( $row ) {
				$biz_id   = ! empty( $row->reservation_business_hours_price_id ) ? sanitize_text_field( (string) $row->reservation_business_hours_price_id ) : '';
				$after_id = ! empty( $row->reservation_after_hours_price_id )     ? sanitize_text_field( (string) $row->reservation_after_hours_price_id )    : '';
			}
		}

		if ( '' === $biz_id && ! empty( $settings['reservation_business_hours_price_id'] ) ) {
			$biz_id = sanitize_text_field( (string) $settings['reservation_business_hours_price_id'] );
		}
		if ( '' === $after_id && ! empty( $settings['reservation_after_hours_price_id'] ) ) {
			$after_id = sanitize_text_field( (string) $settings['reservation_after_hours_price_id'] );
		}

		return array( 'business_hours_price_id' => $biz_id, 'after_hours_price_id' => $after_id );
	}

	private function res_get_stripe_settings( $settings = array() ) {
		if ( empty( $settings ) ) {
			$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		}
		return array(
			'publishable_key' => ! empty( $settings['stripe_publishable_key'] ) ? sanitize_text_field( (string) $settings['stripe_publishable_key'] ) : '',
			'secret_key'      => ! empty( $settings['stripe_secret_key'] )      ? sanitize_text_field( (string) $settings['stripe_secret_key'] )      : '',
		);
	}

	private function res_flatten_for_stripe( $array, $prefix = '' ) {
		$result = array();
		foreach ( $array as $key => $value ) {
			$full_key = '' !== $prefix ? $prefix . '[' . $key . ']' : (string) $key;
			if ( is_array( $value ) ) {
				$result = array_merge( $result, $this->res_flatten_for_stripe( $value, $full_key ) );
			} else {
				$result[ $full_key ] = $value;
			}
		}
		return $result;
	}

	private function res_format_cart_item( $item, $settings, $timezone, $biz_rate, $after_rate ) {
		try {
			$tz    = new DateTimeZone( ! empty( $timezone ) ? $timezone : 'America/New_York' );
			$start = new DateTime( (string) ( $item['start_at'] ?? 'now' ), new DateTimeZone( 'UTC' ) );
			$end   = new DateTime( (string) ( $item['end_at'] ?? 'now' ), new DateTimeZone( 'UTC' ) );
			$start->setTimezone( $tz );
			$end->setTimezone( $tz );
		} catch ( Exception $e ) {
			return null;
		}
		$pricing_type = isset( $item['pricing_type'] ) ? (string) $item['pricing_type'] : 'after_hours_weekend';
		$rate         = 'business_hours' === $pricing_type ? $biz_rate : $after_rate;
		$hours        = max( 1, (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / 3600 ) );
		$biz_label    = ! empty( $settings['reservation_business_hours_label'] ) ? $settings['reservation_business_hours_label'] : 'Business Hours';
		$aft_label    = ! empty( $settings['reservation_after_hours_label'] )    ? $settings['reservation_after_hours_label']    : 'After-Hours / Weekend';

		$item_uuid  = isset( $item['reservation_uuid'] ) ? (string) $item['reservation_uuid'] : '';
		$start_utc  = isset( $item['start_at'] ) ? (string) $item['start_at'] : '';
		$end_utc    = isset( $item['end_at'] )   ? (string) $item['end_at']   : '';
		$item_total = round( $hours * $rate, 2 );

		return array(
			'uuid'             => $item_uuid,
			'reservation_uuid' => $item_uuid,
			'reservation_id'   => isset( $item['id'] ) ? (int) $item['id'] : 0,
			'resource_key'     => isset( $item['resource_key'] )  ? (string) $item['resource_key']  : '',
			'resource_name'    => isset( $item['resource_name'] ) ? (string) $item['resource_name'] : '',
			'start_at'         => $start_utc,
			'end_at'           => $end_utc,
			'start_at_utc'     => $start_utc,
			'end_at_utc'       => $end_utc,
			'start_at_local'   => $start->format( 'Y-m-d\TH:i:s' ),
			'end_at_local'     => $end->format( 'Y-m-d\TH:i:s' ),
			'timezone'         => $timezone,
			'date_display'     => $start->format( 'M j, Y' ),
			'time_display'     => $start->format( 'g:i A' ) . ' – ' . $end->format( 'g:i A T' ),
			'duration_hours'   => $hours,
			'duration_minutes' => $hours * 60,
			'pricing_type'     => $pricing_type,
			'pricing_label'    => 'business_hours' === $pricing_type ? $biz_label : $aft_label,
			'rate'             => $rate,
			'amount'           => $item_total,
			'total'            => $item_total,
			'currency'         => 'usd',
		);
	}

	private function format_reservation_row( $res, $timezone, $is_ops = false ) {
		$res_timezone = ! empty( $res['timezone'] ) ? $res['timezone'] : $timezone;
		$res_uuid     = (string) ( $res['reservation_uuid'] ?? '' );
		$res_id       = (int) ( $res['id'] ?? 0 );
		$start_utc    = (string) ( $res['start_at'] ?? '' );
		$end_utc      = (string) ( $res['end_at'] ?? '' );
		$friendly_ref = class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::generate_friendly_reference( $res_id ) : '';
		$pricing_type = (string) ( $res['pricing_type'] ?? '' );

		$row = array(
			'id'               => $res_id,
			'uuid'             => $res_uuid,
			'reservation_uuid' => $res_uuid,
			'friendly_ref'     => $friendly_ref,
			'reservation_ref'  => $friendly_ref,
			'resource_key'     => (string) ( $res['resource_key'] ?? '' ),
			'resource_name'    => (string) ( $res['resource_name'] ?? '' ),
			'start_at'         => $start_utc,
			'end_at'           => $end_utc,
			'start_at_utc'     => $start_utc,
			'end_at_utc'       => $end_utc,
			'timezone'         => $res_timezone,
			'status'           => (string) ( $res['status'] ?? '' ),
			'status_label'     => class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::get_reservation_status_label( (string) ( $res['status'] ?? '' ) ) : (string) ( $res['status'] ?? '' ),
			'pricing_type'     => $pricing_type,
			'pricing_label'    => class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::get_pricing_type_label( $pricing_type ) : $pricing_type,
			'amount'           => isset( $res['amount'] ) ? (float) $res['amount'] : 0.0,
			'currency'         => (string) ( $res['currency'] ?? 'usd' ),
			'customer_name'    => (string) ( $res['customer_name'] ?? '' ),
			'customer_email'   => (string) ( $res['customer_email'] ?? '' ),
			'customer_phone'   => (string) ( $res['customer_phone'] ?? '' ),
			'customer_notes'   => (string) ( $res['customer_notes'] ?? '' ),
			'created_at'       => (string) ( $res['created_at'] ?? '' ),
			'updated_at'       => (string) ( $res['updated_at'] ?? '' ),
		);

		try {
			$tz    = new DateTimeZone( $res_timezone );
			$start = new DateTime( $start_utc ?: 'now', new DateTimeZone( 'UTC' ) );
			$end   = new DateTime( $end_utc   ?: 'now', new DateTimeZone( 'UTC' ) );
			$row['duration_minutes'] = (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / 60 );
			$start->setTimezone( $tz );
			$end->setTimezone( $tz );
			$row['start_at_local'] = $start->format( 'Y-m-d\TH:i:s' );
			$row['end_at_local']   = $end->format( 'Y-m-d\TH:i:s' );
			$row['date_display']   = $start->format( 'M j, Y' );
			$row['time_display']   = $start->format( 'g:i A' ) . ' – ' . $end->format( 'g:i A T' );
		} catch ( Exception $e ) {
			$row['duration_minutes'] = 0;
			$row['start_at_local']   = '';
			$row['end_at_local']     = '';
			$row['date_display']     = '';
			$row['time_display']     = '';
		}

		if ( $is_ops ) {
			$row['wp_user_id']                 = (int) ( $res['wp_user_id'] ?? 0 );
			$row['stripe_customer_id']         = (string) ( $res['stripe_customer_id'] ?? '' );
			$row['stripe_checkout_session_id'] = (string) ( $res['stripe_checkout_session_id'] ?? '' );
			$row['stripe_payment_intent_id']   = (string) ( $res['stripe_payment_intent_id'] ?? '' );
			$row['zoho_event_id']              = (string) ( $res['zoho_event_id'] ?? '' );
			$row['admin_notes']                = (string) ( $res['admin_notes'] ?? '' );
		}

		return $row;
	}

	private function get_current_user_stripe_customer_id() {
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			return '';
		}
		global $wpdb;
		$mapping_table = $wpdb->prefix . 'aj_auth_user_mappings';
		if ( $this->table_exists( $wpdb, $mapping_table ) ) {
			$customer_id = $wpdb->get_var( $wpdb->prepare( "SELECT stripe_customer_id FROM `{$mapping_table}` WHERE user_id = %d OR portal_user_email = %s OR customer_email = %s ORDER BY updated_at DESC, id DESC LIMIT 1", (int) $user->ID, $user->user_email, $user->user_email ) );
			if ( $customer_id ) {
				return sanitize_text_field( (string) $customer_id );
			}
		}
		$pdb = $this->get_portal_db();
		$customers_table = $this->portal_table( 'aj_portal_stripe_customers' );
		if ( $this->table_exists( $pdb, $customers_table ) ) {
			$customer_id = $pdb->get_var( $pdb->prepare( "SELECT stripe_customer_id FROM `{$customers_table}` WHERE email = %s LIMIT 1", $user->user_email ) );
			if ( $customer_id ) {
				return sanitize_text_field( (string) $customer_id );
			}
		}
		return '';
	}

	private function get_current_user_file_rows() {
		global $wpdb;
		$user = wp_get_current_user();
		$files_table = $wpdb->prefix . 'aj_portal_files';
		$link_table  = $wpdb->prefix . 'aj_portal_file_users';
		if ( ! $user || ! $this->table_exists( $wpdb, $files_table ) || ! $this->table_exists( $wpdb, $link_table ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.attachment_id, f.title, f.description, f.category, f.created_at, f.updated_at FROM `{$files_table}` f INNER JOIN `{$link_table}` fu ON fu.file_id = f.id WHERE fu.user_id = %d OR fu.user_email = %s ORDER BY f.created_at DESC, f.id DESC LIMIT 100",
				(int) $user->ID,
				$user->user_email
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	// ── Mobile auth endpoints ─────────────────────────────────────────────────

	public function portal_auth_login( WP_REST_Request $request ) {
		$username = sanitize_user( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );
		if ( ! empty( $username ) && ! empty( $password ) ) {
			$user = wp_authenticate_username_password( null, $username, $password );
			if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
				return new WP_Error( 'ajcore_auth_failed', 'Invalid username or password.', array( 'status' => 401 ) );
			}
		} else {
			$user = wp_get_current_user();
			if ( ! $user || ! $user->ID ) {
				return new WP_Error( 'ajcore_auth_required', 'Username and password are required.', array( 'status' => 401 ) );
			}
		}
		$token = AJCore_JWT::generate( $user->ID );
		return rest_ensure_response( array(
			'token'              => $token,
			'user'               => $this->format_user( $user ),
			'stripe_customer_id' => $this->get_stripe_customer_id_for_user( $user ),
			'site_uuid'          => get_option( 'ajcore_site_uuid', '' ),
		) );
	}

	public function portal_auth_logout( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => true ) );
	}

	// ── OPS auth ─────────────────────────────────────────────────────────────

	public function ops_auth_login( WP_REST_Request $request ) {
		$username = sanitize_user( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_Error( 'ajcore_auth_required', 'Username and password are required.', array( 'status' => 400 ) );
		}

		$user = wp_authenticate_username_password( null, $username, $password );
		if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return new WP_Error( 'ajcore_auth_failed', 'Invalid username or password.', array( 'status' => 401 ) );
		}

		if ( ! user_can( $user, 'manage_options' )
			&& ! user_can( $user, 'ajcore_ops_access' )
			&& ! in_array( 'aj_ops_user', (array) $user->roles, true )
		) {
			return new WP_Error( 'ajcore_ops_forbidden', 'This account does not have OPS access.', array( 'status' => 403 ) );
		}

		$token = AJCore_JWT::generate( $user->ID );
		return rest_ensure_response( array(
			'token'     => $token,
			'user'      => $this->format_user( $user ),
			'site_uuid' => get_option( 'ajcore_site_uuid', '' ),
		) );
	}

	public function ops_auth_logout( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_ops_me( WP_REST_Request $request ) {
		$user = wp_get_current_user();
		return rest_ensure_response( array(
			'user'      => $this->format_user( $user ),
			'site_uuid' => get_option( 'ajcore_site_uuid', '' ),
		) );
	}

	public function ops_create_customer( WP_REST_Request $request ) {
		$name            = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email           = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone           = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$description     = sanitize_text_field( (string) ( $request->get_param( 'description' ) ?? '' ) );
		$business_name   = sanitize_text_field( (string) ( $request->get_param( 'business_name' ) ?? '' ) );
		$individual_name = sanitize_text_field( (string) ( $request->get_param( 'individual_name' ) ?? '' ) );
		$addr_line1      = sanitize_text_field( (string) ( $request->get_param( 'addr_line1' ) ?? '' ) );
		$addr_line2      = sanitize_text_field( (string) ( $request->get_param( 'addr_line2' ) ?? '' ) );
		$addr_city       = sanitize_text_field( (string) ( $request->get_param( 'addr_city' ) ?? '' ) );
		$addr_state      = sanitize_text_field( (string) ( $request->get_param( 'addr_state' ) ?? '' ) );
		$addr_postal     = sanitize_text_field( (string) ( $request->get_param( 'addr_postal' ) ?? '' ) );
		$addr_country    = sanitize_text_field( (string) ( $request->get_param( 'addr_country' ) ?? '' ) );

		if ( empty( $name ) || empty( $email ) || empty( $phone ) ) {
			return new WP_Error( 'ajcore_missing_fields', 'Name, email, and phone are required.', array( 'status' => 400 ) );
		}

		// Get Stripe secret key from plugin settings.
		$settings        = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$stripe_settings = ! empty( $settings['stripe'] ) ? $settings['stripe'] : array();
		$secret_key      = ! empty( $stripe_settings['secret_key'] ) ? $stripe_settings['secret_key'] : '';

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'ajcore_stripe_not_configured', 'Stripe is not configured.', array( 'status' => 503 ) );
		}

		// Create customer in Stripe.
		$stripe_body = array( 'name' => $name, 'email' => $email, 'phone' => $phone );
		if ( '' !== $description ) {
			$stripe_body['description'] = $description;
		}
		if ( '' !== $business_name ) {
			$stripe_body['metadata[business_name]'] = $business_name;
		}
		if ( '' !== $individual_name ) {
			$stripe_body['metadata[individual_name]'] = $individual_name;
		}
		if ( '' !== $addr_line1 ) {
			$stripe_body['address[line1]']       = $addr_line1;
			$stripe_body['address[line2]']       = $addr_line2;
			$stripe_body['address[city]']        = $addr_city;
			$stripe_body['address[state]']       = $addr_state;
			$stripe_body['address[postal_code]'] = $addr_postal;
			$stripe_body['address[country]']     = $addr_country;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/customers',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $stripe_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ajcore_stripe_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 || ! empty( $decoded['error'] ) ) {
			$message = ! empty( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Stripe error.', 'ajforms' );
			return new WP_Error( 'ajcore_stripe_api_error', $message, array( 'status' => $status_code ?: 502 ) );
		}

		// Upsert into local portal DB.
		$pdb            = $this->get_portal_db();
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );

		if ( $this->table_exists( $pdb, $customer_table ) ) {
			// Add description column if missing (safe on all MySQL/MariaDB versions).
			$cols = $pdb->get_col( "SHOW COLUMNS FROM `{$customer_table}` LIKE 'description'" );
			if ( empty( $cols ) ) {
				$pdb->query( "ALTER TABLE `{$customer_table}` ADD COLUMN `description` varchar(500) NOT NULL DEFAULT '' AFTER `phone`" );
			}

			$meta = array();
			if ( '' !== $business_name ) {
				$meta['business_name'] = $business_name;
			}
			if ( '' !== $individual_name ) {
				$meta['individual_name'] = $individual_name;
			}

			$address_data = array();
			if ( '' !== $addr_line1 ) {
				$address_data = array( 'line1' => $addr_line1, 'line2' => $addr_line2, 'city' => $addr_city, 'state' => $addr_state, 'postal_code' => $addr_postal, 'country' => $addr_country );
			} elseif ( ! empty( $decoded['address'] ) && is_array( $decoded['address'] ) ) {
				$address_data = $decoded['address'];
			}

			$pdb->replace(
				$customer_table,
				array(
					'stripe_customer_id' => $decoded['id'],
					'email'              => $decoded['email'] ?? '',
					'name'               => $decoded['name'] ?? '',
					'phone'              => $decoded['phone'] ?? '',
					'description'        => $decoded['description'] ?? $description,
					'address'            => ! empty( $address_data ) ? wp_json_encode( $address_data ) : '',
					'metadata'           => ! empty( $meta ) ? wp_json_encode( $meta ) : '',
					'portal_status'      => 'active',
					'livemode'           => ! empty( $decoded['livemode'] ) ? 1 : 0,
					'synced_at'          => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		$customer_row = array(
			'stripe_customer_id' => $decoded['id'],
			'email'              => $decoded['email'] ?? '',
			'name'               => $decoded['name'] ?? '',
			'phone'              => $decoded['phone'] ?? '',
			'description'        => $decoded['description'] ?? $description,
			'address'            => ! empty( $decoded['address'] ) ? $decoded['address'] : null,
			'metadata'           => ! empty( $decoded['metadata'] ) ? $decoded['metadata'] : null,
			'portal_status'      => 'active',
			'synced_at'          => current_time( 'mysql' ),
		);

		return rest_ensure_response( array( 'success' => true, 'customer' => $customer_row ) );
	}

	public function ops_trigger_sync( WP_REST_Request $request ) {
		// Schedule a single-run sync event to fire immediately.
		if ( ! wp_next_scheduled( 'ajcore_portal_stripe_sync' ) ) {
			wp_schedule_single_event( time() - 1, 'ajcore_portal_stripe_sync' );
		}
		spawn_cron();
		return rest_ensure_response( array( 'success' => true, 'message' => 'Sync scheduled.' ) );
	}

	public function ops_update_customer( WP_REST_Request $request ) {
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$name               = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email              = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone              = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$description        = sanitize_text_field( (string) ( $request->get_param( 'description' ) ?? '' ) );
		$business_name      = sanitize_text_field( (string) ( $request->get_param( 'business_name' ) ?? '' ) );
		$individual_name    = sanitize_text_field( (string) ( $request->get_param( 'individual_name' ) ?? '' ) );
		$addr_line1         = sanitize_text_field( (string) ( $request->get_param( 'addr_line1' ) ?? '' ) );
		$addr_line2         = sanitize_text_field( (string) ( $request->get_param( 'addr_line2' ) ?? '' ) );
		$addr_city          = sanitize_text_field( (string) ( $request->get_param( 'addr_city' ) ?? '' ) );
		$addr_state         = sanitize_text_field( (string) ( $request->get_param( 'addr_state' ) ?? '' ) );
		$addr_postal        = sanitize_text_field( (string) ( $request->get_param( 'addr_postal' ) ?? '' ) );
		$addr_country       = sanitize_text_field( (string) ( $request->get_param( 'addr_country' ) ?? '' ) );

		if ( empty( $name ) || empty( $email ) || empty( $phone ) ) {
			return new WP_Error( 'ajcore_missing_fields', 'Name, email, and phone are required.', array( 'status' => 400 ) );
		}

		// Get Stripe secret key.
		$settings        = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$stripe_settings = ! empty( $settings['stripe'] ) ? $settings['stripe'] : array();
		$secret_key      = ! empty( $stripe_settings['secret_key'] ) ? $stripe_settings['secret_key'] : '';

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'ajcore_stripe_not_configured', 'Stripe is not configured.', array( 'status' => 503 ) );
		}

		// Build Stripe update body (POST to /v1/customers/{id} = update).
		$stripe_body = array( 'name' => $name, 'email' => $email, 'phone' => $phone );
		if ( '' !== $description ) {
			$stripe_body['description'] = $description;
		}
		if ( '' !== $business_name ) {
			$stripe_body['metadata[business_name]'] = $business_name;
		}
		if ( '' !== $individual_name ) {
			$stripe_body['metadata[individual_name]'] = $individual_name;
		}
		if ( '' !== $addr_line1 ) {
			$stripe_body['address[line1]']       = $addr_line1;
			$stripe_body['address[line2]']       = $addr_line2;
			$stripe_body['address[city]']        = $addr_city;
			$stripe_body['address[state]']       = $addr_state;
			$stripe_body['address[postal_code]'] = $addr_postal;
			$stripe_body['address[country]']     = $addr_country;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/customers/' . $stripe_customer_id,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $stripe_body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ajcore_stripe_request_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 || ! empty( $decoded['error'] ) ) {
			$message = ! empty( $decoded['error']['message'] ) ? $decoded['error']['message'] : 'Stripe error.';
			return new WP_Error( 'ajcore_stripe_api_error', $message, array( 'status' => $status_code ?: 502 ) );
		}

		// Update local portal DB.
		$pdb            = $this->get_portal_db();
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );

		if ( $this->table_exists( $pdb, $customer_table ) ) {
			// Add description column if missing.
			$cols = $pdb->get_col( "SHOW COLUMNS FROM `{$customer_table}` LIKE 'description'" );
			if ( empty( $cols ) ) {
				$pdb->query( "ALTER TABLE `{$customer_table}` ADD COLUMN `description` varchar(500) NOT NULL DEFAULT '' AFTER `phone`" );
			}

			// Merge new metadata values into existing metadata.
			$existing = $pdb->get_row( $pdb->prepare(
				"SELECT metadata FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			), ARRAY_A );
			$meta = array();
			if ( ! empty( $existing['metadata'] ) ) {
				$decoded_meta = json_decode( $existing['metadata'], true );
				if ( is_array( $decoded_meta ) ) {
					$meta = $decoded_meta;
				}
			}
			if ( '' !== $business_name ) {
				$meta['business_name'] = $business_name;
			}
			if ( '' !== $individual_name ) {
				$meta['individual_name'] = $individual_name;
			}

			$address_data = array();
			if ( '' !== $addr_line1 ) {
				$address_data = array( 'line1' => $addr_line1, 'line2' => $addr_line2, 'city' => $addr_city, 'state' => $addr_state, 'postal_code' => $addr_postal, 'country' => $addr_country );
			} elseif ( ! empty( $decoded['address'] ) && is_array( $decoded['address'] ) ) {
				$address_data = $decoded['address'];
			}

			$pdb->update(
				$customer_table,
				array(
					'name'        => $name,
					'email'       => $email,
					'phone'       => $phone,
					'description' => $description,
					'address'     => ! empty( $address_data ) ? wp_json_encode( $address_data ) : '',
					'metadata'    => ! empty( $meta ) ? wp_json_encode( $meta ) : '',
					'synced_at'   => current_time( 'mysql' ),
				),
				array( 'stripe_customer_id' => $stripe_customer_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);
		}

		$customer_row = array(
			'stripe_customer_id' => $decoded['id'],
			'email'              => $decoded['email'] ?? $email,
			'name'               => $decoded['name'] ?? $name,
			'phone'              => $decoded['phone'] ?? $phone,
			'description'        => $decoded['description'] ?? $description,
			'address'            => ! empty( $decoded['address'] ) ? $decoded['address'] : null,
			'metadata'           => ! empty( $decoded['metadata'] ) ? $decoded['metadata'] : null,
			'portal_status'      => 'active',
			'synced_at'          => current_time( 'mysql' ),
		);

		return rest_ensure_response( array( 'success' => true, 'customer' => $customer_row ) );
	}

	public function ops_customer_action( WP_REST_Request $request ) {
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$action             = sanitize_key( (string) $request->get_param( 'action' ) );
		$allowed            = array( 'enable', 'disable', 'archive', 'restore', 'enable_repair', 'reset_password', 'send_welcome', 'delete_archived' );

		if ( ! in_array( $action, $allowed, true ) ) {
			return new WP_Error( 'invalid_action', 'Invalid action.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'AJForms_Admin' ) || ! AJForms_Admin::$instance ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}

		global $wpdb;
		$pdb            = $this->get_portal_db();
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$customer       = null;

		if ( 'enable_repair' === $action ) {
			$stats = AJForms_Admin::$instance->repair_portal_user_links_and_roles( true, true, true, array( $stripe_customer_id ) );
			return rest_ensure_response( array( 'success' => true, 'stats' => $stats ) );
		}

		$customer = $this->table_exists( $pdb, $customer_table )
			? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ) )
			: null;

		if ( ! $customer ) {
			return new WP_Error( 'customer_not_found', 'Customer not found.', array( 'status' => 404 ) );
		}

		$mapping_table   = $wpdb->prefix . 'aj_portal_user_mappings';
		$mapping         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$mapping_table}` WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ) );
		$customer->user_id           = $mapping ? $mapping->user_id : null;
		$customer->portal_user_email = $mapping ? $mapping->portal_user_email : null;

		$portal_status = ! empty( $customer->portal_status ) ? (string) $customer->portal_status : 'disabled';
		$result        = true;

		switch ( $action ) {
			case 'enable':
			case 'restore':
				$result = AJForms_Admin::$instance->enable_stripe_customer_as_portal_user( $stripe_customer_id );
				break;

			case 'disable':
				$result = AJForms_Admin::$instance->disable_stripe_customer_portal_access( $stripe_customer_id );
				break;

			case 'archive':
				$result = AJForms_Admin::$instance->disable_stripe_customer_portal_access( $stripe_customer_id, 'archived' );
				break;

			case 'reset_password':
				if ( empty( $customer->user_id ) ) {
					return new WP_Error( 'no_wp_user', 'No linked WordPress user. Run Enable & Repair first.', array( 'status' => 400 ) );
				}
				$result = AJForms_Admin::$instance->send_portal_user_password_reset( (int) $customer->user_id );
				if ( false === $result ) {
					$result = new WP_Error( 'reset_failed', 'Password reset email could not be sent.' );
				}
				break;

			case 'send_welcome':
				if ( empty( $customer->user_id ) ) {
					return new WP_Error( 'no_wp_user', 'No linked WordPress user. Run Enable & Repair first.', array( 'status' => 400 ) );
				}
				if ( 'active' !== $portal_status ) {
					return new WP_Error( 'not_active', 'Customer must have active portal access to receive a welcome email.', array( 'status' => 400 ) );
				}
				$result = AJForms_Admin::$instance->send_portal_user_welcome_email( (int) $customer->user_id );
				if ( false === $result ) {
					$result = new WP_Error( 'welcome_failed', 'Welcome email could not be sent.' );
				}
				break;

			case 'delete_archived':
				if ( 'archived' !== $portal_status ) {
					return new WP_Error( 'not_archived', 'Customer must be archived before deletion.', array( 'status' => 400 ) );
				}
				$wpdb->delete( $mapping_table, array( 'stripe_customer_id' => $stripe_customer_id ), array( '%s' ) );
				$pdb->delete( $this->portal_table( 'aj_portal_customer_states' ), array( 'stripe_customer_id' => $stripe_customer_id ), array( '%s' ) );
				$pdb->delete( $customer_table, array( 'stripe_customer_id' => $stripe_customer_id ), array( '%s' ) );
				return rest_ensure_response( array( 'success' => true, 'message' => 'Customer deleted from local portal cache.' ) );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'action_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'message' => $action . ' completed.' ) );
	}

	// ── Portal overview (flat counts for the mobile home screen) ─────────────

	public function get_portal_overview() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_get_portal_overview() );
		}
		$user = wp_get_current_user();
		$name = $user->display_name ?: $user->user_login;
		return rest_ensure_response( array(
			'active_services'       => 0,
			'open_tasks'            => 0,
			'pending_invoices'      => 0,
			'open_service_requests' => 0,
			'welcome_message'       => 'Welcome back, ' . $name . '!',
		) );
	}

	// ── Billing sub-routes ────────────────────────────────────────────────────

	public function get_portal_billing_invoices() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$rows = $this->get_customer_rows(
			'aj_portal_ledger',
			array( 'id', 'stripe_customer_id', 'source_type', 'source_object_id', 'description', 'amount', 'currency', 'status', 'ledger_date', 'invoice_id', 'created_at' ),
			$stripe_customer_id,
			'ledger_date DESC, created_at DESC, id DESC'
		);
		return rest_ensure_response( array( 'invoices' => $rows ) );
	}

	public function get_portal_billing_transactions() {
		if ( AJForms::$instance ) {
			return rest_ensure_response( AJForms::$instance->api_get_portal_ledger() );
		}
		return rest_ensure_response( array( 'transactions' => array() ) );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function format_user( WP_User $user ) {
		return array(
			'id'           => (int) $user->ID,
			'user_login'   => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
		);
	}

	private function get_stripe_customer_id_for_user( WP_User $user ) {
		global $wpdb;
		$mapping_table = $wpdb->prefix . 'aj_auth_user_mappings';
		if ( $this->table_exists( $wpdb, $mapping_table ) ) {
			$customer_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT stripe_customer_id FROM `{$mapping_table}` WHERE user_id = %d OR portal_user_email = %s OR customer_email = %s ORDER BY updated_at DESC, id DESC LIMIT 1",
				(int) $user->ID, $user->user_email, $user->user_email
			) );
			if ( $customer_id ) {
				return sanitize_text_field( (string) $customer_id );
			}
		}
		$pdb             = $this->get_portal_db();
		$customers_table = $this->portal_table( 'aj_portal_stripe_customers' );
		if ( $this->table_exists( $pdb, $customers_table ) ) {
			$customer_id = $pdb->get_var( $pdb->prepare(
				"SELECT stripe_customer_id FROM `{$customers_table}` WHERE email = %s LIMIT 1",
				$user->user_email
			) );
			if ( $customer_id ) {
				return sanitize_text_field( (string) $customer_id );
			}
		}
		return '';
	}
}
