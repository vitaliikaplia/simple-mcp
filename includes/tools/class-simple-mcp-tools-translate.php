<?php
/**
 * Translate toolset — in-session translation workflow for posts / WooCommerce products (wp-loc).
 *
 * Model: the AI client does the actual translating; these tools only package and apply.
 *   translate_list  → which source posts are untranslated in a language
 *   translate_get   → ALL translatable text of one post in one payload (title, excerpt,
 *                     html body OR acf-block text fields, AIOSEO fields)
 *   translate_apply → write the translated payload into the correct target post
 *                     (creates/links the translation when missing, publishes by default)
 *
 * "Untranslated" states (wp-loc conventions): missing = no translation row; draft_copy =
 * translation exists but is a draft (wp-loc auto-copies are drafts on sites where status
 * sync is off); identical = title/content byte-equal the source (untouched auto-copy on
 * sites where status IS synced). Product structure (prices/stock/variations/terms) is
 * synced automatically by wp-loc(+wc addon) — only TEXT needs translating.
 *
 * Write-safety: while writing the target we detach wp-loc's sync_translations (it would
 * push the target's post_status onto the source and siblings), and block auto-creation
 * of further language copies during our own duplicate insert.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Translate {

    /** AIOSEO-поля, які і читаємо, і пишемо (текстові з whitelist SEO-модуля) */
    const SEO_FIELDS = ['title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description'];

    static function defs() {
        return [
            'translate_list' => [
                'description' => 'List PUBLISHED default-language posts that are untranslated in a target language. States per language: "missing" (no translation row), "draft_copy" (translation exists but is a draft), "identical" (translation title/content is byte-equal to the source — usually an untouched auto-copy; brand names can legitimately match, verify with translate_get before overwriting). Workflow: translate_list → translate_get → translate the strings in-session → translate_apply. wp-loc only (on bare WPML use its own translation management).',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_type' => ['type' => 'string', 'description' => 'one translatable type (e.g. product, page); default: all translatable types (shop_coupon always excluded — coupon titles are shared codes)'],
                        'lang'      => ['type' => 'string', 'description' => 'target language slug ("ru") or wpml code; default: every enabled non-default language'],
                        'limit'     => ['type' => 'integer', 'description' => 'default 50, max 200'],
                        'offset'    => ['type' => 'integer'],
                    ]],
                'callback' => [__CLASS__, 'list_untranslated'],
            ],
            'translate_get' => [
                'description' => 'Fetch ALL translatable text of one post in a single package: title, excerpt, and either content_html (raw post_content — may contain Gutenberg comment delimiters <!-- wp:… -->; translate only visible text and keep ALL markup/delimiters byte-identical) or blocks (ACF text/textarea/wysiwyg fields of top-level acf/* blocks, keyed by flattened field name). Includes seo (AIOSEO title/description/og/twitter) when AIOSEO is active. Translate the strings in-session, then call translate_apply with the same shape. Top-level blocks only (nested innerBlocks and link/image fields are not extracted in v1).',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer']],
                    'required'   => ['post_id']],
                'callback' => [__CLASS__, 'get_package'],
            ],
            'translate_apply' => [
                'description' => 'Write a translated package into the {lang} translation of source_id. Creates and links the translation post automatically when missing (otherwise updates the existing one — idempotent). translated accepts: title, excerpt, content_html (full body — byte-verified), blocks ([{index, fields:{name:value}}] — validated against the SOURCE structure: the block at each index must have the same blockName and the field keys must already exist as strings; the tool never restructures blocks), seo ({title, description, og_title, …} — partial-safe AIOSEO write). status defaults to "publish" (in wp-loc publish = translated); if the SOURCE is not published the target stays draft (note in response). Safe against wp-loc status back-sync (the source is never touched). For products, structure (prices/stock/variations) re-syncs from the default-language source automatically after the write.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'source_id'  => ['type' => 'integer'],
                        'lang'       => ['type' => 'string', 'description' => 'target language slug or wpml code'],
                        'translated' => ['type' => 'object', 'description' => '{title?, excerpt?, content_html?, blocks?:[{index, fields:{k:v}}], seo?:{title?, description?, og_title?, og_description?, twitter_title?, twitter_description?}}'],
                        'status'     => ['type' => 'string', 'description' => 'publish | draft | pending | private (default publish)'],
                    ],
                    'required' => ['source_id', 'lang', 'translated']],
                'callback' => [__CLASS__, 'apply'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    // ── helpers ───────────────────────────────────────────────────────────

    /** Типи, які реально перекладаємо (runtime-список wp-loc мінус купони/варіації). */
    static function translatable_types() {
        $types = (array) apply_filters('wp_loc_translatable_post_types', ['post', 'page']);
        return array_values(array_diff($types, ['shop_coupon', 'product_variation']));
    }

    /** [code => slug] увімкнених НЕдефолтних мов. */
    static function target_codes() {
        [, , $def] = Simple_MCP_Tools_Wploc::lang_map();
        $out = [];
        $langs = get_option('wp_loc_languages', []);
        if (is_array($langs)) {
            foreach ($langs as $slug => $info) {
                if (empty($info['enabled'])) continue;
                $code = $info['wpml_code'] ?? $slug;
                if ($code !== $def) $out[$code] = $slug;
            }
        }
        return $out;
    }

    // ── translate_list ────────────────────────────────────────────────────

    static function list_untranslated($args) {
        if (!class_exists('WP_LOC')) {
            return self::err('translate_list requires wp-loc (on bare WPML use its native translation management)');
        }
        global $wpdb;

        [, , $def] = Simple_MCP_Tools_Wploc::lang_map();
        if (!$def) return self::err('could not resolve the default language');

        $targets = self::target_codes();
        if (isset($args['lang'])) {
            $code = Simple_MCP_Tools_Wploc::to_code((string) $args['lang']);
            if ($code === $def) return self::err('"' . $code . '" is the default language — it is the translation SOURCE');
            if (!isset($targets[$code])) return self::err('unknown/disabled language "' . $args['lang'] . '" — enabled non-default: ' . implode(', ', array_keys($targets)));
            $targets = [$code => $targets[$code]];
        }
        if (!$targets) return self::err('no enabled non-default languages configured');

        $types = self::translatable_types();
        if (isset($args['post_type'])) {
            $pt = sanitize_key($args['post_type']);
            if (!in_array($pt, $types, true)) {
                return self::err('post_type "' . $pt . '" is not translatable — valid: ' . implode(', ', $types));
            }
            $types = [$pt];
        }
        $limit  = max(1, min(200, intval($args['limit'] ?? 50)));
        $offset = max(0, intval($args['offset'] ?? 0));

        $icl   = WP_LOC::instance()->db->get_table();
        $posts = $wpdb->posts;

        // Один прохід на мову; злиття по id. Стани: missing > draft_copy > identical.
        // Джерела їдуть від таблиці постів: ловимо і пости, ще НЕ зареєстровані в icl
        // (wp-loc реєструє лише на другому збереженні) — вони теж «неперекладені».
        $type_ph = implode(',', array_fill(0, count($types), '%s'));
        $items = [];
        $total = 0;
        foreach ($targets as $code => $slug) {
            $sql = $wpdb->prepare(
                "SELECT ps.ID AS id, ps.post_type, ps.post_title,
                        s.element_id AS reg_id,
                        tr.element_id AS tr_id, pt.post_status AS tr_status
                 FROM {$posts} ps
                 LEFT JOIN {$icl} s ON s.element_id = ps.ID AND s.element_type = CONCAT('post_', ps.post_type)
                 LEFT JOIN {$icl} tr ON tr.trid = s.trid AND tr.element_type = s.element_type AND tr.language_code = %s
                 LEFT JOIN {$posts} pt ON pt.ID = tr.element_id
                 WHERE ps.post_type IN ($type_ph)
                   AND ps.post_status = 'publish'
                   AND ( s.element_id IS NULL OR s.language_code = %s )
                   AND ( tr.element_id IS NULL
                      OR pt.post_status = 'draft'
                      OR (CHAR_LENGTH(ps.post_title) > 0 AND pt.post_title = ps.post_title COLLATE utf8mb4_bin)
                      OR (CHAR_LENGTH(ps.post_content) > 0 AND pt.post_content = ps.post_content COLLATE utf8mb4_bin) )
                 ORDER BY ps.post_type, ps.ID",
                array_merge([$code], $types, [$def])
            );
            foreach ((array) $wpdb->get_results($sql) as $row) {
                $id = intval($row->id);
                if (!isset($items[$id])) {
                    $items[$id] = ['id' => $id, 'post_type' => $row->post_type, 'title' => $row->post_title, 'langs' => []];
                }
                if ($row->tr_id === null) $state = 'missing';
                elseif ($row->tr_status === 'draft') $state = 'draft_copy';
                else $state = 'identical';
                $items[$id]['langs'][$code] = $state;
            }
        }
        $total = count($items);
        $page  = array_slice(array_values($items), $offset, $limit);

        return self::ok([
            'items'             => $page,
            'total'             => $total,
            'limit'             => $limit,
            'offset'            => $offset,
            'has_more'          => ($offset + $limit) < $total,
            'default_language'  => $def,
            'checked_languages' => array_keys($targets),
        ]);
    }

    // ── translate_get ─────────────────────────────────────────────────────

    static function get_package($args) {
        $id = intval($args['post_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('post not found');
        if (!in_array($post->post_type, self::translatable_types(), true)) {
            return self::err('post_type "' . $post->post_type . '" is not translatable — valid: ' . implode(', ', self::translatable_types()));
        }

        [, , $def] = Simple_MCP_Tools_Wploc::lang_map();
        $lang = Simple_MCP_Tools_Wploc::elem_lang($id, 'post_' . $post->post_type, $def);

        $out = [
            'post_id'   => $id,
            'post_type' => $post->post_type,
            'language'  => $lang,
            'title'     => $post->post_title,
            'excerpt'   => $post->post_excerpt,
        ];

        // Формат: blocks лише коли є хоч один acf/-блок з data; інакше сирий html
        $blocks = parse_blocks($post->post_content);
        $acf_blocks = [];
        $skipped_non_acf = 0;
        $ord = 0;
        foreach ($blocks as $b) {
            if (empty($b['blockName'])) continue;
            if (strpos($b['blockName'], 'acf/') === 0 && !empty($b['attrs']['data']) && is_array($b['attrs']['data'])) {
                $fields = self::translatable_block_fields($b['attrs']['data']);
                if ($fields) {
                    $acf_blocks[] = ['index' => $ord, 'blockName' => $b['blockName'], 'fields' => $fields];
                }
            } else {
                $skipped_non_acf++;
            }
            $ord++;
        }

        if ($acf_blocks) {
            $out['format'] = 'blocks';
            $out['blocks'] = $acf_blocks;
            $out['skipped_non_acf'] = $skipped_non_acf;
        } else {
            $out['format'] = 'html';
            $out['content_html'] = $post->post_content;
        }

        // AIOSEO-поля (текстові, непорожні)
        if (function_exists('aioseo') && class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
            $p = \AIOSEO\Plugin\Common\Models\Post::getPost($id);
            $seo = [];
            foreach (self::SEO_FIELDS as $f) {
                $v = $p->$f ?? null;
                if (is_string($v) && trim($v) !== '') $seo[$f] = $v;
            }
            if ($seo) $out['seo'] = $seo;
        }

        return self::ok($out);
    }

    /** Флет-ключі acf-блока, чиї значення — перекладний текст (тип поля з дзеркала _key). */
    static function translatable_block_fields($data) {
        $fields = [];
        foreach ($data as $k => $v) {
            if ($k === '' || $k[0] === '_') continue;
            if (!is_string($v) || trim($v) === '') continue;
            if (!isset($data['_' . $k]) || !is_string($data['_' . $k])) continue;
            $def = function_exists('acf_get_field') ? acf_get_field($data['_' . $k]) : null;
            if ($def && in_array($def['type'] ?? '', ['text', 'textarea', 'wysiwyg'], true)) {
                $fields[$k] = $v;
            }
        }
        return $fields;
    }

    // ── translate_apply ───────────────────────────────────────────────────

    static function apply($args) {
        $src = intval($args['source_id'] ?? 0);
        $post = $src ? get_post($src) : null;
        if (!$post) return self::err('source post not found');
        $tr = $args['translated'] ?? null;
        if (!is_array($tr) || !$tr) return self::err('"translated" object required');
        if (isset($tr['content_html']) && isset($tr['blocks'])) {
            return self::err('pass content_html OR blocks, not both');
        }
        if (isset($tr['seo']) && !function_exists('aioseo')) {
            return self::err('translated.seo passed but AIOSEO is not active');
        }
        $status = sanitize_key($args['status'] ?? 'publish');
        if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) {
            return self::err('status must be publish|draft|pending|private');
        }

        // Ціль: існуючий переклад або новий draft-дублікат
        $ensured = Simple_MCP_Tools_Wploc::ensure_translation($src, (string) ($args['lang'] ?? ''), 'draft');
        if (is_wp_error($ensured)) return self::err($ensured->get_error_message());
        $target  = $ensured['id'];
        $created = $ensured['created'];
        $notes   = [];

        // publish лише якщо джерело саме опубліковане — інакше чернетка
        if ($status === 'publish' && $post->post_status !== 'publish') {
            $status = 'draft';
            $notes[] = 'source is not published — target kept draft';
        }

        // На час запису знімаємо back-sync статусу wp-loc (він би пушнув status на джерело/сиблінгів)
        $detached = false;
        if (class_exists('WP_LOC') && isset(WP_LOC::instance()->content)) {
            $detached = remove_action('save_post', [WP_LOC::instance()->content, 'sync_translations'], 30);
        }

        try {
            $applied = ['title' => false, 'excerpt' => false, 'content_html' => false, 'blocks_updated' => [], 'fields_count' => 0, 'seo' => []];
            $verified = null;

            // 1) Тіло: html або блокові поля
            if (array_key_exists('content_html', $tr)) {
                $verified = Simple_MCP_Tools::save_post_content($target, (string) $tr['content_html']);
                if (is_wp_error($verified)) return self::err($verified->get_error_message());
                $applied['content_html'] = true;
            } elseif (!empty($tr['blocks'])) {
                if (!is_array($tr['blocks'])) return self::err('"blocks" must be an array of {index, fields}');
                $r = self::apply_blocks($src, $target, $tr['blocks']);
                if (is_wp_error($r)) return self::err($r->get_error_message());
                $verified = $r['verified'];
                $applied['blocks_updated'] = $r['indices'];
                $applied['fields_count']   = $r['fields_count'];
            }

            // 2) title / excerpt / status одним апдейтом
            $postarr = ['ID' => $target, 'post_status' => $status];
            if (isset($tr['title']))   { $postarr['post_title']   = wp_slash((string) $tr['title']);   $applied['title'] = true; }
            if (isset($tr['excerpt'])) { $postarr['post_excerpt'] = wp_slash((string) $tr['excerpt']); $applied['excerpt'] = true; }
            $u = wp_update_post($postarr, true);
            if (is_wp_error($u)) return self::err('target update failed: ' . $u->get_error_message());

            // 3) SEO (partial-safe шлях SEO-модуля)
            if (!empty($tr['seo']) && is_array($tr['seo'])) {
                $seo_fields = array_intersect_key($tr['seo'], array_flip(self::SEO_FIELDS));
                $bad = array_diff(array_keys((array) $tr['seo']), self::SEO_FIELDS);
                if ($bad) return self::err('unknown seo field(s): ' . implode(', ', $bad) . ' — allowed: ' . implode(', ', self::SEO_FIELDS));
                if ($seo_fields) {
                    $sr = Simple_MCP_Tools_SEO::update(['post_id' => $target, 'fields' => $seo_fields]);
                    if (!empty($sr['isError'])) return self::err('seo write failed: ' . $sr['content'][0]['text']);
                    $applied['seo'] = array_keys($seo_fields);
                }
            }
        } finally {
            if ($detached) {
                add_action('save_post', [WP_LOC::instance()->content, 'sync_translations'], 30, 2);
            }
        }

        // 4) Товар: детермінований фінальний синк структури з джерела
        $wc_synced = false;
        if ($post->post_type === 'product' && class_exists('WP_LOC_WC')) {
            WP_LOC_WC::instance()->variations->sync_product($target);
            $wc_synced = true;
        }

        clean_post_cache($target);
        return self::ok([
            'target_id'        => $target,
            'created'          => $created,
            'language'         => $ensured['language'],
            'status'           => get_post_status($target),
            'content_verified' => $verified,
            'applied'          => $applied,
            'wc_synced'        => $wc_synced,
            'notes'            => $notes,
        ]);
    }

    /**
     * Заміна значень блокових полів у ЦІЛІ за індексами ДЖЕРЕЛА.
     * Validate-all-then-write: жодного запису, поки всі індекси/імена/ключі не звірені.
     */
    static function apply_blocks($src, $target, $specs) {
        $src_blocks = parse_blocks(get_post($src)->post_content);
        $tgt_blocks = parse_blocks(get_post($target)->post_content);
        $src_raw = self::raw_indices($src_blocks);
        $tgt_raw = self::raw_indices($tgt_blocks);

        // Пас 1: валідація всіх специфікацій
        $writes = []; // [raw_target_index => [k => v]]
        $seen = [];
        foreach ($specs as $spec) {
            if (!is_array($spec) || !isset($spec['index']) || empty($spec['fields']) || !is_array($spec['fields'])) {
                return new WP_Error('badspec', 'each blocks item must be {index, fields:{name:value}}');
            }
            $i = intval($spec['index']);
            if (isset($seen[$i])) return new WP_Error('dupindex', 'duplicate block index ' . $i);
            $seen[$i] = true;
            if (!isset($src_raw[$i]) || !isset($tgt_raw[$i])) {
                return new WP_Error('range', 'block index ' . $i . ' out of range (source has ' . count($src_raw) . ', target has ' . count($tgt_raw) . ' top-level blocks)');
            }
            $sb = $src_blocks[$src_raw[$i]];
            $tb = $tgt_blocks[$tgt_raw[$i]];
            if ($sb['blockName'] !== $tb['blockName']) {
                return new WP_Error('diverged', 'block #' . $i . ' is "' . $tb['blockName'] . '" in target but "' . $sb['blockName'] . '" in source — target structure has diverged from the source copy; re-copy content or use block_update manually');
            }
            $tdata = $tb['attrs']['data'] ?? [];
            if (!is_array($tdata)) return new WP_Error('nodata', 'block #' . $i . ' has no ACF data in target');
            $bad = [];
            foreach ($spec['fields'] as $k => $v) {
                if (!array_key_exists($k, $tdata) || !is_string($tdata[$k]) || !is_string($v)) $bad[] = $k;
            }
            if ($bad) {
                return new WP_Error('badkeys', 'block #' . $i . ': field(s) not present as strings in target: ' . implode(', ', $bad) . ' — take keys from translate_get');
            }
            $writes[$tgt_raw[$i]] = $spec['fields'];
        }

        // Пас 2: запис
        $count = 0;
        foreach ($writes as $raw => $fields) {
            foreach ($fields as $k => $v) {
                $tgt_blocks[$raw]['attrs']['data'][$k] = $v;
                $count++;
            }
        }
        $verified = Simple_MCP_Tools::save_post_content($target, serialize_blocks($tgt_blocks));
        if (is_wp_error($verified)) return $verified;

        return ['verified' => $verified, 'indices' => array_map('intval', array_keys($seen)), 'fields_count' => $count];
    }

    /** Мапа: ординал серед непорожніх блоків → сирий індекс parse_blocks-масиву. */
    static function raw_indices($blocks) {
        $raw = [];
        foreach ($blocks as $i => $b) {
            if (!empty($b['blockName'])) $raw[] = $i;
        }
        return $raw;
    }
}
