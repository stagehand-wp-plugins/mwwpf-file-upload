<?php
/**
 * Tests for the update checker (§6.13).
 *
 * @package MWWPF_File_Upload
 */

/**
 * Updater tests.
 */
class Test_Updater extends WP_UnitTestCase {

	/**
	 * Test: the update checker watches this plugin's GitHub releases.
	 */
	public function test_register() {
		// Registered when the plugin loads; registering again returns the same checker.
		$checker = MWWPF_Updater::checker();
		$this->assertNotNull( $checker );
		$this->assertSame( $checker, MWWPF_Updater::register( MWWPF_PLUGIN_FILE ) );

		// The library's namespace has its minor version (v5p7), so only the class name is checked.
		$this->assertStringEndsWith( '\Vcs\PluginUpdateChecker', get_class( $checker ) );
		$this->assertSame( 'mwwpf-file-upload', $checker->slug );
		$this->assertSame( 'mwwpf-file-upload/mwwpf-file-upload.php', $checker->pluginFile ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertStringEndsWith( '\Vcs\GitHubApi', get_class( $checker->getVcsApi() ) );
	}

	/**
	 * Responds to GitHub API requests.
	 *
	 * @param array $responses Path (after /repos/{owner}/{repo}) => response body, or an HTTP status code.
	 * @return array Requested paths, filled as requests are made.
	 */
	private function github( array $responses ) {
		$requested = new ArrayObject();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $responses, $requested ) {
				$path        = preg_replace( '#^https://api\.github\.com/repos/stagehand-wp-plugins/mwwpf-file-upload#', '', strtok( $url, '?' ) );
				$requested[] = $path;
				$body        = isset( $responses[ $path ] ) ? $responses[ $path ] : 404;
				return array(
					'headers'  => array(),
					'body'     => is_int( $body ) ? '{"message":"Not Found"}' : wp_json_encode( $body ),
					'response' => array(
						'code'    => is_int( $body ) ? $body : 200,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
		return $requested;
	}

	/**
	 * A release as the GitHub API returns it.
	 *
	 * @param string $tag    Tag.
	 * @param bool   $assets Whether the built zip is attached.
	 * @return array
	 */
	private function release( $tag, $assets = true ) {
		return array(
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => false !== strpos( $tag, '-' ),
			'zipball_url'  => 'https://api.github.com/repos/stagehand-wp-plugins/mwwpf-file-upload/zipball/' . $tag,
			'published_at' => '2026-11-07T00:00:00Z',
			'body'         => 'Changes',
			'assets'       => $assets ? array(
				array(
					'name'                 => 'mwwpf-file-upload.zip',
					'url'                  => 'https://api.github.com/repos/stagehand-wp-plugins/mwwpf-file-upload/releases/assets/1',
					'browser_download_url' => 'https://github.com/stagehand-wp-plugins/mwwpf-file-upload/releases/download/' . $tag . '/mwwpf-file-upload.zip',
					'download_count'       => 0,
				),
			) : array(),
		);
	}

	/**
	 * Test: the update is the built zip attached to the latest release.
	 */
	public function test_latest_release() {
		$this->github( array( '/releases/latest' => $this->release( 'v9.0.0' ) ) );
		$update = MWWPF_Updater::checker()->requestUpdate();

		$this->assertSame( '9.0.0', $update->version );
		$this->assertSame( 'https://github.com/stagehand-wp-plugins/mwwpf-file-upload/releases/download/v9.0.0/mwwpf-file-upload.zip', $update->download_url );
	}

	/**
	 * Test: without a release that has the built zip, there is no update (no fallback to tags or the branch).
	 */
	public function test_no_fallback_to_source_zip() {
		$requested = $this->github(
			array(
				'/tags'          => array( array( 'name' => 'v9.0.0' ) ),
				'/branches/main' => array( 'name' => 'main' ),
			)
		);
		$this->assertNull( MWWPF_Updater::checker()->requestUpdate() );
		$this->assertSame( array( '/releases/latest' ), $requested->getArrayCopy() );

		remove_all_filters( 'pre_http_request' );
		$this->github( array( '/releases/latest' => $this->release( 'v9.0.0', false ) ) );
		$this->assertNull( MWWPF_Updater::checker()->requestUpdate() );
	}

	/**
	 * Test: without the library, the plugin keeps working and only the update check is off.
	 */
	public function test_without_library() {
		$property = new ReflectionProperty( 'MWWPF_Updater', 'checker' );
		$property->setAccessible( true );
		$checker = $property->getValue();
		$property->setValue( null, null );

		$this->assertNull( MWWPF_Updater::register( get_temp_dir() . 'mwwpf-no-vendor/mwwpf-file-upload.php' ) );
		$property->setValue( null, $checker );
	}
}
