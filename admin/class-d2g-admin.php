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
                        <option value="status-asc"><?php esc_html_e( 'Status (A–Z)', 'divi2gutenberg' ); ?></option>
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
                        <th class="d2g-sortable" data-sort="title"><?php esc_html_e( 'Title', 'divi2gutenberg' ); ?></th>
                        <th class="d2g-sortable" data-sort="type"><?php esc_html_e( 'Type', 'divi2gutenberg' ); ?></th>
                        <th class="d2g-sortable" data-sort="status"><?php esc_html_e( 'Status', 'divi2gutenberg' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'divi2gutenberg' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'divi2gutenberg' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <div id="d2g-pagination" class="d2g-pagination" style="display:none;">
                <span id="d2g-page-info" class="d2g-page-info"></span>
                <div class="d2g-page-buttons">
                    <button type="button" id="d2g-page-first" class="button" title="<?php esc_attr_e( 'First page', 'divi2gutenberg' ); ?>">&laquo;</button>
                    <button type="button" id="d2g-page-prev" class="button" title="<?php esc_attr_e( 'Previous page', 'divi2gutenberg' ); ?>">&lsaquo;</button>
                    <span class="d2g-page-current">
                        <?php esc_html_e( 'Page', 'divi2gutenberg' ); ?>
                        <input type="number" id="d2g-page-input" min="1" value="1" class="small-text" />
                        <?php esc_html_e( 'of', 'divi2gutenberg' ); ?>
                        <span id="d2g-total-pages">1</span>
                    </span>
                    <button type="button" id="d2g-page-next" class="button" title="<?php esc_attr_e( 'Next page', 'divi2gutenberg' ); ?>">&rsaquo;</button>
                    <button type="button" id="d2g-page-last" class="button" title="<?php esc_attr_e( 'Last page', 'divi2gutenberg' ); ?>">&raquo;</button>
                </div>
            </div>

            <div id="d2g-batch-bar" class="d2g-batch-bar" style="display:none;">
                <button type="button" id="d2g-convert-selected" class="button button-primary">
                    <?php esc_html_e( 'Convert Selected', 'divi2gutenberg' ); ?>
                </button>
                <span id="d2g-batch-progress"></span>
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
