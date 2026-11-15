<?php
/**
 * End-to-end test of other plugins' tools (WordPress Abilities API) on a real WordPress, over HTTP with a key:
 * which abilities the agent sees, how they look as MCP tools, and calling them — success, schema errors,
 * their own permission refusal and failure. Needs tests/e2e/fixtures/smcp-test-abilities.php as a mu-plugin
 * (tests/e2e/run.sh copies it) and WordPress 6.9+.
 *
 *   php tests/e2e/abilities.php <endpoint-url> <key> [hidden]
 *
 * hidden — a site upgraded from before 2.7.1 (run.sh simulates it): the row «Інструменти плагінів і теми» is off by
 * default there, so the agent sees exactly the built-in tools it saw before the upgrade.
 *
 * SMCP_E2E_INSECURE_TLS=1 skips TLS verification. Exit 1 on the first failure.
 */
if (PHP_SAPI !== 'cli' || $argc < 3) {
    fwrite(STDERR, "usage: php tests/e2e/abilities.php <endpoint-url> <key> [hidden]\n");
    exit(2);
}
[, $url, $key] = $argv;
$mode = $argv[3] ?? 'shown';

$checks = 0;
function check($ok, $what, $context = null) {
    global $checks;
    $checks++;
    if ($ok) {
        echo "ok   $what\n";
        return;
    }
    echo "FAIL $what\n";
    if ($context !== null) echo '     ' . (is_string($context) ? substr($context, 0, 1200) : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
    exit(1);
}

function rpc($method, $params = null, $id = 1) {
    global $url, $key;
    $msg = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== null) $msg['params'] = $params;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => json_encode($msg),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: 2025-11-25', 'Authorization: Bearer ' . $key],
    ];
    if (getenv('SMCP_E2E_INSECURE_TLS')) { $opts[CURLOPT_SSL_VERIFYPEER] = false; $opts[CURLOPT_SSL_VERIFYHOST] = 0; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) check(false, "POST $url reachable", curl_error($ch));
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) $raw, true), (string) $raw];
}

/** tools/call → [isError, text, structuredContent|null, raw] */
function call_tool($name, $args) {
    static $id = 100;
    [$s, $b, $raw] = rpc('tools/call', ['name' => $name, 'arguments' => (object) $args], ++$id);
    check($s === 200 && isset($b['result']), "tools/call $name answers with a result", $s . ' ' . $raw);
    return [!empty($b['result']['isError']), (string) ($b['result']['content'][0]['text'] ?? ''), $b['result']['structuredContent'] ?? null, $raw];
}

[$s, $b, $raw] = rpc('tools/list');
check($s === 200 && isset($b['result']['tools']), 'tools/list', $raw);
$tools = [];
foreach ($b['result']['tools'] as $t) $tools[$t['name']] = $t;

if ($mode === 'hidden') {
    $ability_tools = array_filter(array_keys($tools), function ($n) { return strpos($n, '_') === false; });
    check(!$ability_tools, 'an upgraded site shows no tools of other plugins until the administrator turns them on', array_values($ability_tools));
    check(isset($tools['get_post'], $tools['block_get'], $tools['wp_cli']), 'the built-in tools are all there');
    [$s, $b] = rpc('tools/call', ['name' => 'smcp-test-echo', 'arguments' => ['text' => 'x']], 9);
    check(isset($b['error']) || !empty($b['result']['isError']), 'a hidden ability cannot be called either', $b);
    echo "\nPHP " . PHP_VERSION . " client — abilities (upgraded site): $checks checks passed against $url\n";
    exit(0);
}

foreach (['smcp-test-echo', 'smcp-test-shout', 'smcp-test-site-name', 'smcp-test-options', 'smcp-test-forbidden', 'smcp-test-fails'] as $n) {
    check(isset($tools[$n]), "the ability marked for MCP is the tool $n", array_keys($tools));
}

// An object schema never lists "default": [] (WordPress declares 'default' => array()); read the raw JSON, where {} ≠ []
$bad = [];
$walk = function ($node, $path) use (&$walk, &$bad) {
    if (is_object($node)) {
        if (($node->type ?? null) === 'object' && property_exists($node, 'default') && is_array($node->default)) $bad[] = $path;
        foreach (get_object_vars($node) as $k => $v) $walk($v, "$path.$k");
    } elseif (is_array($node)) {
        foreach ($node as $i => $v) $walk($v, "$path[$i]");
    }
};
$listed = [];
foreach ((json_decode($raw)->result->tools ?? []) as $t) $listed[$t->name] = $t;
foreach ($listed as $name => $t) $walk($t->inputSchema ?? null, $name);
check(!$bad, 'no object schema in tools/list has an array default (core abilities included)', $bad);
check(json_encode($listed['smcp-test-options']->inputSchema->default ?? null) === '{}', "the ability's 'default' => array() is listed as {}",
    json_encode($listed['smcp-test-options']->inputSchema ?? null));
check(!isset($tools['smcp-test-hidden']) && !isset($tools['smcp-test-opted-out']), 'abilities without the MCP mark (or opted out) are not shown');
check(isset($tools['get_post']), 'the built-in tools are still there');

$echo = $tools['smcp-test-echo'];
check($echo['title'] === 'Echo text' && strpos($echo['description'], '(WordPress ability smcp-test/echo)') !== false, 'title is the label, the description names the ability', $echo);
check(($echo['inputSchema']['required'] ?? null) === ['text'] && !isset($echo['inputSchema']['properties']['text']['required'])
    && !isset($echo['inputSchema']['additionalProperties']), 'the WP REST «required: true» is a JSON Schema required array; the list schema is portable', $echo['inputSchema']);
check(($echo['annotations']['readOnlyHint'] ?? null) === true && ($echo['annotations']['destructiveHint'] ?? null) === false
    && ($echo['annotations']['idempotentHint'] ?? null) === true, 'annotations come from meta.annotations', $echo['annotations'] ?? null);
check(($tools['smcp-test-shout']['inputSchema']['properties']['input']['type'] ?? '') === 'string'
    && ($tools['smcp-test-shout']['inputSchema']['required'] ?? null) === ['input'], 'a string schema is wrapped into {input}', $tools['smcp-test-shout']['inputSchema']);
check(($tools['smcp-test-fails']['annotations']['destructiveHint'] ?? null) === true, 'without annotations a tool may change things');

[$err, $text, $sc] = call_tool('smcp-test-echo', ['text' => 'ab', 'times' => 3]);
check(!$err && ($sc['echo'] ?? '') === 'ababab' && ($sc['user'] ?? '') !== '', 'the ability runs as the key\'s user and its array result is structured', $text);
[$err, $text] = call_tool('smcp-test-echo', ['times' => 2]);
check($err && stripos($text, 'invalid input') !== false, 'missing required input → the ability\'s own validation error', $text);
[$err, $text] = call_tool('smcp-test-echo', ['text' => 'x', 'extra' => 1]);
check($err, 'an argument the ability does not know is refused by its schema', $text);
[$err, $text] = call_tool('smcp-test-shout', ['input' => 'hi']);
check(!$err && $text === 'HI', 'a wrapped string input reaches the ability as a string', $text);
[$err, $text, $sc] = call_tool('smcp-test-options', []);
check(!$err && ($sc['verbose'] ?? null) === false, 'an object schema with an empty default runs without arguments', $text);
[$err, $text, $sc] = call_tool('smcp-test-options', ['verbose' => true]);
check(!$err && ($sc['verbose'] ?? null) === true, '… and with them', $text);
[$err, $text, $sc] = call_tool('smcp-test-site-name', []);
check(!$err && isset($sc['name']), 'an ability without an input schema runs', $text);
[$err, $text] = call_tool('smcp-test-forbidden', []);
check($err && strpos($text, 'smcp-test/forbidden') !== false && strpos($text, 'secret reason') === false, 'its own permission refusal → a rights error that does not leak the reason', $text);
[$err, $text] = call_tool('smcp-test-fails', []);
check($err && strpos($text, 'failed on purpose') !== false, 'a WP_Error from the ability → isError with its message', $text);

echo "\nPHP " . PHP_VERSION . " client — abilities: $checks checks passed against $url\n";
