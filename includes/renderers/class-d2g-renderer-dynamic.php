<?php
/**
 * Modules that pull content from the site rather than holding it: post loops,
 * portfolios, menus, search, comments, login, sidebars, forms and the shop.
 *
 * These convert least faithfully, and the honest ones say so. A Divi contact
 * form has no core equivalent that is not experimental, so it becomes visible
 * rebuild instructions rather than a broken form. A portfolio becomes a Query
 * Loop over Divi's own `project` post type, which stops existing when Divi is
 * removed — so the conversion warns about it instead of leaving an empty grid.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Renderer_Dynamic extends D2G_Module_Renderer {

    /**
     * @return array<string, string>
     */
    public static function tags(): array {
        return [
            'et_pb_blog'                 => 'blog',
            'et_pb_portfolio'            => 'portfolio',
            'et_pb_filterable_portfolio' => 'portfolio',
            'et_pb_fullwidth_portfolio'  => 'portfolio',
            'et_pb_menu'                 => 'menu',
            'et_pb_fullwidth_menu'       => 'menu',
            'et_pb_search'               => 'search',
            'et_pb_post_title'           => 'post_title',
            'et_pb_comments'             => 'comments',
            'et_pb_login'                => 'login',
            'et_pb_sidebar'              => 'sidebar',
            'et_pb_shop'                 => 'shop',
            'et_pb_contact_form'         => 'contact_form',
            'et_pb_signup'               => 'signup',
        ];
    }

    protected function blog( array $node ): string {
        $attrs      = $node['attrs'];
        $posts_num  = $attrs['posts_number'] ?? '10';
        $categories = $attrs['include_categories'] ?? '';

        $block_attrs = [ 'postsToShow' => (int) $posts_num ];
        if ( $categories ) {
            $block_attrs['categories'] = array_map( function ( $id ) {
                return [ 'id' => (int) $id ];
            }, explode( ',', $categories ) );
        }

        return D2G_Block_Builder::block( 'latest-posts', $block_attrs );
    }

    protected function portfolio( array $node ): string {
        $attrs      = $node['attrs'];
        $posts_num  = (int) ( $attrs['posts_number'] ?? 4 );
        $categories = $attrs['include_categories'] ?? '';
        $fullwidth  = ( $attrs['fullwidth'] ?? '' ) === 'on';

        // The `project` post type and `project_category` taxonomy are Divi's,
        // not WordPress's — this plugin does not register them and nothing else
        // will once Divi is removed. The query block below is only as portable
        // as its post type, so both are filterable and the dependency is
        // reported rather than left for the user to discover as an empty grid.
        $post_type = (string) apply_filters( 'd2g_portfolio_post_type', 'project', $attrs );
        $taxonomy  = (string) apply_filters( 'd2g_portfolio_taxonomy', 'project_category', $attrs );

        if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( $post_type ) ) {
            $this->warn(
                $node['tag'],
                sprintf(
                    /* translators: %s: post type slug. */
                    __( 'A portfolio module became a Query Loop over the "%s" post type, which Divi registers. The block will list nothing once Divi is removed unless that post type is registered by something else — migrate the projects to a core post type, or use the d2g_portfolio_post_type filter.', 'block-converter-for-divi' ),
                    $post_type
                )
            );
        }

        $query = [
            'postType' => $post_type,
            'perPage'  => $posts_num,
            'order'    => 'desc',
            'orderBy'  => 'date',
        ];

        if ( $categories ) {
            $cat_ids = array_map( 'intval', explode( ',', $categories ) );
            $query['taxQuery'] = [
                $taxonomy => $cat_ids,
            ];
        }

        $query_attrs = [ 'query' => $query ];

        // Build wp:post-template inner blocks.
        $template_inner  = D2G_Block_Builder::block( 'post-featured-image' );
        $template_inner .= D2G_Block_Builder::block( 'post-title' );
        if ( ! $fullwidth ) {
            $template_inner .= D2G_Block_Builder::block( 'post-excerpt' );
        }

        $post_template = D2G_Block_Builder::block( 'post-template', [], $template_inner, true );
        $html = '<div class="wp-block-query">' . "\n" . $post_template . '</div>';
        return D2G_Block_Builder::block( 'query', $query_attrs, $html, true );
    }

    /**
     * Convert a Divi menu module to core/navigation.
     *
     * Divi's menu_id is a classic nav_menu term ID. core/navigation has no
     * menuId attribute — it points at a wp_navigation post through `ref`, and a
     * term ID is not a post ID. Writing the term ID into a made-up menuId
     * attribute produced a block that parsed but resolved to nothing, so the
     * converted page showed an empty navigation.
     *
     * A bare core/navigation is emitted instead. It falls back to the site's
     * menu, and the classic menu is named in a warning so the user can point
     * the block at the right one — WordPress offers exactly that as a one-click
     * import in the block's own toolbar.
     */
    protected function menu( array $node ): string {
        $menu_id = $node['attrs']['menu_id'] ?? '';

        if ( $menu_id ) {
            $menu_name = '';
            if ( function_exists( 'wp_get_nav_menu_object' ) ) {
                $menu_object = wp_get_nav_menu_object( (int) $menu_id );
                if ( $menu_object && ! is_wp_error( $menu_object ) ) {
                    $menu_name = $menu_object->name;
                }
            }

            $this->warn(
                $node['tag'],
                $menu_name
                    ? sprintf(
                        /* translators: %s: classic menu name. */
                        __( 'A menu module became an empty Navigation block. Use the block\'s toolbar to import the classic menu "%s".', 'block-converter-for-divi' ),
                        $menu_name
                    )
                    : sprintf(
                        /* translators: %s: classic menu ID. */
                        __( 'A menu module became an empty Navigation block. Use the block\'s toolbar to import classic menu ID %s.', 'block-converter-for-divi' ),
                        (int) $menu_id
                    )
            );
        }

        return D2G_Block_Builder::block( 'navigation' );
    }

    protected function search( array $node ): string {
        $attrs = $node['attrs'];
        $placeholder = $attrs['placeholder'] ?? '';
        $block_attrs = [];
        if ( $placeholder ) {
            $block_attrs['placeholder'] = $placeholder;
        }
        return D2G_Block_Builder::block( 'search', $block_attrs );
    }

    protected function post_title( array $node ): string {
        return D2G_Block_Builder::block( 'post-title' );
    }

    protected function comments( array $node ): string {
        // core/comments holds inner blocks, so its save() writes a wrapper div.
        // Emitting a self-closing delimiter left the saved markup empty where
        // core expected that div, and the block was reported invalid.
        return D2G_Block_Builder::block( 'comments', [], '<div class="wp-block-comments"></div>', true );
    }

    protected function login( array $node ): string {
        return D2G_Block_Builder::block( 'loginout' );
    }

    protected function sidebar( array $node ): string {
        $attrs = $node['attrs'];
        $area  = $attrs['area'] ?? 'sidebar-1';
        $this->warn(
            'et_pb_sidebar',
            __( 'A sidebar module became a text placeholder. Core has no drop-in equivalent — rebuild it with the widget blocks you need.', 'block-converter-for-divi' )
        );

        return D2G_Block_Builder::block(
            'paragraph',
            [],
            '<p><em>' . sprintf(
                /* translators: %s: name of the Divi widget area. */
                esc_html__( '[Sidebar: %s — use a Widgets block or shortcode to display this sidebar.]', 'block-converter-for-divi' ),
                D2G_Block_Builder::text( $area )
            ) . '</em></p>'
        );
    }

    protected function shop( array $node ): string {
        $attrs   = $node['attrs'];
        $type    = $attrs['type'] ?? 'recent';
        $columns = $attrs['columns_number'] ?? '4';
        $rows    = $attrs['posts_number'] ?? '4';

        $this->warn(
            'et_pb_shop',
            __( 'A shop module became a text placeholder. Rebuild it with the WooCommerce Products block.', 'block-converter-for-divi' )
        );

        return D2G_Block_Builder::block(
            'paragraph',
            [],
            '<p><em>' . sprintf(
                /* translators: 1: Divi shop type, 2: column count, 3: product count. */
                esc_html__( '[WooCommerce Products — type: %1$s, columns: %2$s, count: %3$s. Install WooCommerce and use the Products block.]', 'block-converter-for-divi' ),
                esc_html( $type ),
                esc_html( $columns ),
                esc_html( $rows )
            ) . '</em></p>'
        );
    }

    /**
     * Convert a Divi contact form into a description of the form to rebuild.
     *
     * Earlier versions emitted core/form, core/form-input and
     * core/form-submit-button. Those three blocks are experimental: they ship
     * with the Gutenberg feature plugin and are not registered by WordPress
     * core, so on a normal install the converted page showed three "block not
     * supported" placeholders where the form used to be. The mapping also
     * flattened select, radio and checkbox fields to plain text inputs, and
     * nothing carried the recipient address into a working mail path, so even
     * where the blocks did render the form could not be submitted.
     *
     * A form is not something to silently half-convert. The fields, their
     * types, and the recipient are written out as ordinary core blocks — always
     * valid, always visible — and the module is reported as needing manual
     * work, so the user rebuilds it with a form plugin of their choosing rather
     * than discovering later that submissions were being dropped.
     *
     * The `d2g_contact_form_markup` filter lets a site swap in blocks for
     * whichever form plugin it actually uses.
     */
    protected function contact_form( array $node ): string {
        $attrs       = $node['attrs'];
        $title       = $attrs['title'] ?? '';
        $email       = $attrs['email'] ?? '';
        $submit_text = $attrs['submit_button_text'] ?? __( 'Send', 'block-converter-for-divi' );

        // Human-readable names for what Divi called each field type.
        $type_labels = [
            'input'    => __( 'text', 'block-converter-for-divi' ),
            'email'    => __( 'email', 'block-converter-for-divi' ),
            'text'     => __( 'multi-line text', 'block-converter-for-divi' ),
            'select'   => __( 'dropdown', 'block-converter-for-divi' ),
            'radio'    => __( 'radio buttons', 'block-converter-for-divi' ),
            'checkbox' => __( 'checkboxes', 'block-converter-for-divi' ),
        ];

        $fields = '';
        foreach ( $node['children'] as $child ) {
            if ( 'et_pb_contact_field' !== $child['tag'] ) {
                continue;
            }

            $f_attrs = $child['attrs'];
            $f_title = $f_attrs['field_title'] ?? ( $f_attrs['field_id'] ?? __( 'Field', 'block-converter-for-divi' ) );
            $f_type  = $f_attrs['field_type'] ?? 'input';
            $label   = $type_labels[ $f_type ] ?? $f_type;

            $line = sprintf(
                /* translators: 1: form field label, 2: field type. */
                __( '%1$s — %2$s', 'block-converter-for-divi' ),
                D2G_Block_Builder::text( $f_title ),
                D2G_Block_Builder::text( $label )
            );

            if ( ( $f_attrs['required_mark'] ?? 'on' ) === 'on' ) {
                $line .= ' (' . esc_html__( 'required', 'block-converter-for-divi' ) . ')';
            }

            if ( ! empty( $f_attrs['field_options'] ) ) {
                $line .= '<br/>' . esc_html( str_replace( '|', ', ', $f_attrs['field_options'] ) );
            }

            $fields .= D2G_Block_Builder::block( 'list-item', [], '<li>' . $line . '</li>' );
        }

        $inner = '';
        if ( $title ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 3 ], '<h3>' . D2G_Block_Builder::text( $title ) . '</h3>' );
        }

        $intro = $email
            ? sprintf(
                /* translators: %s: recipient email address. */
                __( 'This was a Divi contact form sending to %s. WordPress has no stable core form block, so rebuild it with a form plugin using the fields below.', 'block-converter-for-divi' ),
                D2G_Block_Builder::text( $email )
            )
            : __( 'This was a Divi contact form. WordPress has no stable core form block, so rebuild it with a form plugin using the fields below.', 'block-converter-for-divi' );

        $inner .= D2G_Block_Builder::block( 'paragraph', [], '<p><em>' . $intro . '</em></p>' );

        if ( '' !== $fields ) {
            $inner .= D2G_Block_Builder::block( 'list', [], '<ul class="wp-block-list">' . "\n" . $fields . '</ul>', true );
        }

        $inner .= D2G_Block_Builder::block(
            'paragraph',
            [],
            '<p><em>' . sprintf(
                /* translators: %s: submit button label. */
                esc_html__( 'Submit button label: %s', 'block-converter-for-divi' ),
                D2G_Block_Builder::text( $submit_text )
            ) . '</em></p>'
        );

        $this->warn(
            'et_pb_contact_form',
            __( 'Contact forms cannot be converted to core blocks — WordPress has no stable form block. The fields and recipient address were preserved as text so the form can be rebuilt with a form plugin.', 'block-converter-for-divi' )
        );

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        $markup = D2G_Block_Builder::block( 'group', [], $html, true );

        /**
         * Filter the blocks emitted for a Divi contact form.
         *
         * @param string $markup Block markup produced by the converter.
         * @param array  $node   The parsed et_pb_contact_form node.
         */
        return (string) apply_filters( 'd2g_contact_form_markup', $markup, $node );
    }

    protected function signup( array $node ): string {
        $attrs = $node['attrs'];
        $title = $attrs['title'] ?? __( 'Subscribe', 'block-converter-for-divi' );
        $desc  = $attrs['description'] ?? '';

        $inner = '';
        if ( $title ) {
            $inner .= D2G_Block_Builder::block( 'heading', [ 'level' => 3 ], '<h3>' . D2G_Block_Builder::text( $title ) . '</h3>' );
        }
        if ( $desc ) {
            $inner .= D2G_Block_Builder::block( 'paragraph', [], '<p>' . D2G_Block_Builder::text( $desc ) . '</p>' );
        }
        $this->warn(
            'et_pb_signup',
            __( 'An email opt-in module became a text placeholder. Rebuild it with your email marketing plugin.', 'block-converter-for-divi' )
        );

        $inner .= D2G_Block_Builder::block( 'paragraph', [], '<p><em>' . esc_html__( '[Email signup form — configure with your email marketing plugin.]', 'block-converter-for-divi' ) . '</em></p>' );

        $html = '<div class="wp-block-group">' . "\n" . $inner . "\n" . '</div>';
        return D2G_Block_Builder::block( 'group', [], $html, true );
    }
}
