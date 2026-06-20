<?php
/**
 * AJ Core REST API.
 *
 * Provides a small API foundation for OPS and customer-facing apps.
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
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_summary' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_customers' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'per_page' => array(
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
					'search' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/portal/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_portal_me' ),
				'permission_callback' => array( $this, 'can_use_portal_api' ),
			)
		);
	}

	private function is_master_api_site() {
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
		if ( ! $this->is_master_api_site() ) {
			return $this->master_only_error();
		}

		return current_user_can( 'manage_options' );
	}

	public function can_use_portal_api() {
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

		return rest_ensure_response(
			array(
				'plugin'                    => 'ajcore',
				'version'                   => defined( 'AJCORE_VERSION' ) ? AJCORE_VERSION : '',
				'site_url'                  => home_url( '/' ),
				'site_uuid'                 => get_option( 'ajcore_site_uuid', '' ),
				'shared_db_enabled'         => (bool) $shared_enabled,
				'multisite_portal_enabled'  => (bool) $ms_enabled,
				'is_master_api_site'        => (bool) $is_master,
				'api_available_on_this_site'=> (bool) $is_master,
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
		$pdb      = $this->get_portal_db();
		$table    = $this->portal_table( 'aj_portal_stripe_customers' );
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'customers' => array() ) );
		}

		$where  = '1=1';
		$params = array();
		if ( '' !== $search ) {
			$where    = '(name LIKE %s OR email LIKE %s OR stripe_customer_id LIKE %s)';
			$like     = '%' . $pdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql = "SELECT stripe_customer_id, email, name, phone, portal_status, enabled_portal, livemode, synced_at FROM `{$table}` WHERE {$where} ORDER BY synced_at DESC, id DESC LIMIT %d";
		$params[] = $per_page;
		$rows = $pdb->get_results( $pdb->prepare( $sql, $params ), ARRAY_A );

		return rest_ensure_response( array( 'customers' => is_array( $rows ) ? $rows : array() ) );
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
				'site_uuid' => get_option( 'ajcore_site_uuid', '' ),
			)
		);
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
}
