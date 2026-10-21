import { test, expect } from '@playwright/test';
import { FormPage } from './utils/form-page';
import { pdf, png } from './utils/files';
import { formId } from './utils/forms';
import { createTestForms, wpCli } from './utils/wp-env';

const quotePdf = { name: 'quote.pdf', mimeType: 'application/pdf', buffer: pdf( 2000 ) };
const photoPng = { name: 'photo.png', mimeType: 'image/png', buffer: png( 100, 80 ) };

/**
 * Changes one rule option of the main form's validation settings (after a file was uploaded).
 */
function setRule( field: string, rule: string, options: Record< string, string > ) {
	const id = formId( 'mwwpf-test-main' );
	const php = `
		$s = get_post_meta( ${ id }, 'mw-wp-form', true );
		foreach ( $s['validation'] as $i => $v ) {
			if ( $v['target'] === ${ JSON.stringify( field ) } ) {
				$s['validation'][ $i ][ ${ JSON.stringify( rule ) } ] = json_decode( ${ JSON.stringify( JSON.stringify( options ) ) }, true );
			}
		}
		update_post_meta( ${ id }, 'mw-wp-form', $s );
	`;
	wpCli( [ 'eval', php ] );
}

/**
 * §9.1 validation tests (Phase 4). Run as an anonymous visitor.
 */
test.describe( 'validation (§9.1)', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.afterEach( () => {
		// Restore the validation settings changed by a test.
		createTestForms();
	} );

	test( 'an image passes the image rules and is previewed on the confirm screen (F9)', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		await form.upload( 'photo', photoPng );

		await form.confirm();

		await expect( form.form.locator( '.error' ) ).toHaveCount( 0 );
		const photo = form.form.locator( '.mwwpf-confirm--image' );
		await expect( photo ).toContainText( 'photo.png' );
		const thumb = photo.locator( 'img.mwwpf__thumb' );
		await expect( thumb ).toBeVisible();
		await expect.poll( () => thumb.evaluate( ( img: HTMLImageElement ) => img.naturalWidth ) ).toBe( 100 );
	} );

	test( 'T6: removing the file and sending shows the noempty error', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		await form.field( 'attach' ).locator( '.mwwpf__remove' ).click();

		await form.confirm();

		await expect( form.field( 'attach' ) ).toBeVisible();
		await expect( form.form.locator( '.mwwpf[data-mwwpf-field="attach"] + .error, .mwwpf[data-mwwpf-field="attach"] ~ .error' ) ).toHaveCount( 1 );
	} );

	test( 'T7 (server side): filetype is checked again on submit', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		setRule( 'attach', 'filetype', { types: 'docx' } );

		await form.confirm();

		await expect( form.field( 'attach' ).locator( '~ .error' ) ).toHaveCount( 1 );
	} );

	test( 'T8 (server side): filesize is checked again on submit', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		setRule( 'attach', 'filesize', { bytes: '1000' } );

		await form.confirm();

		await expect( form.field( 'attach' ).locator( '~ .error' ) ).toHaveCount( 1 );
	} );

	test( 'T9 (server side): maxfilesize is checked again on submit', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		await form.upload( 'photo', photoPng );
		setRule( 'photo', 'maxfilesize', { width: '50', height: '50' } );

		await form.confirm();

		await expect( form.field( 'photo' ).locator( '~ .error' ) ).toHaveCount( 1 );
		await expect( form.field( 'attach' ).locator( '~ .error' ) ).toHaveCount( 0 );
	} );
} );

test( 'F3: the validation settings UI shows the file rules', async ( { page } ) => {
	await page.goto( `/wp-admin/post.php?post=${ formId( 'mwwpf-test-main' ) }&action=edit` );

	for ( const [ selector, value ] of [
		[ 'input[name$="[filetype][types]"]', 'pdf' ],
		[ 'input[name$="[filesize][bytes]"]', '2097152' ],
		[ 'input[name$="[maxfilesize][width]"]', '2000' ],
	] ) {
		const inputs = page.locator( selector );
		await expect( inputs.first() ).toBeAttached();
		const values = await inputs.evaluateAll( ( els ) => els.map( ( el ) => ( el as HTMLInputElement ).value ) );
		expect( values, selector ).toContain( value );
	}
	await expect( page.locator( 'input[type="checkbox"][name$="[noempty]"]:checked' ).first() ).toBeAttached();
} );
