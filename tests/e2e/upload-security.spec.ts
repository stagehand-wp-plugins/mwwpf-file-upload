import { test, expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test';
import { formId, getTestForms } from './utils/forms';
import { pdf, png } from './utils/files';
import { ajaxUrl, getNonce, upload } from './utils/upload';
import { wpCli } from './utils/wp-env';

/**
 * §9.2 security tests with direct requests, as anonymous visitors.
 * Each visitor is a separate request context with its own cookies.
 */
test.describe( 'upload endpoints (§9.2)', () => {
	let visitor: APIRequestContext;
	let other: APIRequestContext;
	let nonce: string;
	let main: number;

	test.beforeEach( async ( { baseURL } ) => {
		visitor = await playwrightRequest.newContext( { baseURL } );
		other = await playwrightRequest.newContext( { baseURL } );
		nonce = await getNonce( visitor );
		main = formId( 'mwwpf-test-main' );
	} );

	test.afterEach( async () => {
		await visitor.dispose();
		await other.dispose();
	} );

	const preview = ( ctx: APIRequestContext, token: string, field = 'photo' ) =>
		ctx.get( `${ ajaxUrl }?action=mwwpf_preview&token=${ token }&form_id=${ main }&field=${ field }` );

	test( 'valid upload, own preview, and S3: another visitor cannot use the token', async () => {
		const res = await upload( visitor, { nonce, formId: main, field: 'photo', name: 'photo.png', buffer: png( 10, 10 ) } );
		expect( res.status ).toBe( 200 );
		expect( res.body.data.token ).toMatch( /^[a-f0-9]{32}$/ );
		expect( res.body.data.name ).toBe( 'photo.png' );

		const own = await preview( visitor, res.body.data.token );
		expect( own.status() ).toBe( 200 );
		expect( own.headers()[ 'content-type' ] ).toBe( 'image/png' );
		expect( own.headers()[ 'x-content-type-options' ] ).toBe( 'nosniff' );
		expect( own.headers()[ 'cache-control' ] ).toContain( 'no-store' );

		expect( ( await preview( other, res.body.data.token ) ).status() ).toBe( 404 );
	} );

	test( 'S1: a PHP file renamed to .pdf is rejected', async () => {
		const res = await upload( visitor, { nonce, formId: main, field: 'attach', name: 'evil.pdf', buffer: Buffer.from( "<?php echo 'x';\n" ) } );
		expect( res.status ).toBe( 400 );
		expect( res.body.data.code ).toBe( 'filetype' );
	} );

	test( 'S2: dangerous names and double extensions are rejected', async () => {
		const direct = formId( 'mwwpf-test-direct' );
		// The direct form has the same filetype rule (pdf only) for attach.
		for ( const name of [ 'a.pdf.php', 'a.svg', '.htaccess', 'a.php.pdf' ] ) {
			const buffer = name === 'a.php.pdf' ? Buffer.from( "<?php echo 'x';\n" ) : pdf();
			const res = await upload( visitor, { nonce, formId: direct, field: 'attach', name, buffer } );
			expect( res.status, name ).toBe( 400 );
		}
	} );

	test( 'S4: unknown field, missing form, and other post types are rejected', async () => {
		const pageId = getTestForms()[ 'mwwpf-test-main' ].page_id!;
		for ( const [ form, field ] of [
			[ main, 'your-name' ],
			[ main, 'unknown' ],
			[ 999999, 'attach' ],
			[ pageId, 'attach' ],
		] as const ) {
			const res = await upload( visitor, { nonce, formId: form, field, name: 'a.pdf', buffer: pdf() } );
			expect( res.status, `${ form }/${ field }` ).toBe( 400 );
			expect( res.body.data.code ).toBe( 'invalid_target' );
		}
	} );

	test( 'S5: field names with ../ or a null byte are rejected', async () => {
		for ( const field of [ '../attach', 'attach\0', 'attach/../x' ] ) {
			const res = await upload( visitor, { nonce, formId: main, field, name: 'a.pdf', buffer: pdf() } );
			expect( res.status, JSON.stringify( field ) ).toBe( 400 );
		}
	} );

	test( 'S8: the storage directory cannot be accessed directly', async () => {
		const res = await upload( visitor, { nonce, formId: main, field: 'attach', name: 'a.pdf', buffer: pdf() } );
		const token = res.body.data.token;
		for ( const path of [ `tmp/${ token }.bin`, `tmp/${ token }.json`, 'tmp/', '' ] ) {
			const direct = await visitor.get( `/wp-content/uploads/mwwpf/${ path }` );
			expect( [ 403, 404 ], path ).toContain( direct.status() );
		}
	} );

	test( 'S9: missing or wrong nonce is rejected', async () => {
		const missing = await upload( visitor, { formId: main, field: 'attach', name: 'a.pdf', buffer: pdf() } );
		expect( missing.status ).toBe( 403 );
		expect( missing.body.data.code ).toBe( 'invalid_nonce' );

		const wrong = await upload( visitor, { nonce: 'abc', formId: main, field: 'attach', name: 'a.pdf', buffer: pdf() } );
		expect( wrong.status ).toBe( 403 );
	} );

	test( 'S9: rate limit', async () => {
		wpCli( [ 'transient', 'delete', '--all' ] );
		wpCli( [ 'option', 'update', 'mwwpf_test_rate_limit', '3' ] );
		try {
			const statuses = [];
			for ( let i = 0; i < 4; i++ ) {
				statuses.push( ( await upload( visitor, { nonce, formId: main, field: 'attach', name: 'a.pdf', buffer: pdf() } ) ).status );
			}
			expect( statuses ).toEqual( [ 200, 200, 200, 429 ] );
		} finally {
			wpCli( [ 'option', 'delete', 'mwwpf_test_rate_limit' ] );
			wpCli( [ 'transient', 'delete', '--all' ] );
		}
	} );

	test( 'S10: preview of a non-image token returns 404', async () => {
		const res = await upload( visitor, { nonce, formId: main, field: 'attach', name: 'a.pdf', buffer: pdf() } );
		expect( ( await preview( visitor, res.body.data.token, 'attach' ) ).status() ).toBe( 404 );
	} );

	test( 'delete: only the owner can delete, and only once', async () => {
		const res = await upload( visitor, { nonce, formId: main, field: 'attach', name: 'a.pdf', buffer: pdf() } );
		const token = res.body.data.token;
		const del = ( ctx: APIRequestContext, n: string ) =>
			ctx.post( ajaxUrl, { form: { action: 'mwwpf_delete', nonce: n, form_id: String( main ), field: 'attach', token } } );

		expect( ( await del( other, await getNonce( other ) ) ).status() ).toBe( 404 );
		expect( ( await del( visitor, nonce ) ).status() ).toBe( 200 );
		expect( ( await del( visitor, nonce ) ).status() ).toBe( 404 );
	} );

	test( 'filesize and image size limits from the form settings', async () => {
		const big = await upload( visitor, { nonce, formId: main, field: 'attach', name: 'big.pdf', buffer: pdf( 3 * 1024 * 1024 ) } );
		expect( big.status ).toBe( 400 );
		expect( big.body.data.code ).toBe( 'filesize' );

		const wide = await upload( visitor, { nonce, formId: main, field: 'photo', name: 'wide.png', buffer: png( 2001, 1 ) } );
		expect( wide.status ).toBe( 400 );
		expect( wide.body.data.code ).toBe( 'maxfilesize' );

		const docx = await upload( visitor, { nonce, formId: main, field: 'attach', name: 'a.docx', buffer: pdf() } );
		expect( docx.status ).toBe( 400 );
		expect( docx.body.data.code ).toBe( 'filetype' );
	} );
} );
