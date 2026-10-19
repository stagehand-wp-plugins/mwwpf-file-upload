<?php
/**
 * Plugin Name: MWWPF Test Mail Capture
 * Description: Test-only. Stops outgoing mail and stores it as JSON in wp-content/mwwpf-mail-log/.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MWWPF_TEST_MAIL_CAPTURE' ) || ! MWWPF_TEST_MAIL_CAPTURE ) {
	return;
}

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		$log_dir = WP_CONTENT_DIR . '/mwwpf-mail-log';
		$id      = gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$dir     = $log_dir . '/' . $id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$attachments = $atts['attachments'];
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$attachment_names = array();
		foreach ( array_filter( $attachments ) as $key => $path ) {
			$name               = is_string( $key ) ? $key : basename( $path );
			$attachment_names[] = $name;
			if ( is_readable( $path ) ) {
				copy( $path, $dir . '/' . sanitize_file_name( basename( $path ) ) );
			}
		}

		$record = array(
			'to'          => $atts['to'],
			'subject'     => $atts['subject'],
			'message'     => $atts['message'],
			'headers'     => $atts['headers'],
			'attachments' => $attachment_names,
		);

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$dir . '/mail.json',
			wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		return true;
	},
	10,
	2
);
