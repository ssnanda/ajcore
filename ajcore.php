<?php
/**
 * Plugin Name:       AJ Core
 * Plugin URI:        https://github.com/ssnanda/ajcore
 * Description:       A modular WordPress business toolkit for forms, payments, portals, auth, CRM, and automations.
 * Version: 0.6.4
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
	define( 'AJCORE_VERSION', '0.6.4' );
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
			'lead_followup_from_email'      => 'contactus@ncllcagents.com',
			'lead_followup_from_name'       => '',
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
			'stripe_mode'                   => 'test',
			'stripe_sandbox_publishable_key' => '',
			'stripe_sandbox_secret_key'      => '',
			'stripe_live_publishable_key'    => '',
			'stripe_live_secret_key'         => '',
			'stripe_publishable_key'        => '',
			'stripe_secret_key'             => '',
			'stripe_products_mode'          => 'all',
			'stripe_selected_prices'        => array(),
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

		// Overlay calendar/reservation settings from shared DB so all sites use the same values.
		if ( function_exists( 'ajcore_is_shared_db_enabled' ) && ajcore_is_shared_db_enabled()
			&& function_exists( 'ajcore_read_shared_calendar_settings' ) ) {
			$shared_calendar = ajcore_read_shared_calendar_settings();
			if ( ! empty( $shared_calendar ) ) {
				$settings = array_merge( $settings, $shared_calendar );
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

if ( ! function_exists( 'ajcore_is_stripe_sync_owner' ) ) {
	/**
	 * Returns true when this site may run Stripe sync/cron/webhook.
	 * Always true for local-only installs (shared DB disabled).
	 * When shared DB is enabled, reads is_master from the aj_shared_sites control table.
	 */
	function ajcore_is_stripe_sync_owner() {
		if ( ! ajcore_is_shared_db_enabled() ) {
			return true;
		}

		$shared_db = ajcore_get_shared_db();
		if ( ! $shared_db ) {
			return true; // Can't connect — don't silently disable syncing.
		}

		$uuid = (string) get_option( 'ajcore_site_uuid', '' );
		if ( '' === $uuid ) {
			return false;
		}

		$table = $shared_db->prefix . 'aj_shared_sites';
		if ( $shared_db->get_var( $shared_db->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return true; // Control table not yet created — schema not initialized yet.
		}

		$is_master = $shared_db->get_var(
			$shared_db->prepare( "SELECT is_master FROM `{$table}` WHERE site_uuid = %s LIMIT 1", $uuid )
		);

		return '1' === (string) $is_master;
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

		$domain   = (string) home_url( '/' );
		$existing = $shared_db->get_row(
			$shared_db->prepare( "SELECT id FROM `{$table}` WHERE site_uuid = %s LIMIT 1", $uuid )
		);

		if ( $existing ) {
			$shared_db->update(
				$table,
				array( 'domain' => $domain, 'last_seen' => current_time( 'mysql' ) ),
				array( 'site_uuid' => $uuid ),
				array( '%s', '%s' ),
				array( '%s' )
			);
		} else {
			$shared_db->insert(
				$table,
				array(
					'site_uuid'     => $uuid,
					'domain'        => $domain,
					'is_master'     => 0,
					'last_seen'     => current_time( 'mysql' ),
					'registered_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s', '%s' )
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
		if ( '1' !== (string) $result ) {
			return null;
		}

		$shared_db_cache = $db;
		return $shared_db_cache;
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

	if ( AJFORMS_VERSION === $installed_version && '24' === $portal_schema_version ) {
		return;
	}

	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
	AJForms_Activator::activate();
	update_option( 'ajforms_version', AJFORMS_VERSION, false );
}
add_action( 'plugins_loaded', 'ajforms_maybe_upgrade', 5 );

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
 * Begins execution of the plugin.
 */
function run_ajforms() {
	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms.php';

	$plugin = new AJForms();
	$plugin->run();
}

run_ajforms();
