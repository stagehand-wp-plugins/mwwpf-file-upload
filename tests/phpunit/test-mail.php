<?php
/**
 * Tests for mail tags and attachments (§6.8).
 *
 * @package MWWPF_File_Upload
 */

/**
 * Mail tests.
 */
class Test_Mail extends WP_UnitTestCase {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	private $form_id;

	/**
	 * Form key.
	 *
	 * @var string
	 */
	private $form_key;

	/**
	 * Sets up a form with two file fields and a visitor.
	 */
	public function set_up() {
		parent::set_up();
		MWWPF_Form_Scanner::flush();
		MWWPF_Mail::flush();
		$this->form_id                    = self::factory()->post->create(
			array(
				'post_type'    => 'mw-wp-form',
				'post_content' => '[mwform_text name="your-name"] [mwform_file name="a"] [mwform_file name="b"]',
			)
		);
		$this->form_key                   = 'mw-wp-form-' . $this->form_id;
		$_COOKIE[ MWWPF_Storage::COOKIE ] = str_repeat( 'e', 32 );
		MWWPF_Storage::reset_sid();
	}

	/**
	 * Cleans up.
	 */
	public function tear_down() {
		MWWPF_Storage::safe_rmdir( MWWPF_Storage::root_dir() . '/tmp' );
		MWWPF_Test_Files::cleanup();
		unset( $_COOKIE[ MWWPF_Storage::COOKIE ] );
		MWWPF_Storage::reset_sid();
		MWWPF_Mail::flush();
		parent::tear_down();
	}

	/**
	 * Stores a file for a field.
	 *
	 * @param string $field Field.
	 * @param string $name  Original name.
	 * @return string Token.
	 */
	private function upload( $field, $name ) {
		$path = MWWPF_Test_Files::write( 'x.pdf', MWWPF_Test_Files::pdf() );
		$meta = MWWPF_Storage::store(
			$path,
			array(
				'form_id' => $this->form_id,
				'field'   => $field,
				'type'    => 'file',
				'name'    => $name,
				'ext'     => 'pdf',
				'mime'    => 'application/pdf',
				'size'    => filesize( $path ),
			),
			false
		);
		return $meta['token'];
	}

	/**
	 * Posts tokens as MW WP Form would.
	 *
	 * @param array $tokens Field => token.
	 */
	private function post( array $tokens ) {
		MW_WP_Form_Data::connect(
			$this->form_key,
			array(
				'your-name'   => 'taro',
				'mwwpf_token' => $tokens,
			)
		);
		MWWPF_Mail::flush();
	}

	/**
	 * Test: mail tags.
	 */
	public function test_mail_tag() {
		$this->post( array( 'a' => $this->upload( 'a', '見積.pdf' ) ) );

		$this->assertSame( '見積.pdf', MWWPF_Mail::mail_tag( $this->form_key, 'tampered.php', 'a' ) );
		$this->assertSame( '', MWWPF_Mail::mail_tag( $this->form_key, 'tampered.php', 'b' ) );
		$this->assertSame( 'taro', MWWPF_Mail::mail_tag( $this->form_key, 'taro', 'your-name' ) );
	}

	/**
	 * Test: attachments use the original names, with a suffix for duplicates.
	 */
	public function test_attach_with_original_and_unique_names() {
		$this->post(
			array(
				'a' => $this->upload( 'a', 'same.pdf' ),
				'b' => $this->upload( 'b', 'same.pdf' ),
			)
		);
		$mail              = new stdClass();
		$mail->attachments = array( 'other' => '/tmp/other.pdf' );

		$mail = MWWPF_Mail::attach( $this->form_key, $mail );

		$this->assertSame( '/tmp/other.pdf', $mail->attachments['other'] );
		$this->assertSame( 'same.pdf', basename( $mail->attachments['a'] ) );
		$this->assertSame( 'same (2).pdf', basename( $mail->attachments['b'] ) );
		$this->assertFileExists( $mail->attachments['a'] );
		$this->assertTrue( MWWPF_Storage::is_inside_root( $mail->attachments['a'] ) );
	}

	/**
	 * Test: a token of another visitor is not attached.
	 */
	public function test_foreign_token_is_not_attached() {
		$token                            = $this->upload( 'a', 'secret.pdf' );
		$_COOKIE[ MWWPF_Storage::COOKIE ] = str_repeat( 'f', 32 );
		MWWPF_Storage::reset_sid();
		$this->post( array( 'a' => $token ) );
		$mail              = new stdClass();
		$mail->attachments = array();

		$mail = MWWPF_Mail::attach( $this->form_key, $mail );

		$this->assertSame( array(), $mail->attachments );
		$this->assertSame( '', MWWPF_Mail::mail_tag( $this->form_key, 'secret.pdf', 'a' ) );
	}

	/**
	 * Test: PHPMailer keeps a Japanese attachment name intact.
	 */
	public function test_phpmailer_japanese_attachment_name() {
		$this->post( array( 'a' => $this->upload( 'a', '見積依頼書（最新版）.pdf' ) ) );
		$mail              = new stdClass();
		$mail->attachments = array();
		$mail              = MWWPF_Mail::attach( $this->form_key, $mail );

		// Bypass the test mail capture so that PHPMailer builds the message.
		$filters = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
		remove_all_filters( 'pre_wp_mail' );
		reset_phpmailer_instance();

		$error = null;
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail( 'admin@example.com', 'test', 'body', array( 'From: wordpress@example.com' ), array_values( $mail->attachments ) );
		remove_action( 'wp_mail_failed', $catch );

		if ( $filters ) {
			$GLOBALS['wp_filter']['pre_wp_mail'] = $filters;
		}
		$this->assertTrue( $sent, $error ? $error->get_error_message() : '' );

		$message = tests_retrieve_phpmailer_instance()->get_sent()->body;
		$this->assertMatchesRegularExpression( '/name="?([^";\r\n]+)"?/', $message );
		preg_match_all( '/(?:file)?name="([^"]+)"/', $message, $matches );
		$names = array_map(
			static function ( $encoded ) {
				return iconv_mime_decode( $encoded, 0, 'UTF-8' );
			},
			$matches[1]
		);
		$this->assertContains( '見積依頼書（最新版）.pdf', $names );
	}
}
