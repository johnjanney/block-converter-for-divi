<?php
/**
 * Modules whose point is behaviour rather than content: accordions, toggles,
 * tabs, sliders and counters.
 *
 * Core has one of these — core/details — and it arrived in WordPress 6.3, so
 * even that is feature-detected. Everything else loses its behaviour: tabs
 * become stacked sections with every panel visible, sliders show all their
 * slides at once, counters become static text.
 *
 * Every renderer here therefore raises a warning saying what stopped working.
 * That is not decoration — a user who converts a tabbed page and is not told
 * discovers it from a visitor.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Interactive extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    public static function tags(): array {
        return [
            'et_pb_accordion'             => 'accordion',
            'et_pb_toggle'                => 'toggle',
            'et_pb_tabs'                  => 'tabs',
            'et_pb_tab'                   => 'tab',
            'et_pb_slider'                => 'slider',
            'et_pb_fullwidth_slider'      => 'slider',
            'et_pb_post_slider'           => 'slider',
            'et_pb_fullwidth_post_slider' => 'slider',
            'et_pb_slide'                 => 'slide',
            'et_pb_counters'              => 'counters',
            'et_pb_counter'               => 'counter',
            'et_pb_number_counter'        => 'number_counter',
            'et_pb_circle_counter'        => 'circle_counter',
        ];
    }

    protected function accordion( array $node ): string {
        $this->warn(
            'et_pb_accordion',
            __( 'An accordion became a series of independent Details blocks. Each one opens and closes on its own; opening one no longer closes the others.', 'block-converter-for-divi' )
        );

        // Convert each toggle child into a details block.
        $inner = '';
        foreach ( $node['children'] as $child ) {
            $inner .= $this->context->render_node( $child );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function toggle( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? __( 'Toggle', 'block-converter-for-divi' );
        $is_open = ( $attrs['open'] ?? '' ) === 'on';
        $body    = $this->context->render_inner_blocks( $node, $attrs );

        // core/details arrived in WordPress 6.3. On anything older the block is
        // unregistered, so the editor would show "your site doesn't include
        // support for this block" instead of the content.
        if ( ! $this->context->block_supported( 'core/details' ) ) {
            $this->warn(
                'et_pb_toggle',
                __( 'Toggles and accordions were converted to headings and text because this WordPress version has no Details block (added in 6.3).', 'block-converter-for-divi' )
            );
            $inner = D2G_Block_Builder::block( 'heading', [ 'level' => 3 ], '<h3>' . D2G_Block_Builder::text( $title ) . '</h3>' ) . $body;
            return D2G_Block_Builder::block( 'group', [], '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>', true );
        }

        // showContent is what core/details reads to decide whether to write the
        // `open` attribute back out. Emitting `open` without it makes the saved
        // markup disagree with the regenerated markup, and the block is flagged
        // as invalid the first time the page is opened in the editor.
        $block_attrs = $is_open ? [ 'showContent' => true ] : [];
        $open_attr   = $is_open ? ' open' : '';

        $html = '<details class="wp-block-details"' . $open_attr . '><summary>' . D2G_Block_Builder::text( $title ) . '</summary>';
        if ( '' !== trim( $body ) ) {
            $html .= "\n" . $body;
        }
        $html .= '</details>';

        return D2G_Block_Builder::block( 'details', $block_attrs, $html, true );
    }

    protected function tabs( array $node ): string {
        $this->warn(
            'et_pb_tabs',
            __( 'A tabs module became a stack of headed sections, one per tab. Core has no tabs block, so all panels are now visible at once instead of one at a time.', 'block-converter-for-divi' )
        );

        // Dispatched through render_node() rather than calling tab()
        // directly, so each child also passes the unmapped-style check. Calling
        // the renderer straight meant a tab with its own padding was converted
        // but never reported.
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( 'et_pb_tab' === $child['tag'] ) {
                $inner .= $this->context->render_node( $child );
            }
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function tab( array $node ): string {
        $attrs   = $node['attrs'];
        $title   = $attrs['title'] ?? __( 'Tab', 'block-converter-for-divi' );

        $heading = D2G_Block_Builder::block( 'heading', [ 'level' => 3 ], '<h3>' . D2G_Block_Builder::text( $title ) . '</h3>' );
        $body    = $this->context->render_inner_blocks( $node, $attrs );

        $html = '<div class="wp-block-group">' . "\n" . $heading . $body . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function slider( array $node ): string {
        $this->warn(
            $node['tag'],
            __( 'A slider became a stack of sections, one per slide. Core has no slider block, so every slide is now shown at once and the auto-advance, arrows and dots are gone.', 'block-converter-for-divi' )
        );

        $inner = '';
        foreach ( $node['children'] as $child ) {
            $inner .= $this->context->render_node( $child );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function slide( array $node ): string {
        $attrs   = $node['attrs'];
        $heading = $attrs['heading'] ?? '';
        $bg_img  = $attrs['background_image'] ?? $attrs['image'] ?? '';
        $btn_text = $attrs['button_text'] ?? '';
        $btn_url  = $attrs['button_link'] ?? $attrs['link'] ?? '#';

        $inner = '';

        if ( $heading ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 2 ], '<h2>' . D2G_Block_Builder::text( $heading ) . '</h2>' );
        }
        $inner .= $this->context->render_inner_blocks( $node, $attrs );
        if ( $btn_text ) {
            $btn_inner = D2G_Block_Builder::block( 'button', [], '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $btn_url ) . '">' . D2G_Block_Builder::text( $btn_text ) . '</a></div>' );
            $inner .= D2G_Block_Builder::block( 'buttons', [], '<div class="wp-block-buttons">' . "\n" . $btn_inner . "\n" . '</div>', true );
        }

        if ( $bg_img ) {
            return D2G_Block_Builder::cover( $bg_img, D2G_Block_Builder::css_color( $attrs['background_color'] ?? '' ), $inner );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function counters( array $node ): string {
        $inner = '';
        foreach ( $node['children'] as $child ) {
            if ( 'et_pb_counter' === $child['tag'] ) {
                $inner .= $this->context->render_node( $child );
            }
        }
        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function counter( array $node ): string {
        $attrs   = $node['attrs'];
        $percent = $attrs['percent'] ?? '0';

        // A counter's label is plain text in a bar chart, but Divi stores it as
        // the module's body, which is HTML. Escaping that HTML wholesale put the
        // *markup* on the page: a body of `<p>Sales</p>` was published as the
        // visible characters `&lt;p&gt;Sales&lt;/p&gt;`. Reduce it to its words
        // first, then escape those.
        $label = trim( wp_strip_all_tags( $this->context->get_inner_content( $node ), true ) );
        if ( '' === $label ) {
            $label = (string) ( $attrs['title'] ?? '' );
        }

        $this->warn(
            'et_pb_counter',
            __( 'Bar counters became a line of text showing their label and percentage. Core has no animated bar-counter block, so the bar and its animation were not carried over.', 'block-converter-for-divi' )
        );

        $html = '<p><strong>' . D2G_Block_Builder::text( $label ) . '</strong>: ' . esc_html( $percent ) . '%</p>';
        return D2G_Block_Builder::block( 'paragraph', [], $html );
    }

    protected function number_counter( array $node ): string {
        $this->warn(
            $node['tag'],
            __( 'A counter became a static heading showing its final value. Core has no counter block, so the count-up animation was not carried over.', 'block-converter-for-divi' )
        );

        $attrs  = $node['attrs'];
        $number = $attrs['number'] ?? '0';
        $title  = $attrs['title'] ?? '';
        $percent = $attrs['percent_sign'] ?? 'on';

        $display = esc_html( $number );
        if ( $percent === 'on' ) {
            $display .= '%';
        }

        $inner = D2G_Block_Builder::block(
            'heading',
            [ 'textAlign' => 'center', 'level' => 2 ],
            '<h2 class="has-text-align-center">' . $display . '</h2>'
        );
        if ( $title ) {
            $inner .= D2G_Block_Builder::block( 'paragraph', [ 'align' => 'center' ], '<p class="has-text-align-center">' . D2G_Block_Builder::text( $title ) . '</p>' );
        }

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }

    protected function circle_counter( array $node ): string {
        return $this->number_counter( $node );
    }
}
