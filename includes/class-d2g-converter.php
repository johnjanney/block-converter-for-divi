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
        $is_specialty = ( $attrs['specialty'] ?? '' ) === 'on';

        $inner = $this->render_nodes( $node['children'] );
        $style = D2G_Style_Mapper::wrapper_style( $attrs );

        // Use a Group block to wrap sections.
        $block_attrs = [];
        if ( ! empty( $attrs['background_color'] ) ) {
            $block_attrs['backgroundColor'] = $attrs['background_color'];
        }

        $layout = $is_fullwidth ? 'full' : 'constrained';
        $block_attrs['layout'] = [ 'type' => $layout ];

        $json = wp_json_encode( $block_attrs );

        if ( $style || ! empty( $attrs['background_color'] ) || ! empty( $attrs['background_image'] ) ) {
            $inner_html = '<div class="wp-block-group"' . $style . '>' . "\n" . $inner . "\n" . '</div>';
            return $this->gutenberg_block( 'group', $block_attrs, $inner_html, true );
        }

        return $this->gutenberg_block( 'group', $block_attrs, $inner, true );
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
        $style = D2G_Style_Mapper::wrapper_style( $attrs );

        if ( $col_count >= 2 ) {
            // Use wp:columns block.
            $columns_html = '<div class="wp-block-columns"' . $style . '>' . "\n" . $inner . "\n" . '</div>';
            return $this->gutenberg_block( 'columns', [], $columns_html, true );
        }

        if ( $style ) {
            $html = '<div class="wp-block-group"' . $style . '>' . "\n" . $inner . "\n" . '</div>';
            return $this->gutenberg_block( 'group', [], $html, true );
        }

        return $inner;
    }

    private function convert_column( array $node ): string {
        $attrs = $node['attrs'];
        $inner = $this->render_nodes( $node['children'] );
        $style = D2G_Style_Mapper::wrapper_style( $attrs );

        // Map Divi column type to width.
        $type  = $attrs['type'] ?? '';
        $width = $this->column_type_to_width( $type );
        $block_attrs = [];
        if ( $width ) {
            $block_attrs['width'] = $width;
        }

        // Check if parent is a multi-column row (rendered as wp:columns).
        $col_html = '<div class="wp-block-column"' . $style . '>' . "\n" . $inner . "\n" . '</div>';
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
        $style   = D2G_Style_Mapper::build_inline_style( $attrs );
        $align   = D2G_Style_Mapper::text_align_class( $attrs );

        if ( '' === trim( strip_tags( $content ) ) && '' === trim( $content ) ) {
            return '';
        }

        // If the content already contains block-level HTML (h1-h6, ul, ol, table, etc.),
        // render as an HTML block to preserve formatting.
        if ( preg_match( '#<(?:h[1-6]|ul|ol|table|blockquote|pre|dl|figure)[>\s]#i', $content ) ) {
            if ( $style ) {
                $content = '<div style="' . esc_attr( $style ) . '">' . $content . '</div>';
            }
            return $this->convert_rich_html( $content, $attrs );
        }

        // Simple paragraph content.
        $classes = $align ? ' class="' . $align . '"' : '';
        $st      = $style ? ' style="' . esc_attr( $style ) . '"' : '';

        // Strip wrapping <p> tags if already present, then re-wrap.
        $content = preg_replace( '#^<p[^>]*>(.*)</p>$#s', '$1', trim( $content ) );
        $html    = '<p' . $classes . $st . '>' . $content . '</p>';

        $block_attrs = [];
        if ( $align ) {
            $block_attrs['align'] = $attrs['text_orientation'];
        }

        return $this->gutenberg_block( 'paragraph', $block_attrs, $html );
    }

    /**
     * Convert rich HTML content (headings, lists, etc.) into appropriate Gutenberg blocks.
     */
    private function convert_rich_html( string $html, array $attrs ): string {
        $output = '';

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

        $alt    = $attrs['alt'] ?? '';
        $title  = $attrs['title_text'] ?? '';
        $url    = $attrs['url'] ?? '';
        $align  = $attrs['align'] ?? '';
        $width  = $attrs['max_width'] ?? '';

        $block_attrs = [];

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
        if ( $title ) {
            $img .= ' title="' . esc_attr( $title ) . '"';
        }
        if ( $attach_id ) {
            $img .= ' class="wp-image-' . $attach_id . '"';
        }
        $img .= '/>';

        if ( $url ) {
            $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';
            $img = '<a href="' . esc_url( $url ) . '"' . $target . '>' . $img . '</a>';
        }

        $figure_class = 'wp-block-image';
        if ( $align ) {
            $figure_class .= ' align' . $align;
        }
        $style_attr = '';
        if ( $width ) {
            $block_attrs['width'] = $width;
            $style_attr = ' style="max-width:' . esc_attr( $width ) . '"';
        }

        $caption = $this->get_inner_content( $node );
        $caption = trim( strip_tags( $caption, '<a><em><strong><br>' ) );

        $figure  = '<figure class="' . $figure_class . '"' . $style_attr . '>' . $img;
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

        $style_parts = [];
        if ( $bg_color ) {
            $style_parts[] = 'background-color:' . esc_attr( $bg_color );
        }
        if ( $text_color ) {
            $style_parts[] = 'color:' . esc_attr( $text_color );
        }
        if ( ! empty( $attrs['button_border_radius'] ) ) {
            $style_parts[] = 'border-radius:' . esc_attr( $attrs['button_border_radius'] );
        }

        $style = $style_parts ? ' style="' . implode( ';', $style_parts ) . '"' : '';

        $align = $attrs['button_alignment'] ?? $attrs['text_orientation'] ?? '';
        $wrapper_class = 'wp-block-buttons';
        if ( $align ) {
            $wrapper_class .= ' is-content-justification-' . $align;
        }

        $block_attrs = [];
        if ( $align ) {
            $block_attrs['layout'] = [
                'type'           => 'flex',
                'justifyContent' => $align,
            ];
        }

        $inner_block = $this->gutenberg_block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $style . ' href="' . esc_url( $url ) . '"' . $target . '>' . esc_html( $text ) . '</a></div>' );

        $html = '<div class="' . $wrapper_class . '">' . "\n" . $inner_block . "\n" . '</div>';
        return $this->gutenberg_block( 'buttons', $block_attrs, $html, true );
    }

    // =========================================================================
    // Video Module
    // =========================================================================

    private function convert_video( array $node ): string {
        $attrs = $node['attrs'];
        $src   = $attrs['src'] ?? '';

        if ( '' === $src ) {
            return '';
        }

        // Check if it's a YouTube/Vimeo embed URL.
        if ( preg_match( '#(?:youtube\.com|youtu\.be|vimeo\.com)#', $src ) ) {
            $html = '<figure class="wp-block-embed is-type-video"><div class="wp-block-embed__wrapper">' . "\n" . esc_url( $src ) . "\n" . '</div></figure>';
            $provider = strpos( $src, 'vimeo' ) !== false ? 'vimeo' : 'youtube';
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
        $style   = D2G_Style_Mapper::build_inline_style( $attrs );
        $header_level = $attrs['header_level'] ?? 'h4';

        $output = '';

        // Wrap with styling if present.
        $style_attr = $style ? ' style="' . esc_attr( $style ) . '"' : '';
        $group_open = '<div class="wp-block-group d2g-blurb"' . $style_attr . '>';

        $inner = '';

        // Image.
        if ( $image ) {
            $img_html = '<img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '"/>';
            if ( $url ) {
                $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';
                $img_html = '<a href="' . esc_url( $url ) . '"' . $target . '>' . $img_html . '</a>';
            }
            $inner .= $this->gutenberg_block( 'image', [], '<figure class="wp-block-image">' . $img_html . '</figure>' );
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
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-blurb' ], $html, true );
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
        $style   = D2G_Style_Mapper::build_inline_style( $attrs );

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

        $style_attr = $style ? ' style="' . esc_attr( $style ) . '"' : '';
        $html = '<div class="wp-block-cover d2g-cta"' . $style_attr . '><div class="wp-block-cover__inner-container">' . "\n" . $inner . "\n" . '</div></div>';

        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-cta' ], $html, true );
    }

    // =========================================================================
    // Divider
    // =========================================================================

    private function convert_divider( array $node ): string {
        $attrs = $node['attrs'];
        $color = $attrs['color'] ?? '';
        $block_attrs = [];
        $style = '';

        if ( $color ) {
            $block_attrs['customColor'] = $color;
            $style = ' style="border-top-color:' . esc_attr( $color ) . '"';
        }

        return $this->gutenberg_block( 'separator', $block_attrs, '<hr class="wp-block-separator has-alpha-channel-opacity"' . $style . '/>' );
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
        $style   = D2G_Style_Mapper::build_inline_style( $attrs );

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 1 ], '<h1 class="has-text-align-center">' . esc_html( $title ) . '</h1>' );
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
                $cover_attrs['overlayColor'] = $bg_color;
            }
            $html = '<div class="wp-block-cover"><span class="wp-block-cover__background"></span><img class="wp-block-cover__image-background" src="' . esc_url( $bg_img ) . '" alt=""/><div class="wp-block-cover__inner-container">' . "\n" . $inner . "\n" . '</div></div>';
            return $this->gutenberg_block( 'cover', $cover_attrs, $html, true );
        }

        $style_attr = $style ? ' style="' . esc_attr( $style ) . '"' : '';
        $html = '<div class="wp-block-group d2g-header"' . $style_attr . '>' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-header' ], $html, true );
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
            $url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $id ) : false;
            $alt = function_exists( 'get_post_meta' ) ? get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
            $caption = ( $show_cap && function_exists( 'wp_get_attachment_caption' ) ) ? wp_get_attachment_caption( $id ) : '';

            $img_attrs = [
                'id'              => $id,
                'sizeSlug'        => 'large',
                'linkDestination' => $link_to,
            ];

            if ( $url ) {
                $img_tag = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/>';
            } else {
                // Attachment not found in media library — leave a reference so the user can fix it.
                $img_tag = '<!-- attachment ID ' . $id . ' not found --><img src="" alt="" class="wp-image-' . $id . '"/>';
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

        $html = '<div class="wp-block-group d2g-accordion">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-accordion' ], $html, true );
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

        $html = '<div class="wp-block-group d2g-tabs">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-tabs' ], $html, true );
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

        $html = '<div class="wp-block-group d2g-tab">' . "\n" . $heading . $body . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-tab' ], $html, true );
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

        $html = '<div class="wp-block-group d2g-slider">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-slider' ], $html, true );
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
            $html = '<div class="wp-block-cover"><span class="wp-block-cover__background"></span><img class="wp-block-cover__image-background" src="' . esc_url( $bg_img ) . '" alt=""/><div class="wp-block-cover__inner-container">' . "\n" . $inner . "\n" . '</div></div>';
            return $this->gutenberg_block( 'cover', [ 'url' => $bg_img ], $html, true );
        }

        $html = '<div class="wp-block-group d2g-slide">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-slide' ], $html, true );
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
            $inner .= $this->gutenberg_block( 'image', [], '<figure class="wp-block-image is-style-rounded"><img src="' . esc_url( $portrait ) . '" alt="' . esc_attr( $author ) . '"/></figure>' );
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

        $html = '<div class="wp-block-group d2g-testimonial">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-testimonial' ], $html, true );
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
            $inner .= $this->gutenberg_block( 'image', [], '<figure class="wp-block-image"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $name ) . '"/></figure>' );
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
            $links = '';
            foreach ( $socials as $network => $link ) {
                $links .= '<li class="wp-social-link wp-social-link-' . esc_attr( $network ) . '"><a href="' . esc_url( $link ) . '">' . esc_html( ucfirst( $network ) ) . '</a></li>';
            }
            $inner .= $this->gutenberg_block( 'social-links', [], '<ul class="wp-block-social-links">' . $links . '</ul>' );
        }

        $html = '<div class="wp-block-group d2g-team-member">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-team-member' ], $html, true );
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

        $html = '<div class="wp-block-columns d2g-pricing-tables">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'columns', [ 'className' => 'd2g-pricing-tables' ], $html, true );
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

        $class = 'd2g-pricing-table' . ( $featured ? ' d2g-featured' : '' );
        $html = '<div class="wp-block-column ' . esc_attr( $class ) . '">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'column', [ 'className' => $class ], $html, true );
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
        $html = '<div class="wp-block-group d2g-counters">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-counters' ], $html, true );
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

        $html = '<div class="wp-block-group d2g-number-counter">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-number-counter' ], $html, true );
    }

    private function convert_circle_counter( array $node ): string {
        return $this->convert_number_counter( $node );
    }

    // =========================================================================
    // Social Media Follow
    // =========================================================================

    private function convert_social_follow( array $node ): string {
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_social_media_follow_network' ) {
                $inner .= $this->convert_social_network( $child );
            }
        }

        $html = '<ul class="wp-block-social-links is-style-default">' . $inner . '</ul>';
        return $this->gutenberg_block( 'social-links', [], $html );
    }

    private function convert_social_network( array $node ): string {
        $attrs   = $node['attrs'];
        $network = $attrs['social_network'] ?? '';
        $url     = $attrs['url'] ?? '#';
        $content = $this->get_inner_content( $node );
        $label   = trim( $content ) ?: ucfirst( $network );

        return '<li class="wp-social-link wp-social-link-' . esc_attr( $network ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
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
            return $this->gutenberg_block( 'group', [ 'className' => 'd2g-map' ], '<div class="wp-block-group d2g-map">' . "\n" . $this->gutenberg_block( 'html', [], $map_content ) . "\n" . '</div>', true );
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
        $attrs = $node['attrs'];
        $title = $attrs['title'] ?? 'Contact Us';

        $inner = '';
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 3 ], '<h3>' . esc_html( $title ) . '</h3>' );
        }
        $inner .= $this->gutenberg_block( 'paragraph', [], '<p><em>[Contact form — please install a forms plugin such as WPForms or Contact Form 7 to recreate this form.]</em></p>' );

        // List the fields for reference.
        $fields = [];
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_contact_field' ) {
                $f_id = $child['attrs']['field_id'] ?? '';
                $f_title = $child['attrs']['field_title'] ?? $f_id;
                $f_type = $child['attrs']['field_type'] ?? 'input';
                $required = ( $child['attrs']['required_mark'] ?? 'on' ) === 'on' ? ' *' : '';
                $fields[] = esc_html( $f_title ) . ' (' . esc_html( $f_type ) . ')' . $required;
            }
        }
        if ( $fields ) {
            $list_html = '<ul>';
            foreach ( $fields as $f ) {
                $list_html .= '<li>' . $f . '</li>';
            }
            $list_html .= '</ul>';
            $inner .= $this->gutenberg_block( 'list', [], $list_html );
        }

        $html = '<div class="wp-block-group d2g-contact-form">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-contact-form' ], $html, true );
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
            $inner .= $this->gutenberg_block( 'image', [], '<figure class="wp-block-image"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '"/></figure>' );
        }
        if ( $title ) {
            $inner .= $this->gutenberg_block( 'heading', [ 'level' => 4 ], '<h4>' . esc_html( $title ) . '</h4>' );
        }
        if ( $artist ) {
            $inner .= $this->gutenberg_block( 'paragraph', [], '<p>' . esc_html( $artist ) . '</p>' );
        }

        $inner .= $this->gutenberg_block( 'audio', [ 'src' => $src ], '<figure class="wp-block-audio"><audio controls src="' . esc_url( $src ) . '"></audio></figure>' );

        if ( $title || $artist || $image ) {
            $html = '<div class="wp-block-group d2g-audio">' . "\n" . $inner . "\n" . '</div>';
            return $this->gutenberg_block( 'group', [ 'className' => 'd2g-audio' ], $html, true );
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

        $json = wp_json_encode( $block_attrs );
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

        $html = '<div class="wp-block-group d2g-signup">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-signup' ], $html, true );
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
        $json = wp_json_encode( $block_attrs );
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
        $json = wp_json_encode( $block_attrs );
        return "<!-- wp:search $json /-->\n\n";
    }

    // =========================================================================
    // Portfolio
    // =========================================================================

    private function convert_portfolio( array $node ): string {
        $attrs     = $node['attrs'];
        $posts_num = $attrs['posts_number'] ?? '4';

        $block_attrs = [
            'postsToShow' => (int) $posts_num,
            'displayPostContent' => true,
        ];
        $json = wp_json_encode( $block_attrs );
        return "<!-- wp:latest-posts $json /-->\n\n";
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

        $html = '<div class="wp-block-group d2g-video-slider">' . "\n" . $inner . "\n" . '</div>';
        return $this->gutenberg_block( 'group', [ 'className' => 'd2g-video-slider' ], $html, true );
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
            'core/paragraph'     => 'paragraph',
            'core/heading'       => 'heading',
            'core/image'         => 'image',
            'core/list'          => 'list',
            'core/quote'         => 'quote',
            'core/button'        => 'button',
            'core/buttons'       => 'buttons',
            'core/columns'       => 'columns',
            'core/column'        => 'column',
            'core/group'         => 'group',
            'core/cover'         => 'cover',
            'core/separator'     => 'separator',
            'core/html'          => 'html',
            'core/embed'         => 'embed',
            'core/video'         => 'video',
            'core/audio'         => 'audio',
            'core/gallery'       => 'gallery',
            'core/table'         => 'table',
            'core/preformatted'  => 'preformatted',
            'core/details'       => 'details',
            'core/social-links'  => 'social-links',
        ];

        $block_name = $name_map[ $full_name ] ?? $name;

        $attrs_json = '';
        if ( ! empty( $attrs ) ) {
            $attrs_json = ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
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
}
