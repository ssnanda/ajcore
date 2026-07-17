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
					'customer_type'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers/(?P<stripe_customer_id>(?:cus_|local_)[A-Za-z0-9_\-]+)',
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
			'/ops/customers/(?P<stripe_customer_id>local_[A-Za-z0-9_\-]+)/ledger/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_import_local_customer_ledger' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers/(?P<stripe_customer_id>local_[A-Za-z0-9_\-]+)/ledger/charges',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_add_local_customer_charge' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/ops/customers/(?P<stripe_customer_id>local_[A-Za-z0-9_\-]+)/ledger/(?P<entry_id>\d+)',
			array(
				array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'ops_update_local_ledger_entry' ), 'permission_callback' => array( $this, 'can_manage_ops_api' ) ),
				array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'ops_delete_local_ledger_entry' ), 'permission_callback' => array( $this, 'can_manage_ops_api' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			// cus_* customers are allowed too: they get $0 tracking-only services (no billing
			// dates, so the recurring job never posts ledger charges for them).
			'/ops/customers/(?P<stripe_customer_id>(?:cus_|local_)[A-Za-z0-9_\-]+)/local-services',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_upsert_local_customer_service' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/local-recurring-transactions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_local_recurring_transactions' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);
		register_rest_route( self::NAMESPACE, '/ops/accounting-catalog', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_ops_accounting_catalog' ), 'permission_callback' => array( $this, 'can_manage_ops_api' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'save_ops_accounting_catalog' ), 'permission_callback' => array( $this, 'can_manage_ops_api' ) ),
		) );

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
			'/ops/customers/(?P<stripe_customer_id>cus_[A-Za-z0-9_\-]+)/impersonation-link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_create_customer_impersonation_link' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/customers/(?P<stripe_customer_id>cus_[A-Za-z0-9_\-]+)/subscriptions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_create_customer_subscription' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'price_id'          => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'quantity'          => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'collection_method' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					'days_until_due'    => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'trial_days'        => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'service_start_date' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'service_end_date'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'billing_start_date' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
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

		register_rest_route(
			self::NAMESPACE,
			'/ops/sync/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_sync_status' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/sync/run-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ops_sync_run_status' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_create_lead' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'name'    => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
					'email'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_email' ),
					'phone'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'company' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'source'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'notes'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'status'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads/(?P<id>\d+)/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ops_add_lead_note' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'note' => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_ops_lead' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_ops_lead_fields' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_ops_leads' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'action' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'ids'    => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads/merge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'merge_ops_leads' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
				'args'                => array(
					'ids'        => array( 'required' => true ),
					'primary_id' => array( 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/leads/fix-duplicates',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'fix_ops_lead_duplicates' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		// Tasks CRUD
		register_rest_route(
			self::NAMESPACE,
			'/ops/tasks',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ops_task' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'title'              => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
						'task_scope'         => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'task_frequency'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'status'             => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'due_date'           => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'action_required'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						'client_visible'     => array( 'required' => false ),
						'stripe_customer_id' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/tasks/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_task' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_ops_task' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/tasks/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_ops_tasks' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'action' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
						'ids'    => array( 'required' => true ),
					),
				),
			)
		);

		// Compliance calendar (entities + annual-report filings)
		register_rest_route(
			self::NAMESPACE,
			'/ops/compliance',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ops_compliance_entity' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'entity_name'        => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
						'stripe_customer_id' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'entity_type'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'jurisdiction'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'sos_id'             => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'formation_date'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'first_report_year'  => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'due_month'          => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'due_day'            => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						'entity_status'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						'notes'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/compliance/filings/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_compliance_filing' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'action'       => array( 'required' => true,  'sanitize_callback' => 'sanitize_key' ),
						'confirmation' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'due_date'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'notes'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		// Client portal: mark an annual-report filing complete (or undo) for one of
		// the current user's own entities.
		register_rest_route(
			self::NAMESPACE,
			'/portal/compliance/filings/(?P<id>\d+)/complete',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'complete_portal_compliance_filing' ),
					'permission_callback' => array( $this, 'can_use_portal_api' ),
					'args'                => array(
						'completed' => array( 'required' => false ),
						'note'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/compliance/(?P<id>\d+)/remind',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'remind_ops_compliance_entity' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/compliance/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_compliance_entity' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_ops_compliance_entity' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/ajphone/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ops_ajphone_settings' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_ajphone_settings' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'account_id'           => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'client_id'            => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'client_secret'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'phone_number'         => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'monitored_user_ids'   => array( 'required' => false ),
						'account_id_2'         => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'client_id_2'          => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'client_secret_2'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'phone_number_2'       => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'monitored_user_ids_2' => array( 'required' => false ),
						'account_label_2'      => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'automation_enabled'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'automation_enabled_at' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'automation_staff_notify_number' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'automation_rules'     => array( 'required' => false ),
						'automation_logs'      => array( 'required' => false ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/ajphone/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ops_ajphone_conversations' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_ajphone_conversation' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'key'          => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
						'account_key'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'own_number'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'peer_number'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
						'is_read'      => array( 'required' => false ),
						'is_pinned'    => array( 'required' => false ),
						'is_archived'  => array( 'required' => false ),
						'is_deleted'   => array( 'required' => false ),
						'queue'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
					),
				),
			)
		);

		// ── OPS Staff (GET list + POST create + PATCH access by ID) ─────────────
		register_rest_route(
			self::NAMESPACE,
			'/ops/staff',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ops_staff' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ops_staff' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/ops/staff/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_ops_staff' ),
				'permission_callback' => array( $this, 'can_manage_ops_api' ),
			)
		);

		// ── OPS Files (GET list + POST create + PATCH/DELETE by ID) ─────────────
		register_rest_route(
			self::NAMESPACE,
			'/ops/files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ops_files' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
					'args'                => array(
						'per_page' => array( 'default' => 200, 'sanitize_callback' => 'absint' ),
						'search'   => array( 'default' => '',  'sanitize_callback' => 'sanitize_text_field' ),
						'category' => array( 'default' => '',  'sanitize_callback' => 'sanitize_text_field' ),
						'status'   => array( 'default' => 'active', 'sanitize_callback' => 'sanitize_key' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ops_file' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/files/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_ops_file' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_ops_file' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);

		// Mail intake: create/update accept multipart form data (optional scan upload),
		// so they are registered directly rather than through the JSON route map.
		register_rest_route(
			self::NAMESPACE,
			'/ops/mail',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_ops_mail_item' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ops/mail/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_ops_mail_item' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_ops_mail_item' ),
					'permission_callback' => array( $this, 'can_manage_ops_api' ),
				),
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
			'/ops/customers/(?P<stripe_customer_id>(?:cus_|local_)[A-Za-z0-9_\-]+)' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_customer', 'permission' => 'can_manage_ops_api' ),
			'/ops/products' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_products', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/subscriptions' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_subscriptions', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/ledger' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_ledger', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/transactions' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_transactions', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/leads'              => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_leads',         'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/leads/(?P<id>\d+)' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_lead_detail',    'permission' => 'can_manage_ops_api' ),
			'/ops/leads/(?P<id>\d+)/status' => array( 'methods' => 'PATCH',           'callback' => 'update_ops_lead_status', 'permission' => 'can_manage_ops_api' ),
			'/ops/tasks' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_tasks', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/compliance' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_compliance', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/service-requests' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_service_requests', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/service-requests/(?P<id>\d+)' => array( 'methods' => 'POST', 'callback' => 'update_ops_service_request', 'permission' => 'can_manage_ops_api' ),
			'/ops/service-requests/(?P<id>\d+)/quick-action' => array( 'methods' => 'POST', 'callback' => 'apply_ops_service_request_quick_action', 'permission' => 'can_manage_ops_api' ),
			'/ops/service-requests/(?P<id>\d+)/notify' => array( 'methods' => 'POST', 'callback' => 'notify_ops_service_request_status', 'permission' => 'can_manage_ops_api' ),
			'/ops/service-requests/(?P<id>\d+)/history' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_service_request_history', 'permission' => 'can_manage_ops_api' ),
			'/ops/service-requests/bulk' => array( 'methods' => 'POST', 'callback' => 'bulk_update_ops_service_requests', 'permission' => 'can_manage_ops_api' ),
			'/ops/sync-logs' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_sync_logs', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/event-log' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_event_log', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/email-log' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_email_log', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/partners' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_partners', 'permission' => 'can_manage_ops_api' ),
			'/ops/product-counts' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_product_counts', 'permission' => 'can_manage_ops_api' ),
			'/ops/customers/(?P<stripe_customer_id>(?:cus_|local_)[A-Za-z0-9_\-]+)/partner' => array( 'methods' => 'POST', 'callback' => 'update_ops_customer_partner', 'permission' => 'can_manage_ops_api' ),
			'/ops/customers/(?P<stripe_customer_id>(?:cus_|local_)[A-Za-z0-9_\-]+)/profile' => array( 'methods' => 'POST', 'callback' => 'update_ops_customer_profile', 'permission' => 'can_manage_ops_api' ),
			'/ops/customers/(?P<stripe_customer_id>cus_[A-Za-z0-9_\-]+)/invoices' => array( 'methods' => 'POST', 'callback' => 'ops_create_customer_invoice', 'permission' => 'can_manage_ops_api' ),
			'/ops/customer-profiles/expiring' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_expiring_id_profiles', 'permission' => 'can_manage_ops_api' ),
			'/ops/email-log/delete-all' => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'delete_ops_email_log_all', 'permission' => 'can_manage_ops_api' ),
			// Mail intake (sub-routes before the bare /ops/mail/{id} GET; create/update/delete registered above)
			'/ops/mail/(?P<id>\d+)/notify'  => array( 'methods' => 'POST', 'callback' => 'notify_ops_mail_item', 'permission' => 'can_manage_ops_api' ),
			'/ops/mail/(?P<id>\d+)/publish' => array( 'methods' => 'POST', 'callback' => 'publish_ops_mail_item_to_files', 'permission' => 'can_manage_ops_api' ),
			'/ops/mail/(?P<id>\d+)'         => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_mail_item', 'permission' => 'can_manage_ops_api' ),
			'/ops/mail'                     => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_mail_items', 'permission' => 'can_manage_ops_api', 'args' => $read_args ),
			'/ops/email-log/(?P<id>\d+)' => array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'get_ops_email_log_entry', 'permission' => 'can_manage_ops_api' ),
			'/ops/email-log/(?P<id>\d+)/delete' => array( 'methods' => 'DELETE', 'callback' => 'delete_ops_email_log_entry', 'permission' => 'can_manage_ops_api' ),
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
			'/portal/compliance'      => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_compliance',         'permission' => 'can_use_portal_api' ),
			'/portal/service-requests/create' => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'create_portal_service_request', 'permission' => 'can_use_portal_api' ),
			'/portal/files'           => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_files',         'permission' => 'can_use_portal_api' ),
			'/portal/mail'            => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'get_portal_mail',          'permission' => 'can_use_portal_api' ),
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
			'/ops/reservations/busy'                => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'ops_reservations_busy',              'permission' => 'can_manage_ops_api' ),
			'/ops/reservations/(?P<id>[0-9]+)'      => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'ops_get_reservation',               'permission' => 'can_manage_ops_api' ),
			'/ops/reservations'                     => array( 'methods' => WP_REST_Server::READABLE,  'callback' => 'ops_get_reservations',               'permission' => 'can_manage_ops_api' ),
			'/ops/reservations/create'              => array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'ops_create_reservation',             'permission' => 'can_manage_ops_api' ),
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
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/customers/{stripe_customer_id}', 'auth' => 'Admin', 'purpose' => 'Single customer profile with subscriptions, ledger, service requests and tasks.', 'app' => 'OPS customer view' ),
			array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/customers/{stripe_customer_id}/subscriptions', 'auth' => 'Admin', 'purpose' => 'Create a Stripe subscription for a customer from a synced recurring price.', 'app' => 'OPS customer view' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/products', 'auth' => 'Admin', 'purpose' => 'Synced Stripe/product catalog rows for product management.', 'app' => 'OPS catalog' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/subscriptions', 'auth' => 'Admin', 'purpose' => 'Subscription list for services and renewals.', 'app' => 'OPS services' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/ledger', 'auth' => 'Admin', 'purpose' => 'Billing ledger entries.', 'app' => 'OPS billing' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/tasks', 'auth' => 'Admin', 'purpose' => 'Task definitions and customer task statuses.', 'app' => 'OPS tasks' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/compliance', 'auth' => 'Admin', 'purpose' => 'Compliance calendar: registered entities with annual-report deadlines and filing history.', 'app' => 'OPS compliance' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/service-requests', 'auth' => 'Admin', 'purpose' => 'Service request queue.', 'app' => 'OPS service desk' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/files', 'auth' => 'Admin', 'purpose' => 'Shared files list with assignment labels.', 'app' => 'OPS files' ),
			array( 'surface' => 'OPS', 'method' => 'GET', 'path' => '/ops/mail', 'auth' => 'Admin', 'purpose' => 'Mail/service-of-process intake queue with stats. Filters: search, status (received|scanned|notified|closed|open), mail_type, sop, stripe_customer_id.', 'app' => 'OPS mail' ),
			array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail', 'auth' => 'Admin', 'purpose' => 'Log a received mail item (multipart; optional scan upload + notify flag for log-and-notify in one step).', 'app' => 'OPS mail' ),
			array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail/{id}', 'auth' => 'Admin', 'purpose' => 'Update a mail item: fields, scan upload, disposition (forwarded|picked_up|shredded|returned|held).', 'app' => 'OPS mail' ),
			array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail/{id}/notify', 'auth' => 'Admin', 'purpose' => 'Send (or resend) the client notification email for a mail item; stamps notified_at.', 'app' => 'OPS mail' ),
			array( 'surface' => 'OPS', 'method' => 'POST', 'path' => '/ops/mail/{id}/publish', 'auth' => 'Admin', 'purpose' => 'Publish the item scan into the client Files library and link it via file_id.', 'app' => 'OPS mail' ),
			array( 'surface' => 'Portal', 'method' => 'GET', 'path' => '/portal/mail', 'auth' => 'Portal user', 'purpose' => 'Current user mailbox: received mail items with scan links and dispositions (no staff fields).', 'app' => 'iOS app / portal' ),
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
		global $wpdb;
		$pdb = $this->get_portal_db();

		$customers_table     = $this->portal_table( 'aj_portal_stripe_customers' );
		$local_customers_table = $this->portal_table( 'aj_portal_local_customers' );
		$subscriptions_table = $this->portal_table( 'aj_portal_stripe_subscriptions' );
		$leads_table         = $pdb->prefix . 'aj_forms_leads';

		$customers_with_active_subscription = (int) $pdb->get_var(
			"SELECT COUNT(DISTINCT stripe_customer_id) FROM `{$subscriptions_table}` WHERE status = 'active'"
		);

		$leads_total  = 0;
		$leads_unread = 0;
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) ) === $leads_table ) {
			$leads_total  = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$leads_table}`" );
			$leads_unread = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$leads_table}` WHERE status = 'new'" );
		}

		$sr_stats   = $this->get_service_request_stats_for_ops();
		$tasks_table = $this->portal_table( 'aj_portal_tasks' );
		$tasks_total = $this->count_table( $pdb, $tasks_table );
		$tasks_open  = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$tasks_table}` WHERE status NOT IN ('completed','cancelled')" );

		return rest_ensure_response(
			array(
				'customers'                          => $this->count_table( $pdb, $customers_table ) + $this->count_table( $pdb, $local_customers_table ),
				'customers_with_active_subscription' => $customers_with_active_subscription,
				'customer_states'                    => $this->count_table( $pdb, $this->portal_table( 'aj_portal_customer_states' ) ),
				'products'                            => $this->count_table( $pdb, $this->portal_table( 'aj_portal_stripe_products' ) ),
				'subscriptions'                       => $this->count_table( $pdb, $subscriptions_table ),
				'ledger'                              => $this->count_table( $pdb, $this->portal_table( 'aj_portal_ledger' ) ) + $this->count_table( $pdb, $this->portal_table( 'aj_portal_local_ledger' ) ),
				'tasks'                               => $tasks_total,
				'tasks_open'                          => $tasks_open,
				'service_requests'                    => $sr_stats['total'],
				'service_requests_needs_action'       => $sr_stats['needs_action'],
				'leads'                               => $leads_total,
				'leads_unread'                        => $leads_unread,
				'sync_logs'                           => $this->count_table( $pdb, $this->portal_table( 'aj_portal_sync_logs' ) ),
			)
		);
	}

	public function get_ops_customers( WP_REST_Request $request ) {
		$customers = $this->select_rows( $this->portal_table( 'aj_portal_stripe_customers' ), array( 'stripe_customer_id', 'email', 'name', 'phone', 'description', 'address', 'metadata', 'portal_status', 'enabled_portal', 'partner_key', 'livemode', 'synced_at' ), $request, array( 'name', 'email', 'stripe_customer_id' ), 'synced_at DESC, id DESC' );
		$pdb = $this->get_portal_db();
		$local_table = $this->portal_table( 'aj_portal_local_customers' );
		if ( $this->table_exists( $pdb, $local_table ) ) {
			$local_rows = $pdb->get_results( "SELECT local_customer_id AS stripe_customer_id,email,name,phone,description,address,metadata,status AS portal_status,0 AS enabled_portal,partner_key,0 AS livemode,updated_at AS synced_at FROM `{$local_table}` ORDER BY updated_at DESC,id DESC", ARRAY_A );
			$customers = array_merge( $customers, (array) $local_rows );
		}
		$customers = $this->attach_customer_site_labels( array_map( array( $this, 'format_ops_customer_row' ), $customers ) );
		$customers = $this->attach_customer_portal_user_links( $customers );
		return rest_ensure_response( array( 'customers' => $customers ) );
	}

	private function attach_customer_portal_user_links( $customers ) {
		$customers = (array) $customers;
		$ids       = array_values( array_unique( array_filter( array_map(
			function ( $c ) { return isset( $c['stripe_customer_id'] ) ? (string) $c['stripe_customer_id'] : ''; },
			$customers
		) ) ) );

		$links_by_customer = array();
		if ( ! empty( $ids ) ) {
			global $wpdb;
			$mapping_table = $wpdb->prefix . 'aj_auth_user_mappings';
			if ( $this->table_exists( $wpdb, $mapping_table ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT stripe_customer_id, user_id FROM `{$mapping_table}` WHERE user_id > 0 AND stripe_customer_id IN ({$placeholders})", $ids ) );
				foreach ( (array) $rows as $row ) {
					$cid = (string) $row->stripe_customer_id;
					if ( ! isset( $links_by_customer[ $cid ] ) ) {
						$links_by_customer[ $cid ] = array();
					}
					$links_by_customer[ $cid ][] = (int) $row->user_id;
				}
			}
		}

		foreach ( $customers as &$customer ) {
			$cid    = isset( $customer['stripe_customer_id'] ) ? (string) $customer['stripe_customer_id'] : '';
			$user_ids = isset( $links_by_customer[ $cid ] ) ? array_values( array_unique( $links_by_customer[ $cid ] ) ) : array();
			$customer['portal_user_id']       = 1 === count( $user_ids ) ? (int) $user_ids[0] : 0;
			$customer['portal_user_count']    = count( $user_ids );
			$customer['has_portal_login']     = 1 === count( $user_ids );
		}
		unset( $customer );

		return $customers;
	}

	/** Attaches site_uuid + site_label to customer rows from aj_portal_customer_states, which
	 *  records the site each customer's portal user belongs to (stamped on portal enable /
	 *  welcome email / status changes). One batch query, not N. */
	private function attach_customer_site_labels( $customers ) {
		$customers = (array) $customers;
		$ids       = array_values( array_unique( array_filter( array_map(
			function ( $c ) { return isset( $c['stripe_customer_id'] ) ? (string) $c['stripe_customer_id'] : ''; },
			$customers
		) ) ) );

		$site_by_customer = array();
		if ( ! empty( $ids ) ) {
			$pdb          = $this->get_portal_db();
			$states_table = $this->portal_table( 'aj_portal_customer_states' );
			if ( $this->table_exists( $pdb, $states_table ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$state_rows = $pdb->get_results( $pdb->prepare( "SELECT stripe_customer_id, site_uuid FROM `{$states_table}` WHERE stripe_customer_id IN ({$placeholders})", $ids ) );
				foreach ( (array) $state_rows as $s ) {
					$site_by_customer[ (string) $s->stripe_customer_id ] = (string) $s->site_uuid;
				}
			}
		}

		foreach ( $customers as &$c ) {
			$cid              = isset( $c['stripe_customer_id'] ) ? (string) $c['stripe_customer_id'] : '';
			$site_uuid        = isset( $site_by_customer[ $cid ] ) ? $site_by_customer[ $cid ] : '';
			$c['site_uuid']   = $site_uuid;
			$c['site_label']  = $this->get_site_label( $site_uuid );
		}
		unset( $c );

		return $customers;
	}

	public function get_ops_customer( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$is_local       = 0 === strpos( $stripe_customer_id, 'local_' );
		$customer_table = $this->portal_table( $is_local ? 'aj_portal_local_customers' : 'aj_portal_stripe_customers' );
		$desired_cols   = array( 'stripe_customer_id', 'email', 'name', 'phone', 'description', 'address', 'metadata', 'raw_data', 'portal_status', 'enabled_portal', 'partner_key', 'livemode', 'synced_at' );
		if ( $is_local ) {
			$customer = $this->table_exists( $pdb, $customer_table ) ? $pdb->get_row( $pdb->prepare( "SELECT local_customer_id AS stripe_customer_id,email,name,phone,description,address,metadata,status AS portal_status,0 AS enabled_portal,partner_key,0 AS livemode,updated_at AS synced_at FROM `{$customer_table}` WHERE local_customer_id=%s LIMIT 1", $stripe_customer_id ), ARRAY_A ) : null;
		} else {
			$select_cols = $this->table_exists( $pdb, $customer_table ) ? $this->existing_columns( $pdb, $customer_table, $desired_cols ) : array();
			$customer = ! empty( $select_cols ) ? $pdb->get_row( $pdb->prepare( 'SELECT `' . implode( '`,`', $select_cols ) . "` FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ), ARRAY_A ) : null;
		}
		if ( ! $customer ) {
			return new WP_Error( 'ajcore_customer_not_found', __( 'Customer not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$decoded = $this->format_ops_customer_row( $this->decode_json_fields( $customer, array( 'address', 'metadata' ) ) );
		$with_site = $this->attach_customer_site_labels( array( $decoded ) );
		$decoded   = $with_site[0];
		$with_portal_user = $this->attach_customer_portal_user_links( array( $decoded ) );
		$decoded          = $with_portal_user[0];
		// Build $meta from the metadata column; then overlay values from raw_data.
		// business_name / individual_name are top-level Stripe customer fields, not inside raw_data.metadata.
		$meta = is_array( $decoded['metadata'] ?? null ) ? $decoded['metadata'] : array();
		if ( ! empty( $customer['raw_data'] ) ) {
			$raw_parsed = json_decode( (string) $customer['raw_data'], true );
			if ( is_array( $raw_parsed ) ) {
				// If metadata column was empty but raw_data.metadata has content, use it.
				if ( empty( $meta ) && is_array( $raw_parsed['metadata'] ?? null ) && ! empty( $raw_parsed['metadata'] ) ) {
					$meta = $raw_parsed['metadata'];
				}
				// Stripe stores business_name / individual_name as top-level customer fields.
				if ( empty( $meta['business_name'] ) && ! empty( $raw_parsed['business_name'] ) ) {
					$meta['business_name'] = $raw_parsed['business_name'];
				}
				if ( empty( $meta['individual_name'] ) && ! empty( $raw_parsed['individual_name'] ) ) {
					$meta['individual_name'] = $raw_parsed['individual_name'];
				}
				// Backfill decoded metadata so legacy clients reading c.metadata?.business_name still work.
				if ( ! empty( $meta ) ) {
					$decoded['metadata'] = $meta;
				}
			}
		}
		$decoded['business_name']   = $meta['business_name'] ?? '';
		$decoded['individual_name'] = $meta['individual_name'] ?? '';
		unset( $decoded['raw_data'] );
		$services = array( 'subscriptions' => array(), 'one_time_services' => array() );
		if ( $is_local ) {
			$local_services = $this->portal_table( 'aj_portal_local_services' );
			$rows = $this->table_exists( $pdb, $local_services ) ? $pdb->get_results( $pdb->prepare( "SELECT * FROM `{$local_services}` WHERE local_customer_id=%s ORDER BY contract_start_date DESC,id DESC", $stripe_customer_id ), ARRAY_A ) : array();
			foreach ( (array) $rows as $row ) {
				$features = json_decode( (string) $row['features'], true );
				$services['subscriptions'][] = array(
					'service_name' => $row['service_name'], 'price' => '$' . number_format( (float) $row['monthly_rate'], 2 ) . '/' . $row['billing_interval'],
					'recurring_interval' => $row['billing_interval'], 'billing_type_key' => 'subscription', 'billing_type' => 'Local Contract',
					'stripe_price_id' => '', 'stripe_product_id' => '', 'stripe_subscription_id' => '', 'service_period' => '',
					'service_period_start' => $row['contract_start_date'], 'service_period_end' => $row['contract_end_date'], 'next_billing_date' => '',
					'status' => $row['status'], 'amount' => number_format( (float) $row['monthly_rate'], 2, '.', '' ), 'paid_at' => '', 'next_action' => '',
					'features' => is_array( $features ) ? $features : array(), 'local_only' => true,
					'local_service_id' => $row['local_service_id'], 'move_in_date' => $row['move_in_date'] ?? '',
					'billing_start_date' => $row['billing_start_date'] ?? '', 'next_charge_date' => $row['next_charge_date'] ?? '',
					'last_billed_date' => $row['last_billed_date'] ?? '', 'pending_rate' => $row['pending_rate'] ?? null,
					'pending_rate_date' => $row['pending_rate_date'] ?? '', 'notes' => $row['notes'] ?? '',
				);
			}
		} elseif ( class_exists( 'AJForms_Admin' ) ) {
			$admin = new AJForms_Admin();
			if ( method_exists( $admin, 'api_get_ops_customer_services' ) ) {
				$services = $admin->api_get_ops_customer_services( $stripe_customer_id );
			}
		}

		// $0 tracking-only services (partner-billed VO customers): stored in the local services
		// table under the cus_* id, never billed here or in Stripe.
		$tracking_services = array();
		if ( ! $is_local ) {
			$local_services_table = $this->portal_table( 'aj_portal_local_services' );
			$tracking_rows = $this->table_exists( $pdb, $local_services_table ) ? $pdb->get_results( $pdb->prepare( "SELECT * FROM `{$local_services_table}` WHERE local_customer_id=%s ORDER BY contract_start_date DESC,id DESC", $stripe_customer_id ), ARRAY_A ) : array();
			foreach ( (array) $tracking_rows as $row ) {
				$tracking_services[] = array(
					'local_service_id' => $row['local_service_id'], 'service_name' => $row['service_name'],
					'move_in_date' => $row['move_in_date'] ?? '', 'service_period_start' => $row['contract_start_date'],
					'service_period_end' => $row['contract_end_date'], 'status' => $row['status'], 'notes' => $row['notes'] ?? '',
				);
			}
		}

		// Fetch ledger (standard columns — payment_intent_id/charge_id may not exist in all installs).
		if ( $is_local ) {
			$local_ledger = $this->portal_table( 'aj_portal_local_ledger' );
			$ledger = $this->table_exists( $pdb, $local_ledger ) ? $pdb->get_results( $pdb->prepare( "SELECT id,local_customer_id AS stripe_customer_id,source_object_id,source_type,ledger_date,description,amount,currency,status,'' AS invoice_id,'' AS payment_intent_id,'' AS charge_id,metadata,created_at FROM `{$local_ledger}` WHERE local_customer_id=%s ORDER BY ledger_date ASC,id ASC", $stripe_customer_id ), ARRAY_A ) : array();
		} else {
			$ledger = $this->select_by_customer( 'aj_portal_ledger', array( 'id', 'stripe_customer_id', 'source_object_id', 'source_type', 'ledger_date', 'description', 'amount', 'currency', 'status', 'invoice_id', 'payment_intent_id', 'charge_id', 'metadata', 'created_at' ), $stripe_customer_id, 'ledger_date ASC, id ASC' );
		}
		foreach ( $ledger as &$ledger_entry ) {
			if ( isset( $ledger_entry['metadata'] ) && is_string( $ledger_entry['metadata'] ) ) {
				$metadata = json_decode( $ledger_entry['metadata'], true );
				$ledger_entry['metadata'] = is_array( $metadata ) ? $metadata : array();
			}
		}
		unset( $ledger_entry );

		// Build billing_type lookup from service snapshots: payment_intent_id / invoice_id → 'subscription' | 'one_time'.
		$snapshots = $this->select_by_customer( 'aj_portal_service_snapshots', array( 'payment_intent_id', 'invoice_id', 'billing_type' ), $stripe_customer_id, 'created_at DESC' );
		$pi_billing  = array();
		$inv_billing = array();
		foreach ( (array) $snapshots as $snap ) {
			$pi  = isset( $snap['payment_intent_id'] ) ? (string) $snap['payment_intent_id'] : '';
			$inv = isset( $snap['invoice_id'] ) ? (string) $snap['invoice_id'] : '';
			$bt  = isset( $snap['billing_type'] ) ? (string) $snap['billing_type'] : 'one_time';
			if ( '' !== $pi )  $pi_billing[ $pi ]   = $bt;
			if ( '' !== $inv ) $inv_billing[ $inv ] = $bt;
		}

		// Fetch transactions and annotate with billing_type; remove charge duplicates of invoice transactions.
		$transactions = $this->select_by_customer( 'aj_portal_stripe_transactions', array( 'id', 'stripe_object_id', 'object_type', 'stripe_customer_id', 'description', 'amount', 'currency', 'status', 'transaction_date', 'due_date', 'invoice_id', 'payment_intent_id', 'charge_id', 'livemode', 'synced_at', 'raw_data' ), $stripe_customer_id, 'transaction_date DESC, id DESC' );

		$transactions = $this->dedupe_stripe_transaction_rows( $transactions );
		$transactions = $this->attach_payment_display_fields( $transactions );

		foreach ( $transactions as &$tx ) {
			$pi  = isset( $tx['payment_intent_id'] ) ? (string) $tx['payment_intent_id'] : '';
			$inv = isset( $tx['stripe_object_id'] ) ? (string) $tx['stripe_object_id'] : '';
			if ( '' !== $pi && isset( $pi_billing[ $pi ] ) ) {
				$tx['billing_type'] = $pi_billing[ $pi ];
			} elseif ( '' !== $inv && isset( $inv_billing[ $inv ] ) ) {
				$tx['billing_type'] = $inv_billing[ $inv ];
			} else {
				$tx['billing_type'] = '';
			}
		}
		unset( $tx );

		return rest_ensure_response(
			array(
				'customer'         => $decoded,
				'profile'          => $this->get_customer_profile_row( $stripe_customer_id ),
				'tracking_services' => $tracking_services,
				'services'         => $services,
				'subscriptions'    => $this->select_by_customer( 'aj_portal_stripe_subscriptions', array( 'stripe_subscription_id', 'stripe_customer_id', 'status', 'current_period_end', 'cancel_at_period_end', 'items', 'livemode', 'synced_at' ), $stripe_customer_id, 'synced_at DESC, id DESC' ),
				'transactions'     => $transactions,
				'ledger'           => $ledger,
				'service_requests' => $this->select_by_customer( 'aj_portal_service_requests', array( 'id', 'stripe_customer_id', 'title', 'status', 'service_status', 'amount', 'currency', 'created_at', 'updated_at' ), $stripe_customer_id, 'updated_at DESC, id DESC' ),
			)
		);
	}

	public function ops_import_local_customer_ledger( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$csv         = (string) $request->get_param( 'csv' );
		$filename    = sanitize_file_name( (string) $request->get_param( 'filename' ) );

		if ( '' === trim( $csv ) ) {
			return new WP_Error( 'ajcore_empty_ledger', __( 'Choose a non-empty CSV ledger.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$customer_table = $this->portal_table( 'aj_portal_local_customers' );
		if ( ! $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$customer_table}` WHERE local_customer_id = %s LIMIT 1", $customer_id ) ) ) {
			return new WP_Error( 'ajcore_customer_not_found', __( 'Customer not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );
		$headers = fgetcsv( $handle, 0, ',', '"', '\\' );
		$headers = array_map( function ( $header ) { return strtolower( trim( (string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF" ) ); }, (array) $headers );
		$required = array( 'date', 'category', 'description', 'memo', 'amount' );
		foreach ( $required as $column ) {
			if ( ! in_array( $column, $headers, true ) ) {
				fclose( $handle );
				return new WP_Error( 'ajcore_invalid_ledger_columns', sprintf( __( 'The CSV is missing the %s column.', 'ajforms' ), $column ), array( 'status' => 400 ) );
			}
		}

		$table    = $this->portal_table( 'aj_portal_local_ledger' );
		$inserted = 0;
		$skipped  = 0;
		$invalid  = 0;
		$row_num  = 1;
		while ( false !== ( $values = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			$row_num++;
			if ( count( array_filter( $values, 'strlen' ) ) === 0 ) continue;
			$values = array_pad( $values, count( $headers ), '' );
			$row    = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
			$ts     = strtotime( (string) $row['date'] );
			$amount = (float) str_replace( array( '$', ',' ), '', (string) $row['amount'] );
			if ( ! $ts || ! is_numeric( str_replace( array( '$', ',', ' ' ), '', (string) $row['amount'] ) ) ) { $invalid++; continue; }
			$description = sanitize_text_field( $row['description'] ?: ( $row['memo'] ?: $row['category'] ) );
			$fingerprint = hash( 'sha256', implode( '|', array( $customer_id, gmdate( 'Y-m-d', $ts ), $row['check num'] ?? '', $row['category'], $row['description'], $row['memo'], number_format( $amount, 2, '.', '' ) ) ) );
			$source_id   = 'local_csv_' . substr( $fingerprint, 0, 54 );
			if ( $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$table}` WHERE source_object_id = %s LIMIT 1", $source_id ) ) ) { $skipped++; continue; }
			$metadata = array(
				'import_type' => 'local_customer_csv', 'filename' => $filename, 'row_number' => $row_num,
				'check_num' => sanitize_text_field( $row['check num'] ?? '' ), 'category' => sanitize_text_field( $row['category'] ),
				'optional' => sanitize_text_field( $row['optional'] ?? '' ), 'memo' => sanitize_text_field( $row['memo'] ),
			);
			$result = $pdb->insert( $table, array(
				'local_customer_id' => $customer_id, 'source_object_id' => $source_id,
				'source_type' => $amount < 0 ? 'local_charge' : 'local_payment', 'ledger_date' => gmdate( 'Y-m-d 00:00:00', $ts ),
				'description' => $description, 'amount' => $amount, 'currency' => 'usd', 'status' => 'posted',
				'metadata' => wp_json_encode( $metadata ),
			), array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ) );
			false === $result ? $invalid++ : $inserted++;
		}
		fclose( $handle );
		return rest_ensure_response( array( 'success' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'invalid' => $invalid ) );
	}

	public function ops_add_local_customer_charge( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$description = sanitize_text_field( (string) $request->get_param( 'description' ) );
		$category    = sanitize_text_field( (string) ( $request->get_param( 'category' ) ?: 'Additional Service' ) );
		$date        = sanitize_text_field( (string) ( $request->get_param( 'date' ) ?: current_time( 'Y-m-d' ) ) );
		$amount      = round( abs( (float) $request->get_param( 'amount' ) ), 2 );
		$entry_type  = sanitize_key( (string) ( $request->get_param( 'entry_type' ) ?: 'charge' ) );
		$is_payment  = 'payment' === $entry_type;
		if ( '' === $description || $amount <= 0 || ! strtotime( $date ) ) {
			return new WP_Error( 'ajcore_invalid_local_charge', __( 'Description, valid date, and an amount greater than zero are required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$customers = $this->portal_table( 'aj_portal_local_customers' );
		if ( ! $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$customers}` WHERE local_customer_id=%s LIMIT 1", $customer_id ) ) ) {
			return new WP_Error( 'ajcore_customer_not_found', __( 'Customer not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$table = $this->portal_table( 'aj_portal_local_ledger' );
		$source_id = 'local_manual_' . str_replace( '-', '', wp_generate_uuid4() );
		$inserted = $pdb->insert( $table, array(
			'local_customer_id' => $customer_id, 'source_object_id' => $source_id, 'source_type' => $is_payment ? 'local_payment' : 'local_charge',
			'ledger_date' => gmdate( 'Y-m-d 00:00:00', strtotime( $date ) ), 'description' => $description,
			'amount' => $is_payment ? $amount : -$amount, 'currency' => 'usd', 'status' => 'posted',
			'metadata' => wp_json_encode( array( 'entry_type' => $is_payment ? 'manual_payment' : 'manual_charge', 'category' => $category, 'created_by' => get_current_user_id() ) ),
		), array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ) );
		if ( false === $inserted ) return new WP_Error( 'ajcore_local_charge_failed', __( 'The charge could not be saved.', 'ajforms' ), array( 'status' => 500 ) );
		return rest_ensure_response( array( 'success' => true, 'id' => (int) $pdb->insert_id ) );
	}

	public function ops_update_local_ledger_entry( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$table = $this->portal_table( 'aj_portal_local_ledger' );
		$customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$id = absint( $request->get_param( 'entry_id' ) );
		$row = $pdb->get_row( $pdb->prepare( "SELECT amount,metadata FROM `{$table}` WHERE id=%d AND local_customer_id=%s LIMIT 1", $id, $customer_id ), ARRAY_A );
		if ( ! $row ) return new WP_Error( 'ajcore_ledger_entry_not_found', __( 'Ledger entry not found.', 'ajforms' ), array( 'status' => 404 ) );
		$date = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$description = sanitize_text_field( (string) $request->get_param( 'description' ) );
		$category = sanitize_text_field( (string) $request->get_param( 'category' ) );
		$amount = round( abs( (float) $request->get_param( 'amount' ) ), 2 );
		$entry_type = sanitize_key( (string) ( $request->get_param( 'entry_type' ) ?: ( (float) $row['amount'] < 0 ? 'charge' : 'payment' ) ) );
		if ( ! strtotime( $date ) || '' === $description || $amount <= 0 ) return new WP_Error( 'ajcore_invalid_ledger_entry', __( 'Date, description, and an amount greater than zero are required.', 'ajforms' ), array( 'status' => 400 ) );
		$metadata = json_decode( (string) $row['metadata'], true );
		if ( ! is_array( $metadata ) ) $metadata = array();
		$metadata['category'] = $category;
		$result = $pdb->update( $table, array( 'ledger_date' => gmdate( 'Y-m-d 00:00:00', strtotime( $date ) ), 'description' => $description, 'amount' => 'payment' === $entry_type ? $amount : -$amount, 'metadata' => wp_json_encode( $metadata ) ), array( 'id' => $id, 'local_customer_id' => $customer_id ) );
		return false === $result ? new WP_Error( 'ajcore_ledger_update_failed', __( 'Ledger entry could not be updated.', 'ajforms' ), array( 'status' => 500 ) ) : rest_ensure_response( array( 'success' => true ) );
	}

	public function ops_delete_local_ledger_entry( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$deleted = $pdb->delete( $this->portal_table( 'aj_portal_local_ledger' ), array( 'id' => absint( $request->get_param( 'entry_id' ) ), 'local_customer_id' => sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ) ), array( '%d', '%s' ) );
		return false === $deleted ? new WP_Error( 'ajcore_ledger_delete_failed', __( 'Ledger entry could not be deleted.', 'ajforms' ), array( 'status' => 500 ) ) : rest_ensure_response( array( 'success' => true ) );
	}

	public function ops_upsert_local_customer_service( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$name        = sanitize_text_field( (string) $request->get_param( 'service_name' ) );
		$start       = sanitize_text_field( (string) $request->get_param( 'contract_start_date' ) );
		$end         = sanitize_text_field( (string) $request->get_param( 'contract_end_date' ) );
		$move_in     = sanitize_text_field( (string) ( $request->get_param( 'move_in_date' ) ?: $start ) );
		$billing_start = sanitize_text_field( (string) ( $request->get_param( 'billing_start_date' ) ?: $start ) );
		$amount      = round( (float) $request->get_param( 'monthly_rate' ), 2 );
		$service_id  = sanitize_text_field( (string) $request->get_param( 'local_service_id' ) );
		$action      = sanitize_key( (string) ( $request->get_param( 'action' ) ?: 'save' ) );
		$status      = sanitize_key( (string) ( $request->get_param( 'status' ) ?: 'active' ) );
		$features_param = $request->get_param( 'features' );
		$features    = array_values( array_filter( array_map( 'sanitize_text_field', (array) $features_param ) ) );
		$is_tracking_only = 0 === strpos( $customer_id, 'cus_' );
		if ( $is_tracking_only ) {
			$customers = $this->portal_table( 'aj_portal_stripe_customers' );
			$customer  = $this->table_exists( $pdb, $customers ) ? $pdb->get_row( $pdb->prepare( "SELECT email FROM `{$customers}` WHERE stripe_customer_id = %s LIMIT 1", $customer_id ) ) : null;
		} else {
			$customers = $this->portal_table( 'aj_portal_local_customers' );
			$customer  = $pdb->get_row( $pdb->prepare( "SELECT email FROM `{$customers}` WHERE local_customer_id = %s LIMIT 1", $customer_id ) );
		}
		if ( ! $customer ) return new WP_Error( 'ajcore_customer_not_found', __( 'Customer not found.', 'ajforms' ), array( 'status' => 404 ) );
		$table = $this->portal_table( 'aj_portal_local_services' );
		if ( 'delete' === $action ) {
			if ( '' === $service_id ) return new WP_Error( 'ajcore_invalid_local_service', __( 'A local service ID is required.', 'ajforms' ), array( 'status' => 400 ) );
			// End rather than delete: keep the row so the service period stays on record.
			$end_data = array( 'status' => 'cancelled', 'next_charge_date' => null );
			if ( $is_tracking_only ) {
				$open_ended = $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$table}` WHERE local_customer_id=%s AND local_service_id=%s AND contract_end_date IS NULL LIMIT 1", $customer_id, $service_id ) );
				if ( $open_ended ) $end_data['contract_end_date'] = gmdate( 'Y-m-d' );
			}
			$deleted = $pdb->update( $table, $end_data, array( 'local_customer_id' => $customer_id, 'local_service_id' => $service_id ) );
			return false === $deleted ? new WP_Error( 'ajcore_local_service_delete_failed', __( 'The recurring transaction could not be cancelled.', 'ajforms' ), array( 'status' => 500 ) ) : rest_ensure_response( array( 'success' => true, 'history_retained' => true ) );
		}
		if ( '' === $name || ! strtotime( $start ) || ! strtotime( $move_in ) || ( ! $is_tracking_only && ! strtotime( $billing_start ) ) || ( '' !== $end && ! strtotime( $end ) ) || $amount < 0 ) {
			return new WP_Error( 'ajcore_invalid_local_service', $is_tracking_only ? __( 'Service name, move-in, and service start are required.', 'ajforms' ) : __( 'Service name, move-in, service start, charges begin, and a valid monthly rate are required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$key  = $service_id ?: 'local_service_' . substr( hash( 'sha256', $customer_id . '|' . strtolower( $name ) ), 0, 52 );
		$data = array(
			'local_service_id' => $key, 'local_customer_id' => $customer_id, 'service_name' => $name,
			'contract_start_date' => gmdate( 'Y-m-d', strtotime( $start ) ), 'contract_end_date' => $end ? gmdate( 'Y-m-d', strtotime( $end ) ) : null,
			'move_in_date' => gmdate( 'Y-m-d', strtotime( $move_in ) ), 'billing_start_date' => $is_tracking_only ? null : gmdate( 'Y-m-d', strtotime( $billing_start ) ),
			'monthly_rate' => $is_tracking_only ? 0 : $amount, 'currency' => 'usd',
			'billing_interval' => 'month', 'variable_charges' => wp_json_encode( $is_tracking_only ? array() : array( 'postage' ) ),
			'status' => in_array( $status, array( 'active', 'paused', 'cancelled' ), true ) ? $status : 'active',
			'notes' => sanitize_textarea_field( (string) ( $request->get_param( 'notes' ) ?? ( $is_tracking_only ? 'Tracking-only service. Billed by the partner; never billed here or in Stripe.' : 'Reporting-only local AJCore contract.' ) ) ),
		);
		if ( null !== $features_param ) $data['features'] = wp_json_encode( $features );
		$existing = $pdb->get_row( $pdb->prepare( "SELECT id,next_charge_date,last_billed_date FROM `{$table}` WHERE local_service_id = %s AND local_customer_id = %s LIMIT 1", $key, $customer_id ), ARRAY_A );
		$exists = ! empty( $existing['id'] );
		if ( $is_tracking_only ) $data['next_charge_date'] = null;
		elseif ( ! $exists || ( empty( $existing['next_charge_date'] ) && empty( $existing['last_billed_date'] ) ) ) $data['next_charge_date'] = gmdate( 'Y-m-d', strtotime( $billing_start ) );
		$new_rate = $request->get_param( 'new_rate' );
		$new_rate_date = sanitize_text_field( (string) $request->get_param( 'new_rate_date' ) );
		if ( $exists && null !== $new_rate && '' !== (string) $new_rate && strtotime( $new_rate_date ) ) {
			$data['pending_rate'] = round( (float) $new_rate, 2 );
			$data['pending_rate_date'] = gmdate( 'Y-m-d', strtotime( $new_rate_date ) );
			if ( $request->get_param( 'update_accounting' ) ) {
				$ledger = $this->portal_table( 'aj_portal_local_ledger' );
				$like = '%"local_service_id":"' . $pdb->esc_like( $key ) . '"%';
				$pdb->query( $pdb->prepare( "UPDATE `{$ledger}` SET amount=%f WHERE local_customer_id=%s AND source_type='local_recurring_charge' AND ledger_date >= %s AND metadata LIKE %s", -abs( (float) $new_rate ), $customer_id, gmdate( 'Y-m-d 00:00:00', strtotime( $new_rate_date ) ), $like ) );
			}
		}
		$result = $exists ? $pdb->update( $table, $data, array( 'local_service_id' => $key, 'local_customer_id' => $customer_id ) ) : $pdb->insert( $table, $data );
		if ( false === $result ) return new WP_Error( 'ajcore_local_service_save_failed', __( 'The local service could not be saved.', 'ajforms' ), array( 'status' => 500 ) );
		return rest_ensure_response( array( 'success' => true, 'snapshot_key' => $key ) );
	}

	public function get_ops_local_recurring_transactions() {
		$pdb = $this->get_portal_db();
		$services = $this->portal_table( 'aj_portal_local_services' );
		$customers = $this->portal_table( 'aj_portal_local_customers' );
		// Exclude $0 tracking-only rows (cus_* customers) — they never generate recurring charges.
		$rows = $pdb->get_results( "SELECT s.local_service_id,s.local_customer_id,s.service_name,s.move_in_date,s.contract_start_date,s.contract_end_date,s.billing_start_date,s.next_charge_date,s.last_billed_date,s.monthly_rate,s.pending_rate,s.pending_rate_date,s.status,c.name AS customer_name,c.email FROM `{$services}` s LEFT JOIN `{$customers}` c ON c.local_customer_id=s.local_customer_id WHERE NOT (s.monthly_rate = 0 AND s.billing_start_date IS NULL) ORDER BY s.status='active' DESC,s.next_charge_date ASC,c.name ASC", ARRAY_A );
		$history = array();
		$ledger = $this->portal_table( 'aj_portal_local_ledger' );
		$posted = $pdb->get_results( "SELECT id,ledger_date,description,amount,currency,status,metadata FROM `{$ledger}` WHERE source_type='local_recurring_charge' ORDER BY ledger_date DESC,id DESC", ARRAY_A );
		foreach ( (array) $posted as $entry ) { $meta = json_decode( (string) $entry['metadata'], true ); $service_id = is_array( $meta ) ? ( $meta['local_service_id'] ?? '' ) : ''; unset( $entry['metadata'] ); if ( $service_id ) $history[ $service_id ][] = $entry; }
		foreach ( $rows as &$row ) { $row['posting_history'] = $history[ $row['local_service_id'] ] ?? array(); $row['posted_charge_count'] = count( $row['posting_history'] ); }
		unset( $row );
		return rest_ensure_response( array( 'transactions' => is_array( $rows ) ? $rows : array(), 'last_run' => get_option( 'ajcore_local_recurring_transactions_last_run', '' ) ) );
	}

	public function get_ops_accounting_catalog() {
		return rest_ensure_response( array(
			'charge_categories' => get_option( 'ajcore_local_charge_categories', array( 'Rent', 'Postage', 'Conference Room', 'Printing', 'Additional Service' ) ),
			'payment_categories' => get_option( 'ajcore_local_payment_categories', array( 'Rental Income', 'Service Income', 'Reimbursement', 'Uncategorized Income' ) ),
		) );
	}

	public function save_ops_accounting_catalog( WP_REST_Request $request ) {
		$clean = static function ( $values ) { return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $values ) ) ) ); };
		$charges = $clean( $request->get_param( 'charge_categories' ) );
		$payments = $clean( $request->get_param( 'payment_categories' ) );
		update_option( 'ajcore_local_charge_categories', $charges );
		update_option( 'ajcore_local_payment_categories', $payments );
		return rest_ensure_response( array( 'success' => true, 'charge_categories' => $charges, 'payment_categories' => $payments ) );
	}

	public function get_ops_products( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'products' => $this->select_rows( $this->portal_table( 'aj_portal_stripe_products' ), array( 'stripe_product_id', 'stripe_price_id', 'name', 'description', 'price_amount', 'currency', 'recurring_interval', 'active', 'visibility', 'custom_label', 'sort_order', 'livemode', 'synced_at' ), $request, array( 'name', 'stripe_product_id', 'stripe_price_id' ), 'sort_order ASC, name ASC, id DESC' ) ) );
	}

	public function get_ops_subscriptions( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'subscriptions' => $this->select_rows( $this->portal_table( 'aj_portal_stripe_subscriptions' ), array( 'stripe_subscription_id', 'stripe_customer_id', 'status', 'current_period_end', 'cancel_at_period_end', 'livemode', 'synced_at' ), $request, array( 'stripe_subscription_id', 'stripe_customer_id', 'status' ), 'synced_at DESC, id DESC' ) ) );
	}

	public function ops_create_customer_invoice( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'ajcore_admin_unavailable', __( 'AJCore admin services are unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$admin = new AJForms_Admin();
		if ( ! method_exists( $admin, 'api_create_ops_customer_invoice' ) ) {
			return new WP_Error( 'ajcore_invoice_unavailable', __( 'Invoice creation is unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$result = $admin->api_create_ops_customer_invoice(
			sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ),
			array(
				'items'          => $request->get_param( 'items' ),
				'mode'           => sanitize_key( (string) $request->get_param( 'mode' ) ),
				'days_until_due' => absint( $request->get_param( 'days_until_due' ) ),
				'send_email'     => ! empty( $request->get_param( 'send_email' ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result );
	}

	public function ops_create_customer_subscription( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'ajcore_admin_unavailable', __( 'AJCore admin services are unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}

		$admin = new AJForms_Admin();
		if ( ! method_exists( $admin, 'api_create_ops_customer_subscription' ) ) {
			return new WP_Error( 'ajcore_subscription_unavailable', __( 'Subscription creation is unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}

		$result = $admin->api_create_ops_customer_subscription(
			sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ),
			array(
				'price_id'          => sanitize_text_field( (string) $request->get_param( 'price_id' ) ),
				'quantity'          => absint( $request->get_param( 'quantity' ) ),
				'collection_method' => sanitize_key( (string) $request->get_param( 'collection_method' ) ),
				'days_until_due'    => absint( $request->get_param( 'days_until_due' ) ),
				'trial_days'        => absint( $request->get_param( 'trial_days' ) ),
				'service_start_date' => sanitize_text_field( (string) $request->get_param( 'service_start_date' ) ),
				'service_end_date'  => sanitize_text_field( (string) $request->get_param( 'service_end_date' ) ),
				'billing_start_date' => sanitize_text_field( (string) $request->get_param( 'billing_start_date' ) ),
				'prorate_first_period' => ! empty( $request->get_param( 'prorate_first_period' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}

	public function get_ops_ledger( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$t_ledger    = $this->portal_table( 'aj_portal_ledger' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );

		if ( ! $this->table_exists( $pdb, $t_ledger ) ) {
			return rest_ensure_response( array( 'ledger' => array(), 'stats' => array( 'total' => 0, 'charges' => 0, 'payments' => 0, 'balance' => 0 ) ) );
		}

		$search      = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status      = sanitize_key( (string) $request->get_param( 'status' ) );
		$source_type = sanitize_key( (string) $request->get_param( 'source_type' ) );
		$customer    = sanitize_text_field( (string) $request->get_param( 'customer' ) );
		$limit       = min( 500, max( 1, absint( $request->get_param( 'per_page' ) ?: 300 ) ) );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $customer ) {
			$where[]  = 'l.stripe_customer_id = %s';
			$params[] = $customer;
		}
		if ( '' !== $status ) {
			$where[]  = 'l.status = %s';
			$params[] = $status;
		}
		if ( '' !== $source_type ) {
			$where[]  = 'l.source_type = %s';
			$params[] = $source_type;
		}
		if ( '' !== $search ) {
			$like     = '%' . $pdb->esc_like( $search ) . '%';
			$where[]  = '(l.description LIKE %s OR l.stripe_customer_id LIKE %s OR l.invoice_id LIKE %s OR c.email LIKE %s OR c.name LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT l.id, l.stripe_customer_id, l.source_object_id, l.source_type, l.ledger_date, l.description, l.amount, l.currency, l.status, l.invoice_id, l.payment_intent_id, l.charge_id, l.created_at,
			c.name AS customer_name, c.email AS customer_email
			FROM `{$t_ledger}` l
			LEFT JOIN `{$t_customers}` c ON c.stripe_customer_id = l.stripe_customer_id
			WHERE {$where_sql}
			ORDER BY l.ledger_date DESC, l.id DESC
			LIMIT %d";

		$params_with_limit = array_merge( $params, array( $limit ) );
		$rows = $pdb->get_results( $pdb->prepare( $sql, $params_with_limit ) );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$ledger = array();
		foreach ( $rows as $r ) {
			$ledger[] = array(
				'id'                => (int) $r->id,
				'stripe_customer_id' => (string) $r->stripe_customer_id,
				'source_object_id'  => (string) $r->source_object_id,
				'source_type'       => (string) $r->source_type,
				'ledger_date'       => (string) $r->ledger_date,
				'description'       => (string) $r->description,
				'amount'            => (float) $r->amount,
				'currency'          => (string) $r->currency,
				'status'            => (string) $r->status,
				'invoice_id'        => (string) $r->invoice_id,
				'payment_intent_id' => (string) $r->payment_intent_id,
				'charge_id'         => (string) $r->charge_id,
				'created_at'        => (string) $r->created_at,
				'customer_name'     => (string) $r->customer_name,
				'customer_email'    => (string) $r->customer_email,
			);
		}

		// Global stats (not affected by current filter) — same KPIs as the WP admin Billing tab.
		$total = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$t_ledger}`" );
		$kpis  = array( 'records' => $total, 'open_balance' => 0.0, 'credit_balance' => 0.0, 'paid_total' => 0.0 );
		if ( class_exists( 'AJForms_Admin' ) ) {
			$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
			$kpis  = $admin->get_ops_billing_stats();
		}

		return rest_ensure_response( array(
			'ledger' => $ledger,
			'stats'  => array(
				'total'          => $total,
				// Legacy keys kept for older clients: charges = billed, payments = paid, balance = open.
				'charges'        => round( $kpis['paid_total'] + $kpis['open_balance'], 2 ),
				'payments'       => $kpis['paid_total'],
				'balance'        => $kpis['open_balance'],
				'open_balance'   => $kpis['open_balance'],
				'credit_balance' => $kpis['credit_balance'],
				'paid_total'     => $kpis['paid_total'],
			),
		) );
	}

	public function get_ops_transactions( WP_REST_Request $request ) {
		$transactions = $this->select_rows( $this->portal_table( 'aj_portal_stripe_transactions' ), array( 'id', 'stripe_object_id', 'object_type', 'stripe_customer_id', 'description', 'amount', 'currency', 'status', 'transaction_date', 'due_date', 'invoice_id', 'payment_intent_id', 'charge_id', 'livemode', 'synced_at', 'raw_data' ), $request, array( 'stripe_customer_id', 'description', 'status', 'object_type', 'stripe_object_id' ), 'transaction_date DESC, id DESC' );
		$transactions = $this->dedupe_stripe_transaction_rows( $transactions );
		$transactions = $this->attach_payment_display_fields( $transactions );

		// Attach customer name/email for display.
		$pdb            = $this->get_portal_db();
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$customers      = array();
		if ( $this->table_exists( $pdb, $customer_table ) ) {
			foreach ( (array) $pdb->get_results( "SELECT stripe_customer_id, name, email FROM `{$customer_table}`", ARRAY_A ) as $c ) {
				$customers[ (string) $c['stripe_customer_id'] ] = $c;
			}
		}
		foreach ( $transactions as &$tx ) {
			$cid = isset( $tx['stripe_customer_id'] ) ? (string) $tx['stripe_customer_id'] : '';
			$tx['customer_name']  = isset( $customers[ $cid ] ) ? (string) $customers[ $cid ]['name'] : '';
			$tx['customer_email'] = isset( $customers[ $cid ] ) ? (string) $customers[ $cid ]['email'] : '';
		}
		unset( $tx );

		return rest_ensure_response( array( 'transactions' => $transactions ) );
	}

	/**
	 * One Stripe payment can surface as several synced objects (invoice, charge, checkout_session).
	 * Keep the most descriptive record per payment and drop the duplicate representations.
	 */
	public function dedupe_stripe_transaction_rows( $transactions ) {
		$transactions = is_array( $transactions ) ? $transactions : array();

		// Refunds are not payments: like Stripe's own Payments list, the refund shows as a
		// "Refunded" badge on the original payment row (attach_payment_display_fields() derives
		// it from the charge). Listing the refund object as its own row duplicates the payment
		// and double-counts the Refunded total.
		$transactions = array_values( array_filter( $transactions, function( $tx ) {
			return 'refund' !== strtolower( isset( $tx['object_type'] ) ? (string) $tx['object_type'] : '' );
		} ) );

		// Build sets from invoice transactions so their duplicate charge records can be removed.
		// A charge is a duplicate when: its invoice_id matches an invoice's stripe_object_id (primary),
		// OR its payment_intent_id matches an invoice's payment_intent_id (fallback).
		$invoice_obj_set = array();
		$invoice_pi_set  = array();
		foreach ( $transactions as $tx ) {
			if ( 'invoice' === strtolower( isset( $tx['object_type'] ) ? (string) $tx['object_type'] : '' ) ) {
				$obj = isset( $tx['stripe_object_id'] ) ? (string) $tx['stripe_object_id'] : '';
				$pi  = isset( $tx['payment_intent_id'] ) ? (string) $tx['payment_intent_id'] : '';
				if ( '' !== $obj ) $invoice_obj_set[ $obj ] = true;
				if ( '' !== $pi )  $invoice_pi_set[ $pi ]   = true;
			}
		}

		// "Payment for Invoice" / "Subscription creation" / "Subscription update" are Stripe's default
		// descriptions for charges generated by invoice payments. Stripe API 2025-03-31+ (Basil) no longer
		// exposes invoice/payment_intent cross-links on these objects, so the ID matching below finds
		// nothing for newly synced data — match by description as well.
		$generic_charge_descs = array( 'payment for invoice', 'subscription creation', 'subscription update' );

		$transactions = array_values( array_filter( $transactions, function( $tx ) use ( $invoice_obj_set, $invoice_pi_set, $generic_charge_descs ) {
			if ( 'charge' !== strtolower( isset( $tx['object_type'] ) ? (string) $tx['object_type'] : '' ) ) {
				return true;
			}
			// Drop by generic description — always a duplicate of the invoice.
			$desc = strtolower( trim( isset( $tx['description'] ) ? (string) $tx['description'] : '' ) );
			if ( in_array( $desc, $generic_charge_descs, true ) ) return false;
			// Also drop by ID if populated.
			$inv_id = isset( $tx['invoice_id'] ) ? (string) $tx['invoice_id'] : '';
			$pi     = isset( $tx['payment_intent_id'] ) ? (string) $tx['payment_intent_id'] : '';
			if ( '' !== $inv_id && isset( $invoice_obj_set[ $inv_id ] ) ) return false;
			if ( '' !== $pi && isset( $invoice_pi_set[ $pi ] ) )           return false;
			return true;
		} ) );

		// A checkout_session row is a receipt for a payment already represented by an invoice or
		// payment_intent transaction — drop it when the object it links to survived the filtering above.
		// Also drop sessions that were never completed (abandoned/expired carts): Stripe itself does
		// not list those under Payments, only as customer events.
		$paid_session_statuses = array( 'paid', 'complete', 'completed', 'succeeded' );
		$linked_ids = array();
		foreach ( $transactions as $tx ) {
			if ( 'checkout_session' === strtolower( isset( $tx['object_type'] ) ? (string) $tx['object_type'] : '' ) ) {
				continue;
			}
			$obj = isset( $tx['stripe_object_id'] ) ? (string) $tx['stripe_object_id'] : '';
			$pi  = isset( $tx['payment_intent_id'] ) ? (string) $tx['payment_intent_id'] : '';
			if ( '' !== $obj ) $linked_ids[ $obj ] = true;
			if ( '' !== $pi )  $linked_ids[ $pi ]  = true;
		}
		return array_values( array_filter( $transactions, function( $tx ) use ( $linked_ids, $paid_session_statuses ) {
			if ( 'checkout_session' !== strtolower( isset( $tx['object_type'] ) ? (string) $tx['object_type'] : '' ) ) {
				return true;
			}
			if ( ! in_array( sanitize_key( isset( $tx['status'] ) ? (string) $tx['status'] : '' ), $paid_session_statuses, true ) ) {
				return false;
			}
			$inv_id = isset( $tx['invoice_id'] ) ? (string) $tx['invoice_id'] : '';
			$pi     = isset( $tx['payment_intent_id'] ) ? (string) $tx['payment_intent_id'] : '';
			if ( '' !== $inv_id && isset( $linked_ids[ $inv_id ] ) ) return false;
			if ( '' !== $pi && isset( $linked_ids[ $pi ] ) )          return false;
			return true;
		} ) );
	}

	private function stripe_minor_to_decimal( $amount, $currency ) {
		$zero_decimal = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
		$divisor      = in_array( strtolower( (string) $currency ), $zero_decimal, true ) ? 1 : 100;
		return round( ( (float) $amount ) / $divisor, 2 );
	}

	/**
	 * Human label for how a charge was paid, from the charge's payment_method_details.
	 */
	private function payment_method_label_from_charge( $charge ) {
		$pmd  = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] ) ? $charge['payment_method_details'] : array();
		$type = isset( $pmd['type'] ) ? (string) $pmd['type'] : '';
		if ( 'card' === $type ) {
			$card   = isset( $pmd['card'] ) && is_array( $pmd['card'] ) ? $pmd['card'] : array();
			$wallet = isset( $card['wallet']['type'] ) ? (string) $card['wallet']['type'] : '';
			$brand  = isset( $card['brand'] ) ? ucwords( str_replace( '_', ' ', (string) $card['brand'] ) ) : 'Card';
			$last4  = isset( $card['last4'] ) ? (string) $card['last4'] : '';
			$label  = trim( $brand . ( '' !== $last4 ? ' •••• ' . $last4 : '' ) );
			if ( 'link' === $wallet ) {
				$label = 'Link (' . $label . ')';
			} elseif ( 'apple_pay' === $wallet ) {
				$label = 'Apple Pay (' . $label . ')';
			} elseif ( 'google_pay' === $wallet ) {
				$label = 'Google Pay (' . $label . ')';
			}
			return $label;
		}
		if ( 'link' === $type ) {
			return 'Link';
		}
		if ( 'us_bank_account' === $type || 'ach_debit' === $type ) {
			$last4 = isset( $pmd[ $type ]['last4'] ) ? (string) $pmd[ $type ]['last4'] : '';
			return trim( 'Bank account' . ( '' !== $last4 ? ' •••• ' . $last4 : '' ) );
		}
		if ( 'cashapp' === $type ) {
			return 'Cash App Pay';
		}
		return '' !== $type ? ucwords( str_replace( '_', ' ', $type ) ) : '';
	}

	/**
	 * Business-friendly "what was purchased" from an invoice's line items.
	 * Stripe line descriptions look like "1 × Registered Agent (at $98.00 / year)".
	 */
	/**
	 * Strip Stripe's quantity/pricing decoration: "1 × Registered Agent (at $98.00 / year)"
	 * → "Registered Agent". Works mid-string too ("Free trial for 1 × Registered Agent").
	 */
	private function clean_line_item_name( $name ) {
		$name = preg_replace( '/\s*\(at\s[^)]*\)\s*/u', ' ', (string) $name );
		$name = preg_replace( '/(^|\s)\d+\s*×\s*/u', '$1', $name );
		$name = preg_replace( '/^\s*\d+\s*x\s+/i', '', $name );
		return trim( preg_replace( '/\s{2,}/', ' ', $name ) );
	}

	private function service_name_from_lines( $lines ) {
		$names = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_array( $line ) || empty( $line['description'] ) ) {
				continue;
			}
			$name = $this->clean_line_item_name( (string) $line['description'] );
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Attach staff-facing display fields (payment_method, refund_status, refunded_amount,
	 * refunded_at, service_name) derived from synced Stripe raw_data. Rows must include
	 * raw_data; it is removed from every row before returning.
	 *
	 * Invoices don't carry payment/refund details themselves — those live on the charge —
	 * so charges for the same customers are loaded and matched by ID links first, then by
	 * (customer, amount, nearest date) because Stripe API 2025-03-31+ no longer exposes
	 * invoice/payment_intent cross-links on charge objects.
	 */
	public function attach_payment_display_fields( $transactions ) {
		$transactions = is_array( $transactions ) ? $transactions : array();
		if ( empty( $transactions ) ) {
			return $transactions;
		}

		$customer_ids = array();
		foreach ( $transactions as $tx ) {
			$cid = isset( $tx['stripe_customer_id'] ) ? (string) $tx['stripe_customer_id'] : '';
			if ( '' !== $cid ) {
				$customer_ids[ $cid ] = true;
			}
		}

		// Load all charge rows for these customers (dedupe hides them from the list, but
		// they're still synced and carry payment_method_details + refund state).
		$by_charge_id = array();
		$by_invoice   = array();
		$by_pi        = array();
		$by_customer  = array();
		if ( ! empty( $customer_ids ) ) {
			$pdb          = $this->get_portal_db();
			$table        = $this->portal_table( 'aj_portal_stripe_transactions' );
			$placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%s' ) );
			$charge_rows  = (array) $pdb->get_results(
				$pdb->prepare(
					"SELECT stripe_object_id, stripe_customer_id, invoice_id, payment_intent_id, amount, transaction_date, raw_data FROM `{$table}` WHERE object_type = 'charge' AND stripe_customer_id IN ({$placeholders})",
					array_keys( $customer_ids )
				),
				ARRAY_A
			);
			foreach ( $charge_rows as $row ) {
				$raw = ! empty( $row['raw_data'] ) ? json_decode( (string) $row['raw_data'], true ) : null;
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$entry = array(
					'raw'    => $raw,
					'amount' => (float) $row['amount'],
					'ts'     => ! empty( $row['transaction_date'] ) ? strtotime( (string) $row['transaction_date'] ) : 0,
				);
				if ( ! empty( $row['stripe_object_id'] ) )  $by_charge_id[ (string) $row['stripe_object_id'] ] = $entry;
				if ( ! empty( $row['invoice_id'] ) )        $by_invoice[ (string) $row['invoice_id'] ]         = $entry;
				if ( ! empty( $row['payment_intent_id'] ) ) $by_pi[ (string) $row['payment_intent_id'] ]       = $entry;
				$cid = (string) $row['stripe_customer_id'];
				if ( '' !== $cid ) {
					$by_customer[ $cid ][] = $entry;
				}
			}
		}

		foreach ( $transactions as &$tx ) {
			// select_rows()/select_by_customer() may have already JSON-decoded raw_data.
			$raw = isset( $tx['raw_data'] ) ? $tx['raw_data'] : null;
			if ( is_string( $raw ) && '' !== $raw ) {
				$raw = json_decode( $raw, true );
			}
			$raw = is_array( $raw ) ? $raw : array();
			unset( $tx['raw_data'] );

			$type     = strtolower( isset( $tx['object_type'] ) ? (string) $tx['object_type'] : '' );
			$currency = isset( $tx['currency'] ) ? (string) $tx['currency'] : 'usd';

			$tx['payment_method']  = '';
			$tx['refund_status']   = '';
			$tx['refunded_amount'] = 0;
			$tx['refunded_at']     = '';
			$tx['service_name']    = '';

			// Resolve the charge object holding payment/refund details for this row.
			$charge = null;
			if ( 'charge' === $type ) {
				$charge = $raw;
			} else {
				$charge_id = isset( $tx['charge_id'] ) ? (string) $tx['charge_id'] : '';
				$obj_id    = isset( $tx['stripe_object_id'] ) ? (string) $tx['stripe_object_id'] : '';
				$pi        = isset( $tx['payment_intent_id'] ) ? (string) $tx['payment_intent_id'] : '';
				if ( '' !== $charge_id && isset( $by_charge_id[ $charge_id ] ) ) {
					$charge = $by_charge_id[ $charge_id ]['raw'];
				} elseif ( '' !== $obj_id && isset( $by_invoice[ $obj_id ] ) ) {
					$charge = $by_invoice[ $obj_id ]['raw'];
				} elseif ( '' !== $pi && isset( $by_pi[ $pi ] ) ) {
					$charge = $by_pi[ $pi ]['raw'];
				} else {
					// Basil-era data has no cross-links: match by customer + amount + nearest date.
					$cid       = isset( $tx['stripe_customer_id'] ) ? (string) $tx['stripe_customer_id'] : '';
					$amount    = isset( $tx['amount'] ) ? (float) $tx['amount'] : 0;
					$tx_ts     = ! empty( $tx['transaction_date'] ) ? strtotime( (string) $tx['transaction_date'] ) : 0;
					$best      = null;
					$best_diff = 172800; // 48h window
					foreach ( isset( $by_customer[ $cid ] ) ? $by_customer[ $cid ] : array() as $entry ) {
						if ( abs( $entry['amount'] - $amount ) > 0.005 || 0 === $entry['ts'] || 0 === $tx_ts ) {
							continue;
						}
						$diff = abs( $entry['ts'] - $tx_ts );
						if ( $diff <= $best_diff ) {
							$best_diff = $diff;
							$best      = $entry['raw'];
						}
					}
					$charge = $best;
				}
			}

			if ( is_array( $charge ) ) {
				$tx['payment_method'] = $this->payment_method_label_from_charge( $charge );
				$amount_refunded      = isset( $charge['amount_refunded'] ) ? (float) $charge['amount_refunded'] : 0;
				if ( ! empty( $charge['refunded'] ) ) {
					$tx['refund_status'] = 'refunded';
				} elseif ( $amount_refunded > 0 ) {
					$tx['refund_status'] = 'partially_refunded';
				}
				if ( $amount_refunded > 0 ) {
					$tx['refunded_amount'] = $this->stripe_minor_to_decimal( $amount_refunded, $currency );
				}
				if ( ! empty( $charge['refunds']['data'][0]['created'] ) ) {
					$tx['refunded_at'] = gmdate( 'Y-m-d H:i:s', (int) $charge['refunds']['data'][0]['created'] );
				}
			}

			// Checkout sessions have no charge object of their own; show the offered method types.
			if ( '' === $tx['payment_method'] && ! empty( $raw['payment_method_types'] ) && is_array( $raw['payment_method_types'] ) ) {
				$tx['payment_method'] = implode( ', ', array_map( function( $t ) {
					return ucwords( str_replace( '_', ' ', (string) $t ) );
				}, $raw['payment_method_types'] ) );
			}

			// What was purchased, in business terms.
			if ( 'invoice' === $type && ! empty( $raw['lines']['data'] ) ) {
				$tx['service_name'] = $this->service_name_from_lines( $raw['lines']['data'] );
			} elseif ( 'checkout_session' === $type && ! empty( $raw['line_items']['data'] ) ) {
				$tx['service_name'] = $this->service_name_from_lines( $raw['line_items']['data'] );
			}
			if ( '' === $tx['service_name'] ) {
				$desc    = trim( isset( $tx['description'] ) ? (string) $tx['description'] : '' );
				$generic = array( 'payment for invoice', 'subscription creation', 'subscription update' );
				if ( '' !== $desc && ! in_array( strtolower( $desc ), $generic, true ) && ! preg_match( '/^(invoice|charge|checkout session) (in_|ch_|py_|cs_)/i', $desc ) ) {
					$tx['service_name'] = $this->clean_line_item_name( $desc );
				}
			}
		}
		unset( $tx );

		return $transactions;
	}

	public function get_ops_tasks( WP_REST_Request $request ) {
		$pdb              = $this->get_portal_db();
		$t_tasks          = $this->portal_table( 'aj_portal_tasks' );
		$t_customers      = $this->portal_table( 'aj_portal_stripe_customers' );
		$t_statuses       = $this->portal_table( 'aj_portal_task_statuses' );
		$t_comments       = $this->portal_table( 'aj_portal_task_comments' );

		// Filters
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$scope      = sanitize_key( (string) $request->get_param( 'scope' ) );
		$frequency  = sanitize_key( (string) $request->get_param( 'frequency' ) );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );
		$client     = sanitize_text_field( (string) $request->get_param( 'client' ) );
		$due_from   = sanitize_text_field( (string) $request->get_param( 'due_from' ) );
		$due_to     = sanitize_text_field( (string) $request->get_param( 'due_to' ) );

		$valid_scopes     = array( 'global', 'client' );
		$valid_freqs      = array( 'one_time', 'recurring' );
		$valid_statuses   = array( 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled' );
		$scope            = in_array( $scope, $valid_scopes, true ) ? $scope : '';
		$frequency        = in_array( $frequency, $valid_freqs, true ) ? $frequency : '';
		$status           = in_array( $status, $valid_statuses, true ) ? $status : '';
		$due_from         = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_from ) ? $due_from : '';
		$due_to           = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_to ) ? $due_to : '';

		// Build WHERE
		$where  = '1=1';
		$params = array();
		if ( '' !== $scope ) {
			$where   .= ' AND t.task_scope = %s';
			$params[] = $scope;
		}
		if ( '' !== $frequency ) {
			$where   .= ' AND t.task_frequency = %s';
			$params[] = $frequency;
		}
		if ( '' !== $status ) {
			$where   .= ' AND t.status = %s';
			$params[] = $status;
		}
		if ( '' !== $client ) {
			$where   .= ' AND (t.stripe_customer_id = %s OR t.task_scope = \'global\')';
			$params[] = $client;
		}
		if ( '' !== $due_from ) {
			$where   .= ' AND t.due_date >= %s';
			$params[] = $due_from;
		}
		if ( '' !== $due_to ) {
			$where   .= ' AND t.due_date <= %s';
			$params[] = $due_to;
		}
		if ( '' !== $search ) {
			$like     = '%' . $pdb->esc_like( $search ) . '%';
			$where   .= ' AND (t.title LIKE %s OR t.action_required LIKE %s OR c.name LIKE %s OR c.email LIKE %s)';
			$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
		}

		$sql  = "SELECT t.id, t.stripe_customer_id, t.task_scope, t.task_frequency, t.title, t.status, t.due_date, t.action_required, t.client_visible, t.created_at, t.updated_at,
			c.name AS customer_name, c.email AS customer_email
			FROM `{$t_tasks}` t
			LEFT JOIN `{$t_customers}` c ON c.stripe_customer_id = t.stripe_customer_id
			WHERE {$where}
			ORDER BY t.updated_at DESC, t.id DESC
			LIMIT 1000";
		$tasks_raw = $params ? $pdb->get_results( $pdb->prepare( $sql, $params ) ) : $pdb->get_results( $sql );
		if ( ! is_array( $tasks_raw ) ) {
			$tasks_raw = array();
		}

		// Comment counts + latest comment
		$comment_counts  = array();
		$latest_comments = array();
		$all_ids         = wp_list_pluck( $tasks_raw, 'id' );
		if ( ! empty( $all_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
			$comments_raw = $pdb->get_results( $pdb->prepare(
				"SELECT task_id, comment, created_at FROM `{$t_comments}` WHERE task_id IN ({$placeholders}) ORDER BY created_at DESC, id DESC",
				$all_ids
			) );
			foreach ( (array) $comments_raw as $cmt ) {
				$tid = (int) $cmt->task_id;
				$comment_counts[ $tid ] = isset( $comment_counts[ $tid ] ) ? $comment_counts[ $tid ] + 1 : 1;
				if ( ! isset( $latest_comments[ $tid ] ) ) {
					$latest_comments[ $tid ] = wp_trim_words( (string) $cmt->comment, 12, '…' );
				}
			}
		}

		// Global task progress (how many customers completed each task)
		$total_customers = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$t_customers}` WHERE enabled_portal = 1" );
		if ( $total_customers < 1 ) {
			$total_customers = (int) $pdb->get_var( "SELECT COUNT(*) FROM `{$t_customers}`" );
		}
		$global_progress = array();
		if ( ! empty( $all_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
			$status_rows  = $pdb->get_results( $pdb->prepare(
				"SELECT task_id, status FROM `{$t_statuses}` WHERE task_id IN ({$placeholders})",
				$all_ids
			) );
			foreach ( (array) $status_rows as $sr ) {
				$tid = (int) $sr->task_id;
				if ( ! isset( $global_progress[ $tid ] ) ) {
					$global_progress[ $tid ] = array( 'completed' => 0, 'total' => 0 );
				}
				$global_progress[ $tid ]['total']++;
				if ( 'completed' === $sr->status ) {
					$global_progress[ $tid ]['completed']++;
				}
			}
		}

		$today         = gmdate( 'Y-m-d' );
		$closed_status = array( 'completed', 'cancelled', 'canceled', 'closed', 'archived' );
		$stats         = array( 'shown' => 0, 'open' => 0, 'overdue' => 0, 'completed' => 0, 'visible' => 0 );
		$tasks         = array();
		foreach ( $tasks_raw as $t ) {
			$id       = (int) $t->id;
			$eff_status = sanitize_key( (string) $t->status );

			$progress = null;
			if ( 'global' === $t->task_scope ) {
				$prog_data = isset( $global_progress[ $id ] ) ? $global_progress[ $id ] : array( 'completed' => 0, 'total' => 0 );
				$progress  = array(
					'completed'       => $prog_data['completed'],
					'total_customers' => $total_customers,
				);
			}

			$tasks[] = array(
				'id'               => $id,
				'stripe_customer_id' => (string) $t->stripe_customer_id,
				'task_scope'       => (string) $t->task_scope,
				'task_frequency'   => (string) $t->task_frequency,
				'title'            => (string) $t->title,
				'status'           => $eff_status,
				'due_date'         => (string) $t->due_date,
				'action_required'  => (string) $t->action_required,
				'client_visible'   => (bool) $t->client_visible,
				'created_at'       => (string) $t->created_at,
				'updated_at'       => (string) $t->updated_at,
				'customer_name'    => (string) $t->customer_name,
				'customer_email'   => (string) $t->customer_email,
				'comments_count'   => $comment_counts[ $id ] ?? 0,
				'latest_comment'   => $latest_comments[ $id ] ?? '',
				'progress'         => $progress,
			);

			$stats['shown']++;
			if ( ! empty( $t->client_visible ) ) {
				$stats['visible']++;
			}
			if ( 'completed' === $eff_status ) {
				$stats['completed']++;
			} elseif ( in_array( $eff_status, array( 'open', 'waiting_on_client', 'in_progress', 'upcoming' ), true ) ) {
				$stats['open']++;
			}
			if ( ! empty( $t->due_date ) && $t->due_date < $today && ! in_array( $eff_status, $closed_status, true ) ) {
				$stats['overdue']++;
			}
		}

		return rest_ensure_response( array( 'tasks' => $tasks, 'stats' => $stats, 'total_customers' => $total_customers ) );
	}

	public function create_ops_task( WP_REST_Request $request ) {
		$pdb    = $this->get_portal_db();
		$t_tasks = $this->portal_table( 'aj_portal_tasks' );

		$scope     = in_array( $request->get_param( 'task_scope' ), array( 'global', 'client' ), true ) ? $request->get_param( 'task_scope' ) : 'client';
		$freq      = in_array( $request->get_param( 'task_frequency' ), array( 'one_time', 'recurring' ), true ) ? $request->get_param( 'task_frequency' ) : 'one_time';
		$valid_s   = array( 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled' );
		$status    = in_array( $request->get_param( 'status' ), $valid_s, true ) ? $request->get_param( 'status' ) : 'open';
		$title     = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$due_date  = sanitize_text_field( (string) $request->get_param( 'due_date' ) );
		$due_date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date ) ? $due_date : null;
		$visible   = rest_sanitize_boolean( $request->get_param( 'client_visible' ) );
		$customer  = 'global' === $scope ? '' : sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$desc      = sanitize_textarea_field( (string) $request->get_param( 'action_required' ) );

		if ( '' === $title ) {
			return new WP_Error( 'bad_request', 'Title is required.', array( 'status' => 400 ) );
		}

		$inserted = $pdb->insert( $t_tasks, array(
			'stripe_customer_id' => $customer,
			'task_scope'         => $scope,
			'task_frequency'     => $freq,
			'title'              => $title,
			'status'             => $status,
			'due_date'           => $due_date,
			'action_required'    => $desc,
			'client_visible'     => $visible ? 1 : 0,
			'created_by'         => get_current_user_id(),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ) );

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Failed to create task.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $pdb->insert_id ) );
	}

	public function update_ops_task( WP_REST_Request $request ) {
		$pdb     = $this->get_portal_db();
		$t_tasks = $this->portal_table( 'aj_portal_tasks' );
		$id      = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'bad_request', 'Invalid task ID.', array( 'status' => 400 ) );
		}

		$valid_scopes = array( 'global', 'client' );
		$valid_freqs  = array( 'one_time', 'recurring' );
		$valid_status = array( 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled' );

		$data    = array();
		$formats = array();
		$p       = $request->get_params();

		if ( isset( $p['task_scope'] ) && in_array( $p['task_scope'], $valid_scopes, true ) ) {
			$data['task_scope'] = $p['task_scope']; $formats[] = '%s';
		}
		if ( isset( $p['task_frequency'] ) && in_array( $p['task_frequency'], $valid_freqs, true ) ) {
			$data['task_frequency'] = $p['task_frequency']; $formats[] = '%s';
		}
		if ( isset( $p['title'] ) && '' !== $p['title'] ) {
			$data['title'] = sanitize_text_field( $p['title'] ); $formats[] = '%s';
		}
		if ( isset( $p['status'] ) && in_array( $p['status'], $valid_status, true ) ) {
			$data['status'] = $p['status']; $formats[] = '%s';
		}
		if ( array_key_exists( 'due_date', $p ) ) {
			$dd = sanitize_text_field( (string) $p['due_date'] );
			$data['due_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dd ) ? $dd : null;
			$formats[] = '%s';
		}
		if ( array_key_exists( 'action_required', $p ) ) {
			$data['action_required'] = sanitize_textarea_field( (string) $p['action_required'] ); $formats[] = '%s';
		}
		if ( isset( $p['client_visible'] ) ) {
			$data['client_visible'] = rest_sanitize_boolean( $p['client_visible'] ) ? 1 : 0; $formats[] = '%d';
		}
		if ( isset( $p['stripe_customer_id'] ) ) {
			$data['stripe_customer_id'] = sanitize_text_field( (string) $p['stripe_customer_id'] ); $formats[] = '%s';
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'bad_request', 'No fields to update.', array( 'status' => 400 ) );
		}
		$updated = $pdb->update( $t_tasks, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error( 'db_error', 'Failed to update task.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function delete_ops_task( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$id         = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'bad_request', 'Invalid task ID.', array( 'status' => 400 ) );
		}
		$pdb->delete( $this->portal_table( 'aj_portal_task_comments' ), array( 'task_id' => $id ), array( '%d' ) );
		$pdb->delete( $this->portal_table( 'aj_portal_task_statuses' ), array( 'task_id' => $id ), array( '%d' ) );
		$pdb->delete( $this->portal_table( 'aj_portal_tasks' ), array( 'id' => $id ), array( '%d' ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function bulk_ops_tasks( WP_REST_Request $request ) {
		$pdb     = $this->get_portal_db();
		$t_tasks = $this->portal_table( 'aj_portal_tasks' );
		$action  = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids_raw = $request->get_param( 'ids' );
		$ids     = array_filter( array_map( 'absint', (array) $ids_raw ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'bad_request', 'No task IDs provided.', array( 'status' => 400 ) );
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( 'delete' === $action ) {
			$pdb->query( $pdb->prepare( "DELETE FROM `{$this->portal_table('aj_portal_task_comments')}` WHERE task_id IN ({$placeholders})", $ids ) );
			$pdb->query( $pdb->prepare( "DELETE FROM `{$this->portal_table('aj_portal_task_statuses')}` WHERE task_id IN ({$placeholders})", $ids ) );
			$pdb->query( $pdb->prepare( "DELETE FROM `{$t_tasks}` WHERE id IN ({$placeholders})", $ids ) );
		} elseif ( 'complete' === $action ) {
			$pdb->query( $pdb->prepare( "UPDATE `{$t_tasks}` SET status = 'completed', updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( current_time( 'mysql' ) ), $ids ) ) );
		} elseif ( 'reopen' === $action ) {
			$pdb->query( $pdb->prepare( "UPDATE `{$t_tasks}` SET status = 'open', updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( current_time( 'mysql' ) ), $ids ) ) );
		} elseif ( 'set_status' === $action ) {
			$valid_s = array( 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled' );
			$new_s   = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $new_s, $valid_s, true ) ) {
				return new WP_Error( 'bad_request', 'Invalid status.', array( 'status' => 400 ) );
			}
			$pdb->query( $pdb->prepare( "UPDATE `{$t_tasks}` SET status = %s, updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( $new_s, current_time( 'mysql' ) ), $ids ) ) );
		} elseif ( 'set_visibility' === $action ) {
			$vis = rest_sanitize_boolean( $request->get_param( 'client_visible' ) ) ? 1 : 0;
			$pdb->query( $pdb->prepare( "UPDATE `{$t_tasks}` SET client_visible = %d, updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( $vis, current_time( 'mysql' ) ), $ids ) ) );
		} elseif ( 'set_due_date' === $action ) {
			$dd = sanitize_text_field( (string) $request->get_param( 'due_date' ) );
			$dd = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dd ) ? $dd : null;
			if ( null === $dd ) {
				return new WP_Error( 'bad_request', 'Invalid due date.', array( 'status' => 400 ) );
			}
			$pdb->query( $pdb->prepare( "UPDATE `{$t_tasks}` SET due_date = %s, updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( $dd, current_time( 'mysql' ) ), $ids ) ) );
		} else {
			return new WP_Error( 'bad_request', 'Unknown bulk action.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'affected' => count( $ids ) ) );
	}

	// ── Compliance calendar ─────────────────────────────────────────────────────

	private function get_compliance_valid_entity_types() {
		return array( 'llc', 'corp', 'nonprofit', 'lp', 'llp', 'other' );
	}

	private function get_compliance_valid_entity_statuses() {
		return array( 'active', 'inactive', 'dissolved' );
	}

	/** The report year an active entity currently owes (its next/current deadline). */
	private function get_compliance_target_year( $entity ) {
		$current = (int) gmdate( 'Y' );
		$first   = (int) $entity->first_report_year;
		return max( $current, $first );
	}

	private function compute_compliance_due_date( $entity, $year ) {
		$month = max( 1, min( 12, (int) $entity->due_month ) );
		$day   = max( 1, min( 28, (int) $entity->due_day ) ); // clamp to 28 so every month/year is valid
		return sprintf( '%04d-%02d-%02d', (int) $year, $month, $day );
	}

	/**
	 * Lazily guarantees every active entity has a filing row for its current target
	 * year, so year rollover needs no cron: the row appears on the first list/cron
	 * touch after Jan 1. Uses the (entity_id, filing_type, period_year) unique key
	 * to stay idempotent.
	 */
	private function ensure_compliance_filing_rows() {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );

		$entities = $pdb->get_results( "SELECT * FROM `{$t_entities}` WHERE entity_status = 'active'" );
		foreach ( (array) $entities as $entity ) {
			$year   = $this->get_compliance_target_year( $entity );
			$exists = (int) $pdb->get_var( $pdb->prepare(
				"SELECT COUNT(*) FROM `{$t_filings}` WHERE entity_id = %d AND filing_type = 'annual_report' AND period_year = %d",
				(int) $entity->id,
				$year
			) );
			if ( ! $exists ) {
				$pdb->query( $pdb->prepare(
					"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, 'annual_report', %d, %s)",
					(int) $entity->id,
					$year,
					$this->compute_compliance_due_date( $entity, $year )
				) );
			}
		}
	}

	private function format_compliance_filing_row( $f ) {
		return array(
			'id'                  => (int) $f->id,
			'entity_id'           => (int) $f->entity_id,
			'filing_type'         => (string) $f->filing_type,
			'period_year'         => (int) $f->period_year,
			'due_date'            => (string) $f->due_date,
			'status'              => (string) $f->status,
			'filed_at'            => (string) $f->filed_at,
			'confirmation'        => (string) $f->confirmation,
			'notes'               => (string) $f->notes,
			'client_completed'    => ! empty( $f->client_completed ),
			'client_completed_at' => (string) $f->client_completed_at,
			'client_note'         => (string) $f->client_note,
			'reminder_stage'      => (string) $f->reminder_stage,
			'last_reminder_at'    => (string) $f->last_reminder_at,
			'reminders_sent'      => (int) $f->reminders_sent,
		);
	}

	public function get_ops_compliance( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$t_entities  = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings   = $this->portal_table( 'aj_portal_compliance_filings' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );

		$this->ensure_compliance_filing_rows();

		$search        = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status_filter = sanitize_key( (string) $request->get_param( 'status' ) );
		$entity_status = sanitize_key( (string) $request->get_param( 'entity_status' ) );
		$client        = sanitize_text_field( (string) $request->get_param( 'client' ) );

		$valid_derived  = array( 'overdue', 'due_soon', 'upcoming', 'filed', 'waived' );
		$status_filter  = in_array( $status_filter, $valid_derived, true ) ? $status_filter : '';
		$entity_status  = in_array( $entity_status, $this->get_compliance_valid_entity_statuses(), true ) ? $entity_status : '';

		$where  = '1=1';
		$params = array();
		if ( '' !== $entity_status ) {
			$where   .= ' AND e.entity_status = %s';
			$params[] = $entity_status;
		}
		if ( '' !== $client ) {
			$where   .= ' AND e.stripe_customer_id = %s';
			$params[] = $client;
		}
		if ( '' !== $search ) {
			$like     = '%' . $pdb->esc_like( $search ) . '%';
			$where   .= ' AND (e.entity_name LIKE %s OR e.sos_id LIKE %s OR c.name LIKE %s OR c.email LIKE %s)';
			$params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
		}

		$sql = "SELECT e.*, c.name AS customer_name, c.email AS customer_email
			FROM `{$t_entities}` e
			LEFT JOIN `{$t_customers}` c ON c.stripe_customer_id = e.stripe_customer_id
			WHERE {$where}
			ORDER BY e.entity_name ASC
			LIMIT 2000";
		$entities_raw = $params ? $pdb->get_results( $pdb->prepare( $sql, $params ) ) : $pdb->get_results( $sql );
		$entities_raw = is_array( $entities_raw ) ? $entities_raw : array();

		// Filings for the listed entities, newest period first.
		$filings_by_entity = array();
		$entity_ids        = array_map( 'intval', wp_list_pluck( $entities_raw, 'id' ) );
		if ( ! empty( $entity_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $entity_ids ), '%d' ) );
			$filing_rows  = $pdb->get_results( $pdb->prepare(
				"SELECT * FROM `{$t_filings}` WHERE entity_id IN ({$placeholders}) ORDER BY period_year DESC, id DESC",
				$entity_ids
			) );
			foreach ( (array) $filing_rows as $f ) {
				$filings_by_entity[ (int) $f->entity_id ][] = $f;
			}
		}

		$today    = gmdate( 'Y-m-d' );
		$year     = (int) gmdate( 'Y' );
		$due30    = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
		$due90    = gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS );
		$stats    = array( 'shown' => 0, 'due_30' => 0, 'due_90' => 0, 'overdue' => 0, 'filed_this_year' => 0 );
		$entities = array();

		foreach ( $entities_raw as $e ) {
			$id      = (int) $e->id;
			$filings = isset( $filings_by_entity[ $id ] ) ? $filings_by_entity[ $id ] : array();

			// Current filing = oldest still-pending one; else the most recent row.
			$current = null;
			foreach ( array_reverse( $filings ) as $f ) {
				if ( 'pending' === $f->status ) {
					$current = $f;
					break;
				}
			}
			if ( ! $current && ! empty( $filings ) ) {
				$current = $filings[0];
			}

			$derived = 'upcoming';
			if ( $current ) {
				if ( 'filed' === $current->status ) {
					$derived = 'filed';
				} elseif ( 'waived' === $current->status ) {
					$derived = 'waived';
				} elseif ( $current->due_date < $today ) {
					$derived = 'overdue';
				} elseif ( $current->due_date <= $due90 ) {
					$derived = 'due_soon';
				}
			}

			if ( '' !== $status_filter && $derived !== $status_filter ) {
				continue;
			}

			$entities[] = array(
				'id'                 => $id,
				'stripe_customer_id' => (string) $e->stripe_customer_id,
				'entity_name'        => (string) $e->entity_name,
				'entity_type'        => (string) $e->entity_type,
				'jurisdiction'       => (string) $e->jurisdiction,
				'sos_id'             => (string) $e->sos_id,
				'formation_date'     => (string) $e->formation_date,
				'first_report_year'  => (int) $e->first_report_year,
				'due_month'          => (int) $e->due_month,
				'due_day'            => (int) $e->due_day,
				'entity_status'      => (string) $e->entity_status,
				'notes'              => (string) $e->notes,
				'created_at'         => (string) $e->created_at,
				'updated_at'         => (string) $e->updated_at,
				'customer_name'      => (string) $e->customer_name,
				'customer_email'     => (string) $e->customer_email,
				'derived_status'     => $derived,
				'current_filing'     => $current ? $this->format_compliance_filing_row( $current ) : null,
				'filings'            => array_map( array( $this, 'format_compliance_filing_row' ), $filings ),
			);

			$stats['shown']++;
			if ( 'overdue' === $derived ) {
				$stats['overdue']++;
			}
			if ( $current && 'pending' === $current->status && $current->due_date >= $today ) {
				if ( $current->due_date <= $due30 ) {
					$stats['due_30']++;
				}
				if ( $current->due_date <= $due90 ) {
					$stats['due_90']++;
				}
			}
			foreach ( $filings as $f ) {
				if ( 'filed' === $f->status && (int) $f->period_year === $year ) {
					$stats['filed_this_year']++;
					break;
				}
			}
		}

		return rest_ensure_response( array( 'entities' => $entities, 'stats' => $stats ) );
	}

	public function create_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );

		$name = sanitize_text_field( (string) $request->get_param( 'entity_name' ) );
		if ( '' === $name ) {
			return new WP_Error( 'bad_request', 'Entity name is required.', array( 'status' => 400 ) );
		}

		$type   = sanitize_key( (string) $request->get_param( 'entity_type' ) );
		$type   = in_array( $type, $this->get_compliance_valid_entity_types(), true ) ? $type : 'llc';
		$estatus = sanitize_key( (string) $request->get_param( 'entity_status' ) );
		$estatus = in_array( $estatus, $this->get_compliance_valid_entity_statuses(), true ) ? $estatus : 'active';

		$jurisdiction = strtoupper( sanitize_text_field( (string) $request->get_param( 'jurisdiction' ) ) );
		$jurisdiction = preg_match( '/^[A-Z]{2}$/', $jurisdiction ) ? $jurisdiction : 'NC';

		$formation = sanitize_text_field( (string) $request->get_param( 'formation_date' ) );
		$formation = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $formation ) ? $formation : null;

		$first_year = absint( $request->get_param( 'first_report_year' ) );
		if ( ! $first_year && $formation ) {
			// NC annual reports start the year after formation.
			$first_year = (int) substr( $formation, 0, 4 ) + 1;
		}
		if ( ! $first_year ) {
			$first_year = (int) gmdate( 'Y' );
		}

		$due_month = absint( $request->get_param( 'due_month' ) );
		$due_month = ( $due_month >= 1 && $due_month <= 12 ) ? $due_month : 4;
		$due_day   = absint( $request->get_param( 'due_day' ) );
		$due_day   = ( $due_day >= 1 && $due_day <= 28 ) ? $due_day : 15;

		$inserted = $pdb->insert( $t_entities, array(
			'stripe_customer_id' => sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) ),
			'entity_name'        => $name,
			'entity_type'        => $type,
			'jurisdiction'       => $jurisdiction,
			'sos_id'             => sanitize_text_field( (string) $request->get_param( 'sos_id' ) ),
			'formation_date'     => $formation,
			'first_report_year'  => $first_year,
			'due_month'          => $due_month,
			'due_day'            => $due_day,
			'entity_status'      => $estatus,
			'notes'              => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'created_by'         => get_current_user_id(),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d' ) );

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Failed to create entity.', array( 'status' => 500 ) );
		}
		$entity_id = (int) $pdb->insert_id;

		if ( 'active' === $estatus ) {
			$entity = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", $entity_id ) );
			$year   = $this->get_compliance_target_year( $entity );
			$pdb->query( $pdb->prepare(
				"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, 'annual_report', %d, %s)",
				$entity_id,
				$year,
				$this->compute_compliance_due_date( $entity, $year )
			) );
		}

		return rest_ensure_response( array( 'success' => true, 'id' => $entity_id ) );
	}

	public function update_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$id         = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'bad_request', 'Invalid entity ID.', array( 'status' => 400 ) );
		}

		$data    = array();
		$formats = array();
		$p       = $request->get_params();

		if ( isset( $p['entity_name'] ) && '' !== $p['entity_name'] ) {
			$data['entity_name'] = sanitize_text_field( (string) $p['entity_name'] ); $formats[] = '%s';
		}
		if ( isset( $p['stripe_customer_id'] ) ) {
			$data['stripe_customer_id'] = sanitize_text_field( (string) $p['stripe_customer_id'] ); $formats[] = '%s';
		}
		if ( isset( $p['entity_type'] ) && in_array( $p['entity_type'], $this->get_compliance_valid_entity_types(), true ) ) {
			$data['entity_type'] = $p['entity_type']; $formats[] = '%s';
		}
		if ( isset( $p['jurisdiction'] ) && preg_match( '/^[A-Za-z]{2}$/', (string) $p['jurisdiction'] ) ) {
			$data['jurisdiction'] = strtoupper( (string) $p['jurisdiction'] ); $formats[] = '%s';
		}
		if ( isset( $p['sos_id'] ) ) {
			$data['sos_id'] = sanitize_text_field( (string) $p['sos_id'] ); $formats[] = '%s';
		}
		if ( array_key_exists( 'formation_date', $p ) ) {
			$fd = sanitize_text_field( (string) $p['formation_date'] );
			$data['formation_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fd ) ? $fd : null;
			$formats[] = '%s';
		}
		if ( isset( $p['first_report_year'] ) ) {
			$data['first_report_year'] = absint( $p['first_report_year'] ); $formats[] = '%d';
		}
		if ( isset( $p['due_month'] ) && absint( $p['due_month'] ) >= 1 && absint( $p['due_month'] ) <= 12 ) {
			$data['due_month'] = absint( $p['due_month'] ); $formats[] = '%d';
		}
		if ( isset( $p['due_day'] ) && absint( $p['due_day'] ) >= 1 && absint( $p['due_day'] ) <= 28 ) {
			$data['due_day'] = absint( $p['due_day'] ); $formats[] = '%d';
		}
		if ( isset( $p['entity_status'] ) && in_array( $p['entity_status'], $this->get_compliance_valid_entity_statuses(), true ) ) {
			$data['entity_status'] = $p['entity_status']; $formats[] = '%s';
		}
		if ( array_key_exists( 'notes', $p ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $p['notes'] ); $formats[] = '%s';
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'bad_request', 'No fields to update.', array( 'status' => 400 ) );
		}

		$updated = $pdb->update( $t_entities, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $updated ) {
			return new WP_Error( 'db_error', 'Failed to update entity.', array( 'status' => 500 ) );
		}

		// Keep pending deadlines in sync when the due-date rule changes.
		if ( isset( $data['due_month'] ) || isset( $data['due_day'] ) ) {
			$entity   = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", $id ) );
			$pending  = $pdb->get_results( $pdb->prepare( "SELECT id, period_year FROM `{$t_filings}` WHERE entity_id = %d AND status = 'pending'", $id ) );
			foreach ( (array) $pending as $f ) {
				$pdb->update(
					$t_filings,
					array( 'due_date' => $this->compute_compliance_due_date( $entity, (int) $f->period_year ) ),
					array( 'id' => (int) $f->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function delete_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb = $this->get_portal_db();
		$id  = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'bad_request', 'Invalid entity ID.', array( 'status' => 400 ) );
		}
		$pdb->delete( $this->portal_table( 'aj_portal_compliance_filings' ), array( 'entity_id' => $id ), array( '%d' ) );
		$pdb->delete( $this->portal_table( 'aj_portal_compliance_entities' ), array( 'id' => $id ), array( '%d' ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function update_ops_compliance_filing( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$id         = absint( $request->get_param( 'id' ) );
		$action     = sanitize_key( (string) $request->get_param( 'action' ) );

		$filing = $id ? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_filings}` WHERE id = %d", $id ) ) : null;
		if ( ! $filing ) {
			return new WP_Error( 'not_found', 'Filing not found.', array( 'status' => 404 ) );
		}

		if ( 'file' === $action ) {
			$pdb->update( $t_filings, array(
				'status'       => 'filed',
				'filed_at'     => current_time( 'mysql' ),
				'filed_by'     => get_current_user_id(),
				'confirmation' => sanitize_text_field( (string) $request->get_param( 'confirmation' ) ),
			), array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );

			// Queue next year's deadline so the calendar always shows what is next.
			$entity = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", (int) $filing->entity_id ) );
			if ( $entity && 'active' === $entity->entity_status ) {
				$next_year = (int) $filing->period_year + 1;
				$pdb->query( $pdb->prepare(
					"INSERT IGNORE INTO `{$t_filings}` (entity_id, filing_type, period_year, due_date) VALUES (%d, %s, %d, %s)",
					(int) $entity->id,
					(string) $filing->filing_type,
					$next_year,
					$this->compute_compliance_due_date( $entity, $next_year )
				) );
			}
		} elseif ( 'reopen' === $action ) {
			$pdb->update( $t_filings, array(
				'status'       => 'pending',
				'filed_at'     => null,
				'filed_by'     => 0,
				'confirmation' => '',
			), array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		} elseif ( 'waive' === $action ) {
			$pdb->update( $t_filings, array( 'status' => 'waived' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		} elseif ( 'update' === $action ) {
			$data    = array();
			$formats = array();
			$p       = $request->get_params();
			if ( array_key_exists( 'notes', $p ) ) {
				$data['notes'] = sanitize_textarea_field( (string) $p['notes'] ); $formats[] = '%s';
			}
			if ( isset( $p['due_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $p['due_date'] ) ) {
				$data['due_date'] = (string) $p['due_date']; $formats[] = '%s';
			}
			if ( empty( $data ) ) {
				return new WP_Error( 'bad_request', 'No fields to update.', array( 'status' => 400 ) );
			}
			$pdb->update( $t_filings, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		} else {
			return new WP_Error( 'bad_request', 'Unknown filing action.', array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function remind_ops_compliance_entity( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );
		$id         = absint( $request->get_param( 'id' ) );

		$entity = $id ? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_entities}` WHERE id = %d", $id ) ) : null;
		if ( ! $entity ) {
			return new WP_Error( 'not_found', 'Entity not found.', array( 'status' => 404 ) );
		}

		$filing = $pdb->get_row( $pdb->prepare(
			"SELECT * FROM `{$t_filings}` WHERE entity_id = %d AND status = 'pending' ORDER BY due_date ASC LIMIT 1",
			$id
		) );
		if ( ! $filing ) {
			return new WP_Error( 'bad_request', 'No pending filing to remind about.', array( 'status' => 400 ) );
		}

		$customer = '' !== (string) $entity->stripe_customer_id
			? $pdb->get_row( $pdb->prepare( "SELECT name, email FROM `{$t_customers}` WHERE stripe_customer_id = %s", (string) $entity->stripe_customer_id ) )
			: null;
		if ( ! $customer || ! is_email( (string) $customer->email ) ) {
			return new WP_Error( 'bad_request', 'Entity has no linked customer with a valid email.', array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'server_error', 'Admin module unavailable.', array( 'status' => 500 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$sent  = $admin->send_compliance_reminder_for_ops( $entity, $filing, (string) $customer->name, (string) $customer->email );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'The reminder email could not be sent.', array( 'status' => 500 ) );
		}

		$pdb->update( $t_filings, array(
			'last_reminder_at' => current_time( 'mysql' ),
			'reminders_sent'   => (int) $filing->reminders_sent + 1,
		), array( 'id' => (int) $filing->id ), array( '%s', '%d' ), array( '%d' ) );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Client portal: the current user's entities with their filing deadlines. */
	public function get_portal_compliance() {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		if ( ! $this->table_exists( $pdb, $t_entities ) ) {
			return rest_ensure_response( array( 'entities' => array() ) );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return rest_ensure_response( array( 'entities' => array() ) );
		}

		$this->ensure_compliance_filing_rows();

		$entities_raw = $pdb->get_results( $pdb->prepare(
			"SELECT * FROM `{$t_entities}` WHERE stripe_customer_id = %s ORDER BY entity_name ASC",
			$stripe_customer_id
		) );
		$entities_raw = is_array( $entities_raw ) ? $entities_raw : array();

		$entities = array();
		foreach ( $entities_raw as $e ) {
			$filings = $pdb->get_results( $pdb->prepare(
				"SELECT * FROM `{$t_filings}` WHERE entity_id = %d ORDER BY period_year DESC, id DESC",
				(int) $e->id
			) );
			$entities[] = array(
				'id'             => (int) $e->id,
				'entity_name'    => (string) $e->entity_name,
				'entity_type'    => (string) $e->entity_type,
				'jurisdiction'   => (string) $e->jurisdiction,
				'sos_id'         => (string) $e->sos_id,
				'formation_date' => (string) $e->formation_date,
				'entity_status'  => (string) $e->entity_status,
				'filings'        => array_map( array( $this, 'format_compliance_filing_row' ), (array) $filings ),
			);
		}

		return rest_ensure_response( array( 'entities' => $entities ) );
	}

	/**
	 * Client portal: the customer marks a filing complete on their side ("I handled
	 * this / it's filed"). Staff still verify and mark it officially filed in ops;
	 * automated reminders stop once the client has marked it complete.
	 */
	public function complete_portal_compliance_filing( WP_REST_Request $request ) {
		$pdb        = $this->get_portal_db();
		$t_entities = $this->portal_table( 'aj_portal_compliance_entities' );
		$t_filings  = $this->portal_table( 'aj_portal_compliance_filings' );
		$id         = absint( $request->get_param( 'id' ) );

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'forbidden', __( 'No linked customer account.', 'ajforms' ), array( 'status' => 403 ) );
		}

		$filing = $id ? $pdb->get_row( $pdb->prepare(
			"SELECT f.*, e.stripe_customer_id AS owner_customer_id
			FROM `{$t_filings}` f
			INNER JOIN `{$t_entities}` e ON e.id = f.entity_id
			WHERE f.id = %d",
			$id
		) ) : null;
		if ( ! $filing || (string) $filing->owner_customer_id !== $stripe_customer_id ) {
			return new WP_Error( 'not_found', __( 'Filing not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$completed = $request->get_param( 'completed' );
		$completed = null === $completed ? true : rest_sanitize_boolean( $completed );

		$data = array(
			'client_completed'    => $completed ? 1 : 0,
			'client_completed_at' => $completed ? current_time( 'mysql' ) : null,
		);
		$formats = array( '%d', '%s' );
		if ( null !== $request->get_param( 'note' ) ) {
			$data['client_note'] = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
			$formats[]           = '%s';
		}
		$pdb->update( $t_filings, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		$fresh = $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$t_filings}` WHERE id = %d", $id ) );
		return rest_ensure_response( array( 'success' => true, 'filing' => $this->format_compliance_filing_row( $fresh ) ) );
	}

	public function get_ops_service_requests( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$t_sr        = $this->portal_table( 'aj_portal_service_requests' );
		$t_customers = $this->portal_table( 'aj_portal_stripe_customers' );

		// Keep requests honest against Stripe (e.g. a subscription canceled after the request was
		// created) without requiring someone to load the WP admin tab first.
		if ( class_exists( 'AJForms_Admin' ) ) {
			( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() )->reconcile_service_requests_for_ops();
		}

		$search        = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status_filter = sanitize_key( (string) $request->get_param( 'status' ) );
		$source_filter = sanitize_key( (string) $request->get_param( 'source' ) );

		$valid_statuses = array( 'draft', 'pending_payment', 'awaiting_payment', 'paid', 'updating_sosn', 'signing_cmra', 'active', 'cancelled', 'failed', 'admin_review_required', 'completed', 'refunded', 'partially_refunded' );

		$where  = array( '1=1' );
		$params = array();

		// Source filter (default: hide unpaid checkout leads). A refund means the request WAS paid —
		// it must stay alongside 'paid'/'completed'/'active' here, or a refunded checkout-session
		// purchase gets misclassified as an abandoned/unpaid lead and silently dropped from every
		// view (not just "Needs Action" — this filter runs before that logic even sees the row).
		if ( 'checkout_only' === $source_filter ) {
			$where[] = "(r.source_type = 'checkout_session' OR r.source = 'checkout_session')";
		} elseif ( 'show_all' !== $source_filter ) {
			$where[] = "NOT ((r.source_type = 'checkout_session' OR r.source = 'checkout_session') AND r.status NOT IN ('paid','completed','active','refunded','partially_refunded'))";
		}

		// Status filter (default: needs action — applied in PHP below via the same logic that
		// drives the quick-action buttons, so a row only counts as "needs action" when there's
		// actually a next-step button for it; a literal status like 'paid' is not enough on its
		// own once the service_status has reached active/completed).
		$needs_action_filter = false;
		if ( 'all' === $status_filter ) {
			// no filter
		} elseif ( in_array( $status_filter, $valid_statuses, true ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $status_filter;
		} elseif ( '' === $search ) {
			// Only default to "needs action" when there's no search term — a search should look
			// across every request, not just the ones still awaiting a next step.
			$needs_action_filter = true;
		}

		// Search
		if ( '' !== $search ) {
			$like     = '%' . $pdb->esc_like( $search ) . '%';
			$where[]  = '(r.service_name LIKE %s OR r.stripe_customer_id LIKE %s OR c.email LIKE %s OR c.name LIKE %s OR r.client_notes LIKE %s OR r.admin_notes LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT r.id, r.stripe_customer_id, r.service_name, r.request_type, r.status, r.service_status,
			r.amount, r.currency, r.source, r.source_type, r.client_notes, r.admin_notes, r.created_at, r.updated_at,
			r.stripe_price_id, r.stripe_product_id, r.assigned_user_id,
			c.name AS customer_name, c.email AS customer_email
			FROM `{$t_sr}` r
			LEFT JOIN `{$t_customers}` c ON c.stripe_customer_id = r.stripe_customer_id
			WHERE {$where_sql}
			ORDER BY r.updated_at DESC, r.created_at DESC, r.id DESC
			LIMIT 500";
		$rows = $params ? $pdb->get_results( $pdb->prepare( $sql, $params ) ) : $pdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$admin = class_exists( 'AJForms_Admin' ) ? ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() ) : null;

		// Resolve assignee display info in one query rather than one per row.
		global $wpdb;
		$assignee_ids = array_values( array_unique( array_filter( array_map( function ( $r ) { return (int) $r->assigned_user_id; }, $rows ) ) ) );
		$assignees    = array();
		if ( ! empty( $assignee_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $assignee_ids ), '%d' ) );
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, display_name, user_email FROM {$wpdb->users} WHERE ID IN ({$id_placeholders})", $assignee_ids ) ) as $u ) {
				$assignees[ (int) $u->ID ] = array( 'name' => (string) $u->display_name, 'email' => (string) $u->user_email );
			}
		}

		$service_requests = array();
		foreach ( $rows as $r ) {
			$needs_action = $admin ? $admin->service_request_needs_action_for_ops( $r ) : false;
			if ( $needs_action_filter && ! $needs_action ) {
				continue;
			}
			$assigned_user_id = (int) $r->assigned_user_id;
			$service_requests[] = array(
				'id'                => (int) $r->id,
				'stripe_customer_id' => (string) $r->stripe_customer_id,
				'service_name'      => (string) $r->service_name,
				'request_type'      => (string) $r->request_type,
				'status'            => (string) $r->status,
				'service_status'    => (string) $r->service_status,
				'amount'            => (float) $r->amount,
				'currency'          => (string) $r->currency,
				'source'            => (string) $r->source,
				'source_type'       => (string) $r->source_type,
				'client_notes'      => (string) $r->client_notes,
				'admin_notes'       => (string) $r->admin_notes,
				'created_at'        => (string) $r->created_at,
				'updated_at'        => (string) $r->updated_at,
				'customer_name'     => (string) $r->customer_name,
				'customer_email'    => (string) $r->customer_email,
				'needs_action'      => $needs_action,
				'assigned_user_id'  => $assigned_user_id,
				'assigned_user_name'  => $assigned_user_id && isset( $assignees[ $assigned_user_id ] ) ? $assignees[ $assigned_user_id ]['name'] : '',
				'assigned_user_email' => $assigned_user_id && isset( $assignees[ $assigned_user_id ] ) ? $assignees[ $assigned_user_id ]['email'] : '',
				'service_status_options' => $admin ? $admin->get_service_request_service_status_options_for_ops( $r ) : array(),
				'service_status_in_pipeline' => $admin ? $admin->is_service_request_status_in_pipeline_for_ops( $r ) : true,
				'quick_actions'     => $admin ? $admin->get_service_request_quick_actions_for_ops( $r ) : array(),
			);
			if ( count( $service_requests ) >= 200 ) {
				break;
			}
		}

		// Stats are computed over the whole table (not just the current filter/page) using the
		// same needs-action logic as above, so the stat cards and the default list never disagree.
		$stats = $this->get_service_request_stats_for_ops();

		return rest_ensure_response( array(
			'service_requests' => $service_requests,
			'stats'            => array_merge( $stats, array( 'shown' => count( $service_requests ) ) ),
		) );
	}

	/** Shared by get_ops_service_requests() (table stat cards) and get_ops_summary() (dashboard
	 *  tile) so "Needs Action" never disagrees between the two. */
	private function get_service_request_stats_for_ops() {
		$pdb  = $this->get_portal_db();
		$t_sr = $this->portal_table( 'aj_portal_service_requests' );
		$admin = class_exists( 'AJForms_Admin' ) ? ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() ) : null;

		$all_rows_for_stats = $pdb->get_results( "SELECT id, service_name, stripe_price_id, status, service_status FROM `{$t_sr}`" );
		$total_count        = count( $all_rows_for_stats );
		$action_count       = 0;
		$active_count       = 0;
		$completed_count    = 0;
		foreach ( $all_rows_for_stats as $stat_row ) {
			if ( $admin && $admin->service_request_needs_action_for_ops( $stat_row ) ) {
				$action_count++;
			}
			if ( 'active' === $stat_row->status || 'active' === $stat_row->service_status ) {
				$active_count++;
			}
			// Cancelled counts alongside completed here — both are "done, no further action needed"
			// from a staff perspective (a cancelled request, e.g. from an auto-cancelled refund, isn't
			// still open work) and previously had no stat-card home at all.
			if ( 'completed' === $stat_row->status || 'completed' === $stat_row->service_status || 'cancelled' === $stat_row->service_status ) {
				$completed_count++;
			}
		}

		return array(
			'total'        => $total_count,
			'needs_action' => $action_count,
			'active'       => $active_count,
			'completed'    => $completed_count,
		);
	}

	public function update_ops_service_request( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin  = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$result = $admin->update_service_request_from_ops(
			(int) $request->get_param( 'id' ),
			array(
				'status'           => $request->get_param( 'status' ),
				'service_status'   => $request->get_param( 'service_status' ),
				'admin_notes'      => $request->get_param( 'admin_notes' ),
				'note'             => $request->get_param( 'note' ),
				'assigned_user_id' => $request->get_param( 'assigned_user_id' ),
				'notify_customer'  => rest_sanitize_boolean( $request->get_param( 'notify_customer' ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'update_failed', $result->get_error_message(), array( 'status' => 'not_found' === $result->get_error_code() ? 404 : 400 ) );
		}
		return rest_ensure_response( array(
			'success'         => true,
			'service_request' => array(
				'id'               => (int) $result->id,
				'status'           => (string) $result->status,
				'service_status'   => (string) $result->service_status,
				'admin_notes'      => (string) $result->admin_notes,
				'assigned_user_id' => (int) $result->assigned_user_id,
			),
		) );
	}

	/** Public entry point for the ops REST API — sends the "notify customer of current status"
	 *  email on demand, independent of a status change (SVC Status changes don't auto-notify). */
	public function notify_ops_service_request_status( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin  = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$result = $admin->notify_service_request_status_for_ops( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'notify_failed', $result->get_error_message(), array( 'status' => 'not_found' === $result->get_error_code() ? 404 : 400 ) );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function apply_ops_service_request_quick_action( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin  = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$result = $admin->apply_service_request_quick_action_for_ops(
			(int) $request->get_param( 'id' ),
			(string) $request->get_param( 'action' ),
			rest_sanitize_boolean( $request->get_param( 'notify' ) )
		);
		if ( is_wp_error( $result ) ) {
			$status = 'not_found' === $result->get_error_code() ? 404 : 400;
			return new WP_Error( 'action_failed', $result->get_error_message(), array( 'status' => $status ) );
		}
		return rest_ensure_response( array( 'success' => true, 'result' => $result ) );
	}

	public function get_ops_service_request_history( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return rest_ensure_response( array(
			'history' => $admin->get_service_request_history_for_ops( (int) $request->get_param( 'id' ) ),
		) );
	}

	public function bulk_update_ops_service_requests( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$ids = $request->get_param( 'ids' );
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return new WP_Error( 'bad_request', __( 'At least one request id is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		$admin   = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$fields  = array(
			'service_status'   => $request->get_param( 'service_status' ),
			'assigned_user_id' => $request->get_param( 'assigned_user_id' ),
		);
		$results = $admin->bulk_update_service_requests_for_ops( $ids, $fields );
		$failed  = array_filter( $results, function ( $r ) { return empty( $r['success'] ); } );

		return rest_ensure_response( array(
			'success' => empty( $failed ),
			'updated' => count( $results ) - count( $failed ),
			'failed'  => count( $failed ),
			'results' => $results,
		) );
	}

	public function get_ops_sync_logs( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'sync_logs' => $this->select_rows( $this->portal_table( 'aj_portal_sync_logs' ), array( 'id', 'run_key', 'job_name', 'status', 'message', 'started_at', 'finished_at', 'created_at' ), $request, array( 'run_key', 'job_name', 'status', 'message' ), 'created_at DESC, id DESC' ) ) );
	}

	private function format_ops_staff_user( $user ) {
		$is_admin = user_can( $user, 'manage_options' );

		$recent_logins = array();
		$pdb           = $this->get_portal_db();
		$event_table   = $this->portal_table( 'aj_portal_event_log' );
		if ( $this->table_exists( $pdb, $event_table ) ) {
			$rows = $pdb->get_results(
				$pdb->prepare(
					"SELECT source, created_at FROM `{$event_table}` WHERE event_type = 'user_login' AND actor_user_id = %d ORDER BY id DESC LIMIT 10",
					(int) $user->ID
				),
				ARRAY_A
			);
			$recent_logins = is_array( $rows ) ? $rows : array();
		}

		return array(
			'id'            => (int) $user->ID,
			'username'      => (string) $user->user_login,
			'display_name'  => (string) $user->display_name,
			'email'         => (string) $user->user_email,
			'roles'         => array_values( (array) $user->roles ),
			'is_admin'      => $is_admin,
			'ops_access'    => $is_admin || user_can( $user, 'ajcore_ops_access' ) || in_array( 'aj_ops_user', (array) $user->roles, true ),
			'last_login'    => (string) get_user_meta( $user->ID, 'ajcore_last_login', true ),
			'recent_logins' => $recent_logins,
		);
	}

	public function get_ops_staff() {
		$users = get_users( array( 'role__in' => array( 'administrator', 'aj_ops_user' ), 'number' => 200, 'orderby' => 'display_name' ) );
		return rest_ensure_response( array( 'staff' => array_map( array( $this, 'format_ops_staff_user' ), $users ) ) );
	}

	public function create_ops_staff( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only administrators can manage staff accounts.', 'ajforms' ), array( 'status' => 403 ) );
		}

		$email        = sanitize_email( (string) $request->get_param( 'email' ) );
		$display_name = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
		$username     = sanitize_user( (string) $request->get_param( 'username' ), true );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_exists', __( 'A user with this email already exists.', 'ajforms' ), array( 'status' => 409 ) );
		}
		if ( '' === $username ) {
			$username = sanitize_user( current( explode( '@', $email ) ), true );
		}
		if ( username_exists( $username ) ) {
			$username .= wp_rand( 100, 999 );
		}

		$user_id = wp_create_user( $username, wp_generate_password( 24, true, true ), $email );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'create_failed', $user_id->get_error_message(), array( 'status' => 400 ) );
		}
		wp_update_user( array( 'ID' => $user_id, 'display_name' => '' !== $display_name ? $display_name : $username ) );

		$user = get_user_by( 'id', $user_id );
		$user->set_role( 'aj_ops_user' );

		// Send a set-your-password email so the new staff member can log in.
		if ( class_exists( 'AJForms_Admin' ) ) {
			$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
			$admin->send_portal_user_password_reset( $user_id );
		}

		return rest_ensure_response( array( 'success' => true, 'staff' => $this->format_ops_staff_user( $user ) ) );
	}

	public function update_ops_staff( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only administrators can manage staff accounts.', 'ajforms' ), array( 'status' => 403 ) );
		}

		$user = get_userdata( absint( $request->get_param( 'id' ) ) );
		if ( ! $user ) {
			return new WP_Error( 'not_found', __( 'User not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'cannot_modify_admin', __( 'Administrators always have ops access — manage them in WordPress.', 'ajforms' ), array( 'status' => 400 ) );
		}
		if ( get_current_user_id() === (int) $user->ID ) {
			return new WP_Error( 'cannot_modify_self', __( 'You cannot change your own access.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$grant = rest_sanitize_boolean( $request->get_param( 'ops_access' ) );
		if ( $grant ) {
			$user->add_role( 'aj_ops_user' );
			$user->add_cap( 'ajcore_ops_access' );
		} else {
			$user->remove_role( 'aj_ops_user' );
			$user->remove_cap( 'ajcore_ops_access' );
		}

		return rest_ensure_response( array( 'success' => true, 'staff' => $this->format_ops_staff_user( get_userdata( $user->ID ) ) ) );
	}

	public function get_ops_email_log( WP_REST_Request $request ) {
		global $wpdb;
		// The email log is always a local table (mail is sent by this WordPress install).
		$table = $wpdb->prefix . 'aj_portal_email_log';
		if ( ! $this->table_exists( $wpdb, $table ) ) {
			return rest_ensure_response( array( 'emails' => array() ) );
		}

		$per_page = min( 2000, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$where    = '1=1';
		$params   = array();
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where  = '(to_email LIKE %s OR subject LIKE %s)';
			$params = array( $like, $like );
		}
		$params[] = $per_page;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, to_email, subject, status, error_message, created_at FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d", $params ),
			ARRAY_A
		);

		return rest_ensure_response( array( 'emails' => is_array( $rows ) ? $rows : array() ) );
	}

	public function update_ops_customer_partner( WP_REST_Request $request ) {
		$pdb            = $this->get_portal_db();
		$partners_table = $this->portal_table( 'aj_portal_partners' );
		$customer_id    = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$customer_table = $this->portal_table( 0 === strpos( $customer_id, 'local_' ) ? 'aj_portal_local_customers' : 'aj_portal_stripe_customers' );
		$customer_id_column = 0 === strpos( $customer_id, 'local_' ) ? 'local_customer_id' : 'stripe_customer_id';
		$partner_key    = sanitize_key( (string) $request->get_param( 'partner_key' ) );

		if ( '' !== $partner_key && $this->table_exists( $pdb, $partners_table ) ) {
			$exists = $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$partners_table}` WHERE partner_key = %s LIMIT 1", $partner_key ) );
			if ( ! $exists ) {
				return new WP_Error( 'invalid_partner', __( 'Unknown partner.', 'ajforms' ), array( 'status' => 400 ) );
			}
		}

		$updated = $pdb->update( $customer_table, array( 'partner_key' => $partner_key ), array( $customer_id_column => $customer_id ), array( '%s' ), array( '%s' ) );
		if ( false === $updated ) {
			return new WP_Error( 'update_failed', __( 'Could not update the customer partner.', 'ajforms' ), array( 'status' => 500 ) );
		}
		$partner_assignments = $this->portal_table( 'aj_portal_customer_partners' );
		if ( $this->table_exists( $pdb, $partner_assignments ) ) {
			$pdb->replace( $partner_assignments, array( 'customer_id' => $customer_id, 'partner_key' => $partner_key, 'source' => 'ajops' ), array( '%s', '%s', '%s' ) );
		}

		// Keep the payer classification portable across AJCore sites by mirroring it to Stripe metadata.
		if ( 0 === strpos( $customer_id, 'cus_' ) ) {
			$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
			$secret_key = trim( (string) ( $settings['stripe_secret_key'] ?? '' ) );
			if ( '' !== $secret_key ) {
				$stripe_response = wp_remote_post(
					'https://api.stripe.com/v1/customers/' . rawurlencode( $customer_id ),
					array(
						'headers' => array( 'Authorization' => 'Bearer ' . $secret_key, 'Content-Type' => 'application/x-www-form-urlencoded' ),
						'body'    => http_build_query( array( 'metadata[partner_key]' => $partner_key, 'metadata[customer_type]' => $partner_key ? $partner_key : 'direct' ) ),
						'timeout' => 30,
					)
				);
				if ( is_wp_error( $stripe_response ) || wp_remote_retrieve_response_code( $stripe_response ) >= 400 ) {
					return new WP_Error( 'stripe_partner_update_failed', __( 'The AJCore partner was saved, but Stripe metadata could not be updated.', 'ajforms' ), array( 'status' => 502 ) );
				}
			}
		}

		return rest_ensure_response( array( 'success' => true, 'partner_key' => $partner_key ) );
	}

	/**
	 * Durable local enrichment (PMB, ID expiration, document links) for any customer —
	 * Stripe-billed (cus_*) or local (local_*). Never written to Stripe.
	 */
	private function customer_profile_defaults() {
		return array(
			'pmb_number' => '', 'mailbox_start_date' => null,
			'id_type' => '', 'id_issuer' => '', 'id_expiration_date' => null, 'id_file_id' => 0,
			'address_proof_type' => '', 'address_proof_file_id' => 0,
			'form_1583_status' => 'none', 'form_1583_date' => null, 'form_1583_file_id' => 0,
			'notes' => '', 'extra' => array(),
		);
	}

	/**
	 * The upgrade routine runs at plugins_loaded, which can be before the shared portal DB is
	 * connected — so the profiles table may exist locally but not in the portal DB. Creating it
	 * here on demand is safe: the activator uses CREATE TABLE IF NOT EXISTS throughout.
	 */
	private function ensure_customer_profiles_table( $pdb, $table ) {
		if ( $this->table_exists( $pdb, $table ) ) {
			return true;
		}
		if ( ! class_exists( 'AJForms_Activator' ) && defined( 'AJFORMS_PLUGIN_DIR' ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
		}
		if ( class_exists( 'AJForms_Activator' ) && method_exists( 'AJForms_Activator', 'create_and_migrate_local_business_tables' ) ) {
			AJForms_Activator::create_and_migrate_local_business_tables( $pdb );
		}
		return $this->table_exists( $pdb, $table );
	}

	private function get_customer_profile_row( $customer_id ) {
		$pdb   = $this->get_portal_db();
		$table = $this->portal_table( 'aj_portal_customer_profiles' );
		$row   = $this->table_exists( $pdb, $table ) ? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$table}` WHERE customer_id=%s LIMIT 1", $customer_id ), ARRAY_A ) : null;
		$profile = $this->customer_profile_defaults();
		if ( $row ) {
			foreach ( array_keys( $profile ) as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$profile[ $key ] = $row[ $key ];
				}
			}
			$extra = json_decode( (string) ( $row['extra'] ?? '' ), true );
			$profile['extra'] = is_array( $extra ) ? $extra : array();
			foreach ( array( 'id_file_id', 'address_proof_file_id', 'form_1583_file_id' ) as $file_key ) {
				$profile[ $file_key ] = (int) $profile[ $file_key ];
			}
			$profile['updated_at'] = $row['updated_at'] ?? '';
		}
		$profile['customer_id'] = $customer_id;
		return $profile;
	}

	public function update_ops_customer_profile( WP_REST_Request $request ) {
		$pdb         = $this->get_portal_db();
		$table       = $this->portal_table( 'aj_portal_customer_profiles' );
		$customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		if ( ! $this->ensure_customer_profiles_table( $pdb, $table ) ) {
			return new WP_Error( 'ajcore_profiles_table_missing', __( 'Customer profile storage is not available yet — update AJCore.', 'ajforms' ), array( 'status' => 503 ) );
		}

		$date_or_null = function ( $value ) {
			$value = sanitize_text_field( (string) $value );
			return ( '' !== $value && strtotime( $value ) ) ? gmdate( 'Y-m-d', strtotime( $value ) ) : null;
		};
		$extra = $request->get_param( 'extra' );

		$data = array(
			'customer_id'           => $customer_id,
			'pmb_number'            => sanitize_text_field( (string) ( $request->get_param( 'pmb_number' ) ?? '' ) ),
			'mailbox_start_date'    => $date_or_null( $request->get_param( 'mailbox_start_date' ) ),
			'id_type'               => sanitize_text_field( (string) ( $request->get_param( 'id_type' ) ?? '' ) ),
			'id_issuer'             => sanitize_text_field( (string) ( $request->get_param( 'id_issuer' ) ?? '' ) ),
			'id_expiration_date'    => $date_or_null( $request->get_param( 'id_expiration_date' ) ),
			'id_file_id'            => absint( $request->get_param( 'id_file_id' ) ),
			'address_proof_type'    => sanitize_text_field( (string) ( $request->get_param( 'address_proof_type' ) ?? '' ) ),
			'address_proof_file_id' => absint( $request->get_param( 'address_proof_file_id' ) ),
			'form_1583_status'      => sanitize_key( (string) ( $request->get_param( 'form_1583_status' ) ?? 'none' ) ),
			'form_1583_date'        => $date_or_null( $request->get_param( 'form_1583_date' ) ),
			'form_1583_file_id'     => absint( $request->get_param( 'form_1583_file_id' ) ),
			'notes'                 => sanitize_textarea_field( (string) ( $request->get_param( 'notes' ) ?? '' ) ),
			'extra'                 => wp_json_encode( is_array( $extra ) ? $extra : array() ),
		);
		if ( ! in_array( $data['form_1583_status'], array( 'none', 'sent', 'received', 'notarized' ), true ) ) {
			$data['form_1583_status'] = 'none';
		}

		$existing_id = $pdb->get_var( $pdb->prepare( "SELECT id FROM `{$table}` WHERE customer_id=%s LIMIT 1", $customer_id ) );
		$saved = $existing_id
			? $pdb->update( $table, $data, array( 'id' => (int) $existing_id ) )
			: $pdb->insert( $table, $data );
		if ( false === $saved ) {
			return new WP_Error( 'ajcore_profile_save_failed', __( 'Could not save the customer profile.', 'ajforms' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'profile' => $this->get_customer_profile_row( $customer_id ) ) );
	}

	/** Customers whose ID has expired or expires within ?days (default 60), soonest first. */
	public function get_ops_expiring_id_profiles( WP_REST_Request $request ) {
		$pdb   = $this->get_portal_db();
		$table = $this->portal_table( 'aj_portal_customer_profiles' );
		$days  = min( 365, max( 1, absint( $request->get_param( 'days' ) ?: 60 ) ) );
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'profiles' => array(), 'days' => $days ) );
		}
		$rows = $pdb->get_results( $pdb->prepare(
			"SELECT customer_id, pmb_number, id_type, id_expiration_date FROM `{$table}`
			 WHERE id_expiration_date IS NOT NULL AND id_expiration_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
			 ORDER BY id_expiration_date ASC LIMIT 200", $days
		), ARRAY_A );
		$stripe_customers = $this->portal_table( 'aj_portal_stripe_customers' );
		$local_customers  = $this->portal_table( 'aj_portal_local_customers' );
		foreach ( (array) $rows as &$row ) {
			$is_local = 0 === strpos( $row['customer_id'], 'local_' );
			$lookup   = $is_local
				? ( $this->table_exists( $pdb, $local_customers ) ? $pdb->get_row( $pdb->prepare( "SELECT name,email FROM `{$local_customers}` WHERE local_customer_id=%s LIMIT 1", $row['customer_id'] ), ARRAY_A ) : null )
				: ( $this->table_exists( $pdb, $stripe_customers ) ? $pdb->get_row( $pdb->prepare( "SELECT name,email FROM `{$stripe_customers}` WHERE stripe_customer_id=%s LIMIT 1", $row['customer_id'] ), ARRAY_A ) : null );
			$row['customer_name']  = $lookup['name'] ?? '';
			$row['customer_email'] = $lookup['email'] ?? '';
			$row['expired']        = $row['id_expiration_date'] < gmdate( 'Y-m-d' );
		}
		unset( $row );
		return rest_ensure_response( array( 'profiles' => (array) $rows, 'days' => $days ) );
	}

	public function get_ops_product_counts() {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return rest_ensure_response( array( 'counts' => array( 'registered_agent_subscription' => 0, 'virtual_office_subscription' => 0 ) ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return rest_ensure_response( array( 'counts' => $admin->get_portal_core_subscription_product_counts() ) );
	}

	public function get_ops_partners() {
		$pdb            = $this->get_portal_db();
		$partners_table = $this->portal_table( 'aj_portal_partners' );
		$customer_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$local_customer_table = $this->portal_table( 'aj_portal_local_customers' );
		if ( ! $this->table_exists( $pdb, $partners_table ) ) {
			return rest_ensure_response( array( 'partners' => array() ) );
		}

		$admin     = class_exists( 'AJForms_Admin' ) ? ( AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin() ) : null;
		$price_map = $admin ? $admin->get_partner_price_map() : array();

		$partners = array();
		foreach ( (array) $pdb->get_results( "SELECT * FROM `{$partners_table}` ORDER BY name ASC" ) as $p ) {
			$accounts = $pdb->get_results(
				$pdb->prepare(
					"SELECT stripe_customer_id, name, email, portal_status, partner_price_id FROM `{$customer_table}` WHERE partner_key = %s ORDER BY name ASC",
					$p->partner_key
				)
			);
			$accounts = is_array( $accounts ) ? $accounts : array();
			if ( $this->table_exists( $pdb, $local_customer_table ) ) {
				$local_accounts = $pdb->get_results( $pdb->prepare(
					"SELECT local_customer_id AS stripe_customer_id,name,email,status AS portal_status,'' AS partner_price_id FROM `{$local_customer_table}` WHERE partner_key=%s ORDER BY name ASC",
					$p->partner_key
				) );
				$accounts = array_merge( $accounts, (array) $local_accounts );
			}

			$total        = 0.0;
			$account_rows = array();
			foreach ( $accounts as $account ) {
				$rate   = $admin ? $admin->get_partner_account_rate( $p, $account, $price_map ) : (float) $p->per_account_amount;
				$total += $rate;
				$account_rows[] = array(
					'stripe_customer_id' => (string) $account->stripe_customer_id,
					'name'               => (string) $account->name,
					'email'              => (string) $account->email,
					'portal_status'      => (string) $account->portal_status,
					'partner_price_id'   => (string) $account->partner_price_id,
					'rate'               => round( $rate, 2 ),
				);
			}

			$default_rate = $admin ? $admin->get_partner_account_rate( $p, (object) array( 'partner_price_id' => '' ), $price_map ) : (float) $p->per_account_amount;

			$partners[] = array(
				'id'                 => (int) $p->id,
				'partner_key'        => (string) $p->partner_key,
				'name'               => (string) $p->name,
				'billing_mode'       => (string) $p->billing_mode,
				'per_account_amount' => round( $default_rate, 2 ),
				'stripe_price_id'    => (string) $p->stripe_price_id,
				'currency'           => (string) $p->currency,
				'notes'              => (string) $p->notes,
				'account_count'      => count( $accounts ),
				'monthly_total'      => round( $total, 2 ),
				'accounts'           => $account_rows,
			);
		}

		return rest_ensure_response( array( 'partners' => $partners ) );
	}

	public function delete_ops_email_log_entry( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aj_portal_email_log';
		if ( ! $this->table_exists( $wpdb, $table ) ) {
			return new WP_Error( 'not_found', __( 'Email not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$wpdb->delete( $table, array( 'id' => absint( $request->get_param( 'id' ) ) ), array( '%d' ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function delete_ops_email_log_all() {
		global $wpdb;
		$table = $wpdb->prefix . 'aj_portal_email_log';
		if ( $this->table_exists( $wpdb, $table ) ) {
			$wpdb->query( "TRUNCATE TABLE `{$table}`" );
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_ops_email_log_entry( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aj_portal_email_log';
		if ( ! $this->table_exists( $wpdb, $table ) ) {
			return new WP_Error( 'not_found', __( 'Email not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, to_email, subject, headers, message, status, error_message, created_at FROM `{$table}` WHERE id = %d LIMIT 1", absint( $request->get_param( 'id' ) ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Email not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'email' => $row ) );
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
		$blocked = $this->block_impersonated_portal_write();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
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
		$blocked = $this->block_impersonated_portal_write();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
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
		$blocked = $this->block_impersonated_portal_write();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
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
		$blocked = $this->block_impersonated_portal_write();
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}
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

	// ── OPS Files Endpoint ────────────────────────────────────────────────────

	public function get_ops_files( WP_REST_Request $request ) {
		global $wpdb;

		$files_table = $wpdb->prefix . 'aj_portal_files';
		$users_table = $wpdb->prefix . 'aj_portal_file_users';

		if ( ! $this->table_exists( $wpdb, $files_table ) ) {
			return rest_ensure_response( array( 'files' => array(), 'stats' => array( 'total' => 0 ) ) );
		}

		$search   = isset( $request['search'] )   ? sanitize_text_field( $request['search'] )   : '';
		$category = isset( $request['category'] ) ? sanitize_text_field( $request['category'] ) : '';
		$status   = 'archived' === sanitize_key( (string) ( $request['status'] ?? 'active' ) ) ? 'archived' : 'active';
		$per_page = min( absint( $request['per_page'] ?? 200 ), 500 );
		if ( $per_page < 1 ) {
			$per_page = 200;
		}

		$where  = array( '1=1' );
		$params = array();
		$file_columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$files_table}`", 0 );
		$has_status = in_array( 'status', (array) $file_columns, true );
		if ( $has_status ) {
			$where[] = 'archived' === $status ? 'f.status = %s' : '( f.status <> %s OR f.status IS NULL )';
			$params[] = 'archived';
		} elseif ( 'archived' === $status ) {
			return rest_ensure_response( array( 'files' => array(), 'stats' => array( 'total' => 0 ), 'settings' => $this->ops_file_settings() ) );
		}

		if ( $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]   = '( f.title LIKE %s OR f.description LIKE %s OR f.category LIKE %s )';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		if ( $category ) {
			$where[]  = 'f.category = %s';
			$params[] = $category;
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$files = $wpdb->get_results( $wpdb->prepare( "SELECT f.* FROM `{$files_table}` f WHERE {$where_sql} ORDER BY f.created_at DESC LIMIT %d", $params ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_params = array_slice( $params, 0, -1 );
		$total = empty( $count_params ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$files_table}` f WHERE {$where_sql}" ) : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$files_table}` f WHERE {$where_sql}", $count_params ) );

		$has_users_table = $this->table_exists( $wpdb, $users_table );
		$result          = array();

		foreach ( $files as $file ) {
			$attachment_url = wp_get_attachment_url( (int) $file->attachment_id );
			$filename       = $attachment_url ? basename( wp_parse_url( $attachment_url, PHP_URL_PATH ) ) : '';

			$assignments = array();
			if ( $has_users_table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, user_email FROM `{$users_table}` WHERE file_id = %d ORDER BY user_email ASC, user_id ASC", (int) $file->id ) );
				foreach ( $rows as $row ) {
					if ( ! empty( $row->user_id ) ) {
						$user = get_userdata( (int) $row->user_id );
						if ( $user ) {
							$assignments[] = array(
								'type'  => 'user',
								'label' => $user->display_name . ' <' . $user->user_email . '>',
								'email' => $user->user_email,
							);
							continue;
						}
					}
					if ( ! empty( $row->user_email ) ) {
						$assignments[] = array(
							'type'  => 'email',
							'label' => (string) $row->user_email,
							'email' => (string) $row->user_email,
						);
					}
				}
			}

			$result[] = array(
				'id'            => (int) $file->id,
				'attachment_id' => (int) $file->attachment_id,
				'title'         => (string) $file->title,
				'category'      => (string) $file->category,
				'description'   => (string) $file->description,
				'file_url'      => $attachment_url ? (string) $attachment_url : '',
				'filename'      => $filename,
				'assignments'   => $assignments,
				'tags'          => $this->ops_get_file_tags( (int) $file->id ),
				'status'        => isset( $file->status ) && 'archived' === $file->status ? 'archived' : 'active',
				'created_at'    => (string) $file->created_at,
				'updated_at'    => (string) $file->updated_at,
			);
		}

		return rest_ensure_response( array(
			'files' => $result,
			'stats' => array( 'total' => $total ),
			'settings' => $this->ops_file_settings(),
		) );
	}

	public function create_ops_file( WP_REST_Request $request ) {
		global $wpdb;

		$files_table = $wpdb->prefix . 'aj_portal_files';

		if ( ! $this->table_exists( $wpdb, $files_table ) ) {
			return new WP_Error( 'no_table', 'Files table not found.', array( 'status' => 500 ) );
		}

		$file_params   = $request->get_file_params();
		$attachment_id = 0;
		$uploaded_file_path = '';

		if ( ! empty( $file_params['file'] ) && UPLOAD_ERR_OK === (int) $file_params['file']['error'] ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$uploaded = wp_handle_upload( $file_params['file'], array( 'test_form' => false ) );

			if ( isset( $uploaded['error'] ) ) {
				return new WP_Error( 'upload_error', $uploaded['error'], array( 'status' => 400 ) );
			}

			$att_title = sanitize_text_field( $request['title'] ?: basename( $file_params['file']['name'] ) );

			$attachment    = array(
				'post_title'     => $att_title,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => $uploaded['type'],
			);
			$attachment_id = wp_insert_attachment( $attachment, $uploaded['file'], 0, true );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$uploaded_file_path = $uploaded['file'];

		} elseif ( ! empty( $request['attachment_id'] ) ) {
			$attachment_id = absint( $request['attachment_id'] );
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				return new WP_Error( 'invalid_attachment', 'Attachment not found.', array( 'status' => 404 ) );
			}
		} else {
			return new WP_Error( 'no_file', 'No file uploaded and no attachment_id provided.', array( 'status' => 400 ) );
		}

		$title       = sanitize_text_field( $request['title'] ?: get_the_title( $attachment_id ) ?: '' );
		$category    = sanitize_text_field( $request['category'] ?: '' );
		$description = sanitize_textarea_field( $request['description'] ?: '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$files_table,
			array(
				'attachment_id' => $attachment_id,
				'title'         => $title,
				'category'      => $category,
				'description'   => $description,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$file_id = (int) $wpdb->insert_id;
		if ( ! $file_id ) {
			return new WP_Error( 'db_error', 'Could not create file record.', array( 'status' => 500 ) );
		}

		$this->ops_save_file_assignments( $file_id, $request['assigned_emails'] ?? '' );
		$this->ops_save_file_tags( $file_id, $request['tags'] ?? array() );
		if ( $uploaded_file_path ) {
			// Generate metadata only after the AJCore record, customer assignment,
			// and tags exist so automatic RustFS offload gets the customer/tag path.
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded_file_path ) );
		}

		return rest_ensure_response( $this->ops_get_file_row( $file_id ) );
	}

	public function update_ops_file( WP_REST_Request $request ) {
		global $wpdb;

		$files_table = $wpdb->prefix . 'aj_portal_files';
		$file_id     = absint( $request['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id FROM `{$files_table}` WHERE id = %d", $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $file ) {
			return new WP_Error( 'not_found', 'File not found.', array( 'status' => 404 ) );
		}

		$data = array( 'updated_at' => current_time( 'mysql' ) );
		$fmt  = array( '%s' );

		if ( null !== $request['title'] ) {
			$data['title'] = sanitize_text_field( $request['title'] );
			$fmt[]         = '%s';
		}
		if ( null !== $request['category'] ) {
			$data['category'] = sanitize_text_field( $request['category'] );
			$fmt[]            = '%s';
		}
		if ( null !== $request['description'] ) {
			$data['description'] = sanitize_textarea_field( $request['description'] );
			$fmt[]               = '%s';
		}
		if ( null !== $request['status'] && in_array( sanitize_key( (string) $request['status'] ), array( 'active', 'archived' ), true ) ) {
			$data['status'] = sanitize_key( (string) $request['status'] );
			$fmt[] = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( $files_table, $data, array( 'id' => $file_id ), $fmt, array( '%d' ) );

		if ( null !== $request['assigned_emails'] ) {
			$this->ops_save_file_assignments( $file_id, $request['assigned_emails'] );
		}
		if ( null !== $request['tags'] ) {
			$this->ops_save_file_tags( $file_id, (array) $request['tags'] );
		}
		if ( ( null !== $request['assigned_emails'] || null !== $request['tags'] ) && class_exists( 'AJCore_Storage_Service' ) && AJCore_Storage_Service::get_remote_record( (int) $file->attachment_id ) ) {
			// Existing remote files may have been uploaded before their customer/tag
			// assignment existed. Re-evaluate and relocate their object key on save.
			$relocated = AJCore_Storage_Service::migrate_attachment_ids( array( (int) $file->attachment_id ) );
			if ( ! empty( $relocated['failed'][ (int) $file->attachment_id ] ) ) {
				return new WP_Error( 'storage_relocation_failed', $relocated['failed'][ (int) $file->attachment_id ], array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( $this->ops_get_file_row( $file_id ) );
	}

	public function delete_ops_file( WP_REST_Request $request ) {
		global $wpdb;

		$files_table = $wpdb->prefix . 'aj_portal_files';
		$users_table = $wpdb->prefix . 'aj_portal_file_users';
		$file_id     = absint( $request['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id FROM `{$files_table}` WHERE id = %d", $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $file ) {
			return new WP_Error( 'not_found', 'File not found.', array( 'status' => 404 ) );
		}

		$other_references = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$files_table}` WHERE attachment_id = %d AND id <> %d", (int) $file->attachment_id, $file_id ) );
		if ( 0 === $other_references && class_exists( 'AJCore_Storage_Service' ) ) {
			$storage_deleted = AJCore_Storage_Service::delete_attachment_storage( (int) $file->attachment_id );
			if ( is_wp_error( $storage_deleted ) ) { return $storage_deleted; }
		}
		if ( 0 === $other_references && ! wp_delete_attachment( (int) $file->attachment_id, true ) ) {
			return new WP_Error( 'attachment_delete_failed', 'The storage object was handled, but WordPress could not delete the Media attachment.', array( 'status' => 500 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $users_table, array( 'file_id' => $file_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'aj_portal_file_tags', array( 'file_id' => $file_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $files_table, array( 'id' => $file_id ), array( '%d' ) );

		return rest_ensure_response( array( 'deleted' => true, 'id' => $file_id ) );
	}

	private function ops_save_file_assignments( $file_id, $raw_emails ) {
		global $wpdb;

		$users_table = $wpdb->prefix . 'aj_portal_file_users';
		if ( ! $this->table_exists( $wpdb, $users_table ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $users_table, array( 'file_id' => $file_id ), array( '%d' ) );

		$parts = preg_split( '/[\s,;]+/', (string) $raw_emails );
		foreach ( $parts as $email ) {
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$user = get_user_by( 'email', $email );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$users_table,
					array(
						'file_id'    => $file_id,
						'user_id'    => $user ? (int) $user->ID : 0,
						'user_email' => strtolower( $email ),
						'created_at' => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}
		}
	}

	private function ops_file_settings() {
		$settings = function_exists( 'ajcore_get_portal_file_settings' ) ? ajcore_get_portal_file_settings() : array();
		return array( 'categories' => array_values( (array) ( $settings['categories'] ?? array() ) ), 'tags' => (object) (array) ( $settings['tags'] ?? array() ) );
	}

	private function ops_get_file_tags( $file_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aj_portal_file_tags';
		if ( ! $this->table_exists( $wpdb, $table ) ) { return array(); }
		return array_values( array_map( 'sanitize_key', $wpdb->get_col( $wpdb->prepare( "SELECT tag_slug FROM `{$table}` WHERE file_id = %d ORDER BY tag_slug ASC", $file_id ) ) ) );
	}

	private function ops_save_file_tags( $file_id, $tags ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aj_portal_file_tags';
		if ( ! $this->table_exists( $wpdb, $table ) ) { return; }
		$allowed = array_keys( (array) ( $this->ops_file_settings()['tags'] ?? array() ) );
		$tags = array_values( array_intersect( array_unique( array_map( 'sanitize_key', (array) $tags ) ), $allowed ) );
		$wpdb->delete( $table, array( 'file_id' => $file_id ), array( '%d' ) );
		foreach ( $tags as $tag ) { $wpdb->insert( $table, array( 'file_id' => $file_id, 'tag_slug' => $tag, 'created_at' => current_time( 'mysql' ) ), array( '%d', '%s', '%s' ) ); }
	}

	private function ops_get_file_row( $file_id ) {
		global $wpdb;

		$files_table = $wpdb->prefix . 'aj_portal_files';
		$users_table = $wpdb->prefix . 'aj_portal_file_users';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$files_table}` WHERE id = %d", $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $file ) {
			return null;
		}

		$attachment_url = wp_get_attachment_url( (int) $file->attachment_id );
		$filename       = $attachment_url ? basename( wp_parse_url( $attachment_url, PHP_URL_PATH ) ) : '';
		$assignments    = array();

		if ( $this->table_exists( $wpdb, $users_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, user_email FROM `{$users_table}` WHERE file_id = %d ORDER BY user_email ASC", $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				if ( ! empty( $row->user_id ) ) {
					$user = get_userdata( (int) $row->user_id );
					if ( $user ) {
						$assignments[] = array( 'type' => 'user', 'label' => $user->display_name . ' <' . $user->user_email . '>', 'email' => $user->user_email );
						continue;
					}
				}
				if ( ! empty( $row->user_email ) ) {
					$assignments[] = array( 'type' => 'email', 'label' => (string) $row->user_email, 'email' => (string) $row->user_email );
				}
			}
		}

		return array(
			'id'            => (int) $file->id,
			'attachment_id' => (int) $file->attachment_id,
			'title'         => (string) $file->title,
			'category'      => (string) $file->category,
			'description'   => (string) $file->description,
			'file_url'      => $attachment_url ?: '',
			'filename'      => $filename,
			'assignments'   => $assignments,
			'tags'          => $this->ops_get_file_tags( (int) $file->id ),
			'status'        => isset( $file->status ) && 'archived' === $file->status ? 'archived' : 'active',
			'created_at'    => (string) $file->created_at,
			'updated_at'    => (string) $file->updated_at,
		);
	}

	// ── Mail intake endpoints ─────────────────────────────────────────────────
	// Physical mail / service-of-process workflow: log item received → scan/attach →
	// notify the client → track disposition. Separate from Files (a document library):
	// a mail item is a chain-of-custody record whose scan is optional; publishing the
	// scan into the client's Files is an explicit action for keeper documents.

	private function get_mail_items_table() {
		return $this->portal_table( 'aj_portal_mail_items' );
	}

	private function ensure_mail_items_table() {
		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		if ( $this->table_exists( $pdb, $table ) ) {
			return true;
		}
		if ( ! class_exists( 'AJForms_Activator' ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
		}
		AJForms_Activator::activate();
		return $this->table_exists( $pdb, $table );
	}

	private function mail_item_types() {
		return array( 'letter', 'legal', 'government', 'package', 'check', 'other' );
	}

	private function mail_item_dispositions() {
		return array( 'forwarded', 'picked_up', 'shredded', 'returned', 'held' );
	}

	private function format_mail_item_row( $row, $staff_view = true ) {
		$row = (array) $row;

		$item = array(
			'id'                 => (int) $row['id'],
			'mail_uuid'          => (string) $row['mail_uuid'],
			'stripe_customer_id' => (string) $row['stripe_customer_id'],
			'customer_email'     => (string) $row['customer_email'],
			'customer_name'      => isset( $row['customer_name'] ) ? (string) $row['customer_name'] : '',
			'recipient_name'     => (string) $row['recipient_name'],
			'mail_type'          => (string) $row['mail_type'],
			'is_sop'             => ! empty( $row['is_sop'] ),
			'sender_name'        => (string) $row['sender_name'],
			'carrier'            => (string) $row['carrier'],
			'tracking_number'    => (string) $row['tracking_number'],
			'description'        => (string) $row['description'],
			'status'             => (string) $row['status'],
			'disposition'        => (string) $row['disposition'],
			'scan_url'           => (string) $row['scan_url'],
			'file_id'            => (int) $row['file_id'],
			'received_at'        => (string) $row['received_at'],
			'notified_at'        => (string) $row['notified_at'],
			'disposed_at'        => (string) $row['disposed_at'],
			'created_at'         => (string) $row['created_at'],
			'updated_at'         => (string) $row['updated_at'],
		);

		if ( $staff_view ) {
			$item['scan_attachment_id'] = (int) $row['scan_attachment_id'];
			$item['admin_notes']        = (string) $row['admin_notes'];
			$item['created_by']         = (int) $row['created_by'];
		}

		return $item;
	}

	private function fetch_mail_item_row( $id ) {
		$pdb             = $this->get_portal_db();
		$table           = $this->get_mail_items_table();
		$customers_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$join            = $this->table_exists( $pdb, $customers_table )
			? "LEFT JOIN `{$customers_table}` c ON c.stripe_customer_id = m.stripe_customer_id"
			: '';
		$name_select     = '' !== $join ? 'c.name AS customer_name, COALESCE(NULLIF(m.customer_email, \'\'), c.email) AS customer_email' : 'm.customer_email';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $pdb->get_row( $pdb->prepare( "SELECT m.*, {$name_select} FROM `{$table}` m {$join} WHERE m.id = %d", absint( $id ) ), ARRAY_A );
	}

	private function log_mail_event( $event_type, $item, $details = array() ) {
		$pdb   = $this->get_portal_db();
		$table = $this->portal_table( 'aj_portal_event_log' );
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return;
		}
		$user = wp_get_current_user();
		$pdb->insert(
			$table,
			array(
				'event_type'         => sanitize_key( $event_type ),
				'severity'           => ! empty( $item['is_sop'] ) ? 'warning' : 'info',
				'source'             => 'ops_mail',
				'stripe_customer_id' => (string) $item['stripe_customer_id'],
				'actor_user_id'      => $user ? (int) $user->ID : 0,
				'actor_email'        => $user ? (string) $user->user_email : '',
				'details'            => wp_json_encode( array_merge( array( 'mail_item_id' => (int) $item['id'], 'mail_uuid' => (string) $item['mail_uuid'] ), $details ) ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/** Handles an optional multipart scan upload; returns array( attachment_id, url ) or WP_Error. */
	private function handle_mail_scan_upload( WP_REST_Request $request ) {
		$file_params = $request->get_file_params();
		if ( empty( $file_params['scan'] ) || UPLOAD_ERR_OK !== (int) $file_params['scan']['error'] ) {
			return array( 0, '' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$uploaded = wp_handle_upload( $file_params['scan'], array( 'test_form' => false ) );
		if ( isset( $uploaded['error'] ) ) {
			return new WP_Error( 'upload_error', $uploaded['error'], array( 'status' => 400 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sanitize_text_field( basename( $file_params['scan']['name'] ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => $uploaded['type'],
			),
			$uploaded['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );

		return array( (int) $attachment_id, (string) wp_get_attachment_url( $attachment_id ) );
	}

	public function get_ops_mail_items( WP_REST_Request $request ) {
		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'mail_items' => array(), 'stats' => array( 'total' => 0, 'received' => 0, 'awaiting_notify' => 0, 'open' => 0, 'sop_open' => 0 ) ) );
		}

		$customers_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$has_customers   = $this->table_exists( $pdb, $customers_table );
		$join            = $has_customers ? "LEFT JOIN `{$customers_table}` c ON c.stripe_customer_id = m.stripe_customer_id" : '';
		$name_select     = $has_customers ? 'c.name AS customer_name, COALESCE(NULLIF(m.customer_email, \'\'), c.email) AS customer_email' : 'm.customer_email';

		$where  = array( '1=1' );
		$params = array();

		$search = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $pdb->esc_like( $search ) . '%';
			$clause  = '( m.recipient_name LIKE %s OR m.sender_name LIKE %s OR m.tracking_number LIKE %s OR m.description LIKE %s OR m.customer_email LIKE %s OR m.stripe_customer_id LIKE %s';
			$params  = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
			if ( $has_customers ) {
				$clause  .= ' OR c.name LIKE %s OR c.email LIKE %s';
				$params[] = $like;
				$params[] = $like;
			}
			$where[] = $clause . ' )';
		}

		$status = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );
		if ( in_array( $status, array( 'received', 'scanned', 'notified', 'closed' ), true ) ) {
			$where[]  = 'm.status = %s';
			$params[] = $status;
		} elseif ( 'open' === $status ) {
			$where[] = "m.status <> 'closed'";
		}

		$mail_type = sanitize_key( (string) ( $request->get_param( 'mail_type' ) ?: '' ) );
		if ( in_array( $mail_type, $this->mail_item_types(), true ) ) {
			$where[]  = 'm.mail_type = %s';
			$params[] = $mail_type;
		}

		if ( ! empty( $request->get_param( 'sop' ) ) ) {
			$where[] = 'm.is_sop = 1';
		}

		$customer_filter = sanitize_text_field( (string) ( $request->get_param( 'stripe_customer_id' ) ?: '' ) );
		if ( '' !== $customer_filter ) {
			$where[]  = 'm.stripe_customer_id = %s';
			$params[] = $customer_filter;
		}

		$per_page = min( 500, max( 1, absint( $request->get_param( 'per_page' ) ?: 200 ) ) );
		$params[] = $per_page;

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $pdb->get_results( $pdb->prepare( "SELECT m.*, {$name_select} FROM `{$table}` m {$join} WHERE {$where_sql} ORDER BY (m.is_sop = 1 AND m.status <> 'closed') DESC, m.received_at DESC, m.id DESC LIMIT %d", $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$stats = $pdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(status = 'received') AS received,
				SUM(status IN ('received','scanned')) AS awaiting_notify,
				SUM(status <> 'closed') AS open_items,
				SUM(is_sop = 1 AND status <> 'closed') AS sop_open
			FROM `{$table}`",
			ARRAY_A
		);

		return rest_ensure_response( array(
			'mail_items' => array_map( array( $this, 'format_mail_item_row' ), $rows ),
			'stats'      => array(
				'total'           => (int) ( $stats['total'] ?? 0 ),
				'received'        => (int) ( $stats['received'] ?? 0 ),
				'awaiting_notify' => (int) ( $stats['awaiting_notify'] ?? 0 ),
				'open'            => (int) ( $stats['open_items'] ?? 0 ),
				'sop_open'        => (int) ( $stats['sop_open'] ?? 0 ),
			),
		) );
	}

	public function get_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_mail_item_row( $row ) );
	}

	public function create_ops_mail_item( WP_REST_Request $request ) {
		if ( ! $this->ensure_mail_items_table() ) {
			return new WP_Error( 'no_table', __( 'Mail items table could not be created.', 'ajforms' ), array( 'status' => 500 ) );
		}

		$recipient_name = sanitize_text_field( (string) ( $request->get_param( 'recipient_name' ) ?: '' ) );
		if ( '' === $recipient_name ) {
			return new WP_Error( 'missing_recipient', __( 'Recipient name is required.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$scan = $this->handle_mail_scan_upload( $request );
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		list( $scan_attachment_id, $scan_url ) = $scan;

		$mail_type = sanitize_key( (string) ( $request->get_param( 'mail_type' ) ?: 'letter' ) );
		if ( ! in_array( $mail_type, $this->mail_item_types(), true ) ) {
			$mail_type = 'other';
		}

		$received_at = sanitize_text_field( (string) ( $request->get_param( 'received_at' ) ?: '' ) );
		$received_ts = '' !== $received_at ? strtotime( $received_at ) : false;

		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		$data  = array(
			'mail_uuid'          => wp_generate_uuid4(),
			'stripe_customer_id' => sanitize_text_field( (string) ( $request->get_param( 'stripe_customer_id' ) ?: '' ) ),
			'customer_email'     => sanitize_email( (string) ( $request->get_param( 'customer_email' ) ?: '' ) ),
			'recipient_name'     => $recipient_name,
			'mail_type'          => $mail_type,
			'is_sop'             => ! empty( $request->get_param( 'is_sop' ) ) ? 1 : 0,
			'sender_name'        => sanitize_text_field( (string) ( $request->get_param( 'sender_name' ) ?: '' ) ),
			'carrier'            => sanitize_text_field( (string) ( $request->get_param( 'carrier' ) ?: '' ) ),
			'tracking_number'    => sanitize_text_field( (string) ( $request->get_param( 'tracking_number' ) ?: '' ) ),
			'description'        => sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?: '' ) ),
			'status'             => $scan_attachment_id ? 'scanned' : 'received',
			'disposition'        => '',
			'scan_attachment_id' => $scan_attachment_id,
			'scan_url'           => $scan_url,
			'received_at'        => false !== $received_ts ? gmdate( 'Y-m-d H:i:s', $received_ts ) : current_time( 'mysql' ),
			'admin_notes'        => sanitize_textarea_field( (string) ( $request->get_param( 'admin_notes' ) ?: '' ) ),
			'created_by'         => get_current_user_id(),
			'created_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
		);

		$inserted = $pdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not create mail item.', 'ajforms' ), array( 'status' => 500 ) );
		}

		$item_id = (int) $pdb->insert_id;
		$row     = $this->fetch_mail_item_row( $item_id );
		$this->log_mail_event( 'mail_item_created', $row );

		// Optional immediate client notification ("log & notify" in one step).
		$notified = false;
		if ( ! empty( $request->get_param( 'notify' ) ) ) {
			$result = $this->send_mail_item_notification( $row );
			if ( ! is_wp_error( $result ) ) {
				$notified = true;
				$row      = $this->fetch_mail_item_row( $item_id );
			}
		}

		$response = $this->format_mail_item_row( $row );
		$response['notified'] = $notified;
		return rest_ensure_response( $response );
	}

	public function update_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$scan = $this->handle_mail_scan_upload( $request );
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		list( $scan_attachment_id, $scan_url ) = $scan;

		$data = array( 'updated_at' => current_time( 'mysql' ) );
		$fmt  = array( '%s' );

		$text_fields = array(
			'recipient_name'  => 'sanitize_text_field',
			'sender_name'     => 'sanitize_text_field',
			'carrier'         => 'sanitize_text_field',
			'tracking_number' => 'sanitize_text_field',
			'description'     => 'sanitize_textarea_field',
			'admin_notes'     => 'sanitize_textarea_field',
			'stripe_customer_id' => 'sanitize_text_field',
			'customer_email'  => 'sanitize_email',
		);
		foreach ( $text_fields as $field => $sanitizer ) {
			if ( null !== $request->get_param( $field ) ) {
				$data[ $field ] = call_user_func( $sanitizer, (string) $request->get_param( $field ) );
				$fmt[]          = '%s';
			}
		}

		if ( null !== $request->get_param( 'mail_type' ) ) {
			$mail_type         = sanitize_key( (string) $request->get_param( 'mail_type' ) );
			$data['mail_type'] = in_array( $mail_type, $this->mail_item_types(), true ) ? $mail_type : 'other';
			$fmt[]             = '%s';
		}

		if ( null !== $request->get_param( 'is_sop' ) ) {
			$data['is_sop'] = ! empty( $request->get_param( 'is_sop' ) ) ? 1 : 0;
			$fmt[]          = '%d';
		}

		if ( null !== $request->get_param( 'received_at' ) ) {
			$ts = strtotime( sanitize_text_field( (string) $request->get_param( 'received_at' ) ) );
			if ( false !== $ts ) {
				$data['received_at'] = gmdate( 'Y-m-d H:i:s', $ts );
				$fmt[]               = '%s';
			}
		}

		if ( $scan_attachment_id ) {
			$data['scan_attachment_id'] = $scan_attachment_id;
			$fmt[]                      = '%d';
			$data['scan_url']           = $scan_url;
			$fmt[]                      = '%s';
			if ( 'received' === (string) $row['status'] ) {
				$data['status'] = 'scanned';
				$fmt[]          = '%s';
			}
		}

		$disposition = null !== $request->get_param( 'disposition' ) ? sanitize_key( (string) $request->get_param( 'disposition' ) ) : null;
		if ( null !== $disposition && '' !== $disposition ) {
			if ( ! in_array( $disposition, $this->mail_item_dispositions(), true ) ) {
				return new WP_Error( 'invalid_disposition', __( 'Invalid disposition.', 'ajforms' ), array( 'status' => 400 ) );
			}
			$data['disposition'] = $disposition;
			$fmt[]               = '%s';
			$data['disposed_at'] = current_time( 'mysql' );
			$fmt[]               = '%s';
			// "held" keeps the item open (e.g. awaiting pickup); everything else closes it.
			if ( 'held' !== $disposition ) {
				$data['status'] = 'closed';
				$fmt[]          = '%s';
			}
		} elseif ( '' === $disposition ) {
			$data['disposition'] = '';
			$fmt[]               = '%s';
			$data['disposed_at'] = null;
			$fmt[]               = '%s';
		}

		$pdb = $this->get_portal_db();
		$pdb->update( $this->get_mail_items_table(), $data, array( 'id' => (int) $row['id'] ), $fmt, array( '%d' ) );

		$updated = $this->fetch_mail_item_row( $row['id'] );
		if ( null !== $disposition && '' !== $disposition ) {
			$this->log_mail_event( 'mail_item_disposed', $updated, array( 'disposition' => $disposition ) );
		}

		return rest_ensure_response( $this->format_mail_item_row( $updated ) );
	}

	public function delete_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$pdb = $this->get_portal_db();
		$pdb->delete( $this->get_mail_items_table(), array( 'id' => (int) $row['id'] ), array( '%d' ) );
		return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $row['id'] ) );
	}

	/** Sends the client notification for a mail item row and stamps notified_at/status. */
	private function send_mail_item_notification( $row ) {
		$row   = (array) $row;
		$email = sanitize_email( (string) $row['customer_email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'no_email', __( 'No customer email on file for this mail item. Link a customer or set an email first.', 'ajforms' ), array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'ajcore_unavailable', __( 'Notification service unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$name  = ! empty( $row['customer_name'] ) ? (string) $row['customer_name'] : (string) $row['recipient_name'];
		$sent  = $admin->send_mail_item_notification_for_ops( $row, $name, $email );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'The notification email could not be sent.', 'ajforms' ), array( 'status' => 500 ) );
		}

		$pdb  = $this->get_portal_db();
		$data = array( 'notified_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) );
		$fmt  = array( '%s', '%s' );
		if ( in_array( (string) $row['status'], array( 'received', 'scanned' ), true ) ) {
			$data['status'] = 'notified';
			$fmt[]          = '%s';
		}
		$pdb->update( $this->get_mail_items_table(), $data, array( 'id' => (int) $row['id'] ), $fmt, array( '%d' ) );

		$this->log_mail_event( 'mail_item_notified', $row, array( 'email' => $email ) );
		return true;
	}

	public function notify_ops_mail_item( WP_REST_Request $request ) {
		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		$result = $this->send_mail_item_notification( $row );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $this->format_mail_item_row( $this->fetch_mail_item_row( $request['id'] ) ) );
	}

	/**
	 * Publishes the item's scan into the client Files library (for keeper documents) and
	 * links it back via file_id. Files live in the local WP database, same as /ops/files.
	 */
	public function publish_ops_mail_item_to_files( WP_REST_Request $request ) {
		global $wpdb;

		$row = $this->fetch_mail_item_row( $request['id'] );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Mail item not found.', 'ajforms' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $row['file_id'] ) ) {
			$existing_attachment_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attachment_id FROM `{$wpdb->prefix}aj_portal_files` WHERE id = %d LIMIT 1", (int) $row['file_id'] ) );
			if ( $existing_attachment_id && class_exists( 'AJCore_Storage_Service' ) ) {
				$relocated = AJCore_Storage_Service::migrate_attachment_ids( array( $existing_attachment_id ) );
				if ( ! empty( $relocated['failed'][ $existing_attachment_id ] ) ) {
					return new WP_Error( 'storage_relocation_failed', $relocated['failed'][ $existing_attachment_id ], array( 'status' => 500 ) );
				}
			}
			return rest_ensure_response( $this->format_mail_item_row( $row ) );
		}
		if ( empty( $row['scan_attachment_id'] ) ) {
			return new WP_Error( 'no_scan', __( 'Attach a scan before publishing to Files.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$files_table = $wpdb->prefix . 'aj_portal_files';
		$users_table = $wpdb->prefix . 'aj_portal_file_users';
		if ( ! $this->table_exists( $wpdb, $files_table ) ) {
			return new WP_Error( 'no_table', __( 'Files table not found.', 'ajforms' ), array( 'status' => 500 ) );
		}

		$received_day = ! empty( $row['received_at'] ) ? mysql2date( 'M j, Y', (string) $row['received_at'] ) : '';
		$title        = trim( sprintf( '%s — %s%s', (string) $row['recipient_name'], ucwords( str_replace( '_', ' ', (string) $row['mail_type'] ) ), '' !== $received_day ? ' (' . $received_day . ')' : '' ) );

		$wpdb->insert(
			$files_table,
			array(
				'attachment_id' => (int) $row['scan_attachment_id'],
				'title'         => $title,
				'category'      => 'Mail',
				'description'   => (string) $row['description'],
				'created_by'    => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$file_id = (int) $wpdb->insert_id;
		if ( ! $file_id ) {
			return new WP_Error( 'db_error', __( 'Could not create the file record.', 'ajforms' ), array( 'status' => 500 ) );
		}

		$email = sanitize_email( (string) $row['customer_email'] );
		if ( is_email( $email ) && $this->table_exists( $wpdb, $users_table ) ) {
			$user = get_user_by( 'email', $email );
			$wpdb->insert(
				$users_table,
				array( 'file_id' => $file_id, 'user_id' => $user ? (int) $user->ID : 0, 'user_email' => strtolower( $email ), 'created_at' => current_time( 'mysql' ) ),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		$pdb = $this->get_portal_db();
		$pdb->update(
			$this->get_mail_items_table(),
			array( 'file_id' => $file_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( class_exists( 'AJCore_Storage_Service' ) ) {
			$attachment_id = (int) $row['scan_attachment_id'];
			$relocated = AJCore_Storage_Service::migrate_attachment_ids( array( $attachment_id ) );
			if ( ! empty( $relocated['failed'][ $attachment_id ] ) ) {
				return new WP_Error( 'storage_relocation_failed', $relocated['failed'][ $attachment_id ], array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( $this->format_mail_item_row( $this->fetch_mail_item_row( $request['id'] ) ) );
	}

	/** Client mailbox: the current portal user's mail items, without staff-only fields. */
	public function get_portal_mail() {
		$pdb   = $this->get_portal_db();
		$table = $this->get_mail_items_table();
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return rest_ensure_response( array( 'mail_items' => array() ) );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$user               = wp_get_current_user();
		$user_email         = $user ? (string) $user->user_email : '';
		if ( '' === $stripe_customer_id && '' === $user_email ) {
			return rest_ensure_response( array( 'mail_items' => array() ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $pdb->get_results(
			$pdb->prepare(
				"SELECT * FROM `{$table}` WHERE ( stripe_customer_id = %s AND stripe_customer_id <> '' ) OR ( customer_email = %s AND customer_email <> '' ) ORDER BY received_at DESC, id DESC LIMIT 200",
				$stripe_customer_id,
				strtolower( $user_email )
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->format_mail_item_row( $row, false );
		}

		return rest_ensure_response( array( 'mail_items' => $items ) );
	}

	// ── OPS Reservation Endpoints ─────────────────────────────────────────────

	public function ops_get_reservations( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return rest_ensure_response( array( 'reservations' => array(), 'stats' => array( 'total' => 0, 'upcoming' => 0, 'confirmed' => 0, 'cancelled' => 0 ) ) );
		}
		$status       = sanitize_key( (string) ( $request->get_param( 'status' ) ?: '' ) );
		$resource_key = sanitize_key( (string) ( $request->get_param( 'resource_key' ) ?: '' ) );
		$pricing_type = sanitize_key( (string) ( $request->get_param( 'pricing_type' ) ?: '' ) );
		$date_from    = sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?: '' ) );
		$date_to      = sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?: '' ) );
		$limit        = min( 200, max( 1, absint( $request->get_param( 'per_page' ) ?: 100 ) ) );

		$filters = array( 'limit' => $limit );
		if ( '' !== $status )       { $filters['status']       = $status; }
		if ( '' !== $resource_key ) { $filters['resource_key'] = $resource_key; }
		if ( '' !== $pricing_type ) { $filters['pricing_type'] = $pricing_type; }
		if ( '' !== $date_from )    { $filters['date_from']    = $date_from; }
		if ( '' !== $date_to )      { $filters['date_to']      = $date_to; }

		$settings  = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone  = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$rows      = AJCore_Reservations::get_all_reservations( $filters );

		$formatted = array_map( function( $row ) use ( $timezone ) {
			return $this->format_reservation_row( (array) $row, $timezone, true );
		}, is_array( $rows ) ? $rows : array() );

		// Stats from full set (unfiltered)
		$all_rows  = AJCore_Reservations::get_all_reservations( array( 'limit' => 2000 ) );
		$all_rows  = is_array( $all_rows ) ? $all_rows : array();
		$total     = count( $all_rows );
		$upcoming  = 0;
		$confirmed = 0;
		$cancelled = 0;
		foreach ( $all_rows as $r ) {
			$s = isset( $r->status ) ? (string) $r->status : '';
			if ( in_array( $s, array( 'pending_payment', 'paid', 'paid_pending_calendar' ), true ) ) {
				$upcoming++;
			} elseif ( 'confirmed' === $s ) {
				$confirmed++;
			} elseif ( in_array( $s, array( 'cancelled', 'admin_archived' ), true ) ) {
				$cancelled++;
			}
		}

		return rest_ensure_response( array(
			'reservations' => array_values( $formatted ),
			'stats'        => array(
				'total'     => $total,
				'upcoming'  => $upcoming,
				'confirmed' => $confirmed,
				'cancelled' => $cancelled,
			),
		) );
	}

	/**
	 * Busy blocks for the staff booking calendar: local reservations (confirmed/paid
	 * plus live pending-payment holds) and Zoho calendar events, in the site timezone.
	 */
	public function ops_reservations_busy( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : get_option( 'ajforms_settings', array() );
		$site_tz  = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';
		$tz_obj   = new DateTimeZone( $site_tz );

		$start_raw = sanitize_text_field( (string) ( $request->get_param( 'start' ) ?: '' ) );
		$end_raw   = sanitize_text_field( (string) ( $request->get_param( 'end' ) ?: '' ) );
		try {
			$start_utc = '' !== $start_raw ? ( new DateTime( $start_raw ) )->setTimezone( new DateTimeZone( 'UTC' ) ) : new DateTime( 'now', new DateTimeZone( 'UTC' ) );
			$end_utc   = '' !== $end_raw ? ( new DateTime( $end_raw ) )->setTimezone( new DateTimeZone( 'UTC' ) ) : ( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+60 days' );
		} catch ( Exception $e ) {
			return new WP_Error( 'invalid_datetime', __( 'Invalid date range.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$events = array();

		$pdb   = AJCore_Reservations::get_pdb();
		$table = AJCore_Reservations::get_reservations_table();
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$hold_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( AJCore_Reservations::PENDING_HOLD_MINUTES * 60 ) );
			$rows        = $pdb->get_results(
				$pdb->prepare(
					"SELECT start_at, end_at, customer_name FROM `{$table}`
					 WHERE ( status IN ('confirmed','paid','paid_pending_calendar') OR ( status = 'pending_payment' AND created_at >= %s ) )
					 AND start_at < %s AND end_at > %s",
					$hold_cutoff,
					$end_utc->format( 'Y-m-d H:i:s' ),
					$start_utc->format( 'Y-m-d H:i:s' )
				)
			);
			foreach ( (array) $rows as $r ) {
				try {
					$s_dt = ( new DateTime( $r->start_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_obj );
					$e_dt = ( new DateTime( $r->end_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz_obj );
				} catch ( Exception $ex ) {
					continue;
				}
				$events[] = array(
					'type'  => 'booked',
					'title' => __( 'Booked', 'ajforms' ) . ( ! empty( $r->customer_name ) ? ' — ' . sanitize_text_field( (string) $r->customer_name ) : '' ),
					'start' => $s_dt->format( 'Y-m-d\TH:i:s' ),
					'end'   => $e_dt->format( 'Y-m-d\TH:i:s' ),
				);
			}
		}

		$zoho_cal_uid = ! empty( $settings['zoho_calendar_uid'] ) ? trim( (string) $settings['zoho_calendar_uid'] ) : '';
		if ( '' !== $zoho_cal_uid && class_exists( 'AJCore_Zoho_Calendar' ) ) {
			$zoho_token = AJCore_Zoho_Calendar::get_valid_token( $settings );
			if ( '' !== $zoho_token ) {
				$zoho_events = AJCore_Zoho_Calendar::get_events_for_range( $zoho_cal_uid, $start_utc->format( 'c' ), $end_utc->format( 'c' ), $site_tz, $zoho_token );
				if ( ! is_wp_error( $zoho_events ) ) {
					foreach ( $zoho_events as $ze ) {
						$ze['start']->setTimezone( $tz_obj );
						$ze['end']->setTimezone( $tz_obj );
						$events[] = array(
							'type'  => 'external',
							'title' => '' !== (string) $ze['title'] ? (string) $ze['title'] : __( 'Unavailable', 'ajforms' ),
							'start' => $ze['start']->format( 'Y-m-d\TH:i:s' ),
							'end'   => $ze['end']->format( 'Y-m-d\TH:i:s' ),
						);
					}
				}
			}
		}

		return rest_ensure_response( array(
			'timezone' => $site_tz,
			'events'   => $events,
		) );
	}

	public function ops_create_reservation( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJCore_Reservations' ) ) {
			return new WP_Error( 'reservation_unavailable', __( 'Reservation system unavailable.', 'ajforms' ), array( 'status' => 503 ) );
		}

		$reservation = AJCore_Reservations::create_manual_reservation(
			array(
				'date'               => $request->get_param( 'date' ),
				'start_time'         => $request->get_param( 'start_time' ),
				'end_time'           => $request->get_param( 'end_time' ),
				'timezone'           => $request->get_param( 'timezone' ),
				'resource_key'       => $request->get_param( 'resource_key' ),
				'stripe_customer_id' => $request->get_param( 'stripe_customer_id' ),
				'customer_name'      => $request->get_param( 'customer_name' ),
				'customer_email'     => $request->get_param( 'customer_email' ),
				'amount'             => $request->get_param( 'amount' ),
				'notes'              => $request->get_param( 'notes' ),
			)
		);
		if ( is_wp_error( $reservation ) ) {
			return new WP_Error( 'create_failed', $reservation->get_error_message(), array( 'status' => 400 ) );
		}

		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$timezone = ! empty( $settings['zoho_default_timezone'] ) ? $settings['zoho_default_timezone'] : 'America/New_York';

		return rest_ensure_response( array(
			'success'     => true,
			'reservation' => $this->format_reservation_row( (array) $reservation, $timezone, true ),
		) );
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
		$per_page = min( 2000, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
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

		// Auto-refresh: Zoho access tokens expire hourly; with the lenient mode below,
		// a stale token would 401 and report busy slots as available to AJOps/iOS.
		$api_token    = class_exists( 'AJCore_Zoho_Calendar' ) ? AJCore_Zoho_Calendar::get_valid_token( $settings ) : '';
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
				"SELECT f.id, f.attachment_id, f.title, f.description, f.category, f.created_at, f.updated_at FROM `{$files_table}` f INNER JOIN `{$link_table}` fu ON fu.file_id = f.id WHERE ( f.status <> 'archived' OR f.status IS NULL ) AND ( fu.user_id = %d OR fu.user_email = %s ) ORDER BY f.created_at DESC, f.id DESC LIMIT 100",
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

		if ( function_exists( 'ajcore_record_user_login' ) ) {
			ajcore_record_user_login( $user, 'ops_login' );
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
		$phone           = $this->normalize_us_phone_for_storage( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
		$description     = sanitize_text_field( (string) ( $request->get_param( 'description' ) ?? '' ) );
		$business_name   = sanitize_text_field( (string) ( $request->get_param( 'business_name' ) ?? '' ) );
		$individual_name = sanitize_text_field( (string) ( $request->get_param( 'individual_name' ) ?? '' ) );
		$addr_line1      = sanitize_text_field( (string) ( $request->get_param( 'addr_line1' ) ?? '' ) );
		$addr_line2      = sanitize_text_field( (string) ( $request->get_param( 'addr_line2' ) ?? '' ) );
		$addr_city       = sanitize_text_field( (string) ( $request->get_param( 'addr_city' ) ?? '' ) );
		$addr_state      = sanitize_text_field( (string) ( $request->get_param( 'addr_state' ) ?? '' ) );
		$addr_postal     = sanitize_text_field( (string) ( $request->get_param( 'addr_postal' ) ?? '' ) );
		$addr_country    = sanitize_text_field( (string) ( $request->get_param( 'addr_country' ) ?? '' ) );
		$customer_type   = sanitize_key( (string) ( $request->get_param( 'customer_type' ) ?? 'direct' ) );
		$customer_type   = in_array( $customer_type, array( 'direct', 'opus', 'alliance_vo' ), true ) ? $customer_type : 'direct';
		$partner_key     = 'opus' === $customer_type ? 'opus' : ( 'alliance_vo' === $customer_type ? 'alliance_vo' : '' );

		if ( empty( $name ) || empty( $email ) || empty( $phone ) ) {
			return new WP_Error( 'ajcore_missing_fields', 'Name, email, and phone are required.', array( 'status' => 400 ) );
		}

		// Reject duplicate emails: check portal DB before calling Stripe.
		$check_pdb   = $this->get_portal_db();
		$check_table = $this->portal_table( 'aj_portal_stripe_customers' );
		$local_check_table = $this->portal_table( 'aj_portal_local_customers' );
		if ( $this->table_exists( $check_pdb, $check_table ) ) {
			$existing_id = $check_pdb->get_var( $check_pdb->prepare( "SELECT stripe_customer_id FROM `{$check_table}` WHERE email = %s LIMIT 1", $email ) );
			if ( ! $existing_id && $this->table_exists( $check_pdb, $local_check_table ) ) {
				$existing_id = $check_pdb->get_var( $check_pdb->prepare( "SELECT local_customer_id FROM `{$local_check_table}` WHERE email = %s LIMIT 1", $email ) );
			}
			if ( $existing_id ) {
				return new WP_Error(
					'ajcore_duplicate_email',
					sprintf( 'A customer with email %s already exists.', $email ),
					array( 'status' => 409 )
				);
			}
		}

		if ( 'alliance_vo' === $customer_type ) {
			$local_customer_id = 'local_' . str_replace( '-', '', wp_generate_uuid4() );
			$metadata = array_filter( array( 'business_name' => $business_name, 'individual_name' => $individual_name, 'customer_type' => 'local' ) );
			$address_data = array_filter( array( 'line1' => $addr_line1, 'line2' => $addr_line2, 'city' => $addr_city, 'state' => $addr_state, 'postal_code' => $addr_postal, 'country' => $addr_country ) );
			$inserted = $check_pdb->insert(
				$local_check_table,
				array(
					'local_customer_id' => $local_customer_id, 'email' => $email, 'name' => $name, 'phone' => $phone,
					'description' => $description, 'address' => wp_json_encode( $address_data ), 'metadata' => wp_json_encode( $metadata ),
					'partner_key' => 'alliance_vo', 'status' => 'active', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				return new WP_Error( 'ajcore_local_customer_failed', 'Could not create the local AJCore customer.', array( 'status' => 500 ) );
			}
			$partner_assignments = $this->portal_table( 'aj_portal_customer_partners' );
			if ( $this->table_exists( $check_pdb, $partner_assignments ) ) {
				$check_pdb->replace( $partner_assignments, array( 'customer_id' => $local_customer_id, 'partner_key' => 'alliance_vo', 'source' => 'ajops' ), array( '%s', '%s', '%s' ) );
			}
			return rest_ensure_response( array( 'success' => true, 'customer' => array(
				'stripe_customer_id' => $local_customer_id, 'email' => $email, 'name' => $name,
				'phone' => $this->format_us_phone_for_display( $phone ), 'description' => $description,
				'address' => $address_data, 'metadata' => $metadata, 'partner_key' => 'alliance_vo',
				'portal_status' => 'active', 'livemode' => 0, 'synced_at' => current_time( 'mysql' ),
			) ) );
		}

		// Get Stripe secret key from plugin settings.
		$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$secret_key = trim( (string) ( $settings['stripe_secret_key'] ?? '' ) );

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'ajcore_stripe_not_configured', 'Stripe is not configured.', array( 'status' => 503 ) );
		}

		// Create customer in Stripe.
		$stripe_body = array( 'name' => $name, 'email' => $email, 'phone' => $phone );
		if ( '' !== $description ) {
			$stripe_body['description'] = $description;
		}
		if ( '' !== $business_name ) {
			$stripe_body['business_name']           = $business_name;
			$stripe_body['metadata[business_name]'] = $business_name;
		}
		if ( '' !== $individual_name ) {
			$stripe_body['individual_name']           = $individual_name;
			$stripe_body['metadata[individual_name]'] = $individual_name;
		}
		$stripe_body['metadata[customer_type]'] = $customer_type;
		if ( '' !== $partner_key ) {
			$stripe_body['metadata[partner_key]'] = $partner_key;
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
			$meta['customer_type'] = $customer_type;
			if ( '' !== $partner_key ) {
				$meta['partner_key'] = $partner_key;
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
					'phone'              => $this->normalize_us_phone_for_storage( $decoded['phone'] ?? $phone ),
					'description'        => $decoded['description'] ?? $description,
					'address'            => ! empty( $address_data ) ? wp_json_encode( $address_data ) : '',
					'metadata'           => ! empty( $meta ) ? wp_json_encode( $meta ) : '',
					'partner_key'        => $partner_key,
					'portal_status'      => 'active',
					'livemode'           => ! empty( $decoded['livemode'] ) ? 1 : 0,
					'synced_at'          => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}

		$customer_row = array(
			'stripe_customer_id' => $decoded['id'],
			'email'              => $decoded['email'] ?? '',
			'name'               => $decoded['name'] ?? '',
			'phone'              => $this->format_us_phone_for_display( $decoded['phone'] ?? $phone ),
			'description'        => $decoded['description'] ?? $description,
			'address'            => ! empty( $decoded['address'] ) ? $decoded['address'] : null,
			'metadata'           => ! empty( $decoded['metadata'] ) ? $decoded['metadata'] : null,
			'partner_key'        => $partner_key,
			'portal_status'      => 'active',
			'synced_at'          => current_time( 'mysql' ),
		);

		return rest_ensure_response( array( 'success' => true, 'customer' => $customer_row ) );
	}

	/**
	 * "Run Selected/Full Sync Now" — schedules the sync to run in the BACKGROUND (a one-off WP-Cron
	 * event) instead of running it inline within this request. A real Stripe sync (especially the
	 * "transactions" job, paging through full invoice/charge history) can take longer than a typical
	 * PHP or reverse-proxy request timeout allows — this looks instant against a small local/dev
	 * dataset but reliably fails on a live account with real data volume, returning a broken/empty
	 * response with no useful error message. AJOps polls /ops/sync/run-status with the returned
	 * run_key to know when it's actually done. Accepts an optional 'jobs' array (any of: products,
	 * customers, subscriptions, transactions); omitted/empty means the account's configured default.
	 */
	public function ops_trigger_sync( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		$jobs  = $request->get_param( 'jobs' );
		$jobs  = is_array( $jobs ) ? array_map( 'sanitize_key', $jobs ) : array();

		$run_key = $admin->trigger_manual_sync_for_ops( $jobs );
		if ( is_wp_error( $run_key ) ) {
			return new WP_Error( 'sync_schedule_failed', $run_key->get_error_message(), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'run_key' => $run_key ) );
	}

	/** Poll target for ops_trigger_sync()'s run_key — see get_sync_run_status_for_ops(). */
	public function get_ops_sync_run_status( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$run_key = sanitize_text_field( (string) $request->get_param( 'run_key' ) );
		if ( '' === $run_key ) {
			return new WP_Error( 'bad_request', 'run_key is required.', array( 'status' => 400 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return rest_ensure_response( $admin->get_sync_run_status_for_ops( $run_key ) );
	}

	/** Sync status for the AJOps global sync widget: jobs, enabled/frequency, last/next run. */
	public function get_ops_sync_status( WP_REST_Request $request ) {
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
		return rest_ensure_response( $admin->get_portal_sync_status_for_ops() );
	}

	public function ops_update_customer( WP_REST_Request $request ) {
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		$name               = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email              = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone              = $this->normalize_us_phone_for_storage( sanitize_text_field( (string) $request->get_param( 'phone' ) ) );
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

		if ( 0 === strpos( $stripe_customer_id, 'local_' ) ) {
			$pdb = $this->get_portal_db();
			$customer_table = $this->portal_table( 'aj_portal_local_customers' );
			$metadata = array_filter( array( 'business_name' => $business_name, 'individual_name' => $individual_name, 'customer_type' => 'local' ) );
			$address_data = array_filter( array( 'line1' => $addr_line1, 'line2' => $addr_line2, 'city' => $addr_city, 'state' => $addr_state, 'postal_code' => $addr_postal, 'country' => $addr_country ) );
			$updated = $pdb->update( $customer_table, array(
				'name' => $name, 'email' => $email, 'phone' => $phone, 'description' => $description,
				'address' => wp_json_encode( $address_data ), 'metadata' => wp_json_encode( $metadata ), 'updated_at' => current_time( 'mysql' ),
			), array( 'local_customer_id' => $stripe_customer_id ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%s' ) );
			if ( false === $updated ) { return new WP_Error( 'ajcore_local_customer_update_failed', 'Could not update the local AJCore customer.', array( 'status' => 500 ) ); }
			return rest_ensure_response( array( 'stripe_customer_id' => $stripe_customer_id, 'name' => $name, 'email' => $email, 'phone' => $this->format_us_phone_for_display( $phone ), 'description' => $description, 'address' => $address_data, 'metadata' => $metadata, 'portal_status' => 'active', 'synced_at' => current_time( 'mysql' ) ) );
		}

		// Get Stripe secret key.
		$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$secret_key = trim( (string) ( $settings['stripe_secret_key'] ?? '' ) );

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'ajcore_stripe_not_configured', 'Stripe is not configured.', array( 'status' => 503 ) );
		}

		// Build Stripe update body (POST to /v1/customers/{id} = update).
		$stripe_body = array( 'name' => $name, 'email' => $email, 'phone' => $phone );
		if ( '' !== $description ) {
			$stripe_body['description'] = $description;
		}
		if ( '' !== $business_name ) {
			$stripe_body['business_name']           = $business_name;
			$stripe_body['metadata[business_name]'] = $business_name;
		}
		if ( '' !== $individual_name ) {
			$stripe_body['individual_name']           = $individual_name;
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

			// Merge new values into existing metadata and raw_data.
			$existing = $pdb->get_row( $pdb->prepare(
				"SELECT metadata, raw_data FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1",
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

			// Build updated raw_data: start from fresh Stripe response, then overlay our explicit edits.
			// Using the Stripe response keeps all other fields current; the overlay ensures business_name
			// and individual_name are reflected immediately (Stripe stores these as top-level fields).
			$raw_obj = is_array( $decoded ) ? $decoded : array();
			if ( '' !== $business_name ) {
				$raw_obj['business_name'] = $business_name;
			}
			if ( '' !== $individual_name ) {
				$raw_obj['individual_name'] = $individual_name;
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
					'raw_data'    => ! empty( $raw_obj ) ? wp_json_encode( $raw_obj ) : '',
					'synced_at'   => current_time( 'mysql' ),
				),
				array( 'stripe_customer_id' => $stripe_customer_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);
		}

		$customer_row = array(
			'stripe_customer_id' => $decoded['id'],
			'email'              => $decoded['email'] ?? $email,
			'name'               => $decoded['name'] ?? $name,
			'phone'              => $this->format_us_phone_for_display( $decoded['phone'] ?? $phone ),
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
			// Ensure the WP user + mapping exist first; repair alone only relinks existing users.
			$enabled = AJForms_Admin::$instance->enable_stripe_customer_as_portal_user( $stripe_customer_id );
			if ( is_wp_error( $enabled ) ) {
				return new WP_Error( 'action_failed', $enabled->get_error_message(), array( 'status' => 400 ) );
			}
			$stats = AJForms_Admin::$instance->repair_portal_user_links_and_roles( true, true, true, array( $stripe_customer_id ) );
			return rest_ensure_response( array( 'success' => true, 'stats' => $stats ) );
		}

		$customer = $this->table_exists( $pdb, $customer_table )
			? $pdb->get_row( $pdb->prepare( "SELECT * FROM `{$customer_table}` WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ) )
			: null;

		if ( ! $customer ) {
			return new WP_Error( 'customer_not_found', 'Customer not found.', array( 'status' => 404 ) );
		}

		$mapping_table   = $wpdb->prefix . 'aj_auth_user_mappings';
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

	public function ops_create_customer_impersonation_link( WP_REST_Request $request ) {
		$stripe_customer_id = sanitize_text_field( (string) $request->get_param( 'stripe_customer_id' ) );
		if ( ! class_exists( 'AJForms_Admin' ) ) {
			return new WP_Error( 'admin_unavailable', 'Admin handler not initialized.', array( 'status' => 503 ) );
		}
		$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();

		$return_url = '';
		$params     = $request->get_json_params();
		if ( is_array( $params ) && ! empty( $params['return_url'] ) ) {
			$return_url = esc_url_raw( (string) $params['return_url'] );
		}

		$result = $admin->create_customer_impersonation_link( $stripe_customer_id, 'ajops', $return_url );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'url'     => esc_url_raw( $result ),
			)
		);
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

	// ── Leads (aj_forms_leads — on the shared portal DB in multi-site mode, so
	//    leads captured by forms on every site are visible here; local otherwise) ──

	private function extract_lead_field( $decoded, $preferred_keys ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		$skip_types = array( 'radio', 'checkbox', 'select', 'hidden', 'file', 'button', 'submit' );

		// First pass: text-input fields only — prevents a radio "Company yes/no" field
		// from matching before a text "Business Name" field.
		foreach ( $preferred_keys as $preferred_key ) {
			foreach ( $decoded as $field_key => $field ) {
				if ( ! is_array( $field ) || '_meta' === $field_key ) {
					continue;
				}
				$type = isset( $field['type'] ) ? strtolower( trim( $field['type'] ) ) : '';
				if ( in_array( $type, $skip_types, true ) ) {
					continue;
				}
				$label = isset( $field['label'] ) ? strtolower( trim( $field['label'] ) ) : '';
				$key   = strtolower( trim( (string) $field_key ) );
				if ( false !== strpos( $label, $preferred_key ) || false !== strpos( $key, $preferred_key ) ) {
					$value = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					return (string) $value;
				}
			}
		}

		// Second pass: any field type (fallback for forms that use select/radio for contact fields).
		foreach ( $preferred_keys as $preferred_key ) {
			foreach ( $decoded as $field_key => $field ) {
				if ( ! is_array( $field ) || '_meta' === $field_key ) {
					continue;
				}
				$label = isset( $field['label'] ) ? strtolower( trim( $field['label'] ) ) : '';
				$key   = strtolower( trim( (string) $field_key ) );
				if ( false !== strpos( $label, $preferred_key ) || false !== strpos( $key, $preferred_key ) ) {
					$value = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					return (string) $value;
				}
			}
		}

		return '';
	}

	/** Companion to extract_lead_field() — same two-pass fuzzy match, but returns the matching
	 *  field's key (or null) instead of its value, so an edit can update that exact field in
	 *  place rather than bolting on a new, possibly-duplicate key. */
	private function find_lead_field_key( $decoded, $preferred_keys ) {
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$skip_types = array( 'radio', 'checkbox', 'select', 'hidden', 'file', 'button', 'submit' );

		foreach ( $preferred_keys as $preferred_key ) {
			foreach ( $decoded as $field_key => $field ) {
				if ( ! is_array( $field ) || '_meta' === $field_key ) {
					continue;
				}
				$type = isset( $field['type'] ) ? strtolower( trim( $field['type'] ) ) : '';
				if ( in_array( $type, $skip_types, true ) ) {
					continue;
				}
				$label = isset( $field['label'] ) ? strtolower( trim( $field['label'] ) ) : '';
				$key   = strtolower( trim( (string) $field_key ) );
				if ( false !== strpos( $label, $preferred_key ) || false !== strpos( $key, $preferred_key ) ) {
					return $field_key;
				}
			}
		}

		foreach ( $preferred_keys as $preferred_key ) {
			foreach ( $decoded as $field_key => $field ) {
				if ( ! is_array( $field ) || '_meta' === $field_key ) {
					continue;
				}
				$label = isset( $field['label'] ) ? strtolower( trim( $field['label'] ) ) : '';
				$key   = strtolower( trim( (string) $field_key ) );
				if ( false !== strpos( $label, $preferred_key ) || false !== strpos( $key, $preferred_key ) ) {
					return $field_key;
				}
			}
		}

		return null;
	}

	/** Updates a lead's editable core fields (name/email/phone/company/source/notes) in place.
	 *  Locates the field already supplying each value (via the same fuzzy matching read uses)
	 *  and overwrites just its 'value', so form-submitted leads keep their original field/label
	 *  structure instead of accumulating duplicate synthetic keys. Only params actually present
	 *  in the request are touched — omitted fields are left alone. */
	public function update_ops_lead_fields( WP_REST_Request $request ) {
		$wpdb        = $this->get_portal_db();
		$leads_table = $wpdb->prefix . 'aj_forms_leads';
		$lead_id     = absint( $request->get_param( 'id' ) );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, lead_data FROM `{$leads_table}` WHERE id = %d", $lead_id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'ajcore_lead_not_found', __( 'Lead not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$decoded = json_decode( isset( $row['lead_data'] ) ? (string) $row['lead_data'] : '{}', true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		$field_map = array(
			'name'    => array( 'label' => 'Name',    'type' => 'text',     'preferred_keys' => array( 'name', 'full name', 'your name' ) ),
			'email'   => array( 'label' => 'Email',   'type' => 'email',    'preferred_keys' => array( 'email', 'e-mail' ) ),
			'phone'   => array( 'label' => 'Phone',   'type' => 'text',     'preferred_keys' => array( 'phone', 'mobile', 'tel', 'cell' ) ),
			'company' => array( 'label' => 'Company', 'type' => 'text',     'preferred_keys' => array( 'business name', 'company name', 'company', 'business', 'organization', 'organisation' ) ),
			'source'  => array( 'label' => 'Source',  'type' => 'text',     'preferred_keys' => array( 'source' ) ),
			'notes'   => array( 'label' => 'Notes',   'type' => 'textarea', 'preferred_keys' => array( 'notes', 'message', 'comment', 'additional' ) ),
		);
		$skip_types = array( 'radio', 'checkbox', 'select', 'hidden', 'file', 'button', 'submit' );

		$updated_any = false;
		foreach ( $field_map as $param => $spec ) {
			if ( null === $request->get_param( $param ) ) {
				continue;
			}
			$raw = (string) $request->get_param( $param );
			if ( 'email' === $param ) {
				$value = sanitize_email( $raw );
			} elseif ( 'phone' === $param ) {
				$value = '' !== trim( $raw ) ? $this->normalize_us_phone_for_storage( $raw ) : '';
			} elseif ( 'notes' === $param ) {
				$value = sanitize_textarea_field( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}

			$existing_key = $this->find_lead_field_key( $decoded, $spec['preferred_keys'] );
			$target_key   = $existing_key ? $existing_key : $param;
			$existing     = isset( $decoded[ $target_key ] ) && is_array( $decoded[ $target_key ] ) ? $decoded[ $target_key ] : array();
			$existing_type = isset( $existing['type'] ) ? strtolower( trim( (string) $existing['type'] ) ) : '';

			$decoded[ $target_key ] = array(
				'label' => isset( $existing['label'] ) && '' !== $existing['label'] ? $existing['label'] : $spec['label'],
				'type'  => ( '' !== $existing_type && ! in_array( $existing_type, $skip_types, true ) ) ? $existing['type'] : $spec['type'],
				'value' => $value,
			);
			$updated_any = true;
		}

		if ( ! $updated_any ) {
			return new WP_Error( 'ajcore_no_lead_fields', __( 'No editable fields were provided.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$wpdb->update(
			$leads_table,
			array( 'lead_data' => wp_json_encode( $decoded ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $lead_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$fresh_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT l.* FROM `{$leads_table}` l WHERE l.id = %d", $lead_id ),
			ARRAY_A
		);
		$lead = $this->format_lead_row( $fresh_row, $this->get_customers_by_id_for_leads( array( $fresh_row ) ) );

		return rest_ensure_response( array( 'success' => true, 'lead' => $lead ) );
	}

	private function phone_digits( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	private function normalize_us_phone_for_storage( $phone ) {
		$phone  = trim( (string) $phone );
		$digits = $this->phone_digits( $phone );

		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}

		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			return '+' . $digits;
		}

		return $phone;
	}

	private function format_us_phone_for_display( $phone ) {
		$phone  = trim( (string) $phone );
		$digits = $this->phone_digits( $phone );
		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) ) {
			return '+1 ' . substr( $digits, 0, 3 ) . ' ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}

		return $phone;
	}

	private function format_ops_customer_row( $row ) {
		if ( is_array( $row ) && array_key_exists( 'phone', $row ) ) {
			$row['phone'] = $this->format_us_phone_for_display( $row['phone'] );
		}
		if ( is_array( $row ) && array_key_exists( 'portal_status', $row ) ) {
			$status = sanitize_key( (string) $row['portal_status'] );
			if ( 'without_login' === $status ) {
				$status = 'without_portal_login';
			}
			if ( ! in_array( $status, array( 'active', 'disabled', 'archived', 'without_portal_login' ), true ) ) {
				// Legacy rows hold '0' / '1' / '' here — derive from enabled_portal like wp-admin does.
				$status = ! empty( $row['enabled_portal'] ) ? 'active' : 'disabled';
			}
			$row['portal_status'] = $status;
		}
		return $row;
	}

	/** Statuses a lead can be in. "won"/"duplicate" collapse into the Archived queue on the
	 *  client; "lost" is its own queue; "new"/"read" are the active Inbox. "new" is the default
	 *  status on creation; "read" is set automatically when staff open the lead (not manually). */
	private function get_lead_status_labels() {
		return array(
			'new'       => __( 'New', 'ajforms' ),
			'read'      => __( 'Read', 'ajforms' ),
			'won'       => __( 'Won', 'ajforms' ),
			'lost'      => __( 'Lost', 'ajforms' ),
			'duplicate' => __( 'Duplicate', 'ajforms' ),
		);
	}

	private function format_lead_row( $row, $customers_by_id = array() ) {
		$decoded    = json_decode( isset( $row['lead_data'] ) ? (string) $row['lead_data'] : '{}', true );
		$meta       = isset( $decoded['_meta'] ) && is_array( $decoded['_meta'] ) ? $decoded['_meta'] : array();
		$source_val = $this->extract_lead_field( $decoded, array( 'source' ) );
		if ( '' === $source_val ) {
			$source_val = isset( $meta['source'] ) ? (string) $meta['source'] : '';
		}
		$company_raw    = $this->extract_lead_field( $decoded, array( 'business name', 'company name', 'company', 'business', 'organization', 'organisation' ) );
		$boolean_values = array( 'yes', 'no', 'true', 'false', '1', '0' );
		$company        = in_array( strtolower( trim( $company_raw ) ), $boolean_values, true ) ? '' : $company_raw;

		$stripe_customer_id = isset( $row['stripe_customer_id'] ) ? (string) $row['stripe_customer_id'] : '';
		$customer           = '' !== $stripe_customer_id && isset( $customers_by_id[ $stripe_customer_id ] ) ? $customers_by_id[ $stripe_customer_id ] : null;
		$site_uuid          = isset( $row['site_uuid'] ) ? (string) $row['site_uuid'] : '';

		return array(
			'id'                  => (int) $row['id'],
			'form_id'             => (int) $row['form_id'],
			'form_title'          => isset( $row['form_title'] ) ? (string) $row['form_title'] : '',
			'status'              => isset( $row['status'] ) ? (string) $row['status'] : 'new',
			'name'                => $this->extract_lead_field( $decoded, array( 'name', 'full name', 'your name' ) ),
			'email'               => $this->extract_lead_field( $decoded, array( 'email', 'e-mail' ) ),
			'phone'               => $this->format_us_phone_for_display( $this->extract_lead_field( $decoded, array( 'phone', 'mobile', 'tel', 'cell' ) ) ),
			'company'             => $company,
			'source'              => $source_val,
			'notes'               => $this->extract_lead_field( $decoded, array( 'notes', 'message', 'comment', 'additional' ) ),
			'source_url'          => isset( $row['source_url'] ) ? (string) $row['source_url'] : '',
			'user_agent'          => isset( $row['user_agent'] ) ? (string) $row['user_agent'] : '',
			'created_at'          => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
			'updated_at'          => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
			'stripe_customer_id'  => $stripe_customer_id,
			'customer_name'       => $customer ? (string) $customer->name : '',
			'customer_email'      => $customer ? (string) $customer->email : '',
			'merged_into_lead_id' => isset( $row['merged_into_lead_id'] ) ? (int) $row['merged_into_lead_id'] : 0,
			'site_uuid'           => $site_uuid,
			'site_label'          => $this->get_site_label( $site_uuid ),
		);
	}

	/** Human label for a site_uuid, resolved from the shared aj_shared_sites control table
	 *  (its domain column, stripped to a bare hostname). Cached per request — leads/customers
	 *  lists resolve the same handful of sites over and over. */
	private function get_site_label( $site_uuid ) {
		$site_uuid = (string) $site_uuid;
		if ( '' === $site_uuid ) {
			return '';
		}

		static $labels = null;
		if ( null === $labels ) {
			$labels = array();
			$pdb    = $this->get_portal_db();
			$table  = $pdb->prefix . 'aj_shared_sites';
			if ( $this->table_exists( $pdb, $table ) ) {
				foreach ( (array) $pdb->get_results( "SELECT site_uuid, domain FROM `{$table}`" ) as $site ) {
					$host = (string) wp_parse_url( (string) $site->domain, PHP_URL_HOST );
					$labels[ (string) $site->site_uuid ] = '' !== $host ? $host : trim( (string) $site->domain, '/' );
				}
			}
		}

		return isset( $labels[ $site_uuid ] ) ? $labels[ $site_uuid ] : '';
	}

	/** Batch-resolves stripe_customer_id => {name,email} for a set of lead rows, matching the
	 *  assignee-resolution pattern used by get_ops_service_requests (one query, not N). */
	private function get_customers_by_id_for_leads( $rows ) {
		$ids = array_values( array_unique( array_filter( array_map(
			function ( $r ) { return isset( $r['stripe_customer_id'] ) ? (string) $r['stripe_customer_id'] : ''; },
			(array) $rows
		) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$pdb   = $this->get_portal_db();
		$table = $this->portal_table( 'aj_portal_stripe_customers' );
		if ( ! $this->table_exists( $pdb, $table ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_out = $pdb->get_results( $pdb->prepare( "SELECT stripe_customer_id, name, email FROM `{$table}` WHERE stripe_customer_id IN ({$placeholders})", $ids ) );
		$by_id    = array();
		foreach ( (array) $rows_out as $c ) {
			$by_id[ (string) $c->stripe_customer_id ] = $c;
		}
		return $by_id;
	}

	public function get_ops_leads( WP_REST_Request $request ) {
		$wpdb        = $this->get_portal_db();
		$leads_table = $wpdb->prefix . 'aj_forms_leads';
		$per_page    = min( 2000, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$search      = sanitize_text_field( (string) $request->get_param( 'search' ) );

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) ) !== $leads_table ) {
			return rest_ensure_response( array( 'leads' => array() ) );
		}

		$where  = '1=1';
		$params = array();
		if ( '' !== $search ) {
			$where    = '( l.lead_data LIKE %s )';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$params[] = $per_page;

		// form_title is stored on the lead row itself (stamped at insert) — the forms table is
		// per-site/local, so a JOIN can't resolve titles for leads captured on other sites.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.form_id, l.form_title, l.lead_data, l.status, l.source_url, l.user_agent, l.created_at, l.updated_at, l.site_uuid, l.stripe_customer_id, l.merged_into_lead_id
				 FROM `{$leads_table}` l
				 WHERE {$where}
				 ORDER BY l.created_at DESC, l.id DESC
				 LIMIT %d",
				$params
			),
			ARRAY_A
		);

		$customers_by_id = $this->get_customers_by_id_for_leads( $rows );

		// Batch-fetch every listed lead's notes (one query) so the list can show activity —
		// latest note, call/text logs, and the "Follow-up email sent" timestamp.
		$notes_by_lead = array();
		$lead_ids      = array_values( array_filter( array_map( function ( $r ) { return (int) $r['id']; }, (array) $rows ) ) );
		if ( ! empty( $lead_ids ) ) {
			$notes_table  = $wpdb->prefix . 'aj_forms_lead_notes';
			$placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$note_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, lead_id, note, created_by, author_name, created_at FROM `{$notes_table}` WHERE lead_id IN ({$placeholders}) ORDER BY created_at ASC, id ASC", $lead_ids ), ARRAY_A );
			foreach ( (array) $note_rows as $n ) {
				$notes_by_lead[ (int) $n['lead_id'] ][] = array(
					'id'          => (int) $n['id'],
					'note'        => (string) $n['note'],
					'created_by'  => (int) $n['created_by'],
					'author_name' => isset( $n['author_name'] ) ? (string) $n['author_name'] : '',
					'created_at'  => (string) $n['created_at'],
				);
			}
		}

		$leads = array();
		foreach ( (array) $rows as $row ) {
			$lead               = $this->format_lead_row( $row, $customers_by_id );
			$lead['notes_list'] = isset( $notes_by_lead[ (int) $row['id'] ] ) ? $notes_by_lead[ (int) $row['id'] ] : array();
			$leads[]            = $lead;
		}

		return rest_ensure_response( array( 'leads' => $leads ) );
	}

	public function get_ops_lead_detail( WP_REST_Request $request ) {
		$wpdb        = $this->get_portal_db();
		$leads_table = $wpdb->prefix . 'aj_forms_leads';
		$notes_table = $wpdb->prefix . 'aj_forms_lead_notes';
		$lead_id     = absint( $request->get_param( 'id' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT l.* FROM `{$leads_table}` l WHERE l.id = %d", $lead_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'ajcore_lead_not_found', __( 'Lead not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$lead = $this->format_lead_row( $row, $this->get_customers_by_id_for_leads( array( $row ) ) );

		// Notes carry their own author_name (stamped at insert) — the users table is per-site,
		// so a JOIN can't resolve authors for notes written on other sites. Fall back to a local
		// user lookup for legacy rows saved before the column existed.
		$note_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.id, n.note, n.created_by, n.author_name, n.created_at
				 FROM `{$notes_table}` n
				 WHERE n.lead_id = %d
				 ORDER BY n.created_at ASC",
				$lead_id
			),
			ARRAY_A
		);

		$lead['notes_list'] = array();
		foreach ( (array) $note_rows as $n ) {
			$author_name = isset( $n['author_name'] ) ? (string) $n['author_name'] : '';
			if ( '' === $author_name && ! empty( $n['created_by'] ) ) {
				$author       = get_userdata( (int) $n['created_by'] );
				$author_name  = $author ? (string) $author->display_name : '';
			}
			$lead['notes_list'][] = array(
				'id'          => (int) $n['id'],
				'note'        => (string) $n['note'],
				'created_by'  => (int) $n['created_by'],
				'author_name' => $author_name,
				'created_at'  => (string) $n['created_at'],
			);
		}

		$decoded = json_decode( isset( $row['lead_data'] ) ? (string) $row['lead_data'] : '{}', true );
		$fields  = array();
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $key => $field ) {
				if ( '_meta' === $key || ! is_array( $field ) ) {
					continue;
				}
				$value = isset( $field['value'] ) ? $field['value'] : '';
				if ( is_array( $value ) ) {
					$value = implode( ', ', $value );
				}
				if ( '' !== (string) $value ) {
					$fields[] = array(
						'key'   => $key,
						'label' => isset( $field['label'] ) ? (string) $field['label'] : $key,
						'value' => (string) $value,
					);
				}
			}
		}
		$lead['all_fields'] = $fields;

		return rest_ensure_response( array( 'lead' => $lead ) );
	}

	public function ops_add_lead_note( WP_REST_Request $request ) {
		$wpdb        = $this->get_portal_db();
		$leads_table = $wpdb->prefix . 'aj_forms_leads';
		$notes_table = $wpdb->prefix . 'aj_forms_lead_notes';
		$lead_id     = absint( $request->get_param( 'id' ) );
		$note        = (string) $request->get_param( 'note' );

		if ( '' === $note ) {
			return new WP_Error( 'ajcore_empty_note', __( 'Note cannot be empty.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$leads_table}` WHERE id = %d", $lead_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'ajcore_lead_not_found', __( 'Lead not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$user = wp_get_current_user();
		$wpdb->insert(
			$notes_table,
			array(
				'lead_id'     => $lead_id,
				'note'        => $note,
				'created_by'  => $user ? (int) $user->ID : 0,
				'author_name' => $user ? (string) $user->display_name : '',
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$note_id     = (int) $wpdb->insert_id;
		$author_name = $user ? $user->display_name : '';

		return rest_ensure_response( array(
			'note' => array(
				'id'          => $note_id,
				'note'        => $note,
				'created_by'  => $user ? (int) $user->ID : 0,
				'author_name' => $author_name,
				'created_at'  => current_time( 'mysql' ),
			),
		) );
	}

	/**
	 * Scans wp-content/uploads/ajphone-rules/ for *.json files.
	 * Returns merged rule array if any files exist, null if the folder is empty or missing.
	 * Creates the folder (and a README) on first call.
	 */
	private function load_rules_from_folder() {
		$upload_dir = wp_upload_dir();
		$folder     = trailingslashit( $upload_dir['basedir'] ) . 'ajphone-rules';

		if ( ! is_dir( $folder ) ) {
			wp_mkdir_p( $folder );
			// Deny direct HTTP access to raw JSON files.
			file_put_contents( $folder . '/.htaccess', "Order deny,allow\nDeny from all\n" ); // phpcs:ignore
			file_put_contents( $folder . '/README.txt', // phpcs:ignore
				"AJPhone Automation Rules Folder\n" .
				"================================\n" .
				"Drop any number of .json files here. Filename does not matter — only the .json extension.\n" .
				"Each file can be a plain JSON array of rules, or an object with a \"rules\" key.\n" .
				"ALL files are merged together. When at least one file is present, these folder rules\n" .
				"override whatever is saved in the database via the UI.\n\n" .
				"Remove all .json files to fall back to the database rules.\n"
			);
			return null;
		}

		$json_files = glob( $folder . '/*.json' );
		if ( empty( $json_files ) ) {
			return null;
		}

		$all_rules = array();
		foreach ( $json_files as $file ) {
			$content = file_get_contents( $file ); // phpcs:ignore
			if ( false === $content ) {
				continue;
			}
			$decoded = json_decode( $content, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			// Support bare array [ {...}, ... ] or object { "rules": [ {...}, ... ] }
			$rules = ( isset( $decoded['rules'] ) && is_array( $decoded['rules'] ) ) ? $decoded['rules'] : $decoded;
			foreach ( $rules as $rule ) {
				if ( is_array( $rule ) ) {
					$all_rules[] = $rule;
				}
			}
		}

		return empty( $all_rules ) ? null : $all_rules;
	}

	public function get_ops_ajphone_settings( WP_REST_Request $request ) {
		$monitored_raw   = (string) get_option( 'ajcore_ajphone_monitored_user_ids', '[]' );
		$monitored_ids   = json_decode( $monitored_raw, true );
		$monitored_raw_2 = (string) get_option( 'ajcore_ajphone_monitored_user_ids_2', '[]' );
		$monitored_ids_2 = json_decode( $monitored_raw_2, true );

		// Folder-mode: .json files in uploads/ajphone-rules/ override the database.
		$upload_dir          = wp_upload_dir();
		$rules_folder_path   = trailingslashit( $upload_dir['basedir'] ) . 'ajphone-rules';
		$folder_rules        = $this->load_rules_from_folder();
		if ( null !== $folder_rules ) {
			$automation_rules  = $folder_rules;
			$rules_source      = 'folder';
		} else {
			$automation_raw    = (string) get_option( 'ajcore_ajphone_automation_rules', '[]' );
			$automation_rules  = json_decode( $automation_raw, true );
			if ( ! is_array( $automation_rules ) ) {
				$automation_rules = array();
			}
			$rules_source = 'database';
		}

		$automation_logs_raw = (string) get_option( 'ajcore_ajphone_automation_logs', '[]' );
		$automation_logs     = json_decode( $automation_logs_raw, true );

		return rest_ensure_response( array(
			'account_id'                => (string) get_option( 'ajcore_ajphone_account_id', '' ),
			'client_id'                 => (string) get_option( 'ajcore_ajphone_client_id', '' ),
			'client_secret'             => (string) get_option( 'ajcore_ajphone_client_secret', '' ),
			'phone_number'              => (string) get_option( 'ajcore_ajphone_phone_number', '' ),
			'monitored_user_ids'        => is_array( $monitored_ids ) ? $monitored_ids : array(),
			'account_id_2'              => (string) get_option( 'ajcore_ajphone_account_id_2', '' ),
			'client_id_2'              => (string) get_option( 'ajcore_ajphone_client_id_2', '' ),
			'client_secret_2'           => (string) get_option( 'ajcore_ajphone_client_secret_2', '' ),
			'phone_number_2'            => (string) get_option( 'ajcore_ajphone_phone_number_2', '' ),
			'monitored_user_ids_2'      => is_array( $monitored_ids_2 ) ? $monitored_ids_2 : array(),
			'account_label_2'           => (string) get_option( 'ajcore_ajphone_account_label_2', '' ),
			'automation_enabled'                 => (string) get_option( 'ajcore_ajphone_automation_enabled', '0' ),
			'automation_enabled_at'              => (string) get_option( 'ajcore_ajphone_automation_enabled_at', '' ),
			'automation_default_cooldown_minutes' => (int) get_option( 'ajcore_ajphone_automation_default_cooldown_minutes', 15 ),
			'automation_bypass_staff_review'      => (bool) get_option( 'ajcore_ajphone_automation_bypass_staff_review', false ),
			'automation_staff_notify_number'      => (string) get_option( 'ajcore_ajphone_automation_staff_notify_number', '' ),
			'automation_rules'                   => is_array( $automation_rules ) ? $automation_rules : array(),
			'automation_logs'                    => is_array( $automation_logs ) ? $automation_logs : array(),
			'automation_rules_source'            => $rules_source,
			'automation_rules_folder'            => $rules_folder_path,
			'automation_last_run_at'             => (string) get_option( 'ajcore_ajphone_automation_last_run_at', '' ),
			'lead_auto_outreach_enabled'         => (string) get_option( 'ajcore_lead_auto_outreach_enabled', '0' ),
			'lead_auto_outreach_baseline_id'     => (int) get_option( 'ajcore_lead_auto_outreach_baseline_id', 0 ),
			'lead_auto_outreach_last_processed_id' => (int) get_option( 'ajcore_lead_auto_outreach_last_processed_id', 0 ),
			'lead_auto_outreach_from_number'     => (string) get_option( 'ajcore_lead_auto_outreach_from_number', '' ),
			'lead_auto_outreach_account_key'     => (string) get_option( 'ajcore_lead_auto_outreach_account_key', '' ),
			'lead_auto_outreach_template'        => (string) get_option( 'ajcore_lead_auto_outreach_template', '' ),
			'lead_auto_outreach_sites'           => (string) get_option( 'ajcore_lead_auto_outreach_sites', '' ),
		) );
	}

	public function update_ops_ajphone_settings( WP_REST_Request $request ) {
		$account_id    = (string) ( $request->get_param( 'account_id' )    ?? '' );
		$client_id     = (string) ( $request->get_param( 'client_id' )     ?? '' );
		$client_secret = (string) ( $request->get_param( 'client_secret' ) ?? '' );
		$phone_number  = (string) ( $request->get_param( 'phone_number' )  ?? '' );

		if ( '' !== $account_id ) {
			update_option( 'ajcore_ajphone_account_id', $account_id, false );
		}
		if ( '' !== $client_id ) {
			update_option( 'ajcore_ajphone_client_id', $client_id, false );
		}
		if ( '' !== $client_secret && '***' !== $client_secret ) {
			update_option( 'ajcore_ajphone_client_secret', $client_secret, false );
		}
		if ( $request->has_param( 'phone_number' ) ) {
			update_option( 'ajcore_ajphone_phone_number', $phone_number, false );
		}

		$monitored_user_ids = $request->get_param( 'monitored_user_ids' );
		if ( is_array( $monitored_user_ids ) ) {
			update_option( 'ajcore_ajphone_monitored_user_ids', wp_json_encode( array_map( 'strval', $monitored_user_ids ) ), false );
		}

		// Second account — always overwrite to allow clearing when second account is disabled.
		$account_id_2    = (string) ( $request->get_param( 'account_id_2' )    ?? '' );
		$client_id_2     = (string) ( $request->get_param( 'client_id_2' )     ?? '' );
		$client_secret_2 = (string) ( $request->get_param( 'client_secret_2' ) ?? '' );
		$phone_number_2  = (string) ( $request->get_param( 'phone_number_2' )  ?? '' );
		$account_label_2 = (string) ( $request->get_param( 'account_label_2' ) ?? '' );

		if ( $request->has_param( 'account_id_2' ) ) {
			update_option( 'ajcore_ajphone_account_id_2', $account_id_2, false );
		}
		if ( $request->has_param( 'client_id_2' ) ) {
			update_option( 'ajcore_ajphone_client_id_2', $client_id_2, false );
		}
		if ( $request->has_param( 'phone_number_2' ) ) {
			update_option( 'ajcore_ajphone_phone_number_2', $phone_number_2, false );
		}
		if ( $request->has_param( 'account_label_2' ) ) {
			update_option( 'ajcore_ajphone_account_label_2', $account_label_2, false );
		}
		if ( '' !== $client_secret_2 && '***' !== $client_secret_2 ) {
			update_option( 'ajcore_ajphone_client_secret_2', $client_secret_2, false );
		}

		$monitored_user_ids_2 = $request->get_param( 'monitored_user_ids_2' );
		if ( is_array( $monitored_user_ids_2 ) ) {
			update_option( 'ajcore_ajphone_monitored_user_ids_2', wp_json_encode( array_map( 'strval', $monitored_user_ids_2 ) ), false );
		}

		$automation_enabled = $request->get_param( 'automation_enabled' );
		if ( null !== $automation_enabled ) {
			update_option( 'ajcore_ajphone_automation_enabled', in_array( (string) $automation_enabled, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0', false );
		}
		if ( $request->has_param( 'automation_enabled_at' ) ) {
			update_option( 'ajcore_ajphone_automation_enabled_at', (string) $request->get_param( 'automation_enabled_at' ), false );
		}
		$default_cooldown = $request->get_param( 'automation_default_cooldown_minutes' );
		if ( null !== $default_cooldown && is_numeric( $default_cooldown ) ) {
			update_option( 'ajcore_ajphone_automation_default_cooldown_minutes', max( 0, (int) $default_cooldown ), false );
		}
		$last_run_at = $request->get_param( 'automation_last_run_at' );
		if ( null !== $last_run_at && '' !== (string) $last_run_at ) {
			update_option( 'ajcore_ajphone_automation_last_run_at', sanitize_text_field( (string) $last_run_at ), false );
		}
		$bypass_review = $request->get_param( 'automation_bypass_staff_review' );
		if ( null !== $bypass_review ) {
			update_option( 'ajcore_ajphone_automation_bypass_staff_review', in_array( (string) $bypass_review, array( '1', 'true', 'yes', 'on' ), true ), false );
		}
		if ( $request->has_param( 'automation_staff_notify_number' ) ) {
			update_option( 'ajcore_ajphone_automation_staff_notify_number', sanitize_text_field( (string) $request->get_param( 'automation_staff_notify_number' ) ), false );
		}

		$lead_auto_enabled = $request->get_param( 'lead_auto_outreach_enabled' );
		if ( null !== $lead_auto_enabled ) {
			update_option( 'ajcore_lead_auto_outreach_enabled', in_array( (string) $lead_auto_enabled, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '0', false );
		}
		$lead_auto_baseline = $request->get_param( 'lead_auto_outreach_baseline_id' );
		if ( null !== $lead_auto_baseline && is_numeric( $lead_auto_baseline ) ) {
			update_option( 'ajcore_lead_auto_outreach_baseline_id', max( 0, (int) $lead_auto_baseline ), false );
		}
		$lead_auto_last = $request->get_param( 'lead_auto_outreach_last_processed_id' );
		if ( null !== $lead_auto_last && is_numeric( $lead_auto_last ) ) {
			update_option( 'ajcore_lead_auto_outreach_last_processed_id', max( 0, (int) $lead_auto_last ), false );
		}
		if ( $request->has_param( 'lead_auto_outreach_from_number' ) ) {
			update_option( 'ajcore_lead_auto_outreach_from_number', sanitize_text_field( (string) $request->get_param( 'lead_auto_outreach_from_number' ) ), false );
		}
		if ( $request->has_param( 'lead_auto_outreach_account_key' ) ) {
			update_option( 'ajcore_lead_auto_outreach_account_key', sanitize_key( (string) $request->get_param( 'lead_auto_outreach_account_key' ) ), false );
		}
		if ( $request->has_param( 'lead_auto_outreach_template' ) ) {
			update_option( 'ajcore_lead_auto_outreach_template', sanitize_textarea_field( (string) $request->get_param( 'lead_auto_outreach_template' ) ), false );
		}
		// Per-site auto-outreach configs: JSON map of site_uuid => settings. Stored opaque —
		// AJ Ops owns the shape; we just validate it parses as JSON before persisting.
		if ( $request->has_param( 'lead_auto_outreach_sites' ) ) {
			$sites_raw = $request->get_param( 'lead_auto_outreach_sites' );
			if ( is_array( $sites_raw ) ) {
				$sites_raw = wp_json_encode( $sites_raw );
			}
			$sites_raw = (string) $sites_raw;
			if ( '' === $sites_raw || null !== json_decode( $sites_raw, true ) ) {
				update_option( 'ajcore_lead_auto_outreach_sites', $sites_raw, false );
			}
		}

		$automation_rules = $request->get_param( 'automation_rules' );
		if ( is_array( $automation_rules ) ) {
			$valid_match_types  = array( 'contains', 'exact', 'regex', 'fallback' );
			$valid_hours_modes  = array( 'any', 'business_hours', 'after_hours' );
			$clean_rules        = array();
			foreach ( $automation_rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$match_type    = in_array( (string) ( $rule['matchType'] ?? '' ), $valid_match_types, true )
					? (string) $rule['matchType'] : 'contains';
				$incoming_text = sanitize_text_field( (string) ( $rule['incomingText'] ?? '' ) );
				$response_text = sanitize_textarea_field( (string) ( $rule['responseText'] ?? '' ) );
				// fallback rules have no trigger phrases — only response is required.
				if ( 'fallback' !== $match_type && '' === $incoming_text ) {
					continue;
				}
				if ( '' === $response_text ) {
					continue;
				}
				$hours_mode = in_array( (string) ( $rule['businessHoursMode'] ?? '' ), $valid_hours_modes, true )
					? (string) $rule['businessHoursMode'] : 'any';
				$priority   = isset( $rule['priority'] ) && is_numeric( $rule['priority'] )
					? max( 1, min( 999, (int) $rule['priority'] ) ) : 50;
				$cooldown   = isset( $rule['cooldownMinutes'] ) && is_numeric( $rule['cooldownMinutes'] )
					? max( 0, (int) $rule['cooldownMinutes'] ) : 0;
				$clean_rules[] = array(
					'id'                => sanitize_text_field( (string) ( $rule['id'] ?? wp_generate_uuid4() ) ),
					'enabled'           => ! empty( $rule['enabled'] ),
					'matchType'         => $match_type,
					'incomingText'      => $incoming_text,
					'responseText'      => $response_text,
					'priority'          => $priority,
					'category'          => sanitize_text_field( (string) ( $rule['category'] ?? 'General' ) ),
					'title'             => sanitize_text_field( (string) ( $rule['title'] ?? '' ) ),
					'staffReview'       => ! empty( $rule['staffReview'] ),
					'cooldownMinutes'   => $cooldown,
					'businessHoursMode' => $hours_mode,
					'stopProcessing'    => ! empty( $rule['stopProcessing'] ),
				);
			}
			update_option( 'ajcore_ajphone_automation_rules', wp_json_encode( $clean_rules ), false );
		}

		$automation_logs = $request->get_param( 'automation_logs' );
		if ( is_array( $automation_logs ) ) {
			$valid_statuses = array( 'sent', 'failed', 'skipped' );
			$clean_logs     = array();
			foreach ( array_slice( $automation_logs, 0, 100 ) as $log ) {
				if ( ! is_array( $log ) ) {
					continue;
				}
				$status      = (string) ( $log['status'] ?? 'sent' );
				$clean_logs[] = array(
					'id'           => sanitize_text_field( (string) ( $log['id'] ?? wp_generate_uuid4() ) ),
					'ranAt'        => sanitize_text_field( (string) ( $log['ranAt'] ?? '' ) ),
					'status'       => in_array( $status, $valid_statuses, true ) ? $status : 'sent',
					'ruleId'       => sanitize_text_field( (string) ( $log['ruleId'] ?? '' ) ),
					'ruleName'     => sanitize_text_field( (string) ( $log['ruleName'] ?? '' ) ),
					'triggerText'  => sanitize_text_field( (string) ( $log['triggerText'] ?? '' ) ),
					'inboundText'  => sanitize_textarea_field( (string) ( $log['inboundText'] ?? '' ) ),
					'responseText' => sanitize_textarea_field( (string) ( $log['responseText'] ?? '' ) ),
					'from'         => sanitize_text_field( (string) ( $log['from'] ?? '' ) ),
					'to'           => sanitize_text_field( (string) ( $log['to'] ?? '' ) ),
					'accountKey'   => sanitize_text_field( (string) ( $log['accountKey'] ?? '' ) ),
					'error'        => sanitize_textarea_field( (string) ( $log['error'] ?? '' ) ),
				);
			}
			update_option( 'ajcore_ajphone_automation_logs', wp_json_encode( $clean_logs ), false );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_ops_ajphone_conversations( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ajphone_conversations';

		$keys = $request->get_param( 'keys' );
		if ( empty( $keys ) || ! is_array( $keys ) ) {
			return rest_ensure_response( array() );
		}

		$keys       = array_map( 'sanitize_text_field', $keys );
		$keys       = array_slice( $keys, 0, 200 ); // hard cap
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT conversation_key, is_read, is_pinned, is_archived, is_deleted, queue FROM `{$table}` WHERE conversation_key IN ($placeholders)", $keys ),
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ $row['conversation_key'] ] = array(
					'isRead'     => (bool) $row['is_read'],
					'isPinned'   => (bool) $row['is_pinned'],
					'isArchived' => (bool) $row['is_archived'],
					'isDeleted'  => (bool) $row['is_deleted'],
					'queue'      => in_array( $row['queue'], array( 'ai', 'human' ), true ) ? $row['queue'] : 'ai',
				);
			}
		}

		return rest_ensure_response( $result );
	}

	public function update_ops_ajphone_conversation( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ajphone_conversations';

		$key         = (string) ( $request->get_param( 'key' )         ?? '' );
		$account_key = (string) ( $request->get_param( 'account_key' ) ?? 'primary' );
		$own_number  = (string) ( $request->get_param( 'own_number' )  ?? '' );
		$peer_number = (string) ( $request->get_param( 'peer_number' ) ?? '' );

		if ( '' === $key ) {
			return new WP_Error( 'ajcore_missing_key', 'conversation key is required', array( 'status' => 400 ) );
		}

		$data    = array( 'conversation_key' => $key, 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s', '%s' );

		if ( '' !== $account_key ) { $data['account_key'] = $account_key; $formats[] = '%s'; }
		if ( '' !== $own_number )  { $data['own_number']  = $own_number;  $formats[] = '%s'; }
		if ( '' !== $peer_number ) { $data['peer_number'] = $peer_number; $formats[] = '%s'; }

		$is_read     = $request->get_param( 'is_read' );
		$is_pinned   = $request->get_param( 'is_pinned' );
		$is_archived = $request->get_param( 'is_archived' );
		$is_deleted  = $request->get_param( 'is_deleted' );
		$queue       = $request->get_param( 'queue' );

		if ( null !== $is_read )     { $data['is_read']     = $is_read     ? 1 : 0; $formats[] = '%d'; }
		if ( null !== $is_pinned )   { $data['is_pinned']   = $is_pinned   ? 1 : 0; $formats[] = '%d'; }
		if ( null !== $is_archived ) { $data['is_archived'] = $is_archived ? 1 : 0; $formats[] = '%d'; }
		if ( null !== $is_deleted )  { $data['is_deleted']  = $is_deleted  ? 1 : 0; $formats[] = '%d'; }
		if ( null !== $queue && in_array( $queue, array( 'ai', 'human' ), true ) ) { $data['queue'] = $queue; $formats[] = '%s'; }

		$columns      = array_keys( $data );
		$column_sql   = implode( ', ', array_map( static function ( $col ) { return "`{$col}`"; }, $columns ) );
		$values_sql   = implode( ', ', $formats );
		$set_parts    = array();
		foreach ( $columns as $col ) {
			if ( 'conversation_key' === $col ) {
				continue;
			}
			$set_parts[] = "`{$col}` = VALUES(`{$col}`)";
		}
		$set_clause = implode( ', ', $set_parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` ({$column_sql}) VALUES ({$values_sql}) ON DUPLICATE KEY UPDATE {$set_clause}",
			array_values( $data )
		) );

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function update_ops_lead_status( WP_REST_Request $request ) {
		$wpdb               = $this->get_portal_db();
		$leads_table        = $wpdb->prefix . 'aj_forms_leads';
		$lead_id            = absint( $request->get_param( 'id' ) );
		$status             = sanitize_key( (string) $request->get_param( 'status' ) );
		$stripe_customer_id = sanitize_text_field( (string) ( $request->get_param( 'stripe_customer_id' ) ?? '' ) );

		if ( ! isset( $this->get_lead_status_labels()[ $status ] ) ) {
			return new WP_Error( 'ajcore_invalid_status', __( 'Status must be one of: new, read, won, lost, duplicate.', 'ajforms' ), array( 'status' => 400 ) );
		}

		if ( 'won' === $status && '' === $stripe_customer_id ) {
			return new WP_Error( 'ajcore_lead_missing_customer', __( 'Pick a customer to link this lead to before marking it Won.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$leads_table}` WHERE id = %d", $lead_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'ajcore_lead_not_found', __( 'Lead not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$update_data    = array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) );
		$update_formats = array( '%s', '%s' );
		if ( 'won' === $status ) {
			$update_data['stripe_customer_id'] = $stripe_customer_id;
			$update_formats[]                  = '%s';
		}

		$wpdb->update( $leads_table, $update_data, array( 'id' => $lead_id ), $update_formats, array( '%d' ) );

		return rest_ensure_response( array( 'id' => $lead_id, 'status' => $status, 'stripe_customer_id' => 'won' === $status ? $stripe_customer_id : '' ) );
	}

	public function delete_ops_lead( WP_REST_Request $request ) {
		$wpdb             = $this->get_portal_db();
		$leads_table      = $wpdb->prefix . 'aj_forms_leads';
		$lead_notes_table = $wpdb->prefix . 'aj_forms_lead_notes';
		$lead_id          = absint( $request->get_param( 'id' ) );

		if ( ! $lead_id ) {
			return new WP_Error( 'ajcore_invalid_lead_id', __( 'Invalid lead ID.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$leads_table}` WHERE id = %d", $lead_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'ajcore_lead_not_found', __( 'Lead not found.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( $lead_notes_table, array( 'lead_id' => $lead_id ), array( '%d' ) );
		$wpdb->delete( $leads_table, array( 'id' => $lead_id ), array( '%d' ) );

		return rest_ensure_response( array( 'success' => true, 'id' => $lead_id ) );
	}

	public function bulk_ops_leads( WP_REST_Request $request ) {
		$wpdb             = $this->get_portal_db();
		$leads_table      = $wpdb->prefix . 'aj_forms_leads';
		$lead_notes_table = $wpdb->prefix . 'aj_forms_lead_notes';
		$action           = sanitize_key( (string) $request->get_param( 'action' ) );
		$ids              = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ) );

		if ( empty( $ids ) ) {
			return new WP_Error( 'ajcore_no_lead_ids', __( 'No lead IDs provided.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( 'delete' === $action ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$lead_notes_table}` WHERE lead_id IN ({$placeholders})", $ids ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$leads_table}` WHERE id IN ({$placeholders})", $ids ) );
		} elseif ( in_array( $action, array( 'mark_read', 'mark_new', 'mark_lost', 'mark_duplicate' ), true ) ) {
			$status_map = array(
				'mark_read'      => 'read',
				'mark_new'       => 'new',
				'mark_lost'      => 'lost',
				'mark_duplicate' => 'duplicate',
			);
			$status = $status_map[ $action ];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE `{$leads_table}` SET status = %s, updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( $status, current_time( 'mysql' ) ), $ids ) ) );
		} elseif ( 'send_followup_email' === $action ) {
			if ( ! class_exists( 'AJForms_Admin' ) ) {
				return new WP_Error( 'ajcore_admin_unavailable', __( 'Email sending is unavailable right now.', 'ajforms' ), array( 'status' => 500 ) );
			}
			$admin  = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
			$sent   = array();
			$failed = array();
			foreach ( $ids as $lead_id ) {
				$result = $admin->send_lead_followup_email( $lead_id );
				if ( is_wp_error( $result ) ) {
					$failed[] = array( 'id' => $lead_id, 'error' => $result->get_error_message() );
				} elseif ( $result ) {
					$sent[] = $lead_id;
				} else {
					$failed[] = array( 'id' => $lead_id, 'error' => __( 'The email could not be sent.', 'ajforms' ) );
				}
			}
			return rest_ensure_response( array( 'success' => true, 'sent' => $sent, 'failed' => $failed ) );
		} else {
			return new WP_Error( 'ajcore_unknown_lead_bulk_action', __( 'Unknown lead bulk action.', 'ajforms' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'affected' => count( $ids ) ) );
	}

	/** Copies a duplicate lead's notes onto the primary lead (tagged with its origin), then
	 *  archives the duplicate — non-destructive, so a bad auto-match can be manually undone by
	 *  changing its status back. Shared by the manual "Merge Duplicates" action and the
	 *  automatic "Fix Duplicates" scan below. */
	private function merge_lead_into( $primary_id, $duplicate_id ) {
		$wpdb = $this->get_portal_db();
		if ( $primary_id === $duplicate_id ) {
			return;
		}
		$leads_table = $wpdb->prefix . 'aj_forms_leads';
		$notes_table = $wpdb->prefix . 'aj_forms_lead_notes';

		$note_rows = $wpdb->get_results( $wpdb->prepare( "SELECT note, created_by, author_name, created_at FROM `{$notes_table}` WHERE lead_id = %d ORDER BY created_at ASC", $duplicate_id ) );
		foreach ( (array) $note_rows as $n ) {
			$wpdb->insert(
				$notes_table,
				array(
					'lead_id'     => $primary_id,
					'note'        => sprintf( "[Merged from lead #%d]\n%s", $duplicate_id, (string) $n->note ),
					'created_by'  => (int) $n->created_by,
					'author_name' => isset( $n->author_name ) ? (string) $n->author_name : '',
					'created_at'  => (string) $n->created_at,
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}
		$actor = wp_get_current_user();
		$wpdb->insert(
			$notes_table,
			array(
				'lead_id'     => $primary_id,
				'note'        => sprintf( __( 'Lead #%d was merged into this one as a duplicate.', 'ajforms' ), $duplicate_id ),
				'created_by'  => get_current_user_id(),
				'author_name' => $actor ? (string) $actor->display_name : '',
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$wpdb->update(
			$leads_table,
			array( 'status' => 'duplicate', 'merged_into_lead_id' => $primary_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $duplicate_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/** Manual merge: staff selects 2+ leads they know are duplicates and picks (or we default to
	 *  the earliest) which one survives. */
	public function merge_ops_leads( WP_REST_Request $request ) {
		$wpdb        = $this->get_portal_db();
		$leads_table = $wpdb->prefix . 'aj_forms_leads';
		$ids         = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ) );
		$primary_id  = absint( $request->get_param( 'primary_id' ) );

		if ( count( $ids ) < 2 ) {
			return new WP_Error( 'ajcore_merge_needs_two', __( 'Select at least two leads to merge.', 'ajforms' ), array( 'status' => 400 ) );
		}

		if ( ! $primary_id || ! in_array( $primary_id, $ids, true ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$primary_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$leads_table}` WHERE id IN ({$placeholders}) ORDER BY created_at ASC, id ASC LIMIT 1", $ids ) );
		}

		if ( ! $primary_id ) {
			return new WP_Error( 'ajcore_lead_not_found', __( 'Could not determine which lead to keep.', 'ajforms' ), array( 'status' => 404 ) );
		}

		$merged = 0;
		foreach ( $ids as $id ) {
			if ( $id === $primary_id ) {
				continue;
			}
			$this->merge_lead_into( $primary_id, $id );
			$merged++;
		}

		return rest_ensure_response( array( 'success' => true, 'primary_id' => $primary_id, 'merged' => $merged ) );
	}

	/** Auto-detects duplicates within the active Inbox (new/read only — leads already marked
	 *  won/lost/duplicate are resolved outcomes and left alone) by exact, normalized email match,
	 *  falling back to phone when email is blank. Keeps the earliest submission per group and
	 *  archives the rest as duplicates in one pass — this is the "Fix Duplicates" button. */
	public function fix_ops_lead_duplicates( WP_REST_Request $request ) {
		$wpdb        = $this->get_portal_db();
		$leads_table = $wpdb->prefix . 'aj_forms_leads';

		$rows = $wpdb->get_results(
			"SELECT id, lead_data, created_at FROM `{$leads_table}` WHERE status IN ('read','new') ORDER BY created_at ASC, id ASC",
			ARRAY_A
		);

		$groups = array();
		foreach ( (array) $rows as $row ) {
			$decoded = json_decode( isset( $row['lead_data'] ) ? (string) $row['lead_data'] : '{}', true );
			$email   = strtolower( trim( $this->extract_lead_field( $decoded, array( 'email', 'e-mail' ) ) ) );
			$phone   = preg_replace( '/\D/', '', $this->extract_lead_field( $decoded, array( 'phone', 'mobile', 'tel', 'cell' ) ) );

			$key = '';
			if ( '' !== $email ) {
				$key = 'email:' . $email;
			} elseif ( '' !== $phone ) {
				$key = 'phone:' . $phone;
			} else {
				continue; // Nothing to match on — leave it alone.
			}

			$groups[ $key ][] = (int) $row['id'];
		}

		$groups_merged = 0;
		$archived_ids  = array();
		foreach ( $groups as $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$primary_id = $ids[0]; // Rows are already ordered by created_at ASC above.
			foreach ( array_slice( $ids, 1 ) as $duplicate_id ) {
				$this->merge_lead_into( $primary_id, $duplicate_id );
				$archived_ids[] = $duplicate_id;
			}
			$groups_merged++;
		}

		return rest_ensure_response( array( 'success' => true, 'groups_merged' => $groups_merged, 'leads_archived' => count( $archived_ids ), 'archived_ids' => $archived_ids ) );
	}

	public function ops_create_lead( WP_REST_Request $request ) {
		$wpdb  = $this->get_portal_db();
		$table = $wpdb->prefix . 'aj_forms_leads';

		$name    = (string) $request->get_param( 'name' );
		$email   = (string) ( $request->get_param( 'email' ) ?? '' );
		$phone   = $this->normalize_us_phone_for_storage( (string) ( $request->get_param( 'phone' ) ?? '' ) );
		$company = (string) ( $request->get_param( 'company' ) ?? '' );
		$source  = (string) ( $request->get_param( 'source' ) ?? '' );
		$notes   = (string) ( $request->get_param( 'notes' ) ?? '' );
		$status  = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'read' ) );
		if ( ! in_array( $status, array( 'read', 'new' ), true ) ) {
			$status = 'read';
		}

		if ( '' === $name ) {
			return new WP_Error( 'ajcore_missing_name', __( 'Name is required.', 'ajforms' ), array( 'status' => 400 ) );
		}

		$user     = wp_get_current_user();
		$lead_data = array(
			'name'    => array( 'label' => 'Name',    'type' => 'text',     'value' => $name ),
			'email'   => array( 'label' => 'Email',   'type' => 'email',    'value' => $email ),
			'phone'   => array( 'label' => 'Phone',   'type' => 'text',     'value' => $phone ),
			'company' => array( 'label' => 'Company', 'type' => 'text',     'value' => $company ),
			'source'  => array( 'label' => 'Source',  'type' => 'text',     'value' => $source ),
			'notes'   => array( 'label' => 'Notes',   'type' => 'textarea', 'value' => $notes ),
			'_meta'   => array(
				'submitted_at' => current_time( 'mysql' ),
				'source'       => '' !== $source ? $source : 'api',
				'created_by'   => $user ? (int) $user->ID : 0,
			),
		);

		$result = $wpdb->insert(
			$table,
			array(
				'form_id'    => 0,
				'lead_data'  => wp_json_encode( $lead_data ),
				'status'     => $status,
				'ip_address' => '',
				'source_url' => '',
				'user_agent' => 'api',
				'site_uuid'  => (string) get_option( 'ajcore_site_uuid', '' ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return new WP_Error( 'ajcore_lead_create_failed', __( 'Failed to create lead.', 'ajforms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'lead'    => array(
				'id'         => (int) $wpdb->insert_id,
				'form_id'    => 0,
				'status'     => $status,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $this->format_us_phone_for_display( $phone ),
				'company'    => $company,
				'source'     => $source,
				'notes'      => $notes,
				'created_at' => current_time( 'mysql' ),
			),
		) );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private function block_impersonated_portal_write() {
		$cookie_name = 'ajcore_impersonation_return';
		if ( empty( $_COOKIE[ $cookie_name ] ) ) {
			return true;
		}

		$token = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		if ( '' === $token ) {
			return true;
		}

		$hash    = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$payload = get_transient( 'ajcore_impersonation_return_' . $hash );
		if ( is_array( $payload ) && ! empty( $payload['target_user_id'] ) && (int) $payload['target_user_id'] === get_current_user_id() ) {
			return new WP_Error( 'ajcore_impersonation_readonly', __( 'This action is disabled while viewing as a client.', 'ajforms' ), array( 'status' => 403 ) );
		}

		return true;
	}

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
