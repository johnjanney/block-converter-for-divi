# Block Converter for Divi — Installation & Usage

Converts pages built with the Divi Builder into native Gutenberg blocks,
preserving content, images, and design intent.

---

## Requirements

| | |
| --- | --- |
| WordPress | 6.0 or later. 6.3+ recommended — Divi toggles and accordions convert to `core/details`, which arrived in 6.3; on 6.0–6.2 they degrade to a heading plus text |
| PHP | 7.4 or later. The `dom` extension is recommended: without it, rich text is preserved in Custom HTML blocks instead of being split into individual blocks |
| User role | Administrator (`manage_options`), and edit permission on each page you convert |
| Divi | **Not required.** The plugin reads raw shortcodes, so it works after Divi is deactivated |

---

## Before you start — read this

**Conversion overwrites `post_content`.** It can be undone with the **Restore**
button — but *only if the backup checkbox was ticked when you converted*.
Without a backup, recovery means a WordPress revision or a database restore.

Do this first:

1. **Take a full database backup.** Not optional. Use your host's backup tool,
   UpdraftPlus, WP Migrate, or `wp db export`.
2. **Test on a staging copy of the site**, not production.
3. **Convert one page first** and inspect the result in the block editor before
   running a batch.
4. **Leave the Divi theme/plugin active until you are satisfied** with the
   converted pages. Deactivating Divi before conversion leaves unconverted pages
   rendering as raw shortcode text on the front end.

---

## Installation

### Option A — Upload the ZIP (recommended)

1. Download `block-converter-for-divi-X.Y.Z.zip` from the
   [Releases page](https://github.com/johnjanney/block-converter-for-divi/releases).
   Pick the version you want — older versions are kept permanently.
2. In WordPress: **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP, click **Install Now**, then **Activate Plugin**.

### Option B — Manual / FTP

1. Unzip the archive locally. It contains a single `block-converter-for-divi/` folder.
2. Upload that folder to `wp-content/plugins/` on the server.
3. In WordPress: **Plugins**, find *Block Converter for Divi*, click
   **Activate**.

### Option C — From source

```bash
cd wp-content/plugins
git clone https://github.com/johnjanney/block-converter-for-divi.git
```

Then activate under **Plugins**. Source checkouts track `main` and may be ahead
of the last release — see [`CHANGELOG.md`](CHANGELOG.md).

### Verify

Log in as an administrator and open **Tools → Block Converter for Divi**. If the screen
loads, the plugin is working.

---

## Usage

### 1. Scan for Divi pages

Go to **Tools → Block Converter for Divi** and click **Scan for Divi Pages**.

The plugin searches all `page` and `post` records with status *published*,
*draft*, *private*, or *pending* and lists everything that either **contains
`[et_pb_` shortcodes** or **has a backup from a previous conversion**. The title
links to that page's editor in a new tab.

Already-converted pages therefore stay in the list, with their Convert button
greyed out and a **Restore** button available.

**Narrowing the list**

- **Show** — limit to pages only, posts only, or both.
- **Sort by** — title, date, type, or status, ascending or descending.
- **Per page** — 20, 50, 100, or all. "All" is capped at 500 rows so the scan
  cannot exhaust memory on a large site; when the cap applies, the status line
  above the table says so and gives the true total. Work through the pages with
  a smaller per-page setting to reach everything beyond the cap. Site owners who
  know their site is small can raise the cap with the `d2g_scan_hard_cap`
  filter.
- **Column headers** — click Title, Type, Status, or Date to sort by it; click
  the active column again to reverse the direction.

All of these apply to the entire result set on the server, not just the rows
currently on screen. Changing any of them returns you to page 1. Use the
« ‹ › » controls beneath the table to move between pages.

**Backup column** — shows the date a backup was taken, or `—` if the page has
none. Only pages with a backup can be restored.

### 2. Preview a conversion

Click **Preview** on any row. A modal opens showing the original Divi shortcode
content on the left and the Gutenberg block markup it would produce on the
right. **Nothing is saved.** Close with the × or by clicking outside the modal.

Preview everything you care about before converting. This is the only
non-destructive way to check the output.

### 3. Convert a single page

1. Leave **Create backup before converting** ticked (strongly recommended).
2. Click **Convert** on the row and confirm the prompt.

The plugin will:

- Copy the original content to the `_d2g_divi_backup` post meta key with a
  `_d2g_backup_date` timestamp (if backup is ticked)
- Replace `post_content` with the converted block markup
- Delete the `_et_pb_use_builder` and `_et_pb_old_content` meta so WordPress
  opens the page in the block editor rather than the Divi Builder

The row turns green and the button reads **Converted**.

### 4. Convert several pages at once

1. Tick the checkbox on each row you want, or use the header checkbox to select
   everything on the current page of results.
2. Click **Convert Selected** and confirm.

Pages are converted one at a time with a running `done / total` counter. Rows
that fail turn red and the error is shown in the status bar; the batch continues
past failures. Selection applies only to the currently visible page of
results — repeat per page.

**Keep the browser tab open** until the batch finishes. Navigating away stops it
partway through.

### 5. Check the result

Open the converted page in the block editor.

- Confirm there are no "This block contains unexpected or invalid content"
  warnings.
- Check text, images, links, and embedded video survived.
- Compare the front end against the original layout.
- Look for placeholder text (see below) and rebuild those areas manually.

---

## What converts, and what needs manual work

Most Divi modules map to a core block — sections become groups, rows become
columns, text becomes paragraphs or headings, images become image blocks,
galleries become gallery blocks, sliders become stacked cover blocks, and so on.
The full mapping table is in [`BRIEF.md`](BRIEF.md#5-module-coverage).

**These convert to placeholders and need rebuilding by hand:**

| Divi module | What you get | What to do |
| --- | --- | --- |
| Maps (`et_pb_map`) | Address / coordinates / pin names as text | Insert a map block or embed |
| Sidebar (`et_pb_sidebar`) | `[Sidebar: …]` placeholder text | Insert the equivalent widget blocks |
| Email Optin (`et_pb_signup`) | Heading + description + placeholder | Insert your mailing-list provider's block |
| Shop (`et_pb_shop`) | `[WooCommerce Products …]` placeholder | Insert WooCommerce product blocks |

**Also not carried over:**

- Divi Theme Builder templates — headers, footers, and global layouts
- Divi Library / global modules
- Hover states, animations, and per-breakpoint responsive spacing
- Divi theme options and theme-level custom CSS
- Gallery carousel behaviour — carousels get a `d2g-gallery-slider` class but no
  slider script; they render as a standard gallery unless your theme styles it

---

## Restoring a page

### Using the Restore button

If a conversion goes wrong, find the page in the scan results and click
**Restore**. Pages with a backup show the button and a date in the Backup
column; pages without one show `—` and no button.

Restoring will:

- Replace the current Gutenberg content with the original Divi shortcodes
- Put the Divi builder meta back exactly as the backup found it. Both
  `_et_pb_use_builder` and `_et_pb_old_content` are cleared first, then only the
  rows that existed before the conversion are written back — so a key that was
  absent stays absent and one that held an empty value comes back empty. Pages
  backed up by version 1.x predate that snapshot; for those, restore falls back
  to setting `_et_pb_use_builder` to `on`
- **Keep** the backup, so the page can be converted and restored again

The row turns yellow and the Convert button becomes available again.

> The Divi theme or plugin must still be installed and active for a restored
> page to render properly on the front end.

### If there is no backup

**Option 1 — WordPress revisions.** Open the page, expand the **Revisions**
panel in the sidebar, and restore the revision from before the conversion.
Works as long as revisions are enabled.

**Option 2 — The backup meta by hand.** If the meta exists but the button is not
available for some reason, restore it with WP-CLI:

```bash
# Inspect the backup first
wp post meta get <POST_ID> _d2g_divi_backup

# Restore it
wp post update <POST_ID> --post_content="$(wp post meta get <POST_ID> _d2g_divi_backup)"

# Re-enable the Divi Builder for that page
wp post meta update <POST_ID> _et_pb_use_builder on
```

**Option 3 — Your database backup.** The reason step 1 of "Before you start"
exists.

---

## Troubleshooting

**"Tools → Block Converter for Divi" doesn't appear**
You are not an administrator, or the plugin is not activated. The menu requires
the `manage_options` capability.

**Scan returns "No Divi pages found" but the site clearly uses Divi**
The scan only looks at `page` and `post` types. Divi content on custom post
types (including Divi Projects) is not detected. Theme Builder templates are
also stored separately and are not scanned.

**"Network error during scan" or the scan hangs**
Very large sites can exceed the PHP execution limit on the count query. Raise
`max_execution_time`, or check `wp-content/debug.log` after enabling
`WP_DEBUG_LOG`.

**Converted page shows "This block contains unexpected or invalid content"**
Click **Attempt Block Recovery** on the affected block. If it persists, note the
Divi module it came from and open an issue with the original shortcode — these
are conversion bugs and have been the main source of past fixes.

**Batch conversion stalls partway**
The browser tab was backgrounded or closed, or one page failed and the status
bar shows the error. Re-scan; already-converted pages reappear with Convert
disabled, so you can see what did and did not complete.

**A page has no Restore button**
No backup was taken for it — the backup checkbox was unticked at conversion
time, or the page was never converted by this plugin. See *If there is no
backup* above.

**Restore says "No backup found for this page"**
The `_d2g_divi_backup` meta is missing or empty. Check with
`wp post meta get <POST_ID> _d2g_divi_backup`.

**Converted or restored content is missing HTML on a multisite install**
WordPress strips markup for users without the `unfiltered_html` capability,
which on multisite is granted only to super admins. Run the conversion as a
super admin.

**The page still opens in the Divi Builder after conversion**
`_et_pb_use_builder` was not removed — the conversion likely errored before that
step. Check the status message, and clear it manually:
`wp post meta delete <POST_ID> _et_pb_use_builder`.

**Front end looks unstyled after deactivating Divi**
Expected. Divi's stylesheet was providing the visual layer. Converted blocks are
styled by your new theme; budget time for theme-side styling after migration.

---

## Uninstalling

Deactivate and delete under **Plugins**. Converted pages are unaffected —
Gutenberg block markup is native WordPress content and does not depend on this
plugin.

### What happens to your backups

**By default, deleting the plugin keeps every backup.** They are the only way to
restore a converted page, so removing the plugin does not throw away your
ability to roll back. The meta stays in the database and the Restore capability
returns if you reinstall.

Once your migration is finished and you are certain you will not need to roll
anything back, tick **Delete all Divi backups when this plugin is deleted** in
the *Data retention* box on the tools screen. With that on, deleting the plugin
removes all `_d2g_divi_backup`, `_d2g_backup_date`, and `_d2g_builder_meta` meta
across that site.

**On multisite the setting is per site, not network-wide.** Deleting the plugin
walks every site in the network, but it reads each site's own setting and only
clears backups on the sites where an administrator turned it on. A site that
left the setting off keeps its backups even when the plugin is deleted
network-wide. This is deliberate: one administrator should not be able to
destroy another site's only rollback path. To clear a whole network, turn the
setting on for each site first — or use `wp post meta delete` with
`wp site list` to script it.

The setting only takes effect when the plugin is **deleted**, not deactivated,
and it cannot be undone afterwards. Turning it on asks for confirmation.

### Clearing backups by hand

To remove a single page's backup without touching the setting:

```bash
wp post meta delete <POST_ID> _d2g_divi_backup
wp post meta delete <POST_ID> _d2g_backup_date
wp post meta delete <POST_ID> _d2g_builder_meta
```

All three keys belong to one snapshot. Deleting only the first two leaves
`_d2g_builder_meta` behind as an orphan row.

---

## Getting help

- Known gaps and risks — [`BRIEF.md`](BRIEF.md#7-known-gaps--risk-register)
- Unresolved design decisions — [`OPENQUESTIONS.md`](OPENQUESTIONS.md)
- Release history — [`CHANGELOG.md`](CHANGELOG.md)
- Issues — https://github.com/johnjanney/block-converter-for-divi/issues

When reporting a conversion bug, include the original Divi shortcode (the
Preview modal's left pane), the block markup produced (right pane), and your
WordPress and PHP versions.
