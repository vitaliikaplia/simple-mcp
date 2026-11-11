<?php
/**
 * OAuth (Simple_MCP_OAuth): адреси повернення й політика «лише відомі агенти», PKCE, ресурс і метадані,
 * відповідь клієнту, заголовок 401, «переносні» схеми tools/list, маскування токенів у журналі.
 * Сховище, згода і token endpoint — у tests/e2e/oauth.php на справжньому WordPress.
 */

/** Виконати $fn з налаштуваннями плагіна $opts (поверх типових) і повернути результат */
function smcp_with_options(array $opts, callable $fn) {
    $prev = $GLOBALS['smcp_test_options'];
    $GLOBALS['smcp_test_options'][Simple_MCP::OPTION] = $opts;
    try {
        return $fn();
    } finally {
        $GLOBALS['smcp_test_options'] = $prev;
    }
}

smcp_test('oauth: OAuth is off by default, WP-CLI for OAuth is on, redirects are limited to known agents', function () {
    smcp_assert_false(Simple_MCP_OAuth::enabled(), 'off until the administrator enables it');
    smcp_assert_true(Simple_MCP_OAuth::wp_cli_allowed(), 'WP-CLI follows the role by default');
    smcp_assert_false(Simple_MCP_OAuth::any_redirect(), 'known agents only by default');
    smcp_assert_true(smcp_with_options(['oauth_enabled' => true], ['Simple_MCP_OAuth', 'enabled']));
    smcp_assert_false(smcp_with_options(['oauth_enabled' => true, 'enabled' => false], ['Simple_MCP_OAuth', 'enabled']), 'the server switch wins');
});

smcp_test('oauth: redirect addresses a code may be sent to', function () {
    foreach ([
        'https://chatgpt.com/connector_platform_oauth_redirect' => '',
        'https://claude.ai/api/mcp/auth_callback'               => '',
        'http://127.0.0.1:33418/callback'                       => '',
        'http://localhost:8080/cb'                              => '',
        'http://[::1]:9/cb'                                     => '',
        'cursor://anysphere.cursor-retrieval/oauth/callback'    => '',
        'http://example.com/cb'                                 => 'http',
        'https://example.com/cb#frag'                           => 'fragment',
        'javascript:alert(1)'                                   => 'scheme',
        'data:text/html,x'                                      => 'scheme',
        'file:///etc/passwd'                                    => 'scheme',
        ''                                                      => 'invalid',
        "https://example.com/\ncb"                              => 'invalid',
        'https:///nohost'                                       => 'invalid',
        // PHP і браузер мусять бачити один хост: \ і userinfo — ні (рев'ю 11.11.2026)
        'https://attacker.example\\@claude.ai/api/mcp/auth_callback' => 'invalid',
        'http://attacker.example\\@127.0.0.1/callback'              => 'invalid',
        'https://claude.ai@attacker.example/cb'                     => 'invalid',
        'https://user:pass@claude.ai/cb'                            => 'invalid',
        'https://пример.укр/cb'                                     => 'invalid',
        'https://xn--e1afmkfd.xn--j1amh/cb'                         => '',
        'search-ms:query=x&crumb=location:%5C%5Cattacker'           => 'scheme',
        'mailto:x@example.com'                                      => 'scheme',
    ] as $uri => $want) {
        smcp_assert_same($want, Simple_MCP_OAuth::redirect_problem($uri), var_export($uri, true));
    }
    smcp_assert_same('invalid', Simple_MCP_OAuth::redirect_problem(['https://x.test']), 'not a string');
    smcp_assert_same('invalid', Simple_MCP_OAuth::redirect_problem('https://x.test/' . str_repeat('a', 600)), 'too long');
});

smcp_test('oauth: known agents by the address the code goes to, never by the name a client claims', function () {
    foreach ([
        'https://chatgpt.com/connector_platform_oauth_redirect'          => 'ChatGPT',
        'https://platform.openai.com/apps-manage/oauth'                  => 'OpenAI',
        'https://evil.openai.com/cb'                                     => '',
        'https://attacker.example\\@claude.ai/api/mcp/auth_callback'     => '',
        'http://attacker.example\\@127.0.0.1/cb'                          => '',
        'com.example.agent:/oauth/callback'                              => 'Застосунок на цьому комп\'ютері',
        'vscode://some.extension/cb'                                     => 'Застосунок на цьому комп\'ютері',
        'myapp://cb'                                                     => '',
        'search-ms:query=x'                                              => '',
        'https://claude.ai/api/mcp/auth_callback'                        => 'Claude',
        'https://claude.com/api/mcp/auth_callback'                       => 'Claude',
        'https://oauth-redirect.googleusercontent.com/r/user_bound-x'    => 'Gemini',
        'https://oauth-redirect-sandbox.googleusercontent.com/r/x'       => 'Gemini',
        'http://127.0.0.1:1455/auth/callback'                            => 'Агент на цьому комп\'ютері',
        'cursor://anysphere.cursor-retrieval/oauth/callback'             => 'Застосунок на цьому комп\'ютері',
        'https://script.googleusercontent.com/macros/x'                  => '',
        'https://chatgpt.com.evil.example/cb'                            => '',
        'https://evilchatgpt.com/cb'                                     => '',
        'https://sub.claude.ai/cb'                                       => '',
        'http://chatgpt.com/cb'                                          => '',
        'https://example.com/cb'                                         => '',
        'javascript:alert(1)'                                            => '',
    ] as $uri => $want) {
        smcp_assert_same($want, Simple_MCP_OAuth::known_agent($uri), $uri);
    }
    smcp_assert_true(Simple_MCP_OAuth::redirect_allowed('https://claude.ai/api/mcp/auth_callback'));
    smcp_assert_false(Simple_MCP_OAuth::redirect_allowed('https://example.com/cb'), 'unknown host under the default policy');
    smcp_assert_true(smcp_with_options(['oauth_any_redirect' => true], function () {
        return Simple_MCP_OAuth::redirect_allowed('https://example.com/cb');
    }), 'any address once the administrator allows it');
    smcp_assert_false(smcp_with_options(['oauth_any_redirect' => true], function () {
        return Simple_MCP_OAuth::redirect_allowed('javascript:alert(1)');
    }), 'never a dangerous scheme');
});

smcp_test('oauth: a client name loses control and bidi characters', function () {
    smcp_assert_same('Claude moc.liame', Simple_MCP_OAuth::text("Claude\u{202E}moc.liame", 120));
    smcp_assert_same('a b', Simple_MCP_OAuth::text("a\x07\n\tb", 120));
    smcp_assert_same('Клод', Simple_MCP_OAuth::text('Клод', 120));
    smcp_assert_same('', Simple_MCP_OAuth::text(['x'], 120));
});

smcp_test('oauth: the authorization server metadata answers only for this site\'s issuer', function () {
    smcp_assert_same('', Simple_MCP_OAuth::home_path(), 'a site at the root of its host');
});

smcp_test('oauth: a loopback redirect matches on any port, everything else exactly', function () {
    $reg = ['http://127.0.0.1:33418/callback', 'https://claude.ai/api/mcp/auth_callback'];
    smcp_assert_true(Simple_MCP_OAuth::redirect_matches($reg, 'http://127.0.0.1:50000/callback'));
    smcp_assert_true(Simple_MCP_OAuth::redirect_matches($reg, 'https://claude.ai/api/mcp/auth_callback'));
    smcp_assert_false(Simple_MCP_OAuth::redirect_matches($reg, 'http://127.0.0.1:50000/other'));
    smcp_assert_false(Simple_MCP_OAuth::redirect_matches($reg, 'http://localhost:50000/callback'), 'another loopback host');
    smcp_assert_false(Simple_MCP_OAuth::redirect_matches($reg, 'https://claude.ai/api/mcp/auth_callback/x'));
    smcp_assert_false(Simple_MCP_OAuth::redirect_matches($reg, ''));
});

smcp_test('oauth: PKCE S256 (RFC 7636 appendix B)', function () {
    $verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    smcp_assert_true(Simple_MCP_OAuth::pkce_ok($verifier, $challenge));
    smcp_assert_false(Simple_MCP_OAuth::pkce_ok($verifier . 'x', $challenge));
    smcp_assert_false(Simple_MCP_OAuth::pkce_ok('short', $challenge), 'a verifier shorter than 43 characters');
    smcp_assert_false(Simple_MCP_OAuth::pkce_ok(['x'], $challenge));
});

smcp_test('oauth: the resource, the metadata and the answer to the client', function () {
    smcp_assert_same('https://example.test/simple-mcp', Simple_MCP_OAuth::resource());
    smcp_assert_same('https://example.test', Simple_MCP_OAuth::issuer());
    smcp_assert_same('https://example.test/simple-mcp', Simple_MCP_OAuth::canonical_resource(''));
    smcp_assert_same('https://example.test/simple-mcp', Simple_MCP_OAuth::canonical_resource('https://example.test/simple-mcp/'));
    smcp_assert_same('https://example.test/simple-mcp', Simple_MCP_OAuth::canonical_resource('https://example.test'));
    smcp_assert_null(Simple_MCP_OAuth::canonical_resource('https://other.test/mcp'));

    $as = Simple_MCP_OAuth::server_metadata();
    smcp_assert_same('https://example.test', $as['issuer']);
    smcp_assert_same('https://example.test/simple-mcp/oauth/authorize', $as['authorization_endpoint']);
    smcp_assert_same('https://example.test/simple-mcp/oauth/token', $as['token_endpoint']);
    smcp_assert_same('https://example.test/simple-mcp/oauth/register', $as['registration_endpoint']);
    smcp_assert_same(['S256'], $as['code_challenge_methods_supported']);
    smcp_assert_true($as['authorization_response_iss_parameter_supported']);

    $pr = Simple_MCP_OAuth::resource_metadata();
    smcp_assert_same('https://example.test/simple-mcp', $pr['resource']);
    smcp_assert_same(['https://example.test'], $pr['authorization_servers']);
    smcp_assert_same('https://example.test/.well-known/oauth-protected-resource/simple-mcp', Simple_MCP_OAuth::resource_metadata_url());

    $url = Simple_MCP_OAuth::answer_url('https://claude.ai/api/mcp/auth_callback?x=1', ['code' => 'abc', 'state' => 's t', 'error' => '']);
    smcp_assert_same('https://claude.ai/api/mcp/auth_callback?x=1&code=abc&state=s%20t&iss=https%3A%2F%2Fexample.test', $url);
    smcp_assert_same('chatgpt.com', Simple_MCP_OAuth::destination('https://ChatGPT.com/x'));
    smcp_assert_same('cursor', Simple_MCP_OAuth::destination('cursor://cb'));
});

smcp_test('oauth: the 401 points at the resource metadata only while OAuth is on', function () {
    smcp_assert_same('Bearer realm="simple-mcp"', Simple_MCP_Auth::challenge());
    $on = smcp_with_options(['oauth_enabled' => true], function () { return Simple_MCP_Auth::challenge(true); });
    smcp_assert_same('Bearer realm="simple-mcp", resource_metadata="https://example.test/.well-known/oauth-protected-resource/simple-mcp", '
        . 'error="invalid_token", error_description="The access token is invalid or revoked"', $on);
});

smcp_test('oauth: access tokens are told apart from personal keys by their prefix', function () {
    smcp_assert_true(Simple_MCP_OAuth::is_access_token('smcp_oat_' . str_repeat('a', 43)));
    smcp_assert_false(Simple_MCP_OAuth::is_access_token('smcp-1-' . str_repeat('a', 64)));
    smcp_assert_false(Simple_MCP_OAuth::is_access_token('smcp_ort_' . str_repeat('a', 43)), 'a refresh token is not an access token');
    smcp_assert_same(0, Simple_MCP_Auth::token_user_id('smcp_oat_' . str_repeat('a', 43)), 'never parsed as a personal key');
    $r = Simple_MCP_OAuth::random(32);
    smcp_assert_true((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $r), 'random() is 43 base64url characters for 32 bytes');
});

smcp_test('oauth: tools/list shows portable schemas; calls are still checked against the full one', function () {
    $schema = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'properties'           => [
            'post_id' => ['type' => ['integer', 'string'], 'description' => 'ID or selector.'],
            'mode'    => ['type' => 'string', 'enum' => ['a', 'b'], 'maxLength' => 5],
            'name'    => ['type' => 'string', 'maxLength' => 60],
            'opts'    => ['type' => 'object', 'additionalProperties' => false, 'properties' => [], 'required' => []],
            'list'    => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['type' => ['type' => 'string']]]],
        ],
        'required' => ['post_id'],
    ];
    $p = Simple_MCP_Tools::portable($schema);
    smcp_assert_false(isset($p['additionalProperties']));
    smcp_assert_same(['post_id'], $p['required']);
    smcp_assert_same([['type' => 'integer'], ['type' => 'string']], $p['properties']['post_id']['anyOf']);
    smcp_assert_false(isset($p['properties']['post_id']['type']), 'a union type becomes anyOf');
    smcp_assert_same('ID or selector.', $p['properties']['post_id']['description']);
    smcp_assert_false(isset($p['properties']['mode']['maxLength']), 'no length beside an enum');
    smcp_assert_same(60, $p['properties']['name']['maxLength'], 'a length without an enum stays');
    smcp_assert_true($p['properties']['opts']['properties'] instanceof stdClass, 'empty properties stay a JSON object');
    smcp_assert_false(isset($p['properties']['opts']['required']), 'no empty required');
    smcp_assert_false(isset($p['properties']['list']['items']['additionalProperties']), 'nested schemas too');
    smcp_assert_same(['type' => ['type' => 'string']], $p['properties']['list']['items']['properties'], 'a property named "type" is a property, not a keyword');
    smcp_assert_same('{"type":"object","properties":{}}', json_encode(Simple_MCP_Tools::portable(['type' => 'object', 'properties' => []])));
});

smcp_test('oauth: tokens and secrets never reach the audit log', function () {
    $s = Simple_MCP_Audit::redact_text('access smcp_oat_AbCdEfGh123_-xyz refresh smcp_ort_ZZZZZZZZZZ secret smcp_ocs_QQQQQQQQQQQ');
    smcp_assert_same('access smcp_oat_[redacted] refresh smcp_ort_[redacted] secret smcp_ocs_[redacted]', $s);
});
