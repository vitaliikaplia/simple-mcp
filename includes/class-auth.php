<?php
/**
 * Автентифікація та захист ендпоінта.
 *
 * Персональні ключі: кожен ключ належить конкретному WordPress-користувачу
 * (формат smcp-{user_id}-{random}), у user meta зберігається лише SHA-256.
 * Успішна автентифікація = wp_set_current_user(власник ключа), тож усі інструменти
 * працюють під нативними правами цього користувача, а дозволені групи інструментів
 * визначає матриця ролей (Simple_MCP::user_perms).
 *
 * Захист: kill-switch, HTTPS-only, hash_equals, IP-allowlist, rate-limit по
 * користувачу (для невалідних спроб — штрафна пауза по IP).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Auth {

    /** Префікс персонального ключа (далі — id користувача і випадкова частина) */
    const KEY_PREFIX = 'smcp';

    /** Дозволи автентифікованого користувача поточного запиту (all-false поза MCP-запитом) */
    private static $perms = null;
    private static $user_id = 0;

    /**
     * Повертає true або WP_Error (з data['status']).
     * У разі успіху виставляє контекст користувача-власника ключа та його дозволи.
     */
    static function check() {
        if (defined('SIMPLE_MCP_DISABLE') && SIMPLE_MCP_DISABLE) {
            return new WP_Error('disabled', 'MCP вимкнено (kill-switch)', ['status' => 503]);
        }
        if (!Simple_MCP::opt('enabled', true)) {
            return new WP_Error('disabled', 'MCP вимкнено в налаштуваннях', ['status' => 503]);
        }
        if (!is_ssl() && !(defined('SIMPLE_MCP_ALLOW_INSECURE') && SIMPLE_MCP_ALLOW_INSECURE)) {
            return new WP_Error('insecure', 'Потрібен HTTPS', ['status' => 403]);
        }

        $provided = self::bearer();
        if ($provided === '') {
            return new WP_Error('noauth', 'Відсутній bearer-токен', ['status' => 401]);
        }

        // id користувача зашитий у токен (smcp-{id}-…) — O(1) резолв без сканування meta
        $uid = self::token_user_id($provided);
        if (!$uid) {
            self::note_failure();
            return new WP_Error('badauth', 'Невірний токен', ['status' => 403]);
        }

        $hash = get_user_meta($uid, Simple_MCP::USER_KEY_META, true);
        if (!$hash || !hash_equals((string) $hash, hash('sha256', $provided))) {
            self::note_failure();
            return new WP_Error('badauth', 'Невірний токен', ['status' => 403]);
        }

        $user = get_user_by('id', $uid);
        if (!$user) {
            return new WP_Error('nouser', 'Користувача ключа не існує', ['status' => 403]);
        }

        // Матриця ролей: чи має бодай одна роль користувача MCP-доступ
        $perms = Simple_MCP::user_perms($user);
        if (empty($perms['mcp'])) {
            return new WP_Error('norole', 'Роль користувача не має MCP-доступу (Налаштування → Simple MCP)', ['status' => 403]);
        }

        // IP-allowlist (якщо заданий)
        $allow = Simple_MCP::opt('ip_allowlist', []);
        if (!empty($allow)) {
            if (!self::ip_in_list(self::client_ip(), $allow)) {
                return new WP_Error('ip', 'IP не в дозволеному списку', ['status' => 403]);
            }
        }

        // Rate-limit по автентифікованому користувачу
        if (!self::rate_ok('u' . $uid)) {
            return new WP_Error('rate', 'Перевищено ліміт запитів', ['status' => 429]);
        }

        wp_set_current_user($uid);
        self::$user_id = $uid;
        self::$perms   = $perms;

        return true;
    }

    /** Витягуємо id користувача з токена формату smcp-{id}-{random}; 0 якщо формат чужий */
    static function token_user_id($token) {
        if (preg_match('/^' . self::KEY_PREFIX . '-(\d+)-[A-Za-z0-9]{32,}$/', $token, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** Згенерувати новий персональний ключ для користувача (повертає plaintext — показати один раз) */
    static function generate_key_for($user_id) {
        $key = self::KEY_PREFIX . '-' . (int) $user_id . '-' . wp_generate_password(64, false, false);
        update_user_meta($user_id, Simple_MCP::USER_KEY_META, hash('sha256', $key));
        update_user_meta($user_id, Simple_MCP::USER_KEY_CREATED, time());
        return $key;
    }

    /** Відкликати ключ користувача */
    static function revoke_key_for($user_id) {
        delete_user_meta($user_id, Simple_MCP::USER_KEY_META);
        delete_user_meta($user_id, Simple_MCP::USER_KEY_CREATED);
    }

    /** Дозволи поточного MCP-запиту. Поза автентифікованим запитом — все false. */
    static function current_perms() {
        return is_array(self::$perms) ? self::$perms : array_fill_keys(Simple_MCP::PERMS, false);
    }

    static function perm($key) {
        $p = self::current_perms();
        return !empty($p[$key]);
    }

    static function current_user_id() {
        return self::$user_id;
    }

    /** Витягуємо Bearer-токен з різних місць (FPM подекуди ховає Authorization) */
    static function bearer() {
        $h = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $h = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') { $h = $v; break; }
            }
        }
        if ($h && stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        // Запасний власний заголовок, якщо Authorization ріжеться проксі
        if (!empty($_SERVER['HTTP_X_SIMPLE_MCP_KEY'])) {
            return trim((string) $_SERVER['HTTP_X_SIMPLE_MCP_KEY']);
        }
        return '';
    }

    static function client_ip() {
        // За замовчуванням довіряємо лише REMOTE_ADDR (X-Forwarded-For можна підробити).
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    static function ip_in_list($ip, $list) {
        foreach ($list as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            if (strpos($entry, '/') !== false) {
                if (self::cidr_match($ip, $entry)) return true;
            } elseif ($ip === $entry) {
                return true;
            }
        }
        return false;
    }

    /** Проста перевірка CIDR для IPv4 */
    static function cidr_match($ip, $cidr) {
        list($subnet, $bits) = array_pad(explode('/', $cidr, 2), 2, '32');
        $ipL = ip2long($ip);
        $subL = ip2long($subnet);
        if ($ipL === false || $subL === false) return false;
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) return false;
        $mask = $bits === 0 ? 0 : (~((1 << (32 - $bits)) - 1)) & 0xFFFFFFFF;
        return ($ipL & $mask) === ($subL & $mask);
    }

    /** Rate-limit: $bucket — 'u{user_id}' для валідних запитів */
    static function rate_ok($bucket) {
        $limit = intval(Simple_MCP::opt('rate_limit', 120));
        if ($limit <= 0) return true;
        $key = 'simple_mcp_rl_' . md5($bucket . '|' . floor(time() / 60));
        $n = (int) get_transient($key);
        if ($n >= $limit) return false;
        set_transient($key, $n + 1, 65);
        return true;
    }

    /** Невелика штрафна пауза проти брутфорсу ключа (по IP — користувач ще невідомий) */
    static function note_failure() {
        $key = 'simple_mcp_fail_' . md5(self::client_ip());
        $n = (int) get_transient($key);
        set_transient($key, $n + 1, 300);
        if ($n > 3) usleep(500000); // 0.5с
    }
}
