<?php
/**
 * wp_cli — прямий WP-CLI для ролей з manage_options (на мультисайті — лише супер-адміни;
 * god-mode, окремий привілейований процес).
 * Токенізація shell-подібна (лапки), виконання БЕЗ шелла (proc_open argv). Гейт (gate()) звіряє
 * НОРМАЛІЗОВАНИЙ шлях команди — так, як його розбирає WP-CLI 2.12 (Configurator::extract_assoc,
 * Runner::back_compat_conversions, @alias підкоманд у CompositeCommand::find_subcommand), тож
 * синоніми (plugin upgrade, theme uninstall, sql, db dump…) не обходять deny-list і «Server ops».
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_CLI {

    /** Таймаут процесу, секунд. */
    const TIMEOUT = 120;

    /** Ліміт захопленого виводу на потік (stdout/stderr), байтів; решта дочитується й відкидається. */
    const MAX_OUTPUT = 1048576;

    /** Глобальні прапорці WP-CLI, які приймаємо лише від самого плагіна (code-exec / віддалене виконання / stdin). */
    const BLOCKED_FLAGS = ['path', 'exec', 'require', 'ssh', 'http', 'prompt'];

    /** Мультисайт: прапорці, що перемикають сайт мережі, — лише для супер-адмінів (blog — застарілий синонім url). */
    const NETWORK_FLAGS = ['url', 'blog', 'network'];

    /** Застарілі імена кореневих команд (Runner::back_compat_conversions). */
    const TOP_ALIASES = ['sql' => 'db', 'blog' => 'site'];

    /** Синоніми підкоманд WP-CLI 2.12 (@alias у докблоках) → канонічне ім'я; ключ — батьківський шлях. */
    const SUB_ALIASES = [
        'core'         => ['upgrade' => 'update', 'install-network' => 'multisite-convert'],
        'plugin'       => ['upgrade' => 'update'],
        'theme'        => ['upgrade' => 'update', 'uninstall' => 'delete'],
        'db'           => ['connect' => 'cli', 'dump' => 'export'],
        'option'       => ['set' => 'update'],
        'site option'  => ['set' => 'update'],
        'post meta'    => ['set' => 'update'],
        'comment meta' => ['set' => 'update'],
        'term meta'    => ['set' => 'update'],
        'user meta'    => ['set' => 'update'],
        'site meta'    => ['set' => 'update'],
        'network meta' => ['set' => 'update'],
        'scaffold'     => ['cpt' => 'post-type', 'tax' => 'taxonomy', '_s' => 'underscores'],
    ];

    /**
     * Родини «Server ops» (життєвий цикл коду/середовища). Без дозволу server_ops — ЛИШЕ ці
     * read-only підкоманди (allow-list; усе інше в родині, включно з майбутніми підкомандами, заблоковане).
     * Порожній список: простір імен без read-only підкоманд (сам по собі лише друкує довідку);
     * null: це не простір імен, а команда, що одразу виконується (server) — без server_ops недоступна.
     */
    const LIFECYCLE_READONLY = [
        'plugin'           => ['list', 'get', 'status', 'is-installed', 'is-active', 'path', 'search', 'verify-checksums', 'auto-updates status'],
        'theme'            => ['list', 'get', 'status', 'is-installed', 'is-active', 'path', 'search', 'mod get', 'mod list', 'auto-updates status'],
        'core'             => ['version', 'is-installed', 'check-update', 'verify-checksums'],
        'language'         => ['core list', 'core is-installed', 'plugin list', 'plugin is-installed', 'theme list', 'theme is-installed'],
        'config'           => ['get', 'has', 'is-true', 'list', 'path'],
        'maintenance-mode' => ['status', 'is-active'],
        'scaffold'         => [],
        'i18n'             => [],
        'server'           => null,
    ];

    /** Родина cli: дозволені лише ці підкоманди (alias/update/cache/… змінюють сам WP-CLI). */
    const CLI_ALLOWED = ['version', 'info'];

    /**
     * Визначення інструмента wp_cli для реєстру (Simple_MCP_Tools::core_defs). Опис тримаємо в межах
     * ~2000 символів (Claude Code обрізає довші описи до 2048): найважливіші застереження — першими,
     * перелік read-only підкоманд Server ops — у відмові гейта, а не тут.
     */
    static function def() {
        $ops_on = class_exists('Simple_MCP_Auth') && Simple_MCP_Auth::perm('server_ops');
        $deny   = [];
        foreach ((array) Simple_MCP::opt('deny_list', []) as $d) {
            if (is_scalar($d) && trim((string) $d) !== '') $deny[] = trim((string) $d);
        }
        $deny_txt = $deny ? implode(', ', $deny) : 'empty';
        if (strlen($deny_txt) > 120) { // довгий список не витісняє решту опису; відмова називає збіг
            $deny_txt = Simple_MCP_Tools::mb_cut($deny_txt, 120) . '… (' . count($deny) . ' prefixes)';
        }

        return [
                'title'       => 'WP-CLI',
                'description' => 'Run a WP-CLI command on this site (omit the leading "wp"). PREFER the typed tools (block_*, acf_*, update_post, …): wp_cli is a separate privileged process that does NOT inherit the authenticated user\'s object-level capability checks (manage_options roles; on multisite super admins). '
                    . 'SCOPE: content, options, media, taxonomies, translations — never edit PHP/JS/CSS or other theme/plugin source files (they ship via git). '
                    . 'QUOTING: pass values literally and quoted, e.g. post update 12 --post_title="My Title". NEVER JSON-encode a value: non-ASCII becomes \uXXXX and that text is saved verbatim (a Cyrillic title would be stored as literal backslash-u escapes); for writes carrying human text use update_post / create_post / acf_update. '
                    . 'EXECUTION: tokenized shell-style, run WITHOUT a shell (; & | ` $( < > and unclosed quotes are refused); --path, --no-color, --skip-packages added (multisite: --url of this site unless given); stdin /dev/null; ' . self::TIMEOUT . ' s timeout; isolated per-site WP-CLI HOME (no global config, no packages). '
                    . 'RESULT: exit_code, timed_out, stdout, stderr (a stream over 1 MB is cut: *_truncated, *_bytes); isError when exit_code != 0 or timed out. '
                    . 'ALWAYS REJECTED: @aliases; the cli family except cli version/info; package; the flags --path --exec --require --ssh --http --prompt; on multisite --url/--blog/--network for non-super-admins; this site\'s deny-list prefixes (' . $deny_txt . '). Synonyms are normalized first (plugin upgrade = plugin update, sql = db, db dump = db export, …). '
                    . 'SERVER OPS (' . ($ops_on ? 'ENABLED' : 'DISABLED') . ' for this key; always off while DISALLOW_FILE_MODS is true): without them the families ' . implode(', ', array_keys(self::LIFECYCLE_READONLY)) . ' allow only read-only subcommands (list, get, status, …; a refusal lists them). '
                    . ($ops_on ? 'Confirm destructive ones (deleting ACF or another critical plugin, security/DB config) with the user first.' : 'Ask the site admin to grant "Server ops" if one is genuinely needed.'),
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'command' => ['type' => 'string', 'description' => 'WP-CLI command without "wp", e.g. "option get blogname" or "post list --post_type=page --format=json". Pass argument values literally and quoted; never JSON-encode text (non-ASCII gets \uXXXX-escaped and saved verbatim) — for human-text writes use the typed tools (update_post/create_post/acf_update).'],
                    ],
                    'required'             => ['command'],
                ],
                'annotations' => [
                    'readOnlyHint'    => false,
                    'destructiveHint' => true,
                    'idempotentHint'  => false,
                    'openWorldHint'   => false,
                ],
                'callback' => [__CLASS__, 'tool_wp_cli'],
            ];
    }

    static function tool_wp_cli($args) {
        if (!Simple_MCP_Auth::perm('wp_cli')) {
            return Simple_MCP_Tools::err('Інструмент wp_cli недоступний для ролі цього користувача');
        }
        $command = trim((string) ($args['command'] ?? ''));
        if ($command === '') return Simple_MCP_Tools::err('Порожня команда');
        $command = trim(preg_replace('/^\s*wp\s+/i', '', $command)); // прибираємо зайве "wp "

        // Токенізуємо як shell (з урахуванням лапок), але ВИКОНУЄМО без шелла (proc_open argv).
        // Це структурно унеможливлює чейнінг/сабшели й лапкові обходи deny-list.
        $tokens = self::tokenize($command);
        if ($tokens === null) {
            return Simple_MCP_Tools::err('Некоректна команда: заборонені шелл-метасимволи (; & | ` $() < >) або незакриті лапки.');
        }
        if (empty($tokens)) return Simple_MCP_Tools::err('Порожня команда');

        $multisite = is_multisite();
        $why = self::gate($tokens, [
            'server_ops'  => Simple_MCP_Auth::perm('server_ops'),
            'multisite'   => $multisite,
            'super_admin' => $multisite && is_super_admin(),
            'deny'        => (array) Simple_MCP::opt('deny_list', []),
        ]);
        if ($why !== null) return Simple_MCP_Tools::err($why);

        if (!function_exists('proc_open')) {
            return Simple_MCP_Tools::err('proc_open вимкнено на сервері — wp_cli недоступний');
        }

        // Хвіст від плагіна: сайт, без кольорів, без пакетів WP-CLI; на мультисайті — поточний сайт мережі
        // (без --url WP-CLI працював би з головним сайтом, хоч виклик прийшов на ендпоінт підсайту).
        $tail = ['--path=' . ABSPATH, '--no-color', '--skip-packages'];
        $p = self::parse($tokens);
        if ($multisite && !isset($p['flags']['url']) && !isset($p['flags']['blog'])) {
            $tail[] = '--url=' . get_site_url();
        }

        $argv = Simple_MCP::wp_cli_argv(array_merge($tokens, $tail));
        if (is_wp_error($argv)) return Simple_MCP_Tools::err($argv->get_error_message());
        $env = Simple_MCP::cli_env();
        if (is_wp_error($env)) return Simple_MCP_Tools::err($env->get_error_message());

        $r = Simple_MCP::run_shell($argv, ABSPATH, self::TIMEOUT, $env, self::MAX_OUTPUT);

        $out = [
            'command'   => $command,
            'exit_code' => (int) $r['code'],
            'timed_out' => !empty($r['timed_out']),
            // обрізаний посеред символу/бінарний вивід → валідний UTF-8, інакше JSON-кодування впаде
            'stdout'    => self::utf8((string) $r['stdout']),
            'stderr'    => self::utf8((string) $r['stderr']),
        ];
        foreach (['stdout', 'stderr'] as $s) {
            if (!empty($r[$s . '_truncated'])) {
                $out[$s . '_truncated'] = true;
                $out[$s . '_bytes']     = (int) $r[$s . '_bytes'];
            }
        }
        $res = Simple_MCP_Tools::ok($out);
        // Ненульовий код або таймаут = помилка (і в аудиті 'error'), але вивід повертаємо повністю.
        if ($out['exit_code'] !== 0 || $out['timed_out']) $res['isError'] = true;
        return $res;
    }

    /**
     * Валідний UTF-8: невалідні послідовності (обріз посеред символу, бінарний вивід) замінюються.
     * Не wp_check_invalid_utf8($s, true): до WP 6.9 вона робить iconv без //IGNORE і на такому
     * вводі повертає false — увесь потік зник би.
     */
    static function utf8($s) {
        $s = (string) $s;
        if ($s === '' || preg_match('//u', $s)) return $s;
        if (function_exists('wp_scrub_utf8')) return wp_scrub_utf8($s);
        if (function_exists('mb_scrub')) return mb_scrub($s, 'UTF-8');
        $r = function_exists('iconv') ? @iconv('UTF-8', 'UTF-8//IGNORE', $s) : false;
        return is_string($r) ? $r : (string) preg_replace('/[\x80-\xFF]/', '?', $s);
    }

    /**
     * Гейт команди (чиста функція — без WP, тестується окремо).
     * $ctx: server_ops (bool), multisite (bool), super_admin (bool), deny (string[]).
     * Повертає null (дозволено) або текст відмови.
     */
    static function gate(array $tokens, array $ctx) {
        $ctx += ['server_ops' => false, 'multisite' => false, 'super_admin' => false, 'deny' => []];
        $p = self::parse($tokens);

        // @alias: WP-CLI бере alias з першого аргументу (Runner::init_config) і застосовує конфіг alias
        // (ssh/url/path) — відхиляємо будь-яке "@…" на місці команди.
        if (isset($p['pos'][0]) && strpos($p['pos'][0], '@') === 0) {
            return 'Заблоковано: @alias ("' . $p['pos'][0] . '") у wp_cli не підтримується — команда виконується лише на цьому сайті.';
        }

        foreach ($p['flags'] as $name => $raw) {
            $n = strtolower(trim($name));
            if (strpos($n, 'no-') === 0) $n = substr($n, 3);
            if (in_array($n, self::BLOCKED_FLAGS, true)) {
                return 'Заборонений глобальний прапорець: ' . $raw . ' (--path/--exec/--require/--ssh/--http/--prompt задає лише плагін).';
            }
            if ($ctx['multisite'] && !$ctx['super_admin'] && in_array($n, self::NETWORK_FLAGS, true)) {
                return 'Заборонено: ' . $raw . ' на мультисайті доступний лише супер-адміністраторам мережі.';
            }
        }

        $path   = self::normalize($p['pos'], $p['flags']);
        $family = $path[0] ?? '';
        $sub    = implode(' ', $path);

        if ($family === 'package') {
            return 'Заблоковано: родина "package" (встановлення пакетів WP-CLI = довільний код) у wp_cli недоступна.';
        }
        if ($family === 'cli' && !(isset($path[1]) && in_array($path[1], self::CLI_ALLOWED, true))) {
            return 'Заблоковано: з родини "cli" дозволені лише "cli version" і "cli info" (alias/update/cache змінюють сам WP-CLI).';
        }

        $allowed = array_key_exists($family, self::LIFECYCLE_READONLY) ? self::LIFECYCLE_READONLY[$family] : [];
        if (!$ctx['server_ops'] && array_key_exists($family, self::LIFECYCLE_READONLY)
            && ($allowed === null || !self::readonly_ok(array_slice($path, 1), $allowed))) {
            return 'Заблоковано: "' . $sub . '" — це server op (життєвий цикл ' . $family . '). Без дозволу «Server ops» у родині "' . $family . '" доступні лише read-only підкоманди: '
                . ($allowed ? implode(', ', $allowed) : 'жодної') . '. Увімкни «Server ops» для ролі в налаштуваннях Simple MCP (і підтвердь руйнівні дії з користувачем).';
        }

        // Deny-list: обидві сторони розбираємо й нормалізуємо однаково ("db dump" у списку = "db export";
        // лапки як у команді; зайве "wp " і прапорці в рядку списку ігноруються — звіряється шлях команди).
        foreach ((array) $ctx['deny'] as $bad) {
            if (!is_scalar($bad)) continue;
            $bt    = self::tokenize(trim((string) $bad));
            $words = $bt === null ? preg_split('/\s+/', trim((string) $bad), -1, PREG_SPLIT_NO_EMPTY) : self::parse($bt)['pos'];
            if ($words && strtolower($words[0]) === 'wp') array_shift($words);
            if (!$words) continue;
            $badn = implode(' ', self::normalize($words, []));
            if ($badn === '') continue;
            if ($sub === $badn || strpos($sub, $badn . ' ') === 0) {
                return 'Команду заблоковано deny-list: ' . trim((string) $bad);
            }
        }
        return null;
    }

    /**
     * Розбір аргументів так, як Configurator::extract_assoc: токен "--x…" (після -- хоч один символ,
     * не '=') — прапорець, решта (включно з "-x" та "--") — позиційні. flags: ім'я → сирий токен.
     */
    static function parse(array $tokens) {
        $pos = [];
        $flags = [];
        foreach ($tokens as $t) {
            $t = (string) $t;
            if (preg_match('/^--[^=]/', $t)) {
                $name = substr($t, 2);
                $eq = strpos($name, '=');
                if ($eq !== false) $name = substr($name, 0, $eq);
                elseif (strpos($name, 'no-') === 0 && strlen($name) > 3) $name = substr($name, 3); // --no-x → x=false
                if (!isset($flags[$name])) $flags[$name] = $t;
            } else {
                $pos[] = $t;
            }
        }
        return ['pos' => $pos, 'flags' => $flags];
    }

    /**
     * Канонічний шлях команди (lowercase-слова): дзеркало Runner::back_compat_conversions і
     * @alias-резолву підкоманд. Регістр: WP-CLI розрізняє його, тож нижній регістр лише суворіший.
     * Прапорці help/version/info звіряємо ТОЧНО (як isset($assoc_args[...]) у WP-CLI): "--HELP"
     * для WP-CLI — звичайний аргумент команди, а не довідка.
     */
    static function normalize(array $pos, array $flags) {
        $a = array_values(array_map('strtolower', array_map('strval', $pos)));

        if ($a && isset(self::TOP_ALIASES[$a[0]])) $a[0] = self::TOP_ALIASES[$a[0]];
        if ($a && preg_match('/(post|comment|user|network)-meta/', $a[0], $m)) { // *-meta → * meta (як у WP-CLI, без якорів)
            array_splice($a, 0, 1, [$m[1], 'meta']);
        }
        $two = implode(' ', array_slice($a, 0, 2));
        $map = [
            'core config'     => ['config', 'create'],
            'core language'   => ['language', 'core'],
            'checksum core'   => ['core', 'verify-checksums'],
            'checksum plugin' => ['plugin', 'verify-checksums'],
            'plugin scaffold' => ['scaffold', 'plugin'],
        ];
        if ($two === 'cli aliases') {
            array_splice($a, 0, 3, ['cli', 'alias', 'list']);
        } elseif (isset($map[$two])) {
            array_splice($a, 0, 2, $map[$two]);
        } elseif (count($a) > 1 && in_array($a[0], ['plugin', 'theme'], true) && $a[1] === 'update-all') {
            $a[1] = 'update';
        } elseif (count($a) > 1 && $a[0] === 'transient' && in_array($a[1], ['delete-expired', 'delete-all'], true)) {
            $a[1] = 'delete';
        }
        if (array_key_exists('help', $flags)) {
            array_unshift($a, 'help');
        } elseif (!$a) {
            foreach (['version', 'info'] as $k) {
                if (array_key_exists($k, $flags)) { $a = ['cli', $k]; break; }
            }
        }

        // @alias підкоманд: на кожному рівні, як CompositeCommand::find_subcommand
        for ($i = 1, $n = count($a); $i < $n; $i++) {
            $parent = implode(' ', array_slice($a, 0, $i));
            if (isset(self::SUB_ALIASES[$parent][$a[$i]])) $a[$i] = self::SUB_ALIASES[$parent][$a[$i]];
        }
        return $a;
    }

    /**
     * Чи підшлях (без родини) read-only: починається з дозволеної підкоманди, або це сам
     * простір імен / його префікс (WP-CLI тоді лише друкує довідку).
     */
    static function readonly_ok(array $rest, array $allowed) {
        if (!$rest) return true;
        foreach ($allowed as $entry) {
            $e = explode(' ', $entry);
            $k = min(count($e), count($rest)); // rest починається з entry, або rest — префікс entry
            if (array_slice($rest, 0, $k) === array_slice($e, 0, $k)) return true;
        }
        return false;
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
}
