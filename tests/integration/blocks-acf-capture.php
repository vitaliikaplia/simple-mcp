<?php
/**
 * Integration test (needs WordPress + ACF PRO; not part of tests/run.php or CI):
 *
 *   wp eval-file wp-content/plugins/simple-mcp/tests/integration/blocks-acf-capture.php
 *
 * Checks that the blocks module stores block data exactly the way ACF does: for an in-memory block
 * type covering repeater, group, display-"group" clones, seamless clones (top level and inside a
 * repeater) and flexible content, Simple_MCP_Tools_Blocks::merge_value()+flatten() must produce the
 * same keys/values as ACF's own capture of the equivalent block-editor request (acf_setup_meta), and
 * get_fields() must read both back identically. Read-only: local field groups and the block type live
 * in memory only, nothing is written to the database. Exit code 1 on any failure.
 */
if (!defined('ABSPATH')) exit;

$smcp_cap_fail = 0;
$smcp_cap_done = function ($failed) {
    echo $failed ? "\nFAILED: $failed\n" : "\nALL OK\n";
    if ($failed) {
        class_exists('WP_CLI') ? WP_CLI::halt(1) : exit(1);
    }
};

foreach (['acf_add_local_field_group', 'acf_register_block_type', 'acf_setup_meta', 'acf_reset_meta', 'get_fields'] as $fn) {
    if (!function_exists($fn)) {
        echo "SKIP: ACF PRO is not active ($fn() missing)\n";
        return;
    }
}
foreach (['block_field_defs', 'merge_value', 'flatten'] as $method) {
    if (!method_exists('Simple_MCP_Tools_Blocks', $method)) {
        echo "FAIL: Simple_MCP_Tools_Blocks::$method() is missing\n";
        $smcp_cap_done(1);
        return;
    }
}

// ── In-memory fixture ────────────────────────────────────────────────────────

$none = [[['param' => 'post_type', 'operator' => '==', 'value' => 'smcp_none']]];
acf_add_local_field_group(['key' => 'group_smcp_shared', 'title' => 'shared', 'location' => $none, 'fields' => [
    ['key' => 'field_smcp_sh_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text', 'default_value' => 'DefTitle'],
    ['key' => 'field_smcp_sh_sub', 'name' => 'sub', 'label' => 'Sub', 'type' => 'text'],
]]);
acf_add_local_field_group(['key' => 'group_smcp_shared2', 'title' => 'shared2', 'location' => $none, 'fields' => [
    ['key' => 'field_smcp_sh2_x', 'name' => 'x2', 'label' => 'X2', 'type' => 'text'],
]]);
acf_register_block_type(['name' => 'smcp-cap', 'title' => 'SMCP capture', 'render_callback' => '__return_empty_string',
    'data' => ['field_smcp_txt' => 'from-type-data']]);
acf_add_local_field_group(['key' => 'group_smcp_cap', 'title' => 'cap', 'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/smcp-cap']]], 'fields' => [
    ['key' => 'field_smcp_txt', 'name' => 'txt', 'label' => 'Txt', 'type' => 'text'],
    ['key' => 'field_smcp_num', 'name' => 'num', 'label' => 'Num', 'type' => 'number', 'default_value' => 5],
    ['key' => 'field_smcp_tf', 'name' => 'tf', 'label' => 'TF', 'type' => 'true_false', 'default_value' => 1],
    ['key' => 'field_smcp_sel', 'name' => 'sel', 'label' => 'Sel', 'type' => 'select', 'choices' => ['a' => 'A', 'b' => 'B'], 'default_value' => 'a'],
    ['key' => 'field_smcp_rep', 'name' => 'rep', 'label' => 'Rep', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_smcp_rep_t', 'name' => 't', 'label' => 'T', 'type' => 'text'],
        ['key' => 'field_smcp_rep_img', 'name' => 'img', 'label' => 'Img', 'type' => 'image'],
        ['key' => 'field_smcp_rep_clone', 'name' => 'rc', 'label' => 'RC', 'type' => 'clone', 'display' => 'seamless', 'prefix_name' => 0, 'clone' => ['group_smcp_shared']],
    ]],
    ['key' => 'field_smcp_grp', 'name' => 'grp', 'label' => 'Grp', 'type' => 'group', 'sub_fields' => [
        ['key' => 'field_smcp_grp_a', 'name' => 'a', 'label' => 'A', 'type' => 'text'],
        ['key' => 'field_smcp_grp_b', 'name' => 'b', 'label' => 'B', 'type' => 'text', 'default_value' => 'Bdef'],
        ['key' => 'field_smcp_grp_rep', 'name' => 'gr', 'label' => 'GR', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_smcp_grp_rep_x', 'name' => 'x', 'label' => 'X', 'type' => 'text'],
        ]],
    ]],
    ['key' => 'field_smcp_cg', 'name' => 'cg', 'label' => 'CG', 'type' => 'clone', 'display' => 'group', 'prefix_name' => 1, 'clone' => ['group_smcp_shared']],
    ['key' => 'field_smcp_cg0', 'name' => 'cg0', 'label' => 'CG0', 'type' => 'clone', 'display' => 'group', 'prefix_name' => 0, 'clone' => ['group_smcp_shared2']],
    ['key' => 'field_smcp_cs', 'name' => 'hdr', 'label' => 'HDR', 'type' => 'clone', 'display' => 'seamless', 'prefix_name' => 1, 'clone' => ['group_smcp_shared']],
    ['key' => 'field_smcp_cs0', 'name' => 'hdr0', 'label' => 'HDR0', 'type' => 'clone', 'display' => 'seamless', 'prefix_name' => 0, 'clone' => ['group_smcp_shared2']],
    ['key' => 'field_smcp_fc', 'name' => 'fc', 'label' => 'FC', 'type' => 'flexible_content', 'layouts' => [
        'layout_smcp_txt' => ['key' => 'layout_smcp_txt', 'name' => 'txt', 'label' => 'Txt', 'display' => 'block', 'sub_fields' => [
            ['key' => 'field_smcp_fc_t', 'name' => 't', 'label' => 'T', 'type' => 'text'],
        ]],
        'layout_smcp_img' => ['key' => 'layout_smcp_img', 'name' => 'img', 'label' => 'Img', 'display' => 'block', 'sub_fields' => [
            ['key' => 'field_smcp_fc_i', 'name' => 'i', 'label' => 'I', 'type' => 'image'],
        ]],
    ]],
]]);

// ── Friendly nested values → the request the block editor posts (acf-block[...]) ──────────────────

function smcp_cap_conv($f, $v) {
    switch ($f['type']) {
        case 'repeater':
            $o = [];
            foreach ($v as $i => $row) $o['row-' . $i] = smcp_cap_row($f['sub_fields'], $row);
            return $o;
        case 'group':
        case 'clone':
            return smcp_cap_row($f['sub_fields'], $v);
        case 'flexible_content':
            $o = [];
            foreach ($v as $i => $row) {
                $layout = null;
                foreach ($f['layouts'] as $l) if ($l['name'] === $row['acf_fc_layout']) $layout = $l;
                $r = ['acf_fc_layout' => $row['acf_fc_layout']];
                if (!empty($row['acf_fc_layout_disabled'])) $r['acf_fc_layout_disabled'] = 1;
                if (!empty($row['acf_fc_layout_custom_label'])) $r['acf_fc_layout_custom_label'] = $row['acf_fc_layout_custom_label'];
                $o['r' . $i] = $r + smcp_cap_row($layout['sub_fields'], $row);
            }
            return $o;
    }
    return $v;
}

function smcp_cap_row($subs, $row) {
    $o = [];
    foreach ($subs as $sf) if (array_key_exists($sf['name'], $row)) $o[$sf['key']] = smcp_cap_conv($sf, $row[$sf['name']]);
    return $o;
}

function smcp_cap_request($defs, $values) {
    $req = [];
    foreach ($values as $name => $v) {
        $f = $defs[$name];
        if (!empty($f['_clone'])) { // top-level seamless clone: acf-block[<clone key>][<temporary key>]
            $req[$f['_clone']][$f['key']] = smcp_cap_conv($f, $v);
            continue;
        }
        $req[$f['key']] = smcp_cap_conv($f, $v);
    }
    return $req;
}

/** Compares our flattened block data with ACF's capture; true when they match. */
function smcp_cap_compare($label, $block, $values) {
    $defs = Simple_MCP_Tools_Blocks::block_field_defs($block);
    $ida  = 'block_smcpa_' . uniqid();
    $ido  = 'block_smcpo_' . uniqid();

    // Ours: validate with replace semantics (no defaults) + flatten.
    $errors = [];
    $tree   = [];
    foreach ($values as $name => $v) $tree[$name] = Simple_MCP_Tools_Blocks::merge_value($defs[$name], null, $v, $name, $errors, false);
    $ours = [];
    Simple_MCP_Tools_Blocks::flatten(array_values($defs), $tree, '', $ours);

    // ACF: its capture of the equivalent editor request.
    $acf = acf_setup_meta(smcp_cap_request($defs, $values), $ida, false);
    acf_reset_meta($ida);

    $only_acf  = array_diff_key($acf, $ours);
    $only_ours = array_diff_key($ours, $acf);
    $diffs     = [];
    foreach ($ours as $k => $v) {
        // ACF's capture keeps the temporary "<clone>_<field>" reference for a seamless clone inside a
        // repeater; we store the real key on purpose (it resolves through acf_get_field()).
        if ($k === '_rep_0_title') continue;
        if (array_key_exists($k, $acf) && $acf[$k] !== $v) $diffs[$k] = ['acf' => $acf[$k], 'ours' => $v];
    }

    // Front-end view: get_fields() on both.
    acf_setup_meta($acf, $ida, true);
    $fields_acf = get_fields($ida);
    acf_reset_meta($ida);
    acf_setup_meta($ours, $ido, true);
    $fields_ours = get_fields($ido);
    acf_reset_meta($ido);

    $pass = !$errors && !$only_acf && !$only_ours && !$diffs && $fields_acf == $fields_ours;
    echo ($pass ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$pass) {
        echo '       validation_errors=' . wp_json_encode($errors, JSON_UNESCAPED_UNICODE) . "\n";
        echo '       only_in_acf=' . wp_json_encode(array_keys($only_acf)) . ' only_in_ours=' . wp_json_encode(array_keys($only_ours)) . "\n";
        echo '       value_diffs=' . wp_json_encode($diffs, JSON_UNESCAPED_UNICODE) . "\n";
        echo '       get_fields equal=' . var_export($fields_acf == $fields_ours, true) . "\n";
    }
    return $pass;
}

// ── Cases ────────────────────────────────────────────────────────────────────

$smcp_cap_fail += !smcp_cap_compare('repeater, group, clones (group/seamless, nested) and flexible content', 'acf/smcp-cap', [
    'txt'       => 'T',
    'rep'       => [['t' => 'r0', 'img' => '2702', 'title' => 'RT'], ['t' => 'r1']],
    'grp'       => ['a' => 'A', 'gr' => [['x' => 'GX']]],
    'cg'        => ['cg_title' => 'CG'],
    'hdr_title' => 'HS',
    'hdr_sub'   => 'HSS',
    'fc'        => [
        ['acf_fc_layout' => 'txt', 't' => 'F0'],
        ['acf_fc_layout' => 'img', 'i' => 2702, 'acf_fc_layout_disabled' => true],
        ['acf_fc_layout' => 'txt', 't' => 'F2', 'acf_fc_layout_custom_label' => 'Lbl'],
    ],
]);
$smcp_cap_fail += !smcp_cap_compare('empty containers', 'acf/smcp-cap', ['rep' => [], 'fc' => [], 'grp' => []]);

$smcp_cap_done($smcp_cap_fail);
