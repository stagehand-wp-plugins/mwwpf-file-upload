import { test, expect } from '@playwright/test';

test( 'front page returns 200', async ( { page } ) => {
	const response = await page.goto( '/' );
	expect( response?.status() ).toBe( 200 );
} );

test( 'admin dashboard is reachable with the saved login', async ( { page } ) => {
	await page.goto( '/wp-admin/' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
} );
