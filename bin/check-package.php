<?php
/**
 * Checks an unpacked release package (the simple-mcp/ folder of simple-mcp.zip).
 *
 *   php bin/check-package.php build/simple-mcp
 *
 * Every file simple-mcp.php requires is present, includes/tools/ holds the tool modules, and every
 * PHP file in the package passes `php -l` with the running PHP. Exit code 1 on any problem.
 */
if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/release-lib.php';

$dir = rtrim((string) ($argv[1] ?? ''), '/');
if ($dir === '' || !is_file($dir . '/simple-mcp.php')) {
    fwrite(STDERR, "Usage: php bin/check-package.php <unpacked simple-mcp folder>\n");
    exit(1);
}

$errors  = [];
$targets = smcp_require_targets((string) file_get_contents($dir . '/simple-mcp.php'));
if (!$targets) $errors[] = 'simple-mcp.php: no require_once SIMPLE_MCP_DIR . … found';
foreach ($targets as $rel) {
    if (!is_file($dir . '/' . $rel)) $errors[] = 'missing required file: ' . $rel;
}
if (!glob($dir . '/includes/tools/*.php')) $errors[] = 'includes/tools/ holds no tool modules';

$files = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') $files[] = $file->getPathname();
}
sort($files);
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) $errors[] = implode("\n", $out);
    $out = [];
}

printf("PHP %s: %d required files, %d PHP files linted.\n", PHP_VERSION, count($targets), count($files));
if ($errors) {
    fwrite(STDERR, "\nPackage check FAILED:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
exit(0);
