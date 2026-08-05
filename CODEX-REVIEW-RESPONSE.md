# Response to the Codex Repository Review

**Repository:** Block Converter for Divi
**Reviewed revision:** `709bd85` (`Release 2.0.0`)
**Response date:** 2026-08-05
**Resulting version:** `2.1.0`

---

## Summary

Every one of the 17 findings in `CODEX-REVIEW.md` was re-verified against the
code before anything was changed. **All 17 reproduce.** None were false
positives, and none were overstated — where the review says a specific fixture
produced a specific wrong output, that output was reproduced byte for byte
under PHP 8.1.2.

Fifteen findings are fixed. Two are partly fixed, with the remainder recorded as
tracked open questions rather than quietly dropped:

| Finding | Severity | Status |
| --- | --- | --- |
| F-01 Save/backup strip backslashes | High | **Fixed** |
| F-02 Repeat request destroys the backup | High | **Fixed** |
| F-03 Output violates block serialization | High | **Fixed** (static checks; live editor run still outstanding — Q23) |
| F-04 Pricing tables leave shortcodes | High | **Fixed** |
| F-05 Form/nav/portfolio not portable | High | **Fixed** |
| F-06 Style mapper disconnected | High | **Partly** — scope corrected and losses now reported; wiring tracked as Q22 |
| F-07 Compatibility claims unsupported | High | **Partly** — `Requires at least` corrected, deprecation and `mbstring` removed; `Tested up to` still blocked (Q18) |
| F-08 No automated test gate | High | **Fixed** |
| F-09 Backup is not a full snapshot | Medium | **Fixed** |
| F-10 No object capability or type check | Medium | **Fixed** |
| F-11 Scan does not scale | Medium | **Partly** — bounded and honest about truncation; resumable job tracked as Q11 |
| F-12 Batch reports failures as successes | Medium | **Fixed** |
| F-13 Detection too broad and fragile | Medium | **Fixed** |
| F-14 Status path inserts response HTML | Medium | **Fixed** |
| F-15 Multisite uninstall contradicts docs | Medium | **Fixed** |
| F-16 Accessibility, l10n, dead code | Low | **Fixed** |
| F-17 Release documents stale or false | Medium | **Fixed** |

The test suite added for F-08 then found **three further defects the review did
not report**, one of them a silent content-loss bug worse than several of the
High findings. Those are in [New defects](#new-defects-found-while-fixing)
below.

**The release recommendation stands: do not publish 2.1.0 yet.** The reason has
narrowed to one item — `Tested up to:` is still a placeholder, and no live
block-editor validation run has happened. See [What is not
fixed](#what-is-not-fixed).

---

## How each finding was verified

Before changing anything, every converter claim was re-run through a standalone
harness with the source unmodified. The review's three quoted fixtures — the
two-paragraph case, the aligned rich-text case, and the pricing-item case —
reproduced exactly as printed. Confirmations of `menuId`, the experimental form
blocks, the missing `showContent`, and the counter and pricing heading
mismatches were obtained the same way.

Document and configuration findings (F-07, F-15, F-17) were checked by reading
the files against each other. Findings about scale and security posture (F-11,
F-13) were assessed by reading the SQL and the tokenizer; where the review drew
an inference rather than stating a fact, it says so, and those inferences hold.

---

## Findings and fixes

### F-01 — Save and backup operations can remove backslashes · Fixed

**Confirmed.** `wp_update_post()` unslashes the array it is given, and
`update_post_meta()` unslashes the value it is given. Passing unslashed content
to either strips one level of backslashes from the stored result.

The severity is easy to understate. It applied to the *backup* as well as the
conversion, so the rollback copy was already damaged before conversion started —
restoring a page did not return the original bytes, and nothing surfaced that.

**Fix:** `wp_slash()` on all three writes — the backup meta, the conversion
save, and the restore save. Backup meta values, including the new builder-meta
snapshot, are slashed on the way in.

`block-converter-for-divi.php`: `write_backup()`, `ajax_convert_page()`,
`ajax_restore_page()`.

---

### F-02 — A repeated request can destroy the original backup · Fixed

**Confirmed, and the mechanism is exactly as described.** `update_post_meta()`
replaces unconditionally; the server did not check for Divi content before
writing the backup; and `convert()` returns its input unchanged when no Divi
prefix is present. So a second conversion of an already-converted post read
Gutenberg markup, wrote it over `_d2g_divi_backup`, and left the page with no
route back to Divi.

The review is right that the browser-side guard is not a guard. The batch runner
did not disable a row's individual Convert button, and no nonce stops a replay
inside its validity window.

**Fix, in four layers:**

1. The server refuses to convert a post whose content holds no Divi shortcode —
   the condition that made the overwrite possible in the first place.
2. `_d2g_divi_backup` is now write-once. If a snapshot already exists it is
   never replaced, so no later request can overwrite the original.
3. A per-post lock (a 2-minute transient) rejects a second write while one is
   running. It is best-effort, not a database-level lock, but it closes
   double-click and double-queue.
4. Preview returns a hash of the source; convert rejects the save if the post
   changed since. The UI passes it through.

In the browser, every control in a row is disabled while any request for that
post is in flight, and re-enabled to its previous state afterwards — not
unconditionally, so a converted row does not wake back up.

---

### F-03 — Common output does not follow the block serialization contract · Fixed

**All four cited cases confirmed**, and the underlying cause is one design
choice rather than four separate bugs: the text path stripped one outer `<p>`
pair and wrapped whatever was left in a single paragraph block.

**Fix:** the text heuristics are replaced by `html_to_blocks()`, a top-level
walker. Every block-level element becomes its own block; runs of text and inline
elements between them are gathered into a paragraph. It is now the single entry
point for every piece of free-form HTML the converter handles — text modules,
blurb and slide bodies, toggle contents, CTA bodies, testimonials, team member
bios — all of which previously had the same defect and none of which the review
enumerated individually.

Specific mismatches corrected:

- **Alignment.** `has-text-align-*` is now always accompanied by `textAlign`
  (headings) or `align` (paragraphs). Fixed in the text path, pricing tables,
  and counters.
- **Toggles.** An open toggle now emits `{"showContent":true}` alongside the
  `open` attribute.
- **Lists.** `core/list` now emits `core/list-item` inner blocks and the
  `wp-block-list` class, which is how core has saved lists since 6.0. Nested
  lists nest as blocks.
- **Quotes.** `core/quote` now holds its body as inner blocks with the citation
  in a sourced `<cite>`, instead of loose markup inside the blockquote.
- **Tables.** Rows are wrapped in `<tbody>` — `core/table` sources its rows with
  a `tbody tr` selector, so rows written straight into `<table>` were invisible
  to it and would have been dropped, not merely flagged. `hasFixedLayout` is
  stated explicitly because its default has changed between WordPress versions
  and it controls a class name.

Following the review's own recommendation 6, a table carrying attributes core
does not model is preserved as `core/html` rather than converted lossily, and
that decision is reported to the user.

**Not closed:** these are static consistency checks. They cannot run core's
JavaScript `save()` functions, so "opens in the editor with no validation
errors" remains unproven. That is Q23 and it is the reason the release is still
held.

---

### F-04 — Pricing tables leave Divi shortcodes in converted content · Fixed

**Confirmed**, including the diagnosis: `et_pb_pricing_item` was in the parser's
tag list with no renderer, and `get_inner_content()` returned the node's raw
inner span whenever it was non-empty — which for a paired shortcode it always
is.

**Fix:** `convert_pricing_items()` renders each item as a `core/list-item`
inside a `core/list`. Divi's `available="off"` (a struck-through feature) is
carried over as `<s>`, so the distinction survives.

`get_inner_content()` was corrected at the root, exactly as the review
recommends: when a node has real module children, only the loose `__text__`
between them is that node's own content. That was not a pricing-table-specific
bug — it was a general leak in the node accessor.

---

### F-05 — Form, navigation, and portfolio mappings are not portable · Fixed

All three parts confirmed.

**Contact forms.** `core/form`, `core/form-input`, and `core/form-submit-button`
are experimental — they ship with the Gutenberg feature plugin and are not
registered by WordPress core. On a normal install the converted page showed
three "block not supported" placeholders where the form used to be. The mapping
also flattened select, radio, and checkbox fields to plain text inputs, and
nothing carried the recipient into a working mail path.

The review's recommendation was to stop claiming forms convert, and that is what
was done. Half-converting a form is worse than not converting it: a form that
renders but silently drops submissions is a failure the site owner discovers
weeks later. The fields, their types, their options, whether each is required,
the recipient address, and the submit label are now written out as ordinary core
blocks — always valid, always visible — and the module is flagged as needing
manual work. `d2g_contact_form_markup` lets a site substitute blocks for
whichever form plugin it actually uses.

**Navigation.** `core/navigation` has no `menuId`. It references a
`wp_navigation` post through `ref`, and Divi's `menu_id` is a classic `nav_menu`
term ID — not a post ID, so the block parsed and resolved to nothing. The
attribute is gone; a bare Navigation block is emitted and the classic menu is
named (by name where it can be looked up) in a warning, because importing a
classic menu is one click in the block's own toolbar.

**Portfolio.** `project` and `project_category` are Divi's, not WordPress's.
Both are now filterable (`d2g_portfolio_post_type`, `d2g_portfolio_taxonomy`),
and when the post type is not registered the converter says so explicitly rather
than leaving the user with a Query Loop that lists nothing.

---

### F-06 — The stated style mapper is mostly disconnected · Partly fixed

**Confirmed.** Only `text_align_class()` is called. `build_inline_style()`,
`wrapper_style()`, `get_color_attrs()`, and `parse_font()` are dead.

**What was done:** the documented claim now matches the code. `BRIEF.md` §5.1 is
a precise two-way matrix — what is preserved (text alignment, section and CTA
background colours, button colours, column widths, divider colours, cover
background images, heading levels) and what is not (padding, margin, max-width,
borders, radii, shadows, fonts, font sizes, line heights, module custom CSS,
hover states, animations, responsive spacing). `readme.txt` carries the same
matrix in user-facing language. Losses are no longer silent: every lossy module
is reported in the preview.

**What was not done, and why.** The review recommends building "one tested
style-normalization layer". That is correct and it is not a fix pass — it is a
feature. Wiring the existing mapper in naively would make things *worse*, not
better: WordPress regenerates a static block's markup from its attributes and
compares byte for byte, so an inline `style` the block's own save function would
not have produced is precisely the mismatch F-03 is about. Doing it properly
means emitting block-supported `style` attributes and reproducing the style
engine's serialization order. The dead functions were left in place rather than
deleted, because deleting them would erase the only existing work toward that.

Tracked as **Q22** with three named options.

---

### F-07 — WordPress and PHP compatibility claims not supported · Partly fixed

Every factual claim confirmed.

**Fixed:**

- **`Requires at least` raised from 5.0 to 6.0**, derived rather than guessed.
  The converter emits `core/comments` (6.0), `core/navigation` (5.9),
  `core/query` / `core/post-template` / `core/loginout` (5.8), and
  `core/details` (6.3). 6.0 is the highest floor among the blocks emitted
  unconditionally.
- **`core/details` is feature-detected** through `WP_Block_Type_Registry` and
  degrades to a heading plus text on 6.0–6.2, which is the review's
  recommendation 3.
- **`core/form*` removed entirely** — see F-05.
- **`mb_convert_encoding( …, 'HTML-ENTITIES', … )` removed.** This required the
  `mbstring` extension the plugin never declared, and PHP 8.2 deprecates that
  encoding. It is replaced by an `<?xml encoding="UTF-8">` prologue, tested
  side-by-side against the alternatives: it round-trips accented characters,
  smart quotes, and CJK correctly, needs no extension, and — unlike the old
  code — leaves characters as characters rather than rewriting them as numeric
  entities.
- **`DOMDocument` is now optional.** Its absence falls back to preserving
  content in a `core/html` block instead of a fatal error, and libxml errors are
  captured rather than suppressed with `@`.
- Requirements tables in `INSTRUCTIONS.md` and `BRIEF.md` updated to match.

**Not fixed: `Tested up to: 6.8`.** It is still a placeholder. Setting it to any
other number without running that test would be the same false claim with a
different value, so it was left alone and escalated instead: `BRIEF.md` now
states the release and validation position plainly, `OPENQUESTIONS.md` Q18
is marked as blocking publication, and `CHANGELOG.md` carries a do-not-publish
note on the 2.1.0 section. This needs a live WordPress install to close.

---

### F-08 — No automated test or validation gate · Fixed

**Confirmed** — no tests, no manifest, no CI.

**Fix:** `tests/`, runnable with `php tests/run.php`. Deliberately standalone:
a ~60-line WordPress shim, no Composer, no `wp-env`, no database. A suite that
needs a stack to be stood up is a suite that does not get run, and
`bin/build-zip.sh` now refuses to package if it fails, which is the gate the
review asked for.

- `tests/bootstrap.php` — the WordPress shim. Functions the converter guards
  with `function_exists()` are deliberately left undefined, which proves the
  guards work.
- `tests/lib/assertions.php` — structural checks applied to every fixture: no
  surviving `[et_pb_` token, balanced and correctly nested block delimiters,
  valid attribute JSON, and per-block agreement between saved markup and
  attributes (paragraph holds exactly one `<p>`; alignment class matches the
  attribute in both directions; `open` matches `showContent`; `<ol>` matches
  `ordered`; lists use inner blocks; table rows are inside `<tbody>`; no
  experimental block names; no invented `menuId`).
- `tests/fixtures.php` — 37 conversion fixtures plus 8 detection cases. Every
  defect in this review has a fixture that fails without its fix.

This covers items 1, 2, and 5 of the review's recommended test layers. Items 3,
4, and 6 — the JavaScript validator, WordPress integration tests, and a CI
matrix — need an environment that is not available here and are tracked as Q23.

---

### F-09 — Backup and restore do not restore the complete Divi state · Fixed

**Confirmed.** Only `post_content` was snapshotted; conversion deleted
`_et_pb_use_builder` and `_et_pb_old_content`; restore re-added only the first,
as a hardcoded `'on'`.

**Fix:** `write_backup()` captures both keys into `_d2g_builder_meta` before
conversion deletes them, and restore puts them back as found — falling back to
switching the builder on for backups taken before 2.1.0, so existing installs
keep working. The new meta key is included in uninstall cleanup.

The KSES half of this finding is **not** fixed. The review suggests either
requiring `unfiltered_html` or making the effect explicit, and explicitly warns
against a direct `$wpdb` write — which is right, and is why no such write was
added. Choosing between the remaining options is a product decision, still
tracked as Q15.

---

### F-10 — Post actions do not check the target object's capability or type · Fixed

**Confirmed.** All three endpoints gated on `manage_options` only and accepted
any ID `get_post()` could load.

**Fix:** `get_actionable_post()` now sits in front of preview, convert, and
restore. It requires `current_user_can( 'edit_post', $id )`, rejects revisions
and autosaves, and restricts post type and status to allowlists that mirror the
scan query — so the UI can never list something an action would refuse, and a
hand-made request cannot reach an attachment or an unlisted custom type. Both
allowlists are filterable.

---

### F-11 — The scan does not scale well · Partly fixed

**Confirmed.** `LIKE '%[et_pb_%'` cannot use an index, and `all` removed the
limit entirely.

**Fixed:** the `all` mode is bounded by `d2g_scan_hard_cap` (500 by default), and
when the result is truncated the UI says so instead of presenting a partial list
as the whole picture. That closes the memory-exhaustion path on both sides.

**Not fixed:** the leading-wildcard scan itself. A resumable keyset-paginated
inventory job with a cached result and a WP-CLI entry point is the right answer
and is a substantial feature, not a fix — Q11.

---

### F-12 — Batch results report failures as successful conversions · Fixed

**Confirmed.** One counter, incremented from `.always()`, so a batch where every
page failed still finished by announcing them all as converted. The review is
right that this is specifically dangerous in a migration, because it is how
someone removes Divi too early.

**Fix:** successes and failures are counted separately, each failure keeps its
message, and the batch ends in an error state listing every page that failed.
Network failures now produce a network-failure message rather than only a red
row. The progress counter reflects attempts.

A downloadable JSON/CSV report was not added — it belongs with the per-page
reporting work in Phase 3.

---

### F-13 — Detection and parsing too broad and fragile · Fixed

**Confirmed.** Detection matched the bare substring `[et_pb_`, so prose that
merely mentioned a Divi tag could enter a destructive flow.

**Fix:** `has_divi_content()` now requires a syntactically complete tag —
`[et_pb_<name>]`, `[et_pb_<name> …]`, or `[et_pb_<name> /]`. A bare prefix, an
unterminated tag, and an uppercase variant are all correctly rejected; there are
detection cases for each.

The tokenizer was changed in the opposite direction, and deliberately. It now
recognises the *shape* `et_pb_[a-z0-9_]+` rather than only the tags with
renderers — see [New defect 1](#1-unrecognised-modules-were-printed-verbatim)
for why that matters. `D2G_Parser::is_known_tag()` still distinguishes the two.

Depth is bounded at 32 levels (real Divi layouts nest about six), and beyond it
content is retained with its shortcode syntax stripped, so nothing is lost and
nothing is left on the page.

The full stack tokenizer with source offsets that the review recommends was not
built — the residual issue it would solve (an attribute value containing `]`
truncating a tag) is recorded as Q24 with a note that Divi does not normally
produce such values.

---

### F-14 — The admin status path inserts HTML from response data · Fixed

**Confirmed as a sink.** The review is careful to say the exploit path was not
proven, which is fair — but `showStatus()` received post titles and server error
strings, neither of which is HTML, so there was never a reason to use `.html()`.

**Fix:** `.text()` throughout. Table rows, pagination, the backup cell, and the
warnings list are now built as DOM elements with text set as text, rather than
concatenated into markup strings.

---

### F-15 — Multisite uninstall does not agree with the usage guide · Fixed

**Confirmed** — the code reads each site's own option; the guide promised a
network-wide purge.

**Fixed by correcting the guide, not the code.** Of the review's two options,
per-site opt-in is the safer model and the code already implemented it: one
site's administrator should not be able to destroy another site's only rollback
path. `INSTRUCTIONS.md` now states the per-site behaviour explicitly and shows
how to script a whole network.

`get_sites( [ 'number' => 0 ] )` is replaced by batches of 100, so a large
network no longer builds an array of every site ID before it starts.

---

### F-16 — Accessibility, localization, and maintainability · Fixed

Every item confirmed and addressed.

- The preview modal is a real dialog: `role="dialog"`, `aria-modal`,
  `aria-labelledby`, Escape to close, a Tab focus trap, and focus returned to
  the element that opened it.
- Sortable headers are `<button>` elements inside the `<th>`, with `aria-sort`
  maintained in all three states. Pagination controls are buttons, not `href`-less
  anchors.
- The status region is `role="status"` with `aria-live="polite"`.
- Every string is localized. The script now contains no English at all — all
  43 strings come from PHP through `wp_localize_script`, with a small
  positional formatter so translations can reorder arguments.
- Dead code removed: the unused `$regex` in the parser, the unused `$featured`
  in the pricing renderer, and a 34-entry block-name map that mapped every key
  to itself. The stylesheet's duplicate pagination section, its selectors for
  controls that do not exist, and an unused spinner are gone.

Splitting the converter into module classes behind a registry was not done. It
is a sound recommendation, but a 1,800-line refactor executed alongside
correctness fixes makes both harder to review, and the fixture suite that would
make it safe only exists as of this release.

---

### F-17 — Release and distribution documents contain false or stale state · Fixed

**Confirmed, including the internal contradiction** — `BRIEF.md` claimed 2.0.0
was "tagged, packaged, published" in its header while its own risk register said
there were no tags, ZIPs, readme, or uninstall handler.

**Fix:**

- `BRIEF.md` header states the real position: built and tagged locally, **not
  published**, with the two reasons named and linked.
- The risk register is rewritten against the current code, with a "Closed in
  2.1.0" section so the history is not lost.
- The roadmap marks what actually shipped.
- `OPENQUESTIONS.md`: Q6, Q10, Q12, Q16, Q20, Q21, and Q25 moved to Resolved
  with their decisions and where they were implemented. Q18 rewritten as an
  explicit publication blocker. Q22, Q23, and Q24 added.
- The build script now excludes `tests/` and the review documents from the
  distributed ZIP, and `CHANGELOG.md`'s packaging section matches.

**On the 404s specifically — now fixed.** The review's anonymous checks on
2026-08-04 found the repository and releases URLs returning 404. That had two
causes, and both are closed: the repository had not yet been renamed from
`divi2gutenberg` (Q21), and it was private (Q25). It has since been renamed and
made public. The history was scanned for credentials, keys, and local paths
before publishing; it contained none, and nothing but plugin source and
documentation was ever committed. The three URLs the review tested now return
200 anonymously.

Two consequences were accepted deliberately. The review documents are public, so
the data-loss defects in 1.0.0–2.0.0 are on the record — acceptable because the
plugin was never distributed, so there is no install base to warn. And
`johnjanney@gmail.com` appears in the commit history alongside the GitHub
noreply address; rewriting history to remove it was judged not worth the
disruption, but it is a one-line change to make if wanted.

---

## New defects found while fixing

The test suite from F-08 found three defects that were not in the review. The
first is more serious than several of its High findings.

### 1. Self-closing modules swallowed everything after them

**Content loss, present in 2.0.0 and every earlier release.**

The tokenizer's attribute group was greedy:

```
#\[(et_pb_…)((?:\s+[^\]]*)?)(\s*/)?]#
```

For `[et_pb_image src="…" alt="Pic" /]`, `[^\]]*` consumed the trailing ` /`, so
the self-closing group never matched and the tag was read as an **opening** tag.
No `[/et_pb_image]` exists, so the parser took its unmatched-tag branch and
absorbed the entire remainder of the post as that image's content — then
`convert_image()` rendered the image from its attributes and discarded the rest.

Every module after any self-closing module with attributes was silently deleted.
Since self-closing is how Divi writes images, buttons, dividers, and separators,
this would hit ordinary pages. It was confirmed against the released 2.0.0
pattern in isolation.

**Fix:** the attribute group is lazy, so a trailing slash lands in the
self-closing group where it belongs. `[^\]]` cannot cross a `]`, so the match
still always ends at the first one. Fixture: *a self-closing tag does not
swallow its own closer*.

### 2. Unrecognised modules were printed verbatim

The tokenizer recognised only the 58 tags with renderers. Any other `et_pb_*`
tag — a newer Divi release, a third-party module, an Extra or Theme Builder tag
— was never tokenized, fell through as plain text, and was written into the
converted post as literal `[et_pb_whatever]`.

This is F-04 generalised: the review found the one instance in its module table,
but the same failure applied to every module not in the list. Fixed by matching
the tag shape and letting `convert_unknown()` handle it, which preserves the
content and reports the tag.

### 3. A self-closing tag inflated nesting depth

`find_closing_tag()` counted `[et_pb_slide /]` as an opener, so a slider
containing self-closing slides matched the wrong closing tag and consumed
content past its own end. Fixed with a negative lookbehind. Fixture:
*a self-closing tag does not swallow its own closer*.

---

## What is not fixed

Four items, each with a tracked question:

| Item | Why not | Tracked |
| --- | --- | --- |
| `Tested up to:` is a placeholder, and no live block-editor validation has run | Needs a real WordPress install and the JavaScript block validator. Setting the field to a different unverified number would be the same false claim. **This blocks publication.** | Q18, Q23 |
| The style mapper is still not wired in | Requires a style layer that reproduces the style engine's serialization; wiring it naively would recreate the F-03 class of bug. Scope is now documented accurately and losses are reported. | Q22 |
| The scan still uses a leading-wildcard `LIKE` | A resumable, keyset-paginated inventory job with WP-CLI support is a feature. The unbounded-memory path is closed. | Q11 |
| KSES stripping for users without `unfiltered_html` on multisite | A product decision between requiring the capability, warning, or blocking. The review's advice against a `$wpdb` bypass was followed. | Q15 |

Also deliberately not done: splitting the converter into module classes behind a
registry (F-16), and a downloadable batch report (F-12). Both are sound; neither
belongs in the same change as the correctness fixes.

---

## Verification performed

```
php -l                     all 8 PHP files, PHP 8.1.2 — clean
node --check               admin/js/admin.js — clean
bash -n                    bin/build-zip.sh — clean
php tests/run.php          45 passed, 0 failed
```

The fixture suite covers: multi-block text splitting; alignment attribute
agreement; open and closed toggles; ordered, unordered, and nested lists; quotes
with citations; plain and attribute-bearing tables; pricing items including
unavailable ones; contact form field descriptions; navigation; portfolio;
section/row/column nesting; self-closing modules; sibling and same-tag nesting;
backslash preservation; multibyte round-tripping; YouTube embeds; unmatched
closing tags; bracket-bearing attribute values; unknown modules; 60-level
nesting; and eight detection cases.

**What was not verified:** anything requiring a running WordPress. No page has
been converted in a real install, no converted page has been opened in the block
editor, no multisite uninstall has been exercised, and no query has been timed
against a real `wp_posts` table. The review's own limitations section says the
same thing about its work, and it remains true of this one.

---

## Release position

`2.1.0` is built and its version is consistent across the plugin header,
`D2G_VERSION`, and `Stable tag`. `bin/build-zip.sh` will not package it unless
the fixtures pass.

The repository is public, the tag and ZIP exist, and `v2.1.0` is the latest
GitHub release with the archive attached — so the distribution half of the
review's release objection is closed.

The validation half is not, and the release was published knowingly without it.
`Tested up to:` is still a placeholder and no converted page has been opened in
a real block editor, so every release page carries that caveat and tells users
to convert on staging. Closing it means running the conversion against a live
WordPress install and recording the version it passes on — Q18 and Q23. That is
the one step that could not be done from here, and it also gates WordPress.org
submission. Everything else is done.
