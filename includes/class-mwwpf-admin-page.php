<?php
/**
 * Admin screen "Attachments": list, download, preview, delete, retention setting.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
class MWWPF_Admin_Page {

	/**
	 * Page slug.
	 */
	const SLUG = 'mwwpf-attachments';

	/**
	 * Screen option for items per page.
	 */
	const PER_PAGE_OPTION = 'mwwpf_attachments_per_page';

	/**
	 * Nonce action of the retention form. The list form uses WP_List_Table's bulk nonce.
	 */
	const NONCE_ACTION = 'mwwpf_attachments';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Parent menu slug.
	 *
	 * @var string
	 */
	private static $parent = '';

	/**
	 * Registers the hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_post_mwwpf_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_mwwpf_thumb', array( __CLASS__, 'handle_thumb' ) );
		add_filter( 'set_screen_option_' . self::PER_PAGE_OPTION, array( __CLASS__, 'save_screen_option' ), 10, 3 );
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Capability required for the page and all its actions.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to view and manage the stored files.
		 *
		 * @param string $capability Default manage_options.
		 */
		return (string) apply_filters( 'mwwpf_admin_capability', 'manage_options' );
	}

	/**
	 * Adds the submenu under MW WP Form (or under Tools when MW WP Form is inactive).
	 */
	public static function add_menu() {
		self::$parent = post_type_exists( MWWPF_Form_Scanner::FORM_POST_TYPE ) ? 'edit.php?post_type=' . MWWPF_Form_Scanner::FORM_POST_TYPE : 'tools.php';

		self::$hook = (string) add_submenu_page(
			self::$parent,
			__( 'Attachments', 'mwwpf-file-upload' ),
			__( 'Attachments', 'mwwpf-file-upload' ),
			self::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);

		if ( self::$hook ) {
			add_action( 'load-' . self::$hook, array( __CLASS__, 'load' ) );
		}
	}

	/**
	 * Returns the page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$parent = self::$parent ? self::$parent : 'edit.php?post_type=' . MWWPF_Form_Scanner::FORM_POST_TYPE;
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( $parent ) );
	}

	/**
	 * Runs before the page is rendered: handles POSTs and sets up screen options.
	 */
	public static function load() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'mwwpf-file-upload' ), 403 );
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			self::handle_post();
		}

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => self::PER_PAGE_OPTION,
			)
		);
	}

	/**
	 * Saves the items-per-page screen option.
	 *
	 * @param mixed  $status Default value.
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		return self::PER_PAGE_OPTION === $option ? max( 1, min( 500, (int) $value ) ) : $status;
	}

	/**
	 * Handles delete (single and bulk) and the retention setting, then redirects.
	 */
	private static function handle_post() {
		// The retention form has its own nonce; the list form uses the nonce WP_List_Table prints.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only selects which nonce to verify.
		$is_retention = isset( $_POST['mwwpf_save_retention'] );
		check_admin_referer( $is_retention ? self::NONCE_ACTION : 'bulk-mwwpf-files' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		if ( $is_retention ) {
			$days = isset( $_POST['mwwpf_retention_days'] ) ? sanitize_text_field( wp_unslash( $_POST['mwwpf_retention_days'] ) ) : '';
			if ( ! preg_match( '/^\d+$/', $days ) || (int) $days > 3650 ) {
				wp_safe_redirect( self::url( array( 'mwwpf_error' => 'retention' ) ) );
				exit;
			}
			update_option( MWWPF_Archive::RETENTION_OPTION, (int) $days, false );
			wp_safe_redirect( self::url( array( 'mwwpf_saved' => 1 ) ) );
			exit;
		}

		$items = array();
		if ( isset( $_POST['mwwpf_delete_one'] ) ) {
			$items = array( sanitize_text_field( wp_unslash( $_POST['mwwpf_delete_one'] ) ) );
		} elseif ( self::bulk_action() === 'delete' && isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
			$items = array_map( 'sanitize_text_field', wp_unslash( $_POST['items'] ) );
		}
		// phpcs:enable

		$deleted = 0;
		foreach ( $items as $item ) {
			$parts = explode( ':', (string) $item, 2 );
			if ( 2 === count( $parts ) && MWWPF_Archive::delete( $parts[0], $parts[1] ) ) {
				++$deleted;
			}
		}

		$args = array( 'mwwpf_deleted' => $deleted );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter kept on redirect.
		$form_id = isset( $_REQUEST['form_id'] ) ? absint( $_REQUEST['form_id'] ) : 0;
		if ( $form_id ) {
			$args['form_id'] = $form_id;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Returns the selected bulk action (top or bottom dropdown).
	 *
	 * @return string
	 */
	private static function bulk_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by the caller.
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '-1' !== $_POST[ $key ] ) {
				return sanitize_key( wp_unslash( $_POST[ $key ] ) );
			}
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Returns the download URL of an archived file.
	 *
	 * @param array $meta Metadata.
	 * @return string
	 */
	public static function download_url( array $meta ) {
		return self::file_url( 'mwwpf_download', $meta );
	}

	/**
	 * Returns the preview URL of an archived image.
	 *
	 * @param array $meta Metadata.
	 * @return string
	 */
	public static function thumb_url( array $meta ) {
		return self::file_url( 'mwwpf_thumb', $meta );
	}

	/**
	 * Builds a nonce URL for a file action.
	 *
	 * @param string $action admin-post action.
	 * @param array  $meta   Metadata.
	 * @return string
	 */
	private static function file_url( $action, array $meta ) {
		return add_query_arg(
			array(
				'action'   => $action,
				'form_id'  => (int) $meta['form_id'],
				'token'    => $meta['token'],
				'_wpnonce' => wp_create_nonce( self::file_nonce_action( $meta['form_id'], $meta['token'] ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Nonce action for one file.
	 *
	 * @param mixed $form_id Form ID.
	 * @param mixed $token   Token.
	 * @return string
	 */
	private static function file_nonce_action( $form_id, $token ) {
		return 'mwwpf_file_' . (int) $form_id . '_' . $token;
	}

	/**
	 * Validates a file request and returns the metadata, or stops with 403/404.
	 *
	 * @return array
	 */
	private static function file_request() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this file.', 'mwwpf-file-upload' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below.
		$form_id = isset( $_GET['form_id'] ) ? sanitize_text_field( wp_unslash( $_GET['form_id'] ) ) : '';
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable

		if ( ! MWWPF_Archive::path( $form_id, $token ) || ! wp_verify_nonce( $nonce, self::file_nonce_action( $form_id, $token ) ) ) {
			wp_die( esc_html__( 'The link has expired. Please reload the page and try again.', 'mwwpf-file-upload' ), 403 );
		}

		$meta = MWWPF_Archive::read( (int) $form_id, $token );
		if ( ! $meta ) {
			wp_die( esc_html__( 'The file was not found.', 'mwwpf-file-upload' ), 404 );
		}
		return $meta;
	}

	/**
	 * Streams an archived file as a download under its original name.
	 */
	public static function handle_download() {
		$meta = self::file_request();
		MWWPF_Ajax::stream( MWWPF_Archive::path( $meta['form_id'], $meta['token'] ), 'application/octet-stream', 'attachment', $meta['name'] );
	}

	/**
	 * Streams an archived image inline (for the list preview).
	 */
	public static function handle_thumb() {
		$meta = self::file_request();
		if ( ! in_array( $meta['mime'], MWWPF_Storage::PREVIEW_MIMES, true ) ) {
			wp_die( esc_html__( 'The file was not found.', 'mwwpf-file-upload' ), 404 );
		}
		MWWPF_Ajax::stream( MWWPF_Archive::path( $meta['form_id'], $meta['token'] ), $meta['mime'], 'inline', '' );
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		require_once MWWPF_PLUGIN_DIR . 'includes/class-mwwpf-list-table.php';

		$table = new MWWPF_List_Table();
		$table->prepare_items();
		$stats = MWWPF_Archive::stats();
		$days  = MWWPF_Archive::retention_days();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only flags after a redirect.
		$deleted = isset( $_GET['mwwpf_deleted'] ) ? absint( $_GET['mwwpf_deleted'] ) : null;
		$saved   = isset( $_GET['mwwpf_saved'] );
		$error   = isset( $_GET['mwwpf_error'] );
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		// phpcs:enable
		?>
		<div class="wrap mwwpf-attachments">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Attachments', 'mwwpf-file-upload' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( MWWPF_Admin_Notice::storage_may_be_public() ) : ?>
				<div class="notice notice-error inline mwwpf-storage-warning"><p><?php echo esc_html( MWWPF_Admin_Notice::message() ); ?> <a href="<?php echo esc_url( MWWPF_Admin_Notice::help_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'How to fix', 'mwwpf-file-upload' ); ?></a></p></div>
			<?php endif; ?>

			<?php if ( null !== $deleted ) : ?>
				<div class="notice notice-success is-dismissible mwwpf-notice-deleted"><p>
					<?php
					/* translators: %d: number of deleted files. */
					echo esc_html( sprintf( _n( '%d file deleted.', '%d files deleted.', $deleted, 'mwwpf-file-upload' ), $deleted ) );
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible mwwpf-notice-saved"><p><?php esc_html_e( 'Settings saved.', 'mwwpf-file-upload' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Enter a number of days between 0 and 3650.', 'mwwpf-file-upload' ); ?></p></div>
			<?php endif; ?>

			<p class="mwwpf-stats">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of files, 2: total size. */
						__( 'Stored files: %1$s (%2$s)', 'mwwpf-file-upload' ),
						number_format_i18n( $stats['count'] ),
						size_format( $stats['bytes'] ) ? size_format( $stats['bytes'] ) : '0 B'
					)
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="mwwpf-retention">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<label for="mwwpf-retention-days"><?php esc_html_e( 'Retention period', 'mwwpf-file-upload' ); ?></label>
				<input type="number" id="mwwpf-retention-days" name="mwwpf_retention_days" min="0" max="3650" step="1" class="small-text" value="<?php echo esc_attr( (string) $days ); ?>" />
				<?php esc_html_e( 'days (0: never delete automatically)', 'mwwpf-file-upload' ); ?>
				<?php submit_button( __( 'Save', 'mwwpf-file-upload' ), 'secondary', 'mwwpf_save_retention', false ); ?>
			</form>

			<form method="get" action="<?php echo esc_url( admin_url( self::$parent ? strtok( self::$parent, '?' ) : 'edit.php' ) ); ?>" class="mwwpf-filter">
				<?php if ( false !== strpos( self::$parent, 'post_type=' ) ) : ?>
					<input type="hidden" name="post_type" value="<?php echo esc_attr( MWWPF_Form_Scanner::FORM_POST_TYPE ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label for="mwwpf-filter-form" class="screen-reader-text"><?php esc_html_e( 'Filter by form', 'mwwpf-file-upload' ); ?></label>
				<select id="mwwpf-filter-form" name="form_id">
					<option value="0"><?php esc_html_e( 'All forms', 'mwwpf-file-upload' ); ?></option>
					<?php foreach ( MWWPF_Archive::form_ids() as $id ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $form_id, $id ); ?>><?php echo esc_html( MWWPF_List_Table::form_title( $id ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Filter', 'mwwpf-file-upload' ), 'secondary', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( self::url( $form_id ? array( 'form_id' => $form_id ) : array() ) ); ?>" class="mwwpf-list">
				<?php $table->display(); ?>
			</form>
		</div>
		<style>
			.mwwpf-attachments .mwwpf-retention,
			.mwwpf-attachments .mwwpf-filter { margin: 1em 0; }
			.mwwpf-attachments .column-preview { width: 72px; }
			.mwwpf-attachments .column-preview img { max-width: 60px; max-height: 60px; height: auto; display: block; }
			.mwwpf-attachments .column-size { width: 90px; }
			.mwwpf-attachments .row-actions .button-link { font-size: 13px; }
			.mwwpf-attachments .row-actions .delete .button-link { color: #b32d2e; }
		</style>
		<?php
	}
}
