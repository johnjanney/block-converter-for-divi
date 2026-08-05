<?php
/**
 * Modules whose subject is a piece of media: images, video, audio, galleries
 * and maps.
 *
 * Maps are here because a Divi map *is* embedded media, even though core has no
 * map block and the conversion has to fall back to text plus a warning.
 *
 * The attachment helpers at the end are the one place this plugin talks to the
 * media library, and they are deliberately forgiving: a gallery whose
 * attachments have been deleted drops those images rather than emitting a
 * broken <img src="">.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Media extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    public static function tags(): array {
        return [
            'et_pb_image'           => 'image',
            'et_pb_fullwidth_image' => 'image',
            'et_pb_video'           => 'video',
            'et_pb_video_slider'    => 'video_slider',
            'et_pb_audio'           => 'audio',
            'et_pb_gallery'         => 'gallery',
            'et_pb_map'             => 'map',
            'et_pb_fullwidth_map'   => 'map',
        ];
    }

    protected function image( array $node ): string {
        $attrs = $node['attrs'];
        $src   = $attrs['src'] ?? '';
        if ( '' === $src ) {
            return '';
        }

        $alt   = $attrs['alt'] ?? '';
        $url   = $attrs['url'] ?? '';

        // core/image regenerates its `align<value>` class from this attribute,
        // so it can only be one of the alignments the block models.
        $align = D2G_Block_Builder::allowed_value(
            $attrs['align'] ?? '',
            [ 'left', 'center', 'right', 'wide', 'full' ]
        );

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
        $img .= ' alt="' . D2G_Block_Builder::attr( $alt ) . '"';
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

        $caption = $this->context->get_inner_content( $node );
        $caption = trim( strip_tags( $caption, '<a><em><strong><br>' ) );

        $figure = '<figure class="' . esc_attr( $figure_class ) . '">' . $img;
        if ( $caption ) {
            $figure .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
        }
        $figure .= '</figure>';

        return D2G_Block_Builder::block( 'image', $block_attrs, $figure );
    }

    protected function video( array $node ): string {
        $attrs = $node['attrs'];
        $src   = $attrs['src'] ?? '';

        // Fallback to alternative source attributes.
        if ( '' === $src ) {
            $src = $attrs['src_webm'] ?? '';
        }

        // Check if the inner content contains an iframe embed (common in Divi).
        $content = $this->context->get_inner_content( $node );
        if ( '' === $src && preg_match( '#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $content, $m ) ) {
            return $this->context->embed_tag( $content );
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
            return D2G_Block_Builder::block( 'embed', [ 'url' => $src, 'type' => 'video', 'providerNameSlug' => $provider ], $html );
        }

        // Self-hosted video.
        $html = '<figure class="wp-block-video"><video controls src="' . esc_url( $src ) . '"></video></figure>';
        return D2G_Block_Builder::block( 'video', [ 'src' => $src ], $html );
    }

    protected function video_slider( array $node ): string {
        $this->warn(
            'et_pb_video_slider',
            __( 'A video slider became a stack of video blocks. Core has no video slider, so every video is now shown at once and the thumbnail navigation is gone.', 'block-converter-for-divi' )
        );

        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_video_slider_item' ) {
                $src = $child['attrs']['src'] ?? '';
                if ( $src ) {
                    $inner .= $this->video( $child );
                }
            }
        }

        if ( '' === $inner ) {
            return '';
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function audio( array $node ): string {
        $attrs   = $node['attrs'];
        $src     = $attrs['audio'] ?? '';
        $title   = $attrs['title'] ?? '';
        $artist  = $attrs['artist_name'] ?? '';
        $image   = $attrs['image_url'] ?? '';

        if ( '' === $src ) {
            return '';
        }

        $inner = '';
        if ( $image ) {
            $inner .= D2G_Block_Builder::block( 'image', [ 'sizeSlug' => 'large', 'linkDestination' => 'none' ], '<figure class="wp-block-image size-large"><img src="' . esc_url( $image ) . '" alt="' . D2G_Block_Builder::attr( $title ) . '"/></figure>' );
        }
        if ( $title ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 4 ], '<h4>' . D2G_Block_Builder::text( $title ) . '</h4>' );
        }
        if ( $artist ) {
            $inner .= D2G_Block_Builder::block( 'paragraph', [], '<p>' . D2G_Block_Builder::text( $artist ) . '</p>' );
        }

        $inner .= D2G_Block_Builder::block( 'audio', [ 'src' => $src ], '<figure class="wp-block-audio"><audio controls src="' . esc_url( $src ) . '"></audio></figure>' );

        if ( $title || $artist || $image ) {
            $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
            return D2G_Block_Builder::block( 'group', [], $html, true );
        }

        return $inner;
    }

    protected function gallery( array $node ): string {
        $attrs     = $node['attrs'];
        $ids_str   = $attrs['gallery_ids'] ?? '';
        $columns   = (int) ( $attrs['gallery_columns'] ?? $attrs['columns_number'] ?? 3 );
        $show_cap  = ( $attrs['show_title_and_caption'] ?? '' ) !== 'off';

        // Divi gallery modules can render as either a grid or a slider/carousel.
        // Preserve that intent by carrying the format through to the converted block.
        $layout_hint  = strtolower( (string) ( $attrs['gallery_layout'] ?? $attrs['layout'] ?? $attrs['type'] ?? '' ) );
        $is_carousel  = ( $attrs['fullwidth'] ?? '' ) === 'on' || in_array( $layout_hint, [ 'slider', 'carousel' ], true );

        if ( '' === $ids_str ) {
            return D2G_Block_Builder::block( 'paragraph', [], '<p>' . esc_html__( '[Gallery — no images specified]', 'block-converter-for-divi' ) . '</p>' );
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

        // Each image below asks for a URL, an alt text and a caption. Without
        // priming, that is three uncached queries per image — a forty-image
        // gallery cost 120 round trips. One warm-up call covers the lot.
        if ( $ids && function_exists( '_prime_post_caches' ) ) {
            _prime_post_caches( $ids, false, true );
        }

        $gallery_attrs = [
            'columns'   => $columns,
            'linkTo'    => $link_to,
            'imageCrop' => true,
        ];

        if ( $is_carousel ) {
            // Core/gallery doesn't support a native carousel block attribute.
            $gallery_attrs['className'] = 'd2g-gallery-slider';
            $this->warn(
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

            $images_markup .= D2G_Block_Builder::block( 'image', $img_attrs, $fig_html );
        }

        $gallery_classes = 'wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped';
        if ( $is_carousel ) {
            $gallery_classes .= ' d2g-gallery-slider';
        }
        $gallery_html    = '<figure class="' . esc_attr( $gallery_classes ) . '">' . "\n" . $images_markup . '</figure>';
        return D2G_Block_Builder::block( 'gallery', $gallery_attrs, $gallery_html, true );
    }

    protected function map( array $node ): string {
        $attrs   = $node['attrs'];
        $address = $attrs['address'] ?? '';
        $lat     = $attrs['address_lat'] ?? '';
        $lng     = $attrs['address_lng'] ?? '';

        $map_label = '<strong>' . esc_html__( 'Map:', 'block-converter-for-divi' ) . '</strong> ';

        $map_content = '';
        if ( $address ) {
            $map_content = '<p>' . $map_label . D2G_Block_Builder::text( $address ) . '</p>';
        } elseif ( $lat && $lng ) {
            $map_content = '<p>' . $map_label . D2G_Block_Builder::text( $lat ) . ', ' . D2G_Block_Builder::text( $lng ) . '</p>';
        }

        // Render map pins.
        foreach ( $node['children'] as $child ) {
            if ( $child['tag'] === 'et_pb_map_pin' ) {
                $pin_title = $child['attrs']['title'] ?? '';
                $pin_content = $this->context->get_inner_content( $child );
                if ( $pin_title ) {
                    $map_content .= '<p><strong>' . D2G_Block_Builder::text( $pin_title ) . '</strong>';
                    if ( trim( $pin_content ) ) {
                        $map_content .= ': ' . $pin_content;
                    }
                    $map_content .= '</p>';
                }
            }
        }

        if ( $map_content ) {
            $this->warn(
                $node['tag'],
                __( 'A map module became text. Core has no map block — the address and any pin labels were preserved so the map can be rebuilt with a maps plugin or an embed.', 'block-converter-for-divi' )
            );
            return D2G_Block_Builder::block( 'group', [], '<div class="wp-block-group">' . "\n" . D2G_Block_Builder::block( 'html', [], $map_content ) . "\n" . '</div>', true );
        }

        return '';
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
