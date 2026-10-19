<?php
/**
 * Plugin Name: MWWPF Dev Host
 * Description: Dev-only. Serves the site at MWWPF_DEV_HOME (e.g. http://plugins.localhost behind Traefik).
 *
 * The wp-env config always appends the port to WP_SITEURL, so the URL is overridden here instead.
 * MWWPF_DEV_HOME is set only in .wp-env.override.json (not committed), so tests and CI are unaffected.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MWWPF_DEV_HOME' ) || ! MWWPF_DEV_HOME ) {
	return;
}

add_filter( 'option_home', 'mwwpf_dev_home_url', 20 );
add_filter( 'option_siteurl', 'mwwpf_dev_home_url', 20 );

/**
 * Returns the dev host URL.
 *
 * @return string
 */
function mwwpf_dev_home_url() {
	return untrailingslashit( MWWPF_DEV_HOME );
}
