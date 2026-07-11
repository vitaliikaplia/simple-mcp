<?php
/**
 * describe_site — one call that returns this fork's content schema so an AI can self-configure:
 * ACF blocks + their fields, ACF options pages + fields, public CPTs & taxonomies (with
 * translatable flags), and the multilingual language map (slug<->wpml_code). Cached 1h.
 *
 * Note on options: ACF option-page fields are listed precisely (edit via acf_update with
 * post_id 'option' / 'options_{lang}'). Any wp_option NOT listed here is a "plain" option
 * (theme Settings-API/register_setting) — read/write it via wp_cli `wp option get/update`.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Describe {

    const CACHE_KEY = 'simple_mcp_describe';

    static function defs() {
        return [
            'describe_site' => [
                'description' => 'Return this site/fork\'s content schema in one call: acf blocks (+field names/types), acf options pages (+fields), public post types & taxonomies (+translatable flags), and the language map (slug<->wpml_code, default). Call this first on an unfamiliar site to learn exactly which blocks/fields/options/languages exist here, instead of guessing. Cached; pass refresh:true to rebuild.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['refresh' => ['type' => 'boolean']]],
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

    static function languages() {
        $out = ['system' => null, 'default' => null, 'languages' => []];
        if (class_exists('WP_LOC')) $out['system'] = 'wp-loc';
        elseif (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) $out['system'] = 'wpml';
        $langs = get_option('wp_loc_languages', []);
        if (is_array($langs) && $langs) {
            $def_slug = get_option('wp_loc_default_language', '');
            $def_code = ($langs[$def_slug]['wpml_code'] ?? null) ?: apply_filters('wpml_default_language', null);
            $out['default'] = $def_code;
            foreach ($langs as $slug => $info) {
                $code = $info['wpml_code'] ?? $slug;
                $out['languages'][] = [
                    'slug'      => $slug,
                    'wpml_code' => $code,
                    'name'      => $info['display_name'] ?? $slug,
                    'enabled'   => !empty($info['enabled']),
                    'default'   => ($slug === $def_slug || $code === $def_code),
                ];
            }
        } elseif ($out['system']) {
            $active = apply_filters('wpml_active_languages', null);
            if (is_array($active)) {
                foreach ($active as $code => $info) {
                    $out['languages'][] = ['slug' => $code, 'wpml_code' => $code, 'name' => $info['native_name'] ?? $code, 'enabled' => true, 'default' => !empty($info['default'])];
                    if (!empty($info['default'])) $out['default'] = $code;
                }
            }
        }
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
            $out[] = [
                'title'     => $page['page_title'] ?? $menu_slug,
                'menu_slug' => $menu_slug,
                'post_id'   => $page['post_id'] ?? 'options',
                'fields'    => $fields,
            ];
        }
        return $out;
    }

    static function post_types() {
        $skip = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
            'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page'];
        $translatable = (array) get_option('wp_loc_translatable_post_types', ['post', 'page']);
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
                'translatable' => in_array($pt->name, $translatable, true),
            ];
        }
        return $out;
    }

    static function taxonomies() {
        $translatable = (array) get_option('wp_loc_translatable_taxonomies', ['category', 'post_tag']);
        $out = [];
        foreach (get_taxonomies([], 'objects') as $tx) {
            if (!$tx->public && !$tx->show_ui) continue;
            if (in_array($tx->name, ['nav_menu', 'link_category', 'post_format'], true)) continue;
            $out[] = [
                'name'         => $tx->name,
                'label'        => $tx->label,
                'hierarchical' => (bool) $tx->hierarchical,
                'object_type'  => $tx->object_type,
                'translatable' => in_array($tx->name, $translatable, true),
            ];
        }
        return $out;
    }
}
