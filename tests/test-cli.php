<?php
/**
 * wp_cli tokenizer: shell-like quoting, executed without a shell — so control operators must be
 * refused outright and quotes must not let anything through unparsed.
 */

smcp_test('cli: tokenize splits on whitespace', function () {
    smcp_assert_same(['option', 'get', 'blogname'], Simple_MCP_CLI::tokenize('option get blogname'));
    smcp_assert_same(['a', 'b'], Simple_MCP_CLI::tokenize("  a \t  b  "));
    smcp_assert_same(['a', 'b'], Simple_MCP_CLI::tokenize("a\nb"));
    smcp_assert_same([], Simple_MCP_CLI::tokenize(''));
    smcp_assert_same([], Simple_MCP_CLI::tokenize('   '));
});

smcp_test('cli: tokenize honours double and single quotes', function () {
    smcp_assert_same(['post', 'update', '12', '--post_title=My Title'], Simple_MCP_CLI::tokenize('post update 12 --post_title="My Title"'));
    smcp_assert_same(['a', 'b c', 'd'], Simple_MCP_CLI::tokenize("a 'b c' d"));
    smcp_assert_same(['Привіт світ'], Simple_MCP_CLI::tokenize('"Привіт світ"'));
    smcp_assert_same(['ab'], Simple_MCP_CLI::tokenize('a"b"'), 'adjacent quoted part joins the token');
});

smcp_test('cli: tokenize keeps empty quoted arguments', function () {
    smcp_assert_same(['a', '', 'b'], Simple_MCP_CLI::tokenize('a "" b'));
    smcp_assert_same(['--x='], Simple_MCP_CLI::tokenize("--x=''"));
});

smcp_test('cli: tokenize backslash escapes', function () {
    smcp_assert_same(['a b'], Simple_MCP_CLI::tokenize('a\\ b'), 'escaped space outside quotes');
    smcp_assert_same(['a"b'], Simple_MCP_CLI::tokenize('"a\\"b"'), 'escaped quote inside double quotes');
    smcp_assert_same(['a\\b'], Simple_MCP_CLI::tokenize("'a\\b'"), 'single quotes are literal');
    smcp_assert_same(['a\\nb'], Simple_MCP_CLI::tokenize('"a\\nb"'), 'unknown escape inside double quotes stays literal');
});

smcp_test('cli: tokenize refuses shell control operators', function () {
    foreach (['a; b', 'a && b', 'a & b', 'a | b', 'a || b', 'a `id`', 'a $(id)', 'a > f', 'a < f', 'a>f', 'post list;id'] as $cmd) {
        smcp_assert_null(Simple_MCP_CLI::tokenize($cmd), $cmd);
    }
});

smcp_test('cli: tokenize allows metacharacters inside quotes (no shell runs them)', function () {
    smcp_assert_same(['a', ';', 'b'], Simple_MCP_CLI::tokenize('a ";" b'));
    smcp_assert_same(['a', '|'], Simple_MCP_CLI::tokenize("a '|'"));
    smcp_assert_same(['$(id)'], Simple_MCP_CLI::tokenize("'\$(id)'"));
    smcp_assert_same(['$HOME'], Simple_MCP_CLI::tokenize('$HOME'), 'no variable expansion');
});

smcp_test('cli: tokenize refuses unclosed quotes', function () {
    smcp_assert_null(Simple_MCP_CLI::tokenize('a "b'));
    smcp_assert_null(Simple_MCP_CLI::tokenize("a 'b"));
});

smcp_test('cli: Simple_MCP_Tools::tokenize is an alias of Simple_MCP_CLI::tokenize', function () {
    smcp_require_method('Simple_MCP_Tools', 'tokenize');
    foreach (['post list --format=json', 'a "b c"', 'a; b'] as $cmd) {
        smcp_assert_same(Simple_MCP_CLI::tokenize($cmd), Simple_MCP_Tools::tokenize($cmd), $cmd);
    }
});

/** Runs the pure wp_cli gate on a command string: null = allowed, string = refusal. */
function smcp_gate(string $command, array $ctx = []) {
    $tokens = Simple_MCP_CLI::tokenize($command);
    smcp_assert_true(is_array($tokens), "tokenize($command)");
    return Simple_MCP_CLI::gate($tokens, $ctx);
}

smcp_test('cli gate: @alias is always refused', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    foreach (['@prod eval "phpinfo();"', '@all option get blogname', '@self post list'] as $cmd) {
        smcp_assert_true(is_string(smcp_gate($cmd)), $cmd);
        smcp_assert_true(is_string(smcp_gate($cmd, ['server_ops' => true])), "$cmd (server_ops)");
    }
});

smcp_test('cli gate: code-exec / remote global flags are refused', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    foreach (['post list --exec="phpinfo();"', 'post list --require=/tmp/x.php', 'post list --ssh=host', 'post list --http=https://x', 'post list --path=/var/www/other'] as $cmd) {
        smcp_assert_true(is_string(smcp_gate($cmd, ['server_ops' => true])), $cmd);
    }
});

smcp_test('cli gate: server ops and their aliases need the Server ops permission', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    $ops = [
        'plugin install akismet', 'plugin update --all', 'plugin upgrade akismet', 'plugin delete akismet',
        'plugin uninstall akismet', 'theme install x', 'theme update x', 'theme upgrade x', 'theme delete x',
        'theme uninstall x', 'core update', 'core upgrade', 'core download --force', 'config set WP_DEBUG true',
        'config delete WP_DEBUG', 'config shuffle-salts', 'PLUGIN UNINSTALL akismet', 'language core install uk',
        'scaffold plugin x',
    ];
    foreach ($ops as $cmd) {
        smcp_assert_true(is_string(smcp_gate($cmd)), "$cmd without server_ops");
    }
    foreach (['plugin install akismet', 'plugin uninstall akismet', 'theme upgrade x', 'config set WP_DEBUG true'] as $cmd) {
        smcp_assert_null(smcp_gate($cmd, ['server_ops' => true]), "$cmd with server_ops");
    }
});

smcp_test('cli gate: package and cli self-modification are refused even with Server ops', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    foreach (['package install wp-cli/x', 'package update', 'cli alias add @x --ssh=host', 'cli update', 'cli aliases'] as $cmd) {
        smcp_assert_true(is_string(smcp_gate($cmd, ['server_ops' => true])), $cmd);
    }
    smcp_assert_null(smcp_gate('cli version'));
});

smcp_test('cli gate: content and read-only commands pass without Server ops', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    foreach (['option get blogname', 'post list --post_type=page --format=json', 'plugin list', 'theme list', 'core version', 'config get DB_NAME', 'post update 12 --post_title="My Title"'] as $cmd) {
        smcp_assert_null(smcp_gate($cmd), $cmd);
    }
});

smcp_test('cli gate: deny-list matches normalised commands (aliases too)', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    $ctx = ['server_ops' => true, 'deny' => ['db drop', 'db dump', 'eval']];
    foreach (['db drop --yes', 'sql drop --yes', 'DB DROP', 'db export /tmp/x.sql', 'db dump', 'eval "echo 1;"'] as $cmd) {
        smcp_assert_true(is_string(smcp_gate($cmd, $ctx)), $cmd);
    }
    smcp_assert_null(smcp_gate('db query "SELECT 1"', $ctx));
});

smcp_test('cli gate: network flags are super-admin only on multisite', function () {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    smcp_assert_true(is_string(smcp_gate('post list --url=other.example', ['multisite' => true, 'super_admin' => false])));
    smcp_assert_null(smcp_gate('post list --url=other.example', ['multisite' => true, 'super_admin' => true]));
    smcp_assert_null(smcp_gate('post list --url=example.test', ['multisite' => false]));
});

smcp_test('cli: HOME candidates are per site and the system temp dir comes first', function () {
    smcp_require_method('Simple_MCP', 'cli_home_dirs');
    $dirs = Simple_MCP::cli_home_dirs();
    smcp_assert_same(rtrim(sys_get_temp_dir(), '/\\') . '/simple-mcp-' . substr(md5(ABSPATH), 0, 12), $dirs[0]);
    smcp_assert_same($dirs, array_values(array_unique($dirs)));
});

smcp_test('cli: cli_home_prepare makes a private HOME with an empty read-only config', function () {
    smcp_require_method('Simple_MCP', 'cli_home_prepare');
    $base = smcp_temp_dir('home');
    try {
        $home = $base . '/h';
        smcp_assert_true(Simple_MCP::cli_home_prepare($home), 'fresh HOME');
        clearstatcache();
        smcp_assert_same('0700', sprintf('%04o', fileperms($home) & 0777));
        smcp_assert_same(0, filesize($home . '/config.yml'));
        smcp_assert_same(0, fileperms($home . '/config.yml') & 0222 & ~0200, 'config.yml not writable by others');
        smcp_assert_true(is_dir($home . '/packages') && is_dir($home . '/cache'));

        chmod($home, 0777); // a HOME we own but left open is narrowed again
        chmod($home . '/config.yml', 0600);
        file_put_contents($home . '/config.yml', "require: /tmp/evil.php\n");
        smcp_assert_true(Simple_MCP::cli_home_prepare($home), 'reused HOME');
        clearstatcache();
        smcp_assert_same('0700', sprintf('%04o', fileperms($home) & 0777));
        smcp_assert_same(0, filesize($home . '/config.yml'), 'config emptied');

        symlink($home, $base . '/link');
        smcp_assert_true(Simple_MCP::cli_home_prepare($base . '/link') instanceof WP_Error, 'symlinked HOME refused');
    } finally {
        @chmod($base . '/h/config.yml', 0600);
        smcp_rm_tree($base);
    }
});
