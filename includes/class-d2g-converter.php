<?php
/**
 * Converts parsed Divi shortcode trees into Gutenberg block markup.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Converter {

    private $parser;

    /**
     * Modules that could not be carried over faithfully in the last run.
     *
     * Collected rather than silently dropped so the preview can tell the user
     * what will need rebuilding by hand before they commit to a conversion.
     *
     * @var array<int, array{module: string, message: string}>
     */
    private $warnings = [];

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

    public function __construct() {
        $this->parser = new D2G_Parser();
    }

    /**
     * Convert Divi shortcode content to Gutenberg block markup.
     */
    public function convert( string $content ): string {
        $this->warnings = [];

        if ( ! D2G_Parser::has_divi_content( $content ) ) {
            return $content;
        }

        $tree   = $this->parser->parse( $content );
        $output = $this->render_nodes( $tree );

        // Clean up excessive whitespace.
        $output = preg_replace( "/\n{3,}/", "\n\n", $output );
        return trim( $output );
    }

    /**
     * Modules from the last convert() call that need manual attention.
     *
     * @return array<int, array{module: string, message: string}>
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Record a module that converted lossily or not at all.
     */
    private function add_warning( string $module, string $message ) {
        foreach ( $this->warnings as $existing ) {
            if ( $existing['module'] === $module && $existing['message'] === $message ) {
                return; // One entry per distinct problem, not one per instance.
            }
        }
        $this->warnings[] = [
            'module'  => $module,
            'message' => $message,
        ];
    }

    /**
     * Whether a core block is actually registered on this WordPress version.
     *
     * The converter can emit blocks that only exist on newer WordPress —
     * core/details arrived in 6.3. Emitting one on an older install leaves the
     * user with a "block not found" placeholder, so the renderer asks first and
     * falls back to blocks that have always existed.
     */
    private function block_supported( string $block_name ): bool {
        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
            // Outside WordPress (fixture tests): assume a current install.
            return true;
        }
        return WP_Block_Type_Registry::get_instance()->is_registered( $block_name );
    }

    /**
     * Render an array of parsed nodes into Gutenberg markup.
     */
    private function render_nodes( array $nodes ): string {
        $html = '';
        foreach ( $nodes as $node ) {
            $html .= $this->render_node( $node );
        }
        return $html;
    }

    /**
     * Render a single parsed node.
     */
    private function render_node( array $node ): string {
        $tag = $node['tag'];

        switch ( $tag ) {
            case '__text__':
                return $this->convert_text_node( $node );

            // Layout containers.
            case 'et_pb_section':
                return $this->convert_section( $node );
            case 'et_pb_row':
            case 'et_pb_row_inner':
                return $this->convert_row( $node );
            case 'et_pb_column':
            case 'et_pb_column_inner':
                return $this->convert_column( $node );

            // Content modules.
            case 'et_pb_text':
                return $this->convert_text( $node );
            case 'et_pb_image':
            case 'et_pb_fullwidth_image':
                return $this->convert_image( $node );
            case 'et_pb_button':
                return $this->convert_button( $node );
            case 'et_pb_video':
                return $this->convert_video( $node );
            case 'et_pb_blurb':
                return $this->convert_blurb( $node );
            case 'et_pb_cta':
                return $this->convert_cta( $node );
            case 'et_pb_divider':
                return $this->convert_divider( $node );

            // Headers.
            case 'et_pb_fullwidth_header':
                return $this->convert_fullwidth_header( $node );

            // Gallery.
            case 'et_pb_gallery':
                return $this->convert_gallery( $node );

            // Accordion / Toggles.
            case 'et_pb_accordion':
                return $this->convert_accordion( $node );
            case 'et_pb_toggle':
                return $this->convert_toggle( $node );

            // Tabs.
            case 'et_pb_tabs':
                return $this->convert_tabs( $node );
            case 'et_pb_tab':
                return $this->convert_tab( $node );

            // Slider.
            case 'et_pb_slider':
            case 'et_pb_fullwidth_slider':
            case 'et_pb_post_slider':
            case 'et_pb_fullwidth_post_slider':
                return $this->convert_slider( $node );
            case 'et_pb_slide':
                return $this->convert_slide( $node );

            // Testimonial.
            case 'et_pb_testimonial':
                return $this->convert_testimonial( $node );

            // Team member.
            case 'et_pb_team_member':
                return $this->convert_team_member( $node );

            // Pricing tables.
            case 'et_pb_pricing_tables':
                return $this->convert_pricing_tables( $node );
            case 'et_pb_pricing_table':
                return $this->convert_pricing_table( $node );
            case 'et_pb_pricing_item':
                // Normally consumed by convert_pricing_table(); reachable only
                // when an item appears outside a table.
                return $this->convert_pricing_items( [ $node ] );

            // Counters / Number counter.
            case 'et_pb_counters':
                return $this->convert_counters( $node );
            case 'et_pb_counter':
                return $this->convert_counter( $node );
            case 'et_pb_number_counter':
                return $this->convert_number_counter( $node );
            case 'et_pb_circle_counter':
                return $this->convert_circle_counter( $node );

            // Social media.
            case 'et_pb_social_media_follow':
                return $this->convert_social_follow( $node );
            case 'et_pb_social_media_follow_network':
                return $this->convert_social_network( $node );

            // Map.
            case 'et_pb_map':
            case 'et_pb_fullwidth_map':
                return $this->convert_map( $node );

            // Code module.
            case 'et_pb_code':
            case 'et_pb_fullwidth_code':
                return $this->convert_code( $node );

            // Contact form.
            case 'et_pb_contact_form':
                return $this->convert_contact_form( $node );

            // Audio.
            case 'et_pb_audio':
                return $this->convert_audio( $node );

            // Sidebar.
            case 'et_pb_sidebar':
                return $this->convert_sidebar( $node );

            // Blog.
            case 'et_pb_blog':
                return $this->convert_blog( $node );

            // Signup / Login.
            case 'et_pb_signup':
                return $this->convert_signup( $node );
            case 'et_pb_login':
                return $this->convert_login( $node );

            // Post title.
            case 'et_pb_post_title':
                return $this->convert_post_title( $node );

            // Menu.
            case 'et_pb_menu':
            case 'et_pb_fullwidth_menu':
                return $this->convert_menu( $node );

            // Comments.
            case 'et_pb_comments':
                return $this->convert_comments( $node );

            // Search.
            case 'et_pb_search':
                return $this->convert_search( $node );

            // Portfolio.
            case 'et_pb_portfolio':
            case 'et_pb_filterable_portfolio':
            case 'et_pb_fullwidth_portfolio':
                return $this->convert_portfolio( $node );

            // Video slider.
            case 'et_pb_video_slider':
                return $this->convert_video_slider( $node );

            // Shop.
            case 'et_pb_shop':
                return $this->convert_shop( $node );

            default:
                // Unknown Divi module — render children or content as-is.
                return $this->convert_unknown( $node );
        }
    }

    // =========================================================================
    // Text nodes (plain HTML between shortcodes)
    // =========================================================================

    private function convert_text_node( array $node ): string {
        $content = trim( $node['content'] );
        if ( '' === $content ) {
            return '';
        }
        return $this->html_to_blocks( $content, [] );
    }

    // =========================================================================
    // Layout: Section / Row / Column
    // =========================================================================

    private function convert_section( array $node ): string {
        $attrs = $node['attrs'];
        $is_fullwidth = ( $attrs['fullwidth'] ?? '' ) === 'on';

        $inner = $this->render_nodes( $node['children'] );

        $layout = $is_fullwidth ? 'full' : 'constrained';
        $block_attrs = [ 'layout' => [ 'type' => $layout ] ];

        $classes = [ 'wp-block-group' ];
        $style_parts = [];

        if ( ! empty( $attrs['background_color'] ) ) {
            $block_attrs['style'] = [ 'color' => [ 'background' => $attrs['background_color'] ] ];
            $classes[] = 'has-background';
            $style_parts[] = 'background-color:' . $attrs['background_color'];
        }

        $class_str = implode( ' ', $classes );
        $style_str = $style_parts ? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"' : '';

        $inner_html = '<div class="' . $class_str . '"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', $block_attrs, $inner_html, true );
    }

    private function convert_row( array $node ): string {
        $attrs = $node['attrs'];

        // Determine column layout from children.
        $col_count = 0;
        foreach ( $node['children'] as $child ) {
            if ( in_array( $child['tag'], [ 'et_pb_column', 'et_pb_column_inner' ], true ) ) {
                $col_count++;
            }
        }

        $inner = $this->render_nodes( $node['children'] );

        // A core/column block must be nested inside core/columns.
        // Divi rows commonly have a single column (type 4_4), so we still
        // need a columns wrapper to avoid Gutenberg block validation errors.
        if ( $col_count >= 1 ) {
            $columns_html = '<div class="wp-block-columns">' . "\n" . $inner . "\n" . '</div>';
            return $this->gutenberg_block( 'columns', [], $columns_html, true );
        }

        return $inner;
    }

    private function convert_column( array $node ): string {
        $attrs = $node['attrs'];
        $inner = $this->render_nodes( $node['children'] );

        // Map Divi column type to width.
        $type  = $attrs['type'] ?? '';
        $width = $this->column_type_to_width( $type );
        $block_attrs = [];
        $style_str = '';
        if ( $width ) {
            $block_attrs['width'] = $width;
            $style_str = ' style="flex-basis:' . esc_attr( $width ) . '"';
        }

        $col_html = '<div class="wp-block-column"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'column', $block_attrs, $col_html, true );
    }

    private function column_type_to_width( string $type ): string {
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

    // =========================================================================
    // Text Module
    // =========================================================================

    private function convert_text( array $node ): string {
        $attrs   = $node['attrs'];
        $content = $this->get_inner_content( $node );

        if ( '' === trim( $content ) ) {
            return '';
        }

        // If the content contains iframes (YouTube/Vimeo embeds, etc.) or embed/object/video tags,
        // extract them first and convert separately so they aren't lost by DOMDocument.
        if ( preg_match( '#<(?:iframe|embed|object|video)[\s>]#i', $content ) ) {
            return $this->convert_text_with_embeds( $content, $attrs );
        }

        return $this->html_to_blocks( $content, $attrs );
    }

    /**
     * Convert text content that contains embedded media (iframes, video, embeds).
     * Splits around embed tags so text becomes paragraphs and embeds become embed/html blocks.
     */
    private function convert_text_with_embeds( string $content, array $attrs ): string {
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
                $output .= $this->convert_embed_tag( $part );
            } else {
                $output .= $this->html_to_blocks( $part, $attrs );
            }
        }

        return $output;
    }

    /**
     * Convert an iframe/embed/object/video HTML tag into the appropriate Gutenberg block.
     */
    private function convert_embed_tag( string $tag_html ): string {
        // Try to extract src from iframe.
        if ( preg_match( '#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $tag_html, $m ) ) {
            $src = $m[1];

            // YouTube embed.
            if ( preg_match( '#(?:youtube\.com/embed/|youtube-nocookie\.com/embed/)([a-zA-Z0-9_-]+)#', $src, $ym ) ) {
                $watch_url = 'https://www.youtube.com/watch?v=' . $ym[1];
                $html = '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $watch_url ) . "\n" . '</div></figure>';
                return $this->gutenberg_block( 'embed', [ 'url' => $watch_url, 'type' => 'video', 'providerNameSlug' => 'youtube' ], $html );
            }

            // Vimeo embed.
            if ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $src, $vm ) ) {
                $vimeo_url = 'https://vimeo.com/' . $vm[1];
                $html = '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $vimeo_url ) . "\n" . '</div></figure>';
                return $this->gutenberg_block( 'embed', [ 'url' => $vimeo_url, 'type' => 'video', 'providerNameSlug' => 'vimeo' ], $html );
            }

            // Other iframe embeds — preserve as HTML block.
            return $this->gutenberg_block( 'html', [], $tag_html );
        }

        // Video tag with src.
        if ( preg_match( '#<video\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $tag_html, $m ) ) {
            $src = $m[1];
            $html = '<figure class="wp-block-video"><video controls src="' . esc_url( $src ) . '"></video></figure>';
            return $this->gutenberg_block( 'video', [ 'src' => $src ], $html );
        }

        // Fallback: preserve as custom HTML block.
        return $this->gutenberg_block( 'html', [], $tag_html );
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
    private function html_to_blocks( string $html, array $attrs, int $depth = 0 ): string {
        if ( '' === trim( $html ) ) {
            return '';
        }

        // If the HTML contains iframes/embeds, extract and convert them first
        // before DOM parsing, which can corrupt iframe tags.
        if ( preg_match( '#<(?:iframe|embed|object)\b#i', $html ) ) {
            return $this->convert_text_with_embeds( $html, $attrs );
        }

        // Nothing that needs structural parsing: one paragraph.
        if ( ! preg_match( '#<[a-z!/]#i', $html ) ) {
            return $this->paragraph_block( $html, $attrs );
        }

        // Guard against a pathological nesting depth in malformed source.
        if ( $depth > 10 ) {
            return $this->gutenberg_block( 'html', [], $html );
        }

        $root = $this->load_html_fragment( $html );
        if ( null === $root ) {
            // No DOM extension, or the fragment could not be parsed. Preserving
            // it verbatim in a core/html block loses editability but never
            // loses content.
            return $this->gutenberg_block( 'html', [], $html );
        }

        $output = '';
        $buffer = ''; // Accumulated inline content awaiting a paragraph.

        foreach ( $root->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $buffer .= $root->ownerDocument->saveHTML( $child );
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
            $output .= $this->paragraph_block( $buffer, $attrs );
            $buffer  = '';
            $output .= $this->block_for_element( $child, $attrs, $depth );
        }

        $output .= $this->paragraph_block( $buffer, $attrs );

        return $output;
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
            $cls         = '';
            if ( $align ) {
                // The class and the block attribute have to agree: core/heading
                // regenerates the class from textAlign, so emitting one without
                // the other is exactly the mismatch that invalidates the block.
                $block_attrs['textAlign'] = $attrs['text_orientation'];
                $cls                      = ' class="' . $align . '"';
            }
            return $this->gutenberg_block(
                'heading',
                $block_attrs,
                '<' . $tag_name . $cls . '>' . $inner_html . '</' . $tag_name . '>'
            );
        }

        switch ( $tag_name ) {
            case 'p':
                return $this->paragraph_block( $inner_html, $attrs );

            case 'ul':
            case 'ol':
                return $this->list_block( $el, $attrs, $depth );

            case 'blockquote':
                return $this->quote_block( $el, $attrs, $depth );

            case 'table':
                return $this->table_block( $el );

            case 'pre':
                return $this->gutenberg_block(
                    'preformatted',
                    [],
                    '<pre class="wp-block-preformatted">' . $inner_html . '</pre>'
                );

            case 'hr':
                return $this->gutenberg_block(
                    'separator',
                    [],
                    '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
                );

            case 'div':
            case 'section':
            case 'article':
                return $this->html_to_blocks( $inner_html, $attrs, $depth + 1 );

            default:
                return $this->gutenberg_block( 'html', [], $el->ownerDocument->saveHTML( $el ) );
        }
    }

    /**
     * Emit a core/paragraph block, or nothing when there is nothing to say.
     */
    private function paragraph_block( string $inner_html, array $attrs ): string {
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

        return $this->gutenberg_block( 'paragraph', $block_attrs, '<p' . $cls . '>' . trim( $inner_html ) . '</p>' );
    }

    /**
     * Emit a core/list block with one core/list-item per <li>.
     *
     * core/list has held its items as inner blocks since WordPress 6.0; a bare
     * <ul><li>…</li></ul> body only survives through a deprecation path.
     */
    private function list_block( DOMNode $el, array $attrs, int $depth ): string {
        $tag_name    = strtolower( $el->nodeName );
        $ordered     = ( 'ol' === $tag_name );
        $block_attrs = $ordered ? [ 'ordered' => true ] : [];

        $items = '';
        foreach ( $el->childNodes as $li ) {
            if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) {
                continue;
            }

            $item_inline = '';
            $item_nested = '';

            foreach ( $li->childNodes as $part ) {
                if ( XML_ELEMENT_NODE === $part->nodeType
                    && in_array( strtolower( $part->nodeName ), [ 'ul', 'ol' ], true )
                ) {
                    // A nested list is its own core/list block inside the item.
                    $item_nested .= $this->list_block( $part, $attrs, $depth + 1 );
                    continue;
                }
                $item_inline .= $el->ownerDocument->saveHTML( $part );
            }

            $item_html = '<li>' . trim( $item_inline );
            if ( '' !== $item_nested ) {
                $item_html .= "\n" . $item_nested;
            }
            $item_html .= '</li>';

            $items .= $this->gutenberg_block( 'list-item', [], $item_html );
        }

        if ( '' === $items ) {
            return '';
        }

        $html = '<' . $tag_name . ' class="wp-block-list">' . "\n" . $items . '</' . $tag_name . '>';
        return $this->gutenberg_block( 'list', $block_attrs, $html, true );
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
        $inner = $this->html_to_blocks( $body, [], $depth + 1 );
        if ( '' === trim( $inner ) ) {
            return '';
        }

        $html = '<blockquote class="wp-block-quote">' . "\n" . $inner;
        if ( '' !== trim( $cite ) ) {
            $html .= '<cite>' . trim( $cite ) . '</cite>';
        }
        $html .= '</blockquote>';

        return $this->gutenberg_block( 'quote', [], $html, true );
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
                $this->add_warning(
                    'table',
                    __( 'A table used HTML attributes that the core Table block does not store. It was preserved as a Custom HTML block instead.', 'block-converter-for-divi' )
                );
                return $this->gutenberg_block( 'html', [], $html );
            }
        }
        if ( $el->hasAttributes() ) {
            return $this->gutenberg_block( 'html', [], $html );
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
            }
        }

        if ( '' !== $loose ) {
            // Rows written straight into <table> are invisible to core's
            // `tbody tr` selector, so give them the tbody they need.
            $sections .= '<tbody>' . $loose . '</tbody>';
        }

        if ( '' === $sections ) {
            return $this->gutenberg_block( 'html', [], $html );
        }

        // hasFixedLayout is stated rather than left to the block default, which
        // has changed between WordPress versions and controls a class name.
        return $this->gutenberg_block(
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

    // =========================================================================
    // Image Module
    // =========================================================================

    private function convert_image( array $node ): string {
        $attrs = $node['attrs'];
        $src   = $attrs['src'] ?? '';
        if ( '' === $src ) {
            return '';
        }

        $alt   = $attrs['alt'] ?? '';
        $url   = $attrs['url'] ?? '';
        $align = $attrs['align'] ?? '';

        $block_attrs = [
            'sizeSlug'        => 'large',
            'linkDestination' => $url ? 'custom' : 'none',
            'url'             => $src,
            'alt'             => $alt,
        ];

        // Try to resolve WordPress attachment ID.
        $attach_id = $this->url_to_attachment_id( $src );
        if ( $attach_id ) {
            $block_attrs['id'] = $attach_id;
        }

        if ( $url ) {
            $block_attrs['href'] = $url;
        }

        if ( $align ) {
            $block_attrs['align'] = $align;
        }

        $img  = '<img src="' . esc_url( $src ) . '"';
        $img .= ' alt="' . esc_attr( $alt ) . '"';
        if ( $attach_id ) {
            $img .= ' class="wp-image-' . $attach_id . '"';
        }
        $img .= '/>';

        if ( $url ) {
            $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';
            $img = '<a href="' . esc_url( $url ) . '"' . $target . '>' . $img . '</a>';
        }

        $figure_class = 'wp-block-image size-large';
        if ( $align ) {
            $figure_class .= ' align' . $align;
        }

        $caption = $this->get_inner_content( $node );
        $caption = trim( strip_tags( $caption, '<a><em><strong><br>' ) );

        $figure = '<figure class="' . $figure_class . '">' . $img;
        if ( $caption ) {
            $figure .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
        }
        $figure .= '</figure>';

        return $this->gutenberg_block( 'image', $block_attrs, $figure );
    }

    // =========================================================================
    // Button Module
    // =========================================================================

    private function convert_button( array $node ): string {
        $attrs = $node['attrs'];
        $text  = $attrs['button_text'] ?? 'Click Here';
        $url   = $attrs['button_url'] ?? '#';
        $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';

        $bg_color   = $attrs['button_bg_color'] ?? '';
        $text_color = $attrs['button_text_color'] ?? '';

        $align = $attrs['button_alignment'] ?? $attrs['text_orientation'] ?? '';
        $wrapper_class = 'wp-block-buttons';
        if ( $align ) {
            $wrapper_class .= ' is-content-justification-' . $align;
        }

        $buttons_attrs = [];
        if ( $align ) {
            $buttons_attrs['layout'] = [
                'type'           => 'flex',
                'justifyContent' => $align,
            ];
        }

        // Build button inner block with optional color attributes.
        $btn_attrs = [];
        $link_classes = [ 'wp-block-button__link', 'wp-element-button' ];
        $link_style_parts = [];

        if ( $bg_color ) {
            $btn_attrs['style']['color']['background'] = $bg_color;
            $link_classes[] = 'has-background';
            $link_style_parts[] = 'background-color:' . $bg_color;
        }
        if ( $text_color ) {
            $btn_attrs['style']['color']['text'] = $text_color;
            $link_classes[] = 'has-text-color';
            $link_style_parts[] = 'color:' . $text_color;
        }

        $link_class = implode( ' ', $link_classes );
        $link_style = $link_style_parts ? ' style="' . esc_attr( implode( ';', $link_style_parts ) ) . '"' : '';

        $inner_block = $this->gutenberg_block( 'button', $btn_attrs, '<div class="wp-block-button"><a class="' . $link_class . '"' . $link_style . ' href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $text ) . '</a></div>' );

        $html = '<div class="' . $wrapper_class . '">' . "\n" . $inner_block . "\n" . '</div>';
        return $this->gutenberg_block( 'buttons', $buttons_attrs, $html, true );
    }

    // =========================================================================
    // Video Module
    // =========================================================================

    private function convert_video( array $node ): string {
        $attrs = $node['attrs'];
        $src   = $attrs['src'] ?? '';

        // Fallback to alternative source attributes.
        if ( '' === $src ) {
            $src = $attrs['src_webm'] ?? '';
        }

        // Check if the inner content contains an iframe embed (common in Divi).
        $content = $this->get_inner_content( $node );
        if ( '' === $src && preg_match( '#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $content, $m ) ) {
            return $this->convert_embed_tag( $content );
        }

        if ( '' === $src ) {
            // Last resort: check if content itself is a URL.
            $content = trim( $content );
            if ( preg_match( '#^https?://#', $content ) ) {
                $src = $content;
            }
        }

        if ( '' === $src ) {
            return '';
        }

        // Normalize YouTube embed URLs to watch URLs.
        if ( preg_match( '#youtube\.com/embed/([a-zA-Z0-9_-]+)#', $src, $ym ) ) {
            $src = 'https://www.youtube.com/watch?v=' . $ym[1];
        } elseif ( preg_match( '#youtube-nocookie\.com/embed/([a-zA-Z0-9_-]+)#', $src, $ym ) ) {
            $src = 'https://www.youtube.com/watch?v=' . $ym[1];
        }

        // Check if it's a YouTube/Vimeo URL.
        if ( preg_match( '#(?:youtube\.com|youtu\.be|vimeo\.com)#', $src ) ) {
            $provider = strpos( $src, 'vimeo' ) !== false ? 'vimeo' : 'youtube';
            $provider_class = 'is-provider-' . $provider . ' wp-block-embed-' . $provider;
            $html = '<figure class="wp-block-embed is-type-video ' . $provider_class . '"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $src ) . "\n" . '</div></figure>';
            return $this->gutenberg_block( 'embed', [ 'url' => $src, 'type' => 'video', 'providerNameSlug' => $provider ], $html );
        }

        // Self-hosted video.
        $html = '<figure class="wp-block-video"><video controls src="' . esc_url( $src ) . '"></video></figure>';
        return $this->gutenberg_block( 'video', [ 'src' => $src ], $html );
    }

    // =========================================================================
    // Blurb Module (icon + heading + text)
    // =========================================================================

    private function convert_blurb( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $url     = $attrs['url'] ?? '';
        $image   = $attrs['image'] ?? '';
        $content = $this->get_inner_content( $node );
        $header_level = $attrs['header_level'] ?? 'h4';

        $group_open = '<div class="wp-block-group">';

        $inner = '';

        // Image.
        if ( $image ) {
            $img_html = '<img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '"/>';
            if ( $url ) {
                $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';
                $img_html = '<a href="' . esc_url( $url ) . '"' . $target . '>' . $img_html . '</a>';
            }
            $inner .= $this->gutenberg_block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => $url ? 'custom' : 'none' ], '<figure class="wp-block-image size-large">' . $img_html . '</figure>' );
        }

        // Title.
        if ( $title ) {
            $level = (int) str_replace( 'h', '', $header_level );
            if ( $level < 1 || $level > 6 ) {
                $level = 4;
            }
            $h_tag = 'h' . $level;
            $title_html = $title;
            if ( $url ) {
                $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';
                $title_html = '<a href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $title ) . '</a>';
            } else {
                $title_html = esc_html( $title );
            }
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => $level ], '<' . $h_tag . '>' . $title_html . '</' . $h_tag . '>' );
        }

        // Body text.
        $inner .= $this->html_to_blocks( $content, $attrs );

        $html = $group_open . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // CTA (Call to Action)
    // =========================================================================

    private function convert_cta( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $btn_text = $attrs['button_text'] ?? '';
        $btn_url  = $attrs['button_url'] ?? '#';
        $content = $this->get_inner_content( $node );

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 2 ], '<h2>' . esc_html( $title ) . '</h2>' );
        }
        $inner .= $this->html_to_blocks( $content, $attrs );
        if ( $btn_text ) {
            $btn_inner = $this->gutenberg_block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . esc_html( $btn_text ) . '</a></div>' );
            $inner .= $this->gutenberg_block( 'buttons', [], '<div class="wp-block-buttons">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        $block_attrs = [];
        $classes = [ 'wp-block-group' ];
        $style_parts = [];

        if ( ! empty( $attrs['background_color'] ) ) {
            $block_attrs['style'] = [ 'color' => [ 'background' => $attrs['background_color'] ] ];
            $classes[] = 'has-background';
            $style_parts[] = 'background-color:' . $attrs['background_color'];
        }

        $class_str = implode( ' ', $classes );
        $style_str = $style_parts ? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"' : '';
        $html = '<div class="' . $class_str . '"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';

        return $this->gutenberg_block( 'group', $block_attrs, $html, true );
    }

    // =========================================================================
    // Divider
    // =========================================================================

    private function convert_divider( array $node ): string {
        $attrs = $node['attrs'];
        $color = $attrs['color'] ?? '';
        $block_attrs = [];
        $classes = [ 'wp-block-separator', 'has-alpha-channel-opacity' ];
        $style_str = '';

        if ( $color ) {
            $block_attrs['style'] = [ 'color' => [ 'background' => $color ] ];
            $classes[] = 'has-background';
            $style_str = ' style="background-color:' . esc_attr( $color ) . '"';
        }

        return $this->gutenberg_block( 'separator', $block_attrs, '<hr class="' . implode( ' ', $classes ) . '"' . $style_str . '/>' );
    }

    // =========================================================================
    // Fullwidth Header
    // =========================================================================

    private function convert_fullwidth_header( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $subhead = $attrs['subhead'] ?? '';
        $content = $this->get_inner_content( $node );
        $bg_img  = $attrs['background_image'] ?? '';
        $bg_color = $attrs['background_color'] ?? '';

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 1, 'textAlign' => 'center' ], '<h1 class="has-text-align-center">' . esc_html( $title ) . '</h1>' );
        }
        if ( $subhead ) {
            $inner .= $this->gutenberg_block( 'paragraph', [ 'align' => 'center', 'fontSize' => 'large' ], '<p class="has-text-align-center has-large-font-size">' . esc_html( $subhead ) . '</p>' );
        }
        if ( trim( $content ) ) {
            $inner .= $this->gutenberg_block( 'paragraph', [ 'align' => 'center' ], '<p class="has-text-align-center">' . $content . '</p>' );
        }

        // Button(s).
        $btn1_text = $attrs['button_one_text'] ?? '';
        $btn2_text = $attrs['button_two_text'] ?? '';
        if ( $btn1_text || $btn2_text ) {
            $btns = '';
            if ( $btn1_text ) {
                $btn1_url = $attrs['button_one_url'] ?? '#';
                $btns .= $this->gutenberg_block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn1_url ) . '">' . esc_html( $btn1_text ) . '</a></div>' );
            }
            if ( $btn2_text ) {
                $btn2_url = $attrs['button_two_url'] ?? '#';
                $btns .= $this->gutenberg_block( 'button', [ 'className' => 'is-style-outline' ], '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn2_url ) . '">' . esc_html( $btn2_text ) . '</a></div>' );
            }
            $inner .= $this->gutenberg_block( 'buttons', [ 'layout' => [ 'type' => 'flex', 'justifyContent' => 'center' ] ], '<div class="wp-block-buttons is-content-justification-center">' . "\n" . $btns . "\n" . '</div>', true );
        }

        // Use Cover block if there's a background image.
        if ( $bg_img ) {
            $cover_attrs = [ 'url' => $bg_img ];
            if ( $bg_color ) {
                $cover_attrs['customOverlayColor'] = $bg_color;
            }
            $html = '<div class="wp-block-cover"><span class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="' . esc_url( $bg_img ) . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container">' . "\n" . $inner . "\n" . '</div></div>';
            return $this->gutenberg_block( 'cover', $cover_attrs, $html, true );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // Gallery
    // =========================================================================

    private function convert_gallery( array $node ): string {
        $attrs     = $node['attrs'];
        $ids_str   = $attrs['gallery_ids'] ?? '';
        $columns   = (int) ( $attrs['gallery_columns'] ?? $attrs['columns_number'] ?? 3 );
        $show_cap  = ( $attrs['show_title_and_caption'] ?? '' ) !== 'off';

        // Divi gallery modules can render as either a grid or a slider/carousel.
        // Preserve that intent by carrying the format through to the converted block.
        $layout_hint  = strtolower( (string) ( $attrs['gallery_layout'] ?? $attrs['layout'] ?? $attrs['type'] ?? '' ) );
        $is_carousel  = ( $attrs['fullwidth'] ?? '' ) === 'on' || in_array( $layout_hint, [ 'slider', 'carousel' ], true );

        if ( '' === $ids_str ) {
            return $this->gutenberg_block( 'paragraph', [], '<p>[Gallery — no images specified]</p>' );
        }

        // Map Divi gallery_link to Gutenberg linkTo / linkDestination.
        $link_map = [
            'off'        => 'none',
            'lightbox'   => 'media',
            'file'       => 'media',
            'attachment' => 'attachment',
        ];
        $divi_link   = $attrs['gallery_link'] ?? 'lightbox';
        $link_to     = $link_map[ $divi_link ] ?? 'none';

        $ids = array_values( array_filter( array_map( 'intval', explode( ',', $ids_str ) ) ) );
        $columns = max( 1, min( $columns, 8 ) );

        $gallery_attrs = [
            'columns'   => $columns,
            'linkTo'    => $link_to,
            'imageCrop' => true,
        ];

        if ( $is_carousel ) {
            // Core/gallery doesn't support a native carousel block attribute.
            $gallery_attrs['className'] = 'd2g-gallery-slider';
            $this->add_warning(
                'et_pb_gallery',
                __( 'A gallery slider became a static grid gallery tagged with the d2g-gallery-slider class. Core has no carousel gallery; style or script that class in your theme if you need the slider behaviour back.', 'block-converter-for-divi' )
            );
        }

        // Build individual wp:image inner blocks for each gallery image.
        $images_markup = '';
        foreach ( $ids as $id ) {
            $url     = $this->resolve_attachment_url( $id );
            $alt     = function_exists( 'get_post_meta' ) ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
            $caption = ( $show_cap && function_exists( 'wp_get_attachment_caption' ) ) ? wp_get_attachment_caption( $id ) : '';

            $img_attrs = [
                'id'              => $id,
                'sizeSlug'        => 'large',
                'linkDestination' => $link_to,
                'url'             => $url,
                'alt'             => $alt,
            ];

            if ( $url ) {
                $img_tag = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/>';
            } else {
                // Attachment not found — skip this image entirely rather than
                // producing a broken <img src=""> that shows nothing.
                continue;
            }

            $fig_html = '<figure class="wp-block-image size-large">' . $img_tag;
            if ( $caption ) {
                $fig_html .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
            }
            $fig_html .= '</figure>';

            $images_markup .= $this->gutenberg_block( 'image', $img_attrs, $fig_html );
        }

        $gallery_classes = 'wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped';
        if ( $is_carousel ) {
            $gallery_classes .= ' d2g-gallery-slider';
        }
        $gallery_html    = '<figure class="' . esc_attr( $gallery_classes ) . '">' . "\n" . $images_markup . '</figure>';
        return $this->gutenberg_block( 'gallery', $gallery_attrs, $gallery_html, true );
    }

    // =========================================================================
    // Accordion
    // =========================================================================

    private function convert_accordion( array $node ): string {
        // Convert each toggle child into a details block.
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_toggle' ) {
                $inner .= $this->convert_toggle( $child );
            } else {
                $inner .= $this->render_node( $child );
            }
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // Toggle (details/summary)
    // =========================================================================

    private function convert_toggle( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? __( 'Toggle', 'block-converter-for-divi' );
        $content = $this->get_inner_content( $node );
        $is_open = ( $attrs['open'] ?? '' ) === 'on';
        $body    = $this->html_to_blocks( $content, $attrs );

        // core/details arrived in WordPress 6.3. On anything older the block is
        // unregistered, so the editor would show "your site doesn't include
        // support for this block" instead of the content.
        if ( ! $this->block_supported( 'core/details' ) ) {
            $this->add_warning(
                'et_pb_toggle',
                __( 'Toggles and accordions were converted to headings and text because this WordPress version has no Details block (added in 6.3).', 'block-converter-for-divi' )
            );
            $inner = $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $title ) . '</h3>' ) . $body;
            return $this->gutenberg_block( 'group', [], '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>', true );
        }

        // showContent is what core/details reads to decide whether to write the
        // `open` attribute back out. Emitting `open` without it makes the saved
        // markup disagree with the regenerated markup, and the block is flagged
        // as invalid the first time the page is opened in the editor.
        $block_attrs = $is_open ? [ 'showContent' => true ] : [];
        $open_attr   = $is_open ? ' open' : '';

        $html = '<details class="wp-block-details"' . $open_attr . '><summary>' . esc_html( $title ) . '</summary>';
        if ( '' !== trim( $body ) ) {
            $html .= "\n" . $body;
        }
        $html .= '</details>';

        return $this->gutenberg_block( 'details', $block_attrs, $html, true );
    }

    // =========================================================================
    // Tabs
    // =========================================================================

    private function convert_tabs( array $node ): string {
        $inner = '';
        $tab_num = 0;
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_tab' ) {
                $tab_num++;
                $child['_tab_num'] = $tab_num;
                $inner .= $this->convert_tab( $child );
            }
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    private function convert_tab( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? 'Tab';
        $content = $this->get_inner_content( $node );

        $heading = $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $title ) . '</h3>' );
        $body    = $this->html_to_blocks( $content, $attrs );

        $html = '<div class="wp-block-group">' . "\n" . $heading . $body . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // Slider
    // =========================================================================

    private function convert_slider( array $node ): string {
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_slide' ) {
                $inner .= $this->convert_slide( $child );
            } else {
                $inner .= $this->render_node( $child );
            }
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    private function convert_slide( array $node ): string {
        $attrs   = $node['attrs'];
        $heading = $attrs['heading'] ?? '';
        $content = $this->get_inner_content( $node );
        $bg_img  = $attrs['background_image'] ?? $attrs['image'] ?? '';
        $btn_text = $attrs['button_text'] ?? '';
        $btn_url  = $attrs['button_link'] ?? $attrs['link'] ?? '#';

        $inner = '';

        if ( $heading ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 2 ], '<h2>' . esc_html( $heading ) . '</h2>' );
        }
        $inner .= $this->html_to_blocks( $content, $attrs );
        if ( $btn_text ) {
            $btn_inner = $this->gutenberg_block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . esc_html( $btn_text ) . '</a></div>' );
            $inner .= $this->gutenberg_block( 'buttons', [], '<div class="wp-block-buttons">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        if ( $bg_img ) {
            $html = '<div class="wp-block-cover"><span class="wp-block-cover__background has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="' . esc_url( $bg_img ) . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container">' . "\n" . $inner . "\n" . '</div></div>';
            return $this->gutenberg_block( 'cover', [ 'url' => $bg_img ], $html, true );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // Testimonial
    // =========================================================================

    private function convert_testimonial( array $node ): string {
        $attrs   = $node['attrs'];
        $author  = $attrs['author'] ?? '';
        $company = $attrs['company_name'] ?? '';
        $url     = $attrs['url'] ?? '';
        $portrait = $attrs['portrait_url'] ?? '';
        $content = $this->get_inner_content( $node );

        $inner = '';
        if ( $portrait ) {
            $inner .= $this->gutenberg_block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => 'none', 'className' => 'is-style-rounded' ], '<figure class="wp-block-image size-large is-style-rounded"><img src="' . esc_url( $portrait ) . '" alt="' . esc_attr( $author ) . '"/></figure>' );
        }

        $cite_parts = [];
        if ( $author ) {
            $cite_parts[] = '<strong>' . esc_html( $author ) . '</strong>';
        }
        if ( $company ) {
            $c = esc_html( $company );
            if ( $url ) {
                $c = '<a href="' . esc_url( $url ) . '">' . $c . '</a>';
            }
            $cite_parts[] = $c;
        }

        // core/quote keeps its body as inner blocks and its attribution as a
        // sourced <cite>, so the body is built as real blocks rather than as
        // loose markup inside the blockquote.
        $quote_body = $this->html_to_blocks( $content, $attrs );
        if ( '' === trim( $quote_body ) && $cite_parts ) {
            $quote_body = $this->gutenberg_block( 'paragraph', [], '<p></p>' );
        }

        if ( '' !== trim( $quote_body ) ) {
            $quote_html = '<blockquote class="wp-block-quote">' . "\n" . $quote_body;
            if ( $cite_parts ) {
                $quote_html .= '<cite>' . implode( ', ', $cite_parts ) . '</cite>';
            }
            $quote_html .= '</blockquote>';
            $inner .= $this->gutenberg_block( 'quote', [], $quote_html, true );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // Team Member
    // =========================================================================

    private function convert_team_member( array $node ): string {
        $attrs   = $node['attrs'];
        $name    = $attrs['name'] ?? '';
        $position = $attrs['position'] ?? '';
        $image   = $attrs['image_url'] ?? '';
        $content = $this->get_inner_content( $node );

        $inner = '';
        if ( $image ) {
            $inner .= $this->gutenberg_block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => 'none' ], '<figure class="wp-block-image size-large"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $name ) . '"/></figure>' );
        }
        if ( $name ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $name ) . '</h3>' );
        }
        if ( $position ) {
            $inner .= $this->gutenberg_block( 'paragraph', [ 'fontSize' => 'small' ], '<p class="has-small-font-size"><em>' . esc_html( $position ) . '</em></p>' );
        }
        $inner .= $this->html_to_blocks( $content, $attrs );

        // Social links from attrs.
        $socials = $this->extract_social_links( $attrs );
        if ( $socials ) {
            $inner .= $this->build_social_links_block( $socials );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    private function extract_social_links( array $attrs ): array {
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

    // =========================================================================
    // Pricing Tables
    // =========================================================================

    private function convert_pricing_tables( array $node ): string {
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_pricing_table' ) {
                $inner .= $this->convert_pricing_table( $child );
            }
        }

        $html = '<div class="wp-block-columns">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'columns', [], $html, true );
    }

    private function convert_pricing_table( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $subtitle = $attrs['subtitle'] ?? '';
        $currency = $attrs['currency'] ?? '$';
        $price   = $attrs['per'] ?? $attrs['sum'] ?? '';
        $sum     = $attrs['sum'] ?? '';
        $period  = $attrs['per'] ?? '';
        $btn_text = $attrs['button_text'] ?? '';
        $btn_url  = $attrs['button_url'] ?? '#';

        $inner = '';
        if ( $title ) {
            // textAlign has to accompany the has-text-align-center class, or
            // core/heading regenerates the markup without it and the block is
            // reported as invalid.
            $inner .= $this->gutenberg_block(
                'heading',
                [ 'textAlign' => 'center', 'level' => 3 ],
                '<h3 class="has-text-align-center">' . esc_html( $title ) . '</h3>'
            );
        }
        if ( $subtitle ) {
            $inner .= $this->gutenberg_block( 'paragraph', [ 'align' => 'center' ], '<p class="has-text-align-center">' . esc_html( $subtitle ) . '</p>' );
        }
        if ( $sum ) {
            $price_text = esc_html( $currency ) . esc_html( $sum );
            if ( $period ) {
                $price_text .= '<small>/' . esc_html( $period ) . '</small>';
            }
            $inner .= $this->gutenberg_block(
                'heading',
                [ 'textAlign' => 'center', 'level' => 2 ],
                '<h2 class="has-text-align-center">' . $price_text . '</h2>'
            );
        }

        // Feature rows. Divi stores each one as its own [et_pb_pricing_item]
        // shortcode inside the table. There was no renderer for that tag, and
        // the table fell back to the node's raw inner content, so the shortcodes
        // themselves were written into a paragraph — leaving literal
        // "[et_pb_pricing_item]" text on the page once Divi was gone.
        $items = [];
        foreach ( $node['children'] as $child ) {
            if ( 'et_pb_pricing_item' === $child['tag'] ) {
                $items[] = $child;
            }
        }

        if ( $items ) {
            $inner .= $this->convert_pricing_items( $items );
        } else {
            $inner .= $this->html_to_blocks( $this->get_inner_content( $node ), $attrs );
        }

        if ( $btn_text ) {
            $btn_inner = $this->gutenberg_block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . esc_html( $btn_text ) . '</a></div>' );
            $inner .= $this->gutenberg_block( 'buttons', [ 'layout' => [ 'type' => 'flex', 'justifyContent' => 'center' ] ], '<div class="wp-block-buttons is-content-justification-center">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        $html = '<div class="wp-block-column">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'column', [], $html, true );
    }

    /**
     * Render [et_pb_pricing_item] nodes as a core/list of core/list-item blocks.
     *
     * Divi marks a struck-through (unavailable) feature with available="off";
     * that is carried over as a <s> element so the distinction survives.
     */
    private function convert_pricing_items( array $items ): string {
        $list_items = '';

        foreach ( $items as $item ) {
            $text = trim( wp_strip_all_tags( $this->get_inner_content( $item ), true ) );
            if ( '' === $text ) {
                continue;
            }

            $label = esc_html( $text );
            if ( ( $item['attrs']['available'] ?? 'on' ) === 'off' ) {
                $label = '<s>' . $label . '</s>';
            }

            $list_items .= $this->gutenberg_block( 'list-item', [], '<li>' . $label . '</li>' );
        }

        if ( '' === $list_items ) {
            return '';
        }

        return $this->gutenberg_block(
            'list',
            [],
            '<ul class="wp-block-list">' . "\n" . $list_items . '</ul>',
            true
        );
    }

    // =========================================================================
    // Counters / Number Counter / Circle Counter
    // =========================================================================

    private function convert_counters( array $node ): string {
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_counter' ) {
                $inner .= $this->convert_counter( $child );
            }
        }
        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    private function convert_counter( array $node ): string {
        $attrs   = $node['attrs'];
        $percent = $attrs['percent'] ?? '0';
        $content = $this->get_inner_content( $node );
        $label   = trim( $content ) ?: ( $attrs['title'] ?? '' );

        $html = '<p><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $percent ) . '%</p>';
        return $this->gutenberg_block( 'paragraph', [], $html );
    }

    private function convert_number_counter( array $node ): string {
        $attrs  = $node['attrs'];
        $number = $attrs['number'] ?? '0';
        $title  = $attrs['title'] ?? '';
        $percent = $attrs['percent_sign'] ?? 'on';

        $display = esc_html( $number );
        if ( $percent === 'on' ) {
            $display .= '%';
        }

        $inner = $this->gutenberg_block(
            'heading',
            [ 'textAlign' => 'center', 'level' => 2 ],
            '<h2 class="has-text-align-center">' . $display . '</h2>'
        );
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'paragraph', [ 'align' => 'center' ], '<p class="has-text-align-center">' . esc_html( $title ) . '</p>' );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    private function convert_circle_counter( array $node ): string {
        return $this->convert_number_counter( $node );
    }

    // =========================================================================
    // Social Media Follow
    // =========================================================================

    private function convert_social_follow( array $node ): string {
        $links = [];
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_social_media_follow_network' ) {
                $network = $child['attrs']['social_network'] ?? '';
                $url     = $child['attrs']['url'] ?? '#';
                if ( $network ) {
                    $links[ $network ] = $url;
                }
            }
        }

        return $this->build_social_links_block( $links );
    }

    private function convert_social_network( array $node ): string {
        $attrs   = $node['attrs'];
        $network = $attrs['social_network'] ?? '';
        $url     = $attrs['url'] ?? '#';

        if ( ! $network ) {
            return '';
        }

        return $this->gutenberg_block( 'social-link', [ 'url' => $url, 'service' => $network ] );
    }

    /**
     * Build a wp:social-links block with wp:social-link inner blocks.
     */
    private function build_social_links_block( array $links ): string {
        if ( empty( $links ) ) {
            return '';
        }

        $inner = '';
        foreach ( $links as $network => $url ) {
            $inner .= $this->gutenberg_block( 'social-link', [ 'url' => $url, 'service' => $network ] );
        }

        $html = '<ul class="wp-block-social-links">' . "\n" . $inner . '</ul>';
        return $this->gutenberg_block( 'social-links', [], $html, true );
    }

    // =========================================================================
    // Map
    // =========================================================================

    private function convert_map( array $node ): string {
        $attrs   = $node['attrs'];
        $address = $attrs['address'] ?? '';
        $lat     = $attrs['address_lat'] ?? '';
        $lng     = $attrs['address_lng'] ?? '';

        $map_content = '';
        if ( $address ) {
            $map_content = '<p><strong>Map:</strong> ' . esc_html( $address ) . '</p>';
        } elseif ( $lat && $lng ) {
            $map_content = '<p><strong>Map:</strong> ' . esc_html( $lat ) . ', ' . esc_html( $lng ) . '</p>';
        }

        // Render map pins.
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_map_pin' ) {
                $pin_title = $child['attrs']['title'] ?? '';
                $pin_content = $this->get_inner_content( $child );
                if ( $pin_title ) {
                    $map_content .= '<p><strong>' . esc_html( $pin_title ) . '</strong>';
                    if ( trim( $pin_content ) ) {
                        $map_content .= ': ' . $pin_content;
                    }
                    $map_content .= '</p>';
                }
            }
        }

        if ( $map_content ) {
            $this->add_warning(
                'et_pb_map',
                __( 'A map module became text. Core has no map block — the address and any pin labels were preserved so the map can be rebuilt with a maps plugin or an embed.', 'block-converter-for-divi' )
            );
            return $this->gutenberg_block( 'group', [], '<div class="wp-block-group">' . "\n" . $this->gutenberg_block( 'html', [], $map_content ) . "\n" . '</div>', true );
        }

        return '';
    }

    // =========================================================================
    // Code Module
    // =========================================================================

    private function convert_code( array $node ): string {
        $content = $this->get_inner_content( $node );
        if ( '' === trim( $content ) ) {
            return '';
        }
        return $this->gutenberg_block( 'html', [], $content );
    }

    // =========================================================================
    // Contact Form
    // =========================================================================

    /**
     * Convert a Divi contact form into a description of the form to rebuild.
     *
     * Earlier versions emitted core/form, core/form-input and
     * core/form-submit-button. Those three blocks are experimental: they ship
     * with the Gutenberg feature plugin and are not registered by WordPress
     * core, so on a normal install the converted page showed three "block not
     * supported" placeholders where the form used to be. The mapping also
     * flattened select, radio and checkbox fields to plain text inputs, and
     * nothing carried the recipient address into a working mail path, so even
     * where the blocks did render the form could not be submitted.
     *
     * A form is not something to silently half-convert. The fields, their
     * types, and the recipient are written out as ordinary core blocks — always
     * valid, always visible — and the module is reported as needing manual
     * work, so the user rebuilds it with a form plugin of their choosing rather
     * than discovering later that submissions were being dropped.
     *
     * The `d2g_contact_form_markup` filter lets a site swap in blocks for
     * whichever form plugin it actually uses.
     */
    private function convert_contact_form( array $node ): string {
        $attrs       = $node['attrs'];
        $title       = $attrs['title'] ?? '';
        $email       = $attrs['email'] ?? '';
        $submit_text = $attrs['submit_button_text'] ?? __( 'Send', 'block-converter-for-divi' );

        // Human-readable names for what Divi called each field type.
        $type_labels = [
            'input'    => __( 'text', 'block-converter-for-divi' ),
            'email'    => __( 'email', 'block-converter-for-divi' ),
            'text'     => __( 'multi-line text', 'block-converter-for-divi' ),
            'select'   => __( 'dropdown', 'block-converter-for-divi' ),
            'radio'    => __( 'radio buttons', 'block-converter-for-divi' ),
            'checkbox' => __( 'checkboxes', 'block-converter-for-divi' ),
        ];

        $fields = '';
        foreach ( $node['children'] as $child ) {
            if ( 'et_pb_contact_field' !== $child['tag'] ) {
                continue;
            }

            $f_attrs = $child['attrs'];
            $f_title = $f_attrs['field_title'] ?? ( $f_attrs['field_id'] ?? __( 'Field', 'block-converter-for-divi' ) );
            $f_type  = $f_attrs['field_type'] ?? 'input';
            $label   = $type_labels[ $f_type ] ?? $f_type;

            $line = sprintf(
                /* translators: 1: form field label, 2: field type. */
                __( '%1$s — %2$s', 'block-converter-for-divi' ),
                esc_html( $f_title ),
                esc_html( $label )
            );

            if ( ( $f_attrs['required_mark'] ?? 'on' ) === 'on' ) {
                $line .= ' (' . esc_html__( 'required', 'block-converter-for-divi' ) . ')';
            }

            if ( ! empty( $f_attrs['field_options'] ) ) {
                $line .= '<br/>' . esc_html( str_replace( '|', ', ', $f_attrs['field_options'] ) );
            }

            $fields .= $this->gutenberg_block( 'list-item', [], '<li>' . $line . '</li>' );
        }

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $title ) . '</h3>' );
        }

        $intro = $email
            ? sprintf(
                /* translators: %s: recipient email address. */
                __( 'This was a Divi contact form sending to %s. WordPress has no stable core form block, so rebuild it with a form plugin using the fields below.', 'block-converter-for-divi' ),
                esc_html( $email )
            )
            : __( 'This was a Divi contact form. WordPress has no stable core form block, so rebuild it with a form plugin using the fields below.', 'block-converter-for-divi' );

        $inner .= $this->gutenberg_block( 'paragraph', [], '<p><em>' . $intro . '</em></p>' );

        if ( '' !== $fields ) {
            $inner .= $this->gutenberg_block( 'list', [], '<ul class="wp-block-list">' . "\n" . $fields . '</ul>', true );
        }

        $inner .= $this->gutenberg_block(
            'paragraph',
            [],
            '<p><em>' . sprintf(
                /* translators: %s: submit button label. */
                esc_html__( 'Submit button label: %s', 'block-converter-for-divi' ),
                esc_html( $submit_text )
            ) . '</em></p>'
        );

        $this->add_warning(
            'et_pb_contact_form',
            __( 'Contact forms cannot be converted to core blocks — WordPress has no stable form block. The fields and recipient address were preserved as text so the form can be rebuilt with a form plugin.', 'block-converter-for-divi' )
        );

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        $markup = $this->gutenberg_block( 'group', [], $html, true );

        /**
         * Filter the blocks emitted for a Divi contact form.
         *
         * @param string $markup Block markup produced by the converter.
         * @param array  $node   The parsed et_pb_contact_form node.
         */
        return (string) apply_filters( 'd2g_contact_form_markup', $markup, $node );
    }

    // =========================================================================
    // Audio
    // =========================================================================

    private function convert_audio( array $node ): string {
        $attrs   = $node['attrs'];
        $src     = $attrs['audio'] ?? '';
        $title   = $attrs['title'] ?? '';
        $artist  = $attrs['artist_name'] ?? '';
        $image   = $attrs['image_url'] ?? '';
        $content = $this->get_inner_content( $node );

        if ( '' === $src ) {
            return '';
        }

        $inner = '';
        if ( $image ) {
            $inner .= $this->gutenberg_block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => 'none' ], '<figure class="wp-block-image size-large"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '"/></figure>' );
        }
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 4 ], '<h4>' . esc_html( $title ) . '</h4>' );
        }
        if ( $artist ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . esc_html( $artist ) . '</p>' );
        }

        $inner .= $this->gutenberg_block( 'audio', [ 'src' => $src ], '<figure class="wp-block-audio"><audio controls src="' . esc_url( $src ) . '"></audio></figure>' );

        if ( $title || $artist || $image ) {
            $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
            return $this->gutenberg_block( 'group', [], $html, true );
        }

        return $inner;
    }

    // =========================================================================
    // Sidebar
    // =========================================================================

    private function convert_sidebar( array $node ): string {
        $attrs = $node['attrs'];
        $area  = $attrs['area'] ?? 'sidebar-1';
        $this->add_warning(
            'et_pb_sidebar',
            __( 'A sidebar module became a text placeholder. Core has no drop-in equivalent — rebuild it with the widget blocks you need.', 'block-converter-for-divi' )
        );

        return $this->gutenberg_block( 'paragraph', [], '<p><em>[Sidebar: ' . esc_html( $area ) . ' — use a Widgets block or shortcode to display this sidebar.]</em></p>' );
    }

    // =========================================================================
    // Blog
    // =========================================================================

    private function convert_blog( array $node ): string {
        $attrs      = $node['attrs'];
        $posts_num  = $attrs['posts_number'] ?? '10';
        $categories = $attrs['include_categories'] ?? '';

        $block_attrs = [ 'postsToShow' => (int) $posts_num ];
        if ( $categories ) {
            $block_attrs['categories'] = array_map( function ( $id ) {
                return [ 'id' => (int) $id ];
            }, explode( ',', $categories ) );
        }

        $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
        $json = $encode( $block_attrs, JSON_UNESCAPED_SLASHES );
        return "<!-- wp:latest-posts $json /-->\n\n";
    }

    // =========================================================================
    // Signup / Login
    // =========================================================================

    private function convert_signup( array $node ): string {
        $attrs = $node['attrs'];
        $title = $attrs['title'] ?? 'Subscribe';
        $desc  = $attrs['description'] ?? '';

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $title ) . '</h3>' );
        }
        if ( $desc ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . esc_html( $desc ) . '</p>' );
        }
        $this->add_warning(
            'et_pb_signup',
            __( 'An email opt-in module became a text placeholder. Rebuild it with your email marketing plugin.', 'block-converter-for-divi' )
        );

        $inner .= $this->gutenberg_block( 'paragraph', [], '<p><em>[Email signup form — configure with your email marketing plugin.]</em></p>' );

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    private function convert_login( array $node ): string {
        return "<!-- wp:loginout /-->\n\n";
    }

    // =========================================================================
    // Post Title
    // =========================================================================

    private function convert_post_title( array $node ): string {
        return "<!-- wp:post-title /-->\n\n";
    }

    // =========================================================================
    // Menu
    // =========================================================================

    /**
     * Convert a Divi menu module to core/navigation.
     *
     * Divi's menu_id is a classic nav_menu term ID. core/navigation has no
     * menuId attribute — it points at a wp_navigation post through `ref`, and a
     * term ID is not a post ID. Writing the term ID into a made-up menuId
     * attribute produced a block that parsed but resolved to nothing, so the
     * converted page showed an empty navigation.
     *
     * A bare core/navigation is emitted instead. It falls back to the site's
     * menu, and the classic menu is named in a warning so the user can point
     * the block at the right one — WordPress offers exactly that as a one-click
     * import in the block's own toolbar.
     */
    private function convert_menu( array $node ): string {
        $menu_id = $node['attrs']['menu_id'] ?? '';

        if ( $menu_id ) {
            $menu_name = '';
            if ( function_exists( 'wp_get_nav_menu_object' ) ) {
                $menu_object = wp_get_nav_menu_object( (int) $menu_id );
                if ( $menu_object && ! is_wp_error( $menu_object ) ) {
                    $menu_name = $menu_object->name;
                }
            }

            $this->add_warning(
                'et_pb_menu',
                $menu_name
                    ? sprintf(
                        /* translators: %s: classic menu name. */
                        __( 'A menu module became an empty Navigation block. Use the block\'s toolbar to import the classic menu "%s".', 'block-converter-for-divi' ),
                        $menu_name
                    )
                    : sprintf(
                        /* translators: %s: classic menu ID. */
                        __( 'A menu module became an empty Navigation block. Use the block\'s toolbar to import classic menu ID %s.', 'block-converter-for-divi' ),
                        (int) $menu_id
                    )
            );
        }

        return "<!-- wp:navigation /-->\n\n";
    }

    // =========================================================================
    // Comments
    // =========================================================================

    private function convert_comments( array $node ): string {
        return "<!-- wp:comments /-->\n\n";
    }

    // =========================================================================
    // Search
    // =========================================================================

    private function convert_search( array $node ): string {
        $attrs = $node['attrs'];
        $placeholder = $attrs['placeholder'] ?? '';
        $block_attrs = [];
        if ( $placeholder ) {
            $block_attrs['placeholder'] = $placeholder;
        }
        if ( empty( $block_attrs ) ) {
            return "<!-- wp:search /-->\n\n";
        }
        $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
        $json = $encode( $block_attrs, JSON_UNESCAPED_SLASHES );
        return "<!-- wp:search $json /-->\n\n";
    }

    // =========================================================================
    // Portfolio
    // =========================================================================

    private function convert_portfolio( array $node ): string {
        $attrs      = $node['attrs'];
        $posts_num  = (int) ( $attrs['posts_number'] ?? 4 );
        $categories = $attrs['include_categories'] ?? '';
        $fullwidth  = ( $attrs['fullwidth'] ?? '' ) === 'on';

        // The `project` post type and `project_category` taxonomy are Divi's,
        // not WordPress's — this plugin does not register them and nothing else
        // will once Divi is removed. The query block below is only as portable
        // as its post type, so both are filterable and the dependency is
        // reported rather than left for the user to discover as an empty grid.
        $post_type = (string) apply_filters( 'd2g_portfolio_post_type', 'project', $attrs );
        $taxonomy  = (string) apply_filters( 'd2g_portfolio_taxonomy', 'project_category', $attrs );

        if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( $post_type ) ) {
            $this->add_warning(
                'et_pb_portfolio',
                sprintf(
                    /* translators: %s: post type slug. */
                    __( 'A portfolio module became a Query Loop over the "%s" post type, which Divi registers. The block will list nothing once Divi is removed unless that post type is registered by something else — migrate the projects to a core post type, or use the d2g_portfolio_post_type filter.', 'block-converter-for-divi' ),
                    $post_type
                )
            );
        }

        $query = [
            'postType' => $post_type,
            'perPage'  => $posts_num,
            'order'    => 'desc',
            'orderBy'  => 'date',
        ];

        if ( $categories ) {
            $cat_ids = array_map( 'intval', explode( ',', $categories ) );
            $query['taxQuery'] = [
                $taxonomy => $cat_ids,
            ];
        }

        $query_attrs = [ 'query' => $query ];

        // Build wp:post-template inner blocks.
        $template_inner = '';
        $template_inner .= "<!-- wp:post-featured-image /-->\n\n";
        $template_inner .= "<!-- wp:post-title /-->\n\n";
        if ( ! $fullwidth ) {
            $template_inner .= "<!-- wp:post-excerpt /-->\n\n";
        }

        $post_template = "<!-- wp:post-template -->\n" . $template_inner . "<!-- /wp:post-template -->\n\n";
        $html = '<div class="wp-block-query">' . "\n" . $post_template . '</div>';
        return $this->gutenberg_block( 'query', $query_attrs, $html, true );
    }

    // =========================================================================
    // Video Slider
    // =========================================================================

    private function convert_video_slider( array $node ): string {
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_video_slider_item' ) {
                $src = $child['attrs']['src'] ?? '';
                if ( $src ) {
                    $inner .= $this->convert_video( $child );
                }
            }
        }

        if ( '' === $inner ) {
            return '';
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [], $html, true );
    }

    // =========================================================================
    // Shop (WooCommerce)
    // =========================================================================

    private function convert_shop( array $node ): string {
        $attrs   = $node['attrs'];
        $type    = $attrs['type'] ?? 'recent';
        $columns = $attrs['columns_number'] ?? '4';
        $rows    = $attrs['posts_number'] ?? '4';

        $this->add_warning(
            'et_pb_shop',
            __( 'A shop module became a text placeholder. Rebuild it with the WooCommerce Products block.', 'block-converter-for-divi' )
        );

        return $this->gutenberg_block( 'paragraph', [], '<p><em>[WooCommerce Products — type: ' . esc_html( $type ) . ', columns: ' . esc_html( $columns ) . ', count: ' . esc_html( $rows ) . '. Install WooCommerce and use the Products block.]</em></p>' );
    }

    // =========================================================================
    // Unknown module fallback
    // =========================================================================

    private function convert_unknown( array $node ): string {
        if ( '__text__' !== $node['tag'] ) {
            $this->add_warning(
                $node['tag'],
                sprintf(
                    /* translators: %s: Divi shortcode tag. */
                    __( 'The module "%s" has no mapping. Its contents were preserved, but its layout and settings were not.', 'block-converter-for-divi' ),
                    $node['tag']
                )
            );
        }

        // Render children if present, otherwise render content.
        if ( ! empty( $node['children'] ) ) {
            return $this->render_nodes( $node['children'] );
        }

        $content = trim( $node['content'] );
        if ( '' !== $content ) {
            return $this->gutenberg_block( 'html', [], $content );
        }

        return '';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Format a Gutenberg block comment.
     */
    private function gutenberg_block( string $name, array $attrs = [], string $html = '', bool $has_inner = false ): string {
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
     * Get the text/HTML content of a node, preferring non-shortcode content.
     */
    private function get_inner_content( array $node ): string {
        $children = $node['children'] ?? [];

        // The parser stores the full raw inner span on every paired node, child
        // shortcodes and all. Handing that raw span to a renderer that wants
        // text is how [et_pb_pricing_item] markup ended up printed inside a
        // paragraph. When a node has real child modules, only the loose text
        // between them is this node's own content — the modules are rendered
        // separately by whoever owns them.
        $has_module_children = false;
        foreach ( $children as $child ) {
            if ( '__text__' !== $child['tag'] ) {
                $has_module_children = true;
                break;
            }
        }

        if ( $has_module_children ) {
            $text_parts = [];
            foreach ( $children as $child ) {
                if ( '__text__' === $child['tag'] ) {
                    $text_parts[] = $child['content'];
                }
            }
            return trim( implode( "\n", $text_parts ) );
        }

        return trim( (string) ( $node['content'] ?? '' ) );
    }

    /**
     * Try to resolve a URL to a WordPress attachment ID.
     */
    private function url_to_attachment_id( string $url ): int {
        if ( ! function_exists( 'attachment_url_to_postid' ) ) {
            return 0;
        }
        return (int) attachment_url_to_postid( $url );
    }

    /**
     * Resolve an attachment ID to its URL using multiple strategies.
     *
     * 1. wp_get_attachment_url() — standard WordPress lookup.
     * 2. Attachment metadata _wp_attached_file — constructs URL from uploads dir.
     * 3. GUID field — last resort, stored in wp_posts.guid.
     */
    private function resolve_attachment_url( int $id ): string {
        // Strategy 1: standard function.
        if ( function_exists( 'wp_get_attachment_url' ) ) {
            $url = wp_get_attachment_url( $id );
            if ( $url ) {
                return $url;
            }
        }

        // Strategy 2: reconstruct from _wp_attached_file meta + upload dir.
        if ( function_exists( 'get_post_meta' ) && function_exists( 'wp_get_upload_dir' ) ) {
            $file = get_post_meta( $id, '_wp_attached_file', true );
            if ( $file ) {
                $uploads = wp_get_upload_dir();
                return $uploads['baseurl'] . '/' . $file;
            }
        }

        // Strategy 3: use the post GUID (often contains the original URL).
        if ( function_exists( 'get_post' ) ) {
            $post = get_post( $id );
            if ( $post && ! empty( $post->guid ) ) {
                return $post->guid;
            }
        }

        return '';
    }
}
