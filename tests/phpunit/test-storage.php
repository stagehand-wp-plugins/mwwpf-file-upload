<?php
/**
 * Tests for temporary storage, tokens and cleanup (§9.2 S3, S7).
 *
 * @package MWWPF_File_Upload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests manipulate files directly.

/**
 * Storage tests.
 */
class Test_Storage extends WP_UnitTestCase {

	/**
	 * Sets a visitor cookie.
	 */
	public function set_up() {
		parent::set_up();
		$this->as_visitor( str_repeat( 'a', 32 ) );
	}

	/**
	 * Removes temporary files.
	 */
	public function tear_down() {
		MWWPF_Storage::safe_rmdir( MWWPF_Storage::root_dir() . '/tmp' );
		MWWPF_Test_Files::cleanup();
		unset( $_COOKIE[ MWWPF_Storage::COOKIE ] );
		MWWPF_Storage::reset_sid();
		parent::tear_down();
	}

	/**
	 * Switches the visitor cookie.
	 *
	 * @param string $sid Visitor ID.
	 */
	private function as_visitor( $sid ) {
		$_COOKIE[ MWWPF_Storage::COOKIE ] = $sid;
		MWWPF_Storage::reset_sid();
	}

	/**
	 * Stores a generated PDF.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $field   Field.
	 * @return array
	 */
	private function store( $form_id = 1, $field = 'attach' ) {
		$path = MWWPF_Test_Files::write( 'a.pdf', MWWPF_Test_Files::pdf() );
		$meta = MWWPF_Storage::store(
			$path,
			array(
				'form_id' => $form_id,
				'field'   => $field,
				'type'    => 'file',
				'name'    => '見積.pdf',
				'ext'     => 'pdf',
				'mime'    => 'application/pdf',
				'size'    => filesize( $path ),
			),
			false
		);
		$this->assertIsArray( $meta );
		return $meta;
	}

	/**
	 * Test: storage directories are protected.
	 */
	public function test_directories_are_protected() {
		$this->store();
		foreach ( array( MWWPF_Storage::root_dir(), MWWPF_Storage::root_dir() . '/tmp' ) as $dir ) {
			$this->assertFileExists( $dir . '/.htaccess' );
			$this->assertStringContainsString( 'Require all denied', file_get_contents( $dir . '/.htaccess' ) );
			$this->assertStringContainsString( 'Deny from all', file_get_contents( $dir . '/.htaccess' ) );
			$this->assertFileExists( $dir . '/index.php' );
		}
	}

	/**
	 * Test: token format and file names.
	 */
	public function test_token_and_paths() {
		$meta = $this->store();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $meta['token'] );
		$this->assertFileExists( MWWPF_Storage::root_dir() . '/tmp/' . $meta['token'] . '.bin' );
		$this->assertFileExists( MWWPF_Storage::root_dir() . '/tmp/' . $meta['token'] . '.json' );
		// The original name is never part of a path.
		$this->assertEmpty( glob( MWWPF_Storage::root_dir() . '/tmp/*.pdf' ) );
		$this->assertArrayNotHasKey( 'sid', MWWPF_Storage::read_meta( $meta['token'] ) );
	}

	/**
	 * Test: resolve checks form, field, token format and owner.
	 */
	public function test_resolve() {
		$meta = $this->store( 1, 'attach' );

		$this->assertSame( '見積.pdf', MWWPF_Storage::resolve( $meta['token'], 1, 'attach' )['name'] );
		$this->assertNull( MWWPF_Storage::resolve( $meta['token'], 2, 'attach' ) );
		$this->assertNull( MWWPF_Storage::resolve( $meta['token'], 1, 'photo' ) );
		$this->assertNull( MWWPF_Storage::resolve( strtoupper( $meta['token'] ), 1, 'attach' ) );
		$this->assertNull( MWWPF_Storage::resolve( '../' . $meta['token'], 1, 'attach' ) );
		$this->assertNull( MWWPF_Storage::resolve( array( $meta['token'] ), 1, 'attach' ) );
	}

	/**
	 * Test: S3 another visitor's token is not resolved.
	 */
	public function test_s3_other_visitor_cannot_use_token() {
		$meta = $this->store();

		$this->as_visitor( str_repeat( 'b', 32 ) );
		$this->assertNull( MWWPF_Storage::resolve( $meta['token'], 1, 'attach' ) );

		unset( $_COOKIE[ MWWPF_Storage::COOKIE ] );
		MWWPF_Storage::reset_sid();
		$this->assertNull( MWWPF_Storage::resolve( $meta['token'], 1, 'attach' ) );
	}

	/**
	 * Test: S7 consumed token is not resolved.
	 */
	public function test_s7_consumed_token_is_not_resolved() {
		$meta = $this->store();

		$this->assertTrue( MWWPF_Storage::mark_consumed( $meta['token'] ) );
		$this->assertNull( MWWPF_Storage::resolve( $meta['token'], 1, 'attach' ) );
	}

	/**
	 * Test: expired token is not resolved.
	 */
	public function test_expired_token_is_not_resolved() {
		$meta = $this->store();
		$ttl  = static function () {
			return 60;
		};
		add_filter( 'mwwpf_tmp_ttl', $ttl );
		$this->set_created( $meta['token'], time() - 61 );

		$resolved = MWWPF_Storage::resolve( $meta['token'], 1, 'attach' );

		remove_filter( 'mwwpf_tmp_ttl', $ttl );
		$this->assertNull( $resolved );
	}

	/**
	 * Test: delete and copy for mail.
	 */
	public function test_delete_and_copy() {
		$meta = $this->store();
		$dir  = MWWPF_Storage::dir( 'tmp/send-test' );

		$copy = MWWPF_Storage::copy_for_mail( $meta['token'], $dir, '見積.pdf' );
		$this->assertFileExists( $copy );
		$this->assertSame( '見積.pdf', basename( $copy ) );
		$this->assertFalse( MWWPF_Storage::copy_for_mail( $meta['token'], get_temp_dir(), 'x.pdf' ) );

		MWWPF_Storage::delete( $meta['token'] );
		$this->assertNull( MWWPF_Storage::read_meta( $meta['token'] ) );
		$this->assertFileDoesNotExist( MWWPF_Storage::tmp_path( $meta['token'], 'bin' ) );
	}

	/**
	 * Test: unlink is limited to the storage root.
	 */
	public function test_safe_unlink_outside_root_is_refused() {
		$outside = MWWPF_Test_Files::write( 'outside.txt', 'x' );
		$this->assertFalse( MWWPF_Storage::safe_unlink( $outside ) );
		$this->assertFileExists( $outside );
		$this->assertFalse( MWWPF_Storage::safe_unlink( MWWPF_Storage::root_dir() . '/tmp/../../' . basename( $outside ) ) );
	}

	/**
	 * Test: cleanup removes expired, consumed and orphaned files only.
	 */
	public function test_cleanup() {
		$fresh    = $this->store();
		$expired  = $this->store();
		$consumed = $this->store();
		$orphan   = $this->store();

		$this->set_created( $expired['token'], time() - DAY_IN_SECONDS - 10 );
		MWWPF_Storage::mark_consumed( $consumed['token'] );
		unlink( MWWPF_Storage::tmp_path( $orphan['token'], 'json' ) );
		touch( MWWPF_Storage::tmp_path( $orphan['token'], 'bin' ), time() - 2 * HOUR_IN_SECONDS );

		$send = MWWPF_Storage::dir( 'tmp/send-old' );
		file_put_contents( $send . '/a.pdf', 'x' );
		touch( $send, time() - 2 * HOUR_IN_SECONDS );

		MWWPF_Cleanup::clean_tmp();

		$this->assertNotNull( MWWPF_Storage::read_meta( $fresh['token'] ) );
		$this->assertNull( MWWPF_Storage::read_meta( $expired['token'] ) );
		$this->assertFileDoesNotExist( MWWPF_Storage::tmp_path( $expired['token'], 'bin' ) );
		$this->assertNull( MWWPF_Storage::read_meta( $consumed['token'] ) );
		$this->assertFileDoesNotExist( MWWPF_Storage::tmp_path( $orphan['token'], 'bin' ) );
		$this->assertDirectoryDoesNotExist( $send );
	}

	/**
	 * Test: cleanup is scheduled hourly.
	 */
	public function test_cleanup_is_scheduled() {
		wp_clear_scheduled_hook( MWWPF_Cleanup::HOOK );
		MWWPF_Cleanup::schedule();
		$this->assertNotFalse( wp_next_scheduled( MWWPF_Cleanup::HOOK ) );
		$this->assertSame( 'hourly', wp_get_schedule( MWWPF_Cleanup::HOOK ) );
	}

	/**
	 * Rewrites the creation time in the metadata.
	 *
	 * @param string $token   Token.
	 * @param int    $created Time.
	 */
	private function set_created( $token, $created ) {
		$path            = MWWPF_Storage::tmp_path( $token, 'json' );
		$meta            = json_decode( file_get_contents( $path ), true );
		$meta['created'] = $created;
		file_put_contents( $path, wp_json_encode( $meta ) );
	}
}
