<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class AJForms_Leads_List_Table extends WP_List_Table {

	/** form_id => title from this site's local forms table (fallback when a lead row's
	 *  stored form_title is blank — e.g., rows migrated before titles resolved). */
	protected $local_form_titles = array();

	/** site_uuid => bare hostname from the shared aj_shared_sites table. */
	protected $site_labels = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'crm_record',
				'plural'   => 'crm_records',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'id'           => __( 'Record ID', 'ajforms' ),
			'form_title'   => __( 'Form Name', 'ajforms' ),
			'name'         => __( 'Name', 'ajforms' ),
			'email'        => __( 'Email', 'ajforms' ),
			'phone'        => __( 'Phone', 'ajforms' ),
			'company'      => __( 'Company', 'ajforms' ),
			'status'       => __( 'Status', 'ajforms' ),
			'lead_status'  => __( 'Lead Status', 'ajforms' ),
			'created_at'   => __( 'Date & Time', 'ajforms' ),
			'actions'      => __( 'Actions', 'ajforms' ),
		);
	}

	private function format_us_phone_for_display( $phone ) {
		$phone  = trim( (string) $phone );
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( 11 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) ) {
			return '+1 ' . substr( $digits, 0, 3 ) . ' ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
		}

		return $phone;
	}

	public function get_sortable_columns() {
		return array(
			'id'          => array( 'l.id', true ),
			'status'      => array( 'l.status', false ),
			'lead_status' => array( 'l.lead_status', false ),
			'created_at'  => array( 'l.created_at', true ),
		);
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="lead_id[]" value="%s" />',
			absint( $item['id'] )
		);
	}

	public function single_row( $item ) {
		$detail_url = add_query_arg(
			array(
				'page'    => 'ajforms-leads',
				'view'    => 'detail',
				'lead_id' => absint( $item['id'] ),
			),
			admin_url( 'admin.php' )
		);

		printf(
			'<tr class="%1$s" data-detail-url="%2$s">',
			esc_attr( 'ajforms-lead-row' ),
			esc_url( $detail_url )
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	public function get_bulk_actions() {
		return array(
			'mark_read'       => __( 'Mark as Read', 'ajforms' ),
			'mark_new'        => __( 'Mark as New', 'ajforms' ),
			'mark_lost'       => __( 'Mark as Lost', 'ajforms' ),
			'mark_duplicate'  => __( 'Mark as Duplicate', 'ajforms' ),
			'merge_duplicates'=> __( 'Merge Duplicates (keep earliest)', 'ajforms' ),
			'delete'          => __( 'Delete', 'ajforms' ),
		);
	}

	public function process_bulk_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action   = $this->current_action();
		$lead_ids = isset( $_POST['lead_id'] ) ? array_map( 'intval', wp_unslash( $_POST['lead_id'] ) ) : array();

		if ( empty( $action ) || empty( $lead_ids ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$wpdb             = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];
		$leads_table      = $wpdb->prefix . 'aj_forms_leads';
		$lead_notes_table = $wpdb->prefix . 'aj_forms_lead_notes';
		$placeholders     = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );

		$status_map = array(
			'mark_read'      => 'read',
			'mark_new'       => 'new',
			'mark_lost'      => 'lost',
			'mark_duplicate' => 'duplicate',
		);

		if ( 'delete' === $action ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$lead_notes_table} WHERE lead_id IN ({$placeholders})",
					$lead_ids
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$leads_table} WHERE id IN ({$placeholders})",
					$lead_ids
				)
			);
		} elseif ( isset( $status_map[ $action ] ) ) {
			$params = array_merge( array( $status_map[ $action ], current_time( 'mysql' ) ), $lead_ids );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$leads_table} SET status = %s, updated_at = %s WHERE id IN ({$placeholders})",
					$params
				)
			);
		} elseif ( 'merge_duplicates' === $action && count( $lead_ids ) >= 2 && class_exists( 'AJForms_Admin' ) ) {
			$admin = AJForms_Admin::$instance ? AJForms_Admin::$instance : new AJForms_Admin();
			sort( $lead_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$leads_table} WHERE id IN ({$placeholders}) ORDER BY created_at ASC, id ASC", $lead_ids ) );
			$primary_id = ! empty( $rows ) ? (int) $rows[0]->id : 0;
			if ( $primary_id ) {
				foreach ( $lead_ids as $id ) {
					if ( $id !== $primary_id ) {
						$admin->merge_portal_lead_into( $primary_id, $id );
					}
				}
			}
		}
	}

	/**
	 * Two-pass fuzzy extraction — same logic as extract_lead_field() in the REST API.
	 * Pass 1 skips non-text field types so a radio "yes/no" doesn't beat a text "Business Name".
	 * Pass 2 falls back to any type.
	 */
	private function extract_field( $decoded, $preferred_keys ) {
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$skip_types = array( 'radio', 'checkbox', 'select', 'hidden', 'file', 'button', 'submit' );

		foreach ( $preferred_keys as $preferred_key ) {
			foreach ( $decoded as $field_key => $field ) {
				if ( ! is_array( $field ) || '_meta' === $field_key ) {
					continue;
				}
				$type = isset( $field['type'] ) ? strtolower( trim( $field['type'] ) ) : '';
				if ( in_array( $type, $skip_types, true ) ) {
					continue;
				}
				$label = isset( $field['label'] ) ? strtolower( trim( $field['label'] ) ) : '';
				$key   = strtolower( trim( (string) $field_key ) );
				if ( false !== strpos( $label, $preferred_key ) || false !== strpos( $key, $preferred_key ) ) {
					$value = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					return (string) $value;
				}
			}
		}

		foreach ( $preferred_keys as $preferred_key ) {
			foreach ( $decoded as $field_key => $field ) {
				if ( ! is_array( $field ) || '_meta' === $field_key ) {
					continue;
				}
				$label = isset( $field['label'] ) ? strtolower( trim( $field['label'] ) ) : '';
				$key   = strtolower( trim( (string) $field_key ) );
				if ( false !== strpos( $label, $preferred_key ) || false !== strpos( $key, $preferred_key ) ) {
					$value = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					return (string) $value;
				}
			}
		}

		return '';
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return '<span class="ajforms-entry-id-chip">#' . absint( $item['id'] ) . '</span>';

			case 'form_title':
				$form_id        = absint( $item['form_id'] );
				$form_title     = isset( $item['form_title'] ) ? (string) $item['form_title'] : '';
				$lead_site      = isset( $item['site_uuid'] ) ? (string) $item['site_uuid'] : '';
				$this_site_uuid = (string) get_option( 'ajcore_site_uuid', '' );
				// Local titles only apply to this site's own leads — form IDs collide across sites.
				if ( '' === $form_title && $form_id && ( '' === $lead_site || $lead_site === $this_site_uuid ) && isset( $this->local_form_titles[ $form_id ] ) ) {
					$form_title = $this->local_form_titles[ $form_id ];
				}
				if ( '' === $form_title ) {
					$form_title = $form_id ? sprintf( __( 'Form #%d', 'ajforms' ), $form_id ) : __( 'Manual entry', 'ajforms' );
				}
				$site_label = ( '' !== $lead_site && isset( $this->site_labels[ $lead_site ] ) ) ? $this->site_labels[ $lead_site ] : '';
				return '<div class="ajforms-form-title-cell"><strong>' . esc_html( $form_title ) . '</strong><span>' . esc_html( '' !== $site_label ? $site_label : __( 'Source form', 'ajforms' ) ) . '</span></div>';

			case 'name':
				$name       = $item['_name'];
				$detail_url = add_query_arg(
					array(
						'page'    => 'ajforms-leads',
						'view'    => 'detail',
						'lead_id' => absint( $item['id'] ),
					),
					admin_url( 'admin.php' )
				);
				$display = '' !== $name ? esc_html( $name ) : '<em>' . esc_html__( '(no name)', 'ajforms' ) . '</em>';
				return '<a href="' . esc_url( $detail_url ) . '" class="ajforms-lead-name-link">' . $display . '</a>';

			case 'email':
				$email = $item['_email'];
				if ( '' !== $email ) {
					return '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
				}
				return '<span class="ajforms-empty">—</span>';

			case 'phone':
				$phone = $item['_phone'];
				return '' !== $phone ? esc_html( $this->format_us_phone_for_display( $phone ) ) : '<span class="ajforms-empty">—</span>';

			case 'company':
				$company = $item['_company'];
				return '' !== $company ? esc_html( $company ) : '<span class="ajforms-empty">—</span>';

			case 'status':
				$status = sanitize_text_field( $item['status'] );
				$labels = array( 'new' => __( 'New', 'ajforms' ), 'read' => __( 'Read', 'ajforms' ), 'won' => __( 'Won', 'ajforms' ), 'lost' => __( 'Lost', 'ajforms' ), 'duplicate' => __( 'Duplicate', 'ajforms' ) );
				$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
				$out    = '<span class="ajforms-status-badge ' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
				if ( 'won' === $status && ! empty( $item['stripe_customer_id'] ) ) {
					global $wpdb;
					$pdb    = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $wpdb;
					$prefix = $pdb->prefix;
					$name   = $pdb->get_var( $pdb->prepare( "SELECT name FROM {$prefix}aj_portal_stripe_customers WHERE stripe_customer_id = %s", $item['stripe_customer_id'] ) );
					if ( $name ) {
						$out .= '<br><span class="ajforms-empty">→ ' . esc_html( $name ) . '</span>';
					}
				}
				return $out;

			case 'lead_status':
				$lead_id      = absint( $item['id'] );
				$current      = ! empty( $item['lead_status'] ) ? sanitize_key( (string) $item['lead_status'] ) : 'new';
				$pipeline_labels = array(
					'new'               => __( 'New', 'ajforms' ),
					'welcomed'          => __( 'Welcomed', 'ajforms' ),
					'engaged'           => __( 'Engaged', 'ajforms' ),
					'qualified'         => __( 'Qualified', 'ajforms' ),
					'meeting_scheduled' => __( 'Meeting Scheduled', 'ajforms' ),
					'proposal_sent'     => __( 'Proposal Sent', 'ajforms' ),
				);
				$stage_url = function ( $stage ) use ( $lead_id ) {
					return wp_nonce_url(
						add_query_arg(
							array( 'page' => 'ajforms-leads', 'lead_action' => 'set_pipeline_status', 'pipeline_status' => $stage, 'lead_id' => $lead_id ),
							admin_url( 'admin.php' )
						),
						'ajf_lead_action_' . $lead_id
					);
				};
				$out  = '<span class="ajforms-status-badge lead-pipeline-' . esc_attr( $current ) . '">' . esc_html( isset( $pipeline_labels[ $current ] ) ? $pipeline_labels[ $current ] : ucfirst( $current ) ) . '</span>';
				$out .= '<select class="ajforms-lead-pipeline-select" onchange="if(this.value){ location.href = this.value; }">';
				$out .= '<option value="">' . esc_html__( 'Change…', 'ajforms' ) . '</option>';
				foreach ( $pipeline_labels as $stage_key => $stage_label ) {
					if ( $stage_key === $current ) {
						continue;
					}
					$out .= '<option value="' . esc_url( $stage_url( $stage_key ) ) . '">' . esc_html( $stage_label ) . '</option>';
				}
				$out .= '</select>';
				return $out;

			case 'created_at':
				return esc_html(
					wp_date(
						get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
						strtotime( $item['created_at'] )
					)
				);

			case 'actions':
				$lead_id    = absint( $item['id'] );
				$status     = sanitize_text_field( $item['status'] );
				$detail_url = add_query_arg(
					array(
						'page'    => 'ajforms-leads',
						'view'    => 'detail',
						'lead_id' => $lead_id,
					),
					admin_url( 'admin.php' )
				);

				$quick_link = function ( $action, $label ) use ( $lead_id ) {
					$url = wp_nonce_url(
						add_query_arg(
							array( 'page' => 'ajforms-leads', 'lead_action' => $action, 'lead_id' => $lead_id ),
							admin_url( 'admin.php' )
						),
						'ajf_lead_action_' . $lead_id
					);
					return '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				};

				$out = '<div class="ajforms-inline-actions"><a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Open', 'ajforms' ) . '</a>';

				if ( in_array( $status, array( 'new', 'read' ), true ) ) {
					$toggle_action = ( 'new' === $status ) ? 'mark_read' : 'mark_new';
					$toggle_label  = ( 'new' === $status ) ? __( 'Mark Read', 'ajforms' ) : __( 'Mark New', 'ajforms' );
					$out          .= $quick_link( $toggle_action, $toggle_label );
					$out          .= $quick_link( 'mark_lost', __( 'Lost', 'ajforms' ) );
					$out          .= $quick_link( 'mark_duplicate', __( 'Dup', 'ajforms' ) );
				} elseif ( in_array( $status, array( 'lost', 'duplicate' ), true ) ) {
					$out .= $quick_link( 'reopen', __( 'Reopen', 'ajforms' ) );
				}
				// "Won" has no quick action here — linking to a customer requires the picker on the detail page.

				$out .= '</div>';
				return $out;
		}

		return '';
	}

	public function prepare_items() {
		// Leads live on the shared portal DB in multi-site mode; form_title is stored on
		// the lead row itself (forms are per-site, so a JOIN can't resolve cross-site titles).
		$wpdb = function_exists( 'ajcore_get_portal_db' ) ? ajcore_get_portal_db() : $GLOBALS['wpdb'];

		$leads_table = $wpdb->prefix . 'aj_forms_leads';

		// Fallback titles from this site's local forms, and site labels from the shared
		// control table, for the Form Name column.
		$local_db          = $GLOBALS['wpdb'];
		$local_forms_table = $local_db->prefix . 'aj_forms_forms';
		if ( $local_db->get_var( $local_db->prepare( 'SHOW TABLES LIKE %s', $local_forms_table ) ) === $local_forms_table ) {
			foreach ( (array) $local_db->get_results( "SELECT id, title FROM `{$local_forms_table}`" ) as $f ) {
				$this->local_form_titles[ (int) $f->id ] = (string) $f->title;
			}
		}
		$sites_table = $wpdb->prefix . 'aj_shared_sites';
		if ( $wpdb !== $local_db && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sites_table ) ) === $sites_table ) {
			foreach ( (array) $wpdb->get_results( "SELECT site_uuid, domain FROM `{$sites_table}`" ) as $site ) {
				$host = (string) wp_parse_url( (string) $site->domain, PHP_URL_HOST );
				$this->site_labels[ (string) $site->site_uuid ] = '' !== $host ? $host : trim( (string) $site->domain, '/' );
			}
		}

		$per_page = 20;
		$paged    = $this->get_pagenum();
		$offset   = ( $paged - 1 ) * $per_page;

		$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'l.created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC';
		$form_id = isset( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
		$status  = isset( $_GET['lead_status'] ) ? sanitize_text_field( wp_unslash( $_GET['lead_status'] ) ) : '';
		$queue   = isset( $_GET['lead_queue'] ) ? sanitize_key( wp_unslash( $_GET['lead_queue'] ) ) : 'inbox';

		$allowed_orderby = array( 'l.id', 'l.status', 'l.lead_status', 'l.created_at' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'l.created_at';
		}

		$order = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		$where  = array();
		$params = array();

		if ( $form_id ) {
			$where[]  = 'l.form_id = %d';
			$params[] = $form_id;
		}

		if ( 'lost' === $queue ) {
			$where[] = "l.status = 'lost'";
		} elseif ( 'archived' === $queue ) {
			$where[] = "l.status IN ('won','duplicate')";
		} else {
			$where[] = "l.status IN ('new','read')";
			if ( in_array( $status, array( 'read', 'new' ), true ) ) {
				$where[]  = 'l.status = %s';
				$params[] = $status;
			}
		}

		$where_sql = '';
		if ( ! empty( $where ) ) {
			$where_sql = ' WHERE ' . implode( ' AND ', $where );
		}

		$count_sql = "SELECT COUNT(l.id) FROM {$leads_table} l {$where_sql}";
		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $params );
		}
		$total_items = (int) $wpdb->get_var( $count_sql );

		$query_sql = "
			SELECT l.*
			FROM {$leads_table} l
			{$where_sql}
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d
		";

		$query_params   = array_merge( $params, array( $per_page, $offset ) );
		$prepared_query = $wpdb->prepare( $query_sql, $query_params );

		$raw_results = $wpdb->get_results( $prepared_query, ARRAY_A );

		$boolean_values = array( 'yes', 'no', 'true', 'false', '1', '0' );
		$this->items    = array();
		foreach ( $raw_results as $row ) {
			$data            = json_decode( (string) $row['lead_data'], true );
			$company_raw     = $this->extract_field( $data, array( 'business name', 'company name', 'company', 'business', 'organization', 'organisation' ) );
			$row['_name']    = $this->extract_field( $data, array( 'name', 'full name', 'your name' ) );
			$row['_email']   = $this->extract_field( $data, array( 'email', 'e-mail' ) );
			$row['_phone']   = $this->extract_field( $data, array( 'phone', 'mobile', 'tel', 'cell' ) );
			$row['_company'] = in_array( strtolower( trim( $company_raw ) ), $boolean_values, true ) ? '' : $company_raw;
			$this->items[]   = $row;
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
				'total_pages' => $total_items ? (int) ceil( $total_items / $per_page ) : 0,
			)
		);
	}
}
