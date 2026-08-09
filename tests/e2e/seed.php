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
    // Converts cleanly. Carries an ampersand so the encoding fix is visible in
    // the browser, and a permanently lossy setting so the preview has a warning
    // to show.
    //
    // The lossy setting is deliberately an animation rather than padding. This
    // page used to rely on custom_padding being unmappable, and when spacing
    // was mapped onto core's block supports the warning stopped firing and two
    // browser tests failed — they were asserting on a loss the converter had
    // just fixed. An animation needs JavaScript that core has no block for, so
    // it stays lost however far the style layer goes.
    //
    // The padding stays too, so the page still exercises the mapping.
    'e2e: simple page' => sprintf(
        '[et_pb_section custom_padding="40px||40px||true" animation_style="slide"][et_pb_row][et_pb_column type="4_4"]'
        . '[et_pb_text]<h2>Rates &amp; Times</h2><p>Body copy.</p>[/et_pb_text]'
        . '[et_pb_button button_text="Book now" button_url="/book" /]'
        . '[/et_pb_column][/et_pb_row][/et_pb_section]'
    ),

    // Three more for the batch run.
    'e2e: batch one'   => sprintf( $section, '[et_pb_text]<p>One</p>[/et_pb_text]' ),
    'e2e: batch two'   => sprintf( $section, '[et_pb_text]<p>Two</p>[/et_pb_text]' ),

    // Edited behind the browser's back after the scan, so its source token goes
    // stale. Since 2.9.3 that is no longer a failure: the row comes back once
    // with the token the server hands it, converts the version that is actually
    // there, and reports that the page moved. This page is what proves that.
    'e2e: batch stale' => sprintf( $section, '[et_pb_text]<p>Stale</p>[/et_pb_text]' ),

    // Already holds a previous conversion, with one shortcode left over — so
    // the scan lists it and the write refuses it, permanently. That is what the
    // batch runner needs in order to have a real failure to report, which is
    // the behaviour that was broken in 2.0.0 where failures were counted as
    // successes. A stale token used to serve that purpose and no longer can.
    'e2e: batch converted' => "<!-- wp:paragraph -->\n<p>Already converted</p>\n<!-- /wp:paragraph -->\n"
        . '[et_pb_text]<p>A shortcode the previous conversion could not read</p>[/et_pb_text]',
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
