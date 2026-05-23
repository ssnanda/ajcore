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
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		add_role(
			'aj_portal_user',
			__( 'AJ Portal User', 'ajforms' ),
			array(
				'read' => true,
			)
		);
		update_option( 'ajforms_version', AJFORMS_VERSION, false );
		update_option( 'ajforms_portal_schema_version', '2', false );
	}
}
