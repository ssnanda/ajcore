<?php
/**
 * View: Forms Listing Screen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$forms_list_table = new AJForms_Forms_List_Table();
$forms_list_table->process_bulk_action();
$forms_list_table->prepare_items();

$add_new_url = add_query_arg(
	array(
		'page'   => 'ajforms',
		'action' => 'add',
	),
	admin_url( 'admin.php' )
);

$stats = array(
	'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ajforms_forms WHERE status IN ('published','draft')" ),
	'published'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ajforms_forms WHERE status = 'published'" ),
	'draft'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ajforms_forms WHERE status = 'draft'" ),
	'deleted'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ajforms_forms WHERE status = 'deleted'" ),
);
?>

<div class="wrap">
	<style>
		.ajforms-admin-shell {
			margin-top: 18px;
		}

		.ajforms-admin-hero {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			gap: 20px;
			padding: 28px 30px;
			background: linear-gradient(135deg, #fff 0%, #f7fafc 48%, #eef7ff 100%);
			border: 1px solid #dde7f2;
			border-radius: 26px;
			box-shadow: 0 22px 48px rgba(15, 23, 42, 0.06);
		}

		.ajforms-admin-hero h1 {
			margin: 0 0 10px;
			font-size: 32px;
			line-height: 1.1;
		}

		.ajforms-admin-hero p {
			margin: 0;
			max-width: 700px;
			color: #5f6b7a;
			font-size: 15px;
			line-height: 1.7;
		}

		.ajforms-admin-actions {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
			justify-content: flex-end;
		}

		.ajforms-stats-grid {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			gap: 14px;
			margin: 18px 0 22px;
		}

		.ajforms-stat-card {
			padding: 18px 20px;
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 20px;
			box-shadow: 0 10px 26px rgba(15, 23, 42, 0.04);
		}

		.ajforms-stat-card strong {
			display: block;
			font-size: 28px;
			line-height: 1;
			color: #0f172a;
			margin-bottom: 8px;
		}

		.ajforms-stat-card span {
			color: #64748b;
			font-weight: 600;
		}

		.ajforms-list-shell {
			margin-top: 8px;
			padding: 20px;
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 24px;
			box-shadow: 0 18px 42px rgba(15, 23, 42, 0.05);
		}

		#forms-filter .wp-list-table {
			table-layout: fixed;
			border: 0;
		}

		#forms-filter .column-cb {
			width: 36px;
		}

		#forms-filter .column-title {
			width: 19%;
		}

		#forms-filter .column-shortcode {
			width: 16%;
		}

		#forms-filter .column-entries {
			width: 8%;
			white-space: nowrap;
		}

		#forms-filter .column-date {
			width: 14%;
		}

		#forms-filter .column-status {
			width: 11%;
		}

		#forms-filter .column-actions {
			width: 28%;
		}

		#forms-filter .column-actions .ajforms-inline-actions {
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
			justify-content: flex-end;
		}

		#forms-filter .column-actions .button {
			margin: 0;
		}

		#forms-filter .column-shortcode code {
			display: inline-block;
			max-width: 100%;
			overflow-wrap: anywhere;
			white-space: normal;
		}

		#forms-filter .wp-list-table thead th,
		#forms-filter .wp-list-table tfoot th {
			padding-top: 14px;
			padding-bottom: 14px;
			background: #f8fafc;
		}

		#forms-filter .wp-list-table tbody td {
			padding-top: 16px;
			padding-bottom: 16px;
			vertical-align: middle;
		}

		#forms-filter .wp-list-table tbody tr {
			transition: background 0.16s ease;
		}

		#forms-filter .wp-list-table tbody tr:hover {
			background: #fbfdff;
		}

		.ajforms-form-title-cell {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.ajforms-form-title-cell strong {
			font-size: 15px;
			color: #0f172a;
		}

		.ajforms-form-title-cell span {
			font-size: 12px;
			color: #64748b;
		}

		.ajforms-shortcode-chip {
			display: inline-flex;
			padding: 8px 10px;
			border-radius: 10px;
			background: #f8fafc;
			border: 1px solid #e2e8f0;
			font-size: 12px;
		}

		.ajforms-status-badge {
			display: inline-flex;
			align-items: center;
			padding: 6px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
		}

		.ajforms-status-badge.is-published {
			background: #ecfdf3;
			color: #166534;
		}

		.ajforms-status-badge.is-draft {
			background: #f1f5f9;
			color: #475569;
		}

		.ajforms-status-badge.is-deleted {
			background: #fef2f2;
			color: #b91c1c;
		}

		.ajforms-form-row.is-deleted {
			opacity: 0.76;
		}

		.ajforms-toolbar-note {
			margin: 14px 0 0;
			color: #64748b;
			font-size: 13px;
		}

		@media (max-width: 1280px) {
			#forms-filter .column-actions {
				width: 36%;
			}

			#forms-filter .column-title {
				width: 16%;
			}
		}

		@media (max-width: 1100px) {
			.ajforms-admin-hero {
				flex-direction: column;
			}

			.ajforms-stats-grid {
				grid-template-columns: repeat(2, minmax(0, 1fr));
			}
		}
	</style>

	<div class="ajforms-admin-shell">
		<div class="ajforms-admin-hero">
			<div>
				<h1><?php esc_html_e( 'Forms', 'ajforms' ); ?></h1>
				<p><?php esc_html_e( 'Build, publish, preview, duplicate, export, and keep your form library organized from one place.', 'ajforms' ); ?></p>
			</div>
			<div class="ajforms-admin-actions">
				<a href="<?php echo esc_url( $add_new_url ); ?>" class="button button-primary"><?php esc_html_e( 'Add New Form', 'ajforms' ); ?></a>
				<a href="#" id="wpf-import-form-btn" class="button"><?php esc_html_e( 'Import Form', 'ajforms' ); ?></a>
				<a href="#" id="wpf-export-form-btn" class="button"><?php esc_html_e( 'Export Form', 'ajforms' ); ?></a>
			</div>
		</div>
		<div class="ajforms-stats-grid">
			<div class="ajforms-stat-card"><strong><?php echo esc_html( $stats['total'] ); ?></strong><span><?php esc_html_e( 'Active Forms', 'ajforms' ); ?></span></div>
			<div class="ajforms-stat-card"><strong><?php echo esc_html( $stats['published'] ); ?></strong><span><?php esc_html_e( 'Published', 'ajforms' ); ?></span></div>
			<div class="ajforms-stat-card"><strong><?php echo esc_html( $stats['draft'] ); ?></strong><span><?php esc_html_e( 'Drafts', 'ajforms' ); ?></span></div>
			<div class="ajforms-stat-card"><strong><?php echo esc_html( $stats['deleted'] ); ?></strong><span><?php esc_html_e( 'Deleted', 'ajforms' ); ?></span></div>
		</div>
		<input type="file" id="wpf-import-file" accept=".json" style="display:none;" />
	</div>

	<?php if ( isset( $_GET['trashed'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form moved to deleted.', 'ajforms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['restored'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form restored.', 'ajforms' ); ?></p></div>
	<?php endif; ?>

	<div class="ajforms-list-shell">
		<form id="forms-filter" method="get">
			<input type="hidden" name="page" value="ajforms" />
			<?php
			$forms_list_table->search_box( __( 'Search Forms', 'ajforms' ), 'search_id' );
			$forms_list_table->display();
			?>
		</form>
		<p class="ajforms-toolbar-note"><?php esc_html_e( 'Tip: click any active row to jump straight into the builder.', 'ajforms' ); ?></p>
	</div>
</div>

<script>
(function() {
	const importBtn = document.getElementById('wpf-import-form-btn');
	const exportBtn = document.getElementById('wpf-export-form-btn');
	const fileInput = document.getElementById('wpf-import-file');
	const formRows = document.querySelectorAll('.ajforms-form-row');
	const statusFilters = document.querySelectorAll('.ajforms-status-filter');
	const nonce = '<?php echo esc_js( wp_create_nonce( 'ajf_import_form' ) ); ?>';
	const exportNonce = '<?php echo esc_js( wp_create_nonce( 'ajf_export_form' ) ); ?>';
	const exportBaseUrl = '<?php echo esc_js( admin_url( 'admin-post.php?action=ajf_export_form' ) ); ?>';

	function showAlert(message) {
		window.alert(message);
	}

	function importFormFromJson(json) {
		const payload = {
			action: 'ajf_import_form',
			nonce: nonce,
			data: JSON.stringify(json)
		};

		const formData = new FormData();
		Object.keys(payload).forEach((key) => formData.append(key, payload[key]));

		fetch(ajaxurl, {
			method: 'POST',
			body: formData
		})
			.then((res) => res.json())
			.then((res) => {
				if (!res.success) {
					showAlert(res.data || '<?php echo esc_js( __( 'Import failed.', 'ajforms' ) ); ?>');
					return;
				}

				if (res.data && res.data.edit_url) {
					window.location.href = res.data.edit_url;
					return;
				}

				showAlert('<?php echo esc_js( __( 'Form imported successfully.', 'ajforms' ) ); ?>');
			})
			.catch(() => {
				showAlert('<?php echo esc_js( __( 'Import failed.', 'ajforms' ) ); ?>');
			});
	}

	if (importBtn && fileInput) {
		importBtn.addEventListener('click', function(e) {
			e.preventDefault();
			fileInput.click();
		});

		fileInput.addEventListener('change', function() {
			const file = this.files[0];
			if (!file) {
				return;
			}

			const reader = new FileReader();
			reader.onload = function(event) {
				try {
					const json = JSON.parse(event.target.result);
					importFormFromJson(json);
				} catch (err) {
					showAlert('<?php echo esc_js( __( 'Invalid JSON file.', 'ajforms' ) ); ?>');
				}
			};
			reader.readAsText(file);
		});
	}

	if (exportBtn) {
		exportBtn.addEventListener('click', function(e) {
			e.preventDefault();

			const checked = Array.from(document.querySelectorAll('input[name="form_id[]"]:checked'));
			if (checked.length !== 1) {
				showAlert('<?php echo esc_js( __( 'Select exactly one form to export.', 'ajforms' ) ); ?>');
				return;
			}

			const formId = checked[0].value;
			window.location.href = exportBaseUrl + '&form_id=' + encodeURIComponent(formId) + '&_wpnonce=' + encodeURIComponent(exportNonce);
		});
	}

	if (statusFilters.length) {
		statusFilters.forEach(function(filter) {
			filter.addEventListener('change', function() {
				const url = new URL(window.location.href);
				url.searchParams.set('page', 'ajforms');

				if (this.value) {
					url.searchParams.set('form_status', this.value);
				} else {
					url.searchParams.delete('form_status');
				}

				url.searchParams.delete('paged');
				window.location.href = url.toString();
			});
		});
	}

	if (formRows.length) {
		formRows.forEach(function(row) {
			if (row.classList.contains('is-deleted')) {
				return;
			}

			row.style.cursor = 'pointer';

			row.addEventListener('click', function(e) {
				if (
					e.target.closest('a, button, input, select, textarea, label') ||
					e.target.tagName === 'CODE'
				) {
					return;
				}

				const editUrl = row.getAttribute('data-edit-url');
				if (editUrl) {
					window.location.href = editUrl;
				}
			});
		});
	}

})();
</script>
