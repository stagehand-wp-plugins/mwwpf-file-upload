<?php
/**
 * Ajax endpoints: upload, delete, preview.
 *
 * Uses admin-ajax.php rather than the REST API, so that security plugins or server
 * settings that block anonymous REST requests do not break the forms.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajax handlers.
 */
class MWWPF_Ajax {

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'mwwpf_upload';

	/**
	 * MIME types reported by finfo that are never accepted, whatever the extension.
	 */
	const DENIED_REAL_MIMES = array(
		'text/x-php',
		'application/x-php',
		'application/x-httpd-php',
		'text/html',
		'application/xhtml+xml',
		'image/svg+xml',
		'text/javascript',
		'application/javascript',
		'text/x-shellscript',
		'application/x-sh',
		'application/x-executable',
		'application/x-dosexec',
		'application/x-msdownload',
	);

	/**
	 * Registers the Ajax actions.
	 */
	public static function register() {
		foreach ( array( 'upload', 'delete', 'preview', 'nonce' ) as $action ) {
			add_action( 'wp_ajax_mwwpf_' . $action, array( __CLASS__, 'handle_' . $action ) );
			add_action( 'wp_ajax_nopriv_mwwpf_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Returns a fresh nonce. Used by the front-end script when a cached page carries a stale one.
	 */
	public static function handle_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * Handles an upload.
	 */
	public static function handle_upload() {
		nocache_headers();

		$result = self::process_upload();
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::maybe_cleanup();

		wp_send_json_success(
			array(
				'token'       => $result['token'],
				'name'        => $result['name'],
				'size'        => $result['size'],
				'size_label'  => size_format( $result['size'] ),
				'mime'        => $result['mime'],
				'preview_url' => MWWPF_Storage::preview_url( $result ),
			)
		);
	}

	/**
	 * Validates and stores the uploaded file.
	 *
	 * @return array|WP_Error Stored metadata.
	 */
	public static function process_upload() {
		// 1. Nonce.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return new WP_Error( 'invalid_nonce', __( 'The page has expired. Please reload the page and try again.', 'mwwpf-file-upload' ), 403 );
		}

		// 2-3. Form and field.
		$target = self::get_target();
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		list( $form_id, $field, $definition ) = $target;

		// 4. Rate limit.
		if ( ! self::check_rate_limit() ) {
			return new WP_Error( 'rate_limited', __( 'Too many uploads. Please wait a while and try again.', 'mwwpf-file-upload' ), 429 );
		}

		// 5. Quota of the temporary directory.
		/**
		 * Filters the maximum total size of temporary (not yet sent) files.
		 *
		 * @param int $bytes Bytes. Default 500MB.
		 */
		$quota = (int) apply_filters( 'mwwpf_tmp_quota_bytes', 500 * MB_IN_BYTES );
		if ( MWWPF_Storage::tmp_usage() >= $quota ) {
			return new WP_Error( 'quota', __( 'The server cannot accept files right now. Please try again later.', 'mwwpf-file-upload' ), 503 );
		}

		// 6. The upload itself.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonce checked above; the array is validated below and the name is cleaned.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( ! $file || ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || is_array( $file['error'] ) ) {
			return new WP_Error( 'no_file', __( 'Failed to upload the file.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}
		if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return new WP_Error( 'filesize', __( 'This file size is too big.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_error', __( 'Failed to upload the file.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		$checked = self::validate_file( $file['tmp_name'], (string) $file['name'], $form_id, $field, $definition['type'] );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// 11. Store.
		return MWWPF_Storage::store( $file['tmp_name'], $checked );
	}

	/**
	 * Validates a file (steps 7-10). Separated from the request handling so that it can be tested.
	 *
	 * @param string $path    Path of the file to check.
	 * @param string $name    Original file name sent by the browser.
	 * @param int    $form_id Form ID.
	 * @param string $field   Field name.
	 * @param string $type    Field type: file or image.
	 * @return array|WP_Error Metadata for MWWPF_Storage::store().
	 */
	public static function validate_file( $path, $name, $form_id, $field, $type ) {
		$limits = MWWPF_Storage::get_limits( $form_id, $field );
		$name   = MWWPF_Storage::clean_filename( $name );
		$size   = (int) filesize( $path );

		$invalid = new WP_Error( 'filetype', __( 'This file is invalid.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

		// 7. Size.
		if ( $size <= 0 ) {
			return new WP_Error( 'empty', __( 'Failed to upload the file.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}
		if ( $size > $limits['max_bytes'] ) {
			return new WP_Error( 'filesize', __( 'This file size is too big.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		// 8. Extension: the last one decides (a.php.pdf is a pdf; it is stored as .bin anyway).
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $name || MWWPF_Storage::is_denied_filename( $name ) || ! in_array( $ext, $limits['exts'], true ) ) {
			return $invalid;
		}

		// 9. Real content.
		$check = wp_check_filetype_and_ext( $path, $name, wp_get_mime_types() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || strtolower( $check['ext'] ) !== $ext ) {
			return $invalid;
		}
		$mime      = $check['type'];
		$real_mime = self::real_mime( $path );
		if ( $real_mime && in_array( $real_mime, self::DENIED_REAL_MIMES, true ) ) {
			return $invalid;
		}

		// 10. Images.
		$width  = null;
		$height = null;
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$image = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are handled below.
			if ( is_array( $image ) && $image[0] > 0 && $image[1] > 0 ) {
				$width  = (int) $image[0];
				$height = (int) $image[1];
			} elseif ( 'image' === $type || in_array( $mime, MWWPF_Storage::PREVIEW_MIMES, true ) ) {
				return $invalid;
			}
		} elseif ( 'image' === $type ) {
			return $invalid;
		}

		if ( null !== $width ) {
			if ( ( null !== $limits['max_w'] && $width > $limits['max_w'] ) || ( null !== $limits['max_h'] && $height > $limits['max_h'] ) ) {
				return new WP_Error( 'maxfilesize', __( 'This image size is too big.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			}
			if ( ( null !== $limits['min_w'] && $width < $limits['min_w'] ) || ( null !== $limits['min_h'] && $height < $limits['min_h'] ) ) {
				return new WP_Error( 'minfilesize', __( 'This image size is too small.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			}
		}

		return array(
			'form_id' => $form_id,
			'field'   => $field,
			'type'    => $type,
			'name'    => $name,
			'ext'     => $ext,
			'mime'    => $mime,
			'size'    => $size,
			'width'   => $width,
			'height'  => $height,
		);
	}

	/**
	 * Handles deletion of the visitor's own temporary file.
	 */
	public static function handle_delete() {
		nocache_headers();

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::send_error( new WP_Error( 'invalid_nonce', __( 'The page has expired. Please reload the page and try again.', 'mwwpf-file-upload' ), 403 ) );
		}

		$target = self::get_target();
		if ( is_wp_error( $target ) ) {
			self::send_error( $target );
		}
		list( $form_id, $field ) = $target;

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		if ( ! MWWPF_Storage::resolve( $token, $form_id, $field ) ) {
			self::send_error( new WP_Error( 'not_found', __( 'The file was not found.', 'mwwpf-file-upload' ), 404 ) );
		}

		MWWPF_Storage::delete( $token );
		wp_send_json_success();
	}

	/**
	 * Streams the visitor's own uploaded image.
	 */
	public static function handle_preview() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only; access is tied to the visitor cookie.
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$field   = isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( $_GET['field'] ) ) : '';
		// phpcs:enable

		$meta = MWWPF_Storage::resolve( $token, $form_id, $field );
		if ( ! $meta || ! in_array( $meta['mime'], MWWPF_Storage::PREVIEW_MIMES, true ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		self::stream( MWWPF_Storage::tmp_path( $token, 'bin' ), $meta['mime'], 'inline', '' );
	}

	/**
	 * Streams a file with safe headers and exits.
	 *
	 * @param string $path        File path (built from a validated token).
	 * @param string $mime        Content type.
	 * @param string $disposition inline or attachment.
	 * @param string $filename    Download file name (for attachment).
	 */
	public static function stream( $path, $mime, $disposition, $filename ) {
		if ( ! is_file( $path ) ) {
			status_header( 404 );
			exit;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, private' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );

		if ( 'attachment' === $disposition ) {
			$fallback = preg_replace( '/[^A-Za-z0-9._-]/', '_', $filename );
			header( sprintf( 'Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode( $filename ) ) );
		} else {
			header( 'Content-Disposition: inline' );
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Reads and validates form_id and field from the request.
	 *
	 * @return array|WP_Error [ form_id, field, definition ].
	 */
	private static function get_target() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers verify the nonce first.
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$field   = isset( $_POST['field'] ) && is_string( $_POST['field'] ) ? wp_unslash( $_POST['field'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated against the allowlist.
		// phpcs:enable

		$invalid = new WP_Error( 'invalid_target', __( 'Failed to upload the file.', 'mw-wp-form' ), 400 ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

		$post = $form_id ? get_post( $form_id ) : null;
		if ( ! $post || MWWPF_Form_Scanner::FORM_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return $invalid;
		}

		$definition = MWWPF_Form_Scanner::get_field( $form_id, $field );
		if ( ! $definition ) {
			return $invalid;
		}

		return array( $form_id, $field, $definition );
	}

	/**
	 * Counts an upload attempt for the client IP and returns false when over the limit.
	 *
	 * @return bool
	 */
	private static function check_rate_limit() {
		/**
		 * Filters the upload rate limit per IP address.
		 *
		 * @param array $limit [ max attempts, window in seconds ]. Default [ 30, 600 ].
		 */
		$limit  = (array) apply_filters( 'mwwpf_rate_limit', array( 30, 600 ) );
		$max    = isset( $limit[0] ) ? (int) $limit[0] : 30;
		$window = isset( $limit[1] ) ? (int) $limit[1] : 600;
		if ( $max <= 0 || $window <= 0 ) {
			return true;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the client IP address used for rate limiting.
		 *
		 * Behind a reverse proxy, return the real client address here.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip  = (string) apply_filters( 'mwwpf_client_ip', $ip );
		$key = 'mwwpf_rl_' . md5( $ip );

		$state = get_transient( $key );
		$now   = time();
		if ( ! is_array( $state ) || ! isset( $state['count'], $state['start'] ) || $state['start'] + $window <= $now ) {
			$state = array(
				'count' => 0,
				'start' => $now,
			);
		}

		++$state['count'];
		set_transient( $key, $state, max( 1, $state['start'] + $window - $now ) );

		return $state['count'] <= $max;
	}

	/**
	 * Returns the MIME type detected from the file content, or an empty string.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function real_mime( $path ) {
		if ( ! function_exists( 'finfo_open' ) ) {
			return '';
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( ! $finfo ) {
			return '';
		}
		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );
		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * Runs the cleanup now and then, for sites where WP-Cron does not run.
	 */
	private static function maybe_cleanup() {
		/**
		 * Filters the probability (0-100) of running the cleanup after an upload.
		 *
		 * @param int $percent Default 2.
		 */
		$percent = (int) apply_filters( 'mwwpf_cleanup_probability', 2 );
		if ( $percent > 0 && wp_rand( 1, 100 ) <= $percent ) {
			MWWPF_Cleanup::run();
		}
	}

	/**
	 * Sends a JSON error response and exits.
	 *
	 * @param WP_Error $error Error. Its data is the HTTP status.
	 */
	private static function send_error( WP_Error $error ) {
		$status = $error->get_error_data();
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			is_int( $status ) ? $status : 400
		);
	}
}
