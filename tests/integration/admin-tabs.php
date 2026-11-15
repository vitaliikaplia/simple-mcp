<?php
/**
 * Integration test (needs WordPress):
 *
 *   wp eval-file wp-content/plugins/simple-mcp/tests/integration/admin-tabs.php
 *
 * The settings page in tabs (2.7.4): every tab renders only its own part and form; the form of a tab saves
 * only its own fields (an unchecked box is absent from POST, so saving one tab must not switch off another
 * tab's settings); a POST without a tab — the single-page form from before 2.7.4, still open in a browser
 * during the update — saves everything as before; old log links land on «Журнал». Writes the plugin option
 * and restores it at the end. Exit code 1 on any failure.
 */
if (!defined('ABSPATH')) exit;
require_once ABSPATH . 'wp-admin/includes/template.php';

// wp eval-file runs this file inside a function, so the counters live in $GLOBALS explicitly
$GLOBALS['smcp_tabs_fail']   = 0;
$GLOBALS['smcp_tabs_checks'] = 0;
function smcp_tabs_check($ok, $what, $context = null) {
    $GLOBALS['smcp_tabs_checks']++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        $GLOBALS['smcp_tabs_fail']++;
        if ($context !== null) echo '     ' . substr(is_string($context) ? $context : wp_json_encode($context), 0, 600) . "\n";
    }
}

$smcp_admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if (!$smcp_admins) {
    echo "SKIP: no administrator\n";
    return;
}
wp_set_current_user((int) $smcp_admins[0]);

// PHP notices from the plugin while rendering count as failures
$smcp_tabs_notices = [];
set_error_handler(function ($no, $msg, $file, $line) use (&$smcp_tabs_notices) {
    if (strpos((string) $file, 'simple-mcp') !== false) $smcp_tabs_notices[] = basename($file) . ":$line $msg";
    return false;
});

/** Render the page for these GET parameters */
function smcp_tabs_render(array $get) {
    $_GET = $get;
    ob_start();
    Simple_MCP_Admin::render();
    return (string) ob_get_clean();
}

/** handle_save() with this POST; returns the redirect location (the redirect is stopped before exit) */
function smcp_tabs_save(array $post) {
    $_POST = $_REQUEST = $post + ['action' => 'simple_mcp_save', '_wpnonce' => wp_create_nonce('simple_mcp_save')];
    $loc = null;
    $stop = function ($location) use (&$loc) { $loc = $location; throw new RuntimeException('redirect'); };
    add_filter('wp_redirect', $stop, 0);
    try {
        Simple_MCP_Admin::handle_save();
    } catch (RuntimeException $e) {
        // the redirect — expected
    } finally {
        remove_filter('wp_redirect', $stop, 0);
        $_POST = $_REQUEST = [];
    }
    return (string) $loc;
}

$smcp_orig = get_option(Simple_MCP::OPTION, null);
try {
    // ── Rendering ────────────────────────────────────────────────────────────
    $smcp_fields = ['roles' => 'name="roles[', 'oauth' => 'name="oauth_enabled"', 'settings' => 'name="rate_limit"', 'log' => 'simple_mcp_clear_log'];
    foreach (Simple_MCP_Admin::TABS as $smcp_tab => $smcp_label) {
        $smcp_html = smcp_tabs_render(['page' => 'simple-mcp', 'tab' => $smcp_tab]);
        smcp_tabs_check(substr_count($smcp_html, 'nav-tab-active') === 1
            && preg_match('~class="nav-tab nav-tab-active"[^>]*>' . preg_quote(esc_html($smcp_label), '~') . '</a>~', $smcp_html),
            "tab «{$smcp_label}» is the active one");
        smcp_tabs_check(preg_match_all('~class="nav-tab(?: nav-tab-active)?"~', $smcp_html) === count(Simple_MCP_Admin::TABS), "tab «{$smcp_label}»: all six tabs in the bar");
        preg_match_all('~name="tab" value="([a-z_]+)"~', $smcp_html, $smcp_m);
        $smcp_want = in_array($smcp_tab, Simple_MCP_Admin::FORM_TABS, true) ? [$smcp_tab] : ($smcp_tab === 'log' ? ['log'] : []);
        smcp_tabs_check($smcp_m[1] === $smcp_want, "tab «{$smcp_label}»: its own form only", $smcp_m[1]);
        foreach ($smcp_fields as $smcp_owner => $smcp_needle) {
            $smcp_has = strpos($smcp_html, $smcp_needle) !== false;
            if ($smcp_owner === $smcp_tab) smcp_tabs_check($smcp_has, "tab «{$smcp_label}» shows $smcp_needle");
            elseif ($smcp_has) smcp_tabs_check(false, "tab «{$smcp_label}» must not show $smcp_needle");
        }
    }
    $smcp_html = smcp_tabs_render(['page' => 'simple-mcp']);
    smcp_tabs_check(strpos($smcp_html, 'class="nav-tab nav-tab-active" aria-current="page">Підключення<') !== false, 'no tab → «Підключення»');
    smcp_tabs_check(strpos($smcp_html, 'simple-mcp-log') !== false && strpos($smcp_html, 'location.replace') !== false, '… with the script that takes old #simple-mcp-log links to «Журнал»');
    $_GET = ['page' => 'simple-mcp', 'log_page' => '2'];
    smcp_tabs_check(Simple_MCP_Admin::current_tab() === 'log', 'old log pagination link → «Журнал»');
    $_GET = ['page' => 'simple-mcp', 'tab' => 'nope'];
    smcp_tabs_check(Simple_MCP_Admin::current_tab() === 'connection', 'unknown tab → «Підключення»');
    $_GET = [];

    // ── Saving: a known starting point ───────────────────────────────────────
    $smcp_o     = Simple_MCP::options();
    $smcp_roles = [];
    foreach (array_keys(wp_roles()->roles) as $smcp_slug) $smcp_roles[$smcp_slug] = Simple_MCP::role_defaults($smcp_slug);
    $smcp_base = array_merge(is_array($smcp_orig) ? $smcp_orig : [], [
        'enabled' => true, 'rate_limit' => 120, 'oauth_enabled' => true, 'oauth_wp_cli' => true, 'oauth_any_redirect' => false, 'roles' => $smcp_roles,
    ]);
    update_option(Simple_MCP::OPTION, $smcp_base);
    $smcp_settings_post = ['enabled' => '1', 'path' => (string) $smcp_o['path'], 'deny_list' => implode("\n", (array) $smcp_o['deny_list']),
        'ip_allowlist' => implode("\n", (array) $smcp_o['ip_allowlist']), 'log_retention_days' => (string) Simple_MCP_Audit::retention_days(),
        'php_bin' => (string) $smcp_o['php_bin'], 'wp_bin' => (string) $smcp_o['wp_bin']];

    // «Налаштування» — no OAuth or role fields in its POST, and they stay
    $smcp_loc = smcp_tabs_save(['tab' => 'settings', 'rate_limit' => '77'] + $smcp_settings_post);
    $smcp_now = Simple_MCP::options();
    smcp_tabs_check((int) $smcp_now['rate_limit'] === 77, '«Налаштування» saves its fields');
    smcp_tabs_check(!empty($smcp_now['oauth_enabled']) && !empty($smcp_now['oauth_wp_cli']), '… and leaves the OAuth switches on', $smcp_now);
    smcp_tabs_check($smcp_now['roles'] === $smcp_roles, '… and the role matrix as it was');
    smcp_tabs_check(strpos($smcp_loc, 'tab=settings') !== false && strpos($smcp_loc, 'saved=1') !== false, '… and returns to «Налаштування»', $smcp_loc);

    // «OAuth» — only its switches (an unchecked one is off), nothing else
    $smcp_loc = smcp_tabs_save(['tab' => 'oauth', 'oauth_enabled' => '1']);
    $smcp_now = Simple_MCP::options();
    smcp_tabs_check(!empty($smcp_now['oauth_enabled']) && empty($smcp_now['oauth_wp_cli']) && empty($smcp_now['oauth_any_redirect']), '«OAuth» saves its switches (the unchecked one is off)', $smcp_now);
    smcp_tabs_check((int) $smcp_now['rate_limit'] === 77 && !empty($smcp_now['enabled']) && $smcp_now['roles'] === $smcp_roles, '… and nothing of the other tabs');
    smcp_tabs_check(strpos($smcp_loc, 'tab=oauth') !== false, '… and returns to «OAuth»', $smcp_loc);

    // «Права» — only the matrix
    $smcp_loc = smcp_tabs_save(['tab' => 'roles', 'roles' => ['administrator' => ['mcp' => '1', 'blocks' => '1']]]);
    $smcp_now = Simple_MCP::options();
    smcp_tabs_check(!empty($smcp_now['roles']['administrator']['mcp']) && !empty($smcp_now['roles']['administrator']['blocks'])
        && empty($smcp_now['roles']['administrator']['content']), '«Права» saves the matrix', $smcp_now['roles']['administrator'] ?? null);
    smcp_tabs_check((int) $smcp_now['rate_limit'] === 77 && !empty($smcp_now['oauth_enabled']) && empty($smcp_now['oauth_wp_cli']), '… and nothing of the other tabs');
    smcp_tabs_check(strpos($smcp_loc, 'tab=roles') !== false, '… and returns to «Права»', $smcp_loc);

    // A POST without a tab (the single-page form from before 2.7.4) saves everything, as it did
    $smcp_loc = smcp_tabs_save(['rate_limit' => '55', 'roles' => ['administrator' => ['mcp' => '1']]] + $smcp_settings_post);
    $smcp_now = Simple_MCP::options();
    smcp_tabs_check((int) $smcp_now['rate_limit'] === 55 && empty($smcp_now['oauth_enabled']) && empty($smcp_now['roles']['administrator']['blocks']),
        'a POST without a tab saves every field, as the old single form did', $smcp_now);
    smcp_tabs_check(strpos($smcp_loc, 'saved=1') !== false, '… and redirects with «Збережено»', $smcp_loc);
} finally {
    if ($smcp_orig === null) delete_option(Simple_MCP::OPTION);
    else update_option(Simple_MCP::OPTION, $smcp_orig);
    restore_error_handler();
    $_GET = [];
}
smcp_tabs_check(get_option(Simple_MCP::OPTION, null) === $smcp_orig, 'the plugin option is restored');
smcp_tabs_check(!$smcp_tabs_notices, 'no PHP notices from the plugin', $smcp_tabs_notices);

echo "\nadmin tabs: " . ($GLOBALS['smcp_tabs_checks'] - $GLOBALS['smcp_tabs_fail']) . ' of ' . $GLOBALS['smcp_tabs_checks'] . " checks passed\n";
if ($GLOBALS['smcp_tabs_fail']) exit(1);
