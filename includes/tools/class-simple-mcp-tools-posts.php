<?php
/**
 * Posts toolset (group 'mcp' — every MCP role): find posts, assign terms, list/restore rollback
 * points and flush caches, so routine content work never needs wp_cli.
 *
 * Every callback re-checks native WordPress capabilities on the objects it touches. Also hosts the
 * small multilingual/term helpers shared with the content module (both files load on every request).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Posts {

    /** Post types that are never content: WordPress/ACF internals (wp_* and acf-* prefixes are internal too). */
    const INTERNAL_TYPES = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request'];

    /** Max bytes of content per entry in revision_list (full content: get_post {id: revision_id}). */
    const REVISION_CONTENT_MAX = 100000;

    static function defs() {
        return [
            'find_posts' => [
                'title'       => 'Find posts',
                'description' => 'Find posts/pages/CPT items (attachments only when post_type asks for them) without wp_cli. Filters: post_type (string or list; default: every editable content type except attachments — non-public types only if you can edit them), status (string or list; default: every non-internal status, i.e. not trash), search, lang, parent, author, ids. With lang, only items registered in that language are returned (unregistered items are excluded); without lang, all languages are returned and on multilingual sites each item carries its own lang. Returns {total, page, per_page, pages, skipped, posts:[{id,title,type,status,slug,parent,author,modified,url,lang?}]}. Only items your WordPress user can read are returned: unless you can edit others\' items of a type, other users\' unpublished items and their items of non-public types are filtered out in the query, and the rare rows the per-item read check still rejects (e.g. password-protected posts) are counted in skipped, so a page can hold fewer than per_page items.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_type' => ['type' => ['string', 'array'], 'items' => ['type' => 'string'], 'description' => 'post type slug or list of slugs'],
                        'status'    => ['type' => ['string', 'array'], 'items' => ['type' => 'string'], 'description' => 'publish, draft, pending, private, future, trash, inherit (attachments) or a list'],
                        'search'    => ['type' => 'string', 'description' => 'keyword search in title/excerpt/content'],
                        'lang'      => ['type' => 'string', 'description' => 'URL slug ("ua") or wpml_code ("uk"); multilingual sites only'],
                        'parent'    => ['type' => 'integer', 'description' => 'only children of this post ID (0 = top level)'],
                        'author'    => ['type' => 'integer', 'description' => 'author user ID'],
                        'ids'       => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 100, 'description' => 'restrict to these post IDs'],
                        'per_page'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'default 20'],
                        'page'      => ['type' => 'integer', 'minimum' => 1, 'description' => 'default 1'],
                        'orderby'   => ['type' => 'string', 'enum' => ['date', 'modified', 'title', 'menu_order', 'ID', 'name', 'parent', 'relevance', 'post__in'], 'description' => 'default date (relevance when search is given)'],
                        'order'     => ['type' => 'string', 'enum' => ['ASC', 'DESC', 'asc', 'desc'], 'description' => 'default DESC'],
                    ]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'find_posts'],
            ],
            'set_post_terms' => [
                'title'       => 'Set post terms',
                'description' => 'Assign taxonomy terms to a post (native wp_set_object_terms). terms: integers are term IDs; strings are matched by slug, then by exact name (on multilingual sites a match in the post\'s language wins). Replaces the post\'s terms in that taxonomy unless append:true; an empty list without append removes them all. Unmatched strings fail the call before anything changes, unless create_missing:true creates them (in the post\'s language on multilingual sites), which needs the same capability as creating a term in the block editor (edit_terms for hierarchical taxonomies, assign_terms for flat ones). Requires edit_post on the post and assign_terms on the taxonomy; a post in the trash is refused (restore it first with update_post). Returns the post\'s resulting terms; on multilingual sites with term sync on, sync_note explains that the next save of any translation propagates terms across its translation group.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'        => ['type' => 'integer'],
                        'taxonomy'       => ['type' => 'string', 'description' => 'e.g. category, post_tag or a custom taxonomy registered for this post type'],
                        'terms'          => ['type' => 'array', 'items' => ['type' => ['integer', 'string']], 'description' => 'term IDs (integers) and/or slugs or names (strings)'],
                        'append'         => ['type' => 'boolean', 'description' => 'add to the existing terms instead of replacing them (default false)'],
                        'create_missing' => ['type' => 'boolean', 'description' => 'create string terms that do not exist yet (default false)'],
                    ],
                    'required' => ['post_id', 'taxonomy', 'terms']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'set_post_terms'],
            ],
            'revision_list' => [
                'title'       => 'List revisions',
                'description' => 'List the rollback points of a post: WordPress revisions (newest first, autosaves flagged) and Simple MCP meta backups (_simple_mcp_backup, used when the post type keeps no revisions; legacy pre-2.5.0 backups are flagged). Each entry has its id, time, author, bytes and etag (md5 of its content — compare with current_etag to see which one matches the live post). include_content:true adds each content, capped at 100 KB (read a full revision with get_post {id: revision_id}). limit caps the number of revisions returned (default 20, max 100). Requires edit_post on the post. Restore one with revision_restore.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'         => ['type' => 'integer'],
                        'include_content' => ['type' => 'boolean', 'description' => 'default false'],
                        'limit'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'max revisions to list (default 20)'],
                    ],
                    'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'revision_list'],
            ],
            'revision_restore' => [
                'title'       => 'Restore revision',
                'description' => 'Restore a post to one of its rollback points (ids from revision_list). Pass exactly one of revision_id (a WordPress revision of this post: restores its title, content and excerpt via wp_restore_post_revision) or backup_id (a Simple MCP meta backup: restores the body). The current state is saved as a rollback point first. Legacy backups (made before 2.5.0, stored unslashed, so their \\uXXXX escapes may be damaged) are refused unless allow_legacy:true. if_match (an etag from block_get or revision_list current_etag) refuses the restore when the post changed since you read it. Serialized per post with other MCP writes. Requires edit_post; a post in the trash is refused (restore it first with update_post). Returns content_verified and the new etag, plus sync_note when the multilingual plugin copies this post\'s attributes to its translations on save.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'      => ['type' => 'integer'],
                        'revision_id'  => ['type' => 'integer'],
                        'backup_id'    => ['type' => 'integer'],
                        'if_match'     => ['type' => 'string', 'description' => 'etag the post must still have'],
                        'allow_legacy' => ['type' => 'boolean', 'description' => 'allow restoring a legacy (pre-2.5.0) backup'],
                    ],
                    'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'revision_restore'],
            ],
            'cache_flush' => [
                'title'       => 'Flush cache',
                'description' => 'Flush caches after content edits. With post_id (needs edit_post on it): clean_post_cache plus the per-post purge of every detected page-cache plugin (W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Rocket, WP Fastest Cache, Cache Enabler, SiteGround Optimizer, Hummingbird, WP-Optimize, Nginx Helper, WP Engine). Without post_id: flushes the whole object cache and every detected page cache (needs manage_options). Returns what was flushed; page_cache:false means no supported page-cache plugin was detected, so only the object cache was cleared.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer', 'description' => 'omit to flush the whole site']]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'cache_flush'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    // ── Shared helpers (also used by Simple_MCP_Tools_Content) ────────────

    /** Is a multilingual system (wp-loc / WPML) active and the multilingual module loaded? */
    static function ml() {
        return class_exists('Simple_MCP_Tools_Wploc') && (bool) Simple_MCP::multilingual_system();
    }

    /** WordPress/ACF internal post type (never content)? $allow_attachment lets attachments through. */
    static function is_internal_type($pt, $allow_attachment = false) {
        $pt = (string) $pt;
        if ($allow_attachment && $pt === 'attachment') return false;
        return in_array($pt, self::INTERNAL_TYPES, true) || strpos($pt, 'wp_') === 0 || strpos($pt, 'acf-') === 0;
    }

    /** Resolve a lang argument strictly. Returns the resolve_lang() array or an MCP error (array with isError). */
    static function lang_arg($lang, $allow_inactive = false) {
        if (!self::ml()) return self::err('lang requires an active multilingual system (wp-loc / WPML)');
        $l = Simple_MCP_Tools_Wploc::resolve_lang((string) $lang, $allow_inactive);
        if (is_wp_error($l)) return self::err($l->get_error_message());
        return $l;
    }

    /** The site default language as a resolve_lang() array, or null. */
    static function default_lang() {
        if (!self::ml()) return null;
        $d = apply_filters('wpml_default_language', null);
        if (!$d) return null;
        $l = Simple_MCP_Tools_Wploc::resolve_lang((string) $d);
        return is_wp_error($l) ? null : $l;
    }

    /** Language code of a post (or null when not registered / not multilingual). */
    static function post_lang($post) {
        $post = get_post($post);
        if (!$post || !self::ml()) return null;
        $code = apply_filters('wpml_element_language_code', null, ['element_id' => $post->ID, 'element_type' => 'post_' . $post->post_type]);
        return $code ? (string) $code : null;
    }

    /** Run $fn with the multilingual system switched to $code (restored afterwards, also on exceptions). */
    static function in_language($code, callable $fn) {
        if (!$code || !self::ml()) return $fn();
        $prev = apply_filters('wpml_current_language', null);
        $switch = ($prev !== $code);
        if ($switch) do_action('wpml_switch_language', $code);
        try {
            return $fn();
        } finally {
            if ($switch) do_action('wpml_switch_language', $prev);
        }
    }

    /**
     * Run $fn with raw term lookups: no wp-loc/WPML "adjust term to the current language" swap in
     * get_term(). With $all_languages (lookups/assignment) also term_exists() unfiltered by language
     * and (WPML) queries in all languages; without it (inserting/updating a term) the language
     * context stays as is, so slug/duplicate checks run in the term's own language.
     * Without this, in a front-end-like MCP request get_term(42) can return 42's translation and
     * wp_set_object_terms() silently drops term IDs of other languages.
     */
    static function raw_terms(callable $fn, $all_languages = true) {
        add_filter('wpml_disable_term_adjust_id', [__CLASS__, 'filter_true'], 99);
        $wpml = $all_languages && Simple_MCP::multilingual_system() === 'wpml';
        $prev = $wpml ? apply_filters('wpml_current_language', null) : null;
        if ($all_languages) add_filter('term_exists_default_query_args', [__CLASS__, 'filter_all_langs'], 99);
        if ($wpml) do_action('wpml_switch_language', 'all');
        try {
            return $fn();
        } finally {
            if ($wpml) do_action('wpml_switch_language', $prev);
            if ($all_languages) remove_filter('term_exists_default_query_args', [__CLASS__, 'filter_all_langs'], 99);
            remove_filter('wpml_disable_term_adjust_id', [__CLASS__, 'filter_true'], 99);
        }
    }

    static function filter_true() { return true; }

    /** wp-loc reads args['lang']; 'all' switches its language filter off for this lookup. */
    static function filter_all_langs($args) {
        $args['lang'] = 'all';
        return $args;
    }

    /** get_term() without the multilingual ID swap. WP_Term or null. */
    static function raw_term($term_id, $taxonomy) {
        return self::raw_terms(function () use ($term_id, $taxonomy) {
            $t = get_term((int) $term_id, $taxonomy);
            return ($t instanceof WP_Term) ? $t : null;
        });
    }

    /** Capability to create a term, as in the core REST terms controller: hierarchical → edit_terms, flat → assign_terms. */
    static function term_create_cap($txo) {
        return is_taxonomy_hierarchical($txo->name) ? $txo->cap->edit_terms : $txo->cap->assign_terms;
    }

    /** Does the translations table ({prefix}icl_translations, shared by wp-loc and WPML) exist? */
    static function icl_table() {
        static $t = null;
        if ($t !== null) return $t;
        global $wpdb;
        $name = $wpdb->prefix . 'icl_translations';
        $t = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) === $name) ? $name : '';
        return $t;
    }

    /**
     * Languages of many elements in one query. $ids — element IDs (post ID / term_taxonomy_id),
     * $etypes — element types ('post_page', 'tax_category', …). Returns ['{etype}:{id}' => code].
     */
    static function element_langs(array $ids, array $etypes) {
        $t = self::ml() ? self::icl_table() : '';
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $etypes = array_values(array_unique(array_map('strval', $etypes)));
        if ($t === '' || !$ids || !$etypes) return [];
        global $wpdb;
        $sql = "SELECT element_id, element_type, language_code FROM {$t} WHERE element_id IN (" . implode(',', $ids) . ')'
            . ' AND element_type IN (' . implode(',', array_fill(0, count($etypes), '%s')) . ')';
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $etypes)) as $r) {
            $out[$r->element_type . ':' . (int) $r->element_id] = (string) $r->language_code;
        }
        return $out;
    }

    /** SQL "IN (…)" list of quoted strings. */
    static function sql_in(array $values) {
        global $wpdb;
        return implode(',', array_map(function ($v) use ($wpdb) { return $wpdb->prepare('%s', (string) $v); }, $values));
    }

    /** One-line explanation when content_verified is false because kses filtered the HTML (no unfiltered_html). */
    static function kses_note($verified) {
        if ($verified !== false || current_user_can('unfiltered_html')) return null;
        return 'Your WordPress user lacks unfiltered_html, so WordPress (kses) filtered the HTML on save: the stored content differs from what was sent. Compare it with get_post and remove the disallowed markup.';
    }

    // ── find_posts ────────────────────────────────────────────────────────

    static function find_posts($args) {
        // post types: default = every editable (show_ui) non-internal type, attachments excluded;
        // non-viewable types (logs, bookings…) only when the user can edit items of that type
        $types = $args['post_type'] ?? null;
        if ($types === null || $types === '' || $types === [] || $types === 'any') {
            $types = [];
            foreach (get_post_types(['show_ui' => true], 'objects') as $pt => $pto) {
                if (self::is_internal_type($pt)) continue;
                if (!is_post_type_viewable($pto) && !current_user_can($pto->cap->edit_posts)) continue;
                $types[] = $pt;
            }
        } else {
            $types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $types))));
            foreach ($types as $pt) {
                if (!post_type_exists($pt)) return self::err('unknown post_type: ' . $pt);
                if ($pt === 'revision') return self::err('revisions are listed with revision_list {post_id}');
                if (self::is_internal_type($pt, true)) return self::err('post_type "' . $pt . '" is internal to WordPress and cannot be searched here');
            }
        }
        if (!$types) return self::err('no searchable post types');

        // statuses: default = every non-internal status (+ inherit for attachments)
        $allowed = array_keys(get_post_stati(['internal' => false]));
        $allowed_all = array_merge($allowed, ['trash', 'inherit']);
        if (isset($args['status']) && $args['status'] !== '' && $args['status'] !== []) {
            $statuses = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $args['status']))));
            foreach ($statuses as $s) {
                if (!in_array($s, $allowed_all, true)) return self::err('unknown status "' . $s . '". Allowed: ' . implode(', ', $allowed_all));
            }
        } else {
            $statuses = $allowed;
            if (in_array('attachment', $types, true)) $statuses[] = 'inherit';
        }

        $per   = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page  = max(1, (int) ($args['page'] ?? 1));
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $orderby = (string) ($args['orderby'] ?? ($search !== '' ? 'relevance' : 'date'));
        if (!in_array($orderby, ['date', 'modified', 'title', 'menu_order', 'ID', 'name', 'parent', 'relevance', 'post__in'], true)) {
            return self::err('unsupported orderby "' . $orderby . '"');
        }
        if ($orderby === 'relevance' && $search === '') $orderby = 'date';
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $q = [
            'post_type'              => $types,
            'post_status'            => $statuses,
            'posts_per_page'         => $per,
            'paged'                  => $page,
            'orderby'                => $orderby,
            'order'                  => $order,
            // no 'perm' => 'readable': with several post types WP_Query checks read_private_multiple_post_types,
            // which nobody has; smcp_readable/smcp_att below and the per-row can_read_post do the narrowing
            'ignore_sticky_posts'    => true,
            'suppress_filters'       => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'lang'                   => 'all', // wp-loc: switch its current-language query filter off
        ];
        if ($search !== '') $q['s'] = $search;
        if (isset($args['parent'])) $q['post_parent'] = max(0, (int) $args['parent']);
        if (!empty($args['author'])) $q['author'] = (int) $args['author'];
        if (!empty($args['ids'])) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $args['ids'])))), 0, 100);
            $q['post__in'] = $ids ?: [0];
        } elseif ($orderby === 'post__in') {
            $q['orderby'] = 'date';
        }

        $ml = self::ml();
        $lang = null;
        if (isset($args['lang']) && $args['lang'] !== '') {
            $lang = self::lang_arg($args['lang'], true);
            if (isset($lang['isError'])) return $lang;
            if (self::icl_table() === '') return self::err('translations table not found — cannot filter by language');
            $q['smcp_lang'] = array_values(array_unique([$lang['code'], $lang['slug']]));
        }

        // Narrow in SQL to what the user can plausibly read (a superset of can_read_post, which still
        // filters every row), so total/pages are not inflated by other authors' private items.
        $restricted = [];
        foreach ($types as $pt) {
            $pto = get_post_type_object($pt);
            if ($pt === 'attachment' || !$pto || current_user_can($pto->cap->edit_others_posts)) continue; // attachments: readable via parent
            $restricted[$pt] = ['viewable' => is_post_type_viewable($pto), 'private' => current_user_can($pto->cap->read_private_posts)];
        }
        if ($restricted) $q['smcp_readable'] = ['uid' => get_current_user_id(), 'types' => $restricted];
        // Attachments are readable when unattached or when their parent is (can_read_post): leave out in SQL
        // those whose parent exists and is not plausibly readable, so total/skipped do not reveal them.
        $ato = get_post_type_object('attachment');
        if (in_array('attachment', $types, true) && $ato && !current_user_can($ato->cap->edit_others_posts)) {
            $parents = [];
            foreach (get_post_types([], 'objects') as $pt => $pto) {
                if ($pt === 'attachment' || current_user_can($pto->cap->edit_others_posts)) continue;
                $parents[$pt] = ['viewable' => is_post_type_viewable($pto), 'private' => current_user_can($pto->cap->read_private_posts)];
            }
            if ($parents) $q['smcp_att'] = ['uid' => get_current_user_id(), 'types' => $parents];
        }

        add_filter('posts_clauses', [__CLASS__, 'find_posts_clauses'], 20, 2);
        $wpml = Simple_MCP::multilingual_system() === 'wpml';
        $prev = $wpml ? apply_filters('wpml_current_language', null) : null;
        if ($wpml) do_action('wpml_switch_language', 'all'); // WPML: no current-language filter
        try {
            $wpq = new WP_Query($q);
        } finally {
            if ($wpml) do_action('wpml_switch_language', $prev);
            remove_filter('posts_clauses', [__CLASS__, 'find_posts_clauses'], 20);
        }

        $langs = [];
        if ($ml && $wpq->posts) {
            $langs = self::element_langs(wp_list_pluck($wpq->posts, 'ID'), array_map(function ($p) { return 'post_' . $p->post_type; }, $wpq->posts));
        }
        $items = [];
        $skipped = 0;
        foreach ($wpq->posts as $p) {
            if (!Simple_MCP_Tools::can_read_post($p)) { $skipped++; continue; }
            $item = [
                'id'       => (int) $p->ID,
                'title'    => $p->post_title,
                'type'     => $p->post_type,
                'status'   => $p->post_status,
                'slug'     => $p->post_name,
                'parent'   => (int) $p->post_parent,
                'author'   => (int) $p->post_author,
                'modified' => $p->post_modified,
                'url'      => get_permalink($p),
            ];
            if ($ml) $item['lang'] = $langs['post_' . $p->post_type . ':' . $p->ID] ?? null;
            $items[] = $item;
        }
        $out = [
            'total'    => (int) $wpq->found_posts,
            'page'     => $page,
            'per_page' => $per,
            'pages'    => (int) $wpq->max_num_pages,
            'skipped'  => $skipped,
            'posts'    => $items,
        ];
        if ($lang) $out['lang'] = $lang['code'];
        return self::ok($out);
    }

    /**
     * posts_clauses for find_posts queries (marked by their smcp_* query vars): smcp_lang — only elements
     * registered in those language codes; smcp_readable — for types the user cannot edit others' items of,
     * only own items, published public items and (with read_private_posts) private ones; smcp_att — the
     * same test on the PARENT of attachments (an attachment without an existing parent passes).
     */
    static function find_posts_clauses($clauses, $query) {
        global $wpdb;
        $codes = $query->get('smcp_lang');
        $t = self::icl_table();
        if (!empty($codes) && is_array($codes) && $t !== '') {
            $clauses['join']  .= " INNER JOIN {$t} smcp_pl ON smcp_pl.element_id = {$wpdb->posts}.ID"
                . " AND smcp_pl.element_type = CONCAT('post_', {$wpdb->posts}.post_type)";
            $clauses['where'] .= ' AND smcp_pl.language_code IN (' . self::sql_in($codes) . ')';
        }
        $rd = $query->get('smcp_readable');
        if (!empty($rd['types']) && is_array($rd['types'])) {
            $all = array_keys($rd['types']);
            $pub = array_keys(array_filter($rd['types'], function ($x) { return !empty($x['viewable']); }));
            $prv = array_keys(array_filter($rd['types'], function ($x) { return !empty($x['private']); }));
            $or = ["{$wpdb->posts}.post_type NOT IN (" . self::sql_in($all) . ')', $wpdb->prepare("{$wpdb->posts}.post_author = %d", (int) $rd['uid'])];
            if ($pub) $or[] = "({$wpdb->posts}.post_type IN (" . self::sql_in($pub) . ") AND {$wpdb->posts}.post_status = 'publish' AND {$wpdb->posts}.post_password = '')";
            if ($prv) $or[] = "({$wpdb->posts}.post_type IN (" . self::sql_in($prv) . ") AND {$wpdb->posts}.post_status = 'private' AND {$wpdb->posts}.post_password = '')";
            $clauses['where'] .= ' AND (' . implode(' OR ', $or) . ')';
        }
        $at = $query->get('smcp_att');
        if (!empty($at['types']) && is_array($at['types'])) {
            $pp  = 'smcp_pp';
            $pub = array_keys(array_filter($at['types'], function ($x) { return !empty($x['viewable']); }));
            $prv = array_keys(array_filter($at['types'], function ($x) { return !empty($x['private']); }));
            $ok  = ["{$pp}.post_type NOT IN (" . self::sql_in(array_keys($at['types'])) . ')', $wpdb->prepare("{$pp}.post_author = %d", (int) $at['uid'])];
            if ($pub) $ok[] = "({$pp}.post_type IN (" . self::sql_in($pub) . ") AND {$pp}.post_status = 'publish' AND {$pp}.post_password = '')";
            if ($prv) $ok[] = "({$pp}.post_type IN (" . self::sql_in($prv) . ") AND {$pp}.post_status = 'private' AND {$pp}.post_password = '')";
            $clauses['where'] .= " AND ({$wpdb->posts}.post_type <> 'attachment' OR NOT EXISTS (SELECT 1 FROM {$wpdb->posts} {$pp}"
                . " WHERE {$pp}.ID = {$wpdb->posts}.post_parent AND NOT (" . implode(' OR ', $ok) . ')))';
        }
        return $clauses;
    }

    // ── set_post_terms ────────────────────────────────────────────────────

    static function set_post_terms($args) {
        $post = Simple_MCP_Tools::writable_post($args['post_id'] ?? 0);
        if (is_array($post)) return $post;
        $tax = sanitize_key((string) ($args['taxonomy'] ?? ''));
        $txo = $tax !== '' ? get_taxonomy($tax) : null;
        if (!$txo) return self::err('unknown taxonomy: ' . $tax);
        if (!is_object_in_taxonomy($post->post_type, $tax)) {
            return self::err('taxonomy "' . $tax . '" is not registered for post type "' . $post->post_type . '"');
        }
        if (!current_user_can($txo->cap->assign_terms)) return Simple_MCP_Tools::err_cap($txo->cap->assign_terms . ' (' . $tax . ')');
        if (!isset($args['terms']) || !is_array($args['terms'])) return self::err('terms must be an array of term IDs and/or slugs/names');
        $append = !empty($args['append']);
        $create = !empty($args['create_missing']);
        $post_lang = self::post_lang($post);

        // 1) resolve every reference before changing anything
        $ids = [];
        $missing = [];
        $ambiguous = [];
        foreach ($args['terms'] as $ref) {
            if (is_int($ref) || is_float($ref)) {
                $t = self::raw_term((int) $ref, $tax);
                if (!$t) return self::err('term #' . (int) $ref . ' does not exist in taxonomy "' . $tax . '"');
                $ids[] = (int) $t->term_id;
                continue;
            }
            $s = trim((string) $ref);
            if ($s === '') continue;
            $found = self::find_term_by_string($s, $tax, $post_lang);
            if (is_array($found)) { $ambiguous[$s] = $found; continue; }
            if ($found) { $ids[] = (int) $found->term_id; continue; }
            $missing[] = $s;
        }
        if ($ambiguous) {
            return self::err('ambiguous term reference(s), pass the term ID instead: ' . wp_json_encode($ambiguous, JSON_UNESCAPED_UNICODE));
        }
        if ($missing && !$create) {
            return self::err('term(s) not found in "' . $tax . '": ' . wp_json_encode($missing, JSON_UNESCAPED_UNICODE) . '. Pass create_missing:true to create them, or use term IDs.');
        }

        // 2) per-term native cap (assign_term meta cap, as in the REST posts controller)
        foreach (array_unique($ids) as $tid) {
            if (!current_user_can('assign_term', $tid)) return Simple_MCP_Tools::err_cap('assign_term #' . $tid);
        }
        $created = [];
        if ($missing) {
            $cap = self::term_create_cap($txo);
            if (!current_user_can($cap)) return Simple_MCP_Tools::err_cap($cap . ' (create terms in ' . $tax . ')');
            $def = $post_lang ? null : self::default_lang();
            $term_lang = $post_lang ?: ($def ? $def['code'] : null);
            foreach (array_unique($missing) as $name) {
                $r = self::insert_term($tax, $name, [], $term_lang);
                if (isset($r['isError'])) return self::err('could not create term "' . $name . '": ' . ($r['content'][0]['text'] ?? 'error')
                    . ($created ? ' (already created: ' . wp_json_encode($created, JSON_UNESCAPED_UNICODE) . '; terms of the post were not changed)' : ''));
                $created[] = ['term_id' => $r['term_id'], 'name' => $name] + (isset($r['language_warning']) ? ['language_warning' => $r['language_warning']] : []);
                $ids[] = (int) $r['term_id'];
            }
        }

        // 3) assign by ID (integers: never auto-creates), with raw (language-neutral) term lookups
        $ids = array_values(array_unique(array_map('intval', $ids)));
        // Simple_MCP_Tools::with_raw_terms also drops cached term queries/relationships, whose IDs may be language-swapped
        // (wp_set_object_terms diffs the new IDs against the post's old ones)
        $res = Simple_MCP_Tools::with_raw_terms(function () use ($post, $ids, $tax, $append) {
            return self::raw_terms(function () use ($post, $ids, $tax, $append) {
                return wp_set_object_terms($post->ID, $ids, $tax, $append);
            });
        }, $post->ID);
        if (is_wp_error($res)) return self::err('could not set terms: ' . $res->get_error_message());

        $now = self::raw_terms(function () use ($post, $tax) {
            return wp_get_object_terms($post->ID, $tax, ['orderby' => 'name']);
        });
        $now = is_wp_error($now) ? [] : $now;
        $langs = $post_lang ? self::element_langs(wp_list_pluck($now, 'term_taxonomy_id'), ['tax_' . $tax]) : [];
        $terms = [];
        $mismatch = [];
        foreach ($now as $t) {
            $row = ['term_id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
            $tl = $langs['tax_' . $tax . ':' . (int) $t->term_taxonomy_id] ?? null;
            if ($post_lang) $row['lang'] = $tl;
            if ($post_lang && $tl && $tl !== $post_lang) $mismatch[] = (int) $t->term_id;
            $terms[] = $row;
        }
        $not_kept = array_values(array_diff($ids, array_map('intval', wp_list_pluck($now, 'term_id'))));

        $out = ['post_id' => (int) $post->ID, 'taxonomy' => $tax, 'append' => $append, 'terms' => $terms];
        if ($created) $out['created'] = $created;
        if ($not_kept) $out['not_assigned'] = $not_kept; // a hook rejected them
        if ($post_lang) $out['post_language'] = $post_lang;
        if ($mismatch) $out['language_mismatch'] = $mismatch;
        if (self::ml()) {
            $note = Simple_MCP_Tools_Wploc::sync_note();
            if ($note) $out['sync_note'] = $note;
        }
        return self::ok($out);
    }

    /**
     * Find a term by slug, then by exact name, in all languages. WP_Term, null (not found) or a list of
     * candidate term IDs (ambiguous: several matches and none — or several — in the post's language).
     */
    static function find_term_by_string($s, $tax, $post_lang) {
        foreach (['slug' => sanitize_title($s), 'name' => $s] as $field => $val) {
            if ($val === '') continue;
            $found = self::raw_terms(function () use ($tax, $field, $val) {
                return get_terms(['taxonomy' => $tax, $field => $val, 'hide_empty' => false, 'number' => 20, 'lang' => 'all']);
            });
            if (is_wp_error($found) || !$found) continue;
            if (count($found) === 1) return $found[0];
            if ($post_lang) {
                $langs = self::element_langs(wp_list_pluck($found, 'term_taxonomy_id'), ['tax_' . $tax]);
                $same = array_values(array_filter($found, function ($t) use ($langs, $tax, $post_lang) {
                    return ($langs['tax_' . $tax . ':' . (int) $t->term_taxonomy_id] ?? null) === $post_lang;
                }));
                if (count($same) === 1) return $same[0];
            }
            return array_map('intval', wp_list_pluck($found, 'term_id'));
        }
        return null;
    }

    /**
     * Create a term in a language (the multilingual context is switched to it during wp_insert_term,
     * so wp-loc/WPML register it there from the start) and register it via register_term_language.
     * Returns ['term_id','term_taxonomy_id','language'?, 'language_warning'?] or an MCP error.
     * Caller checks capabilities.
     */
    static function insert_term($tax, $name, array $targs, $lang_code) {
        $res = self::in_language($lang_code, function () use ($tax, $name, $targs) {
            return self::raw_terms(function () use ($tax, $name, $targs) {
                return wp_insert_term(wp_slash($name), $tax, wp_slash($targs));
            }, false);
        });
        if (is_wp_error($res)) {
            $existing = $res->get_error_data('term_exists');
            return self::err($res->get_error_message() . ($existing ? ' (existing term_id ' . (int) $existing . ')' : ''));
        }
        $out = ['term_id' => (int) $res['term_id'], 'term_taxonomy_id' => (int) $res['term_taxonomy_id']];
        if ($lang_code && self::ml()) {
            $r = Simple_MCP_Tools_Wploc::register_term_language($out['term_id'], $tax, $lang_code);
            // a taxonomy that is not translatable simply has no language
            if (is_wp_error($r) && $r->get_error_code() !== 'not_translatable') $out['language_warning'] = 'term created but its language was not registered: ' . $r->get_error_message();
        }
        $l = self::element_langs([$out['term_taxonomy_id']], ['tax_' . $tax]);
        $out['language'] = $l['tax_' . $tax . ':' . $out['term_taxonomy_id']] ?? null;
        return $out;
    }

    // ── revision_list / revision_restore ─────────────────────────────────

    static function revision_list($args) {
        $post = Simple_MCP_Tools::writable_post($args['post_id'] ?? 0, true); // edit_post on the parent; refuses revision IDs; read-only, so trashed posts are fine
        if (is_array($post)) return $post;
        $with = !empty($args['include_content']);
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));

        $total = function_exists('wp_get_latest_revision_id_and_total_count')
            ? wp_get_latest_revision_id_and_total_count($post->ID) : null;
        $revs = wp_get_post_revisions($post->ID, ['check_enabled' => false, 'posts_per_page' => $limit]);
        $list = [];
        foreach ($revs as $r) {
            $row = [
                'revision_id' => (int) $r->ID,
                'time'        => $r->post_modified,
                'author'      => (int) $r->post_author,
                'autosave'    => (bool) wp_is_post_autosave($r),
                'title'       => $r->post_title,
                'bytes'       => strlen($r->post_content),
                'etag'        => md5((string) $r->post_content),
            ];
            if ($with) self::attach_content($row, $r->post_content);
            $list[] = $row;
        }
        $backups = [];
        foreach (Simple_MCP_Tools::get_backups($post->ID) as $b) {
            $row = [
                'backup_id' => (int) $b['backup_id'],
                'time'      => $b['time'] ? wp_date('Y-m-d H:i:s', (int) $b['time']) : null,
                'author'    => (int) $b['user_id'],
                'legacy'    => (bool) $b['legacy'],
                'bytes'     => strlen($b['content']),
                'etag'      => md5((string) $b['content']),
            ];
            if ($with) self::attach_content($row, $b['content']);
            $backups[] = $row;
        }
        return self::ok([
            'post_id'           => (int) $post->ID,
            'current_etag'      => Simple_MCP_Tools::content_etag($post->ID),
            'revisions_enabled' => wp_revisions_enabled($post),
            'revisions_total'   => is_array($total) && !is_wp_error($total) ? (int) ($total['count'] ?? count($list)) : count($list),
            'revisions'         => $list,
            'backups'           => $backups,
        ]);
    }

    static function attach_content(array &$row, $content) {
        $cut = Simple_MCP_Tools::mb_cut((string) $content, self::REVISION_CONTENT_MAX);
        $row['content'] = $cut;
        if (strlen($cut) < strlen((string) $content)) $row['content_truncated'] = true;
    }

    static function revision_restore($args) {
        $post = Simple_MCP_Tools::writable_post($args['post_id'] ?? 0);
        if (is_array($post)) return $post;
        $pid = (int) $post->ID;
        $rev_id = (int) ($args['revision_id'] ?? 0);
        $bk_id  = (int) ($args['backup_id'] ?? 0);
        if (($rev_id > 0) === ($bk_id > 0)) return self::err('pass exactly one of revision_id or backup_id (ids come from revision_list)');

        return Simple_MCP_Tools::with_post_lock($pid, function () use ($pid, $rev_id, $bk_id, $args) {
            clean_post_cache($pid);
            $post = get_post($pid);
            if (!$post) return self::err('post not found');
            $pre = Simple_MCP_Tools::precondition($pid, $args);
            if ($pre) return $pre;
            $warnings = [];

            if ($rev_id) {
                $rev = wp_get_post_revision($rev_id);
                if (!$rev || (int) $rev->post_parent !== $pid) return self::err('revision #' . $rev_id . ' is not a revision of post #' . $pid . ' (see revision_list)');
                $expected = (string) $rev->post_content;
                // rollback point for the current state (a no-op revision when nothing changed since the last one)
                if (wp_revisions_enabled($post)) wp_save_post_revision($pid);
                else Simple_MCP_Tools::add_backup($pid, $post->post_content);
                // raw term context: on multilingual sites the save must not swap this post's terms for current-language ones
                $r = Simple_MCP_Tools::with_raw_terms(function () use ($rev_id) { return wp_restore_post_revision($rev_id); }, $pid);
                if (!$r || is_wp_error($r)) {
                    return self::err('restore failed' . (is_wp_error($r) ? ': ' . $r->get_error_message() : ''));
                }
                clean_post_cache($pid);
                $verified = Simple_MCP_Tools::content_matches(get_post($pid)->post_content, $expected);
                $restored = ['revision_id' => $rev_id];
            } else {
                $bk = null;
                foreach (Simple_MCP_Tools::get_backups($pid) as $b) {
                    if ((int) $b['backup_id'] === $bk_id) { $bk = $b; break; }
                }
                if (!$bk) return self::err('backup #' . $bk_id . ' not found for post #' . $pid . ' (see revision_list)');
                if ($bk['legacy']) {
                    if (empty($args['allow_legacy'])) {
                        return self::err('backup #' . $bk_id . ' is a legacy pre-2.5.0 backup stored without slashing, so its \\uXXXX escapes may be damaged. Inspect it with revision_list {include_content:true}, then pass allow_legacy:true to restore it anyway.');
                    }
                    $warnings[] = 'restored a legacy backup: check the rendered page (render_post) — its \\uXXXX escapes may have been damaged';
                }
                $expected = (string) $bk['content'];
                $v = Simple_MCP_Tools::save_post_content($pid, $expected);
                if (is_wp_error($v)) return self::err('restore failed: ' . $v->get_error_message());
                $verified = (bool) $v;
                $restored = ['backup_id' => $bk_id];
            }

            $out = ['post_id' => $pid, 'restored' => $restored, 'content_verified' => $verified, 'etag' => Simple_MCP_Tools::content_etag($pid)];
            $note = self::kses_note($verified);
            if ($note) $out['kses_note'] = $note;
            if ($warnings) $out['warnings'] = $warnings;
            // the restore is a normal save: wp-loc pushes this post's attributes to its translations
            if (self::ml() && Simple_MCP_Tools_Wploc::siblings($pid, 'post_' . $post->post_type)) {
                $sync = Simple_MCP_Tools_Wploc::sync_note();
                if ($sync) $out['sync_note'] = $sync;
            }
            return self::ok($out);
        });
    }

    // ── cache_flush ───────────────────────────────────────────────────────

    static function cache_flush($args) {
        $pid = (int) ($args['post_id'] ?? 0);
        $done = [];
        $failed = [];
        $page = false;
        $run = function ($label, callable $fn, $is_page = true) use (&$done, &$failed, &$page) {
            try {
                $fn();
                $done[] = $label;
                if ($is_page) $page = true;
            } catch (\Throwable $e) {
                $failed[] = $label . ': ' . $e->getMessage();
            }
        };

        if ($pid) {
            $post = get_post($pid);
            if (!$post) return self::err('post not found');
            if (!Simple_MCP_Tools::can_edit_post($pid)) return Simple_MCP_Tools::err_cap('edit_post #' . $pid);
            $url = get_permalink($pid);
            $run('object cache: post #' . $pid, function () use ($pid) { clean_post_cache($pid); }, false);
            if (function_exists('w3tc_flush_post'))           $run('W3 Total Cache', function () use ($pid) { w3tc_flush_post($pid); });
            if (function_exists('wpsc_delete_post_cache'))    $run('WP Super Cache', function () use ($pid) { wpsc_delete_post_cache($pid); });
            if (has_action('litespeed_purge_post'))           $run('LiteSpeed Cache', function () use ($pid) { do_action('litespeed_purge_post', $pid); });
            if (function_exists('rocket_clean_post'))         $run('WP Rocket', function () use ($pid) { rocket_clean_post($pid); });
            if (function_exists('wpfc_clear_post_cache_by_id')) $run('WP Fastest Cache', function () use ($pid) { wpfc_clear_post_cache_by_id($pid); });
            if (has_action('cache_enabler_clear_page_cache_by_post')) $run('Cache Enabler', function () use ($pid) { do_action('cache_enabler_clear_page_cache_by_post', $pid); });
            if (function_exists('sg_cachepress_purge_cache') && $url) $run('SiteGround Optimizer', function () use ($url) { sg_cachepress_purge_cache($url); });
            if (has_action('wphb_clear_page_cache'))          $run('Hummingbird', function () use ($pid) { do_action('wphb_clear_page_cache', $pid); });
            if (class_exists('WPO_Page_Cache') && method_exists('WPO_Page_Cache', 'delete_single_post_cache')) {
                $run('WP-Optimize', function () use ($pid) { WPO_Page_Cache::delete_single_post_cache($pid); });
            }
            global $nginx_purger;
            if (is_object($nginx_purger) && method_exists($nginx_purger, 'purge_url') && $url) $run('Nginx Helper', function () use ($nginx_purger, $url) { $nginx_purger->purge_url($url); });
            if (class_exists('WpeCommon') && method_exists('WpeCommon', 'purge_varnish_cache')) $run('WP Engine', function () use ($pid) { WpeCommon::purge_varnish_cache($pid); });
        } else {
            if (!current_user_can('manage_options')) return Simple_MCP_Tools::err_cap('manage_options (flushing the whole site cache)');
            $run('object cache (wp_cache_flush)', function () { wp_cache_flush(); }, false);
            if (function_exists('w3tc_flush_all'))            $run('W3 Total Cache', function () { w3tc_flush_all(); });
            if (function_exists('wp_cache_clear_cache'))      $run('WP Super Cache', function () { wp_cache_clear_cache(); });
            if (has_action('litespeed_purge_all'))            $run('LiteSpeed Cache', function () { do_action('litespeed_purge_all'); });
            if (function_exists('rocket_clean_domain'))       $run('WP Rocket', function () { rocket_clean_domain(); });
            if (function_exists('wpfc_clear_all_cache'))      $run('WP Fastest Cache', function () { wpfc_clear_all_cache(true); });
            if (has_action('cache_enabler_clear_complete_cache')) $run('Cache Enabler', function () { do_action('cache_enabler_clear_complete_cache'); });
            if (function_exists('sg_cachepress_purge_everything')) $run('SiteGround Optimizer', function () { sg_cachepress_purge_everything(); });
            if (has_action('wphb_clear_page_cache'))          $run('Hummingbird', function () { do_action('wphb_clear_page_cache'); });
            if (function_exists('WP_Optimize'))               $run('WP-Optimize', function () {
                $pc = WP_Optimize()->get_page_cache();
                if (is_object($pc) && method_exists($pc, 'purge')) $pc->purge();
            });
            if (has_action('rt_nginx_helper_purge_all'))      $run('Nginx Helper', function () { do_action('rt_nginx_helper_purge_all'); });
            if (has_action('breeze_clear_all_cache'))         $run('Breeze', function () { do_action('breeze_clear_all_cache'); });
            if (class_exists('WpeCommon')) $run('WP Engine', function () {
                if (method_exists('WpeCommon', 'purge_memcached')) WpeCommon::purge_memcached();
                if (method_exists('WpeCommon', 'purge_varnish_cache')) WpeCommon::purge_varnish_cache();
            });
        }

        $out = ['scope' => $pid ? 'post' : 'site', 'flushed' => $done, 'page_cache' => $page];
        if ($pid) $out['post_id'] = $pid;
        if ($failed) $out['failed'] = $failed;
        return self::ok($out);
    }
}
