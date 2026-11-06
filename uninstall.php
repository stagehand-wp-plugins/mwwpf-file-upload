<?php
/**
 * Uninstall: removes the storage (temporary and stored files), options, transients and cron.
 * MW WP Form's inquiry data is not touched.
 *
 * @package MWWPF_File_Upload
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-mwwpf-form-scanner.php';
require_once __DIR__ . '/includes/class-mwwpf-storage.php';

$mwwpf_root = MWWPF_Storage::root_dir();
if ( is_dir( $mwwpf_root ) ) {
	// Remove only what this plugin creates, so other files placed in the root by mistake are kept.
	// safe_rmdir() refuses the root itself, so the root is removed last and only when it is empty.
	foreach ( array( 'tmp', 'files' ) as $mwwpf_item ) {
		MWWPF_Storage::safe_rmdir( $mwwpf_root . '/' . $mwwpf_item );
	}
	foreach ( array( '.htaccess', 'index.php' ) as $mwwpf_item ) {
		MWWPF_Storage::safe_unlink( $mwwpf_root . '/' . $mwwpf_item );
	}
	foreach ( (array) glob( $mwwpf_root . '/' . MWWPF_Storage::PROBE_PREFIX . '*' ) as $mwwpf_item ) {
		MWWPF_Storage::safe_unlink( (string) $mwwpf_item );
	}
	@rmdir( $mwwpf_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

delete_option( 'mwwpf_retention_days' );
delete_option( 'mwwpf_storage_check' );
delete_metadata( 'user', 0, 'mwwpf_attachments_per_page', '', true );
wp_clear_scheduled_hook( 'mwwpf_cleanup' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's rate limit transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_mwwpf_rl_' ) . '%', $wpdb->esc_like( '_transient_timeout_mwwpf_rl_' ) . '%' ) );
