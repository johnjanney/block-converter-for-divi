# Codex Repository Review

**Review date:** 2026-08-05

**Reviewed local revision:** `86106f6` (`docs/ci-guarantees`)

**Equivalent remote `main` revision:** `7dca2f1`

**Reviewed tree:** `123940f4d73c17474c176e51ac10fa84be586d1a`

**Reviewed release:** `v2.2.0`

**Response under review:** `CODEX-REVIEW-RESPONSE.md`

The local revision and remote `main` have the same Git tree. Thus, this review
applies to the current remote `main` source even though the local branch has the
pull-request commit rather than the merge commit.

## Executive assessment

Version 2.2.0 is a large improvement over version 2.1.0. The response fixed the
specific N-01 through N-05 and N-09 through N-12 probes. Later commits also
added the live WordPress, browser, multisite, and version-floor tests that the
response says were still missing. The current source has good separation of
responsibilities, strong endpoint authorization, a useful layered converter
suite, an atomic plugin lock, source-version checks, exact builder-meta
snapshots, and a KSES refusal path.

The plugin is not yet a lossless automatic migration tool. A fresh probe found
that some structural renderers silently discard loose text and unexpected child
modules. The write path can still overwrite an external edit that occurs after
its source check. The block-attribute serializer does not use WordPress's safe
HTML-comment encoding. The scan still performs two leading-wildcard table
scans. The test scripts also warn, but do not fail, when WordPress writes to its
debug log.

The project documents correctly admit the most important product limit: no
page from a real Divi site is in the test corpus. The fixtures prove the cases
that the developers wrote. They do not prove that the parser covers the forms
that Divi has produced across releases, modules, and third-party extensions.

**Release decision:** Keep 2.2.0 as an assisted migration release. Do not
describe it as lossless. Do not submit it to WordPress.org until C-01, C-02,
C-03, and C-04 are fixed and a representative, anonymized Divi corpus is added.
Users must preview every conversion, keep the backup option on, inspect every
warning, open the result in the block editor, and compare the published page
before they remove Divi.

## Verified facts, inferences, and unknowns

### Verified facts

- The working tree was clean before this file was changed.
- The local revision and remote `main` have the same tree.
- The plugin header, `D2G_VERSION`, `readme.txt` stable tag, release tag, and ZIP
  all identify version 2.2.0.
- The local release ZIP is 107,467 bytes and has SHA-256
  `06b8f1e62fc323782d51f61520a383182fac716416fe4be449390421ac6b75a0`.
  The [GitHub v2.2.0 release](https://github.com/johnjanney/block-converter-for-divi/releases/tag/v2.2.0)
  reports the same size and digest.
- The release is public, final, and not marked as a pre-release.
- The current required-status-check list has 11 checks. Administrators can
  bypass it, and the branch is not required to be current with `main` before a
  merge. The latest [same-tree CI run](https://github.com/johnjanney/block-converter-for-divi/actions/runs/31067632155)
  passed.
- The fresh local converter suite passed 154 of 154 checks and sent 396 blocks
  through `@wordpress/block-library` 10.3.0.
- The fresh WordPress 7.0.2 live suite passed 16 of 16 checks. It stored all 138
  conversion fixtures, read them back, and validated 391 stored blocks.
- The fresh browser suite passed 9 of 9 checks.
- The fresh multisite suite passed 12 of 12 checks.
- A fresh floor check confirmed that WordPress 6.0 refuses the plugin as
  declared and WordPress 6.1 passes the 16 live checks.
- Both npm audits reported zero known vulnerabilities on the review date.
- PHP syntax, JavaScript syntax, shell syntax, ZIP integrity, whitespace, and a
  focused secret-pattern scan passed.
- WordPress 7.0.2 is a current stable security release on the review date. The
  official release post says it fixes one critical and one high-severity issue.
  See the [WordPress 7.0.2 release](https://wordpress.org/news/2026/07/wordpress-7-0-2-release/).

### Inferences

- C-01 can lose real content if Divi, a third-party module, hand-edited source,
  or old stored source puts an unexpected child in a structural parent. Whether
  normal Divi UI output uses these forms is not known.
- C-02 is a real optimistic-concurrency gap. It needs an external save in a
  short window, so it is less likely than the old missing-token defect.
- C-03 can interfere with HTML-comment embedding in consumers that treat raw
  `post_content` as HTML. WordPress's standard block parser accepted the probe,
  so a stored cross-site scripting result in the standard WordPress front end
  was not proved.
- Scan time will increase with the size of `wp_posts`. The exact increase is not
  known because no production timing data was supplied.

### Unknowns

- A real, anonymized Divi page corpus was **not found in documents**.
- A visual comparison between a Divi page and its converted page was **not
  found in documents**.
- Production database timing results were **not found in documents**.
- A fresh uninstall execution test was **not found in documents** and was not
  run in this review.
- Per-version JavaScript `save()` validation for WordPress 6.1 through 6.8 was
  **not found in documents**. The version matrix checks block registration and
  database behavior. It does not run each older block library's save contract.

## Assessment of `CODEX-REVIEW-RESPONSE.md`

The response is detailed and useful as a record of the work that created
version 2.2.0. Its code claims must be read as claims about the state when that
response was written. Later commits made its final “What is not fixed” section
stale. For example, the current repository now has live WordPress, endpoint,
database, browser, multisite, and compatibility tests.

### N-finding verification

| ID | Current verdict | Verification and clarification |
| --- | --- | --- |
| N-01 | **Specific fix verified** | Image and Button alignment values use fixed allowlists. Active mapped colors use `D2G_Block_Builder::css_color()`. The malicious fixtures pass. See `includes/class-d2g-block-builder.php:134-167`, `includes/renderers/class-d2g-renderer-media.php:49-103`, and `includes/renderers/class-d2g-renderer-content.php:38-90`. C-03 is a different block-comment serialization issue. |
| N-02 | **Verified fixed** | Fullwidth Header now sends its body through `render_inner_blocks()`. The nested-paragraph fixture passes. See `includes/renderers/class-d2g-renderer-content.php:198-245`. |
| N-03 | **Six specific paths fixed; broader invariant still fails** | Caption and `colgroup` tables fall back to Custom HTML, comments are handled, Text-module children keep source order, counters strip tags, the tag scanner is quote-aware, and body entities are no longer normalized. See `includes/class-d2g-html-converter.php:101-130`, `216-227`, and `389-453`; `includes/class-d2g-parser.php:117-119` and `250-395`; and `includes/class-d2g-converter.php:366-380`. C-01 shows that other parent renderers still discard content. |
| N-04 | **Partly fixed** | The plugin lock is now an atomic option-row `INSERT` with an owner token and stale-lock compare-and-swap. A source hash is mandatory, and the post is read again after the lock. This stops concurrent plugin requests. It does not make the final post update conditional on the same source, so an ordinary editor save can still land after the check. See C-02 and `block-converter-for-divi.php:440-535`, `676-733`. |
| N-05 | **Partly fixed by design** | A name-pattern registry now reports the documented unmapped style categories. Interactive renderers report lost behavior. Browser code now shows warnings even without Preview. The registry can still miss new or unusual attribute names, as Q28 states. See `includes/class-d2g-converter.php:188-254` and `admin/js/admin.js:423-471`, `592-605`. |
| N-06 | **Substantially fixed after the response** | The converter has fixture, recursive structure, golden, real block-validator, module-coverage, dispatch, determinism, and idempotence checks. Later commits added endpoint, database, restore, browser, multisite, and version-floor suites. Uninstall, older block-library save contracts, real Divi pages, visual fidelity, and some browser paths remain untested. See `tests/README.md`. |
| N-07 | **Partly fixed** | Front-end requests return before loading the converter. Gallery attachments are cache-primed. The scan is capped and paginated. The two leading-wildcard scans remain. See `block-converter-for-divi.php:35-55`, `203-347`, and `includes/renderers/class-d2g-renderer-media.php:234-296`. |
| N-08 | **Metadata and core compatibility now verified** | The plugin declares WordPress 6.1+, `readme.txt` says tested through 7.0, and fresh runs passed on WordPress 6.1 and 7.0.2. WordPress 6.0 was refused as declared. The historical matrix also covers 6.2, 6.3, and 6.8. The older JavaScript save-contract gap remains. |
| N-09 | **Verified fixed** | Builder meta records all values and whether a key existed. Restore deletes both managed keys before adding captured values. The live suite verifies restore byte identity for content. See `block-converter-for-divi.php:775-855`. |
| N-10 | **Verified fixed** | User-visible converter text is localized, the JavaScript strings come from PHP, and the old converter was split into an orchestrator, block builder, HTML converter, and seven renderer families. See `block-converter-for-divi.php:111-177` and `includes/load.php`. |
| N-11 | **Original corrections verified; current regression found** | The response corrected its listed document conflicts. The current `BRIEF.md` now says the admin screen was never opened even though the current browser suite exists and Q30 is closed. See D-01. |
| N-12 | **Verified fixed** | Divi attribute text is decoded once and then escaped for its output context. The three N-12 fixtures pass. See `includes/class-d2g-block-builder.php:169-193`. |

### Round-one F-finding verification

| ID | Current verdict | Current state |
| --- | --- | --- |
| F-01 | **Fixed** | Backup, conversion, and restore use `wp_slash()`. The live database and restore checks pass. |
| F-02 | **Core defect fixed; concurrency remains partial** | The first content backup wins and a second conversion is refused. See C-02 for the external-edit race. |
| F-03 | **Fixed for covered output** | Rich text is split into valid blocks and all current fixtures pass core validation. Older WordPress save contracts are still not tested in JavaScript. |
| F-04 | **Fixed** | Pricing items and unknown modules no longer remain as raw shortcodes. |
| F-05 | **Safer degradation, not full conversion** | Forms, menus, portfolios, maps, sidebars, shops, and signup modules use visible fallbacks or warnings where core has no portable equivalent. Manual rebuilding remains necessary. |
| F-06 | **Partly fixed** | Loss reporting is much better. Most Divi design settings are still not mapped. |
| F-07 | **Largely fixed** | The declared WordPress floor and current tested version have live evidence. Older block-library serialization remains unproved. |
| F-08 | **Largely fixed** | The test surface is now broad. Uninstall, real Divi pages, visual fidelity, some browser paths, and older save contracts remain open. |
| F-09 | **Fixed for the measured core paths** | Meta restoration is exact for current snapshots. KSES-destructive conversion and restore are refused on multisite. Custom content filters from other plugins were not tested. |
| F-10 | **Endpoint checks fixed; scan scope remains fixed** | Actions check nonce, site and object capability, type, status, revision, and autosave state. The scan UI is still hard-coded to `page` and `post`, while action allowlists are filterable. |
| F-11 | **Partly fixed** | Pagination and the 500-row “All” cap bound memory. The database search is still non-indexable. |
| F-12 | **Fixed** | Batch successes and failures are counted separately, and the browser suite checks a stale-row failure. |
| F-13 | **Fixed for covered syntax** | One quote-aware scanner now backs detection, parsing, closing-tag matching, and stripping. |
| F-14 | **Fixed** | Admin data is inserted with text nodes or jQuery `text`; no relevant data-driven `.html()` sink remains. |
| F-15 | **Code and documents fixed; execution untested here** | Multisite uninstall walks sites in batches and uses each site's retention setting. No fresh uninstall run was made. |
| F-16 | **Much improved** | The converter split, localization, dialog focus, sorting, status, and warnings are better. Pagination, select-all, larger batches, and some accessibility states still lack browser coverage. |
| F-17 | **Distribution fixed; document accuracy needs work** | The repository and release are public, the ZIP digest matches, and CI is required. D-01 and T-01 still overstate or contradict actual behavior. |

## Fresh findings

### C-01 — High — Structural renderers can silently discard content

**Area:** Purpose, correctness

`render_inner_blocks()` fixed content order for general content renderers. Some
structural parents do not use it. They iterate only the child tags that they
expect and ignore everything else:

- Tabs keep only `et_pb_tab` children at
  `includes/renderers/class-d2g-renderer-interactive.php:95-113`.
- Counters keep only `et_pb_counter` children at
  `includes/renderers/class-d2g-renderer-interactive.php:168-176`.
- Pricing Tables keep only `et_pb_pricing_table` children at
  `includes/renderers/class-d2g-renderer-pricing.php:31-40`.
- A Pricing Table with at least one pricing item drops its loose text and any
  other child modules at `includes/renderers/class-d2g-renderer-pricing.php:83-97`.
- Social Follow keeps only network children at
  `includes/renderers/class-d2g-renderer-content.php:320-332`.
- Video Slider skips a child unless it has a `src` attribute, even though the
  shared Video renderer can read an iframe or URL from the body. See
  `includes/renderers/class-d2g-renderer-media.php:153-174`.

Fresh input:

```text
[et_pb_tabs]Before[et_pb_button button_text="Keep" /]After[/et_pb_tabs]
[et_pb_pricing_table title="Plan"]Before
[et_pb_pricing_item]Feature[/et_pb_pricing_item]
After[et_pb_button button_text="Keep too" /][/et_pb_pricing_table]
```

The output contained neither `Before`, `After`, `Keep`, nor `Keep too`. No
warning named that loss. The tabs warning only said that tab behavior changed.

Whether canonical Divi output uses these shapes was **not found in documents**.
The plugin also promises content preservation for unknown and third-party
forms, so it must fail safely when a known parent has an unexpected child.

**Recommendation:** Give each structural renderer a source-order partition.
Map expected children normally. Render loose text and other children through
`render_inner_blocks()` or an equivalent iterator. Add a warning that names an
unexpected structure. Add one fixture for each filtering parent.

### C-02 — Medium — The final write is not an atomic source comparison

**Area:** Data integrity

The response correctly replaced the check-then-set plugin lock. It also made
the source token mandatory. The code now:

1. Takes the plugin lock.
2. Reads the post and compares its MD5 at
   `block-converter-for-divi.php:695-701`.
3. Writes a backup and converts content.
4. Calls `wp_update_post()` at `block-converter-for-divi.php:727-733`.

An ordinary editor does not take the plugin's option-row lock. That editor can
save after step 2 and before step 4. The conversion then overwrites the newer
edit. The current live concurrency test proves that two plugin operations do
not overlap. It does not simulate a normal post save in this window.

**Recommendation:** Add a test that saves the post from outside the converter
after the locked comparison and before the final write. Use a final compare and
swap, or another WordPress-compatible optimistic-write design, so the write can
only succeed while the stored source is still the source that was converted.
Do not bypass revisions, hooks, or KSES to implement it.

### C-03 — Medium — Block attributes are not serialized with WordPress's safe encoder

**Area:** Security, correctness

`D2G_Block_Builder::block()` inserts plain `wp_json_encode()` output into an
HTML comment at `includes/class-d2g-block-builder.php:30-53`. Blog and Search
also build comments directly at
`includes/renderers/class-d2g-renderer-dynamic.php:43-58` and `159-171`.

Fresh input:

```text
[et_pb_search placeholder="find--><img src=x onerror=alert(1)>" /]
```

Observed output:

```html
<!-- wp:search {"placeholder":"find--><img src=x onerror=alert(1)>"} /-->
```

WordPress provides `serialize_block_attributes()` for this exact context. It
escapes `--`, `<`, `>`, `&`, backslashes, and escaped quotes because those
characters can interfere with an HTML comment. See the official
[`serialize_block_attributes()` reference](https://developer.wordpress.org/reference/functions/serialize_block_attributes/).

The current JavaScript validator accepted this probe. This shows a test gap:
the validator checks parsed block validity, but it does not require canonical,
HTML-safe delimiter serialization. Standard WordPress front-end exploitation
was not proved in this review. A raw HTML consumer can interpret the first
`-->` as the end of the comment, so the output is not safe for all post-content
consumers.

The same consistency issue applies to URLs. For example, Image, Video, Audio,
and Social Link can keep `javascript:` in block attributes while `esc_url()`
removes it from the paired HTML. WordPress says to use `sanitize_url()` for
stored URL data and `esc_url()` for displayed URLs. See the official
[`esc_url()` reference](https://developer.wordpress.org/reference/functions/esc_url/).

**Recommendation:** Centralize all block comments in the builder. Use
`serialize_block_attributes()` in WordPress. Add an exact compatible fallback
to the standalone test shim. Sanitize URL block attributes before serialization
and use the same cleaned value in the HTML. Add `-->`, `<`, `&`, unsafe URL,
and comment/parser round-trip fixtures.

### C-04 — Medium — A dirty WordPress debug log does not fail CI

**Area:** Test quality

The workflow says the live job fails on any notice or deprecation at
`.github/workflows/tests.yml:122-127`. The test guide also says the debug log
must be empty at `tests/README.md:235-248`.

The scripts do not enforce this rule:

- `bin/live-check.sh:104-112` prints a warning and then reports success.
- `bin/e2e.sh:72-80` prints a warning and returns only the browser status.
- `bin/multisite-check.sh:66-74` prints a warning and returns only the suite
  status.
- `bin/wp-matrix.sh:135-139` prints log lines but does not change the verdict.

The logs were empty in this review. Thus, this did not hide a current runtime
warning. It can let a future PHP notice, warning, or deprecation pass a required
check.

**Recommendation:** Set a failure flag when the log is non-empty and exit
nonzero after diagnostics. In `e2e.sh`, run the browser command inside `if` or
with a temporary `set +e`; otherwise `set -e` can exit before the debug-log
diagnostics run on a browser failure.

### C-05 — Medium — The scan still performs two full content scans

**Area:** Performance

Each scan runs a count query and a data query with
`post_content LIKE '%[et_pb_%'` at `block-converter-for-divi.php:257-314`.
The leading wildcard cannot use a normal B-tree prefix lookup. Pagination and
the 500-row cap bound the returned data. They do not reduce the work needed to
find matching rows. Backup joins and `MD5(post_content)` add work to the data
query.

Production timing data was **not found in documents**.

**Recommendation:** Build a resumable inventory keyed by post ID and modified
time, or maintain indexed detection meta when a post changes. Keep a repair or
rebuild command because external database imports can bypass WordPress hooks.
Until then, document an operational limit and test representative 10k, 100k,
and 1m-row datasets.

### C-06 — Medium — The destructive path still permits conversion without a backup

**Area:** Purpose, safety

The browser sends `backup=no` when the checkbox is clear at
`admin/js/admin.js:562-576`. The server then skips `write_backup()` at
`block-converter-for-divi.php:703-708`, but it still overwrites content and
deletes the Divi builder meta at `block-converter-for-divi.php:727-745`.

This behavior is documented, but it is not the safest implementation of the
project's backup and restore objective. WordPress revisions can be disabled or
pruned. A database backup might not be available to the person who ran the
conversion.

**Recommendation:** Make a first conversion backup mandatory. If an advanced
override is required, put it behind a separate, explicit confirmation and do
not delete builder meta without a snapshot. Keep the current write-once rule.

### C-07 — Medium — Older WordPress save contracts are not validated

**Area:** Compatibility, purpose

The version matrix proves that emitted blocks are registered and that endpoint,
database, restore, and KSES behavior works on the tested WordPress versions.
The JavaScript validator uses only `@wordpress/block-library` 10.3.0. It does
not run the save functions from WordPress 6.1, 6.2, 6.3, or 6.8. The test guide
states this limit at `tests/README.md:283-294`.

The fresh WordPress 6.1 run passed the live checks and registered all 32 emitted
block types. That is good compatibility evidence. It does not prove that every
stored static block matches the WordPress 6.1 editor's exact save markup.

**Recommendation:** Map each tested WordPress release to the matching block
library package and validate the stored fixture set with that version. At a
minimum, test the declared floor, each markup-changing boundary, and the
`Tested up to` release.

### D-01 — Low — Current documents contradict current test coverage

**Area:** Documentation

`BRIEF.md:274-281` says the admin screen has never been opened in a browser and
that no test covers the scan, preview, batch runner, progress, or error report.
The current browser suite covers those main paths, and `OPENQUESTIONS.md:46`
marks Q30 resolved. The fresh 9-test browser run passed.

`tests/README.md:287-294` points the older save-contract gap to Q18. Q18 is
already resolved and concerns the measured `Tested up to` value. This remaining
work needs a new open-question ID or no ID.

`CODEX-REVIEW-RESPONSE.md:810-827` also says there is no live WordPress run, no
endpoint or database test, and no KSES resolution. That was true at the response
checkpoint. It is false for current `main`. The response should be marked as a
historical checkpoint rather than rewritten as if it never made those claims.

**Recommendation:** Update the BRIEF risk register. Add a short “historical
response” note to the response header. Give the older save-contract work its
own tracking item.

### Q-01 — Low — The dead style mapper keeps unsafe code in the release

**Area:** Quality, security debt

The documents and coverage report say that only
`D2G_Style_Mapper::text_align_class()` is active. The other functions in
`includes/class-d2g-style-mapper.php` are dead. Some of that dead code builds
CSS from raw Divi values and appends `custom_css_main_element` directly at
lines 15-112.

This is not an active vulnerability because the converter does not call it.
It is a future trap: connecting the helper can restore the same CSS-injection
class that N-01 fixed in active renderers.

**Recommendation:** Delete the dead functions. If style mapping is later
implemented, build a new layer from block-supported attributes, strict value
grammars, and canonical block serialization.

## Quality assessment

### Strengths

- The refactor has clear boundaries. The parser, HTML conversion, block
  serialization, orchestration, and module families are separate.
- Renderer registration detects ownership collisions and has a golden dispatch
  snapshot.
- The parser uses one quote-aware scanner and has bounded recursion.
- The test design combines focused assertions, recursive structure checks,
  golden output, core block validation, module coverage, dispatch coverage,
  determinism, and idempotence.
- The live suite tests the endpoint and database boundary where earlier slash
  loss occurred.
- The browser test covers the old false-success batch defect.
- Comments explain the reason for difficult code, especially locks, KSES,
  source tokens, and block save contracts.
- User-visible server and browser text is localized.

### Limits

- The fixtures are implementation-aware and synthetic.
- Several structural renderers duplicate child-filter loops, which caused C-01.
- The main plugin file still combines bootstrap, scan SQL, locks, backup state,
  KSES policy, and all five endpoints in about 950 lines.
- The block builder does not own all block serialization. Blog and Search bypass
  it.
- The dead style mapper increases cognitive and security review cost.
- Static style and behavior loss is reported but not repaired.

## Security assessment

### Positive controls

- Every AJAX endpoint checks the nonce and `manage_options`.
- Post actions also check `edit_post`, post type, status, revision, and autosave
  state.
- Scan sorting and filters use allowlists, and SQL values use prepared
  statements.
- Admin-side data is inserted as text, not executable HTML.
- Active alignment and color outputs use allowlists or value grammars.
- The plugin refuses writes that core KSES would damage for users without
  `unfiltered_html`.
- The lock has an owner token, an atomic insert, stale-lock handling, and
  owner-only release.
- The plugin loads no public route, public script, or front-end filter.
- The release ZIP excludes tests, Node dependencies, wp-env files, and review
  documents.
- The focused present-tree and history secret scan found no credential pattern.
- npm reported zero known vulnerabilities in both dependency trees.

### Residual security work

- Fix C-03 with WordPress's block-attribute serializer and stored-URL
  sanitization.
- Remove the unsafe dead style builder in Q-01.
- Pin third-party GitHub Actions to reviewed commit SHAs. The current workflow
  uses mutable major tags at `.github/workflows/tests.yml:22-25`, `33-35`, and
  similar steps.
- Keep Custom HTML behavior explicit. Code and iframe preservation is a product
  requirement, but it also means a user with `unfiltered_html` can preserve
  active content. The current capability and KSES policy is reasonable.

WordPress's security handbook says to escape for the exact output context and
to escape late. See [Escaping Data](https://developer.wordpress.org/apis/security/escaping/).

## Performance assessment

### Good decisions

- Front-end requests return before loading the conversion classes.
- Result size is paginated and “All” is capped.
- Scan rows do not transfer full post or backup content.
- Gallery attachment caches are primed in one call.
- Batch writes run sequentially, which bounds write concurrency.
- Parser and DOM recursion have explicit limits.
- Production requests do not load Node packages or test code.

### Main cost

C-05 is the main performance risk. The count and data queries both search large
text values with a leading wildcard. The current design is reasonable for a
small one-time migration. It is not the best design for a large content store.
No production benchmark was available.

## Purpose assessment

The plugin now fulfills the narrower purpose stated in the current brief: it is
an assisted migration tool that produces a first Gutenberg draft and identifies
known manual work. It does not fulfill a stronger claim of automatic lossless
conversion.

Evidence that supports the narrower purpose:

- All declared module tags have at least one fixture.
- Current fixture output validates with the current block library.
- Stored output survives the live WordPress database path.
- Restore, KSES refusal, direct conversion warnings, and batch failure reporting
  have live or browser checks.
- The project states which styles and behaviors do not survive.

Evidence that blocks the stronger purpose:

- C-01 proves a remaining silent content-loss family.
- No real Divi corpus exists in the repository.
- No visual fidelity comparison exists.
- Most Divi design settings are not mapped.
- Some dynamic modules become instructions or placeholders.
- Gallery carousel behavior is not shipped.
- Portfolio output depends on a Divi post type unless the site migrates it.
- Backups remain optional.

## Recommended implementation order

### P0 — Prevent silent loss and unsafe serialization

1. Fix every filtering parent in C-01 and add source-order fixtures.
2. Replace plain JSON block attributes with
   `serialize_block_attributes()`-compatible output.
3. Sanitize stored URL attributes and use one cleaned value for attributes and
   HTML.
4. Add a final atomic source condition to the conversion write.

### P1 — Make the safety gate match its documentation

1. Make every non-empty WordPress debug log fail its suite.
2. Make first-conversion backup mandatory.
3. Add an uninstall integration test for single site and multisite.
4. Add per-version block-library validation for the declared floor and tested
   ceiling.

### P2 — Validate the actual product

1. Build an anonymized corpus from several Divi versions, common modules,
   third-party modules, malformed pages, and hand-edited shortcode.
2. Add semantic assertions for text, links, image references, media embeds,
   comments, and module order before and after conversion.
3. Add selected visual comparisons for representative layouts.
4. Benchmark scan and conversion on realistic database sizes.

### P3 — Reduce maintenance risk

1. Delete the dead style mapper code.
2. Move scan, lock, backup, and endpoint policy out of the main bootstrap class.
3. Replace repeated structural child loops with one tested traversal utility.
4. Correct the current document conflicts.
5. Pin CI actions to commit SHAs.

## Verification record

### Passed in this review

| Check | Result |
| --- | --- |
| PHP syntax, all tracked PHP | Pass on PHP 8.1.2 |
| JavaScript syntax, admin, browser, config, validator | Pass on Node 24.14.0 |
| Shell syntax, all `bin/*.sh` | Pass |
| Converter suite with required validator | 154 passed, 0 failed |
| Current core block validation | 396 blocks checked |
| Module coverage | 58 of 58 declared tags exercised |
| Golden snapshots | 140 files present and current |
| Live WordPress 7.0.2 | 16 passed, 0 failed |
| Stored conversion fixtures | 138 round trips unchanged |
| Stored block validation | 391 blocks checked, 0 invalid |
| Browser suite | 9 passed, 0 failed |
| Multisite KSES suite | 12 passed, 0 failed |
| WordPress floor probe | 6.0 refused as declared; 6.1 passed 16 checks |
| npm audit, root development tools | 0 known vulnerabilities |
| npm audit, block validator | 0 known vulnerabilities |
| ZIP integrity | Pass |
| ZIP/main source identity | Pass |
| Release ZIP digest and size | Match GitHub release |
| Focused secret-pattern scan | No match |
| Final `git diff --check` | Pass |

### Fresh probes that found defects

| Probe | Result |
| --- | --- |
| Button and text placed directly in Tabs | Content discarded; only empty Group emitted |
| Loose text and Button beside a Pricing Item | Loose text and Button discarded |
| Search placeholder containing `-->` and HTML | Raw comment terminator and HTML written into block attributes |
| `javascript:` media and social URLs | Unsafe value kept in block JSON while removed from paired HTML |

### Not run or not available

- The complete 6.0 through 7.0.2 matrix was not rerun. This review reran 6.0,
  6.1, and 7.0.2. The same-tree GitHub CI and prior matrix records cover the
  other versions.
- PHP 7.4 through 8.4 was not rerun locally. The same-tree required CI jobs
  passed.
- Line coverage was not remeasured because Xdebug or PCOV was not installed.
- Uninstall was not executed.
- A production-size database benchmark was not run.
- A real Divi corpus was not available.
- A visual comparison was not run.

## Sources

### Repository sources

- `BRIEF.md` — objectives, scope, architecture, module and style coverage, and
  risk register.
- `CODEX-REVIEW-RESPONSE.md` — claims and fixes under verification.
- `block-converter-for-divi.php` — endpoints, scanning, locks, KSES, backup,
  conversion, and restore.
- `includes/class-d2g-parser.php` — quote-aware shortcode scanner and parser.
- `includes/class-d2g-converter.php` — traversal, warnings, and inner-content
  services.
- `includes/class-d2g-block-builder.php` — block comments, escaping, alignment,
  colors, and attributes.
- `includes/class-d2g-html-converter.php` — HTML splitting, comments, lists,
  quotes, and tables.
- `includes/renderers/*.php` — module-specific conversions.
- `admin/js/admin.js` — scan UI, preview, warnings, conversion, restore, and
  batch status.
- `.github/workflows/tests.yml`, `bin/*.sh`, and `tests/README.md` — test and CI
  contracts.
- `tests/fixtures.php`, `tests/live/*.php`, and `tests/e2e/*.js` — current test
  coverage.
- `uninstall.php` — retention and multisite cleanup.

### External primary sources

- [WordPress: `serialize_block_attributes()`](https://developer.wordpress.org/reference/functions/serialize_block_attributes/)
- [WordPress Security Handbook: Escaping Data](https://developer.wordpress.org/apis/security/escaping/)
- [WordPress: `esc_url()`](https://developer.wordpress.org/reference/functions/esc_url/)
- [WordPress 7.0.2 security release](https://wordpress.org/news/2026/07/wordpress-7-0-2-release/)
- [GitHub: About protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches)
- [GitHub v2.2.0 release](https://github.com/johnjanney/block-converter-for-divi/releases/tag/v2.2.0)
- [GitHub same-tree CI run](https://github.com/johnjanney/block-converter-for-divi/actions/runs/31067632155)
