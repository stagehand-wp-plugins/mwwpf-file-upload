<?php
/**
 * Proxy for the required rule.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Required: same as noempty for file fields, with MW WP Form's required message and label.
 */
class MWWPF_Rule_Required extends MWWPF_Rule_Noempty {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'required';

	/**
	 * Default message (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function default_message() {
		return __( 'This is required.', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}

	/**
	 * Checkbox label (same as MW WP Form).
	 *
	 * @return string
	 */
	protected function admin_label() {
		return __( 'No empty( with checkbox )', 'mw-wp-form' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}
}
