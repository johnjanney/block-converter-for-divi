# Project Brief — Divi to Gutenberg Converter (`divi2gutenberg`)

**Status:** Working prototype at `1.1.0` (not yet tagged or packaged)
**Repository:** https://github.com/johnjanney/divi2gutenberg
**Owner:** John Janney
**License:** GPL-2.0-or-later
**Last brief update:** 2026-08-04

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
| Style mapping | Translate common Divi design attributes to inline CSS / block attributes |
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
divi2gutenberg.php            Bootstrap, constants, admin menu, 3 AJAX endpoints
├── includes/
│   ├── class-d2g-parser.php        Divi shortcode → node tree
│   ├── class-d2g-converter.php     Node tree → Gutenberg block markup (~1800 LOC)
│   └── class-d2g-style-mapper.php  Divi attrs → CSS / block attributes
└── admin/
    ├── class-d2g-admin.php   Tools › Divi to Gutenberg screen markup
    ├── js/admin.js           Scan, paginate, preview, convert (jQuery + AJAX)
    └── css/admin.css         Admin screen + preview modal styling
```

**Data flow:**
`post_content` → `D2G_Parser::parse()` → node tree (`tag` / `attrs` / `content` /
`children`) → `D2G_Converter::convert()` → recursive per-module renderers →
Gutenberg block comment markup → `wp_update_post()`.

**Key classes**

- **`D2G_Parser`** — regex-driven tokenizer over a fixed whitelist of known
  `et_pb_*` tags. Handles nesting via depth counting, self-closing tags,
  unmatched closers, smart-quote entity normalization, and wraps loose text in
  synthetic `__text__` nodes. Static helper `has_divi_content()`.
- **`D2G_Style_Mapper`** — static translation of `background_color`,
  `background_image`, text colors, `text_orientation`, `custom_padding` /
  `custom_margin`, `max_width`, `border_radii`, borders, box shadows, font
  sizes, line heights, `custom_css_main_element`, and Divi font strings into an
  inline style string, a `has-text-align-*` class, or block colour attributes.
- **`D2G_Converter`** — one renderer method per module, recursive, with a
  fallback that renders children or wraps raw content in `core/html`.

**Endpoints** (all nonce-checked `d2g_nonce`, all gated on `manage_options`):

| Action | Purpose |
| --- | --- |
| `d2g_scan_pages` | Filtered, sorted, paginated list of posts/pages that contain Divi shortcodes **or** hold a backup |
| `d2g_preview_conversion` | Convert in memory, return original + converted |
| `d2g_convert_page` | Backup (optional), convert, save, detach builder meta |
| `d2g_restore_page` | Restore `post_content` from `_d2g_divi_backup`, re-enable the Divi Builder |

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
| `et_pb_contact_form` (+ `et_pb_contact_field`) | `core/form` › `core/form-input` + `core/form-submit-button` |
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

**Fallback** — any unrecognised tag renders its children recursively; if it has
content but no children, the raw content is wrapped in `core/html`; otherwise it
emits nothing.

## 6. Requirements

| | |
| --- | --- |
| WordPress | 5.0+ (block editor); `core/details`, `core/form` blocks need 6.3+ / 6.5+ for full fidelity |
| PHP | 7.4+ |
| Capability | `manage_options` |
| Dependencies | jQuery (bundled with WP), `DOMDocument` (`ext-dom`) |
| Divi | Not required at conversion time — the parser reads raw shortcodes |

## 7. Known gaps / risk register

1. **Restore depends on the backup being taken.** Conversion still overwrites
   `post_content` outright. If the backup checkbox was unticked there is no
   `_d2g_divi_backup`, no Restore button, and recovery falls back to WordPress
   revisions or a database backup.
2. **Backup covers content only.** Conversion deletes `_et_pb_use_builder` and
   `_et_pb_old_content`; restore re-adds the former but cannot recover the
   latter, because only `post_content` is snapshotted. See `OPENQUESTIONS.md` Q16.
3. **kses filtering on multisite.** `wp_update_post()` strips markup for users
   without `unfiltered_html`, which on multisite means every non-super-admin.
   Affects both convert and restore. See `OPENQUESTIONS.md` Q15.
4. **Heuristic HTML classification.** `et_pb_text` routing between paragraph /
   HTML / embed relies on regex sniffing; `DOMDocument` parsing of malformed
   Divi HTML is error-suppressed and can degrade.
5. **Style fidelity is partial.** Hover states, animations, responsive
   breakpoints, and Divi's per-device spacing are not mapped.
6. **Gallery carousels** emit a `d2g-gallery-slider` class with no shipped CSS or
   JS to make it behave as a carousel.
7. **No release engineering.** No tags, no built ZIPs, no `readme.txt`, no
   `uninstall.php`, and the version constant has never been bumped despite ~20
   commits of post-1.0.0 fixes.
8. **No automated tests.** All ~3,200 lines are verified manually.

## 8. Roadmap

**Phase 1 — Release hygiene (next)**
- Adopt semantic versioning; bump `D2G_VERSION` + plugin header together
- Build and retain versioned ZIPs (`divi2gutenberg-X.Y.Z.zip`), tag each release
- Add `readme.txt`, `uninstall.php`, `LICENSE`
- Populate `CHANGELOG.md` going forward

**Phase 2 — Safety** *(partly delivered in 1.1.0)*
- ~~Restore-from-backup UI and endpoint~~ — done in 1.1.0
- ~~Guard against double-converting a page~~ — done in 1.1.0 via `has_divi`
- Extend the backup to cover `_et_pb_*` meta, not just `post_content` (Q16)
- Resolve kses stripping for non-`unfiltered_html` users (Q15)
- Dry-run / preview-all mode; conversion report of lossy modules per page

**Phase 3 — UI completion** *(partly delivered in 1.1.0)*
- ~~Wire up the filter, sort, and per-page controls~~ — done in 1.1.0
- Per-row conversion warnings for placeholder modules

**Phase 4 — Coverage**
- Divi Library / global module resolution
- Real map, signup, and shop block targets
- Optional companion stylesheet for carousel and layout classes

**Phase 5 — Quality**
- Fixture-based unit tests: known Divi input → expected block markup
- Block-validity assertion so converted output never trips "invalid content"
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
