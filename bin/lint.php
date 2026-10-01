<?php
/**
 * php -l over every PHP file of the plugin with the running PHP binary.
 *
 *   php bin/lint.php
 *
 * Prints only failures; exit code 1 when any file fails.
 */
if (PHP_SAPI !== 'cli') exit(1);

$root  = dirname(__DIR__);
$skip  = ['.git', '.idea', 'vendor', 'node_modules'];
$files = [];
$it    = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($file) use ($skip) {
            return !($file->isDir() && in_array($file->getFilename(), $skip, true));
        }
    )
);
foreach ($it as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$failed = 0;
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failed++;
        echo implode("\n", $out), "\n";
    }
    $out = [];
}

printf("PHP %s: linted %d files, %d failed.\n", PHP_VERSION, count($files), $failed);
exit($failed ? 1 : 0);
