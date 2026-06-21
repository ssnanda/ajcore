<?php

class AJForms_Activator {

	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_forms      = $wpdb->prefix . 'aj_forms_forms';
		$table_leads      = $wpdb->prefix . 'aj_forms_leads';
		$table_lead_notes = $wpdb->prefix . 'aj_forms_lead_notes';
		$table_portal_files      = $wpdb->prefix . 'aj_portal_files';
		$table_portal_file_users = $wpdb->prefix . 'aj_portal_file_users';
		$table_stripe_customers     = $wpdb->prefix . 'aj_portal_stripe_customers';
		$table_stripe_products      = $wpdb->prefix . 'aj_portal_stripe_products';
		$table_product_catalog      = $wpdb->prefix . 'aj_portal_product_catalog';
		$table_stripe_subscriptions = $wpdb->prefix . 'aj_portal_stripe_subscriptions';
		$table_stripe_transactions  = $wpdb->prefix . 'aj_portal_stripe_transactions';
		$table_user_mappings        = $wpdb->prefix . 'aj_auth_user_mappings';
		$table_entity_mappings      = $wpdb->prefix . 'aj_portal_entity_mappings';
		$table_ledger               = $wpdb->prefix . 'aj_portal_ledger';
		$table_tasks                = $wpdb->prefix . 'aj_portal_tasks';
		$table_task_statuses        = $wpdb->prefix . 'aj_portal_task_statuses';
		$table_task_comments        = $wpdb->prefix . 'aj_portal_task_comments';
		$table_sync_logs            = $wpdb->prefix . 'aj_portal_sync_logs';
		$table_sync_log_items       = $wpdb->prefix . 'aj_portal_sync_log_items';
		$table_service_requests     = $wpdb->prefix . 'aj_portal_service_requests';
		$table_service_request_history = $wpdb->prefix . 'aj_portal_service_request_history';
		$table_event_log            = $wpdb->prefix . 'aj_portal_event_log';
		$table_stripe_events        = $wpdb->prefix . 'aj_portal_stripe_events';
		$table_service_snapshots    = $wpdb->prefix . 'aj_portal_service_snapshots';
		$table_service_states       = $wpdb->prefix . 'aj_portal_service_states';
		$table_customer_states      = $wpdb->prefix . 'aj_portal_customer_states';

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
			portal_status varchar(50) DEFAULT 'disabled' NOT NULL,
			created_at datetime NULL,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY email (email),
			KEY enabled_portal (enabled_portal),
			KEY portal_status (portal_status)
		) $charset_collate;

		CREATE TABLE $table_customer_states (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			portal_status varchar(50) DEFAULT 'disabled' NOT NULL,
			enabled_portal tinyint(1) NOT NULL DEFAULT 0,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			portal_user_email varchar(190) DEFAULT '' NOT NULL,
			site_uuid varchar(100) DEFAULT '' NOT NULL,
			status_source varchar(100) DEFAULT '' NOT NULL,
			status_reason varchar(255) DEFAULT '' NOT NULL,
			notes longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY customer_email (customer_email),
			KEY portal_status (portal_status),
			KEY enabled_portal (enabled_portal),
			KEY wp_user_id (wp_user_id),
			KEY portal_user_email (portal_user_email),
			KEY site_uuid (site_uuid)
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
			visibility varchar(50) DEFAULT 'hidden' NOT NULL,
			custom_label varchar(255) DEFAULT '' NOT NULL,
			sort_order int(11) DEFAULT 0 NOT NULL,
			description_override longtext NULL,
			duplicate_behavior varchar(50) DEFAULT 'no_duplicates' NOT NULL,
			upgrade_from_product_id varchar(100) DEFAULT '' NOT NULL,
			custom_request_title varchar(255) DEFAULT '' NOT NULL,
			custom_request_message longtext NULL,
			custom_request_button_label varchar(255) DEFAULT '' NOT NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_price_id (stripe_price_id),
			KEY stripe_product_id (stripe_product_id),
			KEY active (active)
		) $charset_collate;

		CREATE TABLE $table_product_catalog (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_product_id varchar(100) NOT NULL,
			visibility varchar(50) DEFAULT 'hidden' NOT NULL,
			custom_label varchar(255) DEFAULT '' NOT NULL,
			sort_order int(11) DEFAULT 0 NOT NULL,
			description_override longtext NULL,
			duplicate_behavior varchar(50) DEFAULT 'no_duplicates' NOT NULL,
			upgrade_from_product_id varchar(100) DEFAULT '' NOT NULL,
			custom_request_title varchar(255) DEFAULT '' NOT NULL,
			custom_request_message longtext NULL,
			custom_request_button_label varchar(255) DEFAULT '' NOT NULL,
			price_settings longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_product_id (stripe_product_id),
			KEY visibility (visibility),
			KEY duplicate_behavior (duplicate_behavior)
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
			livemode tinyint(1) NOT NULL DEFAULT 0,
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
			livemode tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_object_id (stripe_object_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY invoice_id (invoice_id),
			KEY status (status)
		) $charset_collate;

		CREATE TABLE $table_service_snapshots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			snapshot_key varchar(190) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			guest_customer_id varchar(100) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			product_id varchar(100) DEFAULT '' NOT NULL,
			price_id varchar(100) DEFAULT '' NOT NULL,
			product_name_snapshot varchar(255) DEFAULT '' NOT NULL,
			price_label_snapshot varchar(100) DEFAULT '' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			recurring_interval varchar(50) DEFAULT '' NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			billing_type varchar(50) DEFAULT 'one_time' NOT NULL,
			checkout_session_id varchar(100) DEFAULT '' NOT NULL,
			invoice_id varchar(100) DEFAULT '' NOT NULL,
			payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			charge_id varchar(100) DEFAULT '' NOT NULL,
			subscription_id varchar(100) DEFAULT '' NOT NULL,
			service_period_start datetime NULL,
			service_period_end datetime NULL,
			service_period varchar(255) DEFAULT '' NOT NULL,
			next_billing_date datetime NULL,
			source_type varchar(50) DEFAULT '' NOT NULL,
			status varchar(50) DEFAULT '' NOT NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			raw_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_key (snapshot_key),
			KEY stripe_customer_id (stripe_customer_id),
			KEY guest_customer_id (guest_customer_id),
			KEY customer_email (customer_email),
			KEY product_id (product_id),
			KEY price_id (price_id),
			KEY billing_type (billing_type),
			KEY status (status),
			KEY subscription_id (subscription_id),
			KEY checkout_session_id (checkout_session_id),
			KEY invoice_id (invoice_id),
			KEY payment_intent_id (payment_intent_id),
			KEY livemode (livemode)
		) $charset_collate;

		CREATE TABLE $table_service_states (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_state_key varchar(190) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			guest_customer_id varchar(100) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			product_id varchar(100) DEFAULT '' NOT NULL,
			price_id varchar(100) DEFAULT '' NOT NULL,
			product_name varchar(255) DEFAULT '' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			checkout_session_id varchar(100) DEFAULT '' NOT NULL,
			invoice_id varchar(100) DEFAULT '' NOT NULL,
			payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			charge_id varchar(100) DEFAULT '' NOT NULL,
			subscription_id varchar(100) DEFAULT '' NOT NULL,
			service_period_start datetime NULL,
			service_period_end datetime NULL,
			service_period varchar(255) DEFAULT '' NOT NULL,
			service_status varchar(50) DEFAULT '' NOT NULL,
			notes longtext NULL,
			used_at datetime NULL,
			used_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_state_key (service_state_key),
			KEY stripe_customer_id (stripe_customer_id),
			KEY guest_customer_id (guest_customer_id),
			KEY customer_email (customer_email),
			KEY product_id (product_id),
			KEY price_id (price_id),
			KEY invoice_id (invoice_id),
			KEY checkout_session_id (checkout_session_id),
			KEY payment_intent_id (payment_intent_id),
			KEY service_status (service_status)
		) $charset_collate;

		CREATE TABLE $table_user_mappings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			portal_user_email varchar(190) DEFAULT '' NOT NULL,
			site_uuid varchar(100) DEFAULT '' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY customer_email (customer_email),
			KEY portal_user_email (portal_user_email),
			KEY site_uuid (site_uuid)
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

		CREATE TABLE $table_sync_log_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL DEFAULT 0,
			run_key varchar(64) NOT NULL,
			job_name varchar(100) DEFAULT '' NOT NULL,
			action varchar(50) DEFAULT '' NOT NULL,
			record_type varchar(100) DEFAULT '' NOT NULL,
			record_id varchar(190) DEFAULT '' NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			status varchar(50) DEFAULT 'success' NOT NULL,
			message longtext NULL,
			raw_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY log_id (log_id),
			KEY run_key (run_key),
			KEY job_name (job_name),
			KEY action (action),
			KEY record_type (record_type),
			KEY record_id (record_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY status (status)
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
			service_status varchar(50) DEFAULT 'new' NOT NULL,
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
			KEY service_status (service_status),
			KEY source_object_id (source_object_id),
			KEY ledger_id (ledger_id),
			KEY created_at (created_at)
		) $charset_collate;


		CREATE TABLE $table_service_request_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			event_type varchar(50) DEFAULT 'note' NOT NULL,
			status_before varchar(50) DEFAULT '' NOT NULL,
			status_after varchar(50) DEFAULT '' NOT NULL,
			service_status_before varchar(50) DEFAULT '' NOT NULL,
			service_status_after varchar(50) DEFAULT '' NOT NULL,
			note longtext NULL,
			visibility varchar(30) DEFAULT 'internal' NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_email varchar(190) DEFAULT '' NOT NULL,
			details longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY event_type (event_type),
			KEY visibility (visibility),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) $charset_collate;

		CREATE TABLE $table_event_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(100) NOT NULL,
			severity varchar(20) DEFAULT 'info' NOT NULL,
			source varchar(100) DEFAULT '' NOT NULL,
			correlation_id varchar(100) DEFAULT '' NOT NULL,
			site_uuid varchar(100) DEFAULT '' NOT NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			wp_user_id_before bigint(20) unsigned NOT NULL DEFAULT 0,
			wp_user_id_after bigint(20) unsigned NOT NULL DEFAULT 0,
			email_before varchar(190) DEFAULT '' NOT NULL,
			email_after varchar(190) DEFAULT '' NOT NULL,
			portal_status_before varchar(50) DEFAULT '' NOT NULL,
			portal_status_after varchar(50) DEFAULT '' NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_email varchar(190) DEFAULT '' NOT NULL,
			details longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY severity (severity),
			KEY source (source),
			KEY correlation_id (correlation_id),
			KEY site_uuid (site_uuid),
			KEY customer_id (customer_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) $charset_collate;

		CREATE TABLE $table_stripe_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(190) NOT NULL,
			event_type varchar(100) NOT NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			stripe_account varchar(100) DEFAULT '' NOT NULL,
			object_id varchar(190) DEFAULT '' NOT NULL,
			processing_status varchar(50) DEFAULT 'received' NOT NULL,
			first_seen_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			processed_at datetime NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			last_error longtext NULL,
			raw_event longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY event_type (event_type),
			KEY livemode (livemode),
			KEY stripe_account (stripe_account),
			KEY object_id (object_id),
			KEY processing_status (processing_status),
			KEY first_seen_at (first_seen_at)
		) $charset_collate;

		CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aj_portal_carts (
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
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$legacy_table_migrations = array(
			$wpdb->prefix . 'ajforms_forms'         => $table_forms,
			$wpdb->prefix . 'ajforms_leads'         => $table_leads,
			$wpdb->prefix . 'ajforms_lead_notes'    => $table_lead_notes,
			$wpdb->prefix . 'aj_portal_user_mappings' => $table_user_mappings,
		);
		foreach ( $legacy_table_migrations as $legacy_table => $new_table ) {
			$legacy_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_table ) );
			$new_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );
			if ( $legacy_exists === $legacy_table && $new_exists === $new_table ) {
				$wpdb->query( "INSERT IGNORE INTO $new_table SELECT * FROM $legacy_table" );
				$wpdb->query( "DROP TABLE IF EXISTS $legacy_table" );
			}
		}

		$catalog_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_product_catalog" );
		if ( 0 === $catalog_count ) {
			$dependency_settings = get_option( 'ajcore_public_product_dependencies', array() );
			$dependency_settings = is_array( $dependency_settings ) ? $dependency_settings : array();
			$legacy_products = $wpdb->get_results(
				"SELECT stripe_product_id,
					MAX(visibility) AS visibility,
					MAX(custom_label) AS custom_label,
					MIN(sort_order) AS sort_order,
					MAX(description_override) AS description_override,
					MAX(duplicate_behavior) AS duplicate_behavior,
					MAX(upgrade_from_product_id) AS upgrade_from_product_id,
					MAX(custom_request_title) AS custom_request_title,
					MAX(custom_request_message) AS custom_request_message,
					MAX(custom_request_button_label) AS custom_request_button_label
				FROM $table_stripe_products
				WHERE stripe_product_id <> ''
				GROUP BY stripe_product_id"
			);
			foreach ( (array) $legacy_products as $legacy_product ) {
				$price_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT stripe_price_id FROM $table_stripe_products WHERE stripe_product_id = %s AND stripe_price_id <> ''",
						$legacy_product->stripe_product_id
					)
				);
				$price_settings = array();
				foreach ( (array) $price_ids as $price_id ) {
					if ( ! empty( $dependency_settings[ $price_id ] ) && is_array( $dependency_settings[ $price_id ] ) ) {
						$price_settings[ $price_id ] = array(
							'requires_price_id' => isset( $dependency_settings[ $price_id ]['requires_price_id'] ) ? sanitize_text_field( (string) $dependency_settings[ $price_id ]['requires_price_id'] ) : '',
							'dependency_note'   => isset( $dependency_settings[ $price_id ]['dependency_note'] ) ? sanitize_textarea_field( (string) $dependency_settings[ $price_id ]['dependency_note'] ) : '',
						);
					}
				}
				$duplicate_behavior = sanitize_key( (string) $legacy_product->duplicate_behavior );
				if ( ! in_array( $duplicate_behavior, array( 'no_duplicates', 'allow_duplicate', 'custom_request', 'upgrade' ), true ) ) {
					$duplicate_behavior = 'no_duplicates';
				}
				$visibility = sanitize_key( (string) $legacy_product->visibility );
				$wpdb->insert(
					$table_product_catalog,
					array(
						'stripe_product_id'           => sanitize_text_field( (string) $legacy_product->stripe_product_id ),
						'visibility'                  => 'visible' === $visibility ? 'visible' : 'hidden',
						'custom_label'                => sanitize_text_field( (string) $legacy_product->custom_label ),
						'sort_order'                  => intval( $legacy_product->sort_order ),
						'description_override'        => sanitize_textarea_field( (string) $legacy_product->description_override ),
						'duplicate_behavior'          => $duplicate_behavior,
						'upgrade_from_product_id'     => sanitize_text_field( (string) $legacy_product->upgrade_from_product_id ),
						'custom_request_title'        => sanitize_text_field( (string) $legacy_product->custom_request_title ),
						'custom_request_message'      => sanitize_textarea_field( (string) $legacy_product->custom_request_message ),
						'custom_request_button_label' => sanitize_text_field( (string) $legacy_product->custom_request_button_label ),
						'price_settings'              => ! empty( $price_settings ) ? wp_json_encode( $price_settings ) : '',
					),
					array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}

		$wpdb->query( "UPDATE $table_stripe_customers SET portal_status = 'active' WHERE enabled_portal = 1 AND (portal_status = '' OR portal_status IS NULL)" );
		$wpdb->query( "UPDATE $table_stripe_customers SET portal_status = 'disabled' WHERE enabled_portal = 0 AND (portal_status = '' OR portal_status IS NULL)" );
		$wpdb->query( "UPDATE $table_stripe_customers SET enabled_portal = 1 WHERE portal_status = 'active'" );
		$wpdb->query( "UPDATE $table_stripe_customers SET enabled_portal = 0 WHERE portal_status IN ('disabled','archived','without_portal_login')" );

		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table_customer_states
					(stripe_customer_id, customer_email, portal_status, enabled_portal, status_source, status_reason, created_at, updated_at)
				SELECT stripe_customer_id, email, portal_status, enabled_portal, %s, %s, %s, %s
				FROM $table_stripe_customers
				WHERE stripe_customer_id <> ''
				ON DUPLICATE KEY UPDATE
					customer_email = VALUES(customer_email),
					updated_at = VALUES(updated_at)",
				'activation_seed',
				'preserve_existing_customer_status',
				$now,
				$now
			)
		);

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
				'read'                          => true,
				'ajcore_customer_portal_access' => true,
			)
		);
		$portal_role = get_role( 'aj_portal_user' );
		if ( $portal_role && ! $portal_role->has_cap( 'read' ) ) {
			$portal_role->add_cap( 'read' );
		}
		if ( $portal_role && ! $portal_role->has_cap( 'ajcore_customer_portal_access' ) ) {
			$portal_role->add_cap( 'ajcore_customer_portal_access' );
		}
		update_option( 'ajforms_version', AJFORMS_VERSION, false );
		update_option( 'ajforms_portal_schema_version', '12', false );
	}

	/**
	 * Returns CREATE TABLE SQL for the 18 shared portal tables only.
	 * Does NOT include local-only tables (forms, leads, lead_notes, files, file_users, user_mappings).
	 * Safe to pass to dbDelta() with a shared wpdb instance.
	 */
	public static function get_shared_portal_table_sql( $prefix, $charset_collate ) {
		$t_shared_sites         = $prefix . 'aj_shared_sites';
		$t_stripe_customers     = $prefix . 'aj_portal_stripe_customers';
		$t_customer_states      = $prefix . 'aj_portal_customer_states';
		$t_stripe_products      = $prefix . 'aj_portal_stripe_products';
		$t_product_catalog      = $prefix . 'aj_portal_product_catalog';
		$t_stripe_subscriptions = $prefix . 'aj_portal_stripe_subscriptions';
		$t_stripe_transactions  = $prefix . 'aj_portal_stripe_transactions';
		$t_service_snapshots    = $prefix . 'aj_portal_service_snapshots';
		$t_service_states       = $prefix . 'aj_portal_service_states';
		$t_entity_mappings      = $prefix . 'aj_portal_entity_mappings';
		$t_tasks                = $prefix . 'aj_portal_tasks';
		$t_task_statuses        = $prefix . 'aj_portal_task_statuses';
		$t_task_comments        = $prefix . 'aj_portal_task_comments';
		$t_sync_logs            = $prefix . 'aj_portal_sync_logs';
		$t_sync_log_items       = $prefix . 'aj_portal_sync_log_items';
		$t_ledger               = $prefix . 'aj_portal_ledger';
		$t_service_requests     = $prefix . 'aj_portal_service_requests';
		$t_event_log            = $prefix . 'aj_portal_event_log';
		$t_stripe_events        = $prefix . 'aj_portal_stripe_events';
		$t_carts                = $prefix . 'aj_portal_carts';

		return "CREATE TABLE $t_shared_sites (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_uuid varchar(100) NOT NULL,
			domain varchar(255) DEFAULT '' NOT NULL,
			is_master tinyint(1) NOT NULL DEFAULT 0,
			last_seen datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			registered_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY site_uuid (site_uuid),
			KEY is_master (is_master),
			KEY domain (domain)
		) $charset_collate;

		CREATE TABLE $t_stripe_customers (
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
			portal_status varchar(50) DEFAULT 'disabled' NOT NULL,
			created_at datetime NULL,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY email (email),
			KEY enabled_portal (enabled_portal),
			KEY portal_status (portal_status)
		) $charset_collate;

		CREATE TABLE $t_customer_states (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			portal_status varchar(50) DEFAULT 'disabled' NOT NULL,
			enabled_portal tinyint(1) NOT NULL DEFAULT 0,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			portal_user_email varchar(190) DEFAULT '' NOT NULL,
			site_uuid varchar(100) DEFAULT '' NOT NULL,
			status_source varchar(100) DEFAULT '' NOT NULL,
			status_reason varchar(255) DEFAULT '' NOT NULL,
			notes longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_customer_id (stripe_customer_id),
			KEY customer_email (customer_email),
			KEY portal_status (portal_status),
			KEY enabled_portal (enabled_portal),
			KEY wp_user_id (wp_user_id),
			KEY portal_user_email (portal_user_email),
			KEY site_uuid (site_uuid)
		) $charset_collate;

		CREATE TABLE $t_stripe_products (
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
			visibility varchar(50) DEFAULT 'hidden' NOT NULL,
			custom_label varchar(255) DEFAULT '' NOT NULL,
			sort_order int(11) DEFAULT 0 NOT NULL,
			description_override longtext NULL,
			duplicate_behavior varchar(50) DEFAULT 'no_duplicates' NOT NULL,
			upgrade_from_product_id varchar(100) DEFAULT '' NOT NULL,
			custom_request_title varchar(255) DEFAULT '' NOT NULL,
			custom_request_message longtext NULL,
			custom_request_button_label varchar(255) DEFAULT '' NOT NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_price_id (stripe_price_id),
			KEY stripe_product_id (stripe_product_id),
			KEY active (active)
		) $charset_collate;

		CREATE TABLE $t_product_catalog (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_product_id varchar(100) NOT NULL,
			visibility varchar(50) DEFAULT 'hidden' NOT NULL,
			custom_label varchar(255) DEFAULT '' NOT NULL,
			sort_order int(11) DEFAULT 0 NOT NULL,
			description_override longtext NULL,
			duplicate_behavior varchar(50) DEFAULT 'no_duplicates' NOT NULL,
			upgrade_from_product_id varchar(100) DEFAULT '' NOT NULL,
			custom_request_title varchar(255) DEFAULT '' NOT NULL,
			custom_request_message longtext NULL,
			custom_request_button_label varchar(255) DEFAULT '' NOT NULL,
			price_settings longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_product_id (stripe_product_id),
			KEY visibility (visibility),
			KEY duplicate_behavior (duplicate_behavior)
		) $charset_collate;

		CREATE TABLE $t_stripe_subscriptions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_subscription_id varchar(100) NOT NULL,
			stripe_customer_id varchar(100) NOT NULL,
			status varchar(50) DEFAULT '' NOT NULL,
			current_period_end datetime NULL,
			cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
			items longtext NULL,
			raw_data longtext NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_subscription_id (stripe_subscription_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY status (status),
			KEY current_period_end (current_period_end)
		) $charset_collate;

		CREATE TABLE $t_stripe_transactions (
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
			livemode tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_object_id (stripe_object_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY invoice_id (invoice_id),
			KEY status (status)
		) $charset_collate;

		CREATE TABLE $t_service_snapshots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			snapshot_key varchar(190) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			guest_customer_id varchar(100) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			product_id varchar(100) DEFAULT '' NOT NULL,
			price_id varchar(100) DEFAULT '' NOT NULL,
			product_name_snapshot varchar(255) DEFAULT '' NOT NULL,
			price_label_snapshot varchar(100) DEFAULT '' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			recurring_interval varchar(50) DEFAULT '' NOT NULL,
			quantity int(11) NOT NULL DEFAULT 1,
			billing_type varchar(50) DEFAULT 'one_time' NOT NULL,
			checkout_session_id varchar(100) DEFAULT '' NOT NULL,
			invoice_id varchar(100) DEFAULT '' NOT NULL,
			payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			charge_id varchar(100) DEFAULT '' NOT NULL,
			subscription_id varchar(100) DEFAULT '' NOT NULL,
			service_period_start datetime NULL,
			service_period_end datetime NULL,
			service_period varchar(255) DEFAULT '' NOT NULL,
			next_billing_date datetime NULL,
			source_type varchar(50) DEFAULT '' NOT NULL,
			status varchar(50) DEFAULT '' NOT NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			raw_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_key (snapshot_key),
			KEY stripe_customer_id (stripe_customer_id),
			KEY guest_customer_id (guest_customer_id),
			KEY customer_email (customer_email),
			KEY product_id (product_id),
			KEY price_id (price_id),
			KEY billing_type (billing_type),
			KEY status (status),
			KEY subscription_id (subscription_id),
			KEY checkout_session_id (checkout_session_id),
			KEY invoice_id (invoice_id),
			KEY payment_intent_id (payment_intent_id),
			KEY livemode (livemode)
		) $charset_collate;

		CREATE TABLE $t_service_states (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_state_key varchar(190) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			guest_customer_id varchar(100) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			product_id varchar(100) DEFAULT '' NOT NULL,
			price_id varchar(100) DEFAULT '' NOT NULL,
			product_name varchar(255) DEFAULT '' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			checkout_session_id varchar(100) DEFAULT '' NOT NULL,
			invoice_id varchar(100) DEFAULT '' NOT NULL,
			payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			charge_id varchar(100) DEFAULT '' NOT NULL,
			subscription_id varchar(100) DEFAULT '' NOT NULL,
			service_period_start datetime NULL,
			service_period_end datetime NULL,
			service_period varchar(255) DEFAULT '' NOT NULL,
			service_status varchar(50) DEFAULT '' NOT NULL,
			notes longtext NULL,
			used_at datetime NULL,
			used_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_state_key (service_state_key),
			KEY stripe_customer_id (stripe_customer_id),
			KEY guest_customer_id (guest_customer_id),
			KEY customer_email (customer_email),
			KEY product_id (product_id),
			KEY price_id (price_id),
			KEY invoice_id (invoice_id),
			KEY checkout_session_id (checkout_session_id),
			KEY payment_intent_id (payment_intent_id),
			KEY service_status (service_status)
		) $charset_collate;

		CREATE TABLE $t_entity_mappings (
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

		CREATE TABLE $t_tasks (
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

		CREATE TABLE $t_task_statuses (
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

		CREATE TABLE $t_task_comments (
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

		CREATE TABLE $t_sync_logs (
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

		CREATE TABLE $t_sync_log_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL DEFAULT 0,
			run_key varchar(64) NOT NULL,
			job_name varchar(100) DEFAULT '' NOT NULL,
			action varchar(50) DEFAULT '' NOT NULL,
			record_type varchar(100) DEFAULT '' NOT NULL,
			record_id varchar(190) DEFAULT '' NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			status varchar(50) DEFAULT 'success' NOT NULL,
			message longtext NULL,
			raw_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY log_id (log_id),
			KEY run_key (run_key),
			KEY job_name (job_name),
			KEY action (action),
			KEY record_type (record_type),
			KEY record_id (record_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY status (status)
		) $charset_collate;

		CREATE TABLE $t_ledger (
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

		CREATE TABLE $t_service_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stripe_customer_id varchar(100) NOT NULL,
			stripe_price_id varchar(100) DEFAULT '' NOT NULL,
			stripe_product_id varchar(100) DEFAULT '' NOT NULL,
			service_name varchar(255) DEFAULT '' NOT NULL,
			request_type varchar(100) DEFAULT 'add_service' NOT NULL,
			status varchar(50) DEFAULT 'draft' NOT NULL,
			service_status varchar(50) DEFAULT 'new' NOT NULL,
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
			KEY service_status (service_status),
			KEY source_object_id (source_object_id),
			KEY ledger_id (ledger_id),
			KEY created_at (created_at)
		) $charset_collate;

		CREATE TABLE $t_event_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(100) NOT NULL,
			severity varchar(20) DEFAULT 'info' NOT NULL,
			source varchar(100) DEFAULT '' NOT NULL,
			correlation_id varchar(100) DEFAULT '' NOT NULL,
			site_uuid varchar(100) DEFAULT '' NOT NULL,
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			wp_user_id_before bigint(20) unsigned NOT NULL DEFAULT 0,
			wp_user_id_after bigint(20) unsigned NOT NULL DEFAULT 0,
			email_before varchar(190) DEFAULT '' NOT NULL,
			email_after varchar(190) DEFAULT '' NOT NULL,
			portal_status_before varchar(50) DEFAULT '' NOT NULL,
			portal_status_after varchar(50) DEFAULT '' NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_email varchar(190) DEFAULT '' NOT NULL,
			details longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY severity (severity),
			KEY source (source),
			KEY correlation_id (correlation_id),
			KEY site_uuid (site_uuid),
			KEY customer_id (customer_id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY actor_user_id (actor_user_id),
			KEY created_at (created_at)
		) $charset_collate;

		CREATE TABLE $t_stripe_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id varchar(190) NOT NULL,
			event_type varchar(100) NOT NULL,
			livemode tinyint(1) NOT NULL DEFAULT 0,
			stripe_account varchar(100) DEFAULT '' NOT NULL,
			object_id varchar(190) DEFAULT '' NOT NULL,
			processing_status varchar(50) DEFAULT 'received' NOT NULL,
			first_seen_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			processed_at datetime NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			last_error longtext NULL,
			raw_event longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY event_type (event_type),
			KEY livemode (livemode),
			KEY stripe_account (stripe_account),
			KEY object_id (object_id),
			KEY processing_status (processing_status),
			KEY first_seen_at (first_seen_at)
		) $charset_collate;";
	}
}
