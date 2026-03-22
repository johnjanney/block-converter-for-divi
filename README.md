# Divi to Gutenberg Converter

A WordPress plugin that converts pages built with the Divi Builder into native Gutenberg blocks, preserving content, images, and design intent.

**Plugin URI:** https://github.com/johnjanney/divi2gutenberg
**Author:** John Janney
**License:** GPL-2.0-or-later
**Requires WordPress:** 5.0+
**Requires PHP:** 7.4+

## Features

- **Scan** — Detects all Divi-built pages and posts by content pattern (`[et_pb_*`) and the `_et_pb_use_builder` meta flag
- **Preview** — Side-by-side comparison of original Divi shortcodes vs. converted Gutenberg markup before committing changes
- **Convert** — Single-page or batch conversion with progress tracking
- **Backup** — Optionally stores the original Divi content in post meta (`_d2g_divi_backup`) before converting
- **Filter & Sort** — Filter results by post type (Pages / Posts / All) and sort by title, date, type, or status
- **Pagination** — Client-side pagination with configurable items per page (20, 50, 100, or All)

## Supported Divi Modules

| Category | Modules |
|---|---|
| **Layout** | Section, Row, Row Inner, Column, Column Inner |
| **Text & Media** | Text, Image, Button, Video, Audio, Blurb, CTA, Divider, Code |
| **Interactive** | Accordion, Toggle, Tabs, Slider, Video Slider |
| **Gallery & Data** | Gallery, Pricing Tables, Counters, Number Counter, Circle Counter |
| **People & Social** | Testimonial, Team Member, Social Media Follow |
| **Forms** | Contact Form, Signup, Login |
| **Blog & Portfolio** | Blog, Portfolio, Filterable Portfolio, Shop |
| **Navigation** | Menu, Fullwidth Menu, Search, Sidebar, Comments, Post Title |
| **Fullwidth** | Fullwidth Header, Fullwidth Image, Fullwidth Slider, Fullwidth Code, Fullwidth Post Slider |
| **Other** | Map, Map Pin, Post Slider |

Unrecognized modules fall back gracefully — children are rendered recursively, or the raw content is preserved in an HTML block.

## Gutenberg Block Mappings

Key conversions include:

- `et_pb_section` → `core/group`
- `et_pb_row` → `core/columns`
- `et_pb_column` → `core/column` (with Divi width percentages preserved)
- `et_pb_text` → `core/paragraph` (with rich HTML support for headings, lists, tables)
- `et_pb_image` → `core/image` (resolves WordPress attachment IDs)
- `et_pb_button` → `core/buttons` / `core/button`
- `et_pb_video` → `core/embed` (YouTube/Vimeo) or `core/video`
- `et_pb_accordion` / `et_pb_toggle` → `core/details`
- `et_pb_tabs` → `core/group` with headings per tab
- `et_pb_gallery` → `core/gallery`
- `et_pb_testimonial` → `core/group` with `core/quote`
- `et_pb_social_media_follow` → `core/social-links`
- `et_pb_contact_form` → `core/form`
- `et_pb_blog` → `core/latest-posts`
- `et_pb_portfolio` → `core/query` / `core/post-template`
- `et_pb_code` → `core/html`
- `et_pb_slider` → `core/group` with `core/cover` blocks

## Style Preservation

The converter maps Divi styling attributes to Gutenberg equivalents where possible:

- Background color and image
- Text color and alignment
- Custom padding and margin (Divi's pipe-delimited format)
- Max width, border radius, border width/color
- Box shadow, font size, line height
- Custom CSS (`custom_css_main_element`)

## Installation

1. Download or clone this repository into `wp-content/plugins/divi2gutenberg/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Navigate to **Tools → Divi to Gutenberg**

## Usage

1. Click **Scan for Divi Pages** to detect all Divi-built content
2. Use the filter and sort controls to find specific pages
3. Click **Preview** on any page to see the conversion result before applying it
4. Check **Create backup before converting** (enabled by default) to save the original content
5. Click **Convert** on individual pages, or select multiple pages and use **Convert Selected** for batch conversion

## File Structure

```
divi2gutenberg/
├── divi2gutenberg.php              # Main plugin file, AJAX handlers
├── admin/
│   ├── class-d2g-admin.php         # Admin page HTML
│   ├── js/admin.js                 # Scan, filter, pagination, convert UI
│   └── css/admin.css               # Admin page styles
└── includes/
    ├── class-d2g-parser.php        # Divi shortcode → tree parser
    ├── class-d2g-converter.php     # Tree → Gutenberg block converter
    └── class-d2g-style-mapper.php  # Divi styles → CSS/block attributes
```

## Known Limitations

- **Responsive design** — Divi responsive breakpoints are not carried over; converted blocks use static widths
- **Animations** — Divi scroll/hover animations are not preserved
- **WooCommerce Shop** — Converted to a placeholder; requires manual block setup
- **Sidebars** — Rendered as a placeholder paragraph
- **Sliders/Carousels** — Converted to static groups (a CSS class hint is added for custom styling)
- **Form processing** — Contact form markup is converted but backend handlers are not migrated

## Development

The conversion pipeline has three stages:

1. **Parse** (`D2G_Parser`) — Normalizes encoding, then recursively parses `[et_pb_*]` shortcodes into a tree of nodes with tag names, attributes, and children
2. **Convert** (`D2G_Converter`) — Walks the tree and dispatches each node to a module-specific converter method that outputs Gutenberg block comment markup
3. **Style** (`D2G_Style_Mapper`) — Maps Divi attributes (colors, spacing, fonts, borders) to inline CSS and Gutenberg block JSON attributes
