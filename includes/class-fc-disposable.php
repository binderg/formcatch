<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disposable / temporary email domain blocking.
 *
 * The blocklist is sourced from the community-maintained
 * disposable-email-domains project and cached in an option. It's refreshed
 * automatically whenever the plugin updates (and can be refreshed manually
 * from Settings). A small bundled fallback keeps blocking working before the
 * first successful fetch or if the remote is unreachable.
 */
class FC_Disposable {

	const OPTION_LIST    = 'fc_disposable_domains';
	const OPTION_UPDATED = 'fc_disposable_updated';
	const SOURCE_URL     = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';

	/**
	 * Minimal seed list so blocking is meaningful even before the first
	 * remote fetch. The full list (thousands of domains) replaces this.
	 *
	 * @return string[]
	 */
	private static function fallback() {
		return array(
			'mailinator.com',
			'guerrillamail.com',
			'10minutemail.com',
			'tempmail.com',
			'temp-mail.org',
			'throwawaymail.com',
			'yopmail.com',
			'trashmail.com',
			'getnada.com',
			'dispostable.com',
			'maildrop.cc',
			'fakeinbox.com',
			'sharklasers.com',
			'guerrillamailblock.com',
			'mailnesia.com',
		);
	}

	/**
	 * The active blocklist as a lookup map (domain => true) for O(1) checks.
	 *
	 * @return array
	 */
	private static function map() {
		$stored = get_option( self::OPTION_LIST, '' );
		$domains = is_string( $stored ) && '' !== $stored
			? preg_split( '/\r\n|\r|\n/', $stored )
			: self::fallback();

		$map = array();
		foreach ( $domains as $d ) {
			$d = strtolower( trim( $d ) );
			if ( '' !== $d && '#' !== $d[0] ) {
				$map[ $d ] = true;
			}
		}
		return $map;
	}

	/**
	 * Is the given email address on a disposable domain?
	 *
	 * @param string $email
	 * @return bool
	 */
	public static function is_disposable( $email ) {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain = strtolower( trim( substr( $email, $at + 1 ) ) );
		if ( '' === $domain ) {
			return false;
		}

		$map = self::map();
		if ( isset( $map[ $domain ] ) ) {
			return true;
		}

		// Also match subdomains of a listed domain (e.g. foo.mailinator.com).
		$parts = explode( '.', $domain );
		while ( count( $parts ) > 2 ) {
			array_shift( $parts );
			$parent = implode( '.', $parts );
			if ( isset( $map[ $parent ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scan a submitted payload for any email-looking value on a disposable
	 * domain. Returns the offending address, or '' if none.
	 *
	 * @param array $payload
	 * @return string
	 */
	public static function first_disposable_in( $payload ) {
		foreach ( (array) $payload as $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value && is_email( $value ) && self::is_disposable( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Fetch the latest blocklist from the source and cache it. Returns the
	 * number of domains stored, or a WP_Error on failure (leaving the
	 * previous cached list in place).
	 *
	 * @return int|WP_Error
	 */
	public static function refresh() {
		$response = wp_remote_get( self::SOURCE_URL, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'fc_disposable_http', sprintf( 'HTTP %d fetching blocklist.', $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $body ) ) );

		// Sanity check: the real list is thousands of lines. Bail if it looks
		// truncated/wrong rather than overwriting a good list with junk.
		if ( count( $lines ) < 100 ) {
			return new WP_Error( 'fc_disposable_short', 'Fetched blocklist looks too short; keeping existing.' );
		}

		update_option( self::OPTION_LIST, implode( "\n", $lines ), false );
		update_option( self::OPTION_UPDATED, current_time( 'mysql' ), false );

		return count( $lines );
	}

	/**
	 * Count of domains currently in the active blocklist.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::map() );
	}

	/**
	 * When the active list was last fetched, or '' if never (using fallback).
	 *
	 * @return string
	 */
	public static function last_updated() {
		return (string) get_option( self::OPTION_UPDATED, '' );
	}
}
