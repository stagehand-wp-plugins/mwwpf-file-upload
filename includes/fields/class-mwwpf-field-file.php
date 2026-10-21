<?php
/**
 * Replacement for [mwform_file].
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * File field.
 *
 * The file input has no name attribute, so MW WP Form never receives the file in $_FILES.
 * The file is uploaded asynchronously and only the original name and a token are posted.
 */
class MWWPF_Field_File extends MW_WP_Form_Abstract_Form_Field {

	/**
	 * Tag generator group.
	 *
	 * @var string
	 */
	public $type = 'input';

	/**
	 * Name of the hidden field array that carries the tokens.
	 */
	const TOKEN_FIELD = 'mwwpf_token';

	/**
	 * Field type used in data attributes and metadata.
	 *
	 * @return string
	 */
	protected function field_type() {
		return 'file';
	}

	/**
	 * Shortcode name and display name.
	 *
	 * @return array
	 */
	protected function set_names() {
		return array(
			'shortcode_name' => 'mwform_file',
			// Same label as MW WP Form, translated with its text domain.
			'display_name'   => __( 'File', 'mw-wp-form' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		);
	}

	/**
	 * Default attributes. Same as MW WP Form, plus accept.
	 *
	 * @return array
	 */
	protected function set_defaults() {
		return array(
			'name'       => '',
			'id'         => null,
			'class'      => null,
			'show_error' => 'true',
			'accept'     => null,
		);
	}

	/**
	 * Input page.
	 *
	 * @return string
	 */
	protected function input_page() {
		$name = $this->atts['name'];
		if ( ! MWWPF_Form_Scanner::is_valid_name( $name ) ) {
			return '';
		}

		MWWPF_Plugin::instance()->request_assets();

		$form_id  = MWWPF_Form_Scanner::form_id_from_key( $this->form_key );
		$limits   = MWWPF_Storage::get_limits( $form_id, $name );
		$uploaded = $this->get_uploaded( $form_id, $name );

		$input_atts = array(
			'type'  => 'file',
			'id'    => $this->atts['id'],
			'class' => trim( 'mwwpf__input ' . (string) $this->atts['class'] ),
		);
		if ( ! empty( $this->atts['accept'] ) ) {
			$input_atts['accept'] = $this->atts['accept'];
		}

		$html = sprintf(
			'<span class="%1$s" data-mwwpf-form="%2$d" data-mwwpf-field="%3$s" data-mwwpf-type="%4$s" data-mwwpf-max="%5$d" data-mwwpf-ext="%6$s">',
			esc_attr( 'mwwpf mwwpf--' . $this->field_type() . ( $uploaded ? ' is-uploaded' : '' ) ),
			$form_id,
			esc_attr( $name ),
			esc_attr( $this->field_type() ),
			$limits['max_bytes'],
			esc_attr( implode( ',', $limits['exts'] ) )
		);

		$html .= '<input' . $this->build_atts( $input_atts ) . ' />';
		$html .= $this->render_status( $form_id, $name, $uploaded );
		$html .= $this->render_hidden( $name, $uploaded );
		$html .= sprintf(
			'<noscript><span class="mwwpf__noscript">%s</span></noscript>',
			esc_html__( 'Please enable JavaScript to upload files.', 'mwwpf-file-upload' )
		);
		$html .= '</span>';

		if ( 'false' !== $this->atts['show_error'] ) {
			$html .= $this->get_error( $name );
		}

		return $html;
	}

	/**
	 * Confirm page.
	 *
	 * @return string
	 */
	protected function confirm_page() {
		$name = $this->atts['name'];
		if ( ! MWWPF_Form_Scanner::is_valid_name( $name ) ) {
			return '';
		}

		$form_id  = MWWPF_Form_Scanner::form_id_from_key( $this->form_key );
		$uploaded = $this->get_uploaded( $form_id, $name );
		if ( ! $uploaded ) {
			return '';
		}

		$html  = sprintf( '<span class="%s">', esc_attr( 'mwwpf-confirm mwwpf-confirm--' . $this->field_type() ) );
		$html .= $this->render_preview( $form_id, $name, $uploaded );
		$html .= sprintf( '<span class="mwwpf-confirm__name">%s</span>', esc_html( $uploaded['name'] ) );
		$html .= '</span>';
		$html .= $this->render_hidden( $name, $uploaded );

		return $html;
	}

	/**
	 * Tag generator dialog. Same fields as MW WP Form, plus accept.
	 *
	 * @param array $options Options.
	 */
	public function mwform_tag_generator_dialog( array $options = array() ) {
		// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- Labels shared with MW WP Form use its text domain.
		?>
		<p>
			<strong>name<span class="mwf_require">*</span></strong>
			<?php $name = $this->get_value_for_generator( 'name', $options ); ?>
			<input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" />
		</p>
		<p>
			<strong>id</strong>
			<?php $id = $this->get_value_for_generator( 'id', $options ); ?>
			<input type="text" name="id" value="<?php echo esc_attr( $id ); ?>" />
		</p>
		<p>
			<strong>class</strong>
			<?php $class = $this->get_value_for_generator( 'class', $options ); ?>
			<input type="text" name="class" value="<?php echo esc_attr( $class ); ?>" />
		</p>
		<p>
			<strong>accept</strong>
			<?php $accept = $this->get_value_for_generator( 'accept', $options ); ?>
			<input type="text" name="accept" value="<?php echo esc_attr( $accept ); ?>" />
			<span class="mwf_note"><?php esc_html_e( 'Example: .pdf,image/*', 'mwwpf-file-upload' ); ?></span>
		</p>
		<p>
			<strong><?php esc_html_e( 'Display error', 'mw-wp-form' ); ?></strong>
			<?php $show_error = $this->get_value_for_generator( 'show_error', $options ); ?>
			<label><input type="checkbox" name="show_error" value="false" <?php checked( 'false', $show_error ); ?> /> <?php esc_html_e( 'Don\'t display error.', 'mw-wp-form' ); ?></label>
		</p>
		<?php
		// phpcs:enable
	}

	/**
	 * Returns the token posted for a field, or an empty string.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	protected function get_posted_token( $name ) {
		$tokens = $this->Data->get_post_value_by_key( self::TOKEN_FIELD ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MW WP Form property.
		if ( is_array( $tokens ) && isset( $tokens[ $name ] ) && is_string( $tokens[ $name ] ) ) {
			return $tokens[ $name ];
		}
		return '';
	}

	/**
	 * Returns the metadata of the file uploaded for this field, or null.
	 *
	 * The posted token is the source of truth: the posted file name is only for display
	 * and may be tampered with, so the name always comes from the token's metadata.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $name    Field name.
	 * @return array|null
	 */
	protected function get_uploaded( $form_id, $name ) {
		$token = $this->get_posted_token( $name );
		if ( '' === $token ) {
			return null;
		}
		return MWWPF_Storage::resolve( $token, $form_id, $name );
	}

	/**
	 * Renders the status area (file name, size, remove button, progress, error).
	 *
	 * @param int        $form_id  Form ID.
	 * @param string     $name     Field name.
	 * @param array|null $uploaded Uploaded file metadata.
	 * @return string
	 */
	protected function render_status( $form_id, $name, $uploaded ) {
		$html  = '<span class="mwwpf__status" aria-live="polite">';
		$html .= '<span class="mwwpf__file"' . ( $uploaded ? '' : ' hidden' ) . '>';
		$html .= $uploaded ? $this->render_preview( $form_id, $name, $uploaded ) : '';
		$html .= sprintf( '<span class="mwwpf__name">%s</span>', $uploaded ? esc_html( $uploaded['name'] ) : '' );
		$html .= sprintf( '<span class="mwwpf__size">%s</span>', $uploaded ? esc_html( size_format( $uploaded['size'] ) ) : '' );
		$html .= sprintf(
			'<button type="button" class="mwwpf__remove">%s</button>',
			esc_html__( 'Remove', 'mwwpf-file-upload' )
		);
		$html .= '</span>';
		$html .= '<progress class="mwwpf__progress" max="100" value="0" hidden></progress>';
		$html .= '<span class="mwwpf__error" role="alert"></span>';
		$html .= '</span>';
		return $html;
	}

	/**
	 * Renders a preview of the uploaded file. Only image fields show one.
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $name     Field name.
	 * @param array  $uploaded Uploaded file metadata.
	 * @return string
	 */
	protected function render_preview( $form_id, $name, $uploaded ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$url = MWWPF_Storage::preview_url( $uploaded );
		if ( 'image' !== $this->field_type() || '' === $url ) {
			return '';
		}
		return sprintf( '<img class="mwwpf__thumb" src="%s" alt="" />', esc_url( $url ) );
	}

	/**
	 * Renders the two hidden fields: the original file name and the token.
	 *
	 * @param string     $name     Field name.
	 * @param array|null $uploaded Uploaded file metadata.
	 * @return string
	 */
	protected function render_hidden( $name, $uploaded ) {
		return sprintf(
			'<input type="hidden" class="mwwpf__value" name="%1$s" value="%2$s" /><input type="hidden" class="mwwpf__token" name="%3$s" value="%4$s" />',
			esc_attr( $name ),
			esc_attr( $uploaded ? $uploaded['name'] : '' ),
			esc_attr( self::TOKEN_FIELD . '[' . $name . ']' ),
			esc_attr( $uploaded ? $uploaded['token'] : '' )
		);
	}

	/**
	 * Builds an attribute string, skipping empty values.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	protected function build_atts( array $atts ) {
		$html = '';
		foreach ( $atts as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$html .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		return $html;
	}
}
