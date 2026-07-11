<?php
/**
 * Повне чищення при видаленні плагіна: таблиця логу, опції, ключ, тимчасовий каталог.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$table = $wpdb->prefix . 'simple_mcp_log';
$wpdb->query("DROP TABLE IF EXISTS $table");

delete_option('simple_mcp_options');
delete_option('simple_mcp_key_hash');

// Знімаємо cron ретенції
wp_clear_scheduled_hook('simple_mcp_prune');

// Прибираємо власні транзієнти (ключ-флеш, частинкові завантаження)
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_simple\\_mcp\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_simple\\_mcp\\_%'"
);

// Тимчасовий каталог частинкових завантажень
$up  = wp_upload_dir();
$dir = trailingslashit($up['basedir']) . 'simple-mcp-tmp';
if (is_dir($dir)) {
    foreach ((array) glob($dir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}
