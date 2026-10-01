<?php
/**
 * Персональні MCP-ключі в профілі користувача.
 *
 * Секція «Simple MCP» на екрані профілю (свого — show_user_profile, чужого —
 * edit_user_profile для адмінів). Користувач може мати до Simple_MCP_Auth::MAX_KEYS
 * іменованих ключів (окремий для кожного пристрою/клієнта): список із датою створення,
 * автором, останнім використанням; кожен ключ відкликається окремо.
 * Генерація/відкликання — через admin-post nonce-посилання (сторінка профілю — одна велика
 * <form>, вкладені форми не можна; назву нового ключа JS додає до посилання).
 * Plaintext ключа показується РІВНО ОДИН РАЗ через короткоживучий транзієнт (окремий для
 * пари «хто дивиться — чий профіль»), разом із готовою командою підключення для Claude Code.
 * Генерація й відкликання пишуться в аудит-лог (хто, для кого, який ключ).
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

    /** Транзієнт одноразового показу: окремий для глядача й власника профілю */
    static function flash_key($viewer_id, $target_id) {
        return self::FLASH_PREFIX . (int) $viewer_id . '_' . (int) $target_id;
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

    /** Логін користувача за id (або '—' / '#id видалено') */
    static function user_label($uid) {
        $uid = (int) $uid;
        if (!$uid) return '—';
        $u = get_userdata($uid);
        return $u ? $u->user_login : '#' . $uid . ' (видалено)';
    }

    static function fmt_time($ts) {
        return $ts ? wp_date(get_option('date_format') . ' H:i', (int) $ts) : '';
    }

    static function render($user) {
        if (!self::can_manage($user->ID)) return;

        $perms    = Simple_MCP::user_perms($user);
        $has_mcp  = !empty($perms['mcp']);
        $keys     = Simple_MCP_Auth::keys_for($user->ID);
        $endpoint = home_url('/' . trim((string) Simple_MCP::opt('path', 'simple-mcp'), '/'));
        $viewer   = get_current_user_id();
        $is_self  = $viewer === (int) $user->ID;
        $max      = Simple_MCP_Auth::MAX_KEYS;

        $flash_key = self::flash_key($viewer, $user->ID);
        $flash     = get_transient($flash_key);
        if ($flash) delete_transient($flash_key);
        if (is_string($flash)) $flash = ['key' => $flash, 'name' => '']; // формат до 2.5.0

        $nonce   = wp_create_nonce('simple_mcp_user_key_' . $user->ID);
        $gen_url = add_query_arg(
            ['action' => 'simple_mcp_user_genkey', 'user_id' => $user->ID, '_wpnonce' => $nonce],
            admin_url('admin-post.php')
        );
        $revoke_url = function ($key_id) use ($user, $nonce) {
            return add_query_arg(
                ['action' => 'simple_mcp_user_revoke', 'user_id' => $user->ID, 'key_id' => $key_id, '_wpnonce' => $nonce],
                admin_url('admin-post.php')
            );
        };
        ?>
        <h2 id="simple-mcp">Simple MCP — ключі доступу</h2>
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

            <?php if (is_array($flash) && !empty($flash['key'])): ?>
                <tr>
                    <th scope="row">Новий ключ</th>
                    <td>
                        <div style="border:1px solid #dba617;background:#fcf9e8;border-radius:4px;padding:12px 16px;max-width:720px">
                            <p style="margin:0 0 6px"><strong>Ключ<?php echo $flash['name'] !== '' ? ' «' . esc_html($flash['name']) . '»' : ''; ?> показується один раз — скопіюй зараз:</strong></p>
                            <p style="margin:0 0 10px"><code style="font-size:13px;user-select:all;background:#fff;padding:6px 10px;display:inline-block;border:1px solid #ccc;word-break:break-all"><?php echo esc_html($flash['key']); ?></code></p>
                            <p style="margin:0 0 6px"><strong>Підключення в Claude Code:</strong></p>
                            <p style="margin:0"><code style="font-size:12px;user-select:all;background:#fff;padding:6px 10px;display:inline-block;border:1px solid #ccc;word-break:break-all">claude mcp add --transport http simple-mcp <?php echo esc_html($endpoint); ?> --header "Authorization: Bearer <?php echo esc_html($flash['key']); ?>"</code></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>

            <tr>
                <th scope="row">Ключі</th>
                <td>
                    <?php if ($keys): ?>
                        <table class="widefat striped" style="max-width:900px">
                            <thead><tr><th>Назва</th><th>Створено</th><th>Ким</th><th>Останнє використання</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($keys as $k): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($k['name']); ?></strong>
                                        <br><span style="color:#777;font-size:11px">id <code><?php echo esc_html($k['id']); ?></code></span></td>
                                    <td><?php echo esc_html(self::fmt_time($k['created']) ?: '—'); ?></td>
                                    <td><?php
                                        if ($k['legacy']) {
                                            echo '<span style="color:#999">—</span>';
                                        } elseif ((int) $k['created_by'] === (int) $user->ID) {
                                            echo 'власник';
                                        } else {
                                            echo esc_html(self::user_label($k['created_by']));
                                        }
                                    ?></td>
                                    <td><?php if ($k['last_used']): ?>
                                            <?php echo esc_html(self::fmt_time($k['last_used'])); ?>
                                            <?php if ($k['last_ip'] !== ''): ?><br><span style="color:#777;font-size:11px"><?php echo esc_html($k['last_ip']); ?></span><?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#999">ще не використовувався<?php echo $k['legacy'] ? ' (з 2.5.0)' : ''; ?></span>
                                        <?php endif; ?></td>
                                    <td style="text-align:right">
                                        <a class="button button-link-delete" href="<?php echo esc_url($revoke_url($k['id'])); ?>"
                                           onclick="return confirm(<?php echo esc_attr(wp_json_encode('Відкликати ключ «' . $k['name'] . '»? Клієнти, що ним підключені, перестануть працювати.')); ?>)">Відкликати</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (count($keys) > 1): ?>
                            <p style="margin:8px 0 0"><a class="button-link-delete" href="<?php echo esc_url($revoke_url('')); ?>"
                                  onclick="return confirm('Відкликати ВСІ ключі цього користувача?')">Відкликати всі</a></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#b32d2e">● жодного ключа</span>
                    <?php endif; ?>

                    <?php if ($has_mcp): ?>
                        <?php if (count($keys) < $max): ?>
                            <p style="margin:12px 0 0;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                                <label for="simple-mcp-key-name" class="screen-reader-text">Назва нового ключа</label>
                                <input type="text" id="simple-mcp-key-name" maxlength="60" class="regular-text" placeholder="Назва, напр. Claude Code — ноутбук" autocomplete="off"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('simple-mcp-genkey').click();}">
                                <a class="button" id="simple-mcp-genkey" href="<?php echo esc_url($gen_url); ?>" data-url="<?php echo esc_attr($gen_url); ?>"
                                   onclick="var n=document.getElementById('simple-mcp-key-name');this.href=this.getAttribute('data-url')+'&amp;name='+encodeURIComponent(n?n.value:'');return true;">Згенерувати ключ</a>
                            </p>
                            <p class="description">Новий ключ не скасовує наявні. До <?php echo (int) $max; ?> ключів — окремий для кожного пристрою чи клієнта, щоб відкликати їх незалежно.</p>
                        <?php else: ?>
                            <p class="description" style="margin-top:10px">Досягнуто ліміту в <?php echo (int) $max; ?> ключів — відклич непотрібний, щоб створити новий.</p>
                        <?php endif; ?>
                        <p class="description">У БД зберігається лише SHA-256 хеш. Ключ діє від імені
                            <?php echo $is_self ? 'тебе' : 'цього користувача'; ?> з правами ролі
                            (<code><?php echo esc_html(implode(', ', (array) $user->roles)); ?></code>).</p>
                        <p class="description">Ендпоінт: <code style="user-select:all"><?php echo esc_html($endpoint); ?></code></p>
                    <?php elseif ($keys): ?>
                        <p class="description" style="color:#b32d2e">Ключі існують, але роль більше не має MCP-доступу — автентифікація відхиляється. Радимо відкликати.</p>
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

        $name = sanitize_text_field(wp_unslash((string) ($_GET['name'] ?? '')));
        $res  = Simple_MCP_Auth::create_key($uid, $name, get_current_user_id());
        if (is_wp_error($res)) {
            wp_die(esc_html($res->get_error_message()), '', ['back_link' => true]);
        }
        // Показуємо plaintext рівно один раз (короткий TTL — секрет не живе в БД довго)
        set_transient(self::flash_key(get_current_user_id(), $uid), ['key' => $res['key'], 'name' => $res['name']], 5 * MINUTE_IN_SECONDS);
        self::audit('key_generate', $user, [['id' => $res['id'], 'name' => $res['name']]]);
        self::redirect_back($uid);
    }

    static function handle_revoke() {
        $uid = intval($_GET['user_id'] ?? 0);
        check_admin_referer('simple_mcp_user_key_' . $uid);
        if (!$uid || !self::can_manage($uid)) wp_die('403');

        $key_id  = sanitize_key(wp_unslash((string) ($_GET['key_id'] ?? '')));
        $removed = Simple_MCP_Auth::revoke_key_for($uid, $key_id === '' ? null : $key_id);
        delete_transient(self::flash_key(get_current_user_id(), $uid));
        $user = get_user_by('id', $uid);
        if ($removed && $user) self::audit('key_revoke', $user, $removed);
        self::redirect_back($uid);
    }

    /** Аудит дій із ключами: хто (поточний користувач — рядок логу), для кого, які ключі */
    static function audit($action, $target, array $keys) {
        $actor = wp_get_current_user();
        foreach ($keys as $k) {
            Simple_MCP_Audit::log($action, [
                'for_user_id' => (int) $target->ID,
                'for_user'    => $target->user_login,
                'key_id'      => (string) $k['id'],
                'name'        => (string) $k['name'],
            ], 'ok', [
                'key_id' => (string) $k['id'],
                'detail' => ($action === 'key_generate' ? 'Ключ створив ' : 'Ключ відкликав ')
                    . ($actor->ID ? $actor->user_login : '?')
                    . ((int) $actor->ID === (int) $target->ID ? ' (власник)' : ' для ' . $target->user_login),
            ]);
        }
    }

    static function redirect_back($uid) {
        $url = (get_current_user_id() === $uid)
            ? admin_url('profile.php')
            : admin_url('user-edit.php?user_id=' . $uid);
        wp_safe_redirect($url . '#simple-mcp');
        exit;
    }
}
