<?php
/**
 * A theme with its own block tools (2.7.7), over HTTP with a key — run by tests/e2e/run.sh with the test mu-plugin
 * (tests/e2e/fixtures/smcp-test-abilities.php), whose hooks the option smcp_test_ext switches on per mode:
 *
 *   theme  — instructions, hide_blocks, pre_write: the theme's line is in the initialize instructions and block_*
 *            are not; the built-in block tools are gone from tools/list; update_post with content is refused for a
 *            post the theme guards (the body stays), without content it is not; images in an ability result
 *            arrive as MCP images (up to 3, up to 1.5 MB) with the inline signal on.
 *   blocks — pre_write only: block_* are back and named in the instructions; block_insert and revision_restore
 *            are refused for a guarded post, a dry run is not.
 *
 *   php tests/e2e/extensibility.php <endpoint-url> <key> theme|blocks
 */
[, $url, $key, $mode] = $argv + [3 => 'theme'];

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

/** tools/call → [isError, first text, structuredContent|null, the whole result] */
function call_tool($name, $args) {
    static $id = 100;
    [$s, $b, $raw] = rpc('tools/call', ['name' => $name, 'arguments' => (object) $args], ++$id);
    check($s === 200 && isset($b['result']), "tools/call $name answers with a result", $s . ' ' . $raw);
    return [!empty($b['result']['isError']), (string) ($b['result']['content'][0]['text'] ?? ''), $b['result']['structuredContent'] ?? null, $b['result']];
}

function body_of($id) {
    [$err, $text, $sc] = call_tool('get_post', ['id' => $id]);
    check(!$err, "get_post #$id", $text);
    return (string) ($sc['content'] ?? '');
}

$para = function ($text) { return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->"; };

[$s, $b, $raw] = rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'smcp-e2e', 'version' => '1']]);
check($s === 200 && isset($b['result']['instructions']), 'initialize', $raw);
$instr = (string) $b['result']['instructions'];
[$s, $b, $raw] = rpc('tools/list');
check($s === 200 && isset($b['result']['tools']), 'tools/list', $raw);
$tools = array_column($b['result']['tools'], null, 'name');
$block_tools = array_values(array_filter(array_keys($tools), function ($n) { return strpos($n, 'block_') === 0 || $n === 'list_block_fields'; }));

if ($mode === 'theme') {
    // ── simple_mcp_instructions and simple_mcp_hide_builtin_blocks ──
    check(strpos($instr, 'SMCP-TEST: call smcp-test-site-name first (abilities on).') !== false, 'the theme adds its line to the initialize instructions (with the user\'s permissions)', $instr);
    check(strpos($instr, 'block_') === false, 'the instructions do not name the hidden block_* tools', $instr);
    check(!$block_tools, 'the built-in block tools are hidden in favour of the theme\'s', $block_tools);
    check(isset($tools['get_post'], $tools['update_post'], $tools['smcp-test-image']), 'the other built-in tools and the abilities are there');

    // ── simple_mcp_pre_write: update_post with content is refused for a guarded post, without content it is not ──
    [$err, $text, $sc] = call_tool('create_post', ['post_type' => 'post', 'title' => 'SMCP refuse me', 'content' => $para('one')]);
    check(!$err && !empty($sc['id']), 'create_post is not a write to an open post, so the theme is not asked', $text);
    $id = (int) $sc['id'];
    [$err, $text] = call_tool('update_post', ['id' => $id, 'content' => $para('two'), 'excerpt' => 'x']);
    check($err && $text === 'SMCP-TEST refused update_post on #' . $id . ' (args: id,content,excerpt)', 'update_post with content: the theme\'s WP_Error is the answer; it got the post, the tool and the arguments', $text);
    $body = body_of($id);
    check(strpos($body, '<p>one</p>') !== false, 'the body is as it was', $body);
    [$err, $text, $sc] = call_tool('get_post', ['id' => $id]);
    check(($sc['excerpt'] ?? null) === '', '… and the other fields of the refused call were not written either', $sc);
    [$err, $text] = call_tool('update_post', ['id' => $id, 'excerpt' => 'not the body']);
    check(!$err, 'update_post without content is not a body write: the theme is not asked', $text);

    // ── _mcp_images in an ability result ──
    [$err, $text, $sc, $res] = call_tool('smcp-test-image', ['count' => 1]);
    $content = $res['content'] ?? [];
    check(!$err && count($content) === 2 && ($content[1]['type'] ?? '') === 'image' && ($content[1]['mimeType'] ?? '') === 'image/png'
        && strpos((string) base64_decode((string) ($content[1]['data'] ?? ''), true), "\x89PNG") === 0, 'an image in the result reaches the client as an MCP image', $content);
    check(json_decode($text, true) === ['inline' => true, 'count' => 1] && $sc === ['inline' => true, 'count' => 1],
        '_mcp_images is not in the JSON text or structuredContent; the ability saw simple_mcp_inline_images on', [$text, $sc]);
    [$err, $text, $sc, $res] = call_tool('smcp-test-image', ['count' => 2, 'big' => true]);
    $types = array_column($res['content'] ?? [], 'type');
    $note  = (string) (end($res['content'])['text'] ?? '');
    check($types === ['text', 'image', 'image', 'text'] && $note === 'Not attached: image 3 (2 MB, over 1.5 MB).', 'an image over 1.5 MB is dropped with a note', [$types, $note]);
    [$err, $text, $sc, $res] = call_tool('smcp-test-image', ['count' => 5]);
    $types = array_column($res['content'] ?? [], 'type');
    $note  = (string) (end($res['content'])['text'] ?? '');
    check($types === ['text', 'image', 'image', 'image', 'text'] && strpos($note, 'image 4 (only 3 images per result); image 5') !== false,
        'five images: three are attached, the note names the rest', [$types, $note]);
    [$err, $text, $sc, $res] = call_tool('smcp-test-echo', ['text' => 'x']);
    check(count($res['content'] ?? []) === 1 && empty($err), 'other abilities answer as before');

    echo "\nPHP " . PHP_VERSION . " client — theme with its own block tools: $checks checks passed against $url\n";
    exit(0);
}

// ── blocks: the built-in block tools are visible again; simple_mcp_pre_write guards them and revision_restore ──
check(isset($tools['block_get'], $tools['block_insert'], $tools['block_batch']), 'without simple_mcp_hide_builtin_blocks the block tools are there', $block_tools);
check(strpos($instr, 'block_get') !== false && strpos($instr, 'SMCP-TEST') === false, 'the instructions name them again, and without the theme\'s line', $instr);

[$err, $text, $sc] = call_tool('create_post', ['post_type' => 'post', 'title' => 'SMCP history', 'content' => $para('one')]);
check(!$err && !empty($sc['id']), 'create_post', $text);
$id = (int) $sc['id'];
[$err, $text] = call_tool('update_post', ['id' => $id, 'content' => $para('two')]);
check(!$err, 'a post the theme does not guard is written as usual', $text);
[$err, $text] = call_tool('update_post', ['id' => $id, 'title' => 'SMCP refuse history']);
check(!$err, 'retitled (not a body write), now the theme guards it', $text);

$spec = ['blockName' => 'core/paragraph', 'html' => '<p>three</p>'];
[$err, $text] = call_tool('block_insert', ['post_id' => $id, 'block' => $spec]);
check($err && $text === 'SMCP-TEST refused block_insert on #' . $id . ' (args: post_id,block)', 'block_insert is refused with the theme\'s message', $text);
[$err, $text, $sc] = call_tool('block_insert', ['post_id' => $id, 'block' => $spec, 'dry_run' => true]);
check(!$err && !empty($sc['dry_run']), 'a dry run writes nothing, so it is not refused', $text);
[$err, $text, $sc] = call_tool('block_batch', ['post_id' => $id, 'ops' => [['op' => 'insert', 'block' => $spec]]]);
check($err && strpos($text, 'SMCP-TEST refused block_batch on #' . $id) === 0, 'block_batch too', $text);
$body = body_of($id);
check(strpos($body, '<p>two</p>') !== false && strpos($body, 'three') === false, 'the body is untouched', $body);

[$err, $text, $sc] = call_tool('revision_list', ['post_id' => $id]);
$rev = 0;
foreach ((array) ($sc['revisions'] ?? []) as $r) {
    if (empty($r['autosave'])) { $rev = (int) $r['revision_id']; break; }
}
check(!$err && $rev > 0, 'the post has revisions', $text);
[$err, $text] = call_tool('revision_restore', ['post_id' => $id, 'revision_id' => $rev]);
check($err && strpos($text, 'SMCP-TEST refused revision_restore on #' . $id) === 0, 'revision_restore is refused as well', $text);
check(strpos(body_of($id), '<p>two</p>') !== false, 'and the body stays');

echo "\nPHP " . PHP_VERSION . " client — block writes guarded by the theme: $checks checks passed against $url\n";
