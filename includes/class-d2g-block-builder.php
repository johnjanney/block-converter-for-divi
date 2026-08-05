<?php
/**
 * Primitives for building Gutenberg block markup, and the guards that keep
 * author-supplied values safe inside it.
 *
 * Everything here is static and stateless: given the same inputs it returns the
 * same string, with no reference to the document being converted. That is what
 * separates it from a renderer — a renderer decides *which* blocks a Divi
 * module becomes, this decides how any one block is written down.
 *
 * The sanitisers live here rather than in a renderer because they answer a
 * question about markup, not about Divi: what may be placed inside a quoted
 * attribute, and what may be placed inside a CSS declaration. Every renderer
 * needs the same answer, and when they each answered it themselves the result
 * was an HTML attribute injection through `align` and a CSS injection through
 * colour values.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Block_Builder {

    /**
     * Format a Gutenberg block comment.
     */
    public static function block( string $name, array $attrs = [], string $html = '', bool $has_inner = false ): string {
        // Core blocks are written without their namespace in block comments,
        // which is exactly the name passed in here.
        $block_name = $name;

        $attrs_json = '';
        if ( ! empty( $attrs ) ) {
            $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
            $attrs_json = ' ' . $encode( $attrs, JSON_UNESCAPED_SLASHES );
        }

        if ( $has_inner ) {
            $open  = "<!-- wp:{$block_name}{$attrs_json} -->";
            $close = "<!-- /wp:{$block_name} -->";
            return $open . "\n" . $html . "\n" . $close . "\n\n";
        }

        if ( '' === $html ) {
            return "<!-- wp:{$block_name}{$attrs_json} /-->\n\n";
        }

        $open  = "<!-- wp:{$block_name}{$attrs_json} -->";
        $close = "<!-- /wp:{$block_name} -->";
        return $open . "\n" . $html . "\n" . $close . "\n\n";
    }

    /**
     * Emit a core/paragraph block, or nothing when there is nothing to say.
     */
    public static function paragraph( string $inner_html, array $attrs ): string {
        // Whitespace and empty markup are not content. An <img> is, even though
        // it strips to nothing.
        if ( '' === trim( strip_tags( $inner_html, '<img><br>' ) ) && ! preg_match( '#<img\b#i', $inner_html ) ) {
            return '';
        }

        $align       = D2G_Style_Mapper::text_align_class( $attrs );
        $block_attrs = [];
        $cls         = '';

        if ( $align ) {
            $block_attrs['align'] = $attrs['text_orientation'];
            $cls                  = ' class="' . $align . '"';
        }

        return D2G_Block_Builder::block( 'paragraph', $block_attrs, '<p' . $cls . '>' . trim( $inner_html ) . '</p>' );
    }

    /**
     * Emit a core/cover block with the markup core's own save() produces.
     *
     * The order of the two background elements is not cosmetic. core/cover
     * saves the <img> first and the dim <span> second; this converter emitted
     * them the other way round, omitted aria-hidden and the has-background-dim-100
     * class, and put the overlay colour nowhere. WordPress re-runs save() over
     * the parsed attributes and compares, so the first token it reached
     * disagreed and every converted Cover was reported as containing
     * "unexpected or invalid content".
     *
     * Four rounds of static string assertions never saw this, because a static
     * check can only look for what someone thought to look for. The real block
     * validator found it on its first run — see tests/js/validate.mjs, and
     * tests/js/canonical.mjs for where this markup came from.
     *
     * @param string $url     Background image URL.
     * @param string $overlay Optional overlay colour, already validated.
     * @param string $inner   Serialized inner blocks.
     */
    public static function cover( string $url, string $overlay, string $inner ): string {
        $attrs = [ 'url' => $url ];

        $span_class = 'wp-block-cover__background has-background-dim-100 has-background-dim';
        $span_style = '';

        if ( '' !== $overlay ) {
            $attrs['customOverlayColor'] = $overlay;
            $span_style                  = ' style="background-color:' . esc_attr( $overlay ) . '"';
        }

        $html = '<div class="wp-block-cover">'
            . '<img class="wp-block-cover__image-background" alt="" src="' . esc_url( $url ) . '" data-object-fit="cover"/>'
            . '<span aria-hidden="true" class="' . $span_class . '"' . $span_style . '></span>'
            . '<div class="wp-block-cover__inner-container">' . "\n" . $inner . "\n" . '</div>'
            . '</div>';

        return D2G_Block_Builder::block( 'cover', $attrs, $html, true );
    }

    /**
     * Reduce a shortcode value to one of a fixed set of tokens.
     *
     * Shortcode attribute values are author-supplied text. They reach markup
     * that this class builds by hand, and the parser accepts single-quoted
     * values, so a value of `center" onmouseover="alert(1)` closed the class
     * attribute it was concatenated into and opened an event handler:
     *
     *     <figure class="wp-block-image size-large aligncenter" onmouseover="…">
     *
     * Escaping alone is not the fix here. These values also become *block
     * attributes*, where an arbitrary string is meaningless to the block that
     * has to regenerate markup from it. So every value that controls layout is
     * reduced to a token this converter understands, and anything else is
     * dropped. Escaping is applied on top, not instead.
     */
    public static function allowed_value( $value, array $allowed, string $default = '' ): string {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    /**
     * A colour value that is safe to write into a CSS declaration.
     *
     * esc_attr() makes a string safe as *markup*; it does nothing about a value
     * that is syntactically valid CSS. `red;background-image:url(…)` survives
     * esc_attr() intact and adds a declaration nobody asked for. Only the colour
     * grammars CSS actually defines are accepted.
     */
    public static function css_color( $value ): string {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }
        // #rgb, #rgba, #rrggbb, #rrggbbaa.
        if ( preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
            return $value;
        }
        // rgb()/rgba()/hsl()/hsla() holding nothing but numbers and separators.
        if ( preg_match( '/^(?:rgba?|hsla?)\(\s*[0-9a-z.,%\s\/-]*\)$/i', $value ) ) {
            return $value;
        }
        // A bare CSS colour keyword.
        if ( preg_match( '/^[a-z]+$/i', $value ) ) {
            return strtolower( $value );
        }

        return '';
    }

    /**
     * Escape a Divi-supplied string for use as HTML text.
     *
     * Divi stores shortcode attribute values HTML-encoded, so a button reading
     * "Fish & Chips" is stored as `button_text="Fish &amp; Chips"`. Running
     * esc_html() straight over that encodes the ampersand a second time, and the
     * page publishes the literal characters `Fish &amp; Chips`. The same applied
     * to every title, heading, author, and label the converter drew from an
     * attribute. Decoding once before escaping is what makes the round trip
     * lossless.
     *
     * Not used for values that come from WordPress itself — attachment alt text
     * and captions are already plain — because those are not double-encoded and
     * decoding them would be the mirror-image bug.
     */
    public static function text( $value ): string {
        return esc_html( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
    }

    /**
     * The same decode-then-escape, for a value going into an HTML attribute.
     */
    public static function attr( $value ): string {
        return esc_attr( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
    }

    public static function column_width( string $type ): string {
        $map = [
            '4_4'  => '100%',
            '1_2'  => '50%',
            '1_3'  => '33.33%',
            '2_3'  => '66.66%',
            '1_4'  => '25%',
            '3_4'  => '75%',
            '1_5'  => '20%',
            '2_5'  => '40%',
            '3_5'  => '60%',
            '4_5'  => '80%',
            '1_6'  => '16.66%',
            '5_6'  => '83.33%',
        ];
        return $map[ $type ] ?? '';
    }

    public static function social_links_from_attrs( array $attrs ): array {
        $networks = [ 'facebook', 'twitter', 'linkedin', 'instagram', 'youtube', 'github', 'pinterest', 'tumblr', 'dribbble' ];
        $links = [];
        foreach ( $networks as $net ) {
            $key = $net . '_url';
            if ( ! empty( $attrs[ $key ] ) ) {
                $links[ $net ] = $attrs[ $key ];
            }
        }
        return $links;
    }

    /**
     * Build a wp:social-links block with wp:social-link inner blocks.
     */
    public static function social_links( array $links ): string {
        if ( empty( $links ) ) {
            return '';
        }

        $inner = '';
        foreach ( $links as $network => $url ) {
            $inner .= D2G_Block_Builder::block( 'social-link', [ 'url' => $url, 'service' => $network ] );
        }

        $html = '<ul class="wp-block-social-links">' . "\n" . $inner . '</ul>';
        return D2G_Block_Builder::block( 'social-links', [], $html, true );
    }
}
