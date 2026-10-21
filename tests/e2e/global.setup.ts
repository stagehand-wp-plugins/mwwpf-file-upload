import fs from 'node:fs';
import { test as setup, expect } from '@playwright/test';
import { createTestForms } from './utils/wp-env';

const authFile = 'test-results/.auth/admin.json';
const formsFile = 'test-results/.forms.json';

setup( 'create test forms', async () => {
	const forms = createTestForms();
	expect( forms[ 'mwwpf-test-main' ]?.form_id ).toBeGreaterThan( 0 );
	fs.mkdirSync( 'test-results', { recursive: true } );
	fs.writeFileSync( formsFile, JSON.stringify( forms, null, 2 ) );
} );

setup( 'log in as admin', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.waitForLoadState( 'load' );
	// The login page focuses the user field on load; fill and verify both fields before submitting.
	await expect( async () => {
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
		await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin', { timeout: 1000 } );
		await expect( page.locator( '#user_pass' ) ).toHaveValue( 'password', { timeout: 1000 } );
	} ).toPass();
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await page.context().storageState( { path: authFile } );
} );
