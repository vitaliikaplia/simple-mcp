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

    /** Full registry = core tools + enabled tool-module defs. Disabled groups are hidden entirely. */
    static function registry() {
        $reg = self::core_defs();
        if (!Simple_MCP::module_on('wp_cli')) unset($reg['wp_cli']); // typed-only mode

        $modules = [
            'Simple_MCP_Tools_Blocks'   => 'blocks',
            'Simple_MCP_Tools_Wploc'    => 'wploc',
            'Simple_MCP_Tools_Translate' => 'wploc',
            'Simple_MCP_Tools_Content'  => 'content',
            'Simple_MCP_Tools_Describe' => 'content',
            'Simple_MCP_Tools_WC'       => 'wc',
            'Simple_MCP_Tools_MC'       => 'mc',
            'Simple_MCP_Tools_SEO'      => 'seo',
        ];
        foreach ($modules as $cls => $group) {
            if (!Simple_MCP::module_on($group)) continue;
            if ($group === 'wploc' && !Simple_MCP::multilingual_system()) continue; // no wp-loc/WPML → hide
            if ($group === 'wc' && !class_exists('WP_LOC_WC')) continue;            // no wp-loc-woocommerce → hide
            if ($group === 'mc' && !class_exists('WP_LOC_MC')) continue;            // no wp-loc-multicurrency → hide
            if ($group === 'seo' && !(class_exists('WP_LOC_AIOSEO') && function_exists('aioseo'))) continue; // no wp-loc-aioseo → hide
            if (class_exists($cls) && method_exists($cls, 'defs')) {
                $reg = array_merge($reg, (array) $cls::defs());
            }
        }
        return $reg;
    }

    /** Core tools shipped in this file: name → [description, inputSchema, callback] */
    static function core_defs() {
        return [
            'wp_cli' => [
                'description' => 'Run any WP-CLI command server-side (omit the leading "wp"; --path is added automatically). Returns stdout/stderr/exit_code. Destructive subcommands are deny-listed and shell metacharacters/chaining are blocked. SCOPE: this MCP is for CONTENT, options, media, taxonomies and translations — NOT code. Do NOT install/activate/update/edit plugins, themes, or files here: theme & plugin code is managed locally via git + CI/CD, so server-side code changes drift from git and are overwritten on the next deploy. For content edits prefer the typed tools (block_*, acf_*, wploc_*, create_post, upload_media) over raw wp_cli. ARGUMENT QUOTING: the command is tokenized shell-style (quotes respected) then executed without a shell (argv), so pass text values literally and quoted, e.g. post update 12 --post_title="My Title". NEVER JSON-encode a value: JSON escapes non-ASCII to \uXXXX and that raw \uXXXX text is then saved verbatim (a Cyrillic title would appear in the DB as the raw text backslash-u-0417 backslash-u-0430 ... instead of the real letters). For any write that carries human text (titles, excerpts, field values) use the typed tools update_post / create_post / acf_update instead of wp_cli.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'command' => ['type' => 'string', 'description' => 'WP-CLI command without "wp", e.g. "option get blogname" or "post list --post_type=page --format=json". Pass argument values literally and quoted; never JSON-encode text (non-ASCII gets \uXXXX-escaped and saved verbatim) — for human-text writes use the typed tools (update_post/create_post/acf_update).'],
                    ],
                    'required'   => ['command'],
                ],
                'callback' => [__CLASS__, 'tool_wp_cli'],
            ],

            'get_post' => [
                'description' => 'Read a post/page: title, status, type, slug, and raw post_content (Gutenberg block markup). To read ACF field VALUES inside blocks use block_get instead (it decodes the inline block data) — get_post returns the raw \\uXXXX-escaped markup.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required'   => ['id'],
                ],
                'callback' => [__CLASS__, 'tool_get_post'],
            ],

            'update_post' => [
                'description' => 'Update a post safely. content (FULL Gutenberg block markup) is saved with an auto-revision + wp_slash + byte-for-byte verify (content_verified), so it never corrupts block-delimiter \\uXXXX JSON. Use for full-body replacement or title/status. To change ONE ACF field inside a block, prefer block_update (targeted — no need to resend the whole body).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'content' => ['type' => 'string', 'description' => 'Full post_content (Gutenberg markup). Optional. To edit a single block field use block_update instead.'],
                        'title'   => ['type' => 'string'],
                        'status'  => ['type' => 'string', 'description' => 'publish | draft | pending | private'],
                    ],
                    'required'   => ['id'],
                ],
                'callback' => [__CLASS__, 'tool_update_post'],
            ],

            'acf_get' => [
                'description' => 'Read ACF field value(s) from POST META (also user_/term_/options). post_id is an int or an ACF selector ("option", "options_uk", "user_5", "term_10"). Omit field to get all. NOTE: does NOT read ACF fields embedded in Gutenberg blocks (those live inline in post_content) — use block_get for those.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => ['type' => ['integer', 'string']],
                        'field'   => ['type' => 'string', 'description' => 'Field name or field_key. Optional.'],
                    ],
                    'required'   => ['post_id'],
                ],
                'callback' => [__CLASS__, 'tool_acf_get'],
            ],

            'acf_update' => [
                'description' => 'Write an ACF field via native update_field() — correct for repeaters/flex/group. Works for POST fields, user_/term_, and OPTIONS pages (post_id "option", or "options_{wpml_code}" for a per-language value). CANNOT edit ACF fields inside Gutenberg blocks (their data is inline in post_content, not post meta) — use block_update for those. Note: this fills VALUES only; field DEFINITIONS (acf-json) are managed in theme code locally, not here.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => ['type' => ['integer', 'string']],
                        'field'   => ['type' => 'string'],
                        'value'   => ['description' => 'Value (string/number/array depending on the field type).'],
                    ],
                    'required'   => ['post_id', 'field', 'value'],
                ],
                'callback' => [__CLASS__, 'tool_acf_update'],
            ],

            'upload_media' => [
                'description' => 'Upload a file to the media library via media_handle_sideload — this triggers the theme pipeline (resize to max width + .webp generation). source: base64 (data field) or url. For large files (video, hi-res photos) use upload_begin/upload_chunk/upload_finish instead. Returns attachment_id, url and webp_url. For untrusted URLs prefer base64.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'source'   => ['type' => 'string', 'enum' => ['base64', 'url'], 'description' => 'default base64'],
                        'filename' => ['type' => 'string', 'description' => 'filename with extension, e.g. photo.jpg'],
                        'data'     => ['type' => 'string', 'description' => 'base64 content (for source=base64)'],
                        'url'      => ['type' => 'string', 'description' => 'file URL (for source=url)'],
                        'title'    => ['type' => 'string'],
                        'alt'      => ['type' => 'string'],
                        'post_id'  => ['type' => 'integer', 'description' => 'attach to a post (optional)'],
                    ],
                    'required'   => ['filename'],
                ],
                'callback' => [__CLASS__, 'tool_upload_media'],
            ],

            'upload_begin' => [
                'description' => 'Begin a chunked upload of a large file. Returns upload_id. Then send parts with upload_chunk and finish with upload_finish (which runs the theme resize+webp pipeline).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['filename' => ['type' => 'string']],
                    'required'   => ['filename'],
                ],
                'callback' => [__CLASS__, 'tool_upload_begin'],
            ],

            'upload_chunk' => [
                'description' => 'Append the next base64 chunk to an upload_id (from upload_begin).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'upload_id' => ['type' => 'string'],
                        'data'      => ['type' => 'string', 'description' => 'base64-частина.'],
                    ],
                    'required'   => ['upload_id', 'data'],
                ],
                'callback' => [__CLASS__, 'tool_upload_chunk'],
            ],

            'upload_finish' => [
                'description' => 'Finish a chunked upload and sideload the assembled file into the media library (theme resize+webp pipeline runs). Returns attachment_id, url, webp_url.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'upload_id' => ['type' => 'string'],
                        'title'     => ['type' => 'string'],
                        'alt'       => ['type' => 'string'],
                        'post_id'   => ['type' => 'integer'],
                    ],
                    'required'   => ['upload_id'],
                ],
                'callback' => [__CLASS__, 'tool_upload_finish'],
            ],
        ];
    }

    /** Список для tools/list (без callback) */
    static function list_public() {
        $out = [];
        foreach (self::registry() as $name => $def) {
            $out[] = [
                'name'        => $name,
                'description' => $def['description'],
                'inputSchema' => $def['inputSchema'],
            ];
        }
        return $out;
    }

    /** Виклик інструмента (tools/call) + аудит */
    static function call($name, $args) {
        $reg = self::registry();
        if (!isset($reg[$name])) {
            return new WP_Error('unknown_tool', 'Невідомий інструмент: ' . $name);
        }
        try {
            $res = call_user_func($reg[$name]['callback'], is_array($args) ? $args : []);
        } catch (\Throwable $e) {
            $res = self::err($e->getMessage());
        }
        Simple_MCP_Audit::log($name, $args, empty($res['isError']) ? 'ok' : 'error');
        return $res;
    }

    // ── Формат відповіді MCP ──────────────────────────────────────────────

    static function ok($data) {
        $text = is_string($data)
            ? $data
            : wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }
    static function err($msg) {
        return ['content' => [['type' => 'text', 'text' => (string) $msg]], 'isError' => true];
    }

    /**
     * Безпечний запис post_content: авто-ревізія (для відкату) → wp_slash (щоб не побити
     * блоковий \uXXXX JSON) → byte-for-byte verify. Спільний для update_post і block-toolset.
     * Повертає true|false (verified) або WP_Error.
     */
    static function save_post_content($post_id, $content) {
        // rollback point BEFORE mutating: a WP revision, or a meta backup if revisions are off
        if (wp_revisions_enabled(get_post($post_id))) {
            wp_save_post_revision($post_id);
        } else {
            update_post_meta($post_id, '_simple_mcp_backup', get_post($post_id)->post_content);
        }
        $r = wp_update_post(['ID' => $post_id, 'post_content' => wp_slash((string) $content)], true);
        if (is_wp_error($r)) return $r;
        clean_post_cache($post_id);
        return get_post($post_id)->post_content === (string) $content;
    }

    // ── Інструменти ───────────────────────────────────────────────────────

    static function tool_wp_cli($args) {
        if (!Simple_MCP::opt('wp_cli_enabled', true)) {
            return self::err('Інструмент wp_cli вимкнено в налаштуваннях');
        }
        $command = trim((string) ($args['command'] ?? ''));
        if ($command === '') return self::err('Порожня команда');
        $command = trim(preg_replace('/^\s*wp\s+/i', '', $command)); // прибираємо зайве "wp "

        // Токенізуємо як shell (з урахуванням лапок), але ВИКОНУЄМО без шелла (proc_open argv).
        // Це структурно унеможливлює чейнінг/сабшели й лапкові обходи deny-list.
        $tokens = self::tokenize($command);
        if ($tokens === null) {
            return self::err('Некоректна команда: заборонені шелл-метасимволи (; & | ` $() < >) або незакриті лапки.');
        }
        if (empty($tokens)) return self::err('Порожня команда');

        // Забороняємо небезпечні глобальні прапорці (code-exec / віддалене виконання)
        foreach ($tokens as $t) {
            $tl = strtolower($t);
            foreach (['--exec', '--require', '--ssh', '--http'] as $g) {
                if ($tl === $g || strpos($tl, $g . '=') === 0) {
                    return self::err('Заборонений глобальний прапорець: ' . $t);
                }
            }
        }

        // Субкоманда = провідні позиційні токени (прапорці -* пропускаємо). deny-list звіряємо по ній.
        $positional = [];
        foreach ($tokens as $t) {
            if ($t !== '' && $t[0] === '-') continue;
            $positional[] = $t;
        }
        $subcmd = strtolower(implode(' ', $positional));

        // Server ops (wp-config directives + plugin/theme install/update/delete) are environment-
        // specific changes, not content. Off by default; enable "Server ops" per-site to allow.
        // The AI must still CONFIRM destructive ones (deleting ACF/critical plugins, security/DB config).
        if (!Simple_MCP::opt('allow_server_ops', false)) {
            $ops = ['config set', 'config delete', 'config edit', 'config create', 'config shuffle-salts',
                    'plugin install', 'plugin update', 'plugin delete',
                    'theme install', 'theme update', 'theme delete'];
            foreach ($ops as $bad) {
                if (preg_match('/^' . preg_quote($bad, '/') . '(\s|$)/', $subcmd)) {
                    return self::err('Blocked: "' . $bad . '" is a server op (wp-config / plugin / theme lifecycle). Enable "Server ops" in Simple MCP settings to allow it on this site.');
                }
            }
        }

        // Configurable deny-list
        foreach ((array) Simple_MCP::opt('deny_list', []) as $bad) {
            $bad = strtolower(preg_replace('/\s+/', ' ', trim($bad)));
            if ($bad === '') continue;
            if (preg_match('/^' . preg_quote($bad, '/') . '(\s|$)/', $subcmd)) {
                return self::err('Команду заблоковано deny-list: ' . $bad);
            }
        }

        if (!function_exists('proc_open')) {
            return self::err('proc_open вимкнено на сервері — wp_cli недоступний');
        }

        // argv БЕЗ шелла + прокинуте середовище (php у PATH, бо wp — це "env php"-скрипт)
        $argv = array_merge([Simple_MCP::wp_bin()], $tokens, ['--path=' . ABSPATH, '--no-color']);
        $r    = Simple_MCP::run_shell($argv, ABSPATH, 120, Simple_MCP::cli_env());

        return self::ok([
            'command'   => $command,
            'exit_code' => $r['code'],
            'stdout'    => $r['stdout'],
            'stderr'    => $r['stderr'],
        ]);
    }

    /**
     * Мінімальний shell-подібний токенайзер: повертає argv-масив
     * або null, якщо є контрольні метасимволи (; & | ` $() < >) чи незакрита лапка.
     */
    static function tokenize($str) {
        $tokens = [];
        $cur    = '';
        $has    = false; // чи почався токен (важливо для порожніх лапок "")
        $in     = null;  // активна лапка: " або '
        $len    = strlen($str);
        $i      = 0;
        while ($i < $len) {
            $c = $str[$i];
            if ($in === '"') {
                if ($c === '\\' && $i + 1 < $len && strpos('"\\$`', $str[$i + 1]) !== false) { $cur .= $str[$i + 1]; $i += 2; continue; }
                if ($c === '"') { $in = null; $i++; continue; }
                $cur .= $c; $i++; continue;
            } elseif ($in === "'") {
                if ($c === "'") { $in = null; $i++; continue; }
                $cur .= $c; $i++; continue;
            } else {
                if ($c === '"' || $c === "'") { $in = $c; $has = true; $i++; continue; }
                if ($c === '\\' && $i + 1 < $len) { $cur .= $str[$i + 1]; $i += 2; $has = true; continue; }
                if (ctype_space($c)) { if ($has || $cur !== '') { $tokens[] = $cur; $cur = ''; $has = false; } $i++; continue; }
                if (strpos(';&|`<>', $c) !== false) return null;                    // контрольні оператори
                if ($c === '$' && $i + 1 < $len && $str[$i + 1] === '(') return null; // $(...)
                $cur .= $c; $has = true; $i++; continue;
            }
        }
        if ($in !== null) return null; // незакрита лапка
        if ($has || $cur !== '') $tokens[] = $cur;
        return $tokens;
    }

    static function tool_get_post($args) {
        $id = intval($args['id'] ?? 0);
        $p  = $id ? get_post($id) : null;
        if (!$p) return self::err('Пост не знайдено');
        return self::ok([
            'id'       => $p->ID,
            'title'    => $p->post_title,
            'status'   => $p->post_status,
            'type'     => $p->post_type,
            'slug'     => $p->post_name,
            'modified' => $p->post_modified,
            'content'  => $p->post_content,
        ]);
    }

    static function tool_update_post($args) {
        $id = intval($args['id'] ?? 0);
        if (!$id || !get_post($id)) return self::err('Пост не знайдено');

        $postarr = ['ID' => $id];
        if (isset($args['title']))  $postarr['post_title']  = wp_slash(sanitize_text_field((string) $args['title']));
        if (isset($args['status'])) $postarr['post_status'] = sanitize_key((string) $args['status']);
        $has_content = array_key_exists('content', $args);
        if ($has_content) {
            // КЛЮЧОВЕ: wp_slash, бо wp_update_post усередині робить wp_unslash і побив би \uXXXX / блокові делімітери.
            $postarr['post_content'] = wp_slash((string) $args['content']);
            wp_save_post_revision($id); // знімок ДО правки — вбудований відкат
        }

        $r = wp_update_post($postarr, true);
        if (is_wp_error($r)) return self::err('Не вдалося оновити: ' . $r->get_error_message());

        $verified = null;
        if ($has_content) {
            clean_post_cache($id);
            $saved    = get_post($id)->post_content;
            $verified = ($saved === (string) $args['content']); // round-trip перевірка байт-у-байт
        }
        return self::ok(['id' => $id, 'updated' => true, 'content_verified' => $verified]);
    }

    static function tool_acf_get($args) {
        if (!function_exists('get_field')) return self::err('ACF не активний');
        $post_id = $args['post_id'] ?? 0; // може бути int або "option"/"user_X"/"term_X"
        $field   = isset($args['field']) ? (string) $args['field'] : '';
        if ($field !== '') {
            return self::ok(['field' => $field, 'value' => get_field($field, $post_id)]);
        }
        $all = function_exists('get_field_objects') ? get_field_objects($post_id) : null;
        $out = [];
        if (is_array($all)) {
            foreach ($all as $k => $o) $out[$k] = $o['value'] ?? null;
        }
        return self::ok(['fields' => $out]);
    }

    static function tool_acf_update($args) {
        if (!function_exists('update_field')) return self::err('ACF не активний');
        $post_id = $args['post_id'] ?? 0;
        $field   = (string) ($args['field'] ?? '');
        if ($field === '') return self::err("Потрібне поле (ім'я або field_key)");
        if (!array_key_exists('value', $args)) return self::err('Потрібне value');
        $ok = update_field($field, $args['value'], $post_id);
        return self::ok(['field' => $field, 'updated' => (bool) $ok, 'value' => get_field($field, $post_id)]);
    }

    static function tool_upload_media($args) {
        self::ensure_media_includes();
        $filename = sanitize_file_name((string) ($args['filename'] ?? ''));
        if ($filename === '') return self::err('Потрібне filename');
        $source = $args['source'] ?? 'base64';
        $tmp    = wp_tempnam($filename);

        if ($source === 'base64') {
            $data = base64_decode((string) ($args['data'] ?? ''), true);
            if ($data === false) { @unlink($tmp); return self::err('Некоректний base64'); }
            file_put_contents($tmp, $data);
        } elseif ($source === 'url') {
            $url = esc_url_raw((string) ($args['url'] ?? ''));
            if (!$url) { @unlink($tmp); return self::err('Потрібен url'); }
            if (!Simple_MCP::url_is_safe($url)) { @unlink($tmp); return self::err('URL заблоковано (приватний/зарезервований хост)'); }
            $dl = download_url($url, 60);
            if (is_wp_error($dl)) { @unlink($tmp); return self::err('Не вдалося завантажити: ' . $dl->get_error_message()); }
            @copy($dl, $tmp);
            @unlink($dl);
        } else {
            @unlink($tmp);
            return self::err('source має бути base64 або url');
        }

        return self::sideload_and_respond($tmp, $filename, $args);
    }

    static function tool_upload_begin($args) {
        $filename = sanitize_file_name((string) ($args['filename'] ?? ''));
        if ($filename === '') return self::err('Потрібне filename');
        $dir = self::tmp_dir();
        if (!$dir) return self::err('Не вдалося створити тимчасовий каталог');
        $id   = wp_generate_password(24, false, false);
        $path = $dir . '/' . $id . '.part';
        file_put_contents($path, '');
        set_transient('simple_mcp_up_' . $id, ['filename' => $filename, 'path' => $path], HOUR_IN_SECONDS);
        return self::ok(['upload_id' => $id, 'filename' => $filename]);
    }

    static function tool_upload_chunk($args) {
        $id   = (string) ($args['upload_id'] ?? '');
        $meta = $id ? get_transient('simple_mcp_up_' . $id) : false;
        if (!$meta || empty($meta['path']) || !file_exists($meta['path'])) return self::err('Невідомий upload_id');
        $data = base64_decode((string) ($args['data'] ?? ''), true);
        if ($data === false) return self::err('Некоректний base64');
        if ((filesize($meta['path']) + strlen($data)) > self::MAX_UPLOAD_BYTES) {
            @unlink($meta['path']);
            delete_transient('simple_mcp_up_' . $id);
            return self::err('Перевищено ліміт розміру завантаження (1 GB)');
        }
        file_put_contents($meta['path'], $data, FILE_APPEND | LOCK_EX);
        return self::ok(['upload_id' => $id, 'bytes' => filesize($meta['path'])]);
    }

    static function tool_upload_finish($args) {
        self::ensure_media_includes();
        $id   = (string) ($args['upload_id'] ?? '');
        $meta = $id ? get_transient('simple_mcp_up_' . $id) : false;
        if (!$meta || empty($meta['path']) || !file_exists($meta['path'])) return self::err('Невідомий upload_id');

        // media_handle_sideload видаляє tmp_name — тому копіюємо у wp_tempnam
        $tmp = wp_tempnam($meta['filename']);
        copy($meta['path'], $tmp);
        @unlink($meta['path']);
        delete_transient('simple_mcp_up_' . $id);

        return self::sideload_and_respond($tmp, $meta['filename'], $args);
    }

    // ── Хелпери медіа ─────────────────────────────────────────────────────

    static function ensure_media_includes() {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    static function tmp_dir() {
        $up  = wp_upload_dir();
        $dir = trailingslashit($up['basedir']) . 'simple-mcp-tmp';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            // Хардненг: закриваємо каталог від веб-доступу й лістингу
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return is_dir($dir) ? $dir : '';
    }

    /** Максимальний накопичений розмір частинкового завантаження (захист від disk-DoS) */
    const MAX_UPLOAD_BYTES = 1073741824; // 1 GB

    static function sideload_and_respond($tmp, $filename, $args) {
        $file_array = ['name' => $filename, 'tmp_name' => $tmp];
        $post_id    = intval($args['post_id'] ?? 0);
        $title      = isset($args['title']) ? sanitize_text_field((string) $args['title']) : null;

        // media_handle_sideload → wp_handle_upload (з контекстом 'sideload') → тема ресайзить + робить webp
        $att_id = media_handle_sideload($file_array, $post_id, $title);
        if (is_wp_error($att_id)) {
            @unlink($tmp);
            return self::err('Помилка sideload: ' . $att_id->get_error_message());
        }
        if (!empty($args['alt'])) {
            update_post_meta($att_id, '_wp_attachment_image_alt', sanitize_text_field((string) $args['alt']));
        }
        $webp = get_post_meta($att_id, 'webp_url', true);
        return self::ok([
            'attachment_id' => $att_id,
            'url'           => wp_get_attachment_url($att_id),
            'webp_url'      => $webp ?: null,
            'filename'      => $filename,
        ]);
    }
}
