<?php
/**
 * Tests for MWWPF_Form_Scanner.
 *
 * @package MWWPF_File_Upload
 */

/**
 * Form scanner tests.
 */
class Test_Form_Scanner extends WP_UnitTestCase {

	/**
	 * Clears the scanner cache between tests.
	 */
	public function set_up() {
		parent::set_up();
		MWWPF_Form_Scanner::flush();
	}

	/**
	 * Creates a form with the given content.
	 *
	 * @param string $content Form content.
	 * @return int
	 */
	private function create_form( $content ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'mw-wp-form',
				'post_content' => $content,
			)
		);
	}

	/**
	 * Test: lists file and image fields.
	 */
	public function test_lists_file_and_image_fields() {
		$form_id = $this->create_form( '<p>[mwform_text name="your-name"]</p><p>[mwform_file name="attach" id="a"]</p><p>[mwform_image name="photo"]</p>' );

		$fields = MWWPF_Form_Scanner::get_fields( $form_id );

		$this->assertSame( array( 'attach', 'photo' ), array_keys( $fields ) );
		$this->assertSame( 'file', $fields['attach']['type'] );
		$this->assertSame( 'a', $fields['attach']['atts']['id'] );
		$this->assertSame( 'image', $fields['photo']['type'] );
	}

	/**
	 * Test: ignores invalid names and escaped shortcodes.
	 */
	public function test_ignores_invalid_names_and_escaped_shortcodes() {
		$form_id = $this->create_form( '[mwform_file name="../x"] [mwform_file name="a b"] [mwform_file] [[mwform_file name="escaped"]] [mwform_file name="ok_1-x"]' );

		$this->assertSame( array( 'ok_1-x' ), array_keys( MWWPF_Form_Scanner::get_fields( $form_id ) ) );
	}

	/**
	 * Test: returns nothing for other post types.
	 */
	public function test_returns_nothing_for_other_post_types() {
		$post_id = self::factory()->post->create( array( 'post_content' => '[mwform_file name="attach"]' ) );

		$this->assertSame( array(), MWWPF_Form_Scanner::get_fields( $post_id ) );
		$this->assertSame( array(), MWWPF_Form_Scanner::get_fields( 0 ) );
		$this->assertSame( array(), MWWPF_Form_Scanner::get_fields( 999999 ) );
	}

	/**
	 * Test: get field rejects unknown and malformed names.
	 */
	public function test_get_field_rejects_unknown_and_malformed_names() {
		$form_id = $this->create_form( '[mwform_file name="attach"]' );

		$this->assertNotNull( MWWPF_Form_Scanner::get_field( $form_id, 'attach' ) );
		$this->assertNull( MWWPF_Form_Scanner::get_field( $form_id, 'other' ) );
		$this->assertNull( MWWPF_Form_Scanner::get_field( $form_id, "attach\0" ) );
		$this->assertNull( MWWPF_Form_Scanner::get_field( $form_id, array( 'attach' ) ) );
	}

	/**
	 * Test: filter can add fields but not invalid ones.
	 */
	public function test_filter_can_add_fields_but_not_invalid_ones() {
		$form_id = $this->create_form( '' );
		$filter  = static function ( $fields ) {
			$fields['generated'] = array( 'type' => 'image' );
			$fields['../bad']    = array( 'type' => 'file' );
			$fields['badtype']   = array( 'type' => 'video' );
			return $fields;
		};
		add_filter( 'mwwpf_form_fields', $filter );

		$fields = MWWPF_Form_Scanner::get_fields( $form_id );

		remove_filter( 'mwwpf_form_fields', $filter );
		$this->assertSame( array( 'generated' ), array_keys( $fields ) );
		$this->assertSame( array(), $fields['generated']['atts'] );
	}

	/**
	 * Test: form id from key.
	 */
	public function test_form_id_from_key() {
		$this->assertSame( 12, MWWPF_Form_Scanner::form_id_from_key( 'mw-wp-form-12' ) );
		$this->assertSame( 0, MWWPF_Form_Scanner::form_id_from_key( 'mw-wp-form-12x' ) );
		$this->assertSame( 0, MWWPF_Form_Scanner::form_id_from_key( 'other-12' ) );
		$this->assertSame( 0, MWWPF_Form_Scanner::form_id_from_key( null ) );
	}
}
