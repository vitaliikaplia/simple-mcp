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

**You act as a specific WordPress user.** Your key is personal: authentication sets that user
as the current WordPress user (the `initialize` instructions tell you who). Tool groups the role
isn't granted are absent from `tools/list`; typed operations that touch WordPress objects
re-check object-level rights
(an author edits only their own posts, option writes need `manage_options`, publishing needs
`publish_posts`). A capability-denied error is the system working — don't retry or route around
it; tell the user which permission is missing. Without edit rights you can read only published,
password-less items of public post types. `wp_cli` is the deliberate exception: it runs as
a separate privileged process without the HTTP user's object-level capability context, so it is
hard-limited to roles with `manage_options` (super admins on multisite) and must be treated as god-mode.

- ✅ **Content:** find/edit posts and page blocks, fill ACF values, upload media, manage CPT items,
  list/create/update terms and assign them, edit theme options, create/link translations, roll back
  revisions and flush caches. Deleting terms and plain options require `wp_cli`.
- ❌ **Never edit theme/plugin CODE files** (PHP/JS/CSS) here. Site theme code ships through
  that site's `git → CI/CD`; Simple MCP itself ships from its own GitHub repository/updater.
  Server-side source edits drift from those sources and get overwritten.
- ⚙️ **Server ops** — editing **wp-config directives** and **installing / updating / removing
  whole plugins or themes** — are legitimate, *environment-specific* changes (config and the
  plugin set differ between local and prod by design; site themes and Simple MCP have separate
  versioned sources). They are
  **off by default**, gated by the **"Server ops"** toggle. When enabled you may do them via
  `wp_cli`, but **always confirm DESTRUCTIVE ops** with the user first — deleting ACF or another
  critical plugin, or changing security/DB config. When disabled, the plugin/theme/core/language/
  config/maintenance-mode/scaffold/i18n/server families allow only read-only subcommands (list, get,
  status, is-installed, path, search, version, verify-checksums, …); WP-CLI synonyms (`upgrade`,
  `uninstall`, `sql`, …) are normalized first. `@alias`, `cli` (except version/info), `package` and the
  `--path/--exec/--require/--ssh/--http/--prompt` flags are always refused.

---

## 1. Golden rules

1. **Never hand-assemble Gutenberg block-delimiter JSON.** Use the `block_*` tools. Manually
   editing `post_content` for ACF blocks corrupts the `\uXXXX` escapes and the
   `_name → field_key` mirror (this has broken pages before). The `block_*` tools serialize
   server-side and byte-verify.
2. **Direct typed `post_content` edits are verified.** `update_post`, `block_*`, `create_post`
   and `revision_restore` return `content_verified` (exact bytes, or the same parsed block tree —
   ACF re-encodes attributes on save). If it is not `true`, stop and investigate (`filtered_blocks`
   / `kses_note` tell you what save filters changed). Every body write keeps a rollback point (WP
   revision, or a `_simple_mcp_backup` ring of 5 when revisions are off) — see `revision_list` /
   `revision_restore`. ACF/media/translation writes use their own result fields.
   **Stale writes:** pass the `etag` (`get_post`) / `content_etag` (`block_get`) you read as
   `if_match`; a write is refused if the body changed since. Writes to one post are serialized.
3. **Discover before you edit an unfamiliar site.** If available, call `describe_site` once to
   learn the blocks, fields, options pages, post types, taxonomies and languages of *this* fork;
   it is cached for one hour, so pass `refresh:true` after schema/theme changes. Call
   `list_block_fields <block>` for a block's exact field schema before `block_update`.
4. **Multilingual = separate entities.** Each language is a **different post/term ID** linked
   by a `trid`. Resolve the right-language ID with `wploc_get_translations` **before** editing.
   Editing "the page" edits only one language.
5. **Flush cache after content edits** if a full-page cache (e.g. W3 Total Cache) is active,
   or the front-end will look unchanged: `cache_flush {post_id}` (needs edit rights on the post;
   purges W3TC, WP Super Cache, LiteSpeed, WP Rocket, … for that post) or `cache_flush {}` for the
   whole site (`manage_options`).
6. **Prefer typed tools over `wp_cli`** for content. `wp_cli` is the god-mode backstop; the
   typed tools are safer and self-verifying.
7. **wp-loc attribute sync.** Saving ANY translation pushes its status, author, password,
   menu_order, parent, template and terms to all siblings (tools return `sync_note`). Unpublishing or
   trashing one language with `update_post` does the same to the others — use `safe_delete`.
8. **Arguments are validated** against each tool's `inputSchema`: unknown keys, wrong types and
   missing required params return an `isError` result listing the allowed keys — read it and retry.

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
- **Seamless clones.** Fields that reach a block through a seamless clone (a shared header or
  buttons group) are stored under the field's **own** key, never the in-memory `<clone>_<field>` key
  ACF uses while editing — that one does not resolve, and ACF silently drops the value. Since 2.4.0
  the `block_*` tools write the right key, refuse a write whose new reference would not resolve, and
  `block_update` repairs such references left in the edited block by older versions (returned as
  `refs_repaired`). If a field shows in `block_get` but not on the page, check the block's
  `broken_refs` in `block_get`: run `block_update` on that block — re-setting a field's current
  value is enough — and it repairs prefixed references and lists them in `refs_repaired`.
- **Nested blocks.** `block_get` describes inner blocks recursively (`children`) and gives every
  block a `path` (`"2"`, `"2.0"`, `"2.0.1"`), so ACF blocks inside `core/group`/`core/columns` are
  editable with `locator:{path}`; `block_insert`/`block_move` take `parent_path`.
- **Groups** are stored as an empty parent value plus its reference (`"video_files":""`,
  `"_video_files":"field_…"`) and their sub-fields as `video_files_mp4` etc.; before 2.4.0
  `block_update` dropped that parent value and the whole group vanished from the page.

### 2.2 The other ACF storage modes (don't conflate them)
| Where | Storage | Read / Write |
|---|---|---|
| **Block fields** | inline in `post_content` | `block_get` / `block_update` |
| **Post/CPT fields** | post meta | `acf_get` / `acf_update` (post_id = int) |
| **Options pages** (header/footer/theme settings) | `wp_options` | `acf_update` post_id `"option"` (+ `lang` per language; exact ids in `describe_site`) |
| **User / term / comment fields** | corresponding meta | `acf_update` post_id `"user_5"` / `"term_10"` / `"comment_5"` |

### 2.3 Two options systems (a classic gotcha)
Theme settings land in `wp_options` from **two** systems with similar-looking keys:
- **ACF options pages** → write with `acf_update` (post_id `"option"`).
- **Plain Settings-API dashboard options** (the theme's own `register_setting` layer) → write
  with `wp_cli` `option update <key> <value>`.

`describe_site` lists the **ACF** option pages/fields precisely. Any `wp_option` **not** listed
there is a plain option — use `wp_cli` when that tool is available. Typed-only users must ask an
administrator to perform the plain-option change or grant an appropriate tool; when unsure,
read first with `wp_cli` `option get <key>`.

### 2.4 Multilingual (wp-loc / WPML)
- Model: **one post/term per language**, linked by a shared `trid` in `{prefix}icl_translations`.
- Language codes: the **URL slug** (`ua`) can differ from the **wpml_code** (`uk`). Every tool
  accepts the slug, the code, a locale or case variant (`ua`, `uk`, `uk_UA`, `UK`) and resolves it
  strictly: unknown languages are an error with the valid list, disabled ones need
  `allow_inactive:true`. Outputs use wpml codes. `describe_site.languages` gives the map.
- `element_type` = `post_{type}` for posts, `tax_{taxonomy}` for terms. Terms are addressed by
  **term_id**: pass `kind:"term"` (+ `taxonomy`) for a term. Without `kind`/`element_type`/`taxonomy`
  an existing post with that ID wins (post IDs and term_ids are separate sequences); when a term of a
  translatable taxonomy shares the ID the result carries a `note`.
- **Per-language ACF options on wp-loc:** the default language lives in `options`, other languages
  in `options_{slug}` (e.g. `options_en`) — NOT `options_uk`. Use `acf_update {post_id:"option",
  lang:"en", …}` or the exact `languages[].options_post_id` from `describe_site`.

---

## 3. Recipes

### Edit one ACF field in a block (the #1 operation)
```
1. block_get {post_id, nested:true}         → block path / anchor, current values, content_etag
2. list_block_fields {block_name}           → confirm field name & type (once per block type)
3. block_update {post_id, locator:{path:"N"}, set:{field: newValue}, if_match:<content_etag>}
                                             → check content_verified:true
                                               (keys_changed / keys_removed show the effect;
                                                refs_repaired lists references it fixed)
4. cache_flush {post_id}                     (if a page cache is active)
```
`block_update` **merges**: a group value `{sub:val}` changes only that sub-field, a repeater list
merges row by row (rows beyond the list are removed), `null` deletes a value, flat paths such as
`tiles_0_title` address one sub-field of an existing row and `tiles_2: null` removes row 2.
**Row indexes in one `set` always refer to the block before the call**: `{tiles_2:null, tiles_3:null}`
removes the old rows 2 and 3 (removals are applied last), and a key inside a removed row or inside a
list another key replaces (`{tiles:[…], tiles_1_title:…}`) is refused as a conflict. `replace:true`
replaces the whole field.
`dry_run:true` validates and shows the diff without saving. Several edits on one post at once:
`block_batch {post_id, ops:[…]}` (one save, all-or-nothing).
Value shapes: scalars as-is; image/file = attachment ID (`''` clears); link = `{title,url,target}`;
gallery = `[ids]`; repeater = `[{sub:val}, …]`; group = `{sub:val}`;
flexible = `[{acf_fc_layout:name, sub:val}, …]`; true_false = bool; date_picker = `Ymd` or `Y-m-d`.
Every value is validated against its field type first; all errors come back with their paths.

### Find content
`find_posts {post_type, status, search, lang, parent, author, ids}` → ids, titles, statuses, languages
(only items you can read). Terms: `term_list {taxonomy, search, lang}`.

### Edit the full page body / post fields / reorder blocks
- Full body: `update_post {id, content, if_match}` (you supply complete block markup) — rarely needed.
- Post fields: `update_post {id, title|slug|excerpt|date|parent|menu_order|thumbnail|meta|status}` —
  one save; `status:"trash"` trashes an untranslated post (a post with translations is refused — use
  `safe_delete`; an attachment only while `MEDIA_TRASH` is on), a non-trash status on a trashed post restores it. A post in the trash is refused by
  every other write until it is restored. Block tools refuse post types whose body is not block markup.
- Terms of a post: `set_post_terms {post_id, taxonomy, terms:[ids or slugs], append}`.
- Structure: `block_insert` / `block_move` / `block_remove`; build a page with `block_replace`
  from `[{blockName, data:{…}}]` specs (server serializes — no raw markup).

### Create a page and fill it
```
create_post {post_type, title, status:"draft", lang}   → new id (registered in that language)
block_insert {post_id:id, block:{blockName, data:{…}}, position:"end"}   (repeat)
                                                    → field and block defaults are applied
render_post {post_id:id}                            → sanity-check the rendered HTML (as on the front end)
```

### Upload media (always through the theme pipeline)
- Small: `upload_media {source:"base64", filename, data}` → returns `attachment_id`, `url`,
  `webp_url` (the theme resized + generated webp).
- Large (video/hi-res): `upload_begin` → `upload_chunk {upload_id, data, index}` × N →
  `upload_finish {upload_id, size, sha256}`. Base64 may be cut at any character; `index` makes a retry
  of the last chunk a no-op. The session belongs to you, expires 1 h after the last chunk, the file is
  limited to 1 GB (3 active uploads / 2 GB pending per user). A failed finish keeps the session.
- Then reference the returned `attachment_id` in a block/field via `block_update`/`acf_update`.

### Theme options
- ACF option (from `describe_site.acf_options`): `acf_update {post_id:"option", field, value}`;
  per-language: add `lang:"en"` (or use `acf_options[].post_ids` from `describe_site`). The result's
  `post_id` shows where it was stored.
- `acf_update` of a repeater/flexible/group/clone checks the value against the field's sub-fields
  first (an unknown sub-field, a row that is not an object or an unknown `acf_fc_layout` fails the
  call — ACF would silently drop it and cut the repeater). Read the exact shape with
  `acf_get {format:false}`. On a translated post/term, `translations_changed` lists the translations
  the multilingual plugin also wrote (fields shared or copy-once across translations).
- Plain option (when `wp_cli` is available): `wp_cli "option update <key> '<value>'"`.

### Multilingual
```
wploc_get_translations {element_id}                → {trid, translations:{uk:{id,status,title,…}, en:{…}}}
# edit the correct language's ID with block_update/acf_update/update_post
wploc_create_translation {source_id, lang:"en"}    → duplicates + links a new EN post (same status,
                                                     author, password as the source; parent, terms,
                                                     featured image and ACF links mapped); then
                                                     translate its fields on the new id
wploc_link_translation {source_id, target_id, lang}→ link two existing posts as translations
                                                     (refuses occupied slots / other groups unless
                                                      replace:true / move:true)
```
An unregistered source needs `source_language`. On wp-loc with attribute sync (the default) a
published source gives a published, still untranslated copy — it is live immediately.

### Delete safely
`safe_delete {post_id}` — moves the post to the trash (any post type). It refuses if translations
exist (lists them); `allow_cascade:true` first **detaches** the post from its translation group, so
only it is affected (wp-loc would otherwise delete the whole group when the trash is emptied). A
detached post keeps its old group in `_simple_mcp_detached`; relink it with `wploc_link_translation`
after restoring it. `force:true` deletes permanently; attachments need `force` while media trash is
off. The result reports the real outcome and which siblings still exist.

### Roll back
`revision_list {post_id}` → WP revisions + Simple MCP backups with their etags;
`revision_restore {post_id, revision_id | backup_id, if_match}`.

---

## 4. Gotchas that have actually broken things

- **`wp_slash` / `\uXXXX`.** Writing `post_content` through the WordPress PHP API without
  `wp_slash` strips the backslashes
  from `\uXXXX`, turning `<` into literal `u003c` on the front-end and corrupting the
  block. All Simple MCP typed content tools handle this. Do not send a raw block body through
  `wp_cli`; prefer the typed tools.
- **In ACF block JSON, Cyrillic is literal but HTML `< > "` are `\uXXXX`-escaped.** So a naive
  string search for a heading may not match the raw bytes. `block_get`/`block_update` decode
  this for you.
- **Cache.** After an edit the front-end may be stale under W3TC/object cache — `cache_flush` (see
  rule 5). `block_get`/DB reflect the truth immediately; the rendered page may not.
- **One translation changes the others** (wp-loc sync, rule 7): status, author, password, parent,
  template and terms are shared across the group; ACF fields in "shared"/"copy once" mode too
  (`acf_update` reports them in `translations_changed`).
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
| `describe_site` | Learn this fork (1h cache; `refresh:true` rebuilds) |
| `find_posts` | Find posts by type/status/text/language/parent/author |
| `block_get` / `list_block_fields` | Read a page's block tree (paths, etag) / a block's field schema |
| `block_update` | Edit ACF field(s) of one block (merge, validated, verified) |
| `block_insert` / `block_move` / `block_remove` / `block_replace` | Structure the page (nested via path/parent_path) |
| `block_batch` | Several block ops on one post in one save |
| `get_post` / `update_post` | Read (etag) / write body and post fields (block-safe, one save) |
| `create_post` | Create page/post/CPT with block-safe body and language |
| `render_post` | Rendered HTML (as on the front end, or `mode:"blocks"`) to verify an edit |
| `acf_get` / `acf_update` | POST/user/term/comment/**options** ACF fields (NOT block fields) |
| `set_post_terms` / `term_list` / `term_create` / `term_update` | Terms |
| `revision_list` / `revision_restore` | Rollback points of a post |
| `cache_flush` | Purge page caches for a post or the whole site |
| `upload_media` / `upload_begin`·`upload_chunk`·`upload_finish` | Media through resize+webp |
| `wploc_get_translations` / `wploc_link_translation` / `wploc_create_translation` | Translations (posts and terms) |
| `safe_delete` | Translation-aware delete (trash by default) |
| `wp_cli` | Privileged backstop; server ops only when separately enabled, never edit source files |
