<?php
/**
 * Автентифікація та захист ендпоінта:
 * kill-switch, HTTPS-only, звірка ключа (hash_equals по SHA-256), IP-allowlist, rate-limit.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Auth {

    /**
     * Повертає true або WP_Error (з data['status']).
     * У разі успіху виставляє контекст тех-користувача для типізованих інструментів.
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

        $hash = get_option(Simple_MCP::KEY_OPTION, '');
        if (!$hash) {
            return new WP_Error('nokey', 'Ключ сервера не налаштовано', ['status' => 503]);
        }

        $provided = self::bearer();
        if ($provided === '') {
            return new WP_Error('noauth', 'Відсутній bearer-токен', ['status' => 401]);
        }
        if (!hash_equals($hash, hash('sha256', $provided))) {
            self::note_failure();
            return new WP_Error('badauth', 'Невірний токен', ['status' => 403]);
        }

        // IP-allowlist (якщо заданий)
        $allow = Simple_MCP::opt('ip_allowlist', []);
        if (!empty($allow)) {
            if (!self::ip_in_list(self::client_ip(), $allow)) {
                return new WP_Error('ip', 'IP не в дозволеному списку', ['status' => 403]);
            }
        }

        // Rate-limit
        if (!self::rate_ok()) {
            return new WP_Error('rate', 'Перевищено ліміт запитів', ['status' => 429]);
        }

        // Контекст тех-користувача (для media/blocks/acf інструментів)
        $uid = intval(Simple_MCP::opt('user_id', 0));
        if (!$uid) {
            $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
            $uid = $admins ? intval($admins[0]) : 0;
        }
        if ($uid) wp_set_current_user($uid);

        return true;
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

    static function rate_ok() {
        $limit = intval(Simple_MCP::opt('rate_limit', 120));
        if ($limit <= 0) return true;
        $key = 'simple_mcp_rl_' . md5(self::client_ip() . '|' . floor(time() / 60));
        $n = (int) get_transient($key);
        if ($n >= $limit) return false;
        set_transient($key, $n + 1, 65);
        return true;
    }

    /** Невелика штрафна пауза проти брутфорсу ключа */
    static function note_failure() {
        $key = 'simple_mcp_fail_' . md5(self::client_ip());
        $n = (int) get_transient($key);
        set_transient($key, $n + 1, 300);
        if ($n > 3) usleep(500000); // 0.5с
    }
}
