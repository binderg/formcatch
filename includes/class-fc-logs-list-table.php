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
			'created'    => __( 'Time', 'formcatch' ),
			'type'       => __( 'Event type', 'formcatch' ),
			'status'     => __( 'Status', 'formcatch' ),
			'form'       => __( 'Form', 'formcatch' ),
			'submission' => __( 'Submission', 'formcatch' ),
			'message'    => __( 'Message', 'formcatch' ),
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
		$subs_table  = FC_DB::submissions_table();

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

		// The submissions join lets us tell "no submission attached" apart from
		// "submission was deleted", so we never render a link to a dead row.
		$base_query = "FROM {$table} l
			LEFT JOIN {$forms_table} f ON f.id = l.form_id
			LEFT JOIN {$subs_table} s ON s.id = l.submission_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$count_sql = $wpdb->prepare( "SELECT COUNT(*) {$base_query}", $params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$count_sql = "SELECT COUNT(*) {$base_query}"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total_items = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset       = ( $current_page - 1 ) * $per_page;
		$select_sql   = "SELECT l.*, f.name AS form_name, s.id AS submission_exists {$base_query} ORDER BY l.{$orderby} {$order} LIMIT %d OFFSET %d";
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

	/**
	 * Link to the submission this event belongs to — "the email failed, show me
	 * the submission it was for" is the question this screen exists to answer.
	 */
	public function column_submission( $item ) {
		if ( ! $item->submission_id ) {
			return '&mdash;';
		}

		// Submission rows can be pruned by retention while the log survives.
		if ( ! $item->submission_exists ) {
			return '<span class="fc-muted">' . esc_html__( '(deleted)', 'formcatch' ) . '</span>';
		}

		$url = admin_url( 'admin.php?page=formcatch-submissions&view=' . (int) $item->submission_id );

		return sprintf(
			'<a href="%s">#%d</a>',
			esc_url( $url ),
			(int) $item->submission_id
		);
	}

	public function column_message( $item ) {
		$out = '<span>' . esc_html( $item->message ) . '</span>';

		if ( empty( $item->context ) ) {
			return $out;
		}

		$ctx = json_decode( $item->context, true );
		if ( ! is_array( $ctx ) || empty( $ctx ) ) {
			return $out;
		}

		$rows = array();
		foreach ( $ctx as $key => $value ) {
			$value = self::stringify_context_value( $value );
			if ( '' === $value ) {
				continue;
			}
			$rows[] = '<dt>' . esc_html( $key ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}

		if ( ! $rows ) {
			return $out;
		}

		// Collapsed by default: a failed webhook can carry a 500-character
		// response body, which would otherwise wreck the table row.
		$out .= '<details class="fc-log-context">';
		$out .= '<summary>' . esc_html__( 'Details', 'formcatch' ) . '</summary>';
		$out .= '<dl>' . implode( '', $rows ) . '</dl>';
		$out .= '</details>';

		return $out;
	}

	/**
	 * Flatten a single context value for display. Nested arrays are rendered as
	 * pretty JSON rather than imploded, which previously turned any nested
	 * associative array into the literal string "Array" and dropped its keys.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function stringify_context_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '';
		}

		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}

		if ( is_array( $value ) ) {
			// A flat list stays readable as a comma-separated line.
			if ( array_values( $value ) === $value && ! array_filter( $value, 'is_array' ) ) {
				return implode( ', ', array_map( array( __CLASS__, 'stringify_context_value' ), $value ) );
			}
			return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		return '';
	}

	public function no_items() {
		esc_html_e( 'No log events found.', 'formcatch' );
	}
}
