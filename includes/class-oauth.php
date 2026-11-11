<?php
/**
 * OAuth 2.1 для MCP-ендпоінта — щоб агент, який не може нести наш заголовок із ключем (ChatGPT,
 * конектори claude.ai, Gemini) або не хоче тримати ключ у конфігу (Codex, Claude Code), підключався
 * за згодою людини: агенту дають лише адресу ендпоінта, людина входить у WordPress і тисне «Дозволити».
 * Механіка перенесена з Memo (lib/oauth.js) разом з уроками його інцидентів.
 *
 * ПЛАГІН — ОБИДВІ СТОРОНИ: сервер авторизації й захищений ресурс (специфікація авторизації MCP):
 *
 *   discovery     /.well-known/oauth-protected-resource/<шлях> (RFC 9728) називає сайт сервером
 *                 авторизації, /.well-known/oauth-authorization-server (RFC 8414) каже, як ним
 *                 користуватися; 401 ендпоінта несе resource_metadata
 *   реєстрація    динамічна (RFC 7591), відкрита, з лімітом на адресу: клієнт — це назва й адреси,
 *                 куди можна повернути код. Без client id metadata documents: сервер ніколи не
 *                 завантажує адресу, яку дав незнайомець. Адреси — лише відомих агентів (ChatGPT,
 *                 Claude, Gemini; loopback і схеми застосунків для Codex, Claude Code, Cursor), якщо
 *                 в налаштуваннях не дозволено «будь-які»
 *   згода         <шлях>/oauth/authorize — звичайний вхід WordPress і «Дозволити»; код прив'язаний до
 *                 клієнта, адреси, PKCE (лише S256) і ресурсу, відповідь несе iss (RFC 9207)
 *   токени        <шлях>/oauth/token — access на годину, refresh на 90 днів з ротацією й хвилиною
 *                 грації для клієнта, що оновився двічі одночасно
 *
 * ПІДКЛЮЧЕННЯ — ЦЕ ІМЕНОВАНИЙ КЛЮЧ користувача (Simple_MCP_Auth::create_key з via «OAuth · хост»):
 * профіль, відкликання, аудит, матриця ролей і ліміт ключів знають його без окремого коду. Секрет
 * цього ключа ніде не показується й не вживається — клієнт тримає лише OAuth-токени. Відкликання
 * ключа в профілі завершує підключення одразу; вимкнення OAuth у налаштуваннях — усі підключення.
 * WP-CLI підпорядковується ролі користувача; перемикач «WP-CLI для OAuth-підключень» (типово
 * увімкнений) може забрати його в усіх OAuth-підключень.
 *
 * У базі лише SHA-256 кодів, токенів і секретів. Таблиці — на кожному сайті (префікс сайту).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_OAuth {

    const ACCESS_PREFIX  = 'smcp_oat_';
    const REFRESH_PREFIX = 'smcp_ort_';
    const SECRET_PREFIX  = 'smcp_ocs_';
    const CLIENT_PREFIX  = 'smcpc_';

    const CODE_TTL    = 300;          // код зі сторінки згоди — 5 хв
    const ACCESS_TTL  = 3600;         // access — година
    const REFRESH_TTL = 7776000;      // refresh — 90 днів
    const REUSE_GRACE = 60;           // використаний refresh ще хвилину дає нову пару
    const CLIENT_IDLE = 2592000;      // клієнт без жодного підключення живе 30 днів

    /** Ліміти дверей: реєстрацій з адреси за годину; невдач клієнта на token endpoint за 15 хв; рядків журналу відмов */
    const REGISTER_WINDOW = 3600;
    const REGISTER_MAX    = 20;
    const DENY_WINDOW     = 900;
    const DENY_MAX        = 30;
    const LOG_MAX         = 5;
    /** Реєстрацій на весь сайт за годину (ліміт на адресу IPv6 /64 обходиться ротацією мереж) */
    const REGISTER_GLOBAL_MAX = 200;
    /** Скільки тримати використані refresh-токени: їх повторне пред'явлення — ознака крадіжки */
    const USED_REFRESH_KEEP   = 604800;

    const NAME_MAX      = 120;
    const URI_MAX       = 500;
    const REDIRECTS_MAX = 10;
    const AUTH_METHODS  = ['none', 'client_secret_post', 'client_secret_basic'];
    const LOOPBACK      = ['127.0.0.1', 'localhost', '[::1]'];
    /** Схеми, куди код не віддаємо ніколи: вони виконують, читають чи відкривають обробники ОС, а не застосунок агента */
    const BAD_SCHEMES   = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms',
        'search', 'search-ms', 'ms-officecmd', 'ms-word', 'ms-excel', 'ms-powerpoint', 'microsoft-edge', 'itms-services',
        'shell', 'smb', 'ssh', 'telnet', 'ldap', 'jar', 'view-source', 'chrome', 'resource'];
    /** Схеми застосунків-агентів, які вважаємо відомими (інші — лише зворотні DNS-імена на кшталт com.example.app, RFC 8252 §7.1) */
    const APP_SCHEMES   = ['cursor', 'vscode', 'vscode-insiders', 'windsurf', 'codium'];

    /**
     * Адреси повернення відомих агентів: хост => [назва агента, чи підходять піддомени].
     * Для Google — лише його службові хости OAuth: на інших *.googleusercontent.com живе вміст користувачів.
     */
    const KNOWN_HOSTS = [
        'chatgpt.com'                                  => ['ChatGPT', false],
        'openai.com'                                   => ['OpenAI', false],
        'platform.openai.com'                          => ['OpenAI', false],
        'claude.ai'                                    => ['Claude', false],
        'claude.com'                                   => ['Claude', false],
        'oauth-redirect.googleusercontent.com'         => ['Gemini', false],
        'oauth-redirect-sandbox.googleusercontent.com' => ['Gemini', false],
    ];

    // ── Налаштування ──────────────────────────────────────────────────────

    /** OAuth увімкнено (типово — ні) і сам сервер не вимкнено */
    static function enabled() {
        if (defined('SIMPLE_MCP_DISABLE') && SIMPLE_MCP_DISABLE) return false;
        return (bool) Simple_MCP::opt('enabled', true) && (bool) Simple_MCP::opt('oauth_enabled', false);
    }

    /** Чи отримують OAuth-підключення wp_cli (у межах ролі). Типово — так. */
    static function wp_cli_allowed() {
        return (bool) Simple_MCP::opt('oauth_wp_cli', true);
    }

    /** Чи дозволені будь-які адреси повернення (типово — лише відомих агентів) */
    static function any_redirect() {
        return (bool) Simple_MCP::opt('oauth_any_redirect', false);
    }

    // ── Адреси ────────────────────────────────────────────────────────────

    static function endpoint_path() {
        return trim((string) Simple_MCP::opt('path', 'simple-mcp'), '/');
    }

    /** issuer = адреса сайту (сервер авторизації) */
    static function issuer() {
        return untrailingslashit(home_url());
    }

    /** Захищений ресурс = адреса MCP-ендпоінта */
    static function resource() {
        return untrailingslashit(home_url('/' . self::endpoint_path()));
    }

    /** Двері OAuth живуть під шляхом ендпоінта: <ендпоінт>/oauth/{register,token,authorize} */
    static function door_url($door) {
        return self::resource() . '/oauth/' . $door;
    }

    /** Шлях сайту від кореня хоста ('' для сайту в корені; 'site2' для підкаталогу чи мультисайту за шляхами) */
    static function home_path() {
        $p = wp_parse_url(home_url(), PHP_URL_PATH);
        return is_string($p) ? trim($p, '/') : '';
    }

    static function resource_metadata_url() {
        return home_url('/.well-known/oauth-protected-resource/' . self::endpoint_path());
    }

    static function resource_metadata() {
        return [
            'resource'                 => self::resource(),
            'authorization_servers'    => [self::issuer()],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) ?: 'Simple MCP',
        ];
    }

    static function server_metadata() {
        return [
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => self::door_url('authorize'),
            'token_endpoint'                        => self::door_url('token'),
            'registration_endpoint'                 => self::door_url('register'),
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => self::AUTH_METHODS,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Ресурс, який назвав клієнт, у канонічному вигляді — або null, якщо це інший сервер.
     * Відсутній параметр — цей ресурс: клієнт старший за RFC 8707, а не просить чужий сервер.
     */
    static function canonical_resource($value) {
        $asked = rtrim(self::field($value), '/');
        if ($asked === '' || $asked === self::resource() || $asked === self::issuer()) return self::resource();
        return null;
    }

    // ── Дрібні хелпери ────────────────────────────────────────────────────

    /** Одне рядкове значення з запиту (повторений ключ дає масив — тоді порожньо) */
    static function field($value) {
        return is_string($value) ? $value : '';
    }

    static function hash($value) {
        return hash('sha256', (string) $value);
    }

    /** base64url без padding з $bytes випадкових байтів */
    static function random($bytes) {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Однорядковий текст від клієнта: без керівних і форматних символів (bidi-перевертання назви), до $max символів */
    static function text($value, $max) {
        if (!is_string($value)) return '';
        $s = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $value);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
    }

    /** PKCE S256: BASE64URL(SHA256(verifier)) === challenge */
    static function pkce_ok($verifier, $challenge) {
        $verifier = self::field($verifier);
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)) return false;
        $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals((string) $challenge, $calc);
    }

    static function is_access_token($value) {
        return is_string($value) && strpos($value, self::ACCESS_PREFIX) === 0;
    }

    // ── Адреси повернення ─────────────────────────────────────────────────

    /**
     * Чому адресу не можна зареєструвати — або '', якщо можна (без політики «лише відомі агенти»).
     * Лише символи RFC 3986 і без userinfo: PHP і браузер мусять бачити той самий хост. Інакше
     * https://attacker.example\@claude.ai/cb для parse_url — хост claude.ai (зелена позначка «Claude»),
     * а браузер за WHATWG іде на attacker.example і несе туди код (рев'ю 11.11.2026).
     */
    static function redirect_problem($uri) {
        if (!is_string($uri) || $uri === '' || strlen($uri) > self::URI_MAX) return 'invalid';
        if (strpos($uri, '#') !== false) return 'fragment';
        if (preg_match('~[^A-Za-z0-9\-._\~:/?\[\]@!$&\'()*+,;=%]~', $uri)) return 'invalid';
        $p = wp_parse_url($uri);
        if (!is_array($p) || empty($p['scheme'])) return 'invalid';
        if (isset($p['user']) || isset($p['pass'])) return 'invalid';
        if (isset($p['fragment'])) return 'fragment';
        $scheme = strtolower($p['scheme']);
        if ($scheme === 'https') return !empty($p['host']) ? '' : 'invalid';
        if ($scheme === 'http') return self::is_loopback($p['host'] ?? '') ? '' : 'http';
        if (in_array($scheme, self::BAD_SCHEMES, true)) return 'scheme';
        if (!preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme)) return 'invalid';
        return '';
    }

    static function is_loopback($host) {
        $host = strtolower((string) $host);
        return in_array($host, self::LOOPBACK, true) || $host === '::1';
    }

    /** Відомі хости (фільтр simple_mcp_oauth_known_hosts додає свої: host => [назва, піддомени]) */
    static function known_hosts() {
        $hosts = apply_filters('simple_mcp_oauth_known_hosts', self::KNOWN_HOSTS);
        return is_array($hosts) ? $hosts : self::KNOWN_HOSTS;
    }

    /**
     * Назва відомого агента, якому належить адреса, або ''. https — хост зі списку; loopback-http і
     * власна схема застосунку — «Агент на цьому комп'ютері» (Codex, Claude Code, Cursor…).
     */
    static function known_agent($uri) {
        if (self::redirect_problem($uri) !== '') return '';
        $p = wp_parse_url((string) $uri);
        if (!is_array($p) || empty($p['scheme'])) return '';
        $scheme = strtolower($p['scheme']);
        $host   = strtolower((string) ($p['host'] ?? ''));
        if ($scheme === 'http' || $scheme === 'https') {
            if (self::is_loopback($host)) return 'Агент на цьому комп\'ютері';
            if ($scheme !== 'https' || $host === '') return '';
            foreach (self::known_hosts() as $known => $info) {
                $known = strtolower((string) $known);
                $name  = is_array($info) ? (string) ($info[0] ?? $known) : (string) $info;
                $sub   = is_array($info) && !empty($info[1]);
                if ($host === $known || ($sub && substr($host, -strlen('.' . $known)) === '.' . $known)) return $name;
            }
            return '';
        }
        if (self::redirect_problem($uri) !== '') return '';
        // власна схема — відома лише як зворотне DNS-ім'я (com.example.app) або схема знайомого застосунку
        return (strpos($scheme, '.') !== false || in_array($scheme, self::APP_SCHEMES, true)) ? 'Застосунок на цьому комп\'ютері' : '';
    }

    /** Чи пускає адресу політика сайту: відомі агенти або «будь-які» */
    static function redirect_allowed($uri) {
        if (self::redirect_problem($uri) !== '') return false;
        return self::any_redirect() || self::known_agent($uri) !== '';
    }

    /** Зареєстрована адреса — або та сама loopback на іншому порту (RFC 8252 §7.3) */
    static function redirect_matches(array $registered, $presented) {
        if (!is_string($presented) || $presented === '') return false;
        if (in_array($presented, $registered, true)) return true;
        $a = wp_parse_url($presented);
        if (!is_array($a) || strtolower($a['scheme'] ?? '') !== 'http' || !self::is_loopback($a['host'] ?? '') || isset($a['fragment'])) {
            return false;
        }
        foreach ($registered as $r) {
            $b = wp_parse_url((string) $r);
            if (!is_array($b) || strtolower($b['scheme'] ?? '') !== 'http') continue;
            if (strtolower($b['host'] ?? '') === strtolower($a['host'] ?? '')
                && ($b['path'] ?? '') === ($a['path'] ?? '') && ($b['query'] ?? '') === ($a['query'] ?? '')) {
                return true;
            }
        }
        return false;
    }

    /** Куди піде код, як це прочитає людина: хост або схема застосунку */
    static function destination($uri) {
        $p = wp_parse_url((string) $uri);
        if (!is_array($p) || empty($p['scheme'])) return '';
        $scheme = strtolower($p['scheme']);
        if ($scheme === 'http' || $scheme === 'https') return strtolower((string) ($p['host'] ?? ''));
        return $scheme;
    }

    /** Відповідь клієнту: його адреса з полями в query і завжди iss (RFC 9207) */
    static function answer_url($redirect_uri, array $fields) {
        $fields['iss'] = self::issuer();
        $add = [];
        foreach ($fields as $k => $v) {
            if ($v !== null && $v !== '') $add[$k] = (string) $v;
        }
        $sep = strpos($redirect_uri, '?') === false ? '?' : '&';
        return $redirect_uri . $sep . http_build_query($add, '', '&', PHP_QUERY_RFC3986);
    }

    // ── Таблиці ───────────────────────────────────────────────────────────

    static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'simple_mcp_oauth_' . $name;
    }

    static function tables() {
        return ['clients', 'grants', 'codes', 'tokens'];
    }

    /** SQL для dbDelta. Часи — unix-секунди (int), без часових поясів. */
    static function schema() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        [$clients, $grants, $codes, $tokens] = array_map([__CLASS__, 'table'], self::tables());
        return [
            "CREATE TABLE $clients (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(64) NOT NULL DEFAULT '',
            client_name varchar(120) NOT NULL DEFAULT '',
            redirect_uris text NOT NULL,
            auth_method varchar(24) NOT NULL DEFAULT 'none',
            secret_hash char(64) NULL,
            created_at int(10) unsigned NOT NULL DEFAULT 0,
            created_ip varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id),
            KEY created_at (created_at)
            ) $c;",
            "CREATE TABLE $grants (
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            key_id varchar(32) NOT NULL DEFAULT '',
            client_id bigint(20) unsigned NOT NULL DEFAULT 0,
            redirect_host varchar(255) NOT NULL DEFAULT '',
            created_at int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (user_id,key_id),
            KEY client_user (client_id,user_id)
            ) $c;",
            "CREATE TABLE $codes (
            code_hash char(64) NOT NULL DEFAULT '',
            client_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            key_id varchar(32) NOT NULL DEFAULT '',
            key_name varchar(120) NOT NULL DEFAULT '',
            redirect_uri varchar(500) NOT NULL DEFAULT '',
            code_challenge varchar(64) NOT NULL DEFAULT '',
            resource varchar(500) NOT NULL DEFAULT '',
            expires_at int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (code_hash),
            KEY expires_at (expires_at)
            ) $c;",
            "CREATE TABLE $tokens (
            token_hash char(64) NOT NULL DEFAULT '',
            kind varchar(10) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            key_id varchar(32) NOT NULL DEFAULT '',
            client_id bigint(20) unsigned NOT NULL DEFAULT 0,
            resource varchar(500) NOT NULL DEFAULT '',
            expires_at int(10) unsigned NOT NULL DEFAULT 0,
            used_at int(10) unsigned NULL,
            PRIMARY KEY  (token_hash),
            KEY grant_kind (user_id,key_id,kind),
            KEY expires_at (expires_at)
            ) $c;",
        ];
    }

    static function create_tables() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::schema());
    }

    /** Чи всі таблиці на місці (міграція могла ще не відпрацювати — див. Simple_MCP::maybe_upgrade) */
    static function tables_ready() {
        global $wpdb;
        foreach (self::tables() as $n) {
            $t = self::table($n);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) !== $t) return false;
        }
        return true;
    }

    /** Створити таблиці, якщо їх немає. true — готово. */
    static function ensure_tables() {
        if (self::tables_ready()) return true;
        self::create_tables();
        return self::tables_ready();
    }

    // ── Реєстрація клієнтів (RFC 7591) ────────────────────────────────────

    /** Відмова: error/description — для клієнта, reason і решта — лише для журналу */
    private static function fail($error, $description, array $more = []) {
        return ['ok' => false, 'error' => $error, 'description' => $description] + $more;
    }

    /** @return array ['ok'=>true,'client'=>[…]] або ['ok'=>false,'error','description','reason'?] */
    static function register($meta, $ip = '') {
        global $wpdb;
        $meta = is_array($meta) && !wp_is_numeric_array($meta) ? $meta : [];
        $uris = $meta['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > self::REDIRECTS_MAX || !wp_is_numeric_array($uris)) {
            return self::fail('invalid_redirect_uri', 'redirect_uris: one to ' . self::REDIRECTS_MAX . ' addresses.');
        }
        foreach ($uris as $uri) {
            $problem = self::redirect_problem($uri);
            if ($problem !== '') {
                return self::fail('invalid_redirect_uri', $problem === 'http'
                    ? 'redirect_uris: plain http only for a loopback address (127.0.0.1, localhost, [::1]).'
                    : 'redirect_uris: ' . substr((string) (is_string($uri) ? $uri : wp_json_encode($uri)), 0, 80) . ' is not an address a code can be sent to.');
            }
            if (!self::redirect_allowed($uri)) {
                return self::fail('invalid_redirect_uri',
                    'redirect_uris: this site lets only known AI agents connect (ChatGPT, Claude, Gemini, apps on the person\'s computer); '
                    . self::destination($uri) . ' is not one of them. The site administrator can allow any address in Simple MCP settings.',
                    ['reason' => 'redirect ' . self::destination($uri) . ' is not a known agent']);
            }
        }
        $method = array_key_exists('token_endpoint_auth_method', $meta) && $meta['token_endpoint_auth_method'] !== null
            ? (string) (is_scalar($meta['token_endpoint_auth_method']) ? $meta['token_endpoint_auth_method'] : '') : 'none';
        if (!in_array($method, self::AUTH_METHODS, true)) {
            return self::fail('invalid_client_metadata', 'token_endpoint_auth_method: one of ' . implode(', ', self::AUTH_METHODS) . '.');
        }
        if (isset($meta['grant_types']) && (!is_array($meta['grant_types']) || !in_array('authorization_code', $meta['grant_types'], true))) {
            return self::fail('invalid_client_metadata', 'grant_types: authorization_code is the only way in.');
        }
        if (isset($meta['response_types']) && (!is_array($meta['response_types']) || !in_array('code', $meta['response_types'], true))) {
            return self::fail('invalid_client_metadata', 'response_types: code.');
        }

        $recent = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('clients') . ' WHERE created_at > %d', time() - self::REGISTER_WINDOW));
        if ($recent >= self::REGISTER_GLOBAL_MAX) {
            return self::fail('too_many', 'Too many registrations on this site. Try again later.', ['reason' => $recent . ' registrations on the whole site in the last hour']);
        }

        $name      = self::text($meta['client_name'] ?? '', self::NAME_MAX);
        $client_id = self::CLIENT_PREFIX . self::random(18);
        $secret    = $method === 'none' ? null : self::SECRET_PREFIX . self::random(32);
        $uris      = array_values(array_map('strval', $uris));
        $ok = $wpdb->insert(self::table('clients'), [
            'client_id'     => $client_id,
            'client_name'   => $name,
            'redirect_uris' => (string) wp_json_encode($uris, JSON_UNESCAPED_SLASHES),
            'auth_method'   => $method,
            'secret_hash'   => $secret ? self::hash($secret) : null,
            'created_at'    => time(),
            'created_ip'    => substr((string) $ip, 0, 64),
        ]);
        if (!$ok) {
            return self::fail('server_error', 'The client could not be stored.', ['reason' => 'insert failed: ' . $wpdb->last_error]);
        }
        $client = [
            'client_id'                  => $client_id,
            'client_id_issued_at'        => time(),
            'redirect_uris'              => $uris,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $method,
        ];
        if ($name !== '') $client['client_name'] = $name;
        if ($secret) {
            $client['client_secret']            = $secret;
            $client['client_secret_expires_at'] = 0;
        }
        return ['ok' => true, 'client' => $client];
    }

    /** Зареєстрований клієнт за публічним id (з розібраними адресами) або null */
    static function client($client_id) {
        global $wpdb;
        $id = self::field($client_id);
        if (strpos($id, self::CLIENT_PREFIX) !== 0 || strlen($id) > 64) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, client_id, client_name, redirect_uris, auth_method, secret_hash, created_at FROM ' . self::table('clients') . ' WHERE client_id = %s',
            $id
        ), ARRAY_A);
        if (!$row || !hash_equals((string) $row['client_id'], $id)) return null; // колація без урахування регістру
        $uris = json_decode((string) $row['redirect_uris'], true);
        $row['redirect_uris'] = is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /**
     * Клієнт на token endpoint: публічний (none) — лише id, його доводить PKCE; конфіденційний
     * показує ще й секрет (Basic або поле форми). null — невідомий клієнт чи хибний секрет.
     */
    static function authenticate_client($client_id, $secret) {
        $row = self::client($client_id);
        if (!$row) return null;
        if ($row['auth_method'] === 'none') return $row;
        if (!is_string($secret) || $secret === '' || empty($row['secret_hash'])) return null;
        return hash_equals((string) $row['secret_hash'], self::hash($secret)) ? $row : null;
    }

    // ── Згода ─────────────────────────────────────────────────────────────

    /**
     * Перевірка запиту авторизації (query GET-а або ті самі поля з форми згоди):
     *   ['fatal' => 'client'|'redirect'|'policy'] — немає надійної адреси для відповіді: людині сторінка;
     *   ['client','redirect_uri','state','error' => [...]]  — помилка клієнта, відповідь на його адресу;
     *   ['client','redirect_uri','state','params' => [...]] — запит, який показуємо людині.
     */
    static function check_authorize(array $in) {
        $client = self::client($in['client_id'] ?? '');
        if (!$client) return ['fatal' => 'client'];
        $asked    = self::field($in['redirect_uri'] ?? '');
        $redirect = $asked !== '' ? $asked : (count($client['redirect_uris']) === 1 ? $client['redirect_uris'][0] : '');
        if ($redirect === '' || self::redirect_problem($redirect) !== '' || !self::redirect_matches($client['redirect_uris'], $redirect)) {
            return ['fatal' => 'redirect']; // і збережені до 2.6.0-фіксу адреси з \ чи userinfo
        }
        // політику могли звузити після реєстрації — код на таку адресу вже не віддаємо
        if (!self::redirect_allowed($redirect)) return ['fatal' => 'policy', 'client' => $client, 'redirect_uri' => $redirect];

        $state  = substr(self::field($in['state'] ?? ''), 0, 1000);
        $answer = function ($error, $desc) use ($client, $redirect, $state) {
            return ['client' => $client, 'redirect_uri' => $redirect, 'state' => $state, 'error' => ['error' => $error, 'error_description' => $desc]];
        };
        if (self::field($in['response_type'] ?? '') !== 'code') return $answer('unsupported_response_type', 'response_type must be code.');
        $challenge = self::field($in['code_challenge'] ?? '');
        if (self::field($in['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) {
            return $answer('invalid_request', 'PKCE is required: code_challenge with code_challenge_method S256.');
        }
        if (self::canonical_resource($in['resource'] ?? '') === null) return $answer('invalid_target', 'This server is ' . self::resource() . '.');

        return [
            'client'       => $client,
            'redirect_uri' => $redirect,
            'state'        => $state,
            'params'       => [
                'client_id'             => $client['client_id'],
                'redirect_uri'          => $redirect,
                'response_type'         => 'code',
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
                'state'                 => $state,
                'resource'              => self::field($in['resource'] ?? ''),
                'scope'                 => substr(self::field($in['scope'] ?? ''), 0, 200),
            ],
        ];
    }

    /** Живий ключ користувача (іменований, не легасі) або null */
    static function live_key($user_id, $key_id) {
        if (!$user_id || $key_id === '' || !class_exists('Simple_MCP_Auth')) return null;
        foreach (Simple_MCP_Auth::stored_keys((int) $user_id) as $k) {
            if ($k['id'] === (string) $key_id) return $k;
        }
        return null;
    }

    /** Живе підключення цієї людини з цим клієнтом (його поновлює повторна згода) або null */
    static function grant_for($client_row_id, $user_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT key_id FROM ' . self::table('grants') . ' WHERE client_id = %d AND user_id = %d ORDER BY created_at DESC',
            (int) $client_row_id, (int) $user_id
        ), ARRAY_A);
        foreach ((array) $rows as $r) {
            $k = self::live_key($user_id, (string) $r['key_id']);
            if ($k) return ['key_id' => $k['id'], 'name' => $k['name']];
        }
        return null;
    }

    /** Чи нове підключення перевищить ліміт ключів користувача */
    static function at_limit($user_id) {
        return count(Simple_MCP_Auth::keys_for((int) $user_id)) >= Simple_MCP_Auth::MAX_KEYS;
    }

    /** Людина дозволила: одноразовий код для обміну */
    static function issue_code(array $client, $user_id, $redirect_uri, $challenge, $resource, $key_id = '', $key_name = '') {
        global $wpdb;
        $code = self::random(32);
        $ok = $wpdb->insert(self::table('codes'), [
            'code_hash'      => self::hash($code),
            'client_id'      => (int) $client['id'],
            'user_id'        => (int) $user_id,
            'key_id'         => (string) $key_id,
            'key_name'       => self::text($key_name, 60),
            'redirect_uri'   => (string) $redirect_uri,
            'code_challenge' => (string) $challenge,
            'resource'       => (string) self::canonical_resource($resource),
            'expires_at'     => time() + self::CODE_TTL,
        ]);
        return $ok ? $code : null;
    }

    // ── Токени ────────────────────────────────────────────────────────────

    /** Нова пара токенів або null, якщо записати не вдалося */
    private static function issue_pair($user_id, $key_id, $client_row_id, $resource) {
        global $wpdb;
        $access  = self::ACCESS_PREFIX . self::random(32);
        $refresh = self::REFRESH_PREFIX . self::random(32);
        $now     = time();
        foreach ([[$access, 'access', $now + self::ACCESS_TTL], [$refresh, 'refresh', $now + self::REFRESH_TTL]] as [$value, $kind, $exp]) {
            $ok = $wpdb->insert(self::table('tokens'), [
                'token_hash' => self::hash($value),
                'kind'       => $kind,
                'user_id'    => (int) $user_id,
                'key_id'     => (string) $key_id,
                'client_id'  => (int) $client_row_id,
                'resource'   => (string) $resource,
                'expires_at' => $exp,
            ]);
            if (!$ok) {
                $wpdb->delete(self::table('tokens'), ['token_hash' => self::hash($access)]);
                return null;
            }
        }
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh];
    }

    /** Відкликати всі токени підключення (ланцюжок refresh) — крадіжка чи нова згода */
    private static function revoke_chain($user_id, $key_id, $client_row_id) {
        global $wpdb;
        $wpdb->delete(self::table('tokens'), ['user_id' => (int) $user_id, 'key_id' => (string) $key_id, 'client_id' => (int) $client_row_id]);
    }

    /** Чи людина досі може мати MCP: користувач існує і його роль має групу mcp */
    private static function user_ok($user_id) {
        $user = get_userdata((int) $user_id);
        if (!$user) return null;
        $perms = Simple_MCP::user_perms($user);
        return empty($perms['mcp']) ? null : $user;
    }

    /** Підключення, що досі варте токенів: рядок grants, живий ключ, користувач із MCP-доступом */
    private static function live_grant($user_id, $key_id, $client_row_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT user_id, key_id, client_id, redirect_host FROM ' . self::table('grants') . ' WHERE user_id = %d AND key_id = %s AND client_id = %d',
            (int) $user_id, (string) $key_id, (int) $client_row_id
        ), ARRAY_A);
        if (!$row) return null;
        $key  = self::live_key($user_id, $key_id);
        $user = $key ? self::user_ok($user_id) : null;
        if (!$key || !$user) return null;
        return ['user_id' => (int) $user_id, 'key_id' => (string) $key_id, 'name' => $key['name'], 'host' => (string) $row['redirect_host'], 'user' => $user];
    }

    /**
     * authorization_code → перша пара токенів. Нове підключення створює ключ тут, а не на згоді:
     * код, який ніхто не обміняв, нічого не лишає.
     * @return array ['ok'=>true,'tokens','grant','created'] або відмова
     */
    static function exchange_code(array $client, array $in) {
        global $wpdb;
        $code = self::field($in['code'] ?? '');
        if ($code === '' || strlen($code) > 100) return self::fail('invalid_request', 'code is missing.', ['reason' => 'no code']);
        $h   = self::hash($code);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('codes') . ' WHERE code_hash = %s', $h), ARRAY_A);
        $bad = 'The code is unknown, spent or expired.';
        if (!$row) return self::fail('invalid_grant', $bad, ['reason' => 'unknown or already used code']);
        // Одноразовий за будь-якого результату: виграв той DELETE, що прибрав рядок
        $spent = $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('codes') . ' WHERE code_hash = %s', $h));
        $whose = ['user_id' => (int) $row['user_id']];
        if ((int) $spent !== 1) return self::fail('invalid_grant', $bad, ['reason' => 'the code was used at the same moment'] + $whose);
        if ((int) $row['expires_at'] < time()) return self::fail('invalid_grant', $bad, ['reason' => 'the code expired'] + $whose);
        if ((int) $row['client_id'] !== (int) $client['id']) return self::fail('invalid_grant', $bad, ['reason' => 'the code belongs to another client'] + $whose);
        $redirect = self::field($in['redirect_uri'] ?? '');
        if ($redirect !== '' && $redirect !== $row['redirect_uri']) {
            return self::fail('invalid_grant', 'redirect_uri differs from the authorization request.', ['reason' => 'redirect_uri differs'] + $whose);
        }
        if (!self::pkce_ok($in['code_verifier'] ?? '', $row['code_challenge'])) {
            return self::fail('invalid_grant', 'code_verifier does not match the code_challenge.', ['reason' => 'PKCE verifier mismatch'] + $whose);
        }
        if (isset($in['resource']) && self::canonical_resource($in['resource']) !== $row['resource']) {
            return self::fail('invalid_target', 'This server is ' . self::resource() . '.', ['reason' => 'another resource'] + $whose);
        }

        $uid     = (int) $row['user_id'];
        $grant   = $row['key_id'] !== '' ? self::live_grant($uid, $row['key_id'], (int) $client['id']) : null;
        $created = false;
        if (!$grant) {
            $user = self::user_ok($uid);
            if (!$user) return self::fail('invalid_grant', 'The account cannot use MCP.', ['reason' => 'the user is gone or the role lost MCP access'] + $whose);
            $host = substr(self::destination($row['redirect_uri']), 0, 255);
            $name = $row['key_name'] !== '' ? $row['key_name'] : ($client['client_name'] !== '' ? $client['client_name'] : 'MCP');
            $made = Simple_MCP_Auth::create_key($uid, $name, $uid, 'OAuth · ' . ($host !== '' ? $host : 'agent'));
            if (is_wp_error($made)) {
                return self::fail('invalid_grant', 'The account already has ' . Simple_MCP_Auth::MAX_KEYS . ' keys.', ['reason' => 'key limit: ' . $made->get_error_message()] + $whose);
            }
            $ok = $wpdb->insert(self::table('grants'), [
                'user_id'       => $uid,
                'key_id'        => (string) $made['id'],
                'client_id'     => (int) $client['id'],
                'redirect_host' => $host,
                'created_at'    => time(),
            ]);
            if (!$ok) {
                Simple_MCP_Auth::revoke_key_for($uid, (string) $made['id']); // ключ без підключення не займає місце в ліміті
                return self::fail('server_error', 'The connection could not be stored.', ['reason' => 'grant insert failed: ' . $wpdb->last_error] + $whose);
            }
            $grant   = ['user_id' => $uid, 'key_id' => (string) $made['id'], 'name' => (string) $made['name'], 'host' => $host, 'user' => $user];
            $created = true;
        } else {
            // Повторна згода починає новий ланцюжок: токени попереднього (можливо, вкраденого) більше не діють
            self::revoke_chain($uid, $grant['key_id'], (int) $client['id']);
        }
        $tokens = self::issue_pair($uid, $grant['key_id'], (int) $client['id'], $row['resource']);
        if ($tokens === null) {
            if ($created) Simple_MCP_Auth::revoke_key_for($uid, $grant['key_id']);
            return self::fail('server_error', 'The tokens could not be stored.', ['reason' => 'token insert failed: ' . $wpdb->last_error] + $whose);
        }
        return ['ok' => true, 'tokens' => $tokens, 'grant' => $grant, 'created' => $created];
    }

    /**
     * refresh_token → нова пара, старий refresh витрачено. Показаний знову протягом хвилини він ще
     * діє (клієнт, що оновився двічі одночасно, не втрачає підключення), потім — ніколи.
     */
    static function refresh(array $client, array $in) {
        global $wpdb;
        $presented = self::field($in['refresh_token'] ?? '');
        $refused   = function ($reason, array $more = []) {
            return self::fail('invalid_grant', 'The refresh token is unknown, spent or expired.', ['reason' => $reason] + $more);
        };
        if (strpos($presented, self::REFRESH_PREFIX) !== 0 || strlen($presented) > 200) return $refused('not a refresh token of this site');
        $h   = self::hash($presented);
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT user_id, key_id, client_id, resource, expires_at, used_at FROM ' . self::table('tokens') . " WHERE token_hash = %s AND kind = 'refresh'",
            $h
        ), ARRAY_A);
        if (!$row) return $refused('unknown refresh token: never issued here, or expired and purged');
        $whose = ['user_id' => (int) $row['user_id'], 'key_id' => (string) $row['key_id']];
        $now   = time();
        if ((int) $row['client_id'] !== (int) $client['id']) return $refused('the refresh token belongs to another client', $whose);
        if ((int) $row['expires_at'] < $now) return $refused('the refresh token expired', $whose);
        if ($row['used_at'] !== null && $now - (int) $row['used_at'] > self::REUSE_GRACE) {
            // Повторне пред'явлення після хвилини грації — ознака крадіжки: відкликаємо весь ланцюжок
            // (OAuth 2.1 §4.3.1); підключення лишається, людина просто підключає агента заново
            self::revoke_chain((int) $row['user_id'], (string) $row['key_id'], (int) $row['client_id']);
            return $refused('the refresh token was already used ' . ($now - (int) $row['used_at']) . ' s ago — it is single-use; all tokens of the connection were revoked', $whose);
        }
        if (isset($in['resource']) && self::canonical_resource($in['resource']) !== $row['resource']) {
            return self::fail('invalid_target', 'This server is ' . self::resource() . '.', ['reason' => 'another resource'] + $whose);
        }
        if ($row['resource'] !== self::resource()) {
            // шлях ендпоінта чи адреса сайту змінились: токени старого ресурсу ендпоінт не прийме — хай клієнт підключиться заново
            return $refused('the token was issued for ' . $row['resource'] . ', the endpoint is now ' . self::resource(), $whose);
        }
        $grant = self::live_grant((int) $row['user_id'], (string) $row['key_id'], (int) $client['id']);
        if (!$grant) {
            return self::fail('invalid_grant', 'The connection was revoked on the site.', ['reason' => 'the connection was revoked, or the user lost MCP access'] + $whose);
        }
        if ($row['used_at'] === null) {
            $wpdb->query($wpdb->prepare('UPDATE ' . self::table('tokens') . ' SET used_at = %d WHERE token_hash = %s AND used_at IS NULL', $now, $h));
        }
        $tokens = self::issue_pair($grant['user_id'], $grant['key_id'], (int) $client['id'], $row['resource']);
        if ($tokens === null) {
            return self::fail('server_error', 'The tokens could not be stored.', ['reason' => 'token insert failed: ' . $wpdb->last_error] + $whose);
        }
        return ['ok' => true, 'tokens' => $tokens, 'grant' => $grant, 'created' => false];
    }

    /**
     * Хто стоїть за access-токеном на ендпоінті: ['user_id','key_id'] або null (невідомий, прострочений,
     * для іншого ресурсу, підключення відкликане чи ключ видалено).
     */
    static function authenticate($presented) {
        global $wpdb;
        $value = self::field($presented);
        if (!self::is_access_token($value) || strlen($value) > 200) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT t.user_id, t.key_id, t.resource, t.expires_at FROM ' . self::table('tokens') . ' t
             JOIN ' . self::table('grants') . " g ON g.user_id = t.user_id AND g.key_id = t.key_id AND g.client_id = t.client_id
             WHERE t.token_hash = %s AND t.kind = 'access'",
            self::hash($value)
        ), ARRAY_A);
        if (!$row || (int) $row['expires_at'] < time() || $row['resource'] !== self::resource()) return null;
        if (!self::live_key((int) $row['user_id'], (string) $row['key_id'])) return null;
        return ['user_id' => (int) $row['user_id'], 'key_id' => (string) $row['key_id']];
    }

    // ── Відкликання і прибирання ──────────────────────────────────────────

    /** Ключі відкликано (профіль чи адмін): їхні підключення, коди й токени зникають одразу */
    static function forget_keys($user_id, array $key_ids) {
        global $wpdb;
        if (!$key_ids || !self::tables_exist_cached()) return;
        foreach ($key_ids as $kid) {
            foreach (['grants', 'tokens', 'codes'] as $n) {
                $wpdb->delete(self::table($n), ['user_id' => (int) $user_id, 'key_id' => (string) $kid]);
            }
        }
    }

    /** Користувача видалено: усе його OAuth */
    static function forget_user($user_id) {
        global $wpdb;
        if (!self::tables_exist_cached()) return;
        foreach (['grants', 'tokens', 'codes'] as $n) {
            $wpdb->delete(self::table($n), ['user_id' => (int) $user_id]);
        }
    }

    /** Таблиці є (раз на запит) — щоб відкликання ключа на сайті без міграції не сипало помилками БД */
    private static function tables_exist_cached() {
        static $ready = null;
        if ($ready === null) $ready = self::tables_ready();
        return $ready;
    }

    /** Щодня (simple_mcp_prune): прострочені коди й токени, підключення відкликаних ключів, клієнти без підключень старші за 30 днів */
    static function purge() {
        global $wpdb;
        if (!self::tables_ready()) return;
        $now = time();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('codes') . ' WHERE expires_at < %d', $now));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('tokens') . ' WHERE expires_at < %d', $now));
        // використані refresh живуть тиждень — довше їх повторне пред'явлення вже не розпізнати як крадіжку
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('tokens') . " WHERE kind = 'refresh' AND used_at IS NOT NULL AND used_at < %d", $now - self::USED_REFRESH_KEEP));
        foreach ((array) $wpdb->get_results('SELECT user_id, key_id FROM ' . self::table('grants'), ARRAY_A) as $g) {
            if (!self::live_key((int) $g['user_id'], (string) $g['key_id'])) {
                self::forget_keys((int) $g['user_id'], [(string) $g['key_id']]);
            }
        }
        $wpdb->query($wpdb->prepare(
            'DELETE c FROM ' . self::table('clients') . ' c LEFT JOIN ' . self::table('grants') . ' g ON g.client_id = c.id
             WHERE g.client_id IS NULL AND c.created_at < %d',
            $now - self::CLIENT_IDLE
        ));
    }

    /** Усі підключення сайту для екрана налаштувань: користувач, ключ, агент, хост, коли */
    static function connections() {
        global $wpdb;
        if (!self::tables_ready()) return [];
        $rows = $wpdb->get_results(
            'SELECT g.user_id, g.key_id, g.redirect_host, g.created_at, c.client_name FROM ' . self::table('grants') . ' g
             LEFT JOIN ' . self::table('clients') . ' c ON c.id = g.client_id ORDER BY g.created_at DESC LIMIT 200',
            ARRAY_A
        );
        $out = [];
        foreach ((array) $rows as $r) {
            $key = self::live_key((int) $r['user_id'], (string) $r['key_id']);
            if (!$key) continue;
            $out[] = [
                'user_id'   => (int) $r['user_id'],
                'key_id'    => (string) $r['key_id'],
                'name'      => $key['name'],
                'client'    => (string) $r['client_name'],
                'host'      => (string) $r['redirect_host'],
                'created'   => (int) $r['created_at'],
                'last_used' => (int) $key['last_used'],
                'last_ip'   => (string) $key['last_ip'],
            ];
        }
        return $out;
    }
}
