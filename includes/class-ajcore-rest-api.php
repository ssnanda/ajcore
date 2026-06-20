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
			'/portal/me' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_me', 'permission' => 'can_use_portal_api' ),
			'/portal/dashboard' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_dashboard', 'permission' => 'can_use_portal_api' ),
			'/portal/services' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_services', 'permission' => 'can_use_portal_api' ),
			'/portal/billing' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_billing', 'permission' => 'can_use_portal_api' ),
			'/portal/tasks' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_tasks', 'permission' => 'can_use_portal_api' ),
			'/portal/files' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_files', 'permission' => 'can_use_portal_api' ),
			'/portal/service-requests' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_portal_service_requests', 'permission' => 'can_use_portal_api' ),
		);
	}

	public static function get_default_api_settings() {
		return array(
			'enabled'             => '1',
			'ops_enabled'         => '1',
			'portal_enabled'      => '1',
			'public_status'       => '1',
			'master_only'         => '1',
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
		);
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

	private function is_master_api_site() {
		$settings = self::get_api_settings();
		if ( '1' !== (string) $settings['master_only'] ) {
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
		if ( ! $this->is_master_api_site() ) {
			return $this->master_only_error();
		}
		return current_user_can( 'manage_options' );
	}

	public function can_use_portal_api() {
		$enabled = $this->protected_api_enabled( 'portal' );
		if ( is_wp_error( $enabled ) ) {
			return $enabled;
		}
		if ( ! $this->is_master_api_site() ) {
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
		$shared_enabled = function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled();
		$ms_enabled     = function_exists( 'ajcore_is_multisite_portal_enabled' ) && ajcore_is_multisite_portal_enabled();
		$is_master      = $this->is_master_api_site();
		$settings       = self::get_api_settings();

		return rest_ensure_response(
			array(
				'plugin'                     => 'ajcore',
				'version'                    => defined( 'AJCORE_VERSION' ) ? AJCORE_VERSION : '',
				'site_url'                   => home_url( '/' ),
				'site_uuid'                  => get_option( 'ajcore_site_uuid', '' ),
				'shared_db_enabled'          => (bool) $shared_enabled,
				'multisite_portal_enabled'   => (bool) $ms_enabled,
				'is_master_api_site'         => (bool) $is_master,
				'api_available_on_this_site' => (bool) $is_master,
				'api_enabled'                => '1' === (string) $settings['enabled'],
				'ops_api_enabled'            => '1' === (string) $settings['ops_enabled'],
				'portal_api_enabled'         => '1' === (string) $settings['portal_enabled'],
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
		return rest_ensure_response( array( 'customers' => $this->select_rows( $this->portal_table( 'aj_portal_stripe_customers' ), array( 'stripe_customer_id', 'email', 'name', 'phone', 'portal_status', 'enabled_portal', 'livemode', 'synced_at' ), $request, array( 'name', 'email', 'stripe_customer_id' ), 'synced_at DESC, id DESC' ) ) );
	}

	public function get_ops_customer( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$customer = $this->table_exists( $pdb, $customer_table ) ? $pdb->get_row( $pdb->prepare( "SELECT stripe_customer_id, email, name, phone, address, metadata, portal_status, enabled_portal, livemode, synced_at FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ), ARRAY_A ) : null;
		if ( ! $customer ) {
			return new WP_Error( 'ajcore_customer_not_found', __( 'Customer not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'customer'         => $this->decode_json_fields( $customer, array( 'address', 'metadata' ) ),
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
		return rest_ensure_response(
			array(
				'user' => array(
					'id'           => (int) $user->ID,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
					'roles'        => array_values( (array) $user->roles ),
				),
				'site_uuid'          => get_option( 'ajcore_site_uuid', '' ),
				'stripe_customer_id' => $this->get_current_user_stripe_customer_id(),
			)
		);
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
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$rows = $this->get_customer_rows( 'aj_portal_service_snapshots', array( 'id', 'stripe_customer_id', 'stripe_subscription_id', 'service_name', 'service_status', 'service_period', 'current_period_start', 'current_period_end', 'next_billing_date', 'amount', 'currency', 'updated_at' ), $stripe_customer_id );
		if ( empty( $rows ) ) {
			$rows = $this->get_customer_rows( 'aj_portal_stripe_subscriptions', array( 'stripe_subscription_id', 'stripe_customer_id', 'status', 'current_period_end', 'items', 'synced_at' ), $stripe_customer_id );
		}
		return rest_ensure_response( array( 'services' => $rows ) );
	}

	public function get_portal_billing() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		return rest_ensure_response( array( 'ledger' => $this->get_customer_rows( 'aj_portal_ledger', array( 'id', 'stripe_customer_id', 'source_type', 'source_id', 'description', 'amount', 'currency', 'status', 'transaction_date', 'due_date', 'created_at' ), $stripe_customer_id, 'created_at DESC, id DESC' ) ) );
	}

	public function get_portal_tasks() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		return rest_ensure_response( array( 'tasks' => $this->get_customer_rows( 'aj_portal_task_statuses', array( 'id', 'task_id', 'stripe_customer_id', 'status', 'completed_at', 'updated_at' ), $stripe_customer_id, 'updated_at DESC, id DESC' ) ) );
	}

	public function get_portal_files() {
		return rest_ensure_response( array( 'files' => $this->get_current_user_file_rows() ) );
	}

	public function get_portal_service_requests() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		return rest_ensure_response( array( 'service_requests' => $this->get_customer_rows( 'aj_portal_service_requests', array( 'id', 'stripe_customer_id', 'title', 'status', 'service_status', 'amount', 'currency', 'created_at', 'updated_at' ), $stripe_customer_id, 'updated_at DESC, id DESC' ) ) );
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
}
