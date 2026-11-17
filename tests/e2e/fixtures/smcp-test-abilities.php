<?php
/**
 * Test abilities for tests/e2e/abilities.php — copied into wp-content/mu-plugins by tests/e2e/run.sh for the
 * duration of the run and removed afterwards. Never part of the plugin (tests/ is export-ignore).
 *
 * Mirrors what another plugin does to give Simple MCP a tool: register a category and abilities on the Abilities
 * API hooks, mark the ones for MCP with meta.mcp.public / meta.public.
 */
if (!function_exists('wp_register_ability')) return;

add_action('wp_abilities_api_categories_init', function () {
    wp_register_ability_category('smcp-test', ['label' => 'Simple MCP test', 'description' => 'Abilities of the Simple MCP end-to-end test.']);
});

add_action('wp_abilities_api_init', function () {
    $base = ['category' => 'smcp-test', 'permission_callback' => function () { return current_user_can('edit_posts'); }];

    // An object schema with a WP-REST style boolean "required", read-only, structured output
    wp_register_ability('smcp-test/echo', $base + [
        'label'            => 'Echo text',
        'description'      => 'Repeats the given text a number of times.',
        'input_schema'     => [
            'type'                 => 'object',
            'properties'           => [
                'text'  => ['type' => 'string', 'required' => true, 'minLength' => 1],
                'times' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1],
            ],
            'additionalProperties' => false,
        ],
        'execute_callback' => function ($input) {
            return ['echo' => str_repeat((string) $input['text'], (int) ($input['times'] ?? 1)), 'user' => wp_get_current_user()->user_login];
        },
        'meta'             => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
    ]);

    // A string schema (wrapped into {input}), exposed by the general meta.public
    wp_register_ability('smcp-test/shout', $base + [
        'label'            => 'Shout',
        'description'      => 'Upper-cases a string.',
        'input_schema'     => ['type' => 'string', 'minLength' => 1],
        'execute_callback' => function ($input) { return strtoupper((string) $input); },
        'meta'             => ['public' => true],
    ]);

    // No input schema at all
    wp_register_ability('smcp-test/site-name', $base + [
        'label'            => 'Site name',
        'description'      => 'Returns the site name.',
        'execute_callback' => function () { return ['name' => get_bloginfo('name')]; },
        'meta'             => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]],
    ]);

    // An object input schema with 'default' => array(), as WordPress core/get-site-info and Simple Storage declare it
    wp_register_ability('smcp-test/options', $base + [
        'label'            => 'Options',
        'description'      => 'Optional flags only.',
        'input_schema'     => [
            'type'       => 'object',
            'properties' => ['verbose' => ['type' => 'boolean', 'default' => false]],
            'default'    => [],
        ],
        'execute_callback' => function ($input = []) { return ['verbose' => !empty($input['verbose'])]; },
        'meta'             => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]],
    ]);

    // Refused by its own permission check (a WP_Error, which core would also turn into a _doing_it_wrong notice)
    wp_register_ability('smcp-test/forbidden', [
        'category'            => 'smcp-test',
        'label'               => 'Forbidden',
        'description'         => 'Nobody may run this.',
        'permission_callback' => function () { return new WP_Error('nope', 'secret reason that must not leak'); },
        'execute_callback'    => function () { return 'should never run'; },
        'meta'                => ['mcp' => ['public' => true]],
    ]);

    // Fails inside its own callback
    wp_register_ability('smcp-test/fails', $base + [
        'label'            => 'Fails',
        'description'      => 'Always returns an error.',
        'execute_callback' => function () { return new WP_Error('smcp_test_failed', 'The test ability failed on purpose.'); },
        'meta'             => ['mcp' => ['public' => true]],
    ]);

    // Images in the result (_mcp_images, 2.7.7): count small PNGs, then a 2 MB one when big is set; inline reports the
    // simple_mcp_inline_images signal Simple MCP turns on while it runs the ability
    wp_register_ability('smcp-test/image', $base + [
        'label'            => 'Image',
        'description'      => 'Returns PNG images to the client.',
        'input_schema'     => [
            'type'       => 'object',
            'properties' => ['count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6, 'default' => 1], 'big' => ['type' => 'boolean', 'default' => false]],
            'default'    => [],
        ],
        'execute_callback' => function ($input = []) {
            // a 1×1 PNG
            $png    = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
            $images = array_fill(0, (int) ($input['count'] ?? 1), ['mimeType' => 'image/png', 'data' => $png]);
            if (!empty($input['big'])) $images[] = ['mimeType' => 'image/png', 'data' => base64_encode(str_repeat("\0", 2 * 1048576))];
            return ['inline' => (bool) apply_filters('simple_mcp_inline_images', false), 'count' => count($images), '_mcp_images' => $images];
        },
        'meta'             => ['mcp' => ['public' => true], 'annotations' => ['readonly' => true]],
    ]);

    // Not for MCP: no flag, and public but opted out of MCP
    wp_register_ability('smcp-test/hidden', $base + [
        'label' => 'Hidden', 'description' => 'Not marked for MCP.', 'execute_callback' => '__return_true',
        'meta'  => ['show_in_rest' => true],
    ]);
    wp_register_ability('smcp-test/opted-out', $base + [
        'label' => 'Opted out', 'description' => 'Public, but not for MCP.', 'execute_callback' => '__return_true',
        'meta'  => ['public' => true, 'mcp' => ['public' => false]],
    ]);
});

// ── A theme with its own block tools (2.7.7), switched on per phase by tests/e2e/run.sh: option smcp_test_ext lists
// instructions, hide_blocks, pre_write ──
function smcp_test_ext($what) {
    return in_array($what, (array) get_option('smcp_test_ext', []), true);
}
add_filter('simple_mcp_instructions', function ($lines, $perms) {
    if (smcp_test_ext('instructions')) $lines[] = 'SMCP-TEST: call smcp-test-site-name first' . (empty($perms['abilities']) ? '' : ' (abilities on)') . '.';
    return $lines;
}, 10, 2);
add_filter('simple_mcp_hide_builtin_blocks', function ($hide) {
    return smcp_test_ext('hide_blocks') ? true : $hide;
});
// Refuse body writes to posts titled "SMCP refuse…" (as a theme refuses while its editor has unsaved changes)
add_filter('simple_mcp_pre_write', function ($verdict, $post_id, $tool, $args) {
    if (!smcp_test_ext('pre_write') || strpos(get_the_title($post_id), 'SMCP refuse') !== 0) return $verdict;
    return new WP_Error('smcp_test_busy', 'SMCP-TEST refused ' . $tool . ' on #' . $post_id . ' (args: ' . implode(',', array_keys($args)) . ')');
}, 10, 4);
