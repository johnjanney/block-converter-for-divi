<?php
/**
 * Fixture runner for the Divi-to-blocks converter.
 *
 * Usage:
 *   php tests/run.php            # run everything
 *   php tests/run.php -v         # also print the converted markup
 *   php tests/run.php "F-03"     # run fixtures whose name contains "F-03"
 *
 * Exits non-zero when anything fails, so it can be a release gate.
 *
 * @package block-converter-for-divi
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/assertions.php';
require_once ABSPATH . 'includes/class-d2g-parser.php';
require_once ABSPATH . 'includes/class-d2g-style-mapper.php';
require_once ABSPATH . 'includes/class-d2g-converter.php';

$argv    = $_SERVER['argv'];
$verbose = in_array( '-v', $argv, true );
$filter  = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( '-' !== substr( $arg, 0, 1 ) ) {
        $filter = $arg;
    }
}

$fixtures = require __DIR__ . '/fixtures.php';

$passed = 0;
$failed = 0;

foreach ( $fixtures as $name => $fixture ) {
    if ( '' !== $filter && false === stripos( $name, $filter ) ) {
        continue;
    }

    $converter = new D2G_Converter();
    $output    = $converter->convert( $fixture['divi'] );
    $warnings  = $converter->get_warnings();
    $errors    = [];

    if ( ! empty( $fixture['unchanged'] ) ) {
        // Content the detector must not claim: convert() has to hand it back
        // byte for byte, because the convert endpoint refuses to save anything
        // this returns unchanged.
        if ( $output !== $fixture['divi'] ) {
            $errors[] = 'content was modified but should have been left alone';
        }
    } else {
        $errors = d2g_check_block_markup( $output );
    }

    foreach ( $fixture['expect'] ?? [] as $needle ) {
        if ( false === strpos( $output, $needle ) ) {
            $errors[] = 'missing expected markup: ' . $needle;
        }
    }

    foreach ( $fixture['reject'] ?? [] as $needle ) {
        if ( false !== strpos( $output, $needle ) ) {
            $errors[] = 'output contains forbidden markup: ' . $needle;
        }
    }

    $warned = array_column( $warnings, 'module' );
    foreach ( $fixture['warns'] ?? [] as $module ) {
        if ( ! in_array( $module, $warned, true ) ) {
            $errors[] = 'expected a conversion warning for ' . $module;
        }
    }

    if ( $errors ) {
        $failed++;
        echo "FAIL  $name\n";
        foreach ( $errors as $error ) {
            echo "        - $error\n";
        }
        echo "      --- output ---\n" . rtrim( preg_replace( '/^/m', '      ', $output ) ) . "\n\n";
        continue;
    }

    $passed++;
    echo "ok    $name\n";
    if ( $verbose ) {
        echo rtrim( preg_replace( '/^/m', '      ', $output ) ) . "\n\n";
    }
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

echo "\n";
printf( "%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
