<?php

class AJForms_Admin {

	private $portal_db_error = '';
	private $portal_sync_current_log_id = 0;
	private $portal_sync_current_run_key = '';
	private $portal_sync_current_job = '';
	private $portal_sync_stats = array();
	private $portal_service_display_skips = array();

	private function get_latest_release_api_url() {
		return 'https://api.github.com/repos/ssnanda/ajcore/releases/latest';
	}

	private function get_releases_api_url() {
		return 'https://api.github.com/repos/ssnanda/ajcore/releases';
	}

	private function get_update_cache_key() {
		return $this->developer_updates_enabled() ? 'ajforms_latest_developer_release_info' : 'ajforms_latest_release_info';
	}

	private function developer_updates_enabled() {
		return '1' === (string) get_option( 'ajforms_developer_updates_enabled', '0' );
	}

	private function set_developer_updates_enabled( $enabled ) {
		update_option( 'ajforms_developer_updates_enabled', $enabled ? '1' : '0' );
		delete_transient( 'ajforms_latest_release_info' );
		delete_transient( 'ajforms_latest_developer_release_info' );
	}

	private function extract_release_version( $release ) {
		$candidates = array();
		if ( isset( $release['tag_name'] ) ) {
			$candidates[] = (string) $release['tag_name'];
		}
		if ( isset( $release['name'] ) ) {
			$candidates[] = (string) $release['name'];
		}

		foreach ( $candidates as $candidate ) {
			if ( preg_match( '/([0-9]+\.[0-9]+\.[0-9]+)/', $candidate, $matches ) ) {
				return $matches[1];
			}
		}

		return '';
	}

	private function normalize_release_info( $release ) {
		$tag_name = isset( $release['tag_name'] ) ? sanitize_text_field( (string) $release['tag_name'] ) : '';
		$version  = $this->extract_release_version( $release );

		if ( '' === $version ) {
			return new WP_Error( 'ajforms_update_invalid', __( 'The latest release did not include a valid version tag.', 'ajforms' ) );
		}

		$download_url = '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$asset_name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( preg_match( '/^(ajcore|ajforms).*\.zip$/i', $asset_name ) && ! empty( $asset['browser_download_url'] ) ) {
					$download_url = esc_url_raw( (string) $asset['browser_download_url'] );
					break;
				}
			}
		}

		if ( '' === $download_url ) {
			$download_url = 'https://github.com/ssnanda/ajcore/releases/download/' . rawurlencode( $tag_name ) . '/ajcore-' . rawurlencode( $version ) . '.zip';
		}

		return array(
			'version'      => $version,
			'tag_name'     => $tag_name,
			'name'         => isset( $release['name'] ) ? sanitize_text_field( (string) $release['name'] ) : $tag_name,
			'download_url' => $download_url,
			'prerelease'   => ! empty( $release['prerelease'] ),
			'checked_at'   => current_time( 'mysql' ),
		);
	}

	private function fetch_latest_release_info( $force_refresh = false ) {
		$cache_key = $this->get_update_cache_key();

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			$this->developer_updates_enabled() ? $this->get_releases_api_url() : $this->get_latest_release_api_url(),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AJCore/' . AJFORMS_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $response_code || ! is_array( $body ) ) {
			return new WP_Error( 'ajforms_update_lookup_failed', __( 'Unable to reach the latest AJ Core release right now.', 'ajforms' ) );
		}

		if ( $this->developer_updates_enabled() ) {
			$best_release = null;
			$best_version = '';
			foreach ( $body as $release ) {
				if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
					continue;
				}
				$release_version = $this->extract_release_version( $release );
				if ( '' === $release_version ) {
					continue;
				}
				if ( null === $best_release || version_compare( $release_version, $best_version, '>' ) ) {
					$best_release = $release;
					$best_version = $release_version;
				}
			}

			if ( null === $best_release ) {
				return new WP_Error( 'ajforms_update_invalid', __( 'No usable AJ Core releases were found.', 'ajforms' ) );
			}

			$release_info = $this->normalize_release_info( $best_release );
		} else {
			$release_info = $this->normalize_release_info( $body );
		}

		if ( is_wp_error( $release_info ) ) {
			return $release_info;
		}

		set_transient( $cache_key, $release_info, 6 * HOUR_IN_SECONDS );

		return $release_info;
	}

	private function get_update_status( $force_refresh = false ) {
		$latest_release = $this->fetch_latest_release_info( $force_refresh );

		if ( is_wp_error( $latest_release ) ) {
			return $latest_release;
		}

		$current_version = AJFORMS_VERSION;
		$latest_version  = isset( $latest_release['version'] ) ? $latest_release['version'] : $current_version;

		return array(
			'current_version' => $current_version,
			'latest_version'  => $latest_version,
			'has_update'      => version_compare( $latest_version, $current_version, '>' ),
			'developer'       => ! empty( $latest_release['prerelease'] ),
			'release'         => $latest_release,
		);
	}

	private function install_plugin_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new WP_Error( 'insufficient_permissions', __( 'You do not have permission to update plugins.', 'ajforms' ) );
		}

		$latest_release = $this->fetch_latest_release_info( true );
		if ( is_wp_error( $latest_release ) ) {
			return $latest_release;
		}

		if ( empty( $latest_release['download_url'] ) ) {
			return new WP_Error( 'missing_download_url', __( 'The latest AJ Core release does not include a downloadable zip.', 'ajforms' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $latest_release['download_url'], array( 'overwrite_package' => true ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$activation_result = activate_plugin( AJCORE_PLUGIN_BASENAME );
		if ( is_wp_error( $activation_result ) && AJFORMS_PLUGIN_BASENAME !== AJCORE_PLUGIN_BASENAME ) {
			activate_plugin( AJFORMS_PLUGIN_BASENAME );
		}

		return true;
	}

	private function get_about_update_url( $action ) {
		$args = array(
			'page'          => 'ajforms-about',
			'ajf_about_act' => $action,
		);

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php' ) ), 'ajf_about_update_' . $action );
	}

	private function handle_about_update_action() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		if ( isset( $_GET['ajf_dev_updates'], $_GET['_wpnonce'] ) ) {
			check_admin_referer( 'ajf_toggle_developer_updates' );
			$this->set_developer_updates_enabled( '1' === (string) sanitize_text_field( wp_unslash( $_GET['ajf_dev_updates'] ) ) );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                => 'ajforms-about',
						'developer-updates'   => 'saved',
						'already-current'     => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if (
			! isset( $_GET['ajf_about_act'] )
			&& ! isset( $_GET['update-error'] )
			&& ! isset( $_GET['update-success'] )
			&& ! isset( $_GET['already-current'] )
			&& ! isset( $_GET['developer-updates'] )
		) {
			$args   = array( 'page' => 'ajforms-about' );
			$status = $this->get_update_status( true );

			if ( is_wp_error( $status ) ) {
				$args['update-error'] = rawurlencode( $status->get_error_message() );
			} elseif ( ! empty( $status['has_update'] ) ) {
				$result = $this->install_plugin_update();
				if ( is_wp_error( $result ) ) {
					$args['update-error'] = rawurlencode( $result->get_error_message() );
				} else {
					$args['update-success'] = '1';
				}
			} else {
				$args['already-current'] = '1';
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_GET['ajf_about_act'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['ajf_about_act'] ) );
		if ( ! in_array( $action, array( 'check', 'update' ), true ) ) {
			return;
		}

		check_admin_referer( 'ajf_about_update_' . $action );

		$args = array(
			'page' => 'ajforms-about',
		);

		if ( 'check' === $action ) {
			delete_transient( $this->get_update_cache_key() );
			$status = $this->get_update_status( true );

			if ( is_wp_error( $status ) ) {
				$args['update-error'] = rawurlencode( $status->get_error_message() );
			} elseif ( ! empty( $status['has_update'] ) ) {
				$args['update-available'] = '1';
			} else {
				$args['already-current'] = '1';
			}
		} else {
			$result = $this->install_plugin_update();

			if ( is_wp_error( $result ) ) {
				$args['update-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['update-success'] = '1';
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function get_asana_reference_cache() {
		$cache = get_option(
			'ajforms_asana_reference_cache',
			array(
				'updated_at'   => '',
				'workspaces'   => array(),
				'projects'     => array(),
				'users'        => array(),
				'workspace_gid'=> '',
			)
		);

		return is_array( $cache ) ? $cache : array(
			'updated_at'    => '',
			'workspaces'    => array(),
			'projects'      => array(),
			'users'         => array(),
			'workspace_gid' => '',
		);
	}

	private function update_asana_reference_cache( $cache ) {
		update_option( 'ajforms_asana_reference_cache', $cache );
	}

	private function get_stripe_products_cache() {
		$cache = get_option(
			'ajforms_stripe_products_cache',
			array(
				'updated_at' => '',
				'prices'     => array(),
			)
		);

		return is_array( $cache ) ? wp_parse_args(
			$cache,
			array(
				'updated_at' => '',
				'prices'     => array(),
			)
		) : array(
			'updated_at' => '',
			'prices'     => array(),
		);
	}

	private function update_stripe_products_cache( $cache ) {
		update_option( 'ajforms_stripe_products_cache', $cache );
	}


	private function get_public_product_dependency_settings() {
		$settings = get_option( 'ajcore_public_product_dependencies', array() );
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $settings as $price_id => $dependency ) {
			$price_id = sanitize_text_field( (string) $price_id );
			if ( '' === $price_id || ! is_array( $dependency ) ) {
				continue;
			}

			$normalized[ $price_id ] = array(
				'requires_price_id' => isset( $dependency['requires_price_id'] ) ? sanitize_text_field( (string) $dependency['requires_price_id'] ) : '',
				'dependency_note'   => isset( $dependency['dependency_note'] ) ? sanitize_textarea_field( (string) $dependency['dependency_note'] ) : '',
			);
		}

		return $normalized;
	}

	private function save_public_product_dependency_settings( $raw_settings ) {
		$raw_settings = is_array( $raw_settings ) ? $raw_settings : array();
		$normalized   = array();

		foreach ( $raw_settings as $price_id => $dependency ) {
			$price_id = sanitize_text_field( (string) $price_id );
			if ( '' === $price_id || ! is_array( $dependency ) ) {
				continue;
			}

			$required_price_id = isset( $dependency['requires_price_id'] ) ? sanitize_text_field( (string) $dependency['requires_price_id'] ) : '';
			$dependency_note   = isset( $dependency['dependency_note'] ) ? sanitize_textarea_field( (string) $dependency['dependency_note'] ) : '';

			if ( $required_price_id === $price_id ) {
				$required_price_id = '';
			}

			if ( '' === $required_price_id && '' === $dependency_note ) {
				continue;
			}

			$normalized[ $price_id ] = array(
				'requires_price_id' => $required_price_id,
				'dependency_note'   => $dependency_note,
			);
		}

		update_option( 'ajcore_public_product_dependencies', $normalized, false );
		$this->merge_public_product_dependency_settings_into_cache();

		return $normalized;
	}

	private function merge_public_product_dependency_settings_into_cache() {
		$dependency_settings = $this->get_public_product_dependency_settings();
		$cache               = $this->get_stripe_products_cache();
		$prices              = isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array();

		foreach ( $prices as $index => $price ) {
			if ( ! is_array( $price ) || empty( $price['id'] ) ) {
				continue;
			}

			$price_id = sanitize_text_field( (string) $price['id'] );
			$dependency = isset( $dependency_settings[ $price_id ] ) ? $dependency_settings[ $price_id ] : array();

			if ( ! empty( $dependency['requires_price_id'] ) ) {
				$prices[ $index ]['requires_price_id'] = sanitize_text_field( (string) $dependency['requires_price_id'] );
			}
			if ( ! empty( $dependency['dependency_note'] ) ) {
				$prices[ $index ]['dependency_note'] = sanitize_textarea_field( (string) $dependency['dependency_note'] );
			}
		}

		$cache['prices'] = $prices;
		$this->update_stripe_products_cache( $cache );
	}

	private function stripe_api_get( $path, $secret_key, $query_args = array() ) {
		$mode_error = $this->get_stripe_mode_blocking_error();
		if ( '' !== $mode_error ) {
			return new WP_Error( 'stripe_mode_key_mismatch', $mode_error );
		}

		$url = add_query_arg( $query_args, 'https://api.stripe.com/v1/' . ltrim( $path, '/' ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['error']['message'] ) ? sanitize_text_field( (string) $body['error']['message'] ) : __( 'Stripe request failed.', 'ajforms' );
			return new WP_Error( 'stripe_api_error', $message );
		}

		return is_array( $body ) ? $body : array();
	}

	private function stripe_api_request( $path, $secret_key, $body = array(), $method = 'POST' ) {
		$mode_error = $this->get_stripe_mode_blocking_error();
		if ( '' !== $mode_error ) {
			return new WP_Error( 'stripe_mode_key_mismatch', $mode_error );
		}

		$response = wp_remote_request(
			'https://api.stripe.com/v1/' . ltrim( $path, '/' ),
			array(
				'method'  => strtoupper( $method ),
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $decoded['error']['message'] ) ? sanitize_text_field( (string) $decoded['error']['message'] ) : __( 'Stripe request failed.', 'ajforms' );
			return new WP_Error( 'stripe_api_error', $message );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	private function stripe_api_get_all( $path, $secret_key, $query_args = array(), $limit_pages = 10 ) {
		$items       = array();
		$starting_after = '';
		$page_count  = 0;

		do {
			$args = array_merge( array( 'limit' => 100 ), $query_args );
			if ( '' !== $starting_after ) {
				$args['starting_after'] = $starting_after;
			}

			$response = $this->stripe_api_get( $path, $secret_key, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$items[] = $item;
				}
			}

			$page_count++;
			$has_more = ! empty( $response['has_more'] ) && ! empty( $data );
			$last     = end( $data );
			$starting_after = is_array( $last ) && ! empty( $last['id'] ) ? (string) $last['id'] : '';
		} while ( $has_more && '' !== $starting_after && $page_count < $limit_pages );

		return $items;
	}

	private function stripe_amount_to_decimal( $amount, $currency ) {
		$amount   = intval( $amount );
		$currency = strtolower( sanitize_key( $currency ) );

		return in_array( $currency, array( 'jpy', 'krw', 'vnd' ), true ) ? (float) $amount : (float) $amount / 100;
	}

	private function stripe_decimal_to_minor_units( $amount, $currency ) {
		$currency = strtolower( sanitize_key( $currency ) );

		return in_array( $currency, array( 'jpy', 'krw', 'vnd' ), true ) ? (int) round( (float) $amount ) : (int) round( (float) $amount * 100 );
	}

	private function stripe_timestamp_to_mysql( $timestamp ) {
		$timestamp = absint( $timestamp );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	private function get_stripe_secret_key_for_portal() {
		$settings = $this->get_plugin_settings();

		return ! empty( $settings['stripe_secret_key'] ) ? sanitize_text_field( $settings['stripe_secret_key'] ) : '';
	}

	private function get_current_stripe_livemode() {
		$settings = $this->get_plugin_settings();

		return ! empty( $settings['stripe_mode'] ) && 'live' === sanitize_key( (string) $settings['stripe_mode'] ) ? 1 : 0;
	}

	private function ensure_portal_schema() {
		global $wpdb;

		$required_tables = array(
			$this->get_portal_stripe_customers_table(),
			$this->get_portal_stripe_products_table(),
			$this->get_portal_stripe_subscriptions_table(),
			$this->get_portal_stripe_transactions_table(),
			$this->get_portal_user_mappings_table(),
			$this->get_portal_entity_mappings_table(),
			$this->get_portal_ledger_table(),
			$this->get_portal_tasks_table(),
			$this->get_portal_task_statuses_table(),
			$this->get_portal_task_comments_table(),
			$this->get_portal_sync_logs_table(),
			$this->get_portal_sync_log_items_table(),
			$this->get_portal_service_requests_table(),
			$this->get_portal_event_log_table(),
			$this->get_portal_stripe_events_table(),
			$this->get_portal_service_snapshots_table(),
		);

		foreach ( $required_tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
				AJForms_Activator::activate();
				break;
			}
		}

		$customer_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_stripe_customers_table()}", 0 );
		if ( is_array( $customer_columns ) && ! in_array( 'portal_status', $customer_columns, true ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
			AJForms_Activator::activate();
		}

		$mapping_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_user_mappings_table()}", 0 );
		if ( is_array( $mapping_columns ) && ( ! in_array( 'portal_user_email', $mapping_columns, true ) || ! in_array( 'site_uuid', $mapping_columns, true ) ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
			AJForms_Activator::activate();
		}

		$transaction_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_stripe_transactions_table()}", 0 );
		$subscription_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_stripe_subscriptions_table()}", 0 );
		$product_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_stripe_products_table()}", 0 );
		$snapshot_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_service_snapshots_table()}", 0 );
		if (
			( is_array( $transaction_columns ) && ! in_array( 'livemode', $transaction_columns, true ) )
			|| ( is_array( $subscription_columns ) && ! in_array( 'livemode', $subscription_columns, true ) )
			|| ( is_array( $product_columns ) && ! in_array( 'livemode', $product_columns, true ) )
			|| ( is_array( $product_columns ) && ! in_array( 'upgrade_from_product_id', $product_columns, true ) )
			|| ( is_array( $snapshot_columns ) && ! in_array( 'service_period', $snapshot_columns, true ) )
			|| ( is_array( $snapshot_columns ) && ! in_array( 'next_billing_date', $snapshot_columns, true ) )
		) {
			require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
			AJForms_Activator::activate();
		}

		$this->ensure_aj_portal_user_role();
		$this->repair_portal_user_links_and_roles( false, false, false );
	}

	private function get_ajcore_site_uuid() {
		$uuid = (string) get_option( 'ajcore_site_uuid', '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( 'ajcore_site_uuid', $uuid, false );
			$this->log_site_uuid_created_event( $uuid );
		}

		return $uuid;
	}

	private function log_site_uuid_created_event( $uuid ) {
		global $wpdb;

		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$actor = wp_get_current_user();
		return $wpdb->insert(
			$table,
			array(
				'event_type'     => 'site_uuid_created',
				'severity'       => 'info',
				'source'         => is_admin() ? 'wp_admin' : 'system',
				'correlation_id' => wp_generate_uuid4(),
				'site_uuid'      => sanitize_text_field( (string) $uuid ),
				'actor_user_id'  => get_current_user_id(),
				'actor_email'    => $actor && ! empty( $actor->user_email ) ? sanitize_email( $actor->user_email ) : '',
				'details'        => wp_json_encode( array( 'option' => 'ajcore_site_uuid' ) ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	private function get_portal_event_correlation_id() {
		static $correlation_id = '';
		if ( '' === $correlation_id ) {
			$correlation_id = wp_generate_uuid4();
		}

		return $correlation_id;
	}

	private function log_portal_event( $event_type, $args = array() ) {
		global $wpdb;

		$event_type = sanitize_key( (string) $event_type );
		if ( '' === $event_type ) {
			return false;
		}

		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}

		$actor = wp_get_current_user();
		$args = wp_parse_args(
			(array) $args,
			array(
				'severity'             => 'info',
				'source'               => is_admin() ? 'wp_admin' : 'frontend',
				'correlation_id'       => $this->get_portal_event_correlation_id(),
				'customer_id'          => 0,
				'stripe_customer_id'   => '',
				'wp_user_id_before'    => 0,
				'wp_user_id_after'     => 0,
				'email_before'         => '',
				'email_after'          => '',
				'portal_status_before' => '',
				'portal_status_after'  => '',
				'actor_user_id'        => get_current_user_id(),
				'actor_email'          => $actor && ! empty( $actor->user_email ) ? $actor->user_email : '',
				'details'              => array(),
			)
		);

		$details = is_array( $args['details'] ) ? $args['details'] : array( 'value' => $args['details'] );

		return $wpdb->insert(
			$table,
			array(
				'event_type'             => $event_type,
				'severity'               => in_array( sanitize_key( (string) $args['severity'] ), array( 'debug', 'info', 'warning', 'error', 'critical' ), true ) ? sanitize_key( (string) $args['severity'] ) : 'info',
				'source'                 => sanitize_key( (string) $args['source'] ),
				'correlation_id'         => sanitize_text_field( (string) $args['correlation_id'] ),
				'site_uuid'              => sanitize_text_field( $this->get_ajcore_site_uuid() ),
				'customer_id'            => absint( $args['customer_id'] ),
				'stripe_customer_id'     => sanitize_text_field( (string) $args['stripe_customer_id'] ),
				'wp_user_id_before'      => absint( $args['wp_user_id_before'] ),
				'wp_user_id_after'       => absint( $args['wp_user_id_after'] ),
				'email_before'           => sanitize_email( (string) $args['email_before'] ),
				'email_after'            => sanitize_email( (string) $args['email_after'] ),
				'portal_status_before'   => sanitize_key( (string) $args['portal_status_before'] ),
				'portal_status_after'    => sanitize_key( (string) $args['portal_status_after'] ),
				'actor_user_id'          => absint( $args['actor_user_id'] ),
				'actor_email'            => sanitize_email( (string) $args['actor_email'] ),
				'details'                => wp_json_encode( $details ),
				'created_at'             => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	private function normalize_portal_customer_status( $status ) {
		$status = sanitize_key( (string) $status );
		if ( 'without_login' === $status ) {
			$status = 'without_portal_login';
		}

		return in_array( $status, array( 'active', 'disabled', 'archived', 'without_portal_login' ), true ) ? $status : 'disabled';
	}

	private function portal_customer_status_enabled_value( $status ) {
		return 'active' === $this->normalize_portal_customer_status( $status ) ? 1 : 0;
	}

	private function set_portal_customer_status( $stripe_customer_id, $new_status, $reason = '', $source = 'portal_users', $details = array() ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$new_status         = $this->normalize_portal_customer_status( $new_status );
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'missing_customer', __( 'Stripe customer ID is required.', 'ajforms' ) );
		}

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, portal_status, enabled_portal FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);
		if ( ! $customer ) {
			return new WP_Error( 'missing_customer', __( 'Portal customer was not found.', 'ajforms' ) );
		}

		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);

		$old_status  = $this->normalize_portal_customer_status( ! empty( $customer->portal_status ) ? $customer->portal_status : ( ! empty( $customer->enabled_portal ) ? 'active' : 'disabled' ) );
		$old_enabled = (int) $customer->enabled_portal;
		$new_enabled = $this->portal_customer_status_enabled_value( $new_status );

		$updated = $wpdb->update(
			$this->get_portal_stripe_customers_table(),
			array(
				'enabled_portal' => $new_enabled,
				'portal_status'  => $new_status,
			),
			array( 'stripe_customer_id' => $stripe_customer_id ),
			array( '%d', '%s' ),
			array( '%s' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'status_update_failed', __( 'Unable to update portal customer status.', 'ajforms' ) );
		}

		if ( $old_status !== $new_status || $old_enabled !== $new_enabled ) {
			$details = is_array( $details ) ? $details : array( 'value' => $details );
			$details['reason'] = sanitize_text_field( (string) $reason );
			$details['enabled_portal_before'] = $old_enabled;
			$details['enabled_portal_after']  = $new_enabled;
			$wp_user_id_after = $mapping && ! empty( $mapping->user_id ) ? (int) $mapping->user_id : ( ! empty( $details['wp_user_id'] ) ? absint( $details['wp_user_id'] ) : 0 );
			$email_after      = $mapping && ! empty( $mapping->portal_user_email ) ? $mapping->portal_user_email : ( ! empty( $details['user_email'] ) ? sanitize_email( (string) $details['user_email'] ) : '' );
			$this->log_portal_event(
				'portal_status_changed',
				array(
					'source'                 => sanitize_key( (string) $source ),
					'customer_id'            => (int) $customer->id,
					'stripe_customer_id'     => $stripe_customer_id,
					'wp_user_id_before'      => $mapping && ! empty( $mapping->user_id ) ? (int) $mapping->user_id : 0,
					'wp_user_id_after'       => $wp_user_id_after,
					'email_before'           => $mapping && ! empty( $mapping->portal_user_email ) ? $mapping->portal_user_email : '',
					'email_after'            => $email_after,
					'portal_status_before'   => $old_status,
					'portal_status_after'    => $new_status,
					'details'                => $details,
				)
			);
		}

		return true;
	}

	private function get_portal_cache_counts() {
		global $wpdb;

		$this->ensure_portal_schema();

		return array(
			'customers'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_customers_table()}" ),
			'products'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_products_table()}" ),
			'subscriptions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_subscriptions_table()}" ),
			'transactions'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_transactions_table()}" ),
			'ledger'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_ledger_table()}" ),
			'mappings'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_user_mappings_table()}" ),
			'tasks'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_tasks_table()}" ),
			'sync_logs'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_sync_logs_table()}" ),
		);
	}

	private function ensure_aj_portal_user_role() {
		$role = get_role( 'aj_portal_user' );
		if ( ! $role ) {
			add_role(
				'aj_portal_user',
				__( 'AJ Portal User', 'ajforms' ),
				array(
					'read'                          => true,
					'ajcore_customer_portal_access' => true,
				)
			);
			return;
		}

		if ( ! $role->has_cap( 'read' ) ) {
			$role->add_cap( 'read' );
		}
		if ( ! $role->has_cap( 'ajcore_customer_portal_access' ) ) {
			$role->add_cap( 'ajcore_customer_portal_access' );
		}
	}

	private function get_portal_customer_link_emails( $customer, $mapping = null ) {
		$emails = array();
		foreach ( array(
			$customer && ! empty( $customer->email ) ? $customer->email : '',
			$mapping && ! empty( $mapping->customer_email ) ? $mapping->customer_email : '',
			$mapping && ! empty( $mapping->mapped_email ) ? $mapping->mapped_email : '',
			$mapping && ! empty( $mapping->portal_user_email ) ? $mapping->portal_user_email : '',
		) as $email ) {
			$email = strtolower( sanitize_email( (string) $email ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	private function get_valid_portal_mapping_user( $customer, $mapping ) {
		if ( ! $customer || ! $mapping || empty( $mapping->user_id ) ) {
			return null;
		}

		$user = get_userdata( (int) $mapping->user_id );
		if ( ! $user ) {
			return null;
		}

		$emails = $this->get_portal_customer_link_emails( $customer, $mapping );
		if ( ! in_array( strtolower( $user->user_email ), $emails, true ) ) {
			return null;
		}

		$current_site_uuid = $this->get_ajcore_site_uuid();
		$mapping_site_uuid = '';
		if ( isset( $mapping->mapping_site_uuid ) ) {
			$mapping_site_uuid = sanitize_text_field( (string) $mapping->mapping_site_uuid );
		} elseif ( isset( $mapping->site_uuid ) ) {
			$mapping_site_uuid = sanitize_text_field( (string) $mapping->site_uuid );
		}

		if ( '' === $mapping_site_uuid ) {
			$this->migrate_portal_mapping_site_uuid( $mapping, $customer, $user, 'mapping_validation' );
			return $user;
		}

		if ( $mapping_site_uuid !== $current_site_uuid ) {
			$this->quarantine_portal_mapping_site_uuid_mismatch( $mapping, $customer, $user, 'mapping_validation' );
			return null;
		}

		return $user;
	}

	private function migrate_portal_mapping_site_uuid( $mapping, $customer, $user, $source = 'repair' ) {
		global $wpdb;

		if ( ! $mapping || empty( $mapping->stripe_customer_id ) || ! $user ) {
			return false;
		}

		$current_site_uuid = $this->get_ajcore_site_uuid();
		$wpdb->update(
			$this->get_portal_user_mappings_table(),
			array(
				'site_uuid'  => $current_site_uuid,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'stripe_customer_id' => sanitize_text_field( (string) $mapping->stripe_customer_id ) ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		$this->log_portal_event(
			'mapping_migrated',
			array(
				'source'             => sanitize_key( (string) $source ),
				'customer_id'        => isset( $customer->id ) ? (int) $customer->id : 0,
				'stripe_customer_id' => sanitize_text_field( (string) $mapping->stripe_customer_id ),
				'wp_user_id_after'   => (int) $user->ID,
				'email_after'        => $user->user_email,
				'details'            => array(
					'reason'    => 'missing_site_uuid_email_validated',
					'site_uuid' => $current_site_uuid,
				),
			)
		);

		return true;
	}

	private function quarantine_portal_mapping_site_uuid_mismatch( $mapping, $customer, $user = null, $source = 'repair' ) {
		$mapping_site_uuid = '';
		if ( isset( $mapping->mapping_site_uuid ) ) {
			$mapping_site_uuid = sanitize_text_field( (string) $mapping->mapping_site_uuid );
		} elseif ( isset( $mapping->site_uuid ) ) {
			$mapping_site_uuid = sanitize_text_field( (string) $mapping->site_uuid );
		}

		$this->log_portal_event(
			'mapping_quarantined',
			array(
				'severity'           => 'warning',
				'source'             => sanitize_key( (string) $source ),
				'customer_id'        => isset( $customer->id ) ? (int) $customer->id : 0,
				'stripe_customer_id' => isset( $mapping->stripe_customer_id ) ? sanitize_text_field( (string) $mapping->stripe_customer_id ) : '',
				'wp_user_id_before'  => isset( $mapping->user_id ) ? (int) $mapping->user_id : 0,
				'email_before'       => $user && ! empty( $user->user_email ) ? $user->user_email : ( isset( $mapping->portal_user_email ) ? $mapping->portal_user_email : '' ),
				'details'            => array(
					'reason'            => 'site_uuid_mismatch',
					'mapping_site_uuid' => $mapping_site_uuid,
					'current_site_uuid' => $this->get_ajcore_site_uuid(),
				),
			)
		);

		return true;
	}

	private function portal_user_has_portal_role( $user ) {
		return $user && ( $this->user_has_elevated_role( $user ) || in_array( 'aj_portal_user', (array) $user->roles, true ) || user_can( $user, 'ajcore_customer_portal_access' ) );
	}

	private function user_has_elevated_role( $user ) {
		if ( ! $user ) {
			return false;
		}

		$elevated_roles = array( 'administrator', 'editor', 'shop_manager' );
		return (bool) array_intersect( $elevated_roles, (array) $user->roles );
	}

	private function assign_aj_portal_user_role( $user ) {
		if ( ! $user ) {
			return false;
		}

		$this->ensure_aj_portal_user_role();
		if ( $this->user_has_elevated_role( $user ) ) {
			return false;
		}

		if ( ! in_array( 'aj_portal_user', (array) $user->roles, true ) ) {
			$roles_before = (array) $user->roles;
			$user->set_role( 'aj_portal_user' );
			$this->log_portal_event(
				'role_added',
				array(
					'wp_user_id_after' => (int) $user->ID,
					'email_after'      => $user->user_email,
					'details'          => array( 'role' => 'aj_portal_user', 'roles_before' => $roles_before, 'roles_after' => array( 'aj_portal_user' ) ),
				)
			);
		} elseif ( in_array( 'subscriber', (array) $user->roles, true ) && 1 < count( (array) $user->roles ) ) {
			$roles_before = (array) $user->roles;
			$user->remove_role( 'subscriber' );
			$this->log_portal_event(
				'role_removed',
				array(
					'wp_user_id_after' => (int) $user->ID,
					'email_after'      => $user->user_email,
					'details'          => array( 'role' => 'subscriber', 'roles_before' => $roles_before, 'roles_after' => (array) $user->roles ),
				)
			);
		}

		return true;
	}

	private function record_portal_link_repair_item( $action, $stripe_customer_id, $message, $raw_data = array() ) {
		$this->record_portal_sync_item(
			$action,
			'portal_user_link',
			$stripe_customer_id,
			'success',
			$message,
			$raw_data,
			$stripe_customer_id
		);
	}

	private function repair_portal_user_links_and_roles( $log_items = true, $relink_matches = true, $activate_matches = false, $customer_ids = array() ) {
		global $wpdb;

		$this->ensure_aj_portal_user_role();
		if ( $log_items ) {
			$this->log_portal_event(
				'repair_started',
				array(
					'source'  => $activate_matches ? 'enable_repair' : 'repair',
					'details' => array(
						'relink_matches'  => (bool) $relink_matches,
						'activate_matches'=> (bool) $activate_matches,
						'selected_count'  => count( (array) $customer_ids ),
					),
				)
			);
		}

		$where = '1=1';
		$params = array();
		$customer_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $customer_ids ) ) );
		if ( ! empty( $customer_ids ) ) {
			$where = 'c.stripe_customer_id IN (' . implode( ',', array_fill( 0, count( $customer_ids ), '%s' ) ) . ')';
			$params = $customer_ids;
		}

		$sql = "SELECT c.*, m.id AS mapping_id, m.user_id, m.customer_email AS mapped_email, m.portal_user_email, m.site_uuid AS mapping_site_uuid
			FROM {$this->get_portal_stripe_customers_table()} c
			LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id
			WHERE {$where}
			ORDER BY c.id ASC";
		$customers = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$stats = array( 'cleared' => 0, 'relinked' => 0, 'roles' => 0, 'skipped' => 0 );
		foreach ( (array) $customers as $customer ) {
			$emails = $this->get_portal_customer_link_emails( $customer, $customer );
			if ( empty( $emails ) ) {
				$stats['skipped']++;
				continue;
			}

			$current_user = ! empty( $customer->user_id ) ? get_userdata( (int) $customer->user_id ) : null;
			$valid_current_user = $current_user && in_array( strtolower( $current_user->user_email ), $emails, true );
			$mapping_site_uuid = isset( $customer->mapping_site_uuid ) ? sanitize_text_field( (string) $customer->mapping_site_uuid ) : '';
			if ( $valid_current_user && '' !== $mapping_site_uuid && $mapping_site_uuid !== $this->get_ajcore_site_uuid() ) {
				if ( $log_items ) {
					$this->quarantine_portal_mapping_site_uuid_mismatch( $customer, $customer, $current_user, $activate_matches ? 'enable_repair' : 'repair' );
				}
				$valid_current_user = false;
			} elseif ( $valid_current_user && '' === $mapping_site_uuid && $log_items ) {
				$this->migrate_portal_mapping_site_uuid( $customer, $customer, $current_user, $activate_matches ? 'enable_repair' : 'repair' );
			}
			$matched_user = null;
			foreach ( $emails as $email ) {
				$matched_user = get_user_by( 'email', $email );
				if ( $matched_user ) {
					break;
				}
			}

			if ( $current_user && ! $valid_current_user ) {
				if ( $customer->mapping_id ) {
					$wpdb->delete(
						$this->get_portal_user_mappings_table(),
						array( 'id' => absint( $customer->mapping_id ) ),
						array( '%d' )
					);
				}
				$stats['cleared']++;
				if ( $log_items ) {
					$this->record_portal_link_repair_item( 'cleared', $customer->stripe_customer_id, __( 'Cleared mismatched local WordPress user ID from portal mapping.', 'ajforms' ), array( 'old_user_id' => (int) $current_user->ID, 'old_user_email' => $current_user->user_email, 'customer_emails' => $emails ) );
					$this->log_portal_event(
						'portal_mapping_cleared',
						array(
							'severity'           => 'warning',
							'source'             => 'repair',
							'customer_id'        => isset( $customer->id ) ? (int) $customer->id : 0,
							'stripe_customer_id' => $customer->stripe_customer_id,
							'wp_user_id_before'  => (int) $current_user->ID,
							'email_before'       => $current_user->user_email,
							'email_after'        => ! empty( $emails[0] ) ? $emails[0] : '',
							'portal_status_before' => isset( $customer->portal_status ) ? $customer->portal_status : '',
							'portal_status_after'  => isset( $customer->portal_status ) ? $customer->portal_status : '',
							'details'            => array( 'customer_emails' => $emails ),
						)
					);
				}
			}

			if ( $matched_user && ( $relink_matches || $valid_current_user ) ) {
				$old_customer_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT stripe_customer_id FROM {$this->get_portal_user_mappings_table()} WHERE user_id = %d AND stripe_customer_id <> %s",
						(int) $matched_user->ID,
						$customer->stripe_customer_id
					)
				);
				foreach ( (array) $old_customer_ids as $old_customer_id ) {
					$wpdb->delete(
						$this->get_portal_user_mappings_table(),
						array( 'stripe_customer_id' => sanitize_text_field( (string) $old_customer_id ) ),
						array( '%s' )
					);
				}

				$this->upsert_portal_record(
					$this->get_portal_user_mappings_table(),
					array(
						'user_id'            => (int) $matched_user->ID,
						'stripe_customer_id' => sanitize_text_field( (string) $customer->stripe_customer_id ),
						'customer_email'     => sanitize_email( (string) $emails[0] ),
						'portal_user_email'  => sanitize_email( (string) $matched_user->user_email ),
						'site_uuid'          => $this->get_ajcore_site_uuid(),
						'updated_at'         => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s' ),
					'stripe_customer_id'
				);
				if ( $activate_matches ) {
					$status_before = ! empty( $customer->portal_status ) ? sanitize_key( (string) $customer->portal_status ) : ( ! empty( $customer->enabled_portal ) ? 'active' : 'disabled' );
					$this->set_portal_customer_status(
						$customer->stripe_customer_id,
						'active',
						'enable_repair',
						'enable_repair',
						array(
							'wp_user_id' => (int) $matched_user->ID,
							'user_email' => $matched_user->user_email,
							'previous_status' => $status_before,
						)
					);
				}
				$stats['relinked']++;
				if ( $log_items ) {
					$this->record_portal_link_repair_item( 'relinked', $customer->stripe_customer_id, __( 'Relinked portal customer to local WordPress user by email.', 'ajforms' ), array( 'user_id' => (int) $matched_user->ID, 'user_email' => $matched_user->user_email ) );
					$this->log_portal_event(
						'relinked_by_email',
						array(
							'source'              => $activate_matches ? 'enable_repair' : 'repair',
							'customer_id'         => isset( $customer->id ) ? (int) $customer->id : 0,
							'stripe_customer_id'  => $customer->stripe_customer_id,
							'wp_user_id_before'   => $valid_current_user && $current_user ? (int) $current_user->ID : 0,
							'wp_user_id_after'    => (int) $matched_user->ID,
							'email_before'        => $valid_current_user && $current_user ? $current_user->user_email : '',
							'email_after'         => $matched_user->user_email,
							'portal_status_before'=> isset( $customer->portal_status ) ? $customer->portal_status : '',
							'portal_status_after' => $activate_matches ? 'active' : ( isset( $customer->portal_status ) ? $customer->portal_status : '' ),
						)
					);
					$this->log_portal_event(
						'portal_mapping_repaired',
						array(
							'source'              => $activate_matches ? 'enable_repair' : 'repair',
							'customer_id'         => isset( $customer->id ) ? (int) $customer->id : 0,
							'stripe_customer_id'  => $customer->stripe_customer_id,
							'wp_user_id_before'   => $valid_current_user && $current_user ? (int) $current_user->ID : 0,
							'wp_user_id_after'    => (int) $matched_user->ID,
							'email_before'        => $valid_current_user && $current_user ? $current_user->user_email : '',
							'email_after'         => $matched_user->user_email,
							'portal_status_before'=> isset( $customer->portal_status ) ? $customer->portal_status : '',
							'portal_status_after' => $activate_matches ? 'active' : ( isset( $customer->portal_status ) ? $customer->portal_status : '' ),
							'details'             => array( 'repair_action' => 'relinked_by_email' ),
						)
					);
					$this->log_portal_event(
						'mapping_relinked',
						array(
							'source'              => $activate_matches ? 'enable_repair' : 'repair',
							'customer_id'         => isset( $customer->id ) ? (int) $customer->id : 0,
							'stripe_customer_id'  => $customer->stripe_customer_id,
							'wp_user_id_before'   => $valid_current_user && $current_user ? (int) $current_user->ID : 0,
							'wp_user_id_after'    => (int) $matched_user->ID,
							'email_before'        => $valid_current_user && $current_user ? $current_user->user_email : '',
							'email_after'         => $matched_user->user_email,
							'portal_status_before'=> isset( $customer->portal_status ) ? $customer->portal_status : '',
							'portal_status_after' => $activate_matches ? 'active' : ( isset( $customer->portal_status ) ? $customer->portal_status : '' ),
							'details'             => array( 'site_uuid' => $this->get_ajcore_site_uuid() ),
						)
					);
				}

				if ( $this->assign_aj_portal_user_role( $matched_user ) ) {
					$stats['roles']++;
				}
			}
		}

		if ( $log_items ) {
			$this->log_portal_event(
				$activate_matches ? 'enable_repair_completed' : 'repair_completed',
				array(
					'source'  => $activate_matches ? 'enable_repair' : 'repair',
					'details' => $stats,
				)
			);
		}

		return $stats;
	}

	private function reset_portal_sync_stats() {
		$this->portal_sync_stats = array(
			'inserted'           => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'unmatched_payments' => 0,
			'unmatched_refunds'  => 0,
			'missing_users'      => 0,
			'warnings'           => array(),
		);
	}

	private function increment_portal_sync_stat( $key, $amount = 1 ) {
		if ( empty( $this->portal_sync_stats ) ) {
			$this->reset_portal_sync_stats();
		}

		if ( ! isset( $this->portal_sync_stats[ $key ] ) || is_array( $this->portal_sync_stats[ $key ] ) ) {
			$this->portal_sync_stats[ $key ] = 0;
		}

		$this->portal_sync_stats[ $key ] += absint( $amount );
	}

	private function add_portal_sync_warning( $message ) {
		if ( empty( $this->portal_sync_stats ) ) {
			$this->reset_portal_sync_stats();
		}

		$message = sanitize_text_field( (string) $message );
		if ( '' !== $message && ! in_array( $message, $this->portal_sync_stats['warnings'], true ) ) {
			$this->portal_sync_stats['warnings'][] = $message;
		}
	}

	private function record_portal_sync_item( $action, $record_type, $record_id, $status = 'success', $message = '', $raw_data = array(), $stripe_customer_id = '' ) {
		if ( empty( $this->portal_sync_current_log_id ) ) {
			return;
		}

		global $wpdb;

		$wpdb->insert(
			$this->get_portal_sync_log_items_table(),
			array(
				'log_id'             => absint( $this->portal_sync_current_log_id ),
				'run_key'            => sanitize_text_field( (string) $this->portal_sync_current_run_key ),
				'job_name'           => sanitize_key( (string) $this->portal_sync_current_job ),
				'action'             => sanitize_key( (string) $action ),
				'record_type'        => sanitize_key( (string) $record_type ),
				'record_id'          => sanitize_text_field( (string) $record_id ),
				'stripe_customer_id' => sanitize_text_field( (string) $stripe_customer_id ),
				'status'             => sanitize_key( (string) $status ),
				'message'            => sanitize_textarea_field( (string) $message ),
				'raw_data'           => ! empty( $raw_data ) ? wp_json_encode( $raw_data ) : '',
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private function upsert_portal_record( $table, $data, $formats, $unique_key ) {
		global $wpdb;

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$unique_key} = %s LIMIT 1",
				$data[ $unique_key ]
			)
		);
		$existing_id = $existing ? (int) $existing->id : 0;

		$mode_scoped_tables = array(
			$this->get_portal_stripe_products_table(),
			$this->get_portal_stripe_subscriptions_table(),
			$this->get_portal_stripe_transactions_table(),
		);
		if ( $existing && in_array( $table, $mode_scoped_tables, true ) && array_key_exists( 'livemode', $data ) && isset( $existing->livemode ) && (int) $existing->livemode !== (int) $data['livemode'] ) {
			$message = __( 'Skipped Stripe row because the existing record belongs to a different Stripe mode.', 'ajforms' );
			$this->increment_portal_sync_stat( 'skipped' );
			$this->record_portal_sync_item(
				'stripe_mode_mismatch',
				$unique_key,
				isset( $data[ $unique_key ] ) ? $data[ $unique_key ] : '',
				'skipped',
				$message,
				array(
					'existing_livemode' => (int) $existing->livemode,
					'incoming_livemode' => (int) $data['livemode'],
					'data'              => $data,
				),
				isset( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : ''
			);
			$this->log_portal_event(
				'stripe_mode_mismatch_skipped',
				array(
					'severity'           => 'warning',
					'source'             => 'stripe_sync',
					'stripe_customer_id' => isset( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : '',
					'details'            => array(
						'table'             => $table,
						'unique_key'        => $unique_key,
						'record_id'         => isset( $data[ $unique_key ] ) ? $data[ $unique_key ] : '',
						'existing_livemode' => (int) $existing->livemode,
						'incoming_livemode' => (int) $data['livemode'],
					),
				)
			);
			return 0;
		}

		if ( $existing_id ) {
			$result = $wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ), $formats, array( '%d' ) );
			if ( false === $result ) {
				$this->portal_db_error = $wpdb->last_error ? $wpdb->last_error : __( 'Database update failed.', 'ajforms' );
				update_option( 'ajforms_last_portal_db_error', $this->portal_db_error, false );
				$this->increment_portal_sync_stat( 'failed' );
				$this->record_portal_sync_item( 'update', $unique_key, isset( $data[ $unique_key ] ) ? $data[ $unique_key ] : '', 'failed', $this->portal_db_error, $data, isset( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : '' );
				return 0;
			}
			$this->increment_portal_sync_stat( 'updated' );
			$this->record_portal_sync_item( 'updated', $unique_key, isset( $data[ $unique_key ] ) ? $data[ $unique_key ] : '', 'success', '', $data, isset( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : '' );
			return absint( $existing_id );
		}

		$result = $wpdb->insert( $table, $data, $formats );
		if ( false === $result ) {
			$this->portal_db_error = $wpdb->last_error ? $wpdb->last_error : __( 'Database insert failed.', 'ajforms' );
			update_option( 'ajforms_last_portal_db_error', $this->portal_db_error, false );
			$this->increment_portal_sync_stat( 'failed' );
			$this->record_portal_sync_item( 'insert', $unique_key, isset( $data[ $unique_key ] ) ? $data[ $unique_key ] : '', 'failed', $this->portal_db_error, $data, isset( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : '' );
			return 0;
		}

		$this->increment_portal_sync_stat( 'inserted' );
		$this->record_portal_sync_item( 'inserted', $unique_key, isset( $data[ $unique_key ] ) ? $data[ $unique_key ] : '', 'success', '', $data, isset( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : '' );

		return (int) $wpdb->insert_id;
	}

	private function upsert_portal_stripe_customer_record( $data, $formats ) {
		global $wpdb;

		$table = $this->get_portal_stripe_customers_table();
		if ( empty( $data['stripe_customer_id'] ) ) {
			return 0;
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, enabled_portal, portal_status, livemode FROM {$table} WHERE stripe_customer_id = %s LIMIT 1",
				$data['stripe_customer_id']
			)
		);

		if ( $existing && array_key_exists( 'livemode', $data ) && isset( $existing->livemode ) && (int) $existing->livemode !== (int) $data['livemode'] ) {
			$message = __( 'Skipped Stripe customer because the existing record belongs to a different Stripe mode.', 'ajforms' );
			$this->increment_portal_sync_stat( 'skipped' );
			$this->record_portal_sync_item(
				'stripe_mode_mismatch',
				'stripe_customer_id',
				$data['stripe_customer_id'],
				'skipped',
				$message,
				array(
					'existing_livemode' => (int) $existing->livemode,
					'incoming_livemode' => (int) $data['livemode'],
				),
				$data['stripe_customer_id']
			);
			$this->log_portal_event(
				'stripe_mode_mismatch_skipped',
				array(
					'severity'           => 'warning',
					'source'             => 'stripe_sync',
					'customer_id'        => (int) $existing->id,
					'stripe_customer_id' => $data['stripe_customer_id'],
					'details'            => array(
						'table'             => $table,
						'unique_key'        => 'stripe_customer_id',
						'record_id'         => $data['stripe_customer_id'],
						'existing_livemode' => (int) $existing->livemode,
						'incoming_livemode' => (int) $data['livemode'],
					),
				)
			);
			return 0;
		}

		if ( $existing && in_array( $this->normalize_portal_customer_status( $existing->portal_status ), array( 'active', 'disabled', 'archived', 'without_portal_login' ), true ) ) {
			unset( $data['enabled_portal'], $data['portal_status'] );
			$formats = array_slice( $formats, 0, count( $data ) );
			$preserved_status = $this->normalize_portal_customer_status( $existing->portal_status );
			$this->set_portal_customer_status( $data['stripe_customer_id'], $preserved_status, 'sync_preserve_status', 'stripe_sync' );
			$this->record_portal_sync_item(
				'preserved',
				'portal_status',
				$data['stripe_customer_id'],
				'success',
				sprintf(
					/* translators: %s: portal status */
					__( 'Preserved existing portal status: %s.', 'ajforms' ),
					$preserved_status
				),
				array( 'portal_status' => $preserved_status, 'enabled_portal' => (int) $existing->enabled_portal ),
				$data['stripe_customer_id']
			);
			$this->log_portal_event(
				'sync_status_preserved',
				array(
					'source'                 => 'stripe_sync',
					'customer_id'            => (int) $existing->id,
					'stripe_customer_id'     => $data['stripe_customer_id'],
					'portal_status_before'   => $preserved_status,
					'portal_status_after'    => $preserved_status,
					'details'                => array( 'enabled_portal' => (int) $existing->enabled_portal ),
				)
			);
		} else {
			if ( ! array_key_exists( 'enabled_portal', $data ) ) {
				$data['enabled_portal'] = $this->portal_customer_status_enabled_value( isset( $data['portal_status'] ) ? $data['portal_status'] : 'disabled' );
				$formats[] = '%d';
			}
			if ( ! array_key_exists( 'portal_status', $data ) ) {
				$data['portal_status'] = 'disabled';
				$formats[] = '%s';
			}
			$data['portal_status']  = $this->normalize_portal_customer_status( $data['portal_status'] );
			$data['enabled_portal'] = $this->portal_customer_status_enabled_value( $data['portal_status'] );
		}

		return $this->upsert_portal_record( $table, $data, $formats, 'stripe_customer_id' );
	}

	private function get_guest_portal_customer_id( $email ) {
		$email = strtolower( sanitize_email( (string) $email ) );

		return is_email( $email ) ? 'guest_' . md5( $email ) : '';
	}

	private function is_real_stripe_customer_id( $customer_id ) {
		return 0 === strpos( sanitize_text_field( (string) $customer_id ), 'cus_' );
	}

	private function get_payment_customer_id( $payment ) {
		if ( ! empty( $payment['customer'] ) && is_string( $payment['customer'] ) ) {
			return sanitize_text_field( (string) $payment['customer'] );
		}
		if ( ! empty( $payment['customer']['id'] ) && is_string( $payment['customer']['id'] ) ) {
			return sanitize_text_field( (string) $payment['customer']['id'] );
		}

		$email = '';
		if ( ! empty( $payment['customer_details']['email'] ) ) {
			$email = (string) $payment['customer_details']['email'];
		} elseif ( ! empty( $payment['customer_email'] ) ) {
			$email = (string) $payment['customer_email'];
		} elseif ( ! empty( $payment['billing_details']['email'] ) ) {
			$email = (string) $payment['billing_details']['email'];
		}

		return $this->get_guest_portal_customer_id( $email );
	}

	private function extract_checkout_custom_fields( $payment ) {
		$fields = array();

		if ( empty( $payment['custom_fields'] ) || ! is_array( $payment['custom_fields'] ) ) {
			return $fields;
		}

		foreach ( $payment['custom_fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}

			$key   = sanitize_key( (string) $field['key'] );
			$value = '';
			$type  = ! empty( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : '';

			if ( 'text' === $type && isset( $field['text']['value'] ) ) {
				$value = sanitize_text_field( (string) $field['text']['value'] );
			} elseif ( 'numeric' === $type && isset( $field['numeric']['value'] ) ) {
				$value = sanitize_text_field( (string) $field['numeric']['value'] );
			} elseif ( 'dropdown' === $type && isset( $field['dropdown']['value'] ) ) {
				$value = sanitize_text_field( (string) $field['dropdown']['value'] );
			}

			if ( '' !== $key && '' !== $value ) {
				$fields[ $key ] = $value;
			}
		}

		return $fields;
	}

	private function get_checkout_custom_field_value( $payment, $key ) {
		$fields = $this->extract_checkout_custom_fields( $payment );
		$key    = sanitize_key( (string) $key );

		return isset( $fields[ $key ] ) ? sanitize_text_field( (string) $fields[ $key ] ) : '';
	}

	private function update_portal_customer_checkout_custom_fields( $stripe_customer_id, $payment ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$custom_fields      = $this->extract_checkout_custom_fields( $payment );

		if ( '' === $stripe_customer_id || empty( $custom_fields ) ) {
			return false;
		}

		$table = $this->get_portal_stripe_customers_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, metadata FROM {$table} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);

		if ( ! $row ) {
			return false;
		}

		$metadata = ! empty( $row->metadata ) ? json_decode( (string) $row->metadata, true ) : array();
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		foreach ( $custom_fields as $field_key => $field_value ) {
			$metadata[ $field_key ] = $field_value;
		}

		if ( ! empty( $custom_fields['business_name'] ) ) {
			$metadata['business_name'] = sanitize_text_field( (string) $custom_fields['business_name'] );
		}

		return false !== $wpdb->update(
			$table,
			array(
				'metadata'  => wp_json_encode( $metadata ),
				'synced_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $row->id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function get_invoice_line_summary( $invoice ) {
		$summary = array(
			'description'          => '',
			'invoice_number'       => ! empty( $invoice['number'] ) ? sanitize_text_field( (string) $invoice['number'] ) : '',
			'subscription_id'      => ! empty( $invoice['subscription'] ) ? sanitize_text_field( (string) $invoice['subscription'] ) : '',
			'service_period_start' => '',
			'service_period_end'   => '',
			'service_period'       => '',
			'invoice_pdf'          => ! empty( $invoice['invoice_pdf'] ) ? esc_url_raw( (string) $invoice['invoice_pdf'] ) : '',
			'hosted_invoice_url'   => ! empty( $invoice['hosted_invoice_url'] ) ? esc_url_raw( (string) $invoice['hosted_invoice_url'] ) : '',
		);

		$lines = ! empty( $invoice['lines']['data'] ) && is_array( $invoice['lines']['data'] ) ? $invoice['lines']['data'] : array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}

			if ( '' === $summary['description'] && ! empty( $line['description'] ) ) {
				$summary['description'] = sanitize_text_field( (string) $line['description'] );
			}

			if ( '' === $summary['subscription_id'] && ! empty( $line['subscription'] ) ) {
				$summary['subscription_id'] = sanitize_text_field( (string) $line['subscription'] );
			}

			if ( '' === $summary['service_period'] ) {
				$metadata = ! empty( $line['metadata'] ) && is_array( $line['metadata'] ) ? $line['metadata'] : array();
				foreach ( array( 'service_period', 'period' ) as $period_key ) {
					if ( ! empty( $metadata[ $period_key ] ) && is_scalar( $metadata[ $period_key ] ) ) {
						$summary['service_period'] = sanitize_text_field( (string) $metadata[ $period_key ] );
						break;
					}
				}
			}

			if ( ! empty( $line['period']['start'] ) && ! empty( $line['period']['end'] ) ) {
				$start_timestamp = absint( $line['period']['start'] );
				$end_timestamp   = absint( $line['period']['end'] );
				$summary['service_period_start'] = $this->stripe_timestamp_to_mysql( $start_timestamp );
				$summary['service_period_end']   = $this->stripe_timestamp_to_mysql( $end_timestamp );
				$summary['service_period']       = sprintf(
					'%1$s - %2$s',
					date_i18n( get_option( 'date_format' ), $start_timestamp ),
					date_i18n( get_option( 'date_format' ), $end_timestamp )
				);
				break;
			}
		}

		return $summary;
	}

	private function enrich_checkout_session_with_line_items( $session, $secret_key ) {
		if ( empty( $session['id'] ) ) {
			return $session;
		}

		if ( ! empty( $session['line_items']['data'] ) || ! empty( $session['items'] ) ) {
			return $session;
		}

		$line_items = $this->stripe_api_get(
			'checkout/sessions/' . rawurlencode( (string) $session['id'] ) . '/line_items',
			$secret_key,
			array( 'limit' => 100, 'expand[]' => 'data.price.product' )
		);

		if ( is_wp_error( $line_items ) || empty( $line_items['data'] ) || ! is_array( $line_items['data'] ) ) {
			return $session;
		}

		$session['line_items'] = $line_items;

		return $session;
	}

	private function upsert_portal_checkout_session_transaction_cache( $session, $customer_id ) {
		if ( empty( $session['id'] ) || '' === $customer_id ) {
			return 0;
		}

		$payment_intent_id = ! empty( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : '';
		$currency = isset( $session['currency'] ) ? strtolower( sanitize_key( $session['currency'] ) ) : 'usd';

		$upserted = $this->upsert_portal_record(
			$this->get_portal_stripe_transactions_table(),
			array(
				'stripe_object_id'   => sanitize_text_field( (string) $session['id'] ),
				'object_type'        => 'checkout_session',
				'stripe_customer_id' => sanitize_text_field( (string) $customer_id ),
				'invoice_id'         => ! empty( $session['invoice'] ) ? sanitize_text_field( (string) $session['invoice'] ) : '',
				'payment_intent_id'  => $payment_intent_id,
				'charge_id'          => '',
				'description'        => ! empty( $session['metadata']['source'] ) ? sanitize_text_field( (string) $session['metadata']['source'] ) : sprintf( __( 'Checkout session %s', 'ajforms' ), $session['id'] ),
				'amount'             => $this->stripe_amount_to_decimal( isset( $session['amount_total'] ) ? $session['amount_total'] : 0, $currency ),
				'currency'           => $currency,
				'status'             => ! empty( $session['payment_status'] ) ? sanitize_key( (string) $session['payment_status'] ) : '',
				'transaction_date'   => ! empty( $session['created'] ) ? $this->stripe_timestamp_to_mysql( $session['created'] ) : null,
				'due_date'           => null,
				'raw_data'           => wp_json_encode( $session ),
				'livemode'           => ! empty( $session['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
				'synced_at'          => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			'stripe_object_id'
		);
		$this->maybe_create_portal_service_snapshots_from_stripe_object( $session, 'checkout_session' );

		return $upserted;
	}

	private function sync_portal_stripe_products( $secret_key ) {
		global $wpdb;

		$products = $this->stripe_api_get_all( 'prices', $secret_key, array( 'expand[]' => 'data.product' ) );
		if ( is_wp_error( $products ) ) {
			return $products;
		}

		$count = 0;
		foreach ( $products as $price ) {
			if ( empty( $price['id'] ) ) {
				continue;
			}

			$product  = isset( $price['product'] ) && is_array( $price['product'] ) ? $price['product'] : array();
			$currency = isset( $price['currency'] ) ? strtolower( sanitize_key( $price['currency'] ) ) : 'usd';
			$recurring = isset( $price['recurring'] ) && is_array( $price['recurring'] ) ? $price['recurring'] : array();
			$price_id = sanitize_text_field( (string) $price['id'] );
			$existing_visibility = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT visibility FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s LIMIT 1",
					$price_id
				)
			);

			$upserted = $this->upsert_portal_record(
				$this->get_portal_stripe_products_table(),
				array(
					'stripe_product_id'    => ! empty( $product['id'] ) ? sanitize_text_field( (string) $product['id'] ) : sanitize_text_field( (string) $price['product'] ),
					'stripe_price_id'      => $price_id,
					'name'                 => ! empty( $product['name'] ) ? sanitize_text_field( (string) $product['name'] ) : __( 'Stripe product', 'ajforms' ),
					'description'          => ! empty( $product['description'] ) ? sanitize_textarea_field( (string) $product['description'] ) : '',
					'price_amount'         => $this->stripe_amount_to_decimal( isset( $price['unit_amount'] ) ? $price['unit_amount'] : 0, $currency ),
					'currency'             => $currency,
					'recurring_interval'   => ! empty( $recurring['interval'] ) ? sanitize_key( (string) $recurring['interval'] ) : '',
					'active'               => ( ! isset( $price['active'] ) || ! empty( $price['active'] ) ) && ( empty( $product ) || ! isset( $product['active'] ) || ! empty( $product['active'] ) ) ? 1 : 0,
					'metadata'             => ! empty( $product['metadata'] ) ? wp_json_encode( $product['metadata'] ) : '',
					'raw_data'             => wp_json_encode( $price ),
					'visibility'           => $existing_visibility ? sanitize_key( (string) $existing_visibility ) : 'hidden',
					'livemode'             => ! empty( $price['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
					'synced_at'            => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ),
				'stripe_price_id'
			);
			if ( $upserted ) {
				$count++;
			}
		}

		$product_cache = $this->fetch_stripe_product_prices( $secret_key );
		if ( is_wp_error( $product_cache ) ) {
			// The portal product cache above supports recurring prices; keep going if the older public-product cache rejects some data.
		}

		return $this->portal_db_error ? new WP_Error( 'portal_db_error', $this->portal_db_error ) : $count;
	}

	private function sync_portal_stripe_customers( $secret_key ) {
		$customers = $this->stripe_api_get_all( 'customers', $secret_key );
		if ( is_wp_error( $customers ) ) {
			return $customers;
		}

		$count = 0;
		foreach ( $customers as $customer ) {
			if ( empty( $customer['id'] ) || ! empty( $customer['deleted'] ) ) {
				continue;
			}

			$upserted = $this->upsert_portal_stripe_customer_record(
				array(
					'stripe_customer_id' => sanitize_text_field( (string) $customer['id'] ),
					'email'              => ! empty( $customer['email'] ) ? sanitize_email( (string) $customer['email'] ) : '',
					'name'               => ! empty( $customer['name'] ) ? sanitize_text_field( (string) $customer['name'] ) : '',
					'phone'              => ! empty( $customer['phone'] ) ? sanitize_text_field( (string) $customer['phone'] ) : '',
					'address'            => ! empty( $customer['address'] ) ? wp_json_encode( $customer['address'] ) : '',
					'metadata'           => ! empty( $customer['metadata'] ) ? wp_json_encode( $customer['metadata'] ) : '',
					'raw_data'           => wp_json_encode( $customer ),
					'livemode'           => ! empty( $customer['livemode'] ) ? 1 : 0,
					'created_at'         => ! empty( $customer['created'] ) ? $this->stripe_timestamp_to_mysql( $customer['created'] ) : null,
					'synced_at'          => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( $upserted ) {
				$count++;
			}
		}

		$sessions = $this->stripe_api_get_all( 'checkout/sessions', $secret_key );
		if ( is_wp_error( $sessions ) ) {
			return $sessions;
		}

		foreach ( $sessions as $session ) {
			if ( empty( $session['id'] ) ) {
				continue;
			}

			$customer_id = $this->get_payment_customer_id( $session );
			if ( ! $this->is_real_stripe_customer_id( $customer_id ) ) {
				$this->record_portal_sync_item( 'skipped', 'checkout_session', $session['id'], 'skipped', __( 'Skipped guest checkout buyer because portal customer sync now imports Stripe Customer records only.', 'ajforms' ), $session );
				continue;
			}

			$this->update_portal_customer_checkout_custom_fields( $customer_id, $session );
		}

		return $this->portal_db_error ? new WP_Error( 'portal_db_error', $this->portal_db_error ) : $count;
	}

	private function sync_portal_stripe_subscriptions( $secret_key, $stripe_customer_id = '' ) {
		$args = array( 'status' => 'all', 'expand[]' => 'data.items.data.price' );
		if ( '' !== $stripe_customer_id ) {
			$args['customer'] = sanitize_text_field( $stripe_customer_id );
		}

		$subscriptions = $this->stripe_api_get_all( 'subscriptions', $secret_key, $args );
		if ( is_wp_error( $subscriptions ) ) {
			return $subscriptions;
		}

		$count = 0;
		foreach ( $subscriptions as $subscription ) {
			if ( empty( $subscription['id'] ) || empty( $subscription['customer'] ) ) {
				continue;
			}

			$upserted = $this->upsert_portal_record(
				$this->get_portal_stripe_subscriptions_table(),
				array(
					'stripe_subscription_id' => sanitize_text_field( (string) $subscription['id'] ),
					'stripe_customer_id'     => sanitize_text_field( (string) $subscription['customer'] ),
					'status'                 => ! empty( $subscription['status'] ) ? sanitize_key( (string) $subscription['status'] ) : '',
					'current_period_end'     => ! empty( $subscription['current_period_end'] ) ? $this->stripe_timestamp_to_mysql( $subscription['current_period_end'] ) : null,
					'cancel_at_period_end'   => ! empty( $subscription['cancel_at_period_end'] ) ? 1 : 0,
					'items'                  => ! empty( $subscription['items']['data'] ) ? wp_json_encode( $subscription['items']['data'] ) : '',
					'raw_data'               => wp_json_encode( $subscription ),
					'livemode'               => ! empty( $subscription['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
					'synced_at'              => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ),
				'stripe_subscription_id'
			);
			if ( $upserted ) {
				$this->maybe_create_portal_service_snapshots_from_stripe_object( $subscription, 'subscription' );
				$count++;
			}
		}

		$count += $this->backfill_portal_ledger_service_charges_from_snapshots( $stripe_customer_id );

		return $this->portal_db_error ? new WP_Error( 'portal_db_error', $this->portal_db_error ) : $count;
	}


	private function get_ignored_unpaid_checkout_sources() {
		return array(
			'ajcore_portal_balance_payment',
			'ajcore_products_cart',
			'ajcore_portal_add_service',
			'ajcore_mixed_cart_subscription',
			'ajcore_portal_mixed_cart_subscription',
		);
	}

	private function cleanup_unpaid_portal_checkout_sessions( $stripe_customer_id = '' ) {
		global $wpdb;

		$ledger_table         = $this->get_portal_ledger_table();
		$transactions_table   = $this->get_portal_stripe_transactions_table();
		$snapshots_table      = $this->get_portal_service_snapshots_table();
		$stripe_customer_id   = sanitize_text_field( (string) $stripe_customer_id );
		$unpaid_statuses      = array( 'unpaid', 'open', 'pending', 'pending_payment', 'requires_payment_method' );
		$ignored_sources      = $this->get_ignored_unpaid_checkout_sources();
		$status_placeholders  = implode( ',', array_fill( 0, count( $unpaid_statuses ), '%s' ) );
		$source_placeholders  = implode( ',', array_fill( 0, count( $ignored_sources ), '%s' ) );
		$metadata_conditions  = array();

		foreach ( $ignored_sources as $ignored_source ) {
			$metadata_conditions[] = 'metadata LIKE %s';
		}

		$ledger_sql = "DELETE FROM {$ledger_table} WHERE source_type = %s AND status IN ({$status_placeholders}) AND (description IN ({$source_placeholders}) OR " . implode( ' OR ', $metadata_conditions ) . ')';
		$ledger_params = array_merge(
			array( 'checkout_session' ),
			$unpaid_statuses,
			$ignored_sources,
			array_map(
				function ( $ignored_source ) {
					return '%' . $ignored_source . '%';
				},
				$ignored_sources
			)
		);
		if ( '' !== $stripe_customer_id ) {
			$ledger_sql .= ' AND stripe_customer_id = %s';
			$ledger_params[] = $stripe_customer_id;
		}
		$wpdb->query( $wpdb->prepare( $ledger_sql, $ledger_params ) );

		$service_charge_sql = "DELETE FROM {$ledger_table} WHERE source_type = %s AND status IN ({$status_placeholders}) AND metadata LIKE %s";
		$service_charge_params = array_merge(
			array( 'service_charge' ),
			$unpaid_statuses,
			array( '%"snapshot_source_type":"checkout_session"%' )
		);
		if ( '' !== $stripe_customer_id ) {
			$service_charge_sql .= ' AND stripe_customer_id = %s';
			$service_charge_params[] = $stripe_customer_id;
		}
		$wpdb->query( $wpdb->prepare( $service_charge_sql, $service_charge_params ) );

		$snapshot_sql = "DELETE FROM {$snapshots_table} WHERE source_type = %s AND status IN ({$status_placeholders})";
		$snapshot_params = array_merge(
			array( 'checkout_session' ),
			$unpaid_statuses
		);
		if ( '' !== $stripe_customer_id ) {
			$snapshot_sql .= ' AND stripe_customer_id = %s';
			$snapshot_params[] = $stripe_customer_id;
		}
		$wpdb->query( $wpdb->prepare( $snapshot_sql, $snapshot_params ) );

		$transaction_sql = "DELETE FROM {$transactions_table} WHERE object_type = %s AND status IN ({$status_placeholders}) AND description IN ({$source_placeholders})";
		$transaction_params = array_merge(
			array( 'checkout_session' ),
			$unpaid_statuses,
			$ignored_sources
		);
		if ( '' !== $stripe_customer_id ) {
			$transaction_sql .= ' AND stripe_customer_id = %s';
			$transaction_params[] = $stripe_customer_id;
		}
		$wpdb->query( $wpdb->prepare( $transaction_sql, $transaction_params ) );
	}

	private function cleanup_unpaid_portal_balance_payment_sessions( $stripe_customer_id = '' ) {
		$this->cleanup_unpaid_portal_checkout_sessions( $stripe_customer_id );
	}

	private function reconcile_portal_balance_payment_session( $session, $customer_id, $payment_intent_id = '' ) {
		global $wpdb;

		if ( empty( $session['metadata']['ledger_ids'] ) ) {
			return 0;
		}

		$session_id        = ! empty( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '';
		$customer_id       = sanitize_text_field( (string) $customer_id );
		$payment_intent_id = sanitize_text_field( (string) $payment_intent_id );
		$ledger_ids        = array_values( array_filter( array_map( 'absint', preg_split( '/[,|]/', (string) $session['metadata']['ledger_ids'] ) ) ) );
		$updated           = 0;

		foreach ( $ledger_ids as $ledger_id ) {
			$ledger_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, metadata FROM {$this->get_portal_ledger_table()} WHERE id = %d AND stripe_customer_id = %s LIMIT 1",
					$ledger_id,
					$customer_id
				)
			);
			if ( ! $ledger_row ) {
				continue;
			}

			$manual_metadata = ! empty( $ledger_row->metadata ) ? json_decode( (string) $ledger_row->metadata, true ) : array();
			if ( ! is_array( $manual_metadata ) ) {
				$manual_metadata = array();
			}

			$manual_metadata['paid_checkout_session_id'] = $session_id;
			if ( '' !== $payment_intent_id ) {
				$manual_metadata['paid_payment_intent_id'] = $payment_intent_id;
			}
			if ( ! empty( $session['amount_total'] ) ) {
				$manual_metadata['paid_checkout_amount_total'] = absint( $session['amount_total'] );
			}

			$result = $wpdb->update(
				$this->get_portal_ledger_table(),
				array(
					'status'            => 'paid',
					'payment_intent_id' => $payment_intent_id,
					'metadata'          => wp_json_encode( $manual_metadata ),
				),
				array( 'id' => $ledger_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $result ) {
				$updated++;
			}
		}

		return $updated;
	}


	private function get_stripe_charge_ledger_status( $charge ) {
		$status          = ! empty( $charge['status'] ) ? sanitize_key( (string) $charge['status'] ) : '';
		$amount          = isset( $charge['amount'] ) ? absint( $charge['amount'] ) : 0;
		$amount_refunded = isset( $charge['amount_refunded'] ) ? absint( $charge['amount_refunded'] ) : 0;

		if ( $amount_refunded > 0 && $amount > 0 ) {
			return $amount_refunded >= $amount || ! empty( $charge['refunded'] ) ? 'refunded' : 'partially_refunded';
		}

		return $status;
	}

	private function get_stripe_charge_refunds( $charge ) {
		$refunds = array();

		if ( ! empty( $charge['refunds']['data'] ) && is_array( $charge['refunds']['data'] ) ) {
			foreach ( $charge['refunds']['data'] as $refund ) {
				if ( is_array( $refund ) && ! empty( $refund['id'] ) ) {
					$refunds[] = $refund;
				}
			}
		}

		if ( empty( $refunds ) && ! empty( $charge['amount_refunded'] ) ) {
			$refunds[] = array(
				'id'      => 'rf_' . sanitize_text_field( (string) $charge['id'] ) . '_synced',
				'amount'  => absint( $charge['amount_refunded'] ),
				'currency'=> ! empty( $charge['currency'] ) ? sanitize_key( (string) $charge['currency'] ) : 'usd',
				'charge'  => sanitize_text_field( (string) $charge['id'] ),
				'status'  => 'succeeded',
				'created' => ! empty( $charge['created'] ) ? absint( $charge['created'] ) : time(),
				'reason'  => ! empty( $charge['refunds']['data'][0]['reason'] ) ? sanitize_text_field( (string) $charge['refunds']['data'][0]['reason'] ) : '',
				'synthetic' => true,
			);
		}

		return $refunds;
	}

	private function upsert_portal_refund_from_charge( $refund, $charge, $customer_id ) {
		$customer_id = sanitize_text_field( (string) $customer_id );
		if ( '' === $customer_id || empty( $refund['id'] ) || empty( $charge['id'] ) ) {
			return 0;
		}

		$currency = ! empty( $refund['currency'] ) ? strtolower( sanitize_key( (string) $refund['currency'] ) ) : ( ! empty( $charge['currency'] ) ? strtolower( sanitize_key( (string) $charge['currency'] ) ) : 'usd' );
		$status   = ! empty( $refund['status'] ) ? sanitize_key( (string) $refund['status'] ) : 'succeeded';
		if ( 'pending' === $status ) {
			$status = 'pending';
		} elseif ( in_array( $status, array( 'failed', 'canceled', 'cancelled' ), true ) ) {
			$status = 'failed';
		} else {
			$status = 'succeeded';
		}

		$refund_id = sanitize_text_field( (string) $refund['id'] );
		$charge_id = sanitize_text_field( (string) $charge['id'] );
		$description = sprintf( __( 'Refund for charge %s', 'ajforms' ), $charge_id );
		if ( ! empty( $refund['reason'] ) ) {
			$description .= ': ' . sanitize_text_field( (string) $refund['reason'] );
		}

		$data = array(
			'stripe_object_id'   => $refund_id,
			'object_type'        => 'refund',
			'stripe_customer_id' => $customer_id,
			'invoice_id'         => ! empty( $charge['invoice'] ) ? sanitize_text_field( (string) $charge['invoice'] ) : '',
			'payment_intent_id'  => ! empty( $charge['payment_intent'] ) ? sanitize_text_field( (string) $charge['payment_intent'] ) : '',
			'charge_id'          => $charge_id,
			'description'        => $description,
			'amount'             => $this->stripe_amount_to_decimal( isset( $refund['amount'] ) ? $refund['amount'] : 0, $currency ),
			'currency'           => $currency,
			'status'             => $status,
			'transaction_date'   => ! empty( $refund['created'] ) ? $this->stripe_timestamp_to_mysql( $refund['created'] ) : current_time( 'mysql' ),
			'due_date'           => null,
			'raw_data'           => wp_json_encode( $refund ),
			'livemode'           => isset( $refund['livemode'] ) ? ( ! empty( $refund['livemode'] ) ? 1 : 0 ) : ( isset( $charge['livemode'] ) ? ( ! empty( $charge['livemode'] ) ? 1 : 0 ) : $this->get_current_stripe_livemode() ),
			'synced_at'          => current_time( 'mysql' ),
		);

		$metadata = array(
			'charge_id'          => $charge_id,
			'payment_intent_id'  => $data['payment_intent_id'],
			'invoice_id'         => $data['invoice_id'],
			'original_charge_amount' => $this->stripe_amount_to_decimal( isset( $charge['amount'] ) ? $charge['amount'] : 0, $currency ),
			'amount_refunded_total'  => $this->stripe_amount_to_decimal( isset( $charge['amount_refunded'] ) ? $charge['amount_refunded'] : 0, $currency ),
			'synthetic'          => ! empty( $refund['synthetic'] ),
		);

		$transaction_upserted = $this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ), 'stripe_object_id' );
		$ledger_upserted = $this->upsert_portal_record(
			$this->get_portal_ledger_table(),
			array(
				'stripe_customer_id' => $data['stripe_customer_id'],
				'source_object_id'   => $data['stripe_object_id'],
				'source_type'        => 'refund',
				'ledger_date'        => $data['transaction_date'],
				'description'        => $data['description'],
				'amount'             => $data['amount'],
				'currency'           => $data['currency'],
				'status'             => $data['status'],
				'invoice_id'         => $data['invoice_id'],
				'payment_intent_id'  => $data['payment_intent_id'],
				'charge_id'          => $data['charge_id'],
				'metadata'           => wp_json_encode( $metadata ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			'source_object_id'
		);

		return $transaction_upserted && $ledger_upserted ? 1 : 0;
	}

	private function sync_portal_stripe_transactions( $secret_key, $stripe_customer_id = '' ) {
		global $wpdb;

		$this->cleanup_unpaid_portal_checkout_sessions( $stripe_customer_id );

		$invoice_args = array();
		if ( '' !== $stripe_customer_id ) {
			$invoice_args['customer'] = sanitize_text_field( $stripe_customer_id );
		}

		$invoices = $this->stripe_api_get_all( 'invoices', $secret_key, $invoice_args );
		if ( is_wp_error( $invoices ) ) {
			return $invoices;
		}

		$count = 0;
		foreach ( $invoices as $invoice ) {
			if ( empty( $invoice['id'] ) || empty( $invoice['customer'] ) ) {
				continue;
			}

			$currency = isset( $invoice['currency'] ) ? strtolower( sanitize_key( $invoice['currency'] ) ) : 'usd';
			$line_summary = $this->get_invoice_line_summary( $invoice );
			$invoice_amount = isset( $invoice['total'] ) ? $invoice['total'] : ( isset( $invoice['amount_paid'] ) ? $invoice['amount_paid'] : ( isset( $invoice['amount_due'] ) ? $invoice['amount_due'] : 0 ) );
			$data = array(
				'stripe_object_id'   => sanitize_text_field( (string) $invoice['id'] ),
				'object_type'        => 'invoice',
				'stripe_customer_id' => sanitize_text_field( (string) $invoice['customer'] ),
				'invoice_id'         => sanitize_text_field( (string) $invoice['id'] ),
				'payment_intent_id'  => ! empty( $invoice['payment_intent'] ) ? sanitize_text_field( (string) $invoice['payment_intent'] ) : '',
				'charge_id'          => ! empty( $invoice['charge'] ) ? sanitize_text_field( (string) $invoice['charge'] ) : '',
				'description'        => ! empty( $invoice['description'] ) ? sanitize_text_field( (string) $invoice['description'] ) : ( ! empty( $line_summary['description'] ) ? $line_summary['description'] : sprintf( __( 'Invoice %s', 'ajforms' ), $invoice['id'] ) ),
				'amount'             => $this->stripe_amount_to_decimal( $invoice_amount, $currency ),
				'currency'           => $currency,
				'status'             => ! empty( $invoice['status'] ) ? sanitize_key( (string) $invoice['status'] ) : '',
				'transaction_date'   => ! empty( $invoice['created'] ) ? $this->stripe_timestamp_to_mysql( $invoice['created'] ) : null,
				'due_date'           => ! empty( $invoice['due_date'] ) ? $this->stripe_timestamp_to_mysql( $invoice['due_date'] ) : null,
				'raw_data'           => wp_json_encode( $invoice ),
				'livemode'           => ! empty( $invoice['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
				'synced_at'          => current_time( 'mysql' ),
			);

			$transaction_upserted = $this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ), 'stripe_object_id' );
			$ledger_upserted = $this->upsert_portal_record(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $data['stripe_customer_id'],
					'source_object_id'   => $data['stripe_object_id'],
					'source_type'        => 'invoice',
					'ledger_date'        => $data['transaction_date'],
					'description'        => $data['description'],
					'amount'             => $data['amount'],
					'currency'           => $data['currency'],
					'status'             => $data['status'],
					'invoice_id'         => $data['invoice_id'],
					'payment_intent_id'  => $data['payment_intent_id'],
					'charge_id'          => $data['charge_id'],
					'metadata'           => wp_json_encode( $line_summary ),
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
			if ( $transaction_upserted && $ledger_upserted ) {
				$this->maybe_create_portal_service_snapshots_from_stripe_object( $invoice, 'invoice' );
				$count++;
			}
		}

		$charge_args = array();
		if ( '' !== $stripe_customer_id ) {
			$charge_args['customer'] = sanitize_text_field( $stripe_customer_id );
		}

		$charges = $this->stripe_api_get_all( 'charges', $secret_key, $charge_args );
		if ( is_wp_error( $charges ) ) {
			return $charges;
		}

		foreach ( $charges as $charge ) {
			if ( empty( $charge['id'] ) ) {
				continue;
			}

			$customer_id = $this->get_payment_customer_id( $charge );
			if ( ! $this->is_real_stripe_customer_id( $customer_id ) ) {
				$this->record_portal_sync_item( 'skipped', 'charge', $charge['id'], 'skipped', __( 'Skipped guest charge because portal transaction sync now imports Stripe Customer records only.', 'ajforms' ), $charge );
				continue;
			}

			$currency = isset( $charge['currency'] ) ? strtolower( sanitize_key( $charge['currency'] ) ) : 'usd';
			$charge_status = $this->get_stripe_charge_ledger_status( $charge );
			$charge_ledger_metadata = array(
				'refunded'              => ! empty( $charge['refunded'] ),
				'amount_refunded'       => $this->stripe_amount_to_decimal( isset( $charge['amount_refunded'] ) ? $charge['amount_refunded'] : 0, $currency ),
				'original_charge_amount'=> $this->stripe_amount_to_decimal( isset( $charge['amount'] ) ? $charge['amount'] : 0, $currency ),
			);
			$data = array(
				'stripe_object_id'   => sanitize_text_field( (string) $charge['id'] ),
				'object_type'        => 'charge',
				'stripe_customer_id' => $customer_id,
				'invoice_id'         => ! empty( $charge['invoice'] ) ? sanitize_text_field( (string) $charge['invoice'] ) : '',
				'payment_intent_id'  => ! empty( $charge['payment_intent'] ) ? sanitize_text_field( (string) $charge['payment_intent'] ) : '',
				'charge_id'          => sanitize_text_field( (string) $charge['id'] ),
				'description'        => ! empty( $charge['description'] ) ? sanitize_text_field( (string) $charge['description'] ) : sprintf( __( 'Charge %s', 'ajforms' ), $charge['id'] ),
				'amount'             => $this->stripe_amount_to_decimal( isset( $charge['amount'] ) ? $charge['amount'] : 0, $currency ),
				'currency'           => $currency,
				'status'             => $charge_status,
				'transaction_date'   => ! empty( $charge['created'] ) ? $this->stripe_timestamp_to_mysql( $charge['created'] ) : null,
				'due_date'           => null,
				'raw_data'           => wp_json_encode( $charge ),
				'livemode'           => ! empty( $charge['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
				'synced_at'          => current_time( 'mysql' ),
			);

			$transaction_upserted = $this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ), 'stripe_object_id' );
			$ledger_upserted = $this->upsert_portal_record(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $data['stripe_customer_id'],
					'source_object_id'   => $data['stripe_object_id'],
					'source_type'        => 'charge',
					'ledger_date'        => $data['transaction_date'],
					'description'        => $data['description'],
					'amount'             => $data['amount'],
					'currency'           => $data['currency'],
					'status'             => $data['status'],
					'invoice_id'         => $data['invoice_id'],
					'payment_intent_id'  => $data['payment_intent_id'],
					'charge_id'          => $data['charge_id'],
					'metadata'           => wp_json_encode( $charge_ledger_metadata ),
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
			if ( $transaction_upserted && $ledger_upserted ) {
				$this->maybe_create_portal_service_snapshots_from_stripe_object( $charge, 'charge' );
				$count++;
			}

			foreach ( $this->get_stripe_charge_refunds( $charge ) as $refund ) {
				$count += $this->upsert_portal_refund_from_charge( $refund, $charge, $customer_id );
			}
		}

		$sessions = $this->stripe_api_get_all( 'checkout/sessions', $secret_key );
		if ( is_wp_error( $sessions ) ) {
			return $sessions;
		}

		foreach ( $sessions as $session ) {
			if ( empty( $session['id'] ) ) {
				continue;
			}

			$session = $this->enrich_checkout_session_with_line_items( $session, $secret_key );
			$customer_id = $this->get_payment_customer_id( $session );
			if ( ! $this->is_real_stripe_customer_id( $customer_id ) ) {
				$this->record_portal_sync_item( 'skipped', 'checkout_session', $session['id'], 'skipped', __( 'Skipped guest checkout session because portal transaction sync now imports Stripe Customer records only.', 'ajforms' ), $session );
				continue;
			}

			$this->update_portal_customer_checkout_custom_fields( $customer_id, $session );
			$checkout_custom_fields = $this->extract_checkout_custom_fields( $session );

			$payment_intent_id = ! empty( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : '';
			$currency = isset( $session['currency'] ) ? strtolower( sanitize_key( $session['currency'] ) ) : 'usd';
			$status   = ! empty( $session['payment_status'] ) ? sanitize_key( (string) $session['payment_status'] ) : '';
			$session_source = ! empty( $session['metadata']['source'] ) ? sanitize_key( (string) $session['metadata']['source'] ) : '';
			$is_balance_payment_session = 'ajcore_portal_balance_payment' === $session_source;
			$is_ignored_unpaid_checkout = '' !== $session_source && in_array( $session_source, $this->get_ignored_unpaid_checkout_sources(), true ) && 'paid' !== $status;

			if ( $is_ignored_unpaid_checkout ) {
				$this->cleanup_unpaid_portal_checkout_sessions( $customer_id );
				continue;
			}

			/*
			 * Balance-payment checkout sessions are intentionally reconciled before the
			 * generic duplicate-payment-intent skip below. Stripe charges are synced before
			 * checkout sessions in this method, so the related charge can already exist in
			 * aj_portal_stripe_transactions. If we skip here too early, the original
			 * manual ledger rows remain pending even though the checkout was paid.
			 */
			if ( $is_balance_payment_session ) {
				if ( 'paid' === $status ) {
					$count += $this->reconcile_portal_balance_payment_session( $session, $customer_id, $payment_intent_id );
				}

				$this->cleanup_unpaid_portal_checkout_sessions( $customer_id );
				continue;
			}

			if ( '' !== $payment_intent_id ) {
				$existing_payment = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$this->get_portal_stripe_transactions_table()} WHERE payment_intent_id = %s LIMIT 1",
						$payment_intent_id
					)
				);
				if ( $existing_payment ) {
					$this->upsert_portal_checkout_session_transaction_cache( $session, $customer_id );
					if ( 'paid' === $status ) {
						$this->maybe_create_portal_service_snapshots_from_stripe_object( $session, 'checkout_session' );
					}
					continue;
				}
			}

			$session_ledger_metadata = array();
			if ( ! empty( $checkout_custom_fields ) ) {
				$session_ledger_metadata['checkout_custom_fields'] = $checkout_custom_fields;
				foreach ( $checkout_custom_fields as $field_key => $field_value ) {
					$session_ledger_metadata[ $field_key ] = $field_value;
				}
			}
			$data     = array(
				'stripe_object_id'   => sanitize_text_field( (string) $session['id'] ),
				'object_type'        => 'checkout_session',
				'stripe_customer_id' => $customer_id,
				'invoice_id'         => ! empty( $session['invoice'] ) ? sanitize_text_field( (string) $session['invoice'] ) : '',
				'payment_intent_id'  => $payment_intent_id,
				'charge_id'          => '',
				'description'        => ! empty( $session['metadata']['source'] ) ? sanitize_text_field( (string) $session['metadata']['source'] ) : sprintf( __( 'Checkout session %s', 'ajforms' ), $session['id'] ),
				'amount'             => $this->stripe_amount_to_decimal( isset( $session['amount_total'] ) ? $session['amount_total'] : 0, $currency ),
				'currency'           => $currency,
				'status'             => $status,
				'transaction_date'   => ! empty( $session['created'] ) ? $this->stripe_timestamp_to_mysql( $session['created'] ) : null,
				'due_date'           => null,
				'raw_data'           => wp_json_encode( $session ),
				'livemode'           => ! empty( $session['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
				'synced_at'          => current_time( 'mysql' ),
			);

			$transaction_upserted = $this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ), 'stripe_object_id' );
			$ledger_upserted = $this->upsert_portal_record(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $data['stripe_customer_id'],
					'source_object_id'   => $data['stripe_object_id'],
					'source_type'        => 'checkout_session',
					'ledger_date'        => $data['transaction_date'],
					'description'        => $data['description'],
					'amount'             => $data['amount'],
					'currency'           => $data['currency'],
					'status'             => $data['status'],
					'invoice_id'         => $data['invoice_id'],
					'payment_intent_id'  => $data['payment_intent_id'],
					'charge_id'          => $data['charge_id'],
					'metadata'           => ! empty( $session_ledger_metadata ) ? wp_json_encode( $session_ledger_metadata ) : '',
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
			if ( $transaction_upserted && $ledger_upserted ) {
				$this->maybe_create_portal_service_snapshots_from_stripe_object( $session, 'checkout_session' );
				$count++;
			}
		}

		$count += $this->backfill_portal_ledger_service_charges_from_snapshots( $stripe_customer_id );

		return $this->portal_db_error ? new WP_Error( 'portal_db_error', $this->portal_db_error ) : $count;
	}


	private function get_portal_sync_jobs() {
		return array(
			'products'      => __( 'Stripe Products', 'ajforms' ),
			'customers'     => __( 'Stripe Customers', 'ajforms' ),
			'subscriptions' => __( 'Stripe Subscriptions', 'ajforms' ),
			'transactions'  => __( 'Stripe Invoices / Charges', 'ajforms' ),
		);
	}

	private function get_portal_sync_settings() {
		$settings = get_option( 'ajcore_portal_sync_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$jobs     = $this->get_portal_sync_jobs();
		$selected = isset( $settings['jobs'] ) && is_array( $settings['jobs'] ) ? array_map( 'sanitize_key', $settings['jobs'] ) : array_keys( $jobs );
		$selected = array_values( array_intersect( $selected, array_keys( $jobs ) ) );
		if ( empty( $selected ) ) {
			$selected = array_keys( $jobs );
		}

		$frequency = ! empty( $settings['frequency'] ) ? sanitize_key( (string) $settings['frequency'] ) : 'daily';
		$allowed   = array( 'ajcore_every_15_minutes', 'ajcore_every_30_minutes', 'hourly', 'ajcore_every_6_hours', 'twicedaily', 'daily', 'ajcore_weekly' );
		if ( ! in_array( $frequency, $allowed, true ) ) {
			$frequency = 'daily';
		}

		return array(
			'enabled'   => ! empty( $settings['enabled'] ),
			'frequency' => $frequency,
			'jobs'      => $selected,
		);
	}

	private function get_portal_sync_frequency_labels() {
		return array(
			'ajcore_every_15_minutes' => __( 'Every 15 minutes', 'ajforms' ),
			'ajcore_every_30_minutes' => __( 'Every 30 minutes', 'ajforms' ),
			'hourly'                  => __( 'Hourly', 'ajforms' ),
			'ajcore_every_6_hours'    => __( 'Every 6 hours', 'ajforms' ),
			'twicedaily'              => __( 'Twice daily', 'ajforms' ),
			'daily'                   => __( 'Daily', 'ajforms' ),
			'ajcore_weekly'           => __( 'Weekly', 'ajforms' ),
		);
	}

	private function get_portal_sync_next_run_label() {
		$timestamp = wp_next_scheduled( 'ajcore_portal_stripe_sync' );
		if ( ! $timestamp ) {
			return __( 'Not scheduled', 'ajforms' );
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private function get_portal_sync_reconciliation() {
		global $wpdb;

		$this->ensure_portal_schema();

		$unmatched_payments = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->get_portal_ledger_table()} l
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = l.stripe_customer_id
			WHERE l.source_type IN ('charge','payment','checkout_session') AND (l.stripe_customer_id = '' OR c.id IS NULL)"
		);
		$unmatched_refunds = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->get_portal_ledger_table()} r
			LEFT JOIN {$this->get_portal_ledger_table()} c ON c.charge_id = r.charge_id AND c.source_type IN ('charge','payment')
			WHERE r.source_type = 'refund' AND (r.charge_id = '' OR c.id IS NULL)"
		);
		$missing_users = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->get_portal_stripe_customers_table()} c
			LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id
			LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
			WHERE c.enabled_portal = 1 AND (m.id IS NULL OR u.ID IS NULL)"
		);
		$failed_logs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_sync_logs_table()} WHERE status = 'failed'" );
		$settings = $this->get_plugin_settings();
		$key_warnings = function_exists( 'ajcore_get_stripe_mode_issues' ) ? ajcore_get_stripe_mode_issues( $settings, true ) : array();
		if ( empty( $settings['stripe_secret_key'] ) ) {
			$key_warnings[] = __( 'Stripe secret key is missing for the currently selected mode.', 'ajforms' );
		}

		return array(
			'unmatched_payments' => $unmatched_payments,
			'unmatched_refunds'  => $unmatched_refunds,
			'missing_users'      => $missing_users,
			'failed'             => $failed_logs,
			'key_warnings'       => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $key_warnings ) ) ) ),
		);
	}

	private function reset_portal_sync_cache() {
		global $wpdb;

		$this->ensure_portal_schema();

		$tables = array(
			$this->get_portal_user_mappings_table(),
			$this->get_portal_entity_mappings_table(),
			$this->get_portal_ledger_table(),
			$this->get_portal_stripe_transactions_table(),
			$this->get_portal_stripe_subscriptions_table(),
			$this->get_portal_service_snapshots_table(),
			$this->get_portal_stripe_products_table(),
			$this->get_portal_stripe_customers_table(),
			$this->get_portal_sync_log_items_table(),
			$this->get_portal_sync_logs_table(),
		);

		$deleted = 0;
		foreach ( $tables as $table ) {
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$result = $wpdb->query( "DELETE FROM {$table}" );
			if ( false === $result ) {
				return new WP_Error( 'portal_reset_failed', sprintf( __( 'Could not reset table %s.', 'ajforms' ), $table ) );
			}
			$deleted += absint( $count );
		}

		delete_option( 'ajcore_portal_sync_last_run' );
		delete_option( 'ajforms_last_portal_db_error' );

		return $deleted;
	}

	private function get_portal_sync_message( $message, $stats = array() ) {
		$stats = is_array( $stats ) ? $stats : array();

		return wp_json_encode(
			array(
				'summary' => sanitize_textarea_field( (string) $message ),
				'stats'   => $stats,
			)
		);
	}

	private function decode_portal_sync_message( $message ) {
		$decoded = ! empty( $message ) ? json_decode( (string) $message, true ) : array();
		if ( is_array( $decoded ) && isset( $decoded['summary'] ) ) {
			return $decoded;
		}

		return array(
			'summary' => (string) $message,
			'stats'   => array(),
		);
	}

	private function write_portal_sync_log( $run_key, $source, $job_name, $status, $records_synced = 0, $message = '', $log_id = 0 ) {
		global $wpdb;

		$data = array(
			'run_key'        => sanitize_text_field( (string) $run_key ),
			'source'         => sanitize_key( (string) $source ),
			'job_name'       => sanitize_key( (string) $job_name ),
			'status'         => sanitize_key( (string) $status ),
			'records_synced' => absint( $records_synced ),
			'message'        => sanitize_textarea_field( (string) $message ),
			'created_by'     => get_current_user_id(),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%d' );

		if ( $log_id ) {
			$data['finished_at'] = current_time( 'mysql' );
			$formats[] = '%s';
			$wpdb->update( $this->get_portal_sync_logs_table(), $data, array( 'id' => absint( $log_id ) ), $formats, array( '%d' ) );
			return absint( $log_id );
		}

		$data['started_at'] = current_time( 'mysql' );
		$formats[] = '%s';
		$wpdb->insert( $this->get_portal_sync_logs_table(), $data, $formats );

		return (int) $wpdb->insert_id;
	}

	private function get_stripe_event_object_id( $event ) {
		$object = ! empty( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		return ! empty( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '';
	}

	private function get_stripe_event_account_id( $event ) {
		if ( ! empty( $event['account'] ) ) {
			return sanitize_text_field( (string) $event['account'] );
		}
		if ( ! empty( $event['context'] ) ) {
			return sanitize_text_field( (string) $event['context'] );
		}

		return '';
	}

	private function get_processed_stripe_event( $event_id ) {
		global $wpdb;

		$event_id = sanitize_text_field( (string) $event_id );
		if ( '' === $event_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_events_table()} WHERE event_id = %s LIMIT 1",
				$event_id
			)
		);
	}

	private function record_stripe_event_received( $event ) {
		global $wpdb;

		$event_id = ! empty( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		if ( '' === $event_id ) {
			return new WP_Error( 'missing_event_id', __( 'Stripe event ID is missing.', 'ajforms' ) );
		}

		$event_type     = ! empty( $event['type'] ) ? sanitize_text_field( (string) $event['type'] ) : '';
		$livemode       = ! empty( $event['livemode'] ) ? 1 : 0;
		$stripe_account = $this->get_stripe_event_account_id( $event );
		$object_id      = $this->get_stripe_event_object_id( $event );
		$existing       = $this->get_processed_stripe_event( $event_id );

		if ( $existing ) {
			$wpdb->update(
				$this->get_portal_stripe_events_table(),
				array(
					'attempts'  => (int) $existing->attempts + 1,
					'raw_event' => wp_json_encode( $event ),
				),
				array( 'event_id' => $event_id ),
				array( '%d', '%s' ),
				array( '%s' )
			);
			return $this->get_processed_stripe_event( $event_id );
		}

		$wpdb->insert(
			$this->get_portal_stripe_events_table(),
			array(
				'event_id'          => $event_id,
				'event_type'        => $event_type,
				'livemode'          => $livemode,
				'stripe_account'    => $stripe_account,
				'object_id'         => $object_id,
				'processing_status' => 'received',
				'first_seen_at'     => current_time( 'mysql' ),
				'processed_at'      => null,
				'attempts'          => 1,
				'last_error'        => '',
				'raw_event'         => wp_json_encode( $event ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $this->get_processed_stripe_event( $event_id );
	}

	private function mark_stripe_event_processing( $event_id ) {
		global $wpdb;

		return $wpdb->update(
			$this->get_portal_stripe_events_table(),
			array( 'processing_status' => 'processing' ),
			array( 'event_id' => sanitize_text_field( (string) $event_id ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	private function mark_stripe_event_processed( $event_id ) {
		global $wpdb;

		return $wpdb->update(
			$this->get_portal_stripe_events_table(),
			array(
				'processing_status' => 'processed',
				'processed_at'      => current_time( 'mysql' ),
				'last_error'        => '',
			),
			array( 'event_id' => sanitize_text_field( (string) $event_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	private function mark_stripe_event_failed( $event_id, $error_message ) {
		global $wpdb;

		return $wpdb->update(
			$this->get_portal_stripe_events_table(),
			array(
				'processing_status' => 'failed',
				'processed_at'      => current_time( 'mysql' ),
				'last_error'        => sanitize_textarea_field( (string) $error_message ),
			),
			array( 'event_id' => sanitize_text_field( (string) $event_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	private function fetch_current_stripe_webhook_object( $type, $object, $secret_key ) {
		$object_id = ! empty( $object['id'] ) ? sanitize_text_field( (string) $object['id'] ) : '';
		if ( '' === $object_id ) {
			return $object;
		}

		if ( 0 === strpos( $type, 'checkout.session.' ) ) {
			$session = $this->stripe_api_get( 'checkout/sessions/' . rawurlencode( $object_id ), $secret_key );
			if ( is_wp_error( $session ) ) {
				return $session;
			}

			return $this->enrich_checkout_session_with_line_items( $session, $secret_key );
		}

		if ( 0 === strpos( $type, 'invoice.' ) ) {
			return $this->stripe_api_get( 'invoices/' . rawurlencode( $object_id ), $secret_key );
		}

		if ( 0 === strpos( $type, 'customer.subscription.' ) ) {
			return $this->stripe_api_get(
				'subscriptions/' . rawurlencode( $object_id ),
				$secret_key,
				array( 'expand[]' => 'items.data.price' )
			);
		}

		if ( 0 === strpos( $type, 'charge.' ) ) {
			return $this->stripe_api_get( 'charges/' . rawurlencode( $object_id ), $secret_key );
		}

		return $object;
	}

	private function get_scalar_stripe_id( $value, $prefix = '' ) {
		if ( is_array( $value ) && ! empty( $value['id'] ) ) {
			$value = $value['id'];
		}

		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		if ( '' !== $prefix && 0 !== strpos( $value, $prefix ) ) {
			return '';
		}

		return $value;
	}

	private function get_deferred_one_time_price_ids_from_checkout_session( $session ) {
		$metadata = ! empty( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$raw      = ! empty( $metadata['ajcore_one_time_price_ids'] ) ? sanitize_text_field( (string) $metadata['ajcore_one_time_price_ids'] ) : '';
		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter(
				array_unique(
					array_map(
						'sanitize_text_field',
						array_map( 'trim', explode( ',', $raw ) )
					)
				)
			)
		);
	}

	private function get_deferred_one_time_payment_method_from_subscription( $subscription_id, $customer_id, $secret_key ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );
		$customer_id     = sanitize_text_field( (string) $customer_id );
		if ( '' === $subscription_id ) {
			return '';
		}

		$subscription = $this->stripe_api_get(
			'subscriptions/' . rawurlencode( $subscription_id ),
			$secret_key,
			array( 'expand[]' => 'latest_invoice.payment_intent' )
		);
		if ( is_wp_error( $subscription ) ) {
			return '';
		}

		$payment_method = $this->get_scalar_stripe_id( isset( $subscription['default_payment_method'] ) ? $subscription['default_payment_method'] : '', 'pm_' );
		if ( '' !== $payment_method ) {
			return $payment_method;
		}

		$latest_invoice = ! empty( $subscription['latest_invoice'] ) && is_array( $subscription['latest_invoice'] ) ? $subscription['latest_invoice'] : array();
		$payment_intent = ! empty( $latest_invoice['payment_intent'] ) && is_array( $latest_invoice['payment_intent'] ) ? $latest_invoice['payment_intent'] : array();
		$payment_method = $this->get_scalar_stripe_id( isset( $payment_intent['payment_method'] ) ? $payment_intent['payment_method'] : '', 'pm_' );
		if ( '' !== $payment_method ) {
			return $payment_method;
		}

		$latest_invoice_id = empty( $latest_invoice ) && ! empty( $subscription['latest_invoice'] ) ? $this->get_scalar_stripe_id( $subscription['latest_invoice'], 'in_' ) : '';
		if ( '' !== $latest_invoice_id ) {
			$invoice = $this->stripe_api_get(
				'invoices/' . rawurlencode( $latest_invoice_id ),
				$secret_key,
				array( 'expand[]' => 'payment_intent' )
			);
			if ( ! is_wp_error( $invoice ) && ! empty( $invoice['payment_intent'] ) && is_array( $invoice['payment_intent'] ) ) {
				$payment_method = $this->get_scalar_stripe_id( isset( $invoice['payment_intent']['payment_method'] ) ? $invoice['payment_intent']['payment_method'] : '', 'pm_' );
				if ( '' !== $payment_method ) {
					return $payment_method;
				}
			}
		}

		if ( '' !== $customer_id ) {
			$customer = $this->stripe_api_get( 'customers/' . rawurlencode( $customer_id ), $secret_key );
			if ( ! is_wp_error( $customer ) && ! empty( $customer['invoice_settings']['default_payment_method'] ) ) {
				return $this->get_scalar_stripe_id( $customer['invoice_settings']['default_payment_method'], 'pm_' );
			}
		}

		return '';
	}

	private function create_deferred_one_time_service_snapshots_from_checkout( $session, $payment_intent, $price_ids ) {
		$count       = 0;
		$customer_id = ! empty( $session['customer'] ) && is_scalar( $session['customer'] ) ? sanitize_text_field( (string) $session['customer'] ) : '';
		$currency    = ! empty( $payment_intent['currency'] ) ? strtolower( sanitize_key( (string) $payment_intent['currency'] ) ) : ( ! empty( $session['currency'] ) ? strtolower( sanitize_key( (string) $session['currency'] ) ) : 'usd' );
		$status      = ! empty( $payment_intent['status'] ) ? sanitize_key( (string) $payment_intent['status'] ) : 'succeeded';
		$parent      = array(
			'id'             => ! empty( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '',
			'customer'       => $customer_id,
			'payment_intent' => ! empty( $payment_intent['id'] ) ? sanitize_text_field( (string) $payment_intent['id'] ) : '',
			'status'         => in_array( $status, array( 'succeeded', 'processing' ), true ) ? $status : 'paid',
			'payment_status' => 'succeeded' === $status ? 'paid' : $status,
			'currency'       => $currency,
			'livemode'       => ! empty( $payment_intent['livemode'] ) || ! empty( $session['livemode'] ),
			'metadata'       => ! empty( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array(),
		);

		foreach ( $price_ids as $price_id ) {
			$price_id = sanitize_text_field( (string) $price_id );
			if ( '' === $price_id ) {
				continue;
			}

			$product = $this->get_portal_product_by_price_id( $price_id );
			if ( ! $product ) {
				continue;
			}

			$item_currency = ! empty( $product->currency ) ? strtolower( sanitize_key( (string) $product->currency ) ) : $currency;
			$amount_minor  = $this->stripe_decimal_to_minor_units( isset( $product->price_amount ) ? (float) $product->price_amount : 0, $item_currency );
			$item          = array(
				'price'        => array(
					'id'          => $price_id,
					'currency'    => $item_currency,
					'unit_amount' => $amount_minor,
					'product'     => array(
						'id'   => ! empty( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
						'name' => ! empty( $product->name ) ? sanitize_text_field( (string) $product->name ) : '',
					),
				),
				'quantity'     => 1,
				'amount_total' => $amount_minor,
				'currency'     => $item_currency,
				'description'  => ! empty( $product->name ) ? sanitize_text_field( (string) $product->name ) : $price_id,
			);

			$count += $this->maybe_create_portal_service_snapshot_from_line_item( $item, $parent, 'checkout_session' ) ? 1 : 0;
		}

		if ( $count && '' !== $customer_id ) {
			$this->backfill_portal_ledger_service_charges_from_snapshots( $customer_id );
		}

		return $count;
	}

	private function maybe_charge_deferred_one_time_items_for_checkout_session( $session, $secret_key ) {
		if ( empty( $session['id'] ) || empty( $session['subscription'] ) || empty( $session['customer'] ) ) {
			return 0;
		}

		$price_ids = $this->get_deferred_one_time_price_ids_from_checkout_session( $session );
		if ( empty( $price_ids ) ) {
			return 0;
		}

		global $wpdb;

		$session_id  = sanitize_text_field( (string) $session['id'] );
		$customer_id = $this->get_scalar_stripe_id( $session['customer'], 'cus_' );
		if ( '' === $customer_id ) {
			return new WP_Error( 'missing_customer', __( 'Unable to charge one-time cart items because the checkout session customer is missing.', 'ajforms' ) );
		}
		$existing    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->get_portal_ledger_table()} WHERE source_object_id = %s OR metadata LIKE %s LIMIT 1",
				'mixed_one_time_' . $session_id,
				'%' . $wpdb->esc_like( $session_id ) . '%ajcore_mixed_one_time_payment%'
			)
		);
		if ( $existing ) {
			return 0;
		}

		$metadata = ! empty( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		$amount   = ! empty( $metadata['ajcore_one_time_amount'] ) ? absint( $metadata['ajcore_one_time_amount'] ) : 0;
		$currency = ! empty( $metadata['ajcore_one_time_currency'] ) ? strtolower( sanitize_key( (string) $metadata['ajcore_one_time_currency'] ) ) : ( ! empty( $session['currency'] ) ? strtolower( sanitize_key( (string) $session['currency'] ) ) : 'usd' );
		if ( $amount <= 0 ) {
			foreach ( $price_ids as $price_id ) {
				$product = $this->get_portal_product_by_price_id( $price_id );
				if ( $product ) {
					$currency = ! empty( $product->currency ) ? strtolower( sanitize_key( (string) $product->currency ) ) : $currency;
					$amount  += $this->stripe_decimal_to_minor_units( isset( $product->price_amount ) ? (float) $product->price_amount : 0, $currency );
				}
			}
		}
		if ( $amount <= 0 ) {
			return 0;
		}

		$subscription_id = $this->get_scalar_stripe_id( $session['subscription'], 'sub_' );
		if ( '' === $subscription_id ) {
			return new WP_Error( 'missing_subscription', __( 'Unable to charge one-time cart items because the checkout session subscription is missing.', 'ajforms' ) );
		}
		$payment_method = $this->get_deferred_one_time_payment_method_from_subscription( $subscription_id, $customer_id, $secret_key );
		if ( '' === $payment_method ) {
			return new WP_Error( 'missing_payment_method', __( 'Unable to charge one-time cart items because Stripe did not expose the subscription payment method yet.', 'ajforms' ) );
		}

		$description = sprintf( __( 'One-time items from checkout %s', 'ajforms' ), $session_id );
		$payment_intent = $this->stripe_api_request(
			'payment_intents',
			$secret_key,
			array(
				'amount'                       => $amount,
				'currency'                     => $currency,
				'customer'                     => $customer_id,
				'payment_method'              => $payment_method,
				'confirm'                     => 'true',
				'off_session'                 => 'true',
				'description'                 => $description,
				'metadata[source]'            => 'ajcore_mixed_one_time_payment',
				'metadata[checkout_session_id]' => $session_id,
				'metadata[subscription_id]'    => $subscription_id,
				'metadata[stripe_customer_id]' => $customer_id,
				'metadata[price_ids]'          => implode( ',', $price_ids ),
			)
		);
		if ( is_wp_error( $payment_intent ) ) {
			return $payment_intent;
		}

		$payment_intent_id = ! empty( $payment_intent['id'] ) ? sanitize_text_field( (string) $payment_intent['id'] ) : '';
		$status            = ! empty( $payment_intent['status'] ) ? sanitize_key( (string) $payment_intent['status'] ) : '';
		$amount_decimal    = $this->stripe_amount_to_decimal( $amount, $currency );
		$charge_id         = ! empty( $payment_intent['latest_charge'] ) ? $this->get_scalar_stripe_id( $payment_intent['latest_charge'], 'ch_' ) : '';
		$now               = current_time( 'mysql' );

		if ( '' !== $payment_intent_id ) {
			$this->upsert_portal_record(
				$this->get_portal_stripe_transactions_table(),
				array(
					'stripe_object_id'   => $payment_intent_id,
					'object_type'        => 'payment_intent',
					'stripe_customer_id' => $customer_id,
					'invoice_id'         => '',
					'payment_intent_id'  => $payment_intent_id,
					'charge_id'          => $charge_id,
					'description'        => __( 'One-time cart payment', 'ajforms' ),
					'amount'             => $amount_decimal,
					'currency'           => $currency,
					'status'             => $status,
					'transaction_date'   => $now,
					'due_date'           => null,
					'raw_data'           => wp_json_encode( $payment_intent ),
					'livemode'           => ! empty( $payment_intent['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
					'synced_at'          => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
				'stripe_object_id'
			);
			$this->upsert_portal_record(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $customer_id,
					'source_object_id'   => 'mixed_one_time_' . $session_id,
					'source_type'        => 'payment_intent',
					'ledger_date'        => $now,
					'description'        => __( 'Payment', 'ajforms' ),
					'amount'             => $amount_decimal,
					'currency'           => $currency,
					'status'             => $status,
					'invoice_id'         => '',
					'payment_intent_id'  => $payment_intent_id,
					'charge_id'          => $charge_id,
					'metadata'           => wp_json_encode( array( 'source' => 'ajcore_mixed_one_time_payment', 'checkout_session_id' => $session_id, 'price_ids' => $price_ids ) ),
					'created_at'         => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
		}

		$snapshot_count = $this->create_deferred_one_time_service_snapshots_from_checkout( $session, $payment_intent, $price_ids );
		$this->log_portal_event(
			'mixed_cart_one_time_payment_created',
			array(
				'source'             => 'webhook',
				'stripe_customer_id' => $customer_id,
				'details'            => array(
					'checkout_session_id' => $session_id,
					'subscription_id'     => $subscription_id,
					'payment_intent_id'   => $payment_intent_id,
					'amount'              => $amount_decimal,
					'currency'            => $currency,
					'price_ids'           => $price_ids,
					'snapshots'           => $snapshot_count,
				),
			)
		);

		return 1 + absint( $snapshot_count );
	}

	private function get_checkout_session_metadata_value( $session, $key ) {
		if ( ! is_array( $session ) || empty( $key ) ) {
			return '';
		}

		if ( ! empty( $session['metadata'] ) && is_array( $session['metadata'] ) && isset( $session['metadata'][ $key ] ) && is_scalar( $session['metadata'][ $key ] ) ) {
			return sanitize_text_field( (string) $session['metadata'][ $key ] );
		}

		return '';
	}

	private function process_portal_upgrade_checkout_session( $session, $secret_key, $source = 'webhook' ) {
		global $wpdb;

		if ( ! is_array( $session ) ) {
			return 0;
		}

		$is_upgrade = '1' === $this->get_checkout_session_metadata_value( $session, 'ajcore_upgrade' );
		$old_subscription_id = $this->get_checkout_session_metadata_value( $session, 'ajcore_upgrade_from_subscription_id' );
		if ( ! $is_upgrade || '' === $old_subscription_id || 0 !== strpos( $old_subscription_id, 'sub_' ) ) {
			return 0;
		}

		$status         = ! empty( $session['status'] ) ? sanitize_key( (string) $session['status'] ) : '';
		$payment_status = ! empty( $session['payment_status'] ) ? sanitize_key( (string) $session['payment_status'] ) : '';
		if ( ! in_array( $status, array( 'complete', 'completed' ), true ) && 'paid' !== $payment_status ) {
			return 0;
		}

		$new_subscription_id = '';
		if ( ! empty( $session['subscription'] ) ) {
			if ( is_array( $session['subscription'] ) && ! empty( $session['subscription']['id'] ) ) {
				$new_subscription_id = sanitize_text_field( (string) $session['subscription']['id'] );
			} elseif ( is_string( $session['subscription'] ) ) {
				$new_subscription_id = sanitize_text_field( (string) $session['subscription'] );
			}
		}
		if ( '' !== $new_subscription_id && $new_subscription_id === $old_subscription_id ) {
			return new WP_Error( 'invalid_upgrade_subscription', __( 'Upgrade checkout returned the same subscription as the source subscription.', 'ajforms' ) );
		}

		$old_subscription = $this->stripe_api_get( 'subscriptions/' . rawurlencode( $old_subscription_id ), $secret_key );
		if ( is_wp_error( $old_subscription ) ) {
			return $old_subscription;
		}

		$old_status = ! empty( $old_subscription['status'] ) ? sanitize_key( (string) $old_subscription['status'] ) : '';
		if ( ! in_array( $old_status, array( 'canceled', 'cancelled' ), true ) ) {
			$cancel_result = $this->stripe_api_request( 'subscriptions/' . rawurlencode( $old_subscription_id ), $secret_key, array(), 'DELETE' );
			if ( is_wp_error( $cancel_result ) ) {
				return $cancel_result;
			}
		}

		$wpdb->update(
			$this->get_portal_stripe_subscriptions_table(),
			array(
				'status'    => 'canceled',
				'synced_at' => current_time( 'mysql' ),
			),
			array( 'stripe_subscription_id' => $old_subscription_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		$wpdb->update(
			$this->get_portal_service_snapshots_table(),
			array(
				'status'     => 'canceled',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'subscription_id' => $old_subscription_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		$this->log_portal_event(
			'portal_service_upgraded',
			array(
				'source'             => sanitize_key( (string) $source ),
				'stripe_customer_id' => ! empty( $session['customer'] ) && is_string( $session['customer'] ) ? sanitize_text_field( (string) $session['customer'] ) : '',
				'details'            => array(
					'checkout_session_id'       => ! empty( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '',
					'from_subscription_id'      => $old_subscription_id,
					'to_subscription_id'        => $new_subscription_id,
					'from_product_id'           => $this->get_checkout_session_metadata_value( $session, 'ajcore_upgrade_from_product_id' ),
					'to_product_id'             => $this->get_checkout_session_metadata_value( $session, 'ajcore_upgrade_to_product_id' ),
					'to_price_id'               => $this->get_checkout_session_metadata_value( $session, 'ajcore_upgrade_to_price_id' ),
				),
			)
		);

		return 1;
	}

	public function finalize_mixed_checkout_session_by_id( $session_id, $source = 'manual' ) {
		$this->ensure_portal_schema();

		$session_id = sanitize_text_field( (string) $session_id );
		$source     = sanitize_key( (string) $source );
		if ( 0 !== strpos( $session_id, 'cs_' ) ) {
			return new WP_Error( 'invalid_checkout_session', __( 'Invalid checkout session.', 'ajforms' ) );
		}

		$secret_key = $this->get_stripe_secret_key_for_portal();
		if ( '' === $secret_key ) {
			return new WP_Error( 'missing_stripe_key', __( 'Stripe secret key is required.', 'ajforms' ) );
		}

		$session = $this->stripe_api_get(
			'checkout/sessions/' . rawurlencode( $session_id ),
			$secret_key,
			array(
				'expand[]' => 'subscription',
			)
		);
		if ( is_wp_error( $session ) ) {
			$this->log_portal_event(
				'mixed_cart_one_time_payment_failed',
				array(
					'severity' => 'error',
					'source'   => $source,
					'details'  => array(
						'checkout_session_id' => $session_id,
						'error'               => $session->get_error_message(),
					),
				)
			);
			return $session;
		}

		$session = $this->enrich_checkout_session_with_line_items( $session, $secret_key );
		$result  = $this->maybe_charge_deferred_one_time_items_for_checkout_session( $session, $secret_key );
		if ( is_wp_error( $result ) ) {
			$this->log_portal_event(
				'mixed_cart_one_time_payment_failed',
				array(
					'severity' => 'error',
					'source'   => $source,
					'details'  => array(
						'checkout_session_id' => $session_id,
						'error'               => $result->get_error_message(),
					),
				)
			);
			return $result;
		}

		$upgrade_result = $this->process_portal_upgrade_checkout_session( $session, $secret_key, $source );
		if ( is_wp_error( $upgrade_result ) ) {
			$this->log_portal_event(
				'portal_service_upgrade_failed',
				array(
					'severity' => 'error',
					'source'   => $source,
					'details'  => array(
						'checkout_session_id' => $session_id,
						'error'               => $upgrade_result->get_error_message(),
					),
				)
			);
			return $upgrade_result;
		}

		if ( $result > 0 ) {
			$this->log_portal_event(
				'mixed_cart_one_time_payment_finalized',
				array(
					'source'  => $source,
					'details' => array(
						'checkout_session_id' => $session_id,
						'result'              => $result,
					),
				)
			);
		}

		return absint( $result ) + absint( $upgrade_result );
	}

	public function run_portal_sync_job( $source = 'manual', $requested_jobs = array() ) {
		$this->ensure_portal_schema();

		$secret_key = $this->get_stripe_secret_key_for_portal();
		if ( '' === $secret_key ) {
			return new WP_Error( 'missing_stripe_key', __( 'Stripe secret key is required.', 'ajforms' ) );
		}

		$available_jobs = $this->get_portal_sync_jobs();
		$sync_settings = $this->get_portal_sync_settings();
		$jobs = ! empty( $requested_jobs ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $requested_jobs ), array_keys( $available_jobs ) ) ) : $sync_settings['jobs'];
		if ( empty( $jobs ) ) {
			$jobs = array_keys( $available_jobs );
		}

		$run_key = wp_generate_uuid4();
		$total   = 0;
		$errors  = array();

		foreach ( $jobs as $job ) {
			$label  = isset( $available_jobs[ $job ] ) ? $available_jobs[ $job ] : $job;
			$log_id = $this->write_portal_sync_log( $run_key, $source, $job, 'started', 0, sprintf( __( '%s sync started.', 'ajforms' ), $label ) );
			$this->reset_portal_sync_stats();
			$this->portal_sync_current_log_id  = $log_id;
			$this->portal_sync_current_run_key = $run_key;
			$this->portal_sync_current_job     = $job;
			$result = null;

			if ( 'products' === $job ) {
				$result = $this->sync_portal_stripe_products( $secret_key );
			} elseif ( 'customers' === $job ) {
				$result = $this->sync_portal_stripe_customers( $secret_key );
			} elseif ( 'subscriptions' === $job ) {
				$result = $this->sync_portal_stripe_subscriptions( $secret_key );
			} elseif ( 'transactions' === $job ) {
				$result = $this->sync_portal_stripe_transactions( $secret_key );
			}

			if ( is_wp_error( $result ) ) {
				$errors[] = $label . ': ' . $result->get_error_message();
				$this->increment_portal_sync_stat( 'failed' );
				$this->write_portal_sync_log( $run_key, $source, $job, 'failed', 0, $this->get_portal_sync_message( $result->get_error_message(), $this->portal_sync_stats ), $log_id );
				$this->portal_sync_current_log_id  = 0;
				$this->portal_sync_current_run_key = '';
				$this->portal_sync_current_job     = '';
				continue;
			}

			$count = absint( $result );
			$total += $count;
			$reconciliation = $this->get_portal_sync_reconciliation();
			$this->portal_sync_stats['unmatched_payments'] = $reconciliation['unmatched_payments'];
			$this->portal_sync_stats['unmatched_refunds']  = $reconciliation['unmatched_refunds'];
			$this->portal_sync_stats['missing_users']      = $reconciliation['missing_users'];
			foreach ( $reconciliation['key_warnings'] as $warning ) {
				$this->add_portal_sync_warning( $warning );
			}
			$this->write_portal_sync_log( $run_key, $source, $job, 'success', $count, $this->get_portal_sync_message( sprintf( __( '%1$s sync completed. %2$d records refreshed.', 'ajforms' ), $label, $count ), $this->portal_sync_stats ), $log_id );
			$this->portal_sync_current_log_id  = 0;
			$this->portal_sync_current_run_key = '';
			$this->portal_sync_current_job     = '';
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'portal_sync_failed', implode( ' | ', $errors ) );
		}

		update_option( 'ajcore_portal_sync_last_run', current_time( 'mysql' ), false );
		delete_option( 'ajforms_last_portal_db_error' );

		return $total;
	}

	public function run_scheduled_portal_sync_job() {
		$settings = $this->get_portal_sync_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		$this->run_portal_sync_job( 'cron', $settings['jobs'] );
	}

	public function handle_stripe_webhook_request() {
		$this->ensure_portal_schema();

		$secret_key = $this->get_stripe_secret_key_for_portal();
		if ( '' === $secret_key ) {
			status_header( 500 );
			wp_send_json_error( array( 'message' => __( 'Stripe secret key is required.', 'ajforms' ) ) );
		}

		$payload = file_get_contents( 'php://input' );
		$event   = json_decode( (string) $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			status_header( 400 );
			wp_send_json_error( array( 'message' => __( 'Invalid Stripe event payload.', 'ajforms' ) ) );
		}

		$event_id = sanitize_text_field( (string) $event['id'] );
		$verified_event = $this->stripe_api_get( 'events/' . rawurlencode( $event_id ), $secret_key );
		if ( is_wp_error( $verified_event ) || empty( $verified_event['id'] ) || $verified_event['id'] !== $event_id ) {
			status_header( 400 );
			wp_send_json_error( array( 'message' => __( 'Stripe event could not be verified with the active Stripe key.', 'ajforms' ) ) );
		}

		$event = $verified_event;
		$type  = sanitize_text_field( (string) $event['type'] );
		$object = ! empty( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		$object_id = $this->get_stripe_event_object_id( $event );
		$event_livemode = ! empty( $event['livemode'] ) ? 1 : 0;
		$expected_livemode = $this->get_current_stripe_livemode();
		if ( $event_livemode !== $expected_livemode ) {
			$this->log_portal_event(
				'webhook_failed',
				array(
					'severity' => 'warning',
					'source'   => 'webhook',
					'details'  => array(
						'event_id'          => $event_id,
						'event_type'        => $type,
						'object_id'         => $object_id,
						'reason'            => 'livemode_mismatch',
						'event_livemode'    => $event_livemode,
						'expected_livemode' => $expected_livemode,
					),
				)
			);
			status_header( 400 );
			wp_send_json_error( array( 'message' => __( 'Stripe event mode does not match the active AJ Core Stripe mode.', 'ajforms' ) ) );
		}

		$event_row = $this->record_stripe_event_received( $event );
		if ( is_wp_error( $event_row ) ) {
			status_header( 500 );
			wp_send_json_error( array( 'message' => $event_row->get_error_message() ) );
		}

		$this->log_portal_event(
			'webhook_received',
			array(
				'source'  => 'webhook',
				'details' => array(
					'event_id'       => $event_id,
					'event_type'     => $type,
					'object_id'      => $object_id,
					'livemode'       => $event_livemode,
					'attempts'       => isset( $event_row->attempts ) ? (int) $event_row->attempts : 1,
					'stripe_account' => $this->get_stripe_event_account_id( $event ),
				),
			)
		);

		if ( $event_row && 'processed' === (string) $event_row->processing_status ) {
			$run_key = wp_generate_uuid4();
			$job_name = sanitize_key( str_replace( '.', '_', $type ) );
			$log_id = $this->write_portal_sync_log( $run_key, 'webhook', $job_name, 'started', 0, __( 'Duplicate Stripe webhook event received.', 'ajforms' ) );
			$this->portal_sync_current_log_id = $log_id;
			$this->portal_sync_current_run_key = $run_key;
			$this->portal_sync_current_job = $job_name;
			$this->record_portal_sync_item( 'duplicate_ignored', 'stripe_event', $event_id, 'skipped', __( 'Duplicate Stripe webhook event already processed successfully.', 'ajforms' ), $event, $this->get_payment_customer_id( $object ) );
			$this->write_portal_sync_log( $run_key, 'webhook', $job_name, 'success', 0, $this->get_portal_sync_message( __( 'Duplicate Stripe webhook event ignored.', 'ajforms' ), array( 'skipped' => 1 ) ), $log_id );
			$this->portal_sync_current_log_id = 0;
			$this->portal_sync_current_run_key = '';
			$this->portal_sync_current_job = '';
			$this->log_portal_event(
				'webhook_duplicate_skipped',
				array(
					'source'  => 'webhook',
					'details' => array( 'event_id' => $event_id, 'event_type' => $type, 'object_id' => $object_id ),
				)
			);
			wp_send_json_success( array( 'message' => 'duplicate_ignored' ) );
		}

		if ( $event_row && 'failed' === (string) $event_row->processing_status ) {
			$this->log_portal_event(
				'webhook_retried',
				array(
					'source'  => 'webhook',
					'details' => array(
						'event_id'   => $event_id,
						'event_type' => $type,
						'object_id'  => $object_id,
						'attempts'   => (int) $event_row->attempts,
					),
				)
			);
		}

		$this->mark_stripe_event_processing( $event_id );
		$supported = array(
			'checkout.session.completed',
			'invoice.paid',
			'invoice.payment_failed',
			'customer.subscription.updated',
			'customer.subscription.deleted',
			'charge.refunded',
		);

		if ( ! in_array( $type, $supported, true ) ) {
			$this->mark_stripe_event_processed( $event_id );
			$this->log_portal_event(
				'webhook_skipped',
				array(
					'source'  => 'webhook',
					'details' => array(
						'event_id'   => $event_id,
						'event_type' => $type,
						'object_id'  => $object_id,
						'reason'     => 'unsupported_event_type',
					),
				)
			);
			wp_send_json_success( array( 'message' => 'ignored' ) );
		}

		$current_object = $this->fetch_current_stripe_webhook_object( $type, $object, $secret_key );
		if ( is_wp_error( $current_object ) ) {
			$this->mark_stripe_event_failed( $event_id, $current_object->get_error_message() );
			$this->log_portal_event(
				'webhook_failed',
				array(
					'severity' => 'error',
					'source'   => 'webhook',
					'details'  => array( 'event_id' => $event_id, 'event_type' => $type, 'object_id' => $object_id, 'error' => $current_object->get_error_message() ),
				)
			);
			status_header( 500 );
			wp_send_json_error( array( 'message' => $current_object->get_error_message() ) );
		}
		if ( is_array( $current_object ) && ! empty( $current_object['id'] ) ) {
			$object = $current_object;
		}

		$stripe_customer_id = '';
		if ( ! empty( $object['customer'] ) && is_string( $object['customer'] ) ) {
			$stripe_customer_id = sanitize_text_field( (string) $object['customer'] );
		}

		$deferred_one_time_result = 0;
		if ( 'checkout.session.completed' === $type && is_array( $object ) ) {
			$deferred_one_time_result = $this->maybe_charge_deferred_one_time_items_for_checkout_session( $object, $secret_key );
			if ( is_wp_error( $deferred_one_time_result ) ) {
				$this->mark_stripe_event_failed( $event_id, $deferred_one_time_result->get_error_message() );
				$this->log_portal_event(
					'webhook_failed',
					array(
						'severity' => 'error',
						'source'   => 'webhook',
						'details'  => array( 'event_id' => $event_id, 'event_type' => $type, 'object_id' => $object_id, 'error' => $deferred_one_time_result->get_error_message(), 'phase' => 'mixed_cart_one_time_payment' ),
					)
				);
				status_header( 500 );
				wp_send_json_error( array( 'message' => $deferred_one_time_result->get_error_message() ) );
			}
			$upgrade_result = $this->process_portal_upgrade_checkout_session( $object, $secret_key, 'webhook' );
			if ( is_wp_error( $upgrade_result ) ) {
				$this->mark_stripe_event_failed( $event_id, $upgrade_result->get_error_message() );
				$this->log_portal_event(
					'webhook_failed',
					array(
						'severity' => 'error',
						'source'   => 'webhook',
						'details'  => array( 'event_id' => $event_id, 'event_type' => $type, 'object_id' => $object_id, 'error' => $upgrade_result->get_error_message(), 'phase' => 'portal_service_upgrade' ),
					)
				);
				status_header( 500 );
				wp_send_json_error( array( 'message' => $upgrade_result->get_error_message() ) );
			}
		}

		$run_key = wp_generate_uuid4();
		$log_id  = $this->write_portal_sync_log( $run_key, 'webhook', sanitize_key( str_replace( '.', '_', $type ) ), 'started', 0, sprintf( __( 'Stripe webhook %s received.', 'ajforms' ), $type ) );
		$this->reset_portal_sync_stats();
		$this->portal_sync_current_log_id  = $log_id;
		$this->portal_sync_current_run_key = $run_key;
		$this->portal_sync_current_job     = sanitize_key( str_replace( '.', '_', $type ) );

		if ( '' !== $stripe_customer_id && 0 === strpos( $stripe_customer_id, 'cus_' ) ) {
			$result = $this->sync_single_portal_stripe_customer( $secret_key, $stripe_customer_id );
		} else {
			$customer_result = $this->sync_portal_stripe_customers( $secret_key );
			$result = is_wp_error( $customer_result ) ? $customer_result : $this->sync_portal_stripe_transactions( $secret_key );
		}

		if ( ! is_wp_error( $result ) ) {
			$result = absint( $result ) + absint( $deferred_one_time_result );
			$this->backfill_portal_service_requests_from_ledger();
			$this->cleanup_stale_pending_checkout_service_requests();
		}

		$this->write_portal_sync_log(
			$run_key,
			'webhook',
			sanitize_key( str_replace( '.', '_', $type ) ),
			is_wp_error( $result ) ? 'failed' : 'success',
			is_wp_error( $result ) ? 0 : absint( $result ),
			$this->get_portal_sync_message( is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Stripe webhook %s processed.', 'ajforms' ), $type ), $this->portal_sync_stats ),
			$log_id
		);

		$this->portal_sync_current_log_id  = 0;
		$this->portal_sync_current_run_key = '';
		$this->portal_sync_current_job     = '';

		if ( is_wp_error( $result ) ) {
			$this->mark_stripe_event_failed( $event_id, $result->get_error_message() );
			$this->log_portal_event(
				'webhook_failed',
				array(
					'severity' => 'error',
					'source'   => 'webhook',
					'details'  => array( 'event_id' => $event_id, 'event_type' => $type, 'object_id' => $object_id, 'error' => $result->get_error_message() ),
				)
			);
			status_header( 500 );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->mark_stripe_event_processed( $event_id );
		$this->log_portal_event(
			'webhook_processed',
			array(
				'source'  => 'webhook',
				'details' => array( 'event_id' => $event_id, 'event_type' => $type, 'object_id' => $object_id, 'records' => absint( $result ) ),
			)
		);
		wp_send_json_success( array( 'message' => 'processed', 'records' => absint( $result ) ) );
	}

	private function sync_single_portal_stripe_customer( $secret_key, $stripe_customer_id ) {
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'missing_customer', __( 'Stripe customer ID is required.', 'ajforms' ) );
		}

		if ( ! $this->is_real_stripe_customer_id( $stripe_customer_id ) ) {
			return new WP_Error( 'guest_customer_sync_disabled', __( 'Guest buyer records are no longer imported. Portal sync now imports Stripe Customer records only.', 'ajforms' ) );
		}

		$customer = $this->stripe_api_get( 'customers/' . rawurlencode( $stripe_customer_id ), $secret_key );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		if ( empty( $customer['id'] ) || ! empty( $customer['deleted'] ) ) {
			return new WP_Error( 'customer_not_found', __( 'Stripe customer was not found.', 'ajforms' ) );
		}

		$this->upsert_portal_stripe_customer_record(
			array(
				'stripe_customer_id' => sanitize_text_field( (string) $customer['id'] ),
				'email'              => ! empty( $customer['email'] ) ? sanitize_email( (string) $customer['email'] ) : '',
				'name'               => ! empty( $customer['name'] ) ? sanitize_text_field( (string) $customer['name'] ) : '',
				'phone'              => ! empty( $customer['phone'] ) ? sanitize_text_field( (string) $customer['phone'] ) : '',
				'address'            => ! empty( $customer['address'] ) ? wp_json_encode( $customer['address'] ) : '',
				'metadata'           => ! empty( $customer['metadata'] ) ? wp_json_encode( $customer['metadata'] ) : '',
				'raw_data'           => wp_json_encode( $customer ),
				'livemode'           => ! empty( $customer['livemode'] ) ? 1 : 0,
				'created_at'         => ! empty( $customer['created'] ) ? $this->stripe_timestamp_to_mysql( $customer['created'] ) : null,
				'synced_at'          => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$subscription_count = $this->sync_portal_stripe_subscriptions( $secret_key, $stripe_customer_id );
		if ( is_wp_error( $subscription_count ) ) {
			return $subscription_count;
		}

		$transaction_count = $this->sync_portal_stripe_transactions( $secret_key, $stripe_customer_id );
		if ( is_wp_error( $transaction_count ) ) {
			return $transaction_count;
		}

		return 1 + absint( $subscription_count ) + absint( $transaction_count );
	}

	private function disable_stripe_customer_portal_access( $stripe_customer_id, $portal_status = 'disabled' ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$portal_status      = in_array( $this->normalize_portal_customer_status( $portal_status ), array( 'disabled', 'archived', 'without_portal_login' ), true ) ? $this->normalize_portal_customer_status( $portal_status ) : 'disabled';
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
			)
		);

		if ( $mapping && ! empty( $mapping->user_id ) ) {
			$user = get_userdata( (int) $mapping->user_id );
			if ( $user && in_array( 'aj_portal_user', (array) $user->roles, true ) ) {
				$user->remove_role( 'aj_portal_user' );
				$this->log_portal_event(
					'role_removed',
					array(
						'stripe_customer_id' => $stripe_customer_id,
						'wp_user_id_before'  => (int) $user->ID,
						'email_before'       => $user->user_email,
						'details'            => array( 'role' => 'aj_portal_user' ),
					)
				);
			}
		}

		return $this->set_portal_customer_status(
			$stripe_customer_id,
			$portal_status,
			$portal_status,
			'portal_users',
			array( 'role_removed_if_present' => true )
		);
	}

	private function send_portal_user_password_reset( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return new WP_Error( 'missing_user', __( 'WordPress user was not found.', 'ajforms' ) );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);
		$settings = $this->get_plugin_settings();
		$subject = ! empty( $settings['wp_password_reset_subject'] ) ? sanitize_text_field( (string) $settings['wp_password_reset_subject'] ) : __( 'Password reset for your Portal Login for NC LLC Agents Inc', 'ajforms' );
		$from_email = ! empty( $settings['wp_email_from_email'] ) ? sanitize_email( (string) $settings['wp_email_from_email'] ) : ( defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? sanitize_email( AJCORE_SYSTEM_FROM_EMAIL ) : 'donotreply@ncllcagents.com' );
		if ( ! is_email( $from_email ) ) {
			$from_email = 'donotreply@ncllcagents.com';
		}
		$from_name = ! empty( $settings['wp_email_from_name'] ) ? sanitize_text_field( (string) $settings['wp_email_from_name'] ) : ( ! empty( $settings['default_from_name'] ) ? sanitize_text_field( (string) $settings['default_from_name'] ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message = sprintf(
			'<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fc;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
				<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:#f6f8fc;padding:32px 16px;">
					<tr>
						<td align="center">
							<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dbe7f3;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15,23,42,.10);">
								<tr>
									<td style="height:8px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed);font-size:0;line-height:0;">&nbsp;</td>
								</tr>
								<tr>
									<td style="padding:34px 34px 30px;">
										<p style="margin:0 0 10px;color:#2563eb;font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">%1$s</p>
										<h1 style="margin:0 0 16px;font-size:30px;line-height:1.15;color:#0f172a;">%2$s</h1>
										<p style="margin:0 0 18px;font-size:17px;line-height:1.65;color:#334155;">%3$s</p>
										<p style="margin:0 0 28px;font-size:16px;line-height:1.6;color:#475569;">%4$s</p>
										<p style="margin:0 0 28px;">
											<a href="%5$s" style="display:inline-block;background:#3157ff;color:#ffffff;text-decoration:none;border-radius:999px;padding:14px 24px;font-size:16px;font-weight:800;box-shadow:0 12px 28px rgba(49,87,255,.28);">%6$s</a>
										</p>
										<p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#64748b;">%7$s</p>
										<p style="margin:0;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;font-size:14px;line-height:1.55;word-break:break-all;">
											<a href="%5$s" style="color:#2563eb;text-decoration:underline;">%5$s</a>
										</p>
										<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#64748b;">%8$s</p>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</body></html>',
			esc_html( $site_name ),
			esc_html__( 'Set your client portal password', 'ajforms' ),
			esc_html( sprintf( __( 'Hi %s,', 'ajforms' ), $user->display_name ) ),
			esc_html__( 'Use the secure button below to create a new password for your client portal account. This link is private and should only be used by you.', 'ajforms' ),
			esc_url( $reset_url ),
			esc_html__( 'Set New Password', 'ajforms' ),
			esc_html__( 'If the button does not work, copy and paste this link into your browser:', 'ajforms' ),
			esc_html__( 'If you did not request this email, you can ignore it.', 'ajforms' )
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
			$headers[] = 'Reply-To: ' . $from_email;
		}

		return wp_mail( $user->user_email, $subject, $message, $headers );
	}

	private function send_portal_user_welcome_email( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		if ( ! $user ) {
			return new WP_Error( 'missing_user', __( 'WordPress user was not found.', 'ajforms' ) );
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$reset_url = network_site_url(
			'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ),
			'login'
		);
		$settings = $this->get_plugin_settings();
		$subject = ! empty( $settings['wp_welcome_email_subject'] ) ? sanitize_text_field( (string) $settings['wp_welcome_email_subject'] ) : __( 'Welcome : Your portal access is enabled to NC LLC Agents Inc', 'ajforms' );
		$from_email = ! empty( $settings['wp_email_from_email'] ) ? sanitize_email( (string) $settings['wp_email_from_email'] ) : ( defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? sanitize_email( AJCORE_SYSTEM_FROM_EMAIL ) : 'donotreply@ncllcagents.com' );
		if ( ! is_email( $from_email ) ) {
			$from_email = 'donotreply@ncllcagents.com';
		}
		$from_name = ! empty( $settings['wp_email_from_name'] ) ? sanitize_text_field( (string) $settings['wp_email_from_name'] ) : ( ! empty( $settings['default_from_name'] ) ? sanitize_text_field( (string) $settings['default_from_name'] ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$message = sprintf(
			'<!doctype html><html><body style="margin:0;padding:0;background:#f6f8fc;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
				<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="background:#f6f8fc;padding:32px 16px;">
					<tr>
						<td align="center">
							<table role="presentation" width="100%%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #dbe7f3;border-radius:24px;overflow:hidden;box-shadow:0 18px 48px rgba(15,23,42,.10);">
								<tr>
									<td style="height:8px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed);font-size:0;line-height:0;">&nbsp;</td>
								</tr>
								<tr>
									<td style="padding:34px 34px 30px;">
										<p style="margin:0 0 10px;color:#2563eb;font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">%1$s</p>
										<h1 style="margin:0 0 16px;font-size:30px;line-height:1.15;color:#0f172a;">%2$s</h1>
										<p style="margin:0 0 18px;font-size:17px;line-height:1.65;color:#334155;">%3$s</p>
										<p style="margin:0 0 18px;font-size:16px;line-height:1.6;color:#475569;">%4$s</p>
										<p style="margin:0 0 28px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;font-size:16px;line-height:1.55;color:#0f172a;"><strong>%5$s</strong><br>%6$s</p>
										<p style="margin:0 0 28px;">
											<a href="%7$s" style="display:inline-block;background:#3157ff;color:#ffffff;text-decoration:none;border-radius:999px;padding:14px 24px;font-size:16px;font-weight:800;box-shadow:0 12px 28px rgba(49,87,255,.28);">%8$s</a>
										</p>
										<p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#64748b;">%9$s</p>
										<p style="margin:0;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;font-size:14px;line-height:1.55;word-break:break-all;">
											<a href="%7$s" style="color:#2563eb;text-decoration:underline;">%7$s</a>
										</p>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</body></html>',
			esc_html( $site_name ),
			esc_html__( 'Welcome to your client portal', 'ajforms' ),
			esc_html( sprintf( __( 'Hi %s,', 'ajforms' ), $user->display_name ) ),
			esc_html__( 'Your client portal access has been enabled. Use the button below to set your password and sign in securely.', 'ajforms' ),
			esc_html__( 'Your username is your email address:', 'ajforms' ),
			esc_html( $user->user_email ),
			esc_url( $reset_url ),
			esc_html__( 'Set Password and Sign In', 'ajforms' ),
			esc_html__( 'If the button does not work, copy and paste this link into your browser:', 'ajforms' )
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from_email ) ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
			$headers[] = 'Reply-To: ' . $from_email;
		}

		return wp_mail( $user->user_email, $subject, $message, $headers );
	}

	private function relink_stripe_customer_to_user_email( $stripe_customer_id, $email ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$email              = sanitize_email( (string) $email );

		if ( '' === $stripe_customer_id || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_relink', __( 'A valid Stripe customer and WordPress user email are required.', 'ajforms' ) );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'No WordPress user exists with that email.', 'ajforms' ) );
		}
		$customer_before = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, portal_status, enabled_portal FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);
		$mapping_before = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);

		$old_customer_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT stripe_customer_id FROM {$this->get_portal_user_mappings_table()} WHERE user_id = %d AND stripe_customer_id <> %s",
				(int) $user->ID,
				$stripe_customer_id
			)
		);

		foreach ( $old_customer_ids as $old_customer_id ) {
			$this->log_portal_event(
				'ambiguous_email',
				array(
					'severity'           => 'warning',
					'source'             => 'customer_detail',
					'stripe_customer_id' => sanitize_text_field( $old_customer_id ),
					'wp_user_id_before'  => (int) $user->ID,
					'wp_user_id_after'   => (int) $user->ID,
					'email_before'       => $user->user_email,
					'email_after'        => $user->user_email,
					'details'            => array( 'kept_stripe_customer_id' => $stripe_customer_id, 'cleared_stripe_customer_id' => sanitize_text_field( $old_customer_id ) ),
				)
			);
			$this->set_portal_customer_status(
				sanitize_text_field( $old_customer_id ),
				'disabled',
				'ambiguous_email_cleared',
				'customer_detail',
				array( 'kept_stripe_customer_id' => $stripe_customer_id )
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->get_portal_user_mappings_table()} WHERE (user_id = %d AND stripe_customer_id <> %s) OR (stripe_customer_id = %s AND user_id <> %d)",
				(int) $user->ID,
				$stripe_customer_id,
				$stripe_customer_id,
				(int) $user->ID
			)
		);

		$this->assign_aj_portal_user_role( $user );

		$this->upsert_portal_record(
			$this->get_portal_user_mappings_table(),
			array(
				'user_id'            => (int) $user->ID,
				'stripe_customer_id' => $stripe_customer_id,
				'customer_email'     => $email,
				'portal_user_email'  => sanitize_email( $user->user_email ),
				'site_uuid'          => $this->get_ajcore_site_uuid(),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			'stripe_customer_id'
		);

		$this->set_portal_customer_status(
			$stripe_customer_id,
			'active',
			'relinked_by_email',
			'customer_detail',
			array( 'wp_user_id' => (int) $user->ID, 'user_email' => $user->user_email )
		);
		$this->log_portal_event(
			'relinked_by_email',
			array(
				'source'                 => 'customer_detail',
				'customer_id'            => $customer_before ? (int) $customer_before->id : 0,
				'stripe_customer_id'     => $stripe_customer_id,
				'wp_user_id_before'      => $mapping_before && ! empty( $mapping_before->user_id ) ? (int) $mapping_before->user_id : 0,
				'wp_user_id_after'       => (int) $user->ID,
				'email_before'           => $mapping_before && ! empty( $mapping_before->portal_user_email ) ? $mapping_before->portal_user_email : '',
				'email_after'            => $user->user_email,
				'portal_status_before'   => $customer_before && ! empty( $customer_before->portal_status ) ? $customer_before->portal_status : ( $customer_before && ! empty( $customer_before->enabled_portal ) ? 'active' : 'disabled' ),
				'portal_status_after'    => 'active',
			)
		);
		$this->log_portal_event(
			'mapping_relinked',
			array(
				'source'                 => 'customer_detail',
				'customer_id'            => $customer_before ? (int) $customer_before->id : 0,
				'stripe_customer_id'     => $stripe_customer_id,
				'wp_user_id_before'      => $mapping_before && ! empty( $mapping_before->user_id ) ? (int) $mapping_before->user_id : 0,
				'wp_user_id_after'       => (int) $user->ID,
				'email_before'           => $mapping_before && ! empty( $mapping_before->portal_user_email ) ? $mapping_before->portal_user_email : '',
				'email_after'            => $user->user_email,
				'portal_status_before'   => $customer_before && ! empty( $customer_before->portal_status ) ? $customer_before->portal_status : ( $customer_before && ! empty( $customer_before->enabled_portal ) ? 'active' : 'disabled' ),
				'portal_status_after'    => 'active',
				'details'                => array( 'site_uuid' => $this->get_ajcore_site_uuid() ),
			)
		);

		return (int) $user->ID;
	}

	private function get_portal_customer_detail_data( $stripe_customer_id ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
			)
		);

		if ( ! $customer ) {
			return null;
		}

		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
			)
		);
		$user = $this->get_valid_portal_mapping_user( $customer, $mapping );

		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_customer_id = %s ORDER BY current_period_end DESC, id DESC",
				$stripe_customer_id
			)
		);

		$ledger = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE stripe_customer_id = %s ORDER BY ledger_date DESC, id DESC LIMIT %d",
				$stripe_customer_id,
				100
			)
		);

		$requests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_service_requests_table()} WHERE stripe_customer_id = %s ORDER BY updated_at DESC, created_at DESC, id DESC LIMIT %d",
				$stripe_customer_id,
				100
			)
		);

		$tasks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, ts.status AS customer_status, ts.completed_at AS customer_completed_at, ts.updated_at AS customer_status_updated_at
				FROM {$this->get_portal_tasks_table()} t
				LEFT JOIN {$this->get_portal_task_statuses_table()} ts ON ts.task_id = t.id AND ts.stripe_customer_id = %s
				WHERE t.stripe_customer_id = %s OR t.task_scope = 'global'
				ORDER BY t.due_date IS NULL ASC, t.due_date ASC, t.updated_at DESC, t.id DESC
				LIMIT %d",
				$stripe_customer_id,
				$stripe_customer_id,
				100
			)
		);

		$task_comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tc.*, t.title AS task_title
				FROM {$this->get_portal_task_comments_table()} tc
				LEFT JOIN {$this->get_portal_tasks_table()} t ON t.id = tc.task_id
				WHERE tc.stripe_customer_id = %s
				ORDER BY tc.created_at DESC, tc.id DESC
				LIMIT %d",
				$stripe_customer_id,
				50
			)
		);

		$entities = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_entity_mappings_table()} WHERE stripe_customer_id = %s ORDER BY entity_label ASC, entity_key ASC",
				$stripe_customer_id
			)
		);

		$files = array();
		if ( $user || ! empty( $customer->email ) ) {
			$user_id = $user ? (int) $user->ID : 0;
			$email   = ! empty( $customer->email ) ? strtolower( sanitize_email( $customer->email ) ) : '';
			$files   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT f.* FROM {$this->get_portal_files_table()} f INNER JOIN {$this->get_portal_file_users_table()} fu ON fu.file_id = f.id WHERE fu.user_id = %d OR LOWER(fu.user_email) = %s ORDER BY f.created_at DESC",
					$user_id,
					$email
				)
			);
		}

		$active_subscriptions = array_values( array_filter( $subscriptions, array( $this, 'is_active_portal_subscription' ) ) );
		foreach ( $active_subscriptions as $subscription ) {
			$subscription->billing_type = $this->get_portal_billing_type_label( 'subscription' );
		}
		$active_recurring_services = array_merge(
			$this->get_portal_service_records_from_snapshots( 'recurring', $stripe_customer_id, 100 ),
			$this->get_portal_service_records_from_subscriptions( $active_subscriptions ),
			$this->get_portal_recurring_service_records_from_ledger( $stripe_customer_id, 100 )
		);
		$active_recurring_services = $this->dedupe_portal_service_records( $active_recurring_services );
		foreach ( $ledger as $entry ) {
			if ( $this->portal_ledger_entry_has_subscription_context( $entry ) ) {
				$entry->billing_type = $this->get_portal_billing_type_label( 'subscription' );
			} elseif ( $this->is_one_time_paid_service_ledger_entry( $entry ) ) {
				$entry->billing_type = $this->get_portal_billing_type_label( 'one_time' );
			} else {
				$entry->billing_type = $this->get_portal_billing_type_label( 'manual' );
			}
		}

		return array(
			'customer'          => $customer,
			'mapping'           => $mapping,
			'user'              => $user,
			'subscriptions'     => $subscriptions,
			'active_subs'       => $active_subscriptions,
			'active_recurring_services' => $active_recurring_services,
			'one_time_services' => $this->get_portal_one_time_paid_services( $stripe_customer_id, 100 ),
			'purchased_products' => $this->get_portal_customer_purchased_products( $subscriptions, $ledger ),
			'ledger'            => $ledger,
			'requests'          => $requests,
			'upcoming_payments' => $this->get_portal_customer_upcoming_payments( $subscriptions ),
			'entities'          => $entities,
			'files'             => $files,
			'tasks'             => $tasks,
			'task_comments'     => $task_comments,
			'balance'           => $this->get_portal_customer_balance_summary( $ledger ),
			'activity'          => $this->get_portal_customer_activity_summary( $ledger, $requests, $tasks, $task_comments, $files ),
		);
	}

	private function get_portal_customer_balance_summary( $ledger ) {
		$open_balance = 0.0;
		$credit_balance = 0.0;
		$paid_total = 0.0;
		$failed_count = 0;

		foreach ( (array) $ledger as $entry ) {
			$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
			$effect = $this->get_portal_ledger_balance_effect( $entry );
			if ( $effect > 0 ) {
				$open_balance += $effect;
			} elseif ( $effect < 0 ) {
				$credit_balance += abs( $effect );
			}
			if ( $effect < 0 && in_array( $status, array( 'paid', 'succeeded', 'partially_refunded', 'partial_refund' ), true ) ) {
				$paid_total += abs( $effect );
			}
			if ( in_array( $status, array( 'failed', 'payment_failed', 'requires_payment_method' ), true ) ) {
				$failed_count++;
			}
		}

		return array(
			'open_balance'   => $open_balance,
			'credit_balance' => $credit_balance,
			'paid_total'     => $paid_total,
			'failed_count'   => $failed_count,
		);
	}

	private function get_portal_customer_activity_summary( $ledger, $requests, $tasks, $task_comments, $files ) {
		$activity = array();

		foreach ( (array) $requests as $request ) {
			$date = ! empty( $request->updated_at ) ? $request->updated_at : $request->created_at;
			$activity[] = array(
				'date'    => $date,
				'type'    => __( 'Request', 'ajforms' ),
				'summary' => trim( (string) $request->service_name ) . ' · ' . sanitize_key( (string) $request->status ),
				'note'    => ! empty( $request->admin_notes ) ? (string) $request->admin_notes : '',
			);
		}

		foreach ( (array) $ledger as $entry ) {
			$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
			$activity[] = array(
				'date'    => ! empty( $entry->ledger_date ) ? $entry->ledger_date : $entry->created_at,
				'type'    => __( 'Billing', 'ajforms' ),
				'summary' => $this->get_portal_ledger_display_description( $entry ) . ' · ' . sanitize_key( (string) $entry->status ),
				'note'    => ! empty( $metadata['admin_notes'] ) ? sanitize_textarea_field( (string) $metadata['admin_notes'] ) : '',
			);
		}

		foreach ( (array) $tasks as $task ) {
			$status = ! empty( $task->customer_status ) ? $task->customer_status : $task->status;
			$activity[] = array(
				'date'    => ! empty( $task->customer_status_updated_at ) ? $task->customer_status_updated_at : $task->updated_at,
				'type'    => __( 'Task', 'ajforms' ),
				'summary' => trim( (string) $task->title ) . ' · ' . sanitize_key( (string) $status ),
				'note'    => '',
			);
		}

		foreach ( (array) $task_comments as $comment ) {
			$activity[] = array(
				'date'    => $comment->created_at,
				'type'    => __( 'Task Comment', 'ajforms' ),
				'summary' => ! empty( $comment->task_title ) ? $comment->task_title : __( 'Task comment', 'ajforms' ),
				'note'    => (string) $comment->comment,
			);
		}

		foreach ( (array) $files as $file ) {
			$activity[] = array(
				'date'    => $file->created_at,
				'type'    => __( 'File', 'ajforms' ),
				'summary' => (string) $file->title,
				'note'    => '',
			);
		}

		usort(
			$activity,
			function ( $a, $b ) {
				return strtotime( $b['date'] ) <=> strtotime( $a['date'] );
			}
		);

		return array_slice( $activity, 0, 40 );
	}

	private function is_active_portal_subscription( $subscription ) {
		return isset( $subscription->status ) && in_array( $subscription->status, array( 'active', 'trialing' ), true );
	}

	private function get_portal_customer_upcoming_payments( $subscriptions ) {
		$upcoming = array();
		$now      = current_time( 'timestamp' );
		$limit    = $now + ( 30 * DAY_IN_SECONDS );

		foreach ( $subscriptions as $subscription ) {
			if ( ! $this->is_active_portal_subscription( $subscription ) || empty( $subscription->current_period_end ) ) {
				continue;
			}

			$timestamp = strtotime( $subscription->current_period_end );
			if ( $timestamp && $timestamp >= $now && $timestamp <= $limit ) {
				$upcoming[] = $subscription;
			}
		}

		return $upcoming;
	}

	private function get_portal_billing_type_label( $billing_type ) {
		$billing_type = sanitize_key( (string) $billing_type );

		if ( 'subscription' === $billing_type ) {
			return __( 'Subscription / Auto-Pay Enabled', 'ajforms' );
		}

		if ( 'one_time' === $billing_type ) {
			return __( 'One-Time Paid / Auto-Pay Not Enabled', 'ajforms' );
		}

		return __( 'Manual / Internal', 'ajforms' );
	}

	private function get_portal_product_cache_by_price_id() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		global $wpdb;
		$cache = array();
		$rows  = $wpdb->get_results( "SELECT * FROM {$this->get_portal_stripe_products_table()}" );
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row->stripe_price_id ) ) {
				$cache[ sanitize_text_field( (string) $row->stripe_price_id ) ] = $row;
			}
		}

		return $cache;
	}

	private function get_portal_product_cache_by_product_id() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		global $wpdb;
		$cache = array();
		$rows  = $wpdb->get_results( "SELECT * FROM {$this->get_portal_stripe_products_table()} ORDER BY recurring_interval DESC, price_amount DESC" );
		foreach ( (array) $rows as $row ) {
			if ( ! empty( $row->stripe_product_id ) && ! isset( $cache[ $row->stripe_product_id ] ) ) {
				$cache[ sanitize_text_field( (string) $row->stripe_product_id ) ] = $row;
			}
		}

		return $cache;
	}

	private function collect_portal_service_identifiers_from_data( $data, &$identifiers ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				if ( 'price' === $key ) {
					if ( ! empty( $value['id'] ) && is_scalar( $value['id'] ) ) {
						$identifiers['price_ids'][] = sanitize_text_field( (string) $value['id'] );
					}
					if ( ! empty( $value['product'] ) ) {
						if ( is_array( $value['product'] ) && ! empty( $value['product']['id'] ) ) {
							$identifiers['product_ids'][] = sanitize_text_field( (string) $value['product']['id'] );
						} elseif ( is_scalar( $value['product'] ) ) {
							$identifiers['product_ids'][] = sanitize_text_field( (string) $value['product'] );
						}
					}
				}
				if ( 'product' === $key && ! empty( $value['id'] ) && is_scalar( $value['id'] ) ) {
					$identifiers['product_ids'][] = sanitize_text_field( (string) $value['id'] );
				}
				$this->collect_portal_service_identifiers_from_data( $value, $identifiers );
				continue;
			}

			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );
			if ( in_array( $key, array( 'price_id', 'stripe_price_id', 'price' ), true ) && preg_match( '/^price_/', $value ) ) {
				$identifiers['price_ids'][] = $value;
			} elseif ( in_array( $key, array( 'product_id', 'stripe_product_id', 'product' ), true ) && preg_match( '/^prod_/', $value ) ) {
				$identifiers['product_ids'][] = $value;
			} elseif ( in_array( $key, array( 'subscription_id', 'stripe_subscription_id', 'subscription' ), true ) && preg_match( '/^sub_/', $value ) ) {
				$identifiers['subscription_ids'][] = $value;
			} elseif ( in_array( $key, array( 'checkout_session_id', 'checkout_session', 'session_id' ), true ) && preg_match( '/^cs_/', $value ) ) {
				$identifiers['checkout_session_ids'][] = $value;
			} elseif ( in_array( $key, array( 'invoice_id', 'invoice' ), true ) && preg_match( '/^in_/', $value ) ) {
				$identifiers['invoice_ids'][] = $value;
			} elseif ( in_array( $key, array( 'payment_intent_id', 'payment_intent' ), true ) && preg_match( '/^pi_/', $value ) ) {
				$identifiers['payment_intent_ids'][] = $value;
			}
		}
	}

	private function get_portal_service_identifiers_from_ledger_entry( $entry ) {
		$identifiers = array(
			'price_ids'          => array(),
			'product_ids'        => array(),
			'subscription_ids'   => array(),
			'checkout_session_ids'=> array(),
			'invoice_ids'        => array(),
			'payment_intent_ids' => array(),
		);

		foreach ( array( 'stripe_price_id' => 'price_ids', 'stripe_product_id' => 'product_ids', 'invoice_id' => 'invoice_ids', 'payment_intent_id' => 'payment_intent_ids' ) as $field => $bucket ) {
			if ( isset( $entry->{$field} ) && is_scalar( $entry->{$field} ) && '' !== (string) $entry->{$field} ) {
				$identifiers[ $bucket ][] = sanitize_text_field( (string) $entry->{$field} );
			}
		}
		if ( ! empty( $entry->source_type ) && 'checkout_session' === sanitize_key( (string) $entry->source_type ) && ! empty( $entry->source_object_id ) ) {
			$identifiers['checkout_session_ids'][] = sanitize_text_field( (string) $entry->source_object_id );
		}
		if ( ! empty( $entry->source_type ) && 'invoice' === sanitize_key( (string) $entry->source_type ) && ! empty( $entry->source_object_id ) ) {
			$identifiers['invoice_ids'][] = sanitize_text_field( (string) $entry->source_object_id );
		}

		$this->collect_portal_service_identifiers_from_data( $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' ), $identifiers );
		$this->collect_portal_service_identifiers_from_data( $this->decode_portal_json( isset( $entry->raw_data ) ? $entry->raw_data : '' ), $identifiers );
		$this->collect_portal_service_identifiers_from_data( $this->decode_portal_json( isset( $entry->transaction_raw_data ) ? $entry->transaction_raw_data : '' ), $identifiers );
		foreach ( $this->get_related_portal_transaction_raw_data_for_entry( $entry ) as $raw_data ) {
			$this->collect_portal_service_identifiers_from_data( $raw_data, $identifiers );
		}

		foreach ( $identifiers as $key => $values ) {
			$identifiers[ $key ] = array_values( array_filter( array_unique( $values ) ) );
		}

		return $identifiers;
	}

	private function get_related_portal_transaction_raw_data_for_entry( $entry ) {
		global $wpdb;

		$conditions = array();
		$params     = array();
		foreach ( array(
			'stripe_object_id'  => isset( $entry->source_object_id ) ? $entry->source_object_id : '',
			'payment_intent_id' => isset( $entry->payment_intent_id ) ? $entry->payment_intent_id : '',
			'invoice_id'        => isset( $entry->invoice_id ) ? $entry->invoice_id : '',
			'charge_id'         => isset( $entry->charge_id ) ? $entry->charge_id : '',
		) as $column => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$conditions[] = "{$column} = %s";
				$params[]     = sanitize_text_field( (string) $value );
			}
		}

		if ( empty( $conditions ) ) {
			return array();
		}

		$sql = "SELECT raw_data FROM {$this->get_portal_stripe_transactions_table()} WHERE (" . implode( ' OR ', $conditions ) . ") ORDER BY CASE object_type WHEN 'checkout_session' THEN 0 WHEN 'invoice' THEN 1 WHEN 'charge' THEN 2 ELSE 3 END, id DESC LIMIT 10";
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		$data = array();
		foreach ( (array) $rows as $raw ) {
			$decoded = $this->decode_portal_json( $raw );
			if ( ! empty( $decoded ) ) {
				$data[] = $decoded;
			}
		}

		return $data;
	}

	private function resolve_portal_product_from_identifiers( $identifiers ) {
		$by_price   = $this->get_portal_product_cache_by_price_id();
		$by_product = $this->get_portal_product_cache_by_product_id();

		foreach ( (array) $identifiers['price_ids'] as $price_id ) {
			if ( isset( $by_price[ $price_id ] ) ) {
				return $by_price[ $price_id ];
			}
		}
		foreach ( (array) $identifiers['product_ids'] as $product_id ) {
			if ( isset( $by_product[ $product_id ] ) ) {
				return $by_product[ $product_id ];
			}
		}

		return null;
	}

	private function get_portal_product_price_label( $product ) {
		if ( ! $product ) {
			return '';
		}

		$label = $this->format_portal_money( isset( $product->price_amount ) ? $product->price_amount : 0, isset( $product->currency ) ? $product->currency : 'usd' );
		if ( ! empty( $product->recurring_interval ) ) {
			$label .= '/' . sanitize_key( (string) $product->recurring_interval );
		}

		return $label;
	}

	private function get_portal_product_admin_label( $product ) {
		if ( ! $product ) {
			return '';
		}

		$status = ! empty( $product->active ) ? __( 'Active', 'ajforms' ) : __( 'Inactive', 'ajforms' );
		$interval = ! empty( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : __( 'one-time', 'ajforms' );
		$price_id = ! empty( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';

		return sprintf(
			'%1$s — %2$s — %3$s — %4$s — %5$s',
			sanitize_text_field( (string) $product->name ),
			$this->get_portal_product_price_label( $product ),
			$interval,
			$status,
			$price_id
		);
	}

	private function get_portal_service_snapshot_key( $data ) {
		$customer_identifier = ! empty( $data['stripe_customer_id'] ) ? $data['stripe_customer_id'] : ( ! empty( $data['guest_customer_id'] ) ? $data['guest_customer_id'] : ( ! empty( $data['customer_email'] ) ? strtolower( $data['customer_email'] ) : '' ) );
		$product_part = ! empty( $data['product_id'] ) ? $data['product_id'] : ( ! empty( $data['product_name_snapshot'] ) ? sanitize_title( (string) $data['product_name_snapshot'] ) : '' );
		$price_part   = ! empty( $data['price_id'] ) ? $data['price_id'] : '';
		if ( ! empty( $data['subscription_id'] ) ) {
			return 'svc_' . sha1( implode( '|', array( $customer_identifier, $product_part, $price_part, $data['subscription_id'], isset( $data['service_period_start'] ) ? $data['service_period_start'] : '', isset( $data['service_period_end'] ) ? $data['service_period_end'] : '' ) ) );
		}
		foreach ( array( 'payment_intent_id', 'checkout_session_id', 'invoice_id', 'charge_id' ) as $flow_id ) {
			if ( ! empty( $data[ $flow_id ] ) ) {
				return 'svc_' . sha1( implode( '|', array( $customer_identifier, $product_part, $price_part, $data[ $flow_id ], isset( $data['service_period_start'] ) ? $data['service_period_start'] : '', isset( $data['service_period_end'] ) ? $data['service_period_end'] : '' ) ) );
			}
		}

		$parts = array(
			$customer_identifier,
			$product_part,
			$price_part,
			isset( $data['subscription_id'] ) ? $data['subscription_id'] : '',
			isset( $data['checkout_session_id'] ) ? $data['checkout_session_id'] : '',
			isset( $data['invoice_id'] ) ? $data['invoice_id'] : '',
			isset( $data['payment_intent_id'] ) ? $data['payment_intent_id'] : '',
			isset( $data['service_period_start'] ) ? $data['service_period_start'] : '',
			isset( $data['service_period_end'] ) ? $data['service_period_end'] : '',
			isset( $data['product_name_snapshot'] ) ? sanitize_title( (string) $data['product_name_snapshot'] ) : '',
		);

		return 'svc_' . sha1( implode( '|', array_map( 'strval', $parts ) ) );
	}

	private function upsert_portal_service_snapshot( $data ) {
		global $wpdb;

		$defaults = array(
			'snapshot_key'          => '',
			'stripe_customer_id'    => '',
			'guest_customer_id'     => '',
			'customer_email'        => '',
			'product_id'            => '',
			'price_id'              => '',
			'product_name_snapshot' => '',
			'price_label_snapshot'  => '',
			'amount'                => 0,
			'currency'              => 'usd',
			'recurring_interval'    => '',
			'quantity'              => 1,
			'billing_type'          => 'one_time',
			'checkout_session_id'   => '',
			'invoice_id'            => '',
			'payment_intent_id'     => '',
			'charge_id'             => '',
			'subscription_id'       => '',
			'service_period_start'  => null,
			'service_period_end'    => null,
			'service_period'        => '',
			'next_billing_date'     => null,
			'source_type'           => '',
			'status'                => '',
			'livemode'              => $this->get_current_stripe_livemode(),
			'raw_data'              => '',
		);
		$data = array_merge( $defaults, is_array( $data ) ? $data : array() );
		$data['stripe_customer_id']    = sanitize_text_field( (string) $data['stripe_customer_id'] );
		$data['guest_customer_id']     = sanitize_text_field( (string) $data['guest_customer_id'] );
		$data['customer_email']        = sanitize_email( (string) $data['customer_email'] );
		$data['product_id']            = sanitize_text_field( (string) $data['product_id'] );
		$data['price_id']              = sanitize_text_field( (string) $data['price_id'] );
		$data['product_name_snapshot'] = sanitize_text_field( (string) $data['product_name_snapshot'] );
		$data['price_label_snapshot']  = sanitize_text_field( (string) $data['price_label_snapshot'] );
		$data['amount']                = (float) $data['amount'];
		$data['currency']              = strtolower( sanitize_key( (string) $data['currency'] ) );
		$data['recurring_interval']    = sanitize_key( (string) $data['recurring_interval'] );
		$data['quantity']              = max( 1, absint( $data['quantity'] ) );
		$data['billing_type']          = 'recurring' === sanitize_key( (string) $data['billing_type'] ) ? 'recurring' : 'one_time';
		$data['checkout_session_id']   = sanitize_text_field( (string) $data['checkout_session_id'] );
		$data['invoice_id']            = sanitize_text_field( (string) $data['invoice_id'] );
		$data['payment_intent_id']     = sanitize_text_field( (string) $data['payment_intent_id'] );
		$data['charge_id']             = sanitize_text_field( (string) $data['charge_id'] );
		$data['subscription_id']       = sanitize_text_field( (string) $data['subscription_id'] );
		$data['service_period']        = sanitize_text_field( (string) $data['service_period'] );
		$data['next_billing_date']     = ! empty( $data['next_billing_date'] ) ? sanitize_text_field( (string) $data['next_billing_date'] ) : null;
		$data['source_type']           = sanitize_key( (string) $data['source_type'] );
		$data['status']                = sanitize_key( (string) $data['status'] );
		$data['livemode']              = ! empty( $data['livemode'] ) ? 1 : 0;

		if ( '' === $data['snapshot_key'] ) {
			$data['snapshot_key'] = $this->get_portal_service_snapshot_key( $data );
		}
		$data['snapshot_key'] = sanitize_text_field( (string) $data['snapshot_key'] );

		if ( '' === $data['stripe_customer_id'] && '' === $data['guest_customer_id'] && '' === $data['customer_email'] ) {
			return 0;
		}
		if ( '' === $data['product_name_snapshot'] && '' === $data['product_id'] && '' === $data['price_id'] ) {
			return 0;
		}

		$table = $this->get_portal_service_snapshots_table();
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE snapshot_key = %s LIMIT 1",
				$data['snapshot_key']
			)
		);

		$row = $data;
		$row['updated_at'] = current_time( 'mysql' );
		$formats = array(
			'%s', // snapshot_key
			'%s', // stripe_customer_id
			'%s', // guest_customer_id
			'%s', // customer_email
			'%s', // product_id
			'%s', // price_id
			'%s', // product_name_snapshot
			'%s', // price_label_snapshot
			'%f', // amount
			'%s', // currency
			'%s', // recurring_interval
			'%d', // quantity
			'%s', // billing_type
			'%s', // checkout_session_id
			'%s', // invoice_id
			'%s', // payment_intent_id
			'%s', // charge_id
			'%s', // subscription_id
			'%s', // service_period_start
			'%s', // service_period_end
			'%s', // service_period
			'%s', // next_billing_date
			'%s', // source_type
			'%s', // status
			'%d', // livemode
			'%s', // raw_data
			'%s', // updated_at
		);

		if ( $existing ) {
			$unchanged = (string) $existing->product_name_snapshot === (string) $row['product_name_snapshot']
				&& (float) $existing->amount === (float) $row['amount']
				&& (string) $existing->status === (string) $row['status']
				&& (int) $existing->livemode === (int) $row['livemode']
				&& (string) $existing->service_period === (string) $row['service_period']
				&& (string) $existing->service_period_start === (string) $row['service_period_start']
				&& (string) $existing->service_period_end === (string) $row['service_period_end']
				&& (string) $existing->next_billing_date === (string) $row['next_billing_date'];
			if ( $unchanged ) {
				$this->log_portal_event(
					'service_snapshot_skipped_duplicate',
					array(
						'source'             => $row['source_type'],
						'stripe_customer_id' => $row['stripe_customer_id'],
						'email_after'        => $row['customer_email'],
						'details'            => array(
							'snapshot_key' => $row['snapshot_key'],
							'product_name' => $row['product_name_snapshot'],
						),
					)
				);
				return (int) $existing->id;
			}

			$backfilled = array();
			foreach ( array( 'service_period', 'service_period_start', 'service_period_end', 'next_billing_date' ) as $field ) {
				if ( empty( $existing->$field ) && ! empty( $row[ $field ] ) ) {
					$backfilled[ $field ] = $row[ $field ];
				}
			}

			$wpdb->update( $table, $row, array( 'id' => (int) $existing->id ), $formats, array( '%d' ) );
			if ( ! empty( $backfilled ) ) {
				$this->log_portal_event(
					'service_snapshot_backfilled',
					array(
						'source'             => $row['source_type'],
						'stripe_customer_id' => $row['stripe_customer_id'],
						'email_after'        => $row['customer_email'],
						'details'            => array(
							'snapshot_key' => $row['snapshot_key'],
							'product_name' => $row['product_name_snapshot'],
							'backfilled'   => $backfilled,
						),
					)
				);
			}
			$this->log_portal_event(
				'service_snapshot_updated',
				array(
					'source'             => $row['source_type'],
					'stripe_customer_id' => $row['stripe_customer_id'],
					'email_after'        => $row['customer_email'],
					'details'            => array(
						'snapshot_key' => $row['snapshot_key'],
						'product_name' => $row['product_name_snapshot'],
						'status'       => $row['status'],
					),
				)
			);
			return (int) $existing->id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$formats[] = '%s';
		$wpdb->insert( $table, $row, $formats );
		$insert_id = (int) $wpdb->insert_id;
		if ( $insert_id ) {
			$this->log_portal_event(
				'service_snapshot_created',
				array(
					'source'             => $row['source_type'],
					'stripe_customer_id' => $row['stripe_customer_id'],
					'email_after'        => $row['customer_email'],
					'details'            => array(
						'snapshot_key' => $row['snapshot_key'],
						'product_name' => $row['product_name_snapshot'],
						'billing_type' => $row['billing_type'],
						'amount'       => $row['amount'],
						'currency'     => $row['currency'],
					),
				)
			);
		}

		return $insert_id;
	}

	private function get_portal_service_snapshot_customer_context( $object ) {
		$stripe_customer_id = $this->get_payment_customer_id( is_array( $object ) ? $object : array() );
		$email = '';
		foreach ( array( 'customer_email', 'receipt_email' ) as $key ) {
			if ( ! empty( $object[ $key ] ) && is_scalar( $object[ $key ] ) ) {
				$email = sanitize_email( (string) $object[ $key ] );
				break;
			}
		}
		if ( '' === $email && ! empty( $object['customer_details']['email'] ) ) {
			$email = sanitize_email( (string) $object['customer_details']['email'] );
		}
		if ( '' === $email && ! empty( $object['billing_details']['email'] ) ) {
			$email = sanitize_email( (string) $object['billing_details']['email'] );
		}

		return array(
			'stripe_customer_id' => $this->is_real_stripe_customer_id( $stripe_customer_id ) ? $stripe_customer_id : '',
			'guest_customer_id'  => $this->is_real_stripe_customer_id( $stripe_customer_id ) ? '' : ( '' !== $stripe_customer_id ? $stripe_customer_id : $this->get_guest_portal_customer_id( $email ) ),
			'customer_email'     => $email,
		);
	}

	private function get_portal_service_snapshot_line_items( $object ) {
		foreach ( array( 'line_items', 'lines', 'items', 'display_items' ) as $key ) {
			if ( empty( $object[ $key ] ) || ! is_array( $object[ $key ] ) ) {
				continue;
			}
			$items = $object[ $key ];
			if ( isset( $items['data'] ) && is_array( $items['data'] ) ) {
				$items = $items['data'];
			}
			if ( ! empty( $items ) ) {
				return array_values( array_filter( $items, 'is_array' ) );
			}
		}

		return array();
	}

	private function maybe_create_portal_service_snapshot_from_line_item( $item, $parent, $source_type ) {
		$customer = $this->get_portal_service_snapshot_customer_context( $parent );
		$price    = ! empty( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : ( ! empty( $item['plan'] ) && is_array( $item['plan'] ) ? $item['plan'] : array() );
		$price_id = ! empty( $price['id'] ) ? sanitize_text_field( (string) $price['id'] ) : ( ! empty( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '' );
		$product_id = '';
		$product_name = '';
		if ( ! empty( $price['product'] ) && is_array( $price['product'] ) ) {
			$product_id   = ! empty( $price['product']['id'] ) ? sanitize_text_field( (string) $price['product']['id'] ) : '';
			$product_name = ! empty( $price['product']['name'] ) ? sanitize_text_field( (string) $price['product']['name'] ) : '';
		} elseif ( ! empty( $price['product'] ) && is_scalar( $price['product'] ) ) {
			$product_id = sanitize_text_field( (string) $price['product'] );
		}
		if ( '' === $product_id && ! empty( $item['product'] ) && is_array( $item['product'] ) ) {
			$product_id   = ! empty( $item['product']['id'] ) ? sanitize_text_field( (string) $item['product']['id'] ) : '';
			$product_name = '' === $product_name && ! empty( $item['product']['name'] ) ? sanitize_text_field( (string) $item['product']['name'] ) : $product_name;
		} elseif ( '' === $product_id && ! empty( $item['product'] ) && is_scalar( $item['product'] ) ) {
			$product_id = sanitize_text_field( (string) $item['product'] );
		}

		$product = $this->resolve_portal_product_from_identifiers(
			array(
				'price_ids'           => '' !== $price_id ? array( $price_id ) : array(),
				'product_ids'         => '' !== $product_id ? array( $product_id ) : array(),
				'subscription_ids'    => array(),
				'checkout_session_ids'=> array(),
				'invoice_ids'         => array(),
				'payment_intent_ids'  => array(),
			)
		);
		if ( $product ) {
			$product_id = '' !== $product_id ? $product_id : sanitize_text_field( (string) $product->stripe_product_id );
			$price_id   = '' !== $price_id ? $price_id : sanitize_text_field( (string) $product->stripe_price_id );
		}

		if ( '' === $product_name ) {
			$product_name = $product && ! empty( $product->name ) ? sanitize_text_field( (string) $product->name ) : '';
		}
		if ( '' === $product_name && ! empty( $item['description'] ) ) {
			$product_name = sanitize_text_field( (string) $item['description'] );
		}
		if ( '' === $product_name && ! empty( $parent['description'] ) && ! $this->is_generic_portal_service_label( $parent['description'] ) ) {
			$product_name = sanitize_text_field( (string) $parent['description'] );
		}

		$currency = ! empty( $item['currency'] ) ? strtolower( sanitize_key( (string) $item['currency'] ) ) : ( ! empty( $price['currency'] ) ? strtolower( sanitize_key( (string) $price['currency'] ) ) : ( ! empty( $parent['currency'] ) ? strtolower( sanitize_key( (string) $parent['currency'] ) ) : 'usd' ) );
		$quantity = ! empty( $item['quantity'] ) ? absint( $item['quantity'] ) : 1;
		$amount_cents = isset( $item['amount_total'] ) ? $item['amount_total'] : ( isset( $item['amount'] ) ? $item['amount'] : ( isset( $price['unit_amount'] ) ? ( (int) $price['unit_amount'] * $quantity ) : 0 ) );
		$amount = $this->stripe_amount_to_decimal( $amount_cents, $currency );
		$interval = ! empty( $price['recurring']['interval'] ) ? sanitize_key( (string) $price['recurring']['interval'] ) : ( $product && ! empty( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '' );
		$item_subscription_id = ! empty( $item['subscription'] ) && is_scalar( $item['subscription'] ) ? sanitize_text_field( (string) $item['subscription'] ) : '';
		$parent_subscription_id = ! empty( $parent['subscription'] ) && is_scalar( $parent['subscription'] ) ? sanitize_text_field( (string) $parent['subscription'] ) : '';
		$subscription_id = $item_subscription_id;
		if ( 'subscription' === $source_type && ! empty( $parent['id'] ) ) {
			$subscription_id = sanitize_text_field( (string) $parent['id'] );
		}

		$period_start = ! empty( $item['period']['start'] ) ? $this->stripe_timestamp_to_mysql( $item['period']['start'] ) : ( ! empty( $parent['current_period_start'] ) ? $this->stripe_timestamp_to_mysql( $parent['current_period_start'] ) : null );
		$period_end   = ! empty( $item['period']['end'] ) ? $this->stripe_timestamp_to_mysql( $item['period']['end'] ) : ( ! empty( $parent['current_period_end'] ) ? $this->stripe_timestamp_to_mysql( $parent['current_period_end'] ) : null );
		$service_period = '';
		$item_metadata = ! empty( $item['metadata'] ) && is_array( $item['metadata'] ) ? $item['metadata'] : array();
		$parent_metadata = ! empty( $parent['metadata'] ) && is_array( $parent['metadata'] ) ? $parent['metadata'] : array();
		foreach ( array( $item_metadata, $parent_metadata ) as $metadata ) {
			foreach ( array( 'service_period', 'period' ) as $period_key ) {
				if ( ! empty( $metadata[ $period_key ] ) && is_scalar( $metadata[ $period_key ] ) ) {
					$service_period = sanitize_text_field( (string) $metadata[ $period_key ] );
					break 2;
				}
			}
		}
		if ( '' === $service_period && 'invoice' === $source_type ) {
			$line_summary = $this->get_invoice_line_summary( $parent );
			$service_period = ! empty( $line_summary['service_period'] ) ? sanitize_text_field( (string) $line_summary['service_period'] ) : '';
			if ( empty( $period_start ) && ! empty( $line_summary['service_period_start'] ) ) {
				$period_start = $line_summary['service_period_start'];
			}
			if ( empty( $period_end ) && ! empty( $line_summary['service_period_end'] ) ) {
				$period_end = $line_summary['service_period_end'];
			}
		}
		$status       = ! empty( $parent['status'] ) ? sanitize_key( (string) $parent['status'] ) : ( ! empty( $parent['payment_status'] ) ? sanitize_key( (string) $parent['payment_status'] ) : '' );
		if ( '' === $interval && $product && ! empty( $product->recurring_interval ) ) {
			$interval = sanitize_key( (string) $product->recurring_interval );
		}
		if ( '' === $subscription_id && '' !== $interval && '' !== $parent_subscription_id ) {
			$subscription_id = $parent_subscription_id;
		}
		$billing_type = ( '' !== $interval || '' !== $subscription_id ) ? 'recurring' : 'one_time';
		if ( '' === $service_period && $period_start && $period_end ) {
			$service_period = $this->format_portal_date( $period_start ) . ' - ' . $this->format_portal_date( $period_end );
		}
		$next_billing_date = null;
		foreach ( array( $item_metadata, $parent_metadata ) as $metadata ) {
			foreach ( array( 'next_billing_date', 'next_renewal_date', 'renewal_date', 'due_date' ) as $date_key ) {
				if ( ! empty( $metadata[ $date_key ] ) && is_scalar( $metadata[ $date_key ] ) ) {
					$timestamp = strtotime( (string) $metadata[ $date_key ] );
					$next_billing_date = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : sanitize_text_field( (string) $metadata[ $date_key ] );
					break 2;
				}
			}
		}
		if ( empty( $next_billing_date ) && 'recurring' === $billing_type && $period_end ) {
			$next_billing_date = $period_end;
		}
		$price_label  = $this->format_portal_money( $amount, $currency ) . ( '' !== $interval ? '/' . $interval : '' );

		return $this->upsert_portal_service_snapshot(
			array_merge(
				$customer,
				array(
					'product_id'            => $product_id,
					'price_id'              => $price_id,
					'product_name_snapshot' => $product_name,
					'price_label_snapshot'  => $price_label,
					'amount'                => $amount,
					'currency'              => $currency,
					'recurring_interval'    => $interval,
					'quantity'              => $quantity,
					'billing_type'          => $billing_type,
					'checkout_session_id'   => 'checkout_session' === $source_type && ! empty( $parent['id'] ) ? sanitize_text_field( (string) $parent['id'] ) : '',
					'invoice_id'            => 'invoice' === $source_type && ! empty( $parent['id'] ) ? sanitize_text_field( (string) $parent['id'] ) : ( ! empty( $parent['invoice'] ) && is_scalar( $parent['invoice'] ) ? sanitize_text_field( (string) $parent['invoice'] ) : '' ),
					'payment_intent_id'     => ! empty( $parent['payment_intent'] ) && is_scalar( $parent['payment_intent'] ) ? sanitize_text_field( (string) $parent['payment_intent'] ) : '',
					'charge_id'             => 'charge' === $source_type && ! empty( $parent['id'] ) ? sanitize_text_field( (string) $parent['id'] ) : ( ! empty( $parent['charge'] ) && is_scalar( $parent['charge'] ) ? sanitize_text_field( (string) $parent['charge'] ) : '' ),
					'subscription_id'       => $subscription_id,
					'service_period_start'  => $period_start,
					'service_period_end'    => $period_end,
					'service_period'        => $service_period,
					'next_billing_date'     => $next_billing_date,
					'source_type'           => $source_type,
					'status'                => $status,
					'livemode'              => ! empty( $parent['livemode'] ) ? 1 : $this->get_current_stripe_livemode(),
					'raw_data'              => wp_json_encode( array( 'parent' => $parent, 'item' => $item ) ),
				)
			)
		);
	}

	private function maybe_create_portal_service_snapshots_from_stripe_object( $object, $source_type ) {
		if ( ! is_array( $object ) || empty( $object['id'] ) ) {
			return 0;
		}

		if ( 'checkout_session' === $source_type ) {
			$checkout_status = ! empty( $object['status'] ) ? sanitize_key( (string) $object['status'] ) : '';
			$payment_status  = ! empty( $object['payment_status'] ) ? sanitize_key( (string) $object['payment_status'] ) : '';
			if ( 'paid' !== $payment_status && ! in_array( $checkout_status, array( 'complete', 'completed' ), true ) ) {
				return 0;
			}
		}

		$count = 0;
		$items = $this->get_portal_service_snapshot_line_items( $object );
		if ( empty( $items ) ) {
			$items = array( $object );
		}
		foreach ( $items as $item ) {
			$count += $this->maybe_create_portal_service_snapshot_from_line_item( $item, $object, $source_type ) ? 1 : 0;
		}

		return $count;
	}

	private function get_portal_service_charge_ledger_source_id( $snapshot ) {
		$snapshot_key = ! empty( $snapshot->snapshot_key ) ? sanitize_text_field( (string) $snapshot->snapshot_key ) : '';
		if ( '' === $snapshot_key ) {
			$parts = array(
				! empty( $snapshot->stripe_customer_id ) ? $snapshot->stripe_customer_id : '',
				! empty( $snapshot->product_id ) ? $snapshot->product_id : '',
				! empty( $snapshot->price_id ) ? $snapshot->price_id : '',
				! empty( $snapshot->subscription_id ) ? $snapshot->subscription_id : '',
				! empty( $snapshot->checkout_session_id ) ? $snapshot->checkout_session_id : '',
				! empty( $snapshot->invoice_id ) ? $snapshot->invoice_id : '',
				! empty( $snapshot->payment_intent_id ) ? $snapshot->payment_intent_id : '',
				! empty( $snapshot->charge_id ) ? $snapshot->charge_id : '',
				! empty( $snapshot->service_period_start ) ? $snapshot->service_period_start : '',
				! empty( $snapshot->service_period_end ) ? $snapshot->service_period_end : '',
			);
			$snapshot_key = implode( '|', array_map( 'strval', $parts ) );
		}

		return 'svc_charge_' . substr( sha1( $snapshot_key ), 0, 32 );
	}

	private function should_create_portal_service_charge_ledger_row( $snapshot ) {
		$amount = isset( $snapshot->amount ) ? (float) $snapshot->amount : 0.0;
		if ( $amount <= 0 ) {
			return false;
		}

		$source_type = ! empty( $snapshot->source_type ) ? sanitize_key( (string) $snapshot->source_type ) : '';
		if ( ! in_array( $source_type, array( 'invoice', 'checkout_session' ), true ) ) {
			return false;
		}

		$status = ! empty( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : '';
		if ( in_array( $status, array( 'failed', 'void', 'voided', 'draft', 'requires_payment_method' ), true ) ) {
			return false;
		}

		$service_name = ! empty( $snapshot->product_name_snapshot ) ? sanitize_text_field( (string) $snapshot->product_name_snapshot ) : '';
		if ( '' === $service_name && empty( $snapshot->product_id ) && empty( $snapshot->price_id ) ) {
			return false;
		}
		if ( '' !== $service_name && $this->is_generic_portal_service_label( $service_name ) && empty( $snapshot->product_id ) && empty( $snapshot->price_id ) ) {
			return false;
		}

		return true;
	}

	private function get_portal_service_charge_ledger_status( $snapshot ) {
		$status = ! empty( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : '';
		if ( in_array( $status, array( 'paid', 'succeeded', 'completed', 'active', 'trialing' ), true ) ) {
			return 'paid';
		}
		if ( in_array( $status, array( 'open', 'unpaid', 'pending', 'pending_payment', 'awaiting_payment' ), true ) ) {
			return $status;
		}

		return '' !== $status ? $status : 'paid';
	}

	private function get_portal_service_charge_ledger_date( $snapshot ) {
		$raw = ! empty( $snapshot->raw_data ) ? json_decode( (string) $snapshot->raw_data, true ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$parent = ! empty( $raw['parent'] ) && is_array( $raw['parent'] ) ? $raw['parent'] : array();
		foreach ( array( 'created', 'effective_at' ) as $timestamp_key ) {
			if ( ! empty( $parent[ $timestamp_key ] ) && is_numeric( $parent[ $timestamp_key ] ) ) {
				return $this->stripe_timestamp_to_mysql( $parent[ $timestamp_key ] );
			}
		}
		if ( ! empty( $parent['status_transitions']['paid_at'] ) && is_numeric( $parent['status_transitions']['paid_at'] ) ) {
			return $this->stripe_timestamp_to_mysql( $parent['status_transitions']['paid_at'] );
		}

		return ! empty( $snapshot->created_at ) ? $snapshot->created_at : current_time( 'mysql' );
	}

	private function backfill_portal_ledger_service_charges_from_snapshots( $stripe_customer_id = '' ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$where             = 'stripe_customer_id <> %s';
		$params            = array( '' );
		if ( '' !== $stripe_customer_id ) {
			$where    .= ' AND stripe_customer_id = %s';
			$params[] = $stripe_customer_id;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_service_snapshots_table()} WHERE {$where} ORDER BY updated_at ASC, id ASC",
				$params
			)
		);
		if ( empty( $rows ) ) {
			return 0;
		}

		$changed = 0;
		foreach ( $rows as $snapshot ) {
			if ( ! $this->should_create_portal_service_charge_ledger_row( $snapshot ) ) {
				continue;
			}

			$source_object_id = $this->get_portal_service_charge_ledger_source_id( $snapshot );
			$service_name     = ! empty( $snapshot->product_name_snapshot ) ? sanitize_text_field( (string) $snapshot->product_name_snapshot ) : __( 'Service charge', 'ajforms' );
			$ledger_date      = $this->get_portal_service_charge_ledger_date( $snapshot );
			$product_interval = $this->get_portal_product_recurring_interval_from_ids(
				! empty( $snapshot->price_id ) ? $snapshot->price_id : '',
				! empty( $snapshot->product_id ) ? $snapshot->product_id : ''
			);
			$effective_recurring_interval = null !== $product_interval ? $product_interval : ( ! empty( $snapshot->recurring_interval ) ? sanitize_key( (string) $snapshot->recurring_interval ) : '' );
			$effective_billing_type = '' !== $effective_recurring_interval ? 'recurring' : 'one_time';
			$invoice_metadata = array();
			if ( ! empty( $snapshot->invoice_id ) ) {
				$invoice_metadata_json = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT metadata FROM {$this->get_portal_ledger_table()} WHERE source_type = %s AND source_object_id = %s LIMIT 1",
						'invoice',
						sanitize_text_field( (string) $snapshot->invoice_id )
					)
				);
				$invoice_metadata = $this->decode_portal_json( $invoice_metadata_json );
			}
			$metadata         = array(
				'ledger_line_type'      => 'service_charge',
				'snapshot_key'          => ! empty( $snapshot->snapshot_key ) ? sanitize_text_field( (string) $snapshot->snapshot_key ) : '',
				'product_id'            => ! empty( $snapshot->product_id ) ? sanitize_text_field( (string) $snapshot->product_id ) : '',
				'price_id'              => ! empty( $snapshot->price_id ) ? sanitize_text_field( (string) $snapshot->price_id ) : '',
				'price_label_snapshot'  => ! empty( $snapshot->price_label_snapshot ) ? sanitize_text_field( (string) $snapshot->price_label_snapshot ) : '',
				'billing_type'          => $effective_billing_type,
				'recurring_interval'    => $effective_recurring_interval,
				'quantity'              => ! empty( $snapshot->quantity ) ? absint( $snapshot->quantity ) : 1,
				'checkout_session_id'   => ! empty( $snapshot->checkout_session_id ) ? sanitize_text_field( (string) $snapshot->checkout_session_id ) : '',
				'invoice_id'            => ! empty( $snapshot->invoice_id ) ? sanitize_text_field( (string) $snapshot->invoice_id ) : '',
				'payment_intent_id'     => ! empty( $snapshot->payment_intent_id ) ? sanitize_text_field( (string) $snapshot->payment_intent_id ) : '',
				'charge_id'             => ! empty( $snapshot->charge_id ) ? sanitize_text_field( (string) $snapshot->charge_id ) : '',
				'subscription_id'       => 'recurring' === $effective_billing_type && ! empty( $snapshot->subscription_id ) ? sanitize_text_field( (string) $snapshot->subscription_id ) : '',
				'service_period'        => ! empty( $snapshot->service_period ) ? sanitize_text_field( (string) $snapshot->service_period ) : '',
				'service_period_start'  => ! empty( $snapshot->service_period_start ) ? sanitize_text_field( (string) $snapshot->service_period_start ) : '',
				'service_period_end'    => ! empty( $snapshot->service_period_end ) ? sanitize_text_field( (string) $snapshot->service_period_end ) : '',
				'next_billing_date'     => ! empty( $snapshot->next_billing_date ) ? sanitize_text_field( (string) $snapshot->next_billing_date ) : '',
				'snapshot_source_type'  => ! empty( $snapshot->source_type ) ? sanitize_key( (string) $snapshot->source_type ) : '',
			);
			foreach ( array( 'invoice_number', 'invoice_pdf', 'hosted_invoice_url' ) as $invoice_field ) {
				if ( ! empty( $invoice_metadata[ $invoice_field ] ) && is_scalar( $invoice_metadata[ $invoice_field ] ) ) {
					$metadata[ $invoice_field ] = sanitize_text_field( (string) $invoice_metadata[ $invoice_field ] );
				}
			}
			$data             = array(
				'stripe_customer_id' => sanitize_text_field( (string) $snapshot->stripe_customer_id ),
				'source_object_id'   => $source_object_id,
				'source_type'        => 'service_charge',
				'ledger_date'        => $ledger_date,
				'description'        => $service_name,
				'amount'             => (float) $snapshot->amount,
				'currency'           => ! empty( $snapshot->currency ) ? strtolower( sanitize_key( (string) $snapshot->currency ) ) : 'usd',
				'status'             => $this->get_portal_service_charge_ledger_status( $snapshot ),
				'invoice_id'         => ! empty( $snapshot->invoice_id ) ? sanitize_text_field( (string) $snapshot->invoice_id ) : '',
				'payment_intent_id'  => ! empty( $snapshot->payment_intent_id ) ? sanitize_text_field( (string) $snapshot->payment_intent_id ) : '',
				'charge_id'          => ! empty( $snapshot->charge_id ) ? sanitize_text_field( (string) $snapshot->charge_id ) : '',
				'metadata'           => wp_json_encode( $metadata ),
				'created_at'         => current_time( 'mysql' ),
			);
			$formats          = array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
			$existing_id      = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->get_portal_ledger_table()} WHERE source_object_id = %s LIMIT 1",
					$source_object_id
				)
			);

			if ( $existing_id ) {
				$result = $wpdb->update( $this->get_portal_ledger_table(), $data, array( 'id' => $existing_id ), $formats, array( '%d' ) );
			} else {
				$result = $wpdb->insert( $this->get_portal_ledger_table(), $data, $formats );
			}

			if ( false !== $result && $result > 0 ) {
				$changed++;
			}
		}

		if ( $changed > 0 ) {
			$this->log_portal_event(
				'ledger_service_charges_backfilled',
				array(
					'source'             => 'stripe_sync',
					'stripe_customer_id' => $stripe_customer_id,
					'details'            => array(
						'rows_changed' => $changed,
					),
				)
			);
		}

		return $changed;
	}

	private function get_portal_product_by_price_id( $price_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			return null;
		}

		$by_price = $this->get_portal_product_cache_by_price_id();
		return isset( $by_price[ $price_id ] ) ? $by_price[ $price_id ] : null;
	}

	private function get_portal_service_period_from_ledger_entry( $entry ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		foreach ( array( 'service_period', 'period' ) as $key ) {
			if ( ! empty( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				return sanitize_text_field( (string) $metadata[ $key ] );
			}
		}

		return '';
	}

	private function get_ledger_metadata_value( $entry, $key ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		if ( ! is_array( $metadata ) || empty( $key ) ) {
			return '';
		}

		$key = sanitize_key( (string) $key );
		return isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ? sanitize_text_field( (string) $metadata[ $key ] ) : '';
	}

	private function clean_stripe_line_service_name( $description ) {
		$description = sanitize_text_field( (string) $description );
		$description = preg_replace( '/^\s*\d+\s*(?:x|\x{00D7})\s*/iu', '', $description );
		$description = preg_replace( '/\s*\(at\s+.*?\)\s*$/i', '', $description );

		return trim( $description );
	}

	private function get_portal_product_recurring_interval_from_ids( $price_id = '', $product_id = '' ) {
		global $wpdb;

		$price_id   = sanitize_text_field( (string) $price_id );
		$product_id = sanitize_text_field( (string) $product_id );

		if ( '' !== $price_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT recurring_interval FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s LIMIT 1",
					$price_id
				)
			);
			if ( $row ) {
				return ! empty( $row->recurring_interval ) ? sanitize_key( (string) $row->recurring_interval ) : '';
			}
			return null;
		}

		if ( '' !== $product_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT recurring_interval FROM {$this->get_portal_stripe_products_table()} WHERE stripe_product_id = %s ORDER BY recurring_interval DESC, active DESC, id DESC LIMIT 1",
					$product_id
				)
			);
			if ( $row ) {
				return ! empty( $row->recurring_interval ) ? sanitize_key( (string) $row->recurring_interval ) : '';
			}
			return null;
		}

		return null;
	}

	private function get_portal_service_record_period_label( $record ) {
		if ( ! empty( $record->service_period ) && '-' !== (string) $record->service_period ) {
			return sanitize_text_field( (string) $record->service_period );
		}

		$start = ! empty( $record->service_period_start ) ? $record->service_period_start : '';
		$end   = ! empty( $record->service_period_end ) ? $record->service_period_end : '';
		if ( $start && $end ) {
			return $this->format_portal_date( $start ) . ' - ' . $this->format_portal_date( $end );
		}
		if ( $end ) {
			return 'one_time' === ( isset( $record->billing_type_key ) ? $record->billing_type_key : '' )
				? sprintf( __( 'Through %s', 'ajforms' ), $this->format_portal_date( $end ) )
				: sprintf( __( 'Through %s', 'ajforms' ), $this->format_portal_date( $end ) );
		}
		if ( ! empty( $record->paid_at ) ) {
			return sprintf( __( 'Purchased %s', 'ajforms' ), $this->format_portal_date( $record->paid_at ) );
		}

		return '-';
	}

	private function get_portal_service_record_next_billing_date_label( $record ) {
		$billing_type = isset( $record->billing_type_key ) ? sanitize_key( (string) $record->billing_type_key ) : '';
		if ( 'subscription' !== $billing_type && 'recurring' !== $billing_type ) {
			return ! empty( $record->next_billing_date ) && '-' !== (string) $record->next_billing_date ? $this->format_portal_date( $record->next_billing_date ) : '-';
		}

		foreach ( array( 'next_billing_date', 'current_period_end', 'service_period_end' ) as $field ) {
			if ( ! empty( $record->$field ) && '-' !== (string) $record->$field ) {
				return $this->format_portal_date( $record->$field );
			}
		}

		return '-';
	}

	private function is_generic_portal_service_label( $label ) {
		$label = trim( sanitize_text_field( (string) $label ) );
		if ( '' === $label ) {
			return true;
		}

		$normalized = strtolower( preg_replace( '/\s+/', ' ', $label ) );
		$generic    = array(
			'payment',
			'website checkout payment',
			'checkout payment',
			'service checkout payment',
			'balance payment',
			'subscription creation',
			'billing item',
			'charge',
			'invoice',
			'checkout session',
			'customer',
			'product',
			'price',
			'ajcore_products_cart',
			'ajcore_portal_add_service',
			'ajcore_portal_balance_payment',
		);

		if ( in_array( $normalized, $generic, true ) ) {
			return true;
		}

		if ( preg_match( '/^(charge|payment|invoice|checkout session|subscription creation)\s+(ch_|py_|pi_|in_|cs_)/', $normalized ) ) {
			return true;
		}

		return preg_match( '/^(cs_|pi_|ch_|in_|cus_|prod_|price_|sub_)/', $normalized );
	}

	private function add_portal_service_label_candidate( &$candidates, $label, $priority = 50 ) {
		if ( ! is_scalar( $label ) ) {
			return;
		}

		$label = trim( sanitize_text_field( (string) $label ) );
		if ( $this->is_generic_portal_service_label( $label ) ) {
			return;
		}

		$candidates[] = array(
			'label'    => $label,
			'priority' => absint( $priority ),
		);
	}

	private function collect_portal_service_label_candidates_from_item( $item, &$candidates ) {
		if ( ! is_array( $item ) ) {
			return;
		}

		foreach ( array( 'product_name', 'service_name', 'name' ) as $key ) {
			if ( isset( $item[ $key ] ) ) {
				$this->add_portal_service_label_candidate( $candidates, $item[ $key ], 10 );
			}
		}

		if ( ! empty( $item['description'] ) ) {
			$this->add_portal_service_label_candidate( $candidates, $item['description'], 40 );
		}

		if ( ! empty( $item['price'] ) && is_array( $item['price'] ) && ! empty( $item['price']['product'] ) ) {
			if ( is_array( $item['price']['product'] ) && ! empty( $item['price']['product']['name'] ) ) {
				$this->add_portal_service_label_candidate( $candidates, $item['price']['product']['name'], 12 );
			} elseif ( is_scalar( $item['price']['product'] ) ) {
				$product = $this->resolve_portal_product_from_identifiers(
					array(
						'price_ids'           => array(),
						'product_ids'         => array( sanitize_text_field( (string) $item['price']['product'] ) ),
						'subscription_ids'    => array(),
						'checkout_session_ids'=> array(),
						'invoice_ids'         => array(),
						'payment_intent_ids'  => array(),
					)
				);
				if ( $product && ! empty( $product->name ) ) {
					$this->add_portal_service_label_candidate( $candidates, $product->name, 20 );
				}
			}
		}

		if ( ! empty( $item['price'] ) && is_array( $item['price'] ) ) {
			foreach ( array( 'nickname', 'description' ) as $key ) {
				if ( isset( $item['price'][ $key ] ) ) {
					$this->add_portal_service_label_candidate( $candidates, $item['price'][ $key ], 35 );
				}
			}
			if ( ! empty( $item['price']['product'] ) && is_array( $item['price']['product'] ) ) {
				$this->collect_portal_service_label_candidates_from_item( $item['price']['product'], $candidates );
			}
		}

		if ( ! empty( $item['product'] ) && is_array( $item['product'] ) ) {
			$this->collect_portal_service_label_candidates_from_item( $item['product'], $candidates );
		}
	}

	private function collect_portal_service_label_candidates_from_data( $data, &$candidates ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( array( 'line_items', 'lines', 'items', 'display_items' ) as $container_key ) {
			if ( empty( $data[ $container_key ] ) || ! is_array( $data[ $container_key ] ) ) {
				continue;
			}

			$items = $data[ $container_key ];
			if ( isset( $items['data'] ) && is_array( $items['data'] ) ) {
				$items = $items['data'];
			}
			foreach ( $items as $item ) {
				$this->collect_portal_service_label_candidates_from_item( $item, $candidates );
			}
		}

		foreach ( array( 'product_name', 'service_name', 'item_name', 'line_item_description', 'product_label', 'description' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$priority = 'description' === $key ? 80 : 25;
				$this->add_portal_service_label_candidate( $candidates, $data[ $key ], $priority );
			}
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_portal_service_label_candidates_from_data( $value, $candidates );
			}
		}
	}

	private function get_portal_fallback_service_name_from_ledger_entry( $entry ) {
		$candidates = array();
		$this->collect_portal_service_label_candidates_from_data( $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' ), $candidates );
		$this->collect_portal_service_label_candidates_from_data( $this->decode_portal_json( isset( $entry->raw_data ) ? $entry->raw_data : '' ), $candidates );
		$this->collect_portal_service_label_candidates_from_data( $this->decode_portal_json( isset( $entry->transaction_raw_data ) ? $entry->transaction_raw_data : '' ), $candidates );
		foreach ( $this->get_related_portal_transaction_raw_data_for_entry( $entry ) as $raw_data ) {
			$this->collect_portal_service_label_candidates_from_data( $raw_data, $candidates );
		}
		if ( isset( $entry->description ) ) {
			$this->add_portal_service_label_candidate( $candidates, $entry->description, 100 );
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return (int) $a['priority'] <=> (int) $b['priority'];
			}
		);

		$seen = array();
		foreach ( $candidates as $candidate ) {
			$key = strtolower( $candidate['label'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			return $candidate['label'];
		}

		return '';
	}

	private function record_portal_service_display_skip( $entry, $reason ) {
		if ( count( $this->portal_service_display_skips ) >= 25 ) {
			return;
		}

		$this->portal_service_display_skips[] = array(
			'reason'           => sanitize_text_field( (string) $reason ),
			'customer'         => ! empty( $entry->customer_name ) ? sanitize_text_field( (string) $entry->customer_name ) : ( ! empty( $entry->customer_email ) ? sanitize_email( (string) $entry->customer_email ) : sanitize_text_field( (string) $entry->stripe_customer_id ) ),
			'stripe_customer_id'=> isset( $entry->stripe_customer_id ) ? sanitize_text_field( (string) $entry->stripe_customer_id ) : '',
			'source_type'      => isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '',
			'source_object_id' => isset( $entry->source_object_id ) ? sanitize_text_field( (string) $entry->source_object_id ) : '',
			'status'           => isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '',
			'amount'           => $this->format_portal_money( isset( $entry->amount ) ? $entry->amount : 0, isset( $entry->currency ) ? $entry->currency : 'usd' ),
		);
	}

	private function get_portal_service_dedupe_key( $record ) {
		$customer = isset( $record->stripe_customer_id ) ? (string) $record->stripe_customer_id : '';
		$product  = isset( $record->stripe_product_id ) ? (string) $record->stripe_product_id : '';
		$price    = isset( $record->stripe_price_id ) ? (string) $record->stripe_price_id : '';
		if ( ! empty( $record->stripe_subscription_id ) ) {
			return implode( ':', array_filter( array( $customer, (string) $record->stripe_subscription_id ), 'strlen' ) );
		}

		$flow_ids = array();
		foreach ( array( 'checkout_session_id', 'invoice_id', 'payment_intent_id' ) as $field ) {
			if ( ! empty( $record->{$field} ) ) {
				$flow_ids[] = (string) $record->{$field};
			}
		}

		if ( '' !== $customer && ( '' !== $product || '' !== $price ) && ! empty( $flow_ids ) ) {
			return implode( ':', array_filter( array_merge( array( $customer, $product, $price ), $flow_ids ), 'strlen' ) );
		}

		$parts = array(
			$customer,
			$product,
			$price,
			isset( $record->service_name ) ? sanitize_title( (string) $record->service_name ) : '',
			isset( $record->service_period ) ? (string) $record->service_period : '',
		);

		$key = implode( ':', array_filter( $parts, 'strlen' ) );
		return '' !== $key ? $key : md5( wp_json_encode( $record ) );
	}

	private function get_portal_one_time_service_dedupe_key( $record ) {
		$customer_id = '';
		foreach ( array( 'stripe_customer_id', 'customer_id', 'local_customer_id' ) as $field ) {
			if ( ! empty( $record->{$field} ) ) {
				$customer_id = sanitize_text_field( (string) $record->{$field} );
				break;
			}
		}
		if ( '' === $customer_id && ! empty( $record->customer ) ) {
			$customer_id = sanitize_title( (string) $record->customer );
		}

		$service_parts = array();
		foreach ( array( 'stripe_product_id', 'stripe_price_id', 'checkout_session_id', 'invoice_id', 'payment_intent_id' ) as $field ) {
			if ( ! empty( $record->{$field} ) ) {
				$service_parts[] = sanitize_text_field( (string) $record->{$field} );
			}
		}

		if ( empty( $service_parts ) && ! empty( $record->charge_id ) ) {
			$service_parts[] = sanitize_text_field( (string) $record->charge_id );
		}

		if ( ! empty( $record->service_name ) ) {
			$service_parts[] = sanitize_title( (string) $record->service_name );
		}
		if ( ! empty( $record->price ) ) {
			$service_parts[] = sanitize_title( (string) $record->price );
		}
		if ( ! empty( $record->amount ) ) {
			$service_parts[] = sanitize_title( (string) $record->amount );
		}
		if ( ! empty( $record->service_period ) ) {
			$service_parts[] = sanitize_title( (string) $record->service_period );
		}

		$parts = array_filter( array_merge( array( $customer_id ), $service_parts ), 'strlen' );
		return ! empty( $parts ) ? implode( ':', $parts ) : md5( wp_json_encode( $record ) );
	}

	private function get_portal_one_time_service_source_id( $record ) {
		foreach ( array( 'checkout_session_id', 'invoice_id', 'payment_intent_id', 'charge_id', 'source_ref' ) as $field ) {
			if ( ! empty( $record->{$field} ) ) {
				return sanitize_text_field( (string) $record->{$field} );
			}
		}

		return '';
	}

	private function text_contains_recurring_price_interval( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		if ( '' === trim( $value ) ) {
			return false;
		}

		return (bool) preg_match( '/(?:\/|\bper\b)\s*(day|week|month|year)s?\b/i', $value );
	}

	private function is_displayable_one_time_service_record( $record ) {
		$name = ! empty( $record->service_name ) ? sanitize_text_field( (string) $record->service_name ) : '';
		if ( '' === $name || $this->is_generic_portal_service_label( $name ) ) {
			return false;
		}

		if ( ! empty( $record->stripe_subscription_id ) ) {
			return false;
		}

		$billing_type = ! empty( $record->billing_type_key ) ? sanitize_key( (string) $record->billing_type_key ) : '';
		if ( '' !== $billing_type && 'one_time' !== $billing_type ) {
			return false;
		}

		foreach ( array( 'service_name', 'price', 'amount' ) as $field ) {
			if ( ! empty( $record->{$field} ) && $this->text_contains_recurring_price_interval( $record->{$field} ) ) {
				return false;
			}
		}

		return true;
	}

	private function get_portal_service_status_rank( $status ) {
		$ranks = array(
			'active'          => 0,
			'trialing'        => 0,
			'paid'            => 1,
			'succeeded'       => 2,
			'completed'       => 3,
			'pending_payment' => 4,
			'failed'          => 5,
		);
		$status = sanitize_key( (string) $status );

		return isset( $ranks[ $status ] ) ? $ranks[ $status ] : 99;
	}

	private function merge_portal_service_record( $existing, $candidate ) {
		$candidate_rank = $this->get_portal_service_status_rank( isset( $candidate->status ) ? $candidate->status : '' );
		$existing_rank  = $this->get_portal_service_status_rank( isset( $existing->status ) ? $existing->status : '' );
		if ( $candidate_rank < $existing_rank ) {
			$primary = $candidate;
			$fallback = $existing;
		} else {
			$primary = $existing;
			$fallback = $candidate;
		}

		foreach ( array( 'service_period', 'service_period_start', 'service_period_end', 'next_billing_date', 'price', 'amount', 'synced_at', 'paid_at', 'stripe_subscription_id', 'stripe_product_id', 'stripe_price_id', 'checkout_session_id', 'invoice_id', 'payment_intent_id', 'charge_id', 'source_ref' ) as $field ) {
			if ( empty( $primary->{$field} ) && ! empty( $fallback->{$field} ) ) {
				$primary->{$field} = $fallback->{$field};
			}
		}

		return $primary;
	}

	private function portal_recurring_service_records_match( $record, $existing_record ) {
		$record_type = isset( $record->billing_type_key ) ? sanitize_key( (string) $record->billing_type_key ) : '';
		$existing_type = isset( $existing_record->billing_type_key ) ? sanitize_key( (string) $existing_record->billing_type_key ) : '';
		if ( ! in_array( $record_type, array( 'subscription', 'recurring' ), true ) || ! in_array( $existing_type, array( 'subscription', 'recurring' ), true ) ) {
			return false;
		}

		if ( empty( $record->stripe_customer_id ) || empty( $existing_record->stripe_customer_id ) || (string) $record->stripe_customer_id !== (string) $existing_record->stripe_customer_id ) {
			return false;
		}

		if ( ! empty( $record->stripe_subscription_id ) || ! empty( $existing_record->stripe_subscription_id ) ) {
			return ! empty( $record->stripe_subscription_id )
				&& ! empty( $existing_record->stripe_subscription_id )
				&& (string) $record->stripe_subscription_id === (string) $existing_record->stripe_subscription_id;
		}

		$product_match = ! empty( $record->stripe_product_id ) && ! empty( $existing_record->stripe_product_id ) && (string) $record->stripe_product_id === (string) $existing_record->stripe_product_id;
		$price_match = ! empty( $record->stripe_price_id ) && ! empty( $existing_record->stripe_price_id ) && (string) $record->stripe_price_id === (string) $existing_record->stripe_price_id;
		if ( ! $product_match && ! $price_match ) {
			return false;
		}

		return ! empty( $record->stripe_subscription_id ) || ! empty( $existing_record->stripe_subscription_id );
	}

	private function merge_portal_one_time_service_record( $existing, $candidate ) {
		$candidate_rank = $this->get_portal_service_status_rank( isset( $candidate->status ) ? $candidate->status : '' );
		$existing_rank  = $this->get_portal_service_status_rank( isset( $existing->status ) ? $existing->status : '' );
		if ( $candidate_rank < $existing_rank ) {
			$existing->status = $candidate->status;
		}

		foreach ( array( 'amount', 'paid_at', 'synced_at' ) as $field ) {
			if ( empty( $existing->{$field} ) || $candidate_rank < $existing_rank ) {
				if ( ! empty( $candidate->{$field} ) ) {
					$existing->{$field} = $candidate->{$field};
				}
			}
		}

		foreach ( array( 'stripe_product_id', 'stripe_price_id', 'checkout_session_id', 'invoice_id', 'payment_intent_id', 'charge_id', 'service_period', 'price' ) as $field ) {
			if ( empty( $existing->{$field} ) && ! empty( $candidate->{$field} ) ) {
				$existing->{$field} = $candidate->{$field};
			}
		}

		if ( ! empty( $candidate->source_ref ) && empty( $existing->source_ref ) ) {
			$existing->source_ref = $candidate->source_ref;
		}

		return $existing;
	}

	private function portal_one_time_service_records_match( $record, $existing_record ) {
		if ( empty( $record->stripe_customer_id ) || empty( $existing_record->stripe_customer_id ) ) {
			return false;
		}
		if ( (string) $record->stripe_customer_id !== (string) $existing_record->stripe_customer_id ) {
			return false;
		}
		if ( sanitize_title( (string) $record->service_name ) !== sanitize_title( (string) $existing_record->service_name ) ) {
			return false;
		}

		foreach ( array( 'payment_intent_id', 'checkout_session_id', 'invoice_id' ) as $field ) {
			if ( ! empty( $record->{$field} ) && ! empty( $existing_record->{$field} ) && (string) $record->{$field} === (string) $existing_record->{$field} ) {
				return true;
			}
		}

		return empty( $record->payment_intent_id )
			&& empty( $existing_record->payment_intent_id )
			&& empty( $record->checkout_session_id )
			&& empty( $existing_record->checkout_session_id )
			&& ! empty( $record->amount )
			&& ! empty( $existing_record->amount )
			&& (string) $record->amount === (string) $existing_record->amount;
	}

	private function dedupe_portal_one_time_service_records( $records ) {
		$deduped = array();
		$order   = array();

		foreach ( (array) $records as $record ) {
			$key = $this->get_portal_one_time_service_dedupe_key( $record );
			foreach ( $deduped as $existing_key => $existing_record ) {
				if ( $this->portal_one_time_service_records_match( $record, $existing_record ) ) {
					$key = $existing_key;
					break;
				}
			}
			if ( isset( $deduped[ $key ] ) ) {
				$deduped[ $key ] = $this->merge_portal_one_time_service_record( $deduped[ $key ], $record );
				continue;
			}
			$deduped[ $key ] = $record;
			$order[] = $key;
		}

		$records = array();
		foreach ( $order as $key ) {
			$records[] = $deduped[ $key ];
		}

		return $records;
	}

	private function dedupe_portal_service_records( $records ) {
		$deduped = array();
		$order   = array();

		foreach ( (array) $records as $record ) {
			$key = $this->get_portal_service_dedupe_key( $record );
			foreach ( $deduped as $existing_key => $existing_record ) {
				if ( $this->portal_recurring_service_records_match( $record, $existing_record ) ) {
					$key = $existing_key;
					break;
				}
			}
			if ( isset( $deduped[ $key ] ) ) {
				if ( $this->portal_recurring_service_records_match( $record, $deduped[ $key ] ) && ( empty( $record->stripe_subscription_id ) || empty( $deduped[ $key ]->stripe_subscription_id ) ) ) {
					$this->log_portal_event(
						'service_recurring_payment_suppressed_from_one_time',
						array(
							'source'             => 'products_services_display',
							'stripe_customer_id' => ! empty( $record->stripe_customer_id ) ? $record->stripe_customer_id : '',
							'severity'           => 'info',
							'details'            => array(
								'product_id'       => ! empty( $record->stripe_product_id ) ? $record->stripe_product_id : '',
								'price_id'         => ! empty( $record->stripe_price_id ) ? $record->stripe_price_id : '',
								'subscription_id'  => ! empty( $record->stripe_subscription_id ) ? $record->stripe_subscription_id : ( ! empty( $deduped[ $key ]->stripe_subscription_id ) ? $deduped[ $key ]->stripe_subscription_id : '' ),
								'reason'           => 'recurring_payment_row_represented_by_subscription_service',
							),
						)
					);
				}
				$deduped[ $key ] = $this->merge_portal_service_record( $deduped[ $key ], $record );
				continue;
			}
			$deduped[ $key ] = $record;
			$order[] = $key;
		}

		$records = array();
		foreach ( $order as $key ) {
			$records[] = $deduped[ $key ];
		}

		return $records;
	}

	private function make_portal_service_record_from_product( $product, $context = array() ) {
		$billing_type_key = ! empty( $product->recurring_interval ) ? 'subscription' : 'one_time';

		return (object) array_merge(
			array(
				'service_name'          => isset( $product->name ) ? sanitize_text_field( (string) $product->name ) : __( 'Service', 'ajforms' ),
				'price'                 => $this->get_portal_product_price_label( $product ),
				'recurring_interval'    => ! empty( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '',
				'billing_type_key'      => $billing_type_key,
				'billing_type'          => $this->get_portal_billing_type_label( $billing_type_key ),
				'stripe_price_id'       => isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '',
				'stripe_product_id'     => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
				'stripe_subscription_id'=> '',
				'checkout_session_id'   => '',
				'invoice_id'            => '',
				'payment_intent_id'     => '',
				'charge_id'             => '',
				'service_period'        => '',
				'service_period_start'  => '',
				'service_period_end'    => '',
				'next_billing_date'     => '',
				'status'                => '',
				'amount'                => '',
				'paid_at'               => '',
				'synced_at'             => '',
				'customer'              => '',
				'stripe_customer_id'    => '',
				'source_ref'            => '',
				'next_action'           => '',
			),
			$context
		);
	}

	private function make_portal_service_record_from_snapshot( $snapshot ) {
		$product = $this->resolve_portal_product_from_identifiers(
			array(
				'price_ids'           => ! empty( $snapshot->price_id ) ? array( sanitize_text_field( (string) $snapshot->price_id ) ) : array(),
				'product_ids'         => ! empty( $snapshot->product_id ) ? array( sanitize_text_field( (string) $snapshot->product_id ) ) : array(),
				'subscription_ids'    => ! empty( $snapshot->subscription_id ) ? array( sanitize_text_field( (string) $snapshot->subscription_id ) ) : array(),
				'checkout_session_ids'=> ! empty( $snapshot->checkout_session_id ) ? array( sanitize_text_field( (string) $snapshot->checkout_session_id ) ) : array(),
				'invoice_ids'         => ! empty( $snapshot->invoice_id ) ? array( sanitize_text_field( (string) $snapshot->invoice_id ) ) : array(),
				'payment_intent_ids'  => ! empty( $snapshot->payment_intent_id ) ? array( sanitize_text_field( (string) $snapshot->payment_intent_id ) ) : array(),
			)
		);
		$snapshot_interval = ! empty( $snapshot->recurring_interval ) ? sanitize_key( (string) $snapshot->recurring_interval ) : ( $product && ! empty( $product->recurring_interval ) ? sanitize_key( (string) $product->recurring_interval ) : '' );
		$billing_type_key = ( ! empty( $snapshot_interval ) || ! empty( $snapshot->subscription_id ) || ( ! empty( $snapshot->billing_type ) && 'recurring' === sanitize_key( (string) $snapshot->billing_type ) ) ) ? 'subscription' : 'one_time';
		$customer_ref = ! empty( $snapshot->stripe_customer_id ) ? sanitize_text_field( (string) $snapshot->stripe_customer_id ) : sanitize_text_field( (string) $snapshot->guest_customer_id );
		$period_start = ! empty( $snapshot->service_period_start ) ? $snapshot->service_period_start : '';
		$period_end = ! empty( $snapshot->service_period_end ) ? $snapshot->service_period_end : '';
		$next_billing_date = ! empty( $snapshot->next_billing_date ) ? $snapshot->next_billing_date : '';
		if ( 'subscription' === $billing_type_key && ( empty( $period_end ) || empty( $next_billing_date ) ) && ! empty( $snapshot->subscription_id ) ) {
			$subscription_period = $this->get_synced_portal_subscription_period_context( $snapshot->subscription_id );
			if ( empty( $period_start ) && ! empty( $subscription_period['current_period_start'] ) ) {
				$period_start = $subscription_period['current_period_start'];
			}
			if ( empty( $period_end ) && ! empty( $subscription_period['current_period_end'] ) ) {
				$period_end = $subscription_period['current_period_end'];
			}
			if ( empty( $next_billing_date ) && ! empty( $subscription_period['current_period_end'] ) ) {
				$next_billing_date = $subscription_period['current_period_end'];
			}
		}

		$record = (object) array(
			'service_name'           => ! empty( $snapshot->product_name_snapshot ) ? sanitize_text_field( (string) $snapshot->product_name_snapshot ) : __( 'Service', 'ajforms' ),
			'price'                  => ! empty( $snapshot->price_label_snapshot ) ? sanitize_text_field( (string) $snapshot->price_label_snapshot ) : $this->format_portal_money( $snapshot->amount, $snapshot->currency ),
			'recurring_interval'     => $snapshot_interval,
			'billing_type_key'       => $billing_type_key,
			'billing_type'           => $this->get_portal_billing_type_label( $billing_type_key ),
			'stripe_price_id'        => ! empty( $snapshot->price_id ) ? sanitize_text_field( (string) $snapshot->price_id ) : '',
			'stripe_product_id'      => ! empty( $snapshot->product_id ) ? sanitize_text_field( (string) $snapshot->product_id ) : '',
			'stripe_subscription_id' => ! empty( $snapshot->subscription_id ) ? sanitize_text_field( (string) $snapshot->subscription_id ) : '',
			'checkout_session_id'    => ! empty( $snapshot->checkout_session_id ) ? sanitize_text_field( (string) $snapshot->checkout_session_id ) : '',
			'invoice_id'             => ! empty( $snapshot->invoice_id ) ? sanitize_text_field( (string) $snapshot->invoice_id ) : '',
			'payment_intent_id'      => ! empty( $snapshot->payment_intent_id ) ? sanitize_text_field( (string) $snapshot->payment_intent_id ) : '',
			'charge_id'              => ! empty( $snapshot->charge_id ) ? sanitize_text_field( (string) $snapshot->charge_id ) : '',
			'service_period'         => ! empty( $snapshot->service_period ) ? sanitize_text_field( (string) $snapshot->service_period ) : '',
			'service_period_start'   => $period_start,
			'service_period_end'     => $period_end,
			'next_billing_date'      => $next_billing_date,
			'status'                 => ! empty( $snapshot->status ) ? sanitize_key( (string) $snapshot->status ) : '',
			'amount'                 => $this->format_portal_money( $snapshot->amount, $snapshot->currency ),
			'paid_at'                => ! empty( $snapshot->created_at ) ? $snapshot->created_at : '',
			'synced_at'              => ! empty( $snapshot->updated_at ) ? $snapshot->updated_at : '',
			'customer'               => ! empty( $snapshot->customer_name ) ? sanitize_text_field( (string) $snapshot->customer_name ) : ( ! empty( $snapshot->customer_email ) ? sanitize_email( (string) $snapshot->customer_email ) : $customer_ref ),
			'stripe_customer_id'     => $customer_ref,
			'source_ref'             => ! empty( $snapshot->snapshot_key ) ? sanitize_text_field( (string) $snapshot->snapshot_key ) : '',
			'next_action'            => 'one_time' === $billing_type_key ? __( 'Convert to Auto-Pay Subscription', 'ajforms' ) : '',
		);
		$record->service_period = $this->get_portal_service_record_period_label( $record );
		$record->next_billing_date = $this->get_portal_service_record_next_billing_date_label( $record );

		return $record;
	}

	private function get_portal_service_records_from_snapshots( $billing_type = '', $stripe_customer_id = '', $limit = 300 ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $billing_type ) {
			if ( 'recurring' === $billing_type || 'subscription' === $billing_type ) {
				$where[] = "(s.billing_type = 'recurring' OR s.recurring_interval <> '' OR s.subscription_id <> '' OR p.recurring_interval <> '')";
			} else {
				$where[] = "s.billing_type = 'one_time' AND s.recurring_interval = '' AND s.subscription_id = '' AND COALESCE(p.recurring_interval, '') = ''";
			}
		}
		if ( '' !== $stripe_customer_id ) {
			$where[]  = 's.stripe_customer_id = %s';
			$params[] = sanitize_text_field( (string) $stripe_customer_id );
		}
		if ( 'one_time' === $billing_type ) {
			$where[] = "s.status IN ('paid','succeeded','completed','used')";
		} elseif ( 'recurring' === $billing_type || 'subscription' === $billing_type ) {
			$where[] = "(s.status IN ('active','trialing') OR (s.subscription_id = '' AND s.status IN ('paid','succeeded','completed')))";
		}

		$sql = "SELECT s.*, c.name AS customer_name
			FROM {$this->get_portal_service_snapshots_table()} s
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = s.stripe_customer_id
			LEFT JOIN {$this->get_portal_stripe_products_table()} p ON p.stripe_price_id = s.price_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY s.updated_at DESC, s.id DESC
			LIMIT %d';
		$params[] = absint( $limit );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$records = array();
		foreach ( (array) $rows as $row ) {
			$record = $this->make_portal_service_record_from_snapshot( $row );
			if ( 'one_time' === $billing_type && 'one_time' !== $record->billing_type_key ) {
				$this->log_portal_event(
					'service_snapshot_recurring_suppressed_from_one_time',
					array(
						'source'             => 'products_services_display',
						'stripe_customer_id' => ! empty( $row->stripe_customer_id ) ? $row->stripe_customer_id : '',
						'email_after'        => ! empty( $row->customer_email ) ? $row->customer_email : '',
						'severity'           => 'info',
						'details'            => array(
							'snapshot_key'    => ! empty( $row->snapshot_key ) ? $row->snapshot_key : '',
							'product_name'    => ! empty( $row->product_name_snapshot ) ? $row->product_name_snapshot : '',
							'price_id'        => ! empty( $row->price_id ) ? $row->price_id : '',
							'subscription_id' => ! empty( $row->subscription_id ) ? $row->subscription_id : '',
							'reason'          => 'recurring_price_or_subscription_context',
						),
					)
				);
				continue;
			}
			if ( 'one_time' === $billing_type && ! $this->is_displayable_one_time_service_record( $record ) ) {
				$this->log_portal_event(
					'service_one_time_artifact_suppressed',
					array(
						'source'             => 'products_services_display',
						'stripe_customer_id' => ! empty( $row->stripe_customer_id ) ? $row->stripe_customer_id : '',
						'email_after'        => ! empty( $row->customer_email ) ? $row->customer_email : '',
						'severity'           => 'info',
						'details'            => array(
							'snapshot_key' => ! empty( $row->snapshot_key ) ? $row->snapshot_key : '',
							'service_name' => ! empty( $record->service_name ) ? $record->service_name : '',
							'source_id'    => $this->get_portal_one_time_service_source_id( $record ),
							'reason'       => 'generic_or_recurring_payment_artifact',
						),
					)
				);
				continue;
			}
			$records[] = $record;
		}

		return 'one_time' === $billing_type ? $this->dedupe_portal_one_time_service_records( $records ) : $this->dedupe_portal_service_records( $records );
	}

	private function make_portal_fallback_one_time_service_record( $entry, $service_name, $identifiers = array() ) {
		$record = (object) array(
			'service_name'          => sanitize_text_field( (string) $service_name ),
			'price'                 => '',
			'recurring_interval'    => '',
			'billing_type_key'      => 'one_time',
			'billing_type'          => $this->get_portal_billing_type_label( 'one_time' ),
			'stripe_price_id'       => '',
			'stripe_product_id'     => '',
			'stripe_subscription_id'=> ! empty( $identifiers['subscription_ids'][0] ) ? sanitize_text_field( (string) $identifiers['subscription_ids'][0] ) : '',
			'checkout_session_id'   => ! empty( $identifiers['checkout_session_ids'][0] ) ? sanitize_text_field( (string) $identifiers['checkout_session_ids'][0] ) : ( ! empty( $entry->source_type ) && 'checkout_session' === sanitize_key( (string) $entry->source_type ) && ! empty( $entry->source_object_id ) ? sanitize_text_field( (string) $entry->source_object_id ) : '' ),
			'invoice_id'            => ! empty( $identifiers['invoice_ids'][0] ) ? sanitize_text_field( (string) $identifiers['invoice_ids'][0] ) : ( ! empty( $entry->invoice_id ) ? sanitize_text_field( (string) $entry->invoice_id ) : '' ),
			'payment_intent_id'     => ! empty( $identifiers['payment_intent_ids'][0] ) ? sanitize_text_field( (string) $identifiers['payment_intent_ids'][0] ) : ( ! empty( $entry->payment_intent_id ) ? sanitize_text_field( (string) $entry->payment_intent_id ) : '' ),
			'charge_id'             => ! empty( $entry->charge_id ) ? sanitize_text_field( (string) $entry->charge_id ) : '',
			'service_period'        => $this->get_portal_service_period_from_ledger_entry( $entry ),
			'service_period_start'  => $this->get_ledger_metadata_value( $entry, 'service_period_start' ),
			'service_period_end'    => $this->get_ledger_metadata_value( $entry, 'service_period_end' ),
			'next_billing_date'     => $this->get_ledger_metadata_value( $entry, 'next_billing_date' ),
			'status'                => isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '',
			'amount'                => $this->format_portal_money( isset( $entry->amount ) ? $entry->amount : 0, isset( $entry->currency ) ? $entry->currency : 'usd' ),
			'paid_at'               => isset( $entry->ledger_date ) ? $entry->ledger_date : '',
			'synced_at'             => ! empty( $entry->created_at ) ? $entry->created_at : '',
			'customer'              => ! empty( $entry->customer_name ) ? sanitize_text_field( (string) $entry->customer_name ) : ( ! empty( $entry->customer_email ) ? sanitize_email( (string) $entry->customer_email ) : ( ! empty( $entry->stripe_customer_id ) ? sanitize_text_field( (string) $entry->stripe_customer_id ) : __( 'Guest customer', 'ajforms' ) ) ),
			'stripe_customer_id'    => ! empty( $entry->stripe_customer_id ) ? sanitize_text_field( (string) $entry->stripe_customer_id ) : '',
			'source_ref'            => ! empty( $entry->source_object_id ) ? sanitize_text_field( (string) $entry->source_object_id ) : '',
			'next_action'           => __( 'Convert to Auto-Pay Subscription', 'ajforms' ),
		);
		$record->service_period = $this->get_portal_service_record_period_label( $record );
		$record->next_billing_date = $this->get_portal_service_record_next_billing_date_label( $record );

		return $record;
	}

	private function get_portal_service_records_from_subscriptions( $subscriptions ) {
		$records = array();
		$seen    = array();

		foreach ( (array) $subscriptions as $subscription ) {
			if ( ! $this->is_active_portal_subscription( $subscription ) ) {
				continue;
			}

			$items = json_decode( (string) $subscription->items, true );
			if ( ! is_array( $items ) ) {
				$items = array();
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$identifiers = array(
					'price_ids'          => array(),
					'product_ids'        => array(),
					'subscription_ids'   => array( sanitize_text_field( (string) $subscription->stripe_subscription_id ) ),
					'checkout_session_ids'=> array(),
					'invoice_ids'        => array(),
					'payment_intent_ids' => array(),
				);
				$this->collect_portal_service_identifiers_from_data( $item, $identifiers );
				if ( ! empty( $item['price_id'] ) ) {
					$identifiers['price_ids'][] = sanitize_text_field( (string) $item['price_id'] );
				}
				if ( ! empty( $item['product_id'] ) ) {
					$identifiers['product_ids'][] = sanitize_text_field( (string) $item['product_id'] );
				}
				foreach ( $identifiers as $key => $values ) {
					$identifiers[ $key ] = array_values( array_filter( array_unique( $values ) ) );
				}

				$product = $this->resolve_portal_product_from_identifiers( $identifiers );
				if ( ! $product ) {
					continue;
				}
				$subscription_period = $this->get_synced_portal_subscription_period_context( $subscription->stripe_subscription_id );
				$period_start = ! empty( $subscription_period['current_period_start'] ) ? $subscription_period['current_period_start'] : '';
				$period_end   = ! empty( $subscription_period['current_period_end'] ) ? $subscription_period['current_period_end'] : ( ! empty( $subscription->current_period_end ) ? $subscription->current_period_end : '' );
				$service_period = ( $period_start && $period_end ) ? $this->format_portal_date( $period_start ) . ' - ' . $this->format_portal_date( $period_end ) : '';

				$record = $this->make_portal_service_record_from_product(
					$product,
					array(
						'stripe_customer_id'     => $subscription->stripe_customer_id,
						'customer'               => ! empty( $subscription->customer_name ) ? $subscription->customer_name : ( ! empty( $subscription->customer_email ) ? $subscription->customer_email : $subscription->stripe_customer_id ),
						'stripe_subscription_id' => $subscription->stripe_subscription_id,
						'status'                 => $subscription->status,
						'service_period'         => $service_period,
						'service_period_start'   => $period_start,
						'service_period_end'     => $period_end,
						'next_billing_date'      => $period_end,
						'synced_at'              => ! empty( $subscription->synced_at ) ? $subscription->synced_at : '',
					)
				);
				$record->service_period = $this->get_portal_service_record_period_label( $record );
				$record->next_billing_date = $this->get_portal_service_record_next_billing_date_label( $record );
				if ( 'subscription' !== $record->billing_type_key ) {
					$record->billing_type_key = 'subscription';
					$record->billing_type     = $this->get_portal_billing_type_label( 'subscription' );
				}

				$dedupe_key = $this->get_portal_service_dedupe_key( $record );
				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;
				$records[] = $record;
			}
		}

		return $records;
	}

	private function portal_ledger_entry_has_subscription_context( $entry ) {
		foreach ( array( 'subscription_id', 'stripe_subscription_id' ) as $field ) {
			if ( ! empty( $entry->{$field} ) ) {
				return true;
			}
		}

		$metadata        = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		$raw             = $this->decode_portal_json( isset( $entry->raw_data ) ? $entry->raw_data : '' );
		$transaction_raw = $this->decode_portal_json( isset( $entry->transaction_raw_data ) ? $entry->transaction_raw_data : '' );
		foreach ( array( $metadata, $raw, $transaction_raw ) as $data ) {
			foreach ( array( 'subscription_id', 'stripe_subscription_id', 'subscription' ) as $key ) {
				if ( ! empty( $data[ $key ] ) ) {
					return true;
				}
			}
			if ( ! empty( $data['lines']['data'] ) && is_array( $data['lines']['data'] ) ) {
				foreach ( $data['lines']['data'] as $line ) {
					if ( ! empty( $line['subscription'] ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	private function is_one_time_paid_service_ledger_entry( $entry ) {
		$status      = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		$description = isset( $entry->description ) ? sanitize_key( (string) $entry->description ) : '';

		if ( ! in_array( $status, array( 'paid', 'succeeded', 'completed' ), true ) ) {
			return false;
		}

		if ( 'checkout_session' === $source_type && false !== strpos( $description, 'ajcore_portal_balance_payment' ) ) {
			return false;
		}

		return in_array( $source_type, array( 'checkout_session', 'charge', 'payment', 'payment_intent', 'invoice' ), true );
	}

	private function has_synced_active_subscription( $subscription_id ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return false;
		}

		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_subscription_id = %s AND status IN ('active','trialing') LIMIT 1",
				$subscription_id
			)
		);
	}

	private function get_synced_portal_subscription_status( $subscription_id ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return '';
		}

		global $wpdb;
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_subscription_id = %s LIMIT 1",
				$subscription_id
			)
		);

		return $status ? sanitize_key( (string) $status ) : '';
	}

	private function normalize_portal_subscription_period_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		if ( is_numeric( $value ) ) {
			return $this->stripe_timestamp_to_mysql( $value );
		}

		$timestamp = strtotime( (string) $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : sanitize_text_field( (string) $value );
	}

	private function get_synced_portal_subscription_period_context( $subscription_id ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );
		if ( '' === $subscription_id ) {
			return array();
		}

		global $wpdb;
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT current_period_end, items, raw_data FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_subscription_id = %s LIMIT 1",
				$subscription_id
			)
		);
		if ( ! $subscription ) {
			return array();
		}

		$raw = $this->decode_portal_json( $subscription->raw_data );
		$start = ! empty( $raw['current_period_start'] ) ? $this->normalize_portal_subscription_period_value( $raw['current_period_start'] ) : '';
		$end   = ! empty( $subscription->current_period_end ) ? $this->normalize_portal_subscription_period_value( $subscription->current_period_end ) : ( ! empty( $raw['current_period_end'] ) ? $this->normalize_portal_subscription_period_value( $raw['current_period_end'] ) : '' );
		$items = $this->decode_portal_json( $subscription->items );
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( empty( $start ) ) {
				foreach ( array( 'current_period_start', 'period_start' ) as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						$start = $this->normalize_portal_subscription_period_value( $item[ $key ] );
						break;
					}
				}
			}
			if ( empty( $end ) ) {
				foreach ( array( 'current_period_end', 'period_end' ) as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						$end = $this->normalize_portal_subscription_period_value( $item[ $key ] );
						break;
					}
				}
			}
			if ( ( empty( $start ) || empty( $end ) ) && ! empty( $item['period'] ) && is_array( $item['period'] ) ) {
				if ( empty( $start ) && ! empty( $item['period']['start'] ) ) {
					$start = $this->normalize_portal_subscription_period_value( $item['period']['start'] );
				}
				if ( empty( $end ) && ! empty( $item['period']['end'] ) ) {
					$end = $this->normalize_portal_subscription_period_value( $item['period']['end'] );
				}
			}
			if ( $start && $end ) {
				break;
			}
		}

		return array(
			'current_period_start' => $start,
			'current_period_end'   => $end,
		);
	}

	private function get_portal_ledger_service_records( $billing_type = '', $stripe_customer_id = '', $limit = 300 ) {
		global $wpdb;

		$where  = "l.status IN ('paid','succeeded','completed')";
		$params = array();
		if ( '' !== $stripe_customer_id ) {
			$where   .= ' AND l.stripe_customer_id = %s';
			$params[] = sanitize_text_field( (string) $stripe_customer_id );
		}

		$sql = "SELECT l.*, c.name AS customer_name, c.email AS customer_email, t.raw_data AS transaction_raw_data
			FROM {$this->get_portal_ledger_table()} l
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = l.stripe_customer_id
			LEFT JOIN {$this->get_portal_stripe_transactions_table()} t ON (
				t.stripe_object_id = l.source_object_id
				OR (l.payment_intent_id <> '' AND t.payment_intent_id = l.payment_intent_id)
				OR (l.invoice_id <> '' AND t.invoice_id = l.invoice_id)
				OR (l.charge_id <> '' AND t.charge_id = l.charge_id)
			)
			WHERE {$where}
			ORDER BY l.ledger_date DESC, l.id DESC
			LIMIT %d";
		$params[] = absint( $limit );

		$ledger = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$rows   = array();
		foreach ( (array) $ledger as $entry ) {
			if ( ! $this->is_one_time_paid_service_ledger_entry( $entry ) ) {
				continue;
			}

				$identifiers = $this->get_portal_service_identifiers_from_ledger_entry( $entry );
				$product     = $this->resolve_portal_product_from_identifiers( $identifiers );
				if ( ! $product ) {
					if ( $this->portal_ledger_entry_has_subscription_context( $entry ) ) {
						$this->record_portal_service_display_skip( $entry, __( 'Skipped because this payment has subscription context and is represented by recurring services.', 'ajforms' ) );
						$this->log_portal_event(
							'service_recurring_payment_suppressed_from_one_time',
							array(
								'source'             => 'products_services_display',
								'stripe_customer_id' => ! empty( $entry->stripe_customer_id ) ? $entry->stripe_customer_id : '',
								'severity'           => 'info',
								'details'            => array(
									'source_type'       => ! empty( $entry->source_type ) ? $entry->source_type : '',
									'source_object_id'  => ! empty( $entry->source_object_id ) ? $entry->source_object_id : '',
									'invoice_id'        => ! empty( $entry->invoice_id ) ? $entry->invoice_id : '',
									'payment_intent_id' => ! empty( $entry->payment_intent_id ) ? $entry->payment_intent_id : '',
									'reason'            => 'subscription_context_without_resolved_product',
								),
							)
						);
						continue;
					}
					$fallback_service_name = $this->get_portal_fallback_service_name_from_ledger_entry( $entry );
					if ( '' === $fallback_service_name ) {
						$this->record_portal_service_display_skip( $entry, __( 'Skipped because no synced Stripe product, price, or usable checkout item description was found.', 'ajforms' ) );
						continue;
					}
					if ( $this->is_generic_portal_service_label( $fallback_service_name ) ) {
						$this->record_portal_service_display_skip( $entry, __( 'Skipped because the payment label is generic. One-time services require a product or checkout line item.', 'ajforms' ) );
						continue;
					}
					if ( $this->text_contains_recurring_price_interval( $fallback_service_name ) ) {
						$this->record_portal_service_display_skip( $entry, __( 'Skipped because the payment label looks like a recurring price artifact. One-time services require a one-time product or checkout line item.', 'ajforms' ) );
						continue;
					}
					if ( 'subscription' === $billing_type ) {
						continue;
					}

					$record = $this->make_portal_fallback_one_time_service_record( $entry, $fallback_service_name, $identifiers );
					$rows[] = $record;
					continue;
				}

				$record_billing_type = ! empty( $product->recurring_interval ) ? 'subscription' : 'one_time';
				if ( '' !== $billing_type && $record_billing_type !== $billing_type ) {
					if ( 'one_time' === $billing_type && 'subscription' === $record_billing_type ) {
						$this->record_portal_service_display_skip( $entry, __( 'Skipped because the resolved Stripe price is recurring and is represented by recurring services.', 'ajforms' ) );
						$this->log_portal_event(
							'service_recurring_payment_suppressed_from_one_time',
							array(
								'source'             => 'products_services_display',
								'stripe_customer_id' => ! empty( $entry->stripe_customer_id ) ? $entry->stripe_customer_id : '',
								'severity'           => 'info',
								'details'            => array(
									'product_id'         => ! empty( $product->stripe_product_id ) ? $product->stripe_product_id : '',
									'price_id'           => ! empty( $product->stripe_price_id ) ? $product->stripe_price_id : '',
									'recurring_interval' => ! empty( $product->recurring_interval ) ? $product->recurring_interval : '',
									'source_type'        => ! empty( $entry->source_type ) ? $entry->source_type : '',
									'source_object_id'   => ! empty( $entry->source_object_id ) ? $entry->source_object_id : '',
									'reason'             => 'resolved_recurring_price',
								),
							)
						);
					}
					continue;
				}
			if ( 'subscription' === $record_billing_type && ! empty( $identifiers['subscription_ids'][0] ) ) {
				$synced_subscription_status = $this->get_synced_portal_subscription_status( $identifiers['subscription_ids'][0] );
				if ( in_array( $synced_subscription_status, array( 'active', 'trialing' ), true ) ) {
					continue;
				}
				if ( '' !== $synced_subscription_status ) {
					$this->record_portal_service_display_skip(
						$entry,
						sprintf(
							/* translators: %s: Stripe subscription status */
							__( 'Skipped recurring ledger row because the synced Stripe subscription is %s.', 'ajforms' ),
							$synced_subscription_status
						)
					);
					continue;
				}
			}

			$record = $this->make_portal_service_record_from_product(
				$product,
				array(
					'stripe_customer_id'     => $entry->stripe_customer_id,
					'customer'               => ! empty( $entry->customer_name ) ? $entry->customer_name : ( ! empty( $entry->customer_email ) ? $entry->customer_email : $entry->stripe_customer_id ),
					'stripe_subscription_id' => ! empty( $identifiers['subscription_ids'][0] ) ? $identifiers['subscription_ids'][0] : '',
					'checkout_session_id'    => ! empty( $identifiers['checkout_session_ids'][0] ) ? $identifiers['checkout_session_ids'][0] : ( 'checkout_session' === $entry->source_type ? $entry->source_object_id : '' ),
					'invoice_id'             => ! empty( $identifiers['invoice_ids'][0] ) ? $identifiers['invoice_ids'][0] : $entry->invoice_id,
					'payment_intent_id'      => ! empty( $identifiers['payment_intent_ids'][0] ) ? $identifiers['payment_intent_ids'][0] : $entry->payment_intent_id,
					'charge_id'              => ! empty( $entry->charge_id ) ? $entry->charge_id : '',
					'service_period'         => $this->get_portal_service_period_from_ledger_entry( $entry ),
					'service_period_start'   => $this->get_ledger_metadata_value( $entry, 'service_period_start' ),
					'service_period_end'     => $this->get_ledger_metadata_value( $entry, 'service_period_end' ),
					'next_billing_date'      => $this->get_ledger_metadata_value( $entry, 'next_billing_date' ),
					'status'                 => $entry->status,
					'amount'                 => $this->format_portal_money( $entry->amount, $entry->currency ),
					'paid_at'                => $entry->ledger_date,
					'synced_at'              => ! empty( $entry->created_at ) ? $entry->created_at : '',
					'source_ref'             => ! empty( $entry->source_object_id ) ? $entry->source_object_id : '',
					'next_action'            => 'one_time' === $record_billing_type ? __( 'Convert to Auto-Pay Subscription', 'ajforms' ) : '',
				)
			);
			$record->service_period = $this->get_portal_service_record_period_label( $record );
			$record->next_billing_date = $this->get_portal_service_record_next_billing_date_label( $record );

			if ( 'one_time' === $billing_type && ! $this->is_displayable_one_time_service_record( $record ) ) {
				$this->record_portal_service_display_skip( $entry, __( 'Skipped because this row is a generic payment or recurring-price artifact, not a one-time service line item.', 'ajforms' ) );
				continue;
			}

			$rows[] = $record;
		}

		if ( 'one_time' === $billing_type ) {
			return $this->dedupe_portal_one_time_service_records( array_merge( $this->get_portal_service_records_from_snapshots( 'one_time', $stripe_customer_id, $limit ), $rows ) );
		}

		if ( 'subscription' === $billing_type || 'recurring' === $billing_type ) {
			return $this->dedupe_portal_service_records( array_merge( $this->get_portal_service_records_from_snapshots( 'recurring', $stripe_customer_id, $limit ), $rows ) );
		}

		return $this->dedupe_portal_service_records( $rows );
	}

	private function get_portal_one_time_paid_services( $stripe_customer_id = '', $limit = 300 ) {
		return $this->get_portal_ledger_service_records( 'one_time', $stripe_customer_id, $limit );
	}

	private function get_portal_recurring_service_records_from_ledger( $stripe_customer_id = '', $limit = 300 ) {
		return $this->get_portal_ledger_service_records( 'subscription', $stripe_customer_id, $limit );
	}

	private function render_portal_service_display_reconciliation_notes() {
		if ( empty( $this->portal_service_display_skips ) ) {
			return;
		}
		?>
		<details class="ajcore-service-reconciliation" style="margin:16px 0;">
			<summary><strong><?php esc_html_e( 'Service Display Reconciliation', 'ajforms' ); ?></strong></summary>
			<p class="description"><?php esc_html_e( 'Paid ledger rows listed here were not shown as services. This is diagnostic only; billing ledger rows were not changed.', 'ajforms' ); ?></p>
			<table class="widefat striped" style="margin-top:10px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reason', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->portal_service_display_skips as $skip ) : ?>
						<tr>
							<td><?php echo esc_html( $skip['reason'] ); ?></td>
							<td>
								<?php echo esc_html( $skip['customer'] ); ?>
								<?php if ( ! empty( $skip['stripe_customer_id'] ) ) : ?><br><code><?php echo esc_html( $skip['stripe_customer_id'] ); ?></code><?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $skip['source_type'] ); ?>
								<?php if ( ! empty( $skip['source_object_id'] ) ) : ?><br><code><?php echo esc_html( $skip['source_object_id'] ); ?></code><?php endif; ?>
							</td>
							<td><?php echo esc_html( $skip['status'] ); ?></td>
							<td><?php echo esc_html( $skip['amount'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	private function get_portal_customer_purchased_products( $subscriptions, $ledger ) {
		global $wpdb;

		$products = array();
		foreach ( $subscriptions as $subscription ) {
			$items = json_decode( (string) $subscription->items, true );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$price      = ! empty( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
				$product    = ! empty( $price['product'] ) && is_array( $price['product'] ) ? $price['product'] : array();
				$price_id   = ! empty( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '';
				$price_id   = '' === $price_id && ! empty( $price['id'] ) ? sanitize_text_field( (string) $price['id'] ) : $price_id;
				$product_id = ! empty( $item['product_id'] ) ? sanitize_text_field( (string) $item['product_id'] ) : '';
				$product_id = '' === $product_id && ! empty( $product['id'] ) ? sanitize_text_field( (string) $product['id'] ) : $product_id;
				$product_id = '' === $product_id && ! empty( $price['product'] ) && is_string( $price['product'] ) ? sanitize_text_field( (string) $price['product'] ) : $product_id;
				$name       = ! empty( $item['product_name'] ) ? sanitize_text_field( (string) $item['product_name'] ) : '';
				$name       = '' === $name && ! empty( $product['name'] ) ? sanitize_text_field( (string) $product['name'] ) : $name;

				if ( '' === $name && '' !== $price_id ) {
					$product = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s LIMIT 1",
							$price_id
						)
					);
					if ( $product ) {
						$name = $product->name;
					}
				}

				$key = $price_id ? $price_id : $product_id;
				if ( '' === $key ) {
					continue;
				}

				$products[ $key ] = array(
					'name'            => $name ? $name : $key,
					'stripe_price_id' => $price_id,
					'stripe_product_id' => $product_id,
					'billing_type'    => $this->get_portal_billing_type_label( 'subscription' ),
					'source'          => __( 'Recurring service', 'ajforms' ),
				);
			}
		}

		foreach ( $ledger as $entry ) {
			if ( ! $this->is_one_time_paid_service_ledger_entry( $entry ) ) {
				continue;
			}

			$identifiers = $this->get_portal_service_identifiers_from_ledger_entry( $entry );
			$product     = $this->resolve_portal_product_from_identifiers( $identifiers );
			if ( ! $product ) {
				continue;
			}

			$key = ! empty( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : ( ! empty( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : 'ledger-' . (int) $entry->id );
			if ( isset( $products[ $key ] ) ) {
				continue;
			}

			$products[ $key ] = array(
				'name'             => sanitize_text_field( (string) $product->name ),
				'stripe_price_id'  => sanitize_text_field( (string) $product->stripe_price_id ),
				'stripe_product_id' => sanitize_text_field( (string) $product->stripe_product_id ),
				'billing_type'     => ! empty( $product->recurring_interval ) ? $this->get_portal_billing_type_label( 'subscription' ) : $this->get_portal_billing_type_label( 'one_time' ),
				'source'           => ! empty( $product->recurring_interval ) ? __( 'Recurring service', 'ajforms' ) : __( 'One-time paid service', 'ajforms' ),
			);
		}

		return array_values( $products );
	}

	private function format_portal_money( $amount, $currency ) {
		$currency = strtolower( sanitize_key( (string) $currency ) );
		$amount   = number_format_i18n( (float) $amount, 2 );

		if ( 'usd' === $currency ) {
			return '$' . $amount;
		}

		return strtoupper( sanitize_text_field( (string) $currency ) ) . ' ' . $amount;
	}



	private function get_portal_ledger_debit_credit( $entry ) {
		$effect = $this->get_portal_ledger_balance_effect( $entry );
		$currency = ! empty( $entry->currency ) ? sanitize_key( (string) $entry->currency ) : 'usd';

		return array(
			'debit'    => $effect > 0.00001 ? $this->format_portal_money( abs( $effect ), $currency ) : '',
			'credit'   => $effect < -0.00001 ? $this->format_portal_money( abs( $effect ), $currency ) : '',
			'currency' => $currency,
		);
	}


	private function get_portal_ledger_transaction_id( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			foreach ( array( 'payment_intent_id', 'charge_id', 'source_object_id' ) as $field ) {
				if ( ! empty( $entry->{$field} ) ) {
					return sanitize_text_field( (string) $entry->{$field} );
				}
			}
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			$billing_type = $this->get_ledger_metadata_value( $entry, 'billing_type' );
			$recurring_interval = $this->get_ledger_metadata_value( $entry, 'recurring_interval' );
			$product_interval = $this->get_portal_product_recurring_interval_from_ids(
				$this->get_ledger_metadata_value( $entry, 'price_id' ),
				$this->get_ledger_metadata_value( $entry, 'product_id' )
			);
			$is_recurring = null !== $product_interval ? '' !== $product_interval : ( 'recurring' === sanitize_key( $billing_type ) || '' !== $recurring_interval );
			$keys = $is_recurring
				? array( 'subscription_id', 'price_id', 'product_id' )
				: array( 'checkout_session_id', 'payment_intent_id', 'price_id', 'product_id', 'invoice_id' );
			foreach ( $keys as $metadata_key ) {
				$value = $this->get_ledger_metadata_value( $entry, $metadata_key );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		foreach ( array( 'charge_id', 'payment_intent_id', 'invoice_id', 'source_object_id' ) as $field ) {
			if ( ! empty( $entry->{$field} ) ) {
				return sanitize_text_field( (string) $entry->{$field} );
			}
		}

		return '';
	}

	private function get_portal_ledger_display_description( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		$status      = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		$description = isset( $entry->description ) ? sanitize_text_field( (string) $entry->description ) : '';

		if ( 'charge' === $source_type || 'payment' === $source_type ) {
			if ( 'refunded' === $status ) {
				return __( 'Payment — Refunded', 'ajforms' );
			}
			if ( in_array( $status, array( 'partially_refunded', 'partial_refund' ), true ) ) {
				return __( 'Payment — Partially Refunded', 'ajforms' );
			}

			return __( 'Payment', 'ajforms' );
		}

		if ( 'refund' === $source_type ) {
			return __( 'Refund', 'ajforms' );
		}

		if ( 'manual_charge' === $source_type && '' !== $description ) {
			return $description;
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) && '' !== $description ) {
			return $this->clean_stripe_line_service_name( $description );
		}

		if ( 'invoice' === $source_type ) {
			$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
			if ( ! empty( $metadata['invoice_number'] ) ) {
				return sprintf( __( 'Invoice %s', 'ajforms' ), sanitize_text_field( (string) $metadata['invoice_number'] ) );
			}
			if ( '' !== $description ) {
				return $description;
			}
			return __( 'Invoice', 'ajforms' );
		}

		if ( 'checkout_session' === $source_type ) {
			if ( false !== strpos( $description, 'ajcore_products_cart' ) ) {
				return __( 'Website Checkout Payment', 'ajforms' );
			}
			if ( false !== strpos( $description, 'ajcore_portal_add_service' ) ) {
				return __( 'Service Checkout Payment', 'ajforms' );
			}
			if ( false !== strpos( $description, 'ajcore_portal_balance_payment' ) ) {
				return __( 'Balance Payment', 'ajforms' );
			}
		}

		return '' !== $description ? $description : __( 'Billing Item', 'ajforms' );
	}

	private function get_portal_open_ledger_statuses() {
		return array( 'open', 'unpaid', 'pending', 'pending_payment', 'awaiting_payment' );
	}

	private function decode_portal_json( $value ) {
		$decoded = ! empty( $value ) ? json_decode( (string) $value, true ) : array();

		return is_array( $decoded ) ? $decoded : array();
	}

	private function get_portal_ledger_refunded_amount( $entry ) {
		$raw = $this->decode_portal_json( isset( $entry->raw_data ) ? $entry->raw_data : '' );
		if ( isset( $raw['amount_refunded'], $raw['currency'] ) ) {
			return $this->stripe_amount_to_decimal( $raw['amount_refunded'], $raw['currency'] );
		}

		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		if ( isset( $metadata['amount_refunded_total'] ) ) {
			return (float) $metadata['amount_refunded_total'];
		}

		return 0.0;
	}

	private function get_portal_ledger_balance_effect( $entry ) {
		$amount = isset( $entry->amount ) ? (float) $entry->amount : 0.0;
		if ( 0.0 === $amount ) {
			return 0.0;
		}

		$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';

		if ( in_array( $status, array( 'cancelled', 'canceled', 'failed', 'void', 'voided', 'draft', 'admin_review_required' ), true ) ) {
			return 0.0;
		}

		if ( 'refund' === $source_type ) {
			return 0.0;
		}

		if ( in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return abs( $amount );
		}

		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			if ( 'refunded' === $status ) {
				return 0.0;
			}

			if ( in_array( $status, array( 'partially_refunded', 'partial_refund' ), true ) ) {
				$net_paid = max( 0.0, abs( $amount ) - abs( $this->get_portal_ledger_refunded_amount( $entry ) ) );
				return $net_paid > 0 ? -1 * $net_paid : 0.0;
			}

			return in_array( $status, array( 'succeeded', 'paid' ), true ) ? -1 * abs( $amount ) : 0.0;
		}

		if ( 'checkout_session' === $source_type ) {
			return 0.0;
		}

		if ( in_array( $source_type, array( 'manual_charge', 'invoice' ), true ) && in_array( $status, $this->get_portal_open_ledger_statuses(), true ) ) {
			return abs( $amount );
		}

		if ( in_array( $status, $this->get_portal_open_ledger_statuses(), true ) ) {
			return abs( $amount );
		}

		return 0.0;
	}

	private function get_portal_ledger_running_balances( $ledger ) {
		$running_by_customer = array();
		$balances = array();
		$totals = array();
		$entries = (array) $ledger;
		usort(
			$entries,
			function ( $a, $b ) {
				$a_time = ! empty( $a->ledger_date ) ? strtotime( $a->ledger_date . ' UTC' ) : 0;
				$b_time = ! empty( $b->ledger_date ) ? strtotime( $b->ledger_date . ' UTC' ) : 0;
				if ( $a_time === $b_time ) {
					return ( isset( $a->id ) ? (int) $a->id : 0 ) <=> ( isset( $b->id ) ? (int) $b->id : 0 );
				}

				return $a_time <=> $b_time;
			}
		);

		foreach ( $entries as $entry ) {
			$entry_id = isset( $entry->id ) ? absint( $entry->id ) : 0;
			$customer_id = isset( $entry->stripe_customer_id ) ? sanitize_text_field( (string) $entry->stripe_customer_id ) : '';
			if ( '' === $customer_id ) {
				$customer_id = '_unknown';
			}

			if ( ! isset( $running_by_customer[ $customer_id ] ) ) {
				$running_by_customer[ $customer_id ] = 0.0;
			}

			$running_by_customer[ $customer_id ] += $this->get_portal_ledger_balance_effect( $entry );

			if ( $entry_id ) {
				$balances[ $entry_id ] = $running_by_customer[ $customer_id ];
			}
		}

		foreach ( $running_by_customer as $customer_id => $amount ) {
			$totals[ $customer_id ] = (float) $amount;
		}

		return array(
			'balances' => $balances,
			'totals'   => $totals,
		);
	}

	private function admin_portal_ledger_entry_is_recurring_service( $entry ) {
		$product_interval = $this->get_portal_product_recurring_interval_from_ids(
			$this->get_ledger_metadata_value( $entry, 'price_id' ),
			$this->get_ledger_metadata_value( $entry, 'product_id' )
		);
		if ( null !== $product_interval ) {
			return '' !== $product_interval;
		}

		$billing_type       = sanitize_key( $this->get_ledger_metadata_value( $entry, 'billing_type' ) );
		$recurring_interval = sanitize_key( $this->get_ledger_metadata_value( $entry, 'recurring_interval' ) );

		return 'recurring' === $billing_type || '' !== $recurring_interval;
	}

	private function get_admin_portal_ledger_reference_rank( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( in_array( $source_type, array( 'charge', 'payment' ), true ) ) {
			return ! empty( $entry->payment_intent_id ) ? 1 : ( ! empty( $entry->charge_id ) ? 2 : 6 );
		}
		if ( ! in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return 9;
		}
		if ( $this->admin_portal_ledger_entry_is_recurring_service( $entry ) ) {
			return '' !== $this->get_ledger_metadata_value( $entry, 'subscription_id' ) ? 1 : 7;
		}

		return '' !== $this->get_ledger_metadata_value( $entry, 'checkout_session_id' ) ? 1 : ( '' !== $this->get_ledger_metadata_value( $entry, 'payment_intent_id' ) ? 2 : 7 );
	}

	private function get_admin_portal_ledger_service_key( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( ! in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item' ), true ) ) {
			return '';
		}

		return implode(
			':',
			array_filter(
				array(
					! empty( $entry->stripe_customer_id ) ? sanitize_text_field( (string) $entry->stripe_customer_id ) : '',
					! empty( $entry->description ) ? sanitize_title( $this->clean_stripe_line_service_name( (string) $entry->description ) ) : '',
					! empty( $entry->invoice_id ) ? sanitize_text_field( (string) $entry->invoice_id ) : $this->get_ledger_metadata_value( $entry, 'invoice_id' ),
					number_format( abs( (float) $entry->amount ), 2, '.', '' ),
				),
				'strlen'
			)
		);
	}

	private function should_show_admin_portal_ledger_entry( $entry ) {
		$source_type = isset( $entry->source_type ) ? sanitize_key( (string) $entry->source_type ) : '';
		if ( in_array( $source_type, array( 'invoice', 'checkout_session' ), true ) ) {
			return false;
		}

		return in_array( $source_type, array( 'service_charge', 'invoice_line_item', 'checkout_line_item', 'manual_charge', 'charge', 'payment' ), true );
	}

	private function get_admin_portal_display_ledger( $ledger ) {
		$display = array();
		foreach ( (array) $ledger as $entry ) {
			if ( ! $this->should_show_admin_portal_ledger_entry( $entry ) ) {
				continue;
			}

			$key = $this->get_admin_portal_ledger_service_key( $entry );
			if ( '' !== $key && isset( $display[ $key ] ) ) {
				$existing = $display[ $key ];
				$current_ref_rank = $this->get_admin_portal_ledger_reference_rank( $entry );
				$existing_ref_rank = $this->get_admin_portal_ledger_reference_rank( $existing );
				if ( $current_ref_rank < $existing_ref_rank ) {
					$display[ $key ] = $entry;
				}
				continue;
			}

			$display[ '' !== $key ? $key : 'entry_' . ( isset( $entry->id ) ? (int) $entry->id : count( $display ) ) ] = $entry;
		}

		$display = array_values( $display );
		usort(
			$display,
			function ( $a, $b ) {
				$a_time = ! empty( $a->ledger_date ) ? strtotime( $a->ledger_date . ' UTC' ) : 0;
				$b_time = ! empty( $b->ledger_date ) ? strtotime( $b->ledger_date . ' UTC' ) : 0;
				if ( $a_time === $b_time ) {
					return ( isset( $a->id ) ? (int) $a->id : 0 ) <=> ( isset( $b->id ) ? (int) $b->id : 0 );
				}

				return $a_time <=> $b_time;
			}
		);

		return $display;
	}

	private function format_portal_balance_amount( $amount, $currency ) {
		$amount = (float) $amount;
		$label = $this->format_portal_money( abs( $amount ), $currency );

		if ( $amount < -0.00001 ) {
			return sprintf( __( 'Credit %s', 'ajforms' ), $label );
		}

		return $label;
	}

	private function format_portal_date( $date ) {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );
		if ( ! $timestamp ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	private function get_portal_customer_display_fields() {
		$fields = get_option( 'ajcore_portal_customer_display_fields', array( 'name', 'email', 'created', 'livemode' ) );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', $fields ) ) );
	}

	private function sanitize_portal_display_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_unique(
					array_map(
						function ( $field ) {
							return preg_replace( '/[^a-zA-Z0-9_.-]/', '', sanitize_text_field( (string) $field ) );
						},
						$fields
					)
				)
			)
		);
	}

	private function get_portal_detail_display_fields( $section, $defaults = array() ) {
		$section = sanitize_key( $section );
		$fields  = get_option( 'ajcore_portal_detail_display_fields_' . $section, $defaults );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_text_field', $fields ) ) );
	}

	private function discover_portal_customer_scalar_fields( $customers ) {
		$fields = array();

		foreach ( $customers as $customer ) {
			$raw = ! empty( $customer->raw_data ) ? json_decode( (string) $customer->raw_data, true ) : array();
			if ( is_array( $raw ) ) {
				$fields = array_merge( $fields, $this->flatten_scalar_field_paths( $raw ) );
			}
		}

		$fields = array_values( array_unique( $fields ) );
		sort( $fields, SORT_NATURAL | SORT_FLAG_CASE );

		return $fields;
	}

	private function discover_portal_row_scalar_fields( $rows ) {
		$fields = array();

		foreach ( $rows as $row ) {
			$data = is_array( $row ) ? $row : get_object_vars( $row );
			foreach ( $data as $key => $value ) {
				if ( is_scalar( $value ) && ! in_array( $key, array( 'raw_data' ), true ) ) {
					$fields[] = sanitize_key( (string) $key );
				}
			}

			if ( ! empty( $data['raw_data'] ) ) {
				$raw = json_decode( (string) $data['raw_data'], true );
				if ( is_array( $raw ) ) {
					$fields = array_merge( $fields, $this->flatten_scalar_field_paths( $raw ) );
				}
			}

			if ( ! empty( $data['metadata'] ) ) {
				$metadata = json_decode( (string) $data['metadata'], true );
				if ( is_array( $metadata ) ) {
					$fields = array_merge( $fields, $this->flatten_scalar_field_paths( $metadata, 'metadata' ) );
				}
			}
		}

		$fields = array_values( array_unique( $fields ) );
		sort( $fields, SORT_NATURAL | SORT_FLAG_CASE );

		return $fields;
	}

	private function flatten_scalar_field_paths( $data, $prefix = '', $depth = 0 ) {
		if ( $depth > 4 || ! is_array( $data ) ) {
			return array();
		}

		$fields = array();
		foreach ( $data as $key => $value ) {
			if ( is_int( $key ) ) {
				continue;
			}

			$key  = sanitize_key( (string) $key );
			$path = '' === $prefix ? $key : $prefix . '.' . $key;

			if ( is_scalar( $value ) ) {
				$fields[] = $path;
			} elseif ( is_array( $value ) && $this->is_assoc_array( $value ) ) {
				$fields = array_merge( $fields, $this->flatten_scalar_field_paths( $value, $path, $depth + 1 ) );
			}
		}

		return $fields;
	}

	private function is_assoc_array( $array ) {
		if ( array() === $array ) {
			return false;
		}

		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	private function get_nested_raw_value( $data, $path ) {
		$parts = explode( '.', (string) $path );
		$value = $data;

		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null;
			}
			$value = $value[ $part ];
		}

		return is_scalar( $value ) ? $value : null;
	}

	private function get_portal_customer_display_value( $customer, $field ) {
		$raw   = ! empty( $customer->raw_data ) ? json_decode( (string) $customer->raw_data, true ) : array();
		$value = is_array( $raw ) ? $this->get_nested_raw_value( $raw, $field ) : null;

		if ( null === $value && isset( $customer->{$field} ) ) {
			$value = $customer->{$field};
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'ajforms' ) : __( 'No', 'ajforms' );
		}

		if ( is_numeric( $value ) && in_array( $field, array( 'created', 'created_at', 'synced_at' ), true ) ) {
			return date_i18n( get_option( 'date_format' ), (int) $value );
		}

		return null === $value || '' === $value ? '-' : sanitize_text_field( (string) $value );
	}

	private function get_portal_row_display_value( $row, $field ) {
		$data  = is_array( $row ) ? $row : get_object_vars( $row );
		$value = null;

		if ( ! empty( $data['raw_data'] ) ) {
			$raw = json_decode( (string) $data['raw_data'], true );
			if ( is_array( $raw ) ) {
				$value = $this->get_nested_raw_value( $raw, $field );
			}
		}

		if ( null === $value && 0 === strpos( (string) $field, 'metadata.' ) && ! empty( $data['metadata'] ) ) {
			$metadata = json_decode( (string) $data['metadata'], true );
			if ( is_array( $metadata ) ) {
				$value = $this->get_nested_raw_value( array( 'metadata' => $metadata ), $field );
			}
		}

		if ( null === $value && array_key_exists( $field, $data ) ) {
			$value = $data[ $field ];
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'ajforms' ) : __( 'No', 'ajforms' );
		}

		if ( is_numeric( $value ) && preg_match( '/(^created$|_at$|_date$|date$)/', (string) $field ) ) {
			$timestamp = (int) $value;
			if ( $timestamp > 1000000000 ) {
				return date_i18n( get_option( 'date_format' ), $timestamp );
			}
		}

		if ( is_string( $value ) && preg_match( '/(^created_at$|_at$|_date$|date$)/', (string) $field ) ) {
			$formatted = $this->format_portal_date( $value );
			if ( '' !== $formatted ) {
				return $formatted;
			}
		}

		return null === $value || '' === $value ? '-' : sanitize_text_field( (string) $value );
	}

	private function format_portal_customer_field_label( $field ) {
		return ucwords( str_replace( array( '.', '_' ), ' ', sanitize_text_field( (string) $field ) ) );
	}

	private function enable_stripe_customer_as_portal_user( $stripe_customer_id ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'missing_customer', __( 'Stripe customer ID is required.', 'ajforms' ) );
		}

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
			)
		);

		if ( ! $customer || empty( $customer->email ) || ! is_email( $customer->email ) ) {
			return new WP_Error( 'missing_customer_email', __( 'This Stripe customer needs a valid email before it can be enabled as a portal user.', 'ajforms' ) );
		}
		$status_before = ! empty( $customer->portal_status ) ? sanitize_key( (string) $customer->portal_status ) : ( ! empty( $customer->enabled_portal ) ? 'active' : 'disabled' );
		$mapping_before = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s LIMIT 1",
				$stripe_customer_id
			)
		);

		$user = get_user_by( 'email', $customer->email );
		if ( ! $user ) {
			$user_id = wp_create_user(
				sanitize_user( current( explode( '@', $customer->email ) ), true ) . wp_rand( 1000, 9999 ),
				wp_generate_password( 24, true, true ),
				$customer->email
			);

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_user_by( 'id', $user_id );
			$this->log_portal_event(
				'portal_wp_user_created',
				array(
					'source'             => 'portal_users',
					'customer_id'        => isset( $customer->id ) ? (int) $customer->id : 0,
					'stripe_customer_id' => $stripe_customer_id,
					'wp_user_id_after'   => (int) $user_id,
					'email_after'        => $customer->email,
					'details'            => array( 'created_for_portal' => true ),
				)
			);
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => ! empty( $customer->name ) ? $customer->name : $customer->email,
					'first_name'   => ! empty( $customer->name ) ? $customer->name : '',
				)
			);
		}

		if ( ! $user ) {
			return new WP_Error( 'user_create_failed', __( 'Unable to create or load the portal user.', 'ajforms' ) );
		}

		$old_customer_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT stripe_customer_id FROM {$this->get_portal_user_mappings_table()} WHERE user_id = %d AND stripe_customer_id <> %s",
				(int) $user->ID,
				$stripe_customer_id
			)
		);

		foreach ( $old_customer_ids as $old_customer_id ) {
			$this->log_portal_event(
				'ambiguous_email',
				array(
					'severity'           => 'warning',
					'source'             => 'portal_users',
					'stripe_customer_id' => sanitize_text_field( $old_customer_id ),
					'wp_user_id_before'  => (int) $user->ID,
					'wp_user_id_after'   => (int) $user->ID,
					'email_before'       => $user->user_email,
					'email_after'        => $user->user_email,
					'details'            => array( 'kept_stripe_customer_id' => $stripe_customer_id, 'cleared_stripe_customer_id' => sanitize_text_field( $old_customer_id ) ),
				)
			);
			$this->set_portal_customer_status(
				sanitize_text_field( $old_customer_id ),
				'disabled',
				'ambiguous_email_cleared',
				'portal_users',
				array( 'kept_stripe_customer_id' => $stripe_customer_id )
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->get_portal_user_mappings_table()} WHERE (user_id = %d AND stripe_customer_id <> %s) OR (stripe_customer_id = %s AND user_id <> %d)",
				(int) $user->ID,
				$stripe_customer_id,
				$stripe_customer_id,
				(int) $user->ID
			)
		);

		$this->assign_aj_portal_user_role( $user );

		$this->set_portal_customer_status(
			$stripe_customer_id,
			'active',
			'enabled_portal_user',
			'portal_users',
			array(
				'wp_user_id'       => (int) $user->ID,
				'user_email'       => $user->user_email,
				'previous_status'  => $status_before,
			)
		);

		$this->upsert_portal_record(
			$this->get_portal_user_mappings_table(),
			array(
				'user_id'            => (int) $user->ID,
				'stripe_customer_id' => $stripe_customer_id,
				'customer_email'     => sanitize_email( $customer->email ),
				'portal_user_email'  => sanitize_email( $user->user_email ),
				'site_uuid'          => $this->get_ajcore_site_uuid(),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			'stripe_customer_id'
		);
		$this->log_portal_event(
			'portal_mapping_created',
			array(
				'source'                 => 'portal_users',
				'customer_id'            => isset( $customer->id ) ? (int) $customer->id : 0,
				'stripe_customer_id'     => $stripe_customer_id,
				'wp_user_id_before'      => $mapping_before && ! empty( $mapping_before->user_id ) ? (int) $mapping_before->user_id : 0,
				'wp_user_id_after'       => (int) $user->ID,
				'email_before'           => $mapping_before && ! empty( $mapping_before->portal_user_email ) ? $mapping_before->portal_user_email : '',
				'email_after'            => $user->user_email,
				'portal_status_before'   => $status_before,
				'portal_status_after'    => 'active',
			)
		);

		return (int) $user->ID;
	}

	private function format_stripe_price_label( $price ) {
		$product_name = isset( $price['product_name'] ) ? $price['product_name'] : __( 'Stripe product', 'ajforms' );
		$amount       = isset( $price['amount'] ) ? (float) $price['amount'] : 0;
		$currency     = isset( $price['currency'] ) ? strtoupper( $price['currency'] ) : 'USD';

		return sprintf(
			'%1$s - %2$s %3$s',
			$product_name,
			$currency,
			number_format_i18n( $amount, 2 )
		);
	}

	private function fetch_stripe_product_prices( $secret_key ) {
		$secret_key = sanitize_text_field( (string) $secret_key );
		if ( '' === $secret_key ) {
			return new WP_Error( 'missing_stripe_secret_key', __( 'Stripe secret key is required to sync products.', 'ajforms' ) );
		}

		$response = $this->stripe_api_get(
			'prices',
			$secret_key,
			array(
				'limit'                  => 100,
				'expand[]'               => 'data.product',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$prices = array();
		foreach ( isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array() as $price ) {
			if ( ! is_array( $price ) || empty( $price['id'] ) ) {
				continue;
			}

			$product      = isset( $price['product'] ) && is_array( $price['product'] ) ? $price['product'] : array();
			$product_id   = ! empty( $product['id'] ) ? sanitize_text_field( (string) $product['id'] ) : sanitize_text_field( (string) $price['product'] );
			$product_name = ! empty( $product['name'] ) ? sanitize_text_field( (string) $product['name'] ) : __( 'Stripe product', 'ajforms' );
			$product_description = ! empty( $product['description'] ) ? sanitize_textarea_field( (string) $product['description'] ) : '';
			$product_rich_description = '';
			$product_summary = '';
			if ( ! empty( $product['metadata'] ) && is_array( $product['metadata'] ) ) {
				if ( ! empty( $product['metadata']['summary'] ) ) {
					$product_summary = sanitize_text_field( (string) $product['metadata']['summary'] );
				} elseif ( ! empty( $product['metadata']['short_description'] ) ) {
					$product_summary = sanitize_text_field( (string) $product['metadata']['short_description'] );
				}
				if ( ! empty( $product['metadata']['rich_description'] ) ) {
					$product_rich_description = wp_kses_post( (string) $product['metadata']['rich_description'] );
				} elseif ( ! empty( $product['metadata']['description_html'] ) ) {
					$product_rich_description = wp_kses_post( (string) $product['metadata']['description_html'] );
				}
			}
			$product_metadata = ! empty( $product['metadata'] ) && is_array( $product['metadata'] ) ? $product['metadata'] : array();
			$price_metadata   = ! empty( $price['metadata'] ) && is_array( $price['metadata'] ) ? $price['metadata'] : array();
			$required_price_id = '';
			foreach ( array( 'ajcore_requires_price_id', 'requires_price_id', 'required_price_id' ) as $dependency_key ) {
				if ( '' === $required_price_id && ! empty( $price_metadata[ $dependency_key ] ) ) {
					$required_price_id = sanitize_text_field( (string) $price_metadata[ $dependency_key ] );
				}
				if ( '' === $required_price_id && ! empty( $product_metadata[ $dependency_key ] ) ) {
					$required_price_id = sanitize_text_field( (string) $product_metadata[ $dependency_key ] );
				}
			}
			$required_product_name = '';
			foreach ( array( 'ajcore_requires_product_name', 'requires_product_name', 'required_product_name' ) as $dependency_key ) {
				if ( '' === $required_product_name && ! empty( $price_metadata[ $dependency_key ] ) ) {
					$required_product_name = sanitize_text_field( (string) $price_metadata[ $dependency_key ] );
				}
				if ( '' === $required_product_name && ! empty( $product_metadata[ $dependency_key ] ) ) {
					$required_product_name = sanitize_text_field( (string) $product_metadata[ $dependency_key ] );
				}
			}
			$dependency_note = '';
			foreach ( array( 'ajcore_dependency_note', 'dependency_note', 'required_product_note' ) as $dependency_note_key ) {
				if ( '' === $dependency_note && ! empty( $price_metadata[ $dependency_note_key ] ) ) {
					$dependency_note = sanitize_textarea_field( (string) $price_metadata[ $dependency_note_key ] );
				}
				if ( '' === $dependency_note && ! empty( $product_metadata[ $dependency_note_key ] ) ) {
					$dependency_note = sanitize_textarea_field( (string) $product_metadata[ $dependency_note_key ] );
				}
			}
			$product_active = ! isset( $product['active'] ) || ! empty( $product['active'] );
			$price_active   = ! isset( $price['active'] ) || ! empty( $price['active'] );
			$unit_amount  = isset( $price['unit_amount'] ) ? absint( $price['unit_amount'] ) : 0;
			$currency     = isset( $price['currency'] ) ? strtolower( sanitize_key( $price['currency'] ) ) : 'usd';
			$amount       = in_array( $currency, array( 'jpy', 'krw', 'vnd' ), true ) ? $unit_amount : $unit_amount / 100;
			$recurring    = isset( $price['recurring'] ) && is_array( $price['recurring'] ) ? $price['recurring'] : array();
			$recurring_interval = ! empty( $recurring['interval'] ) ? sanitize_key( (string) $recurring['interval'] ) : '';
			$recurring_interval_count = ! empty( $recurring['interval_count'] ) ? absint( $recurring['interval_count'] ) : 1;

			if ( $unit_amount <= 0 ) {
				continue;
			}

			$prices[] = array(
				'id'           => sanitize_text_field( (string) $price['id'] ),
				'product_id'   => $product_id,
				'product_name' => $product_name,
				'product_description' => $product_description,
				'product_rich_description' => $product_rich_description,
				'product_summary' => $product_summary,
				'product_metadata' => $product_metadata,
				'price_metadata' => $price_metadata,
				'requires_price_id' => $required_price_id,
				'requires_product_name' => $required_product_name,
				'dependency_note' => isset( $dependency_note ) ? $dependency_note : '',
				'product_active' => $product_active,
				'price_active'   => $price_active,
				'nickname'     => ! empty( $price['nickname'] ) ? sanitize_text_field( (string) $price['nickname'] ) : '',
				'amount'       => $amount,
				'currency'     => $currency,
				'recurring_interval' => $recurring_interval,
				'recurring_interval_count' => $recurring_interval_count,
			);
		}

		usort(
			$prices,
			function ( $a, $b ) {
				return strcasecmp( $a['product_name'], $b['product_name'] );
			}
		);

		$dependency_settings = $this->get_public_product_dependency_settings();
		if ( ! empty( $dependency_settings ) ) {
			foreach ( $prices as $index => $price ) {
				if ( ! is_array( $price ) || empty( $price['id'] ) || empty( $dependency_settings[ $price['id'] ] ) ) {
					continue;
				}
				$dependency = $dependency_settings[ $price['id'] ];
				if ( ! empty( $dependency['requires_price_id'] ) ) {
					$prices[ $index ]['requires_price_id'] = $dependency['requires_price_id'];
				}
				if ( ! empty( $dependency['dependency_note'] ) ) {
					$prices[ $index ]['dependency_note'] = $dependency['dependency_note'];
				}
			}
		}

		$cache = array(
			'updated_at' => current_time( 'mysql' ),
			'prices'     => $prices,
		);
		$this->update_stripe_products_cache( $cache );

		return $cache;
	}

	private function asana_api_get( $path, $token, $query_args = array() ) {
		$url = 'https://app.asana.com/api/1.0/' . ltrim( $path, '/' );

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : __( 'Asana request failed.', 'ajforms' );
			return new WP_Error( 'asana_api_error', $message );
		}

		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
	}

	private function fetch_asana_reference_data( $token, $workspace_gid = '' ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'missing_token', __( 'Asana personal access token is required.', 'ajforms' ) );
		}

		$workspaces = $this->asana_api_get( 'workspaces', $token, array( 'limit' => 100 ) );
		if ( is_wp_error( $workspaces ) ) {
			return $workspaces;
		}

		$formatted_workspaces = array();
		foreach ( $workspaces as $workspace ) {
			if ( empty( $workspace['gid'] ) || empty( $workspace['name'] ) ) {
				continue;
			}

			$formatted_workspaces[] = array(
				'gid'  => sanitize_text_field( (string) $workspace['gid'] ),
				'name' => sanitize_text_field( (string) $workspace['name'] ),
			);
		}

		$selected_workspace_gid = sanitize_text_field( (string) $workspace_gid );
		if ( '' === $selected_workspace_gid && ! empty( $formatted_workspaces[0]['gid'] ) ) {
			$selected_workspace_gid = $formatted_workspaces[0]['gid'];
		}

		$projects = array();
		$users    = array();
		if ( '' !== $selected_workspace_gid ) {
			$workspace_projects = $this->asana_api_get(
				'workspaces/' . rawurlencode( $selected_workspace_gid ) . '/projects',
				$token,
				array(
					'limit'              => 100,
					'archived'           => 'false',
					'opt_fields'         => 'gid,name',
				)
			);

			if ( is_wp_error( $workspace_projects ) ) {
				return $workspace_projects;
			}

			foreach ( $workspace_projects as $project ) {
				if ( empty( $project['gid'] ) || empty( $project['name'] ) ) {
					continue;
				}

				$projects[] = array(
					'gid'  => sanitize_text_field( (string) $project['gid'] ),
					'name' => sanitize_text_field( (string) $project['name'] ),
				);
			}

			$workspace_users = $this->asana_api_get(
				'workspaces/' . rawurlencode( $selected_workspace_gid ) . '/users',
				$token,
				array(
					'limit'  => 100,
					'opt_fields' => 'gid,name,email',
				)
			);

			if ( is_wp_error( $workspace_users ) ) {
				return $workspace_users;
			}

			foreach ( $workspace_users as $user ) {
				if ( empty( $user['gid'] ) || empty( $user['name'] ) ) {
					continue;
				}

				$users[] = array(
					'gid'   => sanitize_text_field( (string) $user['gid'] ),
					'name'  => sanitize_text_field( (string) $user['name'] ),
					'email' => isset( $user['email'] ) ? sanitize_email( (string) $user['email'] ) : '',
				);
			}
		}

		$cache = array(
			'updated_at'    => current_time( 'mysql' ),
			'workspaces'    => $formatted_workspaces,
			'projects'      => $projects,
			'users'         => $users,
			'workspace_gid' => $selected_workspace_gid,
		);

		$this->update_asana_reference_cache( $cache );

		return $cache;
	}

	public function sync_asana_reference_data() {
		$settings = $this->get_plugin_settings();
		if ( empty( $settings['asana_personal_access_token'] ) ) {
			return;
		}

		return $this->fetch_asana_reference_data(
			$settings['asana_personal_access_token'],
			isset( $settings['asana_workspace_gid'] ) ? $settings['asana_workspace_gid'] : ''
		);
	}

	public function ajax_sync_asana_reference_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ajforms' ), 403 );
		}

		check_ajax_referer( 'ajf_sync_asana_reference_data', 'nonce' );

		$token         = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$workspace_gid = isset( $_POST['workspace_gid'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_gid'] ) ) : '';
		$result        = $this->fetch_asana_reference_data( $token, $workspace_gid );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 400 );
		}

		wp_send_json_success( $result );
	}

	public function get_plugin_settings() {
		return function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array(
			'default_notification_email'     => get_option( 'admin_email' ),
			'default_notification_subject'   => 'New submission for {form_title}',
			'default_notifications_enabled' => '1',
			'default_from_name'              => get_bloginfo( 'name' ),
			'default_reply_to_mode'          => 'submitter',
			'default_success_message'        => 'Form submitted successfully.',
			'validation_mode'                => 'native',
			'require_unique_form_names'      => '1',
			'honeypot_enabled'               => '1',
			'spam_challenge_provider'        => 'turnstile',
			'recaptcha_site_key'             => '',
			'recaptcha_secret_key'           => '',
			'hcaptcha_site_key'              => '',
			'hcaptcha_secret_key'            => '',
			'turnstile_site_key'             => '',
			'turnstile_secret_key'           => '',
			'webhook_url'                    => '',
			'asana_enabled'                  => '0',
			'asana_personal_access_token'    => '',
			'asana_workspace_gid'            => '',
			'asana_project_gid'              => '',
			'stripe_mode'                    => 'test',
			'stripe_sandbox_publishable_key'  => '',
			'stripe_sandbox_secret_key'       => '',
			'stripe_live_publishable_key'     => '',
			'stripe_live_secret_key'          => '',
			'stripe_publishable_key'         => '',
			'stripe_secret_key'              => '',
			'stripe_products_mode'           => 'all',
			'stripe_selected_prices'         => array(),
		);
	}


	private function get_stripe_mode_badge_data() {
		$settings = $this->get_plugin_settings();

		if ( function_exists( 'ajcore_get_stripe_mode_badge_data' ) ) {
			return ajcore_get_stripe_mode_badge_data( $settings );
		}

		$mode = ! empty( $settings['stripe_mode'] ) && 'live' === sanitize_key( (string) $settings['stripe_mode'] ) ? 'live' : 'test';

		return array(
			'mode'       => $mode,
			'label'      => 'live' === $mode ? __( 'Live', 'ajforms' ) : __( 'Sandbox', 'ajforms' ),
			'is_live'    => 'live' === $mode,
			'has_issues' => false,
			'issues'     => array(),
		);
	}

	public function display_stripe_mode_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$stripe_pages = array(
			'ajforms',
			'ajforms-products',
			'ajforms-client-portal',
			'ajforms-settings',
		);

		if ( ! in_array( $page, $stripe_pages, true ) ) {
			return;
		}

		$data = $this->get_stripe_mode_badge_data();
		$settings_url = add_query_arg( array( 'page' => 'ajforms-settings', 'section' => 'payments' ), admin_url( 'admin.php' ) );
		$notice_class = ! empty( $data['has_issues'] ) ? 'notice-error' : ( ! empty( $data['is_live'] ) ? 'notice-warning' : 'notice-info' );
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> ajcore-stripe-mode-notice">
			<p>
				<strong><?php echo esc_html( sprintf( __( 'Stripe %s Mode', 'ajforms' ), $data['label'] ) ); ?></strong>
				<?php if ( ! empty( $data['is_live'] ) ) : ?>
					<?php esc_html_e( 'Real customer payments are enabled.', 'ajforms' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Sandbox payments only. Use Stripe sandbox/test cards such as 4242 4242 4242 4242.', 'ajforms' ); ?>
				<?php endif; ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Stripe settings', 'ajforms' ); ?></a>
			</p>
			<?php if ( ! empty( $data['issues'] ) ) : ?>
				<ul style="margin-top:0;list-style:disc;padding-left:22px;">
					<?php foreach ( $data['issues'] as $issue ) : ?>
						<li><?php echo esc_html( $issue ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_stripe_mode_blocking_error() {
		$settings = $this->get_plugin_settings();
		$issues = function_exists( 'ajcore_get_stripe_mode_issues' ) ? ajcore_get_stripe_mode_issues( $settings, true ) : array();

		if ( empty( $issues ) ) {
			return '';
		}

		return implode( ' ', array_map( 'sanitize_text_field', $issues ) );
	}

	private function normalize_imported_schema( $schema ) {
		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			return array(
				'version'   => isset( $schema['version'] ) ? absint( $schema['version'] ) : 1,
				'source'    => isset( $schema['source'] ) ? sanitize_text_field( $schema['source'] ) : 'ajforms',
				'fields'    => $schema['fields'],
				'settings'  => isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : array(),
				'sureforms' => isset( $schema['sureforms'] ) && is_array( $schema['sureforms'] ) ? $schema['sureforms'] : array(),
			);
		}

		if ( is_array( $schema ) ) {
			return array(
				'version'   => 1,
				'source'    => 'legacy',
				'fields'    => $schema,
				'settings'  => array(),
				'sureforms' => array(),
			);
		}

		return array(
			'version'   => 1,
			'source'    => 'ajforms',
			'fields'    => array(),
			'settings'  => array(),
			'sureforms' => array(),
		);
	}

	private function normalize_field_for_storage( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$normalized = array(
			'id'          => isset( $field['id'] ) ? sanitize_key( $field['id'] ) : '',
			'type'        => isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text',
			'label'       => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
			'field_name'  => isset( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : '',
			'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
			'required'    => ! empty( $field['required'] ),
			'css_class'   => isset( $field['css_class'] ) ? sanitize_html_class( $field['css_class'] ) : '',
			'width'       => isset( $field['width'] ) ? absint( $field['width'] ) : 100,
			'help_text'   => isset( $field['help_text'] ) ? sanitize_text_field( $field['help_text'] ) : '',
			'default_value' => isset( $field['default_value'] ) ? sanitize_text_field( $field['default_value'] ) : '',
			'conversational' => array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( isset( $field['conversation_step'] ) ? 'final_contact' !== sanitize_key( $field['conversation_step'] ) : ( isset( $field['type'] ) && 'question' === sanitize_key( $field['type'] ) ) ),
			'branch_map'   => array(),
			'flow_rules'   => array(),
			'accepted_file_types' => isset( $field['accepted_file_types'] ) ? sanitize_text_field( $field['accepted_file_types'] ) : '.pdf,.jpg,.jpeg,.png,.gif,.webp',
		);
		$normalized['conversation_step'] = $normalized['conversational'] ? 'question' : 'final_contact';

		if ( '' === $normalized['id'] ) {
			$normalized['id'] = 'field_' . wp_generate_password( 8, false, false );
		}

		if ( '' === $normalized['field_name'] ) {
			$normalized['field_name'] = sanitize_key( $normalized['label'] ? $normalized['label'] : $normalized['id'] );
		}

		if ( ! in_array( $normalized['width'], array( 100, 50, 33, 25 ), true ) ) {
			$normalized['width'] = 100;
		}

		if ( isset( $field['branch_map'] ) && is_array( $field['branch_map'] ) ) {
			foreach ( $field['branch_map'] as $option_value => $target_id ) {
				$option_value = sanitize_text_field( (string) $option_value );
				$target_id    = sanitize_text_field( (string) $target_id );

				if ( '' !== $option_value && '' !== $target_id ) {
					$normalized['branch_map'][ $option_value ] = $target_id;
				}
			}
		}

		if ( isset( $field['flow_rules'] ) && is_array( $field['flow_rules'] ) ) {
			$normalized['flow_rules'] = $this->sanitize_rules_for_storage( $field['flow_rules'], 'flow' );
		}

		if ( in_array( $normalized['type'], array( 'question', 'select', 'checkboxes', 'multiple_choice' ), true ) ) {
			$normalized['options'] = array();

			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $option ) {
					$label = '';
					$value = '';

					if ( is_array( $option ) ) {
						$label = isset( $option['label'] ) ? sanitize_text_field( $option['label'] ) : '';
						$value = isset( $option['value'] ) ? sanitize_text_field( $option['value'] ) : '';
					} else {
						$label = sanitize_text_field( $option );
						$value = $label;
					}

					if ( '' === $label && '' === $value ) {
						continue;
					}

					$normalized['options'][] = array(
						'label' => '' !== $label ? $label : $value,
						'value' => '' !== $value ? $value : $label,
					);
				}
			}

			if ( 'question' === $normalized['type'] && empty( $normalized['options'] ) ) {
				$normalized['options'] = array(
					array(
						'label' => 'Yes',
						'value' => 'yes',
					),
					array(
						'label' => 'No',
						'value' => 'no',
					),
				);
			}
		}

		return $normalized;
	}

	private function sanitize_rules_for_storage( $rules, $context = 'confirmation' ) {
		$sanitized = array();

		foreach ( (array) $rules as $index => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$conditions = array();
			if ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ) {
				foreach ( $rule['conditions'] as $condition ) {
					if ( ! is_array( $condition ) ) {
						continue;
					}

					$field    = isset( $condition['field'] ) ? sanitize_key( $condition['field'] ) : '';
					$operator = isset( $condition['operator'] ) ? sanitize_key( $condition['operator'] ) : 'equals';
					$value    = isset( $condition['value'] ) ? sanitize_text_field( (string) $condition['value'] ) : '';

					if ( '' === $field || ! in_array( $operator, array( 'equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty' ), true ) ) {
						continue;
					}

					$conditions[] = array(
						'field'    => $field,
						'operator' => $operator,
						'value'    => $value,
					);
				}
			}

			$actions = array();
			if ( isset( $rule['actions'] ) && is_array( $rule['actions'] ) ) {
				foreach ( $rule['actions'] as $action ) {
					if ( ! is_array( $action ) ) {
						continue;
					}

					$type = isset( $action['type'] ) ? sanitize_key( $action['type'] ) : '';
					if ( 'flow' === $context ) {
						if ( ! in_array( $type, array( 'next', 'jump', 'end', 'action' ), true ) ) {
							continue;
						}

						$actions[] = array(
							'type'   => $type,
							'target' => isset( $action['target'] ) ? sanitize_text_field( (string) $action['target'] ) : '',
						);
					} else {
						if ( ! in_array( $type, array( 'show_message', 'redirect', 'webhook' ), true ) ) {
							continue;
						}

						$actions[] = array(
							'type'    => $type,
							'message' => isset( $action['message'] ) ? wp_kses_post( (string) $action['message'] ) : '',
							'url'     => isset( $action['url'] ) ? esc_url_raw( (string) $action['url'] ) : '',
						);
					}
				}
			}

			if ( empty( $conditions ) || empty( $actions ) ) {
				continue;
			}

			$sanitized[] = array(
				'id'              => isset( $rule['id'] ) ? sanitize_key( $rule['id'] ) : 'rule_' . ( $index + 1 ),
				'name'            => isset( $rule['name'] ) ? sanitize_text_field( (string) $rule['name'] ) : '',
				'enabled'         => ! array_key_exists( 'enabled', $rule ) || ! empty( $rule['enabled'] ),
				'priority'        => isset( $rule['priority'] ) ? intval( $rule['priority'] ) : ( $index + 1 ) * 10,
				'logic'           => isset( $rule['logic'] ) && 'OR' === strtoupper( (string) $rule['logic'] ) ? 'OR' : 'AND',
				'conditions'      => $conditions,
				'actions'         => $actions,
				'stop_processing' => ! empty( $rule['stop_processing'] ),
			);
		}

		usort(
			$sanitized,
			function ( $a, $b ) {
				return intval( $a['priority'] ) <=> intval( $b['priority'] );
			}
		);

		return $sanitized;
	}

	private function sanitize_schema_for_storage( $schema ) {
		$normalized = $this->normalize_imported_schema( $schema );
		$fields     = array();
		$plugin_settings = $this->get_plugin_settings();

		foreach ( $normalized['fields'] as $field ) {
			$sanitized_field = $this->normalize_field_for_storage( $field );
			if ( null !== $sanitized_field ) {
				$fields[] = $sanitized_field;
			}
		}

		return array(
			'version'   => isset( $normalized['version'] ) ? absint( $normalized['version'] ) : 1,
			'source'    => isset( $normalized['source'] ) ? sanitize_text_field( $normalized['source'] ) : 'ajforms',
			'fields'    => $fields,
			'settings'  => array(
				'submit_text'           => isset( $normalized['settings']['submit_text'] ) ? sanitize_text_field( $normalized['settings']['submit_text'] ) : 'Submit',
				'notifications_enabled' => isset( $normalized['settings']['notifications_enabled'] ) ? (bool) $normalized['settings']['notifications_enabled'] : true,
				'notification_email'    => isset( $normalized['settings']['notification_email'] ) ? sanitize_text_field( $normalized['settings']['notification_email'] ) : $plugin_settings['default_notification_email'],
				'notification_subject'  => isset( $normalized['settings']['notification_subject'] ) ? sanitize_text_field( $normalized['settings']['notification_subject'] ) : $plugin_settings['default_notification_subject'],
				'notification_body'     => isset( $normalized['settings']['notification_body'] ) ? wp_kses_post( $normalized['settings']['notification_body'] ) : "{submission_table}{submission_details_table}",
				'notification_from_name' => isset( $normalized['settings']['notification_from_name'] ) ? sanitize_text_field( $normalized['settings']['notification_from_name'] ) : ( isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ) ),
				'notification_from_email' => isset( $normalized['settings']['notification_from_email'] ) ? sanitize_email( $normalized['settings']['notification_from_email'] ) : '',
				'notification_reply_to' => isset( $normalized['settings']['notification_reply_to'] ) ? sanitize_text_field( $normalized['settings']['notification_reply_to'] ) : '',
				'button_alignment'      => isset( $normalized['settings']['button_alignment'] ) ? sanitize_key( $normalized['settings']['button_alignment'] ) : 'left',
				'form_description'      => isset( $normalized['settings']['form_description'] ) ? sanitize_textarea_field( $normalized['settings']['form_description'] ) : '',
				'success_message'       => isset( $normalized['settings']['success_message'] ) ? sanitize_textarea_field( $normalized['settings']['success_message'] ) : ( isset( $plugin_settings['default_success_message'] ) ? $plugin_settings['default_success_message'] : 'Form submitted successfully.' ),
				'confirmation_mode'     => isset( $normalized['settings']['confirmation_mode'] ) && in_array( sanitize_key( $normalized['settings']['confirmation_mode'] ), array( 'default', 'conditional' ), true ) ? sanitize_key( $normalized['settings']['confirmation_mode'] ) : ( ! empty( $normalized['settings']['confirmation_rules'] ) ? 'conditional' : 'default' ),
				'confirmation_type'     => isset( $normalized['settings']['confirmation_type'] ) && in_array( sanitize_key( $normalized['settings']['confirmation_type'] ), array( 'message', 'redirect' ), true ) ? sanitize_key( $normalized['settings']['confirmation_type'] ) : 'message',
				'redirect_url'          => isset( $normalized['settings']['redirect_url'] ) ? esc_url_raw( $normalized['settings']['redirect_url'] ) : '',
				'confirmation_rules'    => isset( $normalized['settings']['confirmation_rules'] ) && is_array( $normalized['settings']['confirmation_rules'] ) ? $this->sanitize_rules_for_storage( $normalized['settings']['confirmation_rules'], 'confirmation' ) : array(),
				'use_label_placeholders' => ! empty( $normalized['settings']['use_label_placeholders'] ),
				'custom_css'            => isset( $normalized['settings']['custom_css'] ) ? wp_strip_all_tags( $normalized['settings']['custom_css'] ) : '',
				'asana_task_enabled'    => ! empty( $normalized['settings']['asana_task_enabled'] ),
				'asana_task_name'       => isset( $normalized['settings']['asana_task_name'] ) ? sanitize_text_field( $normalized['settings']['asana_task_name'] ) : 'New form submission: {form_title}',
				'asana_task_notes'      => isset( $normalized['settings']['asana_task_notes'] ) ? sanitize_textarea_field( $normalized['settings']['asana_task_notes'] ) : "Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}",
				'asana_project_gid'     => isset( $normalized['settings']['asana_project_gid'] ) ? sanitize_text_field( $normalized['settings']['asana_project_gid'] ) : '',
				'asana_assignee_gid'    => isset( $normalized['settings']['asana_assignee_gid'] ) ? sanitize_text_field( $normalized['settings']['asana_assignee_gid'] ) : '',
				'asana_due_date'        => isset( $normalized['settings']['asana_due_date'] ) && in_array( sanitize_key( $normalized['settings']['asana_due_date'] ), array( 'none', 'today' ), true ) ? sanitize_key( $normalized['settings']['asana_due_date'] ) : 'today',
			'stripe_enabled'        => ! empty( $normalized['settings']['stripe_enabled'] ),
			'stripe_price_id'       => isset( $normalized['settings']['stripe_price_id'] ) ? sanitize_text_field( $normalized['settings']['stripe_price_id'] ) : '',
			'stripe_price_label'    => isset( $normalized['settings']['stripe_price_label'] ) ? sanitize_text_field( $normalized['settings']['stripe_price_label'] ) : '',
			'stripe_amount'         => isset( $normalized['settings']['stripe_amount'] ) ? max( 0, round( (float) $normalized['settings']['stripe_amount'], 2 ) ) : 0,
			'stripe_currency'       => isset( $normalized['settings']['stripe_currency'] ) ? sanitize_key( $normalized['settings']['stripe_currency'] ) : 'usd',
			'stripe_description'    => isset( $normalized['settings']['stripe_description'] ) ? sanitize_text_field( $normalized['settings']['stripe_description'] ) : 'Payment for {form_title}',
				'form_theme'            => isset( $normalized['settings']['form_theme'] ) && in_array( sanitize_key( $normalized['settings']['form_theme'] ), array( 'clean', 'soft', 'contrast' ), true ) ? sanitize_key( $normalized['settings']['form_theme'] ) : 'clean',
				'background_mode'       => isset( $normalized['settings']['background_mode'] ) && in_array( sanitize_key( $normalized['settings']['background_mode'] ), array( 'solid', 'gradient' ), true ) ? sanitize_key( $normalized['settings']['background_mode'] ) : 'solid',
				'background_color'      => isset( $normalized['settings']['background_color'] ) ? sanitize_hex_color( $normalized['settings']['background_color'] ) : '#ffffff',
				'background_gradient_start' => isset( $normalized['settings']['background_gradient_start'] ) ? sanitize_hex_color( $normalized['settings']['background_gradient_start'] ) : '#ffffff',
				'background_gradient_end'   => isset( $normalized['settings']['background_gradient_end'] ) ? sanitize_hex_color( $normalized['settings']['background_gradient_end'] ) : '#f3f7fb',
				'primary_color'         => isset( $normalized['settings']['primary_color'] ) ? sanitize_hex_color( $normalized['settings']['primary_color'] ) : '#0f7ac6',
				'text_color'            => isset( $normalized['settings']['text_color'] ) ? sanitize_hex_color( $normalized['settings']['text_color'] ) : '#1f2937',
				'input_background'      => isset( $normalized['settings']['input_background'] ) ? sanitize_hex_color( $normalized['settings']['input_background'] ) : '#ffffff',
				'input_border_color'    => isset( $normalized['settings']['input_border_color'] ) ? sanitize_hex_color( $normalized['settings']['input_border_color'] ) : '#d7dce3',
				'border_radius'         => isset( $normalized['settings']['border_radius'] ) ? min( 32, max( 0, absint( $normalized['settings']['border_radius'] ) ) ) : 16,
			),
			'sureforms' => isset( $normalized['sureforms'] ) && is_array( $normalized['sureforms'] ) ? $normalized['sureforms'] : array(),
		);
	}

	private function delete_forms_and_related_data( $form_ids, $permanent = false ) {
		global $wpdb;

		$form_ids = array_filter( array_map( 'absint', (array) $form_ids ) );
		if ( empty( $form_ids ) ) {
			return;
		}

		$forms_table      = $this->get_forms_table();
		$leads_table      = $this->get_leads_table();
		$lead_notes_table = $this->get_lead_notes_table();
		$placeholders     = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );

		$lead_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$leads_table} WHERE form_id IN ({$placeholders})",
				$form_ids
			)
		);

		if ( $permanent && ! empty( $lead_ids ) ) {
			$lead_placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$lead_notes_table} WHERE lead_id IN ({$lead_placeholders})",
					$lead_ids
				)
			);
		}

		if ( $permanent ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$leads_table} WHERE form_id IN ({$placeholders})",
					$form_ids
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$forms_table} WHERE id IN ({$placeholders})",
					$form_ids
				)
			);
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$forms_table} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
				array_merge( array( 'deleted', current_time( 'mysql' ) ), $form_ids )
			)
		);
	}

	public function get_form_preview_url( $form_id ) {
		return add_query_arg(
			array(
				'ajforms_preview' => absint( $form_id ),
			),
			home_url( '/' )
		);
	}

	private function get_form_edit_url( $form_id ) {
		return add_query_arg(
			array(
				'page'    => 'ajforms',
				'action'  => 'edit',
				'form_id' => absint( $form_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	private function duplicate_form( $form_id ) {
		global $wpdb;

		$table = $this->get_forms_table();
		$form  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$form_id
			)
		);

		if ( ! $form ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'title'       => sprintf( __( '%s Copy', 'ajforms' ), $form->title ),
				'form_schema' => $form->form_schema,
				'status'      => 'draft',
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	private function export_form( $form_id ) {
		global $wpdb;

		$table = $this->get_forms_table();
		$form  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$form_id
			),
			ARRAY_A
		);

		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'ajforms' ) );
		}

		$payload = array(
			'title'  => $form['title'],
			'status' => $form['status'],
			'schema' => json_decode( $form['form_schema'], true ),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $form['title'] . '.json' ) );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT );
		exit;
	}

	public function handle_export_form_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $form_id || ! wp_verify_nonce( $nonce, 'ajf_export_form' ) ) {
			wp_die( esc_html__( 'Invalid export request.', 'ajforms' ) );
		}

		$this->export_form( $form_id );
	}

	private function update_form_status( $form_id, $status ) {
		global $wpdb;

		$allowed_statuses = array( 'draft', 'published', 'deleted' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			return false;
		}

		return false !== $wpdb->update(
			$this->get_forms_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $form_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	private function handle_form_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_GET['form_action'], $_GET['form_id'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$form_action = sanitize_text_field( wp_unslash( $_GET['form_action'] ) );
		$form_id     = absint( wp_unslash( $_GET['form_id'] ) );
		$nonce       = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'ajf_form_action_' . $form_id ) ) {
			return;
		}

		$redirect = admin_url( 'admin.php?page=ajforms' );

		if ( 'delete' === $form_action ) {
			$this->delete_forms_and_related_data( array( $form_id ) );
			$redirect = add_query_arg( array( 'page' => 'ajforms', 'form_status' => 'deleted', 'trashed' => 1 ), admin_url( 'admin.php' ) );
		} elseif ( 'restore' === $form_action ) {
			$this->update_form_status( $form_id, 'draft' );
			$redirect = add_query_arg( array( 'page' => 'ajforms', 'restored' => 1 ), admin_url( 'admin.php' ) );
		} elseif ( 'duplicate' === $form_action ) {
			$new_form_id = $this->duplicate_form( $form_id );
			if ( $new_form_id ) {
				$redirect = add_query_arg(
					array(
						'page'      => 'ajforms',
						'action'    => 'edit',
						'form_id'   => $new_form_id,
						'duplicated'=> 1,
					),
					admin_url( 'admin.php' )
				);
			}
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_admin_actions() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'ajforms' === $page ) {
			$this->handle_form_actions();
		} elseif ( 'ajforms-leads' === $page ) {
			$this->handle_lead_actions();
		} elseif ( 'ajforms-settings' === $page ) {
			$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
			if ( 'role-manager' === $section || isset( $_GET['role_manager_action'] ) || isset( $_POST['role_manager_action'] ) ) {
				$this->handle_role_manager_actions();
			} else {
				$this->handle_settings_save();
			}
		} elseif ( 'ajforms-products' === $page ) {
			$this->handle_products_action();
		} elseif ( 'ajforms-file-library' === $page ) {
			$this->handle_file_library_actions();
		} elseif ( 'ajforms-service-requests' === $page ) {
			$this->handle_service_requests_actions();
		} elseif ( 'ajforms-client-portal' === $page ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'service-requests';
			if ( 'service-requests' === $tab || isset( $_GET['service_request_action'] ) ) {
				$this->handle_service_requests_actions();
			} elseif ( 'billing' === $tab || isset( $_POST['ajcore_billing_action'] ) ) {
				$this->handle_portal_billing_actions();
			} elseif ( in_array( $tab, array( 'sync', 'menu', 'portal-users', 'sold-items', 'products-services', 'tasks' ), true ) ) {
				$this->handle_client_portal_settings_save();
			} elseif ( 'customer' === $tab ) {
				$this->handle_portal_customer_detail_actions();
			} elseif ( 'file-library' === $tab ) {
				$this->handle_file_library_actions();
			}
		} elseif ( 'ajforms-auth' === $page ) {
			$this->handle_auth_settings_save();
		} elseif ( 'ajforms-automations' === $page ) {
			$this->handle_automations_settings_save();
		} elseif ( 'ajforms-role-manager' === $page ) {
			$this->handle_role_manager_actions();
		} elseif ( 'ajforms-about' === $page ) {
			$this->handle_about_update_action();
		}
	}

	private function current_user_can_manage_ajcore_roles() {
		$user = wp_get_current_user();

		return is_super_admin() || ( $user && in_array( 'administrator', (array) $user->roles, true ) );
	}

	private function get_customer_portal_menu_items() {
		$items = get_option( 'ajcore_customer_portal_menu_items', array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$default_items = array(
			array( 'id' => 'overview', 'label' => __( 'Overview', 'ajforms' ), 'type' => 'built_in', 'url' => '', 'enabled' => true ),
			array( 'id' => 'services', 'label' => __( 'My Services', 'ajforms' ), 'type' => 'built_in', 'url' => '', 'enabled' => true ),
			array( 'id' => 'tasks', 'label' => __( 'Tasks', 'ajforms' ), 'type' => 'built_in', 'url' => '', 'enabled' => true ),
			array( 'id' => 'billing', 'label' => __( 'Billing', 'ajforms' ), 'type' => 'built_in', 'url' => '', 'enabled' => true ),
			array( 'id' => 'file-library', 'label' => __( 'File Library', 'ajforms' ), 'type' => 'built_in', 'url' => '', 'enabled' => true ),
			array( 'id' => 'profile', 'label' => __( 'Profile', 'ajforms' ), 'type' => 'built_in', 'url' => '', 'enabled' => true ),
		);

		$built_in_ids    = wp_list_pluck( $default_items, 'id' );
		$built_in_labels = array_map( 'strtolower', wp_list_pluck( $default_items, 'label' ) );

		$normalized = array();
		foreach ( array_merge( $default_items, $items ) as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}

			$id    = sanitize_key( $item['id'] );
			$type  = ! empty( $item['type'] ) && 'custom' === $item['type'] ? 'custom' : 'built_in';
			$label = ! empty( $item['label'] ) ? sanitize_text_field( $item['label'] ) : $id;

			if ( 'custom' === $type && ( in_array( $id, $built_in_ids, true ) || in_array( strtolower( $label ), $built_in_labels, true ) ) ) {
				continue;
			}

			$normalized[ $id ] = array(
				'id'      => $id,
				'label'   => $label,
				'type'    => $type,
				'url'     => ! empty( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
				'enabled' => ! isset( $item['enabled'] ) || (bool) $item['enabled'],
			);
		}

		return array_values( $normalized );
	}


	private function get_customer_portal_services_display_settings() {
		$settings = get_option( 'ajcore_customer_portal_services_display', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$selected_price_ids = isset( $settings['selected_price_ids'] ) && is_array( $settings['selected_price_ids'] ) ? array_map( 'sanitize_text_field', $settings['selected_price_ids'] ) : array();

		return array(
			'show_current_services' => ! isset( $settings['show_current_services'] ) || (bool) $settings['show_current_services'],
			'show_add_services'     => ! isset( $settings['show_add_services'] ) || (bool) $settings['show_add_services'],
			'product_mode'          => isset( $settings['product_mode'] ) && 'selected' === $settings['product_mode'] ? 'selected' : 'all',
			'selected_price_ids'    => array_values( array_filter( array_unique( $selected_price_ids ) ) ),
		);
	}


	private function get_portal_product_duplicate_behavior_options() {
		return array(
			'no_duplicates'  => __( 'Do not allow duplicate', 'ajforms' ),
			'allow_duplicate' => __( 'Allow duplicate', 'ajforms' ),
			'custom_request'  => __( 'Show custom request if already owned', 'ajforms' ),
			'upgrade'         => __( 'Upgrade existing service', 'ajforms' ),
		);
	}

	private function get_portal_stripe_products_for_settings() {
		global $wpdb;

		$this->ensure_portal_schema();

		return $wpdb->get_results( "SELECT * FROM {$this->get_portal_stripe_products_table()} ORDER BY sort_order ASC, name ASC" );
	}

	private function handle_client_portal_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->ensure_portal_schema();

		if ( isset( $_POST['ajcore_portal_master_reset_nonce'] ) ) {
			check_admin_referer( 'ajcore_portal_master_reset', 'ajcore_portal_master_reset_nonce' );

			$result = $this->reset_portal_sync_cache();
			$args   = array( 'page' => 'ajforms-client-portal', 'tab' => 'sync' );

			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-reset'] = absint( $result );
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_portal_sync_settings_nonce'] ) ) {
			check_admin_referer( 'ajcore_save_portal_sync_settings', 'ajcore_portal_sync_settings_nonce' );
			$jobs = isset( $_POST['sync_jobs'] ) && is_array( $_POST['sync_jobs'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['sync_jobs'] ) ) : array();
			$available_jobs = array_keys( $this->get_portal_sync_jobs() );
			$jobs = array_values( array_intersect( $jobs, $available_jobs ) );
			if ( empty( $jobs ) ) {
				$jobs = $available_jobs;
			}

			$frequencies = array_keys( $this->get_portal_sync_frequency_labels() );
			$frequency = isset( $_POST['sync_frequency'] ) ? sanitize_key( wp_unslash( $_POST['sync_frequency'] ) ) : 'daily';
			if ( ! in_array( $frequency, $frequencies, true ) ) {
				$frequency = 'daily';
			}

			update_option(
				'ajcore_portal_sync_settings',
				array(
					'enabled'   => ! empty( $_POST['sync_enabled'] ) ? 1 : 0,
					'frequency' => $frequency,
					'jobs'      => $jobs,
				),
				false
			);

			wp_clear_scheduled_hook( 'ajcore_portal_stripe_sync' );
			if ( ! empty( $_POST['sync_enabled'] ) ) {
				wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, $frequency, 'ajcore_portal_stripe_sync' );
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync', 'portal-sync-settings' => 'saved' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_mark_service_used_nonce'], $_POST['service_snapshot_key'] ) ) {
			check_admin_referer( 'ajcore_mark_service_used', 'ajcore_mark_service_used_nonce' );

			global $wpdb;
			$snapshot_key = sanitize_text_field( wp_unslash( $_POST['service_snapshot_key'] ) );
			if ( '' !== $snapshot_key ) {
				$wpdb->update(
					$this->get_portal_service_snapshots_table(),
					array(
						'status'     => 'used',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'snapshot_key' => $snapshot_key ),
					array( '%s', '%s' ),
					array( '%s' )
				);
				$this->log_portal_event(
					'service_marked_used',
					array(
						'source'   => 'admin_sold_items',
						'severity' => 'info',
						'details'  => array( 'snapshot_key' => $snapshot_key ),
					)
				);
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sold-items', 'service-used' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_GET['portal_action'], $_GET['_wpnonce'] ) ) {
			$action     = sanitize_key( wp_unslash( $_GET['portal_action'] ) );
			$secret_key = $this->get_stripe_secret_key_for_portal();
			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'menu';
			$current_tab = in_array( $current_tab, array( 'sync', 'menu', 'portal-users', 'sold-items', 'products-services', 'tasks' ), true ) ? $current_tab : 'menu';
			$args        = array( 'page' => 'ajforms-client-portal', 'tab' => $current_tab );

			if ( '' === $secret_key ) {
				$args['portal-error'] = rawurlencode( __( 'Stripe secret key is required.', 'ajforms' ) );
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}

			check_admin_referer( 'ajcore_portal_' . $action );

			$result = null;
			if ( 'sync_all' === $action ) {
				$result = $this->run_portal_sync_job( 'manual' );
			} elseif ( 'sync_products' === $action ) {
				$result = $this->run_portal_sync_job( 'manual', array( 'products' ) );
			} elseif ( 'sync_customers' === $action ) {
				$result = $this->run_portal_sync_job( 'manual', array( 'customers' ) );
			} elseif ( 'sync_subscriptions' === $action ) {
				$result = $this->run_portal_sync_job( 'manual', array( 'subscriptions' ) );
			} elseif ( 'sync_transactions' === $action ) {
				$result = $this->run_portal_sync_job( 'manual', array( 'transactions' ) );
			}

			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				delete_option( 'ajforms_last_portal_db_error' );
				$args['portal-synced'] = absint( $result );
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_enable_portal_customer_nonce'], $_POST['stripe_customer_id'] ) ) {
			check_admin_referer( 'ajcore_enable_portal_customer', 'ajcore_enable_portal_customer_nonce' );
			$result = $this->enable_stripe_customer_as_portal_user( sanitize_text_field( wp_unslash( $_POST['stripe_customer_id'] ) ) );
			$redirect_tab = isset( $_POST['redirect_tab'] ) ? sanitize_key( wp_unslash( $_POST['redirect_tab'] ) ) : 'portal-users';
			$redirect_tab = in_array( $redirect_tab, array( 'sync', 'menu', 'portal-users', 'sold-items', 'products-services', 'tasks' ), true ) ? $redirect_tab : 'portal-users';
			$args         = array( 'page' => 'ajforms-client-portal', 'tab' => $redirect_tab );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-user-enabled'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_create_stripe_customer_nonce'] ) ) {
			check_admin_referer( 'ajcore_create_stripe_customer', 'ajcore_create_stripe_customer_nonce' );

			$secret_key = $this->get_stripe_secret_key_for_portal();
			$args       = array( 'page' => 'ajforms-client-portal', 'tab' => 'portal-users' );
			if ( '' === $secret_key ) {
				$args['portal-error'] = rawurlencode( __( 'Stripe secret key is required.', 'ajforms' ) );
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}

			$email         = isset( $_POST['stripe_customer_email'] ) ? sanitize_email( wp_unslash( $_POST['stripe_customer_email'] ) ) : '';
			$name          = isset( $_POST['stripe_customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_customer_name'] ) ) : '';
			$phone         = isset( $_POST['stripe_customer_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_customer_phone'] ) ) : '';
			$business_name = isset( $_POST['stripe_customer_business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_customer_business_name'] ) ) : '';
			$description   = isset( $_POST['stripe_customer_description'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_customer_description'] ) ) : '';

			if ( ! is_email( $email ) ) {
				$args['portal-error'] = rawurlencode( __( 'A valid customer email is required.', 'ajforms' ) );
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}

			$body = array(
				'email' => $email,
			);
			if ( '' !== $name || '' !== $business_name ) {
				$body['name'] = '' !== $name ? $name : $business_name;
			}
			if ( '' !== $phone ) {
				$body['phone'] = $phone;
			}
			if ( '' !== $description ) {
				$body['description'] = $description;
			}
			if ( '' !== $business_name ) {
				$body['metadata[business_name]'] = $business_name;
			}

			$result = $this->stripe_api_request( 'customers', $secret_key, $body );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} elseif ( empty( $result['id'] ) ) {
				$args['portal-error'] = rawurlencode( __( 'Stripe did not return a customer ID.', 'ajforms' ) );
			} else {
				$sync_result = $this->sync_single_portal_stripe_customer( $secret_key, sanitize_text_field( (string) $result['id'] ) );
				if ( is_wp_error( $sync_result ) ) {
					$args['portal-error'] = rawurlencode( $sync_result->get_error_message() );
				} else {
					$args['portal-customer-created'] = sanitize_text_field( (string) $result['id'] );
				}
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_repair_portal_user_links_nonce'] ) ) {
			check_admin_referer( 'ajcore_repair_portal_user_links', 'ajcore_repair_portal_user_links_nonce' );
			$stats = $this->repair_portal_user_links_and_roles( true, true, false );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                  => 'ajforms-client-portal',
						'tab'                   => 'portal-users',
						'portal-links-repaired' => 1,
						'portal-cleared'        => absint( $stats['cleared'] ),
						'portal-relinked'       => absint( $stats['relinked'] ),
						'portal-roles'          => absint( $stats['roles'] ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( isset( $_POST['ajcore_portal_bulk_user_nonce'], $_POST['portal_bulk_action'] ) ) {
			check_admin_referer( 'ajcore_portal_bulk_user_action', 'ajcore_portal_bulk_user_nonce' );
			global $wpdb;

			$action = sanitize_key( wp_unslash( $_POST['portal_bulk_action'] ) );
			$selected_values = isset( $_POST['portal_customer_ids'] ) && is_array( $_POST['portal_customer_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['portal_customer_ids'] ) ) : array();
			$selected_ids    = array();
			foreach ( $selected_values as $selected_value ) {
				$parts = explode( '|', (string) $selected_value );
				$selected_ids[] = sanitize_text_field( (string) $parts[0] );
			}
			$selected_ids = array_values( array_filter( array_unique( $selected_ids ) ) );
			$allowed_actions = array( 'enable', 'enable_repair', 'disable', 'archive', 'restore', 'reset_password', 'send_welcome', 'delete_archived' );
			$args = array( 'page' => 'ajforms-client-portal', 'tab' => 'portal-users' );
			$updated = 0;
			$skipped = 0;
			$errors = array();

			if ( empty( $selected_ids ) || ! in_array( $action, $allowed_actions, true ) ) {
				$args['portal-error'] = rawurlencode( __( 'Select at least one customer and a valid bulk action.', 'ajforms' ) );
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}

			if ( 'enable_repair' === $action ) {
				$stats = $this->repair_portal_user_links_and_roles( true, true, true, $selected_ids );
				$args['portal-bulk-updated'] = absint( $stats['relinked'] );
				$args['portal-bulk-skipped'] = absint( $stats['skipped'] );
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}

			foreach ( $selected_ids as $stripe_customer_id ) {
				$customer = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT c.*, m.user_id, m.customer_email, m.portal_user_email, m.site_uuid AS mapping_site_uuid FROM {$this->get_portal_stripe_customers_table()} c LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id WHERE c.stripe_customer_id = %s LIMIT 1",
						$stripe_customer_id
					)
				);
				if ( ! $customer ) {
					$skipped++;
					continue;
				}

				$status = ! empty( $customer->portal_status ) ? sanitize_key( (string) $customer->portal_status ) : ( ! empty( $customer->enabled_portal ) ? 'active' : 'disabled' );
				$is_active = 'active' === $status && ! empty( $customer->enabled_portal );
				$is_disabled = 'disabled' === $status || ( ! $is_active && 'archived' !== $status );
				$is_archived = 'archived' === $status;
				$result = true;

				if ( 'delete_archived' === $action ) {
					if ( ! $is_archived ) {
						$skipped++;
						continue;
					}

					$wpdb->delete(
						$this->get_portal_user_mappings_table(),
						array( 'stripe_customer_id' => $stripe_customer_id ),
						array( '%s' )
					);
					$result = $wpdb->delete(
						$this->get_portal_stripe_customers_table(),
						array( 'stripe_customer_id' => $stripe_customer_id ),
						array( '%s' )
					);
					$this->record_portal_sync_item(
						'deleted',
						'portal_customer',
						$stripe_customer_id,
						false === $result ? 'failed' : 'success',
						false === $result ? __( 'Archived portal customer delete failed.', 'ajforms' ) : __( 'Archived portal customer deleted from local cache.', 'ajforms' ),
						array(),
						$stripe_customer_id
					);
				} elseif ( 'disable' === $action && $is_active ) {
					$result = $this->disable_stripe_customer_portal_access( $stripe_customer_id );
				} elseif ( 'archive' === $action && ( $is_active || $is_disabled ) ) {
					$result = $this->disable_stripe_customer_portal_access( $stripe_customer_id, 'archived' );
				} elseif ( 'enable' === $action && $is_disabled ) {
					$result = $this->enable_stripe_customer_as_portal_user( $stripe_customer_id );
				} elseif ( 'restore' === $action && $is_archived ) {
					$result = $this->enable_stripe_customer_as_portal_user( $stripe_customer_id );
				} elseif ( 'reset_password' === $action && ! empty( $customer->user_id ) ) {
					$valid_user = $this->get_valid_portal_mapping_user( $customer, $customer );
					if ( ! $valid_user ) {
						$skipped++;
						continue;
					}
					$result = $this->send_portal_user_password_reset( (int) $valid_user->ID );
					if ( false === $result ) {
						$result = new WP_Error( 'reset_failed', __( 'Password reset email could not be sent.', 'ajforms' ) );
					}
				} elseif ( 'send_welcome' === $action && ! empty( $customer->user_id ) ) {
					$valid_user = $this->get_valid_portal_mapping_user( $customer, $customer );
					if ( ! $valid_user || ! $is_active ) {
						$skipped++;
						continue;
					}
					$result = $this->send_portal_user_welcome_email( (int) $valid_user->ID );
					if ( false === $result ) {
						$result = new WP_Error( 'welcome_failed', __( 'Welcome email could not be sent.', 'ajforms' ) );
					}
				} else {
					$skipped++;
					continue;
				}

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
					$skipped++;
				} else {
					$updated++;
				}
			}

			if ( ! empty( $errors ) ) {
				$args['portal-error'] = rawurlencode( implode( ' ', array_unique( $errors ) ) );
			}
			if ( 'delete_archived' === $action ) {
				$args['portal-users-deleted'] = $updated;
			} else {
				$args['portal-bulk-updated'] = $updated;
			}
			$args['portal-bulk-skipped'] = $skipped;

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_portal_user_action_nonce'], $_POST['stripe_customer_id'], $_POST['portal_user_action'] ) ) {
			$stripe_customer_id = sanitize_text_field( wp_unslash( $_POST['stripe_customer_id'] ) );
			check_admin_referer( 'ajcore_portal_user_action_' . $stripe_customer_id, 'ajcore_portal_user_action_nonce' );

			$action = sanitize_key( wp_unslash( $_POST['portal_user_action'] ) );
			$args   = array( 'page' => 'ajforms-client-portal', 'tab' => 'portal-users' );

			if ( 'restore' === $action || 'enable' === $action ) {
				$result = $this->enable_stripe_customer_as_portal_user( $stripe_customer_id );
				if ( is_wp_error( $result ) ) {
					$args['portal-error'] = rawurlencode( $result->get_error_message() );
				} else {
					$args['portal-user-enabled'] = 1;
				}
			} elseif ( 'disable' === $action ) {
				$result = $this->disable_stripe_customer_portal_access( $stripe_customer_id );
				if ( is_wp_error( $result ) ) {
					$args['portal-error'] = rawurlencode( $result->get_error_message() );
				} else {
					$args['portal-user-disabled'] = 1;
				}
			} elseif ( 'archive' === $action ) {
				$result = $this->disable_stripe_customer_portal_access( $stripe_customer_id, 'archived' );
				if ( is_wp_error( $result ) ) {
					$args['portal-error'] = rawurlencode( $result->get_error_message() );
				} else {
					$args['portal-user-archived'] = 1;
				}
			} elseif ( 'reset_password' === $action ) {
				global $wpdb;
				$customer = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT c.*, m.user_id, m.customer_email, m.portal_user_email, m.site_uuid AS mapping_site_uuid FROM {$this->get_portal_stripe_customers_table()} c LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id WHERE c.stripe_customer_id = %s LIMIT 1",
						$stripe_customer_id
					)
				);
				$user = $customer ? $this->get_valid_portal_mapping_user( $customer, $customer ) : null;
				$result = $user ? $this->send_portal_user_password_reset( (int) $user->ID ) : new WP_Error( 'invalid_link', __( 'The linked WordPress user does not match this customer email. Run Repair WP User Links & Roles first.', 'ajforms' ) );
				if ( is_wp_error( $result ) ) {
					$args['portal-error'] = rawurlencode( $result->get_error_message() );
				} elseif ( ! $result ) {
					$args['portal-error'] = rawurlencode( __( 'Password reset email could not be sent.', 'ajforms' ) );
				} else {
					$args['portal-password-reset'] = 1;
				}
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_portal_customer_fields_nonce'] ) ) {
			check_admin_referer( 'ajcore_save_portal_customer_fields', 'ajcore_portal_customer_fields_nonce' );

			$fields = isset( $_POST['portal_customer_display_fields'] ) && is_array( $_POST['portal_customer_display_fields'] ) ? wp_unslash( $_POST['portal_customer_display_fields'] ) : array();
			$fields = $this->sanitize_portal_display_fields( $fields );

			update_option( 'ajcore_portal_customer_display_fields', $fields, false );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                  => 'ajforms-client-portal',
						'tab'                   => 'portal-users',
						'portal-fields-updated' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( isset( $_POST['ajcore_portal_products_nonce'] ) ) {
			check_admin_referer( 'ajcore_save_portal_products', 'ajcore_portal_products_nonce' );

			global $wpdb;
			$product_group_rows = isset( $_POST['portal_product_groups'] ) && is_array( $_POST['portal_product_groups'] ) ? wp_unslash( $_POST['portal_product_groups'] ) : array();
			if ( ! empty( $product_group_rows ) ) {
				foreach ( $product_group_rows as $group_key => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}

					$row_ids = isset( $row['row_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( $row['row_ids'] ) ) ) ) : array();
					if ( empty( $row_ids ) ) {
						continue;
					}

					$visibility = isset( $row['visibility'] ) && 'hidden' === sanitize_key( $row['visibility'] ) ? 'hidden' : 'visible';
					$duplicate_options = $this->get_portal_product_duplicate_behavior_options();
					$duplicate_behavior = isset( $row['duplicate_behavior'] ) ? sanitize_key( $row['duplicate_behavior'] ) : 'no_duplicates';
					if ( ! isset( $duplicate_options[ $duplicate_behavior ] ) ) {
						$duplicate_behavior = 'no_duplicates';
					}
					$upgrade_from_product_id = isset( $row['upgrade_from_product_id'] ) ? sanitize_text_field( $row['upgrade_from_product_id'] ) : '';
					if ( 'upgrade' !== $duplicate_behavior || 0 !== strpos( $upgrade_from_product_id, 'prod_' ) ) {
						$upgrade_from_product_id = '';
					}

					foreach ( $row_ids as $product_id ) {
						$current_product = $wpdb->get_row(
							$wpdb->prepare(
								"SELECT stripe_product_id FROM {$this->get_portal_stripe_products_table()} WHERE id = %d",
								$product_id
							)
						);
						$row_upgrade_from_product_id = $upgrade_from_product_id;
						if ( $current_product && $row_upgrade_from_product_id === (string) $current_product->stripe_product_id ) {
							$row_upgrade_from_product_id = '';
						}
						$wpdb->update(
							$this->get_portal_stripe_products_table(),
							array(
								'visibility'                  => $visibility,
								'custom_label'                => isset( $row['custom_label'] ) ? sanitize_text_field( $row['custom_label'] ) : '',
								'description_override'        => isset( $row['description_override'] ) ? sanitize_textarea_field( $row['description_override'] ) : '',
								'sort_order'                  => isset( $row['sort_order'] ) ? intval( $row['sort_order'] ) : 0,
								'duplicate_behavior'          => $duplicate_behavior,
								'upgrade_from_product_id'     => $row_upgrade_from_product_id,
								'custom_request_title'        => isset( $row['custom_request_title'] ) ? sanitize_text_field( $row['custom_request_title'] ) : '',
								'custom_request_message'      => isset( $row['custom_request_message'] ) ? sanitize_textarea_field( $row['custom_request_message'] ) : '',
								'custom_request_button_label' => isset( $row['custom_request_button_label'] ) ? sanitize_text_field( $row['custom_request_button_label'] ) : '',
							),
							array( 'id' => $product_id ),
							array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
							array( '%d' )
						);
					}
				}
			}

			$product_rows = isset( $_POST['portal_products'] ) && is_array( $_POST['portal_products'] ) ? wp_unslash( $_POST['portal_products'] ) : array();
			foreach ( $product_rows as $product_id => $row ) {
				$product_id = absint( $product_id );
				if ( ! $product_id || ! is_array( $row ) ) {
					continue;
				}

				$visibility = isset( $row['visibility'] ) && 'hidden' === sanitize_key( $row['visibility'] ) ? 'hidden' : 'visible';
				$duplicate_options = $this->get_portal_product_duplicate_behavior_options();
				$duplicate_behavior = isset( $row['duplicate_behavior'] ) ? sanitize_key( $row['duplicate_behavior'] ) : 'no_duplicates';
				if ( ! isset( $duplicate_options[ $duplicate_behavior ] ) ) {
					$duplicate_behavior = 'no_duplicates';
				}
				$upgrade_from_product_id = isset( $row['upgrade_from_product_id'] ) ? sanitize_text_field( $row['upgrade_from_product_id'] ) : '';
				if ( 'upgrade' !== $duplicate_behavior || 0 !== strpos( $upgrade_from_product_id, 'prod_' ) ) {
					$upgrade_from_product_id = '';
				}
				$current_product = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT stripe_product_id FROM {$this->get_portal_stripe_products_table()} WHERE id = %d",
						$product_id
					)
				);
				if ( $current_product && $upgrade_from_product_id === (string) $current_product->stripe_product_id ) {
					$upgrade_from_product_id = '';
				}

				$wpdb->update(
					$this->get_portal_stripe_products_table(),
					array(
						'visibility'                  => $visibility,
						'custom_label'                => isset( $row['custom_label'] ) ? sanitize_text_field( $row['custom_label'] ) : '',
						'description_override'        => isset( $row['description_override'] ) ? sanitize_textarea_field( $row['description_override'] ) : '',
						'sort_order'                  => isset( $row['sort_order'] ) ? intval( $row['sort_order'] ) : 0,
						'duplicate_behavior'          => $duplicate_behavior,
						'upgrade_from_product_id'     => $upgrade_from_product_id,
						'custom_request_title'        => isset( $row['custom_request_title'] ) ? sanitize_text_field( $row['custom_request_title'] ) : '',
						'custom_request_message'      => isset( $row['custom_request_message'] ) ? sanitize_textarea_field( $row['custom_request_message'] ) : '',
						'custom_request_button_label' => isset( $row['custom_request_button_label'] ) ? sanitize_text_field( $row['custom_request_button_label'] ) : '',
					),
					array( 'id' => $product_id ),
					array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			}

			$dependency_rows = isset( $_POST['portal_product_dependencies'] ) && is_array( $_POST['portal_product_dependencies'] ) ? wp_unslash( $_POST['portal_product_dependencies'] ) : array();
			if ( ! empty( $dependency_rows ) ) {
				$dependency_settings = $this->get_public_product_dependency_settings();
				foreach ( $dependency_rows as $dependency_price_id => $dependency_row ) {
					$dependency_price_id = sanitize_text_field( (string) $dependency_price_id );
					if ( '' === $dependency_price_id || ! is_array( $dependency_row ) ) {
						continue;
					}

					$required_price_id = isset( $dependency_row['requires_price_id'] ) ? sanitize_text_field( $dependency_row['requires_price_id'] ) : '';
					$dependency_note   = isset( $dependency_row['dependency_note'] ) ? sanitize_textarea_field( $dependency_row['dependency_note'] ) : '';
					if ( $required_price_id === $dependency_price_id ) {
						$required_price_id = '';
					}
					if ( '' === $required_price_id && '' === $dependency_note ) {
						unset( $dependency_settings[ $dependency_price_id ] );
						continue;
					}

					$dependency_settings[ $dependency_price_id ] = array(
						'requires_price_id' => $required_price_id,
						'dependency_note'   => $dependency_note,
					);
				}
				$this->save_public_product_dependency_settings( $dependency_settings );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => 'ajforms-client-portal',
						'tab'             => 'products-services',
						'portal-products' => 'saved',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( isset( $_POST['ajcore_portal_task_bulk_nonce'] ) ) {
			check_admin_referer( 'ajcore_bulk_portal_tasks', 'ajcore_portal_task_bulk_nonce' );

			global $wpdb;
			$task_ids = isset( $_POST['portal_task_ids'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['portal_task_ids'] ) ) ) ) : array();
			$bulk_action = isset( $_POST['portal_task_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['portal_task_bulk_action'] ) ) : '';
			$allowed_statuses = array( 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled' );
			$redirect_args = array(
				'page'        => 'ajforms-client-portal',
				'tab'         => 'tasks',
				'portal-task' => 'bulk',
			);

			if ( ! empty( $task_ids ) && current_user_can( 'manage_options' ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $task_ids ), '%d' ) );

				if ( 'delete' === $bulk_action ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->get_portal_task_comments_table()} WHERE task_id IN ({$placeholders})", $task_ids ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->get_portal_task_statuses_table()} WHERE task_id IN ({$placeholders})", $task_ids ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->get_portal_tasks_table()} WHERE id IN ({$placeholders})", $task_ids ) );
				} elseif ( 'mark_completed' === $bulk_action ) {
					$params = array_merge( array( 'completed', current_time( 'mysql' ) ), $task_ids );
					$wpdb->query( $wpdb->prepare( "UPDATE {$this->get_portal_tasks_table()} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})", $params ) );
				} elseif ( 'mark_open' === $bulk_action ) {
					$params = array_merge( array( 'open', current_time( 'mysql' ) ), $task_ids );
					$wpdb->query( $wpdb->prepare( "UPDATE {$this->get_portal_tasks_table()} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})", $params ) );
				} elseif ( 'bulk_update' === $bulk_action ) {
					$updates = array();
					$formats = array();

					$bulk_status = isset( $_POST['bulk_task_status'] ) ? sanitize_key( wp_unslash( $_POST['bulk_task_status'] ) ) : '';
					if ( '' !== $bulk_status && in_array( $bulk_status, $allowed_statuses, true ) ) {
						$updates['status'] = $bulk_status;
						$formats[] = '%s';
					}

					$bulk_due_date = isset( $_POST['bulk_task_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_task_due_date'] ) ) : '';
					if ( '' !== $bulk_due_date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bulk_due_date ) ) {
						$updates['due_date'] = $bulk_due_date;
						$formats[] = '%s';
					}

					$bulk_visibility = isset( $_POST['bulk_task_visibility'] ) ? sanitize_key( wp_unslash( $_POST['bulk_task_visibility'] ) ) : '';
					if ( in_array( $bulk_visibility, array( 'visible', 'hidden' ), true ) ) {
						$updates['client_visible'] = 'visible' === $bulk_visibility ? 1 : 0;
						$formats[] = '%d';
					}

					if ( ! empty( $updates ) ) {
						$updates['updated_at'] = current_time( 'mysql' );
						$formats[] = '%s';
						foreach ( $task_ids as $bulk_task_id ) {
							$wpdb->update( $this->get_portal_tasks_table(), $updates, array( 'id' => absint( $bulk_task_id ) ), $formats, array( '%d' ) );
						}
					}
				}
			}

			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_portal_task_nonce'] ) ) {
			check_admin_referer( 'ajcore_save_portal_task', 'ajcore_portal_task_nonce' );

			global $wpdb;
			$task_id            = isset( $_POST['portal_task_id'] ) ? absint( wp_unslash( $_POST['portal_task_id'] ) ) : 0;
			$task_scope         = isset( $_POST['task_scope'] ) ? sanitize_key( wp_unslash( $_POST['task_scope'] ) ) : 'client';
			$task_frequency     = isset( $_POST['task_frequency'] ) ? sanitize_key( wp_unslash( $_POST['task_frequency'] ) ) : 'one_time';
			$stripe_customer_id = isset( $_POST['task_stripe_customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_stripe_customer_id'] ) ) : '';
			$title              = isset( $_POST['task_title'] ) ? sanitize_text_field( wp_unslash( $_POST['task_title'] ) ) : '';
			$status             = isset( $_POST['task_status'] ) ? sanitize_key( wp_unslash( $_POST['task_status'] ) ) : 'open';
			$due_date           = isset( $_POST['task_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['task_due_date'] ) ) : '';
			$action_required    = isset( $_POST['task_action_required'] ) ? sanitize_textarea_field( wp_unslash( $_POST['task_action_required'] ) ) : '';
			$client_visible     = isset( $_POST['task_client_visible'] ) ? 1 : 0;
			$allowed_statuses   = array( 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled' );
			$task_scope         = in_array( $task_scope, array( 'global', 'client' ), true ) ? $task_scope : 'client';
			$task_frequency     = in_array( $task_frequency, array( 'one_time', 'recurring' ), true ) ? $task_frequency : 'one_time';
			$status             = in_array( $status, $allowed_statuses, true ) ? $status : 'open';
			$due_date           = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due_date ) ? $due_date : null;

			if ( 'global' === $task_scope ) {
				$stripe_customer_id = '';
			}

			if ( '' !== $title && ( 'global' === $task_scope || '' !== $stripe_customer_id ) ) {
				$data = array(
					'stripe_customer_id' => $stripe_customer_id,
					'task_scope'         => $task_scope,
					'task_frequency'     => $task_frequency,
					'title'              => $title,
					'status'             => $status,
					'due_date'           => $due_date,
					'action_required'    => $action_required,
					'client_visible'     => $client_visible,
					'created_by'         => get_current_user_id(),
					'updated_at'         => current_time( 'mysql' ),
				);
				$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );
				if ( $task_id ) {
					$wpdb->update( $this->get_portal_tasks_table(), $data, array( 'id' => $task_id ), $formats, array( '%d' ) );
				} else {
					$wpdb->insert( $this->get_portal_tasks_table(), $data, $formats );
				}
			}

			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'tasks', 'portal-task' => 'saved' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_GET['portal_task_action'], $_GET['task_id'], $_GET['_wpnonce'] ) ) {
			$task_id = absint( wp_unslash( $_GET['task_id'] ) );
			check_admin_referer( 'ajcore_delete_portal_task_' . $task_id );
			if ( 'delete' === sanitize_key( wp_unslash( $_GET['portal_task_action'] ) ) ) {
				global $wpdb;
				$wpdb->delete( $this->get_portal_task_comments_table(), array( 'task_id' => $task_id ), array( '%d' ) );
				$wpdb->delete( $this->get_portal_task_statuses_table(), array( 'task_id' => $task_id ), array( '%d' ) );
				$wpdb->delete( $this->get_portal_tasks_table(), array( 'id' => $task_id ), array( '%d' ) );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'tasks', 'portal-task' => 'deleted' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_POST['ajcore_client_portal_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajcore_save_client_portal', 'ajcore_client_portal_nonce' );

		$services_display = array(
			'show_current_services' => isset( $_POST['portal_services_show_current'] ),
			'show_add_services'     => isset( $_POST['portal_services_show_add'] ),
			'product_mode'          => isset( $_POST['portal_services_product_mode'] ) && 'selected' === sanitize_key( wp_unslash( $_POST['portal_services_product_mode'] ) ) ? 'selected' : 'all',
			'selected_price_ids'    => isset( $_POST['portal_services_selected_prices'] ) && is_array( $_POST['portal_services_selected_prices'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['portal_services_selected_prices'] ) ) ) ) : array(),
		);
		update_option( 'ajcore_customer_portal_services_display', $services_display, false );

		$labels  = isset( $_POST['portal_menu_label'] ) && is_array( $_POST['portal_menu_label'] ) ? wp_unslash( $_POST['portal_menu_label'] ) : array();
		$urls    = isset( $_POST['portal_menu_url'] ) && is_array( $_POST['portal_menu_url'] ) ? wp_unslash( $_POST['portal_menu_url'] ) : array();
		$types   = isset( $_POST['portal_menu_type'] ) && is_array( $_POST['portal_menu_type'] ) ? wp_unslash( $_POST['portal_menu_type'] ) : array();
		$enabled = isset( $_POST['portal_menu_enabled'] ) && is_array( $_POST['portal_menu_enabled'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['portal_menu_enabled'] ) ) : array();

		$items = array();
		foreach ( $labels as $id => $label ) {
			$id    = sanitize_key( $id );
			$label = sanitize_text_field( $label );
			$type  = isset( $types[ $id ] ) && 'custom' === sanitize_key( $types[ $id ] ) ? 'custom' : 'built_in';
			$url   = isset( $urls[ $id ] ) ? esc_url_raw( $urls[ $id ] ) : '';

			if ( '' === $id || '' === $label ) {
				continue;
			}

			$items[] = array(
				'id'      => $id,
				'label'   => $label,
				'type'    => $type,
				'url'     => $url,
				'enabled' => in_array( $id, $enabled, true ),
			);
		}

		$new_label       = isset( $_POST['new_portal_menu_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_portal_menu_label'] ) ) : '';
		$new_url         = isset( $_POST['new_portal_menu_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_portal_menu_url'] ) ) : '';
		$built_in_labels = array( 'overview', 'my services', 'tasks', 'billing', 'file library', 'profile' );
		if ( '' !== $new_label && '' !== $new_url && ! in_array( strtolower( $new_label ), $built_in_labels, true ) ) {
			$items[] = array(
				'id'      => 'custom-' . wp_generate_uuid4(),
				'label'   => $new_label,
				'type'    => 'custom',
				'url'     => $new_url,
				'enabled' => true,
			);
		}

		update_option( 'ajcore_customer_portal_menu_items', $items, false );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'ajforms-client-portal',
					'tab'            => 'menu',
					'portal-updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_portal_customer_detail_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stripe_customer_id = '';
		if ( isset( $_REQUEST['stripe_customer_id'] ) ) {
			$stripe_customer_id = sanitize_text_field( wp_unslash( $_REQUEST['stripe_customer_id'] ) );
		}

		if ( '' === $stripe_customer_id ) {
			return;
		}

		$redirect_args = array(
			'page'               => 'ajforms-client-portal',
			'tab'                => 'customer',
			'stripe_customer_id' => $stripe_customer_id,
		);

		if ( isset( $_GET['customer_action'], $_GET['_wpnonce'] ) && 'sync_customer' === sanitize_key( wp_unslash( $_GET['customer_action'] ) ) ) {
			check_admin_referer( 'ajcore_portal_customer_sync_' . $stripe_customer_id );

			$secret_key = $this->get_stripe_secret_key_for_portal();
			if ( '' === $secret_key ) {
				$redirect_args['portal-error'] = rawurlencode( __( 'Stripe secret key is required.', 'ajforms' ) );
			} else {
				$result = $this->sync_single_portal_stripe_customer( $secret_key, $stripe_customer_id );
				if ( is_wp_error( $result ) ) {
					$redirect_args['portal-error'] = rawurlencode( $result->get_error_message() );
				} else {
					$redirect_args['portal-synced'] = absint( $result );
				}
			}

			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_customer_access_nonce'], $_POST['portal_customer_action'] ) ) {
			check_admin_referer( 'ajcore_customer_access_' . $stripe_customer_id, 'ajcore_customer_access_nonce' );

			$action = sanitize_key( wp_unslash( $_POST['portal_customer_action'] ) );
			if ( 'enable' === $action ) {
				$result = $this->enable_stripe_customer_as_portal_user( $stripe_customer_id );
			} elseif ( 'disable' === $action ) {
				$result = $this->disable_stripe_customer_portal_access( $stripe_customer_id );
			} elseif ( 'archive' === $action ) {
				$result = $this->disable_stripe_customer_portal_access( $stripe_customer_id, 'archived' );
			} else {
				$result = new WP_Error( 'invalid_action', __( 'Invalid portal customer action.', 'ajforms' ) );
			}

			if ( is_wp_error( $result ) ) {
				$redirect_args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$redirect_args['portal-updated'] = 1;
			}

			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_customer_relink_nonce'], $_POST['wp_user_email'] ) ) {
			check_admin_referer( 'ajcore_customer_relink_' . $stripe_customer_id, 'ajcore_customer_relink_nonce' );

			$result = $this->relink_stripe_customer_to_user_email(
				$stripe_customer_id,
				sanitize_email( wp_unslash( $_POST['wp_user_email'] ) )
			);

			if ( is_wp_error( $result ) ) {
				$redirect_args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$redirect_args['portal-updated'] = 1;
			}

			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_portal_detail_fields_nonce'], $_POST['detail_field_section'] ) ) {
			$section = sanitize_key( wp_unslash( $_POST['detail_field_section'] ) );
			if ( ! in_array( $section, array( 'subscriptions', 'active_recurring_services', 'one_time_services', 'products', 'ledger', 'upcoming' ), true ) ) {
				return;
			}

			check_admin_referer( 'ajcore_save_portal_detail_fields_' . $section, 'ajcore_portal_detail_fields_nonce' );

			$fields = isset( $_POST['portal_detail_display_fields'] ) && is_array( $_POST['portal_detail_display_fields'] ) ? wp_unslash( $_POST['portal_detail_display_fields'] ) : array();
			update_option( 'ajcore_portal_detail_display_fields_' . $section, $this->sanitize_portal_display_fields( $fields ), false );

			$redirect_args['portal-fields-updated'] = 1;
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	private function handle_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['ajforms_settings_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajforms_save_settings', 'ajforms_settings_nonce' );

		$section    = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'general';
		$subsection = isset( $_GET['subsection'] ) ? sanitize_key( wp_unslash( $_GET['subsection'] ) ) : '';
		$current_settings = $this->get_plugin_settings();

		$settings = array(
			'default_notification_email'     => isset( $_POST['default_notification_email'] ) ? sanitize_text_field( wp_unslash( $_POST['default_notification_email'] ) ) : get_option( 'admin_email' ),
			'default_notification_subject'   => isset( $_POST['default_notification_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['default_notification_subject'] ) ) : 'New submission for {form_title}',
			'default_notifications_enabled'  => isset( $_POST['default_notifications_enabled'] ) ? '1' : '0',
			'default_from_name'              => isset( $_POST['default_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['default_from_name'] ) ) : get_bloginfo( 'name' ),
			'default_reply_to_mode'          => isset( $_POST['default_reply_to_mode'] ) && in_array( sanitize_key( wp_unslash( $_POST['default_reply_to_mode'] ) ), array( 'submitter', 'site' ), true ) ? sanitize_key( wp_unslash( $_POST['default_reply_to_mode'] ) ) : 'submitter',
			'wp_email_templates_enabled'     => isset( $_POST['wp_email_templates_enabled'] ) ? '1' : '0',
			'wp_email_from_email'            => isset( $_POST['wp_email_from_email'] ) ? sanitize_email( wp_unslash( $_POST['wp_email_from_email'] ) ) : ( defined( 'AJCORE_SYSTEM_FROM_EMAIL' ) ? AJCORE_SYSTEM_FROM_EMAIL : 'donotreply@ncllcagents.com' ),
			'wp_email_from_name'             => isset( $_POST['wp_email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_email_from_name'] ) ) : get_bloginfo( 'name' ),
			'wp_password_reset_subject'      => isset( $_POST['wp_password_reset_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_password_reset_subject'] ) ) : 'Password reset for your Portal Login for NC LLC Agents Inc',
			'wp_welcome_email_subject'       => isset( $_POST['wp_welcome_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['wp_welcome_email_subject'] ) ) : 'Welcome : Your portal access is enabled to NC LLC Agents Inc',
			'default_success_message'        => isset( $_POST['default_success_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['default_success_message'] ) ) : 'Form submitted successfully.',
			'validation_mode'                => 'native',
			'require_unique_form_names'      => '1',
			'honeypot_enabled'               => isset( $_POST['honeypot_enabled'] ) ? '1' : '0',
			'spam_challenge_provider'        => isset( $_POST['spam_challenge_provider'] ) && in_array( sanitize_key( wp_unslash( $_POST['spam_challenge_provider'] ) ), array( 'recaptcha', 'hcaptcha', 'turnstile' ), true ) ? sanitize_key( wp_unslash( $_POST['spam_challenge_provider'] ) ) : 'turnstile',
			'recaptcha_site_key'             => isset( $_POST['recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ) ) : '',
			'recaptcha_secret_key'           => isset( $_POST['recaptcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_secret_key'] ) ) : '',
			'hcaptcha_site_key'              => isset( $_POST['hcaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hcaptcha_site_key'] ) ) : '',
			'hcaptcha_secret_key'            => isset( $_POST['hcaptcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['hcaptcha_secret_key'] ) ) : '',
			'turnstile_site_key'             => isset( $_POST['turnstile_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ) ) : '',
			'turnstile_secret_key'           => isset( $_POST['turnstile_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_secret_key'] ) ) : '',
			'webhook_url'                    => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
			'asana_enabled'                  => isset( $_POST['asana_enabled'] ) ? '1' : '0',
			'asana_personal_access_token'    => isset( $_POST['asana_personal_access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['asana_personal_access_token'] ) ) : '',
			'asana_workspace_gid'            => isset( $_POST['asana_workspace_gid'] ) ? sanitize_text_field( wp_unslash( $_POST['asana_workspace_gid'] ) ) : '',
			'asana_project_gid'              => isset( $_POST['asana_project_gid'] ) ? sanitize_text_field( wp_unslash( $_POST['asana_project_gid'] ) ) : '',
			'stripe_mode'                    => isset( $_POST['stripe_mode'] ) && in_array( sanitize_key( wp_unslash( $_POST['stripe_mode'] ) ), array( 'test', 'live' ), true ) ? sanitize_key( wp_unslash( $_POST['stripe_mode'] ) ) : 'test',
			'stripe_sandbox_publishable_key'  => isset( $_POST['stripe_sandbox_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_sandbox_publishable_key'] ) ) : '',
			'stripe_sandbox_secret_key'       => isset( $_POST['stripe_sandbox_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_sandbox_secret_key'] ) ) : '',
			'stripe_live_publishable_key'     => isset( $_POST['stripe_live_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_live_publishable_key'] ) ) : '',
			'stripe_live_secret_key'          => isset( $_POST['stripe_live_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_live_secret_key'] ) ) : '',
			'stripe_publishable_key'         => '',
			'stripe_secret_key'              => '',
			'stripe_products_mode'           => isset( $_POST['stripe_products_mode'] ) && in_array( sanitize_key( wp_unslash( $_POST['stripe_products_mode'] ) ), array( 'all', 'selected' ), true ) ? sanitize_key( wp_unslash( $_POST['stripe_products_mode'] ) ) : 'all',
			'stripe_selected_prices'         => isset( $_POST['stripe_selected_prices'] ) && is_array( $_POST['stripe_selected_prices'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $_POST['stripe_selected_prices'] ) ) ) ) : array(),
		);

		$active_stripe_prefix = 'live' === $settings['stripe_mode'] ? 'stripe_live' : 'stripe_sandbox';
		$settings['stripe_publishable_key'] = isset( $settings[ $active_stripe_prefix . '_publishable_key' ] ) ? $settings[ $active_stripe_prefix . '_publishable_key' ] : '';
		$settings['stripe_secret_key']      = isset( $settings[ $active_stripe_prefix . '_secret_key' ] ) ? $settings[ $active_stripe_prefix . '_secret_key' ] : '';

		$section_keys = array(
			'general'      => array( 'default_notification_email', 'default_notification_subject', 'default_notifications_enabled', 'default_from_name', 'default_reply_to_mode', 'default_success_message', 'validation_mode', 'require_unique_form_names' ),
			'email-templates' => array( 'wp_email_templates_enabled', 'wp_email_from_email', 'wp_email_from_name', 'wp_password_reset_subject', 'wp_welcome_email_subject' ),
			'spam'         => array( 'honeypot_enabled', 'spam_challenge_provider', 'recaptcha_site_key', 'recaptcha_secret_key', 'hcaptcha_site_key', 'hcaptcha_secret_key', 'turnstile_site_key', 'turnstile_secret_key' ),
			'integrations' => array( 'webhook_url', 'asana_enabled', 'asana_personal_access_token', 'asana_workspace_gid', 'asana_project_gid' ),
			'payments'     => array( 'stripe_mode', 'stripe_sandbox_publishable_key', 'stripe_sandbox_secret_key', 'stripe_live_publishable_key', 'stripe_live_secret_key', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_products_mode', 'stripe_selected_prices' ),
		);

		foreach ( $section_keys as $section_key => $keys ) {
			if ( $section_key === $section ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $current_settings ) ) {
					$settings[ $key ] = $current_settings[ $key ];
				}
			}
		}

		update_option( 'ajforms_settings', $settings );
		if ( function_exists( 'ajforms_write_synced_settings_file' ) ) {
			ajforms_write_synced_settings_file( $settings );
		}

		if ( ! empty( $settings['asana_personal_access_token'] ) ) {
			$this->fetch_asana_reference_data(
				$settings['asana_personal_access_token'],
				$settings['asana_workspace_gid']
			);
		}

		$redirect_args = array(
			'page'             => 'ajforms-settings',
			'section'          => $section,
			'subsection'       => $subsection,
			'settings-updated' => 'true',
		);

		if ( 'payments' === $section && ! empty( $settings['stripe_secret_key'] ) && isset( $_POST['ajf_sync_stripe_products'] ) ) {
			$stripe_sync = $this->fetch_stripe_product_prices( $settings['stripe_secret_key'] );
			if ( is_wp_error( $stripe_sync ) ) {
				$redirect_args['stripe-sync-error'] = rawurlencode( $stripe_sync->get_error_message() );
			} else {
				$redirect_args['stripe-synced'] = count( $stripe_sync['prices'] );
			}
		}

		wp_safe_redirect(
			add_query_arg( $redirect_args, admin_url( 'admin.php' ) )
		);
		exit;
	}

	private function handle_products_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['ajcore_product_dependencies_nonce'] ) ) {
			check_admin_referer( 'ajcore_save_product_dependencies', 'ajcore_product_dependencies_nonce' );
			$raw_dependencies = isset( $_POST['product_dependencies'] ) && is_array( $_POST['product_dependencies'] ) ? wp_unslash( $_POST['product_dependencies'] ) : array();
			$this->save_public_product_dependency_settings( $raw_dependencies );
			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-products', 'dependencies-saved' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_GET['ajf_products_action'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['ajf_products_action'] ) );
		if ( 'sync' !== $action ) {
			return;
		}

		check_admin_referer( 'ajf_sync_stripe_products' );

		$settings = $this->get_plugin_settings();
		$args     = array( 'page' => 'ajforms-products' );

		$result = $this->fetch_stripe_product_prices( isset( $settings['stripe_secret_key'] ) ? $settings['stripe_secret_key'] : '' );
		if ( is_wp_error( $result ) ) {
			$args['stripe-sync-error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['stripe-synced'] = count( $result['prices'] );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function normalize_portal_assignment_emails( $raw_emails ) {
		$emails = preg_split( '/[\s,;]+/', (string) $raw_emails );
		$valid  = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$valid[] = strtolower( $email );
			}
		}

		return array_values( array_unique( $valid ) );
	}

	private function get_portal_file_record( $file_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_files_table()} WHERE id = %d",
				absint( $file_id )
			)
		);
	}

	private function get_portal_file_assignments( $file_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_file_users_table()} WHERE file_id = %d ORDER BY user_email ASC, user_id ASC",
				absint( $file_id )
			)
		);
	}

	private function get_portal_file_assignment_labels( $file_id ) {
		$labels = array();

		foreach ( $this->get_portal_file_assignments( $file_id ) as $assignment ) {
			if ( ! empty( $assignment->user_id ) ) {
				$user = get_userdata( (int) $assignment->user_id );
				if ( $user ) {
					$labels[] = $user->display_name . ' <' . $user->user_email . '>';
					continue;
				}
			}

			if ( ! empty( $assignment->user_email ) ) {
				$labels[] = $assignment->user_email;
			}
		}

		return array_values( array_unique( $labels ) );
	}

	private function get_portal_file_customer_links( $file_id ) {
		global $wpdb;

		$links = array();
		foreach ( $this->get_portal_file_assignments( $file_id ) as $assignment ) {
			$user_id = ! empty( $assignment->user_id ) ? (int) $assignment->user_id : 0;
			$email   = ! empty( $assignment->user_email ) ? strtolower( sanitize_email( $assignment->user_email ) ) : '';
			$where   = array();
			$params  = array();

			if ( $user_id ) {
				$where[]  = 'm.user_id = %d';
				$params[] = $user_id;
			}

			if ( '' !== $email ) {
				$where[]  = 'LOWER(c.email) = %s';
				$params[] = $email;
			}

			if ( empty( $where ) ) {
				continue;
			}

			$sql = "SELECT c.stripe_customer_id, c.name, c.email
				FROM {$this->get_portal_stripe_customers_table()} c
				LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id
				WHERE " . implode( ' OR ', $where ) . '
				LIMIT 5';
			$customers = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
			foreach ( $customers as $customer ) {
				$label = $customer->name ? $customer->name : ( $customer->email ? $customer->email : $customer->stripe_customer_id );
				$links[ $customer->stripe_customer_id ] = array(
					'label' => $label,
					'url'   => $this->get_portal_customer_360_url( $customer->stripe_customer_id ),
				);
			}
		}

		return array_values( $links );
	}

	private function handle_file_library_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		if ( isset( $_GET['portal_file_action'], $_GET['file_id'], $_GET['_wpnonce'] ) && 'delete' === sanitize_key( wp_unslash( $_GET['portal_file_action'] ) ) ) {
			$file_id = absint( $_GET['file_id'] );
			check_admin_referer( 'ajf_delete_portal_file_' . $file_id );

			$wpdb->delete( $this->get_portal_file_users_table(), array( 'file_id' => $file_id ), array( '%d' ) );
			$wpdb->delete( $this->get_portal_files_table(), array( 'id' => $file_id ), array( '%d' ) );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'ajforms-client-portal',
						'tab'     => 'file-library',
						'deleted' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( ! isset( $_POST['ajf_portal_file_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajf_save_portal_file', 'ajf_portal_file_nonce' );

		$file_id       = isset( $_POST['portal_file_id'] ) ? absint( $_POST['portal_file_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$title         = isset( $_POST['portal_file_title'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_file_title'] ) ) : '';
		$description   = isset( $_POST['portal_file_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['portal_file_description'] ) ) : '';
		$category      = isset( $_POST['portal_file_category'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_file_category'] ) ) : '';
		$user_ids      = isset( $_POST['assigned_user_ids'] ) && is_array( $_POST['assigned_user_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['assigned_user_ids'] ) ) : array();
		$extra_emails  = isset( $_POST['assigned_user_emails'] ) ? $this->normalize_portal_assignment_emails( wp_unslash( $_POST['assigned_user_emails'] ) ) : array();

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'ajforms-client-portal',
						'tab'   => 'file-library',
						'error' => 'missing-file',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( '' === $title ) {
			$title = get_the_title( $attachment_id );
			if ( '' === $title ) {
				$title = basename( (string) get_attached_file( $attachment_id ) );
			}
		}

		$data = array(
			'attachment_id' => $attachment_id,
			'title'         => $title,
			'description'   => $description,
			'category'      => $category,
			'updated_at'    => current_time( 'mysql' ),
		);

		if ( $file_id && $this->get_portal_file_record( $file_id ) ) {
			$wpdb->update(
				$this->get_portal_files_table(),
				$data,
				array( 'id' => $file_id ),
				array( '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert(
				$this->get_portal_files_table(),
				$data,
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			$file_id = (int) $wpdb->insert_id;
		}

		if ( $file_id ) {
			$wpdb->delete( $this->get_portal_file_users_table(), array( 'file_id' => $file_id ), array( '%d' ) );

			foreach ( array_values( array_unique( $user_ids ) ) as $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					continue;
				}

				$wpdb->insert(
					$this->get_portal_file_users_table(),
					array(
						'file_id'    => $file_id,
						'user_id'    => $user_id,
						'user_email' => strtolower( $user->user_email ),
						'created_at' => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}

			foreach ( $extra_emails as $email ) {
				$wpdb->insert(
					$this->get_portal_file_users_table(),
					array(
						'file_id'    => $file_id,
						'user_id'    => 0,
						'user_email' => $email,
						'created_at' => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'ajforms-client-portal',
					'tab'   => 'file-library',
					'saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function get_all_role_capabilities() {
		global $wp_roles;

		if ( ! isset( $wp_roles ) || ! $wp_roles instanceof WP_Roles ) {
			$wp_roles = wp_roles();
		}

		$capabilities = array();
		foreach ( $wp_roles->roles as $role ) {
			if ( empty( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
				continue;
			}

			foreach ( $role['capabilities'] as $capability => $enabled ) {
				$capabilities[ $capability ] = true;
			}
		}

		$core_caps = array(
			'read',
			'edit_posts',
			'delete_posts',
			'publish_posts',
			'upload_files',
			'edit_pages',
			'publish_pages',
			'delete_pages',
			'edit_others_posts',
			'delete_others_posts',
			'manage_categories',
			'moderate_comments',
			'manage_options',
			'list_users',
			'edit_users',
			'create_users',
			'delete_users',
			'promote_users',
			'activate_plugins',
			'install_plugins',
			'update_plugins',
			'delete_plugins',
			'switch_themes',
			'edit_theme_options',
			'update_core',
		);

		foreach ( $core_caps as $capability ) {
			$capabilities[ $capability ] = true;
		}

		$capabilities = array_keys( $capabilities );
		sort( $capabilities );

		return $capabilities;
	}

	private function get_role_user_count( $role_key ) {
		$query = new WP_User_Query(
			array(
				'role'   => $role_key,
				'fields' => 'ID',
				'number' => 1,
			)
		);

		return (int) $query->get_total();
	}

	private function get_ajcore_managed_roles() {
		$roles = get_option( 'ajcore_managed_roles', array() );

		return is_array( $roles ) ? array_values( array_unique( array_map( 'sanitize_key', $roles ) ) ) : array();
	}

	private function mark_ajcore_managed_role( $role_key ) {
		$roles   = $this->get_ajcore_managed_roles();
		$roles[] = sanitize_key( $role_key );

		update_option( 'ajcore_managed_roles', array_values( array_unique( $roles ) ), false );
	}

	private function unmark_ajcore_managed_role( $role_key ) {
		$role_key = sanitize_key( $role_key );
		$roles    = array_values(
			array_filter(
				$this->get_ajcore_managed_roles(),
				function ( $managed_role ) use ( $role_key ) {
					return $managed_role !== $role_key;
				}
			)
		);

		update_option( 'ajcore_managed_roles', $roles, false );
	}

	private function get_role_type_info( $role_key ) {
		$role_key = sanitize_key( $role_key );

		if ( in_array( $role_key, array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ), true ) ) {
			return array(
				'label' => __( 'WordPress Default', 'ajforms' ),
				'class' => 'wordpress',
			);
		}

		if ( in_array( $role_key, $this->get_ajcore_managed_roles(), true ) || 0 === strpos( $role_key, 'ajcore_' ) || 0 === strpos( $role_key, 'aj_' ) ) {
			return array(
				'label' => __( 'AJ Core', 'ajforms' ),
				'class' => 'ajcore',
			);
		}

		return array(
			'label' => __( 'Custom / Plugin', 'ajforms' ),
			'class' => 'custom',
		);
	}

	private function update_role_display_name( $role_key, $role_label ) {
		global $wp_roles;

		if ( ! isset( $wp_roles ) || ! $wp_roles instanceof WP_Roles ) {
			$wp_roles = wp_roles();
		}

		if ( empty( $wp_roles->roles[ $role_key ] ) ) {
			return;
		}

		$wp_roles->roles[ $role_key ]['name'] = $role_label;
		$wp_roles->role_names[ $role_key ]    = $role_label;

		if ( ! empty( $wp_roles->use_db ) ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
	}

	private function sanitize_role_capability_list( $capabilities, $custom_capabilities = '' ) {
		$clean = array();

		if ( is_array( $capabilities ) ) {
			foreach ( $capabilities as $capability ) {
				$capability = sanitize_key( wp_unslash( $capability ) );
				if ( '' !== $capability ) {
					$clean[] = $capability;
				}
			}
		}

		$custom_items = preg_split( '/[\s,;]+/', (string) $custom_capabilities );
		foreach ( $custom_items as $capability ) {
			$capability = sanitize_key( $capability );
			if ( '' !== $capability ) {
				$clean[] = $capability;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	private function handle_role_manager_actions() {
		if ( ! $this->current_user_can_manage_ajcore_roles() ) {
			return;
		}

		if ( isset( $_GET['role_manager_action'], $_GET['role'], $_GET['_wpnonce'] ) && 'delete' === sanitize_key( wp_unslash( $_GET['role_manager_action'] ) ) ) {
			$role_key = sanitize_key( wp_unslash( $_GET['role'] ) );
			check_admin_referer( 'ajcore_delete_role_' . $role_key );

			$args = array( 'page' => 'ajforms-settings', 'section' => 'role-manager' );

			if ( in_array( $role_key, array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ), true ) ) {
				$args['role-error'] = 'wordpress-default-delete';
			} elseif ( $this->get_role_user_count( $role_key ) > 0 ) {
				$args['role-error'] = 'role-has-users';
			} elseif ( get_role( $role_key ) ) {
				remove_role( $role_key );
				$this->unmark_ajcore_managed_role( $role_key );
				$args['role-deleted'] = 1;
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_POST['ajcore_role_manager_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajcore_save_role', 'ajcore_role_manager_nonce' );

		$action              = isset( $_POST['role_manager_action'] ) ? sanitize_key( wp_unslash( $_POST['role_manager_action'] ) ) : '';
		$role_key            = isset( $_POST['role_key'] ) ? sanitize_key( wp_unslash( $_POST['role_key'] ) ) : '';
		$role_label          = isset( $_POST['role_label'] ) ? sanitize_text_field( wp_unslash( $_POST['role_label'] ) ) : '';
		$selected_caps       = isset( $_POST['role_capabilities'] ) && is_array( $_POST['role_capabilities'] ) ? $_POST['role_capabilities'] : array();
		$custom_capabilities = isset( $_POST['custom_capabilities'] ) ? wp_unslash( $_POST['custom_capabilities'] ) : '';
		$capabilities        = $this->sanitize_role_capability_list( $selected_caps, $custom_capabilities );
		$args                = array( 'page' => 'ajforms-settings', 'section' => 'role-manager' );

		if ( '' === $role_key || '' === $role_label ) {
			$args['role-error'] = 'missing-fields';
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'add' === $action ) {
			if ( get_role( $role_key ) ) {
				$args['role-error'] = 'role-exists';
			} else {
				$cap_map = array();
				foreach ( $capabilities as $capability ) {
					$cap_map[ $capability ] = true;
				}

				add_role( $role_key, $role_label, $cap_map );
				$this->mark_ajcore_managed_role( $role_key );
				$args['role-saved'] = 1;
			}
		} elseif ( 'edit' === $action && get_role( $role_key ) ) {
			$role = get_role( $role_key );
			$this->update_role_display_name( $role_key, $role_label );

			if ( 'administrator' === $role_key ) {
				$capabilities = array_values( array_unique( array_merge( array_keys( $role->capabilities ), $capabilities ) ) );
			}

			foreach ( array_keys( $role->capabilities ) as $capability ) {
				if ( 'administrator' === $role_key ) {
					continue;
				}

				if ( ! in_array( $capability, $capabilities, true ) ) {
					$role->remove_cap( $capability );
				}
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability );
			}

			$args['role-saved'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function enqueue_styles( $hook_suffix ) {
		if ( strpos( $hook_suffix, 'ajforms' ) === false ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$css_rel_path = 'admin/partials/ajforms-builder.css';
			$css_path     = AJFORMS_PLUGIN_DIR . $css_rel_path;
			$version      = file_exists( $css_path ) ? filemtime( $css_path ) : AJFORMS_VERSION;

			wp_enqueue_style(
				'ajforms-builder',
				AJFORMS_PLUGIN_URL . 'admin/partials/ajforms-builder.css',
				array(),
				$version
			);
		}

		if ( isset( $_GET['page'] ) && in_array( sanitize_text_field( wp_unslash( $_GET['page'] ) ), array( 'ajforms', 'ajforms-leads' ), true ) ) {
			wp_add_inline_style(
				'wp-admin',
				'
				.ajforms-status-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
				.ajforms-status-badge.unread{background:#fff4e5;color:#b26a00;border:1px solid #f0c36d}
				.ajforms-status-badge.read{background:#ecf7ed;color:#1e7e34;border:1px solid #9ad3a3}
				.ajforms-lead-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin-top:16px}
				.ajforms-lead-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px}
				.ajforms-lead-meta-table{width:100%;border-collapse:collapse}
				.ajforms-lead-meta-table th,.ajforms-lead-meta-table td{padding:10px 12px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
				.ajforms-note{border:1px solid #e5e5e5;border-radius:8px;padding:12px;margin-bottom:12px;background:#fafafa}
				.ajforms-inline-actions{display:flex;gap:8px;align-items:center}
				.ajforms-inline-actions .button-link-delete{color:#b32d2e}
				.ajforms-summary-line{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:420px}
				'
			);
		}
	}

	public function enqueue_scripts( $hook_suffix ) {
		if ( strpos( $hook_suffix, 'ajforms' ) === false ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$js_rel_path = 'admin/ajforms-builder.js';
			$js_path     = AJFORMS_PLUGIN_DIR . $js_rel_path;
			$version     = file_exists( $js_path ) ? filemtime( $js_path ) : AJFORMS_VERSION;

			wp_enqueue_script(
				'ajforms-builder-js',
				AJFORMS_PLUGIN_URL . $js_rel_path,
				array(),
				$version,
				true
			);

			wp_localize_script(
				'ajforms-builder-js',
				'ajFormsBuilder',
				array(
					'ajaxurl'      => admin_url( 'admin-ajax.php' ),
					'nonce_save'   => wp_create_nonce( 'ajf_save_form' ),
					'nonce_import' => wp_create_nonce( 'ajf_import_form' ),
					'formsUrl'     => admin_url( 'admin.php?page=ajforms' ),
				)
			);
		}

		if ( isset( $_GET['page'] ) && 'ajforms-file-library' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_enqueue_media();
		}

		if ( isset( $_GET['page'] ) && 'ajforms-client-portal' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_enqueue_media();
		}
	}

	private function get_forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajforms_forms';
	}

	private function get_leads_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajforms_leads';
	}

	private function get_lead_notes_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajforms_lead_notes';
	}

	private function get_portal_files_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_files';
	}

	private function get_portal_file_users_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_file_users';
	}

	private function get_portal_stripe_customers_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_stripe_customers';
	}

	private function get_portal_stripe_products_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_stripe_products';
	}

	private function get_portal_stripe_subscriptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_stripe_subscriptions';
	}

	private function get_portal_stripe_transactions_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_stripe_transactions';
	}

	private function get_portal_user_mappings_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_user_mappings';
	}

	private function get_portal_entity_mappings_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_entity_mappings';
	}

	private function get_portal_ledger_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_ledger';
	}

	private function get_portal_tasks_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_tasks';
	}

	private function get_portal_task_statuses_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_task_statuses';
	}

	private function get_portal_task_comments_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_task_comments';
	}

	private function get_portal_sync_logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_sync_logs';
	}

	private function get_portal_sync_log_items_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_sync_log_items';
	}

	private function get_portal_service_requests_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_service_requests';
	}

	private function get_portal_event_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_event_log';
	}

	private function get_portal_stripe_events_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_stripe_events';
	}

	private function get_portal_service_snapshots_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_service_snapshots';
	}

	public function get_form_record( $form_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_forms_table()} WHERE id = %d",
				absint( $form_id )
			)
		);
	}

	public function get_form_schema_fields( $form_id ) {
		$form = $this->get_form_record( $form_id );
		if ( ! $form || empty( $form->form_schema ) ) {
			return array();
		}

		$decoded = json_decode( $form->form_schema, true );
		if ( isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ) {
			return $decoded['fields'];
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	private function sanitize_lead_value_for_field( $field, $posted_value, $existing_value ) {
		$field_type = isset( $field['type'] ) ? $field['type'] : 'text';
		$field_id   = isset( $field['id'] ) ? $field['id'] : '';
		$label      = ! empty( $field['label'] ) ? $field['label'] : $field_id;
		$required   = ! empty( $field['required'] );

		if ( 'separator' === $field_type || '' === $field_id ) {
			return array( 'skip' => true );
		}

		if ( 'file' === $field_type ) {
			$has_upload = isset( $_FILES[ $field_id ] ) && ! empty( $_FILES[ $field_id ]['name'] );
			$current    = is_array( $existing_value ) ? $existing_value : array();

			if ( ! $has_upload ) {
				if ( $required && empty( $current['value'] ) ) {
					return array( 'error' => sprintf( __( '%s is required.', 'ajforms' ), $label ) );
				}

				return array(
					'value' => array(
						'label'          => $label,
						'type'           => $field_type,
						'value'          => isset( $current['value'] ) ? $current['value'] : '',
						'file_name'      => isset( $current['file_name'] ) ? $current['file_name'] : '',
						'file_path'      => isset( $current['file_path'] ) ? $current['file_path'] : '',
						'accepted_types' => isset( $current['accepted_types'] ) ? $current['accepted_types'] : '',
					),
				);
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';

			$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? explode( ',', (string) $field['accepted_file_types'] ) : array( '.pdf', '.jpg', '.jpeg', '.png', '.gif', '.webp' );
			$accepted_file_types = array_map( 'trim', $accepted_file_types );
			$allowed_mimes       = array(
				'pdf'          => 'application/pdf',
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'gif'          => 'image/gif',
				'webp'         => 'image/webp',
			);

			$uploaded = wp_handle_upload(
				$_FILES[ $field_id ],
				array(
					'test_form' => false,
					'mimes'     => $allowed_mimes,
				)
			);

			if ( isset( $uploaded['error'] ) ) {
				return array( 'error' => sprintf( __( '%s upload failed.', 'ajforms' ), $label ) );
			}

			$file_url  = isset( $uploaded['url'] ) ? esc_url_raw( $uploaded['url'] ) : '';
			$file_path = isset( $uploaded['file'] ) ? $uploaded['file'] : '';
			$file_name = isset( $_FILES[ $field_id ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ $field_id ]['name'] ) ) : '';
			$file_ext  = strtolower( strrchr( $file_name, '.' ) );

			if ( ! empty( $accepted_file_types ) && ! in_array( $file_ext, $accepted_file_types, true ) ) {
				return array( 'error' => sprintf( __( '%s file type is not allowed.', 'ajforms' ), $label ) );
			}

			return array(
				'value' => array(
					'label'          => $label,
					'type'           => $field_type,
					'value'          => $file_url,
					'file_name'      => $file_name,
					'file_path'      => $file_path,
					'accepted_types' => implode( ',', $accepted_file_types ),
				),
			);
		}

		if ( is_array( $posted_value ) ) {
			$clean_value = array_map( 'sanitize_text_field', $posted_value );
			$is_empty    = empty( array_filter( $clean_value, 'strlen' ) );
		} else {
			switch ( $field_type ) {
				case 'email':
					$clean_value = sanitize_email( $posted_value );
					break;
				case 'url':
					$clean_value = esc_url_raw( $posted_value );
					break;
				case 'textarea':
					$clean_value = sanitize_textarea_field( $posted_value );
					break;
				default:
					$clean_value = sanitize_text_field( $posted_value );
					break;
			}

			$is_empty = '' === $clean_value;
		}

		if ( $required && $is_empty ) {
			return array( 'error' => sprintf( __( '%s is required.', 'ajforms' ), $label ) );
		}

		return array(
			'value' => array(
				'label' => $label,
				'type'  => $field_type,
				'value' => $clean_value,
			),
		);
	}

	private function update_lead_entry( $lead_id ) {
		global $wpdb;

		$lead = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_leads_table()} WHERE id = %d",
				$lead_id
			)
		);

		if ( ! $lead ) {
			return array( 'success' => false, 'message' => __( 'Entry not found.', 'ajforms' ) );
		}

		$form = $this->get_form_record( $lead->form_id );
		if ( ! $form ) {
			return array( 'success' => false, 'message' => __( 'This entry is linked to a form that no longer exists.', 'ajforms' ) );
		}

		$fields         = $this->get_form_schema_fields( $lead->form_id );
		$existing_data  = json_decode( $lead->lead_data, true );
		$existing_data  = is_array( $existing_data ) ? $existing_data : array();
		$updated_data   = $existing_data;
		$errors         = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['id'] ) ) {
				continue;
			}

			$field_id      = $field['id'];
			$posted_value  = isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : null;
			$existing_value = isset( $existing_data[ $field_id ] ) ? $existing_data[ $field_id ] : array();
			$result        = $this->sanitize_lead_value_for_field( $field, $posted_value, $existing_value );

			if ( ! empty( $result['skip'] ) ) {
				continue;
			}

			if ( ! empty( $result['error'] ) ) {
				$errors[] = $result['error'];
				continue;
			}

			$updated_data[ $field_id ] = $result['value'];
		}

		if ( ! empty( $errors ) ) {
			return array(
				'success' => false,
				'message' => implode( ' ', $errors ),
			);
		}

		$updated = $wpdb->update(
			$this->get_leads_table(),
			array( 'lead_data' => wp_json_encode( $updated_data ) ),
			array( 'id' => $lead_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return array( 'success' => false, 'message' => __( 'Unable to update entry.', 'ajforms' ) );
		}

		return array( 'success' => true, 'message' => __( 'Entry updated.', 'ajforms' ) );
	}

	private function get_portal_service_request_status_labels() {
		return array(
			'draft'                 => __( 'Draft', 'ajforms' ),
			'pending_payment'       => __( 'Pending Payment', 'ajforms' ),
			'awaiting_payment'      => __( 'Awaiting Payment', 'ajforms' ),
			'paid'                  => __( 'Paid', 'ajforms' ),
			'cancelled'             => __( 'Cancelled', 'ajforms' ),
			'failed'                => __( 'Failed', 'ajforms' ),
			'admin_review_required' => __( 'Admin Review Required', 'ajforms' ),
			'completed'             => __( 'Completed', 'ajforms' ),
		);
	}

	private function normalize_portal_service_request_status( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = $this->get_portal_service_request_status_labels();

		return isset( $labels[ $status ] ) ? $status : 'draft';
	}


	private function get_portal_service_request_raw_data( $request ) {
		$raw = isset( $request->raw_data ) ? json_decode( (string) $request->raw_data, true ) : array();

		return is_array( $raw ) ? $raw : array();
	}

	private function get_portal_service_request_meta_value( $request, $key ) {
		$raw = $this->get_portal_service_request_raw_data( $request );

		return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
	}

	private function sync_portal_service_request_to_ledger( $request, $data = array() ) {
		global $wpdb;

		if ( ! $request ) {
			return false;
		}

		$ledger_update = array();
		$ledger_formats = array();

		if ( array_key_exists( 'amount', $data ) ) {
			$ledger_update['amount'] = (float) $data['amount'];
			$ledger_formats[] = '%f';
		}
		if ( array_key_exists( 'currency', $data ) ) {
			$ledger_update['currency'] = sanitize_key( (string) $data['currency'] );
			$ledger_formats[] = '%s';
		}
		if ( array_key_exists( 'status', $data ) ) {
			$ledger_update['status'] = sanitize_key( (string) $data['status'] );
			$ledger_formats[] = '%s';
		}

		$ledger = null;
		if ( ! empty( $request->ledger_id ) ) {
			$ledger = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->get_portal_ledger_table()} WHERE id = %d LIMIT 1",
					(int) $request->ledger_id
				)
			);
		}

		if ( ! $ledger && ! empty( $request->source_object_id ) ) {
			$ledger = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->get_portal_ledger_table()} WHERE stripe_customer_id = %s AND source_object_id = %s LIMIT 1",
					sanitize_text_field( (string) $request->stripe_customer_id ),
					sanitize_text_field( (string) $request->source_object_id )
				)
			);
		}

		if ( ! $ledger ) {
			return false;
		}

		$metadata = ! empty( $ledger->metadata ) ? json_decode( (string) $ledger->metadata, true ) : array();
		$metadata = is_array( $metadata ) ? $metadata : array();

		foreach ( array( 'payment_url', 'payment_reference', 'client_notes', 'request_id' ) as $meta_key ) {
			if ( array_key_exists( $meta_key, $data ) ) {
				$metadata[ $meta_key ] = is_scalar( $data[ $meta_key ] ) ? (string) $data[ $meta_key ] : '';
			}
		}
		$metadata['request_status_updated_at'] = current_time( 'mysql' );

		$ledger_update['metadata'] = wp_json_encode( $metadata );
		$ledger_formats[] = '%s';

		if ( empty( $ledger_update ) ) {
			return true;
		}

		$updated = $wpdb->update(
			$this->get_portal_ledger_table(),
			$ledger_update,
			array( 'id' => (int) $ledger->id ),
			$ledger_formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	private function handle_portal_service_request_details_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['service_request_action'] ) || 'save_details' !== sanitize_key( wp_unslash( $_POST['service_request_action'] ) ) ) {
			return;
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0;
		if ( ! $request_id ) {
			return;
		}

		check_admin_referer( 'ajcore_service_request_details_' . $request_id );

		global $wpdb;
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->get_portal_service_requests_table()} WHERE id = %d", $request_id ) );
		if ( ! $request ) {
			return;
		}

		$amount = (float) $request->amount;
		if ( isset( $_POST['request_amount'] ) ) {
			$raw_amount = wp_unslash( $_POST['request_amount'] );
			if ( function_exists( 'wc_format_decimal' ) ) {
				$amount = (float) wc_format_decimal( $raw_amount );
			} else {
				$amount = (float) preg_replace( '/[^0-9.\-]/', '', (string) $raw_amount );
			}
		}
		$currency = isset( $_POST['request_currency'] ) ? sanitize_key( wp_unslash( $_POST['request_currency'] ) ) : sanitize_key( (string) $request->currency );
		if ( '' === $currency ) {
			$currency = 'usd';
		}

		$client_notes = isset( $_POST['client_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['client_notes'] ) ) : '';
		$admin_notes  = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';
		$payment_url  = isset( $_POST['payment_url'] ) ? esc_url_raw( wp_unslash( $_POST['payment_url'] ) ) : '';
		$payment_reference = isset( $_POST['payment_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_reference'] ) ) : '';
		$after_save_status = isset( $_POST['after_save_status'] ) ? sanitize_key( wp_unslash( $_POST['after_save_status'] ) ) : '';
		$selected_price_id = isset( $_POST['request_stripe_price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_stripe_price_id'] ) ) : '';
		$selected_product  = $this->get_portal_product_by_price_id( $selected_price_id );
		if ( '' !== $selected_price_id && ! $selected_product ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'service-requests', 'service-request-error' => rawurlencode( __( 'Selected Stripe price was not found in the synced product cache.', 'ajforms' ) ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$subscription_id = isset( $_POST['billing_subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_subscription_id'] ) ) : '';
		$subscription_item_id = isset( $_POST['billing_subscription_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_subscription_item_id'] ) ) : '';
		$proration_behavior = isset( $_POST['billing_proration_behavior'] ) ? sanitize_key( wp_unslash( $_POST['billing_proration_behavior'] ) ) : 'create_prorations';
		if ( ! in_array( $proration_behavior, array( 'create_prorations', 'none', 'always_invoice' ), true ) ) {
			$proration_behavior = 'create_prorations';
		}

		$old_request_status = sanitize_key( (string) $request->status );
		$status             = $old_request_status;
		if ( '' !== $after_save_status && isset( $this->get_portal_service_request_status_labels()[ $after_save_status ] ) ) {
			$status = $after_save_status;
		}

		$raw_data = $this->get_portal_service_request_raw_data( $request );
		$raw_data['payment_url'] = $payment_url;
		$raw_data['payment_reference'] = $payment_reference;
		$raw_data['details_updated_at'] = current_time( 'mysql' );
		$raw_data['details_updated_by'] = get_current_user_id();
		if ( $selected_product ) {
			$raw_data['selected_service_price'] = array(
				'stripe_price_id'   => sanitize_text_field( (string) $selected_product->stripe_price_id ),
				'stripe_product_id' => sanitize_text_field( (string) $selected_product->stripe_product_id ),
				'name'              => sanitize_text_field( (string) $selected_product->name ),
				'amount'            => (float) $selected_product->price_amount,
				'currency'          => sanitize_key( (string) $selected_product->currency ),
				'recurring_interval'=> sanitize_key( (string) $selected_product->recurring_interval ),
				'active'            => ! empty( $selected_product->active ) ? 1 : 0,
			);
			$raw_data['billing_preview'] = array(
				'subscription_id'      => $subscription_id,
				'subscription_item_id' => $subscription_item_id,
				'new_price_id'         => sanitize_text_field( (string) $selected_product->stripe_price_id ),
				'new_product_id'       => sanitize_text_field( (string) $selected_product->stripe_product_id ),
				'new_price_label'      => $this->get_portal_product_admin_label( $selected_product ),
				'proration_behavior'   => $proration_behavior,
				'preview_saved_at'     => current_time( 'mysql' ),
				'preview_saved_by'     => get_current_user_id(),
			);
		}

		$update_data = array(
			'amount'       => $amount,
			'currency'     => $currency,
			'status'       => $status,
			'admin_notes'  => $admin_notes,
			'client_notes' => $client_notes,
			'raw_data'     => wp_json_encode( $raw_data ),
			'updated_at'   => current_time( 'mysql' ),
		);
		$update_formats = array( '%f', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( $selected_product ) {
			$update_data['stripe_price_id']   = sanitize_text_field( (string) $selected_product->stripe_price_id );
			$update_data['stripe_product_id'] = sanitize_text_field( (string) $selected_product->stripe_product_id );
			$update_data['service_name']      = sanitize_text_field( (string) $selected_product->name );
			$update_formats[] = '%s';
			$update_formats[] = '%s';
			$update_formats[] = '%s';
		}

		$wpdb->update(
			$this->get_portal_service_requests_table(),
			$update_data,
			array( 'id' => $request_id ),
			$update_formats,
			array( '%d' )
		);

		$request->amount = $amount;
		$request->currency = $currency;
		$request->status = $status;
		$request->client_notes = $client_notes;
		$request->admin_notes = $admin_notes;
		$request->raw_data = wp_json_encode( $raw_data );
		if ( $selected_product ) {
			$request->stripe_price_id = sanitize_text_field( (string) $selected_product->stripe_price_id );
			$request->stripe_product_id = sanitize_text_field( (string) $selected_product->stripe_product_id );
			$request->service_name = sanitize_text_field( (string) $selected_product->name );
		}

		$this->sync_portal_service_request_to_ledger(
			$request,
			array(
				'amount'            => $amount,
				'currency'          => $currency,
				'status'            => $status,
				'payment_url'       => $payment_url,
				'payment_reference' => $payment_reference,
				'client_notes'      => $client_notes,
				'request_id'        => $request_id,
			)
		);
		if ( 'completed' === $status && 'completed' !== $old_request_status ) {
			$this->log_portal_event(
				'service_provisioned',
				array(
					'source'             => 'service_requests',
					'stripe_customer_id' => $request->stripe_customer_id,
					'wp_user_id_after'   => isset( $request->wp_user_id ) ? (int) $request->wp_user_id : 0,
					'portal_status_before'=> $old_request_status,
					'portal_status_after'=> 'active',
					'details'            => array(
						'request_id'        => (int) $request_id,
						'service_name'      => $request->service_name,
						'stripe_price_id'   => $request->stripe_price_id,
						'stripe_product_id' => $request->stripe_product_id,
					),
				)
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'service-requests', 'service-request-updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function cleanup_stale_pending_checkout_service_requests() {
		global $wpdb;

		$this->ensure_portal_schema();

		$stale_requests = $wpdb->get_results(
			"SELECT r.*, l.source_type AS ledger_source_type, l.status AS ledger_status, l.invoice_id, l.payment_intent_id, l.charge_id
			FROM {$this->get_portal_service_requests_table()} r
			LEFT JOIN {$this->get_portal_ledger_table()} l ON l.id = r.ledger_id
			WHERE r.request_type = 'add_service'
			AND r.source = 'ledger_backfill'
			AND r.status IN ('draft','pending_payment')
			AND (r.source_type = 'checkout_session' OR l.source_type = 'checkout_session')"
		);

		foreach ( $stale_requests as $request ) {
			if ( ! empty( $request->ledger_id ) && $this->is_portal_service_request_ledger_safe_to_delete( $request ) ) {
				$wpdb->delete( $this->get_portal_ledger_table(), array( 'id' => (int) $request->ledger_id ), array( '%d' ) );
			}

			$wpdb->delete( $this->get_portal_service_requests_table(), array( 'id' => (int) $request->id ), array( '%d' ) );
		}
	}

	private function is_portal_service_request_ledger_safe_to_delete( $request ) {
		if ( empty( $request->ledger_id ) ) {
			return false;
		}

		global $wpdb;
		$ledger = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE id = %d LIMIT 1",
				(int) $request->ledger_id
			)
		);

		if ( ! $ledger ) {
			return false;
		}

		if ( ! empty( $ledger->invoice_id ) || ! empty( $ledger->payment_intent_id ) || ! empty( $ledger->charge_id ) ) {
			return false;
		}

		$source_type = sanitize_key( (string) $ledger->source_type );
		$status      = sanitize_key( (string) $ledger->status );

		if ( in_array( $source_type, array( 'invoice', 'charge' ), true ) ) {
			return false;
		}

		if ( 'checkout_session' === $source_type && in_array( $status, array( 'paid', 'succeeded', 'completed' ), true ) ) {
			return false;
		}

		return in_array( $source_type, array( 'checkout_session', 'custom_service_request', 'service_quote_request', 'custom_request', 'service_request', 'portal_custom_request', 'portal_additional_entity_pricing' ), true );
	}

	private function handle_portal_service_request_delete( $request_id ) {
		global $wpdb;

		$request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_service_requests_table()} WHERE id = %d LIMIT 1",
				(int) $request_id
			)
		);

		if ( ! $request ) {
			return false;
		}

		if ( $this->is_portal_service_request_ledger_safe_to_delete( $request ) ) {
			$wpdb->delete( $this->get_portal_ledger_table(), array( 'id' => (int) $request->ledger_id ), array( '%d' ) );
		}

		return false !== $wpdb->delete( $this->get_portal_service_requests_table(), array( 'id' => (int) $request_id ), array( '%d' ) );
	}

	private function apply_portal_billing_change_request( $request_id ) {
		global $wpdb;

		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->get_portal_service_requests_table()} WHERE id = %d LIMIT 1", absint( $request_id ) ) );
		if ( ! $request ) {
			return new WP_Error( 'request_not_found', __( 'Service request was not found.', 'ajforms' ) );
		}

		$raw = $this->get_portal_service_request_raw_data( $request );
		$preview = ! empty( $raw['billing_preview'] ) && is_array( $raw['billing_preview'] ) ? $raw['billing_preview'] : $raw;
		$subscription_id = ! empty( $preview['subscription_id'] ) ? sanitize_text_field( (string) $preview['subscription_id'] ) : '';
		$subscription_item_id = ! empty( $preview['subscription_item_id'] ) ? sanitize_text_field( (string) $preview['subscription_item_id'] ) : '';
		$new_price_id = ! empty( $preview['new_price_id'] ) ? sanitize_text_field( (string) $preview['new_price_id'] ) : ( ! empty( $request->stripe_price_id ) ? sanitize_text_field( (string) $request->stripe_price_id ) : '' );
		$proration_behavior = ! empty( $preview['proration_behavior'] ) ? sanitize_key( (string) $preview['proration_behavior'] ) : 'create_prorations';
		$allowed_proration = array( 'create_prorations', 'none', 'always_invoice' );
		if ( ! in_array( $proration_behavior, $allowed_proration, true ) ) {
			$proration_behavior = 'create_prorations';
		}

		if ( '' === $subscription_id || '' === $new_price_id ) {
			return new WP_Error( 'missing_billing_preview', __( 'This request does not have enough saved billing preview data to apply to Stripe.', 'ajforms' ) );
		}

		$secret_key = $this->get_stripe_secret_key_for_portal();
		if ( '' === $secret_key ) {
			return new WP_Error( 'missing_stripe_key', __( 'Stripe secret key is required.', 'ajforms' ) );
		}

		if ( '' === $subscription_item_id ) {
			$subscription = $this->stripe_api_get( 'subscriptions/' . rawurlencode( $subscription_id ), $secret_key );
			if ( is_wp_error( $subscription ) ) {
				return $subscription;
			}
			if ( ! empty( $subscription['items']['data'][0]['id'] ) ) {
				$subscription_item_id = sanitize_text_field( (string) $subscription['items']['data'][0]['id'] );
			}
		}

		if ( '' === $subscription_item_id ) {
			return new WP_Error( 'missing_subscription_item', __( 'Unable to determine the Stripe subscription item to update.', 'ajforms' ) );
		}

		$response = $this->stripe_api_request(
			'subscription_items/' . rawurlencode( $subscription_item_id ),
			$secret_key,
			array(
				'price'              => $new_price_id,
				'proration_behavior' => $proration_behavior,
			),
			'POST'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw['billing_apply'] = array(
			'applied_at'            => current_time( 'mysql' ),
			'applied_by'            => get_current_user_id(),
			'subscription_id'       => $subscription_id,
			'subscription_item_id'  => $subscription_item_id,
			'new_price_id'          => $new_price_id,
			'proration_behavior'    => $proration_behavior,
			'stripe_response_id'    => ! empty( $response['id'] ) ? sanitize_text_field( (string) $response['id'] ) : '',
		);

		$wpdb->update(
			$this->get_portal_service_requests_table(),
			array(
				'status'          => 'admin_review_required',
				'stripe_price_id' => $new_price_id,
				'raw_data'        => wp_json_encode( $raw ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => (int) $request->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->sync_single_portal_stripe_customer( $secret_key, sanitize_text_field( (string) $request->stripe_customer_id ) );
		$this->sync_portal_service_request_to_ledger(
			$request,
			array(
				'status'            => 'admin_review_required',
				'payment_reference' => ! empty( $raw['billing_apply']['stripe_response_id'] ) ? $raw['billing_apply']['stripe_response_id'] : '',
				'request_id'        => (int) $request->id,
			)
		);

		return true;
	}

	private function backfill_portal_service_requests_from_ledger() {
		global $wpdb;

		$this->ensure_portal_schema();
		$actionable_source_types = array( 'custom_service_request', 'service_quote_request', 'custom_request', 'service_request', 'checkout_session' );
		$source_placeholders = implode( ',', array_fill( 0, count( $actionable_source_types ), '%s' ) );
		$ledger_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, c.email AS customer_email, c.name AS customer_name
				FROM {$this->get_portal_ledger_table()} l
				LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = l.stripe_customer_id
				WHERE l.source_type IN ({$source_placeholders})
				ORDER BY l.id ASC",
				$actionable_source_types
			)
		);

		foreach ( $ledger_rows as $entry ) {
			if ( empty( $entry->source_object_id ) ) {
				continue;
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->get_portal_service_requests_table()} WHERE source_object_id = %s LIMIT 1",
					$entry->source_object_id
				)
			);

			$metadata = ! empty( $entry->metadata ) ? json_decode( (string) $entry->metadata, true ) : array();
			$metadata = is_array( $metadata ) ? $metadata : array();
			$stripe_customer_id = sanitize_text_field( (string) $entry->stripe_customer_id );
			$wp_user_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s LIMIT 1",
					$stripe_customer_id
				)
			);
			$source_type = sanitize_key( (string) $entry->source_type );
			$is_checkout_request = 'checkout_session' === $source_type;
			if ( $is_checkout_request && ! in_array( sanitize_key( (string) $entry->status ), array( 'paid', 'succeeded', 'completed', 'failed' ), true ) ) {
				continue;
			}
			$request_type = $is_checkout_request ? 'add_service' : 'custom_request';
			$status = 'admin_review_required';
			if ( $is_checkout_request ) {
				$status = 'paid' === $entry->status ? 'paid' : ( 'failed' === $entry->status ? 'failed' : 'pending_payment' );
			} elseif ( ! empty( $entry->status ) ) {
				$status = $this->normalize_portal_service_request_status( $entry->status );
			}

			$request_data = array(
				'wp_user_id'          => $wp_user_id,
				'stripe_customer_id'  => $stripe_customer_id,
				'stripe_price_id'     => ! empty( $metadata['price_id'] ) ? sanitize_text_field( (string) $metadata['price_id'] ) : '',
				'stripe_product_id'   => ! empty( $metadata['product_id'] ) ? sanitize_text_field( (string) $metadata['product_id'] ) : '',
				'service_name'        => ! empty( $metadata['product_name'] ) ? sanitize_text_field( (string) $metadata['product_name'] ) : sanitize_text_field( (string) $entry->description ),
				'request_type'        => $request_type,
				'status'              => $status,
				'amount'              => (float) $entry->amount,
				'currency'            => sanitize_key( (string) $entry->currency ),
				'source_object_id'    => sanitize_text_field( (string) $entry->source_object_id ),
				'source_type'         => sanitize_key( (string) $entry->source_type ),
				'ledger_id'           => (int) $entry->id,
				'source'              => ! empty( $metadata['source'] ) ? sanitize_key( (string) $metadata['source'] ) : 'ledger_backfill',
				'created_by'          => 0,
				'raw_data'            => wp_json_encode( array( 'ledger_metadata' => $metadata ) ),
				'updated_at'          => current_time( 'mysql' ),
			);

			$request_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

			if ( $existing ) {
				$wpdb->update(
					$this->get_portal_service_requests_table(),
					$request_data,
					array( 'id' => (int) $existing->id ),
					$request_formats,
					array( '%d' )
				);
				continue;
			}

			$request_data['created_at'] = ! empty( $entry->created_at ) ? $entry->created_at : current_time( 'mysql' );
			$request_formats[] = '%s';

			$wpdb->insert(
				$this->get_portal_service_requests_table(),
				$request_data,
				$request_formats
			);
	}
	}

	private function handle_service_requests_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->ensure_portal_schema();
		$this->backfill_portal_service_requests_from_ledger();
		$this->cleanup_stale_pending_checkout_service_requests();
		$this->handle_portal_service_request_details_save();

		if ( ! isset( $_GET['service_request_action'], $_GET['request_id'], $_GET['_wpnonce'] ) ) {
			return;
		}

		$request_id = absint( wp_unslash( $_GET['request_id'] ) );
		$action     = sanitize_key( wp_unslash( $_GET['service_request_action'] ) );
		check_admin_referer( 'ajcore_service_request_' . $request_id );

		if ( 'delete' === $action ) {
			$this->handle_portal_service_request_delete( $request_id );
			wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'service-requests', 'service-request-deleted' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'apply_billing_change' === $action ) {
			$result = $this->apply_portal_billing_change_request( $request_id );
			$args = array( 'page' => 'ajforms-client-portal', 'tab' => 'service-requests' );
			if ( is_wp_error( $result ) ) {
				$args['service-request-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['service-request-updated'] = 1;
				$args['billing-change-applied'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$action_status_map = array(
			'under_review'     => 'admin_review_required',
			'await_payment'    => 'awaiting_payment',
			'paid'             => 'paid',
			'complete'     => 'completed',
			'cancel'       => 'cancelled',
			'failed'       => 'failed',
		);
		if ( ! isset( $action_status_map[ $action ] ) ) {
			return;
		}

		global $wpdb;
		$status = $action_status_map[ $action ];
		$request = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->get_portal_service_requests_table()} WHERE id = %d", $request_id ) );
		if ( $request ) {
			$wpdb->update(
				$this->get_portal_service_requests_table(),
				array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $request_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$ledger_status = 'pending_payment' === $status ? 'unpaid' : $status;
			$request->status = $status;
			$this->sync_portal_service_request_to_ledger(
				$request,
				array(
					'status' => $ledger_status,
				)
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'service-requests', 'service-request-updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function display_service_requests_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;
		$this->ensure_portal_schema();
		$this->backfill_portal_service_requests_from_ledger();
		$this->cleanup_stale_pending_checkout_service_requests();

		$status_filter = isset( $_GET['request_status'] ) ? sanitize_key( wp_unslash( $_GET['request_status'] ) ) : '';
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$labels        = $this->get_portal_service_request_status_labels();
		$where         = array( '1=1' );
		$params        = array();
		$actionable_statuses = array( 'admin_review_required', 'pending_payment', 'awaiting_payment', 'paid', 'failed' );
		$show_actionable_default = '' === $status_filter && '' === $search;

		if ( 'all' === $status_filter ) {
			$status_filter = 'all';
		} elseif ( '' !== $status_filter && isset( $labels[ $status_filter ] ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $status_filter;
		} elseif ( $show_actionable_default ) {
			$where[] = "r.status IN ('admin_review_required','pending_payment','awaiting_payment','paid','failed')";
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(r.service_name LIKE %s OR r.stripe_customer_id LIKE %s OR c.email LIKE %s OR c.name LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$sql = "SELECT r.*, c.email AS customer_email, c.name AS customer_name, u.user_email AS wp_user_email, u.display_name AS wp_display_name
			FROM {$this->get_portal_service_requests_table()} r
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = r.stripe_customer_id
			LEFT JOIN {$wpdb->users} u ON u.ID = r.wp_user_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY r.created_at DESC, r.id DESC
			LIMIT 200";
		$requests = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->get_portal_service_requests_table()} GROUP BY status", OBJECT_K );
		$base_url = add_query_arg( array( 'page' => 'ajforms-service-requests' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap ajforms-service-requests-admin">
			<h1><?php esc_html_e( 'Service Requests', 'ajforms' ); ?></h1>
			<?php if ( isset( $_GET['service-request-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Service request updated.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['service-request-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['service-request-error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['service-request-deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Service request deleted.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Review client add-service checkout attempts, paid purchases, and custom upgrade requests from one place.', 'ajforms' ); ?></p>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo '' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Needs Action', 'ajforms' ); ?></a></li>
				<li> | <a href="<?php echo esc_url( add_query_arg( 'request_status', 'all', $base_url ) ); ?>" class="<?php echo 'all' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'ajforms' ); ?></a></li>
				<?php foreach ( $labels as $status_key => $status_label ) : ?>
					<?php $count = isset( $counts[ $status_key ] ) ? (int) $counts[ $status_key ]->total : 0; ?>
					<li> | <a href="<?php echo esc_url( add_query_arg( 'request_status', $status_key, $base_url ) ); ?>" class="<?php echo $status_filter === $status_key ? 'current' : ''; ?>"><?php echo esc_html( $status_label . ' (' . $count . ')' ); ?></a></li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="clear:both;margin:14px 0;display:flex;gap:8px;align-items:center;">
				<input type="hidden" name="page" value="ajforms-service-requests">
				<?php if ( '' !== $status_filter ) : ?><input type="hidden" name="request_status" value="<?php echo esc_attr( $status_filter ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer or service', 'ajforms' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'ajforms' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Request', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Created', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No requests found.', 'ajforms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $requests as $request ) : ?>
						<?php
						$customer_label = $request->customer_name ? $request->customer_name : ( $request->customer_email ? $request->customer_email : $request->stripe_customer_id );
						$status_label = isset( $labels[ $request->status ] ) ? $labels[ $request->status ] : ucfirst( str_replace( '_', ' ', $request->status ) );
						$request_product = ! empty( $request->stripe_price_id ) ? $this->get_portal_product_by_price_id( $request->stripe_price_id ) : null;
						$request_price_label = $request_product ? $this->get_portal_product_admin_label( $request_product ) : '';
						$actions = array(
							'under_review'  => __( 'Under Review', 'ajforms' ),
							'await_payment' => __( 'Awaiting Payment', 'ajforms' ),
							'paid'          => __( 'Paid', 'ajforms' ),
							'complete'     => __( 'Complete', 'ajforms' ),
							'cancel'       => __( 'Cancel', 'ajforms' ),
							'failed'       => __( 'Failed', 'ajforms' ),
							'apply_billing_change' => __( 'Apply Billing Change', 'ajforms' ),
							'delete'       => __( 'Delete', 'ajforms' ),
						);
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $request->service_name ? $request->service_name : __( 'Service Request', 'ajforms' ) ); ?></strong><br>
								<small><?php echo esc_html( $request->request_type ); ?><?php echo $request_price_label ? ' · ' . esc_html( $request_price_label ) : ( $request->stripe_price_id ? ' · ' . esc_html( $request->stripe_price_id ) : '' ); ?></small>
							</td>
							<td>
								<?php echo esc_html( $customer_label ); ?><br>
								<small><?php echo esc_html( $request->wp_user_email ? $request->wp_user_email : $request->stripe_customer_id ); ?></small><br>
								<a href="<?php echo esc_url( $this->get_portal_customer_360_url( $request->stripe_customer_id ) ); ?>"><?php esc_html_e( 'Customer 360', 'ajforms' ); ?></a>
							</td>
							<td><strong><?php echo esc_html( $status_label ); ?></strong></td>
							<td><?php echo esc_html( strtoupper( (string) $request->currency ) . ' ' . number_format_i18n( (float) $request->amount, 2 ) ); ?></td>
							<td><?php echo esc_html( $request->source ? $request->source : $request->source_type ); ?></td>
							<td><?php echo esc_html( $request->created_at ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $request->created_at . ' UTC' ) ) : '-' ); ?></td>
							<td>
								<?php foreach ( $actions as $action_key => $action_label ) : ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-service-requests', 'service_request_action' => $action_key, 'request_id' => (int) $request->id ), admin_url( 'admin.php' ) ), 'ajcore_service_request_' . (int) $request->id ) ); ?>"><?php echo esc_html( $action_label ); ?></a>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function add_plugin_admin_menu() {
		add_menu_page(
			__( 'AJ Core', 'ajforms' ),
			__( 'AJ Core', 'ajforms' ),
			'manage_options',
			'ajforms',
			array( $this, 'display_forms_page' ),
			'dashicons-feedback',
			25
		);

		add_submenu_page(
			'ajforms',
			__( 'Forms', 'ajforms' ),
			__( 'Forms', 'ajforms' ),
			'manage_options',
			'ajforms',
			array( $this, 'display_forms_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'CRM', 'ajforms' ),
			__( 'CRM', 'ajforms' ),
			'manage_options',
			'ajforms-leads',
			array( $this, 'display_leads_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'Products', 'ajforms' ),
			__( 'Products', 'ajforms' ),
			'manage_options',
			'ajforms-products',
			array( $this, 'display_products_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'Client Portal', 'ajforms' ),
			__( 'Client Portal', 'ajforms' ),
			'manage_options',
			'ajforms-client-portal',
			array( $this, 'display_client_portal_page' )
		);


		add_submenu_page(
			'ajforms',
			__( 'Auth', 'ajforms' ),
			__( 'Auth', 'ajforms' ),
			'manage_options',
			'ajforms-auth',
			array( $this, 'display_auth_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'Automations', 'ajforms' ),
			__( 'Automations', 'ajforms' ),
			'manage_options',
			'ajforms-automations',
			array( $this, 'display_automations_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'Settings', 'ajforms' ),
			__( 'Settings', 'ajforms' ),
			'manage_options',
			'ajforms-settings',
			array( $this, 'display_settings_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'Update AJ Core', 'ajforms' ),
			__( 'Update AJ Core', 'ajforms' ),
			'manage_options',
			'ajforms-about',
			array( $this, 'display_about_page' )
		);

	}

	public function add_plugin_action_links( $links ) {
		$custom_links = array(
			'update'   => '<a href="' . esc_url( $this->get_about_update_url( 'update' ) ) . '">' . esc_html__( 'Update AJ Core', 'ajforms' ) . '</a>',
		);

		return array_merge( $custom_links, $links );
	}

	public function add_plugin_row_meta_links( $links, $file ) {
		if ( AJFORMS_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( 'https://itspector.com/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'IT Spector LLC', 'ajforms' ) . '</a>';
		$links[] = '<a href="' . esc_url( $this->get_about_update_url( 'update' ) ) . '">' . esc_html__( 'Update AJ Core', 'ajforms' ) . '</a>';

		return $links;
	}

	public function display_forms_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			require_once AJFORMS_PLUGIN_DIR . 'admin/partials/ajforms-admin-builder.php';
		} else {
			require_once AJFORMS_PLUGIN_DIR . 'admin/class-ajforms-forms-list-table.php';
			require_once AJFORMS_PLUGIN_DIR . 'admin/partials/ajforms-admin-forms.php';
		}
	}

	public function display_leads_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$view    = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'list';
		$lead_id = isset( $_GET['lead_id'] ) ? absint( wp_unslash( $_GET['lead_id'] ) ) : 0;

		if ( 'detail' === $view && $lead_id ) {
			require_once AJFORMS_PLUGIN_DIR . 'admin/partials/ajforms-admin-lead-details.php';
			return;
		}

		require_once AJFORMS_PLUGIN_DIR . 'admin/class-ajforms-leads-list-table.php';
		require_once AJFORMS_PLUGIN_DIR . 'admin/partials/ajforms-admin-leads.php';
	}

	private function display_portal_service_requests_tab() {
		global $wpdb;

		$this->ensure_portal_schema();
		$this->backfill_portal_service_requests_from_ledger();
		$this->cleanup_stale_pending_checkout_service_requests();

		$status_filter = isset( $_GET['request_status'] ) ? sanitize_key( wp_unslash( $_GET['request_status'] ) ) : '';
		$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$labels        = $this->get_portal_service_request_status_labels();
		$where         = array( '1=1' );
		$params        = array();
		$actionable_statuses = array( 'admin_review_required', 'pending_payment', 'awaiting_payment', 'paid', 'failed' );
		$show_actionable_default = '' === $status_filter && '' === $search;

		if ( 'all' === $status_filter ) {
			$status_filter = 'all';
		} elseif ( '' !== $status_filter && isset( $labels[ $status_filter ] ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $status_filter;
		} elseif ( $show_actionable_default ) {
			$where[] = "r.status IN ('admin_review_required','pending_payment','awaiting_payment','paid','failed')";
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(r.service_name LIKE %s OR r.stripe_customer_id LIKE %s OR c.email LIKE %s OR c.name LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$sql = "SELECT r.*, c.email AS customer_email, c.name AS customer_name, u.user_email AS wp_user_email, u.display_name AS wp_display_name
			FROM {$this->get_portal_service_requests_table()} r
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = r.stripe_customer_id
			LEFT JOIN {$wpdb->users} u ON u.ID = r.wp_user_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY r.created_at DESC, r.id DESC
			LIMIT 200";
		$requests = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		$counts   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->get_portal_service_requests_table()} GROUP BY status", OBJECT_K );
		$base_url = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'service-requests' ), admin_url( 'admin.php' ) );
		$service_products = $this->get_portal_stripe_products_for_settings();
		?>
		<div class="ajcore-admin-panel">
			<h2><?php esc_html_e( 'Requests', 'ajforms' ); ?></h2>
			<?php if ( isset( $_GET['service-request-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Service request updated.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['service-request-deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Service request deleted.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['service-request-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['service-request-error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Review client checkout attempts, custom pricing requests, paid purchases, and other service-related items that need an admin decision.', 'ajforms' ); ?></p>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo '' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Needs Action', 'ajforms' ); ?></a></li>
				<li> | <a href="<?php echo esc_url( add_query_arg( 'request_status', 'all', $base_url ) ); ?>" class="<?php echo 'all' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'ajforms' ); ?></a></li>
				<?php foreach ( $labels as $status_key => $status_label ) : ?>
					<?php $count = isset( $counts[ $status_key ] ) ? (int) $counts[ $status_key ]->total : 0; ?>
					<li> | <a href="<?php echo esc_url( add_query_arg( 'request_status', $status_key, $base_url ) ); ?>" class="<?php echo $status_filter === $status_key ? 'current' : ''; ?>"><?php echo esc_html( $status_label . ' (' . $count . ')' ); ?></a></li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="clear:both;margin:14px 0;display:flex;gap:8px;align-items:center;">
				<input type="hidden" name="page" value="ajforms-client-portal">
				<input type="hidden" name="tab" value="service-requests">
				<?php if ( '' !== $status_filter ) : ?><input type="hidden" name="request_status" value="<?php echo esc_attr( $status_filter ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer or service', 'ajforms' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'ajforms' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Request', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Created', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No requests found.', 'ajforms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $requests as $request ) : ?>
						<?php
						$customer_label = $request->customer_name ? $request->customer_name : ( $request->customer_email ? $request->customer_email : $request->stripe_customer_id );
						$status_label   = isset( $labels[ $request->status ] ) ? $labels[ $request->status ] : ucfirst( str_replace( '_', ' ', $request->status ) );
						$request_product = ! empty( $request->stripe_price_id ) ? $this->get_portal_product_by_price_id( $request->stripe_price_id ) : null;
						$request_price_label = $request_product ? $this->get_portal_product_admin_label( $request_product ) : '';
						$actions        = array(
							'under_review'  => __( 'Under Review', 'ajforms' ),
							'await_payment' => __( 'Awaiting Payment', 'ajforms' ),
							'paid'          => __( 'Paid', 'ajforms' ),
							'complete'     => __( 'Complete', 'ajforms' ),
							'cancel'       => __( 'Cancel', 'ajforms' ),
							'failed'       => __( 'Failed', 'ajforms' ),
							'apply_billing_change' => __( 'Apply Billing Change', 'ajforms' ),
							'delete'       => __( 'Delete', 'ajforms' ),
						);
						$payment_url = $this->get_portal_service_request_meta_value( $request, 'payment_url' );
						$payment_reference = $this->get_portal_service_request_meta_value( $request, 'payment_reference' );
						$request_raw = $this->get_portal_service_request_raw_data( $request );
						$billing_preview = ! empty( $request_raw['billing_preview'] ) && is_array( $request_raw['billing_preview'] ) ? $request_raw['billing_preview'] : array();
						$current_product = ! empty( $request->stripe_price_id ) ? $this->get_portal_product_by_price_id( $request->stripe_price_id ) : null;
						$current_price_label = $current_product ? $this->get_portal_product_admin_label( $current_product ) : '';
						$save_details_nonce = wp_create_nonce( 'ajcore_service_request_details_' . (int) $request->id );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $request->service_name ? $request->service_name : __( 'Service Request', 'ajforms' ) ); ?></strong><br>
								<small><?php echo esc_html( $request->request_type ); ?><?php echo $request_price_label ? ' · ' . esc_html( $request_price_label ) : ( $request->stripe_price_id ? ' · ' . esc_html( $request->stripe_price_id ) : '' ); ?></small>
							</td>
							<td>
								<?php echo esc_html( $customer_label ); ?><br>
								<small><?php echo esc_html( $request->wp_user_email ? $request->wp_user_email : $request->stripe_customer_id ); ?></small><br>
								<a href="<?php echo esc_url( $this->get_portal_customer_360_url( $request->stripe_customer_id ) ); ?>"><?php esc_html_e( 'Customer 360', 'ajforms' ); ?></a>
							</td>
							<td><strong><?php echo esc_html( $status_label ); ?></strong></td>
							<td><?php echo esc_html( strtoupper( (string) $request->currency ) . ' ' . number_format_i18n( (float) $request->amount, 2 ) ); ?></td>
							<td><?php echo esc_html( $request->source ? $request->source : $request->source_type ); ?></td>
							<td><?php echo esc_html( $request->created_at ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $request->created_at . ' UTC' ) ) : '-' ); ?></td>
							<td>
								<?php foreach ( $actions as $action_key => $action_label ) : ?>
									<?php
									$action_url = wp_nonce_url(
										add_query_arg(
											array(
												'page'                   => 'ajforms-client-portal',
												'tab'                    => 'service-requests',
												'service_request_action' => $action_key,
												'request_id'             => (int) $request->id,
											),
											admin_url( 'admin.php' )
										),
										'ajcore_service_request_' . (int) $request->id
									);
									?>
									<a class="button button-small" href="<?php echo esc_url( $action_url ); ?>"<?php echo 'delete' === $action_key ? ' onclick="return confirm(\'' . esc_js( __( 'Delete this request? Safe linked non-Stripe ledger rows may also be removed.', 'ajforms' ) ) . '\');"' : ''; ?>><?php echo esc_html( $action_label ); ?></a>
								<?php endforeach; ?>

								<details style="margin-top:8px;max-width:420px;">
									<summary><?php esc_html_e( 'Payment / Fulfillment Details', 'ajforms' ); ?></summary>
									<form method="post" style="margin-top:8px;display:grid;gap:6px;">
										<input type="hidden" name="page" value="ajforms-client-portal">
										<input type="hidden" name="tab" value="service-requests">
										<input type="hidden" name="service_request_action" value="save_details">
										<input type="hidden" name="request_id" value="<?php echo esc_attr( (int) $request->id ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $save_details_nonce ); ?>">
										<label><?php esc_html_e( 'Service / Stripe Price', 'ajforms' ); ?><br>
											<select name="request_stripe_price_id" style="width:100%;max-width:100%;">
												<option value=""><?php esc_html_e( 'No synced Stripe price selected', 'ajforms' ); ?></option>
												<?php foreach ( $service_products as $product ) : ?>
													<?php
													$price_id = ! empty( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
													if ( '' === $price_id ) {
														continue;
													}
													?>
													<option value="<?php echo esc_attr( $price_id ); ?>" <?php selected( $request->stripe_price_id, $price_id ); ?>><?php echo esc_html( $this->get_portal_product_admin_label( $product ) ); ?></option>
												<?php endforeach; ?>
											</select>
											<?php if ( '' !== $current_price_label ) : ?><br><span class="description"><?php echo esc_html( sprintf( __( 'Current: %s', 'ajforms' ), $current_price_label ) ); ?></span><?php endif; ?>
										</label>
										<label><?php esc_html_e( 'Amount', 'ajforms' ); ?><br><input type="number" step="0.01" name="request_amount" value="<?php echo esc_attr( number_format( (float) $request->amount, 2, '.', '' ) ); ?>" style="width:120px;"> <input type="text" name="request_currency" value="<?php echo esc_attr( strtolower( (string) $request->currency ) ); ?>" style="width:70px;"></label>
										<label><?php esc_html_e( 'Payment URL', 'ajforms' ); ?><br><input type="url" name="payment_url" value="<?php echo esc_attr( $payment_url ); ?>" placeholder="https://" style="width:100%;"></label>
										<label><?php esc_html_e( 'Payment / Invoice Reference', 'ajforms' ); ?><br><input type="text" name="payment_reference" value="<?php echo esc_attr( $payment_reference ); ?>" style="width:100%;"></label>
										<details>
											<summary><?php esc_html_e( 'Billing Change Preview', 'ajforms' ); ?></summary>
											<div style="display:grid;gap:6px;margin-top:8px;">
												<label><?php esc_html_e( 'Stripe Subscription ID', 'ajforms' ); ?><br><input type="text" name="billing_subscription_id" value="<?php echo esc_attr( ! empty( $billing_preview['subscription_id'] ) ? $billing_preview['subscription_id'] : '' ); ?>" placeholder="sub_..." style="width:100%;"></label>
												<label><?php esc_html_e( 'Stripe Subscription Item ID', 'ajforms' ); ?><br><input type="text" name="billing_subscription_item_id" value="<?php echo esc_attr( ! empty( $billing_preview['subscription_item_id'] ) ? $billing_preview['subscription_item_id'] : '' ); ?>" placeholder="si_..." style="width:100%;"></label>
												<label><?php esc_html_e( 'Proration Behavior', 'ajforms' ); ?><br>
													<select name="billing_proration_behavior" style="width:100%;">
														<?php $selected_proration = ! empty( $billing_preview['proration_behavior'] ) ? sanitize_key( (string) $billing_preview['proration_behavior'] ) : 'create_prorations'; ?>
														<option value="create_prorations" <?php selected( $selected_proration, 'create_prorations' ); ?>><?php esc_html_e( 'Create prorations', 'ajforms' ); ?></option>
														<option value="none" <?php selected( $selected_proration, 'none' ); ?>><?php esc_html_e( 'No proration', 'ajforms' ); ?></option>
														<option value="always_invoice" <?php selected( $selected_proration, 'always_invoice' ); ?>><?php esc_html_e( 'Always invoice', 'ajforms' ); ?></option>
													</select>
												</label>
												<p class="description"><?php esc_html_e( 'Saving this preview uses the exact selected Stripe Price as the new price when Apply Billing Change is clicked.', 'ajforms' ); ?></p>
											</div>
										</details>
										<label><?php esc_html_e( 'Client-facing Note', 'ajforms' ); ?><br><textarea name="client_notes" rows="2" style="width:100%;"><?php echo esc_textarea( (string) $request->client_notes ); ?></textarea></label>
										<label><?php esc_html_e( 'Internal Admin Notes', 'ajforms' ); ?><br><textarea name="admin_notes" rows="2" style="width:100%;"><?php echo esc_textarea( (string) $request->admin_notes ); ?></textarea></label>
										<label><?php esc_html_e( 'After Save Status', 'ajforms' ); ?><br><select name="after_save_status"><option value=""><?php esc_html_e( 'Keep current status', 'ajforms' ); ?></option><option value="awaiting_payment"><?php esc_html_e( 'Awaiting Payment', 'ajforms' ); ?></option><option value="paid"><?php esc_html_e( 'Paid', 'ajforms' ); ?></option><option value="completed"><?php esc_html_e( 'Completed', 'ajforms' ); ?></option></select></label>
										<button class="button button-small button-primary" type="submit"><?php esc_html_e( 'Save Details', 'ajforms' ); ?></button>
									</form>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}


	private function handle_portal_billing_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['billing_export'], $_GET['_wpnonce'] ) && 'csv' === sanitize_key( wp_unslash( $_GET['billing_export'] ) ) ) {
			check_admin_referer( 'ajcore_export_billing_ledger' );
			$this->export_portal_billing_csv();
			exit;
		}

		$action = isset( $_POST['ajcore_billing_action'] ) ? sanitize_key( wp_unslash( $_POST['ajcore_billing_action'] ) ) : '';
		if ( 'add_manual_charge' !== $action ) {
			return;
		}

		check_admin_referer( 'ajcore_add_manual_charge', 'ajcore_manual_charge_nonce' );
		$this->ensure_portal_schema();

		$stripe_customer_id = isset( $_POST['stripe_customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_customer_id'] ) ) : '';
		$description        = isset( $_POST['charge_label'] ) ? sanitize_text_field( wp_unslash( $_POST['charge_label'] ) ) : '';
		$amount             = isset( $_POST['charge_amount'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['charge_amount'] ) ) : 0;
		$currency           = isset( $_POST['charge_currency'] ) ? strtolower( sanitize_key( wp_unslash( $_POST['charge_currency'] ) ) ) : 'usd';
		$client_notes       = isset( $_POST['client_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['client_notes'] ) ) : '';
		$admin_notes        = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';

		$redirect = add_query_arg(
			array(
				'page' => 'ajforms-client-portal',
				'tab'  => 'billing',
			),
			admin_url( 'admin.php' )
		);

		if ( '' === $stripe_customer_id || '' === $description || $amount <= 0 || '' === $currency ) {
			wp_safe_redirect( add_query_arg( 'billing-error', 'missing-fields', $redirect ) );
			exit;
		}

		global $wpdb;
		$customer_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
			)
		);

		if ( ! $customer_exists ) {
			wp_safe_redirect( add_query_arg( 'billing-error', 'customer-not-found', $redirect ) );
			exit;
		}

		$source_object_id = 'manual_charge_' . wp_generate_uuid4();
		$metadata = array(
			'source'       => 'manual_charge',
			'client_notes' => $client_notes,
			'admin_notes'  => $admin_notes,
			'created_by'   => get_current_user_id(),
		);

		$inserted = $wpdb->insert(
			$this->get_portal_ledger_table(),
			array(
				'stripe_customer_id' => $stripe_customer_id,
				'source_object_id'   => $source_object_id,
				'source_type'        => 'manual_charge',
				'ledger_date'        => current_time( 'mysql' ),
				'description'        => $description,
				'amount'             => $amount,
				'currency'           => $currency,
				'status'             => 'unpaid',
				'invoice_id'         => '',
				'payment_intent_id'  => '',
				'charge_id'          => '',
				'metadata'           => wp_json_encode( $metadata ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_safe_redirect( add_query_arg( 'billing-error', 'insert-failed', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'manual-charge-added', '1', $redirect ) );
		exit;
	}

	private function get_portal_billing_filter_data() {
		global $wpdb;

		$status_filter   = isset( $_GET['billing_status'] ) ? sanitize_key( wp_unslash( $_GET['billing_status'] ) ) : '';
		$source_filter   = isset( $_GET['billing_source'] ) ? sanitize_key( wp_unslash( $_GET['billing_source'] ) ) : '';
		$customer_filter = isset( $_GET['billing_customer'] ) ? sanitize_text_field( wp_unslash( $_GET['billing_customer'] ) ) : '';
		$view_filter     = isset( $_GET['billing_view'] ) ? sanitize_key( wp_unslash( $_GET['billing_view'] ) ) : '';
		$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$where           = array( '1=1' );
		$params          = array();
		$open_statuses   = $this->get_portal_open_ledger_statuses();

		if ( '' !== $status_filter ) {
			$where[]  = 'l.status = %s';
			$params[] = $status_filter;
		}

		if ( '' !== $source_filter ) {
			$where[]  = 'l.source_type = %s';
			$params[] = $source_filter;
		} else {
			$where[] = "l.source_type <> 'refund'";
		}

		if ( '' !== $customer_filter ) {
			$where[]  = 'l.stripe_customer_id = %s';
			$params[] = $customer_filter;
		}

		if ( 'open' === $view_filter ) {
			$where[] = 'l.status IN (' . implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) ) . ')';
			$params = array_merge( $params, $open_statuses );
		} elseif ( 'failed' === $view_filter ) {
			$failed_statuses = array( 'failed', 'payment_failed', 'requires_payment_method' );
			$where[] = 'l.status IN (' . implode( ',', array_fill( 0, count( $failed_statuses ), '%s' ) ) . ')';
			$params = array_merge( $params, $failed_statuses );
		} elseif ( 'overdue' === $view_filter ) {
			$ledger_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$this->get_portal_ledger_table()}", 0 );
			$date_expression = is_array( $ledger_columns ) && in_array( 'due_date', $ledger_columns, true ) ? 'COALESCE(NULLIF(l.due_date,\'\'), l.ledger_date)' : 'l.ledger_date';
			$where[] = 'l.status IN (' . implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) ) . ')';
			$params = array_merge( $params, $open_statuses );
			$where[] = "DATE({$date_expression}) < %s";
			$params[] = current_time( 'Y-m-d' );
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(l.description LIKE %s OR l.stripe_customer_id LIKE %s OR l.invoice_id LIKE %s OR c.email LIKE %s OR c.name LIKE %s)';
			$params = array_merge( $params, array( $like, $like, $like, $like, $like ) );
		}

		return array(
			'status'   => $status_filter,
			'source'   => $source_filter,
			'customer' => $customer_filter,
			'view'     => $view_filter,
			'search'   => $search,
			'where'    => $where,
			'params'   => $params,
		);
	}

	private function export_portal_billing_csv() {
		global $wpdb;

		$this->ensure_portal_schema();
		$filters = $this->get_portal_billing_filter_data();
		$sql = "SELECT l.*, c.email AS customer_email, c.name AS customer_name
			FROM {$this->get_portal_ledger_table()} l
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = l.stripe_customer_id
			WHERE " . implode( ' AND ', $filters['where'] ) . '
			ORDER BY l.ledger_date DESC, l.id DESC';
		$ledger = ! empty( $filters['params'] ) ? $wpdb->get_results( $wpdb->prepare( $sql, $filters['params'] ) ) : $wpdb->get_results( $sql );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ajcore-billing-ledger-' . gmdate( 'Ymd-His' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Date', 'Customer', 'Email', 'Stripe Customer ID', 'Description', 'Transaction ID', 'Source', 'Status', 'Debit', 'Credit', 'Amount', 'Currency', 'Invoice ID', 'Charge ID' ) );
		foreach ( $ledger as $entry ) {
			$debit_credit = $this->get_portal_ledger_debit_credit( $entry );
			fputcsv(
				$output,
				array(
					$entry->ledger_date,
					$entry->customer_name,
					$entry->customer_email,
					$entry->stripe_customer_id,
					$this->get_portal_ledger_display_description( $entry ),
					$this->get_portal_ledger_transaction_id( $entry ),
					$entry->source_type,
					$entry->status,
					$debit_credit['debit'],
					$debit_credit['credit'],
					$entry->amount,
					$entry->currency,
					$entry->invoice_id,
					$entry->charge_id,
				)
			);
		}
		fclose( $output );
	}


	private function display_portal_billing_tab() {
		global $wpdb;

		$this->ensure_portal_schema();
		$this->cleanup_unpaid_portal_checkout_sessions();
		$this->backfill_portal_ledger_service_charges_from_snapshots();

		$filters         = $this->get_portal_billing_filter_data();
		$status_filter   = $filters['status'];
		$source_filter   = $filters['source'];
		$customer_filter = $filters['customer'];
		$view_filter     = $filters['view'];
		$search          = $filters['search'];

		$sql = "SELECT l.*, c.email AS customer_email, c.name AS customer_name
			FROM {$this->get_portal_ledger_table()} l
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = l.stripe_customer_id
			WHERE " . implode( ' AND ', $filters['where'] ) . "
			ORDER BY l.ledger_date ASC, l.id ASC
			LIMIT 300";
		$ledger = ! empty( $filters['params'] ) ? $wpdb->get_results( $wpdb->prepare( $sql, $filters['params'] ) ) : $wpdb->get_results( $sql );
		$ledger = $this->get_admin_portal_display_ledger( $ledger );
		$balance_data = $this->get_portal_ledger_running_balances( $ledger );
		$running_balances = $balance_data['balances'];
		$customer_running_totals = $balance_data['totals'];
		$selected_customer_balance = '' !== $customer_filter && isset( $customer_running_totals[ $customer_filter ] ) ? (float) $customer_running_totals[ $customer_filter ] : 0.0;
		$open_balance = 0.0;
		$credit_balance = 0.0;
		$paid_total = 0.0;
		foreach ( $ledger as $entry ) {
			$effect = $this->get_portal_ledger_balance_effect( $entry );
			if ( $effect > 0 ) {
				$open_balance += $effect;
			} elseif ( $effect < 0 ) {
				$credit_balance += abs( $effect );
			}
			if ( $effect < 0 && in_array( sanitize_key( (string) $entry->status ), array( 'paid', 'succeeded', 'partially_refunded', 'partial_refund' ), true ) ) {
				$paid_total += abs( $effect );
			}
		}
		$totals = $wpdb->get_row( "SELECT COUNT(*) AS total_rows, COALESCE(SUM(amount),0) AS total_amount FROM {$this->get_portal_ledger_table()}" );
		$customers = $wpdb->get_results( "SELECT stripe_customer_id, email, name FROM {$this->get_portal_stripe_customers_table()} ORDER BY name ASC, email ASC, stripe_customer_id ASC LIMIT 500" );
		$base_url = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'billing' ), admin_url( 'admin.php' ) );
		$export_url = wp_nonce_url( add_query_arg( array_merge( $_GET, array( 'billing_export' => 'csv' ) ), admin_url( 'admin.php' ) ), 'ajcore_export_billing_ledger' );
		?>
		<div class="ajcore-admin-panel">
			<h2><?php esc_html_e( 'Master Billing', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Review all synced invoices, charges, checkout sessions, and client-created billing requests from one generic billing ledger.', 'ajforms' ); ?></p>
			<div class="ajforms-settings-inline-actions" style="margin:12px 0;">
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d records', 'ajforms' ), (int) $totals->total_rows ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( 'Open balance: %s', 'ajforms' ), $this->format_portal_money( $open_balance, 'usd' ) ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( 'Credit balance: %s', 'ajforms' ), $this->format_portal_money( $credit_balance, 'usd' ) ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( 'Paid total: %s', 'ajforms' ), $this->format_portal_money( $paid_total, 'usd' ) ) ); ?></span>
			</div>
			<?php if ( '' !== $customer_filter ) : ?>
				<p><strong><?php esc_html_e( 'Filtered Customer Balance:', 'ajforms' ); ?></strong> <?php echo esc_html( $this->format_portal_balance_amount( $selected_customer_balance, 'usd' ) ); ?></p>
			<?php else : ?>
				<p><em><?php esc_html_e( 'Select a customer filter to view that customer’s running ledger balance.', 'ajforms' ); ?></em></p>
			<?php endif; ?>

			<?php if ( isset( $_GET['manual-charge-added'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Customer charge added.', 'ajforms' ); ?></p></div>
			<?php elseif ( isset( $_GET['billing-error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Unable to add customer charge. Please check the customer, label, and amount.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<details class="ajcore-admin-card" style="margin:16px 0;padding:16px;border:1px solid #dcdcde;background:#fff;border-radius:8px;">
				<summary style="font-weight:700;cursor:pointer;"><?php esc_html_e( 'Add Customer Charge', 'ajforms' ); ?></summary>
				<form method="post" style="margin-top:14px;display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px;max-width:920px;">
					<input type="hidden" name="page" value="ajforms-client-portal">
					<input type="hidden" name="tab" value="billing">
					<input type="hidden" name="ajcore_billing_action" value="add_manual_charge">
					<?php wp_nonce_field( 'ajcore_add_manual_charge', 'ajcore_manual_charge_nonce' ); ?>
					<label style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;"><?php esc_html_e( 'Customer', 'ajforms' ); ?>
						<select name="stripe_customer_id" required>
							<option value=""><?php esc_html_e( 'Select customer', 'ajforms' ); ?></option>
							<?php foreach ( $customers as $customer ) : ?>
								<?php $customer_label = $customer->name ? $customer->name : ( $customer->email ? $customer->email : $customer->stripe_customer_id ); ?>
								<option value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>"><?php echo esc_html( $customer_label . ' — ' . $customer->stripe_customer_id ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label style="display:flex;flex-direction:column;gap:6px;"><?php esc_html_e( 'Charge Label', 'ajforms' ); ?><input type="text" name="charge_label" required placeholder="<?php esc_attr_e( 'Document filing fee', 'ajforms' ); ?>"></label>
					<label style="display:flex;flex-direction:column;gap:6px;"><?php esc_html_e( 'Amount', 'ajforms' ); ?><span><input type="number" min="0.01" step="0.01" name="charge_amount" required style="width:140px;"> <input type="text" name="charge_currency" value="usd" style="width:70px;"></span></label>
					<label style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;"><?php esc_html_e( 'Client-facing Note', 'ajforms' ); ?><textarea name="client_notes" rows="2" placeholder="<?php esc_attr_e( 'This note appears in the client billing ledger.', 'ajforms' ); ?>"></textarea></label>
					<label style="display:flex;flex-direction:column;gap:6px;grid-column:1/-1;"><?php esc_html_e( 'Internal Admin Notes', 'ajforms' ); ?><textarea name="admin_notes" rows="2"></textarea></label>
					<div style="grid-column:1/-1;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Add Charge', 'ajforms' ); ?></button></div>
				</form>
			</details>

			<form method="get" style="margin:14px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<input type="hidden" name="page" value="ajforms-client-portal">
				<input type="hidden" name="tab" value="billing">
				<?php if ( '' !== $view_filter ) : ?><input type="hidden" name="billing_view" value="<?php echo esc_attr( $view_filter ); ?>"><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer, invoice, or description', 'ajforms' ); ?>">
				<input type="text" name="billing_status" value="<?php echo esc_attr( $status_filter ); ?>" placeholder="<?php esc_attr_e( 'Status', 'ajforms' ); ?>">
				<input type="text" name="billing_source" value="<?php echo esc_attr( $source_filter ); ?>" placeholder="<?php esc_attr_e( 'Source type', 'ajforms' ); ?>">
				<select name="billing_customer">
					<option value=""><?php esc_html_e( 'All customers', 'ajforms' ); ?></option>
					<?php foreach ( $customers as $customer ) : ?>
						<?php $customer_label = $customer->name ? $customer->name : ( $customer->email ? $customer->email : $customer->stripe_customer_id ); ?>
						<option value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>" <?php selected( $customer_filter, $customer->stripe_customer_id ); ?>><?php echo esc_html( $customer_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'ajforms' ); ?></button>
				<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'ajforms' ); ?></a>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Description', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Transaction ID', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Debit', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Credit', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Running Balance', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Invoice / Charge', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $ledger ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'No billing records found.', 'ajforms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $ledger as $entry ) : ?>
						<?php $customer_label = $entry->customer_name ? $entry->customer_name : ( $entry->customer_email ? $entry->customer_email : $entry->stripe_customer_id ); ?>
						<?php $entry_debit_credit = $this->get_portal_ledger_debit_credit( $entry ); ?>
						<tr>
							<td><?php echo esc_html( $entry->ledger_date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->ledger_date . ' UTC' ) ) : '-' ); ?></td>
							<td><?php echo esc_html( $customer_label ); ?><br><small><?php echo esc_html( $entry->stripe_customer_id ); ?></small><br><a href="<?php echo esc_url( $this->get_portal_customer_360_url( $entry->stripe_customer_id ) ); ?>"><?php esc_html_e( 'Customer 360', 'ajforms' ); ?></a></td>
							<td><?php echo esc_html( $this->get_portal_ledger_display_description( $entry ) ); ?></td>
							<td><code><?php echo esc_html( $this->get_portal_ledger_transaction_id( $entry ) ? $this->get_portal_ledger_transaction_id( $entry ) : '-' ); ?></code></td>
							<td><?php echo esc_html( $entry->source_type ); ?></td>
							<td><strong><?php echo esc_html( $entry->status ); ?></strong></td>
							<td><?php echo esc_html( $entry_debit_credit['debit'] ? $entry_debit_credit['debit'] : '-' ); ?></td>
							<td><?php echo esc_html( $entry_debit_credit['credit'] ? $entry_debit_credit['credit'] : '-' ); ?></td>
							<td><?php echo esc_html( $this->format_portal_balance_amount( isset( $running_balances[ (int) $entry->id ] ) ? $running_balances[ (int) $entry->id ] : 0, $entry->currency ) ); ?></td>
							<td>
								<?php if ( $entry->invoice_id ) : ?><code><?php echo esc_html( $entry->invoice_id ); ?></code><br><?php endif; ?>
								<?php if ( $entry->charge_id ) : ?><code><?php echo esc_html( $entry->charge_id ); ?></code><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function get_portal_dashboard_url( $tab, $args = array() ) {
		$args = array_merge(
			array(
				'page' => 'ajforms-client-portal',
				'tab'  => sanitize_key( (string) $tab ),
			),
			(array) $args
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function get_portal_customer_360_url( $stripe_customer_id ) {
		return add_query_arg(
			array(
				'page'               => 'ajforms-client-portal',
				'tab'                => 'customer',
				'stripe_customer_id' => sanitize_text_field( (string) $stripe_customer_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	private function render_portal_dashboard_metric( $label, $value, $url = '', $note = '' ) {
		?>
		<a class="ajcore-dashboard-metric" href="<?php echo esc_url( $url ? $url : '#' ); ?>">
			<span class="ajcore-dashboard-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( (string) $value ); ?></strong>
			<?php if ( '' !== (string) $note ) : ?>
				<small><?php echo esc_html( (string) $note ); ?></small>
			<?php endif; ?>
		</a>
		<?php
	}

	private function get_portal_dashboard_task_metrics() {
		global $wpdb;

		$tasks_table         = $this->get_portal_tasks_table();
		$task_statuses_table = $this->get_portal_task_statuses_table();
		$today               = current_time( 'Y-m-d' );
		$closed_statuses     = array( 'completed', 'verified', 'closed', 'cancelled', 'canceled', 'archived' );
		$closed_placeholders = implode( ',', array_fill( 0, count( $closed_statuses ), '%s' ) );

		return array(
			'overdue' => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT t.id)
					FROM {$tasks_table} t
					LEFT JOIN {$task_statuses_table} ts ON ts.task_id = t.id AND ts.stripe_customer_id = t.stripe_customer_id
					WHERE t.due_date IS NOT NULL
					AND t.due_date < %s
					AND COALESCE(NULLIF(ts.status, ''), t.status) NOT IN ({$closed_placeholders})",
					array_merge( array( $today ), $closed_statuses )
				)
			),
		);
	}

	private function get_portal_dashboard_billing_metrics() {
		global $wpdb;

		$today          = current_time( 'Y-m-d' );
		$ledger         = $wpdb->get_results( "SELECT * FROM {$this->get_portal_ledger_table()}" );
		$open_balance   = 0.0;
		$overdue_amount = 0.0;
		$failed_count   = 0;

		foreach ( $ledger as $entry ) {
			$status   = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
			$effect   = $this->get_portal_ledger_balance_effect( $entry );
			$due_date = '';

			if ( $effect > 0 ) {
				$open_balance += $effect;
			}

			if ( in_array( $status, array( 'failed', 'payment_failed', 'requires_payment_method' ), true ) ) {
				$failed_count++;
			}

			if ( isset( $entry->due_date ) && ! empty( $entry->due_date ) ) {
				$due_date = substr( (string) $entry->due_date, 0, 10 );
			} elseif ( ! empty( $entry->ledger_date ) ) {
				$due_date = substr( (string) $entry->ledger_date, 0, 10 );
			}

			if ( $effect > 0 && '' !== $due_date && $due_date < $today && in_array( $status, $this->get_portal_open_ledger_statuses(), true ) ) {
				$overdue_amount += $effect;
			}
		}

		return array(
			'open_balance'   => $open_balance,
			'overdue_amount' => $overdue_amount,
			'failed_count'   => $failed_count,
		);
	}

	private function display_portal_dashboard_tab() {
		global $wpdb;

		$this->ensure_portal_schema();

		$customers_table        = $this->get_portal_stripe_customers_table();
		$subscriptions_table    = $this->get_portal_stripe_subscriptions_table();
		$service_requests_table = $this->get_portal_service_requests_table();
		$sync_logs_table        = $this->get_portal_sync_logs_table();

		$active_customers      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$customers_table} WHERE portal_status = 'active' AND enabled_portal = 1" );
		$active_subscription_objects = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subscriptions_table} WHERE status IN ('active','trialing')" );
		$active_subscription_rows = $wpdb->get_results(
			"SELECT s.*, c.name AS customer_name, c.email AS customer_email
			FROM {$subscriptions_table} s
			LEFT JOIN {$customers_table} c ON c.stripe_customer_id = s.stripe_customer_id
			WHERE s.status IN ('active','trialing')
			ORDER BY s.current_period_end ASC
			LIMIT 2000"
		);
		$active_recurring_services = $this->dedupe_portal_service_records( array_merge( $this->get_portal_service_records_from_subscriptions( $active_subscription_rows ), $this->get_portal_recurring_service_records_from_ledger( '', 2000 ) ) );
		$auto_pay_services    = count( $active_recurring_services );
		$one_time_services    = count( $this->get_portal_one_time_paid_services( '', 2000 ) );
		$active_services      = $auto_pay_services + $one_time_services;
		$billing_metrics      = $this->get_portal_dashboard_billing_metrics();
		$paid_not_completed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$service_requests_table} WHERE status = 'paid'" );
		$admin_review_required = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$service_requests_table} WHERE status = 'admin_review_required'" );
		$task_metrics         = $this->get_portal_dashboard_task_metrics();
		$stripe_mode          = $this->get_stripe_mode_badge_data();
		$last_success         = $wpdb->get_row( "SELECT * FROM {$sync_logs_table} WHERE status = 'success' ORDER BY finished_at DESC, started_at DESC, id DESC LIMIT 1" );
		$last_failed          = $wpdb->get_row( "SELECT * FROM {$sync_logs_table} WHERE status = 'failed' ORDER BY finished_at DESC, started_at DESC, id DESC LIMIT 1" );
		$last_webhook         = $wpdb->get_row( "SELECT * FROM {$sync_logs_table} WHERE source = 'webhook' ORDER BY started_at DESC, id DESC LIMIT 1" );
		$last_success_time    = $last_success ? strtotime( $last_success->finished_at ? $last_success->finished_at : $last_success->started_at ) : 0;
		$last_failed_time     = $last_failed ? strtotime( $last_failed->finished_at ? $last_failed->finished_at : $last_failed->started_at ) : 0;
		$health_has_issue     = ! empty( $stripe_mode['has_issues'] ) || ! $last_success || ! $last_webhook || ( $last_failed_time > $last_success_time );
		$health_note          = $last_success ? sprintf( __( 'Last sync: %s', 'ajforms' ), $last_success->finished_at ? $last_success->finished_at : $last_success->started_at ) : __( 'No successful sync found.', 'ajforms' );
		if ( $last_webhook ) {
			$health_note .= ' · ' . sprintf( __( 'Webhook: %s', 'ajforms' ), ucfirst( sanitize_key( (string) $last_webhook->status ) ) );
		} else {
			$health_note .= ' · ' . __( 'No webhook events yet.', 'ajforms' );
		}
		?>
		<div class="ajforms-settings-card ajcore-dashboard">
			<style>
				.ajcore-dashboard .ajcore-dashboard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:16px}.ajcore-dashboard .ajcore-dashboard-metric{display:flex;min-height:116px;flex-direction:column;justify-content:space-between;gap:10px;padding:16px 18px;border:1px solid #dcdcde;border-radius:10px;background:#fff;text-decoration:none;color:#1d2327;box-shadow:0 1px 2px rgba(0,0,0,.03)}.ajcore-dashboard .ajcore-dashboard-metric:hover{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}.ajcore-dashboard .ajcore-dashboard-label{font-weight:700;color:#50575e}.ajcore-dashboard .ajcore-dashboard-metric strong{font-size:30px;line-height:1.1;color:#1d2327}.ajcore-dashboard .ajcore-dashboard-metric small{color:#646970}.ajcore-dashboard .ajcore-dashboard-metric.is-alert strong{color:#b32d2e}.ajcore-dashboard .ajcore-dashboard-context{margin-top:20px;color:#646970}.ajcore-dashboard .ajcore-dashboard-context a{margin-right:12px}
			</style>
			<h2><?php esc_html_e( 'Dashboard', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Read-only operating dashboard focused on items that need attention.', 'ajforms' ); ?></p>

			<div class="ajcore-dashboard-grid">
				<?php $this->render_portal_dashboard_metric( __( 'Active Clients', 'ajforms' ), $active_customers, $this->get_portal_dashboard_url( 'portal-users', array( 'portal_user_status' => 'active' ) ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Sold Items', 'ajforms' ), $active_services, $this->get_portal_dashboard_url( 'sold-items' ), sprintf( __( '%1$d auto-pay services, %2$d one-time paid.', 'ajforms' ), $auto_pay_services, $one_time_services ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Auto-Pay Subscriptions', 'ajforms' ), $auto_pay_services, $this->get_portal_dashboard_url( 'sold-items', array( 'sold_type' => 'recurring' ) ), sprintf( __( '%d active Stripe subscription records.', 'ajforms' ), $active_subscription_objects ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Total Money Owed', 'ajforms' ), $this->format_portal_money( $billing_metrics['open_balance'], 'usd' ), $this->get_portal_dashboard_url( 'billing', array( 'billing_view' => 'open' ) ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Overdue Amount', 'ajforms' ), $this->format_portal_money( $billing_metrics['overdue_amount'], 'usd' ), $this->get_portal_dashboard_url( 'billing', array( 'billing_view' => 'overdue' ) ), __( 'Uses due date, falling back to ledger date.', 'ajforms' ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Overdue Tasks', 'ajforms' ), $task_metrics['overdue'], $this->get_portal_dashboard_url( 'tasks', array( 'task_view' => 'overdue' ) ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Paid Not Completed', 'ajforms' ), $paid_not_completed, $this->get_portal_dashboard_url( 'service-requests', array( 'request_status' => 'paid' ) ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Admin Review Required', 'ajforms' ), $admin_review_required, $this->get_portal_dashboard_url( 'service-requests', array( 'request_status' => 'admin_review_required' ) ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Failed Payments', 'ajforms' ), $billing_metrics['failed_count'], $this->get_portal_dashboard_url( 'billing', array( 'billing_view' => 'failed' ) ) ); ?>
				<?php $this->render_portal_dashboard_metric( __( 'Sync / Webhook Health', 'ajforms' ), $health_has_issue ? __( 'Review', 'ajforms' ) : __( 'OK', 'ajforms' ), $this->get_portal_dashboard_url( 'sync', $last_failed ? array( 'sync_log_id' => (int) $last_failed->id ) : array() ), $health_note ); ?>
			</div>

			<div class="ajcore-dashboard-context">
				<strong><?php echo esc_html( sprintf( __( 'Stripe mode: %s', 'ajforms' ), $stripe_mode['label'] ) ); ?></strong>
				<a href="<?php echo esc_url( $this->get_portal_dashboard_url( 'sync' ) ); ?>"><?php esc_html_e( 'View Sync History', 'ajforms' ); ?></a>
				<a href="<?php echo esc_url( $this->get_portal_dashboard_url( 'billing' ) ); ?>"><?php esc_html_e( 'Open Billing Workspace', 'ajforms' ); ?></a>
				<a href="<?php echo esc_url( $this->get_portal_dashboard_url( 'service-requests' ) ); ?>"><?php esc_html_e( 'Review Requests', 'ajforms' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function display_client_portal_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$this->ensure_portal_schema();

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tab      = in_array( $tab, array( 'dashboard', 'file-library', 'sync', 'event-log', 'menu', 'portal-users', 'sold-items', 'products-services', 'billing', 'service-requests', 'tasks', 'customer' ), true ) ? $tab : 'dashboard';
		$base_url = add_query_arg( array( 'page' => 'ajforms-client-portal' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap ajforms-client-portal-admin">
			<style>
				.ajforms-client-portal-admin .ajcore-status-pill{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;background:#f0f6fc;color:#0969da}
				.ajforms-client-portal-admin .ajcore-status-pill.off{background:#f6f7f7;color:#646970}
				.ajforms-client-portal-admin .ajcore-status-pill.active{background:#dcfce7;color:#166534}
				.ajforms-client-portal-admin .ajcore-status-pill.disabled{background:#fef3c7;color:#92400e}
				.ajforms-client-portal-admin .ajcore-status-pill.archived{background:#fee2e2;color:#991b1b}
				.ajforms-client-portal-admin .ajcore-status-pill.no-login{background:#f1f5f9;color:#475569}
				.ajforms-client-portal-admin .ajcore-portal-users-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:14px 0}
				.ajforms-client-portal-admin .ajcore-portal-users-toolbar form{display:flex;align-items:center;gap:8px;margin:0}
				.ajforms-client-portal-admin .ajcore-portal-users-toolbar .ajcore-toolbar-spacer{margin-left:auto}
				.ajforms-client-portal-admin .ajcore-portal-users-table tr.ajcore-row-active td{background:#f6fff8}
				.ajforms-client-portal-admin .ajcore-portal-users-table tr.ajcore-row-disabled td{background:#fffaf0}
				.ajforms-client-portal-admin .ajcore-portal-users-table tr.ajcore-row-archived td{background:#fff7f7}
				.ajforms-client-portal-admin .ajcore-portal-users-table tr.ajcore-row-no-login td{background:#f8fafc}
			</style>
			<h1><?php esc_html_e( 'Client Portal', 'ajforms' ); ?></h1>
			<?php if ( 'customer' !== $tab ) : ?>
				<h2 class="nav-tab-wrapper">
					<a class="nav-tab <?php echo 'dashboard' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'dashboard', $base_url ) ); ?>"><?php esc_html_e( 'Dashboard', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'service-requests' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'service-requests', $base_url ) ); ?>"><?php esc_html_e( 'Requests', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'billing' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'billing', $base_url ) ); ?>"><?php esc_html_e( 'Billing', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'portal-users' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'portal-users', $base_url ) ); ?>"><?php esc_html_e( 'Customers', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'sold-items' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'sold-items', $base_url ) ); ?>"><?php esc_html_e( 'Sold Items', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'products-services' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'products-services', $base_url ) ); ?>"><?php esc_html_e( 'Product Catalog', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'tasks' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'tasks', $base_url ) ); ?>"><?php esc_html_e( 'Tasks', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'file-library' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'file-library', $base_url ) ); ?>"><?php esc_html_e( 'File Library', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'sync' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'sync', $base_url ) ); ?>"><?php esc_html_e( 'Sync', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'event-log' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'event-log', $base_url ) ); ?>"><?php esc_html_e( 'Event Log', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'menu' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'menu', $base_url ) ); ?>"><?php esc_html_e( 'Menu', 'ajforms' ); ?></a>
				</h2>
			<?php endif; ?>
			<?php
			if ( 'customer' === $tab ) {
				$this->display_portal_customer_detail_page();
			} elseif ( 'dashboard' === $tab ) {
				$this->display_portal_dashboard_tab();
			} elseif ( 'sync' === $tab ) {
				$this->display_portal_sync_tab();
			} elseif ( 'event-log' === $tab ) {
				$this->display_portal_event_log_tab();
			} elseif ( 'portal-users' === $tab ) {
				$this->display_portal_users_tab();
			} elseif ( 'sold-items' === $tab ) {
				$this->display_portal_sold_items_tab();
			} elseif ( 'products-services' === $tab ) {
				$this->display_portal_products_services_tab();
			} elseif ( 'billing' === $tab ) {
				$this->display_portal_billing_tab();
			} elseif ( 'service-requests' === $tab ) {
				$this->display_portal_service_requests_tab();
			} elseif ( 'tasks' === $tab ) {
				$this->display_portal_tasks_tab();
			} elseif ( 'menu' === $tab ) {
				$this->display_client_portal_settings_tab( 'menu', true );
			} else {
				$this->display_file_library_page( true );
			}
			?>
		</div>
		<?php
	}

	private function display_portal_event_log_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;

		$table = $this->get_portal_event_log_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The portal event log table is not available yet. Deactivate and reactivate AJ Core or run the schema check by opening Client Portal again.', 'ajforms' ) . '</p></div>';
			return;
		}

		$event_type         = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : '';
		$severity           = isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '';
		$source             = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$stripe_customer_id = isset( $_GET['stripe_customer_id'] ) ? sanitize_text_field( wp_unslash( $_GET['stripe_customer_id'] ) ) : '';
		$search             = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $event_type ) {
			$where[]  = 'event_type = %s';
			$params[] = $event_type;
		}
		if ( '' !== $severity ) {
			$where[]  = 'severity = %s';
			$params[] = $severity;
		}
		if ( '' !== $source ) {
			$where[]  = 'source = %s';
			$params[] = $source;
		}
		if ( '' !== $stripe_customer_id ) {
			$where[]  = 'stripe_customer_id = %s';
			$params[] = $stripe_customer_id;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(event_type LIKE %s OR correlation_id LIKE %s OR stripe_customer_id LIKE %s OR email_before LIKE %s OR email_after LIKE %s OR actor_email LIKE %s OR details LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT 300';
		$rows = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type ASC" );
		$sources     = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} ORDER BY source ASC" );
		$severities  = array( 'debug', 'info', 'warning', 'error', 'critical' );
		$base_url    = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'event-log' ), admin_url( 'admin.php' ) );
		?>
		<h2><?php esc_html_e( 'Event Log', 'ajforms' ); ?></h2>
		<p><?php esc_html_e( 'Audit trail for portal mapping, role, lifecycle, auth, sync preservation, repair, and provisioning events.', 'ajforms' ); ?></p>

		<form method="get" class="ajcore-filter-bar" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0 16px;">
			<input type="hidden" name="page" value="ajforms-client-portal">
			<input type="hidden" name="tab" value="event-log">
			<select name="event_type">
				<option value=""><?php esc_html_e( 'All events', 'ajforms' ); ?></option>
				<?php foreach ( (array) $event_types as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $event_type, $type ); ?>><?php echo esc_html( $type ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="severity">
				<option value=""><?php esc_html_e( 'All severities', 'ajforms' ); ?></option>
				<?php foreach ( $severities as $level ) : ?>
					<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $severity, $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="source">
				<option value=""><?php esc_html_e( 'All sources', 'ajforms' ); ?></option>
				<?php foreach ( (array) $sources as $item_source ) : ?>
					<option value="<?php echo esc_attr( $item_source ); ?>" <?php selected( $source, $item_source ); ?>><?php echo esc_html( $item_source ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="stripe_customer_id" value="<?php echo esc_attr( $stripe_customer_id ); ?>" placeholder="<?php echo esc_attr__( 'Stripe customer ID', 'ajforms' ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search event log', 'ajforms' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ajforms' ); ?></button>
			<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'ajforms' ); ?></a>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Event', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'WP User', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Email', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Portal Status', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Actor', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Correlation', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Details', 'ajforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="11"><?php esc_html_e( 'No portal events found.', 'ajforms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$details = json_decode( (string) $row->details, true );
						$details = is_array( $details ) ? $details : array();
						$json    = wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><strong><?php echo esc_html( $row->event_type ); ?></strong></td>
							<td><span class="ajcore-status-pill <?php echo esc_attr( sanitize_html_class( (string) $row->severity ) ); ?>"><?php echo esc_html( ucfirst( (string) $row->severity ) ); ?></span></td>
							<td>
								<?php if ( ! empty( $row->customer_id ) ) : ?>
									#<?php echo esc_html( (int) $row->customer_id ); ?><br>
								<?php endif; ?>
								<code><?php echo esc_html( $row->stripe_customer_id ); ?></code>
							</td>
							<td><?php echo esc_html( (int) $row->wp_user_id_before ); ?> &rarr; <?php echo esc_html( (int) $row->wp_user_id_after ); ?></td>
							<td><?php echo esc_html( $row->email_before ); ?><br>&rarr; <?php echo esc_html( $row->email_after ); ?></td>
							<td><?php echo esc_html( $row->portal_status_before ); ?><br>&rarr; <?php echo esc_html( $row->portal_status_after ); ?></td>
							<td>
								<?php echo esc_html( (int) $row->actor_user_id ); ?>
								<?php if ( ! empty( $row->actor_email ) ) : ?><br><?php echo esc_html( $row->actor_email ); ?><?php endif; ?>
							</td>
							<td><?php echo esc_html( $row->source ); ?><br><code><?php echo esc_html( $row->site_uuid ); ?></code></td>
							<td><code><?php echo esc_html( $row->correlation_id ); ?></code></td>
							<td>
								<?php if ( ! empty( $details ) ) : ?>
									<details>
										<summary><?php esc_html_e( 'View JSON', 'ajforms' ); ?></summary>
										<pre style="white-space:pre-wrap;max-width:360px;overflow:auto;"><?php echo esc_html( $json ); ?></pre>
									</details>
								<?php else : ?>
									-
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function display_portal_customer_detail_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$stripe_customer_id = isset( $_GET['stripe_customer_id'] ) ? sanitize_text_field( wp_unslash( $_GET['stripe_customer_id'] ) ) : '';
		$detail             = $this->get_portal_customer_detail_data( $stripe_customer_id );
		$back_url           = add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'menu' ), admin_url( 'admin.php' ) );

		if ( ! $detail ) {
			?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Portal customer was not found in the synced Stripe customer cache.', 'ajforms' ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Back to Client Portal', 'ajforms' ); ?></a></p>
			<?php
			return;
		}

		$customer     = $detail['customer'];
		$user         = $detail['user'];
		$active_services_count = count( $detail['active_recurring_services'] ) + count( $detail['one_time_services'] );
		$portal_status = ! empty( $customer->portal_status ) ? sanitize_key( (string) $customer->portal_status ) : ( ! empty( $customer->enabled_portal ) ? 'active' : 'disabled' );
		$portal_on    = 'active' === $portal_status && ! empty( $customer->enabled_portal ) && $user;
		$display_fields = $this->get_portal_customer_display_fields();
		$sync_url     = wp_nonce_url(
			add_query_arg(
				array(
					'page'               => 'ajforms-client-portal',
					'tab'                => 'customer',
					'stripe_customer_id' => $customer->stripe_customer_id,
					'customer_action'    => 'sync_customer',
				),
				admin_url( 'admin.php' )
			),
			'ajcore_portal_customer_sync_' . $customer->stripe_customer_id
		);
		?>
		<style>
			.ajcore-customer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:16px 0 18px}
			.ajcore-customer-head h2{margin:0 0 6px;font-size:24px}
			.ajcore-customer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:1180px}
			.ajcore-customer-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px}
			.ajcore-customer-card h3{margin:0 0 14px;font-size:16px}
			.ajcore-customer-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:0 0 16px}
			.ajcore-customer-summary div{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px}
			.ajcore-customer-summary span{display:block;color:#646970;font-weight:600}
			.ajcore-customer-summary strong{display:block;margin-top:8px;font-size:22px}
			.ajcore-customer-meta{display:grid;grid-template-columns:150px minmax(0,1fr);gap:8px 14px}
			.ajcore-customer-meta dt{font-weight:600;color:#50575e}
			.ajcore-customer-meta dd{margin:0;overflow-wrap:anywhere}
			.ajcore-customer-wide{grid-column:1/-1}
			.ajcore-status-pill{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;background:#f0f6fc;color:#0969da}
			.ajcore-status-pill.off{background:#f6f7f7;color:#646970}
			.ajcore-customer-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
			.ajcore-customer-actions form{display:flex;gap:8px;align-items:center;margin:0}
			.ajcore-customer-actions input[type="email"]{min-width:280px}
			.ajcore-customer-card table{margin-top:8px}
			.ajcore-customer-activity{margin:0;padding:0;list-style:none}
			.ajcore-customer-activity li{border-left:3px solid #dbeafe;padding:8px 0 8px 12px;margin:0 0 10px}
			.ajcore-customer-quick-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}
			@media (max-width: 960px){.ajcore-customer-grid{grid-template-columns:1fr}.ajcore-customer-head{display:block}.ajcore-customer-meta{grid-template-columns:1fr}}
		</style>

		<?php if ( isset( $_GET['portal-updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Portal customer updated.', 'ajforms' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['portal-synced'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Synced %d Stripe records for this customer.', 'ajforms' ), absint( wp_unslash( $_GET['portal-synced'] ) ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['portal-error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['portal-fields-updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Display fields saved.', 'ajforms' ); ?></p></div>
		<?php endif; ?>

		<div class="ajcore-customer-head">
			<div>
				<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Client Portal', 'ajforms' ); ?></a></p>
				<h2><?php esc_html_e( 'Customer 360', 'ajforms' ); ?>: <?php echo esc_html( ! empty( $customer->name ) ? $customer->name : $customer->email ); ?></h2>
				<code><?php echo esc_html( $customer->stripe_customer_id ); ?></code>
			</div>
			<div class="ajcore-customer-actions">
				<a class="button" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Sync This Customer', 'ajforms' ); ?></a>
				<form method="post">
					<?php wp_nonce_field( 'ajcore_customer_access_' . $customer->stripe_customer_id, 'ajcore_customer_access_nonce' ); ?>
					<input type="hidden" name="stripe_customer_id" value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>">
					<input type="hidden" name="portal_customer_action" value="<?php echo esc_attr( $portal_on ? 'disable' : 'enable' ); ?>">
					<button type="submit" class="button <?php echo esc_attr( $portal_on ? '' : 'button-primary' ); ?>"><?php echo $portal_on ? esc_html__( 'Disable Portal Access', 'ajforms' ) : esc_html__( 'Enable Portal Access', 'ajforms' ); ?></button>
				</form>
				<?php if ( 'archived' !== $portal_status ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'ajcore_customer_access_' . $customer->stripe_customer_id, 'ajcore_customer_access_nonce' ); ?>
						<input type="hidden" name="stripe_customer_id" value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>">
						<input type="hidden" name="portal_customer_action" value="archive">
						<button type="submit" class="button"><?php esc_html_e( 'Archive Portal User', 'ajforms' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="ajcore-customer-summary">
			<div><span><?php esc_html_e( 'Portal Status', 'ajforms' ); ?></span><strong><?php echo esc_html( 'active' === $portal_status ? __( 'Active', 'ajforms' ) : ucfirst( $portal_status ) ); ?></strong></div>
			<div><span><?php esc_html_e( 'Active Services', 'ajforms' ); ?></span><strong><?php echo esc_html( $active_services_count ); ?></strong></div>
			<div><span><?php esc_html_e( 'Auto-Pay Subscriptions', 'ajforms' ); ?></span><strong><?php echo esc_html( count( $detail['active_recurring_services'] ) ); ?></strong></div>
			<div><span><?php esc_html_e( 'Open Balance', 'ajforms' ); ?></span><strong><?php echo esc_html( $this->format_portal_money( $detail['balance']['open_balance'], 'usd' ) ); ?></strong></div>
			<div><span><?php esc_html_e( 'Credit Balance', 'ajforms' ); ?></span><strong><?php echo esc_html( $this->format_portal_money( $detail['balance']['credit_balance'], 'usd' ) ); ?></strong></div>
			<div><span><?php esc_html_e( 'Requests', 'ajforms' ); ?></span><strong><?php echo esc_html( count( $detail['requests'] ) ); ?></strong></div>
			<div><span><?php esc_html_e( 'Tasks', 'ajforms' ); ?></span><strong><?php echo esc_html( count( $detail['tasks'] ) ); ?></strong></div>
		</div>

		<div class="ajcore-customer-grid">
			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Stripe Customer Profile', 'ajforms' ); ?></h3>
				<dl class="ajcore-customer-meta">
					<dt><?php esc_html_e( 'Name', 'ajforms' ); ?></dt><dd><?php echo esc_html( $customer->name ); ?></dd>
					<dt><?php esc_html_e( 'Email', 'ajforms' ); ?></dt><dd><?php echo esc_html( $customer->email ); ?></dd>
					<dt><?php esc_html_e( 'Phone', 'ajforms' ); ?></dt><dd><?php echo esc_html( $customer->phone ); ?></dd>
					<dt><?php esc_html_e( 'Mode', 'ajforms' ); ?></dt><dd><?php echo ! empty( $customer->livemode ) ? esc_html__( 'Live', 'ajforms' ) : esc_html__( 'Sandbox', 'ajforms' ); ?></dd>
					<dt><?php esc_html_e( 'Created', 'ajforms' ); ?></dt><dd><?php echo esc_html( $this->format_portal_date( $customer->created_at ) ); ?></dd>
					<dt><?php esc_html_e( 'Last Synced', 'ajforms' ); ?></dt><dd><?php echo esc_html( $this->format_portal_date( $customer->synced_at ) ); ?></dd>
					<?php foreach ( $display_fields as $field ) : ?>
						<dt><?php echo esc_html( $this->format_portal_customer_field_label( $field ) ); ?></dt><dd><?php echo esc_html( $this->get_portal_customer_display_value( $customer, $field ) ); ?></dd>
					<?php endforeach; ?>
				</dl>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Portal Access', 'ajforms' ); ?></h3>
				<dl class="ajcore-customer-meta">
					<dt><?php esc_html_e( 'Status', 'ajforms' ); ?></dt><dd><span class="ajcore-status-pill <?php echo esc_attr( $portal_on ? '' : 'off' ); ?>"><?php echo esc_html( 'active' === $portal_status ? __( 'Enabled', 'ajforms' ) : ( 'archived' === $portal_status ? __( 'Archived', 'ajforms' ) : __( 'Disabled', 'ajforms' ) ) ); ?></span></dd>
					<dt><?php esc_html_e( 'WordPress User', 'ajforms' ); ?></dt><dd>
						<?php
						if ( $user ) {
							echo esc_html( $user->display_name . ' #' . $user->ID . ' (' . $user->user_email . ')' );
						} else {
							esc_html_e( 'Not linked', 'ajforms' );
						}
						?>
					</dd>
				</dl>
				<hr>
				<form method="post" class="ajcore-customer-actions">
					<?php wp_nonce_field( 'ajcore_customer_relink_' . $customer->stripe_customer_id, 'ajcore_customer_relink_nonce' ); ?>
					<input type="hidden" name="stripe_customer_id" value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>">
					<input type="email" name="wp_user_email" value="<?php echo esc_attr( $user ? $user->user_email : $customer->email ); ?>" placeholder="<?php esc_attr_e( 'WordPress user email', 'ajforms' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Relink User', 'ajforms' ); ?></button>
				</form>
				<div class="ajcore-customer-quick-actions">
					<a class="button" href="<?php echo esc_url( $this->get_portal_dashboard_url( 'billing', array( 'billing_customer' => $customer->stripe_customer_id ) ) ); ?>"><?php esc_html_e( 'Billing Ledger', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( $this->get_portal_dashboard_url( 'service-requests', array( 's' => $customer->stripe_customer_id, 'request_status' => 'all' ) ) ); ?>"><?php esc_html_e( 'Requests', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( $this->get_portal_dashboard_url( 'tasks', array( 'task_client_filter' => $customer->stripe_customer_id ) ) ); ?>"><?php esc_html_e( 'Tasks', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( $this->get_portal_dashboard_url( 'file-library' ) ); ?>"><?php esc_html_e( 'Files', 'ajforms' ); ?></a>
				</div>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Active Recurring Services', 'ajforms' ); ?></h3>
				<?php
				$this->render_portal_dataset_section(
					'active_recurring_services',
					__( 'Active Recurring Services', 'ajforms' ),
					$detail['active_recurring_services'],
					array( 'service_name', 'price', 'billing_type', 'status', 'service_period', 'next_billing_date', 'stripe_subscription_id', 'stripe_price_id' ),
					__( 'No active recurring services.', 'ajforms' )
				);
				?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'One-Time Paid Services', 'ajforms' ); ?></h3>
				<?php
				$this->render_portal_dataset_section(
					'one_time_services',
					__( 'One-Time Paid Services', 'ajforms' ),
					$detail['one_time_services'],
					array( 'service_name', 'billing_type', 'status', 'amount', 'service_period', 'next_billing_date', 'paid_at', 'next_action' ),
					__( 'No one-time paid services found.', 'ajforms' )
				);
				?>
				<?php if ( ! empty( $detail['one_time_services'] ) ) : ?>
					<p><a class="button button-primary" href="<?php echo esc_url( $this->get_portal_dashboard_url( 'service-requests', array( 's' => $customer->stripe_customer_id, 'request_status' => 'all' ) ) ); ?>"><?php esc_html_e( 'Convert to Auto-Pay Subscription', 'ajforms' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Service Records', 'ajforms' ); ?></h3>
				<?php
				$this->render_portal_dataset_section(
					'products',
					__( 'Service Records', 'ajforms' ),
					$detail['purchased_products'],
					array( 'name', 'billing_type', 'source', 'stripe_price_id', 'stripe_product_id' ),
					__( 'No service records found in the synced cache.', 'ajforms' )
				);
				?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Billing Balance / Ledger', 'ajforms' ); ?></h3>
				<p>
					<strong><?php esc_html_e( 'Open:', 'ajforms' ); ?></strong> <?php echo esc_html( $this->format_portal_money( $detail['balance']['open_balance'], 'usd' ) ); ?>
					&nbsp; <strong><?php esc_html_e( 'Credit:', 'ajforms' ); ?></strong> <?php echo esc_html( $this->format_portal_money( $detail['balance']['credit_balance'], 'usd' ) ); ?>
					&nbsp; <strong><?php esc_html_e( 'Paid:', 'ajforms' ); ?></strong> <?php echo esc_html( $this->format_portal_money( $detail['balance']['paid_total'], 'usd' ) ); ?>
					&nbsp; <strong><?php esc_html_e( 'Failed:', 'ajforms' ); ?></strong> <?php echo esc_html( $detail['balance']['failed_count'] ); ?>
				</p>
				<?php
				$this->render_portal_dataset_section(
					'ledger',
					__( 'Recent Invoices / Charges Ledger', 'ajforms' ),
					$detail['ledger'],
					array( 'ledger_date', 'description', 'billing_type', 'metadata.service_period', 'amount', 'currency', 'status', 'invoice_id', 'charge_id' ),
					__( 'No recent ledger records.', 'ajforms' )
				);
				?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Requests', 'ajforms' ); ?></h3>
				<?php
				$this->render_portal_dataset_section(
					'customer_requests',
					__( 'Requests', 'ajforms' ),
					$detail['requests'],
					array( 'created_at', 'service_name', 'request_type', 'status', 'amount', 'currency', 'source', 'admin_notes', 'client_notes' ),
					__( 'No requests found for this customer.', 'ajforms' )
				);
				?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Tasks', 'ajforms' ); ?></h3>
				<?php
				$this->render_portal_dataset_section(
					'customer_tasks',
					__( 'Tasks', 'ajforms' ),
					$detail['tasks'],
					array( 'title', 'task_scope', 'task_frequency', 'status', 'customer_status', 'due_date', 'action_required', 'client_visible' ),
					__( 'No tasks found for this customer.', 'ajforms' )
				);
				?>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Upcoming Payment', 'ajforms' ); ?></h3>
				<?php
				$this->render_portal_dataset_section(
					'upcoming',
					__( 'Upcoming Payment', 'ajforms' ),
					$detail['upcoming_payments'],
					array( 'stripe_subscription_id', 'status', 'current_period_end' ),
					__( 'No upcoming payment within the next 30 days.', 'ajforms' )
				);
				?>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Linked Entities', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_entities_table( $detail['entities'] ); ?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Linked Files', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_files_table( $detail['files'] ); ?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Internal Notes / Activity', 'ajforms' ); ?></h3>
				<?php if ( empty( $detail['activity'] ) ) : ?>
					<p><?php esc_html_e( 'No customer activity found yet.', 'ajforms' ); ?></p>
				<?php else : ?>
					<ul class="ajcore-customer-activity">
						<?php foreach ( $detail['activity'] as $activity ) : ?>
							<li>
								<strong><?php echo esc_html( $activity['type'] ); ?></strong>
								<?php echo esc_html( $this->format_portal_date( $activity['date'] ) ); ?><br>
								<?php echo esc_html( $activity['summary'] ); ?>
								<?php if ( ! empty( $activity['note'] ) ) : ?><br><span class="description"><?php echo esc_html( wp_trim_words( $activity['note'], 28 ) ); ?></span><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php $this->render_portal_field_picker_script(); ?>
		<?php
	}

	private function render_portal_customer_subscriptions_table( $subscriptions ) {
		if ( empty( $subscriptions ) ) {
			echo '<p>' . esc_html__( 'No matching Stripe subscriptions.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Stripe Subscription', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Current Period End', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Canceling', 'ajforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
					<tr>
						<td><code><?php echo esc_html( $subscription->stripe_subscription_id ); ?></code></td>
						<td><?php echo esc_html( $subscription->status ); ?></td>
						<td><?php echo esc_html( $this->format_portal_date( $subscription->current_period_end ) ); ?></td>
						<td><?php echo ! empty( $subscription->cancel_at_period_end ) ? esc_html__( 'Yes', 'ajforms' ) : esc_html__( 'No', 'ajforms' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_portal_field_display_picker( $args ) {
		$section       = isset( $args['section'] ) ? sanitize_key( $args['section'] ) : '';
		$title         = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : __( 'Fields to Display', 'ajforms' );
		$available     = isset( $args['available'] ) && is_array( $args['available'] ) ? $args['available'] : array();
		$selected      = isset( $args['selected'] ) && is_array( $args['selected'] ) ? $args['selected'] : array();
		$field_name    = isset( $args['field_name'] ) ? sanitize_key( $args['field_name'] ) : 'portal_detail_display_fields';
		$nonce_action  = isset( $args['nonce_action'] ) ? sanitize_text_field( $args['nonce_action'] ) : '';
		$nonce_name    = isset( $args['nonce_name'] ) ? sanitize_key( $args['nonce_name'] ) : '';
		$hidden_inputs = isset( $args['hidden_inputs'] ) && is_array( $args['hidden_inputs'] ) ? $args['hidden_inputs'] : array();

		if ( empty( $available ) || '' === $nonce_action || '' === $nonce_name ) {
			return;
		}

		$selected = array_values( array_intersect( $selected, $available ) );
		$unselected = array_values( array_diff( $available, $selected ) );
		?>
		<details class="ajcore-field-picker" data-ajcore-field-picker>
			<summary><strong><?php echo esc_html( $title ); ?></strong></summary>
			<form method="post">
				<?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
				<?php foreach ( $hidden_inputs as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endforeach; ?>
				<p><?php esc_html_e( 'Select fields, then drag the selected display columns into the order you want.', 'ajforms' ); ?></p>
				<p>
					<button type="button" class="button" data-ajcore-select-all-fields><?php esc_html_e( 'Select All', 'ajforms' ); ?></button>
					<button type="button" class="button" data-ajcore-clear-fields><?php esc_html_e( 'Clear', 'ajforms' ); ?></button>
				</p>
				<h4><?php esc_html_e( 'Display Columns', 'ajforms' ); ?></h4>
				<ul class="ajcore-selected-field-list" data-ajcore-selected-fields style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 16px;min-height:44px;padding:8px;border:1px dashed #c3c4c7;background:#f6f7f7;">
					<?php foreach ( $selected as $field ) : ?>
						<li draggable="true" style="display:flex;gap:6px;align-items:center;border:1px solid #dcdcde;border-radius:999px;padding:6px 10px;background:#fff;cursor:grab;">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>[]" value="<?php echo esc_attr( $field ); ?>" checked>
								<code><?php echo esc_html( $field ); ?></code>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<h4><?php esc_html_e( 'Available Fields', 'ajforms' ); ?></h4>
				<ul class="ajcore-available-field-list" data-ajcore-available-fields style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px 14px;margin:10px 0 16px;">
					<?php foreach ( $unselected as $field ) : ?>
						<li style="display:flex;gap:6px;align-items:center;border:1px solid #dcdcde;border-radius:6px;padding:6px;background:#fff;">
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>[]" value="<?php echo esc_attr( $field ); ?>">
								<code><?php echo esc_html( $field ); ?></code>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php submit_button( __( 'Save Display Fields', 'ajforms' ), 'secondary', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	private function render_portal_field_picker_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		(function() {
			function moveToSelected(item, picker) {
				const selected = picker.querySelector('[data-ajcore-selected-fields]');
				const input = item ? item.querySelector('input[type="checkbox"]') : null;
				if (!item || !selected || !input) {
					return;
				}
				input.checked = true;
				item.draggable = true;
				item.style.borderRadius = '999px';
				item.style.cursor = 'grab';
				selected.appendChild(item);
			}

			function moveToAvailable(item, picker) {
				const available = picker.querySelector('[data-ajcore-available-fields]');
				const input = item ? item.querySelector('input[type="checkbox"]') : null;
				if (!item || !available || !input) {
					return;
				}
				input.checked = false;
				item.draggable = false;
				item.style.borderRadius = '6px';
				item.style.cursor = '';
				available.appendChild(item);
			}

			document.querySelectorAll('[data-ajcore-field-picker]').forEach(function(picker) {
				const selected = picker.querySelector('[data-ajcore-selected-fields]');
				const available = picker.querySelector('[data-ajcore-available-fields]');
				let dragged = null;

				picker.addEventListener('change', function(event) {
					if (!event.target.matches('input[type="checkbox"]')) {
						return;
					}
					const item = event.target.closest('li');
					if (event.target.checked) {
						moveToSelected(item, picker);
					} else {
						moveToAvailable(item, picker);
					}
				});

				const selectAll = picker.querySelector('[data-ajcore-select-all-fields]');
				if (selectAll) {
					selectAll.addEventListener('click', function() {
						Array.from(picker.querySelectorAll('li')).forEach(function(item) {
							moveToSelected(item, picker);
						});
					});
				}

				const clear = picker.querySelector('[data-ajcore-clear-fields]');
				if (clear) {
					clear.addEventListener('click', function() {
						Array.from(picker.querySelectorAll('li')).forEach(function(item) {
							moveToAvailable(item, picker);
						});
					});
				}

				if (!selected) {
					return;
				}

				selected.addEventListener('dragstart', function(event) {
					dragged = event.target.closest('li');
					if (dragged) {
						event.dataTransfer.effectAllowed = 'move';
					}
				});

				selected.addEventListener('dragover', function(event) {
					const target = event.target.closest('li');
					if (!dragged || !target || dragged === target || !selected.contains(target)) {
						return;
					}
					event.preventDefault();
					const rect = target.getBoundingClientRect();
					const after = event.clientX > rect.left + rect.width / 2 || event.clientY > rect.top + rect.height / 2;
					selected.insertBefore(dragged, after ? target.nextSibling : target);
				});

				selected.addEventListener('dragend', function() {
					dragged = null;
				});
			});
		})();
		</script>
		<?php
	}

	private function render_portal_dataset_section( $section, $title, $rows, $defaults, $empty_message ) {
		$section    = sanitize_key( $section );
		$available  = $this->discover_portal_row_scalar_fields( $rows );
		$selected   = array_values( array_intersect( $this->get_portal_detail_display_fields( $section, $defaults ), $available ) );
		if ( empty( $selected ) && ! empty( $available ) ) {
			$selected = array_slice( array_values( array_intersect( $defaults, $available ) ), 0, 8 );
		}

		$this->render_portal_field_display_picker(
			array(
				'section'       => $section,
				'title'         => sprintf( __( '%s Fields to Display', 'ajforms' ), $title ),
				'available'     => $available,
				'selected'      => $selected,
				'field_name'    => 'portal_detail_display_fields',
				'nonce_action'  => 'ajcore_save_portal_detail_fields_' . $section,
				'nonce_name'    => 'ajcore_portal_detail_fields_nonce',
				'hidden_inputs' => array(
					'detail_field_section' => $section,
				),
			)
		);

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html( $empty_message ) . '</p>';
			return;
		}

		if ( empty( $selected ) ) {
			echo '<p>' . esc_html__( 'No display fields selected.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<?php foreach ( $selected as $field ) : ?>
						<th><?php echo esc_html( $this->format_portal_customer_field_label( $field ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( $selected as $field ) : ?>
							<td><?php echo esc_html( $this->get_portal_row_display_value( $row, $field ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_portal_customer_products_table( $products ) {
		if ( empty( $products ) ) {
			echo '<p>' . esc_html__( 'No purchased products or services found in the synced cache.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product / Service', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Stripe Price', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Stripe Product', 'ajforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $products as $product ) : ?>
					<tr>
						<td><?php echo esc_html( $product['name'] ); ?></td>
						<td><?php echo esc_html( $product['source'] ); ?></td>
						<td>
							<?php if ( ! empty( $product['stripe_price_id'] ) ) : ?>
								<code><?php echo esc_html( $product['stripe_price_id'] ); ?></code>
							<?php else : ?>
								<?php esc_html_e( '-', 'ajforms' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $product['stripe_product_id'] ) ) : ?>
								<code><?php echo esc_html( $product['stripe_product_id'] ); ?></code>
							<?php else : ?>
								<?php esc_html_e( '-', 'ajforms' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_portal_customer_ledger_table( $ledger ) {
		if ( empty( $ledger ) ) {
			echo '<p>' . esc_html__( 'No recent ledger records.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Description', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Invoice', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Charge', 'ajforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $ledger as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_portal_date( $entry->ledger_date ) ); ?></td>
						<td><?php echo esc_html( $entry->description ); ?></td>
						<td><?php echo esc_html( $this->format_portal_money( $entry->amount, $entry->currency ) ); ?></td>
						<td><?php echo esc_html( $entry->status ); ?></td>
						<td>
							<?php if ( ! empty( $entry->invoice_id ) ) : ?>
								<code><?php echo esc_html( $entry->invoice_id ); ?></code>
							<?php else : ?>
								<?php esc_html_e( '-', 'ajforms' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $entry->charge_id ) ) : ?>
								<code><?php echo esc_html( $entry->charge_id ); ?></code>
							<?php else : ?>
								<?php esc_html_e( '-', 'ajforms' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_portal_customer_entities_table( $entities ) {
		if ( empty( $entities ) ) {
			echo '<p>' . esc_html__( 'No linked entities.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Entity', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Type', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Key', 'ajforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entities as $entity ) : ?>
					<tr>
						<td><?php echo esc_html( $entity->entity_label ); ?></td>
						<td><?php echo esc_html( $entity->entity_type ); ?></td>
						<td><code><?php echo esc_html( $entity->entity_key ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_portal_customer_files_table( $files ) {
		if ( empty( $files ) ) {
			echo '<p>' . esc_html__( 'No linked files.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Category', 'ajforms' ); ?></th>
					<th><?php esc_html_e( 'Created', 'ajforms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $files as $file ) : ?>
					<tr>
						<td><?php echo esc_html( $file->title ); ?></td>
						<td><?php echo esc_html( $file->category ); ?></td>
						<td><?php echo esc_html( $this->format_portal_date( $file->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}


	private function display_portal_sync_tab() {
		global $wpdb;

		$this->ensure_portal_schema();

		$cache_counts = $this->get_portal_cache_counts();
		$settings     = $this->get_portal_sync_settings();
		$jobs         = $this->get_portal_sync_jobs();
		$frequencies  = $this->get_portal_sync_frequency_labels();
		$logs         = $wpdb->get_results( "SELECT * FROM {$this->get_portal_sync_logs_table()} ORDER BY started_at DESC, id DESC LIMIT 80" );
		$last_run     = get_option( 'ajcore_portal_sync_last_run', '' );
		$reconciliation = $this->get_portal_sync_reconciliation();
		$open_log_id  = isset( $_GET['sync_log_id'] ) ? absint( wp_unslash( $_GET['sync_log_id'] ) ) : 0;
		$open_log_items = $open_log_id ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->get_portal_sync_log_items_table()} WHERE log_id = %d ORDER BY id ASC LIMIT 500", $open_log_id ) ) : array();
		$sync_url     = wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync', 'portal_action' => 'sync_all' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_all' );
		?>
		<div class="ajforms-settings-card">
			<h3><?php esc_html_e( 'Stripe Sync Center', 'ajforms' ); ?></h3>
			<p><?php esc_html_e( 'Use this tab as the single place to sync Stripe portal data, schedule recurring syncs, and review sync history.', 'ajforms' ); ?></p>
			<p><strong><?php esc_html_e( 'Stripe Webhook URL:', 'ajforms' ); ?></strong> <code><?php echo esc_html( add_query_arg( 'ajcore_stripe_webhook', '1', home_url( '/' ) ) ); ?></code></p>

			<?php if ( isset( $_GET['portal-sync-settings'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Sync schedule saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-synced'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( __( 'Synced %d Stripe records.', 'ajforms' ), absint( wp_unslash( $_GET['portal-synced'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-reset'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( __( 'Master reset complete. Deleted %d local portal sync/cache rows. Forms, leads, files, tasks, service requests, WordPress users, and settings were kept.', 'ajforms' ), absint( wp_unslash( $_GET['portal-reset'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-error'] ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="ajforms-settings-inline-actions">
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d customers cached', 'ajforms' ), $cache_counts['customers'] ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d products cached', 'ajforms' ), $cache_counts['products'] ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d Stripe subscriptions cached', 'ajforms' ), $cache_counts['subscriptions'] ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d billing records cached', 'ajforms' ), $cache_counts['ledger'] ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d portal users linked', 'ajforms' ), $cache_counts['mappings'] ) ); ?></span>
			</div>

			<div class="ajforms-settings-card" style="margin:16px 0 0;">
				<h4 style="margin-top:0;"><?php esc_html_e( 'Data Quality / Reconciliation', 'ajforms' ); ?></h4>
				<div class="ajforms-settings-inline-actions">
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d unmatched payments', 'ajforms' ), $reconciliation['unmatched_payments'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d unmatched refunds', 'ajforms' ), $reconciliation['unmatched_refunds'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d missing portal users', 'ajforms' ), $reconciliation['missing_users'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d failed sync logs', 'ajforms' ), $reconciliation['failed'] ) ); ?></span>
				</div>
				<?php if ( ! empty( $reconciliation['key_warnings'] ) ) : ?>
					<ul style="margin:12px 0 0;list-style:disc;padding-left:22px;">
						<?php foreach ( $reconciliation['key_warnings'] as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="ajforms-settings-inline-actions" style="margin-top:12px;">
				<a class="button button-primary" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Run Selected Sync Now', 'ajforms' ); ?></a>
				<?php foreach ( $jobs as $job_key => $job_label ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync', 'portal_action' => 'sync_' . $job_key ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_' . $job_key ) ); ?>"><?php echo esc_html( sprintf( __( 'Sync %s', 'ajforms' ), $job_label ) ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<form method="post" class="ajforms-settings-card" onsubmit="return window.confirm('<?php echo esc_js( __( 'Run AJ Core Client Portal master reset? This deletes local portal users, Stripe customer/product/subscription/transaction cache, billing ledger, mappings, and sync history. It does not delete WordPress users, forms, leads, files, tasks, service requests, or plugin settings.', 'ajforms' ) ); ?>');">
			<?php wp_nonce_field( 'ajcore_portal_master_reset', 'ajcore_portal_master_reset_nonce' ); ?>
			<h3><?php esc_html_e( 'Master Reset', 'ajforms' ); ?></h3>
			<p><?php esc_html_e( 'Use this when local portal sync/cache data is bad and you want to sync fresh from Stripe.', 'ajforms' ); ?></p>
			<p class="description"><?php esc_html_e( 'Deletes local portal users, Stripe customer/product/subscription/transaction cache, billing ledger, mappings, and sync history. Keeps forms, leads, files, tasks, service requests, WordPress users, and settings.', 'ajforms' ); ?></p>
			<?php submit_button( __( 'MASTER RESET', 'ajforms' ), 'delete', 'submit', false ); ?>
		</form>

		<form method="post" class="ajforms-settings-card">
			<?php wp_nonce_field( 'ajcore_save_portal_sync_settings', 'ajcore_portal_sync_settings_nonce' ); ?>
			<h3><?php esc_html_e( 'Schedule Syncs', 'ajforms' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable schedule', 'ajforms' ); ?></th>
					<td><label><input type="checkbox" name="sync_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Run selected syncs automatically with WP-Cron.', 'ajforms' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Frequency', 'ajforms' ); ?></th>
					<td>
						<select name="sync_frequency">
							<?php foreach ( $frequencies as $frequency_key => $frequency_label ) : ?>
								<option value="<?php echo esc_attr( $frequency_key ); ?>" <?php selected( $settings['frequency'], $frequency_key ); ?>><?php echo esc_html( $frequency_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html( sprintf( __( 'Next scheduled run: %s', 'ajforms' ), $this->get_portal_sync_next_run_label() ) ); ?></p>
						<?php if ( $last_run ) : ?><p class="description"><?php echo esc_html( sprintf( __( 'Last completed run: %s', 'ajforms' ), $last_run ) ); ?></p><?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sync jobs', 'ajforms' ); ?></th>
					<td>
						<?php foreach ( $jobs as $job_key => $job_label ) : ?>
							<label style="display:block;margin:0 0 8px;"><input type="checkbox" name="sync_jobs[]" value="<?php echo esc_attr( $job_key ); ?>" <?php checked( in_array( $job_key, $settings['jobs'], true ) ); ?>> <?php echo esc_html( $job_label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Recommended order is Products, Customers, Stripe Subscriptions, then Invoices / Charges. The automatic run uses this same order.', 'ajforms' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Sync Schedule', 'ajforms' ) ); ?>
		</form>

		<div class="ajforms-settings-card">
			<h3><?php esc_html_e( 'Sync History', 'ajforms' ); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Started', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Job', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Records', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Message / Log', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No sync history yet.', 'ajforms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->started_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->started_at ) ) : '-' ); ?></td>
								<td><?php echo esc_html( ucfirst( (string) $log->source ) ); ?></td>
								<td><?php echo esc_html( isset( $jobs[ $log->job_name ] ) ? $jobs[ $log->job_name ] : $log->job_name ); ?></td>
								<td><span class="ajcore-status-pill <?php echo 'success' === $log->status ? '' : 'off'; ?>"><?php echo esc_html( ucfirst( (string) $log->status ) ); ?></span></td>
								<td><?php echo esc_html( absint( $log->records_synced ) ); ?></td>
								<td>
									<?php $decoded_message = $this->decode_portal_sync_message( $log->message ); ?>
									<?php echo esc_html( (string) $decoded_message['summary'] ); ?>
									<br><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync', 'sync_log_id' => (int) $log->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open details', 'ajforms' ); ?></a>
								</td>
							</tr>
							<?php if ( $open_log_id === (int) $log->id ) : ?>
								<tr>
									<td colspan="6">
										<?php $open_message = $this->decode_portal_sync_message( $log->message ); ?>
										<?php if ( ! empty( $open_message['stats'] ) ) : ?>
											<div class="ajforms-settings-inline-actions" style="margin-bottom:10px;">
												<?php foreach ( array( 'inserted', 'updated', 'skipped', 'failed', 'unmatched_payments', 'unmatched_refunds', 'missing_users' ) as $stat_key ) : ?>
													<span class="ajforms-settings-pill"><?php echo esc_html( ucwords( str_replace( '_', ' ', $stat_key ) ) . ': ' . absint( isset( $open_message['stats'][ $stat_key ] ) ? $open_message['stats'][ $stat_key ] : 0 ) ); ?></span>
												<?php endforeach; ?>
											</div>
										<?php endif; ?>
										<table class="widefat striped">
											<thead><tr><th><?php esc_html_e( 'Action', 'ajforms' ); ?></th><th><?php esc_html_e( 'Type', 'ajforms' ); ?></th><th><?php esc_html_e( 'Record ID', 'ajforms' ); ?></th><th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Message', 'ajforms' ); ?></th></tr></thead>
											<tbody>
												<?php if ( empty( $open_log_items ) ) : ?>
													<tr><td colspan="6"><?php esc_html_e( 'No per-record details were captured for this run.', 'ajforms' ); ?></td></tr>
												<?php else : ?>
													<?php foreach ( $open_log_items as $item ) : ?>
														<tr>
															<td><?php echo esc_html( $item->action ); ?></td>
															<td><?php echo esc_html( $item->record_type ); ?></td>
															<td><code><?php echo esc_html( $item->record_id ); ?></code></td>
															<td><code><?php echo esc_html( $item->stripe_customer_id ); ?></code></td>
															<td><?php echo esc_html( $item->status ); ?></td>
															<td><?php echo esc_html( $item->message ); ?></td>
														</tr>
													<?php endforeach; ?>
												<?php endif; ?>
											</tbody>
										</table>
									</td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function display_portal_users_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;

		$status_filter = isset( $_GET['portal_user_status'] ) ? sanitize_key( wp_unslash( $_GET['portal_user_status'] ) ) : '';
		$allowed_status_filters = array( 'active', 'disabled', 'archived', 'without_login' );
		$status_filter = in_array( $status_filter, $allowed_status_filters, true ) ? $status_filter : '';
		$where = array( '1=1' );
		if ( 'active' === $status_filter ) {
			$where[] = "c.portal_status = 'active' AND c.enabled_portal = 1";
		} elseif ( 'disabled' === $status_filter ) {
			$where[] = "(c.portal_status = 'disabled' OR c.portal_status = 'without_portal_login' OR (c.enabled_portal = 0 AND (c.portal_status = '' OR c.portal_status IS NULL)))";
		} elseif ( 'archived' === $status_filter ) {
			$where[] = "c.portal_status = 'archived'";
		} elseif ( 'without_login' === $status_filter ) {
			$where[] = '(m.id IS NULL OR u.ID IS NULL)';
		} else {
			$where[] = "(c.portal_status IS NULL OR c.portal_status <> 'archived')";
		}

		$customers = $wpdb->get_results(
			"SELECT c.*, m.user_id, m.customer_email AS mapped_email, m.portal_user_email, m.site_uuid AS mapping_site_uuid, m.updated_at AS mapped_at
			FROM {$this->get_portal_stripe_customers_table()} c
			LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id
			LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY c.enabled_portal DESC, c.name ASC, c.email ASC
			LIMIT 300'
		);

		$total_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_customers_table()}" );
		$enabled_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_customers_table()} WHERE portal_status = 'active' AND enabled_portal = 1" );
		$available_fields = $this->discover_portal_customer_scalar_fields( $customers );
		$display_fields   = array_values( array_intersect( $this->get_portal_customer_display_fields(), $available_fields ) );
		if ( empty( $display_fields ) && ! empty( $available_fields ) ) {
			$display_fields = array_slice( array_values( array_intersect( array( 'name', 'email', 'customer_details.name', 'customer_details.email', 'created', 'livemode' ), $available_fields ) ), 0, 6 );
		}
		?>
		<div class="ajforms-settings-card">
			<h2><?php esc_html_e( 'Customers', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Customers are synced from Stripe and can optionally be linked to WordPress portal access.', 'ajforms' ); ?></p>

			<?php if ( isset( $_GET['portal-user-enabled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stripe customer enabled for portal access.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-user-disabled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Customer portal access disabled.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-user-archived'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Customer archived. Billing, requests, files, tasks, and history were kept.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-users-deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Archived customer records deleted from the local cache: %1$d. Skipped: %2$d.', 'ajforms' ), absint( wp_unslash( $_GET['portal-users-deleted'] ) ), isset( $_GET['portal-bulk-skipped'] ) ? absint( wp_unslash( $_GET['portal-bulk-skipped'] ) ) : 0 ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-customer-created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Stripe customer created and synced: %s.', 'ajforms' ), sanitize_text_field( wp_unslash( $_GET['portal-customer-created'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-password-reset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Password reset email sent.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-bulk-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Bulk action complete. Updated: %1$d. Skipped: %2$d.', 'ajforms' ), absint( wp_unslash( $_GET['portal-bulk-updated'] ) ), isset( $_GET['portal-bulk-skipped'] ) ? absint( wp_unslash( $_GET['portal-bulk-skipped'] ) ) : 0 ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-fields-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Customer display fields saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-links-repaired'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Repair complete. Cleared: %1$d. Relinked: %2$d. Roles updated: %3$d.', 'ajforms' ), isset( $_GET['portal-cleared'] ) ? absint( wp_unslash( $_GET['portal-cleared'] ) ) : 0, isset( $_GET['portal-relinked'] ) ? absint( wp_unslash( $_GET['portal-relinked'] ) ) : 0, isset( $_GET['portal-roles'] ) ? absint( wp_unslash( $_GET['portal-roles'] ) ) : 0 ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
			<?php endif; ?>
			<div class="ajforms-settings-inline-actions">
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d synced customers', 'ajforms' ), $total_customers ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d with portal access', 'ajforms' ), $enabled_count ) ); ?></span>
			</div>

			<details class="ajforms-settings-card" open>
				<summary><strong><?php esc_html_e( 'Create Stripe Customer', 'ajforms' ); ?></strong></summary>
				<form method="post" class="ajforms-settings-grid" style="margin-top:12px;">
					<?php wp_nonce_field( 'ajcore_create_stripe_customer', 'ajcore_create_stripe_customer_nonce' ); ?>
					<label>
						<span><?php esc_html_e( 'Email', 'ajforms' ); ?></span>
						<input type="email" name="stripe_customer_email" required>
					</label>
					<label>
						<span><?php esc_html_e( 'Customer Name', 'ajforms' ); ?></span>
						<input type="text" name="stripe_customer_name">
					</label>
					<label>
						<span><?php esc_html_e( 'Business Name', 'ajforms' ); ?></span>
						<input type="text" name="stripe_customer_business_name">
					</label>
					<label>
						<span><?php esc_html_e( 'Phone', 'ajforms' ); ?></span>
						<input type="text" name="stripe_customer_phone">
					</label>
					<label class="ajforms-settings-grid-full">
						<span><?php esc_html_e( 'Description', 'ajforms' ); ?></span>
						<input type="text" name="stripe_customer_description">
					</label>
					<div class="ajforms-settings-grid-full">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Stripe Customer', 'ajforms' ); ?></button>
					</div>
				</form>
			</details>

			<div class="ajcore-portal-users-toolbar">
				<form method="get" id="ajcore-portal-users-filter-form">
					<input type="hidden" name="page" value="ajforms-client-portal">
					<input type="hidden" name="tab" value="portal-users">
					<select name="portal_user_status" id="ajcore-portal-user-status-filter">
						<option value=""><?php esc_html_e( 'All except archived', 'ajforms' ); ?></option>
						<option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active portal access', 'ajforms' ); ?></option>
						<option value="disabled" <?php selected( $status_filter, 'disabled' ); ?>><?php esc_html_e( 'Disabled portal access', 'ajforms' ); ?></option>
						<option value="archived" <?php selected( $status_filter, 'archived' ); ?>><?php esc_html_e( 'Archived customers', 'ajforms' ); ?></option>
						<option value="without_login" <?php selected( $status_filter, 'without_login' ); ?>><?php esc_html_e( 'Without portal login', 'ajforms' ); ?></option>
					</select>
				</form>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button" data-portal-bulk-action="enable"><?php esc_html_e( 'Enable', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button button-primary" data-portal-bulk-action="enable_repair"><?php esc_html_e( 'Enable & Repair Selected Customers', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button" data-portal-bulk-action="disable"><?php esc_html_e( 'Disable', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button" data-portal-bulk-action="archive"><?php esc_html_e( 'Archive', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button button-primary" data-portal-bulk-action="restore"><?php esc_html_e( 'Restore / Enable', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button" data-portal-bulk-action="reset_password"><?php esc_html_e( 'Reset Password', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button" data-portal-bulk-action="send_welcome"><?php esc_html_e( 'Send Welcome Email', 'ajforms' ); ?></button>
				<button type="submit" form="ajcore-portal-users-bulk-form" class="button button-link-delete" data-portal-bulk-action="delete_archived"><?php esc_html_e( 'Delete Archived', 'ajforms' ); ?></button>
				<form method="post" style="display:inline;margin:0;">
				<?php wp_nonce_field( 'ajcore_repair_portal_user_links', 'ajcore_repair_portal_user_links_nonce' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Repair WP User Links & Roles', 'ajforms' ); ?></button>
				</form>
				<a class="button ajcore-toolbar-spacer" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Sync Center', 'ajforms' ); ?></a>
			</div>

			<?php if ( ! empty( $available_fields ) ) : ?>
				<?php
				$this->render_portal_field_display_picker(
					array(
						'section'      => 'customers',
						'title'        => __( 'Customer Fields to Display', 'ajforms' ),
						'available'    => $available_fields,
						'selected'     => $display_fields,
						'field_name'   => 'portal_customer_display_fields',
						'nonce_action' => 'ajcore_save_portal_customer_fields',
						'nonce_name'   => 'ajcore_portal_customer_fields_nonce',
					)
				);
				?>
			<?php endif; ?>

			<form method="post" id="ajcore-portal-users-bulk-form">
				<?php wp_nonce_field( 'ajcore_portal_bulk_user_action', 'ajcore_portal_bulk_user_nonce' ); ?>
				<input type="hidden" name="portal_bulk_action" id="ajcore-portal-bulk-action" value="">

				<table class="widefat striped ajcore-portal-users-table">
					<thead>
						<tr>
							<th style="width:34px;"><input type="checkbox" id="ajcore-check-all-portal-users"></th>
							<th><?php esc_html_e( 'Portal Status', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Customer ID', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Stripe Customer', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Email', 'ajforms' ); ?></th>
							<?php foreach ( $display_fields as $field ) : ?>
								<th><?php echo esc_html( $this->format_portal_customer_field_label( $field ) ); ?></th>
							<?php endforeach; ?>
							<th><?php esc_html_e( 'WordPress User', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Synced', 'ajforms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $customers ) ) : ?>
							<tr>
								<td colspan="<?php echo esc_attr( 7 + count( $display_fields ) ); ?>">
									<p><strong><?php esc_html_e( 'No synced Stripe customers yet.', 'ajforms' ); ?></strong></p>
									<p><?php esc_html_e( 'Click Sync Stripe Customers to pull saved Stripe Customer records from the connected Stripe account.', 'ajforms' ); ?></p>
									<p>
										<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Sync Center', 'ajforms' ); ?></a>
									</p>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $customers as $customer ) : ?>
								<?php
								$raw_user = ! empty( $customer->user_id ) ? get_userdata( (int) $customer->user_id ) : null;
								$user = $this->get_valid_portal_mapping_user( $customer, $customer );
								$link_mismatch = $raw_user && ! $user;
								$missing_portal_role = $user && ! $this->portal_user_has_portal_role( $user );
								$portal_status = ! empty( $customer->portal_status ) ? sanitize_key( (string) $customer->portal_status ) : ( ! empty( $customer->enabled_portal ) ? 'active' : 'disabled' );
								$is_active = 'active' === $portal_status && ! empty( $customer->enabled_portal );
								$has_portal_login = $user && ! empty( $customer->user_id );
								if ( 'archived' === $portal_status ) {
									$status_label = __( 'Archived', 'ajforms' );
									$status_class = 'archived';
									$row_class    = 'ajcore-row-archived';
								} elseif ( ! $has_portal_login ) {
									$status_label = __( 'Without portal login', 'ajforms' );
									$status_class = 'no-login';
									$row_class    = 'ajcore-row-no-login';
								} elseif ( $is_active ) {
									$status_label = __( 'Active', 'ajforms' );
									$status_class = 'active';
									$row_class    = 'ajcore-row-active';
								} else {
									$status_label = __( 'Disabled', 'ajforms' );
									$status_class = 'disabled';
									$row_class    = 'ajcore-row-disabled';
								}
								$customer_email      = ! empty( $customer->email ) ? sanitize_email( (string) $customer->email ) : '';
								$customer_unique_key = sanitize_text_field( (string) $customer->stripe_customer_id ) . '|' . strtolower( $customer_email );
								?>
								<tr class="<?php echo esc_attr( $row_class ); ?>">
									<td><input type="checkbox" class="ajcore-portal-user-checkbox" name="portal_customer_ids[]" value="<?php echo esc_attr( $customer_unique_key ); ?>" data-customer-id="<?php echo esc_attr( $customer->stripe_customer_id ); ?>" data-customer-email="<?php echo esc_attr( $customer_email ); ?>"></td>
									<td><span class="ajcore-status-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
									<td><code><?php echo esc_html( $customer->stripe_customer_id ); ?></code></td>
									<td>
										<strong><?php echo esc_html( ! empty( $customer->name ) ? $customer->name : __( 'Unnamed customer', 'ajforms' ) ); ?></strong><br>
										<small><?php echo esc_html( sprintf( __( 'Unique: %s', 'ajforms' ), $customer_unique_key ) ); ?></small><br>
										<a href="<?php echo esc_url( $this->get_portal_customer_360_url( $customer->stripe_customer_id ) ); ?>"><?php esc_html_e( 'Customer 360', 'ajforms' ); ?></a>
									</td>
									<td><?php echo esc_html( $customer_email ); ?></td>
									<?php foreach ( $display_fields as $field ) : ?>
										<td><?php echo esc_html( $this->get_portal_customer_display_value( $customer, $field ) ); ?></td>
									<?php endforeach; ?>
									<td>
										<?php
										if ( $user ) {
											echo esc_html( $user->display_name . ' #' . $user->ID );
											echo '<br><span class="description">' . esc_html( $user->user_email ) . '</span>';
											if ( $missing_portal_role ) {
												echo '<br><span class="notice-warning" style="color:#8a6d00;">' . esc_html__( 'Missing AJ Portal User role', 'ajforms' ) . '</span>';
											}
										} elseif ( $link_mismatch ) {
											echo '<strong style="color:#b32d2e;">' . esc_html__( 'Mismatched WP user link', 'ajforms' ) . '</strong>';
											echo '<br><span class="description">' . esc_html( $raw_user->user_email ) . '</span>';
										} elseif ( ! empty( $customer->user_id ) ) {
											esc_html_e( 'Linked user missing', 'ajforms' );
										} else {
											esc_html_e( '-', 'ajforms' );
										}
										?>
									</td>
									<td><?php echo esc_html( $this->format_portal_date( $customer->synced_at ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</form>
			<script>
			(function(){
				var form = document.getElementById('ajcore-portal-users-bulk-form');
				var actionInput = document.getElementById('ajcore-portal-bulk-action');
				var checkAll = document.getElementById('ajcore-check-all-portal-users');
				var filterForm = document.getElementById('ajcore-portal-users-filter-form');
				var statusFilter = document.getElementById('ajcore-portal-user-status-filter');
				function boxes(){return Array.prototype.slice.call(document.querySelectorAll('.ajcore-portal-user-checkbox'));}
				function setAll(checked){boxes().forEach(function(box){box.checked = checked;}); if(checkAll){checkAll.checked = checked;}}
				if(statusFilter && filterForm){statusFilter.addEventListener('change', function(){filterForm.submit();});}
				if(checkAll){checkAll.addEventListener('change', function(){setAll(checkAll.checked);});}
				if(form){
					form.addEventListener('submit', function(event){
						var submitter = event.submitter || document.activeElement;
						var action = submitter ? submitter.getAttribute('data-portal-bulk-action') : '';
						if(!action){event.preventDefault(); return;}
						if(!boxes().some(function(box){return box.checked;})){
							event.preventDefault();
							window.alert('<?php echo esc_js( __( 'Select at least one customer.', 'ajforms' ) ); ?>');
							return;
						}
						if('delete_archived' === action && !window.confirm('<?php echo esc_js( __( 'Delete selected archived portal customer records from the local AJ Core cache? WordPress users, Stripe records, billing, requests, files, tasks, and history will not be deleted.', 'ajforms' ) ); ?>')){
							event.preventDefault();
							return;
						}
						actionInput.value = action;
					});
				}
			})();
			</script>
			<?php $this->render_portal_field_picker_script(); ?>
		</div>
		<?php
	}

	private function display_portal_sold_items_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;

		$type_filter = isset( $_GET['sold_type'] ) ? wp_unslash( $_GET['sold_type'] ) : array( 'recurring', 'one_time' );
		$type_filter = is_array( $type_filter ) ? array_map( 'sanitize_key', $type_filter ) : array( sanitize_key( (string) $type_filter ) );
		$type_filter = array_values( array_intersect( array( 'recurring', 'one_time' ), $type_filter ) );
		if ( empty( $type_filter ) ) {
			$type_filter = array( 'recurring', 'one_time' );
		}

		$search = isset( $_GET['sold_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sold_search'] ) ) : '';
		$this->portal_service_display_skips = array();

		$active_subscriptions = $wpdb->get_results(
			"SELECT s.*, c.name AS customer_name, c.email AS customer_email
			FROM {$this->get_portal_stripe_subscriptions_table()} s
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = s.stripe_customer_id
			WHERE s.status IN ('active','trialing')
			ORDER BY s.current_period_end ASC, s.synced_at DESC
			LIMIT 1000"
		);
		$recurring_services = $this->get_portal_service_records_from_subscriptions( $active_subscriptions );
		$one_time_services  = $this->get_portal_one_time_paid_services( '', 1000 );
		$sold_items         = array();

		if ( in_array( 'recurring', $type_filter, true ) ) {
			foreach ( $recurring_services as $service ) {
				$service->sold_item_type = 'recurring';
				$service->sold_item_type_label = __( 'Recurring / Auto-Pay', 'ajforms' );
				$service->sold_item_source_id = ! empty( $service->stripe_subscription_id ) ? $service->stripe_subscription_id : '';
				$sold_items[] = $service;
			}
		}
		if ( in_array( 'one_time', $type_filter, true ) ) {
			foreach ( $one_time_services as $service ) {
				$service->sold_item_type = 'one_time';
				$service->sold_item_type_label = __( 'One-Time', 'ajforms' );
				$service->sold_item_source_id = $this->get_portal_one_time_service_source_id( $service );
				$sold_items[] = $service;
			}
		}

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$sold_items = array_values(
				array_filter(
					$sold_items,
					function ( $item ) use ( $needle ) {
						$haystack = strtolower(
							implode(
								' ',
								array(
									isset( $item->service_name ) ? $item->service_name : '',
									isset( $item->customer ) ? $item->customer : '',
									isset( $item->stripe_customer_id ) ? $item->stripe_customer_id : '',
									isset( $item->sold_item_source_id ) ? $item->sold_item_source_id : '',
									isset( $item->status ) ? $item->status : '',
								)
							)
						);
						return false !== strpos( $haystack, $needle );
					}
				)
			);
		}

		?>
		<div class="ajforms-settings-card">
			<h2><?php esc_html_e( 'Sold Items', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Purchased items from Stripe and AJ Core checkout, including recurring subscriptions and true one-time services.', 'ajforms' ); ?></p>
			<?php if ( isset( $_GET['service-used'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Service marked used.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<form method="get" id="ajcore-sold-items-filter" class="ajforms-settings-inline-actions" style="align-items:center;gap:12px;">
				<input type="hidden" name="page" value="ajforms-client-portal">
				<input type="hidden" name="tab" value="sold-items">
				<label><input type="checkbox" name="sold_type[]" value="recurring" <?php checked( in_array( 'recurring', $type_filter, true ) ); ?>> <?php esc_html_e( 'Recurring', 'ajforms' ); ?></label>
				<label><input type="checkbox" name="sold_type[]" value="one_time" <?php checked( in_array( 'one_time', $type_filter, true ) ); ?>> <?php esc_html_e( 'One-time', 'ajforms' ); ?></label>
				<input type="search" name="sold_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer, service, ID', 'ajforms' ); ?>" style="min-width:280px;">
				<button class="button"><?php esc_html_e( 'Search', 'ajforms' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sold-items' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Reset', 'ajforms' ); ?></a>
			</form>
			<script>
			(function(){
				var form = document.getElementById('ajcore-sold-items-filter');
				if(!form){return;}
				form.querySelectorAll('input[type="checkbox"]').forEach(function(box){
					box.addEventListener('change', function(){ form.submit(); });
				});
			})();
			</script>

			<table class="widefat striped" style="margin:16px 0;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Service', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Source ID', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Service Period', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Next Billing / Paid', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Synced', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sold_items ) ) : ?>
						<tr><td colspan="10"><?php esc_html_e( 'No sold items found for this filter.', 'ajforms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $sold_items as $item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $item->sold_item_type_label ); ?></strong></td>
								<td><?php echo esc_html( $item->service_name ); ?></td>
								<td><code><?php echo esc_html( ! empty( $item->sold_item_source_id ) ? $item->sold_item_source_id : '-' ); ?></code></td>
								<td><?php echo esc_html( $item->customer ); ?><br><small><?php echo esc_html( $item->stripe_customer_id ); ?></small><br><a href="<?php echo esc_url( $this->get_portal_customer_360_url( $item->stripe_customer_id ) ); ?>"><?php esc_html_e( 'Customer 360', 'ajforms' ); ?></a></td>
								<td><strong><?php echo esc_html( $item->status ); ?></strong></td>
								<td><?php echo esc_html( ! empty( $item->amount ) ? $item->amount : $item->price ); ?></td>
								<td><?php echo 'recurring' === $item->sold_item_type ? esc_html( $this->get_portal_service_record_period_label( $item ) ) : '-'; ?></td>
								<td><?php echo 'recurring' === $item->sold_item_type ? esc_html( $this->get_portal_service_record_next_billing_date_label( $item ) ) : '-'; ?></td>
								<td><?php echo esc_html( $this->format_portal_date( $item->synced_at ) ); ?></td>
								<td>
									<?php if ( 'one_time' === $item->sold_item_type && ! empty( $item->source_ref ) && 'used' !== sanitize_key( (string) $item->status ) ) : ?>
										<form method="post" style="margin:0;">
											<?php wp_nonce_field( 'ajcore_mark_service_used', 'ajcore_mark_service_used_nonce' ); ?>
											<input type="hidden" name="service_snapshot_key" value="<?php echo esc_attr( $item->source_ref ); ?>">
											<button type="submit" class="button"><?php esc_html_e( 'Mark Used', 'ajforms' ); ?></button>
										</form>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php $this->render_portal_service_display_reconciliation_notes(); ?>
		</div>
		<?php
	}

	private function display_portal_products_services_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;

		$products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_products_table()} ORDER BY active DESC, sort_order ASC, name ASC LIMIT %d",
				300
			)
		);
		$active_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_products_table()} WHERE active = 1" );
		$visible_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_products_table()} WHERE active = 1 AND visibility <> 'hidden'" );
		$total_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_products_table()}" );
		$duplicate_behavior_options = $this->get_portal_product_duplicate_behavior_options();
		$dependency_settings = $this->get_public_product_dependency_settings();
		$product_groups = array();
		foreach ( (array) $products as $product ) {
			$group_key = ! empty( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : 'row_' . (int) $product->id;
			if ( ! isset( $product_groups[ $group_key ] ) ) {
				$product_groups[ $group_key ] = array(
					'product' => $product,
					'rows'    => array(),
				);
			}
			$product_groups[ $group_key ]['rows'][] = $product;
		}
		$upgrade_product_options = array();
		foreach ( $product_groups as $option_group_key => $option_group ) {
			$option_product = $option_group['product'];
			$option_product_id = ! empty( $option_product->stripe_product_id ) ? sanitize_text_field( (string) $option_product->stripe_product_id ) : '';
			if ( '' === $option_product_id ) {
				continue;
			}
			$upgrade_product_options[ $option_product_id ] = ! empty( $option_product->custom_label ) ? sanitize_text_field( (string) $option_product->custom_label ) : sanitize_text_field( (string) $option_product->name );
		}
		$dependency_price_options = array();
		foreach ( (array) $products as $dependency_product ) {
			$dependency_price_id = ! empty( $dependency_product->stripe_price_id ) ? sanitize_text_field( (string) $dependency_product->stripe_price_id ) : '';
			if ( '' === $dependency_price_id ) {
				continue;
			}
			$dependency_label = ! empty( $dependency_product->custom_label ) ? sanitize_text_field( (string) $dependency_product->custom_label ) : sanitize_text_field( (string) $dependency_product->name );
			$dependency_price_options[ $dependency_price_id ] = sprintf(
				'%1$s - %2$s%3$s',
				$dependency_label,
				$this->format_portal_money( $dependency_product->price_amount, $dependency_product->currency ),
				! empty( $dependency_product->recurring_interval ) ? '/' . sanitize_key( (string) $dependency_product->recurring_interval ) : ''
			);
		}
		?>
		<div class="ajforms-settings-card">
			<h2><?php esc_html_e( 'Product Catalog', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Synced Stripe products and prices. These are the services/products you sell; portal fields only control how catalog items appear in Add Services.', 'ajforms' ); ?></p>

			<?php if ( isset( $_GET['portal-products'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Portal product display settings saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Synced %d Stripe records.', 'ajforms' ), absint( wp_unslash( $_GET['portal-synced'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="ajforms-settings-inline-actions">
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d active', 'ajforms' ), $active_count ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d visible in Add Services', 'ajforms' ), $visible_count ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d total', 'ajforms' ), $total_count ) ); ?></span>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sold-items' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Sold Items', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Sync Center', 'ajforms' ); ?></a>
			</div>
			<p class="description"><?php esc_html_e( 'Customer 360 opens from each customer link in Portal Users, Billing, Sold Items, Requests, Tasks, and Files.', 'ajforms' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'ajcore_save_portal_products', 'ajcore_portal_products_nonce' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Show in Add Services', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Product / Service', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Portal Label / Description', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Duplicate / Upgrade Logic', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Prices', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Sort', 'ajforms' ); ?></th>
							<th><?php esc_html_e( 'Stripe IDs', 'ajforms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $product_groups ) ) : ?>
							<tr><td colspan="7"><?php esc_html_e( 'No synced Stripe products yet.', 'ajforms' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $product_groups as $group_key => $group ) : ?>
								<?php
								$product = $group['product'];
								$rows    = $group['rows'];
								$row_ids = wp_list_pluck( $rows, 'id' );
								$active_rows = array_values(
									array_filter(
										$rows,
										function ( $row ) {
											return ! empty( $row->active );
										}
									)
								);
								$visible_rows = array_values(
									array_filter(
										$rows,
										function ( $row ) {
											return ! empty( $row->active ) && 'hidden' !== (string) $row->visibility;
										}
									)
								);
								$group_active  = ! empty( $active_rows );
								$group_visible = ! empty( $visible_rows );
								$field_key     = sanitize_key( $group_key );
								?>
								<tr>
									<td>
										<input type="hidden" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][row_ids]" value="<?php echo esc_attr( implode( ',', array_map( 'absint', $row_ids ) ) ); ?>">
										<input type="hidden" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][visibility]" value="hidden">
										<label><input type="checkbox" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][visibility]" value="visible" <?php checked( $group_visible ); ?> <?php disabled( ! $group_active ); ?>> <?php esc_html_e( 'Visible', 'ajforms' ); ?></label>
										<?php if ( ! $group_active ) : ?><br><span class="description"><?php esc_html_e( 'Archived in Stripe', 'ajforms' ); ?></span><?php endif; ?>
									</td>
									<td>
										<strong><?php echo esc_html( $product->name ); ?></strong>
										<?php if ( count( $rows ) > 1 ) : ?><br><span class="description"><?php echo esc_html( sprintf( __( '%d prices', 'ajforms' ), count( $rows ) ) ); ?></span><?php endif; ?>
										<?php if ( ! empty( $product->description ) ) : ?><br><span class="description"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $product->description ), 18 ) ); ?></span><?php endif; ?>
									</td>
									<td>
										<input type="text" style="width:100%;margin-bottom:6px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][custom_label]" value="<?php echo esc_attr( $product->custom_label ); ?>" placeholder="<?php esc_attr_e( 'Optional portal label', 'ajforms' ); ?>">
										<textarea style="width:100%;min-height:70px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][description_override]" placeholder="<?php esc_attr_e( 'Optional portal description override', 'ajforms' ); ?>"><?php echo esc_textarea( $product->description_override ); ?></textarea>
									</td>
									<td>
										<?php $selected_duplicate_behavior = ! empty( $product->duplicate_behavior ) && isset( $duplicate_behavior_options[ $product->duplicate_behavior ] ) ? $product->duplicate_behavior : 'no_duplicates'; ?>
										<select style="width:100%;margin-bottom:6px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][duplicate_behavior]">
											<?php foreach ( $duplicate_behavior_options as $behavior_key => $behavior_label ) : ?>
												<option value="<?php echo esc_attr( $behavior_key ); ?>" <?php selected( $selected_duplicate_behavior, $behavior_key ); ?>><?php echo esc_html( $behavior_label ); ?></option>
											<?php endforeach; ?>
										</select>
										<label class="description" for="ajcore-upgrade-from-<?php echo esc_attr( $field_key ); ?>"><?php esc_html_e( 'Upgrade from', 'ajforms' ); ?></label>
										<select id="ajcore-upgrade-from-<?php echo esc_attr( $field_key ); ?>" style="width:100%;margin:2px 0 10px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][upgrade_from_product_id]">
											<option value=""><?php esc_html_e( 'None', 'ajforms' ); ?></option>
											<?php foreach ( $upgrade_product_options as $upgrade_product_id => $upgrade_product_label ) : ?>
												<?php if ( $upgrade_product_id === (string) $product->stripe_product_id ) : ?>
													<?php continue; ?>
												<?php endif; ?>
												<option value="<?php echo esc_attr( $upgrade_product_id ); ?>" <?php selected( isset( $product->upgrade_from_product_id ) ? (string) $product->upgrade_from_product_id : '', $upgrade_product_id ); ?>><?php echo esc_html( $upgrade_product_label ); ?></option>
											<?php endforeach; ?>
										</select>
										<input type="text" style="width:100%;margin-bottom:6px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][custom_request_title]" value="<?php echo esc_attr( isset( $product->custom_request_title ) ? $product->custom_request_title : '' ); ?>" placeholder="<?php esc_attr_e( 'Custom request title', 'ajforms' ); ?>">
										<textarea style="width:100%;min-height:60px;margin-bottom:6px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][custom_request_message]" placeholder="<?php esc_attr_e( 'Custom request message shown after client already owns this service', 'ajforms' ); ?>"><?php echo esc_textarea( isset( $product->custom_request_message ) ? $product->custom_request_message : '' ); ?></textarea>
										<input type="text" style="width:100%" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][custom_request_button_label]" value="<?php echo esc_attr( isset( $product->custom_request_button_label ) ? $product->custom_request_button_label : '' ); ?>" placeholder="<?php esc_attr_e( 'Button label', 'ajforms' ); ?>">
									</td>
									<td>
										<?php foreach ( $rows as $price_row ) : ?>
											<?php
											$price_dependency = ! empty( $dependency_settings[ $price_row->stripe_price_id ] ) ? $dependency_settings[ $price_row->stripe_price_id ] : array();
											$selected_required_price = ! empty( $price_dependency['requires_price_id'] ) ? sanitize_text_field( (string) $price_dependency['requires_price_id'] ) : '';
											$dependency_note = ! empty( $price_dependency['dependency_note'] ) ? sanitize_textarea_field( (string) $price_dependency['dependency_note'] ) : '';
											?>
											<div style="margin:0 0 8px;">
												<strong><?php echo esc_html( $this->format_portal_money( $price_row->price_amount, $price_row->currency ) . ( ! empty( $price_row->recurring_interval ) ? '/' . $price_row->recurring_interval : '' ) ); ?></strong>
												<br><span class="description"><?php echo ! empty( $price_row->recurring_interval ) ? esc_html( $this->get_portal_billing_type_label( 'subscription' ) ) : esc_html( $this->get_portal_billing_type_label( 'one_time' ) ); ?></span>
												<br><code><?php echo esc_html( $price_row->stripe_price_id ); ?></code>
												<?php if ( empty( $price_row->active ) ) : ?><br><span class="description"><?php esc_html_e( 'Archived', 'ajforms' ); ?></span><?php endif; ?>
												<div style="margin-top:8px;padding:8px;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff;">
													<label class="description" for="ajcore-dependency-<?php echo esc_attr( $price_row->stripe_price_id ); ?>"><?php esc_html_e( 'Requires another product', 'ajforms' ); ?></label>
													<select id="ajcore-dependency-<?php echo esc_attr( $price_row->stripe_price_id ); ?>" style="width:100%;margin:2px 0 6px;" name="portal_product_dependencies[<?php echo esc_attr( $price_row->stripe_price_id ); ?>][requires_price_id]">
														<option value=""><?php esc_html_e( 'No dependency', 'ajforms' ); ?></option>
														<?php foreach ( $dependency_price_options as $dependency_price_id => $dependency_label ) : ?>
															<?php if ( $dependency_price_id === (string) $price_row->stripe_price_id ) : ?>
																<?php continue; ?>
															<?php endif; ?>
															<option value="<?php echo esc_attr( $dependency_price_id ); ?>" <?php selected( $selected_required_price, $dependency_price_id ); ?>><?php echo esc_html( $dependency_label ); ?></option>
														<?php endforeach; ?>
													</select>
													<textarea style="width:100%;min-height:52px;" name="portal_product_dependencies[<?php echo esc_attr( $price_row->stripe_price_id ); ?>][dependency_note]" placeholder="<?php esc_attr_e( 'Dependency note shown to logged-in customers', 'ajforms' ); ?>"><?php echo esc_textarea( $dependency_note ); ?></textarea>
												</div>
											</div>
										<?php endforeach; ?>
									</td>
									<td><input type="number" style="width:80px" name="portal_product_groups[<?php echo esc_attr( $field_key ); ?>][sort_order]" value="<?php echo esc_attr( (int) $product->sort_order ); ?>"></td>
									<td><code><?php echo esc_html( $product->stripe_product_id ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<p><?php submit_button( __( 'Save Product Display Settings', 'ajforms' ), 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	private function display_portal_tasks_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;
		$customers = $wpdb->get_results( "SELECT stripe_customer_id, name, email FROM {$this->get_portal_stripe_customers_table()} WHERE enabled_portal = 1 AND portal_status = 'active' ORDER BY name ASC, email ASC LIMIT 500" );
		if ( empty( $customers ) ) {
			$customers = $wpdb->get_results( "SELECT stripe_customer_id, name, email FROM {$this->get_portal_stripe_customers_table()} ORDER BY name ASC, email ASC LIMIT 500" );
		}

		$customer_map = array();
		foreach ( $customers as $customer ) {
			$customer_map[ $customer->stripe_customer_id ] = $customer;
		}

		$statuses = array(
			'open'              => __( 'Open', 'ajforms' ),
			'waiting_on_client' => __( 'Waiting on Client', 'ajforms' ),
			'in_progress'       => __( 'In Progress', 'ajforms' ),
			'upcoming'          => __( 'Upcoming', 'ajforms' ),
			'completed'         => __( 'Completed', 'ajforms' ),
			'cancelled'         => __( 'Cancelled', 'ajforms' ),
		);
		$scopes = array(
			'global' => __( 'Global — all portal users', 'ajforms' ),
			'client' => __( 'Client-specific', 'ajforms' ),
		);
		$frequencies = array(
			'one_time'  => __( 'One-time', 'ajforms' ),
			'recurring' => __( 'Recurring', 'ajforms' ),
		);

		$filters = array(
			'search'    => isset( $_GET['task_search'] ) ? sanitize_text_field( wp_unslash( $_GET['task_search'] ) ) : '',
			'scope'     => isset( $_GET['task_scope_filter'] ) ? sanitize_key( wp_unslash( $_GET['task_scope_filter'] ) ) : '',
			'frequency' => isset( $_GET['task_frequency_filter'] ) ? sanitize_key( wp_unslash( $_GET['task_frequency_filter'] ) ) : '',
			'status'    => isset( $_GET['task_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['task_status_filter'] ) ) : '',
			'client'    => isset( $_GET['task_client_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['task_client_filter'] ) ) : '',
			'due_from'  => isset( $_GET['task_due_from'] ) ? sanitize_text_field( wp_unslash( $_GET['task_due_from'] ) ) : '',
			'due_to'    => isset( $_GET['task_due_to'] ) ? sanitize_text_field( wp_unslash( $_GET['task_due_to'] ) ) : '',
			'view'      => isset( $_GET['task_view'] ) ? sanitize_key( wp_unslash( $_GET['task_view'] ) ) : '',
		);
		$filters['scope']     = in_array( $filters['scope'], array_keys( $scopes ), true ) ? $filters['scope'] : '';
		$filters['frequency'] = in_array( $filters['frequency'], array_keys( $frequencies ), true ) ? $filters['frequency'] : '';
		$filters['status']    = in_array( $filters['status'], array_keys( $statuses ), true ) ? $filters['status'] : '';
		$filters['due_from']  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['due_from'] ) ? $filters['due_from'] : '';
		$filters['due_to']    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['due_to'] ) ? $filters['due_to'] : '';
		$filters['view']      = in_array( $filters['view'], array( 'overdue' ), true ) ? $filters['view'] : '';

		$edit_task_id  = isset( $_GET['edit_task_id'] ) ? absint( wp_unslash( $_GET['edit_task_id'] ) ) : 0;
		$editing_task  = $edit_task_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->get_portal_tasks_table()} WHERE id = %d", $edit_task_id ) ) : null;
		$show_task_form = $editing_task || ( isset( $_GET['new_task'] ) && '1' === (string) $_GET['new_task'] );

		$tasks_raw = $wpdb->get_results(
			"SELECT t.*, c.name AS customer_name, c.email AS customer_email, ts.status AS customer_status, ts.completed_at AS customer_completed_at, ts.updated_at AS customer_status_updated_at
			FROM {$this->get_portal_tasks_table()} t
			LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = t.stripe_customer_id
			LEFT JOIN {$this->get_portal_task_statuses_table()} ts ON ts.task_id = t.id AND ts.stripe_customer_id = t.stripe_customer_id
			ORDER BY t.updated_at DESC, t.id DESC LIMIT 1000"
		);

		$all_task_ids = wp_list_pluck( (array) $tasks_raw, 'id' );
		$comments_by_task = array();
		$comment_counts = array();
		$latest_comments = array();
		if ( ! empty( $all_task_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $all_task_ids ), '%d' ) );
			$comments = $wpdb->get_results( $wpdb->prepare( "SELECT tc.*, c.name AS customer_name, c.email AS customer_email FROM {$this->get_portal_task_comments_table()} tc LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = tc.stripe_customer_id WHERE tc.task_id IN ({$placeholders}) ORDER BY tc.created_at DESC, tc.id DESC", $all_task_ids ) );
			foreach ( $comments as $comment ) {
				$task_id = absint( $comment->task_id );
				if ( ! isset( $comments_by_task[ $task_id ] ) ) {
					$comments_by_task[ $task_id ] = array();
					$latest_comments[ $task_id ] = $comment;
				}
				$comments_by_task[ $task_id ][] = $comment;
				$comment_counts[ $task_id ] = isset( $comment_counts[ $task_id ] ) ? $comment_counts[ $task_id ] + 1 : 1;
			}
		}

		$global_statuses_by_task = array();
		$global_progress = array();
		$total_portal_customers = max( 1, count( $customers ) );
		$global_status_rows = $wpdb->get_results(
			"SELECT ts.*, c.name AS customer_name, c.email AS customer_email FROM {$this->get_portal_task_statuses_table()} ts LEFT JOIN {$this->get_portal_stripe_customers_table()} c ON c.stripe_customer_id = ts.stripe_customer_id ORDER BY ts.updated_at DESC, ts.id DESC"
		);
		foreach ( $global_status_rows as $status_row ) {
			$task_id = absint( $status_row->task_id );
			if ( ! isset( $global_statuses_by_task[ $task_id ] ) ) {
				$global_statuses_by_task[ $task_id ] = array();
			}
			$global_statuses_by_task[ $task_id ][] = $status_row;
			if ( 'completed' === sanitize_key( $status_row->status ) ) {
				$global_progress[ $task_id ] = isset( $global_progress[ $task_id ] ) ? $global_progress[ $task_id ] + 1 : 1;
			}
		}

		$tasks = array_values(
			array_filter(
				(array) $tasks_raw,
				function ( $task ) use ( $filters ) {
					$scope = ! empty( $task->task_scope ) ? sanitize_key( $task->task_scope ) : 'client';
					$frequency = ! empty( $task->task_frequency ) ? sanitize_key( $task->task_frequency ) : 'one_time';
					$effective_status = 'client' === $scope && ! empty( $task->customer_status ) ? sanitize_key( $task->customer_status ) : sanitize_key( $task->status );
					$closed_statuses = array( 'completed', 'verified', 'closed', 'cancelled', 'canceled', 'archived' );

					if ( '' !== $filters['scope'] && $scope !== $filters['scope'] ) {
						return false;
					}
					if ( '' !== $filters['frequency'] && $frequency !== $filters['frequency'] ) {
						return false;
					}
					if ( '' !== $filters['status'] && $effective_status !== $filters['status'] ) {
						return false;
					}
					if ( '' !== $filters['client'] && (string) $task->stripe_customer_id !== $filters['client'] ) {
						return false;
					}
					if ( '' !== $filters['due_from'] && ( empty( $task->due_date ) || $task->due_date < $filters['due_from'] ) ) {
						return false;
					}
					if ( '' !== $filters['due_to'] && ( empty( $task->due_date ) || $task->due_date > $filters['due_to'] ) ) {
						return false;
					}
					if ( 'overdue' === $filters['view'] && ( empty( $task->due_date ) || $task->due_date >= current_time( 'Y-m-d' ) || in_array( $effective_status, $closed_statuses, true ) ) ) {
						return false;
					}
					if ( '' !== $filters['search'] ) {
						$haystack = strtolower( $task->title . ' ' . $task->action_required . ' ' . $task->customer_name . ' ' . $task->customer_email );
						return false !== strpos( $haystack, strtolower( $filters['search'] ) );
					}

					return true;
				}
			)
		);

		$current_scope     = $editing_task && ! empty( $editing_task->task_scope ) ? sanitize_key( $editing_task->task_scope ) : 'client';
		$current_frequency = $editing_task && ! empty( $editing_task->task_frequency ) ? sanitize_key( $editing_task->task_frequency ) : 'one_time';
		?>
		<div class="ajforms-settings-card ajcore-admin-tasks">
			<style>
				.ajcore-admin-tasks .ajcore-task-toolbar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:16px 0}.ajcore-admin-tasks .ajcore-task-form-card{max-width:980px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;margin:16px 0}.ajcore-admin-tasks .ajcore-task-filters{display:grid;grid-template-columns:repeat(7,minmax(130px,1fr));gap:10px;align-items:end;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:12px;margin:14px 0}.ajcore-admin-tasks .ajcore-task-filters label{display:flex;flex-direction:column;gap:5px;font-weight:600}.ajcore-admin-tasks .ajcore-task-bulkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px}.ajcore-admin-tasks .ajcore-task-comments{max-width:360px}.ajcore-admin-tasks .ajcore-comment-list{margin:8px 0 0;padding-left:0;list-style:none}.ajcore-admin-tasks .ajcore-comment-list li{border-left:3px solid #dbeafe;padding:6px 0 6px 10px;margin:8px 0}.ajcore-admin-tasks .ajcore-progress-pill{display:inline-flex;border-radius:999px;background:#eef2ff;color:#1d4ed8;font-weight:700;padding:4px 9px}.ajcore-admin-tasks .ajcore-muted{color:#646970}.ajcore-admin-tasks .ajcore-status-client{display:block;color:#166534;font-size:12px;margin-top:4px}.ajcore-admin-tasks .column-check{width:34px}@media(max-width:1200px){.ajcore-admin-tasks .ajcore-task-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
			</style>
			<h2><?php esc_html_e( 'Client Tasks / Action Items', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Create global tasks for every portal user or client-specific tasks for one customer. Portal users can comment and mark visible tasks complete from their portal.', 'ajforms' ); ?></p>
			<?php if ( isset( $_GET['portal-task'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Task updated.', 'ajforms' ); ?></p></div><?php endif; ?>

			<div class="ajcore-task-toolbar">
				<button type="button" class="button button-primary" id="ajcore-toggle-task-form"><?php echo esc_html( $editing_task ? __( 'Edit Task', 'ajforms' ) : __( 'New Task', 'ajforms' ) ); ?></button>
				<span class="ajcore-muted"><?php echo esc_html( sprintf( __( '%d tasks shown', 'ajforms' ), count( $tasks ) ) ); ?></span>
			</div>

			<div id="ajcore-task-form-wrap" style="<?php echo $show_task_form ? '' : 'display:none;'; ?>">
				<form method="post" class="ajcore-task-form-card">
					<?php wp_nonce_field( 'ajcore_save_portal_task', 'ajcore_portal_task_nonce' ); ?>
					<input type="hidden" name="portal_task_id" value="<?php echo esc_attr( $editing_task ? (int) $editing_task->id : 0 ); ?>">
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th><label for="task_scope"><?php esc_html_e( 'Task Type', 'ajforms' ); ?></label></th>
							<td><select id="task_scope" name="task_scope"><?php foreach ( $scopes as $scope_key => $scope_label ) : ?><option value="<?php echo esc_attr( $scope_key ); ?>" <?php selected( $current_scope, $scope_key ); ?>><?php echo esc_html( $scope_label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Global tasks show to every portal user. Client-specific tasks show only to the selected customer.', 'ajforms' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="task_frequency"><?php esc_html_e( 'Task Frequency', 'ajforms' ); ?></label></th>
							<td><select id="task_frequency" name="task_frequency"><?php foreach ( $frequencies as $frequency_key => $frequency_label ) : ?><option value="<?php echo esc_attr( $frequency_key ); ?>" <?php selected( $current_frequency, $frequency_key ); ?>><?php echo esc_html( $frequency_label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Use One-time for BOI-type tasks. Use Recurring for annual reports and tax reminders.', 'ajforms' ); ?></p></td>
						</tr>
						<tr>
							<th><label for="task_stripe_customer_id"><?php esc_html_e( 'Client', 'ajforms' ); ?></label></th>
							<td><select id="task_stripe_customer_id" name="task_stripe_customer_id"><option value=""><?php esc_html_e( 'Select client for client-specific tasks', 'ajforms' ); ?></option><?php foreach ( $customers as $customer ) : ?><option value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>" <?php selected( $editing_task ? $editing_task->stripe_customer_id : '', $customer->stripe_customer_id ); ?>><?php echo esc_html( ( $customer->name ? $customer->name : $customer->email ) . ' — ' . $customer->stripe_customer_id ); ?></option><?php endforeach; ?></select></td>
						</tr>
						<tr><th><label for="task_title"><?php esc_html_e( 'Task', 'ajforms' ); ?></label></th><td><input type="text" class="regular-text" id="task_title" name="task_title" value="<?php echo esc_attr( $editing_task ? $editing_task->title : '' ); ?>" required></td></tr>
						<tr><th><label for="task_status"><?php esc_html_e( 'Default Status', 'ajforms' ); ?></label></th><td><select id="task_status" name="task_status"><?php foreach ( $statuses as $status_key => $status_label ) : ?><option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $editing_task ? $editing_task->status : 'open', $status_key ); ?>><?php echo esc_html( $status_label ); ?></option><?php endforeach; ?></select></td></tr>
						<tr><th><label for="task_due_date"><?php esc_html_e( 'Due Date', 'ajforms' ); ?></label></th><td><input type="date" id="task_due_date" name="task_due_date" value="<?php echo esc_attr( $editing_task && $editing_task->due_date ? $editing_task->due_date : '' ); ?>"></td></tr>
						<tr><th><label for="task_action_required"><?php esc_html_e( 'Action Required', 'ajforms' ); ?></label></th><td><textarea class="large-text" rows="4" id="task_action_required" name="task_action_required"><?php echo esc_textarea( $editing_task ? $editing_task->action_required : '' ); ?></textarea></td></tr>
						<tr><th><?php esc_html_e( 'Visibility', 'ajforms' ); ?></th><td><label><input type="checkbox" name="task_client_visible" value="1" <?php checked( ! $editing_task || ! empty( $editing_task->client_visible ) ); ?>> <?php esc_html_e( 'Show to client', 'ajforms' ); ?></label></td></tr>
					</tbody></table>
					<?php submit_button( $editing_task ? __( 'Update Task', 'ajforms' ) : __( 'Add Task', 'ajforms' ), 'primary', 'submit', false ); ?>
					<?php if ( $editing_task ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'tasks' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel Edit', 'ajforms' ); ?></a><?php endif; ?>
				</form>
			</div>

			<form method="get" class="ajcore-task-filters">
				<input type="hidden" name="page" value="ajforms-client-portal">
				<input type="hidden" name="tab" value="tasks">
				<?php if ( '' !== $filters['view'] ) : ?><input type="hidden" name="task_view" value="<?php echo esc_attr( $filters['view'] ); ?>"><?php endif; ?>
				<label><?php esc_html_e( 'Search', 'ajforms' ); ?><input type="search" name="task_search" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
				<label><?php esc_html_e( 'Type', 'ajforms' ); ?><select name="task_scope_filter"><option value=""><?php esc_html_e( 'All', 'ajforms' ); ?></option><?php foreach ( $scopes as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['scope'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Frequency', 'ajforms' ); ?><select name="task_frequency_filter"><option value=""><?php esc_html_e( 'All', 'ajforms' ); ?></option><?php foreach ( $frequencies as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['frequency'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Status', 'ajforms' ); ?><select name="task_status_filter"><option value=""><?php esc_html_e( 'All', 'ajforms' ); ?></option><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Client', 'ajforms' ); ?><select name="task_client_filter"><option value=""><?php esc_html_e( 'All', 'ajforms' ); ?></option><?php foreach ( $customers as $customer ) : ?><option value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>" <?php selected( $filters['client'], $customer->stripe_customer_id ); ?>><?php echo esc_html( $customer->name ? $customer->name : $customer->email ); ?></option><?php endforeach; ?></select></label>
				<label><?php esc_html_e( 'Due From', 'ajforms' ); ?><input type="date" name="task_due_from" value="<?php echo esc_attr( $filters['due_from'] ); ?>"></label>
				<label><?php esc_html_e( 'Due To', 'ajforms' ); ?><input type="date" name="task_due_to" value="<?php echo esc_attr( $filters['due_to'] ); ?>"></label>
				<div><button class="button" type="submit"><?php esc_html_e( 'Filter', 'ajforms' ); ?></button> <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'tasks' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Reset', 'ajforms' ); ?></a></div>
			</form>

			<form method="post" id="ajcore-tasks-bulk-form">
				<?php wp_nonce_field( 'ajcore_bulk_portal_tasks', 'ajcore_portal_task_bulk_nonce' ); ?>
				<input type="hidden" name="portal_task_bulk_action" id="portal_task_bulk_action" value="">
				<div class="ajcore-task-bulkbar">
					<button type="button" class="button" id="ajcore-select-all-tasks"><?php esc_html_e( 'Select All Tasks', 'ajforms' ); ?></button>
					<select name="bulk_task_status"><option value=""><?php esc_html_e( 'Bulk status...', 'ajforms' ); ?></option><?php foreach ( $statuses as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<input type="date" name="bulk_task_due_date" title="<?php esc_attr_e( 'Bulk due date', 'ajforms' ); ?>">
					<select name="bulk_task_visibility"><option value=""><?php esc_html_e( 'Visibility...', 'ajforms' ); ?></option><option value="visible"><?php esc_html_e( 'Show to client', 'ajforms' ); ?></option><option value="hidden"><?php esc_html_e( 'Hide from client', 'ajforms' ); ?></option></select>
					<button type="submit" class="button" data-bulk-action="bulk_update"><?php esc_html_e( 'Bulk Edit Selected', 'ajforms' ); ?></button>
					<button type="submit" class="button button-primary" data-bulk-action="mark_completed"><?php esc_html_e( 'Bulk Complete', 'ajforms' ); ?></button>
					<button type="submit" class="button" data-bulk-action="mark_open"><?php esc_html_e( 'Bulk Reopen', 'ajforms' ); ?></button>
					<button type="submit" class="button button-link-delete" data-bulk-action="delete"><?php esc_html_e( 'Delete Selected', 'ajforms' ); ?></button>
				</div>

				<table class="widefat striped">
					<thead><tr><th class="column-check"><input type="checkbox" id="ajcore-check-all-tasks"></th><th><?php esc_html_e( 'Type', 'ajforms' ); ?></th><th><?php esc_html_e( 'Client / Progress', 'ajforms' ); ?></th><th><?php esc_html_e( 'Task', 'ajforms' ); ?></th><th><?php esc_html_e( 'Frequency', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Due Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Comments', 'ajforms' ); ?></th><th><?php esc_html_e( 'Visible', 'ajforms' ); ?></th><th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th></tr></thead>
					<tbody>
						<?php if ( empty( $tasks ) ) : ?>
							<tr><td colspan="10"><?php esc_html_e( 'No tasks match the current filters.', 'ajforms' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $tasks as $task ) : ?>
								<?php
								$task_id = absint( $task->id );
								$task_scope = ! empty( $task->task_scope ) ? sanitize_key( $task->task_scope ) : 'client';
								$task_frequency = ! empty( $task->task_frequency ) ? sanitize_key( $task->task_frequency ) : 'one_time';
								$effective_status = 'client' === $task_scope && ! empty( $task->customer_status ) ? sanitize_key( $task->customer_status ) : sanitize_key( $task->status );
								$completed_count = isset( $global_progress[ $task_id ] ) ? absint( $global_progress[ $task_id ] ) : 0;
								$activity_rows = isset( $global_statuses_by_task[ $task_id ] ) ? $global_statuses_by_task[ $task_id ] : array();
								$task_comments = isset( $comments_by_task[ $task_id ] ) ? $comments_by_task[ $task_id ] : array();
								$latest_comment = isset( $latest_comments[ $task_id ] ) ? $latest_comments[ $task_id ] : null;
								?>
								<tr>
									<td><input type="checkbox" class="ajcore-task-checkbox" name="portal_task_ids[]" value="<?php echo esc_attr( $task_id ); ?>"></td>
									<td><?php echo esc_html( isset( $scopes[ $task_scope ] ) ? $scopes[ $task_scope ] : $task_scope ); ?></td>
									<td>
										<?php if ( 'global' === $task_scope ) : ?>
											<span class="ajcore-progress-pill"><?php echo esc_html( sprintf( __( '%1$d of %2$d complete', 'ajforms' ), $completed_count, $total_portal_customers ) ); ?></span>
											<details><summary><?php esc_html_e( 'Drill down', 'ajforms' ); ?></summary>
												<?php if ( empty( $activity_rows ) ) : ?><p class="ajcore-muted"><?php esc_html_e( 'No client activity yet.', 'ajforms' ); ?></p><?php else : ?>
												<ul><?php foreach ( $activity_rows as $row ) : ?><li><?php echo esc_html( ( $row->customer_name ? $row->customer_name : $row->customer_email ) . ' — ' . ( isset( $statuses[ $row->status ] ) ? $statuses[ $row->status ] : $row->status ) . ' — ' . $this->format_portal_date( $row->updated_at ) ); ?></li><?php endforeach; ?></ul>
												<?php endif; ?>
											</details>
										<?php else : ?>
											<?php echo esc_html( $task->customer_name ? $task->customer_name : $task->customer_email ); ?><?php if ( ! empty( $task->stripe_customer_id ) ) : ?><br><code><?php echo esc_html( $task->stripe_customer_id ); ?></code><br><a href="<?php echo esc_url( $this->get_portal_customer_360_url( $task->stripe_customer_id ) ); ?>"><?php esc_html_e( 'Customer 360', 'ajforms' ); ?></a><?php endif; ?>
										<?php endif; ?>
									</td>
									<td><strong><?php echo esc_html( $task->title ); ?></strong><br><span class="description"><?php echo esc_html( wp_trim_words( (string) $task->action_required, 18 ) ); ?></span></td>
									<td><?php echo esc_html( isset( $frequencies[ $task_frequency ] ) ? $frequencies[ $task_frequency ] : $task_frequency ); ?></td>
									<td><?php echo esc_html( isset( $statuses[ $effective_status ] ) ? $statuses[ $effective_status ] : $effective_status ); ?><?php if ( 'client' === $task_scope && ! empty( $task->customer_status ) ) : ?><span class="ajcore-status-client"><?php esc_html_e( 'Updated by portal user', 'ajforms' ); ?></span><?php endif; ?></td>
									<td><?php echo esc_html( $task->due_date ? $this->format_portal_date( $task->due_date ) : '-' ); ?></td>
									<td class="ajcore-task-comments">
										<?php if ( empty( $task_comments ) ) : ?>
											<span class="ajcore-muted"><?php esc_html_e( 'No comments', 'ajforms' ); ?></span>
										<?php else : ?>
											<strong><?php echo esc_html( sprintf( _n( '%d comment', '%d comments', count( $task_comments ), 'ajforms' ), count( $task_comments ) ) ); ?></strong>
											<?php if ( $latest_comment ) : ?><div class="ajcore-muted"><?php echo esc_html( wp_trim_words( (string) $latest_comment->comment, 16 ) ); ?></div><?php endif; ?>
											<details><summary><?php esc_html_e( 'View comments', 'ajforms' ); ?></summary><ul class="ajcore-comment-list"><?php foreach ( $task_comments as $comment ) : ?><li><strong><?php echo esc_html( ( $comment->customer_name ? $comment->customer_name : $comment->customer_email ) . ( ! empty( $comment->is_client ) ? ' — Client' : ' — Admin' ) ); ?></strong><?php echo esc_html( $comment->comment ); ?><br><span class="ajcore-muted"><?php echo esc_html( $this->format_portal_date( $comment->created_at ) ); ?></span></li><?php endforeach; ?></ul></details>
										<?php endif; ?>
									</td>
									<td><?php echo ! empty( $task->client_visible ) ? esc_html__( 'Yes', 'ajforms' ) : esc_html__( 'No', 'ajforms' ); ?></td>
									<td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'tasks', 'edit_task_id' => $task_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'ajforms' ); ?></a> <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'tasks', 'portal_task_action' => 'delete', 'task_id' => $task_id ), admin_url( 'admin.php' ) ), 'ajcore_delete_portal_task_' . $task_id ) ); ?>"><?php esc_html_e( 'Delete', 'ajforms' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<script>
		(function(){
			var formWrap = document.getElementById('ajcore-task-form-wrap');
			var toggle = document.getElementById('ajcore-toggle-task-form');
			if(toggle && formWrap){toggle.addEventListener('click', function(){formWrap.style.display = formWrap.style.display === 'none' ? '' : 'none';});}
			var scope = document.getElementById('task_scope');
			var client = document.getElementById('task_stripe_customer_id');
			function syncClientRequirement(){if(!scope || !client){return;} client.required = scope.value === 'client'; client.disabled = scope.value === 'global';}
			if(scope){scope.addEventListener('change', syncClientRequirement); syncClientRequirement();}
			var checkAll = document.getElementById('ajcore-check-all-tasks');
			var selectBtn = document.getElementById('ajcore-select-all-tasks');
			function setAllTasks(checked){document.querySelectorAll('.ajcore-task-checkbox').forEach(function(box){box.checked = checked;}); if(checkAll){checkAll.checked = checked;}}
			if(checkAll){checkAll.addEventListener('change', function(){setAllTasks(checkAll.checked);});}
			if(selectBtn){selectBtn.addEventListener('click', function(){setAllTasks(true);});}
			document.querySelectorAll('[data-bulk-action]').forEach(function(button){button.addEventListener('click', function(e){var any = Array.from(document.querySelectorAll('.ajcore-task-checkbox')).some(function(box){return box.checked;}); if(!any){e.preventDefault(); alert('Select at least one task first.'); return;} var action = button.getAttribute('data-bulk-action') || ''; if(action === 'delete' && !confirm('Delete selected tasks and their comments/status history?')){e.preventDefault(); return;} document.getElementById('portal_task_bulk_action').value = action;});});
		})();
		</script>
		<?php
	}

	public function display_file_library_page( $embedded = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;

		$edit_file_id     = isset( $_GET['edit_file_id'] ) ? absint( $_GET['edit_file_id'] ) : 0;
		$editing_file     = $edit_file_id ? $this->get_portal_file_record( $edit_file_id ) : null;
		$assigned_users   = array();
		$assigned_emails  = array();
		$attachment_title = '';
		$attachment_url   = '';

		if ( $editing_file ) {
			foreach ( $this->get_portal_file_assignments( (int) $editing_file->id ) as $assignment ) {
				if ( ! empty( $assignment->user_id ) ) {
					$assigned_users[] = (int) $assignment->user_id;
				} elseif ( ! empty( $assignment->user_email ) ) {
					$assigned_emails[] = $assignment->user_email;
				}
			}

			$attachment_title = get_the_title( (int) $editing_file->attachment_id );
			$attachment_url   = wp_get_attachment_url( (int) $editing_file->attachment_id );
		}

		$users = get_users(
			array(
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 500,
			)
		);

		$files = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_files_table()} ORDER BY created_at DESC LIMIT %d",
				1000
			)
		);
		?>
		<div class="<?php echo $embedded ? 'ajforms-file-library' : 'wrap ajforms-file-library'; ?>">
			<?php if ( ! $embedded ) : ?>
				<h1><?php esc_html_e( 'File Library', 'ajforms' ); ?></h1>
			<?php endif; ?>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'File saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'File record deleted.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['error'] ) && 'missing-file' === sanitize_key( wp_unslash( $_GET['error'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Select a Media Library file before saving.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<style>
				.ajforms-file-library-grid{display:grid;grid-template-columns:minmax(360px,520px) minmax(520px,1fr);gap:24px;align-items:start;margin-top:18px}
				.ajforms-file-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
				.ajforms-file-card h2{margin:0 0 16px;font-size:18px}
				.ajforms-file-field{margin-bottom:16px}
				.ajforms-file-field label{display:block;font-weight:700;margin-bottom:7px}
				.ajforms-file-field input[type="text"],.ajforms-file-field textarea{width:100%;max-width:100%}
				.ajforms-file-picker{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
				.ajforms-selected-file{margin-top:8px;color:#50575e;word-break:break-word}
				.ajforms-user-list{border:1px solid #dcdcde;border-radius:8px;max-height:260px;overflow:auto;padding:8px;background:#f6f7f7}
				.ajforms-user-list label{display:block;padding:7px 8px;margin:0;border-radius:6px;font-weight:500}
				.ajforms-user-list label:hover{background:#fff}
				.ajforms-file-table td{vertical-align:top}
				.ajforms-assignment-list{margin:0;padding-left:18px}
				@media (max-width:1200px){.ajforms-file-library-grid{grid-template-columns:1fr}}
			</style>

			<div class="ajforms-file-library-grid">
				<form class="ajforms-file-card" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ajforms-client-portal&tab=file-library' ) ); ?>">
					<h2><?php echo $editing_file ? esc_html__( 'Edit File', 'ajforms' ) : esc_html__( 'Add File', 'ajforms' ); ?></h2>
					<?php wp_nonce_field( 'ajf_save_portal_file', 'ajf_portal_file_nonce' ); ?>
					<input type="hidden" name="portal_file_id" value="<?php echo esc_attr( $editing_file ? (int) $editing_file->id : 0 ); ?>">
					<input type="hidden" name="attachment_id" id="ajforms-portal-attachment-id" value="<?php echo esc_attr( $editing_file ? (int) $editing_file->attachment_id : 0 ); ?>">

					<div class="ajforms-file-field">
						<label><?php esc_html_e( 'Media File', 'ajforms' ); ?></label>
						<div class="ajforms-file-picker">
							<button type="button" class="button" id="ajforms-select-portal-file"><?php esc_html_e( 'Select File', 'ajforms' ); ?></button>
							<button type="button" class="button" id="ajforms-clear-portal-file"><?php esc_html_e( 'Clear', 'ajforms' ); ?></button>
						</div>
						<div class="ajforms-selected-file" id="ajforms-selected-portal-file">
							<?php echo $attachment_url ? esc_html( $attachment_title ? $attachment_title : basename( $attachment_url ) ) : esc_html__( 'No file selected.', 'ajforms' ); ?>
						</div>
					</div>

					<div class="ajforms-file-field">
						<label for="portal_file_title"><?php esc_html_e( 'Title', 'ajforms' ); ?></label>
						<input type="text" id="portal_file_title" name="portal_file_title" value="<?php echo esc_attr( $editing_file ? $editing_file->title : '' ); ?>">
					</div>

					<div class="ajforms-file-field">
						<label for="portal_file_category"><?php esc_html_e( 'Category', 'ajforms' ); ?></label>
						<input type="text" id="portal_file_category" name="portal_file_category" value="<?php echo esc_attr( $editing_file ? $editing_file->category : '' ); ?>">
					</div>

					<div class="ajforms-file-field">
						<label for="portal_file_description"><?php esc_html_e( 'Description', 'ajforms' ); ?></label>
						<textarea id="portal_file_description" name="portal_file_description" rows="4"><?php echo esc_textarea( $editing_file ? $editing_file->description : '' ); ?></textarea>
					</div>

					<div class="ajforms-file-field">
						<label><?php esc_html_e( 'Share With Users', 'ajforms' ); ?></label>
						<div class="ajforms-user-list">
							<?php foreach ( $users as $user ) : ?>
								<label>
									<input type="checkbox" name="assigned_user_ids[]" value="<?php echo esc_attr( (int) $user->ID ); ?>" <?php checked( in_array( (int) $user->ID, $assigned_users, true ) ); ?>>
									<?php echo esc_html( $user->display_name . ' <' . $user->user_email . '>' ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="ajforms-file-field">
						<label for="assigned_user_emails"><?php esc_html_e( 'Additional Email Access', 'ajforms' ); ?></label>
						<textarea id="assigned_user_emails" name="assigned_user_emails" rows="3" placeholder="<?php esc_attr_e( 'one@example.com, two@example.com', 'ajforms' ); ?>"><?php echo esc_textarea( implode( "\n", $assigned_emails ) ); ?></textarea>
					</div>

					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save File', 'ajforms' ); ?></button>
						<?php if ( $editing_file ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ajforms-client-portal&tab=file-library' ) ); ?>"><?php esc_html_e( 'Cancel', 'ajforms' ); ?></a>
						<?php endif; ?>
					</p>
				</form>

				<div class="ajforms-file-card">
					<h2><?php esc_html_e( 'Shared Files', 'ajforms' ); ?></h2>
					<table class="widefat striped ajforms-file-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Title', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Category', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'File', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Shared With', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $files ) ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'No files have been shared yet.', 'ajforms' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $files as $file ) : ?>
									<?php
									$file_url = wp_get_attachment_url( (int) $file->attachment_id );
									$labels   = $this->get_portal_file_assignment_labels( (int) $file->id );
									$customer_links = $this->get_portal_file_customer_links( (int) $file->id );
									?>
									<tr>
										<td><strong><?php echo esc_html( $file->title ); ?></strong><br><span class="description"><?php echo esc_html( wp_trim_words( (string) $file->description, 16 ) ); ?></span></td>
										<td><?php echo esc_html( $file->category ); ?></td>
										<td><?php echo $file_url ? esc_html( basename( parse_url( $file_url, PHP_URL_PATH ) ) ) : esc_html__( 'Missing attachment', 'ajforms' ); ?></td>
										<td>
											<?php if ( empty( $labels ) ) : ?>
												<span class="description"><?php esc_html_e( 'No users assigned.', 'ajforms' ); ?></span>
											<?php else : ?>
												<ul class="ajforms-assignment-list">
													<?php foreach ( $labels as $label ) : ?>
														<li><?php echo esc_html( $label ); ?></li>
													<?php endforeach; ?>
												</ul>
												<?php if ( ! empty( $customer_links ) ) : ?>
													<p style="margin:8px 0 0;">
														<?php foreach ( $customer_links as $customer_link ) : ?>
															<a href="<?php echo esc_url( $customer_link['url'] ); ?>"><?php echo esc_html( sprintf( __( 'Customer 360: %s', 'ajforms' ), $customer_link['label'] ) ); ?></a><br>
														<?php endforeach; ?>
													</p>
												<?php endif; ?>
											<?php endif; ?>
										</td>
										<td>
											<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'file-library', 'edit_file_id' => (int) $file->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'ajforms' ); ?></a>
											|
											<a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'file-library', 'portal_file_action' => 'delete', 'file_id' => (int) $file->id ), admin_url( 'admin.php' ) ), 'ajf_delete_portal_file_' . (int) $file->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this file record?', 'ajforms' ) ); ?>');"><?php esc_html_e( 'Delete', 'ajforms' ); ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<script>
		(function($) {
			let frame;
			const attachmentInput = $('#ajforms-portal-attachment-id');
			const selectedFile = $('#ajforms-selected-portal-file');
			const titleInput = $('#portal_file_title');

			$('#ajforms-select-portal-file').on('click', function(event) {
				event.preventDefault();

				if (frame) {
					frame.open();
					return;
				}

				frame = wp.media({
					title: '<?php echo esc_js( __( 'Select File', 'ajforms' ) ); ?>',
					button: { text: '<?php echo esc_js( __( 'Use This File', 'ajforms' ) ); ?>' },
					multiple: false
				});

				frame.on('select', function() {
					const attachment = frame.state().get('selection').first().toJSON();
					attachmentInput.val(attachment.id || '');
					selectedFile.text(attachment.filename || attachment.title || attachment.url || '<?php echo esc_js( __( 'File selected.', 'ajforms' ) ); ?>');
					if (!titleInput.val() && attachment.title) {
						titleInput.val(attachment.title);
					}
				});

				frame.open();
			});

			$('#ajforms-clear-portal-file').on('click', function(event) {
				event.preventDefault();
				attachmentInput.val('');
				selectedFile.text('<?php echo esc_js( __( 'No file selected.', 'ajforms' ) ); ?>');
			});
		})(jQuery);
		</script>
		<?php
	}

	public function display_role_manager_page( $embedded = false ) {
		if ( ! $this->current_user_can_manage_ajcore_roles() ) {
			wp_die( esc_html__( 'Only Administrators can manage roles.', 'ajforms' ) );
		}

		global $wp_roles;

		if ( ! isset( $wp_roles ) || ! $wp_roles instanceof WP_Roles ) {
			$wp_roles = wp_roles();
		}

		$roles        = $wp_roles->roles;
		$all_caps     = $this->get_all_role_capabilities();
		$edit_role    = isset( $_GET['edit_role'] ) ? sanitize_key( wp_unslash( $_GET['edit_role'] ) ) : '';
		$editing_role = ( $edit_role && isset( $roles[ $edit_role ] ) ) ? $roles[ $edit_role ] : null;
		$is_editing   = null !== $editing_role;
		$form_role    = $is_editing ? $edit_role : '';
		$form_label   = $is_editing ? $editing_role['name'] : '';
		$is_adding    = isset( $_GET['role_manager_action'] ) && 'add' === sanitize_key( wp_unslash( $_GET['role_manager_action'] ) );
		$form_caps    = $is_editing && ! empty( $editing_role['capabilities'] ) && is_array( $editing_role['capabilities'] ) ? array_keys( array_filter( $editing_role['capabilities'] ) ) : array( 'read' );
		$base_url     = add_query_arg( array( 'page' => 'ajforms-settings', 'section' => 'role-manager' ), admin_url( 'admin.php' ) );
		?>
		<div class="<?php echo $embedded ? 'ajcore-role-manager' : 'wrap ajcore-role-manager'; ?>">
			<?php if ( ! $embedded ) : ?>
				<h1><?php esc_html_e( 'Role Manager', 'ajforms' ); ?></h1>
			<?php endif; ?>

			<?php if ( isset( $_GET['role-saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Role saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['role-deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Role deleted.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['role-error'] ) ) : ?>
				<?php
				$error_key = sanitize_key( wp_unslash( $_GET['role-error'] ) );
				$messages  = array(
					'administrator-delete' => __( 'Administrator cannot be deleted.', 'ajforms' ),
					'wordpress-default-delete' => __( 'WordPress default roles cannot be deleted.', 'ajforms' ),
					'role-has-users'       => __( 'This role has assigned users. Reassign those users before deleting the role.', 'ajforms' ),
					'missing-fields'       => __( 'Role key and label are required.', 'ajforms' ),
					'role-exists'          => __( 'A role with that key already exists.', 'ajforms' ),
				);
				$message = isset( $messages[ $error_key ] ) ? $messages[ $error_key ] : __( 'Role action could not be completed.', 'ajforms' );
				?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<style>
				.ajcore-role-manager-grid{display:grid;gap:18px;align-items:start;margin-top:18px}
				.ajcore-role-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
				.ajcore-role-panel h2{margin:0 0 16px;font-size:18px}
				.ajcore-role-panel-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}
				.ajcore-role-panel-head h2{margin:0}
				.ajcore-role-type-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
				.ajcore-role-type-badge.wordpress{background:#eef2ff;color:#3730a3}
				.ajcore-role-type-badge.ajcore{background:#ecfdf3;color:#027a48}
				.ajcore-role-type-badge.custom{background:#fff7ed;color:#c2410c}
				.ajcore-selected-capabilities{margin:14px 0 18px;padding:14px;border:1px solid #dbe3ec;border-radius:10px;background:#f8fafc}
				.ajcore-selected-capabilities h3{margin:0 0 10px;font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:#344054}
				.ajcore-selected-capability-list{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
				.ajcore-selected-capability-list.is-empty:before{content:attr(data-empty);color:#667085}
				.ajcore-selected-capability-badge{display:inline-flex;align-items:center;border-radius:999px;background:#e0f2fe;color:#075985;font-weight:700;font-size:12px;padding:4px 8px}
				.ajcore-capability-tools{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:12px;align-items:center;margin-bottom:10px}
				.ajcore-capability-tools input[type="search"]{width:100%;min-height:38px;border:1px solid #d1d5db;border-radius:8px;padding:7px 10px}
				.ajcore-role-field{margin-bottom:16px}
				.ajcore-role-field label{display:block;font-weight:700;margin-bottom:7px}
				.ajcore-role-field input[type="text"],.ajcore-role-field textarea{width:100%;max-width:100%}
				.ajcore-capability-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;max-height:420px;overflow:auto;padding:10px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7}
				.ajcore-capability-list label{display:block;margin:0;padding:7px 8px;border-radius:6px;background:#fff;font-weight:500;word-break:break-word}
				.ajcore-capability-list label:hover{background:#eef6ff}
				.ajcore-role-table td{vertical-align:top}
			</style>

			<div class="ajcore-role-manager-grid">
				<?php if ( $is_editing || $is_adding ) : ?>
					<form class="ajcore-role-panel" method="post" action="<?php echo esc_url( $base_url ); ?>">
						<h2><?php echo $is_editing ? esc_html__( 'Edit Role', 'ajforms' ) : esc_html__( 'Add Role', 'ajforms' ); ?></h2>
						<?php wp_nonce_field( 'ajcore_save_role', 'ajcore_role_manager_nonce' ); ?>
						<input type="hidden" name="role_manager_action" value="<?php echo esc_attr( $is_editing ? 'edit' : 'add' ); ?>">

						<div class="ajcore-role-field">
							<label for="role_key"><?php esc_html_e( 'Role Key', 'ajforms' ); ?></label>
							<input type="text" id="role_key" name="role_key" value="<?php echo esc_attr( $form_role ); ?>" <?php disabled( $is_editing ); ?>>
							<?php if ( $is_editing ) : ?>
								<input type="hidden" name="role_key" value="<?php echo esc_attr( $form_role ); ?>">
							<?php endif; ?>
						</div>

						<div class="ajcore-role-field">
							<label for="role_label"><?php esc_html_e( 'Role Label', 'ajforms' ); ?></label>
							<input type="text" id="role_label" name="role_label" value="<?php echo esc_attr( $form_label ); ?>">
						</div>

						<div class="ajcore-selected-capabilities">
							<h3><?php esc_html_e( 'Selected Capabilities', 'ajforms' ); ?></h3>
							<div class="ajcore-selected-capability-list" data-empty="<?php esc_attr_e( 'No capabilities selected.', 'ajforms' ); ?>"></div>
							<p class="description"><?php esc_html_e( 'read = basic WordPress admin/login access. It does not grant access to all private AJ Core files.', 'ajforms' ); ?></p>
							<p class="description"><?php esc_html_e( 'level_0 through level_9 are legacy WordPress capability levels and should generally not be used for new AJ Core access logic.', 'ajforms' ); ?></p>
						</div>

						<div class="ajcore-role-field">
							<label><?php esc_html_e( 'Capabilities', 'ajforms' ); ?></label>
							<?php if ( 'administrator' === $form_role ) : ?>
								<p class="description"><?php esc_html_e( 'Existing Administrator capabilities are protected and cannot be removed here.', 'ajforms' ); ?></p>
							<?php endif; ?>
							<div class="ajcore-capability-tools">
								<input type="search" class="ajcore-capability-search" placeholder="<?php esc_attr_e( 'Search capabilities...', 'ajforms' ); ?>" aria-label="<?php esc_attr_e( 'Search capabilities', 'ajforms' ); ?>">
								<label style="display:flex;align-items:center;gap:7px;margin:0;font-weight:600;">
									<input type="checkbox" class="ajcore-show-selected-only">
									<?php esc_html_e( 'Show selected only', 'ajforms' ); ?>
								</label>
							</div>
							<div class="ajcore-capability-list">
								<?php foreach ( $all_caps as $capability ) : ?>
									<label data-capability="<?php echo esc_attr( $capability ); ?>">
										<input type="checkbox" name="role_capabilities[]" value="<?php echo esc_attr( $capability ); ?>" <?php checked( in_array( $capability, $form_caps, true ) ); ?>>
										<?php echo esc_html( $capability ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="ajcore-role-field">
							<label for="custom_capabilities"><?php esc_html_e( 'Additional Capabilities', 'ajforms' ); ?></label>
							<textarea id="custom_capabilities" name="custom_capabilities" rows="3" placeholder="<?php esc_attr_e( 'custom_capability, another_capability', 'ajforms' ); ?>"></textarea>
						</div>

						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Role', 'ajforms' ); ?></button>
							<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Cancel', 'ajforms' ); ?></a>
						</p>
					</form>
				<?php endif; ?>

				<div class="ajcore-role-panel">
					<div class="ajcore-role-panel-head">
						<h2><?php esc_html_e( 'WordPress Roles', 'ajforms' ); ?></h2>
						<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'role_manager_action', 'add', $base_url ) ); ?>"><?php esc_html_e( 'Add Role', 'ajforms' ); ?></a>
					</div>
					<table class="widefat striped ajcore-role-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Role', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Role Type', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Users', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $roles as $role_key => $role ) : ?>
								<?php
								$user_count = $this->get_role_user_count( $role_key );
								$role_type  = $this->get_role_type_info( $role_key );
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $role['name'] ); ?></strong><br>
										<code><?php echo esc_html( $role_key ); ?></code>
									</td>
									<td>
										<span class="ajcore-role-type-badge <?php echo esc_attr( $role_type['class'] ); ?>"><?php echo esc_html( $role_type['label'] ); ?></span>
									</td>
									<td><?php echo esc_html( number_format_i18n( $user_count ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( add_query_arg( 'edit_role', $role_key, $base_url ) ); ?>"><?php esc_html_e( 'Edit', 'ajforms' ); ?></a>
										<?php if ( ! in_array( $role_key, array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ), true ) ) : ?>
											|
											<?php if ( $user_count > 0 ) : ?>
												<span class="description"><?php esc_html_e( 'Reassign users before delete', 'ajforms' ); ?></span>
											<?php else : ?>
												<a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'role_manager_action' => 'delete', 'role' => $role_key ), $base_url ), 'ajcore_delete_role_' . $role_key ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this role?', 'ajforms' ) ); ?>');"><?php esc_html_e( 'Delete', 'ajforms' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<script>
			(function() {
				const root = document.querySelector('.ajcore-role-manager');
				if (!root) {
					return;
				}

				const capabilityLabels = Array.from(root.querySelectorAll('.ajcore-capability-list label[data-capability]'));
				const searchInput = root.querySelector('.ajcore-capability-search');
				const selectedOnlyInput = root.querySelector('.ajcore-show-selected-only');
				const selectedList = root.querySelector('.ajcore-selected-capability-list');

				function updateSelectedSummary() {
					if (!selectedList) {
						return;
					}

					const selected = capabilityLabels
						.map((label) => label.querySelector('input[type="checkbox"]'))
						.filter((input) => input && input.checked)
						.map((input) => input.value)
						.sort();

					selectedList.innerHTML = '';
					selectedList.classList.toggle('is-empty', selected.length === 0);

					selected.forEach((capability) => {
						const badge = document.createElement('span');
						badge.className = 'ajcore-selected-capability-badge';
						badge.textContent = capability;
						selectedList.appendChild(badge);
					});
				}

				function applyCapabilityFilter() {
					const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
					const selectedOnly = selectedOnlyInput ? selectedOnlyInput.checked : false;

					capabilityLabels.forEach((label) => {
						const capability = (label.dataset.capability || '').toLowerCase();
						const checkbox = label.querySelector('input[type="checkbox"]');
						const isSelected = checkbox && checkbox.checked;
						const matchesSearch = !query || capability.includes(query);
						const matchesSelected = !selectedOnly || isSelected;

						label.style.display = matchesSearch && matchesSelected ? '' : 'none';
					});
				}

				capabilityLabels.forEach((label) => {
					const checkbox = label.querySelector('input[type="checkbox"]');
					if (!checkbox) {
						return;
					}

					checkbox.addEventListener('change', function() {
						updateSelectedSummary();
						applyCapabilityFilter();
					});
				});

				if (searchInput) {
					searchInput.addEventListener('input', applyCapabilityFilter);
				}

				if (selectedOnlyInput) {
					selectedOnlyInput.addEventListener('change', applyCapabilityFilter);
				}

				updateSelectedSummary();
				applyCapabilityFilter();
			})();
			</script>
		</div>
		<?php
	}

	private function display_client_portal_settings_tab( $subsection, $embedded = false ) {
		$subsection = in_array( $subsection, array( 'file-library', 'menu' ), true ) ? $subsection : 'file-library';
		$portal_page_id = absint( get_option( 'ajcore_customer_portal_page_id', 0 ) );
		$portal_url     = $portal_page_id ? get_permalink( $portal_page_id ) : '';
		$menu_items     = $this->get_customer_portal_menu_items();
		$stripe_enabled = '' !== $this->get_stripe_secret_key_for_portal();
		$cache_counts      = $this->get_portal_cache_counts();
		$service_settings = $this->get_customer_portal_services_display_settings();
		$last_db_error     = get_option( 'ajforms_last_portal_db_error', '' );
		?>
		<?php if ( ! $embedded ) : ?>
			<div class="ajforms-settings-head">
				<h2><?php esc_html_e( 'Client Portal', 'ajforms' ); ?></h2>
				<p><?php esc_html_e( 'Control the customer-facing portal page and the menu tabs customers see after login.', 'ajforms' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $_GET['portal-updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Client Portal settings saved.', 'ajforms' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['portal-synced'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Synced %d Stripe records.', 'ajforms' ), absint( wp_unslash( $_GET['portal-synced'] ) ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['portal-user-enabled'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stripe customer enabled as a portal user.', 'ajforms' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['portal-error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
		<?php endif; ?>
		<?php if ( $last_db_error ) : ?>
			<div class="notice notice-warning is-dismissible"><p><?php echo esc_html( sprintf( __( 'Last portal database error: %s', 'ajforms' ), $last_db_error ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'file-library' === $subsection ) : ?>
			<div class="ajforms-settings-card">
				<h3><?php esc_html_e( 'File Library', 'ajforms' ); ?></h3>
				<p><?php esc_html_e( 'File Library is the first built-in Client Portal tab. It displays files assigned to the logged-in user.', 'ajforms' ); ?></p>
				<div class="ajforms-settings-note">
					<p><strong><?php esc_html_e( 'Shortcode', 'ajforms' ); ?></strong>: <code>[aj_customer_portal]</code></p>
					<?php if ( $portal_url ) : ?>
						<p><strong><?php esc_html_e( 'Portal Page', 'ajforms' ); ?></strong>: <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $portal_url ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
		<?php else : ?>
			<div class="ajforms-settings-card">
				<h3><?php esc_html_e( 'Stripe Portal Sync', 'ajforms' ); ?></h3>
				<p><?php esc_html_e( 'Stripe is the source of truth. These buttons refresh local portal cache records only.', 'ajforms' ); ?></p>
				<div class="ajforms-settings-inline-actions">
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d customers cached', 'ajforms' ), $cache_counts['customers'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d products cached', 'ajforms' ), $cache_counts['products'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d Stripe subscriptions cached', 'ajforms' ), $cache_counts['subscriptions'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d billing records cached', 'ajforms' ), $cache_counts['ledger'] ) ); ?></span>
					<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d portal users linked', 'ajforms' ), $cache_counts['mappings'] ) ); ?></span>
				</div>
				<?php if ( ! $stripe_enabled ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Add your Stripe secret key under Settings > Stripe Payments before syncing portal data.', 'ajforms' ); ?></p></div>
				<?php endif; ?>
				<div class="ajforms-settings-inline-actions">
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'sync' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Sync Center', 'ajforms' ); ?></a>
				</div>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'ajcore_save_client_portal', 'ajcore_client_portal_nonce' ); ?>
				<div class="ajforms-settings-card">
					<h3><?php esc_html_e( 'Client Portal Menu', 'ajforms' ); ?></h3>
					<p><?php esc_html_e( 'These items appear as tabs inside the Client Portal. File Library is built in; custom items can link to other public pages.', 'ajforms' ); ?></p>

					<style>
						.ajcore-portal-menu-table input[type="text"],.ajcore-portal-menu-table input[type="url"]{width:100%}
						.ajcore-portal-menu-table td{vertical-align:middle}
					</style>

					<table class="widefat striped ajcore-portal-menu-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Enabled', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Label', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'Type', 'ajforms' ); ?></th>
								<th><?php esc_html_e( 'URL', 'ajforms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $menu_items as $item ) : ?>
								<tr>
									<td>
										<input type="checkbox" name="portal_menu_enabled[]" value="<?php echo esc_attr( $item['id'] ); ?>" <?php checked( ! empty( $item['enabled'] ) ); ?>>
										<input type="hidden" name="portal_menu_type[<?php echo esc_attr( $item['id'] ); ?>]" value="<?php echo esc_attr( $item['type'] ); ?>">
									</td>
									<td>
										<input type="text" name="portal_menu_label[<?php echo esc_attr( $item['id'] ); ?>]" value="<?php echo esc_attr( $item['label'] ); ?>">
									</td>
									<td><?php echo 'built_in' === $item['type'] ? esc_html__( 'Built in', 'ajforms' ) : esc_html__( 'Custom Link', 'ajforms' ); ?></td>
									<td>
										<input type="url" name="portal_menu_url[<?php echo esc_attr( $item['id'] ); ?>]" value="<?php echo esc_attr( $item['url'] ); ?>" <?php disabled( 'built_in' === $item['type'] ); ?>>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<td><?php esc_html_e( 'New', 'ajforms' ); ?></td>
								<td><input type="text" name="new_portal_menu_label" placeholder="<?php esc_attr_e( 'Custom tab label', 'ajforms' ); ?>"></td>
								<td><?php esc_html_e( 'Custom Link', 'ajforms' ); ?></td>
								<td><input type="url" name="new_portal_menu_url" placeholder="<?php echo esc_attr( home_url( '/your-page/' ) ); ?>"></td>
							</tr>
						</tbody>
					</table>


					<div class="ajforms-settings-card" style="margin-top:18px;">
						<h3><?php esc_html_e( 'My Services Page Controls', 'ajforms' ); ?></h3>
						<p><?php esc_html_e( 'Choose which sections appear on the customer-facing My Services page. Add Services products are controlled from Client Portal > Product Catalog using Show in Add Services.', 'ajforms' ); ?></p>

						<div class="ajcore-service-controls" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:16px;">
							<div class="ajcore-service-control-box">
								<h4><?php esc_html_e( 'Sections', 'ajforms' ); ?></h4>
								<label><input type="checkbox" name="portal_services_show_current" value="1" <?php checked( ! empty( $service_settings['show_current_services'] ) ); ?>> <?php esc_html_e( 'Show Current Services', 'ajforms' ); ?></label><br>
								<label><input type="checkbox" name="portal_services_show_add" value="1" <?php checked( ! empty( $service_settings['show_add_services'] ) ); ?>> <?php esc_html_e( 'Show Add Services', 'ajforms' ); ?></label>
							</div>
						</div>
						<p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'products-services' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Product Catalog', 'ajforms' ); ?></a></p>
					</div>

					<div class="ajforms-settings-actions">
						<?php submit_button( __( 'Save Client Portal Menu', 'ajforms' ), 'primary', 'submit', false ); ?>
					</div>
				</div>
			</form>

		<?php endif; ?>
		<?php
	}

	public function display_products_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$settings = $this->get_plugin_settings();
		$cache    = $this->get_stripe_products_cache();
		$prices   = isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array();
		$dependency_settings = $this->get_public_product_dependency_settings();
		$active_count = 0;
		$archived_count = 0;
		foreach ( $prices as $price ) {
			if ( ! empty( $price['product_active'] ) && ! empty( $price['price_active'] ) ) {
				$active_count++;
			} else {
				$archived_count++;
			}
		}
		$sync_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                => 'ajforms-products',
					'ajf_products_action' => 'sync',
				),
				admin_url( 'admin.php' )
			),
			'ajf_sync_stripe_products'
		);
		?>
		<div class="wrap">
			<style>
				.ajcore-products-shell{max-width:1180px;margin-top:20px}
				.ajcore-products-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px;padding:22px 24px;border:1px solid #e5e7eb;border-radius:14px;background:#fff}
				.ajcore-products-hero h1{margin:0 0 8px;font-size:28px;line-height:1.2}
				.ajcore-products-hero p{margin:0;color:#64748b;max-width:760px}
				.ajcore-products-notice{margin:0 0 18px;padding:12px 14px;border-radius:10px}
				.ajcore-products-notice.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
				.ajcore-products-notice.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
				.ajcore-shortcode-builder{margin-bottom:18px;padding:22px 24px;border:1px solid #dbe3ec;border-radius:14px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.05)}
				.ajcore-builder-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}
				.ajcore-builder-head h2{margin:0 0 6px;font-size:21px}
				.ajcore-builder-head p{margin:0;color:#64748b}
				.ajcore-builder-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}
				.ajcore-builder-field{padding:14px;border:1px solid #edf2f7;border-radius:12px;background:#f8fafc}
				.ajcore-builder-field strong{display:block;margin-bottom:10px;color:#111827}
				.ajcore-builder-checks{display:grid;gap:8px}
				.ajcore-builder-checks label{display:flex;align-items:center;gap:8px;margin:0;color:#334155}
				.ajcore-product-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px;margin-bottom:16px}
				.ajcore-product-choice{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
				.ajcore-product-choice.is-archived{background:#f8fafc;color:#64748b}
				.ajcore-product-choice strong{display:block;color:#111827}
				.ajcore-product-choice span{display:block;margin-top:4px;color:#64748b;font-size:12px}
				.ajcore-generated-shortcode{display:flex;gap:10px;align-items:center;margin-top:14px}
				.ajcore-generated-shortcode input{width:100%;font-family:monospace;min-height:42px;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px}
				.ajcore-products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
				.ajcore-product-card{padding:18px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
				.ajcore-product-card h2{margin:0 0 8px;font-size:17px}
				.ajcore-product-price{font-size:24px;font-weight:800;color:#111827;margin:10px 0}
				.ajcore-product-meta{display:grid;gap:5px;color:#64748b;font-size:13px}
				.ajcore-product-dependency-box{margin-top:14px;padding:12px;border:1px solid #dbeafe;border-radius:12px;background:#eff6ff}
				.ajcore-product-dependency-box label{display:block;margin:0 0 8px;font-weight:700;color:#1e3a8a}
				.ajcore-product-dependency-box select,.ajcore-product-dependency-box textarea{width:100%;box-sizing:border-box;margin-top:4px}
				.ajcore-product-dependency-note{margin:8px 0 0;color:#475569;font-size:12px;line-height:1.45}
				.ajcore-shortcode{display:inline-block;margin-top:12px;padding:6px 8px;border-radius:8px;background:#f1f5f9;color:#334155}
				.ajcore-products-empty{padding:24px;border:1px dashed #cbd5e1;border-radius:12px;background:#fff;color:#64748b}
				@media (max-width: 960px){.ajcore-builder-controls{grid-template-columns:1fr}}
			</style>

			<div class="ajcore-products-shell">
				<div class="ajcore-products-hero">
					<div>
						<h1><?php esc_html_e( 'Products', 'ajforms' ); ?></h1>
						<p><?php esc_html_e( 'Sync Stripe Prices here, then place products on any page with the shortcode. One-time and recurring prices can use Stripe Checkout.', 'ajforms' ); ?></p>
					</div>
					<a class="button button-primary" href="<?php echo esc_url( $sync_url ); ?>"><?php esc_html_e( 'Sync Stripe Products', 'ajforms' ); ?></a>
				</div>

				<?php if ( empty( $settings['stripe_secret_key'] ) ) : ?>
					<div class="ajcore-products-notice error"><?php esc_html_e( 'Add your Stripe secret key in Settings > Stripe Payments before syncing products.', 'ajforms' ); ?></div>
				<?php elseif ( isset( $_GET['stripe-sync-error'] ) ) : ?>
					<div class="ajcore-products-notice error"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['stripe-sync-error'] ) ) ); ?></div>
				<?php elseif ( isset( $_GET['stripe-synced'] ) ) : ?>
					<div class="ajcore-products-notice ok">
						<?php
						printf(
							esc_html__( 'Synced %d Stripe prices.', 'ajforms' ),
							absint( wp_unslash( $_GET['stripe-synced'] ) )
						);
						?>
					</div>
				<?php endif; ?>
				<?php if ( isset( $_GET['dependencies-saved'] ) ) : ?>
					<div class="ajcore-products-notice ok"><?php esc_html_e( 'Product dependency settings saved.', 'ajforms' ); ?></div>
				<?php endif; ?>

				<p>
					<strong><?php esc_html_e( 'Page shortcodes:', 'ajforms' ); ?></strong>
					<code>[ajcore_products]</code>
					<code>[ajcore_products mode="cart"]</code>
				</p>

				<?php if ( empty( $prices ) ) : ?>
					<div class="ajcore-products-empty"><?php esc_html_e( 'No synced Stripe prices yet.', 'ajforms' ); ?></div>
				<?php else : ?>
					<div class="ajcore-shortcode-builder" id="ajcore-shortcode-builder">
						<div class="ajcore-builder-head">
							<div>
								<h2><?php esc_html_e( 'Build a Product Shortcode', 'ajforms' ); ?></h2>
								<p><?php esc_html_e( 'Filter, select products, choose what to show, then generate a shortcode for any page.', 'ajforms' ); ?></p>
							</div>
							<button type="button" class="button" id="ajcore-select-visible-products"><?php esc_html_e( 'Select Visible', 'ajforms' ); ?></button>
						</div>

						<div class="ajcore-builder-controls">
							<div class="ajcore-builder-field">
								<strong><?php esc_html_e( 'Filter products', 'ajforms' ); ?></strong>
								<div class="ajcore-builder-checks">
									<label><input type="checkbox" class="ajcore-status-filter" value="active" checked> <?php echo esc_html( sprintf( __( 'Active (%d)', 'ajforms' ), $active_count ) ); ?></label>
									<label><input type="checkbox" class="ajcore-status-filter" value="archived"> <?php echo esc_html( sprintf( __( 'Archived / inactive (%d)', 'ajforms' ), $archived_count ) ); ?></label>
								</div>
							</div>
							<div class="ajcore-builder-field">
								<strong><?php esc_html_e( 'Display mode', 'ajforms' ); ?></strong>
								<div class="ajcore-builder-checks">
									<label><input type="radio" name="ajcore_shortcode_mode" value="buy" checked> <?php esc_html_e( 'Buy Now', 'ajforms' ); ?></label>
									<label><input type="radio" name="ajcore_shortcode_mode" value="cart"> <?php esc_html_e( 'Add to Cart', 'ajforms' ); ?></label>
								</div>
							</div>
							<div class="ajcore-builder-field">
								<strong><?php esc_html_e( 'Fields to show', 'ajforms' ); ?></strong>
								<div class="ajcore-builder-checks">
									<label><input type="checkbox" class="ajcore-display-field" value="title" checked> <?php esc_html_e( 'Product name', 'ajforms' ); ?></label>
									<label><input type="checkbox" class="ajcore-display-field" value="description" checked> <?php esc_html_e( 'Description', 'ajforms' ); ?></label>
									<label><input type="checkbox" class="ajcore-display-field" value="summary"> <?php esc_html_e( 'Short summary', 'ajforms' ); ?></label>
									<label><input type="checkbox" class="ajcore-display-field" value="price" checked> <?php esc_html_e( 'Price', 'ajforms' ); ?></label>
									<label><input type="checkbox" class="ajcore-display-field" value="button" checked> <?php esc_html_e( 'Button', 'ajforms' ); ?></label>
								</div>
							</div>
						</div>

						<div class="ajcore-builder-controls">
							<div class="ajcore-builder-field">
								<strong><?php esc_html_e( 'Template', 'ajforms' ); ?></strong>
								<div class="ajcore-builder-checks">
									<label><input type="radio" name="ajcore_shortcode_template" value="default" checked> <?php esc_html_e( 'Default', 'ajforms' ); ?></label>
									<label><input type="radio" name="ajcore_shortcode_template" value="compact"> <?php esc_html_e( 'Compact', 'ajforms' ); ?></label>
								</div>
							</div>
							<div class="ajcore-builder-field">
								<strong><?php esc_html_e( 'Details', 'ajforms' ); ?></strong>
								<div class="ajcore-builder-checks">
									<label><input type="radio" name="ajcore_shortcode_details" value="none" checked> <?php esc_html_e( 'No expandable details', 'ajforms' ); ?></label>
									<label><input type="radio" name="ajcore_shortcode_details" value="expand"> <?php esc_html_e( 'Expandable details', 'ajforms' ); ?></label>
								</div>
							</div>
						</div>

						<div class="ajcore-product-picker">
							<?php foreach ( $prices as $price ) : ?>
								<?php
								$is_active = ! empty( $price['product_active'] ) && ! empty( $price['price_active'] );
								$state     = $is_active ? 'active' : 'archived';
								?>
								<label class="ajcore-product-choice <?php echo $is_active ? 'is-active' : 'is-archived'; ?>" data-status="<?php echo esc_attr( $state ); ?>">
									<input type="checkbox" class="ajcore-product-select" value="<?php echo esc_attr( $price['id'] ); ?>">
									<span>
										<strong><?php echo esc_html( isset( $price['product_name'] ) ? $price['product_name'] : __( 'Stripe product', 'ajforms' ) ); ?></strong>
										<?php
										$price_interval = ! empty( $price['recurring_interval'] ) ? '/' . sanitize_key( (string) $price['recurring_interval'] ) : '';
										?>
										<span><?php echo esc_html( strtoupper( $price['currency'] ) . ' ' . number_format_i18n( (float) $price['amount'], 2 ) . $price_interval . ' - ' . $price['id'] ); ?></span>
										<?php if ( ! empty( $price['product_description'] ) ) : ?>
											<span><?php echo esc_html( wp_trim_words( $price['product_description'], 16 ) ); ?></span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>

						<div class="ajcore-generated-shortcode">
							<input type="text" id="ajcore-generated-shortcode" readonly value="[ajcore_products]">
							<button type="button" class="button button-primary" id="ajcore-copy-shortcode"><?php esc_html_e( 'Copy', 'ajforms' ); ?></button>
						</div>
					</div>

					<form method="post" class="ajcore-product-dependencies-form">
						<?php wp_nonce_field( 'ajcore_save_product_dependencies', 'ajcore_product_dependencies_nonce' ); ?>
						<div class="ajcore-products-grid">
						<?php foreach ( $prices as $price ) : ?>
							<div class="ajcore-product-card">
								<h2><?php echo esc_html( isset( $price['product_name'] ) ? $price['product_name'] : __( 'Stripe product', 'ajforms' ) ); ?></h2>
								<?php if ( empty( $price['product_active'] ) || empty( $price['price_active'] ) ) : ?>
									<p style="margin:0 0 8px;color:#b45309;font-weight:700;"><?php esc_html_e( 'Archived / inactive', 'ajforms' ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $price['product_description'] ) ) : ?>
									<p style="margin:0 0 10px;color:#64748b;"><?php echo esc_html( $price['product_description'] ); ?></p>
								<?php endif; ?>
								<?php $price_interval = ! empty( $price['recurring_interval'] ) ? '/' . sanitize_key( (string) $price['recurring_interval'] ) : ''; ?>
								<div class="ajcore-product-price"><?php echo esc_html( strtoupper( $price['currency'] ) . ' ' . number_format_i18n( (float) $price['amount'], 2 ) . $price_interval ); ?></div>
								<div class="ajcore-product-meta">
									<span><?php echo esc_html( 'Price: ' . $price['id'] ); ?></span>
									<span><?php echo esc_html( 'Product: ' . $price['product_id'] ); ?></span>
								</div>
								<?php
								$dependency = isset( $dependency_settings[ $price['id'] ] ) ? $dependency_settings[ $price['id'] ] : array();
								$selected_required_price = ! empty( $dependency['requires_price_id'] ) ? $dependency['requires_price_id'] : ( ! empty( $price['requires_price_id'] ) ? $price['requires_price_id'] : '' );
								$dependency_note = ! empty( $dependency['dependency_note'] ) ? $dependency['dependency_note'] : ( ! empty( $price['dependency_note'] ) ? $price['dependency_note'] : '' );
								?>
								<div class="ajcore-product-dependency-box">
									<label>
										<?php esc_html_e( 'Requires another product', 'ajforms' ); ?>
										<select name="product_dependencies[<?php echo esc_attr( $price['id'] ); ?>][requires_price_id]">
											<option value=""><?php esc_html_e( 'No dependency', 'ajforms' ); ?></option>
											<?php foreach ( $prices as $dependency_price ) : ?>
												<?php if ( empty( $dependency_price['id'] ) || $dependency_price['id'] === $price['id'] ) { continue; } ?>
												<option value="<?php echo esc_attr( $dependency_price['id'] ); ?>" <?php selected( $selected_required_price, $dependency_price['id'] ); ?>><?php echo esc_html( $dependency_price['product_name'] . ' — ' . strtoupper( $dependency_price['currency'] ) . ' ' . number_format_i18n( (float) $dependency_price['amount'], 2 ) . ( ! empty( $dependency_price['recurring_interval'] ) ? '/' . sanitize_key( (string) $dependency_price['recurring_interval'] ) : '' ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>
										<?php esc_html_e( 'Dependency note shown to customer', 'ajforms' ); ?>
										<textarea rows="3" name="product_dependencies[<?php echo esc_attr( $price['id'] ); ?>][dependency_note]" placeholder="<?php esc_attr_e( 'Example: This subscription requires Virtual Office Setup. We added it to your cart automatically.', 'ajforms' ); ?>"><?php echo esc_textarea( $dependency_note ); ?></textarea>
									</label>
									<p class="ajcore-product-dependency-note"><?php esc_html_e( 'When this product is added to cart, the required product will be added automatically with quantity 1.', 'ajforms' ); ?></p>
								</div>
								<code class="ajcore-shortcode">[ajcore_products price_ids="<?php echo esc_attr( $price['id'] ); ?>"]</code>
							</div>
						<?php endforeach; ?>
						</div>
						<p style="margin-top:16px;"><?php submit_button( __( 'Save Product Dependencies', 'ajforms' ), 'primary', 'submit', false ); ?></p>
					</form>
					<script>
					(function() {
						const builder = document.getElementById('ajcore-shortcode-builder');
						if (!builder) {
							return;
						}
						const statusFilters = Array.from(builder.querySelectorAll('.ajcore-status-filter'));
						const productChoices = Array.from(builder.querySelectorAll('.ajcore-product-choice'));
						const productInputs = Array.from(builder.querySelectorAll('.ajcore-product-select'));
						const displayInputs = Array.from(builder.querySelectorAll('.ajcore-display-field'));
						const output = document.getElementById('ajcore-generated-shortcode');
						const copyButton = document.getElementById('ajcore-copy-shortcode');
						const selectVisibleButton = document.getElementById('ajcore-select-visible-products');

						function selectedStatuses() {
							return statusFilters.filter((input) => input.checked).map((input) => input.value);
						}

						function updateVisibility() {
							const statuses = selectedStatuses();
							productChoices.forEach((choice) => {
								const visible = statuses.includes(choice.dataset.status);
								choice.style.display = visible ? 'flex' : 'none';
								if (!visible) {
									const input = choice.querySelector('.ajcore-product-select');
									if (input) {
										input.checked = false;
									}
								}
							});
							updateShortcode();
						}

						function updateShortcode() {
							const modeInput = builder.querySelector('input[name="ajcore_shortcode_mode"]:checked');
							const templateInput = builder.querySelector('input[name="ajcore_shortcode_template"]:checked');
							const detailsInput = builder.querySelector('input[name="ajcore_shortcode_details"]:checked');
							const mode = modeInput ? modeInput.value : 'buy';
							const template = templateInput ? templateInput.value : 'default';
							const details = detailsInput ? detailsInput.value : 'none';
							const priceIds = productInputs.filter((input) => input.checked).map((input) => input.value);
							const fields = displayInputs.filter((input) => input.checked).map((input) => input.value);
							const includeArchived = selectedStatuses().includes('archived') ? 'yes' : '';
							const attrs = [];
							if (mode !== 'buy') {
								attrs.push('mode="' + mode + '"');
							}
							if (template !== 'default') {
								attrs.push('template="' + template + '"');
							}
							if (details !== 'none') {
								attrs.push('details="' + details + '"');
							}
							if (priceIds.length) {
								attrs.push('price_ids="' + priceIds.join(',') + '"');
							}
							if (fields.length && fields.join(',') !== 'title,description,price,button') {
								attrs.push('show="' + fields.join(',') + '"');
							}
							if (includeArchived) {
								attrs.push('include_archived="yes"');
							}
							output.value = '[ajcore_products' + (attrs.length ? ' ' + attrs.join(' ') : '') + ']';
						}

						statusFilters.forEach((input) => input.addEventListener('change', updateVisibility));
						productInputs.forEach((input) => input.addEventListener('change', updateShortcode));
						displayInputs.forEach((input) => input.addEventListener('change', updateShortcode));
						builder.querySelectorAll('input[name="ajcore_shortcode_mode"]').forEach((input) => input.addEventListener('change', updateShortcode));
						builder.querySelectorAll('input[name="ajcore_shortcode_template"]').forEach((input) => input.addEventListener('change', updateShortcode));
						builder.querySelectorAll('input[name="ajcore_shortcode_details"]').forEach((input) => input.addEventListener('change', updateShortcode));
						selectVisibleButton.addEventListener('click', function() {
							productChoices.forEach((choice) => {
								if (choice.style.display !== 'none') {
									const input = choice.querySelector('.ajcore-product-select');
									if (input) {
										input.checked = true;
									}
								}
							});
							updateShortcode();
						});
						copyButton.addEventListener('click', function() {
							output.select();
							document.execCommand('copy');
						});
						updateVisibility();
					})();
					</script>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function handle_lead_actions() {
		global $wpdb;

		$leads_table      = $this->get_leads_table();
		$lead_notes_table = $this->get_lead_notes_table();

		if ( isset( $_POST['ajf_update_lead_id'], $_POST['ajf_update_lead_nonce'] ) ) {
			$lead_id = absint( wp_unslash( $_POST['ajf_update_lead_id'] ) );

			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajf_update_lead_nonce'] ) ), 'ajf_update_lead_' . $lead_id ) ) {
				$result = $this->update_lead_entry( $lead_id );
				$redirect = add_query_arg(
					array(
						'page'         => 'ajforms-leads',
						'view'         => 'detail',
						'lead_id'      => $lead_id,
						'entry-updated'=> $result['success'] ? '1' : '0',
						'entry-message'=> rawurlencode( $result['message'] ),
					),
					admin_url( 'admin.php' )
				);

				wp_safe_redirect( $redirect );
				exit;
			}
		}

		if ( isset( $_POST['ajf_add_note_lead_id'], $_POST['ajf_lead_note'], $_POST['_wpnonce'] ) ) {
			$lead_id = absint( wp_unslash( $_POST['ajf_add_note_lead_id'] ) );

			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ajf_add_lead_note_' . $lead_id ) ) {
				$note = sanitize_textarea_field( wp_unslash( $_POST['ajf_lead_note'] ) );

				if ( '' !== $note ) {
					$wpdb->insert(
						$lead_notes_table,
						array(
							'lead_id'     => $lead_id,
							'note'        => $note,
							'created_by'  => get_current_user_id(),
							'created_at'  => current_time( 'mysql' ),
						),
						array( '%d', '%s', '%d', '%s' )
					);
				}

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'         => 'ajforms-leads',
							'view'         => 'detail',
							'lead_id'      => $lead_id,
							'note-updated' => '' !== $note ? '1' : '0',
							'note-message' => rawurlencode( '' !== $note ? __( 'Note added.', 'ajforms' ) : __( 'Please enter a note before saving.', 'ajforms' ) ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		if ( isset( $_GET['lead_action'], $_GET['lead_id'], $_GET['_wpnonce'] ) ) {
			$action  = sanitize_text_field( wp_unslash( $_GET['lead_action'] ) );
			$lead_id = absint( wp_unslash( $_GET['lead_id'] ) );
			$nonce   = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

			if ( ! wp_verify_nonce( $nonce, 'ajf_lead_action_' . $lead_id ) ) {
				return;
			}

			if ( 'mark_read' === $action ) {
				$wpdb->update(
					$leads_table,
					array( 'status' => 'read' ),
					array( 'id' => $lead_id ),
					array( '%s' ),
					array( '%d' )
				);
			} elseif ( 'mark_unread' === $action ) {
				$wpdb->update(
					$leads_table,
					array( 'status' => 'unread' ),
					array( 'id' => $lead_id ),
					array( '%s' ),
					array( '%d' )
				);
			} elseif ( 'delete' === $action ) {
				$wpdb->delete( $lead_notes_table, array( 'lead_id' => $lead_id ), array( '%d' ) );
				$wpdb->delete( $leads_table, array( 'id' => $lead_id ), array( '%d' ) );
			}

			$redirect = admin_url( 'admin.php?page=ajforms-leads' );

			if ( isset( $_GET['view'] ) && 'detail' === sanitize_text_field( wp_unslash( $_GET['view'] ) ) && 'delete' !== $action ) {
				$redirect = add_query_arg(
					array(
						'page'    => 'ajforms-leads',
						'view'    => 'detail',
						'lead_id' => $lead_id,
					),
					admin_url( 'admin.php' )
				);
			}

			wp_safe_redirect( $redirect );
			exit;
		}
	}


	private function handle_auth_settings_save() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['ajcore_auth_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajcore_save_auth_settings', 'ajcore_auth_nonce' );

		$settings = array(
			'customer_role'       => isset( $_POST['customer_role'] ) ? sanitize_key( wp_unslash( $_POST['customer_role'] ) ) : 'aj_portal_user',
			'block_wp_admin'      => isset( $_POST['block_wp_admin'] ) ? '1' : '0',
			'hide_admin_bar'      => isset( $_POST['hide_admin_bar'] ) ? '1' : '0',
			'login_redirect_mode' => isset( $_POST['login_redirect_mode'] ) && 'portal' === sanitize_key( wp_unslash( $_POST['login_redirect_mode'] ) ) ? 'portal' : 'default',
		);

		update_option( 'ajcore_auth_settings', $settings, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-auth', 'settings-updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function display_auth_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$settings = get_option( 'ajcore_auth_settings', array() );
		$settings = is_array( $settings ) ? wp_parse_args(
			$settings,
			array(
				'customer_role'       => 'aj_portal_user',
				'block_wp_admin'      => '1',
				'hide_admin_bar'      => '1',
				'login_redirect_mode' => 'portal',
			)
		) : array(
			'customer_role'       => 'aj_portal_user',
			'block_wp_admin'      => '1',
			'hide_admin_bar'      => '1',
			'login_redirect_mode' => 'portal',
		);
		$roles = wp_roles()->roles;
		?>
		<div class="wrap ajforms-admin-shell">
			<div class="ajforms-admin-hero" style="margin:18px 0;">
				<div>
					<h1><?php esc_html_e( 'Auth', 'ajforms' ); ?></h1>
					<p><?php esc_html_e( 'Control the customer role and portal login behavior used by AJ Core.', 'ajforms' ); ?></p>
				</div>
			</div>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Auth settings saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'ajcore_save_auth_settings', 'ajcore_auth_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="customer_role"><?php esc_html_e( 'Customer Portal Role', 'ajforms' ); ?></label></th>
						<td><select name="customer_role" id="customer_role">
							<?php foreach ( $roles as $role_key => $role ) : ?>
								<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $settings['customer_role'], $role_key ); ?>><?php echo esc_html( translate_user_role( $role['name'] ) . ' (' . $role_key . ')' ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr><th scope="row"><?php esc_html_e( 'Portal User Protection', 'ajforms' ); ?></th><td>
						<label><input type="checkbox" name="block_wp_admin" value="1" <?php checked( '1', $settings['block_wp_admin'] ); ?>> <?php esc_html_e( 'Redirect portal users away from wp-admin', 'ajforms' ); ?></label><br>
						<label><input type="checkbox" name="hide_admin_bar" value="1" <?php checked( '1', $settings['hide_admin_bar'] ); ?>> <?php esc_html_e( 'Hide admin bar for portal users', 'ajforms' ); ?></label>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Login Redirect', 'ajforms' ); ?></th><td>
						<label><input type="radio" name="login_redirect_mode" value="portal" <?php checked( 'portal', $settings['login_redirect_mode'] ); ?>> <?php esc_html_e( 'Send portal users to Client Portal', 'ajforms' ); ?></label><br>
						<label><input type="radio" name="login_redirect_mode" value="default" <?php checked( 'default', $settings['login_redirect_mode'] ); ?>> <?php esc_html_e( 'Use WordPress default redirect', 'ajforms' ); ?></label>
					</td></tr>
				</table>
				<?php submit_button( __( 'Save Auth Settings', 'ajforms' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function handle_automations_settings_save() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['ajcore_automations_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajcore_save_automations_settings', 'ajcore_automations_nonce' );
		$settings = array(
			'enabled'              => isset( $_POST['automations_enabled'] ) ? '1' : '0',
			'portal_task_defaults' => isset( $_POST['portal_task_defaults'] ) ? '1' : '0',
			'notes'                => isset( $_POST['automation_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['automation_notes'] ) ) : '',
		);
		update_option( 'ajcore_automations_settings', $settings, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ajforms-automations', 'settings-updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function display_automations_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$settings = get_option( 'ajcore_automations_settings', array() );
		$settings = is_array( $settings ) ? wp_parse_args( $settings, array( 'enabled' => '1', 'portal_task_defaults' => '1', 'notes' => '' ) ) : array( 'enabled' => '1', 'portal_task_defaults' => '1', 'notes' => '' );
		?>
		<div class="wrap ajforms-admin-shell">
			<div class="ajforms-admin-hero" style="margin:18px 0;">
				<div>
					<h1><?php esc_html_e( 'Automations', 'ajforms' ); ?></h1>
					<p><?php esc_html_e( 'Manage lightweight workflow settings for portal tasks and future automated actions.', 'ajforms' ); ?></p>
				</div>
			</div>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Automation settings saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'ajcore_save_automations_settings', 'ajcore_automations_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Automation Engine', 'ajforms' ); ?></th><td><label><input type="checkbox" name="automations_enabled" value="1" <?php checked( '1', $settings['enabled'] ); ?>> <?php esc_html_e( 'Enable AJ Core automations', 'ajforms' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Portal Task Defaults', 'ajforms' ); ?></th><td><label><input type="checkbox" name="portal_task_defaults" value="1" <?php checked( '1', $settings['portal_task_defaults'] ); ?>> <?php esc_html_e( 'Show default compliance reminders when no custom task replaces them', 'ajforms' ); ?></label></td></tr>
					<tr><th scope="row"><label for="automation_notes"><?php esc_html_e( 'Internal Notes', 'ajforms' ); ?></label></th><td><textarea id="automation_notes" name="automation_notes" class="large-text" rows="6"><?php echo esc_textarea( $settings['notes'] ); ?></textarea></td></tr>
				</table>
				<?php submit_button( __( 'Save Automation Settings', 'ajforms' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function display_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$section    = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'general';
		$subsection = isset( $_GET['subsection'] ) ? sanitize_key( wp_unslash( $_GET['subsection'] ) ) : '';

		$settings = $this->get_plugin_settings();
		$asana_cache = $this->get_asana_reference_cache();
		$stripe_cache = $this->get_stripe_products_cache();
		$stripe_prices = isset( $stripe_cache['prices'] ) && is_array( $stripe_cache['prices'] ) ? $stripe_cache['prices'] : array();
		$selected_stripe_prices = isset( $settings['stripe_selected_prices'] ) && is_array( $settings['stripe_selected_prices'] ) ? $settings['stripe_selected_prices'] : array();
		$sections = array(
			'general'      => array(
				'label' => __( 'General Settings', 'ajforms' ),
				'icon'  => 'admin-generic',
			),
			'email-templates' => array(
				'label' => __( 'WP Email Templates', 'ajforms' ),
				'icon'  => 'email-alt',
			),
			'spam'         => array(
				'label' => __( 'Spam Protection', 'ajforms' ),
				'icon'  => 'warning',
			),
			'integrations' => array(
				'label' => __( 'Integrations', 'ajforms' ),
				'icon'  => 'admin-links',
			),
			'payments'     => array(
				'label' => __( 'Stripe Payments', 'ajforms' ),
				'icon'  => 'cart',
			),
			'role-manager' => array(
				'label' => __( 'Role Manager', 'ajforms' ),
				'icon'  => 'admin-users',
			),
		);

		if ( ! isset( $sections[ $section ] ) ) {
			$section = 'general';
		}

		if ( empty( $subsection ) && ! empty( $sections[ $section ]['children'] ) ) {
			$subsection = array_key_first( $sections[ $section ]['children'] );
		}
		?>
		<div class="wrap">
			<style>
				.ajforms-settings-shell{margin-top:18px;background:#f7f7f9;border:1px solid #e5e7eb;border-radius:24px;overflow:hidden;box-shadow:0 18px 45px rgba(15,23,42,.06)}
				.ajforms-settings-topbar{display:flex;align-items:center;gap:28px;padding:0 28px;background:#fff;border-bottom:1px solid #eceef2;min-height:74px}
				.ajforms-settings-brand{display:flex;align-items:center;gap:14px;margin-right:8px}
				.ajforms-settings-brand-badge{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:18px;letter-spacing:.04em}
				.ajforms-settings-brand-title{font-size:28px;font-weight:700;color:#1f2937}
				.ajforms-settings-layout{display:grid;grid-template-columns:320px 1fr;min-height:720px}
				.ajforms-settings-sidebar{background:#fff;border-right:1px solid #eceef2;padding:28px 0}
				.ajforms-settings-menu{display:flex;flex-direction:column;gap:8px}
				.ajforms-settings-link{display:flex;align-items:center;gap:14px;padding:14px 28px;color:#4b5563;text-decoration:none;font-size:18px;font-weight:600}
				.ajforms-settings-link .dashicons{font-size:22px;width:22px;height:22px}
				.ajforms-settings-link.is-active{color:#111827}
				.ajforms-settings-group{margin-top:12px}
				.ajforms-settings-sublinks{margin:10px 0 0 52px;padding-left:18px;border-left:1px solid #e5e7eb;display:flex;flex-direction:column;gap:8px}
				.ajforms-settings-sublinks a{padding:12px 16px;border:1px solid transparent;border-radius:16px;color:#4b5563;text-decoration:none;font-size:16px}
				.ajforms-settings-sublinks a.is-active{border-color:#fb923c;background:#fff7ed;color:#111827}
				.ajforms-settings-content{padding:52px 56px}
				.ajforms-settings-head h2{margin:0 0 10px;font-size:28px;line-height:1.2;color:#111827}
				.ajforms-settings-head p{margin:0;color:#6b7280;font-size:16px;max-width:920px}
				.ajforms-settings-card{margin-top:28px;background:#fff;border:1px solid #eceef2;border-radius:24px;padding:28px 30px;box-shadow:0 10px 30px rgba(15,23,42,.04)}
				.ajforms-settings-card h3{margin:0 0 8px;font-size:20px;color:#111827}
				.ajforms-settings-card > p{margin:0 0 24px;color:#6b7280;font-size:15px}
				.ajforms-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}
				.ajforms-settings-field label{display:block;margin-bottom:8px;font-weight:600;color:#111827}
				.ajforms-settings-field input[type="text"],.ajforms-settings-field input[type="url"],.ajforms-settings-field textarea,.ajforms-settings-field select{width:100%;min-height:46px;border:1px solid #d1d5db;border-radius:14px;padding:11px 14px;background:#fff}
				.ajforms-settings-field textarea{min-height:96px}
				.ajforms-settings-help{margin-top:8px;color:#6b7280;font-size:13px}
				.ajforms-settings-checkbox{display:flex;align-items:flex-start;gap:12px;padding:16px 18px;border:1px solid #eceef2;border-radius:18px;background:#fcfcfd}
				.ajforms-settings-checkbox input{margin-top:2px}
				.ajforms-settings-checkbox strong{display:block;color:#111827;margin-bottom:2px}
				.ajforms-settings-note{margin-top:18px;padding:18px 20px;border-radius:18px;background:#f9fafb;color:#4b5563;font-size:15px}
				.ajforms-settings-pill{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;background:#fff7ed;color:#c2410c;font-weight:700;font-size:12px;letter-spacing:.04em;text-transform:uppercase}
				.ajforms-settings-actions{margin-top:28px;display:flex;align-items:center;gap:14px}
				.ajforms-settings-actions .button-primary{background:#ea580c;border-color:#ea580c;padding:0 18px;min-height:42px}
				.ajforms-settings-inline-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px}
				.ajforms-spam-layout{display:grid;grid-template-columns:280px 1fr;gap:18px;align-items:start}
				.ajforms-provider-picker{padding:18px;border:1px solid #eceef2;border-radius:20px;background:#fff}
				.ajforms-provider-editor{padding:18px;border:1px solid #eceef2;border-radius:20px;background:#fff}
				.ajforms-provider-editor .ajforms-settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
				.ajforms-settings-help-links{display:flex;gap:10px;flex-wrap:wrap}
				#spam_challenge_provider{
					appearance:none;
					-webkit-appearance:none;
					-moz-appearance:none;
					padding-right:44px;
					font-weight:600;
					background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 20 20' fill='none'%3E%3Cpath d='M5 7.5L10 12.5L15 7.5' stroke='%23475569' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
					background-repeat:no-repeat;
					background-position:right 14px center;
					background-size:16px 16px;
					box-shadow:0 1px 2px rgba(15,23,42,.04);
					cursor:pointer;
				}
				@media (max-width: 1100px){
					.ajforms-settings-layout{grid-template-columns:1fr}
					.ajforms-settings-sidebar{border-right:0;border-bottom:1px solid #eceef2}
					.ajforms-settings-grid{grid-template-columns:1fr}
					.ajforms-spam-layout,.ajforms-provider-editor .ajforms-settings-grid{grid-template-columns:1fr}
				}
			</style>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ajforms' ); ?></p></div>
			<?php endif; ?>

			<div class="ajforms-settings-shell">
				<div class="ajforms-settings-topbar">
					<div class="ajforms-settings-brand">
						<div class="ajforms-settings-brand-badge">F</div>
						<div class="ajforms-settings-brand-title"><?php esc_html_e( 'Settings', 'ajforms' ); ?></div>
					</div>
				</div>

				<div class="ajforms-settings-layout">
					<aside class="ajforms-settings-sidebar">
						<nav class="ajforms-settings-menu" aria-label="<?php esc_attr_e( 'Settings navigation', 'ajforms' ); ?>">
							<?php foreach ( $sections as $section_key => $section_config ) : ?>
								<?php
								$section_url = add_query_arg(
									array(
										'page'       => 'ajforms-settings',
										'section'    => $section_key,
										'subsection' => ! empty( $section_config['children'] ) ? array_key_first( $section_config['children'] ) : '',
									),
									admin_url( 'admin.php' )
								);
								?>
								<div class="ajforms-settings-group">
									<a href="<?php echo esc_url( $section_url ); ?>" class="ajforms-settings-link <?php echo $section === $section_key ? 'is-active' : ''; ?>">
										<span class="dashicons dashicons-<?php echo esc_attr( $section_config['icon'] ); ?>"></span>
										<span><?php echo esc_html( $section_config['label'] ); ?></span>
									</a>
									<?php if ( ! empty( $section_config['children'] ) ) : ?>
										<div class="ajforms-settings-sublinks">
											<?php foreach ( $section_config['children'] as $child_key => $child_label ) : ?>
												<?php
												$child_url = add_query_arg(
													array(
														'page'       => 'ajforms-settings',
														'section'    => $section_key,
														'subsection' => $child_key,
													),
													admin_url( 'admin.php' )
												);
												?>
												<a href="<?php echo esc_url( $child_url ); ?>" class="<?php echo $section === $section_key && $subsection === $child_key ? 'is-active' : ''; ?>"><?php echo esc_html( $child_label ); ?></a>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</nav>
					</aside>

					<div class="ajforms-settings-content">
						<?php if ( 'role-manager' === $section ) : ?>
							<div class="ajforms-settings-head">
								<h2><?php esc_html_e( 'Role Manager', 'ajforms' ); ?></h2>
								<p><?php esc_html_e( 'View, add, edit, and delete WordPress roles used by AJ Core and the rest of the site.', 'ajforms' ); ?></p>
							</div>
							<?php $this->display_role_manager_page( true ); ?>
						<?php else : ?>
						<form method="post">
				<?php wp_nonce_field( 'ajforms_save_settings', 'ajforms_settings_nonce' ); ?>
							<div class="ajforms-settings-head">
								<?php if ( 'general' === $section ) : ?>
									<h2><?php esc_html_e( 'General Settings', 'ajforms' ); ?></h2>
									<p><?php esc_html_e( 'Set the defaults AJ Core should use for notifications, reply behavior, and submission feedback across your forms.', 'ajforms' ); ?></p>
								<?php elseif ( 'spam' === $section ) : ?>
									<h2><?php esc_html_e( 'Spam Protection', 'ajforms' ); ?></h2>
									<p><?php esc_html_e( 'Manage the site-wide spam defaults and whichever one challenge provider you want ready to use.', 'ajforms' ); ?></p>
								<?php elseif ( 'integrations' === $section ) : ?>
									<h2><?php esc_html_e( 'Integrations', 'ajforms' ); ?></h2>
									<p><?php esc_html_e( 'Prepare outbound hooks and future service connections so your form submissions can feed the rest of your stack.', 'ajforms' ); ?></p>
								<?php elseif ( 'payments' === $section ) : ?>
									<h2><?php esc_html_e( 'Stripe Payments', 'ajforms' ); ?></h2>
									<p><?php esc_html_e( 'Connect Stripe here once, then choose which individual forms should use it from the builder.', 'ajforms' ); ?></p>
								<?php elseif ( 'email-templates' === $section ) : ?>
									<h2><?php esc_html_e( 'WP Email Templates', 'ajforms' ); ?></h2>
									<p><?php esc_html_e( 'Control WordPress transactional email branding and subjects so customer-facing messages do not expose internal email addresses.', 'ajforms' ); ?></p>
								<?php endif; ?>
							</div>

							<?php if ( 'general' === $section ) : ?>
								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Notifications', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Default email delivery', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'These values seed new forms automatically, while still letting you override them per form in the builder.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-grid">
										<div class="ajforms-settings-field">
											<label for="default_notification_email"><?php esc_html_e( 'Default Notification Email', 'ajforms' ); ?></label>
											<input name="default_notification_email" id="default_notification_email" type="text" value="<?php echo esc_attr( $settings['default_notification_email'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Comma-separated addresses are supported.', 'ajforms' ); ?></div>
										</div>
										<div class="ajforms-settings-field">
											<label for="default_notification_subject"><?php esc_html_e( 'Default Notification Subject', 'ajforms' ); ?></label>
											<input name="default_notification_subject" id="default_notification_subject" type="text" value="<?php echo esc_attr( $settings['default_notification_subject'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Use {form_title}, {submission_fields}, numbered tags like {field_1}, or custom field names like {email}.', 'ajforms' ); ?></div>
										</div>
										<div class="ajforms-settings-field">
											<label for="default_from_name"><?php esc_html_e( 'From Name', 'ajforms' ); ?></label>
											<input name="default_from_name" id="default_from_name" type="text" value="<?php echo esc_attr( $settings['default_from_name'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Displayed in outgoing notifications when your mailer supports it.', 'ajforms' ); ?></div>
										</div>
										<div class="ajforms-settings-field">
											<label for="default_reply_to_mode"><?php esc_html_e( 'Reply-To Behavior', 'ajforms' ); ?></label>
											<select name="default_reply_to_mode" id="default_reply_to_mode">
												<option value="submitter" <?php selected( $settings['default_reply_to_mode'], 'submitter' ); ?>><?php esc_html_e( 'Use submitter email when available', 'ajforms' ); ?></option>
												<option value="site" <?php selected( $settings['default_reply_to_mode'], 'site' ); ?>><?php esc_html_e( 'Keep site mail headers only', 'ajforms' ); ?></option>
											</select>
										</div>
									</div>
									<div class="ajforms-settings-checkbox" style="margin-top:22px;">
										<input name="default_notifications_enabled" id="default_notifications_enabled" type="checkbox" value="1" <?php checked( '1' === (string) $settings['default_notifications_enabled'] ); ?>>
										<div>
											<strong><?php esc_html_e( 'Enable notifications by default', 'ajforms' ); ?></strong>
											<span><?php esc_html_e( 'Every new form starts with notifications turned on unless you switch it off in the builder.', 'ajforms' ); ?></span>
										</div>
									</div>
								</div>

								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Submission UX', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Default success state', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'Give new forms a cleaner completion experience right out of the gate.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-field">
										<label for="default_success_message"><?php esc_html_e( 'Success Message', 'ajforms' ); ?></label>
										<textarea name="default_success_message" id="default_success_message"><?php echo esc_textarea( $settings['default_success_message'] ); ?></textarea>
										<div class="ajforms-settings-help"><?php esc_html_e( 'Used as the starting success message when building a new form.', 'ajforms' ); ?></div>
									</div>
								</div>
							<?php elseif ( 'email-templates' === $section ) : ?>
								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'WordPress Mail', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Sender identity', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'These values are used for WordPress and AJ Core system emails. Keep internal inboxes out of From and Reply-To headers.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-checkbox" style="margin-bottom:22px;">
										<input name="wp_email_templates_enabled" id="wp_email_templates_enabled" type="checkbox" value="1" <?php checked( '1' === (string) $settings['wp_email_templates_enabled'] ); ?>>
										<div>
											<strong><?php esc_html_e( 'Use AJ Core branded WordPress email templates', 'ajforms' ); ?></strong>
											<span><?php esc_html_e( 'Currently applies to WordPress password reset emails and AJ Core portal password/welcome emails.', 'ajforms' ); ?></span>
										</div>
									</div>
									<div class="ajforms-settings-grid">
										<div class="ajforms-settings-field">
											<label for="wp_email_from_email"><?php esc_html_e( 'System From Email', 'ajforms' ); ?></label>
											<input name="wp_email_from_email" id="wp_email_from_email" type="text" value="<?php echo esc_attr( $settings['wp_email_from_email'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Recommended: donotreply@ncllcagents.com', 'ajforms' ); ?></div>
										</div>
										<div class="ajforms-settings-field">
											<label for="wp_email_from_name"><?php esc_html_e( 'System From Name', 'ajforms' ); ?></label>
											<input name="wp_email_from_name" id="wp_email_from_name" type="text" value="<?php echo esc_attr( $settings['wp_email_from_name'] ); ?>">
										</div>
									</div>
								</div>

								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Templates', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Email subjects', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'The HTML design is handled by AJ Core. Edit the customer-facing subjects here.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-grid">
										<div class="ajforms-settings-field">
											<label for="wp_password_reset_subject"><?php esc_html_e( 'Password Reset Subject', 'ajforms' ); ?></label>
											<input name="wp_password_reset_subject" id="wp_password_reset_subject" type="text" value="<?php echo esc_attr( $settings['wp_password_reset_subject'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Used for WordPress Users reset password and AJ Core portal reset password.', 'ajforms' ); ?></div>
										</div>
										<div class="ajforms-settings-field">
											<label for="wp_welcome_email_subject"><?php esc_html_e( 'Portal Welcome Subject', 'ajforms' ); ?></label>
											<input name="wp_welcome_email_subject" id="wp_welcome_email_subject" type="text" value="<?php echo esc_attr( $settings['wp_welcome_email_subject'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Used by the Portal Users bulk Send Welcome Email action.', 'ajforms' ); ?></div>
										</div>
									</div>
									<div class="ajforms-settings-note">
										<strong><?php esc_html_e( 'Email types currently controlled:', 'ajforms' ); ?></strong>
										<ul style="margin:10px 0 0 18px;list-style:disc;">
											<li><?php esc_html_e( 'WordPress password reset from Users / wp-login.php', 'ajforms' ); ?></li>
											<li><?php esc_html_e( 'AJ Core Portal Users reset password', 'ajforms' ); ?></li>
											<li><?php esc_html_e( 'AJ Core Portal Users welcome email', 'ajforms' ); ?></li>
											<li><?php esc_html_e( 'AJ Core form notification sender fallback', 'ajforms' ); ?></li>
										</ul>
									</div>
								</div>
							<?php elseif ( 'spam' === $section ) : ?>
								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Spam Protection', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Honeypot', 'ajforms' ); ?></h3>
									<div class="ajforms-settings-checkbox">
										<input name="honeypot_enabled" id="honeypot_enabled" type="checkbox" value="1" <?php checked( '1' === (string) $settings['honeypot_enabled'] ); ?>>
										<div>
											<strong><?php esc_html_e( 'Enable Honeypot by default', 'ajforms' ); ?></strong>
											<span><?php esc_html_e( 'Applies to both new and existing forms because the spam check runs during submission, not only when a form is created.', 'ajforms' ); ?></span>
										</div>
									</div>
								</div>

								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Spam Protection', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Challenge Providers', 'ajforms' ); ?></h3>
									<div class="ajforms-spam-layout">
										<div class="ajforms-provider-picker">
											<div class="ajforms-settings-field">
												<label for="spam_challenge_provider"><?php esc_html_e( 'Provider', 'ajforms' ); ?></label>
												<select name="spam_challenge_provider" id="spam_challenge_provider">
													<option value=""><?php esc_html_e( 'None selected', 'ajforms' ); ?></option>
													<option value="recaptcha" <?php selected( $settings['spam_challenge_provider'], 'recaptcha' ); ?>><?php esc_html_e( 'reCAPTCHA', 'ajforms' ); ?></option>
													<option value="hcaptcha" <?php selected( $settings['spam_challenge_provider'], 'hcaptcha' ); ?>><?php esc_html_e( 'hCaptcha by Intuition Machines', 'ajforms' ); ?></option>
													<option value="turnstile" <?php selected( $settings['spam_challenge_provider'], 'turnstile' ); ?>><?php esc_html_e( 'Turnstile by Cloudflare', 'ajforms' ); ?></option>
												</select>
												<div class="ajforms-settings-help"><?php esc_html_e( 'Choose one challenge provider. Honeypot remains compatible with any one of these.', 'ajforms' ); ?></div>
											</div>
										</div>

										<div class="ajforms-provider-editor">
											<div class="ajforms-settings-grid">
												<div class="ajforms-settings-field">
													<label for="spam_challenge_site_key"><?php esc_html_e( 'Site Key', 'ajforms' ); ?></label>
													<input type="text" id="spam_challenge_site_key" value="">
												</div>
												<div class="ajforms-settings-field">
													<label for="spam_challenge_secret_key"><?php esc_html_e( 'Secret Key', 'ajforms' ); ?></label>
													<input type="text" id="spam_challenge_secret_key" value="">
												</div>
											</div>
											<div class="ajforms-settings-help ajforms-settings-help-links" id="wpf-spam-provider-links" style="display:none;"></div>
										</div>
									</div>
									<input type="hidden" name="recaptcha_site_key" id="recaptcha_site_key" value="<?php echo esc_attr( $settings['recaptcha_site_key'] ); ?>">
									<input type="hidden" name="recaptcha_secret_key" id="recaptcha_secret_key" value="<?php echo esc_attr( $settings['recaptcha_secret_key'] ); ?>">
									<input type="hidden" name="hcaptcha_site_key" id="hcaptcha_site_key" value="<?php echo esc_attr( $settings['hcaptcha_site_key'] ); ?>">
									<input type="hidden" name="hcaptcha_secret_key" id="hcaptcha_secret_key" value="<?php echo esc_attr( $settings['hcaptcha_secret_key'] ); ?>">
									<input type="hidden" name="turnstile_site_key" id="turnstile_site_key" value="<?php echo esc_attr( $settings['turnstile_site_key'] ); ?>">
									<input type="hidden" name="turnstile_secret_key" id="turnstile_secret_key" value="<?php echo esc_attr( $settings['turnstile_secret_key'] ); ?>">
									<script>
									(function() {
										const providerSelect = document.getElementById('spam_challenge_provider');
										const siteKeyInput = document.getElementById('spam_challenge_site_key');
										const secretKeyInput = document.getElementById('spam_challenge_secret_key');
										const linksWrap = document.getElementById('wpf-spam-provider-links');
										const providerMap = {
											recaptcha: {
												site: document.getElementById('recaptcha_site_key'),
												secret: document.getElementById('recaptcha_secret_key'),
												links: [
													{ label: '<?php echo esc_js( __( 'Get keys', 'ajforms' ) ); ?>', href: 'https://www.google.com/recaptcha/admin/site' },
													{ label: '<?php echo esc_js( __( 'Setup guide', 'ajforms' ) ); ?>', href: 'https://cloud.google.com/recaptcha/docs/create-key-website' }
												]
											},
											hcaptcha: {
												site: document.getElementById('hcaptcha_site_key'),
												secret: document.getElementById('hcaptcha_secret_key'),
												links: [
													{ label: '<?php echo esc_js( __( 'Get keys', 'ajforms' ) ); ?>', href: 'https://dashboard.hcaptcha.com/' },
													{ label: '<?php echo esc_js( __( 'Setup guide', 'ajforms' ) ); ?>', href: 'https://docs.hcaptcha.com/switch' }
												]
											},
											turnstile: {
												site: document.getElementById('turnstile_site_key'),
												secret: document.getElementById('turnstile_secret_key'),
												links: [
													{ label: '<?php echo esc_js( __( 'Get keys', 'ajforms' ) ); ?>', href: 'https://dash.cloudflare.com/?to=/:account/turnstile' },
													{ label: '<?php echo esc_js( __( 'Setup guide', 'ajforms' ) ); ?>', href: 'https://developers.cloudflare.com/turnstile/get-started/widget-management/dashboard/' }
												]
											}
										};

										if (!providerSelect || !siteKeyInput || !secretKeyInput || !linksWrap) {
											return;
										}

										function syncVisibleToHidden(provider) {
											if (!provider || !providerMap[provider]) {
												return;
											}

											providerMap[provider].site.value = siteKeyInput.value;
											providerMap[provider].secret.value = secretKeyInput.value;
										}

										function renderProvider(provider) {
											if (!provider || !providerMap[provider]) {
												siteKeyInput.value = '';
												secretKeyInput.value = '';
												linksWrap.style.display = 'none';
												linksWrap.innerHTML = '';
												return;
											}

											siteKeyInput.value = providerMap[provider].site.value || '';
											secretKeyInput.value = providerMap[provider].secret.value || '';
											linksWrap.innerHTML = providerMap[provider].links.map((link) => '<a href="' + link.href + '" target="_blank" rel="noopener noreferrer">' + link.label + '</a>').join('');
											linksWrap.style.display = 'flex';
										}

										siteKeyInput.addEventListener('input', function() {
											syncVisibleToHidden(providerSelect.value);
										});

										secretKeyInput.addEventListener('input', function() {
											syncVisibleToHidden(providerSelect.value);
										});

										providerSelect.addEventListener('change', function() {
											renderProvider(this.value);
										});

										if (!providerSelect.value) {
											const firstConfigured = ['recaptcha', 'hcaptcha', 'turnstile'].find(function(provider) {
												return providerMap[provider].site.value || providerMap[provider].secret.value;
											});

											if (firstConfigured) {
												providerSelect.value = firstConfigured;
											}
										}

										renderProvider(providerSelect.value);
									})();
									</script>
								</div>
							<?php elseif ( 'integrations' === $section ) : ?>
								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Integrations', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Asana', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'Connect Asana once here, then enable task creation on any form from the builder.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-checkbox" style="margin-bottom:22px;">
										<input name="asana_enabled" id="asana_enabled" type="checkbox" value="1" <?php checked( '1' === (string) $settings['asana_enabled'] ); ?>>
										<div>
											<strong><?php esc_html_e( 'Enable Asana integration', 'ajforms' ); ?></strong>
											<span><?php esc_html_e( 'When enabled, forms can create Asana tasks after successful submissions.', 'ajforms' ); ?></span>
										</div>
									</div>
									<div class="ajforms-settings-grid">
										<div class="ajforms-settings-field">
											<label for="asana_personal_access_token"><?php esc_html_e( 'Personal Access Token', 'ajforms' ); ?></label>
											<input name="asana_personal_access_token" id="asana_personal_access_token" type="text" value="<?php echo esc_attr( $settings['asana_personal_access_token'] ); ?>">
											<div class="ajforms-settings-help"><?php esc_html_e( 'Create this in Asana and keep it private. AJ Core uses it to create tasks through the Asana API.', 'ajforms' ); ?></div>
											<div class="ajforms-settings-inline-actions">
												<button type="button" class="button" id="wpf-refresh-asana-data"><?php esc_html_e( 'Refresh from Asana', 'ajforms' ); ?></button>
												<span id="wpf-asana-sync-status" class="ajforms-settings-help">
													<?php
													if ( ! empty( $asana_cache['updated_at'] ) ) {
														echo esc_html(
															sprintf(
																/* translators: %s: date/time */
																__( 'Last synced: %s', 'ajforms' ),
																wp_date(
																	get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
																	strtotime( $asana_cache['updated_at'] )
																)
															)
														);
													} else {
														esc_html_e( 'Not synced yet.', 'ajforms' );
													}
													?>
												</span>
											</div>
										</div>
										<div class="ajforms-settings-field">
											<label for="asana_workspace_gid"><?php esc_html_e( 'Workspace', 'ajforms' ); ?></label>
											<select name="asana_workspace_gid" id="asana_workspace_gid">
												<option value=""><?php esc_html_e( 'Select a workspace', 'ajforms' ); ?></option>
												<?php foreach ( $asana_cache['workspaces'] as $workspace ) : ?>
													<option value="<?php echo esc_attr( $workspace['gid'] ); ?>" <?php selected( $settings['asana_workspace_gid'], $workspace['gid'] ); ?>><?php echo esc_html( $workspace['name'] . ' (' . $workspace['gid'] . ')' ); ?></option>
												<?php endforeach; ?>
											</select>
											<div class="ajforms-settings-help"><?php esc_html_e( 'Loaded from Asana using the current personal access token.', 'ajforms' ); ?></div>
										</div>
										<div class="ajforms-settings-field">
											<label for="asana_project_gid"><?php esc_html_e( 'Default Project', 'ajforms' ); ?></label>
											<select name="asana_project_gid" id="asana_project_gid">
												<option value=""><?php esc_html_e( 'No default project', 'ajforms' ); ?></option>
												<?php foreach ( $asana_cache['projects'] as $project ) : ?>
													<option value="<?php echo esc_attr( $project['gid'] ); ?>" <?php selected( $settings['asana_project_gid'], $project['gid'] ); ?>><?php echo esc_html( $project['name'] . ' (' . $project['gid'] . ')' ); ?></option>
												<?php endforeach; ?>
											</select>
											<div class="ajforms-settings-help"><?php esc_html_e( 'Optional. Projects refresh based on the selected workspace.', 'ajforms' ); ?></div>
										</div>
									</div>
									<script>
									(function() {
										const tokenInput = document.getElementById('asana_personal_access_token');
										const workspaceSelect = document.getElementById('asana_workspace_gid');
										const projectSelect = document.getElementById('asana_project_gid');
										const refreshButton = document.getElementById('wpf-refresh-asana-data');
										const statusNode = document.getElementById('wpf-asana-sync-status');
										const syncNonce = '<?php echo esc_js( wp_create_nonce( 'ajf_sync_asana_reference_data' ) ); ?>';

										if (!tokenInput || !workspaceSelect || !projectSelect || !refreshButton) {
											return;
										}

										function setStatus(message, isError) {
											if (!statusNode) {
												return;
											}

											statusNode.textContent = message;
											statusNode.style.color = isError ? '#b32d2e' : '';
										}

										function replaceOptions(select, options, placeholder, selectedValue) {
											select.innerHTML = '';
											const baseOption = document.createElement('option');
											baseOption.value = '';
											baseOption.textContent = placeholder;
											select.appendChild(baseOption);

											options.forEach(function(option) {
												const el = document.createElement('option');
												el.value = option.gid;
												el.textContent = option.name + ' (' + option.gid + ')';
												if (selectedValue && selectedValue === option.gid) {
													el.selected = true;
												}
												select.appendChild(el);
											});
										}

										function syncAsanaData(workspaceOverride) {
											const token = tokenInput.value.trim();
											if (!token) {
												setStatus('<?php echo esc_js( __( 'Add a personal access token first.', 'ajforms' ) ); ?>', true);
												return;
											}

											refreshButton.disabled = true;
											setStatus('<?php echo esc_js( __( 'Refreshing Asana data...', 'ajforms' ) ); ?>', false);

											const formData = new FormData();
											formData.append('action', 'ajf_sync_asana_reference_data');
											formData.append('nonce', syncNonce);
											formData.append('token', token);
											formData.append('workspace_gid', typeof workspaceOverride === 'string' ? workspaceOverride : workspaceSelect.value);

											fetch(ajaxurl, {
												method: 'POST',
												body: formData
											})
												.then((response) => response.json())
												.then((response) => {
													if (!response.success) {
														setStatus(response.data || '<?php echo esc_js( __( 'Unable to refresh Asana data.', 'ajforms' ) ); ?>', true);
														return;
													}

													const data = response.data || {};
													replaceOptions(workspaceSelect, data.workspaces || [], '<?php echo esc_js( __( 'Select a workspace', 'ajforms' ) ); ?>', data.workspace_gid || '');
													replaceOptions(projectSelect, data.projects || [], '<?php echo esc_js( __( 'No default project', 'ajforms' ) ); ?>', projectSelect.value);
													if (data.updated_at) {
														setStatus('<?php echo esc_js( __( 'Asana data refreshed.', 'ajforms' ) ); ?>', false);
													}
												})
												.catch(() => {
													setStatus('<?php echo esc_js( __( 'Unable to refresh Asana data.', 'ajforms' ) ); ?>', true);
												})
												.finally(() => {
													refreshButton.disabled = false;
												});
										}

										refreshButton.addEventListener('click', function() {
											syncAsanaData();
										});

										workspaceSelect.addEventListener('change', function() {
											if (tokenInput.value.trim()) {
												syncAsanaData(this.value);
											}
										});
									})();
									</script>
								</div>

								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Integrations', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Outbound hooks', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'If you want a lightweight second integration, start with a webhook so submissions can be mirrored elsewhere.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-field">
										<label for="webhook_url"><?php esc_html_e( 'Default Webhook URL', 'ajforms' ); ?></label>
										<input name="webhook_url" id="webhook_url" type="url" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>">
										<div class="ajforms-settings-help"><?php esc_html_e( 'Used by conditional confirmation webhook actions when a rule supplies this URL.', 'ajforms' ); ?></div>
									</div>
								</div>
							<?php elseif ( 'payments' === $section ) : ?>
								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Stripe Payments', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Stripe connection', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'These keys connect AJ Core to Stripe site-wide. Products sync from Stripe Prices and can be used in forms or on pages.', 'ajforms' ); ?></p>
<?php $stripe_mode_data = $this->get_stripe_mode_badge_data(); ?>
									<div class="notice <?php echo ! empty( $stripe_mode_data['has_issues'] ) ? 'notice-error' : ( ! empty( $stripe_mode_data['is_live'] ) ? 'notice-warning' : 'notice-info' ); ?> inline" style="margin:12px 0 18px;">
										<p><strong><?php echo esc_html( sprintf( __( 'Stripe %s Mode', 'ajforms' ), $stripe_mode_data['label'] ) ); ?></strong>
										<?php if ( ! empty( $stripe_mode_data['is_live'] ) ) : ?>
											<?php esc_html_e( 'Use only live Stripe keys here. Payments are real.', 'ajforms' ); ?>
										<?php else : ?>
											<?php esc_html_e( 'Use Stripe sandbox/test keys here. Use Stripe test cards, not random card numbers.', 'ajforms' ); ?>
										<?php endif; ?></p>
										<?php if ( ! empty( $stripe_mode_data['issues'] ) ) : ?>
											<ul style="list-style:disc;padding-left:20px;">
												<?php foreach ( $stripe_mode_data['issues'] as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</div>
									<div class="ajforms-settings-field" style="max-width:280px;margin-bottom:22px;">
										<label for="stripe_mode"><?php esc_html_e( 'Stripe Mode', 'ajforms' ); ?></label>
										<select name="stripe_mode" id="stripe_mode">
											<option value="test" <?php selected( $settings['stripe_mode'], 'test' ); ?>><?php esc_html_e( 'Sandbox', 'ajforms' ); ?></option>
											<option value="live" <?php selected( $settings['stripe_mode'], 'live' ); ?>><?php esc_html_e( 'Live', 'ajforms' ); ?></option>
										</select>
									</div>
									<div class="ajforms-settings-grid">
										<div class="ajforms-settings-field">
											<label for="stripe_sandbox_publishable_key"><?php esc_html_e( 'Sandbox Publishable Key', 'ajforms' ); ?></label>
											<input name="stripe_sandbox_publishable_key" id="stripe_sandbox_publishable_key" type="text" value="<?php echo esc_attr( isset( $settings['stripe_sandbox_publishable_key'] ) ? $settings['stripe_sandbox_publishable_key'] : '' ); ?>" placeholder="pk_test_...">
										</div>
										<div class="ajforms-settings-field">
											<label for="stripe_sandbox_secret_key"><?php esc_html_e( 'Sandbox Secret Key', 'ajforms' ); ?></label>
											<input name="stripe_sandbox_secret_key" id="stripe_sandbox_secret_key" type="text" value="<?php echo esc_attr( isset( $settings['stripe_sandbox_secret_key'] ) ? $settings['stripe_sandbox_secret_key'] : '' ); ?>" placeholder="sk_test_...">
										</div>
										<div class="ajforms-settings-field">
											<label for="stripe_live_publishable_key"><?php esc_html_e( 'Live Publishable Key', 'ajforms' ); ?></label>
											<input name="stripe_live_publishable_key" id="stripe_live_publishable_key" type="text" value="<?php echo esc_attr( isset( $settings['stripe_live_publishable_key'] ) ? $settings['stripe_live_publishable_key'] : '' ); ?>" placeholder="pk_live_...">
										</div>
										<div class="ajforms-settings-field">
											<label for="stripe_live_secret_key"><?php esc_html_e( 'Live Secret Key', 'ajforms' ); ?></label>
											<input name="stripe_live_secret_key" id="stripe_live_secret_key" type="text" value="<?php echo esc_attr( isset( $settings['stripe_live_secret_key'] ) ? $settings['stripe_live_secret_key'] : '' ); ?>" placeholder="sk_live_...">
										</div>
									</div>
									<p class="ajforms-settings-help" style="margin-top:10px;"><?php esc_html_e( 'AJ Core stores Sandbox and Live keys separately. The Stripe Mode dropdown chooses which key pair is active, so switching modes does not overwrite the other environment.', 'ajforms' ); ?></p>
									<div class="ajforms-settings-actions" style="margin-top:18px;">
										<button type="submit" class="button" name="ajf_sync_stripe_products" value="1"><?php esc_html_e( 'Save and Sync Products', 'ajforms' ); ?></button>
										<span class="ajforms-settings-help">
											<?php
											if ( ! empty( $stripe_cache['updated_at'] ) ) {
												printf(
													esc_html__( 'Last synced: %s.', 'ajforms' ),
													esc_html( $stripe_cache['updated_at'] )
												);
											} else {
												esc_html_e( 'Products have not been synced yet.', 'ajforms' );
											}
											?>
										</span>
									</div>
								</div>

								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Products', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Product availability', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'Choose which synced Stripe prices can appear in forms and product shortcodes.', 'ajforms' ); ?></p>

									<?php if ( isset( $_GET['stripe-sync-error'] ) ) : ?>
										<div class="notice notice-error inline"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['stripe-sync-error'] ) ) ); ?></p></div>
									<?php elseif ( isset( $_GET['stripe-synced'] ) ) : ?>
										<div class="notice notice-success inline"><p><?php echo esc_html( sprintf( __( 'Synced %d Stripe prices.', 'ajforms' ), absint( wp_unslash( $_GET['stripe-synced'] ) ) ) ); ?></p></div>
									<?php endif; ?>

									<div class="ajforms-settings-field" style="max-width:360px;margin-bottom:18px;">
										<label for="stripe_products_mode"><?php esc_html_e( 'Products to show', 'ajforms' ); ?></label>
										<select name="stripe_products_mode" id="stripe_products_mode">
											<option value="all" <?php selected( isset( $settings['stripe_products_mode'] ) ? $settings['stripe_products_mode'] : 'all', 'all' ); ?>><?php esc_html_e( 'All synced products', 'ajforms' ); ?></option>
											<option value="selected" <?php selected( isset( $settings['stripe_products_mode'] ) ? $settings['stripe_products_mode'] : 'all', 'selected' ); ?>><?php esc_html_e( 'Only selected products', 'ajforms' ); ?></option>
										</select>
									</div>

									<?php if ( empty( $stripe_prices ) ) : ?>
										<div class="ajforms-settings-note"><?php esc_html_e( 'No synced products yet. Save your Stripe keys, then click Save and Sync Products.', 'ajforms' ); ?></div>
									<?php else : ?>
										<div style="display:grid;gap:10px;">
											<?php foreach ( $stripe_prices as $price ) : ?>
												<label class="ajforms-settings-checkbox">
													<input type="checkbox" name="stripe_selected_prices[]" value="<?php echo esc_attr( $price['id'] ); ?>" <?php checked( in_array( $price['id'], $selected_stripe_prices, true ) ); ?>>
													<span>
														<strong><?php echo esc_html( $this->format_stripe_price_label( $price ) ); ?></strong>
														<span><?php echo esc_html( $price['id'] ); ?></span>
													</span>
												</label>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							<?php endif; ?>

							<div class="ajforms-settings-actions">
								<?php submit_button( __( 'Save Settings', 'ajforms' ), 'primary', 'submit', false ); ?>
								<span style="color:#6b7280;"><?php esc_html_e( 'Changes are stored site-wide for AJ Core.', 'ajforms' ); ?></span>
							</div>
						</form>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function display_about_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$developer_updates_enabled = $this->developer_updates_enabled();
		$developer_toggle_url      = wp_nonce_url(
			add_query_arg(
				array(
					'page'            => 'ajforms-about',
					'ajf_dev_updates' => $developer_updates_enabled ? '0' : '1',
				),
				admin_url( 'admin.php' )
			),
			'ajf_toggle_developer_updates'
		);
		$update_status             = current_user_can( 'update_plugins' ) ? $this->get_update_status() : null;

		?>
		<div class="wrap">
			<style>
				.ajforms-about-shell{max-width:760px;margin-top:20px;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;box-shadow:0 12px 30px rgba(15,23,42,.06)}
				.ajforms-about-shell h1{margin:0 0 8px;font-size:28px;line-height:1.2;color:#111827}
				.ajforms-about-shell p{font-size:14px;line-height:1.6;color:#4b5563;margin:0}
				.ajforms-about-status{margin:22px 0 0;padding:18px;border:1px solid #dbeafe;border-radius:12px;background:#eff6ff;color:#1e3a8a}
				.ajforms-about-status.is-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
				.ajforms-about-status.is-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}
				.ajforms-about-status strong{display:block;margin-bottom:6px;color:inherit;font-size:15px}
				.ajforms-about-actions{margin-top:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
				.ajforms-about-toggle{margin-top:22px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb}
				.ajforms-about-toggle label{display:flex;align-items:flex-start;gap:10px;font-weight:700;color:#111827}
				.ajforms-about-toggle span{display:block;margin-top:3px;color:#6b7280;font-weight:400;font-size:13px;line-height:1.4}
				.ajforms-about-meta{margin-top:22px;width:100%;border-collapse:collapse}
				.ajforms-about-meta th,.ajforms-about-meta td{padding:11px 0;border-bottom:1px solid #f3f4f6;text-align:left}
				.ajforms-about-meta th{width:160px;color:#6b7280;font-weight:600}
			</style>

			<div class="ajforms-about-shell">
				<h1><?php esc_html_e( 'Update AJ Core', 'ajforms' ); ?></h1>
				<p><?php esc_html_e( 'Install the latest AJ Core release when one is available.', 'ajforms' ); ?></p>

				<table class="ajforms-about-meta">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'ajforms' ); ?></th>
							<td><?php echo esc_html( 'AJ Core ' . AJFORMS_VERSION ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Developer', 'ajforms' ); ?></th>
							<td><a href="https://itspector.com/" target="_blank" rel="noopener noreferrer">IT Spector LLC</a></td>
						</tr>
					</tbody>
				</table>

				<?php if ( current_user_can( 'update_plugins' ) ) : ?>
					<div class="ajforms-about-toggle">
						<label>
							<input type="checkbox" <?php checked( $developer_updates_enabled ); ?> onchange="window.location.href='<?php echo esc_url( $developer_toggle_url ); ?>';">
							<span>
								<?php esc_html_e( 'Enable developer updates', 'ajforms' ); ?>
								<span><?php esc_html_e( 'Developer updates include prerelease builds from non-main branches. Leave this off for normal main-branch updates.', 'ajforms' ); ?></span>
							</span>
						</label>
					</div>

					<?php if ( isset( $_GET['update-error'] ) ) : ?>
						<div class="ajforms-about-status is-error">
							<strong><?php esc_html_e( 'Update failed.', 'ajforms' ); ?></strong>
							<?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['update-error'] ) ) ); ?>
						</div>
					<?php elseif ( isset( $_GET['update-success'] ) ) : ?>
						<div class="ajforms-about-status is-ok">
							<strong><?php esc_html_e( 'AJ Core was updated.', 'ajforms' ); ?></strong>
							<?php esc_html_e( 'The plugin update completed successfully.', 'ajforms' ); ?>
						</div>
					<?php elseif ( is_wp_error( $update_status ) ) : ?>
						<div class="ajforms-about-status is-error">
							<strong><?php esc_html_e( 'Unable to check updates.', 'ajforms' ); ?></strong>
							<?php echo esc_html( $update_status->get_error_message() ); ?>
						</div>
					<?php elseif ( is_array( $update_status ) && ! empty( $update_status['has_update'] ) ) : ?>
						<div class="ajforms-about-status">
							<strong><?php echo ! empty( $update_status['developer'] ) ? esc_html__( 'An AJ Core developer update is available.', 'ajforms' ) : esc_html__( 'An AJ Core update is available.', 'ajforms' ); ?></strong>
							<?php
							printf(
								esc_html__( 'Installed: %1$s. Latest: %2$s.', 'ajforms' ),
								esc_html( $update_status['current_version'] ),
								esc_html( $update_status['latest_version'] )
							);
							?>
							<div class="ajforms-about-actions">
								<a class="button button-primary" href="<?php echo esc_url( $this->get_about_update_url( 'update' ) ); ?>"><?php esc_html_e( 'Update AJ Core', 'ajforms' ); ?></a>
							</div>
						</div>
					<?php elseif ( is_array( $update_status ) ) : ?>
						<div class="ajforms-about-status is-ok">
							<strong><?php esc_html_e( 'AJ Core is up to date.', 'ajforms' ); ?></strong>
							<?php
							printf(
								esc_html__( 'Installed version: %s.', 'ajforms' ),
								esc_html( $update_status['current_version'] )
							);
							?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function ajax_save_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ajforms' ), 403 );
		}

		check_ajax_referer( 'ajf_save_form', 'nonce' );

		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$form_id     = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$status      = isset( $_POST['status'] ) && 'draft' === sanitize_text_field( wp_unslash( $_POST['status'] ) ) ? 'draft' : 'published';
		$schema_json = isset( $_POST['schema'] ) ? wp_unslash( $_POST['schema'] ) : '';

		if ( '' === $title ) {
			wp_send_json_error( __( 'Please enter a form title.', 'ajforms' ), 400 );
		}

		if ( 'Untitled Form' === $title ) {
			wp_send_json_error( __( 'Change the form name before saving. The default title cannot be used.', 'ajforms' ), 400 );
		}

		$schema = json_decode( $schema_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $schema ) ) {
			wp_send_json_error( __( 'Invalid form schema.', 'ajforms' ), 400 );
		}

		$sanitized_schema = $this->sanitize_schema_for_storage( $schema );

		global $wpdb;
		$table = $this->get_forms_table();
		$existing_form_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE title = %s AND id != %d LIMIT 1",
				$title,
				$form_id
			)
		);

		if ( $existing_form_id > 0 ) {
			wp_send_json_error( __( 'This form name already exists. Change the name and save again.', 'ajforms' ), 400 );
		}

		$data  = array(
			'title'       => $title,
			'form_schema' => wp_json_encode( $sanitized_schema ),
			'status'      => $status,
			'updated_at'  => current_time( 'mysql' ),
		);

		if ( $form_id > 0 ) {
			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $form_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( __( 'Unable to save form.', 'ajforms' ), 500 );
			}
		} else {
			$data['created_at'] = current_time( 'mysql' );

			$inserted = $wpdb->insert(
				$table,
				$data,
				array( '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				wp_send_json_error( __( 'Unable to save form.', 'ajforms' ), 500 );
			}

			$form_id = (int) $wpdb->insert_id;
		}

		wp_send_json_success(
			array(
				'form_id'  => $form_id,
				'edit_url' => add_query_arg(
					array(
						'page'    => 'ajforms',
						'action'  => 'edit',
						'form_id' => $form_id,
					),
					admin_url( 'admin.php' )
				),
				'preview_url' => $this->get_form_preview_url( $form_id ),
				'forms_url'   => admin_url( 'admin.php?page=ajforms' ),
			)
		);
	}

	public function ajax_import_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ajforms' ), 403 );
		}

		check_ajax_referer( 'ajf_import_form', 'nonce' );

		$raw_data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$decoded  = json_decode( $raw_data, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			wp_send_json_error( __( 'Invalid import payload.', 'ajforms' ), 400 );
		}

		$title = '';
		if ( isset( $decoded['title'] ) && '' !== trim( (string) $decoded['title'] ) ) {
			$title = sanitize_text_field( $decoded['title'] );
		}

		if ( '' === $title && isset( $decoded['name'] ) && '' !== trim( (string) $decoded['name'] ) ) {
			$title = sanitize_text_field( $decoded['name'] );
		}

		if ( '' === $title ) {
			$title = __( 'Imported Form', 'ajforms' );
		}

		$schema            = isset( $decoded['schema'] ) && is_array( $decoded['schema'] ) ? $decoded['schema'] : $decoded;
		$sanitized_schema  = $this->sanitize_schema_for_storage( $schema );

		global $wpdb;
		$table    = $this->get_forms_table();
		$inserted = $wpdb->insert(
			$table,
			array(
				'title'       => $title,
				'form_schema' => wp_json_encode( $sanitized_schema ),
				'status'      => 'draft',
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( __( 'Unable to import form.', 'ajforms' ), 500 );
		}

		$form_id = (int) $wpdb->insert_id;

		wp_send_json_success(
			array(
				'form_id'  => $form_id,
				'edit_url' => add_query_arg(
					array(
						'page'    => 'ajforms',
						'action'  => 'edit',
						'form_id' => $form_id,
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	public function bulk_delete_forms( $form_ids ) {
		$this->delete_forms_and_related_data( $form_ids );
	}
}
