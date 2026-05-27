<?php
/**
 * Plugin Name:       AJ Core
 * Plugin URI:        https://github.com/ssnanda/ajcore
 * Description:       A modular WordPress business toolkit for forms, payments, portals, auth, CRM, and automations.
 * Version: 0.2.21
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
	define( 'AJCORE_VERSION', '0.2.21' );
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

	if ( AJFORMS_VERSION === $installed_version && '8' === $portal_schema_version ) {
		return;
	}

	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
	AJForms_Activator::activate();
	update_option( 'ajforms_version', AJFORMS_VERSION, false );
}
add_action( 'plugins_loaded', 'ajforms_maybe_upgrade', 5 );

/**
 * Begins execution of the plugin.
 */
function run_ajforms() {
	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms.php';

	$plugin = new AJForms();
	$plugin->run();
}

run_ajforms();
