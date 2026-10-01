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
            'log_retention_days' => 30, // скільки днів зберігати audit-лог (0 = не чистити)
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

    /** Server ops заблоковані для всіх ролей, коли wp-config забороняє зміни файлів (DISALLOW_FILE_MODS). */
    static function server_ops_locked() {
        return defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS;
    }

    /**
     * Ефективні дозволи однієї ролі: збережене в матриці (або дефолт) + хард-лімити:
     * wp_cli/server_ops лише для ролей з manage_options; server_ops без wp_cli не діє;
     * server_ops off при DISALLOW_FILE_MODS. Мультисайтне обмеження (лише супер-адміни) —
     * у user_perms(), бо супер-адмін — це користувач, а не роль.
     */
    static function role_perms($role) {
        $p = self::role_perms_raw($role);
        if (!self::role_can_godmode($role)) {
            $p['wp_cli'] = false;
            $p['server_ops'] = false;
        }
        if (empty($p['wp_cli']) || self::server_ops_locked()) $p['server_ops'] = false;
        if (empty($p['mcp'])) return array_fill_keys(self::PERMS, false); // без MCP-доступу все off
        return $p;
    }

    /**
     * Дозволи користувача = об'єднання (OR) дозволів усіх його ролей.
     * Мультисайт: wp_cli/server ops діють на всю мережу (спільні таблиці, --url будь-якого сайту),
     * тож лише для супер-адмінів — адміністратор окремого сайту їх не отримує.
     */
    static function user_perms($user) {
        $user = is_numeric($user) ? get_user_by('id', (int) $user) : $user;
        $p = array_fill_keys(self::PERMS, false);
        if (!$user instanceof WP_User) return $p;
        foreach ((array) $user->roles as $role) {
            foreach (self::role_perms($role) as $k => $v) {
                if ($v) $p[$k] = true;
            }
        }
        if (is_multisite() && !is_super_admin($user->ID)) {
            $p['wp_cli'] = false;
            $p['server_ops'] = false;
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
        add_action('simple_mcp_upgrade', [__CLASS__, 'maybe_upgrade'], 10, 0); // відкладена міграція схеми (див. maybe_upgrade)
        self::maybe_upgrade();
        // Власний ендпоінт ловимо на найранішому етапі парсингу запиту —
        // ДО того, як спрацює REST API та його гейт «REST off для анонімів».
        add_filter('do_parse_request', ['Simple_MCP_Endpoint', 'maybe_handle'], 0, 2);
        // Щоденне чищення. accepted_args = 0: do_action() без аргументів передає '' першим аргументом,
        // а prune('') упав би з TypeError на арифметиці ('' * DAY_IN_SECONDS).
        add_action('simple_mcp_prune', ['Simple_MCP_Audit', 'prune'], 10, 0);             // ретенція audit-логу
        if (method_exists('Simple_MCP_Tools', 'gc_uploads')) {
            add_action('simple_mcp_prune', ['Simple_MCP_Tools', 'gc_uploads'], 10, 0);   // покинуті частинкові завантаження
        }
        // Подія могла зникнути (чищення cron, міграція, оновлення без реактивації) — відновлюємо.
        if (!wp_installing() && !wp_next_scheduled('simple_mcp_prune')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'simple_mcp_prune');
        }
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
     * Зміна схеми аудит-таблиці (ALTER; на MySQL < 8.0 / MariaDB < 10.3 — перебудова таблиці, яку
     * до 2.5.0 cron ніколи не чистив) і штамп версії — лише в адмінці, cron чи WP-CLI і лише одним
     * запитом за раз (GET_LOCK): фронтенд-запит лише ставить одноразову cron-подію, а
     * Simple_MCP_Audit::log() тим часом працює і зі старою схемою.
     */
    static function maybe_upgrade() {
        global $wpdb;
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

        if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
            if (!wp_installing() && !wp_next_scheduled('simple_mcp_upgrade')) {
                wp_schedule_single_event(time(), 'simple_mcp_upgrade');
            }
            return;
        }
        // NULL — іменовані блокування недоступні (напр. Galera): тоді без нього
        $lock = 'simple_mcp_upgrade_' . substr(md5($wpdb->prefix . ABSPATH), 0, 16);
        $got  = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ($got !== null && (string) $got !== '1') return; // міграцію саме виконує інший запит
        try {
            // інший запит міг завершити її, поки ми чекали (читаємо повз кеш опцій)
            $stamped = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::VERSION_OPTION));
            if ($stamped === SIMPLE_MCP_VERSION) return;
            Simple_MCP_Audit::create_table(); // нові колонки (2.5.0: key_id, duration_ms, detail) — одним ALTER
            update_option(self::VERSION_OPTION, SIMPLE_MCP_VERSION);
        } finally {
            if ($got !== null) $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
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
        wp_clear_scheduled_hook('simple_mcp_upgrade');
    }

    // ── WP-CLI: бінарники, HOME, середовище, запуск ───────────────────────

    /**
     * Чи придатний шлях з НАЛАШТУВАНЬ (БД) для бінарника $kind ('wp' | 'php'): абсолютний, без
     * керівних символів, ім'я файлу саме php/wp (php, php8.3, php83, php-8.3; wp, wp-cli, wp-cli.phar),
     * існує як файл; php — виконуваний, wp — читабельний (phar запускаємо через php).
     * Так значення з БД ніколи не запустить довільний бінарник (/bin/sh тощо).
     */
    static function bin_path_valid($kind, $path) {
        $path = (string) $path;
        if ($path === '' || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $path)) return false;
        if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $path)) return false;
        $name = basename(str_replace('\\', '/', $path));
        if ($kind === 'php') {
            if (!preg_match('/^php(-?\d+(\.\d+)*)?(-cli)?(\.exe)?$/i', $name)) return false;
            return @is_file($path) && @is_executable($path);
        }
        if ($kind === 'wp') {
            if (!preg_match('/^wp(-cli)?(\.phar|\.bat)?$/i', $name)) return false;
            return @is_file($path) && @is_readable($path);
        }
        return false;
    }

    /** Звідки береться бінарник $kind: константа wp-config > валідний шлях з налаштувань > автовизначення. */
    static function bin_source($kind) {
        $const = $kind === 'php' ? 'SIMPLE_MCP_PHP_BIN' : 'SIMPLE_MCP_WP_BIN';
        if (defined($const) && constant($const)) return 'constant';
        $opt = trim((string) self::opt($kind === 'php' ? 'php_bin' : 'wp_bin', ''));
        if ($opt !== '' && self::bin_path_valid($kind, $opt)) return 'option';
        return 'auto'; // порожньо або невалідний шлях з БД (ігнорується)
    }

    /** Шлях до WP-CLI ('wp' без шляху — крайній випадок, покладаємось на PATH). */
    static function wp_bin() {
        switch (self::bin_source('wp')) {
            case 'constant': return (string) SIMPLE_MCP_WP_BIN;
            case 'option':   return trim((string) self::opt('wp_bin', ''));
        }
        $php  = self::php_bin();
        $dirs = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin'];
        if (strpos($php, '/') !== false) $dirs[] = dirname($php);
        if (PHP_BINARY) $dirs[] = dirname(PHP_BINARY);
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if ($d !== '') $dirs[] = $d;
        }
        foreach (array_unique($dirs) as $d) {
            foreach (['wp', 'wp-cli', 'wp-cli.phar'] as $n) {
                $p = rtrim($d, '/') . '/' . $n;
                if (@is_file($p) && @is_readable($p)) return $p;
            }
        }
        return 'wp';
    }

    /**
     * Шлях до CLI-php для wp-cli. Константа > валідний шлях з налаштувань > автовизначення:
     * php тієї ж major.minor, що й поточний PHP (тема/composer можуть вимагати саме її), перевірений
     * запуском (SAPI cli + версія); результат кешується в транзієнті. Немає збігу — перший робочий
     * CLI-php будь-якої версії, інакше 'php' з PATH.
     */
    static function php_bin() {
        switch (self::bin_source('php')) {
            case 'constant': return (string) SIMPLE_MCP_PHP_BIN;
            case 'option':   return trim((string) self::opt('php_bin', ''));
        }
        $found = self::php_detect();
        return $found['path'];
    }

    /** Автовизначення CLI-php (кеш: транзієнт simple_mcp_php_bin; ключ — PHP_BINARY + PHP_VERSION). */
    static function php_detect() {
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $sig  = md5(PHP_BINARY . '|' . PHP_VERSION);
        $c    = get_transient('simple_mcp_php_bin');
        $stat = !ini_get('open_basedir'); // під open_basedir is_file() бреше — тоді лише пробний запуск
        if (is_array($c) && ($c['sig'] ?? '') === $sig && !empty($c['path'])
            && (!$stat || strpos($c['path'], '/') === false || @is_executable($c['path']))) {
            return $c;
        }
        $fallback = null;
        $found    = null;
        foreach (self::php_candidates() as $p) {
            if ($stat && !(@is_file($p) && @is_executable($p))) continue;
            $v = self::php_probe($p);
            if ($v === null) continue;
            if ($v === $want) { $found = ['path' => $p, 'version' => $v, 'matched' => true]; break; }
            if ($fallback === null) $fallback = ['path' => $p, 'version' => $v, 'matched' => false];
        }
        $res = $found ?: ($fallback ?: ['path' => 'php', 'version' => null, 'matched' => false]);
        $res['sig'] = $sig;
        set_transient('simple_mcp_php_bin', $res, $found ? DAY_IN_SECONDS : HOUR_IN_SECONDS);
        return $res;
    }

    /**
     * Кандидати CLI-php, від найімовірнішого збігу версії: сам PHP_BINARY (якщо це CLI), його
     * «двійник» без -fpm/-cgi (Herd: php85-fpm → php85; Debian: sbin/php-fpm8.3 → bin/php8.3),
     * PHP_BINDIR, Homebrew/системні/панельні (cPanel, Plesk, CloudLinux) шляхи, PATH веб-процесу.
     */
    static function php_candidates() {
        $mm    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $mmc   = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $names = ['php' . $mm, 'php' . $mmc, 'php-' . $mm, 'php'];
        $out   = [];
        $dirs  = [];
        if (PHP_BINARY) {
            if (PHP_SAPI === 'cli') $out[] = PHP_BINARY;
            $dir  = dirname(PHP_BINARY);
            $twin = preg_replace('/-?(fpm|cgi)/i', '', basename(PHP_BINARY));
            if ($twin !== '' && $twin !== basename(PHP_BINARY)) {
                $out[] = $dir . '/' . $twin;
                $out[] = dirname($dir) . '/bin/' . $twin;
            }
            $dirs[] = $dir;
            $dirs[] = dirname($dir) . '/bin';
        }
        if (PHP_BINDIR) $dirs[] = PHP_BINDIR;
        $dirs = array_merge($dirs, [
            '/opt/homebrew/opt/php@' . $mm . '/bin', '/usr/local/opt/php@' . $mm . '/bin',
            '/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin',
            '/opt/cpanel/ea-php' . $mmc . '/root/usr/bin', '/opt/plesk/php/' . $mm . '/bin',
            '/opt/alt/php' . $mmc . '/usr/bin', '/usr/local/php' . $mmc . '/bin',
        ]);
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if ($d !== '') $dirs[] = $d;
        }
        foreach (array_unique($dirs) as $d) {
            foreach ($names as $n) $out[] = rtrim($d, '/') . '/' . $n;
        }
        return array_values(array_unique($out));
    }

    /** Пробний запуск: "major.minor" для CLI-php або null (не запускається / не cli SAPI). */
    static function php_probe($php) {
        $r = self::run_shell(
            [$php, '-d', 'display_errors=stderr', '-r', 'echo PHP_SAPI, " ", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;'],
            null, 10, ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C'], 4096
        );
        if ($r['code'] !== 0 || !preg_match('/^cli (\d+\.\d+)$/', trim($r['stdout']), $m)) return null;
        return $m[1];
    }

    /** Діагностика для сторінки налаштувань: шляхи, джерела, версія php (зондується лише для auto). */
    static function cli_info() {
        $info = [
            'wp'          => self::wp_bin(),
            'wp_source'   => self::bin_source('wp'),
            'php'         => self::php_bin(),
            'php_source'  => self::bin_source('php'),
            'php_version' => null,
            'php_matches' => null,
            'home'        => self::cli_home_dir(),
        ];
        if ($info['php_source'] === 'auto') {
            $d = self::php_detect();
            $info['php_version'] = $d['version'];
            $info['php_matches'] = (bool) $d['matched'];
        }
        return $info;
    }

    /**
     * Кандидати HOME для WP-CLI цього сайту (не спільний між сайтами): у системному temp, у temp
     * WordPress (get_temp_dir — інший, коли системний недоступний: open_basedir, sys_temp_dir без
     * прав) і в uploads/simple-mcp-tmp (закритий від веб-доступу). cli_env() бере перший, який
     * вдалося підготувати; uninstall прибирає всі.
     */
    static function cli_home_dirs() {
        $name  = '/simple-mcp-' . substr(md5(ABSPATH), 0, 12);
        $bases = [sys_get_temp_dir()];
        // get_temp_dir() — WP_TEMP_DIR / upload_tmp_dir, але не сам wp-content (його останній варіант): там краще uploads нижче
        if (function_exists('get_temp_dir') && (!defined('WP_CONTENT_DIR') || rtrim(get_temp_dir(), '/\\') !== rtrim(WP_CONTENT_DIR, '/\\'))) {
            $bases[] = get_temp_dir();
        }
        if (function_exists('wp_upload_dir')) {
            $up = wp_upload_dir(null, false);
            if (!empty($up['basedir'])) $bases[] = rtrim((string) $up['basedir'], '/\\') . '/simple-mcp-tmp';
        }
        $out = [];
        foreach ($bases as $b) {
            $b = rtrim((string) $b, '/\\');
            if ($b !== '') $out[] = $b . $name;
        }
        return array_values(array_unique($out));
    }

    /** HOME для WP-CLI, яким скористається cli_env(): уже підготовлений або перший із придатним батьківським каталогом. */
    static function cli_home_dir() {
        $dirs = self::cli_home_dirs();
        foreach ($dirs as $d) {
            if (@is_dir($d) && !@is_link($d)) return $d;
        }
        foreach ($dirs as $d) {
            if (@is_writable(dirname($d))) return $d;
        }
        return $dirs[0];
    }

    /**
     * Готує HOME: каталог 0700 нашого користувача (не симлінк), порожній read-only config.yml
     * (без глобального конфігу = без alias/require), порожній packages/ і cache/. Права перевіряються
     * і без posix: каталог, до якого має доступ хтось іще, чи config.yml, який може змінити хтось
     * іще (спільний /tmp, каталог створив інший користувач), — відмова.
     */
    static function cli_home_prepare($home) {
        if (!@is_dir($home) && !@mkdir($home, 0700, true) && !@is_dir($home)) {
            return new WP_Error('cli_home', 'Не вдалося створити HOME для WP-CLI: ' . $home);
        }
        clearstatcache(true, $home);
        if (is_link($home) || (function_exists('posix_geteuid') && @fileowner($home) !== posix_geteuid())) {
            return new WP_Error('cli_home', 'HOME для WP-CLI належить іншому користувачу або є симлінком — відмова: ' . $home);
        }
        @chmod($home, 0700);
        clearstatcache(true, $home);
        $perm = @fileperms($home);
        if ($perm === false || ($perm & 0077) !== 0) {
            return new WP_Error('cli_home', 'HOME для WP-CLI доступний іншим користувачам (права не вдалося звузити до 0700) — відмова: ' . $home);
        }
        $cfg = $home . '/config.yml';
        clearstatcache(true, $cfg);
        if (is_link($cfg)) @unlink($cfg);
        if (!file_exists($cfg) || filesize($cfg) > 0) {
            @chmod($cfg, 0600);
            if (@file_put_contents($cfg, '') === false) {
                return new WP_Error('cli_home', 'Не вдалося підготувати порожній config.yml для WP-CLI: ' . $cfg);
            }
        }
        @chmod($cfg, 0400);
        clearstatcache(true, $cfg);
        $perm = @fileperms($cfg);
        if (is_link($cfg) || $perm === false || ($perm & 0022) !== 0 || filesize($cfg) > 0
            || (function_exists('posix_geteuid') && @fileowner($cfg) !== posix_geteuid())) {
            return new WP_Error('cli_home', 'config.yml у HOME для WP-CLI може змінити інший користувач — відмова: ' . $cfg);
        }
        foreach (['packages', 'cache'] as $d) {
            if (!is_dir($home . '/' . $d)) @mkdir($home . '/' . $d, 0700);
        }
        return true;
    }

    /**
     * Середовище для запуску wp-cli (proc_open замінює його повністю — задаємо все явно):
     * PATH з php, WP_CLI_PHP(+ARGS) для shell-обгорток, власний HOME, порожній глобальний конфіг,
     * порожній каталог пакетів, кеш у HOME, без авто-перевірки оновлень, EDITOR=false (команди
     * з редактором не висітимуть до таймауту), UTF-8 локаль. Якщо жоден кандидат HOME непридатний,
     * WP-CLI все одно запускається (як у 2.4.0) — з HOME у системному temp, але без глобального
     * конфігу, пакетів і кешу (/dev/null).
     */
    static function cli_env() {
        $php  = self::php_bin();
        $home = null;
        foreach (self::cli_home_dirs() as $cand) {
            // uploads/simple-mcp-tmp створюємо так, як це робить сам плагін (з .htaccess/index.php)
            if (strpos($cand, '/simple-mcp-tmp/') !== false && class_exists('Simple_MCP_Tools') && method_exists('Simple_MCP_Tools', 'tmp_dir')) {
                Simple_MCP_Tools::tmp_dir(true);
            }
            if (self::cli_home_prepare($cand) === true) {
                $home = $cand;
                break;
            }
        }
        $path = '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin';
        if (strpos($php, '/') !== false) $path = dirname($php) . ':' . $path;
        return [
            'PATH'                             => $path,
            'HOME'                             => $home !== null ? $home : sys_get_temp_dir(),
            'WP_CLI_PHP'                       => $php,
            'WP_CLI_PHP_ARGS'                  => '-d display_errors=stderr -d error_reporting=E_ALL&~E_DEPRECATED',
            'WP_CLI_CONFIG_PATH'               => $home !== null ? $home . '/config.yml' : '/dev/null',
            'WP_CLI_PACKAGES_DIR'              => $home !== null ? $home . '/packages' : '/dev/null',
            'WP_CLI_CACHE_DIR'                 => $home !== null ? $home . '/cache' : '/dev/null', // /dev/null вимикає кеш WP-CLI
            'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1',
            'EDITOR'                           => 'false',
            'LANG'                             => 'en_US.UTF-8',
            'LC_ALL'                           => 'en_US.UTF-8',
        ];
    }

    /**
     * argv для WP-CLI: phar/PHP-скрипт запускаємо обраним php ЯВНО (shebang "env php" взяв би
     * випадковий php з PATH) з display_errors=stderr і без E_DEPRECATED, щоб нотиси не псували
     * stdout (--format=json). Не-PHP обгортки (sh) — напряму, вони беруть WP_CLI_PHP із середовища.
     */
    static function wp_cli_argv(array $args) {
        $wp = self::wp_bin();
        if (self::is_php_script($wp)) {
            return array_merge(
                [self::php_bin(), '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $wp],
                $args
            );
        }
        return array_merge([$wp], $args);
    }

    /** Чи файл — PHP-скрипт/phar (shebang з php, "<?php" або .phar). Нечитабельний — false (запуск напряму). */
    static function is_php_script($path) {
        if (strpos($path, '/') === false && strpos($path, '\\') === false) return false;
        if (preg_match('/\.phar$/i', $path)) return true;
        $h = @fopen($path, 'rb');
        if (!$h) return false;
        $head = (string) fread($h, 256);
        fclose($h);
        if (strncmp($head, '<?php', 5) === 0) return true;
        if (strncmp($head, '#!', 2) === 0) {
            $line = strtok($head, "\n");
            return $line !== false && stripos($line, 'php') !== false;
        }
        return false;
    }

    /**
     * Запуск процесу (argv без шелла) з таймаутом. stdin — /dev/null (промпти й читання stdin
     * одразу отримують EOF). $max_bytes > 0 — ліміт захопленого виводу на потік: решту дочитуємо
     * й відкидаємо (процес не блокується на повному pipe і не вбивається посеред запису).
     * Повертає ['code', 'stdout', 'stderr', 'timed_out', 'stdout_bytes', 'stderr_bytes',
     * 'stdout_truncated', 'stderr_truncated'].
     */
    static function run_shell($cmd, $cwd, $timeout = 120, $env = null, $max_bytes = 0) {
        $res = [
            'code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false,
            'stdout_bytes' => 0, 'stderr_bytes' => 0, 'stdout_truncated' => false, 'stderr_truncated' => false,
        ];
        if (!function_exists('proc_open')) {
            $res['stderr'] = 'proc_open вимкнено на цьому сервері';
            return $res;
        }
        $null  = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $desc  = [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = @proc_open($cmd, $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            $res['stderr'] = 'не вдалося запустити процес';
            return $res;
        }
        $streams = ['stdout' => $pipes[1], 'stderr' => $pipes[2]];
        foreach ($streams as $s) stream_set_blocking($s, false);

        $start = microtime(true);
        $code  = null;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                // exitcode справжній лише в першому виклику після завершення — фіксуємо одразу
                $code = !empty($st['signaled']) ? 128 + (int) $st['termsig'] : (int) $st['exitcode'];
            }
            $read = [];
            foreach ($streams as $s) {
                if (!feof($s)) $read[] = $s;
            }
            if ($st['running']) {
                if ($read) {
                    $w = null;
                    $e = null;
                    @stream_select($read, $w, $e, 0, 100000);
                } else {
                    usleep(50000); // обидва pipe закриті, процес ще живий
                }
            }
            foreach ($streams as $k => $s) self::drain($s, $res, $k, $max_bytes);
            if (!$st['running']) break;
            if ((microtime(true) - $start) > $timeout) {
                proc_terminate($proc, 9);
                $res['timed_out'] = true;
                break;
            }
        }
        foreach ($streams as $k => $s) {
            self::drain($s, $res, $k, $max_bytes);
            fclose($s);
        }
        $close = proc_close($proc);
        if ($res['timed_out'] && $code === null) $code = 128 + 9; // вбили SIGKILL самі; proc_close дав би сирий статус (9)
        if ($code === null || $code < 0) $code = $close;
        if ($res['timed_out']) $res['stderr'] .= "\n[simple-mcp] таймаут після {$timeout}с — процес зупинено";
        $res['code'] = (int) $code;
        return $res;
    }

    /** Дочитати доступне з неблокувального pipe у $res[$k] з урахуванням ліміту (≤ 16 МБ за прохід). */
    private static function drain($s, array &$res, $k, $max) {
        for ($i = 0; $i < 256; $i++) {
            $chunk = @fread($s, 65536);
            if ($chunk === false || $chunk === '') return;
            $n = strlen($chunk);
            $res[$k . '_bytes'] += $n;
            if ($max > 0) {
                $room = $max - strlen($res[$k]);
                if ($room <= 0) { $res[$k . '_truncated'] = true; continue; }
                if ($n > $room) { $chunk = substr($chunk, 0, $room); $res[$k . '_truncated'] = true; }
            }
            $res[$k] .= $chunk;
        }
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
