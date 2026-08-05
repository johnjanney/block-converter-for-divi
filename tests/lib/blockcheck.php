<?php
/**
 * Bridge from the PHP suite to WordPress's real block validator.
 *
 * The validator itself is JavaScript, because block save() functions are
 * JavaScript — see tests/js/validate.mjs. This hands it every fixture's output
 * in one batch (registering 113 core blocks takes a couple of seconds, so
 * paying that once matters) and folds its verdicts back into the PHP results.
 *
 * When the harness is not installed the suite says so, loudly, and keeps going.
 * It never silently reports a pass it did not make: `--require-validator` turns
 * a missing harness into a failure, and both CI and bin/build-zip.sh pass it, so
 * a release cannot be cut without real validation having run.
 *
 * @package block-converter-for-divi
 */

/**
 * The oldest Node this harness runs on.
 *
 * Set by jsdom, which the validator needs for a DOM: jsdom 30 declares
 * `^22.22.2 || ^24.15.0 || >=26.0.0`, and its undici dependency throws on
 * anything older. npm treats an engine mismatch as a warning and installs
 * anyway, so without this check the first sign of trouble is a stack trace from
 * a transitive dependency — which is exactly how it was found.
 */
const D2G_MIN_NODE_MAJOR = 22;

/**
 * Is the Node harness installed and usable?
 *
 * @return array{ok: bool, reason: string}
 */
function d2g_validator_status(): array {
    $dir = dirname( __DIR__ ) . '/js';

    if ( ! is_dir( $dir . '/node_modules' ) ) {
        return [
            'ok'     => false,
            'reason' => 'dependencies are not installed — run: npm --prefix tests/js ci',
        ];
    }

    $node = d2g_find_node();
    if ( '' === $node ) {
        return [ 'ok' => false, 'reason' => 'node was not found on PATH' ];
    }

    $version = trim( (string) @shell_exec( escapeshellcmd( $node ) . ' --version 2>/dev/null' ) );
    if ( preg_match( '/^v(\d+)/', $version, $m ) && (int) $m[1] < D2G_MIN_NODE_MAJOR ) {
        return [
            'ok'     => false,
            'reason' => sprintf(
                'node %s is too old — the validator needs Node %d or newer (jsdom requirement)',
                $version,
                D2G_MIN_NODE_MAJOR
            ),
        ];
    }

    return [ 'ok' => true, 'reason' => '' ];
}

function d2g_find_node(): string {
    $which = @shell_exec( 'command -v node 2>/dev/null' );
    return is_string( $which ) ? trim( $which ) : '';
}

/**
 * Run every fixture's markup through core's parser and validator.
 *
 * @param array<string, string> $outputs Fixture name => converted markup.
 * @return array{ran: bool, reason: string, problems: array<string, string[]>, blocks: int}
 */
function d2g_validate_blocks( array $outputs ): array {
    $status = d2g_validator_status();
    if ( ! $status['ok'] ) {
        return [ 'ran' => false, 'reason' => $status['reason'], 'problems' => [], 'blocks' => 0 ];
    }

    $dir  = dirname( __DIR__ ) . '/js';
    $file = tempnam( sys_get_temp_dir(), 'd2g-blocks-' );

    file_put_contents( $file, (string) wp_json_encode( $outputs ) );

    // stderr goes to a file rather than /dev/null. Discarding it meant that when
    // the validator failed, the suite reported "no usable report" and threw away
    // the only thing that would have explained it — which is how a Node version
    // mismatch survived long enough to reach CI.
    $err_file = tempnam( sys_get_temp_dir(), 'd2g-blocks-err-' );

    $command = escapeshellcmd( d2g_find_node() )
        . ' ' . escapeshellarg( $dir . '/validate.mjs' )
        . ' ' . escapeshellarg( $file )
        . ' --json 2>' . escapeshellarg( $err_file );

    $raw    = shell_exec( $command );
    $stderr = trim( (string) file_get_contents( $err_file ) );

    unlink( $file );
    unlink( $err_file );

    $report = json_decode( (string) $raw, true );

    if ( ! is_array( $report ) || ! isset( $report['results'] ) ) {
        // Keep the first few lines: the top of a Node stack trace names the
        // module that failed, which is the part worth reading.
        $detail = '';
        if ( '' !== $stderr ) {
            $head   = array_slice( explode( "\n", $stderr ), 0, 4 );
            $detail = "\n            " . implode( "\n            ", $head );
        }

        return [
            'ran'      => false,
            'reason'   => 'the validator failed to produce a report'
                . ( '' !== $detail ? $detail : ' and wrote nothing to stderr' ),
            'problems' => [],
            'blocks'   => 0,
        ];
    }

    $problems = [];
    $blocks   = 0;

    foreach ( $report['results'] as $result ) {
        $blocks += (int) ( $result['blocks'] ?? 0 );
        foreach ( $result['problems'] ?? [] as $problem ) {
            $problems[ $result['name'] ][] = sprintf(
                "WordPress rejects this block: %s\n            %s",
                $problem['path'],
                $problem['reason']
            );
        }
    }

    return [ 'ran' => true, 'reason' => '', 'problems' => $problems, 'blocks' => $blocks ];
}
