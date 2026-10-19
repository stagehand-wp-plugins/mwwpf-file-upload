import { execFileSync } from 'node:child_process';

/**
 * Runs a WP-CLI command in the wp-env tests container and returns stdout.
 */
export function wpCli( args: string[] ): string {
	const out = execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', ...args ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
	return out;
}

export type TestForms = Record<
	string,
	{ form_id?: number; page?: string; page_id?: number }
>;

/**
 * Creates (or updates) the test forms and pages. Returns the JSON map printed by the script.
 */
export function createTestForms(): TestForms {
	const out = wpCli( [
		'eval-file',
		'wp-content/plugins/mwwpf-file-upload/tests/setup/create-forms.php',
	] );
	const json = out.slice( out.indexOf( '{' ), out.lastIndexOf( '}' ) + 1 );
	return JSON.parse( json );
}
