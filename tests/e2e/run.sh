#!/usr/bin/env bash
# WordPress smoke test (CI job "wordpress"): serves an installed WordPress with PHP's built-in server and runs
# tests/e2e/smoke.php against the Simple MCP endpoint over HTTP. The site must already have the plugin active,
# SIMPLE_MCP_ALLOW_INSECURE (plain-HTTP localhost), WP_DEBUG + WP_DEBUG_LOG on, WP_DEBUG_DISPLAY off, and an
# administrator with user ID 1. Any warning, notice, deprecation or fatal error raised in the plugin's own files
# (in the web requests or in the wp_cli subprocesses) fails the run. After the key smoke test, tests/e2e/oauth.php walks
# the OAuth way in (switched on for it, then off again), and tests/e2e/abilities.php calls other plugins' tools
# (WordPress Abilities from tests/e2e/fixtures, installed as a mu-plugin for the run only), tests/e2e/extensibility.php checks
# the hooks for a theme with its own block tools (instructions, pre-write veto, hidden block_*, images), an agent's request runs the overdue daily
# housekeeping without WP-Cron (and not when it is switched off), tests/integration/housekeeping.php checks its claim, settings
# and paths, and tests/integration/admin-tabs.php
# checks the tabs of the settings page.
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
# Test abilities (another plugin's tools through the WordPress Abilities API) as a mu-plugin, only for this run
MU="$WP_DIR/wp-content/mu-plugins"
MU_FIXTURE="$MU/smcp-test-abilities.php"
mkdir -p "$MU"
cp "$HERE/fixtures/smcp-test-abilities.php" "$MU_FIXTURE"
trap 'rm -f "$MU_FIXTURE"' EXIT
DEBUG_LOG="$WP_DIR/wp-content/debug.log"
: > "$DEBUG_LOG"

VERSION=$("$PHP" -r 'preg_match("/^\s*\*\s*Version:\s*(\S+)/m", file_get_contents($argv[1]), $m); echo $m[1];' "$PLUGIN/simple-mcp.php")
# the fixture's theme hooks start switched off (a run that failed half-way may have left them on)
$WPCLI --path="$WP_DIR" eval 'delete_option("smcp_test_ext"); echo "EXT=off\n";' | grep -q 'EXT=off' || { echo "could not reset smcp_test_ext"; exit 1; }
# a fresh key for the administrator (grep: WP-CLI may print its own deprecation notices around the output)
KEY=$($WPCLI --path="$WP_DIR" eval 'echo "KEY=", Simple_MCP_Auth::generate_key_for(1, "CI smoke"), "\n";' | grep -o 'KEY=smcp-1-[A-Za-z0-9]*' | cut -d= -f2)
[ -n "$KEY" ] || { echo "could not generate a key"; exit 1; }

"$PHP" -d error_reporting=E_ALL -d display_errors=0 -d log_errors=1 -d error_log="$LOGS/php-errors.log" \
    -S "127.0.0.1:$PORT" -t "$WP_DIR" "$HERE/router.php" > "$LOGS/server.log" 2>&1 &
SERVER=$!
trap 'kill "$SERVER" 2>/dev/null || true; rm -f "$MU_FIXTURE"' EXIT
for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/wp-login.php" && break
    sleep 0.2
done

"$PHP" "$HERE/smoke.php" "http://127.0.0.1:$PORT/simple-mcp" "$KEY" "$VERSION"
"$PHP" "$HERE/abilities.php" "http://127.0.0.1:$PORT/simple-mcp" "$KEY"
# A theme with its own block tools (2.7.7): the fixture's hooks, switched on per phase by the option smcp_test_ext
ext() { $WPCLI --path="$WP_DIR" eval "update_option('smcp_test_ext', $1); echo 'EXT=ok';" | grep -q 'EXT=ok' || { echo "could not set smcp_test_ext"; exit 1; }; }
ext "['instructions', 'hide_blocks', 'pre_write']"
"$PHP" "$HERE/extensibility.php" "http://127.0.0.1:$PORT/simple-mcp" "$KEY" theme
ext "['pre_write']"
"$PHP" "$HERE/extensibility.php" "http://127.0.0.1:$PORT/simple-mcp" "$KEY" blocks
$WPCLI --path="$WP_DIR" eval '
    delete_option("smcp_test_ext");
    $id = wp_insert_post(["post_title" => "SMCP lock", "post_status" => "draft"]);
    update_post_meta($id, "_edit_lock", time() . ":1");
    $shown = Simple_MCP_Tools_Blocks::edit_lock_warning($id) !== null;
    add_filter("simple_mcp_hide_builtin_blocks", "__return_true");
    $dropped = Simple_MCP_Tools_Blocks::edit_lock_warning($id) === null;
    foreach (get_posts(["post_type" => "post", "post_status" => "any", "posts_per_page" => -1, "s" => "SMCP"]) as $p) {
        if (strpos($p->post_title, "SMCP ") === 0) wp_delete_post($p->ID, true);
    }
    echo "LOCK=", (int) $shown, (int) $dropped, "\n";' | grep -q 'LOCK=11' || { echo "FAIL editor_lock_warning is not shown for another user's open editor, or not dropped when the theme owns the blocks"; exit 1; }
echo "ok   editor_lock_warning: shown for another user's open editor, dropped when the theme replays changes itself"
# A site upgraded from before 2.7.1: the migration marks it, the row «Інструменти плагінів і теми» is off by default
$WPCLI --path="$WP_DIR" eval '
    update_option("simple_mcp_version", "2.6.0");
    Simple_MCP::maybe_upgrade();
    echo "FLAG=", (int) !empty(get_option(Simple_MCP::OPTION)["abilities_default_off"]), " ADMIN=", (int) Simple_MCP::role_perms_raw("administrator")["abilities"],
        " STAMP=", get_option("simple_mcp_version"), "\n";' | grep -q "FLAG=1 ADMIN=0 STAMP=$VERSION" || { echo "the upgrade did not keep plugin tools off"; exit 1; }
echo "ok   an upgrade from 2.6.0 marks the site and keeps plugin tools off"
"$PHP" "$HERE/abilities.php" "http://127.0.0.1:$PORT/simple-mcp" "$KEY" hidden
$WPCLI --path="$WP_DIR" eval '$o = get_option(Simple_MCP::OPTION); unset($o["abilities_default_off"]); update_option(Simple_MCP::OPTION, $o); echo "OK\n";' | grep -q OK

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

# ── Daily housekeeping without WP-Cron (2.7.5): an agent's request cleans up after its response when the last
# run is older than a day; an unauthenticated one does not ──
hk_seed() {
    $WPCLI --path="$WP_DIR" eval '
        global $wpdb;
        $wpdb->replace($wpdb->options, ["option_name" => Simple_MCP::PRUNED_OPTION, "option_value" => (string) (time() - 2 * DAY_IN_SECONDS), "autoload" => "no"]);
        $wpdb->replace(Simple_MCP_OAuth::table("codes"), ["code_hash" => hash("sha256", "smcp-e2e-housekeeping"), "expires_at" => time() - 60]);
        echo "SEEDED\n";' | grep -q SEEDED || { echo "could not seed the housekeeping check"; exit 1; }
}
hk_state() {
    $WPCLI --path="$WP_DIR" eval '
        global $wpdb;
        $fresh = Simple_MCP::last_housekeeping() > time() - 60 ? 1 : 0;
        $dead  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Simple_MCP_OAuth::table("codes") . " WHERE code_hash = %s", hash("sha256", "smcp-e2e-housekeeping")));
        echo "HK=fresh$fresh,dead$dead\n";' | grep -o 'HK=[a-z0-9,]*'
}
PING='{"jsonrpc":"2.0","id":1,"method":"ping"}'
hk_seed
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    --data "$PING" "http://127.0.0.1:$PORT/simple-mcp")
[ "$CODE" = 401 ] && [ "$(hk_state)" = "HK=fresh0,dead1" ] || { echo "FAIL an unauthenticated request ($CODE) touched the housekeeping: $(hk_state)"; exit 1; }
echo "ok   an unauthenticated request does not start the daily housekeeping"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -H "Authorization: Bearer $KEY" --data "$PING" "http://127.0.0.1:$PORT/simple-mcp")
[ "$CODE" = 200 ] && [ "$(hk_state)" = "HK=fresh1,dead0" ] || { echo "FAIL an agent's request ($CODE) did not clean up: $(hk_state)"; exit 1; }
echo "ok   an agent's request runs the overdue daily housekeeping after its response (no WP-Cron)"
# «Автоматичне прибирання» switched off in the settings (2.7.6): the same request cleans nothing up
opt '$o["housekeeping"] = false;'
hk_seed
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -H "Authorization: Bearer $KEY" --data "$PING" "http://127.0.0.1:$PORT/simple-mcp")
[ "$CODE" = 200 ] && [ "$(hk_state)" = "HK=fresh0,dead1" ] || { echo "FAIL with the housekeeping off an agent's request ($CODE) still cleaned up: $(hk_state)"; exit 1; }
echo "ok   switched off in the settings, an agent's request cleans nothing up"
opt 'unset($o["housekeeping"]);'

# The housekeeping, its claim, settings and paths (rolled back), and the settings page in tabs (writes the option, restores it)
$WPCLI --path="$WP_DIR" eval-file "$PLUGIN/tests/integration/housekeeping.php" | grep -v '^Deprecated'
$WPCLI --path="$WP_DIR" eval-file "$PLUGIN/tests/integration/admin-tabs.php" | grep -v '^Deprecated'

touch "$LOGS/php-errors.log"
if grep -h -E '/simple-mcp/' "$LOGS/php-errors.log" "$DEBUG_LOG"; then
    echo "FAIL PHP reported the errors above in Simple MCP files"
    exit 1
fi
echo "ok   no PHP errors, warnings or deprecations from Simple MCP files"
