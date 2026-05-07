<?php

class AJForms_Activator {

	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_forms      = $wpdb->prefix . 'ajforms_forms';
		$table_leads      = $wpdb->prefix . 'ajforms_leads';
		$table_lead_notes = $wpdb->prefix . 'ajforms_lead_notes';

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
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'ajforms_version', AJFORMS_VERSION, false );
	}
}
