<?php
/**
 * Converts parsed Divi shortcode trees into Gutenberg block markup.
 *
 * This class used to be the whole conversion: every module renderer, a
 * 170-line dispatch switch, the HTML-to-blocks engine, and the markup
 * primitives, in 2,600 lines. It is now the orchestrator for those parts and
 * nothing else. What lives here is the work that is genuinely about *the
 * document* rather than about any one module:
 *
 *   - walking the parsed node tree and dispatching each node to a renderer
 *   - collecting the warnings that tell a user what will need rebuilding
 *   - the shared services renderers call back into: recursion, the HTML
 *     engine, and reading a node's own content
 *
 * The renderers register the tags they handle (see D2G_Module_Renderer), so the
 * dispatch table is assembled from them rather than maintained by hand next to
 * them.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Converter {

    /**
     * Renderer classes, in registration order.
     *
     * Each declares the Divi tags it handles. Two renderers claiming the same
     * tag is a programming error, and build_dispatch_table() says so rather
     * than silently letting the last one win.
     */
    private static $renderer_classes = [
        'D2G_Renderer_Layout',
        'D2G_Renderer_Text',
        'D2G_Renderer_Media',
        'D2G_Renderer_Content',
        'D2G_Renderer_Interactive',
        'D2G_Renderer_Pricing',
        'D2G_Renderer_Dynamic',
    ];

    /**
     * The registered renderer classes.
     *
     * Exposed so the test suite can snapshot the whole dispatch table: which
     * renderer owns which Divi tag is exactly the kind of thing a refactor
     * changes by accident, and a diff is the cheapest way to notice.
     *
     * @return string[]
     */
    public static function renderer_classes(): array {
        return self::$renderer_classes;
    }

    /**
     * @var D2G_Parser
     */
    private $parser;

    /**
     * @var D2G_HTML_Converter
     */
    private $html;

    /**
     * Divi tag => the renderer instance that handles it.
     *
     * @var array<string, D2G_Module_Renderer>
     */
    private $dispatch = [];

    /**
     * Divi tag => the design settings its renderer now maps.
     *
     * Assembled from the renderers, so report_unmapped_styles() cannot claim a
     * setting was lost that a renderer carried over.
     *
     * @var array<string, string[]>
     */
    private $mapped_styles = [];

    /**
     * Modules that could not be carried over faithfully in the last run.
     *
     * Collected rather than silently dropped so the preview can tell the user
     * what will need rebuilding by hand before they commit to a conversion.
     *
     * @var array<int, array{module: string, message: string}>
     */
    private $warnings = [];

    public function __construct() {
        $this->parser = new D2G_Parser();
        $this->html   = new D2G_HTML_Converter( $this );
        $this->build_dispatch_table();
    }

    /**
     * Ask every renderer which tags it handles and build the lookup from that.
     *
     * Doing this from the renderers, rather than from a switch statement living
     * apart from them, is the point of the split: a module cannot be registered
     * to a renderer that does not implement it, and a tag cannot be silently
     * claimed twice.
     */
    private function build_dispatch_table() {
        foreach ( self::$renderer_classes as $class ) {
            $renderer = new $class( $this );

            foreach ( $class::tags() as $tag => $method ) {
                if ( isset( $this->dispatch[ $tag ] ) ) {
                    // A collision means two renderers disagree about who owns a
                    // module. Silently keeping one would make the conversion
                    // depend on registration order.
                    if ( ! function_exists( '_doing_it_wrong' ) ) {
                        continue; // Outside WordPress (fixture suite).
                    }
                    _doing_it_wrong(
                        __METHOD__,
                        sprintf(
                            /* translators: 1: Divi tag, 2: renderer class name. */
                            esc_html__( 'The Divi tag "%1$s" is already handled by another renderer; %2$s cannot claim it too.', 'block-converter-for-divi' ),
                            esc_html( $tag ),
                            esc_html( $class )
                        ),
                        '2.2.0'
                    );
                    continue;
                }

                $this->dispatch[ $tag ] = $renderer;
            }

            foreach ( $class::mapped_style_attrs() as $tag => $names ) {
                $this->mapped_styles[ $tag ] = array_merge( $this->mapped_styles[ $tag ] ?? [], $names );
            }
        }
    }

    /**
     * Convert Divi shortcode content to Gutenberg block markup.
     */
    public function convert( string $content ): string {
        $this->warnings = [];

        if ( ! D2G_Parser::has_divi_content( $content ) ) {
            return $content;
        }

        $tree   = $this->parser->parse( $content );
        $output = $this->render_nodes( $tree );

        // Clean up excessive whitespace.
        $output = preg_replace( "/\n{3,}/", "\n\n", $output );
        return trim( $output );
    }

    /**
     * Modules from the last convert() call that need manual attention.
     *
     * @return array<int, array{module: string, message: string}>
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * Record a module that converted lossily or not at all.
     */
    public function add_warning( string $module, string $message ) {
        foreach ( $this->warnings as $existing ) {
            if ( $existing['module'] === $module && $existing['message'] === $message ) {
                return; // One entry per distinct problem, not one per instance.
            }
        }
        $this->warnings[] = [
            'module'  => $module,
            'message' => $message,
        ];
    }

    /**
     * Whether a core block is actually registered on this WordPress version.
     *
     * The converter can emit blocks that only exist on newer WordPress —
     * core/details arrived in 6.3. Emitting one on an older install leaves the
     * user with a "block not found" placeholder, so the renderer asks first and
     * falls back to blocks that have always existed.
     */
    public function block_supported( string $block_name ): bool {
        if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
            // Outside WordPress (fixture tests): assume a current install.
            return true;
        }
        return WP_Block_Type_Registry::get_instance()->is_registered( $block_name );
    }

    /**
     * Divi attribute names that carry a visual or behavioural setting this
     * converter does not reproduce.
     *
     * The project documents claimed unsupported settings were "reported rather
     * than silently dropped". Nothing detected them: a Section with
     * custom_padding lost its padding and the preview stayed empty. This list
     * is what makes that claim true. Every pattern is deliberately disjoint
     * from the attributes a renderer actually consumes, so a mapped setting
     * never produces a warning.
     */
    private static $unmapped_style_patterns = [
        '/^custom_(padding|margin)/'                  => 'spacing (padding or margin)',
        '/^(max_width|min_height|width|height)($|_)/'  => 'explicit sizing',
        '/^border_/'                                   => 'borders',
        '/^box_shadow_/'                               => 'box shadows',
        '/^text_shadow_/'                              => 'text shadows',
        '/_(font|font_size|line_height|letter_spacing)($|_)/' => 'typography',
        // `image` is deliberately absent: Fullwidth Header and Slide both map
        // background_image onto a core/cover URL. Section does not, and says so
        // for itself in D2G_Renderer_Layout::section().
        '/^background_(gradient|blend|size|position|repeat)/' => 'background treatment',
        '/^parallax/'                                  => 'parallax backgrounds',
        '/^(animation|filter_)/'                       => 'animation and filter effects',
        '/^custom_css/'                                => 'custom CSS',
        '/^module_(id|class)$/'                        => 'custom IDs and classes',
        '/^(transform_|sticky_|z_index|positioning)/'  => 'positioning and transforms',
        '/^disabled_on$/'                              => 'per-device visibility',
        '/(^hover_enabled$|__hover$)/'                 => 'hover styling',
        '/^(header_|body_)?text_color$/'               => 'text colour',
        '/_(tablet|phone)$/'                           => 'tablet and phone overrides',
    ];

    /**
     * Report the styling a module carried that this conversion cannot express.
     *
     * One warning per module tag per kind of loss, so a page with forty padded
     * sections produces one line, not forty.
     */
    private function report_unmapped_styles( string $module, array $attrs ) {
        $found  = [];
        $mapped = $this->mapped_styles[ $module ] ?? [];

        foreach ( $attrs as $name => $value ) {
            if ( '' === trim( (string) $value ) ) {
                continue;
            }

            // Carried over by this module's renderer, so not a loss. Matched by
            // exact name: custom_padding is mapped, custom_padding_tablet is
            // not, and only one of them should go quiet.
            if ( in_array( $name, $mapped, true ) ) {
                continue;
            }
            foreach ( self::$unmapped_style_patterns as $pattern => $label ) {
                if ( preg_match( $pattern, $name ) ) {
                    $found[ $label ] = true;
                    break;
                }
            }
        }

        if ( ! $found ) {
            return;
        }

        $this->add_warning(
            $module,
            sprintf(
                /* translators: %s: comma-separated list of Divi design settings, e.g. "spacing (padding or margin), borders". */
                __( 'Design settings were not carried over and need rebuilding with block or theme styles: %s.', 'block-converter-for-divi' ),
                implode( ', ', array_keys( $found ) )
            )
        );
    }

    /**
     * Render an array of parsed nodes into Gutenberg markup.
     */
    public function render_nodes( array $nodes ): string {
        $html = '';
        foreach ( $nodes as $node ) {
            $html .= $this->render_node( $node );
        }
        return $html;
    }

    /**
     * Render a single parsed node.
     *
     * The 170-line switch this replaced mapped 56 tags to renderer methods on
     * this class. The mapping now comes from the renderers themselves; what is
     * left here is the part that is true of every node regardless of type —
     * loose text is HTML, a module reports the design settings it lost, and a
     * module nobody claims still has to have its content preserved.
     */
    public function render_node( array $node ): string {
        $tag = $node['tag'];

        if ( '__text__' === $tag ) {
            return $this->convert_text_node( $node );
        }

        if ( ! empty( $node['attrs'] ) ) {
            $this->report_unmapped_styles( $tag, $node['attrs'] );
        }

        if ( isset( $this->dispatch[ $tag ] ) ) {
            return $this->dispatch[ $tag ]->render( $node );
        }

        return $this->convert_unknown( $node );
    }

    private function convert_text_node( array $node ): string {
        $content = trim( $node['content'] );
        if ( '' === $content ) {
            return '';
        }
        return $this->html_to_blocks( $content, [] );
    }

    private function convert_unknown( array $node ): string {
        if ( '__text__' !== $node['tag'] ) {
            $this->add_warning(
                $node['tag'],
                sprintf(
                    /* translators: %s: Divi shortcode tag. */
                    __( 'The module "%s" has no mapping. Its contents were preserved, but its layout and settings were not.', 'block-converter-for-divi' ),
                    $node['tag']
                )
            );
        }

        // Render children if present, otherwise render content.
        if ( ! empty( $node['children'] ) ) {
            return $this->render_nodes( $node['children'] );
        }

        $content = trim( $node['content'] );
        if ( '' !== $content ) {
            return $this->gutenberg_block( 'html', [], $content );
        }

        return '';
    }

    /**
     * Convert a fragment of free-form HTML into blocks.
     *
     * A pass-through to D2G_HTML_Converter, kept on the converter because that
     * is what renderers hold a reference to. See that class for the rules.
     */
    public function html_to_blocks( string $html, array $attrs, int $depth = 0 ): string {
        return $this->html->to_blocks( $html, $attrs, $depth );
    }

    /**
     * Convert HTML that contains iframes, video, embed or object tags.
     */
    public function html_with_embeds( string $html, array $attrs ): string {
        return $this->html->with_embeds( $html, $attrs );
    }

    /**
     * Convert one embedded-media tag into its matching block.
     */
    public function embed_tag( string $tag_html ): string {
        return $this->html->embed_tag( $tag_html );
    }

    /**
     * Render a node's whole inner span — loose HTML *and* child modules — in
     * source order.
     *
     * get_inner_content() answers a narrower question: "what loose text does
     * this node own?". Renderers that only want a label (a counter's caption, a
     * pricing feature) are right to ask that. Renderers that emit blocks were
     * asking it too, and the answer threw away every child module: a Button
     * placed inside a Text module vanished from the converted page while the
     * text either side of it survived, with no warning and nothing in the
     * preview to show it had gone.
     *
     * @param array $node  The parsed node whose inner span to render.
     * @param array $attrs Styling attributes applied to the loose HTML runs.
     */
    public function render_inner_blocks( array $node, array $attrs ): string {
        $children = $node['children'] ?? [];

        if ( empty( $children ) ) {
            return $this->render_inner_html( (string) ( $node['content'] ?? '' ), $attrs );
        }

        $output = '';
        foreach ( $children as $child ) {
            $output .= '__text__' === $child['tag']
                ? $this->render_inner_html( $child['content'], $attrs )
                : $this->render_node( $child );
        }

        return $output;
    }

    /**
     * Render a structural parent's children in source order, handing the child
     * tags it understands to a callback and keeping everything else.
     *
     * Six renderers each wrote their own version of this loop, and every one of
     * them was written the same wrong way: `foreach ( children as child ) { if
     * ( child.tag === the_one_i_want ) … }`. Anything else in the parent — loose
     * text, a Button somebody dropped into a Tabs module, a third-party module,
     * a shape Divi emits that this converter has not seen — fell off the end of
     * the `if` and was gone. No block, no warning, nothing in the preview.
     *
     *     [et_pb_tabs]Before[et_pb_button button_text="Keep" /]After[/et_pb_tabs]
     *
     * converted to an empty Group. All four pieces of content were discarded.
     *
     * This is the one traversal they now share. Expected children arrive at the
     * callback in *runs* of consecutive siblings, because some parents batch
     * them — a pricing table turns a run of items into one list — and a run
     * boundary is exactly where unexpected content belongs. Everything that is
     * not expected is rendered where it stands, and the parent says that it
     * found something it did not model.
     *
     * @param array    $node      The structural parent.
     * @param string[] $expected  Child tags the parent handles itself.
     * @param callable $on_run    fn( array $children ): string — one run of expected children.
     * @param array    $attrs     Styling attributes applied to loose HTML runs.
     */
    public function render_structural_children( array $node, array $expected, callable $on_run, array $attrs = [] ): string {
        $output     = '';
        $run        = [];
        $unexpected = false;

        $flush = function () use ( &$run, $on_run, &$output ) {
            if ( $run ) {
                $output .= (string) $on_run( $run );
                $run     = [];
            }
        };

        foreach ( $node['children'] ?? [] as $child ) {
            if ( in_array( $child['tag'], $expected, true ) ) {
                $run[] = $child;
                continue;
            }

            $rendered = '__text__' === $child['tag']
                ? $this->render_inner_html( $child['content'], $attrs )
                : $this->render_node( $child );

            // Whitespace between shortcodes is how Divi formats its own output;
            // it is not content, and reporting it would make the warning
            // meaningless on every ordinary page.
            if ( '' === trim( $rendered ) ) {
                continue;
            }

            $flush();
            $output    .= $rendered;
            $unexpected = true;
        }

        $flush();

        if ( $unexpected ) {
            $this->add_warning(
                $node['tag'],
                sprintf(
                    /* translators: %s: Divi shortcode tag. */
                    __( 'The module "%s" held content this converter does not model as part of that module — loose text, or another module nested inside it. The content was kept in place, but check where it landed and how it is styled.', 'block-converter-for-divi' ),
                    $node['tag']
                )
            );
        }

        return $output;
    }

    /**
     * Convert one run of loose HTML, routing embedded media around the DOM step.
     */
    public function render_inner_html( string $html, array $attrs ): string {
        if ( '' === trim( $html ) ) {
            return '';
        }

        if ( preg_match( '#<(?:iframe|embed|object|video)[\s>]#i', $html ) ) {
            return $this->html_with_embeds( $html, $attrs );
        }

        return $this->html_to_blocks( $html, $attrs );
    }

    /**
     * Get the text/HTML content of a node, preferring non-shortcode content.
     */
    public function get_inner_content( array $node ): string {
        $children = $node['children'] ?? [];

        // The parser stores the full raw inner span on every paired node, child
        // shortcodes and all. Handing that raw span to a renderer that wants
        // text is how [et_pb_pricing_item] markup ended up printed inside a
        // paragraph. When a node has real child modules, only the loose text
        // between them is this node's own content — the modules are rendered
        // separately by whoever owns them.
        $has_module_children = false;
        foreach ( $children as $child ) {
            if ( '__text__' !== $child['tag'] ) {
                $has_module_children = true;
                break;
            }
        }

        if ( $has_module_children ) {
            $text_parts = [];
            foreach ( $children as $child ) {
                if ( '__text__' === $child['tag'] ) {
                    $text_parts[] = $child['content'];
                }
            }
            return trim( implode( "\n", $text_parts ) );
        }

        return trim( (string) ( $node['content'] ?? '' ) );
    }
}
