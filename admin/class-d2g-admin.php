<?php
/**
 * Admin page for Divi to Gutenberg Converter.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Render the admin tools page.
     */
    public function render_page() {
        ?>
        <div class="wrap d2g-wrap">
            <h1><?php esc_html_e( 'Divi to Gutenberg Converter', 'divi2gutenberg' ); ?></h1>
            <p class="d2g-description">
                <?php esc_html_e( 'Scan your site for pages built with the Divi Builder and convert them to native Gutenberg blocks.', 'divi2gutenberg' ); ?>
            </p>

            <div class="d2g-toolbar">
                <button type="button" id="d2g-scan" class="button button-primary">
                    <?php esc_html_e( 'Scan for Divi Pages', 'divi2gutenberg' ); ?>
                </button>
                <label class="d2g-backup-label">
                    <input type="checkbox" id="d2g-backup" checked />
                    <?php esc_html_e( 'Create backup before converting', 'divi2gutenberg' ); ?>
                </label>
            </div>

            <div id="d2g-status" class="d2g-status" style="display:none;"></div>

            <div id="d2g-filters" class="d2g-filters" style="display:none;">
                <div class="d2g-filter-group">
                    <label for="d2g-filter-type"><?php esc_html_e( 'Show:', 'divi2gutenberg' ); ?></label>
                    <select id="d2g-filter-type">
                        <option value="all"><?php esc_html_e( 'All', 'divi2gutenberg' ); ?></option>
                        <option value="page"><?php esc_html_e( 'Pages', 'divi2gutenberg' ); ?></option>
                        <option value="post"><?php esc_html_e( 'Posts', 'divi2gutenberg' ); ?></option>
                    </select>
                </div>
                <div class="d2g-filter-group">
                    <label for="d2g-sort-by"><?php esc_html_e( 'Sort by:', 'divi2gutenberg' ); ?></label>
                    <select id="d2g-sort-by">
                        <option value="title-asc"><?php esc_html_e( 'Title (A–Z)', 'divi2gutenberg' ); ?></option>
                        <option value="title-desc"><?php esc_html_e( 'Title (Z–A)', 'divi2gutenberg' ); ?></option>
                        <option value="date-desc"><?php esc_html_e( 'Date (Newest)', 'divi2gutenberg' ); ?></option>
                        <option value="date-asc"><?php esc_html_e( 'Date (Oldest)', 'divi2gutenberg' ); ?></option>
                        <option value="type-asc"><?php esc_html_e( 'Type (A–Z)', 'divi2gutenberg' ); ?></option>
                        <option value="type-desc"><?php esc_html_e( 'Type (Z–A)', 'divi2gutenberg' ); ?></option>
                        <option value="status-asc"><?php esc_html_e( 'Status (A–Z)', 'divi2gutenberg' ); ?></option>
                        <option value="status-desc"><?php esc_html_e( 'Status (Z–A)', 'divi2gutenberg' ); ?></option>
                    </select>
                </div>
                <div class="d2g-filter-group">
                    <label for="d2g-per-page"><?php esc_html_e( 'Per page:', 'divi2gutenberg' ); ?></label>
                    <select id="d2g-per-page">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="all"><?php esc_html_e( 'All', 'divi2gutenberg' ); ?></option>
                    </select>
                </div>
            </div>

            <table id="d2g-results" class="widefat d2g-table" style="display:none;">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="d2g-select-all" /></th>
                        <th class="d2g-sortable" data-sort="title"><span><?php esc_html_e( 'Title', 'divi2gutenberg' ); ?></span></th>
                        <th class="d2g-sortable" data-sort="type"><span><?php esc_html_e( 'Type', 'divi2gutenberg' ); ?></span></th>
                        <th class="d2g-sortable" data-sort="status"><span><?php esc_html_e( 'Status', 'divi2gutenberg' ); ?></span></th>
                        <th class="d2g-sortable" data-sort="date"><span><?php esc_html_e( 'Date', 'divi2gutenberg' ); ?></span></th>
                        <th><?php esc_html_e( 'Backup', 'divi2gutenberg' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'divi2gutenberg' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <div id="d2g-pagination" class="tablenav" style="display:none;">
                <div class="tablenav-pages">
                    <span class="displaying-num" id="d2g-displaying-num"></span>
                    <span class="pagination-links" id="d2g-pagination-links"></span>
                </div>
            </div>

            <div id="d2g-batch-bar" class="d2g-batch-bar" style="display:none;">
                <button type="button" id="d2g-convert-selected" class="button button-primary">
                    <?php esc_html_e( 'Convert Selected', 'divi2gutenberg' ); ?>
                </button>
                <span id="d2g-batch-progress"></span>
            </div>

            <div class="d2g-settings">
                <h2 class="d2g-settings-heading"><?php esc_html_e( 'Data retention', 'divi2gutenberg' ); ?></h2>
                <label class="d2g-setting-label">
                    <input type="checkbox" id="d2g-delete-data"
                        <?php checked( (bool) get_option( 'd2g_delete_data_on_uninstall', false ) ); ?> />
                    <?php esc_html_e( 'Delete all Divi backups when this plugin is deleted', 'divi2gutenberg' ); ?>
                </label>
                <p class="d2g-setting-help">
                    <?php esc_html_e( 'Off by default. Backups are the only way to restore a converted page, so they are kept even after the plugin is removed. Turn this on once your migration is finished and you no longer need to roll anything back.', 'divi2gutenberg' ); ?>
                </p>
                <span id="d2g-settings-feedback" class="d2g-setting-feedback"></span>
            </div>

            <div id="d2g-preview-modal" class="d2g-modal" style="display:none;">
                <div class="d2g-modal-content">
                    <div class="d2g-modal-header">
                        <h2><?php esc_html_e( 'Conversion Preview', 'divi2gutenberg' ); ?></h2>
                        <button type="button" class="d2g-modal-close">&times;</button>
                    </div>
                    <div class="d2g-modal-body">
                        <div class="d2g-preview-panes">
                            <div class="d2g-pane">
                                <h3><?php esc_html_e( 'Original (Divi)', 'divi2gutenberg' ); ?></h3>
                                <pre id="d2g-preview-original"></pre>
                            </div>
                            <div class="d2g-pane">
                                <h3><?php esc_html_e( 'Converted (Gutenberg)', 'divi2gutenberg' ); ?></h3>
                                <pre id="d2g-preview-converted"></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
