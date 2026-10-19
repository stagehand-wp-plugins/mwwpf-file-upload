<?php
/**
 * Plugin Name: MWWPF Dev Mail
 * Description: Dev-only. Sends all mail to a local SMTP catcher (Mailpit) when MWWPF_DEV_SMTP_HOST is set.
 *
 * MWWPF_DEV_SMTP_HOST is set only in .wp-env.override.json (not committed), so tests and CI are unaffected.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MWWPF_DEV_SMTP_HOST' ) || ! MWWPF_DEV_SMTP_HOST ) {
	return;
}

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer properties.
		$phpmailer->isSMTP();
		$phpmailer->Host        = MWWPF_DEV_SMTP_HOST;
		$phpmailer->Port        = defined( 'MWWPF_DEV_SMTP_PORT' ) ? (int) MWWPF_DEV_SMTP_PORT : 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPSecure  = '';
		$phpmailer->SMTPAutoTLS = false;
		// phpcs:enable
	}
);
