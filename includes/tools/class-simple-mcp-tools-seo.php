<?php
/**
 * SEO toolset (optional, needs AIOSEO + the wp-loc-aioseo companion plugin).
 *
 * Model: AIOSEO keeps per-post SEO in its own table keyed by post ID, and wp-loc gives each
 * language a SEPARATE post ID — so per-language SEO editing is just "edit the right ID"
 * (resolve with wploc_get_translations). Site-wide localizable strings (title/description
 * templates, breadcrumbs, social homepage) live in AIOSEO options for the default language
 * and in the wp_loc_aioseo_strings option (keyed by language SLUG) for translations.
 *
 * Write path warning: AIOSEO's Post::savePost() resets every field you do NOT pass to its
 * default — we assign model properties directly and ->save() instead, then mirror the
 * _aioseo_* post meta the same way AIOSEO does (they exist "for localization").
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_SEO {

    /** Поля моделі, дозволені до читання/запису */
    const FIELDS = [
        'title', 'description', 'og_title', 'og_description',
        'twitter_title', 'twitter_description', 'twitter_use_og',
        'canonical_url', 'robots_default', 'robots_noindex', 'robots_nofollow',
    ];

    /** Текстові поля, що мають дзеркальну пост-мету _aioseo_* */
    const META_MIRROR = [
        'title'               => '_aioseo_title',
        'description'         => '_aioseo_description',
        'og_title'            => '_aioseo_og_title',
        'og_description'      => '_aioseo_og_description',
        'twitter_title'       => '_aioseo_twitter_title',
        'twitter_description' => '_aioseo_twitter_description',
    ];

    static function defs() {
        return [
            'seo_get' => [
                'description' => 'Read a post\'s AIOSEO fields: title, description, og_*, twitter_*, canonical_url, robots_*. Each LANGUAGE is a separate post ID with its own SEO row — resolve the right ID with wploc_get_translations first. null title/description means AIOSEO falls back to the post-type template (see seo_get_strings base values). twitter_use_og:true means the twitter_* fields are ignored in favour of og_*.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer']],
                    'required'   => ['post_id']],
                'callback' => [__CLASS__, 'get'],
            ],
            'seo_update' => [
                'description' => 'Update AIOSEO fields of ONE post (one language — each language is a separate post ID). Partial-safe: only the fields you pass change; everything else is preserved (unlike AIOSEO\'s own savePost). Allowed fields: title, description, og_title, og_description, twitter_title, twitter_description, twitter_use_og (bool), canonical_url, robots_default (bool), robots_noindex (bool), robots_nofollow (bool). Setting robots_noindex/nofollow true auto-sets robots_default:false (AIOSEO ignores the flags while default is on) — reported in the response. Empty string clears a field back to the template fallback. AIOSEO cache is flushed.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'fields'  => ['type' => 'object', 'description' => 'field => value; unknown fields are rejected with the allowed list'],
                    ],
                    'required' => ['post_id', 'fields']],
                'callback' => [__CLASS__, 'update'],
            ],
            'seo_get_strings' => [
                'description' => 'Read AIOSEO site-wide localizable strings for a language (accepts slug "ua" or wpml_code "uk"). Returns base (the FULL key set with default-language values from AIOSEO settings — the reference for what CAN be translated, buckets "main" and "dynamic") and translations (the stored per-language overrides; keys absent there fall back to base on the front-end).',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['lang' => ['type' => 'string']],
                    'required'   => ['lang']],
                'callback' => [__CLASS__, 'get_strings'],
            ],
            'seo_update_strings' => [
                'description' => 'Translate AIOSEO site-wide strings for a NON-default language. MERGE semantics: only the keys you pass change, the rest of the stored translations survive. Keys must come from seo_get_strings\' base maps (unknown keys are rejected). Pass "" as a value to DELETE a translation (front-end falls back to the default-language value). The default language is rejected — its strings ARE the AIOSEO settings themselves (edit via wp_cli option update aioseo_options_localized or the admin). Cache flush is automatic.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'lang'    => ['type' => 'string'],
                        'main'    => ['type' => 'object', 'description' => 'key => translated string ("" deletes)'],
                        'dynamic' => ['type' => 'object', 'description' => 'key => translated string ("" deletes)'],
                    ],
                    'required' => ['lang']],
                'callback' => [__CLASS__, 'update_strings'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    static function model($post_id) {
        return \AIOSEO\Plugin\Common\Models\Post::getPost($post_id);
    }

    /** Слаг мови зі slug або wpml_code; null якщо мова не активна. */
    static function to_slug($lang) {
        $langs = get_option('wp_loc_languages', []);
        if (!is_array($langs)) return null;
        if (isset($langs[$lang])) return $lang;
        foreach ($langs as $slug => $info) {
            if (($info['wpml_code'] ?? '') === $lang) return $slug;
        }
        return null;
    }

    // ── Tools ─────────────────────────────────────────────────────────────

    static function get($args) {
        if (!function_exists('aioseo')) return self::err('AIOSEO is not active');
        $id = intval($args['post_id'] ?? 0);
        if (!$id || !get_post($id)) return self::err('post not found');
        $p = self::model($id);
        $out = ['post_id' => $id, 'exists_in_aioseo' => (bool) $p->exists()];
        foreach (self::FIELDS as $f) {
            $out[$f] = $p->$f ?? null;
        }
        return self::ok($out);
    }

    static function update($args) {
        if (!function_exists('aioseo')) return self::err('AIOSEO is not active');
        $id = intval($args['post_id'] ?? 0);
        if (!$id || !get_post($id)) return self::err('post not found');
        $fields = $args['fields'] ?? null;
        if (!is_array($fields) || !$fields) return self::err('"fields" object required');

        $unknown = array_diff(array_keys($fields), self::FIELDS);
        if ($unknown) {
            return self::err('unknown field(s): ' . implode(', ', $unknown) . ' — allowed: ' . implode(', ', self::FIELDS));
        }

        $bools = ['twitter_use_og', 'robots_default', 'robots_noindex', 'robots_nofollow'];
        $auto_robots_default = false;
        // robots-прапорці діють лише при robots_default=false
        if ((!empty($fields['robots_noindex']) || !empty($fields['robots_nofollow'])) && !array_key_exists('robots_default', $fields)) {
            $fields['robots_default'] = false;
            $auto_robots_default = true;
        }

        // НЕ savePost(): він скидає всі непередані поля в дефолти. Прямі властивості + save().
        $p = self::model($id);
        $updated = [];
        foreach ($fields as $f => $v) {
            if (in_array($f, $bools, true)) {
                $p->$f = rest_sanitize_boolean($v);
            } else {
                $v = sanitize_text_field((string) $v);
                $p->$f = ($v === '') ? null : $v;
            }
            $updated[] = $f;
        }
        $p->save();

        // Дзеркальна пост-мета (AIOSEO тримає її «for localization») — лише для торканих полів
        foreach (self::META_MIRROR as $f => $meta_key) {
            if (in_array($f, $updated, true)) {
                update_post_meta($id, $meta_key, (string) ($p->$f ?? ''));
            }
        }
        aioseo()->core->cache->clear();

        $fresh = self::model($id);
        $seo = ['post_id' => $id];
        foreach (self::FIELDS as $f) {
            $seo[$f] = $fresh->$f ?? null;
        }
        return self::ok([
            'post_id'             => $id,
            'updated'             => $updated,
            'auto_robots_default' => $auto_robots_default,
            'seo'                 => $seo,
        ]);
    }

    static function get_strings($args) {
        if (!class_exists('WP_LOC_AIOSEO_Options')) return self::err('wp-loc-aioseo is not active');
        $slug = self::to_slug((string) ($args['lang'] ?? ''));
        if (!$slug) return self::err('unknown language — use a wp-loc slug or wpml_code (see describe_site.languages)');
        $default = get_option('wp_loc_default_language', '');
        return self::ok([
            'lang'             => $slug,
            'default_language' => $default,
            'is_default'       => $slug === $default,
            'translations'     => WP_LOC_AIOSEO_Options::get_translations($slug),
            'base'             => [
                'main'    => WP_LOC_AIOSEO_Options::base_localized('main'),
                'dynamic' => WP_LOC_AIOSEO_Options::base_localized('dynamic'),
            ],
        ]);
    }

    static function update_strings($args) {
        if (!class_exists('WP_LOC_AIOSEO_Options')) return self::err('wp-loc-aioseo is not active');
        $slug = self::to_slug((string) ($args['lang'] ?? ''));
        if (!$slug) return self::err('unknown language — use a wp-loc slug or wpml_code');
        if ($slug === get_option('wp_loc_default_language', '')) {
            return self::err('"' . $slug . '" is the DEFAULT language — its strings ARE the AIOSEO settings; edit aioseo_options_localized via wp_cli/admin instead');
        }
        $buckets = [];
        foreach (['main', 'dynamic'] as $b) {
            if (isset($args[$b]) && is_array($args[$b]) && $args[$b]) $buckets[$b] = $args[$b];
        }
        if (!$buckets) return self::err('pass at least one of "main"/"dynamic" with key=>value pairs');

        // Ключі мусять існувати в базовій мапі AIOSEO
        foreach ($buckets as $b => $pairs) {
            $known = array_keys(WP_LOC_AIOSEO_Options::base_localized($b));
            $bad = array_diff(array_keys($pairs), $known);
            if ($bad) {
                return self::err('unknown ' . $b . ' key(s): ' . implode(', ', $bad) . ' — take keys from seo_get_strings.base.' . $b);
            }
        }

        // MERGE: save_translations() замінює весь бакет мови — зливаємо з наявними
        $existing = WP_LOC_AIOSEO_Options::get_translations($slug);
        $merged = $existing;
        $updated = [];
        $cleared = [];
        foreach ($buckets as $b => $pairs) {
            foreach ($pairs as $k => $v) {
                $v = (string) $v;
                $merged[$b][$k] = $v; // "" відфільтрується в save_translations → видалення
                if ($v === '') $cleared[] = "$b.$k";
                else $updated[] = "$b.$k";
            }
        }
        WP_LOC_AIOSEO_Options::save_translations($slug, $merged);
        // кеш AIOSEO чиститься автоматично хуком update_option_wp_loc_aioseo_strings

        $stored = WP_LOC_AIOSEO_Options::get_translations($slug);
        return self::ok([
            'lang'          => $slug,
            'updated'       => $updated,
            'cleared'       => $cleared,
            'stored_counts' => ['main' => count($stored['main']), 'dynamic' => count($stored['dynamic'])],
        ]);
    }
}
