import zlib from 'node:zlib';

/**
 * Minimal valid PDF. `pad` appends bytes to control the size.
 */
export function pdf( pad = 0 ): Buffer {
	return Buffer.concat( [
		Buffer.from(
			'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'
		),
		Buffer.alloc( pad, '%' ),
	] );
}

function crc32( buf: Buffer ): number {
	let crc = ~0;
	for ( const byte of buf ) {
		crc ^= byte;
		for ( let k = 0; k < 8; k++ ) {
			crc = ( crc >>> 1 ) ^ ( 0xedb88320 & -( crc & 1 ) );
		}
	}
	return ~crc >>> 0;
}

function chunk( type: string, data: Buffer ): Buffer {
	const len = Buffer.alloc( 4 );
	len.writeUInt32BE( data.length );
	const body = Buffer.concat( [ Buffer.from( type ), data ] );
	const crc = Buffer.alloc( 4 );
	crc.writeUInt32BE( crc32( body ) );
	return Buffer.concat( [ len, body, crc ] );
}

/**
 * Minimal valid PNG (solid red).
 */
export function png( width: number, height: number ): Buffer {
	const row = Buffer.concat( [ Buffer.from( [ 0 ] ), Buffer.alloc( width * 3, Buffer.from( [ 255, 0, 0 ] ) ) ] );
	const raw = Buffer.concat( Array( height ).fill( row ) );
	const ihdr = Buffer.alloc( 13 );
	ihdr.writeUInt32BE( width, 0 );
	ihdr.writeUInt32BE( height, 4 );
	ihdr.set( [ 8, 2, 0, 0, 0 ], 8 );
	return Buffer.concat( [
		Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] ),
		chunk( 'IHDR', ihdr ),
		chunk( 'IDAT', zlib.deflateSync( raw ) ),
		chunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}
