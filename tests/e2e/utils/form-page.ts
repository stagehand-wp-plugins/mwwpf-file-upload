import { expect, type Locator, type Page } from '@playwright/test';

/**
 * Helpers for one MW WP Form on a page. `index` selects the form when a page has several.
 */
export class FormPage {
	readonly form: Locator;

	constructor( readonly page: Page, index = 0 ) {
		this.form = page.locator( '.mw_wp_form' ).nth( index ).locator( 'form' );
	}

	field( name: string ): Locator {
		return this.form.locator( `.mwwpf[data-mwwpf-field="${ name }"]` );
	}

	token( name: string ): Locator {
		return this.form.locator( `input[type="hidden"][name="mwwpf_token[${ name }]"]` );
	}

	async choose( name: string, file: { name: string; mimeType: string; buffer: Buffer } ) {
		await this.field( name ).locator( 'input[type="file"]' ).setInputFiles( file );
	}

	/** Chooses a file and waits until the upload has finished. */
	async upload( name: string, file: { name: string; mimeType: string; buffer: Buffer } ) {
		await this.choose( name, file );
		await expect( this.field( name ) ).toHaveClass( /is-uploaded/ );
		await expect( this.token( name ) ).toHaveValue( /^[a-f0-9]{32}$/ );
	}

	async fillName( value: string ) {
		await this.form.locator( 'input[name="your-name"]' ).fill( value );
	}

	async confirm() {
		await this.form.locator( 'button[name="submitConfirm"]' ).click();
		await this.page.waitForLoadState( 'load' );
	}

	async back() {
		await this.form.locator( 'button[name="submitBack"]' ).click();
		await this.page.waitForLoadState( 'load' );
	}

	async submit() {
		await this.form.locator( 'button[type="submit"][value="send"]' ).click();
		await this.page.waitForLoadState( 'load' );
	}
}
