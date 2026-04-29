<?php
/**
 * Plugin Name:       WP Formy
 * Plugin URI:        https://github.com/ssnanda/wp-formy
 * Description:       A custom WordPress form builder plugin for building forms, collecting entries, and managing workflows inside WordPress.
 * Version:           0.1.14
 * Author:            itSpector
 * Author URI:        https://itspector.com
 * Update URI:        https://github.com/ssnanda/wp-formy
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-formy
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'WP_FORMY_VERSION' ) ) {
	define( 'WP_FORMY_VERSION', '0.1.14' );
}

if ( ! defined( 'WP_FORMY_PLUGIN_DIR' ) ) {
	define( 'WP_FORMY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WP_FORMY_PLUGIN_URL' ) ) {
	define( 'WP_FORMY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WP_FORMY_PLUGIN_BASENAME' ) ) {
	define( 'WP_FORMY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'WP_FORMY_SYNCED_SETTINGS_FILE' ) ) {
	define( 'WP_FORMY_SYNCED_SETTINGS_FILE', WP_FORMY_PLUGIN_DIR . 'config/synced-settings.json' );
}

if ( ! function_exists( 'wp_formy_get_settings_defaults' ) ) {
	function wp_formy_get_settings_defaults() {
		return array(
			'default_notification_email'    => get_option( 'admin_email' ),
			'default_notification_subject'  => 'New submission for {form_title}',
			'default_notifications_enabled' => '1',
			'default_from_name'             => get_bloginfo( 'name' ),
			'default_reply_to_mode'         => 'submitter',
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
			'stripe_publishable_key'        => '',
			'stripe_secret_key'             => '',
		);
	}
}

if ( ! function_exists( 'wp_formy_get_settings' ) ) {
	function wp_formy_get_settings() {
		$saved_settings = get_option( 'wp_formy_settings', array() );
		if ( ! is_array( $saved_settings ) ) {
			$saved_settings = array();
		}

		$file_settings = wp_formy_read_synced_settings_file();
		if ( ! is_array( $file_settings ) ) {
			$file_settings = array();
		}

		return wp_parse_args(
			array_merge( $saved_settings, $file_settings ),
			wp_formy_get_settings_defaults()
		);
	}
}

if ( ! function_exists( 'wp_formy_get_synced_setting_keys' ) ) {
	function wp_formy_get_synced_setting_keys() {
		return array(
			'honeypot_enabled',
			'spam_challenge_provider',
			'recaptcha_site_key',
			'hcaptcha_site_key',
			'turnstile_site_key',
			'asana_enabled',
			'asana_workspace_gid',
			'asana_project_gid',
			'stripe_mode',
			'stripe_publishable_key',
		);
	}
}

if ( ! function_exists( 'wp_formy_read_synced_settings_file' ) ) {
	function wp_formy_read_synced_settings_file() {
		if ( ! file_exists( WP_FORMY_SYNCED_SETTINGS_FILE ) || ! is_readable( WP_FORMY_SYNCED_SETTINGS_FILE ) ) {
			return array();
		}

		$raw_settings = file_get_contents( WP_FORMY_SYNCED_SETTINGS_FILE );
		if ( false === $raw_settings || '' === trim( $raw_settings ) ) {
			return array();
		}

		$decoded = json_decode( $raw_settings, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}

		$synced = array();
		foreach ( wp_formy_get_synced_setting_keys() as $key ) {
			if ( array_key_exists( $key, $decoded ) ) {
				$synced[ $key ] = $decoded[ $key ];
			}
		}

		return $synced;
	}
}

if ( ! function_exists( 'wp_formy_write_synced_settings_file' ) ) {
	function wp_formy_write_synced_settings_file( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$directory = dirname( WP_FORMY_SYNCED_SETTINGS_FILE );
		if ( ! file_exists( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		$synced_settings = array();
		foreach ( wp_formy_get_synced_setting_keys() as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$synced_settings[ $key ] = $settings[ $key ];
			}
		}

		$encoded = wp_json_encode( $synced_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			return false;
		}

		return false !== file_put_contents( WP_FORMY_SYNCED_SETTINGS_FILE, $encoded . PHP_EOL, LOCK_EX );
	}
}

/**
 * The code that runs during plugin activation.
 */
function activate_wp_formy() {
	require_once WP_FORMY_PLUGIN_DIR . 'includes/class-wp-formy-activator.php';
	WP_Formy_Activator::activate();
}
register_activation_hook( __FILE__, 'activate_wp_formy' );

/**
 * Ensure custom tables exist after regular plugin updates.
 *
 * WordPress does not run activation hooks when a plugin is updated from a zip
 * or GitHub release, so table creation must also be checked at runtime.
 */
function wp_formy_maybe_upgrade() {
	$installed_version = get_option( 'wp_formy_version', '' );

	if ( WP_FORMY_VERSION === $installed_version ) {
		return;
	}

	require_once WP_FORMY_PLUGIN_DIR . 'includes/class-wp-formy-activator.php';
	WP_Formy_Activator::activate();
	update_option( 'wp_formy_version', WP_FORMY_VERSION, false );
}
add_action( 'plugins_loaded', 'wp_formy_maybe_upgrade', 5 );

/**
 * Begins execution of the plugin.
 */
function run_wp_formy() {
	require_once WP_FORMY_PLUGIN_DIR . 'includes/class-wp-formy.php';

	$plugin = new WP_Formy();
	$plugin->run();
}

run_wp_formy();
