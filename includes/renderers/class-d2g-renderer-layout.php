<?php
/**
 * Divi's layout containers: sections, rows and columns.
 *
 * The mapping is not one-to-one. Divi nests section > row > column and lets a
 * row hold a single full-width column; Gutenberg requires a core/column to sit
 * inside a core/columns, so every row with at least one column is wrapped even
 * when wrapping looks redundant. A row with no columns passes its content
 * straight through.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Layout extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    public static function tags(): array {
        return [
            'et_pb_section'      => 'section',
            'et_pb_row'          => 'row',
            'et_pb_row_inner'    => 'row',
            'et_pb_column'       => 'column',
            'et_pb_column_inner' => 'column',
        ];
    }

    protected function section( array $node ): string {
        $attrs = $node['attrs'];
        $is_fullwidth = ( $attrs['fullwidth'] ?? '' ) === 'on';

        $inner = $this->context->render_nodes( $node['children'] );

        $layout = $is_fullwidth ? 'full' : 'constrained';
        $block_attrs = [ 'layout' => [ 'type' => $layout ] ];

        $classes = [ 'wp-block-group' ];
        $style_parts = [];

        $bg_color = D2G_Block_Builder::css_color( $attrs['background_color'] ?? '' );
        if ( '' !== $bg_color ) {
            $block_attrs['style'] = [ 'color' => [ 'background' => $bg_color ] ];
            $classes[] = 'has-background';
            $style_parts[] = 'background-color:' . $bg_color;
        }

        // A section keeps its background colour but not its background image:
        // core/group has no image background, and turning the section into a
        // core/cover would change the layout semantics of everything inside it.
        if ( ! empty( $attrs['background_image'] ) ) {
            $this->warn(
                'et_pb_section',
                __( 'A section had a background image. The Group block it became has no image background, so the image was not carried over — rebuild it with a Cover block or theme styles.', 'block-converter-for-divi' )
            );
        }

        $class_str = implode( ' ', $classes );
        $style_str = $style_parts ? ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"' : '';

        $inner_html = '<div class="' . $class_str . '"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', $block_attrs, $inner_html, true );
    }

    protected function row( array $node ): string {
        $attrs = $node['attrs'];

        // Determine column layout from children.
        $col_count = 0;
        foreach ( $node['children'] as $child ) {
            if ( in_array( $child['tag'], [ 'et_pb_column', 'et_pb_column_inner' ], true ) ) {
                $col_count++;
            }
        }

        $inner = $this->context->render_nodes( $node['children'] );

        // A core/column block must be nested inside core/columns.
        // Divi rows commonly have a single column (type 4_4), so we still
        // need a columns wrapper to avoid Gutenberg block validation errors.
        if ( $col_count >= 1 ) {
            $columns_html = '<div class="wp-block-columns">' . "\n" . $inner . "\n" . '</div>';
            return D2G_Block_Builder::block( 'columns', [], $columns_html, true );
        }

        return $inner;
    }

    protected function column( array $node ): string {
        $attrs = $node['attrs'];
        $inner = $this->context->render_nodes( $node['children'] );

        // Map Divi column type to width.
        $type  = $attrs['type'] ?? '';
        $width = D2G_Block_Builder::column_width( $type );
        $block_attrs = [];
        $style_str = '';
        if ( $width ) {
            $block_attrs['width'] = $width;
            $style_str = ' style="flex-basis:' . esc_attr( $width ) . '"';
        }

        $col_html = '<div class="wp-block-column"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'column', $block_attrs, $col_html, true );
    }
}
