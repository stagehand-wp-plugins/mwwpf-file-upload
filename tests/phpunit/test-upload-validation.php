<?php
/**
 * Tests for MWWPF_Ajax::validate_file() and file name cleaning (§9.2 S1, S2).
 *
 * @package MWWPF_File_Upload
 */

/**
 * Upload validation tests.
 */
class Test_Upload_Validation extends WP_UnitTestCase {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Creates the §9.1 test form.
	 */
	public function set_up() {
		parent::set_up();
		MWWPF_Form_Scanner::flush();
		$this->form_id = self::factory()->post->create(
			array(
				'post_type'    => 'mw-wp-form',
				'post_content' => '[mwform_file name="attach"] [mwform_image name="photo"] [mwform_file name="any"]',
			)
		);
		update_post_meta(
			$this->form_id,
			'mw-wp-form',
			array(
				'validation' => array(
					array(
						'target'   => 'attach',
						'filetype' => array( 'types' => 'pdf' ),
						'filesize' => array( 'bytes' => '1000' ),
					),
					array(
						'target'      => 'photo',
						'filetype'    => array( 'types' => 'jpg,png' ),
						'maxfilesize' => array(
							'width'  => '20',
							'height' => '20',
						),
						'minfilesize' => array(
							'width'  => '5',
							'height' => '5',
						),
					),
				),
			)
		);
	}

	/**
	 * Removes generated files.
	 */
	public function tear_down() {
		MWWPF_Test_Files::cleanup();
		parent::tear_down();
	}

	/**
	 * Validates a generated file.
	 *
	 * @param string $field    Field.
	 * @param string $name     Original name.
	 * @param string $contents Contents.
	 * @return array|WP_Error
	 */
	private function validate( $field, $name, $contents ) {
		$definition = MWWPF_Form_Scanner::get_field( $this->form_id, $field );
		$path       = MWWPF_Test_Files::write( 'upload', $contents );
		return MWWPF_Ajax::validate_file( $path, $name, $this->form_id, $field, $definition['type'] );
	}

	/**
	 * Asserts a validation error code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Result.
	 */
	private function assertRejected( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Test: valid pdf keeps the Japanese name.
	 */
	public function test_valid_pdf_keeps_japanese_name() {
		$result = $this->validate( 'attach', '見積依頼書（最新版）.pdf', MWWPF_Test_Files::pdf() );

		$this->assertIsArray( $result );
		$this->assertSame( '見積依頼書（最新版）.pdf', $result['name'] );
		$this->assertSame( 'pdf', $result['ext'] );
		$this->assertSame( 'application/pdf', $result['mime'] );
	}

	/**
	 * Test: S1 php renamed to pdf is rejected.
	 */
	public function test_s1_php_renamed_to_pdf_is_rejected() {
		$this->assertRejected( 'filetype', $this->validate( 'attach', 'evil.pdf', "<?php echo 'x';\n" ) );
	}

	/**
	 * Test: S2 double extensions and dangerous names are rejected.
	 */
	public function test_s2_double_extensions_and_dangerous_names() {
		// a.php.pdf with PHP content: the content does not match pdf.
		$this->assertRejected( 'filetype', $this->validate( 'any', 'a.php.pdf', "<?php echo 'x';\n" ) );
		// a.php.pdf with real PDF content is a pdf (it is stored as .bin anyway).
		$this->assertIsArray( $this->validate( 'any', 'a.php.pdf', MWWPF_Test_Files::pdf() ) );
		$this->assertRejected( 'filetype', $this->validate( 'any', 'a.pdf.php', MWWPF_Test_Files::pdf() ) );
		$this->assertRejected( 'filetype', $this->validate( 'any', 'a.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>' ) );
		$this->assertRejected( 'filetype', $this->validate( 'any', '.htaccess', 'Options +ExecCGI' ) );
		$this->assertRejected( 'filetype', $this->validate( 'any', 'page.html', '<html></html>' ) );
	}

	/**
	 * Test: filetype rule limits extensions.
	 */
	public function test_filetype_rule() {
		$this->assertRejected( 'filetype', $this->validate( 'attach', 'a.txt', 'hello' ) );
		$this->assertIsArray( $this->validate( 'any', 'a.txt', 'hello' ) );
	}

	/**
	 * Test: filesize rule.
	 */
	public function test_filesize_rule() {
		$this->assertRejected( 'filesize', $this->validate( 'attach', 'big.pdf', MWWPF_Test_Files::pdf( 1000 ) ) );
	}

	/**
	 * Test: image dimensions and type.
	 */
	public function test_image_rules() {
		$ok = $this->validate( 'photo', 'ok.png', MWWPF_Test_Files::png( 10, 10 ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 10, $ok['width'] );
		$this->assertSame( 10, $ok['height'] );

		$this->assertRejected( 'maxfilesize', $this->validate( 'photo', 'big.png', MWWPF_Test_Files::png( 30, 10 ) ) );
		$this->assertRejected( 'minfilesize', $this->validate( 'photo', 'small.png', MWWPF_Test_Files::png( 2, 10 ) ) );
		// A broken image is rejected.
		$this->assertRejected( 'filetype', $this->validate( 'photo', 'broken.png', "\x89PNG\r\n\x1a\nbroken" ) );
	}

	/**
	 * Test: empty file is rejected.
	 */
	public function test_empty_file() {
		$this->assertRejected( 'empty', $this->validate( 'any', 'a.txt', '' ) );
	}

	/**
	 * Test: clean filename.
	 */
	public function test_clean_filename() {
		$this->assertSame( '見積.pdf', MWWPF_Storage::clean_filename( '見積.pdf' ) );
		$this->assertSame( 'passwd', MWWPF_Storage::clean_filename( '../../etc/passwd' ) );
		$this->assertSame( 'b.pdf', MWWPF_Storage::clean_filename( 'C:\\a\\b.pdf' ) );
		$this->assertSame( 'htaccess', MWWPF_Storage::clean_filename( '..htaccess' ) );
		$this->assertSame( 'ab.pdf', MWWPF_Storage::clean_filename( "a\x00b.pdf" ) );
		$this->assertSame( 'a_b.pdf', MWWPF_Storage::clean_filename( 'a<b.pdf' ) );

		$long = MWWPF_Storage::clean_filename( str_repeat( 'あ', 100 ) . '.pdf' );
		$this->assertLessThanOrEqual( 200, strlen( $long ) );
		$this->assertStringEndsWith( '.pdf', $long );
		$this->assertTrue( mb_check_encoding( $long, 'UTF-8' ) );
	}
}
