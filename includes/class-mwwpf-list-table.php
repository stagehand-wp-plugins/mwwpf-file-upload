<?php
/**
 * List table of archived files.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table.
 */
class MWWPF_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mwwpf-file',
				'plural'   => 'mwwpf-files',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'preview'  => __( 'Preview', 'mwwpf-file-upload' ),
			'name'     => __( 'File name', 'mwwpf-file-upload' ),
			'form'     => __( 'Form', 'mwwpf-file-upload' ),
			'field'    => __( 'Field', 'mwwpf-file-upload' ),
			'size'     => __( 'Size', 'mwwpf-file-upload' ),
			'mime'     => __( 'Type', 'mwwpf-file-upload' ),
			'archived' => __( 'Received', 'mwwpf-file-upload' ),
			'expires'  => __( 'Deleted on', 'mwwpf-file-upload' ),
			'inquiry'  => __( 'Inquiry data', 'mwwpf-file-upload' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'mwwpf-file-upload' ) );
	}

	/**
	 * Loads the items.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( MWWPF_Admin_Page::PER_PAGE_OPTION, 20 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		$result = MWWPF_Archive::query(
			array(
				'form_id'  => $form_id,
				'paged'    => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Message when there are no files.
	 */
	public function no_items() {
		esc_html_e( 'No stored files.', 'mwwpf-file-upload' );
	}

	/**
	 * Returns the item key used in checkboxes and delete buttons.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	private function key( array $item ) {
		return (int) $item['form_id'] . ':' . $item['token'];
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="mwwpf-item-%1$s">%2$s</label><input type="checkbox" id="mwwpf-item-%1$s" name="items[]" value="%3$s" />',
			esc_attr( $item['token'] ),
			/* translators: %s: file name. */
			esc_html( sprintf( __( 'Select %s', 'mwwpf-file-upload' ), $item['name'] ) ),
			esc_attr( $this->key( $item ) )
		);
	}

	/**
	 * Preview column (images only).
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_preview( $item ) {
		if ( ! in_array( $item['mime'], MWWPF_Storage::PREVIEW_MIMES, true ) ) {
			return '';
		}
		$url = MWWPF_Admin_Page::thumb_url( $item );
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" alt="" loading="lazy" /></a>',
			esc_url( $url )
		);
	}

	/**
	 * File name column with row actions.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_name( $item ) {
		$actions = array(
			'download' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( MWWPF_Admin_Page::download_url( $item ) ),
				esc_html__( 'Download', 'mwwpf-file-upload' )
			),
			'delete'   => sprintf(
				'<button type="submit" class="button-link mwwpf-delete" name="mwwpf_delete_one" value="%1$s" onclick="return window.confirm(\'%2$s\');">%3$s</button>',
				esc_attr( $this->key( $item ) ),
				esc_js(
					/* translators: %s: file name. */
					sprintf( __( 'Delete "%s"? This cannot be undone.', 'mwwpf-file-upload' ), $item['name'] )
				),
				esc_html__( 'Delete', 'mwwpf-file-upload' )
			),
		);

		return sprintf(
			'<strong><a href="%1$s" class="mwwpf-download">%2$s</a></strong>%3$s',
			esc_url( MWWPF_Admin_Page::download_url( $item ) ),
			esc_html( $item['name'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Form column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_form( $item ) {
		$link = get_edit_post_link( (int) $item['form_id'] );
		$name = self::form_title( (int) $item['form_id'] );
		return $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $name ) ) : esc_html( $name );
	}

	/**
	 * Returns a form's title for display.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	public static function form_title( $form_id ) {
		$post = get_post( $form_id );
		if ( ! $post || MWWPF_Form_Scanner::FORM_POST_TYPE !== $post->post_type ) {
			/* translators: %d: form ID. */
			return sprintf( __( 'Deleted form #%d', 'mwwpf-file-upload' ), $form_id );
		}
		$title = get_the_title( $post );
		/* translators: %d: form ID. */
		return '' !== $title ? $title : sprintf( __( 'Form #%d', 'mwwpf-file-upload' ), $form_id );
	}

	/**
	 * Field column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_field( $item ) {
		return esc_html( $item['field'] );
	}

	/**
	 * Size column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_size( $item ) {
		return esc_html( (string) size_format( (int) $item['size'] ) );
	}

	/**
	 * Type column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_mime( $item ) {
		return esc_html( (string) $item['mime'] );
	}

	/**
	 * Received column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_archived( $item ) {
		return esc_html( self::format_time( (int) $item['archived'] ) );
	}

	/**
	 * Deletion date column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_expires( $item ) {
		$expires = MWWPF_Archive::expires_at( $item );
		return null === $expires ? esc_html__( 'Not deleted automatically', 'mwwpf-file-upload' ) : esc_html( self::format_time( $expires ) );
	}

	/**
	 * Inquiry data column.
	 *
	 * @param array $item Metadata.
	 * @return string
	 */
	protected function column_inquiry( $item ) {
		$id = isset( $item['saved_mail_id'] ) ? (int) $item['saved_mail_id'] : 0;
		if ( $id <= 0 || ! get_post( $id ) ) {
			return '&mdash;';
		}
		$link = get_edit_post_link( $id );
		return $link ? sprintf( '<a href="%s" class="mwwpf-inquiry">%s</a>', esc_url( $link ), esc_html__( 'View', 'mwwpf-file-upload' ) ) : '&mdash;';
	}

	/**
	 * Formats a timestamp in the site's time zone.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
