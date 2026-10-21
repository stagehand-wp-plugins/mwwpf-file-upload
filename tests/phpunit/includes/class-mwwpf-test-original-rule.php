<?php
/**
 * Original rule stand-in for the proxy rule tests.
 *
 * @package MWWPF_File_Upload
 */

/**
 * Original rule stand-in. Does not register itself.
 */
class MWWPF_Test_Original_Rule extends MW_WP_Form_Abstract_Validation_Rule {

	/**
	 * Rule name.
	 *
	 * @var string
	 */
	protected $name = 'filetype';

	/**
	 * Constructor without self-registration.
	 */
	public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found

	/**
	 * Returns a marker.
	 *
	 * @param string $name    Name.
	 * @param array  $options Options.
	 * @return string
	 */
	public function rule( $name, array $options = array() ) {
		return 'original:' . $name;
	}

	/**
	 * Prints a marker.
	 *
	 * @param string $key   Key.
	 * @param array  $value Value.
	 */
	public function admin( $key, $value ) {
		echo 'original-admin';
	}
}
