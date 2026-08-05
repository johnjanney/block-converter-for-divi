# Changelog

All notable changes to **Divi to Gutenberg Converter** are documented in this
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

1. **Bump the version in both places** — the `Version:` header in
   `divi2gutenberg.php` and the `D2G_VERSION` constant. They must always match;
   `D2G_VERSION` is used for asset cache-busting.
2. **Have a dated section in this file** listing every user-visible change.
3. **Be tagged in git** as `vX.Y.Z` on the release commit.
4. **Produce a distributable plugin ZIP named with its version number** —
   `divi2gutenberg-X.Y.Z.zip` (for example `divi2gutenberg-1.2.3.zip`). The ZIP
   must contain a single top-level `divi2gutenberg/` directory so that it
   installs correctly through the WordPress plugin uploader.
5. **Preserve all previously built ZIPs.** Old version archives are **never
   deleted or overwritten** — they are the rollback path for anyone who needs to
   return to an earlier build. Each new release adds an archive; it does not
   replace one.

### What goes in the ZIP

Ships to users: the plugin code, `LICENSE`, `README.md`, `INSTRUCTIONS.md`, and
`CHANGELOG.md`. `LICENSE` is not optional — the GPL requires the licence text to
accompany the distributed work.

Excluded as internal: `BRIEF.md`, `OPENQUESTIONS.md`, `.git/`, `dist/`, and
`build/`.

### Where ZIPs live

Built archives are kept in `dist/` (git-ignored for the build artefacts
themselves) and attached to the corresponding GitHub release for the tag, which
is the durable, permanent copy. The `dist/` directory accumulates every version
built locally:

```
dist/
  divi2gutenberg-1.0.0.zip
  divi2gutenberg-1.0.1.zip
  divi2gutenberg-1.1.0.zip
  ...
```

### Building a release ZIP

From the repository root:

Use the build script, which reads the version from the plugin header and refuses
to overwrite an archive that already exists:

```bash
./bin/build-zip.sh
```

Then tag and publish:

```bash
git tag -a "v${VERSION}" -m "Release ${VERSION}"
git push origin "v${VERSION}"
gh release create "v${VERSION}" "dist/divi2gutenberg-${VERSION}.zip" \
   --title "v${VERSION}" --notes-file <(sed -n '/## \['"${VERSION}"'\]/,/## \[/p' CHANGELOG.md)
```

---

## [Unreleased]

_Nothing yet._

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
- Admin screen under **Tools › Divi to Gutenberg** for scanning, previewing, and
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

[Unreleased]: https://github.com/johnjanney/divi2gutenberg/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/johnjanney/divi2gutenberg/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/johnjanney/divi2gutenberg/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/johnjanney/divi2gutenberg/releases/tag/v1.0.0
