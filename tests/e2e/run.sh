#!/usr/bin/env bash
# WordPress smoke test (CI job "wordpress"): serves an installed WordPress with PHP's built-in server and runs
# tests/e2e/smoke.php against the Simple MCP endpoint over HTTP. The site must already have the plugin active,
# SIMPLE_MCP_ALLOW_INSECURE (plain-HTTP localhost), WP_DEBUG + WP_DEBUG_LOG on, WP_DEBUG_DISPLAY off, and an
# administrator with user ID 1. Any warning, notice, deprecation or fatal error raised in the plugin's own files
# (in the web requests or in the wp_cli subprocesses) fails the run.
#
#   tests/e2e/run.sh <wordpress-dir> [port]
#   PHP=php8.1 WP="wp" tests/e2e/run.sh /tmp/wordpress 8089   # PHP serves the site, WP is the WP-CLI command
set -euo pipefail

WP_DIR=$(cd "$1" && pwd)
PORT=${2:-8089}
PHP=${PHP:-php}
WPCLI=${WP:-wp}
HERE=$(cd "$(dirname "$0")" && pwd)
PLUGIN=$(cd "$HERE/../.." && pwd)
LOGS=$(mktemp -d)
DEBUG_LOG="$WP_DIR/wp-content/debug.log"
: > "$DEBUG_LOG"

VERSION=$("$PHP" -r 'preg_match("/^\s*\*\s*Version:\s*(\S+)/m", file_get_contents($argv[1]), $m); echo $m[1];' "$PLUGIN/simple-mcp.php")
# a fresh key for the administrator (grep: WP-CLI may print its own deprecation notices around the output)
KEY=$($WPCLI --path="$WP_DIR" eval 'echo "KEY=", Simple_MCP_Auth::generate_key_for(1, "CI smoke"), "\n";' | grep -o 'KEY=smcp-1-[A-Za-z0-9]*' | cut -d= -f2)
[ -n "$KEY" ] || { echo "could not generate a key"; exit 1; }

"$PHP" -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 -d error_log="$LOGS/php-errors.log" \
    -S "127.0.0.1:$PORT" -t "$WP_DIR" "$HERE/router.php" > "$LOGS/server.log" 2>&1 &
SERVER=$!
trap 'kill "$SERVER" 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/wp-login.php" && break
    sleep 0.2
done

"$PHP" "$HERE/smoke.php" "http://127.0.0.1:$PORT/simple-mcp" "$KEY" "$VERSION"

$WPCLI --path="$WP_DIR" eval '
    global $wpdb;
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . Simple_MCP_Audit::table() . "`");
    echo "AUDIT=", $n, "\n";' | grep -q 'AUDIT=[1-9]' || { echo "the audit log stayed empty"; exit 1; }
echo "ok   the audit log recorded the calls"

touch "$LOGS/php-errors.log"
if grep -h -E '/simple-mcp/' "$LOGS/php-errors.log" "$DEBUG_LOG"; then
    echo "FAIL PHP reported the errors above in Simple MCP files"
    exit 1
fi
echo "ok   no PHP errors, warnings or deprecations from Simple MCP files"
