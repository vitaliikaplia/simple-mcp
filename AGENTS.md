# AGENTS.md — Simple MCP

Agent-facing notes. **Read [docs/MCP-GUIDE.md](docs/MCP-GUIDE.md) before editing content** — it
has the full model, recipes and gotchas. Summary below.

## What this plugin is
A private MCP server for controlling this WordPress site's **content** (blocks, ACF values,
media, options, taxonomies, translations). Custom non-REST endpoint. Ships an auto-updater
from GitHub (`vitaliikaplia/simple-mcp`).

**Auth model:** per-user Bearer keys (`smcp-{user_id}-…`, up to 10 named keys per user generated
on the profile screen; SHA-256 in user meta `simple_mcp_keys`, the pre-2.5 single
`simple_mcp_key_hash` still accepted as a legacy key). Authentication sets the key owner via `wp_set_current_user`,
so two layers apply to **typed tools**: (1) the **roles matrix** decides which groups appear in
`tools/list`, and (2) operations touching WP objects re-check **native WP capabilities**
(`edit_post` on the target, `publish_posts`, `upload_files`, `manage_options`, `delete_post`,
…). Capability-denied results are expected behavior, not bugs. `wp_cli` is a separate privileged
subprocess that does not inherit object-level user caps, so wp_cli/server-ops are hard-limited
to roles with `manage_options` (super admins on multisite). Reading without edit rights is limited to
published, password-less posts of viewable post types (`can_read_post`).

## Scope boundary
**Content first.** Never edit theme/plugin **code files** here: each site's theme ships through
its git/CI/CD, while Simple MCP ships from its own GitHub repository/updater. But **server ops** —
wp-config directives and installing/updating/removing whole plugins or themes — are legitimate
environment-specific changes, gated behind the **"Server ops"** toggle (off by default).
Confirm destructive ones with the user.

## The rules that keep pages intact
1. Page content = **ACF blocks inline in `post_content`**. Never hand-write block JSON — use
   `block_get` / `list_block_fields` / `block_update`. `acf_update` does **not** reach block fields.
2. **Multilingual**: each language is a separate post/term ID linked by `trid`. Use
   `wploc_get_translations` to edit the right one; `wploc_create_translation` to add one.
3. On an unfamiliar site, call **`describe_site`** first when available
   (blocks/fields/options/CPTs/languages differ per fork); use `refresh:true` after schema changes.
4. Direct typed `post_content` writes are verified (`content_verified`: exact bytes, or the same
   parsed block tree — ACF re-encodes attributes on save); every body write keeps a revision or a
   `_simple_mcp_backup` (ring of 5) and runs under a per-post lock. Pass the `etag`/`content_etag`
   you read as `if_match` to refuse stale writes. `block_update` MERGES by default
   (`replace:true` = full replace); use `dry_run:true` to preview. Flush caches with `cache_flush`.
5. **wp-loc attribute sync**: saving ANY translation pushes status, author, password, menu_order,
   parent, template and terms to all its siblings (tools return `sync_note`). Never unpublish/trash
   one language via `update_post` expecting the others to stay; delete with `safe_delete`.
6. Two options systems: ACF options (`acf_update` post_id `"option"`, per language `lang` or the
   exact `options_post_id` from `describe_site` — on wp-loc `options` for the default language and
   `options_{slug}` for others) vs plain Settings-API (`wp_cli option update`).

## Code layout
- `simple-mcp.php` — bootstrap, constants, module autoload.
- `includes/class-simple-mcp.php` — core: options, roles matrix (`role_perms`/`user_perms`),
  version migrations, cron, wp/php binary resolution, shell runner, SSRF helper.
- `includes/class-simple-mcp-cli.php` — the `wp_cli` tool: tokenizer, alias normalization,
  deny-list + server-ops allow-list gate, isolated WP-CLI execution.
- `includes/class-endpoint.php` — MCP transport (do_parse_request, JSON-RPC, protocol
  negotiation, per-user `initialize` `instructions`); `process()` is testable without exiting.
- `includes/class-auth.php` — per-user key auth (`smcp-{uid}-…` → hashes in user meta), trusted
  proxies, IP allowlist (IPv4/IPv6), lockout, atomic rate-limit counters, perms of the request.
- `includes/class-user-keys.php` — profile-screen key UI (named keys, generate/revoke, one-time plaintext).
- `includes/class-audit.php` / `class-admin.php` — audit log (user, key, duration, detail; redacted),
  settings + roles matrix.
- `includes/class-tools.php` — core tools + shared contracts (`can_read_post`, `writable_post`,
  `validate_status`, `meta_write_check`, `with_post_lock`, `content_etag`/`precondition`,
  `content_matches`, backups, `save_post_content`, `validate_args`, `ann`) + per-user registry that
  merges tool modules and validates arguments against `inputSchema`.
- `includes/tools/class-simple-mcp-tools-{posts,blocks,wploc,content,describe}.php` — tool modules
  (each exposes `defs()`; write paths cap-checked). `posts` is in the core `mcp` group.
- `includes/class-simple-mcp-github-updater.php` — auto-update from GitHub Releases (SHA-256 checked).
- `uninstall.php` — removes everything the plugin stored (all sites on multisite).
- Dev-only (export-ignored, never shipped): `tests/` (`php tests/run.php [filter]`, dependency-free
  with WP stubs; `tests/integration/` holds read-only WordPress checks run with `wp eval-file`, not part
  of CI), `bin/` (`check-version.php`, `lint.php`, `release-notes.php`, `check-package.php`,
  `release-lib.php`), `.github/workflows/` (`ci.yml`: lint on PHP 8.1–8.5 + tests + version check;
  `release.yml`: tag → verify on PHP 8.1/8.5 + package (zip, unpacked-zip check, notes) → publish).

## Release/documentation sync

Current release: **2.5.0**. Canonical author URL: **https://kaplia.pro/**. For a release: keep
the `Version:` header and `SIMPLE_MCP_VERSION` in `simple-mcp.php` identical; update this
“Current release” line, README's «Поточна версія» and add the new top README changelog entry
`### X.Y.Z — <title>` under `## Зміни` (it becomes the GitHub release notes and the WordPress
“View details” changelog). Run `php bin/check-version.php vX.Y.Z`, `php tests/run.php` and
`php bin/lint.php`, push `master`, then push the tag `vX.Y.Z` — `.github/workflows/release.yml`
builds `simple-mcp.zip` + `simple-mcp.zip.sha256` and publishes the release. Sites on 2.5.0+ update
only from release assets (checksum verified); runtime migrations and MCP `serverInfo` use the constant.
**Transition:** sites on 2.4.0 or older still run the old branch updater — it installs the `master` zip,
unverified, as soon as master's `Version:` is higher, so pushing `master` IS the release for them.
Commit every new file (`git add -A`; nothing required may stay untracked — `simple-mcp.php` requires
`includes/class-simple-mcp-cli.php` and loads `includes/tools/*.php`), push to a branch first and wait
for green CI, then fast-forward `master` and push the tag right away.

Tool descriptions and the MCP `instructions` must stay under 2048 characters (Claude Code truncates
longer ones): put data-loss warnings first; `tests/integration/descriptions.php` checks the worst case.

## ACF field keys in block data
`acf_get_fields()` expands a **seamless clone** into the cloned fields and gives each one a
temporary in-memory key `<clone key>_<field key>`; the real key sits in `__key` (ACF restores it via
`acf/prepare_field` when it renders the field input, so the editor posts and saves the real key). Block data must store the real key — the temporary one does not
resolve through `acf_get_field()`, so ACF silently drops the value on the front end. The blocks module
therefore writes `field_ref($f)` (`__key` when present), `list_block_fields` reports that same key,
`assert_refs_resolve()` refuses a write whose new references do not resolve, and `block_update`
repairs `<clone>_<field>` references left in the edited block by versions before 2.4.0
(`refs_repaired` in the result). Groups and display-"group" clones are stored the way ACF stores them:
an empty parent value plus its reference (`"video_files":""`, `"_video_files":"field_…"`); without the
parent value `get_fields()` skips the whole group. At the TOP level of block data a seamless clone also
gets its parent entry (`"hdr":""` + `"_hdr":<clone key>`), as ACF stores it — `get_fields()` needs it to
return the clone as a group. Inside repeaters/groups ACF itself stores the temporary `<clone>_<field>`
key for seamless-clone sub-fields; we store the real key and both load identically. Flexible content
also stores `_{name}_layout_meta` = `{disabled:[rows], renamed:{row:label}}` (disabled rows are hidden
on the front end); block writes keep it consistent. Values are validated per field type and stored in
ACF's editor format (true_false `'1'/'0'`, date_picker `Ymd`, ids as strings in lists, …).

## Adding a tool
Create/extend a module in `includes/tools/`, add an entry to its `defs()` (name → `title`,
`description`, `inputSchema` with `additionalProperties:false`, `annotations` via
`Simple_MCP_Tools::ann()`, `callback`), return via `Simple_MCP_Tools::ok()/err()`. Modules are
auto-loaded and merged into the registry (map the class to a permission group in
`Simple_MCP_Tools::registry()`); arguments are validated against `inputSchema` before the callback.
Body writes: `writable_post()` (refuses revisions and trashed posts unless `$allow_trash`) →
`with_post_lock()` → re-read → `precondition()` (if_match) → `save_post_content()`; return the new
`content_etag`. Wrap any other `wp_update_post` / `wp_insert_post` / `wp_trash_post` /
`wp_untrash_post` / `wp_restore_post_revision` / `wp_set_object_terms` of a post in
`Simple_MCP_Tools::with_raw_terms()` — outside wp-admin wp-loc swaps term IDs to the current
language, which would rewrite a translation's terms (no-op without a multilingual plugin). **Every callback that touches a WP object must
enforce native caps**: use `Simple_MCP_Tools::can_read_post()/writable_post()/can_publish_type()/
meta_write_check()/acf_cap_check()` and return `Simple_MCP_Tools::err_cap()` on denial — the
registry gate only controls visibility, not object-level rights. Add tests to `tests/` for pure logic.
