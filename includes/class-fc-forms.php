<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central form service: the single source of truth for validating and
 * sanitizing a form's settings, and for creating / updating / reading /
 * deleting / importing / exporting forms.
 *
 * Every path that writes a form — the admin UI, the REST write API, and JSON
 * import — funnels through here, so validation can never drift between them.
 */
class FC_Forms {

	/**
	 * Sanitize a raw (already-unslashed) settings array into a complete,
	 * clean settings array.
	 *
	 * @param array      $input    Raw settings (assoc array; e.g. from $_POST or a JSON body).
	 * @param array|null $existing Existing settings, for updates.
	 * @param bool       $partial  When true (PATCH-style), keys absent from $input keep their
	 *                             existing value. When false (full save/create), absent booleans
	 *                             become false and absent strings fall back to defaults.
	 * @return array
	 */
	public static function sanitize_settings( $input, $existing = null, $partial = false ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = FC_Helpers::default_form_settings();
		$base     = is_array( $existing ) ? wp_parse_args( $existing, $defaults ) : $defaults;

		$out = $base;

		$bool_keys = array( 'save_to_db', 'notify_email', 'notify_on_spam', 'require_captcha', 'allow_uploads' );
		foreach ( $bool_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			} elseif ( ! $partial ) {
				$out[ $key ] = false;
			}
		}

		if ( array_key_exists( 'emails', $input ) ) {
			$out['emails'] = self::sanitize_emails( $input['emails'] );
		} elseif ( ! $partial ) {
			$out['emails'] = $base['emails'];
		}
		if ( empty( $out['emails'] ) ) {
			$out['emails'] = array( FC_Helpers::default_email() );
		}

		$text_keys = array(
			'email_from_name'    => 'sanitize_text_field',
			'email_from_address' => 'sanitize_email',
			'redirect_url'       => 'esc_url_raw',
			'honeypot_field'     => 'sanitize_key',
		);
		foreach ( $text_keys as $key => $fn ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = call_user_func( $fn, (string) $input[ $key ] );
			} elseif ( ! $partial ) {
				$out[ $key ] = $base[ $key ];
			}
		}

		if ( array_key_exists( 'webhook_url', $input ) ) {
			$out['webhook_url'] = self::sanitize_lines( $input['webhook_url'], 'esc_url_raw' );
		} elseif ( ! $partial ) {
			$out['webhook_url'] = $base['webhook_url'];
		}

		if ( array_key_exists( 'allowed_domains', $input ) ) {
			$out['allowed_domains'] = self::sanitize_domains( $input['allowed_domains'] );
		} elseif ( ! $partial ) {
			$out['allowed_domains'] = $base['allowed_domains'];
		}

		if ( array_key_exists( 'captcha_v3_badge', $input ) ) {
			$out['captcha_v3_badge'] = FC_Captcha::normalize_badge( sanitize_key( (string) $input['captcha_v3_badge'] ) );
		} elseif ( ! $partial ) {
			$out['captcha_v3_badge'] = $base['captcha_v3_badge'];
		}

		// Honeypot must never be empty: keep existing, else generate one.
		if ( empty( $out['honeypot_field'] ) ) {
			$out['honeypot_field'] = ! empty( $base['honeypot_field'] )
				? $base['honeypot_field']
				: FC_Helpers::generate_honeypot_field();
		}

		// Pro-only settings can't be stored as "on" on a free install, so the
		// saved state never lies about what will actually run. Runtime enforces
		// this too; clamping here keeps admin, REST, and import consistent.
		// Only clamp keys actually present (respects partial/PATCH updates).
		if ( ! FC_Helpers::is_pro() ) {
			if ( array_key_exists( 'require_captcha', $input ) || ! $partial ) {
				$out['require_captcha'] = false;
			}
			if ( array_key_exists( 'allow_uploads', $input ) || ! $partial ) {
				$out['allow_uploads'] = false;
			}
			if ( array_key_exists( 'webhook_url', $input ) || ! $partial ) {
				$out['webhook_url'] = '';
			}
		}

		return $out;
	}

	/**
	 * Validate a settings array (post-sanitization). Returns true or a
	 * WP_Error whose data carries a per-field 'errors' map for API callers.
	 *
	 * @param array $settings
	 * @return true|WP_Error
	 */
	public static function validate_settings( $settings ) {
		$errors = array();

		// A form can't require CAPTCHA unless a provider + keys are configured
		// globally, or the requirement would silently do nothing.
		if ( ! empty( $settings['require_captcha'] ) && ! FC_Captcha::is_configured() ) {
			$errors['require_captcha'] = __( 'CAPTCHA is not configured. Set a provider and keys in FormCatch Settings before requiring CAPTCHA on a form.', 'formcatch' );
		}

		if ( ! empty( $settings['email_from_address'] ) && ! is_email( $settings['email_from_address'] ) ) {
			$errors['email_from_address'] = __( 'From address is not a valid email.', 'formcatch' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'fc_invalid_form',
				__( 'One or more form settings are invalid.', 'formcatch' ),
				array( 'status' => 422, 'errors' => $errors )
			);
		}

		return true;
	}

	/* -----------------------------------------------------------------
	 * Field sanitizers (shared)
	 * --------------------------------------------------------------- */

	/**
	 * Sanitize the emails[] array into clean to/subject/body/format rules.
	 * Rows without a recipient are dropped.
	 *
	 * @param mixed $raw
	 * @return array
	 */
	public static function sanitize_emails( $raw ) {
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
				continue;
			}

			$format = ( isset( $row['format'] ) && 'html' === $row['format'] ) ? 'html' : 'plain';
			$body   = isset( $row['body'] ) ? $row['body'] : '';
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
	 * Sanitize a list value (newline or comma separated, or an array) into a
	 * newline-separated string, applying $fn to each item and dropping empties.
	 *
	 * @param mixed    $raw
	 * @param callable $fn
	 * @return string
	 */
	public static function sanitize_lines( $raw, $fn ) {
		if ( is_array( $raw ) ) {
			$items = $raw;
		} else {
			$items = preg_split( '/[\r\n,]+/', (string) $raw );
		}
		$items = array_filter( array_map( 'trim', (array) $items ) );
		$items = array_filter( array_map( $fn, $items ) );

		return implode( "\n", array_values( array_unique( $items ) ) );
	}

	/**
	 * Sanitize allowed-domains input into a newline list of bare hostnames.
	 *
	 * @param mixed $raw
	 * @return string
	 */
	public static function sanitize_domains( $raw ) {
		if ( is_array( $raw ) ) {
			$lines = $raw;
		} else {
			$lines = preg_split( '/[\r\n,]+/', (string) $raw );
		}
		$lines = array_filter( array_map( 'trim', (array) $lines ) );

		$hosts = array();
		foreach ( $lines as $line ) {
			$host = wp_parse_url( false !== strpos( $line, '//' ) ? $line : 'https://' . $line, PHP_URL_HOST );
			$host = $host ? strtolower( $host ) : strtolower( preg_replace( '#^www\.#', '', $line ) );
			$host = sanitize_text_field( $host );
			if ( $host ) {
				$hosts[] = $host;
			}
		}

		return implode( "\n", array_unique( $hosts ) );
	}

	/* -----------------------------------------------------------------
	 * CRUD
	 * --------------------------------------------------------------- */

	/**
	 * @param int $id
	 * @return object|null Form row with ->settings_arr decoded.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = FC_DB::forms_table();
		$form  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $form ) {
			$form->settings_arr = FC_Helpers::merge_settings( json_decode( $form->settings, true ) );
		}

		return $form;
	}

	/**
	 * Create a form. Token is always server-generated.
	 *
	 * @param string $name
	 * @param array  $settings Already-sanitized settings.
	 * @param string $status   'active' | 'disabled'.
	 * @return int New form id.
	 */
	public static function create( $name, $settings, $status = 'active' ) {
		global $wpdb;

		// Retry on the (near-impossible) event that a concurrent insert grabs
		// the same token between our uniqueness check and this insert — the
		// UNIQUE key makes the duplicate insert fail rather than clobber.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$ok = $wpdb->insert(
				FC_DB::forms_table(),
				array(
					'name'       => '' !== trim( $name ) ? $name : __( 'Untitled form', 'formcatch' ),
					'token'      => FC_Helpers::generate_unique_token(),
					'settings'   => wp_json_encode( $settings ),
					'status'     => 'disabled' === $status ? 'disabled' : 'active',
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false !== $ok ) {
				return (int) $wpdb->insert_id;
			}
		}

		return 0;
	}

	/**
	 * Update a form's name/settings/status.
	 *
	 * @param int    $id
	 * @param string $name
	 * @param array  $settings
	 * @param string $status
	 * @return void
	 */
	public static function update( $id, $name, $settings, $status ) {
		global $wpdb;

		$wpdb->update(
			FC_DB::forms_table(),
			array(
				'name'       => '' !== trim( $name ) ? $name : __( 'Untitled form', 'formcatch' ),
				'settings'   => wp_json_encode( $settings ),
				'status'     => 'disabled' === $status ? 'disabled' : 'active',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int $id
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( FC_DB::forms_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* -----------------------------------------------------------------
	 * API shaping / import-export
	 * --------------------------------------------------------------- */

	/**
	 * Full API representation of a form, including its ready-to-use endpoint
	 * and a copy-paste HTML example so an agent knows exactly what to embed.
	 *
	 * @param object $form Row (with ->settings_arr, or raw settings).
	 * @return array
	 */
	public static function to_api_array( $form ) {
		$settings = isset( $form->settings_arr )
			? $form->settings_arr
			: FC_Helpers::merge_settings( json_decode( $form->settings, true ) );

		$endpoint = FC_Helpers::submit_url( $form->token );

		return array(
			'id'           => (int) $form->id,
			'name'         => $form->name,
			'status'       => $form->status,
			'token'        => $form->token,
			'endpoint_url' => $endpoint,
			'example_html' => self::example_html( $endpoint, $settings['honeypot_field'] ),
			'settings'     => $settings,
			'created_at'   => $form->created_at,
			'updated_at'   => $form->updated_at,
		);
	}

	/**
	 * Portable export: config only, no internal id/token/timestamps. Suitable
	 * for import into another site or re-creation by an agent.
	 *
	 * @param object $form
	 * @return array
	 */
	public static function to_export_array( $form ) {
		$settings = isset( $form->settings_arr )
			? $form->settings_arr
			: FC_Helpers::merge_settings( json_decode( $form->settings, true ) );

		return array(
			'name'     => $form->name,
			'status'   => $form->status,
			'settings' => $settings,
		);
	}

	/**
	 * A minimal HTML form example wired to the endpoint.
	 *
	 * @param string $endpoint
	 * @param string $honeypot
	 * @return string
	 */
	private static function example_html( $endpoint, $honeypot ) {
		return sprintf(
			"<form action=\"%s\" method=\"post\">\n  <input type=\"text\" name=\"name\" required>\n  <input type=\"email\" name=\"email\" required>\n  <textarea name=\"message\"></textarea>\n  <input type=\"text\" name=\"%s\" style=\"display:none\" tabindex=\"-1\" autocomplete=\"off\">\n  <button type=\"submit\">Send</button>\n</form>",
			$endpoint,
			$honeypot
		);
	}

	/**
	 * Machine-readable description of the form config, so an AI/agent can
	 * construct a valid form without reading prose docs.
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'form'     => array(
				'name'   => array( 'type' => 'string', 'required' => true, 'description' => 'Human label, not shown to submitters.' ),
				'status' => array( 'type' => 'string', 'enum' => array( 'active', 'disabled' ), 'default' => 'active' ),
			),
			'settings' => array(
				'save_to_db'         => array( 'type' => 'boolean', 'default' => true ),
				'notify_email'       => array( 'type' => 'boolean', 'default' => false, 'description' => 'Master switch for the emails[] rules.' ),
				'emails'             => array(
					'type'  => 'array',
					'items' => array(
						'to'             => array( 'type' => 'string', 'description' => 'Comma-separated recipients. Supports {{tokens}}; use {{email}} for an autoresponder.' ),
						'subject'        => array( 'type' => 'string', 'description' => 'Supports {{tokens}}.' ),
						'body'           => array( 'type' => 'string', 'description' => 'Supports {{tokens}}: {{field_name}}, {{form_name}}, {{submission_id}}, {{all_fields}}, {{files}}.' ),
						'format'         => array( 'type' => 'string', 'enum' => array( 'plain', 'html' ), 'default' => 'plain' ),
						'reply_to_field' => array( 'type' => 'string', 'description' => 'Name of a submitted field holding the visitor email to set as Reply-To.' ),
					),
				),
				'email_from_name'    => array( 'type' => 'string', 'default' => '' ),
				'email_from_address' => array( 'type' => 'string', 'format' => 'email', 'default' => '' ),
				'redirect_url'       => array( 'type' => 'string', 'format' => 'url', 'default' => '', 'description' => 'Redirect target for non-JSON form posts.' ),
				'honeypot_field'     => array( 'type' => 'string', 'description' => 'Hidden anti-spam field name. Auto-generated if omitted.' ),
				'webhook_url'        => array( 'type' => 'string', 'description' => 'One URL per line (or comma separated). POSTed as JSON per submission.' ),
				'allowed_domains'    => array( 'type' => 'string', 'description' => 'One host per line. If set, only submissions from these origins are accepted.' ),
				'notify_on_spam'     => array( 'type' => 'boolean', 'default' => false ),
				'require_captcha'    => array( 'type' => 'boolean', 'default' => false, 'requires' => 'A CAPTCHA provider + keys configured in Settings, else creation fails with 422.' ),
				'captcha_v3_badge'   => array( 'type' => 'string', 'enum' => array( 'bottomright', 'bottomleft', 'inline' ), 'default' => 'bottomright' ),
				'allow_uploads'      => array( 'type' => 'boolean', 'default' => false ),
			),
		);
	}
}
