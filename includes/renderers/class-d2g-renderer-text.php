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
        return $this->context->render_inner_blocks( $node, $node['attrs'] );
    }

    protected function code( array $node ): string {
        $content = $this->context->get_inner_content( $node );
        if ( '' === trim( $content ) ) {
            return '';
        }
        return D2G_Block_Builder::block( 'html', [], $content );
    }
}
