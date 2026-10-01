<?php
/**
 * Content toolset — block-safe post creation, server-side render verification,
 * translation-aware deletion and taxonomy-term management.
 *
 * Multilingual and term helpers live in Simple_MCP_Tools_Posts (always loaded).
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Content {

    /** Max bytes of rendered HTML returned by render_post. */
    const RENDER_MAX = 300000;

    /** Globals touched by setting up a post for rendering (setup_postdata + main query); restored afterwards. */
    const RENDER_GLOBALS = ['post', 'wp_query', 'wp_the_query', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages'];

    static function defs() {
        return [
            'create_post' => [
                'title'       => 'Create post',
                'description' => 'Create a post/page/CPT item with a block-safe body in one call. post_type must be an editable (show_ui) content type — not attachments (use upload_media) or WordPress/ACF internals — and your user needs its create_posts capability (types that disable it, e.g. log CPTs, are refused); publish/private/future also need publish_posts, and future needs a date in the future (site time). parent must be an existing, readable item of the same hierarchical type; thumbnail an existing image attachment you can read. content (Gutenberg block markup) is wp_slash-ed and verified (content_verified; kses_note explains a mismatch caused by missing unfiltered_html). meta is written after the insert key by key through the native add/edit_post_meta capability checks — protected "_" keys are refused unless registered with an auth_callback — and refused keys are listed in meta_skipped (for ACF post fields use acf_update instead). On a multilingual site a new item of a translatable post type is always registered in a language — lang (an active language, or a disabled one with allow_inactive:true), else the default language — so wp-loc never auto-creates sibling drafts for it later, and its slug is made unique within that language. Returns {id, status, language, content_verified, etag, url, …}. Fill ACF block fields afterwards with block_update; make a translated copy with wploc_create_translation.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_type'  => ['type' => 'string'],
                        'title'      => ['type' => 'string', 'description' => 'stored as given'],
                        'status'     => ['type' => 'string', 'description' => 'draft (default) | pending | publish | private | future (needs date) | a custom registered status'],
                        'content'    => ['type' => 'string', 'description' => 'Gutenberg block markup'],
                        'slug'       => ['type' => 'string'],
                        'excerpt'    => ['type' => 'string'],
                        'parent'     => ['type' => 'integer', 'description' => 'parent ID (hierarchical types only)'],
                        'menu_order' => ['type' => 'integer'],
                        'date'       => ['type' => 'string', 'description' => 'Y-m-d H:i:s, site time'],
                        'thumbnail'  => ['type' => 'integer', 'description' => 'image attachment ID'],
                        'meta'       => ['type' => 'object', 'description' => 'post meta key => value (for ACF post fields use acf_update after)'],
                        'lang'       => ['type' => 'string', 'description' => 'language for the new post (URL slug "ua" or wpml_code "uk"); default: the site default language; multilingual sites only'],
                        'allow_inactive' => ['type' => 'boolean', 'description' => 'allow a configured but disabled language in lang (to prepare content before enabling it)'],
                    ],
                    'required' => ['post_type', 'title']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'create_post'],
            ],
            'render_post' => [
                'title'       => 'Render post',
                'description' => 'Return the server-rendered HTML of a post so you can verify an edit actually renders (the theme convention: check rendered output, not field presence). mode "the_content" (default) runs the full the_content filter chain with the post set up as the main query (conditional tags such as is_front_page() and theme content filters behave as on the front end); mode "blocks" runs do_blocks() only. Rendering happens in the post\'s own language context by default; lang renders under another active language context (it does NOT switch to the translation — a warning names the translation\'s ID); an unknown or disabled lang is an error. Header/footer/template parts are not included. Returns {post_id, mode, lang, post_language, bytes, returned_bytes, truncated, html}; html is cut at 300 KB on a UTF-8 character boundary.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'mode'    => ['type' => 'string', 'enum' => ['the_content', 'blocks'], 'description' => 'default the_content'],
                        'lang'    => ['type' => 'string', 'description' => 'language context (URL slug or wpml_code); default: the post\'s own language'],
                    ],
                    'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'render_post'],
            ],
            'safe_delete' => [
                'title'       => 'Delete post safely',
                'description' => 'Translation-aware delete that reports what really happened. Without force the post goes to the trash (wp_trash_post, for every post type); this is refused when the trash is disabled (EMPTY_TRASH_DAYS=0), for attachments when MEDIA_TRASH is off, and for posts already in the trash — pass force:true to delete permanently. If the post has linked translations (wp-loc/WPML) it is refused unless allow_cascade:true, because wp-loc deletes the whole translation group when any member is deleted permanently (now, or later when the trash is emptied) and its attribute sync would trash the siblings too. With allow_cascade:true the post is first detached from its translation group (its former group is kept in the _simple_mcp_detached meta while it sits in the trash) and only this post is deleted; files still used by language copies of an attachment are kept. Afterwards the post and every former sibling are re-read: returns {outcome: trashed|deleted, status, siblings_left, siblings_lost, …}. Requires delete_post.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'       => ['type' => 'integer'],
                        'force'         => ['type' => 'boolean', 'description' => 'skip the trash and delete permanently'],
                        'allow_cascade' => ['type' => 'boolean', 'description' => 'proceed although translations exist: detach this post from its group first, then delete only it'],
                    ],
                    'required' => ['post_id']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'safe_delete'],
            ],
            'term_list' => [
                'title'       => 'List terms',
                'description' => 'List the terms of a taxonomy (ordered by name). Filters: search (name/slug substring), parent (hierarchical taxonomies), hide_empty, lang (only terms registered in that language; without lang every language is listed and on multilingual sites each term carries its lang). Non-public taxonomies need the taxonomy\'s manage_terms capability. Returns {total, page, per_page, terms:[{term_id, term_taxonomy_id, name, slug, parent, count, description, lang?}]}; descriptions are cut at 1000 bytes.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'taxonomy'   => ['type' => 'string'],
                        'search'     => ['type' => 'string'],
                        'parent'     => ['type' => 'integer', 'description' => 'direct children of this term ID (0 = top level)'],
                        'hide_empty' => ['type' => 'boolean', 'description' => 'default false'],
                        'lang'       => ['type' => 'string', 'description' => 'URL slug or wpml_code; multilingual sites only'],
                        'per_page'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'default 50'],
                        'page'       => ['type' => 'integer', 'minimum' => 1, 'description' => 'default 1'],
                    ],
                    'required' => ['taxonomy']],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'term_list'],
            ],
            'term_create' => [
                'title'       => 'Create term',
                'description' => 'Create a taxonomy term (native wp_insert_term). Capability as in the block editor / REST API: the taxonomy\'s edit_terms for hierarchical taxonomies, assign_terms for flat ones (tags). parent must be an existing term of the same hierarchical taxonomy (in the same language on multilingual sites). On a multilingual site a term of a translatable taxonomy is created and registered in lang (an active language, or a disabled one with allow_inactive:true; default: the site default language); no copies in other languages are created — add them with wploc_create_translation. A duplicate name/slug is an error that names the existing term_id. Returns the new term.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'taxonomy'    => ['type' => 'string'],
                        'name'        => ['type' => 'string'],
                        'slug'        => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'parent'      => ['type' => 'integer', 'description' => 'parent term ID (hierarchical taxonomies only)'],
                        'lang'        => ['type' => 'string', 'description' => 'URL slug or wpml_code; multilingual sites only'],
                        'allow_inactive' => ['type' => 'boolean', 'description' => 'allow a configured but disabled language in lang'],
                    ],
                    'required' => ['taxonomy', 'name']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'term_create'],
            ],
            'term_update' => [
                'title'       => 'Update term',
                'description' => 'Update a term\'s name, slug, description and/or parent (native wp_update_term; only the fields you pass change). Requires the edit_term capability on that term. parent (hierarchical taxonomies only; 0 = top level) must be another existing term of the taxonomy (in the term\'s language on multilingual sites). On wp-loc sites every update re-applies the term\'s parent (mapped per language) to its translations. Returns the updated term.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'term_id'     => ['type' => 'integer'],
                        'taxonomy'    => ['type' => 'string'],
                        'name'        => ['type' => 'string'],
                        'slug'        => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'parent'      => ['type' => 'integer'],
                    ],
                    'required' => ['term_id', 'taxonomy']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
                'callback'    => [__CLASS__, 'term_update'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    // ── create_post ───────────────────────────────────────────────────────

    static function create_post($args) {
        $pt  = sanitize_key((string) ($args['post_type'] ?? ''));
        $pto = $pt !== '' ? get_post_type_object($pt) : null;
        if (!$pto) return self::err('unknown post_type: ' . $pt);
        if ($pt === 'attachment') return self::err('attachments are created with upload_media / upload_begin…upload_finish (they need a file and the theme image pipeline)');
        if (empty($pto->show_ui) || Simple_MCP_Tools_Posts::is_internal_type($pt)) {
            return self::err('post_type "' . $pt . '" is not an editable content type (WordPress/ACF internal, or no admin UI) and cannot be created here');
        }
        // create_posts as registered: false (e.g. log CPTs) natively denies everyone
        $create_cap = isset($pto->cap->create_posts) ? $pto->cap->create_posts : 'edit_posts';
        if (!$create_cap) return Simple_MCP_Tools::err_cap('create_posts (' . $pt . ' — creating items is disabled for this post type)');
        if (!current_user_can($create_cap)) return Simple_MCP_Tools::err_cap($create_cap . ' (' . $pt . ')');

        $date = isset($args['date']) && $args['date'] !== '' ? (string) $args['date'] : null;
        $status = Simple_MCP_Tools::validate_status($args['status'] ?? 'draft', false, $date);
        if (is_wp_error($status)) return self::err($status->get_error_message());
        if (Simple_MCP_Tools::is_publish_status($status) && !Simple_MCP_Tools::can_publish_type($pt)) {
            return Simple_MCP_Tools::err_cap('publish_posts (' . $pt . ')');
        }

        $warnings = [];
        $ml = Simple_MCP_Tools_Posts::ml();
        $lang = null;
        if (isset($args['lang']) && $args['lang'] !== '') {
            $lang = Simple_MCP_Tools_Posts::lang_arg($args['lang'], !empty($args['allow_inactive']));
            if (isset($lang['isError'])) return $lang;
        } elseif ($ml) {
            $lang = Simple_MCP_Tools_Posts::default_lang();
            if (!$lang) $warnings[] = 'the site default language could not be resolved, so the post was not registered in a language';
        }

        $parent_id = isset($args['parent']) ? (int) $args['parent'] : 0;
        if ($parent_id) {
            if (!$pto->hierarchical) return self::err('post_type "' . $pt . '" is not hierarchical — parent is not allowed');
            $parent = get_post($parent_id);
            if (!$parent || $parent->post_type !== $pt) return self::err('parent #' . $parent_id . ' does not exist or is not a "' . $pt . '"');
            if ($parent->post_status === 'trash') return self::err('parent #' . $parent_id . ' is in the trash');
            if (!Simple_MCP_Tools::can_read_post($parent)) return Simple_MCP_Tools::err_cap('read parent #' . $parent_id);
            $plang = Simple_MCP_Tools_Posts::post_lang($parent);
            if ($lang && $plang && $plang !== $lang['code']) {
                $warnings[] = 'parent #' . $parent_id . ' is in "' . $plang . '", the new post in "' . $lang['code'] . '" — use the parent\'s "' . $lang['code'] . '" translation (wploc_get_translations)';
            }
        }

        $thumb = isset($args['thumbnail']) ? (int) $args['thumbnail'] : 0;
        if ($thumb) {
            $att = get_post($thumb);
            if (!$att || $att->post_type !== 'attachment' || !wp_attachment_is_image($att)) {
                return self::err('thumbnail #' . $thumb . ' is not an existing image attachment');
            }
            if (!Simple_MCP_Tools::can_read_post($att)) return Simple_MCP_Tools::err_cap('read attachment #' . $thumb);
            if (!post_type_supports($pt, 'thumbnail')) $warnings[] = 'post_type "' . $pt . '" does not declare featured-image support; the theme may not show it';
        }

        $meta = [];
        if (isset($args['meta'])) {
            if (!is_array($args['meta']) || ($args['meta'] !== [] && array_is_list($args['meta']))) return self::err('meta must be an object {key: value}');
            $meta = $args['meta'];
        }

        $arr = [
            'post_type'   => $pt,
            'post_status' => $status,
            'post_title'  => (string) ($args['title'] ?? ''),
        ];
        $has_content = array_key_exists('content', $args);
        if ($has_content)                 $arr['post_content'] = (string) $args['content'];
        if (isset($args['excerpt']))      $arr['post_excerpt'] = (string) $args['excerpt'];
        $want_slug = isset($args['slug']) ? sanitize_title((string) $args['slug']) : '';
        if ($want_slug !== '')            $arr['post_name']    = $want_slug;
        if ($parent_id)                   $arr['post_parent']  = $parent_id;
        if (isset($args['menu_order']))   $arr['menu_order']   = (int) $args['menu_order'];
        if ($date !== null)               $arr['post_date']    = $date;

        $id = wp_insert_post(wp_slash($arr), true);
        if (is_wp_error($id)) return self::err('create failed: ' . $id->get_error_message());
        $id = (int) $id;

        // Language first: wp-loc registers unregistered posts on their next save in the admin language
        // and auto-creates draft copies in every other language — register_post_language prevents that.
        $language = null;
        if ($lang && $ml) {
            $r = Simple_MCP_Tools_Wploc::register_post_language($id, $lang['code']);
            if (is_wp_error($r)) {
                // a post type that is not translatable simply has no language (worth a note only when lang was asked for)
                if ($r->get_error_code() !== 'not_translatable') $warnings[] = 'post created but not registered in "' . $lang['code'] . '": ' . $r->get_error_message();
                elseif (isset($args['lang']) && $args['lang'] !== '') $warnings[] = 'post_type "' . $pt . '" is not translatable on this site, so lang was ignored';
            } else {
                $language = Simple_MCP_Tools_Posts::post_lang($id) ?: $lang['code'];
                // The slug was made unique before the language existed (across ALL languages, e.g. "about-2");
                // now that it is registered, recompute it within its language (wp-loc allows per-language duplicates).
                $p = get_post($id);
                $base = $want_slug !== '' ? $want_slug : ($p->post_name !== '' ? sanitize_title($p->post_title) : '');
                if ($base !== '' && $base !== $p->post_name) {
                    $uniq = wp_unique_post_slug($base, $id, $p->post_status, $p->post_type, $p->post_parent);
                    if ($uniq !== $p->post_name) wp_update_post(['ID' => $id, 'post_name' => wp_slash($uniq)]);
                }
            }
        }

        $thumbnail_set = null;
        if ($thumb) {
            set_post_thumbnail($id, $thumb);
            $thumbnail_set = ((int) get_post_thumbnail_id($id) === $thumb);
        }

        // meta AFTER insert: the native meta caps need the post to exist (protected keys need an auth_callback)
        $written = [];
        $skipped = [];
        foreach ($meta as $k => $v) {
            $k = (string) $k;
            $chk = Simple_MCP_Tools::meta_write_check($id, $k);
            if ($chk !== true) { $skipped[] = ['key' => $k, 'missing' => (string) $chk]; continue; }
            update_post_meta($id, $k, wp_slash($v));
            $written[] = $k;
        }

        clean_post_cache($id);
        $post = get_post($id);
        $verified = $has_content ? Simple_MCP_Tools::content_matches($post->post_content, (string) $args['content']) : null;
        $out = [
            'id'               => $id,
            'post_type'        => $pt,
            'status'           => $post->post_status,
            'title'            => $post->post_title,
            'slug'             => $post->post_name,
            'parent'           => (int) $post->post_parent,
            'language'         => $language,
            'content_verified' => $verified,
            'etag'             => md5((string) $post->post_content),
            'url'              => get_permalink($id),
        ];
        $note = Simple_MCP_Tools_Posts::kses_note($verified);
        if ($note) $out['kses_note'] = $note;
        if ($thumbnail_set !== null) $out['thumbnail_set'] = $thumbnail_set;
        if ($written) $out['meta_written'] = $written;
        if ($skipped) $out['meta_skipped'] = $skipped;
        if ($warnings) $out['warnings'] = $warnings;
        return self::ok($out);
    }

    // ── render_post ───────────────────────────────────────────────────────

    static function render_post($args) {
        $id = (int) ($args['post_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('post not found');
        if (!Simple_MCP_Tools::can_read_post($post)) return Simple_MCP_Tools::err_cap('read post #' . $id);
        $mode = (string) ($args['mode'] ?? 'the_content');
        if (!in_array($mode, ['the_content', 'blocks'], true)) return self::err('mode must be "the_content" or "blocks"');

        $ml = Simple_MCP_Tools_Posts::ml();
        $post_lang = Simple_MCP_Tools_Posts::post_lang($post);
        $target = null;
        $warnings = [];
        if (isset($args['lang']) && $args['lang'] !== '') {
            $l = Simple_MCP_Tools_Posts::lang_arg($args['lang'], true);
            if (isset($l['isError'])) return $l;
            if (empty($l['active'])) return self::err('language "' . $l['code'] . '" is disabled on this site, so nothing can be rendered in its context (enable it in wp-loc first)');
            $target = $l['code'];
            if ($post_lang && $post_lang !== $target) {
                $tr = method_exists('Simple_MCP_Tools_Wploc', 'post_in_lang') ? Simple_MCP_Tools_Wploc::post_in_lang($id, $target) : null;
                $warnings[] = 'post #' . $id . ' is in "' . $post_lang . '" but was rendered under the "' . $target . '" context; '
                    . ($tr ? 'render its "' . $target . '" translation #' . $tr . ' to see that page' : 'it has no "' . $target . '" translation');
            }
        } elseif ($post_lang) {
            $l = Simple_MCP_Tools_Wploc::resolve_lang($post_lang);
            if (is_wp_error($l)) $warnings[] = 'the post\'s language "' . $post_lang . '" is not active; rendered under the current language';
            else $target = $l['code'];
        }

        $saved = self::save_globals();
        $ob_level = ob_get_level();
        $switched = false;
        $prev_lang = null;
        $effective = null;
        $stray = '';
        try {
            if ($target !== null) {
                $prev_lang = apply_filters('wpml_current_language', null);
                if ($prev_lang !== $target) {
                    do_action('wpml_switch_language', $target);
                    $switched = true;
                }
            }
            $effective = $ml ? apply_filters('wpml_current_language', null) : null;
            ob_start(); // stray echo from hooks must not corrupt the JSON-RPC response
            self::setup_render_context($post);
            $html = $mode === 'blocks' ? do_blocks($post->post_content) : apply_filters('the_content', $post->post_content);
            $stray = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $ob_level) ob_end_clean();
            self::restore_globals($saved);
            if ($switched) do_action('wpml_switch_language', $prev_lang);
        }
        if ($target !== null && $effective !== $target) {
            $warnings[] = 'the language context could not be switched to "' . $target . '" (effective: "' . $effective . '")';
        }

        $html = (string) $html;
        $out_html = Simple_MCP_Tools::mb_cut($html, self::RENDER_MAX);
        $out = [
            'post_id'        => $id,
            'mode'           => $mode,
            'lang'           => $effective,
            'post_language'  => $post_lang,
            'bytes'          => strlen($html),
            'returned_bytes' => strlen($out_html),
            'truncated'      => strlen($out_html) < strlen($html),
        ];
        if ($warnings) $out['warnings'] = $warnings;
        if (trim($stray) !== '') $out['stray_output'] = Simple_MCP_Tools::mb_cut($stray, 2000);
        $out['html'] = $out_html;
        return self::ok($out);
    }

    /**
     * Make the post the main query (conditional tags) and the loop's current post, like a front-end
     * request for it. Filters are suppressed in the query itself so wp-loc/WPML language filtering
     * cannot hide the post; the result is forced to the post if anything still filters it out.
     */
    static function setup_render_context($post) {
        $qargs = [
            'post_type'           => $post->post_type,
            'post_status'         => [$post->post_status],
            'posts_per_page'      => 1,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'suppress_filters'    => true,
        ];
        if ($post->post_type === 'page') $qargs['page_id'] = $post->ID;
        else $qargs['p'] = $post->ID;
        $q = new WP_Query($qargs);
        if (!$q->posts || (int) $q->posts[0]->ID !== (int) $post->ID) {
            $q->posts = [$post];
            $q->post_count = 1;
            $q->found_posts = 1;
            $q->max_num_pages = 1;
        }
        $q->queried_object = $post;
        $q->queried_object_id = (int) $post->ID;
        $GLOBALS['wp_query'] = $q;
        $GLOBALS['wp_the_query'] = $q;
        $q->the_post(); // global $post + setup_postdata, in_the_loop
    }

    static function save_globals() {
        $s = [];
        foreach (self::RENDER_GLOBALS as $g) {
            $s[$g] = array_key_exists($g, $GLOBALS) ? [$GLOBALS[$g]] : null;
        }
        return $s;
    }

    static function restore_globals(array $s) {
        foreach ($s as $g => $v) {
            if ($v === null) unset($GLOBALS[$g]);
            else $GLOBALS[$g] = $v[0];
        }
    }

    // ── safe_delete ───────────────────────────────────────────────────────

    static function safe_delete($args) {
        $id = (int) ($args['post_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('post not found');
        if ($post->post_type === 'revision' || wp_is_post_revision($post)) {
            return self::err('#' . $id . ' is a revision (a rollback point), not a post — see revision_list / revision_restore');
        }
        if (!current_user_can('delete_post', $id)) return Simple_MCP_Tools::err_cap('delete_post #' . $id);

        $force  = !empty($args['force']);
        $is_att = $post->post_type === 'attachment';
        if (!$force) {
            if ($post->post_status === 'trash') return self::err('post #' . $id . ' is already in the trash. Pass force:true to delete it permanently.');
            if (!EMPTY_TRASH_DAYS) return self::err('the trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so deleting would be permanent. Pass force:true to delete permanently.');
            if ($is_att && !MEDIA_TRASH) return self::err('the media trash is disabled on this site (MEDIA_TRASH is off), so deleting attachment #' . $id . ' would be permanent (files included). Pass force:true to delete it permanently.');
        }

        $etype = 'post_' . $post->post_type;
        $ml = Simple_MCP_Tools_Posts::ml();
        $siblings = $ml ? Simple_MCP_Tools_Wploc::siblings($id, $etype) : [];
        if ($siblings && empty($args['allow_cascade'])) {
            return self::err('Post #' . $id . ' has translations ' . wp_json_encode($siblings) . '. wp-loc (and WPML when configured so) deletes the WHOLE translation group when any member is deleted permanently (now with force:true, or later when the trash is emptied), and wp-loc\'s attribute sync pushes the trash status to the siblings. Re-call with allow_cascade:true to first detach this post from its group (the translations stay, unlinked from it) and delete only it, or delete each language explicitly.');
        }

        $detached = null;
        $d = null;
        if ($siblings) {
            $d = Simple_MCP_Tools_Wploc::detach_element($id, $etype);
            if (is_wp_error($d)) return self::err('could not detach post #' . $id . ' from its translation group (' . $d->get_error_message() . '); nothing was deleted');
            if (Simple_MCP_Tools_Wploc::siblings($id, $etype)) return self::err('post #' . $id . ' is still linked to its translations after detaching; nothing was deleted');
            $detached = ['trid' => isset($d['trid']) ? (int) $d['trid'] : null, 'language' => $d['language'] ?? null,
                'source_language' => $d['source_language'] ?? null, 'new_original' => $d['new_original'] ?? null, 'siblings' => $siblings];
            // undo info while the post sits in the trash (a permanent delete removes it with the post)
            if (!$force) update_post_meta($id, '_simple_mcp_detached', wp_slash($detached + ['time' => time(), 'user_id' => get_current_user_id()]));
        }

        // Language copies of an attachment share its files: keep every file a former sibling still uses
        // (also against hooks that unlink directly instead of through wp_delete_file, e.g. a theme's webp copy).
        $keep = ($is_att && $force && $siblings) ? self::attachment_files(array_values($siblings)) : [];
        $guard = function ($file) use ($keep) {
            return in_array(wp_normalize_path((string) $file), $keep, true) ? '' : $file;
        };
        // Some themes delete child attachments with their post (before_delete_post): report it.
        $children = (!$is_att && $force) ? get_children(['post_parent' => $id, 'post_type' => 'attachment', 'fields' => 'ids']) : [];

        $snap = $keep ? self::snapshot_files($keep) : [];
        if ($keep) add_filter('wp_delete_file', $guard, 1);
        try {
            // raw term context: trashing re-saves the post, which must not swap its terms for current-language ones
            $r = $force ? wp_delete_post($id, true) : Simple_MCP_Tools::with_raw_terms(function () use ($id) { return wp_trash_post($id); }, $id);
        } finally {
            if ($keep) remove_filter('wp_delete_file', $guard, 1);
            $restored = self::restore_files($snap);
        }

        clean_post_cache($id);
        $after = get_post($id);
        $outcome = !$after ? 'deleted' : ($after->post_status === 'trash' ? 'trashed' : 'unchanged');
        if ($outcome === 'unchanged' || ($force && $outcome !== 'deleted')) {
            $relinked = false;
            if ($detached && $detached['trid'] && $detached['language']) {
                // reattach_element also undoes the promotion of another member to original (no group with two originals)
                $re = method_exists('Simple_MCP_Tools_Wploc', 'reattach_element')
                    ? Simple_MCP_Tools_Wploc::reattach_element($id, $etype, $d) : new WP_Error('no_reattach', 'reattach_element is unavailable');
                if (!is_wp_error($re)) delete_post_meta($id, '_simple_mcp_detached');
                $relinked = !is_wp_error($re) && (bool) Simple_MCP_Tools_Wploc::siblings($id, $etype);
            }
            return self::err('delete failed: WordPress (or a plugin hook) refused it; post #' . $id . ' is still "' . $after->post_status . '"'
                . ($detached ? ($relinked ? ' and was relinked to its translations' : ' and is DETACHED from its translations ' . wp_json_encode($siblings) . ' — relink with wploc_link_translation') : ''));
        }

        $left = [];
        $lost = [];
        foreach ($siblings as $code => $sid) {
            clean_post_cache($sid);
            $sp = get_post($sid);
            if ($sp) $left[$code] = ['id' => (int) $sid, 'status' => $sp->post_status];
            else $lost[$code] = (int) $sid;
        }
        $out = [
            'post_id'       => $id,
            'outcome'       => $outcome,
            'deleted'       => $outcome === 'deleted',
            'trashed'       => $outcome === 'trashed',
            'status'        => $after ? $after->post_status : null,
            'siblings_left' => $left ?: (object) [],
            'siblings_lost' => $lost ?: (object) [],
        ];
        if ($detached) {
            $out['detached'] = ['trid' => $detached['trid'], 'language' => $detached['language']];
            if ($outcome === 'trashed') $out['note'] = 'Restoring it from the trash will NOT relink it to its translations; its former group is stored in the _simple_mcp_detached meta — relink with wploc_link_translation.';
        }
        if ($children) {
            $gone = array_values(array_filter(array_map('intval', $children), function ($cid) { clean_post_cache($cid); return !get_post($cid); }));
            if ($gone) $out['attachments_removed'] = $gone;
        }
        if ($keep) {
            $missing = [];
            foreach ($siblings as $code => $sid) {
                $f = get_attached_file($sid);
                if ($f && !file_exists($f)) $missing[$code] = (int) $sid;
            }
            $out['shared_files_kept'] = count($keep);
            if ($restored) $out['shared_files_restored'] = count($restored);
            if ($missing) $out['siblings_missing_file'] = $missing;
        }
        return self::ok($out);
    }

    /**
     * Normalized absolute paths of every file the given attachments use: original, sizes,
     * original_image, thumb and the theme's webp copy (webp_path meta).
     */
    static function attachment_files(array $ids) {
        $files = [];
        foreach ($ids as $aid) {
            $file = get_attached_file((int) $aid);
            if (!$file) continue;
            $files[] = wp_normalize_path($file);
            $meta = wp_get_attachment_metadata((int) $aid);
            $dir = dirname($file);
            if (is_array($meta)) {
                foreach ((array) ($meta['sizes'] ?? []) as $s) {
                    if (!empty($s['file'])) $files[] = wp_normalize_path(path_join($dir, $s['file']));
                }
                if (!empty($meta['original_image'])) $files[] = wp_normalize_path(path_join($dir, $meta['original_image']));
                if (!empty($meta['thumb'])) $files[] = wp_normalize_path(path_join($dir, $meta['thumb']));
            }
            $webp = get_post_meta((int) $aid, 'webp_path', true);
            if (is_string($webp) && $webp !== '') $files[] = wp_normalize_path($webp);
        }
        return array_values(array_unique($files));
    }

    /** Hard-link (or copy) each existing file next to itself, so a file unlinked by any hook can be put back. [file => backup] */
    static function snapshot_files(array $files) {
        $snap = [];
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            $bak = $f . '.smcp-keep-' . wp_generate_password(8, false, false);
            if (@link($f, $bak) || @copy($f, $bak)) $snap[$f] = $bak;
        }
        return $snap;
    }

    /** Put back snapshotted files that disappeared; drop the other backups. Returns the restored paths. */
    static function restore_files(array $snap) {
        $restored = [];
        foreach ($snap as $f => $bak) {
            if (!file_exists($f) && @rename($bak, $f)) $restored[] = $f;
            elseif (file_exists($bak)) @unlink($bak);
        }
        return $restored;
    }

    // ── Terms ─────────────────────────────────────────────────────────────

    static function term_list($args) {
        $tax = sanitize_key((string) ($args['taxonomy'] ?? ''));
        $txo = $tax !== '' ? get_taxonomy($tax) : null;
        if (!$txo) return self::err('unknown taxonomy: ' . $tax);
        if (empty($txo->public) && !current_user_can($txo->cap->manage_terms)) {
            return Simple_MCP_Tools::err_cap($txo->cap->manage_terms . ' (non-public taxonomy ' . $tax . ')');
        }
        $per  = max(1, min(200, (int) ($args['per_page'] ?? 50)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $q = [
            'taxonomy'   => $tax,
            'hide_empty' => !empty($args['hide_empty']),
            'orderby'    => 'name',
            'order'      => 'ASC',
            'lang'       => 'all', // wp-loc: switch its current-language terms filter off
        ];
        if (isset($args['search']) && trim((string) $args['search']) !== '') $q['search'] = trim((string) $args['search']);
        if (isset($args['parent'])) {
            if (!$txo->hierarchical) return self::err('taxonomy "' . $tax . '" is not hierarchical — parent is not supported');
            $q['parent'] = max(0, (int) $args['parent']);
        }
        $lang = null;
        if (isset($args['lang']) && $args['lang'] !== '') {
            $lang = Simple_MCP_Tools_Posts::lang_arg($args['lang'], true);
            if (isset($lang['isError'])) return $lang;
            if (Simple_MCP_Tools_Posts::icl_table() === '') return self::err('translations table not found — cannot filter by language');
            $q['smcp_lang'] = array_values(array_unique([$lang['code'], $lang['slug']]));
        }

        add_filter('terms_clauses', [__CLASS__, 'terms_lang_clauses'], 20, 3);
        try {
            [$total, $terms] = Simple_MCP_Tools_Posts::raw_terms(function () use ($q, $per, $page) {
                $total = wp_count_terms($q);
                $terms = get_terms($q + ['number' => $per, 'offset' => ($page - 1) * $per]);
                return [$total, $terms];
            });
        } finally {
            remove_filter('terms_clauses', [__CLASS__, 'terms_lang_clauses'], 20);
        }
        if (is_wp_error($terms)) return self::err('term query failed: ' . $terms->get_error_message());

        $ml = Simple_MCP_Tools_Posts::ml();
        $langs = $ml && $terms ? Simple_MCP_Tools_Posts::element_langs(wp_list_pluck($terms, 'term_taxonomy_id'), ['tax_' . $tax]) : [];
        $items = [];
        foreach ((array) $terms as $t) {
            $row = self::term_row($t);
            if ($ml) $row['lang'] = $langs['tax_' . $tax . ':' . (int) $t->term_taxonomy_id] ?? null;
            $items[] = $row;
        }
        $out = [
            'taxonomy' => $tax,
            'total'    => is_wp_error($total) ? null : (int) $total,
            'page'     => $page,
            'per_page' => $per,
            'terms'    => $items,
        ];
        if ($lang) $out['lang'] = $lang['code'];
        return self::ok($out);
    }

    /** terms_clauses: restrict a term_list query (marked by its smcp_lang arg) to terms registered in those language codes. */
    static function terms_lang_clauses($clauses, $taxonomies, $args) {
        if (empty($args['smcp_lang']) || !is_array($args['smcp_lang'])) return $clauses;
        $t = Simple_MCP_Tools_Posts::icl_table();
        if ($t === '') return $clauses;
        $etypes = array_map(function ($x) { return 'tax_' . $x; }, (array) $taxonomies);
        $clauses['join']  .= " INNER JOIN {$t} smcp_tl ON smcp_tl.element_id = tt.term_taxonomy_id"
            . ' AND smcp_tl.element_type IN (' . Simple_MCP_Tools_Posts::sql_in($etypes) . ')';
        $clauses['where'] .= ' AND smcp_tl.language_code IN (' . Simple_MCP_Tools_Posts::sql_in($args['smcp_lang']) . ')';
        return $clauses;
    }

    static function term_row($t, $desc_max = 1000) {
        return [
            'term_id'          => (int) $t->term_id,
            'term_taxonomy_id' => (int) $t->term_taxonomy_id,
            'name'             => $t->name,
            'slug'             => $t->slug,
            'parent'           => (int) $t->parent,
            'count'            => (int) $t->count,
            'description'      => Simple_MCP_Tools::mb_cut((string) $t->description, $desc_max),
        ];
    }

    static function term_create($args) {
        $tax = sanitize_key((string) ($args['taxonomy'] ?? ''));
        $txo = $tax !== '' ? get_taxonomy($tax) : null;
        if (!$txo) return self::err('unknown taxonomy: ' . $tax);
        $cap = Simple_MCP_Tools_Posts::term_create_cap($txo);
        if (!current_user_can($cap)) return Simple_MCP_Tools::err_cap($cap . ' (create terms in ' . $tax . ')');
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') return self::err('name is required');

        $ml = Simple_MCP_Tools_Posts::ml();
        $lang = null;
        if (isset($args['lang']) && $args['lang'] !== '') {
            $lang = Simple_MCP_Tools_Posts::lang_arg($args['lang'], !empty($args['allow_inactive']));
            if (isset($lang['isError'])) return $lang;
        } elseif ($ml) {
            $lang = Simple_MCP_Tools_Posts::default_lang();
        }

        $targs = [];
        if (isset($args['slug']) && trim((string) $args['slug']) !== '') $targs['slug'] = sanitize_title((string) $args['slug']);
        if (isset($args['description'])) $targs['description'] = (string) $args['description'];
        if (!empty($args['parent'])) {
            if (!$txo->hierarchical) return self::err('taxonomy "' . $tax . '" is not hierarchical — parent is not allowed');
            $parent = Simple_MCP_Tools_Posts::raw_term((int) $args['parent'], $tax);
            if (!$parent) return self::err('parent term #' . (int) $args['parent'] . ' does not exist in "' . $tax . '"');
            if ($lang) {
                $pl = Simple_MCP_Tools_Posts::element_langs([$parent->term_taxonomy_id], ['tax_' . $tax]);
                $plang = $pl['tax_' . $tax . ':' . (int) $parent->term_taxonomy_id] ?? null;
                if ($plang && $plang !== $lang['code']) {
                    return self::err('parent term #' . (int) $parent->term_id . ' is in "' . $plang . '", not in "' . $lang['code'] . '" — use the parent\'s "' . $lang['code'] . '" translation');
                }
            }
            $targs['parent'] = (int) $parent->term_id;
        }

        $r = Simple_MCP_Tools_Posts::insert_term($tax, $name, $targs, $lang ? $lang['code'] : null);
        if (isset($r['isError'])) return self::err('term create failed: ' . ($r['content'][0]['text'] ?? 'error'));
        $t = Simple_MCP_Tools_Posts::raw_term($r['term_id'], $tax);
        if (!$t) return self::err('term #' . $r['term_id'] . ' was created but cannot be read back');
        $out = ['taxonomy' => $tax] + self::term_row($t, PHP_INT_MAX);
        if ($ml) $out['language'] = $r['language'] ?? null;
        if (!empty($r['language_warning'])) $out['warnings'] = [$r['language_warning']];
        return self::ok($out);
    }

    static function term_update($args) {
        $tax = sanitize_key((string) ($args['taxonomy'] ?? ''));
        $txo = $tax !== '' ? get_taxonomy($tax) : null;
        if (!$txo) return self::err('unknown taxonomy: ' . $tax);
        $term_id = (int) ($args['term_id'] ?? 0);
        $term = $term_id ? Simple_MCP_Tools_Posts::raw_term($term_id, $tax) : null;
        if (!$term) return self::err('term #' . $term_id . ' does not exist in "' . $tax . '"');
        if (!current_user_can('edit_term', $term_id)) return Simple_MCP_Tools::err_cap('edit_term #' . $term_id);

        $u = [];
        if (isset($args['name'])) {
            $name = trim((string) $args['name']);
            if ($name === '') return self::err('name cannot be empty');
            $u['name'] = $name;
        }
        if (isset($args['slug'])) {
            $slug = sanitize_title((string) $args['slug']);
            if ($slug === '') return self::err('slug cannot be empty');
            $u['slug'] = $slug;
        }
        if (isset($args['description'])) $u['description'] = (string) $args['description'];
        $tl = Simple_MCP_Tools_Posts::element_langs([$term->term_taxonomy_id], ['tax_' . $tax]);
        $tlang = $tl['tax_' . $tax . ':' . (int) $term->term_taxonomy_id] ?? null;
        if (isset($args['parent'])) {
            if (!$txo->hierarchical) return self::err('taxonomy "' . $tax . '" is not hierarchical — parent is not allowed');
            $pid = max(0, (int) $args['parent']);
            if ($pid === $term_id) return self::err('a term cannot be its own parent');
            $parent = $pid ? Simple_MCP_Tools_Posts::raw_term($pid, $tax) : null;
            if ($pid && !$parent) return self::err('parent term #' . $pid . ' does not exist in "' . $tax . '"');
            if ($parent && $tlang) {
                $pl = Simple_MCP_Tools_Posts::element_langs([$parent->term_taxonomy_id], ['tax_' . $tax]);
                $plang = $pl['tax_' . $tax . ':' . (int) $parent->term_taxonomy_id] ?? null;
                if ($plang && $plang !== $tlang) {
                    return self::err('parent term #' . $pid . ' is in "' . $plang . '", term #' . $term_id . ' in "' . $tlang . '" — use the parent\'s "' . $tlang . '" translation');
                }
            }
            $u['parent'] = $pid;
        }
        if (!$u) return self::err('nothing to update — pass name, slug, description and/or parent');

        // Run in the term's own language, so wp-loc/WPML check slug uniqueness within that language.
        $res = Simple_MCP_Tools_Posts::in_language($tlang, function () use ($term_id, $tax, $u) {
            return Simple_MCP_Tools_Posts::raw_terms(function () use ($term_id, $tax, $u) {
                return wp_update_term($term_id, $tax, wp_slash($u));
            }, false);
        });
        if (is_wp_error($res)) return self::err('term update failed: ' . $res->get_error_message());

        clean_term_cache($term_id, $tax);
        $t = Simple_MCP_Tools_Posts::raw_term($term_id, $tax);
        if (!$t) return self::err('term #' . $term_id . ' cannot be read back after the update');
        $out = ['taxonomy' => $tax, 'updated' => array_keys($u)] + self::term_row($t, PHP_INT_MAX);
        if ($tlang) $out['language'] = $tlang;
        return self::ok($out);
    }
}
