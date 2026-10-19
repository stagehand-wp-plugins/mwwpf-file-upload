import { test as setup, expect } from '@playwright/test';

const authFile = 'test-results/.auth/admin.json';

setup( 'log in as admin', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	await page.context().storageState( { path: authFile } );
} );
