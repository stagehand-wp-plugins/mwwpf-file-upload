import crypto from 'node:crypto';
import { test, expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test';
import { FormPage } from './utils/form-page';
import { jpeg, pdf } from './utils/files';
import { clearMails, getMails, type CapturedMail } from './utils/mail';

const md5 = ( buf: Buffer ) => crypto.createHash( 'md5' ).update( buf ).digest( 'hex' );

const quote = { name: '見積依頼書（最新版）.pdf', mimeType: 'application/pdf', buffer: pdf( 500 ) };
const photo = { name: '現場写真.jpg', mimeType: 'image/jpeg', buffer: jpeg() };

/**
 * §9.1 mail tests (Phase 5). Visitors are anonymous; mail is read with the admin session.
 */
test.describe( 'mail (§9.1)', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	let admin: APIRequestContext;

	test.beforeEach( async ( { baseURL } ) => {
		admin = await playwrightRequest.newContext( { baseURL, storageState: 'test-results/.auth/admin.json' } );
		await clearMails( admin );
	} );

	test.afterEach( async () => {
		await admin.dispose();
	} );

	async function waitForMails( count: number ): Promise< CapturedMail[] > {
		await expect.poll( async () => ( await getMails( admin ) ).length ).toBe( count );
		return getMails( admin );
	}

	const toList = ( to: string | string[] ) => ( Array.isArray( to ) ? to : [ to ] ).join( ',' );

	test( 'T1 + T2: admin mail has both files under their original (Japanese) names', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quote );
		await form.upload( 'photo', photo );
		await form.confirm();
		await form.submit();
		await expect( page.locator( '.mwwpf-test-complete' ) ).toBeVisible();

		const [ mail ] = await waitForMails( 1 );
		expect( toList( mail.to ) ).toContain( 'admin@example.com' );
		expect( mail.attachments.sort() ).toEqual( [ quote.name, photo.name ].sort() );
		expect( mail.files[ quote.name ].md5 ).toBe( md5( quote.buffer ) );
		expect( mail.files[ photo.name ].md5 ).toBe( md5( photo.buffer ) );
		expect( mail.message ).toContain( `資料: ${ quote.name }` );
		expect( mail.message ).toContain( `写真: ${ photo.name }` );
	} );

	test( 'T5 + T13: without a confirm screen the file is attached; the auto reply has no attachment', async ( { page } ) => {
		await page.goto( '/mwwpf-test-direct/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.form.locator( 'input[name="your-email"]' ).fill( 'visitor@example.com' );
		await form.upload( 'attach', quote );
		await form.submit();
		await expect( page.locator( '.mwwpf-test-complete' ) ).toBeVisible();

		const mails = await waitForMails( 2 );
		const adminMail = mails.find( ( m ) => toList( m.to ).includes( 'admin@example.com' ) )!;
		const reply = mails.find( ( m ) => toList( m.to ).includes( 'visitor@example.com' ) )!;

		expect( adminMail.attachments ).toEqual( [ quote.name ] );
		expect( reply.attachments ).toEqual( [] );
		expect( reply.message ).toContain( `資料: ${ quote.name }` );
	} );

	test( 'S6: a tampered file name in the hidden field is ignored', async ( { page } ) => {
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', quote );
		await form.form.locator( 'input[type="hidden"][name="attach"]' ).evaluate( ( el: HTMLInputElement ) => ( el.value = 'evil.php' ) );
		await form.confirm();
		await form.form.locator( 'input[type="hidden"][name="attach"]' ).evaluate( ( el: HTMLInputElement ) => ( el.value = 'evil.php' ) );
		await form.submit();

		const [ mail ] = await waitForMails( 1 );
		expect( mail.attachments ).toEqual( [ quote.name ] );
		expect( mail.message ).toContain( `資料: ${ quote.name }` );
		expect( mail.message ).not.toContain( 'evil.php' );
	} );

	test( 'S3: a token taken from another visitor is not attached and not shown', async ( { page, browser } ) => {
		// Another visitor uploads a file.
		const otherContext = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const otherPage = await otherContext.newPage();
		await otherPage.goto( '/mwwpf-test-main/' );
		const otherForm = new FormPage( otherPage );
		await otherForm.upload( 'attach', { ...quote, name: 'secret.pdf' } );
		const stolen = await otherForm.token( 'attach' ).inputValue();
		await otherContext.close();

		// This visitor posts that token. The noempty rule rejects it, so the form cannot even be confirmed.
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.token( 'attach' ).evaluate( ( el: HTMLInputElement, value ) => ( el.value = value ), stolen );
		await form.form.locator( 'input[type="hidden"][name="attach"]' ).evaluate( ( el: HTMLInputElement ) => ( el.value = 'secret.pdf' ) );
		await form.confirm();

		await expect( form.field( 'attach' ).locator( '~ .error' ) ).toHaveCount( 1 );
		expect( await getMails( admin ) ).toHaveLength( 0 );
	} );
} );
