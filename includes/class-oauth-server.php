<?php
/**
 * HTTP-двері OAuth (логіка й сховище — Simple_MCP_OAuth). Ловляться тим самим do_parse_request, що й
 * ендпоінт (Simple_MCP_Endpoint::maybe_handle), і лише коли OAuth увімкнено в налаштуваннях —
 * інакше всі ці адреси відповідають як звичайні невідомі сторінки WordPress.
 *
 *   GET  /.well-known/oauth-protected-resource[/<шлях>]   RFC 9728
 *   GET  /.well-known/oauth-authorization-server[/…]      RFC 8414
 *   POST <шлях>/oauth/register                            RFC 7591, 20 на годину з адреси
 *   POST <шлях>/oauth/token                               authorization_code і refresh_token
 *   GET|POST <шлях>/oauth/authorize                       згода людини (вхід WordPress)
 *
 * ТИПОВІ АДРЕСИ теж: POST /register, POST /token і GET /authorize (→ <шлях>/oauth/authorize) — те, що
 * специфікація MCP 2025-03-26 велить клієнту без метаданих (конектор Claude ходив туди 09.11.2026 у
 * Memo, хоч метадані були). На сайті WordPress ці адреси можуть бути сторінками, тож їх беремо лише
 * тоді, коли запит очевидно OAuth: реєстрація з redirect_uris, token з grant_type, authorize з
 * client_id і response_type; решту віддаємо WordPress.
 *
 * Машинні двері (discovery, register, token) — без кук і CSRF, з відкритим CORS і no-store. Згода —
 * сторінка з входом WordPress (кука logged_in, nonce), не вбудовувана у фрейми. Відповідь на POST
 * згоди — сторінка з meta refresh, а не 302: редирект після POST на чужий сайт заблокувала б CSP
 * form-action 'self' (захисні плагіни).
 *
 * Журнал (Simple_MCP_Audit): кожна реєстрація, кожна відмова register/token з точною причиною (до
 * LOG_MAX з адреси за 15 хв; клієнт отримує загальну помилку), згода чи відмова людини, нове
 * підключення. Про нове підключення — лист людині (wp_mail) уже після відповіді клієнту.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_OAuth_Server {

    /** Що зробити після відповіді (лист), коли клієнт уже отримав токени */
    private static $after = null;

    /**
     * Чи це двері OAuth. Якщо так — відповідає й завершує запит (exit); інакше повертає false.
     * $path — шлях запиту без базового каталогу (Simple_MCP_Endpoint::current_path), $ours — шлях ендпоінта.
     */
    static function route($path, $ours) {
        if (!class_exists('Simple_MCP_OAuth') || !Simple_MCP_OAuth::enabled() || $ours === '') return false;
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $door = null;
        $via  = '';
        $home = Simple_MCP_OAuth::home_path();
        if ($path === '.well-known/oauth-protected-resource' || $path === '.well-known/oauth-protected-resource/' . $ours) {
            $door = 'resource';
        } elseif ($path === '.well-known/oauth-authorization-server'
            || ($home !== '' && ($path === '.well-known/oauth-authorization-server/' . $home || $path === '.well-known/openid-configuration'))) {
            // Метадані сервера лише для СВОГО issuer: сайт у корені — без суфікса; сайт у підкаталозі (мультисайт
            // за шляхами) — суфікс його шляху або <сайт>/.well-known/openid-configuration (третій варіант пошуку
            // за специфікацією MCP), щоб головний сайт мережі не відповідав чужим issuer
            $door = 'server';
        } elseif ($path === $ours . '/oauth/register') {
            $door = 'register';
        } elseif ($path === $ours . '/oauth/token') {
            $door = 'token';
        } elseif ($path === $ours . '/oauth/authorize') {
            $door = 'authorize';
        } elseif ($path === 'register' && $method === 'POST' && self::looks_like_registration()) {
            [$door, $via] = ['register', '/register'];
        } elseif ($path === 'token' && $method === 'POST' && self::looks_like_token_request()) {
            [$door, $via] = ['token', '/token'];
        } elseif ($path === 'authorize' && $method === 'GET' && isset($_GET['client_id'], $_GET['response_type'])) {
            $door = 'default_authorize';
        }
        if ($door === null) return false;

        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEDB'))   define('DONOTCACHEDB', true);

        try {
            if (!Simple_MCP_Auth::request_is_https() && !(defined('SIMPLE_MCP_ALLOW_INSECURE') && SIMPLE_MCP_ALLOW_INSECURE)) {
                if ($door === 'authorize') self::page(403, 'Потрібен HTTPS', '<p>Підключення агентів працює лише через HTTPS.</p>');
                self::json(403, ['error' => 'invalid_request', 'error_description' => 'HTTPS is required.']);
            }
            switch ($door) {
                case 'resource':          self::discovery(Simple_MCP_OAuth::resource_metadata()); break;
                case 'server':            self::discovery(Simple_MCP_OAuth::server_metadata()); break;
                case 'register':          self::register($via); break;
                case 'token':             self::token($via); break;
                case 'authorize':         self::authorize(); break;
                case 'default_authorize': self::redirect_to_consent(); break;
            }
        } catch (\Throwable $e) {
            self::journal_refusal($door, 'failed on our side — ' . substr($e->getMessage(), 0, 200));
            if ($door === 'authorize') self::page(500, 'Помилка', '<p>Не вдалося обробити запит. Спробуйте ще раз.</p>');
            self::json(500, ['error' => 'server_error', 'error_description' => 'Internal error.']);
        }
        return true; // недосяжно: кожна гілка завершує запит
    }

    // ── Розпізнавання типових адрес ───────────────────────────────────────

    private static function raw_body() {
        static $raw = null;
        if ($raw === null) {
            $raw = (string) file_get_contents('php://input');
        }
        return $raw;
    }

    /** JSON-тіло як масив або null */
    private static function json_body() {
        $raw = ltrim(self::raw_body());
        if ($raw === '' || $raw[0] !== '{') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function looks_like_registration() {
        $data = self::json_body();
        return is_array($data) && array_key_exists('redirect_uris', $data);
    }

    private static function looks_like_token_request() {
        return isset(self::form()['grant_type']);
    }

    /** Поля форми token endpoint: application/x-www-form-urlencoded або JSON */
    private static function form() {
        static $form = null;
        if ($form !== null) return $form;
        $post = wp_unslash($_POST);
        if (is_array($post) && $post) return $form = $post;
        $json = self::json_body();
        if ($json !== null) return $form = $json;
        $parsed = [];
        parse_str(self::raw_body(), $parsed);
        return $form = (is_array($parsed) ? $parsed : []);
    }

    // ── Відповіді ─────────────────────────────────────────────────────────

    private static function cors() {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version');
    }

    /** JSON-відповідь машинних дверей і кінець запиту (далі — відкладена дія, якщо є) */
    private static function json($status, $body, array $headers = []) {
        while (ob_get_level() > 0) @ob_end_clean();
        if (!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
            self::cors();
            status_header((int) $status);
            foreach ($headers as $k => $v) header($k . ': ' . $v);
            if ($body !== null) header('Content-Type: application/json; charset=utf-8');
        }
        if ($body !== null) echo wp_json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::finish();
    }

    /** Завершити запит; лист чи інша відкладена дія — після того, як клієнт отримав відповідь */
    private static function finish() {
        if (is_callable(self::$after)) {
            $after = self::$after;
            self::$after = null;
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                @flush();
            }
            try { $after(); } catch (\Throwable $e) { error_log('[simple-mcp] oauth: ' . $e->getMessage()); }
        }
        exit;
    }

    private static function method_guard($allow) {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            while (ob_get_level() > 0) @ob_end_clean();
            if (!headers_sent()) {
                self::cors();
                header('Access-Control-Max-Age: 600');
                status_header(204);
            }
            exit;
        }
        $ok = $allow === 'GET' ? in_array($method, ['GET', 'HEAD'], true) : $method === $allow;
        if (!$ok) self::json(405, ['error' => 'invalid_request', 'error_description' => 'Use ' . $allow . '.'], ['Allow' => $allow . ', OPTIONS']);
    }

    // ── Журнал ────────────────────────────────────────────────────────────

    /** Відмова дверей у журналі — перші LOG_MAX з адреси за 15 хв */
    private static function journal_refusal($door, $detail, $user_id = 0, $key_id = '') {
        if (!class_exists('Simple_MCP_Audit')) return;
        $bucket = Simple_MCP_Auth::ip_bucket(Simple_MCP_Auth::client_ip());
        if (Simple_MCP_Auth::counter_hit('ol', $bucket, Simple_MCP_OAuth::DENY_WINDOW) > Simple_MCP_OAuth::LOG_MAX) return;
        Simple_MCP_Audit::log('oauth_' . $door, ['door' => $door], 'denied', [
            'detail' => $detail, 'user_id' => (int) $user_id, 'key_id' => (string) $key_id,
        ]);
    }

    /** IP-allowlist сайту діє й на машинні двері (згода — у браузері людини, її не стосується) */
    private static function ip_allowed() {
        $allow = (array) Simple_MCP::opt('ip_allowlist', []);
        return !$allow || Simple_MCP_Auth::ip_in_list(Simple_MCP_Auth::client_ip(), $allow);
    }

    // ── Discovery ─────────────────────────────────────────────────────────

    private static function discovery(array $doc) {
        self::method_guard('GET');
        self::json(200, $doc);
    }

    // ── Реєстрація ────────────────────────────────────────────────────────

    private static function register($via) {
        self::method_guard('POST');
        $tag = $via !== '' ? ' · via ' . $via : '';
        if (!self::ip_allowed()) {
            self::journal_refusal('register', 'IP not in the allowlist' . $tag);
            self::json(403, ['error' => 'access_denied', 'error_description' => 'This address may not register clients.']);
        }
        $bucket = Simple_MCP_Auth::ip_bucket(Simple_MCP_Auth::client_ip());
        if (Simple_MCP_Auth::counter_hit('or', $bucket, Simple_MCP_OAuth::REGISTER_WINDOW) > Simple_MCP_OAuth::REGISTER_MAX) {
            self::journal_refusal('register', 'too many registrations from this address (' . Simple_MCP_OAuth::REGISTER_MAX . ' an hour)' . $tag);
            self::json(429, ['error' => 'invalid_request', 'error_description' => 'Too many registrations from this address. Try again later.'],
                ['Retry-After' => (string) Simple_MCP_Auth::retry_after(Simple_MCP_OAuth::REGISTER_WINDOW)]);
        }
        if (strlen(self::raw_body()) > 32768) {
            self::journal_refusal('register', 'the body is larger than 32 KB' . $tag);
            self::json(413, ['error' => 'invalid_request', 'error_description' => 'The body is too large.']);
        }
        $meta = self::json_body();
        if ($meta === null) {
            $type = (string) ($_SERVER['CONTENT_TYPE'] ?? 'no Content-Type');
            self::journal_refusal('register', 'the body is not a JSON object (' . substr($type, 0, 80) . ', ' . strlen(self::raw_body()) . ' bytes)' . $tag);
            self::json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'The body must be a JSON object.']);
        }
        if (!Simple_MCP_OAuth::ensure_tables()) {
            self::journal_refusal('register', 'the OAuth tables are missing and could not be created' . $tag);
            self::json(500, ['error' => 'server_error', 'error_description' => 'Internal error.']);
        }
        $label  = is_string($meta['client_name'] ?? null) && trim($meta['client_name']) !== '' ? substr(trim($meta['client_name']), 0, 80) : 'no name';
        $result = Simple_MCP_OAuth::register($meta, Simple_MCP_Auth::client_ip());
        if (!$result['ok']) {
            self::journal_refusal('register', $label . ': ' . $result['error'] . ' — ' . ($result['reason'] ?? $result['description']) . $tag);
            if ($result['error'] === 'too_many') {
                self::json(429, ['error' => 'invalid_request', 'error_description' => $result['description']],
                    ['Retry-After' => (string) Simple_MCP_Auth::retry_after(Simple_MCP_OAuth::REGISTER_WINDOW)]);
            }
            self::json($result['error'] === 'server_error' ? 500 : 400, ['error' => $result['error'], 'error_description' => $result['description']]);
        }
        $c     = $result['client'];
        $hosts = implode(', ', array_unique(array_filter(array_map(['Simple_MCP_OAuth', 'destination'], $c['redirect_uris']))));
        Simple_MCP_Audit::log('oauth_register', [
            'client_name' => $label, 'redirect' => $hosts, 'auth_method' => $c['token_endpoint_auth_method'], 'via' => $via ?: 'metadata',
        ], 'ok', ['user_id' => 0, 'key_id' => '', 'detail' => 'Зареєстровано клієнт OAuth: ' . $label . ' · ' . ($hosts ?: '—') . ' · ' . $c['token_endpoint_auth_method'] . $tag]);
        self::json(201, $c);
    }

    // ── Token endpoint ────────────────────────────────────────────────────

    /** Облікові дані клієнта з Authorization: Basic (RFC 6749 §2.3.1) або null */
    private static function basic_credentials() {
        $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') { $h = (string) $v; break; }
            }
        }
        if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim($h), $m)) return null;
        $pair = (string) base64_decode($m[1], true);
        $at   = strpos($pair, ':');
        if ($at === false) return null;
        return ['id' => urldecode(substr($pair, 0, $at)), 'secret' => urldecode(substr($pair, $at + 1))];
    }

    private static function token($via) {
        self::method_guard('POST');
        $tag = $via !== '' ? ' · via ' . $via : '';
        if (!self::ip_allowed()) {
            self::journal_refusal('token', 'IP not in the allowlist' . $tag);
            self::json(403, ['error' => 'access_denied', 'error_description' => 'This address may not use the token endpoint.']);
        }
        // 429 рахує лише клієнта, що не довів себе (401); використаний, прострочений чи невідомий код або
        // refresh шле з'єднання, яке втратило стан, а не вгадування — інакше спільні адреси Claude блокувались би
        $bucket = Simple_MCP_Auth::ip_bucket(Simple_MCP_Auth::client_ip());
        if (Simple_MCP_Auth::counter_peek('ot', $bucket, Simple_MCP_OAuth::DENY_WINDOW) >= Simple_MCP_OAuth::DENY_MAX) {
            self::journal_refusal('token', '429 — ' . Simple_MCP_OAuth::DENY_MAX . ' clients that failed to prove themselves from this address in 15 minutes' . $tag);
            self::json(429, ['error' => 'invalid_request', 'error_description' => 'Too many failed attempts. Try again later.'],
                ['Retry-After' => (string) Simple_MCP_Auth::retry_after(Simple_MCP_OAuth::DENY_WINDOW)]);
        }
        if (strlen(self::raw_body()) > 16384) {
            self::journal_refusal('token', 'the body is larger than 16 KB' . $tag);
            self::json(413, ['error' => 'invalid_request', 'error_description' => 'The body is too large.']);
        }

        $in    = self::form();
        $grant = is_string($in['grant_type'] ?? null) ? $in['grant_type'] : '';
        $basic = self::basic_credentials();
        $who   = '';
        $refuse = function ($status, $error, $desc, array $more = []) use ($grant, &$who, $basic, $bucket, $tag) {
            self::journal_refusal('token', ($grant ?: 'no grant_type') . ' · ' . ($who ?: 'no client_id') . ': ' . $error . ' — ' . ($more['reason'] ?? $desc) . $tag,
                (int) ($more['user_id'] ?? 0), (string) ($more['key_id'] ?? ''));
            $headers = [];
            if ($status === 401) {
                Simple_MCP_Auth::counter_hit('ot', $bucket, Simple_MCP_OAuth::DENY_WINDOW);
                if ($basic) $headers['WWW-Authenticate'] = 'Basic realm="simple-mcp"';
            }
            self::json($status, ['error' => $error, 'error_description' => $desc], $headers);
        };

        $client_id = $basic ? $basic['id'] : (is_string($in['client_id'] ?? null) ? $in['client_id'] : '');
        $who       = substr($client_id, 0, 64);
        if ($basic && is_string($in['client_id'] ?? null) && $in['client_id'] !== $basic['id']) {
            $refuse(400, 'invalid_request', 'client_id differs from the Basic credentials.');
        }
        if (!Simple_MCP_OAuth::tables_ready()) {
            $refuse(401, 'invalid_client', 'Unknown client, or a wrong secret. Register again.', ['reason' => 'the OAuth tables are missing']);
        }
        $secret = $basic ? $basic['secret'] : (is_string($in['client_secret'] ?? null) ? $in['client_secret'] : '');
        $client = Simple_MCP_OAuth::authenticate_client($client_id, $secret);
        if (!$client) {
            $known = $client_id !== '' ? Simple_MCP_OAuth::client($client_id) : null;
            $refuse(401, 'invalid_client', 'Unknown client, or a wrong secret. Register again.', [
                'reason' => $client_id === '' ? 'no client_id' : ($known ? 'wrong or missing secret (' . $known['auth_method'] . ')' : 'unknown client: never registered here, or deleted'),
            ]);
        }
        $who = ($client['client_name'] !== '' ? $client['client_name'] : 'no name') . ' (' . $client['client_id'] . ')';

        if ($grant !== 'authorization_code' && $grant !== 'refresh_token') {
            $refuse(400, 'unsupported_grant_type', 'authorization_code or refresh_token.');
        }
        $result = $grant === 'authorization_code'
            ? Simple_MCP_OAuth::exchange_code($client, $in)
            : Simple_MCP_OAuth::refresh($client, $in);
        if (!$result['ok']) {
            $refuse(400, $result['error'], $result['description'], $result);
        }

        if ($result['created']) {
            $g = $result['grant'];
            Simple_MCP_Audit::log('oauth_connect', [
                'agent' => $g['name'], 'client' => $client['client_name'], 'host' => $g['host'],
            ], 'ok', ['user_id' => $g['user_id'], 'key_id' => $g['key_id'], 'detail' => 'Підключено агента: ' . $g['name'] . ' · OAuth · ' . ($g['host'] ?: '—')]);
            // Лист — нове підключення; поновлене (повторна згода, refresh) — не новина
            self::$after = function () use ($g, $client) { self::notify($g, $client); };
        }
        self::json(200, $result['tokens']);
    }

    // ── Згода ─────────────────────────────────────────────────────────────

    private static function consent_url(array $query = []) {
        $url = Simple_MCP_OAuth::door_url('authorize');
        return $query ? $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
    }

    /** GET /authorize (типова адреса) → наша сторінка згоди з тим самим запитом */
    private static function redirect_to_consent() {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        while (ob_get_level() > 0) @ob_end_clean();
        nocache_headers();
        header('Location: ' . Simple_MCP_OAuth::door_url('authorize') . ($qs !== '' ? '?' . $qs : ''), true, 302);
        exit;
    }

    /** Поля запиту авторизації (з query або з форми згоди) */
    private static function request_fields(array $src) {
        $out = [];
        foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'resource', 'scope'] as $k) {
            if (isset($src[$k]) && is_string($src[$k])) $out[$k] = $src[$k];
        }
        return $out;
    }

    private static function authorize() {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
            self::page(405, 'Метод не підтримується', '<p>Ця адреса приймає лише GET і POST.</p>');
        }
        $post = $method === 'POST';
        $in   = self::request_fields($post ? wp_unslash($_POST) : wp_unslash($_GET));

        if (!Simple_MCP_OAuth::tables_ready()) {
            self::page(400, 'Агента не впізнано', '<p>Цей агент не зареєстрований на сайті. Почніть підключення в агенті заново.</p>');
        }
        $checked = Simple_MCP_OAuth::check_authorize($in);
        if (isset($checked['fatal'])) self::problem($checked);
        if (isset($checked['error'])) {
            if ($post) self::leave($checked, $checked['error']);
            self::redirect_out(Simple_MCP_OAuth::answer_url($checked['redirect_uri'], $checked['error'] + ['state' => $checked['state']]));
        }

        if (!is_user_logged_in()) {
            // Вхід WordPress (2FA, змінена адреса входу — як налаштовано на сайті) і назад на цю сторінку
            while (ob_get_level() > 0) @ob_end_clean();
            nocache_headers();
            wp_safe_redirect(wp_login_url(self::consent_url($checked['params'])));
            exit;
        }
        $user = wp_get_current_user();

        if ($post) {
            if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'simple_mcp_oauth_consent')) {
                self::page(403, 'Сторінка застаріла', '<p>Форма згоди застаріла. <a href="' . esc_url(self::consent_url($checked['params'])) . '">Відкрити її знову</a>.</p>');
            }
            $decision = (string) ($_POST['decision'] ?? '');
            $client   = $checked['client'];
            $host     = Simple_MCP_OAuth::destination($checked['redirect_uri']);
            if ($decision !== 'allow') {
                Simple_MCP_Audit::log('oauth_consent', ['client' => $client['client_name'], 'host' => $host, 'decision' => 'decline'], 'denied',
                    ['key_id' => '', 'detail' => 'Людина відмовила агенту «' . ($client['client_name'] ?: '—') . '» (' . $host . ')']);
                self::leave($checked, ['error' => 'access_denied', 'error_description' => 'The person declined.']);
            }
            $perms = Simple_MCP::user_perms($user);
            if (empty($perms['mcp'])) {
                self::leave($checked, ['error' => 'access_denied', 'error_description' => 'The account has no MCP access on this site.']);
            }
            $existing = Simple_MCP_OAuth::grant_for($client['id'], $user->ID);
            if (!$existing && Simple_MCP_OAuth::at_limit($user->ID)) {
                self::consent($checked, $user, null, true);
            }
            $name = Simple_MCP_OAuth::text(wp_unslash((string) ($_POST['agent_name'] ?? '')), 60);
            $code = Simple_MCP_OAuth::issue_code(
                $client, $user->ID, $checked['redirect_uri'], $checked['params']['code_challenge'], $checked['params']['resource'],
                $existing ? $existing['key_id'] : '',
                $existing ? $existing['name'] : ($name !== '' ? $name : self::default_name($checked))
            );
            if ($code === null) {
                self::leave($checked, ['error' => 'server_error', 'error_description' => 'The code could not be stored.']);
            }
            Simple_MCP_Audit::log('oauth_consent', ['client' => $client['client_name'], 'host' => $host, 'decision' => 'allow'], 'ok',
                ['key_id' => $existing ? $existing['key_id'] : '', 'detail' => 'Людина дозволила агенту «' . ($existing ? $existing['name'] : ($name ?: self::default_name($checked))) . '» (' . $host . ')']);
            self::leave($checked, ['code' => $code]);
        }

        $existing = Simple_MCP_OAuth::grant_for($checked['client']['id'], $user->ID);
        self::consent($checked, $user, $existing, !$existing && Simple_MCP_OAuth::at_limit($user->ID));
    }

    /** Назва агента за замовчуванням: що заявив клієнт, інакше — відомий агент за адресою */
    private static function default_name(array $checked) {
        $n = (string) $checked['client']['client_name'];
        if ($n !== '') return Simple_MCP_OAuth::text($n, 60);
        $known = Simple_MCP_OAuth::known_agent($checked['redirect_uri']);
        return $known !== '' ? $known : 'MCP';
    }

    // ── Сторінки ──────────────────────────────────────────────────────────

    /** Заголовки HTML-сторінок OAuth: без кешу, без фреймів (clickjacking), без Referer */
    private static function page_headers($status) {
        while (ob_get_level() > 0) @ob_end_clean();
        if (headers_sent()) return;
        nocache_headers();
        status_header((int) $status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    /** Повна сторінка в рамці картки; $inner — уже екранований HTML */
    private static function page($status, $title, $inner, $head = '') {
        self::page_headers($status);
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $icon = function_exists('get_site_icon_url') ? get_site_icon_url(96) : '';
        echo '<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow"><title>' . esc_html($title . ' — ' . $site) . '</title>' . $head
            . '<style>' . self::css() . '</style></head><body><main class="card">'
            . '<div class="site">' . ($icon ? '<img src="' . esc_url($icon) . '" alt="" width="40" height="40">' : '<span class="tile">' . esc_html(self::initial($site)) . '</span>')
            . '<span>' . esc_html($site) . '</span></div>'
            . $inner . '</main></body></html>';
        self::finish();
    }

    private static function initial($s) {
        $s = trim((string) $s);
        $c = function_exists('mb_substr') ? mb_substr($s, 0, 1) : substr($s, 0, 1);
        return function_exists('mb_strtoupper') ? mb_strtoupper($c) : strtoupper($c);
    }

    private static function css() {
        return 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f0f0f1;'
            . 'font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1d2327;padding:16px;box-sizing:border-box}'
            . '.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.06);max-width:560px;width:100%;padding:28px 28px 24px;box-sizing:border-box}'
            . '.site{display:flex;align-items:center;gap:10px;font-weight:600;color:#50575e;margin-bottom:18px}.site img{border-radius:8px}'
            . '.tile{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:8px;background:#1d2327;color:#fff;font-weight:700}'
            . 'h1{font-size:21px;line-height:1.3;margin:0 0 14px}p{margin:0 0 12px}ul{margin:0 0 14px;padding-left:20px}li{margin:4px 0}'
            . '.agent{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:13px;font-weight:600;margin-bottom:14px}'
            . '.ok{background:#e6f4ea;color:#137333}.warn{background:#fcf0e6;color:#9a4b00}.muted{color:#646970;font-size:13px}'
            . '.box{border:1px solid #dcdcde;border-radius:8px;padding:10px 14px;margin:0 0 14px;background:#f6f7f7}'
            . 'label{display:block;font-weight:600;font-size:13px;margin:4px 0 6px}input[type=text]{width:100%;box-sizing:border-box;padding:8px 10px;font:inherit;border:1px solid #8c8f94;border-radius:6px}'
            . '.actions{display:flex;flex-direction:row-reverse;gap:10px;margin-top:18px;flex-wrap:wrap}'
            . 'button,.btn{font:inherit;font-weight:600;padding:9px 18px;border-radius:6px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer;text-decoration:none;display:inline-block}'
            . 'button.secondary{background:#fff;color:#2271b1}button[disabled]{opacity:.5;cursor:not-allowed}'
            // «Дозволити» не клікається перші 0,7 с після показу: подвійний клік на чужій сторінці, що саме
            // відкрила згоду під курсором (double-clickjacking), не влучить у неї. Лише CSS — без скриптів.
            . 'button.arm{animation:smcp-arm .7s steps(1,end) both}@keyframes smcp-arm{from{pointer-events:none;opacity:.55}to{pointer-events:auto;opacity:1}}'
            . 'code{background:#f0f0f1;padding:1px 5px;border-radius:4px;font-size:13px;word-break:break-all}';
    }

    /** Сторінка без редиректу: невідомий клієнт, незареєстрована адреса чи адреса поза політикою сайту */
    private static function problem(array $checked) {
        $why = [
            'client'   => 'Цей агент не зареєстрований на сайті (або реєстрацію давно прибрано). Почніть підключення в агенті заново.',
            'redirect' => 'Агент попросив повернути відповідь на адресу, яку не реєстрував. Відповідь не надіслано.',
            'policy'   => 'Сайт дозволяє підключати лише відомих ШІ-агентів (ChatGPT, Claude, Gemini, застосунки на вашому комп\'ютері), а цей агент повертає відповідь на '
                . '«' . Simple_MCP_OAuth::destination($checked['redirect_uri'] ?? '') . '». Адміністратор сайту може дозволити будь-які адреси в налаштуваннях Simple MCP.',
        ];
        self::page(400, 'Підключення неможливе', '<h1>Підключення неможливе</h1><p>' . esc_html($why[$checked['fatal']] ?? $why['client']) . '</p>');
    }

    /** Відповідь клієнту після POST згоди: сторінка з meta refresh і кнопкою (не 302 — див. шапку) */
    private static function leave(array $checked, array $fields) {
        $url   = Simple_MCP_OAuth::answer_url($checked['redirect_uri'], $fields + ['state' => $checked['state']]);
        $agent = self::default_name($checked);
        $ok    = isset($fields['code']);
        // адресу перевірено при реєстрації (https, loopback або схема застосунку, без javascript:/data:) — esc_attr, бо esc_url ріже схеми застосунків
        $inner = '<h1>' . esc_html($ok ? 'Готово — повертаємося до «' . $agent . '»' : 'Повертаємося до «' . $agent . '»') . '</h1>'
            . '<p>' . esc_html($ok ? 'Підключення дозволено. Якщо агент не відкрився сам, натисніть «Продовжити».' : 'Підключення не дозволено.') . '</p>'
            . '<div class="actions"><a class="btn" href="' . esc_attr($url) . '">Продовжити</a></div>';
        self::page(200, $ok ? 'Підключення дозволено' : 'Підключення скасовано', $inner,
            '<meta http-equiv="refresh" content="0;url=' . esc_attr($url) . '">');
    }

    /** 302 на адресу клієнта (GET-запит авторизації з помилкою клієнта) */
    private static function redirect_out($url) {
        while (ob_get_level() > 0) @ob_end_clean();
        nocache_headers();
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** Екран згоди */
    private static function consent(array $checked, WP_User $user, $existing, $full) {
        $client   = $checked['client'];
        $redirect = $checked['redirect_uri'];
        $dest     = Simple_MCP_OAuth::destination($redirect);
        $known    = Simple_MCP_OAuth::known_agent($redirect);
        $name     = $existing ? $existing['name'] : self::default_name($checked);
        $claimed  = $client['client_name'] !== '' ? $client['client_name'] : ($known !== '' ? $known : 'MCP-клієнт');
        $site     = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

        $perms = Simple_MCP::user_perms($user);
        $has   = !empty($perms['mcp']);
        if (!Simple_MCP_OAuth::wp_cli_allowed()) {
            $perms['wp_cli'] = false;
            $perms['server_ops'] = false;
        }
        $labels  = Simple_MCP_User_Keys::perm_labels();
        $granted = [];
        foreach (Simple_MCP::PERMS as $p) {
            if (!empty($perms[$p])) $granted[] = $labels[$p];
        }
        $roles = implode(', ', array_map(function ($slug) {
            $r = wp_roles()->roles[$slug]['name'] ?? $slug;
            return translate_user_role($r);
        }, (array) $user->roles));

        $h  = '<h1>' . esc_html('Підключити «' . $claimed . '» до сайту «' . $site . '»?') . '</h1>';
        $h .= $known !== ''
            ? '<span class="agent ok">✓ ' . esc_html($known . ($dest !== '' && $known !== $dest ? ' · ' . $dest : '')) . '</span>'
            : '<span class="agent warn">⚠ ' . esc_html('Невідомий агент · відповідь піде на «' . $dest . '»') . '</span>';

        if (!$has) {
            $h .= '<p>' . esc_html('Ваша роль на цьому сайті не має доступу до MCP, тож агента підключити не можна. Зверніться до адміністратора сайту.') . '</p>';
        } else {
            $h .= '<p>Агент діятиме <strong>від вашого імені</strong> з правами вашої ролі:</p><ul>'
                . '<li>' . esc_html('користувач ' . $user->user_login . ($roles !== '' ? ' (' . $roles . ')' : '')) . '</li>'
                . '<li>' . esc_html('інструменти: ' . implode(' · ', $granted)) . '</li>'
                . (!empty($perms['wp_cli']) ? '<li><strong>' . esc_html('включно з WP-CLI — повний доступ до сайту й бази даних') . '</strong></li>' : '')
                . '<li>' . esc_html('кожен виклик пишеться в журнал; відкликати підключення можна будь-коли в профілі (Simple MCP — ключі доступу)') . '</li></ul>';
            $h .= '<p class="muted">' . esc_html('Відповідь піде на: ' . ($dest !== '' ? $dest : $redirect) . '. Назва агента — лише те, що він сам про себе заявив; довіряйте адресі.') . '</p>';
        }
        $h .= '<p class="muted">' . esc_html('Ви увійшли як ' . $user->user_login . ($user->user_email ? ' (' . $user->user_email . ')' : '') . '.')
            . ' <a href="' . esc_url(wp_logout_url(self::consent_url($checked['params']))) . '">Не ви?</a></p>';

        $form  = '<form method="post" action="' . esc_url(self::consent_url()) . '">' . wp_nonce_field('simple_mcp_oauth_consent', '_wpnonce', false, false);
        foreach ($checked['params'] as $k => $v) {
            $form .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
        }
        if ($has && $existing) {
            $h .= '<div class="box">' . esc_html('Цей агент уже підключений як «' . $existing['name'] . '» — підключення буде поновлено, нового ключа не з\'явиться.') . '</div>';
        } elseif ($has && $full) {
            $h .= '<div class="box">' . esc_html('У вас уже ' . Simple_MCP_Auth::MAX_KEYS . ' ключів і підключень — відкличте непотрібне в профілі, щоб підключити нового агента.') . '</div>';
        } elseif ($has) {
            $form .= '<label for="agent_name">Назва агента</label><input type="text" id="agent_name" name="agent_name" maxlength="60" value="' . esc_attr($name) . '" autocomplete="off">';
        }
        $can   = $has && ($existing || !$full);
        $form .= '<div class="actions">'
            . '<button type="submit" name="decision" value="allow" class="arm"' . ($can ? '' : ' disabled') . '>Дозволити</button>'
            . '<button type="submit" name="decision" value="deny" class="secondary">Скасувати</button>'
            . '</div></form>';
        self::page(200, 'Підключення агента', $h . $form);
    }

    // ── Лист про нове підключення ─────────────────────────────────────────

    /** Лист людині про нове підключення (wp_mail); помилки пошти не зачіпають підключення */
    private static function notify(array $grant, array $client) {
        $user = $grant['user'] ?? get_userdata((int) $grant['user_id']);
        if (!$user || !is_email($user->user_email)) return;
        $site    = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $profile = admin_url('profile.php') . '#simple-mcp';
        $subject = sprintf('[%s] Підключено ШІ-агента «%s»', $site, $grant['name']);
        $lines = [
            sprintf('Вітаємо, %s!', $user->display_name ?: $user->user_login),
            '',
            sprintf('До вашого облікового запису «%s» на сайті %s через OAuth підключено ШІ-агента «%s».', $user->user_login, home_url('/'), $grant['name']),
            sprintf('Агент повертає відповіді на: %s', $grant['host'] !== '' ? $grant['host'] : '—'),
            sprintf('Коли: %s', wp_date(get_option('date_format') . ' H:i')),
            '',
            'Агент діє від вашого імені з правами вашої ролі, і кожен його виклик пишеться в журнал Simple MCP.',
            '',
            'Якщо це були не ви — відкличте підключення в профілі (розділ «Simple MCP — ключі доступу»):',
            $profile,
        ];
        $lines = apply_filters('simple_mcp_oauth_notice_lines', $lines, $grant, $client, $user);
        wp_mail($user->user_email, $subject, implode("\n", (array) $lines));
    }
}
