<?php
/**
 * AJCore product packages: portable JSON product content (format "ajcore.product",
 * schema_version "1.0") for Products > Catalog.
 *
 *   Import package  choose file(s) -> validate -> match an existing Stripe product (product_id,
 *                   unique exact name, or the product picked in the form) -> field-by-field
 *                   preview -> confirm -> write to Stripe -> re-sync. Nothing is written on
 *                   upload; only the changes ticked in the preview are sent.
 *   Export          one product as a package (embedded image when a local copy exists).
 *   Blank template  an empty package to fill in.
 *
 * Rules (enrich): only description, marketing_features, metadata and images change. Missing or
 * empty fields mean "no change" (never deletion). Metadata merges; dependency keys are protected.
 * Marketing features are added to the existing ones unless "replace" is chosen. Package prices
 * are never written; expected_default_price is a check against the live default price. The
 * image is stored in the Media Library (content-addressed by SHA-256) and its URL is prepended
 * to the product's Stripe images; Base64 never goes to Stripe. "create" packages are refused:
 * create the product in the editor, then import its content as "enrich".
 *
 * @package AJCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait AJCore_Product_Packages {

	private static $pkg_max_file_bytes  = 6291456; // 6 MB per package file
	private static $pkg_max_image_bytes = 2097152; // 2 MB, Stripe's product image limit
	private static $pkg_max_pixels      = 4000;    // per side

	/** Metadata keys a package may never change (dependency wiring is edited in its own UI). */
	private function pkg_is_protected_meta_key( $key ) {
		return (bool) preg_match( '/(requires|required|dependency)/i', (string) $key );
	}

	private function pkg_catalog_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'ajforms-products', 'tab' => 'catalog' ), $args ), admin_url( 'admin.php' ) );
	}

	private function pkg_blank_template() {
		return array(
			'format'         => 'ajcore.product',
			'schema_version' => '1.0',
			'operation'      => 'enrich',
			'target'         => array(
				'product_id'             => null,
				'exact_name'             => '',
				'expected_default_price' => null,
			),
			'product'        => array(
				'description'        => '',
				'marketing_features' => array(),
				'metadata'           => (object) array(),
			),
			'images'         => array(),
			'image_policy'   => 'prepend_preserve_existing',
			'prices'         => array(),
			'review_notes'   => array(
				'Set target.product_id or target.exact_name to an existing Stripe product.',
				'Metadata keys used by AJCore product pages: display_title, summary, rich_description, category, service_slug, billing_note, state_fees_note, important_note, audience, turnaround, requirements, steps, faq_1_q / faq_1_a, cta, seo_title, seo_description.',
				'images[]: { filename, mime_type, encoding: "base64", sha256, alt_text, data } (WebP, PNG or JPEG, max 2 MB).',
			),
		);
	}

	private function pkg_send_json_download( $data, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Live product from Stripe (default price expanded), or WP_Error. */
	private function pkg_fetch_product( $product_id, $secret_key ) {
		return $this->stripe_api_get( 'products/' . rawurlencode( $product_id ), $secret_key, array( 'expand' => array( 'default_price' ) ) );
	}

	private function pkg_feature_names( $product ) {
		return array_values(
			array_filter(
				array_map(
					static function ( $feature ) {
						return is_array( $feature ) && isset( $feature['name'] ) ? (string) $feature['name'] : '';
					},
					isset( $product['marketing_features'] ) ? (array) $product['marketing_features'] : array()
				),
				'strlen'
			)
		);
	}

	/** Fingerprint of the fields an import can change, to detect edits made after the preview. */
	private function pkg_snapshot( $product ) {
		$meta = isset( $product['metadata'] ) ? (array) $product['metadata'] : array();
		ksort( $meta );
		return md5( (string) wp_json_encode( array( (string) ( $product['description'] ?? '' ), $this->pkg_feature_names( $product ), $meta, array_values( (array) ( $product['images'] ?? array() ) ) ) ) );
	}

	/** Synced Stripe products: product_id => name (active only unless $all). */
	private function pkg_product_choices( $all = false ) {
		$cache   = $this->get_stripe_products_cache();
		$choices = array();
		foreach ( isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['product_id'] ) || ( ! $all && empty( $row['product_active'] ) ) ) {
				continue;
			}
			$choices[ (string) $row['product_id'] ] = (string) ( $row['product_name'] ?? $row['product_id'] );
		}
		natcasesort( $choices );
		return $choices;
	}

	/**
	 * Decodes and checks one embedded image. Returns [bytes, mime, width, height, sha256,
	 * filename, alt] or WP_Error. SVG and anything that is not a real raster image is refused.
	 */
	private function pkg_check_image( $image ) {
		if ( ! is_array( $image ) || 'base64' !== ( $image['encoding'] ?? '' ) || empty( $image['data'] ) || ! is_string( $image['data'] ) ) {
			return new WP_Error( 'pkg_image', __( 'Image is not embedded as Base64.', 'ajforms' ) );
		}
		if ( strlen( $image['data'] ) > (int) ceil( self::$pkg_max_image_bytes * 4 / 3 ) + 8 ) {
			return new WP_Error( 'pkg_image', __( 'Image is larger than 2 MB.', 'ajforms' ) );
		}
		$bytes = base64_decode( $image['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'pkg_image', __( 'Image Base64 is invalid.', 'ajforms' ) );
		}
		$sha = hash( 'sha256', $bytes );
		if ( empty( $image['sha256'] ) || ! hash_equals( strtolower( (string) $image['sha256'] ), $sha ) ) {
			return new WP_Error( 'pkg_image', __( 'Image SHA-256 does not match its data.', 'ajforms' ) );
		}
		$info  = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$types = array( 'image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg' );
		if ( ! $info || ! isset( $types[ $info['mime'] ] ) ) {
			return new WP_Error( 'pkg_image', __( 'Image is not a WebP, PNG or JPEG file.', 'ajforms' ) );
		}
		if ( $info[0] < 1 || $info[1] < 1 || $info[0] > self::$pkg_max_pixels || $info[1] > self::$pkg_max_pixels ) {
			return new WP_Error( 'pkg_image', __( 'Image dimensions are outside 1–4000 px.', 'ajforms' ) );
		}
		$base = sanitize_file_name( pathinfo( (string) ( $image['filename'] ?? 'product-image' ), PATHINFO_FILENAME ) );
		return array(
			'bytes'    => $bytes,
			'mime'     => $info['mime'],
			'width'    => (int) $info[0],
			'height'   => (int) $info[1],
			'sha256'   => $sha,
			'filename' => ( '' !== $base ? $base : 'product-image' ) . '-' . substr( $sha, 0, 8 ) . '.' . $types[ $info['mime'] ],
			'alt'      => sanitize_text_field( (string) ( $image['alt_text'] ?? '' ) ),
		);
	}

	private function pkg_price_label( $price ) {
		if ( ! is_array( $price ) || ! isset( $price['unit_amount'] ) ) {
			return __( 'none', 'ajforms' );
		}
		$label = strtoupper( (string) ( $price['currency'] ?? '' ) ) . ' ' . number_format_i18n( ( (int) $price['unit_amount'] ) / 100, 2 );
		if ( ! empty( $price['recurring']['interval'] ) ) {
			$count  = (int) ( $price['recurring']['interval_count'] ?? 1 );
			$label .= ' / ' . ( $count > 1 ? $count . ' ' : '' ) . $price['recurring']['interval'];
		} else {
			$label .= ' ' . __( 'one-time', 'ajforms' );
		}
		return $label;
	}

	private function pkg_price_matches( $expected, $actual ) {
		if ( ! is_array( $actual ) ) {
			return false;
		}
		if ( strtolower( (string) ( $expected['currency'] ?? '' ) ) !== strtolower( (string) ( $actual['currency'] ?? '' ) ) || (int) ( $expected['unit_amount'] ?? -1 ) !== (int) ( $actual['unit_amount'] ?? -2 ) ) {
			return false;
		}
		$exp_rec = $expected['recurring'] ?? null;
		$act_rec = $actual['recurring'] ?? null;
		if ( empty( $exp_rec ) || empty( $act_rec ) ) {
			return empty( $exp_rec ) && empty( $act_rec );
		}
		return (string) ( $exp_rec['interval'] ?? '' ) === (string) ( $act_rec['interval'] ?? '' ) && (int) ( $exp_rec['interval_count'] ?? 1 ) === (int) ( $act_rec['interval_count'] ?? 1 );
	}

	/**
	 * Validates one uploaded package and compares it with the live product. Never writes.
	 *
	 * @return array Preview item: file, errors[], warnings[], product_id, name, snapshot,
	 *               current{...}, proposed{...}.
	 */
	private function pkg_preview_item( $filename, $json, $explicit_product_id, $choices, $secret_key ) {
		$item = array( 'file' => $filename, 'errors' => array(), 'warnings' => array(), 'product_id' => '', 'name' => '' );
		$pkg  = json_decode( (string) $json, true );
		if ( ! is_array( $pkg ) ) {
			$item['errors'][] = __( 'Not valid JSON.', 'ajforms' );
			return $item;
		}
		if ( 'ajcore.product' !== ( $pkg['format'] ?? '' ) || '1.0' !== (string) ( $pkg['schema_version'] ?? '' ) ) {
			$item['errors'][] = __( 'Not an AJCore product package (format "ajcore.product", schema_version "1.0").', 'ajforms' );
			return $item;
		}
		if ( 'enrich' !== ( $pkg['operation'] ?? '' ) ) {
			$item['errors'][] = __( 'Only "enrich" packages can be imported. Create the product in the editor first, then import its content.', 'ajforms' );
			return $item;
		}
		if ( ! empty( $pkg['prices'] ) ) {
			$item['errors'][] = __( 'Enrich packages cannot contain prices.', 'ajforms' );
			return $item;
		}
		$product_in = isset( $pkg['product'] ) && is_array( $pkg['product'] ) ? $pkg['product'] : array();
		$unknown    = array_diff( array_keys( $product_in ), array( 'description', 'marketing_features', 'metadata', 'shippable', 'active', 'name' ) );
		if ( $unknown ) {
			$item['errors'][] = sprintf( __( 'Unsupported product fields: %s.', 'ajforms' ), implode( ', ', $unknown ) );
			return $item;
		}
		if ( isset( $product_in['name'] ) || isset( $product_in['active'] ) || isset( $product_in['shippable'] ) ) {
			$item['warnings'][] = __( 'Name, active and shippable in the package are ignored (change them in the product editor).', 'ajforms' );
		}

		// Target.
		$target     = isset( $pkg['target'] ) && is_array( $pkg['target'] ) ? $pkg['target'] : array();
		$product_id = '';
		if ( '' !== $explicit_product_id ) {
			$product_id = $explicit_product_id;
			$item['matched_by'] = __( 'chosen in the form', 'ajforms' );
		} elseif ( ! empty( $target['product_id'] ) ) {
			$product_id = sanitize_text_field( (string) $target['product_id'] );
			$item['matched_by'] = __( 'product ID in the file', 'ajforms' );
		} elseif ( ! empty( $target['exact_name'] ) ) {
			$hits = array_keys( $choices, (string) $target['exact_name'], true );
			if ( 1 !== count( $hits ) ) {
				$item['errors'][] = 0 === count( $hits )
					? sprintf( __( 'No active product is named exactly "%s". Pick the product in the form.', 'ajforms' ), $target['exact_name'] )
					: sprintf( __( 'Several products are named "%s". Pick the product in the form.', 'ajforms' ), $target['exact_name'] );
				return $item;
			}
			$product_id         = (string) $hits[0];
			$item['matched_by'] = __( 'exact name', 'ajforms' );
		} else {
			$item['errors'][] = __( 'The file names no target product. Pick the product in the form.', 'ajforms' );
			return $item;
		}

		$product = $this->pkg_fetch_product( $product_id, $secret_key );
		if ( is_wp_error( $product ) ) {
			$item['errors'][] = sprintf( __( 'Could not load product %1$s from Stripe: %2$s', 'ajforms' ), $product_id, $product->get_error_message() );
			return $item;
		}
		$item['product_id'] = $product_id;
		$item['name']       = (string) ( $product['name'] ?? $product_id );
		$item['snapshot']   = $this->pkg_snapshot( $product );
		if ( ! empty( $target['exact_name'] ) && (string) $target['exact_name'] !== $item['name'] ) {
			$item['warnings'][] = sprintf( __( 'The file is for "%1$s"; it will be applied to "%2$s".', 'ajforms' ), $target['exact_name'], $item['name'] );
		}
		if ( ! empty( $target['expected_default_price'] ) && is_array( $target['expected_default_price'] ) ) {
			$actual = is_array( $product['default_price'] ?? null ) ? $product['default_price'] : null;
			if ( ! $this->pkg_price_matches( $target['expected_default_price'], $actual ) ) {
				$message = sprintf( __( 'Price check failed: the file expects %1$s, the product\'s default price is %2$s.', 'ajforms' ), $this->pkg_price_label( $target['expected_default_price'] ), $this->pkg_price_label( $actual ) );
				if ( '' !== $explicit_product_id ) {
					$item['warnings'][] = $message;
				} else {
					$item['errors'][] = $message;
				}
			}
		} else {
			$item['warnings'][] = __( 'The file has no expected price: check the price by hand.', 'ajforms' );
		}

		$current = array(
			'description' => (string) ( $product['description'] ?? '' ),
			'features'    => $this->pkg_feature_names( $product ),
			'metadata'    => isset( $product['metadata'] ) ? (array) $product['metadata'] : array(),
			'images'      => array_values( (array) ( $product['images'] ?? array() ) ),
		);
		$proposed = array( 'description' => '', 'features' => array(), 'metadata' => array(), 'image' => null );

		$description = isset( $product_in['description'] ) && is_string( $product_in['description'] ) ? trim( sanitize_textarea_field( $product_in['description'] ) ) : '';
		if ( '' !== $description && $description !== $current['description'] ) {
			$proposed['description'] = $description;
		}

		foreach ( isset( $product_in['marketing_features'] ) ? (array) $product_in['marketing_features'] : array() as $feature ) {
			$name = is_array( $feature ) && isset( $feature['name'] ) ? trim( sanitize_text_field( (string) $feature['name'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			if ( strlen( $name ) > 80 ) {
				$item['errors'][] = sprintf( __( 'Marketing feature is over 80 characters: "%s".', 'ajforms' ), $name );
				continue;
			}
			$proposed['features'][] = $name;
		}
		if ( count( array_unique( array_merge( $current['features'], $proposed['features'] ) ) ) > 15 ) {
			$item['warnings'][] = __( 'Adding these features would pass Stripe\'s 15; choose "replace" in the preview.', 'ajforms' );
		}
		if ( count( $proposed['features'] ) > 15 ) {
			$item['errors'][] = __( 'The package has more than 15 marketing features.', 'ajforms' );
		}

		foreach ( isset( $product_in['metadata'] ) && is_array( $product_in['metadata'] ) ? $product_in['metadata'] : array() as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || strlen( $key ) > 40 || false !== strpbrk( $key, '[]' ) ) {
				$item['errors'][] = sprintf( __( 'Invalid metadata key "%s" (max 40 characters, no brackets).', 'ajforms' ), $key );
				continue;
			}
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue; // empty / null = no change, never deletion
			}
			$value = trim( sanitize_textarea_field( (string) $value ) );
			if ( strlen( $value ) > 500 ) {
				$item['errors'][] = sprintf( __( 'Metadata "%s" is over 500 characters.', 'ajforms' ), $key );
				continue;
			}
			if ( $this->pkg_is_protected_meta_key( $key ) ) {
				$item['warnings'][] = sprintf( __( 'Metadata "%s" is a dependency setting and is never changed by a package.', 'ajforms' ), $key );
				continue;
			}
			if ( ! array_key_exists( $key, $current['metadata'] ) || (string) $current['metadata'][ $key ] !== $value ) {
				$proposed['metadata'][ $key ] = $value;
			}
		}
		if ( count( array_unique( array_merge( array_keys( $current['metadata'] ), array_keys( $proposed['metadata'] ) ) ) ) > 50 ) {
			$item['errors'][] = __( 'Merged metadata would pass Stripe\'s 50 keys.', 'ajforms' );
		}

		$images = isset( $pkg['images'] ) ? (array) $pkg['images'] : array();
		if ( $images ) {
			if ( 'prepend_preserve_existing' !== ( $pkg['image_policy'] ?? 'prepend_preserve_existing' ) ) {
				$item['errors'][] = __( 'Only image_policy "prepend_preserve_existing" is supported.', 'ajforms' );
			}
			$checked = $this->pkg_check_image( $images[0] );
			if ( is_wp_error( $checked ) ) {
				$item['errors'][] = $checked->get_error_message();
			} else {
				$proposed['image'] = array(
					'data'     => base64_encode( $checked['bytes'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- kept only in the preview transient
					'mime'     => $checked['mime'],
					'sha256'   => $checked['sha256'],
					'filename' => $checked['filename'],
					'alt'      => '' !== $checked['alt'] ? $checked['alt'] : $item['name'],
					'size'     => $checked['width'] . '×' . $checked['height'] . ', ' . size_format( strlen( $checked['bytes'] ) ),
				);
				if ( count( $current['images'] ) >= 8 ) {
					$item['warnings'][] = __( 'The product already has 8 images: adding this one drops the last.', 'ajforms' );
				}
			}
			if ( count( $images ) > 1 ) {
				$item['warnings'][] = __( 'Only the first image in the package is imported.', 'ajforms' );
			}
		}

		$item['current']  = $current;
		$item['proposed'] = $proposed;
		if ( empty( $item['errors'] ) && '' === $proposed['description'] && ! array_diff( $proposed['features'], $current['features'] ) && ! $proposed['metadata'] && ! $proposed['image'] ) {
			$item['warnings'][] = __( 'Nothing to change: the product already matches this package.', 'ajforms' );
		}
		return $item;
	}

	/** Media Library attachment for a package image, reusing one with the same SHA-256. */
	private function pkg_store_image( $image, $title ) {
		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_ajcore_package_image_sha256', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $image['sha256'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$bytes = base64_decode( (string) $image['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $bytes || ! hash_equals( (string) $image['sha256'], hash( 'sha256', $bytes ) ) ) {
			return new WP_Error( 'pkg_image', __( 'Stored image no longer matches its checksum.', 'ajforms' ) );
		}
		$tmp = wp_tempnam( $image['filename'] );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'pkg_image', __( 'Could not write the image to a temporary file.', 'ajforms' ) );
		}
		$id = media_handle_sideload( array( 'name' => $image['filename'], 'tmp_name' => $tmp ), 0, $title );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp ); // our own temp copy of the upload, not user content
			return $id;
		}
		update_post_meta( $id, '_ajcore_package_image_sha256', $image['sha256'] );
		update_post_meta( $id, '_wp_attachment_image_alt', $image['alt'] );
		return (int) $id;
	}

	/**
	 * Handles package requests (template / export downloads, preview upload, apply). Returns
	 * true when it handled (and redirected/streamed) the request.
	 */
	private function handle_pkg_actions() {
		$action = isset( $_GET['ajf_products_action'] ) ? sanitize_key( wp_unslash( $_GET['ajf_products_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'pkg_template' === $action ) {
			check_admin_referer( 'ajcore_pkg_template' );
			$this->pkg_send_json_download( $this->pkg_blank_template(), 'ajcore-product-package-template.json' );
		}

		if ( 'pkg_export' === $action && isset( $_GET['product_id'] ) ) {
			$product_id = sanitize_text_field( wp_unslash( $_GET['product_id'] ) );
			check_admin_referer( 'ajcore_pkg_export_' . $product_id );
			$this->pkg_export( $product_id );
		}

		if ( isset( $_POST['ajcore_pkg_preview_nonce'] ) ) {
			check_admin_referer( 'ajcore_pkg_preview', 'ajcore_pkg_preview_nonce' );
			$secret_key = $this->get_stripe_secret_key_for_portal();
			if ( '' === $secret_key ) {
				wp_safe_redirect( $this->pkg_catalog_url( array( 'catalog-error' => rawurlencode( __( 'Stripe secret key is required.', 'ajforms' ) ) ) ) );
				exit;
			}
			$explicit = isset( $_POST['pkg_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pkg_product_id'] ) ) : '';
			$choices  = $this->pkg_product_choices();
			$files    = isset( $_FILES['pkg_files'] ) && is_array( $_FILES['pkg_files']['name'] ?? null ) ? $_FILES['pkg_files'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$count    = $files ? count( $files['name'] ) : 0;
			if ( $count > 1 && '' !== $explicit ) {
				$explicit = ''; // one product can only be chosen for one file
			}
			if ( '' !== $explicit && ! isset( $this->pkg_product_choices( true )[ $explicit ] ) ) {
				$explicit = '';
			}
			$items = array();
			for ( $i = 0; $i < min( $count, 25 ); $i++ ) {
				$name = sanitize_file_name( (string) $files['name'][ $i ] );
				if ( UPLOAD_ERR_OK !== (int) $files['error'][ $i ] || ! is_uploaded_file( $files['tmp_name'][ $i ] ) ) {
					$items[] = array( 'file' => $name, 'errors' => array( __( 'Upload failed.', 'ajforms' ) ), 'warnings' => array() );
					continue;
				}
				if ( (int) $files['size'][ $i ] > self::$pkg_max_file_bytes ) {
					$items[] = array( 'file' => $name, 'errors' => array( __( 'File is larger than 6 MB.', 'ajforms' ) ), 'warnings' => array() );
					continue;
				}
				$items[] = $this->pkg_preview_item( $name, file_get_contents( $files['tmp_name'][ $i ] ), $explicit, $choices, $secret_key ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( ! $items ) {
				wp_safe_redirect( $this->pkg_catalog_url( array( 'catalog-error' => rawurlencode( __( 'Choose at least one package file.', 'ajforms' ) ) ) ) );
				exit;
			}
			$token = wp_generate_password( 20, false );
			set_transient( 'ajcore_pkg_' . $token, array( 'user' => get_current_user_id(), 'items' => $items ), 30 * MINUTE_IN_SECONDS );
			wp_safe_redirect( $this->pkg_catalog_url( array( 'pkg' => $token ) ) );
			exit;
		}

		if ( isset( $_POST['ajcore_pkg_apply_nonce'], $_POST['pkg_token'] ) ) {
			check_admin_referer( 'ajcore_pkg_apply', 'ajcore_pkg_apply_nonce' );
			$this->pkg_apply( sanitize_key( wp_unslash( $_POST['pkg_token'] ) ), wp_unslash( $_POST ) );
		}

		return false;
	}

	/** Writes the approved changes of a previewed import to Stripe, then re-syncs. */
	private function pkg_apply( $token, $post ) {
		$state = get_transient( 'ajcore_pkg_' . $token );
		if ( ! is_array( $state ) || (int) $state['user'] !== get_current_user_id() ) {
			wp_safe_redirect( $this->pkg_catalog_url( array( 'catalog-error' => rawurlencode( __( 'This preview has expired. Upload the package again.', 'ajforms' ) ) ) ) );
			exit;
		}
		$mode = $this->get_stripe_mode_badge_data();
		if ( ! empty( $mode['is_live'] ) && empty( $post['pkg_confirm_live'] ) ) {
			wp_safe_redirect( $this->pkg_catalog_url( array( 'pkg' => $token, 'catalog-error' => rawurlencode( __( 'Tick the LIVE confirmation to write to your live Stripe catalog.', 'ajforms' ) ) ) ) );
			exit;
		}
		$secret_key = $this->get_stripe_secret_key_for_portal();
		$apply      = isset( $post['pkg'] ) && is_array( $post['pkg'] ) ? $post['pkg'] : array();
		$results    = array();
		$image_map  = get_option( AJCORE_PRODUCT_IMAGE_MAP_OPTION, array() );
		$image_map  = is_array( $image_map ) ? $image_map : array();
		$wrote      = false;

		foreach ( $state['items'] as $index => $item ) {
			$choice = isset( $apply[ $index ] ) && is_array( $apply[ $index ] ) ? $apply[ $index ] : array();
			if ( empty( $choice['on'] ) || ! empty( $item['errors'] ) || empty( $item['product_id'] ) ) {
				continue;
			}
			$label   = $item['name'] . ' (' . $item['file'] . ')';
			$product = $this->pkg_fetch_product( $item['product_id'], $secret_key );
			if ( is_wp_error( $product ) ) {
				$results[] = array( 'ok' => false, 'text' => $label . ': ' . $product->get_error_message() );
				continue;
			}
			if ( $this->pkg_snapshot( $product ) !== $item['snapshot'] ) {
				$results[] = array( 'ok' => false, 'text' => $label . ': ' . __( 'changed in Stripe since the preview; nothing written. Upload the package again.', 'ajforms' ) );
				continue;
			}
			$proposed = $item['proposed'];
			$body     = array();
			$changed  = array();

			if ( ! empty( $choice['description'] ) && '' !== $proposed['description'] ) {
				$body['description'] = $proposed['description'];
				$changed[]           = __( 'description', 'ajforms' );
			}
			if ( ! empty( $choice['features'] ) && $proposed['features'] ) {
				$current_features = $this->pkg_feature_names( $product );
				$features         = 'replace' === ( $choice['features_mode'] ?? 'add' ) ? $proposed['features'] : array_values( array_unique( array_merge( $current_features, $proposed['features'] ) ) );
				if ( count( $features ) > 15 ) {
					$results[] = array( 'ok' => false, 'text' => $label . ': ' . __( 'more than 15 marketing features; choose "replace". Nothing written.', 'ajforms' ) );
					continue;
				}
				if ( $features !== $current_features ) {
					foreach ( $features as $f => $name ) {
						$body[ 'marketing_features[' . $f . '][name]' ] = $name;
					}
					$changed[] = __( 'marketing features', 'ajforms' );
				}
			}
			$meta_choice = isset( $choice['meta'] ) && is_array( $choice['meta'] ) ? $choice['meta'] : array();
			foreach ( $proposed['metadata'] as $key => $value ) {
				if ( ! empty( $meta_choice[ $key ] ) && ! $this->pkg_is_protected_meta_key( $key ) ) {
					$body[ 'metadata[' . $key . ']' ] = $value;
					$changed[]                        = $key;
				}
			}
			$new_image = '';
			if ( ! empty( $choice['image'] ) && ! empty( $proposed['image'] ) ) {
				$attachment_id = $this->pkg_store_image( $proposed['image'], $item['name'] );
				if ( is_wp_error( $attachment_id ) ) {
					$results[] = array( 'ok' => false, 'text' => $label . ': ' . $attachment_id->get_error_message() );
					continue;
				}
				$new_image = (string) wp_get_attachment_url( $attachment_id );
				if ( 0 !== strpos( $new_image, 'https://' ) ) {
					$results[] = array( 'ok' => false, 'text' => $label . ': ' . __( 'image saved, but its URL is not public HTTPS, so Stripe cannot use it. Nothing written.', 'ajforms' ) );
					continue;
				}
				$images = array_values( array_unique( array_merge( array( $new_image ), array_values( (array) ( $product['images'] ?? array() ) ) ) ) );
				foreach ( array_slice( $images, 0, 8 ) as $n => $url ) {
					$body[ 'images[' . $n . ']' ] = $url;
				}
				$image_map[ $new_image ] = $attachment_id; // the page uses this copy, no re-download
				$changed[]               = __( 'image', 'ajforms' );
			}
			if ( ! $body ) {
				$results[] = array( 'ok' => true, 'text' => $label . ': ' . __( 'nothing selected to change.', 'ajforms' ) );
				continue;
			}
			$result = $this->stripe_api_request( 'products/' . rawurlencode( $item['product_id'] ), $secret_key, $body, 'POST', array( 'Idempotency-Key' => 'ajcore-pkg-' . md5( $token . '|' . $item['product_id'] . '|' . wp_json_encode( $body ) ) ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'ok' => false, 'text' => $label . ': ' . $result->get_error_message() );
				continue;
			}
			$wrote     = true;
			$results[] = array( 'ok' => true, 'text' => $label . ': ' . sprintf( __( 'updated in Stripe (%s).', 'ajforms' ), implode( ', ', $changed ) ) . ( $new_image ? ' ' . $new_image : '' ) );
		}

		update_option( AJCORE_PRODUCT_IMAGE_MAP_OPTION, $image_map, false );
		if ( $wrote ) {
			$sync      = $this->sync_all_product_copies( $secret_key );
			$results[] = is_wp_error( $sync )
				? array( 'ok' => false, 'text' => __( 'Re-sync failed: ', 'ajforms' ) . $sync->get_error_message() . ' ' . __( 'Click Sync Stripe Products.', 'ajforms' ) )
				: array( 'ok' => true, 'text' => __( 'Products re-synced from Stripe.', 'ajforms' ) );
		}
		if ( ! $results ) {
			$results[] = array( 'ok' => false, 'text' => __( 'No product was selected to apply.', 'ajforms' ) );
		}
		delete_transient( 'ajcore_pkg_' . $token );
		$done = wp_generate_password( 20, false );
		set_transient( 'ajcore_pkg_done_' . $done, array( 'user' => get_current_user_id(), 'results' => $results ), 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( $this->pkg_catalog_url( array( 'pkg_done' => $done ) ) );
		exit;
	}

	/** Streams one product as a package (read-only; prices only as the expected-price check). */
	private function pkg_export( $product_id ) {
		$secret_key = $this->get_stripe_secret_key_for_portal();
		$product    = '' !== $secret_key ? $this->pkg_fetch_product( $product_id, $secret_key ) : new WP_Error( 'pkg', __( 'Stripe secret key is required.', 'ajforms' ) );
		if ( is_wp_error( $product ) ) {
			wp_safe_redirect( $this->pkg_catalog_url( array( 'catalog-error' => rawurlencode( $product->get_error_message() ) ) ) );
			exit;
		}
		$default  = is_array( $product['default_price'] ?? null ) ? $product['default_price'] : null;
		$metadata = array();
		foreach ( (array) ( $product['metadata'] ?? array() ) as $key => $value ) {
			if ( ! $this->pkg_is_protected_meta_key( $key ) ) {
				$metadata[ $key ] = (string) $value;
			}
		}
		$package = array(
			'format'         => 'ajcore.product',
			'schema_version' => '1.0',
			'operation'      => 'enrich',
			'target'         => array(
				'product_id'             => (string) $product['id'],
				'exact_name'             => (string) ( $product['name'] ?? '' ),
				'expected_default_price' => $default ? array_filter(
					array(
						'currency'    => (string) ( $default['currency'] ?? '' ),
						'unit_amount' => (int) ( $default['unit_amount'] ?? 0 ),
						'recurring'   => ! empty( $default['recurring'] ) ? array( 'interval' => (string) $default['recurring']['interval'], 'interval_count' => (int) ( $default['recurring']['interval_count'] ?? 1 ) ) : null,
					),
					static function ( $v ) { return null !== $v; }
				) : null,
			),
			'product'        => array(
				'description'        => (string) ( $product['description'] ?? '' ),
				'marketing_features' => array_map( static function ( $name ) { return array( 'name' => $name ); }, $this->pkg_feature_names( $product ) ),
				'metadata'           => (object) $metadata,
			),
			'images'         => array(),
			'image_policy'   => 'prepend_preserve_existing',
			'prices'         => array(),
			'review_notes'   => array( sprintf( 'Exported from %s on %s. Dependency settings are not included.', wp_parse_url( home_url(), PHP_URL_HOST ), gmdate( 'Y-m-d' ) ) ),
		);
		// Embed the first image when a local copy exists; otherwise list its URL only.
		$first = isset( $product['images'][0] ) ? (string) $product['images'][0] : '';
		if ( '' !== $first ) {
			$map  = get_option( AJCORE_PRODUCT_IMAGE_MAP_OPTION, array() );
			$id   = is_array( $map ) && isset( $map[ $first ] ) ? (int) $map[ $first ] : (int) attachment_url_to_postid( $first );
			$file = $id ? get_attached_file( $id ) : '';
			if ( $file && is_readable( $file ) && filesize( $file ) <= self::$pkg_max_image_bytes ) {
				$bytes               = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$package['images'][] = array(
					'filename'  => basename( $file ),
					'mime_type' => (string) get_post_mime_type( $id ),
					'encoding'  => 'base64',
					'sha256'    => hash( 'sha256', $bytes ),
					'alt_text'  => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
					'data'      => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				);
			} else {
				$package['image_urls']     = array_values( (array) $product['images'] );
				$package['review_notes'][] = 'Images are listed as URLs only (no local copy to embed); they are not imported. Re-upload them if needed.';
			}
		}
		$this->pkg_send_json_download( $package, 'product-' . sanitize_title( (string) ( $product['name'] ?? $product_id ) ) . '.json' );
	}

	/** Import panel on Products > Catalog: upload form, preview, or results. */
	private function render_pkg_panel() {
		$mode = $this->get_stripe_mode_badge_data();
		$live = ! empty( $mode['is_live'] );
		$tpl  = wp_nonce_url( $this->pkg_catalog_url( array( 'ajf_products_action' => 'pkg_template' ) ), 'ajcore_pkg_template' );

		// Results after apply.
		$done = isset( $_GET['pkg_done'] ) ? get_transient( 'ajcore_pkg_done_' . sanitize_key( wp_unslash( $_GET['pkg_done'] ) ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_array( $done ) && (int) $done['user'] === get_current_user_id() ) {
			echo '<div class="ajcore-pkg-box"><h3>' . esc_html__( 'Import results', 'ajforms' ) . '</h3><ul class="ajcore-pkg-results">';
			foreach ( $done['results'] as $line ) {
				echo '<li class="' . ( $line['ok'] ? 'ok' : 'bad' ) . '">' . esc_html( $line['text'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		// Preview.
		$token = isset( $_GET['pkg'] ) ? sanitize_key( wp_unslash( $_GET['pkg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = '' !== $token ? get_transient( 'ajcore_pkg_' . $token ) : false;
		if ( is_array( $state ) && (int) $state['user'] === get_current_user_id() ) {
			$this->render_pkg_preview( $token, $state['items'], $live );
			return;
		}
		?>
		<details class="ajcore-pkg-box" id="ajcore-pkg-import" <?php echo '' !== $token ? 'open' : ''; ?>>
			<summary><strong><?php esc_html_e( 'Import product package', 'ajforms' ); ?></strong> <span class="ajcore-catalog-muted"><?php esc_html_e( 'JSON content for an existing product: preview first, nothing is saved until you confirm.', 'ajforms' ); ?></span></summary>
			<form method="post" enctype="multipart/form-data" class="ajcore-pkg-form">
				<?php wp_nonce_field( 'ajcore_pkg_preview', 'ajcore_pkg_preview_nonce' ); ?>
				<label><?php esc_html_e( 'Package file(s)', 'ajforms' ); ?><br><input type="file" name="pkg_files[]" accept=".json,application/json" multiple required></label>
				<label><?php esc_html_e( 'Apply to', 'ajforms' ); ?><br>
					<select name="pkg_product_id">
						<option value=""><?php esc_html_e( 'Product named in the file', 'ajforms' ); ?></option>
						<?php foreach ( $this->pkg_product_choices() as $choice_id => $choice_name ) : ?>
							<option value="<?php echo esc_attr( $choice_id ); ?>"><?php echo esc_html( $choice_name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload & preview', 'ajforms' ); ?></button>
				<a href="<?php echo esc_url( $tpl ); ?>"><?php esc_html_e( 'Download blank template', 'ajforms' ); ?></a>
			</form>
			<p class="ajcore-catalog-muted" style="margin:6px 0 0;"><?php esc_html_e( 'Several files: each goes to the product named inside it (the "Apply to" choice is for a single file). Export any product with the Export link in its row.', 'ajforms' ); ?></p>
		</details>
		<?php
	}

	private function render_pkg_preview( $token, $items, $live ) {
		?>
		<form method="post" class="ajcore-pkg-box ajcore-pkg-preview">
			<?php wp_nonce_field( 'ajcore_pkg_apply', 'ajcore_pkg_apply_nonce' ); ?>
			<input type="hidden" name="pkg_token" value="<?php echo esc_attr( $token ); ?>">
			<h3><?php esc_html_e( 'Import preview', 'ajforms' ); ?>
				<span class="ajcore-pkg-mode <?php echo $live ? 'is-live' : ''; ?>"><?php echo $live ? esc_html__( 'Stripe LIVE', 'ajforms' ) : esc_html__( 'Stripe Sandbox', 'ajforms' ); ?></span>
			</h3>
			<p class="ajcore-catalog-muted"><?php esc_html_e( 'Untick anything you do not want. Only ticked changes are sent; everything else on the product stays as it is.', 'ajforms' ); ?></p>
			<?php foreach ( $items as $i => $item ) : ?>
				<?php $blocked = ! empty( $item['errors'] ) || empty( $item['product_id'] ); ?>
				<div class="ajcore-pkg-item <?php echo $blocked ? 'is-blocked' : ''; ?>">
					<div class="ajcore-pkg-head">
						<label>
							<input type="checkbox" name="pkg[<?php echo (int) $i; ?>][on]" value="1" <?php checked( ! $blocked ); ?> <?php disabled( $blocked ); ?>>
							<strong><?php echo esc_html( '' !== ( $item['name'] ?? '' ) ? $item['name'] : $item['file'] ); ?></strong>
						</label>
						<span class="ajcore-catalog-muted"><?php echo esc_html( $item['file'] . ( ! empty( $item['matched_by'] ) ? ' · ' . sprintf( __( 'matched by %s', 'ajforms' ), $item['matched_by'] ) : '' ) ); ?></span>
					</div>
					<?php foreach ( (array) $item['errors'] as $error ) : ?>
						<div class="ajcore-pkg-msg bad"><?php echo esc_html( $error ); ?></div>
					<?php endforeach; ?>
					<?php foreach ( (array) $item['warnings'] as $warning ) : ?>
						<div class="ajcore-pkg-msg warn"><?php echo esc_html( $warning ); ?></div>
					<?php endforeach; ?>
					<?php if ( ! $blocked ) : ?>
						<?php $cur = $item['current']; $new = $item['proposed']; ?>
						<table class="widefat ajcore-pkg-diff">
							<thead><tr><th style="width:24px;"></th><th style="width:18%;"><?php esc_html_e( 'Field', 'ajforms' ); ?></th><th><?php esc_html_e( 'Now in Stripe', 'ajforms' ); ?></th><th><?php esc_html_e( 'From package', 'ajforms' ); ?></th></tr></thead>
							<tbody>
							<?php if ( ! empty( $new['image'] ) ) : ?>
								<tr>
									<td><input type="checkbox" name="pkg[<?php echo (int) $i; ?>][image]" value="1" checked></td>
									<td><?php esc_html_e( 'Image', 'ajforms' ); ?></td>
									<td><?php echo esc_html( sprintf( _n( '%d image', '%d images', count( $cur['images'] ), 'ajforms' ), count( $cur['images'] ) ) ); ?></td>
									<td><img src="<?php echo esc_attr( 'data:' . $new['image']['mime'] . ';base64,' . $new['image']['data'] ); ?>" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:8px;vertical-align:middle;"> <span class="ajcore-catalog-muted"><?php echo esc_html( $new['image']['size'] . ' · ' . __( 'added first, existing kept', 'ajforms' ) ); ?></span></td>
								</tr>
							<?php endif; ?>
							<?php if ( '' !== $new['description'] ) : ?>
								<tr>
									<td><input type="checkbox" name="pkg[<?php echo (int) $i; ?>][description]" value="1" checked></td>
									<td><?php esc_html_e( 'Description', 'ajforms' ); ?></td>
									<td class="old"><?php echo esc_html( $cur['description'] ); ?></td>
									<td class="new"><?php echo esc_html( $new['description'] ); ?></td>
								</tr>
							<?php endif; ?>
							<?php if ( $new['features'] && array_diff( $new['features'], $cur['features'] ) ) : ?>
								<tr>
									<td><input type="checkbox" name="pkg[<?php echo (int) $i; ?>][features]" value="1" checked></td>
									<td><?php esc_html_e( 'Marketing features', 'ajforms' ); ?>
										<div style="margin-top:6px;font-size:12px;">
											<label><input type="radio" name="pkg[<?php echo (int) $i; ?>][features_mode]" value="add" checked> <?php esc_html_e( 'Add to existing', 'ajforms' ); ?></label><br>
											<label><input type="radio" name="pkg[<?php echo (int) $i; ?>][features_mode]" value="replace"> <?php esc_html_e( 'Replace', 'ajforms' ); ?></label>
										</div>
									</td>
									<td class="old"><?php echo $cur['features'] ? '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $cur['features'] ) ) . '</li></ul>' : '—'; ?></td>
									<td class="new"><ul><li><?php echo implode( '</li><li>', array_map( 'esc_html', $new['features'] ) ); ?></li></ul></td>
								</tr>
							<?php endif; ?>
							<?php foreach ( $new['metadata'] as $key => $value ) : ?>
								<tr>
									<td><input type="checkbox" name="pkg[<?php echo (int) $i; ?>][meta][<?php echo esc_attr( $key ); ?>]" value="1" checked></td>
									<td><code><?php echo esc_html( $key ); ?></code></td>
									<td class="old"><?php echo isset( $cur['metadata'][ $key ] ) && '' !== (string) $cur['metadata'][ $key ] ? esc_html( (string) $cur['metadata'][ $key ] ) : '<span class="ajcore-catalog-muted">' . esc_html__( '(not set)', 'ajforms' ) . '</span>'; ?></td>
									<td class="new"><?php echo esc_html( $value ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<div class="ajcore-pkg-actions">
				<?php if ( $live ) : ?>
					<label class="ajcore-pkg-live"><input type="checkbox" name="pkg_confirm_live" value="1" required> <?php esc_html_e( 'I understand this writes to my LIVE Stripe catalog.', 'ajforms' ); ?></label>
				<?php endif; ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save selected to Stripe', 'ajforms' ); ?></button>
				<a class="button" href="<?php echo esc_url( $this->pkg_catalog_url() ); ?>"><?php esc_html_e( 'Cancel', 'ajforms' ); ?></a>
			</div>
		</form>
		<?php
	}

	/** Styles for the import panel (printed once on the Catalog tab). */
	private function render_pkg_styles() {
		?>
		<style>
			.ajcore-pkg-box{margin:0 0 12px;padding:12px 16px;border:1px solid #dbe3ec;border-radius:12px;background:#fff}
			.ajcore-pkg-box > summary{cursor:pointer}
			.ajcore-pkg-form{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin:10px 0 0}
			.ajcore-pkg-form select{max-width:320px}
			.ajcore-pkg-mode{display:inline-block;margin-left:8px;padding:2px 10px;border-radius:999px;background:#dbeafe;color:#1e40af;font-size:12px}
			.ajcore-pkg-mode.is-live{background:#dc2626;color:#fff}
			.ajcore-pkg-item{margin:12px 0;padding:12px;border:1px solid #e5e7eb;border-radius:10px}
			.ajcore-pkg-item.is-blocked{background:#fef2f2;border-color:#fecaca}
			.ajcore-pkg-head{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0 0 6px}
			.ajcore-pkg-msg{margin:4px 0;padding:4px 10px;border-radius:6px;font-size:13px}
			.ajcore-pkg-msg.bad{background:#fee2e2;color:#991b1b}
			.ajcore-pkg-msg.warn{background:#fef3c7;color:#92400e}
			.ajcore-pkg-diff{margin-top:8px}
			.ajcore-pkg-diff td{vertical-align:top}
			.ajcore-pkg-diff td.old{color:#64748b}
			.ajcore-pkg-diff td.new{color:#065f46;background:#f0fdf4}
			.ajcore-pkg-diff ul{margin:0;padding-left:16px}
			.ajcore-pkg-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:12px}
			.ajcore-pkg-live{padding:6px 10px;border-radius:8px;background:#fee2e2;color:#991b1b;font-weight:600}
			.ajcore-pkg-results li.ok{color:#166534}
			.ajcore-pkg-results li.bad{color:#b32d2e}
		</style>
		<?php
	}
}
