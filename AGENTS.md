# AGENTS.md — Simple MCP

Agent-facing notes. **Read [docs/MCP-GUIDE.md](docs/MCP-GUIDE.md) before editing content** — it
has the full model, recipes and gotchas. Summary below.

## What this plugin is
A private MCP server for controlling this WordPress site's **content** (blocks, ACF values,
media, options, taxonomies, translations). Custom non-REST endpoint. Ships an auto-updater
from GitHub (`vitaliikaplia/simple-mcp`).

**Auth model:** per-user Bearer keys (`smcp-{user_id}-…`, generated on the user's profile
screen; SHA-256 in user meta). Authentication sets the key owner via `wp_set_current_user`,
so two layers apply to **typed tools**: (1) the **roles matrix** decides which groups appear in
`tools/list`, and (2) operations touching WP objects re-check **native WP capabilities**
(`edit_post` on the target, `publish_posts`, `upload_files`, `manage_options`, `delete_post`,
…). Capability-denied results are expected behavior, not bugs. `wp_cli` is a separate privileged
subprocess that does not inherit object-level user caps, so wp_cli/server-ops are hard-limited
to roles with `manage_options`.

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
4. Direct typed `post_content` writes are byte-verified (`content_verified`); block updates
   preserve a revision or fallback backup. ACF/media/translation writes have their own result
   fields. Flush cache (W3TC) after edits.
5. Two options systems: ACF options (`acf_update` post_id `"option"`) vs plain Settings-API
   (`wp_cli option update`). `describe_site` lists the ACF ones.

## Code layout
- `simple-mcp.php` — bootstrap, constants, module autoload.
- `includes/class-simple-mcp.php` — core: options, roles matrix (`role_perms`/`user_perms`),
  version migrations, shell/SSRF helpers.
- `includes/class-endpoint.php` — MCP transport (do_parse_request, JSON-RPC, per-user
  `initialize` `instructions`).
- `includes/class-auth.php` — per-user key auth (`smcp-{uid}-…` → user meta hash), perms of
  the current request, rate-limit.
- `includes/class-user-keys.php` — profile-screen key UI (generate/revoke, one-time plaintext).
- `includes/class-audit.php` / `class-admin.php` — audit log (with user), settings + roles matrix.
- `includes/class-tools.php` — core tools + capability helpers (`can_read_post`/`can_edit_post`/
  `acf_cap_check`) + shared `save_post_content` (auto-revision + wp_slash + verify) + per-user
  registry that merges tool modules.
- `includes/tools/class-simple-mcp-tools-{blocks,wploc,content,describe}.php` — tool modules
  (each exposes `defs()`; write paths cap-checked).
- `includes/class-simple-mcp-github-updater.php` — GitHub auto-update.
- `uninstall.php` — removes options, audit table, per-user key meta, plugin transients and
  chunk-upload temp files on full uninstall.

## Release/documentation sync

Current release: **2.4.0**. Canonical author URL: **https://kaplia.pro/**. For a release, keep
the `Version:` header and `SIMPLE_MCP_VERSION` in `simple-mcp.php` identical; also update this
“Current release” line, README's «Поточна версія», the README changelog and the updater's
WordPress-visible changelog (note: WordPress renders the INSTALLED copy's changelog, so the new text
shows only after the update is installed). The updater reads the header from GitHub `master`, while
runtime migrations and MCP `serverInfo` use the constant.

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
parent value `get_fields()` skips the whole group.

## Adding a tool
Create/extend a module in `includes/tools/`, add an entry to its `defs()` (name → description,
inputSchema, callback), return via `Simple_MCP_Tools::ok()/err()`, and route writes through
`Simple_MCP_Tools::save_post_content()`. Modules are auto-loaded and merged into the registry.
**Every callback that touches a WP object must enforce native caps**: use
`Simple_MCP_Tools::can_read_post()/can_edit_post()/can_publish_type()/acf_cap_check()` and
return `Simple_MCP_Tools::err_cap()` on denial — the registry gate only controls visibility,
not object-level rights.
