<?php
/**
 * MCP-транспорт: Streamable HTTP + JSON-RPC 2.0, повністю поза WP REST API.
 * Слухаємо власний секретний шлях через do_parse_request.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Endpoint {

    const PROTOCOL = '2025-06-18';

    /** Хук do_parse_request: якщо шлях наш — обробляємо й виходимо, інакше не заважаємо WP */
    static function maybe_handle($do, $wp) {
        if (self::current_path() !== trim((string) Simple_MCP::opt('path', 'simple-mcp'), '/')) {
            return $do;
        }
        // GET без валідної автентифікації НЕ розкриваємо: віддаємо звичайний WP-404
        // (щоб угадування шляху не давало оракула «200 simple-mcp»).
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            if (is_wp_error(Simple_MCP_Auth::check())) {
                return $do; // нехай WP віддасть стандартний 404
            }
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow');
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'simple-mcp';
            exit;
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
        if ($home !== '' && strpos($path, $home) === 0) {
            $path = trim(substr($path, strlen($home)), '/');
        }
        return $path;
    }

    static function handle() {
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // GET обробляється в maybe_handle(); сюди потрапляє лише POST (та інші методи → 405)
        if ($method !== 'POST') {
            self::send(405, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Method not allowed']]);
        }

        // Автентифікація — на кожен POST
        $auth = Simple_MCP_Auth::check();
        if (is_wp_error($auth)) {
            $data   = $auth->get_error_data();
            $status = (is_array($data) && isset($data['status'])) ? $data['status'] : 401;
            if ($status === 401) header('WWW-Authenticate: Bearer');
            self::send($status, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => $auth->get_error_message()]]);
        }

        $raw     = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            self::send(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        }

        // Одиночний запит або батч
        $is_batch = array_is_list($payload) && $payload !== [];
        $requests = $is_batch ? $payload : [$payload];
        $responses = [];
        foreach ($requests as $req) {
            if (!is_array($req)) continue;
            $res = self::dispatch($req);
            if ($res !== null) $responses[] = $res;
        }

        if ($is_batch) {
            self::send(200, $responses);
        }
        if (empty($responses)) {
            status_header(202); // це була нотифікація
            return;
        }
        self::send(200, $responses[0]);
    }

    static function dispatch($req) {
        $id             = $req['id'] ?? null;
        $is_notification = !array_key_exists('id', $req);
        $method         = (string) ($req['method'] ?? '');
        $params         = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];

        switch ($method) {
            case 'initialize':
                return self::result($id, [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'simple-mcp', 'version' => SIMPLE_MCP_VERSION],
                    'instructions'    => self::instructions(),
                ]);

            case 'notifications/initialized':
            case 'notifications/cancelled':
                return null;

            case 'ping':
                return self::result($id, (object) []);

            case 'tools/list':
                return self::result($id, ['tools' => Simple_MCP_Tools::list_public()]);

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
                $out  = Simple_MCP_Tools::call($name, $args);
                if (is_wp_error($out)) {
                    return self::error($id, -32602, $out->get_error_message());
                }
                return self::result($id, $out);

            default:
                if ($is_notification) return null;
                return self::error($id, -32601, 'Method not found: ' . $method);
        }
    }

    /**
     * Shown to the AI client at connect time (MCP `instructions`). Encodes the must-know rules,
     * personalized to the authenticated key owner: identity, role limits and enabled tool groups.
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
            $r[] = 'You are authenticated as WordPress user "' . $u->user_login . '" (role: ' . ($roles ?: '—') . '). Every operation runs under THIS user\'s native WordPress capabilities — you cannot read/edit/publish/delete anything this user could not in wp-admin, and capability-denied errors are expected behavior, not bugs. Tool groups this user\'s role is not granted are hidden from tools/list entirely.';
        }
        $r[] = 'Simple MCP\'s main job is CONTENT (pages/blocks, ACF, media, taxonomies, translations). Theme & plugin CODE (editing PHP/JS/CSS files) is managed via git + CI/CD — never edit theme or plugin files here.';
        if (!$cli) {
            $r[] = 'Raw wp_cli is not available to this user (typed-only mode) — use the typed tools.';
        }
        if ($server) {
            $r[] = 'Server ops ARE ENABLED on this site: you MAY edit wp-config directives and install/update/remove whole plugins or themes via wp_cli (config and the plugin set legitimately differ per environment — only the theme is versioned). ALWAYS confirm DESTRUCTIVE server ops with the user first: deleting ACF or another critical plugin, or changing security/DB config.';
        } elseif ($cli) {
            $r[] = 'Server ops (wp-config directives, plugin/theme install/update/delete) are DISABLED for this user — those commands are blocked; ask the site admin to grant "Server ops" to this user\'s role in Simple MCP settings if one is genuinely needed.';
        }
        if ($blocks) {
            $r[] = 'Page content is ACF-block data stored INLINE in post_content — never hand-write block-delimiter JSON; use block_get / list_block_fields / block_update.';
        }
        $r[] = 'acf_update handles POST/user/term/OPTIONS ACF fields' . ($blocks ? ' but NOT fields inside blocks (use block_update for those).' : '.');
        if ($ml) {
            $r[] = 'This site is multilingual (' . $ml . '): each language is a SEPARATE post/term ID linked by a trid — resolve the right one with wploc_get_translations before editing.';
        }
        if ($content) {
            $r[] = 'On an unfamiliar site call describe_site first to learn its blocks, fields, options, post types and languages (they differ per site).';
        }
        $r[] = 'All content writes auto-create a revision and byte-verify (check content_verified:true). After edits, flush cache if a page cache is active.';
        if ($cli) {
            $r[] = 'Prefer typed tools over raw wp_cli. When you do call wp_cli, pass argument values literally and quoted and NEVER JSON-encode text — non-ASCII gets \uXXXX-escaped and is stored verbatim (a Cyrillic title would be saved as the literal escape text, not the letters); for any write carrying human text (titles, excerpts, field values) use update_post / create_post / acf_update. Flush page cache via wp_cli "cache flush" plus the W3 Total Cache flush when W3TC is active.';
        }
        return implode(' ', $r);
    }

    static function result($id, $result) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
    static function error($id, $code, $msg) {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }

    static function send($status, $body) {
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
