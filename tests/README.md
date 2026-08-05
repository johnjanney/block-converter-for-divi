# The converter test suite

```bash
npm --prefix tests/js ci          # once, to enable real block validation
php tests/run.php                 # everything
```

Requires **Node 22 or newer** — jsdom's floor. `npm ci` refuses to install on
anything older rather than installing and letting a transitive dependency throw
later, which is how a Node 20 CI runner got as far as a green install and a
mystifying validator crash.

The suite exists to answer two questions the project could not answer before:

1. **Will WordPress accept this output?** Not "does it look plausible" — will
   the editor open a converted page without saying *this block contains
   unexpected or invalid content*.
2. **Did a change alter output nobody was asserting on?** This is what makes it
   safe to restructure the converter.

---

## The layers

Each catches a class of defect the one before it cannot see. They run in one
command, cheapest first.

| Layer | Lives in | Catches |
| --- | --- | --- |
| 1. Fixture assertions | `fixtures.php` | What a fixture is specifically for — `expect`, `reject`, `warns`, `rejectWarnings` |
| 2. Structural checks | `lib/assertions.php` | House style, stricter than core: delimiter balance, block/attribute agreement, no surviving shortcodes. Applied at **every** block depth |
| 3. Golden snapshots | `lib/golden.php`, `golden/` | Byte-exact output *and* the set of warnings raised. The layer that protects a refactor |
| 4. Real block validation | `lib/blockcheck.php` → `js/validate.mjs` | Core's own parser and `save()` comparison — the actual editor verdict |
| 5. Module coverage | `lib/coverage.php` | A supported Divi module with no fixture |
| 6. Dispatch table | `lib/coverage.php` | Two renderers claiming a tag, a tag routed to a missing method, or a tag quietly ceasing to be dispatched |

Plus two invariants checked per fixture, in `run.php`:

- **Determinism** — converting the same input twice produces identical output.
  Catches a refactor that introduces a static cache or an unreset buffer.
- **Idempotence** — converted output contains no Divi content, and converting it
  again is a no-op. The convert endpoint's "already converted" guard depends on
  exactly this.

---

## Layer 4 is the important one

`js/validate.mjs` registers the real core blocks from `@wordpress/block-library`
and runs core's `parse()`, which re-runs each block's `save()` over the parsed
attributes and compares. Nothing in it reimplements a WordPress rule.

It found three genuine defects on its first runs that four rounds of static
checks had missed:

- `core/cover` emitted its `<span>` and `<img>` in the wrong order, omitted
  `aria-hidden` and `has-background-dim-100`, and dropped the overlay colour —
  so **every** converted Cover was invalid.
- A coloured `core/separator` needs `has-text-color` and a `color` declaration
  as well as the background ones.
- `core/comments` was emitted self-closing when its `save()` writes a wrapper
  `<div>`.

Core is authoritative about what the editor accepts. It is also more permissive
than this project wants to be — a block that only validates through a
deprecation passes here and still means the converter wrote markup a current
WordPress would not have. That is why layer 2 stays.

### `js/canonical.mjs`

A development aid, not part of the run. Asks WordPress what it *would* have
saved, which is how the Cover and Separator markup above was fixed:

```bash
node tests/js/canonical.mjs '{"name":"core/cover","attributes":{"url":"https://example.com/a.jpg"}}'
```

Use it whenever you hand-write block markup. Guessing is what produced the bugs.

---

## Golden snapshots

`golden/*.txt` holds each fixture's exact converted markup and the warnings it
raised. Any difference fails the run and prints a diff.

Changing output on purpose:

```bash
php tests/run.php --update-golden    # then review the diff before committing
```

The diff **is** the review. If a change to one renderer rewrites forty
snapshots, that is the signal, not an inconvenience.

A new fixture fails until its snapshot is committed. That is deliberate:
accepting output should be an explicit act.

---

## Coverage

Layer 5 asserts every tag in `D2G_Parser::known_tags()` has a fixture, reading
the list from the parser so adding a module and forgetting to test it fails.

Line coverage is not measured in CI (no extension is installed), but it was
measured while building this, and the gaps became the `branch …` fixtures:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.1-cli \
  sh -c 'pecl install xdebug >/dev/null 2>&1 && docker-php-ext-enable xdebug \
         && XDEBUG_MODE=coverage php /app/tests/coverage.php'
```

Last measured: **89.0%** across all conversion classes — or **95%** excluding
the style mapper below. The renderers are 92–99% each; the remainder is code
guarded by `function_exists()` for WordPress functions the shim deliberately
leaves undefined, plus defensive returns in the dispatch code.

`class-d2g-style-mapper.php` measures **6%**, and that number is correct and
worth keeping visible: every function in it except `text_align_class()` is dead
code the converter never calls. That is the subject of **Q22**, and now it is a
measurement rather than a claim.

---

## Flags

| Flag | Effect |
| --- | --- |
| `-v` | Print each fixture's converted markup |
| `"substring"` | Run only fixtures whose name matches |
| `--update-golden` | Rewrite snapshots from current output |
| `--no-blocks` | Skip layer 4 (no Node needed) |
| `--require-validator` | Fail if layer 4 could not run. Used by `bin/build-zip.sh` and CI |

## The browser suite

```bash
bash bin/e2e.sh
```

The Tools screen driven by Playwright against `wp-env`. It exists mainly for the
batch runner: that is where 2.0.0 counted failed pages as successes and told the
user everything had converted, and nothing tested it.

Its central test seeds four pages, selects three, then edits one of them behind
the browser's back — via a test-only mu-plugin — so its source token goes stale
and the endpoint refuses it. The batch must then report **two converted, one
failed**, and name the page that failed.

The browser runs inside Playwright's own container. That is not a preference:
Chromium needs system libraries this project cannot assume, and pinning the
container to the installed Playwright version means the run does not depend on
the host. It needs `--network host`, so Linux (including GitHub runners).

Two real defects came out of writing it, both invisible to every other layer:

- A successful single conversion set **no status message at all**. `#d2g-status`
  is the screen's `aria-live` region, so a failed conversion spoke and a
  successful one was silent.
- Conversion warnings were returned by the server and **dropped by the browser**.
  They were rendered for a preview and nowhere else, so anyone who clicked
  Convert without previewing never learned what could not be carried over —
  which is the most direct path through the screen.

Each test re-seeds first. That is not tidiness: conversions are not reversible
from the next test's point of view, and without it the restore test kept finding
a page the convert test had already converted.

## What runs where

| | Offline suite | Live suite | Browser suite | Version matrix |
| --- | --- | --- | --- | --- |
| Command | `php tests/run.php` | `bash bin/live-check.sh` | `bash bin/e2e.sh` | `bash bin/wp-matrix.sh` |
| Needs | PHP (Node 22 for layer 4) | Docker, Node 22 | Docker, Node 22 | Docker, Node 22 |
| Takes | ~3s | ~2 min | ~1 min | ~15 min |
| CI | every push and PR | every push and PR | every push and PR | manual, from the Actions tab |
| Release gate | `bin/build-zip.sh` | — | — | before a release |

Without `--require-validator`, a missing Node harness prints a loud
`NOT RUN — block validity is therefore UNPROVEN` and continues. It never
silently reports a pass it did not make.

---

## The live suite

Everything above runs against a shim. `tests/live/` runs against a real
WordPress in Docker:

```bash
bash bin/live-check.sh
```

It starts `wp-env`, then checks the things that are not the converter and
therefore cannot be checked offline:

- **The endpoint contract** — a bad nonce, a missing source token, a stale
  source token, an editor without `manage_options`, and lock exclusivity.
- **The database round trip** — every fixture is inserted as a post, converted
  through the real AJAX endpoint, and compared byte for byte against the
  offline conversion. `wp_slash()`, KSES and MySQL all sit in that gap, and the
  worst defect this plugin ever shipped lived there.
- **Restore byte-identity**, on every fixture.
- **Real block validation of stored content** — what comes back *out of the
  database* is fed to `js/validate.mjs`, not what the converter emitted.
- **WordPress's own debug log**, which must be empty. A notice or deprecation
  is a finding even when every assertion passes.

The log is truncated at the start of each run, so what it holds afterwards
belongs to that run.

To test another WordPress version, create `.wp-env.override.json`:

```json
{ "core": "WordPress/WordPress#6.1", "phpVersion": "7.4" }
```

Or run the whole matrix, which does that for you on a clean environment per
version — reusing one carries a bundled theme forward that older core cannot
load:

```bash
bash bin/wp-matrix.sh            # the default set
bash bin/wp-matrix.sh 6.5 6.6    # only these
```

This is what found that `Requires at least: 6.0` was wrong. Last full run:

| WordPress | PHP | Result | Blocks not registered |
| --- | --- | --- | --- |
| 6.0 | 7.4.33 | **fail** | `core/comments`, `core/list-item` |
| 6.1 | 7.4.33 | pass | — |
| 6.2 | 8.0.30 | pass | — |
| 6.3 | 8.0.30 | pass | — |
| 6.8 | 8.2.33 | pass | — |
| 7.0.2 | 8.3.33 | pass | — |

The block count rises from 32 to 33 at 6.3: that is `core/details` becoming
available and the feature detection stopping. Offline, that path can only be
tested by stubbing the registry.

## What this still does not cover

Stated here so a green run is not read as more than it is:

- **Block *validity* on older WordPress.** `bin/wp-matrix.sh` runs the live
  suite across versions and asserts every emitted block is *registered* there —
  which is how the 6.0 floor was found to be wrong. But that check is PHP, and
  PHP cannot run a block's `save()`. Validity is still only proven against the
  single block library pinned in `js/package-lock.json`, and core block markup
  does change between versions (the Cover fix in 2.2.0 is exactly such a
  change). Older `@wordpress/block-library` majors are published, so per-version
  JS validation is feasible; it is not built. **Q18**.
- **Multisite**, where KSES strips markup for any non-super-admin. **Q15**.
- **The admin screen beyond the paths above** — pagination, per-page sizes, the
  select-all checkbox, and multi-page batches are not covered.
- **Uninstall.**
- **A corpus of real Divi pages.** Every fixture here was written by someone who
  already knew what the converter does.
- **Whether a converted page *looks* like the Divi original.** Valid is not
  faithful, and nothing here measures fidelity.
