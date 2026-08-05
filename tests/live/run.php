<?php
/**
 * The live suite: every claim that needs a real WordPress to test.
 *
 * `tests/run.php` runs the converter against a shim. It proves the markup is
 * right. It cannot prove any of the following, because none of it is the
 * converter:
 *
 *   - that the AJAX endpoints enforce what they claim to enforce;
 *   - that wp_update_post() stores converted content unchanged — slashing and
 *     KSES both sit between the converter's output and the database, and
 *     backslash loss through that path was the worst defect in 2.0.0;
 *   - that a restore returns the original bytes;
 *   - that WordPress's own parser accepts what came back out of the database,
 *     which is not the same as accepting what the converter produced.
 *
 * Run it with `bash bin/live-check.sh`, which brings the environment up first.
 * Directly:
 *
 *   npx wp-env run cli wp eval-file \
 *     wp-content/plugins/block-converter-for-divi/tests/live/run.php
 *
 * Exits non-zero on failure.
 *
 * @package block-converter-for-divi
 */

// Counters live in $GLOBALS explicitly. `wp eval-file` evaluates this inside a
// function, so file-scope variables here are *not* globals — a plain
// `global $d2g_pass` in the helper below bound to a different, always-zero
// variable, and the run reported "0 passed, 0 failed" while every check passed.
// A test runner that cannot count is a test runner that cannot fail.
// On a WordPress below the declared minimum, WordPress itself refuses to
// activate the plugin — which is the protection `Requires at least` exists to
// provide. Say so, rather than letting the first call to a plugin class die
// with "Class Block_Converter_For_Divi does not exist" and leaving whoever
// reads the log to work out that this is the correct outcome.
if ( ! class_exists( 'D2G_Converter' ) || ! class_exists( 'Block_Converter_For_Divi' ) ) {
    printf(
        "SKIP  the plugin is not active on WordPress %s\n"
        . "      WordPress declines to activate it below the version declared in\n"
        . "      'Requires at least', so there is nothing here to test. That is the\n"
        . "      expected result for an unsupported version, not a defect.\n",
        get_bloginfo( 'version' )
    );
    exit( 1 );
}

$GLOBALS['d2g_pass'] = 0;
$GLOBALS['d2g_fail'] = 0;

function d2g_ok( string $label, bool $condition, string $detail = '' ) {
    if ( $condition ) {
        $GLOBALS['d2g_pass']++;
        echo "ok    $label\n";
        return;
    }
    $GLOBALS['d2g_fail']++;
    echo "FAIL  $label\n";
    if ( '' !== $detail ) {
        echo "        $detail\n";
    }
}

// wp_send_json_*() ends the request with wp_die(), and wp_die() only routes
// through the catchable AJAX handler when wp_doing_ajax() is true. Under
// WP-CLI it is not, so without this the first endpoint call would kill the run.
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
    return function () {
        throw new Exception( '__ajax_done__' );
    };
} );

/**
 * Call an AJAX endpoint the way the browser does, and decode its reply.
 */
function d2g_call( string $action, array $params, bool $valid_nonce = true ) {
    $_POST          = $params;
    $_POST['nonce'] = $valid_nonce ? wp_create_nonce( 'd2g_nonce' ) : 'not-a-nonce';
    $_REQUEST       = $_POST;

    ob_start();
    try {
        do_action( 'wp_ajax_' . $action );
    } catch ( Exception $e ) {
        // Expected: the endpoint finished by dying.
    }

    return json_decode( (string) ob_get_clean(), true );
}

function d2g_make_post( string $divi, string $title = 'live test' ): int {
    $id = wp_insert_post( [
        'post_title'   => $title,
        'post_content' => wp_slash( $divi ),
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ] );
    update_post_meta( $id, '_et_pb_use_builder', 'on' );
    return (int) $id;
}

$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
$admin  = $admins[0];
wp_set_current_user( $admin->ID );

printf( "WordPress %s, plugin %s, PHP %s\n\n", get_bloginfo( 'version' ), D2G_VERSION, PHP_VERSION );

// ------------------------------------------------------- endpoint contract --

$post_id = d2g_make_post( '[et_pb_text]<p>Contract</p>[/et_pb_text]' );
$preview = d2g_call( 'd2g_preview_conversion', [ 'post_id' => $post_id ] );

d2g_ok( 'preview returns converted markup and a source token',
    ! empty( $preview['success'] ) && ! empty( $preview['data']['source_hash'] ) );

$bad_nonce = d2g_call( 'd2g_preview_conversion', [ 'post_id' => $post_id ], false );
d2g_ok( 'a bad nonce is rejected', null === $bad_nonce || empty( $bad_nonce['success'] ) );

$no_token = d2g_call( 'd2g_convert_page', [ 'post_id' => $post_id, 'backup' => 'yes' ] );
d2g_ok( 'conversion without a source token is refused',
    isset( $no_token['success'] ) && false === $no_token['success'] );

$stale = d2g_call( 'd2g_convert_page', [
    'post_id' => $post_id, 'backup' => 'yes', 'source_hash' => md5( 'stale' ),
] );
d2g_ok( 'conversion with a stale source token is refused',
    isset( $stale['success'] ) && false === $stale['success'] );

$missing = d2g_call( 'd2g_convert_page', [
    'post_id' => 999999, 'backup' => 'yes', 'source_hash' => md5( '' ),
] );
d2g_ok( 'conversion of a non-existent post is refused',
    isset( $missing['success'] ) && false === $missing['success'] );

// A non-administrator must not be able to convert, even with a valid nonce.
$editor_id = wp_insert_user( [
    'user_login' => 'd2g-editor-' . wp_rand( 1000, 9999 ),
    'user_pass'  => wp_generate_password(),
    'role'       => 'editor',
] );
wp_set_current_user( $editor_id );
$as_editor = d2g_call( 'd2g_convert_page', [
    'post_id' => $post_id, 'backup' => 'yes', 'source_hash' => $preview['data']['source_hash'],
] );
d2g_ok( 'an editor cannot convert (manage_options is required)',
    isset( $as_editor['success'] ) && false === $as_editor['success'] );
wp_set_current_user( $admin->ID );

// The lock: a second conversion of the same post, while one holds the lock,
// must be refused rather than both writing.
$locked = ( function () use ( $post_id ) {
    $ref    = new ReflectionMethod( 'Block_Converter_For_Divi', 'acquire_lock' );
    $ref->setAccessible( true );
    $token  = $ref->invoke( null, $post_id );
    $second = $ref->invoke( null, $post_id );
    $rel    = new ReflectionMethod( 'Block_Converter_For_Divi', 'release_lock' );
    $rel->setAccessible( true );
    $rel->invoke( null, $post_id, $token );
    return [ (bool) $token, (bool) $second ];
} )();
d2g_ok( 'the write lock is exclusive', $locked[0] && ! $locked[1],
    sprintf( 'first=%s second=%s', var_export( $locked[0], true ), var_export( $locked[1], true ) ) );

wp_delete_post( $post_id, true );

// ------------------------------------------------------- KSES / multisite --
//
// wp_update_post() filters post_content through KSES for any user without
// `unfiltered_html`. Single-site admins have it; on multisite only super admins
// do, so a site administrator — who has manage_options and can reach this
// plugin — writes through the filter.
//
// Measured on 7.0.2: a Divi Code module holding a script converts to a
// core/html block, and KSES stores the JavaScript as visible text with the
// <script> tags removed and any <iframe> deleted. The plugin must refuse.
//
// The capability is removed with a filter rather than by building a network,
// because KSES keys off exactly this check — the condition is identical.

$deny_unfiltered = static function ( $caps, $cap ) {
    if ( 'unfiltered_html' === $cap ) {
        return [ 'do_not_allow' ];
    }
    return $caps;
};

add_filter( 'map_meta_cap', static function ( $caps, $cap ) use ( $deny_unfiltered ) {
    return $deny_unfiltered( $caps, $cap );
}, 10, 2 );

d2g_ok( 'the test really did remove unfiltered_html', ! current_user_can( 'unfiltered_html' ) );

$dangerous = '[et_pb_code]<script>window.track=1;</script><iframe src="https://maps.example.com/x"></iframe>[/et_pb_code]';
$id        = d2g_make_post( $dangerous, 'kses: code module' );
$before    = get_post( $id )->post_content;

$refused = d2g_call( 'd2g_convert_page', [
    'post_id' => $id, 'backup' => 'yes', 'source_hash' => md5( $before ),
] );

d2g_ok( 'a conversion that KSES would strip is refused',
    isset( $refused['success'] ) && false === $refused['success'],
    'response: ' . wp_json_encode( $refused ) );

d2g_ok( 'the refusal names what would have been removed',
    isset( $refused['data'] ) && false !== strpos( (string) $refused['data'], 'script' ),
    'message: ' . ( $refused['data'] ?? '(none)' ) );

d2g_ok( 'the page is left exactly as it was', get_post( $id )->post_content === $before );

// An ordinary page must still convert: refusing everything on multisite would
// be its own kind of broken.
$safe_id   = d2g_make_post( '[et_pb_text]<p>Ordinary</p>[/et_pb_text]', 'kses: ordinary' );
$safe_hash = md5( get_post( $safe_id )->post_content );
$allowed   = d2g_call( 'd2g_convert_page', [
    'post_id' => $safe_id, 'backup' => 'yes', 'source_hash' => $safe_hash,
] );

d2g_ok( 'an ordinary page still converts without unfiltered_html',
    ! empty( $allowed['success'] ),
    'response: ' . wp_json_encode( $allowed ) );

wp_delete_post( $id, true );
wp_delete_post( $safe_id, true );

remove_all_filters( 'map_meta_cap' );
d2g_ok( 'unfiltered_html is restored for the rest of the run', current_user_can( 'unfiltered_html' ) );

// -------------------------------------------- every fixture, through the DB --
//
// The converter's output is already checked offline. What this adds is the
// round trip: wp_update_post() slashes and KSES-filters on the way in, and a
// difference here means the database holds something other than what was
// converted — which is invisible to every offline test.

$fixtures = require dirname( __DIR__ ) . '/fixtures.php';
$GLOBALS['d2g_emitted_blocks'] = [];
$mismatch = [];
$invalid  = [];
$checked  = 0;

foreach ( $fixtures as $name => $fixture ) {
    if ( ! empty( $fixture['unchanged'] ) ) {
        continue; // The endpoint refuses these by design.
    }

    $GLOBALS['d2g_test_unregistered'] = [];

    $expected = ( new D2G_Converter() )->convert( $fixture['divi'] );
    if ( '' === trim( $expected ) ) {
        continue; // Nothing to save; the endpoint refuses empty output.
    }

    $id      = d2g_make_post( $fixture['divi'], $name );
    $hash    = md5( get_post( $id )->post_content );
    $result  = d2g_call( 'd2g_convert_page', [
        'post_id' => $id, 'backup' => 'yes', 'source_hash' => $hash,
    ] );

    if ( empty( $result['success'] ) ) {
        $mismatch[] = $name . ' — endpoint refused: ' . ( $result['data'] ?? '?' );
        wp_delete_post( $id, true );
        continue;
    }

    $stored = get_post( $id )->post_content;
    $checked++;

    if ( $stored !== $expected ) {
        $mismatch[] = $name;
    }

    // What WordPress parses back out of the database, not what we handed it.
    $collect = function ( array $blocks ) use ( &$collect, $name, &$invalid ) {
        foreach ( $blocks as $block ) {
            if ( null === $block['blockName'] ) {
                if ( '' !== trim( $block['innerHTML'] ) ) {
                    $invalid[] = $name . ' — content outside any block';
                }
                continue;
            }
            $GLOBALS['d2g_emitted_blocks'][] = $block['blockName'];
            if ( ! empty( $block['innerBlocks'] ) ) {
                $collect( $block['innerBlocks'] );
            }
        }
    };
    $collect( parse_blocks( $stored ) );

    // Restore has to return the original bytes.
    $restore  = d2g_call( 'd2g_restore_page', [ 'post_id' => $id ] );
    $restored = get_post( $id )->post_content;
    if ( empty( $restore['success'] ) || $restored !== $fixture['divi'] ) {
        $mismatch[] = $name . ' — restore was not byte-identical';
    }

    wp_delete_post( $id, true );
}

d2g_ok( sprintf( 'all %d fixtures survive the database round trip unchanged', $checked ),
    empty( $mismatch ), implode( "\n        ", array_slice( $mismatch, 0, 10 ) ) );

d2g_ok( 'no fixture leaves content outside a block once re-read',
    empty( $invalid ), implode( "\n        ", array_slice( $invalid, 0, 10 ) ) );

// -------------------------------------- every emitted block exists *here* --
//
// This is the check that gives "Requires at least" a meaning. A block the
// converter emits that this WordPress does not register shows the user "your
// site doesn't include support for this block" where their content used to be.
// It cannot be tested offline at all: the fixture suite assumes a current
// install, and core's own validator only knows the block library npm ships.
//
// $emitted is collected above from content read back out of the database.

$registry   = WP_Block_Type_Registry::get_instance();
$unsupported = [];
foreach ( array_unique( $GLOBALS['d2g_emitted_blocks'] ) as $block_name ) {
    if ( ! $registry->is_registered( $block_name ) ) {
        $unsupported[] = $block_name;
    }
}
sort( $unsupported );

d2g_ok(
    sprintf(
        'every one of the %d distinct blocks emitted is registered on WordPress %s',
        count( array_unique( $GLOBALS['d2g_emitted_blocks'] ) ),
        get_bloginfo( 'version' )
    ),
    empty( $unsupported ),
    'not registered here: ' . implode( ', ', $unsupported )
);

// ----------------------------------------------------- hand the JS the truth --
// Write what the database actually holds, so the block validator checks stored
// content rather than in-memory output.

$sample = [];
foreach ( $fixtures as $name => $fixture ) {
    if ( ! empty( $fixture['unchanged'] ) ) {
        continue;
    }
    $GLOBALS['d2g_test_unregistered'] = [];
    $expected = ( new D2G_Converter() )->convert( $fixture['divi'] );
    if ( '' === trim( $expected ) ) {
        continue;
    }
    $id = d2g_make_post( $fixture['divi'], $name );
    $result = d2g_call( 'd2g_convert_page', [
        'post_id' => $id, 'backup' => 'no', 'source_hash' => md5( get_post( $id )->post_content ),
    ] );
    if ( ! empty( $result['success'] ) ) {
        $sample[ $name ] = get_post( $id )->post_content;
    }
    wp_delete_post( $id, true );
}

$out = dirname( __DIR__ ) . '/live/stored-output.json';
file_put_contents( $out, wp_json_encode( $sample ) );
printf( "\nwrote %d stored conversions to tests/live/stored-output.json\n", count( $sample ) );

printf( "\n%d passed, %d failed\n", $GLOBALS['d2g_pass'], $GLOBALS['d2g_fail'] );

if ( $GLOBALS['d2g_fail'] > 0 ) {
    exit( 1 );
}
