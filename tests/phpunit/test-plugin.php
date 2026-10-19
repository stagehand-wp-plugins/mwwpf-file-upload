<?php
/**
 * Tests for MWWPF_Plugin.
 *
 * @package MWWPF_File_Upload
 */

/**
 * Bootstrap tests.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Test: dependency is loaded.
	 */
	public function test_dependency_is_loaded() {
		$this->assertTrue( MWWPF_Plugin::is_dependency_loaded() );
	}

	/**
	 * Test: replaces file and image fields.
	 */
	public function test_replaces_file_and_image_fields() {
		$fields = apply_filters( 'mwform_form_fields', array() );

		$this->assertInstanceOf( 'MWWPF_Field_File', $fields['mwform_file'] );
		$this->assertInstanceOf( 'MWWPF_Field_Image', $fields['mwform_image'] );
	}

	/**
	 * Test: overrides fields registered on default priority.
	 */
	public function test_overrides_fields_registered_on_default_priority() {
		$core = static function ( $fields ) {
			$fields['mwform_file'] = 'core';
			return $fields;
		};
		add_filter( 'mwform_form_fields', $core, 99 );

		$fields = apply_filters( 'mwform_form_fields', array() );

		remove_filter( 'mwform_form_fields', $core, 99 );
		$this->assertInstanceOf( 'MWWPF_Field_File', $fields['mwform_file'] );
	}
}
