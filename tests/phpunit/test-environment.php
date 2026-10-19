<?php
/**
 * Smoke test for the test environment.
 *
 * @package MWWPF_File_Upload
 */

/**
 * Checks that MW WP Form is loaded.
 */
class Test_Environment extends WP_UnitTestCase {

	/**
	 * MW WP Form's form field base class is available.
	 */
	public function test_mw_wp_form_is_loaded() {
		$this->assertTrue( class_exists( 'MW_WP_Form_Abstract_Form_Field' ) );
	}
}
