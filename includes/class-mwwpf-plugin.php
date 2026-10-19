<?php
/**
 * Bootstrap: dependency check and hook registration.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class MWWPF_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var MWWPF_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Replacement form field objects, keyed by shortcode name.
	 *
	 * @var array<string, MW_WP_Form_Abstract_Form_Field>
	 */
	private $fields = array();

	/**
	 * Whether a file field was rendered on this request.
	 *
	 * @var bool
	 */
	private $needs_assets = false;

	/**
	 * Returns the singleton instance.
	 *
	 * @return MWWPF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the bootstrap hook.
	 */
	public function boot() {
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-form-scanner.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-storage.php';

		// MW WP Form loads its classes on plugins_loaded priority 9.
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	/**
	 * Initializes the plugin once MW WP Form is loaded.
	 */
	public function init() {
		load_plugin_textdomain( 'mwwpf-file-upload', false, dirname( plugin_basename( MWWPF_PLUGIN_FILE ) ) . '/languages' );

		if ( ! self::is_dependency_loaded() ) {
			add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
			return;
		}

		// These extend MW WP Form base classes, so they can only be loaded now.
		require_once MWWPF_PLUGIN_DIR . 'includes/fields/class-mwwpf-field-file.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/fields/class-mwwpf-field-image.php';

		$file  = new MWWPF_Field_File();
		$image = new MWWPF_Field_Image();

		$this->fields = array(
			$file->get_shortcode_name()  => $file,
			$image->get_shortcode_name() => $image,
		);

		// The base class registers each field on priority 10, the same as MW WP Form's own
		// fields, so the winner would be undefined. Override on a later priority instead.
		add_filter( 'mwform_form_fields', array( $this, 'override_form_fields' ), 100 );

		add_action( 'wp_footer', array( $this, 'enqueue_assets' ), 1 );
	}

	/**
	 * Whether the MW WP Form base classes this plugin extends are available.
	 *
	 * @return bool
	 */
	public static function is_dependency_loaded() {
		return class_exists( 'MW_WP_Form_Abstract_Form_Field' )
			&& class_exists( 'MW_WP_Form_Abstract_Validation_Rule' );
	}

	/**
	 * Shows an admin notice when MW WP Form is not active.
	 */
	public function render_dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'File Upload for MW WP Form requires MW WP Form to be installed and active.', 'mwwpf-file-upload' )
		);
	}

	/**
	 * Replaces [mwform_file] and [mwform_image] with this plugin's fields.
	 * Also adds them when MW WP Form no longer provides them (5.2+).
	 *
	 * @param array $form_fields Form field objects keyed by shortcode name.
	 * @return array
	 */
	public function override_form_fields( $form_fields ) {
		if ( ! is_array( $form_fields ) ) {
			$form_fields = array();
		}
		return array_merge( $form_fields, $this->fields );
	}

	/**
	 * Marks that a file field was rendered, so that the assets are enqueued in the footer.
	 */
	public function request_assets() {
		$this->needs_assets = true;
	}

	/**
	 * Enqueues the front-end assets when a file field was rendered.
	 */
	public function enqueue_assets() {
		if ( ! $this->needs_assets ) {
			return;
		}

		wp_enqueue_style(
			'mwwpf',
			MWWPF_PLUGIN_URL . 'assets/css/mwwpf.css',
			array(),
			MWWPF_VERSION
		);
	}
}
