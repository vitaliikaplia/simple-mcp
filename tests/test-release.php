<?php
/**
 * Release tooling (bin/release-lib.php): version sources, changelog extraction, tag matching.
 */

function smcp_release_sources(string $header = '2.5.0', string $constant = '2.5.0', string $agents = '2.5.0', string $readme = '2.5.0', string $log = '2.5.0'): array {
    return [
        'simple-mcp.php' => "<?php\n/**\n * Plugin Name: Simple MCP\n * Version: $header\n */\ndefine('SIMPLE_MCP_VERSION', '$constant');\n",
        'AGENTS.md'      => "## Release\n\nCurrent release: **$agents**. Canonical author URL: **https://kaplia.pro/**.\n",
        'README.md'      => "# Simple MCP\n\nАвтор: X · Поточна версія: **$readme**.\n\n## Встановлення\n\n### 9.9.9 — not a changelog entry\n\n"
            . "## Зміни\n\n### $log — нове\n\n- **Виправлено:** перше\n  продовження\n- друге\n\n### 2.4.0 — старе\n\n- старий пункт\n\n## Ліцензія\n\n### 0.0.1 — outside\n",
    ];
}

smcp_test('release: consistent sources pass, with and without a tag', function () {
    $versions = smcp_versions(smcp_release_sources());
    smcp_assert_same(['2.5.0'], array_values(array_unique($versions)));
    smcp_assert_same([], smcp_version_errors($versions));
    smcp_assert_same([], smcp_version_errors($versions, 'v2.5.0'));
    smcp_assert_same([], smcp_version_errors($versions, 'refs/tags/v2.5.0'));
    smcp_assert_same([], smcp_version_errors($versions, '2.5.0'));
});

smcp_test('release: every single mismatch is reported', function () {
    $cases = [
        'header'    => smcp_release_sources('2.5.1'),
        'constant'  => smcp_release_sources('2.5.0', '2.4.0'),
        'agents'    => smcp_release_sources('2.5.0', '2.5.0', '2.4.0'),
        'readme'    => smcp_release_sources('2.5.0', '2.5.0', '2.5.0', '2.4.0'),
        'changelog' => smcp_release_sources('2.5.0', '2.5.0', '2.5.0', '2.5.0', '2.5.1'),
    ];
    foreach ($cases as $label => $sources) {
        smcp_assert_true(smcp_version_errors(smcp_versions($sources)) !== [], $label);
    }
});

smcp_test('release: tag mismatch and missing sources fail', function () {
    smcp_assert_true(smcp_version_errors(smcp_versions(smcp_release_sources()), 'v2.5.1') !== [], 'wrong tag');
    $sources = smcp_release_sources();
    $sources['AGENTS.md'] = 'no release line';
    smcp_assert_true(smcp_version_errors(smcp_versions($sources)) !== [], 'missing AGENTS line');
    smcp_assert_true(smcp_version_errors(smcp_versions([])) !== [], 'nothing found');
    smcp_assert_true(smcp_version_errors(smcp_versions(smcp_release_sources('2.5', '2.5', '2.5', '2.5', '2.5'))) !== [], 'not X.Y.Z');
});

smcp_test('release: changelog is read only from the «Зміни» section, newest first', function () {
    $log = smcp_changelog(smcp_release_sources()['README.md']);
    smcp_assert_same(['2.5.0', '2.4.0'], array_column($log, 'version'));
    smcp_assert_same('нове', $log[0]['title']);
    smcp_assert_same("- **Виправлено:** перше\n  продовження\n- друге\n", $log[0]['body']);
    smcp_assert_same("- старий пункт\n", $log[1]['body']);
    smcp_assert_null(smcp_changelog_entry(smcp_release_sources()['README.md'], '0.0.1'), 'entries after the section are ignored');
    smcp_assert_same([], smcp_changelog("# No changelog here\n"));
});

smcp_test('release: non-version ### lines stay inside the entry body', function () {
    $md  = "## Зміни\n\n### 2.5.0 — нове\n\n- a\n\n### Безпека\n\n- b\n\n### 2.4.0 — старе\n\n- c\n";
    $log = smcp_changelog($md);
    smcp_assert_same(['2.5.0', '2.4.0'], array_column($log, 'version'));
    smcp_assert_same("- a\n\n### Безпека\n\n- b\n", $log[0]['body']);
});

smcp_test('release: tag_version strips refs/tags/ and v', function () {
    smcp_assert_same('2.5.0', smcp_tag_version('v2.5.0'));
    smcp_assert_same('2.5.0', smcp_tag_version('refs/tags/v2.5.0'));
    smcp_assert_same('2.5.0', smcp_tag_version('2.5.0'));
});

smcp_test('release: the repository itself is version-consistent', function () {
    $versions = smcp_versions(smcp_read_sources(SMCP_TEST_ROOT));
    smcp_assert_same([], smcp_version_errors($versions), 'run php bin/check-version.php for details');
    $entry = smcp_changelog_entry((string) file_get_contents(SMCP_TEST_ROOT . '/README.md'), (string) reset($versions));
    smcp_assert_true($entry !== null && $entry['body'] !== '', 'README changelog entry for the current version has release notes');
});

smcp_test('release: notes are unwrapped for GitHub (paragraphs and list items on one line)', function () {
    $md = "Intro line one\nline two.\n\n**Heading**\n- item one\n  continues here\n- item two\n  1. nested\n\n### Sub\ntext after heading\nmore\n\n```\ncode line\nkept\n```\nhard break  \nnext\n";
    $want = "Intro line one line two.\n\n**Heading** - item one continues here\n- item two\n  1. nested\n\n### Sub\ntext after heading more\n\n```\ncode line\nkept\n```\nhard break  \nnext\n";
    // a bold line followed by a list item: the list item starts its own line
    $want = str_replace("**Heading** - item one continues here", "**Heading**\n- item one continues here", $want);
    smcp_assert_same($want, smcp_unwrap_markdown($md));
});

smcp_test('release: every file simple-mcp.php requires exists in the plugin', function () {
    $main    = (string) file_get_contents(SMCP_TEST_ROOT . '/simple-mcp.php');
    $targets = smcp_require_targets($main);
    smcp_assert_true(in_array('includes/class-simple-mcp-cli.php', $targets, true), smcp_export($targets));
    foreach ($targets as $rel) {
        smcp_assert_true(is_file(SMCP_TEST_ROOT . '/' . $rel), $rel);
    }
    smcp_assert_same(['a/b.php', 'c.php'], smcp_require_targets("require_once SIMPLE_MCP_DIR . 'a/b.php';\nrequire(SIMPLE_MCP_DIR . \"c.php\");\nrequire_once SIMPLE_MCP_DIR . 'a/b.php';"));
});
