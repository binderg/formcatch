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

	<form method="get">
		<input type="hidden" name="page" value="formcatch-forms" />
		<?php $list_table->display(); ?>
	</form>
</div>
