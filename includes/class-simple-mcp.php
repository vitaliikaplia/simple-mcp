<?php
/**
 * Ядро: налаштування, активація, спільні хелпери (shell для wp-cli, SSRF-захист).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP {

    const OPTION     = 'simple_mcp_options';
    const KEY_OPTION = 'simple_mcp_key_hash';

    /** Дефолтні налаштування */
    static function defaults() {
        return [
            'enabled'        => true,
            'wp_cli_enabled' => true,
            'path'           => 'simple-mcp',
            // Команди, заборонені за замовчуванням (перевіряється початок команди)
            'deny_list'      => ['db drop', 'db reset', 'db clean', 'db import', 'site empty', 'eval', 'eval-file', 'config edit'],
            'ip_allowlist'   => [],   // порожньо = дозволені всі IP
            'rate_limit'     => 120,  // запитів за хвилину на IP
            'user_id'        => 0,    // тех-користувач для типізованих інструментів (0 = перший адмін)
            'wp_bin'         => '',   // шлях до бінарника wp ('' = автовизначення)
            'php_bin'        => '',   // шлях до CLI-php ('' = автовизначення)
            // Групи інструментів, які можна вмикати/вимикати (ядро контенту завжди ON)
            'modules'        => ['blocks' => true, 'wploc' => true, 'content' => true],
        ];
    }

    static function options() {
        $o = get_option(self::OPTION, []);
        if (!is_array($o)) $o = [];
        return array_merge(self::defaults(), $o);
    }

    static function opt($key, $default = null) {
        $o = self::options();
        return array_key_exists($key, $o) ? $o[$key] : $default;
    }

    /** Чи увімкнена група інструментів. 'wp_cli' керується власним прапорцем. */
    static function module_on($key) {
        if ($key === 'wp_cli') return (bool) self::opt('wp_cli_enabled', true);
        $mods = self::opt('modules', []);
        return !is_array($mods) || !array_key_exists($key, $mods) || !empty($mods[$key]);
    }

    /** Активна система багатомовності: 'wp-loc' | 'wpml' | null. */
    static function multilingual_system() {
        if (class_exists('WP_LOC')) return 'wp-loc';
        if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) return 'wpml';
        return null;
    }

    static function init() {
        // Власний ендпоінт ловимо на найранішому етапі парсингу запиту —
        // ДО того, як спрацює REST API та його гейт «REST off для анонімів».
        add_filter('do_parse_request', ['Simple_MCP_Endpoint', 'maybe_handle'], 0, 2);
        add_action('simple_mcp_prune', ['Simple_MCP_Audit', 'prune']); // ретенція audit-логу
        new Simple_MCP_GitHub_Updater(); // авто-оновлення через GitHub (працює і в cron, не лише в адмінці)
        if (is_admin()) Simple_MCP_Admin::init();
    }

    static function activate() {
        Simple_MCP_Audit::create_table();
        if (get_option(self::OPTION) === false) add_option(self::OPTION, self::defaults());
        if (!wp_next_scheduled('simple_mcp_prune')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'simple_mcp_prune');
        }
    }

    static function deactivate() {
        // Нічого руйнівного: ключ, налаштування й лог лишаються. Повне чищення — в uninstall.php
        wp_clear_scheduled_hook('simple_mcp_prune');
    }

    /** Шлях до бінарника WP-CLI */
    static function wp_bin() {
        $opt = trim((string) self::opt('wp_bin', ''));
        if ($opt !== '') return $opt;
        if (defined('SIMPLE_MCP_WP_BIN') && SIMPLE_MCP_WP_BIN) return SIMPLE_MCP_WP_BIN;
        foreach (['/opt/homebrew/bin/wp', '/usr/local/bin/wp', '/usr/bin/wp'] as $p) {
            if (@is_executable($p)) return $p;
        }
        return 'wp'; // покладаємось на PATH
    }

    /**
     * Шлях до CLI-php. Потрібен, бо wp — це "env php"-скрипт, а PATH веб-процесу
     * (напр. PHP-FPM під Herd) зазвичай не містить php.
     */
    static function php_bin() {
        $opt = trim((string) self::opt('php_bin', ''));
        if ($opt !== '') return $opt;
        if (defined('SIMPLE_MCP_PHP_BIN') && SIMPLE_MCP_PHP_BIN) return SIMPLE_MCP_PHP_BIN;
        $candidates = ['/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php'];
        if (defined('PHP_BINDIR') && PHP_BINDIR) $candidates[] = rtrim(PHP_BINDIR, '/') . '/php';
        foreach ($candidates as $p) {
            if (@is_executable($p)) return $p;
        }
        return 'php';
    }

    /**
     * Середовище для запуску wp-cli: PATH з php, WP_CLI_PHP, HOME (кеш wp-cli), UTF-8 локаль.
     * proc_open замінює середовище повністю — тому задаємо все потрібне явно.
     */
    static function cli_env() {
        $php = self::php_bin();
        $path = dirname($php) . ':/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin';
        $home = sys_get_temp_dir() . '/simple-mcp-home';
        if (!file_exists($home)) @mkdir($home, 0700, true);
        return [
            'PATH'       => $path,
            'WP_CLI_PHP' => $php,
            'HOME'       => is_dir($home) ? $home : sys_get_temp_dir(),
            'LANG'       => 'en_US.UTF-8',
            'LC_ALL'     => 'en_US.UTF-8',
        ];
    }

    /**
     * Запуск shell-команди з таймаутом (для wp_cli-passthrough).
     * Повертає ['code'=>int, 'stdout'=>string, 'stderr'=>string].
     */
    static function run_shell($cmd, $cwd, $timeout = 120, $env = null) {
        if (!function_exists('proc_open')) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open вимкнено на цьому сервері'];
        }
        $desc  = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = proc_open($cmd, $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return ['code' => -1, 'stdout' => '', 'stderr' => 'не вдалося запустити процес'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start  = time();
        $status = [];
        while (true) {
            $status  = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (!$status['running']) break;
            if ((time() - $start) > $timeout) {
                proc_terminate($proc, 9);
                $stderr .= "\n[simple-mcp] таймаут після {$timeout}с";
                break;
            }
            usleep(50000);
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $close = proc_close($proc);
        $code  = (isset($status['exitcode']) && $status['exitcode'] >= 0) ? $status['exitcode'] : $close;
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * SSRF-захист для завантаження медіа з довільного URL.
     * Перевіряє схему (лише http/https) та всі A+AAAA-адреси на приватні/зарезервовані діапазони.
     * Це попередній гейт; фактичне завантаження додатково прикрите core wp_safe_remote_get.
     * Залишковий ризик — DNS-rebinding між перевіркою і завантаженням (тому для недовірених
     * джерел надавайте перевагу base64).
     */
    static function url_is_safe($url) {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) return false;
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $ips = array_merge($ips, $v4);
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
                }
            }
        }
        if (empty($ips)) return false; // не резолвиться — не ризикуємо

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // приватний/зарезервований діапазон (включно з 169.254/16)
            }
        }
        return true;
    }
}
