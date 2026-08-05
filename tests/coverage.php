<?php
/**
 * Line-coverage report for the parser and converter.
 *
 * Not part of `tests/run.php` and not run in CI — it needs Xdebug, which is not
 * installed here and is not worth requiring for a routine test run. It exists
 * because module coverage and branch coverage are different things: the suite
 * can execute every Divi module and still never reach the Fullwidth Header's
 * button path, the pre-6.3 Details degradation, or the `<pre>` branch of the
 * HTML splitter. Measuring is what turned those into fixtures.
 *
 * Run it in a container so no local PHP install has to be modified:
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.1-cli \
 *     sh -c 'pecl install xdebug >/dev/null 2>&1 && docker-php-ext-enable xdebug \
 *            && XDEBUG_MODE=coverage php /app/tests/coverage.php'
 *
 * Anything it reports as unexecuted is either a branch that wants a fixture or
 * a deliberate gap worth writing down. Both are useful; neither is automatic.
 *
 * @package block-converter-for-divi
 */

if ( ! function_exists( 'xdebug_start_code_coverage' ) ) {
    fwrite( STDERR, "Xdebug is not loaded. See the header of this file for how to run it.\n" );
    exit( 2 );
}

xdebug_start_code_coverage( XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE );

require_once __DIR__ . '/bootstrap.php';
// One list, shared with the plugin, so the suite cannot drift from what ships.
require_once ABSPATH . 'includes/load.php';

$fixtures = require __DIR__ . '/fixtures.php';

foreach ( $fixtures as $fixture ) {
    // Mirror what run.php sets up, or the fixtures that opt into a missing
    // block would be measured on the wrong path.
    $GLOBALS['d2g_test_unregistered'] = $fixture['unregistered'] ?? [];
    ( new D2G_Converter() )->convert( $fixture['divi'] );
}

// The detection helpers are exercised by run.php separately.
$detection = [
    '[et_pb_text a="x"]a[/et_pb_text]',
    '[et_pb_image /]',
    'text [et_pb_ more',
    'see [et_pb_text for details',
    '[et_pb_nonesuch]',
    '<p>hi</p>',
    '[ET_PB_TEXT]',
    '[et_pb_text title="a[0]"]x[/et_pb_text]',
    '[/et_pb_text]',
    '[/et_pb_text][et_pb_text]x[/et_pb_text]',
    '[et_pb_text-custom]',
    '[et_pb_text [et_pb_image /]',
];

foreach ( $detection as $case ) {
    D2G_Parser::has_divi_content( $case );
    D2G_Parser::found_tags( $case );
    D2G_Parser::strip_divi_tags( $case );
}
D2G_Parser::is_known_tag( 'et_pb_text' );
D2G_Parser::known_tags();

$coverage = xdebug_get_code_coverage();

// Discovered rather than listed, so a new renderer is measured the day it is
// added instead of the day someone remembers to add it here.
$files = array_merge(
    glob( ABSPATH . 'includes/*.php' ) ?: [],
    glob( ABSPATH . 'includes/renderers/*.php' ) ?: []
);
sort( $files );

$verbose        = in_array( '-v', $_SERVER['argv'], true );
$grand_executed = 0;
$grand_total    = 0;

foreach ( $files as $file ) {
    if ( ! isset( $coverage[ $file ] ) ) {
        continue;
    }

    $source     = file( $file );
    $executed   = 0;
    $unexecuted = [];

    foreach ( $coverage[ $file ] as $line => $state ) {
        if ( 1 === $state ) {
            $executed++;
        } elseif ( -1 === $state ) {
            $unexecuted[ $line ] = rtrim( $source[ $line - 1 ] ?? '' );
        }
    }

    $total = $executed + count( $unexecuted );

    $grand_executed += $executed;
    $grand_total    += $total;

    printf(
        "\n%s: %d/%d lines executed (%.1f%%)\n",
        basename( $file ),
        $executed,
        $total,
        $total ? 100 * $executed / $total : 100
    );

    if ( $verbose ) {
        foreach ( $unexecuted as $line => $text ) {
            printf( "  %5d  %s\n", $line, substr( trim( $text ), 0, 95 ) );
        }
    }
}

printf(
    "\n%s\nTOTAL: %d/%d lines executed (%.1f%%)\n",
    str_repeat( '-', 52 ),
    $grand_executed,
    $grand_total,
    $grand_total ? 100 * $grand_executed / $grand_total : 100
);

if ( ! $verbose ) {
    echo "\nPass -v to list the unexecuted lines.\n";
}
