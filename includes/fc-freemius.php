<?php
/**
 * Freemius SDK bootstrap.
 *
 * Registers FormCatch with Freemius so a paid license unlocks the Pro
 * features (CAPTCHA, file uploads, webhooks, and the REST/Forms API). The free
 * build and the Pro build ship the same code; gating is done at runtime via
 * FC_Helpers::can_use() / for_fs()->can_use_premium_code().
 *
 * Local testing: add these to the site's wp-config.php (NOT here — the secret
 * key must never be committed or shipped):
 *   define( 'WP_FS__DEV_MODE', true );
 *   define( 'WP_FS__SKIP_EMAIL_ACTIVATION', true );
 *   define( 'WP_FS__formcatch_SECRET_KEY', '<your secret key>' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'for_fs' ) ) {
	// Create a helper function for easy SDK access.
	function for_fs() {
		global $for_fs;

		if ( ! isset( $for_fs ) ) {
			$sdk = FC_PLUGIN_DIR . 'vendor/freemius/start.php';

			// SDK not present — run as free tier, never fatal.
			if ( ! file_exists( $sdk ) ) {
				$for_fs = null;
				return null;
			}

			// Include Freemius SDK.
			require_once $sdk;

			$for_fs = fs_dynamic_init(
				array(
					'id'                  => '33969',
					'slug'                => 'formcatch',
					'type'                => 'plugin',
					'public_key'          => 'pk_4f5b9d3e331d208189d347734a5f1',
					'is_premium'          => true,
					'premium_suffix'      => 'Pro',
					// If your plugin is a serviceware, set this option to false.
					'has_premium_version' => true,
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'is_org_compliant'    => true,
					// Automatically removed in the free version. If you're not using
					// the auto-generated free version, delete this line before
					// uploading to wp.org.
					'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
					'menu'                => array(
						// FormCatch's top-level menu slug, so Freemius nests its
						// Account / Upgrade pages under FormCatch (not at the top).
						'slug'    => 'formcatch-forms',
						'support' => false,
					),
				)
			);
		}

		return $for_fs;
	}

	// Init Freemius.
	for_fs();
	// Signal that SDK was initiated.
	do_action( 'for_fs_loaded' );
}
