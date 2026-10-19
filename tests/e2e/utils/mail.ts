import type { APIRequestContext } from '@playwright/test';

export type CapturedMail = {
	id: string;
	to: string | string[];
	subject: string;
	message: string;
	headers: string | string[];
	attachments: string[];
	files: Record< string, { size: number; md5: string } >;
};

/**
 * Returns captured mail (oldest first). The request context must be logged in as admin.
 */
export async function getMails( request: APIRequestContext ): Promise< CapturedMail[] > {
	const res = await request.get( '/wp-admin/admin-ajax.php?action=mwwpf_test_mails' );
	const body = await res.json();
	if ( ! body.success ) {
		throw new Error( 'Failed to read captured mail: ' + JSON.stringify( body ) );
	}
	return body.data;
}

export async function clearMails( request: APIRequestContext ): Promise< void > {
	const res = await request.post( '/wp-admin/admin-ajax.php?action=mwwpf_test_clear_mails' );
	const body = await res.json();
	if ( ! body.success ) {
		throw new Error( 'Failed to clear captured mail: ' + JSON.stringify( body ) );
	}
}
