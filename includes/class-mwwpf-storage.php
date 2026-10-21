<?php
/**
 * Temporary storage, tokens, metadata and per-field limits.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage.
 */
class MWWPF_Storage {

	/**
	 * Extensions that are always rejected, even when allowed by the form's filetype rule.
	 */
	const DENIED_EXTS = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phtml',
		'phar',
		'phps',
		'pht',
		'cgi',
		'pl',
		'py',
		'rb',
		'sh',
		'asp',
		'aspx',
		'jsp',
		'exe',
		'com',
		'bat',
		'cmd',
		'js',
		'mjs',
		'html',
		'htm',
		'shtml',
		'xhtml',
		'svg',
		'svgz',
		'xml',
		'htaccess',
		'htpasswd',
		'ini',
	);

	/**
	 * File names that are always rejected.
	 */
	const DENIED_NAMES = array( '.htaccess', '.htpasswd', '.user.ini', 'web.config' );

	/**
	 * Default allowed extensions per field type (used when the form has no filetype rule).
	 */
	const DEFAULT_EXTS = array(
		'file'  => array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip' ),
		'image' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
	);

	/**
	 * Default maximum file size in bytes (used when the form has no filesize rule).
	 */
	const DEFAULT_MAX_BYTES = 10485760;

	/**
	 * Visitor ID cookie.
	 */
	const COOKIE = 'mwwpf_sid';

	/**
	 * Image MIME types that may be previewed.
	 */
	const PREVIEW_MIMES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

	/**
	 * Visitor ID set during this request (before the browser sends the cookie back).
	 *
	 * @var string|null
	 */
	private static $sid = null;

	/**
	 * Returns the storage root (without a trailing slash).
	 *
	 * @return string
	 */
	public static function root_dir() {
		if ( defined( 'MWWPF_STORAGE_DIR' ) && is_string( MWWPF_STORAGE_DIR ) && '' !== MWWPF_STORAGE_DIR ) {
			return untrailingslashit( wp_normalize_path( MWWPF_STORAGE_DIR ) );
		}
		$upload_dir = wp_upload_dir( null, false );
		return untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) ) . '/mwwpf';
	}

	/**
	 * Returns a protected directory under the storage root, creating it when needed.
	 *
	 * @param string $sub Sub directory, such as "tmp" or "files/12".
	 * @return string|false Path without a trailing slash, or false when it cannot be created.
	 */
	public static function dir( $sub = 'tmp' ) {
		$root = self::root_dir();
		if ( ! self::ensure_protected_dir( $root ) ) {
			return false;
		}

		$path = $root;
		foreach ( explode( '/', trim( (string) $sub, '/' ) ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $part ) ) {
				return false;
			}
			$path .= '/' . $part;
			if ( ! self::ensure_protected_dir( $path ) ) {
				return false;
			}
		}
		return $path;
	}

	/**
	 * Creates a directory with a deny-all .htaccess and an empty index.php.
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	private static function ensure_protected_dir( $path ) {
		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return false;
		}

		$htaccess = $path . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# File Upload for MW WP Form: deny all direct access.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = $path . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	/**
	 * Whether a token has the expected format.
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	public static function is_valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Generates a new random token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Returns the visitor ID from the cookie, optionally issuing a new one.
	 *
	 * @param bool $create Issue a cookie when there is none.
	 * @return string Empty string when there is no visitor ID.
	 */
	public static function get_sid( $create = false ) {
		if ( null !== self::$sid ) {
			return self::$sid;
		}

		$sid = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( self::is_valid_token( $sid ) ) {
			self::$sid = $sid;
			return $sid;
		}

		if ( ! $create ) {
			return '';
		}

		$sid = self::generate_token();
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$sid,
				array(
					'expires'  => 0,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		self::$sid = $sid;
		return $sid;
	}

	/**
	 * Hashes a visitor ID for storage in metadata.
	 *
	 * @param string $sid Visitor ID.
	 * @return string
	 */
	public static function sid_hash( $sid ) {
		return hash_hmac( 'sha256', (string) $sid, wp_salt( 'auth' ) );
	}

	/**
	 * Forgets the visitor ID cached for this request. For tests.
	 */
	public static function reset_sid() {
		self::$sid = null;
	}

	/**
	 * Returns how long temporary files are kept, in seconds.
	 *
	 * @return int
	 */
	public static function ttl() {
		/**
		 * Filters how long temporary (not yet sent) files are kept.
		 *
		 * @param int $seconds Seconds. Default 86400 (24 hours).
		 */
		return max( 60, (int) apply_filters( 'mwwpf_tmp_ttl', DAY_IN_SECONDS ) );
	}

	/**
	 * Stores an uploaded file and its metadata in the temporary directory.
	 *
	 * @param string $source Path of the uploaded file (PHP's temporary file).
	 * @param array  $meta   Metadata: form_id, field, type, name, ext, mime, size, width, height.
	 * @param bool   $is_uploaded_file Whether $source is a PHP upload (moved with move_uploaded_file).
	 * @return array|WP_Error Metadata including the token.
	 */
	public static function store( $source, array $meta, $is_uploaded_file = true ) {
		$dir = self::dir( 'tmp' );
		$sid = self::get_sid( true );
		if ( ! $dir || '' === $sid ) {
			return new WP_Error( 'storage', __( 'Failed to upload the file.', 'mw-wp-form' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		$token = self::generate_token();
		$bin   = $dir . '/' . $token . '.bin';

		$moved = $is_uploaded_file ? move_uploaded_file( $source, $bin ) : copy( $source, $bin );
		if ( ! $moved ) {
			return new WP_Error( 'storage', __( 'Failed to upload the file.', 'mw-wp-form' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}
		chmod( $bin, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$meta = array(
			'form_id'  => (int) $meta['form_id'],
			'field'    => (string) $meta['field'],
			'type'     => (string) $meta['type'],
			'name'     => (string) $meta['name'],
			'ext'      => (string) $meta['ext'],
			'mime'     => (string) $meta['mime'],
			'size'     => (int) $meta['size'],
			'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'sid_hash' => self::sid_hash( $sid ),
			'created'  => time(),
			'consumed' => false,
		);

		if ( ! self::write_meta( $dir . '/' . $token . '.json', $meta ) ) {
			wp_delete_file( $bin );
			return new WP_Error( 'storage', __( 'Failed to upload the file.', 'mw-wp-form' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		$meta['token'] = $token;
		return $meta;
	}

	/**
	 * Returns the metadata of a temporary file when it belongs to the current visitor
	 * and to the given form and field. Otherwise null.
	 *
	 * @param mixed $token   Token.
	 * @param int   $form_id Form ID.
	 * @param mixed $field   Field name.
	 * @return array|null Metadata including the token.
	 */
	public static function resolve( $token, $form_id, $field ) {
		if ( ! self::is_valid_token( $token ) || ! is_string( $field ) ) {
			return null;
		}

		$meta = self::read_meta( $token );
		if ( ! $meta ) {
			return null;
		}

		$sid = self::get_sid();
		if (
			(int) $meta['form_id'] !== (int) $form_id
			|| $meta['field'] !== $field
			|| ! empty( $meta['consumed'] )
			|| '' === $sid
			|| ! is_string( $meta['sid_hash'] )
			|| ! hash_equals( $meta['sid_hash'], self::sid_hash( $sid ) )
			|| (int) $meta['created'] + self::ttl() < time()
			|| ! is_file( self::tmp_path( $token, 'bin' ) )
		) {
			return null;
		}

		$meta['token'] = $token;
		return $meta;
	}

	/**
	 * Returns the path of a temporary file or its metadata.
	 *
	 * The path is built only from a validated token.
	 *
	 * @param string $token Token (must be valid).
	 * @param string $ext   bin or json.
	 * @return string
	 */
	public static function tmp_path( $token, $ext ) {
		return self::root_dir() . '/tmp/' . $token . '.' . ( 'json' === $ext ? 'json' : 'bin' );
	}

	/**
	 * Reads the metadata of a temporary file.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function read_meta( $token ) {
		if ( ! self::is_valid_token( $token ) ) {
			return null;
		}
		$json = self::tmp_path( $token, 'json' );
		if ( ! is_file( $json ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( $json ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $meta ) || ! isset( $meta['form_id'], $meta['field'], $meta['name'], $meta['sid_hash'], $meta['created'] ) ) {
			return null;
		}
		return $meta;
	}

	/**
	 * Marks a temporary file as consumed, so that its token cannot be used again.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function mark_consumed( $token ) {
		$meta = self::read_meta( $token );
		if ( ! $meta ) {
			return false;
		}
		$meta['consumed'] = true;
		return self::write_meta( self::tmp_path( $token, 'json' ), $meta );
	}

	/**
	 * Deletes a temporary file and its metadata.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function delete( $token ) {
		if ( ! self::is_valid_token( $token ) ) {
			return false;
		}
		self::safe_unlink( self::tmp_path( $token, 'bin' ) );
		self::safe_unlink( self::tmp_path( $token, 'json' ) );
		return true;
	}

	/**
	 * Copies a temporary file to a directory under the original (or given) file name.
	 *
	 * @param string $token    Token (must be resolved by the caller).
	 * @param string $dest_dir Destination directory (under the storage root).
	 * @param string $filename File name to use.
	 * @return string|false Copied path.
	 */
	public static function copy_for_mail( $token, $dest_dir, $filename ) {
		if ( ! self::is_valid_token( $token ) || ! self::is_inside_root( $dest_dir ) ) {
			return false;
		}
		$dest = untrailingslashit( $dest_dir ) . '/' . $filename;
		if ( ! copy( self::tmp_path( $token, 'bin' ), $dest ) ) {
			return false;
		}
		return $dest;
	}

	/**
	 * Returns the total size of the temporary files in bytes.
	 *
	 * @return int
	 */
	public static function tmp_usage() {
		$total = 0;
		foreach ( (array) glob( self::root_dir() . '/tmp/*.bin' ) as $file ) {
			$total += (int) filesize( $file );
		}
		return $total;
	}

	/**
	 * Returns the preview URL of an uploaded image, or an empty string for other files.
	 *
	 * @param array $meta Metadata including the token.
	 * @return string
	 */
	public static function preview_url( array $meta ) {
		if ( ! isset( $meta['mime'], $meta['token'] ) || ! in_array( $meta['mime'], self::PREVIEW_MIMES, true ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'action'  => 'mwwpf_preview',
				'token'   => $meta['token'],
				'form_id' => (int) $meta['form_id'],
				'field'   => rawurlencode( $meta['field'] ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Whether a path is inside the storage root (resolved with realpath).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_inside_root( $path ) {
		$root = realpath( self::root_dir() );
		$real = realpath( $path );
		if ( false === $root || false === $real ) {
			return false;
		}
		$root = trailingslashit( wp_normalize_path( $root ) );
		$real = wp_normalize_path( $real );
		return 0 === strpos( trailingslashit( $real ), $root ) && trailingslashit( $real ) !== $root;
	}

	/**
	 * Deletes a file only when it is inside the storage root.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function safe_unlink( $path ) {
		if ( ! is_file( $path ) || ! self::is_inside_root( $path ) ) {
			return false;
		}
		return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * Deletes a directory tree only when it is inside the storage root.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	public static function safe_rmdir( $dir ) {
		if ( ! is_dir( $dir ) || ! self::is_inside_root( $dir ) ) {
			return false;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::safe_rmdir( $path );
			} else {
				self::safe_unlink( $path );
			}
		}
		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Writes metadata as JSON.
	 *
	 * @param string $path Path.
	 * @param array  $meta Metadata.
	 * @return bool
	 */
	private static function write_meta( $path, array $meta ) {
		$json = wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false !== $json && false !== file_put_contents( $path, $json, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Cleans up an original file name: keeps Japanese, removes path separators,
	 * control characters and leading dots, and limits the length to 200 bytes.
	 *
	 * @param string $name Original file name.
	 * @return string
	 */
	public static function clean_filename( $name ) {
		$name = wp_check_invalid_utf8( (string) $name, true );
		$name = str_replace( '\\', '/', $name );
		$name = substr( $name, (int) strrpos( '/' . $name, '/' ) );
		$name = preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		$name = preg_replace( '/[<>:"|?*]/u', '_', (string) $name );
		$name = ltrim( trim( (string) $name ), '.' );
		$name = trim( $name );

		if ( strlen( $name ) > 200 ) {
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$ext  = ( '' !== $ext && strlen( $ext ) <= 10 ) ? '.' . $ext : '';
			$base = mb_strcut( substr( $name, 0, strlen( $name ) - strlen( $ext ) ), 0, 200 - strlen( $ext ), 'UTF-8' );
			$name = $base . $ext;
		}

		return $name;
	}

	/**
	 * Returns the upload limits of a field, derived from the form's validation settings.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $field   Field name.
	 * @return array{max_bytes: int, exts: string[], min_w: int|null, min_h: int|null, max_w: int|null, max_h: int|null}
	 */
	public static function get_limits( $form_id, $field ) {
		$definition = MWWPF_Form_Scanner::get_field( $form_id, $field );
		$type       = $definition ? $definition['type'] : 'file';
		$rules      = self::get_field_rules( $form_id, $field );

		// Extensions.
		$exts = array();
		if ( isset( $rules['filetype']['types'] ) && '' !== trim( (string) $rules['filetype']['types'] ) ) {
			$exts = self::normalize_exts( explode( ',', (string) $rules['filetype']['types'] ) );
		}
		if ( ! $exts ) {
			/**
			 * Filters the allowed extensions used when a field has no filetype rule.
			 *
			 * @param string[] $exts    Extensions without dots.
			 * @param string   $type    Field type: file or image.
			 * @param int      $form_id Form ID.
			 * @param string   $field   Field name.
			 */
			$exts = self::normalize_exts( (array) apply_filters( 'mwwpf_default_exts', self::DEFAULT_EXTS[ $type ], $type, $form_id, $field ) );
		}
		$exts = array_values( array_diff( $exts, self::DENIED_EXTS ) );

		// Size.
		$max_bytes = 0;
		if ( isset( $rules['filesize']['bytes'] ) && preg_match( '/^\d+$/', (string) $rules['filesize']['bytes'] ) ) {
			$max_bytes = (int) $rules['filesize']['bytes'];
		}
		if ( $max_bytes <= 0 ) {
			/**
			 * Filters the maximum file size used when a field has no filesize rule.
			 *
			 * @param int    $bytes   Maximum size in bytes.
			 * @param int    $form_id Form ID.
			 * @param string $field   Field name.
			 */
			$max_bytes = (int) apply_filters( 'mwwpf_default_max_bytes', self::DEFAULT_MAX_BYTES, $form_id, $field );
		}
		$server_max = (int) wp_max_upload_size();
		if ( $server_max > 0 ) {
			$max_bytes = min( $max_bytes, $server_max );
		}

		return array(
			'max_bytes' => $max_bytes,
			'exts'      => $exts,
			'min_w'     => self::dimension( $rules, 'minfilesize', 'width' ),
			'min_h'     => self::dimension( $rules, 'minfilesize', 'height' ),
			'max_w'     => self::dimension( $rules, 'maxfilesize', 'width' ),
			'max_h'     => self::dimension( $rules, 'maxfilesize', 'height' ),
		);
	}

	/**
	 * Returns the validation rules set for a field in the form settings.
	 *
	 * Mirrors how MW_WP_Form_Setting / MW_WP_Form_Validation read the settings,
	 * without depending on those classes.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $field   Field name.
	 * @return array<string, mixed> Rule name => options.
	 */
	public static function get_field_rules( $form_id, $field ) {
		$settings = get_post_meta( (int) $form_id, MWWPF_Form_Scanner::FORM_POST_TYPE, true );
		if ( ! is_array( $settings ) || empty( $settings['validation'] ) || ! is_array( $settings['validation'] ) ) {
			return array();
		}

		$rules = array();
		foreach ( $settings['validation'] as $validation ) {
			if ( ! is_array( $validation ) || ! isset( $validation['target'] ) || $validation['target'] !== $field ) {
				continue;
			}
			foreach ( $validation as $rule => $options ) {
				if ( 'target' === $rule ) {
					continue;
				}
				$rules[ strtolower( (string) $rule ) ] = is_array( $options ) ? $options : array();
			}
		}
		return $rules;
	}

	/**
	 * Whether an extension or file name is on the deny list.
	 *
	 * @param string $filename File name.
	 * @return bool
	 */
	public static function is_denied_filename( $filename ) {
		$lower = strtolower( (string) $filename );
		if ( in_array( $lower, self::DENIED_NAMES, true ) ) {
			return true;
		}
		$ext = strtolower( pathinfo( $lower, PATHINFO_EXTENSION ) );
		return '' === $ext || in_array( $ext, self::DENIED_EXTS, true );
	}

	/**
	 * Normalizes a list of extensions: lowercase, no dots, alphanumeric only, unique.
	 *
	 * @param array $exts Extensions.
	 * @return string[]
	 */
	private static function normalize_exts( array $exts ) {
		$normalized = array();
		foreach ( $exts as $ext ) {
			$ext = strtolower( ltrim( trim( (string) $ext ), '.' ) );
			if ( preg_match( '/^[a-z0-9]+$/', $ext ) ) {
				$normalized[] = $ext;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Returns an image dimension limit from a rule, or null when it is not set.
	 *
	 * @param array  $rules Rules.
	 * @param string $rule  Rule name.
	 * @param string $key   width or height.
	 * @return int|null
	 */
	private static function dimension( array $rules, $rule, $key ) {
		if ( isset( $rules[ $rule ][ $key ] ) && preg_match( '/^\d+$/', (string) $rules[ $rule ][ $key ] ) ) {
			return (int) $rules[ $rule ][ $key ];
		}
		return null;
	}
}
