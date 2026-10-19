<?php
/**
 * PHPUnit bootstrap. Runs inside the wp-env tests-cli container.
 *
 * @package MWWPF_File_Upload
 */

$mwwpf_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $mwwpf_tests_dir ) {
	$mwwpf_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $mwwpf_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$mwwpf_tests_dir}/includes/functions.php. Run the tests with `npm run test:php`." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Required by the WordPress test library.

require_once $mwwpf_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require WP_PLUGIN_DIR . '/mw-wp-form/mw-wp-form.php';
		require dirname( __DIR__, 2 ) . '/mwwpf-file-upload.php';
	}
);

require $mwwpf_tests_dir . '/includes/bootstrap.php';

require __DIR__ . '/includes/class-mwwpf-test-files.php';
