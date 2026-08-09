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

    /**
     * The background colour and spacing a layout module carries, validated.
     *
     * Divi can hold a background colour with the colour switch turned off, and
     * painting it anyway would put a background on a section the author had
     * deliberately cleared.
     *
     * @return array{attrs: array, classes: string[], css: string[]}
     */
    private function wrapper_styles_for( array $attrs ): array {
        $enabled  = ( $attrs['background_enable_color'] ?? 'on' ) !== 'off';
        $bg_color = $enabled ? D2G_Block_Builder::css_color( $attrs['background_color'] ?? '' ) : '';

        return D2G_Block_Builder::wrapper_styles(
            $bg_color,
            D2G_Block_Builder::spacing_box( $attrs['custom_margin'] ?? '' ),
            D2G_Block_Builder::spacing_box( $attrs['custom_padding'] ?? '' )
        );
    }

    /**
     * The Divi settings these renderers now turn into block attributes.
     *
     * Declared here rather than listed in the converter so the two cannot
     * drift: the loss reporter reads this to stop reporting a setting that was
     * carried over. A report that fires for settings the converter *did* map is
     * how users learn to ignore the report — the same reasoning as N-05.
     *
     * Exact names, not patterns. custom_padding_tablet is still lost, and still
     * says so.
     *
     * @return array<string, string[]>
     */
    public static function mapped_style_attrs(): array {
        $mapped = [ 'custom_padding', 'custom_margin', 'background_color' ];
        $out    = [];
        foreach ( array_keys( self::tags() ) as $tag ) {
            $out[ $tag ] = $mapped;
        }
        return $out;
    }

    protected function section( array $node ): string {
        $attrs = $node['attrs'];
        $is_fullwidth = ( $attrs['fullwidth'] ?? '' ) === 'on';

        $inner = $this->context->render_nodes( $node['children'] );

        $layout = $is_fullwidth ? 'full' : 'constrained';

        $styles      = $this->wrapper_styles_for( $attrs );
        $block_attrs = $styles['attrs'] + [ 'layout' => [ 'type' => $layout ] ];

        $classes     = array_merge( [ 'wp-block-group' ], $styles['classes'] );
        $style_parts = $styles['css'];

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
            $styles    = $this->wrapper_styles_for( $attrs );
            $classes   = implode( ' ', array_merge( [ 'wp-block-columns' ], $styles['classes'] ) );
            $style_str = $styles['css'] ? ' style="' . esc_attr( implode( ';', $styles['css'] ) ) . '"' : '';

            $columns_html = '<div class="' . $classes . '"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';
            return D2G_Block_Builder::block( 'columns', $styles['attrs'], $columns_html, true );
        }

        return $inner;
    }

    protected function column( array $node ): string {
        $attrs = $node['attrs'];
        $inner = $this->context->render_nodes( $node['children'] );

        // Map Divi column type to width.
        $type  = $attrs['type'] ?? '';
        $width = D2G_Block_Builder::column_width( $type );

        $styles      = $this->wrapper_styles_for( $attrs );
        $block_attrs = [];
        $declarations = $styles['css'];

        if ( $width ) {
            $block_attrs['width'] = $width;
            // Last, because that is where core's serializer puts it. Ordering
            // this before the spacing declarations makes every converted column
            // report as invalid content.
            $declarations[] = 'flex-basis:' . $width;
        }

        $block_attrs += $styles['attrs'];

        $classes   = implode( ' ', array_merge( [ 'wp-block-column' ], $styles['classes'] ) );
        $style_str = $declarations ? ' style="' . esc_attr( implode( ';', $declarations ) ) . '"' : '';

        $col_html = '<div class="' . $classes . '"' . $style_str . '>' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'column', $block_attrs, $col_html, true );
    }
}
