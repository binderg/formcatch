<?php
/**
 * Plugin Name:       FormCatch
 * Plugin URI:        https://formcatch.dev/
 * Description:       A self-hosted form backend. Point any plain HTML/PHP form at a generated endpoint and FormCatch stores, searches, and (optionally) emails every submission — no shortcodes, no form builder.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            GStudios
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formcatch
 * Domain Path:       /languages
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FC_VERSION', '1.3.0' );
define( 'FC_DB_VERSION', '1.2.0' );
define( 'FC_PLUGIN_FILE', __FILE__ );
define( 'FC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Class autoload / includes.
 */
require_once FC_PLUGIN_DIR . 'includes/class-fc-helpers.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-db.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-forms.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-activator.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-logger.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-uploads.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-disposable.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-captcha.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-mailer.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-webhook.php';
require_once FC_PLUGIN_DIR . 'includes/class-fc-rest-controller.php';

if ( is_admin() ) {
	require_once FC_PLUGIN_DIR . 'includes/class-fc-admin.php';
}

/**
 * Activation / deactivation hooks.
 */
register_activation_hook( FC_PLUGIN_FILE, array( 'FC_Activator', 'activate' ) );

/**
 * Schedule / tear down the daily log-pruning cron.
 */
register_activation_hook(
	FC_PLUGIN_FILE,
	function () {
		if ( ! wp_next_scheduled( 'fc_daily_prune_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fc_daily_prune_logs' );
		}
	}
);
register_deactivation_hook(
	FC_PLUGIN_FILE,
	function () {
		wp_clear_scheduled_hook( 'fc_daily_prune_logs' );
	}
);
add_action( 'fc_daily_prune_logs', array( 'FC_Logger', 'prune' ) );

/**
 * Daily submission pruning. Off by default (retention 0 = keep forever) so
 * nobody loses leads by accident — only runs when the site owner sets a
 * retention window in Settings.
 */
function fc_prune_submissions() {
	global $wpdb;

	$days = (int) get_option( 'fc_submission_retention_days', 0 );
	if ( $days <= 0 ) {
		return;
	}

	$table  = FC_DB::submissions_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

	// Remove attached files from disk before deleting the submission rows.
	$expired_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! empty( $expired_ids ) ) {
		FC_Uploads::delete_for_submissions( $expired_ids );
	}

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
add_action( 'fc_daily_prune_logs', 'fc_prune_submissions' );

/**
 * Load translations.
 */
function fc_load_textdomain() {
	load_plugin_textdomain( 'formcatch', false, dirname( FC_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'fc_load_textdomain' );

/**
 * Check DB version on every load in case of upgrades (covers updates that
 * happen outside the activation hook, e.g. auto-updates).
 */
function fc_maybe_upgrade_db() {
	$installed = get_option( 'fc_db_version', '0' );
	if ( version_compare( $installed, FC_DB_VERSION, '<' ) ) {
		FC_Activator::activate();
	}
}
add_action( 'plugins_loaded', 'fc_maybe_upgrade_db', 5 );

/**
 * Run one-time tasks whenever the plugin version changes (i.e. after an
 * update). Refreshing the disposable-email blocklist here keeps it current on
 * every release without shipping a stale copy. Scheduled async so a slow
 * remote fetch never blocks the request that noticed the update.
 */
function fc_maybe_after_update() {
	$installed = get_option( 'fc_installed_version', '0' );
	if ( FC_VERSION === $installed ) {
		return;
	}

	update_option( 'fc_installed_version', FC_VERSION );

	// Defer the network fetch to a one-off cron tick.
	if ( ! wp_next_scheduled( 'fc_refresh_disposable' ) ) {
		wp_schedule_single_event( time() + 30, 'fc_refresh_disposable' );
	}
}
add_action( 'plugins_loaded', 'fc_maybe_after_update', 6 );
add_action( 'fc_refresh_disposable', array( 'FC_Disposable', 'refresh' ) );

/**
 * Bootstrap REST + Admin.
 */
function fc_init_plugin() {
	new FC_REST_Controller();

	if ( is_admin() ) {
		new FC_Admin();
	}
}
add_action( 'init', 'fc_init_plugin' );
