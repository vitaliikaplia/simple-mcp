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
            tool VARCHAR(100) NOT NULL DEFAULT '',
            args TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);
    }

    static function log($tool, $args, $status) {
        global $wpdb;
        $json = wp_json_encode($args, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > 2000) {
            $json = substr($json, 0, 2000) . '…'; // не роздуваємо лог великими base64
        }
        $wpdb->insert(self::table(), [
            'created_at' => current_time('mysql'),
            'ip'         => Simple_MCP_Auth::client_ip(),
            'tool'       => substr((string) $tool, 0, 100),
            'args'       => is_string($json) ? $json : '',
            'status'     => substr((string) $status, 0, 20),
        ]);
    }

    static function recent($limit = 50) {
        global $wpdb;
        $t = self::table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit));
    }

    /** Ретенція: чистимо записи, старші за N днів (щоденний cron). */
    static function prune($days = 30) {
        global $wpdb;
        $t = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE created_at < %s", $cutoff));
    }
}
