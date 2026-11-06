<?php
/**
 * Update notifications from GitHub Releases (Plugin Update Checker).
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Updater.
 */
class MWWPF_Updater {

	/**
	 * Repository that publishes the releases.
	 */
	const REPOSITORY = 'https://github.com/stagehand-wp-plugins/mwwpf-file-upload/';

	/**
	 * The release asset to install (the built zip, not GitHub's source zip).
	 */
	const ASSET = '/^mwwpf-file-upload\.zip$/';

	/**
	 * The update checker, once registered.
	 *
	 * @var object|null
	 */
	private static $checker = null;

	/**
	 * Starts checking for updates. WordPress then shows them like any other plugin update, and the
	 * site's own "Enable auto-updates" setting decides whether they are installed automatically.
	 *
	 * Does nothing when the library is missing (for example before `composer install` in development),
	 * so that the plugin itself keeps working.
	 *
	 * Registers once: the library refuses a second checker for the same slug.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @return object|null The update checker, or null.
	 */
	public static function register( $plugin_file ) {
		if ( null !== self::$checker ) {
			return self::$checker;
		}
		$autoload = dirname( $plugin_file ) . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return null;
		}
		require_once $autoload;
		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return null;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( self::REPOSITORY, $plugin_file, 'mwwpf-file-upload' );
		$api     = $checker->getVcsApi();
		// Only a release with the built zip is an update. Without this, the library falls back to the latest
		// tag or the main branch and would install GitHub's source zip, which has no vendor/ directory.
		$api->enableReleaseAssets( self::ASSET, $api::REQUIRE_RELEASE_ASSETS );
		add_filter(
			$checker->getUniqueName( 'vcs_update_detection_strategies' ),
			static function ( $strategies ) use ( $api ) {
				return array_intersect_key( (array) $strategies, array( $api::STRATEGY_LATEST_RELEASE => true ) );
			}
		);

		// Pre-releases (tags such as v1.1.0-rc.1) are offered only to sites that opt in, such as staging.
		if ( defined( 'MWWPF_UPDATE_PRERELEASES' ) && MWWPF_UPDATE_PRERELEASES ) {
			$api->setReleaseFilter( null, $api::RELEASE_FILTER_ALL );
		}
		self::$checker = $checker;
		return $checker;
	}

	/**
	 * Returns the registered update checker, or null.
	 *
	 * @return object|null
	 */
	public static function checker() {
		return self::$checker;
	}
}
