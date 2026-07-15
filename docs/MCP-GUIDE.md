# Simple MCP — Agent Guide

How to control these WordPress sites **safely** through the Simple MCP server. Read this
before editing content. It encodes hard rules and the failure modes that have actually
broken pages in the past, so you don't repeat them.

These sites share one architecture: **Timber/Twig + ACF PRO + custom Gutenberg ACF blocks**,
usually **multilingual** (wp-loc, WPML-compatible). Every project is a fork of the same base
theme, so the conventions below hold across all of them — but **field names and keys differ
per fork**, so discover them at runtime (see `describe_site` / `list_block_fields`).

---

## 0. Scope — content first; server ops on request

Primary job: **content, options, media, taxonomies, translations**.

- ✅ **Content:** edit page blocks, fill ACF values, upload media, manage terms & CPT items,
  edit theme options, create/link translations.
- ❌ **Never edit theme/plugin CODE files** (PHP/JS/CSS) here — that's `git → CI/CD`. Only the
  **theme** is versioned; editing theme/plugin source on the server drifts and gets overwritten.
- ⚙️ **Server ops** — editing **wp-config directives** and **installing / updating / removing
  whole plugins or themes** — are legitimate, *environment-specific* changes (config and the
  plugin set differ between local and prod by design; only the theme is in git). They are
  **off by default**, gated by the **"Server ops"** toggle. When enabled you may do them via
  `wp_cli`, but **always confirm DESTRUCTIVE ops** with the user first — deleting ACF or another
  critical plugin, or changing security/DB config. When disabled, those commands are blocked.

---

## 1. Golden rules

1. **Never hand-assemble Gutenberg block-delimiter JSON.** Use the `block_*` tools. Manually
   editing `post_content` for ACF blocks corrupts the `\uXXXX` escapes and the
   `_name → field_key` mirror (this has broken pages before). The `block_*` tools serialize
   server-side and byte-verify.
2. **Every write is verified.** `update_post`/`block_*`/`create_post` return
   `content_verified: true` and auto-save a **revision** first (built-in rollback). If
   `content_verified` is not `true`, stop and investigate.
3. **Discover before you edit an unfamiliar site.** Call `describe_site` once to learn the
   blocks, fields, options pages, post types, taxonomies and languages of *this* fork.
   Call `list_block_fields <block>` for a block's exact field schema before `block_update`.
4. **Multilingual = separate entities.** Each language is a **different post/term ID** linked
   by a `trid`. Resolve the right-language ID with `wploc_get_translations` **before** editing.
   Editing "the page" edits only one language.
5. **Flush cache after content edits** if a full-page cache (e.g. W3 Total Cache) is active,
   or the front-end will look unchanged: `wp_cli` → `cache flush` and the page-cache flush
   (`w3-total-cache flush all` when W3TC is present).
6. **Prefer typed tools over `wp_cli`** for content. `wp_cli` is the god-mode backstop; the
   typed tools are safer and self-verifying.

---

## 2. The content model

### 2.1 Where page content lives — inline ACF block data
Almost all page/CPT body content is composed of **ACF blocks** (`acf/main-*`). Their field
values are stored **inline in `post_content`**, inside the block-delimiter JSON, in ACF's
**flattened** format:

```
<!-- wp:acf/main-first-screen {"name":"acf/main-first-screen","data":{
   "text":"<h1>…</h1>",     ← value (HTML is \uXXXX-escaped, Cyrillic is literal)
   "_text":"field_68f41759b2b82",               ← the _name => field_key mirror (REQUIRED)
   "buttons":2,                                  ← repeater count
   "buttons_0_button":{"title":…,"url":…},       ← repeater row 0, sub-field "button"
   "_buttons_0_button":"field_68f417a4b2b84",
   …
},"mode":"preview"} /-->
```

- **This is NOT post meta.** `acf_get`/`acf_update` cannot see it. Use `block_get` to read and
  `block_update` to write — they resolve the `field_key` mirror from the ACF registry for you.
- Each block also carries a shared **"block settings" group** (padding/margin/bg/anchor). Its
  `field_key`s **differ per fork** — that's fine, `block_update`/`list_block_fields` resolve
  them at runtime.

### 2.2 The other ACF storage modes (don't conflate them)
| Where | Storage | Read / Write |
|---|---|---|
| **Block fields** | inline in `post_content` | `block_get` / `block_update` |
| **Post/CPT fields** | post meta | `acf_get` / `acf_update` (post_id = int) |
| **Options pages** (header/footer/theme settings) | `wp_options` | `acf_update` post_id `"option"` (or `"options_{wpml_code}"` per language) |
| **User / term fields** | user/term meta | `acf_update` post_id `"user_5"` / `"term_10"` |

### 2.3 Two options systems (a classic gotcha)
Theme settings land in `wp_options` from **two** systems with similar-looking keys:
- **ACF options pages** → write with `acf_update` (post_id `"option"`).
- **Plain Settings-API dashboard options** (the theme's own `register_setting` layer) → write
  with `wp_cli` `option update <key> <value>`.

`describe_site` lists the **ACF** option pages/fields precisely. Any `wp_option` **not** listed
there is a plain option — use `wp_cli`. When unsure, `wp_cli` `option get <key>` first.

### 2.4 Multilingual (wp-loc / WPML)
- Model: **one post/term per language**, linked by a shared `trid` in `{prefix}icl_translations`.
- Language codes: the **URL slug** (`ua`) can differ from the **wpml_code** (`uk`). The
  `wploc_*` tools accept either and normalize. `describe_site.languages` gives the map.
- `element_type` = `post_{type}` for posts, `tax_{taxonomy}` for terms (where element_id is the
  **term_taxonomy_id**, not term_id).

---

## 3. Recipes

### Edit one ACF field in a block (the #1 operation)
```
1. block_get {post_id}                      → find the block index / anchor and current values
2. list_block_fields {block_name}           → confirm field name & type (once per block type)
3. block_update {post_id, locator:{index:N}, set:{field: newValue}}
                                             → check content_verified:true
4. (if cached) wp_cli "cache flush" (+ W3TC page flush)
```
Value shapes for `set`: scalars as-is; image/file = attachment ID; link = `{title,url,target}`;
gallery = `[ids]`; repeater = `[{sub:val}, …]`; group = `{sub:val}`;
flexible = `[{acf_fc_layout:name, sub:val}, …]`.

### Edit the full page body / reorder blocks
- Full body: `update_post {id, content}` (you supply complete block markup) — rarely needed.
- Structure: `block_insert` / `block_move` / `block_remove`; build a page with `block_replace`
  from `[{blockName, data:{…}}]` specs (server serializes — no raw markup).

### Create a page and fill it
```
create_post {post_type, title, status:"draft"}     → new id
block_insert {post_id:id, block:{blockName, data:{…}}, position:"end"}   (repeat)
render_post {post_id:id}                            → sanity-check the rendered HTML
```

### Upload media (always through the theme pipeline)
- Small: `upload_media {source:"base64", filename, data}` → returns `attachment_id`, `url`,
  `webp_url` (the theme resized + generated webp).
- Large (video/hi-res): `upload_begin` → `upload_chunk` × N → `upload_finish`.
- Then reference the returned `attachment_id` in a block/field via `block_update`/`acf_update`.

### Theme options
- ACF option (from `describe_site.acf_options`): `acf_update {post_id:"option", field, value}`;
  per-language: `post_id:"options_uk"`.
- Plain option: `wp_cli "option update <key> '<value>'"`.

### Multilingual
```
wploc_get_translations {element_id}                → {trid, translations:{uk:ID, en:ID, …}}
# edit the correct language's ID with block_update/acf_update/update_post
wploc_create_translation {source_id, lang:"en"}    → duplicates + links a new EN post; then
                                                     translate its fields on the new id
wploc_link_translation {source_id, target_id, lang}→ link two existing posts as translations
```

### Delete safely
`safe_delete {post_id}` — refuses if translations exist (lists them); pass `allow_cascade:true`
to delete **only** that post (siblings are never cascaded). Prefer trashing (omit `force`).

### WooCommerce product data (optional `wp-loc-woocommerce` addon)
```
wc_synced_meta_keys {}              → which product meta is mirrored between languages
# edit prices/stock/SKU/attributes on the DEFAULT-LANGUAGE product…
wc_sync_product {product_id}        → push to all translations (pull from source when
                                      called on a translation; accepts a variation ID)
```
- Never edit a synced meta key on a translation — the next sync overwrites it with the
  source value. Translated TEXT (title, description, ACF texts) is never touched.
- The sync MUTATES translations (synced meta overwritten, orphan mirror variations removed).

---

## 4. Gotchas that have actually broken things

- **`wp_slash` / `\uXXXX`.** Writing `post_content` without `wp_slash` strips the backslashes
  from `\uXXXX`, turning `<` into literal `u003c` on the front-end and corrupting the
  block. All Simple MCP write tools handle this; if you ever fall back to raw `wp post update`,
  you must `wp_slash`. Prefer the typed tools.
- **In ACF block JSON, Cyrillic is literal but HTML `< > "` are `\uXXXX`-escaped.** So a naive
  string search for a heading may not match the raw bytes. `block_get`/`block_update` decode
  this for you.
- **Cache.** After an edit the front-end may be stale under W3TC/object cache — flush (see rule
  5). `block_get`/DB reflect the truth immediately; the rendered page may not.
- **Per-fork field keys.** The shared block-settings group has different `field_key`s in each
  theme. Never hard-code keys — resolve via `list_block_fields`/`describe_site`.
- **Hosting (when calling the endpoint yourself for testing).** Some WAFs (Imunify/ModSecurity)
  block scripting User-Agents like `python-urllib` with a 403 — send a browser-like UA. On
  shared hosting, `wp_cli` needs the correct `php`/`wp` binary paths configured and
  `proc_open` enabled; `open_basedir` can block auto-detection of out-of-basedir binaries, so
  set the php/wp paths explicitly in the plugin settings.
- **Wrong-language edit.** Because each language is a separate ID, always resolve with
  `wploc_get_translations` first, or you'll edit the wrong post.

---

## 5. Tool quick reference

| Tool | Use |
|---|---|
| `describe_site` | Learn this fork: blocks, fields, options, CPTs, taxonomies, languages |
| `block_get` / `list_block_fields` | Read a page's blocks / a block's field schema |
| `block_update` | Edit ACF field(s) of one block (safe, verified) |
| `block_insert` / `block_move` / `block_remove` / `block_replace` | Structure the page |
| `get_post` / `update_post` | Read / full-body write (block-safe) |
| `create_post` | Create page/post/CPT with block-safe body |
| `render_post` | Rendered `do_blocks` HTML to verify an edit |
| `acf_get` / `acf_update` | POST/user/term/**options** ACF fields (NOT block fields) |
| `upload_media` / `upload_begin`·`upload_chunk`·`upload_finish` | Media through resize+webp |
| `wploc_get_translations` / `wploc_link_translation` / `wploc_create_translation` | Translations |
| `safe_delete` | Translation-aware delete |
| `wc_sync_product` / `wc_synced_meta_keys` | Sync product data across languages / list mirrored meta (optional addon) |
| `wp_cli` | God-mode backstop (content only — never code; see §0) |
