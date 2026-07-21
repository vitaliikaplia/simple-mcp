<?php
/**
 * Авто-оновлення плагіна через публічний GitHub-репозиторій.
 *
 * Читає заголовок Version: із raw-файлу simple-mcp.php на GitHub, порівнює з локальною
 * версією і, якщо новіша, підкидає оновлення у стандартний механізм WordPress
 * (Плагіни → «Доступне оновлення»). Пакет — zip-архів гілки; тека нормалізується під slug.
 *
 * Гілку можна змінити константою SIMPLE_MCP_GITHUB_BRANCH у wp-config.php.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_GitHub_Updater {

    private const REPOSITORY = 'vitaliikaplia/simple-mcp';
    private const CACHE_KEY  = 'simple_mcp_github_update_data';
    private const CACHE_TTL  = 12 * HOUR_IN_SECONDS;

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filter_update_plugins_transient']);
        add_filter('site_transient_update_plugins', [$this, 'filter_update_plugins_transient']);
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_source_directory'], 11, 4);
        add_action('delete_site_transient_update_plugins', [$this, 'clear_cached_update_data']);
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_update'], 10, 2);
    }

    public function filter_update_plugins_transient($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }

        if (empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        $local_version = $transient->checked[SIMPLE_MCP_BASENAME] ?? SIMPLE_MCP_VERSION;
        $remote_data   = $this->get_remote_update_data($this->should_force_check());

        if (!$remote_data || empty($remote_data['version'])) {
            return $transient;
        }

        if (empty($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (empty($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        $update = $this->build_update_response($remote_data);

        if (version_compare($remote_data['version'], $local_version, '>')) {
            $transient->response[SIMPLE_MCP_BASENAME] = $update;
            unset($transient->no_update[SIMPLE_MCP_BASENAME]);
        } else {
            $transient->no_update[SIMPLE_MCP_BASENAME] = $update;
            unset($transient->response[SIMPLE_MCP_BASENAME]);
        }

        return $transient;
    }

    public function filter_plugins_api($result, string $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->get_slug()) {
            return $result;
        }

        $remote_data = $this->get_remote_update_data($this->should_force_check());
        $version     = $remote_data['version'] ?? SIMPLE_MCP_VERSION;

        return (object) [
            'name'         => 'Simple MCP',
            'slug'         => $this->get_slug(),
            'version'      => $version,
            'author'       => '<a href="https://kaplia.pro/">Vitalii Kaplia</a>',
            'homepage'     => $this->get_repository_url(),
            'requires'     => '6.0',
            'requires_php' => '8.1',
            'tested'       => get_bloginfo('version'),
            'download_link' => $remote_data['package'] ?? $this->get_package_url(),
            'sections'     => [
                'description' => '<p>Simple MCP — приватний MCP-сервер для WordPress: власний ендпоінт поза REST API, персональні ключі з дзеркаленням ролей і прав WordPress, WP-CLI для адмінів (deny-list) та безпечні типізовані інструменти для медіа, Gutenberg-блоків і ACF.</p>',
                'changelog'   => '<p><strong>2.0.0</strong> — персональні ключі замість глобального, матриця «Права ролей», нативні capability-перевірки на кожен виклик, журнал викликів із фільтром/пагінацією/очищенням, admin-only налаштування. Авто-міграція зі старих налаштувань.</p><p>Оновлення завантажуються з гілки публічного GitHub-репозиторію, коли версія в заголовку плагіна новіша за встановлену.</p>',
            ],
        ];
    }

    public function normalize_github_source_directory($source, string $remote_source, $upgrader, array $hook_extra = []) {
        if (is_wp_error($source)) {
            return $source;
        }
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== SIMPLE_MCP_BASENAME) {
            return $source;
        }

        $source_path        = untrailingslashit((string) $source);
        $expected_directory = $this->get_slug();

        if (basename($source_path) === $expected_directory) {
            return trailingslashit($source_path);
        }
        if (!str_starts_with(basename($source_path), $expected_directory . '-')) {
            return $source;
        }

        $target = trailingslashit(dirname($source_path)) . $expected_directory;

        global $wp_filesystem;

        if ($wp_filesystem && $wp_filesystem->exists($target)) {
            $wp_filesystem->delete($target, true);
        } elseif (file_exists($target)) {
            $this->delete_directory($target);
        }

        if ($wp_filesystem && $wp_filesystem->move($source_path, $target, true)) {
            return trailingslashit($target);
        }
        if (@rename($source_path, $target)) {
            return trailingslashit($target);
        }

        return $source;
    }

    private function delete_directory(string $directory): void {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }

    public function clear_cache_after_update($upgrader, array $hook_extra): void {
        if (empty($hook_extra['action']) || $hook_extra['action'] !== 'update') {
            return;
        }
        if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
            return;
        }
        $plugins = isset($hook_extra['plugins']) ? (array) $hook_extra['plugins'] : [$hook_extra['plugin'] ?? ''];
        if (in_array(SIMPLE_MCP_BASENAME, $plugins, true)) {
            $this->clear_cached_update_data();
        }
    }

    public function clear_cached_update_data(): void {
        delete_site_transient(self::CACHE_KEY);
    }

    private function get_remote_update_data(bool $force = false): ?array {
        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return !empty($cached['version']) ? $cached : null;
            }
        }

        $response = wp_remote_get(
            $this->get_remote_plugin_file_url(),
            [
                'timeout'     => 10,
                'redirection' => 3,
                'headers'     => [
                    'Accept'     => 'text/plain',
                    'User-Agent' => 'Simple-MCP/' . SIMPLE_MCP_VERSION . '; ' . home_url('/'),
                ],
            ]
        );

        if (is_wp_error($response)) {
            $this->cache_failed_check();
            return null;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $this->cache_failed_check();
            return null;
        }

        $body    = (string) wp_remote_retrieve_body($response);
        $version = $this->parse_plugin_version($body);

        if (!$version) {
            $this->cache_failed_check();
            return null;
        }

        $data = [
            'version'      => $version,
            'package'      => $this->get_package_url(),
            'url'          => $this->get_repository_url(),
            'branch'       => $this->get_branch(),
            'last_checked' => time(),
        ];

        set_site_transient(self::CACHE_KEY, $data, self::CACHE_TTL);

        return $data;
    }

    private function build_update_response(array $remote_data): stdClass {
        return (object) [
            'id'           => $this->get_repository_url(),
            'slug'         => $this->get_slug(),
            'plugin'       => SIMPLE_MCP_BASENAME,
            'new_version'  => $remote_data['version'],
            'url'          => $remote_data['url'],
            'package'      => $remote_data['package'],
            'requires'     => '6.0',
            'requires_php' => '8.1',
            'tested'       => get_bloginfo('version'),
        ];
    }

    private function parse_plugin_version(string $plugin_file_contents): ?string {
        if (!preg_match('/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $plugin_file_contents, $matches)) {
            return null;
        }
        $version = trim($matches[1]);
        return $version !== '' ? $version : null;
    }

    private function should_force_check(): bool {
        $force_check = isset($_GET['force-check']) ? sanitize_text_field(wp_unslash($_GET['force-check'])) : '';
        return is_admin()
            && current_user_can('update_plugins')
            && $force_check === '1';
    }

    private function cache_failed_check(): void {
        set_site_transient(
            self::CACHE_KEY,
            ['version' => '', 'last_checked' => time()],
            HOUR_IN_SECONDS
        );
    }

    private function get_slug(): string {
        return dirname(SIMPLE_MCP_BASENAME);
    }

    private function get_branch(): string {
        $branch = defined('SIMPLE_MCP_GITHUB_BRANCH') ? (string) SIMPLE_MCP_GITHUB_BRANCH : 'master';
        $branch = trim($branch);
        return $branch !== '' ? $branch : 'master';
    }

    private function get_remote_plugin_file_url(): string {
        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/simple-mcp.php',
            self::REPOSITORY,
            $this->get_url_branch()
        );
    }

    private function get_package_url(): string {
        return sprintf(
            'https://github.com/%s/archive/refs/heads/%s.zip',
            self::REPOSITORY,
            $this->get_url_branch()
        );
    }

    private function get_repository_url(): string {
        return 'https://github.com/' . self::REPOSITORY;
    }

    private function get_url_branch(): string {
        return implode('/', array_map('rawurlencode', explode('/', $this->get_branch())));
    }
}
