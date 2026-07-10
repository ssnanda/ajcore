<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Leads live on the shared portal DB in multi-site mode; form_title is stored on the lead
// row itself (forms are per-site, so a JOIN can't resolve titles for other sites' leads).
$wpdb = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];

$lead_id          = isset( $_GET['lead_id'] ) ? absint( wp_unslash( $_GET['lead_id'] ) ) : 0;
$leads_table      = $wpdb->prefix . 'aj_forms_leads';
$lead_notes_table = $wpdb->prefix . 'aj_forms_lead_notes';
$admin            = new AJForms_Admin();

$lead = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT l.* FROM {$leads_table} l WHERE l.id = %d",
		$lead_id
	)
);

if ( ! $lead ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Lead not found.', 'ajforms' ) . '</h1></div>';
	return;
}

// Only look up the form locally for this site's own leads — form IDs collide across sites.
$lead_site_uuid  = isset( $lead->site_uuid ) ? (string) $lead->site_uuid : '';
$is_local_lead   = ( '' === $lead_site_uuid || $lead_site_uuid === (string) get_option( 'ajcore_site_uuid', '' ) );
$form        = ( $lead->form_id && $is_local_lead ) ? $admin->get_form_record( $lead->form_id ) : null;
$form_fields = ( $lead->form_id && $is_local_lead ) ? $admin->get_form_schema_fields( $lead->form_id ) : array();
if ( ( ! isset( $lead->form_title ) || '' === (string) $lead->form_title ) ) {
	if ( $form && ! empty( $form->title ) ) {
		$lead->form_title = (string) $form->title;
	} else {
		$lead->form_title = $lead->form_id ? sprintf( __( 'Form #%d', 'ajforms' ), (int) $lead->form_id ) : __( 'Manual entry', 'ajforms' );
	}
}
$notes       = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$lead_notes_table} WHERE lead_id = %d ORDER BY created_at DESC",
		$lead_id
	)
);

$data = json_decode( $lead->lead_data, true );
if ( ! is_array( $data ) ) {
	$data = array();
}

$field_map = array();
foreach ( $form_fields as $field ) {
	if ( is_array( $field ) && ! empty( $field['id'] ) ) {
		$field_map[ $field['id'] ] = $field;
	}
}

$orphan_fields = array();
foreach ( $data as $field_key => $field_value ) {
	if ( ! isset( $field_map[ $field_key ] ) ) {
		$orphan_fields[ $field_key ] = $field_value;
	}
}

$back_url = admin_url( 'admin.php?page=ajforms-leads' );
$form_edit_url = $form ? add_query_arg(
	array(
		'page'    => 'ajforms',
		'action'  => 'edit',
		'form_id' => absint( $form->id ),
	),
	admin_url( 'admin.php' )
) : '';

$status_labels = array( 'new' => __( 'New', 'ajforms' ), 'read' => __( 'Read', 'ajforms' ), 'won' => __( 'Won', 'ajforms' ), 'lost' => __( 'Lost', 'ajforms' ), 'duplicate' => __( 'Duplicate', 'ajforms' ) );

$lead_action_url = function ( $action ) use ( $lead_id ) {
	return wp_nonce_url(
		add_query_arg(
			array( 'page' => 'ajforms-leads', 'view' => 'detail', 'lead_action' => $action, 'lead_id' => $lead_id ),
			admin_url( 'admin.php' )
		),
		'ajf_lead_action_' . $lead_id
	);
};

$status_actions = array();
if ( in_array( $lead->status, array( 'new', 'read' ), true ) ) {
	$status_actions['mark_new']      = __( 'Mark New', 'ajforms' );
	$status_actions['mark_read']     = __( 'Mark Read', 'ajforms' );
	$status_actions['mark_lost']     = __( 'Mark Lost', 'ajforms' );
	$status_actions['mark_duplicate']= __( 'Mark Duplicate', 'ajforms' );
} else {
	$status_actions['reopen'] = __( 'Reopen to Inbox', 'ajforms' );
}

$linked_customer_name = '';
if ( 'won' === $lead->status && ! empty( $lead->stripe_customer_id ) ) {
	$pdb_lookup = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $wpdb;
	$linked_customer_name = (string) $pdb_lookup->get_var( $pdb_lookup->prepare( "SELECT name FROM {$pdb_lookup->prefix}aj_portal_stripe_customers WHERE stripe_customer_id = %s", $lead->stripe_customer_id ) );
}

$merged_into_lead_link = '';
if ( ! empty( $lead->merged_into_lead_id ) ) {
	$merged_into_lead_link = add_query_arg( array( 'page' => 'ajforms-leads', 'view' => 'detail', 'lead_id' => absint( $lead->merged_into_lead_id ) ), admin_url( 'admin.php' ) );
}

// All customers, for the "Mark Won" picker — a plain select is enough for an internal admin tool
// (AJOps has the richer searchable picker for day-to-day use).
$pdb_for_customers = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $wpdb;
$won_customers      = $pdb_for_customers->get_results( "SELECT stripe_customer_id, name, email FROM {$pdb_for_customers->prefix}aj_portal_stripe_customers ORDER BY name ASC LIMIT 3000" );

$delete_url = wp_nonce_url(
	add_query_arg(
		array(
			'page'        => 'ajforms-leads',
			'lead_action' => 'delete',
			'lead_id'     => $lead_id,
		),
		admin_url( 'admin.php' )
	),
	'ajf_lead_action_' . $lead_id
);
?>

<div class="wrap">
	<style>
		.ajforms-entry-shell {
			margin-top: 18px;
		}

		.ajforms-entry-hero {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 20px;
			padding: 28px 30px;
			background: linear-gradient(135deg, #fff 0%, #f8fafc 50%, #eef7ff 100%);
			border: 1px solid #dde7f2;
			border-radius: 26px;
			box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
			margin-bottom: 18px;
		}

		.ajforms-entry-backlink {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			text-decoration: none;
			color: #475569;
			font-weight: 600;
		}

		.ajforms-entry-hero h1 {
			margin: 14px 0 8px;
			font-size: 30px;
			line-height: 1.1;
		}

		.ajforms-entry-hero p {
			margin: 0;
			color: #64748b;
			max-width: 740px;
			font-size: 15px;
			line-height: 1.7;
		}

		.ajforms-entry-hero-actions {
			display: flex;
			flex-wrap: wrap;
			justify-content: flex-end;
			gap: 10px;
		}

		.ajforms-entry-layout {
			display: grid;
			grid-template-columns: minmax(0, 1.65fr) minmax(320px, .85fr);
			gap: 18px;
		}

		.ajforms-entry-secondary {
			display: flex;
			flex-direction: column;
			gap: 18px;
		}

		.ajforms-lead-card {
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 24px;
			padding: 24px;
			box-shadow: 0 18px 42px rgba(15, 23, 42, 0.05);
		}

		.ajforms-lead-card h2,
		.ajforms-lead-card h3 {
			margin-top: 0;
		}

		.ajforms-lead-meta-table {
			width: 100%;
			border-collapse: collapse;
		}

		.ajforms-lead-meta-table th,
		.ajforms-lead-meta-table td {
			padding: 16px 0;
			border-bottom: 1px solid #eef2f7;
			vertical-align: top;
		}

		.ajforms-lead-meta-table tr:last-child th,
		.ajforms-lead-meta-table tr:last-child td {
			border-bottom: 0;
		}

		.ajforms-lead-meta-table th {
			color: #334155;
			font-weight: 700;
		}

		.ajforms-lead-meta-table td input[type="text"],
		.ajforms-lead-meta-table td input[type="email"],
		.ajforms-lead-meta-table td input[type="url"],
		.ajforms-lead-meta-table td input[type="number"],
		.ajforms-lead-meta-table td input[type="tel"],
		.ajforms-lead-meta-table td input[type="date"],
		.ajforms-lead-meta-table td input[type="file"],
		.ajforms-lead-meta-table td textarea,
		.ajforms-lead-meta-table td select {
			width: 100%;
			border: 1px solid #d5dee8;
			border-radius: 14px;
			padding: 11px 13px;
			background: #fff;
			box-sizing: border-box;
		}

		.ajforms-lead-meta-table td textarea {
			min-height: 120px;
			resize: vertical;
		}

		.ajforms-entry-status-row {
			display: flex;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
			margin-top: 10px;
		}

		.ajforms-status-badge {
			display: inline-flex;
			align-items: center;
			padding: 6px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
		}

		.ajforms-status-badge.read {
			background: #eff6ff;
			color: #1d4ed8;
		}

		.ajforms-status-badge.new {
			background: #fef3c7;
			color: #92400e;
		}

		.ajforms-status-badge.won {
			background: #dcfce7;
			color: #166534;
		}

		.ajforms-status-badge.lost {
			background: #fee2e2;
			color: #b91c1c;
		}

		.ajforms-status-badge.duplicate {
			background: #f1f5f9;
			color: #475569;
		}

		.ajforms-mark-won-panel {
			display: none;
			margin-top: 14px;
			padding: 14px 18px;
			background: #f0fdf4;
			border: 1px solid #bbf7d0;
			border-radius: 14px;
		}

		.ajforms-mark-won-panel.is-visible {
			display: block;
		}

		.ajforms-mark-won-panel form {
			display: flex;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
		}

		.ajforms-mark-won-panel label {
			font-weight: 600;
			color: #166534;
		}

		.ajforms-entry-note {
			color: #64748b;
			font-size: 14px;
			line-height: 1.6;
		}

		.ajforms-note-form textarea {
			width: 100%;
			min-height: 110px;
			border: 1px solid #d5dee8;
			border-radius: 16px;
			padding: 12px 14px;
			box-sizing: border-box;
			resize: vertical;
		}

		.ajforms-note-list {
			display: flex;
			flex-direction: column;
			gap: 12px;
			margin-top: 18px;
		}

		.ajforms-note-item {
			padding: 16px 18px;
			border: 1px solid #e5edf5;
			border-radius: 18px;
			background: #fbfdff;
		}

		.ajforms-note-item strong {
			display: block;
			margin-bottom: 6px;
			color: #0f172a;
		}

		.ajforms-note-item span {
			display: block;
			margin-top: 8px;
			color: #64748b;
			font-size: 12px;
		}

		@media (max-width: 1100px) {
			.ajforms-entry-hero {
				flex-direction: column;
			}

			.ajforms-entry-layout {
				grid-template-columns: 1fr;
			}
		}
	</style>

	<div class="ajforms-entry-shell">
		<div class="ajforms-entry-hero">
			<div>
				<a class="ajforms-entry-backlink" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to Entries', 'ajforms' ); ?></a>
				<h1><?php echo esc_html( 'Entry #' . $lead_id ); ?></h1>
				<p><?php esc_html_e( 'Update saved values, replace uploaded files, review the submission context, and leave internal notes without losing track of the linked form.', 'ajforms' ); ?></p>
				<div class="ajforms-entry-status-row">
					<span class="ajforms-status-badge <?php echo esc_attr( $lead->status ); ?>"><?php echo esc_html( isset( $status_labels[ $lead->status ] ) ? $status_labels[ $lead->status ] : ucfirst( $lead->status ) ); ?></span>
					<?php if ( 'won' === $lead->status && $linked_customer_name ) : ?>
						<span class="ajforms-entry-note">&rarr; <?php echo esc_html( $linked_customer_name ); ?></span>
					<?php endif; ?>
					<?php if ( $merged_into_lead_link ) : ?>
						<span class="ajforms-entry-note"><?php esc_html_e( 'Merged into', 'ajforms' ); ?> <a href="<?php echo esc_url( $merged_into_lead_link ); ?>">#<?php echo absint( $lead->merged_into_lead_id ); ?></a></span>
					<?php endif; ?>
					<span class="ajforms-entry-note"><?php echo esc_html( $lead->form_title ? $lead->form_title : __( '(Form deleted)', 'ajforms' ) ); ?></span>
				</div>
			</div>
			<div class="ajforms-entry-hero-actions">
				<select id="ajf-lead-status-select" onchange="if(this.value){ location.href = this.value; }">
					<option value=""><?php esc_html_e( 'Change status…', 'ajforms' ); ?></option>
					<?php foreach ( $status_actions as $action_key => $action_label ) : ?>
						<option value="<?php echo esc_url( $lead_action_url( $action_key ) ); ?>"><?php echo esc_html( $action_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( in_array( $lead->status, array( 'new', 'read' ), true ) ) : ?>
					<button type="button" class="button" onclick="document.getElementById('ajf-mark-won-panel').classList.toggle('is-visible');"><?php esc_html_e( 'Mark Won…', 'ajforms' ); ?></button>
				<?php endif; ?>
				<?php if ( $form_edit_url ) : ?>
					<a class="button" href="<?php echo esc_url( $form_edit_url ); ?>"><?php esc_html_e( 'Edit Form', 'ajforms' ); ?></a>
				<?php endif; ?>
				<a class="button button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this lead?');"><?php esc_html_e( 'Delete', 'ajforms' ); ?></a>
			</div>
		</div>

		<div id="ajf-mark-won-panel" class="ajforms-mark-won-panel">
			<form method="get">
				<input type="hidden" name="page" value="ajforms-leads" />
				<input type="hidden" name="view" value="detail" />
				<input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead_id ); ?>" />
				<input type="hidden" name="lead_action" value="mark_won" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ajf_lead_action_' . $lead_id ) ); ?>" />
				<label><?php esc_html_e( 'Customer this lead became:', 'ajforms' ); ?></label>
				<select name="customer_id" required style="min-width:320px;">
					<option value=""><?php esc_html_e( '— Select customer —', 'ajforms' ); ?></option>
					<?php foreach ( $won_customers as $c ) : ?>
						<option value="<?php echo esc_attr( $c->stripe_customer_id ); ?>"><?php echo esc_html( ( $c->name ? $c->name : $c->stripe_customer_id ) . ' — ' . $c->email ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Mark Won', 'ajforms' ); ?></button>
			</form>
		</div>
	</div>

	<?php if ( isset( $_GET['entry-message'] ) ) : ?>
		<?php $message = sanitize_text_field( wp_unslash( $_GET['entry-message'] ) ); ?>
		<div class="notice <?php echo ( isset( $_GET['entry-updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['entry-updated'] ) ) ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['note-message'] ) ) : ?>
		<?php $note_message = sanitize_text_field( wp_unslash( $_GET['note-message'] ) ); ?>
		<div class="notice <?php echo ( isset( $_GET['note-updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['note-updated'] ) ) ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $note_message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $form ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'This entry is linked to a form that no longer exists, so it cannot be edited safely.', 'ajforms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $orphan_fields ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'This entry still contains data for fields that are no longer present on the form.', 'ajforms' ); ?>
				<?php if ( $form_edit_url ) : ?>
					<a href="<?php echo esc_url( $form_edit_url ); ?>"><?php esc_html_e( 'Open the form editor to correct the schema.', 'ajforms' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="ajforms-entry-layout">
		<div>
			<div class="ajforms-lead-card">
				<h2><?php esc_html_e( 'Entry Data', 'ajforms' ); ?></h2>

				<?php if ( $form ) : ?>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'ajf_update_lead_' . $lead_id, 'ajf_update_lead_nonce' ); ?>
						<input type="hidden" name="ajf_update_lead_id" value="<?php echo esc_attr( $lead_id ); ?>">

						<table class="ajforms-lead-meta-table">
							<tbody>
								<?php foreach ( $form_fields as $field ) : ?>
									<?php
									if ( ! is_array( $field ) || empty( $field['id'] ) ) {
										continue;
									}

									$field_id    = $field['id'];
									$field_type  = ! empty( $field['type'] ) ? $field['type'] : 'text';
									$field_label = ! empty( $field['label'] ) ? $field['label'] : $field_id;
									$placeholder = ! empty( $field['placeholder'] ) ? $field['placeholder'] : '';
									$required    = ! empty( $field['required'] );
									$options     = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
									$help_text   = ! empty( $field['help_text'] ) ? $field['help_text'] : '';
									$current     = isset( $data[ $field_id ] ) && is_array( $data[ $field_id ] ) ? $data[ $field_id ] : array();
									$value       = isset( $current['value'] ) ? $current['value'] : '';
									$file_name   = isset( $current['file_name'] ) ? $current['file_name'] : '';
									?>
									<?php if ( 'separator' === $field_type ) : ?>
										<tr>
											<td colspan="2"><hr></td>
										</tr>
										<?php continue; ?>
									<?php endif; ?>
									<?php if ( in_array( $field_type, array( 'note', 'heading', 'container' ), true ) ) : ?>
										<?php continue; ?>
									<?php endif; ?>
									<tr>
										<th style="width:220px;">
											<?php echo esc_html( $field_label ); ?>
											<?php if ( $required ) : ?><span style="color:#d63638;">*</span><?php endif; ?>
										</th>
										<td>
											<?php if ( 'textarea' === $field_type ) : ?>
												<textarea name="<?php echo esc_attr( $field_id ); ?>" rows="4" style="width:100%;"><?php echo esc_textarea( is_string( $value ) ? $value : '' ); ?></textarea>
											<?php elseif ( 'select' === $field_type ) : ?>
												<select name="<?php echo esc_attr( $field_id ); ?>" style="width:100%;">
													<option value=""><?php echo esc_html( $placeholder ?: __( 'Select an option', 'ajforms' ) ); ?></option>
													<?php foreach ( $options as $option ) : ?>
														<?php
														$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
														$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
														?>
														<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( 'checkboxes' === $field_type ) : ?>
												<?php $checked_values = is_array( $value ) ? $value : array(); ?>
												<?php foreach ( $options as $option ) : ?>
													<?php
													$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
													$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
													?>
													<label style="display:block;margin-bottom:6px;">
														<input type="checkbox" name="<?php echo esc_attr( $field_id ); ?>[]" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( in_array( $option_value, $checked_values, true ) ); ?>>
														<?php echo esc_html( $option_label ); ?>
													</label>
												<?php endforeach; ?>
											<?php elseif ( 'multiple_choice' === $field_type ) : ?>
												<?php foreach ( $options as $option ) : ?>
													<?php
													$option_label = is_array( $option ) && isset( $option['label'] ) ? $option['label'] : $option;
													$option_value = is_array( $option ) && isset( $option['value'] ) ? $option['value'] : $option_label;
													?>
													<label style="display:block;margin-bottom:6px;">
														<input type="radio" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( $value, $option_value ); ?>>
														<?php echo esc_html( $option_label ); ?>
													</label>
												<?php endforeach; ?>
											<?php elseif ( 'file' === $field_type ) : ?>
												<?php if ( ! empty( $value ) ) : ?>
													<div style="margin-bottom:8px;">
														<a href="<?php echo esc_url( $value ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $file_name ? $file_name : basename( (string) $value ) ); ?></a>
													</div>
												<?php endif; ?>
												<input type="file" name="<?php echo esc_attr( $field_id ); ?>" style="width:100%;">
												<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Upload a new file to replace the current one, or leave empty to keep the existing file.', 'ajforms' ); ?></p>
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
												<input type="<?php echo esc_attr( $input_type ); ?>" name="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( is_string( $value ) ? $value : '' ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" style="width:100%;">
											<?php endif; ?>

											<?php if ( '' !== $help_text ) : ?>
												<p class="description" style="margin-top:6px;"><?php echo esc_html( $help_text ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php if ( ! empty( $orphan_fields ) ) : ?>
							<div style="margin-top:24px;">
								<h3><?php esc_html_e( 'Removed / Legacy Fields', 'ajforms' ); ?></h3>
								<p class="description"><?php esc_html_e( 'These values are still stored on the entry, but their fields no longer exist on the current form schema.', 'ajforms' ); ?></p>
								<table class="ajforms-lead-meta-table">
									<tbody>
										<?php foreach ( $orphan_fields as $field_key => $field ) : ?>
											<?php
											$label = is_array( $field ) && ! empty( $field['label'] ) ? $field['label'] : $field_key;
											$value = is_array( $field ) && isset( $field['value'] ) ? $field['value'] : '';
											if ( is_array( $value ) ) {
												$value = implode( ', ', $value );
											}
											?>
											<tr>
												<th style="width:220px;"><?php echo esc_html( $label ); ?></th>
												<td><?php echo nl2br( esc_html( (string) $value ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>

						<p style="margin-top:18px;">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Entry', 'ajforms' ); ?></button>
						</p>
					</form>
				<?php else : ?>
					<p><?php esc_html_e( 'The linked form no longer exists, so this entry cannot be edited safely.', 'ajforms' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="ajforms-lead-card">
				<h2><?php esc_html_e( 'Entry Info', 'ajforms' ); ?></h2>
				<table class="ajforms-lead-meta-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Entry', 'ajforms' ); ?></th>
							<td><?php echo esc_html( '#' . $lead_id ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Form name', 'ajforms' ); ?></th>
							<td><?php echo esc_html( $lead->form_title ? $lead->form_title : __( '(Form deleted)', 'ajforms' ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'IP', 'ajforms' ); ?></th>
							<td><?php echo esc_html( $lead->ip_address ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'URL', 'ajforms' ); ?></th>
							<td>
								<?php if ( ! empty( $lead->source_url ) ) : ?>
									<a href="<?php echo esc_url( $lead->source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lead->source_url ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Browser / Device', 'ajforms' ); ?></th>
							<td><?php echo esc_html( $lead->user_agent ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'ajforms' ); ?></th>
							<td><span class="ajforms-status-badge <?php echo esc_attr( $lead->status ); ?>"><?php echo esc_html( isset( $status_labels[ $lead->status ] ) ? $status_labels[ $lead->status ] : ucfirst( $lead->status ) ); ?></span></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Submitted on', 'ajforms' ); ?></th>
							<td>
								<?php
								echo esc_html(
									wp_date(
										get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										strtotime( $lead->created_at )
									)
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="ajforms-entry-secondary">
			<div class="ajforms-lead-card">
				<h2><?php esc_html_e( 'Internal Notes', 'ajforms' ); ?></h2>
				<p class="ajforms-entry-note"><?php esc_html_e( 'Use notes for follow-ups, handoff context, or anything you do not want mixed into the customer-facing form data.', 'ajforms' ); ?></p>

				<form method="post" class="ajforms-note-form" style="margin-bottom:16px;">
					<?php wp_nonce_field( 'ajf_add_lead_note_' . $lead_id ); ?>
					<input type="hidden" name="ajf_add_note_lead_id" value="<?php echo esc_attr( $lead_id ); ?>" />
					<textarea name="ajf_lead_note" rows="4" placeholder="<?php esc_attr_e( 'Add an internal note...', 'ajforms' ); ?>"></textarea>
					<p style="margin-top:10px;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Note', 'ajforms' ); ?></button>
					</p>
				</form>

				<?php if ( empty( $notes ) ) : ?>
					<p class="ajforms-entry-note"><?php esc_html_e( 'No notes yet.', 'ajforms' ); ?></p>
				<?php else : ?>
					<div class="ajforms-note-list">
						<?php foreach ( $notes as $note ) : ?>
							<div class="ajforms-note-item">
								<strong><?php echo nl2br( esc_html( $note->note ) ); ?></strong>
								<span>
									<?php
									echo esc_html(
										wp_date(
											get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
											strtotime( $note->created_at )
										)
									);
									?>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
