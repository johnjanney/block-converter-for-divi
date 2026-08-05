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
