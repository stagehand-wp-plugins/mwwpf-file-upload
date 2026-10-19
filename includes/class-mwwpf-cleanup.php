<?php
/**
 * Scheduled cleanup of temporary files.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleanup.
 */
class MWWPF_Cleanup {

	/**
	 * WP-Cron hook.
	 */
	const HOOK = 'mwwpf_cleanup';

	/**
	 * Files younger than this are never treated as orphans (they may be mid-write).
	 */
	const ORPHAN_GRACE = HOUR_IN_SECONDS;

	/**
	 * Registers the cron handler and makes sure the event is scheduled.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the hourly event when it is missing.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::schedule();
	}

	/**
	 * Deactivation hook. Stored files are kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Runs the cleanup.
	 *
	 * @return int Number of deleted files.
	 */
	public static function run() {
		return self::clean_tmp();
	}

	/**
	 * Deletes expired, consumed and orphaned temporary files, and old mail copies.
	 *
	 * @param int|null $now Current time (for tests).
	 * @return int Number of deleted files.
	 */
	public static function clean_tmp( $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$dir     = MWWPF_Storage::root_dir() . '/tmp';
		$deleted = 0;

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$ttl = MWWPF_Storage::ttl();

		foreach ( (array) glob( $dir . '/*.json' ) as $json ) {
			$token = basename( $json, '.json' );
			if ( ! MWWPF_Storage::is_valid_token( $token ) ) {
				continue;
			}

			$meta    = MWWPF_Storage::read_meta( $token );
			$bin     = MWWPF_Storage::tmp_path( $token, 'bin' );
			$created = $meta ? (int) $meta['created'] : (int) filemtime( $json );
			$expired = $created + $ttl < $now;
			$orphan  = ! is_file( $bin ) && filemtime( $json ) + self::ORPHAN_GRACE < $now;
			$broken  = ! $meta && filemtime( $json ) + self::ORPHAN_GRACE < $now;

			if ( $expired || $orphan || $broken || ( $meta && ! empty( $meta['consumed'] ) ) ) {
				$deleted += (int) MWWPF_Storage::safe_unlink( $bin );
				$deleted += (int) MWWPF_Storage::safe_unlink( $json );
			}
		}

		// .bin without metadata.
		foreach ( (array) glob( $dir . '/*.bin' ) as $bin ) {
			$token = basename( $bin, '.bin' );
			if ( MWWPF_Storage::is_valid_token( $token ) && ! is_file( MWWPF_Storage::tmp_path( $token, 'json' ) ) && filemtime( $bin ) + self::ORPHAN_GRACE < $now ) {
				$deleted += (int) MWWPF_Storage::safe_unlink( $bin );
			}
		}

		// Copies made for mail attachments.
		foreach ( (array) glob( $dir . '/send-*', GLOB_ONLYDIR ) as $send_dir ) {
			if ( filemtime( $send_dir ) + HOUR_IN_SECONDS < $now ) {
				MWWPF_Storage::safe_rmdir( $send_dir );
			}
		}

		return $deleted;
	}
}
