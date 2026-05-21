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
	}

	public function schedule_recurring_events() {
		if ( ! wp_next_scheduled( 'ajforms_daily_asana_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ajforms_daily_asana_sync' );
		}
	}

	private function define_public_hooks() {
		add_shortcode( 'ajforms', array( $this, 'render_form_shortcode' ) );
		add_shortcode( 'ajcore_products', array( $this, 'render_products_shortcode' ) );
		add_shortcode( 'aj_customer_portal', array( $this, 'render_customer_portal_shortcode' ) );
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );
		add_filter( 'show_admin_bar', array( $this, 'filter_show_admin_bar' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'add_customer_portal_nav_item' ), 10, 2 );
		add_action( 'init', array( $this, 'maybe_create_customer_portal_page' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_form_preview' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle_portal_file_download' ) );
		add_action( 'wp_ajax_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_ajcore_create_checkout_session', array( $this, 'ajax_create_checkout_session' ) );
		add_action( 'wp_ajax_nopriv_ajcore_create_checkout_session', array( $this, 'ajax_create_checkout_session' ) );
	}

	private function is_frontend_portal_user() {
		return is_user_logged_in() && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_options' );
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

		if ( ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'manage_options' ) ) {
			return $this->get_customer_portal_url();
		}

		return $redirect_to;
	}

	public function redirect_frontend_portal_users_from_admin() {
		if ( wp_doing_ajax() || ! $this->is_frontend_portal_user() ) {
			return;
		}

		wp_safe_redirect( $this->get_customer_portal_url() );
		exit;
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

	private function render_customer_portal_file_library_tab() {
		$files = $this->get_current_user_portal_files();

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'File Library', 'ajforms' ); ?></h2>

			<?php if ( empty( $files ) ) : ?>
				<p><?php esc_html_e( 'No files have been shared with you yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-customer-file-grid">
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
						?>
						<div class="aj-customer-file">
							<?php if ( '' !== (string) $file->category ) : ?>
								<div class="aj-customer-file-category"><?php echo esc_html( $file->category ); ?></div>
							<?php endif; ?>
							<h3><?php echo esc_html( $file->title ); ?></h3>
							<?php if ( '' !== (string) $file->description ) : ?>
								<p><?php echo esc_html( $file->description ); ?></p>
							<?php endif; ?>
							<a class="button" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'ajforms' ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_customer_portal_overview_tab() {
		global $wpdb;

		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		$customer           = $this->get_current_user_portal_customer();

		if ( '' === $stripe_customer_id || ! $customer ) {
			return '<section class="aj-customer-portal-panel"><h2>' . esc_html__( 'Overview', 'ajforms' ) . '</h2><p>' . esc_html__( 'Your portal account is not linked to Stripe customer data yet.', 'ajforms' ) . '</p></section>';
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
				if ( empty( $subscription->current_period_end ) || 'active' !== $subscription->status ) {
					return false;
				}
				$renewal = strtotime( $subscription->current_period_end . ' UTC' );
				return $renewal && $renewal >= time() && $renewal <= time() + 30 * DAY_IN_SECONDS;
			}
		);

		ob_start();
		?>
		<section class="aj-customer-portal-panel">
			<h2><?php esc_html_e( 'Overview', 'ajforms' ); ?></h2>
			<div class="aj-portal-summary-grid">
				<div class="aj-portal-summary-card">
					<strong><?php esc_html_e( 'Customer', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( $customer->name ? $customer->name : $customer->email ); ?></span>
					<span><?php echo esc_html( $customer->email ); ?></span>
				</div>
				<div class="aj-portal-summary-card">
					<strong><?php esc_html_e( 'Active Subscriptions', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( array_filter( $subscriptions, function ( $subscription ) { return 'active' === $subscription->status; } ) ) ) ); ?></span>
				</div>
				<div class="aj-portal-summary-card">
					<strong><?php esc_html_e( 'Upcoming Payments', 'ajforms' ); ?></strong>
					<span><?php echo esc_html( number_format_i18n( count( $upcoming ) ) ); ?></span>
				</div>
			</div>

			<h3><?php esc_html_e( 'Services / Products Purchased', 'ajforms' ); ?></h3>
			<?php if ( empty( $subscriptions ) ) : ?>
				<p><?php esc_html_e( 'No subscription services are synced yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-table-wrap">
					<table class="aj-portal-table">
						<thead><tr><th><?php esc_html_e( 'Subscription', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Next Billing', 'ajforms' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $subscriptions as $subscription ) : ?>
								<tr>
									<td><code><?php echo esc_html( $subscription->stripe_subscription_id ); ?></code></td>
									<td><?php echo esc_html( ucfirst( $subscription->status ) ); ?></td>
									<td><?php echo esc_html( $subscription->current_period_end ? mysql2date( get_option( 'date_format' ), $subscription->current_period_end ) : '-' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Billing Ledger', 'ajforms' ); ?></h3>
			<?php if ( empty( $ledger ) ) : ?>
				<p><?php esc_html_e( 'No Stripe invoices or charges are synced yet.', 'ajforms' ); ?></p>
			<?php else : ?>
				<div class="aj-portal-table-wrap">
					<table class="aj-portal-table">
						<thead><tr><th><?php esc_html_e( 'Date', 'ajforms' ); ?></th><th><?php esc_html_e( 'Description', 'ajforms' ); ?></th><th><?php esc_html_e( 'Status', 'ajforms' ); ?></th><th><?php esc_html_e( 'Amount', 'ajforms' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $ledger as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry->ledger_date ? mysql2date( get_option( 'date_format' ), $entry->ledger_date ) : '-' ); ?></td>
									<td><?php echo esc_html( $entry->description ); ?></td>
									<td><?php echo esc_html( ucfirst( $entry->status ) ); ?></td>
									<td><?php echo esc_html( strtoupper( $entry->currency ) . ' ' . number_format_i18n( (float) $entry->amount, 2 ) ); ?></td>
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

	private function get_portal_user_mappings_table() {
		global $wpdb;
		return $wpdb->prefix . 'aj_portal_user_mappings';
	}

	private function get_current_user_stripe_customer_id() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		global $wpdb;

		$stripe_customer_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT stripe_customer_id FROM {$this->get_portal_user_mappings_table()} WHERE user_id = %d LIMIT 1",
				get_current_user_id()
			)
		);

		return $stripe_customer_id ? sanitize_text_field( $stripe_customer_id ) : '';
	}

	private function get_current_user_portal_customer() {
		$stripe_customer_id = $this->get_current_user_stripe_customer_id();
		if ( '' === $stripe_customer_id ) {
			return null;
		}

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_portal_stripe_customers_table()} WHERE stripe_customer_id = %s",
				$stripe_customer_id
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

	public function render_customer_portal_shortcode() {
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
		<div class="aj-customer-portal">
			<style>
				.aj-customer-portal{max-width:960px;margin:0 auto}
				.aj-customer-portal h1{margin:0 0 18px}
				.aj-customer-portal h2{margin:0 0 18px}
				.aj-customer-portal-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 22px;border-bottom:1px solid #dfe6ee}
				.aj-customer-portal-tab{display:inline-flex;align-items:center;padding:10px 14px;margin-bottom:-1px;border:1px solid transparent;border-radius:10px 10px 0 0;color:#52616f;text-decoration:none;font-weight:700}
				.aj-customer-portal-tab.is-active{background:#fff;border-color:#dfe6ee;border-bottom-color:#fff;color:#0f7ac6}
				.aj-customer-file-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
				.aj-portal-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:0 0 24px}
				.aj-portal-summary-card{border:1px solid #dfe6ee;border-radius:12px;padding:16px;background:#fff;display:grid;gap:6px}
				.aj-portal-summary-card strong{color:#1f2937}
				.aj-portal-table-wrap{overflow:auto;margin:0 0 24px}
				.aj-portal-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dfe6ee}
				.aj-portal-table th,.aj-portal-table td{padding:10px 12px;border-bottom:1px solid #dfe6ee;text-align:left;vertical-align:top}
				.aj-customer-file{border:1px solid #dfe6ee;border-radius:12px;padding:18px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.06)}
				.aj-customer-file-category{display:inline-block;margin-bottom:10px;color:#0f7ac6;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
				.aj-customer-file h3{margin:0 0 8px;font-size:20px;line-height:1.25}
				.aj-customer-file p{margin:0 0 14px;color:#52616f}
				.aj-customer-file .button{display:inline-block;text-decoration:none}
			</style>
			<h1><?php esc_html_e( 'Client Portal', 'ajforms' ); ?></h1>
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
			} elseif ( 'file-library' === $active_tab ) {
				echo $this->render_customer_portal_file_library_tab(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</div>
		<?php
		return ob_get_clean();
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

	public function ajax_create_checkout_session() {
		$price_id = isset( $_POST['price_id'] ) ? sanitize_text_field( wp_unslash( $_POST['price_id'] ) ) : '';
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$items    = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$include_archived = isset( $_POST['include_archived'] ) && in_array( strtolower( sanitize_text_field( wp_unslash( $_POST['include_archived'] ) ) ), array( '1', 'true', 'yes' ), true );

		if ( is_array( $items ) ) {
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
		$allowed_prices      = $this->get_public_stripe_prices( $requested_price_ids, $include_archived );
		$allowed_price_map   = array();

		foreach ( $allowed_prices as $allowed_price ) {
			if ( is_array( $allowed_price ) && ! empty( $allowed_price['id'] ) ) {
				$allowed_price_map[ $allowed_price['id'] ] = $allowed_price;
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
			'mode'              => 'payment',
			'customer_creation' => 'always',
			'success_url'       => $success_url,
			'cancel_url'        => $cancel_url,
		);

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
			$body['metadata[source]']        = 'ajcore_products';
		}

		$response = $this->stripe_api_request(
			'checkout/sessions',
			$stripe_settings['secret_key'],
			$body
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message(), 400 );
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
