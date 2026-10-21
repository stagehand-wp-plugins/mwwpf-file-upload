<?php
/**
 * Proxy for the noempty rule.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Noempty: a valid upload is required.
 */
class MWWPF_Rule_Noempty extends MWWPF_Rule_Proxy {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'noempty';

	/**
	 * Validates.
	 *
	 * @param array|null $meta    Metadata.
	 * @param string     $token   Token.
	 * @param array      $options Options.
	 * @return string|null
	 */
	protected function check( $meta, $token, array $options ) {
		return $meta ? null : $this->message( $options, $this->default_message() );
	}

	/**
	 * Default message (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function default_message() {
		return __( 'Please enter.', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Settings UI compatible with MW WP Form.
	 *
	 * @param string $key   Row key.
	 * @param array  $value Saved values.
	 */
	protected function admin_fallback( $key, array $value ) {
		$checked = isset( $value[ $this->get_name() ] ) ? $value[ $this->get_name() ] : '';
		printf(
			'<label><input type="checkbox" %1$s name="%2$s" value="1" />%3$s</label>',
			checked( $checked, 1, false ),
			esc_attr( $this->field_name( $key ) ),
			esc_html( $this->admin_label() )
		);
	}

	/**
	 * Checkbox label (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function admin_label() {
		return __( 'No empty', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}
}
