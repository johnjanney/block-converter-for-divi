# Response to the Codex Repository Reviews

> **Rounds two and three are historical checkpoints.** Everything from "Response
> to the round-three review" down to the end of this file describes the
> repository as it stood when 2.3.0 and 2.2.0 were cut. Both are kept as written
> rather than rewritten, because a response that quietly updates itself is not a
> record — one of them says something that turned out to be wrong, and the
> correction is marked in place where the claim is, not applied over the top of
> it.
>
> The current answer is [Response to the round-four review](#response-to-the-round-four-review),
> immediately below. Read that first.

---

## Response to the round-four review

**Repository:** Block Converter for Divi
**Review answered:** `CODEX-REVIEW.md`, dated 2026-08-12
**Reviewed revision:** `b751615` (`main`), tree `98ac284`
**Response date:** 2026-08-12
**Version at review:** `2.9.3`; this work is unreleased

---

### Summary

**All ten findings are confirmed. None is a false positive, and one is
understated.** Each was reproduced against the reviewed tree before anything was
changed, except where the reproduction would itself have destroyed something —
and R4-01, the High finding, was reproduced literally, by letting the old
diagnostic delete a page it did not create.

Nine are fixed. One — R4-08, the scan's leading-wildcard content search — is
accepted and not fixed, for the same reason it was not fixed in round three: the
honest answer is a resumable inventory, which is a feature rather than a repair.
What has changed is that the documents now say what it costs precisely (three
full content scans on the first page of a session, not "a full table scan") and
say plainly that no production-scale benchmark exists.

The understated one is R4-09. The review found that `bin/live-check.sh` and
`bin/e2e.sh` bypass the shared wp-env helper. They do — and the helper they were
bypassing did not work either. In the reviewed tree it returned success when a
start had failed, because the last thing it ran was a `|| true`, so a caller
would have gone on to test against nothing. And the moment the R4-07 upgrade
landed, its reset became a silent no-op as well: wp-env 11 removed the
`install-path` command it used to find the checkouts it deletes, and an empty
answer skipped the removal *and* reported success. The second one is this
response's own doing rather than the reviewed tree's, which is exactly why it is
recorded here — an upgrade that removes a command a script depends on is the
kind of breakage a passing test run hides.

The review's central correction is accepted without reservation: **the
conversion guard is not atomic and must not be called atomic.** The round-three
response said the comparison was "part of the write". It is not. That claim is
now marked wrong where it was made, the code comment that repeated it is
rewritten, and `tests/live/run.php` writes into the remaining window through a
second database connection so its existence is measured rather than argued
about.

---

### R4-01 — Diagnostic cleanup can delete unrelated posts — **confirmed, reproduced, fixed**

Reproduced on WordPress 7.0.4, because reading the code proves the query is
ownership-blind but not that the consequence follows. A page holding the words
"please do not delete me" was created with `post_name` `bcfd-import-probe-1` —
an ordinary page, nothing to do with the diagnostic — and the reviewed version
of `bin/diagnose-encoding.php` was run:

```
  imported 3 post(s), then deleted them
  shortcode attributes encoded after import : no
  real HTML attributes left alone           : NO

victim page 67 still exists: NO
```

Permanently gone, with no revision and no trash, from a tool whose own header
says "no existing post". The review is right, and its severity is right.

Note the third line as well. The stranger's page was read as one of the probes,
it contains no `href`, so the diagnostic reported that the import had eaten the
HTML attributes it was checking. An ownership-blind query does not only delete
the wrong row; it answers the question wrongly too.

Fixed as the review asked, and by ownership rather than by name, which is the
part that matters:

- Each run generates a random identifier. Every probe it imports carries that
  identifier in `_bcfd_probe_run` post meta.
- The cleanup selects by that meta value, not by slug, and re-checks each row's
  marker through `get_post_meta()` immediately before deleting it.
- Anything without the marker is left where it is and named in the report.
- The probes are drafts, not published posts.

The same run after the fix, with the same page in place first:

```
  imported 2 post(s), then deleted 2 of them
  shortcode attributes encoded after import : no
  real HTML attributes left alone           : yes

victim page still exists: yes
probe posts left behind: 0
orphan probe markers: 0
victim intact: please do not delete me
```

`bin/diagnose-encoding.php`

---

### R4-02 — The diagnostic performs write actions on nonce-free GET requests — **confirmed, fixed**

Confirmed. Rendering the Tools page ran everything: the filter walk, the write
probe, and a full import. `/wp-admin/?bcfd-diagnose=1` did the same. Both are
GETs, neither checked a nonce, and a capability check answers a different
question — it says who you are, not that you asked.

The review is also right that the header's "nothing else is touched" was too
strong once the tool grew an import. Calling another plugin's `save_post` filter
is calling another plugin's code, and importing a post fires every hook an
ordinary import fires.

Fixed:

- The Tools page now renders a read-only explanation listing all three kinds of
  side effect, including that the callbacks belong to other plugins and this
  file cannot know what they do. Nothing runs until the button is pressed.
- The button is a POST carrying `wp_nonce_field( 'bcfd-diagnose-run' )`, checked
  with `check_admin_referer()`, on top of the `manage_options` check.
- The plain-text URL takes the same nonce. Without one it prints why it did not
  run and where the token comes from — silence is the one answer this file must
  never give, because that is the confusion it exists to end.
- Probes are drafts.
- The header docblock now states exactly what runs and what it can touch.

One part of the review's correction is not implemented as written: it asks that
arbitrary callbacks be called only if the administrator selects *that* test
specifically. There is one button, not three. The filter walk, the write probe
and the import are one investigation — the import step only exists because the
first two came back clean, and reading the report of one without the others
would mislead — so what the button buys is stated in full above it instead, in
the same words as this response, and the page says the callbacks belong to other
plugins and that this file cannot know what they do.

Script mode (`wp eval-file`) still runs immediately. A command typed at a shell
is not a cross-site request.

Checked by rendering the Tools page as an administrator with no submission, on
the same WordPress the rest of this was verified on:

```
rendered 1614 bytes
has the button      : yes
has a nonce field   : yes
ran the report      : no
rows written        : 0
```

`bin/diagnose-encoding.php`

---

### R4-03 — The conversion source check is still not atomic — **confirmed; the claim is fixed, the window is documented**

Confirmed, and this is the finding that mattered most, because the error was in
what the project *said* about itself.

The review's reasoning is exactly right: `pre_post_update` fires, then core runs
a separate `$wpdb->update()`. The guard's `SELECT` is not a condition of that
statement. The existing test writes at priority 1 — before the guard — and
proves only that ordering.

Measured rather than argued. `tests/live/run.php` now hooks the `query` filter,
recognises core's `UPDATE` on the row before it executes, and writes through a
**second database connection** — a genuinely concurrent writer, not a re-entrant
call on the connection that is mid-statement:

```
ok    the intruding write really did land inside the gap
ok    a save landing between the guard and core's UPDATE is still lost — the documented residual window
```

The first assertion is there so the second cannot pass vacuously. The second
asserts the *documented* behaviour, and says in its own comment that if it ever
fails, the window has been closed and the comments must be updated.

Not closed, and the reason is written down rather than implied. Both cures cost
more than the window is worth:

- A conditional `$wpdb->update()` is a real compare-and-swap and also a write
  that skips revisions, KSES, and every hook another plugin has registered on a
  post save.
- A transaction holding `SELECT … FOR UPDATE` across core's write puts every
  `save_post` callback on the site inside a transaction this plugin opened,
  where another plugin's own `COMMIT` — or a fatal error — decides what happens
  to somebody else's data. It also does nothing on MyISAM.

So: the round-three claim is marked wrong where it was made, the doc comment on
`guarded_update()` now states plainly that this is not a compare-and-swap and
what remains open, and `BRIEF.md` lists the window as a known limit with the
advice the review asked for — convert in batches while the editors are quiet.

`block-converter-for-divi.php`, `tests/live/run.php`, `BRIEF.md`,
[C-02 above](#c-02--the-final-write-is-not-an-atomic-source-comparison--fixed)

---

### R4-04 — Restore can overwrite an edit that arrives during restore — **confirmed, fixed**

Confirmed, and the review's argument is the one that settles it: the user
authorises discarding the version they were looking at. A save that arrives
after that was never on screen and was never consented to, and the plugin's lock
does not stop an ordinary editor. The old comment in the source — that restore
does not need a token because the user is explicitly discarding what is there —
was reasoning about the wrong moment.

Fixed by making restore work the way conversion already does:

- `d2g_restore_page` requires a `source_hash` naming the version being replaced,
  re-reads the row under the lock, and refuses on a mismatch — handing back the
  current token so a second, deliberate Restore can go through afterwards.
- Half of the review's correction, literally read, is not done: the refusal does
  not *show* the new state. It says the page was saved after it was scanned,
  that nothing was written, and to check what it holds now before restoring
  again. This screen lists titles, not content, and its one content panel is the
  conversion preview; building a second diff view to answer "what changed" is a
  feature, and the honest thing is to say so rather than to describe a message
  as a display.
- The final write goes through `guarded_update()`, so restore gets the same
  last-instant check (and the same documented residual window as R4-03).
- Deliberately *not* auto-retried the way a stale conversion is. A conversion
  retry converts the current content, which is still what the user asked for; a
  restore replaces it, and nobody asked for the new thing to go. The second
  Restore has to be pressed.
- `d2g_convert_page` now returns a token for what it has just written, read back
  from the database rather than assumed, so the first restore after a conversion
  is not refused as stale. The admin script stores it.

```
ok    a conversion hands back a token for what it just wrote
ok    a restore that names no version is refused
ok    a restore whose version has been saved over is refused
ok    and the edit it would have discarded is still there
ok    the token the refusal hands back makes a second, deliberate restore work
ok    a save that lands mid-restore is not overwritten
ok    the save that landed mid-restore survives intact
```

`block-converter-for-divi.php`, `admin/js/admin.js`, `tests/live/run.php`

---

### R4-05 — The HTML video path stores a rejected URL in block attributes — **confirmed, fixed**

Confirmed exactly as described. The probe in the review reproduces against the
reviewed tree:

```
- missing expected markup: <!-- wp:html -->
- output contains forbidden markup: "src":"javascript
```

The markup half was escaped and the data half was not, which is the wrong way
round: the block attribute is what the editor regenerates markup from, so the
value that survived was the dangerous one. This is the same defect C-03 fixed
for module attributes in 2.3.0, in the one path that reads a URL out of authored
HTML rather than out of a Divi attribute.

Fixed: the URL goes through `D2G_Block_Builder::url()` before either use. A
source that does not survive sanitising is not a video source, so the tag is
kept verbatim in a Custom HTML block — which is what an unrecognised `<iframe>`
has always got — and a warning names the loss. Nothing is dropped, and no unsafe
value is stored as block data. Two fixtures: the review's probe, confirmed
failing before the fix on every one of its five assertions, and a positive
control so "no video block" cannot become the new bug.

`includes/class-d2g-html-converter.php`, `tests/fixtures.php`

---

### R4-06 — Video provider detection accepts unrelated host names — **confirmed, fixed**

Confirmed, both halves, with the review's own probes:

```
https://notyoutube.com/embed/WRONG  ->  https://www.youtube.com/watch?v=WRONG
https://notvimeo.com/video/123      ->  https://vimeo.com/123
```

A page that embedded one site's player came out embedding another's. The review
is right that this is content integrity rather than a security bug, and right
that the duplicated regex in two files is why the same defect existed twice.

Fixed with one shared classifier, `D2G_Block_Builder::video_provider()`, that
both paths call. It parses the host, lowercases it, drops a trailing dot, and
matches an allowlist on a **dot boundary** — exact host or true subdomain, which
is what makes `notyoutube.com`, `notvimeo.com` and `youtube.com.example.org` all
non-matches while `www.youtube-nocookie.com` and `player.vimeo.com` still work.
Identifiers come from the path or the `v` query parameter, and a host that
matches with no identifier falls through to a Custom HTML block rather than
being guessed at.

Five fixtures, including both of the review's hostile hosts. Three fail against
the reviewed tree. Of the other two, one is a positive control — the real
subdomains must still convert — and `youtube.com.example.org` passed before by
luck, because the old regex looked for the literal `youtube.com/embed/`; it
guards the replacement's boundary rule instead, which its comment says.

`includes/class-d2g-block-builder.php`, `includes/class-d2g-html-converter.php`,
`includes/renderers/class-d2g-renderer-media.php`, `tests/fixtures.php`

---

### R4-07 — A High-severity vulnerable development dependency is locked — **confirmed, fixed, and it was worse than a single upgrade**

Confirmed: `npm audit` reported two High findings through
`@wordpress/env` 10.39.0 → `extract-zip` 1.7.0 (GHSA-jmr9-qjv8-65gv, symlink
path traversal, with no patched `extract-zip` release — the review is right
about that too).

Upgraded to `@wordpress/env` 11.13.0, which drops `extract-zip` entirely. That
alone did not fix it: 11.13.0 unpacks with `adm-zip` and pins `^0.5.9`, and
`adm-zip` below 0.6.0 carries GHSA-xcpc-8h2w-3j85. An `overrides` entry takes
0.6.0, whose `extractAllToAsync` signature wp-env uses is unchanged and was
exercised end to end by a fresh WordPress download. `npm audit` reports zero
across the tree.

The upgrade is a major version, and it broke something quietly, which is worth
recording because it is the kind of breakage a passing test run hides: wp-env 11
removed the `install-path` command. `d2g_wp_env_reset()` used it to find the
root-owned checkouts `wp-env destroy` leaves behind, and an empty answer made
the whole removal a no-op that still reported success. The helper now derives
the path the way wp-env does — an md5 of the config file path under
`~/.wp-env`, or the descriptive `wp-env-<dir>-<hash>` name — and
`d2g_wp_env_start` returns a failure when its retry fails instead of returning
whatever the `|| true` database-upgrade call returned.

`package.json`, `package-lock.json`, `bin/_wp-env.sh`

---

### R4-08 — A first scan still performs multiple full content scans — **confirmed; accepted and not fixed**

Confirmed, and the count is right: three unindexed content searches on page 1 —
the total, the Divi 5 count, and the result query — of which only the last is
bounded by `LIMIT`. The cache removes the recount on pages 2..n and does nothing
for the first page.

Not fixed, for the reason the review itself gives in its required correction:
the answer is a resumable inventory keyed by post ID and content version, with
progress and per-row invalidation. That is a feature, and building it inside a
review response is how a review response becomes a rewrite. It is on the
roadmap.

What did change is the accuracy of the disclosure. `BRIEF.md` said "a full table
scan per query"; it now says three content scans on the first page of a session,
explains that the row cap bounds what is returned rather than what is read, and
states that **no production-scale benchmark exists** — the timings in the change
log measure the converter, not the scan, which is precisely the "not found in
documents" note the review made.

`BRIEF.md`

---

### R4-09 — The default live and browser test start is not reproducible — **confirmed, understated, fixed**

Confirmed on both counts, and the first one is now impossible to argue with: the
tag the review's run could not find, `7.0.4`, exists today. The failure was a
race between WordPress.org announcing a release and the `WordPress/WordPress`
git mirror tagging it — wp-env asks the first and checks out from the second.
Nothing to do with this plugin, on the default gate, on a schedule nobody
controls.

Fixed:

- `.wp-env.json` pins `core` to `WordPress/WordPress#7.0.4`. It is a dependency
  version like any other now: bumped deliberately, with the matrix run when it
  moves. `bin/wp-matrix.sh` still tests `latest` explicitly, by writing
  `"core": null` rather than by leaving the pin alone, and its comment says that
  entry is the one that can fail for reasons that are not the plugin.
- `bin/live-check.sh` and `bin/e2e.sh` start through `d2g_wp_env_start`.

The understatement: the helper those two were bypassing was itself broken in two
ways, described in R4-07. A run of `bin/live-check.sh` during this work hit the
exact stale root-owned checkout the review describes, the helper reported
success without starting anything, and the reason it did was visible only
because the retry was made to fail loudly.

One thing found while fixing this and worth its own line: with core pinned, the
browser gate started failing on `Automatic updates starting...` in
`wp-content/debug.log` — core's own housekeeping, failing a gate that exists to
catch this plugin's notices. `AUTOMATIC_UPDATER_DISABLED` is now set in the same
config as the pin, because a background updater on a pinned core is a
contradiction, and a gate that fails for someone else's housekeeping teaches
people to ignore it.

`.wp-env.json`, `bin/_wp-env.sh`, `bin/live-check.sh`, `bin/e2e.sh`,
`bin/wp-matrix.sh`

---

### R4-10 — Project documentation contains stale technical statements — **confirmed, all five fixed**

All five reproduce.

1. **`D2G_VERSION` in the build instructions.** Renamed to `BCFD_VERSION` in
   2.0.0. Fixed — and given a check, because the release *requirements* section
   three paragraphs above had been corrected in 2.7.0 while the build
   instructions were not, which is what documentation without a test looks like.
   `bin/build-zip.sh` now fails the build if the release procedure at the top of
   `CHANGELOG.md`, or `BRIEF.md`, names a `D2G_*` constant on a line that is not
   describing the rename or struck through as history.
2. **Backup called optional in the endpoint table.** Mandatory since 2.1.0, and
   the write path says so. Fixed, with the version it changed in.
3. **"Spacing, borders, shadows, fonts and custom CSS are lost."** Three
   releases mapped parts of the first three. Replaced with a precise
   partial-support summary that defers to the §5.1 matrix, which was already
   correct — the review is right that the matrix should be the single source.
4. **Completed work still listed as open in the roadmap.** KSES (2.2.0), the
   JavaScript validator (2.2.0/2.3.0) and the CI matrix (2.3.0) are struck
   through with what delivered them. The resumable inventory from R4-08 is added
   as open, because it is.
5. **`has_divi_content()` "requires a known tag".** It does not: any
   syntactically valid `et_pb_*` opening tag qualifies, and the suite has a
   `unlisted but well-formed` case asserting exactly that. The behaviour is
   right — a third-party module's tag is Divi content, and a page made of
   nothing else should be offered for conversion with its losses reported — so
   the comment is corrected to describe it and say why.

`CHANGELOG.md`, `BRIEF.md`, `bin/build-zip.sh`, `includes/class-d2g-parser.php`

---

### Verification

Everything below was run on the fixed tree.

| Check | Result |
| --- | --- |
| Offline PHP suite | **219 passed, 0 failed**; 574 blocks checked by core's own validator (212/566 before) |
| Live WordPress suite, WordPress 7.0.4 | **60 passed, 0 failed**; 200 stored conversions, 565 blocks validated, debug log empty |
| Browser suite (Playwright) | **10 passed**, debug log empty |
| Multisite suite, WordPress 7.0.4 network | **12 passed, 0 failed**, debug log empty |
| Block-library matrix | every mapped release from 6.1 to 7.0.2 accepts the markup: 200/200 fixtures, 574 blocks, nine block libraries |
| `npm audit`, root | **0 vulnerabilities** (2 High before) |
| `npm audit`, `tests/js` | 0 vulnerabilities |
| PHP syntax | every tracked PHP file passes `php -l` |
| JavaScript syntax | admin, validator and canonicalizer pass `node --check` |
| Shell syntax | every tracked script passes `bash -n` |
| Old-constant documentation check | passes (and fails on the defect it was written for) |

The seven new golden snapshots are the only ones that changed: no existing
fixture's byte-exact output moved, which is what says the video and provider
fixes are corrections rather than rewrites.

`bin/wp-matrix.sh`, which runs the whole live suite on six WordPress releases in
six rebuilt environments, was **not** re-run. The block-library matrix above
covers the part of this work that is version-sensitive — whether each release's
`save()` accepts the converted markup — and the endpoint changes use no API
newer than the declared floor. It is a gap in this verification all the same,
and it is named rather than glossed.

---

### What this response does not do

> **One of the two gaps below has since been closed, and the claim is left as
> written.** `tests/live/uninstall.php` now covers both retention settings
> against a real database, and `bin/live-check.sh` runs it — so "no live
> uninstall test" was true when this was written and is not true now. The
> visual comparison of the corpus is still absent. Marked here rather than
> edited out below, because a response that quietly corrects itself is not a
> record.

- **R4-08 is not fixed.** See above. The disclosure is accurate now; the scan
  still costs what it costs.
- **No production-scale scan benchmark** was taken. The review noted its
  absence; this response notes it too rather than pretending otherwise.
- **No visual comparison of the corpus**, and **no live uninstall test** with
  both retention settings. Both were "not found in documents" in the review and
  both are still not found. They are real gaps and they are not review findings
  this response can close by writing prose.
- **The provenance of the quote encoding is still unknown.** The diagnostic is
  now safe to install and safe to run, which is what R4-01 and R4-02 were about.
  It has not been run on the site that has the problem.

---

### Release position

Cut as `2.10.0`, and MINOR rather than PATCH for one reason: restore now
requires the caller to name the version it is replacing. The bundled admin
screen sends it, so nothing a user does changes — but that is a documented
endpoint's contract, and a contract change is not a patch.

`dist/block-converter-for-divi-2.10.0.zip` is built and verified: 159,044 bytes,
SHA-256
`22f5e433ac5fc003a69597f9cdf18631beeca76755632f5208a443fd7600fee5`, all 31
shipped files byte-identical to the source tree, and no test, build or review
file in the archive. The digest is recorded here rather than in `CHANGELOG.md`,
because an archive cannot contain its own digest and putting it there would
break the identity check above.

The review's position on what this plugin is has not changed and should not:
an assisted migration tool for supervised work. The video defects it found are
fixed and the concurrency claims are honest now, which is what it asked for
before the next release. The corpus condition for a WordPress.org submission is
still unmet, and the two gaps named above — no visual comparison, no live
uninstall test — are still open.

---

## Response to the round-three review

**Repository:** Block Converter for Divi
**Review answered:** `CODEX-REVIEW.md`, dated 2026-08-05
**Reviewed tree:** `123940f4d73c17474c176e51ac10fa84be586d1a` (`7dca2f1`, `main`, `v2.2.0`)
**Response date:** 2026-08-05
**Resulting version:** `2.3.0`

---

### Summary

Every finding in the review was reproduced against the reviewed tree before
anything was changed. **All nine reproduce — C-01 through C-07, D-01 and Q-01.
None were false positives, and none were overstated.** The review's verdicts on
the earlier N- and F-findings were spot-checked and agree with the code.

Seven of the nine are fixed. Two are partly fixed and say so below: C-05, where
the count query is now taken once per scan instead of once per page but the
underlying leading-wildcard scan is unchanged, and C-07, where the per-version
validation the review asked for now exists and passes on nine WordPress
releases with two fixtures recorded as known-invalid on 6.1.

Building C-07 found **two compatibility defects the review did not know about
and no check in this project could have seen**:

- Converted **Cover** blocks were invalid on WordPress 6.1 through 6.7.
- Converted **Toggles** were invalid on WordPress 6.3.

Both are now fixed. The review was right that the single pinned block library
was a test gap; it was a larger gap than either of us assumed, because the
pinned library (10.3.0) is newer than any released WordPress. WordPress 7.0.2 —
the version this plugin declares as tested — ships 9.40.2.

A third and fourth defect were found by a test written for C-03. The standalone
`serialize_block_attributes()` fallback disagreed with core's in three ways on
its first run, and then — once the live suite was run on the *declared floor*
rather than only on 7.0.2 — in a fourth. WordPress 7.0 rewrote that function as
a single `strtr()` and added a rule for a literal backslash; 6.1 through 6.8 run
five sequential `preg_replace()` calls and have no such rule. The fallback now
mirrors 7.0's structure, and the test normalises that one spelling difference on
older versions rather than exempting the case. The offline suite could not have
noticed any of this, because the offline suite is the only thing that uses the
fallback.

### Found after the review, while investigating a failed install

The review did not cover installation, and a real upgrade attempt found
something none of the suites could: **activating this plugin on a site that
still had the pre-rename "Divi to Gutenberg Converter" installed failed with
"Plugin could not be activated because it triggered a fatal error."**

It is not the shared class names, which is the obvious guess. Both plugins
define `D2G_VERSION`, `D2G_PLUGIN_DIR` and `D2G_PLUGIN_URL`. `define()` keeps an
existing constant rather than overwriting it, so this plugin read
`D2G_PLUGIN_DIR`, got the *other* plugin's directory, and required its bootstrap
from there:

```
Warning: Constant D2G_PLUGIN_DIR already defined … on line 32
Fatal error: Failed opening required '…/plugins/divi2gutenberg/includes/load.php'
```

That path has existed since 2.0.0 — the release that performed the rename — and
`readme.txt` documents this exact upgrade. Two fixes:

- The runtime constants are now `BCFD_VERSION`, `BCFD_PLUGIN_DIR` and
  `BCFD_PLUGIN_URL`. The `d2g_` **storage** keys are untouched; renaming those
  would orphan every existing backup, which is why they were kept in the first
  place. Nothing outside the main file reads the runtime constants.
- Having both plugins present is detected and refused with an explanation. The
  detector covers both load orders — already-loaded, which is what an activation
  sees, and not-yet-loaded, which is the ordinary admin request where
  `block-converter-for-divi/` sorts before `divi2gutenberg/` and this plugin
  would be the one to declare the classes the other then dies on. When it fires,
  this file declares nothing, so the old plugin keeps working and the site stays
  up.

Three live tests guard it, including one that puts the legacy plugin into
`active_plugins` and asserts the detector fires.

---

### Reproduction, before any fix

Run on PHP 8.1.2 against the reviewed tree, with the review's own probe inputs.

| Finding | Reproduced | Observed |
| --- | --- | --- |
| C-01 Tabs | yes | `[et_pb_tabs]Before[et_pb_button …]After[/et_pb_tabs]` → an empty `core/group`. All four pieces of content gone. |
| C-01 Pricing Table | yes | `Before`, `After` and the Button all discarded; only the item survived. |
| C-01 Counters | yes | loose text and a nested Button discarded. |
| C-01 Social Follow | yes | output was empty; both the text and the Button gone. |
| C-01 Video Slider | yes | an item carrying its URL in the body, not in `src`, produced nothing. |
| C-02 | yes | by inspection of `block-converter-for-divi.php:695-733`; later confirmed by a live test that fails against the old code. |
| C-03 comment terminator | yes | `<!-- wp:search {"placeholder":"find--><img src=x onerror=alert(1)>"} /-->`, verbatim. |
| C-03 unsafe URL | yes | `"url":"javascript:alert(1)"` in the block attributes, stripped from the `<img>`. |
| C-04 | yes | all four scripts print the log and exit 0. `bin/e2e.sh` additionally exits under `set -e` before reaching the log on a browser failure. |
| C-05 | yes | two `LIKE '%[et_pb_%'` scans per request, and the count re-ran for every page of results. |
| C-06 | yes | `backup=no` skipped `write_backup()` and still overwrote content and deleted builder meta. |
| C-07 | yes | and worse than stated — see below. |
| D-01 | yes | `BRIEF.md` claimed no browser test while `bin/e2e.sh` existed and Q30 was closed. |
| Q-01 | yes | only `text_align_class()` is reachable; the rest builds CSS from raw Divi values. |

One correction to the review, on a detail rather than a finding: C-03 recommends
`sanitize_url()` for stored URLs, and that is what was implemented — but the
review's parenthetical that the block comment should also carry escaped slashes
does not match core. `serialize_block_attributes()` passes
`JSON_UNESCAPED_SLASHES`. Slashes stay as they are; `--`, `<`, `>`, `&`, `\"`
and `\\` are what it escapes. This mattered: the first implementation here used
PHP's `JSON_HEX_TAG | JSON_HEX_AMP` instead, which looks equivalent and is not.

---

### C-01 — Structural renderers can silently discard content — **fixed**

Six renderers each wrote their own child loop, and every one was written the
same wrong way: iterate the children, act on the one tag I expect, and let
everything else fall off the end of the `if`.

They now share one traversal, `D2G_Converter::render_structural_children()`.
Expected children arrive at a callback in **runs of consecutive siblings**,
because some parents batch them — a pricing table turns a run of items into one
`core/list` — and a run boundary is exactly where unexpected content belongs.
Everything that is not expected is rendered where it stands, in source order,
and the parent raises a warning naming the module.

Whitespace between shortcodes does not trigger the warning. Divi formats its own
output with newlines between children, and a warning that fires on every
ordinary page is a warning nobody reads.

The Video Slider fix is slightly different: an item was skipped unless it
carried a `src` attribute, even though the shared Video renderer also reads an
iframe or a bare URL out of a module's body — which is how Divi stores a YouTube
slide. It now asks `video()` and, only if that finds nothing, falls back to the
item's own content.

The probe from the review now produces:

```
[et_pb_tabs]Before[et_pb_button button_text="Keep" /]After[/et_pb_tabs]
  → paragraph "Before", a buttons block containing "Keep", paragraph "After"
  → warns et_pb_tabs (behaviour) and et_pb_tabs (unexpected structure)
```

Six new fixtures, one per filtering parent. Each was confirmed to fail against
the reviewed tree.

`includes/class-d2g-converter.php`,
`includes/renderers/class-d2g-renderer-interactive.php`,
`includes/renderers/class-d2g-renderer-pricing.php`,
`includes/renderers/class-d2g-renderer-media.php`,
`includes/renderers/class-d2g-renderer-content.php`

---

### C-02 — The final write is not an atomic source comparison — **fixed**

> **Corrected by the round-four review, and the correction is upheld.** "The
> comparison is now part of the write" below is not true, and calling this
> heading *fixed* claims more than the code does. `pre_post_update` fires
> immediately before core's `UPDATE`, but immediately before is still before:
> two statements are not one, and a save landing between them is overwritten.
> The window went from seconds to microseconds, which is a real improvement and
> not the same as closing it. See R4-03 in the
> [round-four response](#response-to-the-round-four-review), which measures the
> remaining window rather than reasoning about it. Left as written below,
> because a response that quietly corrects itself is not a record.

The review's recommendation was a final compare-and-swap that does not bypass
revisions, hooks or KSES. Re-reading and comparing immediately before
`wp_update_post()` would only have made the window smaller, so the comparison is
now part of the write.

`pre_post_update` is the last hook core fires before its `UPDATE`. A guard
registered there at `PHP_INT_MAX` reads the live row straight from the database
— the object cache still holds the copy this request read, and that copy is the
thing in question — and throws `D2G_Source_Changed` if it is not what was
converted. The exception unwinds `wp_insert_post()` before the row is touched,
before the revision is saved, and before any after-update hook runs. It is
caught in `guarded_update()` and turned into a `WP_Error`, so it never escapes
that method.

A conditional `$wpdb->update()` would have been a genuine CAS and was rejected
for the reason the review gives: it skips revisions, KSES, and every hook
another plugin has registered on a post save.

The live suite now proves it, at the only moment that matters. A second
`pre_post_update` callback at priority 1 writes to `wp_posts` directly —
a genuine external change, not a re-entrant `wp_update_post()` the plugin might
notice another way — and the conversion must refuse:

```
ok    a save that lands mid-conversion is not overwritten
ok    the intruding edit survives intact
```

`block-converter-for-divi.php`, `tests/live/run.php`

---

### C-03 — Block attributes are not serialized with WordPress's safe encoder — **fixed**

`D2G_Block_Builder::block()` now calls `serialize_block_attributes()` whenever
WordPress is loaded, so the plugin's output tracks core rather than a copy of
core. Blog, Search, Menu, Login, Post Title and Portfolio built their comments by
hand and bypassed the builder; they no longer can.

The offline fixture suite runs with no WordPress, so the builder carries a
fallback. Writing it was where this got interesting. The first attempt used
`JSON_HEX_TAG | JSON_HEX_AMP`, which produces `<` where core produces
`<`, escaped slashes where core does not, and no handling of a literal
backslash at all. A block comment is compared as bytes, so all three matter.

The fix for that is not a better guess — it is a test. `tests/live/run.php` holds
the fallback against core's own function on four cases and fails on any
difference. It failed on three of the four the first time it ran, which is
exactly what it is for:

```
FAIL  the offline attribute encoder matches serialize_block_attributes()
        case 0
            core:     {"placeholder":"find--><img …"}
            fallback: {"placeholder":"find--><img …"}
```

It now passes.

**URLs.** `D2G_Block_Builder::url()` sanitises with `sanitize_url()`, and the
same cleaned value is used for both halves of a block. The mismatch the review
found — `esc_url()` in the markup, the raw value in the attribute — ran through
Image, Video, Audio, Cover, Gallery and Social Link. The block attribute is what
the editor rebuilds markup from, so the dangerous value was the one that
survived. A module whose only URL does not survive sanitising now warns rather
than vanishing.

Probe results after the fix:

```
[et_pb_search placeholder="find--><img src=x onerror=alert(1)>" /]
  → <!-- wp:search {"placeholder":"find--><img src=x onerror=alert(1)>"} /-->

[et_pb_image src="javascript:alert(1)" /]
  → nothing, plus a warning naming what was dropped
```

Six new fixtures. `includes/class-d2g-block-builder.php`,
`includes/renderers/class-d2g-renderer-dynamic.php`,
`includes/renderers/class-d2g-renderer-media.php`,
`includes/renderers/class-d2g-renderer-content.php`, `tests/bootstrap.php`,
`tests/live/run.php`

---

### C-04 — A dirty WordPress debug log does not fail CI — **fixed**

All four scripts now set a failure flag and exit non-zero. `bin/e2e.sh` runs the
browser command as a tested condition, which suspends `errexit` for it, so the
debug-log diagnostics run on a browser failure — the second half of the review's
recommendation, and the case where the log is most worth having.

`bin/wp-matrix.sh` records dirty versions in an array and reports them after the
table, so one bad version does not hide the rest of the matrix.

The logs were empty on every run made for this response, so nothing was hiding
behind it today. That was also true when the review was written; the point is
that it will not stay true by accident.

`bin/live-check.sh`, `bin/e2e.sh`, `bin/multisite-check.sh`, `bin/wp-matrix.sh`

---

### C-05 — The scan still performs two full content scans — **partly fixed**

Fixed: the count query is taken on page 1 and cached for the pager, keyed by the
post-type filter, for five minutes. Paging through results was re-running an
unbounded `COUNT(DISTINCT …)` over every row of `wp_posts` for every page; it is
now one scan per scanning session. Page 1 always recounts, so the number a user
is shown is never stale.

Not fixed: the leading wildcard itself. `post_content LIKE '%[et_pb_%'` cannot
use a B-tree prefix lookup, and the first scan of a large `wp_posts` still costs
a full content scan. The resumable inventory the review recommends — keyed by
post ID and modified time, with detection meta maintained on save and a rebuild
command for imports that bypass hooks — is the right design and is a larger
change than this response should carry. It is documented rather than left for a
user to discover.

No production-scale benchmark was run. The review said no timing data was found
in the documents; that is still true, and this response does not claim
otherwise.

`block-converter-for-divi.php`

---

### C-06 — The destructive path still permits conversion without a backup — **fixed**

The backup is mandatory. The argument for the checkbox was that WordPress
revisions exist, and that argument does not survive contact with the details:
revisions can be disabled by a constant, pruned by a plugin, or not reach far
enough, and `_et_pb_use_builder` and `_et_pb_old_content` are not in a revision
at all. Clearing the box produced a conversion nothing could undo.

`write_backup()` already kept the first snapshot and never overwrote it, so
calling it unconditionally cannot damage an existing backup. The `backup`
request parameter is ignored rather than removed, so an old or hand-made client
cannot opt out. The Tools screen replaces the checkbox with a statement of what
happens, and the confirmation dialogs say so too.

The review offered an advanced override behind a second confirmation as an
alternative. It was not taken: the only thing the override buys is a slightly
faster conversion, and the thing it costs is the plugin's entire recovery story.

`block-converter-for-divi.php`, `admin/class-d2g-admin.php`, `admin/js/admin.js`

---

### C-07 — Older WordPress save contracts are not validated — **fixed, and it found two real defects**

This was the largest piece of work in the response, and the most productive.

`tests/js/versions.json` maps each supported WordPress release to the
`@wordpress` package versions it actually shipped, with the derivation recorded
so the mapping can be checked: 6.1 through 6.8 come from `package.json` at the
matching `wordpress-develop` tag; 7.0 builds Gutenberg from a commit rather than
from npm, so its versions come from that commit's own package files.

`bin/block-library-matrix.sh` converts the fixture corpus **as that version** —
the plugin adapts its output to the WordPress it runs on, so generating the
corpus once and validating it nine times would have measured the wrong thing —
and validates it against that release's own block library. `validate.mjs` takes
a `--modules` directory and resolves `@wordpress/*` from there.

Two things had to be solved before any of it ran, and both are recorded in
`versions.json` because neither is guessable:

- Every `@wordpress` package has to be pinned through npm `overrides`. A second
  nested copy of `blocks`, `data` or `private-apis` means one copy registers the
  save-time filters and another runs `save()`, and every block throws before it
  is ever validated. The error says nothing about the version under test.
- `matchMedia` has to be stubbed on the jsdom **window**, not only on
  `globalThis`. Older `@wordpress/viewport` reads `window.matchMedia` at import
  time, and the whole library fails to load without it.

**What it found.** Two real defects, in code that every existing check called
valid:

1. **Converted Cover blocks were invalid on WordPress 6.1 through 6.7.**
   `core/cover` saves an `<img>` and a dimming `<span>`, and swapped their order
   in 6.8. The converter emitted the 6.8 order on every version. The 2.2.0
   changelog describes getting this markup *right* — and it was right, for one
   release branch out of nine.
2. **Converted Toggles were invalid on WordPress 6.3.** `core/details` arrived
   in 6.3 with the summary as a block *attribute*, so 6.3 regenerated every
   converted toggle with the literal word "Details". 6.4 moved it to rich text
   inside the element, where this converter already put it.

Both are decided by `D2G_Block_Builder::wp_at_least()`, a sibling of
`D2G_Converter::block_supported()`: that one asks whether a block exists, this
one asks what shape it has. The 6.3 `summary` attribute is stored already
escaped, because 6.3 writes it out through `RichText.Content` — the decoded
title would have put a live `<script>` back on the page, which is the N-12
defect in a place N-12 never reached.

Current state, all nine releases:

| WordPress | block-library | Result |
| --- | --- | --- |
| 6.1 | 7.14.15 | 148/148 valid or known (2 known) |
| 6.2 | 8.3.16 | 148/148 valid |
| 6.3 | 8.12.20 | 148/148 valid |
| 6.4 | 8.19.18 | 148/148 valid |
| 6.5 | 8.28.12 | 148/148 valid |
| 6.6 | 9.0.8 | 148/148 valid |
| 6.7 | 9.8.17 | 148/148 valid |
| 6.8 | 9.19.6 | 148/148 valid |
| 7.0.2 | 9.40.2 | 148/148 valid |

**What is not fixed.** Two fixtures are still invalid on 6.1 and are recorded as
known failures with reasons. `core/html` on 6.1 re-serializes its content
through a DOM, so raw markup that is not already DOM-normalized — a `<table>`
with no explicit `<tbody>`, an empty `<p>` — is reported as unexpected content.
The content is intact and the block renders; the editor shows a resolve prompt.
Normalizing the markup first would clear the notice and defeat the verbatim
preservation Custom HTML exists for, so it is recorded rather than papered over.
A known failure that starts *passing* fails the run, so the list cannot rot into
a blanket exemption.

`tests/js/versions.json`, `bin/block-library-matrix.sh`, `tests/js/validate.mjs`,
`includes/class-d2g-block-builder.php`,
`includes/renderers/class-d2g-renderer-interactive.php`,
`.github/workflows/tests.yml`

---

### D-01 — Current documents contradict current test coverage — **fixed**

- `BRIEF.md` risk register items 1 and 2 rewritten. The browser-test claim was
  false, and the backup-checkbox risk no longer exists.
- `tests/README.md` no longer points the older-save-contract gap at **Q18**,
  which was the wrong ID — Q18 was about the measured `Tested up to` value. That
  work is now **Q31**, and it is resolved.
- `CODEX-REVIEW-RESPONSE.md` carries a note at the top marking the round-two
  response as a historical checkpoint, kept as written rather than rewritten. A
  response that quietly updates itself is not a record.
- `readme.txt` corrected where it described the backup as a checkbox.
- New questions recorded: **Q31** (per-version validation), **Q32** (mandatory
  backup), **Q33** (per-version block markup). **Q22** moved to resolved.

---

### Q-01 — The dead style mapper keeps unsafe code in the release — **fixed**

Deleted. `build_inline_style()`, `wrapper_style()`, `get_color_attrs()`,
`parse_font()`, `resolve_spacing()` and `parse_border_radii()` are gone;
`text_align_class()` is all that remains and it is an allowlist.

The review's framing is the right one and worth repeating: this was not an
active vulnerability, it was a trap. `build_inline_style()` concatenated raw
Divi values into CSS declarations and appended `custom_css_main_element`
verbatim. Anyone reconnecting it to save time would have reintroduced the
CSS-injection class N-01 had to fix in the live renderers.

`includes/class-d2g-style-mapper.php`

---

### Also done, from the review's residual lists

- **CI actions pinned to commit SHAs.** A tag is a mutable pointer, and whoever
  controls the action repository can move it to a commit that runs with this
  repository checked out and the job's token available. Each pin carries a
  trailing comment naming the release, so an upgrade is still a readable diff.
- **A `block-library-matrix` CI job**, with the per-version installs cached on
  `versions.json`'s hash.
- **`bin/live-check.sh` and `bin/e2e.sh` clear a pending database upgrade on
  start.** `bin/wp-matrix.sh` moves the shared environment between WordPress
  versions, and a version change makes WordPress redirect every admin page to
  `wp-admin/upgrade.php`. Nine browser tests failed with "element not found"
  because what the browser was looking at was the update screen. That is not a
  hypothetical: it is what the environment left behind by the review's own
  matrix run did to the first browser run made for this response.

---

### Verification

Every suite below was run on this machine against the current working tree.

| Check | Result |
| --- | --- |
| Converter suite, with the required block validator | **166 passed, 0 failed**; 432 blocks checked |
| Module coverage | 58 of 58 declared Divi tags exercised |
| Golden snapshots | current and committed |
| Live WordPress 7.0.2 (`bin/live-check.sh`) | **20 passed, 0 failed** |
| Live WordPress 6.1 on PHP 7.4.33 — the declared floor | **20 passed, 0 failed** |
| Plugin activation, WP 7.0.2/PHP 8.3 and WP 6.1/PHP 7.4 | clean; `activate_plugin()` returns no `WP_Error` |
| PHP 7.4 parser, every tracked PHP file | pass |
| — including the mid-conversion save guard | pass |
| — including the offline attribute encoder vs. core's | pass |
| — including that the version check reads the real WordPress version | pass |
| Stored conversion fixtures through the database | 148 round trips unchanged |
| Browser suite (`bin/e2e.sh`) | **9 passed, 0 failed** |
| Multisite KSES suite (`bin/multisite-check.sh`) | **12 passed, 0 failed** |
| Block library per release (`bin/block-library-matrix.sh`) | **9 releases, 0 unexpected failures** |
| WordPress debug log, all three live suites | empty |
| PHP syntax, all tracked PHP | pass on PHP 8.1.2 |
| JavaScript syntax, admin and harness | pass on Node 24.16.0 |
| Shell syntax, all `bin/*.sh` | pass |

**Not run for this response**, and not claimed:

- The full `bin/wp-matrix.sh` WordPress version matrix. The per-release block
  validation above is a different and narrower check: it proves the *markup* is
  valid on each release, not that the endpoints and database behave there.
- PHP 7.4 through 8.4. Only 8.1.2 was available locally; the CI matrix covers
  the rest.
- Uninstall, on single site or multisite. Still untested, as the review says.
- A production-scale database benchmark.
- A real, anonymized Divi page corpus, and any visual comparison. This remains
  the largest gap in the project.

  It is narrower than it was, but only slightly. After the work above was
  complete, 2.3.0 was installed on a live WordPress site and used to convert
  about 15 real Divi pages, successfully. That is the first time this plugin has
  touched content it did not author. Two limits on what it establishes: the
  pages were sections, rows, text and images — the module set the fixtures
  already cover best, and not the renderers where C-01 found silent loss — and
  none of those pages are in the corpus, so nothing about them is guarded
  against regression. Whether the block editor reported any converted page as
  invalid, and whether the published pages matched, were **not established**.

---

### Release position

Unchanged from the review's, and the review's reasoning still holds. This is an
assisted migration tool. It is not lossless, it has never been run against a
page from a real Divi site, and no visual comparison exists.

C-01 through C-04 are fixed and C-07 is fixed further than the review asked. The
corpus condition the review set for a WordPress.org submission is still not met:
converting 15 real pages by hand is encouraging, and it is not a corpus — it
guards nothing, and it covered the easiest modules. Users should preview every conversion, read every
warning, open the result in the block editor, and compare the published page
before removing Divi.

Cut as `2.3.0`. `dist/block-converter-for-divi-2.3.0.zip` is built and verified:
117,515 bytes, SHA-256
`ab050959f9e7cd425f1b45b42834c7f1a5927305952eec3b42ba173a3c13aa89`, every
shipped file byte-identical to the tagged source, and no test, build or review
file in the archive. The digest is recorded here and in the release notes rather
than in `CHANGELOG.md`, because an archive cannot contain its own digest and
putting it there would break exactly the identity check above. Nothing is tagged or published on GitHub
yet — that is a separate decision.

---

## Response to the round-two review

**Repository:** Block Converter for Divi
**Reviewed revision:** `4462a8b` (`main`, `v2.1.0`)
**Prior review:** answered in 2.1.0; that response is summarised in
[Round one](#round-one-the-f-findings) below
**Response date:** 2026-08-05
**Resulting version:** `2.2.0`

---

## Summary

All eleven fresh findings (N-01 … N-11) were re-verified against the code before
anything was changed, using a standalone probe harness on PHP 8.1.2. **All
eleven reproduce.** None were false positives.

The review's process criticism is also accepted and is dealt with first, because
it is the reason the round-one response overstated its own results.

| Finding | Severity | Status |
| --- | --- | --- |
| N-01 Shortcode attributes can inject HTML attributes | High | **Fixed** |
| N-02 Fullwidth Header creates invalid Paragraph blocks | High | **Fixed** |
| N-03 Parser and converter paths change or lose content | High | **Fixed** (all six paths) |
| N-04 Write concurrency controls are incomplete | Medium | **Fixed** |
| N-05 Preview does not report all documented losses | Medium | **Fixed** — detection built; see the caveat in [N-05](#n-05--preview-does-not-report-all-documented-losses--fixed) |
| N-06 The test gate gives more confidence than it provides | Medium | **Fixed for the converter** — real block validation, golden snapshots, 58/58 module coverage, 96.7% line coverage. Endpoint tests still need a WordPress install (Q27) |
| N-07 Scan performance unsuitable for large sites | Medium | **Partly** — conditional loading and cache priming done; resumable inventory tracked as Q11 |
| N-08 Compatibility and release metadata not current | Medium | **Not fixed** — cannot be fixed from here; see [N-08](#n-08--compatibility-and-release-metadata-are-not-current--not-fixed) |
| N-09 Backup meta restoration is not an exact snapshot | Low | **Fixed** |
| N-10 Some converted text is not localized | Low | **Fixed**, including the converter split it also recommended |
| N-11 User documents contain factual conflicts | Low | **Fixed** (all seven) |
| N-12 Divi attribute text is double-encoded *(not in the review)* | Medium | **Fixed** |

That is **nine of the review's findings fixed, one partly fixed, one not
fixed**, plus one further defect found while verifying (N-12) and three more
found by the block validator built for N-06. The count is stated that way
deliberately — see the next section.

**The release recommendation stands: do not submit to WordPress.org, and treat
2.2.0 as staging-only.** Two things gate it, both unchanged: `Tested up to:` is
still a placeholder, and no converted page has been opened in a real block
editor. N-01 is a genuine security fix, so 2.2.0 should still ship on GitHub
ahead of that work rather than waiting for it.

---

## On the status-count conflict — accepted, and its cause

The review is right, and the error is worse than a miscount.

The 2.1.0 response claimed "15 fixed, two partly fixed" in its summary table
while its own finding headings marked F-06, F-07 **and** F-11 as partly fixed —
14 and three. The F-09 body also said the KSES part was not fixed, so F-09 was
not fully fixed as scoped either. The GitHub release notes repeated the wrong
count.

The cause is that the summary table was written first, from intent, and never
reconciled against the finding sections after they were written. That is the
same class of error as N-05 and N-11: **a document asserting a result the work
did not produce.** Three separate findings in this review are instances of it.

What has changed in this response:

- The table above was generated by reading each finding section's own verdict,
  and the counts were re-derived from it afterwards rather than asserted first.
- "Fixed" is used only where a regression fixture proves the fix, or where the
  change is a document correction that can be read directly.
- Where a finding is partly fixed, the summary line says which part and names
  the tracking question. There is no aggregate claim without that detail.
- Claims about what *the tests* prove are now written at the top of
  `tests/run.php`, in the file itself, listing what it does not cover.

---

## How each finding was verified

Before any change, a probe harness (`tests/bootstrap.php` plus the parser,
style mapper and converter, in a plain PHP process) ran the review's own inputs
and printed the raw converted markup. Every quoted output reproduced. The three
that differed in detail, and how:

- **N-01** reproduced exactly, including the injected
  `onmouseover="alert(1)"` on both the Image and the Button. A third instance
  the review flagged only in its recommendation — CSS injection through colour
  values — was confirmed too: `button_bg_color="red;background-image:url(x)"`
  produced `style="background-color:red;background-image:url(x)"`.
- **N-03e** reproduced, with different residue than the review printed. The
  review reported visible `">` text; the probe produced visible `"]`. Same
  defect, same cause; the exact leftover depends on where the bracket sits in
  the attribute string.
- **N-03b** reproduced, and turned out to have a wrinkle the review did not
  mention: a comment shaped like a Gutenberg delimiter cannot simply be
  re-emitted. See N-03 below.

After the fixes, every new fixture was run against the **2.1.0 tree** to confirm
it actually fails there. A fixture that passes both before and after guards
nothing:

```text
Against 4462a8b (2.1.0) with the 2.2.0 test suite:   51 passed, 19 failed
Against 2.2.0 with the 2.2.0 test suite:             70 passed,  0 failed
```

The 19 failures are exactly the 19 new fixtures that assert a fixed defect. The
other six new fixtures are non-regression guards — supported alignments still
work, real colours still work, entity-quoted attributes are still repaired,
mapped settings do *not* raise a false warning, a raw `<script>` in an attribute
is still escaped — and correctly pass in both trees.

---

## Fresh findings and fixes

### N-01 — Shortcode attributes can inject HTML attributes · Fixed

**Confirmed, and it is the most serious finding in the review.** The parser
accepts single-quoted attribute values, so a value can legally contain a double
quote. That value was concatenated into a quoted `class` attribute with no
escaping:

```text
[et_pb_image src="…" align='center" onmouseover="alert(1)' /]
→ <figure class="wp-block-image size-large aligncenter" onmouseover="alert(1)">
```

The same held for `button_alignment` on Buttons. The reverse polarity works too:
a double-quoted value containing a single quote, which the review did not print
but which the fixture now covers.

Three things were wrong, and escaping alone fixes only one of them:

1. **The markup was unescaped.** `esc_attr()` now wraps the completed class
   attribute in `convert_image()` and `convert_button()`.
2. **The value also became a *block attribute*.** The injected string landed in
   `{"align":"center\" onmouseover=\"alert(1)"}`. An arbitrary string is
   meaningless to a block that has to regenerate its markup from it, so escaping
   the markup would have left a block that cannot round-trip. Layout values are
   now reduced to allowlisted tokens by `D2G_Converter::allowed_value()` —
   `left|center|right|wide|full` for Image, `left|center|right|space-between`
   for Buttons — and anything else is dropped entirely.
3. **Colours could inject CSS.** The review raised this as recommendation 3
   rather than as a finding; it reproduces as a defect.
   `esc_attr( 'red;background-image:url(x)' )` is unchanged, because that string
   is safe as *markup* and dangerous only as *CSS*.
   `D2G_Converter::safe_css_color()` now accepts only the colour grammars CSS
   defines — `#rgb`/`#rgba`/`#rrggbb`/`#rrggbbaa`, `rgb()`/`rgba()`/`hsl()`/
   `hsla()` containing nothing but numbers and separators, or a bare keyword —
   and returns `''` for anything else. It gates `button_bg_color`,
   `button_text_color`, section and CTA `background_color`, the divider `color`,
   and the Cover overlay colour.

On severity: the review's stored-XSS inference holds. A contributor who can edit
Divi content plants the value; an administrator with `unfiltered_html` runs the
conversion; the handler is written to `post_content` and survives. KSES is not a
sufficient control precisely because the user most likely to run this plugin is
the one who bypasses KSES.

Fixtures: five, covering single-quoted injection, double-quoted injection and
CSS injection, plus two guards that supported alignments and real colours still
survive.

**Files:** `includes/class-d2g-converter.php` (`allowed_value()`,
`safe_css_color()`, `convert_image()`, `convert_button()`, `convert_section()`,
`convert_cta()`, `convert_divider()`, `convert_fullwidth_header()`).

### N-02 — Fullwidth Header still creates invalid Paragraph blocks · Fixed

**Confirmed byte for byte.** `convert_fullwidth_header()` dropped its whole
inner span into one paragraph block, so a two-paragraph body produced
`<p class="has-text-align-center"><p>One</p><p>Two</p></p>`.

This is the F-03 defect in a renderer F-03's fix never reached. The 2.1.0 work
rewrote the shared HTML splitter and rewired the renderers that called it; this
one built its own paragraph and was missed.

The body now goes through `render_inner_blocks()` with a forced centre
alignment, which is the same path every other rich-text renderer uses.

**The more useful part of this finding is why the suite missed it.** The review
identifies it exactly: `d2g_block_bodies()` walked only top-level blocks, and
this converter wraps practically everything in a Group, a Columns or a Cover, so
the paragraph check almost never ran on a real paragraph. That is fixed under
N-06 — and with the recursive check in place, the 2.1.0 tree reports
`paragraph block contains 3 <p> elements` on this input with no fixture-specific
assertion at all.

**Files:** `includes/class-d2g-converter.php`, `tests/lib/assertions.php`.

### N-03 — Several parser and converter paths still change or lose content · Fixed

All six reproduce. Taken one at a time:

**Table captions and `colgroup` discarded.** `table_block()` copied only
`thead`, `tbody`, `tfoot` and loose `tr` children, so a caption reading "Rates"
did not appear anywhere in the output. Any other child element now sends the
whole table to `core/html` with a warning naming the element — the review's own
recommendation, and the same strategy the method already used for tables with
attributes core cannot model. Native caption mapping was considered and
deferred: `core/table` does model a caption, but getting its save contract
exactly right without a real block registry would be guesswork, and guessing
wrong trades silent loss for a validation error. Tracked as Q26.

**HTML comments discarded.** `html_to_blocks()` walked only text and element
nodes. Comments are now preserved as `core/html`, flushing any pending paragraph
first so a comment cannot land inside a `<p>`.

One case needs different handling, which the review did not raise: a comment
shaped like `<!-- wp:… -->`. Re-emitting one would make WordPress read it as a
block delimiter and corrupt the document. Those are removed and reported by name
instead — the only deliberate content removal added in this release, and it is
warned about.

**Nested module children discarded.** The worst of the six.
`get_inner_content()` returns only loose text when a node has module children,
which is correct for the callers that want a *label* (a counter's caption, a
pricing feature) and catastrophic for the callers that emit *blocks*. A Button
inside a Text module vanished entirely while the text either side of it
survived, silently.

`render_inner_blocks()` now walks a node's children in source order, routing
text runs through the HTML splitter and module children through `render_node()`.
It replaced the `html_to_blocks( get_inner_content( … ) )` pattern in eight
renderers: Text, Blurb, CTA, Toggle, Tab, Slide, Testimonial, Team Member — plus
Fullwidth Header and the Pricing Table fallback. `get_inner_content()` is
unchanged and still serves the label callers, which is what it was always right
for.

The review notes "How often Divi emits this shape was not found in the
documents." That is fair and still true; no corpus was available to measure it.
The fix does not depend on the frequency — the failure is total and silent when
it happens.

**Counter body escaped as visible markup.** `convert_counter()` ran
`esc_html()` over the module's HTML body, so `<p>Sales</p>` was published as the
characters `&lt;p&gt;Sales&lt;/p&gt;`. The label is now reduced with
`wp_strip_all_tags()` first, then escaped — matching how pricing items already
did it.

**`]` inside a quoted attribute corrupted the tag.** Every scanner in the
parser was a regex built on `[^\]]*`, which cannot cross a `]` at all. This was
tracked as Q24 with "Divi does not normally emit such values" as the argument
for accepting it. That argument does not survive contact with the failure mode:
the leftover is *published as visible text on the page*, and the review is right
that the evidence for "Divi never does this" was never in the documents.

`D2G_Parser::scan_tag_end()` now walks from `[` to the closing `]` treating
single- and double-quoted spans as opaque, and `next_tag_span()` builds one
tokenizer on it that `has_divi_content()`, the tree parser, `find_closing_tag()`,
`found_tags()` and `strip_divi_tags()` all share — so those five can no longer
disagree about what a tag is. Self-closing detection moved to *after* the scan,
because `src="a/"` also ends in a slash. Q24 is resolved.

**Curly-quote entities straightened.** `normalize()` rewrote `&#8220;`,
`&#8221;`, `&#8243;`, `&#8216;` and `&#8217;` across the entire document, so
`&#8220;quoted&#8221;` in ordinary body text came out as `"quoted"`. The
review's phrase for this — "not a byte-safe content conversion" — is right.

The replacement existed to repair Divi's habit of encoding an *attribute's*
delimiting quotes, so it could not simply be deleted without breaking attribute
parsing on that content. It moved into `parse_attrs()`, where it operates on one
attribute string instead of the page. Both behaviours now have a fixture: entity
quotes in body text survive, and an entity-quoted attribute is still parsed.

The CRLF/CR collapse is kept and is now documented as deliberate — every
downstream regex and DOM step assumes `\n`, and WordPress normalizes line
endings on save anyway.

**Files:** `includes/class-d2g-parser.php`, `includes/class-d2g-converter.php`.

### N-04 — The write concurrency controls are incomplete · Fixed

**Confirmed on both counts.**

**The lock was check-then-set.** `get_transient()` followed by
`set_transient()`: two requests both read "no lock" before either wrote one, and
both proceeded. Acquisition is now a single `INSERT` against the UNIQUE index on
`wp_options.option_name`, so the database decides the winner rather than the gap
between two statements.

The Options API is bypassed deliberately. `add_option()` resolves a duplicate
with `ON DUPLICATE KEY UPDATE` and cannot report whether the row was new, which
is the one fact the function needs. Nothing reads the key through
`get_option()`, so no cache can go stale behind it, and `autoload='no'` keeps it
out of `alloptions`. The lock carries an owner token, so `release_lock()` can
only delete a lock this request still holds, and it ages out after two minutes —
a request that dies mid-conversion cannot release its own lock, and a post that
stays locked forever is its own kind of data-loss bug. A stale lock is stolen
with a conditional `UPDATE` matching the exact value that was read, so two
requests racing to steal the same stale lock cannot both win.

**The source hash was optional, and the paths that mattered omitted it.** The
review's detail is exactly right: the check ran only `if ( $expected_hash )`,
and both single conversion without Preview and every batch conversion sent
`''`. So the common case wrote over whatever the post held.

Three changes:

1. The scan issues a token for every row it returns, computed as
   `MD5( p.post_content )` **in SQL** so no post content is loaded into PHP or
   shipped to the browser. The browser stores it per row; Preview refreshes it;
   Restore returns a fresh one so a convert straight after a restore still works.
2. The token is mandatory. A conversion request without one is refused.
3. The post is re-read and the token re-checked **after** the lock is acquired.
   The review's point that "there is also time between the check and
   `wp_update_post()`" is correct, and re-reading under the lock is what closes
   it.

Restore deliberately does not require a token: it is the user explicitly
discarding current content in favour of a snapshot they asked for by name. The
lock still applies. That reasoning is now a comment in the endpoint rather than
an unstated assumption.

**Files:** `block-converter-for-divi.php` (`acquire_lock()`, `release_lock()`,
`ajax_convert_page()`, `ajax_scan_pages()`, `ajax_restore_page()`),
`admin/js/admin.js`, `uninstall.php` (clears stray lock rows).

### N-05 — Preview does not report all documented losses · Fixed

**Confirmed, and this is a documentation-integrity failure as much as a
functional gap.** `BRIEF.md` said unsupported settings were "reported rather
than silently dropped". `CHANGELOG.md` said "every lossy or unmapped module" was
shown in Preview. Neither was true: a Section with `custom_padding` lost the
padding and produced no warning at all, and Tabs, Sliders and Video Sliders lost
their behaviour silently. What 2.1.0 actually shipped was warnings for unmapped
module *tags* and a handful of specific modules.

Two mechanisms now exist:

**A style-loss registry.** `report_unmapped_styles()` runs for every module node
and matches its attributes against a pattern registry covering spacing, explicit
sizing, borders, box and text shadows, typography, background treatment,
parallax, animation and filters, custom CSS, module IDs and classes, positioning
and transforms, per-device visibility (`disabled_on`), hover styling, text
colour, and `_tablet`/`_phone` overrides. It raises one warning per module tag
per category, so a page with forty padded sections produces one line, not forty.

**Per-module behaviour warnings.** Tabs, Sliders (all four variants), Video
Sliders, Accordions and both counter types now state what stopped working —
panels all visible at once, slides no longer advancing, accordion sections no
longer closing each other, counters no longer animating.

**Caveat, stated plainly.** The registry is pattern-driven: an attribute is
reported when its *name* matches. That covers everything Divi ships today, but
it will silently miss a setting whose name follows no existing pattern, and it
cannot distinguish "set to the default" from "set deliberately". The precise
alternative — enumerate what each renderer consumes and report everything else —
is per-renderer maintenance, and is raised as Q28 rather than assumed.

One false positive was found while building this and fixed before release:
`background_image` matched the background-treatment pattern, but Fullwidth
Header and Slide genuinely *do* map it onto a `core/cover` URL. It was removed
from the pattern, and Section — which does not map it — reports it directly. Two
fixtures now assert the absence of a warning, because a loss report that fires
for mapped settings trains users to ignore it.

Documents corrected: `BRIEF.md` §3, §5.1 and §7, `CHANGELOG.md` 2.1.0 "Added",
`readme.txt`, and Q22 in `OPENQUESTIONS.md`.

**Files:** `includes/class-d2g-converter.php`, `BRIEF.md`, `CHANGELOG.md`,
`readme.txt`, `OPENQUESTIONS.md`.

### N-06 — The test gate gives more confidence than it provides · Fixed for the converter

*(Originally answered as "partly fixed". Revised after building the real block
validator — see [Making the suite strong enough](#making-the-suite-strong-enough-to-protect-a-refactor)
below. The converter half of this finding is now closed; the endpoint half is
Q27.)*

**Every limit listed is accurate**, including the one that stings: "The response
says that every review defect has a fixture. This is false."

Done:

- **Assertions are recursive.** `d2g_block_bodies()` now pairs every opening
  delimiter with its body at every depth. This is the fix with the most reach —
  it is what would have caught N-02 unaided, and it now applies the paragraph,
  heading, list, table, quote and details checks inside Group, Cover, Columns,
  Details and every other container.
- **A fixture per confirmed defect**, and each one verified to fail against
  2.1.0 (19 of them do; the other six are non-regression guards that must pass
  in both trees).
- **The bracket fixture is fixed.** The review is right that the case named
  "attribute values containing brackets do not truncate the tag" put brackets in
  *body text*, where nothing was ever wrong. It is kept, and two real ones were
  added: a bracket in an attribute value, and a bracket in a self-closing tag's
  attribute.
- **A warning-absence assertion** (`rejectWarnings`), so the N-05 registry can
  be tested for false positives, not just for coverage.
- **The suite says what it is.** `tests/run.php` now opens with an explicit
  "WHAT THIS DOES NOT COVER" list: no bootstrap, no endpoints, no capability or
  nonce checks, no SQL, no lock, no backup or restore state, no JavaScript, no
  uninstall, no real block registry, no other PHP or WordPress version.
- **CI exists.** `.github/workflows/tests.yml` runs lint and the suite on PHP
  7.4, 8.1, 8.3 and 8.4, plus the JavaScript and shell syntax checks.

Not done: PHP unit tests for the endpoint helpers and backup state, JavaScript
tests for the batch logic, and WordPress integration tests with a real block
registry. These need a WordPress test install, which is the same missing piece
that blocks Q23. Raised as **Q27** rather than left implied — and it matters
more now than it did in 2.1.0, because this release added an atomic lock, a
mandatory source token and an exact meta snapshot, all of which are untested
logic in the endpoint layer.

**Verified locally on PHP 8.1.2 only.** Only 8.1 is installed on this machine;
the review's 7.4 and 8.5 runs could not be reproduced here. The CI matrix is
what will cover the rest, and it has not run yet at the time of writing. The
code was read for 7.4 compatibility (no arrow functions, no `str_contains`, no
named arguments, no typed properties) but that is inspection, not a test.

**Files:** `tests/lib/assertions.php`, `tests/fixtures.php`, `tests/run.php`,
`.github/workflows/tests.yml`, `bin/build-zip.sh`, `OPENQUESTIONS.md` (Q27).

### N-07 — Scan performance remains unsuitable for large sites · Partly fixed

**Confirmed.** Both scan queries still use a leading-wildcard `LIKE` on
`post_content`, which cannot use an index, and the 500-row cap does not bound
the count query.

Two of the review's four sub-points are fixed:

- **Admin-only loading.** The parser, converter, style mapper and admin class
  were required on every front-end request although the plugin has no front-end
  runtime feature — roughly 2,700 lines parsed per page view for nothing. The
  plugin now returns early when `! is_admin()`. `admin-ajax.php` defines
  `WP_ADMIN` before WordPress loads plugins, so every request that can reach the
  endpoints is still covered.
- **Attachment cache priming.** Gallery conversion asked for a URL, an alt text
  and a caption per image — three uncached lookups each, 120 round trips for a
  forty-image gallery. One `_prime_post_caches()` call now covers the set.

Not fixed: the resumable keyset inventory, scan progress state, and a WP-CLI
path. Those are a redesign of the scan, not a tuning change, and they remain
tracked as Q11. The `MD5()` added for N-04 is computed in SQL specifically so
this finding is not made worse — no post content is loaded into PHP by the scan.

**Files:** `block-converter-for-divi.php`, `includes/class-d2g-converter.php`.

### N-08 — Compatibility and release metadata are not current · Not fixed

**Confirmed, and it cannot be fixed from here.** This is stated as not fixed
rather than partly fixed, because the substance of the finding — run the plugin
against real WordPress versions and record the results — has not been done.

`Tested up to: 6.8` was left unchanged. The reasoning: the value is already
wrong for being unverified, and replacing it with a different unverified number
would not make it less wrong. Changing it to a version nobody has tested is the
exact failure mode this review keeps finding elsewhere in the project.

The review's sharper point *is* recorded: a placeholder that also trails current
stable gives a **worse** signal than declaring nothing, because it reads as a
tested-and-stale result rather than as an untested one. Q18 now says so, and
`readme.txt` still carries the warning that the plugin has not been run against
a live install.

WordPress 7.0.2 as the current stable release on the review date is taken from
the review; it could not be verified offline here, and is not restated as an
independently confirmed fact.

The PHP recommendation **is** acted on. `readme.txt` now says that 7.4 and 8.1
have both reached end of life and that a currently supported branch is worth
moving to, while keeping 7.4 as the technical floor so the plugin still installs
on old hosts. That separation — compatibility floor versus recommendation — is
the review's suggestion and it is right.

**Files:** `readme.txt`, `OPENQUESTIONS.md` (Q18).

### N-09 — Backup meta restoration is not an exact snapshot · Fixed

**Confirmed.** `get_post_meta( …, true )` returns `''` both for an absent key
and for a key present with an empty value, so the snapshot could not tell them
apart; it also dropped repeated meta rows; and restore wrote saved keys without
first clearing the managed ones, so it could only ever add or overwrite.

Each key is now recorded as an explicit `{ exists, values }` pair capturing
every row, and the snapshot is written **even when both keys are absent** —
because that absence is precisely what restore has to reproduce.
`restore_builder_meta()` deletes both managed keys first, then re-adds only what
existed. 2.1.0's flat snapshot shape is still read, and the 1.x case (no
snapshot at all) still falls back to switching the builder on.

The review's characterisation is accepted in full: the normal "builder on" path
worked, and "restored as found" was too strong for all possible meta states.

Not covered by a fixture: this is endpoint code that needs a WordPress install
to test, which is Q27. That gap is stated rather than papered over.

**Files:** `block-converter-for-divi.php` (`write_backup()`,
`restore_builder_meta()`, `builder_meta_keys()`).

### N-10 — Some user-visible converted text is not localized · Fixed

**Confirmed.** The admin JavaScript was clean, as the review says; the converter
was not. Every string the review names and one it did not are now wrapped:
`Click Here`, `Tab`, `Subscribe`, `Map:`, the sidebar placeholder, the email
signup placeholder, the WooCommerce placeholder, and the empty-gallery
placeholder. The two with interpolated values use `sprintf()` with numbered
placeholders and translator comments.

The review's second recommendation — split the converter into module renderer
classes — is **now done**, in the order the review itself prescribed: "after the
validation suite is strong enough to protect the refactor". Building that suite
came first, and the split came second. See
[The refactor](#the-refactor-one-class-becomes-eleven).

The text-domain CI scan is also not added. Noted here rather than silently
skipped.

**Files:** `includes/class-d2g-converter.php`.

### N-11 — User documents still contain smaller factual conflicts · Fixed

All seven confirmed and corrected:

| Item | Correction |
| --- | --- |
| `BRIEF.md` single-column row passthrough | Rewritten: every row with at least one column is wrapped, because a `core/column` outside `core/columns` is invalid. Only a row with no column children passes through |
| `BRIEF.md` `core/summary` | Corrected to `core/details` with an HTML `<summary>` element. There is no Summary block |
| `INSTRUCTIONS.md` "all" with no cap stated | The 500-row cap, the truncation notice, and the `d2g_scan_hard_cap` filter are now named in that section |
| `INSTRUCTIONS.md` button reads "Done" | Corrected to "Converted", which is what the localized string says |
| `INSTRUCTIONS.md` restore sets builder to `on` | Rewritten to describe the actual behaviour, including the 1.x fallback |
| `INSTRUCTIONS.md` manual cleanup leaves `_d2g_builder_meta` | Third `wp post meta delete` added, with a note that all three keys are one snapshot |
| `CHANGELOG.md` claims `$price` removed | `$price` is now actually removed, and the 2.1.0 entry is annotated to say the original claim was false |

Two of these are corrections to *claims about earlier corrections*, which is the
same pattern as the status-count conflict. Both are annotated in place rather
than quietly rewritten, so the history stays readable.

**Files:** `BRIEF.md`, `INSTRUCTIONS.md`, `CHANGELOG.md`,
`includes/class-d2g-converter.php`.

---

## Making the suite strong enough to protect a refactor

The first pass through this review answered N-10's "split the converter into
module renderer classes" with *not yet* — on the review's own condition, "after
the validation suite is strong enough to protect the refactor". This section is
that work. It also closes the substance of N-06 and resolves Q23, open since the
previous round.

### What "strong enough" had to mean

A refactor that moves 2,300 lines of renderers into separate classes does not
break the things people wrote assertions for. It breaks the things nobody did: a
dropped class on a wrapper, an attribute serialized in a different order, a lost
newline, a warning that stops firing, a renderer nobody ever executed. So three
properties were needed, none of which existed:

1. **Any output change is detected**, whether or not anyone predicted it.
2. **Every renderer is executed**, so an untested one cannot be silently broken.
3. **Output validity is checked by WordPress**, not by this project's guesses
   about WordPress.

### 1. Real WordPress block validation — Q23, resolved

Block validity is decided by core parsing the saved markup, re-running each
block's `save()` over the parsed attributes, and comparing. That is JavaScript.
The previous round recorded this as needing `wp-env` plus a browser harness,
which is why it kept being deferred.

It does not. `@wordpress/blocks` and `@wordpress/block-library` are npm
packages; under jsdom they register the same 113 core blocks the editor uses and
`parse()` returns each block's `isValid` and `validationIssues` straight from
core. `tests/js/validate.mjs` does exactly that and nothing else — it
reimplements no WordPress rule. `tests/lib/blockcheck.php` batches every
fixture's output through it in one process and folds the verdicts into the PHP
run, so `php tests/run.php` covers everything in one command.

**It found three real defects within minutes**, all of them invalid markup, all
of them missed by four rounds of static checks and by both reviews:

- **Every converted Cover block was invalid.** `core/cover` saves its background
  `<img>` before the dim `<span>`; the converter emitted them the other way
  round, omitted `aria-hidden` and `has-background-dim-100`, and dropped the
  overlay colour. That is every Fullwidth Header and every Slide with a
  background image.
- **Coloured Dividers were invalid** — `core/separator` with a colour also needs
  `has-text-color` and a `color` declaration.
- **The Comments block was invalid** — emitted self-closing when its `save()`
  writes a wrapper `<div>`.

The last two were only reachable because module coverage was fixed first; those
modules had no fixture at all.

`tests/js/canonical.mjs` was written alongside it and is arguably as valuable:
it asks core what markup it *would* have saved for a given block, which is how
the Cover and Separator markup was corrected. Hand-writing block markup by
guesswork is what produced all three defects, and there is now no reason to
guess.

**What this does not prove**, stated plainly: the output is valid against the
block library pinned in `tests/js/package-lock.json`. That is not the same as a
given WordPress *release* opening the page, and it says nothing about the admin
screen, the endpoints, or a real database. Q18 and Q27 stay open, and the
lockfile is committed precisely so a newer block library cannot quietly change
what "valid" means underneath the snapshots.

### 2. Golden snapshots — the actual refactor protection

`tests/golden/` now holds every fixture's byte-exact output *and* the sorted set
of warnings it raised. Any difference fails the run and prints a unified diff.
Warnings are included because the loss reporting added in 2.2.0 is behaviour a
refactor can silently drop, and no `expect` list would notice.

`php tests/run.php --update-golden` accepts changed output deliberately, which
puts the diff in the commit where it can be reviewed. A new fixture fails until
its snapshot is committed — accepting output should be an explicit act. CI
re-runs `--update-golden` and fails if the working tree changes, so a stale
snapshot cannot be committed.

This was verified by deliberately introducing a one-class drift in the group
wrapper: 40-odd snapshots failed with exact diffs. That is the mechanism
working.

### 3. Coverage — 33 of 58 modules had no test at all

The blind spot was larger than the review said. `et_pb_gallery`, `et_pb_video`,
`et_pb_blurb`, `et_pb_cta`, `et_pb_team_member`, `et_pb_audio`, `et_pb_map`,
`et_pb_signup`, `et_pb_login`, `et_pb_search`, `et_pb_comments`, every
`fullwidth` variant, and 20 more had never been executed by any test. A refactor
could have deleted any of them silently.

All 58 now have a fixture, and `tests/lib/coverage.php` fails the run if a tag in
`D2G_Parser::known_tags()` has none — reading the list from the parser, so adding
a module and forgetting to test it is a failure rather than a silence.

Module coverage is not branch coverage, so line coverage was measured too
(xdebug, in a container, committed as `tests/coverage.php`). The first
measurement was **87%**, with the mapped background-colour paths, the `<pre>`,
`<hr>` and `<div>` branches of the HTML splitter, Vimeo embeds, the Fullwidth
Header buttons and the pre-6.3 Details degradation all unexecuted. Those gaps
became the `branch …` fixtures. It now measures **96.7%** for the converter and
**98.8%** for the parser; the remainder is code guarded by `function_exists()`
for WordPress functions the shim deliberately leaves undefined.

The style mapper measures **6%** — every function except `text_align_class()` is
provably unreached. That was already known (Q22) but was a claim; it is now a
number.

### 4. Invariants a refactor breaks and assertions miss

Two per-fixture checks were added:

- **Determinism** — the same input converted twice must give identical output.
  This is what catches a refactor introducing a static cache or a buffer that is
  not reset per instance.
- **Idempotence** — converted output must contain no Divi content, and
  converting it again must be a no-op. The convert endpoint's "already
  converted" guard is built on exactly this property, and nothing tested it.

### 5. Making it stick

- `bin/build-zip.sh` passes `--require-validator`, so a release cannot be cut
  from a run that could not check block validity.
- Without that flag, a missing harness prints `NOT RUN — block validity is
  therefore UNPROVEN` and continues. It never silently reports a pass it did not
  make.
- CI runs the full suite with the validator, a PHP matrix from 7.4 to 8.4, and
  the golden-snapshot freshness guard.
- `tests/README.md` documents all five layers and, as importantly, what a green
  run still does not mean.

### Verified across PHP versions

The previous round could only verify on PHP 8.1 and said so. Using Docker, the
suite was run on **7.4, 8.1, 8.2, 8.3, 8.4 and 8.5** — 153 passed, 0 failed on
every one, with byte-identical golden snapshots. That closes the honesty caveat
recorded under N-06 last round.

### Is the refactor now safe?

Yes, and with evidence rather than confidence: every renderer is executed, every
output is byte-pinned, every block is validated by WordPress itself, and
determinism and idempotence are asserted. A refactor that changes behaviour
cannot pass.

---

## The refactor: one class becomes eleven

With the harness in place, the split the reviews had been asking for since F-16.

`D2G_Converter` was 2,638 lines: every module renderer, a 170-line dispatch
switch, the HTML-to-blocks engine, and the markup primitives. Adding a module
meant editing the same file as fixing a parser bug, and a reader looking for
"what does a Gallery become" had to find it among fifty neighbours.

It is now 430 lines of orchestration. The rest is:

| Class | Lines | Holds |
| --- | --- | --- |
| `D2G_Block_Builder` | 239 | `block()`, `paragraph()`, `cover()`, and the sanitisers |
| `D2G_HTML_Converter` | 491 | free-form HTML → blocks; knows nothing about Divi |
| `D2G_Module_Renderer` | 80 | abstract base; each subclass declares `tags()` |
| `D2G_Renderer_Layout` | 108 | sections, rows, columns |
| `D2G_Renderer_Text` | 41 | text, code |
| `D2G_Renderer_Media` | 383 | images, video, audio, gallery, maps |
| `D2G_Renderer_Content` | 344 | buttons, blurbs, CTAs, headers, testimonials, social |
| `D2G_Renderer_Interactive` | 232 | toggles, tabs, sliders, counters |
| `D2G_Renderer_Pricing` | 148 | pricing tables |
| `D2G_Renderer_Dynamic` | 364 | loops, portfolios, menus, forms, search |

Three decisions worth recording:

**Dispatch is built from the renderers, not maintained beside them.** Each
declares a `tag => method` map; the converter assembles the table by asking
them. A tag cannot be routed to a method that does not exist, and two renderers
cannot silently claim the same tag — both are caught at registration. The
170-line switch is gone.

**The sanitisers moved into the builder.** N-01 happened because each renderer
answered "what is safe inside a quoted attribute" for itself, and the answers
disagreed. There is now one answer, in one place, and every renderer uses it.

**Renderers hold no state.** Anything shared — warnings, recursion, the HTML
engine — is reached through the converter, which is passed in as the render
context. That is what keeps the determinism check meaningful.

### How it was verified

Not by reading it. Mechanically:

```text
Golden snapshots, before vs after      139 files, byte-identical
Dispatch table vs the old switch       55 tags, none lost, none added
Full suite                             154 passed, 0 failed
PHP 7.4 / 8.1 / 8.2 / 8.3 / 8.4 / 8.5  154 passed, 0 failed on each
Real WordPress block validation        396 blocks, 0 invalid
```

The dispatch comparison was a script that extracted the `case` labels from the
pre-refactor file and diffed them against the new table. It reported no tags
lost and none added, and confirmed that the three tags which deliberately fall
through — `et_pb_contact_field`, `et_pb_map_pin`, `et_pb_video_slider_item`,
each consumed by its parent module — still do. That check is now permanent: the
table is snapshotted in `tests/golden/dispatch-table.txt`, so a change in
ownership shows up as a reviewable diff.

Line coverage was re-measured afterwards: 89.0% across all conversion classes,
92–99% per renderer.

**Two things the split did *not* do**, since the point was that nothing should
change: no renderer's logic was rewritten, and no output differs by a byte. Any
improvement to a renderer is a separate change, and now has a much smaller file
to happen in.

---

## A defect found while verifying, not in the review

### N-12 — Divi attribute text is double-encoded · Fixed

Found by converting a realistic page end to end after the fixes above, and
checking every input string survived. It did not appear in either review.

Divi stores shortcode attribute values HTML-encoded, so a button reading
"Fish & Chips" is stored as `button_text="Fish &amp; Chips"`. The converter ran
`esc_html()` straight over that, encoding the ampersand a second time, and the
page published the literal characters:

```text
[et_pb_toggle title="Terms &amp; conditions"]
→ <summary>Terms &amp;amp; conditions</summary>     which displays as: Terms &amp; conditions
```

It reproduces on the 2.1.0 tree and on every earlier release. It affected every
title, heading, subhead, author, company, name, position, label, button text,
address and image `alt` the converter drew from an attribute — 46 call sites.

`D2G_Converter::text()` and `::attr()` decode once, then escape. Decoding runs
*before* escaping, so it cannot weaken the escape: a value of
`&lt;script&gt;alert(1)&lt;/script&gt;` decodes to `<script>…` and is then
escaped back to `&lt;script&gt;…`, which is what should have happened to it in
the first place. Two fixtures assert exactly that, one for each polarity.

Values that come from WordPress rather than Divi — attachment alt text, gallery
captions — are deliberately left alone. Those are stored plain, and decoding
them would be the mirror-image bug.

This is the same failure mode as N-03d, where the counter body was escaped as
markup: a value escaped at the wrong point in its lifecycle. Worth noting that
neither review's static reading caught it, and neither did the fixture suite —
an end-to-end conversion with a "does every input string appear in the output"
check did. That check is worth keeping as a habit.

---

## Round one: the F-findings

The review re-verified all seventeen findings from the previous round
independently. Its verdicts are accepted as printed, including the three
downgrades. Two are affected by this release:

- **F-03** — the review marked it partly fixed because Fullwidth Header still
  emitted nested `<p>` elements. That is N-02, now fixed, and the recursive
  assertions mean the whole class is now detectable rather than only the
  instances someone remembered to write a fixture for.
- **F-13** — marked partly fixed because a `]` in a quoted attribute still
  corrupted the tag and the fixture named for it did not test that. Both are now
  fixed (N-03, N-06).

The rest stand as the review recorded them. F-06, F-07, F-08, F-09, F-11, F-16
and F-17 remain partly fixed, with the outstanding parts tracked as Q11, Q15,
Q18, Q22, Q23 and now Q27.

---

## What is not fixed

1. **`Tested up to:` is still a placeholder** and no live WordPress run has
   happened (N-08, Q18, Q23). This blocks WordPress.org submission and is the
   single largest remaining risk.
2. **No endpoint, database, JavaScript or uninstall tests** (N-06, Q27). The
   *converter* is now thoroughly covered — see
   [Making the suite strong enough](#making-the-suite-strong-enough-to-protect-a-refactor)
   — but this release added untested endpoint logic: the atomic lock, the
   mandatory source token, the exact meta snapshot. That gap is larger than it
   was, not smaller, and it is the main remaining test debt.
3. **The scan is still a leading-wildcard `LIKE`** with no resumable inventory
   (N-07, Q11).
4. **KSES on multisite is unresolved** (F-09, Q15). The review's guidance —
   require `unfiltered_html`, or block and explain what would be stripped, but
   do not bypass KSES with a direct database write — is accepted as the shape of
   the answer. It is a product decision, not a code change, and it has not been
   made.
5. ~~**The converter is still one 2,300-line class**~~ (N-10, F-16) — **done**.
   See [The refactor](#the-refactor-one-class-becomes-eleven).
6. **Loss reporting is name-pattern based** and can miss a setting whose
   attribute name follows no existing pattern (N-05, Q28).
7. **Table captions are preserved but not editable**, via the `core/html`
   fallback rather than a native `core/table` caption (N-03, Q26).

---

## Verification record

Run on this machine, 2026-08-05:

```text
PHP lint, all tracked PHP files            PASS (PHP 8.1.2)
JavaScript syntax, admin/js/admin.js       PASS (node --check)
Shell syntax, bin/build-zip.sh             PASS
Converter suite, PHP 8.1                  154 passed, 0 failed
Converter suite, PHP 7.4/8.2/8.3/8.4/8.5  154 passed, 0 failed each (Docker)
Real WordPress block validation           396 blocks, 0 invalid
Module coverage                            58/58 supported Divi modules
Dispatch table                             55 tags, matches the old switch
Line coverage, all conversion classes      89.0% (95% excluding the dead
                                           style mapper; 92-99% per renderer)
Golden snapshots                           140 committed, all current
git diff --check                           PASS
Version agreement (header/const/tag)       PASS — all 2.2.0
```

Probes that failed before and pass now:

```text
Crafted Image align attribute              value dropped; no attribute injected
Crafted Button alignment attribute         value dropped; no attribute injected
Crafted colour value                       value dropped; no CSS injected
Fullwidth Header with two paragraphs       two sibling paragraph blocks
Table with <caption>Rates</caption>        preserved whole as core/html + warning
Text module with nested Button             Button rendered in source order
Counter with <p>Sales</p> body             <strong>Sales</strong>: 70%
Bracket inside shortcode attribute         parsed correctly; no stray output
HTML comment in Text module                preserved as core/html
Curly-quote entities in body text          unchanged
Ampersand in a Divi attribute value        encoded once, not twice (N-12)
Unsupported section padding                warned
Tabs converted to stacked Groups           warned
```

Not done, unchanged from the review's list:

- Live WordPress installation and conversion.
- ~~Real block-editor open/save validation.~~ **Done** — core's own parser and
  save() comparison now runs over every fixture. See Q23.
- WordPress 6.0 through current compatibility matrix.
- Multisite conversion, restore, and uninstall test.
- Production database performance test.
- Real Divi page corpus test.
- Visual comparison with Divi output.
- Live WordPress *compatibility* per release. Output validity is now proven
  against the real block library, which is a different claim.
