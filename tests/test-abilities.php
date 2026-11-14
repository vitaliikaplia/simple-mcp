<?php
/**
 * Інструменти інших плагінів і тем (Simple_MCP_Tools_Abilities): яка ability показується агенту, як її ім'я, схема
 * й анотації стають інструментом MCP, коли ховаються вбудовані wploc_*, і дефолти нового рядка матриці ролей.
 * Виклик справжніх abilities — у tests/e2e/abilities.php на WordPress із тестовим mu-плагіном.
 */

smcp_test('abilities: only abilities marked for MCP are shown, the way the official MCP Adapter reads them', function () {
    foreach ([
        'mcp.public true'                     => [['mcp' => ['public' => true]], true],
        'public true'                         => [['public' => true], true],
        'public true, mcp.public false'       => [['public' => true, 'mcp' => ['public' => false]], false],
        'mcp.public true, public false'       => [['public' => false, 'mcp' => ['public' => true]], true],
        'no flag'                             => [['annotations' => ['readonly' => true], 'show_in_rest' => true], false],
        'show_in_rest only'                   => [['show_in_rest' => true], false],
        'truthy but not true'                 => [['mcp' => ['public' => 1]], false],
        'resource, not a tool'                => [['mcp' => ['public' => true, 'type' => 'resource']], false],
        'prompt, not a tool'                  => [['public' => true, 'mcp' => ['type' => 'prompt']], false],
        'explicit tool'                       => [['mcp' => ['public' => true, 'type' => 'tool']], true],
    ] as $label => [$meta, $want]) {
        smcp_assert_same($want, Simple_MCP_Tools_Abilities::exposed_meta($meta), $label);
    }
});

smcp_test('abilities: the tool name is the ability name with the slash as a hyphen (as the MCP Adapter does)', function () {
    smcp_assert_same('wp-loc-get-translations', Simple_MCP_Tools_Abilities::tool_name('wp-loc/get-translations'));
    smcp_assert_same('simple-seo-audit', Simple_MCP_Tools_Abilities::tool_name('simple-seo/audit'));
    smcp_assert_same('a-b', Simple_MCP_Tools_Abilities::tool_name(' A//B '));
    smcp_assert_same('', Simple_MCP_Tools_Abilities::tool_name('///'));
    smcp_assert_same(128, strlen(Simple_MCP_Tools_Abilities::tool_name('x/' . str_repeat('a', 300))));
    smcp_assert_true((bool) preg_match('/^[a-z0-9-]+$/', Simple_MCP_Tools_Abilities::tool_name('ns/do-thing')), 'never an underscore, so never a built-in name');
});

smcp_test('abilities: a WP REST schema becomes a JSON Schema MCP clients read', function () {
    $s = Simple_MCP_Tools_Abilities::normalize_schema([
        'type'       => 'object',
        'properties' => [
            'post_id' => ['type' => 'integer', 'required' => true, 'context' => ['edit'], 'arg_options' => ['x' => 1]],
            'title'   => ['type' => 'string', 'readonly' => true],
            'nested'  => ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'required' => true]]],
        ],
        'required'   => ['title'],
    ]);
    smcp_assert_same(['title', 'post_id'], $s['required'], 'boolean required lifted into the parent\'s array');
    smcp_assert_same(['type' => 'integer'], $s['properties']['post_id'], 'WP-only keys dropped');
    smcp_assert_same(['type' => 'string'], $s['properties']['title']);
    smcp_assert_same(['a'], $s['properties']['nested']['required'], 'nested objects too');
    smcp_assert_same(['type' => 'object', 'properties' => []], Simple_MCP_Tools_Abilities::normalize_schema(['type' => 'object', 'properties' => []]));
});

smcp_test('abilities: an MCP tool takes an object, so other input is wrapped in {input}', function () {
    smcp_assert_same([['type' => 'object', 'properties' => []], false], Simple_MCP_Tools_Abilities::input_shape([]), 'no schema — an empty object, no input');
    $obj = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];
    smcp_assert_same([$obj, false], Simple_MCP_Tools_Abilities::input_shape($obj));
    [$s, $wrapped] = Simple_MCP_Tools_Abilities::input_shape(['properties' => ['q' => ['type' => 'string']]]);
    smcp_assert_same('object', $s['type'], 'properties without a type are an object');
    smcp_assert_false($wrapped);
    [$s, $wrapped] = Simple_MCP_Tools_Abilities::input_shape(['type' => 'string', 'description' => 'Text']);
    smcp_assert_true($wrapped);
    smcp_assert_same(['type' => 'object', 'properties' => ['input' => ['type' => 'string', 'description' => 'Text']], 'required' => ['input']], $s);
    [$s] = Simple_MCP_Tools_Abilities::input_shape(['type' => 'integer', 'default' => 5]);
    smcp_assert_false(isset($s['required']), 'a schema with a default makes input optional');
});

smcp_test('abilities: annotations accept WordPress and MCP names; unknown ones stay cautious', function () {
    smcp_assert_same(['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        Simple_MCP_Tools_Abilities::annotations(['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]]));
    smcp_assert_same(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
        Simple_MCP_Tools_Abilities::annotations(['annotations' => ['readonly' => null, 'destructive' => null, 'idempotent' => null]]), 'core defaults are null');
    smcp_assert_same(['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
        Simple_MCP_Tools_Abilities::annotations(['annotations' => ['readOnlyHint' => true, 'openWorldHint' => true]]));
    smcp_assert_same(false, Simple_MCP_Tools_Abilities::annotations(['annotations' => ['readonly' => false, 'readOnlyHint' => false]])['readOnlyHint']);
    smcp_assert_same(true, Simple_MCP_Tools_Abilities::annotations([])['destructiveHint'], 'no annotations — may change things');
});

smcp_test('abilities: built-in wploc_* step aside only when wp-loc shows abilities of its own', function () {
    smcp_assert_true(Simple_MCP_Tools_Abilities::provides_namespace(['simple-seo/audit', 'wp-loc/get-translations'], 'wp-loc'));
    smcp_assert_false(Simple_MCP_Tools_Abilities::provides_namespace(['wp-loc-extra/x', 'core/get-site-info'], 'wp-loc'), 'another namespace that only starts the same');
    smcp_assert_false(Simple_MCP_Tools_Abilities::provides_namespace([], 'wp-loc'));
});

smcp_test('abilities: wp-loc/… abilities also need the «Мультимовність» row, other namespaces only «Інструменти плагінів і теми»', function () {
    smcp_assert_same('wploc', Simple_MCP_Tools_Abilities::required_perm('wp-loc/get-translations'));
    smcp_assert_same('wploc', Simple_MCP_Tools_Abilities::required_perm('wp-loc/create-translation'));
    smcp_assert_same(null, Simple_MCP_Tools_Abilities::required_perm('wp-locx/get-translations'), 'a namespace that only starts the same');
    smcp_assert_same(null, Simple_MCP_Tools_Abilities::required_perm('smcp-test/echo'));
    smcp_assert_same(null, Simple_MCP_Tools_Abilities::required_perm('no-slash'));
});

smcp_test('abilities: other tools name a translation tool only when the agent has one', function () {
    $perms = new ReflectionProperty('Simple_MCP_Auth', 'perms');
    $perms->setAccessible(true);
    $was = $perms->getValue();
    try {
        $perms->setValue(null, null);
        smcp_assert_false(Simple_MCP_Tools_Wploc::builtin_hidden(), 'outside an MCP request nothing is hidden');
        smcp_assert_same(null, Simple_MCP_Tools_Wploc::tool('get_translations'), 'no «Мультимовність» — no translation tool to name');
        smcp_assert_same('', Simple_MCP_Tools_Wploc::hint('link_translation', ' — relink with '), 'and the hint is left out');
        $perms->setValue(null, array_fill_keys(Simple_MCP::PERMS, true));
        // no Abilities API and no multilingual plugin here: nothing is hidden and there is no tool to name
        smcp_assert_false(Simple_MCP_Tools_Wploc::builtin_hidden(), 'no wp-loc abilities — built-ins are not hidden');
        smcp_assert_same(null, Simple_MCP_Tools_Wploc::tool('create_translation'), 'no multilingual plugin — no translation tool');
        smcp_assert_same('', Simple_MCP_Tools_Wploc::hint('create_translation', '; make a translated copy with '));
    } finally {
        $perms->setValue(null, $was);
    }
});

smcp_test('abilities: on a new install the row is on for administrators and editors; a saved row without the key takes the role default', function () {
    smcp_assert_true(in_array('abilities', Simple_MCP::PERMS, true));
    smcp_assert_true(Simple_MCP::role_defaults('administrator')['abilities']);
    smcp_assert_true(Simple_MCP::role_defaults('editor')['abilities']);
    smcp_assert_false(Simple_MCP::role_defaults('author')['abilities']);
    smcp_assert_false(Simple_MCP::role_defaults('custom-role')['abilities']);
    $saved = smcp_with_options(['roles' => [
        'administrator' => ['mcp' => true, 'blocks' => true, 'wploc' => true, 'content' => true, 'wp_cli' => true, 'server_ops' => false],
        'editor'        => ['mcp' => true, 'blocks' => false, 'wploc' => true, 'content' => true, 'abilities' => false],
        'author'        => ['mcp' => true],
    ]], function () {
        return [Simple_MCP::role_perms_raw('administrator'), Simple_MCP::role_perms_raw('editor'), Simple_MCP::role_perms_raw('author')];
    });
    smcp_assert_true($saved[0]['abilities'], 'a row saved before 2.7.0 takes the role default');
    smcp_assert_false($saved[0]['server_ops'], 'saved values stay');
    smcp_assert_false($saved[1]['abilities'], 'an explicit choice wins');
    smcp_assert_false($saved[1]['blocks']);
    smcp_assert_false($saved[2]['abilities'], 'author default stays off');
    smcp_assert_same(Simple_MCP::PERMS, array_keys($saved[0]), 'every permission is present, in order');
});

smcp_test('abilities: a site upgraded from before 2.7.1 is told apart from a new install', function () {
    foreach (['2.5.3', '2.6.0', '2.7.0', '1.3.0'] as $v) smcp_assert_true(Simple_MCP::predates_abilities($v, []), "upgraded from $v");
    foreach (['2.7.1', '2.7.2', '2.8.0', '3.0.0'] as $v) smcp_assert_false(Simple_MCP::predates_abilities($v, []), "already on $v");
    smcp_assert_false(Simple_MCP::predates_abilities(false, []), 'a new install has no version stamp yet');
    smcp_assert_false(Simple_MCP::predates_abilities('', ['enabled' => true, 'path' => 'simple-mcp']));
    smcp_assert_true(Simple_MCP::predates_abilities(false, ['wp_cli_enabled' => true]), 'settings of the versions before the stamp');
    smcp_assert_true(Simple_MCP::predates_abilities(false, ['modules' => []]));
});

smcp_test('abilities: on an upgraded site the row stays off until the administrator turns it on', function () {
    $off = smcp_with_options(['abilities_default_off' => true], function () {
        return [Simple_MCP::role_perms_raw('administrator'), Simple_MCP::role_perms_raw('editor')];
    });
    smcp_assert_false($off[0]['abilities'], 'never-saved matrix: administrator default off');
    smcp_assert_false($off[1]['abilities'], 'editor too');
    smcp_assert_true($off[0]['mcp'] && $off[0]['wp_cli'] && $off[0]['blocks'], 'every other default unchanged');
    $saved = smcp_with_options(['abilities_default_off' => true, 'roles' => [
        'administrator' => ['mcp' => true, 'blocks' => true, 'wploc' => true, 'content' => true, 'wp_cli' => true, 'server_ops' => false],
        'editor'        => ['mcp' => true, 'abilities' => true],
    ]], function () {
        return [Simple_MCP::role_perms_raw('administrator'), Simple_MCP::role_perms_raw('editor')];
    });
    smcp_assert_false($saved[0]['abilities'], 'a row saved before 2.7.0 (no key) stays off');
    smcp_assert_true($saved[1]['abilities'], 'once the administrator turns it on, it is on');
    smcp_assert_true(smcp_with_options([], function () { return Simple_MCP::role_perms_raw('administrator')['abilities']; }), 'a new install: on for administrators');
});
