<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CAPTCHA verification for Cloudflare Turnstile and Google reCAPTCHA
 * (v2 checkbox / v3 invisible). Keys are configured once globally (like
 * Elementor's Integrations tab); each form opts in with a per-form toggle.
 *
 * Because FormCatch forms are raw HTML the site owner controls, we can't
 * auto-inject a widget — instead widget_html() gives them the markup to
 * paste, and verify() validates the token the widget adds on submit.
 */
class FC_Captcha {

	/**
	 * Per-provider config: the POST field the widget writes its token to,
	 * and the provider's server-side verify endpoint.
	 *
	 * @return array
	 */
	private static function providers() {
		return array(
			'turnstile'    => array(
				'field'  => 'cf-turnstile-response',
				'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			),
			'hcaptcha'     => array(
				'field'  => 'h-captcha-response',
				'verify' => 'https://api.hcaptcha.com/siteverify',
			),
			'recaptcha_v2' => array(
				'field'  => 'g-recaptcha-response',
				'verify' => 'https://www.google.com/recaptcha/api/siteverify',
			),
			'recaptcha_v3' => array(
				'field'  => 'g-recaptcha-response',
				'verify' => 'https://www.google.com/recaptcha/api/siteverify',
			),
		);
	}

	/**
	 * @return string Configured provider, or 'none'.
	 */
	public static function provider() {
		return (string) get_option( 'fc_captcha_provider', 'none' );
	}

	/**
	 * Normalize a reCAPTCHA v3 badge position value. 'bottomright'
	 * (default), 'bottomleft', or 'inline' (hide the floating badge, show
	 * the required attribution text near the button instead). This is a
	 * per-form presentation setting, not global.
	 *
	 * @param string $badge
	 * @return string
	 */
	public static function normalize_badge( $badge ) {
		return in_array( $badge, array( 'bottomright', 'bottomleft', 'inline' ), true ) ? $badge : 'bottomright';
	}

	/**
	 * Whether a provider is configured with both keys present.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$provider = self::provider();
		if ( 'none' === $provider || ! isset( self::providers()[ $provider ] ) ) {
			return false;
		}
		return '' !== trim( (string) get_option( 'fc_captcha_site_key', '' ) )
			&& '' !== trim( (string) get_option( 'fc_captcha_secret_key', '' ) );
	}

	/**
	 * Verify the CAPTCHA token on an incoming submission.
	 *
	 * @param object          $form
	 * @param WP_REST_Request $request
	 * @return true|WP_Error True when it passes (or isn't required); WP_Error otherwise.
	 */
	public static function verify( $form, $request ) {
		$settings = $form->settings_arr;

		// Not required for this form, or not configured globally → pass.
		if ( empty( $settings['require_captcha'] ) || ! self::is_configured() ) {
			return true;
		}

		$provider = self::provider();
		$config   = self::providers()[ $provider ];

		$params = (array) $request->get_body_params();
		$token  = isset( $params[ $config['field'] ] ) ? trim( (string) $params[ $config['field'] ] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'fc_captcha_missing', __( 'CAPTCHA response missing.', 'formcatch' ) );
		}

		$response = wp_remote_post(
			$config['verify'],
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => (string) get_option( 'fc_captcha_secret_key', '' ),
					'response' => $token,
					'remoteip' => FC_Helpers::get_client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fc_captcha_unreachable', $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			$codes = ( is_array( $data ) && ! empty( $data['error-codes'] ) ) ? implode( ', ', (array) $data['error-codes'] ) : '';
			return new WP_Error( 'fc_captcha_failed', $codes ? $codes : __( 'CAPTCHA verification failed.', 'formcatch' ) );
		}

		// reCAPTCHA v3 returns a 0..1 score — enforce the configured threshold.
		if ( 'recaptcha_v3' === $provider && isset( $data['score'] ) ) {
			$threshold = (float) get_option( 'fc_captcha_v3_threshold', 0.5 );
			if ( (float) $data['score'] < $threshold ) {
				return new WP_Error(
					'fc_captcha_low_score',
					/* translators: 1: score, 2: threshold */
					sprintf( __( 'CAPTCHA score %1$.2f below threshold %2$.2f.', 'formcatch' ), (float) $data['score'], $threshold )
				);
			}
		}

		return true;
	}

	/**
	 * Just the CAPTCHA markup to drop into a form (widget for v2/Turnstile,
	 * invisible script + hidden field for v3). Empty when not configured.
	 *
	 * @param string $badge reCAPTCHA v3 badge position (per-form). Ignored
	 *                      by v2/Turnstile.
	 * @return string
	 */
	public static function widget_html( $badge = 'bottomright' ) {
		if ( ! self::is_configured() ) {
			return '';
		}

		$provider = self::provider();
		$site_key = esc_attr( get_option( 'fc_captcha_site_key', '' ) );

		if ( 'turnstile' === $provider ) {
			return "<script src=\"https://challenges.cloudflare.com/turnstile/v0/api.js\" async defer></script>\n"
				. "<div class=\"cf-turnstile\" data-sitekey=\"{$site_key}\"></div>";
		}

		if ( 'hcaptcha' === $provider ) {
			return "<script src=\"https://js.hcaptcha.com/1/api.js\" async defer></script>\n"
				. "<div class=\"h-captcha\" data-sitekey=\"{$site_key}\"></div>";
		}

		if ( 'recaptcha_v2' === $provider ) {
			return "<script src=\"https://www.google.com/recaptcha/api.js\" async defer></script>\n"
				. "<div class=\"g-recaptcha\" data-sitekey=\"{$site_key}\"></div>";
		}

		if ( 'recaptcha_v3' === $provider ) {
			$badge = self::normalize_badge( $badge );

			// Google requires either the floating badge OR visible attribution.
			// "inline" hides the badge and shows the legal text near the button;
			// "bottomleft" nudges the floating badge to the other corner.
			$badge_css = '';
			$attribution = '';
			if ( 'inline' === $badge ) {
				$badge_css   = "<style>.grecaptcha-badge{visibility:hidden;}</style>\n";
				$attribution = "\n<small>This site is protected by reCAPTCHA and the Google "
					. "<a href=\"https://policies.google.com/privacy\">Privacy Policy</a> and "
					. "<a href=\"https://policies.google.com/terms\">Terms of Service</a> apply.</small>";
			} elseif ( 'bottomleft' === $badge ) {
				$badge_css = "<style>.grecaptcha-badge{left:4px!important;right:auto!important;}</style>\n";
			}

			return $badge_css
				. "<script src=\"https://www.google.com/recaptcha/api.js?render={$site_key}\"></script>\n"
				. "<input type=\"hidden\" name=\"g-recaptcha-response\" id=\"g-recaptcha-response\">\n"
				. "<script>\n"
				. "grecaptcha.ready(function(){\n"
				. "  function refresh(){grecaptcha.execute('{$site_key}',{action:'submit'}).then(function(t){document.getElementById('g-recaptcha-response').value=t;});}\n"
				. "  refresh();\n"
				. "  setInterval(refresh, 90000); // v3 tokens expire after ~2 min; refresh so a slow fill still submits.\n"
				. "});\n"
				. "</script>" . $attribution;
		}

		return '';
	}

	/**
	 * A full, copy-pasteable example <form> for the active provider, wired
	 * to the given submit endpoint and including the honeypot field. This is
	 * what we show the site owner so they can drop a working form straight in.
	 *
	 * @param string $submit_url     The form's submit endpoint.
	 * @param string $honeypot_field The form's honeypot field name.
	 * @param string $badge          reCAPTCHA v3 badge position (per-form).
	 * @return string
	 */
	public static function sample_form_html( $submit_url, $honeypot_field, $badge = 'bottomright' ) {
		if ( ! self::is_configured() ) {
			return '';
		}

		$action   = esc_attr( $submit_url );
		$hp       = esc_attr( $honeypot_field );
		$provider = self::provider();
		$widget   = self::widget_html( $badge );

		$fields = "  <input type=\"text\" name=\"name\" required>\n"
			. "  <input type=\"email\" name=\"email\" required>\n"
			. "  <textarea name=\"message\"></textarea>\n\n"
			. "  <!-- honeypot: keep hidden and empty -->\n"
			. "  <input type=\"text\" name=\"{$hp}\" style=\"display:none\" tabindex=\"-1\" autocomplete=\"off\">\n";

		// v3 is invisible: its script/hidden field can live anywhere, so put
		// it just before the button. v2/Turnstile render a visible widget in
		// place, so it sits between the fields and the button too.
		return "<form action=\"{$action}\" method=\"post\">\n"
			. $fields . "\n"
			. self::indent( $widget ) . "\n\n"
			. "  <button type=\"submit\">Send</button>\n"
			. "</form>";
	}

	/**
	 * Indent a multi-line snippet by two spaces so it nests cleanly inside
	 * the sample <form>.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function indent( $html ) {
		$lines = explode( "\n", $html );
		return implode( "\n", array_map( function ( $l ) {
			return '' === $l ? $l : '  ' . $l;
		}, $lines ) );
	}
}
