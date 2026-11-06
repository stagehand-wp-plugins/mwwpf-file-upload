import { defineConfig, devices } from '@playwright/test';

// Paths in the tests are relative (no leading slash), so a trailing slash lets the
// site live in a subdirectory, e.g. WP_BASE_URL=http://testwp.localhost/7.1.x
const baseURL = ( process.env.WP_BASE_URL ?? 'http://localhost:8889' ).replace( /\/*$/, '/' );

export default defineConfig( {
	testDir: './tests/e2e',
	outputDir: './test-results',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI
		? [ [ 'list' ], [ 'html', { open: 'never' } ] ]
		: [ [ 'list' ] ],
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /global\.setup\.ts/,
		},
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: 'test-results/.auth/admin.json',
			},
			dependencies: [ 'setup' ],
		},
	],
} );
