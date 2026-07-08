<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public-facing REST endpoint that catches form submissions.
 *
 * Route: /wp-json/formcatch/v1/submit/{token}
 */
class FC_REST_Controller {

	const NAMESPACE_SLUG = 'formcatch/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_SLUG,
			'/submit/(?P<token>[a-zA-Z0-9]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_submit' ),
					'permission_callback' => '__return_true', // Auth is the token itself; validated inside.
					'args'                => array(
						'token' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_string( $param ) && preg_match( '/^[a-zA-Z0-9]+$/', $param );
							},
						),
					),
				),
				// Preflight for cross-origin fetch() callers.
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'handle_preflight' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Authenticated read API — list submissions.
		register_rest_route(
			self::NAMESPACE_SLUG,
			'/submissions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_submissions' ),
				'permission_callback' => array( $this, 'read_permission' ),
				'args'                => array(
					'form_id'  => array( 'sanitize_callback' => 'absint' ),
					'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'search'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'page'     => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
					'per_page' => array( 'sanitize_callback' => 'absint', 'default' => 20 ),
				),
			)
		);

		// Authenticated read API — single submission.
		register_rest_route(
			self::NAMESPACE_SLUG,
			'/submissions/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_submission' ),
				'permission_callback' => array( $this, 'read_permission' ),
				'args'                => array(
					'id' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);

		/* ---- Forms API (AI-native: create/read/update/delete + schema) ---- */

		register_rest_route(
			self::NAMESPACE_SLUG,
			'/forms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_list_forms' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_create_form' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
			)
		);

		// Self-describing schema — declared before the {id} route so "schema"
		// isn't captured as an id.
		register_rest_route(
			self::NAMESPACE_SLUG,
			'/forms/schema',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_forms_schema' ),
				'permission_callback' => array( $this, 'read_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_SLUG,
			'/forms/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import_forms' ),
				'permission_callback' => array( $this, 'read_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_SLUG,
			'/forms/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get_form' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
				array(
					'methods'             => array( 'PUT', 'PATCH' ),
					'callback'            => array( $this, 'handle_update_form' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_delete_form' ),
					'permission_callback' => array( $this, 'read_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_SLUG,
			'/forms/(?P<id>\d+)/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_export_form' ),
				'permission_callback' => array( $this, 'read_permission' ),
			)
		);
	}

	/* -----------------------------------------------------------------
	 * Forms API handlers
	 * --------------------------------------------------------------- */

	public function handle_forms_schema() {
		return new WP_REST_Response( FC_Forms::schema(), 200 );
	}

	public function handle_list_forms() {
		global $wpdb;
		$table = FC_DB::forms_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = array_map( array( 'FC_Forms', 'to_api_array' ), $rows );
		return new WP_REST_Response( $out, 200 );
	}

	public function handle_get_form( WP_REST_Request $request ) {
		$form = FC_Forms::get( (int) $request->get_param( 'id' ) );
		if ( ! $form ) {
			return new WP_Error( 'fc_not_found', __( 'Form not found.', 'formcatch' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( FC_Forms::to_api_array( $form ), 200 );
	}

	public function handle_create_form( WP_REST_Request $request ) {
		$body     = (array) $request->get_json_params();
		$name     = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		$status   = isset( $body['status'] ) ? sanitize_key( $body['status'] ) : 'active';
		$in       = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : array();
		$settings = FC_Forms::sanitize_settings( $in, null, false );

		$valid = FC_Forms::validate_settings( $settings );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$id   = FC_Forms::create( $name, $settings, $status );
		$form = FC_Forms::get( $id );

		return new WP_REST_Response( FC_Forms::to_api_array( $form ), 201 );
	}

	public function handle_update_form( WP_REST_Request $request ) {
		$id       = (int) $request->get_param( 'id' );
		$existing = FC_Forms::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'fc_not_found', __( 'Form not found.', 'formcatch' ), array( 'status' => 404 ) );
		}

		$body = (array) $request->get_json_params();

		// PATCH merges; PUT replaces. Absent keys keep existing on PATCH.
		$partial = ( 'PATCH' === $request->get_method() );

		$name   = array_key_exists( 'name', $body ) ? sanitize_text_field( $body['name'] ) : $existing->name;
		$status = array_key_exists( 'status', $body ) ? sanitize_key( $body['status'] ) : $existing->status;
		$in     = isset( $body['settings'] ) && is_array( $body['settings'] ) ? $body['settings'] : array();

		$settings = FC_Forms::sanitize_settings( $in, $existing->settings_arr, $partial );

		$valid = FC_Forms::validate_settings( $settings );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		FC_Forms::update( $id, $name, $settings, $status );
		return new WP_REST_Response( FC_Forms::to_api_array( FC_Forms::get( $id ) ), 200 );
	}

	public function handle_delete_form( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! FC_Forms::get( $id ) ) {
			return new WP_Error( 'fc_not_found', __( 'Form not found.', 'formcatch' ), array( 'status' => 404 ) );
		}
		FC_Forms::delete( $id );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	public function handle_export_form( WP_REST_Request $request ) {
		$form = FC_Forms::get( (int) $request->get_param( 'id' ) );
		if ( ! $form ) {
			return new WP_Error( 'fc_not_found', __( 'Form not found.', 'formcatch' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( FC_Forms::to_export_array( $form ), 200 );
	}

	/**
	 * Import one form or an array of forms from portable JSON. Each is
	 * validated independently; the response reports per-item results.
	 */
	public function handle_import_forms( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		// Accept a single form object or a list.
		$items = ( isset( $body['name'] ) || isset( $body['settings'] ) ) ? array( $body ) : (array) $body;

		$created = array();
		$errors  = array();

		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = array( 'index' => $i, 'error' => 'not an object' );
				continue;
			}

			$name     = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
			$status   = isset( $item['status'] ) ? sanitize_key( $item['status'] ) : 'active';
			$in       = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : array();
			$settings = FC_Forms::sanitize_settings( $in, null, false );

			$valid = FC_Forms::validate_settings( $settings );
			if ( is_wp_error( $valid ) ) {
				$errors[] = array( 'index' => $i, 'error' => $valid->get_error_message(), 'fields' => $valid->get_error_data()['errors'] );
				continue;
			}

			$id        = FC_Forms::create( $name, $settings, $status );
			$created[] = FC_Forms::to_api_array( FC_Forms::get( $id ) );
		}

		$status_code = empty( $created ) && ! empty( $errors ) ? 422 : 200;
		return new WP_REST_Response(
			array(
				'created' => $created,
				'errors'  => $errors,
			),
			$status_code
		);
	}

	/**
	 * Gate the read API behind the FormCatch management capability. Uses the
	 * standard cookie+nonce auth that WordPress applies to REST requests, so
	 * a logged-in admin (or an app passing a valid nonce / application
	 * password) can read; the public cannot.
	 *
	 * @return bool|WP_Error
	 */
	public function read_permission() {
		if ( current_user_can( FC_Helpers::capability() ) ) {
			return true;
		}
		return new WP_Error( 'fc_forbidden', __( 'You are not allowed to read submissions.', 'formcatch' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * GET /submissions — paginated, filterable list.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_list_submissions( WP_REST_Request $request ) {
		global $wpdb;
		$table = FC_DB::submissions_table();

		$where  = array( '1=1' );
		$params = array();

		$form_id = (int) $request->get_param( 'form_id' );
		if ( $form_id ) {
			$where[]  = 'form_id = %d';
			$params[] = $form_id;
		}
		$status = (string) $request->get_param( 'status' );
		if ( $status && 'all' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$where[]  = 'payload LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total       = (int) ( empty( $params )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$data = array_map( array( $this, 'format_submission' ), $rows );

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page ? ceil( $total / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * GET /submissions/{id} — single submission.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_get_submission( WP_REST_Request $request ) {
		global $wpdb;
		$table = FC_DB::submissions_table();
		$id    = (int) $request->get_param( 'id' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return new WP_Error( 'fc_not_found', __( 'Submission not found.', 'formcatch' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->format_submission( $row ), 200 );
	}

	/**
	 * Shape a raw submission row for the API.
	 *
	 * @param object $row
	 * @return array
	 */
	private function format_submission( $row ) {
		$data = json_decode( $row->payload, true );

		$files = array();
		foreach ( FC_Uploads::for_submission( $row->id ) as $file ) {
			$files[] = array(
				'id'    => (int) $file->id,
				'field' => $file->field_name,
				'name'  => $file->original_name,
				'size'  => (int) $file->size,
				'mime'  => $file->mime,
				'url'   => FC_Uploads::signed_url( $file->id ),
			);
		}

		return array(
			'id'         => (int) $row->id,
			'form_id'    => (int) $row->form_id,
			'status'     => $row->status,
			'data'       => is_array( $data ) ? $data : array(),
			'files'      => $files,
			'ip'         => $row->ip,
			'referrer'   => $row->referrer,
			'created_at' => $row->created_at,
		);
	}

	/**
	 * Answer a CORS preflight OPTIONS request.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle_preflight( WP_REST_Request $request ) {
		$this->send_cors_headers( $request );
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Emit CORS headers if the request's Origin is allowed. Allowed origins
	 * are configured in Settings (one per line); "*" allows any origin.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	private function send_cors_headers( WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			return;
		}

		$raw     = (string) get_option( 'fc_cors_origins', '' );
		$raw     = str_replace( ',', "\n", $raw );
		$allowed = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		if ( empty( $allowed ) ) {
			return; // CORS disabled by default; same-origin still works.
		}

		$allow_all = in_array( '*', $allowed, true );
		if ( ! $allow_all && ! in_array( untrailingslashit( $origin ), array_map( 'untrailingslashit', $allowed ), true ) ) {
			return; // Origin not on the allowlist.
		}

		header( 'Access-Control-Allow-Origin: ' . ( $allow_all ? '*' : $origin ) );
		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With' );
		header( 'Vary: Origin' );
	}

	/**
	 * Main submission handler.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_submit( WP_REST_Request $request ) {
		global $wpdb;

		$this->send_cors_headers( $request );

		$token = $request->get_param( 'token' );
		$form  = $this->get_form_by_token( $token );

		if ( ! $form ) {
			return new WP_Error( 'fc_invalid_token', __( 'Unknown form.', 'formcatch' ), array( 'status' => 404 ) );
		}

		if ( 'active' !== $form->status ) {
			return new WP_Error( 'fc_form_disabled', __( 'This form is not currently accepting submissions.', 'formcatch' ), array( 'status' => 403 ) );
		}

		$settings = $form->settings_arr;
		$raw      = $request->get_body_params();

		// Rate limit per IP per form to blunt basic flooding.
		if ( $this->is_rate_limited( $form->id ) ) {
			FC_Logger::submission(
				FC_Logger::STATUS_SKIPPED,
				__( 'Submission rejected: rate limited.', 'formcatch' ),
				array( 'ip' => FC_Helpers::get_client_ip() ),
				$form->id
			);
			return new WP_Error( 'fc_rate_limited', __( 'Too many submissions. Please try again shortly.', 'formcatch' ), array( 'status' => 429 ) );
		}

		// Guard against oversized payloads bloating the DB. Field count is a
		// cheap proxy that also blunts field-stuffing attacks.
		$max_fields = (int) get_option( 'fc_max_payload_fields', 100 );
		if ( $max_fields > 0 && count( (array) $raw ) > $max_fields ) {
			FC_Logger::submission(
				FC_Logger::STATUS_ERROR,
				/* translators: %d: max allowed fields */
				sprintf( __( 'Submission rejected: too many fields (limit %d).', 'formcatch' ), $max_fields ),
				array( 'field_count' => count( (array) $raw ) ),
				$form->id
			);
			return new WP_Error( 'fc_too_large', __( 'Submission has too many fields.', 'formcatch' ), array( 'status' => 413 ) );
		}

		// Domain restriction — if the form limits which sites may post to it,
		// reject submissions whose Origin/Referer host isn't on the allowlist.
		if ( ! $this->is_domain_allowed( $form, $request ) ) {
			FC_Logger::submission(
				FC_Logger::STATUS_ERROR,
				__( 'Submission rejected: origin domain not allowed.', 'formcatch' ),
				array( 'origin' => (string) $request->get_header( 'origin' ), 'referer' => (string) $request->get_header( 'referer' ) ),
				$form->id
			);
			return new WP_Error( 'fc_domain_forbidden', __( 'Submissions are not accepted from this domain.', 'formcatch' ), array( 'status' => 403 ) );
		}

		// CAPTCHA — unlike the honeypot (which silently flags as spam), a
		// failed CAPTCHA is a hard reject: a real human needs to know to
		// retry rather than have their submission quietly swallowed.
		$captcha = FC_Captcha::verify( $form, $request );
		if ( is_wp_error( $captcha ) ) {
			FC_Logger::submission(
				FC_Logger::STATUS_ERROR,
				__( 'Submission rejected: CAPTCHA failed.', 'formcatch' ),
				array(
					'reason' => $captcha->get_error_message(),
					'ip'     => FC_Helpers::get_client_ip(),
				),
				$form->id
			);
			return new WP_Error( 'fc_captcha_failed', __( 'CAPTCHA verification failed. Please try again.', 'formcatch' ), array( 'status' => 403 ) );
		}

		// Don't store the provider's token fields as submission data.
		unset( $raw['cf-turnstile-response'], $raw['g-recaptcha-response'], $raw['h-captcha-response'] );

		// Disposable / throwaway email blocking (global toggle). Hard reject
		// with a clear message so a real person knows to use a real address.
		if ( get_option( 'fc_block_disposable_email', false ) ) {
			$bad = FC_Disposable::first_disposable_in( $raw );
			if ( '' !== $bad ) {
				FC_Logger::submission(
					FC_Logger::STATUS_ERROR,
					__( 'Submission rejected: disposable email address.', 'formcatch' ),
					array( 'email' => $bad ),
					$form->id
				);
				return new WP_Error( 'fc_disposable_email', __( 'Please use a non-disposable email address.', 'formcatch' ), array( 'status' => 403 ) );
			}
		}

		// Honeypot check — if the hidden field is filled, it's (almost
		// certainly) a bot. We still record it as spam rather than
		// silently vanish it, so nothing gets lost without a trace.
		$honeypot_field = ! empty( $settings['honeypot_field'] ) ? $settings['honeypot_field'] : '';
		$is_spam        = false;
		if ( $honeypot_field && ! empty( $raw[ $honeypot_field ] ) ) {
			$is_spam = true;
		}

		// Strip control/honeypot fields out of the stored payload.
		unset( $raw[ $honeypot_field ] );
		$payload = FC_Helpers::sanitize_payload( $raw );

		$submission_id = null;

		if ( $settings['save_to_db'] ) {
			$submission_id = $this->store_submission( $form, $payload, $is_spam );
		}

		FC_Logger::submission(
			$is_spam ? FC_Logger::STATUS_SKIPPED : FC_Logger::STATUS_SUCCESS,
			$is_spam
				? __( 'Submission received (flagged as spam).', 'formcatch' )
				: __( 'Submission received.', 'formcatch' ),
			array( 'fields' => array_keys( (array) $payload ) ),
			$form->id,
			$submission_id
		);

		// Process file uploads (non-spam, stored submissions only — files
		// need a submission row to attach to). Done before notifications so
		// the {{files}} email token and webhook payload can reference them.
		if ( ! $is_spam && $submission_id ) {
			FC_Uploads::process( $form, $request, $submission_id );
		}

		$should_notify = ! $is_spam || ! empty( $settings['notify_on_spam'] );

		if ( $should_notify && ! empty( $settings['notify_email'] ) ) {
			FC_Mailer::send_notification( $form, $payload, $submission_id );
		}

		if ( $should_notify && ! empty( $settings['webhook_url'] ) ) {
			FC_Webhook::send( $form, $payload, $submission_id );
		}

		do_action( 'fc_after_submission_saved', $form, $payload, $submission_id, $is_spam );

		return $this->respond( $request, $form, $submission_id );
	}

	/**
	 * Decide whether to return JSON or issue a redirect, based on how
	 * the request came in. Plain HTML <form> posts get a redirect
	 * (browser needs a navigable response); fetch/AJAX callers get JSON.
	 *
	 * @param WP_REST_Request $request
	 * @param object           $form
	 * @param int|null         $submission_id
	 * @return WP_REST_Response|void Exits directly for redirects.
	 */
	private function respond( WP_REST_Request $request, $form, $submission_id ) {
		$wants_json = $this->wants_json( $request );

		if ( $wants_json ) {
			return new WP_REST_Response(
				array(
					'success'       => true,
					'submission_id' => $submission_id,
				),
				200
			);
		}

		$settings     = $form->settings_arr;
		$redirect_url = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';

		if ( ! $redirect_url ) {
			$referer      = $request->get_header( 'referer' );
			$redirect_url = $referer ? add_query_arg( 'submitted', '1', $referer ) : home_url( '/?submitted=1' );
		}

		$redirect_url = apply_filters( 'fc_redirect_url', $redirect_url, $form );

		wp_safe_redirect( esc_url_raw( $redirect_url ), 302 );
		exit;
	}

	/**
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	private function wants_json( WP_REST_Request $request ) {
		$accept       = (string) $request->get_header( 'accept' );
		$requested_with = (string) $request->get_header( 'x-requested-with' );
		$content_type = (string) $request->get_header( 'content-type' );

		if ( false !== stripos( $accept, 'application/json' ) ) {
			return true;
		}
		if ( false !== stripos( $requested_with, 'xmlhttprequest' ) ) {
			return true;
		}
		if ( false !== stripos( $content_type, 'application/json' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check the request's Origin/Referer host against a form's allowed-domains
	 * list. Empty list = unrestricted. When restricted but no origin info is
	 * present, the request is rejected (fail closed).
	 *
	 * @param object          $form
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	private function is_domain_allowed( $form, $request ) {
		$raw = trim( (string) $form->settings_arr['allowed_domains'] );
		if ( '' === $raw ) {
			return true;
		}

		$allowed = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		if ( empty( $allowed ) ) {
			return true;
		}

		$hosts = array();
		foreach ( array( 'origin', 'referer' ) as $header ) {
			$val = (string) $request->get_header( $header );
			if ( $val ) {
				$host = wp_parse_url( $val, PHP_URL_HOST );
				if ( $host ) {
					$hosts[] = strtolower( $host );
				}
			}
		}

		if ( empty( $hosts ) ) {
			return false; // Restricted, but caller sent no origin info.
		}

		foreach ( $hosts as $host ) {
			foreach ( $allowed as $domain ) {
				$domain = strtolower( ltrim( $domain, '.' ) );
				// Exact host, or a subdomain of an allowed domain.
				if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param string $token
	 * @return object|null
	 */
	private function get_form_by_token( $token ) {
		global $wpdb;
		$table = FC_DB::forms_table();

		$form = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s LIMIT 1", $token ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! $form ) {
			return null;
		}

		$form->settings_arr = FC_Helpers::merge_settings( json_decode( $form->settings, true ) );

		return $form;
	}

	/**
	 * @param object $form
	 * @param array  $payload
	 * @param bool   $is_spam
	 * @return int Insert ID.
	 */
	private function store_submission( $form, $payload, $is_spam ) {
		global $wpdb;
		$table = FC_DB::submissions_table();

		$wpdb->insert(
			$table,
			array(
				'form_id'    => $form->id,
				'payload'    => wp_json_encode( $payload ),
				'ip'         => FC_Helpers::get_client_ip(),
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'referrer'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				'status'     => $is_spam ? 'spam' : 'new',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Simple transient-based rate limit: N submissions per IP per form
	 * per rolling minute. Not bulletproof, but stops naive flooding
	 * without adding an external dependency.
	 *
	 * @param int $form_id
	 * @return bool
	 */
	private function is_rate_limited( $form_id ) {
		$limit = (int) get_option( 'fc_rate_limit_per_minute', 10 );
		if ( $limit <= 0 ) {
			return false; // 0 or negative disables rate limiting.
		}

		$ip  = FC_Helpers::get_client_ip();
		$key = 'fc_rl_' . md5( $form_id . '|' . $ip );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}
}
