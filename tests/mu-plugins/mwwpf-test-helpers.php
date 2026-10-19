<?php
/**
 * Plugin Name: MWWPF Test Helpers
 * Description: Test-only. Admin Ajax endpoints the E2E tests use to inspect captured mail.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MWWPF_TEST_MAIL_CAPTURE' ) || ! MWWPF_TEST_MAIL_CAPTURE ) {
	return;
}

/**
 * Returns the mail log directory.
 *
 * @return string
 */
function mwwpf_test_mail_log_dir() {
	return WP_CONTENT_DIR . '/mwwpf-mail-log';
}

/**
 * Stops the request unless the current user is an administrator.
 */
function mwwpf_test_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}
}

add_action(
	'wp_ajax_mwwpf_test_mails',
	static function () {
		mwwpf_test_require_admin();

		$mails = array();
		foreach ( glob( mwwpf_test_mail_log_dir() . '/*/mail.json' ) as $file ) {
			$mail  = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$files = array();
			foreach ( glob( dirname( $file ) . '/*' ) as $path ) {
				if ( 'mail.json' === basename( $path ) ) {
					continue;
				}
				$files[ basename( $path ) ] = array(
					'size' => filesize( $path ),
					'md5'  => md5_file( $path ),
				);
			}
			$mail['id']    = basename( dirname( $file ) );
			$mail['files'] = $files;
			$mails[]       = $mail;
		}

		usort(
			$mails,
			static function ( $a, $b ) {
				return strcmp( $a['id'], $b['id'] );
			}
		);

		wp_send_json_success( $mails );
	}
);

add_action(
	'wp_ajax_mwwpf_test_clear_mails',
	static function () {
		mwwpf_test_require_admin();

		foreach ( glob( mwwpf_test_mail_log_dir() . '/*', GLOB_ONLYDIR ) as $dir ) {
			array_map( 'unlink', glob( $dir . '/*' ) );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		wp_send_json_success();
	}
);
