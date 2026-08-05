<?php
/**
 * Conversion fixtures: one Divi input per case, with what the output must show.
 *
 * `expect` entries are substrings that have to appear in the converted markup;
 * `reject` entries must not appear. Every fixture also goes through the
 * structural checks in lib/assertions.php regardless of what it declares here.
 *
 * Cases tagged with a finding ID are regression guards for the 2.1.0 review —
 * each one failed before that release.
 *
 * @package block-converter-for-divi
 */

return [

    // ---------------------------------------------------------------- F-03 --
    'F-03 two paragraphs become two paragraph blocks' => [
        'divi'   => '[et_pb_text]<p>One</p><p>Two</p>[/et_pb_text]',
        'expect' => [
            "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->",
            "<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->",
        ],
    ],

    'F-03 alignment class carries its block attribute' => [
        'divi'   => '[et_pb_text text_orientation="center"]<h2>Hello</h2><p>Body</p>[/et_pb_text]',
        'expect' => [
            '<!-- wp:heading {"level":2,"textAlign":"center"} -->',
            '<!-- wp:paragraph {"align":"center"} -->',
        ],
    ],

    'F-03 an open toggle sets showContent' => [
        'divi'   => '[et_pb_toggle title="Terms" open="on"]<p>Body</p>[/et_pb_toggle]',
        'expect' => [ '<!-- wp:details {"showContent":true} -->', '<details class="wp-block-details" open>' ],
    ],

    'F-03 a closed toggle sets neither' => [
        'divi'   => '[et_pb_toggle title="Terms"]<p>Body</p>[/et_pb_toggle]',
        'expect' => [ '<!-- wp:details -->' ],
        'reject' => [ 'showContent', '<details class="wp-block-details" open>' ],
    ],

    'F-03 lists use list-item inner blocks' => [
        'divi'   => '[et_pb_text]<ul><li>alpha</li><li>beta</li></ul>[/et_pb_text]',
        'expect' => [ '<ul class="wp-block-list">', '<!-- wp:list-item -->', '<li>alpha</li>' ],
    ],

    'F-03 ordered lists declare ordered' => [
        'divi'   => '[et_pb_text]<ol><li>first</li></ol>[/et_pb_text]',
        'expect' => [ '<!-- wp:list {"ordered":true} -->', '<ol class="wp-block-list">' ],
    ],

    'F-03 nested lists nest as blocks' => [
        'divi'   => '[et_pb_text]<ul><li>outer<ul><li>inner</li></ul></li></ul>[/et_pb_text]',
        'expect' => [ '<li>outer', '<!-- wp:list -->' ],
    ],

    'F-03 quotes hold inner blocks' => [
        'divi'   => '[et_pb_text]<blockquote><p>Quoted</p><cite>Someone</cite></blockquote>[/et_pb_text]',
        'expect' => [ '<blockquote class="wp-block-quote">', '<!-- wp:paragraph -->', '<cite>Someone</cite>' ],
    ],

    'F-03 plain tables get a tbody' => [
        'divi'   => '[et_pb_text]<table><tr><td>a</td><td>b</td></tr></table>[/et_pb_text]',
        'expect' => [ '<!-- wp:table {"hasFixedLayout":false} -->', '<tbody>' ],
    ],

    'F-03 tables with attributes are preserved verbatim' => [
        'divi'   => '[et_pb_text]<table><tr><td colspan="2" style="color:red">a</td></tr></table>[/et_pb_text]',
        'expect' => [ '<!-- wp:html -->', 'colspan="2"' ],
        'reject' => [ '<!-- wp:table' ],
    ],

    'F-03 headings and paragraphs interleave correctly' => [
        'divi'   => '[et_pb_text]<p>Intro</p><h3>Middle</h3><p>Outro</p>[/et_pb_text]',
        'expect' => [ '<h3>Middle</h3>', '<p>Intro</p>', '<p>Outro</p>' ],
    ],

    'F-03 inline markup stays in one paragraph' => [
        'divi'   => '[et_pb_text]Hello <strong>world</strong> and <a href="/x">a link</a>.[/et_pb_text]',
        'expect' => [ '<p>Hello <strong>world</strong> and <a href="/x">a link</a>.</p>' ],
    ],

    'F-03 counter headings carry textAlign' => [
        'divi'   => '[et_pb_number_counter number="42" title="Users" /]',
        'expect' => [ '<!-- wp:heading {"textAlign":"center","level":2} -->' ],
    ],

    // ---------------------------------------------------------------- F-04 --
    'F-04 pricing items become list items' => [
        'divi'   => '[et_pb_pricing_tables][et_pb_pricing_table title="Basic" sum="10" currency="$" per="mo" button_text="Buy"]'
            . '[et_pb_pricing_item]Feature A[/et_pb_pricing_item]'
            . '[et_pb_pricing_item available="off"]Feature B[/et_pb_pricing_item]'
            . '[/et_pb_pricing_table][/et_pb_pricing_tables]',
        'expect' => [ '<li>Feature A</li>', '<li><s>Feature B</s></li>', '<!-- wp:list -->' ],
        'reject' => [ 'et_pb_pricing_item' ],
    ],

    'F-04 pricing headings carry textAlign' => [
        'divi'   => '[et_pb_pricing_tables][et_pb_pricing_table title="Pro" sum="20"][/et_pb_pricing_table][/et_pb_pricing_tables]',
        'expect' => [ '<!-- wp:heading {"textAlign":"center","level":3} -->', '<!-- wp:heading {"textAlign":"center","level":2} -->' ],
    ],

    // ---------------------------------------------------------------- F-05 --
    'F-05 contact forms do not emit experimental blocks' => [
        'divi'   => '[et_pb_contact_form email="hi@example.com" title="Contact us"]'
            . '[et_pb_contact_field field_id="Name" field_title="Your name" field_type="input"][/et_pb_contact_field]'
            . '[et_pb_contact_field field_id="Pick" field_title="Topic" field_type="select" field_options="Sales|Support"][/et_pb_contact_field]'
            . '[/et_pb_contact_form]',
        'expect' => [ 'hi@example.com', 'Your name', 'dropdown', 'Sales, Support' ],
        'reject' => [ 'wp:form', 'wp:form-input', 'wp:form-submit-button' ],
        'warns'  => [ 'et_pb_contact_form' ],
    ],

    'F-05 navigation does not invent a menuId attribute' => [
        'divi'   => '[et_pb_menu menu_id="12" /]',
        'expect' => [ '<!-- wp:navigation /-->' ],
        'reject' => [ 'menuId' ],
        'warns'  => [ 'et_pb_menu' ],
    ],

    'F-05 portfolio reports its post-type dependency' => [
        'divi'   => '[et_pb_portfolio posts_number="4" include_categories="3,5" /]',
        'expect' => [ '"postType":"project"' ],
        'warns'  => [ 'et_pb_portfolio' ],
    ],

    // ---------------------------------------------------------------- F-13 --
    'F-13 a bare et_pb_ prefix is not Divi content' => [
        'divi'      => '<p>Write [et_pb_ to start a Divi tag.</p>',
        'unchanged' => true,
    ],

    'F-13 an unrecognised module is stripped, not printed' => [
        'divi'   => '[et_pb_nonesuch setting="x"]<p>Inner text</p>[/et_pb_nonesuch]',
        'expect' => [ 'Inner text' ],
        'reject' => [ 'et_pb_nonesuch' ],
        'warns'  => [ 'et_pb_nonesuch' ],
    ],

    // ------------------------------------------------------------ general --
    'section, row and column nest as group and columns' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="1_2"][et_pb_text]Left[/et_pb_text][/et_pb_column]'
            . '[et_pb_column type="1_2"][et_pb_text]Right[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<!-- wp:group', '<!-- wp:columns', '<!-- wp:column {"width":"50%"} -->' ],
    ],

    'code modules keep backslashes and markup verbatim' => [
        'divi'   => '[et_pb_code]<script>var re = /\d+\\n/; var s = "a\\b";</script>[/et_pb_code]',
        'expect' => [ '/\d+\\n/', '"a\\b"' ],
    ],

    'multibyte content survives DOM parsing' => [
        'divi'   => '[et_pb_text]<h2>Café — “quoted” 日本語</h2><p>Grüße</p>[/et_pb_text]',
        'expect' => [ 'Café — “quoted” 日本語', 'Grüße' ],
        'reject' => [ '&#' ],
    ],

    'buttons nest inside a buttons block' => [
        'divi'   => '[et_pb_button button_text="Go" button_url="https://example.com" /]',
        'expect' => [ '<!-- wp:buttons', '<!-- wp:button', 'href="https://example.com"' ],
    ],

    'images resolve to an image block' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" alt="Alt text" /]',
        'expect' => [ '<!-- wp:image', 'src="https://example.com/a.jpg"', 'alt="Alt text"' ],
    ],

    'youtube iframes become embed blocks' => [
        'divi'   => '[et_pb_text]<p>Watch:</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>[/et_pb_text]',
        'expect' => [ '"providerNameSlug":"youtube"', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ],
    ],

    'an unmatched closing tag does not lose content' => [
        'divi'   => '[et_pb_section][et_pb_text]Kept[/et_pb_text]',
        'expect' => [ 'Kept' ],
    ],

    'empty text modules produce nothing' => [
        'divi'      => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]   [/et_pb_text][/et_pb_column][/et_pb_row][et_pb_section]',
        'expect'    => [],
    ],

    'attribute values containing brackets do not truncate the tag' => [
        'divi'   => '[et_pb_text text_orientation="left"]<p>Array[0] and [1]</p>[/et_pb_text]',
        'expect' => [ 'Array[0] and [1]' ],
    ],

    'unknown modules keep their contents and are reported' => [
        'divi'   => '[et_pb_text]<p>Before</p>[/et_pb_text][et_pb_wibble]<p>Inside</p>[/et_pb_wibble]',
        'expect' => [ 'Before', 'Inside' ],
        'reject' => [ 'et_pb_wibble' ],
        'warns'  => [ 'et_pb_wibble' ],
    ],

    'toggles inside an accordion all convert' => [
        'divi'   => '[et_pb_accordion][et_pb_toggle title="One"]<p>1</p>[/et_pb_toggle][et_pb_toggle title="Two" open="on"]<p>2</p>[/et_pb_toggle][/et_pb_accordion]',
        'expect' => [ '<summary>One</summary>', '<summary>Two</summary>', '<!-- wp:details {"showContent":true} -->' ],
    ],

    'testimonials become a quote with a citation' => [
        'divi'   => '[et_pb_testimonial author="Ada" company_name="Analytical Ltd"]<p>It worked.</p>[/et_pb_testimonial]',
        'expect' => [ '<blockquote class="wp-block-quote">', '<p>It worked.</p>', '<cite><strong>Ada</strong>, Analytical Ltd</cite>' ],
    ],

    'a self-closing tag does not swallow its own closer' => [
        // [et_pb_slide /] must not be counted as an opener when the parser is
        // looking for [/et_pb_slider], or the slider would consume the rest of
        // the document.
        'divi'   => '[et_pb_slider][et_pb_slide heading="First" /][/et_pb_slider][et_pb_text]<p>After</p>[/et_pb_text]',
        'expect' => [ '<h2>First</h2>', '<p>After</p>' ],
    ],

    'sibling modules of the same tag stay separate' => [
        'divi'   => '[et_pb_text]<p>One</p>[/et_pb_text][et_pb_text]<p>Two</p>[/et_pb_text]',
        'expect' => [ "<p>One</p>\n<!-- /wp:paragraph -->", "<p>Two</p>\n<!-- /wp:paragraph -->" ],
    ],

    'same-tag nesting still matches the right closer' => [
        'divi'   => '[et_pb_section][et_pb_section][et_pb_text]<p>Inner</p>[/et_pb_text][/et_pb_section][/et_pb_section][et_pb_text]<p>Sibling</p>[/et_pb_text]',
        'expect' => [ '<p>Inner</p>', '<p>Sibling</p>' ],
    ],

    'a column with mixed modules keeps all of them' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<h2>Title</h2><p>Body</p>[/et_pb_text]'
            . '[et_pb_image src="https://example.com/i.jpg" alt="Pic" /]'
            . '[et_pb_button button_text="Act" button_url="/go" /]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<h2>Title</h2>', '<p>Body</p>', 'https://example.com/i.jpg', 'Act' ],
    ],

    'deeply nested source does not blow up' => [
        'divi'   => str_repeat( '[et_pb_section]', 60 ) . '[et_pb_text]Deep[/et_pb_text]' . str_repeat( '[/et_pb_section]', 60 ),
        'expect' => [ 'Deep' ],
    ],
];
