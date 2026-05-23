<?php

class AJForms {

	private $plugin_name;
	private $version;

	public function __construct() {
		$this->plugin_name = 'ajforms';
		$this->version     = AJFORMS_VERSION;

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		add_filter( 'cron_schedules', array( $this, 'add_ajcore_cron_schedules' ) );
		add_action( 'init', array( $this, 'schedule_recurring_events' ) );
	}

	private function load_dependencies() {
		require_once AJFORMS_PLUGIN_DIR . 'admin/class-ajforms-admin.php';
	}

	private function define_admin_hooks() {
		$plugin_admin = new AJForms_Admin();

		add_action( 'admin_init', array( $plugin_admin, 'handle_admin_actions' ) );
		add_action( 'admin_init', array( $this, 'redirect_frontend_portal_users_from_admin' ), 1 );
		add_action( 'admin_post_ajf_export_form', array( $plugin_admin, 'handle_export_form_request' ) );
		add_action( 'admin_menu', array( $plugin_admin, 'add_plugin_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );
		add_filter( 'plugin_action_links_' . AJFORMS_PLUGIN_BASENAME, array( $plugin_admin, 'add_plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $plugin_admin, 'add_plugin_row_meta_links' ), 10, 2 );

		add_action( 'wp_ajax_ajf_save_form', array( $plugin_admin, 'ajax_save_form' ) );
		add_action( 'wp_ajax_ajf_import_form', array( $plugin_admin, 'ajax_import_form' ) );
		add_action( 'wp_ajax_ajf_sync_asana_reference_data', array( $plugin_admin, 'ajax_sync_asana_reference_data' ) );
		add_action( 'ajforms_daily_asana_sync', array( $plugin_admin, 'sync_asana_reference_data' ) );
		add_action( 'ajcore_portal_stripe_sync', array( $plugin_admin, 'run_scheduled_portal_sync_job' ) );
	}

	public function add_ajcore_cron_schedules( $schedules ) {
		$schedules['ajcore_every_15_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'ajforms' ),
		);
		$schedules['ajcore_every_30_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'ajforms' ),
		);
		$schedules['ajcore_every_6_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'ajforms' ),
		);
		$schedules['ajcore_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly', 'ajforms' ),
		);

		return $schedules;
	}

	public function schedule_recurring_events() {
		if ( ! wp_next_scheduled( 'ajforms_daily_asana_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ajforms_daily_asana_sync' );
		}

		$settings = get_option( 'ajcore_portal_sync_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$enabled  = ! empty( $settings['enabled'] );
		$frequency = ! empty( $settings['frequency'] ) ? sanitize_key( (string) $settings['frequency'] ) : 'daily';
		$allowed = array( 'hourly', 'twicedaily', 'daily', 'ajcore_every_6_hours', 'ajcore_weekly', 'ajcore_every_30_minutes', 'ajcore_every_15_minutes' );
		if ( ! in_array( $frequency, $allowed, true ) ) {
			$frequency = 'daily';
		}

		$scheduled = wp_get_schedule( 'ajcore_portal_stripe_sync' );
		if ( ! $enabled ) {
			if ( wp_next_scheduled( 'ajcore_portal_stripe_sync' ) ) {
				wp_clear_scheduled_hook( 'ajcore_portal_stripe_sync' );
			}
			return;
		}

		if ( $scheduled && $scheduled !== $frequency ) {
			wp_clear_scheduled_hook( 'ajcore_portal_stripe_sync' );
		}

		if ( ! wp_next_scheduled( 'ajcore_portal_stripe_sync' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, $frequency, 'ajcore_portal_stripe_sync' );
		}
	}

	private function define_public_hooks() {
		add_shortcode( 'ajforms', array( $this, 'render_form_shortcode' ) );
		add_shortcode( 'ajcore_products', array( $this, 'render_products_shortcode' ) );
		add_shortcode( 'aj_customer_portal', array( $this, 'render_customer_portal_shortcode' ) );
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 999999, 3 );
		add_filter( 'show_admin_bar', array( $this, 'filter_show_admin_bar' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'add_customer_portal_nav_item' ), 10, 2 );
		add_action( 'init', array( $this, 'maybe_create_customer_portal_page' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_form_preview' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_file_upload' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_file_download' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_service_request_remove' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_task_action' ) );
		add_action( 'wp_ajax_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_ajcore_create_checkout_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_nopriv_ajcore_create_checkout_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_ajcore_create_custom_service_request', array( $this, 'ajax_create_custom_service_request' ) );
		add_action( 'wp_ajax_ajcore_cancel_portal_service_request', array( $this, 'ajax_cancel_portal_service_request' ) );
	}

	private function is_portal_user_account( $user = null ) {
		if ( null === $user ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}

			$user = wp_get_current_user();
		}

		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return false;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$roles = array_map( 'sanitize_key', (array) $user->roles );
		$auth_settings = get_option( 'ajcore_auth_settings', array() );
		$customer_role = is_array( $auth_settings ) && ! empty( $auth_settings['customer_role'] ) ? sanitize_key( (string) $auth_settings['customer_role'] ) : 'aj_portal_user';

		if ( in_array( 'aj_portal_user', $roles, true ) || ( $customer_role && in_array( $customer_role, $roles, true ) ) ) {
			return true;
		}

		return ! user_can( $user, 'edit_posts' );
	}

	private function is_frontend_portal_user() {
		return $this->is_portal_user_account();
	}

	private function get_customer_portal_page_id() {
		$page_id = absint( get_option( 'ajcore_customer_portal_page_id', 0 ) );
		if ( $page_id && 'trash' !== get_post_status( $page_id ) ) {
			return $page_id;
		}

		$page = get_page_by_path( 'client-portal' );
		if ( $page && 'trash' !== get_post_status( $page ) ) {
			update_option( 'ajcore_customer_portal_page_id', (int) $page->ID, false );
			return (int) $page->ID;
		}

		$page = get_page_by_path( 'file-library' );
		if ( $page && 'trash' !== get_post_status( $page ) ) {
			update_option( 'ajcore_customer_portal_page_id', (int) $page->ID, false );
			return (int) $page->ID;
		}

		return 0;
	}

	private function get_customer_portal_url() {
		$page_id = $this->get_customer_portal_page_id();
		if ( $page_id ) {
			return get_permalink( $page_id );
		}

		return home_url( '/' );
	}

	public function maybe_create_customer_portal_page() {
		if ( get_option( 'ajcore_customer_portal_page_created', '' ) ) {
			return;
		}

		$page_id = $this->get_customer_portal_page_id();
		if ( ! $page_id && current_user_can( 'manage_options' ) ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Client Portal', 'ajforms' ),
					'post_name'    => 'client-portal',
					'post_content' => '[aj_customer_portal]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				),
				true
			);

			if ( ! is_wp_error( $page_id ) && $page_id ) {
				update_option( 'ajcore_customer_portal_page_id', (int) $page_id, false );
			}
		}

		update_option( 'ajcore_customer_portal_page_created', '1', false );
	}

	public function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		if ( $this->is_portal_user_account( $user ) ) {
			return $this->get_customer_portal_url();
		}

		return $redirect_to;
	}

	public function redirect_frontend_portal_users_from_admin() {
		if ( wp_doing_ajax() || ! $this->is_frontend_portal_user() ) {
			return;
		}

		$portal_url = $this->get_customer_portal_url();
		$current_url = set_url_scheme( 'http://' . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) );

		if ( $portal_url && untrailingslashit( $current_url ) !== untrailingslashit( $portal_url ) ) {
			wp_safe_redirect( $portal_url );
			exit;
		}
	}

	public function filter_show_admin_bar( $show ) {
		if ( $this->is_frontend_portal_user() ) {
			return false;
		}

		return $show;
	}

	public function add_customer_portal_nav_item( $items, $args ) {
		if ( ! $this->is_frontend_portal_user() ) {
			return $items;
		}

		$portal_url = $this->get_customer_portal_url();
		if ( ! $portal_url ) {
			return $items;
		}

		$items .= '<li class="menu-item ajcore-customer-portal-menu-item"><a href="' . esc_url( $portal_url ) . '">' . esc_html__( 'Client Portal', 'ajforms' ) . '</a></li>';

		return $items;
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
				'id'      => 'services',
				'label'   => __( 'My Services', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'tasks',
				'label'   => __( 'Tasks', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
			array(
				'id'      => 'billing',
				'label'   => __( 'Billing', 'ajforms' ),
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
			array(
				'id'      => 'profile',
				'label'   => __( 'Profile', 'ajforms' ),
				'type'    => 'built_in',
				'url'     => '',
				'enabled' => true,
			),
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

	private function render_customer_portal_file_library_tab() {
		$files  = $this->get_current_user_portal_files();
		$notice = isset( $_GET['portal_notice'] ) ? sanitize_key( wp_unslash( $_GET['portal_notice'] ) ) : '';

		$notice_message = '';
		$notice_class   = '';
		if ( 'file-uploaded' === $notice ) {
			$notice_message = __( 'Your document was uploaded and added to your File Library.', 'ajforms' );
			$notice_class   = 'is-success';
		} elseif ( 'file-upload-error' === $notice ) {
			$notice_message = __( 'Unable to upload the file. Please use a PDF, Word document, or image file.', 'ajforms' );
			$notice_class   = 'is-error';
		} elseif ( 'file-upload-invalid' === $notice ) {
			$notice_message = __( 'The upload request was invalid. Please try again.', 'ajforms' );
			$notice_class   = 'is-error';
		}

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'File Library', 'ajforms' ); ?></h2>

			<?php if ( $notice_message ) : ?>
				<div class="aj-portal-notice <?php echo esc_attr( $notice_class ); ?>"><?php echo esc_html( $notice_message ); ?></div>
			<?php endif; ?>

			<details class="aj-portal-upload-drawer">
				<summary class="aj-portal-upload-toggle"><?php esc_html_e( 'Upload File', 'ajforms' ); ?></summary>
				<div class="aj-portal-upload-card">
					<div>
						<h3><?php esc_html_e( 'Upload a Document', 'ajforms' ); ?></h3>
						<p><?php esc_html_e( 'Share PDFs, Word documents, and images with our team. Uploaded files will stay in your File Library.', 'ajforms' ); ?></p>
					</div>
					<form method="post" enctype="multipart/form-data" class="aj-portal-upload-form">
						<?php wp_nonce_field( 'ajcore_portal_file_upload', 'ajcore_portal_file_upload_nonce' ); ?>
						<input type="hidden" name="ajcore_portal_file_upload" value="1">
						<input type="text" name="portal_file_title" placeholder="<?php echo esc_attr__( 'Optional file title', 'ajforms' ); ?>">
						<input type="file" name="portal_file_upload" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif" required>
						<button type="submit" class="button"><?php esc_html_e( 'Upload File', 'ajforms' ); ?></button>
					</form>
				</div>
			</details>

			<?php if ( empty( $files ) ) : ?>
				<div class="aj-portal-empty-state">
					<strong><?php esc_html_e( 'No files yet', 'ajforms' ); ?></strong>
					<p><?php esc_html_e( 'Documents shared with your portal account and documents you upload will appear here.', 'ajforms' ); ?></p>
				</div>
			<?php else : ?>
				<div class="aj-customer-file-list" role="list">
					<?php foreach ( $files as $file ) : ?>
						<?php
						$download_url = wp_nonce_url(
							add_query_arg(
								array(
									'aj_portal_download' => (int) $file->id,
								),
								home_url( '/' )
							),
							'aj_portal_download_' . (int) $file->id
						);
						$file_category = '' !== (string) $file->category ? (string) $file->category : __( 'File', 'ajforms' );
						$file_date     = ! empty( $file->created_at ) ? $this->format_portal_date( $file->created_at ) : '';
						?>
						<div class="aj-customer-file-row" role="listitem">
							<div class="aj-customer-file-main">
								<div class="aj-customer-file-title"><?php echo esc_html( $file->title ); ?></div>
								<div class="aj-customer-file-meta">
									<span><?php echo esc_html( $file_category ); ?></span>
									<?php if ( $file_date ) : ?><span><?php echo esc_html( $file_date ); ?></span><?php endif; ?>
								</div>
								<?php if ( '' !== (string) $file->description ) : ?>
									<p><?php echo esc_html( $file->description ); ?></p>
								<?php endif; ?>
							</div>
							<div class="aj-customer-file-actions">
								<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'ajforms' ); ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_customer_portal_profile_tab() {
		$customer = $this->get_current_user_portal_customer();

		if ( ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Profile', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$business_name    = $this->get_portal_customer_meta_value( $customer, array( 'business_name', 'business', 'company', 'company_name' ) );
		$business_address = $this->get_customer_business_address( $customer );
		$display_name     = $customer->name ? $customer->name : $customer->email;

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Profile', 'ajforms' ); ?></h2>

			<div class="aj-portal-profile-block">
				<div class="aj-portal-profile-main"><?php echo esc_html( $display_name ); ?></div>

				<div class="aj-portal-profile-details">
					<?php if ( $business_name ) : ?>
						<div><?php echo esc_html( $business_name ); ?></div>
					<?php endif; ?>
					<?php if ( $customer->email ) : ?>
						<div><a href="<?php echo esc_url( 'mailto:' . $customer->email ); ?>"><?php echo esc_html( $customer->email ); ?></a></div>
					<?php endif; ?>
					<?php if ( $customer->phone ) : ?>
						<div><?php echo esc_html( $customer->phone ); ?></div>
					<?php endif; ?>
					<?php if ( $business_address ) : ?>
						<div><?php echo esc_html( $business_address ); ?></div>
					<?php endif; ?>
				</div>

				<div class="aj-portal-profile-actions">
					<a class="button" href="<?php echo esc_url( wp_lostpassword_url( $this->get_customer_portal_url() ) ); ?>"><?php esc_html_e( 'Change Password', 'ajforms' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Logout', 'ajforms' ); ?></a>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private function decode_portal_json( $value ) {
		$decoded = ! empty( $value ) ? json_decode( (string) $value, true ) : array();

		return is_array( $decoded ) ? $decoded : array();
	}

	private function get_portal_nested_value( $data, $path ) {
		$value = $data;
		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return '';
			}
			$value = $value[ $part ];
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	private function get_portal_customer_meta_value( $customer, $keys ) {
		$raw      = $this->decode_portal_json( isset( $customer->raw_data ) ? $customer->raw_data : '' );
		$metadata = $this->decode_portal_json( isset( $customer->metadata ) ? $customer->metadata : '' );

		foreach ( (array) $keys as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) && '' !== (string) $metadata[ $key ] ) {
				return sanitize_text_field( (string) $metadata[ $key ] );
			}
			$value = $this->get_portal_nested_value( $raw, 'metadata.' . $key );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
			$value = $this->get_portal_nested_value( $raw, $key );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
		}

		return '';
	}

	private function format_portal_money( $amount, $currency ) {
		return strtoupper( sanitize_text_field( (string) $currency ) ) . ' ' . number_format_i18n( (float) $amount, 2 );
	}

	private function format_portal_date( $date ) {
		if ( empty( $date ) ) {
			return '-';
		}

		$timestamp = is_numeric( $date ) ? (int) $date : strtotime( $date . ' UTC' );

		return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '-';
	}

	private function get_ledger_metadata_value( $entry, $key ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );

		return isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ? sanitize_text_field( (string) $metadata[ $key ] ) : '';
	}

	private function clean_stripe_line_service_name( $description ) {
		$description = sanitize_text_field( (string) $description );
		$description = preg_replace( '/^\s*\d+\s*(?:x|\x{00D7})\s*/iu', '', $description );
		$description = preg_replace( '/\s*\(at\s+.*?\)\s*$/i', '', $description );

		return trim( $description );
	}

	private function get_subscription_ledger_entry( $subscription, $ledger ) {
		$subscription_id = isset( $subscription->stripe_subscription_id ) ? sanitize_text_field( (string) $subscription->stripe_subscription_id ) : '';
		$fallback        = null;

		foreach ( $ledger as $entry ) {
			$metadata_subscription_id = $this->get_ledger_metadata_value( $entry, 'subscription_id' );
			if ( $subscription_id && $metadata_subscription_id && $subscription_id === $metadata_subscription_id ) {
				return $entry;
			}

			if ( null === $fallback && ( $this->get_ledger_metadata_value( $entry, 'description' ) || $this->get_ledger_metadata_value( $entry, 'service_period' ) ) ) {
				$fallback = $entry;
			}
		}

		return $fallback;
	}

	private function get_subscription_service_name( $subscription, $ledger_entry = null ) {
		if ( $ledger_entry ) {
			$description = $this->get_ledger_metadata_value( $ledger_entry, 'description' );
			if ( ! $description && ! empty( $ledger_entry->description ) ) {
				$description = $ledger_entry->description;
			}

			$service_name = $this->clean_stripe_line_service_name( $description );
			if ( $service_name ) {
				return $service_name;
			}
		}

		$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
		foreach ( $items as $item ) {
			if ( ! empty( $item['description'] ) ) {
				$service_name = $this->clean_stripe_line_service_name( $item['description'] );
				if ( $service_name ) {
					return $service_name;
				}
			}

			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			if ( ! empty( $price['nickname'] ) ) {
				return sanitize_text_field( (string) $price['nickname'] );
			}
			if ( ! empty( $price['product'] ) && is_array( $price['product'] ) && ! empty( $price['product']['name'] ) ) {
				return sanitize_text_field( (string) $price['product']['name'] );
			}
		}

		return isset( $subscription->stripe_subscription_id ) ? $subscription->stripe_subscription_id : __( 'Service', 'ajforms' );
	}

	private function get_subscription_amount_label( $subscription ) {
		$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
		foreach ( $items as $item ) {
			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			if ( isset( $price['unit_amount'] ) ) {
				$currency = ! empty( $price['currency'] ) ? $price['currency'] : 'usd';
				$amount   = in_array( strtolower( $currency ), array( 'jpy', 'krw', 'vnd' ), true ) ? (float) $price['unit_amount'] : (float) $price['unit_amount'] / 100;
				$interval = ! empty( $price['recurring']['interval'] ) ? '/' . sanitize_text_field( (string) $price['recurring']['interval'] ) : '';

				return $this->format_portal_money( $amount, $currency ) . $interval;
			}
		}

		return '-';
	}

	private function get_latest_invoice_url( $ledger ) {
		foreach ( $ledger as $entry ) {
			$invoice_pdf = $this->get_ledger_metadata_value( $entry, 'invoice_pdf' );
			if ( $invoice_pdf ) {
				return esc_url_raw( $invoice_pdf );
			}
			$hosted_invoice_url = $this->get_ledger_metadata_value( $entry, 'hosted_invoice_url' );
			if ( $hosted_invoice_url ) {
				return esc_url_raw( $hosted_invoice_url );
			}
		}

		return '';
	}

	private function format_portal_address( $address ) {
		$address = is_array( $address ) ? $address : array();
		$parts   = array();

		foreach ( array( 'line1', 'line2', 'city', 'state', 'postal_code', 'country' ) as $key ) {
			if ( ! empty( $address[ $key ] ) ) {
				$parts[] = sanitize_text_field( (string) $address[ $key ] );
			}
		}

		return implode( ', ', $parts );
	}

	private function get_customer_business_address( $customer ) {
		$raw = $this->decode_portal_json( isset( $customer->raw_data ) ? $customer->raw_data : '' );
		$address = ! empty( $raw['address'] ) && is_array( $raw['address'] ) ? $raw['address'] : $this->decode_portal_json( isset( $customer->address ) ? $customer->address : '' );

		return $this->format_portal_address( $address );
	}

	private function get_subscription_service_period( $subscription, $ledger_entry = null ) {
		if ( $ledger_entry ) {
			$service_period = $this->get_ledger_metadata_value( $ledger_entry, 'service_period' );
			if ( $service_period ) {
				return $service_period;
			}
		}

		$raw   = $this->decode_portal_json( isset( $subscription->raw_data ) ? $subscription->raw_data : '' );
		$start = ! empty( $raw['current_period_start'] ) ? (int) $raw['current_period_start'] : 0;
		$end   = ! empty( $raw['current_period_end'] ) ? (int) $raw['current_period_end'] : 0;

		if ( $start && $end ) {
			return $this->format_portal_date( $start ) . ' - ' . $this->format_portal_date( $end );
		}

		return ! empty( $subscription->current_period_end ) ? __( 'Through ', 'ajforms' ) . $this->format_portal_date( $subscription->current_period_end ) : '-';
	}

	private function get_subscription_next_billing_date( $subscription, $ledger_entry = null ) {
		if ( $ledger_entry ) {
			$service_period_end = $this->get_ledger_metadata_value( $ledger_entry, 'service_period_end' );
			if ( $service_period_end ) {
				return $this->format_portal_date( $service_period_end );
			}
		}

		return ! empty( $subscription->current_period_end ) ? $this->format_portal_date( $subscription->current_period_end ) : '-';
	}

	private function get_subscription_price_ids( $subscription ) {
		$price_ids = array();
		$items     = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );

		foreach ( $items as $item ) {
			$price_id = '';
			if ( ! empty( $item['price_id'] ) ) {
				$price_id = sanitize_text_field( (string) $item['price_id'] );
			}

			$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			if ( '' === $price_id && ! empty( $price['id'] ) ) {
				$price_id = sanitize_text_field( (string) $price['id'] );
			}

			if ( '' !== $price_id ) {
				$price_ids[] = $price_id;
			}
		}

		return array_values( array_unique( $price_ids ) );
	}

	private function get_subscription_product_ids( $subscription ) {
		$product_ids = array();
		$items       = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );

		foreach ( $items as $item ) {
			$product_id = ! empty( $item['product_id'] ) ? sanitize_text_field( (string) $item['product_id'] ) : '';
			$price      = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
			$product    = ! empty( $price['product'] ) && is_array( $price['product'] ) ? $price['product'] : array();

			if ( '' === $product_id && ! empty( $product['id'] ) ) {
				$product_id = sanitize_text_field( (string) $product['id'] );
			}
			if ( '' === $product_id && ! empty( $price['product'] ) && is_string( $price['product'] ) ) {
				$product_id = sanitize_text_field( (string) $price['product'] );
			}

			if ( '' !== $product_id ) {
				$product_ids[] = $product_id;
			}
		}

		return array_values( array_unique( $product_ids ) );
	}

	private function get_customer_purchased_price_ids( $subscriptions ) {
		$price_ids = array();
		foreach ( $subscriptions as $subscription ) {
			$price_ids = array_merge( $price_ids, $this->get_subscription_price_ids( $subscription ) );
		}

		return array_values( array_unique( array_filter( $price_ids ) ) );
	}

	private function get_customer_purchased_product_ids( $subscriptions ) {
		$product_ids = array();
		foreach ( $subscriptions as $subscription ) {
			$product_ids = array_merge( $product_ids, $this->get_subscription_product_ids( $subscription ) );
		}

		return array_values( array_unique( array_filter( $product_ids ) ) );
	}

	private function get_portal_product_duplicate_behavior( $product ) {
		$behavior = isset( $product->duplicate_behavior ) ? sanitize_key( (string) $product->duplicate_behavior ) : 'no_duplicates';
		$allowed  = array( 'no_duplicates', 'allow_duplicate', 'custom_request' );

		return in_array( $behavior, $allowed, true ) ? $behavior : 'no_duplicates';
	}

	private function get_portal_service_identity_tokens( $value ) {
		$value = strtolower( wp_strip_all_tags( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}

		$stop_words = array(
			'0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
			'a', 'an', 'and', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'per', 'the', 'to', 'with',
			'add', 'addon', 'annual', 'annually', 'basic', 'custom', 'fee', 'fees', 'monthly', 'month', 'months',
			'one', 'package', 'plan', 'price', 'pricing', 'product', 'recurring', 'request', 'service', 'services',
			'setup', 'standard', 'subscription', 'time', 'year', 'yearly', 'years',
		);
		$tokens = array();
		foreach ( preg_split( '/\s+/', $value ) as $token ) {
			$token = sanitize_key( $token );
			if ( '' === $token || in_array( $token, $stop_words, true ) || strlen( $token ) < 3 ) {
				continue;
			}
			$tokens[] = $token;
		}

		return array_values( array_unique( $tokens ) );
	}

	private function portal_service_token_sets_match( $product_tokens, $subscription_tokens ) {
		$product_tokens      = array_values( array_unique( array_filter( (array) $product_tokens ) ) );
		$subscription_tokens = array_values( array_unique( array_filter( (array) $subscription_tokens ) ) );

		if ( empty( $product_tokens ) || empty( $subscription_tokens ) ) {
			return false;
		}

		$shared = array_intersect( $product_tokens, $subscription_tokens );
		$minimum_shared = min( 2, count( $product_tokens ), count( $subscription_tokens ) );
		if ( count( $shared ) < $minimum_shared ) {
			return false;
		}

		$smaller_count = min( count( $product_tokens ), count( $subscription_tokens ) );
		return $smaller_count > 0 && ( count( $shared ) / $smaller_count ) >= 0.75;
	}

	private function is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $subscriptions = array(), $ledger = array() ) {
		$price_id   = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
		$product_id = isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '';

		if ( '' !== $price_id && in_array( $price_id, $purchased_price_ids, true ) ) {
			return true;
		}

		if ( '' !== $product_id && in_array( $product_id, $purchased_product_ids, true ) ) {
			return true;
		}

		if ( empty( $subscriptions ) ) {
			return false;
		}

		$product_name = ! empty( $product->custom_label ) ? $product->custom_label : ( ! empty( $product->name ) ? $product->name : '' );
		$product_tokens = $this->get_portal_service_identity_tokens( $product_name );
		if ( empty( $product_tokens ) ) {
			return false;
		}

		foreach ( $subscriptions as $subscription ) {
			$subscription_tokens = $this->get_portal_service_identity_tokens( $this->get_subscription_service_name( $subscription ) );
			if ( $this->portal_service_token_sets_match( $product_tokens, $subscription_tokens ) ) {
				return true;
			}

			$items = $this->decode_portal_json( isset( $subscription->items ) ? $subscription->items : '' );
			foreach ( $items as $item ) {
				$candidates = array();
				if ( ! empty( $item['description'] ) ) {
					$candidates[] = $item['description'];
				}
				$price = isset( $item['price'] ) && is_array( $item['price'] ) ? $item['price'] : array();
				if ( ! empty( $price['nickname'] ) ) {
					$candidates[] = $price['nickname'];
				}
				if ( ! empty( $price['product'] ) && is_array( $price['product'] ) && ! empty( $price['product']['name'] ) ) {
					$candidates[] = $price['product']['name'];
				}

				foreach ( $candidates as $candidate ) {
					if ( $this->portal_service_token_sets_match( $product_tokens, $this->get_portal_service_identity_tokens( $candidate ) ) ) {
						return true;
					}
				}
			}
		}

		foreach ( (array) $ledger as $entry ) {
			$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
			if ( ! in_array( $status, array( 'paid', 'succeeded', 'active' ), true ) ) {
				continue;
			}

			$candidates = array();
			if ( ! empty( $entry->description ) ) {
				$candidates[] = $entry->description;
			}

			$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
			foreach ( array( 'description', 'service_name', 'product_name' ) as $metadata_key ) {
				if ( ! empty( $metadata[ $metadata_key ] ) && is_scalar( $metadata[ $metadata_key ] ) ) {
					$candidates[] = (string) $metadata[ $metadata_key ];
				}
			}

			foreach ( $candidates as $candidate ) {
				if ( $this->portal_service_token_sets_match( $product_tokens, $this->get_portal_service_identity_tokens( $candidate ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function get_portal_filtered_service_products( $subscriptions ) {
		global $wpdb;

		$service_settings   = get_option( 'ajcore_customer_portal_services_display', array() );
		$service_settings   = is_array( $service_settings ) ? $service_settings : array();
		$product_mode       = isset( $service_settings['product_mode'] ) && 'selected' === $service_settings['product_mode'] ? 'selected' : 'all';
		$selected_price_ids = isset( $service_settings['selected_price_ids'] ) && is_array( $service_settings['selected_price_ids'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $service_settings['selected_price_ids'] ) ) ) : array();

		$products = $wpdb->get_results(
			"SELECT * FROM {$this->get_portal_stripe_products_table()} WHERE active = 1 AND visibility <> 'hidden' ORDER BY sort_order ASC, name ASC"
		);

		return array_values(
			array_filter(
				$products,
				function ( $product ) use ( $product_mode, $selected_price_ids ) {
					$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';

					if ( '' === $price_id ) {
						return false;
					}

					return 'selected' !== $product_mode || in_array( $price_id, $selected_price_ids, true );
				}
			)
		);
	}

	private function get_portal_available_service_products( $subscriptions, $ledger = array() ) {
		$purchased_price_ids   = $this->get_customer_purchased_price_ids( $subscriptions );
		$purchased_product_ids = $this->get_customer_purchased_product_ids( $subscriptions );
		$products              = $this->get_portal_filtered_service_products( $subscriptions );
		$stripe_customer_id    = $this->get_current_user_stripe_customer_id();

		return array_values(
			array_filter(
				$products,
				function ( $product ) use ( $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger, $stripe_customer_id ) {
					$is_owned = $this->is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger );
					$behavior = $this->get_portal_product_duplicate_behavior( $product );
					$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
					$product_id = isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '';
					$open_request = $this->get_open_portal_service_request_for_product( $stripe_customer_id, $price_id, $product_id );

					if ( 'allow_duplicate' === $behavior ) {
						return true;
					}

					return ! $is_owned && ! $open_request;
				}
			)
		);
	}

	private function get_portal_custom_request_products( $subscriptions, $ledger = array() ) {
		global $wpdb;

		$purchased_price_ids   = $this->get_customer_purchased_price_ids( $subscriptions );
		$purchased_product_ids = $this->get_customer_purchased_product_ids( $subscriptions );
		$products              = $this->get_portal_filtered_service_products( $subscriptions );
		$available_products    = $this->get_portal_available_service_products( $subscriptions, $ledger );
		$available_price_ids   = array();
		$custom_products       = array();
		$custom_price_ids      = array();
		$custom_request_keys   = array();

		foreach ( $available_products as $available_product ) {
			$available_price_id = isset( $available_product->stripe_price_id ) ? sanitize_text_field( (string) $available_product->stripe_price_id ) : '';
			if ( '' !== $available_price_id ) {
				$available_price_ids[] = $available_price_id;
			}
		}

		$has_service_context = ! empty( $subscriptions );
		if ( ! $has_service_context ) {
			foreach ( (array) $ledger as $entry ) {
				$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
				if ( in_array( $status, array( 'paid', 'succeeded', 'active' ), true ) ) {
					$has_service_context = true;
					break;
				}
			}
		}

		$maybe_add_custom_product = function ( $product ) use ( &$custom_products, &$custom_price_ids, &$custom_request_keys, $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger, $available_price_ids, $has_service_context ) {
			if ( 'custom_request' !== $this->get_portal_product_duplicate_behavior( $product ) ) {
				return;
			}

			$price_id = isset( $product->stripe_price_id ) ? sanitize_text_field( (string) $product->stripe_price_id ) : '';
			if ( '' === $price_id || in_array( $price_id, $custom_price_ids, true ) ) {
				return;
			}

			$request_title   = ! empty( $product->custom_request_title ) ? sanitize_text_field( (string) $product->custom_request_title ) : '';
			$request_message = ! empty( $product->custom_request_message ) ? sanitize_textarea_field( (string) $product->custom_request_message ) : '';
			$request_button  = ! empty( $product->custom_request_button_label ) ? sanitize_text_field( (string) $product->custom_request_button_label ) : '';
			$request_key_source = trim( $request_title . '|' . $request_message . '|' . $request_button, '|' );
			$request_key = '' !== $request_key_source ? md5( strtolower( $request_key_source ) ) : 'price:' . $price_id;
			if ( isset( $custom_request_keys[ $request_key ] ) ) {
				return;
			}

			if ( $this->is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $subscriptions, $ledger ) ) {
				$custom_products[]  = $product;
				$custom_price_ids[] = $price_id;
				$custom_request_keys[ $request_key ] = true;
				return;
			}

			// Last-resort generic fallback: if this product has been configured for custom requests,
			// is not available as a normal Add Service card, and the customer has active/paid service
			// context, show the configured custom request card. This avoids hardcoding service names
			// while still honoring the admin product behavior setting.
			if ( $has_service_context && ! in_array( $price_id, $available_price_ids, true ) ) {
				$custom_products[]  = $product;
				$custom_price_ids[] = $price_id;
				$custom_request_keys[ $request_key ] = true;
			}
		};

		foreach ( $products as $product ) {
			$maybe_add_custom_product( $product );
		}

		// Do not let product-mode selection hide an already-configured custom-request upsell.
		// The admin explicitly controls this using each product's duplicate_behavior setting.
		$fallback_products = $wpdb->get_results(
			"SELECT * FROM {$this->get_portal_stripe_products_table()} WHERE active = 1 AND visibility <> 'hidden' AND duplicate_behavior = 'custom_request' ORDER BY sort_order ASC, name ASC"
		);
		foreach ( $fallback_products as $product ) {
			$maybe_add_custom_product( $product );
		}

		return $custom_products;
	}

	private function get_portal_custom_request_source_object_id( $stripe_customer_id, $price_id ) {
		return 'custom_request_' . md5( sanitize_text_field( (string) $stripe_customer_id ) . '|' . sanitize_text_field( (string) $price_id ) );
	}

	private function get_open_custom_service_request( $stripe_customer_id, $price_id ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$price_id           = sanitize_text_field( (string) $price_id );
		if ( '' === $stripe_customer_id || '' === $price_id ) {
			return null;
		}

		$source_object_id = $this->get_portal_custom_request_source_object_id( $stripe_customer_id, $price_id );
		$service_request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_service_requests_table()} WHERE stripe_customer_id = %s AND source_object_id = %s AND request_type = %s AND status = %s LIMIT 1",
				$stripe_customer_id,
				$source_object_id,
				'custom_request',
				'admin_review_required'
			)
		);
		if ( $service_request ) {
			return $service_request;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE stripe_customer_id = %s AND source_object_id = %s AND source_type = %s AND status = %s LIMIT 1",
				$stripe_customer_id,
				$source_object_id,
				'custom_service_request',
				'admin_review_required'
			)
		);
	}


	private function normalize_portal_service_request_status( $status ) {
		$status = sanitize_key( (string) $status );
		$allowed = array( 'draft', 'pending_payment', 'paid', 'cancelled', 'failed', 'admin_review_required', 'completed' );

		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	private function upsert_portal_service_request( $data ) {
		global $wpdb;

		$defaults = array(
			'wp_user_id'          => get_current_user_id(),
			'stripe_customer_id'  => '',
			'stripe_price_id'     => '',
			'stripe_product_id'   => '',
			'service_name'        => '',
			'request_type'        => 'add_service',
			'status'              => 'draft',
			'amount'              => 0,
			'currency'            => 'usd',
			'source_object_id'    => '',
			'source_type'         => '',
			'ledger_id'           => 0,
			'admin_notes'         => '',
			'client_notes'        => '',
			'source'              => 'system',
			'created_by'          => get_current_user_id(),
			'raw_data'            => '',
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);
		$data = wp_parse_args( is_array( $data ) ? $data : array(), $defaults );
		$data['wp_user_id']         = absint( $data['wp_user_id'] );
		$data['stripe_customer_id'] = sanitize_text_field( (string) $data['stripe_customer_id'] );
		$data['stripe_price_id']    = sanitize_text_field( (string) $data['stripe_price_id'] );
		$data['stripe_product_id']  = sanitize_text_field( (string) $data['stripe_product_id'] );
		$data['service_name']       = sanitize_text_field( (string) $data['service_name'] );
		$data['request_type']       = sanitize_key( (string) $data['request_type'] );
		$data['status']             = $this->normalize_portal_service_request_status( $data['status'] );
		$data['amount']             = (float) $data['amount'];
		$data['currency']           = sanitize_key( (string) $data['currency'] );
		$data['source_object_id']   = sanitize_text_field( (string) $data['source_object_id'] );
		$data['source_type']        = sanitize_key( (string) $data['source_type'] );
		$data['ledger_id']          = absint( $data['ledger_id'] );
		$data['admin_notes']        = sanitize_textarea_field( (string) $data['admin_notes'] );
		$data['client_notes']       = sanitize_textarea_field( (string) $data['client_notes'] );
		$data['source']             = sanitize_key( (string) $data['source'] );
		$data['created_by']         = absint( $data['created_by'] );
		$data['raw_data']           = is_string( $data['raw_data'] ) ? $data['raw_data'] : wp_json_encode( $data['raw_data'] );

		$existing_id = 0;
		if ( '' !== $data['source_object_id'] ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->get_portal_service_requests_table()} WHERE source_object_id = %s LIMIT 1",
					$data['source_object_id']
				)
			);
		}

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );
		if ( $existing_id ) {
			unset( $data['created_at'] );
			array_splice( $formats, -2, 1 );
			$wpdb->update( $this->get_portal_service_requests_table(), $data, array( 'id' => $existing_id ), $formats, array( '%d' ) );
			return $existing_id;
		}

		$wpdb->insert( $this->get_portal_service_requests_table(), $data, $formats );
		return (int) $wpdb->insert_id;
	}

	private function get_open_portal_service_request_for_product( $stripe_customer_id, $price_id, $product_id = '' ) {
		global $wpdb;

		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );
		$price_id = sanitize_text_field( (string) $price_id );
		$product_id = sanitize_text_field( (string) $product_id );
		if ( '' === $stripe_customer_id || ( '' === $price_id && '' === $product_id ) ) {
			return null;
		}

		$open_statuses = array( 'draft', 'pending_payment', 'paid', 'admin_review_required' );
		$status_placeholders = implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) );
		$params = array_merge( array( $stripe_customer_id ), $open_statuses );
		$where = "stripe_customer_id = %s AND status IN ({$status_placeholders})";
		if ( '' !== $price_id && '' !== $product_id ) {
			$where .= ' AND (stripe_price_id = %s OR stripe_product_id = %s)';
			$params[] = $price_id;
			$params[] = $product_id;
		} elseif ( '' !== $price_id ) {
			$where .= ' AND stripe_price_id = %s';
			$params[] = $price_id;
		} else {
			$where .= ' AND stripe_product_id = %s';
			$params[] = $product_id;
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->get_portal_service_requests_table()} WHERE {$where} ORDER BY id DESC LIMIT 1", $params ) );
	}

	private function get_portal_product_amount_label( $product ) {
		$amount   = isset( $product->price_amount ) ? (float) $product->price_amount : 0;
		$currency = isset( $product->currency ) ? $product->currency : 'usd';
		$interval = ! empty( $product->recurring_interval ) ? '/' . sanitize_text_field( (string) $product->recurring_interval ) : '';

		return $this->format_portal_money( $amount, $currency ) . $interval;
	}

	private function get_portal_product_by_price_id( $price_id ) {
		global $wpdb;

		$price_id = sanitize_text_field( (string) $price_id );
		if ( '' === $price_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_products_table()} WHERE stripe_price_id = %s AND active = 1 AND visibility <> 'hidden' LIMIT 1",
				$price_id
			)
		);
	}

	private function get_current_user_portal_billing_context() {
		global $wpdb;

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$customer           = $this->get_current_user_portal_customer();

		if ( '' === $stripe_customer_id || ! $customer ) {
			return array(
				'stripe_customer_id'  => '',
				'customer'            => null,
				'subscriptions'       => array(),
				'ledger'              => array(),
				'upcoming'            => array(),
				'active_subscriptions'=> array(),
			);
		}

		$subscriptions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_subscriptions_table()} WHERE stripe_customer_id = %s ORDER BY current_period_end ASC",
				$stripe_customer_id
			)
		);
		$ledger = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE stripe_customer_id = %s ORDER BY ledger_date DESC LIMIT 50",
				$stripe_customer_id
			)
		);
		$upcoming = array_filter(
			$subscriptions,
			function ( $subscription ) {
				if ( empty( $subscription->current_period_end ) || ! in_array( $subscription->status, array( 'active', 'trialing' ), true ) ) {
					return false;
				}
				$renewal = strtotime( $subscription->current_period_end . ' UTC' );
				return $renewal && $renewal >= time();
			}
		);
		$active_subscriptions = array_filter(
			$subscriptions,
			function ( $subscription ) {
				return 'active' === $subscription->status || 'trialing' === $subscription->status;
			}
		);

		return array(
			'stripe_customer_id'   => $stripe_customer_id,
			'customer'             => $customer,
			'subscriptions'        => $subscriptions,
			'ledger'               => $ledger,
			'upcoming'             => $upcoming,
			'active_subscriptions' => $active_subscriptions,
		);
	}

	private function render_customer_portal_services_tab() {
		$context = $this->get_current_user_portal_billing_context();
		$customer = $context['customer'];

		if ( '' === $context['stripe_customer_id'] || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'My Services', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$subscriptions      = $context['subscriptions'];
		$ledger             = $context['ledger'];
		$service_settings   = get_option( 'ajcore_customer_portal_services_display', array() );
		$service_settings   = is_array( $service_settings ) ? $service_settings : array();
		$show_current       = ! isset( $service_settings['show_current_services'] ) || (bool) $service_settings['show_current_services'];
		$show_add           = ! isset( $service_settings['show_add_services'] ) || (bool) $service_settings['show_add_services'];
		$available_products = $show_add ? $this->get_portal_available_service_products( $subscriptions, $ledger ) : array();
		$custom_request_products = $show_add ? $this->get_portal_custom_request_products( $subscriptions, $ledger ) : array();
		$business_name      = $this->get_portal_customer_meta_value( $customer, array( 'business_name', 'business', 'company', 'company_name' ) );

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'My Services', 'ajforms' ); ?></h2>

			<?php if ( $show_current ) : ?>
			<h3><?php esc_html_e( 'Current Services', 'ajforms' ); ?></h3>
			<?php if ( empty( $subscriptions ) ) : ?>
				<p><?php esc_html_e( 'No subscription services are synced yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-services-list">
					<?php foreach ( $subscriptions as $subscription ) : ?>
						<?php $subscription_ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger ); ?>
						<div class="aj-portal-service-card">
							<h4><?php echo esc_html( $this->get_subscription_service_name( $subscription, $subscription_ledger_entry ) ); ?></h4>
							<div class="aj-portal-service-card-grid">
								<div><strong><?php esc_html_e( 'Business Name', 'ajforms' ); ?></strong><span><?php echo esc_html( $business_name ? $business_name : '-' ); ?></span></div>
								<div><strong><?php esc_html_e( 'Status', 'ajforms' ); ?></strong><span><?php echo esc_html( ucfirst( $subscription->status ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Service Period', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_service_period( $subscription, $subscription_ledger_entry ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_next_billing_date( $subscription, $subscription_ledger_entry ) ); ?></span></div>
								<div><strong><?php esc_html_e( 'Amount', 'ajforms' ); ?></strong><span><?php echo esc_html( $this->get_subscription_amount_label( $subscription ) ); ?></span></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ( $show_add ) : ?>
			<h3><?php esc_html_e( 'Add Services', 'ajforms' ); ?></h3>
			<?php if ( ! empty( $custom_request_products ) ) : ?>
				<div class="aj-portal-add-service-grid aj-portal-custom-request-grid">
					<?php foreach ( $custom_request_products as $product ) : ?>
						<?php
						$product_name = ! empty( $product->custom_label ) ? $product->custom_label : $product->name;
						$price_id     = sanitize_text_field( (string) $product->stripe_price_id );
						$request_title = ! empty( $product->custom_request_title ) ? $product->custom_request_title : sprintf( __( 'Need another %s?', 'ajforms' ), $product_name );
						$request_message = ! empty( $product->custom_request_message ) ? $product->custom_request_message : __( 'Request custom pricing and our team will review your account.', 'ajforms' );
						$request_button = ! empty( $product->custom_request_button_label ) ? $product->custom_request_button_label : __( 'Request Custom Pricing', 'ajforms' );
						$existing_request = $this->get_open_custom_service_request( $context['stripe_customer_id'], $price_id );
						?>
						<div class="aj-portal-add-service-card aj-portal-custom-request-card">
							<h4><?php echo esc_html( $request_title ); ?></h4>
							<p><?php echo esc_html( $request_message ); ?></p>
							<?php if ( $existing_request ) : ?>
								<div class="aj-portal-add-service-price"><?php esc_html_e( 'Under Review', 'ajforms' ); ?></div>
								<button type="button" class="button" disabled><?php esc_html_e( 'Request Submitted', 'ajforms' ); ?></button>
							<?php else : ?>
								<button
									type="button"
									class="button aj-portal-custom-service-request-button"
									data-price-id="<?php echo esc_attr( $price_id ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_portal_custom_request_' . $price_id ) ); ?>"
								><?php echo esc_html( $request_button ); ?></button>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( empty( $available_products ) ) : ?>
				<?php if ( empty( $custom_request_products ) ) : ?>
					<p><?php esc_html_e( 'No additional services are currently available for this account.', 'ajforms' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<div class="aj-portal-add-service-grid">
					<?php foreach ( $available_products as $product ) : ?>
						<?php
						$product_name        = ! empty( $product->custom_label ) ? $product->custom_label : $product->name;
						$product_description = ! empty( $product->description_override ) ? $product->description_override : $product->description;
						$price_id            = sanitize_text_field( (string) $product->stripe_price_id );
						?>
						<div class="aj-portal-add-service-card">
							<h4><?php echo esc_html( $product_name ); ?></h4>
							<?php if ( $product_description ) : ?>
								<p><?php echo esc_html( wp_trim_words( $product_description, 28 ) ); ?></p>
							<?php endif; ?>
							<div class="aj-portal-add-service-price"><?php echo esc_html( $this->get_portal_product_amount_label( $product ) ); ?></div>
							<button
								type="button"
								class="button aj-portal-add-service-button"
								data-price-id="<?php echo esc_attr( $price_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_portal_add_service_' . $price_id ) ); ?>"
							><?php esc_html_e( 'Add to My Services', 'ajforms' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p class="aj-portal-add-service-message" style="display:none;"></p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_customer_portal_billing_tab() {
		$context = $this->get_current_user_portal_billing_context();

		if ( '' === $context['stripe_customer_id'] || ! $context['customer'] ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Billing', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$ledger        = $context['ledger'];
		$upcoming      = $context['upcoming'];
		$subscriptions = $context['subscriptions'];

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Billing', 'ajforms' ); ?></h2>

			<h3><?php esc_html_e( 'Upcoming Payments', 'ajforms' ); ?></h3>
			<?php if ( empty( $upcoming ) ) : ?>
				<p><?php esc_html_e( 'No upcoming payment is currently scheduled.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-table-wrap">
					<table class="aj-portal-table">
						<thead><tr><th><?php esc_html_e( 'Service Name', 'ajforms' ); ?></th><th><?php esc_html_e( 'Next Billing Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $upcoming as $subscription ) : ?>
								<?php $subscription_ledger_entry = $this->get_subscription_ledger_entry( $subscription, $ledger ); ?>
								<tr>
									<td><?php echo esc_html( $this->get_subscription_service_name( $subscription, $subscription_ledger_entry ) ); ?></td>
									<td><?php echo esc_html( $this->get_subscription_next_billing_date( $subscription, $subscription_ledger_entry ) ); ?></td>
									<td><?php echo esc_html( $this->get_subscription_amount_label( $subscription ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Billing History', 'ajforms' ); ?></h3>
			<?php if ( empty( $ledger ) ) : ?>
				<p><?php esc_html_e( 'No Stripe invoices or charges are synced yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-table-wrap">
					<table class="aj-portal-table">
						<thead><tr><th><?php esc_html_e( 'Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Description', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th><th><?php esc_html_e( 'Invoice', 'ajforms' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $ledger as $entry ) : ?>
								<?php $entry_invoice_url = $this->get_ledger_metadata_value( $entry, 'invoice_pdf' ) ? $this->get_ledger_metadata_value( $entry, 'invoice_pdf' ) : $this->get_ledger_metadata_value( $entry, 'hosted_invoice_url' ); ?>
								<?php $entry_invoice_label = $this->get_ledger_metadata_value( $entry, 'invoice_number' ) ? $this->get_ledger_metadata_value( $entry, 'invoice_number' ) : __( 'Download', 'ajforms' ); ?>
								<tr>
									<td><?php echo esc_html( $entry->ledger_date ? $this->format_portal_date( $entry->ledger_date ) : '-' ); ?></td>
									<td><?php echo esc_html( $entry->description ); ?></td>
									<td><?php echo esc_html( 'admin_review_required' === $entry->status ? __( 'Under Review', 'ajforms' ) : ucwords( str_replace( '_', ' ', $entry->status ) ) ); ?></td>
									<td><?php echo esc_html( $this->format_portal_money( $entry->amount, $entry->currency ) ); ?></td>
									<td>
										<?php if ( $entry_invoice_url ) : ?>
											<a href="<?php echo esc_url( $entry_invoice_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry_invoice_label ); ?></a>
										<?php else : ?>
											<?php echo $this->get_portal_service_request_actions( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}


	private function get_current_user_portal_tasks() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return array();
		}

		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, COALESCE(ts.status, t.status) AS portal_status, ts.completed_at AS portal_completed_at, ts.updated_at AS portal_status_updated_at
				FROM {$this->get_portal_tasks_table()} t
				LEFT JOIN {$this->get_portal_task_statuses_table()} ts ON ts.task_id = t.id AND ts.stripe_customer_id = %s
				WHERE t.client_visible = 1
				AND (t.task_scope = 'global' OR t.stripe_customer_id = %s)
				ORDER BY FIELD(COALESCE(ts.status, t.status), 'open', 'waiting_on_client', 'in_progress', 'upcoming', 'completed', 'cancelled'), t.due_date IS NULL, t.due_date ASC, t.id DESC",
				$stripe_customer_id,
				$stripe_customer_id
			)
		);
	}

	private function get_open_portal_tasks_count( $tasks ) {
		$count = 0;
		foreach ( (array) $tasks as $task ) {
			$status = isset( $task->portal_status ) && '' !== (string) $task->portal_status ? sanitize_key( (string) $task->portal_status ) : ( isset( $task->status ) ? sanitize_key( (string) $task->status ) : '' );
			if ( ! in_array( $status, array( 'completed', 'cancelled', 'closed' ), true ) ) {
				$count++;
			}
		}

		return $count;
	}

	private function get_portal_task_comments_by_task_ids( $task_ids, $stripe_customer_id ) {
		$task_ids = array_values( array_filter( array_map( 'absint', (array) $task_ids ) ) );
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );

		if ( empty( $task_ids ) || '' === $stripe_customer_id ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $task_ids ), '%d' ) );
		$params       = array_merge( $task_ids, array( $stripe_customer_id ) );
		$comments     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_task_comments_table()} WHERE task_id IN ({$placeholders}) AND stripe_customer_id = %s ORDER BY created_at ASC, id ASC",
				$params
			)
		);

		$grouped = array();
		foreach ( $comments as $comment ) {
			$task_id = isset( $comment->task_id ) ? absint( $comment->task_id ) : 0;
			if ( ! isset( $grouped[ $task_id ] ) ) {
				$grouped[ $task_id ] = array();
			}
			$grouped[ $task_id ][] = $comment;
		}

		return $grouped;
	}

	private function user_can_access_portal_task( $task_id, $stripe_customer_id ) {
		global $wpdb;

		$task_id            = absint( $task_id );
		$stripe_customer_id = sanitize_text_field( (string) $stripe_customer_id );

		if ( ! $task_id || '' === $stripe_customer_id ) {
			return false;
		}

		$task = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_tasks_table()} WHERE id = %d AND client_visible = 1 AND (task_scope = 'global' OR stripe_customer_id = %s) LIMIT 1",
				$task_id,
				$stripe_customer_id
			)
		);

		return $task ? $task : false;
	}

	public function maybe_handle_portal_task_action() {
		if ( empty( $_POST['ajcore_portal_task_action'] ) || ! is_user_logged_in() ) {
			return;
		}

		$task_id = isset( $_POST['portal_task_id'] ) ? absint( wp_unslash( $_POST['portal_task_id'] ) ) : 0;
		$action  = sanitize_key( wp_unslash( $_POST['ajcore_portal_task_action'] ) );

		if ( ! $task_id || ! in_array( $action, array( 'add_comment', 'mark_complete' ), true ) ) {
			return;
		}

		check_admin_referer( 'ajcore_portal_task_action_' . $task_id, 'ajcore_portal_task_nonce' );

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$task               = $this->user_can_access_portal_task( $task_id, $stripe_customer_id );
		if ( ! $task ) {
			wp_safe_redirect( add_query_arg( 'portal_tab', 'tasks', $this->get_customer_portal_url() ) );
			exit;
		}

		global $wpdb;
		$user_id = get_current_user_id();
		$comment = isset( $_POST['portal_task_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['portal_task_comment'] ) ) : '';

		if ( 'mark_complete' === $action ) {
			$existing_status_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->get_portal_task_statuses_table()} WHERE task_id = %d AND stripe_customer_id = %s LIMIT 1",
					$task_id,
					$stripe_customer_id
				)
			);

			$status_data = array(
				'task_id'            => $task_id,
				'stripe_customer_id' => $stripe_customer_id,
				'status'             => 'completed',
				'completed_at'       => current_time( 'mysql' ),
				'updated_by'         => $user_id,
			);
			$status_formats = array( '%d', '%s', '%s', '%s', '%d' );

			if ( $existing_status_id ) {
				$wpdb->update( $this->get_portal_task_statuses_table(), $status_data, array( 'id' => absint( $existing_status_id ) ), $status_formats, array( '%d' ) );
			} else {
				$wpdb->insert( $this->get_portal_task_statuses_table(), $status_data, $status_formats );
			}

			$auto_note = __( 'Marked complete by customer.', 'ajforms' );
			$comment   = '' !== $comment ? $auto_note . "\n\n" . $comment : $auto_note;
		}

		if ( '' !== $comment ) {
			$wpdb->insert(
				$this->get_portal_task_comments_table(),
				array(
					'task_id'            => $task_id,
					'stripe_customer_id' => $stripe_customer_id,
					'comment'            => $comment,
					'is_client'          => 1,
					'created_by'         => $user_id,
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%d', '%d', '%s' )
			);
		}

		wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'tasks', 'task-updated' => '1' ), $this->get_customer_portal_url() ) );
		exit;
	}

	private function render_customer_portal_tasks_table( $tasks, $include_default_deadlines = true ) {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$task_ids           = wp_list_pluck( (array) $tasks, 'id' );
		$comments_by_task   = $this->get_portal_task_comments_by_task_ids( $task_ids, $stripe_customer_id );

		ob_start();
		?>
		<div class="aj-portal-table-wrap aj-portal-tasks-wrap">
			<table class="aj-portal-table aj-portal-tasks-table">
				<thead><tr><th><?php esc_html_e( 'Task', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Due Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Action Required', 'ajforms' ); ?></th><th><?php esc_html_e( 'Comments / Update', 'ajforms' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $tasks ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No open tasks are available yet.', 'ajforms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $tasks as $task ) : ?>
							<?php
							$task_id      = absint( $task->id );
							$status       = isset( $task->portal_status ) && '' !== (string) $task->portal_status ? sanitize_key( (string) $task->portal_status ) : sanitize_key( (string) $task->status );
							$is_complete  = in_array( $status, array( 'completed', 'cancelled', 'closed' ), true );
							$scope_label  = isset( $task->task_scope ) && 'global' === $task->task_scope ? __( 'All clients', 'ajforms' ) : __( 'Client-specific', 'ajforms' );
							$freq_label   = isset( $task->task_frequency ) && 'recurring' === $task->task_frequency ? __( 'Recurring', 'ajforms' ) : __( 'One-time', 'ajforms' );
							$comments     = isset( $comments_by_task[ $task_id ] ) ? $comments_by_task[ $task_id ] : array();
							?>
							<tr class="aj-portal-task-row is-<?php echo esc_attr( $status ); ?>">
								<td>
									<strong><?php echo esc_html( $task->title ); ?></strong>
									<div class="aj-portal-task-meta"><?php echo esc_html( $scope_label . ' · ' . $freq_label ); ?></div>
								</td>
								<td><span class="aj-portal-task-status aj-portal-task-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $status ) ) ); ?></span></td>
								<td><?php echo esc_html( ! empty( $task->due_date ) ? $this->format_portal_date( $task->due_date ) : '-' ); ?></td>
								<td><?php echo esc_html( ! empty( $task->action_required ) ? $task->action_required : '-' ); ?></td>
								<td>
									<?php if ( ! empty( $comments ) ) : ?>
										<div class="aj-portal-task-comments">
											<?php foreach ( $comments as $comment ) : ?>
												<div class="aj-portal-task-comment">
													<?php echo esc_html( $comment->comment ); ?>
													<span><?php echo esc_html( $this->format_portal_date( $comment->created_at ) ); ?></span>
												</div>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>

									<form class="aj-portal-task-form" method="post">
										<?php wp_nonce_field( 'ajcore_portal_task_action_' . $task_id, 'ajcore_portal_task_nonce' ); ?>
										<input type="hidden" name="portal_task_id" value="<?php echo esc_attr( $task_id ); ?>">
										<textarea name="portal_task_comment" rows="2" placeholder="<?php esc_attr_e( 'Add a comment for our team...', 'ajforms' ); ?>"></textarea>
										<div class="aj-portal-task-actions">
											<button type="submit" class="button aj-portal-task-comment-button" name="ajcore_portal_task_action" value="add_comment"><?php esc_html_e( 'Add Comment', 'ajforms' ); ?></button>
											<?php if ( ! $is_complete ) : ?>
												<button type="submit" class="button aj-portal-task-complete-button" name="ajcore_portal_task_action" value="mark_complete"><?php esc_html_e( 'Mark Complete', 'ajforms' ); ?></button>
											<?php endif; ?>
										</div>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}


	private function render_customer_portal_service_summary( $active_subscriptions, $ledger, $business_name ) {
		if ( empty( $active_subscriptions ) ) {
			return '<div class="aj-portal-account-summary"><h3>' . esc_html__( 'Account Summary', 'ajforms' ) . '</h3><p>' . esc_html__( 'No active services are synced to your portal yet.', 'ajforms' ) . '</p></div>';
		}

		$lines = array();
		foreach ( $active_subscriptions as $subscription ) {
			$ledger_entry   = $this->get_subscription_ledger_entry( $subscription, $ledger );
			$service_name   = $this->get_subscription_service_name( $subscription, $ledger_entry );
			$service_period = $this->get_subscription_service_period( $subscription, $ledger_entry );
			$entity_label    = $business_name ? $business_name : __( 'your business entity', 'ajforms' );

			$lines[] = sprintf(
				/* translators: 1: service name, 2: business/entity name, 3: service period */
				__( 'You currently have %1$s for %2$s with NC LLC Agents Inc from %3$s.', 'ajforms' ),
				$service_name,
				$entity_label,
				$service_period
			);
		}

		ob_start();
		?>
		<div class="aj-portal-account-summary">
			<h3><?php esc_html_e( 'Account Summary', 'ajforms' ); ?></h3>
			<?php foreach ( $lines as $line ) : ?>
				<p><?php echo esc_html( $line ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_customer_portal_tasks_tab() {
		$context = $this->get_current_user_portal_billing_context();
		$customer = $context['customer'];

		if ( '' === $context['stripe_customer_id'] || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Tasks', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$tasks = $this->get_current_user_portal_tasks();

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Tasks', 'ajforms' ); ?></h2>
			<p class="aj-portal-intro-text"><?php esc_html_e( 'Review action items and important compliance dates for your account.', 'ajforms' ); ?></p>
			<?php if ( isset( $_GET['task-updated'] ) ) : ?>
				<div class="aj-portal-add-service-message is-success"><?php esc_html_e( 'Task updated.', 'ajforms' ); ?></div>
			<?php endif; ?>
			<?php echo $this->render_customer_portal_tasks_table( $tasks, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function get_portal_service_request_actions( $entry ) {
		$metadata = $this->decode_portal_json( isset( $entry->metadata ) ? $entry->metadata : '' );
		$status   = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';

		if ( 'checkout_session' !== (string) $entry->source_type || ! in_array( $status, array( 'unpaid', 'open', 'cancelled' ), true ) ) {
			return '';
		}

		$actions = array();
		if ( in_array( $status, array( 'unpaid', 'open' ), true ) && ! empty( $metadata['checkout_url'] ) ) {
			$actions[] = '<a class="button aj-portal-action-button aj-portal-action-resume" href="' . esc_url( $metadata['checkout_url'] ) . '">' . esc_html__( 'Resume', 'ajforms' ) . '</a>';
		}

		$button_label = in_array( $status, array( 'unpaid', 'open' ), true ) ? __( 'Cancel', 'ajforms' ) : __( 'Remove', 'ajforms' );
		$remove_url   = wp_nonce_url(
			add_query_arg(
				array(
					'portal_tab'                    => 'billing',
					'ajcore_remove_service_request' => (int) $entry->id,
				),
				$this->get_customer_portal_url()
			),
			'ajcore_remove_service_request_' . (int) $entry->id
		);
		$confirm_text = esc_attr__( 'Remove this pending service request from your billing history?', 'ajforms' );
		$actions[]    = '<a class="button aj-portal-action-button aj-portal-action-cancel" href="' . esc_url( $remove_url ) . '" onclick="return window.confirm(\'' . $confirm_text . '\');">' . esc_html( $button_label ) . '</a>';

		return '<span class="aj-portal-inline-actions">' . implode( ' ', $actions ) . '</span>';
	}

	private function render_customer_portal_overview_tab() {
		$context = $this->get_current_user_portal_billing_context();
		$customer = $context['customer'];

		if ( '' === $context['stripe_customer_id'] || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Overview', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
		}

		$upcoming             = $context['upcoming'];
		$active_subscriptions = $context['active_subscriptions'];
		$files                = $this->get_current_user_portal_files();
		$tasks                = $this->get_current_user_portal_tasks();
		$display_name         = $customer->name ? $customer->name : $customer->email;
		$billing_url          = add_query_arg( 'portal_tab', 'billing', $this->get_customer_portal_url() );
		$file_library_url     = add_query_arg( 'portal_tab', 'file-library', $this->get_customer_portal_url() );
		$services_url         = add_query_arg( 'portal_tab', 'services', $this->get_customer_portal_url() );
		$tasks_url            = add_query_arg( 'portal_tab', 'tasks', $this->get_customer_portal_url() );
		$email_us_url         = home_url( '/email-us/' );
		$business_name        = $this->get_portal_customer_meta_value( $customer, array( 'business_name', 'business', 'company', 'company_name' ) );
		$text_message         = sprintf(
			__( 'Hi, I am an existing customer and my business name is: %1$s. My name is: %2$s. I need help with ', 'ajforms' ),
			$business_name ? $business_name : '-',
			$display_name ? $display_name : '-'
		);
		$text_url             = 'sms:+17043072135?body=' . rawurlencode( $text_message );

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php echo esc_html( sprintf( __( 'Welcome, %s', 'ajforms' ), $display_name ) ); ?></h2>

			<div class="aj-portal-summary-grid">
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $services_url ); ?>">
					<strong><?php esc_html_e( 'Active Services', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $active_subscriptions ) ) ); ?></span>
				</a>
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $billing_url ); ?>">
					<strong><?php esc_html_e( 'Upcoming Payments', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $upcoming ) ) ); ?></span>
				</a>
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $tasks_url ); ?>">
					<strong><?php esc_html_e( 'Open Tasks', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( $this->get_open_portal_tasks_count( $tasks ) ) ); ?></span>
				</a>
				<a class="aj-portal-summary-card aj-portal-summary-link" href="<?php echo esc_url( $file_library_url ); ?>">
					<strong><?php esc_html_e( 'Available Files', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $files ) ) ); ?></span>
				</a>
			</div>

			<?php echo $this->render_customer_portal_service_summary( $active_subscriptions, $context['ledger'], $business_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<h3 class="aj-portal-quick-actions-heading"><?php esc_html_e( 'Quick Actions', 'ajforms' ); ?></h3>
			<div class="aj-portal-quick-actions">
				<a class="button" href="<?php echo esc_url( $file_library_url ); ?>"><?php esc_html_e( 'Upload Document', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $billing_url ); ?>"><?php esc_html_e( 'View Billing', 'ajforms' ); ?></a>
				<span class="button disabled"><?php esc_html_e( 'Update Payment Method', 'ajforms' ); ?></span>
				<a class="button" href="<?php echo esc_url( $services_url ); ?>"><?php esc_html_e( 'Add Services', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $email_us_url ); ?>"><?php esc_html_e( 'Email Us', 'ajforms' ); ?></a>
				<a class="button" href="<?php echo esc_url( $text_url ); ?>"><?php esc_html_e( 'Text Us', 'ajforms' ); ?></a>
			</div>
		</section>
		<?php
		return ob_get_clean();
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

	private function get_portal_user_mappings_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_user_mappings';
	}

	private function get_portal_service_requests_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_service_requests';
	}

	private function get_current_user_portal_mapping() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		global $wpdb;

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.*
				FROM {$this->get_portal_user_mappings_table()} m
				INNER JOIN {$this->get_portal_stripe_customers_table()} c
					ON c.stripe_customer_id = m.stripe_customer_id
				WHERE m.user_id = %d
					AND c.enabled_portal = 1
				ORDER BY m.updated_at DESC, m.id DESC
				LIMIT 1",
				$user_id
			)
		);
	}

	private function get_current_user_stripe_customer_id() {
		$mapping = $this->get_current_user_portal_mapping();

		return $mapping && ! empty( $mapping->stripe_customer_id ) ? sanitize_text_field( $mapping->stripe_customer_id ) : '';
	}

	private function get_current_user_portal_customer() {
		$mapping = $this->get_current_user_portal_mapping();
		if ( ! $mapping || empty( $mapping->stripe_customer_id ) ) {
			return null;
		}

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s AND enabled_portal = 1",
				sanitize_text_field( $mapping->stripe_customer_id )
			)
		);
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

	private function current_user_can_access_portal_file( $file_id ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		global $wpdb;

		$user       = wp_get_current_user();
		$user_id    = get_current_user_id();
		$user_email = strtolower( (string) $user->user_email );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->get_portal_file_users_table()} WHERE file_id = %d AND (user_id = %d OR LOWER(user_email) = %s)",
				absint( $file_id ),
				$user_id,
				$user_email
			)
		);

		return (int) $count > 0;
	}

	private function get_current_user_portal_files() {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		global $wpdb;

		$user       = wp_get_current_user();
		$user_email = strtolower( (string) $user->user_email );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT f.* FROM {$this->get_portal_files_table()} f
				INNER JOIN {$this->get_portal_file_users_table()} fu ON fu.file_id = f.id
				WHERE fu.user_id = %d OR LOWER(fu.user_email) = %s
				ORDER BY f.category ASC, f.created_at DESC",
				get_current_user_id(),
				$user_email
			)
		);
	}

	public function render_customer_portal_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'show_title' => 'yes',
			),
			$atts,
			'aj_customer_portal'
		);

		if ( ! is_user_logged_in() ) {
			$login_url    = wp_login_url( get_permalink() );
			$register_url = get_option( 'users_can_register' ) ? wp_registration_url() : '';

			ob_start();
			?>
			<div class="aj-customer-portal aj-customer-portal-login">
				<p><?php esc_html_e( 'Please log in to view your files.', 'ajforms' ); ?></p>
				<p>
					<a class="button" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log In', 'ajforms' ); ?></a>
					<?php if ( $register_url ) : ?>
						<a class="button" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Register', 'ajforms' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<?php
			return ob_get_clean();
		}

		$portal_items = array_values(
			array_filter(
				$this->get_customer_portal_menu_items(),
				function ( $item ) {
					return ! empty( $item['enabled'] );
				}
			)
		);
		$active_tab = isset( $_GET['portal_tab'] ) ? sanitize_key( wp_unslash( $_GET['portal_tab'] ) ) : 'overview';
		$active_ids = wp_list_pluck( $portal_items, 'id' );
		if ( ! in_array( $active_tab, $active_ids, true ) ) {
			$active_tab = 'overview';
		}

		ob_start();
		?>
		<div class="ajcore-portal-shell">
			<style>
				.ajcore-portal-shell{
					--ajp-ink:#081225;
					--ajp-muted:#526173;
					--ajp-line:rgba(148,163,184,.24);
					--ajp-card:rgba(255,255,255,.84);
					--ajp-glass:rgba(255,255,255,.72);
					--ajp-primary:#3157ff;
					--ajp-primary-2:#7c3aed;
					--ajp-cyan:#06b6d4;
					--ajp-green:#10b981;
					--ajp-red:#ef4444;
					--ajp-shadow:0 30px 85px rgba(15,23,42,.10);
					--ajp-shadow-soft:0 18px 48px rgba(15,23,42,.075);
					position:relative;
					isolation:isolate;
					width:calc(100vw - 48px);
					max-width:none;
					margin:28px 0 0 calc(50% - 50vw + 24px);
					padding:0 0 72px;
					font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
					color:var(--ajp-ink);
				}
				.ajcore-portal-shell:before{
					content:"";
					position:absolute;
					z-index:-1;
					inset:-48px -28px auto;
					height:230px;
					background:
						radial-gradient(circle at 18% 22%,rgba(49,87,255,.18),transparent 30%),
						radial-gradient(circle at 82% 8%,rgba(124,58,237,.17),transparent 28%),
						radial-gradient(circle at 50% 64%,rgba(6,182,212,.11),transparent 35%);
					filter:blur(8px);
					pointer-events:none;
				}
				.ajcore-portal-shell *{box-sizing:border-box}
				.ajcore-portal-shell h1{margin:0 0 18px;padding:0;font-size:32px;line-height:1.08;letter-spacing:-.04em;color:var(--ajp-ink)}
				.ajcore-portal-shell h2{margin:0 0 24px;padding:0;font-size:clamp(34px,3.2vw,44px);line-height:.98;letter-spacing:-.06em;background:linear-gradient(135deg,#1d4ed8 0%,#3157ff 42%,#7c3aed 100%);-webkit-background-clip:text;background-clip:text;color:transparent;text-wrap:balance}
				.ajcore-portal-shell h3{margin:30px 0 14px;padding:0;font-size:23px;line-height:1.16;letter-spacing:-.04em;color:var(--ajp-ink)}
				.ajcore-portal-shell h3:first-of-type{margin-top:0}
				.ajcore-portal-shell p{color:#334155;line-height:1.65;font-size:16px}
				.ajcore-portal-shell a{color:#2563eb;text-decoration:none;font-weight:800}
				.ajcore-portal-shell a:hover{text-decoration:none;color:#1d4ed8}
				.ajcore-portal-shell .button,
				.ajcore-portal-shell button.button{
					appearance:none;
					display:inline-flex;
					align-items:center;
					justify-content:center;
					min-height:44px;
					padding:12px 19px;
					border:0;
					border-radius:999px;
					background:linear-gradient(135deg,#3157ff 0%,#6d3df2 100%);
					color:#fff!important;
					font-weight:900;
					letter-spacing:-.015em;
					text-decoration:none;
					cursor:pointer;
					box-shadow:0 18px 38px rgba(49,87,255,.24);
					transition:transform .18s ease,box-shadow .18s ease,filter .18s ease,opacity .18s ease;
				}
				.ajcore-portal-shell .button:hover,
				.ajcore-portal-shell button.button:hover{transform:translateY(-2px);box-shadow:0 24px 52px rgba(49,87,255,.28);filter:saturate(1.05)}
				.ajcore-portal-shell .button.disabled,
				.ajcore-portal-shell button.button:disabled{background:#e5e7eb!important;color:#94a3b8!important;box-shadow:none;cursor:not-allowed;transform:none;opacity:1}
				.ajcore-portal-shell .aj-customer-portal-tabs{
					display:flex;
					align-items:center;
					gap:8px;
					margin:0 0 26px;
					padding:8px;
					overflow-x:auto;
					-webkit-overflow-scrolling:touch;
					scrollbar-width:none;
					border:1px solid rgba(219,231,243,.9);
					border-radius:26px;
					background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(255,255,255,.78));
					box-shadow:var(--ajp-shadow-soft);
					backdrop-filter:blur(18px);
				}
				.ajcore-portal-shell .aj-customer-portal-tabs::-webkit-scrollbar{display:none}
				.ajcore-portal-shell .aj-customer-portal-tab{
					position:relative;
					flex:0 0 auto;
					display:inline-flex;
					align-items:center;
					justify-content:center;
					min-height:50px;
					padding:13px 18px;
					border-radius:19px;
					color:#475569;
					font-size:15px;
					font-weight:950;
					letter-spacing:-.02em;
					text-decoration:none;
					transition:background .18s ease,color .18s ease,transform .18s ease,box-shadow .18s ease;
				}
				.ajcore-portal-shell .aj-customer-portal-tab:hover{background:#eef4ff;color:#1d4ed8;transform:translateY(-1px)}
				.ajcore-portal-shell .aj-customer-portal-tab.is-active{background:linear-gradient(135deg,#3157ff 0%,#713df2 100%);color:#fff;box-shadow:0 16px 36px rgba(49,87,255,.26)}
				.ajcore-portal-shell .aj-customer-portal-panel{position:relative;margin:0;padding:0;min-height:560px;animation:ajp-fade-up .28s ease both}
				.ajcore-portal-shell .aj-customer-portal-panel>h2{margin:0 0 24px}
				@keyframes ajp-fade-up{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

				.ajcore-portal-shell .aj-portal-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin:0 0 24px;width:100%}
				.ajcore-portal-shell .aj-portal-summary-card{
					position:relative;
					overflow:hidden;
					display:flex;
					min-height:88px;
					flex-direction:column;
					justify-content:space-between;
					gap:14px;
					padding:16px 18px;
					border:1px solid rgba(219,231,243,.92);
					border-radius:26px;
					background:linear-gradient(180deg,rgba(255,255,255,.94),rgba(248,251,255,.88));
					box-shadow:0 22px 58px rgba(15,23,42,.075);
					backdrop-filter:blur(12px);
				}
				.ajcore-portal-shell .aj-portal-summary-card:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#3157ff,#7c3aed,#06b6d4)}
				.ajcore-portal-shell .aj-portal-summary-card:after{content:"";position:absolute;right:-32px;bottom:-44px;width:122px;height:122px;border-radius:999px;background:radial-gradient(circle,rgba(49,87,255,.10),transparent 68%)}
				.ajcore-portal-shell a.aj-portal-summary-card{text-decoration:none;color:inherit;transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}
				.ajcore-portal-shell a.aj-portal-summary-card:hover{border-color:#93c5fd;box-shadow:0 30px 70px rgba(37,99,235,.16);transform:translateY(-4px)}
				.ajcore-portal-shell .aj-portal-summary-card strong{position:relative;z-index:1;color:#475569;font-size:14px;font-weight:900}
				.ajcore-portal-shell .aj-portal-summary-card span{position:relative;z-index:1;font-size:30px;line-height:1;font-weight:950;letter-spacing:-.055em;color:var(--ajp-ink)}

				.ajcore-portal-shell .aj-portal-services-list{display:grid;gap:16px;margin:0 0 26px;width:100%;max-width:none}
				.ajcore-portal-shell .aj-portal-service-card{
					position:relative;
					overflow:hidden;
					border:1px solid rgba(219,231,243,.95);
					border-radius:30px;
					background:radial-gradient(circle at 100% 0%,rgba(124,58,237,.14),transparent 28%),linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);
					padding:22px;
					box-shadow:0 22px 54px rgba(15,23,42,.075);
				}
				.ajcore-portal-shell .aj-portal-service-card:before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-portal-service-card h4{margin:0 0 18px;font-size:23px;line-height:1.14;letter-spacing:-.045em;color:#111827;text-wrap:balance}
				.ajcore-portal-shell .aj-portal-service-card-grid{display:grid;grid-template-columns:minmax(260px,1.05fr) minmax(100px,.45fr) minmax(160px,.68fr) minmax(150px,.58fr) minmax(120px,.45fr);gap:18px;align-items:start}
				.ajcore-portal-shell .aj-portal-service-card-grid div{min-width:0}
				.ajcore-portal-shell .aj-portal-service-card-grid strong{display:block;font-size:12px;color:#64748b;margin-bottom:8px;text-transform:uppercase;letter-spacing:.075em;font-weight:950}
				.ajcore-portal-shell .aj-portal-service-card-grid span{display:block;color:#0f172a;font-weight:900;font-size:16px;line-height:1.46;overflow-wrap:anywhere}

				.ajcore-portal-shell .aj-portal-add-service-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin:0 0 20px;width:100%;max-width:none}
				.ajcore-portal-shell .aj-portal-add-service-card{
					position:relative;
					overflow:hidden;
					min-height:190px;
					display:flex;
					flex-direction:column;
					gap:16px;
					border:1px solid rgba(219,231,243,.95);
					border-radius:28px;
					padding:22px;
					background:linear-gradient(180deg,rgba(255,255,255,.96) 0%,rgba(248,251,255,.92) 100%);
					box-shadow:0 26px 66px rgba(15,23,42,.07);
					transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;
				}
				.ajcore-portal-shell .aj-portal-add-service-card:after{content:"";position:absolute;right:-64px;top:-76px;width:178px;height:178px;border-radius:999px;background:radial-gradient(circle,rgba(124,58,237,.13),transparent 70%)}
				.ajcore-portal-shell .aj-portal-add-service-card:hover{transform:translateY(-5px);border-color:#bfdbfe;box-shadow:0 32px 72px rgba(37,99,235,.14)}
				.ajcore-portal-shell .aj-portal-add-service-card h4{position:relative;z-index:1;margin:0;font-size:22px;line-height:1.15;letter-spacing:-.04em;color:#111827;text-wrap:balance}
				.ajcore-portal-shell .aj-portal-add-service-card p{position:relative;z-index:1;margin:0;color:#475569;line-height:1.6;font-size:15px}
				.ajcore-portal-shell .aj-portal-add-service-price{position:relative;z-index:1;margin-top:auto;font-weight:950;color:#111827;font-size:19px;letter-spacing:-.025em}
				.ajcore-portal-shell .aj-portal-add-service-card .button{position:relative;z-index:1;align-self:flex-start}
				.ajcore-portal-shell .aj-portal-add-service-message{border:1px solid #dbe7f3;border-radius:18px;padding:15px 16px;background:#fff;color:#1f2937;box-shadow:0 10px 24px rgba(15,23,42,.05)}
				.ajcore-portal-shell .aj-portal-add-service-message.is-error{border-color:#fecaca;color:#b91c1c;background:#fff7f7}
				.ajcore-portal-shell .aj-portal-add-service-message.is-success{border-color:#bbf7d0;color:#166534;background:#f0fdf4}

				.ajcore-portal-shell .aj-portal-table-wrap{overflow:auto;margin:0 0 28px;border-radius:22px;border:1px solid rgba(219,231,243,.95);background:rgba(255,255,255,.88);box-shadow:0 18px 46px rgba(15,23,42,.07);backdrop-filter:blur(12px);width:100%;max-width:none}
				.ajcore-portal-shell .aj-portal-table{width:100%;border-collapse:separate;border-spacing:0;background:transparent;border:0;font-size:15px;min-width:760px}
				.ajcore-portal-shell .aj-portal-table th,.ajcore-portal-shell .aj-portal-table td{padding:14px 18px;border-bottom:1px solid #e8eef6;text-align:left;vertical-align:top}
				.ajcore-portal-shell .aj-portal-table tr:last-child td{border-bottom:0}
				.ajcore-portal-shell .aj-portal-table th{font-size:13px;font-weight:950;color:#475569;background:#f8fbff;text-transform:uppercase;letter-spacing:.065em}
				.ajcore-portal-shell .aj-portal-table td{color:#0f172a;line-height:1.55}
				.ajcore-portal-shell .aj-portal-table tbody tr{transition:background .16s ease}
				.ajcore-portal-shell .aj-portal-table tbody tr:hover{background:rgba(248,251,255,.72)}
				.ajcore-portal-shell .aj-portal-task-meta{margin-top:6px;color:#64748b;font-size:12px;font-weight:850;text-transform:uppercase;letter-spacing:.055em}
				.ajcore-portal-shell .aj-portal-task-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:950;background:#eff6ff;color:#1d4ed8}
				.ajcore-portal-shell .aj-portal-task-status-completed{background:#dcfce7;color:#166534}
				.ajcore-portal-shell .aj-portal-task-status-cancelled,.ajcore-portal-shell .aj-portal-task-status-closed{background:#fee2e2;color:#991b1b}
				.ajcore-portal-shell .aj-portal-task-comments{display:grid;gap:8px;margin:0 0 10px}
				.ajcore-portal-shell .aj-portal-task-comment{border:1px solid #e8eef6;border-radius:14px;background:#fff;padding:10px 12px;font-size:13px;line-height:1.45;color:#1f2937}
				.ajcore-portal-shell .aj-portal-task-comment span{display:block;margin-top:6px;color:#64748b;font-size:11px;font-weight:800}
				.ajcore-portal-shell .aj-portal-task-form{display:grid;gap:9px;min-width:260px}
				.ajcore-portal-shell .aj-portal-task-form textarea{width:100%;border:1px solid #dbe7f3;border-radius:14px;padding:10px 12px;background:#fff;min-height:72px;font:inherit;color:#0f172a}
				.ajcore-portal-shell .aj-portal-task-actions{display:flex;gap:8px;flex-wrap:wrap}
				.ajcore-portal-shell .aj-portal-task-comment-button{min-height:38px;padding:9px 15px;font-size:13px;background:linear-gradient(135deg,#2563eb 0%,#4f46e5 100%)}
				.ajcore-portal-shell .aj-portal-task-complete-button{min-height:38px;padding:9px 15px;font-size:13px;background:linear-gradient(135deg,#16a34a 0%,#10b981 100%)}

				.ajcore-portal-shell .aj-portal-profile-block{border:1px solid rgba(219,231,243,.95);border-radius:28px;background:radial-gradient(circle at 100% 0%,rgba(37,99,235,.12),transparent 34%),linear-gradient(180deg,#fff 0%,#f8fbff 100%);padding:30px;width:min(860px,100%);box-shadow:0 22px 54px rgba(15,23,42,.08)}
				.ajcore-portal-shell .aj-portal-profile-main{font-size:clamp(30px,4vw,42px);line-height:1.02;font-weight:950;color:#111827;margin:0 0 20px;letter-spacing:-.06em}
				.ajcore-portal-shell .aj-portal-profile-details{display:grid;gap:12px;color:#1f2937;font-size:17px;line-height:1.5;margin:0 0 26px}
				.ajcore-portal-shell .aj-portal-profile-actions{display:flex;gap:12px;flex-wrap:wrap}

				.ajcore-portal-shell .aj-customer-file-list{overflow:hidden;border:1px solid rgba(219,231,243,.95);border-radius:28px;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:var(--ajp-shadow);position:relative}
				.ajcore-portal-shell .aj-customer-file-list:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-customer-file-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:center;padding:22px 26px;border-top:1px solid #e8eef6}
				.ajcore-portal-shell .aj-customer-file-row:first-child{border-top:0;padding-top:28px}
				.ajcore-portal-shell .aj-customer-file-row:hover{background:rgba(248,251,255,.9)}
				.ajcore-portal-shell .aj-customer-file-title{font-size:clamp(18px,1.5vw,24px);line-height:1.15;font-weight:950;letter-spacing:-.04em;color:#111827;overflow-wrap:anywhere}
				.ajcore-portal-shell .aj-customer-file-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;color:#64748b;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.065em}
				.ajcore-portal-shell .aj-customer-file-meta span{display:inline-flex;align-items:center;border:1px solid #dbe7f3;background:#f8fbff;border-radius:999px;padding:5px 9px}
				.ajcore-portal-shell .aj-customer-file-main p{margin:10px 0 0;color:#52616f}
				.ajcore-portal-shell .aj-customer-file-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px}

				.ajcore-portal-shell .aj-portal-quick-actions{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 18px}
				.ajcore-portal-shell .aj-portal-inline-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
				.ajcore-portal-shell .aj-portal-action-button{min-height:38px;padding:10px 17px;font-size:14px;box-shadow:none}
				.ajcore-portal-shell .aj-portal-action-resume{min-height:48px;padding:13px 24px;background:linear-gradient(135deg,#16a34a 0%,#10b981 100%)!important;color:#fff!important;box-shadow:0 18px 36px rgba(16,185,129,.24)!important}
				.ajcore-portal-shell .aj-portal-action-cancel{min-height:38px;padding:9px 16px;background:#fee2e2!important;color:#991b1b!important;border:1px solid #fecaca!important;box-shadow:none!important}

				.ajcore-portal-shell .aj-portal-empty-state{position:relative;overflow:hidden;border:1px solid rgba(219,231,243,.95);border-radius:28px;padding:30px;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);box-shadow:0 24px 64px rgba(15,23,42,.075);width:min(900px,100%)}
				.ajcore-portal-shell .aj-portal-empty-state:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-portal-empty-state strong{display:block;margin:0 0 8px;font-size:22px;letter-spacing:-.035em;color:#111827}
				.ajcore-portal-shell .aj-portal-empty-state p{margin:0;color:#526173}
				.ajcore-portal-shell .aj-portal-notice{margin:0 0 18px;padding:14px 16px;border-radius:18px;font-weight:800;border:1px solid rgba(219,231,243,.95);background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.045)}
				.ajcore-portal-shell .aj-portal-notice.is-success{color:#166534;background:#ecfdf5;border-color:#bbf7d0}
				.ajcore-portal-shell .aj-portal-notice.is-error{color:#991b1b;background:#fef2f2;border-color:#fecaca}
				.ajcore-portal-shell .aj-portal-upload-drawer{margin:0 0 22px}
				.ajcore-portal-shell .aj-portal-upload-drawer[open]{margin-bottom:24px}
				.ajcore-portal-shell .aj-portal-upload-toggle{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:13px 22px;border-radius:999px;background:linear-gradient(135deg,#2563eb 0%,#7c3aed 100%);color:#fff;font-weight:950;cursor:pointer;box-shadow:0 18px 38px rgba(79,70,229,.22);list-style:none}
				.ajcore-portal-shell .aj-portal-upload-toggle::-webkit-details-marker{display:none}
				.ajcore-portal-shell .aj-portal-upload-card{display:grid;grid-template-columns:minmax(280px,.9fr) minmax(360px,1.1fr);gap:22px;align-items:end;margin:18px 0 0;padding:26px;border:1px solid rgba(219,231,243,.95);border-radius:28px;background:linear-gradient(135deg,rgba(255,255,255,.94) 0%,rgba(248,251,255,.92) 100%);box-shadow:var(--ajp-shadow-soft);position:relative;overflow:hidden}
				.ajcore-portal-shell .aj-portal-upload-card:before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed)}
				.ajcore-portal-shell .aj-portal-upload-card h3{margin:0 0 8px;font-size:24px;line-height:1.1;letter-spacing:-.04em}
				.ajcore-portal-shell .aj-portal-upload-card p{margin:0;color:#526173;line-height:1.55}
				.ajcore-portal-shell .aj-portal-upload-form{display:grid;grid-template-columns:1fr;gap:12px}
				.ajcore-portal-shell .aj-portal-upload-form input[type="text"],.ajcore-portal-shell .aj-portal-upload-form input[type="file"]{width:100%;border:1px solid #dbe7f3;border-radius:16px;padding:12px 14px;background:#fff;color:#0f172a;font-weight:700}

				.ajcore-portal-shell .aj-portal-tab-intro{margin:-8px 0 24px;color:#475569;font-size:clamp(16px,1.3vw,19px);line-height:1.7;max-width:880px}
				.ajcore-portal-shell .aj-portal-account-summary{margin:30px 0 0;padding:28px 32px;border:1px solid rgba(191,219,254,.9);border-radius:28px;background:linear-gradient(135deg,rgba(255,255,255,.96),rgba(239,246,255,.72));box-shadow:0 22px 60px rgba(15,23,42,.07)}
				.ajcore-portal-shell .aj-portal-account-summary h3{margin:0 0 12px;color:#0f172a;font-size:clamp(22px,1.8vw,30px)}
				.ajcore-portal-shell .aj-portal-account-summary p{margin:10px 0 0;color:#172033;font-size:clamp(16px,1.25vw,19px);line-height:1.75}


				@media (min-width:1280px){
					.ajcore-portal-shell .aj-customer-portal-tabs{width:100%;max-width:none;margin-left:0;margin-right:0}
					.ajcore-portal-shell .aj-customer-portal-panel{width:100%;max-width:none;margin-left:0;margin-right:0}
					.ajcore-portal-shell .aj-portal-summary-grid{max-width:none}
				}
				@media (min-width:1536px){
					.ajcore-portal-shell{width:calc(100vw - 64px);margin-left:calc(50% - 50vw + 32px)}
				}
				.ajcore-portal-shell .aj-customer-portal-panel>p:only-child,
				.ajcore-portal-shell .aj-customer-portal-panel>p:nth-child(2):last-child{
					position:relative;
					overflow:hidden;
					width:min(900px,100%);
					max-width:none;
					margin:0;
					padding:30px;
					border:1px solid rgba(219,231,243,.95);
					border-radius:28px;
					background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);
					box-shadow:0 24px 64px rgba(15,23,42,.075);
				}
				.ajcore-portal-shell .aj-customer-portal-panel>p:only-child:before,
				.ajcore-portal-shell .aj-customer-portal-panel>p:nth-child(2):last-child:before{
					content:"";
					position:absolute;
					inset:0 0 auto;
					height:5px;
					background:linear-gradient(90deg,#06b6d4,#3157ff,#7c3aed);
				}

				@media (max-width:1050px){
					.ajcore-portal-shell .aj-portal-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
					.ajcore-portal-shell .aj-portal-service-card-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
				}
				@media (max-width:760px){
					.ajcore-portal-shell{width:auto;max-width:none;margin:18px auto 0;padding:0 14px 36px}
					.ajcore-portal-shell:before{inset:-44px -20px auto;height:260px}
					.ajcore-portal-shell h2{font-size:34px;margin-bottom:20px}
					.ajcore-portal-shell h3{font-size:22px;margin:28px 0 14px}
					.ajcore-portal-shell .aj-customer-portal-tabs{
						position:sticky;
						top:8px;
						z-index:20;
						display:grid;
						grid-template-columns:repeat(2,minmax(0,1fr));
						gap:8px;
						margin:0 -2px 26px;
						border-radius:24px;
						padding:8px;
						overflow:visible;
					}
					.ajcore-portal-shell .aj-customer-portal-tab{
						width:100%;
						min-height:44px;
						padding:10px 10px;
						font-size:14px;
						border-radius:17px;
						white-space:normal;
						text-align:center;
					}
					.ajcore-portal-shell .aj-customer-portal-tab:last-child:nth-child(odd){grid-column:1 / -1}
					.ajcore-portal-shell .aj-portal-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
					.ajcore-portal-shell .aj-portal-summary-card{min-height:104px;border-radius:22px;padding:20px 18px}
					.ajcore-portal-shell .aj-portal-summary-card span{font-size:30px}
					.ajcore-portal-shell .aj-portal-service-card,.ajcore-portal-shell .aj-portal-add-service-card,.ajcore-portal-shell .aj-portal-profile-block{border-radius:24px;padding:22px}
					.ajcore-portal-shell .aj-portal-service-card-grid{grid-template-columns:1fr;gap:16px}
					.ajcore-portal-shell .aj-portal-add-service-grid{grid-template-columns:1fr}
					.ajcore-portal-shell .aj-portal-table-wrap{border-radius:24px}
					.ajcore-portal-shell .aj-portal-table{min-width:0;font-size:15px}
					.ajcore-portal-shell .aj-portal-table thead{display:none}
					.ajcore-portal-shell .aj-portal-table tbody,.ajcore-portal-shell .aj-portal-table tr,.ajcore-portal-shell .aj-portal-table td{display:block;width:100%}
					.ajcore-portal-shell .aj-portal-table tr{border-bottom:1px solid #e8eef6;padding:14px 0}
					.ajcore-portal-shell .aj-portal-table tr:last-child{border-bottom:0}
					.ajcore-portal-shell .aj-portal-table td{border:0;padding:8px 18px}
					.ajcore-portal-shell .aj-portal-table td:first-child{font-weight:900;color:#0f172a}
					.ajcore-portal-shell .aj-portal-upload-card{grid-template-columns:1fr;padding:22px;border-radius:24px}
					.ajcore-portal-shell .aj-customer-file-row{grid-template-columns:1fr;gap:14px;padding:18px}
					.ajcore-portal-shell .aj-customer-file-actions{justify-content:flex-start}
					.ajcore-portal-shell .aj-portal-quick-actions-heading,.ajcore-portal-shell .aj-portal-quick-actions{display:none}
				}
				@media (max-width:380px){
					.ajcore-portal-shell .aj-customer-portal-tabs{grid-template-columns:1fr}
					.ajcore-portal-shell .aj-customer-portal-tab:last-child:nth-child(odd){grid-column:auto}
				}
			</style>
			<?php if ( 'yes' === $atts['show_title'] ) : ?>
				<h1><?php esc_html_e( 'Client Portal', 'ajforms' ); ?></h1>
			<?php endif; ?>
			<nav class="aj-customer-portal-tabs" aria-label="<?php esc_attr_e( 'Client Portal', 'ajforms' ); ?>">
				<?php foreach ( $portal_items as $item ) : ?>
					<?php
					$is_active = $active_tab === $item['id'];
					$item_url  = 'custom' === $item['type'] && ! empty( $item['url'] )
						? $item['url']
						: add_query_arg( 'portal_tab', $item['id'], $this->get_customer_portal_url() );
					?>
					<a class="aj-customer-portal-tab <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $item_url ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'overview' === $active_tab ) {
				echo $this->render_customer_portal_overview_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'services' === $active_tab ) {
				echo $this->render_customer_portal_services_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'tasks' === $active_tab ) {
				echo $this->render_customer_portal_tasks_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'billing' === $active_tab ) {
				echo $this->render_customer_portal_billing_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'file-library' === $active_tab ) {
				echo $this->render_customer_portal_file_library_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'profile' === $active_tab ) {
				echo $this->render_customer_portal_profile_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<script>
			(function() {
				const shell = document.currentScript.closest('.ajcore-portal-shell');
				if (!shell || shell.dataset.ajcorePortalReady) {
					return;
				}
				shell.dataset.ajcorePortalReady = '1';

				function parseJsonResponse(response) {
					return response.text().then(function(text) {
						try {
							return JSON.parse(text);
						} catch (error) {
							throw new Error('<?php echo esc_js( __( 'The server returned an invalid response.', 'ajforms' ) ); ?>');
						}
					});
				}

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-add-service-button');
					if (!button || button.disabled) {
						return;
					}

					const message = shell.querySelector('.aj-portal-add-service-message');
					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Loading...', 'ajforms' ) ); ?>';

					if (message) {
						message.textContent = '';
						message.className = 'aj-portal-add-service-message';
						message.style.display = 'none';
					}

					const formData = new FormData();
					formData.append('action', 'ajcore_create_checkout_session');
					formData.append('portal_add_service', '1');
					formData.append('price_id', button.dataset.priceId || '');
					formData.append('nonce', button.dataset.nonce || '');
					formData.append('current_url', window.location.href);

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success || !payload.data || !payload.data.url) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to start checkout.', 'ajforms' ) ); ?>');
							}
							window.location.href = payload.data.url;
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							if (message) {
								message.textContent = error.message || '<?php echo esc_js( __( 'Unable to start checkout.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-error';
								message.style.display = 'block';
							} else {
								window.alert(error.message || '<?php echo esc_js( __( 'Unable to start checkout.', 'ajforms' ) ); ?>');
							}
						});
				});


				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-custom-service-request-button');
					if (!button || button.disabled) {
						return;
					}

					const message = shell.querySelector('.aj-portal-add-service-message');
					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Submitting...', 'ajforms' ) ); ?>';

					if (message) {
						message.textContent = '';
						message.className = 'aj-portal-add-service-message';
						message.style.display = 'none';
					}

					const formData = new FormData();
					formData.append('action', 'ajcore_create_custom_service_request');
					formData.append('price_id', button.dataset.priceId || '');
					formData.append('nonce', button.dataset.nonce || '');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
							if (message) {
								message.textContent = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Your request was submitted and is now under review.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-success';
								message.style.display = 'block';
							}
							button.textContent = '<?php echo esc_js( __( 'Request Submitted', 'ajforms' ) ); ?>';
							setTimeout(function() { window.location.reload(); }, 800);
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							if (message) {
								message.textContent = error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-error';
								message.style.display = 'block';
							} else {
								window.alert(error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
						});
				});

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-cancel-service-request');
					if (!button || button.disabled) {
						return;
					}
					if (!window.confirm('<?php echo esc_js( __( 'Cancel this pending service request?', 'ajforms' ) ); ?>')) {
						return;
					}

					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Cancelling...', 'ajforms' ) ); ?>';

					const formData = new FormData();
					formData.append('action', 'ajcore_cancel_portal_service_request');
					formData.append('ledger_id', button.dataset.ledgerId || '');
					formData.append('nonce', button.dataset.nonce || '');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
							}
							window.location.reload();
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							window.alert(error.message || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
						});
				});
			})();
			</script>
		</div>
		<?php
		return ob_get_clean();
	}

	public function maybe_handle_portal_service_request_remove() {
		if ( empty( $_GET['ajcore_remove_service_request'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->get_customer_portal_url() ) );
			exit;
		}

		$ledger_id = absint( wp_unslash( $_GET['ajcore_remove_service_request'] ) );
		if ( ! $ledger_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ajcore_remove_service_request_' . $ledger_id ) ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-invalid' ), $this->get_customer_portal_url() ) );
			exit;
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-unlinked' ), $this->get_customer_portal_url() ) );
			exit;
		}

		global $wpdb;
		$table = $this->get_portal_ledger_table();
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND stripe_customer_id = %s LIMIT 1",
				$ledger_id,
				$stripe_customer_id
			)
		);

		if ( ! $entry || ! in_array( (string) $entry->source_type, array( 'checkout_session', 'custom_service_request' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-not-found' ), $this->get_customer_portal_url() ) );
			exit;
		}

		$status = isset( $entry->status ) ? sanitize_key( (string) $entry->status ) : '';
		if ( ! in_array( $status, array( 'unpaid', 'open', 'cancelled', 'admin_review_required' ), true ) ) {
			wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => 'remove-not-allowed' ), $this->get_customer_portal_url() ) );
			exit;
		}

		$deleted = $wpdb->delete(
			$table,
			array(
				'id'                 => $ledger_id,
				'stripe_customer_id' => $stripe_customer_id,
				'source_type'        => (string) $entry->source_type,
			),
			array( '%d', '%s', '%s' )
		);

		$notice = false === $deleted ? 'remove-error' : 'removed';
		wp_safe_redirect( add_query_arg( array( 'portal_tab' => 'billing', 'portal_notice' => $notice ), $this->get_customer_portal_url() ) );
		exit;
	}

	public function maybe_handle_portal_file_upload() {
		if ( empty( $_POST['ajcore_portal_file_upload'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$portal_url = add_query_arg( 'portal_tab', 'file-library', $this->get_customer_portal_url() );

		if ( ! isset( $_POST['ajcore_portal_file_upload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajcore_portal_file_upload_nonce'] ) ), 'ajcore_portal_file_upload' ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-invalid', $portal_url ) );
			exit;
		}

		if ( empty( $_FILES['portal_file_upload'] ) || empty( $_FILES['portal_file_upload']['name'] ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$file = $_FILES['portal_file_upload'];
		$max_size = 20 * MB_IN_BYTES;
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$allowed_mimes = array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'heic' => 'image/heic',
			'heif' => 'image/heif',
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $allowed_mimes,
			)
		);

		if ( empty( $uploaded['file'] ) || ! empty( $uploaded['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$file_path = $uploaded['file'];
		$file_type = wp_check_filetype( basename( $file_path ), $allowed_mimes );
		if ( empty( $file_type['type'] ) ) {
			@unlink( $file_path );
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$title = isset( $_POST['portal_file_title'] ) ? sanitize_text_field( wp_unslash( $_POST['portal_file_title'] ) ) : '';
		if ( '' === $title ) {
			$title = preg_replace( '/\.[^.]+$/', '', basename( $file_path ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'guid'           => isset( $uploaded['url'] ) ? esc_url_raw( $uploaded['url'] ) : '',
				'post_mime_type' => $file_type['type'],
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			@unlink( $file_path );
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $file_path );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( (int) $attachment_id, $metadata );
		}

		global $wpdb;
		$user = wp_get_current_user();
		$user_email = strtolower( sanitize_email( (string) $user->user_email ) );
		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$this->get_portal_files_table(),
			array(
				'attachment_id' => (int) $attachment_id,
				'title'         => $title,
				'description'   => sprintf( __( 'Uploaded by %s from the Client Portal.', 'ajforms' ), $user_email ),
				'category'      => __( 'Client Upload', 'ajforms' ),
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_delete_attachment( (int) $attachment_id, true );
			wp_safe_redirect( add_query_arg( 'portal_notice', 'file-upload-error', $portal_url ) );
			exit;
		}

		$file_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$this->get_portal_file_users_table(),
			array(
				'file_id'    => $file_id,
				'user_id'    => get_current_user_id(),
				'user_email' => $user_email,
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		wp_safe_redirect( add_query_arg( 'portal_notice', 'file-uploaded', $portal_url ) );
		exit;
	}

	public function maybe_handle_portal_file_download() {
		if ( empty( $_GET['aj_portal_download'] ) ) {
			return;
		}

		$file_id = absint( $_GET['aj_portal_download'] );

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'aj_portal_download_' . $file_id ) ) {
			wp_die( esc_html__( 'Invalid download link.', 'ajforms' ), '', array( 'response' => 403 ) );
		}

		if ( ! $this->current_user_can_access_portal_file( $file_id ) ) {
			wp_die( esc_html__( 'You do not have permission to download this file.', 'ajforms' ), '', array( 'response' => 403 ) );
		}

		$file = $this->get_portal_file_record( $file_id );
		if ( ! $file ) {
			wp_die( esc_html__( 'File not found.', 'ajforms' ), '', array( 'response' => 404 ) );
		}

		$file_path = get_attached_file( (int) $file->attachment_id );
		$real_path = $file_path ? realpath( $file_path ) : false;
		$uploads   = wp_get_upload_dir();
		$uploads_base = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;

		if ( ! $real_path || ! $uploads_base || 0 !== strpos( $real_path, trailingslashit( $uploads_base ) ) || ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			wp_die( esc_html__( 'File is not available.', 'ajforms' ), '', array( 'response' => 404 ) );
		}

		$mime_type = get_post_mime_type( (int) $file->attachment_id );
		if ( ! $mime_type ) {
			$mime_type = 'application/octet-stream';
		}

		$download_name = basename( $real_path );

		nocache_headers();
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
		header( 'Content-Length: ' . filesize( $real_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $real_path );
		exit;
	}

	private function get_default_form_settings() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		return array(
			'submit_text'           => 'Submit',
			'notifications_enabled' => isset( $plugin_settings['default_notifications_enabled'] ) ? '1' === (string) $plugin_settings['default_notifications_enabled'] : true,
			'notification_email'    => isset( $plugin_settings['default_notification_email'] ) ? $plugin_settings['default_notification_email'] : get_option( 'admin_email' ),
			'notification_subject'  => isset( $plugin_settings['default_notification_subject'] ) ? $plugin_settings['default_notification_subject'] : 'New submission for {form_title}',
			'notification_body'     => "{submission_table}{submission_details_table}",
			'notification_from_name' => isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ),
			'notification_from_email' => '',
			'notification_reply_to' => '',
			'button_alignment'      => 'left',
			'form_description'      => '',
			'success_message'       => isset( $plugin_settings['default_success_message'] ) ? $plugin_settings['default_success_message'] : 'Form submitted successfully.',
			'confirmation_mode'     => 'default',
			'confirmation_type'     => 'message',
			'redirect_url'          => '',
			'confirmation_rules'    => array(),
			'use_label_placeholders' => false,
			'custom_css'            => '',
			'asana_task_enabled'    => false,
			'asana_task_name'       => 'New form submission: {form_title}',
			'asana_task_notes'      => "Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}",
			'asana_project_gid'     => isset( $plugin_settings['asana_project_gid'] ) ? $plugin_settings['asana_project_gid'] : '',
			'asana_assignee_gid'    => '',
			'asana_due_date'        => 'today',
			'stripe_enabled'        => false,
			'stripe_amount'         => '',
			'stripe_currency'       => 'usd',
			'stripe_description'    => 'Payment for {form_title}',
			'form_theme'            => 'clean',
			'background_mode'       => 'solid',
			'background_color'      => '#ffffff',
			'background_gradient_start' => '#ffffff',
			'background_gradient_end'   => '#f3f7fb',
			'primary_color'         => '#0f7ac6',
			'text_color'            => '#1f2937',
			'input_background'      => '#ffffff',
			'input_border_color'    => '#d7dce3',
			'border_radius'         => 16,
		);
	}

	private function is_honeypot_enabled() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		return ! empty( $plugin_settings['honeypot_enabled'] ) && '1' === (string) $plugin_settings['honeypot_enabled'];
	}

	private function get_spam_provider_config() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$provider        = ! empty( $plugin_settings['spam_challenge_provider'] ) ? sanitize_key( $plugin_settings['spam_challenge_provider'] ) : '';

		$providers = array(
			'recaptcha' => array(
				'provider'         => 'recaptcha',
				'site_key'         => ! empty( $plugin_settings['recaptcha_site_key'] ) ? sanitize_text_field( $plugin_settings['recaptcha_site_key'] ) : '',
				'secret_key'       => ! empty( $plugin_settings['recaptcha_secret_key'] ) ? sanitize_text_field( $plugin_settings['recaptcha_secret_key'] ) : '',
				'script_url'       => 'https://www.google.com/recaptcha/api.js',
				'token_field'      => 'g-recaptcha-response',
				'container_class'  => 'g-recaptcha',
				'verify_endpoint'  => 'https://www.google.com/recaptcha/api/siteverify',
			),
			'hcaptcha' => array(
				'provider'         => 'hcaptcha',
				'site_key'         => ! empty( $plugin_settings['hcaptcha_site_key'] ) ? sanitize_text_field( $plugin_settings['hcaptcha_site_key'] ) : '',
				'secret_key'       => ! empty( $plugin_settings['hcaptcha_secret_key'] ) ? sanitize_text_field( $plugin_settings['hcaptcha_secret_key'] ) : '',
				'script_url'       => 'https://js.hcaptcha.com/1/api.js',
				'token_field'      => 'h-captcha-response',
				'container_class'  => 'h-captcha',
				'verify_endpoint'  => 'https://api.hcaptcha.com/siteverify',
			),
			'turnstile' => array(
				'provider'         => 'turnstile',
				'site_key'         => ! empty( $plugin_settings['turnstile_site_key'] ) ? sanitize_text_field( $plugin_settings['turnstile_site_key'] ) : '',
				'secret_key'       => ! empty( $plugin_settings['turnstile_secret_key'] ) ? sanitize_text_field( $plugin_settings['turnstile_secret_key'] ) : '',
				'script_url'       => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
				'token_field'      => 'cf-turnstile-response',
				'container_class'  => 'cf-turnstile',
				'verify_endpoint'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			),
		);

		if ( empty( $providers[ $provider ] ) ) {
			return array(
				'provider'   => '',
				'site_key'   => '',
				'secret_key' => '',
			);
		}

		return $providers[ $provider ];
	}

	private function should_render_challenge_provider() {
		$config = $this->get_spam_provider_config();

		return ! empty( $config['provider'] ) && ! empty( $config['site_key'] ) && ! empty( $config['secret_key'] );
	}

	private function validate_challenge_provider_token() {
		$config = $this->get_spam_provider_config();

		if ( empty( $config['provider'] ) || '' === $config['secret_key'] ) {
			return true;
		}

		$token_field = $config['token_field'];
		$token       = isset( $_POST[ $token_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $token_field ] ) ) : '';
		if ( '' === $token ) {
			$messages = array(
				'recaptcha' => __( 'Please complete the reCAPTCHA check before submitting.', 'ajforms' ),
				'hcaptcha'  => __( 'Please complete the hCaptcha check before submitting.', 'ajforms' ),
				'turnstile' => __( 'Please complete the Turnstile check before submitting.', 'ajforms' ),
			);
			$message  = isset( $messages[ $config['provider'] ] ) ? $messages[ $config['provider'] ] : __( 'Please complete the challenge check before submitting.', 'ajforms' );

			return new WP_Error( 'challenge_missing_token', $message );
		}

		$remote_ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$remote_ip = sanitize_text_field( trim( (string) $forwarded[0] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$response = wp_remote_post(
			$config['verify_endpoint'],
			array(
				'timeout' => 15,
				'body'    => array(
					'secret'   => $config['secret_key'],
					'response' => $token,
					'remoteip' => $remote_ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'challenge_request_failed', __( 'Challenge verification could not be completed right now.', 'ajforms' ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $payload['success'] ) ) {
			$messages = array(
				'recaptcha' => __( 'reCAPTCHA verification failed. Please try again.', 'ajforms' ),
				'hcaptcha'  => __( 'hCaptcha verification failed. Please try again.', 'ajforms' ),
				'turnstile' => __( 'Turnstile verification failed. Please try again.', 'ajforms' ),
			);
			$message  = isset( $messages[ $config['provider'] ] ) ? $messages[ $config['provider'] ] : __( 'Challenge verification failed. Please try again.', 'ajforms' );

			return new WP_Error( 'challenge_failed', $message );
		}

		return true;
	}

	private function get_honeypot_field_name( $form_id ) {
		return 'ajf_hp_' . absint( $form_id );
	}

	private function get_stripe_settings() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		return array(
			'mode'            => ! empty( $plugin_settings['stripe_mode'] ) ? sanitize_key( $plugin_settings['stripe_mode'] ) : 'test',
			'publishable_key' => ! empty( $plugin_settings['stripe_publishable_key'] ) ? sanitize_text_field( $plugin_settings['stripe_publishable_key'] ) : '',
			'secret_key'      => ! empty( $plugin_settings['stripe_secret_key'] ) ? sanitize_text_field( $plugin_settings['stripe_secret_key'] ) : '',
		);
	}

	private function get_stripe_payment_config( $form, $settings ) {
		$stripe_settings = $this->get_stripe_settings();
		$enabled         = ! empty( $settings['stripe_enabled'] );
		$price_id        = isset( $settings['stripe_price_id'] ) ? sanitize_text_field( $settings['stripe_price_id'] ) : '';
		$price           = '' !== $price_id ? $this->get_stripe_price_from_cache( $price_id ) : null;
		$amount          = $price ? (float) $price['amount'] : ( isset( $settings['stripe_amount'] ) ? floatval( $settings['stripe_amount'] ) : 0 );
		$currency        = $price ? strtolower( sanitize_key( $price['currency'] ) ) : ( isset( $settings['stripe_currency'] ) ? strtolower( sanitize_key( $settings['stripe_currency'] ) ) : 'usd' );
		$description     = $price ? $price['product_name'] : ( isset( $settings['stripe_description'] ) ? sanitize_text_field( $settings['stripe_description'] ) : 'Payment for {form_title}' );

		return array(
			'enabled'         => $enabled && '' !== $stripe_settings['publishable_key'] && '' !== $stripe_settings['secret_key'] && $amount > 0,
			'publishable_key' => $stripe_settings['publishable_key'],
			'secret_key'      => $stripe_settings['secret_key'],
			'price_id'        => $price_id,
			'product_id'      => $price && isset( $price['product_id'] ) ? $price['product_id'] : '',
			'amount'          => $amount,
			'currency'        => in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ? $currency : 'usd',
			'description'     => str_replace( '{form_title}', $form->title, $description ),
		);
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

	private function get_stripe_price_from_cache( $price_id ) {
		$price_id = sanitize_text_field( (string) $price_id );
		$cache    = $this->get_stripe_products_cache();
		$prices   = isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array();

		foreach ( $prices as $price ) {
			if ( is_array( $price ) && isset( $price['id'] ) && $price_id === $price['id'] ) {
				return $price;
			}
		}

		return null;
	}

	private function get_public_stripe_prices( $requested_price_ids = array(), $include_archived = false ) {
		$settings       = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
		$mode           = isset( $settings['stripe_products_mode'] ) ? sanitize_key( $settings['stripe_products_mode'] ) : 'all';
		$selected       = isset( $settings['stripe_selected_prices'] ) && is_array( $settings['stripe_selected_prices'] ) ? array_map( 'sanitize_text_field', $settings['stripe_selected_prices'] ) : array();
		$requested      = array_filter( array_map( 'sanitize_text_field', (array) $requested_price_ids ) );
		$cache          = $this->get_stripe_products_cache();
		$prices         = isset( $cache['prices'] ) && is_array( $cache['prices'] ) ? $cache['prices'] : array();
		$allowed_prices = array();

		foreach ( $prices as $price ) {
			if ( ! is_array( $price ) || empty( $price['id'] ) ) {
				continue;
			}

			if ( ! $include_archived && ( empty( $price['product_active'] ) || empty( $price['price_active'] ) ) ) {
				continue;
			}

			if ( 'selected' === $mode && ! in_array( $price['id'], $selected, true ) ) {
				continue;
			}

			if ( ! empty( $requested ) && ! in_array( $price['id'], $requested, true ) ) {
				continue;
			}

			$allowed_prices[] = $price;
		}

		return $allowed_prices;
	}

	private function convert_amount_to_minor_units( $amount, $currency ) {
		$zero_decimal_currencies = array( 'jpy', 'krw', 'vnd' );

		if ( in_array( strtolower( $currency ), $zero_decimal_currencies, true ) ) {
			return (int) round( $amount );
		}

		return (int) round( $amount * 100 );
	}

	private function stripe_api_request( $path, $secret_key, $body = array(), $method = 'POST' ) {
		$args = array(
			'timeout' => 20,
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
			),
		);

		if ( 'GET' === strtoupper( $method ) ) {
			$url = add_query_arg( $body, 'https://api.stripe.com/v1/' . ltrim( $path, '/' ) );
		} else {
			$url          = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $payload['error']['message'] ) ? sanitize_text_field( (string) $payload['error']['message'] ) : __( 'Stripe request failed.', 'ajforms' );
			return new WP_Error( 'stripe_request_failed', $message );
		}

		return is_array( $payload ) ? $payload : array();
	}

	public function ajax_create_stripe_payment_intent() {
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $form_id || ! wp_verify_nonce( $nonce, 'ajf_stripe_payment_' . $form_id ) ) {
			wp_send_json_error( __( 'Invalid payment request.', 'ajforms' ), 400 );
		}

		$form = $this->get_form_by_id( $form_id );
		if ( ! $form || 'deleted' === $form->status ) {
			wp_send_json_error( __( 'Form not found.', 'ajforms' ), 404 );
		}

		$schema = json_decode( $form->form_schema, true );
		if ( ! is_array( $schema ) ) {
			wp_send_json_error( __( 'Form schema is invalid.', 'ajforms' ), 400 );
		}

		$normalized     = $this->normalize_schema( $schema );
		$stripe_config  = $this->get_stripe_payment_config( $form, $normalized['settings'] );

		if ( ! $stripe_config['enabled'] ) {
			wp_send_json_error( __( 'Stripe payments are not enabled for this form.', 'ajforms' ), 400 );
		}

		$amount_minor = $this->convert_amount_to_minor_units( $stripe_config['amount'], $stripe_config['currency'] );
		$response     = $this->stripe_api_request(
			'payment_intents',
			$stripe_config['secret_key'],
			array(
				'amount'                 => $amount_minor,
				'currency'               => $stripe_config['currency'],
				'description'            => $stripe_config['description'],
				'payment_method_types[]' => 'card',
				'metadata[form_id]'      => (string) $form_id,
				'metadata[form_title]'   => sanitize_text_field( $form->title ),
				'metadata[price_id]'     => $stripe_config['price_id'],
				'metadata[product_id]'   => $stripe_config['product_id'],
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 400 );
		}

		wp_send_json_success(
			array(
				'client_secret' => isset( $response['client_secret'] ) ? sanitize_text_field( (string) $response['client_secret'] ) : '',
				'amount'        => $stripe_config['amount'],
				'currency'      => strtoupper( $stripe_config['currency'] ),
				'description'   => $stripe_config['description'],
			)
		);
	}

	public function ajax_cancel_portal_service_request() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Login required.', 'ajforms' ), 401 );
		}

		$ledger_id = isset( $_POST['ledger_id'] ) ? absint( wp_unslash( $_POST['ledger_id'] ) ) : 0;
		$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! $ledger_id || ! wp_verify_nonce( $nonce, 'ajcore_cancel_portal_service_request_' . $ledger_id ) ) {
			wp_send_json_error( __( 'Invalid request.', 'ajforms' ), 400 );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_send_json_error( __( 'Portal account is not linked.', 'ajforms' ), 403 );
		}

		global $wpdb;
		$entry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_ledger_table()} WHERE id = %d AND stripe_customer_id = %s LIMIT 1",
				$ledger_id,
				$stripe_customer_id
			)
		);

		if ( ! $entry || ! in_array( (string) $entry->source_type, array( 'checkout_session', 'custom_service_request' ), true ) ) {
			wp_send_json_error( __( 'Request was not found.', 'ajforms' ), 404 );
		}

		$deleted = $wpdb->delete(
			$this->get_portal_ledger_table(),
			array(
				'id'                 => $ledger_id,
				'stripe_customer_id' => $stripe_customer_id,
				'source_type'        => (string) $entry->source_type,
			),
			array( '%d', '%s', '%s' )
		);

		if ( false === $deleted ) {
			wp_send_json_error( __( 'Unable to remove request.', 'ajforms' ), 500 );
		}

		wp_send_json_success( array( 'removed' => true, 'ledger_id' => $ledger_id ) );
	}

	public function ajax_create_custom_service_request() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Login required.', 'ajforms' ), 401 );
		}

		$price_id = isset( $_POST['price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['price_id'] ) ) : '';
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( '' === $price_id || ! wp_verify_nonce( $nonce, 'ajcore_portal_custom_request_' . $price_id ) ) {
			wp_send_json_error( __( 'Invalid custom service request.', 'ajforms' ), 400 );
		}

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			wp_send_json_error( __( 'Portal account is not linked.', 'ajforms' ), 403 );
		}

		$product = $this->get_portal_product_by_price_id( $price_id );
		if ( ! $product || 'custom_request' !== $this->get_portal_product_duplicate_behavior( $product ) ) {
			wp_send_json_error( __( 'This service is not available for custom requests.', 'ajforms' ), 404 );
		}

		$context = $this->get_current_user_portal_billing_context();
		$purchased_price_ids   = $this->get_customer_purchased_price_ids( $context['subscriptions'] );
		$purchased_product_ids = $this->get_customer_purchased_product_ids( $context['subscriptions'] );
		$is_owned_for_custom_request = $this->is_portal_product_owned( $product, $purchased_price_ids, $purchased_product_ids, $context['subscriptions'], $context['ledger'] );
		if ( ! $is_owned_for_custom_request ) {
			$available_products_for_custom_request = $this->get_portal_available_service_products( $context['subscriptions'], $context['ledger'] );
			$available_price_ids_for_custom_request = array();
			foreach ( $available_products_for_custom_request as $available_product_for_custom_request ) {
				$available_price_id_for_custom_request = isset( $available_product_for_custom_request->stripe_price_id ) ? sanitize_text_field( (string) $available_product_for_custom_request->stripe_price_id ) : '';
				if ( '' !== $available_price_id_for_custom_request ) {
					$available_price_ids_for_custom_request[] = $available_price_id_for_custom_request;
				}
			}
			$has_service_context_for_custom_request = ! empty( $context['subscriptions'] );
			if ( ! $has_service_context_for_custom_request ) {
				foreach ( (array) $context['ledger'] as $ledger_entry_for_custom_request ) {
					$ledger_status_for_custom_request = isset( $ledger_entry_for_custom_request->status ) ? sanitize_key( (string) $ledger_entry_for_custom_request->status ) : '';
					if ( in_array( $ledger_status_for_custom_request, array( 'paid', 'succeeded', 'active' ), true ) ) {
						$has_service_context_for_custom_request = true;
						break;
					}
				}
			}
			$is_custom_request_fallback_allowed = $has_service_context_for_custom_request && ! in_array( $price_id, $available_price_ids_for_custom_request, true );
			if ( ! $is_custom_request_fallback_allowed ) {
				wp_send_json_error( __( 'This custom request is available after the base service is active on your account.', 'ajforms' ), 400 );
			}
		}

		$existing_request = $this->get_open_custom_service_request( $stripe_customer_id, $price_id );
		if ( $existing_request ) {
			wp_send_json_success(
				array(
					'message'   => __( 'Your request is already under review.', 'ajforms' ),
					'ledger_id' => (int) $existing_request->id,
				)
			);
		}

		$product_name = ! empty( $product->custom_label ) ? $product->custom_label : ( ! empty( $product->name ) ? $product->name : __( 'Service', 'ajforms' ) );
		$request_title = ! empty( $product->custom_request_title ) ? $product->custom_request_title : sprintf( __( 'Custom request for %s', 'ajforms' ), $product_name );
		$source_object_id = $this->get_portal_custom_request_source_object_id( $stripe_customer_id, $price_id );
		$metadata = array(
			'price_id'     => $price_id,
			'product_id'   => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
			'product_name' => $product_name,
			'source'       => 'portal_custom_request',
		);

		global $wpdb;
		$inserted = $wpdb->replace(
			$this->get_portal_ledger_table(),
			array(
				'stripe_customer_id' => $stripe_customer_id,
				'source_object_id'   => $source_object_id,
				'source_type'        => 'custom_service_request',
				'ledger_date'        => current_time( 'mysql' ),
				'description'        => $request_title,
				'amount'             => 0,
				'currency'           => ! empty( $product->currency ) ? sanitize_key( (string) $product->currency ) : 'usd',
				'status'             => 'admin_review_required',
				'invoice_id'         => '',
				'payment_intent_id'  => '',
				'charge_id'          => '',
				'metadata'           => wp_json_encode( $metadata ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			wp_send_json_error( __( 'Unable to submit the request.', 'ajforms' ), 500 );
		}

		$ledger_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->get_portal_ledger_table()} WHERE source_object_id = %s LIMIT 1",
				$source_object_id
			)
		);

		$service_request_id = $this->upsert_portal_service_request(
			array(
				'wp_user_id'         => get_current_user_id(),
				'stripe_customer_id' => $stripe_customer_id,
				'stripe_price_id'    => $price_id,
				'stripe_product_id'  => isset( $product->stripe_product_id ) ? sanitize_text_field( (string) $product->stripe_product_id ) : '',
				'service_name'       => $product_name,
				'request_type'       => 'custom_request',
				'status'             => 'admin_review_required',
				'amount'             => 0,
				'currency'           => ! empty( $product->currency ) ? sanitize_key( (string) $product->currency ) : 'usd',
				'source_object_id'   => $source_object_id,
				'source_type'        => 'custom_service_request',
				'ledger_id'          => $ledger_id,
				'source'             => 'client_portal',
				'created_by'         => get_current_user_id(),
				'raw_data'           => $metadata,
			)
		);

		if ( ! $service_request_id ) {
			wp_send_json_error( __( 'The request was added to billing, but the service request queue could not be updated.', 'ajforms' ), 500 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Your request was submitted and is now under review.', 'ajforms' ),
			)
		);
	}

	public function ajax_create_checkout_session() {
		$price_id = isset( $_POST['price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['price_id'] ) ) : '';
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$items    = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$include_archived  = isset( $_POST['include_archived'] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $_POST['include_archived'] ) ) ), array( '1', 'true', 'yes' ), true );
		$portal_add_service = isset( $_POST['portal_add_service'] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $_POST['portal_add_service'] ) ) ), array( '1', 'true', 'yes' ), true );

		if ( $portal_add_service ) {
			if ( ! is_user_logged_in() || '' === $price_id || ! wp_verify_nonce( $nonce, 'ajcore_portal_add_service_' . $price_id ) ) {
				wp_send_json_error( __( 'Invalid portal service request.', 'ajforms' ), 400 );
			}
		} elseif ( is_array( $items ) ) {
			if ( ! wp_verify_nonce( $nonce, 'ajcore_cart_checkout' ) ) {
				wp_send_json_error( __( 'Invalid cart request.', 'ajforms' ), 400 );
			}
		} elseif ( '' === $price_id || ! wp_verify_nonce( $nonce, 'ajcore_buy_product_' . $price_id ) ) {
			wp_send_json_error( __( 'Invalid product request.', 'ajforms' ), 400 );
		}

		$requested_price_ids = is_array( $items ) ? array_map(
			function ( $item ) {
				return is_array( $item ) && isset( $item['price_id'] ) ? sanitize_text_field( (string) $item['price_id'] ) : '';
			},
			$items
		) : array( $price_id );
		$requested_price_ids = array_filter( $requested_price_ids );
		$allowed_price_map   = array();
		$checkout_mode       = 'payment';
		$portal_product      = null;

		if ( $portal_add_service ) {
			$portal_product = $this->get_portal_product_by_price_id( $price_id );
			if ( ! $portal_product ) {
				wp_send_json_error( __( 'Service is not available.', 'ajforms' ), 404 );
			}

			$allowed_price_map[ $price_id ] = array(
				'id'                 => $price_id,
				'product_id'         => isset( $portal_product->stripe_product_id ) ? sanitize_text_field( (string) $portal_product->stripe_product_id ) : '',
				'recurring_interval' => isset( $portal_product->recurring_interval ) ? sanitize_key( (string) $portal_product->recurring_interval ) : '',
			);
			$checkout_mode = ! empty( $allowed_price_map[ $price_id ]['recurring_interval'] ) ? 'subscription' : 'payment';
		} else {
			$allowed_prices = $this->get_public_stripe_prices( $requested_price_ids, $include_archived );

			foreach ( $allowed_prices as $allowed_price ) {
				if ( is_array( $allowed_price ) && ! empty( $allowed_price['id'] ) ) {
					$allowed_price_map[ $allowed_price['id'] ] = $allowed_price;
				}
			}
		}

		if ( empty( $allowed_price_map ) ) {
			wp_send_json_error( __( 'Product is not available.', 'ajforms' ), 404 );
		}

		$stripe_settings = $this->get_stripe_settings();
		if ( empty( $stripe_settings['secret_key'] ) ) {
			wp_send_json_error( __( 'Stripe is not connected.', 'ajforms' ), 400 );
		}

		$current_url = isset( $_POST['current_url'] ) ? esc_url_raw( wp_unslash( $_POST['current_url'] ) ) : home_url( '/' );
		$success_url = add_query_arg( 'ajcore_checkout', 'success', $current_url );
		$cancel_url  = add_query_arg( 'ajcore_checkout', 'cancelled', $current_url );
		$body        = array(
			'mode'        => $checkout_mode,
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
		);

		$mapped_stripe_customer_id = is_user_logged_in() ? $this->get_current_user_stripe_customer_id() : '';
		if ( 0 === strpos( $mapped_stripe_customer_id, 'cus_' ) ) {
			$body['customer'] = $mapped_stripe_customer_id;
		} elseif ( 'payment' === $checkout_mode ) {
			$body['customer_creation'] = 'always';
		}

		if ( is_array( $items ) ) {
			$line_index = 0;
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['price_id'] ) ) {
					continue;
				}

				$item_price_id = sanitize_text_field( (string) $item['price_id'] );
				$quantity      = isset( $item['quantity'] ) ? max( 1, min( 99, absint( $item['quantity'] ) ) ) : 1;

				if ( empty( $allowed_price_map[ $item_price_id ] ) ) {
					continue;
				}

				$body[ 'line_items[' . $line_index . '][price]' ]    = $item_price_id;
				$body[ 'line_items[' . $line_index . '][quantity]' ] = $quantity;
				$line_index++;
			}

			if ( 0 === $line_index ) {
				wp_send_json_error( __( 'Your cart is empty.', 'ajforms' ), 400 );
			}

			$body['metadata[source]'] = 'ajcore_products_cart';
		} else {
			$price = reset( $allowed_price_map );
			$body['line_items[0][price]']    = $price_id;
			$body['line_items[0][quantity]'] = 1;
			$body['metadata[price_id]']      = $price_id;
			$body['metadata[product_id]']    = isset( $price['product_id'] ) ? $price['product_id'] : '';
			$body['metadata[source]']        = $portal_add_service ? 'ajcore_portal_add_service' : 'ajcore_products';
			if ( $portal_add_service && '' !== $mapped_stripe_customer_id ) {
				$body['metadata[stripe_customer_id]'] = $mapped_stripe_customer_id;
			}
		}

		$response = $this->stripe_api_request(
			'checkout/sessions',
			$stripe_settings['secret_key'],
			$body
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 400 );
		}

		if ( $portal_add_service && ! empty( $response['id'] ) && '' !== $mapped_stripe_customer_id ) {
			global $wpdb;
			$product_name = $portal_product && ! empty( $portal_product->custom_label ) ? $portal_product->custom_label : ( $portal_product && ! empty( $portal_product->name ) ? $portal_product->name : __( 'Additional service request', 'ajforms' ) );
			$product_description = sprintf( __( 'Service request: %s', 'ajforms' ), $product_name );
			$checkout_url = ! empty( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '';
			$metadata = array(
				'checkout_url' => $checkout_url,
				'price_id'     => $price_id,
				'product_id'   => isset( $allowed_price_map[ $price_id ]['product_id'] ) ? $allowed_price_map[ $price_id ]['product_id'] : '',
				'product_name' => $product_name,
				'source'       => 'portal_add_service',
			);

			$wpdb->replace(
				$this->get_portal_ledger_table(),
				array(
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'source_object_id'   => sanitize_text_field( (string) $response['id'] ),
					'source_type'        => 'checkout_session',
					'ledger_date'        => current_time( 'mysql' ),
					'description'        => $product_description,
					'amount'             => $portal_product ? (float) $portal_product->price_amount : 0,
					'currency'           => $portal_product ? sanitize_key( (string) $portal_product->currency ) : 'usd',
					'status'             => 'unpaid',
					'invoice_id'         => '',
					'payment_intent_id'  => '',
					'charge_id'          => '',
					'metadata'           => wp_json_encode( $metadata ),
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$this->upsert_portal_service_request(
				array(
					'wp_user_id'         => get_current_user_id(),
					'stripe_customer_id' => $mapped_stripe_customer_id,
					'stripe_price_id'    => $price_id,
					'stripe_product_id'  => isset( $allowed_price_map[ $price_id ]['product_id'] ) ? $allowed_price_map[ $price_id ]['product_id'] : '',
					'service_name'       => $product_name,
					'request_type'       => 'add_service',
					'status'             => 'pending_payment',
					'amount'             => $portal_product ? (float) $portal_product->price_amount : 0,
					'currency'           => $portal_product ? sanitize_key( (string) $portal_product->currency ) : 'usd',
					'source_object_id'   => sanitize_text_field( (string) $response['id'] ),
					'source_type'        => 'checkout_session',
					'source'             => 'client_portal',
					'created_by'         => get_current_user_id(),
					'raw_data'           => $response,
				)
			);
		}

		wp_send_json_success(
			array(
				'url' => isset( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '',
			)
		);
	}

	private function validate_stripe_payment_submission( $form, $settings ) {
		$stripe_config = $this->get_stripe_payment_config( $form, $settings );

		if ( ! $stripe_config['enabled'] ) {
			return true;
		}

		$payment_intent_id = isset( $_POST['ajf_stripe_payment_intent'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_stripe_payment_intent'] ) ) : '';
		if ( '' === $payment_intent_id ) {
			return new WP_Error( 'stripe_missing_payment', __( 'Complete the Stripe payment before submitting the form.', 'ajforms' ) );
		}

		$payment_intent = $this->stripe_api_request(
			'payment_intents/' . rawurlencode( $payment_intent_id ),
			$stripe_config['secret_key'],
			array(),
			'GET'
		);

		if ( is_wp_error( $payment_intent ) ) {
			return $payment_intent;
		}

		$expected_amount = $this->convert_amount_to_minor_units( $stripe_config['amount'], $stripe_config['currency'] );
		$actual_amount   = isset( $payment_intent['amount'] ) ? absint( $payment_intent['amount'] ) : 0;
		$actual_status   = isset( $payment_intent['status'] ) ? sanitize_key( $payment_intent['status'] ) : '';
		$actual_currency = isset( $payment_intent['currency'] ) ? strtolower( sanitize_key( $payment_intent['currency'] ) ) : '';
		$form_meta_id    = isset( $payment_intent['metadata']['form_id'] ) ? absint( $payment_intent['metadata']['form_id'] ) : 0;

		if ( 'succeeded' !== $actual_status ) {
			return new WP_Error( 'stripe_not_paid', __( 'Stripe payment is not complete yet.', 'ajforms' ) );
		}

		$actual_price_id = isset( $payment_intent['metadata']['price_id'] ) ? sanitize_text_field( (string) $payment_intent['metadata']['price_id'] ) : '';

		if ( $expected_amount !== $actual_amount || strtolower( $stripe_config['currency'] ) !== $actual_currency || absint( $form->id ) !== $form_meta_id || ( '' !== $stripe_config['price_id'] && $stripe_config['price_id'] !== $actual_price_id ) ) {
			return new WP_Error( 'stripe_payment_mismatch', __( 'Stripe payment details do not match this form submission.', 'ajforms' ) );
		}

		return array(
			'payment_intent_id' => $payment_intent_id,
			'amount'            => $stripe_config['amount'],
			'currency'          => strtoupper( $stripe_config['currency'] ),
			'description'       => $stripe_config['description'],
			'price_id'          => $stripe_config['price_id'],
			'product_id'        => $stripe_config['product_id'],
		);
	}

	private function get_forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajforms_forms';
	}

	private function render_product_rich_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		if ( $text !== wp_strip_all_tags( $text ) ) {
			return wp_kses_post( wpautop( $text ) );
		}

		$lines          = preg_split( '/\r\n|\r|\n/', $text );
		$html           = '';
		$paragraph      = array();
		$list_is_open   = false;

		$flush_paragraph = function () use ( &$html, &$paragraph ) {
			if ( empty( $paragraph ) ) {
				return;
			}

			$html     .= '<p>' . esc_html( implode( ' ', $paragraph ) ) . '</p>';
			$paragraph = array();
		};

		$close_list = function () use ( &$html, &$list_is_open ) {
			if ( $list_is_open ) {
				$html        .= '</ul>';
				$list_is_open = false;
			}
		};

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				$flush_paragraph();
				$close_list();
				continue;
			}

			if ( preg_match( '/^###\s+(.+)$/', $line, $matches ) ) {
				$flush_paragraph();
				$close_list();
				$html .= '<h4>' . esc_html( $matches[1] ) . '</h4>';
				continue;
			}

			if ( preg_match( '/^##?\s+(.+)$/', $line, $matches ) ) {
				$flush_paragraph();
				$close_list();
				$html .= '<h3>' . esc_html( $matches[1] ) . '</h3>';
				continue;
			}

			if ( preg_match( '/^[-*]\s+(.+)$/', $line, $matches ) ) {
				$flush_paragraph();
				if ( ! $list_is_open ) {
					$html        .= '<ul>';
					$list_is_open = true;
				}
				$html .= '<li>' . esc_html( $matches[1] ) . '</li>';
				continue;
			}

			$close_list();
			$paragraph[] = $line;
		}

		$flush_paragraph();
		$close_list();

		return wp_kses_post( $html );
	}

	public function render_products_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'price_ids' => '',
				'columns'   => '3',
				'button'    => __( 'Buy Now', 'ajforms' ),
				'mode'      => 'buy',
				'display'   => '',
				'cart'      => '',
				'show'      => '',
				'template'  => 'default',
				'details'   => 'none',
				'include_archived' => 'no',
			),
			$atts,
			'ajcore_products'
		);

		$requested_price_ids = array_filter( array_map( 'trim', explode( ',', (string) $atts['price_ids'] ) ) );
		$include_archived    = in_array( strtolower( (string) $atts['include_archived'] ), array( '1', 'true', 'yes' ), true );
		$prices              = $this->get_public_stripe_prices( $requested_price_ids, $include_archived );
		$stripe_settings     = $this->get_stripe_settings();
		$columns             = min( 4, max( 1, absint( $atts['columns'] ) ) );
		$requested_mode      = '' !== (string) $atts['display'] ? sanitize_key( $atts['display'] ) : sanitize_key( $atts['mode'] );
		$mode                = in_array( $requested_mode, array( 'buy', 'cart' ), true ) ? $requested_mode : 'buy';
		if ( in_array( strtolower( (string) $atts['cart'] ), array( '1', 'true', 'yes' ), true ) ) {
			$mode = 'cart';
		}
		$is_cart_mode        = 'cart' === $mode;
		$template            = in_array( sanitize_key( $atts['template'] ), array( 'default', 'compact' ), true ) ? sanitize_key( $atts['template'] ) : 'default';
		$details_mode        = in_array( sanitize_key( $atts['details'] ), array( 'none', 'expand' ), true ) ? sanitize_key( $atts['details'] ) : 'none';
		$default_show        = 'compact' === $template ? 'title,summary,price,button' : 'title,description,price,button';
		$show_fields         = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', '' !== (string) $atts['show'] ? (string) $atts['show'] : $default_show ) ) ) );
		$show_title          = in_array( 'title', $show_fields, true );
		$show_description    = in_array( 'description', $show_fields, true );
		$show_summary        = in_array( 'summary', $show_fields, true );
		$show_price          = in_array( 'price', $show_fields, true );
		$show_button         = in_array( 'button', $show_fields, true );

		if ( empty( $prices ) ) {
			return '';
		}

		ob_start();
		?>
		<div
			class="ajcore-products-wrap <?php echo $is_cart_mode ? 'ajcore-products-wrap-cart' : 'ajcore-products-wrap-buy'; ?> ajcore-products-template-<?php echo esc_attr( $template ); ?>"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-cart-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_cart_checkout' ) ); ?>"
			data-include-archived="<?php echo $include_archived ? 'yes' : 'no'; ?>"
		>
			<div class="ajcore-products-list" style="display:grid;grid-template-columns:repeat(<?php echo esc_attr( $columns ); ?>,minmax(0,1fr));gap:18px;">
				<?php foreach ( $prices as $price ) : ?>
					<?php
					$price_amount   = (float) $price['amount'];
					$price_currency = strtoupper( $price['currency'] );
					$price_label    = $price_currency . ' ' . number_format_i18n( $price_amount, 2 );
					$description    = isset( $price['product_description'] ) ? (string) $price['product_description'] : '';
					$rich_description = ! empty( $price['product_rich_description'] ) ? (string) $price['product_rich_description'] : $description;
					$summary        = ! empty( $price['product_summary'] ) ? (string) $price['product_summary'] : wp_trim_words( $description, 16 );
					?>
					<div
						class="ajcore-product"
						data-price-id="<?php echo esc_attr( $price['id'] ); ?>"
						data-product-name="<?php echo esc_attr( $price['product_name'] ); ?>"
						data-amount="<?php echo esc_attr( $price_amount ); ?>"
						data-currency="<?php echo esc_attr( strtolower( $price['currency'] ) ); ?>"
						style="border:1px solid #dfe6ee;border-radius:14px;background:#fff;padding:20px;box-shadow:0 14px 30px rgba(15,23,42,.06);"
					>
						<?php if ( $show_title ) : ?>
							<h3 class="ajcore-product-title" style="margin:0 0 10px;font-size:22px;line-height:1.2;"><?php echo esc_html( $price['product_name'] ); ?></h3>
						<?php endif; ?>
						<?php if ( $show_summary && '' !== $summary ) : ?>
							<div class="ajcore-product-summary" style="margin-bottom:12px;color:#64748b;line-height:1.45;"><?php echo esc_html( $summary ); ?></div>
						<?php endif; ?>
						<?php if ( $show_description && '' !== $rich_description ) : ?>
							<div class="ajcore-product-description" style="margin-bottom:10px;color:#64748b;">
								<?php echo $this->render_product_rich_text( $rich_description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php elseif ( $show_description && ! empty( $price['nickname'] ) ) : ?>
							<div class="ajcore-product-description" style="margin-bottom:10px;color:#64748b;"><?php echo esc_html( $price['nickname'] ); ?></div>
						<?php endif; ?>
						<?php if ( 'expand' === $details_mode && '' !== $rich_description && ! $show_description ) : ?>
							<details class="ajcore-product-details" style="margin:0 0 14px;color:#64748b;">
								<summary style="cursor:pointer;color:#0f7ac6;font-weight:700;"><?php esc_html_e( 'View details', 'ajforms' ); ?></summary>
								<div style="margin-top:8px;line-height:1.45;">
									<?php echo $this->render_product_rich_text( $rich_description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							</details>
						<?php endif; ?>
						<?php if ( $show_price ) : ?>
							<div class="ajcore-product-price" style="margin:12px 0 18px;font-size:28px;font-weight:800;color:#111827;">
								<?php echo esc_html( $price_label ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $show_button && $is_cart_mode ) : ?>
							<div class="ajcore-product-cart-controls" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
								<label style="display:flex;align-items:center;gap:8px;margin:0;color:#334155;font-weight:700;">
									<?php esc_html_e( 'Qty', 'ajforms' ); ?>
									<input class="ajcore-product-quantity" type="number" min="1" max="99" step="1" value="1" style="width:76px;min-height:42px;border:1px solid #d1d5db;border-radius:10px;padding:8px 10px;">
								</label>
								<button
									type="button"
									class="ajcore-product-add"
									<?php disabled( empty( $stripe_settings['secret_key'] ) ); ?>
									style="background:#0f7ac6;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:800;cursor:pointer;"
								><?php esc_html_e( 'Add to Cart', 'ajforms' ); ?></button>
							</div>
						<?php elseif ( $show_button ) : ?>
							<button
								type="button"
								class="ajcore-product-buy"
								data-price-id="<?php echo esc_attr( $price['id'] ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'ajcore_buy_product_' . $price['id'] ) ); ?>"
								<?php disabled( empty( $stripe_settings['secret_key'] ) ); ?>
								style="background:#0f7ac6;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:800;cursor:pointer;"
							><?php echo esc_html( $atts['button'] ); ?></button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $is_cart_mode ) : ?>
				<aside class="ajcore-cart" style="margin-top:22px;border:1px solid #dfe6ee;border-radius:14px;background:#fff;padding:20px;box-shadow:0 14px 30px rgba(15,23,42,.06);">
					<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;">
						<h3 style="margin:0;font-size:22px;line-height:1.2;"><?php esc_html_e( 'Cart', 'ajforms' ); ?></h3>
						<button type="button" class="ajcore-cart-clear" style="background:transparent;color:#64748b;border:0;padding:0;font-weight:700;cursor:pointer;"><?php esc_html_e( 'Clear', 'ajforms' ); ?></button>
					</div>
					<div class="ajcore-cart-empty" style="color:#64748b;"><?php esc_html_e( 'No products selected yet.', 'ajforms' ); ?></div>
					<div class="ajcore-cart-items" style="display:grid;gap:10px;"></div>
					<div class="ajcore-cart-total" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb;font-size:22px;font-weight:800;color:#111827;"></div>
					<button type="button" class="ajcore-cart-checkout" disabled style="margin-top:16px;background:#0f7ac6;color:#fff;border:0;border-radius:10px;padding:13px 20px;font-weight:800;cursor:pointer;"><?php esc_html_e( 'Checkout', 'ajforms' ); ?></button>
					<p class="ajcore-cart-message" style="display:none;margin:12px 0 0;color:#b32d2e;"></p>
				</aside>
			<?php endif; ?>
		</div>
		<script>
		(function() {
			const root = document.currentScript.previousElementSibling;
			if (!root || root.dataset.ajcoreProductsReady) {
				return;
			}
			root.dataset.ajcoreProductsReady = '1';
			const isCartMode = root.dataset.mode === 'cart';
			const cart = {};
			const cartItems = root.querySelector('.ajcore-cart-items');
			const cartEmpty = root.querySelector('.ajcore-cart-empty');
			const cartTotal = root.querySelector('.ajcore-cart-total');
			const checkoutButton = root.querySelector('.ajcore-cart-checkout');
			const cartMessage = root.querySelector('.ajcore-cart-message');

			function formatCurrency(amount, currency) {
				try {
					return new Intl.NumberFormat(undefined, { style: 'currency', currency: (currency || 'usd').toUpperCase() }).format(amount);
				} catch (error) {
					return (currency || 'USD').toUpperCase() + ' ' + amount.toFixed(2);
				}
			}

			function setCartMessage(message) {
				if (!cartMessage) {
					return;
				}

				cartMessage.textContent = message || '';
				cartMessage.style.display = message ? 'block' : 'none';
			}

			function renderCart() {
				if (!isCartMode || !cartItems || !cartEmpty || !cartTotal || !checkoutButton) {
					return;
				}

				const items = Object.values(cart);
				cartItems.innerHTML = '';
				cartEmpty.style.display = items.length ? 'none' : 'block';
				checkoutButton.disabled = items.length === 0;
				let total = 0;
				let currency = 'usd';
				items.forEach(function(item) {
					total += item.amount * item.quantity;
					currency = item.currency;
					const row = document.createElement('div');
					row.className = 'ajcore-cart-row';
					row.style.cssText = 'display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;';
					row.innerHTML = '<div><strong></strong><div></div></div><input type="number" min="1" max="99" step="1"><button type="button">Remove</button>';
					row.querySelector('strong').textContent = item.name;
					row.querySelector('div div').textContent = formatCurrency(item.amount, item.currency);
					const quantityInput = row.querySelector('input');
					quantityInput.value = item.quantity;
					quantityInput.style.cssText = 'width:72px;min-height:38px;border:1px solid #d1d5db;border-radius:9px;padding:7px 9px;';
					quantityInput.addEventListener('change', function() {
						item.quantity = Math.max(1, Math.min(99, parseInt(quantityInput.value, 10) || 1));
						renderCart();
					});
					const removeButton = row.querySelector('button');
					removeButton.style.cssText = 'background:#fff;color:#b32d2e;border:1px solid #fecaca;border-radius:9px;padding:8px 10px;font-weight:700;cursor:pointer;';
					removeButton.addEventListener('click', function() {
						delete cart[item.price_id];
						renderCart();
					});
					cartItems.appendChild(row);
				});
				cartTotal.style.display = items.length ? 'block' : 'none';
				cartTotal.textContent = items.length ? 'Total: ' + formatCurrency(total, currency) : '';
			}

			root.addEventListener('click', function(event) {
				const addButton = event.target.closest('.ajcore-product-add');
				if (addButton) {
					const product = addButton.closest('.ajcore-product');
					if (!product) {
						return;
					}
					const quantityInput = product.querySelector('.ajcore-product-quantity');
					const priceId = product.dataset.priceId;
					const quantity = Math.max(1, Math.min(99, parseInt(quantityInput ? quantityInput.value : '1', 10) || 1));
					if (!cart[priceId]) {
						cart[priceId] = {
							price_id: priceId,
							name: product.dataset.productName || 'Product',
							amount: parseFloat(product.dataset.amount || '0') || 0,
							currency: product.dataset.currency || 'usd',
							quantity: 0
						};
					}
					cart[priceId].quantity = Math.min(99, cart[priceId].quantity + quantity);
					setCartMessage('');
					renderCart();
					return;
				}

				const clearButton = event.target.closest('.ajcore-cart-clear');
				if (clearButton) {
					Object.keys(cart).forEach(function(priceId) {
						delete cart[priceId];
					});
					setCartMessage('');
					renderCart();
					return;
				}

				const button = event.target.closest('.ajcore-product-buy');
				if (!button || button.disabled) {
					return;
				}
				button.disabled = true;
				const originalText = button.textContent;
				button.textContent = 'Loading...';
				const formData = new FormData();
				formData.append('action', 'ajcore_create_checkout_session');
				formData.append('price_id', button.dataset.priceId);
				formData.append('nonce', button.dataset.nonce);
				formData.append('include_archived', root.dataset.includeArchived || 'no');
				formData.append('current_url', window.location.href);
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
					.then(function(response) { return response.json(); })
					.then(function(payload) {
						if (!payload || !payload.success || !payload.data || !payload.data.url) {
							throw new Error((payload && payload.data) || 'Unable to start checkout.');
						}
						window.location.href = payload.data.url;
					})
					.catch(function(error) {
						button.disabled = false;
						button.textContent = originalText;
						window.alert(error.message || 'Unable to start checkout.');
					});
			});

			if (checkoutButton) {
				checkoutButton.addEventListener('click', function() {
					const items = Object.values(cart).map(function(item) {
						return { price_id: item.price_id, quantity: item.quantity };
					});
					if (!items.length) {
						return;
					}
					checkoutButton.disabled = true;
					const originalText = checkoutButton.textContent;
					checkoutButton.textContent = 'Loading...';
					setCartMessage('');
					const formData = new FormData();
					formData.append('action', 'ajcore_create_checkout_session');
					formData.append('items', JSON.stringify(items));
					formData.append('nonce', root.dataset.cartNonce);
					formData.append('include_archived', root.dataset.includeArchived || 'no');
					formData.append('current_url', window.location.href);
					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(function(response) { return response.json(); })
						.then(function(payload) {
							if (!payload || !payload.success || !payload.data || !payload.data.url) {
								throw new Error((payload && payload.data) || 'Unable to start checkout.');
							}
							window.location.href = payload.data.url;
						})
						.catch(function(error) {
							checkoutButton.disabled = false;
							checkoutButton.textContent = originalText;
							setCartMessage(error.message || 'Unable to start checkout.');
						});
				});
			}
		})();
		</script>
		<style>
			.ajcore-products-wrap-cart{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:22px;align-items:start}
			.ajcore-products-wrap-cart .ajcore-products-list{grid-column:1}
			.ajcore-products-wrap-cart .ajcore-cart{grid-column:2;grid-row:1;position:sticky;top:24px}
			.ajcore-product-description :is(h3,h4){margin:10px 0 6px;color:#111827;line-height:1.2}
			.ajcore-product-description h3{font-size:18px}
			.ajcore-product-description h4{font-size:16px}
			.ajcore-product-description p{margin:0 0 10px}
			.ajcore-product-description ul{margin:8px 0 12px 20px;padding:0}
			.ajcore-product-description li{margin:4px 0}
			.ajcore-product-details :is(h3,h4){margin:10px 0 6px;color:#111827;line-height:1.2}
			.ajcore-product-details p{margin:0 0 10px}
			.ajcore-product-details ul{margin:8px 0 12px 20px;padding:0}
			@media (max-width: 800px){.ajcore-products-list{grid-template-columns:1fr!important}}
			@media (max-width: 980px){.ajcore-products-wrap-cart{grid-template-columns:1fr}.ajcore-products-wrap-cart .ajcore-cart{grid-column:auto;grid-row:auto;position:static}}
		</style>
		<?php
		return ob_get_clean();
	}

	private function get_leads_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajforms_leads';
	}

	private function get_form_by_id( $form_id ) {
		global $wpdb;

		$table = $this->get_forms_table();

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $table_exists !== $table ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$form_id
			)
		);
	}

	private function normalize_schema( $schema ) {
		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			$raw_settings = isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : array();
			$settings     = wp_parse_args( $raw_settings, $this->get_default_form_settings() );
			if ( ! isset( $raw_settings['confirmation_mode'] ) && ! empty( $raw_settings['confirmation_rules'] ) ) {
				$settings['confirmation_mode'] = 'conditional';
			}

			return array(
				'fields'   => $schema['fields'],
				'settings' => $settings,
			);
		}

		if ( is_array( $schema ) ) {
			return array(
				'fields'   => $schema,
				'settings' => $this->get_default_form_settings(),
			);
		}

		return array(
			'fields'   => array(),
			'settings' => $this->get_default_form_settings(),
		);
	}

	public function maybe_render_form_preview() {
		$form_id = isset( $_GET['ajforms_preview'] ) ? absint( wp_unslash( $_GET['ajforms_preview'] ) ) : 0;
		if ( ! $form_id ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview this form.', 'ajforms' ), 403 );
		}

		$form = $this->get_form_by_id( $form_id );
		if ( ! $form || 'deleted' === $form->status ) {
			wp_die( esc_html__( 'Form not found.', 'ajforms' ), 404 );
		}

		nocache_headers();
		$form_markup = $this->render_form_shortcode( array( 'id' => $form_id ) );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $form->title . ' Preview' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'ajforms-preview-page' ); ?>>
			<div style="max-width:760px;margin:40px auto;padding:32px;background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.05);">
				<div style="margin-bottom:20px;color:#646970;font-size:14px;">AJ Core Preview</div>
				<?php echo $form_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}

	private function format_lead_value_for_display( $value ) {
		if ( is_array( $value ) ) {
			$cleaned = array_map( 'sanitize_text_field', $value );
			return implode( ', ', array_filter( $cleaned, 'strlen' ) );
		}

		return sanitize_text_field( (string) $value );
	}

	private function get_field_option_label_map( $field ) {
		$options = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$map     = array();

		foreach ( $options as $option ) {
			$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
			$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;

			$map[ sanitize_text_field( (string) $option_value ) ] = sanitize_text_field( (string) $option_label );
		}

		return $map;
	}

	private function map_option_value_to_label( $value, $field ) {
		$option_map = $this->get_field_option_label_map( $field );
		$value      = sanitize_text_field( (string) $value );

		return isset( $option_map[ $value ] ) ? $option_map[ $value ] : $value;
	}

	private function field_type_uses_options( $field_type ) {
		return in_array( $field_type, array( 'checkboxes', 'multiple_choice', 'question', 'select' ), true );
	}

	private function get_client_ip_address() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = array_map( 'trim', explode( ',', $value ) );

			foreach ( $parts as $part ) {
				if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
					return $part;
				}
			}
		}

		return '';
	}

	private function parse_user_agent( $user_agent ) {
		$user_agent = (string) $user_agent;
		$browser    = 'Unknown';
		$os         = 'Unknown';
		$device     = 'Desktop';

		if ( preg_match( '/Edg\//i', $user_agent ) ) {
			$browser = 'Edge';
		} elseif ( preg_match( '/Chrome\//i', $user_agent ) && ! preg_match( '/Chromium|OPR\//i', $user_agent ) ) {
			$browser = 'Chrome';
		} elseif ( preg_match( '/Safari\//i', $user_agent ) && ! preg_match( '/Chrome\//i', $user_agent ) ) {
			$browser = 'Safari';
		} elseif ( preg_match( '/Firefox\//i', $user_agent ) ) {
			$browser = 'Firefox';
		}

		if ( preg_match( '/Windows NT/i', $user_agent ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/Mac OS X/i', $user_agent ) && ! preg_match( '/iPhone|iPad/i', $user_agent ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/iPhone|iPad/i', $user_agent ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/Android/i', $user_agent ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/Linux/i', $user_agent ) ) {
			$os = 'Linux';
		}

		if ( preg_match( '/iPad|Tablet/i', $user_agent ) ) {
			$device = 'Tablet';
		} elseif ( preg_match( '/Mobile|iPhone|Android/i', $user_agent ) ) {
			$device = 'Mobile';
		}

		return array(
			'device_type' => $device,
			'browser'     => $browser,
			'os'          => $os,
		);
	}

	private function get_submission_meta() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$parsed     = $this->parse_user_agent( $user_agent );

		return array(
			'label'       => 'Submission Details',
			'type'        => 'meta',
			'ip_address'  => $this->get_client_ip_address(),
			'device_type' => $parsed['device_type'],
			'browser'     => $parsed['browser'],
			'os'          => $parsed['os'],
			'user_agent'  => $user_agent,
			'page_url'    => isset( $_POST['ajf_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['ajf_page_url'] ) ) : ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '' ),
			'referrer'    => isset( $_POST['ajf_referrer'] ) ? esc_url_raw( wp_unslash( $_POST['ajf_referrer'] ) ) : ( isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' ),
			'utm_source'  => isset( $_POST['ajf_utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_utm_source'] ) ) : '',
			'utm_medium'  => isset( $_POST['ajf_utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_utm_medium'] ) ) : '',
			'utm_campaign'=> isset( $_POST['ajf_utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_utm_campaign'] ) ) : '',
			'screen_size' => isset( $_POST['ajf_screen_size'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_screen_size'] ) ) : '',
			'timezone'    => isset( $_POST['ajf_timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_timezone'] ) ) : '',
			'language'    => isset( $_POST['ajf_language'] ) ? sanitize_text_field( wp_unslash( $_POST['ajf_language'] ) ) : '',
			'submitted_at'=> current_time( 'mysql' ),
		);
	}

	private function render_tracking_inputs() {
		ob_start();
		?>
		<input type="hidden" name="ajf_page_url" value="">
		<input type="hidden" name="ajf_referrer" value="">
		<input type="hidden" name="ajf_utm_source" value="">
		<input type="hidden" name="ajf_utm_medium" value="">
		<input type="hidden" name="ajf_utm_campaign" value="">
		<input type="hidden" name="ajf_screen_size" value="">
		<input type="hidden" name="ajf_timezone" value="">
		<input type="hidden" name="ajf_language" value="">
		<script>
		(function(){
			function fillTracking(form){
				if (!form || form.dataset.ajformsTrackingReady) {
					return;
				}
				form.dataset.ajformsTrackingReady = '1';
				var params = new URLSearchParams(window.location.search || '');
				var values = {
					ajf_page_url: window.location.href || '',
					ajf_referrer: document.referrer || '',
					ajf_utm_source: params.get('utm_source') || '',
					ajf_utm_medium: params.get('utm_medium') || '',
					ajf_utm_campaign: params.get('utm_campaign') || '',
					ajf_screen_size: window.screen ? window.screen.width + 'x' + window.screen.height : '',
					ajf_timezone: Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone || '' : '',
					ajf_language: navigator.language || ''
				};
				Object.keys(values).forEach(function(name){
					var input = form.querySelector('input[name="' + name + '"]');
					if (input) {
						input.value = values[name];
					}
				});
			}
			document.querySelectorAll('form.ajforms-frontend-form').forEach(fillTracking);
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	private function get_notification_recipients( $settings ) {
		$recipient_string = isset( $settings['notification_email'] ) ? (string) $settings['notification_email'] : '';
		$pieces           = preg_split( '/[\s,]+/', $recipient_string );
		$emails           = array();

		if ( is_array( $pieces ) ) {
			foreach ( $pieces as $piece ) {
				$email = sanitize_email( $piece );
				if ( '' !== $email && is_email( $email ) ) {
					$emails[] = $email;
				}
			}
		}

		if ( empty( $emails ) ) {
			$default_email = sanitize_email( get_option( 'admin_email' ) );
			if ( '' !== $default_email && is_email( $default_email ) ) {
				$emails[] = $default_email;
			}
		}

		return array_values( array_unique( $emails ) );
	}
	private function replace_template_tags( $template, $form, $lead_data ) {
		$replacements = array(
			'{form_title}'        => $form->title,
			'{submission_count}'  => '1',
			'{submitted_at}'      => isset( $lead_data['_meta']['submitted_at'] ) ? $lead_data['_meta']['submitted_at'] : current_time( 'mysql' ),
		);

		// Add {submission_fields} tag
		$replacements['{submission_fields}'] = $this->build_submission_fields_text( $lead_data );
		$replacements['{submission_details}'] = $this->build_submission_meta_text( $lead_data );
		$replacements['{submission_table}'] = $this->build_submission_table_html( $lead_data );
		$replacements['{submission_details_table}'] = $this->build_submission_meta_table_html( $lead_data );

		$field_index = 1;
		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( 0 === strpos( (string) $field_id, '_' ) ) {
				continue;
			}

			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';
			
			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			$replacements[ '{field_' . $field_index . '}' ] = $value;
			$replacements[ '{' . $field_id . '}' ] = $value;

			if ( ! empty( $field['field_name'] ) ) {
				$replacements[ '{' . sanitize_key( $field['field_name'] ) . '}' ] = $value;
			}

			$field_index++;
		}

		return strtr( $template, $replacements );
	}


	private function get_reply_to_header( $lead_data ) {
		foreach ( $lead_data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( 0 === strpos( (string) $field_id, '_' ) ) {
				continue;
			}

			if ( empty( $field['type'] ) || 'email' !== $field['type'] || empty( $field['value'] ) ) {
				continue;
			}

			$email = sanitize_email( (string) $field['value'] );
			if ( '' !== $email && is_email( $email ) ) {
				return 'Reply-To: ' . $email;
			}
		}

		return '';
	}

	private function get_notification_reply_to_header( $settings, $form, $lead_data ) {
		if ( ! empty( $settings['notification_reply_to'] ) ) {
			$reply_to = $this->replace_template_tags( (string) $settings['notification_reply_to'], $form, $lead_data );
			$reply_to = sanitize_email( $reply_to );

			if ( '' !== $reply_to && is_email( $reply_to ) ) {
				return 'Reply-To: ' . $reply_to;
			}
		}

		return $this->get_reply_to_header( $lead_data );
	}

	private function build_submission_fields_text( $lead_data ) {
		$lines = array();

		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = ! empty( $field['label'] ) ? sanitize_text_field( $field['label'] ) : sanitize_text_field( (string) $field_id );
			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';

			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			$lines[] = $label . ': ' . ( '' !== $value ? $value : '-' );
		}

		return implode( "\n", $lines );
	}

	private function build_submission_meta_text( $lead_data ) {
		$meta = isset( $lead_data['_meta'] ) && is_array( $lead_data['_meta'] ) ? $lead_data['_meta'] : array();
		if ( empty( $meta ) ) {
			return '';
		}

		$labels = array(
			'ip_address'   => 'IP Address',
			'device_type'  => 'Device',
			'browser'      => 'Browser',
			'os'           => 'Operating System',
			'page_url'     => 'Page URL',
			'referrer'     => 'Referrer',
			'utm_source'   => 'UTM Source',
			'utm_medium'   => 'UTM Medium',
			'utm_campaign' => 'UTM Campaign',
			'screen_size'  => 'Screen Size',
			'timezone'     => 'Timezone',
			'language'     => 'Language',
			'submitted_at' => 'Submitted',
			'user_agent'   => 'User Agent',
		);

		$lines = array();
		foreach ( $labels as $key => $label ) {
			if ( empty( $meta[ $key ] ) ) {
				continue;
			}

			$lines[] = $label . ': ' . sanitize_text_field( (string) $meta[ $key ] );
		}

		return implode( "\n", $lines );
	}

	private function build_submission_table_html( $lead_data ) {
		$rows = '';

		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) || 0 === strpos( (string) $field_id, '_' ) ) {
				continue;
			}

			$label = ! empty( $field['label'] ) ? sanitize_text_field( $field['label'] ) : sanitize_text_field( (string) $field_id );
			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';

			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:10px;border:1px solid #d9e2ec;background:#f8fafc;width:38%;">' . esc_html( $label ) . '</th><td style="padding:10px;border:1px solid #d9e2ec;">' . esc_html( '' !== $value ? $value : '-' ) . '</td></tr>';
		}

		return '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:14px 0;">' . $rows . '</table>';
	}

	private function build_submission_meta_table_html( $lead_data ) {
		$meta = isset( $lead_data['_meta'] ) && is_array( $lead_data['_meta'] ) ? $lead_data['_meta'] : array();
		if ( empty( $meta ) ) {
			return '';
		}

		$labels = array(
			'ip_address'   => 'IP Address',
			'device_type'  => 'Device',
			'browser'      => 'Browser',
			'os'           => 'Operating System',
			'page_url'     => 'Page URL',
			'referrer'     => 'Referrer',
			'utm_source'   => 'UTM Source',
			'utm_medium'   => 'UTM Medium',
			'utm_campaign' => 'UTM Campaign',
			'screen_size'  => 'Screen Size',
			'timezone'     => 'Timezone',
			'language'     => 'Language',
			'submitted_at' => 'Submitted',
			'user_agent'   => 'User Agent',
		);

		$rows = '';
		foreach ( $labels as $key => $label ) {
			if ( empty( $meta[ $key ] ) ) {
				continue;
			}

			$rows .= '<tr><th style="text-align:left;vertical-align:top;padding:8px;border:1px solid #d9e2ec;background:#f8fafc;width:38%;">' . esc_html( $label ) . '</th><td style="padding:8px;border:1px solid #d9e2ec;">' . esc_html( sanitize_text_field( (string) $meta[ $key ] ) ) . '</td></tr>';
		}

		return '' === $rows ? '' : '<h3 style="margin:22px 0 8px;">Submission Details</h3><table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:8px 0;">' . $rows . '</table>';
	}

	private function maybe_create_asana_task( $form, $lead_data, $settings ) {
		if ( empty( $settings['asana_task_enabled'] ) ) {
			return;
		}

		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		if ( empty( $plugin_settings['asana_enabled'] ) || empty( $plugin_settings['asana_personal_access_token'] ) ) {
			return;
		}

		$workspace_gid = ! empty( $plugin_settings['asana_workspace_gid'] ) ? sanitize_text_field( $plugin_settings['asana_workspace_gid'] ) : '';
		if ( '' === $workspace_gid ) {
			return;
		}

		$project_gid = ! empty( $settings['asana_project_gid'] ) ? sanitize_text_field( $settings['asana_project_gid'] ) : '';
		if ( '' === $project_gid && ! empty( $plugin_settings['asana_project_gid'] ) ) {
			$project_gid = sanitize_text_field( $plugin_settings['asana_project_gid'] );
		}

		$task_name_template = ! empty( $settings['asana_task_name'] ) ? (string) $settings['asana_task_name'] : 'New form submission: {form_title}';
		$task_notes_template = ! empty( $settings['asana_task_notes'] ) ? (string) $settings['asana_task_notes'] : "Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}";

		$task_name = $this->replace_template_tags( $task_name_template, $form, $lead_data );
		$task_notes = $this->replace_template_tags( $task_notes_template, $form, $lead_data );

		$request_data = array(
			'name'      => sanitize_text_field( $task_name ),
			'notes'     => $task_notes,
			'workspace' => $workspace_gid,
		);

		if ( ! empty( $settings['asana_assignee_gid'] ) ) {
			$request_data['assignee'] = sanitize_text_field( $settings['asana_assignee_gid'] );
		}

		if ( ! empty( $settings['asana_due_date'] ) && 'today' === $settings['asana_due_date'] ) {
			$request_data['due_on'] = current_time( 'Y-m-d' );
		}

		if ( '' !== $project_gid ) {
			$request_data['projects'] = array( $project_gid );
		}

		$response = wp_remote_post(
			'https://app.asana.com/api/1.0/tasks',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . sanitize_text_field( $plugin_settings['asana_personal_access_token'] ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'data' => $request_data,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( 'AJ Core Asana task creation failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			error_log( 'AJ Core Asana task creation failed with status ' . $response_code . ': ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	private function send_form_notification( $form, $lead_data, $settings ) {
		if ( empty( $settings['notifications_enabled'] ) ) {
			return;
		}

		$recipients = $this->get_notification_recipients( $settings );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject_template = ! empty( $settings['notification_subject'] ) ? (string) $settings['notification_subject'] : 'New submission for {form_title}';
		$subject          = $this->replace_template_tags( $subject_template, $form, $lead_data );
		$subject          = sanitize_text_field( $subject );

		$body_template = ! empty( $settings['notification_body'] ) ? (string) $settings['notification_body'] : "{submission_table}{submission_details_table}";
		$body          = $this->replace_template_tags( $body_template, $form, $lead_data );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from_name = ! empty( $settings['notification_from_name'] ) ? sanitize_text_field( $this->replace_template_tags( (string) $settings['notification_from_name'], $form, $lead_data ) ) : '';
		$from_email = ! empty( $settings['notification_from_email'] ) ? sanitize_email( $settings['notification_from_email'] ) : '';
		$reply_to = $this->get_notification_reply_to_header( $settings, $form, $lead_data );
		$attachments = array();

		foreach ( $lead_data as $field ) {
			if ( ! is_array( $field ) || empty( $field['file_path'] ) ) {
				continue;
			}

			$attachments[] = $field['file_path'];
		}

		if ( '' !== $reply_to ) {
			$headers[] = $reply_to;
		}

		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = 'From: ' . ( '' !== $from_name ? $from_name . ' ' : '' ) . '<' . $from_email . '>';
		}

		wp_mail( $recipients, $subject, wp_kses_post( $body ), $headers, $attachments );
	}

	private function get_rule_field_value( $field_key, $lead_data ) {
		foreach ( $lead_data as $field_id => $field ) {
			if ( 0 === strpos( (string) $field_id, '_' ) || ! is_array( $field ) ) {
				continue;
			}

			$field_name = isset( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : '';
			if ( $field_key === $field_id || $field_key === $field_name ) {
				return isset( $field['value'] ) ? $field['value'] : '';
			}
		}

		return '';
	}

	private function rule_value_matches_condition( $value, $condition ) {
		$operator = isset( $condition['operator'] ) ? sanitize_key( $condition['operator'] ) : 'equals';
		$expected = isset( $condition['value'] ) ? sanitize_text_field( (string) $condition['value'] ) : '';
		$values   = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array( sanitize_text_field( (string) $value ) );
		$joined   = implode( ', ', array_filter( $values, 'strlen' ) );
		$is_empty = '' === $joined;

		switch ( $operator ) {
			case 'not_equals':
				return ! in_array( $expected, $values, true );
			case 'contains':
				foreach ( $values as $single_value ) {
					if ( false !== stripos( $single_value, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'not_contains':
				foreach ( $values as $single_value ) {
					if ( false !== stripos( $single_value, $expected ) ) {
						return false;
					}
				}
				return true;
			case 'is_empty':
				return $is_empty;
			case 'is_not_empty':
				return ! $is_empty;
			case 'equals':
			default:
				return in_array( $expected, $values, true );
		}
	}

	private function evaluate_rule_conditions( $rule, $lead_data ) {
		if ( ( array_key_exists( 'enabled', $rule ) && empty( $rule['enabled'] ) ) || empty( $rule['conditions'] ) || ! is_array( $rule['conditions'] ) ) {
			return false;
		}

		$logic   = isset( $rule['logic'] ) && 'OR' === strtoupper( (string) $rule['logic'] ) ? 'OR' : 'AND';
		$matches = array();

		foreach ( $rule['conditions'] as $condition ) {
			if ( ! is_array( $condition ) || empty( $condition['field'] ) ) {
				continue;
			}

			$value     = $this->get_rule_field_value( sanitize_key( $condition['field'] ), $lead_data );
			$matches[] = $this->rule_value_matches_condition( $value, $condition );
		}

		if ( empty( $matches ) ) {
			return false;
		}

		return 'OR' === $logic ? in_array( true, $matches, true ) : ! in_array( false, $matches, true );
	}

	private function trigger_rule_webhook( $url, $form, $lead_data ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'form_id'    => absint( $form->id ),
						'form_title' => $form->title,
						'lead_data'  => $lead_data,
						'submitted_at' => current_time( 'mysql' ),
					)
				),
			)
		);
	}

	private function get_default_confirmation_result( $settings ) {
		$redirect_url = '';
		if ( ! empty( $settings['confirmation_type'] ) && 'redirect' === $settings['confirmation_type'] && ! empty( $settings['redirect_url'] ) ) {
			$redirect_url = esc_url_raw( $settings['redirect_url'] );
		}

		return array(
			'message'      => '' !== $redirect_url ? __( 'Redirecting...', 'ajforms' ) : ( ! empty( $settings['success_message'] ) ? $settings['success_message'] : 'Form submitted successfully.' ),
			'redirect_url' => $redirect_url,
		);
	}

	private function evaluate_confirmation_rules( $form, $lead_data, $settings ) {
		if ( isset( $settings['confirmation_mode'] ) && 'conditional' !== $settings['confirmation_mode'] ) {
			return $this->get_default_confirmation_result( $settings );
		}

		$result = array(
			'message'      => ! empty( $settings['success_message'] ) ? $settings['success_message'] : 'Form submitted successfully.',
			'redirect_url' => '',
		);
		$rules  = ! empty( $settings['confirmation_rules'] ) && is_array( $settings['confirmation_rules'] ) ? $settings['confirmation_rules'] : array();

		usort(
			$rules,
			function ( $a, $b ) {
				return intval( isset( $a['priority'] ) ? $a['priority'] : 0 ) <=> intval( isset( $b['priority'] ) ? $b['priority'] : 0 );
			}
		);

		$matched = false;
		foreach ( $rules as $rule ) {
			if ( ! $this->evaluate_rule_conditions( $rule, $lead_data ) ) {
				continue;
			}

			$matched = true;
			foreach ( isset( $rule['actions'] ) && is_array( $rule['actions'] ) ? $rule['actions'] : array() as $action ) {
				if ( ! is_array( $action ) || empty( $action['type'] ) ) {
					continue;
				}

				if ( 'show_message' === $action['type'] ) {
					$result['message'] = ! empty( $action['message'] ) ? $this->replace_template_tags( (string) $action['message'], $form, $lead_data ) : $result['message'];
				} elseif ( 'redirect' === $action['type'] ) {
					$result['redirect_url'] = ! empty( $action['url'] ) ? esc_url_raw( $this->replace_template_tags( (string) $action['url'], $form, $lead_data ) ) : $result['redirect_url'];
					$result['message']      = __( 'Redirecting...', 'ajforms' );
				} elseif ( 'webhook' === $action['type'] ) {
					$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();
					$webhook_url     = ! empty( $action['url'] ) ? (string) $action['url'] : ( isset( $plugin_settings['webhook_url'] ) ? (string) $plugin_settings['webhook_url'] : '' );
					$this->trigger_rule_webhook( $this->replace_template_tags( $webhook_url, $form, $lead_data ), $form, $lead_data );
				}
			}

			if ( ! empty( $rule['stop_processing'] ) ) {
				break;
			}
		}

		return $matched ? $result : $this->get_default_confirmation_result( $settings );
	}

	private function handle_form_submission( $form, $fields, $settings ) {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return array(
				'submitted' => false,
				'success'   => false,
				'message'   => '',
			);
		}

		$form_id = absint( $form->id );
		$posted_form_id = isset( $_POST['ajf_form_id'] ) ? absint( wp_unslash( $_POST['ajf_form_id'] ) ) : 0;
		if ( $posted_form_id !== $form_id ) {
			return array(
				'submitted' => false,
				'success'   => false,
				'message'   => '',
			);
		}

		$nonce = isset( $_POST['ajf_form_nonce'] ) ? wp_unslash( $_POST['ajf_form_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ajf_submit_form_' . $form_id ) ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => 'Security check failed.',
			);
		}

		if ( $this->is_honeypot_enabled() ) {
			$honeypot_field_name  = $this->get_honeypot_field_name( $form_id );
			$honeypot_field_value = isset( $_POST[ $honeypot_field_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $honeypot_field_name ] ) ) : '';

			if ( '' !== $honeypot_field_value ) {
				return array(
					'submitted' => true,
					'success'   => false,
					'message'   => __( 'Spam check failed. Please try again.', 'ajforms' ),
				);
			}
		}

		if ( $this->should_render_challenge_provider() ) {
			$challenge_check = $this->validate_challenge_provider_token();

			if ( is_wp_error( $challenge_check ) ) {
				return array(
					'submitted' => true,
					'success'   => false,
					'message'   => $challenge_check->get_error_message(),
				);
			}
		}

		$stripe_payment_result = $this->validate_stripe_payment_submission( $form, $settings );
		if ( is_wp_error( $stripe_payment_result ) ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => $stripe_payment_result->get_error_message(),
			);
		}

		$lead_data = array();
		$errors    = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_id    = ! empty( $field['id'] ) ? $field['id'] : '';
			$field_type  = ! empty( $field['type'] ) ? $field['type'] : 'text';
			$field_label = ! empty( $field['label'] ) ? $field['label'] : $field_id;
			$field_name  = ! empty( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : sanitize_key( $field_label );
			$required    = ! empty( $field['required'] );

			if ( ! $field_id || 'separator' === $field_type ) {
				continue;
			}

			if ( 'file' === $field_type ) {
				$has_upload = isset( $_FILES[ $field_id ] ) && ! empty( $_FILES[ $field_id ]['name'] );

				if ( $required && ! $has_upload ) {
					$errors[] = sprintf( '%s is required.', $field_label );
					continue;
				}

				if ( $has_upload ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';

					$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? explode( ',', (string) $field['accepted_file_types'] ) : array( '.pdf', '.jpg', '.jpeg', '.png', '.gif', '.webp' );
					$accepted_file_types = array_map( 'trim', $accepted_file_types );
					$allowed_mimes       = array(
						'pdf'  => 'application/pdf',
						'jpg|jpeg|jpe' => 'image/jpeg',
						'png'  => 'image/png',
						'gif'  => 'image/gif',
						'webp' => 'image/webp',
					);

					$uploaded = wp_handle_upload(
						$_FILES[ $field_id ],
						array(
							'test_form' => false,
							'mimes'     => $allowed_mimes,
						)
					);

					if ( isset( $uploaded['error'] ) ) {
						$errors[] = sprintf( '%s upload failed.', $field_label );
						continue;
					}

					$file_url = isset( $uploaded['url'] ) ? esc_url_raw( $uploaded['url'] ) : '';
					$file_path = isset( $uploaded['file'] ) ? $uploaded['file'] : '';
					$file_name = isset( $_FILES[ $field_id ]['name'] ) ? sanitize_file_name( wp_unslash( $_FILES[ $field_id ]['name'] ) ) : '';
					$file_ext  = strtolower( strrchr( $file_name, '.' ) );

					if ( ! empty( $accepted_file_types ) && ! in_array( $file_ext, $accepted_file_types, true ) ) {
						$errors[] = sprintf( '%s file type is not allowed.', $field_label );
						continue;
					}

					$lead_data[ $field_id ] = array(
						'label'         => $field_label,
						'field_name'    => $field_name,
						'type'          => $field_type,
						'value'         => $file_url,
						'file_name'     => $file_name,
						'file_path'     => $file_path,
						'accepted_types'=> implode( ',', $accepted_file_types ),
					);
				}

				continue;
			}

			$value = isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : null;

			if ( is_array( $value ) ) {
				$clean_value = array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
				if ( $this->field_type_uses_options( $field_type ) ) {
					$clean_value = array_map(
						function ( $selected_value ) use ( $field ) {
							return $this->map_option_value_to_label( $selected_value, $field );
						},
						$clean_value
					);
				}
				$is_empty    = empty( $clean_value );
			} else {
				switch ( $field_type ) {
					case 'email':
						$clean_value = sanitize_email( $value );
						break;
					case 'url':
						$clean_value = esc_url_raw( $value );
						break;
					case 'textarea':
						$clean_value = sanitize_textarea_field( $value );
						break;
					default:
						$clean_value = sanitize_text_field( $value );
						break;
				}

				if ( '' !== $clean_value && $this->field_type_uses_options( $field_type ) ) {
					$clean_value = $this->map_option_value_to_label( $clean_value, $field );
				}

				$is_empty = '' === $clean_value;
			}

			if ( $required && $is_empty ) {
				$errors[] = sprintf( '%s is required.', $field_label );
			}

			$lead_data[ $field_id ] = array(
				'label'      => $field_label,
				'field_name' => $field_name,
				'type'       => $field_type,
				'value'      => $clean_value,
			);
		}

		if ( ! empty( $errors ) ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => implode( ' ', $errors ),
			);
		}

		if ( is_array( $stripe_payment_result ) ) {
			$lead_data['_stripe_payment'] = array(
				'label'             => 'Stripe Payment',
				'type'              => 'payment',
				'value'             => sprintf(
					/* translators: 1: amount 2: currency */
					__( 'Paid %1$s %2$s', 'ajforms' ),
					number_format_i18n( (float) $stripe_payment_result['amount'], 2 ),
					$stripe_payment_result['currency']
				),
				'payment_intent_id' => $stripe_payment_result['payment_intent_id'],
				'description'       => $stripe_payment_result['description'],
				'price_id'          => $stripe_payment_result['price_id'],
				'product_id'        => $stripe_payment_result['product_id'],
			);
		}

		$submission_meta = $this->get_submission_meta();
		$lead_data['_meta'] = $submission_meta;

		global $wpdb;
		$leads_table = $this->get_leads_table();

		$inserted = $wpdb->insert(
			$leads_table,
			array(
				'form_id'    => $form_id,
				'lead_data'  => wp_json_encode( $lead_data ),
				'status'     => 'unread',
				'ip_address' => isset( $submission_meta['ip_address'] ) ? $submission_meta['ip_address'] : '',
				'source_url' => isset( $submission_meta['page_url'] ) ? $submission_meta['page_url'] : '',
				'user_agent' => isset( $submission_meta['user_agent'] ) ? $submission_meta['user_agent'] : '',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return array(
				'submitted' => true,
				'success'   => false,
				'message'   => 'Unable to save your submission.',
			);
		}

		$this->send_form_notification( $form, $lead_data, $settings );
		$this->maybe_create_asana_task( $form, $lead_data, $settings );

		$confirmation_result = $this->evaluate_confirmation_rules( $form, $lead_data, $settings );
		$redirect_url        = ! empty( $confirmation_result['redirect_url'] ) ? esc_url_raw( $confirmation_result['redirect_url'] ) : '';

		if ( '' !== $redirect_url && ! headers_sent() ) {
			wp_redirect( $redirect_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		return array(
			'submitted'    => true,
			'success'      => true,
			'message'      => ! empty( $confirmation_result['message'] ) ? $confirmation_result['message'] : 'Form submitted successfully.',
			'redirect_url' => $redirect_url,
		);
	}

	private function render_submission_redirect( $submission_result ) {
		if ( empty( $submission_result['success'] ) || empty( $submission_result['redirect_url'] ) ) {
			return '';
		}

		$redirect_url = esc_url_raw( $submission_result['redirect_url'] );

		ob_start();
		?>
		<script>
		window.location.replace(<?php echo wp_json_encode( $redirect_url ); ?>);
		</script>
		<noscript>
			<p><a href="<?php echo esc_url( $redirect_url ); ?>"><?php esc_html_e( 'Continue', 'ajforms' ); ?></a></p>
		</noscript>
		<?php

		return ob_get_clean();
	}

	private function get_frontend_field_data( $field ) {
		$field_id            = ! empty( $field['id'] ) ? $field['id'] : 'field_' . wp_generate_uuid4();
		$field_type          = ! empty( $field['type'] ) ? $field['type'] : 'text';
		$field_label         = ! empty( $field['label'] ) ? $field['label'] : ucfirst( $field_type );
		$default_value       = ! empty( $field['default_value'] ) ? $field['default_value'] : '';
		$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? $field['accepted_file_types'] : '.pdf,.jpg,.jpeg,.png,.gif,.webp';
		$options             = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		if ( 'question' === $field_type && empty( $options ) ) {
			$options = array(
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

		return array(
			'id'                  => $field_id,
			'type'                => $field_type,
			'label'               => $field_label,
			'field_name'          => ! empty( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : sanitize_key( $field_label ),
			'required'            => ! empty( $field['required'] ),
			'placeholder'         => ! empty( $field['placeholder'] ) ? $field['placeholder'] : '',
			'options'             => $options,
			'help_text'           => ! empty( $field['help_text'] ) ? $field['help_text'] : '',
			'default_value'       => $default_value,
			'css_class'           => ! empty( $field['css_class'] ) ? $field['css_class'] : '',
			'conversational'      => array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( ! empty( $field['conversation_step'] ) ? 'final_contact' !== $field['conversation_step'] : 'question' === $field_type ),
			'accepted_file_types' => $accepted_file_types,
			'posted_value'        => isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : $default_value,
		);
	}

	private function render_frontend_field_control( $field_data ) {
		$field_id            = $field_data['id'];
		$field_type          = $field_data['type'];
		$placeholder         = $field_data['placeholder'];
		$required            = $field_data['required'];
		$options             = $field_data['options'];
		$posted_value        = $field_data['posted_value'];
		$accepted_file_types = $field_data['accepted_file_types'];
		$control_style       = 'width:100%;max-width:780px;min-height:54px;padding:14px 16px;border-radius:calc(var(--ajforms-radius) - 6px);background:var(--ajforms-input-bg);border:2px solid var(--ajforms-input-border);color:var(--ajforms-text);font-size:18px;line-height:1.35;box-sizing:border-box;';

		ob_start();
		if ( 'textarea' === $field_type ) :
			?>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>min-height:110px;"><?php echo esc_textarea( is_string( $posted_value ) ? $posted_value : '' ); ?></textarea>
			<?php
		elseif ( 'select' === $field_type ) :
			?>
			<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>">
				<option value=""><?php echo esc_html( $placeholder ?: 'Select an option' ); ?></option>
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $posted_value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php
		elseif ( 'checkboxes' === $field_type ) :
			$posted_array = is_array( $posted_value ) ? $posted_value : array();
			?>
			<div id="<?php echo esc_attr( $field_id ); ?>" class="ajforms-choice-list ajforms-checkbox-list" style="display:grid;gap:10px;max-width:900px;">
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<label style="display:flex;align-items:center;gap:12px;color:var(--ajforms-text);font-size:18px;line-height:1.35;font-weight:600;cursor:pointer;">
						<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>[]" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( in_array( $option_value, $posted_array, true ) ); ?> style="width:22px;height:22px;flex:0 0 auto;accent-color:var(--ajforms-primary);">
						<span><?php echo esc_html( $option_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
		elseif ( 'question' === $field_type || 'multiple_choice' === $field_type ) :
			?>
			<div id="<?php echo esc_attr( $field_id ); ?>" class="<?php echo 'question' === $field_type ? 'ajforms-question-options' : ''; ?>" style="<?php echo 'question' === $field_type ? 'display:grid;gap:14px;' : ''; ?>">
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<label class="<?php echo 'question' === $field_type ? 'ajforms-question-option' : ''; ?>" style="<?php echo 'question' === $field_type ? 'display:flex;align-items:center;gap:16px;padding:24px 26px;border:2px solid var(--ajforms-input-border);border-radius:calc(var(--ajforms-radius) + 6px);background:var(--ajforms-input-bg);color:var(--ajforms-text);font-size:28px;line-height:1.15;font-weight:800;cursor:pointer;' : 'display:flex;align-items:center;gap:12px;margin-bottom:10px;color:var(--ajforms-text);font-size:18px;font-weight:600;cursor:pointer;'; ?>">
						<input type="radio" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( $posted_value, $option_value ); ?> <?php echo $required ? 'required' : ''; ?> style="<?php echo 'question' === $field_type ? '' : 'width:22px;height:22px;flex:0 0 auto;accent-color:var(--ajforms-primary);'; ?>">
						<span><?php echo esc_html( $option_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
		elseif ( 'separator' === $field_type ) :
			?>
			<hr>
			<?php
		elseif ( 'file' === $field_type ) :
			?>
			<input type="file" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" accept="<?php echo esc_attr( $accepted_file_types ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>">
			<?php
		else :
			$input_type_map = array(
				'email'   => 'email',
				'url'     => 'url',
				'number'  => 'number',
				'phone'   => 'tel',
				'tel'     => 'tel',
				'date'    => 'date',
				'address' => 'text',
				'text'    => 'text',
			);
			$input_type = isset( $input_type_map[ $field_type ] ) ? $input_type_map[ $field_type ] : 'text';
			?>
			<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( is_string( $posted_value ) ? $posted_value : '' ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>">
			<?php
		endif;

		return ob_get_clean();
	}

	private function render_conversational_form( $form, $fields, $settings, $submission_result, $wrapper_style, $form_theme, $challenge_enabled, $challenge_config ) {
		$answerable_fields = array_values(
			array_filter(
				$fields,
				function ( $field ) {
					return is_array( $field ) && ( empty( $field['type'] ) || 'separator' !== $field['type'] );
				}
			)
		);
		$question_fields = array_values(
			array_filter(
				$answerable_fields,
				function ( $field ) {
					return array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( empty( $field['conversation_step'] ) || 'final_contact' !== $field['conversation_step'] );
				}
			)
		);
		$contact_fields = array_values(
			array_filter(
				$answerable_fields,
				function ( $field ) {
					return array_key_exists( 'conversational', $field ) ? empty( $field['conversational'] ) : ( ! empty( $field['conversation_step'] ) && 'final_contact' === $field['conversation_step'] );
				}
			)
		);

		$total_steps = count( $answerable_fields );
		if ( ! empty( $contact_fields ) ) {
			$total_steps = count( $question_fields ) + 1;
		}
		if ( 0 === $total_steps ) {
			return '<p>This form has no questions.</p>';
		}

		$step_counter = 0;

		ob_start();
		?>
		<?php if ( $challenge_enabled ) : ?>
			<script src="<?php echo esc_url( $challenge_config['script_url'] ); ?>" async defer></script>
		<?php endif; ?>
		<form class="ajforms-frontend-form ajforms-conversational-form ajforms-theme-<?php echo esc_attr( $form_theme ); ?>" method="post" enctype="multipart/form-data" style="<?php echo esc_attr( $wrapper_style ); ?>margin:0 auto;padding:28px;border-radius:var(--ajforms-radius);background:var(--ajforms-bg);border:1px solid #dfe6ee;box-shadow:0 20px 45px rgba(18,52,77,.08);">
			<style>
				.ajforms-frontend-form{margin-top:0!important}
				.ajforms-frontend-form input:focus,.ajforms-frontend-form textarea:focus,.ajforms-frontend-form select:focus{outline:0;border-color:var(--ajforms-primary)!important;box-shadow:0 0 0 4px rgba(15,122,198,.12)}
				.ajforms-conversational-form .ajforms-question-option input[type="radio"]{width:28px;height:28px;flex:0 0 auto;accent-color:var(--ajforms-primary)}
				.ajforms-conversational-form .ajforms-question-option:has(input:checked){border-color:var(--ajforms-primary);box-shadow:0 0 0 4px rgba(15,122,198,.14)}
				.ajforms-conversational-form .ajforms-conversation-contact-step .ajforms-field{margin-bottom:16px!important}
				.ajforms-conversational-form .ajforms-conversation-contact-step label{font-size:16px!important}
				.ajforms-conversational-form .ajforms-checkbox-list label:hover{color:var(--ajforms-primary)}
				.ajforms-conversational-form .ajforms-choice-list input:checked + span{color:var(--ajforms-primary)}
			</style>
			<input type="hidden" name="ajf_form_id" value="<?php echo esc_attr( $form->id ); ?>">
			<?php wp_nonce_field( 'ajf_submit_form_' . absint( $form->id ), 'ajf_form_nonce' ); ?>
			<?php echo $this->render_tracking_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $this->is_honeypot_enabled() ) : ?>
				<?php $honeypot_field_name = $this->get_honeypot_field_name( $form->id ); ?>
				<div class="ajforms-honeypot" aria-hidden="true" style="position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;">
					<label for="<?php echo esc_attr( $honeypot_field_name ); ?>"><?php esc_html_e( 'Leave this field empty', 'ajforms' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $honeypot_field_name ); ?>" name="<?php echo esc_attr( $honeypot_field_name ); ?>" value="" tabindex="-1" autocomplete="off">
				</div>
			<?php endif; ?>

			<div class="ajforms-conversation-head" style="margin-bottom:22px;">
				<div style="font-size:13px;font-weight:700;color:var(--ajforms-primary);text-transform:uppercase;letter-spacing:.08em;"><?php echo esc_html( $form->title ); ?></div>
				<div class="ajforms-conversation-progress" style="height:6px;background:rgba(15,122,198,.16);border-radius:999px;margin-top:14px;overflow:hidden;">
					<span style="display:block;width:<?php echo esc_attr( 100 / $total_steps ); ?>%;height:100%;background:var(--ajforms-primary);border-radius:999px;"></span>
				</div>
			</div>

			<?php echo $this->render_submission_redirect( $submission_result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $submission_result['submitted'] ) : ?>
				<div class="ajforms-message <?php echo $submission_result['success'] ? 'success' : 'error'; ?>" style="margin-bottom:20px;padding:12px;border-radius:4px;<?php echo $submission_result['success'] ? 'background:#edfaef;color:#116329;' : 'background:#fcf0f1;color:#8a2424;'; ?>">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $submission_result['success'] ) : ?>
				<?php foreach ( $question_fields as $field ) : ?>
					<?php $field_data = $this->get_frontend_field_data( $field ); ?>
					<section
						class="ajforms-conversation-step <?php echo 'question' === $field_data['type'] ? 'ajforms-question-step' : ''; ?>"
						data-step="<?php echo esc_attr( $step_counter ); ?>"
						data-field-id="<?php echo esc_attr( $field_data['id'] ); ?>"
						data-field-type="<?php echo esc_attr( $field_data['type'] ); ?>"
						data-branch-map="<?php echo esc_attr( wp_json_encode( ! empty( $field['branch_map'] ) && is_array( $field['branch_map'] ) ? $field['branch_map'] : array() ) ); ?>"
						data-flow-rules="<?php echo esc_attr( wp_json_encode( ! empty( $field['flow_rules'] ) && is_array( $field['flow_rules'] ) ? $field['flow_rules'] : array() ) ); ?>"
						<?php echo 'question' === $field_data['type'] ? 'data-auto-advance="1"' : ''; ?>
						style="<?php echo 0 === $step_counter ? '' : 'display:none;'; ?>"
					>
						<div style="font-size:14px;color:#64748b;margin-bottom:12px;"><?php echo esc_html( sprintf( 'Question %1$d of %2$d', $step_counter + 1, $total_steps ) ); ?></div>
						<label for="<?php echo esc_attr( $field_data['id'] ); ?>" style="display:block;font-size:24px;line-height:1.25;font-weight:800;color:var(--ajforms-text);margin-bottom:16px;">
							<?php echo esc_html( $field_data['label'] ); ?>
							<?php if ( $field_data['required'] ) : ?>
								<span style="color:#d63638;">*</span>
							<?php endif; ?>
						</label>
						<?php echo $this->render_frontend_field_control( $field_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( '' !== $field_data['help_text'] ) : ?>
							<p style="margin-top:10px;color:#646970;font-size:13px;"><?php echo esc_html( $field_data['help_text'] ); ?></p>
						<?php endif; ?>
					</section>
					<?php $step_counter++; ?>
				<?php endforeach; ?>
				<?php if ( ! empty( $contact_fields ) ) : ?>
					<section class="ajforms-conversation-step ajforms-conversation-contact-step" data-step="<?php echo esc_attr( $step_counter ); ?>" data-field-id="__contact" style="<?php echo empty( $question_fields ) ? '' : 'display:none;'; ?>">
						<div style="font-size:14px;color:#64748b;margin-bottom:12px;"><?php echo esc_html( sprintf( 'Step %1$d of %2$d', $total_steps, $total_steps ) ); ?></div>
						<div style="font-size:24px;line-height:1.25;font-weight:800;color:var(--ajforms-text);margin-bottom:8px;"><?php esc_html_e( 'How can we reach you?', 'ajforms' ); ?></div>
						<p style="margin:0 0 18px;color:#64748b;"><?php esc_html_e( 'Share your contact details and we will follow up with the next step.', 'ajforms' ); ?></p>
						<?php foreach ( $contact_fields as $field ) : ?>
							<?php $field_data = $this->get_frontend_field_data( $field ); ?>
							<div class="ajforms-field <?php echo esc_attr( $field_data['css_class'] ); ?>" style="margin-bottom:18px;">
								<label for="<?php echo esc_attr( $field_data['id'] ); ?>" style="display:block;font-weight:700;color:var(--ajforms-text);margin-bottom:7px;">
									<?php echo esc_html( $field_data['label'] ); ?>
									<?php if ( $field_data['required'] ) : ?>
										<span style="color:#d63638;">*</span>
									<?php endif; ?>
								</label>
								<?php echo $this->render_frontend_field_control( $field_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php if ( '' !== $field_data['help_text'] ) : ?>
									<p style="margin-top:8px;color:#646970;font-size:13px;"><?php echo esc_html( $field_data['help_text'] ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</section>
				<?php endif; ?>

				<?php if ( $challenge_enabled ) : ?>
					<div class="ajforms-challenge-wrap ajforms-challenge-<?php echo esc_attr( $challenge_config['provider'] ); ?>" style="margin:20px 0 0;display:none;">
						<div class="<?php echo esc_attr( $challenge_config['container_class'] ); ?>" data-sitekey="<?php echo esc_attr( $challenge_config['site_key'] ); ?>"></div>
					</div>
				<?php endif; ?>

				<div class="ajforms-conversation-actions" style="display:flex;gap:14px;align-items:center;justify-content:space-between;margin-top:26px;">
					<button type="button" class="ajforms-conversation-prev" style="display:none;background:#fff;color:var(--ajforms-text);border:2px solid var(--ajforms-input-border);border-radius:calc(var(--ajforms-radius) - 4px);padding:14px 22px;font-size:17px;font-weight:800;min-height:54px;cursor:pointer;"><?php esc_html_e( 'Back', 'ajforms' ); ?></button>
					<button type="button" class="ajforms-conversation-next" style="margin-left:auto;background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:15px 24px;font-size:17px;font-weight:800;min-height:54px;cursor:pointer;"><?php esc_html_e( 'Next', 'ajforms' ); ?></button>
					<button type="submit" class="ajforms-conversation-submit" style="display:none;margin-left:auto;background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:15px 24px;font-size:17px;font-weight:800;min-height:54px;cursor:pointer;"><?php echo esc_html( ! empty( $settings['submit_text'] ) ? $settings['submit_text'] : 'Submit' ); ?></button>
				</div>
			<?php endif; ?>
		</form>
		<script>
		(function() {
			const form = document.currentScript.previousElementSibling;
			if (!form) {
				return;
			}

			const steps = Array.from(form.querySelectorAll('.ajforms-conversation-step'));
			const progress = form.querySelector('.ajforms-conversation-progress span');
			const previousButton = form.querySelector('.ajforms-conversation-prev');
			const nextButton = form.querySelector('.ajforms-conversation-next');
			const submitButton = form.querySelector('.ajforms-conversation-submit');
			const challenge = form.querySelector('.ajforms-challenge-wrap');
			const stepIndexByFieldId = {};
			const visitedSteps = new Set();
			const history = [];
			let currentStep = 0;

			steps.forEach(function(step, index) {
				if (step.dataset.fieldId) {
					stepIndexByFieldId[step.dataset.fieldId] = index;
				}
			});

			function getStepControls(step) {
				return Array.from(step.querySelectorAll('input, select, textarea, button'));
			}

			function syncEnabledControls() {
				steps.forEach(function(step, index) {
					const enabled = index === currentStep || visitedSteps.has(index);
					getStepControls(step).forEach(function(control) {
						control.disabled = !enabled;
					});
				});
			}

			function resetVisitedFromHistory() {
				visitedSteps.clear();
				history.forEach(function(stepIndex) {
					visitedSteps.add(stepIndex);
				});
			}

			function getSelectedBranchValues(step) {
				const checked = step.querySelector('input[type="radio"]:checked');
				if (checked) {
					return [checked.value];
				}

				const checkedBoxes = Array.from(step.querySelectorAll('input[type="checkbox"]:checked'));
				if (checkedBoxes.length) {
					return checkedBoxes.map(function(input) {
						return input.value;
					});
				}

				const select = step.querySelector('select');
				if (select && select.value) {
					return [select.value];
				}

				return [];
			}

			function dispatchFlowAction(step, branchValue) {
				const event = new CustomEvent('ajforms:flowAction', {
					detail: {
						form: form,
						step: step,
						fieldId: step.dataset.fieldId || '',
						value: branchValue || '',
						values: getSelectedBranchValues(step)
					}
				});

				form.dispatchEvent(event);
			}

			function flowConditionMatches(condition, values) {
				const operator = condition.operator || 'equals';
				const expected = condition.value || '';
				const hasValue = values.length > 0 && values.join('').length > 0;

				if (operator === 'is_empty') {
					return !hasValue;
				}

				if (operator === 'is_not_empty') {
					return hasValue;
				}

				if (operator === 'not_equals') {
					return !values.includes(expected);
				}

				if (operator === 'contains') {
					return values.some(function(value) {
						return String(value).includes(expected);
					});
				}

				if (operator === 'not_contains') {
					return !values.some(function(value) {
						return String(value).includes(expected);
					});
				}

				return values.includes(expected);
			}

			function evaluateFlowRules(step, values) {
				let rules = [];

				try {
					rules = step.dataset.flowRules ? JSON.parse(step.dataset.flowRules) : [];
				} catch (error) {
					rules = [];
				}

				if (!Array.isArray(rules) || !rules.length) {
					return null;
				}

				rules.sort(function(a, b) {
					return parseInt(a.priority || 0, 10) - parseInt(b.priority || 0, 10);
				});

				for (let ruleIndex = 0; ruleIndex < rules.length; ruleIndex += 1) {
					const rule = rules[ruleIndex];
					if (rule.enabled === false) {
						continue;
					}
					const conditions = Array.isArray(rule.conditions) ? rule.conditions : [];
					const logic = String(rule.logic || 'AND').toUpperCase() === 'OR' ? 'OR' : 'AND';
					const matches = conditions.map(function(condition) {
						return flowConditionMatches(condition, values);
					});
					const isMatch = logic === 'OR' ? matches.includes(true) : matches.length > 0 && !matches.includes(false);

					if (isMatch && Array.isArray(rule.actions) && rule.actions.length) {
						return rule.actions[0];
					}
				}

				return null;
			}

			function getNextStepFromBranch() {
				const step = steps[currentStep];
				let branchMap = {};

				try {
					branchMap = step.dataset.branchMap ? JSON.parse(step.dataset.branchMap) : {};
				} catch (error) {
					branchMap = {};
				}

				const branchValues = getSelectedBranchValues(step);
				const flowAction = evaluateFlowRules(step, branchValues);
				if (flowAction) {
					if (flowAction.type === 'end') {
						return '__submit';
					}

					if (flowAction.type === 'action') {
						dispatchFlowAction(step, branchValues[0] || '');
						return currentStep + 1;
					}

					if (flowAction.type === 'jump' && flowAction.target && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, flowAction.target)) {
						return stepIndexByFieldId[flowAction.target];
					}

					return currentStep + 1;
				}

				let branchValue = '';
				let target = '';

				for (let index = 0; index < branchValues.length; index += 1) {
					if (branchMap[branchValues[index]]) {
						branchValue = branchValues[index];
						target = branchMap[branchValue];
						break;
					}
				}

				if (target === '__contact' && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, '__contact')) {
					return stepIndexByFieldId.__contact;
				}

				if (target === '__end') {
					return '__submit';
				}

				if (target === '__action') {
					dispatchFlowAction(step, branchValue);
					return currentStep + 1;
				}

				if (target && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, target)) {
					return stepIndexByFieldId[target];
				}

				return currentStep + 1;
			}

			function setStep(nextStep) {
				currentStep = Math.max(0, Math.min(nextStep, steps.length - 1));
				visitedSteps.add(currentStep);

				steps.forEach(function(step, index) {
					step.style.display = index === currentStep ? '' : 'none';
				});
				syncEnabledControls();

				if (progress) {
					progress.style.width = (((currentStep + 1) / steps.length) * 100) + '%';
				}

				if (previousButton) {
					previousButton.style.display = currentStep > 0 ? '' : 'none';
				}

				const isLastStep = currentStep === steps.length - 1;
				const isAutoAdvanceStep = steps[currentStep] && steps[currentStep].dataset.autoAdvance === '1';
				if (nextButton) {
					nextButton.style.display = isLastStep || isAutoAdvanceStep ? 'none' : '';
				}
				if (submitButton) {
					submitButton.style.display = isLastStep ? '' : 'none';
				}
				if (challenge) {
					challenge.style.display = isLastStep ? '' : 'none';
				}
			}

			function currentStepIsValid() {
				const controls = Array.from(steps[currentStep].querySelectorAll('input, select, textarea'));
				for (const control of controls) {
					if (typeof control.reportValidity === 'function' && !control.reportValidity()) {
						return false;
					}
				}
				return true;
			}

			if (previousButton) {
				previousButton.addEventListener('click', function() {
					const previousStep = history.length ? history.pop() : currentStep - 1;
					resetVisitedFromHistory();
					setStep(previousStep);
				});
			}

			if (nextButton) {
				nextButton.addEventListener('click', function() {
					advanceCurrentStep();
				});
			}

			function advanceCurrentStep() {
				if (currentStepIsValid()) {
					const nextStep = getNextStepFromBranch();
					if (nextStep === '__submit' || nextStep === currentStep || nextStep >= steps.length) {
						if (typeof form.requestSubmit === 'function') {
							form.requestSubmit();
						} else {
							form.submit();
						}
						return;
					}

					history.push(currentStep);
					resetVisitedFromHistory();
					setStep(nextStep);
				}
			}

			steps.forEach(function(step) {
				if (step.dataset.autoAdvance !== '1') {
					return;
				}

				step.querySelectorAll('input[type="radio"]').forEach(function(input) {
					input.addEventListener('change', function() {
						window.setTimeout(advanceCurrentStep, 140);
					});
				});
			});

			setStep(0);
		})();
		</script>
		<?php

		return ob_get_clean();
	}

	public function render_form_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'ajforms'
		);

		$form_id = absint( $atts['id'] );
		if ( ! $form_id ) {
			return '<p>Invalid form ID.</p>';
		}

		$form = $this->get_form_by_id( $form_id );
		if ( ! $form ) {
			return '<p>Form not found.</p>';
		}

		$schema = json_decode( $form->form_schema, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return '<p>Form schema is invalid.</p>';
		}

		$normalized  = $this->normalize_schema( $schema );
		$fields      = $normalized['fields'];
		$settings    = $normalized['settings'];
		$submit_text = ! empty( $settings['submit_text'] ) ? $settings['submit_text'] : 'Submit';
		$border_radius = isset( $settings['border_radius'] ) ? min( 32, max( 0, absint( $settings['border_radius'] ) ) ) : 16;
		$background_mode = isset( $settings['background_mode'] ) ? $settings['background_mode'] : 'solid';
		$background_value = 'gradient' === $background_mode
			? 'linear-gradient(135deg, ' . ( ! empty( $settings['background_gradient_start'] ) ? $settings['background_gradient_start'] : '#ffffff' ) . ' 0%, ' . ( ! empty( $settings['background_gradient_end'] ) ? $settings['background_gradient_end'] : '#f3f7fb' ) . ' 100%)'
			: ( ! empty( $settings['background_color'] ) ? $settings['background_color'] : '#ffffff' );
		$form_theme = ! empty( $settings['form_theme'] ) ? $settings['form_theme'] : 'clean';
		$wrapper_style = sprintf(
			'--ajforms-primary:%1$s;--ajforms-text:%2$s;--ajforms-input-bg:%3$s;--ajforms-input-border:%4$s;--ajforms-radius:%5$dpx;--ajforms-bg:%6$s;',
			! empty( $settings['primary_color'] ) ? $settings['primary_color'] : '#0f7ac6',
			! empty( $settings['text_color'] ) ? $settings['text_color'] : '#1f2937',
			! empty( $settings['input_background'] ) ? $settings['input_background'] : '#ffffff',
			! empty( $settings['input_border_color'] ) ? $settings['input_border_color'] : '#d7dce3',
			$border_radius,
			$background_value
		);

		if ( empty( $fields ) ) {
			return '<p>This form has no fields.</p>';
		}

		$challenge_enabled = $this->should_render_challenge_provider();
		$challenge_config  = $this->get_spam_provider_config();
		$stripe_config     = $this->get_stripe_payment_config( $form, $settings );
		$stripe_enabled    = ! empty( $stripe_config['enabled'] );
		$stripe_nonce      = wp_create_nonce( 'ajf_stripe_payment_' . $form_id );

		$submission_result = $this->handle_form_submission( $form, $fields, $settings );
		$has_conversational_fields = ! empty(
			array_filter(
				$fields,
				function ( $field ) {
					return is_array( $field ) && ( array_key_exists( 'conversational', $field ) ? ! empty( $field['conversational'] ) : ( ! empty( $field['conversation_step'] ) ? 'final_contact' !== $field['conversation_step'] : ( ! empty( $field['type'] ) && 'question' === $field['type'] ) ) );
				}
			)
		);

		if ( $has_conversational_fields ) {
			return $this->render_conversational_form( $form, $fields, $settings, $submission_result, $wrapper_style, $form_theme, $challenge_enabled, $challenge_config );
		}

		ob_start();
		?>
		<?php if ( $challenge_enabled ) : ?>
			<script src="<?php echo esc_url( $challenge_config['script_url'] ); ?>" async defer></script>
		<?php endif; ?>
		<?php if ( $stripe_enabled ) : ?>
			<script src="https://js.stripe.com/v3/"></script>
		<?php endif; ?>
		<form class="ajforms-frontend-form ajforms-theme-<?php echo esc_attr( $form_theme ); ?>" method="post" enctype="multipart/form-data" style="<?php echo esc_attr( $wrapper_style ); ?>padding:24px;border-radius:var(--ajforms-radius);background:var(--ajforms-bg);border:1px solid #dfe6ee;box-shadow:0 20px 45px rgba(18,52,77,.08);">
			<input type="hidden" name="ajf_form_id" value="<?php echo esc_attr( $form_id ); ?>">
			<input type="hidden" name="ajf_stripe_payment_intent" value="">
			<?php wp_nonce_field( 'ajf_submit_form_' . $form_id, 'ajf_form_nonce' ); ?>
			<?php echo $this->render_tracking_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $this->is_honeypot_enabled() ) : ?>
				<?php $honeypot_field_name = $this->get_honeypot_field_name( $form_id ); ?>
				<div class="ajforms-honeypot" aria-hidden="true" style="position:absolute !important;left:-9999px !important;top:auto !important;width:1px !important;height:1px !important;overflow:hidden !important;">
					<label for="<?php echo esc_attr( $honeypot_field_name ); ?>"><?php esc_html_e( 'Leave this field empty', 'ajforms' ); ?></label>
					<input
						type="text"
						id="<?php echo esc_attr( $honeypot_field_name ); ?>"
						name="<?php echo esc_attr( $honeypot_field_name ); ?>"
						value=""
						tabindex="-1"
						autocomplete="off"
					>
				</div>
			<?php endif; ?>

			<div class="ajforms-form-title">
				<h3><?php echo esc_html( $form->title ); ?></h3>
				<?php if ( ! empty( $settings['form_description'] ) ) : ?>
					<p><?php echo esc_html( $settings['form_description'] ); ?></p>
				<?php endif; ?>
			</div>

			<?php echo $this->render_submission_redirect( $submission_result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( $submission_result['submitted'] ) : ?>
				<div class="ajforms-message <?php echo $submission_result['success'] ? 'success' : 'error'; ?>" style="margin-bottom:20px;padding:12px;border-radius:4px;<?php echo $submission_result['success'] ? 'background:#edfaef;color:#116329;' : 'background:#fcf0f1;color:#8a2424;'; ?>">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $submission_result['success'] ) : ?>
				<?php if ( $challenge_enabled ) : ?>
					<div class="ajforms-challenge-wrap ajforms-challenge-<?php echo esc_attr( $challenge_config['provider'] ); ?>" style="margin:0 0 24px;">
						<div class="<?php echo esc_attr( $challenge_config['container_class'] ); ?>" data-sitekey="<?php echo esc_attr( $challenge_config['site_key'] ); ?>"></div>
					</div>
				<?php endif; ?>

				<?php foreach ( $fields as $field ) : ?>
					<?php
					if ( ! is_array( $field ) ) {
						continue;
					}

					$field_id    = ! empty( $field['id'] ) ? $field['id'] : 'field_' . wp_generate_uuid4();
					$field_type  = ! empty( $field['type'] ) ? $field['type'] : 'text';
					$field_label = ! empty( $field['label'] ) ? $field['label'] : ucfirst( $field_type );
					$required    = ! empty( $field['required'] );
					$placeholder = ! empty( $field['placeholder'] ) ? $field['placeholder'] : '';
					$options     = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					$help_text   = ! empty( $field['help_text'] ) ? $field['help_text'] : '';
					$default_value = ! empty( $field['default_value'] ) ? $field['default_value'] : '';
					$css_class   = ! empty( $field['css_class'] ) ? $field['css_class'] : '';
					$accepted_file_types = ! empty( $field['accepted_file_types'] ) ? $field['accepted_file_types'] : '.pdf,.jpg,.jpeg,.png,.gif,.webp';

					$posted_value = isset( $_POST[ $field_id ] ) ? wp_unslash( $_POST[ $field_id ] ) : $default_value;
					?>
					<div class="ajforms-field <?php echo esc_attr( $css_class ); ?>" style="margin-bottom:20px;">
						<?php if ( 'separator' !== $field_type ) : ?>
							<label for="<?php echo esc_attr( $field_id ); ?>" style="display:block; font-weight:600; margin-bottom:6px;color:var(--ajforms-text);">
								<?php echo esc_html( $field_label ); ?>
								<?php if ( $required ) : ?>
									<span style="color:#d63638;">*</span>
								<?php endif; ?>
							</label>
						<?php endif; ?>

						<?php if ( 'textarea' === $field_type ) : ?>
							<textarea
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							><?php echo esc_textarea( is_string( $posted_value ) ? $posted_value : '' ); ?></textarea>

						<?php elseif ( 'select' === $field_type ) : ?>
							<select
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							>
								<option value=""><?php echo esc_html( $placeholder ?: 'Select an option' ); ?></option>
								<?php foreach ( $options as $option ) : ?>
									<?php
									$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
									$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
									?>
									<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $posted_value, $option_value ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

						<?php elseif ( 'checkboxes' === $field_type ) : ?>
							<div id="<?php echo esc_attr( $field_id ); ?>">
								<?php
								$posted_array = is_array( $posted_value ) ? $posted_value : array();
								foreach ( $options as $option ) :
									$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
									$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
									?>
									<label style="display:block; margin-bottom:6px;color:var(--ajforms-text);">
										<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>[]" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( in_array( $option_value, $posted_array, true ) ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</label>
								<?php endforeach; ?>
							</div>

						<?php elseif ( 'question' === $field_type || 'multiple_choice' === $field_type ) : ?>
							<div id="<?php echo esc_attr( $field_id ); ?>">
								<?php foreach ( $options as $option ) : ?>
									<?php
									$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
									$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
									?>
									<label style="display:block; margin-bottom:6px;color:var(--ajforms-text);">
										<input type="radio" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( $posted_value, $option_value ); ?> <?php echo $required ? 'required' : ''; ?>>
										<?php echo esc_html( $option_label ); ?>
									</label>
								<?php endforeach; ?>
							</div>

						<?php elseif ( 'separator' === $field_type ) : ?>
							<hr>

						<?php elseif ( 'file' === $field_type ) : ?>
							<input
								type="file"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								accept="<?php echo esc_attr( $accepted_file_types ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							>

						<?php else : ?>
							<?php
							$input_type_map = array(
								'email'   => 'email',
								'url'     => 'url',
								'number'  => 'number',
								'phone'   => 'tel',
								'tel'     => 'tel',
								'date'    => 'date',
								'address' => 'text',
								'text'    => 'text',
							);

							$input_type = isset( $input_type_map[ $field_type ] ) ? $input_type_map[ $field_type ] : 'text';
							?>
							<input
								type="<?php echo esc_attr( $input_type ); ?>"
								id="<?php echo esc_attr( $field_id ); ?>"
								name="<?php echo esc_attr( $field_id ); ?>"
								value="<?php echo esc_attr( is_string( $posted_value ) ? $posted_value : '' ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo $required ? 'required' : ''; ?>
								style="width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);"
							>
						<?php endif; ?>

						<?php if ( '' !== $help_text ) : ?>
							<p style="margin-top:6px;color:#646970;font-size:12px;"><?php echo esc_html( $help_text ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php if ( $stripe_enabled ) : ?>
					<div class="ajforms-stripe-payment" data-form-id="<?php echo esc_attr( $form_id ); ?>" data-publishable-key="<?php echo esc_attr( $stripe_config['publishable_key'] ); ?>" data-payment-nonce="<?php echo esc_attr( $stripe_nonce ); ?>">
						<div style="margin:24px 0 16px;padding:18px;border:1px solid #dfe6ee;border-radius:calc(var(--ajforms-radius) - 4px);background:#fff;">
							<div style="font-size:16px;font-weight:700;color:var(--ajforms-text);margin-bottom:6px;"><?php esc_html_e( 'Payment', 'ajforms' ); ?></div>
							<div style="font-size:14px;color:#4b5563;margin-bottom:14px;">
								<?php echo esc_html( $stripe_config['description'] ); ?>
							</div>
							<div style="font-size:22px;font-weight:800;color:var(--ajforms-text);margin-bottom:16px;">
								<?php echo esc_html( strtoupper( $stripe_config['currency'] ) . ' ' . number_format_i18n( (float) $stripe_config['amount'], 2 ) ); ?>
							</div>
							<div class="ajforms-stripe-payment-element" id="ajforms-stripe-payment-element-<?php echo esc_attr( $form_id ); ?>"></div>
							<p class="ajforms-stripe-payment-message" style="display:none;margin:12px 0 0;color:#b32d2e;"></p>
						</div>
					</div>
				<?php endif; ?>

				<div class="ajforms-submit" style="text-align:<?php echo esc_attr( ! empty( $settings['button_alignment'] ) ? $settings['button_alignment'] : 'left' ); ?>;">
					<button type="submit" style="background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:12px 20px;font-weight:700;"><?php echo esc_html( $submit_text ); ?></button>
				</div>
			<?php endif; ?>
		</form>
		<?php if ( $stripe_enabled ) : ?>
			<script>
			(function() {
				const form = document.currentScript.previousElementSibling;
				if (!form || typeof window.Stripe === 'undefined') {
					return;
				}

				const paymentWrap = form.querySelector('.ajforms-stripe-payment');
				const paymentElementNode = form.querySelector('.ajforms-stripe-payment-element');
				const paymentMessage = form.querySelector('.ajforms-stripe-payment-message');
				const paymentIntentInput = form.querySelector('input[name="ajf_stripe_payment_intent"]');
				const submitButton = form.querySelector('button[type="submit"]');

				if (!paymentWrap || !paymentElementNode || !paymentIntentInput || !submitButton) {
					return;
				}

				const stripe = window.Stripe(paymentWrap.dataset.publishableKey);
				let elements = null;
				let activeClientSecret = '';
				let isSubmitting = false;

				function setPaymentMessage(message) {
					if (!paymentMessage) {
						return;
					}

					paymentMessage.textContent = message || '';
					paymentMessage.style.display = message ? 'block' : 'none';
				}

				async function createPaymentIntent() {
					const formData = new FormData();
					formData.append('action', 'ajf_create_stripe_payment_intent');
					formData.append('form_id', paymentWrap.dataset.formId);
					formData.append('nonce', paymentWrap.dataset.paymentNonce);

					const response = await fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						body: formData
					});
					const payload = await response.json();

					if (!payload.success || !payload.data || !payload.data.client_secret) {
						throw new Error((payload && payload.data) || 'Unable to initialize Stripe payment.');
					}

					return payload.data.client_secret;
				}

				async function ensurePaymentElement() {
					if (elements && activeClientSecret) {
						return;
					}

					activeClientSecret = await createPaymentIntent();
					elements = stripe.elements({ clientSecret: activeClientSecret });
					const paymentElement = elements.create('payment');
					paymentElement.mount(paymentElementNode);
				}

				ensurePaymentElement().catch(function(error) {
					setPaymentMessage(error.message || 'Unable to initialize Stripe payment.');
				});

				form.addEventListener('submit', async function(event) {
					if (isSubmitting || paymentIntentInput.value) {
						return;
					}

					event.preventDefault();
					setPaymentMessage('');

					if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
						return;
					}

					try {
						submitButton.disabled = true;
						isSubmitting = true;
						await ensurePaymentElement();

						const result = await stripe.confirmPayment({
							elements: elements,
							redirect: 'if_required'
						});

						if (result.error) {
							throw new Error(result.error.message || 'Stripe payment could not be completed.');
						}

						if (!result.paymentIntent || result.paymentIntent.status !== 'succeeded') {
							throw new Error('Stripe payment is not complete yet.');
						}

						paymentIntentInput.value = result.paymentIntent.id;
						form.submit();
					} catch (error) {
						setPaymentMessage(error.message || 'Stripe payment could not be completed.');
						submitButton.disabled = false;
						isSubmitting = false;
					}

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-custom-service-request-button');
					if (!button || button.disabled) {
						return;
					}

					const message = shell.querySelector('.aj-portal-add-service-message');
					const originalText = button.textContent;
					button.disabled = true;
					button.textContent = '<?php echo esc_js( __( 'Submitting...', 'ajforms' ) ); ?>';

					if (message) {
						message.textContent = '';
						message.className = 'aj-portal-add-service-message';
						message.style.display = 'none';
					}

					const formData = new FormData();
					formData.append('action', 'ajcore_create_custom_service_request');
					formData.append('price_id', button.dataset.priceId || '');
					formData.append('nonce', button.dataset.nonce || '');

					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(parseJsonResponse)
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
							if (message) {
								message.textContent = (payload.data && payload.data.message) || '<?php echo esc_js( __( 'Your request was submitted and is now under review.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-success';
								message.style.display = 'block';
							}
							button.textContent = '<?php echo esc_js( __( 'Request Submitted', 'ajforms' ) ); ?>';
							setTimeout(function() { window.location.reload(); }, 800);
						})
						.catch(function(error) {
							button.disabled = false;
							button.textContent = originalText;
							if (message) {
								message.textContent = error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>';
								message.className = 'aj-portal-add-service-message is-error';
								message.style.display = 'block';
							} else {
								window.alert(error.message || '<?php echo esc_js( __( 'Unable to submit request.', 'ajforms' ) ); ?>');
							}
						});
				});

				shell.addEventListener('click', function(event) {
					const button = event.target.closest('.aj-portal-cancel-service-request');
					if (!button || button.disabled) {
						return;
					}
					if (!window.confirm('<?php echo esc_js( __( 'Cancel this pending service request?', 'ajforms' ) ); ?>')) {
						return;
					}
					button.disabled = true;
					const formData = new FormData();
					formData.append('action', 'ajcore_cancel_portal_service_request');
					formData.append('ledger_id', button.dataset.ledgerId || '');
					formData.append('nonce', button.dataset.nonce || '');
					fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
						method: 'POST',
						credentials: 'same-origin',
						body: formData
					})
						.then(function(response) { return response.json(); })
						.then(function(payload) {
							if (!payload || !payload.success) {
								throw new Error((payload && payload.data) || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
							}
							window.location.reload();
						})
						.catch(function(error) {
							button.disabled = false;
							window.alert(error.message || '<?php echo esc_js( __( 'Unable to cancel request.', 'ajforms' ) ); ?>');
						});
				});
			})();
			</script>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	public function run() {
	}
}
