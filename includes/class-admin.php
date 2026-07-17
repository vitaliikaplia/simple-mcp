<?php
/**
 * Сторінка налаштувань: ендпоінт, матриця прав по ролях (дзеркало WordPress-ролей),
 * deny-list, IP-allowlist, rate-limit, шляхи до бінарників, останні записи логу.
 *
 * Ключі доступу тут НЕ генеруються — вони персональні й живуть у профілі користувача
 * (див. Simple_MCP_User_Keys). Тут лише рамка-нагадування з посиланням на профіль.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Admin {

    const PER_PAGE = 10;

    static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_simple_mcp_save', [__CLASS__, 'handle_save']);
        add_action('admin_post_simple_mcp_clear_log', [__CLASS__, 'handle_clear_log']);
        add_filter('plugin_action_links_' . SIMPLE_MCP_BASENAME, [__CLASS__, 'action_links']);
    }

    /** Валідація дати 'Y-m-d' (інакше — порожньо). */
    static function sanitize_date($v) {
        $v = sanitize_text_field((string) $v);
        if ($v === '') return '';
        $d = DateTime::createFromFormat('Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : '';
    }

    static function menu() {
        add_options_page('Simple MCP', 'Simple MCP', 'manage_options', 'simple-mcp', [__CLASS__, 'render']);
    }

    /** «Налаштування» біля Деактивувати на сторінці Плагіни — лише для адмінів. */
    static function action_links($links) {
        if (!current_user_can('manage_options')) return $links;
        $settings = '<a href="' . esc_url(admin_url('options-general.php?page=simple-mcp')) . '">' . esc_html__('Налаштування') . '</a>';
        array_unshift($links, $settings);
        return $links;
    }

    /** Рядки матриці прав: perm => [назва, підпис]. Порядок = порядок рядків. */
    static function perm_rows() {
        return [
            'mcp'        => ['MCP-доступ (ядро контенту)', 'get/update_post · acf_get/acf_update · upload_*. Вимкнено — роль взагалі не має доступу до MCP.'],
            'blocks'     => ['Блоки', 'block_get / list_block_fields / block_update / insert / move / remove / replace'],
            'wploc'      => ['Мультимовність', 'wploc_get_translations / link / create'],
            'content'    => ['Контент і дискавері', 'create_post / render_post / safe_delete / describe_site'],
            'wp_cli'     => ['wp_cli (god-mode)', 'Прямий WP-CLI. WP-CLI не перевіряє ролі (повний доступ до БД) — тому лише для ролей з manage_options.'],
            'server_ops' => ['Серверні операції', 'wp-config + plugin/theme install/update/delete через wp_cli. Діє лише разом з увімкненим wp_cli.'],
        ];
    }

    static function handle_save() {
        if (!current_user_can('manage_options')) wp_die('403');
        check_admin_referer('simple_mcp_save');

        $in  = wp_unslash($_POST);
        $o   = Simple_MCP::options();

        $o['enabled']      = !empty($in['enabled']);
        $o['path']         = sanitize_title((string) ($in['path'] ?? 'simple-mcp')) ?: 'simple-mcp';
        $o['rate_limit']   = max(0, intval($in['rate_limit'] ?? 120));
        $o['wp_bin']       = sanitize_text_field((string) ($in['wp_bin'] ?? ''));
        $o['php_bin']      = sanitize_text_field((string) ($in['php_bin'] ?? ''));
        $o['deny_list']    = self::lines_to_array((string) ($in['deny_list'] ?? ''));
        $o['ip_allowlist'] = self::lines_to_array((string) ($in['ip_allowlist'] ?? ''));

        // Матриця прав по ролях. Хард-лімит wp_cli/server_ops (лише manage_options-ролі)
        // застосовується й тут — вимкнені чекбокси не приходять у POST, але й підробити їх не можна.
        $ml       = Simple_MCP::multilingual_system();
        $in_roles = isset($in['roles']) && is_array($in['roles']) ? $in['roles'] : [];
        $roles    = [];
        foreach (array_keys(wp_roles()->roles) as $slug) {
            $row = isset($in_roles[$slug]) && is_array($in_roles[$slug]) ? $in_roles[$slug] : [];
            $p = [];
            foreach (Simple_MCP::PERMS as $perm) $p[$perm] = !empty($row[$perm]);
            // Немає системи багатомовності → чекбокс disabled і не постить: зберігаємо попередній намір
            if (!$ml) $p['wploc'] = (bool) Simple_MCP::role_perms_raw($slug)['wploc'];
            if (!Simple_MCP::role_can_godmode($slug)) { $p['wp_cli'] = false; $p['server_ops'] = false; }
            $roles[$slug] = $p;
        }
        $o['roles'] = $roles;

        update_option(Simple_MCP::OPTION, $o);
        wp_safe_redirect(admin_url('options-general.php?page=simple-mcp&saved=1'));
        exit;
    }

    static function handle_clear_log() {
        if (!current_user_can('manage_options')) wp_die('403');
        check_admin_referer('simple_mcp_clear_log');
        Simple_MCP_Audit::clear();
        wp_safe_redirect(admin_url('options-general.php?page=simple-mcp&log_cleared=1') . '#simple-mcp-log');
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
        $ml       = Simple_MCP::multilingual_system();
        $wp_roles = wp_roles()->roles; // slug => ['name' =>, 'capabilities' =>]
        ?>
        <div class="wrap">
            <h1>Simple MCP</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p>Збережено.</p></div>
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
                        <div style="border:1px solid #72aee6;background:#f0f6fc;border-radius:4px;padding:12px 16px;max-width:640px">
                            <p style="margin:0 0 8px"><strong>🔑 Ключі доступу — персональні.</strong>
                                Ключ керування WordPress по MCP прив'язаний до конкретного користувача і його ролі.
                                Він генерується в налаштуваннях профілю.</p>
                            <p style="margin:0 0 8px">
                                <a class="button button-primary" href="<?php echo esc_url(admin_url('profile.php') . '#simple-mcp'); ?>">Мій профіль → MCP-ключ</a>
                                <a class="button" style="margin-left:6px" href="<?php echo esc_url(admin_url('users.php')); ?>">Користувачі (ключі інших)</a>
                            </p>
                            <p class="description" style="margin:0">Адміністратор може згенерувати чи відкликати ключ будь-якого користувача на екрані редагування цього користувача. Що може ключ — визначає роль користувача і матриця нижче.</p>
                        </div>
                    </td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('simple_mcp_save'); ?>
                <input type="hidden" name="action" value="simple_mcp_save">

                <h2>Права ролей</h2>
                <p class="description" style="max-width:820px">
                    Кожен персональний ключ діє в межах ролі свого користувача: дозволені тут групи інструментів
                    визначають, що видно в <code>tools/list</code>, а всередині кожен виклик додатково перевіряє
                    <strong>нативні WordPress-права</strong> (автор не відредагує чужий пост, публікація вимагає
                    <code>publish_posts</code>, опції — <code>manage_options</code> тощо).
                    <code>wp_cli</code> і серверні операції доступні лише ролям з <code>manage_options</code> —
                    WP-CLI не перевіряє ролі, тож для інших це була б ескалація привілеїв.
                    <?php if ($ml): ?>
                        <span style="margin-left:4px;padding:2px 9px;border-radius:10px;background:#e6f4ea;color:#137333;font-weight:600;font-size:12px">● Мультимовність: <?php echo esc_html($ml === 'wp-loc' ? 'WP-LOC' : 'WPML'); ?></span>
                    <?php else: ?>
                        <span style="margin-left:4px;padding:2px 9px;border-radius:10px;background:#fce8e6;color:#c5221f;font-weight:600;font-size:12px">● Мультимовність не виявлена — група прихована для всіх</span>
                    <?php endif; ?>
                </p>
                <table class="widefat striped" style="max-width:980px">
                    <thead>
                        <tr>
                            <th style="width:34%">Дозвіл</th>
                            <?php foreach ($wp_roles as $slug => $info): ?>
                                <th style="text-align:center">
                                    <?php echo esc_html(translate_user_role($info['name'])); ?><br>
                                    <span style="font-weight:400;color:#777;font-size:11px"><code><?php echo esc_html($slug); ?></code></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (self::perm_rows() as $perm => [$label, $hint]): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($label); ?></strong>
                                <p class="description" style="margin:2px 0 0;font-size:12px"><?php echo esc_html($hint); ?></p>
                            </td>
                            <?php foreach ($wp_roles as $slug => $info):
                                $raw      = Simple_MCP::role_perms_raw($slug);
                                $godmode  = Simple_MCP::role_can_godmode($slug);
                                $disabled = false;
                                $title    = '';
                                if (in_array($perm, ['wp_cli', 'server_ops'], true) && !$godmode) {
                                    $disabled = true;
                                    $title    = 'Недоступно: роль не має manage_options (захист від ескалації привілеїв)';
                                }
                                if ($perm === 'wploc' && !$ml) {
                                    $disabled = true;
                                    $title    = 'Немає активної системи багатомовності (WP-LOC / WPML)';
                                }
                                $checked = !$disabled && !empty($raw[$perm]);
                                if ($perm === 'wploc' && !$ml) $checked = false; // приховано — не показуємо як активне
                            ?>
                                <td style="text-align:center" <?php echo $title ? 'title="' . esc_attr($title) . '"' : ''; ?>>
                                    <input type="checkbox"
                                           name="roles[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($perm); ?>]"
                                           value="1"
                                           <?php checked($checked); ?>
                                           <?php disabled($disabled); ?>>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">Кастомні ролі підтягуються автоматично. Нова роль за замовчуванням без MCP-доступу — увімкни свідомо. Якщо в користувача кілька ролей, його дозволи = об'єднання дозволів цих ролей.</p>

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
                            <p class="description">По одному префіксу команди на рядок. Блокується, якщо команда починається з цього. Стосується <code>wp_cli</code> (усіх ключів, включно з адмінськими).</p></td>
                    </tr>
                    <tr>
                        <th scope="row">IP-allowlist</th>
                        <td><textarea name="ip_allowlist" rows="3" class="large-text code"><?php echo esc_textarea(implode("\n", (array) $o['ip_allowlist'])); ?></textarea>
                            <p class="description">Порожньо = будь-який IP. Підтримується IP або CIDR (напр. <code>203.0.113.0/24</code>). Довіряємо лише REMOTE_ADDR.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Ліміт запитів</th>
                        <td><input type="number" name="rate_limit" value="<?php echo esc_attr($o['rate_limit']); ?>" min="0" class="small-text"> / хв на користувача <span class="description">(0 = без ліміту)</span></td>
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

            <?php
            // ── Журнал викликів: фільтр за датами + пагінація + очищення ──
            $from  = self::sanitize_date($_GET['log_from'] ?? '');
            $to    = self::sanitize_date($_GET['log_to'] ?? '');
            $per   = self::PER_PAGE;
            $total = Simple_MCP_Audit::count($from, $to);
            $pages = max(1, (int) ceil($total / $per));
            $paged = min($pages, max(1, intval($_GET['log_page'] ?? 1)));
            $offset = ($paged - 1) * $per;
            $rows   = Simple_MCP_Audit::query($from, $to, $per, $offset);
            $from_c = $total ? ($offset + 1) : 0;
            $to_c   = $offset + count($rows);
            ?>
            <h2 id="simple-mcp-log" style="margin-top:2.5em">Останні виклики</h2>

            <?php if (isset($_GET['log_cleared'])): ?>
                <div class="notice notice-success is-dismissible"><p>Журнал очищено.</p></div>
            <?php endif; ?>

            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;justify-content:space-between;margin:8px 0 12px">
                <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:0">
                    <input type="hidden" name="page" value="simple-mcp">
                    <label style="display:flex;flex-direction:column;font-size:12px;color:#555">Від
                        <input type="date" name="log_from" value="<?php echo esc_attr($from); ?>" max="<?php echo esc_attr($to ?: ''); ?>"></label>
                    <label style="display:flex;flex-direction:column;font-size:12px;color:#555">До
                        <input type="date" name="log_to" value="<?php echo esc_attr($to); ?>" min="<?php echo esc_attr($from ?: ''); ?>"></label>
                    <button class="button">Фільтрувати</button>
                    <?php if ($from !== '' || $to !== ''): ?>
                        <a class="button button-link" href="<?php echo esc_url(admin_url('options-general.php?page=simple-mcp') . '#simple-mcp-log'); ?>">Скинути</a>
                    <?php endif; ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0"
                      onsubmit="return confirm('Очистити весь журнал викликів? Дію не можна скасувати.')">
                    <?php wp_nonce_field('simple_mcp_clear_log'); ?>
                    <input type="hidden" name="action" value="simple_mcp_clear_log">
                    <button class="button button-link-delete"<?php disabled($total, 0); ?>>Очистити лог</button>
                </form>
            </div>

            <table class="widefat striped">
                <thead><tr><th style="width:150px">Час</th><th>Користувач</th><th>IP</th><th>Інструмент</th><th>Статус</th><th>Аргументи</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" style="color:#777;padding:14px"><?php echo ($from !== '' || $to !== '') ? 'За обраний період викликів немає.' : 'Викликів ще не було.'; ?></td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row->created_at); ?></td>
                        <td><?php
                            $login = isset($row->user_login) ? (string) $row->user_login : '';
                            $uid   = isset($row->user_id) ? (int) $row->user_id : 0;
                            $edit  = $uid ? get_edit_user_link($uid) : '';
                            if ($login !== '' && $edit) {
                                echo '<a href="' . esc_url($edit) . '">' . esc_html($login) . '</a>';
                            } elseif ($login !== '') {
                                // користувача видалено (або немає права редагувати) — просто текст
                                echo esc_html($login) . ' <span style="color:#999" title="користувача більше немає">⚑</span>';
                            } else {
                                echo '<span style="color:#999">—</span>';
                            }
                        ?></td>
                        <td><?php echo esc_html($row->ip); ?></td>
                        <td><code><?php echo esc_html($row->tool); ?></code></td>
                        <td><?php echo $row->status === 'ok' ? '✅' : '⚠️'; ?> <?php echo esc_html($row->status); ?></td>
                        <td style="max-width:420px;overflow:hidden"><code style="font-size:11px"><?php echo esc_html(mb_substr((string) $row->args, 0, 200)); ?></code></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <?php if ($total): ?>
                <div class="tablenav" style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
                    <span class="displaying-num"><?php echo esc_html(sprintf('%d–%d з %d', $from_c, $to_c, $total)); ?></span>
                    <?php if ($pages > 1):
                        $links = paginate_links([
                            'base'      => add_query_arg(['log_page' => '%#%'], admin_url('options-general.php?page=simple-mcp')) . '#simple-mcp-log',
                            'format'    => '',
                            'prev_text' => '‹',
                            'next_text' => '›',
                            'total'     => $pages,
                            'current'   => $paged,
                            'add_args'  => array_filter(['log_from' => $from, 'log_to' => $to]),
                        ]);
                        if ($links) echo '<span class="pagination-links">' . $links . '</span>';
                    endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
