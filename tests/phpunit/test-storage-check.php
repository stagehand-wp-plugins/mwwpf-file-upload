<?php
/**
 * Tests for the storage location rules and the access check.
 *
 * @package MWWPF_File_Upload
 */

/**
 * Storage check tests.
 */
class Test_Storage_Check extends WP_UnitTestCase {

	/**
	 * Clears the saved result.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( MWWPF_Storage_Check::OPTION );
	}

	/**
	 * Clears the saved result.
	 */
	public function tear_down() {
		delete_option( MWWPF_Storage_Check::OPTION );
		parent::tear_down();
	}

	/**
	 * Responds to the test file request like a server.
	 *
	 * @param int  $code     Status code.
	 * @param bool $echo_file Whether the body is the test file's content.
	 */
	private function respond( $code, $echo_file = false ) {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $code, $echo_file ) {
				$body = '';
				if ( $echo_file ) {
					$path = MWWPF_Storage::root_dir() . '/' . basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
					$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Test: WordPress directories and their parents cannot be the storage root.
	 */
	public function test_unsafe_dirs() {
		$uploads = wp_upload_dir( null, false );

		foreach ( array( '', 'relative/path', '/', ABSPATH, dirname( ABSPATH ), WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), $uploads['basedir'], $uploads['basedir'] . '/', '/tmp/a/../b' ) as $dir ) {
			$this->assertTrue( MWWPF_Storage::is_unsafe_dir( $dir ), $dir );
		}
		foreach ( array( $uploads['basedir'] . '/mwwpf', WP_CONTENT_DIR . '/mwwpf-private', '/tmp/mwwpf-storage', '/home/user/mwwpf-storage/example.com' ) as $dir ) {
			$this->assertFalse( MWWPF_Storage::is_unsafe_dir( $dir ), $dir );
		}

		// Without MWWPF_STORAGE_DIR, the default is used and nothing is rejected.
		$this->assertSame( MWWPF_Storage::default_dir(), MWWPF_Storage::root_dir() );
		$this->assertFalse( MWWPF_Storage::is_configured_dir_rejected() );
	}

	/**
	 * Test: the URL of the storage root.
	 */
	public function test_url_for() {
		$uploads = wp_upload_dir( null, false );
		$this->assertSame( $uploads['baseurl'] . '/mwwpf', MWWPF_Storage_Check::url_for( $uploads['basedir'] . '/mwwpf' ) );
		$this->assertSame( content_url() . '/private', MWWPF_Storage_Check::url_for( WP_CONTENT_DIR . '/private' ) );
		$this->assertNull( MWWPF_Storage_Check::url_for( '/tmp/mwwpf-storage' ) );
	}

	/**
	 * Test: a readable test file means the storage is public, and the warning is shown on any server.
	 */
	public function test_public() {
		$this->respond( 200, true );
		$result = MWWPF_Storage_Check::run();

		$this->assertSame( MWWPF_Storage_Check::PUBLIC_ACCESS, $result['status'] );
		$this->assertSame( 200, $result['code'] );
		$this->assertEmpty( glob( MWWPF_Storage::root_dir() . '/' . MWWPF_Storage::PROBE_PREFIX . '*' ), 'The test file is removed.' );
		$this->assertTrue( MWWPF_Admin_Notice::storage_may_be_public() );
	}

	/**
	 * Test: a 200 response with other content (such as a "not found" page) is not treated as public.
	 */
	public function test_other_content() {
		$this->respond( 200, false );
		$this->assertSame( MWWPF_Storage_Check::UNKNOWN, MWWPF_Storage_Check::run()['status'] );
	}

	/**
	 * Test: a refused request means protected, even on Nginx.
	 */
	public function test_protected() {
		$nginx = static function () {
			return true;
		};
		add_filter( 'mwwpf_server_ignores_htaccess', $nginx );

		// Without a result, the server-software fallback warns on Nginx.
		$this->assertTrue( MWWPF_Admin_Notice::storage_may_be_public() );

		$this->respond( 403 );
		$this->assertSame( MWWPF_Storage_Check::PROTECTED_ACCESS, MWWPF_Storage_Check::run()['status'] );
		$this->assertFalse( MWWPF_Admin_Notice::storage_may_be_public() );
	}

	/**
	 * Test: a failed request falls back to the server-software check.
	 */
	public function test_request_error() {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7' );
			}
		);
		$result = MWWPF_Storage_Check::run();
		$this->assertSame( MWWPF_Storage_Check::UNKNOWN, $result['status'] );
		$this->assertSame( 'cURL error 7', $result['message'] );
		$this->assertFalse( MWWPF_Admin_Notice::storage_may_be_public() );
	}

	/**
	 * Test: the result is reused for a day and ignored when the storage root changes.
	 */
	public function test_cached_result() {
		$this->respond( 403 );
		MWWPF_Storage_Check::run();
		$this->assertSame( MWWPF_Storage_Check::PROTECTED_ACCESS, MWWPF_Storage_Check::status() );

		$result            = get_option( MWWPF_Storage_Check::OPTION );
		$result['checked'] = time() - DAY_IN_SECONDS - 1;
		update_option( MWWPF_Storage_Check::OPTION, $result );
		$this->assertSame( '', MWWPF_Storage_Check::status() );

		$result['checked'] = time();
		$result['root']    = '/somewhere/else';
		update_option( MWWPF_Storage_Check::OPTION, $result );
		$this->assertSame( '', MWWPF_Storage_Check::status() );
	}

	/**
	 * Test: the suggested path is outside the WordPress directory.
	 */
	public function test_suggested_dir() {
		$dir = MWWPF_Storage_Check::suggested_dir();
		$this->assertFalse( MWWPF_Storage::path_contains( ABSPATH, $dir ) );
		$this->assertFalse( MWWPF_Storage::is_unsafe_dir( $dir ) );
		$this->assertStringContainsString( '/mwwpf-storage/', $dir );
	}
}
