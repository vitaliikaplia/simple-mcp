<?php
/**
 * GitHub Releases updater: tag/asset/checksum/header parsing, release-notes rendering and the
 * package directory normalisation. Network and WordPress-hook behaviour is not covered here.
 */

/** A releases/latest payload shaped like the GitHub API response. */
function smcp_release_fixture(array $override = []): array {
    $dir = 'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/';
    return array_merge([
        'tag_name'     => 'v2.5.0',
        'name'         => '2.5.0',
        'draft'        => false,
        'prerelease'   => false,
        'html_url'     => 'https://github.com/vitaliikaplia/simple-mcp/releases/tag/v2.5.0',
        'published_at' => '2026-10-01T12:00:00Z',
        'body'         => "- **Fixed:** something\n",
        'assets'       => [
            ['name' => 'simple-mcp.zip', 'state' => 'uploaded', 'browser_download_url' => $dir . 'simple-mcp.zip'],
            ['name' => 'simple-mcp.zip.sha256', 'state' => 'uploaded', 'browser_download_url' => $dir . 'simple-mcp.zip.sha256'],
        ],
    ], $override);
}

smcp_test('updater: version_from_tag', function () {
    smcp_assert_same('2.5.0', Simple_MCP_GitHub_Updater::version_from_tag('v2.5.0'));
    smcp_assert_same('2.5.0', Simple_MCP_GitHub_Updater::version_from_tag('2.5.0'));
    smcp_assert_same('2.5.0', Simple_MCP_GitHub_Updater::version_from_tag(' V2.5.0 '));
    smcp_assert_same('2.5', Simple_MCP_GitHub_Updater::version_from_tag('v2.5'));
    smcp_assert_same('2.5.0-rc.1', Simple_MCP_GitHub_Updater::version_from_tag('v2.5.0-rc.1'));
    foreach (['', 'v', 'latest', 'release-2.5.0', 'v2', '2.5.0 beta', 'v2.5.0/../x', 'vv2.5.0'] as $bad) {
        smcp_assert_null(Simple_MCP_GitHub_Updater::version_from_tag($bad), var_export($bad, true));
    }
});

smcp_test('updater: parse_release extracts version, immutable assets and notes', function () {
    $release = Simple_MCP_GitHub_Updater::parse_release(smcp_release_fixture());
    smcp_assert_same('2.5.0', $release['version']);
    smcp_assert_same('v2.5.0', $release['tag']);
    smcp_assert_same('https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip', $release['package']);
    smcp_assert_same('https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip.sha256', $release['checksum_url']);
    smcp_assert_same('https://github.com/vitaliikaplia/simple-mcp/releases/tag/v2.5.0', $release['url']);
    smcp_assert_same("- **Fixed:** something\n", $release['body']);
    smcp_assert_same('2026-10-01T12:00:00Z', $release['published']);
});

smcp_test('updater: parse_release fails closed', function () {
    $dir = 'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/';
    $zip = ['name' => 'simple-mcp.zip', 'state' => 'uploaded', 'browser_download_url' => $dir . 'simple-mcp.zip'];
    $sum = ['name' => 'simple-mcp.zip.sha256', 'state' => 'uploaded', 'browser_download_url' => $dir . 'simple-mcp.zip.sha256'];
    $cases = [
        'draft'                  => ['draft' => true],
        'prerelease'             => ['prerelease' => true],
        'bad tag'                => ['tag_name' => 'latest'],
        'no assets'              => ['assets' => []],
        'zip only'               => ['assets' => [$zip]],
        'checksum only'          => ['assets' => [$sum]],
        'asset still uploading'  => ['assets' => [array_merge($zip, ['state' => 'starter']), $sum]],
        'asset of another repo'  => ['assets' => [array_merge($zip, ['browser_download_url' => 'https://github.com/evil/simple-mcp/releases/download/v2.5.0/simple-mcp.zip']), $sum]],
        'asset of another tag'   => ['assets' => [array_merge($zip, ['browser_download_url' => 'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.4.0/simple-mcp.zip']), $sum]],
        'asset off github.com'   => ['assets' => [array_merge($zip, ['browser_download_url' => 'https://example.com/simple-mcp.zip']), $sum]],
        'garbage assets'         => ['assets' => ['x', null, 5]],
    ];
    foreach ($cases as $label => $override) {
        smcp_assert_null(Simple_MCP_GitHub_Updater::parse_release(smcp_release_fixture($override)), $label);
    }
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_release([]), 'empty payload (e.g. 404 body)');
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_release(['message' => 'Not Found']), 'API error payload');
});

smcp_test('updater: parse_release ignores a foreign html_url and caps the body', function () {
    $release = Simple_MCP_GitHub_Updater::parse_release(smcp_release_fixture([
        'html_url' => 'https://evil.example/',
        'body'     => str_repeat('я', 40000), // 80 000 bytes
    ]));
    smcp_assert_same('https://github.com/vitaliikaplia/simple-mcp', $release['url']);
    smcp_assert_true(strlen($release['body']) <= 65536, 'body capped at 64 KB');
    smcp_assert_true(preg_match('//u', $release['body']) === 1, 'cut stays valid UTF-8');
});

smcp_test('updater: checksum_url_for accepts only release assets of this repo', function () {
    $pkg = 'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip';
    smcp_assert_same($pkg . '.sha256', Simple_MCP_GitHub_Updater::checksum_url_for($pkg));
    foreach ([
        'https://github.com/vitaliikaplia/simple-mcp/archive/refs/heads/master.zip',
        'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/other.zip',
        'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip?x=1',
        'https://github.com/vitaliikaplia/simple-mcp/releases/download/a/b/simple-mcp.zip',
        'https://github.com/evil/simple-mcp/releases/download/v2.5.0/simple-mcp.zip',
        'http://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip',
        'https://downloads.wordpress.org/plugin/simple-mcp.zip',
        '/tmp/simple-mcp.zip',
        '',
    ] as $url) {
        smcp_assert_null(Simple_MCP_GitHub_Updater::checksum_url_for($url), $url);
    }
});

smcp_test('updater: parse_checksum (sha256sum format)', function () {
    $hex = hash('sha256', 'simple-mcp');
    smcp_assert_same($hex, Simple_MCP_GitHub_Updater::parse_checksum("$hex  simple-mcp.zip\n", 'simple-mcp.zip'), 'text mode');
    smcp_assert_same($hex, Simple_MCP_GitHub_Updater::parse_checksum("$hex *simple-mcp.zip", 'simple-mcp.zip'), 'binary mode');
    smcp_assert_same($hex, Simple_MCP_GitHub_Updater::parse_checksum(strtoupper($hex) . "  simple-mcp.zip\r\n", 'simple-mcp.zip'), 'upper case + CRLF');
    smcp_assert_same($hex, Simple_MCP_GitHub_Updater::parse_checksum($hex, 'simple-mcp.zip'), 'bare hash');
    smcp_assert_same($hex, Simple_MCP_GitHub_Updater::parse_checksum(hash('sha256', 'x') . "  other.zip\n$hex  simple-mcp.zip\n", 'simple-mcp.zip'), 'multi-line list');
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_checksum("$hex  other.zip", 'simple-mcp.zip'), 'another file');
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_checksum(substr($hex, 1) . '  simple-mcp.zip', 'simple-mcp.zip'), 'short hash');
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_checksum(md5('x') . '  simple-mcp.zip', 'simple-mcp.zip'), 'md5');
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_checksum('<html>Not Found</html>', 'simple-mcp.zip'));
    smcp_assert_null(Simple_MCP_GitHub_Updater::parse_checksum('', 'simple-mcp.zip'));
});

smcp_test('updater: parse_headers reads the plugin header like get_file_data', function () {
    $php = "<?php\n/**\n * Plugin Name: Simple MCP\n * Version: 2.5.0\n * Requires at least: 6.0\n * Requires PHP: 8.1\n * Tested up to: 7.0\n */\n";
    smcp_assert_same(['version' => '2.5.0', 'requires' => '6.0', 'requires_php' => '8.1', 'tested' => '7.0'], Simple_MCP_GitHub_Updater::parse_headers($php));
    smcp_assert_same('', Simple_MCP_GitHub_Updater::parse_headers("<?php\n// nothing\n")['version']);
    smcp_assert_same('1.0', Simple_MCP_GitHub_Updater::parse_headers("<?php /* Version: 1.0 */")['version'], 'closing comment stripped');
});

smcp_test('updater: parse_headers of the real simple-mcp.php matches the constant', function () {
    $headers = Simple_MCP_GitHub_Updater::parse_headers((string) file_get_contents(SIMPLE_MCP_FILE));
    smcp_assert_same(SIMPLE_MCP_VERSION, $headers['version']);
    smcp_assert_true($headers['tested'] !== '', 'Tested up to header present');
    smcp_assert_same('8.1', $headers['requires_php']);
});

smcp_test('updater: the plugin declares Update URI', function () {
    $php = (string) file_get_contents(SIMPLE_MCP_FILE);
    smcp_assert_true(preg_match('/^[ \t\/*#@]*Update URI:\s*https:\/\/github\.com\/vitaliikaplia\/simple-mcp\s*$/mi', $php) === 1);
});

smcp_test('updater: markdown_to_html renders release notes safely', function () {
    $md = "### 2.5.0 — Title\n\n- **Fixed:** `block_update` <b>x</b>\n  continued line\n- see [docs](https://example.com/a?b=1&c=2)\n\nPlain <script>alert(1)</script> text\nsecond line\n";
    $expected = '<h4>2.5.0 — Title</h4>'
        . '<ul><li><strong>Fixed:</strong> <code>block_update</code> &lt;b&gt;x&lt;/b&gt; continued line</li>'
        . '<li>see <a href="https://example.com/a?b=1&#038;c=2">docs</a></li></ul>'
        . '<p>Plain &lt;script&gt;alert(1)&lt;/script&gt; text second line</p>';
    smcp_assert_same($expected, Simple_MCP_GitHub_Updater::markdown_to_html($md));
});

smcp_test('updater: markdown_to_html refuses non-http links and escapes code', function () {
    smcp_assert_same('<p>[x](javascript:alert(1))</p>', Simple_MCP_GitHub_Updater::markdown_to_html('[x](javascript:alert(1))'));
    smcp_assert_same('<p><code>&lt;a href=&quot;x&quot;&gt;</code></p>', Simple_MCP_GitHub_Updater::markdown_to_html('`<a href="x">`'));
    smcp_assert_same('<p>a</p><p>b</p>', Simple_MCP_GitHub_Updater::markdown_to_html("a\n\n\nb"));
    smcp_assert_same('', Simple_MCP_GitHub_Updater::markdown_to_html(''));
});

smcp_test('updater: normalize_github_source_directory handles release and branch archives', function () {
    $updater = new Simple_MCP_GitHub_Updater();
    $extra   = ['plugin' => SIMPLE_MCP_BASENAME, 'type' => 'plugin', 'action' => 'update'];
    $GLOBALS['wp_filesystem'] = null;

    $work = smcp_temp_dir('release');
    try {
        // Release zip: top-level "simple-mcp/" already matches the slug — left as is.
        mkdir($work . '/simple-mcp');
        smcp_assert_same($work . '/simple-mcp/', $updater->normalize_github_source_directory($work . '/simple-mcp/', $work, null, $extra));
    } finally {
        smcp_rm_tree($work);
    }

    $work = smcp_temp_dir('branch');
    try {
        // Branch archive: "simple-mcp-master/" is renamed to the slug.
        mkdir($work . '/simple-mcp-master');
        file_put_contents($work . '/simple-mcp-master/simple-mcp.php', '<?php');
        smcp_assert_same($work . '/simple-mcp/', $updater->normalize_github_source_directory($work . '/simple-mcp-master/', $work, null, $extra));
        smcp_assert_true(is_file($work . '/simple-mcp/simple-mcp.php'));
        smcp_assert_false(is_dir($work . '/simple-mcp-master'));
    } finally {
        smcp_rm_tree($work);
    }

    $work = smcp_temp_dir('foreign');
    try {
        // Another plugin's upgrade, or an unrelated directory name — untouched.
        mkdir($work . '/other-plugin');
        smcp_assert_same($work . '/other-plugin/', $updater->normalize_github_source_directory($work . '/other-plugin/', $work, null, ['plugin' => 'other/other.php']));
        smcp_assert_same($work . '/other-plugin/', $updater->normalize_github_source_directory($work . '/other-plugin/', $work, null, $extra));
    } finally {
        smcp_rm_tree($work);
    }

    $error = new WP_Error('x', 'y');
    smcp_assert_same($error, $updater->normalize_github_source_directory($error, '', null, $extra));
});

smcp_test('updater: verify_package ignores foreign packages', function () {
    $updater = new Simple_MCP_GitHub_Updater();
    smcp_assert_false($updater->verify_package(false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, ['plugin' => 'akismet/akismet.php']));
    smcp_assert_same('/tmp/x.zip', $updater->verify_package('/tmp/x.zip', '/tmp/x.zip', null, ['type' => 'plugin', 'action' => 'install']));
    $error = new WP_Error('x', 'y');
    smcp_assert_same($error, $updater->verify_package($error, 'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip', null, ['plugin' => SIMPLE_MCP_BASENAME]));
});

smcp_test('updater: without GitHub data only release-asset entries survive for this plugin', function () {
    if (defined('SIMPLE_MCP_UPDATE_CHANNEL')) {
        smcp_skip('SIMPLE_MCP_UPDATE_CHANNEL is defined in this environment');
    }
    $updater = new Simple_MCP_GitHub_Updater(); // test stubs: empty cache, no network on reads
    $entry   = function ($package) { return (object) ['package' => $package, 'new_version' => '99.0']; };
    $release = 'https://github.com/vitaliikaplia/simple-mcp/releases/download/v2.5.0/simple-mcp.zip';
    foreach ([
        'wordpress.org package'      => ['https://downloads.wordpress.org/plugin/simple-mcp.99.0.zip', false],
        'branch zip of this repo'    => ['https://github.com/vitaliikaplia/simple-mcp/archive/refs/heads/master.zip', false],
        'API zipball of this repo'   => ['https://api.github.com/repos/vitaliikaplia/simple-mcp/zipball/v99.0', false],
        'lookalike repository'       => ['https://github.com/vitaliikaplia/simple-mcp-evil/releases/download/v9/simple-mcp.zip', false],
        'release asset of this repo' => [$release, true],
    ] as $label => [$package, $kept]) {
        $t = (object) [
            'checked'   => [SIMPLE_MCP_BASENAME => '2.4.0', 'akismet/akismet.php' => '5.0'],
            'response'  => [SIMPLE_MCP_BASENAME => $entry($package), 'akismet/akismet.php' => $entry('https://downloads.wordpress.org/plugin/akismet.zip')],
            'no_update' => [SIMPLE_MCP_BASENAME => $entry($package)],
        ];
        $t = $updater->read_update_plugins($t);
        smcp_assert_same($kept, isset($t->response[SIMPLE_MCP_BASENAME]), "$label (response)");
        smcp_assert_same($kept, isset($t->no_update[SIMPLE_MCP_BASENAME]), "$label (no_update)");
        smcp_assert_true(isset($t->response['akismet/akismet.php']), "$label: other plugins untouched");
    }
});

smcp_test('updater: verify_package refuses a non-release package for this plugin', function () {
    if (defined('SIMPLE_MCP_UPDATE_CHANNEL')) {
        smcp_skip('SIMPLE_MCP_UPDATE_CHANNEL is defined in this environment');
    }
    $updater = new Simple_MCP_GitHub_Updater();
    $result  = $updater->verify_package(false, 'https://github.com/vitaliikaplia/simple-mcp/archive/refs/heads/master.zip', null, ['plugin' => SIMPLE_MCP_BASENAME]);
    smcp_assert_true($result instanceof WP_Error);
    smcp_assert_same('simple_mcp_untrusted_package', $result->get_error_code());
});
