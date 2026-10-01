<?php
/**
 * Test bootstrap: a tiny assertion framework plus the minimal WordPress stubs needed to load the
 * plugin classes and exercise their pure (WordPress-independent) logic. No WordPress, no database.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
// Some PHP builds (e.g. macOS hardened runtime) cannot allocate PCRE JIT memory and warn on the first
// preg_*() call; run.php turns that warning into a failure. The tests do not need the JIT.
ini_set('pcre.jit', '0');

define('SMCP_TEST_ROOT', dirname(__DIR__));

// ── WordPress constants & stubs (only what the loaded classes touch) ─────────

if (!defined('ABSPATH'))             define('ABSPATH', sys_get_temp_dir() . '/smcp-fake-wp/');
if (!defined('MINUTE_IN_SECONDS'))   define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS'))     define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))      define('DAY_IN_SECONDS', 86400);
if (!defined('SIMPLE_MCP_FILE'))     define('SIMPLE_MCP_FILE', SMCP_TEST_ROOT . '/simple-mcp.php');
if (!defined('SIMPLE_MCP_DIR'))      define('SIMPLE_MCP_DIR', SMCP_TEST_ROOT . '/');
if (!defined('SIMPLE_MCP_BASENAME')) define('SIMPLE_MCP_BASENAME', 'simple-mcp/simple-mcp.php');
if (!defined('SIMPLE_MCP_VERSION')) {
    define('SIMPLE_MCP_VERSION', preg_match("/define\('SIMPLE_MCP_VERSION',\s*'([^']+)'/", (string) file_get_contents(SIMPLE_MCP_FILE), $m) ? $m[1] : '0.0.0');
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

$GLOBALS['smcp_test_filters'] = [];
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['smcp_test_filters'][$hook][] = $callback; return true; }
function add_action($hook, $callback, $priority = 10, $args = 1) { return add_filter($hook, $callback, $priority, $args); }
function apply_filters($hook, $value) { return $value; }
function do_action($hook) {}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function __($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
function esc_url($url) {
    $url = trim((string) $url);
    if (!preg_match('#^https?://#i', $url)) return '';
    return str_replace(['&', "'", '"', '<', '>'], ['&#038;', '&#039;', '&quot;', '', ''], $url);
}
function trailingslashit($value) { return rtrim((string) $value, '/\\') . '/'; }
function untrailingslashit($value) { return rtrim((string) $value, '/\\'); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_option($name, $default = false) { return $default; }
function get_site_transient($key) { return false; }
function set_site_transient($key, $value, $ttl = 0) { return true; }
function delete_site_transient($key) { return true; }
function is_admin() { return false; }
function current_user_can($cap) { return false; }
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
if (!function_exists('array_is_list')) { // PHP 8.0 (WordPress ships the same polyfill)
    function array_is_list($arr) {
        $i = 0;
        foreach ($arr as $k => $v) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}

// ── Load the units under test ────────────────────────────────────────────────

foreach ([
    'includes/class-simple-mcp.php',
    'includes/class-simple-mcp-cli.php',
    'includes/class-auth.php',
    'includes/class-audit.php',
    'includes/class-tools.php',
    'includes/tools/class-simple-mcp-tools-blocks.php',
    'includes/class-simple-mcp-github-updater.php',
    'bin/release-lib.php',
] as $file) {
    require_once SMCP_TEST_ROOT . '/' . $file;
}

// ── Mini test framework ──────────────────────────────────────────────────────

class SMCP_Test_Failure extends Exception {}
class SMCP_Test_Skipped extends Exception {}

$GLOBALS['smcp_tests'] = [];

/** Registers a test case. */
function smcp_test(string $name, callable $fn): void {
    $GLOBALS['smcp_tests'][] = [$name, $fn];
}

function smcp_skip(string $reason): void {
    throw new SMCP_Test_Skipped($reason);
}

function smcp_export($value): string {
    return str_replace("\n", ' ', var_export($value, true));
}

function smcp_assert_same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new SMCP_Test_Failure(($message !== '' ? $message . ': ' : '') . 'expected ' . smcp_export($expected) . ', got ' . smcp_export($actual));
    }
}

function smcp_assert_true($actual, string $message = ''): void {
    smcp_assert_same(true, $actual, $message);
}

function smcp_assert_false($actual, string $message = ''): void {
    smcp_assert_same(false, $actual, $message);
}

function smcp_assert_null($actual, string $message = ''): void {
    smcp_assert_same(null, $actual, $message);
}

/** Skips the current test unless Class::method exists (lets tests run before a role lands it). */
function smcp_require_method(string $class, string $method): void {
    if (!class_exists($class) || !method_exists($class, $method)) {
        smcp_skip("$class::$method() is not available in this build");
    }
}

function smcp_temp_dir(string $prefix): string {
    $dir = sys_get_temp_dir() . '/smcp-test-' . $prefix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    return $dir;
}

function smcp_rm_tree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (array_diff((array) scandir($dir), ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) && !is_link($path) ? smcp_rm_tree($path) : @unlink($path);
    }
    @rmdir($dir);
}
