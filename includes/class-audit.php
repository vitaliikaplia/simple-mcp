<?php
/**
 * Аудит-лог кожного виклику інструмента.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Audit {

    static function table() {
        global $wpdb;
        return $wpdb->prefix . 'simple_mcp_log';
    }

    static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t       = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_login VARCHAR(60) NOT NULL DEFAULT '',
            tool VARCHAR(100) NOT NULL DEFAULT '',
            args TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY user_id (user_id)
        ) $charset;";
        dbDelta($sql);
    }

    static function log($tool, $args, $status) {
        global $wpdb;
        $json = wp_json_encode($args, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > 2000) {
            $json = substr($json, 0, 2000) . '…'; // не роздуваємо лог великими base64
        }
        $user = wp_get_current_user();
        $wpdb->insert(self::table(), [
            'created_at' => current_time('mysql'),
            'ip'         => Simple_MCP_Auth::client_ip(),
            'user_id'    => $user ? (int) $user->ID : 0,
            'user_login' => $user ? substr((string) $user->user_login, 0, 60) : '',
            'tool'       => substr((string) $tool, 0, 100),
            'args'       => is_string($json) ? $json : '',
            'status'     => substr((string) $status, 0, 20),
        ]);
    }

    static function recent($limit = 50) {
        return self::query('', '', $limit, 0);
    }

    /**
     * WHERE-фрагмент за діапазоном дат (локальний час, як і created_at).
     * $from/$to — 'Y-m-d' або ''. Повертає [sql_without_leading_WHERE_or_empty, params].
     */
    static function where_clause($from, $to) {
        $conds  = [];
        $params = [];
        if ($from !== '') { $conds[] = 'created_at >= %s'; $params[] = $from . ' 00:00:00'; }
        if ($to   !== '') { $conds[] = 'created_at <= %s'; $params[] = $to . ' 23:59:59'; }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return [$where, $params];
    }

    /** Сторінка записів за діапазоном дат (найновіші зверху). */
    static function query($from = '', $to = '', $limit = 10, $offset = 0) {
        global $wpdb;
        $t = self::table();
        [$where, $params] = self::where_clause($from, $to);
        $params[] = max(1, (int) $limit);
        $params[] = max(0, (int) $offset);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t $where ORDER BY id DESC LIMIT %d OFFSET %d", $params));
    }

    /** Кількість записів за діапазоном дат (для пагінатора). */
    static function count($from = '', $to = '') {
        global $wpdb;
        $t = self::table();
        [$where, $params] = self::where_clause($from, $to);
        if ($params) {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t $where", $params));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    }

    /** Повне очищення логу. */
    static function clear() {
        global $wpdb;
        $t = self::table();
        $wpdb->query("TRUNCATE TABLE $t");
    }

    /** Ретенція: чистимо записи, старші за N днів (щоденний cron). */
    static function prune($days = 30) {
        global $wpdb;
        $t = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE created_at < %s", $cutoff));
    }
}
