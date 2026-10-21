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
	// safe_rmdir() refuses the root itself, so remove its contents first, then the root.
	foreach ( (array) scandir( $mwwpf_root ) as $mwwpf_item ) {
		if ( '.' === $mwwpf_item || '..' === $mwwpf_item ) {
			continue;
		}
		$mwwpf_path = $mwwpf_root . '/' . $mwwpf_item;
		if ( is_dir( $mwwpf_path ) && ! is_link( $mwwpf_path ) ) {
			MWWPF_Storage::safe_rmdir( $mwwpf_path );
		} else {
			MWWPF_Storage::safe_unlink( $mwwpf_path );
		}
	}
	@rmdir( $mwwpf_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

delete_option( 'mwwpf_retention_days' );
delete_metadata( 'user', 0, 'mwwpf_attachments_per_page', '', true );
wp_clear_scheduled_hook( 'mwwpf_cleanup' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup of this plugin's rate limit transients.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_mwwpf_rl_' ) . '%', $wpdb->esc_like( '_transient_timeout_mwwpf_rl_' ) . '%' ) );
