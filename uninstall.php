<?php
/**
 * Повне чищення при видаленні плагіна: таблиця логу, опції, ключ, тимчасовий каталог.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$table = $wpdb->prefix . 'simple_mcp_log';
$wpdb->query("DROP TABLE IF EXISTS $table");

delete_option('simple_mcp_options');
delete_option('simple_mcp_key_hash'); // legacy: глобальний ключ версій до персональних
delete_option('simple_mcp_version');

// Персональні ключі користувачів (user meta)
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta}
     WHERE meta_key IN ('simple_mcp_key_hash', 'simple_mcp_key_created')"
);

// Знімаємо cron ретенції
wp_clear_scheduled_hook('simple_mcp_prune');

// Прибираємо власні транзієнти (ключ-флеш, частинкові завантаження) — звичайні й site-scope
// (site-transient кеш GitHub-апдейтера зберігається під _site_transient_simple_mcp_%).
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_simple\\_mcp\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_simple\\_mcp\\_%'
        OR option_name LIKE '\\_site\\_transient\\_simple\\_mcp\\_%'
        OR option_name LIKE '\\_site\\_transient\\_timeout\\_simple\\_mcp\\_%'"
);
delete_site_transient('simple_mcp_github_update_data'); // на випадок зовнішнього object-cache

// Тимчасовий каталог частинкових завантажень
$up  = wp_upload_dir();
$dir = trailingslashit($up['basedir']) . 'simple-mcp-tmp';
if (is_dir($dir)) {
    foreach ((array) glob($dir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($dir);
}
