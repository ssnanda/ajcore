<?php
/**
 * Plugin Name:       AJ Core
 * Plugin URI:        https://github.com/ssnanda/ajcore
 * Description:       A modular WordPress business toolkit for forms, payments, portals, auth, CRM, and automations.
 * Version: 0.7.229
 * Author:            IT Spector LLC
 * Author URI:        https://itspector.com
 * Update URI:        false
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       ajcore
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'AJCORE_VERSION' ) ) {
	define( 'AJCORE_VERSION', '0.7.229' );
}

if ( ! defined( 'AJCORE_PLUGIN_DIR' ) ) {
	define( 'AJCORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'AJCORE_PLUGIN_URL' ) ) {
	define( 'AJCORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'AJCORE_PLUGIN_BASENAME' ) ) {
	define( 'AJCORE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'AJCORE_SYNCED_SETTINGS_FILE' ) ) {
	define( 'AJCORE_SYNCED_SETTINGS_FILE', AJCORE_PLUGIN_DIR . 'config/synced-settings.json' );
}

if ( ! defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ) {
	define( 'AJCORE_SYSTEM_FROM_EMAIL', 'donotreply@ncllcagents.com' );
}

if ( ! defined( 'AJFORMS_VERSION' ) ) {
	define( 'AJFORMS_VERSION', AJCORE_VERSION );
}

if ( ! defined( 'AJFORMS_PLUGIN_DIR' ) ) {
	define( 'AJFORMS_PLUGIN_DIR', AJCORE_PLUGIN_DIR );
}

if ( ! defined( 'AJFORMS_PLUGIN_URL' ) ) {
	define( 'AJFORMS_PLUGIN_URL', AJCORE_PLUGIN_URL );
}

if ( ! defined( 'AJFORMS_PLUGIN_BASENAME' ) ) {
	define( 'AJFORMS_PLUGIN_BASENAME', AJCORE_PLUGIN_BASENAME );
}

if ( ! defined( 'AJFORMS_SYNCED_SETTINGS_FILE' ) ) {
	define( 'AJFORMS_SYNCED_SETTINGS_FILE', AJCORE_SYNCED_SETTINGS_FILE );
}

if ( ! function_exists( 'ajcore_generate_service_request_number' ) ) {
	function ajcore_generate_service_request_number( $created_at = null ) {
		$pdb = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];
		$table = $pdb->prefix . 'aj_portal_service_request_number_counters';
		$time = $created_at ? strtotime( (string) $created_at ) : false;
		$month = gmdate( 'Y-m', $time ? $time : time() );
		$pdb->query( $pdb->prepare( "INSERT INTO {$table} (`year_month`, next_seq) VALUES (%s, 1) ON DUPLICATE KEY UPDATE next_seq = LAST_INSERT_ID(next_seq + 1)", $month ) );
		$seq = (int) $pdb->get_var( 'SELECT LAST_INSERT_ID()' );
		return sprintf( '%s-%04d', $month, $seq > 0 ? $seq : 1 );
	}
}

if ( ! function_exists( 'ajcore_backfill_service_request_numbers' ) ) {
	function ajcore_backfill_service_request_numbers() {
		$pdb = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];
		$table = $pdb->prefix . 'aj_portal_service_requests';
		if ( ! $pdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'service_request_number'" ) ) return;
		$lock_name = 'ajcore_service_request_number_backfill';
		$got_lock = (bool) $pdb->get_var( $pdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) );
		if ( ! $got_lock ) return;
		try {
			// Re-read after acquiring the lock in case another request completed the work while this one waited.
			$rows = $pdb->get_results( "SELECT id, created_at FROM {$table} WHERE service_request_number = '' OR service_request_number IS NULL ORDER BY created_at ASC, id ASC" );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$pdb->update( $table, array( 'service_request_number' => ajcore_generate_service_request_number( $row->created_at ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			}
		} finally {
			$pdb->query( $pdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

if ( ! function_exists( 'ajcore_resequence_service_request_numbers' ) ) {
	/** Compact legacy monthly numbering after the original unlocked backfill created gaps. */
	function ajcore_resequence_service_request_numbers() {
		$pdb = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];
		$table = $pdb->prefix . 'aj_portal_service_requests';
		$counter = $pdb->prefix . 'aj_portal_service_request_number_counters';
		if ( ! $pdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'service_request_number'" ) ) return false;
		$lock_name = 'ajcore_service_request_number_backfill';
		$got_lock = (bool) $pdb->get_var( $pdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name ) );
		if ( ! $got_lock ) return false;
		try {
			$rows = $pdb->get_results( "SELECT id, created_at FROM {$table} ORDER BY created_at ASC, id ASC" );
			$counts = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$timestamp = $row->created_at ? strtotime( (string) $row->created_at ) : false;
				$month = gmdate( 'Y-m', $timestamp ? $timestamp : time() );
				$counts[ $month ] = isset( $counts[ $month ] ) ? $counts[ $month ] + 1 : 1;
				$pdb->update( $table, array( 'service_request_number' => sprintf( '%s-%04d', $month, $counts[ $month ] ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
			}
			$pdb->query( "DELETE FROM {$counter}" );
			foreach ( $counts as $month => $count ) {
				$pdb->insert( $counter, array( 'year_month' => $month, 'next_seq' => $count ), array( '%s', '%d' ) );
			}
			return true;
		} finally {
			$pdb->query( $pdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

/** Permanently suppress Hostinger AI's API-token sales notice on AJ Core sites. */
if ( ! function_exists( 'ajcore_suppress_hostinger_ai_token_notice' ) ) {
	function ajcore_suppress_hostinger_ai_token_notice() {
		global $wp_filter;

		if ( empty( $wp_filter['admin_notices'] ) || empty( $wp_filter['admin_notices']->callbacks ) ) {
			return;
		}

		foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$handler = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( is_array( $handler ) && is_object( $handler[0] ) && $handler[0] instanceof Hostinger_Ai_Assistant_Notices && 'api_token_plugin_notice' === $handler[1] ) {
					remove_action( 'admin_notices', $handler, $priority );
				}
			}
		}
	}
	add_action( 'admin_init', 'ajcore_suppress_hostinger_ai_token_notice', PHP_INT_MAX );
}

if ( ! function_exists( 'ajforms_get_settings_defaults' ) ) {
	function ajforms_get_settings_defaults() {
		return array(
			'default_notification_email'    => get_option( 'admin_email' ),
			'default_notification_subject'  => 'New submission for {form_title}',
			'default_notifications_enabled' => '1',
			'default_from_name'             => get_bloginfo( 'name' ),
			'default_reply_to_mode'         => 'submitter',
			'wp_email_templates_enabled'    => '1',
			'wp_email_from_email'           => defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? AJCORE_SYSTEM_FROM_EMAIL : 'donotreply@ncllcagents.com',
			'wp_email_from_name'            => get_bloginfo( 'name' ),
			'wp_password_reset_subject'     => 'Password reset for your Portal Login for NC LLC Agents Inc',
			'wp_welcome_email_subject'      => 'Welcome : Your portal access is enabled to NC LLC Agents Inc',
			'wp_service_status_subject'     => 'Update on {service_name}: {status_label}',
			'lead_followup_email_subject'   => 'Following up from NC LLC Agents',
			'wp_password_reset_heading'     => 'Set your client portal password',
			'wp_password_reset_body'        => "Hi {name},\nUse the secure button below to create a new password for your client portal account. This link is private and should only be used by you.",
			'wp_welcome_heading'            => 'Welcome to your client portal',
			'wp_welcome_body'               => "Hi {name},\nYour client portal access has been enabled. Use the button below to set your password and sign in securely.",
			'wp_service_status_heading'     => 'Your service request was updated',
			'wp_service_status_body'        => "Hi {name},\nThe status of \"{service_name}\" has changed.",
			'lead_followup_heading'         => "We'd love to hear from you",
			'lead_followup_body'            => "Hi {name},\nWe wanted to follow up on your recent inquiry with NC LLC Agents. If you have any questions or would like to talk through your options, give us a call — we are happy to help.\nReady to get started? You can review our services and pricing anytime on our website.",
			'wp_password_reset_from_email'  => '',
			'wp_password_reset_from_name'   => '',
			'wp_welcome_from_email'         => '',
			'wp_welcome_from_name'          => '',
			'wp_service_status_from_email'  => '',
			'wp_service_status_from_name'   => '',
			'university_wp_password_reset_subject'    => 'Password reset for your University Place Office Suites portal login',
			'university_wp_password_reset_heading'    => 'Set your client portal password',
			'university_wp_password_reset_body'       => "Hi {name},\nUse the secure button below to create a new password for your client portal account. This link is private and should only be used by you.",
			'university_wp_password_reset_from_email' => 'donotreply@universityofficesuites.com',
			'university_wp_password_reset_from_name'  => 'University Place Office Suites',
			'university_wp_welcome_email_subject'     => 'Welcome : Your portal access is enabled to University Place Office Suites LLC',
			'university_wp_welcome_heading'           => 'Welcome to your client portal',
			'university_wp_welcome_body'              => "Hi {name},\nYour client portal access has been enabled. Use the button below to set your password and sign in securely.",
			'university_wp_welcome_from_email'        => 'donotreply@universityofficesuites.com',
			'university_wp_welcome_from_name'         => 'University Place Office Suites',
			'university_wp_service_status_subject'    => 'Update on {service_name}: {status_label}',
			'university_wp_service_status_heading'    => 'Your service request was updated',
			'university_wp_service_status_body'       => "Hi {name},\nThe status of \"{service_name}\" has changed.",
			'university_wp_service_status_from_email' => 'donotreply@universityofficesuites.com',
			'university_wp_service_status_from_name'  => 'University Place Office Suites',
			'university_lead_followup_email_subject'  => 'Following up from University Place Office Suites',
			'university_lead_followup_heading'        => "We'd love to hear from you",
			'university_lead_followup_body'           => "Hi {name},\nWe wanted to follow up on your recent inquiry with University Place Office Suites. If you have any questions or would like to talk through your options, give us a call — we are happy to help.\nReady to get started? You can review our services and pricing anytime on our website.",
			'university_lead_followup_from_email'     => 'donotreply@universityofficesuites.com',
			'university_lead_followup_from_name'      => 'University Place Office Suites',
			'lead_followup_from_email'      => 'contactus@ncllcagents.com',
			'lead_followup_from_name'       => '',
			// Zoho Mail shared-inbox OAuth app (Inbox settings). client_id/secret/account_email/
			// data_center are admin-entered; the rest are written only by the OAuth callback itself.
			'zoho_mail_client_id'           => '',
			'zoho_mail_client_secret'       => '',
			'zoho_mail_account_email'       => 'agent@ncllcagents.com',
			// The Zoho Organization ID (zoid) — there's no reliable non-partner API to discover
			// this for a regular admin, so it's entered manually. Visible in the Zoho Mail Admin
			// Console under Organization -> Profile.
			'zoho_mail_org_id'              => '',
			// Optional manual override: the Zoho Group ID (zgid) of the Shared Mailbox group,
			// copied from the Admin Console. Skips the org-wide groups list (which has been
			// observed returning an empty list for a Shared Mailbox that demonstrably exists)
			// and fetches that one group directly instead.
			'zoho_mail_group_id'            => '',
			'zoho_mail_data_center'         => 'com',
			'zoho_mail_access_token'        => '',
			'zoho_mail_refresh_token'       => '',
			'zoho_mail_token_expires_at'    => 0,
			'zoho_mail_account_id'          => '',
			'zoho_mail_connected_email'     => '',
			'zoho_mail_connected_at'        => '',
			// Gmail Intake (Email settings). A dedicated Gmail inbox (not Zoho) that staff forward
			// state-filing notification emails into; AJCore polls it, matches the sender's subject
			// to a customer, and files PDF attachments into that customer's Files.
			'gmail_intake_client_id'         => '',
			'gmail_intake_client_secret'     => '',
			'gmail_intake_address'           => 'universityplaceofficesuites@gmail.com',
			'gmail_intake_access_token'      => '',
			'gmail_intake_refresh_token'     => '',
			'gmail_intake_token_expires_at'  => 0,
			'gmail_intake_connected_email'   => '',
			'gmail_intake_connected_at'      => '',
			'gmail_intake_label_id'          => '',
			// E-Signatures (BreezeDoc). A single shared BreezeDoc account, authenticated with a
			// Personal Access Token, used to send templates out for customer signature.
			'breezedoc_api_token'           => '',
			// Self-hosted Live Chat. chat_server_url/chat_notify_secret are shared across every
			// connected site (master-controlled, same as the settings above) — they point every
			// site's widget/reply actions at the one AJOps chat server and let it verify the
			// /chat/notify webhook AJCore fires after every write. chat_widget_enabled is
			// deliberately LOCAL (per-site, never pushed to the shared DB) so the widget can be
			// rolled out to individual sites one at a time.
			'chat_server_url'               => '',
			// Optional override of chat_server_url for AJCore's own outbound /chat/notify call
			// only (never sent to the browser). In production these are identical and this stays
			// blank — it exists because in local ddev, PHP (inside the ddev container) and the
			// browser resolve "the AJOps server" differently (e.g. http://host.docker.internal:3000
			// vs http://localhost:3000), and no single URL is reachable from both.
			'chat_notify_url'                => '',
			'chat_notify_secret'            => '',
			'chat_ws_token_secret'          => '',
			'chat_internal_secret'          => '',
			'chat_widget_enabled'           => '0',
			// Passive "want to text us?" nudge — on by default (matches the widget's original
			// always-on behavior, before this toggle existed) so existing sites see no change.
			'chat_engage_popup_enabled'     => '1',
			'chat_engage_popup_delay_seconds' => '25',
			// "Live Visitors" self-identify prompt — a light, dismissible ask (name/email/phone, all
			// optional) shown from the chat widget's presence connection, independent of whether the
			// visitor ever opens the chat panel. Deliberately LOCAL per-site, same reasoning as
			// chat_widget_enabled just above, and only has any effect where that's also on (the prompt
			// rides the widget's existing presence WebSocket — see ajcore-chat-widget.js).
			'visitor_identify_enabled'      => '0',
			// No fixed-collision floor needed against the engage popup's delay/auto-dismiss window —
			// the widget retries until the engage popup (if any) is off-screen rather than a one-shot
			// check, so any combination of the two delays below is safe.
			'visitor_identify_delay_seconds' => '55',
			// Visitor-facing "visit timer" — a tiny, unlabeled, barely-there number (cumulative
			// seconds on site across every visit, not just this one) shown in a page corner. Same
			// per-site/local reasoning as the settings above; also only has any effect where
			// chat_widget_enabled is on (the presence connection is what carries the number down).
			'visitor_timer_enabled'         => '0',
			// Business hours gate for the widget's offline banner — a simple "Mon-Fri 09:00-17:00"
			// style string parsed client-side (widget evaluates it against the visitor's local
			// clock), not a full per-day schedule builder.
			'chat_business_hours_enabled'   => '0',
			'chat_business_hours'           => 'Mon-Fri 09:00-17:00',
			// 0 = disabled. Hours of no activity before an open session auto-closes.
			'chat_auto_close_hours'         => '24',
			'chat_transcript_email_subject' => 'Your chat transcript from {site_name}',
			'chat_transcript_email_heading' => 'Here\'s a copy of your chat',
			'chat_transcript_email_body'    => "Hi {name},\nThanks for chatting with us. Here's a copy of your conversation for your records.",
			'default_success_message'       => 'Form submitted successfully.',
			'validation_mode'               => 'native',
			'require_unique_form_names'     => '1',
			'honeypot_enabled'              => '1',
			'spam_challenge_provider'       => 'turnstile',
			'recaptcha_site_key'            => '',
			'recaptcha_secret_key'          => '',
			'hcaptcha_site_key'             => '',
			'hcaptcha_secret_key'           => '',
			'turnstile_site_key'            => '',
			'turnstile_secret_key'          => '',
			'webhook_url'                   => '',
			'asana_enabled'                 => '0',
			'asana_personal_access_token'   => '',
			'asana_workspace_gid'           => '',
			'asana_project_gid'             => '',
			// Rentec Direct API v3. The API key is encrypted at rest with the other
			// integration credentials and is only used server-side by AJ Core.
			'rentec_enabled'                => '0',
			'rentec_api_key'                => '',
			'rentec_account_label_1'        => 'Rentec Account 1',
			'rentec_api_key_2'              => '',
			'rentec_account_label_2'        => 'Rentec Account 2',
			// UPOS Honeywell Home thermostat integration. Credentials remain server-side;
			// AJOps only receives masked configuration status and thermostat data.
			'upos_thermo_client_id'          => '',
			'upos_thermo_client_secret'      => '',
			'upos_thermo_redirect_uri'       => '',
			'upos_thermo_location_id'        => '',
			'upos_thermo_device_ids'         => 'LCC-48A2E6C087EE,LCC-48A2E6C087F0,LCC-5CFCE1418EA4,LCC-5CFCE10F604B,LCC-5CFCE12AC73C,LCC-5CFCE12AC700,LCC-5CFCE12AC788,LCC-5CFCE12AC902,LCC-5CFCE10C335C,LCC-5CFCE10F6061,LCC-5CFCE145B2E1',
			'upos_thermo_refresh_token'      => '',
			'stripe_mode'                   => 'test',
			'stripe_sandbox_publishable_key' => '',
			'stripe_sandbox_secret_key'      => '',
			'stripe_live_publishable_key'    => '',
			'stripe_live_secret_key'         => '',
			'stripe_publishable_key'        => '',
			'stripe_secret_key'             => '',
			'stripe_products_mode'          => 'all',
			'stripe_selected_prices'        => array(),
			'stripe_late_fees_enabled'       => '0',
			'stripe_late_fee_type'           => 'fixed',
			'stripe_late_fee_amount'         => '25.00',
			'stripe_late_fee_grace_days'     => 5,
			'stripe_late_fee_due_days'       => 7,
			'portal_event_log_retention_days'   => 180,
			'portal_event_log_max_rows'         => 50000,
			'zoho_reservations_enabled'         => '0',
			'zoho_default_timezone'             => 'America/New_York',
			'zoho_calendar_uid'                 => '',
			'zoho_calendar_id'                  => '',
			'zoho_calendar_embed_url'           => '',
			'zoho_resource_uid'                 => '',
			'zoho_schedule_appointment_url'     => '',
			'zoho_resource_freebusy_url'        => 'https://calendar.zoho.com/api/v1/resources/{resourceuid}/freebusy',
			'zoho_api_auth_mode'                => '',
			// Canonical Zoho OAuth setting names.
			'zoho_client_id'                    => '',
			'zoho_client_secret'                => '',
			'zoho_access_token'                 => '',
			'zoho_refresh_token'                => '',
			'zoho_token_expires_at'             => '',
			'zoho_api_domain'                   => '',
			// Legacy aliases kept for backward compatibility (populated from canonical on save).
			'zoho_oauth_client_id'              => '',
			'zoho_oauth_client_secret'          => '',
			'zoho_oauth_api_domain'             => '',
			'zoho_api_token'                    => '',
			'zoho_api_token_expires_at'         => '',
			// One-time code: never stored after successful exchange.
			'zoho_oauth_authorization_code'     => '',
			// Availability failure behavior: 'strict' blocks booking if Zoho check fails;
			// 'lenient' allows it and logs a warning. Portal API defaults lenient.
			'zoho_availability_failure_mode'    => 'lenient',
			'reservation_resource_name'         => 'Conference Room',
			'reservation_resource_key'          => 'conference_room',
			'reservation_menu_label'            => 'Conference Room',
			'reservation_business_hours_label'  => 'Business Hours (Mon–Fri 9am–5pm)',
			'reservation_after_hours_label'     => 'After-Hours / Weekend',
		);
	}
}


if ( ! function_exists( 'ajcore_normalize_stripe_settings' ) ) {
	function ajcore_normalize_stripe_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$mode     = ! empty( $settings['stripe_mode'] ) && 'live' === sanitize_key( (string) $settings['stripe_mode'] ) ? 'live' : 'test';
		$settings['stripe_mode'] = $mode;

		foreach ( array( 'stripe_sandbox_publishable_key', 'stripe_sandbox_secret_key', 'stripe_live_publishable_key', 'stripe_live_secret_key', 'stripe_publishable_key', 'stripe_secret_key' ) as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				$settings[ $key ] = '';
			}
		}

		/* Backward compatibility: if an older single-key setting exists, place it into the matching environment bucket in memory. */
		$legacy_publishable = trim( (string) $settings['stripe_publishable_key'] );
		if ( '' !== $legacy_publishable && function_exists( 'ajcore_get_stripe_key_environment' ) ) {
			$environment = ajcore_get_stripe_key_environment( $legacy_publishable );
			if ( 'test' === $environment && '' === trim( (string) $settings['stripe_sandbox_publishable_key'] ) ) {
				$settings['stripe_sandbox_publishable_key'] = $legacy_publishable;
			} elseif ( 'live' === $environment && '' === trim( (string) $settings['stripe_live_publishable_key'] ) ) {
				$settings['stripe_live_publishable_key'] = $legacy_publishable;
			}
		}

		$legacy_secret = trim( (string) $settings['stripe_secret_key'] );
		if ( '' !== $legacy_secret && function_exists( 'ajcore_get_stripe_key_environment' ) ) {
			$environment = ajcore_get_stripe_key_environment( $legacy_secret );
			if ( 'test' === $environment && '' === trim( (string) $settings['stripe_sandbox_secret_key'] ) ) {
				$settings['stripe_sandbox_secret_key'] = $legacy_secret;
			} elseif ( 'live' === $environment && '' === trim( (string) $settings['stripe_live_secret_key'] ) ) {
				$settings['stripe_live_secret_key'] = $legacy_secret;
			}
		}

		$active_prefix = 'live' === $mode ? 'stripe_live' : 'stripe_sandbox';
		$settings['stripe_publishable_key'] = trim( (string) $settings[ $active_prefix . '_publishable_key' ] );
		$settings['stripe_secret_key']      = trim( (string) $settings[ $active_prefix . '_secret_key' ] );

		return $settings;
	}
}

if ( ! function_exists( 'ajforms_get_settings' ) ) {
	function ajforms_get_settings() {
		$raw_saved_settings = get_option( 'ajforms_settings', false );
		$saved_settings     = is_array( $raw_saved_settings ) ? $raw_saved_settings : array();
		$has_saved_settings = false !== $raw_saved_settings && ! empty( $saved_settings );
		if ( ! is_array( $saved_settings ) ) {
			$saved_settings = array();
		}

		$file_settings = ajforms_read_synced_settings_file();
		if ( ! is_array( $file_settings ) ) {
			$file_settings = array();
		}

		if ( ! $has_saved_settings ) {
			$legacy_stripe_settings = ajforms_read_legacy_synced_stripe_settings_file();
			if ( ! empty( $legacy_stripe_settings ) ) {
				$file_settings = array_merge( $file_settings, $legacy_stripe_settings );
			}
		}

		$settings = wp_parse_args(
			array_merge( $file_settings, $saved_settings ),
			ajforms_get_settings_defaults()
		);

		$settings = function_exists( 'ajcore_normalize_stripe_settings' ) ? ajcore_normalize_stripe_settings( $settings ) : $settings;

		if ( ! $has_saved_settings && ! empty( $file_settings ) ) {
			update_option( 'ajforms_settings', $settings );
		}

		// Calendar/reservation settings are shared across sites. The master's local
		// option is the source of truth (it pushes to the shared DB on every save);
		// secondary sites overlay the shared values so they never rely on their own
		// stale local copies.
		if ( function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled()
			&& function_exists( 'ajcore_read_shared_calendar_settings' ) ) {
			$is_master = ! function_exists( 'ajcore_is_stripe_sync_owner' ) || ajcore_is_stripe_sync_owner();
			if ( ! $is_master ) {
				$shared_calendar = ajcore_read_shared_calendar_settings();
				if ( ! empty( $shared_calendar ) ) {
					$settings = array_merge( $settings, $shared_calendar );
				}
				if ( function_exists( 'ajcore_read_shared_chat_settings' ) ) {
					$shared_chat = ajcore_read_shared_chat_settings();
					if ( ! empty( $shared_chat ) ) {
						$settings = array_merge( $settings, $shared_chat );
					}
				}
				if ( function_exists( 'ajcore_read_shared_rentec_settings' ) ) {
					$shared_rentec = ajcore_read_shared_rentec_settings();
					if ( ! empty( $shared_rentec ) ) {
						$settings = array_merge( $settings, $shared_rentec );
					}
				}
				if ( function_exists( 'ajcore_read_shared_gmail_intake_settings' ) ) {
					$shared_gmail_intake = ajcore_read_shared_gmail_intake_settings();
					if ( ! empty( $shared_gmail_intake ) ) {
						$settings = array_merge( $settings, $shared_gmail_intake );
					}
				}
			}
		}

		return $settings;
	}
}

if ( ! function_exists( 'ajforms_get_synced_setting_keys' ) ) {
	function ajforms_get_synced_setting_keys() {
		return array(
			'honeypot_enabled',
			'spam_challenge_provider',
			'recaptcha_site_key',
			'hcaptcha_site_key',
			'turnstile_site_key',
			'asana_enabled',
			'asana_workspace_gid',
			'asana_project_gid',
			'portal_event_log_retention_days',
			'portal_event_log_max_rows',
		);
	}
}

if ( ! function_exists( 'ajforms_get_stripe_setting_keys' ) ) {
	function ajforms_get_stripe_setting_keys() {
		return array(
			'stripe_mode',
			'stripe_sandbox_publishable_key',
			'stripe_sandbox_secret_key',
			'stripe_live_publishable_key',
			'stripe_live_secret_key',
			'stripe_publishable_key',
			'stripe_secret_key',
		);
	}
}

if ( ! function_exists( 'ajcore_get_secret_setting_keys' ) ) {
	/**
	 * Settings fields that hold credentials and are encrypted at rest in wp_options.
	 */
	function ajcore_get_secret_setting_keys() {
		return array(
			'stripe_sandbox_secret_key',
			'stripe_live_secret_key',
			'stripe_secret_key',
			'recaptcha_secret_key',
			'hcaptcha_secret_key',
			'turnstile_secret_key',
			'asana_personal_access_token',
			'breezedoc_api_token',
			'rentec_api_key',
			'rentec_api_key_2',
			'upos_thermo_client_secret',
			'upos_thermo_refresh_token',
		);
	}
}

if ( ! function_exists( 'ajcore_encrypt_setting_value' ) ) {
	/**
	 * Encrypt one settings value with a key derived from this site's auth salt.
	 * Format: "ajenc1:" + base64( nonce + secretbox ). Values that are empty or
	 * already encrypted pass through unchanged, so the transform is idempotent.
	 *
	 * NOTE: rotating AUTH_KEY/AUTH_SALT in wp-config.php makes stored secrets
	 * undecryptable — they must then be re-entered on the settings screen.
	 */
	function ajcore_encrypt_setting_value( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, 'ajenc1:' ) ) {
			return $value;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! function_exists( 'wp_salt' ) ) {
			return $value;
		}
		$key   = hash( 'sha256', wp_salt( 'auth' ) . '|ajcore-settings-v1', true );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'ajenc1:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) );
	}
}

if ( ! function_exists( 'ajcore_decrypt_setting_value' ) ) {
	function ajcore_decrypt_setting_value( $value ) {
		if ( ! is_string( $value ) || 0 !== strpos( $value, 'ajenc1:' ) ) {
			return $value; // Plaintext (pre-migration) passes through.
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || ! function_exists( 'wp_salt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $value, 7 ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$key   = hash( 'sha256', wp_salt( 'auth' ) . '|ajcore-settings-v1', true );
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		return false === $plain ? '' : $plain;
	}
}

if ( ! function_exists( 'ajcore_encrypt_settings_secrets' ) ) {
	/**
	 * pre_update_option_ajforms_settings filter: every save path stores secrets encrypted.
	 */
	function ajcore_encrypt_settings_secrets( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		foreach ( ajcore_get_secret_setting_keys() as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = ajcore_encrypt_setting_value( $settings[ $key ] );
			}
		}
		return $settings;
	}
}

if ( ! function_exists( 'ajcore_decrypt_settings_secrets' ) ) {
	/**
	 * option_ajforms_settings filter: every read path sees decrypted secrets.
	 */
	function ajcore_decrypt_settings_secrets( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		foreach ( ajcore_get_secret_setting_keys() as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = ajcore_decrypt_setting_value( $settings[ $key ] );
			}
		}
		return $settings;
	}
}

add_filter( 'pre_update_option_ajforms_settings', 'ajcore_encrypt_settings_secrets' );
add_filter( 'option_ajforms_settings', 'ajcore_decrypt_settings_secrets' );

if ( ! function_exists( 'ajcore_maybe_encrypt_saved_settings_secrets' ) ) {
	/**
	 * One-time migration: re-save the settings option so existing plaintext secrets
	 * pass through the encryption filter and land encrypted in the database.
	 */
	function ajcore_maybe_encrypt_saved_settings_secrets() {
		if ( '1' === get_option( 'ajcore_settings_secrets_encrypted' ) ) {
			return;
		}
		$settings = get_option( 'ajforms_settings', false );
		if ( is_array( $settings ) && ! empty( $settings ) ) {
			update_option( 'ajforms_settings', $settings );
		}
		update_option( 'ajcore_settings_secrets_encrypted', '1', false );
	}
}
add_action( 'admin_init', 'ajcore_maybe_encrypt_saved_settings_secrets' );

if ( ! function_exists( 'ajcore_mask_secret_for_display' ) ) {
	/**
	 * "sk_live_…82Uq" style hint for settings screens — never the full key.
	 */
	function ajcore_mask_secret_for_display( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( strlen( $value ) <= 12 ) {
			return '••••';
		}
		return substr( $value, 0, 8 ) . '••••' . substr( $value, -4 );
	}
}

if ( ! function_exists( 'ajforms_read_synced_settings_file' ) ) {
	function ajforms_read_synced_settings_file() {
		if ( ! file_exists( AJFORMS_SYNCED_SETTINGS_FILE ) || ! is_readable( AJFORMS_SYNCED_SETTINGS_FILE ) ) {
			return array();
		}

		$raw_settings = file_get_contents( AJFORMS_SYNCED_SETTINGS_FILE );
		if ( false === $raw_settings || '' === trim( $raw_settings ) ) {
			return array();
		}

		$decoded = json_decode( $raw_settings, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}

		$synced = array();
		foreach ( ajforms_get_synced_setting_keys() as $key ) {
			if ( array_key_exists( $key, $decoded ) ) {
				$synced[ $key ] = $decoded[ $key ];
			}
		}

		return $synced;
	}
}

if ( ! function_exists( 'ajforms_read_legacy_synced_stripe_settings_file' ) ) {
	function ajforms_read_legacy_synced_stripe_settings_file() {
		if ( ! file_exists( AJFORMS_SYNCED_SETTINGS_FILE ) || ! is_readable( AJFORMS_SYNCED_SETTINGS_FILE ) ) {
			return array();
		}

		$raw_settings = file_get_contents( AJFORMS_SYNCED_SETTINGS_FILE );
		if ( false === $raw_settings || '' === trim( $raw_settings ) ) {
			return array();
		}

		$decoded = json_decode( $raw_settings, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}

		$synced = array();
		foreach ( ajforms_get_stripe_setting_keys() as $key ) {
			if ( array_key_exists( $key, $decoded ) ) {
				$synced[ $key ] = $decoded[ $key ];
			}
		}

		return $synced;
	}
}

if ( ! function_exists( 'ajforms_write_synced_settings_file' ) ) {
	function ajforms_write_synced_settings_file( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$directory = dirname( AJFORMS_SYNCED_SETTINGS_FILE );
		if ( ! file_exists( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$synced_settings = array();
		foreach ( ajforms_get_synced_setting_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$synced_settings[ $key ] = $settings[ $key ];
			}
		}

		$encoded = wp_json_encode( $synced_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return false;
		}

		return false !== file_put_contents( AJFORMS_SYNCED_SETTINGS_FILE, $encoded . PHP_EOL, LOCK_EX );
	}
}


if ( ! function_exists( 'ajcore_get_stripe_mode_label' ) ) {
	function ajcore_get_stripe_mode_label( $mode ) {
		$mode = sanitize_key( (string) $mode );

		return 'live' === $mode ? __( 'Live', 'ajforms' ) : __( 'Sandbox', 'ajforms' );
	}
}

if ( ! function_exists( 'ajcore_get_stripe_key_environment' ) ) {
	function ajcore_get_stripe_key_environment( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return '';
		}

		if ( preg_match( '/^(pk|sk|rk)_test_/', $key ) ) {
			return 'test';
		}

		if ( preg_match( '/^(pk|sk|rk)_live_/', $key ) ) {
			return 'live';
		}

		return 'unknown';
	}
}

if ( ! function_exists( 'ajcore_get_stripe_mode_issues' ) ) {
	function ajcore_get_stripe_mode_issues( $settings, $include_secret = true ) {
		$settings = is_array( $settings ) ? $settings : array();
		$mode     = ! empty( $settings['stripe_mode'] ) && 'live' === sanitize_key( (string) $settings['stripe_mode'] ) ? 'live' : 'test';
		$expected = $mode;
		$issues   = array();
		$keys     = array(
			'publishable' => isset( $settings['stripe_publishable_key'] ) ? (string) $settings['stripe_publishable_key'] : '',
		);

		if ( $include_secret ) {
			$keys['secret'] = isset( $settings['stripe_secret_key'] ) ? (string) $settings['stripe_secret_key'] : '';
		}

		foreach ( $keys as $label => $key ) {
			$environment = ajcore_get_stripe_key_environment( $key );
			if ( '' === $environment || 'unknown' === $environment ) {
				continue;
			}

			if ( $environment !== $expected ) {
				$issues[] = sprintf(
					/* translators: 1: Stripe mode, 2: key label, 3: key environment */
					__( 'Stripe Mode is set to %1$s, but the %2$s key is a %3$s key.', 'ajforms' ),
					ajcore_get_stripe_mode_label( $mode ),
					$label,
					ajcore_get_stripe_mode_label( $environment )
				);
			}
		}

		return $issues;
	}
}

if ( ! function_exists( 'ajcore_get_stripe_mode_badge_data' ) ) {
	function ajcore_get_stripe_mode_badge_data( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$mode     = ! empty( $settings['stripe_mode'] ) && 'live' === sanitize_key( (string) $settings['stripe_mode'] ) ? 'live' : 'test';
		$issues   = ajcore_get_stripe_mode_issues( $settings, true );

		return array(
			'mode'        => $mode,
			'label'       => ajcore_get_stripe_mode_label( $mode ),
			'is_live'     => 'live' === $mode,
			'has_issues'  => ! empty( $issues ),
			'issues'      => $issues,
		);
	}
}

if ( ! function_exists( 'ajcore_get_shared_db_settings' ) ) {
	/**
	 * Returns effective shared-DB settings, with wp-config.php constants taking priority over saved options.
	 *
	 * Supported constants (all optional):
	 *   AJCORE_SHARED_DB_ENABLED        (bool)
	 *   AJCORE_SHARED_DB_HOST           (string)
	 *   AJCORE_SHARED_DB_NAME           (string)
	 *   AJCORE_SHARED_DB_USER           (string)
	 *   AJCORE_SHARED_DB_PASSWORD       (string)
	 *   AJCORE_SHARED_DB_PREFIX         (string)
	 *   AJCORE_MULTISITE_PORTAL_ENABLED (bool)
	 */
	function ajcore_get_shared_db_settings() {
		$saved = get_option( 'ajcore_shared_db_settings', array() );
		$saved = is_array( $saved ) ? $saved : array();

		$s = wp_parse_args(
			$saved,
			array(
				'enabled'    => false,
				'host'       => '',
				'name'       => '',
				'user'       => '',
				'password'   => '',
				'prefix'     => 'wp_',
				'ms_enabled' => false,
				'site_client_portal_enabled' => true,
				'site_forms_enabled'         => true,
				'site_live_chat_enabled'     => true,
			)
		);

		if ( defined( 'AJCORE_SHARED_DB_ENABLED' ) ) {
			$s['enabled'] = (bool) AJCORE_SHARED_DB_ENABLED;
		}
		if ( defined( 'AJCORE_SHARED_DB_HOST' ) ) {
			$s['host'] = (string) AJCORE_SHARED_DB_HOST;
		}
		if ( defined( 'AJCORE_SHARED_DB_NAME' ) ) {
			$s['name'] = (string) AJCORE_SHARED_DB_NAME;
		}
		if ( defined( 'AJCORE_SHARED_DB_USER' ) ) {
			$s['user'] = (string) AJCORE_SHARED_DB_USER;
		}
		if ( defined( 'AJCORE_SHARED_DB_PASSWORD' ) ) {
			$s['password'] = (string) AJCORE_SHARED_DB_PASSWORD;
		}
		if ( defined( 'AJCORE_SHARED_DB_PREFIX' ) ) {
			$s['prefix'] = (string) AJCORE_SHARED_DB_PREFIX;
		}
		if ( defined( 'AJCORE_MULTISITE_PORTAL_ENABLED' ) ) {
			$s['ms_enabled'] = (bool) AJCORE_MULTISITE_PORTAL_ENABLED;
		}

		return $s;
	}
}

if ( ! function_exists( 'ajcore_get_site_features' ) ) {
	/**
	 * Returns the capabilities enabled for this AJCore installation.
	 *
	 * These switches are deliberately local even when the database is shared. Existing
	 * installations default to the full AJCore feature set until an administrator opts out.
	 */
	function ajcore_get_site_features() {
		$settings = ajcore_get_shared_db_settings();

		return array(
			'client_portal' => ! array_key_exists( 'site_client_portal_enabled', $settings ) || ! empty( $settings['site_client_portal_enabled'] ),
			'forms'         => ! array_key_exists( 'site_forms_enabled', $settings ) || ! empty( $settings['site_forms_enabled'] ),
			'live_chat'     => ! array_key_exists( 'site_live_chat_enabled', $settings ) || ! empty( $settings['site_live_chat_enabled'] ),
		);
	}
}

if ( ! function_exists( 'ajcore_is_site_feature_enabled' ) ) {
	function ajcore_is_site_feature_enabled( $feature ) {
		$features = ajcore_get_site_features();
		return isset( $features[ $feature ] ) && $features[ $feature ];
	}
}

if ( ! function_exists( 'ajcore_is_shared_db_enabled' ) ) {
	function ajcore_is_shared_db_enabled() {
		$s = ajcore_get_shared_db_settings();
		return ! empty( $s['enabled'] );
	}
}

if ( ! function_exists( 'ajcore_is_multisite_portal_enabled' ) ) {
	function ajcore_is_multisite_portal_enabled() {
		$s = ajcore_get_shared_db_settings();
		return ! empty( $s['ms_enabled'] ) && ajcore_is_shared_db_enabled();
	}
}

if ( ! function_exists( 'ajcore_get_portal_db' ) ) {
	/**
	 * Returns the wpdb instance for portal (shared) tables.
	 * In multi-site mode with a live shared DB connection: returns the shared DB.
	 * Otherwise: returns the global $wpdb (local database).
	 */
	function ajcore_get_portal_db() {
		if ( ajcore_is_multisite_portal_enabled() ) {
			$shared = ajcore_get_shared_db();
			if ( $shared ) {
				return $shared;
			}
		}
		global $wpdb;
		return $wpdb;
	}
}

if ( ! function_exists( 'ajcore_get_leads_db' ) ) {
	/**
	 * Forms and leads can participate in the shared AJCore network independently
	 * of the Client Portal. Fall back to the local database if the shared
	 * connection is disabled or unavailable.
	 */
	function ajcore_get_leads_db() {
		if ( ajcore_is_shared_db_enabled() && ajcore_is_site_feature_enabled( 'forms' ) ) {
			$shared = ajcore_get_shared_db();
			if ( $shared ) {
				return $shared;
			}
		}
		global $wpdb;
		return $wpdb;
	}
}

if ( ! function_exists( 'ajcore_is_stripe_sync_owner' ) ) {
	/**
	 * Returns true when this site may run Stripe sync/cron/webhook.
	 * Always true for local-only installs (shared DB disabled).
	 * When shared DB is enabled, reads is_master from the aj_shared_sites control table.
	 */
	function ajcore_is_stripe_sync_owner() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		if ( ! ajcore_is_shared_db_enabled() ) {
			return $cached = true;
		}

		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			// A configured shared site must never gain master authority merely because its
			// connection is temporarily unavailable. Fail closed so Stripe jobs and
			// master-owned settings cannot silently run from a secondary installation.
			return $cached = false;
		}

		$uuid = (string) get_option( 'ajcore_site_uuid', '' );
		if ( '' === $uuid ) {
			return $cached = false;
		}

		$table = $shared_db->prefix . 'aj_shared_sites';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $cached = true; // Control table not yet created — schema not initialized yet.
		}

		$is_master = $shared_db->get_var(
			$shared_db->prepare( "SELECT is_master FROM `{$table}` WHERE site_uuid = %s LIMIT 1", $uuid )
		);

		return $cached = ( '1' === (string) $is_master );
	}
}

if ( ! function_exists( 'ajcore_register_site_in_shared_db' ) ) {
	/**
	 * Upserts this site's record in the shared aj_shared_sites control table.
	 * Silently returns if the shared DB is not connected or the table doesn't exist yet.
	 */
	function ajcore_register_site_in_shared_db() {
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return;
		}

		$uuid = (string) get_option( 'ajcore_site_uuid', '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( 'ajcore_site_uuid', $uuid, false );
		}

		$table = $shared_db->prefix . 'aj_shared_sites';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		if ( ! $shared_db->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'participation'" ) ) {
			$shared_db->query( "ALTER TABLE `{$table}` ADD COLUMN participation longtext NULL AFTER is_master" );
		}
		if ( ! $shared_db->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'ajcore_version'" ) ) {
			$shared_db->query( "ALTER TABLE `{$table}` ADD COLUMN ajcore_version varchar(20) NULL AFTER participation" );
		}
		if ( ! $shared_db->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'update_secret'" ) ) {
			$shared_db->query( "ALTER TABLE `{$table}` ADD COLUMN update_secret varchar(64) NULL AFTER ajcore_version" );
		}

		$features      = function_exists( 'ajcore_get_site_features' ) ? ajcore_get_site_features() : array();
		$participation = wp_json_encode(
			array(
				'client_portal' => ! empty( $features['client_portal'] ),
				'forms_leads'   => ! empty( $features['forms'] ),
				'live_chat'     => ! empty( $features['live_chat'] ),
			)
		);
		$domain   = (string) home_url( '/' );
		$existing = $shared_db->get_row(
			$shared_db->prepare( "SELECT id, update_secret FROM `{$table}` WHERE site_uuid = %s LIMIT 1", $uuid )
		);

		// Each site generates and stores its OWN secret the first time it registers — the Master
		// reads it straight out of this same shared-DB row (it already has direct DB access, no HTTP
		// round trip needed) to authenticate the remote-update request it sends this site later. No
		// new cross-site auth flow: it's the same "coordinate via the shared table" pattern already
		// used for participation/last_seen.
		$update_secret = $existing && ! empty( $existing->update_secret ) ? $existing->update_secret : wp_generate_password( 40, false );

		if ( $existing ) {
			$shared_db->update(
				$table,
				array(
					'domain'         => $domain,
					'participation'  => $participation,
					'ajcore_version' => AJCORE_VERSION,
					'update_secret'  => $update_secret,
					'last_seen'      => current_time( 'mysql' ),
				),
				array( 'site_uuid' => $uuid ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);
		} else {
			$shared_db->insert(
				$table,
				array(
					'site_uuid'      => $uuid,
					'domain'         => $domain,
					'is_master'      => 0,
					'participation'  => $participation,
					'ajcore_version' => AJCORE_VERSION,
					'update_secret'  => $update_secret,
					'last_seen'      => current_time( 'mysql' ),
					'registered_at'  => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}
}

if ( ! function_exists( 'ajcore_get_shared_db' ) ) {
	/**
	 * Returns a wpdb instance connected to the shared DB, or null if not enabled/configured.
	 * The returned connection uses the configured table prefix.
	 */
	function ajcore_get_shared_db() {
		static $shared_db_cache = null;
		static $shared_db_tried = false;

		if ( $shared_db_tried ) {
			return $shared_db_cache;
		}
		$shared_db_tried = true;

		$s = ajcore_get_shared_db_settings();
		if ( empty( $s['enabled'] ) || '' === $s['host'] || '' === $s['name'] || '' === $s['user'] || '' === $s['password'] ) {
			$GLOBALS['ajcore_shared_db_connection_error'] = __( 'Shared DB is enabled, but one or more connection fields are missing.', 'ajforms' );
			return null;
		}

		$db = new wpdb( $s['user'], $s['password'], $s['name'], $s['host'] );
		$db->suppress_errors( true );
		$db->show_errors = false;
		if ( ! empty( $s['prefix'] ) ) {
			$db->prefix       = $s['prefix'];
			$db->base_prefix  = $s['prefix'];
		}

		$result = $db->get_var( 'SELECT 1' );
		if ( '1' !== (string) $result && 'localhost' === strtolower( trim( (string) $s['host'] ) ) ) {
			// Match the Settings > Test Connection behavior. Some hosting PHP pools
			// cannot resolve the localhost Unix socket even though TCP MySQL works.
			$db = new wpdb( $s['user'], $s['password'], $s['name'], '127.0.0.1' );
			$db->suppress_errors( true );
			$db->show_errors = false;
			if ( ! empty( $s['prefix'] ) ) {
				$db->prefix      = $s['prefix'];
				$db->base_prefix = $s['prefix'];
			}
			$result = $db->get_var( 'SELECT 1' );
		}
		if ( '1' !== (string) $result ) {
			$GLOBALS['ajcore_shared_db_connection_error'] = ! empty( $db->last_error )
				? sanitize_text_field( (string) $db->last_error )
				: __( 'The configured Shared DB did not accept the connection.', 'ajforms' );
			return null;
		}

		$GLOBALS['ajcore_shared_db_connection_error'] = '';
		$shared_db_cache = $db;
		return $shared_db_cache;
	}
}

if ( ! function_exists( 'ajcore_get_shared_db_connection_error' ) ) {
	/**
	 * Returns the most recent shared connection failure without exposing credentials.
	 */
	function ajcore_get_shared_db_connection_error() {
		return sanitize_text_field( (string) ( $GLOBALS['ajcore_shared_db_connection_error'] ?? '' ) );
	}
}

if ( ! function_exists( 'ajcore_get_calendar_setting_keys' ) ) {
	function ajcore_get_calendar_setting_keys() {
		return array(
			'zoho_reservations_enabled',
			'zoho_default_timezone',
			'zoho_calendar_uid',
			'zoho_calendar_id',
			'zoho_calendar_embed_url',
			'zoho_resource_uid',
			'zoho_schedule_appointment_url',
			'zoho_resource_freebusy_url',
			'zoho_api_auth_mode',
			'zoho_client_id',
			'zoho_client_secret',
			'zoho_access_token',
			'zoho_refresh_token',
			'zoho_token_expires_at',
			'zoho_api_domain',
			'zoho_oauth_client_id',
			'zoho_oauth_client_secret',
			'zoho_oauth_api_domain',
			'zoho_api_token',
			'zoho_api_token_expires_at',
			'zoho_availability_failure_mode',
			'zoho_availability_source',
			'reservation_resource_name',
			'reservation_resource_key',
			'reservation_menu_label',
			'reservation_business_hours_label',
			'reservation_after_hours_label',
			'reservation_business_hours_rate',
			'reservation_after_hours_rate',
			'reservation_business_hours_price_id',
			'reservation_after_hours_price_id',
		);
	}
}

if ( ! function_exists( 'ajcore_read_shared_calendar_settings' ) ) {
	function ajcore_read_shared_calendar_settings() {
		static $cache     = null;
		static $cache_set = false;

		if ( $cache_set ) {
			return is_array( $cache ) ? $cache : array();
		}
		$cache_set = true;

		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return array();
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$value = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_calendar_settings' )
		);
		if ( null === $value || '' === (string) $value ) {
			return array();
		}
		$decoded = json_decode( (string) $value, true );
		$cache   = is_array( $decoded ) ? $decoded : array();
		return $cache;
	}
}

if ( ! function_exists( 'ajcore_write_shared_calendar_settings' ) ) {
	function ajcore_write_shared_calendar_settings( $settings ) {
		if ( ! ajcore_is_shared_db_enabled() ) {
			return false;
		}
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return false;
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		$data = array();
		foreach ( ajcore_get_calendar_setting_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$data[ $key ] = $settings[ $key ];
			}
		}
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}
		$existing = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_calendar_settings' )
		);
		if ( $existing ) {
			return false !== $shared_db->update(
				$table,
				array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
				array( 'setting_name'  => 'ajcore_calendar_settings' ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}
		return false !== $shared_db->insert(
			$table,
			array( 'setting_name' => 'ajcore_calendar_settings', 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
			array( '%s', '%s', '%s' )
		);
	}
}


if ( ! function_exists( 'ajcore_get_chat_setting_keys' ) ) {
	function ajcore_get_chat_setting_keys() {
		return array(
			'chat_server_url',
			'chat_notify_url',
			'chat_notify_secret',
			'chat_ws_token_secret',
			'chat_internal_secret',
			'chat_business_hours_enabled',
			'chat_business_hours',
			'chat_auto_close_hours',
			'chat_transcript_email_subject',
			'chat_transcript_email_heading',
			'chat_transcript_email_body',
		);
	}
}

if ( ! function_exists( 'ajcore_read_shared_chat_settings' ) ) {
	function ajcore_read_shared_chat_settings() {
		static $cache     = null;
		static $cache_set = false;

		if ( $cache_set ) {
			return is_array( $cache ) ? $cache : array();
		}
		$cache_set = true;

		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return array();
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$value = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_chat_settings' )
		);
		if ( null === $value || '' === (string) $value ) {
			return array();
		}
		$decoded = json_decode( (string) $value, true );
		$cache   = is_array( $decoded ) ? $decoded : array();
		return $cache;
	}
}

/**
 * Live Chat server URL/notify secret are shared across every connected site — the master's local
 * option is the source of truth, pushed here on every save, and secondary sites overlay it in
 * ajforms_get_settings(). chat_widget_enabled deliberately does NOT go through this (see the
 * settings-default comment above).
 */
if ( ! function_exists( 'ajcore_write_shared_chat_settings' ) ) {
	function ajcore_write_shared_chat_settings( $settings ) {
		if ( ! ajcore_is_shared_db_enabled() ) {
			return false;
		}
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return false;
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		$data = array();
		foreach ( ajcore_get_chat_setting_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$data[ $key ] = $settings[ $key ];
			}
		}
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}
		$existing = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_chat_settings' )
		);
		if ( $existing ) {
			return false !== $shared_db->update(
				$table,
				array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
				array( 'setting_name'  => 'ajcore_chat_settings' ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}
		return false !== $shared_db->insert(
			$table,
			array( 'setting_name' => 'ajcore_chat_settings', 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
			array( '%s', '%s', '%s' )
		);
	}
}

if ( ! function_exists( 'ajcore_get_rentec_setting_keys' ) ) {
	function ajcore_get_rentec_setting_keys() {
		return array(
			'rentec_enabled',
			'rentec_api_key',
			'rentec_account_label_1',
			'rentec_api_key_2',
			'rentec_account_label_2',
		);
	}
}

if ( ! function_exists( 'ajcore_read_shared_rentec_settings' ) ) {
	function ajcore_read_shared_rentec_settings() {
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return array();
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$value = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_rentec_settings' )
		);
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

if ( ! function_exists( 'ajcore_write_shared_rentec_settings' ) ) {
	function ajcore_write_shared_rentec_settings( $settings ) {
		if ( ! ajcore_is_shared_db_enabled() ) {
			return false;
		}
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return false;
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		$data = array();
		foreach ( ajcore_get_rentec_setting_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$value = $settings[ $key ];
				if ( in_array( $key, array( 'rentec_api_key', 'rentec_api_key_2' ), true ) && function_exists( 'ajcore_decrypt_setting_value' ) ) {
					$value = ajcore_decrypt_setting_value( $value );
				}
				$data[ $key ] = $value;
			}
		}
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}
		$existing = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_rentec_settings' )
		);
		if ( $existing ) {
			return false !== $shared_db->update(
				$table,
				array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
				array( 'setting_name' => 'ajcore_rentec_settings' ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}
		return false !== $shared_db->insert(
			$table,
			array( 'setting_name' => 'ajcore_rentec_settings', 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
			array( '%s', '%s', '%s' )
		);
	}
}

if ( ! function_exists( 'ajcore_get_gmail_intake_setting_keys' ) ) {
	function ajcore_get_gmail_intake_setting_keys() {
		return array(
			'gmail_intake_client_id',
			'gmail_intake_client_secret',
			'gmail_intake_address',
			'gmail_intake_access_token',
			'gmail_intake_refresh_token',
			'gmail_intake_token_expires_at',
			'gmail_intake_connected_email',
			'gmail_intake_connected_at',
			'gmail_intake_label_id',
		);
	}
}

if ( ! function_exists( 'ajcore_read_shared_gmail_intake_settings' ) ) {
	function ajcore_read_shared_gmail_intake_settings() {
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return array();
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$value = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_gmail_intake_settings' )
		);
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

if ( ! function_exists( 'ajcore_write_shared_gmail_intake_settings' ) ) {
	function ajcore_write_shared_gmail_intake_settings( $settings ) {
		if ( ! ajcore_is_shared_db_enabled() ) {
			return false;
		}
		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return false;
		}
		$table = $shared_db->prefix . 'aj_shared_settings';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		$data = array();
		foreach ( ajcore_get_gmail_intake_setting_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				// None of these fields are on ajcore_get_secret_setting_keys() today (Client
				// Secret and the OAuth tokens are stored plaintext locally), so no decrypt step
				// is needed here — unlike ajcore_write_shared_rentec_settings(). If that ever
				// changes, mirror the ajcore_decrypt_setting_value() call used there.
				$data[ $key ] = $settings[ $key ];
			}
		}
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return false;
		}
		$existing = $shared_db->get_var(
			$shared_db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_gmail_intake_settings' )
		);
		if ( $existing ) {
			return false !== $shared_db->update(
				$table,
				array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
				array( 'setting_name' => 'ajcore_gmail_intake_settings' ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		}
		return false !== $shared_db->insert(
			$table,
			array( 'setting_name' => 'ajcore_gmail_intake_settings', 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) ),
			array( '%s', '%s', '%s' )
		);
	}
}

/**
 * Master → shared DB sync: whenever the master site's settings option changes
 * (admin save, token refresh, migrations), push the calendar/reservation subset
 * to the shared DB so secondary sites always overlay current values.
 */
add_action(
	'update_option_ajforms_settings',
	function ( $old_value, $value ) {
		if ( ! is_array( $value ) || ! function_exists( 'ajcore_write_shared_calendar_settings' ) || ! ajcore_is_shared_db_enabled() ) {
			return;
		}
		if ( function_exists( 'ajcore_is_stripe_sync_owner' ) && ! ajcore_is_stripe_sync_owner() ) {
			return;
		}
		ajcore_write_shared_calendar_settings( $value );
		if ( function_exists( 'ajcore_write_shared_chat_settings' ) ) {
			ajcore_write_shared_chat_settings( $value );
		}
		if ( function_exists( 'ajcore_write_shared_rentec_settings' ) ) {
			ajcore_write_shared_rentec_settings( $value );
		}
		if ( function_exists( 'ajcore_write_shared_gmail_intake_settings' ) ) {
			ajcore_write_shared_gmail_intake_settings( $value );
		}
	},
	10,
	2
);

// Existing installations already have Rentec keys in the master's local option. Publish that
// configuration once when the shared row does not yet exist, so secondary sites inherit it
// immediately after upgrading without requiring the administrator to re-save any secrets.
add_action(
	'admin_init',
	function () {
		if ( ! ajcore_is_shared_db_enabled()
			|| ( function_exists( 'ajcore_is_stripe_sync_owner' ) && ! ajcore_is_stripe_sync_owner() )
			|| ! function_exists( 'ajcore_read_shared_rentec_settings' )
			|| ! function_exists( 'ajcore_write_shared_rentec_settings' )
			|| ! empty( ajcore_read_shared_rentec_settings() ) ) {
			return;
		}
		ajcore_write_shared_rentec_settings( ajforms_get_settings() );
	},
	20
);

// Same one-time backfill for Gmail Intake: ncllc already has a live OAuth connection saved
// locally — publish it to the shared DB once so upos (and any other secondary site) picks it
// up immediately, without the admin needing to reconnect Gmail on every site separately.
add_action(
	'admin_init',
	function () {
		if ( ! ajcore_is_shared_db_enabled()
			|| ( function_exists( 'ajcore_is_stripe_sync_owner' ) && ! ajcore_is_stripe_sync_owner() )
			|| ! function_exists( 'ajcore_read_shared_gmail_intake_settings' )
			|| ! function_exists( 'ajcore_write_shared_gmail_intake_settings' )
			|| ! empty( ajcore_read_shared_gmail_intake_settings() ) ) {
			return;
		}
		ajcore_write_shared_gmail_intake_settings( ajforms_get_settings() );
	},
	20
);

if ( ! function_exists( 'ajcore_get_portal_file_settings' ) ) {
	function ajcore_get_portal_file_settings() {
		$defaults = array(
			'categories'     => array( 'Articles of Organization', 'IRS EIN Letters', 'SOSNC Flyers', 'Change of RA', 'Others' ),
			'tags'           => array( 'registered-agent' => 'RegisteredAgent', 'virtual-office' => 'VirtualOffice' ),
			'migration_tags' => array( 'registered-agent', 'virtual-office' ),
		);
		$saved = array();
		if ( ajcore_is_shared_db_enabled() ) {
			$db = ajcore_get_shared_db();
			if ( $db ) {
				$table = $db->prefix . 'aj_shared_settings';
				$value = $db->get_var( $db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_portal_file_settings' ) );
				$decoded = json_decode( (string) $value, true );
				$saved = is_array( $decoded ) ? $decoded : array();
			}
		} else {
			$saved = get_option( 'ajcore_portal_file_settings', array() );
		}
		$saved = is_array( $saved ) ? $saved : array();
		$settings = wp_parse_args( $saved, $defaults );
		$settings['categories'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $settings['categories'] ) ) );
		$settings['tags'] = is_array( $settings['tags'] ) ? $settings['tags'] : $defaults['tags'];
		$settings['migration_tags'] = array_values( array_intersect( (array) $settings['migration_tags'], array_keys( $settings['tags'] ) ) );
		return $settings;
	}
}

if ( ! function_exists( 'ajcore_update_portal_file_settings' ) ) {
	function ajcore_update_portal_file_settings( $settings ) {
		if ( ajcore_is_shared_db_enabled() ) {
			$db = ajcore_get_shared_db();
			if ( ! $db ) {
				return false;
			}
			$table = $db->prefix . 'aj_shared_settings';
			$encoded = wp_json_encode( $settings );
			$exists = $db->get_var( $db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_portal_file_settings' ) );
			$data = array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) );
			if ( $exists ) {
				return false !== $db->update( $table, $data, array( 'setting_name' => 'ajcore_portal_file_settings' ), array( '%s', '%s' ), array( '%s' ) );
			}
			$data['setting_name'] = 'ajcore_portal_file_settings';
			return false !== $db->insert( $table, $data, array( '%s', '%s', '%s' ) );
		}
		return update_option( 'ajcore_portal_file_settings', $settings, false );
	}
}

// Staff-authored "predefined banners" (Live Monitor's Visitor History question 3) — pushed live to
// a specific visitor's page over their presence WebSocket. Same aj_shared_settings storage pattern
// as ajcore_get/update_portal_file_settings() above: one JSON blob under its own setting_name.
if ( ! function_exists( 'ajcore_get_visitor_banner_templates' ) ) {
	function ajcore_get_visitor_banner_templates() {
		$saved = array();
		if ( ajcore_is_shared_db_enabled() ) {
			$db = ajcore_get_shared_db();
			if ( $db ) {
				$table = $db->prefix . 'aj_shared_settings';
				$value = $db->get_var( $db->prepare( "SELECT setting_value FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_visitor_banner_templates' ) );
				$decoded = json_decode( (string) $value, true );
				$saved = is_array( $decoded ) ? $decoded : array();
			}
		} else {
			$saved = get_option( 'ajcore_visitor_banner_templates', array() );
		}
		return is_array( $saved ) ? array_values( $saved ) : array();
	}
}

if ( ! function_exists( 'ajcore_update_visitor_banner_templates' ) ) {
	function ajcore_update_visitor_banner_templates( $templates ) {
		$templates = is_array( $templates ) ? array_values( $templates ) : array();
		if ( ajcore_is_shared_db_enabled() ) {
			$db = ajcore_get_shared_db();
			if ( ! $db ) {
				return false;
			}
			$table   = $db->prefix . 'aj_shared_settings';
			$encoded = wp_json_encode( $templates );
			$exists  = $db->get_var( $db->prepare( "SELECT setting_name FROM `{$table}` WHERE setting_name = %s LIMIT 1", 'ajcore_visitor_banner_templates' ) );
			$data    = array( 'setting_value' => $encoded, 'updated_at' => current_time( 'mysql' ) );
			if ( $exists ) {
				return false !== $db->update( $table, $data, array( 'setting_name' => 'ajcore_visitor_banner_templates' ), array( '%s', '%s' ), array( '%s' ) );
			}
			$data['setting_name'] = 'ajcore_visitor_banner_templates';
			return false !== $db->insert( $table, $data, array( '%s', '%s', '%s' ) );
		}
		return update_option( 'ajcore_visitor_banner_templates', $templates, false );
	}
}

// "Change of RA" was added to the default category list above, but an install that already has
// a saved ajcore_portal_file_settings row (shared or local) never sees new defaults — wp_parse_args()
// only fills in KEYS that are missing entirely, not new list entries inside an existing 'categories'
// value. Backfill it once so the Gmail Intake filing dropdown (and its category validation in
// handle_portal_gmail_intake_actions()) actually accepts it without every site needing a manual
// re-save. Runs on every site (not gated to the shared-DB master) since local-only installs need
// the same backfill against their own local option.
add_action(
	'admin_init',
	function () {
		if ( ! function_exists( 'ajcore_get_portal_file_settings' ) || ! function_exists( 'ajcore_update_portal_file_settings' ) ) {
			return;
		}
		$settings = ajcore_get_portal_file_settings();
		if ( in_array( 'Change of RA', (array) $settings['categories'], true ) ) {
			return;
		}
		// Goes in ahead of the catch-all "Others" bucket it used to fall into, alongside the other
		// per-document-type categories rather than at the very end.
		$others_pos = array_search( 'Others', $settings['categories'], true );
		if ( false === $others_pos ) {
			$settings['categories'][] = 'Change of RA';
		} else {
			array_splice( $settings['categories'], $others_pos, 0, array( 'Change of RA' ) );
		}
		ajcore_update_portal_file_settings( $settings );
	},
	20
);

/**
 * The code that runs during plugin activation.
 */
function activate_ajforms() {
	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
	AJForms_Activator::activate();
}
register_activation_hook( __FILE__, 'activate_ajforms' );

/**
 * Ensure custom tables exist after regular plugin updates.
 *
 * WordPress does not run activation hooks when a plugin is updated from a zip
 * or GitHub release, so table creation must also be checked at runtime.
 */
function ajforms_maybe_upgrade() {
	$installed_version = get_option( 'ajforms_version', '' );
	$portal_schema_version = get_option( 'ajforms_portal_schema_version', '' );

	// Both guards must be bumped together whenever a schema-affecting change is made (new
	// column/table in class-ajforms-activator.php) — if AJCORE_VERSION isn't bumped too, a
	// re-deploy that keeps the same plugin version number will match this check and skip the
	// migration entirely, silently leaving the new column/table missing on already-migrated
	// installs (schema drift that showed up in production as leads queries erroring out).
	if ( AJFORMS_VERSION === $installed_version && '43' === $portal_schema_version ) {
		return;
	}

	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
	AJForms_Activator::activate();
	update_option( 'ajforms_version', AJFORMS_VERSION, false );
}
add_action( 'plugins_loaded', 'ajforms_maybe_upgrade', 5 );

/**
 * Force IPv4 for Stripe API calls. On hosts with broken IPv6 routing, cURL tries IPv6 first and
 * stalls for several seconds before falling back — multiplied across the sequential Stripe calls
 * an invoice or subscription creation makes, that turns a ~2s operation into ~30s.
 */
add_action(
	'http_api_curl',
	function ( $handle, $parsed_args = array(), $url = '' ) {
		if ( is_string( $url ) && false !== strpos( $url, 'api.stripe.com' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	},
	10,
	3
);

/**
 * Login tracking: stores the user's last login time in user meta and records
 * every login (WP form + AJ Ops API) in the portal event log.
 */
if ( ! function_exists( 'ajcore_record_user_login' ) ) {
	function ajcore_record_user_login( $user, $source = 'wp_login' ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}
		update_user_meta( $user->ID, 'ajcore_last_login', current_time( 'mysql' ) );

		$pdb   = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];
		$table = $pdb->prefix . 'aj_portal_event_log';
		if ( $pdb->get_var( $pdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$pdb->insert(
				$table,
				array(
					'event_type'    => 'user_login',
					'severity'      => 'info',
					'source'        => sanitize_key( (string) $source ),
					'site_uuid'     => (string) get_option( 'ajcore_site_uuid', '' ),
					'actor_user_id' => (int) $user->ID,
					'actor_email'   => (string) $user->user_email,
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}
	add_action(
		'wp_login',
		function ( $user_login, $user ) {
			ajcore_record_user_login( $user, 'wp_login' );
		},
		10,
		2
	);
}

/**
 * Outgoing email log: every wp_mail() call is recorded in aj_portal_email_log
 * (local table) so staff can audit what was sent from both AJ Core and WordPress.
 */
if ( ! function_exists( 'ajcore_email_log_table_exists' ) ) {
	function ajcore_email_log_table_exists() {
		static $exists = null;
		if ( null === $exists ) {
			global $wpdb;
			$table  = $wpdb->prefix . 'aj_portal_email_log';
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return $exists;
	}
}

if ( ! function_exists( 'ajcore_log_outgoing_mail' ) ) {
	function ajcore_log_outgoing_mail( $atts ) {
		if ( is_array( $atts ) && ajcore_email_log_table_exists() ) {
			global $wpdb;
			$to      = isset( $atts['to'] ) ? $atts['to'] : '';
			$to      = is_array( $to ) ? implode( ', ', array_map( 'sanitize_text_field', $to ) ) : sanitize_text_field( (string) $to );
			$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
			$headers = is_array( $headers ) ? implode( "\n", array_map( 'sanitize_text_field', $headers ) ) : sanitize_text_field( (string) $headers );
			$wpdb->insert(
				$wpdb->prefix . 'aj_portal_email_log',
				array(
					'to_email'   => substr( $to, 0, 190 ),
					'subject'    => substr( sanitize_text_field( (string) ( isset( $atts['subject'] ) ? $atts['subject'] : '' ) ), 0, 255 ),
					'headers'    => $headers,
					'message'    => (string) ( isset( $atts['message'] ) ? $atts['message'] : '' ),
					'status'     => 'sent',
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
		return $atts;
	}
	add_filter( 'wp_mail', 'ajcore_log_outgoing_mail', 999 );
}

if ( ! function_exists( 'ajcore_log_outgoing_mail_failed' ) ) {
	function ajcore_log_outgoing_mail_failed( $error ) {
		if ( ! is_wp_error( $error ) || ! ajcore_email_log_table_exists() ) {
			return;
		}
		global $wpdb;
		$data = $error->get_error_data( 'wp_mail_failed' );
		$data = is_array( $data ) ? $data : array();
		$to   = isset( $data['to'] ) ? $data['to'] : '';
		$to   = is_array( $to ) ? implode( ', ', array_map( 'sanitize_text_field', $to ) ) : sanitize_text_field( (string) $to );
		$wpdb->insert(
			$wpdb->prefix . 'aj_portal_email_log',
			array(
				'to_email'      => substr( $to, 0, 190 ),
				'subject'       => substr( sanitize_text_field( (string) ( isset( $data['subject'] ) ? $data['subject'] : '' ) ), 0, 255 ),
				'headers'       => '',
				'message'       => '',
				'status'        => 'failed',
				'error_message' => sanitize_text_field( $error->get_error_message() ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
	add_action( 'wp_mail_failed', 'ajcore_log_outgoing_mail_failed' );
}

/**
 * Renders the self-hosted Live Chat widget on the front end of this site, when enabled locally
 * (see the "Live Chat" CP Settings section — chat_widget_enabled is deliberately per-site, not
 * shared, so the widget can be rolled out to one site at a time). Vanilla JS, no framework, to
 * keep the visitor-facing payload tiny; connects directly to the AJOps chat server's WebSocket
 * endpoint configured in chat_server_url.
 */
function ajcore_render_chat_widget() {
	if ( is_admin() ) {
		return;
	}
	$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
	if ( '1' !== (string) ( $settings['chat_widget_enabled'] ?? '' ) ) {
		return;
	}
	$server_url = untrailingslashit( trim( (string) ( $settings['chat_server_url'] ?? '' ) ) );
	$site_uuid  = (string) get_option( 'ajcore_site_uuid', '' );
	if ( '' === $server_url || '' === $site_uuid ) {
		return;
	}
	?>
	<script>
		window.AJCoreChatConfig = {
			serverUrl: <?php echo wp_json_encode( $server_url ); ?>,
			siteUuid: <?php echo wp_json_encode( $site_uuid ); ?>,
			businessHoursEnabled: <?php echo wp_json_encode( '1' === (string) ( $settings['chat_business_hours_enabled'] ?? '' ) ); ?>,
			businessHours: <?php echo wp_json_encode( (string) ( $settings['chat_business_hours'] ?? '' ) ); ?>,
			engagePopupEnabled: <?php echo wp_json_encode( '1' === (string) ( $settings['chat_engage_popup_enabled'] ?? '1' ) ); ?>,
			engagePopupDelayMs: <?php echo wp_json_encode( max( 0, absint( $settings['chat_engage_popup_delay_seconds'] ?? 25 ) ) * 1000 ); ?>,
			identifyEnabled: <?php echo wp_json_encode( '1' === (string) ( $settings['visitor_identify_enabled'] ?? '' ) ); ?>,
			identifyDelayMs: <?php echo wp_json_encode( max( 0, absint( $settings['visitor_identify_delay_seconds'] ?? 55 ) ) * 1000 ); ?>,
			timerEnabled: <?php echo wp_json_encode( '1' === (string) ( $settings['visitor_timer_enabled'] ?? '' ) ); ?>
		};
	</script>
	<script src="<?php echo esc_url( AJFORMS_PLUGIN_URL . 'assets/js/ajcore-chat-widget.js' ); ?>?v=<?php echo esc_attr( AJFORMS_VERSION ); ?>" defer></script>
	<?php
}
add_action( 'wp_footer', 'ajcore_render_chat_widget' );

/**
 * Ambient "new chat" notification (chime + floating bubble) for staff anywhere in WP-admin, not
 * just while the Live Chat tab is open — WP-admin has no persistent connection like AJOps' single
 * WebSocket, so this polls GET /ops/chat/sessions on an interval instead and compares the newest
 * last_message_at it's seen (persisted in localStorage, so it survives page navigations) against
 * what comes back each time.
 */
function ajcore_render_chat_admin_notifications() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$live_chat_url = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'chat' ), admin_url( 'admin.php' ) );
	?>
	<script>
		window.AJCoreChatAdminNotifyConfig = {
			restUrl: <?php echo wp_json_encode( rest_url( 'ajcore/v1/ops/chat/sessions' ) ); ?>,
			nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
			liveChatUrl: <?php echo wp_json_encode( $live_chat_url ); ?>
		};
	</script>
	<script src="<?php echo esc_url( AJFORMS_PLUGIN_URL . 'assets/js/ajcore-chat-admin-notify.js' ); ?>?v=<?php echo esc_attr( AJFORMS_VERSION ); ?>" defer></script>
	<?php
}
add_action( 'admin_footer', 'ajcore_render_chat_admin_notifications' );

/**
 * Begins execution of the plugin.
 */
function run_ajforms() {
	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms.php';
	require_once AJCORE_PLUGIN_DIR . 'modules/storage/class-ajcore-storage-service.php';

	$plugin = new AJForms();
	$plugin->run();

	AJCore_Storage_Service::instance();
}

run_ajforms();
