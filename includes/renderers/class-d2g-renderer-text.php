<?php
/**
 * Divi's raw-content modules: Text and Code.
 *
 * Text hands its body to the shared HTML engine, which is where all the real
 * work happens. Code is deliberately the opposite: its content is preserved
 * byte for byte in a core/html block, because a code module holds markup the
 * author wrote on purpose and any "improvement" to it is a bug.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Text extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    /**
     * Verified by measurement, not assumed.
     *
     * A module's body goes through the HTML engine, so body_* typography
     * reaches the paragraphs it produces. header_* only lands where the
     * *headings* also come from that engine, which for now is the Text module
     * alone — every other module builds its headings directly in its renderer
     * from an attribute, and never sees header_font_size at all.
     *
     * Claiming otherwise would be the worse of the two errors: reporting a loss
     * that did not happen trains users to ignore the report, but staying quiet
     * about one that did is how they lose something without being told.
     *
     * @return array<string, string[]>
     */
    public static function mapped_style_attrs(): array {
        return [
            'et_pb_text' => array_merge(
                [
                    'body_text_color', 'body_font_size', 'body_line_height', 'body_letter_spacing',
                    'header_text_color', 'header_font_size', 'header_line_height', 'header_letter_spacing',
                ],
                self::spacing_attrs()
            ),
        ];
    }

    public static function tags(): array {
        return [
            'et_pb_text'           => 'text_module',
            'et_pb_code'           => 'code',
            'et_pb_fullwidth_code' => 'code',
        ];
    }

    protected function text_module( array $node ): string {
        // Divi lets other modules sit inside a Text module. Rendering the whole
        // inner span keeps them; reading only the loose text dropped them.
        $inner = $this->context->render_inner_blocks( $node, $node['attrs'] );

        if ( '' === trim( $inner ) ) {
            return $inner;
        }

        // A Text module becomes several sibling blocks, so unlike an image or a
        // gallery it has no single block of its own to carry its padding. The
        // box Divi drew around those paragraphs *is* a group, and a group is
        // already what the section around it converts to, so wrapping is the
        // honest mapping rather than a workaround.
        //
        // Only when there is spacing to carry. A page whose Text modules set no
        // padding — which is most of them — gains no wrappers at all, and the
        // markup stays as flat as it was before this existed.
        $group_attrs = [];
        $css         = self::apply_styles( $group_attrs, $this->spacing_styles( $node['attrs'] ) );

        if ( '' === $css ) {
            return $inner;
        }

        $html = '<div class="wp-block-group" style="' . esc_attr( $css ) . '">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', $group_attrs, $html, true );
    }

    protected function code( array $node ): string {
        $content = $this->context->get_inner_content( $node );
        if ( '' === trim( $content ) ) {
            return '';
        }
        return D2G_Block_Builder::block( 'html', [], $content );
    }
}
