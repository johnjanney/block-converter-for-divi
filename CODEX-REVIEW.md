# Codex Repository Review

**Review date:** 2026-08-05

**Reviewed revision:** `4462a8b` (`main`)

**Reviewed release:** `v2.1.0` / plugin version `2.1.0`

**Prior baseline:** `709bd85` (`v2.0.0`)

**Response under review:** `CODEX-REVIEW-RESPONSE.md`

## Executive assessment

Version 2.1.0 is much safer than version 2.0.0. The response fixed the original
backslash-loss defect, the write-once backup defect, the self-closing-shortcode
parser defect, the false batch success count, and several block-markup defects.
It also added a useful standalone fixture suite.

The response does not prove that the plugin is ready for production. A fresh
review found a high-severity HTML attribute injection path and a high-severity
block-serialization defect. It also found other content-loss paths that the new
suite does not detect. The plugin has still not run in a live WordPress block
editor.

**Release decision:** Do not submit version 2.1.0 to WordPress.org. Do not
recommend it for production migration. Mark the GitHub release as a pre-release,
or publish a corrected version before more users download it. Keep the existing
ZIP for release history. Do not overwrite it.

The primary objective is not yet met. The project brief requires all of these
results:

- No block validation errors.
- No loss of text, images, links, or embedded media.
- Clear notice for every lossy module.

The current code does not meet these criteria. The failures are shown in N-01,
N-02, N-03, and N-05 below.

## Facts, inferences, and unknowns

### Verified facts

- The working tree was clean before this review file was changed.
- Version values agree at `2.1.0` in the plugin header, `D2G_VERSION`, and
  `Stable tag`.
- The public GitHub release is `v2.1.0`.
- The local release ZIP has SHA-256
  `34a1b4a01be353c329c94ad35ce291bdf2d527d581e435008f8acd2151c05ad1`.
  The GitHub release API reports the same digest and the same 71,905-byte size.
- The current 45-test standalone suite passes on PHP 7.4, PHP 8.1, and PHP 8.5.
- PHP syntax checks, the JavaScript syntax check, the shell syntax check,
  `git diff --check`, and ZIP integrity checks pass.
- WordPress 7.0.2 is the current stable security release on the review date.
  `readme.txt` still says `Tested up to: 6.8`. The project documents correctly
  say that this value is not a test result.

### Inferences

- The N-01 injection can become stored cross-site scripting when a user who can
  edit a target post supplies a crafted shortcode and an administrator with
  `unfiltered_html` runs the conversion. The exact path depends on site roles,
  filters, and the `DISALLOW_UNFILTERED_HTML` setting.
- The scan cost will increase with the size of `wp_posts`. No production query
  timing was available, but both scan queries use a leading-wildcard `LIKE` on
  `post_content`.

### Unknowns

- A real Divi page corpus was not found in the documents.
- Evidence for the statement that real Divi layouts never use `]` in an
  attribute value was not found in the documents.
- Independent evidence for the statement that the plugin has no install base
  was not found in the documents.
- Production database timing results were not found in the documents.
- A live WordPress conversion and block-editor validation result was not found
  in the documents.

## Assessment of `CODEX-REVIEW-RESPONSE.md`

The response is useful and detailed. It gives file-level reasons for most
changes. Its test record is reproducible. However, several summary claims are
not correct.

### Status-count conflict

The response says that 15 findings are fixed and two are partly fixed. Its own
finding headings mark F-06, F-07, and F-11 as partly fixed. That is 14 fixed and
three partly fixed before any independent review. The F-09 text also says that
the KSES part is not fixed, so F-09 cannot be fully fixed as originally scoped.
The GitHub release notes repeat the incorrect 15/2 count.

This review does not use that count. It verifies each finding separately.

### Original finding verification

| ID | Current status | Verification and clarification |
| --- | --- | --- |
| F-01 | **Verified fixed** | Backup, conversion, and restore now pass values through `wp_slash()` before WordPress unslashes them. See `block-converter-for-divi.php:505-511`, `553-573`, and `628-634`. The fixture for code backslashes also passes. |
| F-02 | **Core defect fixed; concurrency claim is partial** | The server refuses content that has no complete `et_pb_*` tag, and `_d2g_divi_backup` is write-once. This prevents the original second-pass backup overwrite. The transient lock is not atomic, and the source hash is optional. See N-04. |
| F-03 | **Partly fixed** | Text-module paragraphs, headings, lists, quotes, tables, and Details blocks are better. A Fullwidth Header still emits a Paragraph block with nested `<p>` elements. See N-02. No canonical WordPress validator was run. |
| F-04 | **Verified fixed** | Pricing features now become `core/list-item` blocks. Unknown `et_pb_*` modules are tokenized, stripped, preserved where possible, and reported. The pricing and unknown-module fixtures pass. |
| F-05 | **Partly fixed** | Contact forms now become visible rebuild instructions. The invalid `menuId` is gone. Portfolio output now warns about the Divi post-type dependency. The output is safer, but forms, menus, and portfolios are not automatic portable replacements. |
| F-06 | **Partly fixed** | The documents now state that most style mapping is absent. Only `text_align_class()` is used. The claim that lost styles are reported in Preview is false. See N-05. |
| F-07 | **Partly fixed** | The minimum WordPress value is now 6.0, Details is feature-detected, `mb_convert_encoding()` is gone, and `DOMDocument` is optional. `Tested up to: 6.8` is still unverified and is now behind current WordPress 7.0.2. No live compatibility matrix exists. |
| F-08 | **Partly fixed** | A standalone 45-test converter suite exists and gates the ZIP build. It does not test the AJAX endpoints, backup and restore, SQL, JavaScript batch control, uninstall, or a real block registry. Its body checks only inspect top-level blocks. See N-06. |
| F-09 | **Partly fixed** | Builder meta is now saved and restored for normal non-empty values. KSES loss remains open. Empty-but-present meta is not represented, and restore does not first clear both builder keys. It therefore does not always restore the exact original meta state. |
| F-10 | **Verified fixed** | Preview, convert, and restore now reject invalid IDs, revisions, autosaves, unsupported type/status values, and users without `edit_post`. The scan is still hard-coded to `page` and `post`, so filtered action allowlists do not fully mirror the scan. |
| F-11 | **Partly fixed** | “All” is capped at 500 by default, and truncation is shown. The count and data queries still use a leading-wildcard `LIKE`. See N-07. |
| F-12 | **Verified fixed** | JavaScript now tracks successes and failures separately. A transport failure and an application failure both reject the page promise and enter the final error list. |
| F-13 | **Partly fixed** | Detection now needs a complete lowercase tag, unknown tags are recognized, recursion is bounded, and self-closing matching is fixed. A `]` inside a quoted attribute still corrupts the tag. The fixture named for this case does not put the bracket in an attribute. See N-03 and N-06. |
| F-14 | **Verified fixed** | Status data, table cells, warning text, and controls are built with text nodes or jQuery `text`. No relevant `html()` data sink remains in `admin.js`. |
| F-15 | **Verified fixed** | The usage guide now describes per-site multisite deletion, and uninstall processes site IDs in batches of 100. |
| F-16 | **Partly fixed** | The dialog, focus handling, sort buttons, pagination buttons, live status, and admin-script localization are improved. Several converted-content defaults and placeholders remain hard-coded in English. The 2,280-line converter remains a single class. |
| F-17 | **Partly fixed** | The repository, releases, issues page, tag, and ZIP are public. The release ZIP digest is verified. Release documents still repeat incorrect fix counts and incorrect “every lossy module” claims. The readme has an unverified and stale `Tested up to` value. |

### New defects that the response found

The response lists three parser defects. All three fixes are present.

1. **Self-closing modules no longer consume the rest of a page.** The opening
   tag attribute match is lazy at `includes/class-d2g-parser.php:235-269`.
2. **Unknown modules no longer remain as raw shortcode text.** The tokenizer
   uses the general `et_pb_[a-z0-9_]+` shape at
   `includes/class-d2g-parser.php:217-250`, and the converter reports unknown
   modules at `includes/class-d2g-converter.php:2144-2166`.
3. **Self-closing tags no longer increase same-tag close depth.** The negative
   lookbehind is present at `includes/class-d2g-parser.php:275-305`.

The related regression fixture passes. This is good work.

## Fresh findings

### N-01 — High — Shortcode attributes can inject HTML attributes

**Area:** Security

`convert_image()` appends the raw `align` value to a quoted class attribute at
`includes/class-d2g-converter.php:852-880`. `convert_button()` does the same
with `button_alignment` at `includes/class-d2g-converter.php:898-934`.

This probe is valid input for the current parser because it supports
single-quoted shortcode attributes:

```text
[et_pb_image src="https://example.com/a.jpg" align='center" onmouseover="alert(1)' /]
```

The converter produced this saved markup:

```html
<figure class="wp-block-image size-large aligncenter" onmouseover="alert(1)">
```

The Button path produced the same attribute breakout. This is a verified output
defect. It can become stored cross-site scripting if the converted post is saved
without KSES filtering. WordPress says to use `esc_attr()` for every value that
is printed into an HTML attribute. KSES is not a sufficient primary control
because administrators can have `unfiltered_html`.

**Recommendation:**

1. Allow only supported values. For Image, allow only valid block align values.
   For Buttons, allow only `left`, `center`, `right`, and `space-between` as
   applicable.
2. Escape the complete class attribute with `esc_attr()`.
3. Validate CSS colors and other style values before use. `esc_attr()` does not
   make an arbitrary string a safe CSS value.
4. Add malicious single-quoted and double-quoted attribute fixtures.
5. Treat this as a security fix in the next release.

### N-02 — High — Fullwidth Header still creates invalid Paragraph blocks

**Area:** Purpose, correctness

`convert_fullwidth_header()` wraps the complete inner content in one Paragraph
block at `includes/class-d2g-converter.php:1100-1146`. It does not use
`html_to_blocks()`.

Input:

```text
[et_pb_fullwidth_header title="T"]<p>One</p><p>Two</p>[/et_pb_fullwidth_header]
```

Observed output:

```html
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center"><p>One</p><p>Two</p></p>
<!-- /wp:paragraph -->
```

Nested `<p>` elements are invalid HTML and do not match the core Paragraph save
contract. This is the same class of defect as original F-03. The new assertion
suite misses it because the Paragraph is inside a Group block.

**Recommendation:** Pass this content through `html_to_blocks()` with the
required center alignment. Search every renderer for raw content that is placed
inside one Paragraph block. Validate every result with the real WordPress block
registry.

### N-03 — High — Several parser and converter paths still change or lose content

**Area:** Purpose, correctness

The following results were reproduced:

- A table caption is discarded. `table_block()` copies only `thead`, `tbody`,
  `tfoot`, and loose `tr` children at `includes/class-d2g-converter.php:724-773`.
  Input caption text `Rates` did not occur in the output. `colgroup` has the same
  risk.
- HTML comments are discarded. `html_to_blocks()` ignores all DOM node types
  except text and elements at `includes/class-d2g-converter.php:521-541`.
- Nested module children can be discarded. `get_inner_content()` returns only
  loose text when any module child exists at
  `includes/class-d2g-converter.php:2205-2232`. A Button inside a Text module
  disappeared while the text before and after it remained. How often Divi emits
  this shape was not found in the documents.
- Counter body markup becomes visible escaped markup. An
  `et_pb_counter` body of `<p>Sales</p>` became visible `&lt;p&gt;Sales&lt;/p&gt;`
  because `convert_counter()` escapes the complete HTML string at
  `includes/class-d2g-converter.php:1596-1604`.
- A `]` in a quoted shortcode attribute corrupts output. A title value of
  `Array[0]` added visible `">` text before the body. This residual limit is
  documented, but the test that claims to cover it puts brackets in body text,
  not in an attribute.
- Parser normalization changes curly quote entities to straight quote
  characters at `includes/class-d2g-parser.php:102-112`. This is not a byte-safe
  content conversion.

**Recommendation:** Define a no-content-loss invariant. Add one fixture for each
case. Preserve a complex table as `core/html` when the Table block cannot store
all child content. Preserve comments in Custom HTML or document a deliberate
policy. Make parent renderers combine their loose content and rendered child
modules in source order. Replace the regex tag scanner with a quote-aware
scanner.

### N-04 — Medium — The write concurrency controls are incomplete

**Area:** Safety, data integrity

`acquire_lock()` calls `get_transient()` and then `set_transient()` at
`block-converter-for-divi.php:413-420`. Two requests can both read “no lock”
before either request writes it. This is a check-then-set race, not an atomic
lock.

The preview hash check at `block-converter-for-divi.php:480-484` runs only when
the client supplies a non-empty hash. Single conversion without Preview and
normal batch conversion send an empty hash at `admin/js/admin.js:512-518`.
There is also time between the check and `wp_update_post()`.

The write-once backup prevents the original backup-overwrite defect. However,
these controls do not fully prevent a conversion from overwriting a concurrent
edit.

**Recommendation:** Require an expected source hash for every write, including
batch writes. If Preview is optional, get a source token during Scan and refresh
it immediately before conversion. Use an atomic lock primitive, such as a unique
post-meta insert with an owner token, and release only the lock that the current
request owns. Re-read the post after lock acquisition.

### N-05 — Medium — Preview does not report all documented losses

**Area:** Purpose, user safety

The brief says that unsupported style settings are “reported rather than
silently dropped” at `BRIEF.md:39-50` and `BRIEF.md:192-205`. It also says that
losses are shown in Preview at `BRIEF.md:233-235`. Q22 repeats this claim at
`OPENQUESTIONS.md:24`.

No code detects those style attributes. A Section with `custom_padding` loses
the padding and produces no warning. Tabs become stacked Groups with no warning.
Sliders and video sliders lose slider behavior with no warning. Only gallery
carousel behavior gets a dedicated behavior-loss warning.

The changelog claim that “every lossy or unmapped module” is shown in Preview at
`CHANGELOG.md:182-190` is therefore false.

**Recommendation:** Add a per-module loss registry. It must report unsupported
style attributes and behavior changes. Show warnings in Preview and in the final
batch report. Change the documents now if full detection will come later.

### N-06 — Medium — The test gate gives more confidence than it provides

**Area:** Quality

The test suite is useful, but these limits are important:

- `tests/run.php` loads only the parser, style mapper, and converter. It does not
  load `block-converter-for-divi.php`, the admin PHP class, JavaScript behavior,
  or uninstall logic.
- `d2g_block_bodies()` checks top-level block bodies only at
  `tests/lib/assertions.php:107-140`. It does not run Paragraph, Heading, List,
  Table, or Quote body checks inside Group, Cover, Columns, Details, Gallery,
  Query, or other container blocks.
- The test parser checks delimiter balance and selected markup rules. It does
  not call WordPress `parse_blocks()` or the JavaScript `save()` validator.
- The test named “attribute values containing brackets do not truncate the tag”
  uses `Array[0] and [1]` in body content at `tests/fixtures.php:186-189`. It does
  not test a bracket in an attribute value.
- The response says that every review defect has a fixture. This is false. There
  are no fixture tests for the AJAX capability checks, SQL cap, backup writes,
  exact restore state, batch JavaScript, DOM injection fix, multisite uninstall,
  release documents, or localization.
- There is no CI workflow in the repository.

**Recommendation:** Keep the fast suite. Make its name and documentation clear:
it is a converter smoke and regression suite. Add recursive block checks. Add
PHP unit tests for endpoint helpers and backup state. Add JavaScript tests for
batch result logic. Add WordPress integration tests and a real block-editor
validator matrix for every supported WordPress version.

### N-07 — Medium — Scan performance remains unsuitable for large sites

**Area:** Performance

The response correctly marks this as partial. Each scan makes a total-count
query and a data query. Both use `post_content LIKE '%[et_pb_%'` at
`block-converter-for-divi.php:237-287`. The 500-row cap limits response memory,
but it does not limit the count scan. Each filter, sort, page, or page-size
change repeats both queries.

Other avoidable costs are present:

- The parser, converter, style mapper, and admin class are required on every
  front-end request at `block-converter-for-divi.php:35-38`, although the plugin
  has no front-end runtime feature.
- Attachment URL and metadata lookups occur once per image. Large galleries can
  create many database calls at `includes/class-d2g-converter.php:1153-1233`.
- Browser batch work is serial and has no resumable server-side job state.

**Recommendation:** Build an indexed or cached inventory in small keyset pages.
Store scan progress and expose it to WP-CLI. Load admin-only classes only for
admin and AJAX requests. Prime attachment caches for gallery IDs.

### N-08 — Medium — Compatibility and release metadata are not current

**Area:** Quality, distribution

The project openly states that `Tested up to: 6.8` is a placeholder. WordPress
7.0.2 was current on the review date, and WordPress 7.1 was in beta. A placeholder
that is also behind the current stable version gives no useful compatibility
signal.

The `Requires at least: 6.0` derivation is reasonable, but it is not a test. The
standalone suite passed on PHP 7.4, 8.1, and 8.5 during this review. It did not
prove compatibility with each WordPress release from 6.0 through 7.0.

PHP 7.4 reached end of life in 2022, and PHP 8.1 reached end of life in 2025.
Compatibility with PHP 7.4 can help users on old hosts, but a passing test does
not make that runtime secure. The usage documents should recommend a currently
supported PHP branch even if the technical compatibility floor stays lower.

**Recommendation:** Test at least the minimum supported WordPress version, the
current stable version, and the previous major version. Test PHP 7.4 and current
supported PHP branches. Record exact versions and results. Set `Tested up to`
only after that work.

### N-09 — Low — Backup meta restoration is not an exact snapshot in all cases

**Area:** Data integrity

`write_backup()` uses `get_post_meta( ..., true )` and stores only non-empty
values at `block-converter-for-divi.php:553-573`. It cannot distinguish a missing
key from a present key with an empty value. Restore writes saved keys but does
not first delete both managed builder keys at
`block-converter-for-divi.php:641-652`.

The normal Divi “builder on” path is improved and should work. The statement
“restored as found” is too strong for all possible meta states.

**Recommendation:** Store an explicit record for each key with `exists` and
`value`. On restore, delete both managed keys first, then add only the keys that
existed in the snapshot. Add tests for absent, empty, scalar, and repeated meta.

### N-10 — Low — Some user-visible converted text is not localized

**Area:** Localization, maintainability

The admin JavaScript has no hard-coded English messages. The converter still has
hard-coded defaults and placeholders. Examples include `Click Here`, `Tab`,
`Map:`, sidebar text, email-signup text, and WooCommerce placeholder text at
`includes/class-d2g-converter.php:889-934`, `1313-1323`, `1686-1723`,
`1894-1903`, `1930-1950`, and `2126-2138`.

The response claim that every string is localized is therefore too broad.

**Recommendation:** Localize every string that can appear in converted content.
Add a text-domain scan to CI. Split the converter into small module renderer
classes after the validation suite is strong enough to protect the refactor.

### N-11 — Low — User documents still contain smaller factual conflicts

**Area:** Documentation

- `BRIEF.md:129-143` says a single-column row can pass through. The code wraps
  every row that has one or more columns.
- `BRIEF.md:141` describes `core/summary`. WordPress has a Details block with an
  HTML `<summary>` element. There is no separate Summary block in this output.
- `INSTRUCTIONS.md:90` calls the capped option “all” without stating the
  500-row cap in that section.
- `INSTRUCTIONS.md:123` says the row button reads “Done”. The JavaScript sets it
  to the localized “Converted” value.
- `INSTRUCTIONS.md:186-191` says restore sets the builder to `on`. New backups
  try to restore captured builder meta instead.
- The manual single-page cleanup at `INSTRUCTIONS.md:305-312` leaves
  `_d2g_builder_meta` behind.
- `CHANGELOG.md:197-199` says an unused converter variable was removed, but
  `$price` is still unused at `includes/class-d2g-converter.php:1487`.

**Recommendation:** Correct these statements in the next documentation pass.
Use one generated module and requirements table when possible, so several files
do not need manual synchronization.

## Quality assessment

### Positive findings

- The code uses nonces and capability checks on all AJAX actions.
- SQL sort fields and directions use allowlists.
- Dynamic SQL values use `$wpdb->prepare()`.
- The original backup is now write-once.
- Conversion refuses empty output.
- Restore keeps the backup for another rollback.
- The admin DOM code now uses text nodes for server data.
- The interface has better keyboard and screen-reader behavior.
- The fixture suite is fast, dependency-free, and easy to run.
- The ZIP build checks the three version values and runs the suite.
- There are no runtime third-party package dependencies.
- A high-confidence secret-pattern scan found no credential pattern in current
  tracked files or git history. This is not a complete secret audit.

### Main quality limits

- Converter output is hand-built string markup across one large class.
- There is no canonical block validator.
- There are no endpoint, database, JavaScript, or uninstall tests.
- Several documents state results that the code does not implement.
- Error and warning coverage depends on the renderer author remembering to add
  a warning.

## Security assessment

The nonce, `manage_options`, and `edit_post` checks are good defense layers.
WordPress also states that nonces do not replace authorization, which this code
now follows.

The N-01 attribute injection is the main new security finding. Fix it before a
production release. The unresolved multisite KSES issue also needs a product
decision. Do not bypass KSES with a direct database write. A safer design is to
require `unfiltered_html` for conversions that need raw HTML, or to block and
explain the exact content that WordPress would remove.

No dependency audit was needed because the plugin has no Composer or npm runtime
dependencies. This does not cover WordPress core, the host, themes, Divi, or
other plugins.

## Performance assessment

Normal conversion of one small page should have acceptable CPU and memory cost.
This is an inference; no timing data was available. Large-site scanning is still
the main performance risk. Large pages with many DOM fragments and attachments
can also cause repeated parsing and database lookups.

The current hard cap is a useful safety limit. It is not a scalable inventory
design.

## Purpose assessment

The project has a valid purpose. It provides a migration aid for Divi shortcode
content and reduces lock-in. Its preview, backup, restore, and visible placeholder
approach are appropriate for a destructive migration tool.

The implementation is not yet a dependable automatic converter. It is best
described as an assisted migration tool that creates a first block draft for
manual review. The product text should use that description until all success
criteria have objective tests.

## Recommended implementation order

### Phase 0 — Protect users

1. Fix N-01 with strict value allowlists and full attribute escaping.
2. Mark v2.1.0 as a pre-release or publish a clear warning.
3. Fix N-02 and add its regression fixture.
4. Add the N-03 content-loss fixtures and fix each confirmed path.

### Phase 1 — Make validation real

1. Make block assertions recursive.
2. Run every fixture through the WordPress block parser and JavaScript save
   validator.
3. Add live editor tests on WordPress 6.0 and the current stable release.
4. Add endpoint, backup, restore, batch, and uninstall tests.
5. Add CI for supported PHP and WordPress versions.

### Phase 2 — Make migration state reliable

1. Make the lock atomic.
2. Require a source token on every conversion.
3. Store exact builder-meta existence and values.
4. Decide how to handle `unfiltered_html` and KSES before write and restore.
5. Add a downloadable per-page migration report.

### Phase 3 — Make scope and performance accurate

1. Add complete style and behavior loss reporting.
2. Correct all document conflicts.
3. Replace the repeated wildcard scan with a resumable inventory.
4. Add a WP-CLI path for large migrations.
5. Load conversion and admin code only when it is needed.

## Verification record

### Passed

```text
PHP lint, all 10 PHP files                 PASS (PHP 8.5.6)
JavaScript syntax, admin/js/admin.js       PASS (Node 24.14.0)
Shell syntax, bin/build-zip.sh             PASS
Standalone fixtures, PHP 7.4              45 passed, 0 failed
Standalone fixtures, PHP 8.1              45 passed, 0 failed
Standalone fixtures, PHP 8.5              45 passed, 0 failed
git diff --check                           PASS
ZIP integrity                              PASS
Release/local ZIP SHA-256 match            PASS
High-confidence tracked secret patterns   0 matches
High-confidence history secret patterns   0 matches
```

### Fresh probes that failed product invariants

```text
Crafted Image align attribute              Injected onmouseover attribute
Crafted Button alignment attribute         Injected onmouseover attribute
Fullwidth Header with two paragraphs       Produced nested <p> elements
Plain table with <caption>Rates</caption>   Dropped caption text
Text module with nested Button              Dropped Button block
Counter with <p>Sales</p> body              Displayed escaped <p> tags
Bracket inside shortcode attribute         Corrupted output with visible "> text
Unsupported section padding                Lost with no warning
Tabs converted to stacked Groups           Behavior lost with no warning
HTML comment in Text module                 Dropped comment
```

### Not completed

- Live WordPress installation and conversion.
- Real block-editor open/save validation.
- WordPress 6.0 through 7.0 compatibility matrix.
- Multisite conversion, restore, and uninstall test.
- Production database performance test.
- Real Divi page corpus test.
- Visual comparison with Divi output.

## Sources

### Repository sources

- `CODEX-REVIEW-RESPONSE.md`
- `block-converter-for-divi.php`
- `includes/class-d2g-parser.php`
- `includes/class-d2g-converter.php`
- `includes/class-d2g-style-mapper.php`
- `admin/class-d2g-admin.php`
- `admin/js/admin.js`
- `uninstall.php`
- `tests/bootstrap.php`
- `tests/fixtures.php`
- `tests/lib/assertions.php`
- `tests/run.php`
- `BRIEF.md`, `CHANGELOG.md`, `INSTRUCTIONS.md`, `OPENQUESTIONS.md`,
  `README.md`, and `readme.txt`

### External primary sources

- [WordPress block Edit and Save contract](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/)
- [WordPress block markup representation](https://developer.wordpress.org/block-editor/getting-started/fundamentals/markup-representation-block/)
- [WordPress escaping guidance](https://developer.wordpress.org/apis/security/escaping/)
- [WordPress nonce guidance](https://developer.wordpress.org/apis/security/nonces/)
- [`current_user_can()` reference](https://developer.wordpress.org/reference/functions/current_user_can/)
- [`wp_update_post()` reference](https://developer.wordpress.org/reference/functions/wp_update_post/)
- [`wp_insert_post()` sanitization reference](https://developer.wordpress.org/reference/functions/wp_insert_post/)
- [`wp_slash()` reference](https://developer.wordpress.org/reference/functions/wp_slash/)
- [`wp_filter_post_kses()` reference](https://developer.wordpress.org/reference/functions/wp_filter_post_kses/)
- [`map_meta_cap()` and `unfiltered_html`](https://developer.wordpress.org/reference/functions/map_meta_cap/)
- [Details block in WordPress 6.3](https://developer.wordpress.org/news/2023/12/styles-patterns-and-more-with-the-details-block/)
- [WordPress plugin readme rules](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [WordPress release history](https://wordpress.org/news/category/releases/)
- [PHP supported versions](https://www.php.net/supported-versions.php)
- [PHP end-of-life branches](https://www.php.net/eol.php)
- [Public GitHub v2.1.0 release](https://github.com/johnjanney/block-converter-for-divi/releases/tag/v2.1.0)
- [Public GitHub issues page](https://github.com/johnjanney/block-converter-for-divi/issues)
