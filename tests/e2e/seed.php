<?php
/**
 * Put the site into a known state for the browser tests.
 *
 * Run before each Playwright run, via bin/e2e.sh. Every page it creates is
 * titled with the `e2e:` prefix so the tests can find them and so a re-run
 * starts from the same place rather than accumulating.
 *
 * @package block-converter-for-divi
 */

// Remove anything a previous run left behind.
$existing = get_posts( [
    'post_type'      => 'page',
    'post_status'    => 'any',
    'numberposts'    => -1,
    's'              => 'e2e:',
] );
foreach ( $existing as $old ) {
    wp_delete_post( $old->ID, true );
}

$section = '[et_pb_section][et_pb_row][et_pb_column type="4_4"]%s[/et_pb_column][/et_pb_row][/et_pb_section]';

$pages = [
    // Converts cleanly. Carries a lossy setting so the preview has a warning to
    // show, and an ampersand so the encoding fix is visible in the browser.
    'e2e: simple page' => sprintf(
        '[et_pb_section custom_padding="40px||40px||true"][et_pb_row][et_pb_column type="4_4"]'
        . '[et_pb_text]<h2>Rates &amp; Times</h2><p>Body copy.</p>[/et_pb_text]'
        . '[et_pb_button button_text="Book now" button_url="/book" /]'
        . '[/et_pb_column][/et_pb_row][/et_pb_section]'
    ),

    // Three more for the batch run.
    'e2e: batch one'   => sprintf( $section, '[et_pb_text]<p>One</p>[/et_pb_text]' ),
    'e2e: batch two'   => sprintf( $section, '[et_pb_text]<p>Two</p>[/et_pb_text]' ),

    // This one is edited behind the browser's back after the scan, so its
    // source token goes stale and the endpoint refuses it. That is how the
    // batch runner gets a real failure to report — the behaviour that was
    // broken in 2.0.0, where failures were counted as successes.
    'e2e: batch stale' => sprintf( $section, '[et_pb_text]<p>Stale</p>[/et_pb_text]' ),
];

$ids = [];
foreach ( $pages as $title => $content ) {
    $id = wp_insert_post( [
        'post_title'   => $title,
        'post_content' => wp_slash( $content ),
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ] );
    update_post_meta( $id, '_et_pb_use_builder', 'on' );
    $ids[ $title ] = $id;
}

// Start from the safe default so the settings test asserts a real change.
update_option( 'd2g_delete_data_on_uninstall', 0 );

echo wp_json_encode( $ids ) . "\n";
