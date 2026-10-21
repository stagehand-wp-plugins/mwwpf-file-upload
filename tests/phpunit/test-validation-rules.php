<?php
/**
 * Tests for the proxy validation rules (§6.7).
 *
 * @package MWWPF_File_Upload
 */

/**
 * Proxy rule tests.
 */
class Test_Validation_Rules extends WP_UnitTestCase {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Sets up a form and a visitor.
	 */
	public function set_up() {
		parent::set_up();
		MWWPF_Form_Scanner::flush();
		$this->form_id                    = self::factory()->post->create(
			array(
				'post_type'    => 'mw-wp-form',
				'post_content' => '[mwform_text name="your-name"] [mwform_file name="attach"] [mwform_image name="photo"]',
			)
		);
		$_COOKIE[ MWWPF_Storage::COOKIE ] = str_repeat( 'c', 32 );
		MWWPF_Storage::reset_sid();
	}

	/**
	 * Cleans up.
	 */
	public function tear_down() {
		MWWPF_Storage::safe_rmdir( MWWPF_Storage::root_dir() . '/tmp' );
		MWWPF_Test_Files::cleanup();
		unset( $_COOKIE[ MWWPF_Storage::COOKIE ] );
		MWWPF_Storage::reset_sid();
		parent::tear_down();
	}

	/**
	 * Returns a mocked MW_WP_Form_Data for the test form with the given tokens.
	 *
	 * @param array $tokens Field => token.
	 * @return MW_WP_Form_Data
	 */
	private function data( array $tokens ) {
		$data = $this->getMockBuilder( 'MW_WP_Form_Data' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_form_key', 'get_post_value_by_key' ) )
			->getMock();
		$data->method( 'get_form_key' )->willReturn( 'mw-wp-form-' . $this->form_id );
		$data->method( 'get_post_value_by_key' )->willReturnCallback(
			static function ( $key ) use ( $tokens ) {
				return 'mwwpf_token' === $key ? $tokens : null;
			}
		);
		return $data;
	}

	/**
	 * Returns a proxy rule bound to data.
	 *
	 * @param string $rule_class Class.
	 * @param array  $tokens Tokens.
	 * @return MWWPF_Rule_Proxy
	 */
	private function rule( $rule_class, array $tokens ) {
		$rule = new $rule_class();
		$rule->set_Data( $this->data( $tokens ) );
		return $rule;
	}

	/**
	 * Stores an uploaded file for a field.
	 *
	 * @param string $field Field.
	 * @param string $name  Name.
	 * @param string $body  Contents.
	 * @param array  $extra Width, height, mime.
	 * @return string Token.
	 */
	private function upload( $field, $name, $body, array $extra = array() ) {
		$path = MWWPF_Test_Files::write( $name, $body );
		$meta = MWWPF_Storage::store(
			$path,
			array_merge(
				array(
					'form_id' => $this->form_id,
					'field'   => $field,
					'type'    => 'photo' === $field ? 'image' : 'file',
					'name'    => $name,
					'ext'     => strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ),
					'mime'    => 'application/pdf',
					'size'    => strlen( $body ),
				),
				$extra
			),
			false
		);
		return $meta['token'];
	}

	/**
	 * Test: the filter replaces the six rules and keeps the originals.
	 */
	public function test_filter_replaces_rules_and_keeps_originals() {
		$original = new MWWPF_Test_Original_Rule();
		$rules    = apply_filters( 'mwform_validation_rules', array( 'filetype' => $original ) );

		foreach ( array( 'noempty', 'required', 'filetype', 'filesize', 'maxfilesize', 'minfilesize' ) as $name ) {
			$this->assertInstanceOf( 'MWWPF_Rule_Proxy', $rules[ $name ], $name );
			$this->assertSame( $name, $rules[ $name ]->get_name() );
		}
		$this->assertSame( $original, $rules['filetype']->get_original() );

		// Applying the filter again (MW WP Form does it on every call) keeps the same objects.
		$again = apply_filters( 'mwform_validation_rules', $rules );
		$this->assertSame( $rules['filetype'], $again['filetype'] );
		$this->assertSame( $original, $again['filetype']->get_original() );
	}

	/**
	 * Test: other fields are delegated to the original rule.
	 */
	public function test_other_fields_are_delegated() {
		$rule = $this->rule( 'MWWPF_Rule_Filetype', array() );
		$rule->set_original( new MWWPF_Test_Original_Rule() );
		$this->assertSame( 'original:your-name', $rule->rule( 'your-name', array( 'types' => 'pdf' ) ) );

		// Without an original (5.2), other fields pass.
		$rule->set_original( null );
		$this->assertNull( $rule->rule( 'your-name', array( 'types' => 'pdf' ) ) );
	}

	/**
	 * Test: noempty and required need a valid upload.
	 */
	public function test_noempty_and_required() {
		$token = $this->upload( 'attach', 'a.pdf', 'x' );

		$this->assertNull( $this->rule( 'MWWPF_Rule_Noempty', array( 'attach' => $token ) )->rule( 'attach' ) );
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Noempty', array( 'attach' => '' ) )->rule( 'attach' ) );
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Noempty', array() )->rule( 'attach' ) );
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Required', array( 'attach' => 'f' . substr( $token, 1 ) ) )->rule( 'attach' ) );
		$this->assertSame( 'custom', $this->rule( 'MWWPF_Rule_Required', array() )->rule( 'attach', array( 'message' => 'custom' ) ) );

		// A token of another field does not count.
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Noempty', array( 'photo' => $token ) )->rule( 'photo' ) );

		// Another visitor's token does not count.
		$_COOKIE[ MWWPF_Storage::COOKIE ] = str_repeat( 'd', 32 );
		MWWPF_Storage::reset_sid();
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Noempty', array( 'attach' => $token ) )->rule( 'attach' ) );
	}

	/**
	 * Test: filetype and filesize use the metadata.
	 */
	public function test_filetype_and_filesize() {
		$token  = $this->upload( 'attach', 'a.pdf', str_repeat( 'x', 100 ) );
		$tokens = array( 'attach' => $token );

		$this->assertNull( $this->rule( 'MWWPF_Rule_Filetype', $tokens )->rule( 'attach', array( 'types' => 'jpg, PDF' ) ) );
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Filetype', $tokens )->rule( 'attach', array( 'types' => 'jpg' ) ) );
		$this->assertNull( $this->rule( 'MWWPF_Rule_Filesize', $tokens )->rule( 'attach', array( 'bytes' => '100' ) ) );
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Filesize', $tokens )->rule( 'attach', array( 'bytes' => '99' ) ) );
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Filesize', $tokens )->rule( 'attach', array( 'bytes' => 'abc' ) ) );

		// No upload: only noempty / required report it.
		$this->assertNull( $this->rule( 'MWWPF_Rule_Filetype', array() )->rule( 'attach', array( 'types' => 'jpg' ) ) );
		$this->assertNull( $this->rule( 'MWWPF_Rule_Filesize', array() )->rule( 'attach', array( 'bytes' => '1' ) ) );
	}

	/**
	 * Test: image size rules.
	 */
	public function test_image_size() {
		$image = $this->upload(
			'photo',
			'p.png',
			MWWPF_Test_Files::png( 30, 20 ),
			array(
				'mime'   => 'image/png',
				'width'  => 30,
				'height' => 20,
			)
		);
		$pdf   = $this->upload( 'photo', 'a.pdf', 'x' );

		$max = array(
			'width'  => '30',
			'height' => '20',
		);
		$this->assertNull( $this->rule( 'MWWPF_Rule_Maxfilesize', array( 'photo' => $image ) )->rule( 'photo', $max ) );
		$this->assertNotEmpty(
			$this->rule( 'MWWPF_Rule_Maxfilesize', array( 'photo' => $image ) )->rule(
				'photo',
				array(
					'width'  => '29',
					'height' => '20',
				)
			)
		);
		$this->assertNotEmpty(
			$this->rule( 'MWWPF_Rule_Minfilesize', array( 'photo' => $image ) )->rule(
				'photo',
				array(
					'width'  => '10',
					'height' => '21',
				)
			)
		);
		$this->assertNull( $this->rule( 'MWWPF_Rule_Minfilesize', array( 'photo' => $image ) )->rule( 'photo', $max ) );
		// Not numeric: no check (same as MW WP Form).
		$this->assertNull( $this->rule( 'MWWPF_Rule_Maxfilesize', array( 'photo' => $image ) )->rule( 'photo', array( 'width' => '' ) ) );
		// Not an image: error (same as MW WP Form).
		$this->assertNotEmpty( $this->rule( 'MWWPF_Rule_Maxfilesize', array( 'photo' => $pdf ) )->rule( 'photo', $max ) );
	}

	/**
	 * Test: admin UI is the original's, or a compatible copy without one.
	 */
	public function test_admin_ui() {
		$rule = new MWWPF_Rule_Filetype();
		$rule->set_original( new MWWPF_Test_Original_Rule() );
		ob_start();
		$rule->admin( '3', array() );
		$this->assertSame( 'original-admin', ob_get_clean() );

		$rule->set_original( null );
		ob_start();
		$rule->admin( '3', array( 'filetype' => array( 'types' => 'pdf' ) ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="mw-wp-form[validation][3][filetype][types]"', $html );
		$this->assertStringContainsString( 'value="pdf"', $html );

		$noempty = new MWWPF_Rule_Noempty();
		ob_start();
		$noempty->admin( '"><script>', array( 'noempty' => 1 ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'checked', $html );
		$this->assertStringNotContainsString( '<script>', $html );

		$max = new MWWPF_Rule_Maxfilesize();
		ob_start();
		$max->admin( '1', array() );
		$this->assertStringContainsString( '[maxfilesize][height]', ob_get_clean() );
	}
}
