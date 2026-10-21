import { test, expect } from '@playwright/test';
import { FormPage } from './utils/form-page';
import { pdf, png } from './utils/files';

const quotePdf = { name: '見積依頼書（最新版）.pdf', mimeType: 'application/pdf', buffer: pdf() };
const photoPng = { name: 'photo.png', mimeType: 'image/png', buffer: png( 40, 30 ) };

/**
 * §9.1 screen transition tests (Phase 3). Run as an anonymous visitor.
 */
test.describe( 'screen transitions (§9.1)', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'upload shows the file, sets hidden fields, and can be removed', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );

		await form.upload( 'attach', quotePdf );
		const attach = form.field( 'attach' );
		await expect( attach.locator( '.mwwpf__name' ) ).toHaveText( quotePdf.name );
		await expect( attach.locator( '.mwwpf__remove' ) ).toBeVisible();
		await expect( form.form.locator( 'input[type="hidden"][name="attach"]' ) ).toHaveValue( quotePdf.name );

		await form.upload( 'photo', photoPng );
		await expect( form.field( 'photo' ).locator( 'img.mwwpf__thumb' ) ).toBeVisible();

		await attach.locator( '.mwwpf__remove' ).click();
		await expect( attach ).not.toHaveClass( /is-uploaded/ );
		await expect( form.token( 'attach' ) ).toHaveValue( '' );
		await expect( form.form.locator( 'input[type="hidden"][name="attach"]' ) ).toHaveValue( '' );
	} );

	test( 'T7 (client side): a .docx is rejected before upload', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		let uploads = 0;
		page.on( 'request', ( req ) => {
			if ( req.url().includes( 'admin-ajax.php' ) && req.method() === 'POST' ) {
				uploads++;
			}
		} );

		await form.choose( 'attach', { name: 'a.docx', mimeType: 'application/octet-stream', buffer: pdf() } );

		await expect( form.field( 'attach' ).locator( '.mwwpf__error' ) ).not.toBeEmpty();
		await expect( form.token( 'attach' ) ).toHaveValue( '' );
		expect( uploads ).toBe( 0 );
	} );

	test( 'server-side errors are shown in the field', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );

		// Passes the client-side checks (pdf, small), fails the content check on the server.
		await form.choose( 'attach', { name: 'evil.pdf', mimeType: 'application/pdf', buffer: Buffer.from( "<?php echo 'x';\n" ) } );

		await expect( form.field( 'attach' ).locator( '.mwwpf__error' ) ).not.toBeEmpty();
		await expect( form.token( 'attach' ) ).toHaveValue( '' );
	} );

	test( 'T3: back from the confirm screen keeps the upload, and it can be sent', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		const token = await form.token( 'attach' ).inputValue();

		await form.confirm();
		const confirm = form.form.locator( '.mwwpf-confirm--file' );
		await expect( confirm ).toContainText( quotePdf.name );
		await expect( form.token( 'attach' ) ).toHaveValue( token );
		await expect( form.form.locator( 'input[type="file"]' ) ).toHaveCount( 0 );

		await form.back();
		const attach = form.field( 'attach' );
		await expect( attach ).toHaveClass( /is-uploaded/ );
		await expect( attach.locator( '.mwwpf__name' ) ).toHaveText( quotePdf.name );
		await expect( form.token( 'attach' ) ).toHaveValue( token );

		await form.confirm();
		await form.submit();
		await expect( page.locator( '.mwwpf-test-complete' ) ).toBeVisible();
	} );

	test( 'T4: an error in another field keeps the upload', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.upload( 'attach', quotePdf );
		const token = await form.token( 'attach' ).inputValue();

		// your-name is required and left empty.
		await form.confirm();

		await expect( form.form.locator( 'input[name="your-name"]' ) ).toBeVisible();
		await expect( form.form.locator( '.error' ).first() ).toBeVisible();
		await expect( form.field( 'attach' ) ).toHaveClass( /is-uploaded/ );
		await expect( form.token( 'attach' ) ).toHaveValue( token );
	} );

	test( 'a tampered or foreign token is not shown as uploaded', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quotePdf );
		await form.token( 'attach' ).evaluate( ( el: HTMLInputElement ) => ( el.value = 'f'.repeat( 32 ) ) );

		await form.confirm();

		await expect( form.form.locator( '.mwwpf-confirm--file' ) ).toHaveCount( 0 );
	} );

	test( 'T15: submit buttons are disabled while uploading', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );

		let release: () => void = () => {};
		const held = new Promise< void >( ( resolve ) => ( release = resolve ) );
		await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				await held;
			}
			await route.continue();
		} );

		await form.choose( 'attach', quotePdf );
		const confirmButton = form.form.locator( 'button[name="submitConfirm"]' );
		await expect( confirmButton ).toBeDisabled();
		await expect( form.field( 'attach' ) ).toHaveClass( /is-uploading/ );

		release();
		await expect( form.field( 'attach' ) ).toHaveClass( /is-uploaded/ );
		await expect( confirmButton ).toBeEnabled();
	} );

	test( 'T14: two forms on one page do not interfere', async ( { page } ) => {
		await page.goto( '/mwwpf-test-two/' );
		const first = new FormPage( page, 0 );
		const second = new FormPage( page, 1 );

		await second.upload( 'attach', quotePdf );
		await expect( first.token( 'attach' ) ).toHaveValue( '' );
		await expect( first.field( 'attach' ) ).not.toHaveClass( /is-uploaded/ );

		await first.upload( 'attach', { ...quotePdf, name: 'first.pdf' } );
		await expect( first.field( 'attach' ).locator( '.mwwpf__name' ) ).toHaveText( 'first.pdf' );
		await expect( second.field( 'attach' ).locator( '.mwwpf__name' ) ).toHaveText( quotePdf.name );
		expect( await first.token( 'attach' ).inputValue() ).not.toBe( await second.token( 'attach' ).inputValue() );
		expect( await first.field( 'attach' ).getAttribute( 'data-mwwpf-form' ) ).not.toBe(
			await second.field( 'attach' ).getAttribute( 'data-mwwpf-form' )
		);
	} );

	test( 'a stale nonce (cached page) is refreshed automatically', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		await page.evaluate( () => ( ( window as any ).mwwpfConfig.nonce = 'stale' ) );
		const form = new FormPage( page );

		await form.upload( 'attach', quotePdf );
	} );
} );

test.describe( 'T16: JavaScript disabled', () => {
	test.use( { javaScriptEnabled: false, storageState: { cookies: [], origins: [] } } );

	test( 'shows the noscript message', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		await expect( page.locator( '.mwwpf[data-mwwpf-field="attach"] .mwwpf__noscript' ) ).toBeVisible();
	} );
} );
