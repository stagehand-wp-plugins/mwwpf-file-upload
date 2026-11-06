import { execFileSync } from 'node:child_process';

/**
 * Runs a WP-CLI command and returns stdout.
 *
 * Uses the wp-env tests container by default. To test another site, set WP_CLI_CMD to the
 * command that runs WP-CLI there (split on spaces), e.g.
 * "docker compose -f ../testwp/compose.yaml exec -T -u www-data -w /var/www/html/7.1.x web wp".
 */
export function wpCli( args: string[] ): string {
	const [ cmd, ...prefix ] = process.env.WP_CLI_CMD
		? process.env.WP_CLI_CMD.trim().split( /\s+/ )
		: [ 'npx', 'wp-env', 'run', 'tests-cli', 'wp' ];
	const out = execFileSync( cmd, [ ...prefix, ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
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
