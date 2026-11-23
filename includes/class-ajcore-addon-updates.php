<?php
/**
 * Latest-release lookup and install for the AJCore add-on plugins (AJCore-RA, AJCore-HOA),
 * so the Master's Connected Sites list can show and push add-on updates the same way it
 * does AJCore's own. Same GitHub release source and rules as each add-on's build-release.sh
 * publishes to; follows AJCore's "developer updates" switch (highest version incl.
 * prereleases) so one setting governs every plugin in the list.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AJCore_Addon_Updates {

	/** slug => label, GitHub repo, version constant, basename constant. */
	const ADDONS = array(
		'ajcore-ra'  => array(
			'label'    => 'AJCore-RA',
			'repo'     => 'ssnanda/ajcore-ra',
			'version'  => 'AJCORE_RA_VERSION',
			'basename' => 'AJCORE_RA_BASENAME',
		),
		'ajcore-hoa' => array(
			'label'    => 'AJCore-HOA',
			'repo'     => 'ssnanda/ajcore-hoa',
			'version'  => 'AJCORE_HOA_VERSION',
			'basename' => 'AJCORE_HOA_BASENAME',
		),
	);

	public static function is_addon( $slug ) {
		return isset( self::ADDONS[ (string) $slug ] );
	}

	public static function label( $slug ) {
		return self::is_addon( $slug ) ? self::ADDONS[ $slug ]['label'] : (string) $slug;
	}

	/** @return array<string,string> slug => version of each add-on active on this site. */
	public static function installed_versions() {
		$out = array();
		foreach ( self::ADDONS as $slug => $addon ) {
			if ( defined( $addon['version'] ) ) {
				$out[ $slug ] = (string) constant( $addon['version'] );
			}
		}
		return $out;
	}

	private static function developer_updates() {
		return '1' === (string) get_option( 'ajforms_developer_updates_enabled', '0' );
	}

	private static function cache_key( $slug ) {
		return 'ajcore_addon_release_' . str_replace( '-', '_', $slug ) . ( self::developer_updates() ? '_dev' : '' );
	}

	private static function release_version( $release ) {
		foreach ( array( 'tag_name', 'name' ) as $field ) {
			if ( isset( $release[ $field ] ) && preg_match( '/([0-9]+\.[0-9]+\.[0-9]+)/', (string) $release[ $field ], $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	private static function normalize( $slug, $release ) {
		$version = self::release_version( $release );
		if ( '' === $version ) {
			return null;
		}
		$tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		$url = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( preg_match( '/^' . preg_quote( $slug, '/' ) . '.*\.zip$/i', (string) ( $asset['name'] ?? '' ) ) && ! empty( $asset['browser_download_url'] ) ) {
				$url = esc_url_raw( (string) $asset['browser_download_url'] );
				break;
			}
		}
		if ( '' === $url ) {
			$url = 'https://github.com/' . self::ADDONS[ $slug ]['repo'] . '/releases/download/' . rawurlencode( $tag ) . '/' . $slug . '-' . rawurlencode( $version ) . '.zip';
		}
		return array( 'version' => $version, 'download_url' => $url );
	}

	/**
	 * Latest published release of one add-on. Cached 6h.
	 *
	 * @return array{version:string,download_url:string}|WP_Error
	 */
	public static function latest_release( $slug, $force = false ) {
		if ( ! self::is_addon( $slug ) ) {
			return new WP_Error( 'ajcore_addon_unknown', __( 'Unknown add-on.', 'ajforms' ) );
		}
		$key = self::cache_key( $slug );
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}
			if ( get_transient( $key . '_fail' ) ) {
				/* translators: %s: add-on name */
				return new WP_Error( 'ajcore_addon_lookup_failed', sprintf( __( 'Unable to reach the latest %s release right now.', 'ajforms' ), self::label( $slug ) ) );
			}
		}

		$dev      = self::developer_updates();
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::ADDONS[ $slug ]['repo'] . '/releases' . ( $dev ? '' : '/latest' ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AJCore/' . ( defined( 'AJCORE_VERSION' ) ? AJCORE_VERSION : '' ) . '; ' . home_url( '/' ),
				),
			)
		);
		$body = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			set_transient( $key . '_fail', 1, HOUR_IN_SECONDS ); // keep admin page loads fast while GitHub is unreachable
			/* translators: %s: add-on name */
			return new WP_Error( 'ajcore_addon_lookup_failed', sprintf( __( 'Unable to reach the latest %s release right now.', 'ajforms' ), self::label( $slug ) ) );
		}

		$info = null;
		if ( $dev ) {
			foreach ( $body as $release ) {
				if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
					continue;
				}
				$candidate = self::normalize( $slug, $release );
				if ( $candidate && ( null === $info || version_compare( $candidate['version'], $info['version'], '>' ) ) ) {
					$info = $candidate;
				}
			}
		} else {
			$info = self::normalize( $slug, $body );
		}
		if ( ! $info ) {
			/* translators: %s: add-on name */
			return new WP_Error( 'ajcore_addon_invalid', sprintf( __( 'No usable %s release was found.', 'ajforms' ), self::label( $slug ) ) );
		}

		set_transient( $key, $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	public static function clear_cache() {
		foreach ( array_keys( self::ADDONS ) as $slug ) {
			$base = 'ajcore_addon_release_' . str_replace( '-', '_', $slug );
			foreach ( array( $base, $base . '_dev', $base . '_fail', $base . '_dev_fail' ) as $key ) {
				delete_transient( $key );
			}
		}
	}

	/**
	 * Installs the latest release of an add-on that is active on this site. Only active
	 * add-ons are touched: an update never installs a plugin a site does not run.
	 *
	 * @return array{updated:bool,version:string,message?:string}|WP_Error
	 */
	public static function install_latest( $slug ) {
		$installed = self::installed_versions();
		if ( ! isset( $installed[ $slug ] ) ) {
			/* translators: %s: add-on name */
			return new WP_Error( 'ajcore_addon_inactive', sprintf( __( '%s is not active on this site.', 'ajforms' ), self::label( $slug ) ) );
		}
		$release = self::latest_release( $slug, true );
		if ( is_wp_error( $release ) ) {
			return $release;
		}
		if ( ! version_compare( $release['version'], $installed[ $slug ], '>' ) ) {
			return array( 'updated' => false, 'version' => $installed[ $slug ], 'message' => __( 'Already up to date.', 'ajforms' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $release['download_url'], array( 'overwrite_package' => true ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			/* translators: %s: add-on name */
			return new WP_Error( 'ajcore_addon_install_failed', sprintf( __( '%s update failed.', 'ajforms' ), self::label( $slug ) ) );
		}

		$basename = (string) constant( self::ADDONS[ $slug ]['basename'] );
		if ( '' !== $basename && ! is_plugin_active( $basename ) ) {
			activate_plugin( $basename );
		}
		return array( 'updated' => true, 'version' => $release['version'] );
	}
}
