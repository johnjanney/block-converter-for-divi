/**
 * Validate converted markup with WordPress's own block parser and validator.
 *
 * This is the check the project could not make before, and the reason
 * "no block validation errors" was an unproven claim for four releases.
 *
 * Everything in tests/lib/assertions.php is a static string check: fast, and
 * structurally blind. It cannot answer the question that actually matters,
 * which is what happens when WordPress parses the saved markup, re-runs each
 * block's own save() over the parsed attributes, and compares the two. That
 * comparison is what produces "this block contains unexpected or invalid
 * content" in a real editor, and it lives in JavaScript, in @wordpress/blocks.
 *
 * So this runs the real thing. registerCoreBlocks() registers the same block
 * definitions the editor uses, parse() runs the real grammar and the real
 * validator, and every block it returns carries isValid and validationIssues
 * straight from core. Nothing here reimplements a rule.
 *
 * The two layers are complementary and neither replaces the other:
 *
 *   - Core is authoritative about what the editor will accept. It is also more
 *     permissive than this project wants to be: a block that only validates
 *     through a deprecation is accepted here, and still means the converter
 *     emitted markup a current WordPress would not have written.
 *   - assertions.php is a stricter house style. It can flag what core
 *     tolerates, and it runs with no node_modules.
 *
 * Usage:
 *   node validate.mjs <cases.json> [--json]
 *
 * Input is { fixtureName: blockMarkup }. Exit code 0 when everything validates.
 */

import fs from 'node:fs';
import { createRequire } from 'node:module';
import { JSDOM, VirtualConsole } from 'jsdom';

// ---------------------------------------------------------------- DOM setup --
// The block library is editor code: save functions build elements, and some
// modules touch window/document as they load. jsdom has to be in place first.

const virtualConsole = new VirtualConsole();
virtualConsole.on( 'jsdomError', ( error ) => {
    // The block library ships CSS jsdom cannot parse. That is noise about
    // styles, not about block validity. Anything else is forwarded.
    if ( ! /Could not parse CSS/.test( error.message ) ) {
        process.stderr.write( `jsdom: ${ error.message }\n` );
    }
} );

const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
    url: 'https://example.test/',
    pretendToBeVisual: true,
    virtualConsole,
} );

globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.self = dom.window;

// Node 21+ makes globalThis.navigator getter-only, so it needs redefining.
Object.defineProperty( globalThis, 'navigator', {
    value: dom.window.navigator,
    configurable: true,
    writable: true,
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

// The ESM builds import .json with no import attribute, which Node refuses. The
// CommonJS builds are the same code and load cleanly.
const require = createRequire( import.meta.url );

const { parse, getBlockTypes } = require( '@wordpress/blocks' );
const { registerCoreBlocks } = require( '@wordpress/block-library' );

registerCoreBlocks();

const registered = new Set( getBlockTypes().map( ( type ) => type.name ) );
const libraryVersion = require( '@wordpress/block-library/package.json' ).version;

// ------------------------------------------------------------ issue capture --

/**
 * Render one of core's validation log entries as a single readable line.
 *
 * Core logs these printf-style, and one argument is the entire block type
 * definition — hundreds of lines of React elements and deprecations.
 * Substituting `%o` with a placeholder keeps the part a human needs (which
 * token disagreed, and the two pieces of markup) and drops the part nobody
 * reads.
 */
function describeIssue( issue ) {
    if ( typeof issue === 'string' ) {
        return issue;
    }
    if ( ! issue || ! Array.isArray( issue.args ) || ! issue.args.length ) {
        return JSON.stringify( issue );
    }

    let index = 0;
    const rendered = String( issue.args[ 0 ] ).replace( /%[so]/g, ( token ) => {
        index += 1;
        return token === '%o' ? '…' : String( issue.args[ index ] );
    } );

    return rendered.replace( /\s+/g, ' ' ).trim();
}

/**
 * Silence core's own console output while parsing.
 *
 * @wordpress/blocks writes every validation issue to console.error as it goes.
 * The issues are also attached to the block, which is where this reads them
 * from, so letting them through would print each failure twice — once as an
 * unreadable dump and once as a report line.
 */
function quietly( fn ) {
    const saved = {
        log: console.log, warn: console.warn,
        error: console.error, info: console.info,
    };
    console.log = console.warn = console.error = console.info = () => {};
    try {
        return fn();
    } finally {
        Object.assign( console, saved );
    }
}

// ------------------------------------------------------------- block walking --

function flatten( blocks, path = [] ) {
    const out = [];
    blocks.forEach( ( block, index ) => {
        const label = block.name === 'core/missing'
            ? `core/missing(${ block.attributes?.originalName || '?' })`
            : block.name;
        const here = [ ...path, `${ label }[${ index }]` ];
        out.push( { block, path: here.join( ' > ' ) } );
        if ( block.innerBlocks?.length ) {
            out.push( ...flatten( block.innerBlocks, here ) );
        }
    } );
    return out;
}

function validate( name, markup ) {
    const problems = [];
    let blocks;

    try {
        blocks = quietly( () => parse( markup ) );
    } catch ( error ) {
        return {
            name,
            blocks: 0,
            problems: [ { path: '(parse)', reason: `parser threw: ${ error.message }` } ],
        };
    }

    const all = flatten( blocks );

    for ( const { block, path } of all ) {
        // An unregistered block does not come back under its own name — the
        // parser substitutes core/missing and stashes the original in
        // attributes.originalName. Testing block.name alone therefore sees a
        // registered block and waves it through, which is the mistake that let
        // the experimental core/form* blocks ship in 2.0.0.
        if ( block.name === 'core/missing' ) {
            problems.push( {
                path,
                reason: `block "${ block.attributes?.originalName || 'unknown' }" is not registered by WordPress core`,
            } );
            continue;
        }

        // Non-whitespace markup outside any delimiter. WordPress shows it as a
        // Classic block, which is not something a converter should produce.
        if ( block.name === 'core/freeform' ) {
            const content = ( block.attributes?.content || '' ).trim();
            if ( content ) {
                problems.push( {
                    path,
                    reason: `content sits outside any block delimiter: ${ content.slice( 0, 120 ) }`,
                } );
            }
            continue;
        }

        if ( block.isValid === false ) {
            const issues = ( block.validationIssues || [] ).map( describeIssue );
            problems.push( {
                path,
                reason: issues.length ? issues.join( ' | ' ) : 'save() output did not match the saved markup',
            } );
        }
    }

    return { name, blocks: all.length, problems };
}

// ---------------------------------------------------------------------- main --

const args = process.argv.slice( 2 );
const asJson = args.includes( '--json' );
const inputPath = args.find( ( arg ) => ! arg.startsWith( '--' ) );

if ( ! inputPath ) {
    process.stderr.write( 'usage: node validate.mjs <cases.json> [--json]\n' );
    process.exit( 2 );
}

const cases = JSON.parse( fs.readFileSync( inputPath, 'utf8' ) );
const results = Object.entries( cases ).map( ( [ name, markup ] ) => validate( name, markup ) );
const failed = results.filter( ( result ) => result.problems.length );

if ( asJson ) {
    process.stdout.write( JSON.stringify( { ok: ! failed.length, libraryVersion, results }, null, 2 ) );
} else {
    process.stdout.write(
        `Real WordPress block validation (@wordpress/block-library ${ libraryVersion }, ` +
        `${ registered.size } core blocks registered)\n\n`
    );
    for ( const result of failed ) {
        process.stdout.write( `FAIL  ${ result.name }\n` );
        for ( const problem of result.problems ) {
            process.stdout.write( `        ${ problem.path }\n          ${ problem.reason }\n` );
        }
    }
    const blocks = results.reduce( ( total, result ) => total + result.blocks, 0 );
    process.stdout.write(
        `\n${ results.length - failed.length }/${ results.length } fixtures valid, ` +
        `${ blocks } blocks checked\n`
    );
}

process.exit( failed.length ? 1 : 0 );
