<?php
/**
 * Freemius SDK bootstrap.
 *
 * Registers FormCatch with Freemius so a paid license unlocks the Pro
 * features (CAPTCHA, file uploads, webhooks, and the REST/Forms API).
 * The free build and the Pro build ship the same code; gating is done at
 * runtime via FC_Helpers::can_use() / fc_fs()->can_use_premium_code().
 *
 * TO FINISH SETUP:
 *   1. Create the plugin in your Freemius dashboard (Plugins > add plugin).
 *   2. Freemius will hand you an `id`, `public_key`, and a downloadable copy
 *      of the plugin with the SDK bundled at vendor/freemius/. Drop that
 *      vendor/freemius/ folder in alongside this plugin (or run
 *      `composer require freemius/wordpress-sdk`).
 *   3. Paste the id + public_key into the placeholders below.
 *
 * Until those are filled in (or the SDK is missing) this file no-ops and the
 * whole plugin behaves as the free tier — nothing fatals.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'fc_fs' ) ) {

	/**
	 * Create / fetch the Freemius instance.
	 *
	 * @return Freemius|null Null when the SDK isn't installed yet.
	 */
	function fc_fs() {
		global $fc_fs;

		if ( isset( $fc_fs ) ) {
			return $fc_fs;
		}

		$sdk = FC_PLUGIN_DIR . 'vendor/freemius/start.php';

		// SDK not present yet — run as free tier, never fatal.
		if ( ! file_exists( $sdk ) ) {
			$fc_fs = null;
			return null;
		}

		require_once $sdk;

		$fc_fs = fs_dynamic_init(
			array(
				'id'                  => 'FREEMIUS_PLUGIN_ID',   // <-- from Freemius dashboard.
				'slug'                => 'formcatch',
				'type'                => 'plugin',
				'public_key'          => 'FREEMIUS_PUBLIC_KEY',  // <-- from Freemius dashboard.
				'is_premium'          => true,
				// One codebase serves both tiers; gate at runtime, don't strip code.
				'is_premium_only'     => false,
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'menu'                => array(
					'slug'    => 'formcatch-forms', // FormCatch top-level menu slug.
					'first-path' => 'admin.php?page=formcatch-forms',
					'account' => true,
					'contact' => false, // No support desk — solo project.
					'support' => false,
				),
				'is_org_compliant'    => true, // Free version is distributed on WordPress.org.
			)
		);

		return $fc_fs;
	}

	// Init as early as possible, then let the rest of the plugin know.
	fc_fs();
	do_action( 'fc_fs_loaded' );
}
