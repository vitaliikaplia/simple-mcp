<?php
/**
 * Реєстр MCP-інструментів.
 *
 * Філософія: wp_cli — універсальний шлюз (усе, що вміє WP-CLI). Решта — безпечні
 * обгортки саме для того, що через CLI роблять погано або небезпечно:
 *   - завантаження бінарних медіа (через media_handle_sideload → тема ресайзить + webp);
 *   - запис Gutenberg-контенту без побиття блокового JSON (wp_slash + round-trip verify);
 *   - ACF-поля через рідний API (репітери/flex).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools {

    /**
     * Full registry for the CURRENT authenticated user = core tools + tool-module defs
     * the user's role permissions allow. Groups a role can't use are hidden entirely
     * (absent from tools/list, uncallable). Outside an authenticated MCP request the
     * permission set is all-false, so the registry is empty.
     */
    static function registry() {
        if (!Simple_MCP_Auth::perm('mcp')) return [];
        $reg = self::core_defs();
        if (!Simple_MCP_Auth::perm('wp_cli')) unset($reg['wp_cli']); // typed-only for this user

        $modules = [
            'Simple_MCP_Tools_Posts'    => 'mcp',
            'Simple_MCP_Tools_Blocks'   => 'blocks',
            'Simple_MCP_Tools_Wploc'    => 'wploc',
            'Simple_MCP_Tools_Content'  => 'content',
            'Simple_MCP_Tools_Describe' => 'content',
        ];
        foreach ($modules as $cls => $group) {
            if (!Simple_MCP_Auth::perm($group)) continue;
            if ($group === 'wploc' && !Simple_MCP::multilingual_system()) continue; // no wp-loc/WPML → hide
            if (class_exists($cls) && method_exists($cls, 'defs')) {
                $reg = array_merge($reg, (array) $cls::defs());
            }
        }
        return $reg;
    }

    /** MCP-анотації інструмента (підказки клієнту для авто-підтвердження/попереджень, не гарантії). */
    static function ann($read_only, $destructive, $idempotent, $open_world = false) {
        return [
            'readOnlyHint'    => (bool) $read_only,
            'destructiveHint' => (bool) $destructive,
            'idempotentHint'  => (bool) $idempotent,
            'openWorldHint'   => (bool) $open_world,
        ];
    }

    /** Core tools shipped in this file: name → [title, description, inputSchema, annotations, callback] */
    static function core_defs() {
        $acf_target = 'ACF target: an integer post ID, or "option" (options pages; per language add lang or use "options_{lang}"), "user_{id}", "term_{id}", "comment_{id}".';
        // Живе попередження мультимовного модуля (wp-loc sync атрибутів між перекладами) — до опису update_post
        $sync = (class_exists('Simple_MCP_Tools_Wploc') && Simple_MCP::multilingual_system()
            && method_exists('Simple_MCP_Tools_Wploc', 'sync_note')) ? Simple_MCP_Tools_Wploc::sync_note() : null;
        return [
            'wp_cli' => Simple_MCP_CLI::def(),

            'get_post' => [
                'title'       => 'Read post',
                'description' => 'Read one post/page/CPT item by ID: title, status, type, slug, author (user ID), date and modified (site-local time), excerpt, parent, menu_order, thumbnail (attachment ID or null), etag and the raw post_content (Gutenberg markup; block JSON is \\uXXXX-escaped). Published items of a public post type without a password are readable by every key; drafts, pending and password-protected items and items of non-public post types (logs, bookings…) need edit rights on the item; private items need edit rights or native read_private rights. Pass etag as if_match to update_post (or another write tool that accepts if_match) to refuse the write if the body changed in between. To read ACF values inside blocks use block_get — it decodes the inline block data.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => ['id' => ['type' => 'integer', 'description' => 'Post ID.']],
                    'required'             => ['id'],
                ],
                'annotations' => self::ann(true, false, true),
                'callback'    => [__CLASS__, 'tool_get_post'],
            ],

            'update_post' => [
                'title'       => 'Update post',
                // wp-loc sync першим (Claude Code обрізає описи довші за 2048 символів — застереження не має загубитись)
                'description' => ($sync ? $sync . ' Results then carry sync_note. ' : '')
                    . 'Update an existing post/page/CPT item in one locked save; every field except id is optional. content = the FULL Gutenberg markup (replaces the whole body; a revision, or a meta backup when the type keeps none, is stored first; the saved body is verified → content_verified) — to change ONE ACF field inside a block use block_update. title is stored as given (kses still applies without unfiltered_html). slug, excerpt, date (site-local "Y-m-d H:i:s"; a future date on a published item schedules it), parent (0 = none; same hierarchical type, no loops), menu_order, thumbnail (image attachment ID; 0 removes it), meta (key → value, null deletes; native add/edit/delete_post_meta caps; protected "_" keys only when registered with an auth_callback). '
                    . 'status: a registered status or "trash"; "future" needs a date at least a minute ahead; moving into publish/private/future needs publish rights. "trash" goes through wp_trash_post and needs delete rights; it is refused when the site trash is disabled, for attachments while MEDIA_TRASH is off, and for an item with linked translations (the multilingual plugin would trash or later delete the whole group; use safe_delete with allow_cascade:true). An item in the trash cannot be edited until restored: pass a non-trash status (delete rights) and the other fields in the same call. '
                    . 'if_match = etag from get_post/block_get: the update is refused if the body changed since. Returns id, status, slug, content_verified (null when content was not sent), etag, thumbnail/meta results when sent' . ($sync ? ' and sync_note' : '') . '.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'id'         => ['type' => 'integer', 'description' => 'Post ID.'],
                        'content'    => ['type' => 'string', 'description' => 'Full post_content (Gutenberg markup) — replaces the whole body. To edit a single block field use block_update.'],
                        'title'      => ['type' => 'string'],
                        'status'     => ['type' => 'string', 'description' => 'publish | draft | pending | private | future | trash (or another registered status).'],
                        'slug'       => ['type' => 'string', 'description' => 'post_name; sanitized with sanitize_title, made unique by WordPress.'],
                        'excerpt'    => ['type' => 'string'],
                        'date'       => ['type' => 'string', 'description' => 'Publish date, site-local "Y-m-d H:i:s".'],
                        'parent'     => ['type' => 'integer', 'description' => 'Parent post ID of the same hierarchical type; 0 = none.'],
                        'menu_order' => ['type' => 'integer'],
                        'thumbnail'  => ['type' => 'integer', 'description' => 'Featured image attachment ID; 0 removes it.'],
                        'meta'       => ['type' => 'object', 'description' => 'Post meta key → value; null deletes the key. For ACF post fields prefer acf_update.'],
                        'if_match'   => ['type' => 'string', 'description' => 'etag from get_post/block_get; refuse the update if the body changed since.'],
                    ],
                    'required'             => ['id'],
                ],
                'annotations' => self::ann(false, true, true),
                'callback'    => [__CLASS__, 'tool_update_post'],
            ],

            'acf_get' => [
                'title'       => 'Read ACF fields',
                'description' => 'Read ACF field values stored as meta of a post, user, term or comment, or on ACF options pages. field = field name or field_key: a bare name resolves only among the field groups whose location rules match the target, or through the field reference already stored for that name; anything else is an error (never a silent null). Omit field to get all fields located on the target (options: only from options pages you may open) plus fields that already have stored values (options: only for manage_options). format true (default) returns ACF-formatted values, false the raw stored values. User/post/term/comment objects inside values are reduced to safe summaries (no passwords, activation keys or e-mails). Rights: a post as for get_post; options need the capability of an options page that shows the field; terms of non-public taxonomies need edit_term; users need edit_user unless it is yourself; comments need edit_comment. Does NOT read ACF fields embedded in Gutenberg blocks — use block_get.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'post_id' => ['type' => ['integer', 'string'], 'description' => $acf_target],
                        'field'   => ['type' => 'string', 'description' => 'Field name or field_key. Optional — omit to read all fields.'],
                        'format'  => ['type' => 'boolean', 'description' => 'true (default) = ACF-formatted values; false = raw stored values.'],
                        'lang'    => ['type' => 'string', 'description' => 'Options only, multilingual sites: language slug or code (e.g. "en"); selects the per-language options storage.'],
                    ],
                    'required'             => ['post_id'],
                ],
                'annotations' => self::ann(true, false, true),
                'callback'    => [__CLASS__, 'tool_acf_get'],
            ],

            'acf_update' => [
                'title'       => 'Write ACF field',
                'description' => 'Write one ACF field value through ACF\'s own value API (update_field semantics — correct for repeater/group/flexible values) on a post, user, term, comment or options page. field = field name or field_key: a bare name resolves only among the field groups whose location rules match the target, or through the reference already stored for that name; otherwise the call fails and nothing is written. value null deletes the value; backslashes are preserved. Repeater/flexible/group/clone values are checked against the field\'s sub-fields first: an unknown sub-field, a row that is not an object or an unknown acf_fc_layout fails the call with the path and nothing is written. Options: post_id "option" (+ lang) or "options_{lang}" — on wp-loc the selector is normalized to the real storage (the default language uses "options"). Returns post_id (the storage selector used), field_key, name, updated (the value was written, or was already identical), changed (the stored value differs from before), value (formatted read-back) and translations_changed {lang: id} when the multilingual plugin also wrote the value to translations (shared/copy-once fields, containers included). If the read-back shows the value was not stored, or a delete left the stored value in place (e.g. the multilingual plugin ignores writes to a field shared across languages or translations), the call fails instead of reporting success. Rights: edit rights on the post/term/user/comment (a post in the trash must be restored first); options need manage_options. CANNOT edit ACF fields inside Gutenberg blocks — use block_update. Fills VALUES only; field definitions live in theme code.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'post_id' => ['type' => ['integer', 'string'], 'description' => $acf_target],
                        'field'   => ['type' => 'string', 'description' => 'Field name or field_key.'],
                        'value'   => ['description' => 'New value (string/number/boolean/array/object depending on the field type); null deletes it.'],
                        'lang'    => ['type' => 'string', 'description' => 'Options only, multilingual sites: language slug or code (e.g. "en").'],
                    ],
                    'required'             => ['post_id', 'field', 'value'],
                ],
                'annotations' => self::ann(false, true, true),
                'callback'    => [__CLASS__, 'tool_acf_update'],
            ],

            'upload_media' => [
                'title'       => 'Upload media',
                'description' => 'Upload one file to the media library via media_handle_sideload, so the theme pipeline runs (resize to max width + .webp generation). source "base64" (default; data = the whole file, base64) or "url" (public http/https URL; private/reserved hosts are refused; the download is capped at 1 GB and 60 s). For large files use upload_begin / upload_chunk / upload_finish. Optional title, alt, and post_id to attach it to a post you can edit. Needs upload_files. Returns attachment_id, url, webp_url.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'source'   => ['type' => 'string', 'enum' => ['base64', 'url'], 'description' => 'default base64'],
                        'filename' => ['type' => 'string', 'description' => 'File name with extension, e.g. photo.jpg'],
                        'data'     => ['type' => 'string', 'description' => 'Base64 content (source=base64).'],
                        'url'      => ['type' => 'string', 'description' => 'File URL (source=url).'],
                        'title'    => ['type' => 'string'],
                        'alt'      => ['type' => 'string'],
                        'post_id'  => ['type' => 'integer', 'description' => 'Attach to this post (optional; needs edit rights on it).'],
                    ],
                    'required'             => ['filename'],
                ],
                'annotations' => self::ann(false, false, false, true),
                'callback'    => [__CLASS__, 'tool_upload_media'],
            ],

            'upload_begin' => [
                'title'       => 'Begin chunked upload',
                'description' => 'Start a chunked upload of a large file (video, hi-res photos). Needs upload_files; the extension must be an upload type WordPress allows for you. Returns upload_id, bound to you (other users cannot use it). A session expires one hour after its last chunk; files of abandoned sessions are deleted after two hours (by the next upload_begin or the daily cleanup). Limits: 1 GB per file; per user at most 3 active uploads and 2 GB not yet finished. Then send upload_chunk calls and finish with upload_finish.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => ['filename' => ['type' => 'string', 'description' => 'File name with extension, e.g. video.mp4']],
                    'required'             => ['filename'],
                ],
                'annotations' => self::ann(false, false, false),
                'callback'    => [__CLASS__, 'tool_upload_begin'],
            ],

            'upload_chunk' => [
                'title'       => 'Upload chunk',
                'description' => 'Append the next part of the file to your upload (upload_id from upload_begin) as base64. Chunks may be cut at ANY character of one continuous base64 string (leftover characters are carried over to the next chunk), or each chunk may be separately encoded base64 with its own padding. Pass index (0, 1, 2, …) to make retries safe: re-sending the last accepted index with identical data is a no-op (duplicate:true); different data for an accepted index or a skipped index is an error and changes nothing. Returns bytes (decoded bytes stored), next_index and pending_chars (base64 characters carried over).',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'upload_id' => ['type' => 'string'],
                        'data'      => ['type' => 'string', 'description' => 'Base64 part.'],
                        'index'     => ['type' => 'integer', 'description' => '0-based chunk number (optional; makes retries idempotent).'],
                    ],
                    'required'             => ['upload_id', 'data'],
                ],
                'annotations' => self::ann(false, false, false),
                'callback'    => [__CLASS__, 'tool_upload_chunk'],
            ],

            'upload_finish' => [
                'title'       => 'Finish chunked upload',
                'description' => 'Finish your chunked upload: decode the carried-over base64 characters, optionally verify size (total decoded bytes) and sha256 (hex) of the assembled file, then sideload it into the media library (theme resize + webp pipeline). If a check or the sideload fails the session is kept, so you can send more chunks or fix the problem and call upload_finish again. Optional title, alt, post_id (attach to a post you can edit). Returns attachment_id, url, webp_url, bytes.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'upload_id' => ['type' => 'string'],
                        'size'      => ['type' => 'integer', 'description' => 'Expected total file size in bytes (optional check).'],
                        'sha256'    => ['type' => 'string', 'description' => 'Expected SHA-256 of the file, hex (optional check).'],
                        'title'     => ['type' => 'string'],
                        'alt'       => ['type' => 'string'],
                        'post_id'   => ['type' => 'integer', 'description' => 'Attach to this post (optional; needs edit rights on it).'],
                    ],
                    'required'             => ['upload_id'],
                ],
                'annotations' => self::ann(false, false, false),
                'callback'    => [__CLASS__, 'tool_upload_finish'],
            ],
        ];
    }

    /**
     * Список для tools/list (без callback). Def може мати 'title' та 'annotations'
     * (readOnlyHint / destructiveHint / idempotentHint / openWorldHint) — передаються як є.
     */
    static function list_public() {
        $out = [];
        foreach (self::registry() as $name => $def) {
            $t = ['name' => $name];
            if (!empty($def['title'])) $t['title'] = (string) $def['title'];
            $t['description'] = $def['description'];
            $t['inputSchema'] = $def['inputSchema'];
            if (!empty($def['annotations']) && is_array($def['annotations'])) {
                $ann = $def['annotations'];
                if (!empty($def['title']) && !isset($ann['title'])) $ann['title'] = (string) $def['title'];
                $t['annotations'] = $ann;
            }
            $out[] = $t;
        }
        return $out;
    }

    /** Виклик інструмента (tools/call): валідація аргументів за inputSchema → callback → аудит */
    static function call($name, $args) {
        $reg   = self::registry();
        $start = microtime(true);
        if (!isset($reg[$name])) {
            Simple_MCP_Audit::log((string) $name, $args, 'unknown', ['detail' => 'tool not in this user\'s registry']);
            return new WP_Error('unknown_tool', 'Невідомий інструмент: ' . $name);
        }
        $orig   = $args;
        $args   = is_array($args) ? $args : [];
        $schema = isset($reg[$name]['inputSchema']) && is_array($reg[$name]['inputSchema']) ? $reg[$name]['inputSchema'] : [];
        $errors = self::validate_args($schema, $args);
        if ($errors) {
            // Звичайний isError-результат (а не JSON-RPC помилка): агент бачить, що саме виправити.
            $res = self::err('Некоректні аргументи для ' . $name . ': ' . implode('; ', $errors) . '.');
            Simple_MCP_Audit::log($name, $orig, 'error', [
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'detail'      => 'invalid arguments',
            ]);
            return $res;
        }
        try {
            $res = call_user_func($reg[$name]['callback'], $args);
        } catch (\Throwable $e) {
            $res = self::err($e->getMessage());
        }
        Simple_MCP_Audit::log($name, $orig, empty($res['isError']) ? 'ok' : 'error', [
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
        return $res;
    }

    // ── Валідація аргументів (підмножина JSON Schema) ─────────────────────

    /**
     * Перевіряє $args за inputSchema: required, type (включно з union-типами), enum, вкладені
     * properties/required, additionalProperties:false (зі списком невідомих і дозволених ключів),
     * items масивів. Числові рядки та цілі float для integer/number приводяться до чисел; null у
     * НЕобов'язковому типізованому параметрі вважається «не передано». $args змінюється на місці.
     * Повертає список помилок ([] — усе гаразд).
     */
    static function validate_args($schema, &$args) {
        $errors = [];
        if (!is_array($args)) $args = [];
        self::check_object((array) $schema, $args, '', $errors);
        return $errors;
    }

    private static function check_object($schema, &$obj, $path, &$errors) {
        if ($obj !== [] && array_is_list($obj)) {
            $errors[] = ($path === '' ? 'arguments' : '"' . rtrim($path, '.') . '"') . ' має бути об\'єктом, а не масивом';
            return;
        }
        $props    = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        foreach (array_keys($obj) as $k) {
            if ($obj[$k] !== null || in_array($k, $required, true) || !isset($props[$k]['type'])) continue;
            if (!in_array('null', (array) $props[$k]['type'], true)) unset($obj[$k]); // null = не передано
        }
        foreach ($required as $r) {
            if (!array_key_exists($r, $obj)) $errors[] = 'бракує обов\'язкового параметра "' . $path . $r . '"';
        }
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
            $unknown = array_diff(array_map('strval', array_keys($obj)), array_map('strval', array_keys($props)));
            if ($unknown) {
                $errors[] = 'невідом' . (count($unknown) > 1 ? 'і параметри ' : 'ий параметр ')
                    . implode(', ', array_map(function ($k) use ($path) { return '"' . $path . $k . '"'; }, $unknown))
                    . ' (дозволені: ' . ($props ? implode(', ', array_keys($props)) : '—') . ')';
            }
        }
        foreach ($props as $k => $ps) {
            if (!array_key_exists($k, $obj) || !is_array($ps)) continue;
            self::check_value($ps, $obj[$k], $path . $k, $errors);
        }
    }

    private static function check_value($schema, &$v, $path, &$errors) {
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $t) {
                if (self::type_matches($t, $v)) { $ok = true; break; }
            }
            if (!$ok && in_array('integer', $types, true)) {
                if (is_string($v) && preg_match('/^\s*[-+]?\d{1,18}\s*$/', $v)) { $v = (int) trim($v); $ok = true; }
                elseif (is_float($v) && is_finite($v) && floor($v) == $v && abs($v) < 9.0e15) { $v = (int) $v; $ok = true; }
            }
            if (!$ok && in_array('number', $types, true) && is_string($v) && is_numeric(trim($v))) {
                $v = trim($v) + 0;
                $ok = true;
            }
            if (!$ok) {
                $errors[] = '"' . $path . '" має бути ' . implode(' або ', $types) . ', отримано ' . self::json_type($v);
                return;
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($v, $schema['enum'], true)) {
            $errors[] = '"' . $path . '" має бути одним із: ' . implode(', ', array_map('wp_json_encode', $schema['enum']));
            return;
        }
        if (!is_array($v)) return;
        $is_list = $v === [] || array_is_list($v);
        $obj_schema = isset($schema['properties']) || isset($schema['required'])
            || (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false);
        if ($obj_schema && ($v === [] || !$is_list)) {
            self::check_object($schema, $v, $path . '.', $errors);
        }
        if (isset($schema['items']) && is_array($schema['items']) && $is_list) {
            foreach ($v as $i => &$item) {
                self::check_value($schema['items'], $item, $path . '[' . $i . ']', $errors);
            }
            unset($item);
        }
    }

    private static function type_matches($t, $v) {
        switch ($t) {
            case 'string':  return is_string($v);
            case 'integer': return is_int($v);
            case 'number':  return is_int($v) || is_float($v);
            case 'boolean': return is_bool($v);
            case 'null':    return $v === null;
            case 'array':   return is_array($v) && ($v === [] || array_is_list($v));
            case 'object':  return (is_array($v) && ($v === [] || !array_is_list($v))) || is_object($v);
        }
        return true; // невідомий тип у схемі — не блокуємо
    }

    private static function json_type($v) {
        if ($v === null) return 'null';
        if (is_bool($v)) return 'boolean';
        if (is_int($v)) return 'integer';
        if (is_float($v)) return 'number';
        if (is_string($v)) return 'string';
        if (is_array($v)) return ($v === [] || array_is_list($v)) ? 'array' : 'object';
        return 'object';
    }

    // ── Формат відповіді MCP ──────────────────────────────────────────────

    /**
     * Успішний результат: компактний JSON-текст (для сумісності; без pretty-print — менше токенів)
     * + structuredContent (MCP 2025-06-18), коли дані — асоціативний масив/об'єкт і відповідь не гігантська.
     */
    static function ok($data) {
        $text = is_string($data)
            ? $data
            : wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($text)) $text = '{"error":"result is not JSON-encodable"}';
        $res = ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
        if (strlen($text) <= 200000 && ((is_array($data) && $data !== [] && !array_is_list($data)) || is_object($data))) {
            $res['structuredContent'] = $data;
        }
        return $res;
    }
    static function err($msg) {
        return ['content' => [['type' => 'text', 'text' => (string) $msg]], 'isError' => true];
    }

    // ── Нативні capability-перевірки (дзеркало прав WordPress) ────────────

    /** Уніфікована відмова: агенту одразу видно, що це нативні права WP, а не збій. */
    static function err_cap($action) {
        $u = wp_get_current_user();
        return self::err('Відмовлено: у користувача "' . ($u ? $u->user_login : '?')
            . '" немає WordPress-права на цю дію (' . $action . '). '
            . 'MCP дзеркалить нативні ролі WordPress — попроси адміністратора розширити роль, якщо це потрібно.');
    }

    /**
     * Чи можна ЧИТАТИ пост (дзеркало REST API / wp-admin):
     *  - опублікований пост ПУБЛІЧНОГО типу (is_post_type_viewable) без пароля — будь-кому;
     *  - усе інше (чернетки, приватні, з паролем, непублічні CPT на кшталт логів пошти чи
     *    бронювань) — лише з edit_post; приватний без пароля — ще й з нативним read_post.
     * Вкладення успадковують правило батьківського поста (осиротіле — публічний медіа-асет).
     */
    static function can_read_post($post, $depth = 0) {
        $post = get_post($post);
        if (!$post) return false;
        if ($post->post_type === 'attachment') {
            $parent = ($post->post_parent && $depth < 5) ? get_post($post->post_parent) : null;
            return $parent ? self::can_read_post($parent, $depth + 1) : true;
        }
        $no_password = (string) $post->post_password === '';
        if ($post->post_status === 'publish' && $no_password && is_post_type_viewable($post->post_type)) return true;
        if (current_user_can('edit_post', $post->ID)) return true;
        return $post->post_status === 'private' && $no_password && current_user_can('read_post', $post->ID);
    }

    /** Чи можна РЕДАГУВАТИ пост (нативний meta-cap edit_post: автор — лише свої). */
    static function can_edit_post($post_id) {
        return current_user_can('edit_post', $post_id);
    }

    /** Статуси, що вимагають права публікації (мірор нативного WP: publish/private/future). */
    static function is_publish_status($status) {
        return in_array($status, ['publish', 'private', 'future'], true);
    }

    /** Чи може користувач публікувати пости цього типу (для переходу в publish/private/future). */
    static function can_publish_type($post_type) {
        $pto = get_post_type_object($post_type);
        $cap = $pto && !empty($pto->cap->publish_posts) ? $pto->cap->publish_posts : 'publish_posts';
        return current_user_can($cap);
    }

    /**
     * Розбір ACF-селектора post_id: int → post, "option"/"options"/"options_*" → опції,
     * "user_5" → користувач, "term_10" → терм, "comment_5" → коментар.
     * type 'other' — селектор, який ми не вміємо cap-перевіряти (обробляється як помилка входу,
     * а не як відмова в правах). Повертає ['type', 'id'].
     */
    static function acf_target($post_id) {
        if (is_numeric($post_id)) return ['type' => 'post', 'id' => (int) $post_id];
        $s = (string) $post_id;
        if ($s === 'option' || $s === 'options' || strpos($s, 'options_') === 0) return ['type' => 'option', 'id' => null];
        if (preg_match('/^user_(\d+)$/', $s, $m))    return ['type' => 'user', 'id' => (int) $m[1]];
        if (preg_match('/^term_(\d+)$/', $s, $m))    return ['type' => 'term', 'id' => (int) $m[1]];
        if (preg_match('/^comment_(\d+)$/', $s, $m)) return ['type' => 'comment', 'id' => (int) $m[1]];
        return ['type' => 'other', 'id' => null];
    }

    /**
     * Cap-перевірка ACF-цілі. $write=false — читання, true — запис.
     * Повертає true (дозволено) або рядок-назву відсутнього права (для err_cap).
     * Для type 'other' повертає true — небезпеку відсіює caller через plain-err ще до виклику.
     * Опції при читанні тут перевіряються грубо (право хоча б однієї options-сторінки);
     * точну сторінку конкретного поля перевіряє acf_get.
     */
    static function acf_cap_check($target, $write) {
        $id = (int) ($target['id'] ?? 0);
        switch ($target['type']) {
            case 'post':
                if ($write) return self::can_edit_post($id) ?: 'edit_post #' . $id;
                return self::can_read_post($id) ?: 'read post #' . $id;
            case 'option':
                // Опції — глобальні налаштування сайту: запис лише manage_options; читання — як у
                // wp-admin: capability options-сторінки (за замовчуванням ACF — edit_posts).
                if ($write) return current_user_can('manage_options') ?: 'manage_options';
                if (current_user_can('manage_options')) return true;
                foreach (self::acf_options_pages() as $page) {
                    if (current_user_can((string) ($page['capability'] ?? 'edit_posts'))) return true;
                }
                return 'manage_options';
            case 'user':
                if (!$write && get_current_user_id() === $id) return true;
                return current_user_can('edit_user', $id) ?: 'edit_user #' . $id;
            case 'term':
                if ($write) return current_user_can('edit_term', $id) ?: 'edit_term #' . $id;
                // терми публічних таксономій читаються вільно; непублічних — лише з edit_term
                $term = get_term($id);
                if ($term instanceof WP_Term && is_taxonomy_viewable($term->taxonomy)) return true;
                return current_user_can('edit_term', $id) ?: 'edit_term #' . $id;
            case 'comment':
                // нативний meta-cap: модератор — будь-який, автор — коментарі до своїх постів
                return current_user_can('edit_comment', $id) ?: 'edit_comment #' . $id;
        }
        return true; // 'other' — валідність селектора перевіряє caller
    }

    // ── Спільні контракти для всіх модулів інструментів ──────────────────
    // (сигнатури стабільні — модулі blocks/content/posts/wploc на них покладаються)

    const BACKUP_META = '_simple_mcp_backup';
    const BACKUP_KEEP = 5;

    /** UTF-8-безпечне обрізання до $bytes байтів (не розрізає багатобайтовий символ). */
    static function mb_cut($s, $bytes) {
        $s = (string) $s;
        if (strlen($s) <= $bytes) return $s;
        return function_exists('mb_strcut') ? mb_strcut($s, 0, $bytes, 'UTF-8') : wp_check_invalid_utf8(substr($s, 0, $bytes), true);
    }

    /**
     * Пост для ЗАПИСУ: існує, не ревізія/автозбереження (це точки відкату), не в кошику (як у
     * wp-admin; $allow_trash=true — для update_post, що сам відновлює з кошика, читань і модулів,
     * які відмовляють для кошика власним повідомленням) і в користувача є нативний edit_post.
     * Повертає WP_Post або готовий MCP-результат помилки (масив) —
     * у caller: `$post = Simple_MCP_Tools::writable_post($id); if (is_array($post)) return $post;`
     */
    static function writable_post($post_id, $allow_trash = false) {
        $post_id = (int) $post_id;
        $post = $post_id ? get_post($post_id) : null;
        if (!$post) return self::err('Пост не знайдено');
        if ($post->post_type === 'revision' || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return self::err('#' . $post_id . ' — це ревізія/автозбереження (точка відкату), а не пост. Редагуй батьківський пост #'
                . (int) $post->post_parent . ' або віднови ревізію через revision_restore.');
        }
        if (!self::can_edit_post($post_id)) return self::err_cap('edit_post #' . $post_id);
        if (!$allow_trash && $post->post_status === 'trash') {
            // збереження поста в кошику на wp-loc ще й переносить статус "trash" на всі його переклади
            return self::err('Пост #' . $post_id . ' у кошику — спершу віднови його (update_post зі статусом не "trash"), потім редагуй.');
        }
        return $post;
    }

    /**
     * Валідація статусу поста. Повертає нормалізований статус або WP_Error.
     * Дозволені зареєстровані НЕслужбові статуси (publish/future/draft/pending/private + кастомні);
     * 'trash' — лише з $allow_trash. 'future' вимагає $date (час сайту, 'Y-m-d H:i:s') щонайменше на
     * хвилину в майбутньому — інакше WordPress (wp_insert_post) мовчки опублікує пост одразу.
     */
    static function validate_status($status, $allow_trash = false, $date = null) {
        $status  = sanitize_key((string) $status);
        $allowed = array_keys(get_post_stati(['internal' => false]));
        if ($allow_trash) $allowed[] = 'trash';
        $allowed = array_values(array_unique($allowed));
        if (!in_array($status, $allowed, true)) {
            return new WP_Error('bad_status', 'Невідомий або службовий статус "' . $status . '". Дозволено: ' . implode(', ', $allowed) . '.');
        }
        if ($status === 'future') {
            $ts = $date ? strtotime(get_gmt_from_date((string) $date) . ' +0000') : false;
            if (!$ts || $ts - time() < MINUTE_IN_SECONDS) {
                return new WP_Error('bad_future', 'Статус "future" потребує дату щонайменше на хвилину в майбутньому (date, "Y-m-d H:i:s" за часом сайту) — інакше WordPress опублікує пост одразу.');
            }
        }
        return $status;
    }

    /**
     * Нативна meta-cap перевірка запису post meta (захищені "_"-ключі вимагають зареєстрованого
     * auth_callback — як у REST API). true або назва відсутнього права для err_cap().
     */
    static function meta_write_check($post_id, $key, $exists = null) {
        $key = (string) $key;
        if ($key === '') return 'meta key';
        $exists = $exists ?? metadata_exists('post', (int) $post_id, $key);
        $cap = $exists ? 'edit_post_meta' : 'add_post_meta';
        return current_user_can($cap, (int) $post_id, $key) ?: $cap . ' (' . $key . ')';
    }

    /**
     * Серіалізація записів одного поста між паралельними MCP-викликами (MySQL GET_LOCK).
     * ВСЕРЕДИНІ $fn перечитуй пост (clean_post_cache + get_post) — інакше lock марний.
     * Повертає результат $fn або MCP-помилку, якщо lock не взято за $timeout секунд.
     */
    static function with_post_lock($post_id, callable $fn, $timeout = 10) {
        global $wpdb;
        $name = 'smcp_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . (int) $post_id);
        $got  = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, (int) $timeout));
        if ($got !== 1) {
            return self::err('Пост #' . (int) $post_id . ' зараз змінює інший MCP-виклик — повтори за кілька секунд.');
        }
        try {
            return $fn();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /** Непрозорий тег версії тіла поста (свіжо з БД). Читаючі інструменти віддають etag, записи приймають if_match. */
    static function content_etag($post_id) {
        clean_post_cache((int) $post_id);
        $p = get_post((int) $post_id);
        return $p ? md5((string) $p->post_content) : '';
    }

    /** null — якщо if_match не передано або він збігається; інакше MCP-помилка «тіло змінилось після читання». */
    static function precondition($post_id, $args) {
        if (!isset($args['if_match']) || (string) $args['if_match'] === '') return null;
        $cur = self::content_etag($post_id);
        if (!hash_equals($cur, (string) $args['if_match'])) {
            return self::err('Конфлікт версій: пост #' . (int) $post_id . ' змінився після читання (if_match ' . $args['if_match']
                . ' ≠ поточний etag ' . $cur . '). Перечитай пост (block_get / get_post) і повтори правку.');
        }
        return null;
    }

    /**
     * Чи збереглось тіло так, як задумано: побайтово, або — оскільки ACF на content_save_pre
     * перекодовує атрибути блоків (напр. "\" → "\\") — те саме розібране дерево блоків
     * (строго: same_tree). kses-зміни дають різні значення, тож лишаються помітними.
     */
    static function content_matches($saved, $expected) {
        $saved = (string) $saved;
        $expected = (string) $expected;
        if ($saved === $expected) return true;
        if (strpos($expected, '<!-- wp:') === false && strpos($saved, '<!-- wp:') === false) return false;
        return self::same_tree(parse_blocks($saved), parse_blocks($expected));
    }

    /**
     * Строге глибоке порівняння дерев parse_blocks: рядки — побайтово (нестроге == вважало б
     * рівними "007" і "7" чи "1e3" і "1000"), числа — між собою за величиною, масиви — ключ за
     * ключем (порядок ключів неважливий), решта — ===.
     */
    private static function same_tree($a, $b, $depth = 0) {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || count($a) !== count($b) || $depth > 512) return false;
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::same_tree($v, $b[$k], $depth + 1)) return false;
            }
            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) return $a == $b;
        return $a === $b;
    }

    /** Додати точку відкату в meta (кільце з BACKUP_KEEP останніх; значення wp_slash-ене, бо update_metadata робить wp_unslash). */
    static function add_backup($post_id, $content) {
        global $wpdb;
        add_post_meta((int) $post_id, self::BACKUP_META, wp_slash([
            'time'    => time(),
            'user_id' => get_current_user_id(),
            'content' => (string) $content,
        ]));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC",
            (int) $post_id, self::BACKUP_META
        ));
        foreach (array_slice($ids, self::BACKUP_KEEP) as $mid) {
            delete_metadata_by_mid('post', (int) $mid);
        }
    }

    /**
     * Meta-бекапи поста, новіші першими: [{backup_id, time, user_id, content, legacy}].
     * legacy — однослотовий бекап до 2.5.0 (збережений без wp_slash: \uXXXX могли пошкодитись).
     */
    static function get_backups($post_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC",
            (int) $post_id, self::BACKUP_META
        ));
        $out = [];
        foreach ((array) $rows as $r) {
            $v = maybe_unserialize($r->meta_value);
            if (is_array($v) && isset($v['content'])) {
                $out[] = ['backup_id' => (int) $r->meta_id, 'time' => (int) ($v['time'] ?? 0), 'user_id' => (int) ($v['user_id'] ?? 0),
                          'content' => (string) $v['content'], 'legacy' => false];
            } elseif (is_string($v)) {
                $out[] = ['backup_id' => (int) $r->meta_id, 'time' => 0, 'user_id' => 0, 'content' => $v, 'legacy' => true];
            }
        }
        return $out;
    }

    /**
     * Безпечний запис post_content: точка відкату (WP-ревізія, або meta-бекап, якщо ревізії
     * вимкнені) → wp_slash (щоб не побити блоковий \uXXXX JSON) → verify (content_matches).
     * $extra — додаткові поля wp_update_post (НЕ слешовані; слешуємо тут), щоб усе пішло одним
     * збереженням. Lock і if_match — відповідальність caller-а (with_post_lock / precondition).
     * Повертає true|false (verified) або WP_Error.
     */
    static function save_post_content($post_id, $content, $extra = []) {
        $post = get_post((int) $post_id);
        if (!$post) return new WP_Error('not_found', 'Пост не знайдено');
        // rollback point BEFORE mutating
        if (wp_revisions_enabled($post)) {
            wp_save_post_revision($post->ID);
        } else {
            self::add_backup($post->ID, $post->post_content);
        }
        $arr = ['ID' => $post->ID, 'post_content' => wp_slash((string) $content)];
        foreach ((array) $extra as $k => $v) {
            if ($k === 'ID' || $k === 'post_content') continue;
            $arr[$k] = wp_slash($v);
        }
        $r = self::with_raw_terms(function () use ($arr) { return wp_update_post($arr, true); }, $post->ID);
        if (is_wp_error($r)) return $r;
        clean_post_cache($post->ID);
        return self::content_matches(get_post($post->ID)->post_content, (string) $content);
    }

    /**
     * Збереження поста на мультимовному сайті «як у wp-admin»: без підміни ID термів на переклад
     * поточної мови (wp-loc/WPML роблять її в get_term() поза адмінкою, тобто і в MCP-запиті) і без
     * закешованих підмінених результатів (term-запити, кеш зв'язків поста з термами). Інакше
     * wp_update_post перекладу (напр. en при мові сайту uk) перечитує його рубрики як uk-відповідники,
     * дописує їх до поста, а term sync wp-loc розносить це по групі. $post_id — пост, що зберігається
     * (його кеш термів і кеш його перекладів скидаються). Без мультимовності — просто $fn().
     */
    static function with_raw_terms(callable $fn, $post_id = 0) {
        if (!class_exists('Simple_MCP_Tools_Wploc') || !Simple_MCP::multilingual_system()
            || !method_exists('Simple_MCP_Tools_Wploc', 'with_raw_terms')) {
            return $fn();
        }
        $ids = [];
        if ($post_id && ($p = get_post((int) $post_id))) {
            $ids = array_merge([(int) $p->ID], array_map('intval', array_values(self::translation_siblings($p))));
        }
        self::forget_terms($ids); // підмінені ID, закешовані раніше, не перечитуємо
        try {
            return Simple_MCP_Tools_Wploc::with_raw_terms($fn);
        } finally {
            self::forget_terms($ids); // і «сирі» результати не дістаються пізнішим звичайним читанням
        }
    }

    /** Скинути кеш зв'язків постів $ids з термами і кеш term-запитів (last_changed). */
    private static function forget_terms(array $ids) {
        foreach ($ids as $id) {
            $pt = get_post_type($id);
            if ($pt) clean_object_term_cache($id, $pt);
        }
        if (function_exists('wp_cache_set_terms_last_changed')) wp_cache_set_terms_last_changed(); // WP 6.3+
        else wp_cache_set('last_changed', microtime(), 'terms');
    }

    // ── Інструменти: пости ────────────────────────────────────────────────

    /** Сумісність: токенайзер переїхав у Simple_MCP_CLI (includes/class-simple-mcp-cli.php). */
    static function tokenize($str) {
        return Simple_MCP_CLI::tokenize($str);
    }

    static function tool_get_post($args) {
        $id = intval($args['id'] ?? 0);
        $p  = $id ? get_post($id) : null;
        if (!$p) return self::err('Пост не знайдено');
        if (!self::can_read_post($p)) {
            return self::err_cap('читання поста #' . $id . ' (чернетка / приватний / з паролем / непублічний тип — потрібне edit_post)');
        }
        $etag  = self::content_etag($id); // свіжо з БД
        $p     = get_post($id);
        $thumb = (int) get_post_thumbnail_id($p);
        return self::ok([
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'status'     => $p->post_status,
            'type'       => $p->post_type,
            'slug'       => $p->post_name,
            'author'     => (int) $p->post_author,
            'date'       => $p->post_date,
            'modified'   => $p->post_modified,
            'excerpt'    => $p->post_excerpt,
            'parent'     => (int) $p->post_parent,
            'menu_order' => (int) $p->menu_order,
            'thumbnail'  => $thumb ?: null,
            'etag'       => $etag,
            'content'    => $p->post_content,
        ]);
    }

    static function tool_update_post($args) {
        $id   = intval($args['id'] ?? 0);
        $post = self::writable_post($id, true); // кошик update_post обробляє сам (відновлення / переміщення)
        if (is_array($post)) return $post;
        return self::with_post_lock($id, function () use ($id, $args) {
            return self::update_post_locked($id, $args);
        });
    }

    /** Тіло update_post під локом поста: перечитати → if_match → валідація всього → один save. */
    private static function update_post_locked($id, $args) {
        clean_post_cache($id);
        $post = self::writable_post($id, true); // свіжий стан (пост могли видалити/змінити, поки чекали lock)
        if (is_array($post)) return $post;
        $pre = self::precondition($id, $args);
        if ($pre) return $pre;

        // ── 1. Валідація всіх полів ДО будь-якого запису ──
        $extra = [];
        if (array_key_exists('title', $args))   $extra['post_title']   = (string) $args['title'];   // як є (kses — через title_save_pre)
        if (array_key_exists('excerpt', $args)) $extra['post_excerpt'] = (string) $args['excerpt'];
        if (array_key_exists('slug', $args)) {
            $slug = sanitize_title((string) $args['slug']);
            if ($slug === '') return self::err('slug порожній після санітизації');
            $extra['post_name'] = $slug;
        }
        if (array_key_exists('menu_order', $args)) $extra['menu_order'] = (int) $args['menu_order'];
        $date = null;
        if (array_key_exists('date', $args)) {
            $date = self::normalize_date($args['date']);
            if (is_wp_error($date)) return self::err($date->get_error_message());
            // edit_date + явний GMT: інакше wp_update_post лишить старий post_date_gmt (чи скине дату чернетки)
            $extra['post_date']     = $date;
            $extra['post_date_gmt'] = get_gmt_from_date($date);
            $extra['edit_date']     = true;
        }
        if (array_key_exists('parent', $args)) {
            $parent = (int) $args['parent'];
            $chk = self::check_parent($post, $parent);
            if ($chk !== true) return $chk;
            $extra['post_parent'] = $parent;
        }
        $thumb = null;
        if (array_key_exists('thumbnail', $args)) {
            $thumb = (int) $args['thumbnail'];
            if ($thumb < 0) return self::err('thumbnail має бути ID вкладення-зображення або 0 (прибрати)');
            if ($thumb > 0) {
                $att = get_post($thumb);
                if (!$att || $att->post_type !== 'attachment') return self::err('thumbnail #' . $thumb . ' — не вкладення медіатеки');
                if (!wp_attachment_is_image($att)) return self::err('thumbnail #' . $thumb . ' — не зображення');
                if (!self::can_read_post($att)) return self::err_cap('read attachment #' . $thumb);
            }
        }
        $meta = [];
        if (array_key_exists('meta', $args)) {
            if (!is_array($args['meta']) || ($args['meta'] !== [] && array_is_list($args['meta']))) {
                return self::err('meta має бути об\'єктом key → value (null — видалити ключ)');
            }
            foreach ($args['meta'] as $k => $v) {
                $k = (string) $k;
                if ($v === null) {
                    if (!current_user_can('delete_post_meta', $id, $k)) return self::err_cap('delete_post_meta (' . $k . ')');
                } else {
                    $c = self::meta_write_check($id, $k);
                    if ($c !== true) return self::err_cap($c);
                }
                $meta[$k] = $v;
            }
        }
        $has_content = array_key_exists('content', $args);
        $changes     = $extra || $has_content || $thumb !== null || $meta;

        // ── 2. Статус: validate_status + кошик через нативні wp_trash_post / wp_untrash_post ──
        $cur  = $post->post_status;
        $want = null;
        if (array_key_exists('status', $args)) {
            $st = self::validate_status($args['status'], true, $date ?? $post->post_date);
            if (is_wp_error($st)) return self::err($st->get_error_message());
            $want = $st;
        }
        $trash = $untrash = false;
        if ($cur === 'trash') {
            if ($want === null || $want === 'trash') {
                if ($changes) {
                    return self::err('Пост #' . $id . ' у кошику — спершу віднови його: передай status (напр. "draft"); інші поля можна змінити в тому ж виклику.');
                }
                if ($want === 'trash') return self::ok(['id' => $id, 'updated' => false, 'status' => 'trash', 'note' => 'already in trash']);
            } else {
                // wp-admin вимагає delete_post для відновлення з кошика
                if (!current_user_can('delete_post', $id)) return self::err_cap('delete_post #' . $id . ' (відновлення з кошика)');
                $untrash = true;
            }
        } elseif ($want === 'trash') {
            // Кошик — нативний meta-cap delete_post (edit_others не дає права видаляти чужі пости)
            if (!current_user_can('delete_post', $id)) return self::err_cap('delete_post #' . $id);
            if (!EMPTY_TRASH_DAYS) {
                return self::err('Кошик на сайті вимкнено (EMPTY_TRASH_DAYS = 0): wp_trash_post видалив би пост #' . $id . ' назавжди. Для видалення використовуй safe_delete.');
            }
            // Медіа без MEDIA_TRASH: кошика медіатека не показує, а wp_scheduled_delete потім видалить
            // вкладення разом із файлами — так само, як відмовляє safe_delete.
            if ($post->post_type === 'attachment' && !MEDIA_TRASH) {
                return self::err('Кошик медіа на сайті вимкнено (MEDIA_TRASH вимкнено): вкладення #' . $id . ' в кошику не видно в медіатеці, а за EMPTY_TRASH_DAYS його видалить назавжди разом із файлами. Для видалення використовуй safe_delete {force: true}.');
            }
            // Переклади: sync атрибутів wp-loc переніс би "trash" на всю групу, а остаточне видалення
            // (очищення кошика) будь-якого члена групи wp-loc поширює на всі — це робить лише safe_delete.
            $sib = self::translation_siblings($post);
            if ($sib) {
                return self::err('У поста #' . $id . ' є переклади (' . implode(', ', array_map(function ($c, $sid) { return $c . ': #' . $sid; }, array_keys($sib), $sib))
                    . '): status "trash" переніс би їх у кошик разом із ним (sync атрибутів wp-loc), а очищення кошика видалило б усю групу. '
                    . 'Використай safe_delete {post_id: ' . $id . ', allow_cascade: true} — він від\'єднає пост від групи перекладів і перемістить у кошик лише його.');
            }
            $trash = true;
        }
        if (!$changes && !$trash && !$untrash) {
            if ($want === null) {
                return self::err('Нічого оновлювати: передай content, title, status, slug, excerpt, date, parent, menu_order, thumbnail або meta.');
            }
            if ($want === $cur) return self::ok(['id' => $id, 'updated' => false, 'status' => $cur, 'note' => 'status unchanged']);
        }
        // Публікація/приватність/планування — окреме нативне право (author може, contributor — ні).
        // 'future' теж потребує publish_posts: інакше через планування пост опублікується по cron.
        // Відновлений із кошика пост вважаємо чернеткою (wp_untrash_post → 'draft').
        $from = $untrash ? 'draft' : $cur;
        if ($want !== null && $want !== 'trash' && self::is_publish_status($want)
            && !self::is_publish_status($from) && !self::can_publish_type($post->post_type)) {
            return self::err_cap('publish_posts (' . $post->post_type . ')');
        }

        // ── 3. Запис ──
        $saved    = false;
        $verified = null;
        $prefix   = '';
        if ($untrash) {
            if (!self::with_raw_terms(function () use ($id) { return wp_untrash_post($id); }, $id)) {
                return self::err('Не вдалося відновити пост #' . $id . ' з кошика.');
            }
            clean_post_cache($id);
            $saved  = true;
            $prefix = 'Пост #' . $id . ' відновлено з кошика (статус "' . get_post_status($id) . '"), але ';
            if ($want !== get_post_status($id)) $extra['post_status'] = $want;
        } elseif ($want !== null && $want !== 'trash' && $want !== $cur) {
            $extra['post_status'] = $want;
        }
        if ($has_content) {
            // контент + решта полів одним збереженням (ревізія/бекап → wp_slash → verify)
            $r = self::save_post_content($id, (string) $args['content'], $extra);
            if (is_wp_error($r)) return self::err(($prefix ?: '') . 'не вдалося оновити: ' . $r->get_error_message());
            $verified = (bool) $r;
            $saved    = true;
        } elseif ($extra) {
            $arr = ['ID' => $id];
            foreach ($extra as $k => $v) $arr[$k] = wp_slash($v); // wp_update_post робить wp_unslash
            $r = self::with_raw_terms(function () use ($arr) { return wp_update_post($arr, true); }, $id);
            if (is_wp_error($r)) return self::err(($prefix ?: '') . 'не вдалося оновити: ' . $r->get_error_message());
            $saved = true;
        }
        $out_thumb = null;
        if ($thumb !== null) {
            if ($thumb === 0) delete_post_thumbnail($id);
            else set_post_thumbnail($id, $thumb);
            $out_thumb = (int) get_post_thumbnail_id($id);
        }
        $meta_res = [];
        foreach ($meta as $k => $v) {
            if ($v === null) {
                $meta_res[$k] = delete_post_meta($id, $k) ? 'deleted' : 'absent';
                continue;
            }
            $ok = update_post_meta($id, $k, wp_slash($v)); // update_metadata робить wp_unslash
            if ($ok) {
                $meta_res[$k] = 'updated';
            } else {
                // false = «не змінилось» або збій: звіряємо з тим, що лежить у БД (скаляри там — рядки)
                $now  = get_post_meta($id, $k, true);
                $same = is_array($v) || is_object($v)
                    ? serialize($now) === serialize($v)
                    : (is_scalar($now) && (string) $now === (is_bool($v) ? ($v ? '1' : '') : (string) $v));
                $meta_res[$k] = $same ? 'unchanged' : 'failed';
            }
        }
        if ($trash) {
            if (!self::with_raw_terms(function () use ($id) { return wp_trash_post($id); }, $id)) {
                return self::err('Не вдалося перемістити пост #' . $id . ' у кошик' . ($saved || $meta_res || $thumb !== null ? ' (інші зміни збережено)' : '') . '.');
            }
            $saved = true;
        }

        clean_post_cache($id);
        $p   = get_post($id);
        $out = [
            'id'               => $id,
            'updated'          => true,
            'status'           => $p->post_status,
            'slug'             => $p->post_name,
            'content_verified' => $verified,
            'etag'             => self::content_etag($id),
        ];
        if ($want !== null && $p->post_status !== $want) {
            $out['status_note'] = 'WordPress stored status "' . $p->post_status . '" instead of the requested "' . $want . '" (e.g. a future date schedules a published item).';
        }
        if ($thumb !== null) {
            $out['thumbnail'] = $out_thumb ?: null;
            if ($out_thumb !== $thumb) $out['thumbnail_note'] = 'thumbnail was not set to #' . $thumb;
        }
        if ($meta_res) $out['meta'] = $meta_res;
        if ($saved || $thumb !== null || $meta_res) {
            $note = self::sync_note_for($p);
            if ($note) $out['sync_note'] = $note;
        }
        return self::ok($out);
    }

    /** Дата 'Y-m-d H:i:s' | 'Y-m-d H:i' | 'Y-m-d' (час сайту) → нормалізований 'Y-m-d H:i:s' або WP_Error. */
    static function normalize_date($s) {
        $s = trim((string) $s);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
            return new WP_Error('bad_date', 'Некоректна date "' . $s . '": очікується "Y-m-d H:i:s" за часом сайту.');
        }
        $h = (int) ($m[4] ?? 0);
        $i = (int) ($m[5] ?? 0);
        $c = (int) ($m[6] ?? 0);
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $h > 23 || $i > 59 || $c > 59) {
            return new WP_Error('bad_date', 'Некоректна date "' . $s . '": такої дати/часу не існує.');
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3], $h, $i, $c);
    }

    /** Батьківський пост: 0, або існуючий читабельний пост того ж ієрархічного типу без циклу. true | MCP-помилка. */
    private static function check_parent($post, $parent) {
        if ($parent < 0) return self::err('parent має бути ID поста або 0');
        if ($parent === 0) return true;
        if ($parent === (int) $post->ID) return self::err('Пост не може бути батьком самому собі');
        $pp = get_post($parent);
        if (!$pp) return self::err('Батьківський пост #' . $parent . ' не знайдено');
        if ($post->post_type === 'attachment') {
            // для вкладення parent = пост, до якого воно прикріплене
            return self::can_edit_post($parent) ?: self::err_cap('edit_post #' . $parent . ' (прикріплення медіа до поста)');
        }
        if (!is_post_type_hierarchical($post->post_type)) {
            return self::err('Тип "' . $post->post_type . '" не ієрархічний — parent не підтримується');
        }
        if ($pp->post_type !== $post->post_type) {
            return self::err('parent має бути того ж типу (' . $post->post_type . '), а #' . $parent . ' — ' . $pp->post_type);
        }
        if (in_array((int) $post->ID, array_map('intval', get_post_ancestors($pp)), true)) {
            return self::err('Цикл: #' . $parent . ' є нащадком поста #' . $post->ID);
        }
        if (!self::can_read_post($pp)) return self::err_cap('read post #' . $parent);
        return true;
    }

    /** Переклади поста в інших мовах [code => id] (мультимовний модуль), [] — якщо немає або модуль вимкнений. */
    private static function translation_siblings($post) {
        if (!$post || !class_exists('Simple_MCP_Tools_Wploc') || !Simple_MCP::multilingual_system()
            || !method_exists('Simple_MCP_Tools_Wploc', 'siblings')) {
            return [];
        }
        return (array) Simple_MCP_Tools_Wploc::siblings($post->ID, 'post_' . $post->post_type);
    }

    /**
     * Попередження мультимовного модуля (wp-loc sync атрибутів/термів на всі переклади) — лише якщо
     * воно увімкнене і в поста справді є переклади. null — коли нерелевантно або модуля немає.
     */
    private static function sync_note_for($post) {
        if (!$post || !class_exists('Simple_MCP_Tools_Wploc') || !Simple_MCP::multilingual_system()) return null;
        if (!method_exists('Simple_MCP_Tools_Wploc', 'sync_note')) return null;
        $note = Simple_MCP_Tools_Wploc::sync_note();
        if (!$note) return null;
        if (method_exists('Simple_MCP_Tools_Wploc', 'siblings') && !self::translation_siblings($post)) return null;
        return (string) $note;
    }

    // ── Інструменти: ACF ──────────────────────────────────────────────────

    static function tool_acf_get($args) {
        if (!function_exists('get_field')) return self::err('ACF не активний');
        $t = self::acf_resolve_target($args['post_id'] ?? 0, $args['lang'] ?? null);
        if (isset($t['isError'])) return $t;
        $cap = self::acf_cap_check($t, false);
        if ($cap !== true) return self::err_cap((string) $cap);
        $format  = !array_key_exists('format', $args) || !empty($args['format']);
        $acf_id  = $t['acf_id'];
        $located = self::acf_located_fields($t);

        $field = isset($args['field']) ? trim((string) $args['field']) : '';
        if ($field !== '') {
            $r = self::acf_resolve_field($field, $t, $located);
            if (is_wp_error($r)) return self::err($r->get_error_message());
            if ($t['type'] === 'option') {
                $c = self::acf_option_read_check($r['pages']);
                if ($c !== true) return self::err_cap($c);
            }
            $f = $r['field'];
            return self::ok([
                'post_id'   => $acf_id,
                'field'     => $field,
                'name'      => $f['name'],
                'field_key' => $f['key'],
                'type'      => (string) ($f['type'] ?? ''),
                'formatted' => $format,
                'value'     => self::acf_read($acf_id, $f, $format),
            ]);
        }

        // Усі поля: розміщені на цілі (для опцій — лише зі сторінок, доступних користувачу)…
        $out = [];
        foreach ($located as $e) {
            $name = $e['field']['name'];
            if (array_key_exists($name, $out)) continue;
            if ($t['type'] === 'option' && self::acf_option_read_check($e['pages']) !== true) continue;
            $out[$name] = self::acf_read($acf_id, $e['field'], $format);
        }
        // …плюс поля зі збереженим значенням, чия група вже не розміщена тут (напр. змінився шаблон).
        if ($t['type'] !== 'option' || current_user_can('manage_options')) {
            $stored = function_exists('get_field_objects') ? get_field_objects($acf_id, false, false) : null;
            foreach ((array) $stored as $name => $f) {
                if (!is_array($f) || array_key_exists($name, $out)) continue;
                $out[$name] = self::acf_read($acf_id, $f, $format);
            }
        }
        return self::ok(['post_id' => $acf_id, 'formatted' => $format, 'fields' => $out ?: (object) []]);
    }

    static function tool_acf_update($args) {
        if (!function_exists('update_field')) return self::err('ACF не активний');
        $t = self::acf_resolve_target($args['post_id'] ?? 0, $args['lang'] ?? null);
        if (isset($t['isError'])) return $t;
        if ($t['type'] === 'post') {
            $p = self::writable_post($t['id']); // не ревізія/автозбереження + edit_post
            if (is_array($p)) return $p;
        }
        $cap = self::acf_cap_check($t, true);
        if ($cap !== true) return self::err_cap((string) $cap);
        $sel = trim((string) ($args['field'] ?? ''));
        if ($sel === '') return self::err("Потрібне поле (ім'я або field_key)");
        if (!array_key_exists('value', $args)) return self::err('Потрібне value');

        $r = self::acf_resolve_field($sel, $t, self::acf_located_fields($t));
        if (is_wp_error($r)) return self::err($r->get_error_message());
        $f      = $r['field'];
        $acf_id = $t['acf_id'];
        $value  = $args['value'];

        // ACF мовчки пропускає невідомі під-поля й рядки-не-об'єкти, а репітер ще й обрізає до кількості
        // надісланих рядків — тож структуру контейнера звіряємо зі схемою ДО запису.
        $shape = $value === null ? [] : self::acf_shape_errors($f, $value, (string) $f['name']);
        if ($shape) {
            return self::err('Нічого не записано: значення не відповідає структурі поля "' . $f['name'] . '" (' . $f['key'] . '): '
                . implode('; ', array_slice($shape, 0, 20)) . (count($shape) > 20 ? '; … ще ' . (count($shape) - 20) : '') . '. Схему покаже acf_get з format:false.');
        }
        $sibs     = self::acf_translation_targets($t);
        $sib_meta = $t['type'] === 'term' ? 'term' : 'post';
        $sib_old  = [];
        foreach ($sibs as $code => $sid) $sib_old[$code] = self::acf_raw_snapshot($sib_meta, $sid, (string) $f['name']);

        $before = acf_get_value($acf_id, $f);
        acf_update_value(self::acf_slash($value, $t, $f), $acf_id, $f);
        if (function_exists('acf_flush_value_cache')) acf_flush_value_cache($acf_id, $f['name']);
        $after   = acf_get_value($acf_id, $f);
        $changed = serialize($before) !== serialize($after);
        $rows_sent = (is_array($value) && in_array((string) ($f['type'] ?? ''), ['repeater', 'flexible_content'], true))
            ? count(array_diff_key($value, ['acfcloneindex' => 1])) : null;
        if ($changed && $rows_sent !== null && $rows_sent !== (is_array($after) ? count($after) : 0)) {
            // рядків збереглося не стільки, скільки надіслано — повертаємо попереднє значення
            if (is_array($before)) acf_update_value(self::acf_slash($before, $t, $f), $acf_id, $f);
            else acf_delete_value($acf_id, $f);
            if (function_exists('acf_flush_value_cache')) acf_flush_value_cache($acf_id, $f['name']);
            return self::err('Значення поля "' . $f['name'] . '" для ' . $acf_id . ' не записано: надіслано ' . $rows_sent . ' рядк(ів), ACF зберіг '
                . (is_array($after) ? count($after) : 0) . '. Попереднє значення відновлено.');
        }
        // update_metadata повертає false і для «значення не змінилось», а мультимовний плагін може
        // мовчки проігнорувати запис (поле, спільне для всіх мов чи перекладів) — тож успіх визначаємо
        // за read-back: значення змінилось, або вже дорівнювало надісланому (ідемпотентний повтор;
        // звіряємо і з сирим, і з форматованим значенням); видалення (null) — сирого значення в
        // сховищі більше немає або воно порожнє (wp-loc «видаляє» контейнер, лишаючи 0 рядків).
        $stored = $value === null
            ? (!self::acf_raw_exists($acf_id, $f['name']) || in_array($after, [null, false, '', []], true))
            : ($changed || self::acf_value_matches($value, $after)
                || self::acf_value_matches($value, acf_format_value($after, $acf_id, $f)));
        if (!$stored) {
            return self::err('Значення поля "' . $f['name'] . '" (' . $f['key'] . ') для ' . $acf_id . ' не '
                . ($value === null ? 'видалено' : 'записано') . ': після запису там і далі '
                . self::mb_cut((string) wp_json_encode(self::acf_safe_value($after), JSON_UNESCAPED_UNICODE), 600) . '. '
                . ($t['type'] === 'option' && $acf_id !== $t['base']
                    ? 'Ймовірно, поле спільне для всіх мов (wp-loc) — змінюй його в мові за замовчуванням (post_id "option" без lang).'
                    : 'Ймовірно, запис проігнорував мультимовний плагін (поле спільне для перекладів — змінюй його в джерелі) або ACF нормалізував значення.'));
        }
        // wp-loc копіює спільні/copy-once контейнери (repeater/group/flexible/clone) у переклади лише на
        // acf/save_post, якого acf_update_value не викликає, — запускаємо цю синхронізацію самі, як wp-admin.
        if (Simple_MCP::multilingual_system() === 'wp-loc' && class_exists('WP_LOC')
            && method_exists('WP_LOC', 'instance') && is_object(WP_LOC::instance()->acf ?? null)
            && method_exists(WP_LOC::instance()->acf, 'sync_deferred_container_fields')) {
            WP_LOC::instance()->acf->sync_deferred_container_fields($acf_id);
        }
        $out = [
            'post_id'   => $acf_id,
            'field'     => $sel,
            'name'      => $f['name'],
            'field_key' => $f['key'],
            'updated'   => true,
            'changed'   => $changed,
            'value'     => self::acf_read($acf_id, $f, true),
        ];
        $sib_changed = [];
        foreach ($sibs as $code => $sid) {
            wp_cache_delete($sid, $sib_meta . '_meta');
            if (self::acf_raw_snapshot($sib_meta, $sid, (string) $f['name']) !== $sib_old[$code]) $sib_changed[$code] = $sid;
        }
        if ($sib_changed) {
            $out['translations_changed'] = $sib_changed;
            $out['translations_note'] = 'Мультимовний плагін записав це поле і в переклади (воно спільне або copy-once для перекладів): '
                . implode(', ', array_map(function ($c, $id) { return '#' . $id . ' (' . $c . ')'; }, array_keys($sib_changed), $sib_changed)) . '.';
        }
        return self::ok($out);
    }

    /**
     * Структура значення контейнерного поля ACF (repeater / flexible_content / group / clone) перед
     * записом. ACF мовчки пропускає під-поля з невідомими іменами, рядки, що не є об'єктами, і рядки
     * flexible без відомого acf_fc_layout, а репітер/flexible ще й обрізає до кількості переданих рядків —
     * дані губляться, а запис виглядає успішним. Повертає список проблем зі шляхами ([] — структура
     * коректна, або поле не контейнер); листові значення (текст, числа, ID) не перевіряє.
     */
    static function acf_shape_errors($f, $value, $path, $depth = 0) {
        $type = (string) ($f['type'] ?? '');
        if ($depth > 32 || !in_array($type, ['repeater', 'flexible_content', 'group', 'clone'], true)) return [];
        if (is_object($value)) $value = get_object_vars($value);
        if ($value === null || $value === '' || $value === false || $value === []) return []; // очищення
        $list = $type === 'repeater' || $type === 'flexible_content';
        if (!is_array($value)) {
            return [$path . ': ' . ($list ? 'очікується СПИСОК рядків [{під-поле: значення}, …]' : "очікується об'єкт {під-поле: значення}") . ', отримано ' . gettype($value)];
        }
        if (!$list) return self::acf_object_errors((array) ($f['sub_fields'] ?? []), $value, $path, [], $depth);
        $layouts = [];
        foreach ((array) ($f['layouts'] ?? []) as $l) {
            if (is_array($l) && ($l['name'] ?? '') !== '') $layouts[(string) $l['name']] = $l;
        }
        $errors = [];
        foreach ($value as $i => $row) {
            if ($i === 'acfcloneindex') continue;
            $rp = $path . '[' . $i . ']';
            if (is_object($row)) $row = get_object_vars($row);
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                $errors[] = $rp . ": рядок має бути об'єктом {під-поле: значення}, отримано " . gettype($row)
                    . (array_is_list($value) ? '' : ' (значення поля — СПИСОК рядків, а не один рядок)');
                continue;
            }
            if ($type === 'repeater') {
                $errors = array_merge($errors, self::acf_object_errors((array) ($f['sub_fields'] ?? []), $row, $rp, [], $depth));
                continue;
            }
            $ln = $row['acf_fc_layout'] ?? null;
            if (!is_string($ln) || !isset($layouts[$ln])) {
                $errors[] = $rp . '.acf_fc_layout: ' . ($ln === null ? "обов'язковий" : 'невідомий layout ' . self::mb_cut((string) wp_json_encode($ln), 80))
                    . ' (є: ' . implode(', ', array_keys($layouts)) . ')';
                continue;
            }
            $errors = array_merge($errors, self::acf_object_errors((array) ($layouts[$ln]['sub_fields'] ?? []), $row, $rp,
                ['acf_fc_layout', 'acf_fc_layout_disabled', 'acf_fc_layout_custom_label'], $depth));
        }
        return $errors;
    }

    /** Ключі рядка/об'єкта мають бути під-полями (field_key, ім'я, справжній key поля з клона); вкладені контейнери — рекурсивно. */
    private static function acf_object_errors(array $subs, array $obj, $path, array $extra, $depth) {
        $index = [];
        $names = [];
        foreach ($subs as $s) {
            if (!is_array($s)) continue;
            foreach (['key', '__key', 'name', '_name'] as $k) {
                if (isset($s[$k]) && is_string($s[$k]) && $s[$k] !== '') $index[$s[$k]] = $s;
            }
            if (($s['name'] ?? '') !== '') $names[(string) $s['name']] = true;
        }
        if (!$index) return []; // без під-полів ACF значення не розбирає — звіряти нема з чим
        $errors = [];
        foreach ($obj as $k => $v) {
            $k = (string) $k;
            if (in_array($k, $extra, true)) continue;
            if (!isset($index[$k])) {
                $errors[] = $path . '.' . $k . ': невідоме під-поле (є: ' . implode(', ', array_keys($names)) . ')';
                continue;
            }
            $errors = array_merge($errors, self::acf_shape_errors($index[$k], $v, $path . '.' . $k, $depth + 1));
        }
        return $errors;
    }

    /** Переклади ACF-цілі (пост або терм) [code => post ID | term_id]; [] — немає або мультимовний модуль вимкнений. */
    private static function acf_translation_targets($t) {
        if (!in_array($t['type'], ['post', 'term'], true) || !class_exists('Simple_MCP_Tools_Wploc')
            || !Simple_MCP::multilingual_system() || !method_exists('Simple_MCP_Tools_Wploc', 'siblings')) {
            return [];
        }
        $etype = $t['type'] === 'post' ? 'post_' . get_post_type($t['id']) : 'tax_' . ($t['taxonomy'] ?? '');
        return array_map('intval', (array) Simple_MCP_Tools_Wploc::siblings($t['id'], $etype));
    }

    /** Сирі meta поля (ім'я, під-поля name_* і їхні _-посилання) об'єкта — щоб побачити, чи запис дійшов і туди. */
    private static function acf_raw_snapshot($meta_type, $id, $name) {
        $out = [];
        foreach ((array) get_metadata($meta_type, (int) $id) as $k => $vals) {
            $k    = (string) $k;
            $bare = ($k !== '' && $k[0] === '_') ? substr($k, 1) : $k;
            if ($bare === $name || strpos($bare, $name . '_') === 0) $out[$k] = $vals;
        }
        ksort($out);
        return $out;
    }

    /** Чи збережене скалярне значення вже відповідає надісланому (bool ≈ 1/0/'', числа — за величиною). */
    private static function scalar_same($stored, $value) {
        if (!is_scalar($stored) && $stored !== null) return false;
        $s = (string) $stored;
        if (is_bool($value)) return $value ? in_array($s, ['1', 'true'], true) : in_array($s, ['', '0', 'false'], true);
        if (is_numeric($value) && is_numeric($s)) return (float) $s == (float) $value;
        return $s === (string) $value;
    }

    /**
     * Чи збережене значення ACF ($stored — сире або форматоване) відповідає надісланому ($sent):
     * скаляри — як scalar_same; списки — поелементно тієї ж довжини; об'єкти (рядки репітера, group) —
     * кожен надісланий ключ (ім'я або field_key) є в збереженому з таким самим значенням.
     */
    private static function acf_value_matches($sent, $stored, $depth = 0) {
        if ($depth > 32) return false;
        if (is_object($sent)) $sent = get_object_vars($sent);
        if (is_object($stored)) {
            $stored = (!is_array($sent) && isset($stored->ID)) ? $stored->ID : get_object_vars($stored);
        }
        if (!is_array($sent)) return !is_array($stored) && self::scalar_same($stored, $sent);
        if ($sent === []) return empty($stored);
        if (!is_array($stored)) {
            // зведення об'єкта з acf_get (пост/користувач/терм/зображення) проти збереженого ID
            foreach (['ID', 'id', 'term_id', 'comment_ID'] as $idk) {
                if (isset($sent[$idk]) && is_scalar($sent[$idk])) return self::scalar_same($stored, $sent[$idk]);
            }
            return false;
        }
        if (array_is_list($sent)) {
            $stored = array_values($stored);
            if (count($sent) !== count($stored)) return false;
            foreach ($sent as $i => $x) {
                if (!self::acf_value_matches($x, $stored[$i], $depth + 1)) return false;
            }
            return true;
        }
        $by_name = [];
        foreach ($stored as $k => $x) $by_name[self::acf_key_name($k)] = $x;
        foreach ($sent as $k => $x) {
            $k = self::acf_key_name($k);
            if (!array_key_exists($k, $by_name) || !self::acf_value_matches($x, $by_name[$k], $depth + 1)) return false;
        }
        return true;
    }

    /** Ключ значення ACF у порівняльній формі: field_key → ім'я поля; решта — як є. */
    private static function acf_key_name($k) {
        if (is_string($k) && function_exists('acf_is_field_key') && acf_is_field_key($k)) {
            $f = acf_get_field($k);
            if (is_array($f) && !empty($f['name'])) return (string) $f['name'];
        }
        return (string) $k;
    }

    /** Чи лежить у сховищі сире значення поля (опція чи meta) — перевірка, що видалення справді відбулось. */
    private static function acf_raw_exists($acf_id, $name) {
        $d = function_exists('acf_decode_post_id') ? acf_decode_post_id($acf_id) : null;
        if (!is_array($d) || empty($d['id'])) return false;
        if ($d['type'] === 'option') {
            $miss = new stdClass();
            return get_option($d['id'] . '_' . $name, $miss) !== $miss;
        }
        return metadata_exists((string) $d['type'], (int) $d['id'], (string) $name);
    }

    /**
     * Значення для acf_update_value: ACF, як і для $_POST, чекає wp_slash-ене (його meta/option-шар
     * робить wp_unslash) — інакше губився б один рівень зворотних слешів. Виняток — wp-loc: корені
     * контейнерів (repeater/group/flexible/clone) перекладених опцій ("options_en") він пише сам через
     * update_option БЕЗ wp_unslash, тож там слеші (і \' \") лягли б у сховище.
     */
    private static function acf_slash($value, $t, $f) {
        if ($t['type'] === 'option' && $t['acf_id'] !== $t['base'] && Simple_MCP::multilingual_system() === 'wp-loc'
            && in_array((string) ($f['type'] ?? ''), ['group', 'clone', 'repeater', 'flexible_content'], true)) {
            return $value;
        }
        return wp_slash($value);
    }

    /**
     * ACF-ціль з перевіркою існування об'єкта та мовною нормалізацією опцій. Повертає
     * acf_target() + 'acf_id' (селектор для ACF-API) [+ 'base' для опцій, 'taxonomy',
     * 'comment_post_type'] або MCP-помилку.
     */
    static function acf_resolve_target($post_id, $lang = null) {
        $t    = self::acf_target($post_id);
        $lang = $lang === null ? '' : trim((string) $lang);
        if ($t['type'] === 'other') {
            return self::err('Непідтримуваний селектор post_id: "' . $post_id . '". Підтримуються: число (пост), "option" / "options_{lang}", "user_{id}", "term_{id}", "comment_{id}".');
        }
        if ($lang !== '' && $t['type'] !== 'option') return self::err('lang застосовується лише до опцій (post_id "option").');
        switch ($t['type']) {
            case 'post':
                if (!$t['id'] || !get_post($t['id'])) return self::err('Пост не знайдено');
                $acf_id = $t['id'];
                break;
            case 'user':
                if (!get_userdata($t['id'])) return self::err('Користувача #' . $t['id'] . ' не знайдено');
                $acf_id = 'user_' . $t['id'];
                break;
            case 'term':
                $term = get_term($t['id']);
                if (!$term instanceof WP_Term) return self::err('Терм #' . $t['id'] . ' не знайдено');
                $t['taxonomy'] = $term->taxonomy;
                $acf_id = 'term_' . $t['id'];
                break;
            case 'comment':
                $c = get_comment($t['id']);
                if (!$c) return self::err('Коментар #' . $t['id'] . ' не знайдено');
                $t['comment_post_type'] = (string) get_post_type($c->comment_post_ID);
                $acf_id = 'comment_' . $t['id'];
                break;
            default: // option
                $s   = (string) $post_id;
                $opt = self::acf_option_selector($s === 'option' ? 'options' : $s, $lang);
                if (is_wp_error($opt)) return self::err($opt->get_error_message());
                $t['base'] = $opt['base'];
                $acf_id    = $opt['id'];
        }
        // ACF-нормалізація (option → options; хуки acf/validate_post_id, напр. мовний роутинг wp-loc)
        $t['acf_id'] = function_exists('acf_get_valid_post_id') ? acf_get_valid_post_id($acf_id) : $acf_id;
        return $t;
    }

    /**
     * Селектор опцій → ['base' => базовий post_id options-сторінки, 'id' => реальне сховище].
     * wp-loc: "options_{slug|code}" і lang → база (мова за замовчуванням) або "{base}_{slug}";
     * WPML → "options_{code}", з ACFML — і "{base}_{code}" для власних баз (обидва через
     * Simple_MCP_Tools_Wploc::options_post_id). Без мультимовності селектор лишається як є, а lang — помилка.
     */
    private static function acf_option_selector($sel, $lang) {
        $base   = self::acf_options_base($sel);
        $suffix = $sel === $base ? '' : (string) substr($sel, strlen($base) + 1);
        $ml = class_exists('Simple_MCP_Tools_Wploc') && Simple_MCP::multilingual_system()
            && method_exists('Simple_MCP_Tools_Wploc', 'options_post_id');
        if (!$ml) {
            if ($lang !== '') return new WP_Error('no_ml', 'Параметр lang потребує мультимовності (wp-loc / WPML), а на сайті її немає.');
            return ['base' => $base, 'id' => $sel];
        }
        $id = $sel;
        $from_sel = null;
        if ($suffix !== '') {
            $pid = self::acf_lang_options_id($suffix, $base);
            if (is_wp_error($pid)) return new WP_Error('bad_lang', 'Селектор "' . $sel . '": ' . $pid->get_error_message());
            $id = $from_sel = $pid;
        }
        if ($lang !== '') {
            $by_lang = self::acf_lang_options_id($lang, $base);
            if (is_wp_error($by_lang)) return new WP_Error('bad_lang', $by_lang->get_error_message());
            if ($from_sel !== null && $from_sel !== $by_lang) {
                return new WP_Error('lang_conflict', 'Селектор "' . $sel . '" і lang "' . $lang . '" вказують на різні мови.');
            }
            $id = $by_lang;
        }
        return ['base' => $base, 'id' => $id];
    }

    /**
     * Реальне сховище опцій мови $lang для бази $base (контракт мультимовного модуля: wp-loc —
     * база / "{base}_{slug}"; WPML — "options_{code}"; власна база з ACFML — "{base}_{code}", крім баз,
     * що починаються з options/term_/block_/user_/widget_/{taxonomy}_, — вони спільні; без ACFML
     * власна база спільна). string|WP_Error.
     */
    private static function acf_lang_options_id($lang, $base) {
        $pid = Simple_MCP_Tools_Wploc::options_post_id($lang, $base);
        if (is_wp_error($pid)) return $pid;
        $pid = (string) $pid;
        // версія контракту з одним аргументом рахувала від "options" — переносимо суфікс на власну базу
        if ($base !== 'options' && strpos($pid, $base) !== 0 && strpos($pid, 'options') === 0) {
            $pid = $base . substr($pid, strlen('options'));
        }
        return $pid;
    }

    /** Усі ACF options-сторінки: [menu_slug => page]. */
    private static function acf_options_pages() {
        if (!function_exists('acf_get_options_pages')) return [];
        $pages = acf_get_options_pages();
        if (!is_array($pages)) return [];
        $out = [];
        foreach ($pages as $p) {
            if (is_array($p) && !empty($p['menu_slug'])) $out[(string) $p['menu_slug']] = $p;
        }
        return $out;
    }

    /** Базовий post_id сторінки опцій для селектора ("options_en" → "options"; власний post_id сторінки — як є). */
    private static function acf_options_base($sel) {
        $best = 'options';
        foreach (self::acf_options_pages() as $p) {
            $b = (string) ($p['post_id'] ?? 'options');
            if ($b === 'option') $b = 'options';
            if (($sel === $b || strpos($sel, $b . '_') === 0) && strlen($b) > strlen($best)) $best = $b;
        }
        return $best;
    }

    /** Options-сторінки з базовим post_id $base: [menu_slug => page]. */
    private static function acf_pages_for_base($base) {
        $out = [];
        foreach (self::acf_options_pages() as $slug => $p) {
            $b = (string) ($p['post_id'] ?? 'options');
            if ($b === 'option') $b = 'options';
            if ($b === $base) $out[$slug] = $p;
        }
        return $out;
    }

    /**
     * Поля верхнього рівня з груп, чиї location rules збігаються з ціллю (екрани як в ACF REST:
     * post_id / user_id / taxonomy / comment / options_page). Seamless-clone поля — з реальним
     * key (__key), бо ACF зберігає саме його. [['field' => …, 'pages' => [menu_slug…]], …]
     */
    static function acf_located_fields($t) {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) return [];
        $screens = [];
        switch ($t['type']) {
            case 'post':
                $s = ['post_id' => $t['id']];
                if (get_post_type($t['id']) === 'attachment') $s['attachment'] = $t['id'];
                $screens[''] = $s;
                break;
            case 'user':
                $screens[''] = ['user_id' => $t['id'], 'rest' => true]; // 'rest' — будь-яка user_form
                break;
            case 'term':
                $screens[''] = ['taxonomy' => $t['taxonomy']];
                break;
            case 'comment':
                $screens[''] = ['comment' => $t['comment_post_type']];
                break;
            case 'option':
                foreach (self::acf_pages_for_base($t['base']) as $slug => $p) $screens[$slug] = ['options_page' => $slug];
                break;
        }
        $out = [];
        foreach ($screens as $slug => $screen) {
            foreach ((array) acf_get_field_groups($screen) as $g) {
                if (!is_array($g)) continue;
                foreach ((array) acf_get_fields($g) as $f) {
                    if (!is_array($f) || (string) ($f['name'] ?? '') === '') continue; // tab/message/accordion
                    if (!empty($f['__key'])) $f['key'] = $f['__key'];
                    $k = $f['name'] . '|' . $f['key'];
                    if (!isset($out[$k])) $out[$k] = ['field' => $f, 'pages' => []];
                    if ($slug !== '') $out[$k]['pages'][] = $slug;
                }
            }
        }
        return array_values($out);
    }

    /**
     * Знайти поле для цілі: field_key або ім'я — лише серед груп, розміщених на цілі; інакше —
     * через уже збережене посилання "_{name}" (так само резолвить і get_field). Нічого не
     * вгадуємо через глобальний аліас імені ACF. ['field', 'pages', 'via'] або WP_Error.
     */
    static function acf_resolve_field($sel, $t, $located) {
        $acf_id = $t['acf_id'];
        $is_key = function_exists('acf_is_field_key') && acf_is_field_key($sel);
        $hits = [];
        foreach ($located as $e) {
            if ($is_key ? $e['field']['key'] === $sel : $e['field']['name'] === $sel) $hits[$e['field']['key']] = $e;
        }
        if (count($hits) === 1) return reset($hits) + ['via' => 'location'];
        if (count($hits) > 1) {
            return new WP_Error('ambiguous', 'Ім\'я поля "' . $sel . '" неоднозначне для ' . $acf_id . ': ' . implode(', ', array_keys($hits)) . '. Передай field_key.');
        }
        $f = null;
        if ($is_key) {
            $cand = acf_get_field($sel);
            if ($cand && !empty($cand['name']) && acf_get_reference($cand['name'], $acf_id) === $sel) $f = $cand;
        } elseif (function_exists('acf_get_meta_field')) {
            $f = acf_get_meta_field($sel, $acf_id) ?: null; // напр. рядок репітера "rows_0_title"
        }
        if ($f) return ['field' => $f, 'pages' => self::acf_field_pages($f, $t), 'via' => 'reference'];

        $names = array_values(array_unique(array_map(function ($e) { return $e['field']['name']; }, $located)));
        $hint  = $names
            ? 'Поля на цій цілі: ' . implode(', ', array_slice($names, 0, 60)) . (count($names) > 60 ? ', …' : '') . '.'
            : 'На цю ціль не розміщено жодної групи полів ACF.';
        return new WP_Error('unknown_field', 'Поле "' . $sel . '" не знайдено для ' . $acf_id
            . ': його немає в групах полів, чиї location rules збігаються з ціллю, і немає збереженого посилання. ' . $hint
            . ' Поля всередині блоків читай/пиши через block_get / block_update.');
    }

    /** Options-сторінки (того ж базового post_id), на яких розміщена група цього поля. */
    private static function acf_field_pages($f, $t) {
        if ($t['type'] !== 'option' || !function_exists('acf_get_field_group_visibility')) return [];
        $g = self::acf_field_group_of($f);
        if (!$g) return [];
        $pages = [];
        foreach (self::acf_pages_for_base($t['base']) as $slug => $p) {
            if (acf_get_field_group_visibility($g, ['options_page' => $slug])) $pages[] = $slug;
        }
        return $pages;
    }

    /** Група полів верхнього рівня для поля (піднімаємось по parent через підполя). */
    private static function acf_field_group_of($f) {
        for ($i = 0; is_array($f) && $i < 20; $i++) {
            $parent = $f['parent'] ?? '';
            if (!$parent) return null;
            if (!acf_is_field_key($parent)) {
                $g = acf_get_field_group($parent);
                if ($g) return $g;
            }
            $f = acf_get_field($parent);
        }
        return null;
    }

    /** Читання опцій: true, або назва права для err_cap (capability options-сторінки поля; поза сторінками — manage_options). */
    private static function acf_option_read_check($pages) {
        if (current_user_can('manage_options')) return true;
        $all  = self::acf_options_pages();
        $caps = [];
        foreach ((array) $pages as $slug) {
            $cap = (string) ($all[$slug]['capability'] ?? 'edit_posts');
            if (current_user_can($cap)) return true;
            $caps[] = $cap;
        }
        return $caps ? implode(' | ', array_unique($caps)) . ' (options page)' : 'manage_options';
    }

    /** Значення поля (як get_field: raw → за потреби acf_format_value) + прибирання секретів. */
    private static function acf_read($acf_id, $f, $format) {
        $v = acf_get_value($acf_id, $f);
        if ($format) $v = acf_format_value($v, $acf_id, $f);
        return self::acf_safe_value($v);
    }

    /**
     * Безпечне подання значень ACF у відповіді: WP_User/WP_Post/WP_Term/WP_Comment → короткі
     * зведення; із масивів-записів користувача прибираються user_pass/user_activation_key/user_email,
     * з постів — post_password; пост, який користувач не може читати, — лише ID.
     */
    static function acf_safe_value($v, $depth = 0) {
        if ($depth > 32) return null;
        if ($v instanceof WP_User) {
            return ['ID' => (int) $v->ID, 'user_nicename' => $v->user_nicename, 'display_name' => $v->display_name, 'user_url' => $v->user_url];
        }
        if ($v instanceof WP_Post) {
            if (!self::can_read_post($v)) return ['ID' => (int) $v->ID, 'restricted' => true];
            return ['ID' => (int) $v->ID, 'post_type' => $v->post_type, 'post_status' => $v->post_status,
                    'post_title' => $v->post_title, 'post_name' => $v->post_name, 'url' => get_permalink($v)];
        }
        if ($v instanceof WP_Term) {
            return ['term_id' => (int) $v->term_id, 'name' => $v->name, 'slug' => $v->slug, 'taxonomy' => $v->taxonomy];
        }
        if ($v instanceof WP_Comment) {
            return ['comment_ID' => (int) $v->comment_ID, 'comment_post_ID' => (int) $v->comment_post_ID,
                    'comment_author' => $v->comment_author, 'comment_date' => $v->comment_date, 'comment_approved' => $v->comment_approved];
        }
        if (is_object($v)) {
            if (!($v instanceof stdClass)) return '[object ' . get_class($v) . ']';
            $v = get_object_vars($v);
        }
        if (!is_array($v)) return $v;
        if (array_key_exists('user_pass', $v) || array_key_exists('user_activation_key', $v)
            || (array_key_exists('user_email', $v) && (isset($v['user_nicename']) || isset($v['user_login']) || isset($v['user_registered'])))) {
            unset($v['user_pass'], $v['user_activation_key'], $v['user_email']);
        }
        if (array_key_exists('post_password', $v) && isset($v['post_type'])) unset($v['post_password']);
        foreach ($v as $k => $item) {
            if (is_array($item) || is_object($item)) $v[$k] = self::acf_safe_value($item, $depth + 1);
        }
        return $v;
    }

    // ── Інструменти: медіа ────────────────────────────────────────────────

    /** Максимальний розмір файлу: chunked-завантаження та завантаження з url (захист від disk-DoS) */
    const MAX_UPLOAD_BYTES   = 1073741824; // 1 GB
    /** Сесія chunked-завантаження чинна стільки секунд після останнього чанка */
    const UPLOAD_IDLE_TTL    = 3600;
    /** Файли покинутих сесій (і їхні transients) прибирає gc_uploads() після цього віку */
    const UPLOAD_GC_AGE      = 7200;
    /** На користувача: активних сесій і сумарно незавершених байтів */
    const UPLOAD_MAX_ACTIVE  = 3;
    const UPLOAD_MAX_PENDING = 2147483648; // 2 GB

    static function tool_upload_media($args) {
        if (!current_user_can('upload_files')) return self::err_cap('upload_files');
        self::ensure_media_includes();
        $filename = sanitize_file_name((string) ($args['filename'] ?? ''));
        if ($filename === '') return self::err('Потрібне filename');
        $source = $args['source'] ?? 'base64';
        if ($source !== 'base64' && $source !== 'url') return self::err('source має бути base64 або url');
        $tmp = wp_tempnam($filename);
        if (!$tmp) return self::err('Не вдалося створити тимчасовий файл');

        if ($source === 'base64') {
            $data = base64_decode((string) ($args['data'] ?? ''), true);
            if ($data === false || $data === '') { @unlink($tmp); return self::err('Некоректний або порожній base64'); }
            if (file_put_contents($tmp, $data) !== strlen($data)) { @unlink($tmp); return self::err('Не вдалося записати тимчасовий файл (диск переповнений?)'); }
        } else {
            $url = esc_url_raw((string) ($args['url'] ?? ''));
            if (!$url) { @unlink($tmp); return self::err('Потрібен url'); }
            if (!Simple_MCP::url_is_safe($url)) { @unlink($tmp); return self::err('URL заблоковано (приватний/зарезервований хост)'); }
            // Стрімимо одразу у файл з лімітом розміру (download_url ліміту не має).
            $resp = wp_safe_remote_get($url, [
                'timeout'             => 60,
                'stream'              => true,
                'filename'            => $tmp,
                'limit_response_size' => self::MAX_UPLOAD_BYTES + 1,
            ]);
            if (is_wp_error($resp)) { @unlink($tmp); return self::err('Не вдалося завантажити: ' . $resp->get_error_message()); }
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code !== 200) { @unlink($tmp); return self::err('Не вдалося завантажити: HTTP ' . $code); }
            clearstatcache(true, $tmp);
            $size = (int) @filesize($tmp);
            if ($size > self::MAX_UPLOAD_BYTES) { @unlink($tmp); return self::err('Файл за URL більший за 1 GB — завантаження відхилено.'); }
            if ($size === 0) { @unlink($tmp); return self::err('За URL порожня відповідь'); }
        }

        return self::sideload_and_respond($tmp, $filename, $args);
    }

    static function tool_upload_begin($args) {
        if (!current_user_can('upload_files')) return self::err_cap('upload_files');
        $filename = sanitize_file_name((string) ($args['filename'] ?? ''));
        if ($filename === '') return self::err('Потрібне filename');
        $ft = wp_check_filetype($filename); // дозволені типи для поточного користувача
        if (empty($ft['ext'])) {
            return self::err('Тип файлу "' . $filename . '" не дозволено завантажувати цьому користувачу (розширення не в списку дозволених WordPress).');
        }
        self::gc_uploads(); // заодно прибираємо покинуті сесії
        $dir = self::tmp_dir();
        if (!$dir) return self::err('Не вдалося створити тимчасовий каталог');
        $uid = get_current_user_id();
        [$active, $pending] = self::up_usage($dir, $uid);
        if (count($active) >= self::UPLOAD_MAX_ACTIVE) {
            return self::err('Забагато незавершених завантажень (' . count($active) . ' з ' . self::UPLOAD_MAX_ACTIVE . '): '
                . implode(', ', $active) . '. Заверши їх (upload_finish) або зачекай — сесія спливає через годину після останнього чанка.');
        }
        if ($pending >= self::UPLOAD_MAX_PENDING) {
            return self::err('Незавершені завантаження вже займають ' . size_format($pending) . ' (ліміт 2 GB на користувача). Заверши їх (upload_finish).');
        }
        $id    = wp_generate_password(24, false, false);
        $p     = self::up_paths($dir, $id);
        $now   = time();
        $state = [
            'v' => 2, 'user_id' => $uid, 'filename' => $filename, 'created' => $now, 'updated' => $now,
            'bytes' => 0, 'carry' => '', 'next_index' => 0, 'last_index' => null, 'last_sha1' => null,
        ];
        if (@file_put_contents($p['part'], '') === false || !self::up_write_state($p['state'], $state)) {
            @unlink($p['part']);
            @unlink($p['state']);
            return self::err('Не вдалося створити файли сесії завантаження');
        }
        return self::ok(['upload_id' => $id, 'filename' => $filename, 'max_bytes' => self::MAX_UPLOAD_BYTES, 'expires_after_idle_seconds' => self::UPLOAD_IDLE_TTL]);
    }

    static function tool_upload_chunk($args) {
        if (!current_user_can('upload_files')) return self::err_cap('upload_files');
        $id    = (string) ($args['upload_id'] ?? '');
        $clean = preg_replace('/\s+/', '', (string) ($args['data'] ?? ''));
        if ($clean === '') return self::err('Порожній data');
        $index = array_key_exists('index', $args) ? (int) $args['index'] : null;
        if ($index !== null && $index < 0) return self::err('index має бути ≥ 0');

        $s = self::up_open($id);
        if (isset($s['isError'])) return $s;
        [$h, $st, $p] = $s;
        $sha  = sha1($clean);
        $next = (int) $st['next_index'];
        $resp = function ($st, $dup) use ($id) {
            return ['upload_id' => $id, 'index' => $st['last_index'], 'next_index' => (int) $st['next_index'],
                    'bytes' => (int) $st['bytes'], 'pending_chars' => strlen((string) $st['carry']), 'duplicate' => $dup];
        };
        if ($index !== null) {
            if ($st['last_index'] !== null && $index === (int) $st['last_index']) {
                self::up_close($h);
                if (hash_equals((string) $st['last_sha1'], $sha)) return self::ok($resp($st, true)); // повтор — no-op
                return self::err('Чанк index ' . $index . ' уже прийнято з ІНШИМ вмістом — стан не змінено. Наступний очікуваний index: ' . $next . '.');
            }
            if ($index < $next) {
                self::up_close($h);
                return self::err('Чанк index ' . $index . ' уже прийнято раніше — стан не змінено. Наступний очікуваний index: ' . $next . '.');
            }
            if ($index > $next) {
                self::up_close($h);
                return self::err('Пропущено чанк(и): очікується index ' . $next . ', отримано ' . $index . ' — стан не змінено.');
            }
        }
        $dec = self::b64_stream((string) $st['carry'], $clean);
        if (is_wp_error($dec)) {
            self::up_close($h);
            return self::err($dec->get_error_message() . ' — чанк не прийнято, стан завантаження не змінився.');
        }
        [$bytes, $carry] = $dec;
        $total = (int) $st['bytes'] + strlen($bytes);
        if ($total > self::MAX_UPLOAD_BYTES) {
            self::up_close($h);
            @unlink($p['part']);
            @unlink($p['state']);
            return self::err('Перевищено ліміт розміру завантаження (1 GB) — сесію видалено.');
        }
        [, $pending] = self::up_usage(dirname($p['part']), get_current_user_id());
        if ($pending + strlen($bytes) > self::UPLOAD_MAX_PENDING) {
            self::up_close($h);
            return self::err('Перевищено ліміт незавершених завантажень (2 GB на користувача) — чанк не прийнято. Заверши інші завантаження.');
        }
        if (!self::up_append($h, (int) $st['bytes'], $bytes)) {
            self::up_close($h);
            return self::err('Не вдалося записати чанк (диск переповнений?) — стан не змінився, повтори.');
        }
        $old = $st;
        $st['bytes']      = $total;
        $st['carry']      = $carry;
        $st['last_index'] = $next;
        $st['last_sha1']  = $sha;
        $st['next_index'] = $next + 1;
        $st['updated']    = time();
        if (!self::up_write_state($p['state'], $st)) {
            ftruncate($h, (int) $old['bytes']); // відкочуємо дописане — стан і файл лишаються узгодженими
            self::up_close($h);
            return self::err('Не вдалося зберегти стан завантаження — чанк не прийнято, повтори.');
        }
        self::up_close($h);
        return self::ok($resp($st, false));
    }

    static function tool_upload_finish($args) {
        if (!current_user_can('upload_files')) return self::err_cap('upload_files');
        self::ensure_media_includes();
        // права на прикріплення — ДО споживання сесії, щоб відмова не губила завантажене
        $post_id = intval($args['post_id'] ?? 0);
        if ($post_id && !self::can_edit_post($post_id)) return self::err_cap('edit_post #' . $post_id . ' (прикріплення медіа до поста)');
        $want_sha = null;
        if (isset($args['sha256']) && (string) $args['sha256'] !== '') {
            $want_sha = strtolower(trim((string) $args['sha256']));
            if (!preg_match('/^[a-f0-9]{64}$/', $want_sha)) return self::err('sha256 має бути 64 hex-символи');
        }

        $s = self::up_open((string) ($args['upload_id'] ?? ''));
        if (isset($s['isError'])) return $s;
        [$h, $st, $p] = $s;
        $keep = ' Сесію збережено — виправ і повтори upload_finish (або дошли чанки).';

        // Хвіст base64 (<4 символів) декодуємо, але фіксуємо лише після всіх перевірок
        $tail = '';
        $rest = (string) $st['carry'];
        if ($rest !== '') {
            $r = rtrim($rest, '=');
            $tail = (strlen($r) >= 2 && strpos($r, '=') === false) ? base64_decode($r, true) : false;
            if ($tail === false || $tail === '') {
                self::up_close($h);
                return self::err('Незавершений base64: у сесії лишилось ' . strlen($rest) . ' символ(и) без пари — надішли решту даних через upload_chunk.' . $keep);
            }
        }
        $total = (int) $st['bytes'] + strlen($tail);
        if ($total === 0) { self::up_close($h); return self::err('Порожнє завантаження: не надіслано жодного байта.' . $keep); }
        if ($total > self::MAX_UPLOAD_BYTES) { self::up_close($h); return self::err('Перевищено ліміт розміру завантаження (1 GB).'); }
        if (isset($args['size']) && (int) $args['size'] !== $total) {
            self::up_close($h);
            return self::err('Розмір не збігається: очікувано ' . (int) $args['size'] . ' байтів, зібрано ' . $total . '.' . $keep);
        }
        if ($want_sha !== null) {
            $ctx = hash_init('sha256');
            fseek($h, 0);
            hash_update_stream($ctx, $h);
            if ($tail !== '') hash_update($ctx, $tail);
            $got = hash_final($ctx);
            if (!hash_equals($want_sha, $got)) {
                self::up_close($h);
                return self::err('sha256 не збігається: очікувано ' . $want_sha . ', зібрано ' . $got . '.' . $keep);
            }
        }
        if ($tail !== '') {
            if (!self::up_append($h, (int) $st['bytes'], $tail)) { self::up_close($h); return self::err('Не вдалося дописати хвіст файлу (диск?).' . $keep); }
            $st['bytes'] = $total;
            $st['carry'] = '';
            $st['updated'] = time();
            if (!self::up_write_state($p['state'], $st)) {
                ftruncate($h, $total - strlen($tail));
                self::up_close($h);
                return self::err('Не вдалося зберегти стан завантаження.' . $keep);
            }
        }
        // .part → .finishing у тому ж каталозі (атомарний rename; copy — запасний шлях з перевіркою).
        // .part видаляємо лише після успішного копіювання.
        $moved = @rename($p['part'], $p['fin']);
        if (!$moved) {
            $moved = @copy($p['part'], $p['fin']);
            clearstatcache(true, $p['fin']);
            if (!$moved || (int) @filesize($p['fin']) !== $total) {
                @unlink($p['fin']);
                self::up_close($h);
                return self::err('Не вдалося підготувати зібраний файл (диск переповнений?).' . $keep);
            }
            self::up_close($h);
            @unlink($p['part']);
        } else {
            self::up_close($h);
        }

        $res = self::sideload($p['fin'], (string) $st['filename'], $args, true);
        if (isset($res['isError'])) {
            // sideload не вдався: повертаємо файл у сесію (якщо він ще є), щоб не губити завантажене
            if (is_file($p['fin']) && @rename($p['fin'], $p['part'])) {
                $st['updated'] = time();
                self::up_write_state($p['state'], $st);
                return self::err($res['content'][0]['text'] . '.' . $keep);
            }
            @unlink($p['fin']);
            @unlink($p['state']);
            return $res;
        }
        @unlink($p['state']);
        @unlink($p['fin']); // media_handle_sideload уже перемістив файл; про всяк випадок
        $res['bytes'] = $total;
        if ($want_sha !== null) $res['sha256_verified'] = true;
        return self::ok($res);
    }

    /**
     * Прибирання покинутих chunked-завантажень: файли сесій (.part / .json / .finishing), яких
     * не торкались довше UPLOAD_GC_AGE, і їхні transients (сесії до 2.5.0). Викликається з
     * upload_begin та щоденного крону simple_mcp_prune. Повертає кількість видалених файлів.
     */
    static function gc_uploads() {
        $dir = self::tmp_dir(false);
        if (!$dir) return 0;
        $names = @scandir($dir);
        if (!is_array($names)) return 0;
        $groups = [];
        foreach ($names as $n) {
            if (preg_match('/^([A-Za-z0-9]{24})\.(part|json|json\.tmp|finishing)$/', $n, $m)) $groups[$m[1]][] = $dir . '/' . $n;
        }
        $cut   = time() - self::UPLOAD_GC_AGE;
        $count = 0;
        foreach ($groups as $id => $files) {
            $last = 0;
            foreach ($files as $f) {
                $t = (int) @filemtime($f);
                if ($t > $last) $last = $t;
            }
            if ($last >= $cut) continue;
            foreach ($files as $f) {
                if (@unlink($f)) $count++;
            }
            delete_transient('simple_mcp_up_' . $id);
        }
        return $count;
    }

    // ── Хелпери chunked-завантаження ──────────────────────────────────────
    // Стан сесії — у sidecar "<id>.json" поруч із "<id>.part"; власник = user_id сесії.
    // Усі зміни — під ексклюзивним flock на .part, тож паралельні чанки не перемішуються.

    private static function up_paths($dir, $id) {
        return ['part' => $dir . '/' . $id . '.part', 'state' => $dir . '/' . $id . '.json', 'fin' => $dir . '/' . $id . '.finishing'];
    }

    private static function up_read_state($file) {
        $raw = @file_get_contents($file);
        $s   = is_string($raw) ? json_decode($raw, true) : null;
        return (is_array($s) && isset($s['user_id'], $s['filename'], $s['bytes'])) ? $s : null;
    }

    /** Атомарний запис стану (tmp + rename). */
    private static function up_write_state($file, $state) {
        $tmp = $file . '.tmp';
        $ok  = @file_put_contents($tmp, wp_json_encode($state)) !== false && @rename($tmp, $file);
        if (!$ok) @unlink($tmp);
        return $ok;
    }

    /** Активні (не прострочені) сесії користувача та їхні байти: [[upload_id…], bytes]. */
    private static function up_usage($dir, $uid) {
        $active  = [];
        $pending = 0;
        $files   = glob($dir . '/*.json');
        foreach (is_array($files) ? $files : [] as $f) {
            $id = basename($f, '.json');
            if (!preg_match('/^[A-Za-z0-9]{24}$/', $id)) continue;
            $st = self::up_read_state($f);
            if (!$st || (int) $st['user_id'] !== (int) $uid) continue;
            if (time() - (int) ($st['updated'] ?? 0) > self::UPLOAD_IDLE_TTL) continue;
            $active[] = $id;
            $pending += (int) $st['bytes'];
        }
        return [$active, $pending];
    }

    /**
     * Відкрити СВОЮ сесію під flock: [handle, state, paths] або MCP-помилка. Чужа, невідома чи
     * прострочена сесія → однакове «Невідомий upload_id» (не розкриваємо існування чужих сесій).
     */
    private static function up_open($id) {
        $unknown = self::err('Невідомий або прострочений upload_id — почни нове завантаження (upload_begin).');
        if (!preg_match('/^[A-Za-z0-9]{24}$/', (string) $id)) return $unknown;
        $dir = self::tmp_dir(false);
        if (!$dir) return $unknown;
        $p = self::up_paths($dir, $id);
        if (!is_file($p['part']) || !is_file($p['state'])) return $unknown;
        $h = @fopen($p['part'], 'r+b'); // без створення: після finish файлу вже немає
        if (!$h) return $unknown;
        if (!flock($h, LOCK_EX)) { fclose($h); return self::err('Не вдалося заблокувати сесію завантаження — повтори.'); }
        clearstatcache(true, $p['part']);
        $st = self::up_read_state($p['state']);
        if (!$st || (int) $st['user_id'] !== get_current_user_id() || !is_file($p['part'])) {
            flock($h, LOCK_UN);
            fclose($h);
            return $unknown;
        }
        if (time() - (int) ($st['updated'] ?? 0) > self::UPLOAD_IDLE_TTL) {
            flock($h, LOCK_UN);
            fclose($h);
            @unlink($p['part']);
            @unlink($p['state']);
            return $unknown;
        }
        // Узгодження файлу зі станом: недописаний хвіст після збою — відрізаємо; коротший файл — пошкоджений.
        fseek($h, 0, SEEK_END);
        $size = (int) ftell($h);
        if ($size > (int) $st['bytes']) {
            ftruncate($h, (int) $st['bytes']);
        } elseif ($size < (int) $st['bytes']) {
            flock($h, LOCK_UN);
            fclose($h);
            @unlink($p['part']);
            @unlink($p['state']);
            return self::err('Сесію завантаження пошкоджено (файл коротший за прийняті дані) — її видалено, почни заново (upload_begin).');
        }
        return [$h, $st, $p];
    }

    private static function up_close($h) {
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }

    /** Дописати байти з позиції $offset; при неповному записі — відкат до $offset. */
    private static function up_append($h, $offset, $bytes) {
        if ($bytes === '') return true;
        if (fseek($h, (int) $offset) !== 0) return false;
        $len = strlen($bytes);
        $w   = 0;
        while ($w < $len) {
            $n = fwrite($h, $w === 0 ? $bytes : substr($bytes, $w));
            if ($n === false || $n === 0) break;
            $w += $n;
        }
        fflush($h);
        if ($w !== $len) {
            ftruncate($h, (int) $offset);
            return false;
        }
        return true;
    }

    /**
     * Декодування base64-потоку, розрізаного на ДОВІЛЬНИХ межах: $carry — хвіст попереднього чанка
     * (<4 символів). Повертає [bytes, new_carry] або WP_Error. Окремо закодовані чанки (з власним
     * "=" padding) теж працюють: декодуємо сегментами, що закінчуються групою з "=".
     */
    static function b64_stream($carry, $data) {
        $s = (string) $carry . preg_replace('/\s+/', '', (string) $data);
        if ($s !== '' && preg_match('/[^A-Za-z0-9+\/=]/', $s)) {
            return new WP_Error('b64', 'Некоректний base64: недопустимі символи (дозволено A-Z a-z 0-9 + / =)');
        }
        $cut  = strlen($s) - (strlen($s) % 4);
        $body = substr($s, 0, $cut);
        $out  = '';
        $pos  = 0;
        while ($pos < $cut) {
            $eq  = strpos($body, '=', $pos);
            $end = $eq === false ? $cut : ($eq - ($eq % 4) + 4); // кінець 4-символьної групи з "="
            $dec = base64_decode(substr($body, $pos, $end - $pos), true);
            if ($dec === false) return new WP_Error('b64', 'Некоректний base64 (група біля символу ' . $pos . '; "=" допустимий лише в кінці закодованого фрагмента)');
            $out .= $dec;
            $pos  = $end;
        }
        return [$out, (string) substr($s, $cut)];
    }

    // ── Хелпери медіа ─────────────────────────────────────────────────────

    static function ensure_media_includes() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    /** Тимчасовий каталог chunked-завантажень; $create=false — лише повернути наявний (для GC). */
    static function tmp_dir($create = true) {
        $up = wp_upload_dir(null, $create);
        if (empty($up['basedir'])) return '';
        $dir = trailingslashit($up['basedir']) . 'simple-mcp-tmp';
        if ($create && !file_exists($dir)) {
            wp_mkdir_p($dir);
            // Хардненг: закриваємо каталог від веб-доступу й лістингу
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return is_dir($dir) ? $dir : '';
    }

    /** Sideload + відповідь MCP (сумісний публічний хелпер; tmp видаляється при помилці). */
    static function sideload_and_respond($tmp, $filename, $args) {
        $r = self::sideload($tmp, $filename, $args, false);
        return isset($r['isError']) ? $r : self::ok($r);
    }

    /**
     * media_handle_sideload → wp_handle_upload (контекст 'sideload') → тема ресайзить + робить webp.
     * Повертає масив даних або MCP-помилку; $keep_on_error — не видаляти $tmp при помилці.
     */
    private static function sideload($tmp, $filename, $args, $keep_on_error) {
        $file_array = ['name' => $filename, 'tmp_name' => $tmp];
        $post_id    = intval($args['post_id'] ?? 0);
        if ($post_id && !self::can_edit_post($post_id)) {
            if (!$keep_on_error) @unlink($tmp);
            return self::err_cap('edit_post #' . $post_id . ' (прикріплення медіа до поста)');
        }
        $title  = isset($args['title']) ? sanitize_text_field((string) $args['title']) : null;
        $att_id = media_handle_sideload($file_array, $post_id, $title);
        if (is_wp_error($att_id)) {
            if (!$keep_on_error) @unlink($tmp);
            return self::err('Помилка sideload: ' . $att_id->get_error_message());
        }
        if (!empty($args['alt'])) {
            update_post_meta($att_id, '_wp_attachment_image_alt', wp_slash(sanitize_text_field((string) $args['alt'])));
        }
        $webp = get_post_meta($att_id, 'webp_url', true);
        return [
            'attachment_id' => $att_id,
            'url'           => wp_get_attachment_url($att_id),
            'webp_url'      => $webp ?: null,
            'filename'      => $filename,
        ];
    }
}
