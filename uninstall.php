<?php
/**
 * Fires only when the plugin is deleted from the Plugins screen (not on
 * simple deactivation). We only remove data if the site owner explicitly
 * opted in via Settings, so nobody loses submissions by accident.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$delete_data = get_option( 'fc_delete_data_on_uninstall', false );

if ( $delete_data ) {
	global $wpdb;

	$forms_table       = $wpdb->prefix . 'fc_forms';
	$submissions_table = $wpdb->prefix . 'fc_submissions';
	$logs_table        = $wpdb->prefix . 'fc_logs';
	$files_table       = $wpdb->prefix . 'fc_files';

	$wpdb->query( "DROP TABLE IF EXISTS {$submissions_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$forms_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$files_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Remove all stored upload files.
	$uploads = wp_upload_dir();
	$fc_dir  = trailingslashit( $uploads['basedir'] ) . 'formcatch';
	if ( is_dir( $fc_dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		WP_Filesystem();
		if ( $wp_filesystem ) {
			$wp_filesystem->rmdir( $fc_dir, true );
		}
	}

	delete_option( 'fc_delete_data_on_uninstall' );
	delete_option( 'fc_rate_limit_per_minute' );
	delete_option( 'fc_cors_origins' );
	delete_option( 'fc_log_retention_days' );
	delete_option( 'fc_log_error_unseen' );
	delete_option( 'fc_max_payload_fields' );
	delete_option( 'fc_captcha_provider' );
	delete_option( 'fc_captcha_site_key' );
	delete_option( 'fc_captcha_secret_key' );
	delete_option( 'fc_captcha_v3_threshold' );
	delete_option( 'fc_webhook_secret' );
	delete_option( 'fc_submission_retention_days' );
	delete_option( 'fc_block_disposable_email' );
	delete_option( 'fc_disposable_domains' );
	delete_option( 'fc_disposable_updated' );
	delete_option( 'fc_installed_version' );
	delete_option( 'fc_upload_max_mb' );
	delete_option( 'fc_upload_allowed_ext' );
	delete_option( 'fc_upload_max_files' );
	delete_option( 'fc_file_url_secret' );
	delete_option( 'fc_db_version' );

	wp_clear_scheduled_hook( 'fc_daily_prune_logs' );
	wp_clear_scheduled_hook( 'fc_refresh_disposable' );
}
