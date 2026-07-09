<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * @var FC_Logs_List_Table $list_table
 * @var array              $forms
 */
$current_form_id = ! empty( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : '';
$current_type    = ! empty( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : 'all';
$current_status  = ! empty( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
$current_search  = ! empty( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
?>
<div class="wrap fc-wrap">
	<?php include __DIR__ . '/partials/header-banner.php'; ?>
	<h1><?php esc_html_e( 'FormCatch — Logs', 'formcatch' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Every submission received, email sent, and webhook fired is recorded here.', 'formcatch' ); ?></p>

	<form method="get" class="fc-filters">
		<input type="hidden" name="page" value="formcatch-logs" />

		<select name="event_type">
			<option value="all" <?php selected( $current_type, 'all' ); ?>><?php esc_html_e( 'All event types', 'formcatch' ); ?></option>
			<option value="submission" <?php selected( $current_type, 'submission' ); ?>><?php esc_html_e( 'Submission', 'formcatch' ); ?></option>
			<option value="email" <?php selected( $current_type, 'email' ); ?>><?php esc_html_e( 'Email', 'formcatch' ); ?></option>
			<option value="webhook" <?php selected( $current_type, 'webhook' ); ?>><?php esc_html_e( 'Webhook', 'formcatch' ); ?></option>
		</select>

		<select name="status">
			<option value="all" <?php selected( $current_status, 'all' ); ?>><?php esc_html_e( 'All statuses', 'formcatch' ); ?></option>
			<option value="success" <?php selected( $current_status, 'success' ); ?>><?php esc_html_e( 'Success', 'formcatch' ); ?></option>
			<option value="error" <?php selected( $current_status, 'error' ); ?>><?php esc_html_e( 'Error', 'formcatch' ); ?></option>
			<option value="skipped" <?php selected( $current_status, 'skipped' ); ?>><?php esc_html_e( 'Skipped', 'formcatch' ); ?></option>
		</select>

		<select name="form_id">
			<option value=""><?php esc_html_e( 'All forms', 'formcatch' ); ?></option>
			<?php foreach ( $forms as $form ) : ?>
				<option value="<?php echo esc_attr( $form->id ); ?>" <?php selected( $current_form_id, $form->id ); ?>>
					<?php echo esc_html( $form->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input type="search" name="s" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search events…', 'formcatch' ); ?>" />

		<?php submit_button( __( 'Filter', 'formcatch' ), 'secondary', '', false ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=formcatch-logs' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'formcatch' ); ?></a>
	</form>

	<?php $list_table->display(); ?>
</div>
