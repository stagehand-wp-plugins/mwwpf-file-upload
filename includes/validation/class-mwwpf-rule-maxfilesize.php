<?php
/**
 * Proxy for the maxfilesize rule (maximum image size).
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maxfilesize: the uploaded image must not be wider or taller than the limits.
 */
class MWWPF_Rule_Maxfilesize extends MWWPF_Rule_Proxy {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'maxfilesize';

	/**
	 * Validates. Like MW WP Form: no check when "width" is not numeric, and a file
	 * that is not an image is an error.
	 *
	 * @param array|null $meta    Metadata.
	 * @param string     $token   Token.
	 * @param array      $options Options.
	 * @return string|null
	 */
	protected function check( $meta, $token, array $options ) {
		if ( ! $meta || ! isset( $options['width'] ) || ! is_numeric( $options['width'] ) ) {
			return null;
		}

		$width  = (int) $options['width'];
		$height = ( isset( $options['height'] ) && is_numeric( $options['height'] ) ) ? (int) $options['height'] : 1;

		if ( null === $meta['width'] || null === $meta['height'] || $this->is_out_of_range( (int) $meta['width'], (int) $meta['height'], $width, $height ) ) {
			return $this->message( $options, $this->default_message() );
		}
		return null;
	}

	/**
	 * Whether the image is outside the limit.
	 *
	 * @param int $image_w Image width.
	 * @param int $image_h Image height.
	 * @param int $limit_w Limit width.
	 * @param int $limit_h Limit height.
	 * @return bool
	 */
	protected function is_out_of_range( $image_w, $image_h, $limit_w, $limit_h ) {
		return $image_w > $limit_w || $image_h > $limit_h;
	}

	/**
	 * Default message (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function default_message() {
		return __( 'This image size is too big.', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Settings label (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function admin_label() {
		return __( 'Maximum image size', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Settings UI compatible with MW WP Form.
	 *
	 * @param string $key   Row key.
	 * @param array  $value Saved values.
	 */
	protected function admin_fallback( $key, array $value ) {
		?>
		<table>
			<tr>
				<td><?php echo esc_html( $this->admin_label() ); ?></td>
				<td>
					<input type="text" value="<?php echo esc_attr( $this->saved( $value, 'width' ) ); ?>" size="4" name="<?php echo esc_attr( $this->field_name( $key, 'width' ) ); ?>" />
					&times;
					<input type="text" value="<?php echo esc_attr( $this->saved( $value, 'height' ) ); ?>" size="4" name="<?php echo esc_attr( $this->field_name( $key, 'height' ) ); ?>" />
				</td>
			</tr>
		</table>
		<?php
	}
}
