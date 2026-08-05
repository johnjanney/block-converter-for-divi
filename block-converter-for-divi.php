<?php
/**
 * Plugin Name: Block Converter for Divi
 * Plugin URI:  https://github.com/johnjanney/block-converter-for-divi
 * Description: Converts pages built with the Divi Builder into native Gutenberg blocks, preserving content, images, and design intent.
 * Version:     2.2.0
 * Author:      John Janney
 * License:     GPL-2.0-or-later
 * Text Domain: block-converter-for-divi
 * Requires at least: 6.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * The plugin was renamed from "Divi to Gutenberg Converter" (divi2gutenberg) to
 * satisfy the WordPress.org rule against leading a plugin name or slug with
 * someone else's trademark.
 *
 * The D2G_ / d2g_ prefixes below were deliberately NOT renamed along with it.
 * They are internal and already unique, but more importantly the storage keys
 * built from them — the _d2g_divi_backup and _d2g_backup_date post meta, and the
 * d2g_delete_data_on_uninstall option — hold real data on every site that ran
 * 1.0.0 through 1.2.0. Those backups are the only way to restore a converted
 * page. Renaming the keys would orphan every one of them, so they stay as they
 * are and existing backups keep working after the upgrade.
 */
define( 'D2G_VERSION', '2.2.0' );
define( 'D2G_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'D2G_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Nothing in this plugin runs on the front end. There is no shortcode, no
 * the_content filter, no public asset and no public route — the parser, the
 * converter, the style mapper and the admin screen exist only to serve the
 * Tools screen and its AJAX endpoints. Loading all four on every visitor
 * request meant parsing roughly 2,700 lines of PHP per page view for nothing.
 *
 * is_admin() is true inside admin-ajax.php as well as the admin screens,
 * because admin-ajax.php defines WP_ADMIN before WordPress loads plugins, so
 * it covers every request that can reach the endpoints below.
 *
 * WP-CLI is admitted explicitly. is_admin() is false under WP-CLI, so the guard
 * on its own made the whole plugin unreachable from the command line — which
 * broke nothing for users today, because there is no WP-CLI command yet, but
 * did mean the endpoints could not be tested against a real WordPress at all.
 * A guard that also blocks the only way to test what it guards is the wrong
 * guard. It is also what a WP-CLI migration command would need (Q11).
 */
if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    return;
}

require_once D2G_PLUGIN_DIR . 'includes/load.php';
require_once D2G_PLUGIN_DIR . 'admin/class-d2g-admin.php';

/**
 * Main plugin class.
 */
final class Block_Converter_For_Divi {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_d2g_convert_page', [ $this, 'ajax_convert_page' ] );
        add_action( 'wp_ajax_d2g_scan_pages', [ $this, 'ajax_scan_pages' ] );
        add_action( 'wp_ajax_d2g_preview_conversion', [ $this, 'ajax_preview_conversion' ] );
        add_action( 'wp_ajax_d2g_restore_page', [ $this, 'ajax_restore_page' ] );
        add_action( 'wp_ajax_d2g_save_settings', [ $this, 'ajax_save_settings' ] );
    }

    public function register_admin_menu() {
        add_management_page(
            __( 'Block Converter for Divi', 'block-converter-for-divi' ),
            __( 'Block Converter for Divi', 'block-converter-for-divi' ),
            'manage_options',
            'block-converter-for-divi',
            [ D2G_Admin::instance(), 'render_page' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'tools_page_block-converter-for-divi' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'd2g-admin',
            D2G_PLUGIN_URL . 'admin/css/admin.css',
            [],
            D2G_VERSION
        );
        wp_enqueue_script(
            'd2g-admin',
            D2G_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            D2G_VERSION,
            true
        );
        // Every user-visible string the script can produce is passed in from
        // PHP so it goes through the normal translation pipeline — the script
        // itself contains no English.
        wp_localize_script( 'd2g-admin', 'd2g', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'd2g_nonce' ),
            'i18n'     => [
                'scan'              => __( 'Scan for Divi Pages', 'block-converter-for-divi' ),
                'scanning'          => __( 'Scanning…', 'block-converter-for-divi' ),
                'scanFailed'        => __( 'Scan failed.', 'block-converter-for-divi' ),
                'scanNetworkError'  => __( 'Network error during scan.', 'block-converter-for-divi' ),
                'noResults'         => __( 'No Divi pages found for the current filter.', 'block-converter-for-divi' ),
                'truncated'         => __( 'showing the first batch only. Use a smaller per-page setting and work through the pages.', 'block-converter-for-divi' ),
                /* translators: %s: number of pages found. */
                'found'             => __( '%s page(s) found.', 'block-converter-for-divi' ),
                /* translators: %s: number of items in the result set. */
                'items'             => __( '%s item(s)', 'block-converter-for-divi' ),
                'noTitle'           => __( '(no title)', 'block-converter-for-divi' ),
                'preview'           => __( 'Preview', 'block-converter-for-divi' ),
                'loading'           => __( 'Loading…', 'block-converter-for-divi' ),
                'previewFailed'     => __( 'Preview failed.', 'block-converter-for-divi' ),
                'previewNetworkError' => __( 'Network error during preview.', 'block-converter-for-divi' ),
                'convert'           => __( 'Convert', 'block-converter-for-divi' ),
                'converting'        => __( 'Converting…', 'block-converter-for-divi' ),
                'converted'         => __( 'Converted', 'block-converter-for-divi' ),
                'confirmConvert'    => __( 'Convert this page to Gutenberg blocks? This will modify the page content.', 'block-converter-for-divi' ),
                /* translators: %s: number of selected pages. */
                'confirmBatch'      => __( 'Convert %s page(s) to Gutenberg blocks?', 'block-converter-for-divi' ),
                'noSelection'       => __( 'No pages selected.', 'block-converter-for-divi' ),
                /* translators: 1: post ID, 2: error message. */
                'convertError'      => __( 'Error converting page %1$s: %2$s', 'block-converter-for-divi' ),
                /* translators: %s: post ID. */
                'convertNetworkError' => __( 'Network error converting page %s.', 'block-converter-for-divi' ),
                'unknownError'      => __( 'Unknown error', 'block-converter-for-divi' ),
                /* translators: 1: converted count, 2: total count. */
                'batchDone'         => __( 'Batch conversion complete. %1$s of %2$s converted.', 'block-converter-for-divi' ),
                /* translators: 1: converted count, 2: total count, 3: failed count. */
                'batchDoneErrors'   => __( 'Batch conversion finished with errors. %1$s of %2$s converted, %3$s failed.', 'block-converter-for-divi' ),
                'restore'           => __( 'Restore', 'block-converter-for-divi' ),
                'restoring'         => __( 'Restoring…', 'block-converter-for-divi' ),
                'restored'          => __( 'Page restored.', 'block-converter-for-divi' ),
                'thisPage'          => __( 'this page', 'block-converter-for-divi' ),
                /* translators: %s: page title. */
                'confirmRestore'    => __( 'Restore "%s" to its original Divi content?

This replaces the current Gutenberg content and hands the page back to the Divi Builder.', 'block-converter-for-divi' ),
                /* translators: 1: post ID, 2: error message. */
                'restoreError'      => __( 'Error restoring page %1$s: %2$s', 'block-converter-for-divi' ),
                'restoreNetworkError' => __( 'Network error during restore.', 'block-converter-for-divi' ),
                'confirmDeleteData' => __( 'Delete every Divi backup when this plugin is deleted?

Backups are the only way to restore a converted page. Once the plugin is removed with this on, they cannot be recovered.', 'block-converter-for-divi' ),
                'saving'            => __( 'Saving…', 'block-converter-for-divi' ),
                'saved'             => __( 'Saved.', 'block-converter-for-divi' ),
                'saveFailed'        => __( 'Could not save setting.', 'block-converter-for-divi' ),
                'saveNetworkError'  => __( 'Network error — setting not saved.', 'block-converter-for-divi' ),
                'yes'               => __( 'Yes', 'block-converter-for-divi' ),
                'firstPage'         => __( 'First page', 'block-converter-for-divi' ),
                'prevPage'          => __( 'Previous page', 'block-converter-for-divi' ),
                'nextPage'          => __( 'Next page', 'block-converter-for-divi' ),
                'lastPage'          => __( 'Last page', 'block-converter-for-divi' ),
                /* translators: 1: current page number, 2: total page count. */
                'pageOf'            => __( '%1$s of %2$s', 'block-converter-for-divi' ),
                /* translators: %s: number of modules needing manual work. */
                'warningsCount'     => __( '%s module(s) need manual attention — see the preview.', 'block-converter-for-divi' ),
            ],
        ] );
    }

    /**
     * Allowed sort columns, mapped to their real database columns.
     *
     * Used as a whitelist so the ORDER BY clause can never take user input
     * directly.
     */
    private static function sortable_columns() {
        return [
            'title'  => 'p.post_title',
            'date'   => 'p.post_date',
            'type'   => 'p.post_type',
            'status' => 'p.post_status',
        ];
    }

    /**
     * AJAX: Scan for pages containing Divi shortcodes, or holding a backup.
     *
     * Posts that have already been converted no longer contain [et_pb_ markup,
     * so they are matched on the presence of a _d2g_divi_backup meta row
     * instead. Without that they would drop out of the listing and their
     * backup would be unreachable from the UI.
     */
    public function ajax_scan_pages() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'block-converter-for-divi' ) );
        }

        global $wpdb;

        // ---- Per page --------------------------------------------------
        $per_page_raw = isset( $_POST['per_page'] ) ? sanitize_text_field( wp_unslash( $_POST['per_page'] ) ) : '';

        if ( 'all' === $per_page_raw ) {
            // "All" is still bounded. An unbounded result set has to be built
            // in PHP, JSON-encoded, and turned into DOM rows one at a time, so
            // a site with thousands of Divi pages would exhaust memory on the
            // server, the browser, or both. The cap is filterable for anyone
            // who knows their site is small enough.
            $per_page = 0; // 0 means "up to the hard cap".
        } else {
            $per_page = absint( $per_page_raw );
            if ( ! in_array( $per_page, [ 20, 50, 100 ], true ) ) {
                $per_page = absint( get_user_option( 'edit_per_page' ) );
                if ( ! $per_page ) {
                    $per_page = 20;
                }
            }
        }

        $paged  = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
        $offset = $per_page ? ( $paged - 1 ) * $per_page : 0;

        // ---- Post type filter ------------------------------------------
        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'all';
        if ( ! in_array( $post_type, [ 'all', 'page', 'post' ], true ) ) {
            $post_type = 'all';
        }

        // Placeholders are filled in by a single prepare() call per query
        // below — the SQL is never interpolated with raw input.
        $type_sql = 'all' === $post_type
            ? "p.post_type IN ('page','post')"
            : 'p.post_type = %s';

        // ---- Sorting ----------------------------------------------------
        $columns = self::sortable_columns();
        $orderby = isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'title';
        if ( ! isset( $columns[ $orderby ] ) ) {
            $orderby = 'title';
        }
        $order = isset( $_POST['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_POST['order'] ) ) ? 'DESC' : 'ASC';

        // Both halves come from whitelists, never from raw input.
        $order_sql = $columns[ $orderby ] . ' ' . $order . ', p.ID ASC';

        // ---- Query ------------------------------------------------------
        // Passed as a %s placeholder rather than written into the SQL: a
        // literal '%' inside a prepare()d string is parsed as a placeholder.
        $like = '%' . $wpdb->esc_like( '[et_pb_' ) . '%';

        $join = "LEFT JOIN {$wpdb->postmeta} bk
                    ON bk.post_id = p.ID AND bk.meta_key = '_d2g_divi_backup'
                 LEFT JOIN {$wpdb->postmeta} bd
                    ON bd.post_id = p.ID AND bd.meta_key = '_d2g_backup_date'";

        $where = "WHERE ( p.post_content LIKE %s OR bk.meta_id IS NOT NULL )
                    AND p.post_status IN ('publish','draft','private','pending')
                    AND {$type_sql}";

        // Argument order must follow the placeholders as they appear in the
        // assembled statement.
        $where_args = [ $like ];
        if ( 'all' !== $post_type ) {
            $where_args[] = $post_type;
        }

        $total_items = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} {$where}",
                $where_args
            )
        );

        $total_pages = $per_page ? (int) ceil( $total_items / $per_page ) : 1;

        // Never select p.post_content or bk.meta_value — either can be
        // megabytes, and 500 rows of it would be built in PHP and then shipped
        // to the browser. MD5() is computed by the database so the conversion
        // endpoint gets its source token for free: the token is what lets a
        // conversion refuse to overwrite an edit made after the scan, and it
        // has to exist for rows the user converts without opening Preview.
        //
        // The joined columns are aggregated so the GROUP BY stays valid under
        // ONLY_FULL_GROUP_BY; the p.* columns are functionally dependent on
        // the grouped primary key.
        $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_date,
                       ( p.post_content LIKE %s ) AS has_divi,
                       MD5( p.post_content ) AS source_hash,
                       ( MAX(bk.meta_id) IS NOT NULL ) AS has_backup,
                       MAX(bd.meta_value) AS backup_date
                FROM {$wpdb->posts} p
                {$join}
                {$where}
                GROUP BY p.ID
                ORDER BY {$order_sql}";

        $args = array_merge( [ $like ], $where_args );

        $sql   .= ' LIMIT %d OFFSET %d';
        $args[] = $per_page ? $per_page : self::scan_hard_cap();
        $args[] = $offset;

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

        // Tell the browser when "All" was truncated, so the count in the status
        // line is not read as "this is everything".
        $truncated = ! $per_page && $total_items > self::scan_hard_cap();

        $pages = [];
        foreach ( $results as $row ) {
            $pages[] = [
                'id'          => (int) $row->ID,
                'title'       => $row->post_title,
                'type'        => $row->post_type,
                'status'      => $row->post_status,
                'date'        => $row->post_date,
                'edit'        => get_edit_post_link( $row->ID, 'raw' ),
                'has_divi'    => (bool) $row->has_divi,
                'has_backup'  => (bool) $row->has_backup,
                'backup_date' => $row->backup_date ? $row->backup_date : '',
                'source_hash' => (string) $row->source_hash,
            ];
        }

        wp_send_json_success( [
            'pages'        => $pages,
            'total_items'  => $total_items,
            'total_pages'  => $total_pages,
            'current_page' => $paged,
            'per_page'     => $per_page ? $per_page : 'all',
            'post_type'    => $post_type,
            'orderby'      => $orderby,
            'order'        => strtolower( $order ),
            'truncated'    => $truncated,
            'shown'        => count( $pages ),
        ] );
    }

    /**
     * Upper bound on how many rows a single scan may return.
     *
     * Applies to the "All" per-page option. 20/50/100 are unaffected.
     */
    private static function scan_hard_cap() {
        return (int) apply_filters( 'd2g_scan_hard_cap', 500 );
    }

    /**
     * Post types this plugin is allowed to touch.
     */
    private static function supported_post_types() {
        return (array) apply_filters( 'd2g_supported_post_types', [ 'page', 'post' ] );
    }

    /**
     * Post statuses this plugin is allowed to touch.
     *
     * Mirrors the scan query, so the UI can never list something an action
     * would then refuse.
     */
    private static function supported_post_statuses() {
        return (array) apply_filters( 'd2g_supported_post_statuses', [ 'publish', 'draft', 'private', 'pending' ] );
    }

    /**
     * Load a post and confirm the current user may act on it.
     *
     * `manage_options` is a site-wide gate, not an object one: a custom role can
     * hold it and still have no business editing a given post. It also says
     * nothing about *what* the ID points at, so a hand-made request could
     * otherwise aim convert or restore at a revision, an autosave, an
     * attachment, or a post type the scan never lists.
     *
     * @return WP_Post|WP_Error
     */
    private static function get_actionable_post( $post_id ) {
        $post_id = absint( $post_id );

        if ( ! $post_id ) {
            return new WP_Error( 'd2g_no_post', __( 'Post not found.', 'block-converter-for-divi' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'd2g_no_post', __( 'Post not found.', 'block-converter-for-divi' ) );
        }

        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return new WP_Error( 'd2g_bad_type', __( 'Revisions and autosaves cannot be converted.', 'block-converter-for-divi' ) );
        }

        if ( ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
            return new WP_Error(
                'd2g_bad_type',
                sprintf(
                    /* translators: %s: post type slug. */
                    __( 'The post type "%s" is not supported by this plugin.', 'block-converter-for-divi' ),
                    $post->post_type
                )
            );
        }

        if ( ! in_array( $post->post_status, self::supported_post_statuses(), true ) ) {
            return new WP_Error(
                'd2g_bad_status',
                sprintf(
                    /* translators: %s: post status slug. */
                    __( 'Posts with status "%s" are not supported by this plugin.', 'block-converter-for-divi' ),
                    $post->post_status
                )
            );
        }

        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return new WP_Error( 'd2g_forbidden', __( 'You are not allowed to edit this post.', 'block-converter-for-divi' ) );
        }

        return $post;
    }

    /**
     * How long a held lock stays valid before another request may steal it.
     *
     * A request that dies mid-conversion cannot release its own lock, so the
     * lock has to age out or the post would be unconvertible forever.
     */
    const LOCK_TIMEOUT = 120;

    private static function lock_key( $post_id ) {
        return 'd2g_lock_' . (int) $post_id;
    }

    /**
     * Take a per-post lock for the duration of a write.
     *
     * Nonces do not stop a replay inside their validity window, and a batch run
     * can queue the same post twice, so the write path needs its own guard
     * against two conversions of one post overlapping.
     *
     * The previous implementation read the lock with get_transient() and then
     * wrote it with set_transient(). That is check-then-set: two requests both
     * read "no lock" before either wrote one, and both proceeded. This version
     * makes acquisition a single INSERT against the UNIQUE index on
     * wp_options.option_name, so exactly one of any number of racing requests
     * can succeed — the database decides, not the gap between two statements.
     *
     * The Options API is deliberately bypassed. add_option() resolves a
     * duplicate with ON DUPLICATE KEY UPDATE and cannot report whether the row
     * was new, which is the one fact this function needs. Nothing reads this
     * key through get_option(), so no cache can go stale behind it, and
     * autoload='no' keeps it out of alloptions.
     *
     * @return string|false An owner token when the lock was taken, else false.
     */
    private static function acquire_lock( $post_id ) {
        global $wpdb;

        $key   = self::lock_key( $post_id );
        $now   = time();
        $token = $now . ':' . wp_generate_password( 20, false );

        // A duplicate key is an expected outcome here, not a bug to log.
        $suppress = $wpdb->suppress_errors( true );
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $key,
                $token
            )
        );
        $wpdb->suppress_errors( $suppress );

        if ( $inserted ) {
            return $token;
        }

        // Someone holds it. Steal it only if it has aged past the timeout, and
        // only by replacing the exact value that was read — so two requests
        // racing to steal the same stale lock cannot both win.
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
        );

        if ( null === $existing ) {
            return false; // Released in between; the caller can simply retry.
        }

        $started = (int) strtok( (string) $existing, ':' );
        if ( $started && ( $now - $started ) < self::LOCK_TIMEOUT ) {
            return false;
        }

        $stolen = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $token,
                $key,
                $existing
            )
        );

        return $stolen ? $token : false;
    }

    /**
     * Release a lock, but only if this request is the one still holding it.
     *
     * Without the token comparison a slow request could delete the lock that a
     * later request had already stolen, and both would then be writing.
     */
    private static function release_lock( $post_id, $token ) {
        global $wpdb;

        if ( ! $token ) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::lock_key( $post_id ),
                $token
            )
        );
    }

    /**
     * AJAX: Preview conversion without saving.
     */
    public function ajax_preview_conversion() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'block-converter-for-divi' ) );
        }

        $post = self::get_actionable_post( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
        if ( is_wp_error( $post ) ) {
            wp_send_json_error( $post->get_error_message() );
        }

        $converter = new D2G_Converter();
        $result    = $converter->convert( $post->post_content );

        wp_send_json_success( [
            'original'    => $post->post_content,
            'converted'   => $result,
            'warnings'    => $converter->get_warnings(),
            // Lets convert reject a save when the page changed after preview.
            'source_hash' => md5( $post->post_content ),
        ] );
    }

    /**
     * What WordPress would strip from this content on the way into the database.
     *
     * wp_update_post() runs post_content through wp_filter_post_kses for any
     * user without `unfiltered_html`. Single-site administrators have that
     * capability; on multisite **only super admins do**, so a site administrator
     * — who has `manage_options` and can therefore reach this plugin — writes
     * through KSES.
     *
     * Measured, on WordPress 7.0.2, that is not a theoretical concern. A Divi
     * Code module holding a tracking script converts to:
     *
     *     <!-- wp:html --><script>…</script><iframe src="…"></iframe><!-- /wp:html -->
     *
     * and KSES stores:
     *
     *     <!-- wp:html -->…<!-- /wp:html -->
     *
     * The script tags are gone and their JavaScript is left as visible text on
     * the page; the iframe is gone entirely. That is exactly the silent content
     * destruction this plugin exists to avoid, so the conversion is refused and
     * the loss is named rather than performed.
     *
     * Writing directly with $wpdb to dodge KSES was considered and rejected:
     * the capability exists to stop users storing markup the site does not
     * trust them with, and a plugin is not entitled to overrule it.
     *
     * @return string[] Human-readable descriptions of what would be lost.
     */
    private static function kses_losses( $content ) {
        if ( current_user_can( 'unfiltered_html' ) ) {
            return [];
        }

        $filtered = wp_kses_post( $content );

        // KSES rewrites `<br/>` as `<br />`. That is cosmetic — core's block
        // validator tokenizes rather than string-compares, and 19 of the 24
        // fixtures KSES touches differ only in this way. Treating it as damage
        // would refuse almost every conversion on multisite for no reason.
        $normalise = static function ( $markup ) {
            return preg_replace( '#\s*/>#', '/>', (string) $markup );
        };

        if ( $normalise( $filtered ) === $normalise( $content ) ) {
            return [];
        }

        $losses = [];

        foreach ( [ 'script', 'iframe', 'object', 'embed', 'form', 'style' ] as $tag ) {
            $before = preg_match_all( '#<' . $tag . '\b#i', $content );
            $after  = preg_match_all( '#<' . $tag . '\b#i', $filtered );

            if ( $before > $after ) {
                $losses[] = sprintf(
                    /* translators: 1: number of elements, 2: HTML tag name. */
                    _n( '%1$d <%2$s> element', '%1$d <%2$s> elements', $before - $after, 'block-converter-for-divi' ),
                    $before - $after,
                    $tag
                );
            }
        }

        if ( ! $losses ) {
            // Something changed that is not a whole element — an inline style
            // declaration, or an attribute KSES does not allow.
            $losses[] = __( 'some inline styles or HTML attributes', 'block-converter-for-divi' );
        }

        return $losses;
    }

    /**
     * The message shown when a write would lose content to KSES.
     */
    private static function kses_refusal( array $losses ) {
        return sprintf(
            /* translators: %s: comma-separated list of what would be removed, e.g. "1 <script> element". */
            __( 'This would remove content. Your account cannot save unfiltered HTML — on a multisite network only super admins can — so WordPress would strip %s from this page as it saved. Nothing has been changed. Ask a super admin to run this conversion, or remove that content from the page first.', 'block-converter-for-divi' ),
            implode( ', ', $losses )
        );
    }

    /**
     * AJAX: Convert a page and save.
     */
    public function ajax_convert_page() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'block-converter-for-divi' ) );
        }

        $post = self::get_actionable_post( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
        if ( is_wp_error( $post ) ) {
            wp_send_json_error( $post->get_error_message() );
        }

        $post_id = $post->ID;
        $backup  = isset( $_POST['backup'] ) && 'yes' === $_POST['backup'];

        // The single most destructive thing this plugin can do is overwrite a
        // good backup with content it has already converted. That happens when
        // a post is converted twice: the second pass reads Gutenberg markup,
        // writes it over _d2g_divi_backup, and the only Divi copy is gone. The
        // browser disables the button after a conversion, but a replayed
        // request, a queued batch, or a second tab does not go through the
        // browser. So the server refuses outright.
        if ( ! D2G_Parser::has_divi_content( $post->post_content ) ) {
            wp_send_json_error( __( 'This post does not contain Divi content. It may already have been converted.', 'block-converter-for-divi' ) );
        }

        // Refuse to save against content the client has not actually seen.
        //
        // This check used to run only when the client chose to send a hash, and
        // the two paths that matter — a single conversion without Preview, and
        // every batch conversion — sent an empty one. So the common case wrote
        // over whatever the post held, including an edit somebody had saved
        // seconds earlier. The token is now mandatory: Scan issues one for every
        // row it returns and Preview refreshes it, so there is no flow that
        // legitimately lacks one.
        $expected_hash = isset( $_POST['source_hash'] ) ? sanitize_key( wp_unslash( $_POST['source_hash'] ) ) : '';
        if ( '' === $expected_hash ) {
            wp_send_json_error( __( 'This request did not identify which version of the page it was converting. Scan again, then convert.', 'block-converter-for-divi' ) );
        }

        $lock = self::acquire_lock( $post_id );
        if ( ! $lock ) {
            wp_send_json_error( __( 'A conversion of this post is already running.', 'block-converter-for-divi' ) );
        }

        // Re-read under the lock. Everything checked above was read before the
        // lock existed, so a concurrent save could have landed in between.
        $post = get_post( $post_id );
        if ( ! $post || ! hash_equals( md5( $post->post_content ), $expected_hash ) ) {
            self::release_lock( $post_id, $lock );
            wp_send_json_error( __( 'This post changed since it was scanned. Scan again and re-check the preview before converting.', 'block-converter-for-divi' ) );
        }

        // wp_send_json_*() ends the request, so the lock has to be dropped
        // before each one rather than in a finally block — otherwise a failed
        // conversion would keep the post locked until the lock aged out.
        if ( $backup ) {
            self::write_backup( $post );
        }

        $converter = new D2G_Converter();
        $converted = $converter->convert( $post->post_content );

        if ( '' === trim( $converted ) ) {
            self::release_lock( $post_id, $lock );
            wp_send_json_error( __( 'Conversion produced no content. Nothing was saved.', 'block-converter-for-divi' ) );
        }

        // Refuse rather than let WordPress quietly strip the result. See
        // kses_losses(): on multisite a site administrator writes through KSES,
        // and a page with a Divi Code module loses its scripts and iframes.
        $losses = self::kses_losses( $converted );
        if ( $losses ) {
            self::release_lock( $post_id, $lock );
            wp_send_json_error( self::kses_refusal( $losses ) );
        }

        // wp_update_post() unslashes what it is given, so content that is not
        // slashed on the way in loses a level of backslashes — every "\n" in a
        // code sample, every escape in a regex or JSON string.
        $update = wp_update_post( wp_slash( [
            'ID'           => $post_id,
            'post_content' => $converted,
        ] ), true );

        if ( is_wp_error( $update ) ) {
            self::release_lock( $post_id, $lock );
            wp_send_json_error( $update->get_error_message() );
        }

        // Remove Divi page-builder meta so WP opens the block editor. The
        // values were captured by write_backup() above, so restore can put them
        // back.
        delete_post_meta( $post_id, '_et_pb_use_builder' );
        delete_post_meta( $post_id, '_et_pb_old_content' );

        self::release_lock( $post_id, $lock );

        wp_send_json_success( [
            'post_id'     => $post_id,
            'message'     => sprintf(
                /* translators: %s: page title. */
                __( 'Page "%s" converted successfully.', 'block-converter-for-divi' ),
                $post->post_title
            ),
            'has_backup'  => (bool) $backup,
            'backup_date' => $backup ? get_post_meta( $post_id, '_d2g_backup_date', true ) : '',
            'warnings'    => $converter->get_warnings(),
        ] );
    }

    /**
     * Snapshot a post's Divi state before conversion overwrites it.
     *
     * Two rules matter here:
     *
     * 1. The first snapshot wins. Once _d2g_divi_backup holds the original Divi
     *    content it is never rewritten, so no later request can replace it.
     * 2. post_content alone is not the whole state. Conversion also deletes
     *    _et_pb_use_builder and _et_pb_old_content; without capturing those a
     *    "restore" hands back the text but not the builder state.
     *
     * Meta values are slashed on the way in because update_post_meta() unslashes
     * before it stores.
     */
    private static function write_backup( WP_Post $post ) {
        $existing = get_post_meta( $post->ID, '_d2g_divi_backup', true );

        if ( '' === $existing || null === $existing || false === $existing ) {
            update_post_meta( $post->ID, '_d2g_divi_backup', wp_slash( $post->post_content ) );
            update_post_meta( $post->ID, '_d2g_backup_date', current_time( 'mysql' ) );
        }

        // The builder meta is re-captured each time, because it is deleted on
        // every conversion and re-added on every restore.
        //
        // Recorded as an explicit {exists, values} pair per key rather than as
        // "the non-empty ones". get_post_meta( …, true ) returns '' both for a
        // key that is absent and for a key present with an empty value, so the
        // previous shape could not tell those apart — and it dropped repeated
        // meta rows entirely. Storing every row, and storing the absence of any
        // row as a fact in its own right, is what makes "restored as found"
        // literally true rather than approximately true.
        //
        // The snapshot is written even when both keys are absent, because that
        // absence is exactly what restore has to reproduce.
        $builder_meta = [];
        foreach ( self::builder_meta_keys() as $key ) {
            $values                 = get_post_meta( $post->ID, $key );
            $builder_meta[ $key ]   = [
                'exists' => is_array( $values ) && count( $values ) > 0,
                'values' => is_array( $values ) ? array_values( $values ) : [],
            ];
        }

        update_post_meta( $post->ID, '_d2g_builder_meta', wp_slash( $builder_meta ) );
    }

    /**
     * The Divi builder meta keys this plugin removes and puts back.
     */
    private static function builder_meta_keys() {
        return [ '_et_pb_use_builder', '_et_pb_old_content' ];
    }

    /**
     * Put the captured builder meta back exactly as write_backup() found it.
     *
     * Both managed keys are cleared first. Without that, restore could only
     * ever add or overwrite: a key that did *not* exist before conversion but
     * exists now would survive a "restore", and repeated meta rows would
     * accumulate across repeated restores.
     */
    private static function restore_builder_meta( $post_id ) {
        $snapshot = get_post_meta( $post_id, '_d2g_builder_meta', true );

        if ( ! is_array( $snapshot ) || ! $snapshot ) {
            // No snapshot: a backup taken by 1.x, before builder meta was
            // captured at all. Switching the builder on is the best available
            // guess and matches what those versions did.
            update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
            return;
        }

        foreach ( self::builder_meta_keys() as $key ) {
            delete_post_meta( $post_id, $key );
        }

        foreach ( self::builder_meta_keys() as $key ) {
            if ( ! isset( $snapshot[ $key ] ) ) {
                continue; // Recorded as absent, or never recorded. Leave it absent.
            }

            $record = $snapshot[ $key ];

            // 2.2.0 shape: { exists: bool, values: [ … ] }.
            if ( is_array( $record ) && array_key_exists( 'values', $record ) ) {
                foreach ( (array) $record['values'] as $value ) {
                    add_post_meta( $post_id, $key, wp_slash( $value ) );
                }
                continue;
            }

            // 2.1.0 shape: one scalar per key, non-empty values only.
            add_post_meta( $post_id, $key, wp_slash( $record ) );
        }
    }

    /**
     * AJAX: Save the tools-screen settings.
     *
     * Only one setting so far: whether deleting the plugin should also delete
     * the Divi backups. Defaults to off — see uninstall.php.
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'block-converter-for-divi' ) );
        }

        $delete_data = isset( $_POST['delete_data'] ) && 'yes' === $_POST['delete_data'];

        update_option( 'd2g_delete_data_on_uninstall', $delete_data ? 1 : 0 );

        wp_send_json_success( [
            'delete_data' => $delete_data,
            'message'     => $delete_data
                ? __( 'Backups will be deleted when the plugin is deleted.', 'block-converter-for-divi' )
                : __( 'Backups will be kept when the plugin is deleted.', 'block-converter-for-divi' ),
        ] );
    }

    /**
     * AJAX: Restore a page's original Divi content from its backup.
     *
     * The backup meta is deliberately left in place afterwards so a restore can
     * be repeated, and so converting again does not lose the original.
     */
    public function ajax_restore_page() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'block-converter-for-divi' ) );
        }

        $post = self::get_actionable_post( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
        if ( is_wp_error( $post ) ) {
            wp_send_json_error( $post->get_error_message() );
        }

        $post_id = $post->ID;
        $backup  = get_post_meta( $post_id, '_d2g_divi_backup', true );

        if ( '' === $backup || null === $backup || false === $backup ) {
            wp_send_json_error( __( 'No backup found for this page.', 'block-converter-for-divi' ) );
        }

        $lock = self::acquire_lock( $post_id );
        if ( ! $lock ) {
            wp_send_json_error( __( 'Another operation on this post is already running.', 'block-converter-for-divi' ) );
        }

        // A restore writes the original Divi content, which can hold scripts in
        // a Code module exactly as the converted version can. Restoring through
        // KSES would hand back something that is not the backup, while
        // reporting success — so it is refused on the same terms.
        $losses = self::kses_losses( $backup );
        if ( $losses ) {
            self::release_lock( $post_id, $lock );
            wp_send_json_error( self::kses_refusal( $losses ) );
        }

        // Slashed for the same reason as the conversion write: wp_update_post()
        // unslashes, so an unslashed restore would hand back content that is
        // not byte-identical to the backup.
        $update = wp_update_post( wp_slash( [
            'ID'           => $post_id,
            'post_content' => $backup,
        ] ), true );

        if ( is_wp_error( $update ) ) {
            self::release_lock( $post_id, $lock );
            wp_send_json_error( $update->get_error_message() );
        }

        // Restore does not take a source token. Conversion needs one because it
        // rewrites content the user has only seen a summary of; restore is the
        // user explicitly discarding whatever is there now in favour of a
        // snapshot they asked for by name. The lock still applies.
        self::restore_builder_meta( $post_id );

        self::release_lock( $post_id, $lock );

        wp_send_json_success( [
            'post_id' => $post_id,
            'message' => sprintf(
                /* translators: %s: page title. */
                __( 'Page "%s" restored to its original Divi content.', 'block-converter-for-divi' ),
                $post->post_title
            ),
            // The row is convertible again, so hand back a token for what it
            // now holds — otherwise converting straight after a restore would
            // be refused for having no token.
            'source_hash' => md5( $backup ),
        ] );
    }
}

Block_Converter_For_Divi::instance();
