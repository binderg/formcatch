<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles table creation and schema upgrades. Safe to call repeatedly —
 * dbDelta() only applies the diff, so this doubles as our migration runner.
 */
class FC_Activator {

	public static function activate() {
		self::create_tables();
		self::add_default_options();
		update_option( 'fc_db_version', FC_DB_VERSION );

		// Fetch the disposable-email blocklist shortly after activation.
		if ( ! wp_next_scheduled( 'fc_refresh_disposable' ) ) {
			wp_schedule_single_event( time() + 30, 'fc_refresh_disposable' );
		}
	}

	/**
	 * Create (or upgrade) the two FormCatch tables.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$forms_table      = FC_DB::forms_table();
		$submissions_table = FC_DB::submissions_table();
		$logs_table        = FC_DB::logs_table();
		$files_table       = FC_DB::files_table();

		$sql_forms = "CREATE TABLE {$forms_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			token VARCHAR(64) NOT NULL,
			settings LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status)
		) {$charset_collate};";

		$sql_submissions = "CREATE TABLE {$submissions_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			payload LONGTEXT NULL,
			ip VARCHAR(100) NULL,
			user_agent VARCHAR(255) NULL,
			referrer VARCHAR(500) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NULL,
			submission_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'success',
			message VARCHAR(500) NULL,
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY event_type (event_type),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_files = "CREATE TABLE {$files_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL,
			form_id BIGINT UNSIGNED NOT NULL,
			field_name VARCHAR(255) NULL,
			original_name VARCHAR(255) NULL,
			stored_path VARCHAR(500) NULL,
			mime VARCHAR(150) NULL,
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY form_id (form_id)
		) {$charset_collate};";

		dbDelta( $sql_forms );
		dbDelta( $sql_submissions );
		dbDelta( $sql_logs );
		dbDelta( $sql_files );

		// Ensure the protected upload directory exists with access guards.
		if ( class_exists( 'FC_Uploads' ) ) {
			FC_Uploads::ensure_protected_dir();
		}
	}

	/**
	 * Set sane plugin-wide defaults the first time it's activated.
	 */
	private static function add_default_options() {
		add_option( 'fc_delete_data_on_uninstall', false );
		add_option( 'fc_rate_limit_per_minute', 10 );
		add_option( 'fc_cors_origins', '' );
		add_option( 'fc_log_retention_days', 90 );
		add_option( 'fc_log_error_unseen', 0 );
		add_option( 'fc_max_payload_fields', 100 );
		add_option( 'fc_captcha_provider', 'none' );
		add_option( 'fc_captcha_site_key', '' );
		add_option( 'fc_captcha_secret_key', '' );
		add_option( 'fc_captcha_v3_threshold', 0.5 );
		add_option( 'fc_webhook_secret', '' );
		add_option( 'fc_submission_retention_days', 0 );
		add_option( 'fc_block_disposable_email', false );
		add_option( 'fc_upload_max_mb', 0 );
		add_option( 'fc_upload_allowed_ext', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip' );
		add_option( 'fc_upload_max_files', 10 );
		add_option( 'fc_file_url_secret', '' );
		add_option( 'fc_installed_version', FC_VERSION );
	}
}
