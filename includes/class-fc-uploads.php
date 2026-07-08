<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File upload handling: validation, protected storage, listing, streaming
 * (via an authenticated proxy), and deletion.
 *
 * Files are stored under wp-content/uploads/formcatch/ with unguessable
 * hashed subpaths and an .htaccess/index.php guard. They are never linked
 * directly — downloads go through FC_Admin's capability-checked endpoint,
 * so access control works identically on Apache and Nginx.
 */
class FC_Uploads {

	/** Extensions that are never allowed, even if a site owner lists them. */
	const BLOCKED_EXTENSIONS = array(
		'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phps', 'phar',
		'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py',
		'js', 'mjs', 'jsp', 'asp', 'aspx', 'htaccess', 'htm', 'html',
		'svg', 'svgz', 'xml', 'shtml',
	);

	/**
	 * Absolute base directory + URL for FormCatch uploads.
	 *
	 * @return array { 'dir' => string, 'url' => string }
	 */
	public static function base() {
		$uploads = wp_upload_dir();
		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . 'formcatch',
			'url' => trailingslashit( $uploads['baseurl'] ) . 'formcatch',
		);
	}

	/**
	 * Create the protected upload directory with access guards. Idempotent.
	 */
	public static function ensure_protected_dir() {
		$base = self::base();
		$dir  = $base['dir'];

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Apache/LiteSpeed: deny direct web access. (Nginx ignores this — which
		// is exactly why downloads are always served through the auth proxy.)
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# FormCatch: block direct access. Files are served via an authenticated proxy.\n";
			$rules .= "Order Deny,Allow\nDeny from all\n";
			$rules .= "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Silence directory listing on any server.
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Effective max upload size in bytes: the smaller of the configured
	 * value and what the server (php.ini) actually permits.
	 *
	 * @return int
	 */
	public static function max_size() {
		$server = (int) wp_max_upload_size();
		$configured_mb = (int) get_option( 'fc_upload_max_mb', 0 );

		if ( $configured_mb > 0 ) {
			$configured = $configured_mb * MB_IN_BYTES;
			return min( $server, $configured );
		}

		return $server;
	}

	/**
	 * Allowed file extensions (lowercase, no dot). Blocked extensions are
	 * always filtered out regardless of configuration.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		$default = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';
		$raw     = (string) get_option( 'fc_upload_allowed_ext', $default );

		$exts = array_filter( array_map(
			function ( $e ) {
				return strtolower( trim( ltrim( $e, '.' ) ) );
			},
			preg_split( '/[\s,]+/', $raw )
		) );

		$blocked = self::BLOCKED_EXTENSIONS;
		return array_values( array_diff( array_unique( $exts ), $blocked ) );
	}

	/**
	 * Max number of files accepted per submission.
	 *
	 * @return int
	 */
	public static function max_files() {
		return max( 1, (int) get_option( 'fc_upload_max_files', 10 ) );
	}

	/**
	 * Build an ext => mime map for wp_handle_upload from the allowed list,
	 * using WordPress's own known types.
	 *
	 * @return array
	 */
	private static function allowed_mimes() {
		$all   = wp_get_mime_types();
		$mimes = array();

		foreach ( self::allowed_extensions() as $ext ) {
			foreach ( $all as $pattern => $mime ) {
				// $pattern looks like "jpg|jpeg|jpe".
				if ( in_array( $ext, explode( '|', $pattern ), true ) ) {
					$mimes[ $pattern ] = $mime;
					break;
				}
			}
		}

		return $mimes;
	}

	/**
	 * Process uploaded files for a submission. Validates and stores each,
	 * writing a row per stored file. Logs failures. Silently ignores forms
	 * that don't accept uploads.
	 *
	 * @param object          $form
	 * @param WP_REST_Request $request
	 * @param int             $submission_id
	 * @return void
	 */
	public static function process( $form, $request, $submission_id ) {
		// Pro feature — free installs don't process uploads.
		if ( ! FC_Helpers::can_use( 'uploads' ) ) {
			return;
		}

		if ( empty( $form->settings_arr['allow_uploads'] ) ) {
			return;
		}

		$files = self::normalize( $request->get_file_params() );
		if ( empty( $files ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		self::ensure_protected_dir();

		$max_size  = self::max_size();
		$allowed   = self::allowed_extensions();
		$mimes     = self::allowed_mimes();
		$remaining = self::max_files();

		foreach ( $files as $file ) {
			if ( $remaining <= 0 ) {
				break;
			}
			if ( (int) $file['error'] === UPLOAD_ERR_NO_FILE || '' === $file['name'] ) {
				continue;
			}

			$err = self::validate( $file, $max_size, $allowed );
			if ( $err ) {
				FC_Logger::submission(
					FC_Logger::STATUS_ERROR,
					/* translators: 1: filename, 2: reason */
					sprintf( __( 'File "%1$s" rejected: %2$s', 'formcatch' ), $file['name'], $err ),
					array( 'field' => $file['field'], 'size' => $file['size'] ),
					$form->id,
					$submission_id
				);
				continue;
			}

			$stored = self::store( $file, $form->id, $mimes );
			if ( is_wp_error( $stored ) ) {
				FC_Logger::submission(
					FC_Logger::STATUS_ERROR,
					/* translators: 1: filename, 2: reason */
					sprintf( __( 'File "%1$s" could not be saved: %2$s', 'formcatch' ), $file['name'], $stored->get_error_message() ),
					array( 'field' => $file['field'] ),
					$form->id,
					$submission_id
				);
				continue;
			}

			self::record( $submission_id, $form->id, $file, $stored );
			$remaining--;
		}
	}

	/**
	 * Validate a single file. Returns an error string, or '' if OK.
	 *
	 * @param array $file
	 * @param int   $max_size
	 * @param array $allowed
	 * @return string
	 */
	private static function validate( $file, $max_size, $allowed ) {
		if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return __( 'upload error', 'formcatch' );
		}
		if ( (int) $file['size'] > $max_size ) {
			return __( 'exceeds max size', 'formcatch' );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
			return __( 'file type not permitted', 'formcatch' );
		}
		if ( ! in_array( $ext, $allowed, true ) ) {
			return __( 'file type not allowed', 'formcatch' );
		}

		// Verify the real content matches the extension (defeats a .php
		// renamed to .pdf).
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return __( 'content does not match extension', 'formcatch' );
		}

		return '';
	}

	/**
	 * Move a validated upload into a protected, hashed subdirectory using
	 * WordPress's canonical handler.
	 *
	 * @param array $file
	 * @param int   $form_id
	 * @param array $mimes
	 * @return array|WP_Error { file, url, type } from wp_handle_upload.
	 */
	private static function store( $file, $form_id, $mimes ) {
		// Unguessable per-file subdir under the form's folder.
		$subdir = 'formcatch/' . (int) $form_id . '/' . wp_generate_password( 16, false, false );

		$filter = function ( $dirs ) use ( $subdir ) {
			$dirs['subdir'] = '/' . $subdir;
			$dirs['path']   = $dirs['basedir'] . '/' . $subdir;
			$dirs['url']    = $dirs['baseurl'] . '/' . $subdir;
			return $dirs;
		};

		add_filter( 'upload_dir', $filter );
		$result = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);
		remove_filter( 'upload_dir', $filter );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'fc_upload_failed', $result['error'] );
		}

		return $result;
	}

	/**
	 * Insert a fc_files row for a stored upload. Stores the path relative to
	 * the uploads basedir so it survives a moved WordPress install.
	 *
	 * @param int   $submission_id
	 * @param int   $form_id
	 * @param array $file    Normalized upload.
	 * @param array $stored  wp_handle_upload result.
	 * @return void
	 */
	private static function record( $submission_id, $form_id, $file, $stored ) {
		global $wpdb;

		$uploads  = wp_upload_dir();
		$relative = ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $stored['file'] ), '/' );

		$wpdb->insert(
			FC_DB::files_table(),
			array(
				'submission_id' => (int) $submission_id,
				'form_id'       => (int) $form_id,
				'field_name'    => sanitize_text_field( $file['field'] ),
				'original_name' => sanitize_file_name( $file['name'] ),
				'stored_path'   => $relative,
				'mime'          => isset( $stored['type'] ) ? $stored['type'] : '',
				'size'          => (int) $file['size'],
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Flatten $_FILES (handling both single and array-style file inputs)
	 * into a list of { field, name, type, tmp_name, error, size }.
	 *
	 * @param array $file_params
	 * @return array
	 */
	private static function normalize( $file_params ) {
		$out = array();

		foreach ( (array) $file_params as $field => $f ) {
			if ( ! isset( $f['name'] ) ) {
				continue;
			}

			if ( is_array( $f['name'] ) ) {
				$count = count( $f['name'] );
				for ( $i = 0; $i < $count; $i++ ) {
					$out[] = array(
						'field'    => $field,
						'name'     => $f['name'][ $i ],
						'type'     => isset( $f['type'][ $i ] ) ? $f['type'][ $i ] : '',
						'tmp_name' => isset( $f['tmp_name'][ $i ] ) ? $f['tmp_name'][ $i ] : '',
						'error'    => isset( $f['error'][ $i ] ) ? $f['error'][ $i ] : UPLOAD_ERR_NO_FILE,
						'size'     => isset( $f['size'][ $i ] ) ? $f['size'][ $i ] : 0,
					);
				}
			} else {
				$out[] = array(
					'field'    => $field,
					'name'     => $f['name'],
					'type'     => isset( $f['type'] ) ? $f['type'] : '',
					'tmp_name' => isset( $f['tmp_name'] ) ? $f['tmp_name'] : '',
					'error'    => isset( $f['error'] ) ? $f['error'] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $f['size'] ) ? $f['size'] : 0,
				);
			}
		}

		return $out;
	}

	/* -----------------------------------------------------------------
	 * Signed download URLs (work from an email, no login required)
	 * --------------------------------------------------------------- */

	/**
	 * The secret used to sign download URLs. Auto-generated on first use so
	 * links can't be forged.
	 *
	 * @return string
	 */
	private static function url_secret() {
		$secret = (string) get_option( 'fc_file_url_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, false, false );
			update_option( 'fc_file_url_secret', $secret, false );
		}
		return $secret;
	}

	/**
	 * Build a signed, expiring download URL for a stored file. Safe to put
	 * in an email — no login needed until it expires.
	 *
	 * @param int $file_id
	 * @param int $ttl_days Days until the link expires.
	 * @return string
	 */
	public static function signed_url( $file_id, $ttl_days = 7 ) {
		$ttl_days = (int) apply_filters( 'fc_file_url_ttl_days', $ttl_days );
		$expires  = time() + ( max( 1, $ttl_days ) * DAY_IN_SECONDS );
		$sig      = hash_hmac( 'sha256', $file_id . '|' . $expires, self::url_secret() );

		return add_query_arg(
			array(
				'action'  => 'fc_download_file',
				'file'    => (int) $file_id,
				'expires' => $expires,
				'sig'     => $sig,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Verify a signed download URL's signature and expiry.
	 *
	 * @param int    $file_id
	 * @param int    $expires
	 * @param string $sig
	 * @return bool
	 */
	public static function verify_signature( $file_id, $expires, $sig ) {
		if ( (int) $expires < time() ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $file_id . '|' . (int) $expires, self::url_secret() );
		return hash_equals( $expected, (string) $sig );
	}

	/* -----------------------------------------------------------------
	 * Retrieval / serving / deletion
	 * --------------------------------------------------------------- */

	/**
	 * @param int $submission_id
	 * @return array Rows from fc_files.
	 */
	public static function for_submission( $submission_id ) {
		global $wpdb;
		$table = FC_DB::files_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d ORDER BY id ASC", $submission_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param int $file_id
	 * @return object|null
	 */
	public static function get( $file_id ) {
		global $wpdb;
		$table = FC_DB::files_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $file_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Resolve a stored (relative) path to an absolute path, guarding against
	 * path traversal outside the FormCatch upload directory.
	 *
	 * @param string $relative
	 * @return string|false
	 */
	public static function resolve_path( $relative ) {
		$uploads = wp_upload_dir();
		$abs     = trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' );

		$real  = realpath( $abs );
		$guard = realpath( self::base()['dir'] );

		if ( false === $real || false === $guard ) {
			return false;
		}

		// Normalize separators (realpath returns backslashes on Windows) so the
		// containment check is reliable cross-platform.
		$real_n  = wp_normalize_path( $real );
		$guard_n = trailingslashit( wp_normalize_path( $guard ) );

		if ( 0 !== strpos( $real_n, $guard_n ) ) {
			return false;
		}

		return $real;
	}

	/**
	 * Stream a stored file to the browser as a download. Caller MUST have
	 * already verified the user's capability.
	 *
	 * @param object $file Row from fc_files.
	 * @return void Exits, or returns on failure so caller can 404.
	 */
	public static function stream( $file ) {
		$path = self::resolve_path( $file->stored_path );
		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $file->mime ? $file->mime : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file->original_name ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Delete all files (disk + rows) for a set of submissions.
	 *
	 * @param int[] $submission_ids
	 * @return void
	 */
	public static function delete_for_submissions( $submission_ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $submission_ids ) );
		if ( empty( $ids ) ) {
			return;
		}

		$table        = FC_DB::files_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT id, stored_path FROM {$table} WHERE submission_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $rows as $row ) {
			$path = self::resolve_path( $row->stored_path );
			if ( $path && file_exists( $path ) ) {
				wp_delete_file( $path );
				// Try to remove the now-empty hashed subdir.
				@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE submission_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
