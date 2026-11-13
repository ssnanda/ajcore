<?php
/**
 * Per-staff access to AJOps sections. One place defines the sections, maps every /ops REST route to a section
 * and enforces the level a staff member has: none, view (GET) or edit (everything else).
 *
 * Storage: user meta `ajcore_ops_permissions` = JSON { section: 'none'|'view'|'edit' }. No meta = full access, so
 * existing staff keep working until someone restricts them. Administrators always have full access.
 * Extensions add sections / routes through the `ajcore_ops_sections` and `ajcore_ops_route_sections` filters.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Ops_Access {

	const META = 'ajcore_ops_permissions';

	/** Section key => label (the AJOps menu items). */
	public static function sections() {
		$sections = array(
			'customers'         => 'Customers',
			'leads'             => 'Leads',
			'ajphone'           => 'AJPhone',
			'partners'          => 'Partners',
			'payments'          => 'Payments',
			'requests'          => 'Requests',
			'reservations'      => 'Reservations',
			'live-chat'         => 'Live Chat',
			'files'             => 'Files',
			'emails'            => 'Email Log',
			'mail'              => 'Physical Mail',
			'gmail-intake'      => 'Gmail Intake',
			'esign'             => 'E-Signatures',
			'guest-log'         => 'Guest Log',
			'leases'            => 'Leases',
			'cmra'              => 'CMRA',
			'compliance'        => 'Compliance',
			'websites'          => 'Websites',
			'business-profiles' => 'Business Profiles',
			'rentec'            => 'Rentec',
			'hoa'               => 'HOA',
			'settings'          => 'Settings',
		);
		$filtered = apply_filters( 'ajcore_ops_sections', $sections );
		return is_array( $filtered ) ? $filtered : $sections;
	}

	/** First path segment after /ops/ => section key. */
	public static function route_sections() {
		$map = array(
			'customers' => 'customers', 'customer-profiles' => 'customers',
			'leads' => 'leads',
			'ajphone' => 'ajphone',
			'partners' => 'partners',
			'transactions' => 'payments', 'subscriptions' => 'payments', 'ledger' => 'payments', 'local-recurring-transactions' => 'payments', 'invoices' => 'payments',
			'requests' => 'requests', 'request-workflows' => 'requests',
			'reservations' => 'reservations',
			'chat' => 'live-chat', 'visitors' => 'live-chat', 'visitor-banners' => 'live-chat',
			'files' => 'files',
			'email-log' => 'emails',
			'mail' => 'mail',
			'gmail-intake' => 'gmail-intake',
			'esign' => 'esign',
			'reception' => 'guest-log',
			'suite-leases' => 'leases',
			'cmra' => 'cmra',
			'compliance' => 'compliance', 'tasks' => 'compliance',
			'websites' => 'websites',
			'business-profiles' => 'business-profiles',
			'rentec' => 'rentec',
			'hoa' => 'hoa',
			'staff' => 'settings', 'sync' => 'settings', 'sync-logs' => 'settings', 'event-log' => 'settings', 'self-update' => 'settings',
		);
		$filtered = apply_filters( 'ajcore_ops_route_sections', $map );
		return is_array( $filtered ) ? $filtered : $map;
	}

	/** Shared plumbing every staff member needs: always allowed. */
	private static function always_allowed() {
		return array( 'auth', 'features', 'counts', 'summary', 'sites', 'permissions' );
	}

	/** Reference data other screens read (products, request types, ...): reading is always allowed, changing needs Settings. */
	private static function reference_read() {
		return array( 'products', 'product-counts', 'accounting-catalog', 'request-types', 'customer-billing-types', 'portal-tabs' );
	}

	/** null = full access, otherwise section => level for every section. */
	public static function user_permissions( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return null;
		}
		$raw = get_user_meta( $user->ID, self::META, true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		$stored = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $stored ) ) {
			return null;
		}
		$out = array();
		foreach ( array_keys( self::sections() ) as $key ) {
			$level       = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : 'none';
			$out[ $key ] = in_array( $level, array( 'none', 'view', 'edit' ), true ) ? $level : 'none';
		}
		return $out;
	}

	/** Save a permissions map (null clears it = full access again). */
	public static function save_user_permissions( $user_id, $map ) {
		if ( null === $map ) {
			delete_user_meta( (int) $user_id, self::META );
			return;
		}
		$clean = array();
		foreach ( array_keys( self::sections() ) as $key ) {
			$level         = isset( $map[ $key ] ) ? (string) $map[ $key ] : 'none';
			$clean[ $key ] = in_array( $level, array( 'none', 'view', 'edit' ), true ) ? $level : 'none';
		}
		update_user_meta( (int) $user_id, self::META, wp_json_encode( $clean ) );
	}

	/** The section a request belongs to ('' = shared plumbing, always allowed). */
	public static function section_for_request( WP_REST_Request $request ) {
		$route = (string) $request->get_route();
		$ns    = '/' . trim( AJCore_Extensions::rest_namespace(), '/' ) . '/ops/';
		$pos   = strpos( $route, $ns );
		if ( false === $pos ) {
			return '';
		}
		$segment = strtok( substr( $route, $pos + strlen( $ns ) ), '/' );
		$segment = is_string( $segment ) ? $segment : '';
		if ( in_array( $segment, self::always_allowed(), true ) ) {
			return '';
		}
		if ( in_array( $segment, self::reference_read(), true ) ) {
			return 'GET' === strtoupper( $request->get_method() ) ? '' : 'settings';
		}
		$map = self::route_sections();
		return isset( $map[ $segment ] ) ? $map[ $segment ] : 'settings'; // unknown ops routes are Settings-only
	}

	/** true, or a 403 WP_Error when the current staff member may not make this request. */
	public static function check_request( $request ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return true;
		}
		$perms = self::user_permissions( wp_get_current_user() );
		if ( null === $perms ) {
			return true;
		}
		$section = self::section_for_request( $request );
		if ( '' === $section ) {
			return true;
		}
		$level = isset( $perms[ $section ] ) ? $perms[ $section ] : 'none';
		$write = ! in_array( strtoupper( $request->get_method() ), array( 'GET', 'HEAD', 'OPTIONS' ), true );
		$label = isset( self::sections()[ $section ] ) ? self::sections()[ $section ] : $section;
		if ( 'none' === $level ) {
			return new WP_Error( 'ajcore_section_forbidden', sprintf( 'You do not have access to %s.', $label ), array( 'status' => 403 ) );
		}
		if ( $write && 'edit' !== $level ) {
			return new WP_Error( 'ajcore_section_view_only', sprintf( 'You have view-only access to %s.', $label ), array( 'status' => 403 ) );
		}
		return true;
	}
}
