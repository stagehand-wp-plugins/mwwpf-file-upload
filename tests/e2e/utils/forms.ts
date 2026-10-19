import fs from 'node:fs';
import type { TestForms } from './wp-env';

const formsFile = 'test-results/.forms.json';

/**
 * Returns the test forms created by the setup project.
 */
export function getTestForms(): TestForms {
	return JSON.parse( fs.readFileSync( formsFile, 'utf8' ) );
}

export function formId( slug: string ): number {
	const id = getTestForms()[ slug ]?.form_id;
	if ( ! id ) {
		throw new Error( `Unknown test form: ${ slug }` );
	}
	return id;
}
