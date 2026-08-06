<?php
/**
 * The one Divi styling attribute this converter maps onto a block attribute.
 *
 * This file used to hold a whole style layer: build_inline_style(),
 * wrapper_style(), parse_font(), get_color_attrs(), border and spacing parsers.
 * None of it was called. It was written for a design that was abandoned — the
 * converter emits blocks with block attributes, not divs with inline style —
 * and it sat in the shipped plugin as dead code for four releases.
 *
 * Dead is not the same as harmless. build_inline_style() concatenated raw Divi
 * values straight into CSS declarations, and appended `custom_css_main_element`
 * verbatim, which is precisely the CSS-injection class that had to be fixed in
 * the live renderers (see D2G_Block_Builder::css_color()). Anyone reconnecting
 * the helper to save time would have reintroduced it. Deleting it is the only
 * way to make that impossible.
 *
 * If a real style mapping is built later it should start from block supports
 * and strict value grammars, not from string concatenation — the shape of what
 * is left below.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Style_Mapper {

    /**
     * Determine the Gutenberg text alignment class from Divi attributes.
     *
     * An allowlist rather than a passthrough: the value reaches a class
     * attribute *and* a block attribute, and core/paragraph regenerates its own
     * markup from the latter, so anything it does not model is worse than
     * useless.
     */
    public static function text_align_class( array $attrs ): string {
        $align = $attrs['text_orientation'] ?? '';
        if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
            return 'has-text-align-' . $align;
        }
        return '';
    }
}
