<?php
/**
 * View: Add/Edit Visual Builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$back_url = admin_url( 'admin.php?page=ajforms' );
$form_id  = isset( $_GET['form_id'] ) ? intval( $_GET['form_id'] ) : 0;
$default_notification_body = "{submission_table}{submission_details_table}";
$default_asana_notes = "Form Submission\n\n{submission_fields}\n\nSubmission Details\n\n{submission_details}";
$asana_cache = get_option(
	'ajforms_asana_reference_cache',
	array(
		'workspaces' => array(),
		'projects'   => array(),
		'users'      => array(),
	)
);
$asana_cache = is_array( $asana_cache ) ? wp_parse_args( $asana_cache, array( 'users' => array() ) ) : array( 'users' => array() );
$plugin_settings = function_exists( 'ajforms_get_settings' ) ? ajforms_get_settings() : array(
	'default_notification_email'    => get_option( 'admin_email' ),
	'default_notification_subject'  => 'New submission for {form_title}',
	'default_notifications_enabled' => '1',
	'default_from_name'             => get_bloginfo( 'name' ),
	'asana_enabled'                 => '0',
	'asana_project_gid'             => '',
	'stripe_publishable_key'        => '',
	'stripe_secret_key'             => '',
);

$initial_data = array(
	'form_id' => 0,
	'title'   => '',
	'schema'  => array(
		'version'   => 1,
		'source'    => 'ajforms',
		'fields'    => array(),
		'settings'  => array(
			'submit_text'           => 'Submit',
			'notifications_enabled' => '1' === (string) $plugin_settings['default_notifications_enabled'],
			'notification_email'    => $plugin_settings['default_notification_email'],
			'notification_subject'  => $plugin_settings['default_notification_subject'],
			'notification_body'     => $default_notification_body,
			'notification_from_name' => isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ),
			'notification_from_email' => '',
			'notification_reply_to' => '',
			'button_alignment'      => 'left',
			'form_description'      => '',
			'success_message'       => 'Form submitted successfully.',
			'confirmation_type'     => 'message',
			'redirect_url'          => '',
			'confirmation_rules'    => array(),
			'use_label_placeholders' => false,
			'custom_css'            => '',
			'asana_task_enabled'    => false,
			'asana_task_name'       => 'New form submission: {form_title}',
			'asana_task_notes'      => $default_asana_notes,
			'asana_project_gid'     => $plugin_settings['asana_project_gid'],
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
			'border_radius'         => '16',
		),
		'sureforms' => array(),
	),
);

if ( $form_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'ajforms_forms';
	$form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $form_id ) );

	if ( $form ) {
		$decoded_schema = json_decode( $form->form_schema, true );

		if ( isset( $decoded_schema['fields'] ) && is_array( $decoded_schema['fields'] ) ) {
			$normalized_schema = array(
				'version'   => isset( $decoded_schema['version'] ) ? intval( $decoded_schema['version'] ) : 1,
				'source'    => isset( $decoded_schema['source'] ) ? sanitize_text_field( $decoded_schema['source'] ) : 'ajforms',
				'fields'    => $decoded_schema['fields'],
				'settings'  => isset( $decoded_schema['settings'] ) && is_array( $decoded_schema['settings'] ) ? wp_parse_args(
					$decoded_schema['settings'],
					array(
						'submit_text'           => 'Submit',
						'notifications_enabled' => '1' === (string) $plugin_settings['default_notifications_enabled'],
						'notification_email'    => $plugin_settings['default_notification_email'],
						'notification_subject'  => $plugin_settings['default_notification_subject'],
						'notification_body'     => $default_notification_body,
						'notification_from_name' => isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ),
						'notification_from_email' => '',
						'notification_reply_to' => '',
						'button_alignment'      => 'left',
						'form_description'      => '',
						'success_message'       => 'Form submitted successfully.',
						'confirmation_type'     => 'message',
						'redirect_url'          => '',
						'confirmation_rules'    => array(),
						'use_label_placeholders' => false,
						'custom_css'            => '',
						'asana_task_enabled'    => false,
						'asana_task_name'       => 'New form submission: {form_title}',
						'asana_task_notes'      => $default_asana_notes,
						'asana_project_gid'     => $plugin_settings['asana_project_gid'],
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
						'border_radius'         => '16',
					)
				) : array(
					'submit_text'           => 'Submit',
					'notifications_enabled' => '1' === (string) $plugin_settings['default_notifications_enabled'],
					'notification_email'    => $plugin_settings['default_notification_email'],
					'notification_subject'  => $plugin_settings['default_notification_subject'],
					'notification_body'     => $default_notification_body,
					'notification_from_name' => isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ),
					'notification_from_email' => '',
					'notification_reply_to' => '',
					'button_alignment'      => 'left',
					'form_description'      => '',
					'success_message'       => 'Form submitted successfully.',
					'confirmation_type'     => 'message',
					'redirect_url'          => '',
					'confirmation_rules'    => array(),
					'use_label_placeholders' => false,
					'custom_css'            => '',
					'asana_task_enabled'    => false,
					'asana_task_name'       => 'New form submission: {form_title}',
					'asana_task_notes'      => $default_asana_notes,
					'asana_project_gid'     => $plugin_settings['asana_project_gid'],
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
					'border_radius'         => '16',
				),
				'sureforms' => isset( $decoded_schema['sureforms'] ) && is_array( $decoded_schema['sureforms'] ) ? $decoded_schema['sureforms'] : array(),
			);
		} elseif ( is_array( $decoded_schema ) ) {
			$normalized_schema = array(
				'version'   => 1,
				'source'    => 'legacy',
				'fields'    => $decoded_schema,
				'settings'  => array(
					'submit_text'           => 'Submit',
					'notifications_enabled' => '1' === (string) $plugin_settings['default_notifications_enabled'],
					'notification_email'    => $plugin_settings['default_notification_email'],
					'notification_subject'  => $plugin_settings['default_notification_subject'],
					'notification_body'     => $default_notification_body,
					'notification_from_name' => isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ),
					'notification_from_email' => '',
					'notification_reply_to' => '',
					'button_alignment'      => 'left',
					'form_description'      => '',
					'success_message'       => 'Form submitted successfully.',
					'confirmation_type'     => 'message',
					'redirect_url'          => '',
					'use_label_placeholders' => false,
					'custom_css'            => '',
				),
				'sureforms' => array(),
			);
		} else {
			$normalized_schema = $initial_data['schema'];
		}

		$initial_data['form_id'] = $form_id;
		$initial_data['title']   = $form->title;
		$initial_data['schema']  = $normalized_schema;
	}
}
?>
<script>
window.ajFormsInitialData = <?php echo wp_json_encode( $initial_data ); ?>;
</script>

<div class="ajforms-builder-wrap">
	<div class="wpf-toolbar">
		<div class="wpf-toolbar-left">
			<button id="wpf-toggle-fields-btn" class="wpf-toolbar-icon-btn wpf-toolbar-icon-btn-primary" type="button" title="Add Field">+</button>
			<a href="<?php echo esc_url( $back_url ); ?>" class="wpf-toolbar-icon-btn" title="Back to Forms">←</a>
			<button id="wpf-undo-btn" class="wpf-toolbar-icon-btn" type="button" disabled title="Undo">↶</button>
			<button id="wpf-redo-btn" class="wpf-toolbar-icon-btn" type="button" disabled title="Redo">↷</button>
			<button id="wpf-toggle-structure-btn" class="wpf-toolbar-icon-btn" type="button" title="Form Structure">☰</button>
		</div>

		<div class="wpf-toolbar-center">
			<input
				type="text"
				id="wpf-form-title"
				class="wpf-form-title-input"
				value="<?php echo esc_attr( $initial_data['title'] ); ?>"
				placeholder="Enter a unique form title..."
			>
		</div>

			<div class="wpf-toolbar-right">
			<div class="wpf-toolbar-dropdown">
				<button id="wpf-form-settings-toggle" class="wpf-btn wpf-btn-secondary wpf-settings-trigger" type="button">Form Settings <span>▾</span></button>
				<div id="wpf-form-settings-menu" class="wpf-settings-menu">
					<button type="button" class="wpf-settings-menu-item" data-section="basics">Basics</button>
					<button type="button" class="wpf-settings-menu-item" data-section="notifications">Email Notification</button>
					<button type="button" class="wpf-settings-menu-item" data-section="confirmation">Form Confirmation</button>
					<button type="button" class="wpf-settings-menu-item" data-section="integrations">Integrations</button>
					<button type="button" class="wpf-settings-menu-item" data-section="advanced">Advanced</button>
				</div>
			</div>
			<?php if ( $form_id ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'ajforms_preview' => $form_id ), home_url( '/' ) ) ); ?>" id="wpf-preview-form-link" class="wpf-btn wpf-btn-secondary" target="_blank" rel="noopener noreferrer">Preview</a>
			<?php else : ?>
				<button id="wpf-preview-form-link" class="wpf-btn wpf-btn-secondary" type="button" disabled>Preview</button>
			<?php endif; ?>
			<button id="wpf-save-draft-btn" class="wpf-btn wpf-btn-secondary" type="button">Save Draft</button>
			<button id="wpf-save-form-btn" class="wpf-btn wpf-btn-primary" type="button">Publish Form</button>
		</div>
	</div>

	<div class="wpf-body">
		<div class="wpf-sidebar-left" id="wpf-fields-sidebar">
			<div class="wpf-sidebar-header">
				<span>Form Builder</span>
				<div class="wpf-sidebar-toggle-tabs">
					<button class="wpf-sidebar-toggle-tab" type="button" data-drawer-panel="fields">Fields</button>
					<button class="wpf-sidebar-toggle-tab is-active" type="button" data-drawer-panel="structure">Structure</button>
				</div>
			</div>
			<div class="wpf-drawer-panel" data-drawer-panel-content="fields">
			<div class="wpf-fields-library" id="wpf-fields-library">
			<div class="wpf-fields-search">
				<input type="text" placeholder="Search fields...">
			</div>
			<div class="wpf-fields-grid">
				<?php
				$fields = array(
					'Question'        => 'question',
					'Text'            => 'text',
					'Email'           => 'email',
					'URL'             => 'url',
					'Textarea'        => 'textarea',
					'Dropdown'        => 'select',
					'Single Choice'   => 'multiple_choice',
					'Multiple Choice' => 'checkboxes',
					'Number'          => 'number',
					'Phone'           => 'phone',
					'Address'         => 'address',
					'Date'            => 'date',
					'File Upload'     => 'file',
					'Separator'       => 'separator',
				);

				foreach ( $fields as $field_label => $field_type ) {
					echo '<div class="wpf-field-btn" draggable="true" data-type="' . esc_attr( $field_type ) . '" data-label="' . esc_attr( $field_label ) . '">❖ ' . esc_html( $field_label ) . '</div>';
				}
				?>
			</div>
			</div>
			</div>
			<div class="wpf-drawer-panel is-active" data-drawer-panel-content="structure">
				<div class="wpf-structure-drawer">
					<div id="wpf-structure-list" class="wpf-structure-list">
						<p class="wpf-structure-empty">No fields added yet.</p>
					</div>
				</div>
			</div>
		</div>

		<div class="wpf-canvas-area" id="wpf-dropzone">
			<div class="wpf-canvas" id="wpf-canvas-fields">
				<div class="wpf-empty-state">
					<h3>No fields here yet.</h3>
					<p>Drag and drop fields from the left sidebar to start building your form.</p>
				</div>
			</div>
		</div>

		<div class="wpf-sidebar-right">
			<div class="wpf-inspector-top-tabs">
				<div class="wpf-tab active" data-target="form">Form</div>
				<div class="wpf-tab" data-target="field">Block</div>
			</div>

			<div class="wpf-settings-panels">
				<div id="wpf-panel-form" class="wpf-settings-panel active">
					<div class="wpf-inspector-subtabs">
						<button type="button" class="wpf-inspector-subtab is-active" data-form-subtab="general">General</button>
						<button type="button" class="wpf-inspector-subtab" data-form-subtab="style">Style</button>
					</div>
					<div class="wpf-inspector-subpanel is-active" data-form-subpanel="general">
						<div class="wpf-inspector-nav">
							<button type="button" class="wpf-inspector-nav-item is-active" data-settings-section-tab="basics">Basics</button>
							<button type="button" class="wpf-inspector-nav-item" data-settings-section-tab="notifications">Notifications</button>
							<button type="button" class="wpf-inspector-nav-item" data-settings-section-tab="confirmation">Confirmation</button>
							<button type="button" class="wpf-inspector-nav-item" data-settings-section-tab="integrations">Integrations</button>
							<button type="button" class="wpf-inspector-nav-item" data-settings-section-tab="advanced">Advanced</button>
						</div>
						<div class="wpf-inspector-section is-active" data-settings-section="basics">
							<div class="wpf-inspector-section-title">General</div>
							<div class="wpf-setting-row">
								<label>Form Description</label>
								<textarea id="wpf-form-description" rows="3"><?php echo esc_textarea( isset( $initial_data['schema']['settings']['form_description'] ) ? $initial_data['schema']['settings']['form_description'] : '' ); ?></textarea>
							</div>
							<div class="wpf-setting-row">
								<label>Submit Button Text</label>
								<input type="text" id="wpf-form-submit-text" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['submit_text'] ) ? $initial_data['schema']['settings']['submit_text'] : 'Submit' ); ?>">
							</div>
							<div class="wpf-setting-row">
								<label class="wpf-toggle-row">
									<span>Use Labels as Placeholders</span>
									<input type="checkbox" id="wpf-form-use-label-placeholders" <?php checked( ! empty( $initial_data['schema']['settings']['use_label_placeholders'] ) ); ?>>
								</label>
								<p class="wpf-setting-help">Places labels inside fields where that pattern makes sense.</p>
							</div>
						</div>

						<div class="wpf-inspector-section" data-settings-section="notifications">
							<div class="wpf-inspector-section-title">Email Notification</div>
							<div class="wpf-setting-row">
								<label class="wpf-toggle-row">
									<span>Enable Admin Notifications</span>
									<input type="checkbox" id="wpf-form-notifications" <?php checked( ! empty( $initial_data['schema']['settings']['notifications_enabled'] ) ); ?>>
								</label>
							</div>
							<div class="wpf-setting-row">
								<label>Send Email To</label>
								<input type="text" id="wpf-form-notification-email" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['notification_email'] ) ? $initial_data['schema']['settings']['notification_email'] : $plugin_settings['default_notification_email'] ); ?>">
								<p class="wpf-setting-help">Use one or more emails separated by commas.</p>
							</div>
							<div class="wpf-setting-row">
								<label>Subject</label>
								<input type="text" id="wpf-form-notification-subject" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['notification_subject'] ) ? $initial_data['schema']['settings']['notification_subject'] : $plugin_settings['default_notification_subject'] ); ?>">
							</div>
							<div class="wpf-field-settings-grid">
								<div class="wpf-setting-row">
									<label>From Name</label>
									<input type="text" id="wpf-form-notification-from-name" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['notification_from_name'] ) ? $initial_data['schema']['settings']['notification_from_name'] : ( isset( $plugin_settings['default_from_name'] ) ? $plugin_settings['default_from_name'] : get_bloginfo( 'name' ) ) ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>From Email</label>
									<input type="text" id="wpf-form-notification-from-email" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['notification_from_email'] ) ? $initial_data['schema']['settings']['notification_from_email'] : '' ); ?>">
								</div>
							</div>
							<div class="wpf-setting-row">
								<label>Reply to Address</label>
								<input type="text" id="wpf-form-notification-reply-to" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['notification_reply_to'] ) ? $initial_data['schema']['settings']['notification_reply_to'] : '' ); ?>">
							</div>
							<div class="wpf-setting-row">
								<label>Message Body</label>
								<?php
								$notification_body = isset( $initial_data['schema']['settings']['notification_body'] ) ? $initial_data['schema']['settings']['notification_body'] : $default_notification_body;
								wp_editor(
									$notification_body,
									'wpf_form_notification_body',
									array(
										'textarea_name' => 'wpf_form_notification_body',
										'textarea_rows' => 8,
										'media_buttons' => false,
										'teeny'         => true,
										'quicktags'     => true,
									)
								);
								?>
								<div class="wpf-variable-list" id="wpf-notification-variables">
									<strong>Available variables</strong>
									<code>{form_title}</code>
									<code>{submission_fields}</code>
									<code>{submission_table}</code>
									<code>{submission_details}</code>
									<code>{submission_details_table}</code>
									<code>{submitted_at}</code>
									<?php foreach ( $initial_data['schema']['fields'] as $index => $field ) : ?>
										<?php
										if ( ! is_array( $field ) ) {
											continue;
										}
										$field_name = ! empty( $field['field_name'] ) ? sanitize_key( $field['field_name'] ) : 'field_' . ( $index + 1 );
										?>
										<code>{field_<?php echo esc_html( $index + 1 ); ?>}</code>
										<code>{<?php echo esc_html( $field_name ); ?>}</code>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<div class="wpf-inspector-section" data-settings-section="confirmation">
							<div class="wpf-inspector-section-title">Form Confirmation</div>
							<p class="wpf-setting-help">Default confirmation runs when no conditional rule matches.</p>
							<div class="wpf-setting-row">
								<label>Default Confirmation Type</label>
								<select id="wpf-form-confirmation-type">
									<option value="message" <?php selected( isset( $initial_data['schema']['settings']['confirmation_type'] ) ? $initial_data['schema']['settings']['confirmation_type'] : 'message', 'message' ); ?>>Show Success Message</option>
									<option value="redirect" <?php selected( isset( $initial_data['schema']['settings']['confirmation_type'] ) ? $initial_data['schema']['settings']['confirmation_type'] : 'message', 'redirect' ); ?>>Redirect to URL</option>
								</select>
							</div>
							<div class="wpf-setting-row">
								<label>Success Message</label>
								<textarea id="wpf-form-success-message" rows="3"><?php echo esc_textarea( isset( $initial_data['schema']['settings']['success_message'] ) ? $initial_data['schema']['settings']['success_message'] : 'Form submitted successfully.' ); ?></textarea>
							</div>
							<div class="wpf-setting-row">
								<label>Redirect URL</label>
								<input type="text" id="wpf-form-redirect-url" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['redirect_url'] ) ? $initial_data['schema']['settings']['redirect_url'] : '' ); ?>">
							</div>
							<div class="wpf-field-settings-card" style="margin-top:18px;">
								<div class="wpf-field-settings-card-title">Conditional Confirmation Rules</div>
								<p class="wpf-setting-help">Rules run after a successful submission. Matching rules can show a message, redirect, trigger a webhook, or continue to the next rule.</p>
								<div id="wpf-confirmation-rules"></div>
								<button type="button" class="wpf-btn wpf-btn-secondary" id="wpf-add-confirmation-rule" style="margin-top:12px;">Add Rule</button>
							</div>
						</div>

						<div class="wpf-inspector-section" data-settings-section="integrations">
							<div class="wpf-inspector-section-title">Integrations</div>
							<div class="wpf-setting-row">
								<label class="wpf-toggle-row">
									<span>Create Asana Task</span>
									<input type="checkbox" id="wpf-form-asana-task-enabled" <?php checked( ! empty( $initial_data['schema']['settings']['asana_task_enabled'] ) ); ?> <?php disabled( empty( $plugin_settings['asana_enabled'] ) ); ?>>
								</label>
								<p class="wpf-setting-help"><?php echo ! empty( $plugin_settings['asana_enabled'] ) ? esc_html__( 'When enabled, each successful submission creates a new Asana task.', 'ajforms' ) : esc_html__( 'Enable Asana globally on the Settings page first.', 'ajforms' ); ?></p>
							</div>
							<div class="wpf-setting-row">
								<label>Asana Task Name</label>
								<input type="text" id="wpf-form-asana-task-name" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['asana_task_name'] ) ? $initial_data['schema']['settings']['asana_task_name'] : 'New form submission: {form_title}' ); ?>">
								<p class="wpf-setting-help">Use <code>{form_title}</code>, <code>{submission_count}</code>, numbered tags like <code>{field_1}</code>, or custom field names like <code>{email}</code>.</p>
							</div>
							<div class="wpf-setting-row">
								<label>Asana Task Notes</label>
								<textarea id="wpf-form-asana-task-notes" rows="8"><?php echo esc_textarea( isset( $initial_data['schema']['settings']['asana_task_notes'] ) ? $initial_data['schema']['settings']['asana_task_notes'] : $default_asana_notes ); ?></textarea>
								<p class="wpf-setting-help">Use <code>{form_title}</code>, <code>{submission_fields}</code>, <code>{submission_details}</code>, numbered tags like <code>{field_1}</code>, or custom field names like <code>{email}</code>.</p>
							</div>
							<div class="wpf-field-settings-grid">
								<div class="wpf-setting-row">
									<label>Assignee</label>
									<select id="wpf-form-asana-assignee-gid">
										<option value=""><?php esc_html_e( 'No assignee', 'ajforms' ); ?></option>
										<?php foreach ( $asana_cache['users'] as $user ) : ?>
											<?php
											if ( empty( $user['gid'] ) || empty( $user['name'] ) ) {
												continue;
											}
											$user_label = $user['name'] . ( ! empty( $user['email'] ) ? ' (' . $user['email'] . ')' : '' );
											?>
											<option value="<?php echo esc_attr( $user['gid'] ); ?>" <?php selected( isset( $initial_data['schema']['settings']['asana_assignee_gid'] ) ? $initial_data['schema']['settings']['asana_assignee_gid'] : '', $user['gid'] ); ?>><?php echo esc_html( $user_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="wpf-setting-row">
									<label>Due Date</label>
									<select id="wpf-form-asana-due-date">
										<option value="today" <?php selected( isset( $initial_data['schema']['settings']['asana_due_date'] ) ? $initial_data['schema']['settings']['asana_due_date'] : 'today', 'today' ); ?>><?php esc_html_e( 'Today', 'ajforms' ); ?></option>
										<option value="none" <?php selected( isset( $initial_data['schema']['settings']['asana_due_date'] ) ? $initial_data['schema']['settings']['asana_due_date'] : 'today', 'none' ); ?>><?php esc_html_e( 'No due date', 'ajforms' ); ?></option>
									</select>
								</div>
							</div>
							<div class="wpf-setting-row">
								<label>Project GID Override</label>
								<input type="text" id="wpf-form-asana-project-gid" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['asana_project_gid'] ) ? $initial_data['schema']['settings']['asana_project_gid'] : $plugin_settings['asana_project_gid'] ); ?>">
								<p class="wpf-setting-help">Optional. Leave blank to use the global Asana project from Settings.</p>
							</div>
							<div class="wpf-setting-row">
								<label class="wpf-toggle-row">
									<span>Enable Stripe Payments</span>
									<input type="checkbox" id="wpf-form-stripe-enabled" <?php checked( ! empty( $initial_data['schema']['settings']['stripe_enabled'] ) ); ?> <?php disabled( empty( $plugin_settings['stripe_publishable_key'] ) || empty( $plugin_settings['stripe_secret_key'] ) ); ?>>
								</label>
								<p class="wpf-setting-help"><?php echo ( ! empty( $plugin_settings['stripe_publishable_key'] ) && ! empty( $plugin_settings['stripe_secret_key'] ) ) ? esc_html__( 'Keys are connected globally. Turn this on only for forms that should collect payment.', 'ajforms' ) : esc_html__( 'Add Stripe keys on the Stripe Payments settings page first.', 'ajforms' ); ?></p>
							</div>
							<div class="wpf-setting-row">
								<label>Payment Amount</label>
								<input type="number" id="wpf-form-stripe-amount" min="0" step="0.01" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['stripe_amount'] ) ? $initial_data['schema']['settings']['stripe_amount'] : '' ); ?>">
							</div>
							<div class="wpf-setting-row">
								<label>Currency</label>
								<select id="wpf-form-stripe-currency">
									<?php foreach ( array( 'usd' => 'USD', 'eur' => 'EUR', 'gbp' => 'GBP', 'cad' => 'CAD', 'aud' => 'AUD' ) as $currency_code => $currency_label ) : ?>
										<option value="<?php echo esc_attr( $currency_code ); ?>" <?php selected( isset( $initial_data['schema']['settings']['stripe_currency'] ) ? $initial_data['schema']['settings']['stripe_currency'] : 'usd', $currency_code ); ?>><?php echo esc_html( $currency_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="wpf-setting-row">
								<label>Payment Description</label>
								<input type="text" id="wpf-form-stripe-description" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['stripe_description'] ) ? $initial_data['schema']['settings']['stripe_description'] : 'Payment for {form_title}' ); ?>">
								<p class="wpf-setting-help">Use <code>{form_title}</code>, numbered tags like <code>{field_1}</code>, or custom field names like <code>{email}</code>.</p>
							</div>
						</div>

						<div class="wpf-inspector-section" data-settings-section="advanced">
							<div class="wpf-inspector-section-title">Advanced</div>
							<div class="wpf-setting-row">
								<label>Custom CSS</label>
								<textarea id="wpf-form-custom-css" rows="5"><?php echo esc_textarea( isset( $initial_data['schema']['settings']['custom_css'] ) ? $initial_data['schema']['settings']['custom_css'] : '' ); ?></textarea>
							</div>
							<div class="wpf-setting-row">
								<label>Spam Protection</label>
								<p class="wpf-setting-help">Honeypot and CAPTCHA providers are managed globally on the main Settings screen. Keep form-level spam controls light here unless there is a clear need.</p>
							</div>
						</div>
					</div>

					<div class="wpf-inspector-subpanel" data-form-subpanel="style">
						<div class="wpf-inspector-section">
							<div class="wpf-inspector-section-title">Style</div>
							<div class="wpf-setting-row">
								<label>Form Theme</label>
								<select id="wpf-form-theme">
									<option value="clean" <?php selected( isset( $initial_data['schema']['settings']['form_theme'] ) ? $initial_data['schema']['settings']['form_theme'] : 'clean', 'clean' ); ?>>Clean</option>
									<option value="soft" <?php selected( isset( $initial_data['schema']['settings']['form_theme'] ) ? $initial_data['schema']['settings']['form_theme'] : 'clean', 'soft' ); ?>>Soft</option>
									<option value="contrast" <?php selected( isset( $initial_data['schema']['settings']['form_theme'] ) ? $initial_data['schema']['settings']['form_theme'] : 'clean', 'contrast' ); ?>>Contrast</option>
								</select>
							</div>
							<div class="wpf-setting-row">
								<label>Background</label>
								<select id="wpf-form-background-mode">
									<option value="solid" <?php selected( isset( $initial_data['schema']['settings']['background_mode'] ) ? $initial_data['schema']['settings']['background_mode'] : 'solid', 'solid' ); ?>>Solid</option>
									<option value="gradient" <?php selected( isset( $initial_data['schema']['settings']['background_mode'] ) ? $initial_data['schema']['settings']['background_mode'] : 'solid', 'gradient' ); ?>>Gradient</option>
								</select>
							</div>
							<div class="wpf-style-color-grid">
								<div class="wpf-setting-row">
									<label>Background Color</label>
									<input type="color" id="wpf-form-background-color" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['background_color'] ) ? $initial_data['schema']['settings']['background_color'] : '#ffffff' ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>Gradient Start</label>
									<input type="color" id="wpf-form-gradient-start" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['background_gradient_start'] ) ? $initial_data['schema']['settings']['background_gradient_start'] : '#ffffff' ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>Gradient End</label>
									<input type="color" id="wpf-form-gradient-end" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['background_gradient_end'] ) ? $initial_data['schema']['settings']['background_gradient_end'] : '#f3f7fb' ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>Primary Color</label>
									<input type="color" id="wpf-form-primary-color" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['primary_color'] ) ? $initial_data['schema']['settings']['primary_color'] : '#0f7ac6' ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>Text Color</label>
									<input type="color" id="wpf-form-text-color" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['text_color'] ) ? $initial_data['schema']['settings']['text_color'] : '#1f2937' ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>Input Surface</label>
									<input type="color" id="wpf-form-input-background" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['input_background'] ) ? $initial_data['schema']['settings']['input_background'] : '#ffffff' ); ?>">
								</div>
								<div class="wpf-setting-row">
									<label>Input Border</label>
									<input type="color" id="wpf-form-input-border" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['input_border_color'] ) ? $initial_data['schema']['settings']['input_border_color'] : '#d7dce3' ); ?>">
								</div>
							</div>
							<div class="wpf-setting-row">
								<label>Border Radius</label>
								<input type="range" id="wpf-form-border-radius" min="0" max="32" step="2" value="<?php echo esc_attr( isset( $initial_data['schema']['settings']['border_radius'] ) ? $initial_data['schema']['settings']['border_radius'] : '16' ); ?>">
								<div class="wpf-setting-help"><span id="wpf-form-border-radius-value"><?php echo esc_html( isset( $initial_data['schema']['settings']['border_radius'] ) ? $initial_data['schema']['settings']['border_radius'] : '16' ); ?></span>px</div>
							</div>
							<div class="wpf-setting-row">
								<label>Button Alignment</label>
								<select id="wpf-form-button-alignment">
									<option value="left" <?php selected( isset( $initial_data['schema']['settings']['button_alignment'] ) ? $initial_data['schema']['settings']['button_alignment'] : 'left', 'left' ); ?>>Left</option>
									<option value="center" <?php selected( isset( $initial_data['schema']['settings']['button_alignment'] ) ? $initial_data['schema']['settings']['button_alignment'] : 'left', 'center' ); ?>>Center</option>
									<option value="right" <?php selected( isset( $initial_data['schema']['settings']['button_alignment'] ) ? $initial_data['schema']['settings']['button_alignment'] : 'left', 'right' ); ?>>Right</option>
								</select>
							</div>
							<p class="wpf-setting-help">More visual style controls can stack here without changing the rest of the builder layout.</p>
						</div>
					</div>
				</div>

				<div id="wpf-panel-field" class="wpf-settings-panel">
					<p style="color:#646970;font-size:13px;">Select a field in the canvas to edit its settings.</p>
				</div>
			</div>
		</div>
	</div>
</div>
