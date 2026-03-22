# Changelog

All notable changes to the Divi to Gutenberg Converter plugin are documented in this file.

## [1.1.0] - 2026-03-22

### Fixed
- **Scan returning no results** — The `$wpdb->prepare()` call interpreted `%` characters in the `LIKE '%[et_pb_%'` pattern as printf format specifiers, corrupting the SQL query and returning zero rows. Replaced with a direct query using escaped LIKE wildcards (`et\_pb\_`).
- **Missing post_date in query** — The scan referenced `post_date` in PHP but the SELECT clause did not include it, causing empty date values in the results table.
- **Undeclared JavaScript variables** — `allPages` and `filtered` were referenced in `loadPage()` but never declared, causing runtime errors.
- **DOM binding timing** — JavaScript selectors ran before `$(document).ready()`, which could result in empty jQuery objects and non-functional event handlers depending on script load order.

### Added
- **Meta-based Divi detection** — Scan now also checks the `_et_pb_use_builder` post meta flag via a LEFT JOIN, catching pages where the LIKE pattern alone might not match.
- **Client-side filtering** — Filter scan results by post type (All / Pages / Posts).
- **Client-side sorting** — Sort by title (A–Z / Z–A), date (newest / oldest), type, or status.
- **Per-page control** — Choose 20, 50, 100, or All items per page.
- **Date column** — Results table now displays the post date.
- **Improved error messages** — AJAX failure handler now shows HTTP status and error details instead of a generic message.

### Changed
- **Pagination moved client-side** — All Divi pages are fetched in a single query; pagination, filtering, and sorting happen in the browser for faster interaction. The previous server-side pagination approach (LIMIT/OFFSET with `$wpdb->prepare()`) was the root cause of the scan bug.
- **Scan response format** — `wp_send_json_success()` now returns a flat array of page objects instead of a wrapped `{pages, total_items, total_pages, ...}` envelope, since pagination is handled client-side.

## [1.0.0] - Initial Release

### Added
- Scan for Divi-built pages and posts via `[et_pb_*]` shortcode detection.
- Preview conversion with side-by-side comparison modal (original Divi vs. Gutenberg).
- Single-page and batch conversion with progress tracking.
- Optional backup of original Divi content to `_d2g_divi_backup` post meta.
- Support for 76 Divi modules across layout, text, media, interactive, social, form, blog, portfolio, navigation, and fullwidth categories.
- Divi style attribute mapping: background color/image, text color, alignment, padding, margin, max-width, border, box shadow, font size, line height, and custom CSS.
- Graceful fallback for unrecognized modules (children rendered recursively or preserved as HTML blocks).
- Image attachment ID resolution to maintain WordPress media library relationships.
- Embed detection and conversion (YouTube, Vimeo, iframes).
- Rich HTML parsing via DOMDocument for headings, lists, blockquotes, tables, and preformatted code.
- Admin page under Tools menu with scan button, backup toggle, results table, select-all, and batch convert bar.
