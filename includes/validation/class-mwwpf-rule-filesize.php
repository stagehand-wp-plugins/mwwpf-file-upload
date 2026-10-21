<?php
/**
 * Proxy for the filesize rule.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filesize: the uploaded file must not be larger than "bytes".
 */
class MWWPF_Rule_Filesize extends MWWPF_Rule_Proxy {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'filesize';

	/**
	 * Validates. Like MW WP Form, an invalid "bytes" value is an error.
	 *
	 * @param array|null $meta    Metadata.
	 * @param string     $token   Token.
	 * @param array      $options Options.
	 * @return string|null
	 */
	protected function check( $meta, $token, array $options ) {
		if ( ! $meta ) {
			return null;
		}

		$bytes = isset( $options['bytes'] ) ? (string) $options['bytes'] : '0';
		if ( preg_match( '/^\d+$/', $bytes ) && (int) $bytes >= (int) $meta['size'] ) {
			return null;
		}
		return $this->message( $options, __( 'This file size is too big.', 'mw-wp-form' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Settings UI compatible with MW WP Form.
	 *
	 * @param string $key   Row key.
	 * @param array  $value Saved values.
	 */
	protected function admin_fallback( $key, array $value ) {
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Same labels as MW WP Form.
		?>
		<table>
			<tr>
				<td><?php esc_html_e( 'Permitted file size', 'mw-wp-form' ); ?></td>
				<td><input type="text" value="<?php echo esc_attr( $this->saved( $value, 'bytes' ) ); ?>" name="<?php echo esc_attr( $this->field_name( $key, 'bytes' ) ); ?>" /> <span class="mwf_note"><?php esc_html_e( 'bytes', 'mw-wp-form' ); ?></span></td>
			</tr>
		</table>
		<?php
		// phpcs:enable
	}
}
