<?php
/**
 * Multilingual toolset — resolve/link/create translations, universal across wp-loc and WPML.
 *
 * Model (both systems): each language is a SEPARATE post/term ID linked by a shared trid in
 * {prefix}icl_translations. element_type = 'post_{type}' for posts, 'tax_{taxonomy}' for terms.
 * The table stores a term's term_taxonomy_id; these tools take and return TERM IDs (term_id)
 * and resolve the term_taxonomy_id themselves (wp-loc and WPML disagree on which id the
 * compat API expects, so we never hand them an ambiguous id).
 *
 * Languages: the wpml code ('uk') may differ from the URL slug ('ua'). Every language input is
 * resolved strictly (resolve_lang) against the system's RUNTIME language list; only normalized
 * codes are compared or written, so 'UK' / 'uk_UA' / 'en-US' can never open a second row in a slot.
 *
 * Reads go straight to the table (exact rows: no stale caches, duplicate slots are visible);
 * writes go through the system's own API (wp-loc WP_LOC_DB, WPML wpml_set_element_language_details)
 * so its caches and hooks stay consistent, and every write is re-read and verified.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Wploc {

    /** System/internal post types never translated through these tools. */
    const INTERNAL_TYPES = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family', 'wp_font_face'];

    /** Post meta never duplicated into a translation (exact keys / prefixes); extend via the simple_mcp_translation_skip_meta filter. */
    const SKIP_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug',
        '_encloseme', '_pingme', '_yoast_wpseo_canonical', '_wp_attached_file', '_wp_attachment_metadata'];
    const SKIP_META_PREFIX = ['_wp_loc_', '_dp_', '_wp_trash_meta_', '_simple_mcp_', 'wpml', '_wpml', '_icl'];

    /** ACF field types whose stored value holds object IDs that must point at the target language. */
    const ACF_REL_TYPES = ['image', 'file', 'gallery', 'post_object', 'relationship', 'page_link', 'taxonomy'];

    /** Per-request cache of the WPML language list (wpml_active_languages builds switcher URLs; wp-loc caches its own list). */
    private static $wpml_langs = null;

    static function defs() {
        $sync  = self::sync_note();
        $kind  = ['type' => 'string', 'enum' => ['post', 'term'], 'description' => 'post (IDs are post IDs) or term (IDs are term_id). Optional: implied by element_type/taxonomy; without any of them an existing post with that ID wins.'];
        $tax   = ['type' => 'string', 'description' => 'Taxonomy of a term (e.g. category); implies kind "term". Auto-detected from the term_id when omitted.'];
        $etype = ['type' => 'string', 'description' => 'Optional legacy selector: post_{post type} or tax_{taxonomy} (a bare post type / taxonomy name is accepted). Must match the real object.'];
        $lang  = ['type' => 'string', 'description' => 'Target language: wpml code, URL slug or locale ("uk", "ua", "uk_UA", "en-US"). Unknown or disabled languages are refused.'];
        $inact = ['type' => 'boolean', 'description' => 'Allow a language that is configured but disabled (default false).'];

        return [
            'wploc_get_translations' => [
                'title'       => 'Get translations',
                'description' => 'Resolve the translation group of a post or term so you edit the right ID (every language is a separate post/term). '
                    . 'kind "post": element_id is a post ID; kind "term": element_id is a term_id (taxonomy auto-detected or given). '
                    . 'Without kind/element_type/taxonomy an existing post wins; if a term in a translatable taxonomy has the same term_id the result carries a note (pass kind:"term" for the term). '
                    . 'Returns {trid, language, is_original, default_language, translations:{code:{id, status, title, post_type, is_original, language_active}}, missing:[active languages without a translation]}; '
                    . 'for terms each member is {term_id, term_taxonomy_id, name, slug, is_original, language_active}. Codes are wpml codes ("uk"), not URL slugs. '
                    . 'Members you cannot read are listed as {readable:false} without an ID; trashed members show status "trash"; two elements in one language are reported in conflicts. '
                    . 'For posts on wp-loc the result also carries sync_note when saving one translation propagates attributes to the others.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'element_id'   => ['type' => 'integer', 'description' => 'Post ID, or term_id for kind "term".'],
                        'kind'         => $kind,
                        'taxonomy'     => $tax,
                        'element_type' => $etype,
                    ],
                    'required' => ['element_id']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'get_translations'],
            ],
            'wploc_link_translation' => [
                'title'       => 'Link translation',
                'description' => 'Link an EXISTING post or term as the {lang} translation of a source: puts the target into the source\'s translation group (trid). '
                    . 'Both must be the same post type (or taxonomy), translatable, and editable by you (edit_post / edit_term on each); trashed and auto-draft posts are refused. '
                    . 'Refused: source equal to target; lang equal to the source\'s language; a slot already held by another element (replace:true detaches that element into its own group — it is kept, not deleted; the group\'s original cannot be replaced); '
                    . 'a target that belongs to another group with translations (move:true takes it out; if it was that group\'s original, another member is promoted to original); relabeling the group\'s original. '
                    . 'A source without a language needs source_language and is then registered in it (source.registered:true); a source that has one is never relabeled. '
                    . 'The result is re-read from the database: {trid, unchanged, source, target:{id, language, previous}, replaced, moved_from, translations, warnings?}. '
                    . 'On wp-loc with attribute sync on, warnings flags a target whose status/author/password differ from the source\'s: the next save of any member pushes its own values to the whole group.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'source_id'       => ['type' => 'integer', 'description' => 'Post ID or term_id of the source.'],
                        'target_id'       => ['type' => 'integer', 'description' => 'Post ID or term_id to link as the {lang} translation.'],
                        'lang'            => $lang,
                        'kind'            => $kind,
                        'taxonomy'        => $tax,
                        'element_type'    => $etype,
                        'source_language' => ['type' => 'string', 'description' => 'Language of a source that has none registered yet. If the source already has one, it must match.'],
                        'replace'         => ['type' => 'boolean', 'description' => 'Detach the element currently holding the {lang} slot (default false).'],
                        'move'            => ['type' => 'boolean', 'description' => 'Allow taking the target out of another translation group (default false).'],
                        'allow_inactive'  => $inact,
                    ],
                    'required' => ['source_id', 'target_id', 'lang']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'link_translation'],
            ],
            'wploc_create_translation' => [
                'title'       => 'Create translation',
                'description' => 'Create the {lang} translation of a source post by duplicating it, and link the copy into the source\'s translation group. '
                    . 'If the {lang} slot is already held, that translation is returned (existing:true) instead; a trashed post in the slot is an error. '
                    . 'Copies title, content (checked: content_verified), excerpt, author, password, menu_order, comment/ping status, page template and the post meta as stored '
                    . '(except edit locks, old slugs/dates, trash markers, Simple MCP backups, wp-loc/WPML internals). '
                    . 'Maps to their {lang} translations: the parent (if it has none, the call fails unless you pass parent, 0 = top level), the featured image, taxonomy terms of translatable taxonomies (other taxonomies keep the same terms) '
                    . 'and post-level ACF image/file/gallery/post_object/relationship/page_link/taxonomy IDs; IDs without a translation are kept and listed as unmapped. '
                    . 'Status: on wp-loc with attribute sync on, the copy takes the source\'s status and dates (a published source gives a published, still untranslated copy) and a different status is refused, '
                    . 'because saving any translation pushes status, author and password to the whole group; otherwise status defaults to "draft" ("future" copies the source\'s date). '
                    . 'A non-draft copy gets the source\'s slug made unique within {lang}; a draft keeps an empty slug. '
                    . 'Only translatable, non-system post types; trashed sources are refused. A source without a language needs source_language. '
                    . 'kind "term" creates a term translation instead (name/slug/description default to the source\'s; the parent is mapped the same way). '
                    . 'Next: translate the copy with block_update / update_post / acf_update on new_id.'
                    . ($sync ? ' ' . $sync : ''),
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'source_id'       => ['type' => 'integer', 'description' => 'Source post ID (or term_id for kind "term").'],
                        'lang'            => $lang,
                        'kind'            => ['type' => 'string', 'enum' => ['post', 'term'], 'description' => 'post (default) or term.'],
                        'taxonomy'        => $tax,
                        'element_type'    => $etype,
                        'status'          => ['type' => 'string', 'description' => 'Posts: status of the copy (default "draft"; with wp-loc attribute sync it must equal the source\'s status).'],
                        'source_language' => ['type' => 'string', 'description' => 'Language of a source that has none registered yet. If the source already has one, it must match.'],
                        'parent'          => ['type' => 'integer', 'description' => 'Parent of the copy: an existing {lang} post/term ID, or 0 for top level. Default: the {lang} translation of the source\'s parent.'],
                        'name'            => ['type' => 'string', 'description' => 'Terms only: name of the new term (default: the source\'s name).'],
                        'slug'            => ['type' => 'string', 'description' => 'Terms only: slug of the new term (default: the source\'s slug, made unique).'],
                        'description'     => ['type' => 'string', 'description' => 'Terms only: description (default: the source\'s).'],
                        'allow_inactive'  => $inact,
                    ],
                    'required' => ['source_id', 'lang']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'create_translation'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    // ── System & languages ───────────────────────────────────────────────

    /** Which multilingual system is active: 'wp-loc' | 'wpml' | null. */
    static function system() {
        if (class_exists('Simple_MCP') && method_exists('Simple_MCP', 'multilingual_system')) return Simple_MCP::multilingual_system();
        if (class_exists('WP_LOC')) return 'wp-loc';
        if (defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress')) return 'wpml';
        return null;
    }

    /** wp-loc DB API object, or null (then the WPML-compatible API is used). */
    static function wploc_db() {
        if (self::system() !== 'wp-loc' || !class_exists('WP_LOC')) return null;
        $i = WP_LOC::instance();
        return isset($i->db) && is_object($i->db) ? $i->db : null;
    }

    /** Comparison form of a code/slug/locale: lowercase, '-' → '_'. */
    static function lnorm($s) {
        return strtolower(str_replace('-', '_', trim((string) $s)));
    }

    /**
     * Configured languages from the RUNTIME API, keyed by wpml code:
     * code => ['code', 'slug', 'locale', 'name', 'active', 'default'].
     * wp-loc: every configured language (disabled ones have active:false); WPML: active languages.
     */
    static function languages() {
        $out = [];
        $sys = self::system();
        if ($sys === 'wp-loc' && class_exists('WP_LOC_Languages')) {
            $def = WP_LOC_Languages::get_default_language();
            foreach ((array) WP_LOC_Languages::get_languages() as $slug => $d) {
                if (!is_array($d)) continue;
                $slug = (string) $slug;
                $code = strtolower(class_exists('WP_LOC_DB') ? (string) (WP_LOC_DB::to_db_language_code($slug) ?: $slug) : $slug);
                if ($code === '' || isset($out[$code])) continue;
                $out[$code] = ['code' => $code, 'slug' => $slug, 'locale' => (string) ($d['locale'] ?? ''),
                    'name' => (string) ($d['display_name'] ?? $slug), 'active' => !empty($d['enabled']), 'default' => $slug === $def];
            }
        } elseif ($sys === 'wpml') {
            if (self::$wpml_langs !== null) return self::$wpml_langs;
            $def = strtolower((string) apply_filters('wpml_default_language', null));
            $list = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            if (!$list && isset($GLOBALS['sitepress']) && is_object($GLOBALS['sitepress']) && method_exists($GLOBALS['sitepress'], 'get_active_languages')) {
                $list = $GLOBALS['sitepress']->get_active_languages(); // the language-switcher list can be empty early in a request
            }
            foreach ((array) $list as $c => $d) {
                $d    = (array) $d;
                $code = strtolower(trim((string) (!empty($d['code']) ? $d['code'] : $c)));
                if ($code === '' || isset($out[$code])) continue;
                $out[$code] = ['code' => $code, 'slug' => $code, 'locale' => (string) ($d['default_locale'] ?? ''),
                    'name' => (string) ($d['native_name'] ?? ($d['translated_name'] ?? $code)), 'active' => true, 'default' => $code === $def];
            }
            if ($out) self::$wpml_langs = $out;
        }
        return $out;
    }

    /** wpml code of the default language. */
    static function default_code() {
        foreach (self::languages() as $code => $l) {
            if ($l['default']) return $code;
        }
        $d = apply_filters('wpml_default_language', null);
        return $d ? strtolower((string) $d) : null;
    }

    /** "uk (slug ua, default), en (slug en, disabled)" — for error messages. */
    static function lang_list() {
        $parts = [];
        foreach (self::languages() as $code => $l) {
            $flags = [];
            if ($l['slug'] !== $code) $flags[] = 'slug ' . $l['slug'];
            if ($l['default']) $flags[] = 'default';
            if (!$l['active']) $flags[] = 'disabled';
            $parts[] = $code . ($flags ? ' (' . implode(', ', $flags) . ')' : '');
        }
        return $parts ? implode(', ', $parts) : 'none configured';
    }

    /**
     * Strictly resolve a language given as wpml code ('uk'), URL slug ('ua'), locale ('uk_UA',
     * 'en-US') or a case variant ('UK'). Matching order: slug, code, locale, then the primary
     * subtag when it identifies exactly one language. Returns
     * ['code', 'slug', 'default', 'active', 'locale', 'name'] or WP_Error listing the valid
     * languages. Disabled languages only with $allow_inactive.
     */
    static function resolve_lang($lang, $allow_inactive = false) {
        $raw = is_scalar($lang) ? trim((string) $lang) : '';
        if (!self::system()) return new WP_Error('no_ml', 'No multilingual system (wp-loc / WPML) is active.');
        $langs = self::languages();
        if ($raw === '') return new WP_Error('bad_lang', 'A language is required. Valid languages: ' . self::lang_list() . '.');
        $n = self::lnorm($raw);
        $match = null;
        foreach (['slug', 'code', 'locale'] as $field) {
            foreach ($langs as $l) {
                if ($l[$field] !== '' && self::lnorm($l[$field]) === $n) { $match = $l; break 2; }
            }
        }
        if (!$match && strpos($n, '_') !== false) {
            $p = explode('_', $n)[0];
            $cands = [];
            foreach ($langs as $code => $l) {
                foreach ([$l['slug'], $l['code'], $l['locale']] as $v) {
                    if ($v !== '' && explode('_', self::lnorm($v))[0] === $p) { $cands[$code] = $l; break; }
                }
            }
            if (count($cands) === 1) $match = reset($cands);
        }
        $shown = function_exists('mb_substr') ? mb_substr($raw, 0, 40) : substr($raw, 0, 40);
        if (!$match) {
            return new WP_Error('bad_lang', 'Unknown language "' . $shown . '". Valid languages: ' . self::lang_list() . '.');
        }
        if (!$match['active'] && !$allow_inactive) {
            return new WP_Error('inactive_lang', 'Language "' . $match['code'] . '" is configured but disabled on this site. '
                . 'Pass allow_inactive:true to prepare content for it anyway. Valid languages: ' . self::lang_list() . '.');
        }
        return ['code' => $match['code'], 'slug' => $match['slug'], 'default' => (bool) $match['default'],
            'active' => (bool) $match['active'], 'locale' => $match['locale'], 'name' => $match['name']];
    }

    /**
     * ACF options post_id for a language (and an options page's base post_id):
     * wp-loc → the base for the default language, '{base}_{slug}' otherwise (wp-loc routes options by URL slug);
     * WPML → ACF appends '_{code}' to 'options'; ACFML (WPML's ACF add-on) also appends it to a custom
     * options-page post_id unless that starts with options/term_/block_/user_/widget_/{taxonomy}_.
     * Any other base stays unchanged. Disabled languages are accepted (content may be prepared for them). string|WP_Error
     */
    static function options_post_id($lang, $base = 'options') {
        $base = ((string) $base === '' || $base === 'option') ? 'options' : (string) $base;
        $l = self::resolve_lang($lang, true);
        if (is_wp_error($l)) return $l;
        if ($l['default']) return $base;
        if (self::system() === 'wp-loc') return $base . '_' . $l['slug'];
        if ($base === 'options') return 'options_' . $l['code'];
        if (defined('ACFML_VERSION') && !preg_match('/^(options|term_|block_|user_|widget_)/', $base)) {
            foreach (get_taxonomies([], 'names') as $tx) {
                if (strpos($base, $tx . '_') === 0) return $base;
            }
            return $base . '_' . $l['code']; // ACFML\Options\CustomNamespacesHooks
        }
        return $base;
    }

    /** Back-compat: [code => slug], [slug => code], default code (all configured languages). */
    static function lang_map() {
        $c2s = [];
        $s2c = [];
        foreach (self::languages() as $code => $l) {
            $c2s[$code] = $l['slug'];
            $s2c[$l['slug']] = $code;
        }
        return [$c2s, $s2c, self::default_code()];
    }

    /** Back-compat: normalize a slug/code/locale to the wpml code (input returned trimmed if unknown). */
    static function to_code($lang) {
        $l = self::resolve_lang($lang, true);
        return is_wp_error($l) ? trim((string) $lang) : $l['code'];
    }

    /** Normalize a code read from the table (wp-loc may hold legacy slugs such as 'ua'). */
    static function norm_code($c) {
        $c = strtolower(trim((string) $c));
        if ($c === '') return null;
        if (self::system() === 'wp-loc' && class_exists('WP_LOC_DB')) {
            $n = WP_LOC_DB::to_db_language_code($c);
            return $n ? strtolower((string) $n) : $c;
        }
        return $c;
    }

    /** Language argument for wp-loc's DB API: the slug of a configured language, else the code. */
    static function wploc_lang_arg($code) {
        $langs = self::languages();
        return isset($langs[$code]) ? $langs[$code]['slug'] : (string) $code;
    }

    // ── Sync (wp-loc) ─────────────────────────────────────────────────────

    /** wp-loc pushes status/author/password/parent/template from a saved post to all its translations. */
    static function attr_sync_on() {
        return self::system() === 'wp-loc' && class_exists('WP_LOC_Admin_Settings')
            && WP_LOC_Admin_Settings::should_sync_post_attributes();
    }

    /**
     * One-line warning when wp-loc propagates attributes between translations; null otherwise.
     * Other modules append it to update_post / block_update descriptions.
     */
    static function sync_note() {
        if (self::system() !== 'wp-loc' || !class_exists('WP_LOC_Admin_Settings')) return null;
        $parts = [];
        if (WP_LOC_Admin_Settings::should_sync_post_attributes()) $parts[] = 'status, author, password, menu_order, page template and parent (mapped per language)';
        if (WP_LOC_Admin_Settings::should_sync_post_taxonomies()) $parts[] = 'terms of translatable taxonomies (mapped, replacing theirs)';
        if (method_exists('WP_LOC_Admin_Settings', 'should_sync_featured_image') && WP_LOC_Admin_Settings::should_sync_featured_image()) $parts[] = 'featured-image changes (mapped)';
        if (!$parts) return null;
        return 'wp-loc sync is ON: saving ANY translation of a post (block_update, update_post, …) pushes to every other translation in its group, the source included: '
            . implode('; ', $parts) . '.';
    }

    // ── Elements ─────────────────────────────────────────────────────────

    /**
     * Normalize element_type the way wp-loc does: nav_menu / tax_* / taxonomy name / post_* / post type name.
     * '' when empty; WP_Error for anything that is not an existing post type or taxonomy.
     */
    static function norm_etype($t) {
        $t = trim((string) $t);
        if ($t === '') return '';
        $orig = $t;
        if ($t === 'nav_menu') {
            $t = 'tax_nav_menu';
        } elseif (strpos($t, 'tax_') !== 0 && taxonomy_exists($t)) {
            $t = 'tax_' . $t;
        } elseif (strpos($t, 'tax_') !== 0 && strpos($t, 'post_') !== 0 && post_type_exists($t)) {
            $t = 'post_' . $t;
        }
        if (strpos($t, 'tax_') === 0 && taxonomy_exists(substr($t, 4))) return $t;
        if (strpos($t, 'post_') === 0 && post_type_exists(substr($t, 5))) return $t;
        if (post_type_exists($orig)) return 'post_' . $orig;
        return new WP_Error('bad_etype', 'Unknown element_type "' . substr($orig, 0, 60) . '": expected post_{post type} or tax_{taxonomy} of an existing type.');
    }

    /** ['term_id', 'term_taxonomy_id'] of a term_id in a taxonomy (raw: language filters never swap the term). */
    static function term_row($term_id, $tax) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
            (int) $term_id, (string) $tax
        ), ARRAY_A);
        return $r ? ['term_id' => (int) $r['term_id'], 'term_taxonomy_id' => (int) $r['term_taxonomy_id']] : null;
    }

    static function term_row_by_tt($tt_id, $tax) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s LIMIT 1",
            (int) $tt_id, (string) $tax
        ), ARRAY_A);
        return $r ? ['term_id' => (int) $r['term_id'], 'term_taxonomy_id' => (int) $r['term_taxonomy_id']] : null;
    }

    /**
     * Registered taxonomies a term_id belongs to (normally one). Rows of taxonomies nobody registers
     * any more (e.g. WPML's translation_priority left behind after a migration) are ignored, so they
     * never make a post ID look ambiguous.
     */
    static function term_taxonomies($term_id) {
        global $wpdb;
        $taxes = array_unique(array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d", (int) $term_id
        ))));
        return array_values(array_filter($taxes, 'taxonomy_exists'));
    }

    /** Raw name/slug/description/parent of a term. */
    static function term_info($term_id, $tax) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT t.name, t.slug, tt.description, tt.parent FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE t.term_id = %d AND tt.taxonomy = %s LIMIT 1",
            (int) $term_id, (string) $tax
        ), ARRAY_A);
        return $r ? ['name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'description' => (string) $r['description'], 'parent' => (int) $r['parent']] : null;
    }

    /**
     * Resolve an element from tool args (kind / taxonomy / element_type) to
     * ['kind', 'id' (post ID|term_id), 'eid' (post ID|term_taxonomy_id), 'etype', 'type' (post type|taxonomy), 'note'].
     * WP_Error when it does not exist, does not match the given type, or the ID is ambiguous.
     * $tt_fallback (reads only): an ID that is no term_id in the taxonomy may be read as a
     * term_taxonomy_id, as earlier versions documented.
     */
    static function resolve_element($id, $args, $default_kind = null, $tt_fallback = false) {
        $id = (int) $id;
        if ($id <= 0) return new WP_Error('bad_id', 'A positive element ID is required.');
        $kind = isset($args['kind']) && $args['kind'] !== '' ? (string) $args['kind'] : null;
        if ($kind !== null && !in_array($kind, ['post', 'term'], true)) return new WP_Error('bad_kind', 'kind must be "post" or "term".');
        $etype = self::norm_etype($args['element_type'] ?? '');
        if (is_wp_error($etype)) return $etype;
        $tax = isset($args['taxonomy']) && $args['taxonomy'] !== '' ? sanitize_key((string) $args['taxonomy']) : '';
        if ($tax !== '' && !taxonomy_exists($tax)) return new WP_Error('bad_tax', 'Unknown taxonomy "' . $tax . '".');
        if ($etype !== '') {
            $ek = strpos($etype, 'tax_') === 0 ? 'term' : 'post';
            if ($kind !== null && $kind !== $ek) return new WP_Error('kind_conflict', 'kind "' . $kind . '" contradicts element_type "' . $etype . '".');
            $kind = $ek;
            if ($ek === 'term') {
                $et = substr($etype, 4);
                if ($tax !== '' && $tax !== $et) return new WP_Error('kind_conflict', 'taxonomy "' . $tax . '" contradicts element_type "' . $etype . '".');
                $tax = $et;
            }
        }
        if ($tax !== '') {
            if ($kind === 'post') return new WP_Error('kind_conflict', 'taxonomy is only valid for kind "term".');
            $kind = 'term';
        }
        if ($kind === null) $kind = $default_kind;
        $note = null;
        if ($kind === null) {
            // Post IDs and term_ids are independent sequences: without kind an existing post wins (as in
            // 2.4.0); a term in a translatable taxonomy with the same ID is only mentioned in the note.
            $p = get_post($id);
            $taxes = self::term_taxonomies($id);
            if (!$p && !$taxes) return new WP_Error('not_found', 'No post or term with ID ' . $id . '.');
            $kind = $p ? 'post' : 'term';
            $also = $p ? array_values(array_filter($taxes, [__CLASS__, 'is_translatable_taxonomy'])) : [];
            if ($also) {
                $note = 'ID ' . $id . ' was resolved as the post (' . $p->post_type . '); a term with the same term_id exists in '
                    . implode(', ', $also) . ' — pass kind:"term" for it.';
            }
        }
        if ($kind === 'post') {
            $p = get_post($id);
            if (!$p) return new WP_Error('not_found', 'Post #' . $id . ' not found (for a term pass kind:"term").');
            if ($etype !== '' && $etype !== 'post_' . $p->post_type) {
                return new WP_Error('etype_mismatch', 'element_type "' . $etype . '" does not match post #' . $id . ' (post type "' . $p->post_type . '").');
            }
            return ['kind' => 'post', 'id' => (int) $p->ID, 'eid' => (int) $p->ID, 'etype' => 'post_' . $p->post_type, 'type' => $p->post_type, 'note' => $note];
        }
        if ($tax === '') {
            $taxes = self::term_taxonomies($id);
            if (!$taxes) return new WP_Error('not_found', 'Term ' . $id . ' not found.');
            if (count($taxes) > 1) return new WP_Error('ambiguous_tax', 'term_id ' . $id . ' exists in several taxonomies (' . implode(', ', $taxes) . '); pass taxonomy.');
            $tax = $taxes[0];
        }
        $row = self::term_row($id, $tax);
        if (!$row) {
            $row = $tt_fallback ? self::term_row_by_tt($id, $tax) : null;
            if (!$row) return new WP_Error('not_found', 'Term ' . $id . ' (term_id) not found in taxonomy "' . $tax . '".');
            $note = 'ID ' . $id . ' is not a term_id in "' . $tax . '"; it was read as a term_taxonomy_id (term_id ' . $row['term_id'] . '). Pass term IDs.';
        }
        return ['kind' => 'term', 'id' => $row['term_id'], 'eid' => $row['term_taxonomy_id'], 'etype' => 'tax_' . $tax, 'type' => $tax, 'note' => $note];
    }

    /** Element reference from an (element_id, element_type) pair used by the PHP contracts; posts need not exist any more. */
    static function contract_ref($element_id, $etype) {
        $etype = self::norm_etype($etype);
        if (is_wp_error($etype)) return $etype;
        if ($etype === '') return new WP_Error('bad_etype', 'element_type is required.');
        if (strpos($etype, 'tax_') === 0) {
            $tax = substr($etype, 4);
            $row = self::term_row($element_id, $tax);
            if (!$row) return new WP_Error('not_found', 'Term ' . (int) $element_id . ' not found in taxonomy "' . $tax . '".');
            return ['kind' => 'term', 'id' => $row['term_id'], 'eid' => $row['term_taxonomy_id'], 'etype' => $etype, 'type' => $tax, 'note' => null];
        }
        return ['kind' => 'post', 'id' => (int) $element_id, 'eid' => (int) $element_id, 'etype' => $etype, 'type' => substr($etype, 5), 'note' => null];
    }

    /** Post type translatable on this site (system filters) and not a system type. */
    static function is_translatable_post_type($pt) {
        $pt = (string) $pt;
        if ($pt === '' || !post_type_exists($pt) || in_array($pt, self::INTERNAL_TYPES, true) || strpos($pt, 'acf-') === 0) return false;
        switch (self::system()) {
            case 'wp-loc':
                if (class_exists('WP_LOC_Admin_Settings')) return WP_LOC_Admin_Settings::is_translatable($pt);
                return in_array($pt, (array) apply_filters('wp_loc_translatable_post_types', ['post', 'page']), true);
            case 'wpml':
                return (bool) apply_filters('wpml_is_translated_post_type', null, $pt);
        }
        return false;
    }

    /** Taxonomy translatable on this site (system filters; wp-loc also translates nav menus). */
    static function is_translatable_taxonomy($tax) {
        $tax = (string) $tax;
        if ($tax === '' || !taxonomy_exists($tax)) return false;
        switch (self::system()) {
            case 'wp-loc':
                if ($tax === 'nav_menu') return class_exists('WP_LOC_Menus');
                if (class_exists('WP_LOC_Terms')) return WP_LOC_Terms::is_translatable($tax);
                return in_array($tax, (array) apply_filters('wp_loc_translatable_taxonomies', ['category', 'post_tag']), true);
            case 'wpml':
                return (bool) apply_filters('wpml_is_translated_taxonomy', null, $tax);
        }
        return false;
    }

    static function ref_translatable($ref) {
        return $ref['kind'] === 'post' ? self::is_translatable_post_type($ref['type']) : self::is_translatable_taxonomy($ref['type']);
    }

    /** Can the current user read this term (public taxonomy, or edit_term)? */
    static function can_read_term($term_id, $tax) {
        $t = get_taxonomy($tax);
        if ($t && ($t->public || $t->publicly_queryable)) return true;
        return current_user_can('edit_term', (int) $term_id);
    }

    /** Object-level edit right: edit_post for posts, edit_term for terms. true or the missing cap (for err_cap). */
    static function can_edit_ref($ref) {
        if ($ref['kind'] === 'post') return Simple_MCP_Tools::can_edit_post($ref['id']) ?: 'edit_post #' . $ref['id'];
        return current_user_can('edit_term', $ref['id']) ?: 'edit_term #' . $ref['id'] . ' (' . $ref['type'] . ')';
    }

    /** Public ID of a table element: post ID, or term_id for a term_taxonomy_id. */
    static function public_id($eid, $etype) {
        if (strpos($etype, 'tax_') !== 0) return (int) $eid;
        $r = self::term_row_by_tt($eid, substr($etype, 4));
        return $r ? $r['term_id'] : 0;
    }

    // ── icl_translations reads (direct, normalized codes) ────────────────

    static function table() {
        global $wpdb;
        return $wpdb->prefix . 'icl_translations';
    }

    static function shape_row($r) {
        $src = isset($r['source_language_code']) ? self::norm_code($r['source_language_code']) : null;
        return ['translation_id' => (int) $r['translation_id'], 'element_id' => (int) $r['element_id'], 'trid' => (int) $r['trid'],
            'language' => self::norm_code($r['language_code']), 'source_language' => $src];
    }

    /** The element's own row ['translation_id', 'element_id', 'trid', 'language', 'source_language'] or null. */
    static function row($eid, $etype) {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT translation_id, element_id, trid, language_code, source_language_code FROM " . self::table()
            . " WHERE element_id = %d AND element_type = %s ORDER BY translation_id LIMIT 1",
            (int) $eid, (string) $etype
        ), ARRAY_A);
        return $r ? self::shape_row($r) : null;
    }

    /** All rows of a group for this element type (WPML placeholder rows without an element are skipped). */
    static function group($trid, $etype) {
        global $wpdb;
        if (!$trid) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT translation_id, element_id, trid, language_code, source_language_code FROM " . self::table()
            . " WHERE trid = %d AND element_type = %s ORDER BY translation_id",
            (int) $trid, (string) $etype
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            if ((int) $r['element_id'] > 0) $out[] = self::shape_row($r);
        }
        return $out;
    }

    /** The group's original row (source_language null), or null. */
    static function original_row($rows) {
        foreach ($rows as $r) {
            if ($r['source_language'] === null) return $r;
        }
        return null;
    }

    /** Rows of a group holding a language. */
    static function rows_in_lang($rows, $code) {
        return array_values(array_filter($rows, function ($r) use ($code) { return $r['language'] === $code; }));
    }

    /** [code => public id] of a group (first row per language). */
    static function group_map($rows, $etype) {
        $out = [];
        foreach ($rows as $r) {
            if (!isset($out[$r['language']])) $out[$r['language']] = self::public_id($r['element_id'], $etype);
        }
        return $out;
    }

    // ── Writes (through the system API) ──────────────────────────────────

    /**
     * Write one element's row. $trid null = a new group of its own; $source null = original.
     * wp-loc: WP_LOC_DB::set_element_language (it keeps an existing trid when given null, so the row
     * is dropped first). WPML: wpml_set_element_language_details, always with the 'trid' key.
     */
    static function set_lang($eid, $etype, $code, $trid, $source) {
        $db = self::wploc_db();
        if ($db) {
            if (!$trid && self::row($eid, $etype)) $db->delete_element((int) $eid, (string) $etype);
            $db->set_element_language((int) $eid, (string) $etype, self::wploc_lang_arg($code), $trid ? (int) $trid : null,
                $source !== null ? self::wploc_lang_arg($source) : null);
            return;
        }
        do_action('wpml_set_element_language_details', [
            'element_id'           => (int) $eid,
            'element_type'         => (string) $etype,
            'trid'                 => $trid ? (int) $trid : null,
            'language_code'        => (string) $code,
            'source_language_code' => $source,
        ]);
    }

    /** WPML: refresh its in-memory/object caches after a direct table write (what WPML itself does). */
    static function wpml_after_sql($trid, $eid, $etype) {
        global $wpml_post_translations, $wpml_term_translations;
        foreach ([$wpml_post_translations, $wpml_term_translations] as $o) {
            if (is_object($o) && method_exists($o, 'reload')) $o->reload();
        }
        if ($eid) wp_cache_delete($eid . ':' . $etype, 'element_language_details');
        do_action('wpml_translation_update', ['type' => 'update', 'trid' => (int) $trid, 'element_id' => (int) $eid,
            'element_type' => $etype, 'context' => strpos($etype, 'tax_') === 0 ? 'tax' : 'post']);
    }

    /** Remove a row that points at an element which no longer exists. */
    static function drop_row($row, $etype) {
        $db = self::wploc_db();
        if ($db) {
            $db->delete_element((int) $row['element_id'], (string) $etype);
            return;
        }
        global $wpdb;
        $wpdb->delete(self::table(), ['translation_id' => (int) $row['translation_id']], ['%d']);
        self::wpml_after_sql($row['trid'], $row['element_id'], $etype);
    }

    /**
     * After the original left a group: promote one remaining member (default language first,
     * then language order, then the oldest row) and point the others' source at it.
     * Returns ['language', 'id'] or null when nothing had to change.
     */
    static function promote_original($trid, $etype) {
        $rows = self::group($trid, $etype);
        if (!$rows || self::original_row($rows)) return null;
        $pick = null;
        $order = array_keys(self::languages());
        array_unshift($order, (string) self::default_code());
        foreach ($order as $c) {
            foreach ($rows as $r) {
                if ($r['language'] === $c) { $pick = $r; break 2; }
            }
        }
        if (!$pick) $pick = $rows[0];
        self::make_original($trid, $etype, $pick);
        return ['language' => $pick['language'], 'id' => self::public_id($pick['element_id'], $etype)];
    }

    /**
     * Make $orig (a row of the group) its original: its source_language becomes null and every
     * other member's source points at its language. wp-loc: through WP_LOC_DB; WPML: its API
     * cannot null a source inside an existing group, so the rows are updated the way WPML does it.
     */
    static function make_original($trid, $etype, $orig) {
        if (self::wploc_db()) {
            foreach (self::group($trid, $etype) as $r) {
                self::set_lang($r['element_id'], $etype, $r['language'], $trid, $r['translation_id'] === $orig['translation_id'] ? null : $orig['language']);
            }
            return;
        }
        global $wpdb;
        $wpdb->update(self::table(), ['source_language_code' => $orig['language']], ['trid' => (int) $trid, 'element_type' => $etype]);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET source_language_code = NULL WHERE translation_id = %d', $orig['translation_id']));
        self::wpml_after_sql($trid, 0, $etype);
    }

    /** Give a registered element a group of its own (keeping its language); promote a new original in the old group if needed. */
    static function detach_row($eid, $etype, $row) {
        self::set_lang($eid, $etype, $row['language'], null, null);
        $new = self::row($eid, $etype);
        if (!$new || $new['trid'] === $row['trid'] || $new['language'] !== $row['language']) {
            return new WP_Error('detach_failed', 'Could not take element ' . self::public_id($eid, $etype) . ' out of translation group ' . $row['trid'] . '.');
        }
        $promoted = $row['source_language'] === null ? self::promote_original($row['trid'], $etype) : null;
        return ['trid' => $row['trid'], 'language' => $row['language'], 'source_language' => $row['source_language'],
            'detached' => true, 'new_trid' => $new['trid'], 'new_original' => $promoted];
    }

    /** Serialize translation-group changes across parallel MCP calls (MySQL named lock, re-entrant). */
    static function lock() {
        global $wpdb;
        $name = 'smcp_wploc_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id());
        return ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 10))) === 1 ? $name : null;
    }

    static function unlock($name) {
        global $wpdb;
        if ($name) $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    static function with_group_lock(callable $fn) {
        $lock = self::lock();
        if (!$lock) return self::err('Another translation change is in progress — retry in a few seconds.');
        try {
            return $fn();
        } finally {
            self::unlock($lock);
        }
    }

    // ── CONTRACTS used by other modules (content / posts / core) ─────────

    /** Other-language siblings of an element: [code => post ID | term_id] (self and empty rows excluded). */
    static function siblings($element_id, $etype) {
        $ref = self::contract_ref($element_id, $etype);
        if (is_wp_error($ref)) return [];
        $row = self::row($ref['eid'], $ref['etype']);
        if (!$row) return [];
        $out = [];
        foreach (self::group($row['trid'], $ref['etype']) as $r) {
            if ($r['element_id'] === $ref['eid'] || isset($out[$r['language']])) continue;
            $id = self::public_id($r['element_id'], $ref['etype']);
            if ($id) $out[$r['language']] = $id;
        }
        return $out;
    }

    /**
     * Take an element out of its translation group so deleting it can never cascade to siblings
     * (wp-loc hard-deletes every member of a group when one is permanently deleted). The element
     * keeps its language in a group of its own; if it was the original, another member is promoted.
     * element_id: post ID, or term_id for tax_* types. Returns
     * ['trid', 'language', 'source_language', 'detached', 'new_trid', 'new_original'] — trid/language
     * are what it had (null when it was not registered) — or WP_Error.
     */
    static function detach_element($element_id, $etype) {
        if (!self::system()) return new WP_Error('no_ml', 'No multilingual system is active.');
        $ref = self::contract_ref($element_id, $etype);
        if (is_wp_error($ref)) return $ref;
        $lock = self::lock();
        if (!$lock) return new WP_Error('locked', 'Another translation change is in progress — retry in a few seconds.');
        try {
            $row = self::row($ref['eid'], $ref['etype']);
            if (!$row) return ['trid' => null, 'language' => null, 'source_language' => null, 'detached' => false, 'new_trid' => null, 'new_original' => null];
            $others = array_filter(self::group($row['trid'], $ref['etype']), function ($r) use ($ref) { return $r['element_id'] !== $ref['eid']; });
            if (!$others) {
                return ['trid' => $row['trid'], 'language' => $row['language'], 'source_language' => $row['source_language'],
                    'detached' => false, 'new_trid' => $row['trid'], 'new_original' => null];
            }
            return self::detach_row($ref['eid'], $ref['etype'], $row);
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Undo detach_element(): put the element back into its former group with its former language,
     * and when it was the group's original make it the original again (the member detach_element
     * promoted becomes a translation). $prev = the array detach_element() returned. true|WP_Error
     * (refused when another element took its language slot in the meantime).
     */
    static function reattach_element($element_id, $etype, $prev) {
        if (!self::system()) return new WP_Error('no_ml', 'No multilingual system is active.');
        $ref = self::contract_ref($element_id, $etype);
        if (is_wp_error($ref)) return $ref;
        if (!is_array($prev)) return new WP_Error('nothing_to_restore', 'Pass the array detach_element() returned.');
        $trid = (int) ($prev['trid'] ?? 0);
        $code = self::norm_code($prev['language'] ?? '');
        if (!$trid || !$code) return new WP_Error('nothing_to_restore', 'The element was not in a translation group before; nothing to restore.');
        $lock = self::lock();
        if (!$lock) return new WP_Error('locked', 'Another translation change is in progress — retry in a few seconds.');
        try {
            $others = array_values(array_filter(self::group($trid, $ref['etype']), function ($r) use ($ref) { return $r['element_id'] !== $ref['eid']; }));
            foreach (self::rows_in_lang($others, $code) as $o) {
                return new WP_Error('slot_taken', 'The "' . $code . '" slot of group ' . $trid . ' is now held by element ' . self::public_id($o['element_id'], $ref['etype']) . '; relink manually with wploc_link_translation.');
            }
            $was_original = !array_key_exists('source_language', (array) $prev) || $prev['source_language'] === null;
            $orig = self::original_row($others);
            self::set_lang($ref['eid'], $ref['etype'], $code, $trid, $was_original ? null : ($orig ? $orig['language'] : self::norm_code($prev['source_language'])));
            $row = self::row($ref['eid'], $ref['etype']);
            if (!$row || $row['trid'] !== $trid || $row['language'] !== $code) {
                return new WP_Error('reattach_failed', 'Could not put element ' . $ref['id'] . ' back into translation group ' . $trid . '.');
            }
            if ($was_original && $others) self::make_original($trid, $ref['etype'], $row);
            return true;
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Register a freshly inserted post in a language (a group of its own) and clear wp-loc's
     * _wp_loc_is_new flag, so wp-loc never re-registers it in the admin language and auto-creates
     * sibling drafts on its next save. A post already registered alone in its group is relabeled;
     * one that already has translations is refused. true|WP_Error
     */
    static function register_post_language($post_id, $code) {
        if (!self::system()) return new WP_Error('no_ml', 'No multilingual system is active.');
        $p = get_post((int) $post_id);
        if (!$p) return new WP_Error('not_found', 'Post #' . (int) $post_id . ' not found.');
        if (!self::is_translatable_post_type($p->post_type)) {
            return new WP_Error('not_translatable', 'Post type "' . $p->post_type . '" is not translatable on this site.');
        }
        $l = self::resolve_lang($code, true);
        if (is_wp_error($l)) return $l;
        $etype = 'post_' . $p->post_type;
        $lock = self::lock();
        if (!$lock) return new WP_Error('locked', 'Another translation change is in progress — retry in a few seconds.');
        try {
            $row = self::row($p->ID, $etype);
            if ($row && $row['language'] !== $l['code']) {
                $others = array_filter(self::group($row['trid'], $etype), function ($r) use ($p) { return $r['element_id'] !== (int) $p->ID; });
                if ($others) {
                    return new WP_Error('already_registered', 'Post #' . $p->ID . ' is already registered as "' . $row['language'] . '" with translations (trid '
                        . $row['trid'] . '); relink it with wploc_link_translation instead.');
                }
                self::set_lang($p->ID, $etype, $l['code'], $row['trid'], null);
            } elseif (!$row) {
                self::set_lang($p->ID, $etype, $l['code'], null, null);
            }
            delete_post_meta($p->ID, '_wp_loc_is_new');
            $row = self::row($p->ID, $etype);
            if (!$row || $row['language'] !== $l['code']) return new WP_Error('register_failed', 'Could not register post #' . $p->ID . ' as "' . $l['code'] . '".');
            return true;
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Register a freshly created term (term_id + taxonomy) in a language. The table stores the
     * term_taxonomy_id; it is resolved here for both systems. A term auto-registered alone in
     * its group (wp-loc/WPML do that on created_term) is relabeled; one with translations is refused. true|WP_Error
     */
    static function register_term_language($term_id, $taxonomy, $code) {
        if (!self::system()) return new WP_Error('no_ml', 'No multilingual system is active.');
        $tax = (string) $taxonomy;
        if (!self::is_translatable_taxonomy($tax)) return new WP_Error('not_translatable', 'Taxonomy "' . $tax . '" is not translatable on this site.');
        $tr = self::term_row($term_id, $tax);
        if (!$tr) return new WP_Error('not_found', 'Term ' . (int) $term_id . ' not found in taxonomy "' . $tax . '".');
        $l = self::resolve_lang($code, true);
        if (is_wp_error($l)) return $l;
        $etype = 'tax_' . $tax;
        $eid = $tr['term_taxonomy_id'];
        $lock = self::lock();
        if (!$lock) return new WP_Error('locked', 'Another translation change is in progress — retry in a few seconds.');
        try {
            $row = self::row($eid, $etype);
            if ($row && $row['language'] !== $l['code']) {
                $others = array_filter(self::group($row['trid'], $etype), function ($r) use ($eid) { return $r['element_id'] !== $eid; });
                if ($others) {
                    return new WP_Error('already_registered', 'Term ' . $tr['term_id'] . ' is already registered as "' . $row['language'] . '" with translations (trid '
                        . $row['trid'] . '); relink it with wploc_link_translation instead.');
                }
                self::set_lang($eid, $etype, $l['code'], $row['trid'], null);
            } elseif (!$row) {
                self::set_lang($eid, $etype, $l['code'], null, null);
            }
            $row = self::row($eid, $etype);
            if (!$row || $row['language'] !== $l['code']) return new WP_Error('register_failed', 'Could not register term ' . $tr['term_id'] . ' as "' . $l['code'] . '".');
            return true;
        } finally {
            self::unlock($lock);
        }
    }

    // ── Tools ─────────────────────────────────────────────────────────────

    static function get_translations($args) {
        if (!self::system()) return self::err('No multilingual system (wp-loc / WPML) is active');
        $ref = self::resolve_element($args['element_id'] ?? 0, $args, null, true);
        if (is_wp_error($ref)) return self::err($ref->get_error_message());
        if ($ref['kind'] === 'post') {
            if (!Simple_MCP_Tools::can_read_post($ref['id'])) return Simple_MCP_Tools::err_cap('read post #' . $ref['id']);
        } elseif (!self::can_read_term($ref['id'], $ref['type'])) {
            return Simple_MCP_Tools::err_cap('edit_term #' . $ref['id'] . ' (' . $ref['type'] . ')');
        }

        $langs = self::languages();
        $out = ['kind' => $ref['kind'], 'element_id' => $ref['id'], 'element_type' => $ref['etype']];
        if ($ref['kind'] === 'term') {
            $out['taxonomy'] = $ref['type'];
            $out['term_taxonomy_id'] = $ref['eid'];
        } else {
            $out['post_type'] = $ref['type'];
        }
        $out['translatable'] = self::ref_translatable($ref);
        $out['default_language'] = self::default_code();
        if ($ref['note']) $out['note'] = $ref['note'];

        $row = self::row($ref['eid'], $ref['etype']);
        if (!$row) {
            $out += ['trid' => null, 'language' => null, 'is_original' => null, 'translations' => (object) [],
                'missing' => array_keys(array_filter($langs, function ($l) { return $l['active']; }))];
            $out['note'] = trim(($out['note'] ?? '') . ' The element has no language yet (not registered in any translation group).');
            return self::ok($out);
        }

        $rows = self::group($row['trid'], $ref['etype']);
        $tr = [];
        $conflicts = [];
        foreach ($rows as $r) {
            $code = $r['language'];
            $m = $ref['kind'] === 'post' ? self::member_post($r, $code, $langs) : self::member_term($r, $ref['type'], $code, $langs);
            if (isset($tr[$code])) {
                $conflicts[$code][] = $m['id'] ?? ($m['term_id'] ?? null);
                continue;
            }
            $tr[$code] = $m;
        }
        foreach ($conflicts as $code => $ids) {
            array_unshift($conflicts[$code], $tr[$code]['id'] ?? ($tr[$code]['term_id'] ?? null));
            $conflicts[$code] = array_values(array_filter($conflicts[$code], function ($v) { return $v !== null; }));
        }
        $orig = self::original_row($rows);
        $missing = [];
        foreach ($langs as $code => $l) {
            if ($l['active'] && !isset($tr[$code])) $missing[] = $code;
        }
        $out += [
            'trid'            => $row['trid'],
            'language'        => $row['language'],
            'is_original'     => $row['source_language'] === null,
            'original'        => $orig ? ['language' => $orig['language'], 'id' => self::public_id($orig['element_id'], $ref['etype'])] : null,
            'translations'    => $tr ?: (object) [],
            'missing'         => $missing,
        ];
        if ($conflicts) $out['conflicts'] = $conflicts;
        if ($ref['kind'] === 'post' && ($n = self::sync_note())) $out['sync_note'] = $n;
        return self::ok($out);
    }

    /** One post member of a group, as visible to the current user. */
    static function member_post($r, $code, $langs) {
        $base = ['is_original' => $r['source_language'] === null, 'language_active' => isset($langs[$code]) && $langs[$code]['active']];
        $p = get_post($r['element_id']);
        if (!$p) return ['id' => $r['element_id'], 'status' => null, 'missing' => true] + $base;
        if (!Simple_MCP_Tools::can_read_post($p)) return ['readable' => false] + $base;
        return ['id' => (int) $p->ID, 'status' => $p->post_status, 'title' => $p->post_title, 'post_type' => $p->post_type] + $base;
    }

    /** One term member of a group, as visible to the current user. */
    static function member_term($r, $tax, $code, $langs) {
        $base = ['is_original' => $r['source_language'] === null, 'language_active' => isset($langs[$code]) && $langs[$code]['active']];
        $tr = self::term_row_by_tt($r['element_id'], $tax);
        if (!$tr) return ['term_id' => null, 'term_taxonomy_id' => $r['element_id'], 'missing' => true] + $base;
        if (!self::can_read_term($tr['term_id'], $tax)) return ['readable' => false] + $base;
        $info = self::term_info($tr['term_id'], $tax);
        return ['term_id' => $tr['term_id'], 'term_taxonomy_id' => $tr['term_taxonomy_id'], 'name' => $info['name'] ?? '', 'slug' => $info['slug'] ?? ''] + $base;
    }

    static function link_translation($args) {
        if (!self::system()) return self::err('No multilingual system (wp-loc / WPML) is active');
        $S = self::resolve_element($args['source_id'] ?? 0, $args);
        if (is_wp_error($S)) return self::err('source: ' . $S->get_error_message());
        $T = self::resolve_element($args['target_id'] ?? 0, ['kind' => $S['kind']] + ($S['kind'] === 'term' ? ['taxonomy' => $S['type']] : []));
        if (is_wp_error($T)) return self::err('target: ' . $T->get_error_message());
        if ($S['etype'] !== $T['etype']) {
            return self::err('Source and target must be the same ' . ($S['kind'] === 'post' ? 'post type' : 'taxonomy') . ': source is "' . $S['type'] . '", target is "' . $T['type'] . '".');
        }
        if ($S['eid'] === $T['eid']) return self::err('source_id and target_id are the same element — an element cannot be its own translation.');
        if (!self::ref_translatable($S)) {
            return self::err('"' . $S['type'] . '" is not translatable on this site (' . self::system() . ' settings).');
        }
        foreach ([$S, $T] as $ref) {
            $can = self::can_edit_ref($ref);
            if ($can !== true) return Simple_MCP_Tools::err_cap($can);
        }
        if ($S['kind'] === 'post') {
            // A trashed member is a time bomb: emptying the trash deletes its whole group (wp-loc/WPML),
            // and restoring it pushes its restored status to every translation (wp-loc attribute sync).
            foreach (['source' => $S, 'target' => $T] as $what => $ref) {
                $st = get_post_status($ref['id']);
                if (in_array($st, ['trash', 'auto-draft', 'inherit'], true)) {
                    return self::err('The ' . $what . ' post #' . $ref['id'] . ' has status "' . $st . '"; restore it (or pick another post) before linking it — '
                        . 'a trashed member deletes its whole translation group when the trash is emptied.');
                }
            }
        }
        $L = self::resolve_lang($args['lang'] ?? '', !empty($args['allow_inactive']));
        if (is_wp_error($L)) return self::err($L->get_error_message());
        $code = $L['code'];
        $etype = $S['etype'];

        return self::with_group_lock(function () use ($S, $T, $L, $code, $etype, $args) {
            $srow = self::row($S['eid'], $etype);
            $trow = self::row($T['eid'], $etype);
            $register = false;
            if (isset($args['source_language']) && $args['source_language'] !== '') {
                $SL = self::resolve_lang($args['source_language'], true);
                if (is_wp_error($SL)) return self::err('source_language: ' . $SL->get_error_message());
            } else {
                $SL = null;
            }
            if ($srow) {
                $src_lang = $srow['language'];
                if ($SL && $SL['code'] !== $src_lang) {
                    return self::err('The source is registered as "' . $src_lang . '", not "' . $SL['code'] . '". This tool does not relabel a source; omit source_language.');
                }
            } else {
                if (!$SL) {
                    return self::err('The source (' . $S['kind'] . ' ' . $S['id'] . ') has no language yet. Pass source_language (one of: ' . self::lang_list()
                        . ') — it will be registered in it.');
                }
                $src_lang = $SL['code'];
                $register = true;
            }
            if ($code === $src_lang) return self::err('The target language "' . $code . '" equals the source\'s language — an element cannot be its own translation.');

            $group = $srow ? self::group($srow['trid'], $etype) : [];
            $orig  = self::original_row($group);
            $orig_lang = $orig ? $orig['language'] : $src_lang;
            $prev  = $trow ? ['trid' => $trow['trid'], 'language' => $trow['language'], 'source_language' => $trow['source_language']] : null;

            if ($srow && $trow && $trow['trid'] === $srow['trid']) {
                if ($trow['language'] === $code) {
                    return self::ok(['trid' => $srow['trid'], 'unchanged' => true, 'language' => $code,
                        'source' => ['id' => $S['id'], 'language' => $src_lang, 'registered' => false],
                        'target' => ['id' => $T['id'], 'language' => $code, 'previous' => $prev],
                        'translations' => self::group_map($group, $etype)]);
                }
                if ($trow['source_language'] === null) {
                    return self::err('The target is the ORIGINAL ("' . $trow['language'] . '") of this group; relabeling it as "' . $code
                        . '" would leave the group without an original. Link a different element instead.');
                }
            }

            $moving = null;
            if ($trow && (!$srow || $trow['trid'] !== $srow['trid'])) {
                $tgroup = self::group($trow['trid'], $etype);
                if (count($tgroup) > 1) {
                    if (empty($args['move'])) {
                        return self::err('The target already belongs to another translation group (trid ' . $trow['trid'] . ': '
                            . wp_json_encode(self::group_map($tgroup, $etype)) . '). Pass move:true to take it out of that group'
                            . ($trow['source_language'] === null ? ' (it is that group\'s original — another member will be promoted)' : '') . '.');
                    }
                    $moving = $trow;
                }
            }

            $occupants = $srow ? array_values(array_filter(self::rows_in_lang($group, $code), function ($r) use ($T) { return $r['element_id'] !== $T['eid']; })) : [];
            foreach ($occupants as $o) {
                $oid = self::public_id($o['element_id'], $etype);
                if ($o['source_language'] === null) {
                    return self::err('The "' . $code . '" slot holds the group\'s original (' . $S['kind'] . ' ' . $oid . '); it cannot be replaced.');
                }
                $exists = $S['kind'] === 'post' ? (bool) get_post($o['element_id']) : (bool) $oid;
                if (empty($args['replace'])) {
                    return self::err('The "' . $code . '" slot of this group is already held by ' . $S['kind'] . ' ' . ($oid ?: $o['element_id'])
                        . ($exists ? '' : ' (which no longer exists)') . '. Pass replace:true to detach it (it is kept as a standalone "' . $code . '" '
                        . $S['kind'] . ') and link the target instead.');
                }
                if ($exists) {
                    $oref = ['kind' => $S['kind'], 'id' => $oid, 'eid' => $o['element_id'], 'etype' => $etype, 'type' => $S['type']];
                    $can = self::can_edit_ref($oref);
                    if ($can !== true) return Simple_MCP_Tools::err_cap($can);
                }
            }

            // ── writes ──
            if ($register) {
                self::set_lang($S['eid'], $etype, $src_lang, null, null);
                $srow = self::row($S['eid'], $etype);
                if (!$srow || $srow['language'] !== $src_lang) return self::err('Could not register the source as "' . $src_lang . '".');
            }
            $trid = $srow['trid'];
            $replaced = [];
            foreach ($occupants as $o) {
                $oid = self::public_id($o['element_id'], $etype);
                $exists = $S['kind'] === 'post' ? (bool) get_post($o['element_id']) : (bool) $oid;
                if ($exists) {
                    $d = self::detach_row($o['element_id'], $etype, $o);
                    if (is_wp_error($d)) return self::err($d->get_error_message());
                    $replaced[] = ['id' => $oid, 'language' => $code, 'new_trid' => $d['new_trid']];
                } else {
                    self::drop_row($o, $etype);
                    $replaced[] = ['id' => $o['element_id'], 'language' => $code, 'stale_row_removed' => true];
                }
            }
            $moved_from = null;
            if ($moving) {
                $d = self::detach_row($T['eid'], $etype, $moving);
                if (is_wp_error($d)) return self::err($d->get_error_message());
                $moved_from = ['trid' => $moving['trid'], 'new_original' => $d['new_original']];
            }
            self::set_lang($T['eid'], $etype, $code, $trid, $orig_lang);

            // ── verify ──
            $s2 = self::row($S['eid'], $etype);
            $t2 = self::row($T['eid'], $etype);
            $after = $s2 ? self::group($s2['trid'], $etype) : [];
            if (!$s2 || !$t2 || $s2['trid'] !== $t2['trid'] || $t2['language'] !== $code || $s2['language'] !== $src_lang
                || count(self::rows_in_lang($after, $code)) !== 1) {
                return self::err('The link was written but did not verify: source ' . wp_json_encode($s2) . ', target ' . wp_json_encode($t2)
                    . '. Check with wploc_get_translations.');
            }
            $out = [
                'trid'         => $t2['trid'],
                'unchanged'    => false,
                'language'     => $code,
                'source'       => ['id' => $S['id'], 'language' => $src_lang, 'registered' => $register],
                'target'       => ['id' => $T['id'], 'language' => $t2['language'], 'source_language' => $t2['source_language'], 'previous' => $prev],
                'replaced'     => $replaced,
                'moved_from'   => $moved_from,
                'translations' => self::group_map($after, $etype),
            ];
            $warnings = [];
            if ($S['note']) $warnings[] = 'source: ' . $S['note'];
            if (!$L['active']) $warnings[] = 'Language "' . $code . '" is disabled on this site; the translation is not visible until it is enabled.';
            if ($S['kind'] === 'post' && ($w = self::attr_mismatch($S['id'], $T['id']))) $warnings[] = $w;
            if ($warnings) $out['warnings'] = $warnings;
            if ($S['kind'] === 'post' && ($n = self::sync_note())) $out['sync_note'] = $n;
            return self::ok($out);
        });
    }

    /**
     * With wp-loc attribute sync on: a warning when two group members differ in status/author/password —
     * the next save of EITHER pushes its own values to the whole group (a draft member unpublishes the rest). null otherwise.
     */
    static function attr_mismatch($source_id, $target_id) {
        if (!self::attr_sync_on()) return null;
        $s = get_post($source_id);
        $t = get_post($target_id);
        if (!$s || !$t) return null;
        $diff = [];
        foreach (['post_status' => 'status', 'post_author' => 'author', 'post_password' => 'password'] as $f => $label) {
            if ((string) $s->$f !== (string) $t->$f) $diff[] = $label;
        }
        if (!$diff) return null;
        return 'The target\'s ' . implode('/', $diff) . (count($diff) > 1 ? ' differ' : ' differs') . ' from the source\'s (status "' . $t->post_status . '" vs "' . $s->post_status . '"): with wp-loc attribute sync '
            . 'the next save of ANY member of the group pushes its own ' . implode('/', $diff) . ' to all of them. To keep the source\'s values, save the source first '
            . '(e.g. update_post id:' . (int) $s->ID . ' status:"' . $s->post_status . '").';
    }

    static function create_translation($args) {
        if (!self::system()) return self::err('No multilingual system (wp-loc / WPML) is active');
        $ref = self::resolve_element($args['source_id'] ?? 0, $args, 'post');
        if (is_wp_error($ref)) return self::err('source: ' . $ref->get_error_message());
        return $ref['kind'] === 'term' ? self::create_term_translation($ref, $args) : self::create_post_translation($ref, $args);
    }

    /** Source language for create: the registered one, or the required source_language. [code, register?] | array(err) */
    static function source_language($row, $args, $what) {
        $SL = null;
        if (isset($args['source_language']) && $args['source_language'] !== '') {
            $SL = self::resolve_lang($args['source_language'], true);
            if (is_wp_error($SL)) return self::err('source_language: ' . $SL->get_error_message());
        }
        if ($row) {
            if ($SL && $SL['code'] !== $row['language']) {
                return self::err('The source is registered as "' . $row['language'] . '", not "' . $SL['code'] . '". Omit source_language (or fix the source first with wploc_link_translation).');
            }
            return [$row['language'], false];
        }
        if (!$SL) {
            return self::err('The source (' . $what . ') has no language yet. Pass source_language (one of: ' . self::lang_list() . ') — it will be registered in it.');
        }
        return [$SL['code'], true];
    }

    static function create_post_translation($ref, $args) {
        $post = Simple_MCP_Tools::writable_post($ref['id'], true); // trash is refused below with a translation-specific message
        if (is_array($post)) return $post;
        if (!self::is_translatable_post_type($post->post_type)) {
            return self::err('Post type "' . $post->post_type . '" is not translatable on this site (' . self::system() . ' settings), or is a system type'
                . ($post->post_type === 'attachment' ? ' (media translations are managed by the multilingual plugin itself)' : '') . '.');
        }
        if (in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
            return self::err('Source #' . $post->ID . ' has status "' . $post->post_status . '" — restore it (or pick a real post) before translating it.');
        }
        $pto = get_post_type_object($post->post_type);
        // create_posts as registered: false (e.g. log CPTs) natively denies everyone
        $create_cap = ($pto && isset($pto->cap->create_posts)) ? $pto->cap->create_posts : 'edit_posts';
        if (!$create_cap) return Simple_MCP_Tools::err_cap('create_posts (' . $post->post_type . ' — creating items is disabled)');
        if (!current_user_can($create_cap)) return Simple_MCP_Tools::err_cap($create_cap . ' (' . $post->post_type . ')');
        $L = self::resolve_lang($args['lang'] ?? '', !empty($args['allow_inactive']));
        if (is_wp_error($L)) return self::err($L->get_error_message());
        $code = $L['code'];
        $etype = 'post_' . $post->post_type;

        // Status: with wp-loc attribute sync the whole group must share it (the next save pushes it everywhere).
        $sync = self::attr_sync_on();
        $want = isset($args['status']) && $args['status'] !== '' ? sanitize_key((string) $args['status']) : null;
        if ($sync) {
            $status = $post->post_status;
            if ($want !== null && $want !== $status) {
                return self::err('wp-loc attribute sync is on: the copy is created with the source\'s status "' . $status . '", because saving any translation pushes '
                    . 'status, author and password to the whole group — a "' . $want . '" copy would turn the source "' . $want . '" on its first edit. '
                    . 'Omit status, or change the status of the whole group with update_post on the source.');
            }
        } else {
            $status = Simple_MCP_Tools::validate_status($want ?? 'draft', false, ($want === 'future') ? $post->post_date : null);
            if (is_wp_error($status)) return self::err($status->get_error_message());
        }
        return self::with_group_lock(function () use ($post, $L, $code, $etype, $status, $sync, $args) {
            $srow = self::row($post->ID, $etype);
            $sl = self::source_language($srow, $args, 'post #' . $post->ID);
            if (isset($sl['isError'])) return $sl;
            [$src_lang, $register] = $sl;
            if ($code === $src_lang) {
                return self::err('The target language "' . $code . '" equals the source\'s language — nothing to create; edit the source directly.');
            }

            // Existing translation in the slot?
            $group = $srow ? self::group($srow['trid'], $etype) : [];
            $stale = [];
            foreach (self::rows_in_lang($group, $code) as $o) {
                $op = get_post($o['element_id']);
                if (!$op) { $stale[] = $o; continue; }
                if ($op->post_status === 'trash') {
                    return self::err('The "' . $code . '" slot is held by post #' . $op->ID . ', which is in the trash. Restore it with update_post status "' . $post->post_status . '"'
                        . ($sync ? ' (wp-loc sync pushes the status you restore it with to the whole group, the source included)' : '')
                        . ', delete it with safe_delete force:true allow_cascade:true (it is detached first, so the rest of the group is kept), '
                        . 'or link another post into the slot with wploc_link_translation replace:true — then retry.');
                }
                if (!Simple_MCP_Tools::can_read_post($op)) {
                    return self::err('The "' . $code . '" slot is already held by a post you cannot access.');
                }
                return self::ok(['new_id' => (int) $op->ID, 'existing' => true, 'language' => $code, 'trid' => $o['trid'],
                    'status' => $op->post_status, 'title' => $op->post_title,
                    'note' => 'A "' . $code . '" translation already exists; edit it instead of creating another one.']);
            }

            if (Simple_MCP_Tools::is_publish_status($status) && !Simple_MCP_Tools::can_publish_type($post->post_type)) {
                return Simple_MCP_Tools::err_cap('publish_posts (' . $post->post_type . ', status "' . $status . '")');
            }

            // Parent: the target-language translation of the source's parent, or an explicit one.
            $parent = 0;
            $parent_info = null;
            if (array_key_exists('parent', $args) && $args['parent'] !== null && $args['parent'] !== '') {
                $parent = (int) $args['parent'];
                if ($parent > 0) {
                    if (!is_post_type_hierarchical($post->post_type)) return self::err('parent applies to hierarchical post types only ("' . $post->post_type . '" is not).');
                    $pp = get_post($parent);
                    if (!$pp || $pp->post_type !== $post->post_type || $pp->post_status === 'trash') {
                        return self::err('parent #' . $parent . ' must be an existing, non-trashed ' . $post->post_type . '.');
                    }
                    if (!Simple_MCP_Tools::can_read_post($pp)) return Simple_MCP_Tools::err_cap('read post #' . $parent);
                    $prow = self::row($pp->ID, $etype);
                    if (!$prow || $prow['language'] !== $code) {
                        return self::err('parent #' . $parent . ' is not a "' . $code . '" ' . $post->post_type . ' (its language: ' . ($prow ? $prow['language'] : 'none') . ').');
                    }
                }
                $parent_info = ['source' => (int) $post->post_parent, 'id' => $parent, 'explicit' => true];
            } elseif ($post->post_parent) {
                $pp = get_post($post->post_parent);
                if ($pp && $pp->post_type === $post->post_type) {
                    $mapped = self::post_in_lang($pp->ID, $code);
                    if (!$mapped) {
                        return self::err('The source\'s parent #' . $pp->ID . ' has no "' . $code . '" translation. Translate the parent first '
                            . '(wploc_create_translation source_id:' . $pp->ID . '), or pass parent:0 for a top-level copy (or parent:<id> of an existing "' . $code . '" ' . $post->post_type . ').');
                    }
                    $parent = $mapped;
                    $parent_info = ['source' => (int) $pp->ID, 'id' => $parent, 'mapped' => true];
                } else {
                    $parent = (int) $post->post_parent;
                    $parent_info = ['source' => $parent, 'id' => $parent, 'mapped' => false];
                }
            }

            // Terms: translatable taxonomies → the target-language terms; others keep the same terms.
            $terms = [];
            foreach (get_object_taxonomies($post->post_type) as $tax) {
                $ids = self::object_term_ids($post->ID, $tax);
                $translatable = self::is_translatable_taxonomy($tax);
                $plan = ['ids' => [], 'unmapped' => []];
                foreach ($ids as $tid) {
                    $m = $translatable ? self::term_in_lang($tid, $tax, $code) : $tid;
                    if (!$m) { $plan['unmapped'][] = $tid; $m = $tid; }
                    $plan['ids'][] = (int) $m;
                }
                $plan['ids'] = array_values(array_unique($plan['ids']));
                $terms[$tax] = $plan;
            }

            if ($register) {
                self::set_lang($post->ID, $etype, $src_lang, null, null);
                $srow = self::row($post->ID, $etype);
                if (!$srow || $srow['language'] !== $src_lang) return self::err('Could not register the source as "' . $src_lang . '".');
                delete_post_meta($post->ID, '_wp_loc_is_new');
            }
            foreach ($stale as $o) self::drop_row($o, $etype);
            $trid = $srow['trid'];
            $orig = self::original_row(self::group($trid, $etype));
            $orig_lang = $orig ? $orig['language'] : $src_lang;

            $postarr = [
                'post_type'      => $post->post_type,
                'post_status'    => $status,
                'post_title'     => $post->post_title,
                'post_content'   => $post->post_content,
                'post_excerpt'   => $post->post_excerpt,
                'post_parent'    => $parent,
                'menu_order'     => $post->menu_order,
                'post_password'  => $post->post_password,
                'post_author'    => $post->post_author,
                'comment_status' => $post->comment_status,
                'ping_status'    => $post->ping_status,
            ];
            if ($sync || $status === 'future') {
                $postarr['post_date'] = $post->post_date;
                $postarr['post_date_gmt'] = $post->post_date_gmt;
            }
            if (!empty($terms['category']['ids'])) $postarr['post_category'] = $terms['category']['ids'];

            // wp-loc must not treat the copy as a brand-new post (it would register it in the admin
            // language, auto-create drafts and sync attributes) — we register it ourselves below.
            $suspend = self::system() === 'wp-loc' && class_exists('WP_LOC_Content') && property_exists('WP_LOC_Content', 'suspend_new_post_registration');
            $was = $suspend ? WP_LOC_Content::$suspend_new_post_registration : null;
            if ($suspend) WP_LOC_Content::$suspend_new_post_registration = true;
            try {
                $new = self::with_raw_terms(function () use ($postarr) { return wp_insert_post(wp_slash($postarr), true); });
            } finally {
                if ($suspend) WP_LOC_Content::$suspend_new_post_registration = $was;
            }
            if (is_wp_error($new) || !$new) {
                return self::err('Duplicate failed: ' . (is_wp_error($new) ? $new->get_error_message() : 'wp_insert_post returned 0')
                    . ($register ? ' (the source was registered as "' . $src_lang . '")' : ''));
            }
            $new = (int) $new;

            try {
                $res = self::fill_post_copy($post, $new, $code, $etype, $trid, $orig_lang, $status, $parent, $terms);
            } catch (\Throwable $e) {
                $res = new WP_Error('copy_failed', $e->getMessage());
            }
            if (is_wp_error($res)) {
                self::discard_post($new, $etype);
                return self::err('Creating the translation failed and the copy #' . $new . ' was deleted: ' . $res->get_error_message()
                    . ($register ? ' (the source stays registered as "' . $src_lang . '")' : ''));
            }

            $warnings = $res['warnings'];
            if (!$L['active']) $warnings[] = 'Language "' . $code . '" is disabled on this site; the copy is not visible until it is enabled.';
            if ($sync && Simple_MCP_Tools::is_publish_status($status)) {
                $warnings[] = 'The copy is "' . $status . '" like its source (wp-loc attribute sync), so it is live with source-language content until you translate it.';
            }
            $out = [
                'new_id'   => $new,
                'existing' => false,
                'language' => $code,
                'trid'     => $trid,
                'status'   => get_post_status($new),
                'slug'     => get_post_field('post_name', $new),
                'source'   => ['id' => (int) $post->ID, 'language' => $src_lang, 'registered' => $register],
            ];
            if ($parent_info) $out['parent'] = $parent_info;
            $out += $res['report'];
            if ($warnings) $out['warnings'] = $warnings;
            if ($n = self::sync_note()) $out['sync_note'] = $n;
            $out['next'] = 'Translate the copy: block_update / update_post / acf_update on post #' . $new . '.';
            return self::ok($out);
        });
    }

    /**
     * Fill a freshly inserted copy: meta (raw), featured image, ACF relational IDs, terms, the
     * translation link and the slug, then verify. ['report' => [...], 'warnings' => [...]] | WP_Error.
     */
    static function fill_post_copy($post, $new, $code, $etype, $trid, $orig_lang, $status, $parent, $terms) {
        global $wpdb;
        $warnings = [];
        $report = [];

        // Meta, copied as stored: no unslash damage, no object instantiation.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post->ID
        ), ARRAY_A);
        $present = array_flip(array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d", $new
        ))));
        $copied = [];
        $skipped = [];
        $thumb = 0;
        foreach ((array) $rows as $r) {
            $k = (string) $r['meta_key'];
            if ($k === '_thumbnail_id') { $thumb = (int) $r['meta_value']; continue; }
            if (self::skip_meta($k, $post, $code)) { $skipped[$k] = true; continue; }
            if (isset($present[$k])) {
                $wpdb->delete($wpdb->postmeta, ['post_id' => $new, 'meta_key' => $k], ['%d', '%s']);
                unset($present[$k]);
            }
            if (!$wpdb->insert($wpdb->postmeta, ['post_id' => $new, 'meta_key' => $k, 'meta_value' => $r['meta_value']], ['%d', '%s', '%s'])) {
                return new WP_Error('meta_copy', 'Could not copy meta "' . $k . '": ' . $wpdb->last_error);
            }
            $copied[(int) $wpdb->insert_id] = [$k, (string) $r['meta_value']];
        }
        if ($thumb) {
            // mapped: true = a target-language attachment is used; false = none exists (source kept); null = language-neutral attachment.
            $m = self::map_rel_id($thumb, ['type' => 'image'], $code);
            $tp = get_post($thumb);
            $neutral = !$tp || !self::row($thumb, 'post_' . $tp->post_type);
            $tid = $m ?: $thumb;
            $wpdb->delete($wpdb->postmeta, ['post_id' => $new, 'meta_key' => '_thumbnail_id'], ['%d', '%s']);
            $wpdb->insert($wpdb->postmeta, ['post_id' => $new, 'meta_key' => '_thumbnail_id', 'meta_value' => (string) $tid], ['%d', '%s', '%s']);
            $report['featured_image'] = ['source' => $thumb, 'id' => $tid, 'mapped' => $neutral ? null : (bool) $m];
        }
        wp_cache_delete($new, 'post_meta');
        [$acf_mapped, $acf_unmapped] = self::map_acf_relations($new, $copied, $code);
        wp_cache_delete($new, 'post_meta');
        $report['meta'] = ['copied' => count($copied), 'skipped' => array_keys($skipped)];
        if ($acf_mapped || $acf_unmapped) $report['acf_ids'] = ['mapped' => $acf_mapped, 'unmapped' => $acf_unmapped];

        // Terms (language filters off, so target-language term IDs are accepted), then verify.
        $term_report = [];
        foreach ($terms as $tax => $plan) {
            $r = self::with_raw_terms(function () use ($new, $plan, $tax) { return wp_set_object_terms($new, $plan['ids'], $tax, false); });
            if (is_wp_error($r)) return new WP_Error('terms', 'Could not assign ' . $tax . ' terms: ' . $r->get_error_message());
            $got = self::object_term_ids($new, $tax);
            sort($got);
            $want = $plan['ids'];
            sort($want);
            if ($got !== $want) return new WP_Error('terms', 'The ' . $tax . ' terms did not save as planned (' . wp_json_encode($want) . ' → ' . wp_json_encode($got) . ').');
            if (!$plan['ids']) continue;
            $term_report[$tax] = ['ids' => $plan['ids']] + ($plan['unmapped'] ? ['unmapped' => $plan['unmapped']] : []);
            if ($plan['unmapped']) {
                $warnings[] = $tax . ' terms ' . implode(', ', $plan['unmapped']) . ' have no "' . $code . '" translation and were kept as they are.';
            }
        }
        if ($term_report) $report['terms'] = $term_report;
        if ($acf_unmapped) $warnings[] = 'Some ACF relational IDs have no "' . $code . '" translation and were kept (see acf_ids.unmapped).';
        if (isset($report['featured_image']) && $report['featured_image']['mapped'] === false) {
            $warnings[] = 'The featured image #' . $thumb . ' has no "' . $code . '" translation; the source\'s image was kept.';
        }

        // Link into the source's group and verify the slot.
        self::set_lang($new, $etype, $code, $trid, $orig_lang);
        delete_post_meta($new, '_wp_loc_is_new');
        $nrow = self::row($new, $etype);
        if (!$nrow || $nrow['trid'] !== (int) $trid || $nrow['language'] !== $code) {
            return new WP_Error('link', 'The copy could not be registered as the "' . $code . '" translation (row: ' . wp_json_encode($nrow) . ').');
        }
        if (count(self::rows_in_lang(self::group($trid, $etype), $code)) !== 1) {
            return new WP_Error('link', 'The "' . $code . '" slot of group ' . $trid . ' would hold two posts.');
        }

        // Slug: unique within the target language now that the copy has one (drafts keep an empty slug).
        if ($post->post_name !== '' && !in_array($status, ['draft', 'pending', 'auto-draft'], true)) {
            $slug = wp_unique_post_slug($post->post_name, $new, $status, $post->post_type, $parent);
            if ($slug !== get_post_field('post_name', $new)) {
                $wpdb->update($wpdb->posts, ['post_name' => $slug], ['ID' => $new], ['%s'], ['%d']);
            }
        }
        clean_post_cache($new);

        $saved = get_post($new);
        $report['content_verified'] = Simple_MCP_Tools::content_matches($saved ? $saved->post_content : '', $post->post_content);
        if (!$report['content_verified']) {
            $warnings[] = 'The copied content differs from the source — WordPress filtered it on save (usually kses for a user without unfiltered_html). Compare with get_post and fix it.';
        }
        return ['report' => $report, 'warnings' => $warnings];
    }

    /** Delete a failed copy without cascading: drop its translation row first (wp-loc/WPML delete whole groups). */
    static function discard_post($new, $etype) {
        $row = self::row($new, $etype);
        if ($row) self::drop_row($row, $etype);
        wp_delete_post($new, true);
    }

    static function create_term_translation($ref, $args) {
        $tax = $ref['type'];
        $etype = $ref['etype'];
        if (!self::is_translatable_taxonomy($tax)) return self::err('Taxonomy "' . $tax . '" is not translatable on this site (' . self::system() . ' settings).');
        $can = self::can_edit_ref($ref);
        if ($can !== true) return Simple_MCP_Tools::err_cap($can);
        $txo = get_taxonomy($tax);
        $cap = ($txo && !empty($txo->cap->edit_terms)) ? $txo->cap->edit_terms : 'manage_categories';
        if (!current_user_can($cap)) return Simple_MCP_Tools::err_cap($cap . ' (' . $tax . ')');
        $L = self::resolve_lang($args['lang'] ?? '', !empty($args['allow_inactive']));
        if (is_wp_error($L)) return self::err($L->get_error_message());
        $code = $L['code'];
        if (isset($args['status']) && $args['status'] !== '') return self::err('status applies to posts only.');

        return self::with_group_lock(function () use ($ref, $tax, $etype, $L, $code, $args) {
            $srow = self::row($ref['eid'], $etype);
            $sl = self::source_language($srow, $args, 'term ' . $ref['id']);
            if (isset($sl['isError'])) return $sl;
            [$src_lang, $register] = $sl;
            if ($code === $src_lang) return self::err('The target language "' . $code . '" equals the source\'s language — nothing to create.');

            $group = $srow ? self::group($srow['trid'], $etype) : [];
            $stale = [];
            foreach (self::rows_in_lang($group, $code) as $o) {
                $tr = self::term_row_by_tt($o['element_id'], $tax);
                if (!$tr) { $stale[] = $o; continue; }
                $info = self::term_info($tr['term_id'], $tax);
                return self::ok(['new_term_id' => $tr['term_id'], 'term_taxonomy_id' => $tr['term_taxonomy_id'], 'existing' => true,
                    'language' => $code, 'trid' => $o['trid'], 'name' => $info['name'] ?? '',
                    'note' => 'A "' . $code . '" translation already exists; edit it instead of creating another one.']);
            }

            $src = self::term_info($ref['id'], $tax);
            if (!$src) return self::err('Source term ' . $ref['id'] . ' not found.');
            $parent = 0;
            $parent_info = null;
            if (array_key_exists('parent', $args) && $args['parent'] !== null && $args['parent'] !== '') {
                $parent = (int) $args['parent'];
                if ($parent > 0) {
                    if (!is_taxonomy_hierarchical($tax)) return self::err('parent applies to hierarchical taxonomies only ("' . $tax . '" is not).');
                    $pr = self::term_row($parent, $tax);
                    $prow = $pr ? self::row($pr['term_taxonomy_id'], $etype) : null;
                    if (!$pr || !$prow || $prow['language'] !== $code) {
                        return self::err('parent ' . $parent . ' must be an existing "' . $code . '" term of "' . $tax . '".');
                    }
                }
                $parent_info = ['source' => $src['parent'], 'id' => $parent, 'explicit' => true];
            } elseif ($src['parent'] && is_taxonomy_hierarchical($tax)) {
                $mapped = self::term_in_lang($src['parent'], $tax, $code);
                if (!$mapped) {
                    return self::err('The source\'s parent term ' . $src['parent'] . ' has no "' . $code . '" translation. Translate the parent first, or pass parent:0 for a top-level term.');
                }
                $parent = $mapped;
                $parent_info = ['source' => $src['parent'], 'id' => $parent, 'mapped' => true];
            }
            $name = isset($args['name']) && trim((string) $args['name']) !== '' ? (string) $args['name'] : $src['name'];
            $slug = isset($args['slug']) && trim((string) $args['slug']) !== '' ? sanitize_title((string) $args['slug']) : $src['slug'];
            $desc = isset($args['description']) ? (string) $args['description'] : $src['description'];

            if ($register) {
                self::set_lang($ref['eid'], $etype, $src_lang, null, null);
                $srow = self::row($ref['eid'], $etype);
                if (!$srow || $srow['language'] !== $src_lang) return self::err('Could not register the source as "' . $src_lang . '".');
            }
            foreach ($stale as $o) self::drop_row($o, $etype);
            $trid = $srow['trid'];
            $orig = self::original_row(self::group($trid, $etype));
            $orig_lang = $orig ? $orig['language'] : $src_lang;

            $ins = self::with_term_lang($code, function () use ($name, $tax, $slug, $desc, $parent) {
                return wp_insert_term(wp_slash($name), $tax, ['slug' => $slug, 'description' => wp_slash($desc), 'parent' => $parent]);
            });
            if (is_wp_error($ins) || empty($ins['term_taxonomy_id'])) {
                return self::err('Creating the term failed: ' . (is_wp_error($ins) ? $ins->get_error_message() : 'no term returned')
                    . ' — pass a different name or slug.' . ($register ? ' (The source was registered as "' . $src_lang . '".)' : ''));
            }
            $new_id = (int) $ins['term_id'];
            $new_tt = (int) $ins['term_taxonomy_id'];
            self::set_lang($new_tt, $etype, $code, $trid, $orig_lang);
            $nrow = self::row($new_tt, $etype);
            if (!$nrow || $nrow['trid'] !== (int) $trid || $nrow['language'] !== $code || count(self::rows_in_lang(self::group($trid, $etype), $code)) !== 1) {
                if ($nrow) self::drop_row($nrow, $etype);
                wp_delete_term($new_id, $tax);
                return self::err('The new term could not be linked as the "' . $code . '" translation and was deleted.');
            }
            $info = self::term_info($new_id, $tax);
            $out = ['new_term_id' => $new_id, 'term_taxonomy_id' => $new_tt, 'existing' => false, 'language' => $code, 'trid' => (int) $trid,
                'taxonomy' => $tax, 'name' => $info['name'] ?? $name, 'slug' => $info['slug'] ?? $slug,
                'source' => ['term_id' => $ref['id'], 'language' => $src_lang, 'registered' => $register]];
            if ($parent_info) $out['parent'] = $parent_info;
            $warnings = [];
            if (!$L['active']) $warnings[] = 'Language "' . $code . '" is disabled on this site; the term is not visible until it is enabled.';
            if (isset($info['slug']) && $info['slug'] !== $slug) {
                $warnings[] = 'The slug "' . $slug . '" was taken, so WordPress saved "' . $info['slug'] . '"; pass slug to choose another one.';
            }
            if ($warnings) $out['warnings'] = $warnings;
            return self::ok($out);
        });
    }

    // ── Create helpers ───────────────────────────────────────────────────

    /** The non-trashed target-language translation of a post/attachment (itself when already in it), or null. */
    static function post_in_lang($post_id, $code) {
        $p = get_post((int) $post_id);
        if (!$p) return null;
        $etype = 'post_' . $p->post_type;
        $row = self::row($p->ID, $etype);
        if (!$row) return null;
        if ($row['language'] === $code) return (int) $p->ID;
        foreach (self::rows_in_lang(self::group($row['trid'], $etype), $code) as $r) {
            $t = get_post($r['element_id']);
            if ($t && $t->post_status !== 'trash') return (int) $t->ID;
        }
        return null;
    }

    /** The target-language translation (term_id) of a term, or null. */
    static function term_in_lang($term_id, $tax, $code) {
        $tr = self::term_row($term_id, $tax);
        if (!$tr) return null;
        $etype = 'tax_' . $tax;
        $row = self::row($tr['term_taxonomy_id'], $etype);
        if (!$row) return null;
        if ($row['language'] === $code) return (int) $term_id;
        foreach (self::rows_in_lang(self::group($row['trid'], $etype), $code) as $r) {
            $t = self::term_row_by_tt($r['element_id'], $tax);
            if ($t) return $t['term_id'];
        }
        return null;
    }

    /** term_ids of an object in a taxonomy (raw: no language filter hides any of them). */
    static function object_term_ids($object_id, $tax) {
        global $wpdb;
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id",
            (int) $object_id, (string) $tax
        )));
    }

    /**
     * Run $fn with the system's language handling of terms off: the term-query language filter is
     * removed (so term_exists() sees every language) and get_term() no longer swaps a term for its
     * current-language translation (wpml_disable_term_adjust_id, honored by WPML and wp-loc).
     */
    static function with_raw_terms(callable $fn) {
        $removed = [];
        $cbs = [];
        if (self::system() === 'wp-loc' && class_exists('WP_LOC') && isset(WP_LOC::instance()->terms) && is_object(WP_LOC::instance()->terms)) {
            $cbs[] = [WP_LOC::instance()->terms, 'filter_terms_clauses'];
        }
        if (self::system() === 'wpml' && isset($GLOBALS['sitepress']) && is_object($GLOBALS['sitepress'])) {
            $cbs[] = [$GLOBALS['sitepress'], 'terms_clauses'];
        }
        foreach ($cbs as $cb) {
            $prio = has_filter('terms_clauses', $cb);
            if ($prio !== false) {
                remove_filter('terms_clauses', $cb, $prio);
                $removed[] = [$cb, $prio];
            }
        }
        add_filter('wpml_disable_term_adjust_id', '__return_true', 999);
        try {
            return $fn();
        } finally {
            remove_filter('wpml_disable_term_adjust_id', '__return_true', 999);
            foreach ($removed as $r) add_filter('terms_clauses', $r[0], $r[1], 3);
        }
    }

    /** Run $fn (a term insert) in the target language's term context, so duplicate checks and slugs are per language. */
    static function with_term_lang($code, callable $fn) {
        if (self::system() === 'wpml') {
            // Like WPML's own WPML_Create_Post_Helper: switch without touching the language cookie, then restore.
            $sp = isset($GLOBALS['sitepress']) && is_object($GLOBALS['sitepress']) && method_exists($GLOBALS['sitepress'], 'switch_lang') ? $GLOBALS['sitepress'] : null;
            $prev = apply_filters('wpml_current_language', null);
            if ($sp) $sp->switch_lang($code, false); else do_action('wpml_switch_language', $code);
            try {
                return $fn();
            } finally {
                if ($sp) $sp->switch_lang($prev, false); else do_action('wpml_switch_language', null);
            }
        }
        $slug = self::wploc_lang_arg($code);
        if (function_exists('wp_loc_get_current_lang')) wp_loc_get_current_lang(); // pin the request language before $_REQUEST changes
        $saved = [];
        foreach (['lang', 'wp_loc_lang'] as $k) {
            $saved[$k] = array_key_exists($k, $_REQUEST) ? $_REQUEST[$k] : null;
            $_REQUEST[$k] = $slug;
        }
        $cb = function ($a) use ($slug) {
            if (is_array($a) && !isset($a['lang'])) $a['lang'] = $slug;
            return $a;
        };
        add_filter('get_terms_args', $cb, 999);
        try {
            return $fn();
        } finally {
            remove_filter('get_terms_args', $cb, 999);
            foreach ($saved as $k => $v) {
                if ($v === null) unset($_REQUEST[$k]); else $_REQUEST[$k] = $v;
            }
        }
    }

    /** Should a meta key stay out of the translation? (filter: simple_mcp_translation_skip_meta($skip, $key, $source_post, $code)) */
    static function skip_meta($key, $post, $code) {
        $skip = in_array($key, self::SKIP_META, true);
        if (!$skip) {
            foreach (self::SKIP_META_PREFIX as $p) {
                if (strpos($key, $p) === 0) { $skip = true; break; }
            }
        }
        return (bool) apply_filters('simple_mcp_translation_skip_meta', $skip, $key, $post, $code);
    }

    /** Unserialize a stored meta value without instantiating objects; null when it holds objects or is broken. */
    static function raw_value($raw) {
        if (!is_serialized($raw)) return $raw;
        $v = @unserialize($raw, ['allowed_classes' => false]);
        if ($v === false && $raw !== 'b:0;') return null;
        $has_obj = false;
        $walk = function ($x) use (&$walk, &$has_obj) {
            if (is_object($x)) { $has_obj = true; return; }
            if (is_array($x)) foreach ($x as $y) $walk($y);
        };
        $walk($v);
        return $has_obj ? null : $v;
    }

    /**
     * Point post-level ACF image/file/gallery/post_object/relationship/page_link/taxonomy values of
     * the copy at their target-language translations. Updates the copied rows in place.
     * Returns [mapped [{key, field}], unmapped [{key, id}]].
     */
    static function map_acf_relations($new, $copied, $code) {
        global $wpdb;
        if (!function_exists('acf_get_field') || !$copied) return [[], []];
        $by_key = [];
        foreach ($copied as $mid => $kv) $by_key[$kv[0]][] = $mid;
        $mapped = [];
        $unmapped = [];
        foreach ($copied as $mid => $kv) {
            [$k, $raw] = $kv;
            if ($k === '' || $k[0] === '_' || empty($by_key['_' . $k])) continue;
            $fkey = (string) $copied[$by_key['_' . $k][0]][1];
            if (strpos($fkey, 'field_') !== 0) continue;
            $field = acf_get_field($fkey);
            if (!is_array($field) || !in_array($field['type'] ?? '', self::ACF_REL_TYPES, true)) continue;
            $val = self::raw_value($raw);
            if ($val === null || $val === '' || $val === []) continue;
            $miss = [];
            $changed = false;
            $one = function ($id) use ($field, $code, &$miss, &$changed) {
                if (!is_numeric($id) || (int) $id <= 0) return $id;
                $m = self::map_rel_id((int) $id, $field, $code);
                if ($m === null) { $miss[] = (int) $id; return $id; }
                if ($m !== (int) $id) { $changed = true; return is_string($id) ? (string) $m : $m; }
                return $id;
            };
            $nv = is_array($val) ? array_map($one, $val) : $one($val);
            foreach ($miss as $id) $unmapped[] = ['key' => $k, 'id' => $id];
            if ($changed) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => is_array($nv) ? serialize($nv) : (string) $nv], ['meta_id' => (int) $mid], ['%s'], ['%d']);
                $mapped[] = ['key' => $k, 'field' => (string) ($field['name'] ?? $k)];
            }
        }
        wp_cache_delete($new, 'post_meta');
        return [array_slice($mapped, 0, 100), array_slice($unmapped, 0, 100)];
    }

    /** Target-language ID for one relational ACF value; the same ID when the object is not translated; null when a translation is missing. */
    static function map_rel_id($id, $field, $code) {
        $type = $field['type'] ?? '';
        if ($type === 'taxonomy') {
            $tax = (string) ($field['taxonomy'] ?? '');
            if (!self::is_translatable_taxonomy($tax) || !self::term_row($id, $tax)) return $id;
            return self::term_in_lang($id, $tax, $code);
        }
        $p = get_post($id);
        if (!$p) return $id;
        if (in_array($type, ['image', 'file', 'gallery'], true) && $p->post_type !== 'attachment') return $id;
        if ($p->post_type !== 'attachment' && !self::is_translatable_post_type($p->post_type)) return $id;
        if (!self::row($p->ID, 'post_' . $p->post_type)) return $id; // never registered → language-neutral
        return self::post_in_lang($id, $code);
    }
}
