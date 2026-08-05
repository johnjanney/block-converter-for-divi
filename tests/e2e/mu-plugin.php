<?php
/**
 * Test-only helper, loaded as a must-use plugin by wp-env.
 *
 * The browser tests need to change a post *between* the scan and the batch
 * conversion, which is the only way to produce the situation the source token
 * exists to catch: the page changed after the user looked at it. Nothing the
 * browser can do on its own creates that state, and WP-CLI is not reachable
 * from inside the Playwright container.
 *
 * This is mapped into the container by .wp-env.json and lives under tests/, so
 * it cannot reach a release — bin/build-zip.sh excludes tests/ and asserts the
 * archive contents besides. It also refuses to do anything unless D2G_E2E is
 * defined, which only .wp-env.json sets.
 *
 * @package block-converter-for-divi
 */

add_action( 'init', function () {
    if ( ! defined( 'D2G_E2E' ) || ! D2G_E2E ) {
        return;
    }

    // Reset the site to the seeded state. Called before each browser test, so
    // no test depends on what an earlier one left behind — the restore test was
    // finding a page the convert test had already converted.
    if ( isset( $_GET['d2g-e2e-seed'] ) ) {
        require __DIR__ . '/../plugins/block-converter-for-divi/tests/e2e/seed.php';
        exit;
    }

    if ( ! isset( $_GET['d2g-e2e-touch'] ) ) {
        return;
    }

    $post_id = absint( $_GET['d2g-e2e-touch'] );
    $post    = $post_id ? get_post( $post_id ) : null;

    if ( ! $post ) {
        wp_send_json_error( 'no such post', 404 );
    }

    // Append a comment to the Divi source. The content still converts, so the
    // page is not made *invalid* — only different from what the browser was
    // shown, which is precisely what the source token guards against.
    wp_update_post( wp_slash( [
        'ID'           => $post_id,
        'post_content' => $post->post_content . "\n<!-- edited by someone else -->",
    ] ) );

    wp_send_json_success( [
        'post_id'     => $post_id,
        'source_hash' => md5( get_post( $post_id )->post_content ),
    ] );
} );
