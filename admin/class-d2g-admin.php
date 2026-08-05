<?php
/**
 * Admin page for Block Converter for Divi.
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
            <h1><?php esc_html_e( 'Block Converter for Divi', 'block-converter-for-divi' ); ?></h1>
            <p class="d2g-description">
                <?php esc_html_e( 'Scan your site for pages built with the Divi Builder and convert them to native Gutenberg blocks.', 'block-converter-for-divi' ); ?>
            </p>

            <div class="d2g-toolbar">
                <button type="button" id="d2g-scan" class="button button-primary">
                    <?php esc_html_e( 'Scan for Divi Pages', 'block-converter-for-divi' ); ?>
                </button>
                <label class="d2g-backup-label">
                    <input type="checkbox" id="d2g-backup" checked />
                    <?php esc_html_e( 'Create backup before converting', 'block-converter-for-divi' ); ?>
                </label>
            </div>

            <?php // aria-live so a screen reader hears scan and conversion results, which are otherwise silent. ?>
            <div id="d2g-status" class="d2g-status" role="status" aria-live="polite" aria-atomic="true" style="display:none;"></div>

            <?php
            /*
             * Loss warnings for conversions that already happened.
             *
             * The preview has its own warnings panel inside the dialog, but a
             * user who clicks Convert without previewing never opens it — and
             * until this existed, the warnings the server returned with every
             * conversion response were simply discarded by the browser. That
             * made the whole "we tell you what could not be carried over"
             * feature invisible on the most direct path through the screen.
             */
            ?>
            <div id="d2g-warnings" class="d2g-preview-warnings d2g-conversion-warnings"
                role="region" aria-live="polite"
                aria-label="<?php esc_attr_e( 'What needs manual attention after conversion', 'block-converter-for-divi' ); ?>"
                style="display:none;"></div>

            <div id="d2g-filters" class="d2g-filters" style="display:none;">
                <div class="d2g-filter-group">
                    <label for="d2g-filter-type"><?php esc_html_e( 'Show:', 'block-converter-for-divi' ); ?></label>
                    <select id="d2g-filter-type">
                        <option value="all"><?php esc_html_e( 'All', 'block-converter-for-divi' ); ?></option>
                        <option value="page"><?php esc_html_e( 'Pages', 'block-converter-for-divi' ); ?></option>
                        <option value="post"><?php esc_html_e( 'Posts', 'block-converter-for-divi' ); ?></option>
                    </select>
                </div>
                <div class="d2g-filter-group">
                    <label for="d2g-sort-by"><?php esc_html_e( 'Sort by:', 'block-converter-for-divi' ); ?></label>
                    <select id="d2g-sort-by">
                        <option value="title-asc"><?php esc_html_e( 'Title (A–Z)', 'block-converter-for-divi' ); ?></option>
                        <option value="title-desc"><?php esc_html_e( 'Title (Z–A)', 'block-converter-for-divi' ); ?></option>
                        <option value="date-desc"><?php esc_html_e( 'Date (Newest)', 'block-converter-for-divi' ); ?></option>
                        <option value="date-asc"><?php esc_html_e( 'Date (Oldest)', 'block-converter-for-divi' ); ?></option>
                        <option value="type-asc"><?php esc_html_e( 'Type (A–Z)', 'block-converter-for-divi' ); ?></option>
                        <option value="type-desc"><?php esc_html_e( 'Type (Z–A)', 'block-converter-for-divi' ); ?></option>
                        <option value="status-asc"><?php esc_html_e( 'Status (A–Z)', 'block-converter-for-divi' ); ?></option>
                        <option value="status-desc"><?php esc_html_e( 'Status (Z–A)', 'block-converter-for-divi' ); ?></option>
                    </select>
                </div>
                <div class="d2g-filter-group">
                    <label for="d2g-per-page"><?php esc_html_e( 'Per page:', 'block-converter-for-divi' ); ?></label>
                    <select id="d2g-per-page">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="all"><?php esc_html_e( 'All', 'block-converter-for-divi' ); ?></option>
                    </select>
                </div>
            </div>

            <table id="d2g-results" class="widefat d2g-table" style="display:none;">
                <thead>
                    <tr>
                        <th scope="col" class="check-column">
                            <input type="checkbox" id="d2g-select-all" aria-label="<?php esc_attr_e( 'Select all convertible pages', 'block-converter-for-divi' ); ?>" />
                        </th>
                        <?php
                        // Sorting is a real button inside the header cell, not a
                        // click handler on the cell itself: that makes it
                        // reachable by keyboard, and aria-sort tells assistive
                        // technology which column is active and in which
                        // direction.
                        $d2g_columns = [
                            'title'  => __( 'Title', 'block-converter-for-divi' ),
                            'type'   => __( 'Type', 'block-converter-for-divi' ),
                            'status' => __( 'Status', 'block-converter-for-divi' ),
                            'date'   => __( 'Date', 'block-converter-for-divi' ),
                        ];
                        foreach ( $d2g_columns as $d2g_key => $d2g_label ) :
                            ?>
                            <th scope="col" class="d2g-sortable" data-sort="<?php echo esc_attr( $d2g_key ); ?>" aria-sort="none">
                                <button type="button" class="d2g-sort-btn">
                                    <span><?php echo esc_html( $d2g_label ); ?></span>
                                </button>
                            </th>
                        <?php endforeach; ?>
                        <th scope="col"><?php esc_html_e( 'Backup', 'block-converter-for-divi' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Actions', 'block-converter-for-divi' ); ?></th>
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
                    <?php esc_html_e( 'Convert Selected', 'block-converter-for-divi' ); ?>
                </button>
                <span id="d2g-batch-progress"></span>
            </div>

            <div class="d2g-settings">
                <h2 class="d2g-settings-heading"><?php esc_html_e( 'Data retention', 'block-converter-for-divi' ); ?></h2>
                <label class="d2g-setting-label">
                    <input type="checkbox" id="d2g-delete-data"
                        <?php checked( (bool) get_option( 'd2g_delete_data_on_uninstall', false ) ); ?> />
                    <?php esc_html_e( 'Delete all Divi backups when this plugin is deleted', 'block-converter-for-divi' ); ?>
                </label>
                <p class="d2g-setting-help">
                    <?php esc_html_e( 'Off by default. Backups are the only way to restore a converted page, so they are kept even after the plugin is removed. Turn this on once your migration is finished and you no longer need to roll anything back.', 'block-converter-for-divi' ); ?>
                </p>
                <span id="d2g-settings-feedback" class="d2g-setting-feedback"></span>
            </div>

            <?php
            // role="dialog" plus aria-modal and aria-labelledby give the preview
            // an accessible name and identity; the Escape key, the focus trap,
            // and returning focus on close are handled in admin.js.
            ?>
            <div id="d2g-preview-modal" class="d2g-modal" role="dialog" aria-modal="true"
                aria-labelledby="d2g-preview-title" style="display:none;">
                <div class="d2g-modal-content">
                    <div class="d2g-modal-header">
                        <h2 id="d2g-preview-title"><?php esc_html_e( 'Conversion Preview', 'block-converter-for-divi' ); ?></h2>
                        <button type="button" class="d2g-modal-close"
                            aria-label="<?php esc_attr_e( 'Close preview', 'block-converter-for-divi' ); ?>">&times;</button>
                    </div>
                    <div class="d2g-modal-body">
                        <div id="d2g-preview-warnings" class="d2g-preview-warnings" style="display:none;"></div>
                        <div class="d2g-preview-panes">
                            <div class="d2g-pane">
                                <h3><?php esc_html_e( 'Original (Divi)', 'block-converter-for-divi' ); ?></h3>
                                <pre id="d2g-preview-original"></pre>
                            </div>
                            <div class="d2g-pane">
                                <h3><?php esc_html_e( 'Converted (Gutenberg)', 'block-converter-for-divi' ); ?></h3>
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
