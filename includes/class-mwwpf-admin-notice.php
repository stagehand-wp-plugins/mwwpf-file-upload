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
		// Runs before MW WP Form's notice (priority 10) so it can be removed for this request.
		add_action( 'admin_notices', array( __CLASS__, 'hide_deprecation_notice' ), 0 );
	}

	/**
	 * Removes MW WP Form's notice that [mwform_file] and [mwform_image] will be removed in 5.2.
	 *
	 * This plugin keeps those fields working after 5.2, so the notice no longer applies.
	 * MW WP Form's files are not changed: deactivating this plugin shows the notice again.
	 * When MW WP Form changes or drops the notice class, nothing is removed.
	 */
	public static function hide_deprecation_notice() {
		/**
		 * Filters whether to hide MW WP Form's deprecation notice for [mwform_file] and [mwform_image].
		 *
		 * @param bool $hide Default true.
		 */
		if ( ! apply_filters( 'mwwpf_hide_deprecation_notice', true ) ) {
			return;
		}

		global $wp_filter;
		if ( empty( $wp_filter['admin_notices'] ) || ! $wp_filter['admin_notices'] instanceof WP_Hook ) {
			return;
		}
		foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] ) && is_a( $function[0], 'MW_WP_Form_Deprecation_Notice_Controller' ) ) {
					remove_action( 'admin_notices', $function, $priority );
				}
			}
		}
	}

	/**
	 * Whether the storage may be reachable from outside.
	 *
	 * Uses the result of the HTTP check (MWWPF_Storage_Check) when there is one. Without a result,
	 * or when the check failed, falls back to: inside the document root on a server that ignores .htaccess.
	 *
	 * @return bool
	 */
	public static function storage_may_be_public() {
		if ( defined( 'MWWPF_STORAGE_PROTECTED' ) && MWWPF_STORAGE_PROTECTED ) {
			return false;
		}

		$status = self::check_status();
		if ( MWWPF_Storage_Check::PUBLIC_ACCESS === $status ) {
			return true;
		}
		if ( MWWPF_Storage_Check::PROTECTED_ACCESS === $status || MWWPF_Storage_Check::OUTSIDE === $status ) {
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
		return MWWPF_Storage::path_contains( $dir, $path );
	}

	/**
	 * Returns the status of the last HTTP check for the current storage root ('' when not checked).
	 *
	 * @return string
	 */
	public static function check_status() {
		/**
		 * Filters the result of the storage check used for the warning.
		 *
		 * @param string $status One of the MWWPF_Storage_Check status constants, or '' when not checked.
		 */
		return (string) apply_filters( 'mwwpf_storage_check_status', MWWPF_Storage_Check::status() );
	}

	/**
	 * Returns the warning text.
	 *
	 * @return string
	 */
	public static function message() {
		if ( MWWPF_Storage_Check::PUBLIC_ACCESS === self::check_status() ) {
			return __( 'The storage for uploaded files can be accessed from outside: a test file placed there was readable over HTTP. Set MWWPF_STORAGE_DIR in wp-config.php to a path outside the document root, or deny access to the storage in the web server configuration.', 'mwwpf-file-upload' );
		}
		return __( 'The storage for uploaded files is inside the public directory, and this server does not apply .htaccess protection. Set MWWPF_STORAGE_DIR in wp-config.php to a path outside the document root, or deny access to the storage in the Nginx configuration.', 'mwwpf-file-upload' );
	}

	/**
	 * Returns the text shown when MWWPF_STORAGE_DIR cannot be used.
	 *
	 * @return string
	 */
	public static function rejected_message() {
		return __( 'The path in MWWPF_STORAGE_DIR cannot be used: it must be an absolute path, and must not be a WordPress directory (such as wp-content or uploads) or a parent of one. The default location is used instead.', 'mwwpf-file-upload' );
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
		if ( ! current_user_can( MWWPF_Admin_Page::capability() ) ) {
			return;
		}
		if ( MWWPF_Storage::is_configured_dir_rejected() ) {
			printf(
				'<div class="notice notice-error mwwpf-storage-rejected"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'File Upload for MW WP Form:', 'mwwpf-file-upload' ),
				esc_html( self::rejected_message() )
			);
		}
		if ( ! self::storage_may_be_public() ) {
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
