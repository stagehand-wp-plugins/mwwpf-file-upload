<?php
/**
 * Proxy for the minfilesize rule (minimum image size).
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minfilesize: the uploaded image must not be narrower or shorter than the limits.
 */
class MWWPF_Rule_Minfilesize extends MWWPF_Rule_Maxfilesize {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'minfilesize';

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
		return $image_w < $limit_w || $image_h < $limit_h;
	}

	/**
	 * Default message (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function default_message() {
		return __( 'This image size is too small.', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Settings label (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function admin_label() {
		return __( 'Minimum image size', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}
}
