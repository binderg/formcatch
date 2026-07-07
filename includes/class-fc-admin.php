<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once FC_PLUGIN_DIR . 'includes/class-fc-forms-list-table.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-submissions-list-table.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-logs-list-table.php';

/**
 * Wires up the wp-admin UI: menu pages, form save/delete handlers,
 * submission status/delete handlers, and the settings screen.
 */
class FC_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_error_notice' ) );

		add_action( 'admin_post_fc_save_form', array( $this, 'handle_save_form' ) );
		add_action( 'admin_post_fc_delete_form', array( $this, 'handle_delete_form' ) );
		add_action( 'admin_post_fc_toggle_form_status', array( $this, 'handle_toggle_form_status' ) );
		add_action( 'admin_post_fc_rotate_token', array( $this, 'handle_rotate_token' ) );
		add_action( 'admin_post_fc_submission_action', array( $this, 'handle_submission_action' ) );
		add_action( 'admin_post_fc_resend_notifications', array( $this, 'handle_resend_notifications' ) );
		add_action( 'admin_post_fc_export_csv', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_fc_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_fc_refresh_disposable', array( $this, 'handle_refresh_disposable' ) );
		// File downloads: available to logged-in managers, and to anyone with a
		// valid signed URL (so emailed links work). Both hooks needed because
		// nopriv covers logged-out signed-link clicks.
		add_action( 'admin_post_fc_download_file', array( $this, 'handle_download_file' ) );
		add_action( 'admin_post_nopriv_fc_download_file', array( $this, 'handle_download_file' ) );
	}

	public function register_menu() {
		$cap = FC_Helpers::capability();

		add_menu_page(
			__( 'FormCatch', 'formcatch' ),
			__( 'FormCatch', 'formcatch' ),
			$cap,
			'formcatch-forms',
			array( $this, 'render_forms_page' ),
			'dashicons-feedback',
			30
		);

		add_submenu_page( 'formcatch-forms', __( 'Forms', 'formcatch' ), __( 'Forms', 'formcatch' ), $cap, 'formcatch-forms', array( $this, 'render_forms_page' ) );
		add_submenu_page( 'formcatch-forms', __( 'Submissions', 'formcatch' ), __( 'Submissions', 'formcatch' ), $cap, 'formcatch-submissions', array( $this, 'render_submissions_page' ) );
		add_submenu_page( 'formcatch-forms', __( 'Logs', 'formcatch' ), $this->logs_menu_title(), $cap, 'formcatch-logs', array( $this, 'render_logs_page' ) );
		add_submenu_page( 'formcatch-forms', __( 'Settings', 'formcatch' ), __( 'Settings', 'formcatch' ), $cap, 'formcatch-settings', array( $this, 'render_settings_page' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'formcatch' ) ) {
			return;
		}
		wp_enqueue_style( 'fc-admin', FC_PLUGIN_URL . 'admin/css/admin.css', array(), FC_VERSION );
		wp_enqueue_script( 'fc-admin', FC_PLUGIN_URL . 'admin/js/admin.js', array(), FC_VERSION, true );

		// Pass translatable strings to JS (JS text isn't reachable by the
		// PHP __() translation system otherwise).
		wp_localize_script(
			'fc-admin',
			'fcAdmin',
			array(
				'i18n' => array(
					'copied' => __( 'Copied!', 'formcatch' ),
				),
			)
		);
	}

	/* -----------------------------------------------------------------
	 * Page renderers
	 * --------------------------------------------------------------- */

	public function render_forms_page() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'formcatch' ) );
		}

		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		if ( 'edit' === $action || 'new' === $action ) {
			$form = $form_id ? $this->get_form( $form_id ) : null;
			include FC_PLUGIN_DIR . 'admin/views/form-edit.php';
			return;
		}

		$list_table = new FC_Forms_List_Table();
		$list_table->prepare_items();
		include FC_PLUGIN_DIR . 'admin/views/forms-list.php';
	}

	public function render_submissions_page() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'formcatch' ) );
		}

		$view          = isset( $_GET['view'] ) ? absint( $_GET['view'] ) : 0;
		$submission    = $view ? $this->get_submission( $view ) : null;

		if ( $submission ) {
			include FC_PLUGIN_DIR . 'admin/views/submission-view.php';
			return;
		}

		$list_table = new FC_Submissions_List_Table();
		$list_table->prepare_items();
		$forms = $this->get_all_forms();
		include FC_PLUGIN_DIR . 'admin/views/submissions-list.php';
	}

	public function render_logs_page() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'formcatch' ) );
		}

		// Viewing the log clears the "unseen errors" badge/notice.
		FC_Logger::clear_unseen_errors();

		$list_table = new FC_Logs_List_Table();
		$list_table->prepare_items();
		$forms = $this->get_all_forms();
		include FC_PLUGIN_DIR . 'admin/views/logs-list.php';
	}

	public function render_settings_page() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'formcatch' ) );
		}
		include FC_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Menu title for Logs, with a red bubble showing the unseen-error count
	 * (mirrors how WordPress shows pending-comment / update counts).
	 *
	 * @return string
	 */
	private function logs_menu_title() {
		$count = (int) get_option( 'fc_log_error_unseen', 0 );
		$title = __( 'Logs', 'formcatch' );

		if ( $count > 0 ) {
			$title .= ' <span class="awaiting-mod update-plugins count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>';
		}

		return $title;
	}

	/**
	 * Show a dismissible admin notice on FormCatch screens when there are
	 * unseen error events, linking to the filtered log view.
	 */
	public function maybe_show_error_notice() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'formcatch' ) ) {
			return;
		}

		// Don't nag on the Logs page itself — visiting it clears the count.
		if ( isset( $_GET['page'] ) && 'formcatch-logs' === $_GET['page'] ) {
			return;
		}

		$count = (int) get_option( 'fc_log_error_unseen', 0 );
		if ( $count < 1 ) {
			return;
		}

		$url = admin_url( 'admin.php?page=formcatch-logs&status=error' );
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of failed events */
					_n( 'FormCatch recorded %d failed notification.', 'FormCatch recorded %d failed notifications.', $count, 'formcatch' ),
					$count
				)
			),
			esc_url( $url ),
			esc_html__( 'View error log', 'formcatch' )
		);
	}

	/* -----------------------------------------------------------------
	 * Form CRUD
	 * --------------------------------------------------------------- */

	public function handle_save_form() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_save_form' );

		global $wpdb;
		$table = FC_DB::forms_table();

		$id   = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			$name = __( 'Untitled form', 'formcatch' );
		}

		$settings = array(
			'save_to_db'     => ! empty( $_POST['save_to_db'] ),
			'notify_email'   => ! empty( $_POST['notify_email'] ),
			'emails'         => $this->sanitize_emails( isset( $_POST['emails'] ) ? wp_unslash( $_POST['emails'] ) : array() ),
			'email_from_name'    => isset( $_POST['email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['email_from_name'] ) ) : '',
			'email_from_address' => isset( $_POST['email_from_address'] ) ? sanitize_email( wp_unslash( $_POST['email_from_address'] ) ) : '',
			'redirect_url'   => isset( $_POST['redirect_url'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_url'] ) ) : '',
			'honeypot_field' => isset( $_POST['honeypot_field'] ) ? sanitize_key( wp_unslash( $_POST['honeypot_field'] ) ) : '',
			'webhook_url'    => isset( $_POST['webhook_url'] ) ? $this->sanitize_webhook_urls( wp_unslash( $_POST['webhook_url'] ) ) : '',
			'notify_on_spam' => ! empty( $_POST['notify_on_spam'] ),
			'require_captcha' => ! empty( $_POST['require_captcha'] ),
			'captcha_v3_badge' => isset( $_POST['captcha_v3_badge'] ) ? FC_Captcha::normalize_badge( sanitize_key( wp_unslash( $_POST['captcha_v3_badge'] ) ) ) : 'bottomright',
			'allowed_domains' => isset( $_POST['allowed_domains'] ) ? $this->sanitize_domains( wp_unslash( $_POST['allowed_domains'] ) ) : '',
			'allow_uploads'   => ! empty( $_POST['allow_uploads'] ),
		);

		$status = ! empty( $_POST['status'] ) ? 'active' : 'disabled';

		if ( $id ) {
			$existing = $this->get_form( $id );
			if ( ! $existing ) {
				wp_die( esc_html__( 'Form not found.', 'formcatch' ) );
			}
			if ( empty( $settings['honeypot_field'] ) ) {
				$settings['honeypot_field'] = $existing->settings_arr['honeypot_field'];
			}
			$wpdb->update(
				$table,
				array(
					'name'       => $name,
					'settings'   => wp_json_encode( $settings ),
					'status'     => $status,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			if ( empty( $settings['honeypot_field'] ) ) {
				$settings['honeypot_field'] = FC_Helpers::generate_honeypot_field();
			}
			$wpdb->insert(
				$table,
				array(
					'name'       => $name,
					'token'      => FC_Helpers::generate_token(),
					'settings'   => wp_json_encode( $settings ),
					'status'     => $status,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-forms&action=edit&form_id=' . $id . '&saved=1' ) );
		exit;
	}

	/**
	 * Clean up a textarea of webhook URLs (one per line, commas also
	 * accepted) into a newline-separated string of valid URLs only.
	 *
	 * @param string $raw
	 * @return string
	 */
	/**
	 * Sanitize the repeatable emails[] POST array into a clean list of
	 * to/subject/body/format rules. Blank rows (no recipient) are dropped.
	 *
	 * @param array $raw
	 * @return array
	 */
	private function sanitize_emails( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$emails = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$to = isset( $row['to'] ) ? sanitize_text_field( $row['to'] ) : '';
			if ( '' === trim( $to ) ) {
				continue; // Skip empty rows.
			}

			$format = ( isset( $row['format'] ) && 'html' === $row['format'] ) ? 'html' : 'plain';
			$body   = isset( $row['body'] ) ? $row['body'] : '';
			// Allow safe HTML for html format; strip tags for plain.
			$body   = 'html' === $format ? wp_kses_post( $body ) : sanitize_textarea_field( $body );

			$emails[] = array(
				'to'             => $to,
				'subject'        => isset( $row['subject'] ) ? sanitize_text_field( $row['subject'] ) : '',
				'body'           => $body,
				'format'         => $format,
				'reply_to_field' => isset( $row['reply_to_field'] ) ? sanitize_text_field( $row['reply_to_field'] ) : '',
			);
		}

		return $emails;
	}

	/**
	 * Clean the CORS origins textarea into a newline-separated list. Keeps
	 * "*" as-is; otherwise validates each as a URL origin.
	 *
	 * @param string $raw
	 * @return string
	 */
	private function sanitize_cors_origins( $raw ) {
		$raw   = str_replace( ',', "\n", (string) $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		$clean = array();
		foreach ( $lines as $line ) {
			if ( '*' === $line ) {
				$clean[] = '*';
				continue;
			}
			$url = esc_url_raw( $line );
			if ( $url ) {
				// Reduce to scheme://host[:port] — an Origin never has a path.
				$parts = wp_parse_url( $url );
				if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
					$origin  = $parts['scheme'] . '://' . $parts['host'];
					$origin .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
					$clean[] = $origin;
				}
			}
		}

		return implode( "\n", array_unique( $clean ) );
	}

	/**
	 * Clean a textarea of allowed submission domains into a newline list of
	 * bare hostnames (one per line). Accepts full URLs or hostnames; a leading
	 * "www." is kept as typed (matching is host-suffix aware at check time).
	 *
	 * @param string $raw
	 * @return string
	 */
	private function sanitize_domains( $raw ) {
		$raw   = str_replace( ',', "\n", (string) $raw );
		$lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		$hosts = array();
		foreach ( $lines as $line ) {
			// Allow entries with or without a scheme.
			$host = wp_parse_url( false !== strpos( $line, '//' ) ? $line : 'https://' . $line, PHP_URL_HOST );
			$host = $host ? strtolower( $host ) : strtolower( preg_replace( '#^www\.#', '', $line ) );
			$host = sanitize_text_field( $host );
			if ( $host ) {
				$hosts[] = $host;
			}
		}

		return implode( "\n", array_unique( $hosts ) );
	}

	private function sanitize_webhook_urls( $raw ) {
		$raw  = str_replace( ',', "\n", (string) $raw );
		$urls = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$urls = array_filter( array_map( 'esc_url_raw', $urls ) );

		return implode( "\n", $urls );
	}

	/**
	 * Quick enable/disable toggle from the forms list. A disabled form's
	 * endpoint returns 403 (see FC_REST_Controller::handle_submit).
	 */
	public function handle_toggle_form_status() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_toggle_form_status' );

		global $wpdb;
		$id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		if ( $id ) {
			$form = $this->get_form( $id );
			if ( $form ) {
				$new_status = ( 'active' === $form->status ) ? 'disabled' : 'active';
				$wpdb->update(
					FC_DB::forms_table(),
					array(
						'status'     => $new_status,
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=formcatch-forms' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Regenerate a form's endpoint token. Invalidates the old submit URL
	 * immediately — used if a token leaks or you want to cut off abuse.
	 */
	public function handle_rotate_token() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_rotate_token' );

		global $wpdb;
		$id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		if ( $id ) {
			$wpdb->update(
				FC_DB::forms_table(),
				array(
					'token'      => FC_Helpers::generate_token(),
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-forms&action=edit&form_id=' . $id . '&saved=1' ) );
		exit;
	}

	public function handle_delete_form() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_delete_form' );

		global $wpdb;
		$id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		if ( $id ) {
			$wpdb->delete( FC_DB::forms_table(), array( 'id' => $id ), array( '%d' ) );
			// Note: submissions for this form are intentionally kept
			// (orphaned but viewable) unless the user deletes them
			// explicitly — avoids silent data loss.
		}

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-forms&deleted=1' ) );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Submission actions
	 * --------------------------------------------------------------- */

	public function handle_submission_action() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_submission_action' );

		global $wpdb;
		$table = FC_DB::submissions_table();

		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids = isset( $_POST['submission_ids'] ) ? array_map( 'absint', (array) $_POST['submission_ids'] ) : array();
		$ids = array_filter( $ids );

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			if ( 'delete' === $do ) {
				FC_Uploads::delete_for_submissions( $ids ); // Remove any attached files from disk first.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} elseif ( in_array( $do, array( 'read', 'spam', 'new' ), true ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})", array_merge( array( $do ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=formcatch-submissions' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Re-fire a submission's email + webhook notifications on demand — for
	 * when an email bounced or a webhook endpoint was down at submit time.
	 */
	public function handle_resend_notifications() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_resend_notifications' );

		$id         = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
		$submission = $id ? $this->get_submission( $id ) : null;

		if ( $submission && $submission->form ) {
			$form     = $submission->form;
			$payload  = is_array( $submission->payload_arr ) ? $submission->payload_arr : array();
			$settings = $form->settings_arr;

			if ( ! empty( $settings['notify_email'] ) ) {
				FC_Mailer::send_notification( $form, $payload, $submission->id );
			}
			if ( ! empty( $settings['webhook_url'] ) ) {
				FC_Webhook::send( $form, $payload, $submission->id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-submissions&view=' . $id . '&resent=1' ) );
		exit;
	}

	/* -----------------------------------------------------------------
	 * CSV export
	 * --------------------------------------------------------------- */

	/**
	 * Stream matching submissions as a CSV download. Honors the same
	 * form/status/date/search filters as the submissions list so "export
	 * what I'm looking at" works.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_export_csv' );

		global $wpdb;
		$table = FC_DB::submissions_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $_GET['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = absint( $_GET['form_id'] );
		}
		if ( ! empty( $_GET['status'] ) && 'all' !== $_GET['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( wp_unslash( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) . ' 00:00:00';
		}
		if ( ! empty( $_GET['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) . ' 23:59:59';
		}
		if ( ! empty( $_GET['s'] ) ) {
			$where[]  = 'payload LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC";
		$rows      = empty( $params )
			? $wpdb->get_results( $sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Union of all field keys across the result set = CSV columns. Keeps
		// the export sensible even though submissions are schema-less.
		$field_keys = array();
		$decoded    = array();
		foreach ( $rows as $row ) {
			$data      = json_decode( $row->payload, true );
			$data      = is_array( $data ) ? $data : array();
			$decoded[] = $data;
			foreach ( array_keys( $data ) as $k ) {
				$field_keys[ $k ] = true;
			}
		}
		$field_keys = array_keys( $field_keys );

		$filename = 'formcatch-submissions-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// Meta columns first, then one column per submitted field, then a
		// trailing column of signed download links for any attachments.
		$header = array_merge( array( 'id', 'form_id', 'status', 'ip', 'created_at' ), $field_keys, array( 'attachments' ) );
		fputcsv( $out, $header );

		foreach ( $rows as $i => $row ) {
			$data = $decoded[ $i ];
			$line = array( $row->id, $row->form_id, $row->status, $row->ip, $row->created_at );
			foreach ( $field_keys as $k ) {
				$val = isset( $data[ $k ] ) ? $data[ $k ] : '';
				if ( is_array( $val ) ) {
					$val = wp_json_encode( $val );
				}
				$line[] = $val;
			}

			$links = array();
			foreach ( FC_Uploads::for_submission( $row->id ) as $file ) {
				$links[] = $file->original_name . ' ' . FC_Uploads::signed_url( $file->id );
			}
			$line[] = implode( ' | ', $links );

			fputcsv( $out, $line );
		}

		fclose( $out );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Settings
	 * --------------------------------------------------------------- */

	/**
	 * Manually re-fetch the disposable-email blocklist from Settings.
	 */
	public function handle_refresh_disposable() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_refresh_disposable' );

		$result = FC_Disposable::refresh();
		$arg    = is_wp_error( $result ) ? 'disposable_error' : 'disposable_refreshed';

		if ( is_wp_error( $result ) ) {
			FC_Logger::log(
				FC_Logger::TYPE_SUBMISSION,
				FC_Logger::STATUS_ERROR,
				__( 'Disposable blocklist refresh failed.', 'formcatch' ),
				array( 'error' => $result->get_error_message() )
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-settings&' . $arg . '=1' ) );
		exit;
	}

	/**
	 * Serve a stored upload. Authorized either by a valid signed URL (for
	 * emailed links, no login needed) or by the logged-in manage capability.
	 */
	public function handle_download_file() {
		$file_id = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : 0;
		$file    = $file_id ? FC_Uploads::get( $file_id ) : null;

		if ( ! $file ) {
			status_header( 404 );
			wp_die( esc_html__( 'File not found.', 'formcatch' ), '', array( 'response' => 404 ) );
		}

		$authorized = false;

		// Signed URL path (works from an email without a session).
		if ( isset( $_GET['expires'], $_GET['sig'] ) ) {
			$authorized = FC_Uploads::verify_signature(
				$file_id,
				absint( $_GET['expires'] ),
				sanitize_text_field( wp_unslash( $_GET['sig'] ) )
			);
		}

		// Otherwise fall back to a logged-in manager.
		if ( ! $authorized && current_user_can( FC_Helpers::capability() ) ) {
			$authorized = true;
		}

		if ( ! $authorized ) {
			status_header( 403 );
			wp_die( esc_html__( 'This download link is invalid or has expired.', 'formcatch' ), '', array( 'response' => 403 ) );
		}

		FC_Uploads::stream( $file );

		// If stream() returned, the file was missing on disk.
		status_header( 404 );
		wp_die( esc_html__( 'File is no longer available.', 'formcatch' ), '', array( 'response' => 404 ) );
	}

	public function handle_save_settings() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_save_settings' );

		update_option( 'fc_delete_data_on_uninstall', ! empty( $_POST['fc_delete_data_on_uninstall'] ) );

		$rate_limit = isset( $_POST['fc_rate_limit_per_minute'] ) ? absint( $_POST['fc_rate_limit_per_minute'] ) : 10;
		update_option( 'fc_rate_limit_per_minute', $rate_limit );

		$max_fields = isset( $_POST['fc_max_payload_fields'] ) ? absint( $_POST['fc_max_payload_fields'] ) : 100;
		update_option( 'fc_max_payload_fields', $max_fields );

		$retention = isset( $_POST['fc_log_retention_days'] ) ? absint( $_POST['fc_log_retention_days'] ) : 90;
		update_option( 'fc_log_retention_days', $retention );

		$sub_retention = isset( $_POST['fc_submission_retention_days'] ) ? absint( $_POST['fc_submission_retention_days'] ) : 0;
		update_option( 'fc_submission_retention_days', $sub_retention );

		update_option( 'fc_block_disposable_email', ! empty( $_POST['fc_block_disposable_email'] ) );

		update_option( 'fc_upload_max_mb', isset( $_POST['fc_upload_max_mb'] ) ? absint( $_POST['fc_upload_max_mb'] ) : 0 );
		update_option( 'fc_upload_max_files', isset( $_POST['fc_upload_max_files'] ) ? max( 1, absint( $_POST['fc_upload_max_files'] ) ) : 10 );
		if ( isset( $_POST['fc_upload_allowed_ext'] ) ) {
			$ext = sanitize_text_field( wp_unslash( $_POST['fc_upload_allowed_ext'] ) );
			update_option( 'fc_upload_allowed_ext', $ext );
		}


		update_option( 'fc_cors_origins', $this->sanitize_cors_origins( isset( $_POST['fc_cors_origins'] ) ? wp_unslash( $_POST['fc_cors_origins'] ) : '' ) );

		update_option( 'fc_webhook_secret', isset( $_POST['fc_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_webhook_secret'] ) ) : '' );

		$providers = array( 'none', 'turnstile', 'hcaptcha', 'recaptcha_v2', 'recaptcha_v3' );
		$provider  = isset( $_POST['fc_captcha_provider'] ) ? sanitize_key( wp_unslash( $_POST['fc_captcha_provider'] ) ) : 'none';
		update_option( 'fc_captcha_provider', in_array( $provider, $providers, true ) ? $provider : 'none' );

		update_option( 'fc_captcha_site_key', isset( $_POST['fc_captcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_captcha_site_key'] ) ) : '' );
		update_option( 'fc_captcha_secret_key', isset( $_POST['fc_captcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_captcha_secret_key'] ) ) : '' );

		$threshold = isset( $_POST['fc_captcha_v3_threshold'] ) ? (float) $_POST['fc_captcha_v3_threshold'] : 0.5;
		$threshold = max( 0, min( 1, $threshold ) );
		update_option( 'fc_captcha_v3_threshold', $threshold );

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-settings&saved=1' ) );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Data getters (shared by views)
	 * --------------------------------------------------------------- */

	public function get_form( $id ) {
		global $wpdb;
		$table = FC_DB::forms_table();
		$form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $form ) {
			$form->settings_arr = FC_Helpers::merge_settings( json_decode( $form->settings, true ) );
		}

		return $form;
	}

	public function get_all_forms() {
		global $wpdb;
		$table = FC_DB::forms_table();
		return $wpdb->get_results( "SELECT id, name, token, status FROM {$table} ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function get_submission( $id ) {
		global $wpdb;
		$table = FC_DB::submissions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $row ) {
			$row->payload_arr = json_decode( $row->payload, true );
			if ( ! is_array( $row->payload_arr ) ) {
				$row->payload_arr = array();
			}
			$row->form = $this->get_form( $row->form_id );

			// Auto-mark as read the first time it's opened.
			if ( 'new' === $row->status ) {
				$wpdb->update( $table, array( 'status' => 'read' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
				$row->status = 'read';
			}
		}

		return $row;
	}
}
