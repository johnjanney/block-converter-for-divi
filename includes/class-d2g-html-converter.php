<?php
/**
 * Turns a fragment of free-form HTML into Gutenberg blocks.
 *
 * This is the single entry point for every piece of non-shortcode markup the
 * converter meets — Divi text modules, blurb and slide bodies, toggle contents,
 * table cells, quote bodies. It exists as its own class because it is the one
 * part of the conversion that is about *HTML*, not about Divi: nothing in here
 * knows what an et_pb_ tag is.
 *
 * The rule it implements is the one WordPress enforces: a block-level element
 * becomes its own block, and runs of text and inline elements between them are
 * gathered into a paragraph. Getting that wrong is what produced a paragraph
 * block holding two <p> elements — the defect behind "this block contains
 * unexpected or invalid content".
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_HTML_Converter {

    /**
     * The converter this belongs to, used to report lossy conversions.
     *
     * @var D2G_Converter
     */
    private $context;

    /**
     * HTML elements that belong inside a paragraph rather than beside one.
     *
     * Used to decide where one block ends and the next begins: a run of text
     * and inline elements becomes a single paragraph block, and anything else
     * becomes a block of its own.
     */
    private static $inline_tags = [
        'a', 'abbr', 'acronym', 'b', 'bdi', 'bdo', 'big', 'br', 'cite', 'code',
        'data', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd', 'label', 'mark',
        'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small', 'span', 'strong', 'sub',
        'sup', 'time', 'tt', 'u', 'var', 'wbr',
    ];

    public function __construct( D2G_Converter $context ) {
        $this->context = $context;
    }

    /**
     * Convert a fragment of HTML into one Gutenberg block per top-level element.
     *
     * This is the single entry point for every piece of free-form HTML the
     * converter meets — Divi text modules, blurb and slide bodies, toggle
     * contents, and so on.
     *
     * Earlier versions wrapped a whole fragment in a single paragraph block
     * after stripping one outer <p> pair, so `<p>One</p><p>Two</p>` became one
     * paragraph block containing two paragraph elements. WordPress validates a
     * static block by re-running its save function over the parsed attributes
     * and comparing the result with the stored markup, and a paragraph block
     * whose body is not exactly one <p> fails that comparison — which is what
     * produced "this block contains unexpected or invalid content".
     *
     * So: block-level elements each become their own block, and runs of text
     * and inline elements between them are gathered into a paragraph.
     */
    public function to_blocks( string $html, array $attrs, int $depth = 0 ): string {
        if ( '' === trim( $html ) ) {
            return '';
        }

        // If the HTML contains iframes/embeds, extract and convert them first
        // before DOM parsing, which can corrupt iframe tags.
        if ( preg_match( '#<(?:iframe|embed|object)\b#i', $html ) ) {
            return $this->with_embeds( $html, $attrs );
        }

        // Nothing that needs structural parsing: one paragraph.
        if ( ! preg_match( '#<[a-z!/]#i', $html ) ) {
            return D2G_Block_Builder::paragraph( $html, $attrs );
        }

        // Guard against a pathological nesting depth in malformed source.
        if ( $depth > 10 ) {
            return D2G_Block_Builder::block( 'html', [], $html );
        }

        $root = $this->load_html_fragment( $html );
        if ( null === $root ) {
            // No DOM extension, or the fragment could not be parsed. Preserving
            // it verbatim in a core/html block loses editability but never
            // loses content.
            return D2G_Block_Builder::block( 'html', [], $html );
        }

        $output = '';
        $buffer = ''; // Accumulated inline content awaiting a paragraph.

        foreach ( $root->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $buffer .= $root->ownerDocument->saveHTML( $child );
                continue;
            }

            if ( XML_COMMENT_NODE === $child->nodeType ) {
                $output .= $this->comment_block( $child->nodeValue, $buffer, $attrs );
                $buffer  = '';
                continue;
            }

            if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                continue;
            }

            $tag_name = strtolower( $child->nodeName );

            if ( in_array( $tag_name, self::$inline_tags, true ) ) {
                $buffer .= $root->ownerDocument->saveHTML( $child );
                continue;
            }

            // A block-level element closes whatever paragraph was building up.
            $output .= D2G_Block_Builder::paragraph( $buffer, $attrs );
            $buffer  = '';
            $output .= $this->block_for_element( $child, $attrs, $depth );
        }

        $output .= D2G_Block_Builder::paragraph( $buffer, $attrs );

        return $output;
    }

    /**
     * Convert text content that contains embedded media (iframes, video, embeds).
     * Splits around embed tags so text becomes paragraphs and embeds become embed/html blocks.
     */
    public function with_embeds( string $content, array $attrs ): string {
        $output = '';

        // Split content around iframe/embed/object/video tags, keeping the tags.
        $parts = preg_split(
            '#(<(?:iframe|embed|object|video)\b[^>]*(?:/>|>(?:.*?)</(?:iframe|embed|object|video)>))#is',
            $content,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }

            // Check if this part is an embed tag.
            if ( preg_match( '#^<(iframe|embed|object|video)\b#i', $part ) ) {
                $output .= $this->embed_tag( $part );
            } else {
                $output .= $this->to_blocks( $part, $attrs );
            }
        }

        return $output;
    }

    /**
     * Convert an iframe/embed/object/video HTML tag into the appropriate Gutenberg block.
     */
    public function embed_tag( string $tag_html ): string {
        // Try to extract src from iframe.
        if ( preg_match( '#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $tag_html, $m ) ) {
            $src = $m[1];

            // YouTube embed.
            if ( preg_match( '#(?:youtube\.com/embed/|youtube-nocookie\.com/embed/)([a-zA-Z0-9_-]+)#', $src, $ym ) ) {
                $watch_url = 'https://www.youtube.com/watch?v=' . $ym[1];
                $html = '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $watch_url ) . "\n" . '</div></figure>';
                return D2G_Block_Builder::block( 'embed', [ 'url' => $watch_url, 'type' => 'video', 'providerNameSlug' => 'youtube' ], $html );
            }

            // Vimeo embed.
            if ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $src, $vm ) ) {
                $vimeo_url = 'https://vimeo.com/' . $vm[1];
                $html = '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $vimeo_url ) . "\n" . '</div></figure>';
                return D2G_Block_Builder::block( 'embed', [ 'url' => $vimeo_url, 'type' => 'video', 'providerNameSlug' => 'vimeo' ], $html );
            }

            // Other iframe embeds — preserve as HTML block.
            return D2G_Block_Builder::block( 'html', [], $tag_html );
        }

        // Video tag with src.
        if ( preg_match( '#<video\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $tag_html, $m ) ) {
            $src = $m[1];
            $html = '<figure class="wp-block-video"><video controls src="' . esc_url( $src ) . '"></video></figure>';
            return D2G_Block_Builder::block( 'video', [ 'src' => $src ], $html );
        }

        // Fallback: preserve as custom HTML block.
        return D2G_Block_Builder::block( 'html', [], $tag_html );
    }

    /**
     * Preserve an HTML comment that appeared between block-level elements.
     *
     * html_to_blocks() used to walk only text and element nodes, so every
     * `<!-- … -->` in a converted page was dropped without a word. Comments
     * carry real intent — tracking snippets, editor notes, third-party markers —
     * and losing them silently violates the no-content-loss rule.
     *
     * The one comment shape that cannot be round-tripped is a Gutenberg
     * delimiter: re-emitting `<!-- wp:… -->` would make WordPress read it as a
     * block boundary and corrupt the document. That case is reported instead.
     */
    private function comment_block( string $data, string $pending, array $attrs ): string {
        $output = D2G_Block_Builder::paragraph( $pending, $attrs );

        if ( preg_match( '#^\s*/?wp:#', $data ) ) {
            $this->context->add_warning(
                'html-comment',
                __( 'A block-delimiter comment was found inside Divi content and removed. Re-emitting it would have corrupted the converted document.', 'block-converter-for-divi' )
            );
            return $output;
        }

        return $output . D2G_Block_Builder::block( 'html', [], '<!--' . $data . '-->' );
    }

    /**
     * Turn one block-level DOM element into its matching Gutenberg block.
     */
    private function block_for_element( DOMNode $el, array $attrs, int $depth ): string {
        $tag_name   = strtolower( $el->nodeName );
        $inner_html = $this->dom_inner_html( $el );
        $align      = D2G_Style_Mapper::text_align_class( $attrs );

        if ( preg_match( '/^h([1-6])$/', $tag_name, $hm ) ) {
            $level       = (int) $hm[1];
            $block_attrs = [ 'level' => $level ];
            $classes     = [];

            if ( $align ) {
                // The class and the block attribute have to agree: core/heading
                // regenerates the class from textAlign, so emitting one without
                // the other is exactly the mismatch that invalidates the block.
                $block_attrs['textAlign'] = $attrs['text_orientation'];
                $classes[]                = $align;
            }

            // A heading takes its typography from Divi's header_* settings,
            // a paragraph from body_*. That split is Divi's, and it is why
            // text_styles() takes a prefix rather than guessing.
            $styles       = D2G_Block_Builder::text_styles( $attrs, 'header_' );
            $block_attrs += $styles['attrs'];
            $classes      = array_merge( $classes, $styles['classes'] );

            $cls   = $classes ? ' class="' . implode( ' ', $classes ) . '"' : '';
            $style = $styles['css'] ? ' style="' . esc_attr( implode( ';', $styles['css'] ) ) . '"' : '';

            return D2G_Block_Builder::block(
                'heading',
                $block_attrs,
                '<' . $tag_name . $cls . $style . '>' . $inner_html . '</' . $tag_name . '>'
            );
        }

        switch ( $tag_name ) {
            case 'p':
                return D2G_Block_Builder::paragraph( $inner_html, $attrs );

            case 'ul':
            case 'ol':
                return $this->list_block( $el, $attrs, $depth );

            case 'blockquote':
                return $this->quote_block( $el, $attrs, $depth );

            case 'table':
                return $this->table_block( $el );

            case 'pre':
                return D2G_Block_Builder::block(
                    'preformatted',
                    [],
                    '<pre class="wp-block-preformatted">' . $inner_html . '</pre>'
                );

            case 'hr':
                return D2G_Block_Builder::block(
                    'separator',
                    [],
                    '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
                );

            case 'div':
            case 'section':
            case 'article':
                return $this->to_blocks( $inner_html, $attrs, $depth + 1 );

            default:
                return D2G_Block_Builder::block( 'html', [], $el->ownerDocument->saveHTML( $el ) );
        }
    }

    /**
     * Emit a core/list block with one core/list-item per <li>.
     *
     * core/list has held its items as inner blocks since WordPress 6.1 — not
     * 6.0, which this comment claimed until the version matrix measured it.
     * core/list-item simply does not exist on 6.0, so every converted list
     * showed "your site doesn't include support for this block", once per item.
     * That mistake set the plugin's declared minimum for two releases; the floor
     * is now 6.1. See bin/wp-matrix.sh.
     *
     * A bare <ul><li>…</li></ul> body only survives through a deprecation path.
     */
    private function list_block( DOMNode $el, array $attrs, int $depth ): string {
        $tag_name    = strtolower( $el->nodeName );
        $ordered     = ( 'ol' === $tag_name );
        $block_attrs = $ordered ? [ 'ordered' => true ] : [];

        $collected = [];
        $previous  = null;

        foreach ( $el->childNodes as $child ) {
            if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                continue;
            }

            $child_name = strtolower( $child->nodeName );

            if ( in_array( $child_name, [ 'ul', 'ol' ], true ) ) {
                // A list nested directly inside a list rather than inside an
                // <li>. That is invalid HTML which authors write constantly —
                // every browser renders it as a sub-list of the item above —
                // and this loop used to skip it, deleting the whole sub-tree
                // without a word. One real page lost 173 words and 10 links
                // that way, the entire press-coverage section of a release.
                $stray = $this->list_block( $child, $attrs, $depth + 1 );
                if ( '' === $stray ) {
                    continue;
                }

                if ( null === $previous ) {
                    // Nothing to hang it on: keep it as an item of its own
                    // rather than drop it.
                    $collected[] = [
                        'inline' => '',
                        'nested' => $stray,
                    ];
                    $previous = array_key_last( $collected );
                } else {
                    $collected[ $previous ]['nested'] .= $stray;
                }

                continue;
            }

            if ( 'li' !== $child_name ) {
                continue;
            }

            $item_inline = '';
            $item_nested = '';

            foreach ( $child->childNodes as $part ) {
                if ( XML_ELEMENT_NODE === $part->nodeType
                    && in_array( strtolower( $part->nodeName ), [ 'ul', 'ol' ], true )
                ) {
                    // A nested list is its own core/list block inside the item.
                    $item_nested .= $this->list_block( $part, $attrs, $depth + 1 );
                    continue;
                }
                $item_inline .= $el->ownerDocument->saveHTML( $part );
            }

            $collected[] = [
                'inline' => $item_inline,
                'nested' => $item_nested,
            ];
            $previous = array_key_last( $collected );
        }

        $items = '';
        foreach ( $collected as $item ) {
            $item_html = '<li>' . trim( $item['inline'] );
            if ( '' !== $item['nested'] ) {
                $item_html .= "\n" . $item['nested'];
            }
            $item_html .= '</li>';

            $items .= D2G_Block_Builder::block( 'list-item', [], $item_html );
        }

        if ( '' === $items ) {
            return '';
        }

        $html = '<' . $tag_name . ' class="wp-block-list">' . "\n" . $items . '</' . $tag_name . '>';
        return D2G_Block_Builder::block( 'list', $block_attrs, $html, true );
    }

    /**
     * Emit a core/quote block with its body as inner blocks.
     */
    private function quote_block( DOMNode $el, array $attrs, int $depth ): string {
        $body = '';
        $cite = '';

        foreach ( $el->childNodes as $part ) {
            if ( XML_ELEMENT_NODE === $part->nodeType && 'cite' === strtolower( $part->nodeName ) ) {
                $cite = $this->dom_inner_html( $part );
                continue;
            }
            $body .= $el->ownerDocument->saveHTML( $part );
        }

        // core/quote holds its body as inner blocks and its citation as a
        // sourced <cite>, so the body has to be real blocks, not loose markup.
        $inner = $this->to_blocks( $body, [], $depth + 1 );
        if ( '' === trim( $inner ) ) {
            return '';
        }

        $html = '<blockquote class="wp-block-quote">' . "\n" . $inner;
        if ( '' !== trim( $cite ) ) {
            $html .= '<cite>' . trim( $cite ) . '</cite>';
        }
        $html .= '</blockquote>';

        return D2G_Block_Builder::block( 'quote', [], $html, true );
    }

    /**
     * Emit a core/table block, or preserve the table verbatim when core/table
     * would not round-trip it.
     *
     * core/table stores its rows as sourced attributes selected with
     * `tbody tr`, and regenerates plain <td>/<th> cells from them. Rows outside
     * a <tbody>, or cells carrying attributes core does not model, would be
     * dropped or rewritten on regeneration — which is a validation failure and,
     * worse, silent content loss. Those tables stay as core/html.
     */
    private function table_block( DOMNode $el ): string {
        $doc  = $el->ownerDocument;
        $html = $doc->saveHTML( $el );

        // Any attribute on the table or on a row/cell is a signal that core
        // would not reproduce this table faithfully.
        foreach ( $el->getElementsByTagName( '*' ) as $descendant ) {
            if ( $descendant->hasAttributes() ) {
                $this->context->add_warning(
                    'table',
                    __( 'A table used HTML attributes that the core Table block does not store. It was preserved as a Custom HTML block instead.', 'block-converter-for-divi' )
                );
                return D2G_Block_Builder::block( 'html', [], $html );
            }
        }
        if ( $el->hasAttributes() ) {
            return D2G_Block_Builder::block( 'html', [], $html );
        }

        $sections = '';
        $loose    = '';
        foreach ( $el->childNodes as $child ) {
            if ( XML_ELEMENT_NODE !== $child->nodeType ) {
                continue;
            }
            $name = strtolower( $child->nodeName );
            if ( in_array( $name, [ 'thead', 'tbody', 'tfoot' ], true ) ) {
                $sections .= $doc->saveHTML( $child );
            } elseif ( 'tr' === $name ) {
                $loose .= $doc->saveHTML( $child );
            } else {
                // Anything else under <table> — <caption>, <colgroup> — has no
                // home in the rows this method copies, so building a core/table
                // from what is left would silently delete it. A table captioned
                // "Rates" came out with the word "Rates" nowhere in the output.
                // Preserve the table whole instead.
                $this->context->add_warning(
                    'table',
                    sprintf(
                        /* translators: %s: HTML element name, e.g. caption. */
                        __( 'A table contained a <%s> element that the core Table block does not store. The table was preserved as a Custom HTML block so nothing was lost.', 'block-converter-for-divi' ),
                        $name
                    )
                );
                return D2G_Block_Builder::block( 'html', [], $html );
            }
        }

        if ( '' !== $loose ) {
            // Rows written straight into <table> are invisible to core's
            // `tbody tr` selector, so give them the tbody they need.
            $sections .= '<tbody>' . $loose . '</tbody>';
        }

        if ( '' === $sections ) {
            return D2G_Block_Builder::block( 'html', [], $html );
        }

        // hasFixedLayout is stated rather than left to the block default, which
        // has changed between WordPress versions and controls a class name.
        return D2G_Block_Builder::block(
            'table',
            [ 'hasFixedLayout' => false ],
            '<figure class="wp-block-table"><table>' . $sections . '</table></figure>'
        );
    }

    /**
     * Parse an HTML fragment and return the wrapper element holding its nodes.
     *
     * The `<?xml encoding="UTF-8">` prologue is what keeps multibyte content
     * intact. The previous implementation instead ran the fragment through
     * mb_convert_encoding( …, 'HTML-ENTITIES', … ), which requires the mbstring
     * extension that the plugin never declared, and whose 'HTML-ENTITIES'
     * encoding PHP 8.2 deprecates. The prologue does the same job with no
     * extension requirement and no deprecation, and leaves characters as
     * characters rather than numeric entities.
     *
     * @return DOMNode|null Null when the fragment cannot be parsed.
     */
    private function load_html_fragment( string $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return null;
        }

        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors( true );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return null;
        }

        $root = $dom->getElementsByTagName( 'div' )->item( 0 );
        return $root ? $root : null;
    }

    private function dom_inner_html( DOMNode $node ): string {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }
}
