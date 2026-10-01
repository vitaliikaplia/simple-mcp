<?php
/**
 * Block field merging (Simple_MCP_Tools_Blocks::apply_values): row indexes in one `set` refer to the
 * block before the call, row removals are applied last, conflicting keys are refused.
 */

/** Field definitions by name: a repeater of text rows, a repeater with a nested repeater, a text field. */
function smcp_blocks_defs(): array {
    $text = function ($name) { return ['key' => 'field_' . $name, 'name' => $name, 'type' => 'text']; };
    return [
        'rows'  => ['key' => 'field_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [$text('label')]],
        'tiles' => ['key' => 'field_tiles', 'name' => 'tiles', 'type' => 'repeater', 'sub_fields' => [
            $text('title'),
            ['key' => 'field_items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => [$text('item')]],
        ]],
        'title' => $text('title'),
    ];
}

function smcp_blocks_tree(): array {
    return [
        'rows'  => array_map(function ($i) { return ['label' => 'r' . $i]; }, range(0, 5)),
        'tiles' => [
            ['title' => 't0', 'items' => [['item' => 'a'], ['item' => 'b'], ['item' => 'c']]],
            ['title' => 't1', 'items' => [['item' => 'd']]],
            ['title' => 't2', 'items' => []],
        ],
        'title' => 'T',
    ];
}

/** Runs apply_values on a fresh tree; returns [tree, errors]. */
function smcp_blocks_apply(array $set): array {
    $tree    = smcp_blocks_tree();
    $errors  = [];
    $touched = [];
    Simple_MCP_Tools_Blocks::apply_values(smcp_blocks_defs(), $tree, $set, false, $errors, $touched);
    return [$tree, $errors];
}

smcp_test('blocks: several row removals in one set use the indexes before the call', function () {
    smcp_require_method('Simple_MCP_Tools_Blocks', 'apply_values');
    [$tree, $errors] = smcp_blocks_apply(['rows_2' => null, 'rows_3' => null]);
    smcp_assert_same([], $errors);
    smcp_assert_same(['r0', 'r1', 'r4', 'r5'], array_column($tree['rows'], 'label'));

    [$tree, $errors] = smcp_blocks_apply(['rows_3' => null, 'rows_0' => null, 'rows_5' => null]);
    smcp_assert_same([], $errors);
    smcp_assert_same(['r1', 'r2', 'r4'], array_column($tree['rows'], 'label'), 'order of the keys does not matter');
});

smcp_test('blocks: an edit next to a removal lands on the row it named', function () {
    smcp_require_method('Simple_MCP_Tools_Blocks', 'apply_values');
    [$tree, $errors] = smcp_blocks_apply(['rows_0' => null, 'rows_1_label' => 'EDITED']);
    smcp_assert_same([], $errors);
    smcp_assert_same(['EDITED', 'r2', 'r3', 'r4', 'r5'], array_column($tree['rows'], 'label'));

    [$tree, $errors] = smcp_blocks_apply(['rows_4' => ['label' => 'merged'], 'rows_1' => null]);
    smcp_assert_same([], $errors);
    smcp_assert_same(['r0', 'r2', 'r3', 'merged', 'r5'], array_column($tree['rows'], 'label'));
});

smcp_test('blocks: nested removals — deeper lists first, then the outer list', function () {
    smcp_require_method('Simple_MCP_Tools_Blocks', 'apply_values');
    [$tree, $errors] = smcp_blocks_apply(['tiles_0_items_0' => null, 'tiles_0_items_2' => null, 'tiles_1' => null]);
    smcp_assert_same([], $errors);
    smcp_assert_same(['t0', 't2'], array_column($tree['tiles'], 'title'));
    smcp_assert_same(['b'], array_column($tree['tiles'][0]['items'], 'item'));
});

smcp_test('blocks: keys inside a removed row or a replaced list are refused as conflicts', function () {
    smcp_require_method('Simple_MCP_Tools_Blocks', 'apply_values');
    [, $errors] = smcp_blocks_apply(['rows_2' => null, 'rows_2_label' => 'x']);
    smcp_assert_true(count($errors) === 1 && strpos($errors[0], 'rows_2_label: conflicts with rows_2') === 0, smcp_export($errors));

    [, $errors] = smcp_blocks_apply(['rows' => [['label' => 'n0'], ['label' => 'n1'], ['label' => 'n2']], 'rows_1_label' => 'x']);
    smcp_assert_true(count($errors) === 1 && strpos($errors[0], 'rows_1_label: conflicts with rows') === 0, smcp_export($errors));
    [, $errors] = smcp_blocks_apply(['rows_1_label' => 'x', 'rows' => [['label' => 'n0'], ['label' => 'n1']]]);
    smcp_assert_same(1, count($errors), 'the order of the two keys does not matter');

    [, $errors] = smcp_blocks_apply(['tiles_0' => null, 'tiles_0_items_1' => null]);
    smcp_assert_same(1, count($errors), 'a removal inside a removed row');

    [, $errors] = smcp_blocks_apply(['rows' => null, 'rows_0' => null]);
    smcp_assert_same(1, count($errors), 'a row of a field that is cleared');
});

smcp_test('blocks: unrelated keys in one set stay independent', function () {
    smcp_require_method('Simple_MCP_Tools_Blocks', 'apply_values');
    [$tree, $errors] = smcp_blocks_apply(['title' => 'New', 'rows_5' => null, 'tiles_2_title' => 'x', 'tiles_0_items_1_item' => 'B']);
    smcp_assert_same([], $errors);
    smcp_assert_same('New', $tree['title']);
    smcp_assert_same(['r0', 'r1', 'r2', 'r3', 'r4'], array_column($tree['rows'], 'label'));
    smcp_assert_same('x', $tree['tiles'][2]['title']);
    smcp_assert_same(['a', 'B', 'c'], array_column($tree['tiles'][0]['items'], 'item'));
});
