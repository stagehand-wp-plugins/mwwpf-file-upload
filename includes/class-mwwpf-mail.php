<?php
/**
 * Mail tags and attachments.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mail.
 *
 * The token is the source of truth: the file name in mail bodies and attachment names always
 * come from the token's metadata, never from the posted (and possibly tampered) hidden value.
 */
class MWWPF_Mail {

	/**
	 * Form keys whose hooks are registered.
	 *
	 * @var array<string, bool>
	 */
	private static $registered = array();

	/**
	 * Resolved uploads of this request, keyed by form key and field.
	 *
	 * @var array<string, array<string, array|null>>
	 */
	private static $resolved = array();

	/**
	 * Copy directory for attachments of this request.
	 *
	 * @var string|null
	 */
	private static $send_dir = null;

	/**
	 * Registers the per-form hooks. Called on mwform_start_main_process.
	 *
	 * @param string $form_key Form key.
	 */
	public static function register( $form_key ) {
		if ( ! is_string( $form_key ) || isset( self::$registered[ $form_key ] ) || ! MWWPF_Form_Scanner::form_id_from_key( $form_key ) ) {
			return;
		}
		self::$registered[ $form_key ] = true;

		add_filter(
			'mwform_custom_mail_tag_' . $form_key,
			static function ( $value, $name ) use ( $form_key ) {
				return self::mail_tag( $form_key, $value, $name );
			},
			10,
			2
		);

		add_filter(
			'mwform_admin_mail_' . $form_key,
			static function ( $mail ) use ( $form_key ) {
				return self::attach( $form_key, $mail );
			},
			10,
			1
		);
	}

	/**
	 * Replaces {field} in mail (and in the saved inquiry data) with the original file name.
	 *
	 * @param string $form_key Form key.
	 * @param mixed  $value    Value.
	 * @param string $name     Tag name.
	 * @return mixed
	 */
	public static function mail_tag( $form_key, $value, $name ) {
		$form_id = MWWPF_Form_Scanner::form_id_from_key( $form_key );
		// Check the field first: this filter also runs while MW WP Form builds its data object.
		if ( ! MWWPF_Form_Scanner::get_field( $form_id, $name ) ) {
			return $value;
		}

		$meta = self::get_upload( $form_key, $name );
		return $meta ? $meta['name'] : '';
	}

	/**
	 * Attaches the uploaded files to the admin mail under their original names.
	 *
	 * Copies are made because MW WP Form passes array_values() of the attachments to wp_mail(),
	 * so the attachment name is the file name on disk.
	 *
	 * @param string $form_key Form key.
	 * @param object $mail     MW_WP_Form_Mail.
	 * @return object
	 */
	public static function attach( $form_key, $mail ) {
		if ( ! is_object( $mail ) ) {
			return $mail;
		}

		$form_id = MWWPF_Form_Scanner::form_id_from_key( $form_key );
		$used    = array();

		foreach ( array_keys( MWWPF_Form_Scanner::get_fields( $form_id ) ) as $name ) {
			$meta = self::get_upload( $form_key, $name );
			if ( ! $meta ) {
				continue;
			}

			$dir = self::send_dir();
			if ( ! $dir ) {
				break;
			}

			$filename = self::unique_name( $meta['name'], $used );
			$path     = MWWPF_Storage::copy_for_mail( $meta['token'], $dir, $filename );
			if ( ! $path ) {
				continue;
			}

			$attachments          = is_array( $mail->attachments ) ? $mail->attachments : array();
			$attachments[ $name ] = $path;
			$mail->attachments    = $attachments;
		}

		return $mail;
	}

	/**
	 * Returns the resolved upload of a field in the form being processed.
	 *
	 * @param string $form_key Form key.
	 * @param string $name     Field name.
	 * @return array|null
	 */
	public static function get_upload( $form_key, $name ) {
		if ( isset( self::$resolved[ $form_key ] ) && array_key_exists( $name, self::$resolved[ $form_key ] ) ) {
			return self::$resolved[ $form_key ][ $name ];
		}

		$form_id = MWWPF_Form_Scanner::form_id_from_key( $form_key );
		$tokens  = MW_WP_Form_Data::connect( $form_key )->get_post_value_by_key( MWWPF_Field_File::TOKEN_FIELD );
		$token   = ( is_array( $tokens ) && isset( $tokens[ $name ] ) && is_string( $tokens[ $name ] ) ) ? $tokens[ $name ] : '';
		$meta    = '' === $token ? null : MWWPF_Storage::resolve( $token, $form_id, $name );

		self::$resolved[ $form_key ][ $name ] = $meta;
		return $meta;
	}

	/**
	 * Forgets the uploads resolved in this request (for example after they were consumed).
	 */
	public static function flush() {
		self::$resolved = array();
	}

	/**
	 * Returns the copy directory of this request, creating it and scheduling its removal.
	 *
	 * @return string|false
	 */
	private static function send_dir() {
		if ( null === self::$send_dir || ( self::$send_dir && ! is_dir( self::$send_dir ) ) ) {
			self::$send_dir = MWWPF_Storage::dir( 'tmp/send-' . MWWPF_Storage::generate_token() );
			if ( self::$send_dir ) {
				$dir = self::$send_dir;
				register_shutdown_function(
					static function () use ( $dir ) {
						MWWPF_Storage::safe_rmdir( $dir );
					}
				);
			}
		}
		return self::$send_dir;
	}

	/**
	 * Returns a file name that is not used yet in this mail: "a.pdf", "a (2).pdf", ...
	 *
	 * @param string $name  File name.
	 * @param array  $used  Names already used (lowercase); updated.
	 * @return string
	 */
	private static function unique_name( $name, array &$used ) {
		$ext       = pathinfo( $name, PATHINFO_EXTENSION );
		$base      = '' === $ext ? $name : substr( $name, 0, -( strlen( $ext ) + 1 ) );
		$candidate = $name;
		$i         = 2;
		while ( isset( $used[ strtolower( $candidate ) ] ) ) {
			$candidate = $base . ' (' . $i . ')' . ( '' === $ext ? '' : '.' . $ext );
			++$i;
		}
		$used[ strtolower( $candidate ) ] = true;
		return $candidate;
	}
}
