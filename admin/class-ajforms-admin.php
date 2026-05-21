<?php

class AJForms_Admin {

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

	private function stripe_api_get( $path, $secret_key, $query_args = array() ) {
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

	private function stripe_timestamp_to_mysql( $timestamp ) {
		$timestamp = absint( $timestamp );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	private function get_stripe_secret_key_for_portal() {
		$settings = $this->get_plugin_settings();

		return ! empty( $settings['stripe_secret_key'] ) ? sanitize_text_field( $settings['stripe_secret_key'] ) : '';
	}

	private function upsert_portal_record( $table, $data, $formats, $unique_key ) {
		global $wpdb;

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE {$unique_key} = %s LIMIT 1",
				$data[ $unique_key ]
			)
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => absint( $existing_id ) ), $formats, array( '%d' ) );
			return absint( $existing_id );
		}

		$wpdb->insert( $table, $data, $formats );
		return (int) $wpdb->insert_id;
	}

	private function get_guest_portal_customer_id( $email ) {
		$email = strtolower( sanitize_email( (string) $email ) );

		return is_email( $email ) ? 'guest_' . md5( $email ) : '';
	}

	private function get_payment_customer_id( $payment ) {
		if ( ! empty( $payment['customer'] ) && is_string( $payment['customer'] ) ) {
			return sanitize_text_field( (string) $payment['customer'] );
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

	private function upsert_guest_portal_customer_from_payment( $payment, $source ) {
		$customer_id = $this->get_payment_customer_id( $payment );
		if ( '' === $customer_id || 0 === strpos( $customer_id, 'cus_' ) ) {
			return 0;
		}

		$details = array();
		if ( ! empty( $payment['customer_details'] ) && is_array( $payment['customer_details'] ) ) {
			$details = $payment['customer_details'];
		} elseif ( ! empty( $payment['billing_details'] ) && is_array( $payment['billing_details'] ) ) {
			$details = $payment['billing_details'];
		}

		$email = ! empty( $details['email'] ) ? sanitize_email( (string) $details['email'] ) : '';
		$name  = ! empty( $details['name'] ) ? sanitize_text_field( (string) $details['name'] ) : '';
		$phone = ! empty( $details['phone'] ) ? sanitize_text_field( (string) $details['phone'] ) : '';

		if ( '' === $email ) {
			return 0;
		}

		return $this->upsert_portal_record(
			$this->get_portal_stripe_customers_table(),
			array(
				'stripe_customer_id' => $customer_id,
				'email'              => $email,
				'name'               => $name,
				'phone'              => $phone,
				'address'            => ! empty( $details['address'] ) ? wp_json_encode( $details['address'] ) : '',
				'metadata'           => wp_json_encode( array( 'source' => sanitize_key( $source ), 'guest' => true ) ),
				'raw_data'           => wp_json_encode( $payment ),
				'livemode'           => ! empty( $payment['livemode'] ) ? 1 : 0,
				'created_at'         => ! empty( $payment['created'] ) ? $this->stripe_timestamp_to_mysql( $payment['created'] ) : null,
				'synced_at'          => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			'stripe_customer_id'
		);
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

			$this->upsert_portal_record(
				$this->get_portal_stripe_products_table(),
				array(
					'stripe_product_id'    => ! empty( $product['id'] ) ? sanitize_text_field( (string) $product['id'] ) : sanitize_text_field( (string) $price['product'] ),
					'stripe_price_id'      => sanitize_text_field( (string) $price['id'] ),
					'name'                 => ! empty( $product['name'] ) ? sanitize_text_field( (string) $product['name'] ) : __( 'Stripe product', 'ajforms' ),
					'description'          => ! empty( $product['description'] ) ? sanitize_textarea_field( (string) $product['description'] ) : '',
					'price_amount'         => $this->stripe_amount_to_decimal( isset( $price['unit_amount'] ) ? $price['unit_amount'] : 0, $currency ),
					'currency'             => $currency,
					'recurring_interval'   => ! empty( $recurring['interval'] ) ? sanitize_key( (string) $recurring['interval'] ) : '',
					'active'               => ( ! isset( $price['active'] ) || ! empty( $price['active'] ) ) && ( empty( $product ) || ! isset( $product['active'] ) || ! empty( $product['active'] ) ) ? 1 : 0,
					'metadata'             => ! empty( $product['metadata'] ) ? wp_json_encode( $product['metadata'] ) : '',
					'raw_data'             => wp_json_encode( $price ),
					'synced_at'            => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s', '%s' ),
				'stripe_price_id'
			);
			$count++;
		}

		$product_cache = $this->fetch_stripe_product_prices( $secret_key );
		if ( is_wp_error( $product_cache ) ) {
			// The portal product cache above supports recurring prices; keep going if the older public-product cache rejects some data.
		}

		return $count;
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

			$this->upsert_portal_record(
				$this->get_portal_stripe_customers_table(),
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
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
				'stripe_customer_id'
			);
			$count++;
		}

		$sessions = $this->stripe_api_get_all( 'checkout/sessions', $secret_key );
		if ( is_wp_error( $sessions ) ) {
			return $sessions;
		}

		foreach ( $sessions as $session ) {
			if ( empty( $session['id'] ) ) {
				continue;
			}

			$inserted = $this->upsert_guest_portal_customer_from_payment( $session, 'checkout_session' );
			if ( $inserted ) {
				$count++;
			}
		}

		$charges = $this->stripe_api_get_all( 'charges', $secret_key );
		if ( is_wp_error( $charges ) ) {
			return $charges;
		}

		foreach ( $charges as $charge ) {
			if ( empty( $charge['id'] ) ) {
				continue;
			}

			$inserted = $this->upsert_guest_portal_customer_from_payment( $charge, 'charge' );
			if ( $inserted ) {
				$count++;
			}
		}

		return $count;
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

			$this->upsert_portal_record(
				$this->get_portal_stripe_subscriptions_table(),
				array(
					'stripe_subscription_id' => sanitize_text_field( (string) $subscription['id'] ),
					'stripe_customer_id'     => sanitize_text_field( (string) $subscription['customer'] ),
					'status'                 => ! empty( $subscription['status'] ) ? sanitize_key( (string) $subscription['status'] ) : '',
					'current_period_end'     => ! empty( $subscription['current_period_end'] ) ? $this->stripe_timestamp_to_mysql( $subscription['current_period_end'] ) : null,
					'cancel_at_period_end'   => ! empty( $subscription['cancel_at_period_end'] ) ? 1 : 0,
					'items'                  => ! empty( $subscription['items']['data'] ) ? wp_json_encode( $subscription['items']['data'] ) : '',
					'raw_data'               => wp_json_encode( $subscription ),
					'synced_at'              => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
				'stripe_subscription_id'
			);
			$count++;
		}

		return $count;
	}

	private function sync_portal_stripe_transactions( $secret_key, $stripe_customer_id = '' ) {
		global $wpdb;

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
			$data = array(
				'stripe_object_id'   => sanitize_text_field( (string) $invoice['id'] ),
				'object_type'        => 'invoice',
				'stripe_customer_id' => sanitize_text_field( (string) $invoice['customer'] ),
				'invoice_id'         => sanitize_text_field( (string) $invoice['id'] ),
				'payment_intent_id'  => ! empty( $invoice['payment_intent'] ) ? sanitize_text_field( (string) $invoice['payment_intent'] ) : '',
				'charge_id'          => ! empty( $invoice['charge'] ) ? sanitize_text_field( (string) $invoice['charge'] ) : '',
				'description'        => ! empty( $invoice['description'] ) ? sanitize_text_field( (string) $invoice['description'] ) : sprintf( __( 'Invoice %s', 'ajforms' ), $invoice['id'] ),
				'amount'             => $this->stripe_amount_to_decimal( isset( $invoice['amount_due'] ) ? $invoice['amount_due'] : 0, $currency ),
				'currency'           => $currency,
				'status'             => ! empty( $invoice['status'] ) ? sanitize_key( (string) $invoice['status'] ) : '',
				'transaction_date'   => ! empty( $invoice['created'] ) ? $this->stripe_timestamp_to_mysql( $invoice['created'] ) : null,
				'due_date'           => ! empty( $invoice['due_date'] ) ? $this->stripe_timestamp_to_mysql( $invoice['due_date'] ) : null,
				'raw_data'           => wp_json_encode( $invoice ),
				'synced_at'          => current_time( 'mysql' ),
			);

			$this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ), 'stripe_object_id' );
			$this->upsert_portal_record(
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
					'metadata'           => '',
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
			$count++;
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
			if ( '' === $customer_id ) {
				continue;
			}

			$this->upsert_guest_portal_customer_from_payment( $charge, 'charge' );

			$currency = isset( $charge['currency'] ) ? strtolower( sanitize_key( $charge['currency'] ) ) : 'usd';
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
				'status'             => ! empty( $charge['status'] ) ? sanitize_key( (string) $charge['status'] ) : '',
				'transaction_date'   => ! empty( $charge['created'] ) ? $this->stripe_timestamp_to_mysql( $charge['created'] ) : null,
				'due_date'           => null,
				'raw_data'           => wp_json_encode( $charge ),
				'synced_at'          => current_time( 'mysql' ),
			);

			$this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ), 'stripe_object_id' );
			$this->upsert_portal_record(
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
					'metadata'           => '',
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
			$count++;
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
			if ( '' === $customer_id ) {
				continue;
			}

			$this->upsert_guest_portal_customer_from_payment( $session, 'checkout_session' );

			$payment_intent_id = ! empty( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : '';
			if ( '' !== $payment_intent_id ) {
				$existing_payment = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$this->get_portal_stripe_transactions_table()} WHERE payment_intent_id = %s LIMIT 1",
						$payment_intent_id
					)
				);
				if ( $existing_payment ) {
					continue;
				}
			}

			$currency = isset( $session['currency'] ) ? strtolower( sanitize_key( $session['currency'] ) ) : 'usd';
			$status   = ! empty( $session['payment_status'] ) ? sanitize_key( (string) $session['payment_status'] ) : '';
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
				'synced_at'          => current_time( 'mysql' ),
			);

			$this->upsert_portal_record( $this->get_portal_stripe_transactions_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ), 'stripe_object_id' );
			$this->upsert_portal_record(
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
					'metadata'           => '',
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				'source_object_id'
			);
			$count++;
		}

		return $count;
	}

	private function sync_single_portal_stripe_customer( $secret_key, $stripe_customer_id ) {
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		if ( '' === $stripe_customer_id ) {
			return new WP_Error( 'missing_customer', __( 'Stripe customer ID is required.', 'ajforms' ) );
		}

		if ( 0 === strpos( $stripe_customer_id, 'guest_' ) ) {
			$customer_count    = $this->sync_portal_stripe_customers( $secret_key );
			$transaction_count = $this->sync_portal_stripe_transactions( $secret_key );

			if ( is_wp_error( $customer_count ) ) {
				return $customer_count;
			}

			if ( is_wp_error( $transaction_count ) ) {
				return $transaction_count;
			}

			return absint( $customer_count ) + absint( $transaction_count );
		}

		$customer = $this->stripe_api_get( 'customers/' . rawurlencode( $stripe_customer_id ), $secret_key );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		if ( empty( $customer['id'] ) || ! empty( $customer['deleted'] ) ) {
			return new WP_Error( 'customer_not_found', __( 'Stripe customer was not found.', 'ajforms' ) );
		}

		$this->upsert_portal_record(
			$this->get_portal_stripe_customers_table(),
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
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			'stripe_customer_id'
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

	private function disable_stripe_customer_portal_access( $stripe_customer_id ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$mapping = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_user_mappings_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
			)
		);

		if ( $mapping && ! empty( $mapping->user_id ) ) {
			$user = get_userdata( (int) $mapping->user_id );
			if ( $user && in_array( 'aj_portal_user', (array) $user->roles, true ) ) {
				$user->set_role( 'subscriber' );
			}
		}

		$wpdb->delete( $this->get_portal_user_mappings_table(), array( 'stripe_customer_id' => $stripe_customer_id ), array( '%s' ) );
		$wpdb->update(
			$this->get_portal_stripe_customers_table(),
			array( 'enabled_portal' => 0 ),
			array( 'stripe_customer_id' => $stripe_customer_id ),
			array( '%d' ),
			array( '%s' )
		);

		return true;
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

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT stripe_customer_id FROM {$this->get_portal_user_mappings_table()} WHERE user_id = %d AND stripe_customer_id <> %s LIMIT 1",
				(int) $user->ID,
				$stripe_customer_id
			)
		);

		if ( $existing ) {
			return new WP_Error( 'user_already_linked', __( 'That WordPress user is already linked to another Stripe customer.', 'ajforms' ) );
		}

		$user->set_role( 'aj_portal_user' );

		$this->upsert_portal_record(
			$this->get_portal_user_mappings_table(),
			array(
				'user_id'            => (int) $user->ID,
				'stripe_customer_id' => $stripe_customer_id,
				'customer_email'     => $email,
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' ),
			'stripe_customer_id'
		);

		$wpdb->update(
			$this->get_portal_stripe_customers_table(),
			array( 'enabled_portal' => 1 ),
			array( 'stripe_customer_id' => $stripe_customer_id ),
			array( '%d' ),
			array( '%s' )
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
		$user = $mapping && ! empty( $mapping->user_id ) ? get_userdata( (int) $mapping->user_id ) : null;

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
				20
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

		return array(
			'customer'          => $customer,
			'mapping'           => $mapping,
			'user'              => $user,
			'subscriptions'     => $subscriptions,
			'active_subs'       => array_values( array_filter( $subscriptions, array( $this, 'is_active_portal_subscription' ) ) ),
			'purchased_products' => $this->get_portal_customer_purchased_products( $subscriptions, $ledger ),
			'ledger'            => $ledger,
			'upcoming_payments' => $this->get_portal_customer_upcoming_payments( $subscriptions ),
			'entities'          => $entities,
			'files'             => $files,
			'tasks'             => array(),
		);
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
					'source'          => __( 'Subscription', 'ajforms' ),
				);
			}
		}

		foreach ( $ledger as $entry ) {
			if ( empty( $entry->description ) ) {
				continue;
			}

			$key = 'ledger-' . sanitize_key( (string) $entry->source_object_id );
			if ( isset( $products[ $key ] ) ) {
				continue;
			}

			$products[ $key ] = array(
				'name'             => sanitize_text_field( $entry->description ),
				'stripe_price_id'  => '',
				'stripe_product_id' => '',
				'source'           => __( 'Invoice / charge', 'ajforms' ),
			);
		}

		return array_values( $products );
	}

	private function format_portal_money( $amount, $currency ) {
		return sprintf(
			'%1$s %2$s',
			strtoupper( sanitize_text_field( (string) $currency ) ),
			number_format_i18n( (float) $amount, 2 )
		);
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

		$user->set_role( 'aj_portal_user' );

		$wpdb->update(
			$this->get_portal_stripe_customers_table(),
			array( 'enabled_portal' => 1 ),
			array( 'stripe_customer_id' => $stripe_customer_id ),
			array( '%d' ),
			array( '%s' )
		);

		$this->upsert_portal_record(
			$this->get_portal_user_mappings_table(),
			array(
				'user_id'            => (int) $user->ID,
				'stripe_customer_id' => $stripe_customer_id,
				'customer_email'     => sanitize_email( $customer->email ),
				'updated_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' ),
			'stripe_customer_id'
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
			if ( ! is_array( $price ) || empty( $price['id'] ) || ! empty( $price['recurring'] ) ) {
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
			$product_active = ! isset( $product['active'] ) || ! empty( $product['active'] );
			$price_active   = ! isset( $price['active'] ) || ! empty( $price['active'] );
			$unit_amount  = isset( $price['unit_amount'] ) ? absint( $price['unit_amount'] ) : 0;
			$currency     = isset( $price['currency'] ) ? strtolower( sanitize_key( $price['currency'] ) ) : 'usd';
			$amount       = in_array( $currency, array( 'jpy', 'krw', 'vnd' ), true ) ? $unit_amount : $unit_amount / 100;

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
				'product_active' => $product_active,
				'price_active'   => $price_active,
				'nickname'     => ! empty( $price['nickname'] ) ? sanitize_text_field( (string) $price['nickname'] ) : '',
				'amount'       => $amount,
				'currency'     => $currency,
			);
		}

		usort(
			$prices,
			function ( $a, $b ) {
				return strcasecmp( $a['product_name'], $b['product_name'] );
			}
		);

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
			'stripe_publishable_key'         => '',
			'stripe_secret_key'              => '',
			'stripe_products_mode'           => 'all',
			'stripe_selected_prices'         => array(),
		);
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
		} elseif ( 'ajforms-client-portal' === $page ) {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'file-library';
			if ( in_array( $tab, array( 'menu', 'portal-users', 'products-services' ), true ) ) {
				$this->handle_client_portal_settings_save();
			} elseif ( 'customer' === $tab ) {
				$this->handle_portal_customer_detail_actions();
			} else {
				$this->handle_file_library_actions();
			}
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
			array(
				'id'      => 'overview',
				'label'   => __( 'Overview', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'file-library',
				'label'   => __( 'File Library', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
		);

		$normalized = array();
		foreach ( array_merge( $default_items, $items ) as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}

			$id = sanitize_key( $item['id'] );
			$normalized[ $id ] = array(
				'id'      => $id,
				'label'   => ! empty( $item['label'] ) ? sanitize_text_field( $item['label'] ) : $id,
				'type'    => ! empty( $item['type'] ) && 'custom' === $item['type'] ? 'custom' : 'built_in',
				'url'     => ! empty( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
				'enabled' => ! isset( $item['enabled'] ) || (bool) $item['enabled'],
			);
		}

		return array_values( $normalized );
	}

	private function handle_client_portal_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['portal_action'], $_GET['_wpnonce'] ) ) {
			$action     = sanitize_key( wp_unslash( $_GET['portal_action'] ) );
			$secret_key = $this->get_stripe_secret_key_for_portal();
			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'menu';
			$current_tab = in_array( $current_tab, array( 'menu', 'portal-users', 'products-services' ), true ) ? $current_tab : 'menu';
			$args        = array( 'page' => 'ajforms-client-portal', 'tab' => $current_tab );

			if ( '' === $secret_key ) {
				$args['portal-error'] = rawurlencode( __( 'Stripe secret key is required.', 'ajforms' ) );
				wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
				exit;
			}

			check_admin_referer( 'ajcore_portal_' . $action );

			$result = null;
			if ( 'sync_products' === $action ) {
				$result = $this->sync_portal_stripe_products( $secret_key );
			} elseif ( 'sync_customers' === $action ) {
				$result = $this->sync_portal_stripe_customers( $secret_key );
			} elseif ( 'sync_subscriptions' === $action ) {
				$result = $this->sync_portal_stripe_subscriptions( $secret_key );
			} elseif ( 'sync_transactions' === $action ) {
				$result = $this->sync_portal_stripe_transactions( $secret_key );
			}

			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-synced'] = absint( $result );
			}

			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_enable_portal_customer_nonce'], $_POST['stripe_customer_id'] ) ) {
			check_admin_referer( 'ajcore_enable_portal_customer', 'ajcore_enable_portal_customer_nonce' );
			$result = $this->enable_stripe_customer_as_portal_user( sanitize_text_field( wp_unslash( $_POST['stripe_customer_id'] ) ) );
			$redirect_tab = isset( $_POST['redirect_tab'] ) ? sanitize_key( wp_unslash( $_POST['redirect_tab'] ) ) : 'portal-users';
			$redirect_tab = in_array( $redirect_tab, array( 'menu', 'portal-users', 'products-services' ), true ) ? $redirect_tab : 'portal-users';
			$args         = array( 'page' => 'ajforms-client-portal', 'tab' => $redirect_tab );
			if ( is_wp_error( $result ) ) {
				$args['portal-error'] = rawurlencode( $result->get_error_message() );
			} else {
				$args['portal-user-enabled'] = 1;
			}
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! isset( $_POST['ajcore_client_portal_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'ajcore_save_client_portal', 'ajcore_client_portal_nonce' );

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

		$new_label = isset( $_POST['new_portal_menu_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_portal_menu_label'] ) ) : '';
		$new_url   = isset( $_POST['new_portal_menu_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_portal_menu_url'] ) ) : '';
		if ( '' !== $new_label && '' !== $new_url ) {
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
			'stripe_publishable_key'         => isset( $_POST['stripe_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_publishable_key'] ) ) : '',
			'stripe_secret_key'              => isset( $_POST['stripe_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_secret_key'] ) ) : '',
			'stripe_products_mode'           => isset( $_POST['stripe_products_mode'] ) && in_array( sanitize_key( wp_unslash( $_POST['stripe_products_mode'] ) ), array( 'all', 'selected' ), true ) ? sanitize_key( wp_unslash( $_POST['stripe_products_mode'] ) ) : 'all',
			'stripe_selected_prices'         => isset( $_POST['stripe_selected_prices'] ) && is_array( $_POST['stripe_selected_prices'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $_POST['stripe_selected_prices'] ) ) ) ) : array(),
		);

		$section_keys = array(
			'general'      => array( 'default_notification_email', 'default_notification_subject', 'default_notifications_enabled', 'default_from_name', 'default_reply_to_mode', 'default_success_message', 'validation_mode', 'require_unique_form_names' ),
			'spam'         => array( 'honeypot_enabled', 'spam_challenge_provider', 'recaptcha_site_key', 'recaptcha_secret_key', 'hcaptcha_site_key', 'hcaptcha_secret_key', 'turnstile_site_key', 'turnstile_secret_key' ),
			'integrations' => array( 'webhook_url', 'asana_enabled', 'asana_personal_access_token', 'asana_workspace_gid', 'asana_project_gid' ),
			'payments'     => array( 'stripe_mode', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_products_mode', 'stripe_selected_prices' ),
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
			__( 'Leads', 'ajforms' ),
			__( 'Leads', 'ajforms' ),
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

	public function display_client_portal_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'file-library';
		$tab      = in_array( $tab, array( 'file-library', 'menu', 'portal-users', 'products-services', 'customer' ), true ) ? $tab : 'file-library';
		$base_url = add_query_arg( array( 'page' => 'ajforms-client-portal' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap ajforms-client-portal-admin">
			<style>
				.ajforms-client-portal-admin .ajcore-status-pill{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;background:#f0f6fc;color:#0969da}
				.ajforms-client-portal-admin .ajcore-status-pill.off{background:#f6f7f7;color:#646970}
			</style>
			<h1><?php esc_html_e( 'Client Portal', 'ajforms' ); ?></h1>
			<?php if ( 'customer' !== $tab ) : ?>
				<h2 class="nav-tab-wrapper">
					<a class="nav-tab <?php echo 'file-library' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'file-library', $base_url ) ); ?>"><?php esc_html_e( 'File Library', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'menu' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'menu', $base_url ) ); ?>"><?php esc_html_e( 'Menu', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'portal-users' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'portal-users', $base_url ) ); ?>"><?php esc_html_e( 'Portal Users', 'ajforms' ); ?></a>
					<a class="nav-tab <?php echo 'products-services' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'products-services', $base_url ) ); ?>"><?php esc_html_e( 'Products / Services', 'ajforms' ); ?></a>
				</h2>
			<?php endif; ?>
			<?php
			if ( 'customer' === $tab ) {
				$this->display_portal_customer_detail_page();
			} elseif ( 'portal-users' === $tab ) {
				$this->display_portal_users_tab();
			} elseif ( 'products-services' === $tab ) {
				$this->display_portal_products_services_tab();
			} elseif ( 'menu' === $tab ) {
				$this->display_client_portal_settings_tab( 'menu', true );
			} else {
				$this->display_file_library_page( true );
			}
			?>
		</div>
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
		$portal_on    = ! empty( $customer->enabled_portal ) && $user;
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

		<div class="ajcore-customer-head">
			<div>
				<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Client Portal', 'ajforms' ); ?></a></p>
				<h2><?php echo esc_html( ! empty( $customer->name ) ? $customer->name : $customer->email ); ?></h2>
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
			</div>
		</div>

		<div class="ajcore-customer-grid">
			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Stripe Customer Profile', 'ajforms' ); ?></h3>
				<dl class="ajcore-customer-meta">
					<dt><?php esc_html_e( 'Name', 'ajforms' ); ?></dt><dd><?php echo esc_html( $customer->name ); ?></dd>
					<dt><?php esc_html_e( 'Email', 'ajforms' ); ?></dt><dd><?php echo esc_html( $customer->email ); ?></dd>
					<dt><?php esc_html_e( 'Phone', 'ajforms' ); ?></dt><dd><?php echo esc_html( $customer->phone ); ?></dd>
					<dt><?php esc_html_e( 'Mode', 'ajforms' ); ?></dt><dd><?php echo ! empty( $customer->livemode ) ? esc_html__( 'Live', 'ajforms' ) : esc_html__( 'Test', 'ajforms' ); ?></dd>
					<dt><?php esc_html_e( 'Created', 'ajforms' ); ?></dt><dd><?php echo esc_html( $this->format_portal_date( $customer->created_at ) ); ?></dd>
					<dt><?php esc_html_e( 'Last Synced', 'ajforms' ); ?></dt><dd><?php echo esc_html( $this->format_portal_date( $customer->synced_at ) ); ?></dd>
				</dl>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Portal Access', 'ajforms' ); ?></h3>
				<dl class="ajcore-customer-meta">
					<dt><?php esc_html_e( 'Status', 'ajforms' ); ?></dt><dd><span class="ajcore-status-pill <?php echo esc_attr( $portal_on ? '' : 'off' ); ?>"><?php echo $portal_on ? esc_html__( 'Enabled', 'ajforms' ) : esc_html__( 'Disabled', 'ajforms' ); ?></span></dd>
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
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Active Subscriptions', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_subscriptions_table( $detail['active_subs'] ); ?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Purchased Products / Services', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_products_table( $detail['purchased_products'] ); ?>
			</div>

			<div class="ajcore-customer-card ajcore-customer-wide">
				<h3><?php esc_html_e( 'Recent Invoices / Charges Ledger', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_ledger_table( $detail['ledger'] ); ?>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Upcoming Payment', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_subscriptions_table( $detail['upcoming_payments'] ); ?>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Linked Entities', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_entities_table( $detail['entities'] ); ?>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Linked Files', 'ajforms' ); ?></h3>
				<?php $this->render_portal_customer_files_table( $detail['files'] ); ?>
			</div>

			<div class="ajcore-customer-card">
				<h3><?php esc_html_e( 'Linked Tasks', 'ajforms' ); ?></h3>
				<p><?php esc_html_e( 'No portal task records are synced yet.', 'ajforms' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_portal_customer_subscriptions_table( $subscriptions ) {
		if ( empty( $subscriptions ) ) {
			echo '<p>' . esc_html__( 'No matching subscriptions.', 'ajforms' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Subscription', 'ajforms' ); ?></th>
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

	private function display_portal_users_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
		}

		global $wpdb;

		$customers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, m.user_id, m.customer_email AS mapped_email, m.updated_at AS mapped_at FROM {$this->get_portal_stripe_customers_table()} c LEFT JOIN {$this->get_portal_user_mappings_table()} m ON m.stripe_customer_id = c.stripe_customer_id ORDER BY c.enabled_portal DESC, c.name ASC, c.email ASC LIMIT %d",
				300
			)
		);

		$total_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_customers_table()}" );
		$enabled_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_user_mappings_table()}" );
		?>
		<div class="ajforms-settings-card">
			<h2><?php esc_html_e( 'Portal Users', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'Portal users are WordPress users linked to synced Stripe Customers. Stripe remains the customer source of truth.', 'ajforms' ); ?></p>

			<?php if ( isset( $_GET['portal-user-enabled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stripe customer enabled as a portal user.', 'ajforms' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="ajforms-settings-inline-actions">
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d synced customers', 'ajforms' ), $total_customers ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d portal users', 'ajforms' ), $enabled_count ) ); ?></span>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'portal-users', 'portal_action' => 'sync_customers' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_customers' ) ); ?>"><?php esc_html_e( 'Sync Stripe Customers', 'ajforms' ); ?></a>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Stripe Customer', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Email', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Portal Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'WordPress User', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Synced', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $customers ) ) : ?>
						<tr>
							<td colspan="6">
								<p><strong><?php esc_html_e( 'No synced Stripe customers yet.', 'ajforms' ); ?></strong></p>
								<p><?php esc_html_e( 'Click Sync Stripe Customers to pull saved Stripe Customers and guest Checkout/Charge buyer records from the connected Stripe account.', 'ajforms' ); ?></p>
								<p>
									<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'portal-users', 'portal_action' => 'sync_customers' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_customers' ) ); ?>"><?php esc_html_e( 'Sync Stripe Customers', 'ajforms' ); ?></a>
								</p>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $customers as $customer ) : ?>
							<?php $user = ! empty( $customer->user_id ) ? get_userdata( (int) $customer->user_id ) : null; ?>
							<tr>
								<td>
									<strong><?php echo esc_html( ! empty( $customer->name ) ? $customer->name : __( 'Unnamed customer', 'ajforms' ) ); ?></strong><br>
									<code><?php echo esc_html( $customer->stripe_customer_id ); ?></code>
								</td>
								<td><?php echo esc_html( $customer->email ); ?></td>
								<td>
									<?php if ( ! empty( $customer->enabled_portal ) && $user ) : ?>
										<span class="ajcore-status-pill"><?php esc_html_e( 'Enabled', 'ajforms' ); ?></span>
									<?php else : ?>
										<span class="ajcore-status-pill off"><?php esc_html_e( 'Not enabled', 'ajforms' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $user ) {
										echo esc_html( $user->display_name . ' #' . $user->ID );
										echo '<br><span class="description">' . esc_html( $user->user_email ) . '</span>';
									} elseif ( ! empty( $customer->user_id ) ) {
										esc_html_e( 'Linked user missing', 'ajforms' );
									} else {
										esc_html_e( '-', 'ajforms' );
									}
									?>
								</td>
								<td><?php echo esc_html( $this->format_portal_date( $customer->synced_at ) ); ?></td>
								<td>
									<p style="margin:0 0 6px;">
										<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'customer', 'stripe_customer_id' => $customer->stripe_customer_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'View Portal Customer', 'ajforms' ); ?></a>
									</p>
									<?php if ( empty( $customer->user_id ) ) : ?>
										<form method="post" style="margin:0;">
											<?php wp_nonce_field( 'ajcore_enable_portal_customer', 'ajcore_enable_portal_customer_nonce' ); ?>
											<input type="hidden" name="stripe_customer_id" value="<?php echo esc_attr( $customer->stripe_customer_id ); ?>">
											<input type="hidden" name="redirect_tab" value="portal-users">
											<button type="submit" class="button"><?php esc_html_e( 'Enable Portal User', 'ajforms' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
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
		$active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_products_table()} WHERE active = 1" );
		$total_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->get_portal_stripe_products_table()}" );
		?>
		<div class="ajforms-settings-card">
			<h2><?php esc_html_e( 'Products / Services', 'ajforms' ); ?></h2>
			<p><?php esc_html_e( 'This is the Client Portal service catalog cache synced from Stripe Products and Prices. The public shortcode builder remains under AJ Core > Products.', 'ajforms' ); ?></p>

			<?php if ( isset( $_GET['portal-synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Synced %d Stripe records.', 'ajforms' ), absint( wp_unslash( $_GET['portal-synced'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['portal-error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['portal-error'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="ajforms-settings-inline-actions">
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d active', 'ajforms' ), $active_count ) ); ?></span>
				<span class="ajforms-settings-pill"><?php echo esc_html( sprintf( __( '%d total', 'ajforms' ), $total_count ) ); ?></span>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'products-services', 'portal_action' => 'sync_products' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_products' ) ); ?>"><?php esc_html_e( 'Sync Stripe Products', 'ajforms' ); ?></a>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product / Service', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Billing', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Stripe IDs', 'ajforms' ); ?></th>
						<th><?php esc_html_e( 'Synced', 'ajforms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No synced Stripe products yet.', 'ajforms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $product->custom_label ? $product->custom_label : $product->name ); ?></strong>
									<?php if ( ! empty( $product->description_override ) || ! empty( $product->description ) ) : ?>
										<br><span class="description"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $product->description_override ? $product->description_override : $product->description ), 22 ) ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $this->format_portal_money( $product->price_amount, $product->currency ) ); ?></td>
								<td><?php echo ! empty( $product->recurring_interval ) ? esc_html( ucfirst( $product->recurring_interval ) ) : esc_html__( 'One-time', 'ajforms' ); ?></td>
								<td>
									<?php if ( ! empty( $product->active ) ) : ?>
										<span class="ajcore-status-pill"><?php esc_html_e( 'Active', 'ajforms' ); ?></span>
									<?php else : ?>
										<span class="ajcore-status-pill off"><?php esc_html_e( 'Archived', 'ajforms' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<code><?php echo esc_html( $product->stripe_product_id ); ?></code><br>
									<code><?php echo esc_html( $product->stripe_price_id ); ?></code>
								</td>
								<td><?php echo esc_html( $this->format_portal_date( $product->synced_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
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
				<?php if ( ! $stripe_enabled ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Add your Stripe secret key under Settings > Stripe Payments before syncing portal data.', 'ajforms' ); ?></p></div>
				<?php endif; ?>
				<div class="ajforms-settings-inline-actions">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'menu', 'portal_action' => 'sync_products' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_products' ) ); ?>"><?php esc_html_e( 'Sync Stripe Products', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'menu', 'portal_action' => 'sync_customers' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_customers' ) ); ?>"><?php esc_html_e( 'Sync Stripe Customers', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'menu', 'portal_action' => 'sync_subscriptions' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_subscriptions' ) ); ?>"><?php esc_html_e( 'Sync Stripe Subscriptions', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'ajforms-client-portal', 'tab' => 'menu', 'portal_action' => 'sync_transactions' ), admin_url( 'admin.php' ) ), 'ajcore_portal_sync_transactions' ) ); ?>"><?php esc_html_e( 'Sync Stripe Invoices / Charges', 'ajforms' ); ?></a>
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
								<td><input type="text" name="new_portal_menu_label" placeholder="<?php esc_attr_e( 'Billing', 'ajforms' ); ?>"></td>
								<td><?php esc_html_e( 'Custom Link', 'ajforms' ); ?></td>
								<td><input type="url" name="new_portal_menu_url" placeholder="<?php echo esc_attr( home_url( '/billing/' ) ); ?>"></td>
							</tr>
						</tbody>
					</table>

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
				.ajcore-shortcode{display:inline-block;margin-top:12px;padding:6px 8px;border-radius:8px;background:#f1f5f9;color:#334155}
				.ajcore-products-empty{padding:24px;border:1px dashed #cbd5e1;border-radius:12px;background:#fff;color:#64748b}
				@media (max-width: 960px){.ajcore-builder-controls{grid-template-columns:1fr}}
			</style>

			<div class="ajcore-products-shell">
				<div class="ajcore-products-hero">
					<div>
						<h1><?php esc_html_e( 'Products', 'ajforms' ); ?></h1>
						<p><?php esc_html_e( 'Sync one-time Stripe Prices here, then place products on any page with the shortcode. Product payments use Stripe Checkout.', 'ajforms' ); ?></p>
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

				<p>
					<strong><?php esc_html_e( 'Page shortcodes:', 'ajforms' ); ?></strong>
					<code>[ajcore_products]</code>
					<code>[ajcore_products mode="cart"]</code>
				</p>

				<?php if ( empty( $prices ) ) : ?>
					<div class="ajcore-products-empty"><?php esc_html_e( 'No synced one-time Stripe prices yet.', 'ajforms' ); ?></div>
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
										<span><?php echo esc_html( strtoupper( $price['currency'] ) . ' ' . number_format_i18n( (float) $price['amount'], 2 ) . ' - ' . $price['id'] ); ?></span>
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
								<div class="ajcore-product-price"><?php echo esc_html( strtoupper( $price['currency'] ) . ' ' . number_format_i18n( (float) $price['amount'], 2 ) ); ?></div>
								<div class="ajcore-product-meta">
									<span><?php echo esc_html( 'Price: ' . $price['id'] ); ?></span>
									<span><?php echo esc_html( 'Product: ' . $price['product_id'] ); ?></span>
								</div>
								<code class="ajcore-shortcode">[ajcore_products price_ids="<?php echo esc_attr( $price['id'] ); ?>"]</code>
							</div>
						<?php endforeach; ?>
					</div>
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
									<div class="ajforms-settings-field" style="max-width:280px;margin-bottom:22px;">
										<label for="stripe_mode"><?php esc_html_e( 'Stripe Mode', 'ajforms' ); ?></label>
										<select name="stripe_mode" id="stripe_mode">
											<option value="test" <?php selected( $settings['stripe_mode'], 'test' ); ?>><?php esc_html_e( 'Test', 'ajforms' ); ?></option>
											<option value="live" <?php selected( $settings['stripe_mode'], 'live' ); ?>><?php esc_html_e( 'Live', 'ajforms' ); ?></option>
										</select>
									</div>
									<div class="ajforms-settings-grid">
										<div class="ajforms-settings-field">
											<label for="stripe_publishable_key"><?php esc_html_e( 'Publishable Key', 'ajforms' ); ?></label>
											<input name="stripe_publishable_key" id="stripe_publishable_key" type="text" value="<?php echo esc_attr( $settings['stripe_publishable_key'] ); ?>">
										</div>
										<div class="ajforms-settings-field">
											<label for="stripe_secret_key"><?php esc_html_e( 'Secret Key', 'ajforms' ); ?></label>
											<input name="stripe_secret_key" id="stripe_secret_key" type="text" value="<?php echo esc_attr( $settings['stripe_secret_key'] ); ?>">
										</div>
									</div>
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
