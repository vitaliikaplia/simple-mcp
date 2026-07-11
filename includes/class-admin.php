<?php
/**
 * Проста сторінка налаштувань: показ ендпоінта, генерація ключа (у БД лише SHA-256),
 * тумблери, deny-list, IP-allowlist, тех-користувач, останні записи логу.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Admin {

    const FLASH_KEY = 'simple_mcp_flash_key';

    static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_simple_mcp_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_simple_mcp_genkey', [__CLASS__, 'handle_genkey']);
        add_filter('plugin_action_links_' . SIMPLE_MCP_BASENAME, [__CLASS__, 'action_links']);
    }

    static function menu() {
        add_options_page('Simple MCP', 'Simple MCP', 'manage_options', 'simple-mcp', [__CLASS__, 'render']);
    }

    /** «Налаштування» біля Деактивувати на сторінці Плагіни. */
    static function action_links($links) {
        $settings = '<a href="' . esc_url(admin_url('options-general.php?page=simple-mcp')) . '">' . esc_html__('Налаштування') . '</a>';
        array_unshift($links, $settings);
        return $links;
    }

    static function handle_genkey() {
        if (!current_user_can('manage_options')) wp_die('403');
        check_admin_referer('simple_mcp_genkey');
        $key = wp_generate_password(64, false, false); // 64 alnum
        update_option(Simple_MCP::KEY_OPTION, hash('sha256', $key));
        // Показуємо plaintext рівно один раз (короткий TTL — секрет не тримаємо в options довго)
        set_transient(self::FLASH_KEY, $key, 60);
        wp_safe_redirect(admin_url('options-general.php?page=simple-mcp&generated=1'));
        exit;
    }

    static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('403');
        check_admin_referer('simple_mcp_save');

        $in  = wp_unslash($_POST);
        $o   = Simple_MCP::options();

        $o['enabled']          = !empty($in['enabled']);
        $o['wp_cli_enabled']   = !empty($in['wp_cli_enabled']);
        $o['allow_server_ops'] = !empty($in['allow_server_ops']);
        $o['path']           = sanitize_title((string) ($in['path'] ?? 'simple-mcp')) ?: 'simple-mcp';
        $o['rate_limit']     = max(0, intval($in['rate_limit'] ?? 120));
        $o['user_id']        = intval($in['user_id'] ?? 0);
        $o['wp_bin']         = sanitize_text_field((string) ($in['wp_bin'] ?? ''));
        $o['php_bin']        = sanitize_text_field((string) ($in['php_bin'] ?? ''));
        $o['deny_list']      = self::lines_to_array((string) ($in['deny_list'] ?? ''));
        $o['ip_allowlist']   = self::lines_to_array((string) ($in['ip_allowlist'] ?? ''));

        // Групи інструментів. wploc: якщо системи багатомовності нема — зберігаємо намір (чекбокс disabled).
        $ml = Simple_MCP::multilingual_system();
        $o['modules'] = [
            'blocks'  => !empty($in['module_blocks']),
            'wploc'   => $ml ? !empty($in['module_wploc']) : (bool) ($o['modules']['wploc'] ?? true),
            'content' => !empty($in['module_content']),
        ];

        update_option(Simple_MCP::OPTION, $o);
        wp_safe_redirect(admin_url('options-general.php?page=simple-mcp&saved=1'));
        exit;
    }

    static function lines_to_array($text) {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $out = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l !== '') $out[] = $l;
        }
        return array_values(array_unique($out));
    }

    static function render() {
        if (!current_user_can('manage_options')) return;
        $o        = Simple_MCP::options();
        $endpoint = home_url('/' . trim((string) $o['path'], '/'));
        $has_key  = (bool) get_option(Simple_MCP::KEY_OPTION, '');
        $flash    = get_transient(self::FLASH_KEY);
        if ($flash) delete_transient(self::FLASH_KEY);
        ?>
        <div class="wrap">
            <h1>Simple MCP</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Збережено.</p></div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="notice notice-warning">
                    <p><strong>Новий ключ (показується один раз — скопіюй зараз):</strong></p>
                    <p><code style="font-size:14px;user-select:all;background:#fff;padding:6px 10px;display:inline-block;border:1px solid #ccc"><?php echo esc_html($flash); ?></code></p>
                </div>
            <?php endif; ?>

            <h2>Підключення</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Ендпоінт</th>
                    <td><code style="user-select:all"><?php echo esc_html($endpoint); ?></code>
                        <?php if (!is_ssl()): ?><p style="color:#b32d2e">⚠ Сайт не на HTTPS — ендпоінт вимагатиме HTTPS (або константу <code>SIMPLE_MCP_ALLOW_INSECURE</code> для локалки).</p><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Ключ доступу</th>
                    <td>
                        <?php echo $has_key ? '<span style="color:#008a20">● встановлено</span>' : '<span style="color:#b32d2e">● не встановлено</span>'; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:10px">
                            <?php wp_nonce_field('simple_mcp_genkey'); ?>
                            <input type="hidden" name="action" value="simple_mcp_genkey">
                            <button class="button" onclick="return confirm('Згенерувати новий ключ? Старий перестане працювати.')">Згенерувати новий ключ</button>
                        </form>
                        <p class="description">У БД зберігається лише SHA-256 хеш. Підключення в Claude Code:</p>
                        <p><code style="user-select:all">claude mcp add --transport http simple-mcp <?php echo esc_html($endpoint); ?> --header "Authorization: Bearer ВАШ_КЛЮЧ"</code></p>
                    </td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('simple_mcp_save'); ?>
                <input type="hidden" name="action" value="simple_mcp_save">

                <h2>Модулі інструментів</h2>
                <p class="description">Вимкнені групи повністю приховані від агента (не потрапляють у <code>tools/list</code> і не викликаються). Опис-інструкції при підключенні теж підлаштовуються.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Ядро контенту</th>
                        <td><label><input type="checkbox" checked disabled> <code>get/update_post</code>, <code>acf_*</code>, <code>upload_*</code></label>
                            <p class="description">Безпечна база — завжди увімкнено.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><code>wp_cli</code> (god-mode)</th>
                        <td><label><input type="checkbox" name="wp_cli_enabled" value="1" <?php checked($o['wp_cli_enabled']); ?>> дозволити прямий WP-CLI</label>
                            <p class="description">Вимкни → «typed-only» режим (максимально безпечно, лише типізовані інструменти). Керований RCE — тримай ключ і deny-list у секреті.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Серверні операції</th>
                        <td><label><input type="checkbox" name="allow_server_ops" value="1" <?php checked(!empty($o['allow_server_ops'])); ?>> дозволити правки <code>wp-config</code> + <code>plugin/theme install/update/delete</code></label>
                            <p class="description">Дефолт <strong>OFF</strong>. Це <em>середовищні</em> зміни, не контент: конфіг і набір плагінів законно різняться між локалкою й продом (тільки тема у git). Умикай свідомо на сайтах, де реально просиш агента таке робити. Руйнівне (видалення ACF/критичних плагінів, зміна security/DB) агент має <strong>перепитувати</strong>.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Блоки</th>
                        <td><label><input type="checkbox" name="module_blocks" value="1" <?php checked(!empty($o['modules']['blocks'])); ?>> <code>block_get/list_block_fields/block_update/insert/move/remove/replace</code></label>
                            <p class="description">Безпечне редагування ACF-полів усередині Гутенберг-блоків.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Мультимовність</th>
                        <td>
                            <?php $ml = Simple_MCP::multilingual_system(); ?>
                            <?php if ($ml): ?>
                                <label><input type="checkbox" name="module_wploc" value="1" <?php checked(!empty($o['modules']['wploc'])); ?>> <code>wploc_get_translations / link / create</code></label>
                                <span style="margin-left:8px;padding:2px 9px;border-radius:10px;background:#e6f4ea;color:#137333;font-weight:600;font-size:12px">● Виявлено: <?php echo esc_html($ml === 'wp-loc' ? 'WP-LOC' : 'WPML'); ?></span>
                            <?php else: ?>
                                <label style="color:#999"><input type="checkbox" disabled> <code>wploc_*</code></label>
                                <span style="margin-left:8px;padding:2px 9px;border-radius:10px;background:#fce8e6;color:#c5221f;font-weight:600;font-size:12px">● Не виявлено</span>
                                <p class="description">Немає активного плагіна багатомовності (WP-LOC / WPML) — група прихована автоматично.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Контент і дискавері</th>
                        <td><label><input type="checkbox" name="module_content" value="1" <?php checked(!empty($o['modules']['content'])); ?>> <code>create_post, render_post, safe_delete, describe_site</code></label></td>
                    </tr>
                </table>

                <h2>Налаштування</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Увімкнено</th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked($o['enabled']); ?>> сервер MCP активний</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Шлях ендпоінта</th>
                        <td><input type="text" name="path" value="<?php echo esc_attr($o['path']); ?>" class="regular-text">
                            <p class="description">Частина URL після домену. За замовчуванням <code>simple-mcp</code>.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Deny-list</th>
                        <td><textarea name="deny_list" rows="5" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $o['deny_list'])); ?></textarea>
                            <p class="description">По одному префіксу команди на рядок. Блокується, якщо команда починається з цього.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">IP-allowlist</th>
                        <td><textarea name="ip_allowlist" rows="3" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $o['ip_allowlist'])); ?></textarea>
                            <p class="description">Порожньо = будь-який IP. Підтримується IP або CIDR (напр. <code>203.0.113.0/24</code>). Довіряємо лише REMOTE_ADDR.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Ліміт запитів</th>
                        <td><input type="number" name="rate_limit" value="<?php echo esc_attr($o['rate_limit']); ?>" min="0" class="small-text"> / хв на IP <span class="description">(0 = без ліміту)</span></td>
                    </tr>
                    <tr>
                        <th scope="row">Тех-користувач</th>
                        <td><?php wp_dropdown_users(['name' => 'user_id', 'selected' => $o['user_id'], 'show_option_none' => '— перший адмін —', 'option_none_value' => 0]); ?>
                            <p class="description">Контекст для media/blocks/acf інструментів. Рекомендується окремий користувач з мінімальними правами.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Шлях до wp</th>
                        <td><input type="text" name="wp_bin" value="<?php echo esc_attr($o['wp_bin']); ?>" class="regular-text" placeholder="авто (напр. /opt/homebrew/bin/wp)">
                            <p class="description">Порожньо = автовизначення. Заповни, якщо wp не в PATH веб-сервера.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Шлях до php (CLI)</th>
                        <td><input type="text" name="php_bin" value="<?php echo esc_attr($o['php_bin']); ?>" class="regular-text" placeholder="авто (напр. /opt/homebrew/bin/php)">
                            <p class="description">CLI-php для wp-cli. Порожньо = автовизначення. Потрібно, бо «env php» у скрипті wp має резолвитись у середовищі веб-процесу.</p></td>
                    </tr>
                </table>
                <?php submit_button('Зберегти'); ?>
            </form>

            <h2>Останні виклики</h2>
            <table class="widefat striped">
                <thead><tr><th>Час</th><th>IP</th><th>Інструмент</th><th>Статус</th><th>Аргументи</th></tr></thead>
                <tbody>
                <?php foreach (Simple_MCP_Audit::recent(30) as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php echo esc_html($row->ip); ?></td>
                        <td><code><?php echo esc_html($row->tool); ?></code></td>
                        <td><?php echo $row->status === 'ok' ? '✅' : '⚠️'; ?> <?php echo esc_html($row->status); ?></td>
                        <td style="max-width:480px;overflow:hidden"><code style="font-size:11px"><?php echo esc_html(mb_substr((string) $row->args, 0, 200)); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
