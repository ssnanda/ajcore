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
		$table_portal_file_tags  = $wpdb->prefix . 'aj_portal_file_tags';
		$table_email_log         = $wpdb->prefix . 'aj_portal_email_log';
		$table_partners          = $wpdb->prefix . 'aj_portal_partners';
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
		$table_compliance_entities  = $wpdb->prefix . 'aj_portal_compliance_entities';
		$table_compliance_filings   = $wpdb->prefix . 'aj_portal_compliance_filings';
		$table_sync_logs            = $wpdb->prefix . 'aj_portal_sync_logs';
		$table_sync_log_items       = $wpdb->prefix . 'aj_portal_sync_log_items';
		$table_service_requests     = $wpdb->prefix . 'aj_portal_service_requests';
		$table_service_request_history = $wpdb->prefix . 'aj_portal_service_request_history';
		$table_event_log            = $wpdb->prefix . 'aj_portal_event_log';
		$table_stripe_events        = $wpdb->prefix . 'aj_portal_stripe_events';
		$table_service_snapshots    = $wpdb->prefix . 'aj_portal_service_snapshots';
		$table_service_states       = $wpdb->prefix . 'aj_portal_service_states';
		$table_customer_states      = $wpdb->prefix . 'aj_portal_customer_states';
		$table_reservation_resources  = $wpdb->prefix . 'aj_portal_reservation_resources';
		$table_reservations           = $wpdb->prefix . 'aj_portal_reservations';
		$table_mail_items             = $wpdb->prefix . 'aj_portal_mail_items';
		$table_ajphone_conversations  = $wpdb->prefix . 'ajphone_conversations';
		$table_storage_objects        = $wpdb->prefix . 'aj_storage_objects';

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
			status varchar(50) DEFAULT 'new' NOT NULL,
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
			status varchar(20) DEFAULT 'active' NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY category (category),
			KEY status (status)
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

		CREATE TABLE $table_portal_file_tags (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_id bigint(20) unsigned NOT NULL,
			tag_slug varchar(100) NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_tag (file_id, tag_slug),
			KEY tag_slug (tag_slug)
		) $charset_collate;

		CREATE TABLE $table_partners (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			partner_key varchar(100) NOT NULL,
			name varchar(255) DEFAULT '' NOT NULL,
			billing_mode varchar(30) DEFAULT 'invoiced_report' NOT NULL,
			per_account_amount decimal(10,2) DEFAULT 0 NOT NULL,
			currency varchar(10) DEFAULT 'usd' NOT NULL,
			stripe_price_id varchar(100) DEFAULT '' NOT NULL,
			notes text NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY partner_key (partner_key)
		) $charset_collate;

		CREATE TABLE $table_email_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			to_email varchar(190) DEFAULT '' NOT NULL,
			subject varchar(255) DEFAULT '' NOT NULL,
			headers text NULL,
			message longtext NULL,
			status varchar(20) DEFAULT 'sent' NOT NULL,
			error_message text NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY to_email (to_email),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;

		CREATE TABLE $table_stripe_customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) NOT NULL,
			email varchar(190) DEFAULT '' NOT NULL,
			name varchar(255) DEFAULT '' NOT NULL,
			phone varchar(100) DEFAULT '' NOT NULL,
			description varchar(500) DEFAULT '' NOT NULL,
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
			product_type varchar(50) DEFAULT 'normal' NOT NULL,
			reservation_resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reservation_resource_key varchar(100) DEFAULT '' NOT NULL,
			reservation_business_hours_price_id varchar(100) DEFAULT '' NOT NULL,
			reservation_after_hours_price_id varchar(100) DEFAULT '' NOT NULL,
			reservation_duration_minutes int(11) NOT NULL DEFAULT 60,
			reservation_buffer_before_minutes int(11) NOT NULL DEFAULT 0,
			reservation_buffer_after_minutes int(11) NOT NULL DEFAULT 0,
			reservation_min_duration_minutes int(11) NOT NULL DEFAULT 60,
			reservation_max_duration_minutes int(11) NOT NULL DEFAULT 60,
			reservation_zoho_calendar_uid varchar(255) DEFAULT '' NOT NULL,
			reservation_zoho_calendar_id varchar(255) DEFAULT '' NOT NULL,
			reservation_zoho_resource_uid varchar(255) DEFAULT '' NOT NULL,
			reservation_zoho_schedule_url longtext NULL,
			reservation_zoho_freebusy_url longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stripe_product_id (stripe_product_id),
			KEY visibility (visibility),
			KEY duplicate_behavior (duplicate_behavior),
			KEY product_type (product_type),
			KEY reservation_resource_key (reservation_resource_key)
		) $charset_collate;

		CREATE TABLE $table_reservation_resources (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_key varchar(100) NOT NULL,
			resource_name varchar(255) NOT NULL,
			zoho_calendar_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_calendar_id varchar(255) DEFAULT '' NOT NULL,
			zoho_resource_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_schedule_url longtext NULL,
			zoho_freebusy_url longtext NULL,
			business_hours_price_id varchar(100) DEFAULT '' NOT NULL,
			after_hours_price_id varchar(100) DEFAULT '' NOT NULL,
			duration_minutes int(11) NOT NULL DEFAULT 60,
			buffer_before_minutes int(11) NOT NULL DEFAULT 0,
			buffer_after_minutes int(11) NOT NULL DEFAULT 0,
			min_duration_minutes int(11) NOT NULL DEFAULT 60,
			max_duration_minutes int(11) NOT NULL DEFAULT 60,
			active tinyint(1) NOT NULL DEFAULT 1,
			settings_json longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY resource_key (resource_key),
			KEY active (active)
		) $charset_collate;

		CREATE TABLE $table_reservations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_uuid varchar(100) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resource_key varchar(100) DEFAULT '' NOT NULL,
			resource_name varchar(255) DEFAULT '' NOT NULL,
			zoho_calendar_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_calendar_id varchar(255) DEFAULT '' NOT NULL,
			zoho_resource_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_event_id varchar(255) DEFAULT '' NOT NULL,
			stripe_checkout_session_id varchar(100) DEFAULT '' NOT NULL,
			stripe_payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			stripe_invoice_id varchar(100) DEFAULT '' NOT NULL,
			stripe_price_id varchar(100) DEFAULT '' NOT NULL,
			pricing_type varchar(50) DEFAULT 'after_hours_weekend' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			timezone varchar(100) DEFAULT 'America/New_York' NOT NULL,
			status varchar(50) DEFAULT 'pending_payment' NOT NULL,
			customer_name varchar(255) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			customer_notes longtext NULL,
			admin_notes longtext NULL,
			raw_zoho_data longtext NULL,
			raw_stripe_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reservation_uuid (reservation_uuid),
			KEY stripe_customer_id (stripe_customer_id),
			KEY wp_user_id (wp_user_id),
			KEY resource_id (resource_id),
			KEY resource_key (resource_key),
			KEY stripe_checkout_session_id (stripe_checkout_session_id),
			KEY stripe_payment_intent_id (stripe_payment_intent_id),
			KEY status (status),
			KEY pricing_type (pricing_type),
			KEY start_at (start_at),
			KEY customer_email (customer_email)
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

		CREATE TABLE $table_compliance_entities (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			entity_name varchar(255) NOT NULL,
			entity_type varchar(50) DEFAULT 'llc' NOT NULL,
			jurisdiction varchar(10) DEFAULT 'NC' NOT NULL,
			sos_id varchar(100) DEFAULT '' NOT NULL,
			formation_date date NULL,
			first_report_year smallint(5) unsigned NOT NULL DEFAULT 0,
			due_month tinyint(3) unsigned NOT NULL DEFAULT 4,
			due_day tinyint(3) unsigned NOT NULL DEFAULT 15,
			entity_status varchar(50) DEFAULT 'active' NOT NULL,
			notes longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY entity_status (entity_status),
			KEY entity_type (entity_type),
			KEY jurisdiction (jurisdiction)
		) $charset_collate;

		CREATE TABLE $table_compliance_filings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_id bigint(20) unsigned NOT NULL,
			filing_type varchar(50) DEFAULT 'annual_report' NOT NULL,
			period_year smallint(5) unsigned NOT NULL,
			due_date date NOT NULL,
			status varchar(50) DEFAULT 'pending' NOT NULL,
			filed_at datetime NULL,
			filed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			confirmation varchar(190) DEFAULT '' NOT NULL,
			notes longtext NULL,
			client_completed tinyint(1) NOT NULL DEFAULT 0,
			client_completed_at datetime NULL,
			client_note longtext NULL,
			reminder_stage varchar(20) DEFAULT '' NOT NULL,
			last_reminder_at datetime NULL,
			reminders_sent int(11) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entity_period (entity_id, filing_type, period_year),
			KEY entity_id (entity_id),
			KEY due_date (due_date),
			KEY status (status)
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
		) $charset_collate;

		CREATE TABLE $table_mail_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			mail_uuid varchar(100) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			recipient_name varchar(255) DEFAULT '' NOT NULL,
			mail_type varchar(50) DEFAULT 'letter' NOT NULL,
			is_sop tinyint(1) NOT NULL DEFAULT 0,
			sender_name varchar(255) DEFAULT '' NOT NULL,
			carrier varchar(50) DEFAULT '' NOT NULL,
			tracking_number varchar(100) DEFAULT '' NOT NULL,
			description longtext NULL,
			status varchar(50) DEFAULT 'received' NOT NULL,
			disposition varchar(50) DEFAULT '' NOT NULL,
			scan_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			scan_url text NULL,
			file_id bigint(20) unsigned NOT NULL DEFAULT 0,
			received_at datetime NOT NULL,
			notified_at datetime NULL,
			disposed_at datetime NULL,
			admin_notes longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY mail_uuid (mail_uuid),
			KEY stripe_customer_id (stripe_customer_id),
			KEY customer_email (customer_email),
			KEY status (status),
			KEY mail_type (mail_type),
			KEY is_sop (is_sop),
			KEY disposition (disposition),
			KEY received_at (received_at)
		) $charset_collate;

		CREATE TABLE $table_ajphone_conversations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_key varchar(255) NOT NULL,
			account_key varchar(50) NOT NULL DEFAULT 'primary',
			own_number varchar(30) NOT NULL DEFAULT '',
			peer_number varchar(30) NOT NULL DEFAULT '',
			is_read tinyint(1) NOT NULL DEFAULT 0,
			is_pinned tinyint(1) NOT NULL DEFAULT 0,
			is_archived tinyint(1) NOT NULL DEFAULT 0,
			is_deleted tinyint(1) NOT NULL DEFAULT 0,
			queue varchar(10) NOT NULL DEFAULT 'ai',
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conversation_key (conversation_key),
			KEY account_key (account_key),
			KEY is_read (is_read),
			KEY is_pinned (is_pinned),
			KEY is_archived (is_archived),
			KEY is_deleted (is_deleted),
			KEY queue (queue)
		) $charset_collate;

		CREATE TABLE $table_storage_objects (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			driver varchar(20) NOT NULL DEFAULT 's3',
			bucket varchar(190) NOT NULL DEFAULT '',
			object_key varchar(500) NOT NULL DEFAULT '',
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			content_type varchar(190) NOT NULL DEFAULT '',
			migrated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Records created before file archiving had no status value. Keep them
		// visible as active files instead of silently dropping them from AJCore.
		$wpdb->query( "UPDATE `{$table_portal_files}` SET status = 'active' WHERE status IS NULL OR status = ''" );

		// In shared DB mode, also create reservation and mail tables in the portal/shared DB.
		if ( function_exists( 'ajcore_get_portal_db' ) ) {
			$pdb = ajcore_get_portal_db();
			if ( $pdb !== $wpdb ) {
				$pdb_charset = $pdb->get_charset_collate();
				self::create_reservation_tables_in_portal_db( $pdb->prefix, $pdb_charset, $pdb );
				self::create_mail_tables_in_portal_db( $pdb->prefix, $pdb_charset, $pdb );
			}
		}

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

		// Legacy rows may hold '0' / '1' (old enabled_portal values) in portal_status — treat them as unset too.
		$wpdb->query( "UPDATE $table_stripe_customers SET portal_status = 'active' WHERE enabled_portal = 1 AND (portal_status IN ('', '0', '1') OR portal_status IS NULL)" );
		$wpdb->query( "UPDATE $table_stripe_customers SET portal_status = 'disabled' WHERE enabled_portal = 0 AND (portal_status IN ('', '0', '1') OR portal_status IS NULL)" );
		$wpdb->query( "UPDATE $table_stripe_customers SET enabled_portal = 1 WHERE portal_status = 'active'" );
		$wpdb->query( "UPDATE $table_stripe_customers SET enabled_portal = 0 WHERE portal_status IN ('disabled','archived','without_portal_login')" );

		// In shared DB mode the live customers table is in the portal DB — apply the same normalization there.
		if ( function_exists( 'ajcore_get_portal_db' ) ) {
			$pdb = ajcore_get_portal_db();
			if ( $pdb !== $wpdb ) {
				$shared_customers = $pdb->prefix . 'aj_portal_stripe_customers';
				if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $shared_customers ) ) === $shared_customers ) {
					$pdb->query( "UPDATE $shared_customers SET portal_status = 'active' WHERE enabled_portal = 1 AND (portal_status IN ('', '0', '1') OR portal_status IS NULL)" );
					$pdb->query( "UPDATE $shared_customers SET portal_status = 'disabled' WHERE enabled_portal = 0 AND (portal_status IN ('', '0', '1') OR portal_status IS NULL)" );
					$pdb->query( "UPDATE $shared_customers SET enabled_portal = 1 WHERE portal_status = 'active'" );
					$pdb->query( "UPDATE $shared_customers SET enabled_portal = 0 WHERE portal_status IN ('disabled','archived','without_portal_login')" );
				}
			}
		}

		// Payer/partner accounts: partners table + partner_key on customers, in the local AND portal DB.
		$partner_dbs = array( $wpdb );
		if ( function_exists( 'ajcore_get_portal_db' ) && ajcore_get_portal_db() !== $wpdb ) {
			$partner_dbs[] = ajcore_get_portal_db();
		}
		foreach ( $partner_dbs as $partner_db ) {
			$db_partners  = $partner_db->prefix . 'aj_portal_partners';
			$db_customers = $partner_db->prefix . 'aj_portal_stripe_customers';
			$db_charset   = $partner_db->get_charset_collate();

			$partner_db->query(
				"CREATE TABLE IF NOT EXISTS $db_partners (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					partner_key varchar(100) NOT NULL,
					name varchar(255) DEFAULT '' NOT NULL,
					billing_mode varchar(30) DEFAULT 'invoiced_report' NOT NULL,
					per_account_amount decimal(10,2) DEFAULT 0 NOT NULL,
					currency varchar(10) DEFAULT 'usd' NOT NULL,
					stripe_price_id varchar(100) DEFAULT '' NOT NULL,
					notes text NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
					updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY partner_key (partner_key)
				) $db_charset"
			);

			if ( $partner_db->get_var( $partner_db->prepare( 'SHOW TABLES LIKE %s', $db_customers ) ) === $db_customers ) {
				$has_partner_col = $partner_db->get_var( "SHOW COLUMNS FROM $db_customers LIKE 'partner_key'" );
				if ( ! $has_partner_col ) {
					$partner_db->query( "ALTER TABLE $db_customers ADD COLUMN partner_key varchar(100) DEFAULT '' NOT NULL, ADD KEY partner_key (partner_key)" );
				}
				$has_partner_price_col = $partner_db->get_var( "SHOW COLUMNS FROM $db_customers LIKE 'partner_price_id'" );
				if ( ! $has_partner_price_col ) {
					$partner_db->query( "ALTER TABLE $db_customers ADD COLUMN partner_price_id varchar(100) DEFAULT '' NOT NULL" );
				}
			}

			// Seed the two known partners when missing.
			$seed_partners = array(
				array( 'opus', 'OPUS', 'fixed_per_account', 'OPUS pays a fixed rate per account — no invoicing by us. Extra services are upsold and charged to their account.' ),
				array( 'alliance_vo', 'Alliance VO', 'invoiced_report', 'We bill Alliance from a monthly report (accounts × rate). All end-customer billing goes through Alliance.' ),
			);
			foreach ( $seed_partners as $seed ) {
				$exists = $partner_db->get_var( $partner_db->prepare( "SELECT id FROM $db_partners WHERE partner_key = %s LIMIT 1", $seed[0] ) );
				if ( ! $exists ) {
					$partner_db->insert(
						$db_partners,
						array(
							'partner_key'  => $seed[0],
							'name'         => $seed[1],
							'billing_mode' => $seed[2],
							'notes'        => $seed[3],
							'created_at'   => current_time( 'mysql' ),
							'updated_at'   => current_time( 'mysql' ),
						),
						array( '%s', '%s', '%s', '%s', '%s', '%s' )
					);
				}
			}
		}

		// Service request history + assignee: the history table was added to the plugin after shared
		// DB mode already existed for some installs, so it only ever got created locally — writes to
		// it via the shared connection were silently failing. Also add an assignee column so a
		// request can be handed to a specific staff member. Create/patch on the local AND portal DB.
		$sr_dbs = array( $wpdb );
		if ( function_exists( 'ajcore_get_portal_db' ) && ajcore_get_portal_db() !== $wpdb ) {
			$sr_dbs[] = ajcore_get_portal_db();
		}
		foreach ( $sr_dbs as $sr_db ) {
			$db_sr_history = $sr_db->prefix . 'aj_portal_service_request_history';
			$db_sr         = $sr_db->prefix . 'aj_portal_service_requests';
			$sr_charset    = $sr_db->get_charset_collate();

			$sr_db->query(
				"CREATE TABLE IF NOT EXISTS $db_sr_history (
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
					PRIMARY KEY (id),
					KEY request_id (request_id),
					KEY event_type (event_type),
					KEY visibility (visibility),
					KEY actor_user_id (actor_user_id),
					KEY created_at (created_at)
				) $sr_charset"
			);

			if ( $sr_db->get_var( $sr_db->prepare( 'SHOW TABLES LIKE %s', $db_sr ) ) === $db_sr ) {
				$has_assignee_col = $sr_db->get_var( "SHOW COLUMNS FROM $db_sr LIKE 'assigned_user_id'" );
				if ( ! $has_assignee_col ) {
					$sr_db->query( "ALTER TABLE $db_sr ADD COLUMN assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY assigned_user_id (assigned_user_id)" );
				}
			}
		}

		// Leads: expanded status set (won/lost/duplicate) needs a link to the customer a "won"
		// lead converted to, and duplicate-merge tracking. Leads live only on the local $wpdb
		// (never shared-DB), so a single ALTER is enough — no dual-connection pass needed.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_leads ) ) === $table_leads ) {
			$has_lead_customer_col = $wpdb->get_var( "SHOW COLUMNS FROM $table_leads LIKE 'stripe_customer_id'" );
			if ( ! $has_lead_customer_col ) {
				$wpdb->query( "ALTER TABLE $table_leads ADD COLUMN stripe_customer_id varchar(191) DEFAULT '' NOT NULL, ADD KEY stripe_customer_id (stripe_customer_id)" );
			}
			$has_lead_merged_col = $wpdb->get_var( "SHOW COLUMNS FROM $table_leads LIKE 'merged_into_lead_id'" );
			if ( ! $has_lead_merged_col ) {
				$wpdb->query( "ALTER TABLE $table_leads ADD COLUMN merged_into_lead_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY merged_into_lead_id (merged_into_lead_id)" );
			}
			$has_lead_updated_col = $wpdb->get_var( "SHOW COLUMNS FROM $table_leads LIKE 'updated_at'" );
			if ( ! $has_lead_updated_col ) {
				$wpdb->query( "ALTER TABLE $table_leads ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL" );
			}

			// "unread" renamed to "new" (default status for a fresh lead — "read" is now set
			// automatically when staff open the lead, not via a manual dropdown).
			$wpdb->query( "ALTER TABLE $table_leads MODIFY status varchar(50) DEFAULT 'new' NOT NULL" );
			$wpdb->query( "UPDATE $table_leads SET status = 'new' WHERE status = 'unread'" );
		}

		self::ensure_shared_leads_tables_and_migrate();

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

		add_role(
			'aj_ops_user',
			__( 'AJ Ops User', 'ajforms' ),
			array(
				'read'              => true,
				'ajcore_ops_access' => true,
			)
		);
		$ops_role = get_role( 'aj_ops_user' );
		if ( $ops_role && ! $ops_role->has_cap( 'read' ) ) {
			$ops_role->add_cap( 'read' );
		}
		if ( $ops_role && ! $ops_role->has_cap( 'ajcore_ops_access' ) ) {
			$ops_role->add_cap( 'ajcore_ops_access' );
		}

		update_option( 'ajforms_version', AJFORMS_VERSION, false );
		update_option( 'ajforms_portal_schema_version', '30', false );
	}

	/**
	 * Leads on the shared portal DB: leads used to live only on each site's local DB, which
	 * meant AJ Ops (talking to the master site) never saw leads captured by forms on the other
	 * sites. Now the leads + lead_notes tables also exist on the shared DB (with a site_uuid
	 * column recording which site captured each lead) and all lead reads/writes go through
	 * ajcore_get_portal_db(). This method:
	 *   1. Patches the LOCAL tables with the new columns (site_uuid, form_title, author_name)
	 *      so local-only installs keep an identical schema.
	 *   2. Creates the shared tables when a shared DB is connected.
	 *   3. One-time-migrates this site's existing local leads into the shared DB, remapping
	 *      lead IDs (multiple sites' local IDs collide) for notes and merged_into_lead_id.
	 *      Non-destructive: local rows are left in place, guarded by an option flag.
	 */
	public static function ensure_shared_leads_tables_and_migrate() {
		global $wpdb;

		$local_leads = $wpdb->prefix . 'aj_forms_leads';
		$local_notes = $wpdb->prefix . 'aj_forms_lead_notes';

		// 1. Patch local tables (columns must match the shared schema for local mode).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $local_leads ) ) === $local_leads ) {
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $local_leads LIKE 'site_uuid'" ) ) {
				$wpdb->query( "ALTER TABLE $local_leads ADD COLUMN site_uuid varchar(100) DEFAULT '' NOT NULL, ADD KEY site_uuid (site_uuid)" );
			}
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $local_leads LIKE 'form_title'" ) ) {
				$wpdb->query( "ALTER TABLE $local_leads ADD COLUMN form_title varchar(255) DEFAULT '' NOT NULL" );
			}
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $local_notes ) ) === $local_notes ) {
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $local_notes LIKE 'author_name'" ) ) {
				$wpdb->query( "ALTER TABLE $local_notes ADD COLUMN author_name varchar(190) DEFAULT '' NOT NULL" );
			}
		}

		if ( ! function_exists( 'ajcore_get_portal_db' ) ) {
			return;
		}
		$pdb = ajcore_get_portal_db();
		if ( $pdb === $wpdb ) {
			return; // Local mode — nothing shared to create or migrate.
		}

		// 2. Create the shared tables (mirrors get_shared_portal_table_sql, IF NOT EXISTS form).
		$shared_leads = $pdb->prefix . 'aj_forms_leads';
		$shared_notes = $pdb->prefix . 'aj_forms_lead_notes';
		$charset      = $pdb->get_charset_collate();

		$pdb->query(
			"CREATE TABLE IF NOT EXISTS $shared_leads (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				form_title varchar(255) DEFAULT '' NOT NULL,
				lead_data longtext NOT NULL,
				status varchar(50) DEFAULT 'new' NOT NULL,
				ip_address varchar(100) DEFAULT '' NOT NULL,
				source_url text NULL,
				user_agent text NULL,
				site_uuid varchar(100) DEFAULT '' NOT NULL,
				stripe_customer_id varchar(191) DEFAULT '' NOT NULL,
				merged_into_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				legacy_local_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY (id),
				KEY form_id (form_id),
				KEY status (status),
				KEY site_uuid (site_uuid),
				KEY stripe_customer_id (stripe_customer_id),
				KEY merged_into_lead_id (merged_into_lead_id),
				KEY legacy_local_id (legacy_local_id)
			) $charset"
		);
		$pdb->query(
			"CREATE TABLE IF NOT EXISTS $shared_notes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL,
				note longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				author_name varchar(190) DEFAULT '' NOT NULL,
				created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
				PRIMARY KEY (id),
				KEY lead_id (lead_id)
			) $charset"
		);

		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $shared_leads ) ) !== $shared_leads ) {
			update_option(
				'ajcore_leads_shared_migration_errors',
				array( sprintf( 'Could not create the shared leads table (%s): %s', $shared_leads, (string) $pdb->last_error ) ),
				false
			);
			return; // Try again next activation — flag stays unset.
		}

		// Shared table may pre-date the tracking column (created by an earlier plugin version).
		if ( ! $pdb->get_var( "SHOW COLUMNS FROM $shared_leads LIKE 'legacy_local_id'" ) ) {
			$pdb->query( "ALTER TABLE $shared_leads ADD COLUMN legacy_local_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY legacy_local_id (legacy_local_id)" );
		}

		// 3. Local → shared migration for this site's existing leads. Idempotent: each copied
		//    row records its source local id (legacy_local_id), so re-runs skip already-copied
		//    leads instead of duplicating them, and rows copied by the earlier migration version
		//    (before the tracking column existed) are adopted by exact content match. Every
		//    per-row DB error is captured to the ajcore_leads_shared_migration_errors option
		//    (surfaced on the wp-admin Leads page) and the done-flag is only set when every
		//    local lead is verifiably in the shared table — so a failed run retries on the
		//    next activation instead of silently stranding leads in the local DB.
		$site_uuid = (string) get_option( 'ajcore_site_uuid', '' );
		$forms_table = $wpdb->prefix . 'aj_forms_forms';
		$form_titles = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, title FROM `{$forms_table}`" ) as $f ) {
			$form_titles[ (int) $f->id ] = (string) $f->title;
		}

		// Heal blank form titles on this site's already-copied rows (e.g., rows migrated while
		// the forms lookup failed, or before form_title stamping existed). Runs every time —
		// it's a no-op once titles are filled.
		foreach ( $form_titles as $fid => $ftitle ) {
			if ( '' === $ftitle ) {
				continue;
			}
			$pdb->query( $pdb->prepare( "UPDATE $shared_leads SET form_title = %s WHERE site_uuid = %s AND form_id = %d AND form_title = ''", $ftitle, $site_uuid, $fid ) );
		}

		if ( '2' === (string) get_option( 'ajcore_leads_migrated_to_shared', '' ) ) {
			return;
		}

		$errors      = array();
		$id_map      = array(); // old local id => new shared id (freshly inserted this run only)
		$accounted   = 0;
		$rows        = $wpdb->get_results( "SELECT * FROM `{$local_leads}` ORDER BY id ASC", ARRAY_A );
		$total_local = count( (array) $rows );

		foreach ( (array) $rows as $row ) {
			$old_id  = (int) $row['id'];
			$form_id = (int) $row['form_id'];

			// Already copied by a previous (partial) run?
			$existing = $pdb->get_var( $pdb->prepare( "SELECT id FROM $shared_leads WHERE site_uuid = %s AND legacy_local_id = %d LIMIT 1", $site_uuid, $old_id ) );
			if ( $existing ) {
				$accounted++;
				continue;
			}

			// Copied by the earlier migration version (no tracking column then)? Adopt it.
			$adoptable = $pdb->get_var( $pdb->prepare( "SELECT id FROM $shared_leads WHERE site_uuid = %s AND legacy_local_id = 0 AND created_at = %s AND lead_data = %s LIMIT 1", $site_uuid, (string) $row['created_at'], (string) $row['lead_data'] ) );
			if ( $adoptable ) {
				$pdb->update( $shared_leads, array( 'legacy_local_id' => $old_id ), array( 'id' => (int) $adoptable ), array( '%d' ), array( '%d' ) );
				$accounted++;
				continue;
			}

			// Old rows can hold zero/blank datetimes, which strict-mode MySQL rejects outright.
			$created_at = (string) $row['created_at'];
			if ( '' === $created_at || 0 === strpos( $created_at, '0000-00-00' ) ) {
				$created_at = current_time( 'mysql' );
			}
			$updated_at = ! empty( $row['updated_at'] ) ? (string) $row['updated_at'] : $created_at;
			if ( 0 === strpos( $updated_at, '0000-00-00' ) ) {
				$updated_at = $created_at;
			}

			$inserted = $pdb->insert(
				$shared_leads,
				array(
					'form_id'             => $form_id,
					'form_title'          => isset( $form_titles[ $form_id ] ) ? $form_titles[ $form_id ] : '',
					'lead_data'           => (string) $row['lead_data'],
					'status'              => (string) $row['status'],
					'ip_address'          => isset( $row['ip_address'] ) ? (string) $row['ip_address'] : '',
					'source_url'          => isset( $row['source_url'] ) ? (string) $row['source_url'] : '',
					'user_agent'          => isset( $row['user_agent'] ) ? (string) $row['user_agent'] : '',
					'site_uuid'           => $site_uuid,
					'stripe_customer_id'  => isset( $row['stripe_customer_id'] ) ? (string) $row['stripe_customer_id'] : '',
					'merged_into_lead_id' => isset( $row['merged_into_lead_id'] ) ? (int) $row['merged_into_lead_id'] : 0,
					'legacy_local_id'     => $old_id,
					'created_at'          => $created_at,
					'updated_at'          => $updated_at,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			if ( false === $inserted || ! $pdb->insert_id ) {
				$errors[] = sprintf( 'Lead #%d: %s', $old_id, '' !== (string) $pdb->last_error ? (string) $pdb->last_error : 'insert failed' );
				continue;
			}
			$id_map[ $old_id ] = (int) $pdb->insert_id;
			$accounted++;
		}

		// Remap merged_into_lead_id references to the new shared IDs (fresh inserts only).
		foreach ( $id_map as $old_id => $new_id ) {
			$pdb->query( $pdb->prepare( "UPDATE $shared_leads SET merged_into_lead_id = %d WHERE merged_into_lead_id = %d AND site_uuid = %s AND legacy_local_id != 0", $new_id, $old_id, $site_uuid ) );
		}

		// Notes: only for leads freshly inserted this run — adopted/skipped ones already have theirs.
		$note_rows = $wpdb->get_results( "SELECT * FROM `{$local_notes}` ORDER BY id ASC", ARRAY_A );
		foreach ( (array) $note_rows as $n ) {
			$old_lead_id = (int) $n['lead_id'];
			if ( ! isset( $id_map[ $old_lead_id ] ) ) {
				continue;
			}
			$note_created = (string) $n['created_at'];
			if ( '' === $note_created || 0 === strpos( $note_created, '0000-00-00' ) ) {
				$note_created = current_time( 'mysql' );
			}
			$author = get_userdata( (int) $n['created_by'] );
			$note_inserted = $pdb->insert(
				$shared_notes,
				array(
					'lead_id'     => $id_map[ $old_lead_id ],
					'note'        => (string) $n['note'],
					'created_by'  => (int) $n['created_by'],
					'author_name' => $author ? (string) $author->display_name : '',
					'created_at'  => $note_created,
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			if ( false === $note_inserted ) {
				$errors[] = sprintf( 'Note #%d (lead #%d): %s', (int) $n['id'], $old_lead_id, '' !== (string) $pdb->last_error ? (string) $pdb->last_error : 'insert failed' );
			}
		}

		if ( empty( $errors ) && $accounted === $total_local ) {
			update_option( 'ajcore_leads_migrated_to_shared', '2', false );
			delete_option( 'ajcore_leads_shared_migration_errors' );
		} else {
			if ( $accounted !== $total_local && empty( $errors ) ) {
				$errors[] = sprintf( 'Only %d of %d local leads reached the shared DB.', $accounted, $total_local );
			}
			update_option( 'ajcore_leads_shared_migration_errors', array_slice( $errors, 0, 20 ), false );
		}
	}

	/**
	 * Create reservation tables directly in the given portal DB connection.
	 * Uses CREATE TABLE IF NOT EXISTS so it is safe to call repeatedly.
	 * Called when the portal DB differs from the local $wpdb instance.
	 *
	 * @param string $prefix          DB table prefix for the portal DB.
	 * @param string $charset_collate Charset/collate string from portal DB.
	 * @param object $pdb             Portal wpdb instance.
	 */
	public static function create_reservation_tables_in_portal_db( $prefix, $charset_collate, $pdb ) {
		$res_resources = $prefix . 'aj_portal_reservation_resources';
		$res_table     = $prefix . 'aj_portal_reservations';

		$pdb->query( "CREATE TABLE IF NOT EXISTS {$res_resources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_key varchar(100) NOT NULL,
			resource_name varchar(255) NOT NULL,
			zoho_calendar_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_calendar_id varchar(255) DEFAULT '' NOT NULL,
			zoho_resource_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_schedule_url longtext NULL,
			zoho_freebusy_url longtext NULL,
			business_hours_price_id varchar(100) DEFAULT '' NOT NULL,
			after_hours_price_id varchar(100) DEFAULT '' NOT NULL,
			duration_minutes int(11) NOT NULL DEFAULT 60,
			buffer_before_minutes int(11) NOT NULL DEFAULT 0,
			buffer_after_minutes int(11) NOT NULL DEFAULT 0,
			min_duration_minutes int(11) NOT NULL DEFAULT 60,
			max_duration_minutes int(11) NOT NULL DEFAULT 60,
			active tinyint(1) NOT NULL DEFAULT 1,
			settings_json longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY resource_key (resource_key),
			KEY active (active)
		) {$charset_collate}" );

		$pdb->query( "CREATE TABLE IF NOT EXISTS {$res_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reservation_uuid varchar(100) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resource_id bigint(20) unsigned NOT NULL DEFAULT 0,
			resource_key varchar(100) DEFAULT '' NOT NULL,
			resource_name varchar(255) DEFAULT '' NOT NULL,
			zoho_calendar_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_calendar_id varchar(255) DEFAULT '' NOT NULL,
			zoho_resource_uid varchar(255) DEFAULT '' NOT NULL,
			zoho_event_id varchar(255) DEFAULT '' NOT NULL,
			stripe_checkout_session_id varchar(100) DEFAULT '' NOT NULL,
			stripe_payment_intent_id varchar(100) DEFAULT '' NOT NULL,
			stripe_invoice_id varchar(100) DEFAULT '' NOT NULL,
			stripe_price_id varchar(100) DEFAULT '' NOT NULL,
			pricing_type varchar(50) DEFAULT 'after_hours_weekend' NOT NULL,
			amount decimal(12,2) DEFAULT 0 NOT NULL,
			currency varchar(12) DEFAULT 'usd' NOT NULL,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			timezone varchar(100) DEFAULT 'America/New_York' NOT NULL,
			status varchar(50) DEFAULT 'pending_payment' NOT NULL,
			customer_name varchar(255) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			customer_notes longtext NULL,
			admin_notes longtext NULL,
			raw_zoho_data longtext NULL,
			raw_stripe_data longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reservation_uuid (reservation_uuid),
			KEY stripe_customer_id (stripe_customer_id),
			KEY wp_user_id (wp_user_id),
			KEY resource_id (resource_id),
			KEY resource_key (resource_key),
			KEY stripe_checkout_session_id (stripe_checkout_session_id),
			KEY stripe_payment_intent_id (stripe_payment_intent_id),
			KEY status (status),
			KEY pricing_type (pricing_type),
			KEY start_at (start_at),
			KEY customer_email (customer_email)
		) {$charset_collate}" );

		$shared_settings_table = $prefix . 'aj_shared_settings';
		$pdb->query( "CREATE TABLE IF NOT EXISTS {$shared_settings_table} (
			setting_name varchar(191) NOT NULL,
			setting_value longtext NOT NULL DEFAULT '',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (setting_name)
		) {$charset_collate}" );
	}

	/**
	 * Create the mail intake table directly in the given portal DB connection.
	 * Uses CREATE TABLE IF NOT EXISTS so it is safe to call repeatedly.
	 * Called when the portal DB differs from the local $wpdb instance.
	 *
	 * @param string $prefix          DB table prefix for the portal DB.
	 * @param string $charset_collate Charset/collate string from portal DB.
	 * @param object $pdb             Portal wpdb instance.
	 */
	public static function create_mail_tables_in_portal_db( $prefix, $charset_collate, $pdb ) {
		$mail_table = $prefix . 'aj_portal_mail_items';

		$pdb->query( "CREATE TABLE IF NOT EXISTS {$mail_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			mail_uuid varchar(100) NOT NULL,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			customer_email varchar(190) DEFAULT '' NOT NULL,
			recipient_name varchar(255) DEFAULT '' NOT NULL,
			mail_type varchar(50) DEFAULT 'letter' NOT NULL,
			is_sop tinyint(1) NOT NULL DEFAULT 0,
			sender_name varchar(255) DEFAULT '' NOT NULL,
			carrier varchar(50) DEFAULT '' NOT NULL,
			tracking_number varchar(100) DEFAULT '' NOT NULL,
			description longtext NULL,
			status varchar(50) DEFAULT 'received' NOT NULL,
			disposition varchar(50) DEFAULT '' NOT NULL,
			scan_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			scan_url text NULL,
			file_id bigint(20) unsigned NOT NULL DEFAULT 0,
			received_at datetime NOT NULL,
			notified_at datetime NULL,
			disposed_at datetime NULL,
			admin_notes longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY mail_uuid (mail_uuid),
			KEY stripe_customer_id (stripe_customer_id),
			KEY customer_email (customer_email),
			KEY status (status),
			KEY mail_type (mail_type),
			KEY is_sop (is_sop),
			KEY disposition (disposition),
			KEY received_at (received_at)
		) {$charset_collate}" );
	}

	/**
	 * Returns CREATE TABLE SQL for the shared portal tables only.
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
		$t_compliance_entities  = $prefix . 'aj_portal_compliance_entities';
		$t_compliance_filings   = $prefix . 'aj_portal_compliance_filings';
		$t_sync_logs            = $prefix . 'aj_portal_sync_logs';
		$t_sync_log_items       = $prefix . 'aj_portal_sync_log_items';
		$t_ledger               = $prefix . 'aj_portal_ledger';
		$t_service_requests     = $prefix . 'aj_portal_service_requests';
		$t_event_log            = $prefix . 'aj_portal_event_log';
		$t_stripe_events        = $prefix . 'aj_portal_stripe_events';
		$t_carts                = $prefix . 'aj_portal_carts';
		$t_leads                = $prefix . 'aj_forms_leads';
		$t_lead_notes           = $prefix . 'aj_forms_lead_notes';

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
			description varchar(500) DEFAULT '' NOT NULL,
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

		CREATE TABLE $t_compliance_entities (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			stripe_customer_id varchar(100) DEFAULT '' NOT NULL,
			entity_name varchar(255) NOT NULL,
			entity_type varchar(50) DEFAULT 'llc' NOT NULL,
			jurisdiction varchar(10) DEFAULT 'NC' NOT NULL,
			sos_id varchar(100) DEFAULT '' NOT NULL,
			formation_date date NULL,
			first_report_year smallint(5) unsigned NOT NULL DEFAULT 0,
			due_month tinyint(3) unsigned NOT NULL DEFAULT 4,
			due_day tinyint(3) unsigned NOT NULL DEFAULT 15,
			entity_status varchar(50) DEFAULT 'active' NOT NULL,
			notes longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY stripe_customer_id (stripe_customer_id),
			KEY entity_status (entity_status),
			KEY entity_type (entity_type),
			KEY jurisdiction (jurisdiction)
		) $charset_collate;

		CREATE TABLE $t_compliance_filings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_id bigint(20) unsigned NOT NULL,
			filing_type varchar(50) DEFAULT 'annual_report' NOT NULL,
			period_year smallint(5) unsigned NOT NULL,
			due_date date NOT NULL,
			status varchar(50) DEFAULT 'pending' NOT NULL,
			filed_at datetime NULL,
			filed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			confirmation varchar(190) DEFAULT '' NOT NULL,
			notes longtext NULL,
			client_completed tinyint(1) NOT NULL DEFAULT 0,
			client_completed_at datetime NULL,
			client_note longtext NULL,
			reminder_stage varchar(20) DEFAULT '' NOT NULL,
			last_reminder_at datetime NULL,
			reminders_sent int(11) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entity_period (entity_id, filing_type, period_year),
			KEY entity_id (entity_id),
			KEY due_date (due_date),
			KEY status (status)
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
		) $charset_collate;

		CREATE TABLE $t_leads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			form_title varchar(255) DEFAULT '' NOT NULL,
			lead_data longtext NOT NULL,
			status varchar(50) DEFAULT 'new' NOT NULL,
			ip_address varchar(100) DEFAULT '' NOT NULL,
			source_url text NULL,
			user_agent text NULL,
			site_uuid varchar(100) DEFAULT '' NOT NULL,
			stripe_customer_id varchar(191) DEFAULT '' NOT NULL,
			merged_into_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			legacy_local_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY site_uuid (site_uuid),
			KEY stripe_customer_id (stripe_customer_id),
			KEY merged_into_lead_id (merged_into_lead_id),
			KEY legacy_local_id (legacy_local_id)
		) $charset_collate;

		CREATE TABLE $t_lead_notes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			note longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			author_name varchar(190) DEFAULT '' NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id)
		) $charset_collate;";
	}
}
