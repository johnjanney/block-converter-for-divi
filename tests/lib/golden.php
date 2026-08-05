<?php
/**
 * Byte-exact snapshots of every fixture's converted output.
 *
 * This is the part of the suite that protects a refactor, and it is the only
 * part that can.
 *
 * Assertions test what someone thought to assert. Splitting a 2,300-line
 * converter into module renderer classes does not break the things people
 * thought to assert — it breaks the things nobody did: a lost trailing newline,
 * an attribute emitted in a different order, a class dropped from a wrapper, a
 * warning that stops firing. Every one of those is invisible to `expect` and
 * `reject` lists and visible to a byte comparison.
 *
 * So: every fixture's exact output is committed to tests/golden/. The suite
 * fails on any difference, and prints the difference. A deliberate change is
 * made deliberate by running `php tests/run.php --update-golden`, which
 * rewrites the files and puts the diff in the commit for review.
 *
 * Warnings are snapshotted alongside the markup, because the loss reporting
 * added in 2.2.0 is behaviour a refactor can silently drop.
 *
 * @package block-converter-for-divi
 */

/**
 * Where a fixture's snapshot lives. Names are slugged so they are filesystem
 * safe and sort predictably in a diff.
 */
function d2g_golden_path( string $name ): string {
    $slug = strtolower( $name );
    $slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
    $slug = trim( (string) $slug, '-' );

    return dirname( __DIR__ ) . '/golden/' . $slug . '.txt';
}

/**
 * Render one fixture result into the exact text stored on disk.
 */
function d2g_golden_render( string $markup, array $warnings ): string {
    $out = $markup;

    if ( $warnings ) {
        $lines = [];
        foreach ( $warnings as $warning ) {
            $lines[] = '[' . $warning['module'] . '] ' . $warning['message'];
        }
        // Sorted so the file records *which* warnings fired, not the order the
        // renderers happened to run in — reordering renderers is a legitimate
        // refactor, dropping a warning is not.
        sort( $lines );
        $out .= "\n\n===== WARNINGS =====\n" . implode( "\n", $lines ) . "\n";
    }

    return $out;
}

/**
 * Compare a fixture against its snapshot, or write one if asked.
 *
 * @return string[] Failures; empty means the output is unchanged.
 */
function d2g_check_golden( string $name, string $markup, array $warnings, bool $update ): array {
    $path     = d2g_golden_path( $name );
    $expected = is_file( $path ) ? file_get_contents( $path ) : null;
    $actual   = d2g_golden_render( $markup, $warnings );

    if ( $update ) {
        if ( ! is_dir( dirname( $path ) ) ) {
            mkdir( dirname( $path ), 0777, true );
        }
        if ( $expected !== $actual ) {
            file_put_contents( $path, $actual );
        }
        return [];
    }

    if ( null === $expected ) {
        return [
            sprintf(
                'no snapshot for this fixture. Run `php tests/run.php --update-golden` and commit %s',
                str_replace( dirname( __DIR__, 2 ) . '/', '', $path )
            ),
        ];
    }

    if ( $expected === $actual ) {
        return [];
    }

    return [ "output changed from its snapshot:\n" . d2g_unified_diff( $expected, $actual ) ];
}

/**
 * A small unified diff, so a snapshot failure says what moved rather than
 * printing two walls of markup and leaving the reader to spot the difference.
 */
function d2g_unified_diff( string $expected, string $actual, int $context = 2 ): string {
    $before = explode( "\n", $expected );
    $after  = explode( "\n", $actual );

    // Longest common subsequence over lines. Fixtures are small, so the
    // quadratic table is not a concern and the result is a real diff rather
    // than a first-difference marker.
    $rows = count( $before );
    $cols = count( $after );
    $lcs  = array_fill( 0, $rows + 1, array_fill( 0, $cols + 1, 0 ) );

    for ( $i = $rows - 1; $i >= 0; $i-- ) {
        for ( $j = $cols - 1; $j >= 0; $j-- ) {
            $lcs[ $i ][ $j ] = $before[ $i ] === $after[ $j ]
                ? $lcs[ $i + 1 ][ $j + 1 ] + 1
                : max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
        }
    }

    $ops = [];
    $i   = 0;
    $j   = 0;
    while ( $i < $rows && $j < $cols ) {
        if ( $before[ $i ] === $after[ $j ] ) {
            $ops[] = [ ' ', $before[ $i ] ];
            $i++;
            $j++;
        } elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
            $ops[] = [ '-', $before[ $i ] ];
            $i++;
        } else {
            $ops[] = [ '+', $after[ $j ] ];
            $j++;
        }
    }
    for ( ; $i < $rows; $i++ ) {
        $ops[] = [ '-', $before[ $i ] ];
    }
    for ( ; $j < $cols; $j++ ) {
        $ops[] = [ '+', $after[ $j ] ];
    }

    // Keep only changed hunks plus a little context.
    $keep = [];
    foreach ( $ops as $index => $op ) {
        if ( ' ' !== $op[0] ) {
            for ( $k = max( 0, $index - $context ); $k <= min( count( $ops ) - 1, $index + $context ); $k++ ) {
                $keep[ $k ] = true;
            }
        }
    }
    ksort( $keep );

    $lines = [];
    $last  = -1;
    foreach ( array_keys( $keep ) as $index ) {
        if ( $last >= 0 && $index > $last + 1 ) {
            $lines[] = '          @@';
        }
        $lines[] = '          ' . $ops[ $index ][0] . ' ' . $ops[ $index ][1];
        $last    = $index;
    }

    return implode( "\n", $lines );
}
