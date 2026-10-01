<?php
/**
 * describe_site — one call that returns this fork's content schema so an AI can self-configure:
 * ACF blocks + their fields, ACF options pages + fields (with the exact per-language post_id),
 * public CPTs & taxonomies (translatable flags as the multilingual system really applies them),
 * and the language list from the system's runtime API (slug<->wpml_code). Cached 1h.
 *
 * Note on options: ACF option-page fields are listed precisely (edit via acf_update with the
 * listed post_id / post_ids[lang]). Any wp_option NOT listed here is a "plain" option
 * (theme Settings-API/register_setting) — read/write it via wp_cli `wp option get/update`.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Describe {

    const CACHE_KEY = 'simple_mcp_describe_v2'; // v2: runtime languages + per-language options post_ids (old cached shape is ignored)

    static function defs() {
        return [
            'describe_site' => [
                'title'       => 'Describe site',
                'description' => 'Return this site/fork\'s content schema in one call: ACF blocks (+field names/types); ACF options pages (+fields, the base post_id and, on a multilingual site, '
                    . 'post_ids:{wpml_code: post_id} — the exact acf_get/acf_update post_id for each language); public post types and taxonomies with the translatable flag the multilingual system actually applies; '
                    . 'and languages from the multilingual system\'s runtime API: {system, default, languages:[{slug, wpml_code, name, locale, enabled, default, options_post_id}], sync_note}. '
                    . 'Call this first on an unfamiliar site to learn exactly which blocks/fields/options/languages exist here, instead of guessing. Cached for one hour; pass refresh:true to rebuild.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['refresh' => ['type' => 'boolean', 'description' => 'Rebuild instead of returning the cached copy.']]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback' => [__CLASS__, 'describe_site'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }

    static function describe_site($args) {
        if (empty($args['refresh'])) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached)) return self::ok($cached + ['cached' => true]);
        }
        $data = [
            'site'        => ['name' => get_bloginfo('name'), 'url' => home_url('/'), 'wp' => get_bloginfo('version'), 'theme' => wp_get_theme()->get('Name')],
            'languages'   => self::languages(),
            'blocks'      => self::blocks(),
            'acf_options' => self::acf_options(),
            'post_types'  => self::post_types(),
            'taxonomies'  => self::taxonomies(),
        ];
        set_transient(self::CACHE_KEY, $data, HOUR_IN_SECONDS);
        return self::ok($data + ['cached' => false]);
    }

    /** Languages as the multilingual system resolves them at runtime (wp-loc: incl. disabled ones; WPML: active ones). */
    static function languages() {
        $ml  = class_exists('Simple_MCP_Tools_Wploc');
        $out = ['system' => $ml ? Simple_MCP_Tools_Wploc::system() : null, 'default' => null, 'languages' => []];
        if (!$out['system']) return $out;
        foreach (Simple_MCP_Tools_Wploc::languages() as $code => $l) {
            $opt = Simple_MCP_Tools_Wploc::options_post_id($code);
            $out['languages'][] = [
                'slug'            => $l['slug'],
                'wpml_code'       => $code,
                'name'            => $l['name'],
                'locale'          => $l['locale'],
                'enabled'         => $l['active'],
                'default'         => $l['default'],
                'options_post_id' => is_wp_error($opt) ? null : $opt,
            ];
            if ($l['default']) $out['default'] = $code;
        }
        if (!$out['default']) $out['default'] = Simple_MCP_Tools_Wploc::default_code();
        $note = Simple_MCP_Tools_Wploc::sync_note();
        if ($note) $out['sync_note'] = $note;
        return $out;
    }

    static function blocks() {
        if (!function_exists('acf_get_block_types')) return [];
        $out = [];
        foreach (acf_get_block_types() as $name => $bt) {
            $fields = [];
            foreach (Simple_MCP_Tools_Blocks::block_field_defs($name) as $f) {
                if (in_array($f['type'] ?? '', ['accordion', 'tab', 'message'], true)) continue;
                $fields[] = ['name' => $f['name'], 'type' => $f['type']];
            }
            $out[] = ['name' => $name, 'title' => $bt['title'] ?? $name, 'fields' => $fields];
        }
        return $out;
    }

    static function acf_options() {
        if (!function_exists('acf_get_options_pages')) return [];
        $pages = acf_get_options_pages();
        if (!is_array($pages)) return [];
        $out = [];
        foreach ($pages as $slug => $page) {
            $menu_slug = $page['menu_slug'] ?? $slug;
            $fields = [];
            foreach (acf_get_field_groups(['options_page' => $menu_slug]) as $g) {
                foreach (acf_get_fields($g['key']) as $f) {
                    if (($f['name'] ?? '') === '' || in_array($f['type'] ?? '', ['accordion', 'tab', 'message'], true)) continue;
                    $fields[] = ['name' => $f['name'], 'type' => $f['type']];
                }
            }
            $base = (isset($page['post_id']) && is_string($page['post_id']) && $page['post_id'] !== '') ? $page['post_id'] : 'options';
            $row = [
                'title'     => $page['page_title'] ?? $menu_slug,
                'menu_slug' => $menu_slug,
                'post_id'   => $base,
                'fields'    => $fields,
            ];
            // Exact post_id per language (wp-loc: '{base}_{slug}' for non-default languages; WPML: 'options_{code}').
            if (class_exists('Simple_MCP_Tools_Wploc') && Simple_MCP_Tools_Wploc::system()) {
                $ids = [];
                foreach (array_keys(Simple_MCP_Tools_Wploc::languages()) as $code) {
                    $pid = Simple_MCP_Tools_Wploc::options_post_id($code, $base);
                    if (!is_wp_error($pid)) $ids[$code] = $pid;
                }
                if ($ids) $row['post_ids'] = $ids;
            }
            $out[] = $row;
        }
        return $out;
    }

    static function post_types() {
        $skip = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
            'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page'];
        $ml  = class_exists('Simple_MCP_Tools_Wploc') && Simple_MCP_Tools_Wploc::system();
        $out = [];
        foreach (get_post_types([], 'objects') as $pt) {
            if (in_array($pt->name, $skip, true) || strpos($pt->name, 'acf-') === 0) continue;
            if (!$pt->public && !$pt->show_ui) continue;
            $out[] = [
                'name'         => $pt->name,
                'label'        => $pt->label,
                'hierarchical' => (bool) $pt->hierarchical,
                'has_archive'  => (bool) $pt->has_archive,
                'taxonomies'   => get_object_taxonomies($pt->name),
                'translatable' => $ml && Simple_MCP_Tools_Wploc::is_translatable_post_type($pt->name),
            ];
        }
        return $out;
    }

    static function taxonomies() {
        $ml  = class_exists('Simple_MCP_Tools_Wploc') && Simple_MCP_Tools_Wploc::system();
        $out = [];
        foreach (get_taxonomies([], 'objects') as $tx) {
            if (!$tx->public && !$tx->show_ui) continue;
            if (in_array($tx->name, ['nav_menu', 'link_category', 'post_format'], true)) continue;
            $out[] = [
                'name'         => $tx->name,
                'label'        => $tx->label,
                'hierarchical' => (bool) $tx->hierarchical,
                'object_type'  => $tx->object_type,
                'translatable' => $ml && Simple_MCP_Tools_Wploc::is_translatable_taxonomy($tx->name),
            ];
        }
        return $out;
    }
}
