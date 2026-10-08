<?php
/**
 * Release version consistency check.
 *
 *   php bin/check-version.php           # all version sources agree
 *   php bin/check-version.php v2.5.0    # …and match this tag
 *
 * Sources: the simple-mcp.php "Version:" header, SIMPLE_MCP_VERSION, README «Поточна версія» and the
 * newest CHANGELOG.md entry ("### X.Y.Z — …" under "## Зміни").
 * Exit code 0 when consistent, 1 otherwise.
 */
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/release-lib.php';

$tag      = $argv[1] ?? null;
$versions = smcp_versions(smcp_read_sources(smcp_root()));

foreach ($versions as $label => $version) {
    printf("%s: %s\n", $label, $version ?? "(not found)");
}
if ($tag !== null) {
    printf("tag %s: %s\n", $tag, smcp_tag_version($tag));
}

$errors = smcp_version_errors($versions, $tag);
if ($errors) {
    fwrite(STDERR, "\nVersion check FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "\nVersion check OK.\n";
exit(0);
