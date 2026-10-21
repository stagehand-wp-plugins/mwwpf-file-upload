<?php
/**
 * Proxy for the filetype rule.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filetype: the uploaded file's extension must be in "types".
 */
class MWWPF_Rule_Filetype extends MWWPF_Rule_Proxy {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'filetype';

	/**
	 * Validates. No upload passes (noempty handles that), as in MW WP Form.
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

		$types = array();
		foreach ( explode( ',', isset( $options['types'] ) ? (string) $options['types'] : '' ) as $type ) {
			$type = strtolower( ltrim( trim( $type ), '.' ) );
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}

		if ( in_array( strtolower( (string) $meta['ext'] ), $types, true ) ) {
			return null;
		}
		return $this->message( $options, __( 'This file is invalid.', 'mw-wp-form' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
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
				<td><?php esc_html_e( 'Permitted Extension', 'mw-wp-form' ); ?></td>
				<td><input type="text" value="<?php echo esc_attr( $this->saved( $value, 'types' ) ); ?>" name="<?php echo esc_attr( $this->field_name( $key, 'types' ) ); ?>" /> <span class="mwf_note"><?php esc_html_e( 'Example:jpg or jpg,txt,…', 'mw-wp-form' ); ?></span></td>
			</tr>
		</table>
		<?php
		// phpcs:enable
	}
}
