<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class AJCore_JWT {

	const OPTION_SECRET  = 'ajcore_jwt_secret';
	const EXPIRY_SECONDS = 30 * DAY_IN_SECONDS;

	public static function generate( $user_id ) {
		$secret  = self::get_secret();
		$now     = time();
		$header  = self::b64u( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$payload = self::b64u( wp_json_encode( array(
			'iss' => get_site_url(),
			'iat' => $now,
			'exp' => $now + self::EXPIRY_SECONDS,
			'uid' => (int) $user_id,
		) ) );
		$sig = self::b64u( hash_hmac( 'sha256', "{$header}.{$payload}", $secret, true ) );
		return "{$header}.{$payload}.{$sig}";
	}

	public static function validate( $token ) {
		$secret = self::get_secret();
		if ( empty( $secret ) || empty( $token ) ) {
			return false;
		}
		$parts = explode( '.', (string) $token );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		list( $header, $payload, $sig ) = $parts;
		$expected = self::b64u( hash_hmac( 'sha256', "{$header}.{$payload}", $secret, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return false;
		}
		$data = json_decode( self::b64u_decode( $payload ), true );
		if ( ! is_array( $data ) || empty( $data['exp'] ) || $data['exp'] < time() ) {
			return false;
		}
		if ( empty( $data['uid'] ) ) {
			return false;
		}
		return (int) $data['uid'];
	}

	public static function get_secret() {
		$secret = get_option( self::OPTION_SECRET, '' );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::OPTION_SECRET, $secret, false );
		}
		return $secret;
	}

	private static function b64u( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function b64u_decode( $data ) {
		return base64_decode( strtr( (string) $data, '-_', '+/' ) );
	}
}
