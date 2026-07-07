<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$forms_table = $wpdb->prefix . 'aj_forms_forms';
$forms       = $wpdb->get_results( "SELECT id, title FROM {$forms_table} ORDER BY title ASC" );

$selected_form   = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
$selected_status = isset( $_GET['lead_status'] ) ? sanitize_text_field( wp_unslash( $_GET['lead_status'] ) ) : '';
$selected_queue  = isset( $_GET['lead_queue'] ) ? sanitize_key( wp_unslash( $_GET['lead_queue'] ) ) : 'inbox';

$leads_list_table = new AJForms_Leads_List_Table();
$leads_list_table->process_bulk_action();
$leads_list_table->prepare_items();

$leads_table_name = $wpdb->prefix . 'aj_forms_leads';
$lead_stats = array(
	'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table_name}" ),
	'new'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'new' ) ),
	'read'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'read' ) ),
	'lost'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'lost' ) ),
	'won'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'won' ) ),
	'duplicate'=> (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'duplicate' ) ),
);
$lead_stats['inbox']    = $lead_stats['new'] + $lead_stats['read'];
$lead_stats['archived'] = $lead_stats['won'] + $lead_stats['duplicate'];
$leads_base_url = admin_url( 'admin.php?page=ajforms-leads' );
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
			background: linear-gradient(135deg, #fff 0%, #f8fafc 48%, #eff6ff 100%);
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

		.ajforms-stats-grid {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
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

		.ajforms-filter-shell,
		.ajforms-list-shell {
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 24px;
			box-shadow: 0 18px 42px rgba(15, 23, 42, 0.05);
		}

		.ajforms-filter-shell {
			padding: 18px 20px;
			margin-bottom: 16px;
		}

		.ajforms-filter-grid {
			display: flex;
			align-items: end;
			gap: 12px;
			flex-wrap: wrap;
		}

		.ajforms-filter-grid select {
			min-width: 220px;
		}

		.ajforms-filter-grid label {
			display: flex;
			flex-direction: column;
			gap: 8px;
			font-weight: 600;
			color: #334155;
		}

		.ajforms-list-shell {
			padding: 20px;
		}

		.ajforms-list-shell .wp-list-table {
			border: 0;
		}

		.ajforms-list-shell .wp-list-table thead th,
		.ajforms-list-shell .wp-list-table tfoot th {
			background: #f8fafc;
			padding-top: 14px;
			padding-bottom: 14px;
		}

		.ajforms-list-shell .wp-list-table tbody td {
			padding-top: 16px;
			padding-bottom: 16px;
			vertical-align: middle;
		}

		.ajforms-list-shell .wp-list-table tbody tr:hover {
			background: #fbfdff;
		}

		.ajforms-entry-id-chip {
			display: inline-flex;
			padding: 6px 10px;
			border-radius: 999px;
			background: #eff6ff;
			color: #1d4ed8;
			font-weight: 700;
			font-size: 12px;
		}

		.ajforms-form-title-cell {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}

		.ajforms-form-title-cell strong {
			font-size: 14px;
			color: #0f172a;
		}

		.ajforms-form-title-cell span,
		.ajforms-summary-line {
			color: #64748b;
		}

		.ajforms-status-badge {
			display: inline-flex;
			align-items: center;
			padding: 6px 10px;
			border-radius: 999px;
			font-size: 12px;
			font-weight: 700;
		}

		.ajforms-status-badge.new {
			background: #fef3c7;
			color: #92400e;
		}

		.ajforms-status-badge.read {
			background: #eff6ff;
			color: #1d4ed8;
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

		.ajforms-queue-tabs {
			display: flex;
			border: 1px solid #cbd5e1;
			border-radius: 999px;
			overflow: hidden;
			background: #fff;
		}

		.ajforms-queue-tabs a {
			padding: 8px 16px;
			font-weight: 600;
			font-size: 13px;
			color: #334155;
			text-decoration: none;
		}

		.ajforms-queue-tabs a.is-active {
			background: #2563eb;
			color: #fff;
		}

		.ajforms-inline-note {
			margin-top: 14px;
			color: #64748b;
			font-size: 13px;
		}

		@media (max-width: 1000px) {
			.ajforms-admin-hero {
				flex-direction: column;
			}

			.ajforms-stats-grid {
				grid-template-columns: 1fr;
			}
		}
	</style>

	<div class="ajforms-admin-shell">
		<div class="ajforms-admin-hero">
			<div>
				<h1><?php esc_html_e( 'Leads', 'ajforms' ); ?></h1>
				<p><?php esc_html_e( 'Manage leads from form submissions and manual entries. Review contacts, source, status, notes, and follow-up activity from one place.', 'ajforms' ); ?></p>
			</div>
			<div style="flex-shrink:0;display:flex;gap:8px;align-items:center;">
				<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Scan the Inbox for leads with matching email/phone and archive the newer ones as duplicates?', 'ajforms' ) ); ?>');">
					<?php wp_nonce_field( 'ajf_fix_lead_duplicates', 'ajf_fix_lead_duplicates_nonce' ); ?>
					<button type="submit" class="button" style="font-size:14px;padding:8px 18px;height:auto;">
						<?php esc_html_e( 'Fix Duplicates', 'ajforms' ); ?>
					</button>
				</form>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ajforms-leads&view=new' ) ); ?>" class="button button-primary" style="font-size:14px;padding:8px 18px;height:auto;">
					+ <?php esc_html_e( 'New Lead', 'ajforms' ); ?>
				</a>
			</div>
		</div>

		<?php if ( isset( $_GET['duplicates_fixed'] ) ) : ?>
			<div class="notice notice-success is-dismissible" style="margin:16px 0 0;">
				<p>
					<?php
					$fixed_count = absint( wp_unslash( $_GET['duplicates_fixed'] ) );
					echo esc_html( $fixed_count > 0
						? sprintf( _n( 'Archived %d duplicate lead.', 'Archived %d duplicate leads.', $fixed_count, 'ajforms' ), $fixed_count )
						: __( 'No duplicates found in the Inbox.', 'ajforms' ) );
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="ajforms-stats-grid">
			<a href="<?php echo esc_url( $leads_base_url ); ?>" class="ajforms-stat-card" style="text-decoration:none;"><strong><?php echo esc_html( $lead_stats['total'] ); ?></strong><span><?php esc_html_e( 'Total Leads', 'ajforms' ); ?></span></a>
			<a href="<?php echo esc_url( add_query_arg( 'lead_queue', 'inbox', $leads_base_url ) ); ?>" class="ajforms-stat-card" style="text-decoration:none;"><strong><?php echo esc_html( $lead_stats['inbox'] ); ?></strong><span><?php esc_html_e( 'Inbox', 'ajforms' ); ?></span></a>
			<a href="<?php echo esc_url( add_query_arg( 'lead_queue', 'lost', $leads_base_url ) ); ?>" class="ajforms-stat-card" style="text-decoration:none;"><strong><?php echo esc_html( $lead_stats['lost'] ); ?></strong><span><?php esc_html_e( 'Lost', 'ajforms' ); ?></span></a>
			<a href="<?php echo esc_url( add_query_arg( 'lead_queue', 'archived', $leads_base_url ) ); ?>" class="ajforms-stat-card" style="text-decoration:none;"><strong><?php echo esc_html( $lead_stats['archived'] ); ?></strong><span><?php esc_html_e( 'Archived (Won + Duplicate)', 'ajforms' ); ?></span></a>
		</div>
	</div>

	<div class="ajforms-filter-shell">
		<div class="ajforms-filter-grid" style="margin-bottom:14px;">
			<div class="ajforms-queue-tabs">
				<a href="<?php echo esc_url( add_query_arg( 'lead_queue', 'inbox', $leads_base_url ) ); ?>" class="<?php echo 'inbox' === $selected_queue ? 'is-active' : ''; ?>"><?php esc_html_e( 'Inbox', 'ajforms' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'lead_queue', 'lost', $leads_base_url ) ); ?>" class="<?php echo 'lost' === $selected_queue ? 'is-active' : ''; ?>"><?php esc_html_e( 'Lost', 'ajforms' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'lead_queue', 'archived', $leads_base_url ) ); ?>" class="<?php echo 'archived' === $selected_queue ? 'is-active' : ''; ?>"><?php esc_html_e( 'Archived', 'ajforms' ); ?></a>
			</div>
		</div>
		<form method="get">
			<input type="hidden" name="page" value="ajforms-leads" />
			<input type="hidden" name="lead_queue" value="<?php echo esc_attr( $selected_queue ); ?>" />

			<div class="ajforms-filter-grid">
				<?php if ( 'inbox' === $selected_queue ) : ?>
					<label>
						<span><?php esc_html_e( 'Status', 'ajforms' ); ?></span>
						<select name="lead_status">
							<option value=""><?php esc_html_e( 'All statuses', 'ajforms' ); ?></option>
							<option value="new" <?php selected( $selected_status, 'new' ); ?>><?php esc_html_e( 'New', 'ajforms' ); ?></option>
							<option value="read" <?php selected( $selected_status, 'read' ); ?>><?php esc_html_e( 'Read', 'ajforms' ); ?></option>
						</select>
					</label>
				<?php endif; ?>

				<label>
					<span><?php esc_html_e( 'Form', 'ajforms' ); ?></span>
					<select name="form_id">
						<option value="0"><?php esc_html_e( 'All Forms', 'ajforms' ); ?></option>
						<?php foreach ( $forms as $form ) : ?>
							<option value="<?php echo esc_attr( $form->id ); ?>" <?php selected( $selected_form, $form->id ); ?>>
								<?php echo esc_html( $form->title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'ajforms' ); ?></button>
			</div>
		</form>
	</div>

	<div class="ajforms-list-shell">
		<form method="post">
			<input type="hidden" name="page" value="ajforms-leads" />
			<?php $leads_list_table->display(); ?>
		</form>
		<p class="ajforms-inline-note"><?php esc_html_e( 'Tip: click any row to open that entry in edit mode.', 'ajforms' ); ?></p>
	</div>
</div>

<script>
(function() {
	const leadRows = document.querySelectorAll('.ajforms-lead-row');

	if (!leadRows.length) {
		return;
	}

	leadRows.forEach(function(row) {
		row.style.cursor = 'pointer';

		row.addEventListener('click', function(e) {
			if (e.target.closest('a, button, input, select, textarea, label')) {
				return;
			}

			const detailUrl = row.getAttribute('data-detail-url');
			if (detailUrl) {
				window.location.href = detailUrl;
			}
		});
	});
})();
</script>
