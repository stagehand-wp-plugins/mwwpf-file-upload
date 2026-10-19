<?php
/**
 * Plugin Name:       File Upload for MW WP Form
 * Description:       Adds asynchronous file upload to MW WP Form. Not an official MW WP Form product.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mwwpf-file-upload
 * Domain Path:       /languages
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

define( 'MWWPF_VERSION', '0.1.0' );
define( 'MWWPF_PLUGIN_FILE', __FILE__ );
define( 'MWWPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWWPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-plugin.php';

MWWPF_Plugin::instance()->boot();
