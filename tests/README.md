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

Without `--require-validator`, a missing Node harness prints a loud
`NOT RUN — block validity is therefore UNPROVEN` and continues. It never
silently reports a pass it did not make.

---

## What this still does not cover

Stated here so a green run is not read as more than it is:

- The plugin bootstrap, AJAX endpoints, capability and nonce checks, the scan
  SQL, the write lock, backup and restore state, uninstall. None of it is
  loaded here — it needs a WordPress install. Tracked as **Q27**.
- The admin JavaScript, including batch result handling.
- Any WordPress version. Layer 4 proves the output is valid against the block
  library pinned in `js/package-lock.json`, which is not the same as proving a
  given WordPress release opens it. Tracked as **Q18**.
- Whether a converted page *looks* like the Divi original. Valid is not
  faithful, and nothing here measures fidelity.
