<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FC_Submissions_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'preview'  => __( 'Submission', 'formcatch' ),
			'form'     => __( 'Form', 'formcatch' ),
			'status'   => __( 'Status', 'formcatch' ),
			'ip'       => __( 'IP', 'formcatch' ),
			'created'  => __( 'Date', 'formcatch' ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'read'   => __( 'Mark as Read', 'formcatch' ),
			'spam'   => __( 'Mark as Spam', 'formcatch' ),
			'delete' => __( 'Delete', 'formcatch' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'created' => array( 'created_at', true ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$table       = FC_DB::submissions_table();
		$forms_table = FC_DB::forms_table();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page     = 20;
		$current_page = $this->get_pagenum();

		$where  = array( '1=1' );
		$params = array();

		// Filter: form.
		if ( ! empty( $_GET['form_id'] ) ) {
			$where[]  = 's.form_id = %d';
			$params[] = absint( $_GET['form_id'] );
		}

		// Filter: status.
		if ( ! empty( $_GET['status'] ) && 'all' !== $_GET['status'] ) {
			$where[]  = 's.status = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['status'] ) );
		}

		// Filter: date range.
		if ( ! empty( $_GET['date_from'] ) ) {
			$where[]  = 's.created_at >= %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) . ' 00:00:00';
		}
		if ( ! empty( $_GET['date_to'] ) ) {
			$where[]  = 's.created_at <= %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) . ' 23:59:59';
		}

		// Search: naive LIKE against the JSON payload. Good enough for
		// v1 — not full-text, but catches names/emails/keywords fine
		// at the kind of submission volumes this plugin targets.
		if ( ! empty( $_GET['s'] ) ) {
			$where[]  = 's.payload LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = ! empty( $_GET['orderby'] ) ? sanitize_sql_orderby( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = ( ! empty( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';

		$base_query = "FROM {$table} s LEFT JOIN {$forms_table} f ON f.id = s.form_id WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( "SELECT COUNT(*) {$base_query}", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$count_sql = "SELECT COUNT(*) {$base_query}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total_items = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset = ( $current_page - 1 ) * $per_page;

		$select_sql = "SELECT s.*, f.name AS form_name {$base_query} ORDER BY s.{$orderby} {$order} LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );

		$this->items = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="submission_ids[]" value="%d" />', $item->id );
	}

	public function column_preview( $item ) {
		$payload = json_decode( $item->payload, true );
		$snippet = '';

		if ( is_array( $payload ) ) {
			$parts = array();
			foreach ( $payload as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$parts[] = $key . ': ' . $value;
				if ( count( $parts ) >= 3 ) {
					break;
				}
			}
			$snippet = implode( '  |  ', $parts );
		}

		$snippet = wp_trim_words( $snippet, 20, '…' );
		$view_url = admin_url( 'admin.php?page=formcatch-submissions&view=' . $item->id );

		return sprintf(
			'<a href="%s"><strong>%s</strong></a>',
			esc_url( $view_url ),
			esc_html( $snippet ? $snippet : __( '(empty submission)', 'formcatch' ) )
		);
	}

	public function column_form( $item ) {
		return esc_html( $item->form_name ? $item->form_name : __( '(deleted form)', 'formcatch' ) );
	}

	public function column_status( $item ) {
		$labels = array(
			'new'  => __( 'New', 'formcatch' ),
			'read' => __( 'Read', 'formcatch' ),
			'spam' => __( 'Spam', 'formcatch' ),
		);
		$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;
		return '<span class="fc-badge fc-badge-' . esc_attr( $item->status ) . '">' . esc_html( $label ) . '</span>';
	}

	public function column_ip( $item ) {
		return esc_html( $item->ip );
	}

	public function column_created( $item ) {
		return esc_html( mysql2date( 'Y-m-d H:i', $item->created_at ) );
	}

	public function no_items() {
		esc_html_e( 'No submissions found.', 'formcatch' );
	}
}
