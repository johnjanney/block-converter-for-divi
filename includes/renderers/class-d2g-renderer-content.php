<?php
/**
 * Presentational content modules: buttons, blurbs, calls to action, dividers,
 * fullwidth headers, testimonials, team members and social links.
 *
 * What these have in common is that core has a reasonable equivalent for each,
 * so the conversion is a genuine mapping rather than a placeholder. Where a
 * module has no single equivalent — a blurb is an image plus a heading plus
 * text — it becomes a core/group holding those parts, which is editable
 * afterwards in a way a custom block would not be.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Content extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    public static function tags(): array {
        return [
            'et_pb_button'                      => 'button',
            'et_pb_blurb'                       => 'blurb',
            'et_pb_cta'                         => 'cta',
            'et_pb_divider'                     => 'divider',
            'et_pb_fullwidth_header'            => 'fullwidth_header',
            'et_pb_testimonial'                 => 'testimonial',
            'et_pb_team_member'                 => 'team_member',
            'et_pb_social_media_follow'         => 'social_follow',
            'et_pb_social_media_follow_network' => 'social_network',
        ];
    }

    protected function button( array $node ): string {
        $attrs = $node['attrs'];
        $text  = $attrs['button_text'] ?? __( 'Click Here', 'block-converter-for-divi' );
        $url   = $attrs['button_url'] ?? '#';
        $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';

        $bg_color   = D2G_Block_Builder::css_color( $attrs['button_bg_color'] ?? '' );
        $text_color = D2G_Block_Builder::css_color( $attrs['button_text_color'] ?? '' );

        // core/buttons regenerates `is-content-justification-<value>` from the
        // layout attribute, so only the four justifications the flex layout
        // supports may reach either one.
        $align = D2G_Block_Builder::allowed_value(
            $attrs['button_alignment'] ?? $attrs['text_orientation'] ?? '',
            [ 'left', 'center', 'right', 'space-between' ]
        );

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

        $inner_block = D2G_Block_Builder::block( 'button', $btn_attrs, '<div class="wp-block-button"><a class="' . $link_class . '"' . $link_style . ' href="' . esc_url( $url ) . '"' . $target . '>' . D2G_Block_Builder::text( $text ) . '</a></div>' );

        $html = '<div class="' . esc_attr( $wrapper_class ) . '">' . "\n" . $inner_block . "\n" . '</div>';
        return D2G_Block_Builder::block( 'buttons', $buttons_attrs, $html, true );
    }

    protected function blurb( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $url     = $attrs['url'] ?? '';
        $image   = $attrs['image'] ?? '';
        $header_level = $attrs['header_level'] ?? 'h4';

        $group_open = '<div class="wp-block-group">';

        $inner = '';

        // Image.
        if ( $image ) {
            $img_html = '<img src="' . esc_url( $image ) . '" alt="' . D2G_Block_Builder::attr( $title ) . '"/>';
            if ( $url ) {
                $target = ( $attrs['url_new_window'] ?? '' ) === 'on' ? ' target="_blank" rel="noopener noreferrer"' : '';
                $img_html = '<a href="' . esc_url( $url ) . '"' . $target . '>' . $img_html . '</a>';
            }
            $inner .= D2G_Block_Builder::block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => $url ? 'custom' : 'none' ], '<figure class="wp-block-image size-large">' . $img_html . '</figure>' );
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
                $title_html = '<a href="' . esc_url( $url ) . '"' . $target . '>' . D2G_Block_Builder::text( $title ) . '</a>';
            } else {
                $title_html = D2G_Block_Builder::text( $title );
            }
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => $level ], '<' . $h_tag . '>' . $title_html . '</' . $h_tag . '>' );
        }

        // Body text.
        $inner .= $this->context->render_inner_blocks( $node, $attrs );

        $html = $group_open . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function cta( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $btn_text = $attrs['button_text'] ?? '';
        $btn_url  = $attrs['button_url'] ?? '#';

        $inner = '';
        if ( $title ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 2 ], '<h2>' . D2G_Block_Builder::text( $title ) . '</h2>' );
        }
        $inner .= $this->context->render_inner_blocks( $node, $attrs );
        if ( $btn_text ) {
            $btn_inner = D2G_Block_Builder::block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . D2G_Block_Builder::text( $btn_text ) . '</a></div>' );
            $inner .= D2G_Block_Builder::block( 'buttons', [], '<div class="wp-block-buttons">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        $block_attrs = [];
        $classes = [ 'wp-block-group' ];
        $style_parts = [];

        $bg_color = D2G_Block_Builder::css_color( $attrs['background_color'] ?? '' );
        if ( '' !== $bg_color ) {
            $block_attrs['style'] = [ 'color' => [ 'background' => $bg_color ] ];
            $classes[] = 'has-background';
            $style_parts[] = 'background-color:' . $bg_color;
        }

        $class_str = implode( ' ', $classes );
        $style_str = $style_parts ? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"' : '';
        $html = '<div class="' . $class_str . '"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';

        return D2G_Block_Builder::block( 'group', $block_attrs, $html, true );
    }

    protected function divider( array $node ): string {
        $attrs = $node['attrs'];
        $color = D2G_Block_Builder::css_color( $attrs['color'] ?? '' );

        if ( '' === $color ) {
            return D2G_Block_Builder::block(
                'separator',
                [],
                '<hr class="wp-block-separator has-alpha-channel-opacity"/>'
            );
        }

        // A coloured separator carries has-text-color and a `color` declaration
        // as well as the background ones, and has-text-color sorts before
        // has-alpha-channel-opacity. Core's block supports generate all of that
        // from style.color.background; emitting a subset meant every coloured
        // divider failed validation. Confirmed against core's own serializer —
        // see tests/js/canonical.mjs.
        return D2G_Block_Builder::block(
            'separator',
            [ 'style' => [ 'color' => [ 'background' => $color ] ] ],
            '<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background"'
                . ' style="background-color:' . esc_attr( $color ) . ';color:' . esc_attr( $color ) . '"/>'
        );
    }

    protected function fullwidth_header( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $subhead = $attrs['subhead'] ?? '';
        $bg_img  = $attrs['background_image'] ?? '';
        $bg_color = D2G_Block_Builder::css_color( $attrs['background_color'] ?? '' );

        $inner = '';
        if ( $title ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 1, 'textAlign' => 'center' ], '<h1 class="has-text-align-center">' . D2G_Block_Builder::text( $title ) . '</h1>' );
        }
        if ( $subhead ) {
            $inner .= D2G_Block_Builder::block( 'paragraph', [ 'align' => 'center', 'fontSize' => 'large' ], '<p class="has-text-align-center has-large-font-size">' . D2G_Block_Builder::text( $subhead ) . '</p>' );
        }

        // The body used to be dropped whole into one paragraph block. A header
        // whose content was `<p>One</p><p>Two</p>` therefore produced
        // `<p class="…"><p>One</p><p>Two</p></p>` — nested <p> elements, which
        // are invalid HTML and do not match what core/paragraph regenerates.
        // It is the same defect as F-03, in a renderer that F-03 did not reach.
        // Routing it through the shared HTML splitter fixes it and picks up
        // nested modules at the same time.
        $inner .= $this->context->render_inner_blocks( $node, [ 'text_orientation' => 'center' ] + $attrs );

        // Button(s).
        $btn1_text = $attrs['button_one_text'] ?? '';
        $btn2_text = $attrs['button_two_text'] ?? '';
        if ( $btn1_text || $btn2_text ) {
            $btns = '';
            if ( $btn1_text ) {
                $btn1_url = $attrs['button_one_url'] ?? '#';
                $btns .= D2G_Block_Builder::block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn1_url ) . '">' . D2G_Block_Builder::text( $btn1_text ) . '</a></div>' );
            }
            if ( $btn2_text ) {
                $btn2_url = $attrs['button_two_url'] ?? '#';
                $btns .= D2G_Block_Builder::block( 'button', [ 'className' => 'is-style-outline' ], '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn2_url ) . '">' . D2G_Block_Builder::text( $btn2_text ) . '</a></div>' );
            }
            $inner .= D2G_Block_Builder::block( 'buttons', [ 'layout' => [ 'type' => 'flex', 'justifyContent' => 'center' ] ], '<div class="wp-block-buttons is-content-justification-center">' . "\n" . $btns . "\n" . '</div>', true );
        }

        // Use Cover block if there's a background image.
        if ( $bg_img ) {
            return D2G_Block_Builder::cover( $bg_img, $bg_color, $inner );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function testimonial( array $node ): string {
        $attrs   = $node['attrs'];
        $author  = $attrs['author'] ?? '';
        $company = $attrs['company_name'] ?? '';
        $url     = $attrs['url'] ?? '';
        $portrait = $attrs['portrait_url'] ?? '';

        $inner = '';
        if ( $portrait ) {
            $inner .= D2G_Block_Builder::block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => 'none', 'className' => 'is-style-rounded' ], '<figure class="wp-block-image size-large is-style-rounded"><img src="' . esc_url( $portrait ) . '" alt="' . D2G_Block_Builder::attr( $author ) . '"/></figure>' );
        }

        $cite_parts = [];
        if ( $author ) {
            $cite_parts[] = '<strong>' . D2G_Block_Builder::text( $author ) . '</strong>';
        }
        if ( $company ) {
            $c = D2G_Block_Builder::text( $company );
            if ( $url ) {
                $c = '<a href="' . esc_url( $url ) . '">' . $c . '</a>';
            }
            $cite_parts[] = $c;
        }

        // core/quote keeps its body as inner blocks and its attribution as a
        // sourced <cite>, so the body is built as real blocks rather than as
        // loose markup inside the blockquote.
        $quote_body = $this->context->render_inner_blocks( $node, $attrs );
        if ( '' === trim( $quote_body ) && $cite_parts ) {
            $quote_body = D2G_Block_Builder::block( 'paragraph', [], '<p></p>' );
        }

        if ( '' !== trim( $quote_body ) ) {
            $quote_html = '<blockquote class="wp-block-quote">' . "\n" . $quote_body;
            if ( $cite_parts ) {
                $quote_html .= '<cite>' . implode( ', ', $cite_parts ) . '</cite>';
            }
            $quote_html .= '</blockquote>';
            $inner .= D2G_Block_Builder::block( 'quote', [], $quote_html, true );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function team_member( array $node ): string {
        $attrs   = $node['attrs'];
        $name    = $attrs['name'] ?? '';
        $position = $attrs['position'] ?? '';
        $image   = $attrs['image_url'] ?? '';

        $inner = '';
        if ( $image ) {
            $inner .= D2G_Block_Builder::block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => 'none' ], '<figure class="wp-block-image size-large"><img src="' . esc_url( $image ) . '" alt="' . D2G_Block_Builder::attr( $name ) . '"/></figure>' );
        }
        if ( $name ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 3 ], '<h3>' . D2G_Block_Builder::text( $name ) . '</h3>' );
        }
        if ( $position ) {
            $inner .= D2G_Block_Builder::block( 'paragraph', [ 'fontSize' => 'small' ], '<p class="has-small-font-size"><em>' . D2G_Block_Builder::text( $position ) . '</em></p>' );
        }
        $inner .= $this->context->render_inner_blocks( $node, $attrs );

        // Social links from attrs.
        $socials = D2G_Block_Builder::social_links_from_attrs( $attrs );
        if ( $socials ) {
            $inner .= D2G_Block_Builder::social_links( $socials );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function social_follow( array $node ): string {
        // Networks are batched into one core/social-links block, so a run of
        // them renders together; anything else in the module renders where it
        // stands instead of being dropped on the floor.
        return $this->context->render_structural_children(
            $node,
            [ 'et_pb_social_media_follow_network' ],
            function ( array $networks ) {
                $links = [];
                foreach ( $networks as $child ) {
                    $network = $child['attrs']['social_network'] ?? '';
                    if ( $network ) {
                        $links[ $network ] = $child['attrs']['url'] ?? '#';
                    }
                }
                return D2G_Block_Builder::social_links( $links );
            },
            $node['attrs']
        );
    }

    protected function social_network( array $node ): string {
        $attrs   = $node['attrs'];
        $network = $attrs['social_network'] ?? '';
        $url     = D2G_Block_Builder::url( $attrs['url'] ?? '#' );

        if ( ! $network || '' === $url ) {
            return '';
        }

        return D2G_Block_Builder::block( 'social-link', [ 'url' => $url, 'service' => $network ] );
    }
}
