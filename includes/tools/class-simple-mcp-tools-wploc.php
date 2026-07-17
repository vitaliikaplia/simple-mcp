<?php
/**
 * Multilingual toolset — resolve/link/create translations, universal across wp-loc and WPML.
 *
 * Model (both systems): each language is a SEPARATE post/term ID linked by a shared trid in
 * {prefix}icl_translations. element_type = 'post_{type}' for posts, 'tax_{taxonomy}' for terms
 * (where element_id = term_taxonomy_id, NOT term_id). Language codes use the wpml_code ('uk'),
 * which may differ from the URL slug ('ua'). These tools use the WPML-compat filter/action API
 * (which wp-loc shims) so they work on either system, and normalize slug<->wpml_code for you.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Wploc {

    static function defs() {
        return [
            'wploc_get_translations' => [
                'description' => 'Resolve the translation group (trid) and the per-language element IDs for a post/term, so you edit the correct language entity. Each language is a separate ID. For posts, element_type auto-resolves to post_{type}. Returns {trid, default_language, translations:{wpml_code: id}}.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'element_id'   => ['type' => 'integer'],
                        'element_type' => ['type' => 'string', 'description' => 'optional; e.g. post_page, post_news, tax_category (for terms element_id must be term_taxonomy_id)'],
                    ],
                    'required' => ['element_id']],
                'callback' => [__CLASS__, 'get_translations'],
            ],
            'wploc_link_translation' => [
                'description' => 'Link an EXISTING element as the {lang} translation of a source element (same trid). lang accepts a URL slug ("ua") or wpml_code ("uk"); it is normalized. Registers the source in the default language first if needed. Use when both language entities already exist.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'source_id'       => ['type' => 'integer'],
                        'target_id'       => ['type' => 'integer'],
                        'lang'            => ['type' => 'string'],
                        'element_type'    => ['type' => 'string'],
                        'source_language' => ['type' => 'string', 'description' => 'optional; the source\'s language if it is not the site default'],
                    ],
                    'required' => ['source_id', 'target_id', 'lang']],
                'callback' => [__CLASS__, 'link_translation'],
            ],
            'wploc_create_translation' => [
                'description' => 'Duplicate a source POST into a new {lang} post (copying title, block content, excerpt, meta, thumbnail) and link it as a translation. Returns the new post id. If a translation already exists it is returned instead of duplicating. After creating, translate its fields with block_update/update_post on the new id.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'source_id' => ['type' => 'integer'],
                        'lang'      => ['type' => 'string'],
                        'status'    => ['type' => 'string', 'description' => 'default "draft"'],
                    ],
                    'required' => ['source_id', 'lang']],
                'callback' => [__CLASS__, 'create_translation'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    /** Which multilingual system is active. */
    static function system() {
        if (class_exists('WP_LOC')) return 'wp-loc';
        if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) return 'wpml';
        return null;
    }

    /** [code=>slug], [slug=>code], default_code */
    static function lang_map() {
        $c2s = [];
        $s2c = [];
        $default = null;
        $langs = get_option('wp_loc_languages', []);
        if (is_array($langs) && $langs) {
            $defslug = get_option('wp_loc_default_language', '');
            foreach ($langs as $slug => $info) {
                $code = $info['wpml_code'] ?? $slug;
                $c2s[$code] = $slug;
                $s2c[$slug] = $code;
                if ($slug === $defslug) $default = $code;
            }
        }
        if (!$c2s) {
            $active = apply_filters('wpml_active_languages', null);
            if (is_array($active)) {
                foreach ($active as $code => $info) {
                    $c2s[$code] = $code;
                    $s2c[$code] = $code;
                    if (!empty($info['default'])) $default = $code;
                }
            }
        }
        if (!$default) $default = apply_filters('wpml_default_language', null);
        return [$c2s, $s2c, $default];
    }

    /** Normalize a slug or code to the wpml_code. */
    static function to_code($lang) {
        [$c2s, $s2c] = self::lang_map();
        if (isset($s2c[$lang])) return $s2c[$lang];
        if (isset($c2s[$lang])) return $lang;
        return $lang;
    }

    static function resolve_etype($element_id, $given) {
        if ($given) return $given;
        $p = get_post($element_id);
        return $p ? 'post_' . $p->post_type : null;
    }

    static function elem_lang($id, $etype, $default) {
        $code = apply_filters('wpml_element_language_code', null, ['element_id' => $id, 'element_type' => $etype]);
        return $code ?: $default;
    }

    // ── Tools ─────────────────────────────────────────────────────────────

    static function get_translations($args) {
        if (!self::system()) return self::err('No multilingual system (wp-loc / WPML) is active');
        if (!current_user_can('edit_posts')) return Simple_MCP_Tools::err_cap('edit_posts');
        $eid = intval($args['element_id'] ?? 0);
        if (!$eid) return self::err('element_id required');
        $etype = self::resolve_etype($eid, $args['element_type'] ?? null);
        if (!$etype) return self::err('element_type required (e.g. tax_category) — could not auto-resolve');

        [$c2s, , $def] = self::lang_map();
        $trid = apply_filters('wpml_element_trid', null, $eid, $etype);
        if (!$trid) {
            return self::ok(['element_id' => $eid, 'element_type' => $etype, 'trid' => null,
                'default_language' => $def, 'translations' => (object) [],
                'note' => 'element is not registered in any translation group yet']);
        }
        $tr = apply_filters('wpml_get_element_translations', null, $trid, $etype);
        $out = [];
        foreach ((array) $tr as $code => $obj) {
            $id = is_object($obj) ? ($obj->element_id ?? null) : ($obj['element_id'] ?? null);
            $out[$code] = intval($id);
        }
        return self::ok(['element_id' => $eid, 'element_type' => $etype, 'trid' => intval($trid),
            'default_language' => $def, 'translations' => $out]);
    }

    static function link_translation($args) {
        if (!self::system()) return self::err('No multilingual system is active');
        $src = intval($args['source_id'] ?? 0);
        $tgt = intval($args['target_id'] ?? 0);
        if (!$src || !$tgt) return self::err('source_id and target_id required');
        $lang = (string) ($args['lang'] ?? '');
        if ($lang === '') return self::err('lang required');
        $etype = self::resolve_etype($src, $args['element_type'] ?? null);
        if (!$etype) return self::err('element_type required');

        // Нативні права: для постів — edit_post на обидва елементи; для термів — cap таксономії
        if (strpos($etype, 'post_') === 0) {
            foreach ([$src, $tgt] as $pid) {
                if (!Simple_MCP_Tools::can_edit_post($pid)) return Simple_MCP_Tools::err_cap('edit_post #' . $pid);
            }
        } else {
            // etype = 'tax_{taxonomy}' — беремо cap саме цієї таксономії (не хардкодимо manage_categories)
            $tax = strpos($etype, 'tax_') === 0 ? substr($etype, 4) : '';
            $txo = $tax ? get_taxonomy($tax) : null;
            $cap = ($txo && !empty($txo->cap->edit_terms)) ? $txo->cap->edit_terms : 'manage_categories';
            if (!current_user_can($cap)) return Simple_MCP_Tools::err_cap($cap . ' (лінкування термів' . ($tax ? ' ' . $tax : '') . ')');
        }

        [, , $def] = self::lang_map();
        $code = self::to_code($lang);
        // source's real language (explicit param wins; else detected; else site default)
        $src_lang = isset($args['source_language']) ? self::to_code($args['source_language']) : self::elem_lang($src, $etype, $def);
        if ($code === $src_lang) {
            return self::err('target language equals source language (' . $code . ') — a post cannot be its own translation');
        }

        $trid = apply_filters('wpml_element_trid', null, $src, $etype);
        if (!$trid) {
            do_action('wpml_set_element_language_details', ['element_id' => $src, 'element_type' => $etype, 'language_code' => $src_lang]);
            $trid = apply_filters('wpml_element_trid', null, $src, $etype);
        }
        do_action('wpml_set_element_language_details', [
            'element_id' => $tgt, 'element_type' => $etype, 'trid' => $trid,
            'language_code' => $code, 'source_language_code' => $src_lang,
        ]);
        return self::ok(['trid' => intval($trid),
            'linked' => ['id' => $tgt, 'language' => $code],
            'source' => ['id' => $src, 'language' => $src_lang]]);
    }

    static function create_translation($args) {
        if (!self::system()) return self::err('No multilingual system is active');
        $src = intval($args['source_id'] ?? 0);
        if (!$src) return self::err('source_id required');
        $post = get_post($src);
        if (!$post) return self::err('source post not found (create_translation supports posts)');
        $lang = (string) ($args['lang'] ?? '');
        if ($lang === '') return self::err('lang required');
        $status = sanitize_key($args['status'] ?? 'draft');
        $etype = 'post_' . $post->post_type;
        $code = self::to_code($lang);

        // Нативні права: edit_post на джерело (лінкування змінює його групу перекладів)
        // + створення постів цього типу (+ публікація, якщо запитана)
        if (!Simple_MCP_Tools::can_edit_post($src)) return Simple_MCP_Tools::err_cap('edit_post #' . $src);
        $pto = get_post_type_object($post->post_type);
        $create_cap = !empty($pto->cap->create_posts) ? $pto->cap->create_posts : 'edit_posts';
        if (!current_user_can($create_cap)) return Simple_MCP_Tools::err_cap($create_cap . ' (' . $post->post_type . ')');
        if (Simple_MCP_Tools::is_publish_status($status) && !Simple_MCP_Tools::can_publish_type($post->post_type)) {
            return Simple_MCP_Tools::err_cap('publish_posts (' . $post->post_type . ')');
        }

        [, , $def] = self::lang_map();
        $src_lang = self::elem_lang($src, $etype, $def);
        if ($code === $src_lang) {
            return self::err('target language ("' . $code . '") equals the source post language — nothing to create; edit the source directly.');
        }

        // already translated? return it (guard: wpml_object_id returns the source itself for its own language)
        $existing = apply_filters('wpml_object_id', $src, $etype, false, $code);
        if ($existing && intval($existing) !== $src) {
            return self::ok(['new_id' => intval($existing), 'existing' => true, 'language' => $code,
                'trid' => intval(apply_filters('wpml_element_trid', null, $src, $etype))]);
        }

        // NB: omit post_name — let WP generate a per-language slug (copying it yields "slug-2")
        $new = wp_insert_post(wp_slash([
            'post_type' => $post->post_type, 'post_status' => $status,
            'post_title' => $post->post_title, 'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_parent' => $post->post_parent, 'menu_order' => $post->menu_order,
        ]), true);
        if (is_wp_error($new)) return self::err('duplicate failed: ' . $new->get_error_message());

        // copy meta, but skip edit-locks and translation-internal keys; wp_slash on write
        foreach (get_post_meta($src) as $mk => $vals) {
            if (in_array($mk, ['_edit_lock', '_edit_last', '_wp_old_slug'], true)) continue;
            if (preg_match('/^(wpml|_wpml|_icl)/', $mk)) continue;
            foreach ($vals as $v) add_post_meta($new, $mk, wp_slash(maybe_unserialize($v)));
        }

        $link = self::link_translation(['source_id' => $src, 'target_id' => $new, 'lang' => $code, 'element_type' => $etype]);
        if (!empty($link['isError'])) {
            // Дублікат створено, але зареєструвати як переклад не вдалося — не рапортуємо хибний успіх
            $why = $link['content'][0]['text'] ?? 'link error';
            return self::err('Дублікат #' . $new . ' створено, але його не вдалося зареєструвати як переклад: ' . $why);
        }

        return self::ok(['new_id' => $new, 'existing' => false, 'language' => $code,
            'trid' => intval(apply_filters('wpml_element_trid', null, $src, $etype))]);
    }
}
