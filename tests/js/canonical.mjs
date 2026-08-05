/**
 * Print the markup WordPress itself would save for a given block.
 *
 * A development aid, not part of the test run. The converter builds its block
 * markup as hand-written strings, and the only way to know whether a string
 * matches what a block's save() produces was to guess and hope. This asks core
 * directly: createBlock() + serialize() is exactly what the editor writes to
 * post_content.
 *
 * Usage:
 *   node canonical.mjs '{"name":"core/cover","attributes":{"url":"…"}}'
 *   node canonical.mjs @spec.json
 *
 * The spec may also carry "innerBlocks": [ { name, attributes, innerBlocks } ].
 */

import fs from 'node:fs';
import { createRequire } from 'node:module';
import { JSDOM, VirtualConsole } from 'jsdom';

const virtualConsole = new VirtualConsole();
virtualConsole.on( 'jsdomError', () => {} );

const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
    url: 'https://example.test/',
    pretendToBeVisual: true,
    virtualConsole,
} );

globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.self = dom.window;
Object.defineProperty( globalThis, 'navigator', {
    value: dom.window.navigator, configurable: true, writable: true,
} );
for ( const key of [
    'HTMLElement', 'Element', 'Node', 'DocumentFragment', 'CustomEvent',
    'MutationObserver', 'getComputedStyle', 'DOMParser', 'XMLSerializer',
] ) {
    globalThis[ key ] = dom.window[ key ];
}
globalThis.requestAnimationFrame = ( cb ) => setTimeout( cb, 0 );
globalThis.cancelAnimationFrame = ( id ) => clearTimeout( id );
globalThis.matchMedia = () => ( {
    matches: false,
    addListener() {}, removeListener() {},
    addEventListener() {}, removeEventListener() {},
} );

const require = createRequire( import.meta.url );
const { createBlock, serialize } = require( '@wordpress/blocks' );
require( '@wordpress/block-library' ).registerCoreBlocks();

function build( spec ) {
    const inner = ( spec.innerBlocks || [] ).map( build );
    return createBlock( spec.name, spec.attributes || {}, inner );
}

const arg = process.argv[ 2 ];
if ( ! arg ) {
    process.stderr.write( 'usage: node canonical.mjs \'{"name":"core/cover",…}\' | @spec.json\n' );
    process.exit( 2 );
}

const raw = arg.startsWith( '@' ) ? fs.readFileSync( arg.slice( 1 ), 'utf8' ) : arg;
const spec = JSON.parse( raw );
const specs = Array.isArray( spec ) ? spec : [ spec ];

for ( const one of specs ) {
    process.stdout.write( serialize( build( one ) ) + '\n\n' );
}
