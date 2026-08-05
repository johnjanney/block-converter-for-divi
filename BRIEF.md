# Project Brief — Block Converter for Divi (`block-converter-for-divi`)

**Status:** `2.1.0` built and tagged locally. **Not published** — the
repository and release URLs below 404 (see `OPENQUESTIONS.md` Q21), and
`Tested up to` is still a placeholder rather than a test result (Q18).
**Repository (intended):** https://github.com/johnjanney/block-converter-for-divi
**Owner:** John Janney
**License:** GPL-2.0-or-later
**Last brief update:** 2026-08-05

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
| Style mapping | Translate the Divi design attributes listed in §5.1 to block attributes. Everything else is reported as lost, not silently dropped |
| Preview | Side-by-side original vs. converted diff before committing |
| Single + batch conversion | Convert one page, or a multi-select batch, via AJAX |
| Backup | Snapshot original Divi content into post meta before overwriting |
| Restore | Roll a converted page back to its original Divi content and re-enable the builder |
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
│   ├── class-d2g-parser.php        Divi shortcode → node tree
│   ├── class-d2g-converter.php     Node tree → Gutenberg block markup (~1800 LOC)
│   └── class-d2g-style-mapper.php  Divi attrs → CSS / block attributes
└── admin/
    ├── class-d2g-admin.php   Tools › Block Converter for Divi screen markup
    ├── js/admin.js           Scan, paginate, preview, convert (jQuery + AJAX)
    └── css/admin.css         Admin screen + preview modal styling
```

The `D2G_` / `d2g_` prefixes and the `class-d2g-*.php` filenames predate the
rename and were kept: the storage keys derived from them hold live backup data
on existing installs (see `OPENQUESTIONS.md` Q17).

**Data flow:**
`post_content` → `D2G_Parser::parse()` → node tree (`tag` / `attrs` / `content` /
`children`) → `D2G_Converter::convert()` → recursive per-module renderers →
Gutenberg block comment markup → `wp_update_post()`.

**Key classes**

- **`D2G_Parser`** — regex-driven tokenizer over a fixed whitelist of known
  `et_pb_*` tags. Handles nesting via depth counting, self-closing tags,
  unmatched closers, smart-quote entity normalization, and wraps loose text in
  synthetic `__text__` nodes. Static helper `has_divi_content()`.
- **`D2G_Style_Mapper`** — translation of Divi style attributes. Only
  `text_align_class()` is wired into conversion today; see §5.1 for what that
  means in practice and why the rest is not connected.
- **`D2G_Converter`** — one renderer method per module, recursive, with a
  fallback that renders children or wraps raw content in `core/html`.

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
| `et_pb_row`, `et_pb_row_inner` | `core/columns` (or passthrough if single column) |
| `et_pb_column`, `et_pb_column_inner` | `core/column` with derived `%` width |
| `et_pb_text` | `core/paragraph`, `core/html`, or `core/embed` (heuristic) |
| `et_pb_image`, `et_pb_fullwidth_image` | `core/image` |
| `et_pb_button` | `core/buttons` › `core/button` |
| `et_pb_video` | `core/embed` (YouTube/Vimeo) or `core/video` |
| `et_pb_blurb` | `core/group` (image + heading + paragraph) |
| `et_pb_cta` | `core/group` (heading + paragraph + button) |
| `et_pb_divider` | `core/separator` |
| `et_pb_fullwidth_header` | `core/cover` (with bg image) or `core/group` |
| `et_pb_gallery` | `core/gallery` › `core/image` |
| `et_pb_accordion` / `et_pb_toggle` | `core/group` › `core/details` › `core/summary` |
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

What is **not** carried over, and is reported rather than silently dropped:
padding, margin, `max_width`, borders, `border_radii`, box shadows, fonts, font
sizes, line heights, `custom_css_main_element`, hover states, animations, and
per-breakpoint responsive spacing.

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
| WordPress | 6.0+. Derived from the blocks emitted: `core/comments` needs 6.0, `core/navigation` 5.9, `core/query` / `core/post-template` / `core/loginout` 5.8. `core/details` (6.3) is feature-detected and degrades to a heading + text below that |
| PHP | 7.4+ |
| Capability | `manage_options`, plus `edit_post` on each target post |
| Dependencies | jQuery (bundled with WP). `DOMDocument` (`ext-dom`) recommended — without it rich text falls back to `core/html`. No `mbstring` requirement |
| Divi | Not required at conversion time — the parser reads raw shortcodes |

## 7. Known gaps / risk register

1. **No live block-editor validation has been run.** The fixture suite checks
   that a block's saved markup agrees with its own attributes, which is what
   caught every serialization defect fixed in 2.1.0 — but it cannot execute
   core's JavaScript `save()` functions. Whether a converted page opens clean in
   a real editor on a given WordPress version is still unmeasured. This is the
   single largest remaining risk and it gates publication. See `OPENQUESTIONS.md`
   Q18 and Q23.
2. **Restore depends on the backup being taken.** Conversion still overwrites
   `post_content` outright. If the backup checkbox was unticked there is no
   `_d2g_divi_backup`, no Restore button, and recovery falls back to WordPress
   revisions or a database backup.
3. **kses filtering on multisite.** `wp_update_post()` strips markup for users
   without `unfiltered_html`, which on multisite means every non-super-admin.
   Affects both convert and restore. See `OPENQUESTIONS.md` Q15.
4. **Style fidelity is partial** and mostly unmapped — see §5.1 for the exact
   matrix. Spacing, borders, shadows, fonts, and module custom CSS are lost.
   Losses are now reported in the preview rather than being silent.
5. **Attribute values containing `]` can truncate a tag.** The tokenizer stops an
   opening tag at the first unescaped `]`, even inside a quoted value. Divi does
   not normally produce such values, but a hand-edited layout could.
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
