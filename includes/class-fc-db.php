<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for table names so we never hardcode a prefix elsewhere.
 */
class FC_DB {

	/**
	 * @return string
	 */
	public static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'fc_forms';
	}

	/**
	 * @return string
	 */
	public static function submissions_table() {
		global $wpdb;
		return $wpdb->prefix . 'fc_submissions';
	}

	/**
	 * @return string
	 */
	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'fc_logs';
	}

	/**
	 * @return string
	 */
	public static function files_table() {
		global $wpdb;
		return $wpdb->prefix . 'fc_files';
	}
}
