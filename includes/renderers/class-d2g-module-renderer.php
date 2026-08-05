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
}
