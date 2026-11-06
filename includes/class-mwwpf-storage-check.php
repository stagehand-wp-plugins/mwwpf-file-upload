<?php
/**
 * Checks whether the storage can be reached from outside, by placing a test file and requesting it.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage check.
 */
class MWWPF_Storage_Check {

	/**
	 * Option that keeps the last result.
	 */
	const OPTION = 'mwwpf_storage_check';

	/**
	 * A result older than this is checked again.
	 */
	const MAX_AGE = DAY_IN_SECONDS;

	/**
	 * The test file can be read from outside.
	 */
	const PUBLIC_ACCESS = 'public';

	/**
	 * The request for the test file was refused (401, 403, 404 or 410).
	 */
	const PROTECTED_ACCESS = 'protected';

	/**
	 * The storage is not under a directory this site serves, so there is no URL to check.
	 */
	const OUTSIDE = 'outside';

	/**
	 * The check could not be completed (the request failed or returned an unexpected response).
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Returns the last result for the current storage root, or null when there is none or it is too old.
	 *
	 * @return array{status:string,root:string,checked:int,code:int,message:string}|null
	 */
	public static function get() {
		$result = get_option( self::OPTION );
		if ( ! is_array( $result ) || ! isset( $result['status'], $result['root'], $result['checked'] ) ) {
			return null;
		}
		if ( MWWPF_Storage::root_dir() !== $result['root'] || time() - (int) $result['checked'] > self::MAX_AGE ) {
			return null;
		}
		return $result;
	}

	/**
	 * Returns the last status for the current storage root, or '' when it has not been checked.
	 *
	 * @return string
	 */
	public static function status() {
		$result = self::get();
		return $result ? (string) $result['status'] : '';
	}

	/**
	 * Runs the check when there is no recent result.
	 *
	 * @return array
	 */
	public static function maybe_run() {
		$result = self::get();
		return $result ? $result : self::run();
	}

	/**
	 * Places a test file in the storage root, requests it over HTTP, and saves the result.
	 *
	 * @return array{status:string,root:string,checked:int,code:int,message:string}
	 */
	public static function run() {
		$root   = MWWPF_Storage::root_dir();
		$result = array(
			'status'  => self::UNKNOWN,
			'root'    => $root,
			'checked' => time(),
			'code'    => 0,
			'message' => '',
		);

		$url = self::url_for( $root );
		if ( null === $url ) {
			$result['status'] = self::OUTSIDE;
			return self::save( $result );
		}

		if ( ! MWWPF_Storage::dir( '' ) ) {
			$result['message'] = 'The storage directory cannot be created.';
			return self::save( $result );
		}

		$name    = MWWPF_Storage::PROBE_PREFIX . wp_generate_password( 20, false ) . '.txt';
		$path    = $root . '/' . $name;
		$content = wp_generate_password( 32, false );
		if ( false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$result['message'] = 'The test file cannot be written.';
			return self::save( $result );
		}

		$response = wp_remote_get(
			$url . '/' . $name,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				// Same as WordPress's own loopback requests (Site Health).
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		MWWPF_Storage::safe_unlink( $path );

		if ( is_wp_error( $response ) ) {
			$result['message'] = $response->get_error_message();
			return self::save( $result );
		}

		$code           = (int) wp_remote_retrieve_response_code( $response );
		$result['code'] = $code;
		if ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $response ), $content ) ) {
			$result['status'] = self::PUBLIC_ACCESS;
		} elseif ( in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
			$result['status'] = self::PROTECTED_ACCESS;
		}
		return self::save( $result );
	}

	/**
	 * Returns the URL of a directory when it is under a directory this site serves, or null.
	 *
	 * @param string $dir Directory.
	 * @return string|null URL without a trailing slash.
	 */
	public static function url_for( $dir ) {
		$upload_dir = wp_upload_dir( null, false );
		$bases      = array(
			array( $upload_dir['basedir'], $upload_dir['baseurl'] ),
			array( WP_CONTENT_DIR, content_url() ),
			array( ABSPATH, site_url() ),
		);

		$dir = self::real( $dir );
		foreach ( $bases as $base ) {
			$base_dir = trailingslashit( self::real( $base[0] ) );
			if ( 0 === strpos( trailingslashit( $dir ), $base_dir ) ) {
				$relative = ltrim( substr( $dir, strlen( $base_dir ) ), '/' );
				return untrailingslashit( untrailingslashit( $base[1] ) . '/' . $relative );
			}
		}
		return null;
	}

	/**
	 * Suggests a storage path outside the document root, for the wp-config.php example.
	 *
	 * Uses the home directory of the user PHP runs as (not public on common hosts). When it is unknown,
	 * returns a placeholder: the parent of the WordPress directory is not suggested, because on some hosts
	 * it is the public directory of another domain (e.g. ~/www on Sakura).
	 *
	 * @return string
	 */
	public static function suggested_dir() {
		$home = (string) getenv( 'HOME' );
		if ( '' === $home && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$user = posix_getpwuid( posix_geteuid() );
			$home = is_array( $user ) && isset( $user['dir'] ) ? (string) $user['dir'] : '';
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = '' !== $host ? preg_replace( '/[^A-Za-z0-9.\-]/', '', $host ) : 'site';

		if ( '' !== $home && is_dir( $home ) && wp_is_writable( $home ) && ! MWWPF_Storage::path_contains( ABSPATH, $home ) ) {
			return untrailingslashit( wp_normalize_path( $home ) ) . '/mwwpf-storage/' . $host;
		}
		return '/path/to/private/mwwpf-storage/' . $host;
	}

	/**
	 * Saves a result.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private static function save( array $result ) {
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * Returns a normalized real path (or the normalized path when it does not exist), without a trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function real( $path ) {
		$real = realpath( $path );
		return untrailingslashit( wp_normalize_path( false !== $real ? $real : $path ) );
	}
}
