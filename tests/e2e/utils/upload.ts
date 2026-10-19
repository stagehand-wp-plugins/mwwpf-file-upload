import type { APIRequestContext } from '@playwright/test';

export const ajaxUrl = '/wp-admin/admin-ajax.php';

export async function getNonce( request: APIRequestContext ): Promise< string > {
	const res = await request.get( `${ ajaxUrl }?action=mwwpf_nonce` );
	return ( await res.json() ).data.nonce;
}

export type UploadArgs = {
	nonce?: string;
	formId: number | string;
	field: string;
	name: string;
	buffer: Buffer;
	mimeType?: string;
};

export async function upload( request: APIRequestContext, args: UploadArgs ) {
	const multipart: Record< string, any > = {
		action: 'mwwpf_upload',
		form_id: String( args.formId ),
		field: args.field,
		file: {
			name: args.name,
			mimeType: args.mimeType ?? 'application/octet-stream',
			buffer: args.buffer,
		},
	};
	if ( args.nonce !== undefined ) {
		multipart.nonce = args.nonce;
	}
	const res = await request.post( ajaxUrl, { multipart } );
	return { status: res.status(), body: await res.json() };
}
