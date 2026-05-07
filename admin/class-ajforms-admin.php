<?php

class AJForms_Admin {

	private function get_latest_release_api_url() {
		return 'https://api.github.com/repos/ssnanda/ajforms/releases/latest';
	}

	private function get_update_cache_key() {
		return 'ajforms_latest_release_info';
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
			$this->get_latest_release_api_url(),
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AJForms/' . AJFORMS_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $response_code || ! is_array( $body ) ) {
			return new WP_Error( 'ajforms_update_lookup_failed', __( 'Unable to reach the latest AJ Forms release right now.', 'ajforms' ) );
		}

		$tag_name = isset( $body['tag_name'] ) ? sanitize_text_field( (string) $body['tag_name'] ) : '';
		$version  = ltrim( $tag_name, 'vV' );

		if ( '' === $version ) {
			return new WP_Error( 'ajforms_update_invalid', __( 'The latest release did not include a valid version tag.', 'ajforms' ) );
		}

		$download_url = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				$asset_name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( preg_match( '/^ajforms.*\.zip$/i', $asset_name ) && ! empty( $asset['browser_download_url'] ) ) {
					$download_url = esc_url_raw( (string) $asset['browser_download_url'] );
					break;
				}
			}
		}

		if ( '' === $download_url ) {
			$download_url = 'https://github.com/ssnanda/ajforms/releases/download/' . rawurlencode( $tag_name ) . '/ajforms-' . rawurlencode( $version ) . '.zip';
		}

		$release_info = array(
			'version'      => $version,
			'tag_name'     => $tag_name,
			'name'         => isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : $tag_name,
			'download_url' => $download_url,
			'checked_at'   => current_time( 'mysql' ),
		);

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
			return new WP_Error( 'missing_download_url', __( 'The latest AJ Forms release does not include a downloadable zip.', 'ajforms' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $latest_release['download_url'], array( 'overwrite_package' => true ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		activate_plugin( AJFORMS_PLUGIN_BASENAME );

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
				'workspace_gid'=> '',
			)
		);

		return is_array( $cache ) ? $cache : array(
			'updated_at'    => '',
			'workspaces'    => array(),
			'projects'      => array(),
			'workspace_gid' => '',
		);
	}

	private function update_asana_reference_cache( $cache ) {
		update_option( 'ajforms_asana_reference_cache', $cache );
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
		}

		$cache = array(
			'updated_at'    => current_time( 'mysql' ),
			'workspaces'    => $formatted_workspaces,
			'projects'      => $projects,
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
				'button_alignment'      => isset( $normalized['settings']['button_alignment'] ) ? sanitize_key( $normalized['settings']['button_alignment'] ) : 'left',
				'form_description'      => isset( $normalized['settings']['form_description'] ) ? sanitize_textarea_field( $normalized['settings']['form_description'] ) : '',
				'success_message'       => isset( $normalized['settings']['success_message'] ) ? sanitize_textarea_field( $normalized['settings']['success_message'] ) : ( isset( $plugin_settings['default_success_message'] ) ? $plugin_settings['default_success_message'] : 'Form submitted successfully.' ),
				'confirmation_type'     => isset( $normalized['settings']['confirmation_type'] ) && in_array( sanitize_key( $normalized['settings']['confirmation_type'] ), array( 'message', 'redirect' ), true ) ? sanitize_key( $normalized['settings']['confirmation_type'] ) : 'message',
				'redirect_url'          => isset( $normalized['settings']['redirect_url'] ) ? esc_url_raw( $normalized['settings']['redirect_url'] ) : '',
				'use_label_placeholders' => ! empty( $normalized['settings']['use_label_placeholders'] ),
				'custom_css'            => isset( $normalized['settings']['custom_css'] ) ? wp_strip_all_tags( $normalized['settings']['custom_css'] ) : '',
				'asana_task_enabled'    => ! empty( $normalized['settings']['asana_task_enabled'] ),
				'asana_task_name'       => isset( $normalized['settings']['asana_task_name'] ) ? sanitize_text_field( $normalized['settings']['asana_task_name'] ) : 'New form submission: {form_title}',
				'asana_task_notes'      => isset( $normalized['settings']['asana_task_notes'] ) ? sanitize_textarea_field( $normalized['settings']['asana_task_notes'] ) : "A new submission was received for {form_title}.\n\n{submission_fields}",
				'asana_project_gid'     => isset( $normalized['settings']['asana_project_gid'] ) ? sanitize_text_field( $normalized['settings']['asana_project_gid'] ) : '',
				'stripe_enabled'        => ! empty( $normalized['settings']['stripe_enabled'] ),
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
			$this->handle_settings_save();
		} elseif ( 'ajforms-about' === $page ) {
			$this->handle_about_update_action();
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
		);

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

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'ajforms-settings',
					'section'          => $section,
					'subsection'       => $subsection,
					'settings-updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
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
			__( 'AJ Forms', 'ajforms' ),
			__( 'AJ Forms', 'ajforms' ),
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
			__( 'Settings', 'ajforms' ),
			__( 'Settings', 'ajforms' ),
			'manage_options',
			'ajforms-settings',
			array( $this, 'display_settings_page' )
		);

		add_submenu_page(
			'ajforms',
			__( 'About', 'ajforms' ),
			__( 'About', 'ajforms' ),
			'manage_options',
			'ajforms-about',
			array( $this, 'display_about_page' )
		);

	}

	public function add_plugin_action_links( $links ) {
		$custom_links = array(
			'about'   => '<a href="' . esc_url( add_query_arg( array( 'page' => 'ajforms-about' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'About', 'ajforms' ) . '</a>',
		);

		return array_merge( $custom_links, $links );
	}

	public function add_plugin_row_meta_links( $links, $file ) {
		if ( AJFORMS_PLUGIN_BASENAME !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( add_query_arg( array( 'page' => 'ajforms-about' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'About', 'ajforms' ) . '</a>';

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
						<form method="post">
				<?php wp_nonce_field( 'ajforms_save_settings', 'ajforms_settings_nonce' ); ?>
							<div class="ajforms-settings-head">
								<?php if ( 'general' === $section ) : ?>
									<h2><?php esc_html_e( 'General Settings', 'ajforms' ); ?></h2>
									<p><?php esc_html_e( 'Set the defaults AJ Forms should use for notifications, reply behavior, and submission feedback across your forms.', 'ajforms' ); ?></p>
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
											<div class="ajforms-settings-help"><?php esc_html_e( 'Create this in Asana and keep it private. AJ Forms uses it to create tasks through the Asana API.', 'ajforms' ); ?></div>
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
										<div class="ajforms-settings-help"><?php esc_html_e( 'Not active yet, but saved now so we can wire delivery into submissions next.', 'ajforms' ); ?></div>
									</div>
									<div class="ajforms-settings-note"><?php esc_html_e( 'Future-friendly direction: webhook, CRM sync, and automation providers can all live here without changing the main settings structure again.', 'ajforms' ); ?></div>
								</div>
							<?php elseif ( 'payments' === $section ) : ?>
								<div class="ajforms-settings-card">
									<span class="ajforms-settings-pill"><?php esc_html_e( 'Stripe Payments', 'ajforms' ); ?></span>
									<h3><?php esc_html_e( 'Stripe connection', 'ajforms' ); ?></h3>
									<p><?php esc_html_e( 'These keys connect AJ Forms to Stripe site-wide, but they do not turn payments on for every form. You choose payment-enabled forms individually in the builder.', 'ajforms' ); ?></p>
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
									<div class="ajforms-settings-note"><?php esc_html_e( 'Stripe can now be marked per form in the builder. Live payment field rendering and charge collection still need their own implementation pass.', 'ajforms' ); ?></div>
								</div>
							<?php endif; ?>

							<div class="ajforms-settings-actions">
								<?php submit_button( __( 'Save Settings', 'ajforms' ), 'primary', 'submit', false ); ?>
								<span style="color:#6b7280;"><?php esc_html_e( 'Changes are stored site-wide for AJ Forms.', 'ajforms' ); ?></span>
							</div>
						</form>
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

		$update_status = null;
		if (
			current_user_can( 'update_plugins' )
			&& ( isset( $_GET['update-available'] ) || isset( $_GET['already-current'] ) )
		) {
			$update_status = $this->get_update_status();
		}

		?>
		<div class="wrap">
			<style>
				.ajforms-about-shell{max-width:760px;margin-top:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px}
				.ajforms-about-shell h1{margin:0 0 8px;font-size:28px;line-height:1.2}
				.ajforms-about-shell p{font-size:14px;line-height:1.6;color:#50575e}
				.ajforms-about-status{margin:20px 0 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7}
				.ajforms-about-status strong{display:block;margin-bottom:6px;color:#1d2327}
				.ajforms-about-actions{margin-top:18px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
				.ajforms-about-meta{margin-top:18px;width:100%;border-collapse:collapse}
				.ajforms-about-meta th,.ajforms-about-meta td{padding:10px 0;border-bottom:1px solid #f0f0f1;text-align:left}
				.ajforms-about-meta th{width:160px;color:#646970;font-weight:600}
			</style>

			<div class="ajforms-about-shell">
				<h1><?php esc_html_e( 'AJ Forms', 'ajforms' ); ?></h1>
				<p><?php esc_html_e( 'A WordPress form builder for conversational flows, contact capture, entries, notifications, and practical workflow integrations.', 'ajforms' ); ?></p>

				<table class="ajforms-about-meta">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'ajforms' ); ?></th>
							<td><?php echo esc_html( 'AJ Forms ' . AJFORMS_VERSION ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Developer', 'ajforms' ); ?></th>
							<td>itSpector</td>
						</tr>
					</tbody>
				</table>

				<?php if ( current_user_can( 'update_plugins' ) ) : ?>
					<div class="ajforms-about-actions">
						<a class="button button-secondary" href="<?php echo esc_url( $this->get_about_update_url( 'check' ) ); ?>"><?php esc_html_e( 'Check for Updates', 'ajforms' ); ?></a>
					</div>

					<?php if ( isset( $_GET['update-error'] ) ) : ?>
						<div class="ajforms-about-status">
							<strong><?php esc_html_e( 'Update check failed.', 'ajforms' ); ?></strong>
							<?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['update-error'] ) ) ); ?>
						</div>
					<?php elseif ( isset( $_GET['update-success'] ) ) : ?>
						<div class="ajforms-about-status">
							<strong><?php esc_html_e( 'AJ Forms was updated.', 'ajforms' ); ?></strong>
							<?php esc_html_e( 'The plugin update completed successfully.', 'ajforms' ); ?>
						</div>
					<?php elseif ( is_array( $update_status ) && ! empty( $update_status['has_update'] ) ) : ?>
						<div class="ajforms-about-status">
							<strong><?php esc_html_e( 'An AJ Forms update is available.', 'ajforms' ); ?></strong>
							<?php
							printf(
								esc_html__( 'Installed: %1$s. Latest: %2$s.', 'ajforms' ),
								esc_html( $update_status['current_version'] ),
								esc_html( $update_status['latest_version'] )
							);
							?>
							<div class="ajforms-about-actions">
								<a class="button button-primary" href="<?php echo esc_url( $this->get_about_update_url( 'update' ) ); ?>"><?php esc_html_e( 'Update AJ Forms', 'ajforms' ); ?></a>
							</div>
						</div>
					<?php elseif ( is_array( $update_status ) ) : ?>
						<div class="ajforms-about-status">
							<strong><?php esc_html_e( 'AJ Forms is up to date.', 'ajforms' ); ?></strong>
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
