<?php
/**
 * Runs in a child PHP started with open_basedir = the plugin directory (as on shared hosting):
 * PHP_BINARY and /bin/* are invisible to is_file(), but child processes can still run them.
 * Prints one "ok|FAIL <name>" line per check (tests/test-shell.php parses them).
 */
$GLOBALS['smcp_ob_transients'] = [];
function get_transient($k) { return $GLOBALS['smcp_ob_transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['smcp_ob_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['smcp_ob_transients'][$k]); return true; }

require __DIR__ . '/bootstrap.php';

$php = (string) ($argv[1] ?? '');
$out = function ($ok, $name) { echo ($ok ? 'ok' : 'FAIL') . ' ' . $name . "\n"; };

$out((bool) ini_get('open_basedir') && !@is_file($php), 'php binary hidden by open_basedir');
$r = Simple_MCP::run_shell(['/bin/echo', 'hi'], null, 10);
$out($r['code'] === 0 && trim($r['stdout']) === 'hi', 'run_shell starts processes (stdin is not a /dev/null file)');
$out(Simple_MCP::bin_path_valid('php', $php, true) === (bool) preg_match('/^php(-?\d+(\.\d+)*)?(-cli)?$/i', basename($php)), 'hidden php accepted by a probe run (when named php*)');
$out(Simple_MCP::bin_path_valid('php', dirname($php) . '/php-does-not-exist', true) === false, 'missing php rejected');
$out(Simple_MCP::bin_path_valid('php', '/bin/sh', true) === false, 'non-php name rejected without running it');
$out(Simple_MCP::php_probe($php) !== null, 'php_probe runs the hidden php');
