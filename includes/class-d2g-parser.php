<?php
/**
 * Divi shortcode parser.
 *
 * Parses nested Divi shortcodes into a tree of associative arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Parser {

    /**
     * All known Divi shortcode tags.
     */
    private static $tags = [
        'et_pb_section',
        'et_pb_row',
        'et_pb_row_inner',
        'et_pb_column',
        'et_pb_column_inner',
        'et_pb_text',
        'et_pb_image',
        'et_pb_button',
        'et_pb_video',
        'et_pb_blurb',
        'et_pb_slider',
        'et_pb_slide',
        'et_pb_toggle',
        'et_pb_accordion',
        'et_pb_tabs',
        'et_pb_tab',
        'et_pb_cta',
        'et_pb_divider',
        'et_pb_gallery',
        'et_pb_blog',
        'et_pb_contact_form',
        'et_pb_contact_field',
        'et_pb_sidebar',
        'et_pb_testimonial',
        'et_pb_pricing_tables',
        'et_pb_pricing_table',
        'et_pb_pricing_item',  // alias used inside pricing tables
        'et_pb_counter',
        'et_pb_counters',
        'et_pb_number_counter',
        'et_pb_circle_counter',
        'et_pb_social_media_follow',
        'et_pb_social_media_follow_network',
        'et_pb_map',
        'et_pb_map_pin',
        'et_pb_code',
        'et_pb_signup',
        'et_pb_login',
        'et_pb_portfolio',
        'et_pb_filterable_portfolio',
        'et_pb_fullwidth_header',
        'et_pb_fullwidth_image',
        'et_pb_fullwidth_slider',
        'et_pb_fullwidth_code',
        'et_pb_fullwidth_menu',
        'et_pb_fullwidth_map',
        'et_pb_fullwidth_portfolio',
        'et_pb_fullwidth_post_slider',
        'et_pb_post_slider',
        'et_pb_audio',
        'et_pb_team_member',
        'et_pb_shop',
        'et_pb_search',
        'et_pb_menu',
        'et_pb_post_title',
        'et_pb_comments',
        'et_pb_video_slider',
        'et_pb_video_slider_item',
    ];

    /**
     * How deep the shortcode tree may nest before parsing stops.
     *
     * Each level keeps its own copy of its inner span, so a runaway nesting
     * depth in malformed source costs both time and memory quadratically. Real
     * Divi layouts nest about six deep (section › row › column › module).
     */
    const MAX_DEPTH = 32;

    /**
     * Parse a Divi content string into a tree of nodes.
     *
     * Each node is: [
     *   'tag'      => 'et_pb_text',
     *   'attrs'    => [ 'background_color' => '#fff', ... ],
     *   'content'  => 'inner html or text',
     *   'children' => [ ...child nodes... ],
     * ]
     */
    public function parse( string $content ): array {
        $content = $this->normalize( $content );
        return $this->parse_nodes( $content );
    }

    /**
     * Normalize content: fix encoding issues, trim whitespace.
     */
    private function normalize( string $content ): string {
        // Divi sometimes double-encodes quotes.
        $content = str_replace( [ '&#8220;', '&#8221;', '&#8243;' ], '"', $content );
        $content = str_replace( [ '&#8216;', '&#8217;' ], "'", $content );
        // Normalize line breaks.
        $content = str_replace( "\r\n", "\n", $content );
        $content = str_replace( "\r", "\n", $content );
        return $content;
    }

    /**
     * Recursively parse shortcode nodes from a content string.
     */
    private function parse_nodes( string $content, int $depth = 0 ): array {
        $nodes = [];

        // Past the depth limit the remaining span is kept as text rather than
        // parsed further. Its shortcode syntax is stripped on the way, because
        // leaving raw [et_pb_*] markup in a converted post is the one thing
        // conversion exists to prevent — the words survive, the tags do not.
        if ( $depth > self::MAX_DEPTH ) {
            $remaining = trim( self::strip_divi_tags( $content ) );
            return '' === $remaining ? [] : [
                [
                    'tag'      => '__text__',
                    'attrs'    => [],
                    'content'  => $remaining,
                    'children' => [],
                ],
            ];
        }

        $offset = 0;
        $length = strlen( $content );

        while ( $offset < $length ) {
            // Find the next Divi shortcode opening tag.
            $next = $this->find_next_shortcode( $content, $offset );

            if ( false === $next ) {
                // Remaining content is plain text/HTML.
                $remaining = trim( substr( $content, $offset ) );
                if ( '' !== $remaining ) {
                    $nodes[] = [
                        'tag'      => '__text__',
                        'attrs'    => [],
                        'content'  => $remaining,
                        'children' => [],
                    ];
                }
                break;
            }

            // Any text before this shortcode.
            if ( $next['start'] > $offset ) {
                $before = trim( substr( $content, $offset, $next['start'] - $offset ) );
                if ( '' !== $before ) {
                    $nodes[] = [
                        'tag'      => '__text__',
                        'attrs'    => [],
                        'content'  => $before,
                        'children' => [],
                    ];
                }
            }

            $tag   = $next['tag'];
            $attrs = $this->parse_attrs( $next['attrs_str'] );

            if ( $next['self_closing'] ) {
                $nodes[] = [
                    'tag'      => $tag,
                    'attrs'    => $attrs,
                    'content'  => '',
                    'children' => [],
                ];
                $offset = $next['end'];
            } else {
                // Find the matching closing tag, respecting nesting.
                $close = $this->find_closing_tag( $content, $tag, $next['content_start'] );
                if ( false === $close ) {
                    // Unmatched; treat the rest as content.
                    $inner = substr( $content, $next['content_start'] );
                    $nodes[] = [
                        'tag'      => $tag,
                        'attrs'    => $attrs,
                        'content'  => $inner,
                        'children' => $this->parse_nodes( $inner, $depth + 1 ),
                    ];
                    break;
                }

                $inner = substr( $content, $next['content_start'], $close['content_end'] - $next['content_start'] );
                $children = $this->parse_nodes( $inner, $depth + 1 );

                // Always preserve the raw inner content so that modules
                // like et_pb_text can access HTML (iframes, embeds, images)
                // that may be mixed with or without child shortcodes.
                $nodes[] = [
                    'tag'      => $tag,
                    'attrs'    => $attrs,
                    'content'  => $inner,
                    'children' => $children,
                ];

                $offset = $close['end'];
            }
        }

        return $nodes;
    }

    /**
     * Pattern matching any syntactically complete Divi shortcode tag name.
     *
     * Deliberately broader than self::$tags. The tokenizer used to recognise
     * only the tags it had a renderer for, which meant a module outside that
     * list — a newer Divi release, a third-party module, an Extra or Theme
     * Builder tag — was never tokenized at all. It fell through as plain text
     * and was written into the converted post verbatim, so the page ended up
     * displaying "[et_pb_whatever]" once Divi was gone.
     *
     * Recognising the shape and letting convert_unknown() handle the ones with
     * no mapping means their content survives and the user gets told, instead.
     */
    const TAG_NAME_PATTERN = 'et_pb_[a-z0-9_]+';

    /**
     * Find the next Divi shortcode tag at or after $offset.
     */
    private function find_next_shortcode( string $content, int $offset ) {
        // Match [tag ...] or [tag ... /]
        //
        // The attribute group is lazy. Greedy, it swallowed the closing slash
        // of every self-closing tag that had attributes — so
        // `[et_pb_image src="…" /]` was read as an *opening* tag, no
        // `[/et_pb_image]` was ever found, and the "unmatched tag" branch
        // absorbed the entire rest of the post as that image's content. Every
        // module after a self-closing one silently disappeared.
        //
        // `[^\]]` cannot cross a `]`, so the match always ends at the first
        // one; laziness only decides whether a trailing `/` lands in the
        // attribute group or in the self-closing group, which is the question
        // that matters.
        $pattern = '#\[(' . self::TAG_NAME_PATTERN . ')((?:\s+[^\]]*?)?)\s*(/)?]#';

        if ( ! preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
            return false;
        }

        $full_match  = $m[0][0];
        $start       = $m[0][1];
        $tag         = $m[1][0];
        $attrs_str   = isset( $m[2] ) ? trim( $m[2][0] ) : '';
        $self_close  = ! empty( $m[3][0] );
        $end         = $start + strlen( $full_match );

        return [
            'tag'           => $tag,
            'attrs_str'     => $attrs_str,
            'start'         => $start,
            'end'           => $end,
            'content_start' => $end,
            'self_closing'  => $self_close,
        ];
    }

    /**
     * Find the matching closing tag [/tag], respecting nesting.
     */
    private function find_closing_tag( string $content, string $tag, int $offset ) {
        // A self-closing occurrence of the same tag must not raise the depth,
        // or the closer that follows would be matched to it instead.
        $open_pattern  = '#\[' . preg_quote( $tag, '#' ) . '(?:\s[^\]]*?)?(?<!/)]#';
        $close_pattern = '#\[/' . preg_quote( $tag, '#' ) . ']#';

        $depth = 1;
        $pos   = $offset;
        $len   = strlen( $content );

        while ( $pos < $len && $depth > 0 ) {
            $next_open  = preg_match( $open_pattern, $content, $om, PREG_OFFSET_CAPTURE, $pos ) ? $om[0][1] : PHP_INT_MAX;
            $next_close = preg_match( $close_pattern, $content, $cm, PREG_OFFSET_CAPTURE, $pos ) ? $cm[0][1] : PHP_INT_MAX;

            if ( $next_close === PHP_INT_MAX ) {
                return false; // No closing tag found.
            }

            if ( $next_open < $next_close ) {
                $depth++;
                $pos = $next_open + strlen( $om[0][0] );
            } else {
                $depth--;
                if ( 0 === $depth ) {
                    return [
                        'content_end' => $next_close,
                        'end'         => $next_close + strlen( $cm[0][0] ),
                    ];
                }
                $pos = $next_close + strlen( $cm[0][0] );
            }
        }

        return false;
    }

    /**
     * Parse a shortcode attributes string into key => value pairs.
     */
    private function parse_attrs( string $str ): array {
        $attrs = [];
        if ( '' === $str ) {
            return $attrs;
        }

        // Match key="value" pairs. Divi uses double quotes.
        if ( preg_match_all( '#([\w_-]+)\s*=\s*"([^"]*)"#s', $str, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $attrs[ $m[1] ] = $m[2];
            }
        }

        // Also match single-quoted values.
        if ( preg_match_all( "#([\w_-]+)\s*=\s*'([^']*)'#s", $str, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                if ( ! isset( $attrs[ $m[1] ] ) ) {
                    $attrs[ $m[1] ] = $m[2];
                }
            }
        }

        return $attrs;
    }

    /**
     * Check whether content contains a Divi shortcode this parser knows.
     *
     * This is the gate the convert endpoint uses before it overwrites a post,
     * so a loose match is not harmless. Testing for the bare `[et_pb_` prefix
     * matched documentation, a code sample, or a support answer that merely
     * mentions a Divi tag, and pulled that post into a destructive flow. The
     * match now requires a complete, known tag followed by a real terminator —
     * whitespace, `]`, or `/]` — so `[et_pb_` on its own no longer qualifies.
     */
    public static function has_divi_content( string $content ): bool {
        if ( false === strpos( $content, '[et_pb_' ) ) {
            return false; // Cheap rejection before running the pattern.
        }

        return (bool) preg_match( '#\[' . self::TAG_NAME_PATTERN . '(?:\s[^\]]*)?\s*/?\]#', $content );
    }

    /**
     * List the distinct Divi tags a string contains.
     *
     * @return string[]
     */
    public static function found_tags( string $content ): array {
        if ( ! preg_match_all( '#\[(' . self::TAG_NAME_PATTERN . ')(?:\s[^\]]*)?\s*/?\]#', $content, $m ) ) {
            return [];
        }
        return array_values( array_unique( $m[1] ) );
    }

    /**
     * Whether this parser has a renderer registered for a tag.
     */
    public static function is_known_tag( string $tag ): bool {
        return in_array( $tag, self::$tags, true );
    }

    /**
     * Remove Divi shortcode syntax from a string, keeping the text inside it.
     *
     * Only used where parsing has given up — past the nesting limit — so that
     * content is still preserved without leaving shortcode text on the page.
     */
    public static function strip_divi_tags( string $content ): string {
        return (string) preg_replace(
            '#\[/?' . self::TAG_NAME_PATTERN . '(?:\s[^\]]*)?\s*/?\]#',
            '',
            $content
        );
    }
}
