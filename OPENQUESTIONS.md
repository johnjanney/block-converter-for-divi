# Open Questions

Running log of unresolved decisions for **Divi to Gutenberg Converter**.

**How to use this file:** add new questions to the Open table with the next
sequential ID and the date raised. When a question is answered, move its row to
the Resolved section, record the resolution date, the decision, and where the
decision was implemented. Never delete a resolved question — the history of why
a decision was made is the point of the file.

**Priority:** `P1` blocks a release · `P2` needed soon · `P3` nice to settle

---

## Open

| ID | Raised | Pri | Area | Question |
| --- | --- | --- | --- | --- |
| Q5 | 2026-08-04 | P2 | Scope | Divi Theme Builder templates (headers, footers, global layouts) and Divi Library global modules are out of scope. Is that permanent, or a phase-2 deliverable? Without them a "converted" site still needs Divi active for its chrome. |
| Q6 | 2026-08-04 | P2 | Fidelity | `et_pb_map`, `et_pb_sidebar`, `et_pb_signup`, and `et_pb_shop` emit text placeholders. Should they instead emit a clearly-labelled `core/html` comment block that a user can find with a site search, and should the plugin surface a per-page "N modules need manual attention" report? |
| Q7 | 2026-08-04 | P2 | Fidelity | Gallery carousels get a `d2g-gallery-slider` class with no shipped CSS or JS. Do we ship a small front-end asset to honour it, convert carousels to a plain grid gallery, or document the class as a theme integration point? |
| Q8 | 2026-08-04 | P2 | Distribution | Is the target WordPress.org, GitHub releases only, or private distribution to client sites? This decides whether we need `readme.txt` in WP.org format, a stable-tag discipline, and whether an updater (e.g. a GitHub-based update checker) is required. |
| Q10 | 2026-08-04 | P3 | Quality | There are no automated tests over ~3,200 lines, and most historical commits are conversion-output bug fixes. Do we adopt fixture-based tests (Divi input → expected block markup) plus a block-validity assertion, and if so under PHPUnit + `wp-env` or a lighter standalone harness? |
| Q11 | 2026-08-04 | P3 | Scope | Should there be a WP-CLI command for batch migration? Browser-driven batch conversion runs sequential AJAX calls and will be slow and timeout-prone on sites with hundreds of Divi pages. |
| Q12 | 2026-08-04 | P3 | Compatibility | `core/details` (WP 6.3+) and `core/form` / `core/form-input` (WP 6.5+, experimental) are emitted, but the plugin header declares `Requires at least: 5.0`. Do we raise the minimum, or feature-detect and degrade those modules on older WordPress? |
| Q14 | 2026-08-04 | P3 | Scope | Should the plugin offer to convert Divi custom post types beyond `page` and `post` — e.g. `project` (Divi Projects), or arbitrary CPTs a site has enabled the builder on? The scan is currently hardcoded to `('page','post')`. |
| Q15 | 2026-08-04 | P2 | Safety | `wp_update_post()` passes content through `wp_filter_post_kses` for users without the `unfiltered_html` capability. Single-site admins have it; on **multisite only super admins do**, so a site admin converting or restoring could have markup silently stripped from `post_content`. Do we require `unfiltered_html`, warn on multisite, or write content via `$wpdb` to bypass filtering? |
| Q16 | 2026-08-04 | P3 | Safety | Restore re-adds `_et_pb_use_builder` but cannot restore `_et_pb_old_content`, which conversion deletes and never backed up. Should the backup snapshot capture all `_et_pb_*` meta rather than just `post_content`? |

---

## Resolved

| ID | Raised | Resolved | Question | Decision | Implemented in |
| --- | --- | --- | --- | --- | --- |
| Q1 | 2026-08-04 | 2026-08-04 | Backup meta was written but never read — ship a restore UI, rely on revisions, or drop the backup? | **Ship a restore UI.** Revisions are unreliable (they can be disabled or pruned) and the backup already existed. Added a `d2g_restore_page` endpoint plus a per-row **Restore** button that also re-enables the Divi Builder. The backup meta is kept after restoring so the action is repeatable. | `divi2gutenberg.php` (`ajax_restore_page`), `admin/js/admin.js`, v1.1.0 |
| Q2 | 2026-08-04 | 2026-08-04 | Cut accumulated work as `1.1.0` or `1.0.1`? | **`1.1.0`.** The release adds features (restore, working filters, pagination), which SemVer puts at MINOR. | `divi2gutenberg.php` header + `D2G_VERSION`, `CHANGELOG.md` |
| Q3 | 2026-08-04 | 2026-08-04 | Implement the inert filter/sort controls, or delete the markup? | **Implement.** All three selects and the sortable headers now drive the server-side query, and the filter bar shows after a scan — including on an empty result set, so a filter matching nothing can be undone. | `admin/js/admin.js`, `admin/class-d2g-admin.php`, `admin/css/admin.css`, v1.1.0 |
| Q4 | 2026-08-04 | 2026-08-04 | Should per-page drive a POST parameter or persist to the user option? | **POST parameter**, whitelisted server-side to 20/50/100/all. `edit_per_page` remains the fallback when no valid value is sent. Persisting was rejected as surprising — it would silently change the Posts list screen too. | `divi2gutenberg.php` (`ajax_scan_pages`), v1.1.0 |
| Q13 | 2026-08-04 | 2026-08-04 | Missing `uninstall.php` and `LICENSE`; on uninstall should backup meta be purged (clean) or retained (safe)? | **Both, via an opt-in setting that defaults to retain.** Purging by default would mean deleting the plugin silently destroys the only rollback path for every page it converted; retaining unconditionally would leave potentially megabytes of orphaned meta with no way to clear it. A "Delete all Divi backups when this plugin is deleted" checkbox (off by default, confirmation on enable) resolves both. The preference row itself is always removed, so a reinstall starts from the safe default. `LICENSE` is the canonical GPL-2.0 text (`md5 b234ee4d…`). | `uninstall.php`, `LICENSE`, `divi2gutenberg.php` (`ajax_save_settings`), `admin/class-d2g-admin.php`, `admin/js/admin.js` |
| Q9 | 2026-08-04 | 2026-08-04 | Do we need a `_d2g_converted` flag to prevent double conversion? | **No separate flag needed.** The scan now returns a `has_divi` value computed in SQL; rows without Divi content render with Preview, Convert, and their checkbox disabled. That is derived from actual content, so it cannot drift out of sync the way a stored flag would. | `divi2gutenberg.php` (`ajax_scan_pages`), `admin/js/admin.js`, v1.1.0 |
