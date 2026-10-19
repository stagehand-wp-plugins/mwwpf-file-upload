import { test, expect } from '@playwright/test';

test( 'front page returns 200', async ( { page } ) => {
	const response = await page.goto( '/' );
	expect( response?.status() ).toBe( 200 );
} );

test( 'admin dashboard is reachable with the saved login', async ( { page } ) => {
	await page.goto( '/wp-admin/' );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
} );

test( 'test form page renders the MW WP Form fields', async ( { page } ) => {
	await page.goto( '/mwwpf-test-main/' );
	await expect( page.locator( '.mw_wp_form input[name="your-name"]' ) ).toBeVisible();
	await expect( page.locator( '.mw_wp_form button[name="submitConfirm"]' ) ).toBeVisible();
} );

test( 'captured mail endpoint is available', async ( { request } ) => {
	const res = await request.get( '/wp-admin/admin-ajax.php?action=mwwpf_test_mails' );
	expect( ( await res.json() ).success ).toBe( true );
} );
