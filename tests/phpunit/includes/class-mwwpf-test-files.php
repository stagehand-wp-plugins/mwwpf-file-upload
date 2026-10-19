<?php
/**
 * Generates small test files.
 *
 * @package MWWPF_File_Upload
 */

/**
 * Test file factory.
 */
class MWWPF_Test_Files {

	/**
	 * Created files, removed by cleanup().
	 *
	 * @var string[]
	 */
	private static $files = array();

	/**
	 * Writes a file into the system temp directory and returns its path.
	 *
	 * @param string $name     File name (used as the suffix).
	 * @param string $contents Contents.
	 * @return string
	 */
	public static function write( $name, $contents ) {
		$path = trailingslashit( get_temp_dir() ) . 'mwwpf-' . wp_generate_password( 8, false ) . '-' . $name;
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		self::$files[] = $path;
		return $path;
	}

	/**
	 * Minimal valid PDF.
	 *
	 * @param int $pad Extra bytes to append (to control the size).
	 * @return string
	 */
	public static function pdf( $pad = 0 ) {
		return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" . str_repeat( '%', $pad );
	}

	/**
	 * Minimal valid PNG.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	public static function png( $width, $height ) {
		$row = "\0" . str_repeat( "\xff\x00\x00", $width );
		$raw = str_repeat( $row, $height );

		$chunk = static function ( $type, $data ) {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};

		return "\x89PNG\r\n\x1a\n"
			. $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) )
			. $chunk( 'IDAT', gzcompress( $raw ) )
			. $chunk( 'IEND', '' );
	}

	/**
	 * Removes the created files.
	 */
	public static function cleanup() {
		foreach ( self::$files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		self::$files = array();
	}
}
