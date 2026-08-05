<?php
/**
 * Structural checks applied to every fixture's converted output.
 *
 * These are the cheap half of a block-validity gate. They cannot run core's
 * JavaScript save functions, so they do not prove a block is valid — but every
 * defect the 2.1.0 review turned up (a paragraph block holding two paragraphs,
 * an alignment class with no matching attribute, an `open` details element with
 * no showContent) is a disagreement these checks can see, and they run in a
 * plain PHP process with no WordPress install.
 *
 * @package block-converter-for-divi
 */

/**
 * Split block markup into a flat list of parsed block delimiters.
 *
 * @return array<int, array{name: string, attrs: array|null, self_closing: bool, closing: bool, offset: int, length: int}>
 */
function d2g_parse_block_comments( string $markup ): array {
    $found = [];
    $re    = '#<!--\s*(/)?wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s*(\{.*?\})?\s*(/)?-->#s';

    if ( ! preg_match_all( $re, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
        return $found;
    }

    foreach ( $matches as $m ) {
        $json  = isset( $m[3] ) && '' !== $m[3][0] ? $m[3][0] : null;
        $attrs = null;
        if ( null !== $json ) {
            $attrs = json_decode( $json, true );
            if ( null === $attrs ) {
                $attrs = 'INVALID_JSON';
            }
        }

        $found[] = [
            'name'         => $m[2][0],
            'attrs'        => $attrs,
            'self_closing' => isset( $m[4] ) && '/' === $m[4][0],
            'closing'      => '' !== $m[1][0],
            'offset'       => $m[0][1],
            'length'       => strlen( $m[0][0] ),
        ];
    }

    return $found;
}

/**
 * Run every structural check over one fixture's output.
 *
 * @return string[] Human-readable failures; empty means the output passed.
 */
function d2g_check_block_markup( string $markup ): array {
    $errors = [];
    $blocks = d2g_parse_block_comments( $markup );

    // ---- No Divi shortcode may survive a successful conversion -------------
    if ( preg_match( '#\[/?et_pb_#', $markup, $m, PREG_OFFSET_CAPTURE ) ) {
        $errors[] = sprintf(
            'raw Divi shortcode left in output at offset %d: %s',
            $m[0][1],
            substr( $markup, $m[0][1], 60 )
        );
    }

    // ---- Delimiters must nest and balance ----------------------------------
    $stack = [];
    foreach ( $blocks as $block ) {
        if ( 'INVALID_JSON' === $block['attrs'] ) {
            $errors[] = sprintf( 'block "%s" has attributes that are not valid JSON', $block['name'] );
            continue;
        }
        if ( $block['self_closing'] ) {
            continue;
        }
        if ( $block['closing'] ) {
            $open = array_pop( $stack );
            if ( null === $open ) {
                $errors[] = sprintf( 'closing delimiter for "%s" with nothing open', $block['name'] );
            } elseif ( $open['name'] !== $block['name'] ) {
                $errors[] = sprintf( 'closing "%s" while "%s" is open', $block['name'], $open['name'] );
            }
            continue;
        }
        $stack[] = $block;
    }
    foreach ( $stack as $unclosed ) {
        $errors[] = sprintf( 'block "%s" was never closed', $unclosed['name'] );
    }

    // ---- Per-block body checks ---------------------------------------------
    foreach ( d2g_block_bodies( $markup, $blocks ) as $entry ) {
        $errors = array_merge( $errors, d2g_check_block_body( $entry['name'], $entry['attrs'], $entry['body'] ) );
    }

    return $errors;
}

/**
 * Pair every opening delimiter with the markup it encloses, at every depth.
 *
 * This used to stop at the top level, which meant the body checks below only
 * ever saw blocks that happened to sit at the root of a document. Almost
 * nothing does: this converter wraps practically everything in a Group, a
 * Columns or a Cover. A Fullwidth Header emitting a paragraph block that held
 * two <p> elements — the exact defect the paragraph check exists to catch —
 * sailed through, because the paragraph was one level down inside a Group.
 *
 * @return array<int, array{name: string, attrs: array|null, body: string}>
 */
function d2g_block_bodies( string $markup, array $blocks ): array {
    $bodies = [];
    $stack  = [];

    foreach ( $blocks as $block ) {
        if ( $block['self_closing'] ) {
            $bodies[] = [ 'name' => $block['name'], 'attrs' => $block['attrs'], 'body' => '' ];
            continue;
        }

        if ( ! $block['closing'] ) {
            $stack[] = $block;
            continue;
        }

        $open = array_pop( $stack );
        if ( null === $open ) {
            continue; // Unbalanced; the delimiter check has already reported it.
        }

        $start    = $open['offset'] + $open['length'];
        $bodies[] = [
            'name'  => $open['name'],
            'attrs' => $open['attrs'],
            'body'  => substr( $markup, $start, $block['offset'] - $start ),
        ];
    }

    return $bodies;
}

/**
 * Checks that apply to a single block's saved markup.
 *
 * @return string[]
 */
function d2g_check_block_body( string $name, $attrs, string $body ): array {
    $errors = [];
    $attrs  = is_array( $attrs ) ? $attrs : [];
    $body   = trim( $body );

    switch ( $name ) {
        case 'paragraph':
            // core/paragraph saves exactly one <p>. Two of them in one block is
            // the "unexpected or invalid content" warning waiting to happen.
            $count = preg_match_all( '#<p[\s>]#i', $body );
            if ( $count > 1 ) {
                $errors[] = sprintf( 'paragraph block contains %d <p> elements', $count );
            }
            if ( 0 === $count && '' !== $body ) {
                $errors[] = 'paragraph block body is not a <p> element';
            }
            $errors = array_merge( $errors, d2g_check_align( 'paragraph', $attrs['align'] ?? null, $body ) );
            break;

        case 'heading':
            if ( ! preg_match( '#^<h([1-6])[\s>]#i', $body, $hm ) ) {
                $errors[] = 'heading block body does not start with h1-h6';
                break;
            }
            $level = isset( $attrs['level'] ) ? (int) $attrs['level'] : 2;
            if ( (int) $hm[1] !== $level ) {
                $errors[] = sprintf( 'heading level attribute is %d but markup uses h%s', $level, $hm[1] );
            }
            $errors = array_merge( $errors, d2g_check_align( 'heading', $attrs['textAlign'] ?? null, $body ) );
            break;

        case 'details':
            $is_open      = (bool) preg_match( '#<details\b[^>]*\sopen[\s>]#i', $body );
            $show_content = ! empty( $attrs['showContent'] );
            if ( $is_open !== $show_content ) {
                $errors[] = sprintf(
                    'details markup is %s but showContent is %s',
                    $is_open ? 'open' : 'closed',
                    $show_content ? 'true' : 'absent/false'
                );
            }
            break;

        case 'list':
            if ( ! preg_match( '#^<(ul|ol)\b#i', $body, $lm ) ) {
                $errors[] = 'list block body does not start with ul/ol';
                break;
            }
            $ordered = ! empty( $attrs['ordered'] );
            if ( ( 'ol' === strtolower( $lm[1] ) ) !== $ordered ) {
                $errors[] = sprintf( 'list uses <%s> but ordered is %s', $lm[1], $ordered ? 'true' : 'absent' );
            }
            // Items have been inner blocks since WordPress 6.0.
            if ( preg_match( '#<li[\s>]#i', $body ) && ! preg_match( '#<!--\s*wp:list-item#', $body ) ) {
                $errors[] = 'list has <li> elements but no core/list-item inner blocks';
            }
            break;

        case 'table':
            // core/table sources its rows with a `tbody tr` selector; rows
            // outside a tbody are invisible to it and would be dropped.
            if ( preg_match( '#<tr[\s>]#i', $body ) && ! preg_match( '#<tbody[\s>]#i', $body ) ) {
                $errors[] = 'table rows are not inside a <tbody>';
            }
            break;

        case 'quote':
            if ( preg_match( '#<blockquote[^>]*>\s*<p#i', $body ) ) {
                $errors[] = 'quote body is loose markup rather than inner blocks';
            }
            break;

        case 'form':
        case 'form-input':
        case 'form-submit-button':
            $errors[] = sprintf( 'block "%s" is experimental and is not registered by WordPress core', $name );
            break;

        case 'navigation':
            if ( array_key_exists( 'menuId', $attrs ) ) {
                $errors[] = 'core/navigation has no menuId attribute';
            }
            break;
    }

    return $errors;
}

/**
 * An alignment class and its block attribute have to agree in both directions.
 *
 * @return string[]
 */
function d2g_check_align( string $block, $attr_value, string $body ): array {
    $has_class = preg_match( '#\bhas-text-align-([a-z]+)\b#', $body, $cm );
    $class     = $has_class ? $cm[1] : null;

    if ( $class === $attr_value ) {
        return [];
    }

    return [
        sprintf(
            '%s alignment mismatch: markup class is %s but attribute is %s',
            $block,
            null === $class ? 'absent' : $class,
            null === $attr_value ? 'absent' : $attr_value
        ),
    ];
}
