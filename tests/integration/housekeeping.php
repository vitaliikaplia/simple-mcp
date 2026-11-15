<?php
/**
 * Integration test (needs WordPress):
 *
 *   wp eval-file wp-content/plugins/simple-mcp/tests/integration/housekeeping.php
 *
 * Daily housekeeping without WP-Cron (2.7.5). Many sites run with DISABLE_WP_CRON and no system cron, so the
 * event simple_mcp_prune never fires there; the plugin also cleans up after an agent's request and on its
 * settings page. Every path goes through one claim on the option simple_mcp_pruned_at: the first run inserts
 * it, later runs need a stamp older than Simple_MCP::PRUNE_EVERY (a stamp from the future does not block it).
 * A run removes audit rows past the retention and expired OAuth codes and tokens, and keeps live ones.
 *
 * Everything happens inside a transaction that is rolled back: the site keeps its log, its stamp and its
 * OAuth rows. Skips when a table is not InnoDB (the rollback would not undo the deletes). Exit code 1 on any failure.
 */
if (!defined('ABSPATH')) exit;
require_once ABSPATH . 'wp-admin/includes/template.php';

// wp eval-file runs this file inside a function, so the counters live in $GLOBALS explicitly
$GLOBALS['smcp_hk_fail']   = 0;
$GLOBALS['smcp_hk_checks'] = 0;
function smcp_hk_check($ok, $what, $context = null) {
    $GLOBALS['smcp_hk_checks']++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        $GLOBALS['smcp_hk_fail']++;
        if ($context !== null) echo '     ' . substr(is_string($context) ? $context : wp_json_encode($context), 0, 600) . "\n";
    }
}

global $wpdb;
if (!Simple_MCP_OAuth::ensure_tables()) {
    echo "FAIL could not create the OAuth tables\n";
    exit(1);
}
$smcp_hk_tables = [$wpdb->options, Simple_MCP_Audit::table(), Simple_MCP_OAuth::table('codes'), Simple_MCP_OAuth::table('tokens')];
foreach ($smcp_hk_tables as $smcp_hk_t) {
    $smcp_hk_engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $smcp_hk_t));
    if (strcasecmp((string) $smcp_hk_engine, 'InnoDB') !== 0) {
        echo "SKIP: $smcp_hk_t is not InnoDB ($smcp_hk_engine), the test could not roll its deletes back\n";
        return;
    }
}
$smcp_hk_admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);

/** Stamp: null — no row; otherwise the raw value is written */
function smcp_hk_stamp($v) {
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => Simple_MCP::PRUNED_OPTION]);
    if ($v !== null) $wpdb->insert($wpdb->options, ['option_name' => Simple_MCP::PRUNED_OPTION, 'option_value' => (string) $v, 'autoload' => 'no']);
}

/** Expired and live OAuth rows, an old and a fresh audit row; returns their markers */
function smcp_hk_seed() {
    global $wpdb;
    $now = time();
    $m   = ['run' => wp_generate_password(12, false)];
    foreach (['dead' => $now - 60, 'live' => $now + 600] as $k => $exp) {
        $m["code_$k"]  = hash('sha256', "smcp-hk-code-$k-" . $m['run']);
        $m["token_$k"] = hash('sha256', "smcp-hk-token-$k-" . $m['run']);
        $wpdb->insert(Simple_MCP_OAuth::table('codes'), ['code_hash' => $m["code_$k"], 'expires_at' => $exp]);
        $wpdb->insert(Simple_MCP_OAuth::table('tokens'), ['token_hash' => $m["token_$k"], 'kind' => 'access', 'expires_at' => $exp]);
    }
    foreach (['old' => $now - 40 * DAY_IN_SECONDS, 'new' => $now - HOUR_IN_SECONDS] as $k => $t) {
        $wpdb->insert(Simple_MCP_Audit::table(), [
            'created_at' => wp_date('Y-m-d H:i:s', $t), 'tool' => 'smcp_hk_' . $k, 'args' => $m['run'], 'status' => 'ok',
        ]);
        $m["audit_$k"] = (int) $wpdb->insert_id;
    }
    return $m;
}

/** Which of the seeded rows are still there */
function smcp_hk_left(array $m) {
    global $wpdb;
    $left = [];
    foreach (['dead', 'live'] as $k) {
        if ($wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . Simple_MCP_OAuth::table('codes') . ' WHERE code_hash = %s', $m["code_$k"]))) $left[] = "code_$k";
        if ($wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . Simple_MCP_OAuth::table('tokens') . ' WHERE token_hash = %s', $m["token_$k"]))) $left[] = "token_$k";
    }
    foreach (['old', 'new'] as $k) {
        if ($wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . Simple_MCP_Audit::table() . ' WHERE id = %d', $m["audit_$k"]))) $left[] = "audit_$k";
    }
    sort($left);
    return $left;
}

// A fixed retention of 30 days, whatever the site has set
$smcp_hk_retention = function ($o) { $o = is_array($o) ? $o : []; $o['log_retention_days'] = 30; return $o; };
add_filter('option_' . Simple_MCP::OPTION, $smcp_hk_retention);
$smcp_hk_orig = Simple_MCP::last_housekeeping(true);

$wpdb->query('START TRANSACTION');
try {
    $smcp_hk_now = time();
    $smcp_hk_all = ['audit_new', 'audit_old', 'code_dead', 'code_live', 'token_dead', 'token_live'];
    $smcp_hk_kept = ['audit_new', 'code_live', 'token_live'];

    // ── The first run ever: no stamp yet ────────────────────────────────────
    smcp_hk_stamp(null);
    smcp_hk_check(Simple_MCP::last_housekeeping() === 0, 'no stamp: «never» (0)');
    $smcp_hk_m = smcp_hk_seed();
    smcp_hk_check(smcp_hk_left($smcp_hk_m) === $smcp_hk_all, 'the seeded rows are in place', smcp_hk_left($smcp_hk_m));
    smcp_hk_check(Simple_MCP::housekeeping() === true, 'the first run goes ahead without a stamp');
    smcp_hk_check(abs(Simple_MCP::last_housekeeping() - $smcp_hk_now) <= 5, 'the run stamps the time', Simple_MCP::last_housekeeping());
    smcp_hk_check(smcp_hk_left($smcp_hk_m) === $smcp_hk_kept, 'expired OAuth codes and tokens and audit rows past 30 days are gone, the rest kept', smcp_hk_left($smcp_hk_m));
    smcp_hk_check((string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Simple_MCP::PRUNED_OPTION)) === 'no', 'the stamp is not autoloaded');

    // ── At most once per PRUNE_EVERY ────────────────────────────────────────
    $smcp_hk_m = smcp_hk_seed();
    smcp_hk_check(Simple_MCP::housekeeping() === false, 'a second run right after does nothing');
    smcp_hk_check(smcp_hk_left($smcp_hk_m) === $smcp_hk_all, '… and deletes nothing', smcp_hk_left($smcp_hk_m));
    smcp_hk_stamp($smcp_hk_now - Simple_MCP::PRUNE_EVERY + 600);
    smcp_hk_check(Simple_MCP::housekeeping() === false, 'a stamp younger than PRUNE_EVERY (23 h) blocks the run');
    smcp_hk_stamp($smcp_hk_now - Simple_MCP::PRUNE_EVERY - 5);
    smcp_hk_check(Simple_MCP::housekeeping() === true, 'a stamp older than PRUNE_EVERY lets it run');
    smcp_hk_check(smcp_hk_left($smcp_hk_m) === $smcp_hk_kept, '… and it cleans up', smcp_hk_left($smcp_hk_m));
    smcp_hk_stamp($smcp_hk_now + DAY_IN_SECONDS);
    smcp_hk_check(Simple_MCP::claim_housekeeping() === true, 'a stamp from the future (clock skew) does not block it forever');
    smcp_hk_check(Simple_MCP::claim_housekeeping() === false, 'only one claim per run: the next one finds a fresh stamp');
    smcp_hk_stamp('garbage');
    smcp_hk_check(Simple_MCP::claim_housekeeping() === true, 'a broken stamp counts as long ago');

    // ── The WP-Cron event goes through the same claim ───────────────────────
    smcp_hk_check(has_action('simple_mcp_prune', ['Simple_MCP', 'housekeeping']) === 10, 'simple_mcp_prune runs Simple_MCP::housekeeping');
    smcp_hk_stamp($smcp_hk_now - 3 * DAY_IN_SECONDS);
    $smcp_hk_m = smcp_hk_seed();
    do_action('simple_mcp_prune');
    smcp_hk_check(abs(Simple_MCP::last_housekeeping() - time()) <= 5 && smcp_hk_left($smcp_hk_m) === $smcp_hk_kept, 'the cron event cleans up when it is due', smcp_hk_left($smcp_hk_m));
    $smcp_hk_m = smcp_hk_seed();
    do_action('simple_mcp_prune');
    smcp_hk_check(smcp_hk_left($smcp_hk_m) === $smcp_hk_all, 'the cron event does nothing when another path cleaned up today', smcp_hk_left($smcp_hk_m));

    // ── The settings page ───────────────────────────────────────────────────
    if ($smcp_hk_admins) {
        wp_set_current_user((int) $smcp_hk_admins[0]);
        smcp_hk_stamp($smcp_hk_now - 3 * DAY_IN_SECONDS);
        $_GET = ['page' => 'simple-mcp', 'tab' => 'settings'];
        ob_start();
        Simple_MCP_Admin::render();
        $smcp_hk_html = (string) ob_get_clean();
        $_GET = [];
        smcp_hk_check(smcp_hk_left($smcp_hk_m) === $smcp_hk_kept, 'opening the settings page cleans up when it is due', smcp_hk_left($smcp_hk_m));
        smcp_hk_check(strpos($smcp_hk_html, 'Востаннє: ' . wp_date('d.m.Y H:i', Simple_MCP::last_housekeeping())) !== false, 'the «Налаштування» tab shows when it last ran');
        smcp_hk_stamp(null);
        $_GET = ['page' => 'simple-mcp', 'tab' => 'settings'];
        ob_start();
        Simple_MCP_Admin::render();
        ob_end_clean();
        $_GET = [];
        smcp_hk_check(Simple_MCP::last_housekeeping() > 0, 'the settings page also makes the very first run');
    } else {
        echo "skip the settings page: no administrator\n";
    }
} finally {
    $wpdb->query('ROLLBACK');
    remove_filter('option_' . Simple_MCP::OPTION, $smcp_hk_retention);
}
smcp_hk_check(Simple_MCP::last_housekeeping(true) === $smcp_hk_orig, 'the rollback put the site\'s stamp back', [Simple_MCP::last_housekeeping(true), $smcp_hk_orig]);

echo "\n" . ($GLOBALS['smcp_hk_checks'] - $GLOBALS['smcp_hk_fail']) . ' of ' . $GLOBALS['smcp_hk_checks'] . " housekeeping checks passed\n";
if ($GLOBALS['smcp_hk_fail']) exit(1);
