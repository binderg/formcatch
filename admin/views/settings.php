<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$delete_on_uninstall = get_option( 'fc_delete_data_on_uninstall', false );
$rate_limit           = get_option( 'fc_rate_limit_per_minute', 10 );
$cors_origins         = get_option( 'fc_cors_origins', '' );
$log_retention        = get_option( 'fc_log_retention_days', 90 );
$max_fields           = get_option( 'fc_max_payload_fields', 100 );
$captcha_provider     = get_option( 'fc_captcha_provider', 'none' );
$captcha_site_key     = get_option( 'fc_captcha_site_key', '' );
$captcha_secret_key   = get_option( 'fc_captcha_secret_key', '' );
$captcha_v3_threshold = get_option( 'fc_captcha_v3_threshold', 0.5 );
$webhook_secret       = get_option( 'fc_webhook_secret', '' );
$submission_retention = get_option( 'fc_submission_retention_days', 0 );
$block_disposable     = get_option( 'fc_block_disposable_email', false );
$disposable_count     = FC_Disposable::count();
$disposable_updated   = FC_Disposable::last_updated();
$upload_max_mb        = get_option( 'fc_upload_max_mb', 0 );
$upload_allowed_ext   = get_option( 'fc_upload_allowed_ext', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip' );
$upload_max_files     = get_option( 'fc_upload_max_files', 10 );
$server_limit_bytes   = wp_max_upload_size();
?>
<div class="wrap fc-wrap">
	<?php include __DIR__ . '/partials/header-banner.php'; ?>
	<h1><?php esc_html_e( 'FormCatch — Settings', 'formcatch' ); ?></h1>

	<?php if ( ! empty( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'formcatch' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'fc_save_settings' ); ?>
		<input type="hidden" name="action" value="fc_save_settings" />

		<h2><?php esc_html_e( 'Submissions', 'formcatch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fc-rate-limit"><?php esc_html_e( 'Rate limit', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-rate-limit" name="fc_rate_limit_per_minute" min="0" value="<?php echo esc_attr( $rate_limit ); ?>" class="small-text" />
					<?php esc_html_e( 'submissions per IP, per form, per minute (0 = disabled)', 'formcatch' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fc-max-fields"><?php esc_html_e( 'Max fields per submission', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-max-fields" name="fc_max_payload_fields" min="0" value="<?php echo esc_attr( $max_fields ); ?>" class="small-text" />
					<?php esc_html_e( 'fields (0 = no limit). Submissions with more fields are rejected to prevent database bloat.', 'formcatch' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fc-log-retention"><?php esc_html_e( 'Log retention', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-log-retention" name="fc_log_retention_days" min="0" value="<?php echo esc_attr( $log_retention ); ?>" class="small-text" />
					<?php esc_html_e( 'days to keep log events before automatic deletion (0 = keep forever)', 'formcatch' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fc-sub-retention"><?php esc_html_e( 'Submission retention', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-sub-retention" name="fc_submission_retention_days" min="0" value="<?php echo esc_attr( $submission_retention ); ?>" class="small-text" />
					<?php esc_html_e( 'days to keep submissions before automatic deletion (0 = keep forever)', 'formcatch' ); ?>
					<p class="description"><?php esc_html_e( 'Off by default. Useful for GDPR/data-minimization — but note this permanently deletes stored submissions older than the window.', 'formcatch' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'CORS & Webhooks', 'formcatch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fc-cors"><?php esc_html_e( 'Allowed origins (CORS)', 'formcatch' ); ?></label></th>
				<td>
					<textarea id="fc-cors" name="fc_cors_origins" rows="3" class="large-text code" placeholder="https://example.com&#10;https://app.example.com"><?php echo esc_textarea( $cors_origins ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One origin per line, needed only for cross-domain fetch()/AJAX submissions from a different site. Use * to allow any origin. Leave blank to disable CORS (same-origin submissions always work).', 'formcatch' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fc-webhook-secret"><?php esc_html_e( 'Webhook signing secret', 'formcatch' ); ?></label></th>
				<td>
					<input type="text" id="fc-webhook-secret" name="fc_webhook_secret" class="regular-text code" value="<?php echo esc_attr( $webhook_secret ); ?>" autocomplete="off" />
					<p class="description">
						<?php esc_html_e( 'Optional. When set, each webhook request includes an X-FormCatch-Signature header: "t=<timestamp>,v1=<hmac>", where the HMAC-SHA256 is computed over "<timestamp>.<raw JSON body>" with this secret. Receivers recompute it to verify authenticity and reject replays. Leave blank to send unsigned.', 'formcatch' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'CAPTCHA', 'formcatch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fc-captcha-provider"><?php esc_html_e( 'Provider', 'formcatch' ); ?></label></th>
				<td>
					<select id="fc-captcha-provider" name="fc_captcha_provider">
						<option value="none" <?php selected( $captcha_provider, 'none' ); ?>><?php esc_html_e( 'None', 'formcatch' ); ?></option>
						<option value="turnstile" <?php selected( $captcha_provider, 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile', 'formcatch' ); ?></option>
						<option value="hcaptcha" <?php selected( $captcha_provider, 'hcaptcha' ); ?>><?php esc_html_e( 'hCaptcha', 'formcatch' ); ?></option>
						<option value="recaptcha_v2" <?php selected( $captcha_provider, 'recaptcha_v2' ); ?>><?php esc_html_e( 'Google reCAPTCHA v2 (checkbox)', 'formcatch' ); ?></option>
						<option value="recaptcha_v3" <?php selected( $captcha_provider, 'recaptcha_v3' ); ?>><?php esc_html_e( 'Google reCAPTCHA v3 (invisible)', 'formcatch' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Configure keys once here, then enable CAPTCHA per form on its Spam tab. FormCatch shows you the widget markup to paste into that form.', 'formcatch' ); ?></p>
				</td>
			</tr>
			<tr class="fc-captcha-keys">
				<th scope="row"><label for="fc-captcha-site-key"><?php esc_html_e( 'Site key', 'formcatch' ); ?></label></th>
				<td>
					<input type="text" id="fc-captcha-site-key" name="fc_captcha_site_key" class="regular-text" value="<?php echo esc_attr( $captcha_site_key ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'The public key. Safe to appear in your form markup.', 'formcatch' ); ?></p>
				</td>
			</tr>
			<tr class="fc-captcha-keys">
				<th scope="row"><label for="fc-captcha-secret-key"><?php esc_html_e( 'Secret key', 'formcatch' ); ?></label></th>
				<td>
					<input type="password" id="fc-captcha-secret-key" name="fc_captcha_secret_key" class="regular-text" value="<?php echo esc_attr( $captcha_secret_key ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'The private key. Used server-side to verify submissions — never exposed to visitors.', 'formcatch' ); ?></p>
				</td>
			</tr>
			<tr class="fc-captcha-keys fc-captcha-v3">
				<th scope="row"><label for="fc-captcha-threshold"><?php esc_html_e( 'reCAPTCHA v3 threshold', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-captcha-threshold" name="fc_captcha_v3_threshold" min="0" max="1" step="0.1" value="<?php echo esc_attr( $captcha_v3_threshold ); ?>" class="small-text" />
					<?php esc_html_e( 'Minimum score (0–1) to accept. Lower = more lenient. Only applies to reCAPTCHA v3. Default 0.5.', 'formcatch' ); ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'File uploads', 'formcatch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fc-upload-max"><?php esc_html_e( 'Max upload size', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-upload-max" name="fc_upload_max_mb" min="0" value="<?php echo esc_attr( $upload_max_mb ); ?>" class="small-text" />
					<?php esc_html_e( 'MB per file (0 = use the server maximum).', 'formcatch' ); ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: server max upload size */
							esc_html__( 'Your server currently allows up to %s per upload. Setting a value higher than this has no effect — raising it requires changing upload_max_filesize / post_max_size in php.ini.', 'formcatch' ),
							esc_html( size_format( $server_limit_bytes ) )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fc-upload-ext"><?php esc_html_e( 'Allowed file types', 'formcatch' ); ?></label></th>
				<td>
					<input type="text" id="fc-upload-ext" name="fc_upload_allowed_ext" class="large-text code" value="<?php echo esc_attr( $upload_allowed_ext ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated file extensions accepted on forms that allow uploads. Executable/script types (php, js, html, svg, etc.) are always blocked for security, even if listed.', 'formcatch' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fc-upload-count"><?php esc_html_e( 'Max files per submission', 'formcatch' ); ?></label></th>
				<td>
					<input type="number" id="fc-upload-count" name="fc_upload_max_files" min="1" value="<?php echo esc_attr( $upload_max_files ); ?>" class="small-text" />
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Spam protection', 'formcatch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Disposable emails', 'formcatch' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fc_block_disposable_email" value="1" <?php checked( $block_disposable ); ?> />
						<?php esc_html_e( 'Reject submissions that use disposable / temporary email addresses', 'formcatch' ); ?>
					</label>
					<p class="description">
						<?php
						if ( $disposable_updated ) {
							printf(
								/* translators: 1: domain count, 2: date */
								esc_html__( 'Blocklist: %1$s domains, last updated %2$s. Refreshes automatically on plugin updates.', 'formcatch' ),
								esc_html( number_format_i18n( $disposable_count ) ),
								esc_html( $disposable_updated )
							);
						} else {
							printf(
								/* translators: %s: domain count */
								esc_html__( 'Using a small built-in fallback list (%s domains) until the full list is fetched. Refreshes automatically on plugin updates.', 'formcatch' ),
								esc_html( number_format_i18n( $disposable_count ) )
							);
						}
						?>
					</p>
					<p>
						<?php
						$refresh_url = wp_nonce_url( admin_url( 'admin-post.php?action=fc_refresh_disposable' ), 'fc_refresh_disposable' );
						?>
						<a href="<?php echo esc_url( $refresh_url ); ?>" class="button"><?php esc_html_e( 'Refresh blocklist now', 'formcatch' ); ?></a>
						<?php if ( ! empty( $_GET['disposable_refreshed'] ) ) : ?>
							<span style="color:#1a7a3d;margin-left:8px;"><?php esc_html_e( 'Blocklist refreshed.', 'formcatch' ); ?></span>
						<?php elseif ( ! empty( $_GET['disposable_error'] ) ) : ?>
							<span style="color:#a12622;margin-left:8px;"><?php esc_html_e( 'Refresh failed — kept the existing list. See Logs.', 'formcatch' ); ?></span>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Data', 'formcatch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'On uninstall', 'formcatch' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="fc_delete_data_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?> />
						<?php esc_html_e( 'Delete all FormCatch forms and submissions when the plugin is deleted', 'formcatch' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Deactivating the plugin never touches your data — this only applies if you delete it entirely from the Plugins screen.', 'formcatch' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
