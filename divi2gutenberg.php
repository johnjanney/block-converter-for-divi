<?php
/**
 * Plugin Name: Divi to Gutenberg Converter
 * Plugin URI:  https://github.com/johnjanney/divi2gutenberg
 * Description: Converts pages built with the Divi Builder into native Gutenberg blocks, preserving content, images, and design intent.
 * Version:     1.0.0
 * Author:      John Janney
 * License:     GPL-2.0-or-later
 * Text Domain: divi2gutenberg
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'D2G_VERSION', '1.0.0' );
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
     * AJAX: Scan for pages containing Divi shortcodes.
     */
    public function ajax_scan_pages() {
        check_ajax_referer( 'd2g_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;
        $results = $wpdb->get_results(
            "SELECT ID, post_title, post_type, post_status
             FROM {$wpdb->posts}
             WHERE post_content LIKE '%[et_pb_%'
               AND post_status IN ('publish','draft','private','pending')
               AND post_type IN ('page','post')
             ORDER BY post_type, post_title"
        );

        $pages = [];
        foreach ( $results as $row ) {
            $pages[] = [
                'id'     => (int) $row->ID,
                'title'  => $row->post_title,
                'type'   => $row->post_type,
                'status' => $row->post_status,
                'edit'   => get_edit_post_link( $row->ID, 'raw' ),
            ];
        }

        wp_send_json_success( $pages );
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
            'post_id' => $post_id,
            'message' => sprintf( 'Page "%s" converted successfully.', $post->post_title ),
        ] );
    }
}

Divi2Gutenberg::instance();
