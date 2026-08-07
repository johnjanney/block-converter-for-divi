<?php
/**
 * Turn a real Divi page into a fixture that can be published.
 *
 * The fixture corpus is 362 hand-written cases, and that is its weakness: it can
 * only fail in ways somebody already imagined. Real Divi output looks nothing
 * like the inputs in tests/fixtures.php. A hand-written section is
 *
 *     [et_pb_section][et_pb_row][et_pb_column type="4_4"]
 *
 * and a real one is closer to
 *
 *     [et_pb_section fb_built="1" _builder_version="4.27.4" _module_preset="default"
 *      background_color="#f5f5f5" custom_padding="54px||54px||true|false"
 *      global_colors_info="{}" theme_builder_area="post_content"]
 *
 * Twenty attributes instead of none, braces inside an attribute value, pipe
 * grammars, percent-encoded quotes. That shape is exactly where the quote-aware
 * scanner, the unmapped-style reporter and the attribute decoder meet reality
 * rather than meeting an assumption.
 *
 * So the words get replaced and the structure is kept, to the byte:
 *
 *   REPLACED  visible copy, URLs, emails, phone numbers, attachment and menu
 *             IDs, and any attribute named in CONTENT_ATTRS below.
 *   KEPT      every tag, the nesting, every attribute *name*, every attribute
 *             *value shape* that is not identifying, Divi's entity encoding,
 *             and the whitespace between shortcodes.
 *
 * Tags are located with D2G_Parser::next_tag_span() — the converter's own
 * tokenizer — rather than a second regex. A tool that disagreed with the parser
 * about where a tag ends would scrub the wrong span of somebody's page.
 *
 * THIS IS NOT A PRIVACY GUARANTEE. It is a first pass that makes human review
 * tractable. --report lists every substitution and flags what still looks
 * identifying. Read the output before publishing it; this repository is public.
 *
 * Usage:
 *   wp post get 42 --field=content | php bin/anonymize-divi.php
 *   php bin/anonymize-divi.php page.txt
 *   php bin/anonymize-divi.php page.txt --report
 *   php bin/anonymize-divi.php page.txt --fixture "a real about page"
 *
 * @package block-converter-for-divi
 */

require_once __DIR__ . '/../tests/bootstrap.php';
require_once ABSPATH . 'includes/load.php';

// ---------------------------------------------------------------- policy --

/**
 * Attributes whose value is something a human wrote and can be read on the page.
 *
 * Drawn from what the renderers actually consume, so the list tracks the
 * converter rather than guesswork. A value here is replaced with placeholder
 * prose of a similar shape — similar length, same leading capital, same
 * entities — because a fixture that asserts on "Our Story" is asserting on the
 * wrong thing anyway. What must survive is that *some* text survived.
 */
const CONTENT_ATTRS = [
    'title', 'subtitle', 'subhead', 'heading', 'content', 'header',
    'button_text', 'button_one_text', 'button_two_text', 'submit_button_text',
    'alt', 'author', 'company_name', 'name', 'position', 'quote',
    'description', 'placeholder', 'field_title', 'artist_name', 'area',
    'address', 'caption', 'label', 'text',
    // Divi-specific. title_text sits on every image and holds whatever the
    // media library called the file, which on a real site is a person's name
    // often enough to matter.
    'title_text', 'quote_text', 'author_name', 'company', 'job_title',
];

/** Attributes holding a URL. */
const URL_ATTRS = [
    'src', 'url', 'link', 'button_url', 'button_link', 'button_one_url',
    'button_two_url', 'image_url', 'portrait_url', 'audio', 'background_image',
    'image', 'src_webm', 'video_webm', 'logo', 'background_video_mp4',
    'background_video_webm',
];

/** Attributes holding one or more database IDs. */
const ID_ATTRS = [
    'gallery_ids', 'menu_id', 'include_categories', 'exclude_categories',
    'post_ids', 'category_id',
];

/** Attributes holding an email address. */
const EMAIL_ATTRS = [ 'email', 'recipient', 'to' ];

/**
 * Placeholder vocabulary.
 *
 * Deliberately bland and deliberately deterministic — the same input produces
 * the same output, so re-running the tool does not churn a committed fixture.
 */
const WORDS = [
    'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta',
    'iota', 'kappa', 'lambda', 'sigma', 'omega', 'nova', 'quill', 'harbor',
    'meadow', 'lantern', 'copper', 'willow', 'summit', 'orchard',
];

// ------------------------------------------------------------ machinery --

$GLOBALS['d2g_anon_log']  = [];
$GLOBALS['d2g_anon_seed'] = 0;

function anon_log( string $kind, string $from, string $to ) {
    $GLOBALS['d2g_anon_log'][] = [ 'kind' => $kind, 'from' => $from, 'to' => $to ];
}

/**
 * A deterministic placeholder word for a given input.
 *
 * Keyed on the input so the same source text always maps to the same
 * replacement — repeated headings stay repeated, which preserves the shape of
 * a page that reuses copy.
 */
function anon_word( string $seed_text, int $index = 0 ): string {
    $n = ( crc32( $seed_text ) + $index * 7 ) % count( WORDS );
    return WORDS[ $n ];
}

/**
 * Replace prose while keeping its shape: word count, capitalisation, trailing
 * punctuation, and any HTML entities Divi encoded into it.
 */
function anon_prose( string $value ): string {
    if ( '' === trim( $value ) ) {
        return $value;
    }

    // Entities are structure, not content — &#8217; in a real title is exactly
    // the kind of thing N-12 was about, so it has to survive.
    $entities = [];
    $masked   = preg_replace_callback( '/&(?:[a-z]+|#\d+);/i', function ( $m ) use ( &$entities ) {
        $key = "\x01" . count( $entities ) . "\x02";
        $entities[ $key ] = $m[0];
        return $key;
    }, $value );

    $words  = preg_split( '/(\s+)/', $masked, -1, PREG_SPLIT_DELIM_CAPTURE );
    $out    = '';
    $index  = 0;

    foreach ( $words as $chunk ) {
        if ( '' === trim( $chunk ) ) {
            $out .= $chunk; // whitespace run, kept verbatim
            continue;
        }

        // Keep anything that is not a word: entity placeholders, punctuation
        // runs, numbers, and markup that survived into an attribute.
        if ( ! preg_match( '/\p{L}{2,}/u', $chunk ) ) {
            $out .= $chunk;
            continue;
        }

        $word = anon_word( $value, $index++ );

        // Mirror the original's capitalisation and trailing punctuation.
        if ( preg_match( '/^\p{Lu}/u', $chunk ) ) {
            $word = ucfirst( $word );
        }
        if ( preg_match( '/([.,!?;:]+)$/', $chunk, $m ) ) {
            $word .= $m[1];
        }

        $out .= $word;
    }

    $out = strtr( $out, $entities );
    $out = anon_phones( $out );

    if ( $out !== $value ) {
        anon_log( 'text', $value, $out );
    }

    return $out;
}

/**
 * Replace anything shaped like a phone number.
 *
 * Prose scrubbing alone does not catch these: a phone number is punctuation and
 * digits, and the word replacer deliberately leaves both alone so that
 * `54px||54px` and `(312)` survive as shapes. A real page's phone number is
 * exactly as identifying as its address.
 */
function anon_phones( string $text ): string {
    return preg_replace(
        '/(?<!\d)(?:\+\d{1,3}[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/',
        '(555) 555-0100',
        $text
    );
}

/** Replace a URL, keeping scheme, depth and extension. */
function anon_url( string $value ): string {
    $value = trim( $value );
    if ( '' === $value || '#' === $value ) {
        return $value;
    }

    // mailto: and tel: have no host or path to rebuild, and running them
    // through the path logic below turned mailto:someone@real.example into the
    // nonsense "/eta.com" — losing the scheme and keeping the domain.
    if ( preg_match( '/^(mailto|tel):(.*)$/i', $value, $m ) ) {
        $out = strtolower( $m[1] ) . ':' . (
            'mailto' === strtolower( $m[1] )
                ? anon_word( $m[2] ) . '@example.com'
                : '+15550000000'
        );
        anon_log( 'url', $value, $out );
        return $out;
    }

    // Relative links keep their shape; only the words change.
    $parts = parse_url( $value );
    $path  = $parts['path'] ?? '';
    $ext   = pathinfo( $path, PATHINFO_EXTENSION );

    $depth   = max( 1, substr_count( trim( $path, '/' ) ? $path : '/x', '/' ) );
    $segs    = [];
    for ( $i = 0; $i < $depth; $i++ ) {
        $segs[] = anon_word( $value, $i );
    }
    $new_path = '/' . implode( '/', $segs ) . ( $ext ? '.' . strtolower( $ext ) : '' );

    $out = isset( $parts['host'] )
        ? ( ( $parts['scheme'] ?? 'https' ) . '://example.com' . $new_path )
        : $new_path;

    anon_log( 'url', $value, $out );
    return $out;
}

function anon_email( string $value ): string {
    $out = anon_word( $value ) . '@example.com';
    anon_log( 'email', $value, $out );
    return $out;
}

/** Renumber IDs deterministically, keeping the count and the separators. */
function anon_ids( string $value ): string {
    $out = preg_replace_callback( '/\d+/', function ( $m ) {
        return (string) ( 100 + ( crc32( $m[0] ) % 900 ) );
    }, $value );

    if ( $out !== $value ) {
        anon_log( 'ids', $value, $out );
    }
    return $out;
}

/**
 * Scrub free text that sits between shortcodes — a module's HTML body.
 *
 * Tag names and attributes are structure and stay; only the text nodes and any
 * href/src move. Done with a scanner rather than DOMDocument because the body
 * is a fragment, and round-tripping it through a DOM would normalise exactly
 * the malformed shapes worth keeping.
 */
function anon_html( string $html ): string {
    $out = '';
    $len = strlen( $html );
    $i   = 0;

    while ( $i < $len ) {
        $lt = strpos( $html, '<', $i );

        if ( false === $lt ) {
            $out .= anon_prose( substr( $html, $i ) );
            break;
        }

        $out .= anon_prose( substr( $html, $i, $lt - $i ) );

        $gt = strpos( $html, '>', $lt );
        if ( false === $gt ) {
            $out .= substr( $html, $lt );
            break;
        }

        $tag = substr( $html, $lt, $gt - $lt + 1 );

        // Only the URL-bearing attributes inside the tag are identifying.
        $tag = preg_replace_callback(
            '/\b(href|src|data-src|poster)\s*=\s*(["\'])(.*?)\2/i',
            function ( $m ) {
                return $m[1] . '=' . $m[2] . anon_url( $m[3] ) . $m[2];
            },
            $tag
        );

        $out .= $tag;
        $i    = $gt + 1;
    }

    return $out;
}

/**
 * Rewrite one attribute value according to its name.
 *
 * The default is to KEEP. `_builder_version="4.27.4"`,
 * `custom_padding="54px||54px||true|false"` and `global_colors_info="{}"` are
 * not identifying, and they are the whole reason for capturing a real page.
 */
function anon_attr_value( string $name, string $value ): string {
    $lower = strtolower( $name );

    if ( in_array( $lower, EMAIL_ATTRS, true ) ) {
        return anon_email( $value );
    }
    if ( in_array( $lower, URL_ATTRS, true ) ) {
        return anon_url( $value );
    }
    if ( in_array( $lower, ID_ATTRS, true ) ) {
        return anon_ids( $value );
    }
    if ( in_array( $lower, CONTENT_ATTRS, true ) ) {
        return anon_prose( $value );
    }

    // Not classified by name: still catch anything that looks identifying
    // wherever it appears, because Divi and its third-party modules invent
    // attribute names this list has never seen.
    if ( preg_match( '#^\s*[a-z][a-z0-9+.-]*://#i', $value ) ) {
        return anon_url( $value );
    }
    if ( preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', trim( $value ) ) ) {
        return anon_email( $value );
    }

    // Last resort: a value that reads like a sentence probably is one. Divi and
    // its third-party modules invent attribute names faster than any list can
    // track — title_text held a real person's name and was not on the list the
    // first time this ran.
    //
    // Two or more words of two-plus letters, separated by a space. That leaves
    // every value shape worth keeping alone, because none of them contain a
    // space: `54px||54px||true|false`, `Montserrat|700|||||||`, `post_content`,
    // `#f5f5f5`, `4.27.4`, `{}`, `default`.
    $structural = '/(^_|_(font|style|size|position|repeat|blend|colors_info|preset|area|version|width|height|radius|spacing)$|color)/i';

    if ( ! preg_match( $structural, $lower )
        && preg_match( '/\p{L}{2,}\s+\p{L}{2,}/u', $value ) ) {
        return anon_prose( $value );
    }

    return $value;
}

/**
 * Rebuild an attribute string with rewritten values.
 *
 * Emitted as `name="value"` in the parser's own order. This is the one place
 * the tool does not preserve the original bytes exactly — original spacing and
 * quote style inside the tag are normalised — and that is a deliberate
 * trade: it guarantees the result is well-formed, and attribute *order* and
 * *names*, which is what matters, are untouched.
 */
function anon_attrs_string( string $attrs_str ): string {
    $attrs = D2G_Parser::parse_attrs_string( $attrs_str );
    if ( ! $attrs ) {
        return $attrs_str;
    }

    $parts = [];
    foreach ( $attrs as $name => $value ) {
        $parts[] = $name . '="' . str_replace( '"', '&quot;', anon_attr_value( $name, (string) $value ) ) . '"';
    }

    return implode( ' ', $parts );
}

/** Scrub a whole Divi document. */
function anonymize( string $source ): string {
    $out    = '';
    $cursor = 0;

    while ( false !== ( $span = D2G_Parser::next_tag_span( $source, $cursor ) ) ) {
        // Everything before this tag is module body text.
        $out .= anon_html( substr( $source, $cursor, $span['start'] - $cursor ) );

        if ( $span['closing'] ) {
            $out .= '[/' . $span['tag'] . ']';
        } else {
            $attrs = anon_attrs_string( $span['attrs_str'] );
            $out  .= '[' . $span['tag']
                . ( '' !== $attrs ? ' ' . $attrs : '' )
                . ( $span['self_closing'] ? ' /' : '' ) . ']';
        }

        $cursor = $span['end'];
    }

    return $out . anon_html( substr( $source, $cursor ) );
}

/**
 * Anything in the output that still looks like it belongs to a real site.
 *
 * The point of the tool is human review, and a reviewer needs a list of places
 * to look rather than a wall of shortcodes.
 */
function residual_warnings( string $out ): array {
    $checks = [
        'an email address'                => '/[^@\s"\']+@(?!example\.com)[^@\s"\']+\.[a-z]{2,}/i',
        'a URL not on example.com'        => '#\b(?!mailto:|tel:)[a-z][a-z0-9+.-]*://(?!example\.com)[^\s"\'\]]+#i',
        // (555) 555-0100 is this tool's own replacement. Flagging it would
        // train a reviewer to skim past the section that matters.
        'something shaped like a phone number' => '/(?<!\d)(?!\(555\) 555-0100)(?:\+\d{1,3}[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]\d{3}[\s.-]\d{4}(?!\d)/',
        'a long digit run (an ID or an account number?)' => '/(?<!\d)\d{7,}(?!\d)/',
    ];

    $found = [];
    foreach ( $checks as $label => $pattern ) {
        if ( preg_match_all( $pattern, $out, $m ) ) {
            $found[ $label ] = array_slice( array_unique( $m[0] ), 0, 5 );
        }
    }
    return $found;
}

/**
 * The skeleton of a document: every tag, in order, with its attribute names.
 *
 * This is the tool's contract expressed as data. Scrubbing must not change it —
 * if it does, the fixture no longer describes the page it came from, and the
 * whole point of capturing a real page is lost.
 */
function anon_skeleton( string $source ): array {
    $out    = [];
    $cursor = 0;

    while ( false !== ( $span = D2G_Parser::next_tag_span( $source, $cursor ) ) ) {
        $names = $span['closing'] ? [] : array_keys( D2G_Parser::parse_attrs_string( $span['attrs_str'] ) );
        $out[] = ( $span['closing'] ? '/' : '' ) . $span['tag']
            . ( $names ? '(' . implode( ',', $names ) . ')' : '' )
            . ( $span['self_closing'] ? '/' : '' );
        $cursor = $span['end'];
    }

    return $out;
}

/**
 * Prove the two halves of the promise on a sample built to break them.
 *
 * Run with --self-test. Wired into CI, because the failure mode of a scrubber
 * is silent: it publishes somebody's phone number and nothing goes red.
 */
function anon_self_test(): int {
    $sample = '[et_pb_section fb_built="1" _builder_version="4.27.4" custom_padding="54px||54px||true|false" global_colors_info="{}"]'
        . '[et_pb_text header_font="Montserrat|700|||||||" text_orientation="left"]'
        . "\n<h1>Riverside Dental &#8212; Since 1998</h1>\n"
        . '<p>Ring (312) 555-0147 or <a href="mailto:hello@riversidedental.com">email us</a>.</p>'
        . "\n" . '[/et_pb_text]'
        . '[et_pb_image src="https://riversidedental.com/wp-content/uploads/2024/03/emily-chen.jpg" title_text="Dr Emily Chen" alt="Dr Emily Chen" /]'
        . '[/et_pb_section]';

    $out    = anonymize( $sample );
    $fails  = [];

    // 1. Structure is untouched.
    $before = anon_skeleton( $sample );
    $after  = anon_skeleton( $out );
    if ( $before !== $after ) {
        $fails[] = "the tag/attribute skeleton changed\n    before: " . implode( ' ', $before )
            . "\n    after:  " . implode( ' ', $after );
    }

    // 2. Value *shapes* that carry no identity survive verbatim.
    foreach ( [ '4.27.4', '54px||54px||true|false', '{}', 'Montserrat|700|||||||', 'fb_built="1"', '&#8212;', 'text_orientation="left"' ] as $keep ) {
        if ( false === strpos( $out, $keep ) ) {
            $fails[] = "lost a structural value that must survive: {$keep}";
        }
    }

    // 3. Nothing identifying survives.
    foreach ( [ 'Riverside', 'riversidedental.com', 'hello@', '555-0147', 'Emily', 'Chen', 'emily-chen' ] as $gone ) {
        if ( false !== stripos( $out, $gone ) ) {
            $fails[] = "identifying content survived: {$gone}";
        }
    }

    // 4. Deterministic, so a committed fixture does not churn on re-runs.
    if ( anonymize( $sample ) !== $out ) {
        $fails[] = 'two runs over the same input disagreed';
    }

    // 5. The result is still convertible.
    $converted = ( new D2G_Converter() )->convert( $out );
    if ( '' === trim( $converted ) || false === strpos( $converted, '<!-- wp:' ) ) {
        $fails[] = 'the scrubbed source no longer converts to blocks';
    }

    foreach ( $fails as $f ) {
        fwrite( STDERR, "FAIL  {$f}\n" );
    }

    if ( $fails ) {
        fwrite( STDERR, sprintf( "\n%d self-test failure(s).\n", count( $fails ) ) );
        return 1;
    }

    echo "anonymizer self-test: structure preserved, identity removed, deterministic, still converts.\n";
    return 0;
}

// ------------------------------------------------------------------ main --

$argv    = $_SERVER['argv'];
$report  = in_array( '--report', $argv, true );

if ( in_array( '--self-test', $argv, true ) ) {
    exit( anon_self_test() );
}

$fixture = '';

foreach ( $argv as $i => $arg ) {
    if ( '--fixture' === $arg && isset( $argv[ $i + 1 ] ) ) {
        $fixture = $argv[ $i + 1 ];
    }
}

$path = '';
foreach ( array_slice( $argv, 1 ) as $i => $arg ) {
    if ( '-' !== substr( $arg, 0, 1 ) && $arg !== $fixture ) {
        $path = $arg;
        break;
    }
}

$source = '' !== $path ? @file_get_contents( $path ) : stream_get_contents( STDIN );

if ( false === $source || '' === trim( (string) $source ) ) {
    fwrite( STDERR, "error: no input. Pipe a page in, or pass a file.\n\n" );
    fwrite( STDERR, "  wp post get 42 --field=content | php bin/anonymize-divi.php\n" );
    fwrite( STDERR, "  php bin/anonymize-divi.php page.txt --report\n" );
    exit( 2 );
}

if ( ! D2G_Parser::has_divi_content( $source ) ) {
    fwrite( STDERR, "error: that does not contain Divi shortcodes. Wrong post, or already converted?\n" );
    exit( 2 );
}

$out = anonymize( $source );

if ( $fixture ) {
    // Ready to paste into tests/fixtures.php. Single-quoted PHP, so only
    // backslashes and single quotes need escaping.
    $escaped = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $out );
    printf(
        "    // Captured from a real Divi page with bin/anonymize-divi.php.\n"
        . "    // Review before committing — the scrubbing is a first pass, not a guarantee.\n"
        . "    '%s' => [\n        'divi'   => '%s',\n        'expect' => [ /* what must survive */ ],\n    ],\n",
        str_replace( "'", "\\'", $fixture ),
        $escaped
    );
} else {
    echo $out;
    if ( ! $report ) {
        echo "\n";
    }
}

if ( $report ) {
    $log = $GLOBALS['d2g_anon_log'];
    fwrite( STDERR, sprintf( "\n== %d substitutions ==\n", count( $log ) ) );

    $by_kind = [];
    foreach ( $log as $entry ) {
        $by_kind[ $entry['kind'] ][] = $entry;
    }
    foreach ( $by_kind as $kind => $entries ) {
        fwrite( STDERR, sprintf( "\n%s (%d)\n", $kind, count( $entries ) ) );
        foreach ( array_slice( $entries, 0, 10 ) as $e ) {
            fwrite( STDERR, sprintf( "  %s\n    -> %s\n", mb_strimwidth( $e['from'], 0, 70, '…' ), mb_strimwidth( $e['to'], 0, 70, '…' ) ) );
        }
        if ( count( $entries ) > 10 ) {
            fwrite( STDERR, sprintf( "  … and %d more\n", count( $entries ) - 10 ) );
        }
    }

    $warnings = residual_warnings( $out );
    fwrite( STDERR, "\n== Still looks identifying ==\n" );
    if ( ! $warnings ) {
        fwrite( STDERR, "nothing matched the residual checks.\n" );
        fwrite( STDERR, "That is not a clearance. Read the output before publishing it.\n" );
    } else {
        foreach ( $warnings as $label => $samples ) {
            fwrite( STDERR, sprintf( "\n%s:\n", $label ) );
            foreach ( $samples as $s ) {
                fwrite( STDERR, '  ' . mb_strimwidth( $s, 0, 80, '…' ) . "\n" );
            }
        }
        fwrite( STDERR, "\nFix these by hand, or add the attribute name to the policy lists at the\ntop of this script and re-run.\n" );
    }
}
