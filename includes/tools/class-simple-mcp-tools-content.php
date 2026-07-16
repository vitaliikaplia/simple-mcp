<?php
/**
 * Content toolset — block-safe post creation, server-side render verification,
 * and translation-aware deletion.
 */
if (!defined('ABSPATH')) exit;

class Simple_MCP_Tools_Content {

    static function defs() {
        return [
            'create_post' => [
                'description' => 'Create a post/page/CPT item with a block-safe body and all attributes in one call. content (Gutenberg block markup) is wp_slash-ed and byte-verified like update_post, so no need to route block content through wp_cli. Returns {id, content_verified, url}. To fill ACF block fields afterwards, use block_update on the new id; for a translated copy use wploc_create_translation.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_type'  => ['type' => 'string'],
                        'title'      => ['type' => 'string'],
                        'status'     => ['type' => 'string', 'description' => 'draft|publish|pending|private (default draft)'],
                        'content'    => ['type' => 'string'],
                        'slug'       => ['type' => 'string'],
                        'excerpt'    => ['type' => 'string'],
                        'parent'     => ['type' => 'integer'],
                        'menu_order' => ['type' => 'integer'],
                        'date'       => ['type' => 'string', 'description' => 'Y-m-d H:i:s'],
                        'thumbnail'  => ['type' => 'integer', 'description' => 'attachment ID'],
                        'meta'       => ['type' => 'object', 'description' => 'post meta key=>value (for ACF POST fields use acf_update after)'],
                    ],
                    'required' => ['post_type', 'title']],
                'callback' => [__CLASS__, 'create_post'],
            ],
            'render_post' => [
                'description' => 'Return the rendered do_blocks() HTML of a post (optionally in a given language) so you can verify an edit actually renders, per the theme convention of checking rendered output rather than field presence. Output may be truncated for very large pages.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['post_id' => ['type' => 'integer'], 'lang' => ['type' => 'string']],
                    'required' => ['post_id']],
                'callback' => [__CLASS__, 'render_post'],
            ],
            'safe_delete' => [
                'description' => 'Translation-aware delete with EXPLICIT scope. Modes: (1) default — refuses when the post has linked translations (lists them); (2) allow_cascade:true — deletes ONLY this post, translation siblings are left intact (wp-loc\'s own group-cascade is suppressed for this call); (3) delete_translations:true — deliberately deletes the WHOLE translation group: this post AND every linked sibling. force:true bypasses trash (permanent). Never mixes modes silently — what you ask is exactly what is deleted.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false,
                    'properties' => [
                        'post_id'             => ['type' => 'integer'],
                        'force'               => ['type' => 'boolean', 'description' => 'skip trash, permanently delete'],
                        'allow_cascade'       => ['type' => 'boolean', 'description' => 'proceed even if translations exist — deletes ONLY this post, siblings stay'],
                        'delete_translations' => ['type' => 'boolean', 'description' => 'delete the WHOLE translation group (this post + all linked siblings)'],
                    ],
                    'required' => ['post_id']],
                'callback' => [__CLASS__, 'safe_delete'],
            ],
        ];
    }

    static function ok($d) { return Simple_MCP_Tools::ok($d); }
    static function err($m) { return Simple_MCP_Tools::err($m); }

    static function create_post($args) {
        $pt = sanitize_key($args['post_type'] ?? 'post');
        if (!post_type_exists($pt)) return self::err('unknown post_type: ' . $pt);
        $arr = [
            'post_type'   => $pt,
            'post_status' => sanitize_key($args['status'] ?? 'draft'),
            'post_title'  => (string) ($args['title'] ?? ''),
        ];
        $has_content = array_key_exists('content', $args);
        if ($has_content)                     $arr['post_content'] = (string) $args['content'];
        if (isset($args['excerpt']))          $arr['post_excerpt'] = (string) $args['excerpt'];
        if (isset($args['slug']))             $arr['post_name']    = sanitize_title((string) $args['slug']);
        if (isset($args['parent']))           $arr['post_parent']  = intval($args['parent']);
        if (isset($args['menu_order']))       $arr['menu_order']   = intval($args['menu_order']);
        if (isset($args['date']))             $arr['post_date']    = (string) $args['date'];
        if (isset($args['meta']) && is_array($args['meta'])) $arr['meta_input'] = $args['meta'];

        $id = wp_insert_post(wp_slash($arr), true);
        if (is_wp_error($id)) return self::err('create failed: ' . $id->get_error_message());
        if (!empty($args['thumbnail'])) set_post_thumbnail($id, intval($args['thumbnail']));

        $verified = $has_content ? (get_post($id)->post_content === (string) $args['content']) : null;
        return self::ok(['id' => $id, 'post_type' => $pt, 'status' => get_post_status($id),
            'content_verified' => $verified, 'url' => get_permalink($id)]);
    }

    static function render_post($args) {
        $id = intval($args['post_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('post not found');
        $lang = $args['lang'] ?? null;
        $prev_lang = null;
        if ($lang) {
            $code = class_exists('Simple_MCP_Tools_Wploc') ? Simple_MCP_Tools_Wploc::to_code($lang) : $lang;
            $prev_lang = apply_filters('wpml_current_language', null);
            do_action('wpml_switch_language', $code); // best-effort; ignored if unsupported
        }

        global $post;
        $post = get_post($id);
        setup_postdata($post);
        $html = do_blocks($post->post_content);
        $html = do_shortcode($html);
        wp_reset_postdata();

        if ($lang && $prev_lang) do_action('wpml_switch_language', $prev_lang); // restore

        $max = 300000;
        $truncated = false;
        if (strlen($html) > $max) { $html = substr($html, 0, $max); $truncated = true; }
        return self::ok(['post_id' => $id, 'lang' => $lang, 'length' => strlen($html), 'truncated' => $truncated, 'html' => $html]);
    }

    static function safe_delete($args) {
        $id = intval($args['post_id'] ?? 0);
        $post = $id ? get_post($id) : null;
        if (!$post) return self::err('post not found');

        $etype = 'post_' . $post->post_type;
        $siblings = [];
        $trid = apply_filters('wpml_element_trid', null, $id, $etype);
        if ($trid) {
            $tr = apply_filters('wpml_get_element_translations', null, $trid, $etype);
            foreach ((array) $tr as $code => $obj) {
                $tid = is_object($obj) ? ($obj->element_id ?? null) : ($obj['element_id'] ?? null);
                if (intval($tid) !== $id) $siblings[$code] = intval($tid);
            }
        }
        $group = !empty($args['delete_translations']);
        if ($siblings && !$group && empty($args['allow_cascade'])) {
            return self::err('Post ' . $id . ' has translations ' . wp_json_encode($siblings)
                . '. Either delete the WHOLE group with delete_translations:true, or re-call with allow_cascade:true to delete ONLY this post (translations left intact).');
        }

        $force = !empty($args['force']);
        // Каскадом керуємо самі в обох режимах (wp-loc ≥1.4.1 на before_delete_post зносить
        // усю групу): знімаємо його хук, видаляємо рівно те, що просили, повертаємо хук.
        // Це ж робить delete_translations детермінованим і на WPML (де каскаду нема).
        $detached = false;
        $sync_off = false;
        if (class_exists('WP_LOC') && isset(WP_LOC::instance()->content)) {
            $detached = remove_action('before_delete_post', [WP_LOC::instance()->content, 'handle_delete_post']);
            // Інакше статус-синк затрешить сиблінга ДО нашого wp_delete_post по ньому,
            // а wp_delete_post на вже-затрешеному пості видаляє його НАЗАВЖДИ.
            $sync_off = remove_action('save_post', [WP_LOC::instance()->content, 'sync_translations'], 30);
        }
        $targets = $group ? array_merge([$id], array_values(array_filter($siblings))) : [$id];
        $deleted = [];
        $failed  = [];
        try {
            foreach ($targets as $tid) {
                if (!$force && get_post_status($tid) === 'trash') { $deleted[] = $tid; continue; } // вже в кошику
                if (!wp_delete_post($tid, $force)) { $failed[] = $tid; continue; }
                $deleted[] = $tid;
                if ($force && class_exists('WP_LOC')) {
                    // хук знято → чистимо icl-рядок видаленого поста самі
                    WP_LOC::instance()->db->delete_element($tid, $etype);
                }
            }
        } finally {
            if ($detached) {
                add_action('before_delete_post', [WP_LOC::instance()->content, 'handle_delete_post']);
            }
            if ($sync_off) {
                add_action('save_post', [WP_LOC::instance()->content, 'sync_translations'], 30, 2);
            }
        }
        if (!$deleted) return self::err('delete failed');
        return self::ok([
            'deleted'       => $deleted,
            'failed'        => $failed,
            'trashed'       => !$force,
            'group_deleted' => $group,
            'siblings_left' => $group ? (object) [] : ($siblings ?: (object) []),
        ]);
    }
}
