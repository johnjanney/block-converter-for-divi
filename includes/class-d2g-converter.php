<?php
/**
 * Converts parsed Divi shortcode trees into Gutenberg block markup.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Converter {

    private $parser;

    public function __construct() {
        $this->parser = new D2G_Parser();
    }

    /**
     * Convert Divi shortcode content to Gutenberg block markup.
     */
    public function convert( string $content ): string {
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
        // If it looks like HTML already, wrap in an HTML block.
        if ( preg_match( '#<[a-z][\s\S]*>#i', $content ) ) {
            return $this->gutenberg_block( 'html', [], $content );
        }
        return $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
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

        if ( $col_count >= 2 ) {
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
        $align   = D2G_Style_Mapper::text_align_class( $attrs );

        if ( '' === trim( strip_tags( $content ) ) && '' === trim( $content ) ) {
            return '';
        }

        // If the content contains iframes (YouTube/Vimeo embeds, etc.) or embed/object/video tags,
        // extract them first and convert separately so they aren't lost by DOMDocument.
        if ( preg_match( '#<(?:iframe|embed|object|video)[\s>]#i', $content ) ) {
            return $this->convert_text_with_embeds( $content, $attrs, $align );
        }

        // If the content already contains block-level HTML (h1-h6, ul, ol, table, etc.),
        // render as an HTML block to preserve formatting.
        if ( preg_match( '#<(?:h[1-6]|ul|ol|table|blockquote|pre|dl|figure)[>\s]#i', $content ) ) {
            return $this->convert_rich_html( $content, $attrs );
        }

        // Simple paragraph content.
        $classes = $align ? ' class="' . $align . '"' : '';

        // Strip wrapping <p> tags if already present, then re-wrap.
        $content = preg_replace( '#^<p[^>]*>(.*)</p>$#s', '$1', trim( $content ) );
        $html    = '<p' . $classes . '>' . $content . '</p>';

        $block_attrs = [];
        if ( $align ) {
            $block_attrs['align'] = $attrs['text_orientation'];
        }

        return $this->gutenberg_block( 'paragraph', $block_attrs, $html );
    }

    /**
     * Convert text content that contains embedded media (iframes, video, embeds).
     * Splits around embed tags so text becomes paragraphs and embeds become embed/html blocks.
     */
    private function convert_text_with_embeds( string $content, array $attrs, string $align = '' ): string {
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
            if ( preg_match( '#^<(iframe|embed|object|video)\b#i', $part, $m ) ) {
                $output .= $this->convert_embed_tag( $part );
            } else {
                // Regular HTML/text — use the normal text conversion path.
                $stripped = trim( strip_tags( $part ) );
                if ( '' === $stripped && '' === trim( $part ) ) {
                    continue;
                }

                if ( preg_match( '#<(?:h[1-6]|ul|ol|table|blockquote|pre|dl|figure)[>\s]#i', $part ) ) {
                    $output .= $this->convert_rich_html( $part, $attrs );
                } else {
                    $part = preg_replace( '#^<p[^>]*>(.*)</p>$#s', '$1', $part );
                    $cls = $align ? ' class="' . $align . '"' : '';
                    $output .= $this->gutenberg_block( 'paragraph', [], '<p' . $cls . '>' . $part . '</p>' );
                }
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
     * Convert rich HTML content (headings, lists, etc.) into appropriate Gutenberg blocks.
     */
    private function convert_rich_html( string $html, array $attrs ): string {
        $output = '';

        // If the HTML contains iframes/embeds, extract and convert them first
        // before DOM parsing, which can corrupt iframe tags.
        if ( preg_match( '#<(?:iframe|embed|object)\b#i', $html ) ) {
            return $this->convert_text_with_embeds( $html, $attrs, D2G_Style_Mapper::text_align_class( $attrs ) );
        }

        // Split HTML by block-level elements and convert each.
        $dom = new DOMDocument();
        $wrapped = '<div>' . mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) . '</div>';
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

        $root = $dom->getElementsByTagName( 'div' )->item( 0 );
        if ( ! $root ) {
            return $this->gutenberg_block( 'html', [], $html );
        }

        foreach ( $root->childNodes as $child ) {
            if ( $child->nodeType === XML_TEXT_NODE ) {
                $text = trim( $child->textContent );
                if ( '' !== $text ) {
                    $output .= $this->gutenberg_block( 'paragraph', [], '<p>' . esc_html( $text ) . '</p>' );
                }
                continue;
            }

            if ( $child->nodeType !== XML_ELEMENT_NODE ) {
                continue;
            }

            $tag_name = strtolower( $child->nodeName );
            $inner_html = $this->dom_inner_html( $child );

            if ( preg_match( '/^h([1-6])$/', $tag_name, $hm ) ) {
                $level = (int) $hm[1];
                $align = D2G_Style_Mapper::text_align_class( $attrs );
                $cls = $align ? ' class="' . $align . '"' : '';
                $h_html = '<' . $tag_name . $cls . '>' . $inner_html . '</' . $tag_name . '>';
                $output .= $this->gutenberg_block( 'heading', [ 'level' => $level ], $h_html );
            } elseif ( $tag_name === 'ul' ) {
                $output .= $this->gutenberg_block( 'list', [], '<ul>' . $inner_html . '</ul>' );
            } elseif ( $tag_name === 'ol' ) {
                $output .= $this->gutenberg_block( 'list', [ 'ordered' => true ], '<ol>' . $inner_html . '</ol>' );
            } elseif ( $tag_name === 'blockquote' ) {
                $output .= $this->gutenberg_block( 'quote', [], '<blockquote class="wp-block-quote">' . $inner_html . '</blockquote>' );
            } elseif ( $tag_name === 'table' ) {
                $output .= $this->gutenberg_block( 'table', [], '<figure class="wp-block-table"><table>' . $inner_html . '</table></figure>' );
            } elseif ( $tag_name === 'pre' ) {
                $output .= $this->gutenberg_block( 'preformatted', [], '<pre class="wp-block-preformatted">' . $inner_html . '</pre>' );
            } elseif ( $tag_name === 'p' ) {
                $align = D2G_Style_Mapper::text_align_class( $attrs );
                $cls = $align ? ' class="' . $align . '"' : '';
                $output .= $this->gutenberg_block( 'paragraph', [], '<p' . $cls . '>' . $inner_html . '</p>' );
            } elseif ( $tag_name === 'figure' || $tag_name === 'img' ) {
                $output .= $this->gutenberg_block( 'html', [], $dom->saveHTML( $child ) );
            } elseif ( $tag_name === 'div' ) {
                // Recurse into divs.
                $output .= $this->convert_rich_html( $inner_html, $attrs );
            } else {
                $output .= $this->gutenberg_block( 'html', [], $dom->saveHTML( $child ) );
            }
        }

        return $output;
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
        ];

        // Try to resolve WordPress attachment ID.
        $attach_id = $this->url_to_attachment_id( $src );
        if ( $attach_id ) {
            $block_attrs['id'] = $attach_id;
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
        if ( trim( $content ) ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }

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
        if ( trim( $content ) ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }
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

        $ids = array_map( 'intval', explode( ',', $ids_str ) );
        $columns = max( 1, min( $columns, 8 ) );

        $gallery_attrs = [
            'columns' => $columns,
            'linkTo'  => $link_to,
        ];

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

        $gallery_html = '<figure class="wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped">' . "\n" . $images_markup . '</figure>';
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
        $title   = $attrs['title'] ?? 'Toggle';
        $content = $this->get_inner_content( $node );
        $open    = ( $attrs['open'] ?? '' ) === 'on' ? ' open' : '';

        $html = '<details class="wp-block-details"' . $open . '><summary>' . esc_html( $title ) . '</summary>';
        if ( trim( $content ) ) {
            $html .= "\n" . $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }
        $html .= '</details>';

        return $this->gutenberg_block( 'details', [], $html, true );
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
        $body = '';
        if ( trim( $content ) ) {
            $body = $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }

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
        if ( trim( $content ) ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }
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

        $quote_inner = '';
        if ( trim( $content ) ) {
            $quote_inner .= '<p>' . $content . '</p>';
        }
        if ( $cite_parts ) {
            $quote_inner .= '<cite>' . implode( ', ', $cite_parts ) . '</cite>';
        }

        $inner .= $this->gutenberg_block( 'quote', [], '<blockquote class="wp-block-quote">' . $quote_inner . '</blockquote>' );

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
        if ( trim( $content ) ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }

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
        $content = $this->get_inner_content( $node );
        $featured = ( $attrs['featured'] ?? '' ) === 'on';

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3 class="has-text-align-center">' . esc_html( $title ) . '</h3>' );
        }
        if ( $subtitle ) {
            $inner .= $this->gutenberg_block( 'paragraph', [ 'align' => 'center' ], '<p class="has-text-align-center">' . esc_html( $subtitle ) . '</p>' );
        }
        if ( $sum ) {
            $price_text = esc_html( $currency ) . esc_html( $sum );
            if ( $period ) {
                $price_text .= '<small>/' . esc_html( $period ) . '</small>';
            }
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 2 ], '<h2 class="has-text-align-center">' . $price_text . '</h2>' );
        }

        // Features list from content.
        if ( trim( $content ) ) {
            // Divi pricing table features are typically in the inner content.
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . $content . '</p>' );
        }

        if ( $btn_text ) {
            $btn_inner = $this->gutenberg_block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . esc_html( $btn_text ) . '</a></div>' );
            $inner .= $this->gutenberg_block( 'buttons', [ 'layout' => [ 'type' => 'flex', 'justifyContent' => 'center' ] ], '<div class="wp-block-buttons is-content-justification-center">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        $html = '<div class="wp-block-column">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'column', [], $html, true );
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

        $inner = $this->gutenberg_block( 'heading', [ 'level' => 2 ], '<h2 class="has-text-align-center">' . $display . '</h2>' );
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

    private function convert_contact_form( array $node ): string {
        $attrs      = $node['attrs'];
        $title      = $attrs['title'] ?? '';
        $email      = $attrs['email'] ?? '';
        $submit_text = $attrs['submit_button_text'] ?? 'Send';

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $title ) . '</h3>' );
        }

        // Map Divi field_type to HTML input type.
        $type_map = [
            'input'    => 'text',
            'email'    => 'email',
            'text'     => 'textarea',
            'select'   => 'text',
            'radio'    => 'text',
            'checkbox' => 'text',
        ];

        // Build wp:form-input inner blocks for each contact field.
        $fields_markup = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] !== 'et_pb_contact_field' ) {
                continue;
            }
            $f_attrs  = $child['attrs'];
            $f_title  = $f_attrs['field_title'] ?? ( $f_attrs['field_id'] ?? 'Field' );
            $f_id     = $f_attrs['field_id'] ?? ( function_exists( 'sanitize_title' ) ? sanitize_title( $f_title ) : strtolower( str_replace( ' ', '-', $f_title ) ) );
            $f_type   = $f_attrs['field_type'] ?? 'input';
            $required = ( $f_attrs['required_mark'] ?? 'on' ) === 'on';

            $input_type = $type_map[ $f_type ] ?? 'text';
            $is_textarea = ( $input_type === 'textarea' );

            $field_block_attrs = [
                'label'    => $f_title,
                'required' => $required,
            ];
            if ( ! $is_textarea ) {
                $field_block_attrs['type'] = $input_type;
            }

            $req_attr = $required ? ' required' : '';
            $name_attr = esc_attr( strtolower( $f_id ) );

            if ( $is_textarea ) {
                $input_html = '<textarea name="' . $name_attr . '" class="wp-block-form-input__input"' . $req_attr . '></textarea>';
                $field_block_attrs['type'] = 'textarea';
            } else {
                $input_html = '<input type="' . esc_attr( $input_type ) . '" name="' . $name_attr . '" class="wp-block-form-input__input"' . $req_attr . '/>';
            }

            $label_html = '<label class="wp-block-form-input__label">'
                . '<span class="wp-block-form-input__label-content">' . esc_html( $f_title ) . '</span>'
                . $input_html
                . '</label>';

            $fields_markup .= $this->gutenberg_block( 'form-input', $field_block_attrs, $label_html );
        }

        // Submit button.
        $submit_markup = $this->gutenberg_block(
            'form-submit-button',
            [],
            '<div class="wp-block-form-submit-button"><button type="submit" class="wp-block-button__link wp-element-button">' . esc_html( $submit_text ) . '</button></div>'
        );

        // Wrap in wp:form.
        $form_attrs = [];
        if ( $email ) {
            $form_attrs['submissionMethod'] = 'email';
            $form_attrs['email'] = $email;
        }

        $form_html = '<form class="wp-block-form" method="post">' . "\n" . $fields_markup . $submit_markup . '</form>';
        $inner .= $this->gutenberg_block( 'form', $form_attrs, $form_html, true );

        return $inner;
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

    private function convert_menu( array $node ): string {
        $attrs = $node['attrs'];
        $menu_id = $attrs['menu_id'] ?? '';
        $block_attrs = [];
        if ( $menu_id ) {
            $block_attrs['menuId'] = (int) $menu_id;
        }
        if ( empty( $block_attrs ) ) {
            return "<!-- wp:navigation /-->\n\n";
        }
        $encode = function_exists( 'wp_json_encode' ) ? 'wp_json_encode' : 'json_encode';
        $json = $encode( $block_attrs, JSON_UNESCAPED_SLASHES );
        return "<!-- wp:navigation $json /-->\n\n";
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

        // Determine the portfolio post type (Divi uses 'project' by default).
        $post_type = 'project';

        $query = [
            'postType' => $post_type,
            'perPage'  => $posts_num,
            'order'    => 'desc',
            'orderBy'  => 'date',
        ];

        if ( $categories ) {
            $cat_ids = array_map( 'intval', explode( ',', $categories ) );
            $query['taxQuery'] = [
                'project_category' => $cat_ids,
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

        return $this->gutenberg_block( 'paragraph', [], '<p><em>[WooCommerce Products — type: ' . esc_html( $type ) . ', columns: ' . esc_html( $columns ) . ', count: ' . esc_html( $rows ) . '. Install WooCommerce and use the Products block.]</em></p>' );
    }

    // =========================================================================
    // Unknown module fallback
    // =========================================================================

    private function convert_unknown( array $node ): string {
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
        $full_name = 'core/' . $name;

        // Some blocks use short names.
        $name_map = [
            'core/paragraph'           => 'paragraph',
            'core/heading'             => 'heading',
            'core/image'               => 'image',
            'core/list'                => 'list',
            'core/quote'               => 'quote',
            'core/button'              => 'button',
            'core/buttons'             => 'buttons',
            'core/columns'             => 'columns',
            'core/column'              => 'column',
            'core/group'               => 'group',
            'core/cover'               => 'cover',
            'core/separator'           => 'separator',
            'core/html'                => 'html',
            'core/embed'               => 'embed',
            'core/video'               => 'video',
            'core/audio'               => 'audio',
            'core/gallery'             => 'gallery',
            'core/table'               => 'table',
            'core/preformatted'        => 'preformatted',
            'core/details'             => 'details',
            'core/social-links'        => 'social-links',
            'core/social-link'         => 'social-link',
            'core/form'                => 'form',
            'core/form-input'          => 'form-input',
            'core/form-submit-button'  => 'form-submit-button',
            'core/query'               => 'query',
            'core/post-template'       => 'post-template',
            'core/post-title'          => 'post-title',
            'core/post-featured-image' => 'post-featured-image',
            'core/post-excerpt'        => 'post-excerpt',
        ];

        $block_name = $name_map[ $full_name ] ?? $name;

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
        $content = $node['content'] ?? '';

        // If content has child shortcodes, the text content is in __text__ children.
        if ( '' === $content && ! empty( $node['children'] ) ) {
            $text_parts = [];
            foreach ( $node['children'] as $child ) {
                if ( $child['tag'] === '__text__' ) {
                    $text_parts[] = $child['content'];
                }
            }
            $content = implode( "\n", $text_parts );
        }

        return trim( $content );
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
