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
 * Read-only. It creates nothing, saves nothing, and changes no option.
 *
 * Two ways to run it, on the site where the encoding happens.
 *
 * With WP-CLI, which is the tidy one — nothing is installed and nothing is left
 * behind:
 *
 *   wp eval-file bin/diagnose-encoding.php
 *
 * Without WP-CLI, drop this file into wp-content/mu-plugins/ and load
 *
 *   https://your-site/wp-admin/?bcfd-diagnose=1
 *
 * as an administrator, then delete the file. It reports in the admin footer.
 * The guard below is why that is safe to do on a live site: loaded as a plugin
 * this file runs nothing at all unless an administrator asks for it by hand, so
 * a copy left in mu-plugins by accident costs a visitor nothing.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
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
                echo '<p>' . esc_html__( 'Each row is a callback that runs when a post is saved, with the plugin it came from. A row marked MATCH encodes a shortcode attribute while leaving real HTML alone, which is the behaviour being hunted. Copy all of this and send it on. Nothing here changes your site — delete this plugin when you are done with it.', 'block-converter-for-divi' ) . '</p>';
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

bcfd_diagnose_encoding();

/**
 * Does this look like the encoding being hunted?
 */
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

/**
 * The whole report. A function so the file can be either a script or a plugin.
 */
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

    foreach ( [ 'content_save_pre', 'wp_insert_post_data', 'pre_post_content' ] as $hook ) {
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
                    if ( 'wp_insert_post_data' === $hook ) {
                        $data  = [ 'post_content' => $probe, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'probe' ];
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
        echo "Candidates:\n";
        foreach ( $found as $line ) {
            echo "  $line\n";
        }
    } else {
        echo "No single save filter reproduced it.\n\n";
        echo "That leaves the encoding happening somewhere this script cannot reach by\n";
        echo "calling filters one at a time — inside the importer's own write, in an\n";
        echo "action rather than a filter, or in a code path that only runs during an\n";
        echo "admin request. The next step is to import two or three posts with the\n";
        echo "browser importer and check post_content immediately afterwards, then\n";
        echo "repeat with plugins deactivated in halves.\n";
    }
}
