<?php
/**
 * Convert a whole imported corpus through the real endpoints, and report.
 *
 * This is the manual test the project has been leaning on — import a real Divi
 * export, convert it, read the result — done from the command line against
 * wp-env instead of a browser against somebody's site. It drives the same AJAX
 * endpoints the Tools screen does, including the source token, the retry a
 * stale token earns, and every guard in between, so what it exercises is the
 * shipped path rather than the converter in isolation.
 *
 * Not a substitute for a run on a real site: no media is imported here, so
 * galleries resolve nothing and their emptiness is expected rather than
 * informative.
 *
 * Usage:
 *   npx wp-env run cli wp eval-file \
 *     wp-content/plugins/block-converter-for-divi/tests/live/corpus-run.php
 *
 * @package block-converter-for-divi
 */

if ( ! class_exists( 'Block_Converter_For_Divi' ) ) {
    echo "the plugin is not active\n";
    exit( 1 );
}

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
    return function () {
        throw new Exception( '__ajax_done__' );
    };
} );

function corpus_call( string $action, array $params ) {
    $_POST          = $params;
    $_POST['nonce'] = wp_create_nonce( 'd2g_nonce' );
    $_REQUEST       = $_POST;

    ob_start();
    try {
        do_action( 'wp_ajax_' . $action );
    } catch ( Exception $e ) {
        // Expected: the endpoint finished by dying.
    }

    return json_decode( (string) ob_get_clean(), true );
}

$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admins[0]->ID );

printf( "WordPress %s, plugin %s, PHP %s\n\n", get_bloginfo( 'version' ), BCFD_VERSION, PHP_VERSION );

// ---- what the import left in the database ---------------------------------
global $wpdb;

$divi = $wpdb->get_results(
    "SELECT ID, post_content FROM {$wpdb->posts}
      WHERE post_type IN ('post','page')
        AND post_status IN ('publish','draft','private','pending')
        AND post_content LIKE '%[et_pb\_%'"
);

$plain = 0;
$encoded = 0;
foreach ( $divi as $row ) {
    $plain   += preg_match_all( '#[\w-]+\s*=\s*"#', $row->post_content );
    $encoded += substr_count( $row->post_content, '=&quot;' );
}

printf( "Divi posts imported: %d\n", count( $divi ) );
printf( "attribute delimiters: %d plain, %d encoded as &quot;\n\n", $plain, $encoded );

// ---- scan, the way the screen does ----------------------------------------
$scan = corpus_call( 'd2g_scan_pages', [ 'per_page' => 'all', 'paged' => 1, 'post_type' => 'all' ] );

if ( empty( $scan['success'] ) ) {
    echo "scan failed\n";
    exit( 1 );
}

$rows = $scan['data']['pages'];
printf( "scan: %d listed, %d built with Divi 5 and skipped\n\n", count( $rows ), (int) ( $scan['data']['divi5_count'] ?? 0 ) );

// ---- convert, with the one retry a stale token earns ----------------------
$converted = 0;
$failed    = [];
$retried   = 0;
$warnings  = [];
$started   = microtime( true );

foreach ( $rows as $row ) {
    if ( empty( $row['has_divi'] ) ) {
        continue;
    }

    $hash = $row['source_hash'];
    $res  = corpus_call( 'd2g_convert_page', [ 'post_id' => $row['id'], 'source_hash' => $hash ] );

    // The browser comes back once with the token the refusal handed it, naming
    // what it superseded. Same here, so the retry path is exercised too.
    if ( empty( $res['success'] ) && ! empty( $res['data']['stale_token'] ) ) {
        $retried++;
        $res = corpus_call( 'd2g_convert_page', [
            'post_id'         => $row['id'],
            'source_hash'     => $res['data']['source_hash'],
            'superseded_hash' => $hash,
        ] );
    }

    if ( empty( $res['success'] ) ) {
        $detail    = is_array( $res['data'] ) ? ( $res['data']['message'] ?? '' ) : (string) $res['data'];
        $failed[]  = $row['id'] . ': ' . $detail;
        continue;
    }

    $converted++;
    foreach ( (array) ( $res['data']['warnings'] ?? [] ) as $warning ) {
        $key = $warning['module'] . ' — ' . $warning['message'];
        $warnings[ $key ] = ( $warnings[ $key ] ?? 0 ) + 1;
    }
}

$elapsed = microtime( true ) - $started;

printf( "converted %d, failed %d, retried after a stale token %d\n", $converted, count( $failed ), $retried );
printf( "%.1fs total, %.0f ms/page\n\n", $elapsed, $converted ? ( $elapsed / $converted * 1000 ) : 0 );

foreach ( $failed as $line ) {
    echo "  FAILED $line\n";
}

// ---- what it reported -----------------------------------------------------
arsort( $warnings );
printf( "\n%d distinct warnings across the run:\n", count( $warnings ) );
$shown = 0;
foreach ( $warnings as $text => $count ) {
    printf( "  %4d x %s\n", $count, substr( $text, 0, 110 ) );
    if ( ++$shown >= 14 ) {
        printf( "  … and %d more\n", count( $warnings ) - $shown );
        break;
    }
}

// ---- what the database holds now ------------------------------------------
$left = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
      WHERE post_type IN ('post','page') AND post_content LIKE '%[et_pb\_%'"
);
$backups = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_d2g_divi_backup'"
);
$builder = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_et_pb_use_builder'"
);

printf( "\nafter the run: %d posts still hold shortcodes, %d backups written, %d still flagged for the Divi builder\n",
    $left, $backups, $builder );
