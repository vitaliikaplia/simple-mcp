<?php
/**
 * Router for PHP's built-in server in the WordPress smoke test (tests/e2e/run.sh): existing files are
 * served as they are, every other path goes through WordPress's index.php (the MCP endpoint hooks
 * do_parse_request, so it needs no rewrite rules).
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
