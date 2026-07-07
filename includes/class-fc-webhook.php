<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POSTs a submission to an external URL (Slack, Zapier, etc).
 */
class FC_Webhook {

	/**
	 * @param object   $form          Row from fc_forms (settings already decoded as array on ->settings_arr).
	 * @param array    $payload       Sanitized submitted fields.
	 * @param int|null $submission_id
	 * @return bool
	 */
	public static function send( $form, $payload, $submission_id ) {
		$settings = $form->settings_arr;
		$urls     = self::get_urls( $settings );

		if ( empty( $urls ) ) {
			return false;
		}

		$ok = true;
		foreach ( $urls as $url ) {
			if ( ! self::post_to( $url, $form, $payload, $submission_id ) ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Split the stored webhook_url setting (one URL per line, commas also
	 * accepted) into a list of valid URLs.
	 *
	 * @param array $settings
	 * @return string[]
	 */
	private static function get_urls( $settings ) {
		$raw = (string) $settings['webhook_url'];
		$raw = str_replace( ',', "\n", $raw );

		$urls = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		return array_values( array_filter( $urls, 'wp_http_validate_url' ) );
	}

	/**
	 * @param string   $url
	 * @param object   $form
	 * @param array    $payload
	 * @param int|null $submission_id
	 * @return bool
	 */
	private static function post_to( $url, $form, $payload, $submission_id ) {
		$body = apply_filters(
			'fc_webhook_payload',
			array(
				'form_id'       => (int) $form->id,
				'form_name'     => $form->name,
				'submission_id' => $submission_id,
				'submitted_at'  => current_time( 'mysql' ),
				'data'          => $payload,
			),
			$form,
			$payload
		);

		$json = wp_json_encode( $body );

		$headers = array( 'Content-Type' => 'application/json' );

		// Optional HMAC signing so receivers can verify the request is
		// genuinely from this site. Signature covers "{timestamp}.{body}" to
		// also guard against replay; header format mirrors common webhook
		// conventions (e.g. Stripe): "t=<ts>,v1=<hex-hmac-sha256>".
		$secret = (string) get_option( 'fc_webhook_secret', '' );
		if ( '' !== $secret ) {
			$timestamp = (string) time();
			$signature = hash_hmac( 'sha256', $timestamp . '.' . $json, $secret );
			$headers['X-FormCatch-Signature'] = 't=' . $timestamp . ',v1=' . $signature;
		}

		$headers = apply_filters( 'fc_webhook_headers', $headers, $form, $payload );

		// Blocking so we can capture the response status for the event log.
		// Timeout kept short to limit the delay added to the submitter's request.
		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => 8,
				'blocking' => true,
				'headers'  => $headers,
				'body'     => $json,
			)
		);

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( is_wp_error( $response ) ) {
			FC_Logger::webhook(
				FC_Logger::STATUS_ERROR,
				/* translators: 1: host, 2: error message */
				sprintf( __( 'Webhook to %1$s failed: %2$s', 'formcatch' ), $host, $response->get_error_message() ),
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				),
				$form->id,
				$submission_id
			);
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = ( $code >= 200 && $code < 300 );

		FC_Logger::webhook(
			$ok ? FC_Logger::STATUS_SUCCESS : FC_Logger::STATUS_ERROR,
			$ok
				/* translators: 1: host, 2: HTTP status code */
				? sprintf( __( 'Webhook to %1$s returned %2$d', 'formcatch' ), $host, $code )
				/* translators: 1: host, 2: HTTP status code */
				: sprintf( __( 'Webhook to %1$s returned %2$d', 'formcatch' ), $host, $code ),
			array(
				'url'           => $url,
				'response_code' => $code,
				'body'          => mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 500 ),
			),
			$form->id,
			$submission_id
		);

		return $ok;
	}
}
