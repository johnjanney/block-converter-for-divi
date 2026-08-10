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
 * Almost read-only, and the exception is stated because it matters: it saves
 * one draft post called "bcfd diagnostic probe" and force-deletes it again a
 * line later. That is the whole point of the second half — walking filters only
 * finds things that are filters, and a plugin that re-saves on save_post, or
 * writes with $wpdb, is invisible to that. Nothing else is touched: no option,
 * no existing post, no setting.
 *
 * Two ways to run it, on the site where the encoding happens.
 *
 * With WP-CLI, which is the tidy one — nothing is installed and nothing is left
 * behind:
 *
 *   wp eval-file bin/diagnose-encoding.php
 *
 * Without WP-CLI, install it like any other plugin — this file carries a plugin
 * header, so it can sit straight in wp-content/plugins/ or be uploaded as a zip
 * — activate it, and go to Tools -> Divi quote-encoding diagnostic. Delete it
 * when you are done.
 *
 * The menu item is deliberate. /wp-admin/?bcfd-diagnose=1 also works, but that
 * is the dashboard's own address, so a file that never loaded and a file that
 * declined to answer look identical from the browser. A menu item that is
 * either there or not is a question with an answer.
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

    echo "\n  An ordinary save stores the content unchanged, so whatever encodes it\n";
    echo "  does not run on a normal write. That points at the import itself.\n";
    echo "  Next: import two or three posts with the browser importer, then look at\n";
    echo "  post_content immediately afterwards. If it is encoded there, deactivate\n";
    echo "  plugins in halves and re-import between each pass.\n";
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
            function () {
                ob_start();
                bcfd_diagnose_encoding();
                $report = ob_get_clean();

                echo '<div class="wrap">';
                echo '<h1>' . esc_html__( 'Divi quote-encoding diagnostic', 'block-converter-for-divi' ) . '</h1>';
                echo '<p>' . esc_html__( 'Each row is a callback that runs when a post is saved, with the plugin it came from. A row marked MATCH encodes a shortcode attribute while leaving real HTML alone, which is the behaviour being hunted. Copy all of this and send it on. This saves one draft post and deletes it again; nothing else on your site is touched. Delete this plugin when you are done with it.', 'block-converter-for-divi' ) . '</p>';
                echo '<textarea readonly rows="28" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select()">';
                echo esc_textarea( $report );
                echo '</textarea>';
                echo '</div>';
            }
        );
    } );

    // The plain-text URL still works, for anyone who would rather curl it.
    add_action( 'admin_init', function () {
        if ( ! isset( $_GET['bcfd-diagnose'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        header( 'Content-Type: text/plain; charset=utf-8' );
        bcfd_diagnose_encoding();
        exit;
    } );

    return;
}

// Script mode: nothing above returned, so this copy is a script.
bcfd_diagnose_encoding();
