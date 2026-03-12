<?php
/**
 * Maps Divi styling attributes to inline CSS / Gutenberg block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Style_Mapper {

    /**
     * Build an inline style string from Divi attributes.
     */
    public static function build_inline_style( array $attrs ): string {
        $css = [];

        // Background color.
        if ( ! empty( $attrs['background_color'] ) ) {
            $css[] = 'background-color:' . esc_attr( $attrs['background_color'] );
        }

        // Background image.
        if ( ! empty( $attrs['background_image'] ) ) {
            $css[] = 'background-image:url(' . esc_url( $attrs['background_image'] ) . ')';
            $css[] = 'background-size:cover';
            $css[] = 'background-position:center';
        }

        // Text color.
        if ( ! empty( $attrs['text_color'] ) ) {
            $css[] = 'color:' . esc_attr( $attrs['text_color'] );
        }
        if ( ! empty( $attrs['header_text_color'] ) ) {
            $css[] = 'color:' . esc_attr( $attrs['header_text_color'] );
        }
        if ( ! empty( $attrs['body_text_color'] ) ) {
            $css[] = 'color:' . esc_attr( $attrs['body_text_color'] );
        }

        // Text alignment.
        if ( ! empty( $attrs['text_orientation'] ) ) {
            $css[] = 'text-align:' . esc_attr( $attrs['text_orientation'] );
        }

        // Padding.
        $padding = self::resolve_spacing( $attrs, 'custom_padding' );
        if ( $padding ) {
            $css[] = 'padding:' . $padding;
        }

        // Margin.
        $margin = self::resolve_spacing( $attrs, 'custom_margin' );
        if ( $margin ) {
            $css[] = 'margin:' . $margin;
        }

        // Max width.
        if ( ! empty( $attrs['max_width'] ) ) {
            $css[] = 'max-width:' . esc_attr( $attrs['max_width'] );
        }

        // Border radius.
        if ( ! empty( $attrs['border_radii'] ) ) {
            $radii = self::parse_border_radii( $attrs['border_radii'] );
            if ( $radii ) {
                $css[] = 'border-radius:' . $radii;
            }
        }

        // Border width / color.
        if ( ! empty( $attrs['border_width_all'] ) ) {
            $bw = esc_attr( $attrs['border_width_all'] );
            $bc = ! empty( $attrs['border_color_all'] ) ? esc_attr( $attrs['border_color_all'] ) : '#333';
            $css[] = 'border:' . $bw . ' solid ' . $bc;
        }

        // Box shadow.
        if ( ! empty( $attrs['box_shadow_style'] ) && $attrs['box_shadow_style'] !== 'none' ) {
            $h     = $attrs['box_shadow_horizontal'] ?? '0px';
            $v     = $attrs['box_shadow_vertical'] ?? '0px';
            $blur  = $attrs['box_shadow_blur'] ?? '10px';
            $spread = $attrs['box_shadow_spread'] ?? '0px';
            $color = $attrs['box_shadow_color'] ?? 'rgba(0,0,0,0.3)';
            $css[] = 'box-shadow:' . esc_attr( "$h $v $blur $spread $color" );
        }

        // Font size.
        if ( ! empty( $attrs['header_font_size'] ) ) {
            $css[] = 'font-size:' . esc_attr( $attrs['header_font_size'] );
        }
        if ( ! empty( $attrs['body_font_size'] ) ) {
            $css[] = 'font-size:' . esc_attr( $attrs['body_font_size'] );
        }

        // Line height.
        if ( ! empty( $attrs['body_line_height'] ) ) {
            $css[] = 'line-height:' . esc_attr( $attrs['body_line_height'] );
        }
        if ( ! empty( $attrs['header_line_height'] ) ) {
            $css[] = 'line-height:' . esc_attr( $attrs['header_line_height'] );
        }

        // Custom CSS from Divi (module-level).
        if ( ! empty( $attrs['custom_css_main_element'] ) ) {
            // Divi stores raw CSS declarations separated by ||.
            $raw = str_replace( '||', ';', $attrs['custom_css_main_element'] );
            $raw = str_replace( '&#124;&#124;', ';', $raw );
            $css[] = $raw;
        }

        return implode( ';', $css );
    }

    /**
     * Parse Divi's pipe-delimited spacing value (top|right|bottom|left).
     */
    private static function resolve_spacing( array $attrs, string $key ): string {
        if ( empty( $attrs[ $key ] ) ) {
            return '';
        }
        $val = $attrs[ $key ];
        $parts = explode( '|', $val );
        if ( count( $parts ) < 4 ) {
            return esc_attr( $val );
        }
        // Divi uses empty strings for "auto".
        $mapped = array_map( function ( $p ) {
            $p = trim( $p );
            return '' === $p ? '0px' : esc_attr( $p );
        }, $parts );
        // Divi format: top|right|bottom|left  (sometimes with a 5th empty element).
        return implode( ' ', array_slice( $mapped, 0, 4 ) );
    }

    /**
     * Parse Divi border_radii attribute: on|5px|5px|5px|5px
     */
    private static function parse_border_radii( string $val ): string {
        $parts = explode( '|', $val );
        if ( count( $parts ) < 5 ) {
            return '';
        }
        // First element is "on" or "off".
        if ( $parts[0] !== 'on' ) {
            return '';
        }
        return esc_attr( implode( ' ', array_slice( $parts, 1, 4 ) ) );
    }

    /**
     * Determine the Gutenberg text alignment class from Divi attributes.
     */
    public static function text_align_class( array $attrs ): string {
        $align = $attrs['text_orientation'] ?? '';
        if ( in_array( $align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
            return 'has-text-align-' . $align;
        }
        return '';
    }

    /**
     * Get Gutenberg-compatible color settings.
     */
    public static function get_color_attrs( array $attrs ): array {
        $result = [];
        if ( ! empty( $attrs['background_color'] ) ) {
            $result['backgroundColor'] = $attrs['background_color'];
        }
        if ( ! empty( $attrs['text_color'] ) ) {
            $result['textColor'] = $attrs['text_color'];
        }
        return $result;
    }

    /**
     * Map Divi font string to CSS font properties.
     * Divi font format: "Font Name|style|weight|uppercase|line-through"
     */
    public static function parse_font( string $font_str ): array {
        $parts = explode( '|', $font_str );
        $result = [];

        if ( ! empty( $parts[0] ) ) {
            $result['font-family'] = $parts[0];
        }
        if ( ! empty( $parts[1] ) && $parts[1] === 'on' ) {
            $result['font-style'] = 'italic';
        }
        if ( ! empty( $parts[2] ) ) {
            $w = $parts[2];
            if ( $w === 'on' ) {
                $result['font-weight'] = 'bold';
            } elseif ( is_numeric( $w ) ) {
                $result['font-weight'] = $w;
            }
        }
        if ( ! empty( $parts[3] ) && $parts[3] === 'on' ) {
            $result['text-transform'] = 'uppercase';
        }
        if ( ! empty( $parts[4] ) && $parts[4] === 'on' ) {
            $result['text-decoration'] = 'line-through';
        }

        return $result;
    }

    /**
     * Build a wrapper div style string for sections/rows.
     */
    public static function wrapper_style( array $attrs ): string {
        $style = self::build_inline_style( $attrs );
        return $style ? ' style="' . esc_attr( $style ) . '"' : '';
    }
}
