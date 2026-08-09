<?php
/**
 * Conversion fixtures: one Divi input per case, with what the output must show.
 *
 * `expect` entries are substrings that have to appear in the converted markup;
 * `reject` entries must not appear. Every fixture also goes through the
 * structural checks in lib/assertions.php regardless of what it declares here.
 *
 * `warns` entries name a module that must raise a conversion warning;
 * `rejectWarnings` names one that must not — a loss report that fires for
 * settings the converter did map trains users to ignore it.
 *
 * Cases tagged with a finding ID are regression guards for an external review.
 * F-* comes from the review answered in 2.1.0, N-* from the review answered in
 * 2.2.0. Every one of them was confirmed to fail against the release before its
 * fix landed — a fixture that passes both before and after guards nothing.
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

    // ---------------------------------------------------------------- N-01 --
    // The parser accepts single-quoted attribute values, so a value can carry a
    // double quote. Concatenated into a class attribute it closed that
    // attribute and opened an event handler.
    'N-01 a crafted image align cannot inject an attribute' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" align=\'center" onmouseover="alert(1)\' /]',
        'expect' => [ '<figure class="wp-block-image size-large">' ],
        'reject' => [ 'onmouseover', 'aligncenter"' ],
    ],

    'N-01 a crafted button alignment cannot inject an attribute' => [
        'divi'   => '[et_pb_button button_text="Go" button_url="https://example.com" button_alignment=\'center" onmouseover="alert(1)\' /]',
        'expect' => [ '<div class="wp-block-buttons">' ],
        'reject' => [ 'onmouseover', 'is-content-justification-center"' ],
    ],

    'N-01 a double-quoted crafted value cannot inject an attribute' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" align="center\' onmouseover=\'alert(1)" /]',
        'reject' => [ 'onmouseover' ],
    ],

    'N-01 supported alignments still survive' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" align="center" /]',
        'expect' => [ '"align":"center"', 'wp-block-image size-large aligncenter' ],
    ],

    // esc_attr() makes a value safe as markup, not safe as CSS. A colour of
    // `red;background-image:url(x)` passed straight through it.
    'N-01 a crafted colour cannot inject a CSS declaration' => [
        'divi'   => '[et_pb_button button_text="Go" button_bg_color="red;background-image:url(x)" /]',
        'reject' => [ 'background-image', 'url(x)' ],
    ],

    'N-01 real colours still survive' => [
        'divi'   => '[et_pb_button button_text="Go" button_bg_color="#ff0000" button_text_color="rgba(0,0,0,0.8)" /]',
        'expect' => [ 'background-color:#ff0000', 'color:rgba(0,0,0,0.8)' ],
    ],

    // ---------------------------------------------------------------- N-02 --
    'N-02 a fullwidth header body does not nest paragraphs' => [
        'divi'   => '[et_pb_fullwidth_header title="T"]<p>One</p><p>Two</p>[/et_pb_fullwidth_header]',
        'expect' => [
            '<p class="has-text-align-center">One</p>',
            '<p class="has-text-align-center">Two</p>',
        ],
        'reject' => [ '<p class="has-text-align-center"><p>' ],
    ],

    // ---------------------------------------------------------------- N-03 --
    'N-03 a table caption is not discarded' => [
        'divi'   => '[et_pb_text]<table><caption>Rates</caption><tbody><tr><td>A</td></tr></tbody></table>[/et_pb_text]',
        'expect' => [ 'Rates', '<!-- wp:html -->' ],
        'reject' => [ '<!-- wp:table' ],
        'warns'  => [ 'table' ],
    ],

    'N-03 an HTML comment is preserved' => [
        'divi'   => '[et_pb_text]<p>Before</p><!-- keepme --><p>After</p>[/et_pb_text]',
        'expect' => [ '<!-- keepme -->', '<p>Before</p>', '<p>After</p>' ],
    ],

    'N-03 a block-delimiter comment is removed and reported' => [
        'divi'   => '[et_pb_text]<p>Before</p><!-- wp:paragraph --><p>After</p>[/et_pb_text]',
        'reject' => [ '<!-- wp:paragraph --><p>After' ],
        'warns'  => [ 'html-comment' ],
    ],

    'N-03 a module nested in a text module survives' => [
        'divi'   => '[et_pb_text]<p>Before</p>[et_pb_button button_text="Nested" button_url="https://example.com" /]<p>After</p>[/et_pb_text]',
        'expect' => [ '<p>Before</p>', 'Nested', '<p>After</p>', '<!-- wp:buttons' ],
    ],

    'N-03 a counter body is read as text, not escaped as markup' => [
        'divi'   => '[et_pb_counters][et_pb_counter percent="70"]<p>Sales</p>[/et_pb_counter][/et_pb_counters]',
        'expect' => [ '<strong>Sales</strong>: 70%' ],
        'reject' => [ '&lt;p&gt;' ],
        'warns'  => [ 'et_pb_counter' ],
    ],

    // The 2.1.0 fixture that claimed to cover this put the brackets in body
    // text, where nothing was ever wrong. In an attribute value they truncated
    // the tag and left the remainder visible on the page.
    'N-03 a bracket inside an attribute value does not truncate the tag' => [
        'divi'   => '[et_pb_text title="Array[0]" text_orientation="left"]<p>Body</p>[/et_pb_text]',
        'expect' => [ '<p class="has-text-align-left">Body</p>' ],
        'reject' => [ '"]', 'Array[0]' ],
    ],

    'N-03 a self-closing tag with a bracketed attribute still self-closes' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" alt="Fig [1]" /][et_pb_text]<p>After</p>[/et_pb_text]',
        'expect' => [ 'alt="Fig [1]"', '<p>After</p>' ],
    ],

    'N-03 curly-quote entities in body text are not straightened' => [
        'divi'   => '[et_pb_code]<p>He said &#8220;hi&#8221; and it&#8217;s fine</p>[/et_pb_code]',
        'expect' => [ '&#8220;hi&#8221;', 'it&#8217;s' ],
    ],

    'N-03 entity-quoted attributes are still repaired' => [
        'divi'   => '[et_pb_text text_orientation=&#8220;center&#8221;]<p>Body</p>[/et_pb_text]',
        'expect' => [ '<!-- wp:paragraph {"align":"center"} -->' ],
    ],

    // ------------------------------------------------------------ N-12 --
    // Found while verifying this round, not raised by the review. Divi stores
    // attribute values HTML-encoded, so esc_html() over them encoded a second
    // time and the page published the literal characters `Fish &amp; Chips`.
    'N-12 attribute text is not double-encoded' => [
        'divi'   => '[et_pb_toggle title="Terms &amp; conditions"]<p>x</p>[/et_pb_toggle]'
            . '[et_pb_button button_text="Fish &amp; Chips" /]'
            . '[et_pb_image src="https://example.com/a.jpg" alt="A &amp; B" /]',
        'expect' => [
            '<summary>Terms &amp; conditions</summary>',
            '>Fish &amp; Chips</a>',
            'alt="A &amp; B"',
        ],
        'reject' => [ '&amp;amp;' ],
    ],

    // Decoding happens *before* escaping, so it cannot weaken the escape.
    'N-12 decoding before escaping is still safe' => [
        'divi'   => '[et_pb_toggle title="&lt;script&gt;alert(1)&lt;/script&gt;"]<p>x</p>[/et_pb_toggle]',
        'expect' => [ '&lt;script&gt;' ],
        'reject' => [ '<script>' ],
    ],

    'N-12 a raw tag in an attribute is still escaped' => [
        'divi'   => '[et_pb_button button_text="<script>alert(1)</script>" /]',
        'reject' => [ '<script>' ],
    ],

    // ---------------------------------------------------------------- N-05 --
    // Section padding used to be reported as lost. It is now mapped onto
    // core's spacing support, so this guard moved to the per-device override,
    // which is still genuinely lost — core block supports have no responsive
    // dimension. See the C-08 fixtures for the mapping itself.
    'N-05 unmapped section spacing is reported' => [
        'divi'   => '[et_pb_section custom_padding_tablet="20px|0px|20px|0px"][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'warns'  => [ 'et_pb_section' ],
    ],

    // ---------------------------------------------------------------- C-08 --
    //
    // Divi spacing and background colour mapped onto core's block supports.
    // The markup is not a guess: it is what core's own serializer emits, and
    // the declaration order it emits them in. Getting that order wrong is the
    // Cover defect in a new place, so every one of these is validated by core.

    'C-08 section padding becomes core spacing and is no longer reported lost' => [
        'divi'   => '[et_pb_section custom_padding="54px||54px||true|false"][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [
            '"padding":{"top":"54px","bottom":"54px"}',
            'style="padding-top:54px;padding-bottom:54px"',
        ],
        // Empty components mean "not set"; writing 0px there would flatten
        // spacing the theme supplies.
        'reject' => [ 'padding-left', 'padding-right' ],
        'rejectWarnings' => [ 'et_pb_section' ],
    ],

    'C-08 background comes before spacing, as core serializes it' => [
        'divi'   => '[et_pb_section background_color="#f5f5f5" custom_padding="10px|20px|30px|40px"]'
            . '[et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Hi</p>[/et_pb_text]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [
            'style="background-color:#f5f5f5;padding-top:10px;padding-right:20px;padding-bottom:30px;padding-left:40px"',
            'class="wp-block-group has-background"',
        ],
    ],

    'C-08 margin comes before padding, as core serializes it' => [
        'divi'   => '[et_pb_section custom_margin="1px|||" custom_padding="2px|||"][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ 'style="margin-top:1px;padding-top:2px"' ],
    ],

    'C-08 a row carries its own spacing and background' => [
        'divi'   => '[et_pb_section][et_pb_row background_color="#dddddd" custom_padding="8px|9px|10px|11px"]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [
            '<!-- wp:columns {"style":',
            'class="wp-block-columns has-background"',
            'background-color:#dddddd;padding-top:8px;padding-right:9px;padding-bottom:10px;padding-left:11px',
        ],
    ],

    'C-08 a column keeps flex-basis last, after its spacing' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="1_2" background_color="#eeeeee" custom_padding="20px||20px|"]'
            . '[et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column]'
            . '[et_pb_column type="1_2"][et_pb_text]<p>Ho</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [ 'style="background-color:#eeeeee;padding-top:20px;padding-bottom:20px;flex-basis:50%"' ],
    ],

    'C-08 a background colour switched off is not painted' => [
        'divi'   => '[et_pb_section background_color="#f5f5f5" background_enable_color="off"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'reject' => [ 'has-background', 'background-color' ],
    ],

    'C-08 a spacing value that is not a length is dropped, not escaped' => [
        'divi'   => '[et_pb_section custom_padding="20px;position:fixed|auto|5%|2em"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [ 'padding-bottom:5%', 'padding-left:2em' ],
        // The injection and `auto` are both gone: one is unsafe, the other is
        // not a length core's spacing support can regenerate.
        'reject' => [ 'position:fixed', 'padding-top', 'auto' ],
    ],

    // The label matters as much as the warning. A section that kept its padding
    // and lost only the tablet override must not say "spacing was not carried
    // over" — that sends the user to rebuild something already there.
    'C-08 a per-device override is reported as an override, not as lost spacing' => [
        'divi'   => '[et_pb_section custom_padding="10px|||" custom_padding_tablet="5px|||"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [ 'padding-top:10px' ],
        'warns'  => [ 'et_pb_section' ],
    ],

    // ---------------------------------------------------------------- C-10 --
    //
    // Borders onto core's border support. The declaration order here disproved
    // the "alphabetical" guess the earlier phases got away with: core emits
    // border before background, which alphabetically follows it.

    'C-10 a section carries a uniform border and radius' => [
        'divi'   => '[et_pb_section border_width_all="2px" border_color_all="#333333"'
            . ' border_style_all="solid" border_radii="on|8px|8px|8px|8px"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [
            'style="border-color:#333333;border-style:solid;border-width:2px;border-radius:8px"',
            'class="wp-block-group has-border-color"',
        ],
        'rejectWarnings' => [ 'et_pb_section' ],
    ],

    'C-10 border sorts before background and spacing, as core serializes it' => [
        'divi'   => '[et_pb_section border_width_all="2px" border_color_all="#333333"'
            . ' background_color="#f5f5f5" custom_padding="10px|||"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [
            'style="border-color:#333333;border-width:2px;background-color:#f5f5f5;padding-top:10px"',
            'class="wp-block-group has-border-color has-background"',
        ],
    ],

    // Divi's corner order is not documented and CSS shorthand order is a guess,
    // so a per-corner radius is named rather than rounded onto the wrong side.
    'C-10 a per-corner radius is reported, not guessed at' => [
        'divi'   => '[et_pb_section border_radii="on|4px|8px|12px|16px"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'reject' => [ 'border-radius' ],
        'warns'  => [ 'et_pb_section' ],
    ],

    'C-10 three corners set and one empty is still ambiguous' => [
        'divi'   => '[et_pb_section border_radii="on|5px|5px|5px|"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'reject' => [ 'border-radius' ],
        'warns'  => [ 'et_pb_section' ],
    ],

    'C-10 a radius switched off is not applied and not reported' => [
        'divi'   => '[et_pb_section border_radii="off|5px|5px|5px|5px"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'reject' => [ 'border-radius' ],
        'rejectWarnings' => [ 'et_pb_section' ],
    ],

    'C-10 a border style that is not a keyword is dropped' => [
        'divi'   => '[et_pb_section border_style_all="solid;position:fixed" border_width_all="1px"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [ 'border-width:1px' ],
        'reject' => [ 'position:fixed', 'border-style' ],
    ],

    'C-10 a column carries a border with flex-basis still last' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="1_2" border_width_all="1px" border_color_all="#cccccc"]'
            . '[et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[et_pb_column type="1_2"][et_pb_text]<p>C</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'expect' => [ 'style="border-color:#cccccc;border-width:1px;flex-basis:50%"' ],
    ],

    'C-10 per-side border widths are still reported as lost' => [
        'divi'   => '[et_pb_section border_width_top="4px"][et_pb_row]'
            . '[et_pb_column type="4_4"][et_pb_text]<p>B</p>[/et_pb_text][/et_pb_column]'
            . '[/et_pb_row][/et_pb_section]',
        'reject' => [ 'border-width' ],
        'warns'  => [ 'et_pb_section' ],
    ],

    // ---------------------------------------------------------------- C-09 --
    //
    // Typography onto core's text block supports. Core serializes these
    // alphabetically by CSS property — color, font-size, letter-spacing,
    // line-height — and a block that lists them differently is reported as
    // containing unexpected content.

    'C-09 a text module carries its body and heading typography' => [
        'divi'   => '[et_pb_text header_text_color="#1a1a1a" header_font_size="32px"'
            . ' body_text_color="#444444" body_font_size="18px" body_line_height="1.6"]'
            . '<h2>Title</h2><p>Body</p>[/et_pb_text]',
        'expect' => [
            '<h2 class="has-text-color" style="color:#1a1a1a;font-size:32px">',
            '<p class="has-text-color" style="color:#444444;font-size:18px;line-height:1.6">',
            '"typography":{"fontSize":"32px"}',
        ],
        'rejectWarnings' => [ 'et_pb_text' ],
    ],

    'C-09 alignment and colour classes coexist' => [
        'divi'   => '[et_pb_text text_orientation="center" body_text_color="#444444"]<p>B</p>[/et_pb_text]',
        'expect' => [ 'class="has-text-align-center has-text-color"', '"align":"center"' ],
    ],

    'C-09 line height may be unitless, which css_length alone would reject' => [
        'divi'   => '[et_pb_text body_line_height="1.6"]<p>B</p>[/et_pb_text]',
        'expect' => [ 'line-height:1.6' ],
    ],

    'C-09 a typographic value that is not a length is dropped' => [
        'divi'   => '[et_pb_text body_font_size="18px;position:fixed" body_letter_spacing="2px"]<p>B</p>[/et_pb_text]',
        'expect' => [ 'letter-spacing:2px' ],
        'reject' => [ 'position:fixed', 'font-size' ],
    ],

    // header_* only reaches the Text module. Every other module builds its
    // heading in its own renderer from an attribute and never sees the setting,
    // so it must still be reported.
    'C-09 a CTA carries body typography but still reports its heading typography' => [
        'divi'   => '[et_pb_cta title="T" body_font_size="18px" header_font_size="40px"]<p>B</p>[/et_pb_cta]',
        'expect' => [ 'font-size:18px' ],
        'reject' => [ 'font-size:40px' ],
        'warns'  => [ 'et_pb_cta' ],
    ],

    'C-09 a module that does not render a body still reports its typography' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" body_font_size="18px" /]',
        'reject' => [ 'font-size' ],
        'warns'  => [ 'et_pb_image' ],
    ],

    // Was "C-08 a text module still reports the padding it loses", and it did:
    // 2.4.0 mapped spacing onto containers only, so a Text module's own padding
    // was reported rather than carried. It is carried now, and the fixture
    // guards the opposite — that the padding arrives *and* that the module
    // stops claiming it was lost.
    'C-08 a text module carries its own padding' => [
        'divi'           => '[et_pb_text custom_padding="10px|||"]<p>Hi</p>[/et_pb_text]',
        'expect'         => [
            '<!-- wp:group {"style":{"spacing":{"padding":{"top":"10px"}}}} -->',
            '<div class="wp-block-group" style="padding-top:10px">',
            '<p>Hi</p>',
        ],
        'rejectWarnings' => [ 'et_pb_text' ],
    ],

    // The wrapper is the cost of carrying it, so it is only paid where there is
    // something to carry.
    'a text module with no spacing gains no wrapper' => [
        'divi'   => '[et_pb_text]<p>Hi</p>[/et_pb_text]',
        'expect' => [ '<p>Hi</p>' ],
        'reject' => [ 'wp-block-group' ],
    ],

    // ------------------------------------------- content-module spacing --
    //
    // 2.4.0 mapped spacing onto section, row and column and stopped. On a real
    // 247-page corpus that left 835 spacing values on the modules *inside* the
    // containers reported as lost. Every block below supports spacing in core;
    // the markup each one expects was measured with tests/js/canonical.mjs,
    // because the style attribute's position and the declaration order are
    // per-block and getting either wrong fails validation.

    'an image carries its own margin' => [
        'divi'           => '[et_pb_image src="https://example.com/a.jpg" custom_margin="20px|||" /]',
        'expect'         => [ '"spacing":{"margin":{"top":"20px"}}', '<figure class="wp-block-image size-large" style="margin-top:20px">' ],
        'rejectWarnings' => [ 'et_pb_image' ],
    ],

    'a gallery carries its own padding' => [
        'divi'   => '[et_pb_gallery gallery_ids="11" custom_padding="10px|||" /]',
        'expect' => [ '"spacing":{"padding":{"top":"10px"}}', 'style="padding-top:10px"' ],
    ],

    'a button carries its spacing on the buttons wrapper' => [
        'divi'           => '[et_pb_button button_url="https://example.com" button_text="Go" custom_margin="20px|||" /]',
        'expect'         => [ '<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"20px"}}}} -->', '<div class="wp-block-buttons" style="margin-top:20px">' ],
        'rejectWarnings' => [ 'et_pb_button' ],
    ],

    'a divider carries its margin' => [
        'divi'   => '[et_pb_divider custom_margin="30px||30px|" /]',
        'expect' => [ '"spacing":{"margin":{"top":"30px","bottom":"30px"}}', 'style="margin-top:30px;margin-bottom:30px"' ],
    ],

    // core emits spacing *before* the colour declarations on a separator, which
    // is the reverse of the order a group uses. Measured, not assumed.
    'a coloured divider orders spacing before its colour' => [
        'divi'   => '[et_pb_divider color="#ff0000" custom_margin="30px|||" /]',
        'expect' => [ 'style="margin-top:30px;background-color:#ff0000;color:#ff0000"' ],
    ],

    // core/video and core/audio write style before class; image and gallery
    // write it after. Same support, different serializer.
    'a video writes its style before its class' => [
        'divi'   => '[et_pb_video src="https://example.com/v.mp4" custom_margin="20px|||" /]',
        'expect' => [ '<figure style="margin-top:20px" class="wp-block-video">' ],
    ],

    // ---------------------------------------------- spacing that is not lost --
    //
    // `custom_padding__hover` is a hover override, but the spacing pattern
    // matched it before the hover one did, so a column whose padding had been
    // carried still reported that its spacing was lost. On the live corpus that
    // was all 247 column warnings, every one of them wrong.
    'a hover padding override is reported as hover, not as spacing' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="4_4" custom_padding="10px|||" custom_padding__hover="20px|||"]'
            . '[et_pb_text]<p>x</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ 'padding-top:10px' ],
        'warns'  => [ 'et_pb_column' ],
        'reject' => [ 'spacing (padding or margin)' ],
    ],

    // Divi writes `|||` for a module whose padding was never set. A quarter of
    // every spacing attribute in the live corpus looked like this, and each one
    // sent the user off to rebuild padding that had never existed.
    'a spacing value with no sides set is not reported as lost' => [
        'divi'           => '[et_pb_section][et_pb_row][et_pb_column type="4_4" custom_padding="|||" custom_padding__hover="|||"]'
            . '[et_pb_text]<p>x</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'rejectWarnings' => [ 'et_pb_column' ],
    ],

    'a spacing value that is only Divi\'s trailing flags is not a value' => [
        'divi'           => '[et_pb_image src="https://example.com/a.jpg" custom_margin="||||true|false" /]',
        'rejectWarnings' => [ 'et_pb_image' ],
        'reject'         => [ 'margin-top' ],
    ],

    'N-05 lost tab behaviour is reported' => [
        'divi'   => '[et_pb_tabs][et_pb_tab title="One"]<p>A</p>[/et_pb_tab][et_pb_tab title="Two"]<p>B</p>[/et_pb_tab][/et_pb_tabs]',
        'expect' => [ '<h3>One</h3>', '<h3>Two</h3>' ],
        'warns'  => [ 'et_pb_tabs' ],
    ],

    'N-05 lost slider behaviour is reported' => [
        'divi'   => '[et_pb_slider][et_pb_slide heading="First"]<p>A</p>[/et_pb_slide][/et_pb_slider]',
        'warns'  => [ 'et_pb_slider' ],
    ],

    'N-05 a mapped background image is not falsely reported' => [
        'divi'   => '[et_pb_fullwidth_header title="T" background_image="https://example.com/bg.jpg" /]',
        'expect' => [ '<!-- wp:cover', 'https://example.com/bg.jpg' ],
        'rejectWarnings' => [ 'et_pb_fullwidth_header' ],
    ],

    'N-05 an unmapped section background image is reported' => [
        'divi'   => '[et_pb_section background_image="https://example.com/bg.jpg"][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'warns'  => [ 'et_pb_section' ],
    ],

    'N-05 a module with only mapped settings is not falsely reported' => [
        'divi'   => '[et_pb_text text_orientation="center"]<p>Hi</p>[/et_pb_text]',
        'expect' => [ '<!-- wp:paragraph {"align":"center"} -->' ],
        'rejectWarnings' => [ 'et_pb_text' ],
    ],

    // ------------------------------------------------------- module coverage --
    //
    // One fixture per Divi module this plugin claims to handle. These make no
    // clever assertions; their job is to put every renderer on the path of the
    // structural checks, the real WordPress block validator, and the golden
    // snapshots. Before they existed, 33 of the 58 supported modules were never
    // executed by any test, which meant a refactor could delete or break any of
    // them and the suite would stay green.
    //
    // tests/lib/coverage.php fails the run if a supported tag has no fixture
    // here, so this list cannot silently fall behind the parser's tag list.

    'module et_pb_row_inner and et_pb_column_inner nest' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_row_inner][et_pb_column_inner type="1_2"][et_pb_text]<p>Inner</p>[/et_pb_text][/et_pb_column_inner][/et_pb_row_inner]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<p>Inner</p>', '<!-- wp:column {"width":"50%"} -->' ],
    ],

    'module et_pb_video with a file source' => [
        'divi'   => '[et_pb_video src="https://example.com/clip.mp4" /]',
        'expect' => [ '<!-- wp:video', '<figure class="wp-block-video">' ],
    ],

    'module et_pb_video with a YouTube source' => [
        'divi'   => '[et_pb_video src="https://www.youtube.com/watch?v=dQw4w9WgXcQ" /]',
        'expect' => [ '<!-- wp:embed', 'youtube' ],
    ],

    'module et_pb_blurb' => [
        'divi'   => '[et_pb_blurb title="Fast" image="https://example.com/i.png" url="/why" header_level="h3"]<p>Because.</p>[/et_pb_blurb]',
        'expect' => [ 'Fast', '<p>Because.</p>', 'https://example.com/i.png' ],
    ],

    'module et_pb_cta' => [
        'divi'   => '[et_pb_cta title="Sign up" button_text="Go" button_url="/go"]<p>Do it.</p>[/et_pb_cta]',
        'expect' => [ '<h2>Sign up</h2>', '<p>Do it.</p>', 'href="/go"' ],
    ],

    'module et_pb_divider' => [
        'divi'   => '[et_pb_divider color="#cccccc" /]',
        'expect' => [ '<!-- wp:separator', 'background-color:#cccccc' ],
    ],

    'module et_pb_gallery emits an image per attachment' => [
        'divi'   => '[et_pb_gallery gallery_ids="11,12" gallery_columns="2" /]',
        'expect' => [ '<!-- wp:gallery', 'attachment-11.jpg', 'attachment-12.jpg', 'columns-2' ],
    ],

    'module et_pb_gallery carousel reports lost behaviour' => [
        'divi'   => '[et_pb_gallery gallery_ids="11" gallery_layout="slider" /]',
        'expect' => [ 'd2g-gallery-slider' ],
        'warns'  => [ 'et_pb_gallery' ],
    ],

    'module et_pb_gallery with no images' => [
        'divi'   => '[et_pb_gallery /]',
        'expect' => [ '<!-- wp:paragraph -->' ],
    ],

    'module et_pb_blog' => [
        'divi'   => '[et_pb_blog posts_number="5" include_categories="3" /]',
        'expect' => [ '<!-- wp:latest-posts', '"postsToShow":5' ],
    ],

    'module et_pb_sidebar' => [
        'divi'   => '[et_pb_sidebar area="sidebar-1" /]',
        'expect' => [ 'sidebar-1', '<!-- wp:paragraph -->' ],
    ],

    'module et_pb_circle_counter' => [
        'divi'   => '[et_pb_circle_counter number="88" title="Uptime" /]',
        'expect' => [ '88', 'Uptime' ],
        'warns'  => [ 'et_pb_circle_counter' ],
    ],

    'module et_pb_social_media_follow with networks' => [
        'divi'   => '[et_pb_social_media_follow]'
            . '[et_pb_social_media_follow_network social_network="facebook" url="https://facebook.com/x"][/et_pb_social_media_follow_network]'
            . '[et_pb_social_media_follow_network social_network="x" url="https://x.com/y"][/et_pb_social_media_follow_network]'
            . '[/et_pb_social_media_follow]',
        'expect' => [ '<!-- wp:social-links', '<!-- wp:social-link', '"url":"https://facebook.com/x"' ],
    ],

    'module et_pb_map with a pin' => [
        'divi'   => '[et_pb_map address="10 Downing Street"][et_pb_map_pin title="Office"]<p>Here</p>[/et_pb_map_pin][/et_pb_map]',
        'expect' => [ '10 Downing Street', 'Office' ],
        'warns'  => [ 'et_pb_map' ],
    ],

    'module et_pb_signup' => [
        'divi'   => '[et_pb_signup title="Newsletter" description="Monthly." /]',
        'expect' => [ 'Newsletter', 'Monthly.' ],
    ],

    'module et_pb_login' => [
        'divi'   => '[et_pb_login /]',
        'expect' => [ 'wp:loginout' ],
    ],

    'module et_pb_filterable_portfolio' => [
        'divi'   => '[et_pb_filterable_portfolio posts_number="6" /]',
        'expect' => [ '<!-- wp:query' ],
        'warns'  => [ 'et_pb_filterable_portfolio' ],
    ],

    'module et_pb_fullwidth_image' => [
        'divi'   => '[et_pb_fullwidth_image src="https://example.com/wide.jpg" alt="Wide" /]',
        'expect' => [ '<!-- wp:image', 'https://example.com/wide.jpg' ],
    ],

    'module et_pb_fullwidth_slider' => [
        'divi'   => '[et_pb_fullwidth_slider][et_pb_slide heading="One"]<p>A</p>[/et_pb_slide][/et_pb_fullwidth_slider]',
        'expect' => [ '<h2>One</h2>', '<p>A</p>' ],
        'warns'  => [ 'et_pb_fullwidth_slider' ],
    ],

    'module et_pb_post_slider' => [
        'divi'   => '[et_pb_post_slider][et_pb_slide heading="Post"]<p>B</p>[/et_pb_slide][/et_pb_post_slider]',
        'expect' => [ '<h2>Post</h2>' ],
        'warns'  => [ 'et_pb_post_slider' ],
    ],

    'module et_pb_fullwidth_post_slider' => [
        'divi'   => '[et_pb_fullwidth_post_slider][et_pb_slide heading="Wide post"]<p>C</p>[/et_pb_slide][/et_pb_fullwidth_post_slider]',
        'expect' => [ '<h2>Wide post</h2>' ],
        'warns'  => [ 'et_pb_fullwidth_post_slider' ],
    ],

    'module et_pb_slide with a background image becomes a cover' => [
        'divi'   => '[et_pb_slider][et_pb_slide heading="Hero" background_image="https://example.com/hero.jpg"]<p>D</p>[/et_pb_slide][/et_pb_slider]',
        'expect' => [ '<!-- wp:cover', 'wp-block-cover__image-background', 'has-background-dim' ],
    ],

    'module et_pb_fullwidth_code' => [
        'divi'   => '[et_pb_fullwidth_code]<div data-x="1">raw</div>[/et_pb_fullwidth_code]',
        'expect' => [ '<!-- wp:html -->', 'data-x="1"' ],
    ],

    'module et_pb_fullwidth_menu' => [
        'divi'   => '[et_pb_fullwidth_menu menu_id="7" /]',
        'expect' => [ 'wp:navigation' ],
        'reject' => [ 'menuId' ],
        'warns'  => [ 'et_pb_fullwidth_menu' ],
    ],

    'module et_pb_fullwidth_map' => [
        'divi'   => '[et_pb_fullwidth_map address_lat="51.5" address_lng="-0.12" /]',
        'expect' => [ '51.5', '-0.12' ],
        'warns'  => [ 'et_pb_fullwidth_map' ],
    ],

    'module et_pb_fullwidth_portfolio' => [
        'divi'   => '[et_pb_fullwidth_portfolio posts_number="3" fullwidth="on" /]',
        'expect' => [ '<!-- wp:query' ],
        'warns'  => [ 'et_pb_fullwidth_portfolio' ],
    ],

    'module et_pb_audio' => [
        'divi'   => '[et_pb_audio audio="https://example.com/song.mp3" title="Track" artist_name="Someone" /]',
        'expect' => [ '<!-- wp:audio', 'song.mp3', 'Track', 'Someone' ],
    ],

    'module et_pb_team_member' => [
        'divi'   => '[et_pb_team_member name="Ada" position="Engineer" image_url="https://example.com/ada.jpg" facebook_url="https://facebook.com/ada"]<p>Bio.</p>[/et_pb_team_member]',
        'expect' => [ '<h3>Ada</h3>', 'Engineer', '<p>Bio.</p>', 'wp:social-link' ],
    ],

    'module et_pb_shop' => [
        'divi'   => '[et_pb_shop type="recent" columns_number="4" posts_number="8" /]',
        'expect' => [ 'WooCommerce' ],
    ],

    'module et_pb_search' => [
        'divi'   => '[et_pb_search placeholder="Find things" /]',
        'expect' => [ 'wp:search' ],
    ],

    'module et_pb_post_title' => [
        'divi'   => '[et_pb_post_title /]',
        'expect' => [ 'wp:post-title' ],
    ],

    'module et_pb_comments' => [
        'divi'   => '[et_pb_comments /]',
        'expect' => [ 'wp:comments' ],
    ],

    'module et_pb_video_slider' => [
        'divi'   => '[et_pb_video_slider][et_pb_video_slider_item src="https://example.com/a.mp4" /][et_pb_video_slider_item src="https://example.com/b.mp4" /][/et_pb_video_slider]',
        'expect' => [ 'a.mp4', 'b.mp4' ],
        'warns'  => [ 'et_pb_video_slider' ],
    ],

    // ------------------------------------------------------- branch coverage --
    //
    // Module coverage proves every renderer runs. It does not prove every
    // *branch* of every renderer runs, and a line-coverage measurement (xdebug,
    // in a container) showed 13% of the converter never executing — including
    // the mapped background-colour path, the <pre> and <hr> and <div> branches
    // of the HTML splitter, Vimeo embeds, the Fullwidth Header buttons, and the
    // pre-6.3 Details degradation. Each of those is markup a refactor could
    // break with nothing to notice.

    'branch a section background colour is mapped' => [
        'divi'   => '[et_pb_section background_color="#eeeeee"][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<p>Hi</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '"background":"#eeeeee"', 'has-background', 'background-color:#eeeeee' ],
    ],

    'branch a CTA background colour is mapped' => [
        'divi'   => '[et_pb_cta title="Act" background_color="#123456"]<p>Now</p>[/et_pb_cta]',
        'expect' => [ '"background":"#123456"', 'background-color:#123456' ],
    ],

    'branch a button alignment that is supported is mapped' => [
        'divi'   => '[et_pb_button button_text="Go" button_url="/x" button_alignment="right" /]',
        'expect' => [ '"justifyContent":"right"', 'is-content-justification-right' ],
    ],

    'branch a divider with no colour' => [
        'divi'   => '[et_pb_divider /]',
        'expect' => [ '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ],
        'reject' => [ 'has-background' ],
    ],

    'branch preformatted text becomes core/preformatted' => [
        'divi'   => '[et_pb_text]<pre>line one
line two</pre>[/et_pb_text]',
        'expect' => [ '<!-- wp:preformatted -->', '<pre class="wp-block-preformatted">' ],
    ],

    'branch a horizontal rule becomes a separator' => [
        'divi'   => '[et_pb_text]<p>Above</p><hr /><p>Below</p>[/et_pb_text]',
        'expect' => [ '<!-- wp:separator -->', '<p>Above</p>', '<p>Below</p>' ],
    ],

    'branch a wrapper div is unwrapped rather than preserved' => [
        'divi'   => '[et_pb_text]<div><p>Wrapped</p><h3>Heading</h3></div>[/et_pb_text]',
        'expect' => [ '<p>Wrapped</p>', '<h3>Heading</h3>' ],
        'reject' => [ '<!-- wp:html -->' ],
    ],

    'branch an unknown block element is preserved as html' => [
        'divi'   => '[et_pb_text]<p>Text</p><dl><dt>Term</dt><dd>Definition</dd></dl>[/et_pb_text]',
        'expect' => [ '<!-- wp:html -->', '<dl>', 'Definition' ],
    ],

    'branch a Vimeo iframe becomes a Vimeo embed' => [
        'divi'   => '[et_pb_text]<iframe src="https://player.vimeo.com/video/76979871"></iframe>[/et_pb_text]',
        'expect' => [ '"providerNameSlug":"vimeo"', 'https://vimeo.com/76979871' ],
    ],

    'branch a video tag becomes a video block' => [
        'divi'   => '[et_pb_text]<video src="https://example.com/v.mp4"></video>[/et_pb_text]',
        'expect' => [ '<!-- wp:video', '<figure class="wp-block-video">' ],
    ],

    'branch a video module falls back to a URL in its body' => [
        'divi'   => '[et_pb_video]https://example.com/body.mp4[/et_pb_video]',
        'expect' => [ '<!-- wp:video', 'body.mp4' ],
    ],

    'branch a fullwidth header emits both buttons' => [
        'divi'   => '[et_pb_fullwidth_header title="Hero" button_one_text="Primary" button_one_url="/a"'
            . ' button_two_text="Secondary" button_two_url="/b"]<p>Body</p>[/et_pb_fullwidth_header]',
        'expect' => [ 'Primary', 'Secondary', 'is-style-outline', 'href="/a"', 'href="/b"' ],
    ],

    // The one path that only exists for WordPress 6.0–6.2.
    'branch toggles degrade when core/details is unavailable' => [
        'divi'         => '[et_pb_toggle title="Terms" open="on"]<p>Body</p>[/et_pb_toggle]',
        'unregistered' => [ 'core/details' ],
        'expect'       => [ '<h3>Terms</h3>', '<p>Body</p>' ],
        'reject'       => [ 'wp:details' ],
        'warns'        => [ 'et_pb_toggle' ],
    ],

    'branch a resolvable menu is named in the warning' => [
        'divi'   => '[et_pb_menu menu_id="99" /]',
        'expect' => [ 'wp:navigation' ],
        'warns'  => [ 'et_pb_menu' ],
    ],

    'branch a gallery drops images whose attachment is gone' => [
        'divi'   => '[et_pb_gallery gallery_ids="11,999" /]',
        'expect' => [ 'attachment-11.jpg' ],
        'reject' => [ 'src=""' ],
        'warns'  => [ 'et_pb_gallery' ],
    ],

    // A gallery whose every ID is dead converts to a valid, empty gallery
    // block. That is the right markup and the wrong thing to do quietly: a
    // Divi gallery holds attachment IDs and no URLs, so nothing downstream can
    // reconstruct what was in it. On a live corpus this silently emptied 202
    // of 243 galleries.
    'a gallery with no resolvable images says so' => [
        'divi'   => '[et_pb_gallery gallery_ids="999" /]',
        'expect' => [ 'wp:gallery' ],
        'reject' => [ 'wp:image', 'src=""' ],
        'warns'  => [ 'et_pb_gallery' ],
    ],

    'branch a social network with no name is skipped' => [
        'divi'   => '[et_pb_social_media_follow]'
            . '[et_pb_social_media_follow_network url="https://example.com/x"][/et_pb_social_media_follow_network]'
            . '[et_pb_social_media_follow_network social_network="github" url="https://github.com/x"][/et_pb_social_media_follow_network]'
            . '[/et_pb_social_media_follow]',
        'expect' => [ 'github' ],
    ],

    // get_inner_content() takes a different path when a node has module
    // children: it returns only the loose text between them.
    'branch a counter label ignores nested modules' => [
        'divi'   => '[et_pb_counters][et_pb_counter percent="40"]Growth[et_pb_button button_text="x" /][/et_pb_counter][/et_pb_counters]',
        'expect' => [ '<strong>Growth</strong>: 40%' ],
    ],

    'branch an image with a link, new window and caption' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" alt="A" url="https://example.com/go" url_new_window="on"]Caption <em>text</em>[/et_pb_image]',
        'expect' => [ 'href="https://example.com/go"', 'target="_blank"', 'rel="noopener noreferrer"', '<figcaption class="wp-element-caption">', '"href":"https://example.com/go"' ],
    ],

    'branch a cover carries an overlay colour' => [
        'divi'   => '[et_pb_fullwidth_header title="Hero" background_image="https://example.com/bg.jpg" background_color="#102030"]<p>Body</p>[/et_pb_fullwidth_header]',
        'expect' => [ '"customOverlayColor":"#102030"', 'style="background-color:#102030"' ],
    ],

    'branch a video module normalises a YouTube embed URL' => [
        'divi'   => '[et_pb_video src="https://www.youtube.com/embed/dQw4w9WgXcQ" /]',
        'expect' => [ 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', '"providerNameSlug":"youtube"' ],
    ],

    'branch a video module normalises a nocookie embed URL' => [
        'divi'   => '[et_pb_video src="https://www.youtube-nocookie.com/embed/abc123" /]',
        'expect' => [ 'https://www.youtube.com/watch?v=abc123' ],
    ],

    'branch a video module reads an iframe from its body' => [
        'divi'   => '[et_pb_video]<iframe src="https://player.vimeo.com/video/12345"></iframe>[/et_pb_video]',
        'expect' => [ 'https://vimeo.com/12345' ],
    ],

    'branch a video module with no source produces nothing' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_video /]'
            . '[et_pb_text]<p>Kept</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<p>Kept</p>' ],
        'reject' => [ 'wp:video', 'wp:embed' ],
    ],

    'branch a third-party iframe is preserved as html' => [
        'divi'   => '[et_pb_text]<iframe src="https://maps.example.com/embed?q=1"></iframe>[/et_pb_text]',
        'expect' => [ '<!-- wp:html -->', 'maps.example.com' ],
    ],

    'branch an object embed is preserved as html' => [
        'divi'   => '[et_pb_text]<object data="https://example.com/f.pdf"></object>[/et_pb_text]',
        'expect' => [ '<!-- wp:html -->', 'f.pdf' ],
    ],

    'branch a blurb with no header level defaults to h4' => [
        'divi'   => '[et_pb_blurb title="Plain"]<p>Body</p>[/et_pb_blurb]',
        'expect' => [ '<h4>Plain</h4>', '"level":4' ],
    ],

    'branch a blurb title links when a url is set' => [
        'divi'   => '[et_pb_blurb title="Linked" url="https://example.com/x" url_new_window="on" header_level="h2"]<p>B</p>[/et_pb_blurb]',
        'expect' => [ '<h2><a href="https://example.com/x" target="_blank"', 'Linked</a></h2>' ],
    ],

    'branch a fullwidth header subhead' => [
        'divi'   => '[et_pb_fullwidth_header title="T" subhead="Tagline"]<p>B</p>[/et_pb_fullwidth_header]',
        'expect' => [ 'has-large-font-size', 'Tagline' ],
    ],

    'branch a slide with a button' => [
        'divi'   => '[et_pb_slider][et_pb_slide heading="S" button_text="Act" button_link="/go"]<p>B</p>[/et_pb_slide][/et_pb_slider]',
        'expect' => [ '<!-- wp:buttons', 'Act', 'href="/go"' ],
    ],

    'branch a testimonial with a portrait and linked company' => [
        'divi'   => '[et_pb_testimonial author="Ada" company_name="Analytical" url="https://example.com/c" portrait_url="https://example.com/p.jpg"]<p>Good.</p>[/et_pb_testimonial]',
        'expect' => [ 'is-style-rounded', 'p.jpg', '<a href="https://example.com/c">Analytical</a>' ],
    ],

    'branch a testimonial with no body still quotes the author' => [
        'divi'   => '[et_pb_testimonial author="Ada"][/et_pb_testimonial]',
        'expect' => [ '<!-- wp:quote', '<cite><strong>Ada</strong></cite>' ],
    ],

    'branch a pricing table subtitle' => [
        'divi'   => '[et_pb_pricing_tables][et_pb_pricing_table title="Pro" subtitle="Best value" sum="30"][/et_pb_pricing_table][/et_pb_pricing_tables]',
        'expect' => [ 'Best value' ],
    ],

    'branch a counter falls back to its title attribute' => [
        'divi'   => '[et_pb_counters][et_pb_counter percent="25" title="Attr label" /][/et_pb_counters]',
        'expect' => [ '<strong>Attr label</strong>: 25%' ],
    ],

    'branch a table with attributes on the table element' => [
        'divi'   => '[et_pb_text]<table class="striped"><tbody><tr><td>a</td></tr></tbody></table>[/et_pb_text]',
        'expect' => [ '<!-- wp:html -->', 'class="striped"' ],
        'reject' => [ '<!-- wp:table' ],
    ],

    'branch a table with a head and a foot' => [
        'divi'   => '[et_pb_text]<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>B</td></tr></tbody>'
            . '<tfoot><tr><td>F</td></tr></tfoot></table>[/et_pb_text]',
        'expect' => [ '<!-- wp:table', '<thead>', '<tfoot>' ],
    ],

    'branch an empty table is preserved as html' => [
        'divi'   => '[et_pb_text]<p>Before</p><table></table>[/et_pb_text]',
        'expect' => [ '<p>Before</p>' ],
    ],

    'branch a contact form with no email address' => [
        'divi'   => '[et_pb_contact_form title="Enquiries"][/et_pb_contact_form]',
        'expect' => [ 'Enquiries' ],
        'warns'  => [ 'et_pb_contact_form' ],
    ],

    'branch an audio module with cover art' => [
        'divi'   => '[et_pb_audio audio="https://example.com/s.mp3" image_url="https://example.com/cover.jpg" /]',
        'expect' => [ 'cover.jpg', '<!-- wp:audio' ],
    ],

    'branch a search block with no placeholder' => [
        'divi'   => '[et_pb_search /]',
        'expect' => [ '<!-- wp:search /-->' ],
    ],

    'branch deeply nested wrappers hit the depth guard' => [
        'divi'   => '[et_pb_text]' . str_repeat( '<div>', 14 ) . '<p>Deep</p>' . str_repeat( '</div>', 14 ) . '[/et_pb_text]',
        'expect' => [ 'Deep' ],
    ],

    // add_warning() keeps one entry per distinct problem, not one per instance.
    'branch repeated identical warnings collapse to one' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_text]<table><caption>A</caption><tbody><tr><td>1</td></tr></tbody></table>[/et_pb_text]'
            . '[et_pb_text]<table><caption>B</caption><tbody><tr><td>2</td></tr></tbody></table>[/et_pb_text]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ 'A', 'B' ],
        'warns'  => [ 'table' ],
    ],

    'branch a standalone social network outside a follow module' => [
        'divi'   => '[et_pb_social_media_follow_network social_network="linkedin" url="https://linkedin.com/in/x"][/et_pb_social_media_follow_network]',
        'expect' => [ '<!-- wp:social-link', 'linkedin' ],
    ],

    'branch a blurb with an out-of-range header level falls back to h4' => [
        'divi'   => '[et_pb_blurb title="Odd" header_level="h9"]<p>B</p>[/et_pb_blurb]',
        'expect' => [ '<h4>Odd</h4>', '"level":4' ],
    ],

    // Every renderer's "nothing to render" guard, in one page. The Text module
    // is there so the fixture has output at all; the point is that the empty
    // modules contribute nothing and break nothing.
    'branch empty modules render nothing and break nothing' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type="4_4"]'
            . '[et_pb_image /]'
            . '[et_pb_blurb /]'
            . '[et_pb_audio /]'
            . '[et_pb_gallery gallery_ids="999" /]'
            . '[et_pb_map /]'
            . '[et_pb_social_media_follow][/et_pb_social_media_follow]'
            . '[et_pb_pricing_tables][/et_pb_pricing_tables]'
            . '[et_pb_counters][/et_pb_counters]'
            . '[et_pb_nothinghere]   [/et_pb_nothinghere]'
            . '[et_pb_text]<p>Only this survives</p>[/et_pb_text]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<p>Only this survives</p>' ],
        'reject' => [ 'wp:image', 'wp:audio', 'src=""' ],
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

    // ------------------------------------------------- entity-quoted attrs --
    //
    // Divi content reaches this plugin having been through storage paths that
    // write an attribute's delimiting quotes as &quot;. Every fixture above was
    // written with literal quotes, so nothing caught what that does: the
    // attribute regex matched nothing, every module rendered with an empty
    // attribute array, and a 247-page live corpus lost all 278 of its images,
    // 249 of its 252 button links, and 97% of its mapped design settings —
    // reporting 783 warnings where 1,243 were due.

    'entity-quoted attributes are read as attributes' => [
        'divi'   => '[et_pb_image src=&quot;https://example.com/a.jpg&quot; align=&quot;center&quot; /]',
        'expect' => [ '<!-- wp:image', 'https://example.com/a.jpg', 'aligncenter' ],
        'reject' => [ '&quot;', 'et_pb_image' ],
    ],

    'an entity-quoted button keeps its link and its label' => [
        'divi'   => '[et_pb_button button_url=&quot;https://example.com/donate&quot; button_text=&quot;Donate Now&quot; /]',
        'expect' => [ 'href="https://example.com/donate"', '>Donate Now<' ],
        'reject' => [ 'href="#"' ],
    ],

    'an entity-quoted column keeps its width' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type=&quot;1_3&quot;][et_pb_text]<p>x</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<!-- wp:column {"width":"33.33%"} -->' ],
    ],

    // The encoding stops partway through this tag, which is exactly how real
    // exports store it: `description` opens with &quot; and closes with a
    // literal quote. A scanner that counts only literal quotes never leaves the
    // value, swallows the closing `]`, and spills the rest of the document into
    // the page as raw shortcode text.
    'a tag that mixes quoted and entity-quoted values still closes' => [
        'divi'   => '[et_pb_section][et_pb_row][et_pb_column type=&quot;4_4&quot;]'
            . '[et_pb_signup title=&quot;Stay Informed&quot; description=&quot;<p>News</p>' . "\n"
            . '" footer_content="<p>Privacy</p>' . "\n"
            . '" background_color="#FFCA08"][/et_pb_signup]'
            . '[et_pb_text]<p>After the signup</p>[/et_pb_text]'
            . '[/et_pb_column][/et_pb_row][/et_pb_section]',
        'expect' => [ '<h3>Stay Informed</h3>', '<p>After the signup</p>' ],
        'reject' => [ 'et_pb_signup', 'mailchimp_list', '&quot;' ],
    ],

    // The narrow risk in accepting &quot; as a delimiter: a value that contains
    // one as content. The delimiter that opened the value decides where it
    // ends, so a literal-quoted value keeps its encoded quotes intact.
    'an encoded quote inside a quoted value stays in the value' => [
        'divi'   => '[et_pb_button button_url="https://example.com/" button_text="He said &quot;hello&quot;" /]',
        'expect' => [ 'He said &quot;hello&quot;' ],
        'reject' => [ 'href="#"' ],
    ],

    // A sub-list written as a sibling of the <li> it belongs under, which is
    // what editors that let authors "indent" a bullet produce. Invalid HTML,
    // rendered by every browser as a sub-list, and dropped outright until now.
    'a sub-list written beside its item is kept under that item' => [
        'divi'   => '[et_pb_text]<ul><li>Coverage</li><ul><li>FOX 43 <a href="https://example.com/fox">report</a></li>'
            . '<li>Radio <a href="https://example.com/radio">segment</a></li></ul><li>Website</li></ul>[/et_pb_text]',
        'expect' => [
            '<li>Coverage',
            'https://example.com/fox',
            'https://example.com/radio',
            '<li>Website</li>',
        ],
    ],

    'a sub-list with no item above it is still kept' => [
        'divi'   => '[et_pb_text]<ul><ul><li>Orphan</li></ul><li>Second</li></ul>[/et_pb_text]',
        'expect' => [ 'Orphan', '<li>Second</li>' ],
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

    // ---------------------------------------------------------------- C-01 --
    //
    // Six structural parents iterated only the child tag they expected and let
    // everything else fall off the end of the loop. One fixture per parent,
    // each one built from the shape the review probed with.

    'C-01 a tabs module keeps loose text and unexpected modules' => [
        'divi'   => '[et_pb_tabs]Before[et_pb_button button_text="Keep" /]After[/et_pb_tabs]',
        'expect' => [ '<p>Before</p>', '<p>After</p>', '>Keep</a>' ],
        'warns'  => [ 'et_pb_tabs' ],
    ],

    'C-01 a counters module keeps loose text and unexpected modules' => [
        'divi'   => '[et_pb_counters]Loose[et_pb_button button_text="Keep" /][et_pb_counter percent="50"]Label[/et_pb_counter][/et_pb_counters]',
        'expect' => [ '<p>Loose</p>', '>Keep</a>', '<strong>Label</strong>: 50%' ],
        'warns'  => [ 'et_pb_counters' ],
    ],

    'C-01 a pricing tables wrapper keeps unexpected children' => [
        'divi'   => '[et_pb_pricing_tables]Intro[et_pb_pricing_table title="Plan" /][/et_pb_pricing_tables]',
        'expect' => [ '<p>Intro</p>', 'Plan' ],
        'warns'  => [ 'et_pb_pricing_tables' ],
    ],

    'C-01 a pricing table keeps text and modules around its items' => [
        'divi'   => "[et_pb_pricing_table title=\"Plan\"]Before\n"
            . '[et_pb_pricing_item]Feature[/et_pb_pricing_item]' . "\n"
            . 'After[et_pb_button button_text="Keep too" /][/et_pb_pricing_table]',
        'expect' => [ '<p>Before</p>', '<li>Feature</li>', '<p>After</p>', '>Keep too</a>' ],
        'warns'  => [ 'et_pb_pricing_table' ],
    ],

    'C-01 social follow keeps content that is not a network' => [
        'divi'   => '[et_pb_social_media_follow]Loose text[et_pb_button button_text="Keep" /]'
            . '[et_pb_social_media_follow_network social_network="facebook" url="https://facebook.com/x"][/et_pb_social_media_follow_network]'
            . '[/et_pb_social_media_follow]',
        'expect' => [ '<p>Loose text</p>', '>Keep</a>', '<!-- wp:social-links' ],
        'warns'  => [ 'et_pb_social_media_follow' ],
    ],

    'C-01 a video slider item without a src attribute is still converted' => [
        'divi'   => '[et_pb_video_slider][et_pb_video_slider_item]https://youtu.be/abc[/et_pb_video_slider_item][/et_pb_video_slider]',
        'expect' => [ '<!-- wp:embed', 'youtu.be/abc' ],
        'reject' => [ '<!-- wp:group -->' . "\n" . '<div class="wp-block-group">' . "\n\n" ],
    ],

    // ---------------------------------------------------------------- C-03 --

    'C-03 a comment terminator in an attribute cannot end the block comment' => [
        'divi'   => '[et_pb_search placeholder="find--><img src=x onerror=alert(1)>" /]',
        'expect' => [ '\\u002d\\u002d', '\\u003c', '\\u003e' ],
        // The literal sequence would close the HTML comment early and leave the
        // <img> as live markup for anything reading post_content as HTML.
        'reject' => [ 'find--><img', '-->' . "\n" ],
    ],

    'C-03 an ampersand in a block attribute is escaped' => [
        'divi'   => '[et_pb_search placeholder="Tom &amp; Jerry" /]',
        'expect' => [ '\\u0026' ],
        'reject' => [ '"Tom & Jerry"' ],
    ],

    'C-03 a javascript URL survives in neither half of a block' => [
        'divi'   => '[et_pb_image src="javascript:alert(1)" /]',
        'reject' => [ 'javascript:' ],
        'warns'  => [ 'et_pb_image' ],
    ],

    'C-03 an unsafe link URL is dropped while the image is kept' => [
        'divi'   => '[et_pb_image src="https://example.com/a.jpg" url="javascript:alert(1)" /]',
        'expect' => [ '"linkDestination":"none"', 'example.com/a.jpg' ],
        'reject' => [ 'javascript:', '"href"' ],
    ],

    'C-03 an unsafe social link is dropped rather than stored' => [
        'divi'   => '[et_pb_social_media_follow_network social_network="facebook" url="javascript:alert(1)"][/et_pb_social_media_follow_network]',
        'reject' => [ 'javascript:', '<!-- wp:social-link' ],
    ],

    'C-03 dynamic modules are serialized by the block builder' => [
        'divi'   => '[et_pb_blog posts_number="3" /][et_pb_menu menu_id="4" /][et_pb_login /][et_pb_post_title /]',
        'expect' => [
            '<!-- wp:latest-posts {"postsToShow":3} /-->',
            '<!-- wp:navigation /-->',
            '<!-- wp:loginout /-->',
            '<!-- wp:post-title /-->',
        ],
    ],

    'deeply nested source does not blow up' => [
        'divi'   => str_repeat( '[et_pb_section]', 60 ) . '[et_pb_text]Deep[/et_pb_text]' . str_repeat( '[/et_pb_section]', 60 ),
        'expect' => [ 'Deep' ],
    ],
];
