<?php
/**
 * What deleting the plugin does to the data it stored.
 *
 * This is the one path in the plugin whose whole job is to destroy things, and
 * until now it was the only one with no test at all. Two external reviews said
 * so. The reason it stayed untested is worth stating, because it also shapes
 * what this file can and cannot do: the obvious test is `wp plugin delete`, and
 * running that here would delete the *mounted repository* — wp-env maps the
 * working tree in as the plugin directory, so the honest-looking test destroys
 * the source it is testing.
 *
 * So this does what WordPress does, minus the part that removes the files:
 * defines WP_UNINSTALL_PLUGIN and includes uninstall.php. That is the whole of
 * core's contract with this file (wp-admin/includes/plugin.php,
 * uninstall_plugin()), and everything the file then does is real — a real
 * database, real backups on real posts, a real option.
 *
 * What it cannot check is that WordPress finds the file at all. That is core's
 * behaviour, not this plugin's, and it depends only on the file existing at
 * plugin-root/uninstall.php — which is asserted here, and which
 * bin/build-zip.sh's archive check independently requires to be shipped.
 *
 * Both settings are covered, because "backups survive by default" and "backups
 * go when you asked for that" are two different promises and only one of them
 * is the dangerous one to get wrong.
 *
 * One setting per run, and that is not a convenience: uninstall.php declares a
 * function at the top level, so including it twice in one process is a
 * redeclaration fatal. WordPress includes it exactly once, in a process that
 * then ends. Running each setting in its own process is therefore both the only
 * way this works and the closer imitation of the real thing.
 *
 * Run by bin/live-check.sh, which does both passes. Directly:
 *
 *   npx wp-env run cli wp eval-file \
 *     wp-content/plugins/block-converter-for-divi/tests/live/uninstall.php keep
 *   npx wp-env run cli wp eval-file \
 *     wp-content/plugins/block-converter-for-divi/tests/live/uninstall.php delete
 *
 * Exits non-zero on failure.
 *
 * @package block-converter-for-divi
 */

$d2g_phase = isset( $args[0] ) ? (string) $args[0] : 'keep';

if ( ! in_array( $d2g_phase, [ 'keep', 'delete' ], true ) ) {
    echo "usage: uninstall.php [keep|delete]\n";
    exit( 2 );
}

$GLOBALS['d2g_u_pass'] = 0;
$GLOBALS['d2g_u_fail'] = 0;

function d2g_u_ok( string $label, bool $condition, string $detail = '' ) {
    if ( $condition ) {
        $GLOBALS['d2g_u_pass']++;
        echo "ok    $label\n";
        return;
    }
    $GLOBALS['d2g_u_fail']++;
    echo "FAIL  $label\n";
    if ( '' !== $detail ) {
        echo "        $detail\n";
    }
}

$plugin_dir = defined( 'BCFD_PLUGIN_DIR' ) ? BCFD_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
$uninstall  = $plugin_dir . 'uninstall.php';

printf( "WordPress %s, plugin %s, PHP %s — \"delete backups\" %s\n\n",
    get_bloginfo( 'version' ), BCFD_VERSION, PHP_VERSION,
    'delete' === $d2g_phase ? 'on' : 'off (the default)' );

if ( 'keep' === $d2g_phase ) {
    // ---------------------------------------------- where WordPress looks ----
    //
    // uninstall_plugin() builds this path itself and includes it. A file
    // anywhere else is a file that never runs.

    d2g_u_ok( 'uninstall.php sits where WordPress will look for it', file_exists( $uninstall ) );

    // --------------------------------------------------------- the guard ----
    //
    // Included without the constant, the file must do nothing. It is a plain
    // script in the plugin root, reachable over HTTP on a badly configured
    // server, and what it does is delete post meta.

    $before_guard = wp_json_encode( [
        (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_d2g_divi_backup'" ),
        get_option( 'd2g_delete_data_on_uninstall', 'absent' ),
    ] );

    // A separate process, because the file exits — and `exit` inside an include
    // would end this test run rather than prove anything. Its silence is the
    // evidence.
    $guard_cmd = 'php -r ' . escapeshellarg( 'include ' . var_export( $uninstall, true ) . '; echo "REACHED_THE_END";' ) . ' 2>&1';
    $guard_out = (string) shell_exec( $guard_cmd );

    d2g_u_ok(
        'without WP_UNINSTALL_PLUGIN it exits before doing anything',
        false === strpos( $guard_out, 'REACHED_THE_END' ) && false === stripos( $guard_out, 'fatal' ),
        'it printed: ' . trim( $guard_out )
    );

    d2g_u_ok(
        'and nothing in the database moved while that ran',
        $before_guard === wp_json_encode( [
            (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_d2g_divi_backup'" ),
            get_option( 'd2g_delete_data_on_uninstall', 'absent' ),
        ] )
    );
}

/**
 * Build a page that has been converted, so it carries every key this plugin
 * writes, plus one page and one option that belong to somebody else.
 *
 * @return array{page:int,bystander:int}
 */
function d2g_u_seed(): array {
    $divi = '[et_pb_text]<p>Uninstall fixture</p>[/et_pb_text]';

    $page = wp_insert_post( [
        'post_title'   => 'uninstall: converted page',
        'post_content' => wp_slash( $divi ),
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ] );

    update_post_meta( $page, '_et_pb_use_builder', 'on' );

    $_POST = [
        'nonce'       => wp_create_nonce( 'd2g_nonce' ),
        'post_id'     => $page,
        'source_hash' => md5( get_post( $page )->post_content ),
    ];
    $_REQUEST = $_POST;

    ob_start();
    try {
        do_action( 'wp_ajax_d2g_convert_page' );
    } catch ( Exception $e ) {
        // wp_send_json_success() ends the request; the filters below turn that
        // into an exception so this file keeps running.
    }
    ob_end_clean();

    // Somebody else's page and somebody else's option. A cleanup that takes
    // these has not "removed the plugin's data", whatever the setting said.
    $bystander = wp_insert_post( [
        'post_title'   => 'uninstall: not ours',
        'post_content' => 'nothing to do with the converter',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ] );
    update_post_meta( $bystander, '_some_other_plugin_backup', 'keep me' );
    update_option( 'some_other_plugin_setting', 'keep me' );

    // A lock left behind by a request that died mid-conversion. Working state,
    // never user data, and it goes either way.
    update_option( 'd2g_lock_' . $page, 'stale-token' );

    return [ 'page' => (int) $page, 'bystander' => (int) $bystander ];
}

function d2g_u_run_uninstall( string $uninstall ) {
    // Exactly what uninstall_plugin() does. The constant carries the plugin's
    // basename in core, and the file only tests that it is defined.
    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        define( 'WP_UNINSTALL_PLUGIN', 'block-converter-for-divi/block-converter-for-divi.php' );
    }

    include $uninstall;
}

function d2g_u_cleanup( array $ids ) {
    foreach ( $ids as $id ) {
        wp_delete_post( $id, true );
    }
    delete_option( 'some_other_plugin_setting' );
    delete_option( 'd2g_delete_data_on_uninstall' );
}

// The endpoints end the request with wp_die(); make that catchable.
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
    return function () {
        throw new Exception( '__ajax_done__' );
    };
} );

$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admins[0]->ID );

// Everything below reads the database directly, and that is not fastidiousness.
//
// uninstall.php removes rows with raw `$wpdb->delete()` and `$wpdb->query()`,
// which do not clear WordPress's object cache — and do not need to, because the
// real request ends a moment later. But get_post_meta() and get_option() answer
// from that cache, so a test written with them reports whatever this process
// read earlier, not what is in the database.
//
// That is not a hypothetical. The first version of this file used
// get_post_meta(), and it passed against an uninstall.php with the
// keep-the-backups branch deliberately deleted — every "survives" assertion
// green while the rows were gone. A test that cannot fail is worse than no
// test, because it is quoted as evidence.
function d2g_u_db_option( string $name ) {
    global $wpdb;

    return $wpdb->get_var(
        $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
    );
}

function d2g_u_db_meta( int $post_id, string $key ) {
    global $wpdb;

    $value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
            $post_id,
            $key
        )
    );

    return null === $value ? null : maybe_unserialize( $value );
}

if ( 'keep' === $d2g_phase ) :

// ------------------------------------------- setting off: backups survive ----
//
// The default, and the one that matters most: deleting the plugin must not
// silently destroy the only way to undo everything it did.

update_option( 'd2g_delete_data_on_uninstall', 0 );
$ids = d2g_u_seed();

d2g_u_ok(
    'the fixture really was converted and has a backup',
    null !== d2g_u_db_meta( $ids['page'], '_d2g_divi_backup' ),
    'nothing below means anything if this failed'
);

d2g_u_run_uninstall( $uninstall );

d2g_u_ok(
    'the Divi backup survives',
    null !== d2g_u_db_meta( $ids['page'], '_d2g_divi_backup' )
);
d2g_u_ok(
    'the backup date survives',
    null !== d2g_u_db_meta( $ids['page'], '_d2g_backup_date' )
);
// An array, not a string: the snapshot records every builder meta key as an
// {exists, values} pair. Casting it to compare against '' is how the first
// version of this line passed while raising "Array to string conversion".
$snapshot = d2g_u_db_meta( $ids['page'], '_d2g_builder_meta' );
d2g_u_ok(
    'the builder-meta snapshot survives, so a restore is still exact',
    is_array( $snapshot ) && array_key_exists( '_et_pb_use_builder', $snapshot ),
    'snapshot is: ' . var_export( $snapshot, true )
);

d2g_u_ok(
    'the preference row is dropped, so a reinstall starts from the safe default',
    null === d2g_u_db_option( 'd2g_delete_data_on_uninstall' )
);
d2g_u_ok(
    'a stale conversion lock is dropped',
    null === d2g_u_db_option( 'd2g_lock_' . $ids['page'] )
);
d2g_u_ok(
    "another plugin's post meta is untouched",
    'keep me' === d2g_u_db_meta( $ids['bystander'], '_some_other_plugin_backup' )
);
d2g_u_ok(
    "another plugin's option is untouched",
    'keep me' === d2g_u_db_option( 'some_other_plugin_setting' )
);

// The backup is not just present — it is the Divi source, and restoring from it
// still works after an uninstall. That is the promise being kept.
$kept_backup = (string) d2g_u_db_meta( $ids['page'], '_d2g_divi_backup' );
d2g_u_ok(
    'and the kept backup is still the original Divi content',
    false !== strpos( $kept_backup, '[et_pb_text]' ),
    'backup holds: ' . substr( $kept_backup, 0, 80 )
);

d2g_u_cleanup( $ids );

else :

// -------------------------------------------- setting on: backups removed ----
//
// The other promise, and the reason the setting exists at all: somebody who
// ticked the box and removed the plugin should not find their database still
// carrying a copy of every page they converted.

update_option( 'd2g_delete_data_on_uninstall', 1 );
$ids = d2g_u_seed();

d2g_u_ok(
    'the second fixture was converted and has a backup',
    null !== d2g_u_db_meta( $ids['page'], '_d2g_divi_backup' )
);

d2g_u_run_uninstall( $uninstall );

// get_post_meta() reads through the object cache, which the raw DELETE in
// uninstall.php does not clear. Ask the database.
global $wpdb;
$left = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
          WHERE post_id = %d AND meta_key IN ('_d2g_divi_backup','_d2g_backup_date','_d2g_builder_meta')",
        $ids['page']
    )
);

d2g_u_ok( 'every backup row is gone', 0 === $left, "$left row(s) left behind" );

d2g_u_ok(
    'the preference row is dropped here too',
    null === d2g_u_db_option( 'd2g_delete_data_on_uninstall' )
);
d2g_u_ok(
    "another plugin's post meta is still untouched",
    'keep me' === d2g_u_db_meta( $ids['bystander'], '_some_other_plugin_backup' )
);
d2g_u_ok(
    "another plugin's option is still untouched",
    'keep me' === d2g_u_db_option( 'some_other_plugin_setting' )
);

// The page itself is content. Removing a plugin must never remove that.
d2g_u_ok(
    'the converted page itself still exists',
    (bool) get_post( $ids['page'] )
);
d2g_u_ok(
    'and still holds its converted content',
    false !== strpos( (string) get_post( $ids['page'] )->post_content, '<!-- wp:' )
);

d2g_u_cleanup( $ids );

endif;

printf( "\n%d passed, %d failed\n", $GLOBALS['d2g_u_pass'], $GLOBALS['d2g_u_fail'] );

if ( $GLOBALS['d2g_u_fail'] > 0 ) {
    exit( 1 );
}
