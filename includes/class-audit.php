<?php
/**
 * Аудит-лог: виклики інструментів, відмови автентифікації, генерація/відкликання ключів.
 *
 * Аргументи пишуться відредагованими (секрети, base64, токени) і обрізаються UTF-8-безпечно
 * до ARGS_MAX байтів. created_at — локальний час сайту (current_time('mysql')), тож і
 * ретенція рахується в локальному часі. Невдалий INSERT не губиться мовчки: пробуємо
 * спрощений рядок і пишемо в error_log.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Audit {

    const ARGS_MAX          = 2000;
    const DETAIL_MAX        = 1000;
    const RETENTION_DEFAULT = 30;

    static function table() {
        global $wpdb;
        return $wpdb->prefix . 'simple_mcp_log';
    }

    /** SQL схеми для dbDelta (нові колонки додаються при зміні версії плагіна) */
    static function schema() {
        global $wpdb;
        $t       = self::table();
        $charset = $wpdb->get_charset_collate();
        // Типи в стилі ядра (bigint(20) unsigned): інакше dbDelta на MariaDB/MySQL < 8.0.17
        // щоразу «змінює» тип id/user_id зайвим ALTER.
        return "CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            ip varchar(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_login varchar(60) NOT NULL DEFAULT '',
            key_id varchar(32) NOT NULL DEFAULT '',
            tool varchar(100) NOT NULL DEFAULT '',
            args text NULL,
            status varchar(20) NOT NULL DEFAULT '',
            duration_ms int(10) unsigned NULL,
            detail text NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) $charset;";
    }

    /** Колонки, додані в 2.5.0 (визначення — як у schema()). */
    const ADDED_COLUMNS = [
        'key_id'      => "key_id varchar(32) NOT NULL DEFAULT ''",
        'duration_ms' => 'duration_ms int(10) unsigned NULL',
        'detail'      => 'detail text NULL',
    ];

    static function create_table() {
        global $wpdb;
        $t = self::table();
        // Відсутні колонки додаємо ОДНИМ ALTER: dbDelta робить окремий ALTER на кожну, а на MySQL < 8.0 /
        // MariaDB < 10.3 кожен — повна перебудова таблиці. Далі dbDelta лише звіряє решту схеми.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t) {
            $have = array_map('strtolower', (array) $wpdb->get_col("SHOW COLUMNS FROM `$t`"));
            $add  = [];
            foreach (self::ADDED_COLUMNS as $col => $ddl) {
                if ($have && !in_array($col, $have, true)) $add[] = 'ADD COLUMN ' . $ddl;
            }
            if ($add) $wpdb->query("ALTER TABLE `$t` " . implode(', ', $add));
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::schema());
    }

    /**
     * Записати рядок аудиту. $extra: duration_ms (int), detail (string), а також
     * user_id (перевизначити користувача, напр. 0 для відмов автентифікації) і key_id
     * (за замовчуванням — ключ поточного MCP-запиту). Повертає true, якщо рядок записано.
     */
    static function log($tool, $args, $status, $extra = []) {
        global $wpdb;
        $extra = is_array($extra) ? $extra : [];

        if (array_key_exists('user_id', $extra)) {
            $uid   = (int) $extra['user_id'];
            $u     = $uid ? get_userdata($uid) : null;
            $login = $u ? (string) $u->user_login : '';
        } else {
            $u     = wp_get_current_user();
            $uid   = $u ? (int) $u->ID : 0;
            $login = $u ? (string) $u->user_login : '';
        }
        $key_id = array_key_exists('key_id', $extra)
            ? (string) $extra['key_id']
            : (class_exists('Simple_MCP_Auth') ? Simple_MCP_Auth::current_key_id() : '');

        $tool = (string) $tool;
        $row = [
            'created_at' => current_time('mysql'),
            'ip'         => self::cut(class_exists('Simple_MCP_Auth') ? Simple_MCP_Auth::client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 64),
            'user_id'    => $uid,
            'user_login' => self::cut($login, 60),
            'tool'       => self::cut($tool, 100),
            'args'       => self::encode_args($args, $tool),
            'status'     => self::cut((string) $status, 20),
        ];
        $new = [
            'key_id'      => self::cut($key_id, 32),
            'duration_ms' => isset($extra['duration_ms']) ? max(0, (int) $extra['duration_ms']) : null,
            'detail'      => (isset($extra['detail']) && (string) $extra['detail'] !== '')
                ? self::cut(self::redact_text((string) $extra['detail']), self::DETAIL_MAX)
                : null,
        ];

        // Помилки БД тут не друкує wpdb (його error_log містив би весь INSERT з аргументами) —
        // про невдачу пишемо самі, коротко.
        $suppress = $wpdb->suppress_errors(true);
        try {
            if ($wpdb->insert(self::table(), $row + $new) !== false) return true;

            // Запасні спроби: нові колонки можуть ще не існувати (схему оновлює dbDelta при зміні
            // версії), а таблиця з вужчим charset може не прийняти символи — тоді ASCII-JSON.
            $first_error = (string) $wpdb->last_error;
            $old_schema  = stripos($first_error, 'Unknown column') !== false;
            $ascii       = self::encode_args($args, $tool, true);
            $base        = $old_schema ? $row : $row + $new;
            $attempts    = $old_schema ? [$row] : [];
            $attempts[]  = array_merge($base, ['args' => $ascii]);
            $attempts[]  = array_merge($row, ['args' => '[unloggable]']);
            foreach ($attempts as $data) {
                if ($wpdb->insert(self::table(), $data) !== false) {
                    if (!$old_schema) {
                        error_log('[simple-mcp] audit row stored in reduced form: ' . $first_error);
                    }
                    return true;
                }
            }
            error_log('[simple-mcp] audit insert failed (' . $tool . '/' . $status . '): ' . ($first_error !== '' ? $first_error : $wpdb->last_error));
            return false;
        } finally {
            $wpdb->suppress_errors($suppress);
        }
    }

    /** JSON аргументів після редагування секретів, UTF-8-безпечно обрізаний до ARGS_MAX байтів */
    static function encode_args($args, $tool = '', $ascii = false) {
        $flags = JSON_UNESCAPED_SLASHES | ($ascii ? 0 : JSON_UNESCAPED_UNICODE);
        $json  = wp_json_encode(self::redact($args, (string) $tool), $flags);
        if (!is_string($json)) return '';
        if (strlen($json) > self::ARGS_MAX) {
            $json = self::cut($json, self::ARGS_MAX) . '…'; // не роздуваємо лог великими значеннями
        }
        return $json;
    }

    /** UTF-8-безпечне обрізання до $bytes байтів (контракт ядра, із запасним варіантом) */
    static function cut($s, $bytes) {
        $s = (string) $s;
        if (strlen($s) <= $bytes) return $s;
        if (class_exists('Simple_MCP_Tools') && method_exists('Simple_MCP_Tools', 'mb_cut')) {
            return Simple_MCP_Tools::mb_cut($s, $bytes);
        }
        return function_exists('mb_strcut') ? mb_strcut($s, 0, $bytes, 'UTF-8') : wp_check_invalid_utf8(substr($s, 0, $bytes), true);
    }

    // ── Редагування секретів ──────────────────────────────────────────────

    /** Чи схожа назва ключа/прапорця/константи на секрет (password, *_KEY, *_SALT, token, auth…) */
    static function secret_name($name) {
        return (bool) preg_match('/pass|secret|token|salt|credential|private|api[_-]?key|(^|[_-])(auth|nonce|pwd)([_-]|$)|[_-]key$/i', (string) $name);
    }

    /**
     * Рекурсивно: значення секретних ключів → '[redacted]'; base64 у 'data' → '<base64 N chars>';
     * команда wp_cli — див. redact_command; URL у 'url' — без логіна/пароля й query-рядка;
     * токени smcp-… і логін:пароль@ в URL — у будь-якому рядку.
     */
    static function redact($value, $tool = '', $key = '') {
        if (is_object($value)) $value = get_object_vars($value);
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && self::secret_name($k) && !is_array($v) && !is_object($v)) {
                    $out[$k] = '[redacted]';
                    continue;
                }
                $out[$k] = self::redact($v, $tool, is_string($k) ? $k : $key);
            }
            return $out;
        }
        if (!is_string($value)) return $value;
        if ($key === 'data' && strlen($value) > 64
            && preg_match('~^(data:[\w.+/-]+(;[\w=.+-]+)*,)?[A-Za-z0-9+/=_\-\r\n]+$~', $value)) {
            return '<base64 ' . strlen($value) . ' chars>';
        }
        if ($tool === 'wp_cli' && $key === 'command') {
            $value = self::redact_command($value);
        }
        if ($key === 'url') {
            // підписані посилання (X-Amz-Signature, token=…) — секрет у query-рядку
            $value = (string) preg_replace('~^([a-z][a-z0-9+.-]*://[^?#\s]*)[?#]\S*$~i', '$1?[redacted]', $value);
        }
        return self::redact_text($value);
    }

    /** Персональні ключі, bearer-токени та логін:пароль@ в URL у вільному тексті */
    static function redact_text($s) {
        $s = preg_replace('/\b(' . (class_exists('Simple_MCP_Auth') ? Simple_MCP_Auth::KEY_PREFIX : 'smcp') . '-\d+-)[A-Za-z0-9]{8,}/', '$1[redacted]', (string) $s);
        $s = preg_replace('~\b([a-z][a-z0-9+.-]*://)[^/\s@:]+:[^/\s@]*@~i', '$1[redacted]@', (string) $s);
        return (string) preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i', '$1[redacted]', (string) $s);
    }

    /**
     * Секрети в команді wp_cli: значення прапорців із секретною назвою (--user_pass=V і --user_pass V,
     * --*token*…) і позиційне значення для секретних назв (DB_PASSWORD, *_KEY, smtp_password, api_token…):
     * `config|option add|set|update <name> <value>`, `site|network option …`, `<post|user|term|comment|site|network>
     * meta add|set|update <id> <key> <value>` і `… patch insert|update <name|id key> <path…> <value>`.
     * Значення-JSON (--format=json) редагується всередині.
     */
    static function redact_command($cmd) {
        $cmd = (string) $cmd;
        $cmd = (string) preg_replace_callback(
            '~(--([A-Za-z0-9_-]+)=)("(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S+)~',
            function ($m) {
                return self::secret_name($m[2]) ? $m[1] . '[redacted]' : $m[0];
            },
            $cmd
        );
        $tokens = function ($c) {
            return preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|\S+~', $c, $mm, PREG_OFFSET_CAPTURE) ? $mm[0] : [];
        };

        // позиційне значення option/config/meta-команди
        $pos = [];
        foreach ($tokens($cmd) as $t) {
            if ($t[0] !== '' && $t[0][0] === '-') continue; // прапорці не впливають на позиції
            $pos[] = $t;
        }
        if ($pos && strtolower($pos[0][0]) === 'wp') array_shift($pos);
        $w     = array_map(function ($t) { return strtolower(trim($t[0], '"\'')); }, $pos);
        $base  = null;
        $id    = 0;
        if (in_array($w[0] ?? '', ['config', 'option'], true)) {
            $base = 1;
        } elseif (in_array($w[0] ?? '', ['site', 'network'], true) && ($w[1] ?? '') === 'option') {
            $base = 2;
        } elseif (in_array($w[0] ?? '', ['post', 'user', 'term', 'comment', 'site', 'network'], true) && ($w[1] ?? '') === 'meta') {
            $base = 2;
            $id   = 1; // <id> перед назвою ключа
        }
        $names = [];
        $value = null;
        if ($base !== null) {
            $verb  = $w[$base] ?? '';
            $first = $base + 1 + $id;
            if (in_array($verb, ['add', 'set', 'update'], true)) {
                $names = [$first];
                $value = $first + 1;
            } elseif ($verb === 'patch' && $w[0] !== 'config' && in_array($w[$base + 1] ?? '', ['insert', 'update'], true)) {
                $value = count($pos) - 1;
                $names = $value > $first + 1 ? range($first + 1, $value - 1) : [];
            }
        }
        if ($names && isset($pos[$value])) {
            $secret = false;
            foreach ($names as $i) {
                if (isset($pos[$i]) && self::secret_name(trim($pos[$i][0], '"\''))) $secret = true;
            }
            $raw = $pos[$value][0];
            $new = null;
            if ($secret) {
                $new = '[redacted]';
            } else {
                $json = json_decode(preg_replace('~^([\'"])(.*)\1$~s', '$2', $raw), true);
                if (is_array($json) && ($red = self::redact($json)) !== $json) $new = "'" . wp_json_encode($red, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "'";
            }
            if ($new !== null) $cmd = substr_replace($cmd, $new, $pos[$value][1], strlen($raw));
        }

        // `--user_pass V`: значення секретного прапорця окремим токеном (з кінця — зсуви не ламають позиції)
        $all = $tokens($cmd);
        for ($i = count($all) - 2; $i >= 0; $i--) {
            if (preg_match('~^--([A-Za-z0-9_-]+)$~', $all[$i][0], $m) && self::secret_name($m[1])
                && $all[$i + 1][0] !== '' && $all[$i + 1][0][0] !== '-') {
                $cmd = substr_replace($cmd, '[redacted]', $all[$i + 1][1], strlen($all[$i + 1][0]));
            }
        }
        return $cmd;
    }

    // ── Читання логу ──────────────────────────────────────────────────────

    static function recent($limit = 50) {
        return self::query('', '', $limit, 0);
    }

    /**
     * WHERE-фрагмент за діапазоном дат (локальний час, як і created_at).
     * $from/$to — 'Y-m-d' або ''. Повертає [sql_without_leading_WHERE_or_empty, params].
     */
    static function where_clause($from, $to) {
        $conds  = [];
        $params = [];
        if ($from !== '') { $conds[] = 'created_at >= %s'; $params[] = $from . ' 00:00:00'; }
        if ($to   !== '') { $conds[] = 'created_at <= %s'; $params[] = $to . ' 23:59:59'; }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return [$where, $params];
    }

    /** Сторінка записів за діапазоном дат (найновіші зверху). */
    static function query($from = '', $to = '', $limit = 10, $offset = 0) {
        global $wpdb;
        $t = self::table();
        [$where, $params] = self::where_clause($from, $to);
        $params[] = max(1, (int) $limit);
        $params[] = max(0, (int) $offset);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t $where ORDER BY id DESC LIMIT %d OFFSET %d", $params));
    }

    /** Кількість записів за діапазоном дат (для пагінатора). */
    static function count($from = '', $to = '') {
        global $wpdb;
        $t = self::table();
        [$where, $params] = self::where_clause($from, $to);
        if ($params) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t $where", $params));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    }

    /** Повне очищення логу. */
    static function clear() {
        global $wpdb;
        $t = self::table();
        $wpdb->query("TRUNCATE TABLE $t");
    }

    // ── Ретенція ──────────────────────────────────────────────────────────

    /** Скільки днів зберігати лог (опція log_retention_days; 0 = не чистити) */
    static function retention_days() {
        $d = Simple_MCP::opt('log_retention_days', self::RETENTION_DEFAULT);
        return max(0, min(3650, (int) $d));
    }

    /**
     * Ретенція (щоденний cron simple_mcp_prune; також з екрана налаштувань): видаляє записи,
     * старші за N днів (за замовчуванням — з налаштувань), і прострочені лічильники.
     * Межу рахуємо в локальному часі сайту — так само зберігається created_at.
     */
    static function prune($days = null) {
        global $wpdb;
        $days = ($days === null || $days === '') ? self::retention_days() : max(0, (int) $days);
        if ($days > 0) {
            $t      = self::table();
            $cutoff = wp_date('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            if (is_string($cutoff) && $cutoff !== '') {
                $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE created_at < %s", $cutoff));
            }
        }
        if (class_exists('Simple_MCP_Auth')) Simple_MCP_Auth::sweep_counters();
        // Лічильники версій до 2.5.0 (транзієнти simple_mcp_rl_* / simple_mcp_fail_*)
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_simple\\_mcp\\_rl\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_simple\\_mcp\\_rl\\_%'
                OR option_name LIKE '\\_transient\\_simple\\_mcp\\_fail\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_simple\\_mcp\\_fail\\_%'"
        );
    }
}
