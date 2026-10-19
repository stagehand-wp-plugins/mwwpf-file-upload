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
