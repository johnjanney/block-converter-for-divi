<?php
/**
 * Plugin Name: Divi quote-encoding diagnostic
 * Description: One-off diagnostic. Activate, then visit Tools → Divi quote-encoding diagnostic. Delete when finished. Does nothing until an administrator asks it to.
 * Version:     1.0.0
 * Author:      John Janney
 * License:     GPL-2.0-or-later
 *
 * Find what turns a Divi shortcode's attribute quotes into `&quot;` (Q43).
 *
 * Some sites store `[et_pb_section fb_built=&quot;1&quot;]` where the export
 * they were built from holds `fb_built="1"`. Until 2.7.0 that voided every
 * design setting, every image source and every button link on the page, because
 * the parser read no attributes at all. The parser copes now, wherever the
 * encoding comes from — but nobody has established what does it, and this is the
 * script for finding out on a site where it happens.
 *
 * What has already been ruled out, by measurement rather than by reasoning:
 *
 *   - The WXR export format, and the export step. The source file holds 20,148
 *     plain `="` pairs and none encoded.
 *   - The WordPress importer on its own. The same file imported into a clean
 *     WordPress with only the importer and this plugin active produces none.
 *   - WordPress core's save path: wp_kses_post(), wp_filter_post_kses(), the
 *     content_save_pre chain, balanceTags(), convert_chars(), sanitize_post_field(),
 *     and a save made without the unfiltered_html capability. None of them encode.
 *   - Divi 5's `wp:divi/placeholder` wrapper. On a real corpus 104 of 105 pages
 *     *without* the wrapper were encoded anyway.
 *
 * What is known about the shape of it: quotes in *text* are encoded and quotes
 * inside real HTML tags are not, so whatever does it is HTML-aware. That is the
 * fingerprint this script looks for.
 *
 * It writes, and exactly what it writes is stated here because "diagnostic"
 * does not mean "harmless". Nothing runs until an administrator presses the
 * button on the Tools page; that submission is a POST with a nonce, so no other
 * site can make your browser start it. What it then does:
 *
 *   - Calls every callback registered on five save and import filters, one at a
 *     time, with a sample string. Those callbacks belong to other plugins and
 *     this file cannot know what they do; one that writes to the database or
 *     calls out to a service will do so.
 *   - Saves one draft post and force-deletes it by ID a line later. That is the
 *     whole point of the second half: walking filters only finds things that
 *     are filters, and a plugin that re-saves on save_post, or writes with
 *     $wpdb, is invisible to it.
 *   - Imports two draft posts through the real WordPress Importer and deletes
 *     them again. Every hook an ordinary import fires, fires — including any
 *     integration another plugin has hung on a post being created.
 *
 * The deletion is by proven ownership, never by name: each probe carries a
 * random marker for this one run, and anything without that marker is left
 * alone and reported. An earlier version selected posts by slug, which would
 * have permanently deleted a page of yours that happened to be called
 * `bcfd-import-probe-1`. Nothing else is touched: no option, no existing post,
 * no setting.
 *
 * Two ways to run it, on the site where the encoding happens.
 *
 * With WP-CLI, which is the tidy one — nothing is installed and nothing is left
 * behind:
 *
 *   wp eval-file bin/diagnose-encoding.php
 *
 * Without WP-CLI, zip this file inside a folder and install it through
 * Plugins -> Add New -> Upload Plugin, then activate it and go to
 * Tools -> Divi quote-encoding diagnostic. Delete it when you are done.
 *
 * Prefer that over copying the file in over SFTP, and not for any reason to do
 * with the file: the uploader installs to whichever site you are logged into,
 * and a file copy does not. Someone chasing this spent three rounds on a file
 * that was never arriving, because it was going to a different site of theirs
 * and every symptom looked like a bad download. The uploader cannot make that
 * mistake.
 *
 * The menu item is deliberate. /wp-admin/?bcfd-diagnose=1 still works, with the
 * token the Tools page hands you, but that is the dashboard's own address — so
 * a file that never loaded and a file that declined to answer look identical
 * from the browser. A menu item that is either there or not is a question with
 * an answer. (The URL never answers with silence either: without a token it
 * says why it did not run.)
 *
 * Loaded as a plugin it runs nothing at all until an administrator asks, so a
 * copy left behind by accident costs a visitor nothing.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Does this look like the encoding being hunted?
 */
if ( ! function_exists( 'bcfd_diag_fingerprint' ) ) :
function bcfd_diag_fingerprint( $before, $after ) {
    $shortcode_encoded = ( false === strpos( $before, 'fb_built=&quot;' ) )
        && ( false !== strpos( $after, 'fb_built=&quot;' ) );
    $html_left_alone   = ( false !== strpos( $after, 'href="https://example.com/a"' ) );

    if ( $shortcode_encoded && $html_left_alone ) {
        return 'MATCH — encodes shortcode attributes, leaves HTML alone';
    }
    if ( $shortcode_encoded ) {
        return 'encodes shortcode attributes (and HTML too — not the reported shape)';
    }
    if ( $before !== $after ) {
        return 'changes the content, but not in the shape being hunted';
    }
    return '';
}
endif;

if ( ! function_exists( 'bcfd_diag_source' ) ) :
function bcfd_diag_source( $callback ) {
    try {
        if ( is_string( $callback ) && function_exists( $callback ) ) {
            $r = new ReflectionFunction( $callback );
        } elseif ( is_array( $callback ) && count( $callback ) === 2 ) {
            $r = new ReflectionMethod( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0], $callback[1] );
        } elseif ( $callback instanceof Closure ) {
            $r = new ReflectionFunction( $callback );
        } else {
            return '(unknown)';
        }
        $file = $r->getFileName();
        if ( ! $file ) {
            return '(internal)';
        }
        // The plugin or theme directory is what identifies the culprit.
        if ( preg_match( '#/(?:plugins|themes|mu-plugins)/([^/]+)/#', $file, $m ) ) {
            return $m[1] . '  (' . basename( $file ) . ':' . $r->getStartLine() . ')';
        }
        return basename( dirname( $file ) ) . '/' . basename( $file ) . ':' . $r->getStartLine();
    } catch ( Throwable $e ) {
        return '(could not resolve)';
    }
}
endif;

if ( ! function_exists( 'bcfd_diag_name' ) ) :
function bcfd_diag_name( $callback ) {
    if ( is_string( $callback ) ) {
        return $callback;
    }
    if ( is_array( $callback ) ) {
        $class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
        return $class . '::' . $callback[1];
    }
    if ( $callback instanceof Closure ) {
        return '{closure}';
    }
    return '(callable)';
}
endif;

/**
 * The whole report. A function so the file can be either a script or a plugin.
 */
if ( ! function_exists( 'bcfd_diagnose_encoding' ) ) :
function bcfd_diagnose_encoding() {
    // Shortcode attributes in text, plus real HTML with attributes of its own. The
    // two have to be told apart: the reported behaviour encodes the first and leaves
    // the second alone.
    $probe = '[et_pb_section fb_built="1" custom_padding="0px|||||"]'
        . '[et_pb_text _builder_version="4.16"]<p><a href="https://example.com/a">link</a></p>'
        . '<p style="text-align: center;">middle</p>[/et_pb_text]'
        . '[et_pb_button button_url="https://example.com/go" button_text="Go" /][/et_pb_section]';

    global $wp_filter;

    printf( "WordPress %s, PHP %s\n", get_bloginfo( 'version' ), PHP_VERSION );
    printf( "active plugins: %d\n\n", count( (array) get_option( 'active_plugins', [] ) ) );

    $found = [];

    // The last two are the importer's own, and they are the reason this list
    // grew: on the site this was written for, an ordinary save stored the
    // content unchanged while an import did not. Whatever is responsible only
    // runs during an import, and these are the two places a plugin can reach in
    // and alter a post on its way through one.
    foreach ( [
        'content_save_pre',
        'wp_insert_post_data',
        'pre_post_content',
        'wp_import_post_data_raw',
        'wp_import_post_data_processed',
    ] as $hook ) {
        if ( empty( $wp_filter[ $hook ] ) ) {
            printf( "%s — no callbacks\n", $hook );
            continue;
        }

        printf( "%s\n", $hook );

        foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $entry ) {
                $callback = $entry['function'];
                $name     = bcfd_diag_name( $callback );
                $where    = bcfd_diag_source( $callback );

                // Applied to the probe on its own, so a match names one callback
                // rather than everything downstream of it.
                $after = $probe;
                try {
                    // These three hand round a post array rather than a string.
                    if ( in_array( $hook, [ 'wp_insert_post_data', 'wp_import_post_data_raw', 'wp_import_post_data_processed' ], true ) ) {
                        $data = [
                            'post_content' => $probe,
                            'post_type'    => 'post',
                            'post_status'  => 'publish',
                            'post_title'   => 'probe',
                            'post_name'    => 'probe',
                            'post_author'  => get_current_user_id(),
                        ];
                        $out   = call_user_func_array( $callback, array_slice( [ $data, $data, $data, true ], 0, max( 1, (int) $entry['accepted_args'] ) ) );
                        $after = is_array( $out ) && isset( $out['post_content'] ) ? $out['post_content'] : $probe;
                    } else {
                        $after = call_user_func( $callback, $probe );
                    }
                } catch ( Throwable $e ) {
                    printf( "  %3d  %-46s %s\n", $priority, $name, '(threw: ' . $e->getMessage() . ')' );
                    continue;
                }

                $verdict = is_string( $after ) ? bcfd_diag_fingerprint( $probe, $after ) : '';
                printf( "  %3d  %-46s %s\n", $priority, substr( $name, 0, 46 ), $where );

                if ( '' !== $verdict ) {
                    printf( "       ^^ %s\n", $verdict );
                    $found[] = $name . ' — ' . $where . ' — ' . $verdict;
                }
            }
        }
        echo "\n";
    }

    echo str_repeat( '-', 72 ), "\n";

    if ( $found ) {
        echo "Candidates from the filter walk:\n";
        foreach ( $found as $line ) {
            echo "  $line\n";
        }
    } else {
        echo "No single save filter reproduced it.\n";
    }

    // ---- the write itself -------------------------------------------------
    //
    // Walking filters one at a time only sees things that *are* filters. A
    // plugin that hooks save_post and calls wp_update_post again, or writes with
    // $wpdb directly, is invisible to it — and on the site this was written for,
    // the walk came back with nothing but core.
    //
    // So do the actual thing: save a post the way the importer does, then read
    // the row straight out of the database rather than through get_post(), which
    // would hand back a cached copy of what we just passed in. Whatever the
    // stored bytes are, that is the answer.
    echo "\n";
    echo "Saving a probe post and reading the row back:\n";

    $probe_id = wp_insert_post( [
        'post_title'   => 'bcfd diagnostic probe (safe to delete)',
        'post_content' => wp_slash( $probe ),
        'post_status'  => 'draft',
        'post_type'    => 'post',
    ], true );

    if ( is_wp_error( $probe_id ) || ! $probe_id ) {
        echo "  could not create a probe post: "
            . ( is_wp_error( $probe_id ) ? $probe_id->get_error_message() : 'unknown' ) . "\n";
        return;
    }

    global $wpdb;
    $stored = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $probe_id ) );

    wp_delete_post( $probe_id, true );

    $stored_encoded = ( false !== strpos( (string) $stored, 'fb_built=&quot;' ) );
    $stored_html    = ( false !== strpos( (string) $stored, 'href="https://example.com/a"' ) );

    printf( "  shortcode attributes encoded in the database : %s\n", $stored_encoded ? 'YES' : 'no' );
    printf( "  real HTML attributes left alone              : %s\n", $stored_html ? 'yes' : 'NO' );

    if ( $stored_encoded && $stored_html ) {
        echo "\n  This is it. An ordinary save reproduces the encoding, so it is not the\n";
        echo "  importer — it is something on this site that runs on every write. The\n";
        echo "  hooks below are where to look, and deactivating those plugins in halves\n";
        echo "  while re-running this page will name it in a few passes.\n\n";

        foreach ( [ 'save_post', 'wp_insert_post', 'wp_after_insert_post', 'edit_post' ] as $hook ) {
            if ( empty( $wp_filter[ $hook ] ) ) {
                continue;
            }
            printf( "  %s\n", $hook );
            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $entry ) {
                    $where = bcfd_diag_source( $entry['function'] );
                    // Core is not what is being hunted; a plugin is.
                    if ( 0 === strpos( $where, 'wp-includes' ) || 0 === strpos( $where, 'wp-admin' ) ) {
                        continue;
                    }
                    printf( "    %3d  %-40s %s\n", $priority, substr( bcfd_diag_name( $entry['function'] ), 0, 40 ), $where );
                }
            }
        }
        return;
    }

    // ---- an actual import ------------------------------------------------
    //
    // Everything above has come back clean on the affected site: no save
    // filter, no import filter, no parser, and an ordinary save that stores the
    // content untouched. Yet importing that site's own export produces 10,098
    // encoded delimiters, every time, to the byte.
    //
    // So run the importer. Not a filter of it, not a parse of it — the real
    // thing, on a two-post file built here, with the row read back out of the
    // database afterwards. If this comes back encoded, the difference lives in
    // the import run and a plugin bisect will find it in a few passes. If it
    // comes back clean, the difference is in the file being imported rather
    // than in the site, and that is worth knowing too.
    echo "\n";
    echo "Running a real import of a two-post file:\n";

    if ( ! class_exists( 'WP_Import' ) ) {
        // The importer plugin returns immediately unless WP_LOAD_IMPORTERS is
        // defined, which normally only happens on Tools → Import. Defining it
        // here is what the import screen does, and is why requiring the plugin
        // file directly does nothing without it.
        if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
            define( 'WP_LOAD_IMPORTERS', true );
        }

        // Only the classes, never the plugin's main file. That file declares
        // wordpress_importer_init() and registers the importer, so pulling it
        // in a second time is a redeclaration fatal — and requiring it *once*
        // does nothing when WordPress has already included it. Loading the
        // pieces directly avoids both, and is the same set the main file loads.
        $dir = WP_PLUGIN_DIR . '/wordpress-importer';

        if ( is_dir( $dir ) ) {
            require_once ABSPATH . 'wp-admin/includes/import.php';

            if ( ! class_exists( 'WP_Importer' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-importer.php' ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
            }
            if ( file_exists( $dir . '/compat.php' ) ) {
                require_once $dir . '/compat.php';
            }
            if ( ! class_exists( 'WordPress\XML\XMLProcessor' ) && file_exists( $dir . '/php-toolkit/load.php' ) ) {
                require_once $dir . '/php-toolkit/load.php';
            }
            foreach ( [
                'class-wxr-parser',
                'class-wxr-parser-simplexml',
                'class-wxr-parser-xml',
                'class-wxr-parser-regex',
                'class-wxr-parser-xml-processor',
            ] as $parser_file ) {
                if ( file_exists( $dir . '/parsers/' . $parser_file . '.php' ) ) {
                    require_once $dir . '/parsers/' . $parser_file . '.php';
                }
            }
            if ( file_exists( $dir . '/parsers.php' ) && ! class_exists( 'WXR_Parser' ) ) {
                require_once $dir . '/parsers.php';
            }
            if ( file_exists( $dir . '/class-wp-import.php' ) ) {
                require_once $dir . '/class-wp-import.php';
            }
        }
    }

    if ( ! class_exists( 'WP_Import' ) ) {
        echo "  the WordPress Importer plugin is not installed, so this step was skipped.\n";
        echo "  Install and activate it, then run this page again.\n";
        return;
    }

    // Everything this import creates carries this one run's marker, and the
    // cleanup below deletes by marker rather than by name. A fixed slug is not
    // proof of ownership: a site with a page already called
    // `bcfd-import-probe-1` would have had it permanently deleted by a tool
    // that promised to touch nothing of yours.
    $run = 'bcfd-' . strtolower( wp_generate_password( 12, false, false ) );

    $author = wp_get_current_user()->user_login;
    $item   = function ( $id ) use ( $probe, $author, $run ) {
        $slug = $run . '-' . $id;

        return '<item><title>bcfd import probe ' . $id . ' (' . $run . ')</title>'
            . '<link>http://example.com/' . $slug . '</link>'
            . '<dc:creator><![CDATA[' . $author . ']]></dc:creator>'
            . '<content:encoded><![CDATA[' . $probe . ']]></content:encoded>'
            . '<excerpt:encoded><![CDATA[]]></excerpt:encoded>'
            . '<wp:post_id>' . ( 990000 + $id ) . '</wp:post_id>'
            . '<wp:post_date>2026-01-01 00:00:00</wp:post_date>'
            . '<wp:post_name>' . $slug . '</wp:post_name>'
            // Draft, not published. The question this answers is what the
            // importer stores, and a draft is stored exactly as a published
            // post is — without appearing on the site, in a feed, or in
            // whatever another plugin does when something goes live.
            . '<wp:status>draft</wp:status><wp:post_type>post</wp:post_type>'
            . '<wp:postmeta><wp:meta_key>_et_pb_use_builder</wp:meta_key><wp:meta_value><![CDATA[on]]></wp:meta_value></wp:postmeta>'
            . '<wp:postmeta><wp:meta_key>_bcfd_probe_run</wp:meta_key><wp:meta_value><![CDATA[' . $run . ']]></wp:meta_value></wp:postmeta>'
            . '</item>';
    };

    $wxr = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<rss version="2.0"'
        . ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"'
        . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
        . ' xmlns:wfw="http://wellformedweb.org/CommentAPI/"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:wp="http://wordpress.org/export/1.2/">'
        . '<channel><title>probe</title><link>http://example.com</link>'
        . '<wp:wxr_version>1.2</wp:wxr_version>'
        . '<wp:base_site_url>http://example.com</wp:base_site_url>'
        . '<wp:base_blog_url>http://example.com</wp:base_blog_url>'
        . $item( 1 )
        . $item( 2 )
        . '</channel></rss>';

    $wxr_file = wp_tempnam( 'bcfd-import-probe.xml' );
    file_put_contents( $wxr_file, $wxr );

    $importer                    = new WP_Import();
    $importer->fetch_attachments = false;

    ob_start();
    try {
        $importer->import( $wxr_file );
    } catch ( Throwable $e ) {
        ob_end_clean();
        @unlink( $wxr_file );
        echo '  the import threw: ' . $e->getMessage() . "\n";
        return;
    }
    ob_end_clean();
    @unlink( $wxr_file );

    // Only what this run created, proved by the marker each probe carries.
    // Never "whatever has that slug" — a post this run did not create is
    // somebody's content, and a permanent delete is not an undoable mistake.
    $imported = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bcfd_probe_run' AND meta_value = %s",
            $run
        )
    );

    if ( ! $imported ) {
        echo "  the import created nothing, so this step proved nothing.\n";
        return;
    }

    $import_encoded = false;
    $import_html    = true;
    $deleted        = 0;
    $kept           = [];

    foreach ( $imported as $id ) {
        $id  = (int) $id;
        $row = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );

        if ( false !== strpos( $row, 'fb_built=&quot;' ) ) {
            $import_encoded = true;
        }
        if ( false === strpos( $row, 'href="https://example.com/a"' ) ) {
            $import_html = false;
        }

        // Re-checked through the meta API immediately before deleting, so the
        // thing being deleted is the thing that was proved to belong to this
        // run. Anything else is left where it is and named below.
        if ( $run === (string) get_post_meta( $id, '_bcfd_probe_run', true ) ) {
            wp_delete_post( $id, true );
            $deleted++;
        } else {
            $kept[] = $id;
        }
    }

    if ( $kept ) {
        printf(
            "  left alone (no marker for this run, so not this run's to delete): %s\n",
            implode( ', ', $kept )
        );
    }

    printf( "  imported %d post(s), then deleted %d of them\n", count( $imported ), $deleted );
    printf( "  shortcode attributes encoded after import : %s\n", $import_encoded ? 'YES' : 'no' );
    printf( "  real HTML attributes left alone           : %s\n", $import_html ? 'yes' : 'NO' );

    if ( $import_encoded ) {
        echo "\n  Reproduced. The import encodes and an ordinary save does not, on this\n";
        echo "  site, with a file built here that definitely went in with plain quotes.\n";
        echo "  Deactivate half the plugins, run this page again, and repeat on\n";
        echo "  whichever half still reproduces. Five or six passes will name it.\n";
        return;
    }

    echo "\n  Not reproduced. A real import of a file that definitely contained plain\n";
    echo "  quotes stored plain quotes. That points away from this site's plugins and\n";
    echo "  towards the file being imported — worth checking the raw bytes of the\n";
    echo "  export for &quot; inside its CDATA sections rather than trusting a parser's\n";
    echo "  view of it.\n";
    return;

}
endif;

/**
 * The Tools page: what this will do, then a button that does it.
 *
 * The page used to run everything while WordPress was rendering it, on an
 * ordinary GET. Two things were wrong with that. A capability check says who
 * you are; it does not say that *you* asked, so a link on another site could
 * have your browser start a run that creates and deletes posts and calls every
 * other plugin's save filters. And a page that has already done all that by the
 * time you read what it does has told you too late.
 *
 * So: read it first, then press the button. The submission is a POST carrying
 * an action-specific nonce, which is the pair WordPress documents for exactly
 * this — the capability check and the nonce answer different questions.
 *
 * @see https://developer.wordpress.org/apis/security/nonces/
 */
if ( ! function_exists( 'bcfd_diag_page' ) ) :
function bcfd_diag_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to run this.', 'block-converter-for-divi' ) );
    }

    $run = isset( $_POST['bcfd_diag_run'] );

    if ( $run ) {
        check_admin_referer( 'bcfd-diagnose-run' );
    }

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'Divi quote-encoding diagnostic', 'block-converter-for-divi' ) . '</h1>';

    if ( ! $run ) {
        echo '<p>' . esc_html__( 'This looks for whatever turns a Divi shortcode\'s attribute quotes into &quot; on this site. It has not done anything yet. When you press the button it will:', 'block-converter-for-divi' ) . '</p>';
        echo '<ul style="list-style:disc;margin-left:2em;">';
        echo '<li>' . esc_html__( 'Call every callback registered on five save and import filters, one at a time, with a sample string. Those callbacks belong to your other plugins, and this cannot know what they do — one that writes to the database or calls out to a service will do so.', 'block-converter-for-divi' ) . '</li>';
        echo '<li>' . esc_html__( 'Save one draft post and permanently delete it again by ID.', 'block-converter-for-divi' ) . '</li>';
        echo '<li>' . esc_html__( 'Import two draft posts through the WordPress Importer and delete those too. Every hook an ordinary import fires will fire, including anything another plugin does when a post is created.', 'block-converter-for-divi' ) . '</li>';
        echo '</ul>';
        echo '<p>' . esc_html__( 'Everything it creates carries a random marker for that one run, and only posts holding that marker are deleted. Nothing of yours is touched: no option, no existing post, no setting. A run on a busy production site is still best done during a quiet period.', 'block-converter-for-divi' ) . '</p>';

        echo '<form method="post">';
        wp_nonce_field( 'bcfd-diagnose-run' );
        submit_button( __( 'Run the diagnostic', 'block-converter-for-divi' ), 'primary', 'bcfd_diag_run' );
        echo '</form>';

        echo '<p>' . esc_html__( 'Prefer plain text (for curl, or to paste somewhere)? This link runs the same thing:', 'block-converter-for-divi' ) . ' ';
        $url = wp_nonce_url( admin_url( '?bcfd-diagnose=1' ), 'bcfd-diagnose-run' );
        echo '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
        echo '</div>';
        return;
    }

    ob_start();
    bcfd_diagnose_encoding();
    $report = ob_get_clean();

    echo '<p>' . esc_html__( 'Each row is a callback that runs when a post is saved, with the plugin it came from. A row marked MATCH encodes a shortcode attribute while leaving real HTML alone, which is the behaviour being hunted. Copy all of this and send it on. Delete this plugin when you are done with it.', 'block-converter-for-divi' ) . '</p>';
    echo '<textarea readonly rows="28" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select()">';
    echo esc_textarea( $report );
    echo '</textarea>';

    echo '<form method="post">';
    wp_nonce_field( 'bcfd-diagnose-run' );
    submit_button( __( 'Run it again', 'block-converter-for-divi' ), 'secondary', 'bcfd_diag_run' );
    echo '</form>';
    echo '</div>';
}
endif;

/*
 * Being run as a script and being loaded as a plugin are not the same thing,
 * and WP_CLI alone cannot tell them apart: activating this from the command
 * line loads it *as a plugin* with WP_CLI defined, so keying the immediate run
 * on that made activation print the whole report and WordPress report "the
 * plugin generated unexpected output".
 *
 * Where the file sits is the honest test. Inside the plugin directories it is a
 * plugin and waits to be asked; anywhere else it is a script someone pointed
 * `wp eval-file` at, and should just answer.
 */
$bcfd_diag_is_plugin =
    ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( __FILE__, WP_PLUGIN_DIR ) )
    || ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( __FILE__, WPMU_PLUGIN_DIR ) );

// Silence is the one thing this file must never answer with — that is the
// confusion it exists to end. If someone points `wp eval-file` at a copy that
// happens to live inside a plugin directory, say why nothing happened.
if ( $bcfd_diag_is_plugin && defined( 'WP_CLI' ) && WP_CLI && did_action( 'plugins_loaded' ) ) {
    fwrite(
        STDERR,
        "This copy is inside a plugin directory, so it is behaving as a plugin and\n"
        . "waiting to be asked rather than running now. Either move it somewhere else\n"
        . "and run `wp eval-file` again, or activate it and use\n"
        . "Tools -> Divi quote-encoding diagnostic.\n"
    );
}

if ( $bcfd_diag_is_plugin || ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    // Loaded as a plugin rather than run as a script. Nothing happens until an
    // administrator asks for it.
    //
    // There is a menu item as well as a URL because a URL cannot tell you
    // whether the file loaded: /wp-admin/?bcfd-diagnose=1 is the dashboard's own
    // address, so a plugin that never loaded and a plugin that ignored you look
    // identical from the browser. If the menu item is under Tools, it loaded.
    add_action( 'admin_menu', function () {
        add_management_page(
            __( 'Divi quote-encoding diagnostic', 'block-converter-for-divi' ),
            __( 'Divi quote-encoding diagnostic', 'block-converter-for-divi' ),
            'manage_options',
            'bcfd-diagnose',
            'bcfd_diag_page'
        );
    } );

    // The plain-text URL still works, for anyone who would rather curl it — but
    // it carries the same nonce, because it starts the same writes. Visiting it
    // without one explains itself rather than doing nothing, which is the whole
    // reason there is a URL as well as a menu item.
    add_action( 'admin_init', function () {
        if ( ! isset( $_GET['bcfd-diagnose'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        header( 'Content-Type: text/plain; charset=utf-8' );

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bcfd-diagnose-run' ) ) {
            echo "The diagnostic plugin is installed and this request reached it.\n\n"
                . "It did not run, because running it writes: it calls other plugins' save\n"
                . "filters, saves and deletes a draft post, and imports and deletes two more.\n"
                . "A link somebody else can put in front of you must not be able to start\n"
                . "that, so the run needs a one-time token this address does not carry.\n\n"
                . "Go to Tools -> Divi quote-encoding diagnostic and press the button. That\n"
                . "page also holds a ready-made plain-text link with the token in it.\n";
            exit;
        }

        bcfd_diagnose_encoding();
        exit;
    } );

    return;
}

// Script mode: nothing above returned, so this copy is a script.
bcfd_diagnose_encoding();
