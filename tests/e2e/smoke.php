<?php
/**
 * End-to-end smoke test against a real WordPress, over HTTP, the way an MCP client talks to the site:
 * authentication, transport and protocol versions, the typed tools on a throwaway post, media upload and
 * wp_cli. Started by tests/e2e/run.sh (CI job "wordpress" on the lowest and the highest supported PHP).
 *
 *   php tests/e2e/smoke.php <endpoint-url> <key> <expected-plugin-version>
 *
 * Exit code 1 on the first failed check. Needs the curl extension.
 */
if (PHP_SAPI !== 'cli' || $argc < 4) {
    fwrite(STDERR, "usage: php tests/e2e/smoke.php <endpoint-url> <key> <expected-plugin-version>\n");
    exit(2);
}
[, $url, $key, $version] = $argv;
$run = bin2hex(random_bytes(3)); // unique names: the test can run again on the same site

$checks = 0;
function check($ok, $what, $context = null) {
    global $checks;
    $checks++;
    if ($ok) {
        echo "ok   $what\n";
        return;
    }
    echo "FAIL $what\n";
    if ($context !== null) {
        echo '     ' . (is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
    }
    exit(1);
}

/** One HTTP request: [status, lower-cased headers, raw body]. */
function http($method, $url, $body = null, array $headers = []) {
    $h  = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $h[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    if ($raw === false) {
        check(false, "$method $url reachable", curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, $h, (string) $raw];
}

/** JSON-RPC POST: [status, headers, decoded body|null, raw body]. $auth: true = the key, false = none, string = that token. */
function rpc($payload, array $extra_headers = [], $auth = true) {
    global $url, $key;
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream'], $extra_headers);
    if ($auth !== false) $headers[] = 'Authorization: Bearer ' . (is_string($auth) ? $auth : $key);
    [$status, $h, $raw] = http('POST', $url, is_string($payload) ? $payload : json_encode($payload), $headers);
    return [$status, $h, json_decode($raw, true), $raw];
}

$rpc_id = 100;
/** tools/call: returns [decoded result payload (array|string), the MCP result]. Fails on a JSON-RPC error. */
function tool($name, array $args = [], $expect_ok = true) {
    global $rpc_id;
    $id = ++$rpc_id;
    [$status, , $body, $raw] = rpc(
        ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]],
        ['MCP-Protocol-Version: 2025-11-25']
    );
    check($status === 200 && isset($body['result']), "tools/call $name answers 200 with a result", $status . ' ' . substr($raw, 0, 600));
    $res  = $body['result'];
    $text = $res['content'][0]['text'] ?? '';
    $data = json_decode($text, true);
    $out  = is_array($data) ? $data : $text;
    if ($expect_ok !== null) {
        check(empty($res['isError']) === $expect_ok, "$name " . ($expect_ok ? 'succeeds' : 'is refused'), substr($text, 0, 600));
    }
    return [$out, $res];
}

// ── Transport and authentication ───────────────────────────────────────────
$ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

[$s, $h] = rpc($ping, [], false);
check($s === 401 && stripos($h['www-authenticate'] ?? '', 'Bearer') === 0, 'POST without a key → 401 with WWW-Authenticate: Bearer', $s);

[$s] = rpc($ping, [], 'smcp-1-' . str_repeat('x', 64));
check($s === 401, 'POST with a wrong key → 401', $s);

[$s, $h] = http('GET', $url, null, ['Authorization: Bearer ' . $key]);
check($s === 405 && ($h['allow'] ?? '') === 'POST', 'GET with a key → 405 Allow: POST', $s);

[$s] = http('GET', $url);
[$s_other] = http('GET', preg_replace('~/[^/]*$~', '/no-such-page-' . bin2hex(random_bytes(4)), rtrim($url, '/')));
check($s === $s_other && $s !== 405, "GET without a key answers like any unknown page ($s), so the path is not confirmed", "$s vs $s_other");

[$s, , $b, $raw] = rpc('{"jsonrpc":"2.0","id":2,', []);
check($s === 400 && ($b['error']['code'] ?? 0) === -32700, 'broken JSON → 400 Parse error', $raw);

// ── Lifecycle ───────────────────────────────────────────────────────────────
[$s, , $b, $raw] = rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'initialize', 'params' => [
    'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'simple-mcp-ci', 'version' => '1'],
]]);
check($s === 200 && ($b['result']['protocolVersion'] ?? '') === '2025-11-25', 'initialize negotiates 2025-11-25', $raw);
check(($b['result']['serverInfo']['version'] ?? '') === $version, "serverInfo.version is $version", $b['result']['serverInfo'] ?? $raw);
check(isset($b['result']['capabilities']['tools']), 'initialize advertises tools', $b['result'] ?? $raw);
check(is_string($b['result']['instructions'] ?? null) && $b['result']['instructions'] !== '', 'initialize returns instructions', $b['result'] ?? $raw);

[$s, , , $raw] = rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['MCP-Protocol-Version: 2025-11-25']);
check($s === 202 && $raw === '', 'notifications/initialized → 202 without a body', $s . ' ' . $raw);

[$s, , $b, $raw] = rpc($ping, ['MCP-Protocol-Version: 2025-11-25']);
check($s === 200 && ($b['result'] ?? null) === [], 'ping → empty result', $raw);

[$s, , $b, $raw] = rpc($ping, ['MCP-Protocol-Version: 1999-01-01']);
check($s === 400, 'an unsupported MCP-Protocol-Version → 400', $s . ' ' . $raw);

[$s, , $b, $raw] = rpc([$ping, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping']], ['MCP-Protocol-Version: 2025-03-26']);
check($s === 200 && is_array($b) && count($b) === 2, 'a batch under 2025-03-26 → two responses', $raw);

[$s] = rpc([$ping], ['MCP-Protocol-Version: 2025-06-18']);
check($s === 400, 'a batch under 2025-06-18 → 400 (batching was removed)', $s);

// ── Tool list ───────────────────────────────────────────────────────────────
[$s, , $b, $raw] = rpc(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], ['MCP-Protocol-Version: 2025-11-25']);
$tools = [];
foreach ($b['result']['tools'] ?? [] as $t) $tools[$t['name']] = $t;
check($s === 200 && $tools, 'tools/list returns tools', $raw);
$expected = ['wp_cli', 'get_post', 'update_post', 'create_post', 'find_posts', 'render_post', 'safe_delete', 'set_post_terms',
    'term_list', 'term_create', 'term_update', 'revision_list', 'revision_restore', 'cache_flush', 'upload_media',
    'upload_begin', 'upload_chunk', 'upload_finish', 'block_get', 'block_insert', 'block_move', 'block_remove',
    'block_replace', 'block_batch', 'describe_site'];
check(!array_diff($expected, array_keys($tools)), 'the admin key sees every core, block and content tool', array_values(array_diff($expected, array_keys($tools))));
check(!isset($tools['wploc_get_translations']), 'wp-loc tools are hidden without a multilingual plugin');
foreach ($tools as $name => $t) {
    $ok = is_string($t['title'] ?? null) && is_string($t['description'] ?? null) && strlen($t['description']) <= 2048
        && ($t['inputSchema']['type'] ?? '') === 'object' && isset($t['annotations']['readOnlyHint']);
    check($ok, "tool $name has title, description ≤ 2048, an object inputSchema and annotations");
}

// ── Typed tools on a throwaway post ─────────────────────────────────────────
[$d] = tool('describe_site');
check(is_array($d), 'describe_site returns a site map', $d);

[$s, , $b] = rpc(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'no_such_tool', 'arguments' => new stdClass()]],
    ['MCP-Protocol-Version: 2025-11-25']);
check(isset($b['error']) || !empty($b['result']['isError']), 'an unknown tool is an error', $b);

[$d] = tool('create_post', ['title' => 'missing post_type'], false);

$p1 = "<!-- wp:paragraph -->\n<p>Привіт з PHP " . PHP_MAJOR_VERSION . "</p>\n<!-- /wp:paragraph -->";
[$d, $res] = tool('create_post', ['post_type' => 'post', 'title' => "Simple MCP smoke $run — тест", 'content' => $p1]);
$pid = (int) ($d['id'] ?? $d['post_id'] ?? 0);
check($pid > 0, 'create_post returns the new ID', $d);
check(($d['content_verified'] ?? null) === true, 'create_post verifies the saved body', $d);
check(isset($res['structuredContent']), 'object results carry structuredContent', array_keys($res));

[$d] = tool('get_post', ['id' => $pid]);
check(($d['title'] ?? '') === "Simple MCP smoke $run — тест" && strpos($d['content'] ?? '', 'Привіт з PHP') !== false, 'get_post reads back title and body (UTF-8 intact)', $d);
$etag = (string) ($d['etag'] ?? '');
check($etag !== '', 'get_post returns an etag', $d);

tool('update_post', ['id' => $pid, 'title' => 'stale write', 'if_match' => 'stale-etag'], false);
[$d] = tool('update_post', ['id' => $pid, 'title' => "Simple MCP smoke $run — оновлено", 'if_match' => $etag]);

[$d] = tool('block_get', ['post_id' => $pid]);
$blocks = $d['blocks'] ?? $d;
check(is_array($blocks) && count($blocks) === 1 && ($blocks[0]['blockName'] ?? '') === 'core/paragraph', 'block_get sees one core/paragraph', $d);

tool('block_insert', ['post_id' => $pid, 'block' => ['blockName' => 'core/paragraph', 'html' => '<p>Другий абзац</p>'], 'dry_run' => true]);
tool('block_insert', ['post_id' => $pid, 'block' => ['blockName' => 'core/paragraph', 'html' => '<p>Другий абзац</p>']]);
tool('block_insert', ['post_id' => $pid, 'block' => ['blockName' => 'acf/does-not-exist']], false);
tool('block_move', ['post_id' => $pid, 'from' => 1, 'to' => 0]);
[$d] = tool('block_get', ['post_id' => $pid]);
$blocks = $d['blocks'] ?? $d;
check(count($blocks) === 2 && strpos($blocks[0]['html'] ?? '', 'Другий абзац') !== false, 'block_insert + block_move reorder the body', $d);
tool('block_remove', ['post_id' => $pid, 'locator' => ['index' => 0]]);

[$d] = tool('render_post', ['post_id' => $pid, 'mode' => 'blocks']);
$html = is_array($d) ? (string) ($d['html'] ?? json_encode($d, JSON_UNESCAPED_UNICODE)) : $d;
check(strpos($html, 'Привіт з PHP') !== false && strpos($html, 'Другий абзац') === false, 'render_post renders the edited body', $d);

[$d] = tool('term_create', ['taxonomy' => 'category', 'name' => "Smoke CI $run", 'slug' => "smoke-ci-$run"]);
$tid = (int) ($d['term_id'] ?? $d['id'] ?? 0);
check($tid > 0, 'term_create returns the term ID', $d);
tool('term_update', ['term_id' => $tid, 'taxonomy' => 'category', 'description' => 'created by the CI smoke test']);
tool('set_post_terms', ['post_id' => $pid, 'taxonomy' => 'category', 'terms' => ["smoke-ci-$run"]]);
tool('set_post_terms', ['post_id' => $pid, 'taxonomy' => 'category', 'terms' => ['no-such-term']], false);
[$d] = tool('term_list', ['taxonomy' => 'category', 'search' => $run]);
check(strpos(json_encode($d), "\"smoke-ci-$run\"") !== false, 'term_list finds the new term', $d);

[$d] = tool('find_posts', ['search' => "Simple MCP smoke $run", 'status' => 'draft']);
check(strpos(json_encode($d), '"id":' . $pid) !== false || strpos(json_encode($d), '"ID":' . $pid) !== false, 'find_posts finds the post', $d);

[$d] = tool('revision_list', ['post_id' => $pid]);
check(is_array($d), 'revision_list answers', $d);

$png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
[$d] = tool('upload_media', ['filename' => 'smoke.png', 'data' => $png, 'title' => 'Smoke', 'post_id' => $pid]);
$aid = (int) ($d['attachment_id'] ?? 0);
check($aid > 0, 'upload_media stores the file', $d);
tool('update_post', ['id' => $pid, 'thumbnail' => $aid]);
[$d] = tool('upload_media', ['source' => 'url', 'url' => 'http://127.0.0.1/wp-login.php', 'filename' => 'x.png'], false);
check(strpos((string) (is_string($d) ? $d : json_encode($d, JSON_UNESCAPED_UNICODE)), 'заблоковано') !== false, 'upload_media refuses a loopback URL (SSRF gate)', $d);

tool('cache_flush', ['post_id' => $pid]);

// ── wp_cli ─────────────────────────────────────────────────────────────────
[$d] = tool('wp_cli', ['command' => 'option get blogname']);
check(trim((string) ($d['stdout'] ?? '')) === 'Simple MCP CI' && (int) ($d['exit_code'] ?? -1) === 0, 'wp_cli runs WP-CLI (option get blogname)', $d);
[$d] = tool('wp_cli', ['command' => 'post get ' . $pid . ' --field=post_title']);
check(trim((string) ($d['stdout'] ?? '')) === "Simple MCP smoke $run — оновлено", 'wp_cli sees the typed-tool write (UTF-8 intact)', $d);
tool('wp_cli', ['command' => 'plugin install hello-dolly'], false);
tool('wp_cli', ['command' => 'package list'], false);
tool('wp_cli', ['command' => 'option get blogname; ls'], false);

// ── Clean up ───────────────────────────────────────────────────────────────
tool('safe_delete', ['post_id' => $pid]);
tool('safe_delete', ['post_id' => $pid], false); // already in the trash
tool('safe_delete', ['post_id' => $pid, 'force' => true]);
tool('safe_delete', ['post_id' => $aid, 'force' => true]);
[$s, , $b] = rpc(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'get_post', 'arguments' => ['id' => $pid]]],
    ['MCP-Protocol-Version: 2025-11-25']);
check(!empty($b['result']['isError']) || isset($b['error']), 'the deleted post is gone', $b);

echo "\nPHP " . PHP_VERSION . " client — $checks checks passed against $url\n";
