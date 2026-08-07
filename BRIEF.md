# Project Brief — Block Converter for Divi (`block-converter-for-divi`)

**Status:** `2.3.1` — released. Runs against real WordPress
6.1–7.0.2 (`bin/wp-matrix.sh`), on single site and multisite, with output
checked by WordPress's own block validator against the block library **each**
supported release actually ships (`bin/block-library-matrix.sh`) and the admin
screen driven in a browser. `Requires at least` and `Tested up to` are both
measurements now, not estimates. 2.3.0 also fixes the upgrade from the
pre-rename plugin, which had failed with a fatal error since 2.0.0.
**Remaining caveat:** the fixture corpus is still entirely synthetic — every
fixture was written by someone who already knew what the converter does. 2.3.0
is the first version run against real content: about 15 pages from a live Divi
site, converted successfully. Those pages were sections, rows, text and images,
which is the simplest module set and the one already best covered, so it
confirms the common path and leaves the modules where loss actually happens
(tabs, sliders, pricing tables, forms, galleries, code) unexercised on real
content. It is still best described as an assisted migration tool that produces
a first block draft for review. WordPress.org submission is a separate
decision, not yet made.
**Repository:** https://github.com/johnjanney/block-converter-for-divi
**Owner:** John Janney
**License:** GPL-2.0-or-later
**Last brief update:** 2026-08-07

---

## 1. Problem statement

Sites built with the Divi Builder store their page content as deeply nested
`[et_pb_*]` shortcodes in `wp_posts.post_content`. That content is unreadable
without the Divi theme/plugin active, which means:

- Leaving Divi means losing the page — content renders as raw shortcode soup.
- Divi licensing is perpetual-cost and the builder is heavy compared to the
  native block editor.
- Content is not portable to any other theme, editor, or headless front end.

There is no first-party migration path from Divi to Gutenberg. Doing it by hand
is prohibitively slow on sites with dozens or hundreds of pages.

## 2. Goal

A self-contained WordPress admin plugin that scans a site for Divi-built pages
and rewrites their content as **native Gutenberg block markup**, preserving
content, media, links, and as much design intent as the core block set allows —
without requiring Divi to remain installed afterward.

## 3. Scope

### In scope

| Area | Detail |
| --- | --- |
| Detection | Scan `page` and `post` types with status `publish`, `draft`, `private`, `pending` for `[et_pb_` content |
| Parsing | Full recursive shortcode parser producing an attribute + child tree |
| Conversion | Map Divi modules to core Gutenberg blocks (see §5) |
| Style mapping | Translate the small set of Divi design attributes listed in §5.1 to block attributes. Everything else is **detected and reported** as lost (since 2.2.0), not mapped and not silently dropped |
| Preview | Side-by-side original vs. converted diff before committing |
| Single + batch conversion | Convert one page, or a multi-select batch, via AJAX |
| Backup | Snapshot original Divi content into post meta before overwriting |
| Restore | Roll a converted page back to its original Divi content and put the captured builder meta back exactly as it was found |
| Builder detach | Remove `_et_pb_use_builder` so WordPress opens the block editor |
| Packaging | Versioned, installable plugin ZIP per release |

### Out of scope (current phase)

- Divi Theme Builder templates (headers, footers, global layouts)
- Divi Library / global modules (`global_module` references)
- Divi theme options, custom CSS panels, and theme-level styling
- Pixel-perfect visual fidelity — the target is *design intent*, not a pixel diff
- Front-end rendering shims or a companion stylesheet for converted output
- Divi Builder plugin/theme removal or license cleanup
- Multisite network-wide batch operations
- WP-CLI interface

## 4. Architecture

```
block-converter-for-divi.php  Bootstrap, constants, admin menu, 5 AJAX endpoints
uninstall.php                 Delete-time cleanup; opt-in backup removal
LICENSE                       GPL-2.0
readme.txt                    WordPress.org listing (not yet submitted)
bin/build-zip.sh              Versioned release packaging
├── includes/
│   ├── load.php                    One dependency-ordered require list, shared
│   │                               with the test suite
│   ├── class-d2g-parser.php        Divi shortcode → node tree
│   ├── class-d2g-converter.php     Orchestration: dispatch, warnings, recursion
│   ├── class-d2g-html-converter.php  Free-form HTML → blocks
│   ├── class-d2g-block-builder.php   Block markup primitives + sanitisers
│   ├── class-d2g-style-mapper.php  The one Divi attr mapped to a block attr
│   └── renderers/
│       ├── class-d2g-module-renderer.php  Abstract base; declares tags()
│       ├── class-d2g-renderer-layout.php       sections, rows, columns
│       ├── class-d2g-renderer-text.php         text, code
│       ├── class-d2g-renderer-media.php        images, video, audio, gallery, maps
│       ├── class-d2g-renderer-content.php      buttons, blurbs, CTAs, headers…
│       ├── class-d2g-renderer-interactive.php  toggles, tabs, sliders, counters
│       ├── class-d2g-renderer-pricing.php      pricing tables
│       └── class-d2g-renderer-dynamic.php      loops, menus, forms, search
├── admin/
│   ├── class-d2g-admin.php   Tools › Block Converter for Divi screen markup
│   ├── js/admin.js           Scan, paginate, preview, convert (jQuery + AJAX)
│   └── css/admin.css         Admin screen + preview modal styling
└── tests/                    See tests/README.md
```

Until 2.2.0 everything from `class-d2g-converter.php` down was a single
2,638-line class: every renderer, a 170-line dispatch switch, the HTML engine
and the markup primitives. The split was deliberately made *after* the test
suite could prove it changed nothing — 139 byte-exact golden snapshots, every
module exercised, and every block validated by WordPress itself. Doing it
earlier would have been a rewrite with no way to check it.

The `D2G_` / `d2g_` prefixes and the `class-d2g-*.php` filenames predate the
rename and were kept: the storage keys derived from them hold live backup data
on existing installs (see `OPENQUESTIONS.md` Q17).

**Data flow:**
`post_content` → `D2G_Parser::parse()` → node tree (`tag` / `attrs` / `content` /
`children`) → `D2G_Converter::convert()` → recursive per-module renderers →
Gutenberg block comment markup → `wp_update_post()`.

**Key classes**

- **`D2G_Parser`** — regex-driven tokenizer matching the *shape*
  `et_pb_[a-z0-9_]+`, so a module with no renderer is still tokenized rather
  than left on the page as shortcode text; `is_known_tag()` distinguishes the
  two. Handles nesting via depth counting (bounded at `MAX_DEPTH`),
  self-closing tags, unmatched closers, smart-quote entity normalization, and
  wraps loose text in synthetic `__text__` nodes. Static helper
  `has_divi_content()` requires a syntactically complete tag, not a prefix.
- **`D2G_Style_Mapper`** — `text_align_class()`, and nothing else. It used to
  hold a whole unreached style layer; 2.3.0 deleted it, because dead is not the
  same as harmless — it built CSS by concatenating raw Divi values and appended
  `custom_css_main_element` verbatim, which is the injection class that had to
  be fixed in the live renderers. See §5.1, and Q22.
- **`D2G_Converter`** — the orchestrator. Walks the node tree, dispatches each
  node to whichever renderer claims its tag, collects the warnings shown in
  Preview, and provides the services renderers call back into (recursion, the
  HTML engine, reading a node's own content). Unclaimed tags fall through to a
  path that preserves their content and reports them.
- **`D2G_Module_Renderer`** and its seven subclasses — one family of modules
  each. A renderer declares `tags()` as a `tag => method` map, and the converter
  builds its dispatch table by asking the renderers, so a tag cannot be routed
  to a method that does not exist and two renderers cannot silently claim the
  same tag. The whole table is snapshotted in `tests/golden/dispatch-table.txt`.
- **`D2G_HTML_Converter`** — free-form HTML → blocks. The one part of the
  conversion that knows nothing about Divi: block-level elements each become
  their own block, and runs of text and inline elements between them become a
  paragraph.
- **`D2G_Block_Builder`** — static markup primitives (`block()`, `paragraph()`,
  `cover()`) and the sanitisers every renderer needs (`allowed_value()`,
  `css_color()`, `text()`, `attr()`). The sanitisers live here because when each
  renderer answered "what is safe inside an attribute" for itself, the answers
  disagreed and two injection paths resulted.

**Endpoints** (all nonce-checked `d2g_nonce`, all gated on `manage_options`):

| Action | Purpose |
| --- | --- |
| `d2g_scan_pages` | Filtered, sorted, paginated list of posts/pages that contain Divi shortcodes **or** hold a backup |
| `d2g_preview_conversion` | Convert in memory, return original + converted |
| `d2g_convert_page` | Backup (optional), convert, save, detach builder meta |
| `d2g_restore_page` | Restore `post_content` from `_d2g_divi_backup`, re-enable the Divi Builder |
| `d2g_save_settings` | Persist the opt-in "delete backups on uninstall" preference |

The scan deliberately matches on *"contains `[et_pb_` **or** has a backup"*. A
converted page no longer contains Divi markup, so a content-only match would
drop it from the listing and make its backup unreachable from the UI. Sort
column, sort direction, post type, and page size are all whitelisted
server-side; the `ORDER BY` clause is assembled only from a fixed column map.

## 5. Module coverage

**Direct block mappings**

| Divi module | Gutenberg output |
| --- | --- |
| `et_pb_section` | `core/group` |
| `et_pb_row`, `et_pb_row_inner` | `core/columns`. Every row holding at least one column is wrapped, single-column rows included — a `core/column` outside a `core/columns` is not valid. A row with no column children passes its content through unwrapped |
| `et_pb_column`, `et_pb_column_inner` | `core/column` with derived `%` width |
| `et_pb_text` | One block per top-level element — `core/paragraph`, `core/heading`, `core/list`, `core/quote`, `core/table`, `core/preformatted`, `core/embed`, or `core/html` as a fallback |
| `et_pb_image`, `et_pb_fullwidth_image` | `core/image` |
| `et_pb_button` | `core/buttons` › `core/button` |
| `et_pb_video` | `core/embed` (YouTube/Vimeo) or `core/video` |
| `et_pb_blurb` | `core/group` (image + heading + paragraph) |
| `et_pb_cta` | `core/group` (heading + paragraph + button) |
| `et_pb_divider` | `core/separator` |
| `et_pb_fullwidth_header` | `core/cover` (with bg image) or `core/group` |
| `et_pb_gallery` | `core/gallery` › `core/image` |
| `et_pb_accordion` / `et_pb_toggle` | `core/group` › `core/details`, whose body opens with an HTML `<summary>` element. There is no `core/summary` block; `<summary>` is markup inside `core/details` |
| `et_pb_tabs` / `et_pb_tab` | `core/group` › `core/group` |
| `et_pb_slider` + fullwidth/post variants, `et_pb_slide` | `core/group` › `core/cover` |
| `et_pb_testimonial` | `core/group` › `core/image` + `core/quote` |
| `et_pb_team_member` | `core/group` + `core/social-links` |
| `et_pb_pricing_tables` / `et_pb_pricing_table` | `core/columns` › `core/column` |
| `et_pb_counters` / `et_pb_counter` | `core/group` › `core/paragraph` |
| `et_pb_number_counter`, `et_pb_circle_counter` | `core/group` (heading + paragraph) |
| `et_pb_social_media_follow` (+ `_network`) | `core/social-links` › `core/social-link` |
| `et_pb_code`, `et_pb_fullwidth_code` | `core/html` |
| `et_pb_contact_form` (+ `et_pb_contact_field`) | `core/group` (heading + field description list). **Not** `core/form` — that block is experimental and unregistered in WordPress core |
| `et_pb_audio` | `core/audio`, wrapped in `core/group` when titled |
| `et_pb_blog` | `core/latest-posts` |
| `et_pb_login` | `core/loginout` |
| `et_pb_post_title` | `core/post-title` |
| `et_pb_menu`, `et_pb_fullwidth_menu` | `core/navigation` |
| `et_pb_comments` | `core/comments` |
| `et_pb_search` | `core/search` |
| `et_pb_portfolio` + filterable/fullwidth variants | `core/query` › `core/post-template` |
| `et_pb_video_slider` (+ `_item`) | `core/group` › `core/video` / `core/embed` |

**Lossy / placeholder mappings** (content preserved as text, needs manual rebuild)

| Divi module | Output |
| --- | --- |
| `et_pb_map`, `et_pb_fullwidth_map` | `core/group` › `core/html` with address / lat-lng / pin titles as text |
| `et_pb_sidebar` | `core/paragraph` placeholder |
| `et_pb_signup` | `core/group` with placeholder paragraph |
| `et_pb_shop` | `core/paragraph` placeholder — replace with WooCommerce blocks |

**Fallback** — the tokenizer recognises the *shape* `et_pb_[a-z0-9_]+`, not just
the tags with renderers, so a module from a newer Divi release or a third party
is still tokenized rather than left on the page as shortcode text. An
unrecognised tag renders its children recursively, wraps content-without-children
in `core/html`, and records a conversion warning naming the tag.

### 5.1 Style coverage

The style mapper is only partly wired into conversion. What conversion actually
preserves:

| Divi attribute | Where it lands |
| --- | --- |
| `text_orientation` | `has-text-align-*` class **and** the matching `textAlign` / `align` block attribute on headings and paragraphs |
| `background_color` (section, CTA) | `core/group` `style.color.background` + `has-background` |
| `button_bg_color`, `button_text_color` | `core/button` `style.color.*` |
| `type` (column) | `core/column` `width` |
| `color` (divider) | `core/separator` `style.color.background` |
| `background_image` (header, slide) | `core/cover` `url` |
| `header_level` (blurb) | heading `level` |

What is **not** carried over: spacing (padding and margin), explicit sizing
(`max_width`, `min_height`), borders and `border_radii`, box and text shadows,
fonts, font sizes, line heights, letter spacing, background gradients and
parallax, `custom_css_main_element`, module IDs and classes, hover states,
animations and filters, positioning and transforms, per-device visibility
(`disabled_on`), and per-breakpoint `_tablet` / `_phone` overrides.

Since 2.2.0 each of these **is** detected and reported. `D2G_Converter` matches
every module's attributes against a registry of unmapped-setting patterns and
raises one warning per module tag naming the categories that were lost; the
interactive modules (tabs, sliders, video sliders, accordions, counters) raise a
second warning describing the behaviour that did not survive. Both appear in the
Preview panel and in the response the batch runner reports.

Before 2.2.0 nothing detected any of it. The claim on this page that losses were
"reported rather than silently dropped" was false for every setting listed
above — a Section with `custom_padding` lost its padding and produced no
warning at all.

`D2G_Style_Mapper::build_inline_style()`, `wrapper_style()`, `get_color_attrs()`,
and `parse_font()` are retained but **not called by the converter**. Wiring them
in naively is what would break block validation: WordPress regenerates a static
block's markup from its attributes and compares it byte for byte, and an inline
`style` attribute the block's own save function would not have produced is
exactly the mismatch that shows "unexpected or invalid content". Connecting them
properly means emitting block-supported `style` attributes and reproducing the
style engine's own serialization — a real piece of work, tracked as Q22, not a
one-line change.

## 6. Requirements

| | |
| --- | --- |
| WordPress | **6.1+, measured; tested through 7.0.2.** The live suite passes on 6.1, 6.2, 6.3, 6.8 and 7.0.2, and fails on 6.0.<br>**6.1+, measured** by running the live suite against 6.0 and 6.1 (`bin/wp-matrix.sh`). 6.0 does not register `core/list-item` or `core/comments`, so every converted list broke there. The previous `6.0` was *derived* from looking up when each emitted block arrived, and the derivation was wrong on both counts — `core/comments` is 6.1, and `core/list-item` was not in the derivation at all. `core/details` (6.3) is still feature-detected and degrades to a heading + text on 6.1–6.2 |
| PHP | 7.4+ |
| Capability | `manage_options`, plus `edit_post` on each target post |
| Dependencies | jQuery (bundled with WP). `DOMDocument` (`ext-dom`) recommended — without it rich text falls back to `core/html`. No `mbstring` requirement |
| Divi | Not required at conversion time — the parser reads raw shortcodes |

## 7. Known gaps / risk register

1. ~~**The admin screen has never been opened in a browser.**~~ Closed in
   2.2.0. `bash bin/e2e.sh` drives the Tools screen in Chromium: scan, preview,
   single convert, restore, batch, settings, filters, sorting, dialog keyboard
   behaviour, and console errors on load. Nine tests, and they found two real
   defects nothing else could see. See `OPENQUESTIONS.md` Q30. Still not
   covered: pagination, per-page sizes, select-all, and batches larger than
   three.

   The narrower gap this entry used to describe alongside it — block *validity*
   proven against only one block library — is also closed.
   `bash bin/block-library-matrix.sh` validates every fixture against the
   `@wordpress/block-library` that each of WordPress 6.1, 6.2, 6.3, 6.4, 6.5,
   6.6, 6.7, 6.8 and 7.0.2 actually ships. It found two real defects on its
   first run: converted Cover blocks were invalid on 6.1–6.7 (core swapped the
   order of the two background elements in 6.8) and converted Toggles were
   invalid on 6.3 (the summary was a block attribute there). Both fixed. Two
   fixtures remain known-invalid on 6.1 alone and are recorded, with reasons,
   in `tests/js/versions.json`. See Q31.

2. ~~**Restore depends on the backup being taken.**~~ The backup is no longer
   optional. It was a checkbox, and clearing it still overwrote `post_content`
   and still deleted the Divi builder meta, leaving a conversion nothing could
   undo. The server now snapshots unconditionally; `write_backup()` keeps the
   first snapshot and never overwrites it, so this cannot damage an existing
   backup. See Q32.

3. ~~**kses filtering on multisite.**~~ Measured and handled in 2.2.0. On a
   network, a site administrator's writes go through KSES, and a Divi Code
   module loses its scripts and iframes to it. Conversion and restore now detect
   that and refuse, naming what would be removed, rather than writing damaged
   content and reporting success. See `OPENQUESTIONS.md` Q15.
4. **Style fidelity is partial** and mostly unmapped — see §5.1 for the exact
   matrix. Spacing, borders, shadows, fonts, and module custom CSS are lost.
   Since 2.2.0 those losses are detected and named in the preview rather than
   being silent, but detection is not the same as mapping: the settings still
   have to be rebuilt by hand.
5. ~~**Attribute values containing `]` can truncate a tag.**~~ Fixed in 2.2.0.
   The tokenizer is quote-aware: it scans from `[` to the closing `]` treating
   quoted values as opaque, so `title="Array[0]"` parses correctly.
6. **Gallery carousels** emit a `d2g-gallery-slider` class with no shipped CSS or
   JS to make it behave as a carousel.
7. **The scan does not scale.** It uses a leading-wildcard `LIKE` over
   `post_content`, which cannot use an index. "All" is capped at 500 rows
   (`d2g_scan_hard_cap`) so it cannot exhaust memory, but a large site still
   costs a full table scan per query. See `OPENQUESTIONS.md` Q11.
8. **Portfolio conversion depends on Divi's `project` post type**, which stops
   existing when Divi is removed. The converted Query Loop is warned about but
   still points at it.

### Closed in 2.1.0

- Backslash stripping on backup, convert, and restore (`wp_slash`).
- A repeat conversion overwriting the original backup.
- Backup covering `post_content` only — Divi builder meta is now snapshotted and
  restored.
- Missing object-level capability and post type/status checks.
- Text modules producing one paragraph block containing many paragraphs.
- Alignment, toggle, list, quote, and table markup disagreeing with block
  attributes.
- `[et_pb_pricing_item]` and unrecognised modules left as raw shortcode text.
- Experimental `core/form*` output and the invented `core/navigation` `menuId`.
- `mbstring` dependency and the PHP 8.2 `HTML-ENTITIES` deprecation.
- Batch conversion reporting failures as successes.
- No automated tests — `tests/run.php` now gates `bin/build-zip.sh`.

### Closed in 2.2.0

- HTML attribute injection through `align`, `button_alignment`, and colour
  values. Layout values are now reduced to allowlisted tokens, colours are
  validated against the CSS colour grammars, and class attributes are escaped.
- Fullwidth Header producing a paragraph block containing nested `<p>` elements.
- Child modules nested inside a Text, Blurb, CTA, Toggle, Tab, Slide,
  Testimonial, Team Member, or Pricing Table module being dropped entirely.
- `<caption>` and `<colgroup>` silently deleted when a table became `core/table`.
- HTML comments dropped by the DOM walk.
- A bar counter's HTML body escaped and published as visible markup.
- `]` inside a quoted shortcode attribute truncating the tag.
- Curly-quote entities in body text rewritten to straight quotes.
- Unmapped design settings and lost interactive behaviour going unreported.
- A check-then-set write lock that two concurrent requests could both pass, and
  a conversion that wrote without any source token in the two commonest paths.
- Builder meta snapshots that could not distinguish an absent key from an empty
  one, and a restore that never cleared the managed keys first.
- Block assertions that only inspected top-level blocks.
- Parser, converter, style mapper, and admin class loaded on every front-end
  request.
- Every converted Cover block being invalid, plus coloured Dividers and the
  Comments block — found by running WordPress's own block validator, which the
  suite could not do before 2.2.0.
- 33 of 58 supported modules having no test at all.

## 8. Roadmap

**Phase 1 — Release hygiene** *(largely delivered)*
- ~~Adopt semantic versioning; bump `D2G_VERSION` + plugin header together~~ — done
- ~~Build and retain versioned ZIPs, tag each release~~ — done; `bin/build-zip.sh`
  reads the version from the plugin header and refuses to overwrite an archive
- ~~Add `uninstall.php` and `LICENSE`~~ — done
- ~~Populate `CHANGELOG.md`~~ — done
- ~~`readme.txt` in WordPress.org format~~ — written; submission blocked on the
  name/slug trademark question (Q17), a real `Tested up to` value (Q18), and
  screenshots plus a WP.org username (Q19)

**Phase 2 — Safety** *(delivered in 1.1.0 and 2.1.0)*
- ~~Restore-from-backup UI and endpoint~~ — done in 1.1.0
- ~~Guard against double-converting a page~~ — 1.1.0 in the UI, 2.1.0 on the
  server, which is where it had to be
- ~~Extend the backup to cover `_et_pb_*` meta, not just `post_content`~~ — done
  in 2.1.0 (Q16 resolved)
- ~~Conversion report of lossy modules per page~~ — done in 2.1.0; shown in the
  preview
- Resolve kses stripping for non-`unfiltered_html` users (Q15)
- Dry-run / preview-all mode across a whole batch

**Phase 3 — UI completion** *(delivered in 1.1.0 and 2.1.0)*
- ~~Wire up the filter, sort, and per-page controls~~ — done in 1.1.0
- ~~Conversion warnings for placeholder modules~~ — done in 2.1.0 in the preview
- Surface those warnings per row in the results table too

**Phase 4 — Coverage**
- Divi Library / global module resolution
- Real map, signup, and shop block targets
- Optional companion stylesheet for carousel and layout classes

**Phase 5 — Quality** *(partly delivered in 2.1.0)*
- ~~Fixture-based unit tests: known Divi input → expected block markup~~ — done
  in 2.1.0; `tests/run.php`, gating the build script
- ~~Static block-consistency assertions (markup vs. attributes)~~ — done in 2.1.0
- Run the canonical WordPress **JavaScript** block parser and validator against
  every fixture, on each supported WordPress version — the remaining half of the
  validity gate, and what `Tested up to` is blocked on (Q23)
- CI matrix over supported WordPress and PHP versions
- WP-CLI command for large-site batch migration

## 9. Success criteria

- A Divi page converts to block markup that opens in the block editor with **no
  block validation errors**.
- No loss of text, images, links, or embedded media.
- Layout structure (sections → rows → columns) survives as group/columns nesting.
- Lossy modules are clearly identified rather than silently dropped.
- Every released version ships as a retained, version-tagged ZIP.

## 10. Related documents

- [`CHANGELOG.md`](CHANGELOG.md) — versioning policy and release history
- [`OPENQUESTIONS.md`](OPENQUESTIONS.md) — unresolved decisions
- [`INSTRUCTIONS.md`](INSTRUCTIONS.md) — install and usage guide
