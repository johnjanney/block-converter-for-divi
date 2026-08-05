<?php
/**
 * Converter test suite.
 *
 * Five layers, cheapest first. Each one catches a class of defect the layer
 * before it cannot see:
 *
 *   1. expect / reject / warns assertions — what a fixture is specifically for.
 *   2. Structural checks (lib/assertions.php) — a stricter house style than
 *      core enforces, applied to every block at every depth.
 *   3. Golden snapshots (lib/golden.php) — byte-exact output and warning
 *      records. This is the layer that protects a refactor: it catches the
 *      changes nobody thought to assert.
 *   4. Real WordPress block validation (lib/blockcheck.php → js/validate.mjs) —
 *      core's own parser and save() comparison, the thing that decides whether
 *      the editor says "this block contains unexpected or invalid content".
 *   5. Module coverage (lib/coverage.php) — every supported Divi tag has a
 *      fixture, so a refactor cannot break an untested renderer.
 *
 * WHAT THIS STILL DOES NOT COVER, and must not be read as covering:
 *
 *   - The plugin bootstrap, the AJAX endpoints, capability and nonce checks,
 *     the scan SQL, the write lock, backup and restore state, or uninstall.
 *     None of that is loaded here; it needs a WordPress test install.
 *   - The admin JavaScript, including the batch result logic.
 *   - Any WordPress or PHP version other than the one running this file, and
 *     the block library version pinned in tests/js/package-lock.json.
 *   - How a converted page *looks*. Valid is not the same as faithful.
 *
 * Usage:
 *   php tests/run.php                     # everything
 *   php tests/run.php -v                  # also print converted markup
 *   php tests/run.php "N-03"              # fixtures matching a substring
 *   php tests/run.php --update-golden     # accept current output as the snapshot
 *   php tests/run.php --no-blocks         # skip the Node validator
 *   php tests/run.php --require-validator # fail if the Node validator cannot run
 *
 * Exits non-zero when anything fails, so it can gate a release.
 *
 * @package block-converter-for-divi
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/assertions.php';
require_once __DIR__ . '/lib/golden.php';
require_once __DIR__ . '/lib/coverage.php';
require_once __DIR__ . '/lib/blockcheck.php';
// One list, shared with the plugin, so the suite cannot drift from what ships.
require_once ABSPATH . 'includes/load.php';

$argv    = $_SERVER['argv'];
$verbose = in_array( '-v', $argv, true );
$update  = in_array( '--update-golden', $argv, true );
$skip_js = in_array( '--no-blocks', $argv, true );
$need_js = in_array( '--require-validator', $argv, true );

$filter = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( '-' !== substr( $arg, 0, 1 ) ) {
        $filter = $arg;
    }
}

$fixtures = require __DIR__ . '/fixtures.php';

$passed  = 0;
$failed  = 0;
$errors  = [];   // fixture name => string[]
$outputs = [];   // fixture name => converted markup, for the block validator
$ran     = [];   // fixture names actually run, in order

foreach ( $fixtures as $name => $fixture ) {
    if ( '' !== $filter && false === stripos( $name, $filter ) ) {
        continue;
    }

    $ran[ $name ] = true;

    // Lets a fixture ask "what does this do on a WordPress without that block?"
    // See the WP_Block_Type_Registry stub in bootstrap.php.
    $GLOBALS['d2g_test_unregistered'] = $fixture['unregistered'] ?? [];

    $converter = new D2G_Converter();
    $output    = $converter->convert( $fixture['divi'] );
    $warnings  = $converter->get_warnings();
    $problems  = [];

    // ---- Determinism -------------------------------------------------------
    // A refactor that introduces order-dependence — a static cache, a shared
    // buffer that is not reset, an array keyed by something unstable — shows up
    // here and nowhere else.
    $second = ( new D2G_Converter() )->convert( $fixture['divi'] );
    if ( $second !== $output ) {
        $problems[] = 'conversion is not deterministic: two runs produced different output';
    }

    if ( ! empty( $fixture['unchanged'] ) ) {
        // Content the detector must not claim: convert() has to hand it back
        // byte for byte, because the convert endpoint refuses to save anything
        // this returns unchanged.
        if ( $output !== $fixture['divi'] ) {
            $problems[] = 'content was modified but should have been left alone';
        }
    } else {
        $problems = array_merge( $problems, d2g_check_block_markup( $output ) );

        // ---- Idempotence ---------------------------------------------------
        // Converted output holds no Divi content, so converting it again must
        // be a no-op. If it is not, a second pass over an already-converted
        // page would corrupt it — and the convert endpoint's "already
        // converted" guard is built on exactly this property.
        if ( D2G_Parser::has_divi_content( $output ) ) {
            $problems[] = 'converted output still reports as Divi content, so it could be converted twice';
        } elseif ( ( new D2G_Converter() )->convert( $output ) !== $output ) {
            $problems[] = 'converting the converted output changed it; conversion is not idempotent';
        }

        $outputs[ $name ] = $output;
    }

    foreach ( $fixture['expect'] ?? [] as $needle ) {
        if ( false === strpos( $output, $needle ) ) {
            $problems[] = 'missing expected markup: ' . $needle;
        }
    }

    foreach ( $fixture['reject'] ?? [] as $needle ) {
        if ( false !== strpos( $output, $needle ) ) {
            $problems[] = 'output contains forbidden markup: ' . $needle;
        }
    }

    $warned = array_column( $warnings, 'module' );
    foreach ( $fixture['warns'] ?? [] as $module ) {
        if ( ! in_array( $module, $warned, true ) ) {
            $problems[] = 'expected a conversion warning for ' . $module;
        }
    }

    // A loss report that fires for settings the converter did map trains users
    // to ignore it, so the absence of a warning is asserted too.
    foreach ( $fixture['rejectWarnings'] ?? [] as $module ) {
        if ( in_array( $module, $warned, true ) ) {
            $problems[] = 'unexpected conversion warning for ' . $module;
        }
    }

    $problems = array_merge( $problems, d2g_check_golden( $name, $output, $warnings, $update ) );

    if ( $problems ) {
        $errors[ $name ] = $problems;
    }

    if ( $verbose ) {
        echo "----- $name\n" . rtrim( preg_replace( '/^/m', '      ', $output ) ) . "\n\n";
    }
}

// -------------------------------------------------- real block validation --
$validator = [ 'ran' => false, 'reason' => 'skipped with --no-blocks', 'problems' => [], 'blocks' => 0 ];

if ( ! $skip_js && $outputs ) {
    $validator = d2g_validate_blocks( $outputs );
    foreach ( $validator['problems'] as $name => $problems ) {
        $errors[ $name ] = array_merge( $errors[ $name ] ?? [], $problems );
    }
}

// ------------------------------------------------------------- report -------
foreach ( array_keys( $ran ) as $name ) {
    if ( isset( $errors[ $name ] ) ) {
        $failed++;
        echo "FAIL  $name\n";
        foreach ( $errors[ $name ] as $problem ) {
            echo "        - $problem\n";
        }
        echo "\n";
        continue;
    }
    $passed++;
    echo "ok    $name\n";
}

// -------------------------------------------------------------------- parser --
$parser_cases = [
    'known tag with a space'      => [ '[et_pb_text some="x"]a[/et_pb_text]', true ],
    'known tag self-closed'       => [ '[et_pb_image /]', true ],
    'known tag bare'              => [ '[et_pb_section]a[/et_pb_section]', true ],
    'bare prefix only'            => [ 'text [et_pb_ more', false ],
    'prefix with no closing ]'    => [ 'see [et_pb_text for details', false ],
    'unlisted but well-formed'    => [ '[et_pb_nonesuch]', true ],
    'no divi at all'              => [ '<p>hello</p>', false ],
    'uppercase is not a divi tag' => [ '[ET_PB_TEXT]', false ],
    'bracket inside an attribute' => [ '[et_pb_text title="a[0]"]x[/et_pb_text]', true ],
    'closing tag alone'           => [ '[/et_pb_text]', false ],
    'orphan closer before a tag'  => [ '[/et_pb_text][et_pb_text]x[/et_pb_text]', true ],
    'tag name with a suffix'      => [ '[et_pb_text-custom]', false ],
    'unterminated tag then a tag' => [ '[et_pb_text [et_pb_image /]', true ],
];

foreach ( $parser_cases as $label => $case ) {
    list( $input, $expected ) = $case;
    $actual = D2G_Parser::has_divi_content( $input );
    if ( $actual === $expected ) {
        $passed++;
        echo "ok    detection: $label\n";
    } else {
        $failed++;
        printf( "FAIL  detection: %s (expected %s, got %s)\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
    }
}

// ------------------------------------------------------- dispatch table -----
$dispatch_errors = d2g_check_dispatch_table( $update );
if ( $dispatch_errors ) {
    $failed++;
    echo "FAIL  renderer dispatch table\n";
    foreach ( $dispatch_errors as $problem ) {
        echo "        - $problem\n";
    }
} else {
    $passed++;
    echo "ok    renderer dispatch table\n";
}

// ------------------------------------------------------------- coverage -----
// Only meaningful over the whole set, so it is skipped when filtering.
if ( '' === $filter ) {
    $gaps = d2g_check_module_coverage( $fixtures );
    if ( $gaps ) {
        $failed++;
        echo "FAIL  module coverage\n";
        foreach ( $gaps as $gap ) {
            echo "        - $gap\n";
        }
    } else {
        $passed++;
        $summary = d2g_module_coverage_summary( $fixtures );
        printf( "ok    module coverage: %d/%d supported Divi modules exercised\n", $summary['covered'], $summary['total'] );
    }
}

// ------------------------------------------------------------- summary ------
echo "\n";

if ( $update ) {
    echo "Golden snapshots rewritten. Review the diff before committing.\n";
}

if ( $validator['ran'] ) {
    printf( "Real WordPress block validation: %d blocks checked by core's own validator\n", $validator['blocks'] );
} elseif ( $skip_js ) {
    echo "Real WordPress block validation: SKIPPED (--no-blocks)\n";
} else {
    printf( "Real WordPress block validation: NOT RUN — %s\n", $validator['reason'] );
    echo "  Block validity is therefore UNPROVEN for this run.\n";
    if ( $need_js ) {
        $failed++;
        echo "  --require-validator was given, so this counts as a failure.\n";
    }
}

printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
