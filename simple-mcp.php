<?php
/**
 * Plugin Name: Simple MCP
 * Description: Приватний MCP-сервер для WordPress: власний ендпоінт поза REST API, персональні ключі з дзеркаленням ролей/прав WordPress, WP-CLI для адмінів (deny-list) + безпечні типізовані інструменти для контенту, Gutenberg-блоків, ACF, медіа та мультимовності.
 * Version: 2.5.3
 * Author: Vitalii Kaplia
 * Author URI: https://kaplia.pro/
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Tested up to: 7.0
 * Update URI: https://github.com/vitaliikaplia/simple-mcp
 * License: GPL-2.0-or-later
 *
 * Приватний плагін — не для публікації. Використовується для власних проєктів.
 */

if (!defined('ABSPATH')) exit;

// Мінімум — PHP 8.1. WordPress не активує й не оновлює плагін на старішому PHP, але якщо PHP сервера
// понизили вже після активації (або WP-CLI запущено старішим CLI-php), не валимо сайт фатальною
// помилкою: показуємо адмінам повідомлення й нічого не завантажуємо. Цей файл парситься й на PHP 7.
if (PHP_VERSION_ID < 80100) {
    $simple_mcp_old_php = function () {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p>Simple MCP потребує PHP 8.1 або новіший (зараз ',
            esc_html(PHP_VERSION), ') — плагін не завантажено. Онови PHP сайту.</p></div>';
    };
    add_action('admin_notices', $simple_mcp_old_php);
    add_action('network_admin_notices', $simple_mcp_old_php);
    unset($simple_mcp_old_php);
    return;
}

define('SIMPLE_MCP_VERSION', '2.5.3');
define('SIMPLE_MCP_FILE', __FILE__);
define('SIMPLE_MCP_DIR', plugin_dir_path(__FILE__));
define('SIMPLE_MCP_URL', plugin_dir_url(__FILE__));
define('SIMPLE_MCP_BASENAME', plugin_basename(__FILE__));
// Гілка dev-каналу авто-оновлення (SIMPLE_MCP_UPDATE_CHANNEL = 'branch'); можна перевизначити в wp-config.php
if (!defined('SIMPLE_MCP_GITHUB_BRANCH')) define('SIMPLE_MCP_GITHUB_BRANCH', 'master');

require_once SIMPLE_MCP_DIR . 'includes/class-simple-mcp.php';
require_once SIMPLE_MCP_DIR . 'includes/class-auth.php';
require_once SIMPLE_MCP_DIR . 'includes/class-audit.php';
require_once SIMPLE_MCP_DIR . 'includes/class-tools.php';
require_once SIMPLE_MCP_DIR . 'includes/class-simple-mcp-cli.php';
require_once SIMPLE_MCP_DIR . 'includes/class-endpoint.php';
require_once SIMPLE_MCP_DIR . 'includes/class-admin.php';
require_once SIMPLE_MCP_DIR . 'includes/class-user-keys.php';
require_once SIMPLE_MCP_DIR . 'includes/class-simple-mcp-github-updater.php';

// Tool modules (each exposes a static defs() merged into the tool registry)
foreach (glob(SIMPLE_MCP_DIR . 'includes/tools/*.php') as $__tool_module) {
    require_once $__tool_module;
}

register_activation_hook(__FILE__, ['Simple_MCP', 'activate']);
register_deactivation_hook(__FILE__, ['Simple_MCP', 'deactivate']);

add_action('plugins_loaded', ['Simple_MCP', 'init']);
