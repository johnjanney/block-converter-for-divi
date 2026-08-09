<?php
/**
 * Count what a page contains, before and after conversion, and say what fell out.
 *
 * Every defect the 247-page corpus exposed in 2.7.0 and 2.8.0 was a *silent*
 * loss: all 278 images gone, 249 of 252 button links replaced by "#", a
 * press-coverage section deleted with its ten links. None of them raised a
 * warning, and the reason is structural rather than careless — the loss
 * reporter works by inspecting parsed attributes, so a parse that produced
 * nothing had nothing to report. A page could lose everything and come out
 * looking clean.
 *
 * This is the second opinion. It counts the things a reader would notice going
 * missing — words, links, images, buttons — on the way in and on the way out,
 * and reports any difference the conversion has not already accounted for.
 *
 * IT MUST NOT USE D2G_Parser, AND IT MUST NOT ASK A RENDERER ANYTHING.
 *
 * That restriction is the whole value of the file and it is easy to erode. A
 * counter built on the parser would have read `[et_pb_button
 * button_url=&quot;…&quot;]` as an attribute-less tag in exactly the way the
 * converter did, counted zero links in the source, found zero in the output,
 * and reported that nothing was lost. Naive, duplicated regexes are the point:
 * two implementations that fail the same way are one implementation.
 *
 * @package block-converter-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class D2G_Census {

    /**
     * Attributes Divi stores a URL in. Counted as links, wherever they appear.
     *
     * Deliberately a flat list matched by regex rather than a parse: this is the
     * naive reader, and `button_url` inside a tag it does not recognise still
     * counts.
     */
    const URL_ATTRS = 'src|url|button_url|button_link|image_url|background_image|audio|logo|src_webm';

    /**
     * Count a Divi document: shortcodes, their URL attributes, and their text.
     *
     * @return array<string, int>
     */
    public static function of_divi( string $content ): array {
        $content = self::decode_entities( $content );

        $urls   = self::urls_in_attributes( $content );
        $urls   = array_merge( $urls, self::urls_in_html( $content ) );
        $images = self::image_urls( $urls );

        // Divi stores gallery images as attachment IDs and nothing else, so the
        // only honest count is how many IDs were listed.
        $gallery = 0;
        if ( preg_match_all( '#\bgallery_ids\s*=\s*["\']([^"\']*)["\']#i', $content, $m ) ) {
            foreach ( $m[1] as $list ) {
                foreach ( explode( ',', $list ) as $id ) {
                    if ( '' !== trim( $id ) ) {
                        $gallery++;
                    }
                }
            }
        }

        return [
            'words'   => self::words( self::text_of_divi( $content ) ),
            'links'   => count( self::unique_non_image( $urls ) ),
            'images'  => count( $images ) + $gallery,
            'buttons' => preg_match_all( '#\[et_pb_(?:fullwidth_)?button\b#i', $content ),
        ];
    }

    /**
     * Count a block document: block markup, its URLs, and its text.
     *
     * @return array<string, int>
     */
    public static function of_blocks( string $content ): array {
        $content = self::decode_entities( $content );

        $urls   = self::urls_in_html( $content );
        $images = self::image_urls( $urls );

        // A gallery image that resolved is an <img> inside the gallery; one
        // that did not was dropped, and the renderer has already said so.
        return [
            'words'   => self::words( self::text_of_blocks( $content ) ),
            'links'   => count( self::unique_non_image( $urls ) ),
            'images'  => count( $images ),
            'buttons' => preg_match_all( '#<!--\s+wp:button[\s/]#', $content ),
        ];
    }

    /**
     * What the conversion dropped that it did not account for.
     *
     * @param array<string, int> $before      From of_divi().
     * @param array<string, int> $after       From of_blocks().
     * @param array<string, int> $acknowledged Losses a renderer already reported.
     * @return array<string, array{lost: int, before: int}>
     */
    public static function unexplained( array $before, array $after, array $acknowledged = [] ): array {
        $out = [];

        foreach ( $before as $kind => $count ) {
            $lost = $count - ( $after[ $kind ] ?? 0 ) - ( $acknowledged[ $kind ] ?? 0 );

            // A conversion may legitimately produce *more* than it consumed —
            // a Divi button carries one link and emits it once, but a text
            // module's body can hold links the shortcode never mentioned. Only
            // a shortfall is a finding.
            if ( $lost > 0 ) {
                $out[ $kind ] = [
                    'lost'   => $lost,
                    'before' => $count,
                ];
            }
        }

        return $out;
    }

    /**
     * Human-readable names for the counted kinds.
     */
    public static function label( string $kind ): string {
        switch ( $kind ) {
            case 'words':
                return __( 'words of text', 'block-converter-for-divi' );
            case 'links':
                return __( 'links', 'block-converter-for-divi' );
            case 'images':
                return __( 'images', 'block-converter-for-divi' );
            case 'buttons':
                return __( 'buttons', 'block-converter-for-divi' );
        }
        return $kind;
    }

    // ---------------------------------------------------------------- text --

    /**
     * The words a Divi document would show a reader.
     *
     * Shortcode tags go, their inner text stays. The tag pattern is crude on
     * purpose — anything in square brackets that looks like a tag — because a
     * precise one would be the parser again.
     */
    private static function text_of_divi( string $content ): string {
        // Quoted spans are skipped when looking for the closing bracket, for
        // the same reason the parser does it: `title="Array[0]"` ends at the
        // second `]`, not the first. A stripper that stops early leaves half a
        // tag behind, counts `text_orientation="left"]` as a word of body text,
        // and then reports that the conversion lost it.
        //
        // This is lexical, not a parse. The census still has no idea what any
        // of these tags mean, which is the property that matters.
        $content = preg_replace(
            '#\[/?[a-zA-Z0-9_]+(?:[^\]"\']|"[^"]*"|\'[^\']*\')*\]#s',
            ' ',
            $content
        );
        return self::visible_text( $content );
    }

    /**
     * The words a block document would show a reader.
     */
    private static function text_of_blocks( string $content ): string {
        $content = preg_replace( '#<!--\s*/?wp:.*?-->#s', ' ', $content );
        return self::visible_text( $content );
    }

    private static function visible_text( string $content ): string {
        // Script and style bodies are not words on the page.
        $content = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $content );
        $content = preg_replace( '#<[^>]+>#s', ' ', $content );
        return html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Word count, on the same rules for both sides.
     *
     * Anything that is not whitespace is a word. Punctuation-only runs are
     * skipped so that a paragraph re-wrapped into a list does not read as a
     * change in content.
     */
    private static function words( string $text ): int {
        $count = 0;
        foreach ( preg_split( '#\s+#u', $text, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
            // A web address is counted as a link, not as a word. Conversion
            // routinely moves one between body text and an attribute — a video
            // module holding a bare URL in its body becomes `src="…"` — and
            // counting it both ways would report the move as a loss of text.
            if ( preg_match( '#(?:^\w+://|^www\.)#i', $token ) ) {
                continue;
            }
            if ( preg_match( '#[\p{L}\p{N}]#u', $token ) ) {
                $count++;
            }
        }
        return $count;
    }

    // ---------------------------------------------------------------- urls --

    private static function urls_in_attributes( string $content ): array {
        $found = [];
        if ( preg_match_all( '#\b(?:' . self::URL_ATTRS . ')\s*=\s*["\']([^"\']*)["\']#i', $content, $m ) ) {
            $found = $m[1];
        }
        return array_values( array_filter( array_map( [ __CLASS__, 'normalise' ], $found ) ) );
    }

    private static function urls_in_html( string $content ): array {
        $found = [];
        $patterns = [
            '#\bhref\s*=\s*"([^"]*)"#i',
            '#\bsrc\s*=\s*"([^"]*)"#i',
            // Blocks keep the address in their attributes, not always in the
            // markup: an embed's URL and a social link's URL live in the JSON
            // and appear in the body as bare text or not at all. Counting only
            // href and src reported every embed and every social link as lost.
            '#"(?:url|href|src|mediaLink)"\s*:\s*"([^"]*)"#i',
        ];
        foreach ( $patterns as $pattern ) {
            if ( preg_match_all( $pattern, $content, $m ) ) {
                $found = array_merge( $found, $m[1] );
            }
        }
        return array_values( array_filter( array_map( [ __CLASS__, 'normalise' ], $found ) ) );
    }

    /**
     * Reduce a URL to something both sides can be compared on.
     *
     * esc_url() percent-encodes non-ASCII paths and rewrites `&` as `&#038;`, so
     * the same address is spelled differently before and after conversion. A
     * census that counted those as losses would cry wolf on every page with an
     * Arabic filename — there were eight in the corpus.
     */
    private static function normalise( string $url ): string {
        $url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

        if ( '' === $url || '#' === $url ) {
            return '';
        }

        // A `javascript:` or `data:` address is refused on the way through, and
        // rightly. It is not content the page lost, it is an attack the
        // conversion declined to carry, and counting it would have the census
        // reporting every blocked injection as a defect.
        if ( preg_match( '#^\s*(?:javascript|data|vbscript)\s*:#i', $url ) ) {
            return '';
        }

        $url = rawurldecode( $url );
        $url = preg_replace( '#^https?://#i', '', $url );
        $url = rtrim( $url, '/' );

        return strtolower( $url );
    }

    private static function image_urls( array $urls ): array {
        $images = [];
        foreach ( $urls as $url ) {
            if ( preg_match( '#\.(?:jpe?g|png|gif|webp|svg|avif)(?:$|\?)#i', $url ) ) {
                $images[ $url ] = true;
            }
        }
        return array_keys( $images );
    }

    private static function unique_non_image( array $urls ): array {
        $links = [];
        foreach ( $urls as $url ) {
            if ( ! preg_match( '#\.(?:jpe?g|png|gif|webp|svg|avif)(?:$|\?)#i', $url ) ) {
                $links[ $url ] = true;
            }
        }
        return array_keys( $links );
    }

    /**
     * Undo the attribute-quote encoding the parser also has to cope with.
     *
     * Done here independently rather than by calling the parser, for the reason
     * at the top of this file. If the encoding ever takes a new form, this file
     * and the parser should be updated separately and neither should be trusted
     * to have caught it for the other.
     */
    private static function decode_entities( string $content ): string {
        return str_replace( [ '&quot;', '&#34;', '&#034;' ], '"', $content );
    }
}
