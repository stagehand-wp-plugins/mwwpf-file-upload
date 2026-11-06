<?php
/**
 * Tests for the archive of sent files (§6.9, §6.10) and the storage warning (§6.4).
 *
 * @package MWWPF_File_Upload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests manipulate files directly.

/**
 * Archive tests.
 */
class Test_Archive extends WP_UnitTestCase {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Form key.
	 *
	 * @var string
	 */
	private $form_key;

	/**
	 * Sets up a form and a visitor.
	 */
	public function set_up() {
		parent::set_up();
		MWWPF_Form_Scanner::flush();
		MWWPF_Mail::flush();
		$this->form_id                    = self::factory()->post->create(
			array(
				'post_type'    => 'mw-wp-form',
				'post_content' => '[mwform_file name="attach"] [mwform_image name="photo"]',
			)
		);
		$this->form_key                   = 'mw-wp-form-' . $this->form_id;
		$_COOKIE[ MWWPF_Storage::COOKIE ] = str_repeat( 'a', 32 );
		MWWPF_Storage::reset_sid();
		delete_option( MWWPF_Archive::RETENTION_OPTION );
	}

	/**
	 * Cleans up.
	 */
	public function tear_down() {
		MWWPF_Storage::safe_rmdir( MWWPF_Storage::root_dir() . '/tmp' );
		MWWPF_Storage::safe_rmdir( MWWPF_Storage::root_dir() . '/files' );
		MWWPF_Test_Files::cleanup();
		unset( $_COOKIE[ MWWPF_Storage::COOKIE ] );
		MWWPF_Storage::reset_sid();
		MWWPF_Mail::flush();
		delete_option( MWWPF_Archive::RETENTION_OPTION );
		parent::tear_down();
	}

	/**
	 * Stores a temporary upload.
	 *
	 * @param string $field Field.
	 * @param string $name  Name.
	 * @return string Token.
	 */
	private function upload( $field, $name ) {
		$path = MWWPF_Test_Files::write( 'x.pdf', MWWPF_Test_Files::pdf() );
		$meta = MWWPF_Storage::store(
			$path,
			array(
				'form_id' => $this->form_id,
				'field'   => $field,
				'type'    => 'file',
				'name'    => $name,
				'ext'     => 'pdf',
				'mime'    => 'application/pdf',
				'size'    => filesize( $path ),
			),
			false
		);
		return $meta['token'];
	}

	/**
	 * Simulates a sent submission and returns the archived metadata.
	 *
	 * @param array $tokens        Field => token.
	 * @param int   $saved_mail_id Inquiry data ID.
	 * @return array
	 */
	private function send( array $tokens, $saved_mail_id = 0 ) {
		$data = MW_WP_Form_Data::connect( $this->form_key, array( 'mwwpf_token' => $tokens ) );
		$data->set_saved_mail_id( $saved_mail_id );
		MWWPF_Mail::flush();
		return MWWPF_Archive::archive_submission( $this->form_key, clone $data );
	}

	/**
	 * Archives one file with a given archive time.
	 *
	 * @param int $archived Time.
	 * @return array Archived metadata.
	 */
	private function archived_at( $archived ) {
		$items = $this->send( array( 'attach' => $this->upload( 'attach', 'a.pdf' ) ) );
		$meta  = $items[0];
		$path  = MWWPF_Archive::path( $meta['form_id'], $meta['token'], 'json' );
		$json  = json_decode( file_get_contents( $path ), true );

		$json['archived'] = $archived;
		file_put_contents( $path, wp_json_encode( $json ) );
		return $meta;
	}

	/**
	 * Test: sending moves the files to files/{form_id}/ and consumes the tokens (S7).
	 */
	public function test_send_moves_files_and_consumes_tokens() {
		$attach = $this->upload( 'attach', '見積.pdf' );
		$photo  = $this->upload( 'photo', 'p.pdf' );

		$items = $this->send(
			array(
				'attach' => $attach,
				'photo'  => $photo,
			),
			123
		);

		$this->assertCount( 2, $items );
		$this->assertSame( $items[0]['submission'], $items[1]['submission'] );
		foreach ( array( $attach, $photo ) as $token ) {
			$this->assertFileDoesNotExist( MWWPF_Storage::tmp_path( $token, 'bin' ) );
			$this->assertFileDoesNotExist( MWWPF_Storage::tmp_path( $token, 'json' ) );
			$this->assertFileExists( MWWPF_Storage::root_dir() . '/files/' . $this->form_id . '/' . $token . '.bin' );
			// S7: the token cannot be used again.
			$this->assertNull( MWWPF_Storage::resolve( $token, $this->form_id, 'attach' ) );
		}

		$meta = MWWPF_Archive::read( $this->form_id, $attach );
		$this->assertSame( '見積.pdf', $meta['name'] );
		$this->assertSame( 123, $meta['saved_mail_id'] );
		$this->assertArrayNotHasKey( 'sid_hash', $meta );
		$this->assertFileExists( MWWPF_Storage::root_dir() . '/files/' . $this->form_id . '/.htaccess' );
	}

	/**
	 * Test: without DB saving there is no inquiry ID; invalid tokens are skipped.
	 */
	public function test_send_without_saved_mail_id_and_with_invalid_token() {
		$items = $this->send(
			array(
				'attach' => $this->upload( 'attach', 'a.pdf' ),
				'photo'  => str_repeat( 'f', 32 ),
			)
		);

		$this->assertCount( 1, $items );
		$this->assertNull( MWWPF_Archive::read( $this->form_id, $items[0]['token'] )['saved_mail_id'] );
	}

	/**
	 * Test: query order, paging and filter.
	 */
	public function test_query() {
		$old = $this->archived_at( time() - 100 );
		$new = $this->archived_at( time() - 10 );

		$all = MWWPF_Archive::query();
		$this->assertSame( 2, $all['total'] );
		$this->assertSame( $new['token'], $all['items'][0]['token'] );

		$page2 = MWWPF_Archive::query(
			array(
				'per_page' => 1,
				'paged'    => 2,
			)
		);
		$this->assertSame( $old['token'], $page2['items'][0]['token'] );

		$this->assertSame( 0, MWWPF_Archive::query( array( 'form_id' => $this->form_id + 1 ) )['total'] );
		$this->assertSame( array( $this->form_id ), MWWPF_Archive::form_ids() );
		$this->assertSame( 2, MWWPF_Archive::stats()['count'] );
	}

	/**
	 * Test: S11 delete and paths reject invalid input.
	 */
	public function test_s11_invalid_paths_are_rejected() {
		$meta = $this->archived_at( time() );

		$this->assertNull( MWWPF_Archive::path( '../' . $this->form_id, $meta['token'] ) );
		$this->assertNull( MWWPF_Archive::path( $this->form_id . 'x', $meta['token'] ) );
		$this->assertNull( MWWPF_Archive::path( $this->form_id, '../' . $meta['token'] ) );
		$this->assertNull( MWWPF_Archive::path( 0, $meta['token'] ) );
		$this->assertFalse( MWWPF_Archive::delete( '../' . $this->form_id, $meta['token'] ) );
		$this->assertNotNull( MWWPF_Archive::read( $this->form_id, $meta['token'] ) );

		$this->assertTrue( MWWPF_Archive::delete( (string) $this->form_id, $meta['token'] ) );
		$this->assertNull( MWWPF_Archive::read( $this->form_id, $meta['token'] ) );
		$this->assertFalse( MWWPF_Archive::delete( $this->form_id, $meta['token'] ) );
	}

	/**
	 * Test: retention period (T12).
	 */
	public function test_retention() {
		$this->assertSame( 30, MWWPF_Archive::retention_days() );

		update_option( MWWPF_Archive::RETENTION_OPTION, 1 );
		$expired = $this->archived_at( time() - DAY_IN_SECONDS - 60 );
		$fresh   = $this->archived_at( time() - DAY_IN_SECONDS + 60 );

		update_option( MWWPF_Archive::RETENTION_OPTION, 0 );
		MWWPF_Archive::clean();
		$this->assertNotNull( MWWPF_Archive::read( $this->form_id, $expired['token'] ) );
		$this->assertNull( MWWPF_Archive::expires_at( $fresh ) );

		update_option( MWWPF_Archive::RETENTION_OPTION, 1 );
		MWWPF_Cleanup::run();
		$this->assertNull( MWWPF_Archive::read( $this->form_id, $expired['token'] ) );
		$this->assertFileDoesNotExist( MWWPF_Archive::path( $this->form_id, $expired['token'] ) );
		$this->assertNotNull( MWWPF_Archive::read( $this->form_id, $fresh['token'] ) );
	}

	/**
	 * Test: orphaned archive files are removed after the grace period.
	 */
	public function test_orphans() {
		$meta = $this->archived_at( time() );
		$json = MWWPF_Archive::path( $this->form_id, $meta['token'], 'json' );
		unlink( $json );
		$bin = MWWPF_Archive::path( $this->form_id, $meta['token'] );

		MWWPF_Archive::clean();
		$this->assertFileExists( $bin );

		touch( $bin, time() - 2 * HOUR_IN_SECONDS );
		MWWPF_Archive::clean();
		$this->assertFileDoesNotExist( $bin );
	}

	/**
	 * Test: storage warning conditions (§6.4).
	 */
	public function test_storage_warning() {
		$nginx = static function () {
			return true;
		};

		$this->assertFalse( MWWPF_Admin_Notice::storage_may_be_public() );

		add_filter( 'mwwpf_server_ignores_htaccess', $nginx );
		$public = MWWPF_Admin_Notice::storage_may_be_public();
		remove_filter( 'mwwpf_server_ignores_htaccess', $nginx );

		// The default storage is under uploads/, inside ABSPATH.
		$this->assertTrue( $public );
		$this->assertTrue( MWWPF_Admin_Notice::is_inside( '/var/www/html/wp-content/uploads/mwwpf', '/var/www/html/' ) );
		$this->assertFalse( MWWPF_Admin_Notice::is_inside( '/var/www/private/mwwpf', '/var/www/html/' ) );
		$this->assertFalse( MWWPF_Admin_Notice::is_inside( '/var/www/html-other/mwwpf', '/var/www/html' ) );
	}
}
