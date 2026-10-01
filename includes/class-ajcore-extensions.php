<?php
/**
 * Registry for companion plugins (e.g. AJCore-RA) that extend AJCore.
 *
 * Deliberately tiny: an extension calls AJCore_Extensions::register() once it has
 * confirmed AJCore is present. AJCore never loads or requires any extension; an
 * empty registry is the normal state. Bump AJCORE_EXTENSION_API only on a breaking
 * change to this class, so extensions can gate on it.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! defined( 'AJCORE_EXTENSION_API' ) ) {
	define( 'AJCORE_EXTENSION_API', 1 );
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
