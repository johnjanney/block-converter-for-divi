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
     *
     * Every block comment this plugin writes goes through here. That is not
     * tidiness: a block comment is an *HTML comment*, and the attribute JSON
     * inside it has to be encoded so that nothing in it can end the comment
     * early. Two renderers built their own comments by hand and skipped the
     * encoding, which is how
     *
     *     [et_pb_search placeholder="find--><img src=x onerror=alert(1)>" /]
     *
     * became
     *
     *     <!-- wp:search {"placeholder":"find--><img src=x onerror=alert(1)>"} /-->
     *
     * — a comment that terminates at the author's own `-->`, leaving an <img>
     * tag as live markup for any consumer that reads post_content as HTML.
     */
    public static function block( string $name, array $attrs = [], string $html = '', bool $has_inner = false ): string {
        // Core blocks are written without their namespace in block comments,
        // which is exactly the name passed in here.
        $block_name = $name;

        $attrs_json = '';
        if ( ! empty( $attrs ) ) {
            $attrs_json = ' ' . self::serialize_attributes( $attrs );
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
     * Encode block attributes for the inside of a block's HTML comment.
     *
     * WordPress has a function for exactly this — serialize_block_attributes()
     * — and it is used whenever it is available, so this plugin's output tracks
     * core rather than a copy of core. The fallback below is byte-identical to
     * that function and exists only for the standalone fixture suite, which
     * runs with no WordPress loaded. tests/fixtures.php asserts the two agree
     * on the characters that matter.
     *
     * The encoding is not cosmetic. `--`, `<`, `>` and `&` are escaped as JSON
     * unicode sequences because each of them can interfere with an HTML
     * comment; `\"` is escaped because the block grammar reads the JSON with a
     * regular expression that a raw escaped quote can walk out of.
     *
     * @see https://developer.wordpress.org/reference/functions/serialize_block_attributes/
     */
    public static function serialize_attributes( array $attrs ): string {
        if ( function_exists( 'serialize_block_attributes' ) ) {
            return serialize_block_attributes( $attrs );
        }

        return self::serialize_attributes_fallback( $attrs );
    }

    /**
     * The no-WordPress copy of serialize_block_attributes().
     *
     * Public only so the live suite can hold it against core's and fail if the
     * two ever disagree — a fallback nobody compares is a fallback nobody knows
     * is still correct. See tests/live/run.php.
     */
    public static function serialize_attributes_fallback( array $attrs ): string {
        $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
        $json   = (string) $encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        // The same six substitutions core makes, in core's order. Using PHP's
        // JSON_HEX_TAG/JSON_HEX_AMP instead looks equivalent and is not: PHP
        // writes `<` in upper case and core writes `<`, and a block
        // comment is compared as bytes. The live suite holds this function
        // against core's own and fails on any difference — which is how these
        // three were found after the first attempt got all of them wrong.
        return str_replace(
            [ '--', '<', '>', '&', '\\"', '\\\\' ],
            [ '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022', '\\u005c' ],
            $json
        );
    }

    /**
     * Is the WordPress running this at least the given version?
     *
     * A block's save() is JavaScript that changes between releases, and a saved
     * block only validates against the save() of the WordPress that opens it.
     * The converter therefore cannot emit one canonical markup for a plugin
     * that declares 6.1 through 7.0 — core/cover swapped the order of its two
     * background elements in 6.8, so markup that is correct for 7.0 is reported
     * as "unexpected or invalid content" on every release below it.
     *
     * D2G_Converter::block_supported() answers the related but different
     * question of whether a block exists at all. This one is about the shape of
     * a block that does.
     *
     * Outside WordPress — the fixture suite — the answer is "yes", so fixtures
     * describe current markup by default. bin/block-library-matrix.sh overrides
     * it per version through $GLOBALS['d2g_test_wp_version'], which is how the
     * two defects above were found and how they stay fixed.
     */
    public static function wp_at_least( string $version ): bool {
        if ( isset( $GLOBALS['d2g_test_wp_version'] ) && '' !== $GLOBALS['d2g_test_wp_version'] ) {
            return version_compare( (string) $GLOBALS['d2g_test_wp_version'], $version, '>=' );
        }

        if ( isset( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
            return version_compare( (string) $GLOBALS['wp_version'], $version, '>=' );
        }

        return true;
    }

    /**
     * Clean a Divi-supplied URL for storage in a block attribute.
     *
     * A block attribute is *data*, not markup, so it is sanitised rather than
     * escaped — sanitize_url() is what WordPress documents for a URL on its way
     * into the database. Doing it here also closes a mismatch that ran through
     * every media renderer: the HTML half of a block was built with esc_url(),
     * which drops `javascript:`, while the attribute half kept the raw value.
     * The block attribute is what the editor regenerates markup from, so the
     * dangerous value was the one that survived.
     *
     * Returns '' for a URL that cannot be made safe, which every caller treats
     * as "there is no URL here" rather than emitting an empty one.
     *
     * @see https://developer.wordpress.org/reference/functions/esc_url/
     */
    public static function url( $value ): string {
        $value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );

        if ( '' === trim( $value ) ) {
            return '';
        }

        if ( function_exists( 'sanitize_url' ) ) {
            return sanitize_url( $value );
        }
        if ( function_exists( 'esc_url_raw' ) ) {
            return esc_url_raw( $value );
        }

        return esc_url( $value );
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
        $url   = self::url( $url );
        $attrs = [ 'url' => $url ];

        $span_class = 'wp-block-cover__background has-background-dim-100 has-background-dim';
        $span_style = '';

        if ( '' !== $overlay ) {
            $attrs['customOverlayColor'] = $overlay;
            $span_style                  = ' style="background-color:' . esc_attr( $overlay ) . '"';
        }

        $img  = '<img class="wp-block-cover__image-background" alt="" src="' . esc_url( $url ) . '" data-object-fit="cover"/>';
        $span = '<span aria-hidden="true" class="' . $span_class . '"' . $span_style . '></span>';

        // core/cover swapped these two around in WordPress 6.8. Emitting the
        // 6.8 order on 6.1-6.7 made every converted Cover invalid there — the
        // validator stops at the first token that disagrees and reports
        // "Expected tag name `span`, instead saw `img`". Four releases of block
        // validation never saw it, because the validator only ever ran against
        // the newest block library published to npm. See
        // bin/block-library-matrix.sh.
        $background = self::wp_at_least( '6.8' ) ? $img . $span : $span . $img;

        $html = '<div class="wp-block-cover">'
            . $background
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
     * A length that is safe to write into a CSS declaration.
     *
     * The sibling of css_color(), and there for the same reason: esc_attr()
     * makes a string safe as *markup* and does nothing about a value that is
     * syntactically valid CSS. Only a number followed by a unit CSS actually
     * defines is accepted, so `20px;position:fixed` is dropped rather than
     * escaped into a declaration nobody asked for.
     *
     * `auto` is deliberately rejected. It is legal CSS for a margin, but core's
     * spacing support models a length, and a value the block cannot regenerate
     * from its own attributes is worse than one that was never mapped.
     */
    public static function css_length( $value ): string {
        $value = strtolower( trim( (string) $value ) );

        if ( '' === $value ) {
            return '';
        }
        if ( '0' === $value ) {
            return '0px';
        }
        if ( preg_match( '/^-?\d+(?:\.\d+)?(?:px|em|rem|%|vw|vh|pt|ch)$/', $value ) ) {
            return $value;
        }

        return '';
    }

    /**
     * Divi's pipe-delimited spacing value, as validated box sides.
     *
     * Divi writes `custom_padding` as `top|right|bottom|left` followed by two
     * booleans that say whether the value overrides a theme default:
     *
     *     custom_padding="54px||54px||true|false"
     *     custom_padding="12px|28px|12px|28px|true|true"
     *
     * Empty components mean "not set" and are left out rather than zeroed —
     * core omits absent sides too, and writing 0px where Divi wrote nothing
     * would flatten spacing the theme was supplying.
     *
     * @return array<string, string> Only the sides that carry a usable length.
     */
    public static function spacing_box( $value ): array {
        $parts = explode( '|', (string) $value );
        $sides = [ 'top', 'right', 'bottom', 'left' ];
        $box   = [];

        foreach ( $sides as $i => $side ) {
            $length = self::css_length( $parts[ $i ] ?? '' );
            if ( '' !== $length ) {
                $box[ $side ] = $length;
            }
        }

        return $box;
    }

    /**
     * The block attributes, classes and CSS declarations for a styled wrapper.
     *
     * One function because the declaration *order* is load-bearing and every
     * caller has to get it identical. WordPress regenerates a static block's
     * markup from its attributes and compares; a block that lists its padding
     * before its background is reported as containing unexpected content, in
     * exactly the way the Cover block's element order was. Measured against
     * core's own serializer: background first, then margin, then padding, each
     * in top/right/bottom/left order.
     *
     * `flex-basis` is not handled here — it belongs to core/column alone and
     * core emits it after everything else, so the column renderer appends it.
     *
     * @param string $bg_color Already through css_color(), or ''.
     * @param array  $margin   Already through spacing_box().
     * @param array  $padding  Already through spacing_box().
     * @return array{attrs: array, classes: string[], css: string[]}
     */
    public static function wrapper_styles( string $bg_color, array $margin = [], array $padding = [] ): array {
        $style   = [];
        $classes = [];
        $css     = [];

        if ( '' !== $bg_color ) {
            $style['color'] = [ 'background' => $bg_color ];
            $classes[]      = 'has-background';
            $css[]          = 'background-color:' . $bg_color;
        }

        $spacing = [];
        foreach ( [ 'margin' => $margin, 'padding' => $padding ] as $property => $box ) {
            if ( ! $box ) {
                continue;
            }
            $spacing[ $property ] = $box;
            foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
                if ( isset( $box[ $side ] ) ) {
                    $css[] = $property . '-' . $side . ':' . $box[ $side ];
                }
            }
        }

        if ( $spacing ) {
            $style['spacing'] = $spacing;
        }

        return [
            'attrs'   => $style ? [ 'style' => $style ] : [],
            'classes' => $classes,
            'css'     => $css,
        ];
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
            $url = self::url( $url );
            if ( '' === $url ) {
                continue;
            }
            $inner .= D2G_Block_Builder::block( 'social-link', [ 'url' => $url, 'service' => $network ] );
        }

        if ( '' === $inner ) {
            return '';
        }

        $html = '<ul class="wp-block-social-links">' . "\n" . $inner . '</ul>';
        return D2G_Block_Builder::block( 'social-links', [], $html, true );
    }
}
