import { test, expect } from '@playwright/test';
import { formId } from './utils/forms';

test.describe( 'field rendering (Phase 1)', () => {
	test( '[mwform_file] and [mwform_image] are rendered by this plugin', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = page.locator( '.mw_wp_form form' );

		const attach = form.locator( '.mwwpf[data-mwwpf-field="attach"]' );
		await expect( attach ).toHaveAttribute( 'data-mwwpf-type', 'file' );
		await expect( attach ).toHaveAttribute( 'data-mwwpf-form', String( formId( 'mwwpf-test-main' ) ) );
		await expect( attach ).toHaveAttribute( 'data-mwwpf-ext', 'pdf' );
		await expect( attach ).toHaveAttribute( 'data-mwwpf-max', '2097152' );

		const photo = form.locator( '.mwwpf[data-mwwpf-field="photo"]' );
		await expect( photo ).toHaveAttribute( 'data-mwwpf-type', 'image' );
		await expect( photo ).toHaveAttribute( 'data-mwwpf-ext', 'jpg,png' );
		await expect( photo.locator( 'input[type="file"]' ) ).toHaveAttribute( 'accept', /image\/png/ );

		// The file inputs must not have a name, so that MW WP Form never receives $_FILES.
		await expect( form.locator( 'input[type="file"]' ) ).toHaveCount( 2 );
		await expect( form.locator( 'input[type="file"][name]' ) ).toHaveCount( 0 );

		// Hidden fields carry the original name and the token.
		await expect( form.locator( 'input[type="hidden"][name="attach"]' ) ).toHaveCount( 1 );
		await expect( form.locator( 'input[type="hidden"][name="mwwpf_token[attach]"]' ) ).toHaveCount( 1 );
		await expect( form.locator( 'input[type="hidden"][name="mwwpf_token[photo]"]' ) ).toHaveCount( 1 );

		// MW WP Form's own file field markup is gone.
		await expect( form.locator( '.mwform-file-delete' ) ).toHaveCount( 0 );
	} );

	test( 'the stylesheet is loaded only on pages with a file field', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		await expect( page.locator( 'link#mwwpf-css' ) ).toHaveCount( 1 );

		await page.goto( '/' );
		await expect( page.locator( 'link#mwwpf-css' ) ).toHaveCount( 0 );
	} );

	test( 'tag generator dialog comes from this plugin', async ( { page } ) => {
		await page.goto( `/wp-admin/post.php?post=${ formId( 'mwwpf-test-main' ) }&action=edit` );

		const fileDialog = page.locator( '#dialog-mwform_file' );
		await expect( fileDialog ).toHaveCount( 1 );
		await expect( fileDialog.locator( 'input[name="accept"]' ) ).toHaveCount( 1 );
		await expect( page.locator( '#dialog-mwform_image input[name="accept"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'option[value="mwform_file"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'option[value="mwform_image"]' ) ).toHaveCount( 1 );
	} );
} );
