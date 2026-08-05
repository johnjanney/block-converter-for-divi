<?php
/**
 * Plugin Name: Divi to Gutenberg Converter
 * Plugin URI:  https://github.com/johnjanney/divi2gutenberg
 * Description: Converts pages built with the Divi Builder into native Gutenberg blocks, preserving content, images, and design intent.
 * Version:     1.2.0
 * Author:      John Janney
 * License:     GPL-2.0-or-later
 * Text Domain: divi2gutenberg
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'D2G_VERSION', '1.2.0' );
define( 'D2G_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'D2G_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once D2G_PLUGIN_DIR . 'includes/class-d2g-parser.php';
require_once D2G_PLUGIN_DIR . 'includes/class-d2g-converter.php';
require_once D2G_PLUGIN_DIR . 'includes/class-d2g-style-mapper.php';
require_once D2G_PLUGIN_DIR . 'admin/class-d2g-admin.php';

/**
 * Main plugin class.
 */
final class Divi2Gutenberg {

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
            __( 'Divi to Gutenberg', 'divi2gutenberg' ),
            __( 'Divi to Gutenberg', 'divi2gutenberg' ),
            'manage_options',
            'divi2gutenberg',
            [ D2G_Admin::instance(), 'render_page' ]
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'tools_page_divi2gutenberg' !== $hook ) {
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
        wp_localize_script( 'd2g-admin', 'd2g', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'd2g_nonce' ),
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
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;

        // ---- Per page --------------------------------------------------
        $per_page_raw = isset( $_POST['per_page'] ) ? sanitize_text_field( wp_unslash( $_POST['per_page'] ) ) : '';

        if ( 'all' === $per_page_raw ) {
            $per_page = 0; // 0 means "no limit".
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

        // Never select bk.meta_value — a backup can be megabytes of shortcode.
        // The joined columns are aggregated so the GROUP BY stays valid under
        // ONLY_FULL_GROUP_BY; the p.* columns are functionally dependent on
        // the grouped primary key.
        $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_date,
                       ( p.post_content LIKE %s ) AS has_divi,
                       ( MAX(bk.meta_id) IS NOT NULL ) AS has_backup,
                       MAX(bd.meta_value) AS backup_date
                FROM {$wpdb->posts} p
                {$join}
                {$where}
                GROUP BY p.ID
                ORDER BY {$order_sql}";

        $args = array_merge( [ $like ], $where_args );

        if ( $per_page ) {
            $sql   .= ' LIMIT %d OFFSET %d';
            $args[] = $per_page;
            $args[] = $offset;
        }

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

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
        ] );
    }

    /**
     * AJAX: Preview conversion without saving.
     */
    public function ajax_preview_conversion() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post    = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found.' );
        }

        $converter = new D2G_Converter();
        $result    = $converter->convert( $post->post_content );

        wp_send_json_success( [
            'original'  => $post->post_content,
            'converted' => $result,
        ] );
    }

    /**
     * AJAX: Convert a page and save.
     */
    public function ajax_convert_page() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $backup  = isset( $_POST['backup'] ) && $_POST['backup'] === 'yes';
        $post    = get_post( $post_id );

        if ( ! $post ) {
            wp_send_json_error( 'Post not found.' );
        }

        // Save backup as post meta before converting.
        if ( $backup ) {
            update_post_meta( $post_id, '_d2g_divi_backup', $post->post_content );
            update_post_meta( $post_id, '_d2g_backup_date', current_time( 'mysql' ) );
        }

        $converter = new D2G_Converter();
        $converted = $converter->convert( $post->post_content );

        $update = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $converted,
        ], true );

        if ( is_wp_error( $update ) ) {
            wp_send_json_error( $update->get_error_message() );
        }

        // Remove Divi page-builder meta so WP opens the block editor.
        delete_post_meta( $post_id, '_et_pb_use_builder' );
        delete_post_meta( $post_id, '_et_pb_old_content' );

        wp_send_json_success( [
            'post_id'     => $post_id,
            'message'     => sprintf( 'Page "%s" converted successfully.', $post->post_title ),
            'has_backup'  => (bool) $backup,
            'backup_date' => $backup ? get_post_meta( $post_id, '_d2g_backup_date', true ) : '',
        ] );
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
            wp_send_json_error( 'Permission denied.' );
        }

        $delete_data = isset( $_POST['delete_data'] ) && 'yes' === $_POST['delete_data'];

        update_option( 'd2g_delete_data_on_uninstall', $delete_data ? 1 : 0 );

        wp_send_json_success( [
            'delete_data' => $delete_data,
            'message'     => $delete_data
                ? 'Backups will be deleted when the plugin is deleted.'
                : 'Backups will be kept when the plugin is deleted.',
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
            wp_send_json_error( 'Permission denied.' );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $post    = get_post( $post_id );

        if ( ! $post ) {
            wp_send_json_error( 'Post not found.' );
        }

        $backup = get_post_meta( $post_id, '_d2g_divi_backup', true );

        if ( '' === $backup || null === $backup ) {
            wp_send_json_error( 'No backup found for this page.' );
        }

        $update = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $backup,
        ], true );

        if ( is_wp_error( $update ) ) {
            wp_send_json_error( $update->get_error_message() );
        }

        // Hand the page back to the Divi Builder.
        update_post_meta( $post_id, '_et_pb_use_builder', 'on' );

        wp_send_json_success( [
            'post_id' => $post_id,
            'message' => sprintf( 'Page "%s" restored to its original Divi content.', $post->post_title ),
        ] );
    }
}

Divi2Gutenberg::instance();
