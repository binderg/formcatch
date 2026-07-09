<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var object $submission */
$back_url = admin_url( 'admin.php?page=formcatch-submissions' );
?>
<div class="wrap fc-wrap">
	<?php include __DIR__ . '/partials/header-banner.php'; ?>
	<h1><?php esc_html_e( 'Submission Detail', 'formcatch' ); ?></h1>
	<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to all submissions', 'formcatch' ); ?></a></p>

	<?php if ( ! empty( $_GET['resent'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Notifications resent. Check the Logs screen for delivery status.', 'formcatch' ); ?></p></div>
	<?php endif; ?>

	<table class="widefat fc-meta-table">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Form', 'formcatch' ); ?></th>
				<td><?php echo esc_html( $submission->form ? $submission->form->name : __( '(deleted form)', 'formcatch' ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Date', 'formcatch' ); ?></th>
				<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $submission->created_at ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Status', 'formcatch' ); ?></th>
				<td><span class="fc-badge fc-badge-<?php echo esc_attr( $submission->status ); ?>"><?php echo esc_html( ucfirst( $submission->status ) ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'IP Address', 'formcatch' ); ?></th>
				<td><?php echo esc_html( $submission->ip ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Referrer', 'formcatch' ); ?></th>
				<td><?php echo esc_html( $submission->referrer ? $submission->referrer : '—' ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'User Agent', 'formcatch' ); ?></th>
				<td><?php echo esc_html( $submission->user_agent ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Submitted Fields', 'formcatch' ); ?></h2>
	<table class="widefat striped fc-payload-table">
		<tbody>
			<?php if ( empty( $submission->payload_arr ) ) : ?>
				<tr><td><?php esc_html_e( '(empty submission)', 'formcatch' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $submission->payload_arr as $key => $value ) : ?>
					<tr>
						<th style="width:200px;"><?php echo esc_html( $key ); ?></th>
						<td><?php echo nl2br( esc_html( is_array( $value ) ? wp_json_encode( $value ) : $value ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php $fc_files = FC_Uploads::for_submission( $submission->id ); ?>
	<?php if ( ! empty( $fc_files ) ) : ?>
		<h2><?php esc_html_e( 'Attachments', 'formcatch' ); ?></h2>
		<table class="widefat striped fc-payload-table">
			<tbody>
				<?php foreach ( $fc_files as $fc_file ) : ?>
					<tr>
						<th style="width:200px;"><?php echo esc_html( $fc_file->field_name ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=fc_download_file&file=' . $fc_file->id ) ); ?>">
								<?php echo esc_html( $fc_file->original_name ); ?>
							</a>
							<span class="description">(<?php echo esc_html( size_format( $fc_file->size ) ); ?>)</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="fc-inline-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'fc_submission_action' ); ?>
			<input type="hidden" name="action" value="fc_submission_action" />
			<input type="hidden" name="submission_ids[]" value="<?php echo esc_attr( $submission->id ); ?>" />
			<button type="submit" name="do" value="spam" class="button"><?php esc_html_e( 'Mark as Spam', 'formcatch' ); ?></button>
			<button type="submit" name="do" value="delete" class="button" onclick="return confirm('<?php echo esc_js( __( 'Delete this submission permanently?', 'formcatch' ) ); ?>');"><?php esc_html_e( 'Delete', 'formcatch' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<?php wp_nonce_field( 'fc_resend_notifications' ); ?>
			<input type="hidden" name="action" value="fc_resend_notifications" />
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission->id ); ?>" />
			<button type="submit" class="button" title="<?php esc_attr_e( 'Re-fire this form\'s email and webhook notifications for this submission', 'formcatch' ); ?>"><?php esc_html_e( 'Resend notifications', 'formcatch' ); ?></button>
		</form>
	</div>
</div>
