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

    /** Shown to the AI client at connect time (MCP `instructions`). Encodes the must-know rules. */
    static function instructions() {
        $blocks  = Simple_MCP::module_on('blocks');
        $ml      = Simple_MCP::module_on('wploc') ? Simple_MCP::multilingual_system() : null;
        $content = Simple_MCP::module_on('content');
        $wc      = Simple_MCP::module_on('wc') && class_exists('WP_LOC_WC');
        $mc      = Simple_MCP::module_on('mc') && class_exists('WP_LOC_MC');
        $seo     = Simple_MCP::module_on('seo') && class_exists('WP_LOC_AIOSEO') && function_exists('aioseo');

        $server = Simple_MCP::opt('allow_server_ops', false);

        $r = [];
        $r[] = 'Simple MCP\'s main job is CONTENT (pages/blocks, ACF, media, taxonomies, translations). Theme & plugin CODE (editing PHP/JS/CSS files) is managed via git + CI/CD — never edit theme or plugin files here.';
        if ($server) {
            $r[] = 'Server ops ARE ENABLED on this site: you MAY edit wp-config directives and install/update/remove whole plugins or themes via wp_cli (config and the plugin set legitimately differ per environment — only the theme is versioned). ALWAYS confirm DESTRUCTIVE server ops with the user first: deleting ACF or another critical plugin, or changing security/DB config.';
        } else {
            $r[] = 'Server ops (wp-config directives, plugin/theme install/update/delete) are DISABLED on this site — those commands are blocked; ask the user to enable "Server ops" in the plugin settings if one is genuinely needed.';
        }
        if ($blocks) {
            $r[] = 'Page content is ACF-block data stored INLINE in post_content — never hand-write block-delimiter JSON; use block_get / list_block_fields / block_update.';
        }
        $r[] = 'acf_update handles POST/user/term/OPTIONS ACF fields' . ($blocks ? ' but NOT fields inside blocks (use block_update for those).' : '.');
        if ($ml) {
            $r[] = 'This site is multilingual (' . $ml . '): each language is a SEPARATE post/term ID linked by a trid — resolve the right one with wploc_get_translations before editing.';
            $r[] = 'To translate content: translate_list finds untranslated posts/products, translate_get fetches all translatable text (incl. SEO) in one package, you translate the strings, translate_apply writes them into the correct target post (creates/links it automatically).';
        }
        if ($wc) {
            $r[] = 'WooCommerce product data (prices, stock, SKU, attributes, variations) is synced FROM the default-language product to its translations — edit it on the source product and run wc_sync_product afterwards; never edit synced meta per-language (wc_synced_meta_keys lists them).';
        }
        if ($mc) {
            $r[] = 'Multi-currency: per-currency price overrides live on the SOURCE (default-language) product/variation — mc_set_product_prices auto-resolves to it; exchange rates via mc_set_rate; the base currency is WooCommerce\'s own and cannot have a rate.';
        }
        if ($seo) {
            $r[] = 'SEO (AIOSEO) data is per post ID — each language has its own post ID with its own seo_get/seo_update; site-wide SEO string translations per language via seo_get_strings/seo_update_strings (MERGE semantics — only the keys you pass change).';
        }
        if ($content) {
            $r[] = 'On an unfamiliar site call describe_site first to learn its blocks, fields, options, post types and languages (they differ per site).';
        }
        $r[] = 'All content writes auto-create a revision and byte-verify (check content_verified:true). After edits, flush cache (wp_cli "cache flush" plus W3 Total Cache page flush) if a page cache is active. Prefer typed tools over raw wp_cli. When you do call wp_cli, pass argument values literally and quoted and NEVER JSON-encode text — non-ASCII gets \uXXXX-escaped and is stored verbatim (a Cyrillic title would be saved as the literal escape text, not the letters); for any write carrying human text (titles, excerpts, field values) use update_post / create_post / acf_update.';
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
