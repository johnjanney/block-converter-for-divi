# Changelog

All notable changes to **Block Converter for Divi** are documented in this
file.

---

## Versioning and release policy

This project follows [Semantic Versioning 2.0.0](https://semver.org/) —
`MAJOR.MINOR.PATCH`:

- **MAJOR** — breaking changes: removed features, changed conversion output that
  invalidates previously converted content, raised WordPress/PHP minimums.
- **MINOR** — new backwards-compatible functionality: new Divi module support,
  new admin features, new settings.
- **PATCH** — backwards-compatible bug fixes and internal corrections.

The changelog format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), grouping entries under
`Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, and `Security`.

### Release requirements

Every release **must**:

1. **Bump the version in all three places** — the `Version:` header in
   `block-converter-for-divi.php`, the `D2G_VERSION` constant, and `Stable tag:`
   in `readme.txt`. They must always match: `D2G_VERSION` drives asset
   cache-busting, and WordPress.org serves whatever `Stable tag` names.
   `bin/build-zip.sh` refuses to build if they disagree.
2. **Have a dated section in this file** listing every user-visible change.
3. **Be tagged in git** as `vX.Y.Z` on the release commit.
4. **Produce a distributable plugin ZIP named with its version number** —
   `block-converter-for-divi-X.Y.Z.zip` (for example `block-converter-for-divi-1.2.3.zip`). The ZIP
   must contain a single top-level `block-converter-for-divi/` directory so that it
   installs correctly through the WordPress plugin uploader.
5. **Preserve all previously built ZIPs.** Old version archives are **never
   deleted or overwritten** — they are the rollback path for anyone who needs to
   return to an earlier build. Each new release adds an archive; it does not
   replace one.

### What goes in the ZIP

Ships to users: the plugin code, `LICENSE`, `readme.txt`, `README.md`,
`INSTRUCTIONS.md`, and `CHANGELOG.md`. `LICENSE` is not optional — the GPL
requires the licence text to accompany the distributed work. `readme.txt` must
sit at the plugin root and its `Stable tag:` must match the version being
released, or WordPress.org will serve the wrong version.

Excluded as internal: `BRIEF.md`, `OPENQUESTIONS.md`, `CODEX-REVIEW.md`,
`CODEX-REVIEW-RESPONSE.md`, `tests/`, `.git/`, `dist/`, and `build/`.

### Where ZIPs live

Built archives are kept in `dist/` (git-ignored for the build artefacts
themselves) and attached to the corresponding GitHub release for the tag, which
is the durable, permanent copy. The `dist/` directory accumulates every version
built locally:

```
dist/
  divi2gutenberg-1.0.0.zip          <- pre-rename releases keep their old name
  divi2gutenberg-1.1.0.zip
  divi2gutenberg-1.2.0.zip
  block-converter-for-divi-2.0.0.zip
  ...
```

Archives built before the 2.0.0 rename keep the `divi2gutenberg-` prefix. They
are not renamed retroactively — the filename records what was actually
published under that version.

### Building a release ZIP

From the repository root, use the build script. It reads the version from the
plugin header, checks it against `D2G_VERSION` and the readme's `Stable tag`,
and refuses to overwrite an archive that already exists:

```bash
./bin/build-zip.sh
```

Then tag and publish:

```bash
git tag -a "v${VERSION}" -m "Release ${VERSION}"
git push origin "v${VERSION}"
gh release create "v${VERSION}" "dist/block-converter-for-divi-${VERSION}.zip" \
   --title "v${VERSION}" --notes-file <(sed -n '/## \['"${VERSION}"'\]/,/## \[/p' CHANGELOG.md)
```

---

## [Unreleased]

_Nothing yet._

---

## [2.1.0] — 2026-08-05

Correctness and data-safety release, following an external review of 2.0.0
(`CODEX-REVIEW.md`, answered in `CODEX-REVIEW-RESPONSE.md`).

**Do not publish this version until `Tested up to:` reflects a real test run**
against a live WordPress install — see `OPENQUESTIONS.md` Q18 and Q23.

### Fixed — data safety

- **Backslashes were stripped from content on backup, conversion, and restore.**
  `wp_update_post()` and `update_post_meta()` both unslash what they are given,
  so passing unslashed content removed one level of escaping from every code
  sample, regular expression, JSON string, and escaped quote on the page — and
  the backup was damaged before conversion even started. All three paths now
  pass through `wp_slash()`.
- **A repeated conversion could destroy a page's backup.** The second request
  read the already-converted Gutenberg content and wrote it over
  `_d2g_divi_backup`, leaving no way back to Divi. The browser disabled the
  button after a conversion, but a replayed request, a queued batch, or a second
  tab does not go through the browser. The original snapshot is now written once
  and never replaced, the server refuses to convert a post that holds no Divi
  content, and a per-post lock stops two writes overlapping.
- **The backup covered `post_content` only.** Conversion deletes
  `_et_pb_use_builder` and `_et_pb_old_content`; restore re-added the first and
  could not recover the second. Both are now snapshotted into
  `_d2g_builder_meta` and restored as found.
- **Post actions checked only `manage_options`.** Preview, convert, and restore
  now also require `edit_post` on the specific post, and refuse revisions,
  autosaves, and post types or statuses the scan does not list.
- Conversion can be rejected when the post changed after it was previewed.

### Fixed — conversion output

- **Text modules produced invalid blocks.** A module's whole body was packed
  into one paragraph block after stripping one outer `<p>` pair, so
  `<p>One</p><p>Two</p>` became a single paragraph block containing two
  paragraphs. Every top-level element now becomes its own block, with runs of
  inline content gathered into paragraphs.
- **Alignment classes had no matching block attribute.** A heading or paragraph
  carrying `has-text-align-center` without `textAlign` / `align` fails
  WordPress's validation, which regenerates markup from attributes and compares.
  Affected text modules, pricing tables, and counters.
- **An open toggle wrote `open` without `showContent`** — the same class of
  mismatch on `core/details`.
- Lists now use `core/list-item` inner blocks, quotes hold inner blocks, and
  tables get the `<tbody>` that `core/table` sources its rows from. A table
  carrying attributes core cannot store is preserved verbatim instead.
- **Pricing table features were left on the page as raw
  `[et_pb_pricing_item]` shortcode text.** They are now list items.
- **Unrecognised Divi modules were left on the page as raw shortcode text.** The
  tokenizer now recognises any `et_pb_*` tag, not only the ones with renderers.

### Changed

- **Contact forms no longer convert to `core/form`.** Those blocks are
  experimental — they ship with the Gutenberg plugin and are not registered by
  WordPress core, so on a normal install the converted page showed "block not
  supported" placeholders. The mapping also flattened dropdowns, radios, and
  checkboxes to text inputs and produced nothing that could send mail. The
  fields, their types, and the recipient are now written out as ordinary blocks
  to rebuild with a form plugin, and the module is reported as needing work.
- **Menu modules no longer emit a `menuId` attribute.** `core/navigation` has no
  such attribute — it references a `wp_navigation` post through `ref`, and
  Divi's `menu_id` is a classic menu term ID. The result was a block that parsed
  and resolved to nothing. A bare Navigation block is emitted and the classic
  menu is named in a warning.
- Portfolio conversion reports its dependency on Divi's `project` post type, and
  the post type and taxonomy are filterable (`d2g_portfolio_post_type`,
  `d2g_portfolio_taxonomy`).
- **Minimum WordPress raised to 6.0**, derived from the blocks actually emitted.
  `core/details` (6.3) is feature-detected and degrades to a heading plus text.
- Batch conversion reports successes and failures separately, with a per-page
  error list. It previously counted a failed page as converted.
- The "All" per-page option is capped at 500 rows (`d2g_scan_hard_cap`) and says
  so when it truncates.
- Uninstall walks multisite in batches of 100 rather than loading every site ID.

### Added

- **Conversion warnings.** Every lossy or unmapped module is collected and shown
  in the preview, and returned with the conversion response.
- **A fixture test suite** — `php tests/run.php`. Runs on plain PHP with no
  WordPress install, and gates `bin/build-zip.sh`.
- Filters: `d2g_scan_hard_cap`, `d2g_supported_post_types`,
  `d2g_supported_post_statuses`, `d2g_contact_form_markup`,
  `d2g_portfolio_post_type`, `d2g_portfolio_taxonomy`.

### Removed

- The `mbstring` dependency and the PHP 8.2 `HTML-ENTITIES` deprecation. The DOM
  loader now uses an XML encoding prologue, which also stops multibyte
  characters being rewritten as numeric entities.
- Dead code: an unused regex in the parser, an unused variable and a no-op block
  name map in the converter, and a duplicated pagination section plus selectors
  for controls that do not exist in the stylesheet.

### Security

- Admin status messages are inserted with `.text()` rather than `.html()`. They
  carry post titles and server error strings, neither of which is HTML.

### Accessibility and localization

- The preview is a real dialog: `role="dialog"`, an accessible name, Escape to
  close, a focus trap, and focus returned to the trigger.
- Sortable column headers and pagination controls are real buttons with
  `aria-sort` and labels, reachable from the keyboard.
- The status region announces itself with `aria-live`.
- Every interface string, including AJAX responses, is translatable.

---

## [2.0.0] — 2026-08-04

**The plugin has been renamed. Upgrading from 1.x is not automatic — see below.**

Major only because the distribution identity breaks: the plugin directory name
changed, so WordPress treats this as a different plugin and there is no upgrade
path from 1.2.0. Nothing about conversion behaviour changed, and existing
backups keep working.

### Changed
- **Renamed the plugin to "Block Converter for Divi" (slug
  `block-converter-for-divi`).** WordPress.org does not allow a plugin name or
  slug to lead with someone else's trademark; trailing attribution is the
  accepted form. Covers the plugin name, slug, main file, text domain, admin
  menu slug, and main class.
- The admin screen moved from **Tools → Divi to Gutenberg** to **Tools → Block
  Converter for Divi**. Its URL changed from `tools.php?page=divi2gutenberg` to
  `tools.php?page=block-converter-for-divi`, so old bookmarks will not resolve.

  > **Upgrading is not automatic.** The plugin directory name changed, so
  > WordPress treats this as a different plugin. Deactivate and delete the old
  > "Divi to Gutenberg Converter", then install this one. **Your backups
  > survive** — they are stored as post meta, not plugin files.

### Added
- `readme.txt` in WordPress.org format, with description, installation, FAQ,
  changelog, and upgrade notices. Not yet submitted — see `OPENQUESTIONS.md`
  Q18–Q19 for the remaining blockers.

### Not changed (deliberately)
- The `D2G_` / `d2g_` internal prefixes, class names, AJAX actions, nonce, and
  CSS classes.
- **The storage keys: `_d2g_divi_backup` and `_d2g_backup_date` post meta, and
  the `d2g_delete_data_on_uninstall` option.** Every site running 1.0.0–1.2.0
  holds real data under these keys, and those backups are the only way to
  restore a converted page. Renaming them would orphan every backup, so they
  stay put and existing backups keep working after the rename.

---

## [1.2.0] — 2026-08-04

Release-hygiene release: adds the licence text and a delete-time cleanup
handler, with backup removal opt-in rather than automatic.

### Added
- `LICENSE` — the full GPL-2.0 text, matching the licence declared in the plugin
  header.
- `uninstall.php` — cleanup handler that runs when the plugin is **deleted**
  (not on deactivation). Multisite-aware: it walks every site, since post meta
  and options are per-site and `uninstall.php` executes only once.
- **"Delete all Divi backups when this plugin is deleted"** setting on the tools
  screen, off by default, with a confirmation prompt when switching it on.

### Changed
- Deleting the plugin no longer leaves its preference row behind. **Backups are
  still kept unless the new setting is switched on** — they are the only way to
  restore a converted page, so removing the plugin must not silently destroy the
  ability to undo its own work.

---

## [1.1.0] — 2026-08-04

Adds a restore path and makes the scan filter, sort, and per-page controls
functional. Rolls up the fixes merged since `1.0.0` that were never released
under a version number of their own.

### Added
- **Restore button.** Any page holding a `_d2g_divi_backup` now shows a
  **Restore** action that puts the original Divi content back and re-enables the
  Divi Builder for that page (`_et_pb_use_builder`). Backed by a new
  `d2g_restore_page` AJAX endpoint, nonce-checked and gated on `manage_options`
  like the others. The backup meta is deliberately kept after restoring, so a
  restore can be repeated and converting again does not lose the original.
- **Backup column** in the scan results, showing the backup date where one
  exists, so it is clear which pages can be rolled back.
- Working **Show / Sort by / Per page** filters. All three drive the server-side
  query rather than filtering the visible page of results, so they apply across
  the whole result set. Changing any of them re-queries from page 1.
- Working **sortable column headers** for Title, Type, Status, and Date.
  Clicking the active column toggles the direction; the header state and the
  Sort by dropdown stay in sync in both directions.
- `Type (Z–A)` and `Status (Z–A)` sort options, so every direction reachable by
  clicking a column header is also selectable in the dropdown.
- Pagination for the Divi page scan results, including first/previous/next/last
  controls and an item count (PRs #7, #8).

### Changed
- **The scan now also lists already-converted pages that have a backup.** A
  converted page no longer contains `[et_pb_` markup, so under the previous
  query it disappeared from the results — which would have made a Restore button
  unreachable. Matching is now "contains Divi shortcodes **or** has a backup".
- Rows for already-converted pages render with Preview and Convert disabled and
  their checkbox unavailable, so a page cannot be converted twice.
- Batch selection and "select all" skip rows that have nothing to convert.
- The per-page selector drives the query directly; the user's `edit_per_page`
  screen option is now only the fallback when no valid selection is sent.

### Fixed
- Divi scan results failing to render in the admin table (PR #9).
- Invalid Gutenberg image and gallery conversion output (PR #6).
- Divi gallery grid vs. carousel format not being preserved through conversion
  (PR #5).
- Invalid Gutenberg nesting produced by single-column Divi rows (PR #4).
- Block validation errors causing "This block contains unexpected or invalid
  content" in the block editor (PR #3).
- Embedded video and gallery media being lost during conversion (PR #3).
- Gallery conversion updated to emit modern nested `core/image` blocks inside
  `core/gallery` (PR #2).

---

## [1.0.0] — 2026-03-12

Initial implementation of the plugin.

### Added
- `D2G_Parser` — recursive Divi shortcode parser producing a node tree of tags,
  attributes, raw content, and children. Handles nested modules via depth
  counting, self-closing tags, unmatched closing tags, smart-quote entity
  normalization, and loose text nodes.
- `D2G_Style_Mapper` — translation of Divi design attributes (background colour
  and image, text colours, text orientation, custom padding and margin, max
  width, border radii, border width and colour, box shadow, font size, line
  height, custom CSS, Divi font strings) into inline CSS, Gutenberg colour
  attributes, and `has-text-align-*` classes.
- `D2G_Converter` — module-by-module conversion of Divi shortcodes to core
  Gutenberg blocks, covering sections, rows, columns, text, images, buttons,
  video, blurbs, CTAs, dividers, fullwidth headers, galleries, accordions and
  toggles, tabs, sliders and slides, testimonials, team members, pricing tables,
  counters, social media follow, maps, code, contact forms, audio, sidebars,
  blog, signup, login, post title, menus, comments, search, portfolios, video
  sliders, and shop. Unknown modules fall back to rendering children or wrapping
  raw content in `core/html`.
- Admin screen under **Tools › Block Converter for Divi** for scanning, previewing, and
  converting.
- AJAX endpoint `d2g_scan_pages` — finds `page` and `post` records containing
  `[et_pb_` in `publish`, `draft`, `private`, or `pending` status.
- AJAX endpoint `d2g_preview_conversion` — side-by-side original vs. converted
  markup in a modal, without saving.
- AJAX endpoint `d2g_convert_page` — converts and saves a single page.
- Batch conversion of multi-selected pages, processed sequentially with progress
  reporting.
- Optional backup of the original Divi content to the `_d2g_divi_backup` post
  meta key with a `_d2g_backup_date` timestamp, taken before overwriting.
- Removal of `_et_pb_use_builder` and `_et_pb_old_content` post meta after a
  successful conversion so WordPress opens the page in the block editor.
- Nonce verification (`d2g_nonce`) and `manage_options` capability checks on all
  three AJAX endpoints.

### Known limitations at this version
- The backup written to post meta is never read — there is no restore or revert
  path in the plugin.
- `et_pb_map`, `et_pb_sidebar`, `et_pb_signup`, and `et_pb_shop` convert to text
  placeholders and require manual rebuilding.
- Divi Theme Builder templates and Divi Library global modules are not handled.
- Hover states, animations, and responsive per-breakpoint styling are not
  mapped.

[Unreleased]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/johnjanney/block-converter-for-divi/releases/tag/v1.0.0
