<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The event log: one row per email sent, webhook fired, or submission
 * received. Filterable by type/status and searchable by message.
 */
class FC_Logs_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'log',
				'plural'   => 'logs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'created' => __( 'Time', 'formcatch' ),
			'type'    => __( 'Event type', 'formcatch' ),
			'status'  => __( 'Status', 'formcatch' ),
			'form'    => __( 'Form', 'formcatch' ),
			'message' => __( 'Event', 'formcatch' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'created' => array( 'created_at', true ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$table       = FC_DB::logs_table();
		$forms_table = FC_DB::forms_table();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page     = 30;
		$current_page = $this->get_pagenum();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $_GET['event_type'] ) && 'all' !== $_GET['event_type'] ) {
			$where[]  = 'l.event_type = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['event_type'] ) );
		}

		if ( ! empty( $_GET['status'] ) && 'all' !== $_GET['status'] ) {
			$where[]  = 'l.status = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['status'] ) );
		}

		if ( ! empty( $_GET['form_id'] ) ) {
			$where[]  = 'l.form_id = %d';
			$params[] = absint( $_GET['form_id'] );
		}

		if ( ! empty( $_GET['s'] ) ) {
			$like     = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
			$where[]  = '( l.message LIKE %s OR l.context LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = ! empty( $_GET['orderby'] ) ? sanitize_sql_orderby( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = ( ! empty( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';

		$base_query = "FROM {$table} l LEFT JOIN {$forms_table} f ON f.id = l.form_id WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( "SELECT COUNT(*) {$base_query}", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$count_sql = "SELECT COUNT(*) {$base_query}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total_items = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset       = ( $current_page - 1 ) * $per_page;
		$select_sql   = "SELECT l.*, f.name AS form_name {$base_query} ORDER BY l.{$orderby} {$order} LIMIT %d OFFSET %d";
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

	public function column_created( $item ) {
		return esc_html( mysql2date( 'Y-m-d H:i:s', $item->created_at ) );
	}

	public function column_type( $item ) {
		$labels = array(
			'submission' => __( 'Submission', 'formcatch' ),
			'email'      => __( 'Email', 'formcatch' ),
			'webhook'    => __( 'Webhook', 'formcatch' ),
		);
		$label = isset( $labels[ $item->event_type ] ) ? $labels[ $item->event_type ] : $item->event_type;
		return esc_html( $label );
	}

	public function column_status( $item ) {
		return '<span class="fc-badge fc-badge-log-' . esc_attr( $item->status ) . '">' . esc_html( ucfirst( $item->status ) ) . '</span>';
	}

	public function column_form( $item ) {
		if ( ! $item->form_id ) {
			return '&mdash;';
		}
		return esc_html( $item->form_name ? $item->form_name : __( '(deleted form)', 'formcatch' ) );
	}

	public function column_message( $item ) {
		$out = '<span>' . esc_html( $item->message ) . '</span>';

		// Show structured context inline for quick diagnosis.
		if ( ! empty( $item->context ) ) {
			$ctx = json_decode( $item->context, true );
			if ( is_array( $ctx ) && ! empty( $ctx ) ) {
				$bits = array();
				foreach ( $ctx as $k => $v ) {
					if ( is_array( $v ) ) {
						$v = implode( ', ', array_map( 'strval', $v ) );
					}
					if ( '' === (string) $v ) {
						continue;
					}
					$bits[] = esc_html( $k . ': ' . $v );
				}
				if ( $bits ) {
					$out .= '<br /><small class="fc-log-context">' . implode( ' &nbsp;·&nbsp; ', $bits ) . '</small>';
				}
			}
		}

		return $out;
	}

	public function no_items() {
		esc_html_e( 'No log events found.', 'formcatch' );
	}
}
