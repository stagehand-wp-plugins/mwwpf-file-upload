<?php
/**
 * Creates the test forms and pages used by the E2E tests.
 *
 * Run with WP-CLI inside wp-env:
 *   wp eval-file wp-content/plugins/mwwpf-file-upload/tests/setup/create-forms.php
 *
 * Idempotent: forms and pages are looked up by slug and updated in place.
 * Prints a JSON map of form slugs to form IDs and page paths.
 *
 * @package MWWPF_File_Upload
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Runs only inside WP-CLI eval-file scope.

// Form body from the implementation guide §9.1. It must not be changed for this plugin.
$body = <<<'EOT'
<p>お名前 [mwform_text name="your-name"]</p>
<p>資料 [mwform_file name="attach"]</p>
<p>写真 [mwform_image name="photo"]</p>
<p>[mwform_bback]戻る[/mwform_bback] [mwform_bconfirm]確認[/mwform_bconfirm] [mwform_bsubmit]送信[/mwform_bsubmit]</p>
EOT;

// Without a confirmation screen, with an email field for the auto reply.
$body_direct = <<<'EOT'
<p>お名前 [mwform_text name="your-name"]</p>
<p>メール [mwform_email name="your-email"]</p>
<p>資料 [mwform_file name="attach"]</p>
<p>写真 [mwform_image name="photo"]</p>
<p>[mwform_bsubmit]送信[/mwform_bsubmit]</p>
EOT;

$validation = array(
	array(
		'target'   => 'attach',
		'noempty'  => 1,
		'filetype' => array( 'types' => 'pdf' ),
		'filesize' => array( 'bytes' => '2097152' ),
	),
	array(
		'target'      => 'photo',
		'filetype'    => array( 'types' => 'jpg,png' ),
		'maxfilesize' => array(
			'width'  => '2000',
			'height' => '2000',
		),
	),
);

$admin_mail_content = "お名前: {your-name}\n資料: {attach}\n写真: {photo}\n";

$base_setting = array(
	'querystring'          => false,
	'usedb'                => false,
	'mail_to'              => 'admin@example.com',
	'admin_mail_subject'   => '[admin] {your-name}',
	'admin_mail_content'   => $admin_mail_content,
	'admin_mail_from'      => 'wordpress@example.com',
	'admin_mail_sender'    => 'MWWPF Test',
	'mail_subject'         => '[reply] {your-name}',
	'mail_content'         => "自動返信\n資料: {attach}\n写真: {photo}\n",
	'mail_from'            => 'wordpress@example.com',
	'mail_sender'          => 'MWWPF Test',
	'complete_message'     => '<p class="mwwpf-test-complete">送信しました。</p>',
	'validation'           => $validation,
	'scroll'               => false,
	'akismet_author'       => '',
	'akismet_author_url'   => '',
	'akismet_author_email' => '',
);

$forms = array(
	// T1-T4, T6-T9, T11, T15, T16.
	'mwwpf-test-main'   => array(
		'title'   => 'MWWPF Test Main',
		'content' => $body,
		'setting' => array(),
	),
	// T5, T13.
	'mwwpf-test-direct' => array(
		'title'   => 'MWWPF Test Direct',
		'content' => $body_direct,
		'setting' => array( 'automatic_reply_email' => 'your-email' ),
	),
	// T10, T12.
	'mwwpf-test-db'     => array(
		'title'   => 'MWWPF Test DB',
		'content' => $body,
		'setting' => array( 'usedb' => true ),
	),
	// T14 (second form on the same page as main).
	'mwwpf-test-second' => array(
		'title'   => 'MWWPF Test Second',
		'content' => $body,
		'setting' => array(),
	),
);

/**
 * Creates or updates a post by slug and returns its ID.
 *
 * @param string $post_type Post type.
 * @param string $slug      Slug.
 * @param array  $args      Post fields.
 * @return int
 */
$upsert = static function ( $post_type, $slug, array $args ) {
	$existing = get_posts(
		array(
			'post_type'      => $post_type,
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	$postarr = array_merge(
		array(
			'post_type'   => $post_type,
			'post_name'   => $slug,
			'post_status' => 'publish',
		),
		$args
	);

	if ( $existing ) {
		$postarr['ID'] = $existing[0];
		return (int) wp_update_post( wp_slash( $postarr ), true );
	}
	return (int) wp_insert_post( wp_slash( $postarr ), true );
};

$result = array();

foreach ( $forms as $slug => $form ) {
	$form_id = $upsert(
		'mw-wp-form',
		$slug,
		array(
			'post_title'   => $form['title'],
			'post_content' => $form['content'],
		)
	);

	update_post_meta( $form_id, 'mw-wp-form', array_merge( $base_setting, $form['setting'] ) );

	$result[ $slug ] = array( 'form_id' => $form_id );
}

$formkey = static function ( $slug ) use ( $result ) {
	return sprintf( '[mwform_formkey key="%d"]', $result[ $slug ]['form_id'] );
};

$test_pages = array(
	'mwwpf-test-main'   => $formkey( 'mwwpf-test-main' ),
	'mwwpf-test-direct' => $formkey( 'mwwpf-test-direct' ),
	'mwwpf-test-db'     => $formkey( 'mwwpf-test-db' ),
	'mwwpf-test-two'    => $formkey( 'mwwpf-test-main' ) . "\n\n" . $formkey( 'mwwpf-test-second' ),
);

foreach ( $test_pages as $slug => $content ) {
	$page_id = $upsert(
		'page',
		$slug,
		array(
			'post_title'   => $slug,
			'post_content' => $content,
		)
	);

	$result[ $slug ]['page']    = '/' . $slug . '/';
	$result[ $slug ]['page_id'] = $page_id;
}

// Pretty permalinks so that the page paths above work.
if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
	update_option( 'permalink_structure', '/%postname%/' );
	flush_rewrite_rules();
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
