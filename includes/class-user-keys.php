<?php
/**
 * Персональні MCP-ключі в профілі користувача.
 *
 * Секція «Simple MCP» на екрані профілю (свого — show_user_profile, чужого —
 * edit_user_profile для адмінів). Генерація/відкликання — через admin-post
 * nonce-посилання (сторінка профілю — одна велика <form>, вкладені форми не можна).
 * Plaintext ключа показується РІВНО ОДИН РАЗ через короткоживучий транзієнт,
 * разом із готовою командою підключення для Claude Code.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_User_Keys {

    const FLASH_PREFIX = 'simple_mcp_flash_';

    static function init() {
        add_action('show_user_profile', [__CLASS__, 'render']);   // власний профіль
        add_action('edit_user_profile', [__CLASS__, 'render']);   // чужий профіль (адмін)
        add_action('admin_post_simple_mcp_user_genkey', [__CLASS__, 'handle_genkey']);
        add_action('admin_post_simple_mcp_user_revoke', [__CLASS__, 'handle_revoke']);
    }

    /** Чи може поточний користувач керувати ключем $user (свій профіль або право edit_user). */
    static function can_manage($user_id) {
        if (get_current_user_id() === (int) $user_id) return true;
        return current_user_can('edit_user', $user_id);
    }

    /** Людські назви груп інструментів для зведення дозволів. */
    static function perm_labels() {
        return [
            'mcp'        => 'Ядро контенту (пости, ACF, медіа)',
            'blocks'     => 'Блоки',
            'wploc'      => 'Мультимовність',
            'content'    => 'Контент і дискавері',
            'wp_cli'     => 'wp_cli (god-mode)',
            'server_ops' => 'Серверні операції',
        ];
    }

    static function render($user) {
        if (!self::can_manage($user->ID)) return;

        $perms    = Simple_MCP::user_perms($user);
        $has_mcp  = !empty($perms['mcp']);
        $hash     = get_user_meta($user->ID, Simple_MCP::USER_KEY_META, true);
        $created  = (int) get_user_meta($user->ID, Simple_MCP::USER_KEY_CREATED, true);
        $endpoint = home_url('/' . trim((string) Simple_MCP::opt('path', 'simple-mcp'), '/'));
        $is_self  = get_current_user_id() === (int) $user->ID;

        $flash = get_transient(self::FLASH_PREFIX . $user->ID);
        if ($flash) delete_transient(self::FLASH_PREFIX . $user->ID);

        $gen_url = wp_nonce_url(
            admin_url('admin-post.php?action=simple_mcp_user_genkey&user_id=' . $user->ID),
            'simple_mcp_user_key_' . $user->ID
        );
        $revoke_url = wp_nonce_url(
            admin_url('admin-post.php?action=simple_mcp_user_revoke&user_id=' . $user->ID),
            'simple_mcp_user_key_' . $user->ID
        );
        ?>
        <h2 id="simple-mcp">Simple MCP — ключ доступу</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">MCP-доступ ролі</th>
                <td>
                    <?php if ($has_mcp): ?>
                        <?php
                        $labels  = self::perm_labels();
                        $granted = [];
                        foreach (Simple_MCP::PERMS as $p) {
                            if (!empty($perms[$p])) $granted[] = $labels[$p];
                        }
                        ?>
                        <span style="color:#008a20;font-weight:600">● дозволено</span>
                        <p class="description">Групи інструментів цього користувача (за роллю):
                            <?php echo esc_html(implode(' · ', $granted)); ?>.
                            Всередині груп діють нативні WordPress-права ролі.</p>
                    <?php else: ?>
                        <span style="color:#b32d2e;font-weight:600">● заборонено</span>
                        <p class="description">Роль цього користувача не має MCP-доступу.
                            <?php if (current_user_can('manage_options')): ?>
                                Увімкнути можна в <a href="<?php echo esc_url(admin_url('options-general.php?page=simple-mcp')); ?>">Налаштування → Simple MCP</a> (матриця «Права ролей»).
                            <?php else: ?>
                                Звернись до адміністратора сайту.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <?php if ($flash): ?>
                <tr>
                    <th scope="row">Новий ключ</th>
                    <td>
                        <div style="border:1px solid #dba617;background:#fcf9e8;border-radius:4px;padding:12px 16px;max-width:720px">
                            <p style="margin:0 0 6px"><strong>Ключ показується один раз — скопіюй зараз:</strong></p>
                            <p style="margin:0 0 10px"><code style="font-size:13px;user-select:all;background:#fff;padding:6px 10px;display:inline-block;border:1px solid #ccc;word-break:break-all"><?php echo esc_html($flash); ?></code></p>
                            <p style="margin:0 0 6px"><strong>Підключення в Claude Code:</strong></p>
                            <p style="margin:0"><code style="font-size:12px;user-select:all;background:#fff;padding:6px 10px;display:inline-block;border:1px solid #ccc;word-break:break-all">claude mcp add --transport http simple-mcp <?php echo esc_html($endpoint); ?> --header "Authorization: Bearer <?php echo esc_html($flash); ?>"</code></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>

            <tr>
                <th scope="row">Ключ</th>
                <td>
                    <?php if ($hash): ?>
                        <span style="color:#008a20">● встановлено</span>
                        <?php if ($created): ?>
                            <span class="description" style="margin-left:6px">згенеровано <?php echo esc_html(wp_date(get_option('date_format') . ' H:i', $created)); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#b32d2e">● не встановлено</span>
                    <?php endif; ?>

                    <?php if ($has_mcp): ?>
                        <p style="margin:10px 0 0">
                            <a class="button" href="<?php echo esc_url($gen_url); ?>"
                               onclick="return confirm('<?php echo $hash ? 'Згенерувати новий ключ? Старий перестане працювати.' : 'Згенерувати ключ?'; ?>')">
                                <?php echo $hash ? 'Згенерувати новий ключ' : 'Згенерувати ключ'; ?>
                            </a>
                            <?php if ($hash): ?>
                                <a class="button button-link-delete" style="margin-left:6px" href="<?php echo esc_url($revoke_url); ?>"
                                   onclick="return confirm('Відкликати ключ? Підключення цього користувача по MCP перестане працювати.')">Відкликати ключ</a>
                            <?php endif; ?>
                        </p>
                        <p class="description">У БД зберігається лише SHA-256 хеш. Ключ діє від імені
                            <?php echo $is_self ? 'тебе' : 'цього користувача'; ?> з правами ролі
                            (<code><?php echo esc_html(implode(', ', (array) $user->roles)); ?></code>).</p>
                        <p class="description">Ендпоінт: <code style="user-select:all"><?php echo esc_html($endpoint); ?></code></p>
                    <?php elseif ($hash): ?>
                        <p style="margin:10px 0 0">
                            <a class="button button-link-delete" href="<?php echo esc_url($revoke_url); ?>"
                               onclick="return confirm('Відкликати ключ?')">Відкликати ключ</a>
                        </p>
                        <p class="description" style="color:#b32d2e">Ключ існує, але роль більше не має MCP-доступу — автентифікація відхиляється. Радимо відкликати.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    static function handle_genkey() {
        $uid = intval($_GET['user_id'] ?? 0);
        check_admin_referer('simple_mcp_user_key_' . $uid);
        if (!$uid || !self::can_manage($uid)) wp_die('403');

        $user = get_user_by('id', $uid);
        if (!$user) wp_die('Користувача не знайдено');
        $perms = Simple_MCP::user_perms($user);
        if (empty($perms['mcp'])) {
            wp_die('Роль цього користувача не має MCP-доступу — спершу увімкни її в Налаштування → Simple MCP.');
        }

        $key = Simple_MCP_Auth::generate_key_for($uid);
        // Показуємо plaintext рівно один раз (короткий TTL — секрет не живе в БД довго)
        set_transient(self::FLASH_PREFIX . $uid, $key, 5 * MINUTE_IN_SECONDS);
        self::redirect_back($uid);
    }

    static function handle_revoke() {
        $uid = intval($_GET['user_id'] ?? 0);
        check_admin_referer('simple_mcp_user_key_' . $uid);
        if (!$uid || !self::can_manage($uid)) wp_die('403');

        Simple_MCP_Auth::revoke_key_for($uid);
        delete_transient(self::FLASH_PREFIX . $uid);
        self::redirect_back($uid);
    }

    static function redirect_back($uid) {
        $url = (get_current_user_id() === $uid)
            ? admin_url('profile.php')
            : admin_url('user-edit.php?user_id=' . $uid);
        wp_safe_redirect($url . '#simple-mcp');
        exit;
    }
}
