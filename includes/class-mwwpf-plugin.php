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
	 * Proxy validation rule objects, keyed by rule name.
	 *
	 * @var array<string, MWWPF_Rule_Proxy>
	 */
	private $rules = array();

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
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-ajax.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-cleanup.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-mail.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-archive.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-admin-notice.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-admin-page.php';

		register_activation_hook( MWWPF_PLUGIN_FILE, array( 'MWWPF_Cleanup', 'activate' ) );
		register_deactivation_hook( MWWPF_PLUGIN_FILE, array( 'MWWPF_Cleanup', 'deactivate' ) );

		// Cleanup and the stored files screen keep working even if MW WP Form is deactivated.
		MWWPF_Cleanup::register();
		if ( is_admin() ) {
			MWWPF_Admin_Page::register();
			MWWPF_Admin_Notice::register();
		}

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

		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-proxy.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-noempty.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-required.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-filetype.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-filesize.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-maxfilesize.php';
		require_once MWWPF_PLUGIN_DIR . 'includes/validation/class-mwwpf-rule-minfilesize.php';

		$file  = new MWWPF_Field_File();
		$image = new MWWPF_Field_Image();

		$this->fields = array(
			$file->get_shortcode_name()  => $file,
			$image->get_shortcode_name() => $image,
		);

		// The base class registers each field on priority 10, the same as MW WP Form's own
		// fields, so the winner would be undefined. Override on a later priority instead.
		add_filter( 'mwform_form_fields', array( $this, 'override_form_fields' ), 100 );

		foreach ( array( 'MWWPF_Rule_Noempty', 'MWWPF_Rule_Required', 'MWWPF_Rule_Filetype', 'MWWPF_Rule_Filesize', 'MWWPF_Rule_Maxfilesize', 'MWWPF_Rule_Minfilesize' ) as $class ) {
			$rule                             = new $class();
			$this->rules[ $rule->get_name() ] = $rule;
		}
		add_filter( 'mwform_validation_rules', array( $this, 'override_validation_rules' ), 100 );

		MWWPF_Ajax::register();

		// Fires both when a form is rendered and when it is posted.
		add_action( 'mwform_start_main_process', array( 'MWWPF_Mail', 'register' ) );
		add_action( 'mwform_start_main_process', array( 'MWWPF_Archive', 'register' ) );

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
	 * Replaces the file-related validation rules with proxies that also handle this plugin's
	 * fields. The original rule objects are passed to the proxies; they are missing in 5.2+.
	 *
	 * @param array $rules Rule objects keyed by rule name.
	 * @return array
	 */
	public function override_validation_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}
		foreach ( $this->rules as $name => $proxy ) {
			if ( ! isset( $rules[ $name ] ) || $rules[ $name ] !== $proxy ) {
				$proxy->set_original( isset( $rules[ $name ] ) ? $rules[ $name ] : null );
			}
			$rules[ $name ] = $proxy;
		}
		return $rules;
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

		wp_enqueue_script(
			'mwwpf',
			MWWPF_PLUGIN_URL . 'assets/js/mwwpf.js',
			array(),
			MWWPF_VERSION,
			true
		);

		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Messages shared with MW WP Form use its text domain.
		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( MWWPF_Ajax::NONCE_ACTION ),
			'i18n'    => array(
				'invalidType' => __( 'This file is invalid.', 'mw-wp-form' ),
				'tooBig'      => __( 'This file size is too big.', 'mw-wp-form' ),
				'failed'      => __( 'Failed to upload the file.', 'mw-wp-form' ),
			),
		);
		// phpcs:enable

		wp_add_inline_script( 'mwwpf', 'window.mwwpfConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
