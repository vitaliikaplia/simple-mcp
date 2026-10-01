<?php
/**
 * Dependency-free test runner.
 *
 *   php tests/run.php            # every tests/test-*.php
 *   php tests/run.php updater    # only tests whose name contains "updater"
 *
 * Exit code 1 when any test fails (or when nothing ran). tests/integration/*.php need WordPress and are
 * run separately with `wp eval-file` (see each file's header).
 */
if (PHP_SAPI !== 'cli') exit(1);

// Warnings/notices fail the test that raised them; deprecations are reported but do not fail.
$deprecations = [];
set_error_handler(function ($severity, $message, $file, $line) use (&$deprecations) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        $deprecations[$file . ':' . $line] = $message;
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/test-*.php') ?: [];
sort($files);
foreach ($files as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$counts = ['ok' => 0, 'fail' => 0, 'skip' => 0];
$fails  = [];

foreach ($GLOBALS['smcp_tests'] as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $counts['ok']++;
        echo "  ok    $name\n";
    } catch (SMCP_Test_Skipped $e) {
        $counts['skip']++;
        echo "  skip  $name — " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        $counts['fail']++;
        $where   = $e instanceof SMCP_Test_Failure ? '' : ' (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        $fails[] = "$name: " . $e->getMessage() . $where;
        echo "  FAIL  $name\n        " . $e->getMessage() . $where . "\n";
    }
}

foreach ($deprecations as $where => $message) {
    echo "  deprecated: $message ($where)\n";
}

printf("\nPHP %s — %d passed, %d failed, %d skipped.\n", PHP_VERSION, $counts['ok'], $counts['fail'], $counts['skip']);
if ($fails) {
    echo "\nFailures:\n - " . implode("\n - ", $fails) . "\n";
}
exit($counts['fail'] > 0 || $counts['ok'] === 0 ? 1 : 0);
