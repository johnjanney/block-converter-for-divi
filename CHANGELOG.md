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

## [2.2.0] — 2026-08-05

Security and content-integrity release, following a second external review
(`CODEX-REVIEW.md`, answered in `CODEX-REVIEW-RESPONSE.md`). That review found
one high-severity injection path and several content-loss paths that 2.1.0's own
test suite could not see.

**It has still not been run against a live WordPress install.** `Tested up to:`
remains a placeholder and no converted page has been opened in a real block
editor. Convert on staging first. See `OPENQUESTIONS.md` Q18 and Q23;
WordPress.org submission stays blocked.

### Security

- **Shortcode attribute values could inject HTML attributes.** The parser
  accepts single-quoted attribute values, so a value could contain a double
  quote. `align` on an Image and `button_alignment` on a Button were
  concatenated straight into a quoted `class` attribute, and a crafted value
  closed that attribute and opened an event handler:
  `<figure class="… aligncenter" onmouseover="alert(1)">`. Layout values are now
  reduced to allowlisted tokens, and the class attribute is escaped as well.
- **Colour values could inject CSS declarations.** `esc_attr()` makes a value
  safe as markup but says nothing about CSS, so `red;background-image:url(x)`
  passed through it intact. Colours are now validated against the CSS colour
  grammars — hex, `rgb()`/`rgba()`/`hsl()`/`hsla()`, or a bare keyword — and
  anything else is dropped.

### Fixed

- **Fullwidth Header wrapped its whole body in one paragraph block**, producing
  `<p class="…"><p>One</p><p>Two</p></p>`. Nested `<p>` elements are invalid and
  do not match what `core/paragraph` regenerates. It now goes through the same
  HTML splitter as every other rich-text renderer.
- **Modules nested inside another module were dropped.** A Button placed inside
  a Text module vanished from the converted page while the text either side of
  it survived, with no warning. Text, Blurb, CTA, Toggle, Tab, Slide,
  Testimonial, Team Member, Fullwidth Header, and Pricing Table now render their
  loose content and their child modules together, in source order.
- **`<caption>` and `<colgroup>` were deleted** when a table became
  `core/table`, which copies only `thead`, `tbody`, `tfoot`, and loose `tr`
  children. Such tables are preserved whole as `core/html` with a warning.
- **HTML comments were dropped** by the DOM walk. They are preserved as
  `core/html`. A comment shaped like a block delimiter is removed and reported,
  because re-emitting it would corrupt the document.
- **A bar counter's HTML body was escaped and published as visible markup** — a
  body of `<p>Sales</p>` appeared on the page as `&lt;p&gt;Sales&lt;/p&gt;`. The
  label is reduced to its text first.
- **`]` inside a quoted shortcode attribute truncated the tag**, leaving the
  remainder as visible text. The tokenizer is now quote-aware: it scans from `[`
  to the closing `]` treating quoted values as opaque. One scanner now backs the
  detector, the tree parser, the closing-tag matcher, and the stripper, so all
  four agree on what a tag is.
- **Curly-quote entities in body text were rewritten to straight quotes.**
  `&#8220;quoted&#8221;` came out as `"quoted"` — a silent change to the
  author's words. Normalization no longer touches the document; Divi's habit of
  encoding an attribute's delimiting quotes is repaired inside the attribute
  parser instead.
- **The write lock was check-then-set.** Two requests could both read "no lock"
  before either wrote one. Acquisition is now a single atomic `INSERT` against
  the unique index on `wp_options.option_name`, carries an owner token so a
  request can only release its own lock, and ages out after two minutes.
- **Conversion wrote without a source token in the two commonest paths.** The
  stale-content check ran only when the client chose to send a hash, and both
  single conversion without Preview and every batch conversion sent an empty
  one. The scan now issues a token per row, the token is mandatory, and the post
  is re-read and re-checked *after* the lock is taken.
- **Builder-meta snapshots could not tell an absent key from an empty one**, and
  restore never cleared the managed keys first. Each key is now recorded as an
  explicit `{exists, values}` pair covering repeated meta rows, and restore
  deletes both keys before writing back only what existed. 2.1.0 snapshots are
  still read.
- **Text taken from a Divi attribute was encoded twice.** Divi stores attribute
  values HTML-encoded, so a button reading "Fish & Chips" is stored as
  `button_text="Fish &amp; Chips"`; escaping that again published the literal
  characters `Fish &amp; Chips`. Affected every title, heading, subhead, author,
  name, label, button text, address and image `alt` drawn from an attribute.
  Found while verifying this release end to end, not reported by the review.
- `$price` in `convert_pricing_table()`, which 2.1.0's changelog claimed to have
  removed and had not.

### Changed — `Tested up to` is a measurement now, not a placeholder

`bin/wp-matrix.sh` runs the live suite against a series of WordPress versions,
each on a clean environment and paired with a PHP version that release actually
supported. Results:

| WordPress | PHP | Result | Blocks not registered |
| --- | --- | --- | --- |
| 6.0 | 7.4.33 | **fail** | `core/comments`, `core/list-item` |
| 6.1 | 7.4.33 | pass | — |
| 6.2 | 8.0.30 | pass | — |
| 6.3 | 8.0.30 | pass | — |
| 6.8 | 8.2.33 | pass | — |
| 7.0.2 | 8.3.33 | pass | — |

Each passing run covers the endpoint contract, all 138 fixtures through the
database, restore byte-identity, block registration, and an empty WordPress
debug log. `Tested up to` is therefore `7.0`, and `Requires at least` is `6.1`.
Both had been unverified since the plugin was written, and `Tested up to` was
the single item blocking publication.

The distinct-block count rises from 32 to 33 at WordPress 6.3, which is the
`core/details` feature detection working — visible here as data rather than as
a stubbed registry.

### Changed — the minimum WordPress version is now 6.1, and it was measured

`Requires at least` was `6.0`, derived in 2.1.0 by reading which blocks the
converter emits and looking up when each arrived. Running the plugin against a
real WordPress 6.0 showed the derivation was wrong on both counts:

- **`core/list-item` does not exist on 6.0.** It arrived in 6.1, and it was not
  in the derivation at all — so *every converted list* rendered as "your site
  doesn't include support for this block", once per item. Lists are among the
  commonest things this plugin emits.
- **`core/comments` is 6.1, not 6.0**, which the derivation stated explicitly.

6.1 passes with all 32 distinct emitted blocks registered. The floor is now 6.1
in the plugin header, `readme.txt`, `README.md` and `BRIEF.md`, and the two code
comments that asserted "core/list has held its items as inner blocks since
WordPress 6.0" are corrected.

`bin/wp-matrix.sh` runs the live suite across WordPress versions on clean
environments, and the live suite asserts that every block it emits is registered
on the version under test. That assertion cannot be made offline: the fixture
suite assumes a current install, and core's own validator only knows the block
library npm ships.

### Fixed — found by driving the admin screen in a browser

- **A successful conversion said nothing.** `#d2g-status` is the screen's
  `aria-live` region, and only *errors* wrote to it — so a conversion that
  failed was announced and one that succeeded was silent. The row changed
  colour, which is no use to a screen reader.
- **Conversion warnings never reached the user unless they previewed first.**
  The server returns them with every conversion response and the browser
  discarded them: they were rendered into the preview dialog and nowhere else.
  Anyone who clicked Convert directly — the most direct path through the
  screen — learned nothing about what could not be carried over, which is the
  entire point of the loss reporting added earlier in this release. Warnings now
  appear in a persistent region below the status line, deduplicated, for both
  single and batch conversions.
- **Closing the preview dialog dumped keyboard users at the top of the page.**
  Opening the preview disables the row's buttons while the request runs, and
  disabling the focused element moves focus to `<body>` — so the dialog captured
  "where focus came from" *after* it had already been lost, and returned it
  there. The control to restore focus to is now passed in explicitly, with a
  fallback for when it has since been disabled.

### Fixed — found by the new block validator

Three defects that four rounds of static checks had missed, found within
minutes of running WordPress's own validator against the fixtures. All three
were invalid block markup, which is the one thing the project exists to avoid.

- **Every converted Cover block was invalid.** `core/cover` saves its
  background `<img>` before the dim `<span>`; the converter emitted them the
  other way round, omitted `aria-hidden` and `has-background-dim-100`, and
  dropped the overlay colour entirely. Affected every Fullwidth Header and
  Slide with a background image.
- **Coloured Dividers were invalid.** A `core/separator` carrying a colour also
  needs `has-text-color` and a `color` declaration, not just the background
  ones.
- **Comments blocks were invalid.** Emitted as a self-closing delimiter when
  `core/comments` holds inner blocks and saves a wrapper `<div>`.

### Added

- **Real WordPress block validation in the test suite.** `tests/js/validate.mjs`
  registers the 113 core blocks from `@wordpress/block-library` and runs core's
  own `parse()` — which re-runs each block's `save()` over the parsed attributes
  and compares — over every fixture. This is what decides whether the editor
  says "this block contains unexpected or invalid content", and until now the
  project had no way to ask. Resolves the long-standing Q23.
- **Golden snapshots.** Every fixture's exact output and its full set of
  warnings are committed under `tests/golden/`. Any difference fails the run and
  prints a diff. This is what makes restructuring the converter safe: it catches
  the changes nobody thought to assert on.
- **Complete module coverage, enforced.** 33 of the 58 supported Divi modules
  had no fixture at all. All 58 now do, and `tests/lib/coverage.php` reads the
  tag list from the parser, so adding a module and forgetting to test it fails
  the suite.
- **Determinism and idempotence checks** on every fixture: converting twice
  gives identical output, and converting already-converted output is a no-op.
- **`tests/js/canonical.mjs`**, which asks WordPress what markup it would have
  saved for a given block. Hand-written block markup no longer has to be
  guesswork.
- **`tests/coverage.php`**, a line-coverage report. It is what turned the
  remaining untested branches into fixtures; the converter is now at 96.7% and
  the parser at 98.8%.
- **Style and behaviour loss reporting.** Every module's attributes are matched
  against a registry of unmapped-setting patterns — spacing, sizing, borders,
  shadows, typography, background treatment, parallax, animation, custom CSS,
  IDs and classes, positioning, per-device visibility, hover styling, and
  `_tablet`/`_phone` overrides — and one warning per module tag names what was
  lost. Tabs, sliders, video sliders, accordions, and counters additionally
  report the interactive behaviour that did not survive. This is what the
  documents had been claiming since 2.1.0 without any code behind it.
- Twenty new regression fixtures, one per defect above. Each was confirmed to
  fail against 2.1.0 before the fix landed.
- A GitHub Actions workflow: the full suite including block validation, a PHP
  matrix over 7.4 through 8.4, a guard that golden snapshots are committed and
  current, and lint and syntax checks. The validator needs Node 22 (jsdom's
  floor); `tests/js/.npmrc` sets `engine-strict` so an older Node fails the
  install rather than failing mysteriously at runtime. The suite was additionally run locally
  under Docker on PHP 7.4, 8.1, 8.2, 8.3, 8.4 and 8.5 — identical results and
  byte-identical snapshots on all six.

### Changed

- **The converter is no longer one class.** `D2G_Converter` was 2,638 lines
  holding every module renderer, a 170-line dispatch switch, the HTML-to-blocks
  engine and the markup primitives. It is now 430 lines of orchestration, with
  the work split into `D2G_HTML_Converter`, `D2G_Block_Builder`, and seven
  `D2G_Renderer_*` classes grouped by what a module *is* — layout, text, media,
  content, interactive, pricing, dynamic.

  Renderers declare the tags they handle, and the converter builds its dispatch
  table by asking them. A tag cannot be routed to a method that does not exist,
  and two renderers cannot silently claim the same tag; the whole table is
  snapshotted so a change in ownership is a reviewable diff.

  **No output changed.** All 139 golden snapshots are byte-identical before and
  after, on PHP 7.4, 8.1, 8.2, 8.3, 8.4 and 8.5, and the dispatch table was
  verified to handle exactly the same 55 tags as the switch it replaced. The
  split was made only once the suite could prove that — doing it earlier would
  have been a rewrite with no way to check it.
- CI runs the live suite on every push and pull request, so it cannot rot. The
  version matrix is opt-in from the Actions tab: six clean environments take a
  quarter of an hour, which is too slow to gate a pull request but is what
  should be re-run before a release, and whenever the set of blocks the
  converter emits changes.
- `includes/load.php` is now the single dependency-ordered require list, used by
  both the plugin and the test suite. They were two lists in two files, which is
  a standing invitation for the tests to exercise different code than ships.
- **Block validation gates the release.** `bin/build-zip.sh` now passes
  `--require-validator`, so a ZIP cannot be built from a run that could not
  check block validity. Without that flag a missing harness prints a loud
  "NOT RUN — block validity is therefore UNPROVEN" and continues; it never
  silently reports a pass it did not make.
- **Block assertions are recursive.** They inspected only top-level blocks,
  which is why the Fullwidth Header defect above went undetected — the offending
  paragraph sat one level down inside a Group. `tests/run.php` now also
  documents, at the top of the file, exactly what it does *not* cover.
- The parser, converter, style mapper, and admin class are loaded only for admin,
  AJAX and WP-CLI requests. The plugin has no front-end runtime feature, so
  roughly 2,700 lines were being parsed on every visitor request for nothing.
  WP-CLI is admitted explicitly: `is_admin()` is false there, so the guard alone
  made the plugin unreachable from the command line — which broke nothing for
  users today, since there is no WP-CLI command yet, but did mean the endpoints
  could not be tested against a real WordPress at all.
- `bin/build-zip.sh` asserts the archive contains exactly the files the plugin
  is made of. The exclude list is a denylist, and a denylist silently ships
  whatever nobody remembered to add to it.
- Gallery conversion primes the attachment cache once for all image IDs instead
  of issuing three uncached lookups per image.
- Converted-content strings that were still hard-coded English — `Click Here`,
  `Tab`, `Subscribe`, `Map:`, and the sidebar, email-signup, gallery, and
  WooCommerce placeholders — go through the translation pipeline.
- Uninstall clears any stray conversion-lock rows.

---

## [2.1.0] — 2026-08-05

Correctness and data-safety release, following an external review of 2.0.0
(`CODEX-REVIEW.md`, answered in `CODEX-REVIEW-RESPONSE.md`).

Released on GitHub as `v2.1.0` and marked latest. **It has still not been run
against a live WordPress install** — `Tested up to:` remains a placeholder and
no converted page has been opened in a real block editor. Convert on staging
first. See `OPENQUESTIONS.md` Q18 and Q23; WordPress.org submission stays
blocked on them.

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

- **Conversion warnings.** Modules that could not be carried over faithfully are
  collected and shown in the preview, and returned with the conversion response.
  (Coverage in 2.1.0 was much narrower than this entry originally claimed: it
  covered unmapped module *tags* and a handful of specific modules, not
  unsupported style settings or lost interactive behaviour. Style and behaviour
  loss reporting arrived in 2.2.0.)
- **A fixture test suite** — `php tests/run.php`. Runs on plain PHP with no
  WordPress install, and gates `bin/build-zip.sh`.
- Filters: `d2g_scan_hard_cap`, `d2g_supported_post_types`,
  `d2g_supported_post_statuses`, `d2g_contact_form_markup`,
  `d2g_portfolio_post_type`, `d2g_portfolio_taxonomy`.

### Removed

- The `mbstring` dependency and the PHP 8.2 `HTML-ENTITIES` deprecation. The DOM
  loader now uses an XML encoding prologue, which also stops multibyte
  characters being rewritten as numeric entities.
- Dead code: an unused regex in the parser, a no-op block name map in the
  converter, and a duplicated pagination section plus selectors for controls
  that do not exist in the stylesheet. (This entry also claimed an unused
  converter variable was removed. It was not — `$price` in
  `convert_pricing_table()` survived until 2.2.0.)

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
