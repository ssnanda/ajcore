<?php
/**
 * View: Forms Listing Screen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Bulk actions are processed on admin_init (AJForms_Admin::handle_forms_bulk_actions) so their
// redirects fire before any output; by the time this view renders there is nothing left to do.
$forms_list_table = new AJForms_Forms_List_Table();
$forms_list_table->prepare_items();

$add_new_url = add_query_arg(
	array(
		'page'   => 'ajforms',
		'action' => 'add',
	),
	admin_url( 'admin.php' )
);

$stats = array(
	'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aj_forms_forms WHERE status IN ('published','draft')" ),
	'published'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aj_forms_forms WHERE status = 'published'" ),
	'draft'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aj_forms_forms WHERE status = 'draft'" ),
	'deleted'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aj_forms_forms WHERE status = 'deleted'" ),
);
?>

<div class="wrap">
	<style>
		.ajforms-admin-shell {
			margin-top: 18px;
		}

		.ajforms-admin-hero {
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 16px 24px;
			padding: 16px 22px;
			background: linear-gradient(135deg, #fff 0%, #f7fafc 48%, #eef7ff 100%);
			border: 1px solid #dde7f2;
			border-radius: 20px;
			box-shadow: 0 14px 34px rgba(15, 23, 42, 0.05);
		}

		.ajforms-hero-lead {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 10px 18px;
			min-width: 0;
		}

		.ajforms-admin-hero h1 {
			margin: 0;
			padding: 0;
			font-size: 26px;
			line-height: 1.1;
			color: #0f172a;
		}

		/* Counts as compact chips instead of four big cards: they read at a glance and each one
		   links to that status filter. */
		.ajforms-stat-chips {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px;
		}

		.ajforms-stat-chip {
			display: inline-flex;
			align-items: baseline;
			gap: 6px;
			padding: 5px 12px;
			border-radius: 999px;
			border: 1px solid #dbe5f0;
			background: #fff;
			text-decoration: none;
			line-height: 1.2;
			box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
		}

		.ajforms-stat-chip strong {
			font-size: 15px;
			color: #0f172a;
		}

		.ajforms-stat-chip span {
			font-size: 12px;
			font-weight: 600;
			color: #64748b;
		}

		.ajforms-stat-chip:hover {
			border-color: #94b8d8;
			box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
		}

		.ajforms-stat-chip.is-published {
			border-color: #bbf7d0;
			background: #f0fdf4;
		}

		.ajforms-stat-chip.is-published strong {
			color: #166534;
		}

		.ajforms-stat-chip.is-draft {
			border-color: #fde68a;
			background: #fffbeb;
		}

		.ajforms-stat-chip.is-draft strong {
			color: #92400e;
		}

		.ajforms-stat-chip.is-deleted {
			border-color: #fecaca;
			background: #fef2f2;
		}

		.ajforms-stat-chip.is-deleted strong {
			color: #b91c1c;
		}

		.ajforms-admin-actions {
			display: flex;
			gap: 8px;
			flex-wrap: wrap;
			justify-content: flex-end;
		}

		/* WP's grey default buttons all look alike; give each action its own colour so the primary
		   one is obvious and Import/Export are distinguishable at a glance. */
		.ajforms-admin-actions .ajforms-btn.button {
			height: auto;
			margin: 0;
			padding: 7px 16px;
			border: 0;
			border-radius: 999px;
			color: #fff;
			font-weight: 600;
			line-height: 1.5;
			text-shadow: none;
			box-shadow: 0 4px 12px rgba(15, 23, 42, 0.14);
		}

		.ajforms-admin-actions .ajforms-btn.button:hover,
		.ajforms-admin-actions .ajforms-btn.button:focus {
			color: #fff;
			transform: translateY(-1px);
			box-shadow: 0 7px 18px rgba(15, 23, 42, 0.2);
		}

		.ajforms-admin-actions .ajforms-btn-new {
			background: linear-gradient(135deg, #2563eb, #1d4ed8);
		}

		.ajforms-admin-actions .ajforms-btn-import {
			background: linear-gradient(135deg, #059669, #047857);
		}

		.ajforms-admin-actions .ajforms-btn-export {
			background: linear-gradient(135deg, #7c3aed, #6d28d9);
		}

		/* WP injects admin notices directly after the first h1, which lands the Stripe mode notice
		   inside this hero. Render it as a pill that hugs its own text instead of a full-width
		   notice block -- including overriding the 4px accent border WP puts on .notice. */
		.ajforms-admin-shell .notice.ajcore-stripe-mode-notice {
			flex: 0 1 auto;
			order: 3;
			display: inline-flex;
			align-items: center;
			max-width: 100%;
			margin: 0;
			padding: 5px 14px;
			border: 1px solid #e2e8f0;
			border-left: 1px solid #e2e8f0;
			border-radius: 999px;
			background: #fff;
			box-shadow: none;
		}

		.ajforms-admin-shell .notice.ajcore-stripe-mode-notice.notice-warning,
		.ajforms-admin-shell .notice.ajcore-stripe-mode-notice.notice-error {
			border-color: #fbbf24;
			background: #fffbeb;
		}

		.ajforms-admin-shell .ajcore-stripe-mode-notice p {
			margin: 0;
			padding: 0;
			font-size: 12px;
			color: #475569;
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px;
		}

		.ajforms-admin-shell .ajcore-stripe-mode-notice code {
			padding: 2px 8px;
			border-radius: 6px;
			background: #f1f5f9;
			font-size: 12px;
			letter-spacing: 0.04em;
		}

		.ajforms-list-shell {
			margin-top: 8px;
			padding: 14px 18px 6px;
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 24px;
			box-shadow: 0 18px 42px rgba(15, 23, 42, 0.05);
		}

		/* One toolbar row: bulk actions on the left, search + item count on the right. Core's
		   default stacks a floated search box above a near-empty tablenav, which is where most of
		   the dead vertical space on this screen came from. */
		#forms-filter .ajforms-tablenav {
			display: flex;
			align-items: center;
			justify-content: space-between;
			flex-wrap: wrap;
			gap: 10px 16px;
			height: auto;
			margin: 0;
			padding: 4px 0 10px;
		}

		#forms-filter .ajforms-tablenav.bottom {
			padding: 10px 0 4px;
			border-top: 1px solid #eef2f7;
		}

		#forms-filter .ajforms-tablenav-left,
		#forms-filter .ajforms-tablenav-right {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 8px 12px;
			float: none;
		}

		#forms-filter .ajforms-tablenav .bulkactions {
			display: flex;
			align-items: center;
			gap: 8px;
			float: none;
			margin: 0;
			padding: 0;
		}

		/* Bulk actions are buttons, not a select + Apply -- see AJForms_Forms_List_Table::bulk_actions(). */
		#forms-filter .ajforms-bulk-btn.button {
			height: auto;
			margin: 0;
			padding: 5px 14px;
			border-radius: 999px;
			font-weight: 600;
			line-height: 1.5;
		}

		#forms-filter .ajforms-bulk-btn-bulk-edit-settings {
			border-color: #bfdbfe;
			background: #eff6ff;
			color: #1d4ed8;
		}

		#forms-filter .ajforms-bulk-btn-bulk-edit-settings:hover {
			border-color: #60a5fa;
			background: #dbeafe;
			color: #1e40af;
		}

		#forms-filter .ajforms-bulk-btn-bulk-delete {
			border-color: #fecaca;
			background: #fef2f2;
			color: #b91c1c;
		}

		#forms-filter .ajforms-bulk-btn-bulk-delete:hover {
			border-color: #f87171;
			background: #fee2e2;
			color: #991b1b;
		}

		#forms-filter .ajforms-tablenav .search-box {
			display: flex;
			align-items: center;
			gap: 8px;
			float: none;
			margin: 0;
		}

		#forms-filter .ajforms-tablenav .search-box input[type="search"] {
			margin: 0;
		}

		#forms-filter .ajforms-tablenav .tablenav-pages {
			float: none;
			margin: 0;
			height: auto;
		}

		#forms-filter .ajforms-tablenav .displaying-num {
			margin: 0;
			color: #64748b;
		}

		#forms-filter .ajforms-tablenav br.clear {
			display: none;
		}

		/* Core repeats the column headers in a <tfoot>; on a short list it just reads as a stray
		   duplicate row between the last form and the bulk actions. */
		#forms-filter .wp-list-table tfoot {
			display: none;
		}

		#forms-filter .wp-list-table {
			table-layout: fixed;
			border: 0;
			margin: 0;
		}

		#forms-filter .column-cb {
			width: 36px;
		}

		#forms-filter .column-title {
			width: 21%;
		}

		#forms-filter .column-shortcode {
			width: 12%;
		}

		#forms-filter .column-entries {
			width: 6%;
			white-space: nowrap;
		}

		#forms-filter .column-date {
			width: 16%;
			white-space: nowrap;
		}

		#forms-filter .column-status {
			width: 12%;
		}

		/* The status column header holds a filter <select>; without a cap it overflows the column
		   and collides with the Actions header. */
		#forms-filter .column-status .ajforms-status-filter {
			max-width: 100%;
			min-width: 0;
		}

		#forms-filter th.column-status > label {
			max-width: 100%;
		}

		#forms-filter .column-actions {
			width: 33%;
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
			margin: 10px 0 0;
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
				align-items: flex-start;
			}

			.ajforms-admin-actions {
				justify-content: flex-start;
			}

		}
	</style>

	<div class="ajforms-admin-shell">
		<div class="ajforms-admin-hero">
			<div class="ajforms-hero-lead">
				<h1><?php esc_html_e( 'Forms', 'ajforms' ); ?></h1>
				<?php
				// "Active" was just published + drafts, which reads as a duplicate of Published on
				// the common case of a site with no drafts. Show the states that actually exist.
				$stat_chips = array(
					array( 'key' => 'published', 'label' => __( 'Published', 'ajforms' ), 'count' => $stats['published'], 'always' => true ),
					array( 'key' => 'draft', 'label' => _n( 'Draft', 'Drafts', $stats['draft'], 'ajforms' ), 'count' => $stats['draft'], 'always' => false ),
					array( 'key' => 'deleted', 'label' => __( 'Deleted', 'ajforms' ), 'count' => $stats['deleted'], 'always' => false ),
				);
				?>
				<div class="ajforms-stat-chips">
					<?php
					foreach ( $stat_chips as $chip ) :
						if ( ! $chip['always'] && $chip['count'] < 1 ) {
							continue;
						}

						$chip_url = add_query_arg(
							array(
								'page'        => 'ajforms',
								'form_status' => $chip['key'],
							),
							admin_url( 'admin.php' )
						);
						?>
						<a class="ajforms-stat-chip is-<?php echo esc_attr( $chip['key'] ); ?>" href="<?php echo esc_url( $chip_url ); ?>">
							<strong><?php echo esc_html( $chip['count'] ); ?></strong>
							<span><?php echo esc_html( $chip['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="ajforms-admin-actions">
				<a href="<?php echo esc_url( $add_new_url ); ?>" class="button ajforms-btn ajforms-btn-new"><?php esc_html_e( 'Add New', 'ajforms' ); ?></a>
				<a href="#" id="wpf-import-form-btn" class="button ajforms-btn ajforms-btn-import"><?php esc_html_e( 'Import', 'ajforms' ); ?></a>
				<a href="#" id="wpf-export-form-btn" class="button ajforms-btn ajforms-btn-export"><?php esc_html_e( 'Export', 'ajforms' ); ?></a>
			</div>
		</div>
		<input type="file" id="wpf-import-file" accept=".json" style="display:none;" />
	</div>

	<?php if ( isset( $_GET['trashed'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form moved to deleted.', 'ajforms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['restored'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form restored.', 'ajforms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['bulk_updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				$bulk_updated = absint( wp_unslash( $_GET['bulk_updated'] ) );
				printf(
					/* translators: %d: number of forms updated. */
					esc_html( _n( 'Settings updated on %d form.', 'Settings updated on %d forms.', $bulk_updated, 'ajforms' ) ),
					$bulk_updated
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['bulk_no_selection'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Select at least one form before applying a bulk action.', 'ajforms' ); ?></p></div>
	<?php endif; ?>

	<div class="ajforms-list-shell">
		<form id="forms-filter" method="get">
			<input type="hidden" name="page" value="ajforms" />
			<?php
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
