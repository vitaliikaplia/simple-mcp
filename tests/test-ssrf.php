<?php
/**
 * SSRF pre-gate for media uploads from a URL (Simple_MCP::ip_is_public behind url_is_safe): the same answer
 * on every supported PHP — before 8.3 filter_var() let IPv4-mapped/compatible IPv6 through.
 */

smcp_test('ssrf: private and reserved IPv4 are blocked', function () {
    smcp_require_method('Simple_MCP', 'ip_is_public');
    foreach (['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254', '0.0.0.0', '240.0.0.1',
        '255.255.255.255', '100.64.0.1', '100.100.100.200', '100.127.255.255'] as $ip) {
        smcp_assert_false(Simple_MCP::ip_is_public($ip), $ip);
    }
    foreach (['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.1', '93.184.216.34'] as $ip) {
        smcp_assert_true(Simple_MCP::ip_is_public($ip), $ip);
    }
});

smcp_test('ssrf: IPv6 with an embedded IPv4 is blocked on every PHP version', function () {
    smcp_require_method('Simple_MCP', 'ip_is_public');
    foreach (['::', '::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:10.0.0.1', '::ffff:8.8.8.8',
        '::127.0.0.1', '::ffff:0:10.0.0.1', '64:ff9b::7f00:1', '64:ff9b::a9fe:a9fe', '64:ff9b:1::808:808',
        '2002:7f00:1::1', '2002:a00:1::1', 'fc00::1', 'fd12:3456::1', 'fe80::1'] as $ip) {
        smcp_assert_false(Simple_MCP::ip_is_public($ip), $ip);
    }
    foreach (['2606:4700::1111', '2a00:1450:4001::200e', '64:ff9b::808:808', '2002:808:808::1'] as $ip) {
        smcp_assert_true(Simple_MCP::ip_is_public($ip), $ip);
    }
});

smcp_test('ssrf: garbage is not public', function () {
    smcp_require_method('Simple_MCP', 'ip_is_public');
    foreach (['', 'localhost', '999.1.1.1', 'fe80::1%eth0', '1.2.3'] as $ip) {
        smcp_assert_false(Simple_MCP::ip_is_public($ip), var_export($ip, true));
    }
});
