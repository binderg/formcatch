<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var object|null $form */
$is_new   = empty( $form );
$settings = $is_new ? FC_Helpers::default_form_settings() : $form->settings_arr;
$name     = $is_new ? '' : $form->name;
$status   = $is_new ? true : ( 'active' === $form->status );
$token    = $is_new ? '' : $form->token;
?>
<div class="wrap fc-wrap">
	<h1><?php echo $is_new ? esc_html__( 'Add New Form', 'formcatch' ) : esc_html__( 'Edit Form', 'formcatch' ); ?></h1>

	<?php if ( ! empty( $_GET['saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form saved.', 'formcatch' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['captcha_unconfigured'] ) ) : ?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: settings link */
					esc_html__( 'This form requires CAPTCHA, but no provider is configured yet, so it will not be enforced. Add keys in %s.', 'formcatch' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=formcatch-settings' ) ) . '">' . esc_html__( 'Settings', 'formcatch' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! $is_new ) : ?>
		<div class="fc-endpoint-box">
			<h2><?php esc_html_e( 'Your submit endpoint', 'formcatch' ); ?></h2>
			<p><?php esc_html_e( 'Point any HTML or PHP form at this URL. No shortcode, no JS required (though it works fine with fetch/AJAX too).', 'formcatch' ); ?></p>
			<code id="fc-endpoint-url" class="fc-endpoint-code"><?php echo esc_html( FC_Helpers::submit_url( $token ) ); ?></code>
			<button type="button" class="button fc-copy-btn" data-copy-target="fc-endpoint-url"><?php esc_html_e( 'Copy', 'formcatch' ); ?></button>

			<h3><?php esc_html_e( 'Example', 'formcatch' ); ?></h3>
			<pre class="fc-snippet"><?php echo esc_html( sprintf(
				"<form action=\"%s\" method=\"post\">\n    <input type=\"text\" name=\"name\" required>\n    <input type=\"email\" name=\"email\" required>\n    <textarea name=\"message\"></textarea>\n\n    <!-- honeypot: leave this hidden and empty, real users never fill it -->\n    <input type=\"text\" name=\"%s\" style=\"display:none\" tabindex=\"-1\" autocomplete=\"off\">\n\n    <button type=\"submit\">Send</button>\n</form>",
				FC_Helpers::submit_url( $token ),
				esc_attr( $settings['honeypot_field'] )
			) ); ?></pre>
			<p class="description"><?php esc_html_e( 'Submitting this normally will redirect back to the page with ?submitted=1 (or your custom redirect URL below). Submitting via fetch()/AJAX with an Accept: application/json header will get a JSON response instead — no extra config needed.', 'formcatch' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fc-form-settings">
		<?php wp_nonce_field( 'fc_save_form' ); ?>
		<input type="hidden" name="action" value="fc_save_form" />
		<?php if ( ! $is_new ) : ?>
			<input type="hidden" name="form_id" value="<?php echo esc_attr( $form->id ); ?>" />
		<?php endif; ?>

		<h2 class="nav-tab-wrapper fc-tabs" id="fc-tabs">
			<a href="#fc-tab-general" class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e( 'General', 'formcatch' ); ?></a>
			<a href="#fc-tab-email" class="nav-tab" data-tab="email"><?php esc_html_e( 'Email', 'formcatch' ); ?></a>
			<a href="#fc-tab-spam" class="nav-tab" data-tab="spam"><?php esc_html_e( 'Spam', 'formcatch' ); ?></a>
			<a href="#fc-tab-advanced" class="nav-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'formcatch' ); ?></a>
		</h2>

		<div class="fc-tab-panels">

		<!-- ===================== General ===================== -->
		<div class="fc-tab-panel" id="fc-tab-general">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fc-name"><?php esc_html_e( 'Form name', 'formcatch' ); ?></label></th>
					<td>
						<input type="text" id="fc-name" name="name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Contact page form', 'formcatch' ); ?>" required />
						<p class="description"><?php esc_html_e( 'For your reference only — not shown to submitters.', 'formcatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'formcatch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="status" value="1" <?php checked( $status ); ?> />
							<?php esc_html_e( 'Active (accepting submissions)', 'formcatch' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage', 'formcatch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="save_to_db" value="1" <?php checked( ! empty( $settings['save_to_db'] ) ); ?> />
							<?php esc_html_e( 'Save submissions to the database (searchable in the Submissions tab)', 'formcatch' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'File uploads', 'formcatch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="allow_uploads" value="1" <?php checked( ! empty( $settings['allow_uploads'] ) ); ?> />
							<?php esc_html_e( 'Accept file uploads on this form', 'formcatch' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: 1: max size, 2: allowed extensions */
								esc_html__( 'Off by default. Add file inputs to your form (with enctype="multipart/form-data"). Current limits: up to %1$s per file, types: %2$s — change these in Settings.', 'formcatch' ),
								esc_html( size_format( FC_Uploads::max_size() ) ),
								esc_html( implode( ', ', FC_Uploads::allowed_extensions() ) )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fc-redirect"><?php esc_html_e( 'Redirect URL', 'formcatch' ); ?></label></th>
					<td>
						<input type="url" id="fc-redirect" name="redirect_url" class="regular-text" value="<?php echo esc_attr( $settings['redirect_url'] ); ?>" placeholder="https://example.com/thank-you" />
						<p class="description"><?php esc_html_e( 'Only used for plain (non-JS) form posts. Leave blank to redirect back to the referring page.', 'formcatch' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ===================== Email ===================== -->
		<div class="fc-tab-panel" id="fc-tab-email">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fc-from-name"><?php esc_html_e( 'From name', 'formcatch' ); ?></label></th>
					<td>
						<input type="text" id="fc-from-name" name="email_from_name" class="regular-text" value="<?php echo esc_attr( $settings['email_from_name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Acme Contact Form', 'formcatch' ); ?>" />
						<p class="description"><?php esc_html_e( 'Sender name on this form\'s notification emails. Leave blank for the WordPress default.', 'formcatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fc-from-address"><?php esc_html_e( 'From address', 'formcatch' ); ?></label></th>
					<td>
						<input type="email" id="fc-from-address" name="email_from_address" class="regular-text" value="<?php echo esc_attr( $settings['email_from_address'] ); ?>" placeholder="forms@example.com" />
						<p class="description"><?php esc_html_e( 'Address this form\'s notifications are sent from. For deliverability, use an address whose domain SPF/DKIM authorizes this site (an SMTP plugin helps). Leave blank for the WordPress default.', 'formcatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email notifications', 'formcatch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="fc-notify-toggle" name="notify_email" value="1" <?php checked( ! empty( $settings['notify_email'] ) ); ?> />
							<?php esc_html_e( 'Email when this form receives a submission', 'formcatch' ); ?>
						</label>
						<div class="fc-notify-fields">
							<p class="description fc-emails-help">
								<?php esc_html_e( 'Add one or more emails to send on each submission. Use placeholders in the subject and body to insert submitted values:', 'formcatch' ); ?>
								<code>{{field_name}}</code>, <code>{{form_name}}</code>, <code>{{submission_id}}</code>, <code>{{all_fields}}</code>, <code>{{files}}</code>.
							</p>

							<div id="fc-emails" class="fc-emails">
								<?php
								$emails = ! empty( $settings['emails'] ) ? $settings['emails'] : array( FC_Helpers::default_email() );
								foreach ( $emails as $i => $email ) :
									$email = wp_parse_args( (array) $email, FC_Helpers::default_email() );
									?>
									<div class="fc-email-row">
										<button type="button" class="button-link fc-email-remove" aria-label="<?php esc_attr_e( 'Remove this email', 'formcatch' ); ?>">&times;</button>
										<p>
											<label><?php esc_html_e( 'Send to', 'formcatch' ); ?></label><br />
											<input type="text" name="emails[<?php echo (int) $i; ?>][to]" class="regular-text" value="<?php echo esc_attr( $email['to'] ); ?>" placeholder="you@example.com, other@example.com" />
											<span class="description"><?php esc_html_e( 'Comma separate multiple recipients. Supports {{tokens}} — e.g. {{email}} sends this rule to the submitter (autoresponder).', 'formcatch' ); ?></span>
										</p>
										<p>
											<label><?php esc_html_e( 'Subject', 'formcatch' ); ?></label><br />
											<input type="text" name="emails[<?php echo (int) $i; ?>][subject]" class="regular-text" value="<?php echo esc_attr( $email['subject'] ); ?>" />
										</p>
										<p>
											<label><?php esc_html_e( 'Format', 'formcatch' ); ?></label><br />
											<select name="emails[<?php echo (int) $i; ?>][format]">
												<option value="plain" <?php selected( $email['format'], 'plain' ); ?>><?php esc_html_e( 'Plain text', 'formcatch' ); ?></option>
												<option value="html" <?php selected( $email['format'], 'html' ); ?>><?php esc_html_e( 'HTML', 'formcatch' ); ?></option>
											</select>
										</p>
										<p>
											<label><?php esc_html_e( 'Reply-To field', 'formcatch' ); ?></label><br />
											<input type="text" name="emails[<?php echo (int) $i; ?>][reply_to_field]" class="regular-text" value="<?php echo esc_attr( $email['reply_to_field'] ); ?>" placeholder="email" />
											<span class="description"><?php esc_html_e( 'Optional. Name of a submitted field holding the visitor\'s email — replies go straight to them.', 'formcatch' ); ?></span>
										</p>
										<p>
											<label><?php esc_html_e( 'Content', 'formcatch' ); ?></label><br />
											<textarea name="emails[<?php echo (int) $i; ?>][body]" class="large-text" rows="5"><?php echo esc_textarea( $email['body'] ); ?></textarea>
										</p>
									</div>
								<?php endforeach; ?>
							</div>

							<button type="button" class="button fc-email-add">
								<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add email', 'formcatch' ); ?>
							</button>

							<?php // Template used by JS to add new email rows. __INDEX__ is replaced with a unique index. ?>
							<script type="text/html" id="fc-email-template">
								<div class="fc-email-row">
									<button type="button" class="button-link fc-email-remove" aria-label="<?php esc_attr_e( 'Remove this email', 'formcatch' ); ?>">&times;</button>
									<p>
										<label><?php esc_html_e( 'Send to', 'formcatch' ); ?></label><br />
										<input type="text" name="emails[__INDEX__][to]" class="regular-text" placeholder="you@example.com, other@example.com" />
										<span class="description"><?php esc_html_e( 'Comma separate multiple recipients.', 'formcatch' ); ?></span>
									</p>
									<p>
										<label><?php esc_html_e( 'Subject', 'formcatch' ); ?></label><br />
										<input type="text" name="emails[__INDEX__][subject]" class="regular-text" value="<?php echo esc_attr__( 'New form submission — {{form_name}}', 'formcatch' ); ?>" />
									</p>
									<p>
										<label><?php esc_html_e( 'Format', 'formcatch' ); ?></label><br />
										<select name="emails[__INDEX__][format]">
											<option value="plain"><?php esc_html_e( 'Plain text', 'formcatch' ); ?></option>
											<option value="html"><?php esc_html_e( 'HTML', 'formcatch' ); ?></option>
										</select>
									</p>
									<p>
										<label><?php esc_html_e( 'Reply-To field', 'formcatch' ); ?></label><br />
										<input type="text" name="emails[__INDEX__][reply_to_field]" class="regular-text" placeholder="email" />
										<span class="description"><?php esc_html_e( 'Optional. Name of a submitted field holding the visitor\'s email — replies go straight to them.', 'formcatch' ); ?></span>
									</p>
									<p>
										<label><?php esc_html_e( 'Content', 'formcatch' ); ?></label><br />
										<textarea name="emails[__INDEX__][body]" class="large-text" rows="5">{{all_fields}}</textarea>
									</p>
								</div>
							</script>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<!-- ===================== Spam ===================== -->
		<div class="fc-tab-panel" id="fc-tab-spam">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'CAPTCHA', 'formcatch' ); ?></th>
					<td>
						<?php if ( FC_Captcha::is_configured() ) : ?>
							<label>
								<input type="checkbox" name="require_captcha" value="1" <?php checked( ! empty( $settings['require_captcha'] ) ); ?> />
								<?php
								/* translators: %s: provider name */
								printf( esc_html__( 'Require CAPTCHA (%s) on this form', 'formcatch' ), esc_html( FC_Captcha::provider() ) );
								?>
							</label>

							<?php if ( 'recaptcha_v3' === FC_Captcha::provider() ) : ?>
								<?php $badge = FC_Captcha::normalize_badge( $settings['captcha_v3_badge'] ); ?>
								<p style="margin-top:12px;">
									<label for="fc-captcha-badge"><strong><?php esc_html_e( 'Badge position', 'formcatch' ); ?></strong></label><br />
									<select id="fc-captcha-badge" name="captcha_v3_badge">
										<option value="bottomright" <?php selected( $badge, 'bottomright' ); ?>><?php esc_html_e( 'Bottom right (default)', 'formcatch' ); ?></option>
										<option value="bottomleft" <?php selected( $badge, 'bottomleft' ); ?>><?php esc_html_e( 'Bottom left', 'formcatch' ); ?></option>
										<option value="inline" <?php selected( $badge, 'inline' ); ?>><?php esc_html_e( 'Inline (hide badge, show text near button)', 'formcatch' ); ?></option>
									</select>
									<span class="description"><?php esc_html_e( 'Where the invisible reCAPTCHA badge shows. Google requires the badge OR the attribution text — "Inline" swaps it for that text. Re-save to update the example below.', 'formcatch' ); ?></span>
								</p>
							<?php endif; ?>

							<?php if ( empty( $settings['require_captcha'] ) ) : ?>
								<p class="description"><?php esc_html_e( 'CAPTCHA is not required on this form yet, so the endpoint example above does not include it. Check the box and save to get a wired-in example here.', 'formcatch' ); ?></p>
							<?php elseif ( $is_new ) : ?>
								<p class="description"><?php esc_html_e( 'Save this form first to get a complete copy-paste example with your endpoint URL and CAPTCHA widget.', 'formcatch' ); ?></p>
							<?php else : ?>
								<?php $sample = FC_Captcha::sample_form_html( FC_Helpers::submit_url( $token ), $settings['honeypot_field'], $settings['captcha_v3_badge'] ); ?>
								<?php if ( $sample ) : ?>
									<p class="description"><?php esc_html_e( 'Copy-paste example form with the CAPTCHA widget wired in:', 'formcatch' ); ?></p>
									<pre class="fc-snippet"><?php echo esc_html( $sample ); ?></pre>
								<?php endif; ?>
							<?php endif; ?>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: settings page link */
									esc_html__( 'No CAPTCHA provider configured. Add your keys in %s to enable this.', 'formcatch' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=formcatch-settings' ) ) . '">' . esc_html__( 'Settings', 'formcatch' ) . '</a>'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fc-honeypot"><?php esc_html_e( 'Honeypot field name', 'formcatch' ); ?></label></th>
					<td>
						<input type="text" id="fc-honeypot" name="honeypot_field" class="regular-text" value="<?php echo esc_attr( $settings['honeypot_field'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Add a hidden input with this name to your form (see example above). Real visitors never fill it in; bots often do. Submissions with this field filled are marked as spam.', 'formcatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fc-allowed-domains"><?php esc_html_e( 'Allowed domains', 'formcatch' ); ?></label></th>
					<td>
						<textarea id="fc-allowed-domains" name="allowed_domains" class="regular-text" rows="3" placeholder="example.com&#10;www.example.com"><?php echo esc_textarea( $settings['allowed_domains'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. One domain per line. When set, only submissions coming from these domains (matched on the Origin/Referer header, subdomains included) are accepted — everything else is rejected. Leave blank to accept from anywhere.', 'formcatch' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Spam handling', 'formcatch' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="notify_on_spam" value="1" <?php checked( ! empty( $settings['notify_on_spam'] ) ); ?> />
							<?php esc_html_e( 'Send email/webhook notifications even for submissions flagged as spam (honeypot triggered)', 'formcatch' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Unchecked by default: spam submissions are still saved (if storage is on) but skip notifications.', 'formcatch' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ===================== Advanced ===================== -->
		<div class="fc-tab-panel" id="fc-tab-advanced">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fc-webhook"><?php esc_html_e( 'Webhook URL(s)', 'formcatch' ); ?></label></th>
					<td>
						<textarea id="fc-webhook" name="webhook_url" class="regular-text" rows="3" placeholder="https://hooks.slack.com/services/...&#10;https://hooks.zapier.com/..."><?php echo esc_textarea( $settings['webhook_url'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional. One URL per line (or comma separated). Each non-spam submission is POSTed to every URL as JSON: {form_id, form_name, submission_id, submitted_at, data}. Leave blank to disable.', 'formcatch' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		</div>

		<?php submit_button( $is_new ? __( 'Create Form', 'formcatch' ) : __( 'Save Changes', 'formcatch' ) ); ?>
	</form>

	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=formcatch-forms' ) ); ?>">&larr; <?php esc_html_e( 'Back to all forms', 'formcatch' ); ?></a></p>
</div>
