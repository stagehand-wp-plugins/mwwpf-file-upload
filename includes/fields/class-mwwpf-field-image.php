<?php
/**
 * Replacement for [mwform_image].
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Image field. Same as the file field, with an image accept default and a thumbnail.
 */
class MWWPF_Field_Image extends MWWPF_Field_File {

	/**
	 * Field type used in data attributes and metadata.
	 *
	 * @return string
	 */
	protected function field_type() {
		return 'image';
	}

	/**
	 * Shortcode name and display name.
	 *
	 * @return array
	 */
	protected function set_names() {
		return array(
			'shortcode_name' => 'mwform_image',
			// Same label as MW WP Form, translated with its text domain.
			'display_name'   => __( 'Image', 'mw-wp-form' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		);
	}

	/**
	 * Default attributes.
	 *
	 * @return array
	 */
	protected function set_defaults() {
		$defaults           = parent::set_defaults();
		$defaults['accept'] = 'image/png,image/jpeg,image/gif,image/webp';
		return $defaults;
	}
}
