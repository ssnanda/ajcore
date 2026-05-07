<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class AJForms_Forms_List_Table extends WP_List_Table {

	private function get_status_header_markup() {
		$selected_status = isset( $_GET['form_status'] ) ? sanitize_text_field( wp_unslash( $_GET['form_status'] ) ) : '';

		ob_start();
		?>
		<label style="display:flex;align-items:center;gap:8px;font-weight:600;">
			<span><?php esc_html_e( 'Status', 'ajforms' ); ?></span>
			<select class="ajforms-status-filter" name="form_status">
				<option value=""><?php esc_html_e( 'All', 'ajforms' ); ?></option>
				<option value="published" <?php selected( $selected_status, 'published' ); ?>><?php esc_html_e( 'Published', 'ajforms' ); ?></option>
				<option value="draft" <?php selected( $selected_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'ajforms' ); ?></option>
				<option value="deleted" <?php selected( $selected_status, 'deleted' ); ?>><?php esc_html_e( 'Deleted', 'ajforms' ); ?></option>
			</select>
		</label>
		<?php
		return ob_get_clean();
	}

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'form',
				'plural'   => 'forms',
				'ajax'     => false,
			)
		);
	}

	public function process_bulk_action() {
		if ( 'bulk-delete' === $this->current_action() ) {
			check_admin_referer( 'bulk-' . $this->_args['plural'] );

			$form_ids = isset( $_POST['form_id'] ) ? array_map( 'intval', wp_unslash( $_POST['form_id'] ) ) : array();

			if ( ! empty( $form_ids ) && current_user_can( 'manage_options' ) ) {
				$admin = new AJForms_Admin();
				$admin->bulk_delete_forms( $form_ids );

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'    => 'ajforms',
							'deleted' => count( $form_ids ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'title'     => __( 'Title', 'ajforms' ),
			'shortcode' => __( 'Shortcode', 'ajforms' ),
			'entries'   => __( 'Entries', 'ajforms' ),
			'date'      => __( 'Date & Time', 'ajforms' ),
			'status'    => $this->get_status_header_markup(),
			'actions'   => __( 'Actions', 'ajforms' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
			'date'  => array( 'created_at', false ),
		);
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'shortcode':
				return '<code class="ajforms-shortcode-chip">[ajforms id="' . absint( $item['id'] ) . '"]</code>';

			case 'entries':
				$count = isset( $item['entries_count'] ) ? intval( $item['entries_count'] ) : 0;
				$url   = add_query_arg(
					array(
						'page'    => 'ajforms-leads',
						'form_id' => absint( $item['id'] ),
					),
					admin_url( 'admin.php' )
				);

				return '<a href="' . esc_url( $url ) . '">' . esc_html( $count ) . '</a>';

			case 'date':
				return esc_html(
					wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						strtotime( $item['created_at'] )
					)
				);

			case 'status':
				$status = sanitize_text_field( $item['status'] );
				return '<span class="ajforms-status-badge ' . esc_attr( 'is-' . $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';

			case 'actions':
				return $this->render_actions_column( $item );

			default:
				return '';
		}
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="form_id[]" value="%s" />',
			absint( $item['id'] )
		);
	}

	protected function column_title( $item ) {
		$status_label = isset( $item['status'] ) ? sanitize_text_field( ucfirst( $item['status'] ) ) : __( 'Draft', 'ajforms' );

		return sprintf(
			'<div class="ajforms-form-title-cell"><strong>%1$s</strong><span>%2$s</span></div>',
			esc_html( $item['title'] ),
			esc_html( $status_label )
		);
	}

	public function single_row( $item ) {
		$form_id  = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		$status   = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : 'draft';
		$edit_url = add_query_arg(
			array(
				'page'    => 'ajforms',
				'action'  => 'edit',
				'form_id' => $form_id,
			),
			admin_url( 'admin.php' )
		);

		$row_classes = array( 'ajforms-form-row' );
		if ( 'deleted' === $status ) {
			$row_classes[] = 'is-deleted';
		}

		printf(
			'<tr class="%1$s" data-edit-url="%2$s">',
			esc_attr( implode( ' ', $row_classes ) ),
			esc_url( $edit_url )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	private function render_actions_column( $item ) {
		$admin      = new AJForms_Admin();
		$form_id    = absint( $item['id'] );
		$status     = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : 'draft';
		$edit_url   = add_query_arg(
			array(
				'page'    => 'ajforms',
				'action'  => 'edit',
				'form_id' => $form_id,
			),
			admin_url( 'admin.php' )
		);
		$preview_url   = $admin->get_form_preview_url( $form_id );
		$duplicate_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'ajforms',
					'form_action' => 'duplicate',
					'form_id'     => $form_id,
				),
				admin_url( 'admin.php' )
			),
			'ajf_form_action_' . $form_id
		);
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'ajf_export_form',
					'form_id'     => $form_id,
				),
				admin_url( 'admin-post.php' )
			),
			'ajf_export_form'
		);

		if ( 'deleted' === $status ) {
			$restore_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'ajforms',
						'form_action' => 'restore',
						'form_id'     => $form_id,
					),
					admin_url( 'admin.php' )
				),
				'ajf_form_action_' . $form_id
			);

			return '<div class="ajforms-inline-actions">'
				. '<a class="button button-small" href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Restore', 'ajforms' ) . '</a>'
				. '</div>';
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'ajforms',
					'form_action' => 'delete',
					'form_id'     => $form_id,
				),
				admin_url( 'admin.php' )
			),
			'ajf_form_action_' . $form_id
		);

		return '<div class="ajforms-inline-actions">'
			. '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'ajforms' ) . '</a>'
			. '<a class="button button-small" href="' . esc_url( $preview_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Preview', 'ajforms' ) . '</a>'
			. '<a class="button button-small" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export', 'ajforms' ) . '</a>'
			. '<a class="button button-small" href="' . esc_url( $duplicate_url ) . '">' . esc_html__( 'Duplicate', 'ajforms' ) . '</a>'
			. '<a class="button button-small button-link-delete" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Move this form to deleted?\');">' . esc_html__( 'Delete', 'ajforms' ) . '</a>'
			. '</div>';
	}

	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			echo '<div class="tablenav top">';
			$this->pagination( $which );
			echo '<br class="clear" /></div>';
			return;
		}

		parent::display_tablenav( $which );
	}

	public function get_bulk_actions() {
		return array(
			'bulk-delete' => __( 'Delete', 'ajforms' ),
		);
	}

	public function prepare_items() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'ajforms_forms';
		$leads_table = $wpdb->prefix . 'ajforms_leads';
		$per_page   = 20;
		$paged      = $this->get_pagenum();
		$offset     = ( $paged - 1 ) * $per_page;

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$this->items = array();

			$this->set_pagination_args(
				array(
					'total_items' => 0,
					'per_page'    => $per_page,
					'total_pages' => 0,
				)
			);

			return;
		}

		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status_filter = isset( $_GET['form_status'] ) ? sanitize_text_field( wp_unslash( $_GET['form_status'] ) ) : '';

		$allowed_orderby = array( 'title', 'created_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'created_at';
		}

		$order = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		$where_clauses = array();
		$where_values  = array();

		if ( in_array( $status_filter, array( 'published', 'draft', 'deleted' ), true ) ) {
			$where_clauses[] = 'f.status = %s';
			$where_values[]  = $status_filter;
		} else {
			$where_clauses[] = 'f.status IN (%s, %s)';
			$where_values[]  = 'published';
			$where_values[]  = 'draft';
		}

		if ( '' !== $search ) {
			$where_clauses[] = 'f.title LIKE %s';
			$where_values[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where = '';
		if ( ! empty( $where_clauses ) ) {
			$where = ' WHERE ' . implode( ' AND ', $where_clauses );
		}

		$base_sql = "
			FROM {$table_name} f
			LEFT JOIN {$leads_table} l ON f.id = l.form_id
			{$where}
			GROUP BY f.id
		";

		if ( ! empty( $where_values ) ) {
			$count_sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT f.id {$base_sql}
				) AS counted_forms",
				$where_values
			);
			$total_items = (int) $wpdb->get_var( $count_sql );

			$query_values = array_merge( $where_values, array( $per_page, $offset ) );
			$results_sql  = $wpdb->prepare(
				"SELECT f.*, COUNT(l.id) AS entries_count {$base_sql} ORDER BY f.{$orderby} {$order} LIMIT %d OFFSET %d",
				$query_values
			);
			$this->items = $wpdb->get_results( $results_sql, ARRAY_A );
		} else {
			$total_items = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" );
			$results_sql = $wpdb->prepare(
				"SELECT f.*, COUNT(l.id) AS entries_count
				FROM {$table_name} f
				LEFT JOIN {$leads_table} l ON f.id = l.form_id
				GROUP BY f.id
				ORDER BY f.{$orderby} {$order}
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
			$this->items = $wpdb->get_results( $results_sql, ARRAY_A );
		}

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ( $total_items > 0 ) ? (int) ceil( $total_items / $per_page ) : 0,
			)
		);
	}
}
