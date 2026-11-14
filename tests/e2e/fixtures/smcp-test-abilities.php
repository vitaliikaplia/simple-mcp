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
