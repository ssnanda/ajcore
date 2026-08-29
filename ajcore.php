<?php
/**
 * Plugin Name:       AJ Core
 * Plugin URI:        https://github.com/ssnanda/ajcore
 * Description:       A modular WordPress business toolkit for forms, payments, portals, auth, CRM, and automations.
 * Version: 0.7.278
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
	define( 'AJCORE_VERSION', '0.7.278' );
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
	define( 'AJCORE_SYSTEM_FROM_EMAIL', function_exists( 'get_option' ) ? get_option( 'admin_email' ) : '' );
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
			// Per-site opt-in for the University Place Office Suites brand variant in the Email
			// Templates admin UI (see $brands in display_email_templates_settings_section() and the
			// preserve-on-save loop in handle_settings_save(), both in class-ajforms-admin.php). Off
			// by default so the other 10 sites this plugin ships to don't see a different business's
			// branded fields; ncllc/upos turn it on locally since their lead/customer brand-switch
			// (get_customer_brand_setting_key()) actually uses the university_* settings below.
			'enable_university_brand_templates' => '0',
			'wp_email_from_email'           => defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? AJCORE_SYSTEM_FROM_EMAIL : get_option( 'admin_email' ),
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
			// University Place Office Suites brand overrides (see get_customer_brand_setting_key()
			// in class-ajforms-admin.php) — only take effect when a customer/lead's domain resolves
			// to universityofficesuites.com. Only shown in the Email Templates admin UI when
			// enable_university_brand_templates is on (see above); otherwise these stay real,
			// preserved settings that brand-switch email sending still reads normally.
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
			'lead_followup_from_email'      => '',
			'lead_followup_from_name'       => '',
			// Zoho Mail shared-inbox OAuth app (Inbox settings). client_id/secret/account_email/
			// data_center are admin-entered; the rest are written only by the OAuth callback itself.
			'zoho_mail_client_id'           => '',
			'zoho_mail_client_secret'       => '',
			'zoho_mail_account_email'       => '',
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
			// Content Filtering (Spam Protection) — checked server-side at submission time,
			// applies automatically to every form's free-text fields (and the blocked-domains
			// list to every Email field), same "no per-form setup needed" reasoning as honeypot.
			'content_filter_block_non_latin'        => '0',
			'content_filter_block_links'            => '0',
			'content_filter_blocked_email_domains'  => '',
			'spam_challenge_provider'       => 'turnstile',
			'recaptcha_site_key'            => '',
			'recaptcha_secret_key'          => '',
			'hcaptcha_site_key'             => '',
			'hcaptcha_secret_key'           => '',
			'turnstile_site_key'            => '',
			'turnstile_secret_key'          => '',
			// Cloudflare IP blocking (Spam Protection -> "Mark Spam" in AJOps). One account-level
			// List ("ajcore_spam_list") plus one zone-level WAF Custom Rule blocking it — see
			// ajcore_cloudflare_block_ip() — so blocking any number of spam IPs over time never
			// creates more than one rule. Needs a token with Account > Account Filter Lists: Edit
			// and Zone > WAF: Edit. account_id/zone_id aren't secrets (both visible in the
			// Cloudflare dashboard) but travel with the token since none of the three works alone.
			'cloudflare_api_token'          => '',
			'cloudflare_account_id'         => '',
			'cloudflare_zone_id'            => '',
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
			'cloudflare_api_token',
		);
	}
}

if ( ! function_exists( 'ajcore_get_settings_encryption_key' ) ) {
	/**
	 * A dedicated, DB-persisted key for encrypting settings secrets — deliberately NOT derived
	 * from wp_salt('auth')/AUTH_KEY (see the "ajenc1:" legacy scheme below for why that was a
	 * mistake). wp-config.php's salts can change for reasons entirely outside this plugin's
	 * control — a security-hardening plugin rotating them on a schedule, a hosting panel
	 * regenerating wp-config.php, a migration that doesn't carry the file over — and every secret
	 * encrypted under the old salt becomes permanently undecryptable the moment that happens,
	 * silently reading back as an empty string. From the settings screen that looks exactly like
	 * "the key got removed", even though the (now-unusable) ciphertext is still sitting untouched
	 * in the database. Generated once, stored in wp_options (autoload=false — read only during
	 * encrypt/decrypt, not on every page load), and never regenerated afterward, so it survives
	 * wp-config.php changes/redeploys/salt rotation entirely.
	 */
	function ajcore_get_settings_encryption_key() {
		// BUG FIX (was silently rotating the key on every single call — see the "critical" note in
		// git history/PR discussion): this used to gate reuse of the stored key behind
		// function_exists('sodium_crypto_secretbox_keybytes'), but that's the name of a CONSTANT
		// (SODIUM_CRYPTO_SECRETBOX_KEYBYTES) in PHP's native sodium extension, not a callable
		// function — function_exists() on it returns false on a standard install, so the "reuse the
		// stored key" branch below never ran. Every encrypt/decrypt call fell through to generating
		// and persisting a brand-new random key, overwriting the one anything previously encrypted
		// actually needs — i.e. the exact "silently undecryptable" failure this whole ajenc2: scheme
		// was built to prevent, just via a different mechanism. function_exists('sodium_crypto_secretbox')
		// (the actual function used below and elsewhere in this file) is the right guard for "is
		// libsodium available", and the key is validated on its own merits (decodes, right length).
		$stored = get_option( 'ajcore_settings_encryption_key', '' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$decoded = base64_decode( $stored, true );
			if ( false !== $decoded && function_exists( 'sodium_crypto_secretbox' ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $decoded ) ) {
				return $decoded;
			}
		}
		$key = function_exists( 'random_bytes' ) ? random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) : hash( 'sha256', wp_salt( 'auth' ) . microtime(), true );
		update_option( 'ajcore_settings_encryption_key', base64_encode( $key ), false );
		return $key;
	}
}

if ( ! function_exists( 'ajcore_encrypt_setting_value' ) ) {
	/**
	 * Encrypt one settings value with the dedicated DB-persisted key above.
	 * Format: "ajenc2:" + base64( nonce + secretbox ). Values that are empty or already on this
	 * scheme pass through unchanged, so the transform is idempotent. A value still on the old
	 * "ajenc1:" (wp_salt-keyed) scheme gets opportunistically decrypted and re-encrypted under
	 * ajenc2: the next time it's saved — see ajcore_decrypt_setting_value()'s legacy branch.
	 */
	function ajcore_encrypt_setting_value( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, 'ajenc2:' ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, 'ajenc1:' ) ) {
			$value = ajcore_decrypt_setting_value( $value );
			if ( '' === $value ) {
				return ''; // Already unrecoverable under the old scheme — nothing to carry forward.
			}
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $value;
		}
		$key   = ajcore_get_settings_encryption_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'ajenc2:' . base64_encode( $nonce . sodium_crypto_secretbox( $value, $nonce, $key ) );
	}
}

if ( ! function_exists( 'ajcore_decrypt_setting_value' ) ) {
	function ajcore_decrypt_setting_value( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( 0 === strpos( $value, 'ajenc2:' ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $value, 7 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$key   = ajcore_get_settings_encryption_key();
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $value, 'ajenc1:' ) ) {
			// Legacy scheme, keyed off wp_salt('auth') — this is the one that made secrets go
			// undecryptable whenever the site's salts changed. Kept only so a value still on this
			// scheme decrypts one more time (IF the salt hasn't already rotated away from what
			// encrypted it) so it can be transparently upgraded to ajenc2: on next save; see
			// ajcore_encrypt_setting_value() above.
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
		return $value; // Plaintext (pre-migration) passes through.
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

if ( ! function_exists( 'ajcore_cloudflare_api_request' ) ) {
	/**
	 * Thin wrapper around wp_remote_request for Cloudflare's v4 API — every ajcore_cloudflare_*
	 * helper below goes through this so auth headers/timeout/JSON-decoding stay in one place.
	 *
	 * @return array{success:bool,body:array,status:int}|WP_Error
	 */
	function ajcore_cloudflare_api_request( $method, $url, $api_token, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => is_array( $decoded ) && ! empty( $decoded['success'] ),
			'body'    => is_array( $decoded ) ? $decoded : array(),
			'status'  => $status,
		);
	}
}

if ( ! function_exists( 'ajcore_cloudflare_api_error_message' ) ) {
	function ajcore_cloudflare_api_error_message( $body, $status ) {
		$errors = ( is_array( $body ) && ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) ? $body['errors'] : array();
		if ( ! empty( $errors[0]['message'] ) ) {
			return (string) $errors[0]['message'];
		}
		return sprintf( /* translators: %d: HTTP status code */ __( 'Cloudflare request failed with HTTP status %d.', 'ajforms' ), $status );
	}
}

if ( ! function_exists( 'AJCORE_CLOUDFLARE_LIST_NAME' ) ) {
	// Cloudflare list names: lowercase letters/digits/underscores only, no hyphens or spaces.
	define( 'AJCORE_CLOUDFLARE_LIST_NAME', 'ajcore_spam_list' );
	define( 'AJCORE_CLOUDFLARE_RULE_DESCRIPTION', 'AJCore-Spam-List' );
}

if ( ! function_exists( 'ajcore_cloudflare_get_settings' ) ) {
	/**
	 * Pulls + validates the three Cloudflare fields every helper below needs. Centralized so the
	 * "not configured yet" error message is worded identically everywhere it can surface.
	 *
	 * @return array{api_token:string,account_id:string,zone_id:string}|WP_Error
	 */
	function ajcore_cloudflare_get_settings() {
		$settings   = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$api_token  = ! empty( $settings['cloudflare_api_token'] ) ? trim( (string) $settings['cloudflare_api_token'] ) : '';
		$account_id = ! empty( $settings['cloudflare_account_id'] ) ? trim( (string) $settings['cloudflare_account_id'] ) : '';
		$zone_id    = ! empty( $settings['cloudflare_zone_id'] ) ? trim( (string) $settings['cloudflare_zone_id'] ) : '';

		if ( '' === $api_token || '' === $account_id || '' === $zone_id ) {
			return new WP_Error( 'ajcore_cloudflare_not_configured', __( 'Cloudflare API Token, Account ID, and Zone ID must all be set on the Spam Protection screen first.', 'ajforms' ) );
		}

		return array( 'api_token' => $api_token, 'account_id' => $account_id, 'zone_id' => $zone_id );
	}
}

if ( ! function_exists( 'ajcore_cloudflare_get_or_create_list' ) ) {
	/**
	 * Finds the account's "ajcore_spam_list" IP list, creating it if this is the first time
	 * anything has been blocked. Not cached across requests — this only runs when a lead is
	 * actually marked spam (a human-triggered, infrequent action) or Test Connection is clicked,
	 * so one fresh lookup per request is cheap and immune to the list having been renamed/deleted
	 * by hand on Cloudflare's side since the last call. Callers doing multiple operations in one
	 * request (Test Connection's block-then-unblock round trip) should call this ONCE and reuse
	 * the id — see the $list_id override on ajcore_cloudflare_block_ip()/unblock_ip() — rather than
	 * let each operation re-resolve it, which is what previously made Test Connection slow enough
	 * to occasionally 502 at the origin (each redundant resolution is its own Cloudflare API call).
	 *
	 * @return array{id:string,created:bool}|WP_Error
	 */
	function ajcore_cloudflare_get_or_create_list( $account_id, $api_token ) {
		$list = ajcore_cloudflare_api_request(
			'GET',
			'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account_id ) . '/rules/lists',
			$api_token
		);
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		if ( $list['success'] ) {
			foreach ( (array) ( $list['body']['result'] ?? array() ) as $existing ) {
				if ( isset( $existing['name'] ) && AJCORE_CLOUDFLARE_LIST_NAME === $existing['name'] ) {
					return array( 'id' => (string) $existing['id'], 'created' => false );
				}
			}
		}

		$created = ajcore_cloudflare_api_request(
			'POST',
			'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account_id ) . '/rules/lists',
			$api_token,
			array(
				'name'        => AJCORE_CLOUDFLARE_LIST_NAME,
				'description' => 'AJCore spam IP blocklist — auto-managed by "Mark Spam" in AJOps, do not edit the name.',
				'kind'        => 'ip',
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( ! $created['success'] || empty( $created['body']['result']['id'] ) ) {
			return new WP_Error( 'ajcore_cloudflare_list_create_failed', ajcore_cloudflare_api_error_message( $created['body'], $created['status'] ) );
		}
		return array( 'id' => (string) $created['body']['result']['id'], 'created' => true );
	}
}

if ( ! function_exists( 'ajcore_cloudflare_find_custom_ruleset' ) ) {
	/**
	 * Finds the zone's http_request_firewall_custom-phase ruleset (the one behind Security rules
	 * -> Custom rules in the dashboard), full rules array included. Deliberately goes through
	 * GET /zones/{id}/rulesets (list) + GET /zones/{id}/rulesets/{ruleset_id} (by ID) rather than
	 * the seemingly-equivalent GET /zones/{id}/rulesets/phases/{phase}/entrypoint shortcut — that
	 * shortcut 403s ("Authentication error") under a token scoped with Zone > WAF: Edit even
	 * though the by-ID/list endpoints it's supposedly a convenience wrapper for work fine with the
	 * exact same token, confirmed by hand against a real zone. Cloudflare-side inconsistency, not
	 * a documented permission difference — this works around it rather than depending on it.
	 *
	 * @return array|null|WP_Error The ruleset (with "id" and "rules"), or null if this zone has
	 *                              no custom ruleset yet.
	 */
	function ajcore_cloudflare_find_custom_ruleset( $zone_id, $api_token ) {
		$list = ajcore_cloudflare_api_request(
			'GET',
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/rulesets',
			$api_token
		);
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		if ( ! $list['success'] ) {
			return new WP_Error( 'ajcore_cloudflare_ruleset_list_failed', ajcore_cloudflare_api_error_message( $list['body'], $list['status'] ) );
		}

		$ruleset_id = '';
		foreach ( (array) ( $list['body']['result'] ?? array() ) as $ruleset ) {
			if ( isset( $ruleset['phase'] ) && 'http_request_firewall_custom' === $ruleset['phase'] ) {
				$ruleset_id = (string) $ruleset['id'];
				break;
			}
		}
		if ( '' === $ruleset_id ) {
			return null;
		}

		$detail = ajcore_cloudflare_api_request(
			'GET',
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ),
			$api_token
		);
		if ( is_wp_error( $detail ) ) {
			return $detail;
		}
		if ( ! $detail['success'] || empty( $detail['body']['result']['id'] ) ) {
			return new WP_Error( 'ajcore_cloudflare_ruleset_get_failed', ajcore_cloudflare_api_error_message( $detail['body'], $detail['status'] ) );
		}
		return $detail['body']['result'];
	}
}

if ( ! function_exists( 'ajcore_cloudflare_ensure_custom_rule' ) ) {
	/**
	 * Makes sure exactly one WAF Custom Rule exists on the zone blocking traffic from the list —
	 * "ip.src in $ajcore_spam_list" — creating the zone's custom-rules entrypoint ruleset first if
	 * this zone has never had one. One rule total regardless of how many IPs the list holds later.
	 * Callers doing multiple operations in one request should call this ONCE and skip re-checking
	 * — see the $skip_rule_check param on ajcore_cloudflare_block_ip().
	 *
	 * @return array{created:bool}|WP_Error
	 */
	function ajcore_cloudflare_ensure_custom_rule( $zone_id, $api_token ) {
		$expression = 'ip.src in $' . AJCORE_CLOUDFLARE_LIST_NAME;

		$entrypoint = ajcore_cloudflare_find_custom_ruleset( $zone_id, $api_token );
		if ( is_wp_error( $entrypoint ) ) {
			return $entrypoint;
		}

		if ( null !== $entrypoint ) {
			foreach ( (array) ( $entrypoint['rules'] ?? array() ) as $rule ) {
				if ( isset( $rule['expression'] ) && $expression === $rule['expression'] ) {
					return array( 'created' => false ); // Already there.
				}
			}
			// Ruleset exists, our rule doesn't yet — add it without touching any existing rules.
			$added = ajcore_cloudflare_api_request(
				'POST',
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( (string) $entrypoint['id'] ) . '/rules',
				$api_token,
				array(
					'action'      => 'block',
					'expression'  => $expression,
					'description' => AJCORE_CLOUDFLARE_RULE_DESCRIPTION,
					'enabled'     => true,
				)
			);
			if ( is_wp_error( $added ) ) {
				return $added;
			}
			if ( ! $added['success'] ) {
				return new WP_Error( 'ajcore_cloudflare_rule_add_failed', ajcore_cloudflare_api_error_message( $added['body'], $added['status'] ) );
			}
			return array( 'created' => true );
		}

		// 404 (or any non-success) on the entrypoint GET means this zone has no custom ruleset at
		// all yet — create it with our rule as its first entry.
		$created = ajcore_cloudflare_api_request(
			'POST',
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/rulesets',
			$api_token,
			array(
				'name'  => 'default',
				'kind'  => 'zone',
				'phase' => 'http_request_firewall_custom',
				'rules' => array(
					array(
						'action'      => 'block',
						'expression'  => $expression,
						'description' => AJCORE_CLOUDFLARE_RULE_DESCRIPTION,
						'enabled'     => true,
					),
				),
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( ! $created['success'] ) {
			return new WP_Error( 'ajcore_cloudflare_ruleset_create_failed', ajcore_cloudflare_api_error_message( $created['body'], $created['status'] ) );
		}
		return array( 'created' => true );
	}
}

if ( ! function_exists( 'ajcore_cloudflare_find_list_item' ) ) {
	/**
	 * @return array|null|WP_Error The matching item (with its own "id", distinct from the IP
	 *                             string, needed to delete it later), null if not present.
	 */
	function ajcore_cloudflare_find_list_item( $account_id, $list_id, $api_token, $ip ) {
		$search = ajcore_cloudflare_api_request(
			'GET',
			'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account_id ) . '/rules/lists/' . rawurlencode( $list_id ) . '/items?search=' . rawurlencode( $ip ),
			$api_token
		);
		if ( is_wp_error( $search ) ) {
			return $search;
		}
		if ( ! $search['success'] ) {
			return new WP_Error( 'ajcore_cloudflare_list_search_failed', ajcore_cloudflare_api_error_message( $search['body'], $search['status'] ) );
		}
		foreach ( (array) ( $search['body']['result'] ?? array() ) as $item ) {
			if ( isset( $item['ip'] ) && $ip === $item['ip'] ) {
				return $item;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'ajcore_cloudflare_poll_bulk_operation' ) ) {
	/**
	 * List item add/remove calls are async on Cloudflare's side — they return an operation_id
	 * immediately rather than confirming completion. Polls briefly (these normally finish in well
	 * under a second for a single item); if it hasn't confirmed by the last attempt, treats it as
	 * "submitted" rather than failing the whole action over a slow poll.
	 */
	function ajcore_cloudflare_poll_bulk_operation( $account_id, $operation_id, $api_token, $attempts = 3 ) {
		for ( $i = 0; $i < $attempts; $i++ ) {
			$check = ajcore_cloudflare_api_request(
				'GET',
				'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $account_id ) . '/rules/lists/bulk_operations/' . rawurlencode( $operation_id ),
				$api_token
			);
			if ( ! is_wp_error( $check ) && $check['success'] && isset( $check['body']['result']['status'] ) ) {
				$op_status = (string) $check['body']['result']['status'];
				if ( 'completed' === $op_status ) {
					return true;
				}
				if ( 'failed' === $op_status ) {
					return new WP_Error( 'ajcore_cloudflare_bulk_op_failed', ! empty( $check['body']['result']['error'] ) ? (string) $check['body']['result']['error'] : __( 'Cloudflare list update failed.', 'ajforms' ) );
				}
			}
			if ( $i < $attempts - 1 ) {
				usleep( 250000 ); // 0.25s between polls.
			}
		}
		return true; // Submitted but not confirmed within the poll window — not treated as failure.
	}
}

if ( ! function_exists( 'ajcore_cloudflare_block_ip' ) ) {
	/**
	 * Adds one IP address to the account's shared "ajcore_spam_list" — a single Cloudflare List
	 * that one WAF Custom Rule (AJCore-Spam-List) blocks in its entirety, so blocking any number of
	 * spam IPs over time never creates more than one rule and never touches the zone's limited
	 * Custom Rules quota. First call ever (or the first after either has been deleted by hand)
	 * transparently creates the list and the rule; every call after that just appends to the list.
	 * Requires cloudflare_api_token, cloudflare_account_id, and cloudflare_zone_id in settings.
	 *
	 * @param string      $ip               IPv4 or IPv6 address to block.
	 * @param string      $note             Freeform comment stored on the list item (shows in
	 *                                      Cloudflare's dashboard).
	 * @param string|null $list_id          Skip re-resolving the list when the caller already has
	 *                                      it (e.g. Test Connection doing block-then-unblock in one
	 *                                      request) — saves a Cloudflare round trip per call.
	 * @param bool        $skip_rule_check  Skip re-verifying the WAF rule exists, for the same
	 *                                      reason — only safe when the caller already confirmed it
	 *                                      in this same request.
	 * @return array{blocked:bool,already_existed:bool}|WP_Error
	 */
	function ajcore_cloudflare_block_ip( $ip, $note = '', $list_id = null, $skip_rule_check = false ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'ajcore_invalid_ip', __( 'Not a valid IP address.', 'ajforms' ) );
		}

		$config = ajcore_cloudflare_get_settings();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( null === $list_id ) {
			$list = ajcore_cloudflare_get_or_create_list( $config['account_id'], $config['api_token'] );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$list_id = $list['id'];
		}

		if ( ! $skip_rule_check ) {
			$rule_ready = ajcore_cloudflare_ensure_custom_rule( $config['zone_id'], $config['api_token'] );
			if ( is_wp_error( $rule_ready ) ) {
				return $rule_ready;
			}
		}

		$existing = ajcore_cloudflare_find_list_item( $config['account_id'], $list_id, $config['api_token'], $ip );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( null !== $existing ) {
			return array( 'blocked' => true, 'already_existed' => true );
		}

		$added = ajcore_cloudflare_api_request(
			'POST',
			'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $config['account_id'] ) . '/rules/lists/' . rawurlencode( $list_id ) . '/items',
			$config['api_token'],
			array( array( 'ip' => $ip, 'comment' => '' !== $note ? substr( $note, 0, 500 ) : sprintf( 'AJCore: blocked %s', $ip ) ) )
		);
		if ( is_wp_error( $added ) ) {
			return $added;
		}
		if ( ! $added['success'] || empty( $added['body']['result']['operation_id'] ) ) {
			return new WP_Error( 'ajcore_cloudflare_block_failed', ajcore_cloudflare_api_error_message( $added['body'], $added['status'] ) );
		}

		$polled = ajcore_cloudflare_poll_bulk_operation( $config['account_id'], $added['body']['result']['operation_id'], $config['api_token'] );
		if ( is_wp_error( $polled ) ) {
			return $polled;
		}

		return array( 'blocked' => true, 'already_existed' => false );
	}
}

if ( ! function_exists( 'ajcore_cloudflare_unblock_ip' ) ) {
	/**
	 * Removes one IP from the shared list — used by the Test Connection flow to clean up its own
	 * dummy test IP, and available generally as the inverse of ajcore_cloudflare_block_ip().
	 *
	 * @param string|null $list_id Skip re-resolving the list when the caller already has it.
	 * @return array{removed:bool}|WP_Error
	 */
	function ajcore_cloudflare_unblock_ip( $ip, $list_id = null ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new WP_Error( 'ajcore_invalid_ip', __( 'Not a valid IP address.', 'ajforms' ) );
		}

		$config = ajcore_cloudflare_get_settings();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( null === $list_id ) {
			$list = ajcore_cloudflare_get_or_create_list( $config['account_id'], $config['api_token'] );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			$list_id = $list['id'];
		}

		$existing = ajcore_cloudflare_find_list_item( $config['account_id'], $list_id, $config['api_token'], $ip );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( null === $existing ) {
			return array( 'removed' => true ); // Nothing to do.
		}

		$removed = ajcore_cloudflare_api_request(
			'DELETE',
			'https://api.cloudflare.com/client/v4/accounts/' . rawurlencode( $config['account_id'] ) . '/rules/lists/' . rawurlencode( $list_id ) . '/items',
			$config['api_token'],
			array( 'items' => array( array( 'id' => $existing['id'] ) ) )
		);
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
		if ( ! $removed['success'] || empty( $removed['body']['result']['operation_id'] ) ) {
			return new WP_Error( 'ajcore_cloudflare_unblock_failed', ajcore_cloudflare_api_error_message( $removed['body'], $removed['status'] ) );
		}

		$polled = ajcore_cloudflare_poll_bulk_operation( $config['account_id'], $removed['body']['result']['operation_id'], $config['api_token'] );
		if ( is_wp_error( $polled ) ) {
			return $polled;
		}

		return array( 'removed' => true );
	}
}

if ( ! function_exists( 'ajcore_cloudflare_list_account_zones' ) ) {
	/**
	 * Lists every zone (domain) under the Cloudflare account — used to fan the AJCore-Spam-List
	 * WAF rule out to all of them. True account-level Custom Rules (one rule Cloudflare applies to
	 * every zone automatically) require an Enterprise plan; this account is Free/Pro/Business, so
	 * "block everywhere" instead means creating the same zone-level rule on each zone individually.
	 * They all reference the same account-wide list, so the end result is the same: block an IP
	 * once, and it's blocked on every zone that has this rule.
	 *
	 * @return array<int,array{id:string,name:string}>|WP_Error
	 */
	function ajcore_cloudflare_list_account_zones( $account_id, $api_token ) {
		$zones = array();
		$page  = 1;
		do {
			$list = ajcore_cloudflare_api_request(
				'GET',
				'https://api.cloudflare.com/client/v4/zones?account.id=' . rawurlencode( $account_id ) . '&per_page=50&page=' . $page,
				$api_token
			);
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			if ( ! $list['success'] ) {
				return new WP_Error( 'ajcore_cloudflare_zones_list_failed', ajcore_cloudflare_api_error_message( $list['body'], $list['status'] ) );
			}
			foreach ( (array) ( $list['body']['result'] ?? array() ) as $zone ) {
				if ( ! empty( $zone['id'] ) && ! empty( $zone['name'] ) ) {
					$zones[] = array( 'id' => (string) $zone['id'], 'name' => (string) $zone['name'] );
				}
			}
			$total_pages = isset( $list['body']['result_info']['total_pages'] ) ? (int) $list['body']['result_info']['total_pages'] : 1;
			$page++;
		} while ( $page <= $total_pages );

		return $zones;
	}
}

if ( ! function_exists( 'ajcore_cloudflare_deploy_rule_to_all_zones' ) ) {
	/**
	 * The "block everywhere" fan-out: ensures the AJCore-Spam-List list exists once, then creates
	 * the "ip.src in $ajcore_spam_list" WAF rule on EVERY zone in the account (skipping zones that
	 * already have it). After this runs once, blocking an IP from any single site's Mark Spam
	 * action — or a future account-wide feature — is enforced on every domain, without each site
	 * needing its own Test Connection click first. Requires the API token to have WAF: Edit on
	 * every zone in the account (a token scoped to just one zone will show per-domain errors below
	 * for the zones it can't reach — that's a token-scope problem, not a bug in this function).
	 *
	 * @return array{results:array<int,array{zone:string,status:string,error?:string}>}|WP_Error
	 */
	function ajcore_cloudflare_deploy_rule_to_all_zones() {
		$config = ajcore_cloudflare_get_settings();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$list = ajcore_cloudflare_get_or_create_list( $config['account_id'], $config['api_token'] );
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$zones = ajcore_cloudflare_list_account_zones( $config['account_id'], $config['api_token'] );
		if ( is_wp_error( $zones ) ) {
			return $zones;
		}
		if ( empty( $zones ) ) {
			return new WP_Error( 'ajcore_cloudflare_no_zones', __( 'No zones found on this Cloudflare account.', 'ajforms' ) );
		}

		$results = array();
		foreach ( $zones as $zone ) {
			$rule = ajcore_cloudflare_ensure_custom_rule( $zone['id'], $config['api_token'] );
			if ( is_wp_error( $rule ) ) {
				$results[] = array( 'zone' => $zone['name'], 'status' => 'error', 'error' => $rule->get_error_message() );
			} else {
				$results[] = array( 'zone' => $zone['name'], 'status' => $rule['created'] ? 'created' : 'already_existed' );
			}
		}

		return array( 'results' => $results );
	}
}

if ( ! function_exists( 'ajcore_cloudflare_run_full_test' ) ) {
	/**
	 * The Spam Protection screen's single "Test Connection" button: proves the token can read,
	 * ensures the list + rule exist (creating them on first run), and blocks a real spam IP
	 * (91.84.124.42 — lead #81, ncllcagents.com "Guide Me" form, marked spam but never confirmed
	 * blocked) to prove write access. Deliberately does NOT unblock it afterward — this is a
	 * one-off manual re-block for that specific IP, not a disposable round-trip test like the old
	 * 192.0.2.1 (RFC 5737 TEST-NET-1) version was. Reports exactly what was newly created vs.
	 * already existed so re-running this after the first time reads as "already set up", not
	 * "created again".
	 *
	 * @return array{list_created:bool,rule_created:bool,note:string,list_id:string,account_id:string}|WP_Error
	 */
	function ajcore_cloudflare_run_full_test() {
		$config = ajcore_cloudflare_get_settings();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		// Resolve the list and the rule exactly ONCE each and reuse them below — letting each of
		// those re-resolve independently (the original version of this function) turned one click
		// into ~20 sequential Cloudflare API calls, slow enough to occasionally 502 at the origin
		// on a real host even though it was fine on fast ddev.
		$list = ajcore_cloudflare_get_or_create_list( $config['account_id'], $config['api_token'] );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$list_id               = $list['id'];
		$list_existed_already  = ! $list['created'];

		$rule = ajcore_cloudflare_ensure_custom_rule( $config['zone_id'], $config['api_token'] );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$rule_existed_already = ! $rule['created'];

		$test_ip = '91.84.124.42';
		$blocked = ajcore_cloudflare_block_ip( $test_ip, 'AJCore Test Connection — manually re-blocked (lead #81, ncllcagents.com)', $list_id, true );
		if ( is_wp_error( $blocked ) ) {
			return $blocked;
		}

		if ( $list_existed_already && $rule_existed_already ) {
			$note = sprintf(
				/* translators: %s: IP address */
				__( 'Success — already set up. List and rule both exist, and %s is now blocked (write access confirmed).', 'ajforms' ),
				$test_ip
			);
		} else {
			$note = sprintf(
				/* translators: 1: setup description, 2: IP address */
				__( 'Success — %1$s %2$s is now blocked (write access confirmed).', 'ajforms' ),
				( ! $list_existed_already && ! $rule_existed_already )
					? __( 'created the AJCore-Spam-List list and rule for the first time.', 'ajforms' )
					: ( ! $list_existed_already ? __( 'created the missing list (rule already existed).', 'ajforms' ) : __( 'created the missing rule (list already existed).', 'ajforms' ) ),
				$test_ip
			);
		}

		return array(
			'list_created' => ! $list_existed_already,
			'rule_created' => ! $rule_existed_already,
			'note'         => $note,
			// So the caller can link straight to "Manage Account -> Configurations -> Lists ->
			// ajcore_spam_list" in the Cloudflare dashboard (dash.cloudflare.com/{account_id}/
			// configurations/lists/{list_id}) instead of making staff hunt for it by hand — that
			// path was confirmed against a real account, not guessed, but Cloudflare has reorganized
			// this UI before, so treat it as best-effort rather than permanent.
			'list_id'      => $list_id,
			'account_id'   => $config['account_id'],
		);
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
			// Open-tracking columns, added after this table already existed on most installs —
			// checked/added here (not just via the admin-side ensure_portal_schema() migration
			// path) because wp_mail() can fire from a plain front-end request (password resets,
			// etc.) before any wp-admin page has ever loaded post-update to trigger that path.
			if ( $exists ) {
				$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( is_array( $columns ) && ! in_array( 'tracking_token', $columns, true ) ) {
					$wpdb->query( "ALTER TABLE {$table} ADD COLUMN tracking_token VARCHAR(64) NOT NULL DEFAULT '' AFTER error_message, ADD COLUMN open_count INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER tracking_token, ADD COLUMN opened_at DATETIME NULL AFTER open_count, ADD COLUMN last_opened_at DATETIME NULL AFTER opened_at, ADD KEY tracking_token (tracking_token)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
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
			$message = (string) ( isset( $atts['message'] ) ? $atts['message'] : '' );

			// Open tracking only makes sense for HTML mail — a pixel <img> tag dropped into a
			// plain-text email would show up as literal, broken-looking markup in the body instead
			// of silently loading, so this only fires when the headers actually declare HTML.
			$token = '';
			if ( false !== stripos( $headers, 'text/html' ) && '' !== $message && function_exists( 'random_bytes' ) ) {
				$token = bin2hex( random_bytes( 16 ) );
				$pixel_url = rest_url( 'ajcore/v1/email-log/track/' . $token );
				$pixel_tag = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;" />';
				// Inside </body> if there is one (keeps it out of any content after the closing tag,
				// e.g. a trailing comment some mail clients append), otherwise just tack it on the end.
				$message = ( false !== stripos( $message, '</body>' ) )
					? preg_replace( '/<\/body>/i', $pixel_tag . '</body>', $message, 1 )
					: $message . $pixel_tag;
				$atts['message'] = $message;
			}

			$wpdb->insert(
				$wpdb->prefix . 'aj_portal_email_log',
				array(
					'to_email'       => substr( $to, 0, 190 ),
					'subject'        => substr( sanitize_text_field( (string) ( isset( $atts['subject'] ) ? $atts['subject'] : '' ) ), 0, 255 ),
					'headers'        => $headers,
					'message'        => $message,
					'status'         => 'sent',
					'tracking_token' => $token,
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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

	// Logged-in Client Portal customers already told us who they are — prefilling their name/
	// email/phone into the chat widget's pre-chat form means they don't have to type it again just
	// to say hello. Gated behind is_user_logged_in() (cheap, WP core already resolved this on every
	// request) so anonymous visitors — the overwhelming majority of front-end pageviews — never pay
	// for the extra DB lookups below; this only runs for actual logged-in users.
	$known_name  = '';
	$known_email = '';
	$known_phone = '';
	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		if ( $user && $user->ID ) {
			$known_name  = $user->display_name;
			$known_email = $user->user_email;
			global $wpdb;
			// aj_auth_user_mappings is always local, never in the shared DB — same reasoning as
			// get_current_user_portal_access_context()'s identical comment on the class-based copy
			// of this same two-step lookup.
			$mapping_table = $wpdb->prefix . 'aj_auth_user_mappings';
			$stripe_customer_id = $wpdb->get_var( $wpdb->prepare( "SELECT stripe_customer_id FROM {$mapping_table} WHERE user_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $stripe_customer_id && function_exists( 'ajcore_get_portal_db' ) ) {
				$pdb = ajcore_get_portal_db();
				$customers_table = $pdb->prefix . 'aj_portal_stripe_customers';
				$cust = $pdb->get_row( $pdb->prepare( "SELECT name, email, phone FROM {$customers_table} WHERE stripe_customer_id = %s LIMIT 1", $stripe_customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $cust ) {
					$known_name  = $cust->name ? $cust->name : $known_name;
					$known_email = $cust->email ? $cust->email : $known_email;
					$known_phone = (string) $cust->phone;
				}
			}
		}
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
			timerEnabled: <?php echo wp_json_encode( '1' === (string) ( $settings['visitor_timer_enabled'] ?? '' ) ); ?>,
			knownName: <?php echo wp_json_encode( $known_name ); ?>,
			knownEmail: <?php echo wp_json_encode( $known_email ); ?>,
			knownPhone: <?php echo wp_json_encode( $known_phone ); ?>
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
