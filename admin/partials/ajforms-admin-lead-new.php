<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<style>
		.ajl-new-shell { max-width: 720px; margin-top: 24px; }
		.ajl-new-card {
			background: #fff;
			border: 1px solid #e4ebf3;
			border-radius: 20px;
			box-shadow: 0 14px 36px rgba(15,23,42,.05);
			padding: 32px 36px;
		}
		.ajl-new-title { font-size: 24px; font-weight: 700; margin: 0 0 6px; color: #0f172a; }
		.ajl-new-sub   { color: #64748b; font-size: 14px; margin: 0 0 28px; }
		.ajl-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; }
		.ajl-field-row.full { grid-template-columns: 1fr; }
		.ajl-field     { display: flex; flex-direction: column; gap: 6px; }
		.ajl-field label { font-size: 13px; font-weight: 600; color: #334155; }
		.ajl-field input, .ajl-field select, .ajl-field textarea {
			border: 1px solid #cbd5e1;
			border-radius: 8px;
			padding: 9px 12px;
			font-size: 14px;
			color: #0f172a;
			background: #fff;
			outline: none;
			transition: border-color .15s;
			width: 100%;
			box-sizing: border-box;
		}
		.ajl-field input:focus, .ajl-field select:focus, .ajl-field textarea:focus {
			border-color: #3b82f6;
			box-shadow: 0 0 0 3px rgba(59,130,246,.12);
		}
		.ajl-field textarea { min-height: 90px; resize: vertical; }
		.ajl-actions { display: flex; align-items: center; gap: 12px; margin-top: 28px; }
		.ajl-btn-primary {
			background: #2563eb;
			color: #fff;
			border: none;
			border-radius: 8px;
			padding: 10px 22px;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
		}
		.ajl-btn-primary:hover { background: #1d4ed8; }
		.ajl-divider { border: 0; border-top: 1px solid #e4ebf3; margin: 24px 0; }
	</style>

	<nav style="margin-bottom:18px;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ajforms-leads' ) ); ?>" style="color:#2563eb;text-decoration:none;font-size:13px;">
			&larr; <?php esc_html_e( 'Back to Leads', 'ajforms' ); ?>
		</a>
	</nav>

	<div class="ajl-new-shell">
		<div class="ajl-new-card">
			<h2 class="ajl-new-title"><?php esc_html_e( 'New Lead', 'ajforms' ); ?></h2>
			<p class="ajl-new-sub"><?php esc_html_e( 'Add a lead manually. Fields other than Name are optional.', 'ajforms' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ajforms-leads' ) ); ?>">
				<?php wp_nonce_field( 'ajf_create_lead', 'ajf_create_lead_nonce' ); ?>

				<div class="ajl-field-row">
					<div class="ajl-field">
						<label for="lead_name"><?php esc_html_e( 'Name', 'ajforms' ); ?> <span style="color:#ef4444;">*</span></label>
						<input type="text" id="lead_name" name="lead_name" required placeholder="<?php esc_attr_e( 'Full name', 'ajforms' ); ?>" value="<?php echo esc_attr( isset( $_GET['lead_name'] ) ? sanitize_text_field( wp_unslash( $_GET['lead_name'] ) ) : '' ); ?>" />
					</div>
					<div class="ajl-field">
						<label for="lead_email"><?php esc_html_e( 'Email', 'ajforms' ); ?></label>
						<input type="email" id="lead_email" name="lead_email" placeholder="<?php esc_attr_e( 'email@example.com', 'ajforms' ); ?>" />
					</div>
				</div>

				<hr class="ajl-divider" />

				<div class="ajl-field-row">
					<div class="ajl-field">
						<label for="lead_phone"><?php esc_html_e( 'Phone', 'ajforms' ); ?></label>
						<input type="text" id="lead_phone" name="lead_phone" placeholder="<?php esc_attr_e( '(555) 000-0000', 'ajforms' ); ?>" />
					</div>
					<div class="ajl-field">
						<label for="lead_company"><?php esc_html_e( 'Company', 'ajforms' ); ?></label>
						<input type="text" id="lead_company" name="lead_company" placeholder="<?php esc_attr_e( 'Company name', 'ajforms' ); ?>" />
					</div>
				</div>

				<div class="ajl-field-row" style="margin-top:16px;">
					<div class="ajl-field">
						<label for="lead_source"><?php esc_html_e( 'Source', 'ajforms' ); ?></label>
						<select id="lead_source" name="lead_source">
							<option value=""><?php esc_html_e( '— Select source —', 'ajforms' ); ?></option>
							<option value="manual"><?php esc_html_e( 'Manual entry', 'ajforms' ); ?></option>
							<option value="phone"><?php esc_html_e( 'Phone call', 'ajforms' ); ?></option>
							<option value="email"><?php esc_html_e( 'Email', 'ajforms' ); ?></option>
							<option value="referral"><?php esc_html_e( 'Referral', 'ajforms' ); ?></option>
							<option value="walk_in"><?php esc_html_e( 'Walk-in', 'ajforms' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'ajforms' ); ?></option>
						</select>
					</div>
				</div>

				<div class="ajl-field-row full" style="margin-top:16px;">
					<div class="ajl-field">
						<label for="lead_notes"><?php esc_html_e( 'Notes', 'ajforms' ); ?></label>
						<textarea id="lead_notes" name="lead_notes" placeholder="<?php esc_attr_e( 'Any relevant details about this lead…', 'ajforms' ); ?>"></textarea>
					</div>
				</div>

				<div class="ajl-actions">
					<button type="submit" class="ajl-btn-primary"><?php esc_html_e( 'Create Lead', 'ajforms' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ajforms-leads' ) ); ?>" style="font-size:14px;color:#64748b;text-decoration:none;"><?php esc_html_e( 'Cancel', 'ajforms' ); ?></a>
				</div>
			</form>
		</div>
	</div>
</div>
