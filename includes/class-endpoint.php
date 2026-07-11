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
