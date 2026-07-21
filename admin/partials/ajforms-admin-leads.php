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

// Manual re-run of the local→shared leads migration (button in the diagnostics panel below).
// Runs BEFORE the list/stats are computed so this page render already shows the result.
$leads_migration_rerun = false;
if ( isset( $_POST['ajf_migrate_leads_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajf_migrate_leads_nonce'] ) ), 'ajf_migrate_leads' ) && current_user_can( 'manage_options' ) ) {
	update_option( 'ajcore_leads_migrated_to_shared', '', false ); // force a full re-run (idempotent — already-copied leads are skipped)
	require_once AJFORMS_PLUGIN_DIR . 'includes/class-ajforms-activator.php';
	AJForms_Activator::ensure_shared_leads_tables_and_migrate();
	$leads_migration_rerun = true;
}

// One-time LEAD STATUS cursor-cutover backfill (see backfill_lead_status_for_cursor_cutover()
// in AJForms_Admin) — run this BEFORE switching the auto-outreach cron over to status-based
// triggering, so leads already contacted under the old baseline/last-processed cursor don't get
// re-texted/re-emailed. Idempotent — safe to click more than once.
$lead_status_backfill_result = null;
if ( isset( $_POST['ajf_backfill_lead_status_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ajf_backfill_lead_status_nonce'] ) ), 'ajf_backfill_lead_status' ) && current_user_can( 'manage_options' ) ) {
	$lead_status_backfill_admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
	$lead_status_backfill_result = $lead_status_backfill_admin->backfill_lead_status_for_cursor_cutover();
}
$lead_status_backfill_done_at = get_option( 'ajcore_lead_status_cursor_backfill_done', '' );

$leads_list_table = new AJForms_Leads_List_Table();
$leads_list_table->process_bulk_action();
$leads_list_table->prepare_items();

// Leads live on the shared portal DB in multi-site mode (all sites' leads in one inbox).
$leads_db         = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $wpdb;
$leads_table_name = $leads_db->prefix . 'aj_forms_leads';
$lead_stats = array(
	'total'    => (int) $leads_db->get_var( "SELECT COUNT(*) FROM {$leads_table_name}" ),
	'new'      => (int) $leads_db->get_var( $leads_db->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'new' ) ),
	'read'     => (int) $leads_db->get_var( $leads_db->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'read' ) ),
	'lost'     => (int) $leads_db->get_var( $leads_db->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'lost' ) ),
	'won'      => (int) $leads_db->get_var( $leads_db->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'won' ) ),
	'duplicate'=> (int) $leads_db->get_var( $leads_db->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE status = %s", 'duplicate' ) ),
);
$lead_stats['inbox']    = $lead_stats['new'] + $lead_stats['read'];
$lead_stats['archived'] = $lead_stats['won'] + $lead_stats['duplicate'];
$leads_base_url = admin_url( 'admin.php?page=ajforms-leads' );

if ( $leads_migration_rerun ) {
	echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Leads migration re-run finished — results below.', 'ajforms' ) . '</p></div>';
}

// Shared-DB migration diagnostics: if this site's old local leads could not be copied to the
// shared portal DB, say so loudly instead of silently showing an empty list. The migration
// retries automatically on every plugin upgrade check until it fully succeeds.
$leads_migration_errors = get_option( 'ajcore_leads_shared_migration_errors', array() );
$leads_migration_errors = is_array( $leads_migration_errors ) ? $leads_migration_errors : array();
$leads_migration_flag   = (string) get_option( 'ajcore_leads_migrated_to_shared', '' );
$leads_migration_done   = ( '2' === $leads_migration_flag );
$local_leads_table      = $wpdb->prefix . 'aj_forms_leads';
$local_leads_exists     = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $local_leads_table ) ) === $local_leads_table );
$local_leads_count      = $local_leads_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$local_leads_table}" ) : 0;
$leads_local_pending    = ( $leads_db !== $wpdb && ! $leads_migration_done ) ? $local_leads_count : 0;

// Diagnostics values for the panel.
$diag_site_uuid    = (string) get_option( 'ajcore_site_uuid', '' );
$diag_shared_mode  = ( $leads_db !== $wpdb );
$diag_shared_count = null;
$diag_site_count   = null;
if ( $diag_shared_mode ) {
	$shared_exists = ( $leads_db->get_var( $leads_db->prepare( 'SHOW TABLES LIKE %s', $leads_table_name ) ) === $leads_table_name );
	if ( $shared_exists ) {
		$diag_shared_count = (int) $leads_db->get_var( "SELECT COUNT(*) FROM {$leads_table_name}" );
		$diag_site_count   = (int) $leads_db->get_var( $leads_db->prepare( "SELECT COUNT(*) FROM {$leads_table_name} WHERE site_uuid = %s", $diag_site_uuid ) );
	}
}
// Any lead-ish tables lurking under other names/prefixes on the local DB (legacy names,
// changed prefixes) — catches "the leads are actually over there" situations.
$diag_candidate_tables = array();
foreach ( (array) $wpdb->get_col( "SHOW TABLES LIKE '%forms_leads%'" ) as $cand ) {
	$diag_candidate_tables[ $cand ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$cand}`" );
}
?>
<?php if ( current_user_can( 'manage_options' ) ) : ?>
	<details style="margin:12px 0;padding:10px 14px;background:#fff;border:1px solid #dcdcde;border-radius:8px;" <?php echo ( 0 === $lead_stats['total'] || ! $leads_migration_done || ! empty( $leads_migration_errors ) || $leads_migration_rerun ) ? 'open' : ''; ?>>
		<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'Leads storage diagnostics', 'ajforms' ); ?></summary>
		<table class="widefat striped" style="max-width:760px;margin-top:10px;">
			<tbody>
				<tr><td><?php esc_html_e( 'Plugin version', 'ajforms' ); ?></td><td><code><?php echo esc_html( defined( 'AJCORE_VERSION' ) ? AJCORE_VERSION : '?' ); ?></code> (schema <code><?php echo esc_html( (string) get_option( 'ajforms_portal_schema_version', '?' ) ); ?></code>)</td></tr>
				<tr><td><?php esc_html_e( 'Site UUID', 'ajforms' ); ?></td><td><code><?php echo esc_html( $diag_site_uuid ); ?></code></td></tr>
				<tr><td><?php esc_html_e( 'Shared portal DB mode', 'ajforms' ); ?></td><td><?php echo $diag_shared_mode ? '<span style="color:#166534;">ACTIVE</span> — leads read from shared DB' : '<span style="color:#92400e;">OFF</span> — leads read from local DB'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Local leads table', 'ajforms' ); ?></td><td><code><?php echo esc_html( $local_leads_table ); ?></code> — <?php echo $local_leads_exists ? esc_html( sprintf( __( '%d rows', 'ajforms' ), $local_leads_count ) ) : esc_html__( 'missing', 'ajforms' ); ?></td></tr>
				<?php if ( $diag_shared_mode ) : ?>
					<tr><td><?php esc_html_e( 'Shared leads table', 'ajforms' ); ?></td><td><code><?php echo esc_html( $leads_table_name ); ?></code> — <?php echo null === $diag_shared_count ? '<span style="color:#b91c1c;">MISSING</span>' : esc_html( sprintf( __( '%1$d rows total, %2$d from this site', 'ajforms' ), $diag_shared_count, $diag_site_count ) ); ?></td></tr>
				<?php endif; ?>
				<tr><td><?php esc_html_e( 'Migration flag', 'ajforms' ); ?></td><td><code><?php echo esc_html( '' !== $leads_migration_flag ? $leads_migration_flag : '(unset)' ); ?></code> <?php echo $leads_migration_done ? esc_html__( '(complete)', 'ajforms' ) : esc_html__( '(will retry on next upgrade check)', 'ajforms' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Last migration errors', 'ajforms' ); ?></td><td><?php echo empty( $leads_migration_errors ) ? esc_html__( 'none', 'ajforms' ) : '<code>' . esc_html( implode( ' | ', array_slice( $leads_migration_errors, 0, 3 ) ) ) . '</code>'; ?></td></tr>
				<tr><td><?php esc_html_e( 'Lead-like tables on local DB', 'ajforms' ); ?></td><td><?php
					$cand_bits = array();
					foreach ( $diag_candidate_tables as $cand_table => $cand_count ) {
						$cand_bits[] = '<code>' . esc_html( $cand_table ) . '</code> (' . (int) $cand_count . ')';
					}
					echo $cand_bits ? wp_kses_post( implode( ', ', $cand_bits ) ) : esc_html__( 'none found', 'ajforms' );
				?></td></tr>
			</tbody>
		</table>
		<?php if ( $diag_shared_mode ) : ?>
			<form method="post" style="margin-top:10px;">
				<?php wp_nonce_field( 'ajf_migrate_leads', 'ajf_migrate_leads_nonce' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Re-run local → shared leads migration now', 'ajforms' ); ?></button>
				<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Safe to click repeatedly — already-copied leads are skipped, never duplicated.', 'ajforms' ); ?></span>
			</form>
		<?php endif; ?>
		<form method="post" style="margin-top:10px;">
			<?php wp_nonce_field( 'ajf_backfill_lead_status', 'ajf_backfill_lead_status_nonce' ); ?>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Run LEAD STATUS cursor-cutover backfill now', 'ajforms' ); ?></button>
			<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Run this once before switching Lead Auto Outreach to status-based triggering, so already-contacted leads are marked Welcomed instead of re-texted. Safe to click repeatedly.', 'ajforms' ); ?></span>
			<?php if ( '' !== (string) $lead_status_backfill_done_at ) : ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Last run: %s', 'ajforms' ), $lead_status_backfill_done_at ) ); ?></p>
			<?php endif; ?>
			<?php if ( is_array( $lead_status_backfill_result ) ) : ?>
				<div class="notice notice-success inline" style="margin-top:8px;"><p>
					<?php echo esc_html( sprintf(
						/* translators: 1: number of leads updated, 2: legacy cursor cutoff id, 3: number of per-site cursors found */
						__( 'Backfill complete: %1$d lead(s) marked Welcomed. Legacy cutoff: #%2$d. Per-site cursors found: %3$d.', 'ajforms' ),
						(int) $lead_status_backfill_result['updated'],
						(int) $lead_status_backfill_result['legacy_cutoff'],
						count( (array) $lead_status_backfill_result['per_site_cutoffs'] )
					) ); ?>
				</p></div>
			<?php endif; ?>
		</form>
	</details>
<?php endif; ?>
<?php if ( ! empty( $leads_migration_errors ) ) : ?>
	<div class="notice notice-error">
		<p><strong><?php esc_html_e( 'Some leads could not be migrated to the shared portal database.', 'ajforms' ); ?></strong>
		<?php esc_html_e( 'They are still safe in this site\'s local database and the migration will retry automatically. Last errors:', 'ajforms' ); ?></p>
		<ul style="margin:4px 0 8px 20px;list-style:disc;">
			<?php foreach ( array_slice( $leads_migration_errors, 0, 5 ) as $mig_err ) : ?>
				<li><code><?php echo esc_html( $mig_err ); ?></code></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php elseif ( $leads_local_pending > 0 ) : ?>
	<div class="notice notice-warning">
		<p><?php echo esc_html( sprintf( __( '%d leads from this site\'s local database have not been migrated to the shared portal database yet. The migration runs automatically on the next plugin upgrade check — reload this page in a moment.', 'ajforms' ), $leads_local_pending ) ); ?></p>
	</div>
<?php endif; ?>

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
