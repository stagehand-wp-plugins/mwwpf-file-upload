<?php
/**
 * Base class of the proxy validation rules.
 *
 * @package MWWPF_File_Upload
 */

defined( 'ABSPATH' ) || exit;

/**
 * Proxy rule.
 *
 * Replaces an MW WP Form rule of the same name. For this plugin's file fields it validates
 * the uploaded file from the token metadata; for other fields it delegates to the original
 * rule. The admin settings UI is always the original rule's (or a compatible copy when the
 * original no longer exists, as in MW WP Form 5.2).
 */
abstract class MWWPF_Rule_Proxy extends MW_WP_Form_Abstract_Validation_Rule {

	/**
	 * The MW WP Form rule this proxy replaces, if any.
	 *
	 * @var MW_WP_Form_Abstract_Validation_Rule|null
	 */
	protected $original = null;

	/**
	 * Constructor.
	 *
	 * The parent constructor is intentionally not called: it would register this object on
	 * mwform_validation_rules priority 10, where the winner against the original is undefined.
	 * The plugin registers the proxies on a later priority instead.
	 */
	public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found

	/**
	 * Sets the rule this proxy replaces.
	 *
	 * @param MW_WP_Form_Abstract_Validation_Rule|null $original Original rule.
	 */
	public function set_original( $original ) {
		$this->original = ( $original instanceof MW_WP_Form_Abstract_Validation_Rule && ! $original instanceof self ) ? $original : null;
	}

	/**
	 * Returns the original rule, if any.
	 *
	 * @return MW_WP_Form_Abstract_Validation_Rule|null
	 */
	public function get_original() {
		return $this->original;
	}

	/**
	 * Validates a field.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Rule options.
	 * @return string|null Error message, or null when valid.
	 */
	public function rule( $name, array $options = array() ) {
		$form_id = MWWPF_Form_Scanner::form_id_from_key( $this->get_form_key() );
		if ( $form_id && MWWPF_Form_Scanner::get_field( $form_id, $name ) ) {
			return $this->check( $this->get_uploaded( $form_id, $name ), $this->get_token( $name ), $options );
		}

		if ( $this->original ) {
			if ( ! $this->original->is_set_Data() && $this->is_set_Data() ) {
				$this->original->set_Data( $this->Data ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MW WP Form property.
			}
			return $this->original->rule( $name, $options );
		}

		return null;
	}

	/**
	 * Validates this plugin's file field.
	 *
	 * @param array|null $meta    Metadata of the uploaded file, or null when there is no valid upload.
	 * @param string     $token   Posted token (may be empty or invalid).
	 * @param array      $options Rule options.
	 * @return string|null Error message, or null when valid.
	 */
	abstract protected function check( $meta, $token, array $options );

	/**
	 * Settings UI in the form editor. Always the original rule's, so that it matches MW WP Form.
	 *
	 * @param string $key   Validation row key.
	 * @param array  $value Saved values of the row.
	 */
	public function admin( $key, $value ) {
		if ( $this->original ) {
			$this->original->admin( $key, $value );
			return;
		}
		$this->admin_fallback( $key, is_array( $value ) ? $value : array() );
	}

	/**
	 * Settings UI compatible with MW WP Form 5.1.8, used when the original rule does not exist.
	 *
	 * @param string $key   Validation row key.
	 * @param array  $value Saved values of the row.
	 */
	abstract protected function admin_fallback( $key, array $value );

	/**
	 * Returns the error message: the "message" option, or the default.
	 *
	 * @param array  $options Rule options.
	 * @param string $fallback Default message.
	 * @return string
	 */
	protected function message( array $options, $fallback ) {
		return ( isset( $options['message'] ) && '' !== $options['message'] ) ? (string) $options['message'] : $fallback;
	}

	/**
	 * Returns the form key of the data being validated.
	 *
	 * @return string
	 */
	protected function get_form_key() {
		return $this->is_set_Data() ? (string) $this->Data->get_form_key() : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MW WP Form property.
	}

	/**
	 * Returns the posted token of a field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	protected function get_token( $name ) {
		if ( ! $this->is_set_Data() ) {
			return '';
		}
		$tokens = $this->Data->get_post_value_by_key( MWWPF_Field_File::TOKEN_FIELD ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MW WP Form property.
		return ( is_array( $tokens ) && isset( $tokens[ $name ] ) && is_string( $tokens[ $name ] ) ) ? $tokens[ $name ] : '';
	}

	/**
	 * Returns the metadata of the uploaded file of a field, or null.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $name    Field name.
	 * @return array|null
	 */
	protected function get_uploaded( $form_id, $name ) {
		$token = $this->get_token( $name );
		return '' === $token ? null : MWWPF_Storage::resolve( $token, $form_id, $name );
	}

	/**
	 * Prints the name attribute of a settings input, the same as MW WP Form.
	 *
	 * @param string $key Validation row key.
	 * @param string $sub Sub key (types, bytes, width, height) or empty.
	 * @return string
	 */
	protected function field_name( $key, $sub = '' ) {
		return 'mw-wp-form[validation][' . $key . '][' . $this->get_name() . ']' . ( '' !== $sub ? '[' . $sub . ']' : '' );
	}

	/**
	 * Returns a saved option of this rule in the row.
	 *
	 * @param array  $value Saved values of the row.
	 * @param string $sub   Sub key.
	 * @return string
	 */
	protected function saved( array $value, $sub ) {
		$rule = isset( $value[ $this->get_name() ] ) ? $value[ $this->get_name() ] : null;
		return ( is_array( $rule ) && isset( $rule[ $sub ] ) ) ? (string) $rule[ $sub ] : '';
	}
}
