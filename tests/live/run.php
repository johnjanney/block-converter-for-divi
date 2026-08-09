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

printf( "WordPress %s, plugin %s, PHP %s\n\n", get_bloginfo( 'version' ), BCFD_VERSION, PHP_VERSION );

// ------------------------------------------ the pre-rename plugin conflicts --
//
// Installing 2.x beside the 1.x plugin it was renamed from produced "Plugin
// could not be activated because it triggered a fatal error", and the cause was
// not the shared class names people would guess at. It was the constants: the
// old plugin defines D2G_PLUGIN_DIR first, define() keeps an existing value
// rather than overwriting it, and this plugin then required its bootstrap from
// *the other plugin's directory*.
//
// Both halves are checked here, because a regression in either brings the fatal
// back.

d2g_ok(
    'the runtime constants cannot collide with the pre-rename plugin',
    defined( 'BCFD_PLUGIN_DIR' ) && ! defined( 'D2G_PLUGIN_DIR' ) && ! defined( 'D2G_VERSION' ),
    sprintf(
        'BCFD_PLUGIN_DIR=%s D2G_PLUGIN_DIR=%s',
        defined( 'BCFD_PLUGIN_DIR' ) ? BCFD_PLUGIN_DIR : '(undefined)',
        defined( 'D2G_PLUGIN_DIR' ) ? D2G_PLUGIN_DIR : '(undefined)'
    )
);

d2g_ok(
    'the bootstrap loads from this plugin\'s own directory',
    defined( 'BCFD_PLUGIN_DIR' ) && is_readable( BCFD_PLUGIN_DIR . 'includes/load.php' )
);

// The "not loaded yet" branch of the detector: on an ordinary admin request
// this plugin loads first, so the only evidence available is the option.
$conflict_seen = ( function () {
    $original = get_option( 'active_plugins', [] );
    update_option( 'active_plugins', array_merge( (array) $original, [ 'divi2gutenberg/divi2gutenberg.php' ] ) );
    $seen = function_exists( 'bcfd_legacy_plugin_present' ) ? bcfd_legacy_plugin_present() : null;
    update_option( 'active_plugins', $original );
    return $seen;
} )();

d2g_ok(
    'an active pre-rename plugin is detected before anything is declared',
    true === $conflict_seen,
    'detector returned: ' . var_export( $conflict_seen, true )
);

// ------------------------------------------------------- endpoint contract --

$post_id = d2g_make_post( '[et_pb_text]<p>Contract</p>[/et_pb_text]' );
$preview = d2g_call( 'd2g_preview_conversion', [ 'post_id' => $post_id ] );

d2g_ok( 'preview returns converted markup and a source token',
    ! empty( $preview['success'] ) && ! empty( $preview['data']['source_hash'] ) );

$bad_nonce = d2g_call( 'd2g_preview_conversion', [ 'post_id' => $post_id ], false );
d2g_ok( 'a bad nonce is rejected', null === $bad_nonce || empty( $bad_nonce['success'] ) );

$no_token = d2g_call( 'd2g_convert_page', [ 'post_id' => $post_id ] );
d2g_ok( 'conversion without a source token is refused',
    isset( $no_token['success'] ) && false === $no_token['success'] );

$stale = d2g_call( 'd2g_convert_page', [
    'post_id' => $post_id, 'source_hash' => md5( 'stale' ),
] );
d2g_ok( 'conversion with a stale source token is refused',
    isset( $stale['success'] ) && false === $stale['success'] );

// A stale token is still a refusal, and still writes nothing — but the reply now
// carries the current token, so a batch row is not a dead end. Refusing without
// one meant the only way forward was to abandon the batch and scan again, on a
// page whose content had settled seconds earlier.
$moved_id = d2g_make_post( '[et_pb_text]<p>First</p>[/et_pb_text]', 'zz moved under us' );
$moved_first = md5( get_post( $moved_id )->post_content );

wp_update_post( [ 'ID' => $moved_id, 'post_content' => wp_slash( '[et_pb_text]<p>Second</p>[/et_pb_text]' ) ] );
$moved_now = md5( get_post( $moved_id )->post_content );

$moved = d2g_call( 'd2g_convert_page', [ 'post_id' => $moved_id, 'source_hash' => $moved_first ] );

d2g_ok( 'a stale token is refused', isset( $moved['success'] ) && false === $moved['success'] );
d2g_ok( 'and the refusal wrote nothing',
    false !== strpos( get_post( $moved_id )->post_content, '[et_pb_text]' ) );
d2g_ok( 'the refusal hands back the current token so a batch row can continue',
    ! empty( $moved['data']['stale_token'] ) && ( $moved['data']['source_hash'] ?? '' ) === $moved_now,
    'reply: ' . wp_json_encode( $moved['data'] ?? null ) );

// Coming back with that token converts — and says the page moved, because the
// page that was previewed is not the page that just got converted.
$retried = d2g_call( 'd2g_convert_page', [
    'post_id'         => $moved_id,
    'source_hash'     => $moved_now,
    'superseded_hash' => $moved_first,
] );

d2g_ok( 'the retry converts', ! empty( $retried['success'] ),
    'reply: ' . wp_json_encode( $retried ) );
d2g_ok( 'it converted the version that was actually there',
    false !== strpos( get_post( $moved_id )->post_content, '<p>Second</p>' ) );

$moved_warnings = wp_json_encode( $retried['data']['warnings'] ?? [] );
d2g_ok( 'and the conversion says the page changed after it was scanned',
    false !== strpos( $moved_warnings, 'page-changed' ), $moved_warnings );

wp_delete_post( $moved_id, true );

// A retry that supersedes nothing is an ordinary conversion, and must not be
// labelled as one that moved.
$quiet_id = d2g_make_post( '[et_pb_text]<p>Steady</p>[/et_pb_text]', 'zz not moved' );
$quiet_hash = md5( get_post( $quiet_id )->post_content );
$quiet = d2g_call( 'd2g_convert_page', [
    'post_id'         => $quiet_id,
    'source_hash'     => $quiet_hash,
    'superseded_hash' => $quiet_hash,
] );
d2g_ok( 'a page that did not move is not reported as having moved',
    ! empty( $quiet['success'] )
    && false === strpos( wp_json_encode( $quiet['data']['warnings'] ?? [] ), 'page-changed' ) );
wp_delete_post( $quiet_id, true );

$missing = d2g_call( 'd2g_convert_page', [
    'post_id' => 999999, 'source_hash' => md5( '' ),
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
    'post_id' => $post_id, 'source_hash' => $preview['data']['source_hash'],
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

// ------------------------------------------------------------ the scan query --
//
// The scan is SQL, so nothing in tests/run.php can reach it — and it was the
// one endpoint with no live coverage at all until a real corpus showed what it
// misses. Divi 5 stores a page as `<!-- wp:divi/… -->` blocks rather than
// shortcodes, so `LIKE '%[et_pb_%'` cannot see one: those pages were listed
// nowhere, converted never, and left needing Divi with nothing said about it.

$scan_ids = [
    'shortcode' => d2g_make_post( '[et_pb_text]<p>Shortcode</p>[/et_pb_text]', 'zz scan shortcode' ),
    // A shortcode page carrying a Divi 5 placeholder comment. Convertible, and
    // it must not be counted as a Divi 5 page.
    'hybrid'    => d2g_make_post( '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->[et_pb_text]<p>Hybrid</p>[/et_pb_text]', 'zz scan hybrid' ),
    'divi5'     => d2g_make_post( '<!-- wp:divi/section --><!-- wp:divi/text --><p>Divi 5</p><!-- /wp:divi/text --><!-- /wp:divi/section -->', 'zz scan divi5' ),
];

foreach ( [ 'all', 'post' ] as $scan_filter ) {
    delete_transient( 'd2g_divi5_count_' . md5( wp_json_encode( [ $scan_filter ] ) ) );
    delete_transient( 'd2g_scan_count_' . md5( wp_json_encode( [ $scan_filter ] ) ) );
}

$scan = d2g_call( 'd2g_scan_pages', [ 'per_page' => '100', 'paged' => 1, 'post_type' => 'all' ] );
d2g_ok( 'the scan endpoint answers', ! empty( $scan['success'] ) );

$scan_titles = array_column( $scan['data']['pages'] ?? [], 'title' );
d2g_ok( 'a shortcode page is listed', in_array( 'zz scan shortcode', $scan_titles, true ) );
d2g_ok( 'a page holding both shortcodes and Divi 5 blocks is listed',
    in_array( 'zz scan hybrid', $scan_titles, true ) );
d2g_ok( 'a Divi 5 page is not listed as convertible',
    ! in_array( 'zz scan divi5', $scan_titles, true ) );
d2g_ok( 'a Divi 5 page is counted and reported rather than passed over silently',
    1 === (int) ( $scan['data']['divi5_count'] ?? -1 ),
    sprintf( 'divi5_count=%s', var_export( $scan['data']['divi5_count'] ?? null, true ) ) );

$scan_page2 = d2g_call( 'd2g_scan_pages', [ 'per_page' => '1', 'paged' => 2, 'post_type' => 'all' ] );
d2g_ok( 'the Divi 5 count survives paging on its cache',
    1 === (int) ( $scan_page2['data']['divi5_count'] ?? -1 ) );

$scan_posts = d2g_call( 'd2g_scan_pages', [ 'per_page' => '100', 'paged' => 1, 'post_type' => 'post' ] );
d2g_ok( 'the post-type filter narrows the Divi 5 count too',
    0 === (int) ( $scan_posts['data']['divi5_count'] ?? -1 ) );

foreach ( $scan_ids as $scan_id ) {
    wp_delete_post( $scan_id, true );
}

// ------------------------------------------------------- a stale snapshot --
//
// The write-once rule stops a second conversion replacing the original with
// converted output. It also, until 2.9.2, kept a snapshot that had gone stale
// while the page was still Divi — which is what a refused attempt plus an
// importer still rewriting URLs produces. The page in front of us is Divi and
// is what the author has now, so it is what a restore should give back.

$stale_id = d2g_make_post( '[et_pb_text]<p>Current Divi content</p>[/et_pb_text]', 'zz stale backup' );
update_post_meta( $stale_id, '_d2g_divi_backup', wp_slash( '[et_pb_text]<p>Older Divi content</p>[/et_pb_text]' ) );
update_post_meta( $stale_id, '_d2g_backup_date', '2020-01-01 00:00:00' );

$stale_result = d2g_call( 'd2g_convert_page', [
    'post_id' => $stale_id, 'source_hash' => md5( get_post( $stale_id )->post_content ),
] );

d2g_ok( 'the page still converted', ! empty( $stale_result['success'] ),
    'response: ' . wp_json_encode( $stale_result ) );
d2g_ok( 'a snapshot that had gone stale is refreshed to what was converted',
    false !== strpos( (string) get_post_meta( $stale_id, '_d2g_divi_backup', true ), 'Current Divi content' ) );

wp_delete_post( $stale_id, true );

// And the rule it must not break: a snapshot is never replaced by converted
// output. This is the loss write-once was introduced to prevent.
$guard_id = d2g_make_post( '[et_pb_text]<p>The true original</p>[/et_pb_text]', 'zz snapshot guard' );
$guard_hash = md5( get_post( $guard_id )->post_content );
d2g_call( 'd2g_convert_page', [ 'post_id' => $guard_id, 'source_hash' => $guard_hash ] );

$after_first = (string) get_post_meta( $guard_id, '_d2g_divi_backup', true );
d2g_ok( 'the first conversion snapshotted the original',
    false !== strpos( $after_first, 'The true original' ) );

// Now the post holds blocks. A second conversion is refused outright, and the
// snapshot must survive that untouched.
d2g_call( 'd2g_convert_page', [
    'post_id' => $guard_id, 'source_hash' => md5( get_post( $guard_id )->post_content ),
] );

d2g_ok( 'a converted page cannot overwrite its own snapshot',
    (string) get_post_meta( $guard_id, '_d2g_divi_backup', true ) === $after_first );

wp_delete_post( $guard_id, true );

// ------------------------------------------ converting a conversion again --
//
// A conversion that leaves a shortcode behind leaves the row convertible, so
// the next click feeds the previous *output* back through the converter. On a
// real corpus one page went through that and lost its donation button. The
// write is refused; restore is the way back.

$again = d2g_make_post(
    "<!-- wp:paragraph -->\n<p>Already converted</p>\n<!-- /wp:paragraph -->\n"
    . '[et_pb_text]<p>a shortcode the previous conversion could not read</p>[/et_pb_text]',
    'zz already converted'
);

$again_before = get_post( $again )->post_content;
$again_result = d2g_call( 'd2g_convert_page', [
    'post_id' => $again, 'source_hash' => md5( $again_before ),
] );

d2g_ok( 'converting a page that already holds block markup is refused',
    isset( $again_result['success'] ) && false === $again_result['success'] );
d2g_ok( 'the refusal says to restore first',
    isset( $again_result['data'] ) && false !== stripos( (string) $again_result['data'], 'restore' ),
    (string) ( $again_result['data'] ?? '' ) );
d2g_ok( 'the page is left exactly as it was',
    get_post( $again )->post_content === $again_before );
d2g_ok( 'the refusal did not write a backup over the original',
    '' === (string) get_post_meta( $again, '_d2g_divi_backup', true ) );

wp_delete_post( $again, true );

// A Divi 5 placeholder comment is block markup too, and 142 of 247 pages in a
// real corpus carried one on otherwise ordinary shortcode content. Refusing
// those would be a worse bug than the one the guard exists to prevent.
$placeholder = d2g_make_post(
    '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->[et_pb_text]<p>Convert me</p>[/et_pb_text]',
    'zz divi5 placeholder'
);
$placeholder_result = d2g_call( 'd2g_convert_page', [
    'post_id' => $placeholder, 'source_hash' => md5( get_post( $placeholder )->post_content ),
] );
d2g_ok( 'a Divi 5 placeholder comment does not block a real conversion',
    ! empty( $placeholder_result['success'] ),
    isset( $placeholder_result['data'] ) && is_string( $placeholder_result['data'] ) ? $placeholder_result['data'] : '' );
d2g_ok( 'and that conversion produced blocks',
    false !== strpos( get_post( $placeholder )->post_content, '<!-- wp:paragraph' ) );

wp_delete_post( $placeholder, true );

// ------------------------------------------- an edit that lands mid-conversion --
//
// The lock stops two *conversions* overlapping. It cannot stop an ordinary
// editor, because an editor does not take it. Between the endpoint comparing
// the source hash and wp_update_post() writing, somebody pressing Update in the
// block editor used to have their save silently overwritten.
//
// Simulated at the only moment that matters: `pre_post_update` fires
// immediately before core's UPDATE, so a save made from inside it is exactly a
// save that lands inside the window. The plugin's own guard runs at
// PHP_INT_MAX, after this one, and must refuse.

$race_id  = d2g_make_post( '[et_pb_text]<p>Original</p>[/et_pb_text]', 'race' );
$race_src = get_post( $race_id )->post_content;

$intruder = static function ( $id ) use ( $race_id ) {
    static $done = false;
    if ( $done || (int) $id !== (int) $race_id ) {
        return;
    }
    $done = true; // Only the conversion's own write, not the intruding one.

    global $wpdb;
    // Straight to the database, so this is a genuine external change rather
    // than a re-entrant wp_update_post() the plugin might notice another way.
    $wpdb->update(
        $wpdb->posts,
        [ 'post_content' => '[et_pb_text]<p>Edited by somebody else</p>[/et_pb_text]' ],
        [ 'ID' => $race_id ]
    );
    clean_post_cache( $race_id );
};

add_action( 'pre_post_update', $intruder, 1 );
$raced = d2g_call( 'd2g_convert_page', [
    'post_id' => $race_id, 'source_hash' => md5( $race_src ),
] );
remove_action( 'pre_post_update', $intruder, 1 );

d2g_ok(
    'a save that lands mid-conversion is not overwritten',
    isset( $raced['success'] ) && false === $raced['success'],
    'endpoint reported: ' . wp_json_encode( $raced['data'] ?? null )
);

d2g_ok(
    'the intruding edit survives intact',
    'Edited by somebody else' === wp_strip_all_tags( get_post( $race_id )->post_content ) ||
        false !== strpos( get_post( $race_id )->post_content, 'Edited by somebody else' ),
    'post_content is now: ' . substr( get_post( $race_id )->post_content, 0, 120 )
);

wp_delete_post( $race_id, true );

// ----------------------------------- the standalone attribute encoder is right --
//
// D2G_Block_Builder falls back to its own copy of serialize_block_attributes()
// when WordPress is not loaded, which is how the offline fixture suite runs. A
// fallback nobody compares is a fallback nobody knows is still correct, so it
// is held against core's here, where both exist.

$encoder_cases = [
    [ 'placeholder' => 'find--><img src=x onerror=alert(1)>' ],
    [ 'text' => 'Tom & Jerry', 'url' => 'https://example.test/a?b=1&c=2' ],
    [ 'quoted' => 'she said "hello"', 'slash' => 'a\\b' ],
    [ 'nested' => [ 'colour' => [ 'background' => '#102030' ] ], 'n' => 3, 'on' => true ],
];

$encoder_diffs = [];
foreach ( $encoder_cases as $index => $case ) {
    $core     = serialize_block_attributes( $case );
    $fallback = D2G_Block_Builder::serialize_attributes_fallback( $case );

    // WordPress 7.0 rewrote serialize_block_attributes() as one strtr() and
    // added a sixth rule: a literal backslash becomes \. 6.1 through 6.8
    // ran five sequential preg_replace() calls and left it as JSON's own \\.
    //
    // The fallback tracks current core, so on an older WordPress that one
    // difference is expected — and it is only a difference in how the same
    // character is spelled, which the block grammar reads identically. Normalise
    // it away rather than exempting the case, so everything else about these
    // inputs is still compared byte for byte on every version.
    if ( ! D2G_Block_Builder::wp_at_least( '7.0' ) ) {
        $core = str_replace( '\\\\', '\\u005c', $core );
    }

    if ( $core !== $fallback ) {
        $encoder_diffs[] = sprintf( "case %d\n            core:     %s\n            fallback: %s", $index, $core, $fallback );
    }
}

d2g_ok(
    'the offline attribute encoder matches serialize_block_attributes()',
    empty( $encoder_diffs ),
    implode( "\n          ", $encoder_diffs )
);

// -------------------------------------- the version check reads a real version --
//
// D2G_Block_Builder::wp_at_least() decides which core/cover and core/details
// markup to emit. Outside WordPress it answers "yes" to everything, which is
// also what it would answer here if it failed to find the running version — so
// a passing conversion proves nothing on its own. Ask it something that must be
// false on any WordPress this plugin runs on.

d2g_ok(
    'the block builder reads the running WordPress version',
    D2G_Block_Builder::wp_at_least( get_bloginfo( 'version' ) )
        && ! D2G_Block_Builder::wp_at_least( '999.0' ),
    sprintf(
        'running %s; at_least(self)=%s at_least(999.0)=%s',
        get_bloginfo( 'version' ),
        var_export( D2G_Block_Builder::wp_at_least( get_bloginfo( 'version' ) ), true ),
        var_export( D2G_Block_Builder::wp_at_least( '999.0' ), true )
    )
);

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
    'post_id' => $id, 'source_hash' => md5( $before ),
] );

d2g_ok( 'a conversion that KSES would strip is refused',
    isset( $refused['success'] ) && false === $refused['success'],
    'response: ' . wp_json_encode( $refused ) );

d2g_ok( 'the refusal names what would have been removed',
    isset( $refused['data'] ) && false !== strpos( (string) $refused['data'], 'script' ),
    'message: ' . ( $refused['data'] ?? '(none)' ) );

d2g_ok( 'the page is left exactly as it was', get_post( $id )->post_content === $before );

// A refusal must not leave its snapshot behind. The snapshot is write-once, so
// one left here would still be here at the next attempt, describing content that
// has since moved on — which is what happened on a real site when the WordPress
// importer renamed an image between one conversion attempt and the next.
d2g_ok( 'a refused conversion leaves no backup behind',
    '' === (string) get_post_meta( $id, '_d2g_divi_backup', true ),
    'backup meta: ' . var_export( get_post_meta( $id, '_d2g_divi_backup', true ), true ) );

d2g_ok( 'and leaves no orphaned backup date or builder meta',
    '' === (string) get_post_meta( $id, '_d2g_backup_date', true )
    && '' === (string) get_post_meta( $id, '_d2g_builder_meta', true ) );

// The other half of the rule: a snapshot somebody else's conversion made is not
// this attempt's to delete.
$kept_id = d2g_make_post( $dangerous, 'kses: pre-existing backup' );
update_post_meta( $kept_id, '_d2g_divi_backup', wp_slash( '[et_pb_text]<p>An earlier original</p>[/et_pb_text]' ) );
update_post_meta( $kept_id, '_d2g_backup_date', '2020-01-01 00:00:00' );

d2g_call( 'd2g_convert_page', [
    'post_id' => $kept_id, 'source_hash' => md5( get_post( $kept_id )->post_content ),
] );

d2g_ok( 'a refusal does not delete a backup it did not create',
    false !== strpos( (string) get_post_meta( $kept_id, '_d2g_divi_backup', true ), 'An earlier original' ) );

wp_delete_post( $kept_id, true );

// An ordinary page must still convert: refusing everything on multisite would
// be its own kind of broken.
$safe_id   = d2g_make_post( '[et_pb_text]<p>Ordinary</p>[/et_pb_text]', 'kses: ordinary' );
$safe_hash = md5( get_post( $safe_id )->post_content );
$allowed   = d2g_call( 'd2g_convert_page', [
    'post_id' => $safe_id, 'source_hash' => $safe_hash,
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
        'post_id' => $id, 'source_hash' => $hash,
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
        'post_id' => $id, 'source_hash' => md5( get_post( $id )->post_content ),
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
