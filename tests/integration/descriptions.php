<?php
/**
 * Integration test (needs WordPress; not part of tests/run.php or CI):
 *
 *   wp eval-file wp-content/plugins/simple-mcp/tests/integration/descriptions.php
 *
 * Claude Code cuts every MCP tool description and the server instructions at 2048 characters
 * ("… [truncated]"), so the warnings at their end would be lost. Checks the worst case — an
 * administrator with every tool group including Server ops, and the multilingual sync note when
 * the site has one — for every tool in tools/list and for Simple_MCP_Endpoint::instructions().
 * Read-only. Exit code 1 on any failure.
 */
if (!defined('ABSPATH')) exit;

$smcp_desc_max  = 2048;
$smcp_desc_fail = 0;

$smcp_admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if (!$smcp_admins) {
    echo "SKIP: no administrator\n";
    return;
}
Simple_MCP_Auth::impersonate_for_cli((int) $smcp_admins[0]);
$smcp_perms = new ReflectionProperty('Simple_MCP_Auth', 'perms');
if (PHP_VERSION_ID < 80100) $smcp_perms->setAccessible(true); // no-op since 8.1, deprecated in 8.5
$smcp_perms->setValue(null, array_fill_keys(Simple_MCP::PERMS, true));

$smcp_lengths = [];
foreach (Simple_MCP_Tools::list_public() as $smcp_tool) {
    $smcp_lengths[$smcp_tool['name']] = mb_strlen((string) $smcp_tool['description']);
}
$smcp_lengths['(instructions)'] = mb_strlen(Simple_MCP_Endpoint::instructions());
arsort($smcp_lengths);
foreach ($smcp_lengths as $smcp_name => $smcp_len) {
    $smcp_ok = $smcp_len <= $smcp_desc_max;
    if (!$smcp_ok) $smcp_desc_fail++;
    printf("  %s %-28s %d\n", $smcp_ok ? 'ok  ' : 'FAIL', $smcp_name, $smcp_len);
}
if (count($smcp_lengths) < 10) {
    $smcp_desc_fail++;
    echo "  FAIL expected the full tool list, got " . (count($smcp_lengths) - 1) . " tools\n";
}

echo $smcp_desc_fail ? "\nFAILED: $smcp_desc_fail\n" : "\nALL OK\n";
if ($smcp_desc_fail) {
    class_exists('WP_CLI') ? WP_CLI::halt(1) : exit(1);
}
