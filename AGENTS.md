# AGENTS.md — Simple MCP

Agent-facing notes. **Read [docs/MCP-GUIDE.md](docs/MCP-GUIDE.md) before editing content** — it
has the full model, recipes and gotchas. Summary below.

## What this plugin is
A private MCP server for controlling this WordPress site's **content** (blocks, ACF values,
media, options, taxonomies, translations). Custom non-REST endpoint, Bearer-key auth. Ships an
auto-updater from GitHub (`vitaliikaplia/simple-mcp`).

## Scope boundary
**Content, not code.** Never install/activate/edit plugins, themes or files through this MCP —
code ships via local dev + git + CI/CD, and server-side code edits drift and get overwritten.

## The rules that keep pages intact
1. Page content = **ACF blocks inline in `post_content`**. Never hand-write block JSON — use
   `block_get` / `list_block_fields` / `block_update`. `acf_update` does **not** reach block fields.
2. **Multilingual**: each language is a separate post/term ID linked by `trid`. Use
   `wploc_get_translations` to edit the right one; `wploc_create_translation` to add one.
3. On an unfamiliar site, call **`describe_site`** first (blocks/fields/options/CPTs/languages
   differ per fork).
4. Writes auto-revision + byte-verify (`content_verified`). Flush cache (W3TC) after edits.
5. Two options systems: ACF options (`acf_update` post_id `"option"`) vs plain Settings-API
   (`wp_cli option update`). `describe_site` lists the ACF ones.

## Code layout
- `simple-mcp.php` — bootstrap, constants, module autoload.
- `includes/class-endpoint.php` — MCP transport (do_parse_request, JSON-RPC, `initialize`
  `instructions`).
- `includes/class-auth.php` / `class-audit.php` / `class-admin.php` — auth, audit log, settings.
- `includes/class-tools.php` — core tools + shared `save_post_content` (auto-revision + wp_slash
  + verify) + registry that merges tool modules.
- `includes/tools/class-simple-mcp-tools-{blocks,wploc,content,describe}.php` — tool modules
  (each exposes `defs()`).
- `includes/class-simple-mcp-github-updater.php` — GitHub auto-update.

## Adding a tool
Create/extend a module in `includes/tools/`, add an entry to its `defs()` (name → description,
inputSchema, callback), return via `Simple_MCP_Tools::ok()/err()`, and route writes through
`Simple_MCP_Tools::save_post_content()`. Modules are auto-loaded and merged into the registry.
