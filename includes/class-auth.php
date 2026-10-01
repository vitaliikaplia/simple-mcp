<?php
/**
 * Автентифікація та захист ендпоінта.
 *
 * Персональні ключі: кожен ключ належить конкретному WordPress-користувачу
 * (формат smcp-{user_id}-{random}), у user meta зберігається лише SHA-256.
 * Користувач може мати кілька іменованих ключів (simple_mcp_keys, до MAX_KEYS);
 * легасі-ключ (simple_mcp_key_hash) і далі працює й показується окремим рядком.
 * Успішна автентифікація = wp_set_current_user(власник ключа), тож усі інструменти
 * працюють під нативними правами цього користувача, а дозволені групи інструментів
 * визначає матриця ролей (Simple_MCP::user_perms).
 *
 * Порядок перевірок: kill-switch → HTTPS (з урахуванням довірених проксі) → IP-allowlist →
 * bearer → формат токена → hash_equals з ключами користувача (з заблокованої IP невалідний токен
 * отримує 429 без лічильника й аудиту, а валідний ключ проходить: за NAT/проксі без
 * SIMPLE_MCP_TRUSTED_PROXIES усі клієнти ділять одну адресу) → користувач → роль → rate-limit.
 * Лічильник невдач і троттлінг аудиту рахуються на IPv4-адресу або IPv6-мережу /64 (ip_bucket),
 * а рядків аудиту відмов за вікно — не більше AUDIT_FAIL_MAX на весь сайт.
 * Довірені проксі — константа SIMPLE_MCP_TRUSTED_PROXIES (IP/CIDR): лише для них
 * враховуються X-Forwarded-For (крайня права недовірена адреса) і X-Forwarded-Proto.
 * Лічильники (rate-limit, невдалі спроби) атомарні: wp_cache_incr при зовнішньому
 * object-cache, інакше SQL-upsert рядків simple_mcp_{kind}_{вікно}_{hash} у wp_options.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Auth {

    /** Префікс персонального ключа (далі — id користувача і випадкова частина) */
    const KEY_PREFIX = 'smcp';

    /** User meta зі списком іменованих ключів: [{id,name,hash,created,created_by,last_used,last_ip}] */
    const KEYS_META = 'simple_mcp_keys';
    /** User meta з останнім використанням легасі-ключа: ['time' => int, 'ip' => string] */
    const LEGACY_USED_META = 'simple_mcp_key_legacy_used';
    const LEGACY_ID = 'legacy';
    const MAX_KEYS  = 10;
    /** last_used/last_ip оновлюємо не частіше, ніж раз на 5 хв (менше записів у БД) */
    const TOUCH_EVERY = 300;

    /** Блокування IP: стільки невдалих спроб за вікно → 429 для невалідних токенів (валідний ключ проходить) */
    const LOCKOUT_MAX    = 10;
    const LOCKOUT_WINDOW = 300;
    const RATE_WINDOW    = 60;
    /** Скільки рядків аудиту відмов автентифікації за вікно блокування пишемо загалом (усі IP разом) */
    const AUDIT_FAIL_MAX = 60;

    /** Дозволи автентифікованого користувача поточного запиту (all-false поза MCP-запитом) */
    private static $perms = null;
    private static $user_id = 0;
    private static $key_id = '';

    /**
     * Повертає true або WP_Error (data: status, headers — заголовки для HTTP-відповіді).
     * У разі успіху виставляє контекст користувача-власника ключа та його дозволи.
     * $charge=false — не списувати запит із rate-limit (напр. GET, що отримає 405).
     */
    static function check($charge = true) {
        self::$perms   = null;
        self::$user_id = 0;
        self::$key_id  = '';

        if (defined('SIMPLE_MCP_DISABLE') && SIMPLE_MCP_DISABLE) {
            return self::deny('disabled', 'MCP вимкнено (kill-switch)', 503);
        }
        if (!Simple_MCP::opt('enabled', true)) {
            return self::deny('disabled', 'MCP вимкнено в налаштуваннях', 503);
        }
        if (!self::request_is_https() && !(defined('SIMPLE_MCP_ALLOW_INSECURE') && SIMPLE_MCP_ALLOW_INSECURE)) {
            // В аудит — лише запити з ключем (ключ пішов відкритим текстом); сканери без ключа — ні
            if (self::bearer() !== '') {
                self::audit_throttled('insecure', 'Запит з ключем без HTTPS (або від TLS-проксі поза SIMPLE_MCP_TRUSTED_PROXIES); якщо ключ ішов відкритим текстом — відклич його', self::token_user_id(self::bearer()));
            }
            return self::deny('insecure', 'Потрібен HTTPS', 403);
        }

        $ip = self::client_ip();

        // IP-allowlist (якщо заданий) — до будь-якої роботи з токеном: однакова 403 для всіх
        $allow = (array) Simple_MCP::opt('ip_allowlist', []);
        if (!empty($allow) && !self::ip_in_list($ip, $allow)) {
            self::audit_throttled('ip', 'IP ' . $ip . ' не в дозволеному списку', 0);
            return self::deny('ip', 'IP не в дозволеному списку', 403);
        }

        // Забагато невдалих спроб з цієї IP (/64 для IPv6): невалідний токен → 429 (без лічильника,
        // аудиту й usleep у воркері), але валідний ключ звіряється й проходить — інакше будь-хто за тим
        // самим NAT/проксі блокував би справжні ключі десятьма сміттєвими запитами.
        $locked = self::counter_peek('af', self::ip_bucket($ip), self::LOCKOUT_WINDOW) >= self::LOCKOUT_MAX;

        $provided = self::bearer();
        if ($provided === '') {
            if ($locked) return self::locked();
            return self::deny('noauth', 'Відсутній bearer-токен', 401, ['WWW-Authenticate' => 'Bearer realm="simple-mcp"']);
        }

        // id користувача зашитий у токен (smcp-{id}-…) — O(1) резолв без сканування meta
        $uid = self::token_user_id($provided);
        if (!$uid) {
            return $locked ? self::locked() : self::fail('badauth', 'Невірний токен (формат)', 0);
        }

        // Звіряємо з УСІМА ключами користувача (hash_equals, без раннього виходу)
        $hash  = hash('sha256', $provided);
        $match = '';
        foreach (self::keys_for($uid) as $k) {
            if (hash_equals((string) $k['hash'], $hash) && $match === '') {
                $match = (string) $k['id'];
            }
        }
        if ($match === '') {
            return $locked ? self::locked() : self::fail('badauth', 'Невірний або відкликаний токен', $uid);
        }

        $user = get_user_by('id', $uid);
        if (!$user) {
            return self::fail('nouser', 'Користувача ключа не існує', $uid);
        }

        // Матриця ролей: чи має бодай одна роль користувача MCP-доступ
        $perms = Simple_MCP::user_perms($user);
        if (empty($perms['mcp'])) {
            self::audit_throttled('norole', 'Роль користувача ' . $user->user_login . ' не має MCP-доступу', $uid);
            return self::deny('norole', 'Роль користувача не має MCP-доступу (Налаштування → Simple MCP)', 403);
        }

        // Rate-limit по автентифікованому користувачу
        if ($charge && !self::rate_ok('u' . $uid)) {
            self::audit_throttled('rate', 'Перевищено ліміт запитів користувачем ' . $user->user_login, $uid);
            $retry = self::retry_after(self::RATE_WINDOW);
            return self::deny('rate', 'Перевищено ліміт запитів', 429, ['Retry-After' => (string) $retry]);
        }

        wp_set_current_user($uid);
        self::$user_id = $uid;
        self::$perms   = $perms;
        self::$key_id  = $match;
        self::touch_key($uid, $match);

        return true;
    }

    /** WP_Error відмови з HTTP-статусом і заголовками для транспорту */
    private static function deny($code, $msg, $status, $headers = []) {
        return new WP_Error($code, $msg, ['status' => (int) $status, 'headers' => (array) $headers]);
    }

    /** 429 для невалідного токена з IP, заблокованої після серії невдалих спроб */
    private static function locked() {
        $retry = self::retry_after(self::LOCKOUT_WINDOW);
        return self::deny('locked', 'Забагато невдалих спроб автентифікації з цієї IP — повтори через ' . $retry . ' с', 429, ['Retry-After' => (string) $retry]);
    }

    /**
     * Невдала спроба з невірними обліковими даними: лічильник IP (блокування після
     * LOCKOUT_MAX), аудит — перша спроба у вікні та момент блокування. 401 invalid_token.
     */
    private static function fail($code, $msg, $token_uid) {
        $n = self::counter_hit('af', self::ip_bucket(self::client_ip()), self::LOCKOUT_WINDOW);
        if ($n === 1 || $n === self::LOCKOUT_MAX) {
            $detail = $msg . ($n === self::LOCKOUT_MAX ? ' — IP заблоковано на ' . self::retry_after(self::LOCKOUT_WINDOW) . ' с після ' . $n . ' невдалих спроб' : '');
            self::audit_failure($code, $detail, $token_uid);
        }
        return self::deny($code, $msg, 401, [
            'WWW-Authenticate' => 'Bearer realm="simple-mcp", error="invalid_token", error_description="The access token is invalid or revoked"',
        ]);
    }

    /** Аудит відмови не частіше одного рядка на (IP або IPv6 /64, причину) за вікно блокування */
    private static function audit_throttled($code, $detail, $token_uid) {
        $id = self::ip_bucket(self::client_ip()) . '|' . $code;
        if (self::counter_peek('al', $id, self::LOCKOUT_WINDOW) > 0) return;
        self::counter_hit('al', $id, self::LOCKOUT_WINDOW);
        self::audit_failure($code, $detail, $token_uid);
    }

    private static function audit_failure($code, $detail, $token_uid) {
        if (!class_exists('Simple_MCP_Audit')) return;
        // Загальний бюджет рядків на вікно: ротація адрес (IPv6) чи ботнет не роздуває журнал — понад
        // ліміт відмови лише рахуються, а про обрізання пишемо один рядок.
        $n = self::counter_hit('al', 'global', self::LOCKOUT_WINDOW);
        if ($n > self::AUDIT_FAIL_MAX + 1) return;
        if ($n === self::AUDIT_FAIL_MAX + 1) {
            $code      = 'audit_capped';
            $detail    = 'Понад ' . self::AUDIT_FAIL_MAX . ' відмов автентифікації за ' . intdiv(self::LOCKOUT_WINDOW, 60) . ' хв — до кінця вікна (' . self::retry_after(self::LOCKOUT_WINDOW) . ' с) вони не журналюються';
            $token_uid = 0;
        }
        $args = ['reason' => $code, 'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))];
        // лише id з префікса smcp-{id}-, не сам токен (назва без «token»: redact() сховав би значення)
        if ($token_uid) $args['claimed_user_id'] = (int) $token_uid;
        Simple_MCP_Audit::log('auth', $args, 'denied', ['detail' => $detail, 'user_id' => 0, 'key_id' => '']);
    }

    /** Секунди до кінця поточного фіксованого вікна (для Retry-After) */
    static function retry_after($window) {
        $window = max(1, (int) $window);
        return $window - (time() % $window);
    }

    /** Витягуємо id користувача з токена формату smcp-{id}-{random}; 0 якщо формат чужий */
    static function token_user_id($token) {
        if (preg_match('/^' . self::KEY_PREFIX . '-(\d+)-[A-Za-z0-9]{32,}$/', (string) $token, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    // ── Ключі користувача ─────────────────────────────────────────────────

    /** Збережені іменовані ключі (без легасі), нормалізовані */
    static function stored_keys($user_id) {
        $raw = get_user_meta((int) $user_id, self::KEYS_META, true);
        $out = [];
        if (!is_array($raw)) return $out;
        foreach ($raw as $k) {
            if (!is_array($k) || empty($k['id']) || empty($k['hash'])) continue;
            $out[] = [
                'id'         => (string) $k['id'],
                'name'       => (string) ($k['name'] ?? ''),
                'hash'       => (string) $k['hash'],
                'created'    => (int) ($k['created'] ?? 0),
                'created_by' => (int) ($k['created_by'] ?? 0),
                'last_used'  => (int) ($k['last_used'] ?? 0),
                'last_ip'    => (string) ($k['last_ip'] ?? ''),
            ];
        }
        return $out;
    }

    /** Усі ключі користувача для автентифікації/UI: іменовані + легасі (якщо є), з прапорцем legacy */
    static function keys_for($user_id) {
        $user_id = (int) $user_id;
        $keys = [];
        foreach (self::stored_keys($user_id) as $k) {
            $keys[] = $k + ['legacy' => false];
        }
        $legacy = get_user_meta($user_id, Simple_MCP::USER_KEY_META, true);
        if (is_string($legacy) && $legacy !== '') {
            $used = get_user_meta($user_id, self::LEGACY_USED_META, true);
            $keys[] = [
                'id'         => self::LEGACY_ID,
                'name'       => 'Ключ до 2.5.0 (легасі)',
                'hash'       => $legacy,
                'created'    => (int) get_user_meta($user_id, Simple_MCP::USER_KEY_CREATED, true),
                'created_by' => 0,
                'last_used'  => is_array($used) ? (int) ($used['time'] ?? 0) : 0,
                'last_ip'    => is_array($used) ? (string) ($used['ip'] ?? '') : '',
                'legacy'     => true,
            ];
        }
        return $keys;
    }

    /**
     * Створити новий іменований ключ. Повертає ['key' => plaintext (показати один раз),
     * 'id' => …, 'name' => …] або WP_Error (ліміт MAX_KEYS / помилка запису).
     */
    static function create_key($user_id, $name = '', $created_by = null) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return new WP_Error('nouser', 'Невірний користувач');
        if (count(self::keys_for($user_id)) >= self::MAX_KEYS) {
            return new WP_Error('max_keys', 'У користувача вже ' . self::MAX_KEYS . ' ключів — відклич непотрібний, щоб створити новий.');
        }
        $name = trim(sanitize_text_field((string) $name));
        if (function_exists('mb_substr')) $name = mb_substr($name, 0, 60);
        if ($name === '') $name = 'Ключ від ' . wp_date('Y-m-d H:i');

        $plain = self::KEY_PREFIX . '-' . $user_id . '-' . wp_generate_password(64, false, false);
        $entry = [
            'id'         => bin2hex(random_bytes(6)),
            'name'       => $name,
            'hash'       => hash('sha256', $plain),
            'created'    => time(),
            'created_by' => $created_by === null ? get_current_user_id() : (int) $created_by,
            'last_used'  => 0,
            'last_ip'    => '',
        ];
        $keys   = self::stored_keys($user_id);
        $keys[] = $entry;
        update_user_meta($user_id, self::KEYS_META, wp_slash($keys));

        // перевіряємо, що ключ справді записався (інакше показали б непрацюючий plaintext)
        wp_cache_delete($user_id, 'user_meta');
        $saved = false;
        foreach (self::stored_keys($user_id) as $k) {
            if ($k['id'] === $entry['id'] && hash_equals($k['hash'], $entry['hash'])) $saved = true;
        }
        if (!$saved) return new WP_Error('save_failed', 'Не вдалося зберегти ключ');

        return ['key' => $plain, 'id' => $entry['id'], 'name' => $name];
    }

    /** Згенерувати новий персональний ключ (сумісність: повертає plaintext або WP_Error) */
    static function generate_key_for($user_id, $name = '', $created_by = null) {
        $res = self::create_key($user_id, $name, $created_by);
        return is_wp_error($res) ? $res : $res['key'];
    }

    /**
     * Відкликати ключ користувача. $key_id = null — усі ключі (іменовані + легасі);
     * 'legacy' — лише легасі-ключ; інакше — один іменований ключ.
     * Повертає список відкликаних ключів [{id,name}] (порожній — нічого не знайдено).
     */
    static function revoke_key_for($user_id, $key_id = null) {
        $user_id = (int) $user_id;
        $removed = [];
        foreach (self::keys_for($user_id) as $k) {
            if ($key_id === null || $key_id === '' || $k['id'] === (string) $key_id) {
                $removed[] = ['id' => $k['id'], 'name' => $k['name']];
            }
        }
        if (!$removed) return [];

        $ids = array_column($removed, 'id');
        if (in_array(self::LEGACY_ID, $ids, true)) {
            delete_user_meta($user_id, Simple_MCP::USER_KEY_META);
            delete_user_meta($user_id, Simple_MCP::USER_KEY_CREATED);
            delete_user_meta($user_id, self::LEGACY_USED_META);
        }
        $keep = [];
        foreach (self::stored_keys($user_id) as $k) {
            if (!in_array($k['id'], $ids, true)) $keep[] = $k;
        }
        if ($keep) {
            update_user_meta($user_id, self::KEYS_META, wp_slash($keep));
        } else {
            delete_user_meta($user_id, self::KEYS_META);
        }
        return $removed;
    }

    /**
     * Позначити використання ключа (last_used/last_ip) — не частіше TOUCH_EVERY.
     * Запис через prev_value (compare-and-swap): паралельна генерація/відкликання
     * ключа в адмінці не перезапишеться застарілим списком — тоді просто пропускаємо.
     */
    private static function touch_key($user_id, $key_id) {
        $now = time();
        if ($key_id === self::LEGACY_ID) {
            $used = get_user_meta($user_id, self::LEGACY_USED_META, true);
            if (is_array($used) && $now - (int) ($used['time'] ?? 0) < self::TOUCH_EVERY) return;
            update_user_meta($user_id, self::LEGACY_USED_META, wp_slash(['time' => $now, 'ip' => self::client_ip()]));
            return;
        }
        $old = get_user_meta($user_id, self::KEYS_META, true);
        if (!is_array($old)) return;
        foreach ($old as $i => $k) {
            if (!is_array($k) || (string) ($k['id'] ?? '') !== $key_id) continue;
            if ($now - (int) ($k['last_used'] ?? 0) < self::TOUCH_EVERY) return;
            $new = $old;
            $new[$i]['last_used'] = $now;
            $new[$i]['last_ip']   = self::client_ip();
            update_user_meta($user_id, self::KEYS_META, wp_slash($new), $old);
            return;
        }
    }

    // ── Контекст поточного запиту ─────────────────────────────────────────

    /** Дозволи поточного MCP-запиту. Поза автентифікованим запитом — все false. */
    static function current_perms() {
        return is_array(self::$perms) ? self::$perms : array_fill_keys(Simple_MCP::PERMS, false);
    }

    static function perm($key) {
        $p = self::current_perms();
        return !empty($p[$key]);
    }

    static function current_user_id() {
        return self::$user_id;
    }

    /** Id ключа, яким автентифіковано поточний запит ('legacy' для старого ключа; '' поза MCP) */
    static function current_key_id() {
        return self::$key_id;
    }

    /**
     * Списати один tools/call з rate-limit поточного користувача (для елементів батча:
     * сам HTTP-запит уже списано в check()). true — дозволено.
     */
    static function charge_call() {
        if (!self::$user_id) return true;
        return self::rate_ok('u' . self::$user_id);
    }

    /**
     * Лише для WP-CLI (тести/діагностика): виставити контекст «автентифікованого» MCP-запиту
     * для користувача без ключа й HTTP. Поза WP-CLI нічого не робить.
     */
    static function impersonate_for_cli($user_id) {
        if (!(defined('WP_CLI') && WP_CLI)) return false;
        $user = get_user_by('id', (int) $user_id);
        if (!$user) return false;
        wp_set_current_user($user->ID);
        self::$user_id = $user->ID;
        self::$perms   = Simple_MCP::user_perms($user);
        self::$key_id  = '';
        return true;
    }

    /** Витягуємо Bearer-токен з різних місць (FPM подекуди ховає Authorization) */
    static function bearer() {
        $h = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $h = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') { $h = $v; break; }
            }
        }
        $h = (string) $h;
        if ($h !== '' && stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        // Запасний власний заголовок, якщо Authorization ріжеться проксі
        if (!empty($_SERVER['HTTP_X_SIMPLE_MCP_KEY'])) {
            return trim((string) $_SERVER['HTTP_X_SIMPLE_MCP_KEY']);
        }
        return '';
    }

    // ── IP, HTTPS, довірені проксі ────────────────────────────────────────

    /** Довірені проксі з константи SIMPLE_MCP_TRUSTED_PROXIES (рядок через кому/пробіл або масив) */
    static function trusted_proxies() {
        if (!defined('SIMPLE_MCP_TRUSTED_PROXIES') || !SIMPLE_MCP_TRUSTED_PROXIES) return [];
        $v    = SIMPLE_MCP_TRUSTED_PROXIES;
        $list = is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v);
        $out  = [];
        foreach ((array) $list as $e) {
            $e = trim((string) $e);
            if ($e !== '') $out[] = $e;
        }
        return $out;
    }

    /** Чи прийшов запит безпосередньо від довіреного проксі (REMOTE_ADDR у списку) */
    static function from_trusted_proxy() {
        $trusted = self::trusted_proxies();
        return $trusted && self::ip_in_list((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted);
    }

    /**
     * IP клієнта. За замовчуванням — лише REMOTE_ADDR (X-Forwarded-For можна підробити).
     * Якщо REMOTE_ADDR — довірений проксі, ідемо X-Forwarded-For справа наліво й беремо
     * першу адресу, що не є довіреним проксі (ліві значення підставляє сам клієнт).
     */
    static function client_ip() {
        $remote = self::clean_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !self::from_trusted_proxy()) return $remote;

        $trusted = self::trusted_proxies();
        $client  = $remote;
        foreach (array_reverse(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])) as $hop) {
            $ip = self::clean_ip($hop);
            if ($ip === '') break;      // сміття в ланцюжку — далі не довіряємо, лишаємо останній надійний хоп
            $client = $ip;
            if (!self::ip_in_list($ip, $trusted)) break;
        }
        return $client;
    }

    /** HTTPS з урахуванням довірених проксі (X-Forwarded-Proto — крайнє праве значення) */
    static function request_is_https() {
        if (is_ssl()) return true;
        if (empty($_SERVER['HTTP_X_FORWARDED_PROTO']) || !self::from_trusted_proxy()) return false;
        $parts = explode(',', strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']));
        return trim((string) end($parts)) === 'https';
    }

    /** Нормалізувати IP із заголовка (лапки, [v6]:port, v4:port, zone-id) → канонічний вигляд або '' */
    static function clean_ip($ip) {
        $ip = trim((string) $ip, " \t\n\r\0\x0B\"'");
        if ($ip === '') return '';
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $ip, $m)) {
            $ip = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m)) {
            $ip = $m[1];
        }
        $bin = self::ip_bin($ip);
        return $bin === false ? '' : (string) inet_ntop($bin);
    }

    /**
     * Кошик для лічильника невдач і троттлінгу аудиту: IPv4 — сама адреса, IPv6 — мережа /64
     * (клієнт зазвичай володіє цілою /64 і міг би обходити блокування ротацією адрес).
     */
    static function ip_bucket($ip) {
        $bin = self::ip_bin($ip);
        if ($bin === false) return (string) $ip;
        if (strlen($bin) !== 16) return (string) inet_ntop($bin);
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /** Бінарне представлення IP (inet_pton); IPv4-mapped IPv6 (::ffff:a.b.c.d) → 4 байти */
    static function ip_bin($ip) {
        $ip = trim((string) $ip);
        if (($p = strpos($ip, '%')) !== false) $ip = substr($ip, 0, $p); // fe80::1%eth0
        if ($ip === '') return false;
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        if (strlen($bin) === 16 && strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            $bin = substr($bin, 12);
        }
        return $bin;
    }

    /** Чи входить IP у список (точні IPv4/IPv6 у будь-якому записі або CIDR обох версій) */
    static function ip_in_list($ip, $list) {
        $bin = self::ip_bin($ip);
        if ($bin === false) return false;
        foreach ((array) $list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') continue;
            if (strpos($entry, '/') !== false) {
                if (self::cidr_match($ip, $entry)) return true;
            } elseif (self::ip_bin($entry) === $bin) {
                return true;
            }
        }
        return false;
    }

    /** CIDR для IPv4 та IPv6 (побайтове порівняння префікса) */
    static function cidr_match($ip, $cidr) {
        list($subnet, $bits) = array_pad(explode('/', (string) $cidr, 2), 2, '');
        $ipb  = self::ip_bin($ip);
        $subb = self::ip_bin($subnet);
        if ($ipb === false || $subb === false || strlen($ipb) !== strlen($subb)) return false;
        $max  = strlen($ipb) * 8;
        $bits = trim((string) $bits);
        if ($bits === '') {
            $bits = $max;
        } elseif (!ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits < 0 || $bits > $max) return false;
        $bytes = intdiv($bits, 8);
        $rest  = $bits % 8;
        if ($bytes > 0 && substr($ipb, 0, $bytes) !== substr($subb, 0, $bytes)) return false;
        if ($rest) {
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($ipb[$bytes]) & $mask) !== (ord($subb[$bytes]) & $mask)) return false;
        }
        return true;
    }

    // ── Атомарні лічильники (rate-limit, невдалі спроби, троттлінг аудиту) ──

    /** Rate-limit: $bucket — 'u{user_id}' для валідних запитів. true — дозволено. */
    static function rate_ok($bucket) {
        $limit = intval(Simple_MCP::opt('rate_limit', 120));
        if ($limit <= 0) return true;
        return self::counter_hit('rl', $bucket, self::RATE_WINDOW) <= $limit;
    }

    /** Назва рядка лічильника: simple_mcp_{kind}_{вікно (10 цифр)}_{md5(id)} */
    private static function counter_name($kind, $id, $window, $offset = 0) {
        return 'simple_mcp_' . $kind . '_' . sprintf('%010d', intdiv(time(), max(1, (int) $window)) + $offset) . '_' . md5((string) $id);
    }

    /** Атомарно +1 і повернути нове значення лічильника поточного вікна */
    static function counter_hit($kind, $id, $window) {
        global $wpdb;
        $name = self::counter_name($kind, $id, $window);
        if (wp_using_ext_object_cache()) {
            wp_cache_add($name, 0, 'simple_mcp', $window + 5);
            $n = wp_cache_incr($name, 1, 'simple_mcp');
            if ($n === false) {
                wp_cache_set($name, 1, 'simple_mcp', $window + 5);
                $n = 1;
            }
            return (int) $n;
        }
        // INSERT … ON DUPLICATE KEY UPDATE: атомарний інкремент; LAST_INSERT_ID(expr) повертає
        // нове значення без окремого SELECT (1 рядок змінено = вставка, 2 = оновлення).
        $r = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'off')
             ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + 1)",
            $name
        ));
        if ($r === 1) {
            self::sweep_counters($kind, $window); // нове вікно — прибираємо прострочені рядки цього виду
            return 1;
        }
        if ($r === 2 && (int) $wpdb->insert_id > 0) return (int) $wpdb->insert_id;
        if ($r !== false) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        }
        // БД без upsert (напр. SQLite-драйвер) — неатомарний запасний варіант
        $t = 'simple_mcp_c' . md5($name);
        $n = (int) get_transient($t) + 1;
        set_transient($t, $n, $window + 5);
        return $n;
    }

    /** Поточне значення лічильника без інкременту */
    static function counter_peek($kind, $id, $window) {
        global $wpdb;
        $name = self::counter_name($kind, $id, $window);
        if (wp_using_ext_object_cache()) {
            return (int) wp_cache_get($name, 'simple_mcp');
        }
        $v = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($v === null && $wpdb->last_error !== '') {
            return (int) get_transient('simple_mcp_c' . md5($name));
        }
        return (int) $v;
    }

    /**
     * Видалити рядки лічильників, старші за попереднє вікно. Без аргументів — усі види
     * (викликається з Simple_MCP_Audit::prune і при відкритті кожного нового вікна).
     */
    static function sweep_counters($kind = null, $window = null) {
        global $wpdb;
        if (wp_using_ext_object_cache()) return; // у кеші ключі живуть з TTL
        $kinds = $kind === null
            ? ['rl' => self::RATE_WINDOW, 'af' => self::LOCKOUT_WINDOW, 'al' => self::LOCKOUT_WINDOW]
            : [$kind => (int) $window];
        foreach ($kinds as $k => $w) {
            $base = 'simple_mcp_' . $k . '_';
            $cur  = intdiv(time(), max(1, $w));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
                $wpdb->esc_like($base) . '%',
                $wpdb->esc_like($base . sprintf('%010d', $cur) . '_') . '%',
                $wpdb->esc_like($base . sprintf('%010d', $cur - 1) . '_') . '%'
            ));
        }
    }
}
