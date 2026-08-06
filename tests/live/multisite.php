<?php
/**
 * The multisite case, on an actual network.
 *
 * Q15 is specifically about multisite: on a network, only super admins hold
 * `unfiltered_html`, while any site administrator holds `manage_options` and
 * can therefore reach this plugin. The live suite proves the guard works with
 * the capability filtered away, which is the same condition — but "the same
 * condition" is a claim, and this checks the real thing.
 *
 * Run by bin/multisite-check.sh, which builds the network first.
 */

if ( ! is_multisite() ) {
    echo "FAIL  this must be run on a multisite network\n";
    exit( 1 );
}

$pass = 0;
$fail = 0;
$ok = function ( $label, $cond, $detail = '' ) use ( &$pass, &$fail ) {
    if ( $cond ) { $pass++; echo "ok    $label\n"; return; }
    $fail++; echo "FAIL  $label\n"; if ( $detail ) { echo "        $detail\n"; }
};

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
    return function () { throw new Exception( 'done' ); };
} );

function ms_call( $action, array $params ) {
    $_POST          = $params;
    $_POST['nonce'] = wp_create_nonce( 'd2g_nonce' );
    $_REQUEST       = $_POST;
    ob_start();
    try { do_action( 'wp_ajax_' . $action ); } catch ( Exception $e ) {}
    return json_decode( (string) ob_get_clean(), true );
}

$site_admin = get_user_by( 'login', 'siteadmin' );
$ok( 'a site administrator exists', (bool) $site_admin );

// Create the fixtures as a super admin. Inserting them as the site admin would
// have KSES-stripped the Divi source on the way in, so the converter would
// never see the script and the guard would have nothing to catch — which is
// how the first version of this file fooled itself into passing.
wp_set_current_user( 1 );

$dangerous = '[et_pb_code]<script>window.track=1;</script><iframe src="https://maps.example.com/x"></iframe>[/et_pb_code]';
$id     = wp_insert_post( [ 'post_title' => 'ms: code module', 'post_content' => wp_slash( $dangerous ), 'post_status' => 'publish', 'post_type' => 'page' ] );
$safe   = wp_insert_post( [ 'post_title' => 'ms: ordinary', 'post_content' => wp_slash( '[et_pb_text]<p>Ordinary</p>[/et_pb_text]' ), 'post_status' => 'publish', 'post_type' => 'page' ] );
$before = get_post( $id )->post_content;

$ok( 'the seeded Divi source really does contain a script',
    false !== strpos( $before, '<script>' ) );

wp_set_current_user( $site_admin->ID );

printf( "\nWordPress %s (multisite), acting as %s\n", get_bloginfo( 'version' ), $site_admin->user_login );
$ok( 'the site administrator can manage options', current_user_can( 'manage_options' ) );
$ok( 'the site administrator does NOT hold unfiltered_html — the whole problem',
    ! current_user_can( 'unfiltered_html' ) );

// The destructive case.
$res = ms_call( 'd2g_convert_page', [ 'post_id' => $id, 'source_hash' => md5( $before ) ] );
$ok( 'a conversion that would be stripped is refused', isset( $res['success'] ) && false === $res['success'] );
$ok( 'the refusal explains what would be removed',
    isset( $res['data'] ) && false !== strpos( (string) $res['data'], 'script' ),
    (string) ( $res['data'] ?? '' ) );
$ok( 'the page is untouched', get_post( $id )->post_content === $before );

// The ordinary case must still work, or the plugin is useless on a network.
$res2 = ms_call( 'd2g_convert_page', [ 'post_id' => $safe, 'source_hash' => md5( get_post( $safe )->post_content ) ] );
$ok( 'an ordinary page still converts for a site administrator', ! empty( $res2['success'] ),
    wp_json_encode( $res2 ) );

$stored = get_post( $safe )->post_content;
$ok( 'and what was stored is what the converter produced',
    $stored === ( new D2G_Converter() )->convert( '[et_pb_text]<p>Ordinary</p>[/et_pb_text]' ) );

// A super admin is unaffected and can convert the dangerous page.
wp_set_current_user( 1 );
$ok( 'a super admin does hold unfiltered_html', current_user_can( 'unfiltered_html' ) );
$res3 = ms_call( 'd2g_convert_page', [ 'post_id' => $id, 'source_hash' => md5( get_post( $id )->post_content ) ] );
$ok( 'a super admin can convert the same page', ! empty( $res3['success'] ), wp_json_encode( $res3 ) );
$ok( 'and the script survives for them', false !== strpos( get_post( $id )->post_content, '<script>' ) );

wp_delete_post( $id, true );
wp_delete_post( $safe, true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
