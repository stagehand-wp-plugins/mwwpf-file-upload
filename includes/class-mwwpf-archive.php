<?php
/**
 * Archive of sent files: move after sending, list, delete, retention.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Archive.
 *
 * Sent files are kept in files/{form_id}/{token}.bin with metadata in {token}.json,
 * for every form (whatever MW WP Form's "save to database" setting is). Nothing is
 * registered in the media library. Paths are only ever built from an integer form ID
 * and a validated token.
 */
class MWWPF_Archive {

	/**
	 * Option name of the retention period in days.
	 */
	const RETENTION_OPTION = 'mwwpf_retention_days';

	/**
	 * Default retention period in days.
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Form keys whose hooks are registered.
	 *
	 * @var array<string, bool>
	 */
	private static $registered = array();

	/**
	 * Registers the per-form hook. Called on mwform_start_main_process.
	 *
	 * @param string $form_key Form key.
	 */
	public static function register( $form_key ) {
		if ( ! is_string( $form_key ) || isset( self::$registered[ $form_key ] ) || ! MWWPF_Form_Scanner::form_id_from_key( $form_key ) ) {
			return;
		}
		self::$registered[ $form_key ] = true;

		add_action(
			'mwform_after_send_' . $form_key,
			static function ( $data ) use ( $form_key ) {
				self::archive_submission( $form_key, $data );
			}
		);
	}

	/**
	 * Moves the uploaded files of a sent form to the archive and consumes their tokens.
	 *
	 * @param string $form_key Form key.
	 * @param object $data     MW_WP_Form_Data (a clone).
	 * @return array Archived metadata.
	 */
	public static function archive_submission( $form_key, $data ) {
		$form_id       = MWWPF_Form_Scanner::form_id_from_key( $form_key );
		$saved_mail_id = ( is_object( $data ) && method_exists( $data, 'get_saved_mail_id' ) ) ? (int) $data->get_saved_mail_id() : 0;
		$submission    = substr( MWWPF_Storage::generate_token(), 0, 16 );
		$archived      = array();

		foreach ( array_keys( MWWPF_Form_Scanner::get_fields( $form_id ) ) as $name ) {
			$meta = MWWPF_Mail::get_upload( $form_key, $name );
			if ( ! $meta ) {
				continue;
			}

			$stored = self::store( $meta, $submission, $saved_mail_id );
			if ( $stored ) {
				$archived[] = $stored;
			}

			MWWPF_Storage::mark_consumed( $meta['token'] );
			MWWPF_Storage::delete( $meta['token'] );
		}

		MWWPF_Mail::flush();
		return $archived;
	}

	/**
	 * Moves one temporary file into the archive.
	 *
	 * @param array  $meta          Resolved temporary metadata (with token).
	 * @param string $submission    Submission ID shared by the files of one submission.
	 * @param int    $saved_mail_id Inquiry data ID, or 0.
	 * @return array|null Archived metadata.
	 */
	public static function store( array $meta, $submission, $saved_mail_id ) {
		$form_id = (int) $meta['form_id'];
		$token   = $meta['token'];
		$dir     = MWWPF_Storage::dir( 'files/' . $form_id );
		if ( ! $dir || ! MWWPF_Storage::is_valid_token( $token ) ) {
			return null;
		}

		$source = MWWPF_Storage::tmp_path( $token, 'bin' );
		$target = $dir . '/' . $token . '.bin';

		// Moving within the storage root; falls back to copy when rename fails.
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! copy( $source, $target ) ) {
				return null;
			}
			MWWPF_Storage::safe_unlink( $source );
		}

		$archived = array(
			'form_id'       => $form_id,
			'field'         => (string) $meta['field'],
			'type'          => (string) $meta['type'],
			'name'          => (string) $meta['name'],
			'ext'           => (string) $meta['ext'],
			'mime'          => (string) $meta['mime'],
			'size'          => (int) $meta['size'],
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'created'       => (int) $meta['created'],
			'archived'      => time(),
			'submission'    => (string) $submission,
			'saved_mail_id' => $saved_mail_id > 0 ? (int) $saved_mail_id : null,
		);

		$json = wp_json_encode( $archived, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json || false === file_put_contents( $dir . '/' . $token . '.json', $json, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			MWWPF_Storage::safe_unlink( $target );
			return null;
		}

		$archived['token'] = $token;
		return $archived;
	}

	/**
	 * Returns archived files, newest first.
	 *
	 * @param array $args form_id (int, 0 = all), paged (int), per_page (int, 0 = all), order (desc|asc).
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'form_id'  => 0,
				'paged'    => 1,
				'per_page' => 20,
				'order'    => 'desc',
			)
		);

		$items = self::all( (int) $args['form_id'] );

		usort(
			$items,
			static function ( $a, $b ) use ( $args ) {
				$cmp = $a['archived'] <=> $b['archived'];
				if ( 0 === $cmp ) {
					$cmp = strcmp( $a['submission'] . $a['field'], $b['submission'] . $b['field'] );
				}
				return 'asc' === $args['order'] ? $cmp : -$cmp;
			}
		);

		$total    = count( $items );
		$per_page = max( 0, (int) $args['per_page'] );
		if ( $per_page > 0 ) {
			$items = array_slice( $items, ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page, $per_page );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Returns the metadata of all archived files, optionally of one form.
	 *
	 * @param int $form_id Form ID, or 0 for all.
	 * @return array
	 */
	public static function all( $form_id = 0 ) {
		$root = MWWPF_Storage::root_dir() . '/files';
		$dirs = $form_id > 0 ? array( $root . '/' . (int) $form_id ) : (array) glob( $root . '/*', GLOB_ONLYDIR );

		$items = array();
		foreach ( $dirs as $dir ) {
			$dir_form_id = (int) basename( $dir );
			if ( $dir_form_id <= 0 || basename( $dir ) !== (string) $dir_form_id ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/*.json' ) as $json ) {
				$meta = self::read( $dir_form_id, basename( $json, '.json' ) );
				if ( $meta ) {
					$items[] = $meta;
				}
			}
		}
		return $items;
	}

	/**
	 * Returns the forms that have archived files.
	 *
	 * @return int[] Form IDs.
	 */
	public static function form_ids() {
		$ids = array();
		foreach ( (array) glob( MWWPF_Storage::root_dir() . '/files/*', GLOB_ONLYDIR ) as $dir ) {
			$id = (int) basename( $dir );
			if ( $id > 0 && basename( $dir ) === (string) $id && glob( $dir . '/*.json' ) ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Reads the metadata of an archived file.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $token   Token.
	 * @return array|null Metadata including the token.
	 */
	public static function read( $form_id, $token ) {
		$path = self::path( $form_id, $token, 'json' );
		if ( ! $path || ! is_file( $path ) || ! is_file( self::path( $form_id, $token, 'bin' ) ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $meta ) || ! isset( $meta['name'], $meta['archived'], $meta['size'] ) ) {
			return null;
		}
		$meta['form_id']    = (int) $form_id;
		$meta['token']      = $token;
		$meta['submission'] = isset( $meta['submission'] ) ? (string) $meta['submission'] : '';
		$meta['field']      = isset( $meta['field'] ) ? (string) $meta['field'] : '';
		return $meta;
	}

	/**
	 * Returns the path of an archived file or its metadata, or null for invalid input.
	 *
	 * @param mixed  $form_id Form ID.
	 * @param mixed  $token   Token.
	 * @param string $ext     bin or json.
	 * @return string|null
	 */
	public static function path( $form_id, $token, $ext = 'bin' ) {
		if ( ! is_numeric( $form_id ) || (int) $form_id <= 0 || (string) (int) $form_id !== (string) $form_id || ! MWWPF_Storage::is_valid_token( $token ) ) {
			return null;
		}
		return MWWPF_Storage::root_dir() . '/files/' . (int) $form_id . '/' . $token . '.' . ( 'json' === $ext ? 'json' : 'bin' );
	}

	/**
	 * Deletes an archived file and its metadata.
	 *
	 * @param mixed $form_id Form ID.
	 * @param mixed $token   Token.
	 * @return bool Whether something was deleted.
	 */
	public static function delete( $form_id, $token ) {
		$bin  = self::path( $form_id, $token, 'bin' );
		$json = self::path( $form_id, $token, 'json' );
		if ( ! $bin || ! $json ) {
			return false;
		}
		$deleted = MWWPF_Storage::safe_unlink( $bin );
		$deleted = MWWPF_Storage::safe_unlink( $json ) || $deleted;
		return $deleted;
	}

	/**
	 * Returns the retention period in days (0 = never delete automatically).
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = get_option( self::RETENTION_OPTION, self::DEFAULT_RETENTION_DAYS );
		return max( 0, min( 3650, (int) $days ) );
	}

	/**
	 * Returns the time an archived file will be deleted, or null when it is kept.
	 *
	 * @param array $meta Metadata.
	 * @return int|null
	 */
	public static function expires_at( array $meta ) {
		$days = self::retention_days();
		return $days > 0 ? (int) $meta['archived'] + $days * DAY_IN_SECONDS : null;
	}

	/**
	 * Returns the number and total size of archived files.
	 *
	 * @return array{count: int, bytes: int}
	 */
	public static function stats() {
		$count = 0;
		$bytes = 0;
		foreach ( self::all() as $meta ) {
			++$count;
			$bytes += (int) $meta['size'];
		}
		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * Deletes files past the retention period, and orphaned files.
	 *
	 * @param int|null $now Current time (for tests).
	 * @return int Number of deleted files.
	 */
	public static function clean( $now = null ) {
		clearstatcache();
		$now     = null === $now ? time() : (int) $now;
		$days    = self::retention_days();
		$deleted = 0;

		foreach ( (array) glob( MWWPF_Storage::root_dir() . '/files/*', GLOB_ONLYDIR ) as $dir ) {
			$form_id = basename( $dir );
			if ( (string) (int) $form_id !== $form_id ) {
				continue;
			}

			foreach ( (array) glob( $dir . '/*.json' ) as $json ) {
				$token = basename( $json, '.json' );
				$meta  = self::read( (int) $form_id, $token );
				if ( $meta ) {
					if ( $days > 0 && (int) $meta['archived'] + $days * DAY_IN_SECONDS < $now ) {
						$deleted += (int) self::delete( $form_id, $token );
					}
				} elseif ( filemtime( $json ) + MWWPF_Cleanup::ORPHAN_GRACE < $now ) {
					$deleted += (int) self::delete( $form_id, $token );
				}
			}

			foreach ( (array) glob( $dir . '/*.bin' ) as $bin ) {
				$token = basename( $bin, '.bin' );
				if ( ! is_file( $dir . '/' . $token . '.json' ) && filemtime( $bin ) + MWWPF_Cleanup::ORPHAN_GRACE < $now ) {
					$deleted += (int) self::delete( $form_id, $token );
				}
			}
		}

		return $deleted;
	}
}
