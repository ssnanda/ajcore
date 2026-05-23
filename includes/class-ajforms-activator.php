<?php

class AJForms_Activator {

	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_forms      = $wpdb->prefix . 'ajforms_forms';
		$table_leads      = $wpdb->prefix . 'ajforms_leads';
		$table_lead_notes = $wpdb->prefix . 'ajforms_lead_notes';
		$table_portal_files      = $wpdb->prefix . 'aj_portal_files';
		$table_portal_file_users = $wpdb->prefix . 'aj_portal_file_users';
		$table_stripe_customers     = $wpdb->prefix . 'aj_portal_stripe_customers';
		$table_stripe_products      = $wpdb->prefix . 'aj_portal_stripe_products';
		$table_stripe_subscriptions = $wpdb->prefix . 'aj_portal_stripe_subscriptions';
		$table_stripe_transactions  = $wpdb->prefix . 'aj_portal_stripe_transactions';
		$table_user_mappings        = $wpdb->prefix . 'aj_portal_user_mappings';
		$table_entity_mappings      = $wpdb->prefix . 'aj_portal_entity_mappings';
		$table_ledger               = $wpdb->prefix . 'aj_portal_ledger';
		$table_tasks                = $wpdb->prefix . 'aj_portal_tasks';
		$table_task_statuses        = $wpdb->prefix . 'aj_portal_task_statuses';
		$table_task_comments        = $wpdb->prefix . 'aj_portal_task_comments';
		$table_sync_logs            = $wpdb->prefix . 'aj_portal_sync_logs';
		$table_service_requests     = $wpdb->prefix . 'aj_portal_service_requests';

		$sql = "CREATE TABLE $table_forms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			form_schema longtext NOT NULL,
			status varchar(50) DEFAULT 'published' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;

		CREATE TABLE $table_leads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			lead_data longtext NOT NULL,
			status varchar(50) DEFAULT 'unread' NOT NULL,
			ip_address varchar(100) DEFAULT '' NOT NULL,
			source_url text NULL,
			user_agent text NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status)
		) $charset_collate;

		CREATE TABLE $table_lead_notes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			note longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id)
		) $charset_collate;

		CREATE TABLE $table_portal_files (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL,
			description longtext NULL,
			category varchar(100) DEFAULT '' NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY category (category)
		) $charset_collate;

		CREATE TABLE $table_portal_file_users (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_email varchar(190) DEFAULT '' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY file_id (file_id),
			KEY user_id (user_id),
			KEY user_email (user_email)
		) $charset_collate;

		CREATE TABLE $table_stripe_customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) NOT NULL,
			email varchar(190) DEFAULT '' NOT NULL,
			name varchar(255) DEFAULT '' NOT NULL,
			phone varchar(100) DEFAULT '' NOT NULL,
			address longtext NULL,
			metadata longtext NULL,
			raw_data longtext NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			enabled_portal tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NULL,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY email (email),
			KEY enabled_portal (enabled_portal)
		) $charset_collate;

		CREATE TABLE $table_stripe_products (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_product_id varchar(100) NOT NULL,
			stripe_price_id varchar(100) DEFAULT '' NOT NULL,
			name varchar(255) DEFAULT '' NOT NULL,
			description longtext NULL,
			price_amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			recurring_interval varchar(50) DEFAULT '' NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			metadata longtext NULL,
			raw_data longtext NULL,
			visibility varchar(50) DEFAULT 'visible' NOT NULL,
			custom_label varchar(255) DEFAULT '' NOT NULL,
			sort_order int(11) DEFAULT 0 NOT NULL,
			description_override longtext NULL,
			duplicate_behavior varchar(50) DEFAULT 'no_duplicates' NOT NULL,
			custom_request_title varchar(255) DEFAULT '' NOT NULL,
			custom_request_message longtext NULL,
			custom_request_button_label varchar(255) DEFAULT '' NOT NULL,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_price_id (stripe_price_id),
			KEY stripe_product_id (stripe_product_id),
			KEY active (active)
		) $charset_collate;

		CREATE TABLE $table_stripe_subscriptions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_subscription_id varchar(100) NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			status varchar(50) DEFAULT '' NOT NULL,
			current_period_end datetime NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			items longtext NULL,
			raw_data longtext NULL,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_subscription_id (stripe_subscription_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY status (status),
			KEY current_period_end (current_period_end)
		) $charset_collate;

		CREATE TABLE $table_stripe_transactions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_object_id varchar(100) NOT NULL,
			object_type varchar(50) NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			invoice_id varchar(100) DEFAULT '' NOT NULL,
			payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			charge_id varchar(100) DEFAULT '' NOT NULL,
			description varchar(255) DEFAULT '' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			status varchar(50) DEFAULT '' NOT NULL,
			transaction_date datetime NULL,
			due_date datetime NULL,
			raw_data longtext NULL,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_object_id (stripe_object_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY invoice_id (invoice_id),
			KEY status (status)
		) $charset_collate;

		CREATE TABLE $table_user_mappings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY customer_email (customer_email)
		) $charset_collate;

		CREATE TABLE $table_entity_mappings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) NOT NULL,
			entity_key varchar(100) NOT NULL,
			entity_label varchar(255) DEFAULT '' NOT NULL,
			entity_type varchar(100) DEFAULT '' NOT NULL,
			metadata longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY customer_entity (stripe_customer_id, entity_key),
			KEY stripe_customer_id (stripe_customer_id)
		) $charset_collate;

		CREATE TABLE $table_tasks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			task_scope varchar(50) DEFAULT 'client' NOT NULL,
			task_frequency varchar(50) DEFAULT 'one_time' NOT NULL,
			title varchar(255) NOT NULL,
			status varchar(50) DEFAULT 'open' NOT NULL,
			due_date date NULL,
			action_required longtext NULL,
			client_visible tinyint(1) NOT NULL DEFAULT 1,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY task_scope (task_scope),
			KEY task_frequency (task_frequency),
			KEY status (status),
			KEY due_date (due_date),
			KEY client_visible (client_visible)
		) $charset_collate;

		CREATE TABLE $table_task_statuses (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			task_id bigint(20) unsigned NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			status varchar(50) DEFAULT 'open' NOT NULL,
			completed_at datetime NULL,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY task_customer (task_id, stripe_customer_id),
			KEY task_id (task_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY status (status)
		) $charset_collate;

		CREATE TABLE $table_task_comments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			task_id bigint(20) unsigned NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			comment longtext NOT NULL,
			is_client tinyint(1) NOT NULL DEFAULT 1,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY task_id (task_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY created_at (created_at)
		) $charset_collate;

		CREATE TABLE $table_sync_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_key varchar(64) NOT NULL,
			source varchar(50) DEFAULT 'manual' NOT NULL,
			job_name varchar(100) DEFAULT 'all' NOT NULL,
			status varchar(50) DEFAULT 'started' NOT NULL,
			records_synced int(11) NOT NULL DEFAULT 0,
			message longtext NULL,
			started_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			finished_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY run_key (run_key),
			KEY source (source),
			KEY job_name (job_name),
			KEY status (status),
			KEY started_at (started_at)
		) $charset_collate;

		CREATE TABLE $table_ledger (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) NOT NULL,
			source_object_id varchar(100) NOT NULL,
			source_type varchar(50) NOT NULL,
			ledger_date datetime NULL,
			description varchar(255) DEFAULT '' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			status varchar(50) DEFAULT '' NOT NULL,
			invoice_id varchar(100) DEFAULT '' NOT NULL,
			payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			charge_id varchar(100) DEFAULT '' NOT NULL,
			metadata longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_object_id (source_object_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY status (status),
			KEY ledger_date (ledger_date)
		) $charset_collate;

		CREATE TABLE $table_service_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stripe_customer_id varchar(100) NOT NULL,
			stripe_price_id varchar(100) DEFAULT '' NOT NULL,
			stripe_product_id varchar(100) DEFAULT '' NOT NULL,
			service_name varchar(255) DEFAULT '' NOT NULL,
			request_type varchar(100) DEFAULT 'add_service' NOT NULL,
			status varchar(50) DEFAULT 'draft' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			source_object_id varchar(100) DEFAULT '' NOT NULL,
			source_type varchar(50) DEFAULT '' NOT NULL,
			ledger_id bigint(20) unsigned NOT NULL DEFAULT 0,
			admin_notes longtext NULL,
			client_notes longtext NULL,
			source varchar(50) DEFAULT 'system' NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			raw_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY wp_user_id (wp_user_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY stripe_price_id (stripe_price_id),
			KEY stripe_product_id (stripe_product_id),
			KEY request_type (request_type),
			KEY status (status),
			KEY source_object_id (source_object_id),
			KEY ledger_id (ledger_id),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$current_year = (int) current_time( 'Y' );
		$march_15 = strtotime( $current_year . '-03-15 00:00:00' ) < current_time( 'timestamp' ) ? ( $current_year + 1 ) . '-03-15' : $current_year . '-03-15';
		$april_15 = strtotime( $current_year . '-04-15 00:00:00' ) < current_time( 'timestamp' ) ? ( $current_year + 1 ) . '-04-15' : $current_year . '-04-15';

		$default_tasks = array(
			array(
				'title'           => 'BOI Report',
				'status'          => 'open',
				'due_date'        => null,
				'action_required' => 'Confirm whether BOI reporting is required and mark completed when filed.',
				'task_frequency'  => 'one_time',
			),
			array(
				'title'           => 'Annual Report',
				'status'          => 'upcoming',
				'due_date'        => $april_15,
				'action_required' => 'File the annual report with the state if not already completed.',
				'task_frequency'  => 'recurring',
			),
			array(
				'title'           => 'Tax Return for Multi-Member LLCs / K-1s',
				'status'          => 'upcoming',
				'due_date'        => $march_15,
				'action_required' => 'Prepare partnership return and issue K-1s if applicable.',
				'task_frequency'  => 'recurring',
			),
			array(
				'title'           => 'Tax Return for Pass-Through LLCs',
				'status'          => 'upcoming',
				'due_date'        => $april_15,
				'action_required' => 'Prepare pass-through LLC tax filing if applicable.',
				'task_frequency'  => 'recurring',
			),
		);

		foreach ( $default_tasks as $default_task ) {
			$existing_task_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $table_tasks WHERE task_scope = %s AND title = %s LIMIT 1",
					'global',
					$default_task['title']
				)
			);

			if ( ! $existing_task_id ) {
				$wpdb->insert(
					$table_tasks,
					array(
						'stripe_customer_id' => '',
						'task_scope'         => 'global',
						'task_frequency'     => $default_task['task_frequency'],
						'title'              => $default_task['title'],
						'status'             => $default_task['status'],
						'due_date'           => $default_task['due_date'],
						'action_required'    => $default_task['action_required'],
						'client_visible'     => 1,
						'created_by'         => 0,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
				);
			}
		}

		add_role(
			'aj_portal_user',
			__( 'AJ Portal User', 'ajforms' ),
			array(
				'read' => true,
			)
		);
		update_option( 'ajforms_version', AJFORMS_VERSION, false );
		update_option( 'ajforms_portal_schema_version', '7', false );
	}
}
