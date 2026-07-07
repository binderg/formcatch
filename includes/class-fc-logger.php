<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central event log. Every notification attempt (email, webhook) and each
 * received submission is recorded here so the site owner has a searchable,
 * viewable audit trail — and gets alerted when something fails.
 */
class FC_Logger {

	const TYPE_SUBMISSION = 'submission';
	const TYPE_EMAIL      = 'email';
	const TYPE_WEBHOOK    = 'webhook';

	const STATUS_SUCCESS = 'success';
	const STATUS_ERROR   = 'error';
	const STATUS_SKIPPED = 'skipped';

	/**
	 * Write a log row. Errors additionally bump the "unseen errors"
	 * counter that drives the wp-admin notice.
	 *
	 * @param string   $event_type    One of the TYPE_* constants.
	 * @param string   $status        One of the STATUS_* constants.
	 * @param string   $message       Short human-readable summary.
	 * @param array    $context       Extra structured detail (recipient, url, http code, error...).
	 * @param int|null $form_id
	 * @param int|null $submission_id
	 * @return int Insert ID (0 on failure).
	 */
	public static function log( $event_type, $status, $message, $context = array(), $form_id = null, $submission_id = null ) {
		global $wpdb;

		$wpdb->insert(
			FC_DB::logs_table(),
			array(
				'form_id'       => $form_id ? (int) $form_id : null,
				'submission_id' => $submission_id ? (int) $submission_id : null,
				'event_type'    => $event_type,
				'status'        => $status,
				'message'       => mb_substr( (string) $message, 0, 500 ),
				'context'       => $context ? wp_json_encode( $context ) : null,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( self::STATUS_ERROR === $status ) {
			$unseen = (int) get_option( 'fc_log_error_unseen', 0 );
			update_option( 'fc_log_error_unseen', $unseen + 1 );
		}

		return (int) $wpdb->insert_id;
	}

	/* Convenience wrappers ------------------------------------------- */

	public static function email( $status, $message, $context = array(), $form_id = null, $submission_id = null ) {
		return self::log( self::TYPE_EMAIL, $status, $message, $context, $form_id, $submission_id );
	}

	public static function webhook( $status, $message, $context = array(), $form_id = null, $submission_id = null ) {
		return self::log( self::TYPE_WEBHOOK, $status, $message, $context, $form_id, $submission_id );
	}

	public static function submission( $status, $message, $context = array(), $form_id = null, $submission_id = null ) {
		return self::log( self::TYPE_SUBMISSION, $status, $message, $context, $form_id, $submission_id );
	}

	/**
	 * Delete log rows older than the configured retention window. Hooked
	 * on a daily cron so the table never grows without bound.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) get_option( 'fc_log_retention_days', 90 );
		if ( $days <= 0 ) {
			return; // 0 or negative disables pruning.
		}

		$table  = FC_DB::logs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Reset the unseen-error counter (called when the Logs page is viewed).
	 */
	public static function clear_unseen_errors() {
		update_option( 'fc_log_error_unseen', 0 );
	}
}
