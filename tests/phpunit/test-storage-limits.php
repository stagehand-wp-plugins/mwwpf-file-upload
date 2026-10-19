<?php
/**
 * Tests for MWWPF_Storage::get_limits().
 *
 * @package MWWPF_File_Upload
 */

/**
 * Per-field limit tests.
 */
class Test_Storage_Limits extends WP_UnitTestCase {

	/**
	 * Allows uploads larger than the defaults so that the server limit does not interfere.
	 */
	public function set_up() {
		parent::set_up();
		MWWPF_Form_Scanner::flush();
		add_filter( 'upload_size_limit', array( $this, 'large_upload_limit' ) );
	}

	/**
	 * Removes the filter.
	 */
	public function tear_down() {
		remove_filter( 'upload_size_limit', array( $this, 'large_upload_limit' ) );
		parent::tear_down();
	}

	/**
	 * Upload size limit for the tests.
	 *
	 * @return int
	 */
	public function large_upload_limit() {
		return 1073741824;
	}

	/**
	 * Creates the §9.1 test form with optional validation settings.
	 *
	 * @param array $validation Validation settings.
	 * @return int
	 */
	private function create_form( array $validation = array() ) {
		$form_id = self::factory()->post->create(
			array(
				'post_type'    => 'mw-wp-form',
				'post_content' => '[mwform_file name="attach"] [mwform_image name="photo"]',
			)
		);
		update_post_meta( $form_id, 'mw-wp-form', array( 'validation' => $validation ) );
		return $form_id;
	}

	/**
	 * Test: defaults without rules.
	 */
	public function test_defaults_without_rules() {
		$form_id = $this->create_form();

		$file  = MWWPF_Storage::get_limits( $form_id, 'attach' );
		$image = MWWPF_Storage::get_limits( $form_id, 'photo' );

		$this->assertSame( 10485760, $file['max_bytes'] );
		$this->assertContains( 'pdf', $file['exts'] );
		$this->assertContains( 'docx', $file['exts'] );
		$this->assertSame( array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), $image['exts'] );
		$this->assertNull( $image['max_w'] );
	}

	/**
	 * Test: reads rules from form settings.
	 */
	public function test_reads_rules_from_form_settings() {
		$form_id = $this->create_form(
			array(
				array(
					'target'   => 'attach',
					'noempty'  => 1,
					'filetype' => array( 'types' => ' PDF, .txt ,php' ),
					'filesize' => array( 'bytes' => '2097152' ),
				),
				array(
					'target'      => 'photo',
					'maxfilesize' => array(
						'width'  => '2000',
						'height' => '1500',
					),
					'minfilesize' => array(
						'width'  => '10',
						'height' => '',
					),
				),
			)
		);

		$file  = MWWPF_Storage::get_limits( $form_id, 'attach' );
		$image = MWWPF_Storage::get_limits( $form_id, 'photo' );

		$this->assertSame( 2097152, $file['max_bytes'] );
		// Denied extensions are dropped even when the form allows them.
		$this->assertSame( array( 'pdf', 'txt' ), $file['exts'] );
		$this->assertSame( 2000, $image['max_w'] );
		$this->assertSame( 1500, $image['max_h'] );
		$this->assertSame( 10, $image['min_w'] );
		$this->assertNull( $image['min_h'] );
	}

	/**
	 * Test: server upload limit caps max bytes.
	 */
	public function test_server_upload_limit_caps_max_bytes() {
		$form_id = $this->create_form();
		$cap     = static function () {
			return 1000;
		};
		add_filter( 'upload_size_limit', $cap, 20 );

		$limits = MWWPF_Storage::get_limits( $form_id, 'attach' );

		remove_filter( 'upload_size_limit', $cap, 20 );
		$this->assertSame( 1000, $limits['max_bytes'] );
	}

	/**
	 * Test: default filters.
	 */
	public function test_default_filters() {
		$form_id = $this->create_form();
		$exts    = static function () {
			return array( 'csv', 'exe' );
		};
		$bytes   = static function () {
			return 123;
		};
		add_filter( 'mwwpf_default_exts', $exts );
		add_filter( 'mwwpf_default_max_bytes', $bytes );

		$limits = MWWPF_Storage::get_limits( $form_id, 'attach' );

		remove_filter( 'mwwpf_default_exts', $exts );
		remove_filter( 'mwwpf_default_max_bytes', $bytes );
		$this->assertSame( array( 'csv' ), $limits['exts'] );
		$this->assertSame( 123, $limits['max_bytes'] );
	}

	/**
	 * Test: denied filenames.
	 */
	public function test_denied_filenames() {
		$this->assertTrue( MWWPF_Storage::is_denied_filename( 'a.php' ) );
		$this->assertTrue( MWWPF_Storage::is_denied_filename( 'a.pdf.PHP' ) );
		$this->assertTrue( MWWPF_Storage::is_denied_filename( '.htaccess' ) );
		$this->assertTrue( MWWPF_Storage::is_denied_filename( '.user.ini' ) );
		$this->assertTrue( MWWPF_Storage::is_denied_filename( 'noext' ) );
		$this->assertTrue( MWWPF_Storage::is_denied_filename( 'a.svg' ) );
		$this->assertFalse( MWWPF_Storage::is_denied_filename( 'a.php.pdf' ) );
	}
}
