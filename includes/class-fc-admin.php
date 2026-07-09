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
		add_action( 'admin_notices', array( $this, 'maybe_show_smtp_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_upgrade_banner' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_upgrade_banner' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_smtp_notice' ) );

		add_action( 'admin_post_fc_save_form', array( $this, 'handle_save_form' ) );
		add_action( 'admin_post_fc_delete_form', array( $this, 'handle_delete_form' ) );
		add_action( 'admin_post_fc_toggle_form_status', array( $this, 'handle_toggle_form_status' ) );
		add_action( 'admin_post_fc_rotate_token', array( $this, 'handle_rotate_token' ) );
		add_action( 'admin_post_fc_export_form', array( $this, 'handle_export_form' ) );
		add_action( 'admin_post_fc_import_forms', array( $this, 'handle_import_forms' ) );
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
			$this->menu_icon(),
			30
		);

		add_submenu_page( 'formcatch-forms', __( 'Forms', 'formcatch' ), __( 'Forms', 'formcatch' ), $cap, 'formcatch-forms', array( $this, 'render_forms_page' ) );
		add_submenu_page( 'formcatch-forms', __( 'Submissions', 'formcatch' ), __( 'Submissions', 'formcatch' ), $cap, 'formcatch-submissions', array( $this, 'render_submissions_page' ) );
		add_submenu_page( 'formcatch-forms', __( 'Logs', 'formcatch' ), $this->logs_menu_title(), $cap, 'formcatch-logs', array( $this, 'render_logs_page' ) );
		add_submenu_page( 'formcatch-forms', __( 'Settings', 'formcatch' ), __( 'Settings', 'formcatch' ), $cap, 'formcatch-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * The top-level admin menu icon, as a base64 data URI. WordPress recolors
	 * data-URI SVG icons to match the admin color scheme, so the source file's
	 * own fill doesn't matter for how it renders in the sidebar. Falls back to
	 * a dashicon if the file is ever missing.
	 *
	 * @return string
	 */
	private function menu_icon() {
		$path = FC_PLUGIN_DIR . 'admin/img/menu-icon.svg';
		if ( ! file_exists( $path ) ) {
			return 'dashicons-feedback';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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

	/**
	 * Deliverability warning: when the site has no SMTP transport configured,
	 * WordPress falls back to PHP mail(), which most hosts send from an
	 * unauthenticated address (SPF/DKIM fail) — so form notifications land in
	 * spam or get their From rewritten. Nudge the admin to set up SMTP.
	 *
	 * Only shown when a form actually sends email, and dismissible per user.
	 */
	public function maybe_show_smtp_notice() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'formcatch' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'fc_smtp_notice_dismissed', true ) ) {
			return;
		}

		// Nothing to warn about if no form emails, or if SMTP is already set up.
		if ( ! $this->any_form_sends_email() || self::is_smtp_configured() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'fc_dismiss_smtp', '1' ),
			'fc_dismiss_smtp'
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s</p><p><a href="%s">%s</a> &nbsp;·&nbsp; <a href="%s">%s</a></p></div>',
			esc_html__( 'FormCatch: email deliverability at risk.', 'formcatch' ),
			esc_html__( 'No SMTP plugin is active, so notifications are sent with PHP mail(). On most hosts these fail SPF/DKIM checks and land in spam (or have their From address rewritten). Send through an authenticated SMTP service to fix it.', 'formcatch' ),
			esc_url( 'https://formcatch.dev/docs/guides/troubleshooting/#emails-land-in-spam' ),
			esc_html__( 'How to set up SMTP', 'formcatch' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'formcatch' )
		);
	}

	/**
	 * Best-effort detection of an active SMTP transport. There is no core flag
	 * for "SMTP is configured", so we look for the common SMTP plugins by their
	 * marker class/function. Sites with a custom phpmailer_init hook can signal
	 * it via the fc_smtp_configured filter.
	 *
	 * @return bool
	 */
	public static function is_smtp_configured() {
		$markers = array(
			'WPMailSMTP\\Core',        // WP Mail SMTP
			'EasyWPSMTP',              // Easy WP SMTP
			'PostmanOptions',          // Post SMTP
			'FluentMail\\App\\Application', // FluentSMTP
			'Mailgun',                 // Mailgun
			'WPSES_Plugin',            // WP Offload SES
		);
		foreach ( $markers as $marker ) {
			if ( class_exists( $marker ) ) {
				return (bool) apply_filters( 'fc_smtp_configured', true );
			}
		}
		if ( function_exists( 'gmail_smtp' ) || defined( 'WPSENDGRID_VERSION' ) ) {
			return (bool) apply_filters( 'fc_smtp_configured', true );
		}

		return (bool) apply_filters( 'fc_smtp_configured', false );
	}

	/**
	 * Whether at least one active form actually sends email notifications.
	 * Keeps the SMTP warning off sites that only store submissions.
	 *
	 * @return bool
	 */
	private function any_form_sends_email() {
		global $wpdb;
		$table = FC_DB::forms_table();

		// Cheap: a form sends mail only when notify_email is on in its settings
		// JSON. A LIKE is enough — false positives just mean the (already
		// dismissible) notice shows on a site that could send mail anyway.
		$hit = $wpdb->get_var( "SELECT 1 FROM {$table} WHERE status = 'active' AND settings LIKE '%\"notify_email\":true%' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $hit;
	}

	/**
	 * Persist dismissal of the SMTP deliverability notice for the current user.
	 */
	public function maybe_dismiss_smtp_notice() {
		if ( ! isset( $_GET['fc_dismiss_smtp'] ) ) {
			return;
		}
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			return;
		}
		check_admin_referer( 'fc_dismiss_smtp' );

		update_user_meta( get_current_user_id(), 'fc_smtp_notice_dismissed', 1 );

		wp_safe_redirect( remove_query_arg( array( 'fc_dismiss_smtp', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Free-tier upgrade banner shown on FormCatch admin screens. Dismissible
	 * per user (persisted in user meta). Never shown on a licensed Pro install.
	 */
	public function maybe_show_upgrade_banner() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			return;
		}

		// Pro users have already paid — no pitch.
		if ( FC_Helpers::is_pro() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'formcatch' ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), 'fc_upgrade_banner_dismissed', true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'fc_dismiss_upgrade', '1' ),
			'fc_dismiss_upgrade'
		);
		?>
		<div class="fc-upgrade-banner">
			<span class="fc-ub-icon"><span class="dashicons dashicons-star-filled"></span></span>
			<div class="fc-ub-body">
				<p class="fc-ub-title">
					<?php esc_html_e( 'Unlock FormCatch Pro', 'formcatch' ); ?>
					<span class="fc-ub-price"><?php esc_html_e( '$49 lifetime · unlimited sites', 'formcatch' ); ?></span>
				</p>
				<p class="fc-ub-text">
					<?php esc_html_e( 'Send submissions to webhooks (Slack, Zapier, and more) and read them over the REST API — plus all future updates. FormCatch stays free on this site; if it is useful to you, consider supporting the developer and getting a license.', 'formcatch' ); ?>
				</p>
			</div>
			<div class="fc-ub-actions">
				<a href="<?php echo esc_url( FC_Helpers::upgrade_url() ); ?>" class="fc-ub-cta"><?php esc_html_e( 'Get a lifetime license', 'formcatch' ); ?></a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="fc-ub-dismiss"><?php esc_html_e( 'Dismiss', 'formcatch' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Persist dismissal of the upgrade banner for the current user.
	 */
	public function maybe_dismiss_upgrade_banner() {
		if ( ! isset( $_GET['fc_dismiss_upgrade'] ) ) {
			return;
		}
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			return;
		}
		check_admin_referer( 'fc_dismiss_upgrade' );

		update_user_meta( get_current_user_id(), 'fc_upgrade_banner_dismissed', 1 );

		wp_safe_redirect( remove_query_arg( array( 'fc_dismiss_upgrade', '_wpnonce' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Form CRUD
	 * --------------------------------------------------------------- */

	public function handle_save_form() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_save_form' );

		$id   = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		$existing = $id ? FC_Forms::get( $id ) : null;
		if ( $id && ! $existing ) {
			wp_die( esc_html__( 'Form not found.', 'formcatch' ) );
		}

		// Full unslashed POST → shared sanitizer (same path as the REST API).
		$input    = wp_unslash( $_POST );
		$settings = FC_Forms::sanitize_settings( $input, $existing ? $existing->settings_arr : null, false );
		$status   = ! empty( $_POST['status'] ) ? 'active' : 'disabled';

		if ( $id ) {
			FC_Forms::update( $id, $name, $settings, $status );
		} else {
			$id = FC_Forms::create( $name, $settings, $status );
		}

		// Requiring CAPTCHA without global keys is allowed to save from the UI
		// (so you can configure it in either order), but flag it so it's not a
		// silent no-op.
		$warn = ( ! empty( $settings['require_captcha'] ) && ! FC_Captcha::is_configured() ) ? '&captcha_unconfigured=1' : '';

		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-forms&action=edit&form_id=' . $id . '&saved=1' . $warn ) );
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
					'token'      => FC_Helpers::generate_unique_token(),
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

	/**
	 * Download a single form's portable JSON (config only).
	 */
	public function handle_export_form() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_export_form' );

		$id   = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$form = $id ? FC_Forms::get( $id ) : null;
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'formcatch' ) );
		}

		$slug     = sanitize_title( $form->name ? $form->name : 'form' );
		$filename = 'formcatch-' . $slug . '-' . $id . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo wp_json_encode( FC_Forms::to_export_array( $form ), JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Import forms from pasted JSON (single object or array). Uses the same
	 * shared validation as the REST API.
	 */
	public function handle_import_forms() {
		if ( ! current_user_can( FC_Helpers::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'formcatch' ) );
		}
		check_admin_referer( 'fc_import_forms' );

		$raw    = isset( $_POST['fc_import_json'] ) ? wp_unslash( $_POST['fc_import_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed = json_decode( $raw, true );

		if ( null === $parsed || ! is_array( $parsed ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=formcatch-forms&import_error=1' ) );
			exit;
		}

		$items = ( isset( $parsed['name'] ) || isset( $parsed['settings'] ) ) ? array( $parsed ) : $parsed;

		$count = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name     = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
			$status   = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : 'active';
			$in       = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : array();
			$settings = FC_Forms::sanitize_settings( $in, null, false );

			if ( is_wp_error( FC_Forms::validate_settings( $settings ) ) ) {
				continue; // Skip invalid; keep importing the rest.
			}

			FC_Forms::create( $name, $settings, $status );
			$count++;
		}

		$arg = $count > 0 ? 'imported=' . $count : 'import_error=1';
		wp_safe_redirect( admin_url( 'admin.php?page=formcatch-forms&' . $arg ) );
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
