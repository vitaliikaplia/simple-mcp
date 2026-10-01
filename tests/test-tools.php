<?php
/**
 * ACF selector parsing (Simple_MCP_Tools::acf_target) — decides which capability check applies.
 */

smcp_test('tools: acf_target posts (numeric ids)', function () {
    smcp_require_method('Simple_MCP_Tools', 'acf_target');
    smcp_assert_same(['type' => 'post', 'id' => 13], Simple_MCP_Tools::acf_target(13));
    smcp_assert_same(['type' => 'post', 'id' => 13], Simple_MCP_Tools::acf_target('13'));
});

smcp_test('tools: acf_target options pages', function () {
    smcp_require_method('Simple_MCP_Tools', 'acf_target');
    foreach (['option', 'options', 'options_en', 'options_uk'] as $selector) {
        smcp_assert_same('option', Simple_MCP_Tools::acf_target($selector)['type'], $selector);
    }
});

smcp_test('tools: acf_target users, terms, comments', function () {
    smcp_require_method('Simple_MCP_Tools', 'acf_target');
    smcp_assert_same(['type' => 'user', 'id' => 5], Simple_MCP_Tools::acf_target('user_5'));
    smcp_assert_same(['type' => 'term', 'id' => 7], Simple_MCP_Tools::acf_target('term_7'));
    smcp_assert_same(['type' => 'comment', 'id' => 9], Simple_MCP_Tools::acf_target('comment_9'));
});

smcp_test('tools: acf_target unknown selectors are "other"', function () {
    smcp_require_method('Simple_MCP_Tools', 'acf_target');
    foreach (['foo', 'user_', 'user_x', 'term_5_extra', 'xoption', 'block_abc', ''] as $selector) {
        smcp_assert_same('other', Simple_MCP_Tools::acf_target($selector)['type'], var_export($selector, true));
    }
});

/** acf_update container shapes (Simple_MCP_Tools::acf_shape_errors): what ACF would silently drop. */
function smcp_acf_fields(): array {
    return [
        'rep'  => ['key' => 'field_rep', 'name' => 'rep', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_type', 'name' => 'type', 'type' => 'select'],
            ['key' => 'field_link', 'name' => 'link', 'type' => 'url'],
        ]],
        'flex' => ['key' => 'field_flex', 'name' => 'flex', 'type' => 'flexible_content', 'layouts' => [
            ['name' => 'text', 'sub_fields' => [['key' => 'field_body', 'name' => 'body', 'type' => 'wysiwyg']]],
        ]],
        'grp'  => ['key' => 'field_grp', 'name' => 'grp', 'type' => 'group', 'sub_fields' => [
            ['key' => 'field_title', 'name' => 'title', 'type' => 'text'],
            ['key' => 'field_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'field_x', 'name' => 'x', 'type' => 'text']]],
            // seamless-clone sub-field: in-memory key <clone>_<field>, the real one in __key
            ['key' => 'field_cl_field_sub', '__key' => 'field_sub', 'name' => 'sub', '_name' => 'sub', 'type' => 'text'],
        ]],
    ];
}

smcp_test('tools: acf_shape_errors accepts what ACF stores (names, field keys, clears)', function () {
    smcp_require_method('Simple_MCP_Tools', 'acf_shape_errors');
    $f = smcp_acf_fields();
    smcp_assert_same([], Simple_MCP_Tools::acf_shape_errors($f['rep'], [['type' => 'ig', 'link' => 'https://x']], 'rep'));
    smcp_assert_same([], Simple_MCP_Tools::acf_shape_errors($f['rep'], [['field_type' => 'ig'], []], 'rep'), 'raw field keys / empty row');
    foreach ([[], '', null, false] as $clear) {
        smcp_assert_same([], Simple_MCP_Tools::acf_shape_errors($f['rep'], $clear, 'rep'), 'clear ' . smcp_export($clear));
    }
    smcp_assert_same([], Simple_MCP_Tools::acf_shape_errors($f['flex'], [['acf_fc_layout' => 'text', 'body' => 'x', 'acf_fc_layout_disabled' => true]], 'flex'));
    smcp_assert_same([], Simple_MCP_Tools::acf_shape_errors($f['grp'], ['title' => 't', 'rows' => [['x' => '1']], 'field_sub' => 's', 'sub' => 's2'], 'grp'));
    smcp_assert_same([], Simple_MCP_Tools::acf_shape_errors(['type' => 'text', 'name' => 't'], ['anything'], 't'), 'leaf fields are not checked');
});

smcp_test('tools: acf_shape_errors reports what ACF would silently drop, with paths', function () {
    smcp_require_method('Simple_MCP_Tools', 'acf_shape_errors');
    $f = smcp_acf_fields();
    $e = Simple_MCP_Tools::acf_shape_errors($f['rep'], [['messenger' => 'tg', 'url' => 'https://t.me/x']], 'rep');
    smcp_assert_same(2, count($e), smcp_export($e));
    smcp_assert_true(strpos($e[0], 'rep[0].messenger:') === 0, $e[0]);
    $e = Simple_MCP_Tools::acf_shape_errors($f['rep'], ['type' => 'ig', 'link' => 'https://x'], 'rep');
    smcp_assert_same(2, count($e), 'one row object instead of a list');
    smcp_assert_same(1, count(Simple_MCP_Tools::acf_shape_errors($f['rep'], 'ig', 'rep')), 'scalar for a repeater');
    smcp_assert_same(1, count(Simple_MCP_Tools::acf_shape_errors($f['flex'], [['body' => 'x']], 'flex')), 'missing acf_fc_layout');
    smcp_assert_same(1, count(Simple_MCP_Tools::acf_shape_errors($f['flex'], [['acf_fc_layout' => 'nope']], 'flex')), 'unknown layout');
    smcp_assert_same(1, count(Simple_MCP_Tools::acf_shape_errors($f['flex'], [['acf_fc_layout' => 'text', 'title' => 'x']], 'flex')), 'sub-field of another layout');
    $e = Simple_MCP_Tools::acf_shape_errors($f['grp'], ['rows' => [['y' => 1]]], 'grp');
    smcp_assert_true(count($e) === 1 && strpos($e[0], 'grp.rows[0].y:') === 0, smcp_export($e));
    smcp_assert_same(1, count(Simple_MCP_Tools::acf_shape_errors($f['grp'], 'x', 'grp')), 'scalar for a group');
});
