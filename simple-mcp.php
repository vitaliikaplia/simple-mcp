<?php
/**
 * Plugin Name: Simple MCP
 * Description: Приватний MCP-сервер для WordPress: власний ендпоінт поза REST API, персональні ключі з дзеркаленням ролей/прав WordPress, WP-CLI для адмінів (deny-list) + безпечні типізовані інструменти для контенту, Gutenberg-блоків, ACF, медіа та мультимовності.
 * Version: 2.1.0
 * Author: Vitalii Kaplia
 * Author URI: https://kaplia.pro/
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 *
 * Приватний плагін — не для публікації. Використовується для власних проєктів.
 */

if (!defined('ABSPATH')) exit;

define('SIMPLE_MCP_VERSION', '2.1.0');
define('SIMPLE_MCP_FILE', __FILE__);
define('SIMPLE_MCP_DIR', plugin_dir_path(__FILE__));
define('SIMPLE_MCP_URL', plugin_dir_url(__FILE__));
define('SIMPLE_MCP_BASENAME', plugin_basename(__FILE__));
// Гілку авто-оновлення можна перевизначити в wp-config.php
if (!defined('SIMPLE_MCP_GITHUB_BRANCH')) define('SIMPLE_MCP_GITHUB_BRANCH', 'master');

require_once SIMPLE_MCP_DIR . 'includes/class-simple-mcp.php';
require_once SIMPLE_MCP_DIR . 'includes/class-auth.php';
require_once SIMPLE_MCP_DIR . 'includes/class-audit.php';
require_once SIMPLE_MCP_DIR . 'includes/class-tools.php';
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
