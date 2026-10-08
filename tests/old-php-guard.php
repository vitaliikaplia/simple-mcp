<?php
/**
 * Below the minimum PHP (CI job "old-php", PHP 7.4 and 8.0): the plugin's main file must not fatal — it
 * loads none of its classes and registers an admin notice instead. Not part of tests/run.php.
 *
 *   php tests/old-php-guard.php
 */
if (PHP_VERSION_ID >= 80100) {
    echo "PHP " . PHP_VERSION . " meets the minimum — nothing to check here (run on PHP < 8.1).\n";
    exit(0);
}

define('ABSPATH', __DIR__ . '/');
$GLOBALS['smcp_actions'] = [];
function add_action($hook, $callback) { $GLOBALS['smcp_actions'][$hook][] = $callback; }
function current_user_can($cap) { return $cap === 'activate_plugins'; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
// Deliberately no plugin_dir_path() / register_activation_hook(): reaching them is a fatal error.

include dirname(__DIR__) . '/simple-mcp.php';

$fail = [];
if (defined('SIMPLE_MCP_VERSION')) $fail[] = 'SIMPLE_MCP_VERSION is defined: the guard did not stop loading';
if (class_exists('Simple_MCP', false)) $fail[] = 'class Simple_MCP was loaded';
foreach (['admin_notices', 'network_admin_notices'] as $hook) {
    if (count(isset($GLOBALS['smcp_actions'][$hook]) ? $GLOBALS['smcp_actions'][$hook] : []) !== 1) {
        $fail[] = "no $hook callback";
    }
}
if (isset($simple_mcp_old_php)) $fail[] = 'the helper variable leaked into the global scope';
if (!$fail) {
    ob_start();
    call_user_func($GLOBALS['smcp_actions']['admin_notices'][0]);
    $html = ob_get_clean();
    if (strpos($html, 'PHP 8.1') === false || strpos($html, PHP_VERSION) === false || strpos($html, 'notice-error') === false) {
        $fail[] = 'unexpected notice: ' . $html;
    }
}

if ($fail) {
    echo "FAIL on PHP " . PHP_VERSION . ":\n- " . implode("\n- ", $fail) . "\n";
    exit(1);
}
echo "ok   PHP " . PHP_VERSION . ": the plugin bails out with an admin notice instead of a fatal error\n";
