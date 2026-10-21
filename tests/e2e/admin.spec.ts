import crypto from 'node:crypto';
import fs from 'node:fs';
import { test, expect, type Page, type Browser } from '@playwright/test';
import { FormPage } from './utils/form-page';
import { jpeg, pdf } from './utils/files';
import { formId } from './utils/forms';
import { wpCli } from './utils/wp-env';

const adminUrl = '/wp-admin/edit.php?post_type=mw-wp-form&page=mwwpf-attachments';
const md5 = ( buf: Buffer ) => crypto.createHash( 'md5' ).update( buf ).digest( 'hex' );
const unique = () => Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 6 );

/** Sends a form as an anonymous visitor and returns the tokens of the uploaded files. */
async function send(
	browser: Browser,
	slug: string,
	files: Record< string, { name: string; mimeType: string; buffer: Buffer } >
): Promise< Record< string, string > > {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	const page = await context.newPage();
	await page.goto( `/${ slug }/` );
	const form = new FormPage( page );
	await form.fillName( '山田太郎' );
	const tokens: Record< string, string > = {};
	for ( const [ field, file ] of Object.entries( files ) ) {
		await form.upload( field, file );
		tokens[ field ] = await form.token( field ).inputValue();
	}
	await form.confirm();
	await form.submit();
	await expect( page.locator( '.mwwpf-test-complete' ) ).toBeVisible();
	await context.close();
	return tokens;
}

function row( page: Page, name: string ) {
	return page.locator( 'table.wp-list-table tbody tr', { hasText: name } );
}

function storageFileExists( path: string ): boolean {
	const out = wpCli( [ 'eval', `echo file_exists( MWWPF_Storage::root_dir() . '/${ path }' ) ? 'yes' : 'no';` ] );
	return out.includes( 'yes' );
}

function countAttachments(): number {
	return parseInt( wpCli( [ 'post', 'list', '--post_type=attachment', '--format=count' ] ).trim().split( '\n' ).pop()!, 10 );
}

test.describe( 'stored files (§6.9, §6.14)', () => {
	test( 'T10 + T11: sent files are stored, listed, and not in the media library', async ( { page, browser } ) => {
		const id = unique();
		const mediaBefore = countAttachments();
		const offName = `off-${ id }.pdf`;
		const onName = `on-${ id }.pdf`;

		const off = await send( browser, 'mwwpf-test-main', { attach: { name: offName, mimeType: 'application/pdf', buffer: pdf() } } );
		const on = await send( browser, 'mwwpf-test-db', { attach: { name: onName, mimeType: 'application/pdf', buffer: pdf() } } );

		// T11: nothing left in tmp/; the files are in files/{form_id}/.
		for ( const [ slug, tokens ] of [ [ 'mwwpf-test-main', off ], [ 'mwwpf-test-db', on ] ] as const ) {
			expect( storageFileExists( `tmp/${ tokens.attach }.bin` ) ).toBe( false );
			expect( storageFileExists( `tmp/${ tokens.attach }.json` ) ).toBe( false );
			expect( storageFileExists( `files/${ formId( slug ) }/${ tokens.attach }.bin` ) ).toBe( true );
		}
		expect( countAttachments() ).toBe( mediaBefore );

		await page.goto( adminUrl );
		await expect( row( page, offName ) ).toHaveCount( 1 );
		await expect( row( page, offName ).locator( 'td.column-form' ) ).toContainText( 'MWWPF Test Main' );
		await expect( row( page, offName ).locator( 'td.column-field' ) ).toHaveText( 'attach' );
		await expect( row( page, offName ).locator( 'a.mwwpf-inquiry' ) ).toHaveCount( 0 );
		// DB saving on: a link to the inquiry data.
		await expect( row( page, onName ).locator( 'a.mwwpf-inquiry' ) ).toHaveCount( 1 );
	} );

	test( 'S7: a token that was sent cannot be used again', async ( { browser } ) => {
		const name = `s7-${ unique() }.pdf`;
		const context = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const page = await context.newPage();
		await page.goto( '/mwwpf-test-main/' );
		const form = new FormPage( page );
		await form.fillName( '山田太郎' );
		await form.upload( 'attach', { name, mimeType: 'application/pdf', buffer: pdf() } );
		const token = await form.token( 'attach' ).inputValue();
		await form.confirm();
		await form.submit();

		// Same browser (same cookie) posts the old token again.
		await page.goto( '/mwwpf-test-main/' );
		await form.fillName( '山田太郎' );
		await form.token( 'attach' ).evaluate( ( el: HTMLInputElement, v ) => ( el.value = v ), token );
		await form.form.locator( 'input[type="hidden"][name="attach"]' ).evaluate( ( el: HTMLInputElement, v ) => ( el.value = v ), name );
		await form.confirm();
		await expect( form.field( 'attach' ).locator( '~ .error' ) ).toHaveCount( 1 );
		await context.close();
	} );

	test( 'T17: download under the original name, and image preview', async ( { page, browser } ) => {
		const id = unique();
		const quote = { name: `見積書-${ id }.pdf`, mimeType: 'application/pdf', buffer: pdf( 300 ) };
		const photo = { name: `写真-${ id }.jpg`, mimeType: 'image/jpeg', buffer: jpeg() };
		await send( browser, 'mwwpf-test-main', { attach: quote, photo } );

		await page.goto( adminUrl );
		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			row( page, quote.name ).locator( 'a.mwwpf-download' ).click(),
		] );
		expect( download.suggestedFilename() ).toBe( quote.name );
		expect( md5( fs.readFileSync( ( await download.path() )! ) ) ).toBe( md5( quote.buffer ) );

		const img = row( page, photo.name ).locator( 'td.column-preview img' );
		await expect( img ).toBeVisible();
		await expect.poll( () => img.evaluate( ( el: HTMLImageElement ) => el.naturalWidth ) ).toBeGreaterThan( 0 );
		await expect( row( page, quote.name ).locator( 'td.column-preview img' ) ).toHaveCount( 0 );
	} );

	test( 'T18: delete one file, and delete several with the bulk action', async ( { page, browser } ) => {
		const id = unique();
		const names = [ `del1-${ id }.pdf`, `del2-${ id }.pdf`, `del3-${ id }.pdf`, `keep-${ id }.pdf` ];
		for ( const name of names ) {
			await send( browser, 'mwwpf-test-main', { attach: { name, mimeType: 'application/pdf', buffer: pdf() } } );
		}

		await page.goto( adminUrl );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await row( page, names[ 0 ] ).hover();
		await row( page, names[ 0 ] ).locator( 'button.mwwpf-delete' ).click();
		await expect( page.locator( '.mwwpf-notice-deleted' ) ).toContainText( '1' );
		await expect( row( page, names[ 0 ] ) ).toHaveCount( 0 );

		await row( page, names[ 1 ] ).locator( 'input[type="checkbox"]' ).check();
		await row( page, names[ 2 ] ).locator( 'input[type="checkbox"]' ).check();
		await page.locator( '#bulk-action-selector-top' ).selectOption( 'delete' );
		await page.locator( '#doaction' ).click();
		await expect( page.locator( '.mwwpf-notice-deleted' ) ).toContainText( '2' );
		await expect( row( page, names[ 1 ] ) ).toHaveCount( 0 );
		await expect( row( page, names[ 2 ] ) ).toHaveCount( 0 );
		await expect( row( page, names[ 3 ] ) ).toHaveCount( 1 );
	} );

	test( 'T12: retention period setting and automatic deletion', async ( { page, browser } ) => {
		const id = unique();
		const oldName = `old-${ id }.pdf`;
		const newName = `new-${ id }.pdf`;
		const old = await send( browser, 'mwwpf-test-main', { attach: { name: oldName, mimeType: 'application/pdf', buffer: pdf() } } );
		await send( browser, 'mwwpf-test-main', { attach: { name: newName, mimeType: 'application/pdf', buffer: pdf() } } );

		await page.goto( adminUrl );
		await page.locator( '#mwwpf-retention-days' ).fill( '1' );
		await page.locator( 'input[name="mwwpf_save_retention"]' ).click();
		await expect( page.locator( '.mwwpf-notice-saved' ) ).toBeVisible();
		await expect( page.locator( '#mwwpf-retention-days' ) ).toHaveValue( '1' );

		try {
			// Make one file two days old, then run the cleanup.
			const json = `files/${ formId( 'mwwpf-test-main' ) }/${ old.attach }.json`;
			wpCli( [
				'eval',
				`$p = MWWPF_Storage::root_dir() . '/${ json }'; $m = json_decode( file_get_contents( $p ), true ); $m['archived'] = time() - 2 * DAY_IN_SECONDS; file_put_contents( $p, wp_json_encode( $m ) );`,
			] );
			wpCli( [ 'eval', 'MWWPF_Cleanup::run();' ] );

			await page.goto( adminUrl );
			await expect( row( page, oldName ) ).toHaveCount( 0 );
			await expect( row( page, newName ) ).toHaveCount( 1 );
		} finally {
			wpCli( [ 'option', 'delete', 'mwwpf_retention_days' ] );
		}
	} );

	test( 'T19: editors and anonymous visitors cannot access the screen or the files', async ( { page, browser, baseURL } ) => {
		const name = `t19-${ unique() }.pdf`;
		await send( browser, 'mwwpf-test-main', { attach: { name, mimeType: 'application/pdf', buffer: pdf() } } );
		await page.goto( adminUrl );
		const downloadUrl = await row( page, name ).locator( 'a.mwwpf-download' ).getAttribute( 'href' );
		expect( downloadUrl ).toBeTruthy();

		try {
			wpCli( [ 'user', 'create', 'mwwpf-editor', 'mwwpf-editor@example.com', '--role=editor', '--user_pass=password' ] );
		} catch {
			// Already exists.
		}

		const editor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const editorPage = await editor.newPage();
		await editorPage.goto( '/wp-login.php' );
		await editorPage.locator( '#user_login' ).fill( 'mwwpf-editor' );
		await editorPage.locator( '#user_pass' ).fill( 'password' );
		await editorPage.locator( '#wp-submit' ).click();
		await expect( editorPage.locator( '#wpadminbar' ) ).toBeVisible();

		const screen = await editorPage.request.get( adminUrl );
		expect( screen.status() ).toBe( 403 );
		const file = await editorPage.request.get( downloadUrl! );
		expect( file.status() ).toBe( 403 );
		expect( file.headers()[ 'content-disposition' ] ).toBeUndefined();
		await editor.close();

		const anonymous = await browser.newContext( { storageState: { cookies: [], origins: [] }, baseURL } );
		const anonFile = await anonymous.request.get( downloadUrl!, { maxRedirects: 0 } );
		expect( anonFile.headers()[ 'content-disposition' ] ).toBeUndefined();
		expect( await anonFile.text() ).not.toContain( '%PDF' );
		await anonymous.close();
	} );

	test( 'S11: a download link with a changed token or form ID is rejected', async ( { page, browser } ) => {
		const name = `s11-${ unique() }.pdf`;
		await send( browser, 'mwwpf-test-main', { attach: { name, mimeType: 'application/pdf', buffer: pdf() } } );
		await page.goto( adminUrl );
		const url = new URL( ( await row( page, name ).locator( 'a.mwwpf-download' ).getAttribute( 'href' ) )!, 'http://localhost' );

		for ( const [ key, value ] of [
			[ 'token', '../' + url.searchParams.get( 'token' ) ],
			[ 'form_id', '../' + url.searchParams.get( 'form_id' ) ],
			[ '_wpnonce', 'invalid' ],
		] ) {
			const changed = new URL( url );
			changed.searchParams.set( key, value! );
			const res = await page.request.get( changed.pathname + changed.search );
			expect( res.status(), key ).toBe( 403 );
		}
	} );

	test( 'T20: storage warning on servers that ignore .htaccess', async ( { page } ) => {
		await page.goto( adminUrl );
		await expect( page.locator( '.mwwpf-storage-warning' ) ).toHaveCount( 0 );

		wpCli( [ 'option', 'update', 'mwwpf_test_ignores_htaccess', '1' ] );
		try {
			await page.goto( adminUrl );
			// Shown both as an admin notice and on the screen.
			await expect( page.locator( '.mwwpf-storage-warning' ) ).toHaveCount( 2 );
			await page.goto( '/wp-admin/' );
			await expect( page.locator( '.mwwpf-storage-warning' ) ).toHaveCount( 1 );

			wpCli( [ 'config', 'set', 'MWWPF_STORAGE_PROTECTED', 'true', '--raw' ] );
			// The web server may cache wp-config.php for a moment (opcache).
			await expect( async () => {
				await page.goto( adminUrl );
				await expect( page.locator( '.mwwpf-storage-warning' ) ).toHaveCount( 0, { timeout: 1000 } );
			} ).toPass( { timeout: 20000 } );
		} finally {
			wpCli( [ 'option', 'delete', 'mwwpf_test_ignores_htaccess' ] );
			try {
				wpCli( [ 'config', 'delete', 'MWWPF_STORAGE_PROTECTED' ] );
			} catch {
				// Not set.
			}
		}
	} );
} );
