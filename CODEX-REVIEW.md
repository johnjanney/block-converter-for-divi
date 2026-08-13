# Codex Repository Review

**Review date:** 2026-08-12

**Reviewed revision:** `b751615f5b261322e58951be0ff5a60c0f02b39f` (`main`)

**Reviewed tree:** `98ac284fa70e67ffd86e112f881ec0719b6576a8`

**Reviewed release:** `2.9.3`

**Primary change records:** [`CODEX-REVIEW-RESPONSE.md`](CODEX-REVIEW-RESPONSE.md) and [`CHANGELOG.md`](CHANGELOG.md)

## Executive assessment

The project is a well-structured, admin-only migration tool. It scans WordPress
posts for Divi shortcodes. It previews a conversion. It then replaces the Divi
source with native WordPress block markup. It makes a mandatory backup and can
restore that backup. The code does not load on normal front-end requests
([`block-converter-for-divi.php`, lines 18-37](block-converter-for-divi.php#L18)).

The code quality and test depth are high for a small plugin. The fresh offline
gate passed 212 tests and checked 566 blocks. A fresh live WordPress run passed
50 tests, stored 193 conversions, validated 557 blocks, and wrote no debug-log
entry. The code has clear component boundaries, centralized block construction,
strict AJAX authorization, mandatory backups, a restore path, KSES-loss checks,
and broad fixture coverage.

The plugin must still be described as an assisted migration tool. It is not a
lossless or pixel-perfect converter. The real-site corpus covers only 13 of the
58 supported modules. The project documents this limit correctly
([`BRIEF.md`, lines 51-63](BRIEF.md#L51)).

This fresh review found no Critical issue. It found one High issue, seven Medium
issues, and two Low issues. The High issue is in the new diagnostic tool, not in
the release ZIP. The most important shipped-code issues are two remaining
concurrency windows and two video conversion defects.

## Review of the logged changes

### Verified facts

The round-three response is a historical record for version 2.3.0. It states
that seven of nine prior findings were fixed and that two were partly fixed
([`CODEX-REVIEW-RESPONSE.md`, lines 15-36](CODEX-REVIEW-RESPONSE.md#L15)). The
current release is 2.9.3. The main plugin header, `BCFD_VERSION`, and the stable
tag agree on that version.

The change log records these material changes after the prior review:

- Versions 2.4.0 through 2.6.0 added spacing, border, and typography mappings.
- Version 2.7.0 added a real imported corpus. It also fixed entity-encoded
  shortcode attributes, nested lists, gallery-loss reports, and Divi 5 counts.
- Version 2.8.0 added a server-side double-conversion refusal and more style
  mappings.
- Versions 2.9.0 and 2.9.1 added conversion census data and corrected an image
  count.
- Versions 2.9.2 and 2.9.3 repaired stale-backup behavior and added one explicit
  stale-token retry.

The current source contains these changes. The new work after the 2.9.3 tag is
mostly diagnostic and test work for the unresolved quote-encoding question. It
adds [`bin/diagnose-encoding.php`](bin/diagnose-encoding.php), adds
[`tests/live/corpus-run.php`](tests/live/corpus-run.php), and extends the live
corpus checks.

### Correction to the prior response

The prior response says that finding C-02 is fixed and that the source
comparison is part of the write
([`CODEX-REVIEW-RESPONSE.md`, lines 171-189](CODEX-REVIEW-RESPONSE.md#L171)).
That statement is not correct. The current check is close to the write, but it
is not atomic. Finding R4-03 gives the evidence.

The response correctly says that the leading-wildcard scan remains only partly
fixed. Finding R4-08 confirms that the residual cost is still present.

### Unresolved provenance question

The repository has not identified the component that encoded the imported Divi
shortcode quotes. The diagnostic comments and `BRIEF.md` state that ordinary
WordPress save paths did not reproduce the change. **Not found in documents:**
verified evidence that identifies the responsible plugin, theme, importer hook,
or platform component.

## Findings

### R4-01 — High — Diagnostic cleanup can delete unrelated posts

**Scope:** Internal diagnostic tool. It is excluded from the release ZIP, but
the file tells an administrator to install it as a temporary plugin.

**Verified facts:**

The import probe creates two published posts with fixed slugs:
`bcfd-import-probe-1` and `bcfd-import-probe-2`
([`bin/diagnose-encoding.php`, lines 364-392](bin/diagnose-encoding.php#L364)).
After the import, the tool selects every post with either slug. It then calls
`wp_delete_post( $id, true )` for every selected ID
([`bin/diagnose-encoding.php`, lines 412-434](bin/diagnose-encoding.php#L412)).
The delete is permanent.

The query does not prove that an ID was created by this run. If the site already
has a post with one of these slugs, or if the importer skips a probe as a
duplicate, the cleanup can delete the pre-existing post. This behavior
contradicts the tool's statement that it touches no existing post
([`bin/diagnose-encoding.php`, lines 34-39](bin/diagnose-encoding.php#L34)).

**Impact:** The diagnostic can cause permanent content loss on the site that an
administrator is trying to diagnose.

**Required correction:** Create unique slugs with a random run identifier.
Record each returned import ID before cleanup. Delete only an ID that this run
created and that still has a matching run marker in post meta. Use drafts, not
published posts. Do not delete anything if ownership cannot be proved.

### R4-02 — Medium — The diagnostic performs write actions on nonce-free GET requests

**Scope:** Internal diagnostic tool.

**Verified facts:**

The Tools page runs the diagnostic when WordPress renders the page. A second
`admin_init` path runs it when the URL has `bcfd-diagnose=1`. Both paths use GET.
Both paths can create and permanently delete posts. Neither path checks a nonce
([`bin/diagnose-encoding.php`, lines 484-523](bin/diagnose-encoding.php#L484)).
The capability check limits the action to an authenticated administrator, but
it does not stop a cross-site request.

The diagnostic also calls registered third-party callbacks directly and in
isolation. Some callbacks can have side effects
([`bin/diagnose-encoding.php`, lines 157-215](bin/diagnose-encoding.php#L157)).
The import probe creates published posts and can trigger normal publish or
integration hooks. Thus, the statement that nothing else is touched is too
strong.

WordPress states that nonces help to protect URLs and forms against CSRF. It
also states that a capability check and a nonce have different purposes
([WordPress Nonces documentation](https://developer.wordpress.org/apis/security/nonces/)).

**Impact:** A third-party page can cause a signed-in administrator's browser to
run this state-changing diagnostic. Callback and publish-hook side effects can
also affect external integrations.

**Required correction:** Render a read-only explanation first. Run all write
tests only after a POST form submission that has `manage_options` and a valid
action-specific nonce. State all possible side effects. Prefer draft probes.
Do not call arbitrary callbacks directly unless the administrator selects that
test and the report clearly states the risk.

### R4-03 — Medium — The conversion source check is still not atomic

**Scope:** Shipped plugin.

**Verified facts:**

`guarded_update()` installs a `pre_post_update` callback. That callback reads
`post_content` with a separate `SELECT` and compares its MD5 value. The method
then lets `wp_update_post()` continue
([`block-converter-for-divi.php`, lines 1085-1122](block-converter-for-divi.php#L1085)).

WordPress core calls `pre_post_update` and then executes a separate
`$wpdb->update()`. The source check is not a condition of that SQL update. The
official core source shows the two operations in this order
([WordPress `wp_insert_post()` source](https://developer.wordpress.org/reference/functions/wp_insert_post/)).

**Inference:** Another request can update the row after the guard's `SELECT`
and before core's `UPDATE`. Both requests can pass their checks. The converter
can then overwrite the other request. The current test injects its competing
write before the guard runs. That test proves the guard detects that ordering.
It does not prove that the later window is closed.

**Impact:** A rare concurrent edit can be lost during conversion. The plugin's
lock prevents a second plugin operation, but it does not lock the normal editor
or another plugin.

**Required correction:** Do not call this mechanism atomic. Add a concurrency
test that writes after the guard and before core's database update. Implement a
real compare-and-swap or a database transaction with an appropriate row lock.
Preserve WordPress revisions, KSES behavior, and post-save hooks. If that cannot
be done safely, document the residual window and require an editor quiet period
for batch conversion.

### R4-04 — Medium — Restore can overwrite an edit that arrives during restore

**Scope:** Shipped plugin.

**Verified facts:**

The restore endpoint checks its nonce and permissions, reads the current post
and backup, and acquires the plugin lock. It then calls `wp_update_post()` with
the backup. It does not capture or verify the current content hash
([`block-converter-for-divi.php`, lines 1328-1380](block-converter-for-divi.php#L1328)).
The source comments say that restore intentionally does not take a source token
([`block-converter-for-divi.php`, lines 1374-1377](block-converter-for-divi.php#L1374)).

**Inference:** The user does authorize the removal of content that is visible
when the Restore action starts. The user does not authorize the removal of a
new editor save that arrives after that point. The plugin lock does not prevent
such an editor save.

**Impact:** A concurrent editor save can be lost during restore.

**Required correction:** Capture the content hash when the restore action is
presented. Verify it at the final write. Refuse the restore if the source has
changed. Show the user the new state and require a new explicit Restore action.
Use the same final concurrency control as conversion.

### R4-05 — Medium — The HTML video path stores a rejected URL in block attributes

**Scope:** Shipped plugin.

**Verified facts:**

The HTML converter extracts the `<video src>` value. It escapes the value for
the rendered HTML, but it writes the original value to the block's `src`
attribute
([`includes/class-d2g-html-converter.php`, lines 193-198](includes/class-d2g-html-converter.php#L193)).

A fresh probe used this input:

```text
[et_pb_text]<video src="javascript:alert(1)"></video>[/et_pb_text]
```

The converter produced an empty HTML `src`, but the block comment still held:

```html
<!-- wp:video {"src":"javascript:alert(1)"} -->
```

Thus, the unsafe scheme remains in stored block data even though `esc_url()`
rejects it in the initial markup.

**Impact:** The saved block has conflicting data and markup. A later block edit
or serialization can consume the unsafe attribute. This review did not prove an
executable stored-XSS path in WordPress. The verified defect is unsafe data
retention and invalid conversion output.

**Required correction:** Pass the URL through `D2G_Block_Builder::url()` before
both uses. If the result is empty, preserve the original tag in a Custom HTML
block with a warning, or omit the video with an explicit loss report. Add this
probe to the offline and live suites.

### R4-06 — Medium — Video provider detection accepts unrelated host names

**Scope:** Shipped plugin.

**Verified facts:**

The module renderer detects YouTube and Vimeo with substring regular
expressions. It does not parse or verify the URL host
([`includes/renderers/class-d2g-renderer-media.php`, lines 186-198](includes/renderers/class-d2g-renderer-media.php#L186)).
The text HTML converter uses the same type of substring match
([`includes/class-d2g-html-converter.php`, lines 170-190](includes/class-d2g-html-converter.php#L170)).

Fresh probes produced these incorrect conversions:

- `https://notyoutube.com/embed/WRONG` became
  `https://www.youtube.com/watch?v=WRONG`.
- `https://notvimeo.com/video/123` became `https://vimeo.com/123`.

**Impact:** The converter can replace an unrelated embed with content from a
different provider. This is a content-integrity error and can create a misleading
link.

**Required correction:** Parse the URL. Compare the normalized host with an
explicit allowlist. Accept the exact host or a valid subdomain boundary only.
Cover `youtube.com`, `youtube-nocookie.com`, `youtu.be`, `vimeo.com`, and the
required official subdomains. Add hostile-host fixtures such as
`notyoutube.com`, `youtube.com.example.org`, and `notvimeo.com`.

### R4-07 — Medium — A High-severity vulnerable development dependency is locked

**Scope:** Development and CI dependencies. This package does not ship in the
plugin ZIP.

**Verified facts:**

The root `npm audit --json` reported two High findings in one dependency path.
`@wordpress/env` 10.39.0 depends on `extract-zip` 1.7.0
([`package-lock.json`, lines 1528-1539](package-lock.json#L1528),
[`package-lock.json`, lines 2733-2743](package-lock.json#L2733)). The advisory is
CVE-2026-56876 / GHSA-jmr9-qjv8-65gv. It affects `extract-zip` versions through
2.0.1 and has no patched `extract-zip` release. The flaw permits a crafted ZIP
symlink to point outside the extraction directory
([GitHub Advisory Database](https://github.com/advisories/GHSA-jmr9-qjv8-65gv)).

The separate `tests/js` audit reported zero vulnerability. The release archive
excludes `node_modules` and the build tools.

**Impact:** A malicious archive that reaches the vulnerable extraction path in
local development or CI can read or write outside its extraction directory.
There is no shipped-plugin runtime exposure.

**Required correction:** Upgrade `@wordpress/env` to a version that removes the
vulnerable dependency path, after compatibility tests. If no compatible version
is available, isolate wp-env, use trusted download sources only, and record the
temporary exception with an expiry date. Keep `npm audit` as a visible CI gate.

### R4-08 — Medium — A first scan still performs multiple full content scans

**Scope:** Shipped plugin. This is a known residual issue.

**Verified facts:**

On page 1, the scan can execute three leading-wildcard operations over
`post_content`: the total count, the Divi 5 count, and the result query
([`block-converter-for-divi.php`, lines 379-405](block-converter-for-divi.php#L379),
[`block-converter-for-divi.php`, lines 422-445](block-converter-for-divi.php#L422),
[`block-converter-for-divi.php`, lines 460-483](block-converter-for-divi.php#L460)).
The result limit controls response size. It does not remove the database work
needed to inspect content for the count queries.

The code comments and `BRIEF.md` disclose this limitation. The transient cache
prevents a full recount on later pages in the same scan session. It does not
reduce the first-page work.

**Impact:** Scan latency and database load grow with the number and size of rows
in `wp_posts`. This risk is most important on large or busy sites.

**Required correction:** Build a resumable inventory in small batches. Store a
scan marker or result table keyed by post ID and content version. Show progress.
Invalidate a row when the post changes. Keep the current hard response cap.

**Not found in documents:** a production-scale benchmark for scan time and
database load on a large `wp_posts` table. The change log has converter timing,
but that measurement does not measure the scan queries.

### R4-09 — Low — The default live and browser test start is not reproducible

**Scope:** Test and CI reliability.

**Verified facts:**

`.wp-env.json` does not pin WordPress core
([`.wp-env.json`, lines 1-14](.wp-env.json#L1)). During this review, a fresh
`bin/live-check.sh` start selected `7.0.4` and failed because Git could not find
that remote ref. A temporary local pin to 7.0.2 allowed the test to run.

The shared helper has reset-and-retry logic for stale wp-env checkouts
([`bin/_wp-env.sh`, lines 32-40](bin/_wp-env.sh#L32)). `bin/live-check.sh` and
`bin/e2e.sh` bypass that helper and call `npx wp-env start` directly
([`bin/live-check.sh`, lines 54-65](bin/live-check.sh#L54),
[`bin/e2e.sh`, lines 42-48](bin/e2e.sh#L42)). During this review, the first
pinned retry also failed on a stale, root-owned wp-env checkout. A reset through
the shared helper repaired it.

**Impact:** The default live and browser gates can fail because upstream state
or an old local checkout changed, not because plugin code failed. This reduces
the value of a fresh test run and can block CI.

**Required correction:** Pin the default WordPress version. Update the pin only
in a reviewed dependency change. Make the live and browser scripts use
`d2g_wp_env_start`. Retain the explicit version matrix for compatibility tests.

### R4-10 — Low — Project documentation contains stale technical statements

**Scope:** Internal documentation and release procedure.

**Verified facts:**

- `CHANGELOG.md` correctly names `BCFD_VERSION` in its release requirements, but
  the build instructions still say that the script checks `D2G_VERSION`
  ([`CHANGELOG.md`, lines 76-80](CHANGELOG.md#L76)). The script checks
  `BCFD_VERSION` ([`bin/build-zip.sh`, lines 22-32](bin/build-zip.sh#L22)).
- `BRIEF.md` calls backup optional in its endpoint table
  ([`BRIEF.md`, lines 202-210](BRIEF.md#L202)). The production write path says
  that backup is mandatory
  ([`block-converter-for-divi.php`, lines 968-984](block-converter-for-divi.php#L968)).
- `BRIEF.md` says spacing, borders, shadows, fonts, and custom CSS are lost
  ([`BRIEF.md`, lines 387-391](BRIEF.md#L387)). Versions 2.4.0 through 2.6.0 map
  parts of spacing, borders, and typography. The document needs a precise
  partial-support statement.
- The roadmap still lists completed KSES, JavaScript validator, and CI matrix
  work as open ([`BRIEF.md`, lines 459-487](BRIEF.md#L459)).
- The parser comment says `has_divi_content()` requires a known tag. The method
  accepts any syntactically valid opening `et_pb_*` tag, including an unknown or
  third-party tag
  ([`includes/class-d2g-parser.php`, lines 557-578](includes/class-d2g-parser.php#L557)).

**Impact:** Maintainers can make a release or design decision from obsolete
information. The code behavior is not changed by these errors.

**Required correction:** Update all five statements. Add a small documentation
check for old constant names. Keep the support matrix as the single source for
style fidelity.

## Security assessment

### Verified strengths

- All shipped AJAX actions use the shared nonce and require `manage_options`.
- Post actions also validate the post ID, allowed post type, status, and the
  current user's `edit_post` capability.
- User-selected post type, order field, order direction, and page size values
  pass through allowlists before SQL construction.
- SQL values use `$wpdb->prepare()`.
- Admin output uses text nodes or escaped output for untrusted data. The review
  did not find an active raw-HTML DOM insertion path in the admin script.
- The converter checks for content that KSES would remove and refuses a lossy
  conversion or restore.
- The uninstall path is opt-in for data removal.
- GitHub Actions use commit SHAs for actions, which reduces mutable-tag risk.

### Residual security risks

R4-02 and R4-05 are the direct security findings. R4-07 affects the development
toolchain. The diagnostic tool is not part of the release ZIP, but its own
instructions make installation on a real site an expected use. It must use the
same safety standard as production migration code.

No evidence of unauthenticated shipped AJAX access, direct SQL injection, or a
confirmed executable stored-XSS path was found in this review.

## Performance assessment

### Verified strengths

- Normal front-end requests return before converter and admin classes load.
- The scan does not return `post_content` or backup bodies to the browser.
- The `All` page-size choice has a 500-row hard cap.
- Later scan pages use cached counts.
- Conversion uses one parser pass and a renderer registry. The current fixture
  gate completes quickly.

### Main limit

R4-08 remains the principal performance risk. The cap controls PHP memory and
response volume. It does not make the initial database content searches cheap.
There is no production-scale scan benchmark in the project documents.

## Code quality assessment

### Verified strengths

- The parser, converter, style mapper, block builder, renderers, and admin
  controller have clear roles.
- The renderer registry makes module coverage easy to inspect and extend.
- Block serialization is centralized for nearly all output paths.
- Comments usually explain data-loss and compatibility decisions.
- The test design has useful layers: fixture snapshots, consistency checks,
  canonical JavaScript parsing, live endpoint tests, stored-content validation,
  multisite tests, browser tests, and a WordPress version matrix.
- The real imported corpus found defects that synthetic fixtures did not find.
  This is strong evidence that the test strategy improved.
- Mandatory, write-once backups and exact builder-meta snapshots support safe
  rollback.

### Remaining quality limits

- Real-site coverage is broad in page count but narrow in module variety.
- The concurrency comments claim more protection than the implementation gives.
- Two similar video paths use duplicate provider-detection logic and now have
  the same hostile-host defect. One shared URL-classification function would
  reduce this drift.
- Internal documents have not kept pace with the release changes.

**Not found in documents:** a recorded visual comparison of the 247-page corpus
before and after conversion. Structural and census tests do not prove visual
fidelity.

**Not found in documents:** a successful live uninstall test that verifies both
the retain-data and delete-data settings.

## Purpose and product-fit assessment

The plugin's purpose is clear. It helps an administrator remove Divi shortcode
dependence from posts and pages. It converts supported content to native blocks,
reports known losses, makes a rollback copy, and lets the administrator review
the result.

The implementation fits this purpose for supervised work on small and medium
sites. It is strongest when the source uses common modules such as sections,
rows, columns, text, images, buttons, and galleries. It is weaker for complex
interactive modules, third-party modules, environmental IDs, precise visual
styles, and large-site batch work.

The plugin is not ready for an unattended promise such as “convert the whole
site with no review.” The project itself does not make that promise. The real
corpus, warnings, preview, backups, and restore function support the correct
position: produce a first native-block draft, then inspect it.

Divi 5 block content is counted but not converted. Custom post types are not a
general supported target. Portfolio output can still depend on Divi's `project`
post type. Gallery attachment IDs remain dependent on the destination site's
media database. These are product limits, not hidden implementation details.

## Fresh verification record

| Check | Result | Notes |
| --- | --- | --- |
| Git state before review | Pass | `main` and `origin/main` were at `b751615`; worktree was clean. |
| Offline PHP gate | Pass | 212 passed, 0 failed; 566 blocks checked. |
| Live WordPress gate | Pass after test-environment repair | 50 passed, 0 failed; 193 stored conversions; 557 blocks validated; debug log empty. WordPress was temporarily pinned to 7.0.2. The temporary override was removed. |
| PHP syntax | Pass | Every tracked PHP file passed `php -l`. |
| JavaScript syntax | Pass | Admin, validator, and canonicalizer scripts passed `node --check`. |
| Shell syntax | Pass | Tracked shell scripts passed `bash -n`. |
| Patch whitespace | Pass | `git diff --check` passed. |
| Root dependency audit | Fail | Two High reports in the `@wordpress/env` to `extract-zip` path. See R4-07. |
| `tests/js` dependency audit | Pass | Zero reported vulnerabilities. |
| Default live environment start | Fail | Unpinned WordPress ref `7.0.4` was unavailable. See R4-09. |
| Fresh browser suite | Not run | The default wp-env start was not reproducible. |
| Fresh multisite suite | Not run | The focused live gate and static review took priority. |
| Fresh full WordPress matrix | Not run | The focused live gate used WordPress 7.0.2. Existing matrix files were reviewed, but their historical results were not treated as a fresh run. |

## Recommended order of work

1. Fix R4-01 before anyone installs or runs the diagnostic again.
2. Add nonce-protected POST execution and reduce diagnostic side effects in
   R4-02.
3. Correct the two shipped video paths in R4-05 and R4-06. Add regression
   fixtures before release.
4. Correct the concurrency claims and implement final-write protection for
   conversion and restore in R4-03 and R4-04.
5. Upgrade or isolate the vulnerable wp-env dependency in R4-07.
6. Pin the default test environment and use the shared recovery helper in R4-09.
7. Plan the resumable inventory in R4-08 before large-site promotion.
8. Repair the stale documentation in R4-10.

## Final verdict

Version 2.9.3 is materially stronger than the version in the prior Codex review.
The response and change log describe many real improvements, and the current
tests support most of those claims. The project has a sound architecture and a
serious safety design.

Do not run the new import diagnostic on a real site until R4-01 is fixed. Do not
describe the conversion guard as atomic. For supervised migrations, the shipped
plugin remains useful after the operator understands the documented fidelity
limits. The video defects and concurrency windows should be corrected before
the next release.
