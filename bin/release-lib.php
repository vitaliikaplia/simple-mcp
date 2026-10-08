<?php
/**
 * Release tooling shared by bin/check-version.php, bin/release-notes.php and tests/.
 * Pure functions over file contents; no WordPress needed.
 */

/** Plugin root (the directory that holds simple-mcp.php). */
function smcp_root(): string {
    return dirname(__DIR__);
}

/** "v2.5.0", "refs/tags/v2.5.0" or "2.5.0" → "2.5.0". */
function smcp_tag_version(string $tag): string {
    $tag = trim($tag);
    if (strpos($tag, 'refs/tags/') === 0) {
        $tag = substr($tag, strlen('refs/tags/'));
    }
    return preg_replace('/^v/i', '', $tag);
}

/** The "Version:" header of the main plugin file (first match, as WordPress reads it). */
function smcp_header_version(string $php): ?string {
    if (!preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*Version:(.*)$/mi', substr($php, 0, 8192), $m)) {
        return null;
    }
    $version = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
    return $version !== '' ? $version : null;
}

/** define('SIMPLE_MCP_VERSION', '…'). */
function smcp_constant_version(string $php): ?string {
    return preg_match('/define\(\s*[\'"]SIMPLE_MCP_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/', $php, $m) ? $m[1] : null;
}

/** README.md: "Поточна версія: **X.Y.Z**". */
function smcp_readme_version(string $md): ?string {
    return preg_match('/Поточна версія:\s*\*\*([^*\s]+)\*\*/u', $md, $m) ? $m[1] : null;
}

/**
 * Changelog entries of the "## Зміни" section of CHANGELOG.md, newest first:
 * [['version' => '2.4.0', 'title' => '…', 'body' => '…'], …]. Each entry is a "### X.Y.Z — title"
 * heading; its body runs to the next version heading or the next "##" section. Other "###" lines
 * (sub-headings inside an entry) stay in the body.
 */
function smcp_changelog(string $md): array {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    if (!preg_match('/^##[ \t]+Зміни[ \t]*$/mu', $md, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $section = substr($md, $m[0][1] + strlen($m[0][0]));
    if (preg_match('/^##[ \t]/m', $section, $next, PREG_OFFSET_CAPTURE)) {
        $section = substr($section, 0, $next[0][1]); // stop at the next level-2 heading
    }

    $entries = [];
    $current = null;
    foreach (explode("\n", $section) as $line) {
        if (preg_match('/^###[ \t]+(v?\d+(?:\.\d+)+\S*)(?:[ \t]+(?:—|–|-)[ \t]+(.*?))?[ \t]*$/u', $line, $h)) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = ['version' => smcp_tag_version($h[1]), 'title' => trim($h[2] ?? ''), 'body' => ''];
            continue;
        }
        if ($current !== null) {
            $current['body'] .= $line . "\n";
        }
    }
    if ($current !== null) {
        $entries[] = $current;
    }
    foreach ($entries as &$entry) {
        $entry['body'] = trim($entry['body'], "\n") . "\n";
        $entry['body'] = trim($entry['body']) === '' ? '' : $entry['body'];
    }
    unset($entry);
    return $entries;
}

/** The changelog entry for $version, or null. */
function smcp_changelog_entry(string $md, string $version): ?array {
    foreach (smcp_changelog($md) as $entry) {
        if ($entry['version'] === $version) {
            return $entry;
        }
    }
    return null;
}

/**
 * Every place that carries the release version → its value (null when not found).
 * $sources: ['simple-mcp.php' => …, 'README.md' => …, 'CHANGELOG.md' => …] (file contents).
 */
function smcp_versions(array $sources): array {
    $php = (string) ($sources['simple-mcp.php'] ?? '');
    $log = smcp_changelog((string) ($sources['CHANGELOG.md'] ?? ''));
    return [
        'simple-mcp.php Version header'     => smcp_header_version($php),
        'simple-mcp.php SIMPLE_MCP_VERSION' => smcp_constant_version($php),
        'README.md «Поточна версія»'        => smcp_readme_version((string) ($sources['README.md'] ?? '')),
        'CHANGELOG.md newest entry'         => $log ? $log[0]['version'] : null,
    ];
}

/** Reads the version sources from the plugin root. */
function smcp_read_sources(string $root): array {
    $sources = [];
    foreach (['simple-mcp.php', 'README.md', 'CHANGELOG.md'] as $file) {
        $contents         = @file_get_contents($root . '/' . $file);
        $sources[$file]   = is_string($contents) ? $contents : '';
    }
    return $sources;
}

/** Problems with $versions (empty list = consistent). $tag — optional release tag to match. */
function smcp_version_errors(array $versions, ?string $tag = null): array {
    $errors = [];
    foreach ($versions as $label => $version) {
        if ($version === null || $version === '') {
            $errors[] = "$label: not found";
        }
    }
    $found = array_values(array_unique(array_filter($versions, 'is_string')));
    if (count($found) > 1) {
        $errors[] = 'versions differ: ' . implode(', ', array_map(
            function ($label, $version) { return "$label = " . ($version ?? '∅'); },
            array_keys($versions),
            $versions
        ));
    }
    if (count($found) === 1 && !preg_match('/^\d+\.\d+\.\d+$/', $found[0])) {
        $errors[] = 'version "' . $found[0] . '" is not X.Y.Z';
    }
    if ($tag !== null && $tag !== '') {
        $tag_version = smcp_tag_version($tag);
        if (count($found) !== 1 || $found[0] !== $tag_version) {
            $errors[] = 'tag ' . $tag . ' (' . $tag_version . ') does not match the plugin version' . ($found ? ' ' . implode('/', $found) : '');
        }
    }
    return $errors;
}

/**
 * Markdown with hard-wrapped paragraphs and list items joined into one line each, for GitHub release
 * notes (GitHub renders a single newline in a release body as a line break). A line is joined to the
 * previous one unless either is blank, it starts a heading, list item, quote or table row, or the
 * previous line is a heading or ends with a hard break (two spaces or a backslash). Fenced code is
 * kept as is.
 */
function smcp_unwrap_markdown(string $md): string {
    $md    = str_replace(["\r\n", "\r"], "\n", $md);
    $out   = [];
    $fence = false;
    $join  = false; // may the next line continue the last output line?
    foreach (explode("\n", $md) as $line) {
        if (preg_match('/^[ \t]*(```|~~~)/', $line)) {
            $fence = !$fence;
            $out[] = $line;
            $join  = false;
            continue;
        }
        if ($fence || trim($line) === '') {
            $out[] = $line;
            $join  = false;
            continue;
        }
        $block = (bool) preg_match('/^[ \t]*(#{1,6}[ \t]|[-*+][ \t]|\d+[.)][ \t]|>|\|)/', $line);
        if ($join && !$block) {
            $out[count($out) - 1] = rtrim($out[count($out) - 1]) . ' ' . ltrim($line);
        } else {
            $out[] = $line;
        }
        $last = $out[count($out) - 1];
        $join = !preg_match('/^[ \t]*(#{1,6}[ \t]|\|)/', $last) && !preg_match('/(  |\\\\)$/', $last);
    }
    return implode("\n", $out);
}

/**
 * Files simple-mcp.php loads with `require_once SIMPLE_MCP_DIR . '…'` (paths relative to the plugin
 * root) — the release job checks that each of them is inside the built zip.
 */
function smcp_require_targets(string $php): array {
    preg_match_all('/\brequire(?:_once)?\s*\(?\s*SIMPLE_MCP_DIR\s*\.\s*[\'"]([^\'"]+)[\'"]/', $php, $m);
    return array_values(array_unique($m[1]));
}
