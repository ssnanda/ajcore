<?php
/**
 * Server-side Honeywell Home client used by the AJOps UPOS Temps workspace.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AJCore_UPOS_Temps {
	const AUTH_BASE = 'https://api.honeywellhome.com';
	const API_BASE  = 'https://api.honeywellhome.com/v2';

	private static function settings() {
		$all = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$ids = preg_split( '/[\s,]+/', (string) ( $all['upos_thermo_device_ids'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		$ids = array_values( array_unique( array_map( 'sanitize_text_field', $ids ?: array() ) ) );
		return array(
			'client_id'     => trim( (string) ( $all['upos_thermo_client_id'] ?? '' ) ),
			'client_secret' => trim( (string) ( $all['upos_thermo_client_secret'] ?? '' ) ),
			'redirect_uri'  => trim( (string) ( $all['upos_thermo_redirect_uri'] ?? '' ) ),
			'location_id'   => trim( (string) ( $all['upos_thermo_location_id'] ?? '' ) ),
			'device_ids'    => $ids,
			'refresh_token' => trim( (string) ( $all['upos_thermo_refresh_token'] ?? '' ) ),
		);
	}

	public static function status() {
		$config = self::settings();
		return array(
			'ready'            => self::is_ready( $config ),
			'client_id'        => self::mask( $config['client_id'] ),
			'client_secret_set'=> '' !== $config['client_secret'],
			'redirect_uri'     => $config['redirect_uri'],
			'location_id'      => $config['location_id'],
			'device_ids'       => $config['device_ids'],
			'refresh_token_set'=> '' !== $config['refresh_token'],
		);
	}

	public static function save_settings( $values ) {
		$settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$plain = array(
			'upos_thermo_redirect_uri' => esc_url_raw( (string) ( $values['redirect_uri'] ?? '' ) ),
			'upos_thermo_location_id'  => sanitize_text_field( (string) ( $values['location_id'] ?? '' ) ),
			'upos_thermo_device_ids'   => implode( ',', array_values( array_unique( preg_split( '/[\s,]+/', sanitize_textarea_field( (string) ( $values['device_ids'] ?? '' ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) ) ),
		);
		$settings = array_merge( $settings, $plain );
		if ( '' !== trim( (string) ( $values['client_id'] ?? '' ) ) ) {
			$settings['upos_thermo_client_id'] = sanitize_text_field( (string) $values['client_id'] );
		}
		// Blank secret fields mean "keep the existing value" so the UI never needs
		// to receive a credential back from AJCore.
		if ( '' !== trim( (string) ( $values['client_secret'] ?? '' ) ) ) {
			$settings['upos_thermo_client_secret'] = sanitize_text_field( (string) $values['client_secret'] );
		}
		if ( '' !== trim( (string) ( $values['refresh_token'] ?? '' ) ) ) {
			$settings['upos_thermo_refresh_token'] = sanitize_textarea_field( (string) $values['refresh_token'] );
		}
		update_option( 'ajforms_settings', $settings );
		return self::status();
	}

	public static function fetch_devices() {
		list( $token, $config ) = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$url = add_query_arg(
			array( 'locationId' => $config['location_id'], 'apikey' => $config['client_id'] ),
			self::API_BASE . '/devices'
		);
		$response = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		$payload = self::response_json( $response, 'Honeywell device fetch failed' );
		$raw_devices = isset( $payload['devices'] ) && is_array( $payload['devices'] ) ? $payload['devices'] : $payload;
		$allowed = array_flip( $config['device_ids'] );
		$devices = array();
		foreach ( is_array( $raw_devices ) ? $raw_devices : array() as $raw ) {
			if ( ! is_array( $raw ) ) continue;
			$device = self::parse_device( $raw );
			if ( $device && isset( $allowed[ $device['id'] ] ) ) $devices[] = $device;
		}
		return $devices;
	}

	public static function run_system( $mode, $device_id = '' ) {
		$payloads = array(
			'Off'  => array( 'mode' => 'Off',  'thermostatSetpointStatus' => 'NoHold', 'heatSetpoint' => 66, 'coolSetpoint' => 77 ),
			'Cool' => array( 'mode' => 'Cool', 'thermostatSetpointStatus' => 'NoHold', 'heatSetpoint' => 70, 'coolSetpoint' => 74 ),
			'Heat' => array( 'mode' => 'Heat', 'thermostatSetpointStatus' => 'NoHold', 'heatSetpoint' => 70, 'coolSetpoint' => 74 ),
			'Auto' => array( 'mode' => 'Auto', 'thermostatSetpointStatus' => 'NoHold', 'heatSetpoint' => 70, 'coolSetpoint' => 74 ),
		);
		if ( ! isset( $payloads[ $mode ] ) ) return new WP_Error( 'invalid_upos_mode', 'Unsupported system mode.', array( 'status' => 400 ) );
		return self::run_for_devices( $device_id, 'system', $payloads[ $mode ] );
	}

	public static function run_fan( $mode, $device_id = '' ) {
		if ( ! in_array( $mode, array( 'On', 'Auto', 'Circulate' ), true ) ) return new WP_Error( 'invalid_upos_fan_mode', 'Unsupported fan mode.', array( 'status' => 400 ) );
		return self::run_for_devices( $device_id, 'fan', array( 'mode' => $mode ) );
	}

	private static function run_for_devices( $device_id, $action, $payload ) {
		list( $token, $config ) = self::access_token();
		if ( is_wp_error( $token ) ) return $token;
		$ids = '' !== $device_id ? array( sanitize_text_field( $device_id ) ) : $config['device_ids'];
		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $config['device_ids'], true ) ) return new WP_Error( 'invalid_upos_device', 'Thermostat is not in the configured device list.', array( 'status' => 403 ) );
			$path = self::API_BASE . '/devices/thermostats/' . rawurlencode( $id ) . ( 'fan' === $action ? '/fan' : '' );
			$url = add_query_arg( array( 'locationId' => $config['location_id'], 'apikey' => $config['client_id'] ), $path );
			$response = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
			$result = self::response_json( $response, 'Honeywell request failed' );
			if ( is_wp_error( $result ) ) return $result;
		}
		return array( 'success' => true, 'updated' => count( $ids ) );
	}

	private static function access_token() {
		$config = self::settings();
		if ( ! self::is_ready( $config ) ) return array( new WP_Error( 'upos_not_configured', 'UPOS Honeywell settings are incomplete.', array( 'status' => 400 ) ), $config );
		$response = wp_remote_post( self::AUTH_BASE . '/oauth2/token', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $config['client_id'] . ':' . $config['client_secret'] ), 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body' => array( 'grant_type' => 'refresh_token', 'refresh_token' => $config['refresh_token'], 'redirect_uri' => $config['redirect_uri'] ),
		) );
		$payload = self::response_json( $response, 'Honeywell token refresh failed' );
		if ( is_wp_error( $payload ) ) return array( $payload, $config );
		$token = trim( (string) ( $payload['access_token'] ?? '' ) );
		if ( '' === $token ) return array( new WP_Error( 'upos_token_missing', 'Honeywell returned no access token.', array( 'status' => 502 ) ), $config );
		$new_refresh = trim( (string) ( $payload['refresh_token'] ?? '' ) );
		if ( '' !== $new_refresh && $new_refresh !== $config['refresh_token'] ) {
			$settings = ajforms_get_settings();
			$settings['upos_thermo_refresh_token'] = $new_refresh;
			update_option( 'ajforms_settings', $settings );
		}
		return array( $token, $config );
	}

	private static function response_json( $response, $prefix ) {
		if ( is_wp_error( $response ) ) return new WP_Error( 'upos_http_error', $prefix . ': ' . $response->get_error_message(), array( 'status' => 502 ) );
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) return new WP_Error( 'upos_http_error', $prefix . ' (' . $code . '): ' . wp_strip_all_tags( $body ), array( 'status' => 502 ) );
		$payload = json_decode( $body, true );
		return is_array( $payload ) ? $payload : array();
	}

	private static function parse_device( $raw ) {
		$id = trim( (string) ( $raw['deviceID'] ?? '' ) );
		if ( '' === $id ) return null;
		$change = is_array( $raw['changeableValues'] ?? null ) ? $raw['changeableValues'] : array();
		$fan = is_array( $raw['settings']['fan']['changeableValues'] ?? null ) ? $raw['settings']['fan']['changeableValues'] : array();
		$mode = (string) ( $change['mode'] ?? $change['heatCoolMode'] ?? '' );
		$heat = self::number( $raw['heatSetpoint'] ?? $change['heatSetpoint'] ?? null );
		$cool = self::number( $raw['coolSetpoint'] ?? $change['coolSetpoint'] ?? null );
		return array(
			'id' => $id, 'name' => sanitize_text_field( (string) ( $raw['name'] ?? 'Unknown' ) ),
			'indoor_temp' => self::number( $raw['indoorTemperature'] ?? null ),
			'set_temp' => 'heat' === strtolower( $mode ) ? ( $heat ?? $cool ) : ( $cool ?? $heat ),
			'mode' => $mode ?: null, 'fan_mode' => $fan['mode'] ?? null,
			'heat_setpoint' => $heat, 'cool_setpoint' => $cool,
			'available_system_modes' => self::modes( $change['allowedModes'] ?? null, array( 'Off', 'Cool', 'Heat', 'Auto' ) ),
			'available_fan_modes' => self::modes( $fan['allowedModes'] ?? null, array( 'On', 'Auto', 'Circulate' ) ),
		);
	}

	private static function modes( $value, $fallback ) { return is_array( $value ) && $value ? array_values( array_map( 'sanitize_text_field', $value ) ) : $fallback; }
	private static function number( $value ) { return is_numeric( $value ) ? (float) $value : null; }
	private static function is_ready( $config ) { return '' !== $config['client_id'] && '' !== $config['client_secret'] && '' !== $config['redirect_uri'] && '' !== $config['location_id'] && '' !== $config['refresh_token'] && ! empty( $config['device_ids'] ); }
	private static function mask( $value ) { return '' === $value ? '' : ( strlen( $value ) <= 8 ? '••••' : substr( $value, 0, 4 ) . '…' . substr( $value, -4 ) ); }
}
