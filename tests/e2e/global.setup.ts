import fs from 'node:fs';
import { test as setup, expect } from '@playwright/test';
import { createTestForms } from './utils/wp-env';

const authFile = 'test-results/.auth/admin.json';
export const formsFile = 'test-results/.forms.json';

setup( 'create test forms', async () => {
	const forms = createTestForms();
	expect( forms[ 'mwwpf-test-main' ]?.form_id ).toBeGreaterThan( 0 );
	fs.mkdirSync( 'test-results', { recursive: true } );
	fs.writeFileSync( formsFile, JSON.stringify( forms, null, 2 ) );
} );

setup( 'log in as admin', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await page.context().storageState( { path: authFile } );
} );
