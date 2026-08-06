<?php
/**
 * Divi pricing tables.
 *
 * Separated from the other content modules because the three tags form one
 * structure — a table of tables of items — and because the item tag is the
 * defect this file exists to prevent coming back: [et_pb_pricing_item] had no
 * renderer at all, so the shortcodes themselves were printed into the converted
 * page as visible text once Divi was gone.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Pricing extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    public static function tags(): array {
        return [
            'et_pb_pricing_tables' => 'pricing_tables',
            'et_pb_pricing_table'  => 'pricing_table',
            'et_pb_pricing_item'   => 'pricing_item',
        ];
    }

    protected function pricing_tables( array $node ): string {
        $inner = $this->context->render_structural_children(
            $node,
            [ 'et_pb_pricing_table' ],
            function ( array $tables ) {
                $out = '';
                foreach ( $tables as $table ) {
                    $out .= $this->context->render_node( $table );
                }
                return $out;
            },
            $node['attrs']
        );

        $html = '<div class="wp-block-columns">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'columns', [], $html, true );
    }

    protected function pricing_table( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? '';
        $subtitle = $attrs['subtitle'] ?? '';
        $currency = $attrs['currency'] ?? '$';
        $sum     = $attrs['sum'] ?? '';
        $period  = $attrs['per'] ?? '';
        $btn_text = $attrs['button_text'] ?? '';
        $btn_url  = $attrs['button_url'] ?? '#';

        $inner = '';
        if ( $title ) {
            // textAlign has to accompany the has-text-align-center class, or
            // core/heading regenerates the markup without it and the block is
            // reported as invalid.
            $inner .= D2G_Block_Builder::block(
                'heading',
                [ 'textAlign' => 'center', 'level' => 3 ],
                '<h3 class="has-text-align-center">' . D2G_Block_Builder::text( $title ) . '</h3>'
            );
        }
        if ( $subtitle ) {
            $inner .= D2G_Block_Builder::block( 'paragraph', [ 'align' => 'center' ], '<p class="has-text-align-center">' . D2G_Block_Builder::text( $subtitle ) . '</p>' );
        }
        if ( $sum ) {
            $price_text = D2G_Block_Builder::text( $currency ) . D2G_Block_Builder::text( $sum );
            if ( $period ) {
                $price_text .= '<small>/' . D2G_Block_Builder::text( $period ) . '</small>';
            }
            $inner .= D2G_Block_Builder::block(
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
        //
        // The traversal is shared with every other structural parent, so a
        // table that also holds loose text or another module keeps both. It
        // used to keep the items and drop everything else, silently.
        if ( empty( $node['children'] ) ) {
            $inner .= $this->context->render_inner_blocks( $node, $attrs );
        } else {
            $inner .= $this->context->render_structural_children(
                $node,
                [ 'et_pb_pricing_item' ],
                function ( array $items ) {
                    return $this->pricing_items( $items );
                },
                $attrs
            );
        }

        if ( $btn_text ) {
            $btn_inner = D2G_Block_Builder::block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . D2G_Block_Builder::text( $btn_text ) . '</a></div>' );
            $inner .= D2G_Block_Builder::block( 'buttons', [ 'layout' => [ 'type' => 'flex', 'justifyContent' => 'center' ] ], '<div class="wp-block-buttons is-content-justification-center">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        $html = '<div class="wp-block-column">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'column', [], $html, true );
    }

    /**
     * A pricing item that turned up outside a pricing table.
     *
     * Normally consumed by pricing_table(). This exists so a stray item is
     * still rendered rather than falling through to the unknown-module path.
     */
    protected function pricing_item( array $node ): string {
        return $this->pricing_items( [ $node ] );
    }

    /**
     * Render [et_pb_pricing_item] nodes as a core/list of core/list-item blocks.
     *
     * Divi marks a struck-through (unavailable) feature with available="off";
     * that is carried over as a <s> element so the distinction survives.
     */
    private function pricing_items( array $items ): string {
        $list_items = '';

        foreach ( $items as $item ) {
            $text = trim( wp_strip_all_tags( $this->context->get_inner_content( $item ), true ) );
            if ( '' === $text ) {
                continue;
            }

            $label = D2G_Block_Builder::text( $text );
            if ( ( $item['attrs']['available'] ?? 'on' ) === 'off' ) {
                $label = '<s>' . $label . '</s>';
            }

            $list_items .= D2G_Block_Builder::block( 'list-item', [], '<li>' . $label . '</li>' );
        }

        if ( '' === $list_items ) {
            return '';
        }

        return D2G_Block_Builder::block(
            'list',
            [],
            '<ul class="wp-block-list">' . "\n" . $list_items . '</ul>',
            true
        );
    }
}
