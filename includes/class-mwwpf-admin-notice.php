<?php
/**
 * Warning when the storage may be publicly reachable.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin notice.
 */
class MWWPF_Admin_Notice {

	/**
	 * Registers the notice.
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
	}

	/**
	 * Whether the storage is inside the document root on a server that ignores .htaccess.
	 *
	 * @return bool
	 */
	public static function storage_may_be_public() {
		if ( defined( 'MWWPF_STORAGE_PROTECTED' ) && MWWPF_STORAGE_PROTECTED ) {
			return false;
		}

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		/**
		 * Filters whether the web server ignores .htaccess files.
		 *
		 * @param bool   $ignores  Default: true on Nginx.
		 * @param string $software SERVER_SOFTWARE in lowercase.
		 */
		if ( ! apply_filters( 'mwwpf_server_ignores_htaccess', false !== strpos( $software, 'nginx' ), $software ) ) {
			return false;
		}

		return self::is_inside( MWWPF_Storage::root_dir(), ABSPATH );
	}

	/**
	 * Whether a path is inside a directory. Works for paths that do not exist yet.
	 *
	 * @param string $path Path.
	 * @param string $dir  Directory.
	 * @return bool
	 */
	public static function is_inside( $path, $dir ) {
		$real_dir  = realpath( $dir );
		$real_path = realpath( $path );
		$dir       = trailingslashit( wp_normalize_path( false !== $real_dir ? $real_dir : $dir ) );
		$path      = trailingslashit( wp_normalize_path( false !== $real_path ? $real_path : $path ) );
		return 0 === strpos( $path, $dir );
	}

	/**
	 * Returns the warning text.
	 *
	 * @return string
	 */
	public static function message() {
		return __( 'The storage for uploaded files is inside the public directory, and this server does not apply .htaccess protection. Set MWWPF_STORAGE_DIR in wp-config.php to a path outside the document root, or deny access to the storage in the Nginx configuration.', 'mwwpf-file-upload' );
	}

	/**
	 * Returns the README URL of the Nginx section.
	 *
	 * @return string
	 */
	public static function help_url() {
		return 'https://github.com/stagehand-wp-plugins/mwwpf-file-upload#nginx';
	}

	/**
	 * Prints the notice for administrators.
	 */
	public static function render() {
		if ( ! current_user_can( MWWPF_Admin_Page::capability() ) || ! self::storage_may_be_public() ) {
			return;
		}
		printf(
			'<div class="notice notice-error mwwpf-storage-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
			esc_html__( 'File Upload for MW WP Form:', 'mwwpf-file-upload' ),
			esc_html( self::message() ),
			esc_url( self::help_url() ),
			esc_html__( 'How to fix', 'mwwpf-file-upload' )
		);
	}
}
