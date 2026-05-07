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
		add_action( 'template_redirect', array( $this, 'maybe_render_form_preview' ) );
		add_action( 'wp_ajax_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
		add_action( 'wp_ajax_nopriv_ajf_create_stripe_payment_intent', array( $this, 'ajax_create_stripe_payment_intent' ) );
	}

	private function get_default_form_settings() {
		$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array();

		return array(
			'submit_text'           => 'Submit',
			'notifications_enabled' => isset( $plugin_settings['default_notifications_enabled'] ) ? '1' === (string) $plugin_settings['default_notifications_enabled'] : true,
			'notification_email'    => isset( $plugin_settings['default_notification_email'] ) ? $plugin_settings['default_notification_email'] : get_option( 'admin_email' ),
			'notification_subject'  => isset( $plugin_settings['default_notification_subject'] ) ? $plugin_settings['default_notification_subject'] : 'New submission for {form_title}',
			'button_alignment'      => 'left',
			'form_description'      => '',
			'success_message'       => isset( $plugin_settings['default_success_message'] ) ? $plugin_settings['default_success_message'] : 'Form submitted successfully.',
			'confirmation_type'     => 'message',
			'redirect_url'          => '',
			'use_label_placeholders' => false,
			'custom_css'            => '',
			'asana_task_enabled'    => false,
			'asana_task_name'       => 'New form submission: {form_title}',
			'asana_task_notes'      => "A new submission was received for {form_title}.\n\n{submission_fields}",
			'asana_project_gid'     => isset( $plugin_settings['asana_project_gid'] ) ? $plugin_settings['asana_project_gid'] : '',
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
		$amount          = isset( $settings['stripe_amount'] ) ? floatval( $settings['stripe_amount'] ) : 0;
		$currency        = isset( $settings['stripe_currency'] ) ? strtolower( sanitize_key( $settings['stripe_currency'] ) ) : 'usd';
		$description     = isset( $settings['stripe_description'] ) ? sanitize_text_field( $settings['stripe_description'] ) : 'Payment for {form_title}';

		return array(
			'enabled'         => $enabled && '' !== $stripe_settings['publishable_key'] && '' !== $stripe_settings['secret_key'] && $amount > 0,
			'publishable_key' => $stripe_settings['publishable_key'],
			'secret_key'      => $stripe_settings['secret_key'],
			'amount'          => $amount,
			'currency'        => in_array( $currency, array( 'usd', 'eur', 'gbp', 'cad', 'aud' ), true ) ? $currency : 'usd',
			'description'     => str_replace( '{form_title}', $form->title, $description ),
		);
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

		if ( $expected_amount !== $actual_amount || strtolower( $stripe_config['currency'] ) !== $actual_currency || absint( $form->id ) !== $form_meta_id ) {
			return new WP_Error( 'stripe_payment_mismatch', __( 'Stripe payment details do not match this form submission.', 'ajforms' ) );
		}

		return array(
			'payment_intent_id' => $payment_intent_id,
			'amount'            => $stripe_config['amount'],
			'currency'          => strtoupper( $stripe_config['currency'] ),
			'description'       => $stripe_config['description'],
		);
	}

	private function get_forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'ajforms_forms';
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
			return array(
				'fields'   => $schema['fields'],
				'settings' => wp_parse_args(
					isset( $schema['settings'] ) && is_array( $schema['settings'] ) ? $schema['settings'] : array(),
					$this->get_default_form_settings()
				),
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
				<div style="margin-bottom:20px;color:#646970;font-size:14px;">AJ Forms Preview</div>
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
		);

		// Add {submission_fields} tag
		$replacements['{submission_fields}'] = $this->build_submission_fields_text( $lead_data );

		// Add individual field placeholders like {field_1}, {field_2}, etc.
		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';
			
			if ( ! empty( $field['file_url'] ) ) {
				$value = esc_url_raw( $field['file_url'] );
			}

			// Support {field_id} format
			$replacements[ '{' . $field_id . '}' ] = $value;
		}

		return strtr( $template, $replacements );
	}


	private function get_reply_to_header( $lead_data ) {
		foreach ( $lead_data as $field ) {
			if ( ! is_array( $field ) ) {
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
		$task_notes_template = ! empty( $settings['asana_task_notes'] ) ? (string) $settings['asana_task_notes'] : "A new submission was received for {form_title}.\n\n{submission_fields}";

		$task_name = $this->replace_template_tags( $task_name_template, $form, $lead_data );
		$task_notes = $this->replace_template_tags( $task_notes_template, $form, $lead_data );

		$request_data = array(
			'name'      => sanitize_text_field( $task_name ),
			'notes'     => $task_notes,
			'workspace' => $workspace_gid,
		);

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
			error_log( 'AJ Forms Asana task creation failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			error_log( 'AJ Forms Asana task creation failed with status ' . $response_code . ': ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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

		$lines   = array();
		$lines[] = 'A new submission was received for "' . $form->title . '".';
		$lines[] = '';

		foreach ( $lead_data as $field_id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = ! empty( $field['label'] ) ? sanitize_text_field( $field['label'] ) : sanitize_text_field( (string) $field_id );
			$value = isset( $field['value'] ) ? $this->format_lead_value_for_display( $field['value'] ) : '';

			$lines[] = $label . ': ' . ( '' !== $value ? $value : '-' );
		}

		$lines[] = '';
		$lines[] = 'Submitted: ' . current_time( 'mysql' );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$reply_to = $this->get_reply_to_header( $lead_data );
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

		wp_mail( $recipients, $subject, implode( "\n", $lines ), $headers, $attachments );
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
				$clean_value = array_map( 'sanitize_text_field', $value );
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

				$is_empty = '' === $clean_value;
			}

			if ( $required && $is_empty ) {
				$errors[] = sprintf( '%s is required.', $field_label );
			}

			$lead_data[ $field_id ] = array(
				'label' => $field_label,
				'type'  => $field_type,
				'value' => $clean_value,
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
			);
		}

		global $wpdb;
		$leads_table = $this->get_leads_table();

		$inserted = $wpdb->insert(
			$leads_table,
			array(
				'form_id'    => $form_id,
				'lead_data'  => wp_json_encode( $lead_data ),
				'status'     => 'unread',
				'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				'source_url' => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '',
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
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

		return array(
			'submitted' => true,
			'success'   => true,
			'message'   => ! empty( $settings['success_message'] ) ? $settings['success_message'] : 'Form submitted successfully.',
		);
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
			'required'            => ! empty( $field['required'] ),
			'placeholder'         => ! empty( $field['placeholder'] ) ? $field['placeholder'] : '',
			'options'             => $options,
			'help_text'           => ! empty( $field['help_text'] ) ? $field['help_text'] : '',
			'default_value'       => $default_value,
			'css_class'           => ! empty( $field['css_class'] ) ? $field['css_class'] : '',
			'conversation_step'   => ! empty( $field['conversation_step'] ) && 'final_contact' === $field['conversation_step'] ? 'final_contact' : 'question',
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
		$control_style       = 'width:100%; padding:10px;border-radius:calc(var(--ajforms-radius) - 4px);background:var(--ajforms-input-bg);border:1px solid var(--ajforms-input-border);color:var(--ajforms-text);';

		ob_start();
		if ( 'textarea' === $field_type ) :
			?>
			<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_id ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?> style="<?php echo esc_attr( $control_style ); ?>"><?php echo esc_textarea( is_string( $posted_value ) ? $posted_value : '' ); ?></textarea>
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
			<div id="<?php echo esc_attr( $field_id ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<?php
					$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
					$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
					?>
					<label style="display:block; margin-bottom:6px;color:var(--ajforms-text);">
						<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>[]" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( in_array( $option_value, $posted_array, true ) ); ?>>
						<?php echo esc_html( $option_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
		elseif ( 'question' === $field_type || 'multiple_choice' === $field_type ) :
			?>
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
					return empty( $field['conversation_step'] ) || 'final_contact' !== $field['conversation_step'];
				}
			)
		);
		$contact_fields = array_values(
			array_filter(
				$answerable_fields,
				function ( $field ) {
					return ! empty( $field['conversation_step'] ) && 'final_contact' === $field['conversation_step'];
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
		<form class="ajforms-frontend-form ajforms-conversational-form ajforms-theme-<?php echo esc_attr( $form_theme ); ?>" method="post" enctype="multipart/form-data" style="<?php echo esc_attr( $wrapper_style ); ?>padding:28px;border-radius:var(--ajforms-radius);background:var(--ajforms-bg);border:1px solid #dfe6ee;box-shadow:0 20px 45px rgba(18,52,77,.08);">
			<input type="hidden" name="ajf_form_id" value="<?php echo esc_attr( $form->id ); ?>">
			<?php wp_nonce_field( 'ajf_submit_form_' . absint( $form->id ), 'ajf_form_nonce' ); ?>
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

			<?php if ( $submission_result['submitted'] ) : ?>
				<div class="ajforms-message <?php echo $submission_result['success'] ? 'success' : 'error'; ?>" style="margin-bottom:20px;padding:12px;border-radius:4px;<?php echo $submission_result['success'] ? 'background:#edfaef;color:#116329;' : 'background:#fcf0f1;color:#8a2424;'; ?>">
					<?php echo esc_html( $submission_result['message'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $submission_result['success'] ) : ?>
				<?php foreach ( $question_fields as $field ) : ?>
					<?php $field_data = $this->get_frontend_field_data( $field ); ?>
					<section
						class="ajforms-conversation-step"
						data-step="<?php echo esc_attr( $step_counter ); ?>"
						data-field-id="<?php echo esc_attr( $field_data['id'] ); ?>"
						data-branch-map="<?php echo esc_attr( wp_json_encode( ! empty( $field['branch_map'] ) && is_array( $field['branch_map'] ) ? $field['branch_map'] : array() ) ); ?>"
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

				<div class="ajforms-conversation-actions" style="display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:24px;">
					<button type="button" class="ajforms-conversation-prev" style="display:none;background:#fff;color:var(--ajforms-text);border:1px solid var(--ajforms-input-border);border-radius:calc(var(--ajforms-radius) - 4px);padding:11px 16px;font-weight:700;"><?php esc_html_e( 'Back', 'ajforms' ); ?></button>
					<button type="button" class="ajforms-conversation-next" style="margin-left:auto;background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:12px 20px;font-weight:800;"><?php esc_html_e( 'Next', 'ajforms' ); ?></button>
					<button type="submit" class="ajforms-conversation-submit" style="display:none;margin-left:auto;background:var(--ajforms-primary);color:#fff;border:0;border-radius:calc(var(--ajforms-radius) - 4px);padding:12px 20px;font-weight:800;"><?php echo esc_html( ! empty( $settings['submit_text'] ) ? $settings['submit_text'] : 'Submit' ); ?></button>
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

			function getSelectedBranchValue(step) {
				const checked = step.querySelector('input[type="radio"]:checked');
				if (checked) {
					return checked.value;
				}

				const select = step.querySelector('select');
				if (select && select.value) {
					return select.value;
				}

				return '';
			}

			function getNextStepFromBranch() {
				const step = steps[currentStep];
				let branchMap = {};

				try {
					branchMap = step.dataset.branchMap ? JSON.parse(step.dataset.branchMap) : {};
				} catch (error) {
					branchMap = {};
				}

				const branchValue = getSelectedBranchValue(step);
				const target = branchValue && branchMap[branchValue] ? branchMap[branchValue] : '';

				if (target === '__contact' && Object.prototype.hasOwnProperty.call(stepIndexByFieldId, '__contact')) {
					return stepIndexByFieldId.__contact;
				}

				if (target === '__end') {
					return steps.length - 1;
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
				if (nextButton) {
					nextButton.style.display = isLastStep ? 'none' : '';
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
					if (currentStepIsValid()) {
						const nextStep = getNextStepFromBranch();
						history.push(currentStep);
						resetVisitedFromHistory();
						setStep(nextStep);
					}
				});
			}

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
				'type' => '',
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
		if ( 'conversational' === sanitize_key( $atts['type'] ) ) {
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
