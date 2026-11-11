<?php
/**
 * End-to-end OAuth test against a real WordPress, over HTTP, the way ChatGPT, Claude or Codex connect:
 * discovery, registration (and the MCP spec's default doors), consent with a signed-in person, codes with
 * PKCE, tokens on the MCP endpoint, refresh rotation, revocation and switching OAuth off. Started by
 * tests/e2e/run.sh in phases; the site's settings between phases are changed there with WP-CLI.
 *
 *   php tests/e2e/oauth.php <endpoint-url> <logged-in-cookie name=value> <state-file> <phase>
 *
 *   flow     OAuth on: the whole way in; writes the tokens to <state-file>
 *   no-cli   OAuth on, «WP-CLI for OAuth» off: the connection lost wp_cli, keys did not
 *   reuse    a spent refresh token (aged past its grace minute by run.sh) revokes its connection's chain
 *   revoked  the OAuth keys were revoked in the profile: the access and refresh tokens are dead
 *   off      OAuth off: its doors answer like unknown pages, the 401 no longer names the metadata
 *
 * SMCP_E2E_INSECURE_TLS=1 skips TLS verification (a local site with its own CA). Exit 1 on the first failure.
 */
if (PHP_SAPI !== 'cli' || $argc < 5) {
    fwrite(STDERR, "usage: php tests/e2e/oauth.php <endpoint-url> <cookie name=value> <state-file> <flow|no-cli|revoked|off>\n");
    exit(2);
}
[, $url, $cookie, $state_file, $phase] = $argv;
$url = rtrim($url, '/');
$p   = parse_url($url);
$origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
$path   = trim($p['path'] ?? '', '/');

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
        echo '     ' . (is_string($context) ? substr($context, 0, 1200) : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
    }
    exit(1);
}

/** One HTTP request, no redirects followed: [status, lower-cased headers, body]. */
function http($method, $url, $body = null, array $headers = []) {
    $h  = [];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$h) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $h[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ];
    if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
    if (getenv('SMCP_E2E_INSECURE_TLS')) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $opts);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    if ($raw === false) check(false, "$method $url reachable", curl_error($ch));
    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $h, (string) $raw];
}

function post_json($url, array $data, array $headers = []) {
    return http('POST', $url, json_encode($data, JSON_UNESCAPED_SLASHES), array_merge(['Content-Type: application/json'], $headers));
}

function post_form($url, array $data, array $headers = []) {
    return http('POST', $url, http_build_query($data), array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers));
}

/** JSON-RPC on the MCP endpoint with a bearer token: [status, headers, decoded]. */
function rpc($token, $method, $params = null, $id = 1) {
    global $url;
    $msg = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== null) $msg['params'] = $params;
    $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-11-25'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    [$s, $h, $raw] = http('POST', $url, json_encode($msg), $headers);
    return [$s, $h, json_decode($raw, true), $raw];
}

function tool_names($token) {
    [$s, , $b, $raw] = rpc($token, 'tools/list', null, 5);
    check($s === 200 && isset($b['result']['tools']), 'tools/list answers with the OAuth token', $s . ' ' . $raw);
    return array_column($b['result']['tools'], 'name');
}

/** PKCE pair */
function pkce() {
    $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return [$verifier, $challenge];
}

/** Hidden fields and the nonce of the consent form */
function form_fields($html) {
    preg_match_all('~<input\b[^>]*>~i', $html, $m);
    $out = [];
    foreach ($m[0] as $tag) {
        $attr = function ($name) use ($tag) {
            return preg_match('~\b' . $name . '="([^"]*)"~i', $tag, $a) ? html_entity_decode($a[1], ENT_QUOTES) : null;
        };
        if (strtolower((string) $attr('type')) !== 'hidden' || $attr('name') === null) continue;
        $out[$attr('name')] = (string) $attr('value');
    }
    return $out;
}

/** The address the «leaving» page sends the person back to (meta refresh) */
function leaving_url($html) {
    if (!preg_match('~<meta http-equiv="refresh" content="0;url=([^"]+)"~', $html, $m)) return null;
    return html_entity_decode($m[1], ENT_QUOTES);
}

function query_of($u) {
    parse_str((string) parse_url((string) $u, PHP_URL_QUERY), $q);
    return $q;
}

$authorize = $url . '/oauth/authorize';
$token_url = $url . '/oauth/token';
$cookie_h  = 'Cookie: ' . $cookie;

/** Consent as the signed-in person: GET the screen, POST the decision. Returns the leaving page's query. */
function consent(array $query, $decision, $name = '', $expect_text = null) {
    global $authorize, $cookie_h;
    [$s, , $html] = http('GET', $authorize . '?' . http_build_query($query), null, [$cookie_h]);
    check($s === 200 && strpos($html, 'name="_wpnonce"') !== false, 'the signed-in person sees the consent screen', $s . ' ' . substr($html, 0, 400));
    if ($expect_text !== null) check(strpos($html, $expect_text) !== false, "the consent screen says «{$expect_text}»", substr(strip_tags($html), 0, 600));
    $fields = form_fields($html);
    check(isset($fields['_wpnonce'], $fields['client_id'], $fields['code_challenge']), 'the consent form carries the request and a nonce', $fields);
    $fields['decision'] = $decision;
    if ($name !== '') $fields['agent_name'] = $name;
    [$s, $h, $html] = post_form($authorize, $fields, [$cookie_h]);
    check($s === 200, "the consent answer ($decision) is a page, not a redirect", $s . ' ' . substr($html, 0, 300));
    $to = leaving_url($html);
    check($to !== null, 'the page sends the person back to the agent', substr($html, 0, 400));
    return [$to, query_of($to)];
}

$state = is_file($state_file) ? (array) json_decode((string) file_get_contents($state_file), true) : [];

// ═════════════════════════════════════════════════════════════════════════════
if ($phase === 'flow') {
    // ── Discovery ──
    [$s, $h, $raw] = http('GET', $origin . '/.well-known/oauth-protected-resource/' . $path);
    $prm = json_decode($raw, true);
    check($s === 200 && ($prm['resource'] ?? '') === $url, 'protected resource metadata names the endpoint', $s . ' ' . $raw);
    check(($h['access-control-allow-origin'] ?? '') === '*' && stripos($h['cache-control'] ?? '', 'no-store') !== false, 'discovery is open to CORS and not cached', $h);
    $issuer = $prm['authorization_servers'][0] ?? '';
    check($issuer !== '' && strpos($url, $issuer) === 0, 'the site is its own authorization server', $prm);
    [$s, , $raw] = http('GET', $origin . '/.well-known/oauth-protected-resource');
    check($s === 200 && (json_decode($raw, true)['resource'] ?? '') === $url, 'the root protected-resource document too', $s . ' ' . $raw);
    $issuer_path = trim((string) parse_url($issuer, PHP_URL_PATH), '/');
    [$s, , $raw] = http('GET', $origin . '/.well-known/oauth-authorization-server' . ($issuer_path !== '' ? '/' . $issuer_path : ''));
    $as = json_decode($raw, true);
    check($s === 200 && ($as['issuer'] ?? '') === $issuer && ($as['token_endpoint'] ?? '') === $token_url
        && ($as['authorization_endpoint'] ?? '') === $authorize && ($as['registration_endpoint'] ?? '') === $url . '/oauth/register'
        && ($as['code_challenge_methods_supported'] ?? []) === ['S256'], 'authorization server metadata names our doors and S256', $s . ' ' . $raw);
    [$s] = http('POST', $origin . '/.well-known/oauth-authorization-server');
    check($s === 405, 'discovery takes only GET', $s);

    // ── The 401 leads to OAuth ──
    [$s, $h] = rpc(null, 'ping');
    check($s === 401 && strpos($h['www-authenticate'] ?? '', 'resource_metadata="' . $origin . '/.well-known/oauth-protected-resource/' . $path . '"') !== false,
        'POST without a token → 401 naming the resource metadata', $s . ' ' . ($h['www-authenticate'] ?? ''));
    [$s, $h] = http('HEAD', $url);
    check($s === 401 && strpos($h['www-authenticate'] ?? '', 'resource_metadata=') !== false, 'HEAD without a token → 401 too (Gemini checks the address so)', $s);
    [$s] = rpc('smcp_oat_' . str_repeat('A', 43), 'ping');
    check($s === 401, 'an unknown OAuth token → 401 (not counted towards the IP lock)', $s);

    // ── Registration ──
    [$s, $h] = http('OPTIONS', $url . '/oauth/register', null, ['Origin: https://example.com', 'Access-Control-Request-Method: POST']);
    check($s === 204 && ($h['access-control-allow-origin'] ?? '') === '*', 'registration answers CORS preflight', $s);
    [$s, , $raw] = post_json($url . '/oauth/register', ['client_name' => 'Phisher', 'redirect_uris' => ['https://evil.example/cb']]);
    check($s === 400 && (json_decode($raw, true)['error'] ?? '') === 'invalid_redirect_uri', 'an unknown agent address is refused under the default policy', $s . ' ' . $raw);
    [$s, , $raw] = post_json($url . '/oauth/register', ['client_name' => 'x', 'redirect_uris' => ['javascript:alert(1)']]);
    check($s === 400, 'a javascript: address is refused', $s . ' ' . $raw);
    [$s, , $raw] = post_json($url . '/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://attacker.example\\@claude.ai/api/mcp/auth_callback']]);
    check($s === 400, 'a «claude.ai» address that a browser would send to another host (\\@) is refused', $s . ' ' . $raw);
    [$s, , $raw] = post_json($url . '/oauth/register', ['client_name' => 'Simple MCP e2e', 'redirect_uris' => ['http://127.0.0.1:9/callback']]);
    $pub = json_decode($raw, true);
    check($s === 201 && strpos($pub['client_id'] ?? '', 'smcpc_') === 0 && ($pub['token_endpoint_auth_method'] ?? '') === 'none' && !isset($pub['client_secret']),
        'a loopback client registers as a public client', $s . ' ' . $raw);
    [$s, , $raw] = post_json($origin . '/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'token_endpoint_auth_method' => 'client_secret_post', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]);
    $conf = json_decode($raw, true);
    check($s === 201 && strpos($conf['client_secret'] ?? '', 'smcp_ocs_') === 0, 'the default /register door registers a confidential client', $s . ' ' . $raw);

    // ── Consent ──
    [$verifier, $challenge] = pkce();
    $q = ['client_id' => $pub['client_id'], 'redirect_uri' => 'http://127.0.0.1:45678/callback', 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-1', 'resource' => $url];
    [$s, $h] = http('GET', $authorize . '?' . http_build_query($q));
    check($s === 302 && strpos($h['location'] ?? '', 'wp-login.php') !== false && strpos(urldecode($h['location'] ?? ''), '/oauth/authorize') !== false,
        'a person who is not signed in goes to the WordPress login and back', $s . ' ' . ($h['location'] ?? ''));
    [$s, $h] = http('GET', $origin . '/authorize?' . http_build_query($q));
    check($s === 302 && strpos($h['location'] ?? '', $authorize . '?') === 0, 'the default /authorize door forwards to our consent with the same query', $s . ' ' . ($h['location'] ?? ''));
    [$s, , $html] = http('GET', $authorize . '?' . http_build_query(['client_id' => 'smcpc_nope'] + $q), null, [$cookie_h]);
    check($s === 400 && strpos($html, 'refresh') === false, 'an unknown client gets a page, never a redirect', $s);
    [$s, $h] = http('GET', $authorize . '?' . http_build_query(['code_challenge_method' => 'plain'] + $q), null, [$cookie_h]);
    $err = query_of($h['location'] ?? '');
    check($s === 302 && ($err['error'] ?? '') === 'invalid_request' && ($err['state'] ?? '') === 'st-1' && ($err['iss'] ?? '') === $issuer,
        'no S256 → the client\'s own error, at its address, with state and iss', $s . ' ' . ($h['location'] ?? ''));
    [$s, $h, $html] = http('GET', $authorize . '?' . http_build_query($q), null, [$cookie_h]);
    check(stripos($h['x-frame-options'] ?? '', 'deny') !== false && strpos($h['content-security-policy'] ?? '', "frame-ancestors 'none'") !== false,
        'the consent screen cannot be framed', $h);
    check(strpos($html, 'Агент на цьому комп') !== false && strpos($html, 'Назва агента') !== false, 'the consent screen shows where the answer goes and asks for a name', strip_tags($html));

    [, $answer] = consent($q, 'deny');
    check(($answer['error'] ?? '') === 'access_denied' && ($answer['state'] ?? '') === 'st-1' && ($answer['iss'] ?? '') === $issuer, 'declining sends access_denied with state and iss', $answer);

    // a code exchanged with a wrong verifier is burnt
    [, $answer] = consent($q, 'allow', 'E2E agent');
    check(($answer['code'] ?? '') !== '' && ($answer['state'] ?? '') === 'st-1' && ($answer['iss'] ?? '') === $issuer, 'allowing sends a code with state and iss', $answer);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'client_id' => $pub['client_id'],
        'redirect_uri' => $q['redirect_uri'], 'code_verifier' => pkce()[0]]);
    check($s === 400 && (json_decode($raw, true)['error'] ?? '') === 'invalid_grant', 'a wrong PKCE verifier → invalid_grant', $s . ' ' . $raw);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'client_id' => $pub['client_id'],
        'redirect_uri' => $q['redirect_uri'], 'code_verifier' => $verifier]);
    check($s === 400, 'the code is spent by the failed attempt', $s . ' ' . $raw);

    [, $answer] = consent($q, 'allow', 'E2E agent');
    [$s, $h, $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'client_id' => $pub['client_id'],
        'redirect_uri' => $q['redirect_uri'], 'code_verifier' => $verifier, 'resource' => $url]);
    $tok = json_decode($raw, true);
    check($s === 200 && strpos($tok['access_token'] ?? '', 'smcp_oat_') === 0 && strpos($tok['refresh_token'] ?? '', 'smcp_ort_') === 0
        && ($tok['token_type'] ?? '') === 'Bearer' && ($tok['expires_in'] ?? 0) === 3600, 'the code gives an access and a refresh token', $s . ' ' . $raw);
    check(stripos($h['cache-control'] ?? '', 'no-store') !== false, 'the token answer is not cached', $h);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'client_id' => $pub['client_id'],
        'redirect_uri' => $q['redirect_uri'], 'code_verifier' => $verifier]);
    check($s === 400 && (json_decode($raw, true)['error'] ?? '') === 'invalid_grant', 'a code is used once', $s . ' ' . $raw);

    // ── The token on the MCP endpoint ──
    [$s, , $b, $raw] = rpc($tok['access_token'], 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'e2e', 'version' => '1']]);
    check($s === 200 && ($b['result']['protocolVersion'] ?? '') === '2025-11-25', 'initialize with the OAuth token', $s . ' ' . $raw);
    $names = tool_names($tok['access_token']);
    check(in_array('wp_cli', $names, true) && in_array('get_post', $names, true), 'the administrator\'s connection has the role\'s tools, wp_cli included', $names);
    [, , $b] = rpc($tok['access_token'], 'tools/list', null, 6);
    check(strpos(json_encode($b), 'additionalProperties') === false, 'tools/list schemas are portable (no additionalProperties)');
    [$s, , $b, $raw] = rpc($tok['access_token'], 'tools/call', ['name' => 'wp_cli', 'arguments' => ['command' => 'option get home']], 7);
    check($s === 200 && empty($b['result']['isError']), 'the OAuth connection runs wp_cli', $raw);

    // ── Refresh: rotated, a minute of grace ──
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $pub['client_id']]);
    $tok2 = json_decode($raw, true);
    check($s === 200 && ($tok2['refresh_token'] ?? '') !== $tok['refresh_token'] && ($tok2['access_token'] ?? '') !== '', 'refresh gives a new pair', $s . ' ' . $raw);
    [$s, , $raw] = post_json($origin . '/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tok['refresh_token'], 'client_id' => $pub['client_id']]);
    check($s === 200, 'the spent refresh token still works within the minute of grace (JSON body, default /token door)', $s . ' ' . $raw);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'refresh_token', 'refresh_token' => $tok2['refresh_token'], 'client_id' => $conf['client_id'], 'client_secret' => $conf['client_secret']]);
    check($s === 400 && (json_decode($raw, true)['error'] ?? '') === 'invalid_grant', 'a refresh token of another client is refused', $s . ' ' . $raw);
    [$s] = rpc($tok2['access_token'], 'ping');
    check($s === 200, 'the new access token works', $s);

    // ── Consent again renews the same connection and starts a new chain of tokens ──
    [, $answer] = consent($q, 'allow', '', 'уже підключений');
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'client_id' => $pub['client_id'],
        'redirect_uri' => $q['redirect_uri'], 'code_verifier' => $verifier]);
    $tok3 = json_decode($raw, true);
    check($s === 200 && isset($tok3['access_token']), 'consent again renews the connection', $s . ' ' . $raw);
    [$s] = rpc($tok2['access_token'], 'ping');
    check($s === 401, 'the renewed connection\'s old tokens stop working', $s);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'refresh_token', 'refresh_token' => $tok3['refresh_token'], 'client_id' => $pub['client_id']]);
    $tok4 = json_decode($raw, true);
    check($s === 200 && isset($tok4['access_token']), 'the new chain refreshes', $s . ' ' . $raw);

    // ── A confidential client: Basic or form secret ──
    [$cv, $cc] = pkce();
    $cq = ['client_id' => $conf['client_id'], 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code',
        'code_challenge' => $cc, 'code_challenge_method' => 'S256', 'state' => 'c-1'];
    [$to, $answer] = consent($cq, 'allow', '', 'Claude');
    check(strpos($to, 'https://claude.ai/api/mcp/auth_callback?') === 0, 'the code goes back to claude.ai', $to);
    [$s, $h, $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'redirect_uri' => $cq['redirect_uri'], 'code_verifier' => $cv],
        ['Authorization: Basic ' . base64_encode($conf['client_id'] . ':wrong')]);
    check($s === 401 && (json_decode($raw, true)['error'] ?? '') === 'invalid_client' && stripos($h['www-authenticate'] ?? '', 'Basic') === 0, 'a wrong secret → 401 invalid_client', $s . ' ' . $raw);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'authorization_code', 'code' => $answer['code'], 'redirect_uri' => $cq['redirect_uri'], 'code_verifier' => $cv],
        ['Authorization: Basic ' . base64_encode(rawurlencode($conf['client_id']) . ':' . rawurlencode($conf['client_secret']))]);
    $ctok = json_decode($raw, true);
    check($s === 200 && isset($ctok['access_token']), 'the right secret by Basic gives tokens (the code survived a client that failed to prove itself)', $s . ' ' . $raw);

    file_put_contents($state_file, json_encode(['access' => $tok4['access_token'], 'refresh' => $tok4['refresh_token'], 'spent' => $tok3['refresh_token'],
        'client' => $pub['client_id'], 'conf_access' => $ctok['access_token']]));
}

// ═════════════════════════════════════════════════════════════════════════════
elseif ($phase === 'no-cli') {
    $names = tool_names($state['access']);
    check(!in_array('wp_cli', $names, true) && in_array('get_post', $names, true), 'with «WP-CLI for OAuth» off the connection keeps its typed tools but loses wp_cli', $names);
    [$s, , $b, $raw] = rpc($state['access'], 'tools/call', ['name' => 'wp_cli', 'arguments' => ['command' => 'option get home']], 8);
    check(isset($b['error']) || !empty($b['result']['isError']), 'wp_cli cannot be called through OAuth', $raw);
}

// ═════════════════════════════════════════════════════════════════════════════
elseif ($phase === 'reuse') {
    // run.sh has aged the spent refresh token past its minute of grace
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'refresh_token', 'refresh_token' => $state['spent'], 'client_id' => $state['client']]);
    check($s === 400 && (json_decode($raw, true)['error'] ?? '') === 'invalid_grant', 'a spent refresh token shown after the grace minute is refused', $s . ' ' . $raw);
    [$s] = rpc($state['access'], 'ping');
    check($s === 401, '…and the whole chain of that connection is revoked (a sign of theft)', $s);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'refresh_token', 'refresh_token' => $state['refresh'], 'client_id' => $state['client']]);
    check($s === 400, 'the chain\'s live refresh token is gone too', $s . ' ' . $raw);
    [$s] = rpc($state['conf_access'], 'ping');
    check($s === 200, 'other connections are untouched', $s);
}

// ═════════════════════════════════════════════════════════════════════════════
elseif ($phase === 'revoked') {
    [$s, $h] = rpc($state['access'], 'ping');
    check($s === 401 && strpos($h['www-authenticate'] ?? '', 'invalid_token') !== false, 'a revoked connection\'s access token → 401 invalid_token', $s);
    [$s] = rpc($state['conf_access'], 'ping');
    check($s === 401, 'every revoked connection is gone', $s);
    [$s, , $raw] = post_form($token_url, ['grant_type' => 'refresh_token', 'refresh_token' => $state['refresh'], 'client_id' => $state['client']]);
    check($s === 400 && (json_decode($raw, true)['error'] ?? '') === 'invalid_grant', 'its refresh token is refused', $s . ' ' . $raw);
}

// ═════════════════════════════════════════════════════════════════════════════
elseif ($phase === 'off') {
    [$s, , $raw] = http('GET', $origin . '/.well-known/oauth-protected-resource/' . $path);
    check($s !== 200 || json_decode($raw, true) === null, 'with OAuth off the metadata is gone', $s);
    [$s] = post_json($url . '/oauth/register', ['redirect_uris' => ['http://127.0.0.1:9/cb']]);
    check($s !== 201, 'with OAuth off nobody registers', $s);
    [$s, $h] = rpc(null, 'ping');
    check($s === 401 && strpos($h['www-authenticate'] ?? '', 'resource_metadata') === false, 'the 401 no longer names the metadata', $h['www-authenticate'] ?? '');
    [$s] = http('HEAD', $url);
    check($s !== 401, 'HEAD without a key answers like any unknown page again', $s);
}
else {
    fwrite(STDERR, "unknown phase $phase\n");
    exit(2);
}

echo "\nPHP " . PHP_VERSION . " client — OAuth phase «{$phase}»: $checks checks passed against $url\n";
