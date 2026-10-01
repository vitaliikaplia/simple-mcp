<?php
/**
 * Process launching on hosts with open_basedir. 2.5.0 opened stdin as a /dev/null FILE, which
 * proc_open resolves in the PHP process — outside open_basedir, so every wp_cli call failed and
 * valid wp/php paths were rejected because is_file() cannot see them.
 */

smcp_test('shell: run_shell + binary checks work under open_basedir', function () {
    if (!function_exists('proc_open') || DIRECTORY_SEPARATOR === '\\' || !is_executable('/bin/echo')) {
        smcp_skip('needs proc_open and /bin/echo');
    }
    $r = Simple_MCP::run_shell([PHP_BINARY, '-n', '-d', 'open_basedir=' . SMCP_TEST_ROOT . '/', SMCP_TEST_ROOT . '/tests/open-basedir-child.php', PHP_BINARY], null, 60);
    smcp_assert_same(0, $r['code'], 'child exit code; stderr: ' . $r['stderr']);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $r['stdout']))));
    smcp_assert_true(count($lines) >= 6, 'child printed its checks: ' . $r['stdout'] . $r['stderr']);
    foreach ($lines as $line) {
        smcp_assert_true(strpos($line, 'ok ') === 0, $line);
    }
});
