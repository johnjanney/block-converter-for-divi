<?php
/**
 * Minimal WordPress shim for running the parser and converter outside WordPress.
 *
 * The converter only reaches for a small, deliberate slice of WordPress: the
 * escaping helpers, JSON encoding, and a handful of attachment/menu lookups it
 * already guards with function_exists(). Stubbing that slice is enough to
 * exercise every conversion path in a plain `php` process, which is what makes
 * the fixture suite runnable with no database, no install, and no dependencies.
 *
 * Anything the converter guards with function_exists() is deliberately NOT
 * stubbed unless a fixture needs it — leaving it undefined proves those guards
 * work.
 *
 * @package block-converter-for-divi
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function esc_attr( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
    return esc_html( $text );
}

function esc_url( $url ) {
    $url = (string) $url;
    // Close enough to esc_url() for markup assertions: strip what would break
    // out of an attribute, and reject unsafe protocols.
    if ( preg_match( '#^\s*(javascript|data|vbscript):#i', $url ) ) {
        return '';
    }
    return str_replace( [ '"', "'", '<', '>' ], '', $url );
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function _e( $text, $domain = 'default' ) {
    echo $text;
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
    return json_encode( $data, $options, $depth );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
    $text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
    $text = strip_tags( $text );
    if ( $remove_breaks ) {
        $text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
    }
    return trim( $text );
}

function sanitize_title( $title ) {
    return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
}

function apply_filters( $hook, $value ) {
    return $value;
}

function is_wp_error( $thing ) {
    return false;
}

function post_type_exists( $post_type ) {
    // No Divi in a test process, which is exactly the state a converted site
    // ends up in — so the portfolio warning is expected to fire.
    return in_array( $post_type, [ 'post', 'page', 'attachment' ], true );
}

/**
 * Resolve an attachment ID to a predictable URL.
 *
 * Stubbed because without it the gallery renderer skips every image and the
 * whole image-emitting path — the bulk of what convert_gallery() does — was
 * never executed by any fixture.
 *
 * get_post_meta() and wp_get_attachment_caption() are deliberately left
 * undefined. The renderer guards both with function_exists(), so leaving them
 * out keeps proving those guards work while the main path is still covered.
 */
function wp_get_attachment_url( $id ) {
    // 999 stands for an attachment that no longer exists. get_post_meta() and
    // wp_get_upload_dir() are left undefined, so the two fallback strategies
    // are skipped and the renderer takes its "attachment not found" path —
    // which is the branch that decides whether a dead gallery image becomes a
    // broken <img src=""> or is dropped.
    if ( 999 === (int) $id ) {
        return '';
    }
    return 'https://example.com/uploads/attachment-' . (int) $id . '.jpg';
}

/**
 * A classic menu lookup that answers for exactly one ID.
 *
 * Both branches of convert_menu() matter — the warning names the menu when it
 * can resolve it and falls back to the ID when it cannot — so the stub resolves
 * menu 99 and nothing else.
 */
function wp_get_nav_menu_object( $id ) {
    if ( 99 !== (int) $id ) {
        return false;
    }
    return (object) [ 'term_id' => 99, 'name' => 'Primary Menu' ];
}

/**
 * Stand-in for the block registry, so the suite can exercise what the converter
 * does on a WordPress that lacks a block.
 *
 * core/details arrived in 6.3 and the converter degrades to a heading plus text
 * below that. Without this the degradation path was unreachable outside a real
 * old WordPress, so nothing tested the one thing that keeps 6.0–6.2 users from
 * seeing "your site doesn't include support for this block".
 *
 * A fixture opts in with 'unregistered' => [ 'core/details' ]; by default every
 * block is registered, which is the same answer the converter got before this
 * class existed.
 */
class WP_Block_Type_Registry {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_registered( $name ) {
        return ! in_array( $name, $GLOBALS['d2g_test_unregistered'] ?? [], true );
    }
}
