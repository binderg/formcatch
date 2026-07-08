<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FC_Forms_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'form',
				'plural'   => 'forms',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'name'      => __( 'Name', 'formcatch' ),
			'endpoint'  => __( 'Endpoint', 'formcatch' ),
			'status'    => __( 'Status', 'formcatch' ),
			'submitted' => __( 'Submissions', 'formcatch' ),
			'created'   => __( 'Created', 'formcatch' ),
		);
	}

	public function prepare_items() {
		global $wpdb;
		$forms_table       = FC_DB::forms_table();
		$submissions_table = FC_DB::submissions_table();

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->items = $wpdb->get_results(
			"SELECT f.*, (SELECT COUNT(*) FROM {$submissions_table} s WHERE s.form_id = f.id) AS submission_count
			 FROM {$forms_table} f ORDER BY f.created_at DESC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	public function column_name( $item ) {
		$edit_url = admin_url( 'admin.php?page=formcatch-forms&action=edit&form_id=' . $item->id );
		$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=fc_delete_form&form_id=' . $item->id ), 'fc_delete_form' );

		$is_active  = ( 'active' === $item->status );
		$toggle_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fc_toggle_form_status&form_id=' . $item->id ),
			'fc_toggle_form_status'
		);

		$rotate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fc_rotate_token&form_id=' . $item->id ),
			'fc_rotate_token'
		);

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fc_export_form&form_id=' . $item->id ),
			'fc_export_form'
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'formcatch' ) ),
			'export' => sprintf( '<a href="%s">%s</a>', esc_url( $export_url ), esc_html__( 'Export JSON', 'formcatch' ) ),
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $toggle_url ),
				$is_active ? esc_html__( 'Disable', 'formcatch' ) : esc_html__( 'Enable', 'formcatch' )
			),
			'rotate' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $rotate_url ),
				esc_js( __( 'Rotate this form\'s endpoint token? The old URL will stop working immediately — update any embedded forms.', 'formcatch' ) ),
				esc_html__( 'Rotate token', 'formcatch' )
			),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');" style="color:#b32d2e;">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this form? Submissions will not be deleted.', 'formcatch' ) ),
				esc_html__( 'Delete', 'formcatch' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item->name ),
			$this->row_actions( $actions )
		);
	}

	public function column_endpoint( $item ) {
		$url = FC_Helpers::submit_url( $item->token );
		return '<code class="fc-endpoint" style="user-select:all;">' . esc_html( $url ) . '</code>';
	}

	public function column_status( $item ) {
		$is_active = ( 'active' === $item->status );
		$label     = $is_active ? __( 'Active', 'formcatch' ) : __( 'Disabled', 'formcatch' );
		$class     = $is_active ? 'fc-badge fc-badge-active' : 'fc-badge fc-badge-disabled';
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	public function column_submitted( $item ) {
		$url = admin_url( 'admin.php?page=formcatch-submissions&form_id=' . $item->id );
		return sprintf( '<a href="%s">%d</a>', esc_url( $url ), (int) $item->submission_count );
	}

	public function column_created( $item ) {
		return esc_html( mysql2date( get_option( 'date_format' ), $item->created_at ) );
	}

	public function no_items() {
		esc_html_e( 'No forms yet. Click "Add New" to create your first endpoint.', 'formcatch' );
	}
}
