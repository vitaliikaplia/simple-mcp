<?php
/**
 * IP allow-list matching (Simple_MCP_Auth::cidr_match / ip_in_list).
 */

/** True when this build matches IPv6 CIDRs (landed together with inet_pton normalisation). */
function smcp_auth_supports_ipv6(): bool {
    return Simple_MCP_Auth::cidr_match('2001:db8::1', '2001:db8::/32') === true;
}

smcp_test('auth: cidr_match IPv4', function () {
    smcp_require_method('Simple_MCP_Auth', 'cidr_match');
    smcp_assert_true(Simple_MCP_Auth::cidr_match('10.1.2.3', '10.0.0.0/8'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('11.1.2.3', '10.0.0.0/8'));
    smcp_assert_true(Simple_MCP_Auth::cidr_match('192.168.1.77', '192.168.1.0/24'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('192.168.2.1', '192.168.1.0/24'));
    smcp_assert_true(Simple_MCP_Auth::cidr_match('203.0.113.9', '203.0.113.9/32'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('203.0.113.10', '203.0.113.9/32'));
    smcp_assert_true(Simple_MCP_Auth::cidr_match('8.8.8.8', '0.0.0.0/0'), '/0 matches everything');
    smcp_assert_true(Simple_MCP_Auth::cidr_match('172.16.5.4', '172.16.0.0/12'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('172.32.0.1', '172.16.0.0/12'));
});

smcp_test('auth: cidr_match rejects malformed input', function () {
    smcp_require_method('Simple_MCP_Auth', 'cidr_match');
    smcp_assert_false(Simple_MCP_Auth::cidr_match('10.0.0.1', '10.0.0.0/33'), 'prefix > 32');
    smcp_assert_false(Simple_MCP_Auth::cidr_match('10.0.0.1', '10.0.0.0/-1'), 'negative prefix');
    smcp_assert_false(Simple_MCP_Auth::cidr_match('not-an-ip', '10.0.0.0/8'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('10.0.0.1', 'garbage/8'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('', '10.0.0.0/8'));
});

smcp_test('auth: ip_in_list exact entries, CIDR entries, blanks', function () {
    smcp_require_method('Simple_MCP_Auth', 'ip_in_list');
    $list = ['', '  203.0.113.5  ', '10.0.0.0/8'];
    smcp_assert_true(Simple_MCP_Auth::ip_in_list('203.0.113.5', $list), 'trimmed exact entry');
    smcp_assert_true(Simple_MCP_Auth::ip_in_list('10.200.0.1', $list), 'CIDR entry');
    smcp_assert_false(Simple_MCP_Auth::ip_in_list('203.0.113.6', $list));
    smcp_assert_false(Simple_MCP_Auth::ip_in_list('203.0.113.5', []), 'empty list');
    smcp_assert_false(Simple_MCP_Auth::ip_in_list('', $list), 'empty IP never matches');
});

smcp_test('auth: cidr_match IPv6', function () {
    smcp_require_method('Simple_MCP_Auth', 'cidr_match');
    if (!smcp_auth_supports_ipv6()) {
        smcp_skip('IPv6 CIDR matching is not implemented in this build');
    }
    smcp_assert_true(Simple_MCP_Auth::cidr_match('2001:db8:abcd::1', '2001:db8::/32'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('2001:db9::1', '2001:db8::/32'));
    smcp_assert_true(Simple_MCP_Auth::cidr_match('2001:db8::1', '2001:db8::1/128'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('2001:db8::2', '2001:db8::1/128'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('2001:db8::7', '2001:db8::/127'), '::7 is outside ::0/127');
    smcp_assert_true(Simple_MCP_Auth::cidr_match('fe80::1', '::/0'), '/0 matches every IPv6 address');
    smcp_assert_false(Simple_MCP_Auth::cidr_match('2001:db8::1', '2001:db8::/129'), 'prefix > 128');
});

smcp_test('auth: IPv4 and IPv6 never cross-match', function () {
    smcp_require_method('Simple_MCP_Auth', 'cidr_match');
    if (!smcp_auth_supports_ipv6()) {
        smcp_skip('IPv6 CIDR matching is not implemented in this build');
    }
    smcp_assert_false(Simple_MCP_Auth::cidr_match('10.0.0.1', '::/0'));
    smcp_assert_false(Simple_MCP_Auth::cidr_match('2001:db8::1', '0.0.0.0/0'));
});

smcp_test('auth: ip_in_list compares IPv6 in canonical form', function () {
    smcp_require_method('Simple_MCP_Auth', 'ip_in_list');
    if (!smcp_auth_supports_ipv6()) {
        smcp_skip('IPv6 normalisation is not implemented in this build');
    }
    smcp_assert_true(Simple_MCP_Auth::ip_in_list('2001:db8::1', ['2001:0DB8:0000:0000:0000:0000:0000:0001']), 'expanded upper-case entry');
    smcp_assert_true(Simple_MCP_Auth::ip_in_list('2001:0db8::0001', ['2001:db8::1']), 'non-canonical client IP');
    smcp_assert_true(Simple_MCP_Auth::ip_in_list('::1', ['::1/128']));
    smcp_assert_false(Simple_MCP_Auth::ip_in_list('2001:db8::2', ['2001:db8::1']));
});

smcp_test('auth: ip_bucket — IPv4 as is, IPv6 by /64', function () {
    smcp_require_method('Simple_MCP_Auth', 'ip_bucket');
    smcp_assert_same('203.0.113.5', Simple_MCP_Auth::ip_bucket('203.0.113.5'));
    smcp_assert_same('203.0.113.5', Simple_MCP_Auth::ip_bucket('::ffff:203.0.113.5'), 'IPv4-mapped');
    smcp_assert_same('2001:db8:1:2::/64', Simple_MCP_Auth::ip_bucket('2001:db8:1:2:3:4:5:6'));
    smcp_assert_same('2001:db8:1:2::/64', Simple_MCP_Auth::ip_bucket('2001:db8:1:2:ffff::1'), 'same /64');
    smcp_assert_true(Simple_MCP_Auth::ip_bucket('2001:db8:1:3::1') !== Simple_MCP_Auth::ip_bucket('2001:db8:1:2::1'), 'another /64');
    smcp_assert_same('', Simple_MCP_Auth::ip_bucket(''));
});
