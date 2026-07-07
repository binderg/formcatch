<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var FC_Submissions_List_Table $list_table
 * @var array                     $forms
 */
$current_form_id = ! empty( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : '';
$current_status  = ! empty( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
$current_search  = ! empty( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$date_from       = ! empty( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
$date_to         = ! empty( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
?>
<div class="wrap fc-wrap">
	<h1><?php esc_html_e( 'FormCatch — Submissions', 'formcatch' ); ?></h1>

	<form method="get" class="fc-filters">
		<input type="hidden" name="page" value="formcatch-submissions" />

		<select name="form_id">
			<option value=""><?php esc_html_e( 'All forms', 'formcatch' ); ?></option>
			<?php foreach ( $forms as $form ) : ?>
				<option value="<?php echo esc_attr( $form->id ); ?>" <?php selected( $current_form_id, $form->id ); ?>>
					<?php echo esc_html( $form->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value="all" <?php selected( $current_status, 'all' ); ?>><?php esc_html_e( 'All statuses', 'formcatch' ); ?></option>
			<option value="new" <?php selected( $current_status, 'new' ); ?>><?php esc_html_e( 'New', 'formcatch' ); ?></option>
			<option value="read" <?php selected( $current_status, 'read' ); ?>><?php esc_html_e( 'Read', 'formcatch' ); ?></option>
			<option value="spam" <?php selected( $current_status, 'spam' ); ?>><?php esc_html_e( 'Spam', 'formcatch' ); ?></option>
		</select>

		<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" title="<?php esc_attr_e( 'From date', 'formcatch' ); ?>" />
		<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" title="<?php esc_attr_e( 'To date', 'formcatch' ); ?>" />

		<input type="search" name="s" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search submission content…', 'formcatch' ); ?>" />

		<?php submit_button( __( 'Filter', 'formcatch' ), 'secondary', '', false ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=formcatch-submissions' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'formcatch' ); ?></a>

		<?php
		// Export carries the current filters so you get exactly what you're viewing.
		$export_url = add_query_arg(
			array(
				'action'    => 'fc_export_csv',
				'form_id'   => $current_form_id,
				'status'    => $current_status,
				'date_from' => $date_from,
				'date_to'   => $date_to,
				's'         => $current_search,
			),
			admin_url( 'admin-post.php' )
		);
		$export_url = wp_nonce_url( $export_url, 'fc_export_csv' );
		?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary"><?php esc_html_e( 'Export CSV', 'formcatch' ); ?></a>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="fc-submissions-form">
		<?php wp_nonce_field( 'fc_submission_action' ); ?>
		<input type="hidden" name="action" value="fc_submission_action" />
		<input type="hidden" name="do" value="" class="fc-bulk-do-field" />
		<?php $list_table->display(); ?>
	</form>
</div>
