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
     * Normalize content before parsing.
     *
     * Line endings only. This function used to also rewrite curly-quote
     * entities to straight quote characters across the whole document, which
     * meant `&#8220;quoted&#8221;` in ordinary body text came out of a
     * conversion as `"quoted"` — a silent, unreported change to the author's
     * words. Divi's habit of encoding an *attribute's* delimiting quotes is
     * repaired in parse_attrs() instead, where the blast radius is one
     * attribute string rather than the entire page.
     *
     * The CRLF/CR collapse is kept and is deliberate: every downstream regex
     * and DOM step assumes "\n", and WordPress normalizes line endings on save
     * regardless.
     */
    private function normalize( string $content ): string {
        $content = str_replace( "\r\n", "\n", $content );
        return str_replace( "\r", "\n", $content );
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
     * Scan from the `[` at $open forward to the `]` that closes that tag.
     *
     * Quoted attribute values are opaque: a `]` inside "…" or '…' does not end
     * the tag. Every previous scanner here was a regex built on `[^\]]*`, which
     * cannot cross a `]` at all — so `[et_pb_text title="Array[0]"]` was read
     * as a tag ending at the bracket inside the title, and the leftover `"]`
     * was emitted as visible text in the converted page.
     *
     * An encoded `&quot;` opens a value the same way a literal quote does, and
     * closes one that an encoded quote opened. Counting only literal quotes in
     * a tag whose encoding stops partway through leaves the scan permanently
     * inside a value: it swallows every following `]`, runs to the end of the
     * document, and the rest of the page is emitted as raw shortcode text.
     *
     * Which delimiters close a value follows scan_value_end(), and has to: a
     * tokenizer that disagrees with the attribute reader about where a value
     * ends is how a tag comes out split down the middle.
     *
     * @return int|false Offset of the closing `]`, or false when it never closes.
     */
    private static function scan_tag_end( string $content, int $open ) {
        $len     = strlen( $content );
        $quote   = '';
        $encoded = false;

        for ( $i = $open + 1; $i < $len; $i++ ) {
            $entity = self::quote_entity_len( $content, $i );

            if ( '' !== $quote ) {
                if ( $entity && $encoded ) {
                    $quote = '';
                    $i    += $entity - 1;
                } elseif ( ! $entity && $content[ $i ] === $quote ) {
                    $quote = '';
                } elseif ( $entity ) {
                    // Encoded punctuation inside a literal-quoted value.
                    $i += $entity - 1;
                }
                continue;
            }

            if ( $entity ) {
                $quote   = '"';
                $encoded = true;
                $i      += $entity - 1;
                continue;
            }

            $ch = $content[ $i ];

            if ( '"' === $ch || "'" === $ch ) {
                $quote   = $ch;
                $encoded = false;
            } elseif ( ']' === $ch ) {
                return $i;
            } elseif ( '[' === $ch ) {
                // A new tag opened before this one closed. Treat the first as
                // literal text rather than swallowing the second.
                return false;
            }
        }

        return false;
    }

    /**
     * Find the next syntactically complete Divi tag — opening or closing.
     *
     * This is the single tokenizer every other entry point is built on, so the
     * detector, the tree parser, the closing-tag matcher and the stripper all
     * agree on exactly what counts as a tag.
     *
     * Public so that tooling outside the converter — bin/anonymize-divi.php,
     * which rewrites real Divi source in place — reads tags the same way. A
     * second tokenizer written to "just find the tags" is how the truncation
     * defect in Q24 happened, and a tool that disagrees with the parser about
     * where a tag ends would scrub the wrong span of somebody's page.
     *
     * @return array{tag: string, start: int, end: int, attrs_str: string, closing: bool, self_closing: bool}|false
     */
    public static function next_tag_span( string $content, int $offset ) {
        $len = strlen( $content );

        while ( $offset < $len ) {
            if ( ! preg_match( '#\[(/)?(' . self::TAG_NAME_PATTERN . ')#', $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
                return false;
            }

            $start   = $m[0][1];
            $closing = '' !== $m[1][0];
            $tag     = $m[2][0];
            $after   = $start + strlen( $m[0][0] );

            // The name has to be followed by something that can terminate it.
            // Without this check `[et_pb_text-custom]` would be read as an
            // `et_pb_text` tag with a stray suffix.
            $next = $after < $len ? $content[ $after ] : '';
            $end  = ( ']' === $next || '/' === $next || ( '' !== $next && ctype_space( $next ) ) )
                ? self::scan_tag_end( $content, $start )
                : false;

            if ( false === $end ) {
                $offset = $after;
                continue;
            }

            $body    = substr( $content, $after, $end - $after );
            $trimmed = rtrim( $body );

            // A trailing `/` outside quotes is the self-closing marker. It can
            // only be found after the quote-aware scan, because `src="a/"` ends
            // in a slash too.
            $self_close = ( '' !== $trimmed && '/' === substr( $trimmed, -1 ) );
            if ( $self_close ) {
                $trimmed = substr( $trimmed, 0, -1 );
            }

            return [
                'tag'          => $tag,
                'start'        => $start,
                'end'          => $end + 1,
                'attrs_str'    => trim( $trimmed ),
                'closing'      => $closing,
                'self_closing' => $self_close && ! $closing,
            ];
        }

        return false;
    }

    /**
     * Find the next Divi shortcode *opening* tag at or after $offset.
     */
    private function find_next_shortcode( string $content, int $offset ) {
        $pos = $offset;

        while ( false !== ( $span = self::next_tag_span( $content, $pos ) ) ) {
            $pos = $span['end'];

            if ( $span['closing'] ) {
                continue; // An orphan closer is text, not the start of a node.
            }

            return [
                'tag'           => $span['tag'],
                'attrs_str'     => $span['attrs_str'],
                'start'         => $span['start'],
                'end'           => $span['end'],
                'content_start' => $span['end'],
                'self_closing'  => $span['self_closing'],
            ];
        }

        return false;
    }

    /**
     * Find the matching closing tag [/tag], respecting nesting.
     */
    private function find_closing_tag( string $content, string $tag, int $offset ) {
        $depth = 1;
        $pos   = $offset;

        while ( false !== ( $span = self::next_tag_span( $content, $pos ) ) ) {
            $pos = $span['end'];

            if ( $span['tag'] !== $tag ) {
                continue;
            }

            if ( $span['closing'] ) {
                $depth--;
                if ( 0 === $depth ) {
                    return [
                        'content_end' => $span['start'],
                        'end'         => $span['end'],
                    ];
                }
                continue;
            }

            // A self-closing occurrence of the same tag must not raise the
            // depth, or the closer that follows would be matched to it instead.
            if ( ! $span['self_closing'] ) {
                $depth++;
            }
        }

        return false;
    }

    /**
     * Parse a shortcode attributes string into key => value pairs.
     */
    public static function parse_attrs_string( string $str ): array {
        return ( new self() )->parse_attrs( $str );
    }

    private function parse_attrs( string $str ): array {
        $attrs = [];
        if ( '' === $str ) {
            return $attrs;
        }

        // Divi sometimes writes an attribute's delimiting quotes as HTML
        // entities. Repaired here, on one attribute string, rather than across
        // the whole document — see normalize().
        $str = str_replace( [ '&#8220;', '&#8221;', '&#8243;' ], '"', $str );
        $str = str_replace( [ '&#8216;', '&#8217;' ], "'", $str );

        // Walked left to right rather than matched with preg_match_all, because
        // a value's opening and closing delimiter are not always the same form:
        // real exports contain
        //
        //     [et_pb_signup title=&quot;Stay Informed&quot; description=&quot;<p>…</p>
        //     " footer_content="<p>…</p>
        //     "]
        //
        // where the encoding stops partway through the tag. A pattern anchored
        // on one delimiter form reads only the half of the tag that happens to
        // match it; scanning claims each value from whichever form closes it.
        $len    = strlen( $str );
        $offset = 0;

        while ( $offset < $len && preg_match( '#([\w_-]+)\s*=\s*#', $str, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
            $key   = $m[1][0];
            $after = $m[0][1] + strlen( $m[0][0] );

            $entity = self::quote_entity_len( $str, $after );
            if ( $entity ) {
                $kind    = '"';
                $encoded = true;
                $start   = $after + $entity;
            } elseif ( isset( $str[ $after ] ) && ( '"' === $str[ $after ] || "'" === $str[ $after ] ) ) {
                $kind    = $str[ $after ];
                $encoded = false;
                $start   = $after + 1;
            } else {
                // Unquoted or truncated. Step past this `=` so the scan cannot
                // stall, and let the next key be found on its own terms.
                $offset = $after > $offset ? $after : $offset + 1;
                continue;
            }

            [ $value_end, $offset ] = self::scan_value_end( $str, $start, $kind, $encoded );

            // Matches the previous two-pass behaviour: a later double-quoted
            // value replaces an earlier one, a single-quoted value never
            // overwrites a value already claimed.
            if ( "'" !== $kind || ! isset( $attrs[ $key ] ) ) {
                $attrs[ $key ] = substr( $str, $start, $value_end - $start );
            }
        }

        return $attrs;
    }

    /**
     * Length of the double-quote entity at $i, or 0 when there is not one.
     *
     * Divi content reaches this plugin having been through storage paths that
     * encode an attribute's delimiting quotes. Recognising the encoded form as
     * a delimiter is what keeps a whole page's attributes from parsing as an
     * empty array — which is silent, total loss of every design setting, every
     * image source and every button link on the page.
     */
    private static function quote_entity_len( string $str, int $i ): int {
        // Called once per character by the two scanners below, so the cheap
        // rejection comes first: an entity starts with '&' and nothing else.
        if ( ! isset( $str[ $i ] ) || '&' !== $str[ $i ] ) {
            return 0;
        }

        foreach ( [ '&quot;' => 6, '&#34;' => 5, '&#034;' => 6 ] as $entity => $entity_len ) {
            if ( $entity === substr( $str, $i, $entity_len ) ) {
                return $entity_len;
            }
        }

        return 0;
    }

    /**
     * Scan forward to the delimiter that closes a value opened at $from.
     *
     * The form that *opened* the value decides what can close it, and the
     * asymmetry is deliberate:
     *
     *   - Opened with a literal quote, only a literal quote closes it. That
     *     keeps `button_text="He said &quot;hello&quot;"` intact — those
     *     encoded quotes are the author's punctuation, not delimiters.
     *   - Opened with `&quot;`, either form closes it, because encoding stops
     *     partway through real tags: `description=&quot;<p>…</p>\n"`.
     *
     * @return array{0: int, 1: int} Offset the value ends at, and the offset to
     *                               resume scanning from.
     */
    private static function scan_value_end( string $str, int $from, string $kind, bool $encoded ): array {
        $len = strlen( $str );

        for ( $i = $from; $i < $len; $i++ ) {
            if ( $encoded ) {
                $entity = self::quote_entity_len( $str, $i );
                if ( $entity ) {
                    return [ $i, $i + $entity ];
                }
            }

            if ( $str[ $i ] === $kind ) {
                return [ $i, $i + 1 ];
            }
        }

        // Unterminated: the value runs to the end of the attribute string.
        return [ $len, $len ];
    }

    /**
     * Check whether content contains something this parser reads as a Divi tag.
     *
     * This is the gate the convert endpoint uses before it overwrites a post,
     * so a loose match is not harmless. Testing for the bare `[et_pb_` prefix
     * matched documentation, a code sample, or a support answer that merely
     * mentions a Divi tag, and pulled that post into a destructive flow. The
     * match requires a syntactically complete opening tag — `et_pb_` plus a
     * name, followed by a real terminator: whitespace, `]`, or `/]` — so
     * `[et_pb_` on its own no longer qualifies.
     *
     * Deliberately *not* limited to the 58 tags with renderers. `[et_pb_thing]`
     * from a third-party module is Divi content, and a page made of nothing
     * else is a page this plugin should offer to convert and report the loss
     * on, rather than one it claims to see no Divi in. The check is "is this a
     * tag", not "is this a tag we know" — see the `unlisted but well-formed`
     * case in tests/run.php.
     */
    public static function has_divi_content( string $content ): bool {
        if ( false === strpos( $content, '[et_pb_' ) ) {
            return false; // Cheap rejection before running the tokenizer.
        }

        $pos = 0;
        while ( false !== ( $span = self::next_tag_span( $content, $pos ) ) ) {
            if ( ! $span['closing'] ) {
                return true;
            }
            $pos = $span['end'];
        }

        return false;
    }

    /**
     * List the distinct Divi tags a string contains.
     *
     * @return string[]
     */
    public static function found_tags( string $content ): array {
        $tags = [];
        $pos  = 0;

        while ( false !== ( $span = self::next_tag_span( $content, $pos ) ) ) {
            if ( ! $span['closing'] ) {
                $tags[ $span['tag'] ] = true;
            }
            $pos = $span['end'];
        }

        return array_keys( $tags );
    }

    /**
     * Whether this parser has a renderer registered for a tag.
     */
    public static function is_known_tag( string $tag ): bool {
        return in_array( $tag, self::$tags, true );
    }

    /**
     * Every Divi tag this plugin claims to handle.
     *
     * Exposed so the fixture suite can assert that each one is actually
     * exercised. A module with no fixture is a module a refactor can silently
     * break, and until this existed there was no way to tell which those were.
     *
     * @return string[]
     */
    public static function known_tags(): array {
        return self::$tags;
    }

    /**
     * Remove Divi shortcode syntax from a string, keeping the text inside it.
     *
     * Only used where parsing has given up — past the nesting limit — so that
     * content is still preserved without leaving shortcode text on the page.
     */
    public static function strip_divi_tags( string $content ): string {
        $out = '';
        $pos = 0;

        while ( false !== ( $span = self::next_tag_span( $content, $pos ) ) ) {
            $out .= substr( $content, $pos, $span['start'] - $pos );
            $pos  = $span['end'];
        }

        return $out . substr( $content, $pos );
    }
}
