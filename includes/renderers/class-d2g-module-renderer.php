<?php
/**
 * Base class for the renderers that turn one family of Divi modules into blocks.
 *
 * The converter used to be a single 2,600-line class holding every renderer, a
 * 170-line dispatch switch, the HTML-to-blocks engine, and the markup
 * primitives. Nothing was wrong with any individual renderer; the problem was
 * that adding a module meant editing the same file as fixing a parser bug, and
 * a reader looking for "what does a Gallery become" had to find it among fifty
 * neighbours.
 *
 * Each subclass answers one question — which tags it handles, and what each
 * becomes — and declares that mapping in tags() so the converter can build its
 * dispatch table from the renderers themselves. Adding a module is now adding a
 * method and a line to a map, in the file where its siblings already live.
 *
 * Renderers hold no state between calls. Anything shared — warning collection,
 * recursion back into the node tree, the HTML engine — is reached through the
 * converter, which is passed in as the render context.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class D2G_Module_Renderer {

    /**
     * Divi settings this renderer turns into block attributes, per tag.
     *
     * The loss reporter reads this so it stops naming a setting that was
     * actually carried over. Declared by the renderer that does the mapping,
     * for the same reason tags() is: the converter should not hold a second
     * copy of knowledge that lives in a renderer, because the two drift and the
     * report is what users trust.
     *
     * Empty by default — a renderer that maps no design settings says so by
     * saying nothing.
     *
     * @return array<string, string[]> Divi tag => attribute names.
     */
    public static function mapped_style_attrs(): array {
        return [];
    }

    /**
     * The conversion this renderer belongs to.
     *
     * Not a service locator: it is the *document* being converted. Renderers
     * need it to recurse into child nodes, to hand free-form HTML to the shared
     * engine, and to report what could not be carried over.
     *
     * @var D2G_Converter
     */
    protected $context;

    public function __construct( D2G_Converter $context ) {
        $this->context = $context;
    }

    /**
     * Divi tag => the method on this class that renders it.
     *
     * The converter builds its whole dispatch table by asking each registered
     * renderer for this map, so a tag can never be handled by a method that
     * does not exist, and two renderers claiming the same tag is a collision
     * that shows up at registration rather than at conversion time.
     *
     * @return array<string, string>
     */
    abstract public static function tags(): array;

    /**
     * Render one node by dispatching to whichever method claims its tag.
     */
    public function render( array $node ): string {
        $map    = static::tags();
        $method = $map[ $node['tag'] ] ?? '';

        if ( '' === $method || ! method_exists( $this, $method ) ) {
            return '';
        }

        return $this->{$method}( $node );
    }

    /**
     * Record something this module could not carry over faithfully.
     *
     * Deduplicated by the converter, so a page with forty padded sections
     * produces one line rather than forty.
     */
    protected function warn( string $module, string $message ) {
        $this->context->add_warning( $module, $message );
    }

    /**
     * A content module's own padding and margin, as core would serialize them.
     *
     * 2.4.0 mapped spacing onto section, row and column — the containers. It
     * stopped there, and on a real corpus that left 835 spacing values on the
     * modules *inside* those containers reported as lost: a Text module's
     * padding, a Gallery's margin. Core supports spacing on every block those
     * become, so there was nothing to stop it beyond nobody having measured it.
     *
     * Built through the same wrapper_styles() the layout renderer uses, with no
     * background and no border, so the declaration order matches what core
     * writes: margin before padding, sides omitted rather than zeroed.
     *
     * @return array{attrs: array, classes: string[], css: string[]}
     */
    protected function spacing_styles( array $attrs ): array {
        return D2G_Block_Builder::wrapper_styles(
            '',
            D2G_Block_Builder::spacing_box( $attrs['custom_margin'] ?? '' ),
            D2G_Block_Builder::spacing_box( $attrs['custom_padding'] ?? '' )
        );
    }

    /**
     * The spacing attribute names spacing_styles() consumes.
     *
     * Merged into a renderer's mapped_style_attrs() so the loss reporter stops
     * naming them. Exact names: the `_tablet`, `_phone` and `__hover` variants
     * are still lost and still say so.
     *
     * @return string[]
     */
    protected static function spacing_attrs(): array {
        return [ 'custom_padding', 'custom_margin' ];
    }

    /**
     * Fold a style bundle into a block's attributes and its inline CSS.
     *
     * @param array    $block_attrs Block attributes, modified in place.
     * @param array    $styles      From spacing_styles().
     * @return string  The style attribute's value, or '' when there is none.
     */
    protected static function apply_styles( array &$block_attrs, array $styles ): string {
        if ( ! empty( $styles['attrs']['style'] ) ) {
            // Merged one level down, not assigned. `style` is a bag shared with
            // colour and typography, and a plain array_merge() here would drop
            // whatever a renderer had already put in it — silently, and only on
            // the modules that set both.
            $block_attrs['style'] = array_merge(
                isset( $block_attrs['style'] ) && is_array( $block_attrs['style'] ) ? $block_attrs['style'] : [],
                $styles['attrs']['style']
            );
        }

        return empty( $styles['css'] ) ? '' : implode( ';', $styles['css'] );
    }
}
