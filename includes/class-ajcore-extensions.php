<?php
/**
 * Registry for companion plugins (e.g. AJCore-RA) that extend AJCore.
 *
 * Deliberately tiny: an extension calls AJCore_Extensions::register() once it has
 * confirmed AJCore is present. AJCore never loads or requires any extension; an
 * empty registry is the normal state. Bump AJCORE_EXTENSION_API only on a breaking
 * change to this class, so extensions can gate on it.
 * API 2: added rest_namespace() / can_manage_ops() / can_manage_site_ops() so an extension
 * can register routes into AJCore's namespace behind AJCore's own auth.
 * API 3: 'ajforms_settings_defaults' and 'ajcore_portal_overview_defaults' filters; AJCore's
 * own defaults are neutral and extensions supply business-specific ones.
 * API 4: 'ajcore_ra_authorization_enabled' filter gates the Registered Agent Authorization
 * email template (Email Templates tab and the ops send/preview routes).
 * API 5: email templates carry no business text of their own — 'ajcore_default_brand',
 * 'ajcore_business_contact', 'ajcore_ra_authorization_default_body_lines' and
 * 'ajcore_ra_authorization_default_address' filters let an extension supply it.
 * API 6: the Registered Agent authorization email is built and sent by AJCore-RA
 * ('ajcore_ra_authorization_build' / '_send' / '_static_parts' filters); AJForms_Admin::
 * get_email_toolkit() exposes the email helpers an extension needs.
 * Catalog: extensions add their routes to /docs via the 'ajcore_endpoint_catalog' filter.
 *
 * Moving a route out of AJCore: register it in the extension, delete the AJCore
 * registration and catalog entry in the same change. A private AJCore helper the moved
 * callback needs is either moved too (if only that feature uses it) or made public (if
 * core code also uses it); decide per move, don't pre-expose.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'AJCORE_EXTENSION_API' ) ) {
	define( 'AJCORE_EXTENSION_API', 6 );
}

class AJCore_Extensions {

	/** @var array<string,array> */
	private static $extensions = array();

	/**
	 * @param string $slug Unique id, e.g. "ajcore-ra".
	 * @param array  $args name (string), version (string), diagnostics (callable returning label => value array).
	 * @return bool False if the slug is invalid or already registered.
	 */
	public static function register( $slug, array $args = array() ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || isset( self::$extensions[ $slug ] ) ) {
			return false;
		}
		self::$extensions[ $slug ] = wp_parse_args(
			$args,
			array(
				'name'        => $slug,
				'version'     => '',
				'diagnostics' => null,
			)
		);
		return true;
	}

	/** @return array<string,array> */
	public static function all() {
		return self::$extensions;
	}

	/** AJCore's REST namespace; extension routes register here so URLs stay unchanged. */
	public static function rest_namespace() {
		return AJCore_REST_API::NAMESPACE;
	}

	/**
	 * permission_callback for extension routes that must match AJCore's ops routes exactly
	 * (same enabled/master-site/login/role checks). AJCore_REST_API has no state, so a
	 * fresh instance is equivalent to the one that registers the core routes.
	 */
	public static function can_manage_ops() {
		return ( new AJCore_REST_API() )->can_manage_ops_api();
	}

	/** Same as can_manage_ops() but for site-local routes (matches can_manage_site_ops_api). */
	public static function can_manage_site_ops() {
		return ( new AJCore_REST_API() )->can_manage_site_ops_api();
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 31 );
	}

	public static function add_menu() {
		add_submenu_page( 'ajforms', __( 'Extensions', 'ajcore' ), __( 'Extensions', 'ajcore' ), 'manage_options', 'ajcore-extensions', array( __CLASS__, 'render_page' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Extensions', 'ajcore' ) . '</h1>';
		echo '<p>' . esc_html( sprintf( 'AJCore %s · Extension API %d', AJCORE_VERSION, AJCORE_EXTENSION_API ) ) . '</p>';
		if ( ! self::$extensions ) {
			echo '<p>' . esc_html__( 'None registered.', 'ajcore' ) . '</p></div>';
			return;
		}
		foreach ( self::$extensions as $slug => $ext ) {
			echo '<h2>' . esc_html( $ext['name'] ) . ' <small>' . esc_html( $ext['version'] ) . '</small></h2>';
			echo '<table class="widefat striped" style="max-width:600px"><tbody>';
			$rows = is_callable( $ext['diagnostics'] ) ? (array) call_user_func( $ext['diagnostics'] ) : array();
			foreach ( $rows as $label => $value ) {
				echo '<tr><th style="width:40%">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}

AJCore_Extensions::init();
