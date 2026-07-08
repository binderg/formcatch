<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var FC_Forms_List_Table $list_table */
?>
<div class="wrap fc-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'FormCatch — Forms', 'formcatch' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=formcatch-forms&action=new' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add New', 'formcatch' ); ?>
	</a>
	<hr class="wp-header-end">

	<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form deleted.', 'formcatch' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['imported'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				/* translators: %d: number of forms imported */
				printf( esc_html( _n( '%d form imported.', '%d forms imported.', (int) $_GET['imported'], 'formcatch' ) ), (int) $_GET['imported'] );
				?>
			</p>
		</div>
	<?php elseif ( isset( $_GET['import_error'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Import failed: the JSON was invalid or contained no valid forms.', 'formcatch' ); ?></p></div>
	<?php endif; ?>

	<form method="get">
		<input type="hidden" name="page" value="formcatch-forms" />
		<?php $list_table->display(); ?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'Import / Export (JSON)', 'formcatch' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Paste one form object or an array of forms. This is the same JSON shape the REST API accepts — handy for scripting or AI-generated forms. Export a form from its row actions.', 'formcatch' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'fc_import_forms' ); ?>
		<input type="hidden" name="action" value="fc_import_forms" />
		<textarea name="fc_import_json" rows="6" class="large-text code" placeholder='{ "name": "Contact form", "status": "active", "settings": { "save_to_db": true, "notify_email": true, "emails": [ { "to": "you@example.com", "subject": "New: {{form_name}}", "body": "{{all_fields}}", "format": "plain" } ] } }'></textarea>
		<?php submit_button( __( 'Import forms', 'formcatch' ), 'secondary' ); ?>
	</form>
</div>
