=== Block Converter for Divi ===
Contributors: johnjanney
Tags: divi, gutenberg, block editor, migration, converter
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
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
* Blurbs, testimonials, team members, and pricing tables
* Accordions, toggles, and tabs
* Sliders and fullwidth headers (as cover blocks)
* Dividers, code, audio, and social media follow
* Blog, portfolio, search, comments, login, navigation, and post title
* Contact forms

Anything the converter does not recognise is preserved rather than dropped.

= What needs manual work =

A few modules have no close equivalent in core blocks and convert to clearly
labelled placeholders for you to rebuild:

* Maps — the address and coordinates are preserved as text
* Sidebars
* Email opt-in forms
* WooCommerce shop modules

Divi Theme Builder templates (headers, footers, global layouts) and Divi Library
global modules are not converted. Hover states, animations, and per-breakpoint
responsive spacing are not carried over.

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
scanned.

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

= 1.2.0 =
Adds a licence file and an uninstall handler. Deleting the plugin still keeps
your backups unless you opt in to removing them. Safe in-place upgrade — no
conversion behaviour changed.

= 1.1.0 =
Adds a Restore button so conversions can be undone, and makes the scan filter
and sort controls work. Recommended for anyone who has converted pages.
