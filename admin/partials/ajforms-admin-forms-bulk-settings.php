<?php
/**
 * View: Bulk Edit Form Settings
 *
 * Reached from Forms → select forms → bulk action "Edit Settings". Every field is gated behind
 * its own "Apply" checkbox: only checked rows are written to the selected forms, everything else
 * on each form is left exactly as it was.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Insufficient permissions.', 'ajforms' ) );
}

global $wpdb;

$admin    = new AJForms_Admin();
$groups   = $admin->get_bulk_editable_form_settings();
$raw_ids  = isset( $_GET['form_ids'] ) ? sanitize_text_field( wp_unslash( $_GET['form_ids'] ) ) : '';
$form_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) ) );
$forms    = array();

if ( ! empty( $form_ids ) ) {
	$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
	$forms        = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, title, status, form_schema FROM {$wpdb->prefix}aj_forms_forms WHERE id IN ({$placeholders}) ORDER BY title ASC",
			$form_ids
		),
		ARRAY_A
	);
}

$forms_url = add_query_arg( array( 'page' => 'ajforms' ), admin_url( 'admin.php' ) );

if ( empty( $forms ) ) {
	echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Edit Settings', 'ajforms' ) . '</h1>';
	echo '<div class="notice notice-error"><p>' . esc_html__( 'No forms were selected.', 'ajforms' ) . '</p></div>';
	echo '<p><a class="button" href="' . esc_url( $forms_url ) . '">' . esc_html__( 'Back to Forms', 'ajforms' ) . '</a></p></div>';
	return;
}

// Prefill from the first selected form so "make these forms match this one" is a two-click job.
$reference        = $forms[0];
$reference_schema = json_decode( (string) $reference['form_schema'], true );
$reference_values = ( is_array( $reference_schema ) && isset( $reference_schema['settings'] ) && is_array( $reference_schema['settings'] ) )
	? $reference_schema['settings']
	: array();
?>

<div class="wrap">
	<style>
		.ajforms-bulk-shell {
			max-width: 1080px;
			margin-top: 18px;
		}

		.ajforms-bulk-hero {
			padding: 24px 28px;
			background: linear-gradient(135deg, #fff 0%, #f7fafc 48%, #eef7ff 100%);
			border: 1px solid #dde7f2;
			border-radius: 22px;
		}

		.ajforms-bulk-hero h1 {
			margin: 0 0 8px;
			font-size: 28px;
		}

		.ajforms-bulk-hero p {
			margin: 0;
			color: #5f6b7a;
			font-size: 14px;
			line-height: 1.7;
		}

		.ajforms-bulk-targets {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin-top: 14px;
		}

		.ajforms-bulk-chip {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 5px 12px;
			background: #fff;
			border: 1px solid #dbe5f0;
			border-radius: 999px;
			font-size: 13px;
			font-weight: 600;
			color: #1f2937;
		}

		.ajforms-bulk-chip span {
			font-weight: 500;
			color: #64748b;
		}

		.ajforms-bulk-group {
			margin-top: 18px;
			padding: 20px 24px;
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 20px;
			box-shadow: 0 12px 30px rgba(15, 23, 42, 0.04);
		}

		.ajforms-bulk-group > h2 {
			margin: 0 0 4px;
			font-size: 17px;
		}

		.ajforms-bulk-group > p.description {
			margin: 0 0 14px;
		}

		.ajforms-bulk-row {
			display: grid;
			grid-template-columns: 96px minmax(180px, 240px) minmax(0, 1fr);
			gap: 14px;
			align-items: start;
			padding: 12px 0;
			border-top: 1px solid #f1f5f9;
		}

		.ajforms-bulk-row.is-active {
			background: #f8fbff;
		}

		.ajforms-bulk-row label.ajforms-bulk-apply {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-weight: 600;
			color: #64748b;
			font-size: 12px;
			text-transform: uppercase;
			letter-spacing: 0.04em;
		}

		.ajforms-bulk-row .ajforms-bulk-label {
			font-weight: 600;
			color: #1f2937;
			padding-top: 2px;
		}

		.ajforms-bulk-row input[type="text"],
		.ajforms-bulk-row input[type="email"],
		.ajforms-bulk-row input[type="url"],
		.ajforms-bulk-row input[type="number"],
		.ajforms-bulk-row select,
		.ajforms-bulk-row textarea {
			width: 100%;
			max-width: 560px;
		}

		.ajforms-bulk-row textarea {
			min-height: 90px;
			font-family: Menlo, Consolas, monospace;
			font-size: 12px;
		}

		.ajforms-bulk-row .ajforms-bulk-help {
			display: block;
			margin-top: 4px;
			color: #64748b;
			font-size: 12px;
		}

		.ajforms-bulk-row :disabled {
			opacity: 0.55;
		}

		.ajforms-bulk-actions {
			display: flex;
			gap: 10px;
			align-items: center;
			margin: 20px 0 40px;
		}

		@media (max-width: 860px) {
			.ajforms-bulk-row {
				grid-template-columns: 1fr;
			}
		}
	</style>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ajforms-bulk-shell" id="ajforms-bulk-settings-form">
		<input type="hidden" name="action" value="ajf_bulk_form_settings" />
		<?php wp_nonce_field( 'ajf_bulk_form_settings' ); ?>
		<?php foreach ( $forms as $form ) : ?>
			<input type="hidden" name="form_ids[]" value="<?php echo absint( $form['id'] ); ?>" />
		<?php endforeach; ?>

		<div class="ajforms-bulk-hero">
			<h1><?php esc_html_e( 'Bulk Edit Settings', 'ajforms' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %d: number of selected forms. */
					esc_html( _n( 'Changes apply to the %d form below.', 'Changes apply to the %d forms below.', count( $forms ), 'ajforms' ) ),
					count( $forms )
				);
				?>
				<?php esc_html_e( 'Only the rows you tick under "Apply" are written — every other setting, all fields, conditional confirmation rules, and Stripe payment config stay untouched on each form.', 'ajforms' ); ?>
				<?php
				printf(
					/* translators: %s: form title used to prefill the screen. */
					esc_html__( 'Values below are prefilled from %s.', 'ajforms' ),
					'“' . esc_html( $reference['title'] ) . '”'
				);
				?>
			</p>
			<div class="ajforms-bulk-targets">
				<?php foreach ( $forms as $form ) : ?>
					<span class="ajforms-bulk-chip">
						<?php echo esc_html( $form['title'] ); ?>
						<span><?php echo esc_html( ucfirst( sanitize_text_field( $form['status'] ) ) ); ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
		foreach ( $groups as $group_key => $group ) :
			?>
			<div class="ajforms-bulk-group">
				<h2><?php echo esc_html( $group['label'] ); ?></h2>
				<?php if ( ! empty( $group['description'] ) ) : ?>
					<p class="description"><?php echo esc_html( $group['description'] ); ?></p>
				<?php endif; ?>

				<?php
				foreach ( $group['fields'] as $key => $field ) :
					$type    = isset( $field['type'] ) ? $field['type'] : 'text';
					$current = isset( $reference_values[ $key ] ) ? $reference_values[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
					$input_id = 'ajforms-bulk-' . sanitize_key( $key );
					?>
					<div class="ajforms-bulk-row">
						<label class="ajforms-bulk-apply">
							<input type="checkbox" class="ajforms-bulk-apply-toggle" name="apply[<?php echo esc_attr( $key ); ?>]" value="1" data-target="<?php echo esc_attr( $input_id ); ?>" />
							<?php esc_html_e( 'Apply', 'ajforms' ); ?>
						</label>
						<div class="ajforms-bulk-label">
							<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
						</div>
						<div>
							<?php if ( 'toggle' === $type ) : ?>
								<select id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" disabled>
									<option value="1" <?php selected( ! empty( $current ) ); ?>><?php esc_html_e( 'Enabled', 'ajforms' ); ?></option>
									<option value="" <?php selected( empty( $current ) ); ?>><?php esc_html_e( 'Disabled', 'ajforms' ); ?></option>
								</select>
							<?php elseif ( 'select' === $type ) : ?>
								<select id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" disabled>
									<?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
										<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $current, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'textarea' === $type || 'html' === $type ) : ?>
								<textarea id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" rows="5" disabled><?php echo esc_textarea( (string) $current ); ?></textarea>
							<?php elseif ( 'color' === $type ) : ?>
								<input type="color" id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $current ? $current : '#ffffff' ); ?>" disabled />
							<?php elseif ( 'number' === $type ) : ?>
								<input type="number" id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $current ); ?>" min="<?php echo esc_attr( isset( $field['min'] ) ? $field['min'] : 0 ); ?>" max="<?php echo esc_attr( isset( $field['max'] ) ? $field['max'] : 100 ); ?>" disabled />
							<?php else : ?>
								<input type="<?php echo esc_attr( 'email' === $type ? 'email' : ( 'url' === $type ? 'url' : 'text' ) ); ?>" id="<?php echo esc_attr( $input_id ); ?>" name="settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $current ); ?>" disabled />
							<?php endif; ?>

							<?php if ( ! empty( $field['help'] ) ) : ?>
								<span class="ajforms-bulk-help"><?php echo esc_html( $field['help'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

		<div class="ajforms-bulk-group">
			<h2><?php esc_html_e( 'Status', 'ajforms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Publish or unpublish every selected form at once.', 'ajforms' ); ?></p>
			<div class="ajforms-bulk-row">
				<label class="ajforms-bulk-apply">
					<input type="checkbox" class="ajforms-bulk-apply-toggle" name="apply[form_status]" value="1" data-target="ajforms-bulk-form-status" />
					<?php esc_html_e( 'Apply', 'ajforms' ); ?>
				</label>
				<div class="ajforms-bulk-label">
					<label for="ajforms-bulk-form-status"><?php esc_html_e( 'Form Status', 'ajforms' ); ?></label>
				</div>
				<div>
					<select id="ajforms-bulk-form-status" name="settings[form_status]" disabled>
						<option value="published"><?php esc_html_e( 'Published', 'ajforms' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'ajforms' ); ?></option>
					</select>
				</div>
			</div>
		</div>

		<div class="ajforms-bulk-actions">
			<button type="submit" class="button button-primary" id="ajforms-bulk-submit"><?php esc_html_e( 'Update Selected Forms', 'ajforms' ); ?></button>
			<a class="button" href="<?php echo esc_url( $forms_url ); ?>"><?php esc_html_e( 'Cancel', 'ajforms' ); ?></a>
		</div>
	</form>
</div>

<script>
(function() {
	const form = document.getElementById('ajforms-bulk-settings-form');

	if (!form) {
		return;
	}

	const toggles = Array.from(form.querySelectorAll('.ajforms-bulk-apply-toggle'));

	// Inputs stay disabled until their Apply box is ticked: a disabled control posts nothing, so
	// an untouched row can never overwrite a setting on any of the selected forms.
	function sync(toggle) {
		const target = document.getElementById(toggle.getAttribute('data-target'));
		const row = toggle.closest('.ajforms-bulk-row');

		if (target) {
			target.disabled = !toggle.checked;
		}

		if (row) {
			row.classList.toggle('is-active', toggle.checked);
		}
	}

	toggles.forEach(function(toggle) {
		sync(toggle);
		toggle.addEventListener('change', function() {
			sync(toggle);
		});
	});

	form.addEventListener('submit', function(e) {
		const checked = toggles.filter(function(toggle) {
			return toggle.checked;
		});

		if (!checked.length) {
			e.preventDefault();
			window.alert('<?php echo esc_js( __( 'Tick at least one "Apply" box to choose which settings to change.', 'ajforms' ) ); ?>');
			return;
		}

		const count = form.querySelectorAll('input[name="form_ids[]"]').length;

		if (!window.confirm('<?php echo esc_js( __( 'Apply the ticked settings to', 'ajforms' ) ); ?>' + ' ' + count + ' ' + '<?php echo esc_js( __( 'form(s)? This overwrites those settings on each one.', 'ajforms' ) ); ?>')) {
			e.preventDefault();
		}
	});
})();
</script>
