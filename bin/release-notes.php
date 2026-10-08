<?php
/**
 * Prints the CHANGELOG.md entry of a release (used as GitHub Release notes).
 *
 *   php bin/release-notes.php v2.5.0           # Markdown body of "### 2.5.0 — …" (hard wraps joined)
 *   php bin/release-notes.php --title v2.5.0   # "2.5.0 — <title>"
 *
 * Exit code 1 when the entry is missing or empty.
 */
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/release-lib.php';

$args  = array_slice($argv, 1);
$title = in_array('--title', $args, true);
$args  = array_values(array_diff($args, ['--title']));
if (!isset($args[0]) || $args[0] === '') {
    fwrite(STDERR, "Usage: php bin/release-notes.php [--title] <tag>\n");
    exit(1);
}

$version = smcp_tag_version($args[0]);
$log     = (string) @file_get_contents(smcp_root() . '/CHANGELOG.md');
$entry   = smcp_changelog_entry($log, $version);
if ($entry === null || $entry['body'] === '') {
    fwrite(STDERR, "CHANGELOG.md has no non-empty entry \"### $version\" under \"## Зміни\".\n");
    exit(1);
}

// The changelog is hard-wrapped; GitHub shows every newline of a release body as a line break.
echo $title ? ($entry['title'] !== '' ? $version . ' — ' . $entry['title'] : $version) . "\n" : smcp_unwrap_markdown($entry['body']);
exit(0);
