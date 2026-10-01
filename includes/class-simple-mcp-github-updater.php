<?php
/**
 * Авто-оновлення плагіна з GitHub Releases.
 *
 * Канал «release» (типовий): останній реліз читається через GitHub API
 * (repos/vitaliikaplia/simple-mcp/releases/latest). Версія = tag_name без «v», пакет — незмінний
 * asset simple-mcp.zip цього релізу. Перед установкою zip звіряється з asset-ом
 * simple-mcp.zip.sha256 того ж релізу (upgrader_pre_download); розбіжність → WP_Error, файл видаляється.
 * Реліз без обох assets оновлення не пропонує (fail closed). Requires/Tested беруться із заголовка
 * simple-mcp.php на тезі релізу, changelog у вікні «Деталі» — з опису релізу.
 *
 * Канал «branch» (dev): define('SIMPLE_MCP_UPDATE_CHANNEL', 'branch') у wp-config.php — стара
 * поведінка: Version із raw simple-mcp.php гілки SIMPLE_MCP_GITHUB_BRANCH + zip гілки, БЕЗ
 * контрольної суми.
 *
 * Мережа — лише при оновленні транзієнта update_plugins (pre_set_site_transient_update_plugins) і у
 * вікні «Деталі» (plugins_api); читання транзієнта бере тільки кеш. Кеш 12 год (невдача — 1 год),
 * у межах запиту — мемо, тож «Перевірити знову» (?force-check=1) робить один запит, а не кілька.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_GitHub_Updater {

    private const REPOSITORY   = 'vitaliikaplia/simple-mcp';
    private const ASSET        = 'simple-mcp.zip';
    private const CACHE_KEY    = 'simple_mcp_github_update_data';
    private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
    private const FAIL_TTL     = HOUR_IN_SECONDS;
    private const CACHE_SCHEMA = 2; // формат кешу; записи старих версій (без schema) вважаються промахом
    private const DESCRIPTION  = '<p>Simple MCP — приватний MCP-сервер для WordPress: власний ендпоінт поза REST API, персональні ключі з дзеркаленням ролей і прав WordPress, WP-CLI для адмінів (deny-list) та безпечні типізовані інструменти для контенту, Gutenberg-блоків, ACF, медіа та мультимовності.</p>';

    /** @var array|null Мемо запису (кеш або свіжа відповідь) на час запиту. */
    private $memo = null;
    /** @var bool Чи вже читали кеш у цьому запиті. */
    private $memo_loaded = false;
    /** @var bool Чи вже ходили в мережу в цьому запиті (повторно — ніколи). */
    private $fetched = false;

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'refresh_update_plugins']);
        add_filter('site_transient_update_plugins', [$this, 'read_update_plugins']);
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'verify_package'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'normalize_github_source_directory'], 11, 4);
        add_action('delete_site_transient_update_plugins', [$this, 'clear_cached_update_data']);
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_update'], 10, 2);
    }

    // ── Транзієнт update_plugins ─────────────────────────────────────────

    /** Оновлення транзієнта (wp_update_plugins / cron): тут дозволено сходити на GitHub. */
    public function refresh_update_plugins($transient) {
        return $this->apply_update_data($transient, $this->get_update_data(true, $this->should_force_check()));
    }

    /** Читання транзієнта (адмін-бар, меню, список плагінів): лише кеш, без мережі. */
    public function read_update_plugins($transient) {
        return $this->apply_update_data($transient, $this->get_update_data(false));
    }

    private function apply_update_data($transient, ?array $data) {
        if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = [];
        }

        if (!$data) {
            // Власних даних немає (невдала перевірка): чужий пакет під нашим basename не пропускаємо
            // (wordpress.org, інший апдейтер, zip гілки в каналі release).
            foreach (['response', 'no_update'] as $list) {
                $item = $transient->{$list}[SIMPLE_MCP_BASENAME] ?? null;
                if ($item !== null && !$this->is_own_package((string) (((array) $item)['package'] ?? ''))) {
                    unset($transient->{$list}[SIMPLE_MCP_BASENAME]);
                }
            }
            return $transient;
        }

        $local_version = (string) ($transient->checked[SIMPLE_MCP_BASENAME] ?? SIMPLE_MCP_VERSION);
        $update        = $this->build_update_response($data);

        if (version_compare($data['version'], $local_version, '>')) {
            $transient->response[SIMPLE_MCP_BASENAME] = $update;
            unset($transient->no_update[SIMPLE_MCP_BASENAME]);
        } else {
            $transient->no_update[SIMPLE_MCP_BASENAME] = $update;
            unset($transient->response[SIMPLE_MCP_BASENAME]);
        }

        return $transient;
    }

    private function build_update_response(array $data): stdClass {
        $update = [
            'id'          => $this->get_repository_url(), // = Update URI
            'slug'        => $this->get_slug(),
            'plugin'      => SIMPLE_MCP_BASENAME,
            'new_version' => $data['version'],
            'url'         => $data['url'],
            'package'     => $data['package'],
            'icons'       => [],
            'banners'     => [],
            'banners_rtl' => [],
        ];
        foreach (['requires', 'requires_php', 'tested'] as $field) {
            if (!empty($data[$field])) {
                $update[$field] = $data[$field];
            }
        }
        return (object) $update;
    }

    // ── Вікно «Деталі» ───────────────────────────────────────────────────

    public function filter_plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug) || $args->slug !== $this->get_slug()) {
            return $result;
        }

        $data = $this->get_update_data(true);
        // Без віддалених даних показуємо встановлену копію (і без посилання на пакет).
        $meta = $data ?: $this->local_headers();

        return (object) [
            'name'          => 'Simple MCP',
            'slug'          => $this->get_slug(),
            'version'       => $data['version'] ?? SIMPLE_MCP_VERSION,
            'author'        => '<a href="https://kaplia.pro/">Vitalii Kaplia</a>',
            'homepage'      => $this->get_repository_url(),
            'requires'      => (string) ($meta['requires'] ?? ''),
            'requires_php'  => (string) ($meta['requires_php'] ?? ''),
            'tested'        => (string) ($meta['tested'] ?? ''),
            'last_updated'  => (string) ($data['published'] ?? ''),
            'download_link' => (string) ($data['package'] ?? ''),
            'sections'      => [
                'description' => self::DESCRIPTION,
                'changelog'   => $this->changelog_html($data),
            ],
        ];
    }

    private function changelog_html(?array $data): string {
        $releases = '<p><a href="' . esc_url($this->get_repository_url() . '/releases') . '">Усі релізи на GitHub</a></p>';
        if (!$data) {
            return '<p>Не вдалося отримати дані релізу з GitHub — спробуйте пізніше.</p>' . $releases;
        }
        if ($this->is_branch_channel()) {
            return '<p>Dev-канал (гілка <code>' . esc_html($this->get_branch()) . '</code>): пакет — zip гілки без контрольної суми. Список змін — у <a href="'
                . esc_url($this->get_repository_url() . '#readme') . '">README репозиторію</a>.</p>';
        }
        $body = trim((string) ($data['body'] ?? ''));
        $html = $body !== '' ? self::markdown_to_html($body) : '<p>Опис змін до релізу не додано.</p>';
        return '<h4>' . esc_html($data['version']) . '</h4>' . $html . $releases;
    }

    // ── Перевірка пакета перед установкою ────────────────────────────────

    /**
     * upgrader_pre_download: у каналі release завантажує asset simple-mcp.zip сам і звіряє його
     * SHA-256 з simple-mcp.zip.sha256 того ж релізу. Розбіжність, відсутній checksum або пакет не з
     * релізу → WP_Error (оновлення скасовується, завантажений файл видаляється).
     */
    public function verify_package($reply, $package, $upgrader = null, $hook_extra = []) {
        if (is_wp_error($reply)) {
            return $reply;
        }
        $package      = (string) $package;
        $checksum_url = self::checksum_url_for($package);
        $ours         = (is_array($hook_extra) && ($hook_extra['plugin'] ?? '') === SIMPLE_MCP_BASENAME) || $checksum_url !== null;
        if (!$ours || $this->is_branch_channel()) {
            return $reply; // чужий пакет або dev-канал (без контрольної суми, як раніше)
        }
        if ($checksum_url === null) {
            return new WP_Error('simple_mcp_untrusted_package', 'Simple MCP: пакет оновлення не є asset-ом simple-mcp.zip GitHub-релізу — оновлення скасовано. Перевірте оновлення ще раз.');
        }

        $sums = $this->http_get($checksum_url, 'text/plain', 15, true);
        if (is_wp_error($sums)) {
            return new WP_Error('simple_mcp_checksum_unavailable', 'Simple MCP: не вдалося завантажити ' . self::ASSET . '.sha256 релізу (' . $sums->get_error_message() . ') — оновлення скасовано.');
        }
        $expected = self::parse_checksum($sums, self::ASSET);
        if ($expected === null) {
            return new WP_Error('simple_mcp_checksum_invalid', 'Simple MCP: ' . self::ASSET . '.sha256 релізу має неочікуваний формат — оновлення скасовано.');
        }

        if (is_string($reply) && $reply !== '' && is_file($reply)) {
            $file = $reply; // інший фільтр уже завантажив пакет — перевіряємо саме його
        } else {
            if (is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin) && method_exists($upgrader->skin, 'feedback')) {
                $upgrader->skin->feedback('downloading_package', $package);
            }
            if (!function_exists('download_url')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $file = download_url($package, 300);
            if (is_wp_error($file)) {
                return new WP_Error('download_failed', 'Simple MCP: не вдалося завантажити пакет оновлення.', $file->get_error_message());
            }
        }

        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            @unlink($file);
            return new WP_Error('simple_mcp_checksum_mismatch', 'Simple MCP: SHA-256 завантаженого пакета не збігається з ' . self::ASSET . '.sha256 релізу — оновлення скасовано, файл видалено.');
        }

        if (is_object($upgrader) && isset($upgrader->skin) && is_object($upgrader->skin) && method_exists($upgrader->skin, 'feedback')) {
            $upgrader->skin->feedback('SHA-256 пакета збігається з ' . self::ASSET . '.sha256 релізу.');
        }
        return $file;
    }

    // ── Тека пакета ──────────────────────────────────────────────────────

    /**
     * Zip релізу розпаковується в «simple-mcp/», zip гілки — в «simple-mcp-<гілка>/».
     * Обидва варіанти приводимо до теки встановленого плагіна (slug).
     */
    public function normalize_github_source_directory($source, $remote_source, $upgrader, $hook_extra = []) {
        if (is_wp_error($source)) {
            return $source;
        }
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== SIMPLE_MCP_BASENAME) {
            return $source;
        }

        $source_path        = untrailingslashit((string) $source);
        $source_name        = basename($source_path);
        $expected_directory = $this->get_slug();
        $repo_name          = basename(self::REPOSITORY);

        if ($source_name === $expected_directory) {
            return trailingslashit($source_path);
        }
        if ($source_name !== $repo_name
            && !str_starts_with($source_name, $repo_name . '-')
            && !str_starts_with($source_name, $expected_directory . '-')) {
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

    // ── Кеш ──────────────────────────────────────────────────────────────

    public function clear_cache_after_update($upgrader, $hook_extra): void {
        if (!is_array($hook_extra) || empty($hook_extra['action']) || $hook_extra['action'] !== 'update') {
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
        $this->memo        = null;
        $this->memo_loaded = false;
        $this->fetched     = false;
    }

    /**
     * Дані оновлення: мемо → кеш → (лише якщо $remote) GitHub. $force пропускає кеш, але не мемо:
     * у межах одного запиту мережевий запит робиться щонайбільше раз.
     */
    private function get_update_data(bool $remote, bool $force = false): ?array {
        if (!$this->fetched && !$this->memo_loaded) {
            $this->memo        = $this->read_cache();
            $this->memo_loaded = true;
        }
        if ($this->fetched || ($this->memo !== null && !$force) || !$remote) {
            return ($this->memo !== null && !empty($this->memo['ok'])) ? $this->memo : null;
        }

        $record        = $this->is_branch_channel() ? $this->fetch_branch() : $this->fetch_release();
        $this->memo    = $record;
        $this->fetched = true;
        set_site_transient(self::CACHE_KEY, $record, !empty($record['ok']) ? self::CACHE_TTL : self::FAIL_TTL);

        return !empty($record['ok']) ? $record : null;
    }

    private function read_cache(): ?array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (!is_array($cached)
            || ($cached['schema'] ?? 0) !== self::CACHE_SCHEMA
            || ($cached['channel'] ?? '') !== $this->channel_key()) {
            return null;
        }
        return $cached;
    }

    private function record(bool $ok, array $fields = []): array {
        return array_merge([
            'schema'       => self::CACHE_SCHEMA,
            'channel'      => $this->channel_key(),
            'ok'           => $ok,
            'last_checked' => time(),
        ], $fields);
    }

    private function fetch_release(): array {
        $body = $this->http_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
            'application/vnd.github+json',
            10
        );
        if (is_wp_error($body)) {
            return $this->record(false, ['error' => $body->get_error_message()]);
        }
        $json    = json_decode($body, true);
        $release = self::parse_release(is_array($json) ? $json : []);
        if ($release === null) {
            return $this->record(false, ['error' => 'Останній реліз не має тегу vX.Y.Z або assets ' . self::ASSET . ' і ' . self::ASSET . '.sha256']);
        }

        // Requires/Tested — із заголовка simple-mcp.php на тезі релізу. Не критично: без нього поля порожні.
        $php = $this->http_get($this->raw_file_url($release['tag']), 'text/plain', 6);
        if (!is_wp_error($php)) {
            $headers = self::parse_headers($php);
            if ($headers['version'] !== '' && $headers['version'] !== $release['version']) {
                return $this->record(false, ['error' => 'Тег ' . $release['tag'] . ' не збігається з Version: ' . $headers['version'] . ' у simple-mcp.php релізу']);
            }
            $release['requires']     = $headers['requires'];
            $release['requires_php'] = $headers['requires_php'];
            $release['tested']       = $headers['tested'];
        }

        return $this->record(true, $release);
    }

    private function fetch_branch(): array {
        $php = $this->http_get($this->raw_file_url($this->get_branch()), 'text/plain', 10);
        if (is_wp_error($php)) {
            return $this->record(false, ['error' => $php->get_error_message()]);
        }
        $headers = self::parse_headers($php);
        $version = self::version_from_tag($headers['version']);
        if ($version === null) {
            return $this->record(false, ['error' => 'Не знайдено валідний Version: у simple-mcp.php гілки ' . $this->get_branch()]);
        }
        return $this->record(true, [
            'version'      => $version,
            'tag'          => '',
            'package'      => sprintf('https://github.com/%s/archive/refs/heads/%s.zip', self::REPOSITORY, $this->get_url_ref($this->get_branch())),
            'checksum_url' => '',
            'url'          => $this->get_repository_url(),
            'body'         => '',
            'published'    => '',
            'requires'     => $headers['requires'],
            'requires_php' => $headers['requires_php'],
            'tested'       => $headers['tested'],
        ]);
    }

    /** GET → тіло відповіді (2xx) або WP_Error. $safe — wp_safe_remote_get (редиректи на CDN assets). */
    private function http_get(string $url, string $accept, int $timeout, bool $safe = false) {
        $headers = [
            'Accept'     => $accept,
            'User-Agent' => 'Simple-MCP/' . SIMPLE_MCP_VERSION . '; ' . home_url('/'),
        ];
        if (strpos($url, 'https://api.github.com/') === 0) {
            $headers['X-GitHub-Api-Version'] = '2022-11-28';
        }
        $args     = ['timeout' => $timeout, 'redirection' => $safe ? 5 : 3, 'headers' => $headers];
        $response = $safe ? wp_safe_remote_get($url, $args) : wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('simple_mcp_http', sprintf('HTTP %d від %s', $code, $url));
        }
        return (string) wp_remote_retrieve_body($response);
    }

    // ── Чисті парсери (покриті tests/) ───────────────────────────────────

    /** «v2.5.0» / «2.5.0» → «2.5.0»; некоректний тег → null. */
    public static function version_from_tag(string $tag): ?string {
        $version = preg_replace('/^v/i', '', trim($tag));
        return preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version) ? $version : null;
    }

    /**
     * Відповідь releases/latest → дані оновлення або null (чернетка/пре-реліз, некоректний тег,
     * немає завантажених assets simple-mcp.zip і simple-mcp.zip.sha256 у теці цього тегу).
     */
    public static function parse_release(array $release): ?array {
        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            return null;
        }
        $tag     = (string) ($release['tag_name'] ?? '');
        $version = self::version_from_tag($tag);
        if ($version === null) {
            return null;
        }

        $dir    = 'https://github.com/' . self::REPOSITORY . '/releases/download/' . rawurlencode($tag) . '/';
        $assets = [];
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['state'] ?? '') !== 'uploaded') {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');
            if (($name === self::ASSET || $name === self::ASSET . '.sha256') && $url === $dir . $name) {
                $assets[$name] = $url;
            }
        }
        if (!isset($assets[self::ASSET], $assets[self::ASSET . '.sha256'])) {
            return null;
        }

        $html_url = (string) ($release['html_url'] ?? '');
        $body     = (string) ($release['body'] ?? '');
        if (strlen($body) > 65536) {
            $body = function_exists('mb_strcut') ? mb_strcut($body, 0, 65536, 'UTF-8') : substr($body, 0, 65536);
        }

        return [
            'version'      => $version,
            'tag'          => $tag,
            'package'      => $assets[self::ASSET],
            'checksum_url' => $assets[self::ASSET . '.sha256'],
            'url'          => str_starts_with($html_url, 'https://github.com/' . self::REPOSITORY . '/') ? $html_url : 'https://github.com/' . self::REPOSITORY,
            'body'         => $body,
            'published'    => (string) ($release['published_at'] ?? ''),
            'requires'     => '',
            'requires_php' => '',
            'tested'       => '',
        ];
    }

    /** URL asset-а simple-mcp.zip релізу → URL його .sha256; будь-який інший URL → null. */
    public static function checksum_url_for(string $package): ?string {
        $pattern = '#^https://github\.com/' . preg_quote(self::REPOSITORY, '#') . '/releases/download/[^/?\#]+/' . preg_quote(self::ASSET, '#') . '$#';
        return preg_match($pattern, $package) ? $package . '.sha256' : null;
    }

    /** Вміст *.sha256 у форматі sha256sum («<hex>  simple-mcp.zip») → hex у нижньому регістрі або null. */
    public static function parse_checksum(string $contents, string $filename): ?string {
        $lines = preg_split('/\R/', trim($contents));
        foreach ($lines as $line) {
            if (!preg_match('/^\s*([0-9a-fA-F]{64})(?:\s+\*?(\S.*?))?\s*$/', $line, $m)) {
                continue;
            }
            $name = $m[2] ?? '';
            if ($name === $filename || ($name === '' && count($lines) === 1)) {
                return strtolower($m[1]);
            }
        }
        return null;
    }

    /** Заголовки плагіна (як get_file_data: перші 8 КБ) → version/requires/requires_php/tested. */
    public static function parse_headers(string $php): array {
        $php    = substr($php, 0, 8192);
        $fields = ['version' => 'Version', 'requires' => 'Requires at least', 'requires_php' => 'Requires PHP', 'tested' => 'Tested up to'];
        $out    = [];
        foreach ($fields as $key => $header) {
            $out[$key] = '';
            if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi', $php, $m)) {
                $out[$key] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
            }
        }
        return $out;
    }

    /**
     * Мінімальний Markdown опису релізу → HTML для вікна «Деталі»: заголовки (h4), списки (з
     * продовженням рядків), абзаци, `код`, **жирний**, [посилання](https://…). Усе інше екранується.
     */
    public static function markdown_to_html(string $markdown): string {
        $html  = '';
        $para  = [];
        $items = [];
        $flush = function () use (&$html, &$para, &$items) {
            if ($para) {
                $html .= '<p>' . self::markdown_inline(implode(' ', $para)) . '</p>';
                $para  = [];
            }
            if ($items) {
                $html .= '<ul><li>' . implode('</li><li>', array_map([self::class, 'markdown_inline'], $items)) . '</li></ul>';
                $items = [];
            }
        };

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)) as $line) {
            if (trim($line) === '') {
                $flush();
            } elseif (preg_match('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $flush();
                $html .= '<h4>' . self::markdown_inline($m[1]) . '</h4>';
            } elseif (preg_match('/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/', $line, $m)) {
                if ($para) {
                    $flush();
                }
                $items[] = trim($m[1]);
            } elseif ($items && preg_match('/^\s+\S/', $line)) {
                $items[count($items) - 1] .= ' ' . trim($line); // продовження пункту списку
            } else {
                if ($items) {
                    $flush();
                }
                $para[] = trim($line);
            }
        }
        $flush();

        return $html;
    }

    public static function markdown_inline(string $text): string {
        $out   = '';
        $parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= '<code>' . esc_html(substr($part, 1, -1)) . '</code>';
                continue;
            }
            $part = esc_html($part);
            $part = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $part);
            $part = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function ($m) {
                return '<a href="' . esc_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')) . '">' . $m[1] . '</a>';
            }, $part);
            $out .= $part;
        }
        return $out;
    }

    // ── Дрібниці ─────────────────────────────────────────────────────────

    private function local_headers(): array {
        $headers = function_exists('get_file_data')
            ? get_file_data(SIMPLE_MCP_FILE, ['requires' => 'Requires at least', 'requires_php' => 'Requires PHP', 'tested' => 'Tested up to'])
            : [];
        return is_array($headers) ? $headers : [];
    }

    /**
     * Чи пакет із запису update_plugins наш. Канал release — лише asset simple-mcp.zip релізу (інший
     * однаково відхилить verify_package, тож не показуємо «оновлення», яке не встановиться — напр.
     * zip гілки, що лишився після dev-каналу чи старого апдейтера); dev-канал — будь-що з репозиторію.
     */
    private function is_own_package(string $package): bool {
        if (!$this->is_branch_channel()) {
            return self::checksum_url_for($package) !== null;
        }
        return str_starts_with($package, $this->get_repository_url() . '/');
    }

    private function is_branch_channel(): bool {
        return defined('SIMPLE_MCP_UPDATE_CHANNEL') && SIMPLE_MCP_UPDATE_CHANNEL === 'branch';
    }

    private function channel_key(): string {
        return $this->is_branch_channel() ? 'branch:' . $this->get_branch() : 'release';
    }

    private function should_force_check(): bool {
        $force_check = isset($_GET['force-check']) ? sanitize_text_field(wp_unslash($_GET['force-check'])) : '';
        return is_admin()
            && current_user_can('update_plugins')
            && $force_check === '1';
    }

    private function get_slug(): string {
        return dirname(SIMPLE_MCP_BASENAME);
    }

    private function get_branch(): string {
        $branch = defined('SIMPLE_MCP_GITHUB_BRANCH') ? (string) SIMPLE_MCP_GITHUB_BRANCH : 'master';
        $branch = trim($branch);
        return $branch !== '' ? $branch : 'master';
    }

    private function raw_file_url(string $ref): string {
        return sprintf('https://raw.githubusercontent.com/%s/%s/simple-mcp.php', self::REPOSITORY, $this->get_url_ref($ref));
    }

    private function get_repository_url(): string {
        return 'https://github.com/' . self::REPOSITORY;
    }

    private function get_url_ref(string $ref): string {
        return implode('/', array_map('rawurlencode', explode('/', $ref)));
    }
}
