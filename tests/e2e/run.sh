#!/usr/bin/env bash
# WordPress smoke test (CI job "wordpress"): serves an installed WordPress with PHP's built-in server and runs
# tests/e2e/smoke.php against the Simple MCP endpoint over HTTP. The site must already have the plugin active,
# SIMPLE_MCP_ALLOW_INSECURE (plain-HTTP localhost), WP_DEBUG + WP_DEBUG_LOG on, WP_DEBUG_DISPLAY off, and an
# administrator with user ID 1. Any warning, notice, deprecation or fatal error raised in the plugin's own files
# (in the web requests or in the wp_cli subprocesses) fails the run. After the key smoke test, tests/e2e/oauth.php walks
# the OAuth way in (switched on for it, then off again).
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

# ── OAuth (off by default): switch it on, sign the administrator in, walk the whole way in, then change the
# settings between phases the way an administrator would ──
opt() { $WPCLI --path="$WP_DIR" eval "\$o = get_option(Simple_MCP::OPTION, []); $1 update_option(Simple_MCP::OPTION, \$o); echo 'OPT=ok';" | grep -q 'OPT=ok'; }
opt '$o["oauth_enabled"] = true; Simple_MCP_OAuth::ensure_tables();'
COOKIE=$($WPCLI --path="$WP_DIR" eval 'echo "COOKIE=", LOGGED_IN_COOKIE, "=", wp_generate_auth_cookie(1, time() + 3600, "logged_in"), "\n";' | grep -o 'COOKIE=.*' | cut -d= -f2-)
[ -n "$COOKIE" ] || { echo "could not sign the administrator in"; exit 1; }
STATE="$LOGS/oauth-state.json"
"$PHP" "$HERE/oauth.php" "http://127.0.0.1:$PORT/simple-mcp" "$COOKIE" "$STATE" flow
opt '$o["oauth_wp_cli"] = false;'
"$PHP" "$HERE/oauth.php" "http://127.0.0.1:$PORT/simple-mcp" "$COOKIE" "$STATE" no-cli
$WPCLI --path="$WP_DIR" eval '
    global $wpdb;
    $n = $wpdb->query("UPDATE " . Simple_MCP_OAuth::table("tokens") . " SET used_at = used_at - 3600 WHERE kind = \"refresh\" AND used_at IS NOT NULL");
    echo "AGED=", (int) $n, "\n";' | grep -q 'AGED=[1-9]' || { echo "no spent refresh token to age"; exit 1; }
"$PHP" "$HERE/oauth.php" "http://127.0.0.1:$PORT/simple-mcp" "$COOKIE" "$STATE" reuse
$WPCLI --path="$WP_DIR" eval '
    $n = 0;
    foreach (Simple_MCP_Auth::keys_for(1) as $k) if ($k["via"] !== "") $n += count(Simple_MCP_Auth::revoke_key_for(1, $k["id"]));
    echo "REVOKED=", $n, "\n";' | grep -q 'REVOKED=2' || { echo "expected two OAuth connections to revoke"; exit 1; }
echo "ok   both OAuth connections were keys of the administrator, revoked in the profile"
"$PHP" "$HERE/oauth.php" "http://127.0.0.1:$PORT/simple-mcp" "$COOKIE" "$STATE" revoked
opt '$o["oauth_enabled"] = false; $o["oauth_wp_cli"] = true;'
"$PHP" "$HERE/oauth.php" "http://127.0.0.1:$PORT/simple-mcp" "$COOKIE" "$STATE" off
$WPCLI --path="$WP_DIR" eval '
    global $wpdb;
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . Simple_MCP_Audit::table() . "` WHERE tool IN (\"oauth_register\", \"oauth_connect\", \"oauth_consent\")");
    echo "OAUTH_AUDIT=", $n, "\n";' | grep -q 'OAUTH_AUDIT=[1-9]' || { echo "the audit log has no OAuth rows"; exit 1; }
echo "ok   the audit log recorded registrations, consents and connections"

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
