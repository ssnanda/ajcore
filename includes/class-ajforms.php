<?php

class AJForms {

	public static $instance = null;

	private $plugin_name;
	private $version;

	public function __construct() {
		self::$instance = $this;
		$this->plugin_name = 'ajforms';
		$this->version     = AJFORMS_VERSION;

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_rest_hooks();
		add_filter( 'cron_schedules', array( $this, 'add_ajcore_cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule_recurring_events' ) );
	}

	private function format_us_phone_for_display( $phone ) {
		$phone  = trim( (string) $phone );
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) ) {
			return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}

		return $phone;
	}

	private function load_dependencies() {
		require_once AJFORMS_PLUGIN_DIR . 'admin/class-ajforms-admin.php';
		require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajcore-jwt.php';
		require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajcore-rest-api.php';
		require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajcore-zoho-calendar.php';
		require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajcore-reservations.php';
	}

	private function define_admin_hooks() {
		$plugin_admin = new AJForms_Admin();

		add_action( 'admin_init', array( $plugin_admin, 'handle_admin_actions' ) );
		add_action( 'admin_init', array( $this, 'redirect_frontend_portal_users_from_admin' ), 1 );
		add_action( 'admin_post_ajf_export_form', array( $plugin_admin, 'handle_export_form_request' ) );
		add_action( 'admin_menu', array( $plugin_admin, 'add_plugin_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $plugin_admin, 'display_stripe_mode_admin_notice' ) );
		add_filter( 'plugin_action_links_' . AJFORMS_PLUGIN_BASENAME, array( $plugin_admin, 'add_plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $plugin_admin, 'add_plugin_row_meta_links' ), 10, 2 );

		add_action( 'wp_ajax_ajf_save_form', array( $plugin_admin, 'ajax_save_form' ) );
		add_action( 'wp_ajax_ajf_import_form', array( $plugin_admin, 'ajax_import_form' ) );
		add_action( 'wp_ajax_ajf_sync_asana_reference_data', array( $plugin_admin, 'ajax_sync_asana_reference_data' ) );
		add_action( 'wp_ajax_ajcore_test_shared_db', array( $plugin_admin, 'ajax_test_shared_db_connection' ) );
		add_action( 'wp_ajax_ajcore_init_shared_db_schema', array( $plugin_admin, 'ajax_init_shared_db_schema' ) );
		add_action( 'wp_ajax_ajcore_migrate_portal_data', array( $plugin_admin, 'ajax_migrate_portal_data' ) );
		add_action( 'wp_ajax_ajcore_set_shared_db_master', array( $plugin_admin, 'ajax_set_shared_db_master' ) );
		add_action( 'wp_ajax_ajcore_toggle_multisite_portal', array( $plugin_admin, 'ajax_toggle_multisite_portal' ) );
		add_action( 'ajforms_daily_asana_sync', array( $plugin_admin, 'sync_asana_reference_data' ) );
		add_action( 'ajcore_portal_stripe_sync', array( $plugin_admin, 'run_scheduled_portal_sync_job' ) );

		add_action( 'admin_enqueue_scripts',   array( $this, 'enqueue_username_edit_script' ) );
		add_filter( 'user_profile_update_errors', array( $this, 'validate_username_change' ), 10, 3 );
		add_action( 'personal_options_update',    array( $this, 'save_username_change' ) );
		add_action( 'edit_user_profile_update',   array( $this, 'save_username_change' ) );
	}

	public function add_ajcore_cron_schedules( $schedules ) {
		$schedules['ajcore_every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'ajforms' ),
		);
		$schedules['ajcore_every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'ajforms' ),
		);
		$schedules['ajcore_every_6_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'ajforms' ),
		);
		$schedules['ajcore_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'ajforms' ),
		);

		return $schedules;
	}

	public function schedule_recurring_events() {
		if ( ! wp_next_scheduled( 'ajforms_daily_asana_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ajforms_daily_asana_sync' );
		}

		$settings = get_option( 'ajcore_portal_sync_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$enabled  = ! empty( $settings['enabled'] );
		$frequency = ! empty( $settings['frequency'] ) ? sanitize_key( (string) $settings['frequency'] ) : 'daily';
		$allowed = array( 'hourly', 'twicedaily', 'daily', 'ajcore_every_6_hours', 'ajcore_weekly', 'ajcore_every_30_minutes', 'ajcore_every_15_minutes' );
		if ( ! in_array( $frequency, $allowed, true ) ) {
			$frequency = 'daily';
		}

		$scheduled = wp_get_schedule( 'ajcore_portal_stripe_sync' );
		if ( ! $enabled ) {
			if ( wp_next_scheduled( 'ajcore_portal_stripe_sync' ) ) {
				wp_clear_scheduled_hook( 'ajcore_portal_stripe_sync' );
			}
			return;
		}

		if ( $scheduled && $scheduled !== $frequency ) {
			wp_clear_scheduled_hook( 'ajcore_portal_stripe_sync' );
		}

		if ( ! wp_next_scheduled( 'ajcore_portal_stripe_sync' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, $frequency, 'ajcore_portal_stripe_sync' );
		}
	}

	private function define_rest_hooks() {
		$rest_api = new AJCore_REST_API();
		add_action( 'rest_api_init', array( $rest_api, 'register_routes' ) );
		add_filter( 'determine_current_user', array( $this, 'authenticate_mobile_bearer_token' ), 25 );
	}

	public function authenticate_mobile_bearer_token( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		$auth_header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers     = apache_request_headers();
			$auth_header = isset( $headers['Authorization'] ) ? $headers['Authorization'] : '';
		}
		if ( empty( $auth_header ) || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
			return $user_id;
		}
		$token = trim( substr( $auth_header, 7 ) );
		$uid   = AJCore_JWT::validate( $token );
		if ( false !== $uid && $uid > 0 ) {
			return $uid;
		}
		return $user_id;
	}

	// ── Public API methods called by AJCore_REST_API ──────────────────────────

	public function api_get_portal_services() {
		$context = $this->get_current_user_portal_billing_context();
		if ( '' === $context['stripe_customer_id'] || ! $context['customer'] ) {
			return array( 'services' => array() );
		}
		$subscriptions          = $context['subscriptions'];
		$ledger                 = $context['ledger'];
		$current_subscriptions  = array_values( array_filter( $subscriptions, array( $this, 'is_current_portal_subscription' ) ) );
		$displayable_snapshots  = $this->dedupe_portal_service_snapshots_for_display(
			array_values(
				array_filter(
					$context['service_snapshots'],
					function ( $snapshot ) use ( $current_subscriptions ) {
						return $this->is_displayable_customer_portal_snapshot( $snapshot )
							&& ! $this->recurring_snapshot_is_represented_by_active_subscription( $snapshot, $current_subscriptions );
					}
				)
			)
		);
		$current_snapshots  = array_values( array_filter( $displayable_snapshots, array( $this, 'is_current_portal_service_snapshot' ) ) );
		$past_subscriptions = array_values( array_filter( $subscriptions, function ( $sub ) {
			return ! $this->is_current_portal_subscription( $sub );
		} ) );
		$past_snapshots = array_values( array_filter( $displayable_snapshots, function ( $snap ) {
			return ! $this->is_current_portal_service_snapshot( $snap );
		} ) );

		$services = array();
		foreach ( $current_subscriptions as $subscription ) {
			$ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger );
			$services[] = array(
				'id'                => isset( $subscription->stripe_subscription_id ) ? sanitize_text_field( (string) $subscription->stripe_subscription_id ) : '',
				'type'              => 'subscription',
				'name'              => $this->get_subscription_service_name( $subscription, $ledger_entry ),
				'status'            => isset( $subscription->status ) ? sanitize_key( (string) $subscription->status ) : 'active',
				'service_period'    => $this->get_subscription_service_period( $subscription, $ledger_entry ),
				'next_billing_date' => $this->get_subscription_next_billing_date( $subscription, $ledger_entry ),
				'amount'            => $this->get_subscription_amount_label( $subscription ),
				'is_current'        => true,
			);
		}
		foreach ( $current_snapshots as $snapshot ) {
			$services[] = array(
				'id'                => isset( $snapshot->id ) ? (int) $snapshot->id : 0,
				'type'              => 'snapshot',
				'name'              => $this->get_snapshot_service_name( $snapshot ),
				'status'            => isset( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : 'active',
				'service_period'    => $this->get_snapshot_service_period_label( $snapshot ),
				'next_billing_date' => isset( $snapshot->next_billing_date ) ? sanitize_text_field( (string) $snapshot->next_billing_date ) : '',
				'amount'            => $this->get_snapshot_service_amount_label( $snapshot ),
				'is_current'        => true,
			);
		}
		foreach ( $past_subscriptions as $subscription ) {
			$ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger );
			$services[] = array(
				'id'                => isset( $subscription->stripe_subscription_id ) ? sanitize_text_field( (string) $subscription->stripe_subscription_id ) : '',
				'type'              => 'subscription',
				'name'              => $this->get_subscription_service_name( $subscription, $ledger_entry ),
				'status'            => isset( $subscription->status ) ? sanitize_key( (string) $subscription->status ) : 'canceled',
				'service_period'    => $this->get_subscription_service_period( $subscription, $ledger_entry ),
				'next_billing_date' => '',
				'amount'            => $this->get_subscription_amount_label( $subscription ),
				'is_current'        => false,
			);
		}
		foreach ( $past_snapshots as $snapshot ) {
			$services[] = array(
				'id'                => isset( $snapshot->id ) ? (int) $snapshot->id : 0,
				'type'              => 'snapshot',
				'name'              => $this->get_snapshot_service_name( $snapshot ),
				'status'            => isset( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : 'canceled',
				'service_period'    => $this->get_snapshot_service_period_label( $snapshot ),
				'next_billing_date' => '',
				'amount'            => $this->get_snapshot_service_amount_label( $snapshot ),
				'is_current'        => false,
			);
		}

		// Partner-billed customers (OPUS, Alliance VO, …) see their services without prices —
		// the partner pays, so amounts are never shown to the end customer.
		$customer_partner_key = isset( $context['customer']->partner_key ) ? (string) $context['customer']->partner_key : '';
		if ( '' !== $customer_partner_key ) {
			foreach ( $services as &$service ) {
				$service['amount'] = '';
			}
			unset( $service );
		}

		return array( 'services' => $services );
	}

	public function api_get_portal_ledger() {
		$context = $this->get_current_user_portal_billing_context();
		if ( '' === $context['stripe_customer_id'] || ! $context['customer'] ) {
			return array( 'transactions' => array() );
		}
		$transactions = array();
		foreach ( $context['ledger'] as $entry ) {
			$transactions[] = array(
				'id'               => isset( $entry->id ) ? (int) $entry->id : 0,
				'source_type'      => isset( $entry->source_type ) ? sanitize_text_field( (string) $entry->source_type ) : '',
				'source_object_id' => isset( $entry->source_object_id ) ? sanitize_text_field( (string) $entry->source_object_id ) : '',
				'invoice_id'       => isset( $entry->invoice_id ) ? sanitize_text_field( (string) $entry->invoice_id ) : '',
				'description'      => $this->get_portal_ledger_display_description( $entry ),
				'amount'           => isset( $entry->amount ) ? (float) $entry->amount : 0,
				'currency'         => isset( $entry->currency ) ? sanitize_key( (string) $entry->currency ) : 'usd',
				'status'           => isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '',
				'ledger_date'      => isset( $entry->ledger_date ) ? sanitize_text_field( (string) $entry->ledger_date ) : '',
				'created_at'       => isset( $entry->created_at ) ? sanitize_text_field( (string) $entry->created_at ) : '',
			);
		}
		return array( 'transactions' => $transactions );
	}

	public function api_get_portal_overview() {
		$services_data = $this->api_get_portal_services();
		$services      = isset( $services_data['services'] ) ? (array) $services_data['services'] : array();
		$active_count  = count( array_filter( $services, function ( $s ) {
			return ! empty( $s['is_current'] );
		} ) );

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$pdb                = $this->get_pdb();

		$open_tasks = 0;
		if ( '' !== $stripe_customer_id ) {
			$open_tasks = (int) $pdb->get_var( $pdb->prepare(
				"SELECT COUNT(*) FROM `{$pdb->prefix}aj_portal_task_statuses` WHERE stripe_customer_id = %s AND status != 'completed'",
				$stripe_customer_id
			) );
		}

		$pending_invoices = 0;
		if ( '' !== $stripe_customer_id ) {
			$pending_invoices = (int) $pdb->get_var( $pdb->prepare(
				"SELECT COUNT(*) FROM `{$pdb->prefix}aj_portal_ledger` WHERE stripe_customer_id = %s AND status IN ('open', 'pending', 'unpaid')",
				$stripe_customer_id
			) );
		}

		$open_service_requests = 0;
		if ( '' !== $stripe_customer_id ) {
			$open_service_requests = (int) $pdb->get_var( $pdb->prepare(
				"SELECT COUNT(*) FROM `{$pdb->prefix}aj_portal_service_requests` WHERE stripe_customer_id = %s AND request_type = 'support_request' AND status NOT IN ('closed', 'completed', 'resolved')",
				$stripe_customer_id
			) );
		}

		$user = wp_get_current_user();
		$name = $user->display_name ?: $user->user_login;
		return array(
			'active_services'       => $active_count,
			'open_tasks'            => $open_tasks,
			'pending_invoices'      => $pending_invoices,
			'open_service_requests' => $open_service_requests,
			'welcome_message'       => 'Welcome back, ' . $name . '!',
		);
	}

	public function api_get_portal_tasks() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array( 'tasks' => array() );
		}
		$rows   = $this->get_current_user_portal_tasks();
		$tasks  = array();
		foreach ( (array) $rows as $row ) {
			$tasks[] = array(
				'id'             => isset( $row->id ) ? (int) $row->id : 0,
				'title'          => isset( $row->title ) ? sanitize_text_field( (string) $row->title ) : '',
				'description'    => isset( $row->action_required ) ? wp_kses_post( (string) $row->action_required ) : '',
				'status'         => isset( $row->portal_status ) && '' !== (string) $row->portal_status ? sanitize_key( (string) $row->portal_status ) : ( isset( $row->status ) ? sanitize_key( (string) $row->status ) : 'open' ),
				'due_date'       => isset( $row->due_date ) && $row->due_date ? sanitize_text_field( (string) $row->due_date ) : null,
				'task_scope'     => isset( $row->task_scope ) ? sanitize_key( (string) $row->task_scope ) : 'client',
				'completed_at'   => isset( $row->portal_completed_at ) ? sanitize_text_field( (string) $row->portal_completed_at ) : null,
			);
		}
		return array( 'tasks' => $tasks );
	}

	/**
	 * Returns only client-created service requests (support_request type).
	 * This is the canonical definition of what clients are allowed to see.
	 * Both the web portal renderer and the REST API delegate here so the
	 * visibility rules live in exactly one place.
	 */
	public function api_get_client_service_requests() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array( 'service_requests' => array() );
		}
		$pdb   = $this->get_pdb();
		$table = $this->get_portal_service_requests_table();
		$rows  = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM `{$table}` WHERE stripe_customer_id = %s AND request_type = 'support_request' ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT 100",
				$stripe_customer_id
			)
		);
		return array( 'service_requests' => is_array( $rows ) ? $rows : array() );
	}

	public function api_create_portal_service_request( $title, $description, $priority = 'normal' ) {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'no_customer', __( 'Your account is not linked to a customer profile.', 'ajforms' ), array( 'status' => 403 ) );
		}
		$id = $this->upsert_portal_service_request( array(
			'wp_user_id'         => get_current_user_id(),
			'stripe_customer_id' => $stripe_customer_id,
			'service_name'       => sanitize_text_field( (string) $title ),
			'client_notes'       => sanitize_textarea_field( (string) $description . ( '' !== $priority && 'normal' !== $priority ? "\n\nPriority: " . ucfirst( $priority ) : '' ) ),
			'request_type'       => 'support_request',
			'status'             => 'draft',
			'source'             => 'client_portal',
			'created_by'         => get_current_user_id(),
		) );
		if ( ! $id ) {
			return new WP_Error( 'insert_failed', __( 'Failed to create service request.', 'ajforms' ), array( 'status' => 500 ) );
		}
		return array(
			'id'             => $id,
			'title'          => sanitize_text_field( (string) $title ),
			'description'    => sanitize_textarea_field( (string) $description ),
			'status'         => 'draft',
			'service_status' => 'new',
			'created_at'     => current_time( 'mysql' ),
		);
	}

	public function api_get_portal_store() {
		$context = $this->get_current_user_portal_billing_context();
		if ( '' === $context['stripe_customer_id'] || ! $context['customer'] ) {
			return array( 'products' => array() );
		}
		$products = $this->get_portal_available_service_products( $context['subscriptions'], $context['ledger'] );
		$result   = array();
		foreach ( $products as $product ) {
			$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
			if ( '' === $price_id ) {
				continue;
			}
			$result[] = array(
				'price_id'           => $price_id,
				'product_id'         => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
				'name'               => ! empty( $product->custom_label ) ? sanitize_text_field( (string) $product->custom_label ) : ( isset( $product->name ) ? sanitize_text_field( (string) $product->name ) : '' ),
				'description'        => isset( $product->description ) ? sanitize_text_field( (string) $product->description ) : '',
				'amount'             => isset( $product->price_amount ) ? (float) $product->price_amount : 0,
				'currency'           => isset( $product->currency ) ? sanitize_key( (string) $product->currency ) : 'usd',
				'recurring_interval' => isset( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '',
				'amount_label'       => $this->get_portal_product_amount_label( $product ),
				'can_add'            => ! empty( $product->portal_can_add ),
				'is_owned'           => ! empty( $product->portal_is_owned ),
				'has_open_request'   => ! empty( $product->portal_open_request ),
				'is_upgrade'         => ! empty( $product->portal_is_upgrade ),
			);
		}
		return array( 'products' => $result );
	}

	/**
	 * Cart checkout: create one Stripe checkout session for multiple price IDs.
	 * Delegates to api_portal_add_service when cart has a single item.
	 */
	public function api_portal_checkout_cart( array $price_ids ) {
		$price_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $price_ids ) ) ) );
		if ( empty( $price_ids ) ) {
			return new WP_Error( 'invalid_cart', __( 'Cart is empty.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( 1 === count( $price_ids ) ) {
			return $this->api_portal_add_service( $price_ids[0] );
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Authentication required.', 'ajforms' ), array( 'status' => 401 ) );
		}

		$portal_context  = $this->get_current_user_portal_billing_context();
		$portal_products = ( '' !== $portal_context['stripe_customer_id'] && $portal_context['customer'] )
			? $this->get_portal_available_service_products( $portal_context['subscriptions'], $portal_context['ledger'] )
			: array();

		$allowed_price_map = array();
		foreach ( $portal_products as $available_product ) {
			if ( empty( $available_product->portal_can_add ) ) {
				continue;
			}
			$apid = isset( $available_product->stripe_price_id ) ? sanitize_text_field( (string) $available_product->stripe_price_id ) : '';
			if ( '' === $apid ) {
				continue;
			}
			$allowed_price_map[ $apid ] = array(
				'id'               => $apid,
				'product_id'       => isset( $available_product->stripe_product_id ) ? sanitize_text_field( (string) $available_product->stripe_product_id ) : '',
				'product_name'     => ! empty( $available_product->custom_label ) ? sanitize_text_field( (string) $available_product->custom_label ) : ( isset( $available_product->name ) ? sanitize_text_field( (string) $available_product->name ) : '' ),
				'amount'           => isset( $available_product->price_amount ) ? (float) $available_product->price_amount : 0,
				'currency'         => isset( $available_product->currency ) ? sanitize_key( (string) $available_product->currency ) : 'usd',
				'recurring_interval' => isset( $available_product->recurring_interval ) ? sanitize_key( (string) $available_product->recurring_interval ) : '',
			);
		}

		// Validate every cart item exists in allowed products.
		foreach ( $price_ids as $pid ) {
			if ( empty( $allowed_price_map[ $pid ] ) ) {
				return new WP_Error( 'service_unavailable', sprintf( __( 'Service %s is not available.', 'ajforms' ), $pid ), array( 'status' => 404 ) );
			}
		}

		$stripe_settings = $this->get_stripe_settings();
		$mode_error      = $this->get_stripe_mode_blocking_error( $stripe_settings );
		if ( '' !== $mode_error ) {
			return new WP_Error( 'stripe_error', $mode_error, array( 'status' => 400 ) );
		}
		if ( empty( $stripe_settings['secret_key'] ) ) {
			return new WP_Error( 'stripe_not_connected', __( 'Stripe is not connected.', 'ajforms' ), array( 'status' => 400 ) );
		}

		// Use subscription mode if any item is recurring.
		$has_recurring = false;
		foreach ( $price_ids as $pid ) {
			if ( ! empty( $allowed_price_map[ $pid ]['recurring_interval'] ) ) {
				$has_recurring = true;
				break;
			}
		}
		$checkout_mode = $has_recurring ? 'subscription' : 'payment';

		$return_url  = home_url( '/' );
		$success_url = str_replace(
			'%7BCHECKOUT_SESSION_ID%7D',
			'{CHECKOUT_SESSION_ID}',
			add_query_arg( array( 'ajcore_checkout' => 'success', 'session_id' => '{CHECKOUT_SESSION_ID}' ), $return_url )
		);
		$cancel_url = add_query_arg( 'ajcore_checkout', 'cancelled', $return_url );

		$body = array(
			'mode'        => $checkout_mode,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
		);

		$mapped_stripe_customer_id = $portal_context['stripe_customer_id'];
		if ( 0 === strpos( $mapped_stripe_customer_id, 'cus_' ) ) {
			$body['customer'] = $mapped_stripe_customer_id;
		} elseif ( 'payment' === $checkout_mode ) {
			$body['customer_creation'] = 'always';
		}

		$total_amount = 0;
		$currency     = 'usd';
		$names        = array();
		foreach ( array_values( $price_ids ) as $i => $pid ) {
			$body[ "line_items[{$i}][price]" ]    = $pid;
			$body[ "line_items[{$i}][quantity]" ] = 1;
			$total_amount += isset( $allowed_price_map[ $pid ]['amount'] ) ? (float) $allowed_price_map[ $pid ]['amount'] : 0;
			$currency      = isset( $allowed_price_map[ $pid ]['currency'] ) ? sanitize_key( $allowed_price_map[ $pid ]['currency'] ) : $currency;
			$names[]       = isset( $allowed_price_map[ $pid ]['product_name'] ) ? $allowed_price_map[ $pid ]['product_name'] : $pid;
		}
		$body['metadata[price_ids]']   = implode( ',', $price_ids );
		$body['metadata[source]']      = 'ajcore_portal_cart';
		if ( '' !== $mapped_stripe_customer_id ) {
			$body['metadata[stripe_customer_id]'] = $mapped_stripe_customer_id;
		}

		$response = $this->stripe_api_request( 'checkout/sessions', $stripe_settings['secret_key'], $body, 'POST' );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_request_failed', $response->get_error_message(), array( 'status' => 400 ) );
		}

		if ( ! empty( $response['id'] ) && '' !== $mapped_stripe_customer_id ) {
			$session_id  = sanitize_text_field( (string) $response['id'] );
			$checkout_url = ! empty( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '';
			$cart_description = implode( ', ', $names );
			$metadata = array(
				'checkout_url' => $checkout_url,
				'price_ids'    => $price_ids,
				'product_names'=> $names,
				'source'       => 'portal_cart',
			);
			$this->get_pdb()->replace(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'source_object_id'   => $session_id,
					'source_type'        => 'checkout_session',
					'ledger_date'        => current_time( 'mysql' ),
					'description'        => sprintf( __( 'Cart checkout: %s', 'ajforms' ), $cart_description ),
					'amount'             => $total_amount,
					'currency'           => $currency,
					'status'             => 'unpaid',
					'invoice_id'         => '',
					'payment_intent_id'  => '',
					'charge_id'          => '',
					'metadata'           => wp_json_encode( $metadata ),
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			// One service request per cart item for admin tracking.
			foreach ( $price_ids as $pid ) {
				$p = $allowed_price_map[ $pid ];
				$this->upsert_portal_service_request( array(
					'wp_user_id'         => get_current_user_id(),
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'stripe_price_id'    => $pid,
					'stripe_product_id'  => isset( $p['product_id'] ) ? $p['product_id'] : '',
					'service_name'       => isset( $p['product_name'] ) ? $p['product_name'] : $pid,
					'request_type'       => 'add_service',
					'status'             => 'pending_payment',
					'amount'             => isset( $p['amount'] ) ? (float) $p['amount'] : 0,
					'currency'           => isset( $p['currency'] ) ? sanitize_key( $p['currency'] ) : 'usd',
					'source_object_id'   => $session_id,
					'source_type'        => 'checkout_session',
					'source'             => 'client_portal',
					'created_by'         => get_current_user_id(),
					'raw_data'           => $response,
				) );
			}
		}

		return array(
			'url'             => isset( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '',
			'session_id'      => isset( $response['id'] ) ? sanitize_text_field( (string) $response['id'] ) : '',
			'publishable_key' => isset( $stripe_settings['publishable_key'] ) ? trim( sanitize_text_field( (string) $stripe_settings['publishable_key'] ) ) : '',
		);
	}

	// ── Server-side cart ──────────────────────────────────────────────────

	private function get_portal_carts_table() {
		return $this->get_pdb()->prefix . 'aj_portal_carts';
	}

	private function ensure_carts_table() {
		$pdb   = $this->get_pdb();
		$table = $this->get_portal_carts_table();
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$charset_collate = $pdb->get_charset_collate();
			$pdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				stripe_customer_id varchar(100) NOT NULL,
				wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				price_id varchar(100) NOT NULL,
				quantity int(11) NOT NULL DEFAULT 1,
				added_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY unique_customer_price (stripe_customer_id, price_id),
				KEY stripe_customer_id (stripe_customer_id),
				KEY wp_user_id (wp_user_id)
			) {$charset_collate}" );
		}
	}

	public function api_get_portal_cart() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array( 'items' => array(), 'total' => 0, 'currency' => 'usd', 'count' => 0 );
		}
		$this->ensure_carts_table();
		$pdb   = $this->get_pdb();
		$table = $this->get_portal_carts_table();
		$rows  = $pdb->get_results( $pdb->prepare( "SELECT price_id, quantity, added_at FROM {$table} WHERE stripe_customer_id = %s ORDER BY added_at ASC", $stripe_customer_id ) );

		// Enrich cart rows with product details from the store catalog.
		$portal_context = $this->get_current_user_portal_billing_context();
		$all_products   = ( $portal_context['customer'] )
			? $this->get_portal_available_service_products( $portal_context['subscriptions'], $portal_context['ledger'] )
			: array();
		$product_map = array();
		foreach ( $all_products as $p ) {
			$pid = isset( $p->stripe_price_id ) ? (string) $p->stripe_price_id : '';
			if ( '' !== $pid ) {
				$product_map[ $pid ] = $p;
			}
		}

		$items    = array();
		$total    = 0.0;
		$currency = 'usd';
		foreach ( (array) $rows as $row ) {
			$pid = (string) $row->price_id;
			$p   = isset( $product_map[ $pid ] ) ? $product_map[ $pid ] : null;
			$amount = $p ? (float) $p->price_amount : 0.0;
			$currency = $p && isset( $p->currency ) ? sanitize_key( (string) $p->currency ) : $currency;
			$total   += $amount * (int) $row->quantity;
			$items[] = array(
				'price_id'           => $pid,
				'product_id'         => $p ? (string) $p->stripe_product_id : '',
				'name'               => $p ? ( ! empty( $p->custom_label ) ? (string) $p->custom_label : (string) $p->name ) : $pid,
				'description'        => $p ? (string) ( $p->description ?? '' ) : '',
				'amount'             => $amount,
				'amount_label'       => $p ? (string) ( $p->amount_label ?? '' ) : '',
				'currency'           => $currency,
				'recurring_interval' => $p ? (string) ( $p->recurring_interval ?? '' ) : '',
				'quantity'           => (int) $row->quantity,
				'added_at'           => (string) $row->added_at,
			);
		}
		return array( 'items' => $items, 'total' => $total, 'currency' => $currency, 'count' => count( $items ) );
	}

	public function api_portal_cart_add( $price_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			return new WP_Error( 'invalid_price', __( 'A price_id is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'no_customer', __( 'No Stripe customer found for this account.', 'ajforms' ), array( 'status' => 404 ) );
		}

		// Validate the price exists and is addable.
		$portal_context = $this->get_current_user_portal_billing_context();
		$all_products   = ( $portal_context['customer'] )
			? $this->get_portal_available_service_products( $portal_context['subscriptions'], $portal_context['ledger'] )
			: array();
		$found = false;
		foreach ( $all_products as $p ) {
			if ( isset( $p->stripe_price_id ) && (string) $p->stripe_price_id === $price_id && ! empty( $p->portal_can_add ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'service_unavailable', __( 'Service is not available for your account.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$this->ensure_carts_table();
		$pdb   = $this->get_pdb();
		$table = $this->get_portal_carts_table();
		$pdb->replace( $table, array(
			'stripe_customer_id' => $stripe_customer_id,
			'wp_user_id'         => get_current_user_id(),
			'price_id'           => $price_id,
			'quantity'           => 1,
			'added_at'           => current_time( 'mysql' ),
		), array( '%s', '%d', '%s', '%d', '%s' ) );

		return $this->api_get_portal_cart();
	}

	public function api_portal_cart_remove( $price_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id || '' === $price_id ) {
			return new WP_Error( 'invalid_request', __( 'Invalid request.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$this->ensure_carts_table();
		$pdb   = $this->get_pdb();
		$table = $this->get_portal_carts_table();
		$pdb->delete( $table, array( 'stripe_customer_id' => $stripe_customer_id, 'price_id' => $price_id ), array( '%s', '%s' ) );
		return $this->api_get_portal_cart();
	}

	public function api_portal_cart_clear() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array( 'items' => array(), 'total' => 0, 'currency' => 'usd', 'count' => 0 );
		}
		$this->ensure_carts_table();
		$pdb   = $this->get_pdb();
		$table = $this->get_portal_carts_table();
		$pdb->delete( $table, array( 'stripe_customer_id' => $stripe_customer_id ), array( '%s' ) );
		return array( 'items' => array(), 'total' => 0, 'currency' => 'usd', 'count' => 0 );
	}

	public function api_portal_cart_checkout() {
		$cart = $this->api_get_portal_cart();
		if ( empty( $cart['items'] ) ) {
			return new WP_Error( 'empty_cart', __( 'Your cart is empty.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$price_ids = array_column( $cart['items'], 'price_id' );
		$result    = $this->api_portal_checkout_cart( $price_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Clear the cart on successful session creation.
		$this->api_portal_cart_clear();
		return $result;
	}

	public function api_portal_add_service( $price_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			return new WP_Error( 'invalid_price', __( 'A price ID is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'not_logged_in', __( 'Authentication required.', 'ajforms' ), array( 'status' => 401 ) );
		}

		$portal_context  = $this->get_current_user_portal_billing_context();
		$portal_products = ( '' !== $portal_context['stripe_customer_id'] && $portal_context['customer'] )
			? $this->get_portal_available_service_products( $portal_context['subscriptions'], $portal_context['ledger'] )
			: array();

		$allowed_price_map    = array();
		$portal_upgrade_items = array();

		foreach ( $portal_products as $available_product ) {
			if ( empty( $available_product->portal_can_add ) ) {
				continue;
			}
			$available_price_id = isset( $available_product->stripe_price_id ) ? sanitize_text_field( (string) $available_product->stripe_price_id ) : '';
			if ( '' === $available_price_id ) {
				continue;
			}
			$allowed_price_map[ $available_price_id ] = array(
				'id'                           => $available_price_id,
				'product_id'                   => isset( $available_product->stripe_product_id ) ? sanitize_text_field( (string) $available_product->stripe_product_id ) : '',
				'product_name'                 => ! empty( $available_product->custom_label ) ? sanitize_text_field( (string) $available_product->custom_label ) : ( isset( $available_product->name ) ? sanitize_text_field( (string) $available_product->name ) : '' ),
				'amount'                       => isset( $available_product->price_amount ) ? (float) $available_product->price_amount : 0,
				'currency'                     => isset( $available_product->currency ) ? sanitize_key( (string) $available_product->currency ) : 'usd',
				'recurring_interval'           => isset( $available_product->recurring_interval ) ? sanitize_key( (string) $available_product->recurring_interval ) : '',
				'duplicate_behavior'           => $this->get_portal_product_duplicate_behavior( $available_product ),
				'upgrade_from_product_id'      => ! empty( $available_product->portal_upgrade_from_product_id ) ? sanitize_text_field( (string) $available_product->portal_upgrade_from_product_id ) : '',
				'upgrade_from_subscription_id' => ! empty( $available_product->portal_upgrade_from_subscription_id ) ? sanitize_text_field( (string) $available_product->portal_upgrade_from_subscription_id ) : '',
			);
		}
		$allowed_price_map = array_column( $this->apply_public_product_dependency_settings_to_prices( array_values( $allowed_price_map ) ), null, 'id' );
		foreach ( array_values( $allowed_price_map ) as $allowed_price ) {
			$required_price_id = ! empty( $allowed_price['requires_price_id'] ) ? sanitize_text_field( (string) $allowed_price['requires_price_id'] ) : '';
			if ( '' === $required_price_id || ! empty( $allowed_price_map[ $required_price_id ] ) ) {
				continue;
			}
			$required_price = $this->get_portal_dependency_price_checkout_data( $required_price_id );
			if ( $required_price ) {
				$allowed_price_map[ $required_price_id ] = $required_price;
			}
		}

		$portal_product = $this->get_portal_product_by_price_id( $price_id );
		if ( ! $portal_product || empty( $allowed_price_map[ $price_id ] ) ) {
			return new WP_Error( 'service_unavailable', __( 'Service is not available.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$price         = $allowed_price_map[ $price_id ];
		$checkout_mode = ! empty( $price['recurring_interval'] ) ? 'subscription' : 'payment';

		if ( 'upgrade' === ( isset( $price['duplicate_behavior'] ) ? sanitize_key( (string) $price['duplicate_behavior'] ) : '' ) ) {
			$upgrade_from_subscription_id = ! empty( $price['upgrade_from_subscription_id'] ) ? sanitize_text_field( (string) $price['upgrade_from_subscription_id'] ) : '';
			if ( '' === $upgrade_from_subscription_id || 0 !== strpos( $upgrade_from_subscription_id, 'sub_' ) ) {
				return new WP_Error( 'upgrade_unavailable', __( 'This upgrade is not available for your current services.', 'ajforms' ), array( 'status' => 400 ) );
			}
			$portal_upgrade_items[] = array(
				'from_product_id'      => ! empty( $price['upgrade_from_product_id'] ) ? sanitize_text_field( (string) $price['upgrade_from_product_id'] ) : '',
				'from_subscription_id' => $upgrade_from_subscription_id,
				'to_product_id'        => ! empty( $price['product_id'] ) ? sanitize_text_field( (string) $price['product_id'] ) : '',
				'to_price_id'          => $price_id,
			);
		}

		$stripe_settings = $this->get_stripe_settings();
		$mode_error      = $this->get_stripe_mode_blocking_error( $stripe_settings );
		if ( '' !== $mode_error ) {
			return new WP_Error( 'stripe_error', $mode_error, array( 'status' => 400 ) );
		}
		if ( empty( $stripe_settings['secret_key'] ) ) {
			return new WP_Error( 'stripe_not_connected', __( 'Stripe is not connected.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$return_url  = home_url( '/' );
		$success_url = str_replace(
			'%7BCHECKOUT_SESSION_ID%7D',
			'{CHECKOUT_SESSION_ID}',
			add_query_arg( array( 'ajcore_checkout' => 'success', 'session_id' => '{CHECKOUT_SESSION_ID}' ), $return_url )
		);
		$cancel_url = add_query_arg( 'ajcore_checkout', 'cancelled', $return_url );

		$body = array(
			'mode'        => $checkout_mode,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
		);

		$mapped_stripe_customer_id = $portal_context['stripe_customer_id'];
		if ( 0 === strpos( $mapped_stripe_customer_id, 'cus_' ) ) {
			$body['customer'] = $mapped_stripe_customer_id;
		} elseif ( 'payment' === $checkout_mode ) {
			$body['customer_creation'] = 'always';
		}

		$body['line_items[0][price]']    = $price_id;
		$body['line_items[0][quantity]'] = 1;
		$body['metadata[price_id]']      = $price_id;
		$body['metadata[product_id]']    = isset( $price['product_id'] ) ? $price['product_id'] : '';
		$body['metadata[source]']        = 'ajcore_portal_add_service';
		if ( '' !== $mapped_stripe_customer_id ) {
			$body['metadata[stripe_customer_id]'] = $mapped_stripe_customer_id;
		}

		if ( ! empty( $portal_upgrade_items ) ) {
			$primary_upgrade = reset( $portal_upgrade_items );
			$body['metadata[ajcore_upgrade]']                              = '1';
			$body['metadata[ajcore_upgrade_items]']                        = wp_json_encode( $portal_upgrade_items );
			$body['metadata[ajcore_upgrade_from_product_id]']              = $primary_upgrade['from_product_id'];
			$body['metadata[ajcore_upgrade_from_subscription_id]']         = $primary_upgrade['from_subscription_id'];
			$body['metadata[ajcore_upgrade_to_product_id]']                = $primary_upgrade['to_product_id'];
			$body['metadata[ajcore_upgrade_to_price_id]']                  = $primary_upgrade['to_price_id'];
			if ( 'subscription' === $checkout_mode ) {
				$body['subscription_data[metadata][ajcore_upgrade]']                          = '1';
				$body['subscription_data[metadata][ajcore_upgrade_items]']                    = wp_json_encode( $portal_upgrade_items );
				$body['subscription_data[metadata][ajcore_upgrade_from_subscription_id]']     = $primary_upgrade['from_subscription_id'];
			}
		}

		$response = $this->stripe_api_request( 'checkout/sessions', $stripe_settings['secret_key'], $body, 'POST' );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_request_failed', $response->get_error_message(), array( 'status' => 400 ) );
		}

		if ( ! empty( $response['id'] ) && '' !== $mapped_stripe_customer_id ) {
			global $wpdb;
			$line_product       = $this->get_portal_product_by_price_id( $price_id );
			$product_name       = $line_product
				? ( ! empty( $line_product->custom_label ) ? sanitize_text_field( (string) $line_product->custom_label ) : ( ! empty( $line_product->name ) ? sanitize_text_field( (string) $line_product->name ) : $price_id ) )
				: __( 'Additional services', 'ajforms' );
			$total_amount       = isset( $price['amount'] ) ? (float) $price['amount'] : 0;
			$currency           = isset( $price['currency'] ) ? sanitize_key( (string) $price['currency'] ) : 'usd';
			$checkout_url       = ! empty( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '';
			$product_description = sprintf( __( 'Service request: %s', 'ajforms' ), $product_name );
			$metadata = array(
				'checkout_url'                   => $checkout_url,
				'checkout_session_client_secret' => ! empty( $response['client_secret'] ) ? sanitize_text_field( (string) $response['client_secret'] ) : '',
				'price_id'                       => $price_id,
				'price_ids'                      => array( $price_id ),
				'product_id'                     => isset( $price['product_id'] ) ? $price['product_id'] : '',
				'product_name'                   => $product_name,
				'source'                         => 'portal_add_service',
				'upgrade_items'                  => $portal_upgrade_items,
			);
			$wpdb->replace(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'source_object_id'   => sanitize_text_field( (string) $response['id'] ),
					'source_type'        => 'checkout_session',
					'ledger_date'        => current_time( 'mysql' ),
					'description'        => $product_description,
					'amount'             => $total_amount,
					'currency'           => $currency,
					'status'             => 'unpaid',
					'invoice_id'         => '',
					'payment_intent_id'  => '',
					'charge_id'          => '',
					'metadata'           => wp_json_encode( $metadata ),
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$this->upsert_portal_service_request( array(
				'wp_user_id'         => get_current_user_id(),
				'stripe_customer_id' => $mapped_stripe_customer_id,
				'stripe_price_id'    => $price_id,
				'stripe_product_id'  => isset( $price['product_id'] ) ? $price['product_id'] : '',
				'service_name'       => $product_name,
				'request_type'       => 'add_service',
				'status'             => 'pending_payment',
				'amount'             => $total_amount,
				'currency'           => $currency,
				'source_object_id'   => sanitize_text_field( (string) $response['id'] ),
				'source_type'        => 'checkout_session',
				'source'             => 'client_portal',
				'created_by'         => get_current_user_id(),
				'raw_data'           => $response,
			) );
		}

		return array(
			'url'             => isset( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '',
			'session_id'      => isset( $response['id'] ) ? sanitize_text_field( (string) $response['id'] ) : '',
			'publishable_key' => isset( $stripe_settings['publishable_key'] ) ? trim( sanitize_text_field( (string) $stripe_settings['publishable_key'] ) ) : '',
		);
	}

	private function define_public_hooks() {
		add_shortcode( 'ajforms', array( $this, 'render_form_shortcode' ) );
		add_shortcode( 'ajcore_products', array( $this, 'render_products_shortcode' ) );
		add_shortcode( 'aj_customer_portal', array( $this, 'render_customer_portal_shortcode' ) );
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 999999, 3 );
		add_filter( 'show_admin_bar', array( $this, 'filter_show_admin_bar' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_wp_mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_wp_mail_from_name' ) );
		add_filter( 'retrieve_password_notification_email', array( $this, 'filter_wp_password_reset_email' ), 10, 4 );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_custom_login_styles' ) );
		add_filter( 'login_headerurl', array( $this, 'filter_login_header_url' ) );
		add_filter( 'login_headertext', array( $this, 'filter_login_header_text' ) );
		add_action( 'init', array( $this, 'maybe_create_customer_portal_page' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_form_preview' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_impersonation_request' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_impersonation_return' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_staff_portal_switch' ), 0 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_guest_customer_portal' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_file_upload' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_file_download' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_service_request_remove' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_task_action' ) );
		add_action( 'template_redirect', array( $this, 'maybe_finalize_mixed_checkout_from_success_url' ) );
		add_action( 'init', array( $this, 'maybe_handle_stripe_webhook' ) );
		add_action( 'wp_ajax_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_ajcore_create_checkout_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_nopriv_ajcore_create_checkout_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_ajcore_create_custom_service_request', array( $this, 'ajax_create_custom_service_request' ) );
		add_action( 'wp_ajax_ajcore_submit_portal_service_request', array( $this, 'ajax_submit_portal_service_request' ) );
		add_action( 'wp_ajax_ajcore_cancel_portal_service_request', array( $this, 'ajax_cancel_portal_service_request' ) );
		add_action( 'wp_ajax_ajcore_pay_portal_ledger', array( $this, 'ajax_pay_portal_ledger' ) );
		add_action( 'wp_ajax_ajcore_reservation_check_availability', array( $this, 'ajax_reservation_check_availability' ) );
		add_action( 'wp_ajax_ajcore_reservation_create_checkout',  array( $this, 'ajax_reservation_create_checkout' ) );
		add_action( 'wp_ajax_ajcore_reservation_request',          array( $this, 'ajax_reservation_request' ) );
		add_action( 'wp_ajax_ajcore_reservation_add_to_cart',      array( $this, 'ajax_reservation_add_to_cart' ) );
		add_action( 'wp_ajax_ajcore_reservation_cart_checkout',    array( $this, 'ajax_reservation_cart_checkout' ) );
		add_action( 'wp_ajax_ajcore_reservation_cart_remove',      array( $this, 'ajax_reservation_cart_remove' ) );
		add_action( 'wp_ajax_ajcore_reservation_get_cart',         array( $this, 'ajax_reservation_get_cart' ) );
		add_action( 'wp_ajax_ajcore_stripe_customer_portal',       array( $this, 'ajax_stripe_customer_portal' ) );
		add_action( 'wp_ajax_ajcore_test_zoho_connection', array( $this, 'ajax_test_zoho_connection' ) );
		add_action( 'wp_ajax_ajcore_get_reservation_events', array( $this, 'ajax_get_reservation_events' ) );
		add_action( 'wp_ajax_nopriv_ajcore_get_reservation_events', array( $this, 'ajax_get_reservation_events' ) );
	}

	private function get_custom_login_logo_url() {
		$logo_id = absint( get_theme_mod( 'custom_logo' ) );
		if ( ! $logo_id ) {
			$logo_id = absint( get_option( 'site_logo' ) );
		}

		if ( $logo_id ) {
			$image = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				return esc_url_raw( $image[0] );
			}
		}

		$custom_logo = get_custom_logo();
		if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $custom_logo, $matches ) && ! empty( $matches[1] ) ) {
			return esc_url_raw( html_entity_decode( $matches[1], ENT_QUOTES ) );
		}

		return '';
	}

	public function filter_login_header_url( $url ) {
		return home_url( '/' );
	}

	public function filter_login_header_text( $text ) {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public function enqueue_custom_login_styles() {
		$logo_url  = $this->get_custom_login_logo_url();
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		?>
		<style>
			body.login {
				min-height: 100vh;
				background:
					radial-gradient(circle at top left, rgba(14, 165, 233, 0.16), transparent 34rem),
					radial-gradient(circle at top right, rgba(99, 102, 241, 0.18), transparent 36rem),
					linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
				color: #0f172a;
				font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			}
			body.login #login {
				width: min(420px, calc(100vw - 32px));
				padding: 7vh 0 0;
			}
			body.login h1 {
				margin: 0 0 22px;
			}
			body.login h1 a {
				width: min(300px, 86vw);
				height: 88px;
				margin: 0 auto;
				background-position: center;
				background-repeat: no-repeat;
				background-size: contain;
				color: transparent;
			}
			<?php if ( '' !== $logo_url ) : ?>
			body.login h1 a {
				background-image: url("<?php echo esc_url( $logo_url ); ?>");
			}
			<?php else : ?>
			body.login h1 a {
				width: auto;
				height: auto;
				text-indent: 0;
				background: none;
				color: #0f172a;
				font-size: 28px;
				font-weight: 800;
				line-height: 1.15;
				text-decoration: none;
			}
			body.login h1 a:before {
				content: "<?php echo esc_js( $site_name ); ?>";
			}
			<?php endif; ?>
			body.login form {
				margin-top: 0;
				padding: 30px;
				border: 1px solid #dbe7f5;
				border-radius: 22px;
				background: rgba(255, 255, 255, 0.94);
				box-shadow: 0 24px 70px rgba(15, 23, 42, 0.14);
			}
			body.login label {
				color: #1e293b;
				font-size: 14px;
				font-weight: 700;
			}
			body.login form .input,
			body.login input[type="text"],
			body.login input[type="password"],
			body.login input[type="email"] {
				min-height: 48px;
				padding: 10px 14px;
				border: 1px solid #cbd7e6;
				border-radius: 12px;
				background: #f8fafc;
				color: #0f172a;
				font-size: 17px;
				box-shadow: none;
			}
			body.login form .input:focus,
			body.login input[type="text"]:focus,
			body.login input[type="password"]:focus,
			body.login input[type="email"]:focus {
				border-color: #2563eb;
				background: #fff;
				box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
			}
			body.login .button.wp-hide-pw {
				color: #475569;
			}
			body.login .button-primary {
				min-height: 46px;
				padding: 0 22px;
				border: 0;
				border-radius: 999px;
				background: linear-gradient(135deg, #2563eb 0%, #6d3df5 100%);
				color: #fff;
				font-size: 15px;
				font-weight: 800;
				box-shadow: 0 14px 32px rgba(37, 99, 235, 0.28);
			}
			body.login .button-primary:hover,
			body.login .button-primary:focus {
				background: linear-gradient(135deg, #1d4ed8 0%, #5b21d9 100%);
				box-shadow: 0 16px 36px rgba(37, 99, 235, 0.34);
			}
			body.login #nav,
			body.login #backtoblog {
				text-align: center;
			}
			body.login #nav a,
			body.login #backtoblog a,
			body.login .privacy-policy-page-link a {
				color: #334155;
				font-weight: 700;
				text-decoration: none;
			}
			body.login #nav a:hover,
			body.login #backtoblog a:hover,
			body.login .privacy-policy-page-link a:hover {
				color: #2563eb;
			}
			body.login .message,
			body.login .notice,
			body.login #login_error {
				border-left: 0;
				border-radius: 14px;
				box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
			}
			body.login .language-switcher {
				margin-top: 20px;
			}
			body.login .language-switcher select {
				border-radius: 10px;
			}
		</style>
		<?php
	}

	public function filter_wp_mail_from( $email ) {
		$settings = get_option( 'ajforms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$from_email = ! empty( $settings['wp_email_from_email'] ) ? sanitize_email( (string) $settings['wp_email_from_email'] ) : ( defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? sanitize_email( AJCORE_SYSTEM_FROM_EMAIL ) : 'donotreply@ncllcagents.com' );

		return is_email( $from_email ) ? $from_email : $email;
	}

	public function filter_wp_mail_from_name( $name ) {
		$settings = get_option( 'ajforms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$from_name = ! empty( $settings['wp_email_from_name'] ) ? sanitize_text_field( (string) $settings['wp_email_from_name'] ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		return '' !== $from_name ? $from_name : $name;
	}

	public function filter_wp_password_reset_email( $defaults, $key, $user_login, $user_data ) {
		$settings = get_option( 'ajforms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		if ( isset( $settings['wp_email_templates_enabled'] ) && '1' !== (string) $settings['wp_email_templates_enabled'] ) {
			return $defaults;
		}

		if ( ! $user_data instanceof WP_User ) {
			return $defaults;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ),
			'login'
		);
		$subject = ! empty( $settings['wp_password_reset_subject'] ) ? sanitize_text_field( (string) $settings['wp_password_reset_subject'] ) : __( 'Password reset for your Portal Login for NC LLC Agents Inc', 'ajforms' );
		$defaults['subject'] = $subject;
		$defaults['headers'] = array( 'Content-Type: text/html; charset=UTF-8' );
		$defaults['message'] = $this->build_wp_email_template(
			array(
				'headline'      => __( 'Set your client portal password', 'ajforms' ),
				'greeting'      => sprintf( __( 'Hi %s,', 'ajforms' ), $user_data->display_name ),
				'body'          => __( 'Use the secure button below to create a new password for your account. This link is private and should only be used by you.', 'ajforms' ),
				'button_label'  => __( 'Set New Password', 'ajforms' ),
				'button_url'    => $reset_url,
				'link_intro'    => __( 'If the button does not work, copy and paste this link into your browser:', 'ajforms' ),
				'footer'        => __( 'If you did not request this email, you can ignore it.', 'ajforms' ),
			)
		);

		return $defaults;
	}

	private function build_wp_email_template( $args ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$args = wp_parse_args(
			$args,
			array(
				'headline'     => '',
				'greeting'     => '',
				'body'         => '',
				'button_label' => '',
				'button_url'   => '',
				'link_intro'   => '',
				'footer'       => '',
			)
		);

		return sprintf(
			'<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fc;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
				<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:#f6f8fc;padding:32px 16px;">
					<tr><td align="center">
						<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dbe7f3;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15,23,42,.10);">
							<tr><td style="height:8px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed);font-size:0;line-height:0;">&nbsp;</td></tr>
							<tr><td style="padding:34px 34px 30px;">
								<p style="margin:0 0 10px;color:#2563eb;font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">%1$s</p>
								<h1 style="margin:0 0 16px;font-size:30px;line-height:1.15;color:#0f172a;">%2$s</h1>
								<p style="margin:0 0 18px;font-size:17px;line-height:1.65;color:#334155;">%3$s</p>
								<p style="margin:0 0 28px;font-size:16px;line-height:1.6;color:#475569;">%4$s</p>
								<p style="margin:0 0 28px;"><a href="%5$s" style="display:inline-block;background:#3157ff;color:#ffffff;text-decoration:none;border-radius:999px;padding:14px 24px;font-size:16px;font-weight:800;box-shadow:0 12px 28px rgba(49,87,255,.28);">%6$s</a></p>
								<p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#64748b;">%7$s</p>
								<p style="margin:0;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;font-size:14px;line-height:1.55;word-break:break-all;"><a href="%5$s" style="color:#2563eb;text-decoration:underline;">%5$s</a></p>
								<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#64748b;">%8$s</p>
							</td></tr>
						</table>
					</td></tr>
				</table>
			</body></html>',
			esc_html( $site_name ),
			esc_html( $args['headline'] ),
			esc_html( $args['greeting'] ),
			esc_html( $args['body'] ),
			esc_url( $args['button_url'] ),
			esc_html( $args['button_label'] ),
			esc_html( $args['link_intro'] ),
			esc_html( $args['footer'] )
		);
	}

	public function maybe_handle_stripe_webhook() {
		if ( empty( $_GET['ajcore_stripe_webhook'] ) ) {
			return;
		}

		$admin = new AJForms_Admin();
		$admin->handle_stripe_webhook_request();
		exit;
	}

	private function is_portal_user_account( $user = null ) {
		if ( null === $user ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			$user = wp_get_current_user();
		}

		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return false;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$roles = array_map( 'sanitize_key', (array) $user->roles );
		$auth_settings = get_option( 'ajcore_auth_settings', array() );
		$customer_role = is_array( $auth_settings ) && ! empty( $auth_settings['customer_role'] ) ? sanitize_key( (string) $auth_settings['customer_role'] ) : 'aj_portal_user';

		if ( in_array( 'aj_portal_user', $roles, true ) || user_can( $user, 'ajcore_customer_portal_access' ) || ( $customer_role && in_array( $customer_role, $roles, true ) ) ) {
			return true;
		}

		return false;
	}

	private function is_frontend_portal_user() {
		return $this->is_portal_user_account();
	}

	private function get_customer_portal_page_id() {
		$page_id = absint( get_option( 'ajcore_customer_portal_page_id', 0 ) );
		if ( $page_id && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id;
		}

		$page = get_page_by_path( 'client-portal' );
		if ( $page && 'trash' !== get_post_status( $page ) ) {
			update_option( 'ajcore_customer_portal_page_id', (int) $page->ID, false );
			return (int) $page->ID;
		}

		$page = get_page_by_path( 'file-library' );
		if ( $page && 'trash' !== get_post_status( $page ) ) {
			update_option( 'ajcore_customer_portal_page_id', (int) $page->ID, false );
			return (int) $page->ID;
		}

		return 0;
	}

	private function get_customer_portal_url() {
		$page_id = $this->get_customer_portal_page_id();
		if ( $page_id ) {
			return get_permalink( $page_id );
		}

		return home_url( '/' );
	}

	private function normalize_embedded_checkout_url( $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			$url = home_url( '/' );
		}

		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if (
			isset( $parts['scheme'] )
			&& 'http' === strtolower( (string) $parts['scheme'] )
			&& '' !== $host
			&& ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
		) {
			$url = preg_replace( '/^http:/i', 'https:', $url );
		}

		return esc_url_raw( $url );
	}

	public function maybe_create_customer_portal_page() {
		if ( get_option( 'ajcore_customer_portal_page_created', '' ) ) {
			return;
		}

		$page_id = $this->get_customer_portal_page_id();
		if ( ! $page_id && current_user_can( 'manage_options' ) ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Client Portal', 'ajforms' ),
					'post_name'    => 'client-portal',
					'post_content' => '[aj_customer_portal]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				),
				true
			);

			if ( ! is_wp_error( $page_id ) && $page_id ) {
				update_option( 'ajcore_customer_portal_page_id', (int) $page_id, false );
			}
		}

		update_option( 'ajcore_customer_portal_page_created', '1', false );
	}

	public function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( $this->is_portal_user_account( $user ) ) {
			return $this->get_customer_portal_url();
		}

		return $redirect_to;
	}

	public function redirect_frontend_portal_users_from_admin() {
		if ( wp_doing_ajax() || ! $this->is_frontend_portal_user() ) {
			return;
		}

		$portal_url = $this->get_customer_portal_url();
		$current_url = set_url_scheme( 'http://' . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) );

		if ( $portal_url && untrailingslashit( $current_url ) !== untrailingslashit( $portal_url ) ) {
			wp_safe_redirect( $portal_url );
			exit;
		}
	}

	public function filter_show_admin_bar( $show ) {
		if ( $this->is_frontend_portal_user() ) {
			return false;
		}

		return $show;
	}

	public function maybe_redirect_guest_customer_portal() {
		if ( is_user_logged_in() || ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$portal_page_id = absint( get_option( 'ajcore_customer_portal_page_id', 0 ) );
		$is_portal_page = $portal_page_id && (int) $post->ID === $portal_page_id;
		$has_shortcode  = has_shortcode( (string) $post->post_content, 'aj_customer_portal' );
		if ( ! $is_portal_page && ! $has_shortcode ) {
			return;
		}

		$this->log_guest_portal_redirect();

		wp_safe_redirect( wp_login_url( get_permalink( $post ) ) );
		exit;
	}

	private function log_guest_portal_redirect() {
		$this->log_portal_event(
			'auth_denied',
			array(
				'severity' => 'warning',
				'source'   => 'customer_portal',
				'details'  => array(
					'reason'      => 'not_logged_in',
					'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				),
			)
		);
	}

	public function add_customer_portal_nav_item( $items, $args ) {
		if ( ! $this->is_frontend_portal_user() ) {
			return $items;
		}

		$portal_url = $this->get_customer_portal_url();
		if ( ! $portal_url ) {
			return $items;
		}

		$items .= '<li class="menu-item ajcore-customer-portal-menu-item"><a href="' . esc_url( $portal_url ) . '">' . esc_html__( 'Client Portal', 'ajforms' ) . '</a></li>';

		return $items;
	}

	private function get_customer_portal_menu_items() {
		$items = get_option( 'ajcore_customer_portal_menu_items', array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$default_items = array(
			array(
				'id'      => 'overview',
				'label'   => __( 'Overview', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'services',
				'label'   => __( 'My Services', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'tasks',
				'label'   => __( 'Tasks', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'billing',
				'label'   => __( 'Billing', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'file-library',
				'label'   => __( 'File Library', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'profile',
				'label'   => __( 'Profile', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'service-requests',
				'label'   => __( 'Service Requests', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'reservations',
				'label'   => ( function() {
					$s = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
					return ! empty( $s['reservation_menu_label'] ) ? $s['reservation_menu_label'] : __( 'Conference Room', 'ajforms' );
				} )(),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => ( function() {
					$s = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
					return ! empty( $s['zoho_reservations_enabled'] );
				} )(),
			),
		);

		$built_in_ids    = wp_list_pluck( $default_items, 'id' );
		$built_in_labels = array_map( 'strtolower', wp_list_pluck( $default_items, 'label' ) );

		$normalized = array();
		foreach ( array_merge( $default_items, $items ) as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}

			$id    = sanitize_key( $item['id'] );
			$type  = ! empty( $item['type'] ) && 'custom' === $item['type'] ? 'custom' : 'built_in';
			$label = ! empty( $item['label'] ) ? sanitize_text_field( $item['label'] ) : $id;

			if ( 'custom' === $type && ( in_array( $id, $built_in_ids, true ) || in_array( strtolower( $label ), $built_in_labels, true ) ) ) {
				continue;
			}

			$normalized[ $id ] = array(
				'id'      => $id,
				'label'   => $label,
				'type'    => $type,
				'url'     => ! empty( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
				'enabled' => ! isset( $item['enabled'] ) || (bool) $item['enabled'],
			);
		}

		return array_values( $normalized );
	}

	private function render_customer_portal_file_library_tab() {
		$files  = $this->get_current_user_portal_files();
		$notice = isset( $_GET['portal_notice'] ) ? sanitize_key( wp_unslash( $_GET['portal_notice'] ) ) : '';

		$notice_message = '';
		$notice_class   = '';
		if ( 'file-uploaded' === $notice ) {
			$notice_message = __( 'Your document was uploaded and added to your File Library.', 'ajforms' );
			$notice_class   = 'is-success';
		} elseif ( 'file-upload-error' === $notice ) {
			$notice_message = __( 'Unable to upload the file. Please use a PDF, Word document, or image file.', 'ajforms' );
			$notice_class   = 'is-error';
		} elseif ( 'file-upload-invalid' === $notice ) {
			$notice_message = __( 'The upload request was invalid. Please try again.', 'ajforms' );
			$notice_class   = 'is-error';
		}

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'File Library', 'ajforms' ); ?></h2>

			<?php if ( $notice_message ) : ?>
				<div class="aj-portal-notice <?php echo esc_attr( $notice_class ); ?>"><?php echo esc_html( $notice_message ); ?></div>
			<?php endif; ?>

			<details class="aj-portal-upload-drawer">
				<summary class="aj-portal-upload-toggle"><?php esc_html_e( 'Upload File', 'ajforms' ); ?></summary>
				<div class="aj-portal-upload-card">
					<div>
						<h3><?php esc_html_e( 'Upload a Document', 'ajforms' ); ?></h3>
						<p><?php esc_html_e( 'Share PDFs, Word documents, and images with our team. Uploaded files will stay in your File Library.', 'ajforms' ); ?></p>
					</div>
					<form method="post" enctype="multipart/form-data" class="aj-portal-upload-form">
						<?php wp_nonce_field( 'ajcore_portal_file_upload', 'ajcore_portal_file_upload_nonce' ); ?>
						<input type="hidden" name="ajcore_portal_file_upload" value="1">
						<input type="text" name="portal_file_title" placeholder="<?php echo esc_attr__( 'Optional file title', 'ajforms' ); ?>">
						<input type="file" name="portal_file_upload" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif" required>
						<button type="submit" class="button"><?php esc_html_e( 'Upload File', 'ajforms' ); ?></button>
					</form>
				</div>
			</details>

			<?php if ( empty( $files ) ) : ?>
				<div class="aj-portal-empty-state">
					<strong><?php esc_html_e( 'No files yet', 'ajforms' ); ?></strong>
					<p><?php esc_html_e( 'Documents shared with your portal account and documents you upload will appear here.', 'ajforms' ); ?></p>
				</div>
			<?php else : ?>
				<div class="aj-customer-file-list" role="list">
					<?php foreach ( $files as $file ) : ?>
						<?php
						$download_url = wp_nonce_url(
							add_query_arg(
								array(
									'aj_portal_download' => (int) $file->id,
								),
								home_url( '/' )
							),
							'aj_portal_download_' . (int) $file->id
						);
						$file_category = '' !== (string) $file->category ? (string) $file->category : __( 'File', 'ajforms' );
						$file_date     = ! empty( $file->created_at ) ? $this->format_portal_date( $file->created_at ) : '';
						?>
						<div class="aj-customer-file-row" role="listitem">
							<div class="aj-customer-file-main">
								<div class="aj-customer-file-title"><?php echo esc_html( $file->title ); ?></div>
								<div class="aj-customer-file-meta">
									<span><?php echo esc_html( $file_category ); ?></span>
									<?php if ( $file_date ) : ?><span><?php echo esc_html( $file_date ); ?></span><?php endif; ?>
								</div>
								<?php if ( '' !== (string) $file->description ) : ?>
									<p><?php echo esc_html( $file->description ); ?></p>
								<?php endif; ?>
							</div>
							<div class="aj-customer-file-actions">
								<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'ajforms' ); ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_customer_portal_profile_tab() {
		$customer = $this->get_current_user_portal_customer();

		if ( ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Profile', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$business_name    = $this->get_portal_customer_meta_value( $customer, array( 'business_name', 'business', 'company', 'company_name', 'description' ) );
		$business_address = $this->get_customer_business_address( $customer );
		$display_name     = $customer->name ? $customer->name : $customer->email;

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Profile', 'ajforms' ); ?></h2>

			<div class="aj-portal-profile-block">
				<div class="aj-portal-profile-main"><?php echo esc_html( $display_name ); ?></div>

				<div class="aj-portal-profile-details">
					<?php if ( $business_name ) : ?>
						<div><?php echo esc_html( $business_name ); ?></div>
					<?php endif; ?>
					<?php if ( $customer->email ) : ?>
						<div><a href="<?php echo esc_url( 'mailto:' . $customer->email ); ?>"><?php echo esc_html( $customer->email ); ?></a></div>
					<?php endif; ?>
					<?php if ( $customer->phone ) : ?>
						<div><?php echo esc_html( $this->format_us_phone_for_display( $customer->phone ) ); ?></div>
					<?php endif; ?>
					<?php if ( $business_address ) : ?>
						<div><?php echo esc_html( $business_address ); ?></div>
					<?php endif; ?>
				</div>

				<div class="aj-portal-profile-actions">
					<?php if ( ! $this->is_impersonating_client() ) : ?>
						<a class="button" href="<?php echo esc_url( wp_lostpassword_url( $this->get_customer_portal_url() ) ); ?>"><?php esc_html_e( 'Change Password', 'ajforms' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Logout', 'ajforms' ); ?></a>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private function get_client_service_request_status_label( $status ) {
		$labels = array(
			'draft'                 => __( 'Pending', 'ajforms' ),
			'pending_payment'       => __( 'Pending Payment', 'ajforms' ),
			'awaiting_payment'      => __( 'Awaiting Payment', 'ajforms' ),
			'paid'                  => __( 'Paid – Processing', 'ajforms' ),
			'updating_sosn'         => __( 'Updating SOS/NC', 'ajforms' ),
			'signing_cmra'          => __( 'Awaiting CMRA Signature', 'ajforms' ),
			'active'                => __( 'Active', 'ajforms' ),
			'admin_review_required' => __( 'Under Review', 'ajforms' ),
			'completed'             => __( 'Completed', 'ajforms' ),
			'cancelled'             => __( 'Cancelled', 'ajforms' ),
			'failed'                => __( 'Failed', 'ajforms' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
	}

	private function render_customer_portal_service_requests_tab() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();

		if ( '' === $stripe_customer_id ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Service Requests', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked yet.', 'ajforms' ) . '</p></section>';
		}

		$data     = $this->api_get_client_service_requests();
		$requests = $data['service_requests'];

		$new_sr_nonce = wp_create_nonce( 'ajcore_submit_portal_service_request' );

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Service Requests', 'ajforms' ); ?></h2>

			<div id="aj-new-sr-wrap" style="margin-bottom:24px;">
				<button id="aj-new-sr-toggle" class="button button-primary" onclick="document.getElementById('aj-new-sr-form-wrap').style.display=document.getElementById('aj-new-sr-form-wrap').style.display==='none'?'block':'none';return false;"><?php esc_html_e( '+ New Service Request', 'ajforms' ); ?></button>
				<div id="aj-new-sr-form-wrap" style="display:none;margin-top:12px;padding:16px;border:1px solid #ddd;background:#f9f9f9;border-radius:6px;max-width:600px;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Submit a New Service Request', 'ajforms' ); ?></h3>
					<form id="aj-new-sr-form">
						<p>
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Subject', 'ajforms' ); ?></label>
							<input type="text" id="aj-sr-subject" name="subject" style="width:100%;padding:8px;" placeholder="<?php esc_attr_e( 'e.g. Update registered agent address', 'ajforms' ); ?>" required>
						</p>
						<p>
							<label style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Details', 'ajforms' ); ?></label>
							<textarea id="aj-sr-details" name="details" rows="4" style="width:100%;padding:8px;" placeholder="<?php esc_attr_e( 'Please describe what you need...', 'ajforms' ); ?>"></textarea>
						</p>
						<p id="aj-new-sr-msg" style="display:none;padding:8px;border-radius:4px;"></p>
						<button type="submit" id="aj-new-sr-submit" class="button button-primary"><?php esc_html_e( 'Submit Request', 'ajforms' ); ?></button>
					</form>
					<script>
					(function(){
						document.getElementById('aj-new-sr-form').addEventListener('submit', function(e){
							e.preventDefault();
							var btn = document.getElementById('aj-new-sr-submit');
							var msg = document.getElementById('aj-new-sr-msg');
							btn.disabled = true;
							btn.textContent = '<?php echo esc_js( __( 'Submitting…', 'ajforms' ) ); ?>';
							var fd = new FormData();
							fd.append('action', 'ajcore_submit_portal_service_request');
							fd.append('nonce', '<?php echo esc_js( $new_sr_nonce ); ?>');
							fd.append('subject', document.getElementById('aj-sr-subject').value);
							fd.append('details', document.getElementById('aj-sr-details').value);
							fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST',body:fd,credentials:'same-origin'})
								.then(function(r){return r.json();})
								.then(function(data){
									msg.style.display = 'block';
									if(data.success){
										msg.style.background = '#dcfce7';
										msg.style.color = '#166534';
										msg.textContent = data.data.message || '<?php echo esc_js( __( 'Request submitted!', 'ajforms' ) ); ?>';
										document.getElementById('aj-sr-subject').value = '';
										document.getElementById('aj-sr-details').value = '';
										setTimeout(function(){ location.reload(); }, 1500);
									} else {
										msg.style.background = '#fee2e2';
										msg.style.color = '#991b1b';
										msg.textContent = (data.data && data.data.message) ? data.data.message : '<?php echo esc_js( __( 'Submission failed. Please try again.', 'ajforms' ) ); ?>';
										btn.disabled = false;
										btn.textContent = '<?php echo esc_js( __( 'Submit Request', 'ajforms' ) ); ?>';
									}
								})
								.catch(function(){
									msg.style.display='block';msg.style.background='#fee2e2';msg.style.color='#991b1b';
									msg.textContent='<?php echo esc_js( __( 'Network error. Please try again.', 'ajforms' ) ); ?>';
									btn.disabled=false;btn.textContent='<?php echo esc_js( __( 'Submit Request', 'ajforms' ) ); ?>';
								});
						});
					})();
					</script>
				</div>
			</div>

			<style>
				.aj-client-sr-list{display:grid;gap:14px;margin-top:18px}.aj-client-sr-card{border:1px solid #e4ebf3;border-radius:18px;background:#fff;padding:16px;box-shadow:0 10px 26px rgba(15,23,42,.05)}.aj-client-sr-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.aj-client-sr-title{font-weight:800;color:#0f172a;font-size:16px}.aj-client-sr-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;color:#64748b}.aj-client-sr-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800;background:#dbeafe;color:#1e40af}.aj-client-sr-badge.is-good{background:#dcfce7;color:#166534}.aj-client-sr-badge.is-warn{background:#fef9c3;color:#854d0e}.aj-client-sr-badge.is-bad{background:#fee2e2;color:#991b1b}.aj-client-sr-note{margin-top:12px;padding:12px;border-radius:14px;background:#f8fafc;color:#334155}
			</style>
			<?php if ( empty( $requests ) ) : ?>
				<div class="aj-portal-empty-state">
					<strong><?php esc_html_e( 'No service requests yet', 'ajforms' ); ?></strong>
					<p><?php esc_html_e( 'New service requests and setup progress will appear here.', 'ajforms' ); ?></p>
				</div>
			<?php else : ?>
				<div class="aj-client-sr-list">
					<?php foreach ( $requests as $request ) : ?>
						<?php
						$pay_status = sanitize_key( (string) $request->status );
						$svc_status = isset( $request->service_status ) && '' !== $request->service_status ? sanitize_key( (string) $request->service_status ) : 'new';
						$pay_label  = $this->get_client_service_request_status_label( $pay_status );
						$svc_labels = array(
							'new'                    => __( 'New', 'ajforms' ),
							'under_review'           => __( 'Under Review', 'ajforms' ),
							'pending_customer'       => __( 'Pending Customer', 'ajforms' ),
							'pending_agent'          => __( 'Pending Agent', 'ajforms' ),
							'meeting_scheduled'      => __( 'Meeting Scheduled', 'ajforms' ),
							'sosnc_filing'           => __( 'Filing with SOS/NC', 'ajforms' ),
							'llc_documents_emailed'  => __( 'Documents Emailed', 'ajforms' ),
							'signing_cmra'           => __( 'Awaiting CMRA Signature', 'ajforms' ),
							'id_proof_needed'        => __( 'ID Proof Needed', 'ajforms' ),
							'address_proof_needed'   => __( 'Address Proof Needed', 'ajforms' ),
							'vo_setup_required'      => __( 'Virtual Office Setup Required', 'ajforms' ),
							'sosnc_client'           => __( 'Waiting on Customer SOS/NC Update', 'ajforms' ),
							'updating_sosn'          => __( 'SOS/NC Update in Progress', 'ajforms' ),
							'included_with_llc_setup' => __( 'Included with LLC Setup', 'ajforms' ),
							'active'                 => __( 'Active', 'ajforms' ),
							'completed'              => __( 'Completed', 'ajforms' ),
							'cancelled'              => __( 'Cancelled', 'ajforms' ),
						);
						$svc_label = isset( $svc_labels[ $svc_status ] ) ? $svc_labels[ $svc_status ] : ucwords( str_replace( '_', ' ', $svc_status ) );
						$service_name = ! empty( $request->service_name ) ? sanitize_text_field( (string) $request->service_name ) : '';
						if ( '' === $service_name && ! empty( $request->stripe_price_id ) ) {
							$sr_product = $this->get_portal_product_by_price_id( sanitize_text_field( (string) $request->stripe_price_id ) );
							$service_name = $sr_product && ! empty( $sr_product->name ) ? sanitize_text_field( (string) $sr_product->name ) : '';
						}
						if ( '' === $service_name ) {
							$service_name = __( 'Service Request', 'ajforms' );
						}
						$client_notes = ! empty( $request->client_notes ) ? sanitize_text_field( (string) $request->client_notes ) : '';
						$created = ! empty( $request->created_at ) ? $this->format_portal_date( $request->created_at ) : '-';
						$pay_class = 'paid' === $pay_status || 'completed' === $pay_status || 'active' === $pay_status ? 'is-good' : ( in_array( $pay_status, array( 'cancelled', 'failed' ), true ) ? 'is-bad' : 'is-warn' );
						$svc_class = in_array( $svc_status, array( 'active', 'completed' ), true ) ? 'is-good' : ( 'cancelled' === $svc_status ? 'is-bad' : 'is-warn' );
						?>
						<div class="aj-client-sr-card">
							<div class="aj-client-sr-head">
								<div>
									<div class="aj-client-sr-title"><?php echo esc_html( $service_name ); ?></div>
									<div class="aj-client-sr-meta"><span><?php echo esc_html( $created ); ?></span><span><?php echo esc_html( strtoupper( (string) $request->currency ) . ' ' . number_format_i18n( (float) $request->amount, 2 ) ); ?></span></div>
								</div>
								<div class="aj-client-sr-meta"><span class="aj-client-sr-badge <?php echo esc_attr( $pay_class ); ?>"><?php echo esc_html( $pay_label ); ?></span><span class="aj-client-sr-badge <?php echo esc_attr( $svc_class ); ?>"><?php echo esc_html( $svc_label ); ?></span></div>
							</div>
							<?php if ( '' !== $client_notes ) : ?><div class="aj-client-sr-note"><?php echo esc_html( $client_notes ); ?></div><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function decode_portal_json( $value ) {
		$decoded = ! empty( $value ) ? json_decode( (string) $value, true ) : array();

		return is_array( $decoded ) ? $decoded : array();
	}

	private function get_portal_nested_value( $data, $path ) {
		$value = $data;
		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return '';
			}
			$value = $value[ $part ];
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	private function get_portal_customer_meta_value( $customer, $keys ) {
		$raw      = $this->decode_portal_json( isset( $customer->raw_data ) ? $customer->raw_data : '' );
		$metadata = $this->decode_portal_json( isset( $customer->metadata ) ? $customer->metadata : '' );

		foreach ( (array) $keys as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) && '' !== (string) $metadata[ $key ] ) {
				return sanitize_text_field( (string) $metadata[ $key ] );
			}
			$value = $this->get_portal_nested_value( $raw, 'metadata.' . $key );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
			$value = $this->get_portal_nested_value( $raw, $key );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}

	private function format_portal_money( $amount, $currency ) {
		$currency = strtolower( sanitize_key( (string) $currency ) );
		$amount   = number_format_i18n( (float) $amount, 2 );

		if ( 'usd' === $currency ) {
			return '$' . $amount;
		}

		return strtoupper( sanitize_text_field( (string) $currency ) ) . ' ' . $amount;
	}


	private function get_portal_ledger_refunded_amount( $entry ) {
		$raw = $this->decode_portal_json( isset( $entry->raw_data ) ? $entry->raw_data : '' );
		if ( isset( $raw['amount_refunded'], $raw['currency'] ) ) {
			return $this->stripe_amount_to_decimal( $raw['amount_refunded'], $raw['currency'] );
		}

		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		if ( isset( $metadata['amount_refunded_total'] ) ) {
			return (float) $metadata['amount_refunded_total'];
		}

		return 0.0;
	}

	private function get_portal_ledger_balance_effect( $entry ) {
		$amount = isset( $entry->amount ) ? (float) $entry->amount : 0.0;
		if ( 0.0 === $amount ) {
			return 0.0;
		}

		$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';

		if ( in_array( $status, array( 'cancelled', 'canceled', 'failed', 'void', 'voided', 'draft', 'admin_review_required' ), true ) ) {
			return 0.0;
		}

		if ( 'refund' === $source_type ) {
			return 0.0;
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return abs( $amount );
		}

		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			if ( 'refunded' === $status ) {
				return 0.0;
			}

			if ( in_array( $status, array( 'partially_refunded', 'partial_refund' ), true ) ) {
				$net_paid = max( 0.0, abs( $amount ) - abs( $this->get_portal_ledger_refunded_amount( $entry ) ) );
				return $net_paid > 0 ? -1 * $net_paid : 0.0;
			}

			return in_array( $status, array( 'succeeded', 'paid' ), true ) ? -1 * abs( $amount ) : 0.0;
		}

		if ( 'checkout_session' === $source_type ) {
			return 0.0;
		}

		if ( in_array( $source_type, array( 'manual_charge', 'invoice' ), true ) && in_array( $status, $this->get_portal_open_ledger_statuses(), true ) ) {
			return abs( $amount );
		}

		if ( in_array( $status, $this->get_portal_open_ledger_statuses(), true ) ) {
			return abs( $amount );
		}

		return 0.0;
	}

	private function get_portal_ledger_running_balances( $ledger ) {
		$running = 0.0;
		$balances = array();
		$entries = (array) $ledger;

		foreach ( $entries as $entry ) {
			$entry_id = isset( $entry->id ) ? absint( $entry->id ) : 0;
			$running += $this->get_portal_ledger_balance_effect( $entry );
			if ( $entry_id ) {
				$balances[ $entry_id ] = $running;
			}
		}

		return array(
			'balances' => $balances,
			'total'    => $running,
		);
	}

	private function format_portal_balance_amount( $amount, $currency ) {
		$amount = (float) $amount;
		$label = $this->format_portal_money( abs( $amount ), $currency );

		if ( $amount < -0.00001 ) {
			return sprintf( __( 'Credit %s', 'ajforms' ), $label );
		}

		return $label;
	}


	private function get_portal_ledger_debit_credit( $entry ) {
		$effect = $this->get_portal_ledger_balance_effect( $entry );
		$currency = ! empty( $entry->currency ) ? sanitize_key( (string) $entry->currency ) : 'usd';

		return array(
			'debit'    => $effect > 0.00001 ? $this->format_portal_money( abs( $effect ), $currency ) : '',
			'credit'   => $effect < -0.00001 ? $this->format_portal_money( abs( $effect ), $currency ) : '',
			'currency' => $currency,
		);
	}


	private function get_portal_ledger_transaction_id( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			foreach ( array( 'payment_intent_id', 'charge_id', 'source_object_id' ) as $field ) {
				if ( ! empty( $entry->{$field} ) ) {
					return sanitize_text_field( (string) $entry->{$field} );
				}
			}
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			$billing_type = $this->get_ledger_metadata_value( $entry, 'billing_type' );
			$recurring_interval = $this->get_ledger_metadata_value( $entry, 'recurring_interval' );
			$product_interval = $this->get_portal_product_recurring_interval_from_ids(
				$this->get_ledger_metadata_value( $entry, 'price_id' ),
				$this->get_ledger_metadata_value( $entry, 'product_id' )
			);
			$is_recurring = null !== $product_interval ? '' !== $product_interval : ( 'recurring' === sanitize_key( $billing_type ) || '' !== $recurring_interval );
			$keys = $is_recurring
				? array( 'subscription_id', 'price_id', 'product_id' )
				: array( 'checkout_session_id', 'payment_intent_id', 'price_id', 'product_id', 'invoice_id' );
			foreach ( $keys as $metadata_key ) {
				$value = $this->get_ledger_metadata_value( $entry, $metadata_key );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		foreach ( array( 'charge_id', 'payment_intent_id', 'invoice_id', 'source_object_id' ) as $field ) {
			if ( ! empty( $entry->{$field} ) ) {
				return sanitize_text_field( (string) $entry->{$field} );
			}
		}

		return '';
	}

	private function get_portal_ledger_display_description( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		$status      = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		$description = isset( $entry->description ) ? sanitize_text_field( (string) $entry->description ) : '';

		if ( 'charge' === $source_type || 'payment' === $source_type ) {
			if ( 'refunded' === $status ) {
				return __( 'Payment — Refunded', 'ajforms' );
			}
			if ( in_array( $status, array( 'partially_refunded', 'partial_refund' ), true ) ) {
				return __( 'Payment — Partially Refunded', 'ajforms' );
			}

			if ( '' !== $description && 0 !== strpos( $description, 'Charge ch_' ) ) {
				return $description;
			}

			return __( 'Payment', 'ajforms' );
		}

		if ( 'refund' === $source_type ) {
			return __( 'Refund', 'ajforms' );
		}

		if ( 'manual_charge' === $source_type && '' !== $description ) {
			return $description;
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) && '' !== $description ) {
			return $this->clean_stripe_line_service_name( $description );
		}

		if ( 'invoice' === $source_type ) {
			$invoice_number = $this->get_ledger_metadata_value( $entry, 'invoice_number' );
			if ( $invoice_number ) {
				return sprintf( __( 'Invoice %s', 'ajforms' ), $invoice_number );
			}
			if ( '' !== $description ) {
				return $description;
			}
			return __( 'Invoice', 'ajforms' );
		}

		if ( 'checkout_session' === $source_type ) {
			if ( false !== strpos( $description, 'ajcore_products_cart' ) ) {
				return __( 'Website Checkout Payment', 'ajforms' );
			}
			if ( false !== strpos( $description, 'ajcore_portal_add_service' ) ) {
				return __( 'Service Checkout Payment', 'ajforms' );
			}
			if ( false !== strpos( $description, 'ajcore_portal_balance_payment' ) ) {
				return __( 'Balance Payment', 'ajforms' );
			}
		}

		return '' !== $description ? $description : __( 'Billing Item', 'ajforms' );
	}

	private function format_portal_date( $date ) {
		if ( empty( $date ) ) {
			return '-';
		}

		$timestamp = is_numeric( $date ) ? (int) $date : strtotime( $date . ' UTC' );

		return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '-';
	}

	private function get_ledger_metadata_value( $entry, $key ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );

		return isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ? sanitize_text_field( (string) $metadata[ $key ] ) : '';
	}

	private function clean_stripe_line_service_name( $description ) {
		$description = sanitize_text_field( (string) $description );
		$description = preg_replace( '/^\s*\d+\s*(?:x|\x{00D7})\s*/iu', '', $description );
		$description = preg_replace( '/\s*\(at\s+.*?\)\s*$/i', '', $description );

		return trim( $description );
	}

	private function get_subscription_ledger_entry( $subscription, $ledger ) {
		$subscription_id = isset( $subscription->stripe_subscription_id ) ? sanitize_text_field( (string) $subscription->stripe_subscription_id ) : '';

		foreach ( $ledger as $entry ) {
			$metadata_subscription_id = $this->get_ledger_metadata_value( $entry, 'subscription_id' );
			if ( $subscription_id && $metadata_subscription_id && $subscription_id === $metadata_subscription_id ) {
				return $entry;
			}
		}

		return null;
	}

	private function get_synced_product_name_for_subscription_item( $item ) {
		$pdb = $this->get_pdb();

		$price_id   = '';
		$product_id = '';
		$price      = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();

		if ( ! empty( $item['price_id'] ) ) {
			$price_id = sanitize_text_field( (string) $item['price_id'] );
		}
		if ( '' === $price_id && ! empty( $price['id'] ) ) {
			$price_id = sanitize_text_field( (string) $price['id'] );
		}
		if ( ! empty( $item['product_id'] ) ) {
			$product_id = sanitize_text_field( (string) $item['product_id'] );
		}
		if ( '' === $product_id && ! empty( $price['product']['id'] ) ) {
			$product_id = sanitize_text_field( (string) $price['product']['id'] );
		}
		if ( '' === $product_id && ! empty( $price['product'] ) && is_string( $price['product'] ) ) {
			$product_id = sanitize_text_field( (string) $price['product'] );
		}

		if ( '' !== $price_id ) {
			$name = $pdb->get_var(
				$pdb->prepare(
					"SELECT name FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s LIMIT 1",
					$price_id
				)
			);
			if ( $name ) {
				return sanitize_text_field( (string) $name );
			}
		}

		if ( '' !== $product_id ) {
			$name = $pdb->get_var(
				$pdb->prepare(
					"SELECT name FROM {$this->get_portal_stripe_products_table()} WHERE stripe_product_id = %s ORDER BY active DESC, id DESC LIMIT 1",
					$product_id
				)
			);
			if ( $name ) {
				return sanitize_text_field( (string) $name );
			}
		}

		return '';
	}

	private function get_subscription_service_name( $subscription, $ledger_entry = null ) {
		$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
		foreach ( $items as $item ) {
			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			if ( ! empty( $price['product'] ) && is_array( $price['product'] ) && ! empty( $price['product']['name'] ) ) {
				return sanitize_text_field( (string) $price['product']['name'] );
			}
			$service_name = $this->get_synced_product_name_for_subscription_item( $item );
			if ( $service_name ) {
				return $service_name;
			}
			if ( ! empty( $price['nickname'] ) ) {
				return sanitize_text_field( (string) $price['nickname'] );
			}
			if ( ! empty( $item['description'] ) ) {
				$service_name = $this->clean_stripe_line_service_name( $item['description'] );
				if ( $service_name ) {
					return $service_name;
				}
			}
		}

		if ( $ledger_entry ) {
			$description = $this->get_ledger_metadata_value( $ledger_entry, 'description' );
			if ( ! $description && ! empty( $ledger_entry->description ) ) {
				$description = $ledger_entry->description;
			}

			$service_name = $this->clean_stripe_line_service_name( $description );
			if ( $service_name ) {
				return $service_name;
			}
		}

		return __( 'Service', 'ajforms' );
	}

	private function is_current_portal_subscription( $subscription ) {
		return isset( $subscription->status ) && in_array( (string) $subscription->status, array( 'active', 'trialing' ), true );
	}

	private function get_subscription_amount_label( $subscription ) {
		$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
		foreach ( $items as $item ) {
			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			if ( isset( $price['unit_amount'] ) ) {
				$currency = ! empty( $price['currency'] ) ? $price['currency'] : 'usd';
				$amount   = in_array( strtolower( $currency ), array( 'jpy', 'krw', 'vnd' ), true ) ? (float) $price['unit_amount'] : (float) $price['unit_amount'] / 100;
				$interval = ! empty( $price['recurring']['interval'] ) ? '/' . sanitize_text_field( (string) $price['recurring']['interval'] ) : '';

				return $this->format_portal_money( $amount, $currency ) . $interval;
			}
		}

		return '-';
	}

	private function get_latest_invoice_url( $ledger ) {
		foreach ( $ledger as $entry ) {
			$invoice_pdf = $this->get_ledger_metadata_value( $entry, 'invoice_pdf' );
			if ( $invoice_pdf ) {
				return esc_url_raw( $invoice_pdf );
			}
			$hosted_invoice_url = $this->get_ledger_metadata_value( $entry, 'hosted_invoice_url' );
			if ( $hosted_invoice_url ) {
				return esc_url_raw( $hosted_invoice_url );
			}
		}

		return '';
	}

	private function format_portal_address( $address ) {
		$address = is_array( $address ) ? $address : array();
		$parts   = array();

		foreach ( array( 'line1', 'line2', 'city', 'state', 'postal_code', 'country' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$parts[] = sanitize_text_field( (string) $address[ $key ] );
			}
		}

		return implode( ', ', $parts );
	}

	private function get_customer_business_address( $customer ) {
		$raw = $this->decode_portal_json( isset( $customer->raw_data ) ? $customer->raw_data : '' );
		$address = ! empty( $raw['address'] ) && is_array( $raw['address'] ) ? $raw['address'] : $this->decode_portal_json( isset( $customer->address ) ? $customer->address : '' );

		return $this->format_portal_address( $address );
	}

	private function get_subscription_service_period( $subscription, $ledger_entry = null ) {
		if ( $ledger_entry ) {
			$service_period = $this->get_ledger_metadata_value( $ledger_entry, 'service_period' );
			if ( $service_period ) {
				return $service_period;
			}
		}

		$period = $this->get_subscription_period_context( $subscription );
		$start  = ! empty( $period['start'] ) ? $period['start'] : '';
		$end    = ! empty( $period['end'] ) ? $period['end'] : '';

		if ( $start && $end ) {
			return $this->format_portal_date( $start ) . ' - ' . $this->format_portal_date( $end );
		}

		return $end ? __( 'Through ', 'ajforms' ) . $this->format_portal_date( $end ) : '-';
	}

	private function get_subscription_next_billing_date( $subscription, $ledger_entry = null ) {
		if ( $ledger_entry ) {
			$service_period_end = $this->get_ledger_metadata_value( $ledger_entry, 'service_period_end' );
			if ( $service_period_end ) {
				return $this->format_portal_date( $service_period_end );
			}
		}

		$period = $this->get_subscription_period_context( $subscription );
		return ! empty( $period['end'] ) ? $this->format_portal_date( $period['end'] ) : '-';
	}

	private function normalize_portal_stripe_timestamp_for_display( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', absint( $value ) );
		}

		$timestamp = strtotime( (string) $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : sanitize_text_field( (string) $value );
	}

	private function get_subscription_period_context( $subscription ) {
		$start = '';
		$end   = '';
		$raw   = $this->decode_portal_json( isset( $subscription->raw_data ) ? $subscription->raw_data : '' );

		if ( ! empty( $raw['current_period_start'] ) ) {
			$start = $this->normalize_portal_stripe_timestamp_for_display( $raw['current_period_start'] );
		}
		if ( ! empty( $raw['current_period_end'] ) ) {
			$end = $this->normalize_portal_stripe_timestamp_for_display( $raw['current_period_end'] );
		}

		$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( '' === $start ) {
				foreach ( array( 'current_period_start', 'period_start' ) as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						$start = $this->normalize_portal_stripe_timestamp_for_display( $item[ $key ] );
						break;
					}
				}
			}
			if ( '' === $end ) {
				foreach ( array( 'current_period_end', 'period_end' ) as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						$end = $this->normalize_portal_stripe_timestamp_for_display( $item[ $key ] );
						break;
					}
				}
			}
			if ( ( '' === $start || '' === $end ) && ! empty( $item['period'] ) && is_array( $item['period'] ) ) {
				if ( '' === $start && ! empty( $item['period']['start'] ) ) {
					$start = $this->normalize_portal_stripe_timestamp_for_display( $item['period']['start'] );
				}
				if ( '' === $end && ! empty( $item['period']['end'] ) ) {
					$end = $this->normalize_portal_stripe_timestamp_for_display( $item['period']['end'] );
				}
			}
			if ( $start && $end ) {
				break;
			}
		}

		if ( '' === $end && ! empty( $subscription->current_period_end ) ) {
			$end = $this->normalize_portal_stripe_timestamp_for_display( $subscription->current_period_end );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	private function get_subscription_price_ids( $subscription ) {
		$price_ids = array();
		$items     = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );

		foreach ( $items as $item ) {
			$price_id = '';
			if ( ! empty( $item['price_id'] ) ) {
				$price_id = sanitize_text_field( (string) $item['price_id'] );
			}

			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			if ( '' === $price_id && ! empty( $price['id'] ) ) {
				$price_id = sanitize_text_field( (string) $price['id'] );
			}

			if ( '' !== $price_id ) {
				$price_ids[] = $price_id;
			}
		}

		return array_values( array_unique( $price_ids ) );
	}

	private function get_subscription_product_ids( $subscription ) {
		$product_ids = array();
		$items       = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );

		foreach ( $items as $item ) {
			$product_id = ! empty( $item['product_id'] ) ? sanitize_text_field( (string) $item['product_id'] ) : '';
			$price      = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			$product    = ! empty( $price['product'] ) && is_array( $price['product'] ) ? $price['product'] : array();

			if ( '' === $product_id && ! empty( $product['id'] ) ) {
				$product_id = sanitize_text_field( (string) $product['id'] );
			}
			if ( '' === $product_id && ! empty( $price['product'] ) && is_string( $price['product'] ) ) {
				$product_id = sanitize_text_field( (string) $price['product'] );
			}

			if ( '' !== $product_id ) {
				$product_ids[] = $product_id;
			}
		}

		return array_values( array_unique( $product_ids ) );
	}

	private function get_customer_purchased_price_ids( $subscriptions ) {
		$price_ids = array();
		foreach ( $subscriptions as $subscription ) {
			$price_ids = array_merge( $price_ids, $this->get_subscription_price_ids( $subscription ) );
		}

		return array_values( array_unique( array_filter( $price_ids ) ) );
	}

	private function get_customer_purchased_product_ids( $subscriptions ) {
		$product_ids = array();
		foreach ( $subscriptions as $subscription ) {
			$product_ids = array_merge( $product_ids, $this->get_subscription_product_ids( $subscription ) );
		}

		return array_values( array_unique( array_filter( $product_ids ) ) );
	}

	private function get_portal_product_duplicate_behavior( $product ) {
		$behavior = isset( $product->duplicate_behavior ) ? sanitize_key( (string) $product->duplicate_behavior ) : 'no_duplicates';
		$allowed  = array( 'no_duplicates', 'allow_duplicate', 'custom_request', 'upgrade' );

		return in_array( $behavior, $allowed, true ) ? $behavior : 'no_duplicates';
	}

	private function get_portal_product_upgrade_from_product_id( $product ) {
		$product_id = isset( $product->upgrade_from_product_id ) ? sanitize_text_field( (string) $product->upgrade_from_product_id ) : '';

		return 0 === strpos( $product_id, 'prod_' ) ? $product_id : '';
	}

	private function find_current_portal_subscription_for_product_id( $product_id, $subscriptions ) {
		$product_id = sanitize_text_field( (string) $product_id );
		if ( '' === $product_id ) {
			return null;
		}

		foreach ( (array) $subscriptions as $subscription ) {
			if ( ! $this->is_current_portal_subscription( $subscription ) ) {
				continue;
			}

			if ( in_array( $product_id, $this->get_subscription_product_ids( $subscription ), true ) ) {
				return $subscription;
			}
		}

		return null;
	}

	private function get_portal_subscription_id( $subscription ) {
		foreach ( array( 'stripe_subscription_id', 'subscription_id', 'id' ) as $key ) {
			if ( isset( $subscription->{$key} ) && 0 === strpos( (string) $subscription->{$key}, 'sub_' ) ) {
				return sanitize_text_field( (string) $subscription->{$key} );
			}
			if ( is_array( $subscription ) && ! empty( $subscription[ $key ] ) && 0 === strpos( (string) $subscription[ $key ], 'sub_' ) ) {
				return sanitize_text_field( (string) $subscription[ $key ] );
			}
		}

		return '';
	}

	private function is_portal_product_owned_by_exact_id( $product, $purchased_price_ids, $purchased_product_ids ) {
		$price_id   = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
		$product_id = isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '';

		if ( '' !== $price_id && in_array( $price_id, $purchased_price_ids, true ) ) {
			return true;
		}

		return '' !== $product_id && in_array( $product_id, $purchased_product_ids, true );
	}

	private function get_portal_service_identity_tokens( $value ) {
		$value = strtolower( wp_strip_all_tags( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}

		$stop_words = array(
			'0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
			'a', 'an', 'and', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'per', 'the', 'to', 'with',
			'add', 'addon', 'annual', 'annually', 'basic', 'custom', 'fee', 'fees', 'monthly', 'month', 'months',
			'one', 'package', 'plan', 'price', 'pricing', 'product', 'recurring', 'request', 'service', 'services',
			'setup', 'standard', 'subscription', 'time', 'year', 'yearly', 'years',
		);
		$tokens = array();
		foreach ( preg_split( '/\s+/', $value ) as $token ) {
			$token = sanitize_key( $token );
			if ( '' === $token || in_array( $token, $stop_words, true ) || strlen( $token ) < 3 ) {
				continue;
			}
			$tokens[] = $token;
		}

		return array_values( array_unique( $tokens ) );
	}

	private function portal_service_token_sets_match( $product_tokens, $subscription_tokens ) {
		$product_tokens      = array_values( array_unique( array_filter( (array) $product_tokens ) ) );
		$subscription_tokens = array_values( array_unique( array_filter( (array) $subscription_tokens ) ) );

		if ( empty( $product_tokens ) || empty( $subscription_tokens ) ) {
			return false;
		}

		$shared = array_intersect( $product_tokens, $subscription_tokens );
		$minimum_shared = min( 2, count( $product_tokens ), count( $subscription_tokens ) );
		if ( count( $shared ) < $minimum_shared ) {
			return false;
		}

		$smaller_count = min( count( $product_tokens ), count( $subscription_tokens ) );
		return $smaller_count > 0 && ( count( $shared ) / $smaller_count ) >= 0.75;
	}

	private function is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $subscriptions = array(), $ledger = array() ) {
		$price_id   = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
		$product_id = isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '';

		if ( '' !== $price_id && in_array( $price_id, $purchased_price_ids, true ) ) {
			return true;
		}

		if ( '' !== $product_id && in_array( $product_id, $purchased_product_ids, true ) ) {
			return true;
		}

		if ( empty( $subscriptions ) ) {
			return false;
		}

		$product_name = ! empty( $product->custom_label ) ? $product->custom_label : ( ! empty( $product->name ) ? $product->name : '' );
		$product_tokens = $this->get_portal_service_identity_tokens( $product_name );
		if ( empty( $product_tokens ) ) {
			return false;
		}

		foreach ( $subscriptions as $subscription ) {
			$subscription_tokens = $this->get_portal_service_identity_tokens( $this->get_subscription_service_name( $subscription ) );
			if ( $this->portal_service_token_sets_match( $product_tokens, $subscription_tokens ) ) {
				return true;
			}

			$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
			foreach ( $items as $item ) {
				$candidates = array();
				if ( ! empty( $item['description'] ) ) {
					$candidates[] = $item['description'];
				}
				$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
				if ( ! empty( $price['nickname'] ) ) {
					$candidates[] = $price['nickname'];
				}
				if ( ! empty( $price['product'] ) && is_array( $price['product'] ) && ! empty( $price['product']['name'] ) ) {
					$candidates[] = $price['product']['name'];
				}

				foreach ( $candidates as $candidate ) {
					if ( $this->portal_service_token_sets_match( $product_tokens, $this->get_portal_service_identity_tokens( $candidate ) ) ) {
						return true;
					}
				}
			}
		}

		foreach ( (array) $ledger as $entry ) {
			$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
			if ( ! in_array( $status, array( 'paid', 'succeeded', 'active' ), true ) ) {
				continue;
			}

			$candidates = array();
			if ( ! empty( $entry->description ) ) {
				$candidates[] = $entry->description;
			}

			$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
			foreach ( array( 'description', 'service_name', 'product_name' ) as $metadata_key ) {
				if ( ! empty( $metadata[ $metadata_key ] ) && is_scalar( $metadata[ $metadata_key ] ) ) {
					$candidates[] = (string) $metadata[ $metadata_key ];
				}
			}

			foreach ( $candidates as $candidate ) {
				if ( $this->portal_service_token_sets_match( $product_tokens, $this->get_portal_service_identity_tokens( $candidate ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function get_portal_filtered_service_products( $subscriptions ) {
		$pdb = $this->get_pdb();

		$products = $pdb->get_results(
			"SELECT p.*,
				COALESCE(c.visibility, p.visibility, 'hidden') AS visibility,
				COALESCE(c.custom_label, p.custom_label, '') AS custom_label,
				COALESCE(c.sort_order, p.sort_order, 0) AS sort_order,
				COALESCE(c.description_override, p.description_override, '') AS description_override,
				COALESCE(c.duplicate_behavior, p.duplicate_behavior, 'no_duplicates') AS duplicate_behavior,
				COALESCE(c.upgrade_from_product_id, p.upgrade_from_product_id, '') AS upgrade_from_product_id,
				COALESCE(c.custom_request_title, p.custom_request_title, '') AS custom_request_title,
				COALESCE(c.custom_request_message, p.custom_request_message, '') AS custom_request_message,
				COALESCE(c.custom_request_button_label, p.custom_request_button_label, '') AS custom_request_button_label,
				c.price_settings AS portal_price_settings
			FROM {$this->get_portal_stripe_products_table()} p
			LEFT JOIN {$this->get_portal_product_catalog_table()} c ON c.stripe_product_id = p.stripe_product_id
			WHERE p.active = 1 AND COALESCE(c.visibility, p.visibility, 'hidden') <> 'hidden'
			ORDER BY CASE WHEN COALESCE(c.sort_order, p.sort_order, 0) > 0 THEN 0 ELSE 1 END ASC, sort_order ASC, p.name ASC"
		);

		return array_values(
			array_filter(
				$products,
				function ( $product ) {
					$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';

					return '' !== $price_id;
				}
			)
		);
	}

	private function get_portal_available_service_products( $subscriptions, $ledger = array() ) {
		$purchased_price_ids   = $this->get_customer_purchased_price_ids( $subscriptions );
		$purchased_product_ids = $this->get_customer_purchased_product_ids( $subscriptions );
		$products              = $this->get_portal_filtered_service_products( $subscriptions );
		$stripe_customer_id    = $this->get_current_user_stripe_customer_id();

		return array_values(
			array_filter(
				$products,
				function ( $product ) use ( $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger, $stripe_customer_id ) {
					$behavior = $this->get_portal_product_duplicate_behavior( $product );
					$is_owned = 'upgrade' === $behavior
						? $this->is_portal_product_owned_by_exact_id( $product, $purchased_price_ids, $purchased_product_ids )
						: $this->is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger );
					$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
					$product_id = isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '';
					$open_request = $this->get_open_portal_service_request_for_product( $stripe_customer_id, $price_id, $product_id );
					$upgrade_from_product_id = 'upgrade' === $behavior ? $this->get_portal_product_upgrade_from_product_id( $product ) : '';
					$upgrade_from_subscription = '' !== $upgrade_from_product_id ? $this->find_current_portal_subscription_for_product_id( $upgrade_from_product_id, $subscriptions ) : null;
					$upgrade_from_subscription_id = $upgrade_from_subscription ? $this->get_portal_subscription_id( $upgrade_from_subscription ) : '';

					$product->portal_is_owned                     = $is_owned ? 1 : 0;
					$product->portal_open_request                  = $open_request ? 1 : 0;
					$product->portal_is_upgrade                    = 'upgrade' === $behavior ? 1 : 0;
					$product->portal_upgrade_from_product_id       = $upgrade_from_product_id;
					$product->portal_upgrade_from_subscription_id  = $upgrade_from_subscription_id;
					$product->portal_can_add                       = ( ! $open_request && ( ! $is_owned || 'allow_duplicate' === $behavior ) ) ? 1 : 0;
					if ( 'upgrade' === $behavior ) {
						$product->portal_can_add = ( ! $open_request && ! $is_owned && '' !== $upgrade_from_subscription_id ) ? 1 : 0;
					}

					return true;
				}
			)
		);
	}

	private function get_portal_custom_request_products( $subscriptions, $ledger = array() ) {
		$pdb = $this->get_pdb();

		$purchased_price_ids   = $this->get_customer_purchased_price_ids( $subscriptions );
		$purchased_product_ids = $this->get_customer_purchased_product_ids( $subscriptions );
		$products              = $this->get_portal_filtered_service_products( $subscriptions );
		$available_products    = $this->get_portal_available_service_products( $subscriptions, $ledger );
		$available_price_ids   = array();
		$custom_products       = array();
		$custom_price_ids      = array();
		$custom_request_keys   = array();

		foreach ( $available_products as $available_product ) {
			$available_price_id = isset( $available_product->stripe_price_id ) ? sanitize_text_field( (string) $available_product->stripe_price_id ) : '';
			if ( '' !== $available_price_id ) {
				$available_price_ids[] = $available_price_id;
			}
		}

		$has_service_context = ! empty( $subscriptions );
		if ( ! $has_service_context ) {
			foreach ( (array) $ledger as $entry ) {
				$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
				if ( in_array( $status, array( 'paid', 'succeeded', 'active' ), true ) ) {
					$has_service_context = true;
					break;
				}
			}
		}

		$maybe_add_custom_product = function ( $product ) use ( &$custom_products, &$custom_price_ids, &$custom_request_keys, $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger, $available_price_ids, $has_service_context ) {
			if ( 'custom_request' !== $this->get_portal_product_duplicate_behavior( $product ) ) {
				return;
			}

			$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
			if ( '' === $price_id || in_array( $price_id, $custom_price_ids, true ) ) {
				return;
			}

			$request_title   = ! empty( $product->custom_request_title ) ? sanitize_text_field( (string) $product->custom_request_title ) : '';
			$request_message = ! empty( $product->custom_request_message ) ? sanitize_textarea_field( (string) $product->custom_request_message ) : '';
			$request_button  = ! empty( $product->custom_request_button_label ) ? sanitize_text_field( (string) $product->custom_request_button_label ) : '';
			$request_key_source = trim( $request_title . '|' . $request_message . '|' . $request_button, '|' );
			$request_key = '' !== $request_key_source ? md5( strtolower( $request_key_source ) ) : 'price:' . $price_id;
			if ( isset( $custom_request_keys[ $request_key ] ) ) {
				return;
			}

			if ( $this->is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger ) ) {
				$custom_products[]  = $product;
				$custom_price_ids[] = $price_id;
				$custom_request_keys[ $request_key ] = true;
				return;
			}

			// Last-resort generic fallback: if this product has been configured for custom requests,
			// is not available as a normal Add Service card, and the customer has active/paid service
			// context, show the configured custom request card. This avoids hardcoding service names
			// while still honoring the admin product behavior setting.
			if ( $has_service_context && ! in_array( $price_id, $available_price_ids, true ) ) {
				$custom_products[]  = $product;
				$custom_price_ids[] = $price_id;
				$custom_request_keys[ $request_key ] = true;
			}
		};

		foreach ( $products as $product ) {
			$maybe_add_custom_product( $product );
		}

		// Do not let product-mode selection hide an already-configured custom-request upsell.
		// The admin explicitly controls this using each product's duplicate_behavior setting.
		$fallback_products = $pdb->get_results(
			"SELECT p.*,
				COALESCE(c.visibility, p.visibility, 'hidden') AS visibility,
				COALESCE(c.custom_label, p.custom_label, '') AS custom_label,
				COALESCE(c.sort_order, p.sort_order, 0) AS sort_order,
				COALESCE(c.description_override, p.description_override, '') AS description_override,
				COALESCE(c.duplicate_behavior, p.duplicate_behavior, 'no_duplicates') AS duplicate_behavior,
				COALESCE(c.upgrade_from_product_id, p.upgrade_from_product_id, '') AS upgrade_from_product_id,
				COALESCE(c.custom_request_title, p.custom_request_title, '') AS custom_request_title,
				COALESCE(c.custom_request_message, p.custom_request_message, '') AS custom_request_message,
				COALESCE(c.custom_request_button_label, p.custom_request_button_label, '') AS custom_request_button_label,
				c.price_settings AS portal_price_settings
			FROM {$this->get_portal_stripe_products_table()} p
			LEFT JOIN {$this->get_portal_product_catalog_table()} c ON c.stripe_product_id = p.stripe_product_id
			WHERE p.active = 1 AND COALESCE(c.visibility, p.visibility, 'hidden') <> 'hidden' AND COALESCE(c.duplicate_behavior, p.duplicate_behavior, 'no_duplicates') = 'custom_request'
			ORDER BY CASE WHEN COALESCE(c.sort_order, p.sort_order, 0) > 0 THEN 0 ELSE 1 END ASC, sort_order ASC, p.name ASC"
		);
		foreach ( $fallback_products as $product ) {
			$maybe_add_custom_product( $product );
		}

		return $custom_products;
	}

	private function get_portal_custom_request_source_object_id( $stripe_customer_id, $price_id ) {
		return 'custom_request_' . md5( sanitize_text_field( (string) $stripe_customer_id ) . '|' . sanitize_text_field( (string) $price_id ) );
	}

	private function get_open_custom_service_request( $stripe_customer_id, $price_id ) {
		$pdb = $this->get_pdb();

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$price_id           = sanitize_text_field( (string) $price_id );
		if ( '' === $stripe_customer_id || '' === $price_id ) {
			return null;
		}

		$source_object_id = $this->get_portal_custom_request_source_object_id( $stripe_customer_id, $price_id );
		$service_request = $pdb->get_row(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_service_requests_table()} WHERE stripe_customer_id = %s AND source_object_id = %s AND request_type = %s AND status IN ('draft','pending_payment','awaiting_payment','paid','admin_review_required') LIMIT 1",
				$stripe_customer_id,
				$source_object_id,
				'custom_request'
			)
		);
		if ( $service_request ) {
			return $service_request;
		}

		return $pdb->get_row(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE stripe_customer_id = %s AND source_object_id = %s AND source_type = %s AND status IN ('draft','pending_payment','awaiting_payment','paid','admin_review_required') LIMIT 1",
				$stripe_customer_id,
				$source_object_id,
				'custom_service_request'
			)
		);
	}


	private function normalize_portal_service_request_status( $status ) {
		$status = sanitize_key( (string) $status );
		$allowed = array( 'draft', 'pending_payment', 'awaiting_payment', 'paid', 'updating_sosn', 'signing_cmra', 'active', 'cancelled', 'failed', 'admin_review_required', 'completed' );

		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	private function upsert_portal_service_request( $data ) {
		$pdb = $this->get_pdb();

		$defaults = array(
			'wp_user_id'          => get_current_user_id(),
			'stripe_customer_id'  => '',
			'stripe_price_id'     => '',
			'stripe_product_id'   => '',
			'service_name'        => '',
			'request_type'        => 'add_service',
			'status'              => 'draft',
			'amount'              => 0,
			'currency'            => 'usd',
			'source_object_id'    => '',
			'source_type'         => '',
			'ledger_id'           => 0,
			'admin_notes'         => '',
			'client_notes'        => '',
			'source'              => 'system',
			'created_by'          => get_current_user_id(),
			'raw_data'            => '',
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);
		$data = wp_parse_args( is_array( $data ) ? $data : array(), $defaults );
		$data['wp_user_id']         = absint( $data['wp_user_id'] );
		$data['stripe_customer_id'] = sanitize_text_field( (string) $data['stripe_customer_id'] );
		$data['stripe_price_id']    = sanitize_text_field( (string) $data['stripe_price_id'] );
		$data['stripe_product_id']  = sanitize_text_field( (string) $data['stripe_product_id'] );
		$data['service_name']       = sanitize_text_field( (string) $data['service_name'] );
		$data['request_type']       = sanitize_key( (string) $data['request_type'] );
		$data['status']             = $this->normalize_portal_service_request_status( $data['status'] );
		$data['amount']             = (float) $data['amount'];
		$data['currency']           = sanitize_key( (string) $data['currency'] );
		$data['source_object_id']   = sanitize_text_field( (string) $data['source_object_id'] );
		$data['source_type']        = sanitize_key( (string) $data['source_type'] );
		$data['ledger_id']          = absint( $data['ledger_id'] );
		$data['admin_notes']        = sanitize_textarea_field( (string) $data['admin_notes'] );
		$data['client_notes']       = sanitize_textarea_field( (string) $data['client_notes'] );
		$data['source']             = sanitize_key( (string) $data['source'] );
		$data['created_by']         = absint( $data['created_by'] );
		$data['raw_data']           = is_string( $data['raw_data'] ) ? $data['raw_data'] : wp_json_encode( $data['raw_data'] );

		$existing_id = 0;
		if ( '' !== $data['source_object_id'] ) {
			$existing_id = (int) $pdb->get_var(
				$pdb->prepare(
					"SELECT id FROM {$this->get_portal_service_requests_table()} WHERE source_object_id = %s LIMIT 1",
					$data['source_object_id']
				)
			);
		}

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
		if ( $existing_id ) {
			unset( $data['created_at'] );
			array_splice( $formats, -2, 1 );
			$pdb->update( $this->get_portal_service_requests_table(), $data, array( 'id' => $existing_id ), $formats, array( '%d' ) );
			return $existing_id;
		}

		$pdb->insert( $this->get_portal_service_requests_table(), $data, $formats );
		return (int) $pdb->insert_id;
	}

	private function get_open_portal_service_request_for_product( $stripe_customer_id, $price_id, $product_id = '' ) {
		$pdb = $this->get_pdb();

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$price_id = sanitize_text_field( (string) $price_id );
		$product_id = sanitize_text_field( (string) $product_id );
		if ( '' === $stripe_customer_id || ( '' === $price_id && '' === $product_id ) ) {
			return null;
		}

		$open_statuses = array( 'pending_payment', 'awaiting_payment', 'paid', 'admin_review_required' );
		$status_placeholders = implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) );
		$params = array_merge( array( $stripe_customer_id ), $open_statuses );
		$where = "stripe_customer_id = %s AND status IN ({$status_placeholders})";
		if ( '' !== $price_id && '' !== $product_id ) {
			$where .= ' AND (stripe_price_id = %s OR stripe_product_id = %s)';
			$params[] = $price_id;
			$params[] = $product_id;
		} elseif ( '' !== $price_id ) {
			$where .= ' AND stripe_price_id = %s';
			$params[] = $price_id;
		} else {
			$where .= ' AND stripe_product_id = %s';
			$params[] = $product_id;
		}

		return $pdb->get_row( $pdb->prepare( "SELECT * FROM {$this->get_portal_service_requests_table()} WHERE {$where} ORDER BY id DESC LIMIT 1", $params ) );
	}

	private function get_portal_product_amount_label( $product ) {
		$amount   = isset( $product->price_amount ) ? (float) $product->price_amount : 0;
		$currency = isset( $product->currency ) ? $product->currency : 'usd';
		$interval = ! empty( $product->recurring_interval ) ? '/' . sanitize_text_field( (string) $product->recurring_interval ) : '';

		return $this->format_portal_money( $amount, $currency ) . $interval;
	}

	private function get_portal_product_price_display_label( $product ) {
		$amount   = isset( $product->price_amount ) ? (float) $product->price_amount : 0;
		$currency = isset( $product->currency ) ? strtoupper( sanitize_key( (string) $product->currency ) ) : 'USD';
		$interval = ! empty( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '';

		return $currency . ' ' . number_format_i18n( $amount, 2 ) . ( '' !== $interval ? ' ' . sprintf( __( 'Per %s', 'ajforms' ), $interval ) : '' );
	}

	private function get_portal_dependency_product_by_price_id( $price_id ) {
		$pdb = $this->get_pdb();

		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			return null;
		}

		return $pdb->get_row(
			$pdb->prepare(
				"SELECT p.*,
					COALESCE(c.custom_label, p.custom_label, '') AS custom_label,
					COALESCE(c.description_override, p.description_override, '') AS description_override,
					COALESCE(c.sort_order, p.sort_order, 0) AS sort_order
				FROM {$this->get_portal_stripe_products_table()} p
				LEFT JOIN {$this->get_portal_product_catalog_table()} c ON c.stripe_product_id = p.stripe_product_id
				WHERE p.stripe_price_id = %s AND p.active = 1 LIMIT 1",
				$price_id
			)
		);
	}

	private function get_portal_dependency_price_checkout_data( $price_id ) {
		$product = $this->get_portal_dependency_product_by_price_id( $price_id );
		if ( ! $product ) {
			return null;
		}

		return array(
			'id'                 => sanitize_text_field( (string) $price_id ),
			'product_id'         => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
			'product_name'       => ! empty( $product->custom_label ) ? sanitize_text_field( (string) $product->custom_label ) : ( isset( $product->name ) ? sanitize_text_field( (string) $product->name ) : '' ),
			'amount'             => isset( $product->price_amount ) ? (float) $product->price_amount : 0,
			'currency'           => isset( $product->currency ) ? sanitize_key( (string) $product->currency ) : 'usd',
			'recurring_interval' => isset( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '',
			'duplicate_behavior' => 'allow_duplicate',
			'upgrade_from_product_id' => '',
			'upgrade_from_subscription_id' => '',
		);
	}

	private function get_portal_product_dependency_data( $product, $products_by_price = array() ) {
		$price_id             = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
		$dependency_settings  = $this->get_public_product_dependency_settings();
		$required_price_id    = '';
		$required_product_name = '';
		$dependency_note      = '';
		$required_product     = null;
		$raw_data             = $this->decode_portal_json( isset( $product->raw_data ) ? $product->raw_data : '' );
		$metadata             = $this->decode_portal_json( isset( $product->metadata ) ? $product->metadata : '' );
		$catalog_price_settings = ! empty( $product->portal_price_settings ) ? json_decode( (string) $product->portal_price_settings, true ) : array();

		if ( is_array( $catalog_price_settings ) && isset( $catalog_price_settings[ $price_id ] ) && is_array( $catalog_price_settings[ $price_id ] ) ) {
			$required_price_id = isset( $catalog_price_settings[ $price_id ]['requires_price_id'] ) ? sanitize_text_field( (string) $catalog_price_settings[ $price_id ]['requires_price_id'] ) : '';
			$dependency_note   = isset( $catalog_price_settings[ $price_id ]['dependency_note'] ) ? sanitize_textarea_field( (string) $catalog_price_settings[ $price_id ]['dependency_note'] ) : '';
		} elseif ( isset( $dependency_settings[ $price_id ] ) ) {
			$required_price_id = isset( $dependency_settings[ $price_id ]['requires_price_id'] ) ? sanitize_text_field( (string) $dependency_settings[ $price_id ]['requires_price_id'] ) : '';
			$dependency_note   = isset( $dependency_settings[ $price_id ]['dependency_note'] ) ? sanitize_textarea_field( (string) $dependency_settings[ $price_id ]['dependency_note'] ) : '';
		}

		foreach ( array( $metadata, isset( $raw_data['metadata'] ) && is_array( $raw_data['metadata'] ) ? $raw_data['metadata'] : array() ) as $source ) {
			if ( '' === $required_price_id ) {
				foreach ( array( 'ajcore_requires_price_id', 'requires_price_id', 'required_price_id' ) as $key ) {
					if ( ! empty( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
						$required_price_id = sanitize_text_field( (string) $source[ $key ] );
						break;
					}
				}
			}
			if ( '' === $required_product_name ) {
				foreach ( array( 'ajcore_requires_product_name', 'requires_product_name', 'required_product_name' ) as $key ) {
					if ( ! empty( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
						$required_product_name = sanitize_text_field( (string) $source[ $key ] );
						break;
					}
				}
			}
			if ( '' === $dependency_note ) {
				foreach ( array( 'ajcore_dependency_note', 'dependency_note', 'required_product_note' ) as $key ) {
					if ( ! empty( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
						$dependency_note = sanitize_textarea_field( (string) $source[ $key ] );
						break;
					}
				}
			}
		}

		if ( '' !== $required_price_id && isset( $products_by_price[ $required_price_id ] ) && '' === $required_product_name ) {
			$required_product_name = ! empty( $products_by_price[ $required_price_id ]->custom_label ) ? $products_by_price[ $required_price_id ]->custom_label : ( isset( $products_by_price[ $required_price_id ]->name ) ? $products_by_price[ $required_price_id ]->name : '' );
		}
		if ( '' !== $required_price_id && ! isset( $products_by_price[ $required_price_id ] ) ) {
			$required_product = $this->get_portal_dependency_product_by_price_id( $required_price_id );
			if ( $required_product && '' === $required_product_name ) {
				$required_product_name = ! empty( $required_product->custom_label ) ? sanitize_text_field( (string) $required_product->custom_label ) : ( ! empty( $required_product->name ) ? sanitize_text_field( (string) $required_product->name ) : '' );
			}
		}

		if ( '' === $dependency_note && '' !== $required_product_name ) {
			$dependency_note = sprintf( __( 'This service requires %s. It will be added to your cart when available.', 'ajforms' ), $required_product_name );
		}

		$required_product = $required_product ? $required_product : ( isset( $products_by_price[ $required_price_id ] ) ? $products_by_price[ $required_price_id ] : null );

		return array(
			'requires_price_id'        => $required_price_id,
			'requires_product_name'    => $required_product_name,
			'dependency_note'          => $dependency_note,
			'required_amount'          => $required_product && isset( $required_product->price_amount ) ? (float) $required_product->price_amount : 0,
			'required_currency'        => $required_product && ! empty( $required_product->currency ) ? strtolower( sanitize_key( (string) $required_product->currency ) ) : 'usd',
			'required_interval'        => $required_product && ! empty( $required_product->recurring_interval ) ? sanitize_key( (string) $required_product->recurring_interval ) : '',
			'required_price_label'     => $required_product ? $this->get_portal_product_price_display_label( $required_product ) : '',
		);
	}

	private function get_portal_add_service_product_groups( $products ) {
		$products_by_price = array();
		foreach ( (array) $products as $product ) {
			$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
			if ( '' !== $price_id ) {
				$products_by_price[ $price_id ] = $product;
			}
		}

		$groups = array();
		foreach ( (array) $products as $product ) {
			$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
			if ( '' === $price_id ) {
				continue;
			}

			$product_id = isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '';
			$group_key  = '' !== $product_id ? $product_id : $price_id;
			$name       = ! empty( $product->custom_label ) ? sanitize_text_field( (string) $product->custom_label ) : ( isset( $product->name ) ? sanitize_text_field( (string) $product->name ) : __( 'Service', 'ajforms' ) );
			$description = ! empty( $product->description_override ) ? (string) $product->description_override : ( isset( $product->description ) ? (string) $product->description : '' );
			$dependency = $this->get_portal_product_dependency_data( $product, $products_by_price );

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'product_id'   => $product_id,
					'name'         => $name,
					'description'  => $description,
					'prices'       => array(),
					'sort_order'   => isset( $product->sort_order ) ? (int) $product->sort_order : 0,
				);
			}

			$groups[ $group_key ]['prices'][] = array(
				'price_id'              => $price_id,
				'product_id'            => $product_id,
				'name'                  => $name,
				'amount'                => isset( $product->price_amount ) ? (float) $product->price_amount : 0,
				'currency'              => isset( $product->currency ) ? strtolower( sanitize_key( (string) $product->currency ) ) : 'usd',
				'recurring_interval'    => ! empty( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '',
				'price_label'           => $this->get_portal_product_price_display_label( $product ),
				'button_label'          => ! empty( $product->portal_open_request ) ? __( 'Request Pending', 'ajforms' ) : ( empty( $product->portal_can_add ) ? ( ! empty( $product->portal_is_upgrade ) && empty( $product->portal_is_owned ) ? __( 'Requires Existing Service', 'ajforms' ) : __( 'Already Added', 'ajforms' ) ) : ( ! empty( $product->portal_is_upgrade ) ? __( 'Upgrade Service', 'ajforms' ) : __( 'Add to My Services', 'ajforms' ) ) ),
				'can_add'               => ! empty( $product->portal_can_add ) ? 1 : 0,
				'is_upgrade'            => ! empty( $product->portal_is_upgrade ) ? 1 : 0,
				'upgrade_from_product_id' => ! empty( $product->portal_upgrade_from_product_id ) ? sanitize_text_field( (string) $product->portal_upgrade_from_product_id ) : '',
				'upgrade_from_subscription_id' => ! empty( $product->portal_upgrade_from_subscription_id ) ? sanitize_text_field( (string) $product->portal_upgrade_from_subscription_id ) : '',
				'required_price_id'     => $dependency['requires_price_id'],
				'required_product_name' => $dependency['requires_product_name'],
				'dependency_note'       => $dependency['dependency_note'],
				'required_amount'       => $dependency['required_amount'],
				'required_currency'     => $dependency['required_currency'],
				'required_interval'     => $dependency['required_interval'],
				'required_price_label'  => $dependency['required_price_label'],
			);
		}

		uasort(
			$groups,
			function ( $a, $b ) {
				$sort_a = isset( $a['sort_order'] ) ? (int) $a['sort_order'] : 0;
				$sort_b = isset( $b['sort_order'] ) ? (int) $b['sort_order'] : 0;
				$bucket_a = $sort_a > 0 ? 0 : 1;
				$bucket_b = $sort_b > 0 ? 0 : 1;
				if ( $bucket_a !== $bucket_b ) {
					return $bucket_a <=> $bucket_b;
				}
				if ( $sort_a === $sort_b ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
				return $sort_a <=> $sort_b;
			}
		);

		return array_values( $groups );
	}

	private function get_portal_product_by_price_id( $price_id ) {
		$pdb = $this->get_pdb();

		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			return null;
		}

		return $pdb->get_row(
			$pdb->prepare(
				"SELECT p.*,
					COALESCE(c.visibility, p.visibility, 'hidden') AS visibility,
					COALESCE(c.custom_label, p.custom_label, '') AS custom_label,
					COALESCE(c.sort_order, p.sort_order, 0) AS sort_order,
					COALESCE(c.description_override, p.description_override, '') AS description_override,
					COALESCE(c.duplicate_behavior, p.duplicate_behavior, 'no_duplicates') AS duplicate_behavior,
					COALESCE(c.upgrade_from_product_id, p.upgrade_from_product_id, '') AS upgrade_from_product_id,
					COALESCE(c.custom_request_title, p.custom_request_title, '') AS custom_request_title,
					COALESCE(c.custom_request_message, p.custom_request_message, '') AS custom_request_message,
					COALESCE(c.custom_request_button_label, p.custom_request_button_label, '') AS custom_request_button_label,
					c.price_settings AS portal_price_settings
				FROM {$this->get_portal_stripe_products_table()} p
				LEFT JOIN {$this->get_portal_product_catalog_table()} c ON c.stripe_product_id = p.stripe_product_id
				WHERE p.stripe_price_id = %s AND p.active = 1 AND COALESCE(c.visibility, p.visibility, 'hidden') <> 'hidden' LIMIT 1",
				$price_id
			)
		);
	}

	private function get_portal_product_for_snapshot( $snapshot ) {
		$pdb = $this->get_pdb();

		if ( ! empty( $snapshot->price_id ) ) {
			$product = $pdb->get_row(
				$pdb->prepare(
					"SELECT * FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s LIMIT 1",
					sanitize_text_field( (string) $snapshot->price_id )
				)
			);
			if ( $product ) {
				return $product;
			}
		}

		if ( ! empty( $snapshot->product_id ) ) {
			return $pdb->get_row(
				$pdb->prepare(
					"SELECT * FROM {$this->get_portal_stripe_products_table()} WHERE stripe_product_id = %s ORDER BY recurring_interval DESC, active DESC, id DESC LIMIT 1",
					sanitize_text_field( (string) $snapshot->product_id )
				)
			);
		}

		return null;
	}

	private function get_portal_product_recurring_interval_from_ids( $price_id = '', $product_id = '' ) {
		$pdb = $this->get_pdb();

		$price_id   = sanitize_text_field( (string) $price_id );
		$product_id = sanitize_text_field( (string) $product_id );

		if ( '' !== $price_id ) {
			$row = $pdb->get_row(
				$pdb->prepare(
					"SELECT recurring_interval FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s LIMIT 1",
					$price_id
				)
			);
			if ( $row ) {
				return ! empty( $row->recurring_interval ) ? sanitize_key( (string) $row->recurring_interval ) : '';
			}
			return null;
		}

		if ( '' !== $product_id ) {
			$row = $pdb->get_row(
				$pdb->prepare(
					"SELECT recurring_interval FROM {$this->get_portal_stripe_products_table()} WHERE stripe_product_id = %s ORDER BY recurring_interval DESC, active DESC, id DESC LIMIT 1",
					$product_id
				)
			);
			if ( $row ) {
				return ! empty( $row->recurring_interval ) ? sanitize_key( (string) $row->recurring_interval ) : '';
			}
			return null;
		}

		return null;
	}

	private function get_current_user_portal_billing_context() {
		$pdb = $this->get_pdb();

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$customer           = $this->get_current_user_portal_customer();

		if ( '' === $stripe_customer_id || ! $customer ) {
			return array(
				'stripe_customer_id'  => '',
				'customer'            => null,
				'subscriptions'       => array(),
				'ledger'              => array(),
				'upcoming'            => array(),
				'active_subscriptions'=> array(),
			);
		}

		$subscriptions = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_customer_id = %s ORDER BY current_period_end ASC",
				$stripe_customer_id
			)
		);
		$snapshots = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_service_snapshots_table()} WHERE stripe_customer_id = %s ORDER BY service_period_end DESC, updated_at DESC, id DESC LIMIT 100",
				$stripe_customer_id
			)
		);
		$snapshots = $this->apply_portal_service_states_to_snapshots( $snapshots );
		$ledger = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE stripe_customer_id = %s AND source_type <> 'refund' AND " . $this->get_ignored_unpaid_checkout_sql_fragment() . " ORDER BY ledger_date ASC, id ASC LIMIT 100",
				$stripe_customer_id
			)
		);
		$ledger = $this->get_customer_portal_display_ledger( $ledger );
		$upcoming = array_filter(
			$subscriptions,
			function ( $subscription ) {
				if ( empty( $subscription->current_period_end ) || ! in_array( $subscription->status, array( 'active', 'trialing' ), true ) ) {
					return false;
				}
				$renewal = strtotime( $subscription->current_period_end . ' UTC' );
				return $renewal && $renewal >= time();
			}
		);
		$active_subscriptions = array_filter(
			$subscriptions,
			function ( $subscription ) {
				return $this->is_current_portal_subscription( $subscription );
			}
		);

		return array(
			'stripe_customer_id'   => $stripe_customer_id,
			'customer'             => $customer,
			'subscriptions'        => $subscriptions,
			'service_snapshots'    => $snapshots,
			'ledger'               => $ledger,
			'upcoming'             => $upcoming,
			'active_subscriptions' => $active_subscriptions,
		);
	}

	private function get_snapshot_service_name( $snapshot ) {
		return ! empty( $snapshot->product_name_snapshot ) ? sanitize_text_field( (string) $snapshot->product_name_snapshot ) : __( 'Service', 'ajforms' );
	}

	private function apply_portal_service_states_to_snapshots( $snapshots ) {
		$pdb = $this->get_pdb();

		$snapshots = is_array( $snapshots ) ? $snapshots : array();
		if ( empty( $snapshots ) ) {
			return $snapshots;
		}

		$table = $this->get_portal_service_states_table();
		$exists = $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return $snapshots;
		}

		$customer_ids = array();
		$emails       = array();
		foreach ( $snapshots as $snapshot ) {
			if ( ! empty( $snapshot->stripe_customer_id ) ) {
				$customer_ids[] = sanitize_text_field( (string) $snapshot->stripe_customer_id );
			}
			if ( ! empty( $snapshot->customer_email ) ) {
				$emails[] = sanitize_email( (string) $snapshot->customer_email );
			}
		}
		$customer_ids = array_values( array_unique( array_filter( $customer_ids ) ) );
		$emails       = array_values( array_unique( array_filter( $emails ) ) );
		if ( empty( $customer_ids ) && empty( $emails ) ) {
			return $snapshots;
		}

		$where = array();
		$params = array();
		if ( ! empty( $customer_ids ) ) {
			$where[] = 'stripe_customer_id IN (' . implode( ',', array_fill( 0, count( $customer_ids ), '%s' ) ) . ')';
			$params = array_merge( $params, $customer_ids );
		}
		if ( ! empty( $emails ) ) {
			$where[] = 'customer_email IN (' . implode( ',', array_fill( 0, count( $emails ), '%s' ) ) . ')';
			$params = array_merge( $params, $emails );
		}

		$states = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM {$table} WHERE (" . implode( ' OR ', $where ) . ") AND service_status <> '' ORDER BY updated_at DESC, id DESC LIMIT 200",
				$params
			)
		);
		if ( empty( $states ) ) {
			return $snapshots;
		}

		foreach ( $snapshots as $snapshot ) {
			foreach ( $states as $state ) {
				if ( $this->portal_service_state_matches_snapshot( $state, $snapshot ) ) {
					$snapshot->status = sanitize_key( (string) $state->service_status );
					if ( ! empty( $state->used_at ) ) {
						$snapshot->used_at = $state->used_at;
					}
					if ( ! empty( $state->notes ) ) {
						$snapshot->service_state_notes = $state->notes;
					}
					break;
				}
			}
		}

		return $snapshots;
	}

	private function portal_service_state_matches_snapshot( $state, $snapshot ) {
		$state_name = ! empty( $state->product_name ) ? sanitize_title( (string) $state->product_name ) : '';
		$snapshot_name = ! empty( $snapshot->product_name_snapshot ) ? sanitize_title( (string) $snapshot->product_name_snapshot ) : '';
		if ( '' !== $state_name && '' !== $snapshot_name && $state_name !== $snapshot_name ) {
			return false;
		}

		if ( ! empty( $state->amount ) && ! empty( $snapshot->amount ) && (float) $state->amount !== (float) $snapshot->amount ) {
			return false;
		}

		foreach ( array( 'product_id', 'price_id', 'invoice_id', 'checkout_session_id', 'payment_intent_id', 'charge_id', 'subscription_id' ) as $field ) {
			if ( ! empty( $state->$field ) && ! empty( $snapshot->$field ) && (string) $state->$field === (string) $snapshot->$field ) {
				return true;
			}
		}

		$state_period = ! empty( $state->service_period ) ? sanitize_title( (string) $state->service_period ) : '';
		$snapshot_period = ! empty( $snapshot->service_period ) ? sanitize_title( (string) $snapshot->service_period ) : '';
		if ( '' !== $state_period && '' !== $snapshot_period && $state_period === $snapshot_period ) {
			return true;
		}

		return '' !== $state_name && '' !== $snapshot_name && $state_name === $snapshot_name;
	}

	private function get_snapshot_reference_label( $snapshot ) {
		return '';
	}

	private function get_customer_portal_ledger_display_rank( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( 'service_charge' === $source_type ) {
			return 10;
		}
		if ( in_array( $source_type, array( 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return 20;
		}
		if ( 'manual_charge' === $source_type ) {
			return 30;
		}
		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			return 90;
		}

		return 100;
	}

	private function get_customer_portal_ledger_status_rank( $entry ) {
		$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		$ranks  = array(
			'paid'      => 1,
			'succeeded' => 2,
			'completed' => 3,
			'complete'  => 4,
			'open'      => 5,
			'unpaid'    => 6,
		);

		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 20;
	}

	private function customer_portal_ledger_entry_is_recurring_service( $entry ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		$product_interval = $this->get_portal_product_recurring_interval_from_ids(
			! empty( $metadata['price_id'] ) ? $metadata['price_id'] : '',
			! empty( $metadata['product_id'] ) ? $metadata['product_id'] : ''
		);
		if ( null !== $product_interval ) {
			return '' !== $product_interval;
		}

		$billing_type       = ! empty( $metadata['billing_type'] ) ? sanitize_key( (string) $metadata['billing_type'] ) : '';
		$recurring_interval = ! empty( $metadata['recurring_interval'] ) ? sanitize_key( (string) $metadata['recurring_interval'] ) : '';

		return 'recurring' === $billing_type || '' !== $recurring_interval;
	}

	private function get_customer_portal_ledger_reference_rank( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			return ! empty( $entry->payment_intent_id ) ? 1 : ( ! empty( $entry->charge_id ) ? 2 : 6 );
		}

		if ( ! in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return 9;
		}

		$is_recurring = $this->customer_portal_ledger_entry_is_recurring_service( $entry );
		if ( $is_recurring ) {
			if ( '' !== $this->get_ledger_metadata_value( $entry, 'subscription_id' ) ) {
				return 1;
			}
			if ( '' !== $this->get_ledger_metadata_value( $entry, 'price_id' ) || '' !== $this->get_ledger_metadata_value( $entry, 'product_id' ) ) {
				return 3;
			}
			return 7;
		}

		if ( '' !== $this->get_ledger_metadata_value( $entry, 'checkout_session_id' ) ) {
			return 1;
		}
		if ( '' !== $this->get_ledger_metadata_value( $entry, 'payment_intent_id' ) ) {
			return 2;
		}
		if ( '' !== $this->get_ledger_metadata_value( $entry, 'price_id' ) || '' !== $this->get_ledger_metadata_value( $entry, 'product_id' ) ) {
			return 3;
		}

		return 7;
	}

	private function get_customer_portal_ledger_service_key( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( ! in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return '';
		}

		$parts    = array(
			! empty( $entry->stripe_customer_id ) ? sanitize_text_field( (string) $entry->stripe_customer_id ) : '',
			! empty( $entry->description ) ? sanitize_title( $this->clean_stripe_line_service_name( (string) $entry->description ) ) : '',
			! empty( $entry->invoice_id ) ? sanitize_text_field( (string) $entry->invoice_id ) : $this->get_ledger_metadata_value( $entry, 'invoice_id' ),
			number_format( abs( (float) $entry->amount ), 2, '.', '' ),
		);

		return implode( ':', array_filter( $parts, 'strlen' ) );
	}

	private function should_show_customer_portal_ledger_entry( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( in_array( $source_type, array( 'invoice', 'checkout_session' ), true ) ) {
			return false;
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item', 'manual_charge', 'charge', 'payment' ), true ) ) {
			return 0.0 !== (float) $this->get_portal_ledger_balance_effect( $entry ) || in_array( $source_type, array( 'charge', 'payment' ), true );
		}

		return false;
	}

	private function get_customer_portal_display_ledger( $ledger ) {
		$display = array();
		foreach ( (array) $ledger as $entry ) {
			if ( ! $this->should_show_customer_portal_ledger_entry( $entry ) ) {
				continue;
			}

			$key = $this->get_customer_portal_ledger_service_key( $entry );
			if ( '' !== $key && isset( $display[ $key ] ) ) {
				$existing = $display[ $key ];
				$current_rank  = $this->get_customer_portal_ledger_display_rank( $entry );
				$existing_rank = $this->get_customer_portal_ledger_display_rank( $existing );
				$current_ref_rank = $this->get_customer_portal_ledger_reference_rank( $entry );
				$existing_ref_rank = $this->get_customer_portal_ledger_reference_rank( $existing );
				if ( $current_rank < $existing_rank || ( $current_rank === $existing_rank && ( $current_ref_rank < $existing_ref_rank || ( $current_ref_rank === $existing_ref_rank && $this->get_customer_portal_ledger_status_rank( $entry ) < $this->get_customer_portal_ledger_status_rank( $existing ) ) ) ) ) {
					$display[ $key ] = $entry;
				}
				continue;
			}

			$display[ '' !== $key ? $key : 'entry_' . ( isset( $entry->id ) ? (int) $entry->id : count( $display ) ) ] = $entry;
		}

		$display = array_values( $display );
		usort(
			$display,
			function ( $a, $b ) {
				$a_time = ! empty( $a->ledger_date ) ? strtotime( $a->ledger_date . ' UTC' ) : 0;
				$b_time = ! empty( $b->ledger_date ) ? strtotime( $b->ledger_date . ' UTC' ) : 0;
				if ( $a_time === $b_time ) {
					$rank_compare = $this->get_customer_portal_ledger_display_rank( $a ) <=> $this->get_customer_portal_ledger_display_rank( $b );
					if ( 0 !== $rank_compare ) {
						return $rank_compare;
					}
					return ( isset( $a->id ) ? (int) $a->id : 0 ) <=> ( isset( $b->id ) ? (int) $b->id : 0 );
				}

				return $a_time <=> $b_time;
			}
		);

		return $display;
	}

	private function get_snapshot_service_amount_label( $snapshot ) {
		if ( ! empty( $snapshot->price_label_snapshot ) ) {
			return sanitize_text_field( (string) $snapshot->price_label_snapshot );
		}

		$interval = ! empty( $snapshot->recurring_interval ) ? '/' . sanitize_text_field( (string) $snapshot->recurring_interval ) : '';
		return $this->format_portal_money( isset( $snapshot->amount ) ? $snapshot->amount : 0, isset( $snapshot->currency ) ? $snapshot->currency : 'usd' ) . $interval;
	}

	private function get_snapshot_service_period_label( $snapshot ) {
		if ( ! empty( $snapshot->service_period ) ) {
			return sanitize_text_field( (string) $snapshot->service_period );
		}

		if ( ! empty( $snapshot->service_period_start ) && ! empty( $snapshot->service_period_end ) ) {
			return $this->format_portal_date( $snapshot->service_period_start ) . ' - ' . $this->format_portal_date( $snapshot->service_period_end );
		}

		if ( 'recurring' === $this->get_snapshot_billing_type_key( $snapshot ) ) {
			$subscription_period = $this->get_snapshot_subscription_period_context( $snapshot );
			if ( ! empty( $subscription_period['current_period_start'] ) && ! empty( $subscription_period['current_period_end'] ) ) {
				return $this->format_portal_date( $subscription_period['current_period_start'] ) . ' - ' . $this->format_portal_date( $subscription_period['current_period_end'] );
			}
			if ( ! empty( $subscription_period['current_period_end'] ) ) {
				return __( 'Through ', 'ajforms' ) . $this->format_portal_date( $subscription_period['current_period_end'] );
			}
		}

		if ( ! empty( $snapshot->service_period_end ) ) {
			return __( 'Through ', 'ajforms' ) . $this->format_portal_date( $snapshot->service_period_end );
		}

		if ( 'one_time' === $this->get_snapshot_billing_type_key( $snapshot ) && ! empty( $snapshot->created_at ) ) {
			return sprintf( __( 'Purchased %s', 'ajforms' ), $this->format_portal_date( $snapshot->created_at ) );
		}

		return '-';
	}

	private function get_snapshot_billing_type_key( $snapshot ) {
		if ( ! empty( $snapshot->recurring_interval ) ) {
			return 'recurring';
		}

		$product = $this->get_portal_product_for_snapshot( $snapshot );
		if ( $product && ! empty( $product->recurring_interval ) ) {
			return 'recurring';
		}

		if ( $product && empty( $product->recurring_interval ) ) {
			return 'one_time';
		}

		if ( ! empty( $snapshot->subscription_id ) ) {
			return 'recurring';
		}

		return ! empty( $snapshot->billing_type ) && 'recurring' === sanitize_key( (string) $snapshot->billing_type ) ? 'recurring' : 'one_time';
	}

	private function get_snapshot_subscription_period_context( $snapshot ) {
		if ( empty( $snapshot->subscription_id ) ) {
			return array();
		}

		$pdb = $this->get_pdb();
		$subscription = $pdb->get_row(
			$pdb->prepare(
				"SELECT current_period_end, raw_data FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_subscription_id = %s LIMIT 1",
				sanitize_text_field( (string) $snapshot->subscription_id )
			)
		);
		if ( ! $subscription ) {
			return array();
		}

		$raw = $this->decode_portal_json( $subscription->raw_data );
		return array(
			'current_period_start' => ! empty( $raw['current_period_start'] ) ? gmdate( 'Y-m-d H:i:s', absint( $raw['current_period_start'] ) ) : '',
			'current_period_end'   => ! empty( $subscription->current_period_end ) ? $subscription->current_period_end : ( ! empty( $raw['current_period_end'] ) ? gmdate( 'Y-m-d H:i:s', absint( $raw['current_period_end'] ) ) : '' ),
		);
	}

	private function get_snapshot_service_identity_key( $snapshot ) {
		$customer = ! empty( $snapshot->stripe_customer_id ) ? sanitize_text_field( (string) $snapshot->stripe_customer_id ) : ( ! empty( $snapshot->guest_customer_id ) ? sanitize_text_field( (string) $snapshot->guest_customer_id ) : sanitize_email( (string) $snapshot->customer_email ) );
		$product  = ! empty( $snapshot->product_id ) ? sanitize_text_field( (string) $snapshot->product_id ) : sanitize_title( $this->get_snapshot_service_name( $snapshot ) );
		$price    = ! empty( $snapshot->price_id ) ? sanitize_text_field( (string) $snapshot->price_id ) : '';
		if ( ! empty( $snapshot->subscription_id ) ) {
			return implode( ':', array_filter( array( $customer, $product, $price, sanitize_text_field( (string) $snapshot->subscription_id ) ), 'strlen' ) );
		}
		foreach ( array( 'checkout_session_id', 'invoice_id', 'payment_intent_id', 'charge_id' ) as $field ) {
			if ( ! empty( $snapshot->$field ) ) {
				return implode( ':', array_filter( array( $customer, $product, $price, sanitize_text_field( (string) $snapshot->$field ) ), 'strlen' ) );
			}
		}

		return implode( ':', array_filter( array( $customer, $product, $price, ! empty( $snapshot->service_period ) ? sanitize_title( (string) $snapshot->service_period ) : '' ), 'strlen' ) );
	}

	private function portal_service_snapshots_match_recurring_service( $snapshot, $existing_snapshot ) {
		if ( 'recurring' !== $this->get_snapshot_billing_type_key( $snapshot ) || 'recurring' !== $this->get_snapshot_billing_type_key( $existing_snapshot ) ) {
			return false;
		}

		$customer = ! empty( $snapshot->stripe_customer_id ) ? (string) $snapshot->stripe_customer_id : '';
		$existing_customer = ! empty( $existing_snapshot->stripe_customer_id ) ? (string) $existing_snapshot->stripe_customer_id : '';
		if ( '' === $customer || '' === $existing_customer || $customer !== $existing_customer ) {
			return false;
		}

		$product_match = ! empty( $snapshot->product_id ) && ! empty( $existing_snapshot->product_id ) && (string) $snapshot->product_id === (string) $existing_snapshot->product_id;
		$price_match = ! empty( $snapshot->price_id ) && ! empty( $existing_snapshot->price_id ) && (string) $snapshot->price_id === (string) $existing_snapshot->price_id;
		if ( ! $product_match && ! $price_match ) {
			return false;
		}

		return ! empty( $snapshot->subscription_id ) || ! empty( $existing_snapshot->subscription_id );
	}

	private function portal_service_snapshots_match_one_time_service( $snapshot, $existing_snapshot ) {
		if ( 'one_time' !== $this->get_snapshot_billing_type_key( $snapshot ) || 'one_time' !== $this->get_snapshot_billing_type_key( $existing_snapshot ) ) {
			return false;
		}

		$customer = ! empty( $snapshot->stripe_customer_id ) ? (string) $snapshot->stripe_customer_id : ( ! empty( $snapshot->guest_customer_id ) ? (string) $snapshot->guest_customer_id : (string) $snapshot->customer_email );
		$existing_customer = ! empty( $existing_snapshot->stripe_customer_id ) ? (string) $existing_snapshot->stripe_customer_id : ( ! empty( $existing_snapshot->guest_customer_id ) ? (string) $existing_snapshot->guest_customer_id : (string) $existing_snapshot->customer_email );
		if ( '' === $customer || '' === $existing_customer || $customer !== $existing_customer ) {
			return false;
		}

		$name = sanitize_title( $this->get_snapshot_service_name( $snapshot ) );
		$existing_name = sanitize_title( $this->get_snapshot_service_name( $existing_snapshot ) );
		if ( '' === $name || '' === $existing_name || $name !== $existing_name ) {
			return false;
		}

		$product_match = ! empty( $snapshot->product_id ) && ! empty( $existing_snapshot->product_id ) && (string) $snapshot->product_id === (string) $existing_snapshot->product_id;
		$price_match = ! empty( $snapshot->price_id ) && ! empty( $existing_snapshot->price_id ) && (string) $snapshot->price_id === (string) $existing_snapshot->price_id;
		if ( $product_match || $price_match ) {
			return true;
		}

		foreach ( array( 'checkout_session_id', 'invoice_id', 'payment_intent_id', 'charge_id' ) as $field ) {
			if ( ! empty( $snapshot->$field ) && ! empty( $existing_snapshot->$field ) && (string) $snapshot->$field === (string) $existing_snapshot->$field ) {
				return true;
			}
		}

		if ( ! empty( $snapshot->amount ) && ! empty( $existing_snapshot->amount ) && (float) $snapshot->amount !== (float) $existing_snapshot->amount ) {
			return false;
		}

		$period = ! empty( $snapshot->service_period ) ? sanitize_title( (string) $snapshot->service_period ) : '';
		$existing_period = ! empty( $existing_snapshot->service_period ) ? sanitize_title( (string) $existing_snapshot->service_period ) : '';
		return '' !== $period && '' !== $existing_period && $period === $existing_period;
	}

	private function get_snapshot_display_status_rank( $snapshot ) {
		$status = isset( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : '';
		$type   = $this->get_snapshot_billing_type_key( $snapshot );

		if ( 'one_time' === $type ) {
			$ranks = array(
				'used'      => 0,
				'paid'      => 1,
				'succeeded' => 2,
				'completed' => 3,
				'complete'  => 3,
				'active'    => 4,
			);
		} else {
			$ranks = array(
				'active'    => 0,
				'trialing'  => 0,
				'paid'      => 1,
				'succeeded' => 2,
				'completed' => 3,
				'complete'  => 3,
				'canceled'  => 9,
				'cancelled' => 9,
			);
		}

		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 99;
	}

	private function merge_portal_service_snapshot_for_display( $existing_snapshot, $candidate_snapshot ) {
		$use_candidate = $this->get_snapshot_display_status_rank( $candidate_snapshot ) < $this->get_snapshot_display_status_rank( $existing_snapshot );
		$primary       = $use_candidate ? clone $candidate_snapshot : clone $existing_snapshot;
		$fallback      = $use_candidate ? $existing_snapshot : $candidate_snapshot;

		foreach ( array( 'product_id', 'price_id', 'checkout_session_id', 'invoice_id', 'payment_intent_id', 'charge_id', 'subscription_id', 'service_period', 'service_period_start', 'service_period_end', 'next_billing_date', 'price_label_snapshot', 'raw_data' ) as $field ) {
			if ( empty( $primary->$field ) && ! empty( $fallback->$field ) ) {
				$primary->$field = $fallback->$field;
			}
		}

		return $primary;
	}

	private function get_subscription_service_identity_parts( $subscription ) {
		$parts = array(
			'price_ids'   => array(),
			'product_ids' => array(),
		);
		$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
		if ( ! is_array( $items ) ) {
			return $parts;
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! empty( $item['price_id'] ) ) {
				$parts['price_ids'][] = sanitize_text_field( (string) $item['price_id'] );
			}
			if ( ! empty( $item['product_id'] ) ) {
				$parts['product_ids'][] = sanitize_text_field( (string) $item['product_id'] );
			}
			if ( ! empty( $item['price'] ) && is_array( $item['price'] ) ) {
				if ( ! empty( $item['price']['id'] ) ) {
					$parts['price_ids'][] = sanitize_text_field( (string) $item['price']['id'] );
				}
				if ( ! empty( $item['price']['product'] ) ) {
					if ( is_array( $item['price']['product'] ) && ! empty( $item['price']['product']['id'] ) ) {
						$parts['product_ids'][] = sanitize_text_field( (string) $item['price']['product']['id'] );
					} elseif ( is_scalar( $item['price']['product'] ) ) {
						$parts['product_ids'][] = sanitize_text_field( (string) $item['price']['product'] );
					}
				}
			}
		}

		$parts['price_ids'] = array_values( array_unique( array_filter( $parts['price_ids'] ) ) );
		$parts['product_ids'] = array_values( array_unique( array_filter( $parts['product_ids'] ) ) );
		return $parts;
	}

	private function recurring_snapshot_is_represented_by_active_subscription( $snapshot, $active_subscriptions ) {
		if ( empty( $active_subscriptions ) ) {
			return false;
		}

		if ( ! empty( $snapshot->subscription_id ) ) {
			foreach ( (array) $active_subscriptions as $subscription ) {
				if ( ! empty( $subscription->stripe_subscription_id ) && (string) $subscription->stripe_subscription_id === (string) $snapshot->subscription_id ) {
					return true;
				}
			}
		}

		$snapshot_price = ! empty( $snapshot->price_id ) ? sanitize_text_field( (string) $snapshot->price_id ) : '';
		$snapshot_product = ! empty( $snapshot->product_id ) ? sanitize_text_field( (string) $snapshot->product_id ) : '';
		foreach ( (array) $active_subscriptions as $subscription ) {
			$identity = $this->get_subscription_service_identity_parts( $subscription );
			if ( '' !== $snapshot_price && in_array( $snapshot_price, $identity['price_ids'], true ) ) {
				return true;
			}
			if ( '' !== $snapshot_product && in_array( $snapshot_product, $identity['product_ids'], true ) ) {
				return true;
			}
		}

		// Name-token matching only applies to recurring snapshots. A one-time service (e.g.
		// "Virtual Office Setup") must never be suppressed just because a subscription with a
		// similarly-named service (e.g. "Virtual Office Subscription") is active — they are
		// distinct purchases.
		if ( 'one_time' === $this->get_snapshot_billing_type_key( $snapshot ) ) {
			return false;
		}

		$snapshot_name   = $this->clean_stripe_line_service_name( $this->get_snapshot_service_name( $snapshot ) );
		$snapshot_tokens = $this->get_portal_service_identity_tokens( $snapshot_name );
		if ( empty( $snapshot_tokens ) ) {
			return false;
		}

		foreach ( (array) $active_subscriptions as $subscription ) {
			$subscription_name   = $this->clean_stripe_line_service_name( $this->get_subscription_service_name( $subscription ) );
			$subscription_tokens = $this->get_portal_service_identity_tokens( $subscription_name );
			if ( $this->portal_service_token_sets_match( $snapshot_tokens, $subscription_tokens ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_portal_subscription_reference_label( $subscription ) {
		return '';
	}

	private function dedupe_portal_service_snapshots_for_display( $snapshots ) {
		$deduped = array();
		$order   = array();
		foreach ( (array) $snapshots as $snapshot ) {
			$key = $this->get_snapshot_service_identity_key( $snapshot );
			foreach ( $deduped as $existing_key => $existing_snapshot ) {
				if (
					$this->portal_service_snapshots_match_recurring_service( $snapshot, $existing_snapshot )
					|| $this->portal_service_snapshots_match_one_time_service( $snapshot, $existing_snapshot )
				) {
					$key = $existing_key;
					break;
				}
			}
			if ( isset( $deduped[ $key ] ) ) {
				$deduped[ $key ] = $this->merge_portal_service_snapshot_for_display( $deduped[ $key ], $snapshot );
				continue;
			}
			$deduped[ $key ] = $snapshot;
			$order[] = $key;
		}

		$records = array();
		foreach ( $order as $key ) {
			$records[] = $deduped[ $key ];
		}

		return $records;
	}

	private function get_snapshot_next_billing_date_label( $snapshot ) {
		if ( 'recurring' !== $this->get_snapshot_billing_type_key( $snapshot ) ) {
			return __( 'No future billing', 'ajforms' );
		}

		foreach ( array( 'next_billing_date', 'service_period_end' ) as $field ) {
			if ( ! empty( $snapshot->$field ) ) {
				return $this->format_portal_date( $snapshot->$field );
			}
		}

		$subscription_period = $this->get_snapshot_subscription_period_context( $snapshot );
		if ( ! empty( $subscription_period['current_period_end'] ) ) {
			return $this->format_portal_date( $subscription_period['current_period_end'] );
		}

		return '-';
	}

	private function is_current_portal_service_snapshot( $snapshot ) {
		$status = isset( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : '';
		$type   = $this->get_snapshot_billing_type_key( $snapshot );

		if ( 'recurring' === $type ) {
			return in_array( $status, array( 'active', 'trialing', 'paid', 'succeeded', 'completed' ), true );
		}

		return in_array( $status, array( 'paid', 'succeeded', 'completed', 'complete' ), true );
	}

	private function is_displayable_customer_portal_snapshot( $snapshot ) {
		$name = $this->get_snapshot_service_name( $snapshot );
		if ( $this->is_generic_customer_portal_service_label( $name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * IDs (invoice / payment intent / charge) of this customer's fully refunded payments,
	 * derived from synced Stripe charge data via the shared payment enrichment. Used to keep
	 * refunded services out of Current Services.
	 */
	private function get_portal_refunded_payment_refs( $stripe_customer_id ) {
		$refs = array();
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		if ( '' === $stripe_customer_id || ! class_exists( 'AJCore_REST_API' ) ) {
			return $refs;
		}

		$pdb  = $this->get_pdb();
		$rows = $pdb->get_results(
			$pdb->prepare(
				"SELECT id, stripe_object_id, object_type, stripe_customer_id, description, amount, currency, status, transaction_date, invoice_id, payment_intent_id, charge_id, raw_data FROM {$pdb->prefix}aj_portal_stripe_transactions WHERE stripe_customer_id = %s",
				$stripe_customer_id
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return $refs;
		}

		$api  = new AJCore_REST_API();
		$rows = $api->dedupe_stripe_transaction_rows( $rows );
		$rows = $api->attach_payment_display_fields( $rows );
		foreach ( $rows as $row ) {
			if ( 'refunded' !== ( isset( $row['refund_status'] ) ? $row['refund_status'] : '' ) ) {
				continue; // Partial refunds keep the service in place.
			}
			foreach ( array( 'stripe_object_id', 'invoice_id', 'payment_intent_id', 'charge_id' ) as $field ) {
				if ( ! empty( $row[ $field ] ) ) {
					$refs[ (string) $row[ $field ] ] = true;
				}
			}
		}

		return $refs;
	}

	private function portal_snapshot_payment_was_refunded( $snapshot, $refunded_refs ) {
		if ( empty( $refunded_refs ) ) {
			return false;
		}
		foreach ( array( 'invoice_id', 'payment_intent_id', 'checkout_session_id' ) as $field ) {
			if ( ! empty( $snapshot->$field ) && isset( $refunded_refs[ (string) $snapshot->$field ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_generic_customer_portal_service_label( $label ) {
		$label = strtolower( trim( sanitize_text_field( (string) $label ) ) );
		if ( '' === $label ) {
			return true;
		}

		$generic = array(
			'payment',
			'website checkout payment',
			'checkout payment',
			'service checkout payment',
			'balance payment',
			'subscription creation',
			'billing item',
			'charge',
			'invoice',
			'checkout session',
			'payment for invoice',
		);

		if ( in_array( $label, $generic, true ) ) {
			return true;
		}

		return (bool) preg_match( '/^(cs_|pi_|ch_|in_|sub_|price_|prod_)/', $label );
	}

	private function render_customer_portal_services_tab() {
		$context = $this->get_current_user_portal_billing_context();
		$customer = $context['customer'];

		if ( '' === $context['stripe_customer_id'] || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'My Services', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$subscriptions      = $context['subscriptions'];
		$current_services   = array_values( array_filter( $subscriptions, array( $this, 'is_current_portal_subscription' ) ) );
		$past_services      = array_values(
			array_filter(
				$subscriptions,
				function ( $subscription ) {
					return ! $this->is_current_portal_subscription( $subscription );
				}
			)
		);
		// The Stripe subscription rows drive Current vs Past. A snapshot represented by ANY
		// known subscription is suppressed — active subscriptions render under Current, and a
		// canceled subscription renders under Past, so its paid-invoice snapshot must not
		// linger under Current Services as "Paid".
		$displayable_snapshot_services = $this->dedupe_portal_service_snapshots_for_display(
			array_values(
				array_filter(
					$context['service_snapshots'],
					function ( $snapshot ) use ( $subscriptions ) {
						return $this->is_displayable_customer_portal_snapshot( $snapshot ) && ! $this->recurring_snapshot_is_represented_by_active_subscription( $snapshot, $subscriptions );
					}
				)
			)
		);
		$refunded_refs          = $this->get_portal_refunded_payment_refs( $context['stripe_customer_id'] );
		$snapshot_services      = array();
		$past_snapshot_services = array();
		foreach ( $displayable_snapshot_services as $snapshot ) {
			if ( $this->portal_snapshot_payment_was_refunded( $snapshot, $refunded_refs ) ) {
				$snapshot->status         = 'refunded';
				$past_snapshot_services[] = $snapshot;
				continue;
			}
			if ( $this->is_current_portal_service_snapshot( $snapshot ) ) {
				$snapshot_services[] = $snapshot;
			} else {
				$past_snapshot_services[] = $snapshot;
			}
		}
		$ledger             = $context['ledger'];
		$service_settings   = get_option( 'ajcore_customer_portal_services_display', array() );
		$service_settings   = is_array( $service_settings ) ? $service_settings : array();
		$show_current       = ! isset( $service_settings['show_current_services'] ) || (bool) $service_settings['show_current_services'];
		$show_add           = ! isset( $service_settings['show_add_services'] ) || (bool) $service_settings['show_add_services'];
		$available_products = $show_add ? $this->get_portal_available_service_products( $subscriptions, $ledger ) : array();
		$available_product_groups = $show_add ? $this->get_portal_add_service_product_groups( $available_products ) : array();
		$custom_request_products = $show_add ? $this->get_portal_custom_request_products( $subscriptions, $ledger ) : array();
		$business_name      = $this->get_portal_customer_meta_value( $customer, array( 'business_name', 'business', 'company', 'company_name', 'description' ) );
		$stripe_settings    = $this->get_stripe_settings();

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'My Services', 'ajforms' ); ?></h2>

			<?php if ( $show_current ) : ?>
			<h3><?php esc_html_e( 'Current Services', 'ajforms' ); ?></h3>
			<?php if ( empty( $snapshot_services ) && empty( $current_services ) ) : ?>
				<p><?php esc_html_e( 'No current services are synced yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-services-list">
					<?php foreach ( $current_services as $subscription ) : ?>
						<?php $subscription_ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger ); ?>
						<div class="aj-portal-service-card">
							<h4><?php echo esc_html( $this->get_subscription_service_name( $subscription, $subscription_ledger_entry ) ); ?></h4>
							<?php $subscription_ref = $this->get_portal_subscription_reference_label( $subscription ); ?>
							<?php if ( $subscription_ref ) : ?><p class="aj-portal-service-ref"><?php echo esc_html( $subscription_ref ); ?></p><?php endif; ?>
							<div class="aj-portal-service-card-grid">
								<div><strong><?php esc_html_e( 'Business Name', 'ajforms' ); ?></strong><span><?php echo esc_html( $business_name ? $business_name : '-' ); ?></span></div>
								<div><strong><?php esc_html_e( 'Status', 'ajforms' ); ?></strong><span><?php echo esc_html( ucfirst( $subscription->status ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Service Period', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_service_period( $subscription, $subscription_ledger_entry ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_next_billing_date( $subscription, $subscription_ledger_entry ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Amount', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_amount_label( $subscription ) ); ?></span></div>
							</div>
						</div>
					<?php endforeach; ?>
					<?php foreach ( $snapshot_services as $snapshot ) : ?>
						<div class="aj-portal-service-card">
							<h4><?php echo esc_html( $this->get_snapshot_service_name( $snapshot ) ); ?></h4>
							<?php $snapshot_ref = $this->get_snapshot_reference_label( $snapshot ); ?>
							<?php if ( $snapshot_ref ) : ?><p class="aj-portal-service-ref"><?php echo esc_html( $snapshot_ref ); ?></p><?php endif; ?>
							<?php $snapshot_is_recurring = 'recurring' === $this->get_snapshot_billing_type_key( $snapshot ); ?>
							<div class="aj-portal-service-card-grid">
								<div><strong><?php esc_html_e( 'Business Name', 'ajforms' ); ?></strong><span><?php echo esc_html( $business_name ? $business_name : '-' ); ?></span></div>
								<div><strong><?php esc_html_e( 'Status', 'ajforms' ); ?></strong><span><?php echo esc_html( ucfirst( (string) $snapshot->status ) ); ?></span></div>
								<?php if ( $snapshot_is_recurring ) : ?>
									<div><strong><?php esc_html_e( 'Service Period', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_snapshot_service_period_label( $snapshot ) ); ?></span></div>
									<div><strong><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_snapshot_next_billing_date_label( $snapshot ) ); ?></span></div>
								<?php endif; ?>
								<div><strong><?php esc_html_e( 'Amount', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_snapshot_service_amount_label( $snapshot ) ); ?></span></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $past_services ) || ! empty( $past_snapshot_services ) ) : ?>
				<h3><?php esc_html_e( 'Past Services', 'ajforms' ); ?></h3>
				<div class="aj-portal-services-list">
					<?php foreach ( $past_services as $subscription ) : ?>
						<?php $subscription_ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger ); ?>
						<div class="aj-portal-service-card aj-portal-service-card-past">
							<h4><?php echo esc_html( $this->get_subscription_service_name( $subscription, $subscription_ledger_entry ) ); ?></h4>
							<?php $subscription_ref = $this->get_portal_subscription_reference_label( $subscription ); ?>
							<?php if ( $subscription_ref ) : ?><p class="aj-portal-service-ref"><?php echo esc_html( $subscription_ref ); ?></p><?php endif; ?>
							<div class="aj-portal-service-card-grid">
								<div><strong><?php esc_html_e( 'Business Name', 'ajforms' ); ?></strong><span><?php echo esc_html( $business_name ? $business_name : '-' ); ?></span></div>
								<div><strong><?php esc_html_e( 'Status', 'ajforms' ); ?></strong><span><?php echo esc_html( ucfirst( $subscription->status ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Service Period', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_service_period( $subscription, $subscription_ledger_entry ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></strong><span><?php esc_html_e( 'No future billing', 'ajforms' ); ?></span></div>
								<div><strong><?php esc_html_e( 'Amount', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_amount_label( $subscription ) ); ?></span></div>
							</div>
						</div>
					<?php endforeach; ?>
					<?php foreach ( $past_snapshot_services as $snapshot ) : ?>
						<div class="aj-portal-service-card aj-portal-service-card-past">
							<h4><?php echo esc_html( $this->get_snapshot_service_name( $snapshot ) ); ?></h4>
							<?php $snapshot_ref = $this->get_snapshot_reference_label( $snapshot ); ?>
							<?php if ( $snapshot_ref ) : ?><p class="aj-portal-service-ref"><?php echo esc_html( $snapshot_ref ); ?></p><?php endif; ?>
							<?php $snapshot_is_recurring = 'recurring' === $this->get_snapshot_billing_type_key( $snapshot ); ?>
							<div class="aj-portal-service-card-grid">
								<div><strong><?php esc_html_e( 'Business Name', 'ajforms' ); ?></strong><span><?php echo esc_html( $business_name ? $business_name : '-' ); ?></span></div>
								<div><strong><?php esc_html_e( 'Status', 'ajforms' ); ?></strong><span><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $snapshot->status ) ) ); ?></span></div>
								<?php if ( $snapshot_is_recurring ) : ?>
									<div><strong><?php esc_html_e( 'Service Period', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_snapshot_service_period_label( $snapshot ) ); ?></span></div>
									<div><strong><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></strong><span><?php esc_html_e( 'No future billing', 'ajforms' ); ?></span></div>
								<?php endif; ?>
								<div><strong><?php esc_html_e( 'Amount', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_snapshot_service_amount_label( $snapshot ) ); ?></span></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ( $show_add ) : ?>
			<h3><?php esc_html_e( 'Add Services', 'ajforms' ); ?></h3>
			<?php if ( ! empty( $custom_request_products ) ) : ?>
				<div class="aj-portal-add-service-grid aj-portal-custom-request-grid">
					<?php foreach ( $custom_request_products as $product ) : ?>
						<?php
						$product_name = ! empty( $product->custom_label ) ? $product->custom_label : $product->name;
						$price_id     = sanitize_text_field( (string) $product->stripe_price_id );
						$request_title = ! empty( $product->custom_request_title ) ? $product->custom_request_title : sprintf( __( 'Need another %s?', 'ajforms' ), $product_name );
						$request_message = ! empty( $product->custom_request_message ) ? $product->custom_request_message : __( 'Request custom pricing and our team will review your account.', 'ajforms' );
						$request_button = ! empty( $product->custom_request_button_label ) ? $product->custom_request_button_label : __( 'Request Custom Pricing', 'ajforms' );
						$existing_request = $this->get_open_custom_service_request( $context['stripe_customer_id'], $price_id );
						?>
						<div class="aj-portal-add-service-card aj-portal-custom-request-card">
							<h4><?php echo esc_html( $request_title ); ?></h4>
							<p><?php echo esc_html( $request_message ); ?></p>
							<?php if ( $existing_request ) : ?>
								<div class="aj-portal-add-service-price"><?php esc_html_e( 'Under Review', 'ajforms' ); ?></div>
								<button type="button" class="button" disabled><?php esc_html_e( 'Request Submitted', 'ajforms' ); ?></button>
							<?php else : ?>
								<button
									type="button"
									class="button aj-portal-custom-service-request-button"
									data-price-id="<?php echo esc_attr( $price_id ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_portal_custom_request_' . $price_id ) ); ?>"
								><?php echo esc_html( $request_button ); ?></button>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( empty( $available_product_groups ) ) : ?>
				<?php if ( empty( $custom_request_products ) ) : ?>
					<p><?php esc_html_e( 'No additional services are currently available for this account.', 'ajforms' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<div
					class="aj-portal-service-cart"
					data-cart-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_cart_checkout' ) ); ?>"
					data-publishable-key="<?php echo esc_attr( isset( $stripe_settings['publishable_key'] ) ? $stripe_settings['publishable_key'] : '' ); ?>"
				>
						<div class="aj-portal-service-cart-bar" aria-live="polite">
							<button type="button" class="aj-portal-service-cart-toggle">
								<span><?php esc_html_e( 'Cart', 'ajforms' ); ?></span>
								<strong class="aj-portal-service-cart-count">0</strong>
						</button>
						<div class="aj-portal-service-cart-status"><?php esc_html_e( 'No services selected', 'ajforms' ); ?></div>
							<button type="button" class="aj-portal-service-cart-clear" disabled><?php esc_html_e( 'Clear', 'ajforms' ); ?></button>
							<button type="button" class="aj-portal-service-cart-checkout" disabled><?php esc_html_e( 'Checkout', 'ajforms' ); ?></button>
						</div>
						<div class="aj-portal-service-cart-notice" hidden></div>
						<div class="aj-portal-service-cart-panel" hidden>
						<div class="aj-portal-service-cart-items"></div>
						<div class="aj-portal-service-cart-empty"><?php esc_html_e( 'No services selected yet.', 'ajforms' ); ?></div>
						<div class="aj-portal-service-cart-total"></div>
					</div>
					<div class="aj-portal-service-checkout-panel" hidden>
						<div class="aj-portal-service-checkout-header">
							<h4><?php esc_html_e( 'Secure checkout', 'ajforms' ); ?></h4>
							<button type="button" class="aj-portal-service-checkout-close"><?php esc_html_e( 'Close', 'ajforms' ); ?></button>
						</div>
						<div class="aj-portal-service-checkout-mount"></div>
					</div>
				</div>
				<div class="aj-portal-add-service-grid">
					<?php foreach ( $available_product_groups as $group ) : ?>
						<?php
						$prices              = isset( $group['prices'] ) && is_array( $group['prices'] ) ? array_values( $group['prices'] ) : array();
						$first_price         = ! empty( $prices ) ? $prices[0] : array();
						$product_name        = isset( $group['name'] ) ? $group['name'] : __( 'Service', 'ajforms' );
						$product_description = isset( $group['description'] ) ? $group['description'] : '';
						$can_add             = ! empty( $first_price['can_add'] );
						?>
						<div
							class="aj-portal-add-service-card aj-portal-add-service-product"
							data-product-id="<?php echo esc_attr( isset( $group['product_id'] ) ? $group['product_id'] : '' ); ?>"
						>
							<h4><?php echo esc_html( $product_name ); ?></h4>
							<?php if ( $product_description ) : ?>
								<p><?php echo esc_html( wp_trim_words( $product_description, 28 ) ); ?></p>
							<?php endif; ?>
							<?php if ( count( $prices ) > 1 ) : ?>
								<label class="aj-portal-add-service-price-choice">
									<span><?php esc_html_e( 'Choose option', 'ajforms' ); ?></span>
									<select class="aj-portal-add-service-price-select">
										<?php foreach ( $prices as $price ) : ?>
											<option
												value="<?php echo esc_attr( $price['price_id'] ); ?>"
												data-product-name="<?php echo esc_attr( $price['name'] ); ?>"
												data-amount="<?php echo esc_attr( $price['amount'] ); ?>"
												data-currency="<?php echo esc_attr( $price['currency'] ); ?>"
												data-recurring-interval="<?php echo esc_attr( $price['recurring_interval'] ); ?>"
												data-price-label="<?php echo esc_attr( $price['price_label'] ); ?>"
												data-can-add="<?php echo esc_attr( $price['can_add'] ); ?>"
												data-button-label="<?php echo esc_attr( $price['button_label'] ); ?>"
												data-required-price-id="<?php echo esc_attr( $price['required_price_id'] ); ?>"
												data-required-product-name="<?php echo esc_attr( $price['required_product_name'] ); ?>"
												data-dependency-note="<?php echo esc_attr( $price['dependency_note'] ); ?>"
												data-required-amount="<?php echo esc_attr( $price['required_amount'] ); ?>"
												data-required-currency="<?php echo esc_attr( $price['required_currency'] ); ?>"
												data-required-recurring-interval="<?php echo esc_attr( $price['required_interval'] ); ?>"
												data-required-price-label="<?php echo esc_attr( $price['required_price_label'] ); ?>"
											><?php echo esc_html( $price['price_label'] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							<?php else : ?>
								<input
									type="hidden"
									class="aj-portal-add-service-price-select"
									value="<?php echo esc_attr( isset( $first_price['price_id'] ) ? $first_price['price_id'] : '' ); ?>"
									data-product-name="<?php echo esc_attr( isset( $first_price['name'] ) ? $first_price['name'] : $product_name ); ?>"
									data-amount="<?php echo esc_attr( isset( $first_price['amount'] ) ? $first_price['amount'] : 0 ); ?>"
									data-currency="<?php echo esc_attr( isset( $first_price['currency'] ) ? $first_price['currency'] : 'usd' ); ?>"
									data-recurring-interval="<?php echo esc_attr( isset( $first_price['recurring_interval'] ) ? $first_price['recurring_interval'] : '' ); ?>"
									data-price-label="<?php echo esc_attr( isset( $first_price['price_label'] ) ? $first_price['price_label'] : '' ); ?>"
									data-can-add="<?php echo esc_attr( isset( $first_price['can_add'] ) ? $first_price['can_add'] : 0 ); ?>"
									data-button-label="<?php echo esc_attr( isset( $first_price['button_label'] ) ? $first_price['button_label'] : __( 'Add to My Services', 'ajforms' ) ); ?>"
									data-required-price-id="<?php echo esc_attr( isset( $first_price['required_price_id'] ) ? $first_price['required_price_id'] : '' ); ?>"
									data-required-product-name="<?php echo esc_attr( isset( $first_price['required_product_name'] ) ? $first_price['required_product_name'] : '' ); ?>"
									data-dependency-note="<?php echo esc_attr( isset( $first_price['dependency_note'] ) ? $first_price['dependency_note'] : '' ); ?>"
									data-required-amount="<?php echo esc_attr( isset( $first_price['required_amount'] ) ? $first_price['required_amount'] : 0 ); ?>"
									data-required-currency="<?php echo esc_attr( isset( $first_price['required_currency'] ) ? $first_price['required_currency'] : 'usd' ); ?>"
									data-required-recurring-interval="<?php echo esc_attr( isset( $first_price['required_interval'] ) ? $first_price['required_interval'] : '' ); ?>"
									data-required-price-label="<?php echo esc_attr( isset( $first_price['required_price_label'] ) ? $first_price['required_price_label'] : '' ); ?>"
								>
							<?php endif; ?>
							<div class="aj-portal-add-service-price"><?php echo esc_html( isset( $first_price['price_label'] ) ? $first_price['price_label'] : '' ); ?></div>
							<div class="aj-portal-add-service-requires" <?php echo empty( $first_price['required_product_name'] ) ? 'hidden' : ''; ?>>
								<?php
								echo esc_html(
									! empty( $first_price['required_product_name'] )
										? sprintf( __( 'Requires another product: %s', 'ajforms' ), $first_price['required_product_name'] )
										: ''
								);
								?>
							</div>
							<div class="aj-portal-add-service-dependency-note" <?php echo empty( $first_price['dependency_note'] ) ? 'hidden' : ''; ?>><?php echo esc_html( isset( $first_price['dependency_note'] ) ? $first_price['dependency_note'] : '' ); ?></div>
							<button type="button" class="button aj-portal-add-service-button" <?php disabled( ! $can_add ); ?>><?php echo esc_html( isset( $first_price['button_label'] ) ? $first_price['button_label'] : __( 'Add to My Services', 'ajforms' ) ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p class="aj-portal-add-service-message" style="display:none;"></p>
			<?php endif; ?>

			<?php
			$res_settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
			$res_enabled    = ! empty( $res_settings['zoho_reservations_enabled'] );
			$res_name       = ! empty( $res_settings['reservation_resource_name'] ) ? $res_settings['reservation_resource_name'] : __( 'Conference Room', 'ajforms' );
			if ( $res_enabled ) :
				$reservations_url = add_query_arg( array( 'portal_tab' => 'reservations' ), $this->get_customer_portal_url() );
			?>
			<h3><?php esc_html_e( 'Resource Booking', 'ajforms' ); ?></h3>
			<div class="aj-portal-add-service-grid">
				<div class="aj-portal-add-service-card aj-portal-reservation-tile">
					<h4><?php echo esc_html( $res_name ); ?></h4>
					<p><?php esc_html_e( 'Reserve this resource for your next session. Check availability and book by the hour.', 'ajforms' ); ?></p>
					<a href="<?php echo esc_url( $reservations_url ); ?>" class="button"><?php esc_html_e( 'View &amp; Book', 'ajforms' ); ?></a>
				</div>
			</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function get_portal_open_ledger_statuses() {
		return array( 'open', 'unpaid', 'pending', 'pending_payment', 'awaiting_payment' );
	}

	private function get_ignored_unpaid_checkout_sources() {
		return array(
			'ajcore_portal_balance_payment',
			'ajcore_products_cart',
			'ajcore_portal_add_service',
			'ajcore_mixed_cart_subscription',
			'ajcore_portal_mixed_cart_subscription',
		);
	}

	private function get_ignored_unpaid_checkout_sql_fragment( $table_alias = '' ) {
		$prefix = $table_alias ? sanitize_key( (string) $table_alias ) . '.' : '';

		return "NOT ({$prefix}source_type = 'checkout_session' AND {$prefix}status IN ('unpaid','open','pending','pending_payment','requires_payment_method') AND ({$prefix}description IN ('ajcore_portal_balance_payment','ajcore_products_cart','ajcore_portal_add_service','ajcore_mixed_cart_subscription','ajcore_portal_mixed_cart_subscription') OR {$prefix}metadata LIKE '%%ajcore_portal_balance_payment%%' OR {$prefix}metadata LIKE '%%ajcore_products_cart%%' OR {$prefix}metadata LIKE '%%ajcore_portal_add_service%%' OR {$prefix}metadata LIKE '%%ajcore_mixed_cart_subscription%%' OR {$prefix}metadata LIKE '%%ajcore_portal_mixed_cart_subscription%%'))";
	}

	private function get_current_user_open_portal_ledger( $ledger_ids = array() ) {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array();
		}

		$pdb = $this->get_pdb();

		$statuses = $this->get_portal_open_ledger_statuses();
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params = array_merge( array( $stripe_customer_id ), $statuses );
		$where = "stripe_customer_id = %s AND amount > 0 AND status IN ({$status_placeholders}) AND " . $this->get_ignored_unpaid_checkout_sql_fragment() . "";

		$ledger_ids = array_values( array_filter( array_map( 'absint', (array) $ledger_ids ) ) );
		if ( ! empty( $ledger_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $ledger_ids ), '%d' ) );
			$where .= " AND id IN ({$id_placeholders})";
			$params = array_merge( $params, $ledger_ids );
		}

		$sql = "SELECT * FROM {$this->get_portal_ledger_table()} WHERE {$where} ORDER BY ledger_date ASC, id ASC";

		return $pdb->get_results( $pdb->prepare( $sql, $params ) );
	}

	private function get_portal_open_ledger_total( $entries ) {
		$total = 0;
		$currency = '';

		foreach ( (array) $entries as $entry ) {
			$total += (float) $entry->amount;
			if ( '' === $currency && ! empty( $entry->currency ) ) {
				$currency = sanitize_key( (string) $entry->currency );
			}
		}

		return array(
			'amount'   => $total,
			'currency' => $currency ? $currency : 'usd',
		);
	}

	private function get_portal_pay_ledger_nonce() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();

		return wp_create_nonce( 'ajcore_pay_portal_ledger_' . $stripe_customer_id );
	}

	private function render_customer_portal_billing_tab() {
		$context = $this->get_current_user_portal_billing_context();

		if ( '' === $context['stripe_customer_id'] || ! $context['customer'] ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Billing', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$ledger        = $context['ledger'];
		$upcoming      = $context['upcoming'];
		$subscriptions = $context['subscriptions'];
		$open_ledger   = $this->get_current_user_open_portal_ledger();
		$open_total    = $this->get_portal_open_ledger_total( $open_ledger );
		$pay_nonce     = $this->get_portal_pay_ledger_nonce();
		$balance_data  = $this->get_portal_ledger_running_balances( $ledger );
		$running_balances = $balance_data['balances'];
		$final_balance = (float) $balance_data['total'];
		$balance_currency = ! empty( $open_total['currency'] ) ? $open_total['currency'] : ( ! empty( $ledger[0]->currency ) ? sanitize_key( (string) $ledger[0]->currency ) : 'usd' );
		$balance_due      = max( 0, (float) $final_balance );

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Billing', 'ajforms' ); ?></h2>

			<div class="aj-portal-open-balance" style="margin:0 0 24px;padding:20px;border:1px solid #dbeafe;border-radius:22px;background:#eff6ff;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
				<div>
					<h3 style="margin:0 0 6px;"><?php esc_html_e( 'Open Balance', 'ajforms' ); ?></h3>
					<div style="font-size:24px;font-weight:900;color:#0f172a;"><?php echo esc_html( $this->format_portal_money( $balance_due, $balance_currency ) ); ?></div>
					<p style="margin:6px 0 0;color:#475569;"><?php esc_html_e( 'Make a payment or pay your current balance in one checkout.', 'ajforms' ); ?></p>
				</div>
				<div class="aj-portal-payment-box" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;justify-content:flex-end;">
					<label style="display:flex;flex-direction:column;gap:6px;font-weight:700;color:#334155;min-width:170px;">
						<span><?php esc_html_e( 'Payment Amount', 'ajforms' ); ?></span>
						<input type="number" class="aj-portal-payment-amount-input" min="0.01" step="0.01" inputmode="decimal" value="<?php echo esc_attr( $balance_due > 0 ? number_format( $balance_due, 2, '.', '' ) : '' ); ?>" placeholder="0.00" style="width:170px;border:1px solid #bfdbfe;border-radius:14px;padding:10px 12px;font-weight:800;background:#fff;">
					</label>
					<button type="button" class="button aj-portal-pay-ledger-button" data-ledger-ids="<?php echo esc_attr( $balance_due > 0 ? 'all' : '' ); ?>" data-payment-mode="<?php echo esc_attr( $balance_due > 0 ? 'balance' : 'custom' ); ?>" data-payment-amount="<?php echo esc_attr( $balance_due > 0 ? number_format( $balance_due, 2, '.', '' ) : '' ); ?>" data-payment-currency="<?php echo esc_attr( $balance_currency ); ?>" data-nonce="<?php echo esc_attr( $pay_nonce ); ?>"><?php esc_html_e( 'Make a Payment', 'ajforms' ); ?></button>
				</div>
			</div>

			<div class="aj-portal-balance-summary" style="margin:0 0 24px;padding:16px 18px;border:1px solid #e2e8f0;border-radius:18px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
				<div>
					<strong><?php esc_html_e( 'Final Ledger Balance', 'ajforms' ); ?></strong>
					<p style="margin:4px 0 0;color:#64748b;"><?php esc_html_e( 'Running total after all visible billing history rows.', 'ajforms' ); ?></p>
				</div>
				<div style="font-size:20px;font-weight:900;color:#0f172a;"><?php echo esc_html( $this->format_portal_balance_amount( $final_balance, $balance_currency ) ); ?></div>
			</div>

			<h3><?php esc_html_e( 'Upcoming Payments', 'ajforms' ); ?></h3>
			<?php if ( empty( $upcoming ) ) : ?>
				<p><?php esc_html_e( 'No upcoming payment is currently scheduled.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-table-wrap">
					<table class="aj-portal-table">
						<thead><tr><th><?php esc_html_e( 'Service Name', 'ajforms' ); ?></th><th><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $upcoming as $subscription ) : ?>
								<?php $subscription_ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger ); ?>
								<tr>
									<td><?php echo esc_html( $this->get_subscription_service_name( $subscription, $subscription_ledger_entry ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Next Billing Date', 'ajforms' ); ?>"><?php echo esc_html( $this->get_subscription_next_billing_date( $subscription, $subscription_ledger_entry ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Amount', 'ajforms' ); ?>"><?php echo esc_html( $this->get_subscription_amount_label( $subscription ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Billing History', 'ajforms' ); ?></h3>
			<?php if ( empty( $ledger ) ) : ?>
				<p><?php esc_html_e( 'No Stripe invoices or charges are synced yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-table-wrap">
					<table class="aj-portal-table">
						<thead><tr><th><?php esc_html_e( 'Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Description', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Debit', 'ajforms' ); ?></th><th><?php esc_html_e( 'Credit', 'ajforms' ); ?></th><th><?php esc_html_e( 'Running Balance', 'ajforms' ); ?></th><th><?php esc_html_e( 'Invoice', 'ajforms' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $ledger as $entry ) : ?>
								<?php $entry_invoice_url = $this->get_ledger_metadata_value( $entry, 'invoice_pdf' ); ?>
								<?php $entry_invoice_label = $this->get_ledger_metadata_value( $entry, 'invoice_number' ) ? $this->get_ledger_metadata_value( $entry, 'invoice_number' ) : __( 'PDF', 'ajforms' ); ?>
								<?php $entry_invoice_id = ! empty( $entry->invoice_id ) ? sanitize_text_field( (string) $entry->invoice_id ) : $this->get_ledger_metadata_value( $entry, 'invoice_id' ); ?>
								<?php $entry_client_note = $this->get_ledger_metadata_value( $entry, 'client_notes' ); ?>
								<?php $entry_display_description = $this->get_portal_ledger_display_description( $entry ); ?>
								<?php $entry_is_open = ( $balance_due > 0 && (float) $entry->amount > 0 && in_array( sanitize_key( (string) $entry->status ), $this->get_portal_open_ledger_statuses(), true ) ); ?>
								<?php $entry_debit_credit = $this->get_portal_ledger_debit_credit( $entry ); ?>
								<?php $entry_actions_html = ( ! $entry_invoice_url && ! $entry_invoice_id ) ? $this->get_portal_service_request_actions( $entry ) : ''; ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Date', 'ajforms' ); ?>"><?php echo esc_html( $entry->ledger_date ? $this->format_portal_date( $entry->ledger_date ) : '-' ); ?></td>
									<td><?php echo esc_html( $entry_display_description ); ?><?php if ( $entry_client_note ) : ?><br><small><?php echo esc_html( $entry_client_note ); ?></small><?php endif; ?></td>
									<td data-label="<?php esc_attr_e( 'Status', 'ajforms' ); ?>"><?php echo esc_html( 'admin_review_required' === $entry->status ? __( 'Under Review', 'ajforms' ) : ucwords( str_replace( '_', ' ', $entry->status ) ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Debit', 'ajforms' ); ?>" class="<?php echo $entry_debit_credit['debit'] ? '' : 'aj-portal-td-empty'; ?>"><?php echo esc_html( $entry_debit_credit['debit'] ? $entry_debit_credit['debit'] : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Credit', 'ajforms' ); ?>" class="<?php echo $entry_debit_credit['credit'] ? '' : 'aj-portal-td-empty'; ?>"><?php echo esc_html( $entry_debit_credit['credit'] ? $entry_debit_credit['credit'] : '-' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Running Balance', 'ajforms' ); ?>"><?php echo esc_html( $this->format_portal_balance_amount( isset( $running_balances[ (int) $entry->id ] ) ? $running_balances[ (int) $entry->id ] : 0, $entry->currency ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Invoice', 'ajforms' ); ?>" class="<?php echo ( $entry_invoice_url || $entry_invoice_id || '' !== $entry_actions_html ) ? '' : 'aj-portal-td-empty'; ?>">
										<?php if ( $entry_invoice_url ) : ?>
											<a href="<?php echo esc_url( $entry_invoice_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry_invoice_label ); ?></a>
										<?php elseif ( $entry_invoice_id ) : ?>
											<?php echo esc_html( $entry_invoice_label && 'PDF' !== $entry_invoice_label ? $entry_invoice_label : __( 'Invoice', 'ajforms' ) ); ?>
										<?php else : ?>
											<?php echo $entry_actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}


	private function get_current_user_portal_tasks() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array();
		}

		$pdb = $this->get_pdb();

		return $pdb->get_results(
			$pdb->prepare(
				"SELECT t.*, COALESCE(ts.status, t.status) AS portal_status, ts.completed_at AS portal_completed_at, ts.updated_at AS portal_status_updated_at
				FROM {$this->get_portal_tasks_table()} t
				LEFT JOIN {$this->get_portal_task_statuses_table()} ts ON ts.task_id = t.id AND ts.stripe_customer_id = %s
				WHERE t.client_visible = 1
				AND (t.task_scope = 'global' OR t.stripe_customer_id = %s)
				ORDER BY FIELD(COALESCE(ts.status, t.status), 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled'), t.due_date IS NULL, t.due_date ASC, t.id DESC",
				$stripe_customer_id,
				$stripe_customer_id
			)
		);
	}

	private function get_open_portal_tasks_count( $tasks ) {
		$count = 0;
		foreach ( (array) $tasks as $task ) {
			$status = isset( $task->portal_status ) && '' !== (string) $task->portal_status ? sanitize_key( (string) $task->portal_status ) : ( isset( $task->status ) ? sanitize_key( (string) $task->status ) : '' );
			if ( ! in_array( $status, array( 'completed', 'cancelled', 'closed' ), true ) ) {
				$count++;
			}
		}

		return $count;
	}

	private function get_portal_task_comments_by_task_ids( $task_ids, $stripe_customer_id ) {
		$task_ids = array_values( array_filter( array_map( 'absint', (array) $task_ids ) ) );
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );

		if ( empty( $task_ids ) || '' === $stripe_customer_id ) {
			return array();
		}

		$pdb          = $this->get_pdb();
		$placeholders = implode( ',', array_fill( 0, count( $task_ids ), '%d' ) );
		$params       = array_merge( $task_ids, array( $stripe_customer_id ) );
		$comments     = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_task_comments_table()} WHERE task_id IN ({$placeholders}) AND stripe_customer_id = %s ORDER BY created_at ASC, id ASC",
				$params
			)
		);

		$grouped = array();
		foreach ( $comments as $comment ) {
			$task_id = isset( $comment->task_id ) ? absint( $comment->task_id ) : 0;
			if ( ! isset( $grouped[ $task_id ] ) ) {
				$grouped[ $task_id ] = array();
			}
			$grouped[ $task_id ][] = $comment;
		}

		return $grouped;
	}

	private function user_can_access_portal_task( $task_id, $stripe_customer_id ) {
		$pdb = $this->get_pdb();

		$task_id            = absint( $task_id );
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );

		if ( ! $task_id || '' === $stripe_customer_id ) {
			return false;
		}

		$task = $pdb->get_row(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_tasks_table()} WHERE id = %d AND client_visible = 1 AND (task_scope = 'global' OR stripe_customer_id = %s) LIMIT 1",
				$task_id,
				$stripe_customer_id
			)
		);

		return $task ? $task : false;
	}

	public function maybe_handle_portal_task_action() {
		if ( empty( $_POST['ajcore_portal_task_action'] ) || ! is_user_logged_in() ) {
			return;
		}

		$task_id = isset( $_POST['portal_task_id'] ) ? absint( wp_unslash( $_POST['portal_task_id'] ) ) : 0;
		$action  = sanitize_key( wp_unslash( $_POST['ajcore_portal_task_action'] ) );

		if ( ! $task_id || ! in_array( $action, array( 'add_comment', 'mark_complete' ), true ) ) {
			return;
		}

		check_admin_referer( 'ajcore_portal_task_action_' . $task_id, 'ajcore_portal_task_nonce' );

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$task               = $this->user_can_access_portal_task( $task_id, $stripe_customer_id );
		if ( ! $task ) {
			wp_safe_redirect( add_query_arg( 'portal_tab', 'tasks', $this->get_customer_portal_url() ) );
			exit;
		}

		$pdb     = $this->get_pdb();
		$user_id = get_current_user_id();
		$comment = isset( $_POST['portal_task_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['portal_task_comment'] ) ) : '';

		if ( 'mark_complete' === $action ) {
			$existing_status_id = $pdb->get_var(
				$pdb->prepare(
					"SELECT id FROM {$this->get_portal_task_statuses_table()} WHERE task_id = %d AND stripe_customer_id = %s LIMIT 1",
					$task_id,
					$stripe_customer_id
				)
			);

			$status_data = array(
				'task_id'            => $task_id,
				'stripe_customer_id' => $stripe_customer_id,
				'status'             => 'completed',
				'completed_at'       => current_time( 'mysql' ),
				'updated_by'         => $user_id,
			);
			$status_formats = array( '%d', '%s', '%s', '%s', '%d' );

			if ( $existing_status_id ) {
				$pdb->update( $this->get_portal_task_statuses_table(), $status_data, array( 'id' => absint( $existing_status_id ) ), $status_formats, array( '%d' ) );
			} else {
				$pdb->insert( $this->get_portal_task_statuses_table(), $status_data, $status_formats );
			}

			$auto_note = __( 'Marked complete by customer.', 'ajforms' );
			$comment   = '' !== $comment ? $auto_note . "\n\n" . $comment : $auto_note;
		}

		if ( '' !== $comment ) {
			$pdb->insert(
				$this->get_portal_task_comments_table(),
				array(
					'task_id'            => $task_id,
					'stripe_customer_id' => $stripe_customer_id,
					'comment'            => $comment,
					'is_client'          => 1,
					'created_by'         => $user_id,
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%s' )
			);
		}

		wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'tasks', 'task-updated' => '1' ), $this->get_customer_portal_url() ) );
		exit;
	}

	private function render_customer_portal_tasks_table( $tasks, $include_default_deadlines = true ) {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$task_ids           = wp_list_pluck( (array) $tasks, 'id' );
		$comments_by_task   = $this->get_portal_task_comments_by_task_ids( $task_ids, $stripe_customer_id );

		ob_start();
		?>
		<div class="aj-portal-table-wrap aj-portal-tasks-wrap">
			<table class="aj-portal-table aj-portal-tasks-table">
				<thead><tr><th><?php esc_html_e( 'Task', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Due Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Action Required', 'ajforms' ); ?></th><th><?php esc_html_e( 'Comments / Update', 'ajforms' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $tasks ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No open tasks are available yet.', 'ajforms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $tasks as $task ) : ?>
							<?php
							$task_id      = absint( $task->id );
							$status       = isset( $task->portal_status ) && '' !== (string) $task->portal_status ? sanitize_key( (string) $task->portal_status ) : sanitize_key( (string) $task->status );
							$is_complete  = in_array( $status, array( 'completed', 'cancelled', 'closed' ), true );
							$scope_label  = isset( $task->task_scope ) && 'global' === $task->task_scope ? __( 'All clients', 'ajforms' ) : __( 'Client-specific', 'ajforms' );
							$freq_label   = isset( $task->task_frequency ) && 'recurring' === $task->task_frequency ? __( 'Recurring', 'ajforms' ) : __( 'One-time', 'ajforms' );
							$comments     = isset( $comments_by_task[ $task_id ] ) ? $comments_by_task[ $task_id ] : array();
							?>
							<tr class="aj-portal-task-row is-<?php echo esc_attr( $status ); ?>">
								<td>
									<strong><?php echo esc_html( $task->title ); ?></strong>
									<div class="aj-portal-task-meta"><?php echo esc_html( $scope_label . ' · ' . $freq_label ); ?></div>
								</td>
								<td data-label="<?php esc_attr_e( 'Status', 'ajforms' ); ?>"><span class="aj-portal-task-status aj-portal-task-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $status ) ) ); ?></span></td>
								<td data-label="<?php esc_attr_e( 'Due Date', 'ajforms' ); ?>" class="<?php echo ! empty( $task->due_date ) ? '' : 'aj-portal-td-empty'; ?>"><?php echo esc_html( ! empty( $task->due_date ) ? $this->format_portal_date( $task->due_date ) : '-' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Action Required', 'ajforms' ); ?>" class="<?php echo ! empty( $task->action_required ) ? '' : 'aj-portal-td-empty'; ?>"><?php echo esc_html( ! empty( $task->action_required ) ? $task->action_required : '-' ); ?></td>
								<td>
									<?php if ( ! empty( $comments ) ) : ?>
										<div class="aj-portal-task-comments">
											<?php foreach ( $comments as $comment ) : ?>
												<div class="aj-portal-task-comment">
													<?php echo esc_html( $comment->comment ); ?>
													<span><?php echo esc_html( $this->format_portal_date( $comment->created_at ) ); ?></span>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>

									<details class="aj-portal-task-drawer">
										<summary><?php echo $is_complete ? esc_html__( 'Add Comment', 'ajforms' ) : esc_html__( 'Add Comment / Mark Complete', 'ajforms' ); ?></summary>
										<form class="aj-portal-task-form" method="post">
											<?php wp_nonce_field( 'ajcore_portal_task_action_' . $task_id, 'ajcore_portal_task_nonce' ); ?>
											<input type="hidden" name="portal_task_id" value="<?php echo esc_attr( $task_id ); ?>">
											<textarea name="portal_task_comment" rows="2" placeholder="<?php esc_attr_e( 'Add a comment for our team...', 'ajforms' ); ?>"></textarea>
											<div class="aj-portal-task-actions">
												<button type="submit" class="button aj-portal-task-comment-button" name="ajcore_portal_task_action" value="add_comment"><?php esc_html_e( 'Add Comment', 'ajforms' ); ?></button>
												<?php if ( ! $is_complete ) : ?>
													<button type="submit" class="button aj-portal-task-complete-button" name="ajcore_portal_task_action" value="mark_complete"><?php esc_html_e( 'Mark Complete', 'ajforms' ); ?></button>
												<?php endif; ?>
											</div>
										</form>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}


	private function render_customer_portal_service_summary( $active_subscriptions, $ledger, $business_name ) {
		if ( empty( $active_subscriptions ) ) {
			return '<div class="aj-portal-account-summary"><h3>' . esc_html__( 'Account Summary', 'ajforms' ) . '</h3><p>' . esc_html__( 'No active services are synced to your portal yet.', 'ajforms' ) . '</p></div>';
		}

		$lines = array();
		foreach ( $active_subscriptions as $subscription ) {
			$ledger_entry   = $this->get_subscription_ledger_entry( $subscription, $ledger );
			$service_name   = $this->get_subscription_service_name( $subscription, $ledger_entry );
			$service_period = $this->get_subscription_service_period( $subscription, $ledger_entry );
			$entity_label    = $business_name ? $business_name : __( 'your business entity', 'ajforms' );

			$lines[] = sprintf(
				/* translators: 1: service name, 2: business/entity name, 3: service period */
				__( 'You currently have %1$s for %2$s from %3$s.', 'ajforms' ),
				$service_name,
				$entity_label,
				$service_period
			);
		}

		ob_start();
		?>
		<div class="aj-portal-account-summary">
			<h3><?php esc_html_e( 'Account Summary', 'ajforms' ); ?></h3>
			<?php foreach ( $lines as $line ) : ?>
				<p><?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_customer_portal_tasks_tab() {
		$context = $this->get_current_user_portal_billing_context();
		$customer = $context['customer'];

		if ( '' === $context['stripe_customer_id'] || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Tasks', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$tasks = $this->get_current_user_portal_tasks();

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Tasks', 'ajforms' ); ?></h2>
			<p class="aj-portal-intro-text"><?php esc_html_e( 'Review action items and important compliance dates for your account.', 'ajforms' ); ?></p>
			<?php if ( isset( $_GET['task-updated'] ) ) : ?>
				<div class="aj-portal-add-service-message is-success"><?php esc_html_e( 'Task updated.', 'ajforms' ); ?></div>
			<?php endif; ?>
			<?php echo $this->render_customer_portal_tasks_table( $tasks, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function get_portal_service_request_actions( $entry ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		$status   = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';

		if ( 'checkout_session' !== (string) $entry->source_type || ! in_array( $status, array( 'unpaid', 'open', 'cancelled' ), true ) ) {
			return '';
		}

		$actions = array();
		if ( in_array( $status, array( 'unpaid', 'open' ), true ) && ! empty( $metadata['checkout_url'] ) ) {
			$actions[] = '<a class="button aj-portal-action-button aj-portal-action-resume" href="' . esc_url( $metadata['checkout_url'] ) . '">' . esc_html__( 'Resume', 'ajforms' ) . '</a>';
		}

		$button_label = in_array( $status, array( 'unpaid', 'open' ), true ) ? __( 'Cancel', 'ajforms' ) : __( 'Remove', 'ajforms' );
		$remove_url   = wp_nonce_url(
			add_query_arg(
				array(
					'portal_tab'                    => 'billing',
					'ajcore_remove_service_request' => (int) $entry->id,
				),
				$this->get_customer_portal_url()
			),
			'ajcore_remove_service_request_' . (int) $entry->id
		);
		$confirm_text = esc_attr__( 'Remove this pending service request from your billing history?', 'ajforms' );
		$actions[]    = '<a class="button aj-portal-action-button aj-portal-action-cancel" href="' . esc_url( $remove_url ) . '" onclick="return window.confirm(\'' . $confirm_text . '\');">' . esc_html( $button_label ) . '</a>';

		return '<span class="aj-portal-inline-actions">' . implode( ' ', $actions ) . '</span>';
	}

	private function render_customer_portal_overview_tab() {
		$context = $this->get_current_user_portal_billing_context();
		$customer = $context['customer'];

		if ( '' === $context['stripe_customer_id'] || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Overview', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$upcoming             = $context['upcoming'];
		$active_subscriptions = $context['active_subscriptions'];
		$files                = $this->get_current_user_portal_files();
		$tasks                = $this->get_current_user_portal_tasks();
		$display_name         = $customer->name ? $customer->name : $customer->email;
		$billing_url          = add_query_arg( 'portal_tab', 'billing', $this->get_customer_portal_url() );
		$file_library_url     = add_query_arg( 'portal_tab', 'file-library', $this->get_customer_portal_url() );
		$services_url         = add_query_arg( 'portal_tab', 'services', $this->get_customer_portal_url() );
		$tasks_url            = add_query_arg( 'portal_tab', 'tasks', $this->get_customer_portal_url() );
		$email_us_url         = home_url( '/email-us/' );
		$business_name        = $this->get_portal_customer_meta_value( $customer, array( 'business_name', 'business', 'company', 'company_name', 'description' ) );
		$text_message         = sprintf(
			__( 'Hi, I am an existing customer and my business name is: %1$s. My name is: %2$s. I need help with ', 'ajforms' ),
			$business_name ? $business_name : '-',
			$display_name ? $display_name : '-'
		);
		$text_url             = 'sms:+17043072135?body=' . rawurlencode( $text_message );

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php echo esc_html( sprintf( __( 'Welcome, %s', 'ajforms' ), $display_name ) ); ?></h2>

			<div class="aj-portal-summary-grid">
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $services_url ); ?>">
					<strong><?php esc_html_e( 'Active Services', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $active_subscriptions ) ) ); ?></span>
				</a>
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $billing_url ); ?>">
					<strong><?php esc_html_e( 'Upcoming Payments', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $upcoming ) ) ); ?></span>
				</a>
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $tasks_url ); ?>">
					<strong><?php esc_html_e( 'Open Tasks', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( $this->get_open_portal_tasks_count( $tasks ) ) ); ?></span>
				</a>
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $file_library_url ); ?>">
					<strong><?php esc_html_e( 'Available Files', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $files ) ) ); ?></span>
				</a>
			</div>

			<?php echo $this->render_customer_portal_service_summary( $active_subscriptions, $context['ledger'], $business_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<h3 class="aj-portal-quick-actions-heading"><?php esc_html_e( 'Quick Actions', 'ajforms' ); ?></h3>
			<div class="aj-portal-quick-actions">
				<a class="button" href="<?php echo esc_url( $file_library_url ); ?>"><?php esc_html_e( 'Upload Document', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $billing_url ); ?>"><?php esc_html_e( 'View Billing', 'ajforms' ); ?></a>
				<span class="button disabled"><?php esc_html_e( 'Update Payment Method', 'ajforms' ); ?></span>
				<a class="button" href="<?php echo esc_url( $services_url ); ?>"><?php esc_html_e( 'Add Services', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $email_us_url ); ?>"><?php esc_html_e( 'Email Us', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $text_url ); ?>"><?php esc_html_e( 'Text Us', 'ajforms' ); ?></a>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private function get_portal_files_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_files';
	}

	private function get_portal_file_users_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_file_users';
	}

	private function get_pdb() {
		return function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];
	}

	private function get_portal_stripe_customers_table() {
		return $this->get_pdb()->prefix . 'aj_portal_stripe_customers';
	}

	private function get_portal_stripe_products_table() {
		return $this->get_pdb()->prefix . 'aj_portal_stripe_products';
	}

	private function get_portal_product_catalog_table() {
		return $this->get_pdb()->prefix . 'aj_portal_product_catalog';
	}

	private function get_portal_stripe_subscriptions_table() {
		return $this->get_pdb()->prefix . 'aj_portal_stripe_subscriptions';
	}

	private function get_portal_service_snapshots_table() {
		return $this->get_pdb()->prefix . 'aj_portal_service_snapshots';
	}

	private function get_portal_service_states_table() {
		return $this->get_pdb()->prefix . 'aj_portal_service_states';
	}

	private function get_portal_customer_states_table() {
		return $this->get_pdb()->prefix . 'aj_portal_customer_states';
	}

	private function get_portal_ledger_table() {
		return $this->get_pdb()->prefix . 'aj_portal_ledger';
	}

	private function get_portal_tasks_table() {
		return $this->get_pdb()->prefix . 'aj_portal_tasks';
	}

	private function get_portal_task_statuses_table() {
		return $this->get_pdb()->prefix . 'aj_portal_task_statuses';
	}

	private function get_portal_task_comments_table() {
		return $this->get_pdb()->prefix . 'aj_portal_task_comments';
	}

	private function get_portal_user_mappings_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_auth_user_mappings'; // Always local — not in shared DB.
	}

	private function get_portal_event_log_table() {
		return $this->get_pdb()->prefix . 'aj_portal_event_log';
	}

	private function get_ajcore_site_uuid() {
		$uuid = (string) get_option( 'ajcore_site_uuid', '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( 'ajcore_site_uuid', $uuid, false );
			$this->log_site_uuid_created_event( $uuid );
		}

		return $uuid;
	}

	private function hash_impersonation_token( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	private function get_impersonation_cookie_name() {
		return 'ajcore_impersonation_return';
	}

	private function set_impersonation_cookie( $token, $expires ) {
		$args = array(
			'expires'  => (int) $expires,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
			$args['domain'] = COOKIE_DOMAIN;
		}
		setcookie( $this->get_impersonation_cookie_name(), (string) $token, $args );
		$_COOKIE[ $this->get_impersonation_cookie_name() ] = (string) $token;
	}

	private function clear_impersonation_cookie() {
		$args = array(
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
			$args['domain'] = COOKIE_DOMAIN;
		}
		setcookie( $this->get_impersonation_cookie_name(), '', $args );
		unset( $_COOKIE[ $this->get_impersonation_cookie_name() ] );
	}

	/**
	 * True when the current user is staff (admin or ops) — the same gate
	 * create_customer_impersonation_link() enforces internally.
	 */
	private function current_user_is_portal_staff() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( 'manage_options' )
			|| current_user_can( 'ajcore_ops_access' )
			|| in_array( 'aj_ops_user', (array) wp_get_current_user()->roles, true );
	}

	/**
	 * Staff clicked a customer on the portal switcher: mint a single-use
	 * impersonation token via the hardened admin helper and follow it.
	 * Customers cannot reach this: the staff gate runs before the nonce check,
	 * and create_customer_impersonation_link() re-validates actor + target.
	 */
	public function maybe_handle_staff_portal_switch() {
		if ( empty( $_GET['ajcore_staff_switch'] ) ) {
			return;
		}

		$stripe_customer_id = sanitize_text_field( wp_unslash( $_GET['ajcore_staff_switch'] ) );

		if ( ! $this->current_user_is_portal_staff() ) {
			wp_safe_redirect( $this->get_customer_portal_url() );
			exit;
		}

		check_admin_referer( 'ajcore_staff_switch_' . $stripe_customer_id );

		$admin = new AJForms_Admin();
		$link  = $admin->create_customer_impersonation_link( $stripe_customer_id, 'ajcore_admin', $this->get_customer_portal_url() );

		if ( is_wp_error( $link ) ) {
			wp_safe_redirect( add_query_arg( 'staff_switch_error', rawurlencode( $link->get_error_message() ), $this->get_customer_portal_url() ) );
			exit;
		}

		wp_safe_redirect( $link );
		exit;
	}

	/**
	 * Customer picker shown to staff on the portal page instead of the
	 * "access is not active" message. Lists active portal customers; the
	 * View Portal button starts a normal audited impersonation session.
	 */
	private function render_staff_customer_switcher() {
		global $wpdb;
		$pdb = $this->get_pdb();

		$customers_table = $this->get_portal_stripe_customers_table();
		$states_table    = $this->get_portal_customer_states_table();
		$has_states      = $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $states_table ) ) === $states_table;

		if ( $has_states ) {
			$customers = $pdb->get_results(
				"SELECT c.stripe_customer_id, c.name, c.email
				 FROM {$customers_table} c
				 LEFT JOIN {$states_table} s ON s.stripe_customer_id = c.stripe_customer_id
				 WHERE ( s.portal_status = 'active' OR ( s.portal_status IS NULL AND c.portal_status = 'active' ) )
				 ORDER BY c.name, c.email"
			);
		} else {
			$customers = $pdb->get_results(
				"SELECT stripe_customer_id, name, email FROM {$customers_table} WHERE portal_status = 'active' ORDER BY name, email"
			);
		}

		$mapping_counts = array();
		foreach ( (array) $wpdb->get_results(
			"SELECT stripe_customer_id, COUNT(DISTINCT user_id) AS user_count FROM {$this->get_portal_user_mappings_table()} WHERE user_id > 0 GROUP BY stripe_customer_id"
		) as $row ) {
			$mapping_counts[ (string) $row->stripe_customer_id ] = (int) $row->user_count;
		}

		$error = isset( $_GET['staff_switch_error'] ) ? sanitize_text_field( wp_unslash( $_GET['staff_switch_error'] ) ) : '';

		ob_start();
		?>
		<div class="aj-customer-portal aj-staff-switcher" style="max-width:860px;margin:0 auto;font-family:Inter,ui-sans-serif,system-ui,sans-serif;">
			<div style="margin:0 0 18px;padding:14px 16px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e40af;font-weight:600;">
				<?php echo esc_html( sprintf( __( 'You are signed in as staff (%s). Pick a client below to view their portal exactly as they see it. Every session is logged.', 'ajforms' ), wp_get_current_user()->user_email ) ); ?>
			</div>
			<?php if ( '' !== $error ) : ?>
				<div style="margin:0 0 16px;padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;color:#991b1b;font-weight:600;"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>
			<input type="search" id="aj-staff-switcher-filter" placeholder="<?php esc_attr_e( 'Search clients by name or email…', 'ajforms' ); ?>" style="width:100%;margin:0 0 14px;padding:12px 14px;border:1px solid #cbd5e1;border-radius:12px;font-size:15px;box-sizing:border-box;">
			<?php if ( empty( $customers ) ) : ?>
				<p><?php esc_html_e( 'No clients with active portal access yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div style="border:1px solid #e2e8f0;border-radius:14px;background:#fff;overflow:hidden;">
					<?php foreach ( $customers as $customer ) : ?>
						<?php
						$cus_id     = (string) $customer->stripe_customer_id;
						$user_count = isset( $mapping_counts[ $cus_id ] ) ? $mapping_counts[ $cus_id ] : 0;
						$switch_url = wp_nonce_url( add_query_arg( 'ajcore_staff_switch', rawurlencode( $cus_id ), $this->get_customer_portal_url() ), 'ajcore_staff_switch_' . $cus_id );
						?>
						<div class="aj-staff-switcher-row" data-filter="<?php echo esc_attr( strtolower( $customer->name . ' ' . $customer->email ) ); ?>" style="display:flex;align-items:center;gap:14px;padding:13px 16px;border-top:1px solid #eef2f7;">
							<div style="flex:1;min-width:0;">
								<div style="font-weight:700;color:#0f172a;"><?php echo esc_html( $customer->name ? $customer->name : $customer->email ); ?></div>
								<div style="color:#64748b;font-size:13px;overflow-wrap:anywhere;"><?php echo esc_html( $customer->email ); ?></div>
							</div>
							<?php if ( 1 === $user_count ) : ?>
								<a class="button" href="<?php echo esc_url( $switch_url ); ?>"><?php esc_html_e( 'View Portal', 'ajforms' ); ?></a>
							<?php else : ?>
								<span style="color:#94a3b8;font-size:13px;font-weight:600;"><?php echo esc_html( 0 === $user_count ? __( 'No portal login yet', 'ajforms' ) : __( 'Multiple logins — fix in admin', 'ajforms' ) ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p style="margin:16px 0 0;"><a href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( '← Back to admin dashboard', 'ajforms' ); ?></a></p>
		</div>
		<script>
		(function(){
			var filter = document.getElementById('aj-staff-switcher-filter');
			if (!filter) return;
			filter.addEventListener('input', function(){
				var q = filter.value.trim().toLowerCase();
				document.querySelectorAll('.aj-staff-switcher-row').forEach(function(row){
					row.style.display = !q || row.dataset.filter.indexOf(q) !== -1 ? 'flex' : 'none';
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	public function maybe_handle_impersonation_request() {
		if ( empty( $_GET['ajcore_impersonate'] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['ajcore_impersonate'] ) );
		if ( '' === $token ) {
			wp_safe_redirect( $this->get_customer_portal_url() );
			exit;
		}

		$token_hash = $this->hash_impersonation_token( $token );
		$payload    = get_transient( 'ajcore_impersonation_' . $token_hash );
		if ( ! is_array( $payload ) || empty( $payload['target_user_id'] ) || empty( $payload['actor_user_id'] ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'impersonation-invalid', $this->get_customer_portal_url() ) );
			exit;
		}
		delete_transient( 'ajcore_impersonation_' . $token_hash );

		$target = get_userdata( (int) $payload['target_user_id'] );
		$actor  = get_userdata( (int) $payload['actor_user_id'] );
		if ( ! $target || ! $actor || ! $this->user_has_customer_portal_role( $target ) || current_user_can( 'manage_options' ) && (int) $target->ID === get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'impersonation-invalid', $this->get_customer_portal_url() ) );
			exit;
		}

		$return_token = wp_generate_password( 48, false, false );
		$return_hash  = $this->hash_impersonation_token( $return_token );
		$expires      = time() + ( 8 * HOUR_IN_SECONDS );
		set_transient(
			'ajcore_impersonation_return_' . $return_hash,
			$payload,
			8 * HOUR_IN_SECONDS
		);

		wp_logout();
		wp_set_current_user( (int) $target->ID );
		wp_set_auth_cookie( (int) $target->ID, false, is_ssl() );
		$this->set_impersonation_cookie( $return_token, $expires );

		$this->log_portal_event(
			'impersonation_started',
			array(
				'source'             => ! empty( $payload['source'] ) ? sanitize_key( (string) $payload['source'] ) : 'ajcore_admin',
				'stripe_customer_id' => ! empty( $payload['stripe_customer_id'] ) ? sanitize_text_field( (string) $payload['stripe_customer_id'] ) : '',
				'wp_user_id_after'   => (int) $target->ID,
				'email_after'        => (string) $target->user_email,
				'actor_user_id'      => (int) $actor->ID,
				'actor_email'        => (string) $actor->user_email,
				'details'            => array(
					'actor_user_id'  => (int) $actor->ID,
					'target_user_id' => (int) $target->ID,
					'ip'             => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				),
			)
		);

		wp_safe_redirect( remove_query_arg( 'ajcore_impersonate', $this->get_customer_portal_url() ) );
		exit;
	}

	public function maybe_handle_impersonation_return() {
		if ( empty( $_GET['ajcore_end_impersonation'] ) ) {
			return;
		}

		$token        = sanitize_text_field( wp_unslash( $_GET['ajcore_end_impersonation'] ) );
		$cookie_token = isset( $_COOKIE[ $this->get_impersonation_cookie_name() ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $this->get_impersonation_cookie_name() ] ) ) : '';
		if ( '' === $token || ! hash_equals( $cookie_token, $token ) ) {
			wp_safe_redirect( $this->get_customer_portal_url() );
			exit;
		}

		$return_hash = $this->hash_impersonation_token( $token );
		$payload     = get_transient( 'ajcore_impersonation_return_' . $return_hash );
		if ( ! is_array( $payload ) || empty( $payload['actor_user_id'] ) || empty( $payload['target_user_id'] ) || (int) $payload['target_user_id'] !== get_current_user_id() ) {
			$this->clear_impersonation_cookie();
			wp_safe_redirect( $this->get_customer_portal_url() );
			exit;
		}
		delete_transient( 'ajcore_impersonation_return_' . $return_hash );
		$this->clear_impersonation_cookie();

		$actor = get_userdata( (int) $payload['actor_user_id'] );
		if ( ! $actor ) {
			wp_logout();
			wp_safe_redirect( wp_login_url( admin_url( 'admin.php' ) ) );
			exit;
		}

		$this->log_portal_event(
			'impersonation_ended',
			array(
				'source'             => ! empty( $payload['source'] ) ? sanitize_key( (string) $payload['source'] ) : 'ajcore_admin',
				'stripe_customer_id' => ! empty( $payload['stripe_customer_id'] ) ? sanitize_text_field( (string) $payload['stripe_customer_id'] ) : '',
				'wp_user_id_before'  => (int) $payload['target_user_id'],
				'wp_user_id_after'   => (int) $actor->ID,
				'actor_user_id'      => (int) $actor->ID,
				'actor_email'        => (string) $actor->user_email,
				'details'            => array(
					'actor_user_id'  => (int) $actor->ID,
					'target_user_id' => (int) $payload['target_user_id'],
					'ip'             => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				),
			)
		);

		wp_logout();
		wp_set_current_user( (int) $actor->ID );
		wp_set_auth_cookie( (int) $actor->ID, false, is_ssl() );

		$return_url = ! empty( $payload['return_url'] ) ? esc_url_raw( (string) $payload['return_url'] ) : admin_url( 'admin.php' );
		wp_safe_redirect( wp_validate_redirect( $return_url, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function get_active_impersonation_payload() {
		if ( ! is_user_logged_in() || empty( $_COOKIE[ $this->get_impersonation_cookie_name() ] ) ) {
			return null;
		}

		$token   = sanitize_text_field( wp_unslash( $_COOKIE[ $this->get_impersonation_cookie_name() ] ) );
		$payload = get_transient( 'ajcore_impersonation_return_' . $this->hash_impersonation_token( $token ) );
		if ( ! is_array( $payload ) || empty( $payload['target_user_id'] ) || (int) $payload['target_user_id'] !== get_current_user_id() ) {
			return null;
		}

		$payload['return_token'] = $token;
		return $payload;
	}

	private function is_impersonating_client() {
		return null !== $this->get_active_impersonation_payload();
	}

	private function block_impersonated_portal_write( $message = '' ) {
		if ( ! $this->is_impersonating_client() ) {
			return false;
		}
		$message = '' !== $message ? $message : __( 'This action is disabled while viewing as a client.', 'ajforms' );
		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'message' => $message ), 403 );
		}
		wp_safe_redirect( add_query_arg( 'portal_notice', 'impersonation-readonly', $this->get_customer_portal_url() ) );
		exit;
	}

	private function log_site_uuid_created_event( $uuid ) {
		$wpdb = $this->get_pdb();

		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$actor = wp_get_current_user();
		return $wpdb->insert(
			$table,
			array(
				'event_type'     => 'site_uuid_created',
				'severity'       => 'info',
				'source'         => 'customer_portal',
				'correlation_id' => wp_generate_uuid4(),
				'site_uuid'      => sanitize_text_field( (string) $uuid ),
				'actor_user_id'  => get_current_user_id(),
				'actor_email'    => $actor && ! empty( $actor->user_email ) ? sanitize_email( $actor->user_email ) : '',
				'details'        => wp_json_encode( array( 'option' => 'ajcore_site_uuid' ) ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	private function get_portal_event_correlation_id() {
		static $correlation_id = '';
		if ( '' === $correlation_id ) {
			$correlation_id = wp_generate_uuid4();
		}

		return $correlation_id;
	}

	private function log_portal_event( $event_type, $args = array() ) {
		$wpdb = $this->get_pdb();

		$event_type = sanitize_key( (string) $event_type );
		if ( '' === $event_type ) {
			return false;
		}

		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$actor = wp_get_current_user();
		$args  = wp_parse_args(
			(array) $args,
			array(
				'severity'             => 'info',
				'source'               => 'customer_portal',
				'correlation_id'       => $this->get_portal_event_correlation_id(),
				'customer_id'          => 0,
				'stripe_customer_id'   => '',
				'wp_user_id_before'    => 0,
				'wp_user_id_after'     => 0,
				'email_before'         => '',
				'email_after'          => '',
				'portal_status_before' => '',
				'portal_status_after'  => '',
				'actor_user_id'        => get_current_user_id(),
				'actor_email'          => $actor && ! empty( $actor->user_email ) ? $actor->user_email : '',
				'details'              => array(),
			)
		);

		$details = is_array( $args['details'] ) ? $args['details'] : array( 'value' => $args['details'] );

		$inserted = $wpdb->insert(
			$table,
			array(
				'event_type'             => $event_type,
				'severity'               => in_array( sanitize_key( (string) $args['severity'] ), array( 'debug', 'info', 'warning', 'error', 'critical' ), true ) ? sanitize_key( (string) $args['severity'] ) : 'info',
				'source'                 => sanitize_key( (string) $args['source'] ),
				'correlation_id'         => sanitize_text_field( (string) $args['correlation_id'] ),
				'site_uuid'              => sanitize_text_field( $this->get_ajcore_site_uuid() ),
				'customer_id'            => absint( $args['customer_id'] ),
				'stripe_customer_id'     => sanitize_text_field( (string) $args['stripe_customer_id'] ),
				'wp_user_id_before'      => absint( $args['wp_user_id_before'] ),
				'wp_user_id_after'       => absint( $args['wp_user_id_after'] ),
				'email_before'           => sanitize_email( (string) $args['email_before'] ),
				'email_after'            => sanitize_email( (string) $args['email_after'] ),
				'portal_status_before'   => sanitize_key( (string) $args['portal_status_before'] ),
				'portal_status_after'    => sanitize_key( (string) $args['portal_status_after'] ),
				'actor_user_id'          => absint( $args['actor_user_id'] ),
				'actor_email'            => sanitize_email( (string) $args['actor_email'] ),
				'details'                => wp_json_encode( $details ),
				'created_at'             => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false !== $inserted ) {
			$this->maybe_purge_portal_event_log();
		}

		return $inserted;
	}

	private function get_portal_event_log_retention_settings() {
		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$days     = isset( $settings['portal_event_log_retention_days'] ) ? absint( $settings['portal_event_log_retention_days'] ) : 180;
		$max_rows = isset( $settings['portal_event_log_max_rows'] ) ? absint( $settings['portal_event_log_max_rows'] ) : 50000;

		$shared_settings = $this->get_shared_portal_event_log_retention_settings();
		if ( $shared_settings ) {
			$days     = $shared_settings['days'];
			$max_rows = $shared_settings['max_rows'];
		}

		return array(
			'days'     => $days,
			'max_rows' => $max_rows,
		);
	}

	private function get_shared_portal_event_log_retention_settings() {
		$wpdb = $this->get_pdb();
		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$details_json = $wpdb->get_var( "SELECT details FROM `{$table}` WHERE event_type = 'event_log_retention_settings_updated' ORDER BY created_at DESC, id DESC LIMIT 1" );
		if ( empty( $details_json ) ) {
			return false;
		}

		$details = json_decode( (string) $details_json, true );
		if ( ! is_array( $details ) ) {
			return false;
		}

		return array(
			'days'     => isset( $details['retention_days'] ) ? absint( $details['retention_days'] ) : 180,
			'max_rows' => isset( $details['max_rows'] ) ? absint( $details['max_rows'] ) : 50000,
		);
	}

	private function purge_portal_event_log( $days = null, $max_rows = null ) {
		$wpdb = $this->get_pdb();
		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$retention = $this->get_portal_event_log_retention_settings();
		$days      = null === $days ? $retention['days'] : absint( $days );
		$max_rows  = null === $max_rows ? $retention['max_rows'] : absint( $max_rows );

		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE event_type <> 'event_log_retention_settings_updated' AND created_at < %s", $cutoff ) );
		}

		if ( $max_rows > 0 ) {
			$boundary = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at FROM `{$table}` WHERE event_type <> 'event_log_retention_settings_updated' ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET %d", $max_rows ) );
			if ( $boundary ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$table}` WHERE event_type <> 'event_log_retention_settings_updated' AND (created_at < %s OR (created_at = %s AND id <= %d))",
						$boundary->created_at,
						$boundary->created_at,
						absint( $boundary->id )
					)
				);
			}
		}

		return true;
	}

	private function maybe_purge_portal_event_log() {
		$retention = $this->get_portal_event_log_retention_settings();
		if ( $retention['days'] < 1 && $retention['max_rows'] < 1 ) {
			return;
		}

		$last_run = absint( get_option( 'ajcore_portal_event_log_last_auto_purge', 0 ) );
		if ( $last_run > ( time() - DAY_IN_SECONDS ) ) {
			return;
		}

		update_option( 'ajcore_portal_event_log_last_auto_purge', time(), false );
		$this->purge_portal_event_log( $retention['days'], $retention['max_rows'] );
	}

	private function get_portal_service_requests_table() {
		return $this->get_pdb()->prefix . 'aj_portal_service_requests';
	}

	private function normalize_portal_customer_status( $status ) {
		$status = sanitize_key( (string) $status );
		if ( 'without_login' === $status ) {
			$status = 'without_portal_login';
		}

		return in_array( $status, array( 'active', 'disabled', 'archived', 'without_portal_login' ), true ) ? $status : 'disabled';
	}

	private function user_has_customer_portal_role( $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return false;
		}

		$roles         = array_map( 'sanitize_key', (array) $user->roles );
		$auth_settings = get_option( 'ajcore_auth_settings', array() );
		$customer_role = is_array( $auth_settings ) && ! empty( $auth_settings['customer_role'] ) ? sanitize_key( (string) $auth_settings['customer_role'] ) : 'aj_portal_user';

		return in_array( 'aj_portal_user', $roles, true ) || user_can( $user, 'ajcore_customer_portal_access' ) || ( $customer_role && in_array( $customer_role, $roles, true ) );
	}

	private function migrate_portal_mapping_site_uuid( $mapping, $user, $source = 'customer_portal' ) {
		global $wpdb;

		if ( ! $mapping || empty( $mapping->stripe_customer_id ) || ! $user ) {
			return false;
		}

		$current_site_uuid = $this->get_ajcore_site_uuid();
		$wpdb->update(
			$this->get_portal_user_mappings_table(),
			array(
				'site_uuid'  => $current_site_uuid,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'stripe_customer_id' => sanitize_text_field( (string) $mapping->stripe_customer_id ) ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		$this->log_portal_event(
			'mapping_migrated',
			array(
				'source'             => sanitize_key( (string) $source ),
				'customer_id'        => isset( $mapping->customer_row_id ) ? (int) $mapping->customer_row_id : 0,
				'stripe_customer_id' => sanitize_text_field( (string) $mapping->stripe_customer_id ),
				'wp_user_id_after'   => (int) $user->ID,
				'email_after'        => $user->user_email,
				'details'            => array(
					'reason'    => 'missing_site_uuid_email_validated',
					'site_uuid' => $current_site_uuid,
				),
			)
		);

		return true;
	}

	private function quarantine_portal_mapping_site_uuid_mismatch( $mapping, $user = null, $source = 'customer_portal' ) {
		$this->log_portal_event(
			'mapping_quarantined',
			array(
				'severity'           => 'warning',
				'source'             => sanitize_key( (string) $source ),
				'customer_id'        => isset( $mapping->customer_row_id ) ? (int) $mapping->customer_row_id : 0,
				'stripe_customer_id' => isset( $mapping->stripe_customer_id ) ? sanitize_text_field( (string) $mapping->stripe_customer_id ) : '',
				'wp_user_id_before'  => isset( $mapping->user_id ) ? (int) $mapping->user_id : 0,
				'email_before'       => $user && ! empty( $user->user_email ) ? $user->user_email : ( isset( $mapping->portal_user_email ) ? $mapping->portal_user_email : '' ),
				'details'            => array(
					'reason'            => 'site_uuid_mismatch',
					'mapping_site_uuid' => isset( $mapping->site_uuid ) ? sanitize_text_field( (string) $mapping->site_uuid ) : '',
					'current_site_uuid' => $this->get_ajcore_site_uuid(),
				),
			)
		);

		return true;
	}

	private function get_current_user_portal_access_context() {
		if ( ! is_user_logged_in() ) {
			return array( 'allowed' => false, 'reason' => 'not_logged_in', 'mapping' => null, 'customer' => null );
		}

		global $wpdb;
		$pdb = $this->get_pdb();

		$user = wp_get_current_user();
		if ( ! $user || is_wp_error( $user ) || ! $user->ID ) {
			return array( 'allowed' => false, 'reason' => 'no_mapping', 'mapping' => null, 'customer' => null );
		}

		// user_mappings is always local; stripe_customers is shared — split cross-DB JOIN.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1",
				(int) $user->ID
			)
		);

		if ( ! $row ) {
			return array( 'allowed' => false, 'reason' => 'no_mapping', 'mapping' => null, 'customer' => null );
		}

		$row->customer_stripe_customer_id = ! empty( $row->stripe_customer_id ) ? sanitize_text_field( (string) $row->stripe_customer_id ) : '';
		$row->customer_row_id             = 0;
		$row->stripe_email                = '';
		$row->portal_status               = '';
		$row->enabled_portal              = 0;

		if ( ! empty( $row->stripe_customer_id ) ) {
			$cust = $pdb->get_row(
				$pdb->prepare(
					"SELECT id AS customer_row_id, email AS stripe_email, portal_status, enabled_portal FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s LIMIT 1",
					$row->stripe_customer_id
				)
			);
			if ( $cust ) {
				$row->customer_row_id = $cust->customer_row_id;
				$row->stripe_email    = $cust->stripe_email;
				$row->portal_status   = $cust->portal_status;
				$row->enabled_portal  = $cust->enabled_portal;
			}
		}

		$customer_state_table = $this->get_portal_customer_states_table();
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $customer_state_table ) ) === $customer_state_table ) {
			$customer_state = $pdb->get_row(
				$pdb->prepare(
					"SELECT portal_status, enabled_portal, customer_email, portal_user_email FROM {$customer_state_table} WHERE stripe_customer_id = %s LIMIT 1",
					$row->customer_stripe_customer_id
				)
			);
			if ( $customer_state ) {
				$row->portal_status  = $customer_state->portal_status;
				$row->enabled_portal = (int) $customer_state->enabled_portal;
				if ( empty( $row->stripe_email ) && ! empty( $customer_state->customer_email ) ) {
					$row->stripe_email = $customer_state->customer_email;
				}
				if ( empty( $row->portal_user_email ) && ! empty( $customer_state->portal_user_email ) ) {
					$row->portal_user_email = $customer_state->portal_user_email;
				}
			}
		}

		if ( empty( $row->customer_stripe_customer_id ) ) {
			return array( 'allowed' => false, 'reason' => 'missing_customer', 'mapping' => $row, 'customer' => null );
		}

		$valid_emails = array();
		foreach ( array( $row->stripe_email, $row->customer_email, $row->portal_user_email ) as $email ) {
			$email = strtolower( sanitize_email( (string) $email ) );
			if ( is_email( $email ) ) {
				$valid_emails[] = $email;
			}
		}
		$valid_emails = array_values( array_unique( $valid_emails ) );
		if ( empty( $valid_emails ) || ! in_array( strtolower( (string) $user->user_email ), $valid_emails, true ) ) {
			return array( 'allowed' => false, 'reason' => 'email_mismatch', 'mapping' => $row, 'customer' => $row );
		}

		$current_site_uuid = $this->get_ajcore_site_uuid();
		$mapping_site_uuid = isset( $row->site_uuid ) ? sanitize_text_field( (string) $row->site_uuid ) : '';
		if ( '' === $mapping_site_uuid ) {
			$this->migrate_portal_mapping_site_uuid( $row, $user, 'customer_portal' );
			$row->site_uuid = $current_site_uuid;
		} elseif ( $mapping_site_uuid !== $current_site_uuid ) {
			$this->quarantine_portal_mapping_site_uuid_mismatch( $row, $user, 'customer_portal' );
			return array( 'allowed' => false, 'reason' => 'site_uuid_mismatch', 'mapping' => $row, 'customer' => $row );
		}

		$status = $this->normalize_portal_customer_status( $row->portal_status );
		if ( 'active' !== $status ) {
			return array( 'allowed' => false, 'reason' => 'archived' === $status ? 'status_archived' : 'status_disabled', 'mapping' => $row, 'customer' => $row );
		}

		if ( empty( $row->enabled_portal ) ) {
			return array( 'allowed' => false, 'reason' => 'status_disabled', 'mapping' => $row, 'customer' => $row );
		}

		if ( ! $this->user_has_customer_portal_role( $user ) ) {
			return array( 'allowed' => false, 'reason' => 'missing_role', 'mapping' => $row, 'customer' => $row );
		}

		return array( 'allowed' => true, 'reason' => '', 'mapping' => $row, 'customer' => $row );
	}

	private function get_current_user_portal_mapping() {
		$context = $this->get_current_user_portal_access_context();

		return ! empty( $context['allowed'] ) ? $context['mapping'] : null;
	}

	private function get_current_user_stripe_customer_id() {
		$mapping = $this->get_current_user_portal_mapping();

		return $mapping && ! empty( $mapping->stripe_customer_id ) ? sanitize_text_field( $mapping->stripe_customer_id ) : '';
	}

	private function get_current_user_portal_customer() {
		$mapping = $this->get_current_user_portal_mapping();
		if ( ! $mapping || empty( $mapping->stripe_customer_id ) ) {
			return null;
		}

		$pdb                = $this->get_pdb();
		$stripe_customer_id = sanitize_text_field( $mapping->stripe_customer_id );

		$customer_state_table = $this->get_portal_customer_states_table();
		$state_table_exists   = $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $customer_state_table ) ) === $customer_state_table;
		$state                = null;
		if ( $state_table_exists ) {
			$state = $pdb->get_row(
				$pdb->prepare(
					"SELECT * FROM {$customer_state_table} WHERE stripe_customer_id = %s AND enabled_portal = 1 AND portal_status = 'active' LIMIT 1",
					$stripe_customer_id
				)
			);
		}

		$customer = $pdb->get_row(
			$pdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);
		if ( $customer && ( $state || ( ! empty( $customer->enabled_portal ) && 'active' === $this->normalize_portal_customer_status( $customer->portal_status ) ) ) ) {
			$customer->enabled_portal = 1;
			$customer->portal_status  = 'active';
			return $customer;
		}

		if ( ! $state_table_exists ) {
			return null;
		}
		if ( ! $state ) {
			return null;
		}

		return (object) array(
			'id'                 => 0,
			'stripe_customer_id' => $stripe_customer_id,
			'email'              => ! empty( $state->customer_email ) ? sanitize_email( (string) $state->customer_email ) : sanitize_email( (string) $mapping->customer_email ),
			'name'               => $this->get_current_user_portal_display_name_fallback( $mapping, $state ),
			'phone'              => '',
			'address'            => '',
			'metadata'           => '',
			'raw_data'           => '',
			'livemode'           => 0,
			'enabled_portal'     => 1,
			'portal_status'      => 'active',
			'created_at'         => null,
			'synced_at'          => '',
		);
	}

	private function get_current_user_portal_display_name_fallback( $mapping, $state ) {
		$user = wp_get_current_user();
		if ( $user && ! empty( $user->display_name ) && $user->display_name !== $user->user_login ) {
			return sanitize_text_field( (string) $user->display_name );
		}

		foreach ( array( $state->customer_email ?? '', $state->portal_user_email ?? '', $mapping->customer_email ?? '', $mapping->portal_user_email ?? '' ) as $email ) {
			$email = sanitize_email( (string) $email );
			if ( is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}

	private function get_portal_file_record( $file_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_files_table()} WHERE id = %d",
				absint( $file_id )
			)
		);
	}

	private function current_user_can_access_portal_file( $file_id ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		global $wpdb;

		$user       = wp_get_current_user();
		$user_id    = get_current_user_id();
		$user_email = strtolower( (string) $user->user_email );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->get_portal_file_users_table()} WHERE file_id = %d AND (user_id = %d OR LOWER(user_email) = %s)",
				absint( $file_id ),
				$user_id,
				$user_email
			)
		);

		return (int) $count > 0;
	}

	private function get_current_user_portal_files() {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		global $wpdb;

		$user       = wp_get_current_user();
		$user_email = strtolower( (string) $user->user_email );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT f.* FROM {$this->get_portal_files_table()} f
				INNER JOIN {$this->get_portal_file_users_table()} fu ON fu.file_id = f.id
				WHERE fu.user_id = %d OR LOWER(fu.user_email) = %s
				ORDER BY f.category ASC, f.created_at DESC",
				get_current_user_id(),
				$user_email
			)
		);
	}

	public function render_customer_portal_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'show_title' => 'yes',
			),
			$atts,
			'aj_customer_portal'
		);

		if ( ! is_user_logged_in() ) {
			$this->log_guest_portal_redirect();

			$redirect_to = get_permalink();
			if ( ! $redirect_to ) {
				$redirect_to = $this->get_customer_portal_url();
			}
			$login_url = wp_login_url( $redirect_to );
			if ( ! headers_sent() ) {
				wp_safe_redirect( $login_url );
				exit;
			}

			ob_start();
			?>
			<script>window.location.href = <?php echo wp_json_encode( esc_url_raw( $login_url ) ); ?>;</script>
			<noscript>
				<meta http-equiv="refresh" content="0;url=<?php echo esc_url( $login_url ); ?>">
				<p><a class="button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Continue to login', 'ajforms' ); ?></a></p>
			</noscript>
			<?php
			return ob_get_clean();
		}

		$current_user = wp_get_current_user();
		$access       = $this->get_current_user_portal_access_context();
		$mapping      = ! empty( $access['mapping'] ) ? $access['mapping'] : null;
		if ( ! empty( $access['allowed'] ) && $mapping && ! empty( $mapping->stripe_customer_id ) ) {
			$this->log_portal_event(
				'auth_allowed',
				array(
					'source'               => 'customer_portal',
					'stripe_customer_id'   => $mapping->stripe_customer_id,
					'wp_user_id_after'     => get_current_user_id(),
					'email_after'          => $current_user && ! empty( $current_user->user_email ) ? $current_user->user_email : '',
					'portal_status_after'  => 'active',
					'details'              => array(
						'mapping_id'   => isset( $mapping->id ) ? (int) $mapping->id : 0,
						'request_uri'  => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
					),
				)
			);
		} else {
			$denied_stripe_customer_id = $mapping && ! empty( $mapping->stripe_customer_id ) ? $mapping->stripe_customer_id : '';
			if ( '' === $denied_stripe_customer_id && $mapping && ! empty( $mapping->customer_stripe_customer_id ) ) {
				$denied_stripe_customer_id = $mapping->customer_stripe_customer_id;
			}
			$this->log_portal_event(
				'auth_denied',
				array(
					'severity'          => 'warning',
					'source'            => 'customer_portal',
					'stripe_customer_id'=> $denied_stripe_customer_id,
					'wp_user_id_before' => get_current_user_id(),
					'email_before'      => $current_user && ! empty( $current_user->user_email ) ? $current_user->user_email : '',
					'portal_status_before' => $mapping && isset( $mapping->portal_status ) ? $this->normalize_portal_customer_status( $mapping->portal_status ) : '',
					'details'           => array(
						'reason'      => ! empty( $access['reason'] ) ? $access['reason'] : 'no_mapping',
						'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
					),
				)
			);

			// Staff without a portal mapping get the client switcher instead of a dead end.
			if ( $this->current_user_is_portal_staff() ) {
				return $this->render_staff_customer_switcher();
			}

			ob_start();
			?>
			<div class="aj-customer-portal aj-customer-portal-login">
				<p><?php esc_html_e( 'Your client portal access is not active for this account.', 'ajforms' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log Out', 'ajforms' ); ?></a></p>
			</div>
			<?php
			return ob_get_clean();
		}

		$portal_items = array_values(
			array_filter(
				$this->get_customer_portal_menu_items(),
				function ( $item ) {
					return ! empty( $item['enabled'] );
				}
			)
		);
		$active_tab = isset( $_GET['portal_tab'] ) ? sanitize_key( wp_unslash( $_GET['portal_tab'] ) ) : 'overview';
		$active_ids = wp_list_pluck( $portal_items, 'id' );
		if ( ! in_array( $active_tab, $active_ids, true ) ) {
			$active_tab = 'overview';
		}

		$portal_bg_preset = sanitize_key( get_option( 'ajcore_portal_background', 'default' ) );
		$portal_bg_presets = array(
			'default' => 'radial-gradient(circle at 18% 22%,rgba(49,87,255,.18),transparent 30%),radial-gradient(circle at 82% 8%,rgba(124,58,237,.17),transparent 28%),radial-gradient(circle at 50% 64%,rgba(6,182,212,.11),transparent 35%)',
			'ocean'   => 'radial-gradient(circle at 20% 30%,rgba(14,165,233,.22),transparent 35%),radial-gradient(circle at 80% 10%,rgba(6,182,212,.18),transparent 30%),radial-gradient(circle at 50% 70%,rgba(99,102,241,.10),transparent 30%)',
			'sunset'  => 'radial-gradient(circle at 20% 30%,rgba(251,146,60,.20),transparent 35%),radial-gradient(circle at 80% 10%,rgba(244,63,94,.16),transparent 30%),radial-gradient(circle at 55% 65%,rgba(251,191,36,.10),transparent 35%)',
			'mint'    => 'radial-gradient(circle at 18% 22%,rgba(16,185,129,.18),transparent 30%),radial-gradient(circle at 82% 8%,rgba(6,182,212,.16),transparent 28%),radial-gradient(circle at 50% 64%,rgba(5,150,105,.10),transparent 35%)',
			'slate'   => 'radial-gradient(circle at 18% 22%,rgba(100,116,139,.14),transparent 30%),radial-gradient(circle at 82% 8%,rgba(71,85,105,.12),transparent 28%)',
			'none'    => 'none',
		);
		$portal_shell_bg = isset( $portal_bg_presets[ $portal_bg_preset ] ) ? $portal_bg_presets[ $portal_bg_preset ] : $portal_bg_presets['default'];

		ob_start();
		?>
		<div class="ajcore-portal-shell">
			<style>
				.ajcore-portal-shell{
					--ajp-ink:#081225;
					--ajp-muted:#526173;
					--ajp-line:rgba(148,163,184,.24);
					--ajp-card:rgba(255,255,255,.84);
					--ajp-glass:rgba(255,255,255,.72);
					--ajp-primary:#3157ff;
					--ajp-primary-2:#7c3aed;
					--ajp-cyan:#06b6d4;
					--ajp-green:#10b981;
					--ajp-red:#ef4444;
					--ajp-shadow:0 20px 52px rgba(15,23,42,.09);
					--ajp-shadow-soft:0 12px 32px rgba(15,23,42,.065);
					position:relative;
					isolation:isolate;
					width:calc(100vw - 48px);
					max-width:none;
					margin:18px 0 0 calc(50% - 50vw + 24px);
					padding:0 0 44px;
					font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
					color:var(--ajp-ink);
				}
				.ajcore-portal-shell:before{
					content:"";
					position:absolute;
					z-index:-1;
					inset:-34px -28px auto;
					height:170px;
					background:<?php echo esc_html( $portal_shell_bg ); ?>;
					filter:blur(8px);
					pointer-events:none;
				}
				.ajcore-portal-shell *{box-sizing:border-box}
				.ajcore-portal-shell h1{margin:0 0 14px;padding:0;font-size:28px;line-height:1.08;letter-spacing:-.04em;color:var(--ajp-ink)}
				.ajcore-portal-shell h2{margin:0 0 16px;padding:0;font-size:clamp(28px,2.45vw,36px);line-height:1;letter-spacing:-.055em;background:linear-gradient(135deg,#1d4ed8 0%,#3157ff 42%,#7c3aed 100%);-webkit-background-clip:text;background-clip:text;color:transparent;text-wrap:balance}
				.ajcore-portal-shell h3{margin:22px 0 10px;padding:0;font-size:20px;line-height:1.16;letter-spacing:-.04em;color:var(--ajp-ink)}
				.ajcore-portal-shell h3:first-of-type{margin-top:0}
				.ajcore-portal-shell p{color:#334155;line-height:1.65;font-size:16px}
				.ajcore-portal-shell a{color:#2563eb;text-decoration:none;font-weight:800}
				.ajcore-portal-shell a:hover{text-decoration:none;color:#1d4ed8}
				.ajcore-portal-shell .button,
				.ajcore-portal-shell button.button{
					appearance:none;
					display:inline-flex;
					align-items:center;
					justify-content:center;
					min-height:44px;
					padding:12px 19px;
					border:0;
					border-radius:999px;
					background:linear-gradient(135deg,#3157ff 0%,#6d3df2 100%);
					color:#fff!important;
					font-weight:900;
					letter-spacing:-.015em;
					text-decoration:none;
					cursor:pointer;
					box-shadow:0 18px 38px rgba(49,87,255,.24);
					transition:transform .18s ease,box-shadow .18s ease,filter .18s ease,opacity .18s ease;
				}
				.ajcore-portal-shell .button:hover,
				.ajcore-portal-shell button.button:hover{transform:translateY(-2px);box-shadow:0 24px 52px rgba(49,87,255,.28);filter:saturate(1.05)}
				.ajcore-portal-shell .button.disabled,
				.ajcore-portal-shell button.button:disabled{background:#e5e7eb!important;color:#94a3b8!important;box-shadow:none;cursor:not-allowed;transform:none;opacity:1}
				.ajcore-portal-shell .aj-customer-portal-tabs{
					display:flex;
					align-items:center;
					gap:8px;
					margin:0 0 18px;
					padding:6px;
					overflow-x:auto;
					-webkit-overflow-scrolling:touch;
					scrollbar-width:none;
					border:1px solid rgba(219,231,243,.9);
					border-radius:22px;
					background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(255,255,255,.78));
					box-shadow:var(--ajp-shadow-soft);
					backdrop-filter:blur(18px);
				}
				.ajcore-portal-shell .aj-customer-portal-tabs::-webkit-scrollbar{display:none}
				.ajcore-portal-shell .aj-customer-portal-tab{
					position:relative;
					flex:0 0 auto;
					display:inline-flex;
					align-items:center;
					justify-content:center;
					min-height:40px;
					padding:9px 14px;
					border-radius:15px;
					color:#475569;
					font-size:15px;
					font-weight:950;
					letter-spacing:-.02em;
					text-decoration:none;
					transition:background .18s ease,color .18s ease,transform .18s ease,box-shadow .18s ease;
				}
				.ajcore-portal-shell .aj-customer-portal-tab:hover{background:#eef4ff;color:#1d4ed8;transform:translateY(-1px)}
				.ajcore-portal-shell .aj-customer-portal-tab.is-active{background:linear-gradient(135deg,#3157ff 0%,#713df2 100%);color:#fff;box-shadow:0 10px 24px rgba(49,87,255,.22)}
				.ajcore-portal-shell .aj-customer-portal-logout{margin-left:auto;background:#f8fafc;border:1px solid #e2e8f0;color:#334155}
				.ajcore-portal-shell .aj-customer-portal-logout:hover{background:#fff1f2;border-color:#fecdd3;color:#be123c}
				.ajcore-portal-shell .aj-customer-portal-panel{position:relative;margin:0;padding:0;min-height:0;animation:ajp-fade-up .28s ease both}
				.ajcore-portal-shell .aj-customer-portal-panel>h2{margin:0 0 16px}
				@keyframes ajp-fade-up{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

				.ajcore-portal-shell .aj-portal-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin:0 0 24px;width:100%}
				.ajcore-portal-shell .aj-portal-summary-card{
					position:relative;
					overflow:hidden;
					display:flex;
					min-height:88px;
					flex-direction:column;
					justify-content:space-between;
					gap:14px;
					padding:16px 18px;
					border:1px solid rgba(219,231,243,.92);
					border-radius:26px;
					background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(248,251,255,.88));
					box-shadow:0 22px 58px rgba(15,23,42,.075);
					backdrop-filter:blur(12px);
				}
				.ajcore-portal-shell .aj-portal-summary-card:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#3157ff,#7c3aed,#06b6d4)}
				.ajcore-portal-shell .aj-portal-summary-card:after{content:"";position:absolute;right:-32px;bottom:-44px;width:122px;height:122px;border-radius:999px;background:radial-gradient(circle,rgba(49,87,255,.10),transparent 68%)}
				.ajcore-portal-shell a.aj-portal-summary-card{text-decoration:none;color:inherit;transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}
				.ajcore-portal-shell a.aj-portal-summary-card:hover{border-color:#93c5fd;box-shadow:0 30px 70px rgba(37,99,235,.16);transform:translateY(-4px)}
				.ajcore-portal-shell .aj-portal-summary-card strong{position:relative;z-index:1;color:#475569;font-size:14px;font-weight:900}
				.ajcore-portal-shell .aj-portal-summary-card span{position:relative;z-index:1;font-size:30px;line-height:1;font-weight:950;letter-spacing:-.055em;color:var(--ajp-ink)}

				.ajcore-portal-shell .aj-portal-services-list{display:grid;gap:12px;margin:0 0 20px;width:100%;max-width:none}
				.ajcore-portal-shell .aj-portal-service-card{
					position:relative;
					overflow:hidden;
					border:1px solid rgba(219,231,243,.95);
					border-radius:22px;
					background:radial-gradient(circle at 100% 0%,rgba(124,58,237,.14),transparent 28%),linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
					padding:16px 18px;
					box-shadow:0 14px 34px rgba(15,23,42,.06);
				}
				.ajcore-portal-shell .aj-portal-service-card:before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-portal-service-card-past{background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);box-shadow:0 14px 36px rgba(15,23,42,.055)}
				.ajcore-portal-shell .aj-portal-service-card-past:before{background:linear-gradient(90deg,#94a3b8,#cbd5e1)}
				.ajcore-portal-shell .aj-portal-service-card h4{margin:0 0 12px;font-size:19px;line-height:1.14;letter-spacing:-.04em;color:#111827;text-wrap:balance}
				.ajcore-portal-shell .aj-portal-service-card-grid{display:grid;grid-template-columns:minmax(220px,1fr) minmax(95px,.45fr) minmax(150px,.7fr) minmax(145px,.65fr) minmax(110px,.45fr);gap:12px;align-items:start}
				.ajcore-portal-shell .aj-portal-service-card-grid div{min-width:0}
				.ajcore-portal-shell .aj-portal-service-card-grid strong{display:block;font-size:11px;color:#64748b;margin-bottom:5px;text-transform:uppercase;letter-spacing:.07em;font-weight:950}
				.ajcore-portal-shell .aj-portal-service-card-grid span{display:block;color:#0f172a;font-weight:900;font-size:14px;line-height:1.35;overflow-wrap:anywhere}

				.ajcore-portal-shell .aj-portal-add-service-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin:0 0 18px;width:100%;max-width:none}
				.ajcore-portal-shell .aj-portal-add-service-card{
					position:relative;
					overflow:hidden;
					min-height:0;
					display:flex;
					flex-direction:column;
					gap:11px;
					border:1px solid rgba(219,231,243,.95);
					border-radius:22px;
					padding:16px 18px;
					background:linear-gradient(180deg,rgba(255,255,255,.96) 0%,rgba(248,251,255,.92) 100%);
					box-shadow:0 12px 30px rgba(15,23,42,.055);
					transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
				}
				.ajcore-portal-shell .aj-portal-add-service-card:after{content:"";position:absolute;right:-72px;top:-84px;width:158px;height:158px;border-radius:999px;background:radial-gradient(circle,rgba(124,58,237,.10),transparent 70%)}
				.ajcore-portal-shell .aj-portal-add-service-card:hover{transform:translateY(-2px);border-color:#bfdbfe;box-shadow:0 18px 44px rgba(37,99,235,.11)}
				.ajcore-portal-shell .aj-portal-add-service-card h4{position:relative;z-index:1;margin:0;font-size:18px;line-height:1.18;letter-spacing:-.035em;color:#111827;text-wrap:balance}
				.ajcore-portal-shell .aj-portal-add-service-card p{position:relative;z-index:1;margin:0;color:#475569;line-height:1.45;font-size:14px}
				.ajcore-portal-shell .aj-portal-add-service-price{position:relative;z-index:1;margin-top:auto;font-weight:950;color:#111827;font-size:16px;letter-spacing:-.02em}
				.ajcore-portal-shell .aj-portal-add-service-price-choice{position:relative;z-index:1;display:grid;gap:7px;margin:4px 0 0;color:#475569;font-weight:850;font-size:13px;text-transform:uppercase;letter-spacing:.04em}
				.ajcore-portal-shell .aj-portal-add-service-price-choice select{width:100%;min-height:46px;border:1px solid #dbe7f3;border-radius:14px;background:#fff;color:#111827;padding:0 12px;font-size:15px;font-weight:800;text-transform:none;letter-spacing:0}
				.ajcore-portal-shell .aj-portal-add-service-requires,.ajcore-portal-shell .aj-portal-add-service-dependency-note{position:relative;z-index:1;padding:10px 12px;border-radius:14px;font-size:13px;font-weight:800;line-height:1.4}
				.ajcore-portal-shell .aj-portal-add-service-requires{border:1px solid #dbeafe;background:#eff6ff;color:#1e40af}
				.ajcore-portal-shell .aj-portal-add-service-dependency-note{border:1px solid #fed7aa;background:#fff7ed;color:#9a3412}
				.ajcore-portal-shell .aj-portal-add-service-card .button{position:relative;z-index:1;align-self:flex-start}
				.ajcore-portal-shell .aj-portal-add-service-message{border:1px solid #dbe7f3;border-radius:18px;padding:15px 16px;background:#fff;color:#1f2937;box-shadow:0 10px 24px rgba(15,23,42,.05)}
				.ajcore-portal-shell .aj-portal-add-service-message.is-error{border-color:#fecaca;color:#b91c1c;background:#fff7f7}
				.ajcore-portal-shell .aj-portal-add-service-message.is-success{border-color:#bbf7d0;color:#166534;background:#f0fdf4}
				.ajcore-portal-shell .aj-portal-service-cart{margin:0 0 20px}
				.ajcore-portal-shell .aj-portal-service-cart-bar{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #dbeafe;border-radius:18px;background:rgba(255,255,255,.96);box-shadow:0 14px 34px rgba(15,23,42,.08)}
				.ajcore-portal-shell .aj-portal-service-cart-toggle{display:inline-flex;align-items:center;gap:9px;border:0;border-radius:999px;background:#4f46e5;color:#fff;padding:10px 15px;font-weight:950;cursor:pointer}
				.ajcore-portal-shell .aj-portal-service-cart-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;border-radius:999px;background:#fff;color:#4f46e5;font-size:13px}
				.ajcore-portal-shell .aj-portal-service-cart-status{flex:1;color:#475569;font-size:14px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
				.ajcore-portal-shell .aj-portal-service-cart-clear,.ajcore-portal-shell .aj-portal-service-cart-checkout,.ajcore-portal-shell .aj-portal-service-checkout-close{border:0;border-radius:999px;padding:10px 14px;font-weight:950;cursor:pointer}
					.ajcore-portal-shell .aj-portal-service-cart-clear{background:#f1f5f9;color:#475569}
					.ajcore-portal-shell .aj-portal-service-cart-checkout{background:#0f7ac6;color:#fff}
					.ajcore-portal-shell .aj-portal-service-cart-clear:disabled,.ajcore-portal-shell .aj-portal-service-cart-checkout:disabled{opacity:.5;cursor:not-allowed}
					.ajcore-portal-shell .aj-portal-service-cart-notice{margin-top:10px;padding:11px 13px;border:1px solid #fed7aa;border-radius:14px;background:#fff7ed;color:#9a3412;font-size:13px;font-weight:850;line-height:1.4}
					.ajcore-portal-shell .aj-portal-service-cart-panel,.ajcore-portal-shell .aj-portal-service-checkout-panel{margin-top:14px;border:1px solid #dbe7f3;border-radius:22px;background:#fff;padding:18px;box-shadow:0 18px 48px rgba(15,23,42,.08)}
				.ajcore-portal-shell .aj-portal-service-cart-items{display:grid;gap:10px}
				.ajcore-portal-shell .aj-portal-service-cart-row{display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:13px;border:1px solid #e2e8f0;border-radius:16px;background:#fff}
				.ajcore-portal-shell .aj-portal-service-cart-row strong{display:block;color:#0f172a;font-size:15px;line-height:1.25}
				.ajcore-portal-shell .aj-portal-service-cart-row span{display:block;margin-top:4px;color:#475569;font-weight:850}
				.ajcore-portal-shell .aj-portal-service-cart-row small{display:block;margin-top:5px;color:#9a3412;font-weight:800;line-height:1.35}
				.ajcore-portal-shell .aj-portal-service-cart-row button{border:1px solid #fecaca;background:#fff;color:#b91c1c;border-radius:10px;padding:8px 11px;font-weight:850;cursor:pointer}
				.ajcore-portal-shell .aj-portal-service-cart-empty{color:#64748b;font-weight:800}
				.ajcore-portal-shell .aj-portal-service-cart-total{margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb;font-size:22px;font-weight:950;color:#111827}
				.ajcore-portal-shell .aj-portal-mixed-checkout-review{margin-top:12px;padding:12px;border:1px solid #fed7aa;border-radius:14px;background:#fff7ed;color:#7c2d12;font-size:13px;font-weight:800;line-height:1.4}
				.ajcore-portal-shell .aj-portal-mixed-checkout-review strong{display:block;margin-bottom:7px;color:#111827;font-size:15px}
				.ajcore-portal-shell .aj-portal-mixed-checkout-review p{margin:4px 0}
				.ajcore-portal-shell .aj-portal-service-checkout-header{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}
				.ajcore-portal-shell .aj-portal-service-checkout-header h4{margin:0;font-size:22px;color:#111827}
				.ajcore-portal-shell .aj-portal-service-checkout-close{background:#f1f5f9;color:#475569}

				.ajcore-portal-shell .aj-portal-table-wrap{overflow:auto;margin:0 0 28px;border-radius:22px;border:1px solid rgba(219,231,243,.95);background:rgba(255,255,255,.88);box-shadow:0 18px 46px rgba(15,23,42,.07);backdrop-filter:blur(12px);width:100%;max-width:none}
				.ajcore-portal-shell .aj-portal-table{width:100%;border-collapse:separate;border-spacing:0;background:transparent;border:0;font-size:15px;min-width:760px}
				.ajcore-portal-shell .aj-portal-table th,.ajcore-portal-shell .aj-portal-table td{padding:14px 18px;border-bottom:1px solid #e8eef6;text-align:left;vertical-align:top}
				.ajcore-portal-shell .aj-portal-table tr:last-child td{border-bottom:0}
				.ajcore-portal-shell .aj-portal-table th{font-size:13px;font-weight:950;color:#475569;background:#f8fbff;text-transform:uppercase;letter-spacing:.065em}
				.ajcore-portal-shell .aj-portal-table td{color:#0f172a;line-height:1.55}
				.ajcore-portal-shell .aj-portal-table tbody tr{transition:background .16s ease}
				.ajcore-portal-shell .aj-portal-table tbody tr:hover{background:rgba(248,251,255,.72)}
				.ajcore-portal-shell .aj-portal-task-meta{margin-top:6px;color:#64748b;font-size:12px;font-weight:850;text-transform:uppercase;letter-spacing:.055em}
				.ajcore-portal-shell .aj-portal-task-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:950;background:#eff6ff;color:#1d4ed8}
				.ajcore-portal-shell .aj-portal-task-status-completed{background:#dcfce7;color:#166534}
				.ajcore-portal-shell .aj-portal-task-status-cancelled,.ajcore-portal-shell .aj-portal-task-status-closed{background:#fee2e2;color:#991b1b}
				.ajcore-portal-shell .aj-portal-task-comments{display:grid;gap:8px;margin:0 0 10px}
				.ajcore-portal-shell .aj-portal-task-comment{border:1px solid #e8eef6;border-radius:14px;background:#fff;padding:10px 12px;font-size:13px;line-height:1.45;color:#1f2937}
				.ajcore-portal-shell .aj-portal-task-comment span{display:block;margin-top:6px;color:#64748b;font-size:11px;font-weight:800}
				.ajcore-portal-shell .aj-portal-task-drawer{min-width:260px}
				.ajcore-portal-shell .aj-portal-task-drawer summary{display:inline-flex;align-items:center;min-height:36px;padding:8px 14px;border:1px solid #dbe7f3;border-radius:999px;background:#f8fbff;color:#1d4ed8;font-size:13px;font-weight:900;cursor:pointer;list-style:none}
				.ajcore-portal-shell .aj-portal-task-drawer summary::-webkit-details-marker{display:none}
				.ajcore-portal-shell .aj-portal-task-drawer summary:before{content:"+";margin-right:7px;font-weight:950}
				.ajcore-portal-shell .aj-portal-task-drawer[open] summary{border-color:#bfdbfe;background:#eff6ff}
				.ajcore-portal-shell .aj-portal-task-drawer[open] summary:before{content:"–"}
				.ajcore-portal-shell .aj-portal-task-drawer[open]{width:100%}
				.ajcore-portal-shell .aj-portal-task-form{display:grid;gap:9px;min-width:260px;margin-top:10px}
				.ajcore-portal-shell .aj-portal-task-form textarea{width:100%;border:1px solid #dbe7f3;border-radius:14px;padding:10px 12px;background:#fff;min-height:72px;font:inherit;color:#0f172a}
				.ajcore-portal-shell .aj-portal-task-actions{display:flex;gap:8px;flex-wrap:wrap}
				.ajcore-portal-shell .aj-portal-task-comment-button{min-height:38px;padding:9px 15px;font-size:13px;background:linear-gradient(135deg,#2563eb 0%,#4f46e5 100%)}
				.ajcore-portal-shell .aj-portal-task-complete-button{min-height:38px;padding:9px 15px;font-size:13px;background:linear-gradient(135deg,#16a34a 0%,#10b981 100%)}

				.ajcore-portal-shell .aj-portal-profile-block{border:1px solid rgba(219,231,243,.95);border-radius:28px;background:radial-gradient(circle at 100% 0%,rgba(37,99,235,.12),transparent 34%),linear-gradient(180deg,#fff 0%,#f8fbff 100%);padding:30px;width:min(860px,100%);box-shadow:0 22px 54px rgba(15,23,42,.08)}
				.ajcore-portal-shell .aj-portal-profile-main{font-size:clamp(30px,4vw,42px);line-height:1.02;font-weight:950;color:#111827;margin:0 0 20px;letter-spacing:-.06em}
				.ajcore-portal-shell .aj-portal-profile-details{display:grid;gap:12px;color:#1f2937;font-size:17px;line-height:1.5;margin:0 0 26px}
				.ajcore-portal-shell .aj-portal-profile-actions{display:flex;gap:12px;flex-wrap:wrap}

				.ajcore-portal-shell .aj-customer-file-list{overflow:hidden;border:1px solid rgba(219,231,243,.95);border-radius:28px;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:var(--ajp-shadow);position:relative}
				.ajcore-portal-shell .aj-customer-file-list:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-customer-file-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:center;padding:22px 26px;border-top:1px solid #e8eef6}
				.ajcore-portal-shell .aj-customer-file-row:first-child{border-top:0;padding-top:28px}
				.ajcore-portal-shell .aj-customer-file-row:hover{background:rgba(248,251,255,.9)}
				.ajcore-portal-shell .aj-customer-file-title{font-size:clamp(18px,1.5vw,24px);line-height:1.15;font-weight:950;letter-spacing:-.04em;color:#111827;overflow-wrap:anywhere}
				.ajcore-portal-shell .aj-customer-file-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.065em}
				.ajcore-portal-shell .aj-customer-file-meta span{display:inline-flex;align-items:center;border:1px solid #dbe7f3;background:#f8fbff;border-radius:999px;padding:5px 9px}
				.ajcore-portal-shell .aj-customer-file-main p{margin:10px 0 0;color:#52616f}
				.ajcore-portal-shell .aj-customer-file-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px}

				.ajcore-portal-shell .aj-portal-quick-actions{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px}
				.ajcore-portal-shell .aj-portal-inline-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
				.ajcore-portal-shell .aj-portal-action-button{min-height:38px;padding:10px 17px;font-size:14px;box-shadow:none}
				.ajcore-portal-shell .aj-portal-action-resume{min-height:48px;padding:13px 24px;background:linear-gradient(135deg,#16a34a 0%,#10b981 100%)!important;color:#fff!important;box-shadow:0 18px 36px rgba(16,185,129,.24)!important}
				.ajcore-portal-shell .aj-portal-action-cancel{min-height:38px;padding:9px 16px;background:#fee2e2!important;color:#991b1b!important;border:1px solid #fecaca!important;box-shadow:none!important}

				.ajcore-portal-shell .aj-portal-empty-state{position:relative;overflow:hidden;border:1px solid rgba(219,231,243,.95);border-radius:28px;padding:30px;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:0 24px 64px rgba(15,23,42,.075);width:min(900px,100%)}
				.ajcore-portal-shell .aj-portal-empty-state:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-portal-empty-state strong{display:block;margin:0 0 8px;font-size:22px;letter-spacing:-.035em;color:#111827}
				.ajcore-portal-shell .aj-portal-empty-state p{margin:0;color:#526173}
				.ajcore-portal-shell .aj-portal-notice{margin:0 0 18px;padding:14px 16px;border-radius:18px;font-weight:800;border:1px solid rgba(219,231,243,.95);background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.045)}
				.ajcore-portal-shell .aj-portal-notice.is-success{color:#166534;background:#ecfdf5;border-color:#bbf7d0}
				.ajcore-portal-shell .aj-portal-notice.is-error{color:#991b1b;background:#fef2f2;border-color:#fecaca}
				.ajcore-portal-shell .aj-portal-upload-drawer{margin:0 0 22px}
				.ajcore-portal-shell .aj-portal-upload-drawer[open]{margin-bottom:24px}
				.ajcore-portal-shell .aj-portal-upload-toggle{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:13px 22px;border-radius:999px;background:linear-gradient(135deg,#2563eb 0%,#7c3aed 100%);color:#fff;font-weight:950;cursor:pointer;box-shadow:0 18px 38px rgba(79,70,229,.22);list-style:none}
				.ajcore-portal-shell .aj-portal-upload-toggle::-webkit-details-marker{display:none}
				.ajcore-portal-shell .aj-portal-upload-card{display:grid;grid-template-columns:minmax(280px,.9fr) minmax(360px,1.1fr);gap:22px;align-items:end;margin:18px 0 0;padding:26px;border:1px solid rgba(219,231,243,.95);border-radius:28px;background:linear-gradient(135deg,rgba(255,255,255,.94) 0%,rgba(248,251,255,.92) 100%);box-shadow:var(--ajp-shadow-soft);position:relative;overflow:hidden}
				.ajcore-portal-shell .aj-portal-upload-card:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-portal-upload-card h3{margin:0 0 8px;font-size:24px;line-height:1.1;letter-spacing:-.04em}
				.ajcore-portal-shell .aj-portal-upload-card p{margin:0;color:#526173;line-height:1.55}
				.ajcore-portal-shell .aj-portal-upload-form{display:grid;grid-template-columns:1fr;gap:12px}
				.ajcore-portal-shell .aj-portal-upload-form input[type="text"],.ajcore-portal-shell .aj-portal-upload-form input[type="file"]{width:100%;border:1px solid #dbe7f3;border-radius:16px;padding:12px 14px;background:#fff;color:#0f172a;font-weight:700}

				.ajcore-portal-shell .aj-portal-tab-intro{margin:-8px 0 24px;color:#475569;font-size:clamp(16px,1.3vw,19px);line-height:1.7;max-width:880px}
				.ajcore-portal-shell .aj-portal-account-summary{margin:30px 0 0;padding:28px 32px;border:1px solid rgba(191,219,254,.9);border-radius:28px;background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(239,246,255,.72));box-shadow:0 22px 60px rgba(15,23,42,.07)}
				.ajcore-portal-shell .aj-portal-account-summary h3{margin:0 0 12px;color:#0f172a;font-size:clamp(22px,1.8vw,30px)}
				.ajcore-portal-shell .aj-portal-account-summary p{margin:10px 0 0;color:#172033;font-size:clamp(16px,1.25vw,19px);line-height:1.75}


				@media (min-width:1280px){
					.ajcore-portal-shell .aj-customer-portal-tabs{width:100%;max-width:none;margin-left:0;margin-right:0}
					.ajcore-portal-shell .aj-customer-portal-panel{width:100%;max-width:none;margin-left:0;margin-right:0}
					.ajcore-portal-shell .aj-portal-summary-grid{max-width:none}
				}
				@media (min-width:1536px){
					.ajcore-portal-shell{width:calc(100vw - 64px);margin-left:calc(50% - 50vw + 32px)}
				}
				.ajcore-portal-shell .aj-customer-portal-panel>p:only-child,
				.ajcore-portal-shell .aj-customer-portal-panel>p:nth-child(2):last-child{
					position:relative;
					overflow:hidden;
					width:min(900px,100%);
					max-width:none;
					margin:0;
					padding:30px;
					border:1px solid rgba(219,231,243,.95);
					border-radius:28px;
					background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);
					box-shadow:0 24px 64px rgba(15,23,42,.075);
				}
				.ajcore-portal-shell .aj-customer-portal-panel>p:only-child:before,
				.ajcore-portal-shell .aj-customer-portal-panel>p:nth-child(2):last-child:before{
					content:"";
					position:absolute;
					inset:0 0 auto;
					height:5px;
					background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed);
				}

				@media (max-width:1050px){
					.ajcore-portal-shell .aj-portal-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
					.ajcore-portal-shell .aj-portal-service-card-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
				}
				@media (max-width:760px){
					.ajcore-portal-shell{width:auto;max-width:none;margin:18px auto 0;padding:0 14px 36px}
					.ajcore-portal-shell:before{inset:-44px -20px auto;height:260px}
					.ajcore-portal-shell h2{font-size:27px;margin-bottom:16px}
					.ajcore-portal-shell h3{font-size:19px;margin:24px 0 12px}
					.ajcore-portal-shell p{font-size:15px}
					.ajcore-portal-shell .aj-portal-tab-intro{margin:-4px 0 18px}
					.ajcore-portal-shell .aj-customer-portal-tabs{
						position:sticky;
						top:8px;
						z-index:20;
						display:flex;
						flex-wrap:nowrap;
						gap:6px;
						margin:0 0 22px;
						border-radius:18px;
						padding:6px;
						overflow-x:auto;
						overscroll-behavior-x:contain;
					}
					.ajcore-portal-shell .aj-customer-portal-tab{
						flex:0 0 auto;
						min-height:42px;
						padding:9px 14px;
						font-size:14px;
						border-radius:14px;
						white-space:nowrap;
					}
					.ajcore-portal-shell .aj-customer-portal-logout{margin-left:auto}
					.ajcore-portal-shell .aj-portal-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
					.ajcore-portal-shell .aj-portal-summary-card{min-height:84px;border-radius:20px;padding:14px 16px;gap:8px}
					.ajcore-portal-shell .aj-portal-summary-card span{font-size:26px}
					.ajcore-portal-shell .aj-portal-summary-card strong{font-size:13px}
					.ajcore-portal-shell .aj-portal-service-card,.ajcore-portal-shell .aj-portal-add-service-card,.ajcore-portal-shell .aj-portal-profile-block{border-radius:20px;padding:16px}
					.ajcore-portal-shell .aj-portal-service-card h4{font-size:17px;margin-bottom:10px}
					.ajcore-portal-shell .aj-portal-service-card-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 14px}
					.ajcore-portal-shell .aj-portal-service-card-grid div:first-child{grid-column:1 / -1}
					.ajcore-portal-shell .aj-portal-add-service-grid{grid-template-columns:1fr}
					.ajcore-portal-shell .aj-portal-account-summary{margin-top:22px;padding:18px 16px;border-radius:20px}
					.ajcore-portal-shell .aj-portal-account-summary p{font-size:15px;line-height:1.6}
					.ajcore-portal-shell .aj-portal-open-balance{padding:16px;border-radius:18px}
					.ajcore-portal-shell .aj-portal-payment-box{width:100%}
					.ajcore-portal-shell .aj-portal-payment-box label{width:100%;min-width:0}
					.ajcore-portal-shell .aj-portal-payment-box .aj-portal-payment-amount-input{width:100%!important}
					.ajcore-portal-shell .aj-portal-payment-box .button{width:100%}
					.ajcore-portal-shell .aj-portal-table-wrap{border-radius:20px}
					.ajcore-portal-shell .aj-portal-table{min-width:0;font-size:15px}
					.ajcore-portal-shell .aj-portal-table thead{display:none}
					.ajcore-portal-shell .aj-portal-table tbody,.ajcore-portal-shell .aj-portal-table tr,.ajcore-portal-shell .aj-portal-table td{display:block;width:100%}
					.ajcore-portal-shell .aj-portal-table tr{border-bottom:1px solid #e8eef6;padding:12px 0}
					.ajcore-portal-shell .aj-portal-table tr:last-child{border-bottom:0}
					.ajcore-portal-shell .aj-portal-table td{border:0;padding:7px 16px}
					.ajcore-portal-shell .aj-portal-table td:first-child{font-weight:900;color:#0f172a}
					.ajcore-portal-shell .aj-portal-table td.aj-portal-td-empty{display:none}
					.ajcore-portal-shell .aj-portal-table td[data-label]:before{content:attr(data-label);display:block;margin-bottom:2px;font-size:11px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.06em}
					.ajcore-portal-shell .aj-portal-upload-card{grid-template-columns:1fr;padding:22px;border-radius:24px}
					.ajcore-portal-shell .aj-customer-file-row{grid-template-columns:1fr;gap:14px;padding:18px}
					.ajcore-portal-shell .aj-customer-file-actions{justify-content:flex-start}
					.ajcore-portal-shell .aj-portal-quick-actions-heading,.ajcore-portal-shell .aj-portal-quick-actions{display:none}
				}
			</style>
			<?php if ( 'yes' === $atts['show_title'] ) : ?>
				<h1><?php esc_html_e( 'Client Portal', 'ajforms' ); ?></h1>
			<?php endif; ?>
			<?php $impersonation = $this->get_active_impersonation_payload(); ?>
			<?php if ( $impersonation ) : ?>
				<div class="ajcore-impersonation-banner" style="display:flex;align-items:center;justify-content:space-between;gap:14px;margin:0 0 18px;padding:12px 16px;border:1px solid #f59e0b;border-radius:10px;background:#fffbeb;color:#92400e;font-weight:700;">
					<span><?php echo esc_html( sprintf( __( 'Viewing as client: %s.', 'ajforms' ), ! empty( $impersonation['customer_name'] ) ? $impersonation['customer_name'] : __( 'Client', 'ajforms' ) ) ); ?></span>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'ajcore_end_impersonation', rawurlencode( $impersonation['return_token'] ), $this->get_customer_portal_url() ) ); ?>"><?php esc_html_e( 'Return to Admin', 'ajforms' ); ?></a>
				</div>
			<?php endif; ?>
			<nav class="aj-customer-portal-tabs" aria-label="<?php esc_attr_e( 'Client Portal', 'ajforms' ); ?>">
				<?php foreach ( $portal_items as $item ) : ?>
					<?php
					$is_active = $active_tab === $item['id'];
					$item_url  = 'custom' === $item['type'] && ! empty( $item['url'] )
						? $item['url']
						: add_query_arg( 'portal_tab', $item['id'], $this->get_customer_portal_url() );
					?>
					<a class="aj-customer-portal-tab <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $item_url ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				<?php endforeach; ?>
				<a class="aj-customer-portal-tab aj-customer-portal-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Logout', 'ajforms' ); ?></a>
			</nav>

			<?php
			if ( 'overview' === $active_tab ) {
				echo $this->render_customer_portal_overview_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'services' === $active_tab ) {
				echo $this->render_customer_portal_services_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'tasks' === $active_tab ) {
				echo $this->render_customer_portal_tasks_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'billing' === $active_tab ) {
				echo $this->render_customer_portal_billing_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'file-library' === $active_tab ) {
				echo $this->render_customer_portal_file_library_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'profile' === $active_tab ) {
				echo $this->render_customer_portal_profile_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'service-requests' === $active_tab ) {
				echo $this->render_customer_portal_service_requests_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'reservations' === $active_tab ) {
				echo $this->render_customer_portal_reservations_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php if ( 'services' === $active_tab || 'reservations' === $active_tab ) : ?>
				<script src="https://js.stripe.com/v3/"></script>
			<?php endif; ?>
			<script>
			(function() {
				const shell = document.currentScript.closest('.ajcore-portal-shell');
				if (!shell || shell.dataset.ajcorePortalReady) {
					return;
				}
				shell.dataset.ajcorePortalReady = '1';

				const tabsNav = shell.querySelector('.aj-customer-portal-tabs');
				const activeTab = tabsNav ? tabsNav.querySelector('.aj-customer-portal-tab.is-active') : null;
				if (tabsNav && activeTab && tabsNav.scrollWidth > tabsNav.clientWidth) {
					tabsNav.scrollLeft = Math.max(0, activeTab.offsetLeft - (tabsNav.clientWidth - activeTab.offsetWidth) / 2);
				}

				function parseJsonResponse(response) {
					return response.text().then(function(text) {
						try {
							return JSON.parse(text);
						} catch (error) {
							throw new Error('<?php echo esc_js( __( 'The server returned an invalid response.', 'ajforms' ) ); ?>');
						}
					});
				}

				shell.addEventListener('click', function(event) {
					const payButton = event.target.closest('.aj-portal-pay-ledger-button');
					if (!payButton || payButton.disabled) {
						return;
					}

					const paymentMode = payButton.dataset.paymentMode || '';
					let paymentAmount = payButton.dataset.paymentAmount || '';
					if (paymentMode === 'custom' || paymentMode === 'balance') {
						const paymentBox = payButton.closest('.aj-portal-payment-box');
						const amountInput = paymentBox ? paymentBox.querySelector('.aj-portal-payment-amount-input') : null;
						const enteredAmount = amountInput ? amountInput.value : paymentAmount;

						if (String(enteredAmount).indexOf('-') !== -1) {
							window.alert('<?php echo esc_js( __( 'Negative payment amounts are not allowed.', 'ajforms' ) ); ?>');
							if (amountInput) {
								amountInput.focus();
							}
							return;
						}

						paymentAmount = String(enteredAmount).replace(/[^0-9.]/g, '');
						const numericAmount = parseFloat(paymentAmount);
						if (!Number.isFinite(numericAmount) || numericAmount <= 0) {
							window.alert('<?php echo esc_js( __( 'Enter a payment amount greater than $0.00. Negative amounts are not allowed.', 'ajforms' ) ); ?>');
							if (amountInput) {
								amountInput.focus();
							}
							return;
						}

						paymentAmount = numericAmount.toFixed(2);
						if (amountInput) {
							amountInput.value = paymentAmount;
						}
					}

					payButton.disabled = true;
					const originalText = payButton.textContent;
					payButton.textContent = '<?php echo esc_js( __( 'Loading...', 'ajforms' ) ); ?>';

					const formData = new FormData();
					formData.append('action', 'ajcore_pay_portal_ledger');
					formData.append('ledger_ids', payButton.dataset.ledgerIds || '');
					formData.append('payment_amount', paymentAmount || '');
					formData.append('payment_currency', payButton.dataset.paymentCurrency || 'usd');
					formData.append('nonce', payButton.dataset.nonce || '');
					formData.append('current_url', window.location.href);

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success || !payload.data || !payload.data.url) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to start payment.', 'ajforms' ) ); ?>');
							}
							window.location.href = payload.data.url;
						})
						.catch(function(error) {
							payButton.disabled = false;
							payButton.textContent = originalText;
							window.alert(error.message || '<?php echo esc_js( __( 'Unable to start payment.', 'ajforms' ) ); ?>');
						});
				});

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-add-service-button');
					if (!button || button.disabled) {
						return;
					}
					event.preventDefault();
					event.stopPropagation();

					const message = shell.querySelector('.aj-portal-add-service-message');
					if (message) {
						message.textContent = '';
						message.className = 'aj-portal-add-service-message';
						message.style.display = 'none';
					}

					preservePortalCartScroll(function() {
						addPortalServiceToCart(button.closest('.aj-portal-add-service-product'));
					});
				});

				const portalCartWrap = shell.querySelector('.aj-portal-service-cart');
				const portalCart = {};
				let embeddedCheckout = null;
				let stripeInstance = null;

				function preservePortalCartScroll(callback) {
					const scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
					const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
					callback();
					const restore = function() {
						window.scrollTo(scrollX, scrollY);
					};
					restore();
					window.setTimeout(restore, 0);
					window.requestAnimationFrame(restore);
					window.requestAnimationFrame(function() {
						window.requestAnimationFrame(restore);
					});
				}

				function getPortalPriceOption(card) {
					const input = card ? card.querySelector('.aj-portal-add-service-price-select') : null;
					if (!input) {
						return null;
					}
					if (input.tagName && input.tagName.toLowerCase() === 'select') {
						return input.options[input.selectedIndex] || null;
					}
					return input;
				}

				function getPortalItemFromOption(option, card) {
					if (!option || !option.value) {
						return null;
					}
					return {
						price_id: option.value,
						product_name: option.dataset.productName || (card ? card.querySelector('h4')?.textContent : '') || '<?php echo esc_js( __( 'Service', 'ajforms' ) ); ?>',
						amount: parseFloat(option.dataset.amount || '0') || 0,
						currency: option.dataset.currency || 'usd',
						recurring_interval: option.dataset.recurringInterval || '',
						price_label: option.dataset.priceLabel || '',
						required_price_id: option.dataset.requiredPriceId || '',
						required_product_name: option.dataset.requiredProductName || '',
						dependency_note: option.dataset.dependencyNote || '',
						required_amount: parseFloat(option.dataset.requiredAmount || '0') || 0,
						required_currency: option.dataset.requiredCurrency || 'usd',
						required_recurring_interval: option.dataset.requiredRecurringInterval || '',
						required_price_label: option.dataset.requiredPriceLabel || '',
						can_add: option.dataset.canAdd === '1'
					};
				}

				function formatPortalMoney(amount, currency) {
					try {
						return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format(amount || 0);
					} catch (error) {
						return '$' + (Number(amount || 0)).toFixed(2);
					}
				}

				function getPortalCartItemLabel(item) {
					if (item.price_label) {
						return item.price_label;
					}
					let label = formatPortalMoney(item.amount, item.currency);
					if (item.recurring_interval) {
						label += ' /' + item.recurring_interval;
					}
					return label;
				}

				function getPortalCartSignature(items) {
					return (items || Object.values(portalCart)).map(function(item) {
						return (item.price_id || '') + ':' + (parseInt(item.quantity, 10) || 1);
					}).sort().join('|');
				}

				function getPortalMixedCartBreakdown(items) {
					const breakdown = {
						total: 0,
						one_time_total: 0,
						recurring_total: 0,
						currency: 'usd',
						interval: '',
						has_one_time: false,
						has_recurring: false
					};
					(items || Object.values(portalCart)).forEach(function(item) {
						const amount = (parseFloat(item.amount || '0') || 0) * (parseInt(item.quantity, 10) || 1);
						breakdown.total += amount;
						breakdown.currency = item.currency || breakdown.currency;
						if (item.recurring_interval) {
							breakdown.has_recurring = true;
							breakdown.recurring_total += amount;
							breakdown.interval = breakdown.interval || item.recurring_interval;
						} else {
							breakdown.has_one_time = true;
							breakdown.one_time_total += amount;
						}
					});
					return breakdown;
				}

				function portalCartNeedsMixedCheckoutReview(items) {
					const breakdown = getPortalMixedCartBreakdown(items);
					return breakdown.has_one_time && breakdown.has_recurring;
				}

				function getPortalMixedCheckoutReviewHtml(items) {
					const breakdown = getPortalMixedCartBreakdown(items);
					if (!breakdown.has_one_time || !breakdown.has_recurring) {
						return '';
					}
					const interval = breakdown.interval || '<?php echo esc_js( __( 'year', 'ajforms' ) ); ?>';
					return '<div class="aj-portal-mixed-checkout-review">' +
						'<strong><?php echo esc_js( __( 'Payment review before checkout', 'ajforms' ) ); ?></strong>' +
						'<p><b><?php echo esc_js( __( 'Today:', 'ajforms' ) ); ?></b> ' + formatPortalMoney(breakdown.total, breakdown.currency) + ' <?php echo esc_js( __( 'total.', 'ajforms' ) ); ?></p>' +
						'<p><?php echo esc_js( __( 'Includes', 'ajforms' ) ); ?> ' + formatPortalMoney(breakdown.one_time_total, breakdown.currency) + ' <?php echo esc_js( __( 'one-time and', 'ajforms' ) ); ?> ' + formatPortalMoney(breakdown.recurring_total, breakdown.currency) + ' <?php echo esc_js( __( 'per', 'ajforms' ) ); ?> ' + interval + ' <?php echo esc_js( __( 'recurring.', 'ajforms' ) ); ?></p>' +
						'<p><b><?php echo esc_js( __( 'Renewal:', 'ajforms' ) ); ?></b> ' + formatPortalMoney(breakdown.recurring_total, breakdown.currency) + ' <?php echo esc_js( __( 'per', 'ajforms' ) ); ?> ' + interval + ' <?php echo esc_js( __( 'after the first period.', 'ajforms' ) ); ?></p>' +
						'<p><?php echo esc_js( __( 'You will enter payment once. Stripe may show the subscription portion; AJ Core charges the one-time portion immediately after successful checkout using the same payment method.', 'ajforms' ) ); ?></p>' +
						'</div>';
				}

				function getPortalCartSubscriptionIntervals(items) {
					const intervals = [];
					items.forEach(function(item) {
						const interval = String(item.recurring_interval || '').toLowerCase();
						if (interval && intervals.indexOf(interval) === -1) {
							intervals.push(interval);
						}
					});
					return intervals;
				}

				function setPortalCartNotice(message) {
					const notice = portalCartWrap ? portalCartWrap.querySelector('.aj-portal-service-cart-notice') : null;
					if (!notice) {
						return;
					}
					notice.textContent = message || '';
					notice.hidden = !message;
				}

				function getPortalSubscriptionIntervalConflict(itemsToAdd) {
					const combined = Object.values(portalCart);
					itemsToAdd.forEach(function(item) {
						if (item && item.price_id && !portalCart[item.price_id]) {
							combined.push(item);
						}
					});
					return getPortalCartSubscriptionIntervals(combined);
				}

				function showPortalIntervalConflictPopup(intervals) {
					const message = '<?php echo esc_js( __( 'Checkout does not support multiple subscription billing intervals in the same cart. Please checkout monthly and yearly subscriptions separately.', 'ajforms' ) ); ?>';
					setPortalCartNotice(message);
					window.alert(message);
				}

				function findPortalCardByPriceId(priceId) {
					const options = shell.querySelectorAll('.aj-portal-add-service-price-select option, input.aj-portal-add-service-price-select');
					for (let index = 0; index < options.length; index += 1) {
						if (options[index].value === priceId) {
							return options[index].closest('.aj-portal-add-service-product');
						}
					}
					return null;
				}

				function addPortalCartItem(item, locked) {
					if (!item || !item.price_id || !item.can_add) {
						return false;
					}
					portalCart[item.price_id] = Object.assign({}, item, { quantity: 1, locked: locked || '' });
					return true;
				}

				function addPortalServiceToCart(card) {
					const option = getPortalPriceOption(card);
					const item = getPortalItemFromOption(option, card);
					if (!item || !item.can_add) {
						return;
					}

					let notice = '';
					const itemsToAdd = [item];
					if (item.required_price_id && !portalCart[item.required_price_id]) {
						const requiredCard = findPortalCardByPriceId(item.required_price_id);
						const requiredOption = getPortalPriceOption(requiredCard);
						const requiredItem = getPortalItemFromOption(requiredOption, requiredCard);
						if (requiredItem && requiredItem.price_id === item.required_price_id && requiredItem.can_add) {
							itemsToAdd.unshift(requiredItem);
							notice = item.dependency_note || '';
						} else if (item.required_amount > 0) {
							itemsToAdd.unshift({
								price_id: item.required_price_id,
								product_name: item.required_product_name || '<?php echo esc_js( __( 'Required service', 'ajforms' ) ); ?>',
								amount: item.required_amount,
								currency: item.required_currency || item.currency || 'usd',
								recurring_interval: item.required_recurring_interval || '',
								price_label: item.required_price_label || '',
								required_price_id: '',
								required_product_name: '',
								dependency_note: '',
								can_add: true
							});
							notice = item.dependency_note || '';
						} else if (item.dependency_note) {
							notice = item.dependency_note;
						}
					}

					const recurringConflict = getPortalSubscriptionIntervalConflict(itemsToAdd);
					if (recurringConflict.length > 1) {
						showPortalIntervalConflictPopup(recurringConflict);
						return;
					}

					itemsToAdd.forEach(function(itemToAdd) {
						addPortalCartItem(
							itemToAdd,
							itemToAdd.price_id === item.price_id ? '' : (item.dependency_note || '<?php echo esc_js( __( 'Required service added automatically.', 'ajforms' ) ); ?>')
						);
					});
					setPortalCartNotice('');
					renderPortalCart(notice);
				}

				function renderPortalCart(notice) {
					if (!portalCartWrap) {
						return;
					}
					const items = Object.values(portalCart);
					const count = items.length;
					const countEl = portalCartWrap.querySelector('.aj-portal-service-cart-count');
					const statusEl = portalCartWrap.querySelector('.aj-portal-service-cart-status');
					const clearButton = portalCartWrap.querySelector('.aj-portal-service-cart-clear');
					const checkoutButton = portalCartWrap.querySelector('.aj-portal-service-cart-checkout');
					const panel = portalCartWrap.querySelector('.aj-portal-service-cart-panel');
					const itemsEl = portalCartWrap.querySelector('.aj-portal-service-cart-items');
					const emptyEl = portalCartWrap.querySelector('.aj-portal-service-cart-empty');
					const totalEl = portalCartWrap.querySelector('.aj-portal-service-cart-total');
					let total = 0;
					let currency = 'usd';

					if (countEl) {
						countEl.textContent = String(count);
					}
					if (clearButton) {
						clearButton.disabled = count === 0;
					}
					if (checkoutButton) {
						checkoutButton.disabled = count === 0;
					}
					if (itemsEl) {
						itemsEl.innerHTML = '';
					}
					items.forEach(function(item) {
						total += item.amount || 0;
						currency = item.currency || currency;
						if (!itemsEl) {
							return;
						}
						const row = document.createElement('div');
						row.className = 'aj-portal-service-cart-row';
						row.innerHTML = '<div><strong></strong><span></span><small></small></div><em>Qty 1</em><button type="button"><?php echo esc_js( __( 'Remove', 'ajforms' ) ); ?></button>';
						row.querySelector('strong').textContent = item.product_name || '<?php echo esc_js( __( 'Service', 'ajforms' ) ); ?>';
						row.querySelector('span').textContent = getPortalCartItemLabel(item);
						const note = row.querySelector('small');
						note.textContent = item.locked || '';
						note.hidden = !item.locked;
						row.querySelector('button').addEventListener('click', function(event) {
							event.preventDefault();
							event.stopPropagation();
							preservePortalCartScroll(function() {
								delete portalCart[item.price_id];
								setPortalCartNotice('');
								renderPortalCart('');
							});
						});
						itemsEl.appendChild(row);
					});
					if (emptyEl) {
						emptyEl.hidden = count > 0;
					}
					if (totalEl) {
						const reviewHtml = getPortalMixedCheckoutReviewHtml(items);
						totalEl.innerHTML = count ? ('<div><?php echo esc_js( __( 'Total:', 'ajforms' ) ); ?> ' + formatPortalMoney(total, currency) + '</div>' + reviewHtml) : '';
					}
					if (statusEl) {
						statusEl.textContent = count ? (count + ' <?php echo esc_js( __( 'selected', 'ajforms' ) ); ?> · ' + formatPortalMoney(total, currency)) : '<?php echo esc_js( __( 'No services selected', 'ajforms' ) ); ?>';
					}
					if (panel && count > 0) {
						panel.hidden = false;
					}
					if (notice) {
						const message = shell.querySelector('.aj-portal-add-service-message');
						if (message) {
							message.textContent = notice;
							message.className = 'aj-portal-add-service-message is-success';
							message.style.display = 'block';
						}
					}
				}

				function updatePortalServiceCard(card) {
					const option = getPortalPriceOption(card);
					const item = getPortalItemFromOption(option, card);
					if (!item) {
						return;
					}
					const price = card.querySelector('.aj-portal-add-service-price');
					const requires = card.querySelector('.aj-portal-add-service-requires');
					const note = card.querySelector('.aj-portal-add-service-dependency-note');
					const button = card.querySelector('.aj-portal-add-service-button');
					if (price) {
						price.textContent = item.price_label || getPortalCartItemLabel(item);
					}
					if (requires) {
						requires.textContent = item.required_product_name ? ('<?php echo esc_js( __( 'Requires another product:', 'ajforms' ) ); ?> ' + item.required_product_name) : '';
						requires.hidden = !item.required_product_name;
					}
					if (note) {
						note.textContent = item.dependency_note || '';
						note.hidden = !item.dependency_note;
					}
					if (button) {
						button.disabled = !item.can_add;
						button.textContent = option.dataset.buttonLabel || (item.can_add ? '<?php echo esc_js( __( 'Add to My Services', 'ajforms' ) ); ?>' : '<?php echo esc_js( __( 'Already Added', 'ajforms' ) ); ?>');
					}
				}

				shell.querySelectorAll('.aj-portal-add-service-product').forEach(updatePortalServiceCard);
				shell.addEventListener('change', function(event) {
					if (event.target && event.target.matches('.aj-portal-add-service-price-select')) {
						updatePortalServiceCard(event.target.closest('.aj-portal-add-service-product'));
					}
					if (event.target && event.target.matches('.aj-portal-users-status-filter')) {
						event.target.form.submit();
					}
				});

				if (portalCartWrap) {
					portalCartWrap.addEventListener('click', function(event) {
						if (event.target.closest('.aj-portal-service-cart-toggle')) {
							event.preventDefault();
							event.stopPropagation();
							const panel = portalCartWrap.querySelector('.aj-portal-service-cart-panel');
							if (panel) {
								preservePortalCartScroll(function() {
									panel.hidden = !panel.hidden;
								});
							}
						}
						if (event.target.closest('.aj-portal-service-cart-clear')) {
							event.preventDefault();
							event.stopPropagation();
							preservePortalCartScroll(function() {
								Object.keys(portalCart).forEach(function(priceId) { delete portalCart[priceId]; });
								setPortalCartNotice('');
								renderPortalCart('');
							});
						}
						if (event.target.closest('.aj-portal-service-checkout-close')) {
							event.preventDefault();
							event.stopPropagation();
							const checkoutPanel = portalCartWrap.querySelector('.aj-portal-service-checkout-panel');
							if (embeddedCheckout && typeof embeddedCheckout.destroy === 'function') {
								embeddedCheckout.destroy();
								embeddedCheckout = null;
							}
							if (checkoutPanel) {
								checkoutPanel.hidden = true;
							}
						}
						if (event.target.closest('.aj-portal-service-cart-checkout')) {
							event.preventDefault();
							event.stopPropagation();
							startPortalEmbeddedCheckout(event.target.closest('.aj-portal-service-cart-checkout'));
						}
					});
				}

					function startPortalEmbeddedCheckout(button) {
						const portalItemsForCheckout = Object.values(portalCart);
						if (!portalItemsForCheckout.length) {
							return;
						}
						const recurringIntervals = getPortalCartSubscriptionIntervals(portalItemsForCheckout);
						if (recurringIntervals.length > 1) {
							setPortalCartNotice('<?php echo esc_js( __( 'Checkout does not support multiple subscription billing intervals in the same cart. Please checkout monthly and yearly subscriptions separately.', 'ajforms' ) ); ?>');
							return;
						}
						const items = portalItemsForCheckout.map(function(item) {
							return { price_id: item.price_id, quantity: 1 };
						});
					if (!window.Stripe) {
						window.alert('<?php echo esc_js( __( 'Stripe checkout could not be loaded.', 'ajforms' ) ); ?>');
						return;
					}
					const originalText = button ? button.textContent : '';
					if (button) {
						button.disabled = true;
						button.textContent = '<?php echo esc_js( __( 'Loading checkout...', 'ajforms' ) ); ?>';
					}
					const formData = new FormData();
					formData.append('action', 'ajcore_create_checkout_session');
					formData.append('portal_add_service', '1');
					formData.append('embedded_checkout', '1');
					formData.append('items', JSON.stringify(items));
					formData.append('nonce', portalCartWrap.dataset.cartNonce || '');
					formData.append('current_url', window.location.href);

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success || !payload.data || !payload.data.client_secret) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to start checkout.', 'ajforms' ) ); ?>');
							}
							const publishableKey = payload.data.publishable_key || portalCartWrap.dataset.publishableKey || '';
							if (!publishableKey) {
								throw new Error('<?php echo esc_js( __( 'Stripe publishable key is missing.', 'ajforms' ) ); ?>');
							}
							if (embeddedCheckout && typeof embeddedCheckout.destroy === 'function') {
								embeddedCheckout.destroy();
							}
							stripeInstance = window.Stripe(publishableKey);
							if (typeof stripeInstance.initEmbeddedCheckout !== 'function') {
								throw new Error('<?php echo esc_js( __( 'Stripe Embedded Checkout is not available. Please refresh and try again.', 'ajforms' ) ); ?>');
							}
							return stripeInstance.initEmbeddedCheckout({
								fetchClientSecret: function() {
									return Promise.resolve(decodeURIComponent(String(payload.data.client_secret || '').trim()));
								}
							});
						})
						.then(function(checkout) {
							embeddedCheckout = checkout;
							const checkoutPanel = portalCartWrap.querySelector('.aj-portal-service-checkout-panel');
							const mount = portalCartWrap.querySelector('.aj-portal-service-checkout-mount');
							if (checkoutPanel) {
								checkoutPanel.hidden = false;
							}
							if (mount) {
								mount.innerHTML = '';
								if (!mount.id) {
									mount.id = 'ajcore-portal-embedded-checkout-' + Math.random().toString(36).slice(2);
								}
								embeddedCheckout.mount('#' + mount.id);
							}
						})
							.catch(function(error) {
								setPortalCartNotice(error.message || '<?php echo esc_js( __( 'Unable to start checkout.', 'ajforms' ) ); ?>');
							})
						.finally(function() {
							if (button) {
								button.disabled = Object.keys(portalCart).length === 0;
								button.textContent = originalText;
							}
						});
				}


				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-custom-service-request-button');
					if (!button || button.disabled) {
						return;
					}

					const message = shell.querySelector('.aj-portal-add-service-message');
					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Submitting...', 'ajforms' ) ); ?>';

					if (message) {
						message.textContent = '';
						message.className = 'aj-portal-add-service-message';
						message.style.display = 'none';
					}

					const formData = new FormData();
					formData.append('action', 'ajcore_create_custom_service_request');
					formData.append('price_id', button.dataset.priceId || '');
					formData.append('nonce', button.dataset.nonce || '');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
							if (message) {
								message.textContent = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Your request was submitted and is now under review.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-success';
								message.style.display = 'block';
							}
							button.textContent = '<?php echo esc_js( __( 'Request Submitted', 'ajforms' ) ); ?>';
							setTimeout(function() { window.location.reload(); }, 800);
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							if (message) {
								message.textContent = error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-error';
								message.style.display = 'block';
							} else {
								window.alert(error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
						});
				});

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-cancel-service-request');
					if (!button || button.disabled) {
						return;
					}
					if (!window.confirm('<?php echo esc_js( __( 'Cancel this pending service request?', 'ajforms' ) ); ?>')) {
						return;
					}

					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Cancelling...', 'ajforms' ) ); ?>';

					const formData = new FormData();
					formData.append('action', 'ajcore_cancel_portal_service_request');
					formData.append('ledger_id', button.dataset.ledgerId || '');
					formData.append('nonce', button.dataset.nonce || '');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
							}
							window.location.reload();
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							window.alert(error.message || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
						});
				});
			})();
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	public function maybe_handle_portal_service_request_remove() {
		if ( empty( $_GET['ajcore_remove_service_request'] ) ) {
			return;
		}
		$this->block_impersonated_portal_write();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->get_customer_portal_url() ) );
			exit;
		}

		$ledger_id = absint( wp_unslash( $_GET['ajcore_remove_service_request'] ) );
		if ( ! $ledger_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ajcore_remove_service_request_' . $ledger_id ) ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-invalid' ), $this->get_customer_portal_url() ) );
			exit;
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-unlinked' ), $this->get_customer_portal_url() ) );
			exit;
		}

		global $wpdb;
		$table = $this->get_portal_ledger_table();
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND stripe_customer_id = %s LIMIT 1",
				$ledger_id,
				$stripe_customer_id
			)
		);

		if ( ! $entry || ! in_array( (string) $entry->source_type, array( 'checkout_session', 'custom_service_request' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-not-found' ), $this->get_customer_portal_url() ) );
			exit;
		}

		$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		if ( ! in_array( $status, array( 'unpaid', 'open', 'cancelled', 'admin_review_required' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-not-allowed' ), $this->get_customer_portal_url() ) );
			exit;
		}

		$deleted = $wpdb->delete(
			$table,
			array(
				'id'                 => $ledger_id,
				'stripe_customer_id' => $stripe_customer_id,
				'source_type'        => (string) $entry->source_type,
			),
			array( '%d', '%s', '%s' )
		);

		$notice = false === $deleted ? 'remove-error' : 'removed';
		wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => $notice ), $this->get_customer_portal_url() ) );
		exit;
	}

	public function maybe_handle_portal_file_upload() {
		if ( empty( $_POST['ajcore_portal_file_upload'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$portal_url = add_query_arg( 'portal_tab', 'file-library', $this->get_customer_portal_url() );

		if ( ! isset( $_POST['ajcore_portal_file_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajcore_portal_file_upload_nonce'] ) ), 'ajcore_portal_file_upload' ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-invalid', $portal_url ) );
			exit;
		}

		if ( empty( $_FILES['portal_file_upload'] ) || empty( $_FILES['portal_file_upload']['name'] ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$file = $_FILES['portal_file_upload'];
		$max_size = 20 * MB_IN_BYTES;
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$allowed_mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'heic' => 'image/heic',
			'heif' => 'image/heif',
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( empty( $uploaded['file'] ) || ! empty( $uploaded['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$file_path = $uploaded['file'];
		$file_type = wp_check_filetype( basename( $file_path ), $allowed_mimes );
		if ( empty( $file_type['type'] ) ) {
			@unlink( $file_path );
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$title = isset( $_POST['portal_file_title'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_file_title'] ) ) : '';
		if ( '' === $title ) {
			$title = preg_replace( '/\.[^.]+$/', '', basename( $file_path ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => isset( $uploaded['url'] ) ? esc_url_raw( $uploaded['url'] ) : '',
				'post_mime_type' => $file_type['type'],
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $file_path );
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $file_path );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( (int) $attachment_id, $metadata );
		}

		global $wpdb;
		$user = wp_get_current_user();
		$user_email = strtolower( sanitize_email( (string) $user->user_email ) );
		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$this->get_portal_files_table(),
			array(
				'attachment_id' => (int) $attachment_id,
				'title'         => $title,
				'description'   => sprintf( __( 'Uploaded by %s from the Client Portal.', 'ajforms' ), $user_email ),
				'category'      => __( 'Client Upload', 'ajforms' ),
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_delete_attachment( (int) $attachment_id, true );
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$file_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$this->get_portal_file_users_table(),
			array(
				'file_id'    => $file_id,
				'user_id'    => get_current_user_id(),
				'user_email' => $user_email,
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		wp_safe_redirect( add_query_arg( 'portal_notice', 'file-uploaded', $portal_url ) );
		exit;
	}

	public function maybe_handle_portal_file_download() {
		if ( empty( $_GET['aj_portal_download'] ) ) {
			return;
		}

		$file_id = absint( $_GET['aj_portal_download'] );

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'aj_portal_download_' . $file_id ) ) {
			wp_die( esc_html__( 'Invalid download link.', 'ajforms' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->current_user_can_access_portal_file( $file_id ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'ajforms' ), '', array( 'response' => 403 ) );
		}

		$file = $this->get_portal_file_record( $file_id );
		if ( ! $file ) {
			wp_die( esc_html__( 'File not found.', 'ajforms' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( (int) $file->attachment_id );
		$real_path = $file_path ? realpath( $file_path ) : false;
		$uploads   = wp_get_upload_dir();
		$uploads_base = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;

		if ( ! $real_path || ! $uploads_base || 0 !== strpos( $real_path, trailingslashit( $uploads_base ) ) || ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			wp_die( esc_html__( 'File is not available.', 'ajforms' ), '', array( 'response' => 404 ) );
		}

		$mime_type = get_post_mime_type( (int) $file->attachment_id );
		if ( ! $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		$download_name = basename( $real_path );

		nocache_headers();
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
		header( 'Content-Length: ' . filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $real_path );
		exit;
	}

	private function get_default_form_settings() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		return array(
			'submit_text'           => 'Submit',
			'notifications_enabled' => isset( $plugin_settings['default_notifications_enabled'] ) ? '1' === (string) $plugin_settings['default_notifications_enabled'] : true,
			'notification_email'    => isset( $plugin_settings['default_notification_email'] ) ? $plugin_settings['default_notification_email'] : get_option( 'admin_email' ),
			'notification_subject'  => isset( $plugin_settings['default_notification_subject'] ) ? $plugin_settings['default_notification_subject'] : 'New submission for {form_title}',
			'notification_body'     => "{submission_table}{submission_details_table}",
			'notification_from_name' => isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ),
			'notification_from_email' => '',
			'notification_reply_to' => '',
			'button_alignment'      => 'left',
			'form_description'      => '',
			'success_message'       => isset( $plugin_settings['default_success_message'] ) ? $plugin_settings['default_success_message'] : 'Form submitted successfully.',
			'confirmation_mode'     => 'default',
			'confirmation_type'     => 'message',
			'redirect_url'          => '',
			'confirmation_rules'    => array(),
			'use_label_placeholders' => false,
			'custom_css'            => '',
			'asana_task_enabled'    => false,
			'asana_task_name'       => 'New form submission: {form_title}',
			'asana_task_notes'      => "Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}",
			'asana_project_gid'     => isset( $plugin_settings['asana_project_gid'] ) ? $plugin_settings['asana_project_gid'] : '',
			'asana_assignee_gid'    => '',
			'asana_due_date'        => 'today',
			'stripe_enabled'        => false,
			'stripe_amount'         => '',
			'stripe_currency'       => 'usd',
			'stripe_description'    => 'Payment for {form_title}',
			'form_theme'            => 'clean',
			'background_mode'       => 'solid',
			'background_color'      => '#ffffff',
			'background_gradient_start' => '#ffffff',
			'background_gradient_end'   => '#f3f7fb',
			'primary_color'         => '#0f7ac6',
			'text_color'            => '#1f2937',
			'input_background'      => '#ffffff',
			'input_border_color'    => '#d7dce3',
			'border_radius'         => 16,
		);
	}

	private function is_honeypot_enabled() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		return ! empty( $plugin_settings['honeypot_enabled'] ) && '1' === (string) $plugin_settings['honeypot_enabled'];
	}

	private function get_spam_provider_config() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$provider        = ! empty( $plugin_settings['spam_challenge_provider'] ) ? sanitize_key( $plugin_settings['spam_challenge_provider'] ) : '';

		$providers = array(
			'recaptcha' => array(
				'provider'         => 'recaptcha',
				'site_key'         => ! empty( $plugin_settings['recaptcha_site_key'] ) ? sanitize_text_field( $plugin_settings['recaptcha_site_key'] ) : '',
				'secret_key'       => ! empty( $plugin_settings['recaptcha_secret_key'] ) ? sanitize_text_field( $plugin_settings['recaptcha_secret_key'] ) : '',
				'script_url'       => 'https://www.google.com/recaptcha/api.js',
				'token_field'      => 'g-recaptcha-response',
				'container_class'  => 'g-recaptcha',
				'verify_endpoint'  => 'https://www.google.com/recaptcha/api/siteverify',
			),
			'hcaptcha' => array(
				'provider'         => 'hcaptcha',
				'site_key'         => ! empty( $plugin_settings['hcaptcha_site_key'] ) ? sanitize_text_field( $plugin_settings['hcaptcha_site_key'] ) : '',
				'secret_key'       => ! empty( $plugin_settings['hcaptcha_secret_key'] ) ? sanitize_text_field( $plugin_settings['hcaptcha_secret_key'] ) : '',
				'script_url'       => 'https://js.hcaptcha.com/1/api.js',
				'token_field'      => 'h-captcha-response',
				'container_class'  => 'h-captcha',
				'verify_endpoint'  => 'https://api.hcaptcha.com/siteverify',
			),
			'turnstile' => array(
				'provider'         => 'turnstile',
				'site_key'         => ! empty( $plugin_settings['turnstile_site_key'] ) ? sanitize_text_field( $plugin_settings['turnstile_site_key'] ) : '',
				'secret_key'       => ! empty( $plugin_settings['turnstile_secret_key'] ) ? sanitize_text_field( $plugin_settings['turnstile_secret_key'] ) : '',
				'script_url'       => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
				'token_field'      => 'cf-turnstile-response',
				'container_class'  => 'cf-turnstile',
				'verify_endpoint'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			),
		);

		if ( empty( $providers[ $provider ] ) ) {
			return array(
				'provider'   => '',
				'site_key'   => '',
				'secret_key' => '',
			);
		}

		return $providers[ $provider ];
	}

	private function should_render_challenge_provider() {
		$config = $this->get_spam_provider_config();

		return ! empty( $config['provider'] ) && ! empty( $config['site_key'] ) && ! empty( $config['secret_key'] );
	}

	private function validate_challenge_provider_token() {
		$config = $this->get_spam_provider_config();

		if ( empty( $config['provider'] ) || '' === $config['secret_key'] ) {
			return true;
		}

		$token_field = $config['token_field'];
		$token       = isset( $_POST[ $token_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $token_field ] ) ) : '';
		if ( '' === $token ) {
			$messages = array(
				'recaptcha' => __( 'Please complete the reCAPTCHA check before submitting.', 'ajforms' ),
				'hcaptcha'  => __( 'Please complete the hCaptcha check before submitting.', 'ajforms' ),
				'turnstile' => __( 'Please complete the Turnstile check before submitting.', 'ajforms' ),
			);
			$message  = isset( $messages[ $config['provider'] ] ) ? $messages[ $config['provider'] ] : __( 'Please complete the challenge check before submitting.', 'ajforms' );

			return new WP_Error( 'challenge_missing_token', $message );
		}

		$remote_ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$remote_ip = sanitize_text_field( trim( (string) $forwarded[0] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$response = wp_remote_post(
			$config['verify_endpoint'],
			array(
				'timeout' => 15,
				'body'    => array(
					'secret'   => $config['secret_key'],
					'response' => $token,
					'remoteip' => $remote_ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'challenge_request_failed', __( 'Challenge verification could not be completed right now.', 'ajforms' ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $payload['success'] ) ) {
			$messages = array(
				'recaptcha' => __( 'reCAPTCHA verification failed. Please try again.', 'ajforms' ),
				'hcaptcha'  => __( 'hCaptcha verification failed. Please try again.', 'ajforms' ),
				'turnstile' => __( 'Turnstile verification failed. Please try again.', 'ajforms' ),
			);
			$message  = isset( $messages[ $config['provider'] ] ) ? $messages[ $config['provider'] ] : __( 'Challenge verification failed. Please try again.', 'ajforms' );

			return new WP_Error( 'challenge_failed', $message );
		}

		return true;
	}

	private function get_honeypot_field_name( $form_id ) {
		return 'ajf_hp_' . absint( $form_id );
	}

	private function get_stripe_settings() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$issues = function_exists( 'ajcore_get_stripe_mode_issues' ) ? ajcore_get_stripe_mode_issues( $plugin_settings, true ) : array();

		return array(
			'mode'            => ! empty( $plugin_settings['stripe_mode'] ) ? sanitize_key( $plugin_settings['stripe_mode'] ) : 'test',
			'publishable_key' => ! empty( $plugin_settings['stripe_publishable_key'] ) ? sanitize_text_field( $plugin_settings['stripe_publishable_key'] ) : '',
			'secret_key'      => ! empty( $plugin_settings['stripe_secret_key'] ) ? sanitize_text_field( $plugin_settings['stripe_secret_key'] ) : '',
			'mode_issues'     => is_array( $issues ) ? $issues : array(),
		);
	}

	private function get_stripe_mode_blocking_error( $stripe_settings = array() ) {
		$issues = isset( $stripe_settings['mode_issues'] ) && is_array( $stripe_settings['mode_issues'] ) ? $stripe_settings['mode_issues'] : array();
		if ( empty( $issues ) ) {
			return '';
		}

		return implode( ' ', array_map( 'sanitize_text_field', $issues ) );
	}

	private function get_stripe_payment_config( $form, $settings ) {
		$stripe_settings = $this->get_stripe_settings();
		$enabled         = ! empty( $settings['stripe_enabled'] );
		$price_id        = isset( $settings['stripe_price_id'] ) ? sanitize_text_field( $settings['stripe_price_id'] ) : '';
		$price           = '' !== $price_id ? $this->get_stripe_price_from_cache( $price_id ) : null;
		$amount          = $price ? (float) $price['amount'] : ( isset( $settings['stripe_amount'] ) ? floatval( $settings['stripe_amount'] ) : 0 );
		$currency        = $price ? strtolower( sanitize_key( $price['currency'] ) ) : ( isset( $settings['stripe_currency'] ) ? strtolower( sanitize_key( $settings['stripe_currency'] ) ) : 'usd' );
		$description     = $price ? $price['product_name'] : ( isset( $settings['stripe_description'] ) ? sanitize_text_field( $settings['stripe_description'] ) : 'Payment for {form_title}' );

		return array(
			'enabled'         => $enabled && '' !== $stripe_settings['publishable_key'] && '' !== $stripe_settings['secret_key'] && $amount > 0 && '' === $this->get_stripe_mode_blocking_error( $stripe_settings ),
			'publishable_key' => $stripe_settings['publishable_key'],
			'secret_key'      => $stripe_settings['secret_key'],
			'price_id'        => $price_id,
			'product_id'      => $price && isset( $price['product_id'] ) ? $price['product_id'] : '',
			'mode_issues'     => isset( $stripe_settings['mode_issues'] ) ? $stripe_settings['mode_issues'] : array(),
			'amount'          => $amount,
			'currency'        => in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ? $currency : 'usd',
			'description'     => str_replace( '{form_title}', $form->title, $description ),
		);
	}

	private function get_stripe_products_cache() {
		$cache = get_option(
			'ajforms_stripe_products_cache',
			array(
				'updated_at' => '',
				'prices'     => array(),
			)
		);

		return is_array( $cache ) ? wp_parse_args(
			$cache,
			array(
				'updated_at' => '',
				'prices'     => array(),
			)
		) : array(
			'updated_at' => '',
			'prices'     => array(),
		);
	}

	private function get_stripe_price_from_cache( $price_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		$cache    = $this->get_stripe_products_cache();
		$prices   = isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array();

		foreach ( $prices as $price ) {
			if ( is_array( $price ) && isset( $price['id'] ) && $price_id === $price['id'] ) {
				return $price;
			}
		}

		return null;
	}


	private function get_public_product_dependency_settings() {
		$settings = get_option( 'ajcore_public_product_dependencies', array() );
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $settings as $price_id => $dependency ) {
			$price_id = sanitize_text_field( (string) $price_id );
			if ( '' === $price_id || ! is_array( $dependency ) ) {
				continue;
			}

			$required_price_id = isset( $dependency['requires_price_id'] ) ? sanitize_text_field( (string) $dependency['requires_price_id'] ) : '';
			$dependency_note   = isset( $dependency['dependency_note'] ) ? sanitize_textarea_field( (string) $dependency['dependency_note'] ) : '';

			$normalized[ $price_id ] = array(
				'requires_price_id' => $required_price_id,
				'dependency_note'   => $dependency_note,
			);
		}

		return $normalized;
	}

	private function get_public_product_display_settings() {
		$settings = get_option( 'ajcore_public_product_display_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$selected_prices = isset( $settings['selected_prices'] ) && is_array( $settings['selected_prices'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', $settings['selected_prices'] ) ) ) : array();
		$order = array();
		if ( isset( $settings['order'] ) && is_array( $settings['order'] ) ) {
			foreach ( $settings['order'] as $price_id => $sort_order ) {
				$price_id = sanitize_text_field( (string) $price_id );
				if ( '' !== $price_id ) {
					$order[ $price_id ] = intval( $sort_order );
				}
			}
		}

		return array(
			'selected_prices' => $selected_prices,
			'order'           => $order,
		);
	}

	private function apply_public_product_dependency_settings_to_prices( $prices ) {
		$dependency_settings = $this->get_public_product_dependency_settings();
		if ( empty( $dependency_settings ) || ! is_array( $prices ) ) {
			return $prices;
		}

		foreach ( $prices as $index => $price ) {
			if ( ! is_array( $price ) || empty( $price['id'] ) ) {
				continue;
			}

			$price_id = sanitize_text_field( (string) $price['id'] );
			if ( empty( $dependency_settings[ $price_id ] ) ) {
				continue;
			}

			$dependency = $dependency_settings[ $price_id ];
			if ( ! empty( $dependency['requires_price_id'] ) ) {
				$prices[ $index ]['requires_price_id'] = sanitize_text_field( (string) $dependency['requires_price_id'] );
			}
			if ( ! empty( $dependency['dependency_note'] ) ) {
				$prices[ $index ]['dependency_note'] = sanitize_textarea_field( (string) $dependency['dependency_note'] );
			}
		}

		return $prices;
	}

	private function get_public_stripe_prices( $requested_price_ids = array(), $include_archived = false ) {
		$settings       = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$display_settings = $this->get_public_product_display_settings();
		$mode           = ! empty( $display_settings['selected_prices'] ) ? 'selected' : ( isset( $settings['stripe_products_mode'] ) ? sanitize_key( $settings['stripe_products_mode'] ) : 'all' );
		$selected       = ! empty( $display_settings['selected_prices'] ) ? $display_settings['selected_prices'] : ( isset( $settings['stripe_selected_prices'] ) && is_array( $settings['stripe_selected_prices'] ) ? array_map( 'sanitize_text_field', $settings['stripe_selected_prices'] ) : array() );
		$order          = isset( $display_settings['order'] ) && is_array( $display_settings['order'] ) ? $display_settings['order'] : array();
		$requested      = array_filter( array_map( 'sanitize_text_field', (array) $requested_price_ids ) );
		$cache          = $this->get_stripe_products_cache();
		$prices         = isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array();
		$allowed_prices = array();

		foreach ( $prices as $price ) {
			if ( ! is_array( $price ) || empty( $price['id'] ) ) {
				continue;
			}

			if ( ! $include_archived && ( empty( $price['product_active'] ) || empty( $price['price_active'] ) ) ) {
				continue;
			}

			if ( 'selected' === $mode && ! in_array( $price['id'], $selected, true ) ) {
				continue;
			}

			if ( ! empty( $requested ) && ! in_array( $price['id'], $requested, true ) ) {
				continue;
			}

			$allowed_prices[] = $price;
		}

		if ( ! empty( $requested ) ) {
			usort(
				$allowed_prices,
				function ( $a, $b ) use ( $requested ) {
					$a_id = isset( $a['id'] ) ? sanitize_text_field( (string) $a['id'] ) : '';
					$b_id = isset( $b['id'] ) ? sanitize_text_field( (string) $b['id'] ) : '';
					return array_search( $a_id, $requested, true ) <=> array_search( $b_id, $requested, true );
				}
			);
		} elseif ( ! empty( $order ) ) {
			usort(
				$allowed_prices,
				function ( $a, $b ) use ( $order ) {
					$a_id = isset( $a['id'] ) ? sanitize_text_field( (string) $a['id'] ) : '';
					$b_id = isset( $b['id'] ) ? sanitize_text_field( (string) $b['id'] ) : '';
					$a_order = isset( $order[ $a_id ] ) ? intval( $order[ $a_id ] ) : 9999;
					$b_order = isset( $order[ $b_id ] ) ? intval( $order[ $b_id ] ) : 9999;
					if ( $a_order === $b_order ) {
						return strcasecmp( isset( $a['product_name'] ) ? (string) $a['product_name'] : '', isset( $b['product_name'] ) ? (string) $b['product_name'] : '' );
					}
					return $a_order <=> $b_order;
				}
			);
		}

		return $this->apply_public_product_dependency_settings_to_prices( $allowed_prices );
	}


	private function get_public_price_required_price_id( $price, $allowed_price_map = array() ) {
		if ( ! is_array( $price ) ) {
			return '';
		}

		$required_price_id = ! empty( $price['requires_price_id'] ) ? sanitize_text_field( (string) $price['requires_price_id'] ) : '';
		if ( '' !== $required_price_id && isset( $allowed_price_map[ $required_price_id ] ) ) {
			return $required_price_id;
		}

		$required_name = ! empty( $price['requires_product_name'] ) ? strtolower( trim( wp_strip_all_tags( (string) $price['requires_product_name'] ) ) ) : '';
		if ( '' === $required_name ) {
			return '';
		}

		foreach ( $allowed_price_map as $candidate_price_id => $candidate ) {
			if ( ! is_array( $candidate ) || empty( $candidate['product_name'] ) ) {
				continue;
			}

			$candidate_name = strtolower( trim( wp_strip_all_tags( (string) $candidate['product_name'] ) ) );
			if ( $candidate_name === $required_name ) {
				return sanitize_text_field( (string) $candidate_price_id );
			}
		}

		return '';
	}

	private function normalize_public_cart_items_with_dependencies( $items, $allowed_price_map ) {
		$normalized = array();

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['price_id'] ) ) {
				continue;
			}

			$item_price_id = sanitize_text_field( (string) $item['price_id'] );
			if ( empty( $allowed_price_map[ $item_price_id ] ) ) {
				continue;
			}

			$required_price_id = $this->get_public_price_required_price_id( $allowed_price_map[ $item_price_id ], $allowed_price_map );
			if ( '' !== $required_price_id && empty( $normalized[ $required_price_id ] ) ) {
				$normalized[ $required_price_id ] = array(
					'price_id' => $required_price_id,
					'quantity' => 1,
				);
			}

			$normalized[ $item_price_id ] = array(
				'price_id' => $item_price_id,
				'quantity' => 1,
			);
		}

		return array_values( $normalized );
	}

	private function convert_amount_to_minor_units( $amount, $currency ) {
		$zero_decimal_currencies = array( 'jpy', 'krw', 'vnd' );

		if ( in_array( strtolower( $currency ), $zero_decimal_currencies, true ) ) {
			return (int) round( $amount );
		}

		return (int) round( $amount * 100 );
	}

	private function stripe_api_request( $path, $secret_key, $body = array(), $method = 'POST', $extra_headers = array() ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $secret_key,
		);
		if ( is_array( $extra_headers ) && ! empty( $extra_headers ) ) {
			$headers = array_merge( $headers, $extra_headers );
		}

		$args = array(
			'timeout' => 20,
			'method'  => $method,
			'headers' => $headers,
		);

		if ( 'GET' === strtoupper( $method ) ) {
			$url = add_query_arg( $body, 'https://api.stripe.com/v1/' . ltrim( $path, '/' ) );
		} else {
			$url          = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $payload['error']['message'] ) ? sanitize_text_field( (string) $payload['error']['message'] ) : __( 'Stripe request failed.', 'ajforms' );
			return new WP_Error( 'stripe_request_failed', $message );
		}

		return is_array( $payload ) ? $payload : array();
	}

	public function ajax_create_stripe_payment_intent() {
		$this->block_impersonated_portal_write();
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $form_id || ! wp_verify_nonce( $nonce, 'ajf_stripe_payment_' . $form_id ) ) {
			wp_send_json_error( __( 'Invalid payment request.', 'ajforms' ), 400 );
		}

		$form = $this->get_form_by_id( $form_id );
		if ( ! $form || 'deleted' === $form->status ) {
			wp_send_json_error( __( 'Form not found.', 'ajforms' ), 404 );
		}

		$schema = json_decode( $form->form_schema, true );
		if ( ! is_array( $schema ) ) {
			wp_send_json_error( __( 'Form schema is invalid.', 'ajforms' ), 400 );
		}

		$normalized     = $this->normalize_schema( $schema );
		$stripe_config  = $this->get_stripe_payment_config( $form, $normalized['settings'] );
		$mode_error     = $this->get_stripe_mode_blocking_error( $stripe_config );
		if ( '' !== $mode_error ) {
			wp_send_json_error( $mode_error, 400 );
		}

		if ( ! $stripe_config['enabled'] ) {
			wp_send_json_error( __( 'Stripe payments are not enabled for this form.', 'ajforms' ), 400 );
		}

		$amount_minor = $this->convert_amount_to_minor_units( $stripe_config['amount'], $stripe_config['currency'] );
		$response     = $this->stripe_api_request(
			'payment_intents',
			$stripe_config['secret_key'],
			array(
				'amount'                 => $amount_minor,
				'currency'               => $stripe_config['currency'],
				'description'            => $stripe_config['description'],
				'payment_method_types[]' => 'card',
				'metadata[form_id]'      => (string) $form_id,
				'metadata[form_title]'   => sanitize_text_field( $form->title ),
				'metadata[price_id]'     => $stripe_config['price_id'],
				'metadata[product_id]'   => $stripe_config['product_id'],
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 400 );
		}

		wp_send_json_success(
			array(
				'client_secret' => isset( $response['client_secret'] ) ? sanitize_text_field( (string) $response['client_secret'] ) : '',
				'amount'        => $stripe_config['amount'],
				'currency'      => strtoupper( $stripe_config['currency'] ),
				'description'   => $stripe_config['description'],
			)
		);
	}

	public function ajax_submit_portal_service_request() {
		$this->block_impersonated_portal_write();
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Login required.', 'ajforms' ) ), 401 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ajcore_submit_portal_service_request' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ajforms' ) ), 403 );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_send_json_error( array( 'message' => __( 'Your portal account is not linked yet.', 'ajforms' ) ), 403 );
		}

		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';

		if ( '' === $subject ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a subject.', 'ajforms' ) ), 400 );
		}

		$pdb    = $this->get_pdb();
		$sr_tbl = $this->get_portal_service_requests_table();

		$inserted = $pdb->insert(
			$sr_tbl,
			array(
				'stripe_customer_id' => $stripe_customer_id,
				'wp_user_id'         => get_current_user_id(),
				'source'             => 'client_portal',
				'source_type'        => 'client_portal',
				'source_object_id'   => 'client_portal_' . wp_generate_uuid4(),
				'service_name'       => $subject,
				'client_notes'       => $details,
				'status'             => 'draft',
				'service_status'     => 'new',
				'amount'             => 0,
				'currency'           => 'usd',
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Could not submit request. Please try again.', 'ajforms' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'Your service request has been submitted. We will be in touch shortly.', 'ajforms' ) ) );
	}

	public function ajax_cancel_portal_service_request() {
		$this->block_impersonated_portal_write();
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Login required.', 'ajforms' ), 401 );
		}

		$ledger_id = isset( $_POST['ledger_id'] ) ? absint( wp_unslash( $_POST['ledger_id'] ) ) : 0;
		$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! $ledger_id || ! wp_verify_nonce( $nonce, 'ajcore_cancel_portal_service_request_' . $ledger_id ) ) {
			wp_send_json_error( __( 'Invalid request.', 'ajforms' ), 400 );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_send_json_error( __( 'Portal account is not linked.', 'ajforms' ), 403 );
		}

		global $wpdb;
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE id = %d AND stripe_customer_id = %s LIMIT 1",
				$ledger_id,
				$stripe_customer_id
			)
		);

		if ( ! $entry || ! in_array( (string) $entry->source_type, array( 'checkout_session', 'custom_service_request' ), true ) ) {
			wp_send_json_error( __( 'Request was not found.', 'ajforms' ), 404 );
		}

		$deleted = $wpdb->delete(
			$this->get_portal_ledger_table(),
			array(
				'id'                 => $ledger_id,
				'stripe_customer_id' => $stripe_customer_id,
				'source_type'        => (string) $entry->source_type,
			),
			array( '%d', '%s', '%s' )
		);

		if ( false === $deleted ) {
			wp_send_json_error( __( 'Unable to remove request.', 'ajforms' ), 500 );
		}

		wp_send_json_success( array( 'removed' => true, 'ledger_id' => $ledger_id ) );
	}

	public function ajax_create_custom_service_request() {
		$this->block_impersonated_portal_write();
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Login required.', 'ajforms' ), 401 );
		}

		$price_id = isset( $_POST['price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['price_id'] ) ) : '';
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( '' === $price_id || ! wp_verify_nonce( $nonce, 'ajcore_portal_custom_request_' . $price_id ) ) {
			wp_send_json_error( __( 'Invalid custom service request.', 'ajforms' ), 400 );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_send_json_error( __( 'Portal account is not linked.', 'ajforms' ), 403 );
		}

		$product = $this->get_portal_product_by_price_id( $price_id );
		if ( ! $product || 'custom_request' !== $this->get_portal_product_duplicate_behavior( $product ) ) {
			wp_send_json_error( __( 'This service is not available for custom requests.', 'ajforms' ), 404 );
		}

		$context = $this->get_current_user_portal_billing_context();
		$purchased_price_ids   = $this->get_customer_purchased_price_ids( $context['subscriptions'] );
		$purchased_product_ids = $this->get_customer_purchased_product_ids( $context['subscriptions'] );
		$is_owned_for_custom_request = $this->is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $context['subscriptions'], $context['ledger'] );
		if ( ! $is_owned_for_custom_request ) {
			$available_products_for_custom_request = $this->get_portal_available_service_products( $context['subscriptions'], $context['ledger'] );
			$available_price_ids_for_custom_request = array();
			foreach ( $available_products_for_custom_request as $available_product_for_custom_request ) {
				$available_price_id_for_custom_request = isset( $available_product_for_custom_request->stripe_price_id ) ? sanitize_text_field( (string) $available_product_for_custom_request->stripe_price_id ) : '';
				if ( '' !== $available_price_id_for_custom_request ) {
					$available_price_ids_for_custom_request[] = $available_price_id_for_custom_request;
				}
			}
			$has_service_context_for_custom_request = ! empty( $context['subscriptions'] );
			if ( ! $has_service_context_for_custom_request ) {
				foreach ( (array) $context['ledger'] as $ledger_entry_for_custom_request ) {
					$ledger_status_for_custom_request = isset( $ledger_entry_for_custom_request->status ) ? sanitize_key( (string) $ledger_entry_for_custom_request->status ) : '';
					if ( in_array( $ledger_status_for_custom_request, array( 'paid', 'succeeded', 'active' ), true ) ) {
						$has_service_context_for_custom_request = true;
						break;
					}
				}
			}
			$is_custom_request_fallback_allowed = $has_service_context_for_custom_request && ! in_array( $price_id, $available_price_ids_for_custom_request, true );
			if ( ! $is_custom_request_fallback_allowed ) {
				wp_send_json_error( __( 'This custom request is available after the base service is active on your account.', 'ajforms' ), 400 );
			}
		}

		$existing_request = $this->get_open_custom_service_request( $stripe_customer_id, $price_id );
		if ( $existing_request ) {
			wp_send_json_success(
				array(
					'message'   => __( 'Your request is already under review.', 'ajforms' ),
					'ledger_id' => (int) $existing_request->id,
				)
			);
		}

		$product_name = ! empty( $product->custom_label ) ? $product->custom_label : ( ! empty( $product->name ) ? $product->name : __( 'Service', 'ajforms' ) );
		$request_title = ! empty( $product->custom_request_title ) ? $product->custom_request_title : sprintf( __( 'Custom request for %s', 'ajforms' ), $product_name );
		$source_object_id = $this->get_portal_custom_request_source_object_id( $stripe_customer_id, $price_id );
		$metadata = array(
			'price_id'     => $price_id,
			'product_id'   => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
			'product_name' => $product_name,
			'source'       => 'portal_custom_request',
		);

		global $wpdb;
		$inserted = $wpdb->replace(
			$this->get_portal_ledger_table(),
			array(
				'stripe_customer_id' => $stripe_customer_id,
				'source_object_id'   => $source_object_id,
				'source_type'        => 'custom_service_request',
				'ledger_date'        => current_time( 'mysql' ),
				'description'        => $request_title,
				'amount'             => 0,
				'currency'           => ! empty( $product->currency ) ? sanitize_key( (string) $product->currency ) : 'usd',
				'status'             => 'admin_review_required',
				'invoice_id'         => '',
				'payment_intent_id'  => '',
				'charge_id'          => '',
				'metadata'           => wp_json_encode( $metadata ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( __( 'Unable to submit the request.', 'ajforms' ), 500 );
		}

		$ledger_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->get_portal_ledger_table()} WHERE source_object_id = %s LIMIT 1",
				$source_object_id
			)
		);

		$service_request_id = $this->upsert_portal_service_request(
			array(
				'wp_user_id'         => get_current_user_id(),
				'stripe_customer_id' => $stripe_customer_id,
				'stripe_price_id'    => $price_id,
				'stripe_product_id'  => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
				'service_name'       => $product_name,
				'request_type'       => 'custom_request',
				'status'             => 'admin_review_required',
				'amount'             => 0,
				'currency'           => ! empty( $product->currency ) ? sanitize_key( (string) $product->currency ) : 'usd',
				'source_object_id'   => $source_object_id,
				'source_type'        => 'custom_service_request',
				'ledger_id'          => $ledger_id,
				'source'             => 'client_portal',
				'created_by'         => get_current_user_id(),
				'raw_data'           => $metadata,
			)
		);

		if ( ! $service_request_id ) {
			wp_send_json_error( __( 'The request was added to billing, but the service request queue could not be updated.', 'ajforms' ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your request was submitted and is now under review.', 'ajforms' ),
			)
		);
	}

	private function get_public_cart_recurring_interval_conflict( $items, $allowed_price_map ) {
		$intervals = array();

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['price_id'] ) ) {
				continue;
			}

			$price_id = sanitize_text_field( (string) $item['price_id'] );
			if ( empty( $allowed_price_map[ $price_id ] ) || empty( $allowed_price_map[ $price_id ]['recurring_interval'] ) ) {
				continue;
			}

			$interval = sanitize_key( (string) $allowed_price_map[ $price_id ]['recurring_interval'] );
			if ( '' !== $interval ) {
				$intervals[ $interval ] = true;
			}
		}

		$intervals = array_keys( $intervals );

		return count( $intervals ) > 1 ? $intervals : array();
	}

	public function maybe_finalize_mixed_checkout_from_success_url() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$checkout_status = isset( $_GET['ajcore_checkout'] ) ? sanitize_key( wp_unslash( $_GET['ajcore_checkout'] ) ) : '';
		$session_id      = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		if ( 'success' !== $checkout_status || 0 !== strpos( $session_id, 'cs_' ) ) {
			return;
		}

		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return;
		}

		$admin = new AJForms_Admin();
		if ( method_exists( $admin, 'finalize_mixed_checkout_session_by_id' ) ) {
			$admin->finalize_mixed_checkout_session_by_id( $session_id, 'success_return' );
		}
	}



	private function get_mixed_checkout_interval_label( $interval ) {
		$interval = sanitize_key( (string) $interval );

		$labels = array(
			'day'   => __( 'day', 'ajforms' ),
			'week'  => __( 'week', 'ajforms' ),
			'month' => __( 'month', 'ajforms' ),
			'year'  => __( 'year', 'ajforms' ),
		);

		return isset( $labels[ $interval ] ) ? $labels[ $interval ] : $interval;
	}

	private function get_mixed_checkout_custom_text_message( $items, $allowed_price_map, $deferred_one_time_amount_minor, $deferred_one_time_currency ) {
		if ( empty( $deferred_one_time_amount_minor ) || ! is_array( $items ) ) {
			return '';
		}

		$currency = '' !== (string) $deferred_one_time_currency ? strtolower( sanitize_key( (string) $deferred_one_time_currency ) ) : 'usd';
		$subscription_amount_minor = 0;
		$recurring_interval = '';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['price_id'] ) ) {
				continue;
			}

			$item_price_id = sanitize_text_field( (string) $item['price_id'] );
			if ( empty( $allowed_price_map[ $item_price_id ] ) || empty( $allowed_price_map[ $item_price_id ]['recurring_interval'] ) ) {
				continue;
			}

			$item_currency = ! empty( $allowed_price_map[ $item_price_id ]['currency'] ) ? strtolower( sanitize_key( (string) $allowed_price_map[ $item_price_id ]['currency'] ) ) : $currency;
			if ( $item_currency !== $currency ) {
				continue;
			}

			$quantity = isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;
			$item_amount = isset( $allowed_price_map[ $item_price_id ]['amount'] ) ? (float) $allowed_price_map[ $item_price_id ]['amount'] : 0.0;
			$subscription_amount_minor += $this->convert_amount_to_minor_units( $item_amount, $item_currency ) * $quantity;

			if ( '' === $recurring_interval ) {
				$recurring_interval = sanitize_key( (string) $allowed_price_map[ $item_price_id ]['recurring_interval'] );
			}
		}

		if ( empty( $subscription_amount_minor ) ) {
			return '';
		}

		$total_today_minor = absint( $subscription_amount_minor ) + absint( $deferred_one_time_amount_minor );
		$interval_label    = $this->get_mixed_checkout_interval_label( $recurring_interval );
		$interval_suffix   = '' !== $interval_label ? sprintf( __( ' per %s', 'ajforms' ), $interval_label ) : '';

		return sprintf(
			/* translators: 1: total today amount, 2: recurring subscription amount, 3: recurring interval suffix, 4: one-time amount */
			__( 'Total due today is %1$s. This Stripe page starts the subscription portion: %2$s%3$s. After successful checkout, AJ Core will immediately charge the one-time portion: %4$s using the same saved payment method. Renewal is %2$s%3$s after the first period.', 'ajforms' ),
			$this->format_checkout_notice_money( $total_today_minor, $currency ),
			$this->format_checkout_notice_money( $subscription_amount_minor, $currency ),
			$interval_suffix,
			$this->format_checkout_notice_money( $deferred_one_time_amount_minor, $currency )
		);
	}

	public function ajax_create_checkout_session() {
		$this->block_impersonated_portal_write();
		$price_id = isset( $_POST['price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['price_id'] ) ) : '';
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$items    = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$include_archived  = isset( $_POST['include_archived'] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $_POST['include_archived'] ) ) ), array( '1', 'true', 'yes' ), true );
		$portal_add_service = isset( $_POST['portal_add_service'] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $_POST['portal_add_service'] ) ) ), array( '1', 'true', 'yes' ), true );
		$embedded_checkout = isset( $_POST['embedded_checkout'] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $_POST['embedded_checkout'] ) ) ), array( '1', 'true', 'yes' ), true );

		if ( $portal_add_service ) {
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( __( 'Invalid portal service request.', 'ajforms' ), 400 );
			}
			if ( is_array( $items ) ) {
				if ( ! wp_verify_nonce( $nonce, 'ajcore_cart_checkout' ) ) {
					wp_send_json_error( __( 'Invalid portal service cart request.', 'ajforms' ), 400 );
				}
			} elseif ( '' === $price_id || ! wp_verify_nonce( $nonce, 'ajcore_portal_add_service_' . $price_id ) ) {
				wp_send_json_error( __( 'Invalid portal service request.', 'ajforms' ), 400 );
			}
		} elseif ( is_array( $items ) ) {
			if ( ! wp_verify_nonce( $nonce, 'ajcore_cart_checkout' ) ) {
				wp_send_json_error( __( 'Invalid cart request.', 'ajforms' ), 400 );
			}
		} elseif ( '' === $price_id || ! wp_verify_nonce( $nonce, 'ajcore_buy_product_' . $price_id ) ) {
			wp_send_json_error( __( 'Invalid product request.', 'ajforms' ), 400 );
		}

		$requested_price_ids = is_array( $items ) ? array_map(
			function ( $item ) {
				return is_array( $item ) && isset( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '';
			},
			$items
		) : array( $price_id );
		$requested_price_ids = array_filter( $requested_price_ids );
		$allowed_price_map   = array();
		$checkout_mode       = 'payment';
		$portal_product      = null;
		$deferred_one_time_items = array();
		$deferred_one_time_amount_minor = 0;
		$deferred_one_time_currency = '';
		$portal_upgrade_items = array();

		if ( $portal_add_service ) {
			$portal_context  = $this->get_current_user_portal_billing_context();
			$portal_products = ( '' !== $portal_context['stripe_customer_id'] && $portal_context['customer'] )
				? $this->get_portal_available_service_products( $portal_context['subscriptions'], $portal_context['ledger'] )
				: array();

			foreach ( $portal_products as $available_product ) {
				if ( empty( $available_product->portal_can_add ) ) {
					continue;
				}

				$available_price_id = isset( $available_product->stripe_price_id ) ? sanitize_text_field( (string) $available_product->stripe_price_id ) : '';
				if ( '' === $available_price_id ) {
					continue;
				}

				$allowed_price_map[ $available_price_id ] = array(
					'id'                 => $available_price_id,
					'product_id'         => isset( $available_product->stripe_product_id ) ? sanitize_text_field( (string) $available_product->stripe_product_id ) : '',
					'product_name'       => ! empty( $available_product->custom_label ) ? sanitize_text_field( (string) $available_product->custom_label ) : ( isset( $available_product->name ) ? sanitize_text_field( (string) $available_product->name ) : '' ),
					'amount'             => isset( $available_product->price_amount ) ? (float) $available_product->price_amount : 0,
					'currency'           => isset( $available_product->currency ) ? sanitize_key( (string) $available_product->currency ) : 'usd',
					'recurring_interval' => isset( $available_product->recurring_interval ) ? sanitize_key( (string) $available_product->recurring_interval ) : '',
					'duplicate_behavior' => $this->get_portal_product_duplicate_behavior( $available_product ),
					'upgrade_from_product_id' => ! empty( $available_product->portal_upgrade_from_product_id ) ? sanitize_text_field( (string) $available_product->portal_upgrade_from_product_id ) : '',
					'upgrade_from_subscription_id' => ! empty( $available_product->portal_upgrade_from_subscription_id ) ? sanitize_text_field( (string) $available_product->portal_upgrade_from_subscription_id ) : '',
				);
			}
			$allowed_price_map = array_column( $this->apply_public_product_dependency_settings_to_prices( array_values( $allowed_price_map ) ), null, 'id' );
			foreach ( array_values( $allowed_price_map ) as $allowed_price ) {
				$required_price_id = ! empty( $allowed_price['requires_price_id'] ) ? sanitize_text_field( (string) $allowed_price['requires_price_id'] ) : '';
				if ( '' === $required_price_id || ! empty( $allowed_price_map[ $required_price_id ] ) ) {
					continue;
				}
				$required_price = $this->get_portal_dependency_price_checkout_data( $required_price_id );
				if ( $required_price ) {
					$allowed_price_map[ $required_price_id ] = $required_price;
				}
			}

			if ( ! is_array( $items ) ) {
				$portal_product = $this->get_portal_product_by_price_id( $price_id );
				if ( ! $portal_product || empty( $allowed_price_map[ $price_id ] ) ) {
					wp_send_json_error( __( 'Service is not available.', 'ajforms' ), 404 );
				}
				$checkout_mode = ! empty( $allowed_price_map[ $price_id ]['recurring_interval'] ) ? 'subscription' : 'payment';
			}
		} else {
			// Load the full public price set for public checkout so dependency rules can add required products
			// even when the required price was not part of the original submitted cart.
			$allowed_prices = $this->get_public_stripe_prices( array(), $include_archived );

			foreach ( $allowed_prices as $allowed_price ) {
				if ( is_array( $allowed_price ) && ! empty( $allowed_price['id'] ) ) {
					$allowed_price_map[ $allowed_price['id'] ] = $allowed_price;
				}
			}
		}

		// For public carts, checkout mode is decided from the selected line items after dependencies are normalized.

		if ( empty( $allowed_price_map ) || ( ! is_array( $items ) && ! $portal_add_service && empty( $allowed_price_map[ $price_id ] ) ) ) {
			wp_send_json_error( __( 'Product is not available.', 'ajforms' ), 404 );
		}

		if ( is_array( $items ) ) {
			$items = $this->normalize_public_cart_items_with_dependencies( $items, $allowed_price_map );
			$interval_conflict = $this->get_public_cart_recurring_interval_conflict( $items, $allowed_price_map );
			if ( ! empty( $interval_conflict ) ) {
				wp_send_json_error( __( 'Checkout does not support multiple subscription billing intervals in the same cart. Please checkout monthly and yearly subscriptions separately.', 'ajforms' ), 400 );
			}

			$checkout_mode = 'payment';
			foreach ( $items as $normalized_item ) {
				$normalized_price_id = is_array( $normalized_item ) && ! empty( $normalized_item['price_id'] ) ? sanitize_text_field( (string) $normalized_item['price_id'] ) : '';
				if ( $normalized_price_id && ! empty( $allowed_price_map[ $normalized_price_id ]['recurring_interval'] ) ) {
					$checkout_mode = 'subscription';
					break;
				}
			}
		} elseif ( ! $portal_add_service && ! empty( $allowed_price_map[ $price_id ] ) ) {
			$required_price_id = $this->get_public_price_required_price_id( $allowed_price_map[ $price_id ], $allowed_price_map );
			if ( '' !== $required_price_id ) {
				$items = array(
					array( 'price_id' => $required_price_id, 'quantity' => 1 ),
					array( 'price_id' => $price_id, 'quantity' => 1 ),
				);
			}
		}

		if ( ! $portal_add_service && ! is_array( $items ) && ! empty( $allowed_price_map[ $price_id ]['recurring_interval'] ) ) {
			$checkout_mode = 'subscription';
		} elseif ( ! $portal_add_service && is_array( $items ) ) {
			$checkout_mode = 'payment';
			foreach ( $items as $normalized_item ) {
				$normalized_price_id = is_array( $normalized_item ) && ! empty( $normalized_item['price_id'] ) ? sanitize_text_field( (string) $normalized_item['price_id'] ) : '';
				if ( $normalized_price_id && ! empty( $allowed_price_map[ $normalized_price_id ]['recurring_interval'] ) ) {
					$checkout_mode = 'subscription';
					break;
				}
			}
		}

		if ( is_array( $items ) && 'subscription' === $checkout_mode ) {
			$subscription_items = array();
			foreach ( $items as $normalized_item ) {
				if ( ! is_array( $normalized_item ) || empty( $normalized_item['price_id'] ) ) {
					continue;
				}

				$normalized_price_id = sanitize_text_field( (string) $normalized_item['price_id'] );
				if ( empty( $allowed_price_map[ $normalized_price_id ] ) ) {
					continue;
				}

				if ( ! empty( $allowed_price_map[ $normalized_price_id ]['recurring_interval'] ) ) {
					$subscription_items[] = $normalized_item;
					continue;
				}

				$item_currency = ! empty( $allowed_price_map[ $normalized_price_id ]['currency'] ) ? strtolower( sanitize_key( (string) $allowed_price_map[ $normalized_price_id ]['currency'] ) ) : 'usd';
				if ( '' === $deferred_one_time_currency ) {
					$deferred_one_time_currency = $item_currency;
				} elseif ( $deferred_one_time_currency !== $item_currency ) {
					wp_send_json_error( __( 'Checkout does not support mixed currencies in one cart.', 'ajforms' ), 400 );
				}

				$item_amount = isset( $allowed_price_map[ $normalized_price_id ]['amount'] ) ? (float) $allowed_price_map[ $normalized_price_id ]['amount'] : 0.0;
				$deferred_one_time_amount_minor += $this->convert_amount_to_minor_units( $item_amount, $item_currency );
				$deferred_one_time_items[] = $normalized_item;
			}

			if ( ! empty( $deferred_one_time_items ) ) {
				if ( empty( $subscription_items ) ) {
					$checkout_mode = 'payment';
				} else {
					$items = $subscription_items;
				}
			}
		}

		if ( $portal_add_service ) {
			$selected_items_for_upgrade = is_array( $items ) ? $items : array( array( 'price_id' => $price_id, 'quantity' => 1 ) );
			foreach ( $selected_items_for_upgrade as $selected_item ) {
				$selected_price_id = is_array( $selected_item ) && ! empty( $selected_item['price_id'] ) ? sanitize_text_field( (string) $selected_item['price_id'] ) : '';
				if ( '' === $selected_price_id || empty( $allowed_price_map[ $selected_price_id ] ) ) {
					continue;
				}

				$selected_allowed_price = $allowed_price_map[ $selected_price_id ];
				if ( 'upgrade' !== ( isset( $selected_allowed_price['duplicate_behavior'] ) ? sanitize_key( (string) $selected_allowed_price['duplicate_behavior'] ) : '' ) ) {
					continue;
				}

				$upgrade_from_subscription_id = ! empty( $selected_allowed_price['upgrade_from_subscription_id'] ) ? sanitize_text_field( (string) $selected_allowed_price['upgrade_from_subscription_id'] ) : '';
				if ( '' === $upgrade_from_subscription_id || 0 !== strpos( $upgrade_from_subscription_id, 'sub_' ) ) {
					wp_send_json_error( __( 'This upgrade is not available for your current services.', 'ajforms' ), 400 );
				}

				$portal_upgrade_items[] = array(
					'from_product_id'       => ! empty( $selected_allowed_price['upgrade_from_product_id'] ) ? sanitize_text_field( (string) $selected_allowed_price['upgrade_from_product_id'] ) : '',
					'from_subscription_id'  => $upgrade_from_subscription_id,
					'to_product_id'         => ! empty( $selected_allowed_price['product_id'] ) ? sanitize_text_field( (string) $selected_allowed_price['product_id'] ) : '',
					'to_price_id'           => $selected_price_id,
				);
			}
		}

		$stripe_settings = $this->get_stripe_settings();
		$mode_error = $this->get_stripe_mode_blocking_error( $stripe_settings );
		if ( '' !== $mode_error ) {
			wp_send_json_error( $mode_error, 400 );
		}
		if ( empty( $stripe_settings['secret_key'] ) ) {
			wp_send_json_error( __( 'Stripe is not connected.', 'ajforms' ), 400 );
		}

		$current_url = isset( $_POST['current_url'] ) ? esc_url_raw( wp_unslash( $_POST['current_url'] ) ) : home_url( '/' );
		if ( $embedded_checkout ) {
			$current_url = $this->normalize_embedded_checkout_url( $current_url );
		}
		$success_url = add_query_arg(
			array(
				'ajcore_checkout' => 'success',
				'session_id'      => '{CHECKOUT_SESSION_ID}',
			),
			$current_url
		);
		$success_url = str_replace( '%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $success_url );
		$cancel_url  = add_query_arg( 'ajcore_checkout', 'cancelled', $current_url );
		$body        = array(
			'mode'        => $checkout_mode,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
		);
		if ( $embedded_checkout ) {
			$body = array(
				'mode'       => $checkout_mode,
				'ui_mode'    => 'embedded',
				'return_url' => str_replace(
					'%7BCHECKOUT_SESSION_ID%7D',
					'{CHECKOUT_SESSION_ID}',
					add_query_arg(
						array(
							'ajcore_checkout' => 'success',
							'session_id'      => '{CHECKOUT_SESSION_ID}',
						),
						$current_url
					)
				),
			);
		}

		/*
		 * Collect contact details directly in Stripe Checkout as Stripe's native customer
		 * fields, instead of custom fields that only live on the Checkout Session. This keeps
		 * Stripe Dashboard's Customer record populated when Checkout creates/updates the
		 * customer: Business name and Individual name land under Customer > More options,
		 * phone on the customer itself. All three are required, matching email (which Stripe
		 * always requires).
		 */
		if ( ! $portal_add_service ) {
			$body['name_collection[business][enabled]']    = 'true';
			$body['name_collection[business][optional]']   = 'false';
			$body['name_collection[individual][enabled]']  = 'true';
			$body['name_collection[individual][optional]'] = 'false';
			$body['phone_number_collection[enabled]']      = 'true';
		}

		$mapped_stripe_customer_id = is_user_logged_in() ? $this->get_current_user_stripe_customer_id() : '';
		if ( 0 === strpos( $mapped_stripe_customer_id, 'cus_' ) ) {
			$body['customer'] = $mapped_stripe_customer_id;
			// Stripe requires customer_update[phone]=auto to collect a phone number when an
			// existing Customer is attached to the session; without it session creation fails.
			if ( ! empty( $body['phone_number_collection[enabled]'] ) ) {
				$body['customer_update[phone]'] = 'auto';
			}
		} elseif ( 'payment' === $checkout_mode ) {
			$body['customer_creation'] = 'always';
		}

		if ( is_array( $items ) ) {
			$line_index = 0;
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['price_id'] ) ) {
					continue;
				}

				$item_price_id = sanitize_text_field( (string) $item['price_id'] );
				$quantity      = 1;

				if ( empty( $allowed_price_map[ $item_price_id ] ) ) {
					continue;
				}

				$body[ 'line_items[' . $line_index . '][price]' ]    = $item_price_id;
				$body[ 'line_items[' . $line_index . '][quantity]' ] = $quantity;
				$line_index++;
			}

			if ( 0 === $line_index ) {
				wp_send_json_error( __( 'Your cart is empty.', 'ajforms' ), 400 );
			}

			$body['metadata[source]'] = $portal_add_service ? 'ajcore_portal_add_service' : 'ajcore_products_cart';
			if ( ! empty( $deferred_one_time_items ) ) {
				$deferred_price_ids = array_values(
					array_filter(
						array_map(
							function ( $item ) {
								return is_array( $item ) && ! empty( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '';
							},
							$deferred_one_time_items
						)
					)
				);
				$body['metadata[source]'] = $portal_add_service ? 'ajcore_portal_mixed_cart_subscription' : 'ajcore_mixed_cart_subscription';
				$body['metadata[ajcore_one_time_price_ids]'] = implode( ',', $deferred_price_ids );
				$body['metadata[ajcore_one_time_amount]'] = (string) absint( $deferred_one_time_amount_minor );
				$body['metadata[ajcore_one_time_currency]'] = '' !== $deferred_one_time_currency ? $deferred_one_time_currency : 'usd';
				$body['subscription_data[metadata][ajcore_one_time_price_ids]'] = implode( ',', $deferred_price_ids );
				$body['subscription_data[metadata][ajcore_one_time_amount]'] = (string) absint( $deferred_one_time_amount_minor );
				$body['subscription_data[metadata][ajcore_one_time_currency]'] = '' !== $deferred_one_time_currency ? $deferred_one_time_currency : 'usd';
				$body['subscription_data[metadata][ajcore_checkout_session_source]'] = $body['metadata[source]'];
			}
			if ( $portal_add_service && '' !== $mapped_stripe_customer_id ) {
				$body['metadata[stripe_customer_id]'] = $mapped_stripe_customer_id;
			}
		} else {
			$price = isset( $allowed_price_map[ $price_id ] ) ? $allowed_price_map[ $price_id ] : reset( $allowed_price_map );
			$body['line_items[0][price]']    = $price_id;
			$body['line_items[0][quantity]'] = 1;
			$body['metadata[price_id]']      = $price_id;
			$body['metadata[product_id]']    = isset( $price['product_id'] ) ? $price['product_id'] : '';
			$body['metadata[source]']        = $portal_add_service ? 'ajcore_portal_add_service' : 'ajcore_products';
			if ( $portal_add_service && '' !== $mapped_stripe_customer_id ) {
				$body['metadata[stripe_customer_id]'] = $mapped_stripe_customer_id;
			}
		}

		if ( $portal_add_service && ! empty( $portal_upgrade_items ) ) {
			$primary_upgrade = reset( $portal_upgrade_items );
			$body['metadata[ajcore_upgrade]'] = '1';
			$body['metadata[ajcore_upgrade_items]'] = wp_json_encode( $portal_upgrade_items );
			$body['metadata[ajcore_upgrade_from_product_id]'] = $primary_upgrade['from_product_id'];
			$body['metadata[ajcore_upgrade_from_subscription_id]'] = $primary_upgrade['from_subscription_id'];
			$body['metadata[ajcore_upgrade_to_product_id]'] = $primary_upgrade['to_product_id'];
			$body['metadata[ajcore_upgrade_to_price_id]'] = $primary_upgrade['to_price_id'];
			if ( 'subscription' === $checkout_mode ) {
				$body['subscription_data[metadata][ajcore_upgrade]'] = '1';
				$body['subscription_data[metadata][ajcore_upgrade_items]'] = wp_json_encode( $portal_upgrade_items );
				$body['subscription_data[metadata][ajcore_upgrade_from_subscription_id]'] = $primary_upgrade['from_subscription_id'];
			}
		}


		if ( 'subscription' === $checkout_mode && ! empty( $deferred_one_time_items ) ) {
			$mixed_checkout_message = $this->get_mixed_checkout_custom_text_message( $items, $allowed_price_map, $deferred_one_time_amount_minor, $deferred_one_time_currency );
			if ( '' !== $mixed_checkout_message ) {
				$body['custom_text[submit][message]']       = $mixed_checkout_message;
				$body['custom_text[after_submit][message]'] = $mixed_checkout_message;
			}
		}

		$response         = $this->stripe_api_request(
			'checkout/sessions',
			$stripe_settings['secret_key'],
			$body,
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 400 );
		}

		if ( $portal_add_service && ! empty( $response['id'] ) && '' !== $mapped_stripe_customer_id ) {
			global $wpdb;
			$line_price_ids = is_array( $items )
				? array_values(
					array_filter(
						array_map(
							function ( $item ) {
								return is_array( $item ) && ! empty( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '';
							},
							$items
						)
					)
				)
				: array( $price_id );
			$product_names = array();
			$total_amount  = 0.0;
			$currency      = 'usd';
			foreach ( $line_price_ids as $line_price_id ) {
				$line_product = $this->get_portal_product_by_price_id( $line_price_id );
				if ( $line_product ) {
					$product_names[] = ! empty( $line_product->custom_label ) ? sanitize_text_field( (string) $line_product->custom_label ) : ( ! empty( $line_product->name ) ? sanitize_text_field( (string) $line_product->name ) : $line_price_id );
					$total_amount   += isset( $line_product->price_amount ) ? (float) $line_product->price_amount : 0;
					$currency        = isset( $line_product->currency ) ? sanitize_key( (string) $line_product->currency ) : $currency;
				}
			}
			$product_name = ! empty( $product_names ) ? implode( ', ', array_unique( $product_names ) ) : __( 'Additional services', 'ajforms' );
			$product_description = sprintf( __( 'Service request: %s', 'ajforms' ), $product_name );
			$checkout_url = ! empty( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '';
			$metadata = array(
				'checkout_url' => $checkout_url,
				'checkout_session_client_secret' => ! empty( $response['client_secret'] ) ? sanitize_text_field( (string) $response['client_secret'] ) : '',
				'price_id'     => is_array( $items ) ? '' : $price_id,
				'price_ids'    => $line_price_ids,
				'product_id'   => ! is_array( $items ) && isset( $allowed_price_map[ $price_id ]['product_id'] ) ? $allowed_price_map[ $price_id ]['product_id'] : '',
				'product_name' => $product_name,
				'source'       => 'portal_add_service',
				'upgrade_items' => $portal_upgrade_items,
			);

			$wpdb->replace(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'source_object_id'   => sanitize_text_field( (string) $response['id'] ),
					'source_type'        => 'checkout_session',
					'ledger_date'        => current_time( 'mysql' ),
					'description'        => $product_description,
					'amount'             => $total_amount,
					'currency'           => $currency,
					'status'             => 'unpaid',
					'invoice_id'         => '',
					'payment_intent_id'  => '',
					'charge_id'          => '',
					'metadata'           => wp_json_encode( $metadata ),
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->upsert_portal_service_request(
				array(
					'wp_user_id'         => get_current_user_id(),
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'stripe_price_id'    => is_array( $items ) ? '' : $price_id,
					'stripe_product_id'  => ! is_array( $items ) && isset( $allowed_price_map[ $price_id ]['product_id'] ) ? $allowed_price_map[ $price_id ]['product_id'] : '',
					'service_name'       => $product_name,
					'request_type'       => 'add_service',
					'status'             => 'pending_payment',
					'amount'             => $total_amount,
					'currency'           => $currency,
					'source_object_id'   => sanitize_text_field( (string) $response['id'] ),
					'source_type'        => 'checkout_session',
					'source'             => 'client_portal',
					'created_by'         => get_current_user_id(),
					'raw_data'           => $response,
				)
			);
		}

		wp_send_json_success(
			array(
				'url' => isset( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '',
				'client_secret' => isset( $response['client_secret'] ) ? trim( sanitize_text_field( rawurldecode( (string) $response['client_secret'] ) ) ) : '',
				'publishable_key' => isset( $stripe_settings['publishable_key'] ) ? trim( sanitize_text_field( (string) $stripe_settings['publishable_key'] ) ) : '',
				'session_id'      => isset( $response['id'] ) ? sanitize_text_field( (string) $response['id'] ) : '',
			)
		);
	}


	public function ajax_pay_portal_ledger() {
		$this->block_impersonated_portal_write();
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'You must be logged in to pay this balance.', 'ajforms' ), 401 );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( '' === $stripe_customer_id || ! wp_verify_nonce( $nonce, 'ajcore_pay_portal_ledger_' . $stripe_customer_id ) ) {
			wp_send_json_error( __( 'Invalid payment request.', 'ajforms' ), 400 );
		}

		$raw_ledger_ids = isset( $_POST['ledger_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ledger_ids'] ) ) : '';
		$ledger_ids = array();
		if ( '' !== $raw_ledger_ids && 'all' !== strtolower( $raw_ledger_ids ) ) {
			$ledger_ids = array_filter( array_map( 'absint', preg_split( '/[,|]/', $raw_ledger_ids ) ) );
		}

		$payment_amount_raw = isset( $_POST['payment_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_amount'] ) ) : '';
		if ( false !== strpos( (string) $payment_amount_raw, '-' ) ) {
			wp_send_json_error( __( 'Negative payment amounts are not allowed.', 'ajforms' ), 400 );
		}
		$payment_amount_raw = preg_replace( '/[^0-9.]/', '', (string) $payment_amount_raw );
		$payment_amount     = '' !== $payment_amount_raw ? round( (float) $payment_amount_raw, 2 ) : 0.0;
		if ( $payment_amount < 0 ) {
			wp_send_json_error( __( 'Negative payment amounts are not allowed.', 'ajforms' ), 400 );
		}

		$payment_currency = isset( $_POST['payment_currency'] ) ? sanitize_key( wp_unslash( $_POST['payment_currency'] ) ) : 'usd';
		if ( '' === $payment_currency ) {
			$payment_currency = 'usd';
		}

		$entries = array();
		if ( '' !== $raw_ledger_ids ) {
			$entries = $this->get_current_user_open_portal_ledger( $ledger_ids );
		}

		if ( empty( $entries ) && $payment_amount <= 0 ) {
			wp_send_json_error( __( 'Enter a payment amount greater than $0.00.', 'ajforms' ), 400 );
		}

		$currency = $payment_currency;
		if ( ! empty( $entries ) ) {
			$currency = strtolower( (string) $entries[0]->currency );
			foreach ( $entries as $entry ) {
				if ( strtolower( (string) $entry->currency ) !== $currency ) {
					wp_send_json_error( __( 'Open charges with different currencies must be paid separately.', 'ajforms' ), 400 );
				}
			}
		}

		$open_entries_total = 0.0;
		foreach ( $entries as $entry ) {
			$open_entries_total += (float) $entry->amount;
		}

		$use_custom_amount = $payment_amount > 0;
		$should_reconcile_ledgers = ( ! $use_custom_amount && ! empty( $entries ) ) || ( $use_custom_amount && ! empty( $entries ) && abs( $payment_amount - $open_entries_total ) < 0.005 );

		$stripe_settings = $this->get_stripe_settings();
		$mode_error = $this->get_stripe_mode_blocking_error( $stripe_settings );
		if ( '' !== $mode_error ) {
			wp_send_json_error( $mode_error, 400 );
		}
		if ( empty( $stripe_settings['secret_key'] ) ) {
			wp_send_json_error( __( 'Stripe is not connected.', 'ajforms' ), 400 );
		}

		$current_url = isset( $_POST['current_url'] ) ? esc_url_raw( wp_unslash( $_POST['current_url'] ) ) : $this->get_customer_portal_url();
		$success_url = add_query_arg( 'ajcore_balance_payment', 'success', $current_url );
		$cancel_url  = add_query_arg( 'ajcore_balance_payment', 'cancelled', $current_url );
		$body = array(
			'mode'        => 'payment',
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
			'customer'    => $stripe_customer_id,
			'metadata[source]' => 'ajcore_portal_balance_payment',
			'metadata[stripe_customer_id]' => $stripe_customer_id,
			'metadata[payment_amount]' => $use_custom_amount ? number_format( $payment_amount, 2, '.', '' ) : number_format( $open_entries_total, 2, '.', '' ),
			'metadata[payment_currency]' => $currency,
		);
		if ( $should_reconcile_ledgers ) {
			$body['metadata[ledger_ids]'] = implode( ',', wp_list_pluck( $entries, 'id' ) );
		} else {
			$body['metadata[ledger_ids]'] = '';
			$body['metadata[account_payment_only]'] = '1';
		}

		$line_index = 0;
		if ( $use_custom_amount && ! $should_reconcile_ledgers ) {
			$amount_minor = $this->convert_amount_to_minor_units( $payment_amount, $currency );
			if ( $amount_minor <= 0 ) {
				wp_send_json_error( __( 'Enter a payment amount greater than $0.00.', 'ajforms' ), 400 );
			}
			$body[ 'line_items[' . $line_index . '][price_data][currency]' ] = strtolower( (string) $currency );
			$body[ 'line_items[' . $line_index . '][price_data][unit_amount]' ] = $amount_minor;
			$body[ 'line_items[' . $line_index . '][price_data][product_data][name]' ] = __( 'Account payment', 'ajforms' );
			$body[ 'line_items[' . $line_index . '][quantity]' ] = 1;
			$line_index++;
		} else {
			foreach ( $entries as $entry ) {
				$amount_minor = $this->convert_amount_to_minor_units( (float) $entry->amount, $entry->currency );
				if ( $amount_minor <= 0 ) {
					continue;
				}
				$body[ 'line_items[' . $line_index . '][price_data][currency]' ] = strtolower( (string) $entry->currency );
				$body[ 'line_items[' . $line_index . '][price_data][unit_amount]' ] = $amount_minor;
				$body[ 'line_items[' . $line_index . '][price_data][product_data][name]' ] = wp_strip_all_tags( (string) $entry->description );
				$body[ 'line_items[' . $line_index . '][quantity]' ] = 1;
				$line_index++;
			}
		}

		if ( 0 === $line_index ) {
			wp_send_json_error( __( 'No payable charges were found.', 'ajforms' ), 400 );
		}


		if ( 'subscription' === $checkout_mode && ! empty( $deferred_one_time_items ) ) {
			$mixed_checkout_message = $this->get_mixed_checkout_custom_text_message( $items, $allowed_price_map, $deferred_one_time_amount_minor, $deferred_one_time_currency );
			if ( '' !== $mixed_checkout_message ) {
				$body['custom_text[submit][message]']       = $mixed_checkout_message;
				$body['custom_text[after_submit][message]'] = $mixed_checkout_message;
			}
		}

		$response = $this->stripe_api_request(
			'checkout/sessions',
			$stripe_settings['secret_key'],
			$body
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 400 );
		}

		$checkout_session_id = ! empty( $response['id'] ) ? sanitize_text_field( (string) $response['id'] ) : '';
		if ( $checkout_session_id && $should_reconcile_ledgers ) {
			global $wpdb;
			foreach ( $entries as $entry ) {
				$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
				$metadata['payment_checkout_session_id'] = $checkout_session_id;
				if ( ! empty( $response['url'] ) ) {
					$metadata['payment_url'] = esc_url_raw( (string) $response['url'] );
				}
				$wpdb->update(
					$this->get_portal_ledger_table(),
					array(
						'status'   => 'pending_payment',
						'metadata' => wp_json_encode( $metadata ),
					),
					array( 'id' => absint( $entry->id ), 'stripe_customer_id' => $stripe_customer_id ),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
			}
		}

		wp_send_json_success(
			array(
				'url' => ! empty( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '',
			)
		);
	}


	private function validate_stripe_payment_submission( $form, $settings ) {
		$stripe_config = $this->get_stripe_payment_config( $form, $settings );
		$mode_error = $this->get_stripe_mode_blocking_error( $stripe_config );
		if ( '' !== $mode_error ) {
			return new WP_Error( 'stripe_mode_key_mismatch', $mode_error );
		}

		if ( ! $stripe_config['enabled'] ) {
			return true;
		}

		$payment_intent_id = isset( $_POST['ajf_stripe_payment_intent'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_stripe_payment_intent'] ) ) : '';
		if ( '' === $payment_intent_id ) {
			return new WP_Error( 'stripe_missing_payment', __( 'Complete the Stripe payment before submitting the form.', 'ajforms' ) );
		}

		$payment_intent = $this->stripe_api_request(
			'payment_intents/' . rawurlencode( $payment_intent_id ),
			$stripe_config['secret_key'],
			array(),
			'GET'
		);

		if ( is_wp_error( $payment_intent ) ) {
			return $payment_intent;
		}

		$expected_amount = $this->convert_amount_to_minor_units( $stripe_config['amount'], $stripe_config['currency'] );
		$actual_amount   = isset( $payment_intent['amount'] ) ? absint( $payment_intent['amount'] ) : 0;
		$actual_status   = isset( $payment_intent['status'] ) ? sanitize_key( $payment_intent['status'] ) : '';
		$actual_currency = isset( $payment_intent['currency'] ) ? strtolower( sanitize_key( $payment_intent['currency'] ) ) : '';
		$form_meta_id    = isset( $payment_intent['metadata']['form_id'] ) ? absint( $payment_intent['metadata']['form_id'] ) : 0;

		if ( 'succeeded' !== $actual_status ) {
			return new WP_Error( 'stripe_not_paid', __( 'Stripe payment is not complete yet.', 'ajforms' ) );
		}

		$actual_price_id = isset( $payment_intent['metadata']['price_id'] ) ? sanitize_text_field( (string) $payment_intent['metadata']['price_id'] ) : '';

		if ( $expected_amount !== $actual_amount || strtolower( $stripe_config['currency'] ) !== $actual_currency || absint( $form->id ) !== $form_meta_id || ( '' !== $stripe_config['price_id'] && $stripe_config['price_id'] !== $actual_price_id ) ) {
			return new WP_Error( 'stripe_payment_mismatch', __( 'Stripe payment details do not match this form submission.', 'ajforms' ) );
		}

		return array(
			'payment_intent_id' => $payment_intent_id,
			'amount'            => $stripe_config['amount'],
			'currency'          => strtoupper( $stripe_config['currency'] ),
			'description'       => $stripe_config['description'],
			'price_id'          => $stripe_config['price_id'],
			'product_id'        => $stripe_config['product_id'],
		);
	}

	private function get_forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_forms_forms';
	}

	private function render_product_rich_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		if ( $text !== wp_strip_all_tags( $text ) ) {
			return wp_kses_post( wpautop( $text ) );
		}

		$lines          = preg_split( '/\r\n|\r|\n/', $text );
		$html           = '';
		$paragraph      = array();
		$list_is_open   = false;

		$flush_paragraph = function () use ( &$html, &$paragraph ) {
			if ( empty( $paragraph ) ) {
				return;
			}

			$html     .= '<p>' . esc_html( implode( ' ', $paragraph ) ) . '</p>';
			$paragraph = array();
		};

		$close_list = function () use ( &$html, &$list_is_open ) {
			if ( $list_is_open ) {
				$html        .= '</ul>';
				$list_is_open = false;
			}
		};

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				$flush_paragraph();
				$close_list();
				continue;
			}

			if ( preg_match( '/^###\s+(.+)$/', $line, $matches ) ) {
				$flush_paragraph();
				$close_list();
				$html .= '<h4>' . esc_html( $matches[1] ) . '</h4>';
				continue;
			}

			if ( preg_match( '/^##?\s+(.+)$/', $line, $matches ) ) {
				$flush_paragraph();
				$close_list();
				$html .= '<h3>' . esc_html( $matches[1] ) . '</h3>';
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.+)$/', $line, $matches ) ) {
				$flush_paragraph();
				if ( ! $list_is_open ) {
					$html        .= '<ul>';
					$list_is_open = true;
				}
				$html .= '<li>' . esc_html( $matches[1] ) . '</li>';
				continue;
			}

			$close_list();
			$paragraph[] = $line;
		}

		$flush_paragraph();
		$close_list();

		return wp_kses_post( $html );
	}

	private function format_checkout_notice_money( $amount_minor, $currency ) {
		$currency = strtoupper( sanitize_key( (string) $currency ) );
		$amount   = $this->stripe_minor_amount_to_decimal( $amount_minor, $currency );

		return trim( $currency . ' ' . number_format_i18n( $amount, 2 ) );
	}

	private function stripe_minor_amount_to_decimal( $amount_minor, $currency ) {
		$currency = strtolower( sanitize_key( (string) $currency ) );
		$amount   = (float) $amount_minor;

		return in_array( $currency, array( 'jpy', 'krw', 'vnd' ), true ) ? $amount : $amount / 100;
	}

	private function get_checkout_success_notice_html() {
		$checkout_status = isset( $_GET['ajcore_checkout'] ) ? sanitize_key( wp_unslash( $_GET['ajcore_checkout'] ) ) : '';
		$session_id      = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		if ( 'success' !== $checkout_status ) {
			return '';
		}

		$stripe_settings = $this->get_stripe_settings();
		if ( '' === $session_id || 0 !== strpos( $session_id, 'cs_' ) || empty( $stripe_settings['secret_key'] ) ) {
			return '<strong>' . esc_html__( 'Checkout complete.', 'ajforms' ) . '</strong><span>' . esc_html__( 'Your order was received.', 'ajforms' ) . '</span>';
		}

		$session = $this->stripe_api_request(
			'checkout/sessions/' . rawurlencode( $session_id ),
			$stripe_settings['secret_key'],
			array(),
			'GET'
		);
		if ( is_wp_error( $session ) ) {
			return '<strong>' . esc_html__( 'Checkout complete.', 'ajforms' ) . '</strong><span>' . esc_html__( 'Your order was received.', 'ajforms' ) . '</span>';
		}

		$currency     = ! empty( $session['currency'] ) ? strtolower( sanitize_key( (string) $session['currency'] ) ) : 'usd';
		$session_mode = ! empty( $session['mode'] ) ? sanitize_key( (string) $session['mode'] ) : '';
		$metadata     = ! empty( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$one_time     = ! empty( $metadata['ajcore_one_time_amount'] ) ? absint( $metadata['ajcore_one_time_amount'] ) : 0;
		$checkout_amount = isset( $session['amount_total'] ) ? absint( $session['amount_total'] ) : 0;
		$subscription_amount = 'subscription' === $session_mode ? $checkout_amount : 0;
		$direct_payment_amount = 'payment' === $session_mode ? $checkout_amount : 0;
		$total_today = $subscription_amount + $direct_payment_amount + $one_time;

		$rows = array();
		if ( $subscription_amount > 0 ) {
			$rows[] = array(
				'label' => __( 'Subscription checkout', 'ajforms' ),
				'value' => $this->format_checkout_notice_money( $subscription_amount, $currency ),
				'note'  => __( 'Recurring service charged by Stripe Checkout.', 'ajforms' ),
			);
		}
		if ( $one_time > 0 ) {
			$rows[] = array(
				'label' => __( 'One-time items', 'ajforms' ),
				'value' => $this->format_checkout_notice_money( $one_time, $currency ),
				'note'  => __( 'Charged separately after checkout using the same payment method.', 'ajforms' ),
			);
		}
		if ( $direct_payment_amount > 0 ) {
			$rows[] = array(
				'label' => __( 'One-time checkout', 'ajforms' ),
				'value' => $this->format_checkout_notice_money( $direct_payment_amount, $currency ),
				'note'  => __( 'One-time payment charged by Stripe Checkout.', 'ajforms' ),
			);
		}

		if ( empty( $rows ) ) {
			$rows[] = array(
				'label' => __( 'Checkout', 'ajforms' ),
				'value' => $this->format_checkout_notice_money( $checkout_amount, $currency ),
				'note'  => __( 'Payment processed by Stripe.', 'ajforms' ),
			);
			$total_today = $checkout_amount;
		}

		$html  = '<strong>' . esc_html__( 'Checkout complete.', 'ajforms' ) . '</strong>';
		$html .= '<span>' . esc_html__( 'Your payment was processed. Here is what was charged today:', 'ajforms' ) . '</span>';
		$html .= '<div class="ajcore-checkout-receipt">';
		foreach ( $rows as $row ) {
			$html .= '<div><b>' . esc_html( $row['label'] ) . '</b><em>' . esc_html( $row['value'] ) . '</em><small>' . esc_html( $row['note'] ) . '</small></div>';
		}
		$html .= '<div class="ajcore-checkout-receipt-total"><b>' . esc_html__( 'Total charged today', 'ajforms' ) . '</b><em>' . esc_html( $this->format_checkout_notice_money( $total_today, $currency ) ) . '</em></div>';
		$html .= '</div>';

		return $html;
	}

	public function render_products_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'price_ids' => '',
				'columns'   => '3',
				'button'    => __( 'Buy Now', 'ajforms' ),
				'mode'      => 'buy',
				'display'   => '',
				'cart'      => '',
				'show'      => '',
				'template'  => 'default',
				'details'   => 'none',
				'include_archived' => 'no',
			),
			$atts,
			'ajcore_products'
		);

		$requested_price_ids = array_filter( array_map( 'trim', explode( ',', (string) $atts['price_ids'] ) ) );
		$include_archived    = in_array( strtolower( (string) $atts['include_archived'] ), array( '1', 'true', 'yes' ), true );
		$prices              = $this->get_public_stripe_prices( $requested_price_ids, $include_archived );
		$stripe_settings     = $this->get_stripe_settings();
		$columns             = min( 4, max( 1, absint( $atts['columns'] ) ) );
		$requested_mode      = '' !== (string) $atts['display'] ? sanitize_key( $atts['display'] ) : sanitize_key( $atts['mode'] );
		$mode                = in_array( $requested_mode, array( 'buy', 'cart' ), true ) ? $requested_mode : 'buy';
		if ( in_array( strtolower( (string) $atts['cart'] ), array( '1', 'true', 'yes' ), true ) ) {
			$mode = 'cart';
		}
		$is_cart_mode        = 'cart' === $mode;
		$template            = in_array( sanitize_key( $atts['template'] ), array( 'default', 'compact' ), true ) ? sanitize_key( $atts['template'] ) : 'default';
		$details_mode        = in_array( sanitize_key( $atts['details'] ), array( 'none', 'expand' ), true ) ? sanitize_key( $atts['details'] ) : 'none';
		$default_show        = 'compact' === $template ? 'title,summary,price,button' : 'title,description,price,button';
		$show_fields         = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', '' !== (string) $atts['show'] ? (string) $atts['show'] : $default_show ) ) ) );
		$show_title          = in_array( 'title', $show_fields, true );
		$show_description    = in_array( 'description', $show_fields, true );
		$show_summary        = in_array( 'summary', $show_fields, true );
		$show_price          = in_array( 'price', $show_fields, true );
		$show_button         = in_array( 'button', $show_fields, true );
		$checkout_notice_html = $this->get_checkout_success_notice_html();

		if ( empty( $prices ) ) {
			return '';
		}

		$public_price_map = array();
		foreach ( $prices as $public_price ) {
			if ( is_array( $public_price ) && ! empty( $public_price['id'] ) ) {
				$public_price_map[ $public_price['id'] ] = $public_price;
			}
		}

		ob_start();
		?>
		<?php if ( $is_cart_mode ) : ?>
			<script src="https://js.stripe.com/v3/"></script>
		<?php endif; ?>
		<div
			class="ajcore-products-wrap <?php echo $is_cart_mode ? 'ajcore-products-wrap-cart' : 'ajcore-products-wrap-buy'; ?> ajcore-products-template-<?php echo esc_attr( $template ); ?>"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-cart-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_cart_checkout' ) ); ?>"
			data-include-archived="<?php echo $include_archived ? 'yes' : 'no'; ?>"
		>
			<?php if ( $is_cart_mode ) : ?>
				<div class="ajcore-checkout-notice <?php echo '' !== $checkout_notice_html ? 'is-success' : ''; ?>" <?php echo '' === $checkout_notice_html ? 'hidden' : ''; ?>><?php echo wp_kses_post( $checkout_notice_html ); ?></div>
				<div class="ajcore-cart-mini" aria-live="polite">
					<button type="button" class="ajcore-cart-mini-button" aria-label="<?php echo esc_attr__( 'View selected products in cart', 'ajforms' ); ?>">
						<span class="ajcore-cart-mini-icon" aria-hidden="true">🛒</span>
						<span class="ajcore-cart-mini-label"><?php esc_html_e( 'Cart', 'ajforms' ); ?></span>
						<span class="ajcore-cart-mini-count">0</span>
					</button>
					<div class="ajcore-cart-mini-status"><?php esc_html_e( 'No products selected', 'ajforms' ); ?></div>
					<button type="button" class="ajcore-cart-mini-clear" disabled><?php esc_html_e( 'Clear', 'ajforms' ); ?></button>
					<button type="button" class="ajcore-cart-mini-checkout" disabled><?php esc_html_e( 'Checkout', 'ajforms' ); ?></button>
				</div>
			<?php endif; ?>
			<div class="ajcore-products-list" style="display:grid;grid-template-columns:repeat(<?php echo esc_attr( $columns ); ?>,minmax(0,1fr));gap:18px;">
				<?php foreach ( $prices as $price ) : ?>
					<?php
					$price_amount   = (float) $price['amount'];
					$price_currency = strtoupper( $price['currency'] );
					$price_interval = ! empty( $price['recurring_interval'] ) ? '/' . sanitize_key( (string) $price['recurring_interval'] ) : '';
					$price_label    = $price_currency . ' ' . number_format_i18n( $price_amount, 2 ) . $price_interval;
					$description    = isset( $price['product_description'] ) ? (string) $price['product_description'] : '';
					$rich_description = ! empty( $price['product_rich_description'] ) ? (string) $price['product_rich_description'] : $description;
					$plain_description = wp_strip_all_tags( $rich_description );
					$summary        = ! empty( $price['product_summary'] ) ? (string) $price['product_summary'] : wp_trim_words( $plain_description, 22 );
					$short_description = wp_trim_words( $plain_description, 34 );
					$required_price_id = $this->get_public_price_required_price_id( $price, $public_price_map );
					$required_product_name = '';
					if ( '' !== $required_price_id && ! empty( $public_price_map[ $required_price_id ]['product_name'] ) ) {
						$required_product_name = sanitize_text_field( (string) $public_price_map[ $required_price_id ]['product_name'] );
					} elseif ( ! empty( $price['requires_product_name'] ) ) {
						$required_product_name = sanitize_text_field( (string) $price['requires_product_name'] );
					}
					$dependency_note = ! empty( $price['dependency_note'] ) ? sanitize_textarea_field( (string) $price['dependency_note'] ) : '';
					if ( '' === $dependency_note && '' !== $required_product_name ) {
						$dependency_note = sprintf( __( 'This product requires %s. It will be added to your cart automatically.', 'ajforms' ), $required_product_name );
					}
					?>
					<div
						class="ajcore-product"
						data-price-id="<?php echo esc_attr( $price['id'] ); ?>"
						data-product-name="<?php echo esc_attr( $price['product_name'] ); ?>"
						data-amount="<?php echo esc_attr( $price_amount ); ?>"
						data-currency="<?php echo esc_attr( strtolower( $price['currency'] ) ); ?>"
						data-recurring-interval="<?php echo esc_attr( ! empty( $price['recurring_interval'] ) ? sanitize_key( (string) $price['recurring_interval'] ) : '' ); ?>"
						data-price-label="<?php echo esc_attr( $price_currency . ' ' . number_format_i18n( $price_amount, 2 ) . ( ! empty( $price['recurring_interval'] ) ? ' ' . sprintf( __( 'Per %s', 'ajforms' ), sanitize_key( (string) $price['recurring_interval'] ) ) : '' ) ); ?>"
						data-required-price-id="<?php echo esc_attr( $required_price_id ); ?>"
						data-required-product-name="<?php echo esc_attr( $required_product_name ); ?>"
						data-dependency-note="<?php echo esc_attr( $dependency_note ); ?>"
						style="border:1px solid #dfe6ee;border-radius:14px;background:#fff;padding:20px;box-shadow:0 14px 30px rgba(15,23,42,.06);"
					>
						<?php if ( $show_title ) : ?>
							<h3 class="ajcore-product-title" style="margin:0 0 10px;font-size:22px;line-height:1.2;"><?php echo esc_html( $price['product_name'] ); ?></h3>
						<?php endif; ?>
						<?php if ( $show_summary && '' !== $summary ) : ?>
							<div class="ajcore-product-summary" style="margin-bottom:12px;color:#64748b;line-height:1.45;"><?php echo esc_html( $summary ); ?></div>
						<?php endif; ?>
						<?php if ( $show_description && '' !== $rich_description ) : ?>
							<div class="ajcore-product-description" style="margin-bottom:10px;color:#64748b;">
								<?php echo esc_html( $short_description ); ?>
							</div>
						<?php elseif ( $show_description && ! empty( $price['nickname'] ) ) : ?>
							<div class="ajcore-product-description" style="margin-bottom:10px;color:#64748b;"><?php echo esc_html( $price['nickname'] ); ?></div>
						<?php endif; ?>
						<?php if ( 'expand' === $details_mode && '' !== $rich_description ) : ?>
							<details class="ajcore-product-details" style="margin:0 0 14px;color:#64748b;">
								<summary style="cursor:pointer;color:#0f7ac6;font-weight:700;"><?php esc_html_e( 'View full details', 'ajforms' ); ?></summary>
								<div style="margin-top:8px;line-height:1.45;">
									<?php echo $this->render_product_rich_text( $rich_description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</details>
						<?php endif; ?>
						<?php if ( $required_product_name ) : ?>
							<div class="ajcore-product-requires" style="margin:0 0 12px;color:#475569;font-size:13px;line-height:1.35;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:9px 10px;">
								<?php echo esc_html( sprintf( __( 'Includes required setup: %s', 'ajforms' ), $required_product_name ) ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $dependency_note ) : ?>
							<div class="ajcore-product-dependency-note" style="margin:12px 0 14px;padding:10px 12px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e40af;font-size:13px;font-weight:700;line-height:1.4;"><?php echo esc_html( $dependency_note ); ?></div>
						<?php endif; ?>

						<?php if ( $show_price ) : ?>
							<div class="ajcore-product-price" style="margin:12px 0 18px;font-size:28px;font-weight:800;color:#111827;">
								<?php echo esc_html( $price_label ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $show_button && $is_cart_mode ) : ?>
							<div class="ajcore-product-cart-controls" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
								<span class="ajcore-product-single-qty" style="display:inline-flex;align-items:center;min-height:40px;color:#475569;font-weight:800;"><?php esc_html_e( 'Qty 1', 'ajforms' ); ?></span>
								<input class="ajcore-product-quantity" type="hidden" value="1">
								<button
									type="button"
									class="ajcore-product-add"
									<?php disabled( empty( $stripe_settings['secret_key'] ) ); ?>
									style="background:#0f7ac6;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:800;cursor:pointer;"
								><?php esc_html_e( 'Add to Cart', 'ajforms' ); ?></button>
							</div>
						<?php elseif ( $show_button ) : ?>
							<button
								type="button"
								class="ajcore-product-buy"
								data-price-id="<?php echo esc_attr( $price['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_buy_product_' . $price['id'] ) ); ?>"
								<?php disabled( empty( $stripe_settings['secret_key'] ) ); ?>
								style="background:#0f7ac6;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:800;cursor:pointer;"
							><?php echo esc_html( $atts['button'] ); ?></button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $is_cart_mode ) : ?>
				<button type="button" class="ajcore-floating-cart" aria-label="<?php echo esc_attr__( 'View cart', 'ajforms' ); ?>">
					<span class="ajcore-floating-cart-icon" aria-hidden="true">🛒</span>
					<span class="ajcore-floating-cart-text"><?php esc_html_e( 'Cart', 'ajforms' ); ?></span>
					<span class="ajcore-floating-cart-count">0</span>
				</button>
				<aside class="ajcore-cart" aria-hidden="true" style="display:none;margin-top:22px;border:1px solid #dfe6ee;border-radius:14px;background:#fff;padding:20px;box-shadow:0 14px 30px rgba(15,23,42,.06);">
					<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;">
						<h3 style="margin:0;font-size:22px;line-height:1.2;"><?php esc_html_e( 'Cart', 'ajforms' ); ?></h3>
						<button type="button" class="ajcore-cart-clear" style="background:transparent;color:#64748b;border:0;padding:0;font-weight:700;cursor:pointer;"><?php esc_html_e( 'Clear', 'ajforms' ); ?></button>
					</div>
					<div class="ajcore-cart-empty" style="color:#64748b;"><?php esc_html_e( 'No products selected yet.', 'ajforms' ); ?></div>
					<div class="ajcore-cart-items" style="display:grid;gap:10px;"></div>
					<div class="ajcore-cart-total" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:22px;font-weight:800;color:#111827;"></div>
					<button type="button" class="ajcore-cart-checkout" disabled style="margin-top:16px;background:#0f7ac6;color:#fff;border:0;border-radius:10px;padding:13px 20px;font-weight:800;cursor:pointer;"><?php esc_html_e( 'Checkout', 'ajforms' ); ?></button>
					<p class="ajcore-cart-message" style="display:none;margin:12px 0 0;color:#b32d2e;"></p>
				</aside>
				<div class="ajcore-cart-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Review cart', 'ajforms' ); ?>">
					<div class="ajcore-cart-modal-backdrop" data-ajcore-cart-close="1"></div>
					<div class="ajcore-cart-modal-panel" role="document">
						<div class="ajcore-cart-modal-header">
							<div>
								<h3><?php esc_html_e( 'Review Cart', 'ajforms' ); ?></h3>
								<p><?php esc_html_e( 'Review selected products before continuing to Stripe checkout.', 'ajforms' ); ?></p>
							</div>
							<button type="button" class="ajcore-cart-modal-close" aria-label="<?php echo esc_attr__( 'Close cart', 'ajforms' ); ?>" data-ajcore-cart-close="1">&times;</button>
						</div>
						<div class="ajcore-cart-modal-empty"><?php esc_html_e( 'No products selected yet.', 'ajforms' ); ?></div>
						<div class="ajcore-cart-modal-items"></div>
						<div class="ajcore-cart-modal-note"></div>
						<div class="ajcore-cart-modal-footer">
							<div class="ajcore-cart-modal-total"></div>
							<div class="ajcore-cart-modal-actions">
								<button type="button" class="ajcore-cart-modal-clear"><?php esc_html_e( 'Clear Cart', 'ajforms' ); ?></button>
								<button type="button" class="ajcore-cart-modal-checkout" disabled><?php esc_html_e( 'Checkout', 'ajforms' ); ?></button>
							</div>
						</div>
						<div class="ajcore-cart-embedded-checkout" hidden>
							<div class="ajcore-cart-embedded-summary"></div>
							<div class="ajcore-cart-embedded-stripe">
								<div class="ajcore-cart-embedded-message" hidden></div>
								<div class="ajcore-cart-embedded-mount"></div>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			const root = document.currentScript.previousElementSibling;
			if (!root || root.dataset.ajcoreProductsReady) {
				return;
			}
			root.dataset.ajcoreProductsReady = '1';
			const isCartMode = root.dataset.mode === 'cart';
			const cart = {};
			const cartItems = root.querySelector('.ajcore-cart-items');
			const cartEmpty = root.querySelector('.ajcore-cart-empty');
			const cartTotal = root.querySelector('.ajcore-cart-total');
			const checkoutButton = root.querySelector('.ajcore-cart-checkout');
			const cartMessage = root.querySelector('.ajcore-cart-message');
			const floatingCartButton = root.querySelector('.ajcore-floating-cart');
			const floatingCartCount = root.querySelector('.ajcore-floating-cart-count');
			const miniCart = root.querySelector('.ajcore-cart-mini');
			const miniCartButton = root.querySelector('.ajcore-cart-mini-button');
			const miniCartCount = root.querySelector('.ajcore-cart-mini-count');
			const miniCartStatus = root.querySelector('.ajcore-cart-mini-status');
			const miniClearButton = root.querySelector('.ajcore-cart-mini-clear');
			const miniCheckoutButton = root.querySelector('.ajcore-cart-mini-checkout');
			const cartModal = root.querySelector('.ajcore-cart-modal');
			const cartModalItems = root.querySelector('.ajcore-cart-modal-items');
			const cartModalEmpty = root.querySelector('.ajcore-cart-modal-empty');
			const cartModalTotal = root.querySelector('.ajcore-cart-modal-total');
			const cartModalNote = root.querySelector('.ajcore-cart-modal-note');
			const cartModalClearButton = root.querySelector('.ajcore-cart-modal-clear');
			const cartModalCheckoutButton = root.querySelector('.ajcore-cart-modal-checkout');
			const embeddedCheckoutWrap = root.querySelector('.ajcore-cart-embedded-checkout');
			const embeddedCheckoutSummary = root.querySelector('.ajcore-cart-embedded-summary');
			const embeddedCheckoutMount = root.querySelector('.ajcore-cart-embedded-mount');
			const embeddedCheckoutMessage = root.querySelector('.ajcore-cart-embedded-message');
			const checkoutNotice = root.querySelector('.ajcore-checkout-notice');
			let ajcoreEmbeddedCheckout = null;
			let ajcoreStripeInstance = null;
			const cartModalHome = cartModal ? cartModal.parentNode : null;
			const cartModalPlaceholder = cartModal ? document.createComment('ajcore-cart-modal-home') : null;
			if (cartModal && cartModalHome && cartModalPlaceholder) {
				cartModalHome.insertBefore(cartModalPlaceholder, cartModal);
				if (cartModal.parentNode !== document.body) {
					document.body.appendChild(cartModal);
				}
			}
			const miniCartHome = miniCart ? miniCart.parentNode : null;
			const miniCartPlaceholder = miniCart ? document.createComment('ajcore-cart-mini-home') : null;
			if (miniCart && miniCartHome && miniCartPlaceholder) {
				miniCartHome.insertBefore(miniCartPlaceholder, miniCart);
			}

			function getMobileCartTopOffset() {
				const fallback = 180;
				let headerBottom = 0;
				const selectors = ['.site-header', '#masthead', 'header[role="banner"]', 'header', '.wp-site-blocks > header', '.main-header-bar'];
				for (let index = 0; index < selectors.length; index += 1) {
					const header = document.querySelector(selectors[index]);
					if (!header) {
						continue;
					}
					const rect = header.getBoundingClientRect();
					if (rect && rect.width > 0 && rect.bottom > 0) {
						headerBottom = Math.max(headerBottom, Math.round(rect.bottom));
					}
				}

				const rootRect = root.getBoundingClientRect();
				const cartHeight = miniCart && miniCart.offsetHeight ? miniCart.offsetHeight : 74;
				const safeTop = headerBottom + 18;
				const belowHeroTop = rootRect && rootRect.top > 0 ? Math.round(rootRect.top + 14) : fallback;
				const viewportMax = Math.max(safeTop, window.innerHeight - cartHeight - 18);
				const desiredTop = Math.max(safeTop, belowHeroTop);

				return Math.min(desiredTop, viewportMax);
			}

			function refreshMobileMiniCartPosition() {
				if (!miniCart || !miniCart.classList.contains('ajcore-mobile-fixed-cart')) {
					return;
				}

				miniCart.style.setProperty('--ajcore-cart-mobile-top', getMobileCartTopOffset() + 'px');
			}

			function mountMobileMiniCart() {
				if (!miniCart) {
					return;
				}

				const isMobile = window.matchMedia && window.matchMedia('(max-width: 800px)').matches;
				if (isMobile) {
					miniCart.classList.add('ajcore-mobile-fixed-cart');
					miniCart.style.setProperty('--ajcore-cart-mobile-top', getMobileCartTopOffset() + 'px');
					if (miniCart.parentNode !== document.body) {
						document.body.appendChild(miniCart);
					}
					return;
				}

				miniCart.classList.remove('ajcore-mobile-fixed-cart');
				miniCart.style.removeProperty('--ajcore-cart-mobile-top');
				if (miniCartHome && miniCartPlaceholder && miniCart.parentNode !== miniCartHome) {
					miniCartHome.insertBefore(miniCart, miniCartPlaceholder.nextSibling);
				}
			}

			mountMobileMiniCart();
			window.addEventListener('resize', function() {
				window.clearTimeout(root.ajcoreCartResizeTimer);
				root.ajcoreCartResizeTimer = window.setTimeout(mountMobileMiniCart, 120);
			});
			window.addEventListener('scroll', function() {
				window.clearTimeout(root.ajcoreCartScrollTimer);
				root.ajcoreCartScrollTimer = window.setTimeout(refreshMobileMiniCartPosition, 40);
			}, { passive: true });
			window.setTimeout(refreshMobileMiniCartPosition, 250);

			function formatCurrency(amount, currency) {
				try {
					return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format(amount);
				} catch (error) {
					return (currency || 'USD').toUpperCase() + ' ' + amount.toFixed(2);
				}
			}

			function preserveCartScroll(callback) {
				const scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
				const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
				callback();
				const restore = function() {
					window.scrollTo(scrollX, scrollY);
				};
				restore();
				window.setTimeout(restore, 0);
				window.requestAnimationFrame(restore);
				window.requestAnimationFrame(function() {
					window.requestAnimationFrame(restore);
				});
			}

			function setCartMessage(message) {
				if (cartMessage) {
					cartMessage.textContent = message || '';
					cartMessage.style.display = message ? 'block' : 'none';
				}

				let miniNotice = miniCart ? miniCart.querySelector('.ajcore-cart-mini-notice') : null;
				if (miniCart && !miniNotice) {
					miniNotice = document.createElement('div');
					miniNotice.className = 'ajcore-cart-mini-notice';
					miniCart.appendChild(miniNotice);
				}
				if (miniNotice) {
					miniNotice.textContent = message || '';
					miniNotice.style.display = message ? 'block' : 'none';
				}
			}

			const cartStorageKey = 'ajcoreProductsCart:' + window.location.pathname + ':' + (root.dataset.includeArchived || 'no');

			function saveCart() {
				try {
					window.sessionStorage.setItem(cartStorageKey, JSON.stringify(cart));
				} catch (error) {}
			}

			function loadCart() {
				try {
					const stored = window.sessionStorage.getItem(cartStorageKey);
					if (!stored) {
						return;
					}
					const decoded = JSON.parse(stored);
					if (!decoded || typeof decoded !== 'object') {
						return;
					}
					Object.keys(decoded).forEach(function(priceId) {
						const item = decoded[priceId];
						if (!item || !item.price_id || !getProductByPriceId(item.price_id)) {
							return;
						}
						cart[item.price_id] = {
							price_id: item.price_id,
							name: item.name || 'Product',
							amount: parseFloat(item.amount || '0') || 0,
							currency: item.currency || 'usd',
							recurring_interval: item.recurring_interval || '',
							price_label: item.price_label || '',
							required_price_id: item.required_price_id || '',
							quantity: 1,
							locked: item.locked || ''
						};
					});
				} catch (error) {}
			}

			function clearCart() {
				destroyEmbeddedCheckout();
				Object.keys(cart).forEach(function(priceId) {
					delete cart[priceId];
				});
				try {
					window.sessionStorage.removeItem(cartStorageKey);
				} catch (error) {}
				setCartMessage('');
				renderCart();
			}

			function clearAllStoredAjcoreProductCarts() {
				destroyEmbeddedCheckout();
				Object.keys(cart).forEach(function(priceId) {
					delete cart[priceId];
				});
				try {
					for (let index = window.sessionStorage.length - 1; index >= 0; index -= 1) {
						const key = window.sessionStorage.key(index);
						if (key && key.indexOf('ajcoreProductsCart:') === 0) {
							window.sessionStorage.removeItem(key);
						}
					}
				} catch (error) {}
			}

			function getCheckoutReturnStatus() {
				try {
					return new URLSearchParams(window.location.search).get('ajcore_checkout') || '';
				} catch (error) {
					return '';
				}
			}

			function showCheckoutNotice(message, type) {
				if (!checkoutNotice) {
					return;
				}
				checkoutNotice.textContent = message || '';
				checkoutNotice.hidden = !message;
				checkoutNotice.className = 'ajcore-checkout-notice ' + (type === 'error' ? 'is-error' : 'is-success');
			}

			function getProductByPriceId(priceId) {
				if (!priceId) {
					return null;
				}
				const products = root.querySelectorAll('.ajcore-product');
				for (let index = 0; index < products.length; index += 1) {
					if (products[index].dataset.priceId === priceId) {
						return products[index];
					}
				}
				return null;
			}

			function getIntervalLabel(interval) {
				interval = String(interval || '').toLowerCase();
				if (!interval) {
					return '';
				}
				if (interval === 'month') {
					return 'Per Month';
				}
				if (interval === 'year') {
					return 'Per Year';
				}
				if (interval === 'week') {
					return 'Per Week';
				}
				if (interval === 'day') {
					return 'Per Day';
				}
				return 'Per ' + interval;
			}

			function getProductPriceLabel(product) {
				if (!product || !product.dataset) {
					return '';
				}
				if (product.dataset.priceLabel) {
					return product.dataset.priceLabel;
				}
				const amount = parseFloat(product.dataset.amount || '0') || 0;
				const currency = product.dataset.currency || 'usd';
				const intervalLabel = getIntervalLabel(product.dataset.recurringInterval || '');
				return formatCurrency(amount, currency) + (intervalLabel ? ' ' + intervalLabel : '');
			}

			function getCartItemPriceLabel(item) {
				if (item && item.price_label) {
					return item.price_label;
				}
				const intervalLabel = getIntervalLabel(item && item.recurring_interval ? item.recurring_interval : '');
				return formatCurrency(item.amount, item.currency) + (intervalLabel ? ' ' + intervalLabel : '');
			}

			function getProductsRecurringIntervalConflict(productsToAdd) {
				const intervals = {};
				Object.values(cart).forEach(function(item) {
					const interval = item && item.recurring_interval ? String(item.recurring_interval) : '';
					if (interval) {
						intervals[interval] = true;
					}
				});
				(productsToAdd || []).forEach(function(product) {
					if (!product || !product.dataset) {
						return;
					}
					const interval = product.dataset.recurringInterval || '';
					if (interval) {
						intervals[interval] = true;
					}
				});
				const intervalList = Object.keys(intervals);
				return intervalList.length > 1 ? intervalList : [];
			}

			function getCartSignature(items) {
				return (items || Object.values(cart)).map(function(item) {
					return (item.price_id || '') + ':' + (parseInt(item.quantity, 10) || 1);
				}).sort().join('|');
			}

			function getMixedCartBreakdown(items) {
				const breakdown = {
					total: 0,
					one_time_total: 0,
					recurring_total: 0,
					currency: 'usd',
					interval: '',
					has_one_time: false,
					has_recurring: false
				};
				(items || Object.values(cart)).forEach(function(item) {
					const amount = (parseFloat(item.amount || '0') || 0) * (parseInt(item.quantity, 10) || 1);
					breakdown.total += amount;
					breakdown.currency = item.currency || breakdown.currency;
					if (item.recurring_interval) {
						breakdown.has_recurring = true;
						breakdown.recurring_total += amount;
						breakdown.interval = breakdown.interval || item.recurring_interval;
					} else {
						breakdown.has_one_time = true;
						breakdown.one_time_total += amount;
					}
				});
				return breakdown;
			}

			function cartNeedsMixedCheckoutReview(items) {
				const breakdown = getMixedCartBreakdown(items);
				return breakdown.has_one_time && breakdown.has_recurring;
			}

			function getMixedCheckoutReviewHtml(items) {
				const breakdown = getMixedCartBreakdown(items);
				if (!breakdown.has_one_time || !breakdown.has_recurring) {
					return '';
				}
				const intervalLabel = String(breakdown.interval || 'year').toLowerCase();
				return '<div class="ajcore-mixed-checkout-review">' +
					'<strong>Payment review before Stripe</strong>' +
					'<p><b>Today:</b> ' + formatCurrency(breakdown.total, breakdown.currency) + ' total.</p>' +
					'<p>This includes ' + formatCurrency(breakdown.one_time_total, breakdown.currency) + ' one-time and ' + formatCurrency(breakdown.recurring_total, breakdown.currency) + ' per ' + intervalLabel + ' recurring.</p>' +
					'<p><b>Renewal:</b> ' + formatCurrency(breakdown.recurring_total, breakdown.currency) + ' per ' + intervalLabel + ' after the first period.</p>' +
					'<p>You will enter payment once. Stripe may show the subscription portion on its checkout screen; AJ Core will charge the one-time portion immediately after successful checkout using the same payment method.</p>' +
					'</div>';
			}

			function upsertCartProduct(product, lockedReason) {
				if (!product) {
					return false;
				}
				const priceId = product.dataset.priceId;
				if (!priceId) {
					return false;
				}
				cart[priceId] = {
					price_id: priceId,
					name: product.dataset.productName || 'Product',
					amount: parseFloat(product.dataset.amount || '0') || 0,
					currency: product.dataset.currency || 'usd',
					recurring_interval: product.dataset.recurringInterval || '',
					price_label: getProductPriceLabel(product),
					required_price_id: product.dataset.requiredPriceId || '',
					quantity: 1,
					locked: lockedReason || ''
				};
				return true;
			}

			function addRequiredProductIfNeeded(product) {
				const requiredPriceId = product && product.dataset ? (product.dataset.requiredPriceId || '') : '';
				if (!requiredPriceId) {
					return { added: false, name: '', note: '' };
				}

				const requiredProduct = getProductByPriceId(requiredPriceId);
				if (!requiredProduct) {
					return { added: false, name: '', note: product.dataset.dependencyNote || '' };
				}

				const requiredName = requiredProduct.dataset.productName || 'Required product';
				const dependencyNote = product.dataset.dependencyNote || ('This product requires ' + requiredName + '. It was added to your cart automatically.');

				if (!cart[requiredPriceId]) {
					upsertCartProduct(requiredProduct, dependencyNote);
					return { added: true, name: requiredName, note: dependencyNote };
				}

				return { added: false, name: requiredName, note: dependencyNote };
			}

			function renderCart() {
				if (!isCartMode || !cartItems || !cartEmpty || !cartTotal || !checkoutButton) {
					return;
				}

				const items = Object.values(cart);
				const itemCount = items.reduce(function(total, item) {
					return total + (parseInt(item.quantity, 10) || 0);
				}, 0);
				if (floatingCartCount) {
					floatingCartCount.textContent = String(itemCount);
				}
				if (floatingCartButton) {
					floatingCartButton.classList.toggle('has-items', itemCount > 0);
				}
				if (miniCartCount) {
					miniCartCount.textContent = String(itemCount);
				}
				if (miniCartStatus) {
					miniCartStatus.textContent = itemCount ? (itemCount + ' selected') : 'No products selected';
				}
				if (miniCheckoutButton) {
					miniCheckoutButton.disabled = itemCount === 0;
				}
				if (miniClearButton) {
					miniClearButton.disabled = itemCount === 0;
				}
				cartItems.innerHTML = '';
				cartEmpty.style.display = items.length ? 'none' : 'block';
				checkoutButton.disabled = items.length === 0;
				let total = 0;
				let currency = 'usd';
				items.forEach(function(item) {
					total += item.amount * item.quantity;
					currency = item.currency;
					const row = document.createElement('div');
					row.className = 'ajcore-cart-row';
					row.style.cssText = 'display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;';
					row.innerHTML = '<div><strong></strong><div></div><small class="ajcore-cart-row-note"></small></div><span class="ajcore-cart-row-qty">Qty 1</span><button type="button">Remove</button>';
					row.querySelector('strong').textContent = item.name;
					row.querySelector('div div').textContent = getCartItemPriceLabel(item);
					const rowNote = row.querySelector('.ajcore-cart-row-note');
					if (rowNote) {
						rowNote.textContent = item.locked ? item.locked : '';
						rowNote.style.cssText = 'display:block;margin-top:3px;color:#64748b;font-size:12px;font-weight:700;';
					}
					const quantityBadge = row.querySelector('.ajcore-cart-row-qty');
					if (quantityBadge) {
						quantityBadge.style.cssText = 'display:inline-flex;align-items:center;min-height:34px;border:1px solid #e2e8f0;border-radius:999px;padding:0 10px;color:#475569;font-weight:800;background:#f8fafc;';
					}
					const removeButton = row.querySelector('button');
					removeButton.style.cssText = 'background:#fff;color:#b32d2e;border:1px solid #fecaca;border-radius:9px;padding:8px 10px;font-weight:700;cursor:pointer;';
					removeButton.addEventListener('click', function(event) {
						event.preventDefault();
						event.stopPropagation();
						preserveCartScroll(function() {
							delete cart[item.price_id];
							destroyEmbeddedCheckout();
							renderCart();
						});
					});
					cartItems.appendChild(row);
				});
				cartTotal.style.display = items.length ? 'block' : 'none';
				cartTotal.textContent = items.length ? 'Total: ' + formatCurrency(total, currency) : '';
				if (miniCartStatus && items.length) {
					miniCartStatus.textContent = itemCount + ' selected · ' + formatCurrency(total, currency);
				}
				if (cartModalItems && cartModalEmpty && cartModalTotal && cartModalCheckoutButton) {
					cartModalItems.innerHTML = '';
					cartModalEmpty.style.display = items.length ? 'none' : 'block';
					cartModalCheckoutButton.disabled = items.length === 0;
					if (cartModalClearButton) {
						cartModalClearButton.disabled = items.length === 0;
					}
					items.forEach(function(item) {
						const modalRow = document.createElement('div');
						modalRow.className = 'ajcore-cart-modal-row';
						modalRow.innerHTML = '<div class="ajcore-cart-modal-row-main"><strong></strong><span></span><small></small></div><span class="ajcore-cart-modal-row-qty">Qty 1</span><button type="button">Remove</button>';
						modalRow.querySelector('strong').textContent = item.name;
						modalRow.querySelector('span').textContent = getCartItemPriceLabel(item);
						const modalNote = modalRow.querySelector('small');
						modalNote.textContent = item.locked ? item.locked : '';
						modalNote.style.display = item.locked ? 'block' : 'none';
						modalRow.querySelector('button').addEventListener('click', function(event) {
							event.preventDefault();
							event.stopPropagation();
							delete cart[item.price_id];
							destroyEmbeddedCheckout();
							setCartMessage('');
							renderCart();
						});
						cartModalItems.appendChild(modalRow);
					});
					cartModalTotal.textContent = items.length ? 'Total: ' + formatCurrency(total, currency) : '';
					if (cartModalNote) {
						const messageText = cartMessage && cartMessage.textContent ? cartMessage.textContent : '';
						cartModalNote.textContent = messageText;
						cartModalNote.style.display = messageText ? 'block' : 'none';
					}
					if (cartModalCheckoutButton) {
						cartModalCheckoutButton.textContent = 'Secure Checkout';
					}
				}
			saveCart();
			refreshMobileMiniCartPosition();
			}

			function getCartItemCount() {
				return Object.values(cart).reduce(function(total, item) {
					return total + (parseInt(item.quantity, 10) || 0);
				}, 0);
			}

			function setEmbeddedCheckoutMessage(message) {
				if (!embeddedCheckoutMessage) {
					return;
				}
				embeddedCheckoutMessage.textContent = message || '';
				embeddedCheckoutMessage.hidden = !message;
			}

			function destroyEmbeddedCheckout() {
				if (ajcoreEmbeddedCheckout && typeof ajcoreEmbeddedCheckout.destroy === 'function') {
					ajcoreEmbeddedCheckout.destroy();
				}
				ajcoreEmbeddedCheckout = null;
				if (embeddedCheckoutMount) {
					embeddedCheckoutMount.innerHTML = '';
				}
				if (embeddedCheckoutWrap) {
					embeddedCheckoutWrap.hidden = true;
				}
				setEmbeddedCheckoutMessage('');
			}

			function renderEmbeddedCheckoutSummary(items) {
				if (!embeddedCheckoutSummary) {
					return;
				}
				const breakdown = getMixedCartBreakdown(items);
				const intervalLabel = String(breakdown.interval || 'year').toLowerCase();
				let html = '<h4>Payment Summary</h4><strong>Today due: ' + formatCurrency(breakdown.total, breakdown.currency) + '</strong>';
				if (breakdown.recurring_total > 0) {
					html += '<p>Subscription checkout: ' + formatCurrency(breakdown.recurring_total, breakdown.currency) + ' per ' + intervalLabel + '</p>';
				}
				if (breakdown.one_time_total > 0) {
					html += '<p>One-time items: ' + formatCurrency(breakdown.one_time_total, breakdown.currency) + '</p>';
				}
				if (breakdown.recurring_total > 0) {
					html += '<p>Renewal after first period: ' + formatCurrency(breakdown.recurring_total, breakdown.currency) + ' per ' + intervalLabel + '</p>';
				}
				embeddedCheckoutSummary.innerHTML = html;
			}

			function startEmbeddedCartCheckout(items) {
				if (!embeddedCheckoutWrap || !embeddedCheckoutMount) {
					return false;
				}
				if (window.location.protocol === 'http:' && !/^(localhost|127\.0\.0\.1|\[::1\])$/i.test(window.location.hostname)) {
					window.location.href = window.location.href.replace(/^http:/i, 'https:');
					return true;
				}
				if (typeof window.Stripe === 'undefined') {
					setCartMessage('Stripe checkout could not be loaded. Please refresh and try again.');
					return true;
				}
				destroyEmbeddedCheckout();
				renderEmbeddedCheckoutSummary(items);
				if (cartModal) {
					cartModal.classList.add('is-open');
					cartModal.setAttribute('aria-hidden', 'false');
					document.documentElement.classList.add('ajcore-cart-modal-open');
				}
				embeddedCheckoutWrap.hidden = false;
				embeddedCheckoutMount.innerHTML = '<div class="ajcore-cart-embedded-loading">Loading secure checkout...</div>';
				setEmbeddedCheckoutMessage('');
				const formData = new FormData();
				formData.append('action', 'ajcore_create_checkout_session');
				formData.append('items', JSON.stringify(items.map(function(item) { return { price_id: item.price_id, quantity: 1 }; })));
				formData.append('nonce', root.dataset.cartNonce);
				formData.append('include_archived', root.dataset.includeArchived || 'no');
				formData.append('current_url', window.location.href);
				formData.append('embedded_checkout', '1');
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
					.then(function(response) { return response.json(); })
					.then(function(payload) {
						if (!payload || !payload.success || !payload.data || !payload.data.client_secret || !payload.data.publishable_key) {
							throw new Error((payload && payload.data) || 'Unable to start embedded checkout.');
						}
						const embeddedClientSecret = String(payload.data.client_secret || '').trim();
						if (!/^cs_(test|live)_.*_secret_/.test(decodeURIComponent(embeddedClientSecret))) {
							throw new Error('Stripe did not return a valid embedded Checkout client secret. Please verify the Stripe API version and keys, then try again.');
						}
						ajcoreStripeInstance = window.Stripe(String(payload.data.publishable_key || '').trim());
						if (typeof ajcoreStripeInstance.initEmbeddedCheckout !== 'function') {
							throw new Error('Stripe Embedded Checkout is not available. Please refresh and try again.');
						}
						return ajcoreStripeInstance.initEmbeddedCheckout({
							fetchClientSecret: function() {
								return Promise.resolve(decodeURIComponent(embeddedClientSecret));
							}
						});
					})
					.then(function(checkout) {
						ajcoreEmbeddedCheckout = checkout;
						embeddedCheckoutMount.innerHTML = '';
						if (!embeddedCheckoutMount.id) {
							embeddedCheckoutMount.id = 'ajcore-cart-embedded-checkout-' + Math.random().toString(36).slice(2);
						}
						checkout.mount('#' + embeddedCheckoutMount.id);
						embeddedCheckoutWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
					})
					.catch(function(error) {
						embeddedCheckoutMount.innerHTML = '';
						setEmbeddedCheckoutMessage(error.message || 'Unable to start embedded checkout.');
						checkoutButton.disabled = false;
						checkoutButton.textContent = 'Checkout';
					});
				return true;
			}

			function openCartModal() {
				if (!cartModal) {
					const firstProduct = root.querySelector('.ajcore-product');
					if (firstProduct) {
						firstProduct.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
					return;
				}
				renderCart();
				cartModal.classList.add('is-open');
				cartModal.setAttribute('aria-hidden', 'false');
				document.documentElement.classList.add('ajcore-cart-modal-open');
			}

			function closeCartModal() {
				if (!cartModal) {
					return;
				}
				destroyEmbeddedCheckout();
				if (checkoutButton) {
					checkoutButton.disabled = Object.keys(cart).length === 0;
					checkoutButton.textContent = 'Secure Checkout';
				}
				cartModal.classList.remove('is-open');
				cartModal.setAttribute('aria-hidden', 'true');
				document.documentElement.classList.remove('ajcore-cart-modal-open');
			}

			if (floatingCartButton) {
				floatingCartButton.addEventListener('click', openCartModal);
			}
			if (miniCartButton) {
				miniCartButton.addEventListener('click', openCartModal);
			}
			if (miniCheckoutButton) {
				miniCheckoutButton.addEventListener('click', function() {
					if (checkoutButton && !checkoutButton.disabled) {
						openCartModal();
						if (cartModalCheckoutButton && !cartModalCheckoutButton.disabled) {
							cartModalCheckoutButton.click();
						} else {
							checkoutButton.click();
						}
					}
				});
			}
			if (miniClearButton) {
				miniClearButton.addEventListener('click', function(event) {
					event.preventDefault();
					event.stopPropagation();
					preserveCartScroll(clearCart);
				});
			}
			if (cartModal) {
				cartModal.addEventListener('click', function(event) {
					if (event.target && event.target.closest('[data-ajcore-cart-close]')) {
						closeCartModal();
					}
				});
			}
			if (cartModalClearButton) {
				cartModalClearButton.addEventListener('click', function(event) {
					event.preventDefault();
					event.stopPropagation();
					clearCart();
				});
			}
			if (cartModalCheckoutButton) {
				cartModalCheckoutButton.addEventListener('click', function() {
					if (checkoutButton && !checkoutButton.disabled) {
						checkoutButton.dataset.reviewedCart = getCartSignature();
						checkoutButton.click();
					}
				});
			}
			document.addEventListener('keydown', function(event) {
				if (event.key === 'Escape') {
					closeCartModal();
				}
			});

			root.addEventListener('click', function(event) {
				const addButton = event.target.closest('.ajcore-product-add');
				if (addButton) {
					event.preventDefault();
					event.stopPropagation();
					const product = addButton.closest('.ajcore-product');
					if (!product) {
						return;
					}
					const priceId = product.dataset.priceId;
					const productsToAdd = [product];
					const requiredPriceId = product.dataset.requiredPriceId || '';
					const requiredProductForConflict = requiredPriceId ? getProductByPriceId(requiredPriceId) : null;
					if (requiredProductForConflict && !cart[requiredPriceId]) {
						productsToAdd.push(requiredProductForConflict);
					}
					const recurringConflict = getProductsRecurringIntervalConflict(productsToAdd);
					if (recurringConflict.length > 1) {
						setCartMessage('This cart already includes a ' + getIntervalLabel(recurringConflict[0]) + ' subscription. Please checkout ' + getIntervalLabel(recurringConflict[1]) + ' subscriptions separately.');
						openCartModal();
						return;
					}
					preserveCartScroll(function() {
						const dependencyResult = addRequiredProductIfNeeded(product);
						upsertCartProduct(product, '');
						setCartMessage(dependencyResult && dependencyResult.note ? dependencyResult.note : '');
						renderCart();
					});
					addButton.classList.add('ajcore-added');
					addButton.textContent = 'Added';
					window.setTimeout(function() {
						addButton.classList.remove('ajcore-added');
						addButton.textContent = 'Add to Cart';
					}, 800);
					return;
				}

				const clearButton = event.target.closest('.ajcore-cart-clear, .ajcore-cart-mini-clear');
				if (clearButton) {
					event.preventDefault();
					event.stopPropagation();
					preserveCartScroll(clearCart);
					return;
				}

				const button = event.target.closest('.ajcore-product-buy');
				if (!button || button.disabled) {
					return;
				}
				button.disabled = true;
				const originalText = button.textContent;
				button.textContent = 'Loading...';
				const formData = new FormData();
				formData.append('action', 'ajcore_create_checkout_session');
				formData.append('price_id', button.dataset.priceId);
				formData.append('nonce', button.dataset.nonce);
				formData.append('include_archived', root.dataset.includeArchived || 'no');
				formData.append('current_url', window.location.href);
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
					.then(function(response) { return response.json(); })
					.then(function(payload) {
						if (!payload || !payload.success || !payload.data || !payload.data.url) {
							throw new Error((payload && payload.data) || 'Unable to start checkout.');
						}
						window.location.href = payload.data.url;
					})
					.catch(function(error) {
						button.disabled = false;
						button.textContent = originalText;
						window.alert(error.message || 'Unable to start checkout.');
					});
			});

			if (checkoutButton) {
				checkoutButton.addEventListener('click', function() {
					const cartItemsForCheckout = Object.values(cart);
					if (!cartItemsForCheckout.length) {
						return;
					}
					const items = cartItemsForCheckout.map(function(item) {
						return { price_id: item.price_id, quantity: 1 };
					});
					checkoutButton.disabled = true;
					const originalText = checkoutButton.textContent;
					checkoutButton.textContent = 'Loading...';
					setCartMessage('');
					checkoutButton.textContent = 'Loading secure checkout...';
					if (startEmbeddedCartCheckout(cartItemsForCheckout)) {
						return;
					}
					const formData = new FormData();
					formData.append('action', 'ajcore_create_checkout_session');
					formData.append('items', JSON.stringify(items));
					formData.append('nonce', root.dataset.cartNonce);
					formData.append('include_archived', root.dataset.includeArchived || 'no');
					formData.append('current_url', window.location.href);
					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(function(response) { return response.json(); })
						.then(function(payload) {
							if (!payload || !payload.success || !payload.data || !payload.data.url) {
								throw new Error((payload && payload.data) || 'Unable to start checkout.');
							}
							window.location.href = payload.data.url;
						})
						.catch(function(error) {
							checkoutButton.disabled = false;
							checkoutButton.textContent = originalText;
							setCartMessage(error.message || 'Unable to start checkout.');
						});
				});
			}
			if (getCheckoutReturnStatus() === 'success') {
				clearAllStoredAjcoreProductCarts();
				if (checkoutNotice && checkoutNotice.textContent.trim()) {
					checkoutNotice.hidden = false;
					checkoutNotice.classList.add('is-success');
				} else {
					showCheckoutNotice('Checkout complete. Your order was received.', 'success');
				}
			} else if (getCheckoutReturnStatus() === 'cancelled') {
				showCheckoutNotice('Checkout was cancelled. Your cart is still available.', 'error');
				loadCart();
			} else {
				loadCart();
			}
			renderCart();
		})();
		</script>
		<style>
			.ajcore-products-wrap-cart{display:block}
			.ajcore-checkout-notice{grid-column:1 / -1;margin:0 0 16px;padding:14px 16px;border-radius:14px;font-size:15px;font-weight:850;line-height:1.4}
			.ajcore-checkout-notice.is-success{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534}
			.ajcore-checkout-notice.is-error{border:1px solid #fed7aa;background:#fff7ed;color:#9a3412}
			.ajcore-products-wrap-cart .ajcore-products-list{grid-column:auto}
			.ajcore-products-wrap-cart .ajcore-cart{display:none!important}
			.ajcore-products-wrap-cart.ajcore-cart-highlight .ajcore-cart-mini{box-shadow:0 0 0 4px rgba(15,122,198,.16),0 18px 44px rgba(15,23,42,.12)!important;transform:translateY(-2px)}
			.ajcore-products-wrap .ajcore-product{display:flex;flex-direction:column;min-height:360px;box-sizing:border-box}
			.ajcore-products-wrap .ajcore-product-title{font-size:clamp(20px,2vw,24px)!important;line-height:1.12!important;letter-spacing:-.02em;min-height:2.28em;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
			.ajcore-products-wrap .ajcore-product-summary,.ajcore-products-wrap .ajcore-product-description{font-size:16px;line-height:1.45;color:#64748b;display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden;min-height:7.25em}
			.ajcore-products-wrap .ajcore-product-description :is(h3,h4){margin:8px 0 6px;color:#111827;line-height:1.2;font-size:16px}
			.ajcore-products-wrap .ajcore-product-description p{margin:0 0 10px}
			.ajcore-products-wrap .ajcore-product-description ul{margin:8px 0 12px 20px;padding:0}
			.ajcore-products-wrap .ajcore-product-description li{margin:4px 0}
			.ajcore-products-wrap .ajcore-product-price{margin-top:auto!important;min-height:2.1em;display:flex;align-items:flex-end;font-size:clamp(26px,2.4vw,32px)!important;line-height:1.12!important;letter-spacing:-.03em}
			.ajcore-products-wrap .ajcore-product-cart-controls{margin-top:0}
			.ajcore-product-details :is(h3,h4){margin:10px 0 6px;color:#111827;line-height:1.2}
			.ajcore-product-details p{margin:0 0 10px}
			.ajcore-product-details ul{margin:8px 0 12px 20px;padding:0}
			.ajcore-cart-mini{grid-column:1 / -1;position:sticky;top:0;z-index:9998;display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 16px;padding:12px 14px;border:1px solid #dbeafe;border-radius:16px;background:rgba(255,255,255,.96);box-shadow:0 12px 32px rgba(15,23,42,.10);backdrop-filter:blur(10px)}
			.ajcore-cart-mini-button{display:inline-flex;align-items:center;gap:9px;border:0;border-radius:999px;background:#0f7ac6;color:#fff;padding:10px 14px;font-weight:900;cursor:pointer}
			.ajcore-cart-mini-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:#fff;color:#0f7ac6;font-size:13px;font-weight:900}
			.ajcore-cart-mini-status{flex:1;color:#475569;font-size:14px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
			.ajcore-cart-mini-clear{border:0;border-radius:999px;background:#f1f5f9;color:#475569;padding:10px 12px;font-weight:900;cursor:pointer}
			.ajcore-cart-mini-clear:disabled{opacity:.45;cursor:not-allowed}
			.ajcore-cart-mini-checkout{border:0;border-radius:999px;background:#0f7ac6;color:#fff;padding:10px 14px;font-weight:900;cursor:pointer}
			.ajcore-cart-mini-checkout:disabled{background:#cbd5e1;cursor:not-allowed}
			.ajcore-cart-mini-notice{flex:0 0 100%;display:none;margin-top:6px;padding:8px 10px;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:12px;font-weight:800;line-height:1.35;border:1px solid #fed7aa}
			.ajcore-cart-modal{display:none;position:fixed;inset:0;z-index:2147483100;box-sizing:border-box}
			.ajcore-cart-modal.is-open{display:block}
			.ajcore-cart-modal-backdrop{position:absolute;inset:0;z-index:1;background:rgba(15,23,42,.46);backdrop-filter:blur(4px)}
			.ajcore-cart-modal-panel{position:absolute;z-index:2;left:50%;top:50%;transform:translate(-50%,-50%);width:min(1180px,calc(100vw - 32px));max-height:calc(100vh - 56px);overflow:auto;background:#fff;border:1px solid #dbeafe;border-radius:22px;box-shadow:0 28px 80px rgba(15,23,42,.28);padding:22px;box-sizing:border-box}
			.ajcore-cart-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
			.ajcore-cart-modal-header h3{margin:0 0 4px;font-size:26px;line-height:1.08;color:#0f172a}
			.ajcore-cart-modal-header p{margin:0;color:#64748b;font-size:14px;line-height:1.5}
			.ajcore-cart-modal-close{border:0;background:#f1f5f9;color:#0f172a;border-radius:999px;width:38px;height:38px;font-size:26px;line-height:1;cursor:pointer}
			.ajcore-cart-modal-empty{padding:22px;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#64748b;font-weight:700;text-align:center}
			.ajcore-cart-modal-items{display:grid;gap:12px}
			.ajcore-cart-modal-row{display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:center;padding:14px;border:1px solid #e2e8f0;border-radius:16px;background:#fff}
			.ajcore-cart-modal-row-main strong{display:block;color:#0f172a;font-size:15px;line-height:1.25;margin-bottom:4px}
			.ajcore-cart-modal-row-main span{display:block;color:#475569;font-weight:800}
			.ajcore-cart-modal-row-main small{margin-top:5px;color:#9a3412;font-weight:800;line-height:1.35}
			.ajcore-cart-modal-row-qty{display:inline-flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;border-radius:999px;background:#f8fafc;color:#475569;min-height:34px;padding:0 10px;font-size:13px;font-weight:900;white-space:nowrap}
			.ajcore-cart-modal-row button{background:#fff;color:#b32d2e;border:1px solid #fecaca;border-radius:10px;padding:9px 11px;font-weight:800;cursor:pointer}
			.ajcore-cart-modal-note{display:none;margin-top:12px;padding:10px 12px;border-radius:12px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;font-size:13px;font-weight:800;line-height:1.4}
			.ajcore-cart-modal-note .ajcore-mixed-checkout-review strong{display:block;color:#111827;font-size:15px;margin-bottom:7px}
			.ajcore-cart-modal-note .ajcore-mixed-checkout-review p{margin:4px 0;color:#7c2d12}
			.ajcore-cart-modal-footer{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:18px;padding-top:16px;border-top:1px solid #e5e7eb}
			.ajcore-cart-embedded-checkout{display:grid;grid-template-columns:minmax(260px,.9fr) minmax(320px,1.1fr);gap:18px;margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb}
			.ajcore-cart-embedded-checkout[hidden]{display:none!important}
			.ajcore-cart-embedded-summary{border:1px solid #fed7aa;background:#fff7ed;color:#7c2d12;border-radius:18px;padding:18px 20px;font-weight:900;line-height:1.45}
			.ajcore-cart-embedded-summary h4{margin:0 0 12px;color:#0f172a;font-size:20px;line-height:1.2}
			.ajcore-cart-embedded-summary strong{display:block;margin:0 0 12px;color:#0f172a;font-size:21px;line-height:1.25}
			.ajcore-cart-embedded-summary p{margin:9px 0;color:#7c2d12;font-size:16px;line-height:1.45}
			.ajcore-cart-embedded-stripe{min-height:480px;border:1px solid #e2e8f0;background:#fff;border-radius:18px;padding:14px;overflow:hidden}
			.ajcore-cart-embedded-mount{min-height:460px}
			.ajcore-cart-embedded-message{margin:0 0 12px;padding:10px 12px;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:12px;font-weight:900}
			.ajcore-cart-embedded-loading{padding:18px;color:#475569;font-weight:900;text-align:center}
			.ajcore-cart-modal-total{font-size:22px;font-weight:900;color:#0f172a}
			.ajcore-cart-modal-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
			.ajcore-cart-modal-clear{border:1px solid #cbd5e1;background:#fff;color:#475569;border-radius:999px;padding:11px 14px;font-weight:900;cursor:pointer}
			.ajcore-cart-modal-clear:disabled{opacity:.45;cursor:not-allowed}
			.ajcore-cart-modal-checkout{border:0;background:#0f7ac6;color:#fff;border-radius:999px;padding:12px 18px;font-weight:900;cursor:pointer}
			.ajcore-cart-modal-checkout:disabled{background:#cbd5e1;cursor:not-allowed}
			.ajcore-cart-modal-open{overflow:hidden}
			.ajcore-product-add.ajcore-added{background:#16a34a!important}
			.ajcore-floating-cart{position:fixed;right:22px;bottom:22px;z-index:99999;display:none!important;align-items:center;gap:10px;border:0;border-radius:999px;background:#0f7ac6;color:#fff;padding:12px 15px;box-shadow:0 18px 40px rgba(15,122,198,.28);font-weight:800;cursor:pointer;line-height:1}
			.ajcore-floating-cart:hover,.ajcore-floating-cart:focus{background:#0869ab;color:#fff;outline:0;box-shadow:0 0 0 4px rgba(15,122,198,.18),0 18px 40px rgba(15,122,198,.28)}
			.ajcore-floating-cart-icon{font-size:20px;line-height:1}
			.ajcore-floating-cart-count{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:#fff;color:#0f7ac6;font-size:13px;font-weight:900}
			.ajcore-floating-cart.has-items{animation:ajcoreCartPulse .28s ease}
			@keyframes ajcoreCartPulse{0%{transform:scale(1)}50%{transform:scale(1.06)}100%{transform:scale(1)}}
			@media (max-width: 800px){.ajcore-cart-modal-panel{top:calc(env(safe-area-inset-top,0px) + 110px);transform:translateX(-50%);max-height:calc(100vh - 140px);width:calc(100vw - 24px);padding:18px}.ajcore-cart-modal-row{grid-template-columns:1fr;align-items:start}.ajcore-cart-modal-footer{align-items:flex-start;flex-direction:column}.ajcore-cart-embedded-checkout{grid-template-columns:1fr}.ajcore-products-list{grid-template-columns:1fr!important}.ajcore-products-wrap-cart{padding-top:118px;padding-bottom:42px}.ajcore-products-wrap .ajcore-product{min-height:auto}.ajcore-products-wrap .ajcore-product-title{min-height:auto;-webkit-line-clamp:3}.ajcore-products-wrap .ajcore-product-summary,.ajcore-products-wrap .ajcore-product-description{min-height:auto;-webkit-line-clamp:4}.ajcore-cart-mini{left:12px;right:12px;margin:0;padding:10px 12px;border-radius:16px;gap:8px}.ajcore-cart-mini.ajcore-mobile-fixed-cart{position:fixed!important;top:var(--ajcore-cart-mobile-top,calc(env(safe-area-inset-top,0px) + 190px))!important;bottom:auto!important;left:12px!important;right:12px!important;width:auto!important;max-width:none!important;z-index:2147483000!important;box-sizing:border-box!important;transform:translateZ(0);}.ajcore-cart-mini-label{display:none}.ajcore-cart-mini-status{font-size:13px}.ajcore-cart-mini-clear{padding:9px 10px}.ajcore-cart-mini-checkout{padding:10px 12px}.ajcore-floating-cart{right:16px;bottom:16px;padding:13px 15px}.ajcore-floating-cart-text{display:none}}
			@media (max-width: 980px){.ajcore-products-wrap-cart{grid-template-columns:1fr}.ajcore-products-wrap-cart .ajcore-cart{grid-column:auto;grid-row:auto;position:static}}
			/* While the site's mobile menu is expanded, keep the fixed carts behind it. */
			body.menu-open .ajcore-cart-mini.ajcore-mobile-fixed-cart,
			html.has-modal-open .ajcore-cart-mini.ajcore-mobile-fixed-cart{z-index:1!important}
			body.menu-open .ajcore-floating-cart,
			html.has-modal-open .ajcore-floating-cart{z-index:1!important}
		</style>
		<?php
		return ob_get_clean();
	}

	/** Leads live on the shared portal DB in multi-site mode (so every site's form
	 *  submissions land in one inbox); use get_leads_db() for queries against it. */
	private function get_leads_db() {
		if ( function_exists( 'ajcore_get_portal_db' ) ) {
			return ajcore_get_portal_db();
		}
		global $wpdb;
		return $wpdb;
	}

	private function get_leads_table() {
		return $this->get_leads_db()->prefix . 'aj_forms_leads';
	}

	private function get_form_by_id( $form_id ) {
		global $wpdb;

		$table = $this->get_forms_table();

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $table_exists !== $table ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$form_id
			)
		);
	}

	private function normalize_schema( $schema ) {
		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			$raw_settings = isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : array();
			$settings     = wp_parse_args( $raw_settings, $this->get_default_form_settings() );
			if ( ! isset( $raw_settings['confirmation_mode'] ) && ! empty( $raw_settings['confirmation_rules'] ) ) {
				$settings['confirmation_mode'] = 'conditional';
			}

			return array(
				'fields'   => $schema['fields'],
				'settings' => $settings,
			);
		}

		if ( is_array( $schema ) ) {
			return array(
				'fields'   => $schema,
				'settings' => $this->get_default_form_settings(),
			);
		}

		return array(
			'fields'   => array(),
			'settings' => $this->get_default_form_settings(),
		);
	}

	public function maybe_render_form_preview() {
		$form_id = isset( $_GET['ajforms_preview'] ) ? absint( wp_unslash( $_GET['ajforms_preview'] ) ) : 0;
		if ( ! $form_id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview this form.', 'ajforms' ), 403 );
		}

		$form = $this->get_form_by_id( $form_id );
		if ( ! $form || 'deleted' === $form->status ) {
			wp_die( esc_html__( 'Form not found.', 'ajforms' ), 404 );
		}

		nocache_headers();
		$form_markup = $this->render_form_shortcode( array( 'id' => $form_id ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $form->title . ' Preview' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'ajforms-preview-page' ); ?>>
			<div style="max-width:760px;margin:40px auto;padding:32px;background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.05);">
				<div style="margin-bottom:20px;color:#646970;font-size:14px;">AJ Core Preview</div>
				<?php echo $form_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	private function format_lead_value_for_display( $value ) {
		if ( is_array( $value ) ) {
			$cleaned = array_map( 'sanitize_text_field', $value );
			return implode( ', ', array_filter( $cleaned, 'strlen' ) );
		}

		return sanitize_text_field( (string) $value );
	}

	private function get_field_option_label_map( $field ) {
		$options = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$map     = array();

		foreach ( $options as $option ) {
			$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
			$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;

			$map[ sanitize_text_field( (string) $option_value ) ] = sanitize_text_field( (string) $option_label );
		}

		return $map;
	}

	private function map_option_value_to_label( $value, $field ) {
		$option_map = $this->get_field_option_label_map( $field );
		$value      = sanitize_text_field( (string) $value );

		return isset( $option_map[ $value ] ) ? $option_map[ $value ] : $value;
	}

	private function field_type_uses_options( $field_type ) {
		return in_array( $field_type, array( 'checkboxes', 'multiple_choice', 'question', 'select' ), true );
	}

	private function is_display_only_form_field( $field_type ) {
		return in_array( $field_type, array( 'separator', 'note', 'heading', 'container' ), true );
	}

	private function flatten_form_fields( $fields, $include_containers = false ) {
		$flat_fields = array();

		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_type = ! empty( $field['type'] ) ? (string) $field['type'] : 'text';

			if ( 'container' === $field_type ) {
				if ( $include_containers ) {
					$flat_fields[] = $field;
				}

				if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
					$flat_fields = array_merge( $flat_fields, $this->flatten_form_fields( $field['fields'], $include_containers ) );
				}

				continue;
			}

			$flat_fields[] = $field;
		}

		return $flat_fields;
	}

	private function get_client_ip_address() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = array_map( 'trim', explode( ',', $value ) );

			foreach ( $parts as $part ) {
				if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
					return $part;
				}
			}
		}

		return '';
	}

	private function parse_user_agent( $user_agent ) {
		$user_agent = (string) $user_agent;
		$browser    = 'Unknown';
		$os         = 'Unknown';
		$device     = 'Desktop';

		if ( preg_match( '/Edg\//i', $user_agent ) ) {
			$browser = 'Edge';
		} elseif ( preg_match( '/Chrome\//i', $user_agent ) && ! preg_match( '/Chromium|OPR\//i', $user_agent ) ) {
			$browser = 'Chrome';
		} elseif ( preg_match( '/Safari\//i', $user_agent ) && ! preg_match( '/Chrome\//i', $user_agent ) ) {
			$browser = 'Safari';
		} elseif ( preg_match( '/Firefox\//i', $user_agent ) ) {
			$browser = 'Firefox';
		}

		if ( preg_match( '/Windows NT/i', $user_agent ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/Mac OS X/i', $user_agent ) && ! preg_match( '/iPhone|iPad/i', $user_agent ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/iPhone|iPad/i', $user_agent ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/Android/i', $user_agent ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/Linux/i', $user_agent ) ) {
			$os = 'Linux';
		}

		if ( preg_match( '/iPad|Tablet/i', $user_agent ) ) {
			$device = 'Tablet';
		} elseif ( preg_match( '/Mobile|iPhone|Android/i', $user_agent ) ) {
			$device = 'Mobile';
		}

		return array(
			'device_type' => $device,
			'browser'     => $browser,
			'os'          => $os,
		);
	}

	private function get_submission_meta() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$parsed     = $this->parse_user_agent( $user_agent );

		return array(
			'label'       => 'Submission Details',
			'type'        => 'meta',
			'ip_address'  => $this->get_client_ip_address(),
			'device_type' => $parsed['device_type'],
			'browser'     => $parsed['browser'],
			'os'          => $parsed['os'],
			'user_agent'  => $user_agent,
			'page_url'    => isset( $_POST['ajf_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['ajf_page_url'] ) ) : ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '' ),
			'referrer'    => isset( $_POST['ajf_referrer'] ) ? esc_url_raw( wp_unslash( $_POST['ajf_referrer'] ) ) : ( isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' ),
			'utm_source'  => isset( $_POST['ajf_utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_utm_source'] ) ) : '',
			'utm_medium'  => isset( $_POST['ajf_utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_utm_medium'] ) ) : '',
			'utm_campaign'=> isset( $_POST['ajf_utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_utm_campaign'] ) ) : '',
			'screen_size' => isset( $_POST['ajf_screen_size'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_screen_size'] ) ) : '',
			'timezone'    => isset( $_POST['ajf_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_timezone'] ) ) : '',
			'language'    => isset( $_POST['ajf_language'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_language'] ) ) : '',
			'submitted_at'=> current_time( 'mysql' ),
		);
	}

	private function render_tracking_inputs() {
		ob_start();
		?>
		<input type="hidden" name="ajf_page_url" value="">
		<input type="hidden" name="ajf_referrer" value="">
		<input type="hidden" name="ajf_utm_source" value="">
		<input type="hidden" name="ajf_utm_medium" value="">
		<input type="hidden" name="ajf_utm_campaign" value="">
		<input type="hidden" name="ajf_screen_size" value="">
		<input type="hidden" name="ajf_timezone" value="">
		<input type="hidden" name="ajf_language" value="">
		<script>
		(function(){
			function fillTracking(form){
				if (!form || form.dataset.ajformsTrackingReady) {
					return;
				}
				form.dataset.ajformsTrackingReady = '1';
				var params = new URLSearchParams(window.location.search || '');
				var values = {
					ajf_page_url: window.location.href || '',
					ajf_referrer: document.referrer || '',
					ajf_utm_source: params.get('utm_source') || '',
					ajf_utm_medium: params.get('utm_medium') || '',
					ajf_utm_campaign: params.get('utm_campaign') || '',
					ajf_screen_size: window.screen ? window.screen.width + 'x' + window.screen.height : '',
					ajf_timezone: Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone || '' : '',
					ajf_language: navigator.language || ''
				};
				Object.keys(values).forEach(function(name){
					var input = form.querySelector('input[name="' + name + '"]');
					if (input) {
						input.value = values[name];
					}
				});
			}
			document.querySelectorAll('form.ajforms-frontend-form').forEach(fillTracking);
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	private function get_notification_recipients( $settings ) {
		$recipient_string = isset( $settings['notification_email'] ) ? (string) $settings['notification_email'] : '';
		$pieces           = preg_split( '/[\s,]+/', $recipient_string );
		$emails           = array();

		if ( is_array( $pieces ) ) {
			foreach ( $pieces as $piece ) {
				$email = sanitize_email( $piece );
				if ( '' !== $email && is_email( $email ) ) {
					$emails[] = $email;
				}
			}
		}

		if ( empty( $emails ) ) {
			$default_email = sanitize_email( get_option( 'admin_email' ) );
			if ( '' !== $default_email && is_email( $default_email ) ) {
				$emails[] = $default_email;
			}
		}

		return array_values( array_unique( $emails ) );
	}
	private function replace_template_tags( $template, $form, $lead_data ) {
		$replacements = array(
			'{form_title}'        => $form->title,
			'{submission_count}'  => '1',
			'{submitted_at}'      => isset( $lead_data['_meta']['submitted_at'] ) ? $lead_data['_meta']['submitted_at'] : current_time( 'mysql' ),
		);

		// Add {submission_fields} tag
		$replacements['{submission_fields}'] = $this->build_submission_fields_text( $lead_data );
		$replacements['{submission_details}'] = $this->build_submission_meta_text( $lead_data );
		$replacements['{submission_table}'] = $this->build_submission_table_html( $lead_data );
		$replacements['{submission_details_table}'] = $this->build_submission_meta_table_html( $lead_data );

		$field_index = 1;
		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( 0 === strpos( (string) $field_id, '_' ) ) {
				continue;
			}

			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';
			
			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			$replacements[ '{field_' . $field_index . '}' ] = $value;
			$replacements[ '{' . $field_id . '}' ] = $value;

			if ( ! empty( $field['field_name'] ) ) {
				$replacements[ '{' . sanitize_key( $field['field_name'] ) . '}' ] = $value;
			}

			$field_index++;
		}

		return strtr( $template, $replacements );
	}


	private function get_reply_to_header( $lead_data ) {
		foreach ( $lead_data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( 0 === strpos( (string) $field_id, '_' ) ) {
				continue;
			}

			if ( empty( $field['type'] ) || 'email' !== $field['type'] || empty( $field['value'] ) ) {
				continue;
			}

			$email = sanitize_email( (string) $field['value'] );
			if ( '' !== $email && is_email( $email ) ) {
				return 'Reply-To: ' . $email;
			}
		}

		return '';
	}

	private function get_notification_reply_to_header( $settings, $form, $lead_data ) {
		if ( ! empty( $settings['notification_reply_to'] ) ) {
			$reply_to = $this->replace_template_tags( (string) $settings['notification_reply_to'], $form, $lead_data );
			$reply_to = sanitize_email( $reply_to );

			if ( '' !== $reply_to && is_email( $reply_to ) ) {
				return 'Reply-To: ' . $reply_to;
			}
		}

		return $this->get_reply_to_header( $lead_data );
	}

	private function build_submission_fields_text( $lead_data ) {
		$lines = array();

		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = ! empty( $field['label'] ) ? sanitize_text_field( $field['label'] ) : sanitize_text_field( (string) $field_id );
			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';

			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			$lines[] = $label . ': ' . ( '' !== $value ? $value : '-' );
		}

		return implode( "\n", $lines );
	}

	private function build_submission_meta_text( $lead_data ) {
		$meta = isset( $lead_data['_meta'] ) && is_array( $lead_data['_meta'] ) ? $lead_data['_meta'] : array();
		if ( empty( $meta ) ) {
			return '';
		}

		$labels = array(
			'ip_address'   => 'IP Address',
			'device_type'  => 'Device',
			'browser'      => 'Browser',
			'os'           => 'Operating System',
			'page_url'     => 'Page URL',
			'referrer'     => 'Referrer',
			'utm_source'   => 'UTM Source',
			'utm_medium'   => 'UTM Medium',
			'utm_campaign' => 'UTM Campaign',
			'screen_size'  => 'Screen Size',
			'timezone'     => 'Timezone',
			'language'     => 'Language',
			'submitted_at' => 'Submitted',
			'user_agent'   => 'User Agent',
		);

		$lines = array();
		foreach ( $labels as $key => $label ) {
			if ( empty( $meta[ $key ] ) ) {
				continue;
			}

			$lines[] = $label . ': ' . sanitize_text_field( (string) $meta[ $key ] );
		}

		return implode( "\n", $lines );
	}

	private function build_submission_table_html( $lead_data ) {
		$rows = '';

		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) || 0 === strpos( (string) $field_id, '_' ) ) {
				continue;
			}

			$label = ! empty( $field['label'] ) ? sanitize_text_field( $field['label'] ) : sanitize_text_field( (string) $field_id );
			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';

			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:10px;border:1px solid #d9e2ec;background:#f8fafc;width:38%;">' . esc_html( $label ) . '</th><td style="padding:10px;border:1px solid #d9e2ec;">' . esc_html( '' !== $value ? $value : '-' ) . '</td></tr>';
		}

		return '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:14px 0;">' . $rows . '</table>';
	}

	private function build_submission_meta_table_html( $lead_data ) {
		$meta = isset( $lead_data['_meta'] ) && is_array( $lead_data['_meta'] ) ? $lead_data['_meta'] : array();
		if ( empty( $meta ) ) {
			return '';
		}

		$labels = array(
			'ip_address'   => 'IP Address',
			'device_type'  => 'Device',
			'browser'      => 'Browser',
			'os'           => 'Operating System',
			'page_url'     => 'Page URL',
			'referrer'     => 'Referrer',
			'utm_source'   => 'UTM Source',
			'utm_medium'   => 'UTM Medium',
			'utm_campaign' => 'UTM Campaign',
			'screen_size'  => 'Screen Size',
			'timezone'     => 'Timezone',
			'language'     => 'Language',
			'submitted_at' => 'Submitted',
			'user_agent'   => 'User Agent',
		);

		$rows = '';
		foreach ( $labels as $key => $label ) {
			if ( empty( $meta[ $key ] ) ) {
				continue;
			}

			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:8px;border:1px solid #d9e2ec;background:#f8fafc;width:38%;">' . esc_html( $label ) . '</th><td style="padding:8px;border:1px solid #d9e2ec;">' . esc_html( sanitize_text_field( (string) $meta[ $key ] ) ) . '</td></tr>';
		}

		return '' === $rows ? '' : '<h3 style="margin:22px 0 8px;">Submission Details</h3><table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:8px 0;">' . $rows . '</table>';
	}

	private function maybe_create_asana_task( $form, $lead_data, $settings ) {
		if ( empty( $settings['asana_task_enabled'] ) ) {
			return;
		}

		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		if ( empty( $plugin_settings['asana_enabled'] ) || empty( $plugin_settings['asana_personal_access_token'] ) ) {
			return;
		}

		$workspace_gid = ! empty( $plugin_settings['asana_workspace_gid'] ) ? sanitize_text_field( $plugin_settings['asana_workspace_gid'] ) : '';
		if ( '' === $workspace_gid ) {
			return;
		}

		$project_gid = ! empty( $settings['asana_project_gid'] ) ? sanitize_text_field( $settings['asana_project_gid'] ) : '';
		if ( '' === $project_gid && ! empty( $plugin_settings['asana_project_gid'] ) ) {
			$project_gid = sanitize_text_field( $plugin_settings['asana_project_gid'] );
		}

		$task_name_template = ! empty( $settings['asana_task_name'] ) ? (string) $settings['asana_task_name'] : 'New form submission: {form_title}';
		$task_notes_template = ! empty( $settings['asana_task_notes'] ) ? (string) $settings['asana_task_notes'] : "Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}";

		$task_name = $this->replace_template_tags( $task_name_template, $form, $lead_data );
		$task_notes = $this->replace_template_tags( $task_notes_template, $form, $lead_data );

		$request_data = array(
			'name'      => sanitize_text_field( $task_name ),
			'notes'     => $task_notes,
			'workspace' => $workspace_gid,
		);

		if ( ! empty( $settings['asana_assignee_gid'] ) ) {
			$request_data['assignee'] = sanitize_text_field( $settings['asana_assignee_gid'] );
		}

		if ( ! empty( $settings['asana_due_date'] ) && 'today' === $settings['asana_due_date'] ) {
			$request_data['due_on'] = current_time( 'Y-m-d' );
		}

		if ( '' !== $project_gid ) {
			$request_data['projects'] = array( $project_gid );
		}

		$response = wp_remote_post(
			'https://app.asana.com/api/1.0/tasks',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . sanitize_text_field( $plugin_settings['asana_personal_access_token'] ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'data' => $request_data,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'AJ Core Asana task creation failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			error_log( 'AJ Core Asana task creation failed with status ' . $response_code . ': ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private function send_form_notification( $form, $lead_data, $settings ) {
		if ( empty( $settings['notifications_enabled'] ) ) {
			return;
		}

		$recipients = $this->get_notification_recipients( $settings );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject_template = ! empty( $settings['notification_subject'] ) ? (string) $settings['notification_subject'] : 'New submission for {form_title}';
		$subject          = $this->replace_template_tags( $subject_template, $form, $lead_data );
		$subject          = sanitize_text_field( $subject );

		$body_template = ! empty( $settings['notification_body'] ) ? (string) $settings['notification_body'] : "{submission_table}{submission_details_table}";
		$body          = $this->replace_template_tags( $body_template, $form, $lead_data );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name = ! empty( $settings['notification_from_name'] ) ? sanitize_text_field( $this->replace_template_tags( (string) $settings['notification_from_name'], $form, $lead_data ) ) : '';
		$from_email = ! empty( $settings['notification_from_email'] ) ? sanitize_email( $settings['notification_from_email'] ) : ( defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? sanitize_email( AJCORE_SYSTEM_FROM_EMAIL ) : 'donotreply@ncllcagents.com' );
		$reply_to = $this->get_notification_reply_to_header( $settings, $form, $lead_data );
		$attachments = array();

		foreach ( $lead_data as $field ) {
			if ( ! is_array( $field ) || empty( $field['file_path'] ) ) {
				continue;
			}

			$attachments[] = $field['file_path'];
		}

		if ( '' !== $reply_to ) {
			$headers[] = $reply_to;
		}

		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = 'From: ' . ( '' !== $from_name ? $from_name . ' ' : '' ) . '<' . $from_email . '>';
		}

		wp_mail( $recipients, $subject, wp_kses_post( $body ), $headers, $attachments );
	}

	private function get_rule_field_value( $field_key, $lead_data ) {
		foreach ( $lead_data as $field_id => $field ) {
			if ( 0 === strpos( (string) $field_id, '_' ) || ! is_array( $field ) ) {
				continue;
			}

			$field_name = isset( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : '';
			if ( $field_key === $field_id || $field_key === $field_name ) {
				return isset( $field['value'] ) ? $field['value'] : '';
			}
		}

		return '';
	}

	private function rule_value_matches_condition( $value, $condition ) {
		$operator = isset( $condition['operator'] ) ? sanitize_key( $condition['operator'] ) : 'equals';
		$expected = isset( $condition['value'] ) ? sanitize_text_field( (string) $condition['value'] ) : '';
		$values   = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array( sanitize_text_field( (string) $value ) );
		$joined   = implode( ', ', array_filter( $values, 'strlen' ) );
		$is_empty = '' === $joined;

		switch ( $operator ) {
			case 'not_equals':
				return ! in_array( $expected, $values, true );
			case 'contains':
				foreach ( $values as $single_value ) {
					if ( false !== stripos( $single_value, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'not_contains':
				foreach ( $values as $single_value ) {
					if ( false !== stripos( $single_value, $expected ) ) {
						return false;
					}
				}
				return true;
			case 'is_empty':
				return $is_empty;
			case 'is_not_empty':
				return ! $is_empty;
			case 'equals':
			default:
				return in_array( $expected, $values, true );
		}
	}

	private function evaluate_rule_conditions( $rule, $lead_data ) {
		if ( ( array_key_exists( 'enabled', $rule ) && empty( $rule['enabled'] ) ) || empty( $rule['conditions'] ) || ! is_array( $rule['conditions'] ) ) {
			return false;
		}

		$logic   = isset( $rule['logic'] ) && 'OR' === strtoupper( (string) $rule['logic'] ) ? 'OR' : 'AND';
		$matches = array();

		foreach ( $rule['conditions'] as $condition ) {
			if ( ! is_array( $condition ) || empty( $condition['field'] ) ) {
				continue;
			}

			$value     = $this->get_rule_field_value( sanitize_key( $condition['field'] ), $lead_data );
			$matches[] = $this->rule_value_matches_condition( $value, $condition );
		}

		if ( empty( $matches ) ) {
			return false;
		}

		return 'OR' === $logic ? in_array( true, $matches, true ) : ! in_array( false, $matches, true );
	}

	private function trigger_rule_webhook( $url, $form, $lead_data ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'form_id'    => absint( $form->id ),
						'form_title' => $form->title,
						'lead_data'  => $lead_data,
						'submitted_at' => current_time( 'mysql' ),
					)
				),
			)
		);
	}

	private function get_default_confirmation_result( $settings ) {
		$redirect_url = '';
		if ( ! empty( $settings['confirmation_type'] ) && 'redirect' === $settings['confirmation_type'] && ! empty( $settings['redirect_url'] ) ) {
			$redirect_url = esc_url_raw( $settings['redirect_url'] );
		}

		return array(
			'message'      => '' !== $redirect_url ? __( 'Redirecting...', 'ajforms' ) : ( ! empty( $settings['success_message'] ) ? $settings['success_message'] : 'Form submitted successfully.' ),
			'redirect_url' => $redirect_url,
		);
	}

	private function evaluate_confirmation_rules( $form, $lead_data, $settings ) {
		if ( isset( $settings['confirmation_mode'] ) && 'conditional' !== $settings['confirmation_mode'] ) {
			return $this->get_default_confirmation_result( $settings );
		}

		$result = array(
			'message'      => ! empty( $settings['success_message'] ) ? $settings['success_message'] : 'Form submitted successfully.',
			'redirect_url' => '',
		);
		$rules  = ! empty( $settings['confirmation_rules'] ) && is_array( $settings['confirmation_rules'] ) ? $settings['confirmation_rules'] : array();

		usort(
			$rules,
			function ( $a, $b ) {
				return intval( isset( $a['priority'] ) ? $a['priority'] : 0 ) <=> intval( isset( $b['priority'] ) ? $b['priority'] : 0 );
			}
		);

		$matched = false;
		foreach ( $rules as $rule ) {
			if ( ! $this->evaluate_rule_conditions( $rule, $lead_data ) ) {
				continue;
			}

			$matched = true;
			foreach ( isset( $rule['actions'] ) && is_array( $rule['actions'] ) ? $rule['actions'] : array() as $action ) {
				if ( ! is_array( $action ) || empty( $action['type'] ) ) {
					continue;
				}

				if ( 'show_message' === $action['type'] ) {
					$result['message'] = ! empty( $action['message'] ) ? $this->replace_template_tags( (string) $action['message'], $form, $lead_data ) : $result['message'];
				} elseif ( 'redirect' === $action['type'] ) {
					$result['redirect_url'] = ! empty( $action['url'] ) ? esc_url_raw( $this->replace_template_tags( (string) $action['url'], $form, $lead_data ) ) : $result['redirect_url'];
					$result['message']      = __( 'Redirecting...', 'ajforms' );
				} elseif ( 'webhook' === $action['type'] ) {
					$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
					$webhook_url     = ! empty( $action['url'] ) ? (string) $action['url'] : ( isset( $plugin_settings['webhook_url'] ) ? (string) $plugin_settings['webhook_url'] : '' );
					$this->trigger_rule_webhook( $this->replace_template_tags( $webhook_url, $form, $lead_data ), $form, $lead_data );
				}
			}

			if ( ! empty( $rule['stop_processing'] ) ) {
				break;
			}
		}

		return $matched ? $result : $this->get_default_confirmation_result( $settings );
	}

	private function handle_form_submission( $form, $fields, $settings ) {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return array(
				'submitted' => false,
				'success'   => false,
				'message'   => '',
			);
		}

		$form_id = absint( $form->id );
		$posted_form_id = isset( $_POST['ajf_form_id'] ) ? absint( wp_unslash( $_POST['ajf_form_id'] ) ) : 0;
		if ( $posted_form_id !== $form_id ) {
			return array(
				'submitted' => false,
				'success'   => false,
				'message'   => '',
			);
		}

		$nonce = isset( $_POST['ajf_form_nonce'] ) ? wp_unslash( $_POST['ajf_form_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ajf_submit_form_' . $form_id ) ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => 'Security check failed.',
			);
		}

		if ( $this->is_honeypot_enabled() ) {
			$honeypot_field_name  = $this->get_honeypot_field_name( $form_id );
			$honeypot_field_value = isset( $_POST[ $honeypot_field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $honeypot_field_name ] ) ) : '';

			if ( '' !== $honeypot_field_value ) {
				return array(
					'submitted' => true,
					'success'   => false,
					'message'   => __( 'Spam check failed. Please try again.', 'ajforms' ),
				);
			}
		}

		if ( $this->should_render_challenge_provider() ) {
			$challenge_check = $this->validate_challenge_provider_token();

			if ( is_wp_error( $challenge_check ) ) {
				return array(
					'submitted' => true,
					'success'   => false,
					'message'   => $challenge_check->get_error_message(),
				);
			}
		}

		$stripe_payment_result = $this->validate_stripe_payment_submission( $form, $settings );
		if ( is_wp_error( $stripe_payment_result ) ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => $stripe_payment_result->get_error_message(),
			);
		}

		$lead_data = array();
		$errors    = array();

		foreach ( $this->flatten_form_fields( $fields ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_id    = ! empty( $field['id'] ) ? $field['id'] : '';
			$field_type  = ! empty( $field['type'] ) ? $field['type'] : 'text';
			$field_label = ! empty( $field['label'] ) ? $field['label'] : $field_id;
			$field_name  = ! empty( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : sanitize_key( $field_label );
			$required    = ! empty( $field['required'] );

			if ( ! $field_id || $this->is_display_only_form_field( $field_type ) ) {
				continue;
			}

			if ( 'file' === $field_type ) {
				$has_upload = isset( $_FILES[ $field_id ] ) && ! empty( $_FILES[ $field_id ]['name'] );

				if ( $required && ! $has_upload ) {
					$errors[] = sprintf( '%s is required.', $field_label );
					continue;
				}

				if ( $has_upload ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';

					$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? explode( ',', (string) $field['accepted_file_types'] ) : array( '.pdf', '.jpg', '.jpeg', '.png', '.gif', '.webp' );
					$accepted_file_types = array_map( 'trim', $accepted_file_types );
					$allowed_mimes       = array(
						'pdf'  => 'application/pdf',
						'jpg|jpeg|jpe' => 'image/jpeg',
						'png'  => 'image/png',
						'gif'  => 'image/gif',
						'webp' => 'image/webp',
					);

					$uploaded = wp_handle_upload(
						$_FILES[ $field_id ],
						array(
							'test_form' => false,
							'mimes'     => $allowed_mimes,
						)
					);

					if ( isset( $uploaded['error'] ) ) {
						$errors[] = sprintf( '%s upload failed.', $field_label );
						continue;
					}

					$file_url = isset( $uploaded['url'] ) ? esc_url_raw( $uploaded['url'] ) : '';
					$file_path = isset( $uploaded['file'] ) ? $uploaded['file'] : '';
					$file_name = isset( $_FILES[ $field_id ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ $field_id ]['name'] ) ) : '';
					$file_ext  = strtolower( strrchr( $file_name, '.' ) );

					if ( ! empty( $accepted_file_types ) && ! in_array( $file_ext, $accepted_file_types, true ) ) {
						$errors[] = sprintf( '%s file type is not allowed.', $field_label );
						continue;
					}

					$lead_data[ $field_id ] = array(
						'label'         => $field_label,
						'field_name'    => $field_name,
						'type'          => $field_type,
						'value'         => $file_url,
						'file_name'     => $file_name,
						'file_path'     => $file_path,
						'accepted_types'=> implode( ',', $accepted_file_types ),
					);
				}

				continue;
			}

			$value = isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : null;

			if ( is_array( $value ) ) {
				$clean_value = array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
				if ( $this->field_type_uses_options( $field_type ) ) {
					$clean_value = array_map(
						function ( $selected_value ) use ( $field ) {
							return $this->map_option_value_to_label( $selected_value, $field );
						},
						$clean_value
					);
				}
				$is_empty    = empty( $clean_value );
			} else {
				switch ( $field_type ) {
					case 'email':
						$clean_value = sanitize_email( $value );
						break;
					case 'url':
						$clean_value = esc_url_raw( $value );
						break;
					case 'textarea':
						$clean_value = sanitize_textarea_field( $value );
						break;
					default:
						$clean_value = sanitize_text_field( $value );
						break;
				}

				if ( '' !== $clean_value && $this->field_type_uses_options( $field_type ) ) {
					$clean_value = $this->map_option_value_to_label( $clean_value, $field );
				}

				$is_empty = '' === $clean_value;
			}

			if ( $required && $is_empty ) {
				$errors[] = sprintf( '%s is required.', $field_label );
			}

			$lead_data[ $field_id ] = array(
				'label'      => $field_label,
				'field_name' => $field_name,
				'type'       => $field_type,
				'value'      => $clean_value,
			);
		}

		if ( ! empty( $errors ) ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => implode( ' ', $errors ),
			);
		}

		if ( is_array( $stripe_payment_result ) ) {
			$lead_data['_stripe_payment'] = array(
				'label'             => 'Stripe Payment',
				'type'              => 'payment',
				'value'             => sprintf(
					/* translators: 1: amount 2: currency */
					__( 'Paid %1$s %2$s', 'ajforms' ),
					number_format_i18n( (float) $stripe_payment_result['amount'], 2 ),
					$stripe_payment_result['currency']
				),
				'payment_intent_id' => $stripe_payment_result['payment_intent_id'],
				'description'       => $stripe_payment_result['description'],
				'price_id'          => $stripe_payment_result['price_id'],
				'product_id'        => $stripe_payment_result['product_id'],
			);
		}

		$submission_meta = $this->get_submission_meta();
		$lead_data['_meta'] = $submission_meta;

		$leads_db    = $this->get_leads_db();
		$leads_table = $this->get_leads_table();

		$inserted = $leads_db->insert(
			$leads_table,
			array(
				'form_id'    => $form_id,
				'form_title' => isset( $form->title ) ? (string) $form->title : '',
				'lead_data'  => wp_json_encode( $lead_data ),
				'status'     => 'new',
				'ip_address' => isset( $submission_meta['ip_address'] ) ? $submission_meta['ip_address'] : '',
				'source_url' => isset( $submission_meta['page_url'] ) ? $submission_meta['page_url'] : '',
				'user_agent' => isset( $submission_meta['user_agent'] ) ? $submission_meta['user_agent'] : '',
				'site_uuid'  => (string) get_option( 'ajcore_site_uuid', '' ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => 'Unable to save your submission.',
			);
		}

		$this->send_form_notification( $form, $lead_data, $settings );
		$this->maybe_create_asana_task( $form, $lead_data, $settings );

		$confirmation_result = $this->evaluate_confirmation_rules( $form, $lead_data, $settings );
		$redirect_url        = ! empty( $confirmation_result['redirect_url'] ) ? esc_url_raw( $confirmation_result['redirect_url'] ) : '';

		if ( '' !== $redirect_url && ! headers_sent() ) {
			wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		return array(
			'submitted'    => true,
			'success'      => true,
			'message'      => ! empty( $confirmation_result['message'] ) ? $confirmation_result['message'] : 'Form submitted successfully.',
			'redirect_url' => $redirect_url,
		);
	}

	private function render_submission_redirect( $submission_result ) {
		if ( empty( $submission_result['success'] ) || empty( $submission_result['redirect_url'] ) ) {
			return '';
		}

		$redirect_url = esc_url_raw( $submission_result['redirect_url'] );

		ob_start();
		?>
		<script>
		window.location.replace(<?php echo wp_json_encode( $redirect_url ); ?>);
		</script>
		<noscript>
			<p><a href="<?php echo esc_url( $redirect_url ); ?>"><?php esc_html_e( 'Continue', 'ajforms' ); ?></a></p>
		</noscript>
		<?php

		return ob_get_clean();
	}

	private function get_frontend_field_data( $field ) {
		$field_id            = ! empty( $field['id'] ) ? $field['id'] : 'field_' . wp_generate_uuid4();
		$field_type          = ! empty( $field['type'] ) ? $field['type'] : 'text';
		$field_label         = ! empty( $field['label'] ) ? $field['label'] : ucfirst( $field_type );
		$default_value       = ! empty( $field['default_value'] ) ? $field['default_value'] : '';
		$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? $field['accepted_file_types'] : '.pdf,.jpg,.jpeg,.png,.gif,.webp';
		$options             = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$child_fields        = array();

		if ( 'question' === $field_type && empty( $options ) ) {
			$options = array(
				array(
					'label' => 'Yes',
					'value' => 'yes',
				),
				array(
					'label' => 'No',
					'value' => 'no',
				),
			);
		}

		if ( 'container' === $field_type && ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
			foreach ( $field['fields'] as $child_field ) {
				if ( is_array( $child_field ) ) {
					$child_fields[] = $this->get_frontend_field_data( $child_field );
				}
			}
		}

		return array(
			'id'                  => $field_id,
			'type'                => $field_type,
			'label'               => $field_label,
			'field_name'          => ! empty( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : sanitize_key( $field_label ),
			'required'            => ! empty( $field['required'] ),
			'placeholder'         => ! empty( $field['placeholder'] ) ? $field['placeholder'] : '',
			'options'             => $options,
			'help_text'           => ! empty( $field['help_text'] ) ? $field['help_text'] : '',
			'note_text'           => ! empty( $field['note_text'] ) ? $field['note_text'] : '',
			'heading_level'       => ! empty( $field['heading_level'] ) && in_array( $field['heading_level'], array( 'h2', 'h3', 'h4' ), true ) ? $field['heading_level'] : 'h2',
			'default_value'       => $default_value,
			'css_class'           => ! empty( $field['css_class'] ) ? $field['css_class'] : '',
			'conversational'      => array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( ! empty( $field['conversation_step'] ) ? 'final_contact' !== $field['conversation_step'] : 'question' === $field_type ),
			'accepted_file_types' => $accepted_file_types,
			'posted_value'        => isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : $default_value,
			'fields'              => $child_fields,
		);
	}

	private function render_frontend_field_control( $field_data ) {
		$field_id            = $field_data['id'];
		$field_type          = $field_data['type'];
		$placeholder         = $field_data['placeholder'];
		$required            = $field_data['required'];
		$options             = $field_data['options'];
		$posted_value        = $field_data['posted_value'];
		$accepted_file_types = $field_data['accepted_file_types'];
		$child_fields        = ! empty( $field_data['fields'] ) && is_array( $field_data['fields'] ) ? $field_data['fields'] : array();
		$control_style       = 'width:100%;max-width:780px;min-height:54px;padding:14px 16px;border-radius:calc(var(--ajforms-radius) - 6px);background:var(--ajforms-input-bg);border:2px solid var(--ajforms-input-border);color:var(--ajforms-text);font-size:18px;line-height:1.35;box-sizing:border-box;';

		ob_start();
		if ( 'heading' === $field_type ) :
			$heading_level = in_array( $field_data['heading_level'], array( 'h2', 'h3', 'h4' ), true ) ? $field_data['heading_level'] : 'h2';
			?>
			<div class="ajforms-display-block ajforms-heading-block">
				<<?php echo esc_attr( $heading_level ); ?> style="margin:0 0 8px;color:var(--ajforms-text);line-height:1.2;"><?php echo esc_html( $field_data['label'] ); ?></<?php echo esc_attr( $heading_level ); ?>>
				<?php if ( '' !== $field_data['note_text'] ) : ?>
					<p style="margin:0;color:#64748b;line-height:1.55;"><?php echo esc_html( $field_data['note_text'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php
		elseif ( 'note' === $field_type ) :
			?>
			<div class="ajforms-display-block ajforms-note-block" style="padding:14px 16px;border-radius:calc(var(--ajforms-radius) - 6px);background:rgba(15,122,198,.08);border:1px solid rgba(15,122,198,.18);color:var(--ajforms-text);">
				<?php if ( '' !== $field_data['label'] ) : ?>
					<strong style="display:block;margin-bottom:5px;"><?php echo esc_html( $field_data['label'] ); ?></strong>
				<?php endif; ?>
				<p style="margin:0;color:#4b5563;line-height:1.55;"><?php echo esc_html( $field_data['note_text'] ); ?></p>
			</div>
			<?php
		elseif ( 'container' === $field_type ) :
			?>
			<div class="ajforms-display-block ajforms-container-block" style="padding:18px 20px;border-radius:var(--ajforms-radius);background:rgba(248,250,252,.9);border:1px solid var(--ajforms-input-border);">
				<?php if ( '' !== $field_data['label'] ) : ?>
					<strong style="display:block;margin-bottom:6px;color:var(--ajforms-text);font-size:18px;"><?php echo esc_html( $field_data['label'] ); ?></strong>
				<?php endif; ?>
				<?php if ( '' !== $field_data['note_text'] ) : ?>
					<p style="margin:0 <?php echo ! empty( $child_fields ) ? '0 16px' : '0'; ?>;color:#64748b;line-height:1.55;"><?php echo esc_html( $field_data['note_text'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $child_fields ) ) : ?>
					<div class="ajforms-container-children" style="display:grid;gap:18px;margin-top:18px;">
						<?php foreach ( $child_fields as $child_field ) : ?>
							<?php
							$child_type = ! empty( $child_field['type'] ) ? $child_field['type'] : 'text';
							?>
							<div class="ajforms-container-child ajforms-field <?php echo esc_attr( $child_field['css_class'] ); ?>" style="margin:0;">
								<?php if ( $this->is_display_only_form_field( $child_type ) ) : ?>
									<?php echo $this->render_frontend_field_control( $child_field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php else : ?>
									<label for="<?php echo esc_attr( $child_field['id'] ); ?>" style="display:block;font-weight:600;margin-bottom:6px;color:var(--ajforms-text);">
										<?php echo esc_html( $child_field['label'] ); ?>
										<?php if ( ! empty( $child_field['required'] ) ) : ?>
											<span style="color:#d63638;">*</span>
										<?php endif; ?>
									</label>
									<?php echo $this->render_frontend_field_control( $child_field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ( '' !== $child_field['help_text'] ) : ?>
										<p style="margin-top:6px;color:#646970;font-size:12px;"><?php echo esc_html( $child_field['help_text'] ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
		elseif ( 'textarea' === $field_type ) :
			?>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>min-height:110px;"><?php echo esc_textarea( is_string( $posted_value ) ? $posted_value : '' ); ?></textarea>
			<?php
		elseif ( 'select' === $field_type ) :
			?>
			<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>">
				<option value=""><?php echo esc_html( $placeholder ?: 'Select an option' ); ?></option>
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $posted_value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		elseif ( 'checkboxes' === $field_type ) :
			$posted_array = is_array( $posted_value ) ? $posted_value : array();
			?>
			<div id="<?php echo esc_attr( $field_id ); ?>" class="ajforms-choice-list ajforms-checkbox-list" style="display:grid;gap:10px;max-width:900px;">
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<label style="display:flex;align-items:center;gap:12px;color:var(--ajforms-text);font-size:18px;line-height:1.35;font-weight:600;cursor:pointer;">
						<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>[]" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( in_array( $option_value, $posted_array, true ) ); ?> style="width:22px;height:22px;flex:0 0 auto;accent-color:var(--ajforms-primary);">
						<span><?php echo esc_html( $option_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
		elseif ( 'question' === $field_type || 'multiple_choice' === $field_type ) :
			?>
			<div id="<?php echo esc_attr( $field_id ); ?>" class="<?php echo 'question' === $field_type ? 'ajforms-question-options' : ''; ?>" style="<?php echo 'question' === $field_type ? 'display:grid;gap:14px;' : ''; ?>">
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<label class="<?php echo 'question' === $field_type ? 'ajforms-question-option' : ''; ?>" style="<?php echo 'question' === $field_type ? 'display:flex;align-items:center;gap:16px;padding:24px 26px;border:2px solid var(--ajforms-input-border);border-radius:calc(var(--ajforms-radius) + 6px);background:var(--ajforms-input-bg);color:var(--ajforms-text);font-size:28px;line-height:1.15;font-weight:800;cursor:pointer;' : 'display:flex;align-items:center;gap:12px;margin-bottom:10px;color:var(--ajforms-text);font-size:18px;font-weight:600;cursor:pointer;'; ?>">
						<input type="radio" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( $posted_value, $option_value ); ?> <?php echo $required ? 'required' : ''; ?> style="<?php echo 'question' === $field_type ? '' : 'width:22px;height:22px;flex:0 0 auto;accent-color:var(--ajforms-primary);'; ?>">
						<span><?php echo esc_html( $option_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
		elseif ( 'separator' === $field_type ) :
			?>
			<hr>
			<?php
		elseif ( 'file' === $field_type ) :
			?>
			<input type="file" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" accept="<?php echo esc_attr( $accepted_file_types ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>">
			<?php
		else :
			$input_type_map = array(
				'email'   => 'email',
				'url'     => 'url',
				'number'  => 'number',
				'phone'   => 'tel',
				'tel'     => 'tel',
				'date'    => 'date',
				'address' => 'text',
				'text'    => 'text',
			);
			$input_type = isset( $input_type_map[ $field_type ] ) ? $input_type_map[ $field_type ] : 'text';
			?>
			<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( is_string( $posted_value ) ? $posted_value : '' ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>">
			<?php
		endif;

		return ob_get_clean();
	}

	private function render_conversational_form( $form, $fields, $settings, $submission_result, $wrapper_style, $form_theme, $challenge_enabled, $challenge_config ) {
		$fields = $this->flatten_form_fields( $fields );
		$answerable_fields = array_values(
			array_filter(
				$fields,
				function ( $field ) {
					$field_type = is_array( $field ) && ! empty( $field['type'] ) ? (string) $field['type'] : 'text';
					return is_array( $field ) && ! $this->is_display_only_form_field( $field_type );
				}
			)
		);
		$question_fields = array_values(
			array_filter(
				$answerable_fields,
				function ( $field ) {
					return array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( empty( $field['conversation_step'] ) || 'final_contact' !== $field['conversation_step'] );
				}
			)
		);
		$contact_fields = array_values(
			array_filter(
				$answerable_fields,
				function ( $field ) {
					return array_key_exists( 'conversational', $field ) ? empty( $field['conversational'] ) : ( ! empty( $field['conversation_step'] ) && 'final_contact' === $field['conversation_step'] );
				}
			)
		);

		$total_steps = count( $answerable_fields );
		if ( ! empty( $contact_fields ) ) {
			$total_steps = count( $question_fields ) + 1;
		}
		if ( 0 === $total_steps ) {
			return '<p>This form has no questions.</p>';
		}

		$step_counter = 0;

		ob_start();
		?>
		<?php if ( $challenge_enabled ) : ?>
			<script src="<?php echo esc_url( $challenge_config['script_url'] ); ?>" async defer></script>
		<?php endif; ?>
		<form class="ajforms-frontend-form ajforms-conversational-form ajforms-theme-<?php echo esc_attr( $form_theme ); ?>" method="post" enctype="multipart/form-data" style="<?php echo esc_attr( $wrapper_style ); ?>margin:0 auto;padding:28px;border-radius:var(--ajforms-radius);background:var(--ajforms-bg);border:1px solid #dfe6ee;box-shadow:0 20px 45px rgba(18,52,77,.08);">
			<style>
				.ajforms-frontend-form{margin-top:0!important}
				.ajforms-frontend-form input:focus,.ajforms-frontend-form textarea:focus,.ajforms-frontend-form select:focus{outline:0;border-color:var(--ajforms-primary)!important;box-shadow:0 0 0 4px rgba(15,122,198,.12)}
				.ajforms-conversational-form .ajforms-question-option input[type="radio"]{width:28px;height:28px;flex:0 0 auto;accent-color:var(--ajforms-primary)}
				.ajforms-conversational-form .ajforms-question-option:has(input:checked){border-color:var(--ajforms-primary);box-shadow:0 0 0 4px rgba(15,122,198,.14)}
				.ajforms-conversational-form .ajforms-conversation-contact-step .ajforms-field{margin-bottom:16px!important}
				.ajforms-conversational-form .ajforms-conversation-contact-step label{font-size:16px!important}
				.ajforms-conversational-form .ajforms-checkbox-list label:hover{color:var(--ajforms-primary)}
				.ajforms-conversational-form .ajforms-choice-list input:checked + span{color:var(--ajforms-primary)}
			</style>
			<input type="hidden" name="ajf_form_id" value="<?php echo esc_attr( $form->id ); ?>">
			<?php wp_nonce_field( 'ajf_submit_form_' . absint( $form->id ), 'ajf_form_nonce' ); ?>
			<?php echo $this->render_tracking_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $this->is_honeypot_enabled() ) : ?>
				<?php $honeypot_field_name = $this->get_honeypot_field_name( $form->id ); ?>
				<div class="ajforms-honeypot" aria-hidden="true" style="position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;">
					<label for="<?php echo esc_attr( $honeypot_field_name ); ?>"><?php esc_html_e( 'Leave this field empty', 'ajforms' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $honeypot_field_name ); ?>" name="<?php echo esc_attr( $honeypot_field_name ); ?>" value="" tabindex="-1" autocomplete="off">
				</div>
			<?php endif; ?>

			<div class="ajforms-conversation-head" style="margin-bottom:22px;">
				<div style="font-size:13px;font-weight:700;color:var(--ajforms-primary);text-transform:uppercase;letter-spacing:.08em;"><?php echo esc_html( $form->title ); ?></div>
				<div class="ajforms-conversation-progress" style="height:6px;background:rgba(15,122,198,.16);border-radius:999px;margin-top:14px;overflow:hidden;">
					<span style="display:block;width:<?php echo esc_attr( 100 / $total_steps ); ?>%;height:100%;background:var(--ajforms-primary);border-radius:999px;"></span>
				</div>
			</div>

			<?php echo $this->render_submission_redirect( $submission_result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $submission_result['submitted'] ) : ?>
				<div class="ajforms-message <?php echo $submission_result['success'] ? 'success' : 'error'; ?>" style="margin-bottom:20px;padding:12px;border-radius:4px;<?php echo $submission_result['success'] ? 'background:#edfaef;color:#116329;' : 'background:#fcf0f1;color:#8a2424;'; ?>">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $submission_result['success'] ) : ?>
				<?php foreach ( $question_fields as $field ) : ?>
					<?php $field_data = $this->get_frontend_field_data( $field ); ?>
					<section
						class="ajforms-conversation-step <?php echo 'question' === $field_data['type'] ? 'ajforms-question-step' : ''; ?>"
						data-step="<?php echo esc_attr( $step_counter ); ?>"
						data-field-id="<?php echo esc_attr( $field_data['id'] ); ?>"
						data-field-type="<?php echo esc_attr( $field_data['type'] ); ?>"
						data-branch-map="<?php echo esc_attr( wp_json_encode( ! empty( $field['branch_map'] ) && is_array( $field['branch_map'] ) ? $field['branch_map'] : array() ) ); ?>"
						data-flow-rules="<?php echo esc_attr( wp_json_encode( ! empty( $field['flow_rules'] ) && is_array( $field['flow_rules'] ) ? $field['flow_rules'] : array() ) ); ?>"
						<?php echo 'question' === $field_data['type'] ? 'data-auto-advance="1"' : ''; ?>
						style="<?php echo 0 === $step_counter ? '' : 'display:none;'; ?>"
					>
						<div style="font-size:14px;color:#64748b;margin-bottom:12px;"><?php echo esc_html( sprintf( 'Question %1$d of %2$d', $step_counter + 1, $total_steps ) ); ?></div>
						<label for="<?php echo esc_attr( $field_data['id'] ); ?>" style="display:block;font-size:24px;line-height:1.25;font-weight:800;color:var(--ajforms-text);margin-bottom:16px;">
							<?php echo esc_html( $field_data['label'] ); ?>
							<?php if ( $field_data['required'] ) : ?>
								<span style="color:#d63638;">*</span>
							<?php endif; ?>
						</label>
						<?php echo $this->render_frontend_field_control( $field_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( '' !== $field_data['help_text'] ) : ?>
							<p style="margin-top:10px;color:#646970;font-size:13px;"><?php echo esc_html( $field_data['help_text'] ); ?></p>
						<?php endif; ?>
					</section>
					<?php $step_counter++; ?>
				<?php endforeach; ?>
				<?php if ( ! empty( $contact_fields ) ) : ?>
					<section class="ajforms-conversation-step ajforms-conversation-contact-step" data-step="<?php echo esc_attr( $step_counter ); ?>" data-field-id="__contact" style="<?php echo empty( $question_fields ) ? '' : 'display:none;'; ?>">
						<div style="font-size:14px;color:#64748b;margin-bottom:12px;"><?php echo esc_html( sprintf( 'Step %1$d of %2$d', $total_steps, $total_steps ) ); ?></div>
						<div style="font-size:24px;line-height:1.25;font-weight:800;color:var(--ajforms-text);margin-bottom:8px;"><?php esc_html_e( 'How can we reach you?', 'ajforms' ); ?></div>
						<p style="margin:0 0 18px;color:#64748b;"><?php esc_html_e( 'Share your contact details and we will follow up with the next step.', 'ajforms' ); ?></p>
						<?php foreach ( $contact_fields as $field ) : ?>
							<?php $field_data = $this->get_frontend_field_data( $field ); ?>
							<div class="ajforms-field <?php echo esc_attr( $field_data['css_class'] ); ?>" style="margin-bottom:18px;">
								<label for="<?php echo esc_attr( $field_data['id'] ); ?>" style="display:block;font-weight:700;color:var(--ajforms-text);margin-bottom:7px;">
									<?php echo esc_html( $field_data['label'] ); ?>
									<?php if ( $field_data['required'] ) : ?>
										<span style="color:#d63638;">*</span>
									<?php endif; ?>
								</label>
								<?php echo $this->render_frontend_field_control( $field_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( '' !== $field_data['help_text'] ) : ?>
									<p style="margin-top:8px;color:#646970;font-size:13px;"><?php echo esc_html( $field_data['help_text'] ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</section>
				<?php endif; ?>

				<?php if ( $challenge_enabled ) : ?>
					<div class="ajforms-challenge-wrap ajforms-challenge-<?php echo esc_attr( $challenge_config['provider'] ); ?>" style="margin:20px 0 0;display:none;">
						<div class="<?php echo esc_attr( $challenge_config['container_class'] ); ?>" data-sitekey="<?php echo esc_attr( $challenge_config['site_key'] ); ?>"></div>
					</div>
				<?php endif; ?>

				<div class="ajforms-conversation-actions" style="display:flex;gap:14px;align-items:center;justify-content:space-between;margin-top:26px;">
					<button type="button" class="ajforms-conversation-prev" style="display:none;background:#fff;color:var(--ajforms-text);border:2px solid var(--ajforms-input-border);border-radius:calc(var(--ajforms-radius) - 4px);padding:14px 22px;font-size:17px;font-weight:800;min-height:54px;cursor:pointer;"><?php esc_html_e( 'Back', 'ajforms' ); ?></button>
					<button type="button" class="ajforms-conversation-next" style="margin-left:auto;background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:15px 24px;font-size:17px;font-weight:800;min-height:54px;cursor:pointer;"><?php esc_html_e( 'Next', 'ajforms' ); ?></button>
					<button type="submit" class="ajforms-conversation-submit" style="display:none;margin-left:auto;background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:15px 24px;font-size:17px;font-weight:800;min-height:54px;cursor:pointer;"><?php echo esc_html( ! empty( $settings['submit_text'] ) ? $settings['submit_text'] : 'Submit' ); ?></button>
				</div>
			<?php endif; ?>
		</form>
		<script>
		(function() {
			const form = document.currentScript.previousElementSibling;
			if (!form) {
				return;
			}

			const steps = Array.from(form.querySelectorAll('.ajforms-conversation-step'));
			const progress = form.querySelector('.ajforms-conversation-progress span');
			const previousButton = form.querySelector('.ajforms-conversation-prev');
			const nextButton = form.querySelector('.ajforms-conversation-next');
			const submitButton = form.querySelector('.ajforms-conversation-submit');
			const challenge = form.querySelector('.ajforms-challenge-wrap');
			const stepIndexByFieldId = {};
			const visitedSteps = new Set();
			const history = [];
			let currentStep = 0;

			steps.forEach(function(step, index) {
				if (step.dataset.fieldId) {
					stepIndexByFieldId[step.dataset.fieldId] = index;
				}
			});

			function getStepControls(step) {
				return Array.from(step.querySelectorAll('input, select, textarea, button'));
			}

			function syncEnabledControls() {
				steps.forEach(function(step, index) {
					const enabled = index === currentStep || visitedSteps.has(index);
					getStepControls(step).forEach(function(control) {
						control.disabled = !enabled;
					});
				});
			}

			function resetVisitedFromHistory() {
				visitedSteps.clear();
				history.forEach(function(stepIndex) {
					visitedSteps.add(stepIndex);
				});
			}

			function getSelectedBranchValues(step) {
				const checked = step.querySelector('input[type="radio"]:checked');
				if (checked) {
					return [checked.value];
				}

				const checkedBoxes = Array.from(step.querySelectorAll('input[type="checkbox"]:checked'));
				if (checkedBoxes.length) {
					return checkedBoxes.map(function(input) {
						return input.value;
					});
				}

				const select = step.querySelector('select');
				if (select && select.value) {
					return [select.value];
				}

				return [];
			}

			function dispatchFlowAction(step, branchValue) {
				const event = new CustomEvent('ajforms:flowAction', {
					detail: {
						form: form,
						step: step,
						fieldId: step.dataset.fieldId || '',
						value: branchValue || '',
						values: getSelectedBranchValues(step)
					}
				});

				form.dispatchEvent(event);
			}

			function flowConditionMatches(condition, values) {
				const operator = condition.operator || 'equals';
				const expected = condition.value || '';
				const hasValue = values.length > 0 && values.join('').length > 0;

				if (operator === 'is_empty') {
					return !hasValue;
				}

				if (operator === 'is_not_empty') {
					return hasValue;
				}

				if (operator === 'not_equals') {
					return !values.includes(expected);
				}

				if (operator === 'contains') {
					return values.some(function(value) {
						return String(value).includes(expected);
					});
				}

				if (operator === 'not_contains') {
					return !values.some(function(value) {
						return String(value).includes(expected);
					});
				}

				return values.includes(expected);
			}

			function evaluateFlowRules(step, values) {
				let rules = [];

				try {
					rules = step.dataset.flowRules ? JSON.parse(step.dataset.flowRules) : [];
				} catch (error) {
					rules = [];
				}

				if (!Array.isArray(rules) || !rules.length) {
					return null;
				}

				rules.sort(function(a, b) {
					return parseInt(a.priority || 0, 10) - parseInt(b.priority || 0, 10);
				});

				for (let ruleIndex = 0; ruleIndex < rules.length; ruleIndex += 1) {
					const rule = rules[ruleIndex];
					if (rule.enabled === false) {
						continue;
					}
					const conditions = Array.isArray(rule.conditions) ? rule.conditions : [];
					const logic = String(rule.logic || 'AND').toUpperCase() === 'OR' ? 'OR' : 'AND';
					const matches = conditions.map(function(condition) {
						return flowConditionMatches(condition, values);
					});
					const isMatch = logic === 'OR' ? matches.includes(true) : matches.length > 0 && !matches.includes(false);

					if (isMatch && Array.isArray(rule.actions) && rule.actions.length) {
						return rule.actions[0];
					}
				}

				return null;
			}

			function getNextStepFromBranch() {
				const step = steps[currentStep];
				let branchMap = {};

				try {
					branchMap = step.dataset.branchMap ? JSON.parse(step.dataset.branchMap) : {};
				} catch (error) {
					branchMap = {};
				}

				const branchValues = getSelectedBranchValues(step);
				const flowAction = evaluateFlowRules(step, branchValues);
				if (flowAction) {
					if (flowAction.type === 'end') {
						return '__submit';
					}

					if (flowAction.type === 'action') {
						dispatchFlowAction(step, branchValues[0] || '');
						return currentStep + 1;
					}

					if (flowAction.type === 'jump' && flowAction.target && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, flowAction.target)) {
						return stepIndexByFieldId[flowAction.target];
					}

					return currentStep + 1;
				}

				let branchValue = '';
				let target = '';

				for (let index = 0; index < branchValues.length; index += 1) {
					if (branchMap[branchValues[index]]) {
						branchValue = branchValues[index];
						target = branchMap[branchValue];
						break;
					}
				}

				if (target === '__contact' && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, '__contact')) {
					return stepIndexByFieldId.__contact;
				}

				if (target === '__end') {
					return '__submit';
				}

				if (target === '__action') {
					dispatchFlowAction(step, branchValue);
					return currentStep + 1;
				}

				if (target && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, target)) {
					return stepIndexByFieldId[target];
				}

				return currentStep + 1;
			}

			function setStep(nextStep) {
				currentStep = Math.max(0, Math.min(nextStep, steps.length - 1));
				visitedSteps.add(currentStep);

				steps.forEach(function(step, index) {
					step.style.display = index === currentStep ? '' : 'none';
				});
				syncEnabledControls();

				if (progress) {
					progress.style.width = (((currentStep + 1) / steps.length) * 100) + '%';
				}

				if (previousButton) {
					previousButton.style.display = currentStep > 0 ? '' : 'none';
				}

				const isLastStep = currentStep === steps.length - 1;
				const isAutoAdvanceStep = steps[currentStep] && steps[currentStep].dataset.autoAdvance === '1';
				if (nextButton) {
					nextButton.style.display = isLastStep || isAutoAdvanceStep ? 'none' : '';
				}
				if (submitButton) {
					submitButton.style.display = isLastStep ? '' : 'none';
				}
				if (challenge) {
					challenge.style.display = isLastStep ? '' : 'none';
				}
			}

			function currentStepIsValid() {
				const controls = Array.from(steps[currentStep].querySelectorAll('input, select, textarea'));
				for (const control of controls) {
					if (typeof control.reportValidity === 'function' && !control.reportValidity()) {
						return false;
					}
				}
				return true;
			}

			if (previousButton) {
				previousButton.addEventListener('click', function() {
					const previousStep = history.length ? history.pop() : currentStep - 1;
					resetVisitedFromHistory();
					setStep(previousStep);
				});
			}

			if (nextButton) {
				nextButton.addEventListener('click', function() {
					advanceCurrentStep();
				});
			}

			function advanceCurrentStep() {
				if (currentStepIsValid()) {
					const nextStep = getNextStepFromBranch();
					if (nextStep === '__submit' || nextStep === currentStep || nextStep >= steps.length) {
						if (typeof form.requestSubmit === 'function') {
							form.requestSubmit();
						} else {
							form.submit();
						}
						return;
					}

					history.push(currentStep);
					resetVisitedFromHistory();
					setStep(nextStep);
				}
			}

			steps.forEach(function(step) {
				if (step.dataset.autoAdvance !== '1') {
					return;
				}

				step.querySelectorAll('input[type="radio"]').forEach(function(input) {
					input.addEventListener('change', function() {
						window.setTimeout(advanceCurrentStep, 140);
					});
				});
			});

			setStep(0);
		})();
		</script>
		<?php

		return ob_get_clean();
	}

	public function render_form_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'ajforms'
		);

		$form_id = absint( $atts['id'] );
		if ( ! $form_id ) {
			return '<p>Invalid form ID.</p>';
		}

		$form = $this->get_form_by_id( $form_id );
		if ( ! $form ) {
			return '<p>Form not found.</p>';
		}

		$schema = json_decode( $form->form_schema, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return '<p>Form schema is invalid.</p>';
		}

		$normalized  = $this->normalize_schema( $schema );
		$fields      = $normalized['fields'];
		$settings    = $normalized['settings'];
		$submit_text = ! empty( $settings['submit_text'] ) ? $settings['submit_text'] : 'Submit';
		$border_radius = isset( $settings['border_radius'] ) ? min( 32, max( 0, absint( $settings['border_radius'] ) ) ) : 16;
		$background_mode = isset( $settings['background_mode'] ) ? $settings['background_mode'] : 'solid';
		$background_value = 'gradient' === $background_mode
			? 'linear-gradient(135deg, ' . ( ! empty( $settings['background_gradient_start'] ) ? $settings['background_gradient_start'] : '#ffffff' ) . ' 0%, ' . ( ! empty( $settings['background_gradient_end'] ) ? $settings['background_gradient_end'] : '#f3f7fb' ) . ' 100%)'
			: ( ! empty( $settings['background_color'] ) ? $settings['background_color'] : '#ffffff' );
		$form_theme = ! empty( $settings['form_theme'] ) ? $settings['form_theme'] : 'clean';
		$wrapper_style = sprintf(
			'--ajforms-primary:%1$s;--ajforms-text:%2$s;--ajforms-input-bg:%3$s;--ajforms-input-border:%4$s;--ajforms-radius:%5$dpx;--ajforms-bg:%6$s;',
			! empty( $settings['primary_color'] ) ? $settings['primary_color'] : '#0f7ac6',
			! empty( $settings['text_color'] ) ? $settings['text_color'] : '#1f2937',
			! empty( $settings['input_background'] ) ? $settings['input_background'] : '#ffffff',
			! empty( $settings['input_border_color'] ) ? $settings['input_border_color'] : '#d7dce3',
			$border_radius,
			$background_value
		);

		if ( empty( $fields ) ) {
			return '<p>This form has no fields.</p>';
		}

		$challenge_enabled = $this->should_render_challenge_provider();
		$challenge_config  = $this->get_spam_provider_config();
		$stripe_config     = $this->get_stripe_payment_config( $form, $settings );
		$stripe_enabled    = ! empty( $stripe_config['enabled'] );
		$stripe_nonce      = wp_create_nonce( 'ajf_stripe_payment_' . $form_id );

		$submission_result = $this->handle_form_submission( $form, $fields, $settings );
		$has_conversational_fields = ! empty(
			array_filter(
				$this->flatten_form_fields( $fields ),
				function ( $field ) {
					$field_type = is_array( $field ) && ! empty( $field['type'] ) ? (string) $field['type'] : 'text';
					if ( ! is_array( $field ) || $this->is_display_only_form_field( $field_type ) ) {
						return false;
					}
					return is_array( $field ) && ( array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( ! empty( $field['conversation_step'] ) ? 'final_contact' !== $field['conversation_step'] : ( ! empty( $field['type'] ) && 'question' === $field['type'] ) ) );
				}
			)
		);

		if ( $has_conversational_fields ) {
			return $this->render_conversational_form( $form, $fields, $settings, $submission_result, $wrapper_style, $form_theme, $challenge_enabled, $challenge_config );
		}

		ob_start();
		?>
		<?php if ( $challenge_enabled ) : ?>
			<script src="<?php echo esc_url( $challenge_config['script_url'] ); ?>" async defer></script>
		<?php endif; ?>
		<?php if ( $stripe_enabled ) : ?>
			<script src="https://js.stripe.com/v3/"></script>
		<?php endif; ?>
		<form class="ajforms-frontend-form ajforms-theme-<?php echo esc_attr( $form_theme ); ?>" method="post" enctype="multipart/form-data" style="<?php echo esc_attr( $wrapper_style ); ?>padding:24px;border-radius:var(--ajforms-radius);background:var(--ajforms-bg);border:1px solid #dfe6ee;box-shadow:0 20px 45px rgba(18,52,77,.08);">
			<input type="hidden" name="ajf_form_id" value="<?php echo esc_attr( $form_id ); ?>">
			<input type="hidden" name="ajf_stripe_payment_intent" value="">
			<?php wp_nonce_field( 'ajf_submit_form_' . $form_id, 'ajf_form_nonce' ); ?>
			<?php echo $this->render_tracking_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $this->is_honeypot_enabled() ) : ?>
				<?php $honeypot_field_name = $this->get_honeypot_field_name( $form_id ); ?>
				<div class="ajforms-honeypot" aria-hidden="true" style="position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;">
					<label for="<?php echo esc_attr( $honeypot_field_name ); ?>"><?php esc_html_e( 'Leave this field empty', 'ajforms' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $honeypot_field_name ); ?>"
						name="<?php echo esc_attr( $honeypot_field_name ); ?>"
						value=""
						tabindex="-1"
						autocomplete="off"
					>
				</div>
			<?php endif; ?>

			<div class="ajforms-form-title">
				<h3><?php echo esc_html( $form->title ); ?></h3>
				<?php if ( ! empty( $settings['form_description'] ) ) : ?>
					<p><?php echo esc_html( $settings['form_description'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php echo $this->render_submission_redirect( $submission_result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $submission_result['submitted'] ) : ?>
				<div class="ajforms-message <?php echo $submission_result['success'] ? 'success' : 'error'; ?>" style="margin-bottom:20px;padding:12px;border-radius:4px;<?php echo $submission_result['success'] ? 'background:#edfaef;color:#116329;' : 'background:#fcf0f1;color:#8a2424;'; ?>">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $submission_result['success'] ) : ?>
				<?php if ( $challenge_enabled ) : ?>
					<div class="ajforms-challenge-wrap ajforms-challenge-<?php echo esc_attr( $challenge_config['provider'] ); ?>" style="margin:0 0 24px;">
						<div class="<?php echo esc_attr( $challenge_config['container_class'] ); ?>" data-sitekey="<?php echo esc_attr( $challenge_config['site_key'] ); ?>"></div>
					</div>
				<?php endif; ?>

				<?php foreach ( $fields as $field ) : ?>
					<?php
					if ( ! is_array( $field ) ) {
						continue;
					}

					$field_id    = ! empty( $field['id'] ) ? $field['id'] : 'field_' . wp_generate_uuid4();
					$field_type  = ! empty( $field['type'] ) ? $field['type'] : 'text';
					$field_label = ! empty( $field['label'] ) ? $field['label'] : ucfirst( $field_type );
					$required    = ! empty( $field['required'] );
					$placeholder = ! empty( $field['placeholder'] ) ? $field['placeholder'] : '';
					$options     = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					$help_text   = ! empty( $field['help_text'] ) ? $field['help_text'] : '';
					$default_value = ! empty( $field['default_value'] ) ? $field['default_value'] : '';
					$css_class   = ! empty( $field['css_class'] ) ? $field['css_class'] : '';
					$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? $field['accepted_file_types'] : '.pdf,.jpg,.jpeg,.png,.gif,.webp';

					$posted_value = isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : $default_value;
					?>
					<div class="ajforms-field <?php echo esc_attr( $css_class ); ?>" style="margin-bottom:20px;">
						<?php if ( $this->is_display_only_form_field( $field_type ) ) : ?>
							<?php echo $this->render_frontend_field_control( $this->get_frontend_field_data( $field ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<label for="<?php echo esc_attr( $field_id ); ?>" style="display:block; font-weight:600; margin-bottom:6px;color:var(--ajforms-text);">
								<?php echo esc_html( $field_label ); ?>
								<?php if ( $required ) : ?>
									<span style="color:#d63638;">*</span>
								<?php endif; ?>
							</label>
						<?php endif; ?>

						<?php if ( $this->is_display_only_form_field( $field_type ) ) : ?>
							<?php // Display-only block was rendered above. ?>
						<?php elseif ( 'textarea' === $field_type ) : ?>
							<textarea
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							><?php echo esc_textarea( is_string( $posted_value ) ? $posted_value : '' ); ?></textarea>

						<?php elseif ( 'select' === $field_type ) : ?>
							<select
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							>
								<option value=""><?php echo esc_html( $placeholder ?: 'Select an option' ); ?></option>
								<?php foreach ( $options as $option ) : ?>
									<?php
									$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
									$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
									?>
									<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $posted_value, $option_value ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

						<?php elseif ( 'checkboxes' === $field_type ) : ?>
							<div id="<?php echo esc_attr( $field_id ); ?>">
								<?php
								$posted_array = is_array( $posted_value ) ? $posted_value : array();
								foreach ( $options as $option ) :
									$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
									$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
									?>
									<label style="display:block; margin-bottom:6px;color:var(--ajforms-text);">
										<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>[]" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( in_array( $option_value, $posted_array, true ) ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</label>
								<?php endforeach; ?>
							</div>

						<?php elseif ( 'question' === $field_type || 'multiple_choice' === $field_type ) : ?>
							<div id="<?php echo esc_attr( $field_id ); ?>">
								<?php foreach ( $options as $option ) : ?>
									<?php
									$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
									$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
									?>
									<label style="display:block; margin-bottom:6px;color:var(--ajforms-text);">
										<input type="radio" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( $posted_value, $option_value ); ?> <?php echo $required ? 'required' : ''; ?>>
										<?php echo esc_html( $option_label ); ?>
									</label>
								<?php endforeach; ?>
							</div>

						<?php elseif ( 'separator' === $field_type ) : ?>
							<hr>

						<?php elseif ( 'file' === $field_type ) : ?>
							<input
								type="file"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								accept="<?php echo esc_attr( $accepted_file_types ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							>

						<?php else : ?>
							<?php
							$input_type_map = array(
								'email'   => 'email',
								'url'     => 'url',
								'number'  => 'number',
								'phone'   => 'tel',
								'tel'     => 'tel',
								'date'    => 'date',
								'address' => 'text',
								'text'    => 'text',
							);

							$input_type = isset( $input_type_map[ $field_type ] ) ? $input_type_map[ $field_type ] : 'text';
							?>
							<input
								type="<?php echo esc_attr( $input_type ); ?>"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								value="<?php echo esc_attr( is_string( $posted_value ) ? $posted_value : '' ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							>
						<?php endif; ?>

						<?php if ( '' !== $help_text ) : ?>
							<p style="margin-top:6px;color:#646970;font-size:12px;"><?php echo esc_html( $help_text ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( $stripe_enabled ) : ?>
					<div class="ajforms-stripe-payment" data-form-id="<?php echo esc_attr( $form_id ); ?>" data-publishable-key="<?php echo esc_attr( $stripe_config['publishable_key'] ); ?>" data-payment-nonce="<?php echo esc_attr( $stripe_nonce ); ?>">
						<div style="margin:24px 0 16px;padding:18px;border:1px solid #dfe6ee;border-radius:calc(var(--ajforms-radius) - 4px);background:#fff;">
							<div style="font-size:16px;font-weight:700;color:var(--ajforms-text);margin-bottom:6px;"><?php esc_html_e( 'Payment', 'ajforms' ); ?></div>
							<div style="font-size:14px;color:#4b5563;margin-bottom:14px;">
								<?php echo esc_html( $stripe_config['description'] ); ?>
							</div>
							<div style="font-size:22px;font-weight:800;color:var(--ajforms-text);margin-bottom:16px;">
								<?php echo esc_html( strtoupper( $stripe_config['currency'] ) . ' ' . number_format_i18n( (float) $stripe_config['amount'], 2 ) ); ?>
							</div>
							<div class="ajforms-stripe-payment-element" id="ajforms-stripe-payment-element-<?php echo esc_attr( $form_id ); ?>"></div>
							<p class="ajforms-stripe-payment-message" style="display:none;margin:12px 0 0;color:#b32d2e;"></p>
						</div>
					</div>
				<?php endif; ?>

				<div class="ajforms-submit" style="text-align:<?php echo esc_attr( ! empty( $settings['button_alignment'] ) ? $settings['button_alignment'] : 'left' ); ?>;">
					<button type="submit" style="background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:12px 20px;font-weight:700;"><?php echo esc_html( $submit_text ); ?></button>
				</div>
			<?php endif; ?>
		</form>
		<?php if ( $stripe_enabled ) : ?>
			<script>
			(function() {
				const form = document.currentScript.previousElementSibling;
				if (!form || typeof window.Stripe === 'undefined') {
					return;
				}

				const paymentWrap = form.querySelector('.ajforms-stripe-payment');
				const paymentElementNode = form.querySelector('.ajforms-stripe-payment-element');
				const paymentMessage = form.querySelector('.ajforms-stripe-payment-message');
				const paymentIntentInput = form.querySelector('input[name="ajf_stripe_payment_intent"]');
				const submitButton = form.querySelector('button[type="submit"]');

				if (!paymentWrap || !paymentElementNode || !paymentIntentInput || !submitButton) {
					return;
				}

				const stripe = window.Stripe(paymentWrap.dataset.publishableKey);
				let elements = null;
				let activeClientSecret = '';
				let isSubmitting = false;

				function setPaymentMessage(message) {
					if (!paymentMessage) {
						return;
					}

					paymentMessage.textContent = message || '';
					paymentMessage.style.display = message ? 'block' : 'none';
				}

				async function createPaymentIntent() {
					const formData = new FormData();
					formData.append('action', 'ajf_create_stripe_payment_intent');
					formData.append('form_id', paymentWrap.dataset.formId);
					formData.append('nonce', paymentWrap.dataset.paymentNonce);

					const response = await fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						body: formData
					});
					const payload = await response.json();

					if (!payload.success || !payload.data || !payload.data.client_secret) {
						throw new Error((payload && payload.data) || 'Unable to initialize Stripe payment.');
					}

					return payload.data.client_secret;
				}

				async function ensurePaymentElement() {
					if (elements && activeClientSecret) {
						return;
					}

					activeClientSecret = await createPaymentIntent();
					elements = stripe.elements({ clientSecret: activeClientSecret });
					const paymentElement = elements.create('payment');
					paymentElement.mount(paymentElementNode);
				}

				ensurePaymentElement().catch(function(error) {
					setPaymentMessage(error.message || 'Unable to initialize Stripe payment.');
				});

				form.addEventListener('submit', async function(event) {
					if (isSubmitting || paymentIntentInput.value) {
						return;
					}

					event.preventDefault();
					setPaymentMessage('');

					if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
						return;
					}

					try {
						submitButton.disabled = true;
						isSubmitting = true;
						await ensurePaymentElement();

						const result = await stripe.confirmPayment({
							elements: elements,
							redirect: 'if_required'
						});

						if (result.error) {
							throw new Error(result.error.message || 'Stripe payment could not be completed.');
						}

						if (!result.paymentIntent || result.paymentIntent.status !== 'succeeded') {
							throw new Error('Stripe payment is not complete yet.');
						}

						paymentIntentInput.value = result.paymentIntent.id;
						form.submit();
					} catch (error) {
						setPaymentMessage(error.message || 'Stripe payment could not be completed.');
						submitButton.disabled = false;
						isSubmitting = false;
					}

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-custom-service-request-button');
					if (!button || button.disabled) {
						return;
					}

					const message = shell.querySelector('.aj-portal-add-service-message');
					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Submitting...', 'ajforms' ) ); ?>';

					if (message) {
						message.textContent = '';
						message.className = 'aj-portal-add-service-message';
						message.style.display = 'none';
					}

					const formData = new FormData();
					formData.append('action', 'ajcore_create_custom_service_request');
					formData.append('price_id', button.dataset.priceId || '');
					formData.append('nonce', button.dataset.nonce || '');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
							if (message) {
								message.textContent = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Your request was submitted and is now under review.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-success';
								message.style.display = 'block';
							}
							button.textContent = '<?php echo esc_js( __( 'Request Submitted', 'ajforms' ) ); ?>';
							setTimeout(function() { window.location.reload(); }, 800);
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							if (message) {
								message.textContent = error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-error';
								message.style.display = 'block';
							} else {
								window.alert(error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
						});
				});

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-cancel-service-request');
					if (!button || button.disabled) {
						return;
					}
					if (!window.confirm('<?php echo esc_js( __( 'Cancel this pending service request?', 'ajforms' ) ); ?>')) {
						return;
					}
					button.disabled = true;
					const formData = new FormData();
					formData.append('action', 'ajcore_cancel_portal_service_request');
					formData.append('ledger_id', button.dataset.ledgerId || '');
					formData.append('nonce', button.dataset.nonce || '');
					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(function(response) { return response.json(); })
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
							}
							window.location.reload();
						})
						.catch(function(error) {
							button.disabled = false;
							window.alert(error.message || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
						});
				});
			})();
			</script>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	// =========================================================================
	// Customer Portal – Reservations Tab
	// =========================================================================

	private function render_customer_portal_reservations_tab() {
		$settings           = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$reservations_enabled = ! empty( $settings['zoho_reservations_enabled'] );

		if ( ! $reservations_enabled ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Reservations', 'ajforms' ) . '</h2><p>' . esc_html__( 'Reservations are not currently available.', 'ajforms' ) . '</p></section>';
		}

		$resource_name         = ! empty( $settings['reservation_resource_name'] ) ? $settings['reservation_resource_name'] : __( 'Conference Room', 'ajforms' );
		$resource_key          = ! empty( $settings['reservation_resource_key'] ) ? $settings['reservation_resource_key'] : 'conference_room';
		$business_hours_label  = ! empty( $settings['reservation_business_hours_label'] ) ? $settings['reservation_business_hours_label'] : __( 'Business Hours Rate', 'ajforms' );
		$after_hours_label     = ! empty( $settings['reservation_after_hours_label'] ) ? $settings['reservation_after_hours_label'] : __( 'After-Hours / Weekend Rate', 'ajforms' );
		$timezone              = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		$context            = $this->get_current_user_portal_billing_context();
		$stripe_customer_id = $context['stripe_customer_id'];
		$wp_user_id         = get_current_user_id();
		$stripe_settings    = $this->get_stripe_settings();

		// Upcoming and past reservations for this customer.
		$my_reservations = array();
		if ( class_exists( 'AJCore_Reservations' ) && ( $stripe_customer_id || $wp_user_id ) ) {
			$my_reservations = AJCore_Reservations::get_customer_reservations( $stripe_customer_id, $wp_user_id );
		}

		ob_start();
		$ajax_url     = esc_url( admin_url( 'admin-ajax.php' ) );
		$check_nonce  = wp_create_nonce( 'ajcore_reservation_check_availability' );
		$chkout_nonce = wp_create_nonce( 'ajcore_reservation_create_checkout' );
		$cart_nonce   = wp_create_nonce( 'ajcore_reservation_add_to_cart' );
		$cart_chkout_nonce = wp_create_nonce( 'ajcore_reservation_cart_checkout' );
		$remove_nonce = wp_create_nonce( 'ajcore_reservation_cart_remove' );
		$portal_nonce = wp_create_nonce( 'ajcore_stripe_customer_portal' );
		$pub_key      = isset( $stripe_settings['publishable_key'] ) ? $stripe_settings['publishable_key'] : '';
		?>
		<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
		<style>
		/* ── Conference Room — Top Bar ───────────────────────────────── */
		.aj-res-topbar{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px}
		.aj-res-topbar-left{}
		h2.aj-res-page-title{background:none!important;-webkit-background-clip:initial!important;background-clip:initial!important;color:#1e293b!important;font-size:18px;font-weight:800;margin:0 0 2px;letter-spacing:-.3px}
		.aj-res-page-title{font-size:18px;font-weight:800;color:#1e293b;margin:0 0 2px;letter-spacing:-.3px}
		.aj-res-page-sub{font-size:12px;color:#64748b;margin:0}
		.aj-res-topbar-right{display:flex;flex-wrap:wrap;align-items:center;gap:8px}
		.aj-res-rate-pill{display:inline-flex;flex-direction:column;align-items:center;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:5px 12px;font-size:13px;font-weight:700;color:#1e293b;line-height:1.3}
		.aj-res-rate-pill small{font-size:10px;font-weight:500;color:#64748b;margin-top:1px}
		.aj-res-billing-btn{background:#f8fafc!important;border:1px solid #e2e8f0!important;color:#334155!important;border-radius:8px!important;font-size:12px!important;padding:5px 14px!important;font-weight:600!important;white-space:nowrap;transition:background .15s,border-color .15s;text-shadow:none!important;box-shadow:none!important}
		.aj-res-billing-btn:hover{background:#f1f5f9!important;border-color:#c7d4f5!important;color:#3157ff!important}
		.aj-res-billing-err{font-size:11px;color:#991b1b;margin-top:2px}
		/* ── Info bar (legend + notices) ─────────────────────────────── */
		.aj-res-infobar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:6px 14px;margin-bottom:10px;padding:7px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;font-size:12px;color:#475569}
		.aj-res-legend{display:flex;align-items:center;gap:12px}
		.aj-res-legend-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:4px;vertical-align:middle}
		.aj-res-legend-dot.red{background:#f87171}
		.aj-res-legend-dot.amber{background:#fbbf24}
		.aj-res-notices{display:flex;flex-wrap:wrap;gap:3px 12px}
		.aj-res-policy{margin:0;font-weight:700;color:#b45309}
		.aj-res-voffice{margin:0;color:#15803d;font-weight:600}
		/* ── Cart panel ──────────────────────────────────────────────── */
		.aj-res-cart-panel{background:#fff;border:2px solid #e0e7ff;border-radius:14px;padding:16px 18px;margin-bottom:16px;transition:border-color .25s,box-shadow .25s}
		.aj-res-cart-panel.has-items{border-color:#3157ff;box-shadow:0 4px 24px rgba(49,87,255,.1)}
		.aj-res-cart-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px}
		.aj-res-cart-title-row{display:flex;align-items:center;gap:10px}
		.aj-res-cart-title-text{font-size:15px;font-weight:700;color:#1e293b;margin:0}
		.aj-res-cart-badge{background:#cbd5e1;color:#fff;font-size:11px;font-weight:800;min-width:20px;height:20px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;transition:transform .15s,background .2s}
		.aj-res-cart-badge.has-items{background:#3157ff}
		.aj-res-cart-badge.pulse{animation:aj-badge-pop .28s ease}
		@keyframes aj-badge-pop{0%{transform:scale(1)}50%{transform:scale(1.45)}100%{transform:scale(1)}}
		.aj-res-cart-empty{text-align:center;padding:10px 0 8px;color:#94a3b8}
		.aj-res-cart-empty-icon{font-size:20px;margin-bottom:4px;opacity:.45;line-height:1}
		.aj-res-cart-empty-text{font-size:13px;line-height:1.5}
		.aj-res-cart-item{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:10px 12px;background:#f8fafc;border:1px solid #e8edf5;border-radius:10px;margin-bottom:6px;font-size:13px;transition:background .15s,border-color .15s}
		.aj-res-cart-item:hover{background:#f0f4ff;border-color:#c7d4f5}
		.aj-res-cart-item-info{flex:1;min-width:0}
		.aj-res-cart-item-date{font-weight:700;color:#1e293b;margin-bottom:1px}
		.aj-res-cart-item-time{color:#475569;font-size:12px}
		.aj-res-cart-item-rate{color:#64748b;font-size:12px;margin-top:2px}
		.aj-res-cart-item-price{font-size:17px;font-weight:800;color:#166534;white-space:nowrap}
		.aj-res-cart-remove{background:none;border:none;color:#94a3b8;cursor:pointer;font-size:15px;line-height:1;padding:4px 7px;border-radius:6px;transition:color .12s,background .12s;margin-left:4px}
		.aj-res-cart-remove:hover{color:#dc2626;background:#fee2e2}
		.aj-res-cart-footer{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid #e2e8f0;margin-top:6px}
		.aj-res-cart-total-label{font-size:14px;font-weight:700;color:#1e293b}
		.aj-res-cart-total-amount{color:#166534;margin-left:4px}
		.aj-res-checkout-btn{background:linear-gradient(135deg,#3157ff,#7c3aed)!important;border:none!important;color:#fff!important;font-size:13px!important;font-weight:700!important;padding:7px 20px!important;border-radius:8px!important;cursor:pointer!important;transition:opacity .15s,transform .1s!important}
		.aj-res-checkout-btn:hover:not(:disabled){opacity:.9;transform:translateY(-1px)}
		.aj-res-checkout-btn:disabled{opacity:.55;cursor:not-allowed!important;transform:none!important}
		.aj-res-cart-msg{font-size:12px;color:#991b1b;margin:8px 0 0;padding:6px 10px;background:#fee2e2;border-radius:7px;display:none}
		/* ── My Reservations ─────────────────────────────────────────── */
		.aj-res-my-section{margin-bottom:20px}
		.aj-res-section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
		.aj-res-section-title{font-size:16px;font-weight:700;color:#1e293b;margin:0}
		.aj-res-res-count{font-size:11px;color:#64748b;background:#f1f5f9;border-radius:20px;padding:2px 10px;font-weight:600}
		.aj-res-list{display:flex;flex-direction:column;gap:8px}
		.aj-res-list-item{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;transition:box-shadow .15s;border-left:4px solid #e2e8f0}
		.aj-res-list-item.status-confirmed,.aj-res-list-item.status-paid,.aj-res-list-item.status-paid_pending_calendar{border-left-color:#22c55e}
		.aj-res-list-item.status-pending_payment{border-left-color:#f59e0b}
		.aj-res-list-item.status-cancelled{border-left-color:#ef4444;opacity:.72}
		.aj-res-list-item:hover{box-shadow:0 4px 14px rgba(0,0,0,.07)}
		.aj-res-list-date-block{flex-shrink:0;text-align:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;min-width:52px}
		.aj-res-list-month{font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.05em;line-height:1}
		.aj-res-list-day{font-size:26px;font-weight:800;color:#1e293b;line-height:1.1}
		.aj-res-list-info{flex:1;min-width:0}
		.aj-res-list-time{font-size:14px;font-weight:600;color:#1e293b}
		.aj-res-list-meta{font-size:11px;color:#94a3b8;margin-top:2px}
		.aj-res-list-rate{font-size:12px;color:#475569;margin-top:2px}
		.aj-res-list-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0}
		.aj-portal-status-badge{display:inline-block;border-radius:999px;padding:3px 11px;font-size:11px;font-weight:700;white-space:nowrap}
		.aj-status-good{background:#dcfce7;color:#166534}
		.aj-status-warn{background:#fef3c7;color:#92400e}
		.aj-status-bad{background:#fee2e2;color:#991b1b}
		/* ── FullCalendar chrome ─────────────────────────────────────── */
		.aj-res-fc-wrap{border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:16px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.04)}
		.aj-res-fc-wrap .fc{padding:12px}
		.fc .fc-toolbar{margin-bottom:10px!important}
		.fc .fc-toolbar-title{font-size:17px!important;font-weight:700}
		.fc .fc-button{font-size:13px!important;padding:5px 12px!important;font-weight:600!important}
		.fc .fc-button-group .fc-button{font-size:13px!important;padding:5px 10px!important}
		.fc .fc-timegrid-slot{height:2.2em}
		.fc .fc-timegrid-slot-label{font-size:12px;color:#64748b}
		.fc .fc-col-header-cell-cushion{font-size:13px;font-weight:700}
		.fc .fc-highlight{background:#dbeafe!important;opacity:.85}
		.fc .fc-non-business{background:rgba(241,245,249,.6)}
		.fc-direction-ltr .fc-timegrid-col-events{margin:0}
		/* ── Booking modal ───────────────────────────────────────────── */
		.aj-res-modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:99998;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
		.aj-res-modal-box{background:#fff;border-radius:18px;padding:26px 26px 22px;max-width:460px;width:92%;max-height:92vh;overflow-y:auto;box-shadow:0 30px 80px rgba(0,0,0,.28);position:relative;animation:aj-modal-in .22s ease}
		@keyframes aj-modal-in{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}
		.aj-res-modal-close{position:absolute;top:15px;right:16px;background:none;border:none;font-size:18px;cursor:pointer;color:#94a3b8;line-height:1;padding:4px 6px;border-radius:6px;transition:color .12s,background .12s}
		.aj-res-modal-close:hover{color:#0f172a;background:#f1f5f9}
		.aj-res-modal-box h3{margin:0 0 16px;font-size:18px;font-weight:800;color:#0f172a}
		.aj-res-booking-summary{background:linear-gradient(135deg,#f0f4ff,#fafbff);border:1px solid #c7d4f5;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px}
		.aj-res-booking-summary p{margin:4px 0;color:#334155}
		.aj-res-modal-box label{display:block;margin-bottom:14px;font-size:13px;font-weight:600;color:#334155}
		.aj-res-modal-box label span{display:block;margin-bottom:5px}
		.aj-res-modal-box input,.aj-res-modal-box textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 12px;font-size:14px;box-sizing:border-box;transition:border-color .15s,box-shadow .15s}
		.aj-res-modal-box input:focus,.aj-res-modal-box textarea:focus{border-color:#3157ff;outline:none;box-shadow:0 0 0 3px rgba(49,87,255,.12)}
		.aj-res-pay-button{width:100%!important;padding:11px!important;font-size:14px!important;font-weight:700!important;background:linear-gradient(135deg,#3157ff,#7c3aed)!important;border:none!important;border-radius:10px!important;color:#fff!important;cursor:pointer;transition:opacity .15s}
		.aj-res-pay-button:hover:not(:disabled){opacity:.91}
		.aj-res-pay-button:disabled{opacity:.55;cursor:not-allowed}
		.aj-res-spinner{display:none;font-size:13px;color:#64748b;margin-top:8px;text-align:center}
		.aj-res-error-msg{display:none;color:#991b1b;font-size:13px;margin-top:8px;background:#fee2e2;border-radius:8px;padding:8px 12px}
		.aj-res-success-msg{background:#dcfce7;border:1px solid #86efac;border-radius:10px;padding:14px 16px;color:#166534;font-size:14px;margin-top:10px;text-align:center}
		@media(max-width:640px){
			.aj-res-fc-wrap .fc{padding:6px}
			.fc .fc-toolbar{flex-wrap:wrap;gap:6px}
			.aj-res-topbar{flex-direction:column}
			.aj-res-topbar-right{flex-wrap:wrap}
			.aj-res-infobar{flex-direction:column;align-items:flex-start}
			.aj-res-cart-header{flex-direction:column;align-items:flex-start}
		}
		</style>
		<section class="aj-customer-portal-panel aj-reservations-panel">

			<!-- ── Page header ───────────────────────────────────────────── -->
			<div class="aj-res-topbar">
				<div class="aj-res-topbar-left">
					<h2 class="aj-res-page-title"><?php echo esc_html( $resource_name ); ?></h2>
					<p class="aj-res-page-sub">University Place Office Suites &mdash; 1914 J N Pease Pl, Charlotte, NC 28262</p>
				</div>
				<div class="aj-res-topbar-right">
					<span class="aj-res-rate-pill"><?php echo esc_html( $business_hours_label ); ?> &mdash; $<?php echo esc_html( $settings['reservation_business_hours_rate'] ?? '40' ); ?>/hr<small><?php esc_html_e( 'Mon–Fri 9am–5pm', 'ajforms' ); ?></small></span>
					<span class="aj-res-rate-pill"><?php echo esc_html( $after_hours_label ); ?> &mdash; $<?php echo esc_html( $settings['reservation_after_hours_rate'] ?? '80' ); ?>/hr<small><?php esc_html_e( 'Evenings &amp; Weekends', 'ajforms' ); ?></small></span>
					<button type="button" id="aj-res-billing-portal-btn" class="aj-res-billing-btn"
						data-portal-nonce="<?php echo esc_attr( $portal_nonce ); ?>">
						<?php esc_html_e( 'Manage Payment Methods', 'ajforms' ); ?>
					</button>
					<span id="aj-res-billing-err" class="aj-res-billing-err" style="display:none"></span>
				</div>
			</div>

			<!-- ── Info bar ───────────────────────────────────────────────── -->
			<div class="aj-res-infobar">
				<div class="aj-res-legend">
					<span><span class="aj-res-legend-dot red"></span><?php esc_html_e( 'Already Booked', 'ajforms' ); ?></span>
					<span><span class="aj-res-legend-dot amber"></span><?php esc_html_e( 'Unavailable', 'ajforms' ); ?></span>
				</div>
				<div class="aj-res-notices">
					<p class="aj-res-policy"><?php esc_html_e( 'No cancellations, no rescheduling — reservations are final.', 'ajforms' ); ?></p>
					<p class="aj-res-voffice"><?php esc_html_e( 'Virtual Office Clients get 2 free hours yearly.', 'ajforms' ); ?></p>
				</div>
			</div>

			<!-- My Reservations — shown only when the customer has bookings -->
			<?php if ( ! empty( $my_reservations ) ) : ?>
			<div class="aj-res-my-section">
				<div class="aj-res-section-header">
					<h3 class="aj-res-section-title"><?php esc_html_e( 'My Reservations', 'ajforms' ); ?></h3>
					<span class="aj-res-res-count"><?php echo count( $my_reservations ); ?> reservation<?php echo count( $my_reservations ) !== 1 ? 's' : ''; ?></span>
				</div>
				<div class="aj-res-list">
					<?php foreach ( $my_reservations as $res ) :
						$res          = (array) $res;
						$res_ref      = class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::generate_friendly_reference( (int) $res['id'] ) : ( 'RES-' . $res['id'] );
						$start_dt     = new DateTime( $res['start_at'], new DateTimeZone( 'UTC' ) );
						$end_dt       = new DateTime( $res['end_at'], new DateTimeZone( 'UTC' ) );
						$tz_obj       = new DateTimeZone( $timezone );
						$start_dt->setTimezone( $tz_obj );
						$end_dt->setTimezone( $tz_obj );
						$status_label  = class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::get_reservation_status_label( $res['status'] ) : ucfirst( $res['status'] );
						$status_class  = class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::get_reservation_status_class( $res['status'] ) : 'warn';
						$pricing_label = class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::get_pricing_type_label( $res['pricing_type'], $settings ) : $res['pricing_type'];
						?>
						<div class="aj-res-list-item status-<?php echo esc_attr( $res['status'] ); ?>">
							<div class="aj-res-list-date-block">
								<div class="aj-res-list-month"><?php echo esc_html( $start_dt->format( 'M' ) ); ?></div>
								<div class="aj-res-list-day"><?php echo esc_html( $start_dt->format( 'j' ) ); ?></div>
							</div>
							<div class="aj-res-list-info">
								<div class="aj-res-list-time"><?php echo esc_html( $start_dt->format( 'g:i A' ) . ' – ' . $end_dt->format( 'g:i A T' ) ); ?></div>
								<div class="aj-res-list-meta"><?php echo esc_html( $res_ref ); ?> &middot; <?php echo esc_html( $start_dt->format( 'l, F j, Y' ) ); ?></div>
								<div class="aj-res-list-rate"><?php echo esc_html( $pricing_label ); ?></div>
							</div>
							<div class="aj-res-list-right">
								<span class="aj-portal-status-badge aj-status-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- Reservation Cart — always visible, empty state shown by JS -->
			<div id="aj-res-cart-wrap" class="aj-res-cart-panel">
				<div class="aj-res-cart-header">
					<div class="aj-res-cart-title-row">
						<h3 class="aj-res-cart-title-text"><?php esc_html_e( 'Your Cart', 'ajforms' ); ?></h3>
						<span class="aj-res-cart-badge" id="aj-res-cart-badge">0</span>
					</div>
					<button type="button" id="aj-res-cart-checkout-btn" class="button aj-res-checkout-btn" style="display:none"
						data-checkout-nonce="<?php echo esc_attr( $cart_chkout_nonce ); ?>">
						<?php esc_html_e( 'Checkout', 'ajforms' ); ?> &rarr;
					</button>
				</div>
				<div id="aj-res-cart-items"></div>
				<p id="aj-res-cart-msg" class="aj-res-cart-msg"></p>
			</div>

			<div class="aj-res-fc-wrap">
				<div id="aj-res-fullcalendar"
					data-timezone="<?php echo esc_attr( $timezone ); ?>"
					data-resource-key="<?php echo esc_attr( $resource_key ); ?>"
					data-check-nonce="<?php echo esc_attr( $check_nonce ); ?>"
					data-checkout-nonce="<?php echo esc_attr( $chkout_nonce ); ?>"
					data-cart-nonce="<?php echo esc_attr( $cart_nonce ); ?>"
					data-remove-nonce="<?php echo esc_attr( $remove_nonce ); ?>"
					data-publishable-key="<?php echo esc_attr( $pub_key ); ?>"
					data-business-label="<?php echo esc_attr( $business_hours_label ); ?>"
					data-after-label="<?php echo esc_attr( $after_hours_label ); ?>"
					data-biz-rate="<?php echo esc_attr( $settings['reservation_business_hours_rate'] ?? '40' ); ?>"
					data-after-rate="<?php echo esc_attr( $settings['reservation_after_hours_rate'] ?? '80' ); ?>"
					data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
				></div>
			</div>

			<!-- Booking modal -->
			<div class="aj-res-modal-overlay" id="aj-res-modal-overlay" aria-modal="true" role="dialog">
				<div class="aj-res-modal-box">
					<button class="aj-res-modal-close" id="aj-res-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ajforms' ); ?>">&#x2715;</button>
					<h3><?php esc_html_e( 'New Reservation', 'ajforms' ); ?></h3>
					<div class="aj-res-booking-summary" id="aj-res-booking-summary"></div>
					<label>
						<span><?php esc_html_e( 'Your Name', 'ajforms' ); ?></span>
						<input type="text" id="aj-res-field-name" autocomplete="name" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Your Email', 'ajforms' ); ?></span>
						<input type="email" id="aj-res-field-email" autocomplete="email" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Phone', 'ajforms' ); ?></span>
						<input type="tel" id="aj-res-field-phone" autocomplete="tel" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Notes (optional)', 'ajforms' ); ?></span>
						<textarea id="aj-res-field-notes" rows="3"></textarea>
					</label>
					<button type="button" class="button button-primary aj-res-pay-button" id="aj-res-pay-button"><?php esc_html_e( 'Add to Cart', 'ajforms' ); ?></button>
					<span class="aj-res-spinner" id="aj-res-spinner"><?php esc_html_e( 'Adding to cart…', 'ajforms' ); ?></span>
					<p class="aj-res-error-msg" id="aj-res-error-msg"></p>
					<div class="aj-res-success-msg" id="aj-res-success-msg" style="display:none"></div>
				</div>
			</div>

		</section>
		<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
		<script>
		(function() {
			var calEl       = document.getElementById('aj-res-fullcalendar');
			var overlay     = document.getElementById('aj-res-modal-overlay');
			var closeBtn    = document.getElementById('aj-res-modal-close');
			var summary     = document.getElementById('aj-res-booking-summary');
			var payBtn      = document.getElementById('aj-res-pay-button');
			var spinner     = document.getElementById('aj-res-spinner');
			var errMsg      = document.getElementById('aj-res-error-msg');
			var successMsg  = document.getElementById('aj-res-success-msg');
			var nameField   = document.getElementById('aj-res-field-name');
			var emailField  = document.getElementById('aj-res-field-email');
			var phoneField  = document.getElementById('aj-res-field-phone');
			var notesField  = document.getElementById('aj-res-field-notes');

			if (!calEl || typeof FullCalendar === 'undefined') return;

			var tz            = calEl.dataset.timezone;
			var resourceKey   = calEl.dataset.resourceKey;
			var checkNonce    = calEl.dataset.checkNonce;
			var checkoutNonce = calEl.dataset.checkoutNonce;
			var cartNonce     = calEl.dataset.cartNonce;
			var removeNonce   = calEl.dataset.removeNonce;
			var bizLabel      = calEl.dataset.businessLabel;
			var afterLabel    = calEl.dataset.afterLabel;
			var bizRate       = parseInt(calEl.dataset.bizRate, 10) || 40;
			var afterRate     = parseInt(calEl.dataset.afterRate, 10) || 80;
			var ajaxUrl       = calEl.dataset.ajaxUrl;

			var selectedStartStr = null;
			var selectedEndStr   = null;

			// ── FullCalendar ────────────────────────────────────────────
			var isSmallScreen = window.matchMedia && window.matchMedia('(max-width: 760px)').matches;
			// Full Sun–Sat week by default: weekends are bookable (after-hours rate),
			// so the Mon–Fri "Work Week" view must not be the default — it hides
			// weekend bookings and makes the portal disagree with the Zoho calendar.
			var calendar = new FullCalendar.Calendar(calEl, {
				timeZone:       tz,
				initialView:    isSmallScreen ? 'timeGridDay' : 'timeGridWeek',
				firstDay:       0,
				height:         'auto',
				headerToolbar: isSmallScreen ? {
					left:   'prev,next',
					center: 'title',
					right:  'timeGridDay,timeGridWeek,dayGridMonth'
				} : {
					left:   'prev,next today',
					center: 'title',
					right:  'dayGridMonth,timeGridWeek,timeGridWorkWeek,timeGridDay'
				},
				views: {
					timeGridWorkWeek: {
						type:       'timeGrid',
						duration:   { weeks: 1 },
						hiddenDays: [0, 6],
						buttonText: 'Work Week'
					}
				},
				slotMinTime:     '08:00:00',
				slotMaxTime:     '22:00:00',
				slotDuration:    '01:00:00',
				snapDuration:    '01:00:00',
				slotLabelFormat: { hour: 'numeric', minute: '2-digit', omitZeroMinute: true, meridiem: 'short' },
				allDaySlot:     false,
				nowIndicator:   true,
				businessHours: { daysOfWeek: [1, 2, 3, 4, 5], startTime: '09:00', endTime: '17:00' },
				selectable:     true,
				selectMirror:   true,
				selectOverlap:  false,
				selectAllow: function(info) {
					var now    = new Date();
					if (info.start <= now) return false;
					var startH = getLocalHour(info.startStr);
					var endH   = getLocalHour(info.endStr);
					var startM = getLocalMin(info.startStr);
					var endM   = getLocalMin(info.endStr);
					if (startM !== 0 || endM !== 0) return false;
					if (startH < 8 || endH > 22) return false;
					var durMs = info.end - info.start;
					if (durMs < 3600000 || durMs > 50400000) return false;
					return true;
				},
				validRange: { start: new Date().toISOString().split('T')[0] },
				events: function(fetchInfo, successCb, failureCb) {
					fetch(ajaxUrl + '?' + new URLSearchParams({
						action: 'ajcore_get_reservation_events',
						nonce:  checkNonce,
						start:  fetchInfo.startStr,
						end:    fetchInfo.endStr
					}), { credentials: 'same-origin' })
					.then(function(r) { return r.json(); })
					.then(successCb)
					.catch(failureCb);
				},
				displayEventEnd: true,
				eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
				eventContent: function(arg) {
					var wrap = document.createElement('div');
					wrap.style.cssText = 'padding:4px 7px;overflow:hidden;font-family:inherit;line-height:1.3;';
					var label = document.createElement('div');
					label.style.cssText = 'font-size:12px;font-weight:800;letter-spacing:-.01em;text-transform:uppercase;';
					label.textContent = arg.event.title || '<?php echo esc_js( __( 'Unavailable', 'ajforms' ) ); ?>';
					wrap.appendChild(label);
					if (arg.timeText) {
						var time = document.createElement('div');
						time.style.cssText = 'font-size:12px;font-weight:700;opacity:.9;white-space:nowrap;';
						time.textContent = arg.timeText;
						wrap.appendChild(time);
					}
					return { domNodes: [wrap] };
				},
				eventDidMount: function(info) {
					info.el.style.cursor = 'not-allowed';
					var tipTime = '';
					try {
						var fmt = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
						if (info.event.start && info.event.end) {
							tipTime = ' ' + fmt.format(info.event.start) + ' – ' + fmt.format(info.event.end);
						}
					} catch (e) {}
					info.el.title = (info.event.title || '<?php echo esc_js( __( 'Unavailable', 'ajforms' ) ); ?>') + tipTime;
				},
				dateClick: function(info) {
					if (calendar.view.type === 'dayGridMonth') {
						calendar.changeView('timeGridDay', info.dateStr);
					}
				},
				select: function(info) {
					openModal(info.startStr, info.endStr, info.start, info.end);
					calendar.unselect();
				}
			});
			calendar.render();

			// ── Modal ───────────────────────────────────────────────────
			var pendingIsBiz = false;

			function openModal(startStr, endStr, startDate, endDate) {
				selectedStartStr = startStr;
				selectedEndStr   = endStr;
				var hours    = Math.round((endDate - startDate) / 3600000);
				var startFmt = isoTimeLabel(startStr);
				var endFmt   = isoTimeLabel(endStr);
				var dateFmt  = isoDateLabel(startStr);
				var startH   = getLocalHour(startStr);
				var startDow = startDate.getDay();
				var isBiz    = (startDow >= 1 && startDow <= 5) && (startH >= 9 && startH < 17);
				pendingIsBiz = isBiz;
				var rate     = isBiz ? bizRate : afterRate;
				var total    = hours * rate;
				summary.innerHTML =
					'<p><strong><?php echo esc_js( __( 'Date', 'ajforms' ) ); ?>:</strong> ' + escH(dateFmt) + '</p>' +
					'<p><strong><?php echo esc_js( __( 'Time', 'ajforms' ) ); ?>:</strong> ' + escH(startFmt) + ' &ndash; ' + escH(endFmt) + '</p>' +
					'<p><strong><?php echo esc_js( __( 'Duration', 'ajforms' ) ); ?>:</strong> ' + hours + (hours === 1 ? ' <?php echo esc_js( __( 'hour', 'ajforms' ) ); ?>' : ' <?php echo esc_js( __( 'hours', 'ajforms' ) ); ?>') + '</p>' +
					'<p><strong><?php echo esc_js( __( 'Rate', 'ajforms' ) ); ?>:</strong> $' + rate + '/hr &mdash; ' + escH(isBiz ? bizLabel : afterLabel) + '</p>' +
					'<p><strong><?php echo esc_js( __( 'Total', 'ajforms' ) ); ?>:</strong> <span style="font-size:16px;color:#166534">$' + total + '</span></p>';
				errMsg.style.display    = 'none';
				errMsg.textContent      = '';
				successMsg.style.display = 'none';
				successMsg.textContent  = '';
				nameField.value    = '';
				emailField.value   = '';
				phoneField.value   = '';
				notesField.value   = '';
				payBtn.disabled    = false;
				payBtn.style.display = 'block';
				spinner.style.display = 'none';
				overlay.style.display = 'flex';
				document.body.style.overflow = 'hidden';
				setTimeout(function() { nameField.focus(); }, 50);
			}

			function closeModal() {
				overlay.style.display = 'none';
				document.body.style.overflow = '';
				selectedStartStr = selectedEndStr = null;
			}

			if (closeBtn) closeBtn.addEventListener('click', closeModal);
			overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
			document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && overlay.style.display !== 'none') closeModal(); });

			// ── Add to Cart ─────────────────────────────────────────────
			if (payBtn) {
				payBtn.addEventListener('click', function() {
					var name  = nameField.value.trim();
					var email = emailField.value.trim();
					var phone = phoneField.value.trim();
					var notes = notesField.value.trim();
					errMsg.style.display = 'none';
					if (!selectedStartStr || !selectedEndStr) {
						errMsg.textContent = '<?php echo esc_js( __( 'Please select a time slot first.', 'ajforms' ) ); ?>';
						errMsg.style.display = 'block';
						return;
					}
					if (!name || !email || !phone) {
						errMsg.textContent = '<?php echo esc_js( __( 'Please enter your name, email, and phone.', 'ajforms' ) ); ?>';
						errMsg.style.display = 'block';
						return;
					}
					payBtn.disabled = true;
					payBtn.style.display = 'none';
					spinner.style.display = 'block';
					var fd = new FormData();
					fd.append('action',         'ajcore_reservation_add_to_cart');
					fd.append('nonce',          cartNonce);
					fd.append('resource_key',   resourceKey);
					fd.append('start_at',       selectedStartStr);
					fd.append('end_at',         selectedEndStr);
					fd.append('customer_name',  name);
					fd.append('customer_email', email);
					fd.append('customer_phone', phone);
					fd.append('customer_notes', notes);
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(payload) {
						spinner.style.display = 'none';
						payBtn.disabled = false;
						payBtn.style.display = 'block';
						if (!payload.success) {
							errMsg.textContent = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Unable to add to cart. Please try again.', 'ajforms' ) ); ?>';
							errMsg.style.display = 'block';
							return;
						}
						closeModal();
						loadCart();
					})
					.catch(function() {
						spinner.style.display = 'none';
						payBtn.disabled = false;
						payBtn.style.display = 'block';
						errMsg.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'ajforms' ) ); ?>';
						errMsg.style.display = 'block';
					});
				});
			}

			// ── Cart functions ───────────────────────────────────────────
			function loadCart() {
				fetch(ajaxUrl + '?' + new URLSearchParams({action: 'ajcore_reservation_get_cart', nonce: cartNonce}), {credentials: 'same-origin'})
				.then(function(r) { return r.json(); })
				.then(function(payload) {
					var items = (payload.success && payload.data && payload.data.items) ? payload.data.items : [];
					renderCart(items);
				})
				.catch(function() { /* silently fail cart load */ });
			}

			function renderCart(items) {
				var cartWrap    = document.getElementById('aj-res-cart-wrap');
				var cartItems   = document.getElementById('aj-res-cart-items');
				var badge       = document.getElementById('aj-res-cart-badge');
				var checkoutBtn = document.getElementById('aj-res-cart-checkout-btn');
				if (!cartWrap || !cartItems) return;

				var count = (items && items.length) ? items.length : 0;

				// Animate badge on count increase
				if (badge) {
					var prevCount = parseInt(badge.textContent, 10) || 0;
					badge.textContent = count;
					if (count > prevCount) {
						badge.classList.remove('pulse');
						void badge.offsetWidth;
						badge.classList.add('pulse');
					}
					badge.className = 'aj-res-cart-badge' + (count > 0 ? ' has-items' : '');
					if (count > prevCount) badge.classList.add('pulse');
				}

				// Show/hide checkout button
				if (checkoutBtn) checkoutBtn.style.display = count > 0 ? 'inline-flex' : 'none';

				// Toggle panel highlight
				cartWrap.classList.toggle('has-items', count > 0);

				if (count === 0) {
					cartItems.innerHTML =
						'<div class="aj-res-cart-empty">' +
							'<div class="aj-res-cart-empty-icon">&#128722;</div>' +
							'<div class="aj-res-cart-empty-text"><?php echo esc_js( __( 'Your cart is empty — select a time slot on the calendar below to add a reservation.', 'ajforms' ) ); ?></div>' +
						'</div>';
					return;
				}

				var html = '';
				var grandTotal = 0;
				for (var i = 0; i < items.length; i++) {
					var it = items[i];
					grandTotal += (it.total || 0);
					html += '<div class="aj-res-cart-item">' +
						'<div class="aj-res-cart-item-info">' +
							'<div class="aj-res-cart-item-date">' + escH(it.date || '') + '</div>' +
							'<div class="aj-res-cart-item-time">' + escH(it.time || '') + '</div>' +
							'<div class="aj-res-cart-item-rate">' + escH(it.label || '') + ' &mdash; ' + (it.hours || 0) + ' hr &times; $' + (it.rate || 0) + '/hr</div>' +
						'</div>' +
						'<div style="display:flex;align-items:center">' +
							'<span class="aj-res-cart-item-price">$' + (it.total || 0) + '</span>' +
							'<button type="button" class="aj-res-cart-remove" data-uuid="' + escH(it.uuid || '') + '" title="<?php echo esc_js( __( 'Remove', 'ajforms' ) ); ?>">&#x2715;</button>' +
						'</div>' +
					'</div>';
				}
				html += '<div class="aj-res-cart-footer">' +
					'<span class="aj-res-cart-total-label"><?php echo esc_js( __( 'Total', 'ajforms' ) ); ?>: <span class="aj-res-cart-total-amount">$' + grandTotal + '</span></span>' +
				'</div>';
				cartItems.innerHTML = html;

				// Attach remove handlers
				var removeBtns = cartItems.querySelectorAll('.aj-res-cart-remove');
				for (var j = 0; j < removeBtns.length; j++) {
					removeBtns[j].addEventListener('click', function() {
						var uuid = this.dataset.uuid;
						this.disabled = true;
						var fd = new FormData();
						fd.append('action',            'ajcore_reservation_cart_remove');
						fd.append('nonce',             removeNonce);
						fd.append('reservation_uuid',  uuid);
						fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
						.then(function(r) { return r.json(); })
						.then(function(payload) {
							var newItems = (payload.success && payload.data && payload.data.items) ? payload.data.items : [];
							renderCart(newItems);
						})
						.catch(function() { loadCart(); });
					});
				}
			}

			// ── Cart Checkout button ─────────────────────────────────────
			var cartCheckoutBtn = document.getElementById('aj-res-cart-checkout-btn');
			if (cartCheckoutBtn) {
				var cartCheckoutNonce = cartCheckoutBtn.dataset.checkoutNonce;
				cartCheckoutBtn.addEventListener('click', function() {
					cartCheckoutBtn.disabled = true;
					var cartMsg = document.getElementById('aj-res-cart-msg');
					if (cartMsg) cartMsg.style.display = 'none';
					var fd = new FormData();
					fd.append('action', 'ajcore_reservation_cart_checkout');
					fd.append('nonce',  cartCheckoutNonce);
					fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
					.then(function(r) { return r.json(); })
					.then(function(payload) {
						cartCheckoutBtn.disabled = false;
						if (!payload.success) {
							if (cartMsg) {
								cartMsg.textContent = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Checkout failed.', 'ajforms' ) ); ?>';
								cartMsg.style.display = 'block';
							}
							return;
						}
						window.location.href = payload.data.checkout_url;
					})
					.catch(function() {
						cartCheckoutBtn.disabled = false;
						if (cartMsg) {
							cartMsg.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'ajforms' ) ); ?>';
							cartMsg.style.display = 'block';
						}
					});
				});
			}

			// ── Manage Payment Methods (Stripe Customer Portal) ──────────
			var billingBtn     = document.getElementById('aj-res-billing-portal-btn');
			var billingErrSpan = document.getElementById('aj-res-billing-err');
			if (billingBtn) {
				var portalNonceVal = billingBtn.dataset.portalNonce;
				billingBtn.addEventListener('click', function() {
					billingBtn.disabled = true;
					billingBtn.textContent = '<?php echo esc_js( __( 'Opening…', 'ajforms' ) ); ?>';
					if (billingErrSpan) { billingErrSpan.style.display = 'none'; billingErrSpan.textContent = ''; }
					var fd = new FormData();
					fd.append('action', 'ajcore_stripe_customer_portal');
					fd.append('nonce',  portalNonceVal);
					fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd})
					.then(function(r) { return r.json(); })
					.then(function(payload) {
						billingBtn.disabled = false;
						billingBtn.textContent = '<?php echo esc_js( __( 'Manage Payment Methods', 'ajforms' ) ); ?>';
						if (payload.success && payload.data && payload.data.portal_url) {
							window.location.href = payload.data.portal_url;
						} else {
							var msg = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Could not open billing portal. Please try again.', 'ajforms' ) ); ?>';
							if (billingErrSpan) { billingErrSpan.textContent = msg; billingErrSpan.style.display = 'inline'; }
						}
					})
					.catch(function() {
						billingBtn.disabled = false;
						billingBtn.textContent = '<?php echo esc_js( __( 'Manage Payment Methods', 'ajforms' ) ); ?>';
						if (billingErrSpan) { billingErrSpan.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'ajforms' ) ); ?>'; billingErrSpan.style.display = 'inline'; }
					});
				});
			}

			// Load cart on page load.
			loadCart();

	// ── Helpers ─────────────────────────────────────────────────
	function getLocalHour(iso) {
		var t = iso.replace(/([+-]\d{2}:?\d{2}|Z)$/, '').split('T')[1] || '00:00:00';
		return parseInt(t.split(':')[0], 10);
	}
	function getLocalMin(iso) {
		var t = iso.replace(/([+-]\d{2}:?\d{2}|Z)$/, '').split('T')[1] || '00:00:00';
		return parseInt(t.split(':')[1] || '0', 10);
	}
	function isoTimeLabel(iso) {
		var h    = getLocalHour(iso);
		var ampm = h < 12 ? 'AM' : 'PM';
		var h12  = h % 12 === 0 ? 12 : h % 12;
		return h12 + ':00 ' + ampm;
	}
	function isoDateLabel(iso) {
		var d    = iso.replace(/([+-]\d{2}:?\d{2}|Z)$/, '').split('T')[0];
		var pts  = d.split('-');
		var y = parseInt(pts[0], 10), m = parseInt(pts[1], 10) - 1, day = parseInt(pts[2], 10);
		var months   = ['January','February','March','April','May','June','July','August','September','October','November','December'];
		var weekdays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
		var dow = new Date(y, m, day).getDay();
		return weekdays[dow] + ', ' + months[m] + ' ' + day + ', ' + y;
	}
	function escH(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(str));
		return d.innerHTML;
	}
	})();

	// "New Reservation" button — smooth-scrolls to the calendar
	(function(){
	})();
	</script>
	<?php
	return ob_get_clean();
}

	// =========================================================================
	// Reservation AJAX Handlers
	// =========================================================================

	private function parse_reservation_datetime_to_utc( $datetime_raw, $timezone ) {
		$timezone = ! empty( $timezone ) ? $timezone : 'America/New_York';
		$tz       = new DateTimeZone( $timezone );

		if ( preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', (string) $datetime_raw ) ) {
			$dt = new DateTime( (string) $datetime_raw );
		} else {
			$dt = new DateTime( (string) $datetime_raw, $tz );
		}

		$dt->setTimezone( new DateTimeZone( 'UTC' ) );
		return $dt;
	}

	private function get_reservation_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings ) {
		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		$window_check = AJCore_Reservations::validate_booking_window( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $window_check ) ) {
			return array(
				'available' => false,
				'message'   => $window_check->get_error_message(),
			);
		}

		$conflict = AJCore_Reservations::check_local_conflict( (int) $resource->id, $start_at_utc, $end_at_utc, '' );
		if ( is_wp_error( $conflict ) ) {
			return array(
				'available' => false,
				'message'   => $conflict->get_error_message(),
			);
		}

		// Auto-refresh: Zoho access tokens expire hourly; a stale token here 401s and
		// either blocks all bookings (strict) or lets busy slots book (lenient).
		$zoho_api_token   = class_exists( 'AJCore_Zoho_Calendar' ) ? AJCore_Zoho_Calendar::get_valid_token( $settings ) : '';
		$zoho_calendar_uid = ! empty( $settings['zoho_calendar_uid'] ) ? $settings['zoho_calendar_uid'] : '';
		$zoho_resource_uid = ! empty( $settings['zoho_resource_uid'] ) ? $settings['zoho_resource_uid'] : '';
		$zoho_freebusy_url = ! empty( $settings['zoho_resource_freebusy_url'] ) ? $settings['zoho_resource_freebusy_url'] : '';
		$failure_mode      = ! empty( $settings['zoho_availability_failure_mode'] ) ? $settings['zoho_availability_failure_mode'] : 'strict';

		if ( $zoho_api_token && $zoho_calendar_uid && class_exists( 'AJCore_Zoho_Calendar' ) ) {
			$calendar_check = AJCore_Zoho_Calendar::check_zoho_calendar_events_availability(
				$zoho_calendar_uid,
				$start_dt->format( 'c' ),
				$end_dt->format( 'c' ),
				$timezone,
				$zoho_api_token
			);
			if ( is_wp_error( $calendar_check ) ) {
				if ( 'lenient' !== $failure_mode ) {
					return array(
						'available' => false,
						'message'   => __( 'Could not verify calendar availability. Please try again or contact support.', 'ajforms' ),
					);
				}
				AJCore_Reservations::log_reservation_event( 'reservation_zoho_check_failed', array(
					'severity' => 'warning',
					'error'    => $calendar_check->get_error_message(),
					'mode'     => 'lenient',
				) );
			} elseif ( isset( $calendar_check['is_free'] ) && ! (bool) $calendar_check['is_free'] ) {
				return array(
					'available' => false,
					'message'   => __( 'This time slot is already booked on the calendar. Please choose another time.', 'ajforms' ),
				);
			}
		} elseif ( $zoho_api_token && $zoho_resource_uid && $zoho_freebusy_url && class_exists( 'AJCore_Zoho_Calendar' ) ) {
			$freebusy = AJCore_Zoho_Calendar::check_zoho_resource_freebusy(
				$zoho_resource_uid,
				$zoho_freebusy_url,
				$start_dt->format( 'c' ),
				$end_dt->format( 'c' ),
				$zoho_api_token
			);
			if ( is_wp_error( $freebusy ) ) {
				if ( 'lenient' !== $failure_mode ) {
					return array(
						'available' => false,
						'message'   => __( 'Could not verify calendar availability. Please try again or contact support.', 'ajforms' ),
					);
				}
				AJCore_Reservations::log_reservation_event( 'reservation_zoho_check_failed', array(
					'severity' => 'warning',
					'error'    => $freebusy->get_error_message(),
					'mode'     => 'lenient',
				) );
			} elseif ( isset( $freebusy['is_free'] ) && ! (bool) $freebusy['is_free'] ) {
				return array(
					'available' => false,
					'message'   => __( 'This time slot is already booked on the calendar. Please choose another time.', 'ajforms' ),
				);
			}
		}

		return array(
			'available'    => true,
			'pricing_type' => AJCore_Reservations::determine_pricing_type( $start_at_utc, $timezone ),
		);
	}

	private function validate_reservation_whole_hour_slot( $start_dt, $end_dt, $timezone ) {
		$tz          = new DateTimeZone( ! empty( $timezone ) ? $timezone : 'America/New_York' );
		$start_local = clone $start_dt;
		$end_local   = clone $end_dt;
		$start_local->setTimezone( $tz );
		$end_local->setTimezone( $tz );

		if ( '00' !== $start_local->format( 'i' ) || '00' !== $end_local->format( 'i' ) ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations must start and end on the hour.', 'ajforms' ) );
		}

		$duration_secs = $end_dt->getTimestamp() - $start_dt->getTimestamp();
		if ( $duration_secs < 3600 ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations must be at least one hour.', 'ajforms' ) );
		}
		if ( $duration_secs > 50400 ) { // 14 hours max
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations cannot exceed 14 hours.', 'ajforms' ) );
		}
		if ( 0 !== ( $duration_secs % 3600 ) ) {
			return new WP_Error( 'reservation_invalid_time', __( 'Reservations must be in whole-hour increments.', 'ajforms' ) );
		}

		return true;
	}

	private function get_reservation_price_ids_for_resource( $resource_key ) {
		$pdb = class_exists( 'AJCore_Reservations' ) ? AJCore_Reservations::get_pdb() : $GLOBALS['wpdb'];

		$table = $pdb->prefix . 'aj_portal_product_catalog';
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array(
				'business_hours_price_id' => '',
				'after_hours_price_id'    => '',
			);
		}

		$row = $pdb->get_row(
			$pdb->prepare(
				"SELECT reservation_business_hours_price_id, reservation_after_hours_price_id
				 FROM `{$table}`
				 WHERE product_type = 'reservation'
				 AND reservation_resource_key = %s
				 ORDER BY id DESC
				 LIMIT 1",
				sanitize_key( (string) $resource_key )
			)
		);

		if ( ! $row ) {
			$row = $pdb->get_row(
				"SELECT reservation_business_hours_price_id, reservation_after_hours_price_id
				 FROM `{$table}`
				 WHERE product_type = 'reservation'
				 AND reservation_business_hours_price_id <> ''
				 AND reservation_after_hours_price_id <> ''
				 ORDER BY id DESC
				 LIMIT 1"
			);
		}

		$biz_id   = $row && ! empty( $row->reservation_business_hours_price_id ) ? sanitize_text_field( (string) $row->reservation_business_hours_price_id ) : '';
		$after_id = $row && ! empty( $row->reservation_after_hours_price_id ) ? sanitize_text_field( (string) $row->reservation_after_hours_price_id ) : '';

		// Fall back to ajforms_settings when the product catalog has no configured Price IDs.
		if ( '' === $biz_id || '' === $after_id ) {
			$s = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
			if ( '' === $biz_id && ! empty( $s['reservation_business_hours_price_id'] ) ) {
				$biz_id = sanitize_text_field( (string) $s['reservation_business_hours_price_id'] );
			}
			if ( '' === $after_id && ! empty( $s['reservation_after_hours_price_id'] ) ) {
				$after_id = sanitize_text_field( (string) $s['reservation_after_hours_price_id'] );
			}
		}

		return array(
			'business_hours_price_id' => $biz_id,
			'after_hours_price_id'    => $after_id,
		);
	}

	private function get_configured_reservation_resource( $resource_key, $settings ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return null;
		}

		$pdb = AJCore_Reservations::get_pdb();

		$resource_key = sanitize_key( (string) $resource_key );
		if ( '' === $resource_key ) {
			return null;
		}

		$table = AJCore_Reservations::get_resources_table();
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}

		$resource = AJCore_Reservations::get_resource_by_key( $resource_key );
		$prices   = $this->get_reservation_price_ids_for_resource( $resource_key );

		$data = array(
			'resource_key'            => $resource_key,
			'resource_name'           => ! empty( $settings['reservation_resource_name'] ) ? sanitize_text_field( (string) $settings['reservation_resource_name'] ) : __( 'Conference Room', 'ajforms' ),
			'zoho_calendar_uid'       => ! empty( $settings['zoho_calendar_uid'] ) ? sanitize_text_field( (string) $settings['zoho_calendar_uid'] ) : '',
			'zoho_calendar_id'        => ! empty( $settings['zoho_calendar_id'] ) ? sanitize_text_field( (string) $settings['zoho_calendar_id'] ) : '',
			'zoho_resource_uid'       => ! empty( $settings['zoho_resource_uid'] ) ? sanitize_text_field( (string) $settings['zoho_resource_uid'] ) : '',
			'zoho_schedule_url'       => ! empty( $settings['zoho_schedule_appointment_url'] ) ? esc_url_raw( (string) $settings['zoho_schedule_appointment_url'] ) : '',
			'zoho_freebusy_url'       => ! empty( $settings['zoho_resource_freebusy_url'] ) ? sanitize_text_field( (string) $settings['zoho_resource_freebusy_url'] ) : '',
			'business_hours_price_id' => $prices['business_hours_price_id'],
			'after_hours_price_id'    => $prices['after_hours_price_id'],
			'duration_minutes'        => 60,
			'min_duration_minutes'    => 60,
			'max_duration_minutes'    => 60,
			'active'                  => 1,
			'updated_at'              => current_time( 'mysql' ),
		);

		if ( $resource ) {
			$update = $data;
			if ( empty( $update['business_hours_price_id'] ) && ! empty( $resource->business_hours_price_id ) ) {
				$update['business_hours_price_id'] = $resource->business_hours_price_id;
			}
			if ( empty( $update['after_hours_price_id'] ) && ! empty( $resource->after_hours_price_id ) ) {
				$update['after_hours_price_id'] = $resource->after_hours_price_id;
			}
			unset( $update['resource_key'] );
			$pdb->update(
				$table,
				$update,
				array( 'id' => (int) $resource->id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$pdb->insert(
				$table,
				$data,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}

		return AJCore_Reservations::get_resource_by_key( $resource_key );
	}

	public function ajax_reservation_check_availability() {
		check_ajax_referer( 'ajcore_reservation_check_availability', 'nonce' );

		$resource_key = isset( $_POST['resource_key'] ) ? sanitize_key( wp_unslash( $_POST['resource_key'] ) ) : '';
		$mode         = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'slot';
		$date_raw     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( $_POST['date'] ) ) : '';
		$start_at_raw = isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) ) : '';
		$end_at_raw   = isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : '';

		if ( ! $resource_key || ( 'day' === $mode ? ! $date_raw : ( ! $start_at_raw || ! $end_at_raw ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation system unavailable.', 'ajforms' ) ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		// Get resource.
		$resource = $this->get_configured_reservation_resource( $resource_key, $settings );
		if ( ! $resource ) {
			wp_send_json_error( array( 'message' => __( 'Resource not found.', 'ajforms' ) ) );
		}

		if ( 'day' === $mode ) {
			try {
				$day_dt = new DateTime( $date_raw . ' 00:00:00', new DateTimeZone( $timezone ) );
			} catch ( Exception $e ) {
				wp_send_json_error( array( 'message' => __( 'Invalid date format.', 'ajforms' ) ) );
			}

			$slots = array();
			for ( $hour = AJCore_Reservations::BOOKING_WINDOW_START_HOUR; $hour < AJCore_Reservations::BOOKING_WINDOW_END_HOUR; $hour++ ) {
				$slot_start_local = clone $day_dt;
				$slot_start_local->setTime( $hour, 0, 0 );
				$slot_end_local = clone $slot_start_local;
				$slot_end_local->modify( '+1 hour' );

				$slot_start_utc = clone $slot_start_local;
				$slot_start_utc->setTimezone( new DateTimeZone( 'UTC' ) );
				$slot_end_utc = clone $slot_end_local;
				$slot_end_utc->setTimezone( new DateTimeZone( 'UTC' ) );

				$availability = $this->get_reservation_slot_availability( $resource, $slot_start_utc, $slot_end_utc, $timezone, $settings );
				$slots[] = array(
					'start_at'     => $slot_start_local->format( 'Y-m-d\TH:i:s' ),
					'end_at'       => $slot_end_local->format( 'Y-m-d\TH:i:s' ),
					'available'    => ! empty( $availability['available'] ),
					'pricing_type' => ! empty( $availability['pricing_type'] ) ? $availability['pricing_type'] : '',
					'message'      => ! empty( $availability['message'] ) ? $availability['message'] : '',
				);
			}

			wp_send_json_success( array( 'slots' => $slots ) );
		}

		try {
			$start_dt = $this->parse_reservation_datetime_to_utc( $start_at_raw, $timezone );
			$end_dt   = $this->parse_reservation_datetime_to_utc( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date/time format.', 'ajforms' ) ) );
		}

		$whole_hour_check = $this->validate_reservation_whole_hour_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $whole_hour_check ) ) {
			wp_send_json_error( array( 'message' => $whole_hour_check->get_error_message() ) );
		}

		$availability = $this->get_reservation_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings );
		if ( empty( $availability['available'] ) ) {
			wp_send_json_error( array( 'message' => ! empty( $availability['message'] ) ? $availability['message'] : __( 'This slot is not available.', 'ajforms' ) ) );
		}

		wp_send_json_success( array(
			'available'      => true,
			'pricing_type'   => $availability['pricing_type'],
			'amount_display' => '',
		) );
	}

	public function ajax_reservation_create_checkout() {
		$this->block_impersonated_portal_write();
		check_ajax_referer( 'ajcore_reservation_create_checkout', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to make a reservation.', 'ajforms' ) ) );
		}

		$resource_key    = isset( $_POST['resource_key'] ) ? sanitize_key( wp_unslash( $_POST['resource_key'] ) ) : '';
		$start_at_raw    = isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) ) : '';
		$end_at_raw      = isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : '';
		$customer_name   = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$customer_email  = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$customer_phone  = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
		$customer_notes  = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_notes'] ) ) : '';

		if ( ! $resource_key || ! $start_at_raw || ! $end_at_raw || ! $customer_name || ! $customer_email || ! $customer_phone ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation system unavailable.', 'ajforms' ) ) );
		}

		$settings  = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone  = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$wp_user_id = get_current_user_id();

		try {
			$start_dt = $this->parse_reservation_datetime_to_utc( $start_at_raw, $timezone );
			$end_dt   = $this->parse_reservation_datetime_to_utc( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date/time format.', 'ajforms' ) ) );
		}

		$whole_hour_check = $this->validate_reservation_whole_hour_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $whole_hour_check ) ) {
			wp_send_json_error( array( 'message' => $whole_hour_check->get_error_message() ) );
		}

		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		// Validate booking window.
		$window_check = AJCore_Reservations::validate_booking_window( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $window_check ) ) {
			wp_send_json_error( array( 'message' => $window_check->get_error_message() ) );
		}

		$resource = $this->get_configured_reservation_resource( $resource_key, $settings );
		if ( ! $resource ) {
			wp_send_json_error( array( 'message' => __( 'Resource not found.', 'ajforms' ) ) );
		}

		// Re-check conflict at booking time.
		$conflict = AJCore_Reservations::check_local_conflict( (int) $resource->id, $start_at_utc, $end_at_utc, '' );
		if ( is_wp_error( $conflict ) ) {
			wp_send_json_error( array( 'message' => $conflict->get_error_message() ) );
		}

		$availability = $this->get_reservation_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings );
		if ( empty( $availability['available'] ) ) {
			wp_send_json_error( array( 'message' => ! empty( $availability['message'] ) ? $availability['message'] : __( 'This slot is not available.', 'ajforms' ) ) );
		}

		// Compute full pricing breakdown (handles mixed-rate slots correctly).
		$pricing_breakdown = AJCore_Reservations::calculate_pricing_breakdown( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $pricing_breakdown ) ) {
			wp_send_json_error( array( 'message' => $pricing_breakdown->get_error_message() ) );
		}
		$pricing_type = $pricing_breakdown['pricing_type'];

		// Require configured Stripe Price IDs — do not silently fall back to dynamic price_data.
		$biz_price_id   = ! empty( $resource->business_hours_price_id ) ? (string) $resource->business_hours_price_id : '';
		$after_price_id = ! empty( $resource->after_hours_price_id )    ? (string) $resource->after_hours_price_id    : '';
		if ( '' === $biz_price_id || '' === $after_price_id ) {
			wp_send_json_error( array(
				'message' => __( 'Conference room pricing is not fully configured. Please contact support.', 'ajforms' ),
			) );
		}

		// Build one or two line items based on pricing breakdown (split for mixed-rate reservations).
		$biz_hours_qty   = (int) round( $pricing_breakdown['business_minutes'] / 60 );
		$after_hours_qty = (int) round( $pricing_breakdown['after_hours_minutes'] / 60 );
		$checkout_line_items = array();
		if ( $biz_hours_qty > 0 ) {
			$checkout_line_items[] = array( 'price' => $biz_price_id, 'quantity' => $biz_hours_qty );
		}
		if ( $after_hours_qty > 0 ) {
			$checkout_line_items[] = array( 'price' => $after_price_id, 'quantity' => $after_hours_qty );
		}
		if ( empty( $checkout_line_items ) ) {
			$total_qty = max( 1, (int) round( $pricing_breakdown['total_minutes'] / 60 ) );
			$checkout_line_items[] = array(
				'price'    => 'business_hours' === $pricing_type ? $biz_price_id : $after_price_id,
				'quantity' => $total_qty,
			);
		}

		// Compute estimated amounts for record storage (actual charge comes from Stripe).
		$biz_rate_dollars    = max( 1, (int) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate_dollars  = max( 1, (int) ( $settings['reservation_after_hours_rate'] ?? 80 ) );
		$business_amount     = round( $biz_hours_qty * $biz_rate_dollars, 2 );
		$after_hours_amount  = round( $after_hours_qty * $after_rate_dollars, 2 );
		$total_amount        = $business_amount + $after_hours_amount;

		$stored_notes = trim( sprintf( "Phone: %s\n%s", $customer_phone, $customer_notes ) );

		// Get Stripe customer for logged-in user.
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();

		// Create the pending reservation record.
		$pending = AJCore_Reservations::create_pending_reservation( array(
			'resource_id'       => (int) $resource->id,
			'resource_key'      => sanitize_key( (string) $resource->resource_key ),
			'resource_name'     => sanitize_text_field( (string) $resource->resource_name ),
			'stripe_customer_id'=> $stripe_customer_id,
			'wp_user_id'        => $wp_user_id,
			'start_at'          => $start_at_utc,
			'end_at'            => $end_at_utc,
			'timezone'          => $timezone,
			'pricing_type'      => $pricing_type,
			'stripe_price_id'   => $biz_hours_qty > 0 ? $biz_price_id : $after_price_id,
			'amount'            => $total_amount,
			'currency'          => 'usd',
			'customer_name'     => $customer_name,
			'customer_email'    => $customer_email,
			'customer_notes'    => $stored_notes,
			'zoho_calendar_id'  => ! empty( $settings['zoho_calendar_id'] ) ? $settings['zoho_calendar_id'] : '',
			'zoho_resource_uid' => ! empty( $settings['zoho_resource_uid'] ) ? $settings['zoho_resource_uid'] : '',
			'pricing_breakdown' => array(
				'business_minutes'    => $pricing_breakdown['business_minutes'],
				'after_hours_minutes' => $pricing_breakdown['after_hours_minutes'],
				'total_minutes'       => $pricing_breakdown['total_minutes'],
				'business_amount'     => $business_amount,
				'after_hours_amount'  => $after_hours_amount,
				'total_amount'        => $total_amount,
			),
		) );

		if ( is_wp_error( $pending ) ) {
			wp_send_json_error( array( 'message' => $pending->get_error_message() ) );
		}

		$reservation_uuid = $pending['reservation_uuid'];

		// Build Stripe checkout session.
		$stripe_settings = $this->get_stripe_settings();
		$secret_key      = ! empty( $stripe_settings['secret_key'] ) ? $stripe_settings['secret_key'] : '';

		if ( ! $secret_key ) {
			wp_send_json_error( array( 'message' => __( 'Payment gateway is not configured.', 'ajforms' ) ) );
		}

		$portal_url        = $this->get_customer_portal_url();
		$success_url       = add_query_arg( array( 'portal_tab' => 'reservations', 'res_success' => '1', 'res_uuid' => rawurlencode( $reservation_uuid ) ), $portal_url );
		$cancel_url        = add_query_arg( array( 'portal_tab' => 'reservations', 'res_cancel' => '1' ), $portal_url );

		$duration_hours = (int) round( ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 3600 );
		$duration_hours = max( 1, min( 14, $duration_hours ) );

		$checkout_payload = array(
			'payment_method_types' => array( 'card' ),
			'mode'                 => 'payment',
			'line_items'           => $checkout_line_items,
			'success_url'          => $success_url,
			'cancel_url'           => $cancel_url,
			'metadata'             => array(
				'reservation_uuid'     => $reservation_uuid,
				'ajcore_source'        => 'reservation',
				'resource_key'         => sanitize_key( (string) $resource->resource_key ),
				'reservation_start_at' => $start_at_utc,
				'customer_phone'       => $customer_phone,
			),
		);

		if ( $stripe_customer_id && 0 === strpos( $stripe_customer_id, 'cus_' ) ) {
			$checkout_payload['customer'] = $stripe_customer_id;
		} else {
			$checkout_payload['customer_email'] = $customer_email;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/checkout/sessions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $this->flatten_array_for_stripe( $checkout_payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment gateway error. Please try again.', 'ajforms' ) ) );
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body     = json_decode( wp_remote_retrieve_body( $response ), true );
		$session_id  = ! empty( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		$checkout_url = ! empty( $body['url'] ) ? esc_url_raw( $body['url'] ) : '';

		if ( 200 !== (int) $code || ! $session_id || ! $checkout_url ) {
			$err_msg = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not create checkout session.', 'ajforms' );
			wp_send_json_error( array( 'message' => $err_msg ) );
		}

		// Attach session ID to the pending reservation.
		AJCore_Reservations::attach_stripe_checkout_session( $reservation_uuid, $session_id );

		AJCore_Reservations::log_reservation_event( 'reservation_payment_started', array(
			'reservation_uuid' => $reservation_uuid,
			'stripe_session_id' => $session_id,
			'pricing_type'     => $pricing_type,
			'resource_key'     => $resource_key,
		) );

		wp_send_json_success( array( 'checkout_url' => $checkout_url ) );
	}

	// =========================================================================
	// Cart AJAX Handlers
	// =========================================================================

	/**
	 * Add a reservation slot to the cart (status: in_cart).
	 */
	public function ajax_reservation_add_to_cart() {
		check_ajax_referer( 'ajcore_reservation_add_to_cart', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to make a reservation.', 'ajforms' ) ) );
		}

		$resource_key   = isset( $_POST['resource_key'] ) ? sanitize_key( wp_unslash( $_POST['resource_key'] ) ) : '';
		$start_at_raw   = isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) ) : '';
		$end_at_raw     = isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : '';
		$customer_name  = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$customer_phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) ) : '';
		$customer_notes = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_notes'] ) ) : '';

		if ( ! $resource_key || ! $start_at_raw || ! $end_at_raw || ! $customer_name || ! $customer_email || ! $customer_phone ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation system unavailable.', 'ajforms' ) ) );
		}

		$settings  = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone  = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$wp_user_id = get_current_user_id();

		try {
			$start_dt = $this->parse_reservation_datetime_to_utc( $start_at_raw, $timezone );
			$end_dt   = $this->parse_reservation_datetime_to_utc( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date/time format.', 'ajforms' ) ) );
		}

		$whole_hour_check = $this->validate_reservation_whole_hour_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $whole_hour_check ) ) {
			wp_send_json_error( array( 'message' => $whole_hour_check->get_error_message() ) );
		}

		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		$window_check = AJCore_Reservations::validate_booking_window( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $window_check ) ) {
			wp_send_json_error( array( 'message' => $window_check->get_error_message() ) );
		}

		$resource = $this->get_configured_reservation_resource( $resource_key, $settings );
		if ( ! $resource ) {
			wp_send_json_error( array( 'message' => __( 'Resource not found.', 'ajforms' ) ) );
		}

		$conflict = AJCore_Reservations::check_local_conflict( (int) $resource->id, $start_at_utc, $end_at_utc, '' );
		if ( is_wp_error( $conflict ) ) {
			wp_send_json_error( array( 'message' => $conflict->get_error_message() ) );
		}

		$availability = $this->get_reservation_slot_availability( $resource, $start_dt, $end_dt, $timezone, $settings );
		if ( empty( $availability['available'] ) ) {
			wp_send_json_error( array( 'message' => ! empty( $availability['message'] ) ? $availability['message'] : __( 'This slot is not available.', 'ajforms' ) ) );
		}

		$pricing_type = AJCore_Reservations::determine_pricing_type( $start_at_utc, $timezone );

		$biz_rate_dollars   = max( 1, (int) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate_dollars = max( 1, (int) ( $settings['reservation_after_hours_rate'] ?? 80 ) );
		$unit_rate_dollars  = 'business_hours' === $pricing_type ? $biz_rate_dollars : $after_rate_dollars;
		$duration_hours     = (int) round( ( $end_dt->getTimestamp() - $start_dt->getTimestamp() ) / 3600 );
		$duration_hours     = max( 1, min( 14, $duration_hours ) );
		$total_amount       = $unit_rate_dollars * $duration_hours;

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$stored_notes       = trim( sprintf( "Phone: %s\n%s", $customer_phone, $customer_notes ) );

		// Create the reservation record (initially pending_payment).
		$pending = AJCore_Reservations::create_pending_reservation( array(
			'resource_id'       => (int) $resource->id,
			'resource_key'      => sanitize_key( (string) $resource->resource_key ),
			'resource_name'     => sanitize_text_field( (string) $resource->resource_name ),
			'stripe_customer_id'=> $stripe_customer_id,
			'wp_user_id'        => $wp_user_id,
			'start_at'          => $start_at_utc,
			'end_at'            => $end_at_utc,
			'timezone'          => $timezone,
			'pricing_type'      => $pricing_type,
			'amount'            => $total_amount,
			'currency'          => 'usd',
			'customer_name'     => $customer_name,
			'customer_email'    => $customer_email,
			'customer_notes'    => $stored_notes,
			'zoho_calendar_id'  => ! empty( $settings['zoho_calendar_id'] ) ? $settings['zoho_calendar_id'] : '',
			'zoho_resource_uid' => ! empty( $settings['zoho_resource_uid'] ) ? $settings['zoho_resource_uid'] : '',
		) );

		if ( is_wp_error( $pending ) ) {
			wp_send_json_error( array( 'message' => $pending->get_error_message() ) );
		}

		// Immediately update status to 'in_cart'.
		$pdb_res   = AJCore_Reservations::get_pdb();
		$res_table = AJCore_Reservations::get_reservations_table();
		$pdb_res->update(
			$res_table,
			array( 'status' => 'in_cart', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $pending['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		AJCore_Reservations::log_reservation_event(
			'reservation_added_to_cart',
			array(
				'reservation_uuid'   => $pending['reservation_uuid'],
				'reservation_id'     => $pending['id'],
				'stripe_customer_id' => $stripe_customer_id,
				'wp_user_id'         => $wp_user_id,
				'resource_key'       => $resource_key,
				'pricing_type'       => $pricing_type,
				'start_at'           => $start_at_utc,
				'end_at'             => $end_at_utc,
			)
		);

		$cart_items = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );

		wp_send_json_success( array(
			'cart_count' => count( $cart_items ),
			'message'    => __( 'Added to cart!', 'ajforms' ),
		) );
	}

	/**
	 * Get cart items formatted for JS rendering.
	 */
	public function ajax_reservation_get_cart() {
		check_ajax_referer( 'ajcore_reservation_add_to_cart', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone   = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$wp_user_id = get_current_user_id();
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();

		$items     = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );
		$formatted = array();
		$biz_rate  = max( 1, (int) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate = max( 1, (int) ( $settings['reservation_after_hours_rate'] ?? 80 ) );

		foreach ( $items as $item ) {
			$item  = (array) $item;
			$rate  = 'business_hours' === $item['pricing_type'] ? $biz_rate : $after_rate;
			try {
				$start = new DateTime( $item['start_at'], new DateTimeZone( 'UTC' ) );
				$end   = new DateTime( $item['end_at'], new DateTimeZone( 'UTC' ) );
				$start->setTimezone( new DateTimeZone( $timezone ) );
				$end->setTimezone( new DateTimeZone( $timezone ) );
			} catch ( Exception $e ) {
				continue;
			}
			$hours = max( 1, (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / 3600 ) );
			$total = $hours * $rate;
			$formatted[] = array(
				'uuid'  => $item['reservation_uuid'],
				'date'  => $start->format( 'M j, Y' ),
				'time'  => $start->format( 'g:i A' ) . ' – ' . $end->format( 'g:i A T' ),
				'hours' => $hours,
				'rate'  => $rate,
				'total' => $total,
				'label' => 'business_hours' === $item['pricing_type']
					? ( $settings['reservation_business_hours_label'] ?? 'Business Hours' )
					: ( $settings['reservation_after_hours_label'] ?? 'After-Hours / Weekend' ),
			);
		}

		wp_send_json_success( array( 'items' => $formatted ) );
	}

	/**
	 * Remove a reservation from the cart.
	 */
	public function ajax_reservation_cart_remove() {
		check_ajax_referer( 'ajcore_reservation_cart_remove', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation system unavailable.', 'ajforms' ) ) );
		}

		$uuid = isset( $_POST['reservation_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['reservation_uuid'] ) ) : '';
		if ( '' === $uuid ) {
			wp_send_json_error( array( 'message' => __( 'Missing reservation ID.', 'ajforms' ) ) );
		}

		$pdb_res    = AJCore_Reservations::get_pdb();
		$res_table  = AJCore_Reservations::get_reservations_table();
		$wp_user_id = get_current_user_id();
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();

		// Verify the reservation belongs to this user and is in_cart.
		$reservation = $pdb_res->get_row(
			$pdb_res->prepare(
				"SELECT * FROM `{$res_table}` WHERE reservation_uuid = %s AND status = 'in_cart' AND (wp_user_id = %d OR stripe_customer_id = %s) LIMIT 1",
				$uuid, $wp_user_id, $stripe_customer_id
			)
		);

		if ( ! $reservation ) {
			wp_send_json_error( array( 'message' => __( 'Reservation not found or already removed.', 'ajforms' ) ) );
		}

		$pdb_res->delete( $res_table, array( 'id' => (int) $reservation->id ), array( '%d' ) );

		AJCore_Reservations::log_reservation_event(
			'reservation_removed_from_cart',
			array(
				'reservation_uuid'   => $uuid,
				'reservation_id'     => $reservation->id,
				'stripe_customer_id' => $stripe_customer_id,
				'wp_user_id'         => $wp_user_id,
			)
		);

		// Return updated cart.
		$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone   = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$items      = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );
		$formatted  = array();
		$biz_rate   = max( 1, (int) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate = max( 1, (int) ( $settings['reservation_after_hours_rate'] ?? 80 ) );

		foreach ( $items as $item ) {
			$item = (array) $item;
			$rate = 'business_hours' === $item['pricing_type'] ? $biz_rate : $after_rate;
			try {
				$start = new DateTime( $item['start_at'], new DateTimeZone( 'UTC' ) );
				$end   = new DateTime( $item['end_at'], new DateTimeZone( 'UTC' ) );
				$start->setTimezone( new DateTimeZone( $timezone ) );
				$end->setTimezone( new DateTimeZone( $timezone ) );
			} catch ( Exception $e ) {
				continue;
			}
			$hours = max( 1, (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / 3600 ) );
			$formatted[] = array(
				'uuid'  => $item['reservation_uuid'],
				'date'  => $start->format( 'M j, Y' ),
				'time'  => $start->format( 'g:i A' ) . ' – ' . $end->format( 'g:i A T' ),
				'hours' => $hours,
				'rate'  => $rate,
				'total' => $hours * $rate,
				'label' => 'business_hours' === $item['pricing_type']
					? ( $settings['reservation_business_hours_label'] ?? 'Business Hours' )
					: ( $settings['reservation_after_hours_label'] ?? 'After-Hours / Weekend' ),
			);
		}

		wp_send_json_success( array( 'items' => $formatted ) );
	}

	/**
	 * Cart checkout: build one Stripe session for all in_cart items.
	 */
	public function ajax_reservation_cart_checkout() {
		$this->block_impersonated_portal_write();
		check_ajax_referer( 'ajcore_reservation_cart_checkout', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to checkout.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation system unavailable.', 'ajforms' ) ) );
		}

		$settings           = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone           = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$wp_user_id         = get_current_user_id();
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();

		$cart_items = AJCore_Reservations::get_cart_reservations( $stripe_customer_id, $wp_user_id );

		if ( empty( $cart_items ) ) {
			wp_send_json_error( array( 'message' => __( 'Your cart is empty.', 'ajforms' ) ) );
		}

		$biz_rate   = max( 1, (int) ( $settings['reservation_business_hours_rate'] ?? 40 ) );
		$after_rate = max( 1, (int) ( $settings['reservation_after_hours_rate'] ?? 80 ) );
		$biz_label  = ! empty( $settings['reservation_business_hours_label'] ) ? $settings['reservation_business_hours_label'] : 'Business Hours';
		$aft_label  = ! empty( $settings['reservation_after_hours_label'] ) ? $settings['reservation_after_hours_label'] : 'After-Hours / Weekend';
		$res_name   = ! empty( $settings['reservation_resource_name'] ) ? $settings['reservation_resource_name'] : __( 'Conference Room', 'ajforms' );

		$pdb        = AJCore_Reservations::get_pdb();
		$res_table  = AJCore_Reservations::get_reservations_table();
		$line_items = array();
		$uuids      = array();

		foreach ( $cart_items as $item ) {
			$item_uuid = sanitize_text_field( (string) $item->reservation_uuid );

			// Re-check availability, excluding itself.
			$start_at_utc = sanitize_text_field( (string) $item->start_at );
			$end_at_utc   = sanitize_text_field( (string) $item->end_at );
			$resource_id  = (int) $item->resource_id;

			$conflict = AJCore_Reservations::check_local_conflict( $resource_id, $start_at_utc, $end_at_utc, $item_uuid );
			if ( is_wp_error( $conflict ) ) {
				wp_send_json_error( array( 'message' => sprintf(
					/* translators: %s reservation reference */
					__( 'A slot in your cart is no longer available: %s. Please remove it and try again.', 'ajforms' ),
					$start_at_utc
				) ) );
			}

			// Full breakdown for mixed-rate support.
			$item_breakdown = AJCore_Reservations::calculate_pricing_breakdown( $start_at_utc, $end_at_utc, $timezone );
			if ( is_wp_error( $item_breakdown ) ) {
				continue;
			}

			// Require configured Stripe Price IDs — do not silently fall back to dynamic price_data.
			$item_resource_key   = sanitize_key( (string) $item->resource_key );
			$item_prices         = $this->get_reservation_price_ids_for_resource( $item_resource_key );
			$item_biz_price_id   = $item_prices['business_hours_price_id'];
			$item_after_price_id = $item_prices['after_hours_price_id'];
			if ( '' === $item_biz_price_id || '' === $item_after_price_id ) {
				wp_send_json_error( array(
					'message' => __( 'Conference room pricing is not fully configured. Please contact support.', 'ajforms' ),
				) );
			}

			$item_biz_qty   = (int) round( $item_breakdown['business_minutes'] / 60 );
			$item_after_qty = (int) round( $item_breakdown['after_hours_minutes'] / 60 );
			if ( $item_biz_qty > 0 ) {
				$line_items[] = array( 'price' => $item_biz_price_id, 'quantity' => $item_biz_qty );
			}
			if ( $item_after_qty > 0 ) {
				$line_items[] = array( 'price' => $item_after_price_id, 'quantity' => $item_after_qty );
			}
			if ( 0 === $item_biz_qty && 0 === $item_after_qty ) {
				$total_qty = max( 1, (int) round( $item_breakdown['total_minutes'] / 60 ) );
				$line_items[] = array(
					'price'    => 'business_hours' === $item_breakdown['pricing_type'] ? $item_biz_price_id : $item_after_price_id,
					'quantity' => $total_qty,
				);
			}
			$uuids[] = $item_uuid;
		}

		if ( empty( $line_items ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid items in cart.', 'ajforms' ) ) );
		}

		$stripe_settings = $this->get_stripe_settings();
		$secret_key      = ! empty( $stripe_settings['secret_key'] ) ? $stripe_settings['secret_key'] : '';
		if ( ! $secret_key ) {
			wp_send_json_error( array( 'message' => __( 'Payment gateway is not configured.', 'ajforms' ) ) );
		}

		$portal_url  = $this->get_customer_portal_url();
		$success_url = add_query_arg( array( 'portal_tab' => 'reservations', 'res_success' => '1' ), $portal_url );
		$cancel_url  = add_query_arg( array( 'portal_tab' => 'reservations', 'res_cancel' => '1' ), $portal_url );

		$uuids_str = implode( ',', $uuids );

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
			$first_item = (array) $cart_items[0];
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
				'body' => $this->flatten_array_for_stripe( $checkout_payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Payment gateway error. Please try again.', 'ajforms' ) ) );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		$session_id   = ! empty( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';
		$checkout_url = ! empty( $body['url'] ) ? esc_url_raw( $body['url'] ) : '';

		if ( 200 !== (int) $code || ! $session_id || ! $checkout_url ) {
			$err_msg = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not create checkout session.', 'ajforms' );
			wp_send_json_error( array( 'message' => $err_msg ) );
		}

		// Attach the Stripe session ID but keep status as 'in_cart' so abandoned checkouts
		// don't show as Pending Payment — the cart remains intact if the user comes back.
		// The webhook changes status to 'paid' on successful payment.
		foreach ( $uuids as $uuid ) {
			$pdb->update(
				$res_table,
				array(
					'stripe_checkout_session_id' => $session_id,
					'updated_at'                 => current_time( 'mysql' ),
				),
				array( 'reservation_uuid' => $uuid ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}

		AJCore_Reservations::log_reservation_event(
			'reservation_cart_checkout_started',
			array(
				'reservation_uuids'          => $uuids_str,
				'stripe_customer_id'         => $stripe_customer_id,
				'wp_user_id'                 => $wp_user_id,
				'stripe_checkout_session_id' => $session_id,
				'item_count'                 => count( $uuids ),
			)
		);

		wp_send_json_success( array( 'checkout_url' => $checkout_url ) );
	}

	/**
	 * Open the Stripe Customer Portal (lets users manage payment methods).
	 *
	 * Note: Requires the Stripe Customer Portal to be configured in the Stripe
	 * Dashboard (Stripe > Settings > Customer Portal) before use.
	 */
	public function ajax_stripe_customer_portal() {
		$this->block_impersonated_portal_write();
		check_ajax_referer( 'ajcore_stripe_customer_portal', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Not logged in.', 'ajforms' ) ) );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( ! $stripe_customer_id || 0 !== strpos( $stripe_customer_id, 'cus_' ) ) {
			wp_send_json_error( array( 'message' => __( 'No payment account found for this user.', 'ajforms' ) ) );
		}

		$stripe_settings = $this->get_stripe_settings();
		$secret_key      = ! empty( $stripe_settings['secret_key'] ) ? $stripe_settings['secret_key'] : '';
		if ( ! $secret_key ) {
			wp_send_json_error( array( 'message' => __( 'Payment gateway is not configured.', 'ajforms' ) ) );
		}

		$return_url = $this->get_customer_portal_url();

		$response = wp_remote_post(
			'https://api.stripe.com/v1/billing_portal/sessions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body' => array(
					'customer'   => $stripe_customer_id,
					'return_url' => $return_url,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not open billing portal. Please try again.', 'ajforms' ) ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['url'] ) ) {
			$err_msg = ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not open billing portal.', 'ajforms' );
			wp_send_json_error( array( 'message' => $err_msg ) );
		}

		wp_send_json_success( array( 'portal_url' => esc_url_raw( (string) $body['url'] ) ) );
	}

	public function ajax_test_zoho_connection() {
		check_ajax_referer( 'ajcore_test_zoho_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );

		// Read credentials — accept both new and old POST field names.
		$client_id     = isset( $_POST['zoho_client_id'] )     ? sanitize_text_field( wp_unslash( $_POST['zoho_client_id'] ) )
			: ( isset( $_POST['zoho_oauth_client_id'] )         ? sanitize_text_field( wp_unslash( $_POST['zoho_oauth_client_id'] ) )
			: ( $settings['zoho_client_id'] ?? $settings['zoho_oauth_client_id'] ?? '' ) );

		$client_secret = isset( $_POST['zoho_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['zoho_client_secret'] ) )
			: ( isset( $_POST['zoho_oauth_client_secret'] )     ? sanitize_text_field( wp_unslash( $_POST['zoho_oauth_client_secret'] ) )
			: ( $settings['zoho_client_secret'] ?? $settings['zoho_oauth_client_secret'] ?? '' ) );

		$code_or_token = isset( $_POST['zoho_code_or_refresh'] ) ? sanitize_text_field( wp_unslash( $_POST['zoho_code_or_refresh'] ) ) : '';
		$token_mode    = isset( $_POST['token_mode'] )           ? sanitize_key( wp_unslash( $_POST['token_mode'] ) ) : 'auto';

		$access_token       = '';
		$refresh_token_new  = false; // true when a new refresh token was returned by Zoho

		if ( '' === $client_id || '' === $client_secret ) {
			wp_send_json_error( array( 'message' => __( 'Client ID and Client Secret are required. Save them first, then test.', 'ajforms' ) ) );
		}

		// ── Determine flow: code exchange vs. token refresh ───────────────────
		$use_code_exchange = ( 'code' === $token_mode && '' !== $code_or_token );

		if ( $use_code_exchange ) {
			// Exchange a one-time generated code for access_token + refresh_token.
			$token_response = wp_remote_post(
				add_query_arg(
					array(
						'grant_type'    => 'authorization_code',
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'code'          => $code_or_token,
						'access_type'   => 'offline',
					),
					'https://accounts.zoho.com/oauth/v2/token'
				),
				array( 'timeout' => 20 )
			);

			if ( is_wp_error( $token_response ) ) {
				wp_send_json_error( array( 'message' => $token_response->get_error_message() ) );
			}

			$token_http = wp_remote_retrieve_response_code( $token_response );
			$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );

			if ( 200 !== (int) $token_http || empty( $token_body['access_token'] ) ) {
				$token_err = ! empty( $token_body['error'] ) ? $token_body['error']
					: sprintf( /* translators: %d HTTP status */ __( 'Zoho token exchange returned HTTP %d.', 'ajforms' ), $token_http );
				wp_send_json_error( array( 'message' => $token_err ) );
			}

			$access_token  = sanitize_text_field( (string) $token_body['access_token'] );
			$expires_in    = ! empty( $token_body['expires_in'] ) ? absint( $token_body['expires_in'] ) : 3600;
			$expires_at    = gmdate( 'Y-m-d H:i:s', time() + max( 0, $expires_in - 60 ) );
			$api_domain    = ! empty( $token_body['api_domain'] ) ? esc_url_raw( (string) $token_body['api_domain'] ) : ( $settings['zoho_api_domain'] ?? '' );

			// Save new key names + backward compat aliases.
			$settings['zoho_client_id']           = $client_id;
			$settings['zoho_client_secret']       = $client_secret;
			$settings['zoho_access_token']        = $access_token;
			$settings['zoho_token_expires_at']    = $expires_at;
			$settings['zoho_api_domain']          = $api_domain;
			if ( ! empty( $token_body['refresh_token'] ) ) {
				$settings['zoho_refresh_token'] = sanitize_text_field( (string) $token_body['refresh_token'] );
				$refresh_token_new = true;
			}
			$settings['zoho_oauth_client_id']      = $client_id;
			$settings['zoho_oauth_client_secret']  = $client_secret;
			$settings['zoho_api_token']            = $access_token;
			$settings['zoho_api_token_expires_at'] = $expires_at;
			$settings['zoho_oauth_api_domain']     = $api_domain;
			unset( $settings['zoho_oauth_authorization_code'] );
			update_option( 'ajforms_settings', $settings, false );

		} else {
			// Use the stored refresh token to obtain a fresh access token.
			$stored_refresh = $settings['zoho_refresh_token'] ?? '';
			if ( '' === $stored_refresh ) {
				wp_send_json_error( array( 'message' => __( 'No refresh token stored. Paste a generated code from Zoho Self Client and click Exchange Code & Test API.', 'ajforms' ) ) );
			}

			$refresh_response = wp_remote_post(
				add_query_arg(
					array(
						'grant_type'    => 'refresh_token',
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'refresh_token' => $stored_refresh,
					),
					'https://accounts.zoho.com/oauth/v2/token'
				),
				array( 'timeout' => 20 )
			);

			if ( is_wp_error( $refresh_response ) ) {
				wp_send_json_error( array( 'message' => $refresh_response->get_error_message() ) );
			}

			$refresh_http = wp_remote_retrieve_response_code( $refresh_response );
			$refresh_body = json_decode( wp_remote_retrieve_body( $refresh_response ), true );

			if ( 200 !== (int) $refresh_http || empty( $refresh_body['access_token'] ) ) {
				$err_msg = ! empty( $refresh_body['error'] ) ? $refresh_body['error']
					: sprintf( /* translators: %d HTTP status */ __( 'Token refresh returned HTTP %d.', 'ajforms' ), $refresh_http );
				wp_send_json_error( array( 'message' => $err_msg ) );
			}

			$access_token = sanitize_text_field( (string) $refresh_body['access_token'] );
			$expires_in   = ! empty( $refresh_body['expires_in'] ) ? absint( $refresh_body['expires_in'] ) : 3600;
			$expires_at   = gmdate( 'Y-m-d H:i:s', time() + max( 0, $expires_in - 60 ) );

			$settings['zoho_access_token']        = $access_token;
			$settings['zoho_token_expires_at']    = $expires_at;
			$settings['zoho_api_token']           = $access_token;
			$settings['zoho_api_token_expires_at']= $expires_at;
			if ( '' !== $client_id ) { $settings['zoho_client_id'] = $client_id; $settings['zoho_oauth_client_id'] = $client_id; }
			if ( '' !== $client_secret ) { $settings['zoho_client_secret'] = $client_secret; $settings['zoho_oauth_client_secret'] = $client_secret; }
			update_option( 'ajforms_settings', $settings, false );
		}

		if ( '' === $access_token ) {
			wp_send_json_error( array( 'message' => __( 'Could not obtain an access token. Check Client ID and Client Secret.', 'ajforms' ) ) );
		}

		// ── Test the Calendar API ─────────────────────────────────────────────
		$response = wp_remote_get(
			'https://calendar.zoho.com/api/v1/calendars',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$cal_code = wp_remote_retrieve_response_code( $response );
		$cal_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === (int) $cal_code ) {
			$count = ! empty( $cal_body['calendars'] ) && is_array( $cal_body['calendars'] ) ? count( $cal_body['calendars'] ) : 0;

			$message = sprintf(
				/* translators: %d: number of calendars */
				_n( 'Connected! Found %d calendar.', 'Connected! Found %d calendars.', $count, 'ajforms' ),
				$count
			);

			if ( ! empty( $settings['zoho_calendar_uid'] ) ) {
				$uid_check = wp_remote_get(
					'https://calendar.zoho.com/api/v1/calendars/' . rawurlencode( sanitize_text_field( (string) $settings['zoho_calendar_uid'] ) ),
					array(
						'timeout' => 15,
						'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/json' ),
					)
				);
				$message .= ( ! is_wp_error( $uid_check ) && 200 === (int) wp_remote_retrieve_response_code( $uid_check ) )
					? ' ' . __( 'Configured Calendar UID is readable.', 'ajforms' )
					: ' ' . __( 'Connected, but configured Calendar UID could not be read — check Step 4.', 'ajforms' );
			}

			$final_expires = $settings['zoho_token_expires_at'] ?? $settings['zoho_api_token_expires_at'] ?? '';

			wp_send_json_success( array(
				'message'             => $message,
				'refresh_token_saved' => $refresh_token_new,
				'expires_at'          => $final_expires,
				'api_domain'          => $settings['zoho_api_domain'] ?? $settings['zoho_oauth_api_domain'] ?? '',
			) );
		}

		$err = ! empty( $cal_body['message'] ) ? $cal_body['message']
			: sprintf( /* translators: %d HTTP status */ __( 'Zoho returned HTTP %d. Check your token.', 'ajforms' ), $cal_code );
		wp_send_json_error( array( 'message' => $err ) );
	}

	/**
	 * Return booked reservation events for a given date range — used by FullCalendar events feed.
	 * Returns JSON array of FullCalendar-compatible event objects.
	 */
	public function ajax_get_reservation_events() {
		check_ajax_referer( 'ajcore_reservation_check_availability', 'nonce' );

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json( array() );
		}

		$pdb   = AJCore_Reservations::get_pdb();
		$table = AJCore_Reservations::get_reservations_table();
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			wp_send_json( array() );
		}

		$start_raw = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
		$end_raw   = isset( $_GET['end'] )   ? sanitize_text_field( wp_unslash( $_GET['end'] ) )   : '';

		try {
			$start_utc = '' !== $start_raw ? ( new DateTime( $start_raw ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' );
			$end_utc   = '' !== $end_raw   ? ( new DateTime( $end_raw ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )   : gmdate( 'Y-m-d H:i:s', strtotime( '+1 year' ) );
		} catch ( Exception $e ) {
			wp_send_json( array() );
		}

		$hold_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( AJCore_Reservations::PENDING_HOLD_MINUTES * 60 ) );

		$rows = $pdb->get_results(
			$pdb->prepare(
				"SELECT start_at, end_at, status
				 FROM `{$table}`
				 WHERE (
				     status IN ('confirmed','paid','paid_pending_calendar')
				     OR (status = 'pending_payment' AND created_at >= %s)
				 )
				 AND start_at < %s
				 AND end_at   > %s",
				$hold_cutoff,
				$end_utc,
				$start_utc
			)
		);

		// Return times in the site's configured timezone (without Z suffix = "floating" time).
		// FullCalendar's timeZone setting matches this, so it displays them correctly without needing
		// a timezone plugin conversion from UTC. Avoids the UTC→local conversion bug in CDN bundles.
		// Must use ajforms_get_settings(): on secondary sites the Zoho config lives in the shared DB.
		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$site_tz  = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone']
			: get_option( 'timezone_string', 'America/New_York' );
		$tz_obj   = new DateTimeZone( $site_tz );

		$events = array();
		foreach ( $rows as $r ) {
			try {
				$s_dt = ( new DateTime( $r->start_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_obj );
				$e_dt = ( new DateTime( $r->end_at,   new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_obj );
			} catch ( Exception $ex ) {
				continue;
			}
			$events[] = array(
				'title'           => __( 'Booked', 'ajforms' ),
				'start'           => $s_dt->format( 'Y-m-d\TH:i:s' ),
				'end'             => $e_dt->format( 'Y-m-d\TH:i:s' ),
				'backgroundColor' => '#fee2e2',
				'borderColor'     => '#ef4444',
				'textColor'       => '#991b1b',
				'display'         => 'block',
			);
		}

		// Also show Zoho Calendar events as unavailable blocks. Failures here would
		// silently show busy days as free, so log them (throttled to once per 15 min).
		$log_zoho_feed_failure = function ( $reason, $detail = '' ) {
			if ( get_transient( 'ajcore_res_feed_zoho_fail_logged' ) ) {
				return;
			}
			set_transient( 'ajcore_res_feed_zoho_fail_logged', 1, 15 * MINUTE_IN_SECONDS );
			$this->log_portal_event(
				'reservation_calendar_feed_zoho_failed',
				array(
					'severity' => 'warning',
					'source'   => 'reservation_calendar_feed',
					'details'  => array( 'reason' => $reason, 'detail' => $detail ),
				)
			);
		};

		$zoho_cal_uid = ! empty( $settings['zoho_calendar_uid'] ) ? trim( (string) $settings['zoho_calendar_uid'] ) : '';
		if ( '' === $zoho_cal_uid && ! empty( $settings['zoho_reservations_enabled'] ) ) {
			$log_zoho_feed_failure( 'no_calendar_uid', 'zoho_calendar_uid is empty — set the Calendar UID in Calendar/Reservations Step 4; busy times cannot be blocked without it.' );
		}
		if ( '' !== $zoho_cal_uid && class_exists( 'AJCore_Zoho_Calendar' ) ) {
			$zoho_token = $this->get_valid_zoho_token( $settings );
			if ( '' === $zoho_token ) {
				$log_zoho_feed_failure( 'no_valid_token', 'Token missing/expired and refresh failed — check Zoho client ID, secret, and refresh token in settings.' );
			} else {
				$zoho_events = AJCore_Zoho_Calendar::get_events_for_range( $zoho_cal_uid, $start_raw, $end_raw, $site_tz, $zoho_token );
				if ( is_wp_error( $zoho_events ) ) {
					$log_zoho_feed_failure( 'events_fetch_failed', $zoho_events->get_error_message() );
				}
				if ( ! is_wp_error( $zoho_events ) ) {
					foreach ( $zoho_events as $ze ) {
						$ze['start']->setTimezone( $tz_obj );
						$ze['end']->setTimezone( $tz_obj );
						$events[] = array(
							'title'           => __( 'Unavailable', 'ajforms' ),
							'start'           => $ze['start']->format( 'Y-m-d\TH:i:s' ),
							'end'             => $ze['end']->format( 'Y-m-d\TH:i:s' ),
							'backgroundColor' => '#fef3c7',
							'borderColor'     => '#d97706',
							'textColor'       => '#92400e',
							'display'         => 'block',
						);
					}
				}
			}
		}

		wp_send_json( $events );
	}

	/**
	 * Returns a valid Zoho API token, auto-refreshing with the refresh token if expired.
	 * Returns empty string if no valid token can be obtained.
	 */
	private function get_valid_zoho_token( array &$settings = array() ) {
		return class_exists( 'AJCore_Zoho_Calendar' ) ? AJCore_Zoho_Calendar::get_valid_token( $settings ) : '';
	}

	/**
	 * Save a reservation request to DB without Stripe payment (for testing / manual-confirmation flow).
	 */
	public function ajax_reservation_request() {
		check_ajax_referer( 'ajcore_reservation_check_availability', 'nonce' );

		// Admin-only: this flow bypasses Stripe and confirms without payment.
		// Portal users must go through ajax_reservation_create_checkout (prepay-only).
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ajforms' ) ), 403 );
		}

		$resource_key   = isset( $_POST['resource_key'] )   ? sanitize_key( wp_unslash( $_POST['resource_key'] ) )          : '';
		$start_at_raw   = isset( $_POST['start_at'] )       ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) )        : '';
		$end_at_raw     = isset( $_POST['end_at'] )         ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) )          : '';
		$customer_name  = isset( $_POST['customer_name'] )  ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) )   : '';
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) )       : '';
		$customer_phone = isset( $_POST['customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_phone'] ) )  : '';
		$customer_notes = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['customer_notes'] ) ) : '';

		if ( ! $resource_key || ! $start_at_raw || ! $end_at_raw || ! $customer_name || ! $customer_email ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'ajforms' ) ) );
		}

		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			wp_send_json_error( array( 'message' => __( 'Reservation system unavailable.', 'ajforms' ) ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		try {
			$start_dt = $this->parse_reservation_datetime_to_utc( $start_at_raw, $timezone );
			$end_dt   = $this->parse_reservation_datetime_to_utc( $end_at_raw, $timezone );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date/time format.', 'ajforms' ) ) );
		}

		$whole_hour_check = $this->validate_reservation_whole_hour_slot( $start_dt, $end_dt, $timezone );
		if ( is_wp_error( $whole_hour_check ) ) {
			wp_send_json_error( array( 'message' => $whole_hour_check->get_error_message() ) );
		}

		$start_at_utc = $start_dt->format( 'Y-m-d H:i:s' );
		$end_at_utc   = $end_dt->format( 'Y-m-d H:i:s' );

		$window_check = AJCore_Reservations::validate_booking_window( $start_at_utc, $end_at_utc, $timezone );
		if ( is_wp_error( $window_check ) ) {
			wp_send_json_error( array( 'message' => $window_check->get_error_message() ) );
		}

		$resource = $this->get_configured_reservation_resource( $resource_key, $settings );
		if ( ! $resource ) {
			wp_send_json_error( array( 'message' => __( 'Resource not found.', 'ajforms' ) ) );
		}

		$conflict = AJCore_Reservations::check_local_conflict( (int) $resource->id, $start_at_utc, $end_at_utc, '' );
		if ( is_wp_error( $conflict ) ) {
			wp_send_json_error( array( 'message' => $conflict->get_error_message() ) );
		}

		// Prepend phone to notes since the DB table has no phone column yet.
		$notes_with_phone = '' !== $customer_phone
			? 'Phone: ' . $customer_phone . ( '' !== $customer_notes ? "\n" . $customer_notes : '' )
			: $customer_notes;

		$pricing_type = AJCore_Reservations::determine_pricing_type( $start_at_utc, $timezone );
		$result = AJCore_Reservations::create_pending_reservation( array(
			'wp_user_id'       => get_current_user_id(),
			'resource_id'      => (int) $resource->id,
			'resource_key'     => $resource_key,
			'resource_name'    => (string) ( $resource->resource_name ?? $resource_key ),
			'zoho_calendar_uid'=> ! empty( $settings['zoho_calendar_uid'] ) ? $settings['zoho_calendar_uid'] : '',
			'zoho_calendar_id' => ! empty( $settings['zoho_calendar_id'] ) ? $settings['zoho_calendar_id'] : '',
			'pricing_type'     => $pricing_type,
			'start_at'         => $start_at_utc,
			'end_at'           => $end_at_utc,
			'timezone'         => $timezone,
			'customer_name'    => $customer_name,
			'customer_email'   => $customer_email,
			'customer_notes'   => $notes_with_phone,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$res_id   = (int) $result['id'];
		$res_uuid = (string) $result['reservation_uuid'];

		// Update status to confirmed (no payment required in request flow).
		$pdb_req   = AJCore_Reservations::get_pdb();
		$res_table = AJCore_Reservations::get_reservations_table();
		$pdb_req->update(
			$res_table,
			array( 'status' => 'confirmed', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $res_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Attempt Zoho calendar event with a fresh token.
		$fresh_token = $this->get_valid_zoho_token( $settings );
		if ( '' !== $fresh_token ) {
			// Keep both key names in sync so Zoho classes read it correctly.
			$settings['zoho_access_token'] = $fresh_token;
			$settings['zoho_api_token']    = $fresh_token;
			$reservation_row = AJCore_Reservations::get_reservation_by_uuid( $res_uuid );
			if ( $reservation_row ) {
				$res_array   = (array) $reservation_row;
				$zoho_result = AJCore_Reservations::attempt_zoho_calendar_event( $res_array, $settings );
				if ( ! is_wp_error( $zoho_result ) ) {
					$pdb_req->update(
						$res_table,
						array(
							'zoho_event_id' => sanitize_text_field( $zoho_result['event_id'] ?? '' ),
							'raw_zoho_data' => wp_json_encode( $zoho_result['raw_data'] ?? array() ),
							'updated_at'    => current_time( 'mysql' ),
						),
						array( 'id' => $res_id ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);
				}
			}
		}

		wp_send_json_success( array(
			'message'        => __( 'Reservation confirmed! You will receive a confirmation email shortly.', 'ajforms' ),
			'reservation_id' => $result,
		) );
	}

	/**
	 * Flatten a nested array into Stripe's URL-encoded format (e.g. line_items[0][price]).
	 */
	private function flatten_array_for_stripe( $array, $prefix = '' ) {
		$result = array();
		foreach ( $array as $key => $value ) {
			$full_key = $prefix ? $prefix . '[' . $key . ']' : $key;
			if ( is_array( $value ) ) {
				$result = array_merge( $result, $this->flatten_array_for_stripe( $value, $full_key ) );
			} else {
				$result[ $full_key ] = $value;
			}
		}
		return $result;
	}

	public function enqueue_username_edit_script( $hook ) {
		if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
			return;
		}
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var field = document.getElementById('user_login');
			if (!field) return;
			field.removeAttribute('disabled');
			field.removeAttribute('readonly');
			field.style.backgroundColor = '';
			field.style.cursor = 'text';
			var desc = document.createElement('p');
			desc.className = 'description';
			desc.textContent = 'Username can be changed. It must be unique across all users.';
			field.closest('td').appendChild(desc);
		});
		</script>
		<?php
	}

	public function validate_username_change( $errors, $update, $user ) {
		if ( ! $update || ! isset( $_POST['user_login'] ) ) {
			return $errors;
		}
		$new_login    = sanitize_user( wp_unslash( (string) $_POST['user_login'] ), true );
		$current_user = get_userdata( $user->ID );
		if ( ! $current_user || $current_user->user_login === $new_login ) {
			return $errors;
		}
		if ( '' === $new_login ) {
			$errors->add( 'user_login', __( '<strong>Error:</strong> Username cannot be empty.' ) );
			return $errors;
		}
		if ( $new_login !== sanitize_user( $new_login, true ) ) {
			$errors->add( 'user_login', __( '<strong>Error:</strong> This username contains invalid characters.' ) );
			return $errors;
		}
		if ( username_exists( $new_login ) ) {
			$errors->add( 'user_login', __( '<strong>Error:</strong> That username is already taken. Please choose another.' ) );
		}
		return $errors;
	}

	public function save_username_change( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['user_login'] ) ) {
			return;
		}
		$new_login    = sanitize_user( wp_unslash( (string) $_POST['user_login'] ), true );
		$current_user = get_userdata( $user_id );
		if ( ! $current_user || '' === $new_login || $current_user->user_login === $new_login ) {
			return;
		}
		if ( username_exists( $new_login ) ) {
			return;
		}
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_login' => $new_login ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );
	}

	public function run() {
	}
}
