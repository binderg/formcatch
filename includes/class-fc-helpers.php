<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small static helper utilities shared across the plugin.
 */
class FC_Helpers {

	/**
	 * The capability required to manage FormCatch. Filterable so a site
	 * owner can delegate access to a non-admin role.
	 *
	 * @return string
	 */
	public static function capability() {
		return apply_filters( 'fc_manage_capability', 'manage_options' );
	}

	/**
	 * Whether this site can use Pro features. Backed by Freemius: true when
	 * running a licensed (paid or trial) Pro install. When the Freemius SDK
	 * isn't present the site is treated as free.
	 *
	 * The $feature argument is currently informational (both Pro features share
	 * one plan) but is accepted so gating can later be split per plan without
	 * touching call sites.
	 *
	 * @param string $feature Optional feature slug: webhooks|api.
	 * @return bool
	 */
	public static function can_use( $feature = '' ) {
		if ( ! function_exists( 'for_fs' ) ) {
			return false;
		}
		$fs = for_fs();
		if ( ! $fs ) {
			return false;
		}
		return (bool) $fs->can_use_premium_code();
	}

	/**
	 * Convenience alias for readability at call sites.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return self::can_use();
	}

	/**
	 * URL to the FormCatch upgrade screen (Freemius-generated), or the public
	 * plans page if the SDK isn't loaded. Safe to use in admin views.
	 *
	 * @return string
	 */
	public static function upgrade_url() {
		if ( function_exists( 'for_fs' ) && for_fs() ) {
			return for_fs()->get_upgrade_url();
		}
		return 'https://formcatch.dev/plans/';
	}

	/**
	 * A small inline "PRO" badge for admin labels.
	 *
	 * @return string HTML.
	 */
	public static function pro_badge() {
		return ' <span class="fc-pro-badge" style="display:inline-block;font-size:10px;font-weight:600;line-height:1;padding:2px 7px;border-radius:3px;background:transparent;border:1px solid #dc2626;color:#dc2626;vertical-align:middle;letter-spacing:.03em;">PRO</span>';
	}

	/**
	 * Upgrade nudge shown under a locked (Pro) admin field. Returns nothing on
	 * a licensed Pro install so views can print it unconditionally.
	 *
	 * @param string $label Human name of the feature, e.g. "File uploads".
	 * @return string HTML.
	 */
	public static function pro_lock_notice( $label ) {
		if ( self::is_pro() ) {
			return '';
		}
		return sprintf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html( sprintf(
				/* translators: %s: feature name */
				__( '%s is a FormCatch Pro feature.', 'formcatch' ),
				$label
			) ),
			esc_url( self::upgrade_url() ),
			esc_html__( 'Upgrade to unlock', 'formcatch' )
		);
	}

	/**
	 * Generate a URL-safe random token for a form's submit endpoint.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return substr( wp_generate_password( 32, false, false ), 0, 24 );
	}

	/**
	 * Generate a token guaranteed not to already exist in the forms table.
	 * A 24-char token collision is astronomically unlikely, but with
	 * programmatic/bulk creation we check anyway; the table's UNIQUE key on
	 * token is the hard backstop against a concurrent race.
	 *
	 * @return string
	 */
	public static function generate_unique_token() {
		global $wpdb;
		$table = FC_DB::forms_table();

		for ( $i = 0; $i < 5; $i++ ) {
			$token  = self::generate_token();
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE token = %s LIMIT 1", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $exists ) {
				return $token;
			}
		}

		// Extremely improbable fallback: widen the token.
		return substr( wp_generate_password( 40, false, false ), 0, 32 );
	}

	/**
	 * Generate a honeypot field name. Kept configurable per-form so a
	 * scraped/shared theme doesn't make every FormCatch site guessable
	 * with the same field name.
	 *
	 * @return string
	 */
	public static function generate_honeypot_field() {
		return 'fc_hp_' . substr( wp_generate_password( 8, false, false ), 0, 8 );
	}

	/**
	 * Get the full public submit URL for a given token.
	 *
	 * @param string $token Form token.
	 * @return string
	 */
	public static function submit_url( $token ) {
		return rest_url( 'formcatch/v1/submit/' . $token );
	}

	/**
	 * Default per-form settings.
	 *
	 * @return array
	 */
	public static function default_form_settings() {
		return array(
			'save_to_db'     => true,
			'notify_email'   => false,
			'emails'         => array( self::default_email() ),
			'email_from_name'    => '',
			'email_from_address' => '',
			'redirect_url'   => '',
			'honeypot_field' => self::generate_honeypot_field(),
			'webhook_url'    => '',
			'notify_on_spam' => false,
			'require_captcha' => false,
			'captcha_v3_badge' => 'bottomright',
			'allowed_domains' => '',
			'allow_uploads'   => false,
		);
	}

	/**
	 * A single default email rule (recipient + subject + body + format).
	 *
	 * @return array
	 */
	public static function default_email() {
		return array(
			'to'             => get_option( 'admin_email' ),
			'subject'        => __( 'New form submission — {{form_name}}', 'formcatch' ),
			'body'           => "New submission for {{form_name}}:\n\n{{all_fields}}",
			'format'         => 'plain',
			'reply_to_field' => '',
		);
	}

	/**
	 * Merge saved settings with defaults so new setting keys added in
	 * future versions don't break older stored forms.
	 *
	 * Also migrates the legacy single-recipient fields (notify_to /
	 * notify_subject) into the newer emails[] array so forms saved before
	 * multi-email support keep working.
	 *
	 * @param array $settings Stored settings.
	 * @return array
	 */
	public static function merge_settings( $settings ) {
		$settings = (array) $settings;

		// Legacy migration: pre-emails[] forms only had notify_to/notify_subject.
		if ( ! isset( $settings['emails'] ) && ( isset( $settings['notify_to'] ) || isset( $settings['notify_subject'] ) ) ) {
			$settings['emails'] = array(
				array(
					'to'      => isset( $settings['notify_to'] ) ? $settings['notify_to'] : get_option( 'admin_email' ),
					'subject' => isset( $settings['notify_subject'] ) ? $settings['notify_subject'] : __( 'New form submission', 'formcatch' ),
					'body'    => "New submission for {{form_name}}:\n\n{{all_fields}}",
					'format'  => 'plain',
				),
			);
		}

		$merged = wp_parse_args( $settings, self::default_form_settings() );

		// Guarantee emails is always a non-empty, well-shaped list.
		if ( empty( $merged['emails'] ) || ! is_array( $merged['emails'] ) ) {
			$merged['emails'] = array( self::default_email() );
		} else {
			$merged['emails'] = array_map(
				function ( $email ) {
					return wp_parse_args( (array) $email, self::default_email() );
				},
				array_values( $merged['emails'] )
			);
		}

		return $merged;
	}

	/**
	 * Get the client IP address, respecting common proxy headers.
	 * Best-effort only — do not rely on this for hard security decisions.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For can be a comma separated list; take the first.
				if ( false !== strpos( $ip, ',' ) ) {
					$parts = explode( ',', $ip );
					$ip    = trim( $parts[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Recursively sanitize an array of submitted form data for storage.
	 * Keeps it schema-less (any field name/shape) but strips tags and
	 * null bytes.
	 *
	 * @param mixed $data Raw data.
	 * @return mixed
	 */
	public static function sanitize_payload( $data ) {
		if ( is_array( $data ) ) {
			return array_map( array( __CLASS__, 'sanitize_payload' ), $data );
		}
		return sanitize_textarea_field( wp_unslash( (string) $data ) );
	}
}
