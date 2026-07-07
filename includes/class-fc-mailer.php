<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the "new submission" notification email(s).
 *
 * Each form can define one or more email rules (recipient list + subject +
 * body + format). Subject and body support {{token}} placeholders that are
 * filled from the submitted fields plus a few specials.
 */
class FC_Mailer {

	/**
	 * @param object   $form          Row from fc_forms (settings decoded on ->settings_arr).
	 * @param array    $payload       Sanitized submitted fields.
	 * @param int|null $submission_id
	 * @return bool True if every email was handed to wp_mail successfully.
	 */
	public static function send_notification( $form, $payload, $submission_id = null ) {
		$settings = $form->settings_arr;
		$emails   = ! empty( $settings['emails'] ) ? $settings['emails'] : array();

		if ( empty( $emails ) ) {
			return false;
		}

		$all_ok = true;
		foreach ( $emails as $email ) {
			if ( ! self::send_one( $email, $form, $payload, $submission_id ) ) {
				$all_ok = false;
			}
		}

		return $all_ok;
	}

	/**
	 * Send a single email rule.
	 *
	 * @param array    $email         to/subject/body/format.
	 * @param object   $form
	 * @param array    $payload
	 * @param int|null $submission_id
	 * @return bool
	 */
	private static function send_one( $email, $form, $payload, $submission_id ) {
		$is_html = ( 'html' === $email['format'] );
		$tokens  = self::build_tokens( $form, $payload, $submission_id, $is_html );

		// "To" supports {{tokens}} too — e.g. {{email}} sends straight back to
		// the submitter, turning a rule into an autoresponder. Unescaped
		// (raw) tokens are used here since addresses are never HTML.
		$raw_tokens = self::build_tokens( $form, $payload, $submission_id, false );
		$to_raw     = self::render( (string) $email['to'], $raw_tokens, false );
		$to         = array_filter( array_map( 'trim', explode( ',', $to_raw ) ), 'is_email' );

		if ( empty( $to ) ) {
			FC_Logger::email(
				FC_Logger::STATUS_SKIPPED,
				__( 'Email skipped: no valid recipient.', 'formcatch' ),
				array( 'to_template' => $email['to'] ),
				$form->id,
				$submission_id
			);
			return false;
		}

		$subject = self::render( (string) $email['subject'], $tokens, false );
		if ( '' === trim( $subject ) ) {
			$subject = __( 'New form submission', 'formcatch' );
		}
		$subject = apply_filters( 'fc_email_subject', $subject, $form, $payload );

		$body = self::render( (string) $email['body'], $tokens, $is_html );
		if ( $is_html ) {
			$body = wpautop( $body );
		}
		$body = apply_filters( 'fc_email_body', $body, $form, $payload );

		$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();

		// Optional custom From name/address (per form). Scoped to this email
		// via a header rather than the site-wide wp_mail_from filter, so it
		// never affects other plugins' mail. Note: for good deliverability the
		// address should be on a domain whose SPF/DKIM authorizes this site.
		$from_address = trim( (string) $settings['email_from_address'] );
		$from_name    = trim( (string) $settings['email_from_name'] );
		if ( '' !== $from_address && is_email( $from_address ) ) {
			$headers[] = '' !== $from_name
				? sprintf( 'From: %s <%s>', $from_name, $from_address )
				: 'From: ' . $from_address;
		}

		// Optional Reply-To pulled from a submitted field (e.g. the visitor's
		// email), so replying goes straight to the person who filled the form.
		$reply_to = self::resolve_reply_to( $email, $payload );
		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$headers = apply_filters( 'fc_email_headers', $headers, $form, $payload );

		$sent = wp_mail( $to, $subject, $body, $headers );

		FC_Logger::email(
			$sent ? FC_Logger::STATUS_SUCCESS : FC_Logger::STATUS_ERROR,
			$sent
				/* translators: %s: comma-separated recipients */
				? sprintf( __( 'Email sent to %s', 'formcatch' ), implode( ', ', $to ) )
				/* translators: %s: comma-separated recipients */
				: sprintf( __( 'Email failed to %s', 'formcatch' ), implode( ', ', $to ) ),
			array(
				'to'       => $to,
				'subject'  => $subject,
				'format'   => $email['format'],
				'reply_to' => $reply_to,
			),
			$form->id,
			$submission_id
		);

		return $sent;
	}

	/**
	 * Work out the Reply-To address for an email rule. The rule's
	 * reply_to_field names a submitted field; if that field holds a valid
	 * email, use it. Falls back to empty (WordPress default sender).
	 *
	 * @param array $email
	 * @param array $payload
	 * @return string Empty string when none.
	 */
	private static function resolve_reply_to( $email, $payload ) {
		$field = isset( $email['reply_to_field'] ) ? trim( (string) $email['reply_to_field'] ) : '';
		if ( '' === $field || ! isset( $payload[ $field ] ) ) {
			return '';
		}

		$candidate = is_array( $payload[ $field ] ) ? '' : trim( (string) $payload[ $field ] );

		return is_email( $candidate ) ? $candidate : '';
	}

	/**
	 * Build the map of {{token}} => replacement values.
	 *
	 * @param object   $form
	 * @param array    $payload
	 * @param int|null $submission_id
	 * @param bool     $is_html  Whether values should be HTML-escaped.
	 * @return array
	 */
	private static function build_tokens( $form, $payload, $submission_id, $is_html ) {
		$tokens = array();

		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$tokens[ $key ] = $is_html ? esc_html( $value ) : $value;
		}

		// Specials. These override any submitted field of the same name.
		$tokens['form_name']     = $is_html ? esc_html( $form->name ) : $form->name;
		$tokens['submission_id'] = (string) $submission_id;
		$tokens['all_fields']    = self::render_all_fields( $payload, $is_html );
		$tokens['files']         = self::render_files( $submission_id, $is_html );

		return apply_filters( 'fc_email_tokens', $tokens, $form, $payload, $is_html );
	}

	/**
	 * Render the {{files}} token: signed, expiring download links for any
	 * files attached to this submission. Empty string when there are none.
	 *
	 * @param int|null $submission_id
	 * @param bool     $is_html
	 * @return string
	 */
	private static function render_files( $submission_id, $is_html ) {
		if ( ! $submission_id ) {
			return '';
		}

		$files = FC_Uploads::for_submission( $submission_id );
		if ( empty( $files ) ) {
			return '';
		}

		$lines = array();
		foreach ( $files as $file ) {
			$url  = FC_Uploads::signed_url( $file->id );
			$name = $file->original_name;
			if ( $is_html ) {
				$lines[] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
			} else {
				$lines[] = $name . ': ' . $url;
			}
		}

		return implode( $is_html ? "<br>\n" : "\n", $lines );
	}

	/**
	 * Format the full submitted payload as a "Label: value" block, one
	 * field per line (or per row for HTML).
	 *
	 * @param array $payload
	 * @param bool  $is_html
	 * @return string
	 */
	private static function render_all_fields( $payload, $is_html ) {
		$lines = array();

		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			if ( $is_html ) {
				$lines[] = sprintf( '<strong>%s:</strong> %s', esc_html( $key ), esc_html( $value ) );
			} else {
				$lines[] = sprintf( '%s: %s', $key, $value );
			}
		}

		return implode( $is_html ? "<br>\n" : "\n", $lines );
	}

	/**
	 * Replace {{token}} placeholders in a template. Unknown tokens are
	 * left as-is rather than blanked, so a typo is visible instead of
	 * silently swallowing text.
	 *
	 * @param string $template
	 * @param array  $tokens
	 * @param bool   $is_html  Reserved; escaping is already applied to $tokens.
	 * @return string
	 */
	private static function render( $template, $tokens, $is_html ) {
		return preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/',
			function ( $matches ) use ( $tokens ) {
				$key = $matches[1];
				return array_key_exists( $key, $tokens ) ? $tokens[ $key ] : $matches[0];
			},
			$template
		);
	}
}
