<?php
/**
 * Ядро: налаштування, активація, спільні хелпери (shell для wp-cli, SSRF-захист).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP {

    const OPTION           = 'simple_mcp_options';
    const VERSION_OPTION   = 'simple_mcp_version';      // для міграцій між версіями
    const USER_KEY_META    = 'simple_mcp_key_hash';     // SHA-256 персонального ключа (user meta)
    const USER_KEY_CREATED = 'simple_mcp_key_created';  // timestamp генерації ключа (user meta)

    /** Дозволи, якими керує матриця ролей (порядок = порядок рядків у таблиці) */
    const PERMS = ['mcp', 'blocks', 'wploc', 'content', 'wp_cli', 'server_ops'];

    /** Дефолтні налаштування */
    static function defaults() {
        return [
            'enabled'        => true,
            'path'           => 'simple-mcp',
            // Команди, заборонені за замовчуванням (перевіряється початок команди)
            'deny_list'      => ['db drop', 'db reset', 'db clean', 'db import', 'site empty', 'eval', 'eval-file'],
            'ip_allowlist'   => [],   // порожньо = дозволені всі IP
            'rate_limit'     => 120,  // запитів за хвилину на користувача
            'wp_bin'         => '',   // шлях до бінарника wp ('' = автовизначення)
            'php_bin'        => '',   // шлях до CLI-php ('' = автовизначення)
            // Матриця прав по ролях: role_slug => [perm => bool]. Ролі, яких тут нема,
            // отримують role_defaults() (дзеркальні дефолти для вбудованих ролей, off для кастомних).
            'roles'          => [],
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

    // ── Матриця прав по ролях ─────────────────────────────────────────────

    /**
     * Дзеркальні дефолти для ролі: administrator — усе (server ops off, як і раніше),
     * editor — контент+блоки+мультимовність, author — лише ядро (нативні caps обмежать
     * його своїми постами), решта (включно з кастомними ролями) — MCP вимкнено.
     */
    static function role_defaults($role) {
        $off = array_fill_keys(self::PERMS, false);
        switch ($role) {
            case 'administrator':
                return ['mcp' => true, 'blocks' => true, 'wploc' => true, 'content' => true, 'wp_cli' => true, 'server_ops' => false];
            case 'editor':
                return ['mcp' => true, 'blocks' => true, 'wploc' => true, 'content' => true] + $off;
            case 'author':
                return ['mcp' => true] + $off;
            default:
                return $off;
        }
    }

    /** Чи може роль взагалі отримати wp_cli/server ops (хард-лімит: manage_options). */
    static function role_can_godmode($role) {
        $r = get_role($role);
        return $r && $r->has_cap('manage_options');
    }

    /** Збережені значення матриці для ролі (або дефолти) — БЕЗ каскадних гейтів; для рендера адмінки. */
    static function role_perms_raw($role) {
        $saved = self::opt('roles', []);
        return (isset($saved[$role]) && is_array($saved[$role]))
            ? array_merge(array_fill_keys(self::PERMS, false), array_map('boolval', $saved[$role]))
            : self::role_defaults($role);
    }

    /**
     * Ефективні дозволи однієї ролі: збережене в матриці (або дефолт) + хард-лімити:
     * wp_cli/server_ops лише для ролей з manage_options; server_ops без wp_cli не діє.
     */
    static function role_perms($role) {
        $p = self::role_perms_raw($role);
        if (!self::role_can_godmode($role)) {
            $p['wp_cli'] = false;
            $p['server_ops'] = false;
        }
        if (empty($p['wp_cli'])) $p['server_ops'] = false;
        if (empty($p['mcp'])) return array_fill_keys(self::PERMS, false); // без MCP-доступу все off
        return $p;
    }

    /** Дозволи користувача = об'єднання (OR) дозволів усіх його ролей. */
    static function user_perms($user) {
        $user = is_numeric($user) ? get_user_by('id', (int) $user) : $user;
        $p = array_fill_keys(self::PERMS, false);
        if (!$user instanceof WP_User) return $p;
        foreach ((array) $user->roles as $role) {
            foreach (self::role_perms($role) as $k => $v) {
                if ($v) $p[$k] = true;
            }
        }
        return $p;
    }

    /** Активна система багатомовності: 'wp-loc' | 'wpml' | null. */
    static function multilingual_system() {
        if (class_exists('WP_LOC')) return 'wp-loc';
        if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) return 'wpml';
        return null;
    }

    static function init() {
        self::maybe_upgrade();
        // Власний ендпоінт ловимо на найранішому етапі парсингу запиту —
        // ДО того, як спрацює REST API та його гейт «REST off для анонімів».
        add_filter('do_parse_request', ['Simple_MCP_Endpoint', 'maybe_handle'], 0, 2);
        add_action('simple_mcp_prune', ['Simple_MCP_Audit', 'prune']); // ретенція audit-логу
        new Simple_MCP_GitHub_Updater(); // авто-оновлення через GitHub (працює і в cron, не лише в адмінці)
        if (is_admin()) {
            Simple_MCP_Admin::init();
            Simple_MCP_User_Keys::init();
        }
    }

    /**
     * Міграції між версіями (авто-оновлення з GitHub не проганяє activation hook).
     * Перехід на персональні ключі: глобальний ключ видаляється назавжди, старі
     * глобальні тумблери (wp_cli_enabled / allow_server_ops / modules / user_id)
     * переносяться в матрицю ролей як стартові значення для administrator/editor.
     */
    static function maybe_upgrade() {
        if (get_option(self::VERSION_OPTION) === SIMPLE_MCP_VERSION) return;

        $raw = get_option(self::OPTION, []);
        if (is_array($raw) && (isset($raw['wp_cli_enabled']) || isset($raw['modules']) || isset($raw['user_id']) || isset($raw['allow_server_ops']))) {
            $roles = [];
            foreach (array_keys(wp_roles()->roles) as $slug) {
                $roles[$slug] = self::role_defaults($slug);
            }
            // старі глобальні тумблери стають значеннями адмін-рядка
            if (isset($roles['administrator'])) {
                if (isset($raw['wp_cli_enabled']))   $roles['administrator']['wp_cli']     = !empty($raw['wp_cli_enabled']);
                if (isset($raw['allow_server_ops'])) $roles['administrator']['server_ops'] = !empty($raw['allow_server_ops']);
            }
            // старі module-тумблери застосовуємо до всіх ролей, де модуль був би увімкнений
            if (isset($raw['modules']) && is_array($raw['modules'])) {
                foreach ($roles as $slug => $p) {
                    foreach (['blocks', 'wploc', 'content'] as $m) {
                        if (array_key_exists($m, $raw['modules']) && empty($raw['modules'][$m])) {
                            $roles[$slug][$m] = false;
                        }
                    }
                }
            }
            unset($raw['wp_cli_enabled'], $raw['allow_server_ops'], $raw['modules'], $raw['user_id']);
            $raw['roles'] = $roles;
            update_option(self::OPTION, $raw);
        }

        delete_option('simple_mcp_key_hash'); // глобальний ключ більше не існує — тільки персональні
        Simple_MCP_Audit::create_table();     // dbDelta додає нові колонки (user_id, user_login)
        update_option(self::VERSION_OPTION, SIMPLE_MCP_VERSION);
    }

    static function activate() {
        if (get_option(self::OPTION) === false) add_option(self::OPTION, self::defaults());
        if (!wp_next_scheduled('simple_mcp_prune')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'simple_mcp_prune');
        }
        // Активація може бути й шляхом апгрейду (deactivate → заміна файлів → reactivate):
        // проганяємо міграцію (вона ж створює таблицю й штампує версію), а не штампуємо напряму,
        // інакше легасі-міграція назавжди пропуститься.
        self::maybe_upgrade();
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
