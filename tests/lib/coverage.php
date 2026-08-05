<?php
/**
 * Prove every supported Divi module is actually exercised by a fixture.
 *
 * A module with no fixture is a module a refactor can delete, break, or quietly
 * change the output of, with the suite staying green the whole time. When this
 * check was written, 33 of the 58 tags in D2G_Parser::known_tags() had never
 * been executed by any test — including Gallery, Video, Blurb, CTA, Team
 * Member, Audio, Map, and every fullwidth variant.
 *
 * The check is deliberately mechanical: it reads the tag list from the parser
 * rather than from a second list kept here, so adding a module to the parser
 * and forgetting to test it fails the suite instead of passing silently.
 *
 * @package block-converter-for-divi
 */

/**
 * @param array $fixtures The fixture definitions.
 * @return string[] Human-readable failures; empty means full coverage.
 */
function d2g_check_module_coverage( array $fixtures ): array {
    $exercised = [];

    foreach ( $fixtures as $fixture ) {
        foreach ( D2G_Parser::found_tags( $fixture['divi'] ) as $tag ) {
            $exercised[ $tag ] = true;
        }
    }

    $missing = array_values( array_diff( D2G_Parser::known_tags(), array_keys( $exercised ) ) );

    if ( ! $missing ) {
        return [];
    }

    return [
        sprintf(
            "%d supported module(s) have no fixture, so a refactor could break them undetected:\n          %s",
            count( $missing ),
            implode( "\n          ", $missing )
        ),
    ];
}

/**
 * Count how many of the supported tags a fixture set reaches.
 *
 * @return array{covered: int, total: int}
 */
function d2g_module_coverage_summary( array $fixtures ): array {
    $exercised = [];
    foreach ( $fixtures as $fixture ) {
        foreach ( D2G_Parser::found_tags( $fixture['divi'] ) as $tag ) {
            $exercised[ $tag ] = true;
        }
    }

    $known = D2G_Parser::known_tags();

    return [
        'covered' => count( array_intersect( $known, array_keys( $exercised ) ) ),
        'total'   => count( $known ),
    ];
}

/**
 * Check the renderer registry, and snapshot the whole dispatch table.
 *
 * Three things go wrong when a converter is split across renderer classes, and
 * none of them show up in output assertions:
 *
 *   1. Two renderers claim the same tag, so which one wins depends on
 *      registration order.
 *   2. A renderer declares a tag it has no method for, so the module silently
 *      renders as nothing.
 *   3. A tag quietly stops being dispatched at all and starts falling through
 *      to the unknown-module path — which still preserves content, so the
 *      output stays plausible and the module's real conversion is gone.
 *
 * The first two are assertions. The third is a snapshot: the full tag => owner
 * map is written to tests/golden/, so any change to who handles what appears as
 * a reviewable diff rather than as a silent behaviour change.
 *
 * @return string[]
 */
function d2g_check_dispatch_table( bool $update ): array {
    $errors = [];
    $owners = [];

    foreach ( D2G_Converter::renderer_classes() as $class ) {
        if ( ! class_exists( $class ) ) {
            $errors[] = sprintf( 'renderer class %s is registered but does not exist', $class );
            continue;
        }

        foreach ( $class::tags() as $tag => $method ) {
            if ( isset( $owners[ $tag ] ) ) {
                $errors[] = sprintf(
                    'the tag %s is claimed by both %s and %s',
                    $tag,
                    $owners[ $tag ],
                    $class
                );
                continue;
            }

            if ( ! method_exists( $class, $method ) ) {
                $errors[] = sprintf( '%s declares %s but has no %s() method', $class, $tag, $method );
                continue;
            }

            $owners[ $tag ] = $class . '::' . $method . '()';
        }
    }

    ksort( $owners );

    $lines = [];
    foreach ( $owners as $tag => $owner ) {
        $lines[] = sprintf( '%-38s %s', $tag, $owner );
    }

    // Tags the parser knows but nothing dispatches. These are legitimate — a
    // contact field, a map pin and a video slide are consumed by their parent
    // module — but the list belongs in the snapshot, because a tag joining it
    // by accident is the failure this exists to catch.
    $unhandled = array_values( array_diff( D2G_Parser::known_tags(), array_keys( $owners ) ) );
    sort( $unhandled );

    $snapshot = implode( "\n", $lines ) . "\n\n"
        . "===== CONSUMED BY A PARENT MODULE, NOT DISPATCHED =====\n"
        . implode( "\n", $unhandled ) . "\n";

    $errors = array_merge( $errors, d2g_check_golden( 'dispatch table', $snapshot, [], $update ) );

    return $errors;
}
