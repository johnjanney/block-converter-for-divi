# Codex Repository Review

**Repository:** Block Converter for Divi  
**Reviewed revision:** `709bd854e37469838552ed848c1df2c093f126d2` (`Release 2.0.0`)  
**Review date:** 2026-08-04  
**Review scope:** All tracked source files, project documents, the local 2.0.0 ZIP, local Git tags, and focused converter fixtures

## Executive assessment

The plugin has a useful purpose and a clear user flow. It can find Divi content, show a preview, convert one or more posts, keep a backup, and restore that backup. The code also has good basic controls for AJAX and SQL.

The current 2.0.0 release is **not ready for a production migration**. It does not meet the main success criteria in the project brief:

- It can remove backslashes from source content, saved block content, and backups.
- A repeated conversion request can replace the original backup with converted content.
- Common inputs produce block HTML that does not agree with the block attributes.
- Some advertised module mappings are not available in normal WordPress core.
- Pricing-table shortcodes can remain in the converted result.
- Most of the stated style mapper is not connected to the converter.
- There is no automated test suite or recorded WordPress compatibility test.

These defects conflict with the stated requirements of no content loss, no block validation errors, and preservation of design intent. See the project goal and success criteria in [BRIEF.md lines 25-30](BRIEF.md#L25-L30) and [BRIEF.md lines 236-243](BRIEF.md#L236-L243).

### Release decision

**Recommendation: do not publish or use 2.0.0 for production conversion.** First, correct findings F-01 through F-08. Then, run fixture tests and end-to-end tests on supported WordPress and PHP versions.

| Area | Assessment | Reason |
| --- | --- | --- |
| Purpose | Does not fully meet the objective | Several mappings lose function, leave shortcodes, or make invalid block markup. |
| Data safety | Not acceptable | The save and backup paths do not use the slashing required by WordPress. The original backup is not immutable. |
| Code quality | Needs major work | The 1,810-line converter has repeated manual markup, unused code, and no automated tests. |
| Performance | Suitable only for small sites | The scan uses a leading-wildcard content search, two large SQL queries, and an unbounded `all` mode. |
| Security | Basic controls are good, but gaps remain | Nonces, an administrator capability, prepared SQL, and much output escaping are present. Object checks and safe DOM insertion are incomplete. |
| Documentation | Detailed but inconsistent | The documents disclose many risks, but release, compatibility, module, and multisite claims do not agree with the code or current public state. |

## Severity model

- **High:** Can cause content loss, loss of the rollback path, a failed migration, or a false release claim.
- **Medium:** Can cause incorrect results, poor scale, incomplete authorization, or a difficult recovery.
- **Low:** Reduces maintainability, accessibility, localization, or user clarity.

## Findings

### F-01 — High — Save and backup operations can remove backslashes

**Verified facts**

The conversion path sends an unslashed array to `wp_update_post()`. The restore path does the same. The backup path sends raw `post_content` to `update_post_meta()`. See [block-converter-for-divi.php lines 291-303](block-converter-for-divi.php#L291-L303) and [block-converter-for-divi.php lines 364-373](block-converter-for-divi.php#L364-L373).

WordPress says that post-meta values are expected to be slashed and that `stripslashes()` runs before storage. It gives `wp_slash()` as the correction. WordPress also warns that `wp_update_post()` needs slashed `post_content` when backslashes must remain. See the official [`update_post_meta()` character-escaping documentation](https://developer.wordpress.org/reference/functions/update_post_meta/#character-escaping), [`wp_update_post()` documentation](https://developer.wordpress.org/reference/functions/wp_update_post/), and [`wp_slash()` documentation](https://developer.wordpress.org/reference/functions/wp_slash/).

**Impact**

A code module, JSON string, regular expression, CSS value, or other content with `\` can change during backup, conversion, or restore. The backup can already be damaged before conversion starts. This directly violates the no-content-loss requirement.

**Recommended correction**

1. Call `update_post_meta( $post_id, '_d2g_divi_backup', wp_slash( $post->post_content ) )`.
2. Pass `wp_slash( [ 'ID' => $post_id, 'post_content' => $converted ] )` to `wp_update_post()`.
3. Do the same for restored content.
4. Add fixtures for literal backslashes, JSON, JavaScript, CSS, regular expressions, and escaped quotes.
5. Compare a cryptographic hash of the source with the restored result.

### F-02 — High — A repeated request can destroy the original backup

**Verified facts**

The browser disables Convert after a successful request. See [admin/js/admin.js lines 337-355](admin/js/admin.js#L337-L355). The server does not check `D2G_Parser::has_divi_content()` before it updates the backup. It always uses `update_post_meta()`, which replaces the prior value. See [block-converter-for-divi.php lines 277-299](block-converter-for-divi.php#L277-L299).

The converter returns input unchanged when it does not find the Divi prefix. See [includes/class-d2g-converter.php lines 21-31](includes/class-d2g-converter.php#L21-L31).

The batch process does not disable each row's single Convert button. See [admin/js/admin.js lines 438-473](admin/js/admin.js#L438-L473). Thus, a user can send two requests for one post during a batch. A replayed AJAX request can do the same. WordPress nonces do not stop replay during their valid period. See the official [WordPress nonce documentation](https://developer.wordpress.org/apis/security/nonces/).

**Impact**

The second request can read the converted Gutenberg content, save it over `_d2g_divi_backup`, and remove the only Divi rollback copy. This defeats the main safety feature.

**Recommended correction**

1. Reject conversion when the post does not contain a valid, known Divi opening tag.
2. Make the original snapshot immutable. Use `add_post_meta( ..., true )` for the first snapshot.
3. Store a separate current snapshot if repeat conversion is required.
4. Add a per-post conversion lock and make the endpoint idempotent.
5. Disable all actions for a row while any request for that post is active.
6. Return a conflict error when the post changed after preview. Use a content hash or modified timestamp.

### F-03 — High — Common conversion output does not follow the block serialization contract

**Verified facts**

The simple text path strips one outer pair of `<p>` tags and then creates one paragraph block. See [includes/class-d2g-converter.php lines 319-352](includes/class-d2g-converter.php#L319-L352). A local fixture with this source:

```text
[et_pb_text]<p>One</p><p>Two</p>[/et_pb_text]
```

produced this result under PHP 8.1.2:

```html
<!-- wp:paragraph -->
<p>One</p><p>Two</p>
<!-- /wp:paragraph -->
```

One paragraph block now contains two paragraph elements.

The rich-text path adds `has-text-align-center` to heading and paragraph HTML but does not add the matching block attribute. See [includes/class-d2g-converter.php lines 474-493](includes/class-d2g-converter.php#L474-L493). The verified fixture result was:

```html
<!-- wp:heading {"level":2} -->
<h2 class="has-text-align-center">Hello</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p class="has-text-align-center">Body</p>
<!-- /wp:paragraph -->
```

The same mismatch exists in pricing and counter headings. See [includes/class-d2g-converter.php lines 1170-1188](includes/class-d2g-converter.php#L1170-L1188) and [includes/class-d2g-converter.php lines 1225-1239](includes/class-d2g-converter.php#L1225-L1239).

An open Divi toggle adds the HTML `open` attribute but does not add the `core/details` `showContent` attribute. See [includes/class-d2g-converter.php lines 954-966](includes/class-d2g-converter.php#L954-L966). The official Details block definition shows that `showContent` controls this state. See the official [`core/details` reference](https://developer.wordpress.org/block-editor/reference-guides/core-blocks/core-blocks-text/core-block-details/).

WordPress validates a static block by rebuilding its saved markup from parsed attributes and comparing it with the stored markup. A mismatch marks the block as invalid. See the official [Edit and Save validation documentation](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#validation) and [block markup documentation](https://developer.wordpress.org/block-editor/getting-started/fundamentals/markup-representation-block/).

**Inference**

The examples above violate the documented serialization contract. A full editor test is still required to count all invalid blocks and to account for deprecated block versions. No such test was found in the repository.

**Impact**

Common text, aligned text, open toggles, pricing tables, and counters can show the “unexpected or invalid content” warning. This fails the first success criterion.

**Recommended correction**

1. Parse every top-level HTML element in text modules.
2. Emit one block for each paragraph, heading, list, quote, table, and media element.
3. Keep comment attributes and saved HTML in exact agreement.
4. Add a validation test with the WordPress JavaScript block parser and registered core blocks.
5. Use the target WordPress version's block metadata and save functions as the source of truth.
6. Use `core/html` as a safe fallback when exact static serialization is not possible.

### F-04 — High — Pricing tables leave Divi shortcodes in converted content

**Verified facts**

`et_pb_pricing_item` is in the parser tag list. See [includes/class-d2g-parser.php lines 42-46](includes/class-d2g-parser.php#L42-L46). It has no renderer case. The pricing-table renderer uses the node's raw inner content and wraps it in one paragraph. See [includes/class-d2g-converter.php lines 1157-1197](includes/class-d2g-converter.php#L1157-L1197).

`get_inner_content()` only collects text children when raw content is empty. The parser always stores the raw inner content for a paired shortcode. See [includes/class-d2g-parser.php lines 176-187](includes/class-d2g-parser.php#L176-L187) and [includes/class-d2g-converter.php lines 1745-1763](includes/class-d2g-converter.php#L1745-L1763).

A focused fixture produced this block body:

```html
<p>[et_pb_pricing_item]Feature A[/et_pb_pricing_item][et_pb_pricing_item]Feature B[/et_pb_pricing_item]</p>
```

**Impact**

The advertised pricing-table mapping is incomplete. Users can see raw shortcodes after Divi is removed. This conflicts with the module table in [BRIEF.md lines 139-140](BRIEF.md#L139-L140) and the public feature claim in [readme.txt lines 39-48](readme.txt#L39-L48).

**Recommended correction**

Add `convert_pricing_item()`. Convert each item to `core/list-item`, and put those items in a `core/list`. Change `get_inner_content()` so that it never returns child shortcode wrappers when a renderer needs only text.

### F-05 — High — Form, navigation, and portfolio mappings are not portable replacements

**Verified facts**

The contact-form renderer emits `core/form`, `core/form-input`, and `core/form-submit-button`. See [includes/class-d2g-converter.php lines 1352-1431](includes/class-d2g-converter.php#L1352-L1431). The current official Core Blocks Reference marks these blocks as experimental. The same reference says that experimental blocks are available only when the Gutenberg plugin is active. See the official [Core Blocks Reference](https://developer.wordpress.org/block-editor/reference-guides/core-blocks/).

The form also maps select, radio, and checkbox fields to plain text inputs. See [includes/class-d2g-converter.php lines 1363-1404](includes/class-d2g-converter.php#L1363-L1404). It does not emit a submission notification block. It does not verify that the resulting form can send mail.

The navigation renderer puts a Divi classic-menu ID in a `menuId` attribute. See [includes/class-d2g-converter.php lines 1540-1552](includes/class-d2g-converter.php#L1540-L1552). The official `core/navigation` attribute list has `ref`, not `menuId`. See the official [`core/navigation` reference](https://developer.wordpress.org/block-editor/reference-guides/core-blocks/core-blocks-theme/core-block-navigation/).

The portfolio renderer hardcodes the `project` post type and the `project_category` taxonomy. See [includes/class-d2g-converter.php lines 1586-1621](includes/class-d2g-converter.php#L1586-L1621). This plugin does not register either object. The project documents call this type “Divi Projects” and also say that Divi is not required after conversion. See [OPENQUESTIONS.md line 29](OPENQUESTIONS.md#L29) and [readme.txt lines 20-23](readme.txt#L20-L23).

**Inference**

If no other active component registers the Divi Project type after Divi is removed, the converted portfolio query has no portable data source and can return no items.

**Impact**

A normal WordPress install can treat the form blocks as unsupported. A converted contact form can lose field types and submission function. A navigation block can be empty because the attribute does not identify a `wp_navigation` post. A portfolio can remain dependent on a Divi data type.

**Recommended correction**

1. Do not claim that contact forms convert to stable core blocks.
2. Emit a clear manual-work placeholder until WordPress provides a stable form block and a tested submission path.
3. Preserve the original form configuration in a machine-readable warning report.
4. Convert classic menu items to `core/navigation-link` and `core/navigation-submenu` inner blocks, or create a `wp_navigation` post and use its `ref`.
5. Convert portfolio data to a post type that remains available, or make the result static and portable.
6. Add functional tests that render and submit each supported dynamic module without Divi.

### F-06 — High — The stated style mapper is mostly disconnected from conversion

**Verified facts**

The brief says the style mapper handles backgrounds, colors, spacing, width, borders, shadows, font sizes, line heights, custom CSS, and Divi font strings. See [BRIEF.md lines 89-99](BRIEF.md#L89-L99).

The mapper implements these functions in [includes/class-d2g-style-mapper.php lines 15-215](includes/class-d2g-style-mapper.php#L15-L215). The converter calls only `text_align_class()`. It does not call `build_inline_style()`, `wrapper_style()`, `get_color_attrs()`, or `parse_font()`. The only mapper calls are in the text renderer at [includes/class-d2g-converter.php lines 319-337](includes/class-d2g-converter.php#L319-L337) and [includes/class-d2g-converter.php lines 439-493](includes/class-d2g-converter.php#L439-L493).

Sections, rows, and columns also ignore most of their style attributes. See [includes/class-d2g-converter.php lines 230-295](includes/class-d2g-converter.php#L230-L295).

**Impact**

The plugin does not preserve the stated set of design attributes. The result can lose spacing, widths, borders, shadows, fonts, and custom module styling. This is more loss than the documents tell the user to expect.

**Recommended correction**

Create one tested style-normalization layer. Make each module ask that layer for supported block attributes, classes, and safe CSS. Remove dead mapper functions if they are not in scope. Update the documents with an exact matrix of style attributes that the plugin preserves.

### F-07 — High — The WordPress and PHP compatibility claims are not supported

**Verified facts**

The plugin and public readme state WordPress 5.0 and PHP 7.4 as minimum versions. The public readme states “Tested up to: 6.8.” See [block-converter-for-divi.php lines 3-11](block-converter-for-divi.php#L3-L11) and [readme.txt lines 1-7](readme.txt#L1-L7).

The repository itself says that the 6.8 value is a placeholder and that nobody has run a WordPress version test. See [OPENQUESTIONS.md line 24](OPENQUESTIONS.md#L24). WordPress defines “Tested up to” as the version that the plugin was tested against. See the official [Plugin Readmes documentation](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/).

The code emits blocks that did not exist in WordPress 5.0. The documents identify `core/details` and experimental form blocks, but the converter also emits Navigation, Query, Login/out, and other later blocks. See [includes/class-d2g-converter.php lines 1485-1621](includes/class-d2g-converter.php#L1485-L1621) and the admitted mismatch in [OPENQUESTIONS.md line 28](OPENQUESTIONS.md#L28). Official WordPress release notes place Query Loop and Login/out in WordPress 5.8 and Navigation in WordPress 5.9. See [the WordPress 5.8 template-editor note](https://make.wordpress.org/core/2021/06/16/introducing-the-template-editor-in-wordpress-5-8/) and [the WordPress 5.9 Navigation note](https://make.wordpress.org/core/2022/01/07/the-new-navigation-block/).

The rich-HTML path calls `DOMDocument` and `mb_convert_encoding()`. See [includes/class-d2g-converter.php lines 439-455](includes/class-d2g-converter.php#L439-L455). The usage guide requires `dom` but does not require `mbstring`. See [INSTRUCTIONS.md lines 8-15](INSTRUCTIONS.md#L8-L15). A host can meet the documented requirements and still get an undefined-function fatal error. PHP 8.2 also deprecates `HTML-ENTITIES` as an MBString encoding. See the official [PHP 8.2 deprecation notice](https://www.php.net/manual/en/migration82.deprecated.php) and [`mb_convert_encoding()` documentation](https://www.php.net/manual/en/function.mb-convert-encoding.php).

**Impact**

The current metadata can tell a user that an untested or incompatible environment is supported. A rich-text conversion can fail when `mbstring` or `dom` is absent. PHP 8.2 can add deprecation output or log noise to an AJAX request.

**Recommended correction**

1. Change “Tested up to” to the newest version that passes a real test matrix. If no version passes, do not publish.
2. Raise the WordPress minimum to the oldest version that supports every emitted stable block.
3. Feature-detect optional blocks and use a tested fallback.
4. Check `class_exists( DOMDocument::class )` and `function_exists( 'mb_convert_encoding' )` at activation and before conversion.
5. Remove the deprecated conversion. Use a UTF-8-safe DOM loading method.
6. Test PHP 7.4, 8.1, 8.2, 8.3, and 8.4, or reduce the supported range.

### F-08 — High — There is no automated test or validation gate

**Verified facts**

There is no test directory, test manifest, Composer project file, npm project file, CI workflow, or WordPress test setup in this repository. The brief and open questions also state that no automated tests exist. See [BRIEF.md lines 198-201](BRIEF.md#L198-L201) and [OPENQUESTIONS.md line 26](OPENQUESTIONS.md#L26).

WordPress 6.8 test evidence: **Not found in documents.**  
Block-editor validation evidence: **Not found in documents.**  
PHP compatibility-matrix evidence: **Not found in documents.**

**Impact**

The most important output is a large hand-built markup string. Small changes can cause content loss or invalid blocks. The current history already describes several block-validation fixes, but there is no guard against recurrence. See [readme.txt lines 154-168](readme.txt#L154-L168).

**Recommended correction**

Add these test layers:

1. Parser unit tests for nesting, same-tag nesting, self-closing tags, malformed tags, quotes, brackets, Unicode, and unknown modules.
2. One fixture per module: Divi input, expected block tree, expected markup, warnings, and loss classification.
3. WordPress JavaScript block validation with the target core block library.
4. WordPress integration tests for scan, preview, convert, repeat convert, restore, revisions, capability checks, KSES, and multisite.
5. Property tests for “restore equals original bytes” and “successful output has no raw `[et_pb_` token.”
6. A CI matrix for supported WordPress and PHP versions.

### F-09 — Medium — Backup and restore do not restore the complete Divi state

**Verified facts**

The backup stores only `post_content`. Conversion deletes `_et_pb_use_builder` and `_et_pb_old_content`. Restore re-adds only `_et_pb_use_builder`. See [block-converter-for-divi.php lines 291-311](block-converter-for-divi.php#L291-L311) and [block-converter-for-divi.php lines 364-385](block-converter-for-divi.php#L364-L385).

The project documents already identify this gap and the multisite KSES risk. See [BRIEF.md lines 181-190](BRIEF.md#L181-L190) and [OPENQUESTIONS.md lines 30-31](OPENQUESTIONS.md#L30-L31).

**Impact**

A restore is not a full rollback. On multisite, `wp_update_post()` can filter markup for a site administrator who does not have `unfiltered_html`. A restored page can differ from its source even after F-01 is corrected.

**Recommended correction**

Store a versioned snapshot object with `post_content` and the required `_et_pb_*` meta. Restore that object exactly. Require the correct post capability and a safe HTML capability, or make the KSES effect explicit and block the operation. Do not bypass WordPress security with a direct `$wpdb` content write.

### F-10 — Medium — Post actions do not check the target object's capability or type

**Verified facts**

Every AJAX route checks `manage_options`, which is a good administrative gate. See [block-converter-for-divi.php lines 120-124](block-converter-for-divi.php#L120-L124), [block-converter-for-divi.php lines 253-257](block-converter-for-divi.php#L253-L257), and [block-converter-for-divi.php lines 277-281](block-converter-for-divi.php#L277-L281).

Preview, convert, and restore then accept any post ID that `get_post()` can load. They do not require `current_user_can( 'edit_post', $post_id )`. They do not restrict direct requests to `page` and `post`. See [block-converter-for-divi.php lines 259-266](block-converter-for-divi.php#L259-L266), [block-converter-for-divi.php lines 283-289](block-converter-for-divi.php#L283-L289), and [block-converter-for-divi.php lines 357-364](block-converter-for-divi.php#L357-L364).

WordPress defines `edit_post` as an object capability and says to pass the object ID. See the official [`current_user_can()` documentation](https://developer.wordpress.org/reference/functions/current_user_can/).

**Impact**

A custom role can have `manage_options` but lack permission for a specific object. A direct request can also target an attachment, revision, or custom post type that the UI never lists.

**Recommended correction**

After the post loads, require `current_user_can( 'edit_post', $post_id )`. Check the allowed post type and status. Reject revisions, autosaves, attachments, and unsupported custom types.

### F-11 — Medium — The scan does not scale well

**Verified facts**

The scan uses `post_content LIKE '%[et_pb_%'`. The wildcard starts the pattern. It also uses two left joins, one `COUNT(DISTINCT ...)` query, and one grouped result query. See [block-converter-for-divi.php lines 169-221](block-converter-for-divi.php#L169-L221).

The `all` option removes `LIMIT` and `OFFSET`. See [block-converter-for-divi.php lines 128-144](block-converter-for-divi.php#L128-L144) and [block-converter-for-divi.php lines 215-219](block-converter-for-divi.php#L215-L219). The browser then builds one table row at a time. See [admin/js/admin.js lines 177-191](admin/js/admin.js#L177-L191).

**Inference**

A leading wildcard prevents normal use of a `post_content` prefix index. On a large `wp_posts` table, both queries can scan much of the table. The unbounded mode can also use large amounts of PHP and browser memory.

**Recommended correction**

Use a resumable scan job. Walk candidate post IDs with keyset pagination, inspect content in bounded batches, and cache the result in post meta or a job table. Remove the `all` mode, or put a hard upper limit on it. Add a WP-CLI command for large migrations.

### F-12 — Medium — Batch results report failures as successful conversions

**Verified facts**

`convertPage()` rejects its promise when the server reports an error. The batch uses `.always()`, increments `done` for success and failure, and then says that `done / total` pages were converted. See [admin/js/admin.js lines 304-334](admin/js/admin.js#L304-L334) and [admin/js/admin.js lines 453-470](admin/js/admin.js#L453-L470).

The final success message replaces the prior error message. A network failure in `convertPage()` marks the row but does not show a network error. See [admin/js/admin.js lines 329-334](admin/js/admin.js#L329-L334).

**Impact**

An administrator can believe that all selected posts converted when some failed. This is dangerous in a migration because the user can remove Divi too early.

**Recommended correction**

Track `succeeded`, `failed`, and `skipped` separately. Keep a per-post error list. End with an error state if any post failed. Add a downloadable JSON or CSV report.

### F-13 — Medium — Detection and parsing are too broad and fragile for destructive use

**Verified facts**

Detection checks only for the substring `[et_pb_`. See [includes/class-d2g-parser.php lines 290-295](includes/class-d2g-parser.php#L290-L295). The SQL scan uses the same prefix. See [block-converter-for-divi.php lines 169-180](block-converter-for-divi.php#L169-L180).

The parser recognizes only its fixed tag list. It uses regular expressions that stop an opening tag at the first `]`, even when a quoted attribute contains that character. It also scans again to find each matching close tag. See [includes/class-d2g-parser.php lines 109-189](includes/class-d2g-parser.php#L109-L189) and [includes/class-d2g-parser.php lines 196-259](includes/class-d2g-parser.php#L196-L259).

The parser stores the full raw inner content at each nested node. See [includes/class-d2g-parser.php lines 176-187](includes/class-d2g-parser.php#L176-L187).

**Inference**

A post that contains the prefix as an example or in code can enter the conversion flow. Deep nesting can make time and memory use grow quickly because parent and child nodes each keep large overlapping strings.

**Recommended correction**

Use a stack tokenizer with source offsets. Match a complete known tag, not a prefix. Preserve unknown source spans without converting them. Set depth, node-count, content-size, and execution limits. Return a parse error instead of saving a partial tree.

### F-14 — Medium — The admin status path inserts HTML from response data

**Verified facts**

`showStatus()` uses jQuery `.html(msg)`. See [admin/js/admin.js lines 34-39](admin/js/admin.js#L34-L39). Several callers pass `res.data` or `res.data.message`. See [admin/js/admin.js lines 152-157](admin/js/admin.js#L152-L157), [admin/js/admin.js lines 376-392](admin/js/admin.js#L376-L392), and [admin/js/admin.js lines 419-429](admin/js/admin.js#L419-L429).

The restore response puts the post title in its message without HTML escaping because JSON is a data transport, not an HTML escape. See [block-converter-for-divi.php lines 382-385](block-converter-for-divi.php#L382-L385). WordPress requires output to be escaped for its final context. See the official [WordPress escaping documentation](https://developer.wordpress.org/apis/security/escaping/).

**Inference**

This is an HTML-injection sink. A practical stored-XSS path depends on whether attacker-controlled HTML can reach a title or error after WordPress filters run. That exploit path was not proven in this review.

**Recommended correction**

Use `.text(msg)` for all status messages. If a status needs markup, build fixed DOM elements and set all variable text with `textContent`.

### F-15 — Medium — Multisite uninstall behavior does not agree with the usage guide

**Verified facts**

Uninstall visits every site, but it reads the delete option separately on each site. It deletes backups only on sites where that local option is true. See [uninstall.php lines 28-44](uninstall.php#L28-L44) and [uninstall.php lines 47-63](uninstall.php#L47-L63).

The usage guide says that turning the setting on removes backups across every site in the network. See [INSTRUCTIONS.md lines 280-294](INSTRUCTIONS.md#L280-L294). The code does not implement one network-wide setting.

`get_sites( [ 'number' => 0 ] )` also loads every site ID before deletion. See [uninstall.php lines 49-58](uninstall.php#L49-L58).

**Impact**

A network administrator can expect a full purge but leave data on other sites. A large network can also time out during uninstall.

**Recommended correction**

Choose one clear model:

- Use a network option and a network-admin control for a network-wide purge, or
- Keep per-site controls and state clearly that each site must opt in.

Process sites in bounded batches when possible. Log the count of deleted rows per site.

### F-16 — Low — Accessibility, localization, and maintainability need work

**Verified facts**

- The preview modal has no dialog role, accessible name, focus trap, focus return, or Escape-key handler. See [admin/class-d2g-admin.php lines 119-138](admin/class-d2g-admin.php#L119-L138) and [admin/js/admin.js lines 280-289](admin/js/admin.js#L280-L289).
- Sortable table headers work only by pointer click and do not set `aria-sort`. See [admin/class-d2g-admin.php lines 77-87](admin/class-d2g-admin.php#L77-L87) and [admin/js/admin.js lines 223-244](admin/js/admin.js#L223-L244).
- Pagination links have no `href`, and the status element has no live-region attribute. See [admin/js/admin.js lines 74-94](admin/js/admin.js#L74-L94) and [admin/class-d2g-admin.php line 42](admin/class-d2g-admin.php#L42).
- Most JavaScript strings and all AJAX response strings are not localized. See [admin/js/admin.js lines 133-195](admin/js/admin.js#L133-L195) and [block-converter-for-divi.php lines 238-247](block-converter-for-divi.php#L238-L247).
- The converter has unused variables and repeated hand-built block comments. Examples include `$regex` in [includes/class-d2g-parser.php lines 109-116](includes/class-d2g-parser.php#L109-L116), `$featured` in [includes/class-d2g-converter.php lines 1157-1169](includes/class-d2g-converter.php#L1157-L1169), and manual dynamic-block strings in [includes/class-d2g-converter.php lines 1485-1579](includes/class-d2g-converter.php#L1485-L1579).
- The stylesheet contains two pagination sections and selectors for controls that do not exist. See [admin/css/admin.css lines 162-193](admin/css/admin.css#L162-L193) and [admin/css/admin.css lines 333-363](admin/css/admin.css#L333-L363).

**Recommended correction**

Use native buttons for sort and pagination. Add the WordPress dialog pattern, focus management, and an `aria-live` status. Localize all user text. Split the converter into module-specific classes behind a registry. Remove dead code and duplicated CSS.

### F-17 — Medium — Release and distribution documents contain false or stale state

**Verified facts**

The brief says that 2.0.0 is tagged, packaged, and published. See [BRIEF.md lines 1-7](BRIEF.md#L1-L7). The same brief later says that there are no tags, ZIPs, readme, or uninstall handler. See [BRIEF.md lines 179-201](BRIEF.md#L179-L201). The latter statement is stale because those items are present locally.

`OPENQUESTIONS.md` still lists the 2.0.0 version decision as open even though 2.0.0 is the current release. See [OPENQUESTIONS.md line 22](OPENQUESTIONS.md#L22). It also states that the public GitHub links return 404. See [OPENQUESTIONS.md line 23](OPENQUESTIONS.md#L23).

Anonymous HTTP checks on 2026-08-04 returned 404 for the stated [repository](https://github.com/johnjanney/block-converter-for-divi) and [releases page](https://github.com/johnjanney/block-converter-for-divi/releases). A public durable release asset was not found. The local repository does have tags `v1.0.0` through `v2.0.0` and a valid local 2.0.0 ZIP, but local artifacts do not prove publication.

**Impact**

Users cannot use the documented download path. Reviewers cannot verify the published release. Stale risk records make the project state difficult to trust.

**Recommended correction**

Publish the repository and release assets, or change the documents to the actual distribution method. Update the brief after each release. Move resolved questions out of the Open table. Add a release CI job that builds, checks, and attaches the ZIP from a clean tag.

## Positive findings

The repository has several good controls and design choices:

1. Every AJAX route checks the same nonce and an administrative capability. See [block-converter-for-divi.php lines 54-62](block-converter-for-divi.php#L54-L62) and the route implementations.
2. Scan filters and sort values use fixed allowlists. The SQL placeholders use `$wpdb->prepare()`. See [block-converter-for-divi.php lines 97-109](block-converter-for-divi.php#L97-L109) and [block-converter-for-divi.php lines 146-221](block-converter-for-divi.php#L146-L221).
3. The scan does not select the backup body. This avoids transfer of large backup values. See [block-converter-for-divi.php lines 199-206](block-converter-for-divi.php#L199-L206).
4. The normal browser output escapes titles, URLs, dates, and labels before it creates table rows. See [admin/js/admin.js lines 99-130](admin/js/admin.js#L99-L130).
5. Links that the converter opens in a new window add `noopener noreferrer`. See [includes/class-d2g-converter.php lines 519-577](includes/class-d2g-converter.php#L519-L577).
6. Backup deletion is opt-in. The default keeps the recovery data. See [uninstall.php lines 28-44](uninstall.php#L28-L44).
7. The build script checks the three version values and refuses to overwrite an existing archive. See [bin/build-zip.sh lines 20-58](bin/build-zip.sh#L20-L58).
8. The 2.0.0 ZIP has one top-level plugin directory and contains the expected plugin, license, readme, and source files. This agrees with the release policy in [CHANGELOG.md lines 23-52](CHANGELOG.md#L23-L52).
9. The usage guide gives strong warnings to use staging, keep a database backup, and convert one page first. See [INSTRUCTIONS.md lines 19-34](INSTRUCTIONS.md#L19-L34).

## Recommended implementation order

### Phase 0 — Stop data loss

1. Correct slashing in backup, convert, and restore.
2. Make the original snapshot immutable.
3. Add server-side Divi detection, object capability checks, type checks, and a conversion lock.
4. Store a versioned full snapshot of content and required Divi meta.
5. Reject save when conversion reports a parse error, empty output, unsupported critical module, or stale source hash.

### Phase 1 — Make output valid

1. Replace the text heuristics with a top-level HTML-to-block converter.
2. Correct all comment-attribute and saved-HTML mismatches.
3. Convert pricing items to list items.
4. Remove experimental form output from the supported path.
5. Correct navigation conversion.
6. Add a warning result for every lossy or unsupported module.
7. Run the canonical WordPress JavaScript parser and validator against every fixture.

### Phase 2 — Make the product claim accurate

1. Connect the style mapper or reduce the stated style scope.
2. Set a real WordPress minimum.
3. Set “Tested up to” only after a recorded test.
4. State the exact supported block, module, style, post-type, and multisite matrix.
5. Publish the repository and tagged release assets, or correct the download instructions.

### Phase 3 — Make large migrations reliable

1. Add a resumable job model with bounded batches and per-post results.
2. Add WP-CLI support.
3. Replace the unbounded scan with keyset pagination and a cached inventory.
4. Add retry, cancel, resume, and exportable reports.
5. Keep conversion history with source hash, output hash, converter version, warnings, time, and user ID.

## Better target architecture

The current parser-to-converter flow is a good starting boundary. The safer form is:

```text
Inventory job
  -> source snapshot and hash
  -> stack tokenizer with source spans
  -> normalized Divi node tree
  -> module registry
  -> block builders for one WordPress compatibility profile
  -> result: markup + warnings + unsupported modules + statistics
  -> block validation gate
  -> atomic-style save sequence
  -> audit record and restore test
```

Each module builder must return structured data, not only a string. A result must include at least:

- `markup`
- `warnings`
- `unsupported_modules`
- `lossy_modules`
- `source_hash`
- `output_hash`
- `converter_version`
- `required_wordpress_version`

This design lets preview and save use the same result. It also lets the UI block a dangerous conversion before it writes data.

## Verification record

### Checks that passed

- `php -l` passed for all six PHP files under PHP 8.1.2.
- `node --check admin/js/admin.js` passed under Node 24.14.0.
- `bash -n bin/build-zip.sh` passed.
- The local Git worktree was clean before this review file was added.
- Local tags exist for `v1.0.0`, `v1.1.0`, `v1.2.0`, and `v2.0.0`.
- The local `dist/block-converter-for-divi-2.0.0.zip` is readable and has one top-level directory.
- Focused PHP fixtures verified the two-paragraph, aligned-rich-text, and pricing-item outputs shown in this report.

### Checks that could not be completed

- Automated repository tests: **Not found in documents or code.**
- A live WordPress 6.8 block-editor validation run: **Not found in documents; no test environment is supplied.**
- An end-to-end Divi fixture set: **Not found in documents or code.**
- A public GitHub release download: the documented URLs returned 404 during this review.
- A real multisite conversion and uninstall test: **Not found in documents or code.**

### Review limitations

This was a static review plus focused local execution. It was not a live-site migration. Exact visual fidelity, editor deprecation handling, database query time, KSES behavior, and front-end rendering must still be measured in the supported WordPress test matrix.

## Source list

Primary repository sources:

- [block-converter-for-divi.php](block-converter-for-divi.php)
- [includes/class-d2g-parser.php](includes/class-d2g-parser.php)
- [includes/class-d2g-converter.php](includes/class-d2g-converter.php)
- [includes/class-d2g-style-mapper.php](includes/class-d2g-style-mapper.php)
- [admin/class-d2g-admin.php](admin/class-d2g-admin.php)
- [admin/js/admin.js](admin/js/admin.js)
- [admin/css/admin.css](admin/css/admin.css)
- [uninstall.php](uninstall.php)
- [BRIEF.md](BRIEF.md)
- [INSTRUCTIONS.md](INSTRUCTIONS.md)
- [OPENQUESTIONS.md](OPENQUESTIONS.md)
- [CHANGELOG.md](CHANGELOG.md)
- [readme.txt](readme.txt)
- [bin/build-zip.sh](bin/build-zip.sh)

External primary sources:

- [WordPress Block Editor: Edit and Save](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/)
- [WordPress Block Markup Representation](https://developer.wordpress.org/block-editor/getting-started/fundamentals/markup-representation-block/)
- [WordPress Core Blocks Reference](https://developer.wordpress.org/block-editor/reference-guides/core-blocks/)
- [WordPress 5.8 Template Editor and Theme Blocks](https://make.wordpress.org/core/2021/06/16/introducing-the-template-editor-in-wordpress-5-8/)
- [WordPress 5.9 Navigation Block](https://make.wordpress.org/core/2022/01/07/the-new-navigation-block/)
- [WordPress `current_user_can()`](https://developer.wordpress.org/reference/functions/current_user_can/)
- [WordPress Nonces](https://developer.wordpress.org/apis/security/nonces/)
- [WordPress Output Escaping](https://developer.wordpress.org/apis/security/escaping/)
- [WordPress `wp_update_post()`](https://developer.wordpress.org/reference/functions/wp_update_post/)
- [WordPress `update_post_meta()`](https://developer.wordpress.org/reference/functions/update_post_meta/)
- [WordPress `wp_slash()`](https://developer.wordpress.org/reference/functions/wp_slash/)
- [WordPress Plugin Readmes](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [PHP 8.2 Deprecated Features](https://www.php.net/manual/en/migration82.deprecated.php)
- [PHP `mb_convert_encoding()`](https://www.php.net/manual/en/function.mb-convert-encoding.php)
