<?php
/**
 * MCP-транспорт: Streamable HTTP + JSON-RPC 2.0, повністю поза WP REST API.
 * Слухаємо власний шлях через do_parse_request.
 *
 * Протокол: initialize повертає версію клієнта, якщо вона є в SUPPORTED, інакше найновішу.
 * Заголовок MCP-Protocol-Version перевіряється (непідтримувана версія → 400); без нього,
 * за специфікацією, вважаємо 2025-03-26. JSON-RPC-батчі існують лише у 2025-03-26: для
 * новіших версій — 400, інакше не більше BATCH_MAX повідомлень, і кожен tools/call
 * батча списується з rate-limit. SSE-потоку немає: GET із валідним ключем → 405 Allow: POST;
 * будь-який не-POST без облікових даних (або з невалідними) отримує звичайний WP-404.
 * Фатальна помилка PHP усередині виклику перетворюється на JSON-RPC-помилку + рядок аудиту.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Endpoint {

    /** Найновіша підтримувана версія протоколу (відповідь initialize за замовчуванням) */
    const PROTOCOL = '2025-11-25';
    /** Підтримувані версії, від найновішої */
    const SUPPORTED = ['2025-11-25', '2025-06-18', '2025-03-26'];
    /** Версія, яку вважаємо за відсутності заголовка MCP-Protocol-Version (вимога специфікації) */
    const DEFAULT_VERSION = '2025-03-26';
    /** Перша версія без JSON-RPC-батчів */
    const NO_BATCH_SINCE = '2025-06-18';
    /** Максимум повідомлень в одному батчі (2025-03-26) */
    const BATCH_MAX = 10;

    /** Рівень output buffering до нашого ob_start (усе вище — наш буфер) */
    private static $ob_level = null;
    /** Відповідь уже відправлено (shutdown-обробник нічого не робить) */
    private static $responded = false;
    /** Повідомлення, що обробляється зараз: id, method, tool, args, start */
    private static $current = null;
    /** Уже готові відповіді батча (щоб фатальна помилка посередині не губила їх) */
    private static $batch = null;

    /** Хук do_parse_request: якщо шлях наш — обробляємо й виходимо, інакше не заважаємо WP */
    static function maybe_handle($do, $wp) {
        $ours = trim((string) Simple_MCP::opt('path', 'simple-mcp'), '/');
        // порожній шлях (напр. виставлений через wp option) збігся б із головною сторінкою
        if ($ours === '' || self::current_path() !== $ours) {
            return $do;
        }
        // Наш шлях: не кешувати ні сторінку, ні DB-запити (лічильники автентифікації читаємо свіжими)
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTCACHEDB'))   define('DONOTCACHEDB', true);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            // Без облікових даних або з невалідними — звичайний WP-404: шлях не підтверджуємо.
            if (Simple_MCP_Auth::bearer() === '') return $do;
            if (is_wp_error(Simple_MCP_Auth::check(false))) return $do;
            if (!self::origin_ok()) {
                self::emit(403, [], self::error(null, -32000, 'Forbidden: Origin is not allowed'));
            }
            // SSE-потоку (GET) і сесій (DELETE) немає — лише POST
            self::emit(405, ['Allow' => 'POST'], self::error(null, -32000, 'Method not allowed: this server accepts JSON-RPC over POST only (no SSE stream)'));
        }
        self::handle();
        exit;
    }

    /** Поточний шлях запиту без базового каталогу інсталяції */
    static function current_path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
        $uri = is_string($uri) ? $uri : '';
        $path = trim($uri, '/');
        $home = wp_parse_url(home_url(), PHP_URL_PATH);
        $home = is_string($home) ? trim($home, '/') : '';
        if ($home !== '') {
            // лише цілий сегмент: /blog/simple-mcp, але не /blogsimple-mcp
            if ($path === $home) {
                $path = '';
            } elseif (strpos($path, $home . '/') === 0) {
                $path = trim(substr($path, strlen($home) + 1), '/');
            }
        }
        return $path;
    }

    /** Обробка POST: увесь вивід — через emit(), сторонній вивід відкидається */
    static function handle() {
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // W3TC та ін.: не кешувати (і поза maybe_handle)
        if (!defined('DONOTCACHEDB'))   define('DONOTCACHEDB', true);   // лічильники читаємо свіжими
        self::start_guard();
        $raw = file_get_contents('php://input');
        [$status, $headers, $body] = self::process(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), is_string($raw) ? $raw : '');
        self::emit($status, $headers, $body);
    }

    /**
     * Власний буфер виводу + shutdown-обробник. Обробник WP для фатальних помилок (зареєстрований
     * раніше за нас) друкує сторінку з 'exit' => false у наш буфер; ми її відкидаємо й
     * відповідаємо JSON-RPC. Запускаємося з дії 'shutdown' з найменшим пріоритетом — ДО
     * wp_ob_end_flush_all (пріоритет 1), що інакше виплюнув би буфер клієнту; власна
     * register_shutdown_function — запасний шлях, якщо дія 'shutdown' не відпрацює.
     */
    static function start_guard() {
        if (self::$ob_level !== null) return;
        self::$ob_level  = ob_get_level();
        self::$responded = false;
        ob_start();
        add_action('shutdown', [__CLASS__, 'on_shutdown'], PHP_INT_MIN);
        register_shutdown_function([__CLASS__, 'on_shutdown']);
    }

    /**
     * Обробити запит без побічного виводу: повертає [HTTP-статус, заголовки, тіло|null].
     * null-тіло = відповідь без тіла (202). Викликається з handle() і з тестів (WP-CLI).
     */
    static function process($method, $raw) {
        self::$current = null;
        self::$batch   = null;

        if ($method !== 'POST') {
            return [405, ['Allow' => 'POST'], self::error(null, -32000, 'Method not allowed')];
        }
        if (!self::origin_ok()) {
            return [403, [], self::error(null, -32000, 'Forbidden: Origin is not allowed')];
        }

        // Автентифікація — на кожен POST
        $auth = Simple_MCP_Auth::check();
        if (is_wp_error($auth)) {
            $data    = $auth->get_error_data();
            $status  = (is_array($data) && isset($data['status'])) ? (int) $data['status'] : 401;
            $headers = (is_array($data) && isset($data['headers']) && is_array($data['headers'])) ? $data['headers'] : [];
            return [$status, $headers, self::error(null, -32001, $auth->get_error_message())];
        }

        $trim    = ltrim((string) $raw);
        $payload = json_decode((string) $raw, true);
        if ($trim === '' || json_last_error() !== JSON_ERROR_NONE) {
            return [400, [], self::error(null, -32700, 'Parse error')];
        }
        $version = self::header_version();

        if ($trim[0] === '[') {
            return self::process_batch($payload, $version);
        }
        if ($trim[0] !== '{') {
            return [400, [], self::error(null, -32600, 'Invalid Request: the body must be a JSON-RPC message object')];
        }

        // initialize узгоджує версію сам — заголовок перевіряємо для всіх наступних повідомлень
        $is_init = is_array($payload) && (($payload['method'] ?? null) === 'initialize');
        if ($version !== null && !$is_init && !in_array($version, self::SUPPORTED, true)) {
            return [400, [], self::unsupported_version($version)];
        }

        $credit = 0;
        [$kind, $res] = self::message($payload, false, $credit);
        if ($kind === 'request') return [200, [], $res];
        if ($kind === 'invalid') return [400, [], $res];
        return [202, [], null]; // нотифікація або відповідь клієнта: прийнято, без тіла
    }

    /** JSON-RPC-батч (лише протокол 2025-03-26): ліміт розміру, кожен tools/call — у rate-limit */
    static function process_batch($payload, $version) {
        if (!is_array($payload) || $payload === []) {
            return [400, [], self::error(null, -32600, 'Invalid Request: empty batch')];
        }
        if ($version !== null && !in_array($version, self::SUPPORTED, true)) {
            return [400, [], self::unsupported_version($version)];
        }
        $effective = $version ?? self::DEFAULT_VERSION;
        if (strcmp($effective, self::NO_BATCH_SINCE) >= 0) {
            return [400, [], self::error(null, -32600, 'Invalid Request: JSON-RPC batching is not supported in protocol version ' . $effective)];
        }
        if (count($payload) > self::BATCH_MAX) {
            return [400, [], self::error(null, -32600, 'Invalid Request: batch too large (max ' . self::BATCH_MAX . ' messages)')];
        }

        self::$batch = [];
        $credit = 1; // перший tools/call уже оплачено самим HTTP-запитом (check())
        foreach ($payload as $msg) {
            [, $res] = self::message($msg, true, $credit);
            if ($res !== null) self::$batch[] = $res;
        }
        $out = self::$batch;
        self::$batch = null;
        return $out ? [200, [], $out] : [202, [], null];
    }

    /**
     * Класифікувати й обробити одне повідомлення. Повертає [kind, response|null], де kind:
     * 'request' (є відповідь), 'notification' / 'response' (відповіді немає), 'invalid' (-32600).
     */
    static function message($m, $in_batch, &$credit) {
        if (!is_array($m) || $m === [] || array_is_list($m)) {
            return ['invalid', self::error(null, -32600, 'Invalid Request: expected a JSON-RPC message object')];
        }
        $has_id = array_key_exists('id', $m);
        $id_ok  = $has_id && (is_string($m['id']) || is_int($m['id']));
        $rid    = $id_ok ? $m['id'] : null;

        if (($m['jsonrpc'] ?? null) !== '2.0') {
            return ['invalid', self::error($rid, -32600, 'Invalid Request: "jsonrpc" must be "2.0"')];
        }
        if (!array_key_exists('method', $m)) {
            // Відповідь клієнта на запит сервера (ми їх не надсилаємо) — приймаємо й ігноруємо
            if (array_key_exists('result', $m) || array_key_exists('error', $m)) return ['response', null];
            return ['invalid', self::error($rid, -32600, 'Invalid Request: missing "method"')];
        }
        $method = $m['method'];
        if (!is_string($method) || $method === '') {
            return ['invalid', self::error($rid, -32600, 'Invalid Request: "method" must be a non-empty string')];
        }
        if (!$has_id) {
            self::dispatch($m); // нотифікація: виконуємо (якщо є що), але не відповідаємо
            return ['notification', null];
        }
        if (!$id_ok) {
            return ['invalid', self::error(null, -32600, 'Invalid Request: "id" must be a string or an integer')];
        }
        if (strpos($method, 'notifications/') === 0) {
            return ['invalid', self::error($rid, -32600, 'Invalid Request: notifications must not carry an "id"')];
        }
        if ($in_batch && $method === 'initialize') {
            return ['request', self::error($rid, -32600, 'Invalid Request: initialize must not be part of a JSON-RPC batch')];
        }
        if ($in_batch && $method === 'tools/call') {
            if ($credit > 0) {
                $credit--;
            } elseif (!Simple_MCP_Auth::charge_call()) {
                $p = isset($m['params']) && is_array($m['params']) ? $m['params'] : [];
                Simple_MCP_Audit::log(is_string($p['name'] ?? null) ? $p['name'] : 'tools/call', is_array($p['arguments'] ?? null) ? $p['arguments'] : [], 'rate_limited', ['detail' => 'batch element over the per-user rate limit']);
                return ['request', self::error($rid, -32000, 'Rate limit exceeded: retry in ' . Simple_MCP_Auth::retry_after(Simple_MCP_Auth::RATE_WINDOW) . ' s')];
            }
        }
        return ['request', self::dispatch($m)];
    }

    /** Виконати повідомлення: відповідь для запиту, null для нотифікації */
    static function dispatch($req) {
        if (!array_key_exists('id', $req)) {
            return null; // notifications/initialized, notifications/cancelled та будь-які інші
        }
        $id     = $req['id'];
        $method = (string) ($req['method'] ?? '');
        if (array_key_exists('params', $req) && $req['params'] !== null && !is_array($req['params'])) {
            return self::error($id, -32602, 'Invalid params: "params" must be an object');
        }
        $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];
        self::$current = ['id' => $id, 'method' => $method, 'tool' => null, 'args' => null, 'start' => microtime(true)];

        switch ($method) {
            case 'initialize':
                $asked = isset($params['protocolVersion']) && is_string($params['protocolVersion']) ? $params['protocolVersion'] : '';
                return self::result($id, [
                    'protocolVersion' => in_array($asked, self::SUPPORTED, true) ? $asked : self::PROTOCOL,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'simple-mcp', 'title' => 'Simple MCP', 'version' => SIMPLE_MCP_VERSION],
                    'instructions'    => self::instructions(),
                ]);

            case 'ping':
                return self::result($id, (object) []);

            case 'tools/list':
                return self::result($id, ['tools' => Simple_MCP_Tools::list_public()]);

            case 'tools/call':
                $name = $params['name'] ?? null;
                if (!is_string($name) || $name === '') {
                    return self::error($id, -32602, 'Invalid params: "name" must be a non-empty string');
                }
                if (array_key_exists('arguments', $params) && $params['arguments'] !== null && !is_array($params['arguments'])) {
                    return self::error($id, -32602, 'Invalid params: "arguments" must be an object');
                }
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                self::$current['tool'] = $name;
                self::$current['args'] = $args;
                $out = Simple_MCP_Tools::call($name, $args);
                self::$current['tool'] = null;
                if (is_wp_error($out)) {
                    return self::error($id, -32602, $out->get_error_message());
                }
                return self::result($id, $out);

            default:
                return self::error($id, -32601, 'Method not found: ' . $method);
        }
    }

    /** Origin (якщо є) має збігатися з хостом і портом сайту — захист від DNS rebinding */
    static function origin_ok() {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        if ($origin === '') return true;
        $o = wp_parse_url($origin);
        if (!is_array($o) || empty($o['scheme']) || empty($o['host'])) return false; // напр. "null"
        $want = self::origin_key($o);
        /** Фільтр: додаткові дозволені Origin (URL), напр. браузерний MCP-клієнт на іншому хості */
        $allowed = (array) apply_filters('simple_mcp_allowed_origins', [home_url(), site_url()]);
        foreach ($allowed as $a) {
            $p = wp_parse_url((string) $a);
            if (is_array($p) && !empty($p['host']) && self::origin_key($p) === $want) return true;
        }
        return false;
    }

    private static function origin_key($p) {
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $port   = isset($p['port']) ? (int) $p['port'] : ($scheme === 'http' ? 80 : 443);
        return strtolower((string) $p['host']) . ':' . $port;
    }

    /** Значення заголовка MCP-Protocol-Version або null, якщо його немає */
    static function header_version() {
        if (!isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'])) return null;
        $v = trim((string) $_SERVER['HTTP_MCP_PROTOCOL_VERSION']);
        return $v === '' ? null : $v;
    }

    private static function unsupported_version($v) {
        return self::error(null, -32600, 'Unsupported MCP-Protocol-Version "' . Simple_MCP_Audit::cut($v, 40) . '" (supported: ' . implode(', ', self::SUPPORTED) . ')');
    }

    /**
     * Shown to the AI client at connect time (MCP `instructions`). Encodes the must-know rules,
     * personalized to the authenticated key owner: identity, role limits and enabled tool groups.
     * Kept under ~2000 characters (Claude Code cuts longer instructions at 2048), the data-loss
     * warnings first.
     */
    static function instructions() {
        $perms   = Simple_MCP_Auth::current_perms();
        $blocks  = !empty($perms['blocks']);
        $ml      = !empty($perms['wploc']) ? Simple_MCP::multilingual_system() : null;
        $content = !empty($perms['content']);
        $cli     = !empty($perms['wp_cli']);
        $server  = !empty($perms['server_ops']);

        $r = [];
        $u = wp_get_current_user();
        if ($u && $u->ID) {
            $roles = implode(', ', array_map('translate_user_role', array_map(function ($slug) {
                return wp_roles()->roles[$slug]['name'] ?? $slug;
            }, (array) $u->roles)));
            $r[] = 'You are WordPress user "' . $u->user_login . '" (role: ' . ($roles ?: '—') . '). Every TYPED tool runs under this user\'s native capabilities; capability-denied errors are expected, not bugs. Tool groups the role lacks are hidden.';
        }
        if ($cli) {
            $r[] = 'wp_cli is a privileged subprocess that does NOT inherit this user\'s object-level capability checks (manage_options roles only): prefer typed tools. Pass its values literally and quoted and NEVER JSON-encode text — non-ASCII gets \uXXXX-escaped and is stored verbatim (a Cyrillic title would be saved as escape text); for human-text writes use update_post / create_post / acf_update.';
        } else {
            $r[] = 'wp_cli is not available to this user — use the typed tools.';
        }
        $r[] = 'Scope: CONTENT (pages/blocks, ACF, media, taxonomies, translations). Never edit theme/plugin source files (PHP/JS/CSS) here: themes ship via git/CI/CD, Simple MCP via its own updater.';
        if ($server) {
            $r[] = 'Server ops are ENABLED: you MAY edit wp-config directives and install/update/remove plugins or themes via wp_cli; ALWAYS confirm destructive ones (deleting ACF or another critical plugin, security/DB config) with the user first.';
        } elseif ($cli) {
            $r[] = 'Server ops (wp-config, plugin/theme install/update/delete) are DISABLED for this user; ask the site admin to grant "Server ops" if one is genuinely needed.';
        }
        if ($blocks) {
            $r[] = 'Page content is ACF-block data INLINE in post_content — never hand-write block JSON; use block_get / list_block_fields / block_update.';
        }
        $r[] = 'acf_update writes post/user/term/comment/options ACF fields' . ($blocks ? ', NOT fields inside blocks (block_update).' : '.');
        if ($ml) {
            $r[] = 'Multilingual (' . $ml . '): each language is a SEPARATE post/term ID linked by a trid — resolve the right one with wploc_get_translations before editing.';
        }
        if ($content) {
            $r[] = 'On an unfamiliar site call describe_site first (blocks, fields, options, post types, languages differ per site; cached 1 h, refresh:true after schema changes).';
        }
        $r[] = 'Typed post_content writes are byte-verified (require content_verified:true when returned) and keep a revision or backup (revision_list / revision_restore). After edits call cache_flush (post_id; a whole-site flush needs manage_options) when a page cache is active' . ($cli ? ' — not wp_cli "cache flush" (object cache only)' : '') . '.';
        return implode(' ', $r);
    }

    static function result($id, $result) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** JSON-RPC-помилка; без id, якщо його не вдалося прочитати (MCP: error response "has no id") */
    static function error($id, $code, $msg) {
        $e = ['jsonrpc' => '2.0'];
        if ($id !== null) $e['id'] = $id;
        $e['error'] = ['code' => (int) $code, 'message' => (string) $msg];
        return $e;
    }

    /** Надіслати відповідь і завершити запит. $body = null — без тіла (202). */
    static function emit($status, $headers = [], $body = null) {
        self::$responded = true;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // і для 403/405 з maybe_handle()
        if (self::$ob_level !== null) {
            while (ob_get_level() > self::$ob_level) ob_end_clean(); // сторонній вивід (notice тощо) не ламає JSON
        }
        if (!headers_sent()) {
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow');
            status_header((int) $status);
            foreach ((array) $headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($body !== null) {
            $json = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                $json = (string) wp_json_encode(self::error(self::$current['id'] ?? null, -32603, 'Internal error: the response is not JSON-encodable'));
            }
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo $json;
        }
        exit;
    }

    /** Сумісність: відповідь із тілом */
    static function send($status, $body) {
        self::emit($status, [], $body);
    }

    /**
     * Shutdown: якщо відповідь не відправлено (фатальна помилка PHP або exit()/wp_die()
     * всередині обробки) — відкидаємо надруковане, пишемо аудит і відповідаємо JSON-RPC
     * -32603 з id поточного запиту (у батчі — разом із уже готовими відповідями).
     */
    static function on_shutdown() {
        if (self::$responded) return;
        self::$responded = true;

        $err   = error_get_last();
        $fatal = is_array($err) && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        if ($fatal) {
            // memory-exhaustion: трохи місця, щоб сформувати відповідь і записати аудит
            $limit = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get('memory_limit')) : -1;
            if ($limit > 0) @ini_set('memory_limit', (string) ($limit + 64 * 1024 * 1024));
        }

        $printed = '';
        if (self::$ob_level !== null) {
            while (ob_get_level() > self::$ob_level) {
                $printed = (string) ob_get_clean() . $printed;
            }
        }

        if ($fatal) {
            $msg = (string) preg_replace('/\s*Stack trace:.*$/s', '', (string) $err['message']);
            $why = 'PHP fatal error: ' . str_replace(ABSPATH, '', $msg)
                . ' (' . basename((string) $err['file']) . ':' . (int) $err['line'] . ')';
        } else {
            $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($printed)));
            $why  = 'the request was terminated by exit()/wp_die()' . ($text !== '' ? ': ' . $text : '');
        }
        $why = Simple_MCP_Audit::cut($why, 600);

        $cur = self::$current;
        if (is_array($cur) && !empty($cur['tool'])) {
            Simple_MCP_Audit::log($cur['tool'], is_array($cur['args']) ? $cur['args'] : [], $fatal ? 'fatal' : 'aborted', [
                'duration_ms' => (int) round((microtime(true) - (float) $cur['start']) * 1000),
                'detail'      => $why,
            ]);
        }

        $resp = self::error(is_array($cur) ? $cur['id'] : null, -32603, 'Internal error: ' . $why);
        $body = is_array(self::$batch) ? array_merge(self::$batch, [$resp]) : $resp;
        if (!headers_sent()) {
            nocache_headers();
            status_header(is_array($cur) ? 200 : 500); // з id — звичайна JSON-RPC-помилка, яку клієнт розбере
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
