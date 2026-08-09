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
   `block-converter-for-divi.php`, the `BCFD_VERSION` constant, and `Stable tag:`
   in `readme.txt`. They must always match: `BCFD_VERSION` drives asset
   cache-busting, and WordPress.org serves whatever `Stable tag` names. The
   constant was `D2G_VERSION` before the 2.0.0 rename, and this list said so
   until 2.7.0; the rename is described at the top of the plugin file.
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

## [2.9.0] — 2026-08-09

Both of the last two releases were found by counting a real corpus by hand.
This one moves the counting into the plugin, so it happens on every conversion
and nobody has to think to look.

### Added

- **A content census.** Every conversion is now counted in and counted out —
  words, links, images, buttons — and any shortfall nothing accounted for is
  reported in the same list as every other loss.

  It shares no code with the parser, and that restriction is the whole value of
  it. A counter built on the parser would have read
  `[et_pb_button button_url=&quot;…&quot;]` as an attribute-less tag in exactly
  the way the converter did before 2.7.0: zero links going in, zero coming out,
  and a clean bill of health for a page that had lost every link on it. The
  duplicated, naive regexes in `class-d2g-census.php` are deliberate. Two
  implementations that fail the same way are one implementation.

  A renderer that drops something on purpose says so, and the census subtracts
  it — the gallery images whose attachments are gone, a section's background
  image. That subtraction is what makes the remainder worth reading: what is
  left is loss that nothing in the converter knew about, which is precisely the
  class of defect that produced 2.7.0 and 2.8.0.

  It reports; it does not refuse. A count is evidence, not proof. A module that
  legitimately becomes a placeholder loses words, and blocking a conversion over
  arithmetic would stop work the user asked for.

### Fixed

- A video module carrying two sources kept one and dropped the other in silence.
  Divi offers `src` and `src_webm` so a browser can pick a format it supports,
  and the renderer only read the second when the first was empty. Usually that
  costs nothing, because the two are the same video in two encodings — but
  nothing obliges an author to use them that way, and on a real page they were
  two different Vimeo films. `core/video` holds one source, so this cannot be
  repaired; it is now reported so it can be rebuilt by hand.

  This was the census's first finding on real content, and the only one it
  raised across 247 pages. After the fix it reports none.

- A counter with modules nested inside its label reduced them to their words and
  discarded the rest. It did warn, but about the bar animation, which is a
  different loss; the modules going missing is now its own line.

- A social follow link that named no network was skipped, and its address went
  with it. `core/social-link` is keyed by service, so there was no icon to
  convert it to — but that is a thing to say, not a thing to do quietly.

### Changed

- The offline suite is 211 tests, up from 207, and gained a standing gate: no
  fixture may produce a census warning, so a future change that silently drops
  content turns the run red on its own. It also asserts that the census can
  still detect loss, because a counter that never fires reads as proof.

- Conversion costs about 13% more — 0.74 to 0.84 ms a page — which is what the
  second reading of every document buys.

---

## [2.8.0] — 2026-08-09

The corpus from 2.7.0 was run a second time, against 2.7.0, and read again. That
is what this release is: the same 247 pages, the same measurements, and the
three things the numbers said next.

The second run confirmed the first one held — 285 of 285 converted pages valid
against core's own parser, every content word preserved, 1,427 of 1,428 source
URLs, no residual shortcodes. What it also showed was that 718 spacing settings
were still being reported as lost, that 247 of those reports were wrong, and
that one page had been converted from a previous conversion rather than from
Divi.

### Fixed

- A page that already holds this plugin's own output is refused rather than
  converted again. One page in the 2.7.0 run went through that: it was converted
  from 2.6.0's *output* instead of its restored Divi — reproduced byte for byte
  — and came out having lost its donation button and an image, with the words
  `wp:paragraph` printed on the page. It was reachable because a conversion that
  leaves a shortcode behind leaves the row looking convertible.

  The obvious test — does the content contain `<!-- wp:` — is wrong twice over,
  and the corpus caught both. It would refuse the 142 pages carrying Divi 5's
  `<!-- wp:divi/placeholder -->` marker, which convert perfectly. And it would
  refuse a page with a block comment *inside* a Divi module, which the converter
  has stripped and reported since 2.2.0. So the question asked is structural: a
  cheap pattern decides whether it is worth asking, then the parser decides
  whether the delimiter sits in a top-level text node — the document's own
  structure, so a previous conversion — or inside a module, where it is an
  author's stray comment. Measured on the corpus: none of the 247 restored Divi
  pages are refused, and all 247 previous outputs are.

  No equivalent flag was added to the scan. SQL can only ask whether a substring
  is present, so a scan-level test would grey out the second kind of page and
  take away a conversion that works. The write path is where the answer has to
  be right, and it is the only place that can be.

- Two reasons a module reported spacing it had not lost, together every one of
  247 wrong reports on the corpus. `custom_padding__hover` matched the spacing
  pattern before the hover pattern did, so a column whose padding *had* been
  carried still said it was lost. And Divi writes `custom_padding="|||"` for
  padding that was never set — a quarter of every spacing attribute in the
  corpus — which sent people to rebuild padding that had never existed.

### Added

- Spacing is carried on content modules, not just on the containers around them.
  2.4.0 mapped `custom_padding` and `custom_margin` onto section, row and column
  and stopped there; the corpus had 835 usable spacing values sitting on the
  modules *inside* those containers. Text, gallery, image, button, divider,
  video, audio and video slider now carry their own.

  Each block's markup was measured with `tests/js/canonical.mjs` rather than
  assumed, which is the only reason this validates. Three things it caught:
  `core/video` and `core/audio` write the style attribute *before* the class,
  where image, gallery and separator write it after. `core/separator` emits
  spacing *before* its colour declarations, the reverse of the order a group
  uses. And a Text module becomes several sibling blocks, so it has no block of
  its own to hold padding — it gains a `core/group` wrapper, and only when there
  is spacing to carry, so a page whose text modules set no padding gains no
  wrappers at all.

  Measured over the corpus: blocks carrying mapped design 550 → 1,051, spacing
  reported lost 718 → 0, warnings 1,243 → 668 (5.0 to 2.7 a page), pages
  reporting any design loss 247 → 182. What is left is hover styling and
  positioning — 259 and 235 — which core has no way to express, so they are
  reported and should be.

### Changed

- The offline suite is 207 tests, up from 197, and the live suite is 36, up from
  30. No existing golden snapshot changed: every diff this release produces is
  an addition, which is the evidence that the spacing work only touches content
  that carries spacing.

---

## [2.7.0] — 2026-08-09

The first release driven by a real corpus rather than by fixtures: 247 Divi
pages from a live site, converted and then read back out of the database.

It found that the converter had been discarding almost everything it was
supposed to carry. Every fixture in the suite had been written with canonical
`"` delimiters around shortcode attributes, because that is what someone
writing a fixture types. Real Divi content reaches the plugin having been
through storage paths that write those delimiters as `&quot;`, and against
that input the attribute parser matched nothing at all — so every module
rendered from an empty attribute array, and the loss reporter, which works by
inspecting parsed attributes, had nothing to report. 189 passing tests, 58/58
module coverage and byte-exact golden snapshots could not see any of it.

That is the honest lesson of this release: the fixture corpus was not merely
incomplete, it was systematically biased toward the one input shape that
already worked.

### Fixed

- Shortcode attributes whose delimiting quotes are stored as `&quot;` are read
  as attributes. They previously parsed as an empty array, so **every setting on
  every module was silently discarded**. Measured across the 247-page corpus,
  where 246 pages were affected: all **278** `et_pb_image` modules produced no
  image block at all, **249 of 252** buttons became `<a href="#">Click Here</a>`
  — 207 of them had pointed at a donation page — column widths survived on 24
  blocks instead of 533, and mapped design settings on 17 instead of 550. Only
  783 of the 1,243 warnings that were due were raised, because a loss detector
  that reads parsed attributes cannot report what never parsed.

  `parse_attrs()` is now a left-to-right scanner rather than two anchored
  patterns, and the form that *opened* a value decides what may close it. A
  value opened with a literal quote is closed only by a literal quote, so
  `button_text="He said &quot;hello&quot;"` keeps the author's punctuation. A
  value opened with `&quot;` is closed by either form, because real exports stop
  encoding partway through a tag.

  This does not repair pages already converted by an earlier version. Restore
  them from their backup and convert again — see the upgrade notice.

- A tag mixing both quote forms no longer runs off the end of the document. The
  tokenizer counted only literal quotes, so a tag that opened a value with
  `&quot;` and closed it with `"` left the scan permanently inside a value: it
  swallowed the closing `]`, consumed the rest of the page, and published what
  it had eaten as raw shortcode text. Two pages did this, one of them printing a
  Mailchimp list ID onto the page. `scan_tag_end()` now follows the same
  open-decides-close rule as the attribute reader, which is what keeps the two
  from disagreeing about where a tag ends.

- Gallery images whose attachment ID no longer resolves are reported instead of
  dropped in silence. The renderer skipped them with a bare `continue`, which is
  the right markup — a broken `<img src="">` helps nobody — and the wrong thing
  to do quietly: on the corpus it emptied 202 of 243 galleries without a word.
  The count is now named, with a distinct message when the gallery ends up empty
  and nothing is left to see.

  It cannot recover them. A Divi gallery stores attachment IDs and no URLs, so
  there is no address to fall back on, which also makes moving a site between
  installs before converting a genuinely destructive order of operations — the
  IDs do not survive the move.

- A sub-list written as a sibling of its `<li>` rather than inside it is kept.
  That is invalid HTML which authors produce constantly and every browser
  renders as a sub-list; the converter dropped the whole subtree without a
  warning. One page in the corpus lost 173 words and 10 press-coverage links to
  it. Corpus-wide word loss is now zero, and all 1,189 source URLs survive.

### Added

- Pages stored in Divi 5's block format are counted and reported. Divi 5 writes
  `<!-- wp:divi/… -->` blocks rather than shortcodes, so a scan that looks for
  `[et_pb_` cannot see one: 19 pages in the corpus were listed nowhere,
  converted never, and left needing Divi with nothing said about it — a site
  could report every page converted while some still required the builder. The
  scan now says how many there are, including on the "nothing found" result,
  which is the case where a Divi-5-only site would otherwise be told it has
  nothing left to do.

  They are **counted, not converted.** Converting Divi 5's block format is a
  separate feature and is not in this release.

### Changed

- The offline suite is 197 tests, up from 189, and the live suite is 30, up from
  23. The live suite now covers the scan endpoint, which had no live coverage of
  any kind before — it is SQL, so nothing in the offline suite could reach it,
  and it was where the Divi 5 gap lived.

---

## [2.6.0] — 2026-08-09

The third and last of the block-supports phases. Spacing (2.4.0), typography
(2.5.0) and now borders are carried onto core's own block supports rather than
reported as lost.

### Added

- Divi borders on sections, rows and columns are carried over.
  `border_width_all`, `border_color_all` and `border_style_all` become core's
  `style.border.*`, and `border_radii` becomes `style.border.radius` when all
  four corners carry the same value.

  A radius that differs per corner is **not** guessed at. Core keys its corners
  topLeft/topRight/bottomLeft/bottomRight, CSS shorthand runs
  top-left/top-right/bottom-right/bottom-left, and Divi's order is documented
  nowhere. Rounding the wrong corner of somebody's box on the strength of a
  guess is worse than saying it was not carried over, so the renderer raises a
  warning naming that specific case — more use than the generic "borders" line
  it replaces. Three corners set and one empty is treated the same way.

  Per-side border widths are still reported as lost.

### Fixed

- The declaration order comment on `wrapper_styles()` said the order was
  alphabetical. It is not: measured with border, background and spacing
  together, core emits border before background, which alphabetically follows
  it. The earlier phases got away with the wrong explanation because nothing
  they emitted could tell the difference.

---

## [2.5.0] — 2026-08-09

The second release that repairs a design setting rather than reporting it, and
the one that makes converted body text look like the original.

### Added

- Divi typography and text colour are carried over where a module renders its
  body through the HTML engine. `body_text_color`, `body_font_size`,
  `body_line_height` and `body_letter_spacing` become core's `style.color.text`
  and `style.typography.*` on the paragraphs that body produces; the Text
  module's `header_*` equivalents do the same for its headings.

  Core serializes these alphabetically by CSS property — colour, font size,
  letter spacing, line height — which was measured with `tests/js/canonical.mjs`
  rather than assumed, and the result is validated on all nine supported
  WordPress releases.

  `line-height` gets its own grammar, because it is the one typographic value
  CSS defines as valid without a unit and Divi writes it both ways. Everything
  else goes through the same length grammar as spacing, so
  `body_font_size="18px;position:fixed"` contributes nothing.

- The loss reporter narrows per module rather than globally. `header_*`
  typography reaches only the Text module — every other module builds its
  headings in its own renderer from an attribute and never sees the setting — so
  a CTA now carries its body typography and still reports the heading typography
  it loses. Which modules those are was determined by measurement, not by
  assuming a whole renderer family behaves alike.

### Known

- `header_font` and `body_font` are still reported as lost. Divi packs family,
  weight, style and transform into one pipe-delimited value whose grammar is not
  documented, and this project has not seen enough real examples to encode it.
  Getting it wrong would put the wrong font weight on somebody's page, which is
  worse than saying it was not carried over.

---

## [2.4.0] — 2026-08-09

The first release that repairs a design setting rather than reporting it. Every
converted page used to say "Design settings were not carried over: spacing
(padding or margin)" and leave the user to rebuild by hand what the Divi source
had stated precisely.

### Added

- Divi spacing and background colour on sections, rows and columns are now
  carried over instead of reported as lost. `custom_padding` and `custom_margin`
  become core's `style.spacing` support; `background_color` becomes
  `style.color.background`.

  Naively emitting an inline `style` is what makes a static block report
  "unexpected or invalid content", so nothing here is invented. The declaration
  order — background, then margin, then padding, each top/right/bottom/left,
  with `flex-basis` last on a column — was measured with `tests/js/canonical.mjs`
  by asking core what it would have saved, and the output is validated against
  the block library of all nine supported WordPress releases.

  Divi values are validated rather than escaped. `css_length()` takes a number
  and a known unit; `custom_padding="20px;position:fixed|||"` contributes
  nothing. `auto` is dropped as well — legal CSS, but not a length core's
  spacing support can regenerate. Empty components stay empty rather than
  becoming `0px`, because writing zero where Divi wrote nothing flattens
  spacing the theme was supplying.

  A background colour with `background_enable_color="off"` is not painted.

### Changed

- The loss reporter no longer names spacing on the modules that now keep it.
  Renderers declare what they map via `mapped_style_attrs()` and the converter
  reads that, so the report and the mapping cannot drift. Matching is by exact
  attribute name: `custom_padding` went quiet, `custom_padding_tablet` did not,
  because core block supports have no responsive dimension. A report that fires
  for settings the converter did carry over is how users learn to ignore it.

---

## [2.3.1] — 2026-08-07

Documentation only. No code changed; `bin/build-zip.sh` produces a plugin whose
PHP, JavaScript and CSS are byte-identical to 2.3.0.

It is a release rather than a doc commit because `INSTRUCTIONS.md` **ships
inside the plugin**, so a correction to it only reaches users through a package.

### Fixed

- `INSTRUCTIONS.md` said nothing about the pre-rename plugin. Installing beside
  "Divi to Gutenberg Converter" failed with a fatal error in 2.0.0–2.2.0, and
  2.3.0 turned that into a readable refusal — but the instruction either way is
  to delete the old plugin first, and no install document said so. There is now
  a step before the install options, and troubleshooting entries matching both
  the old fatal error and the new message.
- `INSTRUCTIONS.md` described the backup as conditional — "only if the backup
  checkbox was ticked when you converted". 2.3.0 made the backup mandatory and
  removed the checkbox. That sentence told users a conversion might not be
  undoable when it always is, and it appeared in four places.
- `INSTRUCTIONS.md` still declared WordPress 6.0 as the minimum. It has been 6.1
  since 2.2.0, measured rather than derived.
- The Verify section now points at the per-release SHA-256 digests, so a
  downloaded ZIP can be checked.
- `readme.txt` and `README.md` carry the same upgrade warning.

---

## [2.3.0] — 2026-08-06

Answers the third external review (`CODEX-REVIEW.md`, dated 2026-08-05;
response in `CODEX-REVIEW-RESPONSE.md`). Every finding in it was reproduced
before it was fixed.

It also fixes an install-time defect the review did not cover and none of the
suites could see: activating this plugin beside the pre-rename "Divi to
Gutenberg Converter" failed with a fatal error. That has been broken since
2.0.0, the release that performed the rename.

### Fixed — upgrading from "Divi to Gutenberg Converter"

- Activating this plugin while the pre-rename plugin was still installed failed
  with "Plugin could not be activated because it triggered a fatal error".

  The cause was not the shared class names, which is what it looks like. Both
  plugins defined `D2G_VERSION`, `D2G_PLUGIN_DIR` and `D2G_PLUGIN_URL`, and
  `define()` keeps an existing constant rather than overwriting it — so this
  plugin read `D2G_PLUGIN_DIR`, got `…/plugins/divi2gutenberg/`, and tried to
  require its own bootstrap from the other plugin's directory. That file does
  not exist in 1.x, so the include was fatal. It has been broken on this upgrade
  path since 2.0.0, which is the release that did the rename.

  The three runtime constants are now `BCFD_*`. The `d2g_` storage keys are
  untouched, so existing backups stay exactly where they are and stay
  restorable.

- Having both plugins on a site is now detected and refused with an explanation,
  rather than crashing. Two copies of this code cannot coexist — they declare
  the same classes — and which one dies depended only on directory order. The
  plugin now declares nothing when it finds the old one active, so the site
  keeps working, and activation stops with a message naming what to do.

### Fixed — content loss

- Six structural renderers discarded content. Tabs, Counters, Pricing Tables,
  a Pricing Table with at least one item, Social Follow and Video Slider each
  iterated only the child tag they expected and let everything else fall off
  the end of the loop — loose text, and any module nested inside. The probe
  `[et_pb_tabs]Before[et_pb_button button_text="Keep" /]After[/et_pb_tabs]`
  converted to an empty Group: all four pieces of content gone, no warning.
  They now share one traversal, `D2G_Converter::render_structural_children()`,
  which keeps everything in source order and reports an unexpected structure.
  A Video Slider item is also no longer skipped for lacking a `src` attribute,
  since the shared Video renderer reads an iframe or URL from the body.
- A URL that did not survive sanitising now raises a warning instead of the
  module quietly disappearing.

### Fixed — security

- Block attributes were written into block comments as plain JSON.
  `[et_pb_search placeholder="find--><img src=x onerror=alert(1)>" /]` produced
  a comment that terminated at the author's own `-->`, leaving an `<img>` as
  live markup for anything that reads `post_content` as HTML. All block markup
  now goes through `serialize_block_attributes()`, with a byte-identical
  fallback for the offline suite that the live suite holds against core's own.
- Blog, Search, Menu, Login, Post Title and Portfolio built block comments by
  hand and bypassed the builder entirely. They no longer can.
- Stored URL attributes are sanitised with `sanitize_url()` and the same
  cleaned value is used for the markup. `javascript:` was previously stripped
  from an `<img>` and kept in the block attribute the editor rebuilds the
  `<img>` from.
- Deleted `D2G_Style_Mapper`'s unused functions. They were unreachable, and
  they built CSS by concatenating raw Divi values and appending
  `custom_css_main_element` verbatim — the injection class that had to be
  fixed in the live renderers.
- Third-party GitHub Actions are pinned to commit SHAs rather than mutable
  tags.

### Fixed — compatibility

- Converted Cover blocks were invalid on WordPress 6.1 through 6.7.
  `core/cover` swapped the order of its `<img>` and dimming `<span>` in 6.8,
  and the converter emitted the 6.8 order everywhere.
- Converted Toggles were invalid on WordPress 6.3. `core/details` arrived there
  with the summary as a block attribute, so 6.3 regenerated every converted
  toggle with the literal word "Details".
- Both are decided by `D2G_Block_Builder::wp_at_least()`, and both were found
  by the new per-version validator rather than reasoned about.

### Fixed — data integrity

- A save made in the block editor while a conversion was running was silently
  overwritten. The plugin's lock cannot cover it, because an editor does not
  take that lock. The write is now guarded at `pre_post_update` — the last
  moment before core's own `UPDATE` — and refused if the stored content is no
  longer what was converted. Revisions, hooks and KSES are untouched.
- The backup is no longer optional. Clearing the checkbox still overwrote
  `post_content` and still deleted `_et_pb_use_builder` and
  `_et_pb_old_content`, leaving a conversion nothing could undo.

### Fixed — tests and CI

- A non-empty WordPress debug log now fails `bin/live-check.sh`,
  `bin/e2e.sh`, `bin/multisite-check.sh` and `bin/wp-matrix.sh`. All four
  printed the log with the word "warning" and then exited 0, which is what the
  workflow and the test guide had claimed was a failure for four releases.
- `bin/e2e.sh` runs the browser command as a tested condition, so `set -e` can
  no longer exit before the debug-log diagnostics on a browser failure.
- Added `bin/block-library-matrix.sh` and `tests/js/versions.json`: every
  fixture validated against the `@wordpress/block-library` that each of
  WordPress 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8 and 7.0.2 actually ships.
  The suite previously validated against 10.3.0, which no released WordPress
  uses — 7.0.2 ships 9.40.2.
- Added a live test for the mid-conversion save, and one holding the offline
  attribute encoder against `serialize_block_attributes()`. The second caught
  three real differences in the encoder the first time it ran.
- `bin/live-check.sh` and `bin/e2e.sh` clear a pending database upgrade on
  start. `bin/wp-matrix.sh` moves the shared environment between WordPress
  versions, which makes WordPress redirect every admin page to the upgrade
  screen — nine browser tests failed with "element not found" because that was
  what the browser was actually looking at.
- 12 new fixtures, one per defect.

### Changed

- Scanning no longer re-counts the whole posts table for every page of results.
  The count is taken on page 1 and cached for the pager. The underlying leading
  wildcard scan is unchanged and still bounded only by the size of `wp_posts`.
- The backup checkbox is gone from the Tools screen, replaced by a statement
  that every conversion is backed up.

---

## [2.2.0] — 2026-08-05

Security and content-integrity release, following a second external review
(`CODEX-REVIEW.md`, answered in `CODEX-REVIEW-RESPONSE.md`). That review found
one high-severity injection path and several content-loss paths that 2.1.0's own
test suite could not see.

Unlike every release before it, this one was validated against real WordPress
rather than reasoned about. It runs on 6.1, 6.2, 6.3, 6.8 and 7.0.2, on single
site and multisite; its output is checked by WordPress's own block validator;
and its admin screen is driven in a browser. `Requires at least` and
`Tested up to` are measurements.

Doing that measuring is what found most of what is fixed below — including an
injection path, a `Requires at least` that was simply wrong, a block that was
invalid on every converted page, and silent content destruction on multisite.

**One caveat stands:** no page from a real Divi site has ever been converted.
Every fixture here was written by someone who already knew what the converter
does, which is a blind spot no amount of self-written testing closes. Convert a
copy of your site before you convert your site.

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

### Fixed — content loss on multisite

On a multisite network only super admins hold `unfiltered_html`, while any site
administrator holds `manage_options` and can therefore reach this plugin. Every
write by such a user goes through KSES.

Measured on WordPress 7.0.2, with a real network and a real non-super-admin site
administrator: a Divi Code module holding a tracking script converts to a
`core/html` block, and KSES stores

    <!-- wp:html --> window.track=1; <!-- /wp:html -->

where the conversion produced

    <!-- wp:html --> <script>window.track=1;</script><iframe src="…"></iframe> <!-- /wp:html -->

The script tags are gone and their JavaScript is left as visible text on the
published page; the iframe is deleted. The conversion reported success.

Conversion and restore now compare their output against what KSES would store
and **refuse rather than write**, naming the elements that would be removed.
Harmless differences are excluded: KSES rewrites `<br/>` as `<br />`, which
accounts for 19 of the 24 fixtures it touches and which core's block validator
tolerates — treating those as damage would have refused nearly every conversion
on a network for no reason.

Bypassing KSES with a direct database write was considered and rejected. The
capability exists to stop users storing markup the site does not trust them
with, and a plugin is not entitled to overrule it. A super admin can convert the
same page, and site administrators can still convert ordinary pages.

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

[Unreleased]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.6.0...HEAD
[2.6.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.5.0...v2.6.0
[2.5.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.4.0...v2.5.0
[2.4.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.3.1...v2.4.0
[2.3.1]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/johnjanney/block-converter-for-divi/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/johnjanney/block-converter-for-divi/releases/tag/v1.0.0
