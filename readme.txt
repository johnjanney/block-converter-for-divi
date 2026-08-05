=== Block Converter for Divi ===
Contributors: johnjanney
Tags: divi, gutenberg, block editor, migration, converter
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert pages built with the Divi Builder into native Gutenberg blocks, with preview, batch conversion, and one-click restore.

== Description ==

Sites built with the Divi Builder store their page content as nested `[et_pb_*]`
shortcodes. That content is unreadable without Divi installed, which makes
leaving the builder difficult: deactivate it and your pages render as raw
shortcode text.

This plugin scans your site for Divi-built pages and rewrites their content as
native Gutenberg block markup, preserving text, images, links, embedded media,
and as much design intent as the core block set allows. Divi does not need to
stay installed afterwards.

= How it works =

1. Scan your site for pages and posts containing Divi shortcodes.
2. Preview the conversion — original Divi markup and the resulting blocks side
   by side — without saving anything.
3. Convert a single page, or select many and convert them as a batch.
4. Restore any page to its original Divi content if you are not happy with the
   result.

= What gets converted =

Sections become groups, rows and columns become columns blocks, and modules map
to their closest core block:

* Text, headings, and rich HTML
* Images, galleries, and fullwidth images
* Buttons and calls to action
* Video, video sliders, and YouTube/Vimeo embeds
* Blurbs, testimonials, team members, and pricing tables (features become lists)
* Accordions, toggles, and tabs
* Sliders and fullwidth headers (as cover blocks)
* Dividers, code, audio, and social media follow
* Blog, portfolio, search, comments, login, navigation, and post title

Anything the converter does not recognise is preserved rather than dropped, and
reported in the preview so you know it needs attention.

= What needs manual work =

A few modules have no close equivalent in core blocks and convert to clearly
labelled placeholders for you to rebuild:

* Contact forms — WordPress has no stable core form block, so the fields, their
  types, and the recipient address are written out as text for you to rebuild
  with a form plugin
* Maps — the address and coordinates are preserved as text
* Sidebars
* Email opt-in forms
* WooCommerce shop modules
* Menus — these become a Navigation block that you point at your menu with one
  click from the block toolbar
* Portfolios — these become a Query Loop over Divi's `project` post type, which
  stops existing once Divi is removed

Divi Theme Builder templates (headers, footers, global layouts) and Divi Library
global modules are not converted.

= What styling carries over =

Design *intent*, not a pixel copy. Specifically, these are preserved: text
alignment, section and call-to-action background colours, button background and
text colours, column widths, divider colours, and heading levels.

These are **not** preserved: padding and margin, max-width and min-height,
borders and border radii, box and text shadows, fonts, font sizes, line heights,
letter spacing, background gradients and parallax, module-level custom CSS,
module IDs and classes, hover states, animations and filters, positioning and
transforms, per-device visibility, and per-breakpoint responsive overrides. Divi
renders most of these from its own stylesheet, which stops applying when you
leave the theme, so budget time for theme-side styling after migrating.

The preview tells you which of these a page actually uses, module by module, so
you know what needs rebuilding before you commit to converting. Interactive
modules also report the behaviour that does not survive: tabs become stacked
sections with every panel visible, sliders and video sliders show all their
slides at once, accordions become independent Details blocks that no longer
close each other, and counters become static text.

= Before you convert =

**Back up your database.** Conversion overwrites post content. It can be undone
with the Restore button, but only if the backup checkbox was ticked at
conversion time. Test on a staging copy of your site first, and convert one page
and check the result before running a batch.

== Installation ==

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, or
   extract it to `wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Tools → Block Converter for Divi**.

You need administrator access (the `manage_options` capability). Divi itself
does not need to be active for conversion to work, but leave it installed until
you are satisfied with the results — unconverted pages need it to render.

The plugin runs on PHP 7.4 and above, but 7.4 and 8.1 have both reached end of
life and no longer receive security fixes. If your host offers a currently
supported PHP branch, migrate to it — that is worth doing for the site as a
whole, not just for this plugin.

== Frequently Asked Questions ==

= Does Divi need to be installed? =

Not for the conversion itself — the plugin reads the raw shortcodes from the
database. Keep Divi installed until every page you care about is converted and
checked, though, because any page that has not been converted yet will render as
raw shortcode text without it.

= Can I undo a conversion? =

Yes, if you left the backup checkbox ticked. Converted pages keep a copy of
their original Divi content and show a **Restore** button in the scan results,
which puts the original content back and re-enables the Divi Builder for that
page. Without a backup you would need a WordPress revision or a database
restore.

= Will my pages look identical afterwards? =

No. The goal is to preserve content and design intent, not to produce a
pixel-perfect copy. Divi's visual styling comes largely from its own stylesheet,
which stops applying once you switch themes. Expect to spend time on theme-side
styling after migrating.

= Which post types are scanned? =

Pages and posts. Custom post types, including Divi Projects, are not currently
scanned. Conversion also refuses any post you do not have permission to edit.

= What happens to my backups if I delete the plugin? =

They are kept. Backups are the only way to restore a converted page, so deleting
the plugin does not remove them. If you want them cleared, tick **Delete all
Divi backups when this plugin is deleted** under *Data retention* on the tools
screen before deleting the plugin.

= A converted page shows "This block contains unexpected or invalid content" =

Use **Attempt Block Recovery** on the affected block. If that does not help,
please report it with the original Divi shortcode — conversion output bugs are
the most useful thing to hear about.

== Screenshots ==

1. The scan results, showing Divi pages with preview, convert, and restore
   actions.
2. The conversion preview, with the original Divi markup and the resulting
   Gutenberg blocks side by side.
3. The data retention setting controlling whether backups survive deletion.

== Changelog ==

= 2.2.0 =
* Security: fixed shortcode attribute values being able to inject HTML
  attributes into converted markup. A crafted image or button alignment could
  close the class attribute it was written into and add an event handler.
  Alignment values are now restricted to the ones the blocks actually support.
* Security: fixed colour values being able to inject extra CSS declarations.
  Colours are now validated against the CSS colour formats.
* Fixed Fullwidth Header packing its whole body into one paragraph block,
  producing nested paragraphs that the editor reports as invalid.
* Fixed modules nested inside another module — a button inside a text module,
  for example — being dropped from the converted page entirely.
* Fixed table captions being deleted. Tables that the Table block cannot hold
  whole are now preserved as Custom HTML instead.
* Fixed HTML comments being deleted.
* Fixed a bar counter's body being published as visible escaped markup.
* Fixed a square bracket inside a shortcode attribute value truncating the tag
  and leaving stray characters on the page.
* Fixed curly quotation marks in your text being rewritten as straight quotes.
* Fixed ampersands and other special characters in headings, button labels and
  image alt text being published as `&amp;` instead of `&`.
* Added reporting for design settings that cannot be carried over — spacing,
  borders, shadows, fonts, animations, custom CSS, per-device visibility and
  more — and for interactive behaviour lost by tabs, sliders and accordions.
  The preview now names what will need rebuilding by hand.
* Conversion now refuses to run unless it can confirm which version of the page
  it is converting, so it cannot overwrite an edit made after you scanned.
* Two simultaneous conversions of the same page can no longer both proceed.
* Restore now puts Divi's builder settings back exactly as they were found.
* Fixed every converted Cover block (section and slide backgrounds) being
  reported by the editor as containing invalid content.
* Fixed coloured Dividers and Comments blocks being reported as invalid.
* The plugin no longer loads any of its conversion code on front-end requests.

= 2.1.0 =
* Fixed backslashes being stripped from content on backup, conversion, and
  restore. Code samples, regular expressions, and JSON in a page are now
  preserved exactly.
* Fixed a repeated conversion being able to overwrite a page's backup with
  already-converted content, destroying the only way back. The original snapshot
  is now written once and never replaced, and the server refuses to convert a
  page that holds no Divi content.
* The backup now captures Divi's builder meta as well as the content, so Restore
  returns the full builder state rather than just the text.
* Conversion and restore now require permission to edit that specific page, and
  refuse revisions, autosaves, and unsupported post types.
* Fixed text modules producing invalid blocks. Each paragraph, heading, list,
  quote, and table now becomes its own block instead of being packed into one
  paragraph block.
* Fixed alignment, open toggles, lists, quotes, and tables producing markup that
  disagreed with their block attributes and tripped "this block contains
  unexpected or invalid content".
* Fixed pricing table features being left on the page as raw
  `[et_pb_pricing_item]` shortcode text. They are now list items.
* Fixed unrecognised Divi modules being left on the page as raw shortcode text.
* Contact forms no longer convert to experimental blocks that a normal
  WordPress install cannot render. The form's fields and recipient are preserved
  as text to rebuild with a form plugin.
* Fixed menu modules producing an empty Navigation block from an attribute that
  does not exist.
* The preview now lists every module that needs manual attention.
* Batch conversion now reports successes and failures separately, instead of
  counting a failed page as converted.
* Removed a dependency on the `mbstring` extension, and a PHP 8.2 deprecation.
* Accessibility: the preview is a proper dialog with Escape and focus handling,
  sortable headers and pagination are real buttons, and status messages announce
  themselves.
* All interface text is now translatable.
* Raised the minimum WordPress version to 6.0, which is what the blocks this
  plugin emits actually require.
* Added a fixture test suite (`php tests/run.php`).

= 2.0.0 =
* Renamed the plugin to "Block Converter for Divi". It was previously "Divi to
  Gutenberg Converter".
* The admin screen moved to **Tools → Block Converter for Divi**. Old bookmarks
  to the previous screen will not resolve.
* Upgrading from 1.x is not automatic: the plugin folder name changed, so
  WordPress treats this as a separate plugin. Deactivate and delete the old one,
  then install this. Your backups are stored against your posts, not in the
  plugin, so they survive and remain restorable.
* No change to how pages are converted.

= 1.2.0 =
* Added the GPL-2.0 licence text.
* Added an uninstall handler that runs when the plugin is deleted. It is
  multisite-aware.
* Added a "Delete all Divi backups when this plugin is deleted" setting, off by
  default. Backups are kept unless you switch it on.

= 1.1.0 =
* Added a Restore button that returns a converted page to its original Divi
  content and re-enables the Divi Builder.
* Added a Backup column showing which pages can be rolled back.
* The scan filter, sort, and per-page controls now work, and apply across the
  whole result set rather than the visible page.
* Sortable column headers for Title, Type, Status, and Date.
* Already-converted pages remain listed, with conversion disabled, so they
  cannot be converted twice and their backups stay reachable.
* Fixed scan results failing to render.
* Fixed invalid image and gallery conversion output.
* Fixed gallery grid and carousel formats not being preserved.
* Fixed invalid block nesting from single-column rows.
* Fixed block validation errors in the editor.
* Fixed embedded video and gallery media being lost.

= 1.0.0 =
* Initial release: Divi shortcode parser, module-to-block converter, style
  mapper, and an admin screen for scanning, previewing, and converting pages
  singly or in batches.

== Upgrade Notice ==

= 2.2.0 =
Security and content-integrity release. Upgrade before converting anything.
Crafted Divi content could previously inject an HTML attribute into a converted
page, and several kinds of content — nested modules, table captions, HTML
comments, counter labels — were being dropped silently. Pages already converted
with 2.1.0 are not changed by upgrading; use Restore and convert again to pick
up the fixes.

= 2.1.0 =
Important correctness and data-safety fixes. Converting twice can no longer
destroy a page's backup, backslashes are no longer stripped from content, and
common pages no longer produce invalid blocks. Requires WordPress 6.0 or later.
Pages converted with an earlier version are unaffected; re-run Restore and
convert again to pick up the output fixes.

= 2.0.0 =
The plugin has been renamed. Upgrading is not automatic — deactivate and delete
"Divi to Gutenberg Converter", then install this. Your backups survive and stay
restorable. Conversion behaviour is unchanged.

= 1.2.0 =
Adds a licence file and an uninstall handler. Deleting the plugin still keeps
your backups unless you opt in to removing them. Safe in-place upgrade — no
conversion behaviour changed.

= 1.1.0 =
Adds a Restore button so conversions can be undone, and makes the scan filter
and sort controls work. Recommended for anyone who has converted pages.
