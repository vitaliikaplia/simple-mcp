<?php
/**
 * Повне чищення при видаленні плагіна — на КОЖНОМУ сайті мультисайту:
 * таблиця логу, усі опції simple_mcp_* (налаштування, версія, лічильники rate-limit і невдалих
 * спроб), транзієнти, cron ретенції, post meta (_simple_mcp_backup — резервні копії контенту,
 * _simple_mcp_detached), тимчасовий каталог частинкових завантажень. Глобально: user meta
 * ключів (іменовані + легасі), site-транзієнти мережі, WP-CLI HOME цього сайту.
 * Під час uninstall класи плагіна не завантажені — лише функції WordPress (Simple_MCP підключаємо
 * сам, щоб шлях WP-CLI HOME рахувався тією ж функцією, що й під час роботи).
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

/** Рекурсивно видалити каталог разом із dot-файлами (.htaccess); симлінки не розкриваємо. */
$simple_mcp_rmdir = function ($dir) use (&$simple_mcp_rmdir) {
    if (@is_link($dir)) { @unlink($dir); return; }
    if (!@is_dir($dir)) return; // @: кандидат поза open_basedir
    foreach ((array) @scandir($dir) as $f) {
        if (!is_string($f) || $f === '.' || $f === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p) && !is_link($p)) {
            $simple_mcp_rmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
};

/** Чищення поточного сайту (у мультисайті викликається після switch_to_blog) */
$simple_mcp_site = function () use ($wpdb, $simple_mcp_rmdir) {
    $table = $wpdb->prefix . 'simple_mcp_log';
    $wpdb->query("DROP TABLE IF EXISTS `$table`");

    // Опції (simple_mcp_options, simple_mcp_version, легасі simple_mcp_key_hash, лічильники
    // simple_mcp_rl_/af_/al_*) і транзієнти (флеш ключа, частинкові завантаження, кеші) —
    // звичайні й site-scope (у одиночному сайті site-транзієнти теж у wp_options).
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'simple\\_mcp\\_%'
            OR option_name LIKE '\\_transient\\_simple\\_mcp\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_simple\\_mcp\\_%'
            OR option_name LIKE '\\_site\\_transient\\_simple\\_mcp\\_%'
            OR option_name LIKE '\\_site\\_transient\\_timeout\\_simple\\_mcp\\_%'"
    );
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    delete_site_transient('simple_mcp_github_update_data'); // на випадок зовнішнього object-cache

    // Службові post meta: кільце резервних копій контенту і позначки від'єднаних перекладів
    delete_post_meta_by_key('_simple_mcp_backup');
    delete_post_meta_by_key('_simple_mcp_detached');

    // Cron ретенції
    wp_clear_scheduled_hook('simple_mcp_prune');
    wp_clear_scheduled_hook('simple_mcp_upgrade');

    // Тимчасовий каталог частинкових завантажень — повністю, разом з .htaccess/index.php
    $up = wp_upload_dir(null, false);
    if (!empty($up['basedir'])) {
        $simple_mcp_rmdir(trailingslashit($up['basedir']) . 'simple-mcp-tmp');
    }
};

if (is_multisite()) {
    foreach ((array) get_sites(['fields' => 'ids', 'number' => 0]) as $simple_mcp_blog) {
        switch_to_blog((int) $simple_mcp_blog);
        $simple_mcp_site();
        restore_current_blog();
    }
    // Site-транзієнти мережі (напр. кеш GitHub-апдейтера) живуть у sitemeta
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta}
         WHERE meta_key LIKE '\\_site\\_transient\\_simple\\_mcp\\_%'
            OR meta_key LIKE '\\_site\\_transient\\_timeout\\_simple\\_mcp\\_%'"
    );
} else {
    $simple_mcp_site();
}

// Персональні ключі користувачів (user meta — спільні для мережі): іменовані ключі
// (simple_mcp_keys), легасі-ключ (simple_mcp_key_hash / _created / _legacy_used)
$simple_mcp_users = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key LIKE 'simple\\_mcp\\_%'");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'simple\\_mcp\\_%'");
foreach ((array) $simple_mcp_users as $simple_mcp_uid) {
    wp_cache_delete((int) $simple_mcp_uid, 'user_meta');
}

// WP-CLI HOME цього сайту в усіх каталогах-кандидатах (формула одна — Simple_MCP::cli_home_dirs; файл
// класу без побічних ефектів; кандидат в uploads/simple-mcp-tmp уже прибрано вище) і спільний HOME версій до 2.5.0
if (!class_exists('Simple_MCP')) require_once __DIR__ . '/includes/class-simple-mcp.php';
foreach (Simple_MCP::cli_home_dirs() as $simple_mcp_home) {
    $simple_mcp_rmdir($simple_mcp_home);
}
$simple_mcp_rmdir(rtrim(sys_get_temp_dir(), '/\\') . '/simple-mcp-home');
