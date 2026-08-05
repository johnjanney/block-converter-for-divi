<?php
/**
 * Uninstall handler for Block Converter for Divi.
 *
 * Runs when the plugin is deleted through the WordPress admin — not on
 * deactivation.
 *
 * The only data this plugin stores is the Divi backups (_d2g_divi_backup and
 * _d2g_backup_date), and those are the sole rollback path for every page it has
 * converted. Deleting them by default would mean removing the plugin silently
 * destroys the ability to undo its own work, so removal is opt-in: nothing is
 * touched unless the administrator ticked "Delete backups when the plugin is
 * deleted" on the tools screen.
 *
 * The preference itself is always cleaned up — it is a setting, not user data,
 * and a reinstall should start from the safe default again.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove this plugin's data for the current site.
 */
function d2g_uninstall_current_site() {
    global $wpdb;

    $delete_data = (bool) get_option( 'd2g_delete_data_on_uninstall', false );

    // Always drop the preference row.
    delete_option( 'd2g_delete_data_on_uninstall' );

    // Conversion locks are options rather than transients, because acquiring
    // one has to be a single atomic INSERT. They are short-lived working state,
    // never user data, so they go regardless of the backup preference. A row
    // only survives to this point if a request died mid-conversion.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'd2g_lock_' ) . '%'
        )
    );

    if ( ! $delete_data ) {
        // Backups are kept deliberately. They can be removed later with:
        //   wp post meta delete <ID> _d2g_divi_backup
        return;
    }

    foreach ( [ '_d2g_divi_backup', '_d2g_backup_date', '_d2g_builder_meta' ] as $meta_key ) {
        $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $meta_key ], [ '%s' ] );
    }
}

/*
 * uninstall.php is executed once, not once per site, so multisite has to be
 * walked explicitly. Options and post meta are both per-site, and so is the
 * "delete backups" preference: this deliberately does NOT purge a network from
 * one switch. Each site's administrator opted in for their own site, or did
 * not, and a network-wide purge triggered from another site's setting would
 * destroy backups nobody there agreed to lose. INSTRUCTIONS.md documents the
 * per-site model to match.
 *
 * Sites are walked in batches rather than loaded all at once, so a large
 * network does not build one array of every site ID before it starts.
 */
if ( is_multisite() ) {
    $d2g_batch_size = 100;
    $d2g_offset     = 0;

    do {
        $d2g_site_ids = get_sites( [
            'fields' => 'ids',
            'number' => $d2g_batch_size,
            'offset' => $d2g_offset,
        ] );

        foreach ( $d2g_site_ids as $d2g_site_id ) {
            switch_to_blog( $d2g_site_id );
            d2g_uninstall_current_site();
            restore_current_blog();
        }

        $d2g_offset += $d2g_batch_size;
    } while ( count( $d2g_site_ids ) === $d2g_batch_size );

    unset( $d2g_batch_size, $d2g_offset, $d2g_site_ids, $d2g_site_id );
} else {
    d2g_uninstall_current_site();
}
